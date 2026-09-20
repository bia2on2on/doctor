#!/usr/bin/env python3
"""pilot-slice3-chooser.py — Phase 8 Slice 3 N>1 patient chooser on real browser.

Fixture (created by workflow step before this script):
  SLICE3_PAGE="url|clinic_id|clinician_id|location_id|patientA_id|patientB_id|decoy_id|slot_id|user_login|user_pass"

Journey (per viewport 390x844 and 1366x768):
  1. Anonymous: no chooser/list/data
  2. After authenticated patient render: N chooser visible, exactly A and B selectable, decoy absent
  3. Select Patient B: visible selected state, no default silently chosen
  4. B1: request body contains B's patient_id, real B1 succeeds, persisted cpms_slot_holds.patient_id = B
  5. B2: confirm, persisted Appointment.patient_id = B, A has 0, decoy 0, no new Patient/link
  6. UX: no overflow, no console error, no failed same-origin request

Evidence: booleans, counts, non-sensitive IDs only. No names/mobile/nonce/cookies/secrets.
"""
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

_raw = os.environ.get("SLICE3_PAGE", "").strip()
if not _raw:
    raise SystemExit("SLICE3_PAGE is required: url|clinic|clinician|location|patientA|patientB|decoy|slot|login|pass")
_parts = [p.strip() for p in _raw.split("|")]
if len(_parts) < 10 or not _parts[0]:
    raise SystemExit("SLICE3_PAGE must carry 10 pipe-separated parts")
CFG = {
    "url": _parts[0],
    "clinic_id": int(_parts[1]),
    "clinician_id": int(_parts[2]),
    "location_id": int(_parts[3]),
    "patientA_id": int(_parts[4]),
    "patientB_id": int(_parts[5]),
    "decoy_id": int(_parts[6]),
    "slot_id": int(_parts[7]),
    "login": _parts[8],
    "password": _parts[9],
}

