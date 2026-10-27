#!/usr/bin/env python3
"""Real-browser WordPress HTTP + Playwright Chromium acceptance for Day-2 Workflows (PR #263).

Exercises the four Day-2 operational surfaces end-to-end against a live Apache +
WordPress + MySQL deployment (deployed from the release ZIP artifact) with
strictly isolated BrowserContexts per persona:

  Journey A — Patient:
    A1. Anonymous Public Booking (`390x844` + `320x740` mobile check):
        clinician switcher, multi-location switcher, booking confirmation
        summary, OTP handoff (`IssueKnownCode` DB HMAC patch, real verification),
        first-time patient identity validation gate, and confirmed receipt.
    A2. Authenticated Patient Portal (`390x844` + `320x740` mobile check):
        `reschedule-options` honoring `booking.max_future_days = 30` (excluding
        `+40d` slot), concurrent slot collision (`409` recovery + live refresh),
        and successful reschedule confirmation + reload.

  Journey B — Receptionist (WordPress `subscriber` + `cpms_secretary` membership):
    Daily schedule clinician filter, required no-show reason validation,
    authorized no-show transition + `APPOINTMENT_NO_SHOW` audit, and
    cross-Clinic denial (`403`/`404`).

  Journey C — Doctor (WordPress `subscriber` + `cpms_doctor` membership):
    C1. Assigned Subscriber doctor (`d2doc1`): opens Visit Workspace, loads
        medical-history panel, sees own `doctor_private` note + colleague's
        `patient_visible` note + `RX_READ` prescription, and never sees
        another doctor's `doctor_private` note; writes `MEDICAL_RECORD_VIEWED`.
    C2. Deny-override Subscriber doctor (`d2doc_deny`): explicit `DENY` on
        `PRIVATE_NOTE_READ` and `RX_READ` hides own `doctor_private` note and
        finalized prescriptions while preserving `patient_visible` notes.
    C3. Second clinician in same Clinic (`d2doc1` probing `d2doc_deny`'s Visit):
        denied (`404`) without leaking clinical records.

  Journey D — Accountant (WordPress `subscriber` + `cpms_accountant` membership):
    Scoped Staff Portal reconciliation totals (`cash`, `card_pos`, `captured`,
    `outstanding_balance`, voided excluded), Location switching (`Loc A1` vs
    `Loc A2`), cross-Clinic denial (`Clinic B`), zero patient PHI leakage, and
    `390px` + `320px` mobile layouts (`scrollWidth <= innerWidth + 1`).

Evidence lines contain only booleans, counts, HTTP statuses, and non-sensitive
numeric IDs — never passwords, OTP codes, mobile numbers, patient names, nonces,
or cookies.
"""

import hashlib
import hmac
import json
import os
import re
import subprocess
import sys
import time
from urllib.parse import parse_qs, urlparse

SELFTEST = "--test" in sys.argv[1:]

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
WP_DIR = os.environ.get("WP_DIR", "/home/runner/www")
DB_MAIN = os.environ.get("DB_MAIN", "cpms_main")
DB_PREFIX = os.environ.get("DB_PREFIX", "cpmswp_")
OUT = "pilot-screenshots"

KNOWN_OTP = "535353"
SYN_MOBILE = "09128887701"
SYN_FIRST_NAME = "الناز"
SYN_LAST_NAME = "توکلی"

results = []
failures = []


def ok(key, title, detail=""):
    results.append({"key": key, "status": "PASS", "detail": detail})
    print(f"PASS {key} — {title}" + (f" — {detail}" if detail else ""))


def fail(key, title, err):
    failures.append(key)
    results.append({"key": key, "status": "FAIL", "detail": str(err)})
    print(f"FAIL {key} — {title} — {err}")


def info(line):
    print(f"INFO {line}")


def env_req(name):
    val = os.environ.get(name, "").strip()
    if not val and not SELFTEST:
        raise SystemExit(f"Missing required environment variable: {name}")
    return val


def wp_route(url):
    parsed = urlparse(url)
    flat = {k: v[0] for k, v in parse_qs(parsed.query, keep_blank_values=True).items()}
    route = flat.get("rest_route")
    if route is None:
        m = re.search(r"/wp-json(/.*)?$", parsed.path)
        route = m.group(1) if (m and m.group(1)) else parsed.path
    return route.rstrip("/") or "/"


def build_rest_url(rest_root, sub_route, query=None):
    base_url = rest_root.rstrip("/")
    sub = sub_route.lstrip("/")
    parsed = urlparse(base_url)
    qs = parse_qs(parsed.query, keep_blank_values=True)
    if "rest_route" in qs:
        rr = qs["rest_route"][0].rstrip("/") + "/" + sub
        url = f"{parsed.scheme}://{parsed.netloc}{parsed.path}?rest_route={rr}"
        if query:
            for k, v in query.items():
                url += f"&{k}={v}"
        return url
    url = f"{base_url}/{sub}"
    if query:
        qstr = "&".join(f"{k}={v}" for k, v in query.items())
        url += f"?{qstr}"
    return url


def db(sql):
    out = subprocess.run(
        [
            "mysql",
            "-h",
            "127.0.0.1",
            "-P",
            "3306",
            "-uroot",
            "-proot",
            "--batch",
            "--skip-column-names",
            "-e",
            sql,
            DB_MAIN,
        ],
        capture_output=True,
        text=True,
        timeout=30,
    )
    if out.returncode != 0:
        raise RuntimeError(f"db query failed: {out.stderr.strip()[:300]}")
    return out.stdout.strip()


def db1(sql):
    raw = db(sql)
    if not raw:
        return 0
    cell = raw.split("\n")[0].strip()
    if not cell or cell.upper() == "NULL":
        return 0
    return int(float(cell))


def dbrows(sql):
    raw = db(sql)
    if not raw:
        return []
    return [tuple(line.split("\t")) for line in raw.split("\n")]


def T(name):
    return f"{DB_PREFIX}{name}"


def wp_pepper():
    out = subprocess.run(
        [
            "wp",
            f"--path={WP_DIR}",
            "--allow-root",
            "eval",
            'echo (string) get_option("cpms_otp_pepper","");',
        ],
        capture_output=True,
        text=True,
        timeout=30,
    )
    if out.returncode != 0:
        raise RuntimeError("wp eval for cpms_otp_pepper failed")
    return out.stdout.strip()


def assert_subscriber_in_db(user_id, label):
    cap_hit = db1(
        f"SELECT COUNT(*) FROM {T('usermeta')} "
        f"WHERE user_id={int(user_id)} AND meta_key='{DB_PREFIX}capabilities' "
        "AND meta_value LIKE '%\"subscriber\"%' "
        "AND meta_value NOT LIKE '%cpms_doctor%' "
        "AND meta_value NOT LIKE '%cpms_secretary%' "
        "AND meta_value NOT LIKE '%cpms_accountant%' "
        "AND meta_value NOT LIKE '%administrator%'"
    )
    assert cap_hit == 1, f"{label} user_id={user_id} is not a pure WordPress subscriber"


def assert_no_horizontal_overflow(page, label):
    sw = page.evaluate("document.documentElement.scrollWidth")
    iw = page.evaluate("window.innerWidth")
    assert sw <= iw + 1, f"{label} horizontal overflow: scrollWidth={sw} > innerWidth={iw}"


def login(page, username, password, tag):
    page.goto(f"{BASE}/wp-login.php", wait_until="networkidle")
    page.fill("#user_login", username)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle")
    cur = page.url or ""
    if "wp-login.php" in cur and "loggedout" not in cur:
        raise RuntimeError(f"{tag}: wp-login.php did not complete authentication")


def finance_refresh_ready(selected_location, selector_disabled, awaiting_rows, paid_rows, expected_location):
    """The finance portal refresh is complete only after both required panels resolve."""
    loading = "در حال بارگذاری"
    return (str(selected_location or "") == str(expected_location)
            and not selector_disabled
            and loading not in str(awaiting_rows or "")
            and loading not in str(paid_rows or ""))


def wait_for_finance_refresh(page, location_id):
    """Wait on the rendered result of the sequential location-scoped refresh, not elapsed time."""
    page.wait_for_function(
        """(expectedLocation) => {
            const selector = document.querySelector('[data-role="finance-location"]');
            const awaiting = document.querySelector('[data-role="finance-rows"]');
            const paid = document.querySelector('[data-role="finance-paid-rows"]');
            const loading = 'در حال بارگذاری';
            return !!selector && !!awaiting && !!paid
                && String(selector.value || '') === String(expectedLocation)
                && !selector.disabled
                && !String(awaiting.innerText || '').includes(loading)
                && !String(paid.innerText || '').includes(loading);
        }""",
        arg=str(location_id),
        timeout=25000,
    )


