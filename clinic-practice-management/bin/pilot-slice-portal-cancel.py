#!/usr/bin/env python3
"""pilot-slice-portal-cancel.py — Phase 9 Slice 1 + Slice 2 + Slice 3: patient self-cancel and internal
notifications on the independent frontend Patient Portal («نوبت‌های من», CPMS shell —
WordPress Page + template_include + standalone template; NOT wp-admin) on a real Chromium,
through the EXISTING routes POST clinic/v1/appointments/{id}/cancel (B4) and
POST clinic/v1/notifications/read (R2b, body exactly {"all":true}).

Fixture (created by the workflow step before this script):
  PORTAL_CANCEL="login|pass|clinic_id|patient_id|ok_appt_390|ok_appt_1366|fail_appt|pending_appt|history_appt|notif_unread_ids|notif_read_id"

  - ok_appt_*   : own CONFIRMED appointments far in the future (one per viewport) → cancel succeeds
  - fail_appt   : own CONFIRMED appointment starting in ~2h → B4 rejects (409 CLINIC_POLICY_VIOLATION)
  - pending_appt: own PENDING upcoming appointment → no cancel control may render
  - history_appt: own already-cancelled appointment → history table, no control
  - notif_unread_ids: comma-separated ids of the patient's own UNREAD internal notifications (Slice 2)
  - notif_read_id   : id of the patient's own already-READ internal notification (Slice 2)

Journey (per viewport 390x844 and 1366x768):
  1. Login as the pure patient → landed on the portal; runtime config has exactly
     rest_root/cancel_path/notifications_read_path/nonce (no clinic_id/patient_id anywhere), controls exist
     ONLY for confirmed upcoming rows.
  1b. Slice 2 — notifications: exactly one accessible section; unread badge whose data-unread-count equals the
     server-known unread count (≠ row count); one row per inbox notification with data-read mirroring the DB and a
     server-rendered title; one real, enabled mark-all-read <button>; hidden role=alert region.
  1c. Slice 2 failure path: the same click without the X-WP-Nonce header (request-level fault injection) → 403
     CLINIC_INVALID_NONCE → Persian role=alert, control busy in flight then re-enabled and focused; DB unchanged.
  1d. Slice 2 success path: click → same-origin POST with X-WP-Nonce and body exactly {"all":true} → 200 → page
     reloads → badge and control gone, every row still visible and data-read="1"; DB unread = 0.
  2. Keyboard: Enter on the control opens the accessible confirm dialog; Escape closes it, no request sent.
  3. Failure path: confirm on fail_appt → same-origin POST with X-WP-Nonce and body {} → 409 →
     Persian role=alert error visible, control was disabled/aria-busy in flight and is re-enabled; DB unchanged.
  4. Success path: confirm on ok_appt → 200 → page reloads → row gone from upcoming, listed in history as
     cancelled; DB status cancelled_by_patient, slot capacity released, audit row written.
  5. UX: no horizontal overflow, no page errors, no failed same-origin requests, only the four expected REST calls.

Evidence: booleans, counts, non-sensitive IDs only. No names/mobile/nonce/cookies/secrets are printed.
"""
import json
import os
import re
import subprocess
import sys
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
DB_MAIN = os.environ.get("DB_MAIN", "cpms_main")
DB_PREFIX = os.environ.get("DB_PREFIX", "cpmswp_")
OUT = "pilot-screenshots"

_raw = os.environ.get("PORTAL_CANCEL", "").strip()
if not _raw:
    raise SystemExit("PORTAL_CANCEL is required: login|pass|clinic|patient|ok390|ok1366|fail|pending|history|notif_unread_ids|notif_read_id")
_parts = [p.strip() for p in _raw.split("|")]
if len(_parts) < 11 or not _parts[0]:
    raise SystemExit("PORTAL_CANCEL must carry 11 pipe-separated parts (Slice 2 adds notif_unread_ids|notif_read_id)")
_notif_unread_ids = [int(x) for x in _parts[9].split(",") if x.strip()]
if not _notif_unread_ids or int(_parts[10]) <= 0:
    raise SystemExit("PORTAL_CANCEL parts 10/11 must carry at least one unread notification id and one read notification id")
CFG = {
    "login": _parts[0],
    "password": _parts[1],
    "clinic_id": int(_parts[2]),
    "patient_id": int(_parts[3]),
    "ok_appts": {"mobile-390": int(_parts[4]), "laptop-1366": int(_parts[5])},
    "fail_appt": int(_parts[6]),
    "pending_appt": int(_parts[7]),
    "history_appt": int(_parts[8]),
    "notif_unread_ids": _notif_unread_ids,
    "notif_read_id": int(_parts[10]),
}

RUNS = [
    {"vp": "mobile-390", "w": 390, "h": 844},
    {"vp": "laptop-1366", "w": 1366, "h": 768},
]

# Phase 9 Slice 3: pure-patient home is the frontend CPMS shell (never /wp-admin/).
# Pretty: /cpms-patient-portal/ ; Plain: /?page_id=N or /?pagename=cpms-patient-portal
PORTAL_SLUG = "cpms-patient-portal"
PORTAL_PATH = f"/{PORTAL_SLUG}/"
LEGACY_ADMIN_PORTAL = "page=cpms-patient"
SHELL_ROOT = "#cpms-patient-portal-shell, .cpms-patient-portal-shell, [data-cpms-patient-portal]"
CANCEL_ROUTE_RE = re.compile(r"^/clinic/v1/appointments/(\d+)/cancel$")
BTN = 'button[data-role="cancel-appointment"]'


def is_frontend_portal_url(url: str) -> bool:
    """True when the browser is on the production frontend Patient Portal (not wp-admin)."""
    if not url:
        return False
    if "/wp-admin/" in url or LEGACY_ADMIN_PORTAL in url:
        return False
    if PORTAL_SLUG in url:
        return True
    # Plain permalink may use page_id only — shell root marker is the authority then.
    return False