RUNS = [
    {"vp": "mobile-390", "w": 390, "h": 844},
    {"vp": "laptop-1366", "w": 1366, "h": 768},
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
    cell = db(sql).split("\n")[0].strip() if db(sql) else "0"
    # Need to handle empty
    raw = db(sql)
    if not raw:
        return 0
    first = raw.split("\n")[0].strip()
    if not first or first.upper() == "NULL":
        return 0
    try:
        return int(float(first))
    except:
        return 0

def dbrows(sql):
    raw = db(sql)
    if not raw:
        return []
    return [tuple(line.split("\t")) for line in raw.split("\n")]

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

def run_journey(browser, run):
    key0 = f"slice3-{run['vp']}"
    screenshot = lambda suffix: f"{OUT}/{key0}-{suffix}.png"
    ctx = browser.new_context(viewport={"width": run["w"], "height": run["h"]})
    page = ctx.new_page()
    page.set_default_timeout(25000)
    rest_calls = []
    console_errors, page_errors, net_failed = [], [], {}
    b1_bodies = []  # capture B1 request bodies

    def on_response(resp):
        if not resp.url.startswith(BASE) or "/clinic/v1" not in resp.url:
            return
        rest_calls.append((resp.request.method, wp_route(resp.url), resp.status))
        # Capture B1 body
        if "/clinic/v1/booking/hold" in resp.url and resp.request.method == "POST":
            try:
                body = resp.request.post_data or ""
                # Try to parse JSON
                if body:
                    j = json.loads(body)
                    b1_bodies.append(j)
            except:
                pass

    def on_console(msg):
        if msg.type == "error":
            txt = msg.text[:200]
            # Ignore benign favicon
            if "favicon" in txt or "Failed to load resource" in txt:
                return
            console_errors.append(txt)

    def on_pageerror(err):
        page_errors.append(str(err)[:200])

    def on_requestfailed(req):
        url = req.url
        if not url.startswith(BASE) or "favicon" in url or ".map" in url:
            return
        net_failed.setdefault(req.failure or "failed", []).append(url)

    page.on("response", on_response)
    page.on("console", on_console)
    page.on("pageerror", on_pageerror)
    page.on("requestfailed", on_requestfailed)

    stage = "boot"
    try:
        os.makedirs(OUT, exist_ok=True)
        # Listen for B1 request to capture body even before response
        def handle_request(req):
            if "/clinic/v1/booking/hold" in req.url and req.method == "POST":
                try:
                    body = req.post_data or ""
                    if body and not any(b.get("patient_id") == CFG["patientB_id"] for b in b1_bodies):
                        # Will be captured on response too, but also here
                        j = json.loads(body)
                        # Deduplicate
                        if j not in b1_bodies:
                            b1_bodies.append(j)
                except:
                    pass
        page.on("request", handle_request)

        # --- 1) Anonymous: no chooser ---
        stage = "anon"
        page.goto(CFG["url"], wait_until="networkidle")
        # Anonymous should have no patient chooser
        anon_chooser = page.locator('[data-role="patient-chooser"]').count()
        anon_options = page.locator('[data-role="patient-option"]').count()
        anon_html = page.locator(".cpms-public-booking").first.evaluate("el => el.outerHTML") if page.locator(".cpms-public-booking").count()>0 else ""
        # Check for patient chooser absence and no patient data
        if anon_chooser != 0:
            raise RuntimeError(f"anonymous page must have no chooser, got {anon_chooser}")
        if anon_options != 0:
            raise RuntimeError(f"anonymous page must have no patient options, got {anon_options}")
        if "patient-chooser" in anon_html or "patient-option" in anon_html:
            raise RuntimeError("anonymous page leaked patient chooser tokens")
        # Also check that no patient_id in HTML
        if 'data-patient-id' in anon_html:
            raise RuntimeError("anonymous page leaked data-patient-id")
        ok(f"{key0}-01-anon", "Anonymous: no chooser/list/data", f"chooser=0 options=0 clinic={CFG['clinic_id']}")

        # --- 2) Login as patient user, then authenticated render ---
        stage = "login"
        # Verify fixture user exists before attempting login (DB check for diagnostics)
        user_check = db1(f"SELECT ID FROM {T('users')} WHERE user_login='{CFG['login']}'")
        if user_check == 0:
            raise RuntimeError(f"fixture user {CFG['login']} not found in DB")
        role_check = db1(f"SELECT COUNT(*) FROM {T('usermeta')} WHERE user_id={user_check} AND meta_key='{DB_PREFIX}capabilities' AND meta_value LIKE '%cpms_patient%'")
        if role_check == 0:
            raise RuntimeError(f"fixture user {CFG['login']} missing cpms_patient role")
        page.goto(f"{BASE}/wp-login.php", wait_until="networkidle")
        page.fill("#user_login", CFG["login"])
        page.fill("#user_pass", CFG["password"])
        # Use expect_navigation to avoid race where wait_for_load_state misses the redirect
        try:
            with page.expect_navigation(timeout=15000):
                page.click("#wp-submit")
        except:
            page.wait_for_load_state("networkidle", timeout=15000)
        page.wait_for_load_state("networkidle")
        if "wp-login.php" in page.url:
            # Dump page content for diagnostics (no secrets)
            body_txt = page.content()[:2000]
            raise RuntimeError(f"login failed for {CFG['login']}: {page.url} body={body_txt[:500]}")
        # Now go to booking page again
        page.goto(CFG["url"], wait_until="networkidle")
        # Wait for chooser to appear (it is server-rendered, so should be immediate, but wait)
        # Increase timeout and add diagnostics if not found
        try:
            page.wait_for_selector('[data-role="patient-chooser"]', timeout=15000)
        except Exception as e:
            # Diagnostics: check if page is still showing anonymous or error, and dump relevant HTML
            html = page.content()[:4000]
            cfg_html = page.locator("script.cpms-public-booking__config").first.text_content() if page.locator("script.cpms-public-booking__config").count()>0 else "no-config"
            chooser_count = page.locator('[data-role="patient-chooser"]').count()
            option_count = page.locator('[data-role="patient-option"]').count()
            surface_html = page.locator(".cpms-public-booking").first.evaluate("el => el.outerHTML.slice(0,3000)") if page.locator(".cpms-public-booking").count()>0 else "no-surface"
            raise RuntimeError(f"chooser not found after login (clinic={CFG['clinic_id']} user={CFG['login']} chooser={chooser_count} options={option_count} cfg={cfg_html[:300]} surface={surface_html[:600]} html={html[:600]}) from {e}")
        chooser = page.locator('[data-role="patient-chooser"]')
        if chooser.count() != 1:
            raise RuntimeError(f"authenticated N chooser must be visible, got {chooser.count()}")
        options = page.locator('[data-role="patient-option"]')
        opt_count = options.count()
        if opt_count != 2:
            raise RuntimeError(f"N chooser must have exactly 2 options (A and B), got {opt_count}")
        # Check that A and B are present, decoy absent
        a_present = page.locator(f'[data-patient-id="{CFG["patientA_id"]}"]').count()
        b_present = page.locator(f'[data-patient-id="{CFG["patientB_id"]}"]').count()
        decoy_present = page.locator(f'[data-patient-id="{CFG["decoy_id"]}"]').count() if CFG["decoy_id"]>0 else 0
        if a_present != 1:
            raise RuntimeError(f"Patient A id {CFG['patientA_id']} must be selectable, got {a_present}")
        if b_present != 1:
            raise RuntimeError(f"Patient B id {CFG['patientB_id']} must be selectable, got {b_present}")
        if decoy_present != 0:
            raise RuntimeError(f"Decoy id {CFG['decoy_id']} must be absent, got {decoy_present}")
        # Check that no other patient options exist beyond A and B (exactly those two)
        all_ids = page.evaluate("""() => {
            return Array.from(document.querySelectorAll('[data-role="patient-option"]')).map(el => el.getAttribute('data-patient-id'));
        }""")
        if set(all_ids) != {str(CFG["patientA_id"]), str(CFG["patientB_id"])}:
            raise RuntimeError(f"option set must be exactly A/B, got {all_ids}")
        # Check no horizontal overflow
        sw = page.evaluate("document.documentElement.scrollWidth")
        iw = page.evaluate("window.innerWidth")
        if sw > iw + 1:
            raise RuntimeError(f"horizontal overflow: scrollWidth={sw} > innerWidth={iw}")
        ok(f"{key0}-02-chooser", "Authenticated N chooser: exactly A and B, decoy absent", f"options=2 A={CFG['patientA_id']} B={CFG['patientB_id']} decoy=0 overflow=0")

        # --- 3) Select Patient B: visible selected state, no default silently chosen ---
        stage = "select-B"
        # Before selection, check that no option is pre-selected (aria-pressed false for all, or at least not B)
        # The chooser should not have a default; after our fix, restore only if validated, but initially none selected.
        # Check current pressed states
        pressed_before = page.evaluate("""() => {
            return Array.from(document.querySelectorAll('[data-role="patient-option"]')).map(el => el.getAttribute('aria-pressed'));
        }""")
        # It's ok if one is pressed due to restored session, but we need to verify that after we clear and reload, no default.
        # For this test, we will ensure that initially, after login, no default is silently chosen unless we had a valid stored.
        # We'll clear sessionStorage for this clinic and reload to test clean state.
        # First, test selection of B changes state
        b_option = page.locator(f'[data-patient-id="{CFG["patientB_id"]}"]')
        # Ensure B is not already pressed (if it is due to previous session, we need to clear)
        # Let's clear and reload to get clean state
        page.evaluate(f"sessionStorage.removeItem('cpms-patient-selection:{CFG['clinic_id']}')")
        page.reload(wait_until="networkidle")
        page.wait_for_selector('[data-role="patient-chooser"]')
        # Now check again: no pressed
        pressed_clean = page.evaluate("""() => {
            return Array.from(document.querySelectorAll('[data-role="patient-option"]')).map(el => el.getAttribute('aria-pressed'));
        }""")
        if any(p == "true" for p in pressed_clean):
            raise RuntimeError(f"no default Patient should be silently chosen after clean reload, got pressed={pressed_clean}")
        # Now select B
        page.locator(f'[data-patient-id="{CFG["patientB_id"]}"]').click()
        # Wait for aria-pressed to update
        page.wait_for_function(f"document.querySelector('[data-patient-id=\"{CFG['patientB_id']}\"]').getAttribute('aria-pressed') === 'true'")
        b_pressed = page.locator(f'[data-patient-id="{CFG["patientB_id"]}"]').get_attribute("aria-pressed")
        a_pressed = page.locator(f'[data-patient-id="{CFG["patientA_id"]}"]').get_attribute("aria-pressed")
        if b_pressed != "true" or a_pressed != "false":
            raise RuntimeError(f"select B must change visible state: B pressed={b_pressed}, A pressed={a_pressed}")
        # Check sessionStorage has B
        stored = page.evaluate(f"sessionStorage.getItem('cpms-patient-selection:{CFG['clinic_id']}')")
        if stored != str(CFG["patientB_id"]):
            raise RuntimeError(f"sessionStorage must contain B id after selection, got {stored!r}")
        ok(f"{key0}-03-selectB", "Select Patient B: visible state changes, no default", f"B pressed=true A pressed=false stored=B")

        # --- 4) B1: request body contains B's patient_id, real B1 succeeds, holds.patient_id = B ---
        stage = "b1"
        # Use distinct slot per viewport to avoid CLINIC_DUPLICATE_APPOINTMENT across viewports (same patient B).
        # Fixture creates two slots (slotIds[0] and slotIds[1] = slot_id+1); mobile uses first, laptop uses second.
        slot_for_run = CFG["slot_id"] + (1 if "laptop" in run["vp"] else 0)
        # Need to select a clinician and slot, then B1 will be triggered.
        # The chooser is already with B selected. Now we need to go through A1/A4 then B1.
        # First, click clinician
        with page.expect_response(lambda r: "/clinic/v1/availability" in r.url, timeout=25000) as a1_info:
            page.locator(".cpms-public-booking__clinician").first.click()
        a1 = a1_info.value
        if a1.status != 200:
            raise RuntimeError(f"A1 failed: {a1.status}")
        page.wait_for_selector(".cpms-public-booking__slot", timeout=10000)
        # Find a slot that is bookable (we know slot_id from fixture)
        # The slot we created should be available. Let's click the first slot.
        # Need to ensure we capture B1 body.
        # Clear previous b1_bodies
        b1_bodies.clear()
        # Click slot -> triggers A4 then B1 automatically for patient mode
        # We need to wait for B1 hold
        # The slot's data-slot-id should match our fixture's slot_id
        slot_btn = page.locator(f'.cpms-public-booking__slot[data-slot-id="{slot_for_run}"]')
        if slot_btn.count() == 0:
            # Fallback to first slot
            slot_btn = page.locator(".cpms-public-booking__slot").first
            slot_for_run = int(slot_btn.get_attribute("data-slot-id") or slot_for_run)
        # Listen for hold
        with page.expect_response(lambda r: "/clinic/v1/booking/hold" in r.url, timeout=25000) as hold_info:
            slot_btn.click()
        hold_resp = hold_info.value
        if hold_resp.status != 200:
            body = ""
            try:
                body = hold_resp.text()
            except:
                pass
            raise RuntimeError(f"B1 hold failed: {hold_resp.status} body={body[:500]}")
        # Check that B1 body contained B's patient_id
        found_b1 = any(b.get("patient_id") == CFG["patientB_id"] for b in b1_bodies) if b1_bodies else False
        if not found_b1:
            # Try to get from request that was captured via page.on("request")
            # Also try to check via hold response's request
            try:
                req_body = hold_resp.request.post_data
                if req_body:
                    j = json.loads(req_body)
                    found_b1 = j.get("patient_id") == CFG["patientB_id"]
            except:
                pass
        if not found_b1:
            raise RuntimeError(f"B1 request body must contain B's patient_id={CFG['patientB_id']}, got bodies={b1_bodies}")
        # Check persisted holds.patient_id = B
        holds = dbrows(f"SELECT patient_id, clinic_id, slot_id, holder_wp_user_id FROM {T('cpms_slot_holds')} WHERE slot_id={slot_for_run} AND holder_wp_user_id=(SELECT ID FROM {T('users')} WHERE user_login='{CFG['login']}') ORDER BY id DESC LIMIT 1")
        if not holds:
            raise RuntimeError("no hold row found after B1")
        hold_pid, hold_clinic, hold_slot, hold_uid = holds[0]
        if int(hold_pid) != CFG["patientB_id"]:
            raise RuntimeError(f"persisted cpms_slot_holds.patient_id must be B ({CFG['patientB_id']}), got {hold_pid}")
        if int(hold_clinic) != CFG["clinic_id"]:
            raise RuntimeError(f"hold clinic must be {CFG['clinic_id']}, got {hold_clinic}")
        ok(f"{key0}-04-b1", "B1: body contains B, hold.patient_id = B", f"hold patient_id=B clinic={hold_clinic} slot={hold_slot}")

        # --- 5) B2: confirm, persisted Appointment.patient_id = B, A 0, decoy 0, no new Patient/link ---
        stage = "b2"
        # Need to wait for confirm button to be visible
        page.wait_for_selector('[data-role="confirm-btn"]', state="visible", timeout=10000)
        # Check initial counts
        a_appointments_before = db1(f"SELECT COUNT(*) FROM {T('cpms_appointments')} WHERE patient_id={CFG['patientA_id']}")
        b_appointments_before = db1(f"SELECT COUNT(*) FROM {T('cpms_appointments')} WHERE patient_id={CFG['patientB_id']}")
        decoy_appointments_before = db1(f"SELECT COUNT(*) FROM {T('cpms_appointments')} WHERE patient_id={CFG['decoy_id']}") if CFG["decoy_id"]>0 else 0
        patients_before = db1(f"SELECT COUNT(*) FROM {T('cpms_patients')} WHERE clinic_id={CFG['clinic_id']}")
        links_before = db1(f"SELECT COUNT(*) FROM {T('cpms_patient_user_links')} WHERE wp_user_id=(SELECT ID FROM {T('users')} WHERE user_login='{CFG['login']}')")
        with page.expect_response(lambda r: "/clinic/v1/booking/confirm" in r.url, timeout=25000) as confirm_info:
            page.locator('[data-role="confirm-btn"]').click()
        confirm_resp = confirm_info.value
        if confirm_resp.status != 200:
            body = ""
            try:
                body = confirm_resp.text()
            except:
                pass
            raise RuntimeError(f"B2 confirm failed: {confirm_resp.status} body={body[:500]}")
        # Check receipt visible
        page.wait_for_selector('[data-role="receipt"]', state="visible", timeout=10000)
        ref_code = page.locator('[data-role="reference-code"]').first.text_content() or ""
        if not re.fullmatch(r"AP-\d{8}-\d{2}", ref_code.strip()):
            raise RuntimeError(f"receipt reference_code format wrong: {ref_code!r}")
        # Check persisted Appointment.patient_id = B
        app_rows = dbrows(f"SELECT id, patient_id, clinic_id FROM {T('cpms_appointments')} WHERE slot_id={slot_for_run} AND clinic_id={CFG['clinic_id']} ORDER BY id DESC LIMIT 1")
        if not app_rows:
            raise RuntimeError("no appointment found after B2")
        appt_id, appt_pid, appt_clinic = app_rows[0]
        if int(appt_pid) != CFG["patientB_id"]:
            raise RuntimeError(f"Appointment.patient_id must be B ({CFG['patientB_id']}), got {appt_pid}")
        # Check A has 0, decoy 0
        a_after = db1(f"SELECT COUNT(*) FROM {T('cpms_appointments')} WHERE patient_id={CFG['patientA_id']}")
        b_after = db1(f"SELECT COUNT(*) FROM {T('cpms_appointments')} WHERE patient_id={CFG['patientB_id']}")
        decoy_after = db1(f"SELECT COUNT(*) FROM {T('cpms_appointments')} WHERE patient_id={CFG['decoy_id']}") if CFG["decoy_id"]>0 else 0
        if a_after != 0:
            raise RuntimeError(f"Patient A must have zero Appointment from this journey, got {a_after} (before {a_appointments_before})")
        if decoy_after != 0:
            raise RuntimeError(f"Decoy must have zero Appointment, got {decoy_after}")
        if b_after != 1:
            raise RuntimeError(f"Patient B must have exactly 1 Appointment, got {b_after}")
        # No new Patient auto-created
        patients_after = db1(f"SELECT COUNT(*) FROM {T('cpms_patients')} WHERE clinic_id={CFG['clinic_id']}")
        if patients_after != patients_before:
            raise RuntimeError(f"no new Patient should be auto-created: before {patients_before} after {patients_after}")
        # No extra link
        links_after = db1(f"SELECT COUNT(*) FROM {T('cpms_patient_user_links')} WHERE wp_user_id=(SELECT ID FROM {T('users')} WHERE user_login='{CFG['login']}')")
        if links_after != links_before:
            raise RuntimeError(f"no extra patient_user_link should be created: before {links_before} after {links_after}")
        # Check sessionStorage cleared after B2
        stored_after = page.evaluate(f"sessionStorage.getItem('cpms-patient-selection:{CFG['clinic_id']}')")
        if stored_after is not None:
            raise RuntimeError(f"patient-selection session key must be removed after B2, got {stored_after!r}")
        ok(f"{key0}-05-b2", "B2: Appointment.patient_id = B, A 0 decoy 0 no new Patient/link, storage cleared", f"appt patient_id=B ref={ref_code.strip()}")

        # --- 6) UX: no overflow, no console error, no failed request ---
        stage = "ux"
        sw = page.evaluate("document.documentElement.scrollWidth")
        iw = page.evaluate("window.innerWidth")
        if sw > iw + 1:
            raise RuntimeError(f"horizontal overflow: scrollWidth={sw} > innerWidth={iw}")
        # Filter console errors: allow 422 for N>1 missing selection if we had tested, but our journey is valid so no 422
        hard_console = [c for c in console_errors if "status of 422" not in c and "status of 400" not in c]
        if hard_console:
            raise RuntimeError(f"unexpected console errors: {hard_console[:2]}")
        if page_errors:
            raise RuntimeError(f"page errors: {page_errors[:2]}")
        if net_failed:
            raise RuntimeError(f"failed same-origin requests: {net_failed}")
        unexpected_rest = [(m,r,s) for (m,r,s) in rest_calls if s >= 400 and not (r=="/clinic/v1/booking/confirm" and s in (400,422))]
        # Allow 422 for holds that might have been tested earlier, but our valid journey should have only 200s plus maybe 1 422 for gate test
        # For this journey, we expect all 200s
        bad = [(m,r,s) for (m,r,s) in rest_calls if s >= 400]
        if bad:
            # Check if it's expected: we had no invalid B1, so no 422 expected
            raise RuntimeError(f"unexpected failed REST calls: {bad}")
        page.screenshot(path=screenshot("receipt"), full_page=False)
        ok(f"{key0}-06-ux", "UX: no overflow, no console error, no failed request", f"sw={sw} iw={iw} console=0 net=0")

    except Exception as e:
        try:
            page.screenshot(path=screenshot("FAIL"), full_page=False)
        except:
            pass
        fail(f"{key0}-{stage}", f"safari {run['vp']}", e)
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
    with open("pilot-slice3-results.json", "w", encoding="utf-8") as f:
        json.dump({"summary": summary, "results": results}, f, ensure_ascii=False, indent=1)
    print(json.dumps(summary, ensure_ascii=False))
    sys.exit(1 if failures else 0)

if __name__ == "__main__":
    main()