def run_selftest():
    u1 = build_rest_url("http://localhost:8080/wp-json/clinic/v1", "staff/portal/finance/reconciliation", {"from": "2026-10-24"})
    assert u1 == "http://localhost:8080/wp-json/clinic/v1/staff/portal/finance/reconciliation?from=2026-10-24"
    u2 = build_rest_url("http://localhost:8080/?rest_route=/clinic/v1", "appointments/9/reschedule-options")
    assert u2 == "http://localhost:8080/?rest_route=/clinic/v1/appointments/9/reschedule-options"
    assert wp_route(u1) == "/clinic/v1/staff/portal/finance/reconciliation"
    assert wp_route(u2) == "/clinic/v1/appointments/9/reschedule-options"

    # The location-switch regression is a state predicate, not an arbitrary delay:
    # wrong scope, an active request, or either unresolved panel must remain false.
    assert not finance_refresh_ready("12", False, "rows", "paid rows", "13")
    assert not finance_refresh_ready("13", True, "rows", "paid rows", "13")
    assert not finance_refresh_ready("13", False, "در حال بارگذاری…", "paid rows", "13")
    assert not finance_refresh_ready("13", False, "rows", "در حال بارگذاری…", "13")
    assert finance_refresh_ready("13", False, "rows rendered", "paid rows rendered", "13")

    # Reception board envelope (RestBase::success => {"data": {...}}).
    good = parse_board_envelope(
        {"data": {"date": "2026-10-24", "appointments": [
            {"id": 11, "status": "confirmed", "time": "09:05"},
            {"id": 12, "status": "confirmed", "time": "09:06"},
        ]}}
    )
    assert good["date"] == "2026-10-24"
    assert good["appointment_ids"] == [11, 12]

    malformed_payloads = {
        "missing envelope": {"date": "2026-10-24", "appointments": []},
        "envelope not object": {"data": "2026-10-24"},
        "date missing": {"data": {"appointments": []}},
        "date not Y-m-d": {"data": {"date": "24/10/2026", "appointments": []}},
        "appointments missing": {"data": {"date": "2026-10-24"}},
        "appointments not list": {"data": {"date": "2026-10-24", "appointments": {}}},
        "appointment not object": {"data": {"date": "2026-10-24", "appointments": ["11"]}},
        "appointment id not int": {"data": {"date": "2026-10-24", "appointments": [{"id": "11", "status": "confirmed"}]}},
        "appointment id zero": {"data": {"date": "2026-10-24", "appointments": [{"id": 0, "status": "confirmed"}]}},
        "appointment status missing": {"data": {"date": "2026-10-24", "appointments": [{"id": 11}]}},
        "top-level list": [{"date": "2026-10-24"}],
        "top-level null": None,
    }
    for label, payload in malformed_payloads.items():
        try:
            parse_board_envelope(payload)
        except AssertionError:
            continue
        raise AssertionError(f"malformed reception board payload was accepted: {label}")

    # Hold diagnostics: only the attempt issued after the resume marker, and only
    # for the expected slot, may be reported as "the" hold attempt.
    probe = BookingProbe()
    probe.entries.extend([
        {"route": "/clinic/v1/booking/hold", "status": 200, "code": "", "slot_id": 900},
        {"route": "/clinic/v1/booking/quote", "status": 403, "code": "rest_cookie_invalid_nonce"},
        {"route": "/clinic/v1/booking/hold", "status": 403, "code": "rest_cookie_invalid_nonce", "slot_id": 901},
    ])
    assert [e["status"] for e in probe.hold_attempts(1)] == [403]
    assert probe.hold_attempts(0)[0]["slot_id"] == 900
    described = probe.describe(1)
    assert "quote http=403 code=rest_cookie_invalid_nonce" in described
    assert "hold http=403 code=rest_cookie_invalid_nonce slot_id=901" in described
    assert "token" not in described and "nonce=" not in described

    print("PASS day2-selftest — URL/route + REST envelopes + observable finance refresh readiness passed")
    return 0


BOOKING_ROUTES = (
    "/clinic/v1/availability",
    "/clinic/v1/booking/quote",
    "/clinic/v1/booking/hold",
    "/clinic/v1/booking/confirm",
    "/clinic/v1/otp/request",
    "/clinic/v1/otp/verify",
)
_ERROR_CODE = re.compile(r"^[A-Z][A-Z0-9_]{1,40}$")
_REQUEST_ID_KEYS = ("slot_id", "clinician_id", "location_id")


class BookingProbe:
    """Sanitized REST outcomes of the public-booking journey.

    Every entry keeps only: the REST route, the HTTP status, an allowlisted error
    code, and the three numeric ids the client itself posted. Tokens, nonces,
    cookies, mobile numbers and names are never read, so a failure message can be
    published without leaking anything. The probe exists because the previous
    failure line (`stage=hold-countdown root_state=bookable visible=0`) could not
    distinguish "the server rejected the hold" from "the browser never asked".
    """

    def __init__(self, page=None):
        self.entries = []
        if page is not None:
            page.on("response", self._on_response)

    def _on_response(self, response):
        try:
            route = wp_route(response.url)
        except Exception:  # noqa: BLE001 - diagnostics must never break the journey
            return
        if route not in BOOKING_ROUTES:
            return
        entry = {"route": route, "status": int(getattr(response, "status", 0) or 0), "code": ""}
        try:
            payload = response.json()
        except Exception:  # noqa: BLE001 - non-JSON error pages are still recorded by status
            payload = None
        if isinstance(payload, dict):
            for candidate in (payload.get("code"), (payload.get("data") or {}).get("code")
                              if isinstance(payload.get("data"), dict) else None):
                if isinstance(candidate, str) and _ERROR_CODE.match(candidate):
                    entry["code"] = candidate
                    break
        try:
            posted = response.request.post_data_json
        except Exception:  # noqa: BLE001
            posted = None
        if isinstance(posted, dict):
            for key in _REQUEST_ID_KEYS:
                if key in posted:
                    try:
                        entry[key] = int(posted[key])
                    except (TypeError, ValueError):
                        continue
        self.entries.append(entry)

    def hold_attempts(self, since=0):
        return [e for e in self.entries[since:] if e["route"].endswith("/booking/hold")]

    def describe(self, since=0, limit=8):
        rows = []
        for entry in self.entries[since:][-limit:]:
            bits = [entry["route"].rsplit("/", 2)[-2] + "/" + entry["route"].rsplit("/", 1)[-1],
                    f"http={entry['status']}"]
            if entry["code"]:
                bits.append(f"code={entry['code']}")
            for key in _REQUEST_ID_KEYS:
                if key in entry:
                    bits.append(f"{key}={entry[key]}")
            rows.append(" ".join(bits))
        return "; ".join(rows) if rows else "no booking REST call observed"


def observe_hold_attempt(page, probe, expected_slot_id, since, timeout=25000):
    """Bounded wait for the hold attempt the resume issues for the fixture slot.

    "Any active hold on the slot" is explicitly NOT enough: an attempt counts only
    if it was recorded after `since` (i.e. issued by the post-login resume) AND its
    posted slot id equals the fixture slot. The existing 25s bound is reused; no
    timeout is increased, and the caller keeps the original visibility gate as the
    only pass/fail criterion — this watcher exists so a failure can name the real
    cause instead of only "visible=0".
    """
    deadline = time.monotonic() + (timeout / 1000.0)
    while time.monotonic() < deadline:
        for attempt in probe.hold_attempts(since):
            if expected_slot_id <= 0 or attempt.get("slot_id") == expected_slot_id:
                return attempt
        try:
            if page.locator('[data-role="hold-countdown"]').is_visible():
                return None
        except Exception:  # noqa: BLE001 - page may be mid-navigation
            pass
        page.wait_for_timeout(100)
    return None


def hold_widget_fingerprint(page):
    """Non-PII state of the booking continuation nodes, for honest failure lines."""
    try:
        return page.evaluate(
            """() => {
                const root = document.querySelector('.cpms-public-booking');
                const pick = (role) => {
                    const node = root ? root.querySelector('[data-role="' + role + '"]') : null;
                    if (!node) { return 'absent'; }
                    return node.hidden ? 'hidden' : 'visible';
                };
                const box = root ? root.querySelector('[data-role="hold-countdown"]') : null;
                return {
                    countdown: pick('hold-countdown'),
                    countdown_value: box ? String((box.querySelector('[data-role="countdown-value"]') || {}).textContent || '').slice(0, 8) : '',
                    confirm: pick('confirm-btn'),
                    names: pick('names-form'),
                    continue_status: (root && (root.querySelector('[data-role="continue-status"]') || {}).textContent || '').slice(0, 80),
                };
            }"""
        )
    except Exception:  # noqa: BLE001 - diagnostics must never mask the real failure
        return {}