def assert_on_frontend_portal(page, *, allow_navigate: bool = False) -> None:
    """Ensure the page is the independent CPMS Patient Portal shell."""
    url = page.url or ""
    if not is_frontend_portal_url(url):
        if allow_navigate:
            page.goto(f"{BASE}{PORTAL_PATH}", wait_until="networkidle")
            url = page.url or ""
        if not is_frontend_portal_url(url) and page.locator(SHELL_ROOT).count() == 0:
            raise RuntimeError(f"expected frontend Patient Portal, got {url}")
    if "/wp-admin/" in url or LEGACY_ADMIN_PORTAL in url:
        raise RuntimeError(f"Patient Portal must not remain under wp-admin (url={url})")
    # Shell root is the hard authority (works under Plain page_id URLs too).
    page.wait_for_selector(SHELL_ROOT, timeout=15000)
    html = page.content()
    if 'id="wpadminbar"' in html or 'id="adminmenu"' in html or 'id="wpfooter"' in html:
        raise RuntimeError("wp-admin chrome must be absent from the Patient Portal shell")
    if "مدیریت مطب" in html:
        raise RuntimeError("Staff/Admin navigation must not appear in the Patient Portal shell")
ERROR_BOX = '[data-role="cancel-error"]'
DIALOG = '.cpms-modal[role="dialog"]'
# Slice 2 — notifications section contract (same data-role markers the Integration suite asserts)
READ_ROUTE = "/clinic/v1/notifications/read"
NOTIF_SECTION = '[data-role="notifications-section"]'
NOTIF_ROW = '[data-role="notification-row"]'
NOTIF_BADGE = '[data-role="notifications-unread-badge"]'
MARK_ALL = 'button[data-role="notifications-mark-all-read"]'
NOTIF_ERROR = '[data-role="notifications-error"]'
PERSIAN_RE = re.compile(r"[\u0600-\u06FF]")

results = []
failures = []


def ok(key, title, detail=""):
    results.append({"key": key, "status": "PASS", "detail": detail})
    print(f"PASS {key} — {title}" + (f" — {detail}" if detail else ""))


def fail(key, title, err):
    failures.append(key)
    results.append({"key": key, "status": "FAIL", "detail": str(err)})
    print(f"FAIL {key} — {title} — {err}")


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
    raw = db(sql)
    if not raw:
        return 0
    first = raw.split("\n")[0].strip()
    if not first or first.upper() == "NULL":
        return 0
    try:
        return int(float(first))
    except ValueError:
        return 0


def dbs(sql):
    raw = db(sql)
    return raw.split("\n")[0].strip() if raw else ""


def T(name):
    return f"{DB_PREFIX}{name}"


def wp_route(url):
    parsed = urlparse(url)
    flat = {k: v[0] for k, v in parse_qs(parsed.query, keep_blank_values=True).items()}
    route = flat.get("rest_route")
    if route is None:
        m = re.search(r"/wp-json(/.*)?$", parsed.path)
        route = m.group(1) if (m and m.group(1)) else parsed.path
    return route.rstrip("/") or "/"


def appt_status(appt_id):
    return dbs(f"SELECT status FROM {T('cpms_appointments')} WHERE id={int(appt_id)}")


def appt_ref(appt_id):
    return dbs(f"SELECT reference_code FROM {T('cpms_appointments')} WHERE id={int(appt_id)}")


def slot_booked(appt_id):
    return db1(
        f"SELECT s.booked_count FROM {T('cpms_schedule_slots')} s JOIN {T('cpms_appointments')} a ON a.slot_id = s.id WHERE a.id={int(appt_id)}"
    )


def notif_where():
    """Recipient-scoped predicate of the fixture patient's inbox (mirrors G6: same Clinic, not cancelled)."""
    return f"clinic_id={CFG['clinic_id']} AND recipient_patient_id={CFG['patient_id']} AND status <> 'cancelled'"


def notif_unread_count():
    return db1(f"SELECT COUNT(*) FROM {T('cpms_notifications')} WHERE {notif_where()} AND read_at IS NULL")


def notif_rows():
    """Inbox-shaped rows for the fixture patient — (id, is_read) in the inbox order (id DESC, bounded like G6)."""
    raw = db(f"SELECT id, IF(read_at IS NULL, 0, 1) FROM {T('cpms_notifications')} WHERE {notif_where()} ORDER BY id DESC LIMIT 50")
    rows = []
    for line in raw.split("\n"):
        if line.strip():
            nid, is_read = line.split("\t")
            rows.append((int(nid), int(is_read)))
    return rows


def reset_notifications():
    """Per-viewport reset of the seeded rows (the previous viewport's mark-all must not empty this one).
    Recipient-scoped UPDATEs only: unread ids → read_at NULL, the read id → read_at set."""
    unread_csv = ",".join(str(i) for i in CFG["notif_unread_ids"])
    db(f"UPDATE {T('cpms_notifications')} SET read_at=NULL WHERE id IN ({unread_csv}) AND recipient_patient_id={CFG['patient_id']}")
    db(f"UPDATE {T('cpms_notifications')} SET read_at=UTC_TIMESTAMP() WHERE id={CFG['notif_read_id']} AND recipient_patient_id={CFG['patient_id']}")


def dom_notification_rows(page):
    """[{id, read, title, text}] in document order — what the patient actually sees."""
    return page.evaluate(
        """() => Array.from(document.querySelectorAll('[data-role="notification-row"]')).map(li => ({
            id: parseInt(li.getAttribute('data-notification-id') || '', 10),
            read: li.getAttribute('data-read'),
            title: ((li.querySelector('strong') || {}).textContent || '').trim(),
            text: (li.textContent || '').trim()
        }))"""
    )


def install_trace(page, selector):
    """MutationObserver on the control: records disabled/aria-busy/text transitions (proof of in-flight state)."""
    page.evaluate(
        """(sel) => {
            const btn = document.querySelector(sel);
            window.__cpmsTrace = [];
            if (!btn) { return; }
            const snap = () => ({ disabled: btn.disabled, busy: btn.getAttribute('aria-busy'), text: btn.textContent });
            const obs = new MutationObserver(() => window.__cpmsTrace.push(snap()));
            obs.observe(btn, { attributes: true, childList: true, characterData: true, subtree: true });
        }""",
        selector,
    )


