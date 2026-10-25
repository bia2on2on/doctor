#!/usr/bin/env python3
"""Real-Chromium acceptance for the optional CPMS temporary demonstration.

The script uses the Administrator UI, delivered Classic shortcodes/portals and
production REST calls made by those pages. Generated passwords and OTP values
are kept only in process memory: they are never printed, written to results, or
captured in screenshots. WP-CLI is used only for non-secret fixture identifiers
and final database assertions, never to bypass the browser journey under test.
"""

import json
import os
import subprocess
import sys
from urllib.parse import urlencode

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
WP_DIR = os.environ.get("WP_DIR", "")
OUT = os.environ.get("OUT", "/tmp/acc")
ADMIN_USER = os.environ.get("ADMIN_USER", "")
ADMIN_PASS = os.environ.get("ADMIN_PASS", "")
MOBILE = "09000000000"
ACCOUNTS = (
    "organization_manager",
    "clinic_manager",
    "doctor",
    "receptionist",
    "accountant",
    "patient",
)
RESULTS = []
STATE = {"stage": "start", "url": ""}
LAST_FIXTURE = None


def stage(name):
    STATE["stage"] = name
    print("STAGE " + name, flush=True)


def check(name, ok, detail=""):
    # Details are deliberately non-secret. Never pass DOM/page bodies here.
    RESULTS.append({"check": name, "ok": bool(ok), "detail": str(detail)[:400]})
    print(("PASS " if ok else "FAIL ") + name + (" — " + str(detail)[:400] if detail else ""), flush=True)