def visible_or_diagnose(page, selector, stage, probe=None, since=0, hold_attempt=None):
    """Keep the existing visibility gate; report a bounded, non-PII failure stage."""
    try:
        page.locator(selector).wait_for(state="visible", timeout=25000)
    except Exception as exc:
        root = page.locator(".cpms-public-booking")
        state = root.get_attribute("data-state", timeout=1000) if root.count() else "missing"
        message = f"public booking stage={stage} root_state={state} visible=0"
        if probe is not None:
            message += f" observed={probe.describe(since)}"
        if stage == "hold-countdown":
            if hold_attempt is None:
                message += " hold_attempt=none-for-expected-slot"
            else:
                message += (
                    f" hold_attempt=slot={hold_attempt.get('slot_id')}"
                    f" http={hold_attempt['status']}"
                    f"{' code=' + hold_attempt['code'] if hold_attempt['code'] else ''}"
                )
            widget = hold_widget_fingerprint(page)
            if widget:
                message += (
                    f" countdown={widget.get('countdown')}"
                    f" countdown_value={widget.get('countdown_value') or '-'}"
                    f" confirm={widget.get('confirm')}"
                    f" names_form={widget.get('names')}"
                )
        raise AssertionError(message) from exc


def run_journey_a_public_booking(browser, cfg):
    ctx_pub = browser.new_context(viewport={"width": 390, "height": 844}, locale="fa-IR")
    page_pub = ctx_pub.new_page()
    page_pub.set_default_timeout(25000)
    probe = BookingProbe(page_pub)
    try:
        page_pub.add_init_script(
            "(() => {"
            "  const tapKey = 'cpms-d2:tap:otp-verify';"
            "  const origFetch = window.fetch;"
            "  window.fetch = function () {"
            "    const p = origFetch.apply(this, arguments);"
            "    try {"
            "      const a0 = arguments[0];"
            "      const url = String(a0 && a0.url ? a0.url : a0);"
            "      if (url.indexOf('/otp/verify') !== -1) {"
            "        p.then(function (r) { return r.clone().text(); })"
            "         .then(function (t) { window.sessionStorage.setItem(tapKey, t); });"
            "      }"
            "    } catch (e) {}"
            "    return p;"
            "  };"
            "})();"
        )

        # 320px + 390px mobile layout checks on anonymous public booking surface
        page_pub.set_viewport_size({"width": 320, "height": 740})
        page_pub.goto(cfg["public_url"], wait_until="networkidle")
        assert page_pub.locator(".cpms-public-booking").count() == 1, "public booking root missing"
        assert_no_horizontal_overflow(page_pub, "public-booking-320")

        page_pub.set_viewport_size({"width": 390, "height": 844})
        page_pub.wait_for_timeout(150)
        assert_no_horizontal_overflow(page_pub, "public-booking-390")

        # 1) Public clinician switcher: select Clinician 2 first, then switch to Clinician 1
        c2_btn = page_pub.locator(f'.cpms-public-booking__clinician[data-clinician-id="{cfg["clinician_2"]}"]')
        c1_btn = page_pub.locator(f'.cpms-public-booking__clinician[data-clinician-id="{cfg["clinician_1"]}"]')
        assert c2_btn.count() == 1 and c1_btn.count() == 1, "both clinicians must render in public switcher"

        with page_pub.expect_response(lambda r: "/clinic/v1/availability" in r.url, timeout=25000) as av2_info:
            c2_btn.click()
        assert av2_info.value.status == 200, "Clinician 2 availability failed"
        page_pub.wait_for_function(
            "document.querySelector('.cpms-public-booking__panel').getAttribute('data-state') !== 'loading'"
        )

        with page_pub.expect_response(lambda r: "/clinic/v1/availability" in r.url, timeout=25000) as av1_info:
            c1_btn.click()
        assert av1_info.value.status == 200, "Clinician 1 availability failed"
        page_pub.wait_for_function(
            "document.querySelector('.cpms-public-booking__panel').getAttribute('data-state') !== 'loading'"
        )

        # 2) Public Location switcher: Clinician 1 has 2 Locations (Loc A2 -> Loc A1)
        loc_picker = page_pub.locator('[data-role="location-picker"]')
        loc_picker.wait_for(state="visible", timeout=15000)
        loc2_btn = page_pub.locator(f'[data-role="location-option"][data-location-id="{cfg["loc_a2"]}"]')
        loc1_btn = page_pub.locator(f'[data-role="location-option"][data-location-id="{cfg["loc_a1"]}"]')
        assert loc2_btn.count() == 1 and loc1_btn.count() == 1, "both Location options must render"

        loc2_btn.click()
        page_pub.locator(f'.cpms-public-booking__slot[data-slot-id="{cfg["pub_slot_doc1_loc2"]}"]').wait_for(state="visible")
        assert page_pub.locator(f'.cpms-public-booking__slot[data-slot-id="{cfg["pub_slot_doc1_loc1"]}"]').count() == 0

        loc1_btn.click()
        target_slot = page_pub.locator(f'.cpms-public-booking__slot[data-slot-id="{cfg["pub_slot_doc1_loc1"]}"]')
        target_slot.wait_for(state="visible")
        assert page_pub.locator(f'.cpms-public-booking__slot[data-slot-id="{cfg["pub_slot_doc1_loc2"]}"]').count() == 0

        # 3) Select slot -> verify booking confirmation summary
        with page_pub.expect_response(lambda r: "/clinic/v1/booking/quote" in r.url, timeout=25000) as q_info:
            target_slot.click()
        assert q_info.value.status == 200, "booking/quote failed"
        page_pub.wait_for_function(
            "document.querySelector('.cpms-public-booking__panel').getAttribute('data-state') === 'bookable'"
        )
        detail_box = page_pub.locator('[data-role="detail"]')
        detail_box.wait_for(state="visible")
        loc_name_txt = (page_pub.locator('[data-role="location-name"]').first.text_content() or "").strip()
        assert "ولیعصر" in loc_name_txt, "confirmation summary missing selected Location name"
        assert_no_horizontal_overflow(page_pub, "public-booking-summary-390")
        ok(
            "day2-A1-switchers-summary",
            "Patient Public Booking: clinician switcher + Location switcher + confirmation summary + 390/320 layout",
            f"clinic={cfg['clinic_a']} loc_a1={cfg['loc_a1']} loc_a2={cfg['loc_a2']} slot={cfg['pub_slot_doc1_loc1']}",
        )

        # 4) OTP handoff + first-time identity gate + final booking confirmation
        page_pub.locator('[data-role="continue-auth"]').click()
        visible_or_diagnose(page_pub, '[data-role="otp-mobile"]', "otp-mobile")
        page_pub.locator('[data-role="otp-mobile"]').fill(SYN_MOBILE)
        with page_pub.expect_response(lambda r: "/clinic/v1/otp/request" in r.url, timeout=25000) as otp_req:
            page_pub.locator('[data-auth-action="otp-request"]').click()
        assert otp_req.value.status == 200, f"otp/request HTTP {otp_req.value.status}"
        visible_or_diagnose(page_pub, '[data-role="otp-code"]', "otp-code")

        trows = dbrows(
            f"SELECT id, clinic_id FROM {T('cpms_otp_tokens')} WHERE mobile='{SYN_MOBILE}' ORDER BY id DESC LIMIT 1"
        )
        assert len(trows) == 1 and int(trows[0][1]) == cfg["clinic_a"], "OTP challenge missing or wrong clinic"
        pepper = wp_pepper()
        digest = hmac.new(pepper.encode(), KNOWN_OTP.encode(), hashlib.sha256).hexdigest()
        db(f"UPDATE {T('cpms_otp_tokens')} SET code_hash='{digest}' WHERE id={int(trows[0][0])}")

        page_pub.locator('[data-role="otp-code"]').fill(KNOWN_OTP)
        # مرزِ تشخیصی: هر تماسِ رزرو پس از این ایندکس متعلق به ادامهٔ پس از ورود است.
        resume_marker = len(probe.entries)
        with page_pub.expect_response(lambda r: "/clinic/v1/otp/verify" in r.url, timeout=25000) as otp_ver:
            with page_pub.expect_navigation(timeout=25000):
                page_pub.locator('[data-auth-action="otp-verify"]').click()
        assert otp_ver.value.status == 200, f"otp/verify HTTP {otp_ver.value.status}"
        page_pub.wait_for_load_state("networkidle")

        # فقط همین تلاشِ hold (پس از ورود، برای همان اسلاتِ fixture) موردانتظار است؛
        # «هر hold فعالی روی اسلات» معیار نیست. دروازهٔ اصلی همان visible است.
        hold_attempt = observe_hold_attempt(page_pub, probe, cfg["pub_slot_doc1_loc1"], resume_marker)
        info(f"day2-A1-resume hold_attempts={probe.hold_attempts(resume_marker)}")
        visible_or_diagnose(
            page_pub,
            '[data-role="hold-countdown"]',
            "hold-countdown",
            probe,
            resume_marker,
            hold_attempt,
        )
        if hold_attempt is not None and hold_attempt["status"] != 200:
            raise AssertionError(
                "public booking stage=hold-request — hold rejected for the fixture slot "
                f"slot={cfg['pub_slot_doc1_loc1']} http={hold_attempt['status']}"
                f"{' code=' + hold_attempt['code'] if hold_attempt['code'] else ''}"
            )
        visible_or_diagnose(page_pub, '[data-role="confirm-btn"]', "confirm-btn")

        # First-time patient identity gate: confirm without names -> 400 CLINIC_VALIDATION_FAILED
        with page_pub.expect_response(lambda r: "/clinic/v1/booking/confirm" in r.url, timeout=25000) as gate_resp:
            page_pub.locator('[data-role="confirm-btn"]').click()
        assert gate_resp.value.status == 400, f"expected 400 identity gate, got {gate_resp.value.status}"
        visible_or_diagnose(page_pub, '[data-role="names-form"]', "names-form")

        page_pub.locator('[data-role="patient-first-name"]').fill(SYN_FIRST_NAME)
        page_pub.locator('[data-role="patient-last-name"]').fill(SYN_LAST_NAME)
        with page_pub.expect_response(lambda r: "/clinic/v1/booking/confirm" in r.url, timeout=25000) as conf_resp:
            page_pub.locator('[data-role="confirm-btn"]').click()
        assert conf_resp.value.status == 200, f"booking/confirm HTTP {conf_resp.value.status}"
        visible_or_diagnose(page_pub, '[data-role="receipt"]', "receipt")
        ref_code = (page_pub.locator('[data-role="reference-code"]').first.text_content() or "").strip()
        assert re.fullmatch(r"AP-\d{8}-\d{2}", ref_code), "receipt reference_code format invalid"
        page_pub.set_viewport_size({"width": 320, "height": 740})
        page_pub.wait_for_timeout(100)
        assert_no_horizontal_overflow(page_pub, "public-booking-receipt-320")
        # شواهدِ غیر-PII از ترتیبِ واقعیِ تماس‌های REST — روی PASS هم منتشر می‌شود
        # تا «hold ساخته شد یا نه» هرگز حدس نباشد.
        info(f"day2-A1-rest {probe.describe(0, limit=12)}")
        ok(
            "day2-A1-otp-identity-confirm",
            "Patient Public Booking: OTP handoff + first-time identity gate + confirmed receipt + 320px receipt",
            f"otp_request=200 otp_verify=200 name_gate=400 confirm=200 slot={cfg['pub_slot_doc1_loc1']}",
        )
    finally:
        ctx_pub.close()