def confirm_dialog(page):
    page.wait_for_selector(DIALOG, state="visible", timeout=10000)
    dialog = page.locator(DIALOG)
    if dialog.get_attribute("aria-modal") != "true":
        raise RuntimeError("confirm dialog must be aria-modal")
    if dialog.locator(".cpms-modal-confirm").count() != 1:
        raise RuntimeError("confirm dialog must expose exactly one confirm button")
    return dialog


def run_journey(browser, run):
    key0 = f"portal-cancel-{run['vp']}"
    screenshot = lambda suffix: f"{OUT}/{key0}-{suffix}.png"  # noqa: E731
    ok_appt = CFG["ok_appts"][run["vp"]]
    fail_appt = CFG["fail_appt"]

    ctx = browser.new_context(viewport={"width": run["w"], "height": run["h"]})
    page = ctx.new_page()
    page.set_default_timeout(25000)
    rest_calls = []      # (method, route, status)
    cancel_posts = []    # dicts: route, has_nonce, body, same_origin
    read_posts = []      # Slice 2: POST notifications/read — dicts: has_nonce, body, same_origin
    console_errors, page_errors, net_failed, aborted_by_nav = [], [], {}, []

    def on_request(req):
        if not req.url.startswith(BASE) or "/clinic/v1" not in req.url:
            return
        route = wp_route(req.url)
        if req.method == "POST" and CANCEL_ROUTE_RE.match(route):
            headers = {k.lower(): v for k, v in req.headers.items()}
            cancel_posts.append({
                "route": route,
                "has_nonce": bool(headers.get("x-wp-nonce")),
                "body": req.post_data or "",
                "same_origin": urlparse(req.url).netloc == urlparse(BASE).netloc,
            })
        elif req.method == "POST" and route == READ_ROUTE:
            headers = {k.lower(): v for k, v in req.headers.items()}
            read_posts.append({
                "has_nonce": bool(headers.get("x-wp-nonce")),
                "body": req.post_data or "",
                "same_origin": urlparse(req.url).netloc == urlparse(BASE).netloc,
            })

    def on_response(resp):
        if not resp.url.startswith(BASE) or "/clinic/v1" not in resp.url:
            return
        rest_calls.append((resp.request.method, wp_route(resp.url), resp.status))

    def on_console(msg):
        if msg.type == "error":
            txt = msg.text[:200]
            # The expected 409 surfaces as a resource error line in Chromium — not a script error.
            if "favicon" in txt or "Failed to load resource" in txt:
                return
            console_errors.append(txt)

    def on_pageerror(err):
        page_errors.append(str(err)[:200])

    def on_requestfailed(req):
        url = req.url
        if not url.startswith(BASE) or "favicon" in url or ".map" in url:
            return
        failure = req.failure or "failed"
        # The success path reloads the page; a request still in flight at that moment (e.g. WP
        # heartbeat) is aborted by the navigation itself — not a failure of the surface.
        if "ERR_ABORTED" in failure:
            aborted_by_nav.append(url)
            return
        net_failed.setdefault(failure, []).append(url)

    page.on("request", on_request)
    page.on("response", on_response)
    page.on("console", on_console)
    page.on("pageerror", on_pageerror)
    page.on("requestfailed", on_requestfailed)

    stage = "boot"
    try:
        os.makedirs(OUT, exist_ok=True)

        # ---------- preconditions (DB, no PHI printed) ----------
        stage = "precondition"
        user_id = db1(f"SELECT ID FROM {T('users')} WHERE user_login='{CFG['login']}'")
        if user_id == 0:
            raise RuntimeError("fixture user not found in DB")
        for label, appt_id, expected in (("ok", ok_appt, "confirmed"), ("fail", fail_appt, "confirmed"),
                                         ("pending", CFG["pending_appt"], "pending"), ("history", CFG["history_appt"], "cancelled_by_patient")):
            status = appt_status(appt_id)
            if status != expected:
                raise RuntimeError(f"fixture {label} appointment #{appt_id} must be {expected}, got {status!r}")
        for label, nid in [("unread", i) for i in CFG["notif_unread_ids"]] + [("read", CFG["notif_read_id"])]:
            owner = db1(f"SELECT recipient_patient_id FROM {T('cpms_notifications')} WHERE id={int(nid)}")
            if owner != CFG["patient_id"]:
                raise RuntimeError(f"fixture {label} notification #{nid} must belong to the fixture patient")
        reset_notifications()
        notif_unread_expected = notif_unread_count()
        notif_rows_expected = notif_rows()
        if notif_unread_expected < len(CFG["notif_unread_ids"]) or len(notif_rows_expected) <= notif_unread_expected:
            raise RuntimeError(
                f"fixture notifications must yield unread ≥ {len(CFG['notif_unread_ids'])} and rows > unread, got unread={notif_unread_expected} rows={len(notif_rows_expected)}"
            )
        booked_before = slot_booked(ok_appt)
        audit_before = db1(
            f"SELECT COUNT(*) FROM {T('cpms_audit_logs')} WHERE action='APPOINTMENT_CANCELLED' AND resource_type='appointment' AND resource_id={ok_appt}"
        )

        # ---------- 1) login → portal render ----------
        stage = "login"
        page.goto(f"{BASE}/wp-login.php", wait_until="networkidle")
        page.fill("#user_login", CFG["login"])
        page.fill("#user_pass", CFG["password"])
        try:
            with page.expect_navigation(timeout=15000):
                page.click("#wp-submit")
        except Exception:
            page.wait_for_load_state("networkidle", timeout=15000)
        page.wait_for_load_state("networkidle")
        if "wp-login.php" in page.url:
            raise RuntimeError(f"login failed: still on {page.url}")
        # Slice 3: login_redirect targets the frontend portal; fall back to navigate if needed.
        if not is_frontend_portal_url(page.url) and page.locator(SHELL_ROOT).count() == 0:
            page.goto(f"{BASE}{PORTAL_PATH}", wait_until="networkidle")
        assert_on_frontend_portal(page)
        landed_on_portal = is_frontend_portal_url(page.url) or page.locator(SHELL_ROOT).count() > 0

        stage = "render"
        page.wait_for_selector("h1", timeout=15000)
        h1 = page.locator("h1").first.inner_text().strip()
        if "نوبت" not in h1 or "من" not in h1:
            raise RuntimeError(f"portal title not rendered (h1={h1[:40]!r} url={page.url})")
        cfg_nodes = page.locator("script.cpms-patient-portal__config")
        if cfg_nodes.count() != 1:
            raise RuntimeError(f"exactly one runtime config script expected, got {cfg_nodes.count()}")
        cfg_raw = cfg_nodes.first.text_content() or ""
        cfg = json.loads(cfg_raw)
        required = {"cancel_path", "nonce", "notifications_read_path", "rest_root"}
        allowed = required | {"me_path", "my_records_path", "profile_initial", "profile_me_path",
                              "profile_my_records_path", "profile_records", "visits_path", "visit_detail_path",
                              "prescriptions_path"}
        if not required.issubset(set(cfg.keys())):
            raise RuntimeError(f"config must contain cancel_path/nonce/notifications_read_path/rest_root, got {sorted(cfg.keys())}")
        # Slice 5 adds only the existing C5/C6 route templates, never authority.
        if cfg.get("visits_path") != "/visits" or cfg.get("visit_detail_path") != "/visits/{id}":
            raise RuntimeError("Visits config must target existing C5/C6 routes")
        # Slice 6 adds only the existing C7 route template, never authority.
        if cfg.get("prescriptions_path") != "/prescriptions":
            raise RuntimeError("Prescriptions config must target existing C7 route")
        extra = set(cfg.keys()) - allowed
        if extra:
            raise RuntimeError(f"config contains unexpected keys {sorted(extra)}; allowed={sorted(allowed)}")
        for forbidden in ("clinic_id", "patient_id", "organization_id", "role"):
            if forbidden in cfg:
                raise RuntimeError(f"config must not publish authority key {forbidden}")
        if cfg["cancel_path"] != "/appointments/{id}/cancel":
            raise RuntimeError(f"cancel_path must target existing B4, got {cfg['cancel_path']!r}")
        if cfg["notifications_read_path"] != "/notifications/read":
            raise RuntimeError(f"notifications_read_path must target the EXISTING R2b route, got {cfg['notifications_read_path']!r}")
        if not str(cfg["rest_root"]).startswith(BASE):
            raise RuntimeError("rest_root must be same-origin (rest_url-derived)")
        if not isinstance(cfg["nonce"], str) or cfg["nonce"] == "":
            raise RuntimeError("nonce must be a non-empty string")
        if "clinic_id" in cfg_raw or "patient_id" in cfg_raw or "user_id" in cfg_raw or "recipient" in cfg_raw:
            raise RuntimeError("config must not carry clinic_id/patient_id/user_id/recipient")
        html = page.content()
        if "data-clinic-id" in html or "data-patient-id" in html:
            raise RuntimeError("portal markup must not publish clinic/patient ids")
        buttons = page.locator(BTN)
        btn_ids = sorted(int(x) for x in page.evaluate(
            "() => Array.from(document.querySelectorAll('button[data-role=\"cancel-appointment\"]')).map(b => b.getAttribute('data-appointment-id'))"
        ))
        # Every owned appointment whose server-known status is still `confirmed` (and upcoming) gets a
        # control — including the other viewport's not-yet-cancelled appointment. Derive the expectation
        # from the DB at check time, never from the viewport plan.
        confirmed_upcoming = sorted(set(list(CFG["ok_appts"].values()) + [fail_appt]))
        expected_ids = sorted(a for a in confirmed_upcoming if appt_status(a) == "confirmed")
        if ok_appt not in expected_ids or fail_appt not in expected_ids:
            raise RuntimeError(f"precondition: this run's ok/fail appointments must still be confirmed (expected={expected_ids})")
        if btn_ids != expected_ids:
            raise RuntimeError(f"cancel controls must exist exactly for the confirmed upcoming rows {expected_ids}, got {btn_ids}")
        for appt_id in (CFG["pending_appt"], CFG["history_appt"]):
            if page.locator(f'{BTN}[data-appointment-id="{appt_id}"]').count() != 0:
                raise RuntimeError(f"non-cancellable appointment #{appt_id} must render no control")
        ok_btn = page.locator(f'{BTN}[data-appointment-id="{ok_appt}"]')
        if not ok_btn.is_visible() or not ok_btn.is_enabled():
            raise RuntimeError("control for the confirmed upcoming appointment must be visible and enabled")
        if page.evaluate("(sel) => document.querySelector(sel).tagName", f'{BTN}[data-appointment-id="{ok_appt}"]') != "BUTTON":
            raise RuntimeError("control must be a real <button>")
        if ok_btn.get_attribute("type") != "button":
            raise RuntimeError("control must be type=button")
        acc_name = ok_btn.get_attribute("aria-label") or ok_btn.inner_text()
        if "لغو" not in acc_name:
            raise RuntimeError("accessible name must say لغو")
        row_in_upcoming = page.evaluate(
            """(id) => {
                const btn = document.querySelector('button[data-role="cancel-appointment"][data-appointment-id="' + id + '"]');
                const tr = btn && btn.closest('tr');
                let el = tr && tr.closest('table');
                while (el && el.tagName !== 'H2') { el = el.previousElementSibling; }
                return !!(el && el.textContent.indexOf('پیش') !== -1);
            }""",
            str(ok_appt),
        )
        if not row_in_upcoming:
            raise RuntimeError("control must live inside the UPCOMING table row")
        error_box = page.locator(ERROR_BOX)
        if error_box.count() != 1 or error_box.get_attribute("role") != "alert" or error_box.is_visible():
            raise RuntimeError("one hidden role=alert error region expected before any action")
        page.screenshot(path=screenshot("portal"), full_page=True)
        ok(f"{key0}-01-render", "Authenticated portal: config safe, controls only on confirmed rows",
           f"landed={landed_on_portal} config_keys=4 controls={btn_ids} (=all confirmed upcoming) pending_no_control=True history_no_control=True")

        # ---------- 1b) Slice 2: notifications section + exact unread badge + rows mirror the DB ----------
        stage = "notifications"
        db_unread = notif_unread_count()
        db_rows = notif_rows()
        if db_unread != notif_unread_expected or db_rows != notif_rows_expected:
            raise RuntimeError("notification rows changed between precondition and render")
        section = page.locator(NOTIF_SECTION)
        if section.count() != 1:
            raise RuntimeError(f"exactly one notifications section expected, got {section.count()}")
        if page.evaluate("(sel) => document.querySelector(sel).tagName", NOTIF_SECTION) != "SECTION":
            raise RuntimeError("notifications section must be a <section> landmark")
        heading = section.locator("h2").first.inner_text().strip()
        if "اعلان" not in heading:
            raise RuntimeError(f"notifications section must carry its own Persian heading, got {heading[:30]!r}")
        badge = section.locator(NOTIF_BADGE)
        if badge.count() != 1 or page.locator(NOTIF_BADGE).count() != 1:
            raise RuntimeError(f"exactly one unread badge inside the section expected (section={badge.count()}, page={page.locator(NOTIF_BADGE).count()})")
        if badge.get_attribute("data-unread-count") != str(db_unread):
            raise RuntimeError(f"data-unread-count must equal the server-known unread count {db_unread}, got {badge.get_attribute('data-unread-count')!r}")
        badge_text = (badge.inner_text() or "").translate(str.maketrans("۰۱۲۳۴۵۶۷۸۹", "0123456789"))
        if not re.search(rf"(?<!\d){db_unread}(?!\d)", badge_text):
            raise RuntimeError(f"badge must visibly show the exact unread count {db_unread}, got {badge_text!r}")
        if db_unread == len(db_rows):
            raise RuntimeError("fixture must keep unread ≠ rows so the badge cannot be a row count")
        dom_rows = dom_notification_rows(page)
        if [(r["id"], int(r["read"] or -1)) for r in dom_rows] != db_rows:
            raise RuntimeError(f"rendered rows must mirror the inbox exactly (id, read): dom={[(r['id'], r['read']) for r in dom_rows]} db={db_rows}")
        if section.locator(NOTIF_ROW).count() != len(db_rows):
            raise RuntimeError("every notification row must live inside the notifications section")
        for r in dom_rows:
            if not r["title"]:
                raise RuntimeError(f"row #{r['id']} must render its server-published title")
            if (r["read"] == "0") != ("جدید" in r["text"]):
                raise RuntimeError(f"row #{r['id']} must show the unread marker iff data-read=0")
        if page.locator(MARK_ALL).count() != 1:
            raise RuntimeError(f"exactly one mark-all-read control expected with unread={db_unread}, got {page.locator(MARK_ALL).count()}")
        mark_all = page.locator(MARK_ALL)
        if not mark_all.is_visible() or not mark_all.is_enabled():
            raise RuntimeError("mark-all-read control must be visible and enabled while something is unread")
        if page.evaluate("(sel) => document.querySelector(sel).tagName", MARK_ALL) != "BUTTON" or mark_all.get_attribute("type") != "button":
            raise RuntimeError("mark-all-read control must be a real <button type=button>")
        mark_all_label = (mark_all.get_attribute("aria-label") or mark_all.inner_text() or "").strip()
        if mark_all_label == "" or not PERSIAN_RE.search(mark_all_label):
            raise RuntimeError("mark-all-read control needs a non-empty Persian accessible name")
        mark_all.focus()
        if page.evaluate("() => document.activeElement && document.activeElement.getAttribute('data-role')") != "notifications-mark-all-read":
            raise RuntimeError("mark-all-read control must be focusable")
        notif_error = page.locator(NOTIF_ERROR)
        if notif_error.count() != 1 or notif_error.get_attribute("role") != "alert" or notif_error.is_visible():
            raise RuntimeError("one hidden role=alert notifications error region expected before any action")
        sw0 = page.evaluate("document.documentElement.scrollWidth")
        iw0 = page.evaluate("window.innerWidth")
        if sw0 > iw0 + 1:
            raise RuntimeError(f"horizontal overflow with notifications rendered: scrollWidth={sw0} > innerWidth={iw0}")
        page.screenshot(path=screenshot("notifications"), full_page=True)
        ok(f"{key0}-01b-notifications", "Slice 2: one accessible section, exact unread badge (≠ rows), rows mirror DB read state, real enabled mark-all button",
           f"section=1 unread_badge={db_unread} rows={len(db_rows)} read_rows={sum(1 for _, r in db_rows if r)} button=enabled focusable=True sw={sw0} iw={iw0}")

        # ---------- 1c) Slice 2 failure path: same click without X-WP-Nonce → canonical 403 → alert, re-enable, focus ----------
        stage = "notifications-fail"
        install_trace(page, MARK_ALL)
        posts_before = len(read_posts)

        def strip_nonce(route, request):
            headers = {k: v for k, v in request.headers.items() if k.lower() != "x-wp-nonce"}
            route.continue_(headers=headers)

        def is_rest(url):
            return "/clinic/v1" in url

        page.route(is_rest, strip_nonce)
        try:
            with page.expect_response(lambda r: r.request.method == "POST" and wp_route(r.url) == READ_ROUTE, timeout=20000) as fail_info:
                mark_all.click()
            fail_resp = fail_info.value
        finally:
            page.unroute(is_rest, strip_nonce)
        if fail_resp.status != 403:
            raise RuntimeError(f"the nonce-less mark-all must be rejected with 403, got {fail_resp.status}")
        fail_body = fail_resp.json()
        if fail_body.get("code") != "CLINIC_INVALID_NONCE":
            raise RuntimeError(f"expected the canonical CLINIC_INVALID_NONCE envelope, got {fail_body.get('code')!r}")
        page.wait_for_selector(f"{NOTIF_ERROR}:not([hidden])", state="visible", timeout=10000)
        nerr_text = (notif_error.inner_text() or "").strip()
        if nerr_text == "" or notif_error.get_attribute("data-error-code") != "CLINIC_INVALID_NONCE":
            raise RuntimeError("failure must surface a non-empty inline alert carrying the server code")
        if not PERSIAN_RE.search(nerr_text):
            raise RuntimeError("notifications inline error must be Persian")
        if error_box.is_visible():
            raise RuntimeError("the cancel error region must stay untouched by a notifications failure")
        page.wait_for_function(
            "(sel) => { const b = document.querySelector(sel); return b && !b.disabled && b.getAttribute('aria-busy') === 'false'; }",
            arg=MARK_ALL, timeout=10000,
        )
        ntrace = page.evaluate("() => window.__cpmsTrace || []")
        n_was_busy = any(t.get("disabled") is True and t.get("busy") == "true" for t in ntrace)
        if not n_was_busy:
            raise RuntimeError(f"mark-all control must be disabled + aria-busy while in flight (trace={ntrace[:3]})")
        if mark_all.inner_text().strip() != mark_all_label:
            raise RuntimeError("mark-all label must be restored after failure")
        if page.evaluate("() => document.activeElement && document.activeElement.getAttribute('data-role')") != "notifications-mark-all-read":
            raise RuntimeError("focus must return to the mark-all control after failure")
        if notif_unread_count() != db_unread or notif_rows() != db_rows:
            raise RuntimeError("a rejected mark-all must not mutate any notification")
        if page.locator(NOTIF_BADGE).get_attribute("data-unread-count") != str(db_unread):
            raise RuntimeError("badge must be unchanged after a rejected mark-all")
        if not is_frontend_portal_url(page.url) and page.locator(SHELL_ROOT).count() == 0:
            raise RuntimeError(f"failure must not navigate away from frontend portal (url={page.url})")
        fail_posts = read_posts[posts_before:]
        if len(fail_posts) != 1 or fail_posts[0]["body"].strip() != '{"all":true}' or not fail_posts[0]["same_origin"]:
            raise RuntimeError(f"exactly one same-origin POST with body {{\"all\":true}} expected, got {[(p['body'][:40], p['same_origin']) for p in fail_posts]}")
        page.screenshot(path=screenshot("notifications-fail"), full_page=True)
        ok(f"{key0}-01c-notifications-fail", "Slice 2 failure path: 403 CLINIC_INVALID_NONCE → Persian role=alert, control busy in flight then re-enabled + focused, DB unchanged",
           f"status=403 code=CLINIC_INVALID_NONCE body_all_true=True same_origin=True busy_seen={n_was_busy} focus_restored=True unread_still={db_unread}")

        # ---------- 1d) Slice 2 success path: real nonce → 200 → reload → badge/control gone, rows read & visible ----------
        stage = "notifications-success"
        posts_before = len(read_posts)
        with page.expect_navigation(wait_until="load", timeout=25000):
            with page.expect_response(lambda r: r.request.method == "POST" and wp_route(r.url) == READ_ROUTE, timeout=20000) as read_info:
                mark_all.click()
        read_resp = read_info.value
        if read_resp.status != 200:
            raise RuntimeError(f"the existing read route must accept {{all:true}} from the authenticated patient, got {read_resp.status}")
        try:
            marked = int(((read_resp.json() or {}).get("data") or {}).get("marked"))
        except Exception:
            marked = None  # body may be unavailable once the page has reloaded — DB state below is the proof
        if marked is not None and marked != db_unread:
            raise RuntimeError(f"mark-all must mark exactly the server-known unread notifications ({db_unread}), got marked={marked}")
        page.wait_for_load_state("networkidle")
        n_nav_type = page.evaluate("() => (performance.getEntriesByType('navigation')[0] || {}).type || ''")
        if n_nav_type != "reload":
            raise RuntimeError(f"success must reload the page (navigation type={n_nav_type!r})")
        if page.locator(NOTIF_SECTION).count() != 1:
            raise RuntimeError("notifications section must still render after mark-all")
        if page.locator(NOTIF_BADGE).count() != 0 or page.locator(MARK_ALL).count() != 0:
            raise RuntimeError("badge and mark-all control must be absent (not hidden/disabled) at unread=0")
        after_rows = dom_notification_rows(page)
        if [r["id"] for r in after_rows] != [i for i, _ in db_rows] or any(r["read"] != "1" for r in after_rows):
            raise RuntimeError(f"after mark-all every notification must remain visible with data-read=1, got {[(r['id'], r['read']) for r in after_rows]}")
        if any("جدید" in r["text"] for r in after_rows):
            raise RuntimeError("no row may still carry the unread marker after mark-all")
        if notif_unread_count() != 0 or any(not r for _, r in notif_rows()) or len(notif_rows()) != len(db_rows):
            raise RuntimeError("DB must show unread=0 with every row read and none removed")
        ok_posts = read_posts[posts_before:]
        if len(ok_posts) != 1 or ok_posts[0]["body"].strip() != '{"all":true}' or not ok_posts[0]["has_nonce"] or not ok_posts[0]["same_origin"]:
            raise RuntimeError(f"exactly one same-origin POST with X-WP-Nonce and body {{\"all\":true}} expected, got {[(p['body'][:40], p['has_nonce'], p['same_origin']) for p in ok_posts]}")
        sw1 = page.evaluate("document.documentElement.scrollWidth")
        iw1 = page.evaluate("window.innerWidth")
        if sw1 > iw1 + 1:
            raise RuntimeError(f"horizontal overflow after mark-all: scrollWidth={sw1} > innerWidth={iw1}")
        page.screenshot(path=screenshot("notifications-after"), full_page=True)
        ok(f"{key0}-01d-notifications-success", "Slice 2 success path: 200 {all:true} → reload → badge/control gone, rows read & visible, DB unread=0",
           f"status=200 marked={marked if marked is not None else '(unavailable after reload)'} reload=True nonce=True same_origin=True rows_visible={len(after_rows)} db_unread=0")

        # ---------- 2) keyboard: Enter opens confirm dialog; Escape closes without request ----------
        stage = "keyboard"
        calls_before = len(rest_calls)
        ok_btn.focus()
        if page.evaluate("() => document.activeElement && document.activeElement.getAttribute('data-appointment-id')") != str(ok_appt):
            raise RuntimeError("control must be focusable")
        page.keyboard.press("Enter")
        confirm_dialog(page)
        page.keyboard.press("Escape")
        page.wait_for_selector(DIALOG, state="detached", timeout=10000)
        page.wait_for_timeout(300)
        if len(rest_calls) != calls_before:
            raise RuntimeError("Escape must not send any request")
        if not ok_btn.is_enabled() or appt_status(ok_appt) != "confirmed":
            raise RuntimeError("Escape must leave the control enabled and the appointment confirmed")
        ok(f"{key0}-02-keyboard", "Keyboard: Enter opens accessible confirm, Escape aborts (no request)", "requests=0 status=confirmed")

        # ---------- 3) failure path (deadline policy) ----------
        stage = "fail-path"
        fail_btn = page.locator(f'{BTN}[data-appointment-id="{fail_appt}"]')
        install_trace(page, f'{BTN}[data-appointment-id="{fail_appt}"]')
        fail_btn.click()
        dialog = confirm_dialog(page)
        with page.expect_response(lambda r: r.request.method == "POST" and wp_route(r.url) == f"/clinic/v1/appointments/{fail_appt}/cancel", timeout=20000) as resp_info:
            dialog.locator(".cpms-modal-confirm").click()
        resp = resp_info.value
        if resp.status != 409:
            raise RuntimeError(f"B4 must reject the near-start appointment with 409, got {resp.status}")
        body = resp.json()
        if body.get("code") != "CLINIC_POLICY_VIOLATION":
            raise RuntimeError(f"expected CLINIC_POLICY_VIOLATION envelope, got {body.get('code')!r}")
        page.wait_for_selector(f"{ERROR_BOX}:not([hidden])", state="visible", timeout=10000)
        err_text = (error_box.inner_text() or "").strip()
        if err_text == "" or error_box.get_attribute("data-error-code") != "CLINIC_POLICY_VIOLATION":
            raise RuntimeError("canonical failure must surface a non-empty inline alert with the server code")
        if not re.search(r"[\u0600-\u06FF]", err_text):
            raise RuntimeError("inline error must be Persian")
        page.wait_for_function(
            "(id) => { const b = document.querySelector('button[data-role=\"cancel-appointment\"][data-appointment-id=\"' + id + '\"]'); return b && !b.disabled && b.getAttribute('aria-busy') === 'false'; }",
            arg=str(fail_appt), timeout=10000,
        )
        trace = page.evaluate("() => window.__cpmsTrace || []")
        was_busy = any(t.get("disabled") is True and t.get("busy") == "true" for t in trace)
        if not was_busy:
            raise RuntimeError(f"control must be disabled + aria-busy while the request is in flight (trace={trace[:3]})")
        if fail_btn.inner_text().strip() != "لغو نوبت":
            raise RuntimeError("control label must be restored after failure")
        focused = page.evaluate("() => document.activeElement && document.activeElement.getAttribute('data-appointment-id')")
        posts = [p for p in cancel_posts if p["route"] == f"/clinic/v1/appointments/{fail_appt}/cancel"]
        if len(posts) != 1:
            raise RuntimeError(f"exactly one POST expected for the failing control, got {len(posts)}")
        p0 = posts[0]
        if not p0["has_nonce"] or not p0["same_origin"]:
            raise RuntimeError("cancel POST must be same-origin and carry X-WP-Nonce")
        if p0["body"].strip() not in ("{}", ""):
            raise RuntimeError(f"cancel POST body must carry no authority fields, got {p0['body'][:80]!r}")
        if appt_status(fail_appt) != "confirmed":
            raise RuntimeError("rejected cancel must not mutate the appointment")
        if not is_frontend_portal_url(page.url) and page.locator(SHELL_ROOT).count() == 0:
            raise RuntimeError(f"failure must not navigate away from frontend portal (url={page.url})")
        page.screenshot(path=screenshot("fail-alert"), full_page=True)
        ok(f"{key0}-03-fail", "Failure path: 409 → Persian role=alert, control busy in flight then re-enabled, DB unchanged",
           f"status=409 code=CLINIC_POLICY_VIOLATION nonce={p0['has_nonce']} same_origin={p0['same_origin']} body_empty=True busy_seen={was_busy} focus_restored={focused == str(fail_appt)}")

        # ---------- 4) success path ----------
        stage = "success-path"
        ok_ref = appt_ref(ok_appt)
        ok_btn.click()
        dialog = confirm_dialog(page)
        with page.expect_navigation(wait_until="load", timeout=25000):
            with page.expect_response(lambda r: r.request.method == "POST" and wp_route(r.url) == f"/clinic/v1/appointments/{ok_appt}/cancel", timeout=20000) as ok_info:
                dialog.locator(".cpms-modal-confirm").click()
        ok_resp = ok_info.value
        if ok_resp.status != 200:
            raise RuntimeError(f"B4 must accept the own confirmed upcoming appointment, got {ok_resp.status}")
        # The body may be unavailable once the page has reloaded — read it best-effort; DB state below is the proof.
        try:
            envelope_status = str(((ok_resp.json() or {}).get("data") or {}).get("status") or "")
        except Exception:
            envelope_status = "(unavailable after reload)"
        if envelope_status not in ("cancelled_by_patient", "(unavailable after reload)"):
            raise RuntimeError(f"B4 envelope must report cancelled_by_patient, got {envelope_status!r}")
        page.wait_for_load_state("networkidle")
        nav_type = page.evaluate("() => (performance.getEntriesByType('navigation')[0] || {}).type || ''")
        if nav_type != "reload":
            raise RuntimeError(f"success must reload the page (navigation type={nav_type!r})")
        if page.locator(f'{BTN}[data-appointment-id="{ok_appt}"]').count() != 0:
            raise RuntimeError("cancelled appointment must no longer expose a control")
        remaining = sorted(int(x) for x in page.evaluate(
            "() => Array.from(document.querySelectorAll('button[data-role=\"cancel-appointment\"]')).map(b => b.getAttribute('data-appointment-id'))"
        ))
        if ok_appt in remaining:
            raise RuntimeError("cancelled appointment still listed as cancellable")
        in_history = page.evaluate(
            """(ref) => {
                const cells = Array.from(document.querySelectorAll('td'));
                const cell = cells.find(td => td.textContent.trim() === ref);
                const tr = cell && cell.closest('tr');
                let el = tr && tr.closest('table');
                while (el && el.tagName !== 'H2') { el = el.previousElementSibling; }
                return { found: !!tr, history: !!(el && el.textContent.indexOf('تاریخچه') !== -1),
                         cancelled: !!(tr && tr.textContent.indexOf('لغو توسط بیمار') !== -1) };
            }""",
            ok_ref,
        )
        if not (in_history["found"] and in_history["history"] and in_history["cancelled"]):
            raise RuntimeError(f"after reload the appointment must be listed under history as cancelled by patient, got {in_history}")
        if appt_status(ok_appt) != "cancelled_by_patient":
            raise RuntimeError(f"DB status must be cancelled_by_patient, got {appt_status(ok_appt)!r}")
        cancelled_by = db1(f"SELECT cancelled_by_wp_user_id FROM {T('cpms_appointments')} WHERE id={ok_appt}")
        if cancelled_by != user_id:
            raise RuntimeError("cancelled_by_wp_user_id must be the patient's own WP user")
        booked_after = slot_booked(ok_appt)
        if booked_after != booked_before - 1:
            raise RuntimeError(f"slot capacity must be released (booked_count {booked_before} → {booked_after})")
        audit_after = db1(
            f"SELECT COUNT(*) FROM {T('cpms_audit_logs')} WHERE action='APPOINTMENT_CANCELLED' AND resource_type='appointment' AND resource_id={ok_appt}"
        )
        if audit_after != audit_before + 1:
            raise RuntimeError(f"exactly one APPOINTMENT_CANCELLED audit row expected (before {audit_before} after {audit_after})")
        page.screenshot(path=screenshot("after-cancel"), full_page=True)
        ok(f"{key0}-04-success", "Success path: 200 → reload → row in history as cancelled; DB/slot/audit consistent",
           f"status=200 envelope={envelope_status} reload=True in_history=True db=cancelled_by_patient booked={booked_before}->{booked_after} audit=+1 remaining_controls={len(remaining)}")

        # ---------- 5) UX ----------
        stage = "ux"
        sw = page.evaluate("document.documentElement.scrollWidth")
        iw = page.evaluate("window.innerWidth")
        if sw > iw + 1:
            raise RuntimeError(f"horizontal overflow: scrollWidth={sw} > innerWidth={iw}")
        if console_errors:
            raise RuntimeError(f"unexpected console errors: {console_errors[:2]}")
        if page_errors:
            raise RuntimeError(f"page errors: {page_errors[:2]}")
        if net_failed:
            raise RuntimeError(f"failed same-origin requests: {net_failed}")
        expected_calls = sorted([
            ("POST", READ_ROUTE, 403),
            ("POST", READ_ROUTE, 200),
            ("POST", f"/clinic/v1/appointments/{fail_appt}/cancel", 409),
            ("POST", f"/clinic/v1/appointments/{ok_appt}/cancel", 200),
        ])
        if sorted(rest_calls) != expected_calls:
            raise RuntimeError(f"unexpected REST traffic: {rest_calls}")
        for p in cancel_posts + read_posts:
            if any(k in p["body"] for k in ("clinic_id", "patient_id", "user_id", "recipient")):
                raise RuntimeError("no portal POST may carry clinic_id/patient_id/user_id/recipient")
        ok(f"{key0}-05-ux", "UX: no overflow, no console/page errors, no failed request, only expected REST calls",
           f"sw={sw} iw={iw} rest_calls={len(rest_calls)} console=0 net=0 aborted_by_reload={len(aborted_by_nav)}")

    except Exception as e:
        try:
            page.screenshot(path=screenshot("FAIL"), full_page=True)
        except Exception:
            pass
        fail(f"{key0}-{stage}", f"portal cancel {run['vp']}", e)
        raise
    finally:
        ctx.close()


