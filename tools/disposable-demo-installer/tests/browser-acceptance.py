#!/usr/bin/env python3
"""Real-Chromium acceptance for the CPMS Disposable Demo Installer companion plugin.

Verifies all 20 browser acceptance criteria end-to-end in real Chromium:
  1. ZIP installation through wp-admin (both CPMS Classic and companion ZIP).
  2. Companion activation through wp-admin (no auto-provisioning on activate).
  3. Admin preflight UI (RTL, PASS checks).
  4. Browser-triggered provisioning (including wrong-phrase refusal).
  5. One-time credential display (and absence on subsequent GET reload).
  6. Password rotation (complete usernames with fixture_id, old password rejected,
     new passwords accepted, absence on subsequent GET reload).
  7. Six independent persona logins in separate BrowserContexts.
  8. Role-specific portal navigation.
  9. Authorization denials (patient -> staff, receptionist/accountant -> doctor,
     doctor -> wp-admin companion tool).
 10. Public booking through the published shortcode page.
 11. Patient OTP issued and verified through the actual companion Admin UI + Public Booking UI.
 12. Hold and confirmation with receipt reference code.
 13. Reception arrival check-in and queue enqueue.
 14. Doctor clinical workflow (call, start, notes, prescription, complete).
 15. Finance workflow (invoice issue, offline 'other' payment, reception checkout).
 16. RTL and mobile responsiveness at both 320px and 390px viewports.
 17. Browser-triggered read-only teardown diagnostics.
 18. Browser-triggered teardown (including wrong-phrase refusal and tombstone dismiss).
 19. Post-teardown database verification.
 20. Safe plugin deactivation and deletion through wp-admin.

Secrets (passwords and OTP codes) are held only in process memory and are never
printed, logged, or written to results.
"""

import json
import os
from pathlib import Path
import re
import subprocess
import sys
from urllib.parse import parse_qs, urlencode, urlparse

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
WP_DIR = os.environ.get("WP_DIR", "")
OUT = os.environ.get("OUT", "/tmp/cpdd-ci")
ADMIN_USER = os.environ.get("ADMIN_USER", "owner_admin")
ADMIN_PASS_FILE = os.environ.get("ADMIN_PASS_FILE", "")
CPMS_ZIP = os.environ.get("CPMS_ZIP", "")
COMPANION_ZIP = os.environ.get("COMPANION_ZIP", "")

PERSONAS = (
    "organization_manager",
    "clinic_manager",
    "doctor",
    "receptionist",
    "accountant",
    "patient",
)

RESULTS = []
STATE = {"stage": "init"}


def stage(name: str) -> None:
    STATE["stage"] = name
    print(f"STAGE {name}", flush=True)


def check(name: str, ok: bool, detail: str = "") -> None:
    safe_detail = str(detail)[:300]
    RESULTS.append({"check": name, "ok": bool(ok), "detail": safe_detail})
    prefix = "PASS " if ok else "FAIL "
    suffix = f" — {safe_detail}" if safe_detail else ""
    print(f"{prefix}{name}{suffix}", flush=True)
    if not ok:
        raise RuntimeError(f"Check failed: {name} ({safe_detail})")


def wp_eval(code: str, env_vars: dict | None = None) -> str:
    merged = os.environ.copy()
    if env_vars:
        merged.update(env_vars)
    proc = subprocess.run(
        ["wp", "eval", code, f"--path={WP_DIR}", "--allow-root"],
        capture_output=True,
        text=True,
        timeout=120,
        env=merged,
    )
    if proc.returncode != 0:
        raise RuntimeError(f"wp eval failed rc={proc.returncode}: {(proc.stderr or proc.stdout)[:300]}")
    return (proc.stdout or "").strip()


def login_wp(page, username: str, secret: str) -> bool:
    page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", username)
    page.fill("#user_pass", secret)
    page.click("#wp-submit")
    try:
        page.wait_for_url(lambda url: "wp-login.php" not in url, wait_until="domcontentloaded", timeout=20000)
    except PlaywrightTimeoutError:
        return False
    return "wp-login.php" not in page.url and "login_error" not in page.content()


def open_persona_context(browser, credentials: dict, key: str):
    context = browser.new_context(viewport={"width": 1280, "height": 900}, locale="fa-IR")
    check(f"fresh cookie jar for {key}", len(context.cookies()) == 0)
    page = context.new_page()
    page.set_default_timeout(25000)
    ok = login_wp(page, credentials[key]["username"], credentials[key]["secret"])
    check(f"persona login succeeded: {key}", ok, credentials[key]["username"])
    return context, page


def with_query(url: str, **params) -> str:
    return url + ("&" if "?" in url else "?") + urlencode(params)


def assert_rtl_and_no_overflow(page, label: str, widths=(320, 390)) -> None:
    for width in widths:
        page.set_viewport_size({"width": width, "height": 844})
        page.wait_for_timeout(200)
        metrics = page.evaluate(
            """() => {
                const root = document.querySelector('.cpdd, .cpms-public-booking, #cpms-patient-portal-shell, [data-cpms-org], body');
                const dir = root ? getComputedStyle(root).direction : getComputedStyle(document.documentElement).direction;
                return {
                    dir,
                    clientWidth: document.documentElement.clientWidth,
                    scrollWidth: document.documentElement.scrollWidth
                };
            }"""
        )
        check(
            f"{label} RTL + no horizontal overflow at {width}px",
            metrics["dir"] == "rtl" and metrics["scrollWidth"] <= metrics["clientWidth"] + 2,
            json.dumps(metrics),
        )