def run_journey_a_patient_reschedule(browser, cfg):
    ctx_pt = browser.new_context(viewport={"width": 390, "height": 844}, locale="fa-IR")
    page_pt = ctx_pt.new_page()
    page_pt.set_default_timeout(25000)
    try:
        login(page_pt, cfg["pt_login"], cfg["pass"], "day2_patient_reschedule")
        if "/cpms-patient-portal" not in (page_pt.url or ""):
            page_pt.goto(f"{BASE}/cpms-patient-portal/", wait_until="networkidle")
        page_pt.wait_for_selector(".cpms-patient-portal-shell", timeout=15000)
        assert "/wp-admin/" not in (page_pt.url or ""), "Patient Portal must not use wp-admin"

        # Completion surface: real landing summary + embedded real booking flow.
        page_pt.locator('[data-role="dashboard-section"]').wait_for(state="visible")
        page_pt.locator('[data-role="booking-section"] .cpms-public-booking[data-state="bookable"]').wait_for(
            state="visible"
        )
        assert page_pt.locator('[data-role="nav-dashboard"][aria-current="page"]').count() == 1
        assert page_pt.locator('[data-role="nav-booking"]').count() == 1
        assert page_pt.locator("html").get_attribute("dir") == "rtl"
        page_pt.locator('[data-role="nav-booking"]').click()
        page_pt.locator('[data-role="nav-booking"][aria-current="page"]').wait_for()
        page_pt.locator('[data-role="nav-dashboard"]').click()
        page_pt.locator('[data-role="nav-dashboard"][aria-current="page"]').wait_for()

        appt_id = cfg["resched_appt"]
        appt_row = page_pt.locator(
            f'button[data-role="reschedule-appointment"][data-appointment-id="{appt_id}"]'
        ).locator("xpath=ancestor::tr[1]")
        appointment_detail = appt_row.locator('[data-role="appointment-details"]')
        appointment_detail.wait_for(state="visible")
        detail_text = appointment_detail.inner_text()
        for label in ("بیمار", "پزشک", "مرکز درمانی", "محل مراجعه"):
            assert label in detail_text, f"appointment detail missing {label}"

        # Verify 390px and 320px mobile layouts on Patient Portal
        assert_no_horizontal_overflow(page_pt, "patient-portal-390")
        page_pt.set_viewport_size({"width": 320, "height": 740})
        page_pt.wait_for_timeout(120)
        assert_no_horizontal_overflow(page_pt, "patient-portal-320")
        page_pt.set_viewport_size({"width": 390, "height": 844})

        appt_id = cfg["resched_appt"]
        resched_btn = page_pt.locator(
            f'button[data-role="reschedule-appointment"][data-appointment-id="{appt_id}"]'
        )
        resched_btn.wait_for(state="visible")

        with page_pt.expect_response(
            lambda r: f"/appointments/{appt_id}/reschedule-options" in r.url, timeout=25000
        ) as opt_info:
            resched_btn.click()
        opt_resp = opt_info.value
        assert opt_resp.status == 200, f"reschedule-options HTTP {opt_resp.status}"
        opt_json = opt_resp.json()
        opt_data = opt_json.get("data") if isinstance(opt_json.get("data"), dict) else opt_json
        offered_ids = [
            int(s["slot_id"])
            for d in (opt_data.get("days") or [])
            for s in (d.get("slots") or [])
        ]
        assert cfg["resched_collide_slot"] in offered_ids, "collision candidate slot missing"
        assert cfg["resched_clean_slot"] in offered_ids, "clean destination slot missing"
        assert cfg["resched_beyond_slot"] not in offered_ids, "+40d slot must be excluded by max_future_days=30"

        resched_date = next(
            d["date"]
            for d in (opt_data.get("days") or [])
            if any(int(s["slot_id"]) == cfg["resched_collide_slot"] for s in (d.get("slots") or []))
        )

        page_pt.locator('[data-role="reschedule-picker"]').wait_for(state="visible")
        assert_no_horizontal_overflow(page_pt, "patient-reschedule-panel-390")
        page_pt.set_viewport_size({"width": 320, "height": 740})
        page_pt.wait_for_timeout(100)
        assert_no_horizontal_overflow(page_pt, "patient-reschedule-panel-320")
        page_pt.set_viewport_size({"width": 390, "height": 844})

        # Select the target day tab first, then select the collision slot
        day_tab = page_pt.locator(f'[data-role="reschedule-day"][data-slot-date="{resched_date}"]')
        day_tab.wait_for(state="visible")
        day_tab.click()

        collide_btn = page_pt.locator(
            f'[data-role="reschedule-slot"][data-slot-id="{cfg["resched_collide_slot"]}"]'
        )
        collide_btn.wait_for(state="visible")
        collide_btn.click()

        db(
            f"UPDATE {T('cpms_schedule_slots')} SET booked_count = capacity "
            f"WHERE id = {cfg['resched_collide_slot']}"
        )

        with page_pt.expect_response(
            lambda r: f"/appointments/{appt_id}/reschedule" in r.url and "reschedule-options" not in r.url,
            timeout=25000,
        ) as col_resp_info:
            with page_pt.expect_response(
                lambda r: f"/appointments/{appt_id}/reschedule-options" in r.url, timeout=25000
            ) as refresh_info:
                page_pt.locator('[data-role="reschedule-confirm"]').click()
        assert col_resp_info.value.status == 409, f"expected 409 slot collision, got {col_resp_info.value.status}"
        assert refresh_info.value.status == 200, "collision recovery auto-refresh failed"
        page_pt.locator('[data-role="reschedule-picker"]').wait_for(state="visible")
        page_pt.locator('[data-role="reschedule-error"]').wait_for(state="visible")

        # Re-select target day tab after options reload; verify taken slot is gone and error stayed visible
        day_tab = page_pt.locator(f'[data-role="reschedule-day"][data-slot-date="{resched_date}"]')
        day_tab.wait_for(state="visible")
        day_tab.click()
        assert page_pt.locator('[data-role="reschedule-error"]').is_visible(), "collision error must stay visible across refresh"
        assert (
            page_pt.locator(
                f'[data-role="reschedule-slot"][data-slot-id="{cfg["resched_collide_slot"]}"]'
            ).count()
            == 0
        ), "taken slot must disappear after collision recovery refresh"

        # Recover cleanly by choosing the remaining open slot and confirming
        clean_btn = page_pt.locator(
            f'[data-role="reschedule-slot"][data-slot-id="{cfg["resched_clean_slot"]}"]'
        )
        clean_btn.wait_for(state="visible")
        clean_btn.click()
        with page_pt.expect_response(
            lambda r: f"/appointments/{appt_id}/reschedule" in r.url and "reschedule-options" not in r.url,
            timeout=25000,
        ) as ok_resched:
            with page_pt.expect_navigation(timeout=25000):
                page_pt.locator('[data-role="reschedule-confirm"]').click()
        assert ok_resched.value.status == 200, f"reschedule confirm HTTP {ok_resched.value.status}"
        page_pt.wait_for_load_state("networkidle")

        old_status = db(f"SELECT status FROM {T('cpms_appointments')} WHERE id={appt_id}")
        new_count = db1(
            f"SELECT COUNT(*) FROM {T('cpms_appointments')} "
            f"WHERE rescheduled_from={appt_id} AND slot_id={cfg['resched_clean_slot']} AND status='confirmed'"
        )
        assert old_status == "rescheduled" and new_count == 1, "DB state after reschedule mismatch"
        ok(
            "day2-A2-reschedule-collision-recovery",
            "Patient Portal: dashboard + integrated booking + clear details + reschedule collision recovery + 390/320 layout",
            f"old_appt={appt_id} old_status={old_status} new_confirmed={new_count} beyond_excluded=1 details=1 booking=1",
        )
    finally:
        ctx_pt.close()