def capture_shell_visual(browser, width: int, height: int, tag: str) -> None:
    """Owner visual package — one additional viewport (e.g. ~768 tablet) without cancel traffic."""
    key = f"visual-{tag}"
    ctx = browser.new_context(
        viewport={"width": width, "height": height},
        locale="fa-IR",
        ignore_https_errors=True,
    )
    page = ctx.new_page()
    try:
        page.goto(f"{BASE}/wp-login.php", wait_until="networkidle")
        page.fill("#user_login", CFG["login"])
        page.fill("#user_pass", CFG["password"])
        try:
            with page.expect_navigation(timeout=15000):
                page.click("#wp-submit")
        except Exception:
            page.wait_for_load_state("networkidle", timeout=15000)
        page.wait_for_load_state("networkidle")
        if "wp-login.php" in page.url:
            raise RuntimeError(f"visual login failed: {page.url}")
        if not is_frontend_portal_url(page.url) and page.locator(SHELL_ROOT).count() == 0:
            page.goto(f"{BASE}{PORTAL_PATH}", wait_until="networkidle")
        assert_on_frontend_portal(page)
        page.wait_for_selector("h1", timeout=15000)
        page.screenshot(path=f"{OUT}/portal-shell-{tag}-main.png", full_page=True)
        # Empty/error affordance regions exist (hidden until used).
        err = page.locator('[data-role="cancel-error"]')
        has_err_region = err.count() >= 1
        ok(
            f"{key}-shell",
            f"Owner visual: frontend shell at {width}×{height} (no theme/wp-admin chrome)",
            f"url_ok=True shell=True err_region={has_err_region} shot=portal-shell-{tag}-main.png",
        )
    except Exception as e:
        try:
            page.screenshot(path=f"{OUT}/portal-shell-{tag}-FAIL.png", full_page=True)
        except Exception:
            pass
        fail(f"{key}", f"shell visual {tag}", e)
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
                continue
        # Owner visual package: tablet ~768 (in addition to 390 and 1366 journey shots).
        if not failures:
            try:
                capture_shell_visual(browser, 768, 1024, "tablet-768")
            except Exception:
                pass
        browser.close()
    summary = {"ok": not failures, "runs": len(RUNS), "failed": failures}
    with open("pilot-portal-cancel-results.json", "w", encoding="utf-8") as f:
        json.dump({"summary": summary, "results": results}, f, ensure_ascii=False, indent=1)
    print(json.dumps(summary, ensure_ascii=False))
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