def rest_response(responses: list, path: str):
    for response in reversed(responses):
        parsed = urlparse(response.url)
        route = parse_qs(parsed.query).get("rest_route", [""])[0]
        if parsed.path.endswith("/wp-json/clinic/v1" + path) or route == "/clinic/v1" + path:
            return response
    return None


def install_zip_via_wp_admin(admin_page, zip_path: str, plugin_basename: str, label: str) -> None:
    check(f"{label} ZIP file exists", os.path.isfile(zip_path), os.path.basename(zip_path))
    admin_page.goto(f"{BASE}/wp-admin/plugin-install.php?tab=upload", wait_until="domcontentloaded")
    file_input = admin_page.locator("input#pluginzip")
    file_input.wait_for(state="attached")
    file_input.set_input_files(zip_path)
    submit_btn = admin_page.locator("input#install-plugin-submit")
    submit_btn.wait_for(state="visible")
    with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=60000):
        submit_btn.click()
    activate_link = admin_page.locator("a[href*='action=activate']").first
    activate_link.wait_for(state="visible", timeout=60000)
    with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=60000):
        activate_link.click()
    admin_page.goto(f"{BASE}/wp-admin/plugins.php", wait_until="domcontentloaded")
    check(
        f"{label} installed and activated through wp-admin",
        admin_page.locator(f'tr[data-plugin="{plugin_basename}"].active').count() == 1,
        plugin_basename,
    )


def extract_credentials_from_admin_dom(admin_page) -> dict:
    secret_card = admin_page.locator('[data-cpdd-secret="credentials"]')
    secret_card.wait_for(state="visible")
    creds = {}
    for persona in PERSONAS:
        row = secret_card.locator(f'tr[data-cpdd-persona="{persona}"]')
        check(f"credential row present for {persona}", row.count() == 1)
        username = (row.locator("[data-cpdd-login]").get_attribute("data-cpdd-login") or "").strip()
        secret_val = row.locator("textarea.cpdd-pass-field").input_value().strip()
        creds[persona] = {"username": username, "secret": secret_val}
    return creds