def parse_board_envelope(payload):
    """Read the reception board payload from the real CPMS REST envelope.

    `RestBase::success()` wraps every payload as `{"data": <payload>}`. The board
    handler's payload carries `date` (the server-authoritative operational date)
    and `appointments` (the operational-day projection). Malformed or unexpected
    shapes must fail loudly: silently accepting them is how a date/envelope
    mismatch could hide a real regression.
    """
    if not isinstance(payload, dict):
        raise AssertionError(f"reception board envelope is not an object: {type(payload).__name__}")
    if "data" not in payload:
        raise AssertionError(
            "reception board response is missing the CPMS REST 'data' envelope; "
            f"top-level keys={sorted(payload.keys())[:6]}"
        )
    data = payload["data"]
    if not isinstance(data, dict):
        raise AssertionError(f"reception board 'data' is not an object: {type(data).__name__}")
    date = data.get("date")
    if not isinstance(date, str) or re.fullmatch(r"\d{4}-\d{2}-\d{2}", date) is None:
        raise AssertionError(f"reception board 'data.date' is not a Y-m-d string: {date!r}")
    appointments = data.get("appointments")
    if not isinstance(appointments, list):
        raise AssertionError(f"reception board 'data.appointments' is not a list: {type(appointments).__name__}")
    ids = []
    for row in appointments:
        if not isinstance(row, dict):
            raise AssertionError("reception board appointment entry is not an object")
        row_id = row.get("id")
        if not isinstance(row_id, int) or isinstance(row_id, bool) or row_id <= 0:
            raise AssertionError(f"reception board appointment id is not a positive int: {row_id!r}")
        if not isinstance(row.get("status"), str) or row["status"] == "":
            raise AssertionError(f"reception board appointment {row_id} has no status")
        ids.append(row_id)
    return {"date": date, "appointment_ids": ids}


def anchor_reception_rows(cfg, target_date=None):
    """Re-anchor the Journey B reception rows against the SERVER clock.

    The rows must be, at the instant the reception board is read: already due,
    still inside `queue.no_show_grace_minutes`, and on the operational date the
    server itself resolves. Any fixed wall time (the retired 08:20/08:40/09:00
    Tehran rows) fails that for some clock, which is the defect this PR fixes.
    The anchor delegates to the same pure policy the fixture uses, with the
    server's `now`, the Location's IANA timezone from the database and each
    Clinic's own grace. It never changes status and never disables the job.
    """
    ids = cfg["sec_reanchor_ids"]
    cmd = ["php", "tests/bin/day2-anchor-reception-rows.php", f"--ids={ids}"]
    if target_date:
        cmd.append(f"--date={target_date}")
    env = dict(os.environ)
    env["WP_HOME"] = WP_DIR
    proc = subprocess.run(cmd, capture_output=True, text=True, env=env, timeout=120)
    out = (proc.stdout or "").strip()
    err = (proc.stderr or "").strip()
    if proc.returncode != 0 or "DAY2_ANCHOR_OK" not in out:
        raise AssertionError(
            "reception row anchor failed: exit="
            f"{proc.returncode} detail={(err or out)[:300]}"
        )
    match = re.search(r"\bdate=(\d{4}-\d{2}-\d{2})\b", out)
    if not match:
        raise AssertionError(f"reception row anchor returned no date: {out[:200]}")
    info(out)
    return match.group(1)


def select_reception_scope_and_load_board(page, cfg):
    """Existing reception scope selection, unchanged; returns the board payload."""
    assert "/wp-admin/" not in (page.url or ""), "Receptionist portal must stay out of wp-admin"

    clinic_sel = page.locator('[data-role="sr-clinic-select"]')
    clinic_sel.wait_for(state="visible", timeout=20000)
    page.wait_for_function(
        """(cid) => {
            const s = document.querySelector('[data-role="sr-clinic-select"]');
            return s && Array.from(s.options).some(o => o.value === String(cid));
        }""",
        arg=cfg["clinic_a"],
        timeout=20000,
    )
    with page.expect_response(
        lambda r: "/staff/portal/reception/context" in r.url, timeout=25000
    ) as ctx_info:
        clinic_sel.select_option(str(cfg["clinic_a"]))
    assert ctx_info.value.status == 200, "reception clinic context failed"

    loc_sel = page.locator('[data-role="sr-location-select"]')
    page.wait_for_function(
        """(lid) => {
            const s = document.querySelector('[data-role="sr-location-select"]');
            return s && !s.disabled && Array.from(s.options).some(o => o.value === String(lid));
        }""",
        arg=cfg["loc_a1"],
        timeout=20000,
    )
    with page.expect_response(
        lambda r: "/staff/portal/reception/board" in r.url, timeout=25000
    ) as board_info:
        loc_sel.select_option(str(cfg["loc_a1"]))
    assert board_info.value.status == 200, "reception board load failed"
    try:
        return board_info.value.json()
    except Exception as exc:  # malformed body is a failure, never a silent pass
        raise AssertionError(f"reception board response is not decodable JSON: {exc}") from exc


