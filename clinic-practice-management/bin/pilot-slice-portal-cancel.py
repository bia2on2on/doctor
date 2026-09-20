#!/usr/bin/env python3
"""pilot-slice-portal-cancel.py — Phase 9 Slice 1: patient self-cancel from the Patient Portal
(«نوبت‌های من», wp-admin page cpms-patient) on a real Chromium, through the EXISTING B4 route
POST clinic/v1/appointments/{id}/cancel.

Fixture (created by the workflow step before this script):
  PORTAL_CANCEL="login|pass|clinic_id|patient_id|ok_appt_390|ok_appt_1366|fail_appt|pending_appt|history_appt"

  - ok_appt_*   : own CONFIRMED appointments far in the future (one per viewport) → cancel succeeds
  - fail_appt   : own CONFIRMED appointment starting in ~2h → B4 rejects (409 CLINIC_POLICY_VIOLATION)
  - pending_appt: own PENDING upcoming appointment → no cancel control may render
  - history_appt: own already-cancelled appointment → history table, no control

Journey (per viewport 390x844 and 1366x768):
  1. Login as the pure patient → landed on the portal; runtime config has exactly rest_root/cancel_path/nonce
     (no clinic_id/patient_id anywhere), controls exist ONLY for confirmed upcoming rows.
  2. Keyboard: Enter on the control opens the accessible confirm dialog; Escape closes it, no request sent.
  3. Failure path: confirm on fail_appt → same-origin POST with X-WP-Nonce and body {} → 409 →
     Persian role=alert error visible, control was disabled/aria-busy in flight and is re-enabled; DB unchanged.
  4. Success path: confirm on ok_appt → 200 → page reloads → row gone from upcoming, listed in history as
     cancelled; DB status cancelled_by_patient, slot capacity released, audit row written.
  5. UX: no horizontal overflow, no page errors, no failed same-origin requests, only the two expected REST calls.

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
    raise SystemExit("PORTAL_CANCEL is required: login|pass|clinic|patient|ok390|ok1366|fail|pending|history")
_parts = [p.strip() for p in _raw.split("|")]
if len(_parts) < 9 or not _parts[0]:
    raise SystemExit("PORTAL_CANCEL must carry 9 pipe-separated parts")
CFG = {
    "login": _parts[0],
    "password": _parts[1],
    "clinic_id": int(_parts[2]),
    "patient_id": int(_parts[3]),
    "ok_appts": {"mobile-390": int(_parts[4]), "laptop-1366": int(_parts[5])},
    "fail_appt": int(_parts[6]),
    "pending_appt": int(_parts[7]),
    "history_appt": int(_parts[8]),
}

RUNS = [
    {"vp": "mobile-390", "w": 390, "h": 844},
    {"vp": "laptop-1366", "w": 1366, "h": 768},
]

PORTAL_PATH = "/wp-admin/admin.php?page=cpms-patient"
CANCEL_ROUTE_RE = re.compile(r"^/clinic/v1/appointments/(\d+)/cancel$")
BTN = 'button[data-role="cancel-appointment"]'
ERROR_BOX = '[data-role="cancel-error"]'
DIALOG = '.cpms-modal[role="dialog"]'

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


def install_trace(page, appt_id):
    """MutationObserver on the control: records disabled/aria-busy/text transitions (proof of in-flight state)."""
    page.evaluate(
        """(id) => {
            const btn = document.querySelector('button[data-role="cancel-appointment"][data-appointment-id="' + id + '"]');
            window.__cpmsTrace = [];
            if (!btn) { return; }
            const snap = () => ({ disabled: btn.disabled, busy: btn.getAttribute('aria-busy'), text: btn.textContent });
            const obs = new MutationObserver(() => window.__cpmsTrace.push(snap()));
            obs.observe(btn, { attributes: true, childList: true, characterData: true, subtree: true });
        }""",
        str(appt_id),
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
        if "page=cpms-patient" not in page.url:
            page.goto(f"{BASE}{PORTAL_PATH}", wait_until="networkidle")
        landed_on_portal = "page=cpms-patient" in page.url

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
        if sorted(cfg.keys()) != ["cancel_path", "nonce", "rest_root"]:
            raise RuntimeError(f"config keys must be exactly cancel_path/nonce/rest_root, got {sorted(cfg.keys())}")
        if cfg["cancel_path"] != "/appointments/{id}/cancel":
            raise RuntimeError(f"cancel_path must target existing B4, got {cfg['cancel_path']!r}")
        if not str(cfg["rest_root"]).startswith(BASE):
            raise RuntimeError("rest_root must be same-origin (rest_url-derived)")
        if not isinstance(cfg["nonce"], str) or cfg["nonce"] == "":
            raise RuntimeError("nonce must be a non-empty string")
        if "clinic_id" in cfg_raw or "patient_id" in cfg_raw:
            raise RuntimeError("config must not carry clinic_id/patient_id")
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
           f"landed={landed_on_portal} config_keys=3 controls={btn_ids} (=all confirmed upcoming) pending_no_control=True history_no_control=True")

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
        install_trace(page, fail_appt)
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
        if "page=cpms-patient" not in page.url:
            raise RuntimeError("failure must not navigate away")
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
            ("POST", f"/clinic/v1/appointments/{fail_appt}/cancel", 409),
            ("POST", f"/clinic/v1/appointments/{ok_appt}/cancel", 200),
        ])
        if sorted(rest_calls) != expected_calls:
            raise RuntimeError(f"unexpected REST traffic: {rest_calls}")
        for p in cancel_posts:
            if "clinic_id" in p["body"] or "patient_id" in p["body"]:
                raise RuntimeError("no cancel POST may carry clinic_id/patient_id")
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


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for run in RUNS:
            try:
                run_journey(browser, run)
            except Exception:
                continue
        browser.close()
    summary = {"ok": not failures, "runs": len(RUNS), "failed": failures}
    with open("pilot-portal-cancel-results.json", "w", encoding="utf-8") as f:
        json.dump({"summary": summary, "results": results}, f, ensure_ascii=False, indent=1)
    print(json.dumps(summary, ensure_ascii=False))
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