def main() -> None:
    os.makedirs(f"{OUT}/screenshots", exist_ok=True)
    if not ADMIN_PASS_FILE or not os.path.isfile(ADMIN_PASS_FILE):
        raise RuntimeError("ADMIN_PASS_FILE is required")
    with open(ADMIN_PASS_FILE, encoding="utf-8") as handle:
        admin_secret = handle.read().strip()

    contexts = []
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True, args=["--no-sandbox"])
        try:
            # 1 & 2. ZIP installation through wp-admin & companion activation
            stage("1-2. ZIP installation and activation through wp-admin")
            admin_context = browser.new_context(viewport={"width": 1280, "height": 900}, locale="fa-IR")
            contexts.append(admin_context)
            admin_page = admin_context.new_page()
            admin_page.set_default_timeout(30000)
            check("admin login via wp-login.php", login_wp(admin_page, ADMIN_USER, admin_secret))

            install_zip_via_wp_admin(
                admin_page,
                CPMS_ZIP,
                "clinic-practice-management/clinic-practice-management.php",
                "CPMS Classic production plugin",
            )
            install_zip_via_wp_admin(
                admin_page,
                COMPANION_ZIP,
                "cpms-disposable-demo/cpms-disposable-demo.php",
                "CPMS Disposable Demo companion plugin",
            )

            db_boot = json.loads(
                wp_eval(
                    r"""
                    $db = \ClinicCore\Bootstrap\App::db();
                    echo wp_json_encode([
                        'orgs' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_organizations')),
                        'clinics' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics')),
                        'manifest' => get_option('cpdd_manifest', null) !== null,
                    ]);
                    """
                )
            )
            check(
                "companion activation created zero synthetic fixtures automatically",
                db_boot["orgs"] == 1 and db_boot["clinics"] == 1 and not db_boot["manifest"],
                json.dumps(db_boot),
            )

            # 3. Admin preflight UI
            stage("3. Admin preflight UI")
            tool_url = f"{BASE}/wp-admin/tools.php?page=cpms-disposable-demo"
            admin_page.goto(tool_url, wait_until="domcontentloaded")
            check("companion admin page renders in RTL", admin_page.locator('.wrap.cpdd[dir="rtl"]').count() == 1)
            preflight_fails = admin_page.locator('[data-cpdd-preflight] tr:has(td.cpdd-fail)').all_inner_texts()
            check("admin preflight status is PASS", admin_page.locator('[data-cpdd-preflight="pass"]').count() == 1, " | ".join(preflight_fails))

            # 4 & 5. Browser-triggered provisioning + one-time credential display
            stage("4-5. Browser-triggered provisioning and one-time credential display")
            prov_form = admin_page.locator('form[data-cpdd-form="provision"]')
            check("provisioning form visible", prov_form.count() == 1)
            prov_form.locator('input[name="cpdd_confirm"]').fill("WRONG_PHRASE")
            prov_form.locator('button[type="submit"]').click()
            admin_page.wait_for_load_state("domcontentloaded")
            check("provisioning with wrong phrase refused in browser", admin_page.locator(".notice-error").count() == 1 and admin_page.locator('[data-cpdd-secret="credentials"]').count() == 0)

            prov_form = admin_page.locator('form[data-cpdd-form="provision"]')
            prov_form.locator('input[name="cpdd_confirm"]').fill("I_UNDERSTAND_THIS_IS_A_DISPOSABLE_EMPTY_SITE")
            prov_form.locator('button[type="submit"]').click()
            admin_page.wait_for_load_state("domcontentloaded", timeout=60000)

            fixture_id = (admin_page.locator("[data-cpdd-fixture-id]").get_attribute("data-cpdd-fixture-id") or "").strip()
            check("fixture_id displayed on admin page", bool(re.fullmatch(r"[a-f0-9]{16}", fixture_id)), fixture_id)

            initial_creds = extract_credentials_from_admin_dom(admin_page)
            check(
                "six unique CSPRNG secrets displayed once on provision",
                len({ initial_creds[p]["secret"] for p in PERSONAS }) == 6
                and all(re.fullmatch(r"[a-f0-9]{48}", initial_creds[p]["secret"]) for p in PERSONAS)
                and all(initial_creds[p]["username"] == f"cpdd-{p}-{fixture_id}" for p in PERSONAS),
            )

            # Reload GET: secrets must not appear anywhere in DOM
            admin_page.goto(tool_url, wait_until="domcontentloaded")
            html_after_get = admin_page.content()
            check(
                "one-time credentials absent on subsequent GET reload",
                admin_page.locator('[data-cpdd-secret="credentials"]').count() == 0
                and all(initial_creds[p]["secret"] not in html_after_get for p in PERSONAS),
            )

            # 6. Password rotation (S-6)
            stage("6. Browser-triggered credential rotation (S-6)")
            rotate_form = admin_page.locator('form[data-cpdd-form="rotate"]')
            rotate_form.wait_for(state="visible")
            rotate_form.locator('input[name="cpdd_confirm"]').fill("ROTATE_DEMO_PASSWORDS")
            rotate_form.locator('button[type="submit"]').click()
            admin_page.wait_for_load_state("domcontentloaded")

            rotated_creds = extract_credentials_from_admin_dom(admin_page)
            check(
                "S-6: rotation displays complete usernames with verified fixture_id",
                all(rotated_creds[p]["username"] == f"cpdd-{p}-{fixture_id}" for p in PERSONAS),
                json.dumps({p: rotated_creds[p]["username"] for p in PERSONAS}),
            )
            check(
                "rotation generated six new CSPRNG secrets distinct from initial secrets",
                len({ rotated_creds[p]["secret"] for p in PERSONAS }) == 6
                and all(re.fullmatch(r"[a-f0-9]{48}", rotated_creds[p]["secret"]) for p in PERSONAS)
                and all(rotated_creds[p]["secret"] != initial_creds[p]["secret"] for p in PERSONAS),
            )

            # Verify old secret no longer logs in
            old_ctx = browser.new_context(viewport={"width": 1280, "height": 900}, locale="fa-IR")
            old_page = old_ctx.new_page()
            check("previous doctor secret rejected after rotation", not login_wp(old_page, rotated_creds["doctor"]["username"], initial_creds["doctor"]["secret"]))
            old_ctx.close()

            # Reload GET after rotation: rotated secrets must not appear in DOM
            admin_page.goto(tool_url, wait_until="domcontentloaded")
            html_after_rot_get = admin_page.content()
            check(
                "rotated secrets absent on subsequent GET reload",
                admin_page.locator('[data-cpdd-secret="credentials"]').count() == 0
                and all(rotated_creds[p]["secret"] not in html_after_rot_get for p in PERSONAS),
            )

            links = {}
            for key in (*PERSONAS, "booking"):
                loc = admin_page.locator(f'a[data-cpdd-link="{key}"]')
                check(f"admin link rendered for {key}", loc.count() == 1)
                links[key] = loc.get_attribute("href") or ""

            fixture_meta = json.loads(
                wp_eval(
                    r"""
                    $m = get_option('cpdd_manifest');
                    $db = \ClinicCore\Bootstrap\App::db();
                    $doc = $db->fetchRow('SELECT full_name FROM ' . $db->table('cpms_clinicians') . ' WHERE id = %d', [(int) $m['ids']['clinician_id']]);
                    echo wp_json_encode([
                        'fixture_id' => (string) $m['fixture_id'],
                        'organization_id' => (int) $m['ids']['organization_id'],
                        'clinic_id' => (int) $m['ids']['clinic_id'],
                        'location_id' => (int) $m['ids']['location_ids'][0],
                        'clinician_id' => (int) $m['ids']['clinician_id'],
                        'clinician_name' => (string) ($doc['full_name'] ?? ''),
                        'patient_mobile' => (string) $m['patient_mobile'],
                    ]);
                    """
                )
            )

            # 7, 8, 9. Six independent persona logins, role-specific portals, and authorization denials
            stage("7-9. Six independent persona logins, portals, and authorization denials")
            for persona in PERSONAS:
                p_ctx, p_page = open_persona_context(browser, rotated_creds, persona)
                contexts.append(p_ctx)
                p_page.goto(links[persona], wait_until="domcontentloaded")
                p_page.wait_for_timeout(600)
                if persona == "organization_manager":
                    check("organization_manager portal scope rendered", p_page.locator(f'[data-cpms-org="{fixture_meta["organization_id"]}"]').count() == 1)
                elif persona == "clinic_manager":
                    check("clinic_manager portal scope rendered", p_page.locator(f'[data-cpms-clinic="{fixture_meta["clinic_id"]}"]').count() >= 1)
                elif persona == "doctor":
                    check("doctor portal queue rendered", p_page.locator('[data-role="queue-list"]').count() == 1)
                    # Verify doctor is denied access to the wp-admin companion tool
                    p_page.goto(tool_url, wait_until="domcontentloaded")
                    check("non-admin persona denied wp-admin companion page", p_page.locator('form[data-cpdd-form="teardown"]').count() == 0)
                elif persona == "receptionist":
                    check("receptionist portal rendered", p_page.locator('[data-role="reception-app"]').count() == 1)
                    p_page.goto(f"{BASE}/cpms-doctor-portal/", wait_until="domcontentloaded")
                    check("receptionist denied doctor portal", p_page.locator('[data-role="portal-access-denied"]').count() == 1)
                elif persona == "accountant":
                    check("accountant finance portal rendered", p_page.locator('[data-role="finance-root"]').count() == 1)
                    p_page.goto(f"{BASE}/cpms-doctor-portal/", wait_until="domcontentloaded")
                    check("accountant denied doctor portal", p_page.locator('[data-role="portal-access-denied"]').count() == 1)
                elif persona == "patient":
                    check("patient portal rendered", p_page.locator('#cpms-patient-portal-shell[data-shell-user="patient"]').count() == 1)
                    p_page.goto(links["receptionist"], wait_until="domcontentloaded")
                    check("patient denied staff portal", p_page.locator('[data-role="portal-access-denied"]').count() == 1)
                p_ctx.close()
                contexts.remove(p_ctx)

            # 10, 11, 12. Public booking + Patient OTP through the actual Admin UI + Hold & Confirm
            stage("10-12. Public booking, UI OTP issuance/verification, hold, and confirmation")
            booking_ctx = browser.new_context(viewport={"width": 390, "height": 844}, locale="fa-IR")
            contexts.append(booking_ctx)
            booking_page = booking_ctx.new_page()
            booking_page.set_default_timeout(25000)
            responses = []
            booking_page.on("response", lambda r: responses.append(r))
            booking_page.goto(links["booking"], wait_until="domcontentloaded")
            root = booking_page.locator(".cpms-public-booking")
            root.wait_for()
            check("public booking shortcode rendered", root.count() == 1)

            clinician_btn = root.locator("button.cpms-public-booking__clinician", has_text=fixture_meta["clinician_name"]).first
            clinician_btn.wait_for(state="visible")
            clinician_btn.click()
            slot_btn = root.locator(".cpms-public-booking__slot:not([disabled])").first
            slot_btn.wait_for(state="visible", timeout=30000)
            slot_btn.click()
            root.locator('[data-role="panel"][data-state="bookable"][aria-busy="false"]').wait_for(state="visible", timeout=30000)
            root.locator('[data-role="continue-auth"]').click()
            mobile_input = root.locator('[data-role="otp-mobile"]')
            mobile_input.wait_for(state="visible")
            mobile_input.fill(fixture_meta["patient_mobile"])
            root.locator('[data-auth-action="otp-request"]').click()
            root.locator('[data-role="otp-code"]').wait_for(state="visible")
            otp_req_resp = rest_response(responses, "/otp/request")
            check("public booking OTP request HTTP 200", otp_req_resp is not None and otp_req_resp.status == 200)

            # Issue one-time demo OTP through the actual companion Admin UI!
            admin_page.goto(tool_url, wait_until="domcontentloaded")
            otp_form = admin_page.locator('form[data-cpdd-form="otp"]')
            otp_form.wait_for(state="visible")
            otp_form.locator('input[name="cpdd_confirm"]').fill("SHOW_DEMO_OTP")
            otp_form.locator('button[type="submit"]').click()
            admin_page.wait_for_load_state("domcontentloaded")
            otp_card = admin_page.locator('[data-cpdd-secret="otp"]')
            otp_card.wait_for(state="visible")
            otp_code = otp_card.locator(".cpdd-otp").inner_text().strip()
            check("6-digit OTP displayed once in companion Admin UI", bool(re.fullmatch(r"\d{6}", otp_code)))

            # Verify OTP disappears on Admin GET reload
            admin_page.goto(tool_url, wait_until="domcontentloaded")
            check("one-time OTP absent on subsequent Admin GET reload", admin_page.locator('[data-cpdd-secret="otp"]').count() == 0 and otp_code not in admin_page.content())

            # Submit OTP in public booking UI
            root.locator('[data-role="otp-code"]').fill(otp_code)
            otp_code = ""
            root.locator('[data-auth-action="otp-verify"]').click()
            booking_page.wait_for_load_state("domcontentloaded", timeout=30000)
            root = booking_page.locator(".cpms-public-booking")
            confirm_btn = root.locator('[data-role="confirm-btn"]')
            confirm_btn.wait_for(state="visible", timeout=35000)
            verify_resp = rest_response(responses, "/otp/verify")
            hold_resp = rest_response(responses, "/booking/hold")
            check("OTP verify and automatic slot hold HTTP 200", verify_resp is not None and verify_resp.status == 200 and hold_resp is not None and hold_resp.status == 200)
            confirm_btn.click()
            receipt = root.locator('[data-role="receipt"]')
            receipt.wait_for(state="visible", timeout=30000)
            reference_code = receipt.locator('[data-role="reference-code"]').inner_text().strip()
            confirm_resp = rest_response(responses, "/booking/confirm")
            check("public booking confirmed with reference code", bool(reference_code) and confirm_resp is not None and confirm_resp.status == 200, reference_code)

            # 16. RTL and mobile responsiveness at 320px and 390px
            stage("16. RTL and mobile responsiveness at 320px and 390px")
            assert_rtl_and_no_overflow(booking_page, "Public booking page")
            booking_page.goto(links["patient"], wait_until="domcontentloaded")
            booking_page.wait_for_timeout(800)
            assert_rtl_and_no_overflow(booking_page, "Patient portal")
            booking_ctx.close()
            contexts.remove(booking_ctx)

            assert_rtl_and_no_overflow(admin_page, "Companion wp-admin page")
            admin_page.set_viewport_size({"width": 1280, "height": 900})

            # 13. Reception workflow
            stage("13. Reception arrival check-in and queue enqueue")
            appointment_id = int(
                wp_eval(
                    r"""
                    $db = \ClinicCore\Bootstrap\App::db();
                    echo (int) $db->fetchValue('SELECT id FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d AND reference_code = %s LIMIT 1', [(int) getenv('CID'), (string) getenv('REF')]);
                    """,
                    {"CID": str(fixture_meta["clinic_id"]), "REF": reference_code},
                )
            )
            check("booked appointment persisted in DB", appointment_id > 0, str(appointment_id))

            rec_ctx, rec_page = open_persona_context(browser, rotated_creds, "receptionist")
            contexts.append(rec_ctx)
            rec_page.goto(links["receptionist"], wait_until="domcontentloaded")
            c_sel = rec_page.locator('[data-role="sr-clinic-select"]')
            c_sel.wait_for(state="visible")
            rec_page.locator(f'[data-role="sr-clinic-select"] option[value="{fixture_meta["clinic_id"]}"]').wait_for(state="attached")
            if c_sel.input_value() != str(fixture_meta["clinic_id"]):
                c_sel.select_option(str(fixture_meta["clinic_id"]))
            l_sel = rec_page.locator('[data-role="sr-location-select"]')
            rec_page.locator(f'[data-role="sr-location-select"] option[value="{fixture_meta["location_id"]}"]').wait_for(state="attached", timeout=30000)
            l_sel.select_option(str(fixture_meta["location_id"]))
            arrive_btn = rec_page.locator(f'[data-role="sr-arrive"][data-appointment-id="{appointment_id}"]')
            arrive_btn.wait_for(state="visible", timeout=35000)
            arrive_btn.click()
            arrive_btn.wait_for(state="detached", timeout=35000)
            rec_ctx.close()
            contexts.remove(rec_ctx)

            visit_id = int(
                wp_eval(
                    r"""
                    $db = \ClinicCore\Bootstrap\App::db();
                    echo (int) $db->fetchValue("SELECT id FROM " . $db->table('cpms_visits') . " WHERE clinic_id = %d AND appointment_id = %d AND status = 'waiting' ORDER BY id DESC LIMIT 1", [(int) getenv('CID'), (int) getenv('AID')]);
                    """,
                    {"CID": str(fixture_meta["clinic_id"]), "AID": str(appointment_id)},
                )
            )
            check("reception check-in created waiting visit in DB", visit_id > 0, str(visit_id))

            # 14. Doctor clinical workflow
            stage("14. Doctor clinical workflow")
            doc_ctx, doc_page = open_persona_context(browser, rotated_creds, "doctor")
            contexts.append(doc_ctx)
            doc_page.on("dialog", lambda d: d.accept() if d.type == "confirm" else d.dismiss())
            doc_page.goto(links["doctor"], wait_until="domcontentloaded")
            dc_sel = doc_page.locator('[data-role="clinic-select"]')
            dc_sel.wait_for(state="visible")
            doc_page.locator(f'[data-role="clinic-select"] option[value="{fixture_meta["clinic_id"]}"]').wait_for(state="attached")
            if dc_sel.input_value() != str(fixture_meta["clinic_id"]):
                dc_sel.select_option(str(fixture_meta["clinic_id"]))
            dl_sel = doc_page.locator('[data-role="location-select"]')
            doc_page.locator(f'[data-role="location-select"] option[value="{fixture_meta["location_id"]}"]').wait_for(state="attached", timeout=30000)
            dl_sel.select_option(str(fixture_meta["location_id"]))

            q_item = f'[data-role="queue-item"][data-visit-id="{visit_id}"]'
            doc_page.locator(q_item).wait_for(state="visible", timeout=35000)
            doc_page.locator(f'{q_item} [data-action="call"]').click()
            doc_page.locator(f'{q_item}[data-status="called"]').wait_for(state="visible", timeout=30000)
            doc_page.locator(f'{q_item} [data-action="start"]').click()
            doc_page.locator(f'{q_item}[data-status="in_consultation"]').wait_for(state="visible", timeout=30000)
            doc_page.locator(q_item).click()
            doc_page.locator('[data-role="workspace-body"]').wait_for(state="visible", timeout=30000)

            doc_page.fill('[data-role="workspace-content"]', "یادداشت بالینی ساختگی در مرورگر")
            doc_page.locator('[data-role="workspace-note-form"]').evaluate("f => f.requestSubmit()")
            doc_page.locator('[data-role="workspace-form-success"]').wait_for(state="visible")
            doc_page.fill('[data-role="workspace-cc-text"]', "شکایت اصلی ساختگی در مرورگر")
            doc_page.locator('[data-role="workspace-cc-form"]').evaluate("f => f.requestSubmit()")
            doc_page.locator('[data-role="workspace-cc-success"]').wait_for(state="visible")

            doc_page.fill('[data-role="workspace-rx-generic-name"]', "SYNTHETIC-BROWSER-DRUG")
            doc_page.fill('[data-role="workspace-rx-dose"]', "0 demo-unit")
            doc_page.fill('[data-role="workspace-rx-frequency"]', "test only")
            doc_page.fill('[data-role="workspace-rx-duration-days"]', "1")
            doc_page.fill('[data-role="workspace-rx-instructions"]', "نسخه آزمایشی مرورگر")
            doc_page.locator('[data-role="workspace-rx-form"]').evaluate("f => f.requestSubmit()")
            finalize_btn = doc_page.locator('[data-role="workspace-rx-finalize"]').first
            finalize_btn.wait_for(state="visible", timeout=30000)
            finalize_btn.click()
            doc_page.locator('[data-role="workspace-rx-item"][data-status="finalized"]').wait_for(state="visible", timeout=30000)
            doc_page.locator('[data-role="workspace-consult-complete-submit"]').click()
            doc_page.locator('[data-role="workspace-consult-complete-success"]').wait_for(state="visible", timeout=30000)
            check("doctor clinical workflow completed in browser", True)
            doc_ctx.close()
            contexts.remove(doc_ctx)

            # 15. Finance workflow
            stage("15. Finance workflow (invoice, payment, checkout)")
            acc_ctx, acc_page = open_persona_context(browser, rotated_creds, "accountant")
            contexts.append(acc_ctx)
            acc_page.goto(links["accountant"], wait_until="domcontentloaded")
            acc_page.locator('[data-role="finance-root"]').wait_for(state="visible")
            issue_btn = acc_page.locator(f'[data-role="finance-issue-open"][data-visit-id="{visit_id}"]')
            issue_btn.wait_for(state="visible", timeout=35000)
            issue_btn.click()
            acc_page.fill('[data-role="finance-issue-description"]', "ویزیت آزمایشی مرورگر")
            acc_page.fill('[data-role="finance-issue-quantity"]', "1")
            acc_page.fill('[data-role="finance-issue-unit-price"]', "1000")
            acc_page.locator('[data-role="finance-issue-form"]').evaluate("f => f.requestSubmit()")
            acc_page.locator('[data-role="finance-pay-open"]').first.wait_for(state="visible", timeout=35000)

            invoice_id = int(
                wp_eval(
                    r"""
                    $db = \ClinicCore\Bootstrap\App::db();
                    echo (int) $db->fetchValue('SELECT id FROM ' . $db->table('cpms_invoices') . ' WHERE visit_id = %d ORDER BY id DESC LIMIT 1', [(int) getenv('VID')]);
                    """,
                    {"VID": str(visit_id)},
                )
            )
            check("browser-issued invoice persisted in DB", invoice_id > 0, str(invoice_id))
            pay_btn = acc_page.locator(f'[data-role="finance-pay-open"][data-invoice-id="{invoice_id}"]')
            pay_btn.wait_for(state="visible", timeout=35000)
            pay_btn.click()
            acc_page.fill('[data-role="finance-pay-amount"]', "1000")
            acc_page.select_option('[data-role="finance-pay-method"]', "other")
            acc_page.fill('[data-role="finance-pay-ref"]', "SYNTHETIC-BROWSER-PAYMENT")
            acc_page.locator('[data-role="finance-pay-form"]').evaluate("f => f.requestSubmit()")
            acc_page.locator(f'[data-role="finance-paid-rows"] [data-visit-id="{visit_id}"]').wait_for(state="attached", timeout=35000)
            acc_ctx.close()
            contexts.remove(acc_ctx)

            rec2_ctx, rec2_page = open_persona_context(browser, rotated_creds, "receptionist")
            contexts.append(rec2_ctx)
            rec2_page.goto(links["accountant"], wait_until="domcontentloaded")
            rec2_page.locator('[data-role="finance-root"]').wait_for(state="visible")
            checkout_btn = rec2_page.locator(f'[data-role="finance-checkout-open"][data-visit-id="{visit_id}"]')
            checkout_btn.wait_for(state="visible", timeout=35000)
            checkout_btn.click()
            rec2_page.locator('[data-role="finance-checkout-form"]').evaluate("f => f.requestSubmit()")
            rec2_page.locator(f'[data-role="finance-checkout-open"][data-visit-id="{visit_id}"]').wait_for(state="detached", timeout=35000)
            rec2_ctx.close()
            contexts.remove(rec2_ctx)

            journey_db = json.loads(
                wp_eval(
                    r"""
                    $id = (int) getenv('VID');
                    $db = \ClinicCore\Bootstrap\App::db();
                    echo wp_json_encode([
                        'visit' => $db->fetchValue('SELECT status FROM ' . $db->table('cpms_visits') . ' WHERE id = %d', [$id]),
                        'notes' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinical_notes') . ' WHERE visit_id = %d', [$id]),
                        'rx' => $db->fetchValue('SELECT status FROM ' . $db->table('cpms_prescriptions') . ' WHERE visit_id = %d ORDER BY id DESC LIMIT 1', [$id]),
                        'invoice' => $db->fetchValue('SELECT status FROM ' . $db->table('cpms_invoices') . ' WHERE visit_id = %d ORDER BY id DESC LIMIT 1', [$id]),
                        'payments' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_payments') . ' p INNER JOIN ' . $db->table('cpms_invoices') . ' i ON i.id = p.invoice_id WHERE i.visit_id = %d', [$id]),
                    ]);
                    """,
                    {"VID": str(visit_id)},
                )
            )
            check(
                "server-side DB confirms complete reception -> doctor -> finance -> checkout workflow",
                journey_db["visit"] == "checked_out"
                and journey_db["notes"] >= 2
                and journey_db["rx"] == "finalized"
                and journey_db["invoice"] == "paid"
                and journey_db["payments"] >= 1,
                json.dumps(journey_db),
            )

            # Also check management portal RTL at 320px and 390px
            mgr_ctx, mgr_page = open_persona_context(browser, rotated_creds, "organization_manager")
            contexts.append(mgr_ctx)
            mgr_page.goto(links["organization_manager"], wait_until="domcontentloaded")
            mgr_page.wait_for_timeout(800)
            assert_rtl_and_no_overflow(mgr_page, "Management portal")
            mgr_ctx.close()
            contexts.remove(mgr_ctx)

            # 17, 18, 19. Browser-triggered teardown diagnostics, teardown, and post-teardown DB verification
            stage("17-19. Browser-triggered diagnostics, teardown, and post-teardown DB verification")
            admin_page.goto(tool_url, wait_until="domcontentloaded")
            diag_form = admin_page.locator('form[data-cpdd-form="diagnose"]')
            diag_form.locator('input[name="cpdd_confirm"]').fill("RUN_READ_ONLY_DIAGNOSTIC")
            diag_form.locator('button[type="submit"]').click()
            admin_page.wait_for_load_state("domcontentloaded")
            check("browser-triggered read-only diagnostics PASS", admin_page.locator('[data-cpdd-diagnostic="pass"]').count() == 1)

            td_form = admin_page.locator('form[data-cpdd-form="teardown"]')
            td_form.locator('input[name="cpdd_confirm"]').fill("WRONG_PHRASE")
            td_form.locator('button[type="submit"]').click()
            admin_page.wait_for_load_state("domcontentloaded")
            check("teardown with wrong phrase refused in browser", admin_page.locator(".notice-error").count() == 1)

            td_form = admin_page.locator('form[data-cpdd-form="teardown"]')
            td_form.locator('input[name="cpdd_confirm"]').fill("DESTROY_DEMO_DATA_ONLY")
            td_form.locator('button[type="submit"]').click()
            admin_page.wait_for_load_state("domcontentloaded", timeout=60000)
            check("browser-triggered teardown succeeded cleanly", admin_page.locator('[data-cpdd-teardown="clean"]').count() == 1)
            check("tombstone card rendered after teardown", admin_page.locator('[data-cpdd-tombstone="clean"]').count() == 1)
            check("preflight is PASS again after browser teardown", admin_page.locator('[data-cpdd-preflight="pass"]').count() == 1)

            dismiss_form = admin_page.locator('form[data-cpdd-form="dismiss_tombstone"]')
            dismiss_form.locator('input[name="cpdd_confirm"]').fill("CLEAR_TEARDOWN_RECORD")
            dismiss_form.locator('button[type="submit"]').click()
            admin_page.wait_for_load_state("domcontentloaded")
            check("tombstone record dismissed via browser", admin_page.locator('[data-cpdd-tombstone]').count() == 0)

            post_td_db = json.loads(
                wp_eval(
                    r"""
                    $db = \ClinicCore\Bootstrap\App::db();
                    echo wp_json_encode([
                        'orgs' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_organizations')),
                        'clinics' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics')),
                        'patients' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_patients')),
                        'visits' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_visits')),
                        'invoices' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_invoices')),
                        'payments' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_payments')),
                        'audit_logs' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_audit_logs')),
                        'entity_rate_limits' => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table('cpms_rate_limits') . " WHERE NOT (window_key LIKE 'otp-ip:%' OR window_key LIKE 'otp-verify-ip:%' OR window_key LIKE 'login-ip:%')"),
                        'shared_ip_rate_limits' => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table('cpms_rate_limits') . " WHERE (window_key LIKE 'otp-ip:%' OR window_key LIKE 'otp-verify-ip:%' OR window_key LIKE 'login-ip:%')"),
                        'users' => count(get_users()),
                        'manifest' => get_option('cpdd_manifest', null) !== null,
                        'tombstone' => get_option('cpdd_tombstone', null) !== null,
                    ]);
                    """
                )
            )
            check(
                "post-teardown DB verification confirms zero residual demo entity data, preserved shared-IP counters, and intact baseline",
                post_td_db["orgs"] == 1
                and post_td_db["clinics"] == 1
                and post_td_db["patients"] == 0
                and post_td_db["visits"] == 0
                and post_td_db["invoices"] == 0
                and post_td_db["payments"] == 0
                and post_td_db["audit_logs"] == 0
                and post_td_db["entity_rate_limits"] == 0
                and post_td_db["shared_ip_rate_limits"] >= 1
                and post_td_db["users"] == 1
                and post_td_db["manifest"] is False
                and post_td_db["tombstone"] is False,
                json.dumps(post_td_db),
            )

            # 19b. Browser-triggered reprovisioning and second teardown
            stage("19b. Browser-triggered reprovisioning and second teardown")
            prov_form_2 = admin_page.locator('form[data-cpdd-form="provision"]')
            prov_form_2.locator('input[name="cpdd_confirm"]').fill("I_UNDERSTAND_THIS_IS_A_DISPOSABLE_EMPTY_SITE")
            prov_form_2.locator('button[type="submit"]').click()
            admin_page.wait_for_load_state("domcontentloaded", timeout=60000)
            check("browser-triggered reprovisioning renders new one-time credentials block", admin_page.locator('[data-cpdd-secret="credentials"]').count() == 1)

            second_fixture_id = wp_eval(
                r"""
                $m = get_option('cpdd_manifest', []);
                echo (string) ($m['fixture_id'] ?? '');
                """
            )
            check(
                "reprovisioned demo has a fresh fixture_id distinct from the first run",
                len(second_fixture_id) == 16 and second_fixture_id != str(fixture_meta["fixture_id"]),
                f"{second_fixture_id} vs {fixture_meta['fixture_id']}",
            )

            td_form_2 = admin_page.locator('form[data-cpdd-form="teardown"]')
            td_form_2.locator('input[name="cpdd_confirm"]').fill("DESTROY_DEMO_DATA_ONLY")
            td_form_2.locator('button[type="submit"]').click()
            admin_page.wait_for_load_state("domcontentloaded", timeout=60000)
            check("second browser-triggered teardown succeeded cleanly", admin_page.locator('[data-cpdd-teardown="clean"]').count() == 1)
            check("preflight is PASS again after second browser teardown", admin_page.locator('[data-cpdd-preflight="pass"]').count() == 1)

            dismiss_form_2 = admin_page.locator('form[data-cpdd-form="dismiss_tombstone"]')
            dismiss_form_2.locator('input[name="cpdd_confirm"]').fill("CLEAR_TEARDOWN_RECORD")
            dismiss_form_2.locator('button[type="submit"]').click()
            admin_page.wait_for_load_state("domcontentloaded")
            check("second tombstone record dismissed via browser", admin_page.locator('[data-cpdd-tombstone]').count() == 0)

            # 20. Safe plugin removal through wp-admin
            stage("20. Safe plugin deactivation and removal through wp-admin")
            admin_page.on("dialog", lambda d: d.accept())
            admin_page.goto(f"{BASE}/wp-admin/plugins.php", wait_until="domcontentloaded")
            companion_row = admin_page.locator('tr[data-plugin="cpms-disposable-demo/cpms-disposable-demo.php"]')
            deact = companion_row.locator("a[href*='action=deactivate']").first
            deact.wait_for(state="visible")
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=30000):
                deact.click()
            companion_row = admin_page.locator('tr[data-plugin="cpms-disposable-demo/cpms-disposable-demo.php"]')
            delete_link = companion_row.locator(".delete a, a[href*='action=delete-selected']").first
            delete_link.wait_for(state="visible")
            delete_link.click()
            for _ in range(40):
                admin_page.wait_for_timeout(500)
                if admin_page.locator("input#submit").count() > 0:
                    admin_page.locator("input#submit").click()
                    admin_page.wait_for_load_state("domcontentloaded")
                if not os.path.exists(os.path.join(WP_DIR, "wp-content/plugins/cpms-disposable-demo/cpms-disposable-demo.php")):
                    break

            removed_state = json.loads(
                wp_eval(
                    r"""
                    echo wp_json_encode([
                        'companion_exists' => file_exists(WP_PLUGIN_DIR . '/cpms-disposable-demo/cpms-disposable-demo.php'),
                        'cpms_active' => class_exists(\ClinicCore\Bootstrap\App::class),
                        'manifest' => get_option('cpdd_manifest', null) !== null,
                        'tombstone' => get_option('cpdd_tombstone', null) !== null,
                        'blocked' => get_option('cpdd_uninstall_blocked', null) !== null,
                    ]);
                    """
                )
            )
            check(
                "companion plugin safely deactivated and deleted via wp-admin while CPMS Classic stays active",
                not removed_state["companion_exists"]
                and removed_state["cpms_active"]
                and not removed_state["manifest"]
                and not removed_state["tombstone"]
                and not removed_state["blocked"],
                json.dumps(removed_state),
            )

            stage("complete")
        finally:
            for ctx in contexts:
                try:
                    ctx.close()
                except Exception:
                    pass
            browser.close()

    with open(f"{OUT}/browser-results.json", "w", encoding="utf-8") as handle:
        json.dump({"stage": STATE["stage"], "checks": RESULTS}, handle, ensure_ascii=False, indent=2)
    print(f"CPDD_BROWSER_ACCEPTANCE=PASS checks={len(RESULTS)}", flush=True)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"ERROR: {type(exc).__name__}: {str(exc)[:400]}", flush=True)
        sys.exit(1)