def run_journey_b_receptionist(browser, cfg):
    assert_subscriber_in_db(cfg["sec_user_id"], "receptionist")
    ctx_sec = browser.new_context(viewport={"width": 390, "height": 844}, locale="fa-IR")
    page_sec = ctx_sec.new_page()
    page_sec.set_default_timeout(25000)
    try:
        login(page_sec, cfg["sec_login"], cfg["pass"], "day2_subscriber_receptionist")
        page_sec.goto(cfg["reception_url"], wait_until="networkidle")
        assert "/wp-admin/" not in (page_sec.url or ""), "Receptionist portal must stay out of wp-admin"

        # Clock-safe anchoring: the rows are placed "already due, inside grace,
        # on the server's operational date" immediately before the board is
        # read, so the real visits.no_show job cannot sweep them first and the
        # board cannot resolve a different operational date.
        #
        # The retry loop exists for ONE reason: a genuine local-midnight
        # rollover between the anchor and the board read. It then reloads the
        # portal, re-establishes the intended Clinic + Location, fetches a fresh
        # board and re-anchors to the date the SERVER reports. It never sleeps,
        # never loosens an assertion and is bounded.
        anchor_date = anchor_reception_rows(cfg)
        board = {}
        for attempt in (1, 2, 3):
            if attempt > 1:
                page_sec.reload(wait_until="networkidle")
                page_sec.wait_for_selector('[data-role="sr-clinic-select"]', timeout=20000)
            payload = select_reception_scope_and_load_board(page_sec, cfg)
            board = parse_board_envelope(payload)
            if board["date"] == anchor_date:
                break
            assert attempt < 3, (
                "reception board operational date never converged with the anchored "
                f"date after {attempt} attempts: anchored={anchor_date} board={board['date']}"
            )
            info(
                f"reception_reanchor attempt={attempt} anchored={anchor_date} "
                f"board={board['date']}"
            )
            anchor_date = anchor_reception_rows(cfg, board["date"])

        assert cfg["sec_appt_doc1"] in board["appointment_ids"], (
            "anchored Doc1 reception row is missing from the operational-day board"
        )
        assert cfg["sec_appt_doc2"] in board["appointment_ids"], (
            "anchored Doc2 reception row is missing from the operational-day board"
        )

        row_doc1 = page_sec.locator(
            f'tr[data-role="sr-row"][data-appointment-id="{cfg["sec_appt_doc1"]}"]'
        )
        row_doc2 = page_sec.locator(
            f'tr[data-role="sr-row"][data-appointment-id="{cfg["sec_appt_doc2"]}"]'
        )
        row_doc1.wait_for(state="visible", timeout=15000)
        row_doc2.wait_for(state="visible", timeout=15000)

        # 1) Daily clinician filter isolates Clinician 1 vs Clinician 2 rows
        clin_filter = page_sec.locator('[data-role="sr-schedule-clinician-filter"]')
        clin_filter.select_option(str(cfg["clinician_1"]))
        assert row_doc1.count() == 1 and row_doc1.is_visible(), "Clinician 1 row must stay visible when filtered to Clinician 1"
        assert row_doc2.count() == 0, "Clinician 2 row must be excluded when filtered to Clinician 1"
        clin_filter.select_option("")
        row_doc1.wait_for(state="visible", timeout=10000)
        row_doc2.wait_for(state="visible", timeout=10000)

        # Check 320px and 390px reception layout
        assert_no_horizontal_overflow(page_sec, "reception-390")
        page_sec.set_viewport_size({"width": 320, "height": 740})
        page_sec.wait_for_timeout(100)
        assert_no_horizontal_overflow(page_sec, "reception-320")
        page_sec.set_viewport_size({"width": 390, "height": 844})

        # 2) Required reason validation (blank reason rejected client-side without mutating DB)
        row_doc1.locator('[data-role="sr-noshow-open"]').click()
        ns_row = page_sec.locator(
            f'tr[data-role="sr-row-noshow"][data-appointment-id="{cfg["sec_appt_doc1"]}"]'
        )
        ns_row.wait_for(state="visible")
        ns_row.locator('[data-role="sr-noshow-reason"]').fill("   ")
        ns_row.locator('[data-role="sr-noshow-confirm"]').click()
        assert ns_row.locator('[data-role="sr-noshow-reason"]').get_attribute("aria-invalid") == "true"
        assert (
            db(f"SELECT status FROM {T('cpms_appointments')} WHERE id={cfg['sec_appt_doc1']}") == "confirmed"
        ), "blank no-show reason must not mutate appointment status"

        # 3) Authorized no-show submission with valid reason -> 200 + APPOINTMENT_NO_SHOW audit
        ns_row.locator('[data-role="sr-noshow-reason"]').fill("عدم حضور بیمار در نوبت صبح")
        with page_sec.expect_response(
            lambda r: f"/staff/portal/reception/appointments/{cfg['sec_appt_doc1']}/no-show" in r.url,
            timeout=25000,
        ) as ns_resp_info:
            ns_row.locator('[data-role="sr-noshow-confirm"]').click()
        assert ns_resp_info.value.status == 200, f"no-show HTTP {ns_resp_info.value.status}"
        assert (
            db(f"SELECT status FROM {T('cpms_appointments')} WHERE id={cfg['sec_appt_doc1']}") == "no_show"
        ), "appointment status did not transition to no_show"
        ns_audits = db1(
            f"SELECT COUNT(*) FROM {T('cpms_audit_logs')} "
            f"WHERE action='APPOINTMENT_NO_SHOW' AND resource_id={cfg['sec_appt_doc1']}"
        )
        assert ns_audits >= 1, "APPOINTMENT_NO_SHOW audit missing"

        # 4) Cross-Clinic denial: attempting no-show on foreign Clinic B appointment fails closed
        cross_res = page_sec.evaluate(
            """async ([foreignAppt, clinicA, locA1, clinicB, locB1]) => {
                const cfgEl = document.getElementById('cpms-staff-reception-config');
                const c = JSON.parse(cfgEl.textContent);
                const root = String(c.rest_root || '').replace(/\\/+$/, '');
                const sep = root.includes('?') ? '&' : '/';
                const build = (sub) => {
                    const u = new URL(root, window.location.origin);
                    if (u.searchParams.has('rest_route')) {
                        u.searchParams.set('rest_route', u.searchParams.get('rest_route').replace(/\\/+$/, '') + '/' + sub);
                        return u.toString();
                    }
                    return root + '/' + sub;
                };
                const url = build('staff/portal/reception/appointments/' + foreignAppt + '/no-show');
                const r1 = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': c.nonce,
                        'X-CPMS-Clinic-Id': String(clinicA),
                        'X-CPMS-Location-Id': String(locA1)
                    },
                    body: JSON.stringify({ reason: 'تست کلینیک بیگانه' })
                });
                const r2 = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': c.nonce,
                        'X-CPMS-Clinic-Id': String(clinicB),
                        'X-CPMS-Location-Id': String(locB1)
                    },
                    body: JSON.stringify({ reason: 'تست کلینیک بیگانه' })
                });
                return { s1: r1.status, s2: r2.status };
            }""",
            [cfg["foreign_appt"], cfg["clinic_a"], cfg["loc_a1"], cfg["clinic_b"], cfg["loc_b1"]],
        )
        assert cross_res["s1"] == 404 and cross_res["s2"] == 403, f"unexpected cross-clinic statuses: {cross_res}"
        assert (
            db(f"SELECT status FROM {T('cpms_appointments')} WHERE id={cfg['foreign_appt']}") == "confirmed"
        ), "foreign appointment mutated"
        ok(
            "day2-B-subscriber-receptionist",
            "Subscriber Receptionist: clinician filter + reason validation + no-show + cross-Clinic denial",
            f"sec_sub=1 no_show_appt={cfg['sec_appt_doc1']} audit={ns_audits} cross_s1={cross_res['s1']} cross_s2={cross_res['s2']}",
        )
    finally:
        ctx_sec.close()


def ensure_doctor_portal_ready(page, clinic_id, location_id):
    page.wait_for_selector("#cpms-doctor-portal-app", timeout=20000)
    page.wait_for_function(
        """() => {
            const q = document.querySelector('[data-role="queue-section"]');
            const c = document.querySelector('[data-role="clinic-selector-wrap"]');
            const l = document.querySelector('[data-role="location-selector-wrap"]');
            return (q && !q.hidden) || (c && !c.hidden) || (l && !l.hidden);
        }""",
        timeout=25000,
    )
    c_wrap = page.locator('[data-role="clinic-selector-wrap"]')
    if c_wrap.is_visible():
        c_sel = page.locator('[data-role="clinic-select"]')
        if c_sel.input_value() != str(clinic_id):
            c_sel.select_option(str(clinic_id))
    page.wait_for_function(
        """() => {
            const q = document.querySelector('[data-role="queue-section"]');
            const l = document.querySelector('[data-role="location-selector-wrap"]');
            return (q && !q.hidden) || (l && !l.hidden);
        }""",
        timeout=20000,
    )
    l_wrap = page.locator('[data-role="location-selector-wrap"]')
    if l_wrap.is_visible():
        l_sel = page.locator('[data-role="location-select"]')
        if l_sel.input_value() != str(location_id):
            with page.expect_response(lambda r: "/doctor/today" in r.url, timeout=25000):
                l_sel.select_option(str(location_id))
    page.locator('[data-role="queue-section"]').wait_for(state="visible", timeout=20000)