def dump_results(fixture=None):
    fixture = fixture or LAST_FIXTURE
    os.makedirs(f"{OUT}/logs", exist_ok=True)
    safe_fixture = None
    if fixture:
        safe_fixture = {k: fixture.get(k) for k in (
            "demo_id", "clinic_id", "active_visit_id", "completed_visit_id", "booking_page_id"
        )}
    payload = {
        "fixture": safe_fixture,
        "checks": RESULTS,
        "stage": STATE["stage"],
        "secrets_persisted": False,
    }
    with open(f"{OUT}/logs/demo-results.json", "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
    print("DEMO_RESULTS=" + json.dumps({
        "stage": STATE["stage"],
        "checks": len(RESULTS),
        "failed": [row["check"] for row in RESULTS if not row["ok"]],
    }, ensure_ascii=False), flush=True)


def wp(*args, stdin_text=None):
    if not WP_DIR:
        raise RuntimeError("WP_DIR is required")
    proc = subprocess.run(
        ["wp", *args, f"--path={WP_DIR}", "--allow-root"],
        capture_output=True, text=True, timeout=180, input=stdin_text,
    )
    if proc.returncode != 0:
        raise RuntimeError(f"WP-CLI {args[0]} failed rc={proc.returncode}: {(proc.stderr or proc.stdout)[:300]}")
    return (proc.stdout or "").strip()


def wp_eval(code, env=None):
    merged = os.environ.copy()
    if env:
        merged.update(env)
    proc = subprocess.run(
        ["wp", "eval", code, f"--path={WP_DIR}", "--allow-root"],
        capture_output=True, text=True, timeout=180, env=merged,
    )
    if proc.returncode != 0:
        raise RuntimeError(f"WP-CLI eval failed rc={proc.returncode}: {(proc.stderr or proc.stdout)[:300]}")
    return (proc.stdout or "").strip()


def fixture_from_db():
    output = wp_eval(r'''
$s = get_option(\ClinicCore\Application\Demo\DemoService::STATE_OPTION, []);
echo "DEMO_FIXTURE=" . wp_json_encode([
    "demo_id" => (string) ($s["demo_id"] ?? ""),
    "status" => (string) ($s["status"] ?? ""),
    "clinic_id" => (int) ($s["clinic_id"] ?? 0),
    "active_visit_id" => (int) ($s["active_visit_id"] ?? 0),
    "completed_visit_id" => (int) ($s["completed_visit_id"] ?? 0),
    "booking_page_id" => (int) ($s["booking_page_id"] ?? 0),
    "user_ids" => array_map(static fn($row) => (int) $row["id"], array_values($s["users"] ?? [])),
]) . PHP_EOL;
''')
    marker = next((line for line in output.splitlines() if line.startswith("DEMO_FIXTURE=")), "")
    if not marker:
        raise RuntimeError("Non-secret demo fixture marker missing")
    return json.loads(marker.split("=", 1)[1])


def login(page, username, password):
    page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", username)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    try:
        page.wait_for_url(lambda url: "wp-login.php" not in url, wait_until="domcontentloaded", timeout=20000)
    except PlaywrightTimeoutError:
        return False
    return "wp-login.php" not in page.url


def open_account(browser, credentials, key):
    context = browser.new_context(viewport={"width": 1280, "height": 900}, locale="fa-IR")
    page = context.new_page()
    page.set_default_timeout(20000)
    if not login(page, credentials[key]["username"], credentials[key]["password"]):
        context.close()
        raise RuntimeError(f"Generated {key} login failed")
    return context, page


def click_form_action(page, action):
    form = page.locator(f'form:has(input[name="demo_action"][value="{action}"])')
    form.locator('input[type="submit"], button[type="submit"]').click()
    page.wait_for_load_state("domcontentloaded")


def assert_no_horizontal_overflow(page, name):
    dimensions = page.evaluate("""() => ({
        width: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth
    })""")
    check(name, dimensions["scroll"] <= dimensions["width"] + 2, json.dumps(dimensions))


def public_booking(browser, admin_page, links, credentials):
    stage("public booking OTP hold confirm")
    context = browser.new_context(viewport={"width": 390, "height": 844}, locale="fa-IR")
    page = context.new_page()
    page.set_default_timeout(25000)
    page.goto(links["booking"], wait_until="domcontentloaded")
    root = page.locator(".cpms-public-booking")
    root.wait_for()
    check("public shortcode rendered", root.count() == 1)
    check("public booking RTL", root.evaluate("el => getComputedStyle(el).direction") == "rtl")

    clinician = root.locator(".cpms-public-booking__clinician")
    clinician.wait_for()
    if clinician.evaluate("el => el.tagName") == "SELECT":
        options = clinician.locator("option")
        if options.count() > 1:
            clinician.select_option(index=1)
    else:
        clinician.first.click()
    slot = root.locator(".cpms-public-booking__slot:not([disabled])").first
    slot.wait_for(state="visible", timeout=30000)
    slot.click()
    continue_button = root.locator('[data-role="continue-auth"]')
    continue_button.wait_for(state="visible")
    continue_button.click()
    root.locator('[data-role="otp-mobile"]').fill(MOBILE)
    root.locator('[data-auth-action="otp-request"]').click()
    root.locator('[data-role="otp-code"]').wait_for(state="visible")

    # The OTP is retrieved through the nonce-protected Administrator UI only.
    click_form_action(admin_page, "otp")
    otp_node = admin_page.locator("[data-demo-otp]")
    otp_node.wait_for(state="visible")
    otp = otp_node.inner_text().strip()
    if len(otp) != 6 or not otp.isdigit():
        raise RuntimeError("Administrator OTP response was invalid")
    root.locator('[data-role="otp-code"]').fill(otp)
    otp = ""
    root.locator('[data-auth-action="otp-verify"]').click()
    page.wait_for_load_state("domcontentloaded", timeout=30000)
    root = page.locator(".cpms-public-booking")
    confirm = root.locator('[data-role="confirm-btn"]')
    confirm.wait_for(state="visible", timeout=35000)
    check("OTP restored quote and automatic hold", root.locator('[data-role="hold-countdown"]').is_visible())
    confirm.click()
    receipt = root.locator('[data-role="receipt"]')
    receipt.wait_for(state="visible", timeout=30000)
    reference = receipt.locator('[data-role="reference-code"]').inner_text().strip()
    check("public booking confirmed with receipt", bool(reference))
    assert_no_horizontal_overflow(page, "public booking mobile has no horizontal overflow")
    page.screenshot(path=f"{OUT}/screenshots/demo-booking-receipt.png", full_page=True)

    page.goto(links["patient"], wait_until="domcontentloaded")
    page.wait_for_timeout(1200)
    check("patient dashboard after OTP", page.locator("#cpms-patient-portal-shell").get_attribute("data-shell-user") == "patient")
    assert_no_horizontal_overflow(page, "patient portal mobile has no horizontal overflow")
    page.screenshot(path=f"{OUT}/screenshots/demo-patient-mobile.png", full_page=True)
    return context, page, reference


def reception_arrival(browser, credentials, staff_url, clinic_id, reference):
    stage("reception check-in and enqueue of the public booking")
    output = wp_eval(r'''
$db = \ClinicCore\Bootstrap\App::db();
$row = $db->fetchRow("SELECT id FROM " . $db->table("cpms_appointments") . " WHERE clinic_id = %d AND reference_code = %s LIMIT 1", [(int) getenv("DEMO_CLINIC_ID"), (string) getenv("DEMO_REFERENCE")]);
echo "DEMO_APPOINTMENT=" . (int) ($row["id"] ?? 0) . PHP_EOL;
''', {"DEMO_CLINIC_ID": str(clinic_id), "DEMO_REFERENCE": reference})
    marker = next((line for line in output.splitlines() if line.startswith("DEMO_APPOINTMENT=")), "")
    appointment_id = int(marker.split("=", 1)[1]) if marker else 0
    check("receipt resolves to exact demo appointment", appointment_id > 0)
    if appointment_id <= 0:
        raise RuntimeError("Booked appointment was not found in the demo Clinic")

    context, page = open_account(browser, credentials, "receptionist")
    page.goto(staff_url + ("&" if "?" in staff_url else "?") + urlencode({"cpms-module": "reception"}), wait_until="domcontentloaded")
    arrive = page.locator(f'[data-role="sr-arrive"][data-appointment-id="{appointment_id}"]')
    arrive.wait_for(state="visible", timeout=35000)
    arrive.click()
    arrive.wait_for(state="detached", timeout=35000)

    output = wp_eval(r'''
$db = \ClinicCore\Bootstrap\App::db();
$row = $db->fetchRow("SELECT id, status FROM " . $db->table("cpms_visits") . " WHERE clinic_id = %d AND appointment_id = %d AND active = 1 ORDER BY id DESC LIMIT 1", [(int) getenv("DEMO_CLINIC_ID"), (int) getenv("DEMO_APPOINTMENT_ID")]);
echo "DEMO_ARRIVAL=" . wp_json_encode(["id" => (int) ($row["id"] ?? 0), "status" => (string) ($row["status"] ?? "")]) . PHP_EOL;
''', {"DEMO_CLINIC_ID": str(clinic_id), "DEMO_APPOINTMENT_ID": str(appointment_id)})
    marker = next((line for line in output.splitlines() if line.startswith("DEMO_ARRIVAL=")), "")
    arrival = json.loads(marker.split("=", 1)[1]) if marker else {}
    check("reception checked in and enqueued booked appointment", arrival.get("id", 0) > 0 and arrival.get("status") == "waiting", json.dumps(arrival))
    if arrival.get("id", 0) <= 0 or arrival.get("status") != "waiting":
        raise RuntimeError("Reception arrival did not produce a waiting Visit")
    return context, page, int(arrival["id"])


def doctor_journey(browser, credentials, staff_url, visit_id):
    stage("doctor call start notes prescription complete")
    context, page = open_account(browser, credentials, "doctor")
    page.goto(staff_url + ("&" if "?" in staff_url else "?") + urlencode({"cpms-module": "doctor"}), wait_until="domcontentloaded")
    row_selector = f'[data-role="queue-item"][data-visit-id="{visit_id}"]'
    page.locator(row_selector).wait_for(state="visible", timeout=35000)
    page.locator(f'{row_selector} [data-action="call"]').click()
    page.locator(f'{row_selector}[data-status="called"]').wait_for(state="visible", timeout=30000)
    page.locator(f'{row_selector} [data-action="start"]').click()
    page.locator(f'{row_selector}[data-status="in_consultation"]').wait_for(state="visible", timeout=30000)
    page.locator(row_selector).click()
    page.locator('[data-role="workspace-body"]').wait_for(state="visible", timeout=30000)

    page.fill('[data-role="workspace-content"]', "یادداشت بالینی کاملاً ساختگی برای پذیرش Demo")
    page.locator('[data-role="workspace-note-form"]').evaluate("form => form.requestSubmit()")
    page.locator('[data-role="workspace-form-success"]').wait_for(state="visible")
    page.fill('[data-role="workspace-cc-text"]', "شکایت اصلی ساختگی؛ هیچ داده پزشکی واقعی نیست")
    page.locator('[data-role="workspace-cc-form"]').evaluate("form => form.requestSubmit()")
    page.locator('[data-role="workspace-cc-success"]').wait_for(state="visible")

    page.fill('[data-role="workspace-rx-generic-name"]', "SYNTHETIC-NOT-A-DRUG")
    page.fill('[data-role="workspace-rx-dose"]', "0 demo-unit")
    page.fill('[data-role="workspace-rx-frequency"]', "test only")
    page.fill('[data-role="workspace-rx-duration-days"]', "1")
    page.fill('[data-role="workspace-rx-instructions"]', "نسخه غیرواقعی؛ هرگز مصرف نشود")
    page.locator('[data-role="workspace-rx-form"]').evaluate("form => form.requestSubmit()")
    finalize = page.locator('[data-role="workspace-rx-finalize"]').first
    finalize.wait_for(state="visible", timeout=30000)
    finalize.click()
    page.locator('[data-role="workspace-rx-item"][data-status="finalized"]').wait_for(state="visible", timeout=30000)
    complete = page.locator('[data-role="workspace-consult-complete-submit"]')
    complete.wait_for(state="visible")
    complete.click()
    page.locator('[data-role="workspace-consult-complete-success"]').wait_for(state="visible", timeout=30000)
    check("doctor live clinical journey completed", True)
    page.screenshot(path=f"{OUT}/screenshots/demo-doctor-complete.png", full_page=True)
    return context, page


def finance_journey(browser, credentials, staff_url, visit_id):
    stage("accountant invoice payment and reception checkout")
    accountant_context, accountant = open_account(browser, credentials, "accountant")
    finance_url = staff_url + ("&" if "?" in staff_url else "?") + urlencode({"cpms-module": "finance"})
    accountant.goto(finance_url, wait_until="domcontentloaded")
    issue = accountant.locator(f'[data-role="finance-issue-open"][data-visit-id="{visit_id}"]')
    issue.wait_for(state="visible", timeout=35000)
    issue.click()
    accountant.fill('[data-role="finance-issue-description"]', "ویزیت آزمایشی مرورگر")
    accountant.fill('[data-role="finance-issue-quantity"]', "1")
    accountant.fill('[data-role="finance-issue-unit-price"]', "1000")
    accountant.locator('[data-role="finance-issue-form"]').evaluate("form => form.requestSubmit()")
    pay = accountant.locator(f'[data-role="finance-pay-open"][data-visit-id="{visit_id}"]')
    pay.wait_for(state="visible", timeout=35000)
    pay.click()
    accountant.fill('[data-role="finance-pay-amount"]', "1000")
    accountant.select_option('[data-role="finance-pay-method"]', "other")
    accountant.fill('[data-role="finance-pay-ref"]', "SYNTHETIC-NO-PAYMENT")
    accountant.locator('[data-role="finance-pay-form"]').evaluate("form => form.requestSubmit()")
    accountant.locator(f'[data-role="finance-paid-rows"] [data-visit-id="{visit_id}"]').wait_for(state="attached", timeout=35000)
    check("accountant invoice and full synthetic payment", True)
    accountant_context.close()

    reception_context, reception = open_account(browser, credentials, "receptionist")
    reception.goto(finance_url, wait_until="domcontentloaded")
    checkout = reception.locator(f'[data-role="finance-checkout-open"][data-visit-id="{visit_id}"]')
    checkout.wait_for(state="visible", timeout=35000)
    checkout.click()
    reception.locator('[data-role="finance-checkout-form"]').evaluate("form => form.requestSubmit()")
    reception.locator(f'[data-role="finance-checkout-open"][data-visit-id="{visit_id}"]').wait_for(state="detached", timeout=35000)
    check("reception completed paid checkout", True)
    return reception_context, reception


def verify_operational_db(visit_id):
    output = wp_eval(r'''
$id = (int) getenv("DEMO_VISIT_ID");
$db = \ClinicCore\Bootstrap\App::db();
echo "DEMO_OPERATION=" . wp_json_encode([
  "visit" => $db->fetchValue("SELECT status FROM " . $db->table("cpms_visits") . " WHERE id = %d", [$id]),
  "notes" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_clinical_notes") . " WHERE visit_id = %d", [$id]),
  "rx" => $db->fetchValue("SELECT status FROM " . $db->table("cpms_prescriptions") . " WHERE visit_id = %d ORDER BY id DESC LIMIT 1", [$id]),
  "invoice" => $db->fetchValue("SELECT status FROM " . $db->table("cpms_invoices") . " WHERE visit_id = %d ORDER BY id DESC LIMIT 1", [$id]),
  "payments" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_payments") . " p INNER JOIN " . $db->table("cpms_invoices") . " i ON i.id = p.invoice_id WHERE i.visit_id = %d", [$id]),
]) . PHP_EOL;
''', {"DEMO_VISIT_ID": str(visit_id)})
    marker = next((line for line in output.splitlines() if line.startswith("DEMO_OPERATION=")), "")
    data = json.loads(marker.split("=", 1)[1])
    check("database confirms clinical finance checkout journey",
          data["visit"] == "checked_out" and data["notes"] >= 2 and data["rx"] == "finalized"
          and data["invoice"] == "paid" and data["payments"] >= 1, json.dumps(data))


def main():
    global LAST_FIXTURE
    if not ADMIN_USER or not ADMIN_PASS:
        raise RuntimeError("ADMIN_USER and ADMIN_PASS are required")
    os.makedirs(f"{OUT}/screenshots", exist_ok=True)
    os.makedirs(f"{OUT}/logs", exist_ok=True)
    fixture = None
    contexts = []
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True, args=["--no-sandbox"])
        try:
            stage("administrator provisioning")
            admin_context = browser.new_context(viewport={"width": 1280, "height": 900}, locale="fa-IR")
            contexts.append(admin_context)
            admin = admin_context.new_page()
            admin.set_default_timeout(25000)
            if not login(admin, ADMIN_USER, ADMIN_PASS):
                raise RuntimeError("Administrator login failed")
            admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-demo", wait_until="domcontentloaded")
            admin.check('input[name="ack"]')
            click_form_action(admin, "provision")
            rows = admin.locator("[data-demo-account]")
            if rows.count() != 6:
                raise RuntimeError(f"Expected six one-time credential rows, got {rows.count()}")
            credentials = {}
            for key in ACCOUNTS:
                row = admin.locator(f'[data-demo-account="{key}"]')
                credentials[key] = {
                    "username": row.locator(".demo-username").inner_text().strip(),
                    "password": row.locator(".demo-password").inner_text().strip(),
                }
            check("six generated identities", len(credentials) == 6)
            check("six unique CSPRNG passwords", len({row["password"] for row in credentials.values()}) == 6)
            shortcode = admin.locator("[data-demo-shortcode]").input_value()
            fixture = fixture_from_db()
            LAST_FIXTURE = fixture
            check("exact executing clinic shortcode", shortcode == f'[cpms_public_booking clinic_id="{fixture["clinic_id"]}"]')
            links = {key: admin.locator(f'[data-demo-link="{key}"]').get_attribute("href") for key in ("booking", "patient", "staff", "management")}
            check("four delivered portal links", all(links.values()))

            # Do not screenshot or dump the credential response. Navigate away first.
            admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-demo", wait_until="domcontentloaded")
            check("one-time credentials not redisplayed", admin.locator("[data-demo-credentials]").count() == 0)

            stage("six logins and scoped portal rendering")
            portal_targets = {
                "organization_manager": links["management"],
                "clinic_manager": links["management"],
                "doctor": links["staff"],
                "receptionist": links["staff"] + ("&" if "?" in links["staff"] else "?") + "cpms-module=reception",
                "accountant": links["staff"] + ("&" if "?" in links["staff"] else "?") + "cpms-module=finance",
                "patient": links["patient"],
            }
            for key in ACCOUNTS:
                context, page = open_account(browser, credentials, key)
                contexts.append(context)
                page.goto(portal_targets[key], wait_until="domcontentloaded")
                page.wait_for_timeout(600)
                check(f"login and portal: {key}", "wp-login.php" not in page.url and page.locator("body").count() == 1)
                if key == "receptionist":
                    check("reception module delivered", page.locator('[data-role="reception-app"]').count() == 1)
                if key == "accountant":
                    check("accountant finance module delivered", page.locator('[data-role="finance-root"]').count() == 1)
                if key != "patient":
                    context.close()
                    contexts.remove(context)

            patient_context, patient_page, booking_reference = public_booking(browser, admin, links, credentials)
            contexts.append(patient_context)
            arrival_context, arrival_page, live_visit_id = reception_arrival(browser, credentials, links["staff"], fixture["clinic_id"], booking_reference)
            contexts.append(arrival_context)
            doctor_context, doctor_page = doctor_journey(browser, credentials, links["staff"], live_visit_id)
            contexts.append(doctor_context)
            reception_context, reception_page = finance_journey(browser, credentials, links["staff"], live_visit_id)
            contexts.append(reception_context)
            verify_operational_db(live_visit_id)

            stage("management review and rotation")
            manager_context, manager = open_account(browser, credentials, "organization_manager")
            manager.goto(links["management"], wait_until="domcontentloaded")
            manager.wait_for_timeout(1000)
            check("organization management review renders", "wp-login.php" not in manager.url and manager.locator("body").count() == 1)
            assert_no_horizontal_overflow(manager, "management RTL desktop has no horizontal overflow")
            manager.set_viewport_size({"width": 390, "height": 844})
            assert_no_horizontal_overflow(manager, "management RTL mobile has no horizontal overflow")
            manager.screenshot(path=f"{OUT}/screenshots/demo-management-mobile.png", full_page=True)
            manager_context.close()

            admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-demo", wait_until="domcontentloaded")
            admin.select_option('select[name="account"]', "doctor")
            click_form_action(admin, "rotate")
            rotated_row = admin.locator('[data-demo-account="doctor"]')
            rotated = {
                "username": rotated_row.locator(".demo-username").inner_text().strip(),
                "password": rotated_row.locator(".demo-password").inner_text().strip(),
            }
            check("rotation returns only selected account", admin.locator("[data-demo-account]").count() == 1)
            doctor_page.goto(links["staff"], wait_until="domcontentloaded")
            doctor_page.wait_for_timeout(500)
            check("rotation revokes existing doctor cookie", "wp-login.php" in doctor_page.url or doctor_page.locator('[data-role="portal-login"], [data-role="portal-access-denied"]').count() > 0)
            credentials["doctor"] = rotated
            rotated_context, rotated_page = open_account(browser, credentials, "doctor")
            check("rotated doctor password logs in", "wp-login.php" not in rotated_page.url)
            rotated_context.close()

            stage("end revocation and owned cleanup")
            # Move the Admin away from the one-time rotation response before any evidence.
            admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-demo", wait_until="domcontentloaded")
            click_form_action(admin, "end")
            check("end acknowledgement without secrets", admin.locator(".notice-success").count() >= 1 and admin.locator("[data-demo-credentials]").count() == 0)
            patient_page.goto(links["patient"], wait_until="domcontentloaded")
            patient_page.wait_for_timeout(500)
            check("end revokes existing patient OTP/WP cookie", patient_page.locator("#cpms-patient-portal-shell").get_attribute("data-shell-user") == "anonymous")
            old_doctor = browser.new_context()
            old_page = old_doctor.new_page()
            check("ended account cannot newly authenticate", not login(old_page, credentials["doctor"]["username"], credentials["doctor"]["password"]))
            old_doctor.close()

            before_cleanup = wp_eval(r'''
echo "DEMO_CLEANUP=" . wp_json_encode(["users" => count(get_users(["fields" => "ID"]))]) . PHP_EOL;
''')
            admin.check('input[name="cleanup_ack"]')
            click_form_action(admin, "cleanup")
            check("cleanup returns to explicit OFF state", admin.locator('input[name="ack"]').count() == 1)
            output = wp_eval(r'''
$s = get_option(\ClinicCore\Application\Demo\DemoService::STATE_OPTION, []);
$ids = array_map("intval", explode(",", (string) getenv("DEMO_USER_IDS")));
$remaining = 0;
foreach ($ids as $id) { if (get_userdata($id)) { $remaining++; } }
$db = \ClinicCore\Bootstrap\App::db();
$clinic = (int) getenv("DEMO_CLINIC_ID");
echo "DEMO_CLEANUP=" . wp_json_encode([
  "state_empty" => $s === [],
  "users_remaining" => $remaining,
  "patients" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_patients") . " WHERE clinic_id = %d", [$clinic]),
  "visits" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_visits") . " WHERE clinic_id = %d", [$clinic]),
  "clinic_status" => $db->fetchValue("SELECT status FROM " . $db->table("cpms_clinics") . " WHERE id = %d", [$clinic]),
  "audit_ok" => \ClinicCore\Bootstrap\App::audit()->verifyChain(10000)["ok"],
]) . PHP_EOL;
''', {"DEMO_USER_IDS": ",".join(str(i) for i in fixture["user_ids"]), "DEMO_CLINIC_ID": str(fixture["clinic_id"])})
            marker = next((line for line in output.splitlines() if line.startswith("DEMO_CLEANUP=")), "")
            cleaned = json.loads(marker.split("=", 1)[1])
            check("demo-only cleanup verified", cleaned["state_empty"] and cleaned["users_remaining"] == 0
                  and cleaned["patients"] == 0 and cleaned["visits"] == 0
                  and cleaned["clinic_status"] == "suspended" and cleaned["audit_ok"], json.dumps(cleaned))
            stage("complete")
        finally:
            for context in contexts:
                try:
                    context.close()
                except Exception:
                    pass
            browser.close()
    dump_results(fixture)

    failed = [row for row in RESULTS if not row["ok"]]
    if failed:
        raise RuntimeError("Failed checks: " + ", ".join(row["check"] for row in failed))
    print(f"RESULT: PASS ({len(RESULTS)} checks)", flush=True)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        # Never include page HTML, credentials, cookies or OTP in diagnostics.
        print(f"ERROR: {type(exc).__name__}: {str(exc)[:500]}", flush=True)
        print("DEMO_DIAG=" + json.dumps(STATE, ensure_ascii=False), flush=True)
        try:
            dump_results()
        except Exception:
            pass
        sys.exit(1)
