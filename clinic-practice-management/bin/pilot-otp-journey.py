#!/usr/bin/env python3
"""pilot-otp-journey.py — Phase 8 Slice 2: سفرِ کاملِ بیمار روی مرورگرِ واقعی.

هارنسِ «فقطِ گیت» (در release zip سفید‌لیست نیست — bin/build-release.sh فقط
bin/cpms را می‌سازد). روی محیطِ واقعیِ WP واقعی + Chromium اجرا می‌شود و
هیچ endpoint/flag/bypass تولیدی نمی‌سازد.

سفر (برای هر Viewport: موبایل 390×844 + لپ‌تاپ 1366×768):
  آنونیم (بدون cookie/nonce/authority) → انتخاب پزشک (A1 واقعی) → انتخاب نوبت
  (A4 واقعی) → «ادامه» → موبایل → A2 درخواست OTP (با انتخابِ واقعی — Clinic از
  خودِ انتخابِ persisted مشتق می‌شود، نه ورودیِ کلاینت) → کد از مسیرِ
  «فقطِ-تست» (خواندنِ pepper از option و patch کردنِ code_hash در DB تست —
  دقیقاً همان الگوی Integration IssueKnownCode؛ verification واقعیِ سرویس
  دست‌نخورده باقی می‌ماند) → A3 واقعی (session_issued) → reload واقعی →
  cookie/session سالم، wp_rest nonce فقط حالا در config → B1 Hold واقعی
  (خودکارِ post-reload) → gateِ نام/نام‌خانوادگی (B2 بدون نام →
  CLINIC_VALIDATION_FAILED و hold فعال باقی می‌ماند) → B2 با نام‌ها +
  Idempotency-Key → Appointment + Patient سطح-Clinic + hold converted +
  reference_code در رسید.

قراردادِ شواهد (لاگ/کامنت PR): فقط boolean/شناسه/تعداد-transition —
  کد OTP، موبایل، نام بیمار(ها)، cookie، nonce و secret هرگز print نمی‌شوند.

ورودی: JOURNEY_PAGE="url|clinic_id|clinician_id|location_id|time_mobile|time_desktop"
محیط: BASE (پیش‌فرض http://localhost:8080)، WP_DIR (پیش‌فرض /home/runner/www)،
DB_MAIN (پیش‌فرض cpms_main)، DB_PREFIX (پیش‌فرض cpmswp_)، VIEWPORTS=...
exit≠0 در شکست؛ نتایج در pilot-otp-journey-results.json + صفحه‌بندیِ PASS/FAIL.
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

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
WP_DIR = os.environ.get("WP_DIR", "/home/runner/www")
DB_MAIN = os.environ.get("DB_MAIN", "cpms_main")
DB_PREFIX = os.environ.get("DB_PREFIX", "cpmswp_")
OUT = "pilot-screenshots"

# دادهٔ کاملاً Synthetic — هرگز در لاگ print نمی‌شود (قرارداد شواهد).
KNOWN_CODE = "424242"
FIRST_NAME = "نگار"
LAST_NAME = "مرادی"

# رشته‌هایی که هرگز نباید در سطح/بدنهٔ قابل‌مشاهده نشت کنند.
SURFACE_FORBIDDEN = ["mrn", "0912000", "national_id", "patient_id", "wp-login.php"]
BODYTEXT_FORBIDDEN = ['{"data"', "CLINIC_"]

_raw = os.environ.get("JOURNEY_PAGE", "").strip()
if not _raw:
    raise SystemExit("JOURNEY_PAGE is required: url|clinic|clinician|location|timeMobile|timeDesktop")
_parts = [p.strip() for p in _raw.split("|")]
if len(_parts) < 6 or not _parts[0]:
    raise SystemExit("JOURNEY_PAGE must carry 6 pipe-separated parts")
CFG = {
    "url": _parts[0],
    "clinic_id": int(_parts[1]),
    "clinician_id": int(_parts[2]),
    "location_id": int(_parts[3]),
    "time_mobile": _parts[4],
    "time_desktop": _parts[5],
}

RUNS = [
    {"vp": "mobile-390", "w": 390, "h": 844, "slot_time": CFG["time_mobile"], "mobile": "09129998871"},
    {"vp": "laptop-1366", "w": 1366, "h": 768, "slot_time": CFG["time_desktop"], "mobile": "09129998872"},
]

results = []
failures = []


def ok(key, title, detail=""):
    results.append({"key": key, "status": "PASS", "detail": detail})
    print(f"PASS {key} — {title}" + (f" — {detail}" if detail else ""))


def fail(key, title, err):
    failures.append(key)
    results.append({"key": key, "status": "FAIL", "detail": str(err)})
    print(f"FAIL {key} — {title} — {err}")


# ---------------------------------------------------------------------------
# ابزارها — MySQL CLI (همان الگوی Integration: test-DB خوانده/patch می‌شود)
# ---------------------------------------------------------------------------
def db(sql):
    out = subprocess.run(
        ["mysql", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-proot",
         "--batch", "--skip-column-names", "-e", sql, DB_MAIN],
        capture_output=True, text=True, timeout=30,
    )
    if out.returncode != 0:
        raise RuntimeError(f"db query failed: {out.stderr.strip()[:400]}")
    return out.stdout.strip()


def db1(sql):
    cell = db(sql).split("\n")[0].strip()
    if not cell or cell.upper() in ("NULL",):
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
        ["wp", f"--path={WP_DIR}", "--allow-root", "eval",
         'echo (string) get_option("cpms_otp_pepper","");'],
        capture_output=True, text=True, timeout=30,
    )
    if out.returncode != 0:
        raise RuntimeError(f"wp eval for pepper failed: {out.stderr.strip()[:200]}")
    return out.stdout.strip()


def wp_route(url):
    parsed = urlparse(url)
    flat = {k: v[0] for k, v in parse_qs(parsed.query, keep_blank_values=True).items()}
    route = flat.get("rest_route")
    if route is None:
        m = re.search(r"/wp-json(/.*)?$", parsed.path)
        route = m.group(1) if (m and m.group(1)) else parsed.path
    return route.rstrip("/") or "/"


def counts_preauth(run):
    mobile = run["mobile"]
    return {
        "tokens": db1(f"SELECT COUNT(*) FROM {T('cpms_otp_tokens')} WHERE mobile='{mobile}'"),
        "patients": db1(f"SELECT COUNT(*) FROM {T('cpms_patients')} WHERE mobile='{mobile}'"),
    }


def slot_state(slot_id):
    rows = dbrows(
        f"SELECT capacity, booked_count, held_count FROM {T('cpms_schedule_slots')} WHERE id={slot_id}"
    )
    return tuple(int(v) for v in rows[0]) if rows else None


def holds_state(mobile, slot_id):
    rows = dbrows(
        f"SELECT id, clinic_id, status, holder_wp_user_id, expires_at FROM {T('cpms_slot_holds')} "
        f"WHERE slot_id={slot_id} AND holder_mobile='{mobile}' ORDER BY id DESC"
    )
    return rows


def run_journey(browser, run):
    key0 = f"otp-{run['vp']}"
    mobile = run["mobile"]
    email = f"{mobile}@otp.cpms.local"
    screenshot = lambda suffix: f"{OUT}/{key0}-{suffix}.png"

    ctx = browser.new_context(viewport={"width": run["w"], "height": run["h"]})
    page = ctx.new_page()
    page.set_default_timeout(25000)

    rest_calls = []  # (method, route, status) — same-origin فقط
    console_errors, page_errors, net_failed = [], [], {}

    def on_response(resp):
        if not resp.url.startswith(BASE) or "/clinic/v1" not in resp.url:
            return
        rest_calls.append((resp.request.method, wp_route(resp.url), resp.status))

    def on_console(msg):
        if msg.type == "error":
            console_errors.append(msg.text[:160])

    def on_pageerror(err):
        page_errors.append(str(err)[:160])

    def on_requestfailed(req):
        url = req.url
        if not url.startswith(BASE) or "favicon" in url:
            return
        if url.endswith(".map") or ".map?" in url:
            return
        net_failed.setdefault(req.failure or "failed", []).append(url)

    page.on("response", on_response)
    page.on("console", on_console)
    page.on("pageerror", on_pageerror)
    page.on("requestfailed", on_requestfailed)

    stage = "boot"
    try:
        os.makedirs(OUT, exist_ok=True)

        # --- ۰) آنونیم: هیچ authority/cookie/hold ---
        # tapِ fetch (فقطِ تست): بدنهٔ A3 را پیش از reload در sessionStorage
        # می‌نشاند تا پس از navigation هم برای assertion قابل خواندن بماند.
        page.add_init_script(
            "(() => {"
            "  const tapKey = 'cpms-journey:tap:otp-verify';"
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
            "    } catch (e) { /* harness-only tap */ }"
            "    return p;"
            "  };"
            "})();"
        )
        page.goto(CFG["url"], wait_until="networkidle")
        # diagnosis (Transient): اگر surface رندر نشده باشد، حکمِ واقعیت در لاگ.
        try:
            _href = page.evaluate("document.location.href")
            _n = page.locator(".cpms-public-booking").count()
            print(f"DIAG {key0} url={_href} surface={_n}")
        except Exception as _e:
            print(f"DIAG {key0} eval-failed: {_e}")

        cfg0 = page.evaluate(
            "JSON.parse((document.querySelector('script.cpms-public-booking__config') || {textContent: '{}'}).textContent)"
        )
        if page.locator(".cpms-public-booking").count() < 1:
            raise RuntimeError("surface root not rendered")
        if (page.locator(".cpms-public-booking").first.get_attribute("data-clinic-id") or "") != str(CFG["clinic_id"]):
            raise RuntimeError("surface bound to unexpected clinic")
        stage = "anon-config"
        assert cfg0.get("otp_request_path") == "/otp/request", "anonymous config must publish otp_request_path"
        assert cfg0.get("otp_verify_path") == "/otp/verify", "anonymous config must publish otp_verify_path"
        for k in ("nonce", "hold_path", "confirm_path"):
            assert k not in cfg0, f"anonymous config leaked {k} (authority before auth)"
        cookies0 = ctx.cookies()
        assert not any(c["name"].startswith("wordpress") for c in cookies0), "anonymous visit received WP cookies"
        pre = counts_preauth(run)
        assert pre["tokens"] == 0 and pre["patients"] == 0, f"unexpected pre-auth rows: {pre}"
        ss0 = slot_state(db1(
            f"SELECT id FROM {T('cpms_schedule_slots')} WHERE clinic_id={CFG['clinic_id']} AND slot_time='{run['slot_time']}'"
        ))
        assert ss0 is not None, "journey slot row missing from fixture"
        cap0, bk0, hd0 = ss0
        assert (bk0, hd0) == (0, 0), f"slot capacity dirty before auth: booked={bk0} held={hd0}"
        assert holds_state(mobile, 0) == [], "anonymous hold pre-exists"
        ok(f"{key0}-01-anon", "آنونیم: بدون nonce/hold/confirm، بدون cookie، ظرفیت/Hold صفر",
           f"clinic={CFG['clinic_id']} tokens=0 patients=0 slot_cap={cap0} booked=0 held=0 cookies_wp=0")

        # --- ۱) A1/A4 واقعی روی UI ---
        stage = "a1-a4"
        with page.expect_response(lambda r: "/clinic/v1/availability" in r.url, timeout=25000) as a1_info:
            page.locator(".cpms-public-booking__clinician").first.click()
        a1 = a1_info.value
        assert a1.status == 200, f"A1 availability returned HTTP {a1.status}"
        page.wait_for_function(
            "document.querySelector('.cpms-public-booking__panel').getAttribute('data-state') !== 'loading'")
        slot_sel = f".cpms-public-booking__slot[data-slot-time^=\"{run['slot_time'][:5]}\"]"
        page.locator(slot_sel).first.wait_for(state="visible")
        with page.expect_response(lambda r: "/clinic/v1/booking/quote" in r.url, timeout=25000) as a4_info:
            page.locator(slot_sel).first.click()
        a4 = a4_info.value
        assert a4.status == 200, f"A4 quote returned HTTP {a4.status}"
        page.wait_for_function(
            "document.querySelector('.cpms-public-booking__panel').getAttribute('data-state') !== 'loading'")
        page.locator('[data-role="continue-auth"]').wait_for(state="visible")
        slot_id = db1(
            f"SELECT id FROM {T('cpms_schedule_slots')} WHERE clinic_id={CFG['clinic_id']} AND slot_time='{run['slot_time']}'"
        )
        ok(f"{key0}-02-browse", "A1/A4 واقعی و قابل‌رزرو", f"A1=200 A4=200 state=bookable slot_id={slot_id}")

        # --- ۲) A2 درخواست OTP (با انتخابِ واقعی) ---
        stage = "a2"
        page.locator('[data-role="continue-auth"]').click()
        page.locator('[data-role="otp-mobile"]').wait_for(state="visible")
        page.locator('[data-role="otp-mobile"]').fill(mobile)
        with page.expect_response(lambda r: "/clinic/v1/otp/request" in r.url, timeout=25000) as a2_info:
            page.locator('[data-auth-action="otp-request"]').click()
        a2 = a2_info.value
        assert a2.status == 200, f"A2 otp/request returned HTTP {a2.status} (scope/selection defect)"
        page.locator('[data-role="otp-code"]').wait_for(state="visible")
        trow = dbrows(
            f"SELECT id, clinic_id, consumed_at FROM {T('cpms_otp_tokens')} WHERE mobile='{mobile}' ORDER BY id DESC"
        )
        assert len(trow) == 1, f"expected exactly 1 otp token, got {len(trow)}"
        token_id, token_clinic = int(trow[0][0]), int(trow[0][1])
        assert trow[0][2] in ("NULL", ""), "fresh A2 challenge must be unconsumed"
        assert token_clinic == CFG["clinic_id"], (
            f"challenge clinic={token_clinic} != persisted-selection clinic={CFG['clinic_id']}")
        assert token_clinic > 1, "challenge clinic must be nontrivial (never clinic_id=1 as authority)"
        assert db1(f"SELECT COUNT(*) FROM {T('cpms_slot_holds')} WHERE slot_id={slot_id} AND status='active'") == 0, \
            "a hold exists before authentication"
        cap1, bk1, hd1 = slot_state(slot_id)
        assert hd1 == 0, f"held_count moved before auth: {hd1}"
        ok(f"{key0}-03-a2", "A2: چالش واقعی در Clinicِ انتخاب؛ Hold/ظرفیت دست‌نخورده پیش از auth",
           f"A2=200 token_clinic={token_clinic} holds_active=0 held_count=0")

        # --- ۳) وصلهٔ کدِ تست (DB-only؛ verification واقعی حفظ می‌شود) ---
        stage = "otp-patch"
        pepper = wp_pepper()
        assert pepper, "otp pepper option missing after real A2"
        digest = hmac.new(pepper.encode(), KNOWN_CODE.encode(), hashlib.sha256).hexdigest()
        db(f"UPDATE {T('cpms_otp_tokens')} SET code_hash='{digest}' WHERE id={token_id}")
        ok(f"{key0}-04-otpfix", "کدِ تست از مسیرِ DB/patch (IssueKnownCode) — بدون endpoint/bypass تولیدی",
           f"token_id={token_id}")

        # --- ۴) A3 واقعی + reload + session/nonce ---
        stage = "a3"
        page.locator('[data-role="otp-code"]').fill(KNOWN_CODE)
        with page.expect_response(lambda r: "/clinic/v1/otp/verify" in r.url, timeout=25000) as a3_info:
            with page.expect_navigation(timeout=25000):
                page.locator('[data-auth-action="otp-verify"]').click()
        a3 = a3_info.value
        assert a3.status == 200, f"A3 otp/verify returned HTTP {a3.status}"
        # بدنه باید از tap خوانده شود — بدنهٔ خامِ response پس از navigation
        # در دسترس نیست (Protocol error در run ف8637d1 — class D).
        raw_tap = page.evaluate(
            "(() => { const v = window.sessionStorage.getItem('cpms-journey:tap:otp-verify');"
            " window.sessionStorage.removeItem('cpms-journey:tap:otp-verify'); return v; })()"
        )
        a3_payload = json.loads(raw_tap) if raw_tap else {}
        a3_data = a3_payload.get("data", {})
        assert a3_data.get("session_issued") is True, f"A3 did not issue session: {list(a3_data.keys())}"
        assert isinstance(a3_data.get("user_id"), int) and a3_data["user_id"] > 0, "A3 user_id missing"
        assert a3_data.get("is_new_user") is True, "expected is_new_user=true for first-time mobile"
        page.wait_for_load_state("networkidle")
        uid = db1(f"SELECT ID FROM {T('users')} WHERE user_email='{email}'")
        assert uid > 0 and uid == a3_data["user_id"], f"wp user mismatch: db={uid} a3={a3_data['user_id']}"
        cookies1 = ctx.cookies()
        assert any(c["name"].startswith("wordpress_logged_in") for c in cookies1), "auth cookie missing after reload"
        cfg1 = page.evaluate(
            "JSON.parse((document.querySelector('script.cpms-public-booking__config') || {textContent: '{}'}).textContent)"
        )
        assert isinstance(cfg1.get("nonce"), str) and len(cfg1["nonce"]) > 5, "wp_rest nonce missing after auth"
        assert cfg1.get("hold_path") == "/booking/hold" and cfg1.get("confirm_path") == "/booking/confirm", \
            f"patient config paths wrong: {[k for k in cfg1 if k.endswith('_path')]}"
        assert "otp_request_path" not in cfg1 and "otp_verify_path" not in cfg1, \
            "patient config still publishes anonymous-only otp paths"
        role_hit = db1(
            f"SELECT COUNT(*) FROM {T('usermeta')} WHERE user_id={uid} AND meta_key='{DB_PREFIX}capabilities' "
            "AND meta_value LIKE '%cpms_patient%'"
        )
        assert role_hit == 1, "patient role not recognized on wp user"
        ok(f"{key0}-05-a3", "A3: session واقعی + reload + nonce فقط حالا منتشر شد",
           f"A3=200 user_id={uid} role=cpms_patient cookie_logged_in=1 nonce=1")

        # --- ۵) B1 خودکار (post-reload) → Hold واقعی ---
        stage = "b1"
        deadline = time.time() + 30
        active = []
        while time.time() < deadline:
            active = [r for r in holds_state(mobile, slot_id) if r[2] == "active" and r[3] != "NULL" and int(r[3]) > 0]
            if active:
                break
            time.sleep(0.75)
        assert active, f"B1 hold never materialized (listened-calls={net_failed and 'had-net-fail' or 0})"
        hold_id, hold_clinic, hold_status, hold_uid, hold_exp = active[0]
        assert int(hold_uid) == uid, f"hold holder_wp_user_id={hold_uid} != auth user {uid}"
        assert int(hold_clinic) == CFG["clinic_id"], f"hold clinic={hold_clinic} != selection clinic"
        assert int(hold_clinic) > 1, "hold clinic must be nontrivial"
        # expires_at به‌صورت DATETIME ذخیره شده؛ TTL استاندارد = 600 ثانیه
        now_ms = db1("SELECT UNIX_TIMESTAMP(NOW(3)) * 1000")
        # جلوگیری از محدودِ محافظه‌کار: NOW به‌علتِ فاصلهٔ زمانیِ poll چند ثانیه دیرتر است
        exp_ms = db1(f"SELECT UNIX_TIMESTAMP(expires_at) * 1000 FROM {T('cpms_slot_holds')} WHERE id={hold_id}")
        ttl_ms = exp_ms - now_ms
        assert 400_000 < ttl_ms < 700_000, f"hold TTL off-standard: {ttl_ms}ms"
        cap2, bk2, hd2 = slot_state(slot_id)
        assert (bk2, hd2) == (0, 1), f"capacity wrong after B1: booked={bk2} held={hd2}"
        page.locator('[data-role="hold-countdown"]').wait_for(state="visible")
        page.locator('[data-role="confirm-btn"]').wait_for(state="visible")
        ok(f"{key0}-06-b1", "B1: Hold واقعی فقط پس از auth؛ held_count +1 با TTL استاندارد",
           f"hold_id={hold_id} clinic={int(hold_clinic)} holder=uid held_count=1 ttl≈600s countdown=1")

        # --- ۶) B2 بدون نام‌ها → gate (retryable) ---
        stage = "b2-gate"
        with page.expect_response(lambda r: "/clinic/v1/booking/confirm" in r.url, timeout=25000) as b2g_info:
            page.locator('[data-role="confirm-btn"]').click()
        b2g = b2g_info.value
        assert b2g.status == 400, f"name gate should 400, got {b2g.status}"
        gate_payload = b2g.json()
        assert gate_payload.get("code") == "CLINIC_VALIDATION_FAILED", f"gate code wrong: {gate_payload.get('code')}"
        page.locator('[data-role="names-form"]').wait_for(state="visible")
        page.locator('[data-role="patient-first-name"]').wait_for(state="visible")
        page.locator('[data-role="patient-last-name"]').wait_for(state="visible")
        assert page.locator('[data-role="names-form"]').count() == 1, "names-form missing"
        assert db1(f"SELECT COUNT(*) FROM {T('cpms_appointments')} WHERE slot_id={slot_id}") == 0, \
            "appointment created despite name-validation failure"
        assert db1(f"SELECT COUNT(*) FROM {T('cpms_patients')} WHERE mobile='{mobile}'") == 0, \
            "patient created despite name-validation failure"
        gate_hold = holds_state(mobile, slot_id)[0]
        assert gate_hold[2] == "active", f"hold must stay active+retryable after gate: {gate_hold[2]}"
        ok(f"{key0}-07-b2gate", "B2 gate: بدون نام → CLINIC_VALIDATION_FAILED؛ Hold فعال/بدون سطر",
           "B2=400 code=CLINIC_VALIDATION_FAILED hold=active appt=0 patient=0")

        # --- ۷) B2 با نام‌ها + Idempotency-Key → تأیید واقعی ---
        stage = "b2"
        page.locator('[data-role="patient-first-name"]').fill(FIRST_NAME)
        page.locator('[data-role="patient-last-name"]').fill(LAST_NAME)
        with page.expect_response(lambda r: "/clinic/v1/booking/confirm" in r.url, timeout=25000) as b2_info:
            page.locator('[data-role="confirm-btn"]').click()
        b2 = b2_info.value
        assert b2.status == 200, f"B2 confirm returned HTTP {b2.status}"
        b2_payload = b2.json()
        b2_data = b2_payload.get("data") if isinstance(b2_payload.get("data"), dict) else b2_payload
        assert b2_data.get("reference_code"), f"B2 returned no reference_code: {list(b2_data.keys())}"
        assert re.fullmatch(r"AP-\d{8}-\d{2}", b2_data["reference_code"]), \
            "reference_code format violated"
        page.locator('[data-role="receipt"]').wait_for(state="visible")
        ref_text = (page.locator('[data-role="reference-code"]').first.text_content() or "").strip()
        assert re.fullmatch(r"AP-\d{8}-\d{2}", ref_text), "reference_code not visible in receipt"
        jalali_text = (page.locator('[data-role="slot-jalali"]').first.text_content() or "").strip()
        assert jalali_text, "Jalali slot date missing from receipt"
        time_text = (page.locator('[data-role="slot-time"]').first.text_content() or "").strip()
        assert time_text, "slot time missing from receipt"
        assert page.locator('[data-role="booking-continue"]').is_visible() or \
            page.locator('[data-role="receipt"]').is_visible(), "booking continuation area missing"
        prow = dbrows(
            f"SELECT id, clinic_id, first_name, last_name, mobile, status FROM {T('cpms_patients')} "
            f"WHERE mobile='{mobile}'"
        )
        assert len(prow) == 1, f"expected exactly 1 new patient, got {len(prow)}"
        pid = int(prow[0][0])
        assert int(prow[0][1]) == CFG["clinic_id"], f"patient clinic={prow[0][1]} != selection clinic"
        assert int(prow[0][1]) > 1, "patient clinic must be nontrivial"
        arow = dbrows(
            f"SELECT id, clinic_id, status, reference_code FROM {T('cpms_appointments')} "
            f"WHERE patient_id={pid} AND slot_id={slot_id}"
        )
        assert len(arow) == 1, f"expected exactly 1 appointment, got {len(arow)}"
        assert int(arow[0][1]) == CFG["clinic_id"] and arow[0][2] == "confirmed", \
            f"appointment wrong: clinic={arow[0][1]} status={arow[0][2]}"
        assert arow[0][3].startswith("AP-"), "appointment reference_code mismatch with contract"
        link = db1(
            f"SELECT COUNT(*) FROM {T('cpms_patient_user_links')} WHERE wp_user_id={uid} AND patient_id={pid}"
        )
        assert link == 1, "patient-user link missing"
        cap3, bk3, hd3 = slot_state(slot_id)
        assert (bk3, hd3) == (1, 0), f"capacity wrong after B2: booked={bk3} held={hd3}"
        final_hold = holds_state(mobile, slot_id)[0]
        assert final_hold[2] == "converted", f"hold not converted: {final_hold[2]}"
        ok(f"{key0}-08-b2", "B2: Patientّ‌کد+Appointment واقعی در Clinicِ انتخاب؛ hold converted",
           f"B2=200 appt_id={arow[0][0]} patient_clinic={CFG['clinic_id']} booked=1 held=0 ref=ok links=1")

        # --- ۸) بهداشت: بدون نشتِ خام/خطای Console/overflow ---
        stage = "hygiene"
        body = (page.locator("body").text_content() or "")
        for tok in BODYTEXT_FORBIDDEN:
            assert tok not in body, f"raw {tok!r} leaked into visible body"
        surface = page.evaluate(
            "(function () { var n = document.querySelector('.cpms-public-booking'); return n ? n.outerHTML : ''; })()"
        )
        for tok in SURFACE_FORBIDDEN:
            assert tok not in surface, f"forbidden token {tok!r} in surface subtree"
        sw = page.evaluate("document.documentElement.scrollWidth")
        iw = page.evaluate("window.innerWidth")
        assert sw <= iw + 1, f"horizontal overflow: scrollWidth={sw} > innerWidth={iw}"
        # gateِ نام (B2 بدون نام → 400) عمداً شلیک می‌شود و Chromium آن را به
        # شکل «Failed to load resource... status of 400» در کنسول می‌نویسد.
        # هر خطای کنسولِ 400 باید دقیقاً با یک پاسخِ RESTِ مجازِ 400 جفت شود؛
        # باقی خطاهای کنسول همچنان شکست‌محور است.
        gate_400s = sum(1 for (m, r, s) in rest_calls
                        if r == "/clinic/v1/booking/confirm" and s == 400)
        console_hard, console_400_seen = [], 0
        for err in console_errors:
            if "status of 400" in err and console_400_seen < gate_400s:
                console_400_seen += 1
                continue
            console_hard.append(err)
        assert not console_hard, f"console errors: {console_hard[:3]}"
        assert console_400_seen <= gate_400s
        assert not page_errors, f"page errors: {page_errors[:3]}"
        assert not net_failed, f"failed same-origin requests: {list(net_failed.values())[:3]}"
        unexpected = [(m, r, s) for (m, r, s) in rest_calls
                      if not 200 <= s < 300
                      and not (r == "/clinic/v1/booking/confirm" and s == 400)]
        assert not unexpected, f"unexpected failed REST calls: {unexpected[:4]}"
        expected_flow = [(m, r) for (m, r, s) in rest_calls if r in (
            "/clinic/v1/otp/request", "/clinic/v1/otp/verify", "/clinic/v1/booking/hold",
            "/clinic/v1/booking/confirm")] 
        for route, want in (("/clinic/v1/otp/request", 1), ("/clinic/v1/otp/verify", 1),
                            ("/clinic/v1/booking/hold", 1), ("/clinic/v1/booking/confirm", 2)):
            got = [c for c in rest_calls if c[1] == route and c[0] == "POST"]
            assert len(got) == want, f"{route} count={len(got)} (want {want})"
        page.screenshot(path=screenshot("receipt"), full_page=False)
        ok(f"{key0}-09-hygiene", "بهداشت: بدون نشت/Console clean/بدون overflow/REST موردانتظار",
           f"sw={sw} iw={iw} console_err=0 expected400_console={console_400_seen} net_bad=0 flow={len(expected_flow)}")

    except Exception as e:  # noqa: BLE001
        try:
            page.screenshot(path=screenshot("FAIL"), full_page=False)
        except Exception:
            pass
        fail(f"{key0}-{stage}", f"سفر {run['vp']}", e)
        raise
    finally:
        ctx.close()


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for run in RUNS:
            try:
                run_journey(browser, run)
            except Exception:
                # خطاها قبلاً در results ثبت شده‌اند؛ viewport بعدی هم اجرا شود تا شواهد کامل بماند.
                continue
        browser.close()

    summary = {"ok": not failures, "runs": len(RUNS), "failed": failures}
    with open("pilot-otp-journey-results.json", "w", encoding="utf-8") as f:
        json.dump({"summary": summary, "results": results}, f, ensure_ascii=False, indent=1)
    print(json.dumps(summary, ensure_ascii=False))
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