def run_journey_c_doctors(browser, cfg):
    assert_subscriber_in_db(cfg["doc1_user_id"], "doctor_1")
    assert_subscriber_in_db(cfg["doc_deny_user_id"], "doctor_deny")

    # C1: Authorized Subscriber Doctor 1 — own private notes + RX_READ visible, colleague private notes hidden
    ctx_doc1 = browser.new_context(viewport={"width": 1280, "height": 800}, locale="fa-IR")
    page_doc1 = ctx_doc1.new_page()
    page_doc1.set_default_timeout(25000)
    try:
        login(page_doc1, cfg["doc1_login"], cfg["pass"], "day2_subscriber_doctor_1")
        page_doc1.goto(cfg["doctor_url"], wait_until="networkidle")
        assert "/wp-admin/" not in (page_doc1.url or ""), "Doctor portal must stay out of wp-admin"
        ensure_doctor_portal_ready(page_doc1, cfg["clinic_a"], cfg["loc_a1"])

        visit_id = cfg["active_visit_doc1"]
        q_item = page_doc1.locator(f'[data-role="queue-item"][data-visit-id="{visit_id}"]')
        q_item.wait_for(state="visible", timeout=20000)
        with page_doc1.expect_response(
            lambda r: f"/doctor/portal/visits/{visit_id}/record" in r.url, timeout=25000
        ) as rec_info:
            q_item.click()
        assert rec_info.value.status == 200, f"workspace record HTTP {rec_info.value.status}"

        mh_btn = page_doc1.locator('[data-role="workspace-med-history-load"]')
        mh_btn.wait_for(state="visible", timeout=15000)
        with page_doc1.expect_response(
            lambda r: f"/doctor/portal/visits/{visit_id}/medical-history" in r.url, timeout=25000
        ) as mh_info:
            mh_btn.click()
        assert mh_info.value.status == 200, f"medical-history HTTP {mh_info.value.status}"
        page_doc1.locator('[data-role="workspace-med-history-list"]').wait_for(state="visible", timeout=15000)

        mh_sec_txt = page_doc1.locator('[data-role="workspace-med-history-section"]').inner_text()
        assert "یادداشت خصوصی دکتر سپهری قابل مشاهده برای خودش" in mh_sec_txt, "own doctor_private note missing"
        assert "تشخیص عمومی دکتر کاظمی در پرونده قبلی" in mh_sec_txt, "colleague patient_visible note missing"
        assert "ValsartanD2" in mh_sec_txt, "finalized prescription missing despite RX_READ"
        assert "پنی‌سیلین" in mh_sec_txt, "patient allergy summary missing"
        assert "یادداشت محرمانه دکتر کاظمی هرگز افشا نشود" not in mh_sec_txt, "LEAK: colleague doctor_private note rendered!"

        mh_audits = db1(
            f"SELECT COUNT(*) FROM {T('cpms_audit_logs')} "
            f"WHERE action='MEDICAL_RECORD_VIEWED' AND resource_id={visit_id}"
        )
        assert mh_audits >= 1, "MEDICAL_RECORD_VIEWED audit missing"

        # Same-Clinic colleague Visit denial probe (`d2doc1` probing `active_visit_deny`)
        colleague_probe = page_doc1.evaluate(
            """async ([otherVisitId, clinicA, locA1]) => {
                const c = window.CPMS_DOCTOR_PORTAL;
                const root = String(c.restUrl || '').replace(/\\/+$/, '');
                const u = new URL(root, window.location.origin);
                let url;
                if (u.searchParams.has('rest_route')) {
                    u.searchParams.set('rest_route', u.searchParams.get('rest_route').replace(/\\/+$/, '') + '/doctor/portal/visits/' + otherVisitId + '/medical-history');
                    url = u.toString();
                } else {
                    url = root + '/doctor/portal/visits/' + otherVisitId + '/medical-history';
                }
                const r = await fetch(url, {
                    credentials: 'same-origin',
                    headers: {
                        'X-WP-Nonce': c.nonce,
                        'X-CPMS-Clinic-Id': String(clinicA),
                        'X-CPMS-Location-Id': String(locA1)
                    }
                });
                return r.status;
            }""",
            [cfg["active_visit_deny"], cfg["clinic_a"], cfg["loc_a1"]],
        )
        assert colleague_probe == 404, f"expected 404 probing colleague visit, got {colleague_probe}"
        ok(
            "day2-C1-subscriber-doctor-history",
            "Subscriber Doctor: medical history + own doctor_private + RX_READ + colleague private note hidden + audit",
            f"doc1_sub=1 visit={visit_id} own_private=1 colleague_private=0 rx=1 audit={mh_audits} other_visit={colleague_probe}",
        )
    finally:
        ctx_doc1.close()

    # C2: Deny-override Subscriber Doctor (`PRIVATE_NOTE_READ=deny`, `RX_READ=deny`) in isolated BrowserContext
    ctx_deny = browser.new_context(viewport={"width": 390, "height": 844}, locale="fa-IR")
    page_deny = ctx_deny.new_page()
    page_deny.set_default_timeout(25000)
    try:
        login(page_deny, cfg["doc_deny_login"], cfg["pass"], "day2_subscriber_doctor_deny")
        page_deny.goto(cfg["doctor_url"], wait_until="networkidle")
        ensure_doctor_portal_ready(page_deny, cfg["clinic_a"], cfg["loc_a1"])

        visit_deny = cfg["active_visit_deny"]
        q_deny = page_deny.locator(f'[data-role="queue-item"][data-visit-id="{visit_deny}"]')
        q_deny.wait_for(state="visible", timeout=20000)
        with page_deny.expect_response(
            lambda r: f"/doctor/portal/visits/{visit_deny}/record" in r.url, timeout=25000
        ):
            q_deny.click()

        mh_btn_deny = page_deny.locator('[data-role="workspace-med-history-load"]')
        mh_btn_deny.wait_for(state="visible", timeout=15000)
        with page_deny.expect_response(
            lambda r: f"/doctor/portal/visits/{visit_deny}/medical-history" in r.url, timeout=25000
        ) as mhd_info:
            mh_btn_deny.click()
        assert mhd_info.value.status == 200, f"deny-doctor medical-history HTTP {mhd_info.value.status}"
        page_deny.locator('[data-role="workspace-med-history-list"]').wait_for(state="visible", timeout=15000)

        deny_txt = page_deny.locator('[data-role="workspace-med-history-section"]').inner_text()
        assert "یادداشت عمومی قابل مشاهده برای دکتر نادری" in deny_txt, "patient_visible note must still render"
        assert "یادداشت خصوصی دکتر نادری مسدود با DENY" not in deny_txt, "DENY on PRIVATE_NOTE_READ failed to hide own private note"
        assert "AmlodipineDenyD2" not in deny_txt, "DENY on RX_READ failed to hide prescription"
        assert_no_horizontal_overflow(page_deny, "doctor-workspace-390")
        ok(
            "day2-C2-subscriber-doctor-deny",
            "Subscriber Doctor DENY > GRANT: PRIVATE_NOTE_READ & RX_READ deny overrides enforced in live browser",
            f"doc_deny_sub=1 visit={visit_deny} public_note=1 own_private=0 rx=0",
        )
    finally:
        ctx_deny.close()


def run_journey_d_accountant(browser, cfg):
    assert_subscriber_in_db(cfg["acc_user_id"], "accountant")
    ctx_acc = browser.new_context(viewport={"width": 390, "height": 844}, locale="fa-IR")
    page_acc = ctx_acc.new_page()
    page_acc.set_default_timeout(25000)
    try:
        login(page_acc, cfg["acc_login"], cfg["pass"], "day2_subscriber_accountant")
        page_acc.goto(cfg["finance_url"], wait_until="networkidle")
        assert "/wp-admin/" not in (page_acc.url or ""), "Staff Portal Finance must not use wp-admin"

        loc_sel = page_acc.locator('[data-role="finance-location"]')
        loc_sel.wait_for(state="visible", timeout=20000)
        loc_sel.select_option(str(cfg["loc_a1"]))
        wait_for_finance_refresh(page_acc, cfg["loc_a1"])

        with page_acc.expect_response(
            lambda r: "/staff/portal/finance/reconciliation" in r.url, timeout=25000
        ) as rec_a1_info:
            page_acc.locator('[data-role="finance-recon-today"]').click()
        rec_a1 = rec_a1_info.value
        assert rec_a1.status == 200, f"reconciliation Loc A1 HTTP {rec_a1.status}"
        raw_a1 = json.dumps(rec_a1.json(), ensure_ascii=False)

        page_acc.locator('[data-role="finance-recon-summary"]').wait_for(state="visible", timeout=15000)
        page_acc.locator('[data-role="finance-recon-table-wrap"]').wait_for(state="visible", timeout=15000)
        recon_panel_txt = page_acc.locator('[data-role="finance-reconciliation"]').inner_text()
        recon_panel_html = page_acc.locator('[data-role="finance-reconciliation"]').inner_html()

        assert "450000.00" in recon_panel_txt, "Loc A1 captured total 450000.00 missing"
        assert "250000.00" in recon_panel_txt, "Loc A1 cash total 250000.00 missing"
        assert "200000.00" in recon_panel_txt, "Loc A1 card_pos total 200000.00 missing"
        assert "150000.00" in recon_panel_txt, "Loc A1 open balance 150000.00 missing"
        assert cfg["pay_a1_cash"] in recon_panel_txt and cfg["pay_a1_pos"] in recon_panel_txt
        assert cfg["pay_a1_void"] not in recon_panel_txt, "voided payment must not appear in captured ledger"
        assert cfg["pay_a2_pos"] not in recon_panel_txt, "Loc A2 payment leaked into Loc A1 reconciliation"
        assert cfg["pay_b1_pos"] not in recon_panel_txt, "Foreign Clinic B payment leaked"

        # Zero PHI check on reconciliation payload & DOM
        for phi_token in ("کریمی", "جعفری", "MR-D2W-", "patient_id", "national_id", "0912"):
            assert phi_token not in raw_a1, f"PHI token {phi_token!r} leaked in reconciliation JSON"
            assert phi_token not in recon_panel_html, f"PHI token {phi_token!r} leaked in reconciliation DOM"

        # 390px and 320px mobile layout checks with reconciliation summary + table visible
        assert_no_horizontal_overflow(page_acc, "finance-reconciliation-390")
        page_acc.set_viewport_size({"width": 320, "height": 740})
        page_acc.wait_for_timeout(120)
        assert_no_horizontal_overflow(page_acc, "finance-reconciliation-320")
        page_acc.set_viewport_size({"width": 390, "height": 844})

        # Switch to Loc A2 -> verify strict Location isolation
        loc_sel.select_option(str(cfg["loc_a2"]))
        wait_for_finance_refresh(page_acc, cfg["loc_a2"])
        with page_acc.expect_response(
            lambda r: "/staff/portal/finance/reconciliation" in r.url, timeout=25000
        ) as rec_a2_info:
            page_acc.locator('[data-role="finance-recon-today"]').click()
        assert rec_a2_info.value.status == 200
        page_acc.locator('[data-role="finance-recon-summary"]').wait_for(state="visible", timeout=15000)
        txt_a2 = page_acc.locator('[data-role="finance-reconciliation"]').inner_text()
        assert "800000.00" in txt_a2 and cfg["pay_a2_pos"] in txt_a2
        assert cfg["pay_a1_cash"] not in txt_a2, "Loc A1 payment leaked after switching to Loc A2"

        # Cross-Clinic denial check against Clinic B (matches CustomRoleStaffPortalFinanceModuleTest: 400/403 with zero foreign data)
        foreign_fin = page_acc.evaluate(
            """async ([clinicB, locB1]) => {
                const cfgEl = document.getElementById('cpms-finance-board-config');
                const c = JSON.parse(cfgEl.textContent);
                const root = String(c.rest_root || '').replace(/\\/+$/, '');
                const u = new URL(root, window.location.origin);
                let url;
                if (u.searchParams.has('rest_route')) {
                    u.searchParams.set('rest_route', u.searchParams.get('rest_route').replace(/\\/+$/, '') + '/staff/portal/finance/reconciliation');
                    url = u.toString();
                } else {
                    url = root + '/staff/portal/finance/reconciliation';
                }
                const r = await fetch(url, {
                    credentials: 'same-origin',
                    headers: {
                        'X-WP-Nonce': c.nonce,
                        'X-CPMS-Clinic-Id': String(clinicB),
                        'X-CPMS-Location-Id': String(locB1)
                    }
                });
                const txt = await r.text();
                return { status: r.status, body: txt };
            }""",
            [cfg["clinic_b"], cfg["loc_b1"]],
        )
        foreign_fin_status = int(foreign_fin["status"])
        assert foreign_fin_status in (400, 403), f"expected 400/403 refusal on foreign Clinic B reconciliation, got {foreign_fin_status}"
        assert cfg["pay_b1_pos"] not in foreign_fin["body"] and "990000" not in foreign_fin["body"], "foreign Clinic B finance data leaked"
        ok(
            "day2-D-subscriber-accountant",
            "Subscriber Accountant: scoped reconciliation + Location switch + cross-Clinic refusal + zero PHI + 390/320 layout",
            f"acc_sub=1 loc_a1={cfg['loc_a1']} loc_a2={cfg['loc_a2']} foreign_clinic={foreign_fin_status} phi_leak=0",
        )
    finally:
        ctx_acc.close()


def main():
    if SELFTEST:
        return run_selftest()

    from playwright.sync_api import sync_playwright

    cfg = {
        "public_url": env_req("DAY2_PUBLIC_URL"),
        "reception_url": env_req("DAY2_RECEPTION_URL"),
        "doctor_url": env_req("DAY2_DOCTOR_URL"),
        "finance_url": env_req("DAY2_FINANCE_URL"),
        "clinic_a": int(env_req("DAY2_CLINIC_A")),
        "clinic_b": int(env_req("DAY2_CLINIC_B")),
        "loc_a1": int(env_req("DAY2_LOC_A1")),
        "loc_a2": int(env_req("DAY2_LOC_A2")),
        "loc_b1": int(env_req("DAY2_LOC_B1")),
        "clinician_1": int(env_req("DAY2_CLINICIAN_1")),
        "clinician_2": int(env_req("DAY2_CLINICIAN_2")),
        "clinician_deny": int(env_req("DAY2_CLINICIAN_DENY")),
        "pub_slot_doc1_loc1": int(env_req("DAY2_PUB_SLOT_DOC1_LOC1")),
        "pub_slot_doc1_loc2": int(env_req("DAY2_PUB_SLOT_DOC1_LOC2")),
        "pub_slot_doc2_loc1": int(env_req("DAY2_PUB_SLOT_DOC2_LOC1")),
        "pass": env_req("DAY2_PASS"),
        "pt_login": env_req("DAY2_PT_LOGIN"),
        "pt_user_id": int(env_req("DAY2_PT_USER_ID")),
        "resched_appt": int(env_req("DAY2_RESCHED_APPT")),
        "resched_collide_slot": int(env_req("DAY2_RESCHED_COLLIDE_SLOT")),
        "resched_clean_slot": int(env_req("DAY2_RESCHED_CLEAN_SLOT")),
        "resched_beyond_slot": int(env_req("DAY2_RESCHED_BEYOND_SLOT")),
        "sec_login": env_req("DAY2_SEC_LOGIN"),
        "sec_user_id": int(env_req("DAY2_SEC_USER_ID")),
        "sec_reanchor_ids": env_req("DAY2_SEC_REANCHOR_IDS"),
        "sec_appt_doc1": int(env_req("DAY2_SEC_APPT_DOC1")),
        "sec_appt_doc2": int(env_req("DAY2_SEC_APPT_DOC2")),
        "foreign_appt": int(env_req("DAY2_FOREIGN_APPT")),
        "doc1_login": env_req("DAY2_DOC1_LOGIN"),
        "doc1_user_id": int(env_req("DAY2_DOC1_USER_ID")),
        "active_visit_doc1": int(env_req("DAY2_ACTIVE_VISIT_DOC1")),
        "doc_deny_login": env_req("DAY2_DOC_DENY_LOGIN"),
        "doc_deny_user_id": int(env_req("DAY2_DOC_DENY_USER_ID")),
        "active_visit_deny": int(env_req("DAY2_ACTIVE_VISIT_DENY")),
        "acc_login": env_req("DAY2_ACC_LOGIN"),
        "acc_user_id": int(env_req("DAY2_ACC_USER_ID")),
        "pay_a1_cash": env_req("DAY2_PAY_A1_CASH"),
        "pay_a1_pos": env_req("DAY2_PAY_A1_POS"),
        "pay_a1_void": env_req("DAY2_PAY_A1_VOID"),
        "pay_a2_pos": env_req("DAY2_PAY_A2_POS"),
        "pay_b1_pos": env_req("DAY2_PAY_B1_POS"),
    }

    # Evidence only (no assertion): the reception rows must be ALREADY DUE yet
    # still inside `queue.no_show_grace_minutes`, so the real visits.no_show
    # background job cannot sweep them before Journey B. The fixture fails closed
    # when the Location-local clock cannot host that window.
    info(
        "reception_rows=already_due_inside_grace "
        f"grace_min={os.environ.get('DAY2_RECEPTION_GRACE_MINUTES', 'n/a')} "
        f"doc1_slot={os.environ.get('DAY2_SEC_SLOT_DOC1', 'n/a')} "
        f"doc2_slot={os.environ.get('DAY2_SEC_SLOT_DOC2', 'n/a')} "
        f"foreign_slot={os.environ.get('DAY2_SEC_SLOT_FOREIGN', 'n/a')}"
    )

    os.makedirs(OUT, exist_ok=True)
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        info(f"browser_engine=chromium version={browser.version}")
        for key, fn in (
            ("journey-A1-public", lambda: run_journey_a_public_booking(browser, cfg)),
            ("journey-A2-reschedule", lambda: run_journey_a_patient_reschedule(browser, cfg)),
            ("journey-B-reception", lambda: run_journey_b_receptionist(browser, cfg)),
            ("journey-C-doctors", lambda: run_journey_c_doctors(browser, cfg)),
            ("journey-D-accountant", lambda: run_journey_d_accountant(browser, cfg)),
        ):
            try:
                fn()
            except Exception as exc:
                fail(key, f"Day-2 browser journey failed ({key})", exc)
        browser.close()

    with open("pilot-day2-workflows-results.json", "w", encoding="utf-8") as fh:
        json.dump({"results": results, "failures": failures}, fh, ensure_ascii=False, indent=2)

    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
