#!/usr/bin/env python3
"""Real-Chromium acceptance for the external CPMS disposable-site fixture.

Passwords and the OTP code are read only from protected local 0600 files. They
are never printed, written to results, or captured in screenshots. WP-CLI is
used only for the explicit OTP hash fixture and non-secret database assertions;
all user journeys execute the delivered shortcode, portals, and production REST
routes in fresh per-persona BrowserContexts.
"""

import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
from urllib.parse import parse_qs, urlencode, urlparse

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
WP_DIR = os.environ.get("WP_DIR", "")
OUT = os.environ.get("OUT", "/tmp/acc")
STATE_FILE = os.environ.get("CPMS_DISPOSABLE_STATE", "")
OTP_FILE = os.environ.get("CPMS_DISPOSABLE_OTP_FILE", "")
ADMIN_USER = os.environ.get("ADMIN_USER", "")
REPO = Path(__file__).resolve().parents[2]
OTP_TOOL = REPO / "tests/disposable-site/wp-cli/otp.php"
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


def protected_json(path):
    if not path or not os.path.isabs(path):
        raise RuntimeError("protected JSON path must be absolute")
    if os.path.islink(path) or not os.path.isfile(path):
        raise RuntimeError("protected JSON must be a regular non-symlink file")
    mode = stat.S_IMODE(os.stat(path).st_mode)
    if mode & 0o077:
        raise RuntimeError("protected JSON mode must be 0600 or stricter")
    web_root = os.path.realpath(WP_DIR) if WP_DIR else ""
    parent = os.path.realpath(os.path.dirname(path))
    if web_root and (parent == web_root or parent.startswith(web_root + os.sep)):
        raise RuntimeError("protected JSON must be outside the web root")
    if parent == str(REPO) or parent.startswith(str(REPO) + os.sep):
        raise RuntimeError("protected JSON must be outside the Git worktree")
    with open(path, encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        raise RuntimeError("protected JSON root must be an object")
    return data


def dump_results(fixture=None):
    fixture = fixture or LAST_FIXTURE
    os.makedirs(f"{OUT}/logs", exist_ok=True)
    safe_fixture = None
    if fixture:
        safe_fixture = {k: fixture.get(k) for k in (
            "fixture_id", "clinic_id", "location_id", "organization_id",
            "completed_visit_id", "booking_page_id"
        )}
    payload = {
        "fixture": safe_fixture,
        "checks": RESULTS,
        "stage": STATE["stage"],
        "secrets_in_results": False,
    }
    with open(f"{OUT}/logs/disposable-results.json", "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
    print("DISPOSABLE_RESULTS=" + json.dumps({
        "stage": STATE["stage"],
        "checks": len(RESULTS),
        "failed": [row["check"] for row in RESULTS if not row["ok"]],
    }, ensure_ascii=False), flush=True)


def wp(*args):
    if not WP_DIR:
        raise RuntimeError("WP_DIR is required")
    proc = subprocess.run(
        ["wp", *args, f"--path={WP_DIR}", "--allow-root"],
        capture_output=True, text=True, timeout=180,
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


def make_otp_fixture():
    if not ADMIN_USER:
        raise RuntimeError("ADMIN_USER is required for the explicit WP-CLI fixture")
    env = os.environ.copy()
    env.update({
        "CPMS_DISPOSABLE_STATE": STATE_FILE,
        "CPMS_DISPOSABLE_OTP_FILE": OTP_FILE,
    })
    proc = subprocess.run([
        "wp", "eval-file", str(OTP_TOOL), "--use-include",
        f"--path={WP_DIR}", "--allow-root", f"--user={ADMIN_USER}",
    ], capture_output=True, text=True, timeout=60, env=env)
    if proc.returncode != 0:
        raise RuntimeError(f"external OTP fixture failed rc={proc.returncode}: {(proc.stderr or proc.stdout)[:300]}")
    # The tool's stdout is safe by contract; do not echo it or the OTP file.
    fixture = protected_json(OTP_FILE)
    code = str(fixture.get("code", ""))
    if not re.fullmatch(r"\d{6}", code) or int(fixture.get("token_id", 0)) <= 0:
        raise RuntimeError("external OTP fixture is invalid")
    os.unlink(OTP_FILE)
    return code


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
    check(f"fresh cookie jar: {key}", len(context.cookies()) == 0)
    page = context.new_page()
    page.set_default_timeout(20000)
    if not login(page, credentials[key]["username"], credentials[key]["password"]):
        context.close()
        raise RuntimeError(f"Generated {key} login failed")
    return context, page


def with_query(url, **params):
    return url + ("&" if "?" in url else "?") + urlencode(params)


def assert_no_horizontal_overflow(page, name):
    dimensions = page.evaluate("""() => ({
        width: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth
    })""")
    check(name, dimensions["scroll"] <= dimensions["width"] + 2, json.dumps(dimensions))


def rest_response(responses, path):
    for response in reversed(responses):
        parsed = urlparse(response.url)
        route = parse_qs(parsed.query).get("rest_route", [""])[0]
        if parsed.path.endswith("/wp-json/clinic/v1" + path) or route == "/clinic/v1" + path:
            return response
    return None


def finance_route(url):
    parsed = urlparse(url)
    route = parse_qs(parsed.query).get("rest_route", [""])[0]
    return route or parsed.path


def finance_safe_text(value):
    text = str(value or "")
    text = re.sub(r"\b[a-f0-9]{48}\b", "[REDACTED]", text, flags=re.I)
    text = re.sub(r"(?i)(password|passwd|otp|token|secret|authorization|cookie|nonce)(\s*[:=]\s*)[^\s,;]+", r"\1\2[REDACTED]", text)
    text = re.sub(r"https?://[^\s]+", "[URL]", text)
    return text[:240]


def attach_finance_diagnostics(page):
    data = {"console_errors": [], "page_errors": [], "failed_requests": [], "finance_responses": []}

    def append(key, value):
        if len(data[key]) < 20:
            data[key].append(value)

    page.on("console", lambda message: append("console_errors", {
        "type": message.type,
        "text": finance_safe_text(message.text),
    }) if message.type in ("error", "warning") else None)
    page.on("pageerror", lambda error: append("page_errors", finance_safe_text(error)))
    page.on("requestfailed", lambda request: append("failed_requests", {
        "method": request.method,
        "route": finance_route(request.url),
        "error": finance_safe_text(request.failure),
    }))
    page.on("response", lambda response: append("finance_responses", {
        "route": finance_route(response.url),
        "status": response.status,
    }) if "/staff/portal/finance/" in response.url else None)
    return data


def _capture_finance_diagnostics(page, data, probe=None):
    try:
        dom = page.evaluate("""() => {
            const root = document.querySelector('[data-role="finance-root"]');
            const panel = document.querySelector('[data-role="finance-eligible"]');
            const rows = document.querySelector('[data-role="finance-eligible-rows"]');
            const status = document.querySelector('[data-role="finance-status"]');
            const location = document.querySelector('[data-role="finance-location"]');
            const form = document.querySelector('[data-role="finance-issue-form"]');
            const buttons = Array.from(document.querySelectorAll('[data-role="finance-issue-open"]'));
            return {
                root_count: document.querySelectorAll('[data-role="finance-root"]').length,
                config_present: Boolean(document.getElementById('cpms-finance-board-config')),
                eligible_panel_hidden: panel ? panel.hidden : null,
                eligible_panel_present: Boolean(panel),
                eligible_row_count: rows ? rows.querySelectorAll('tr').length : null,
                issue_button_count: buttons.length,
                issue_button_visit_ids: buttons.map((button) => Number(button.getAttribute('data-visit-id') || 0)),
                issue_form_hidden: form ? form.hidden : null,
                status_kind: status ? String(status.dataset.kind || '') : null,
                status_text_length: status ? String(status.textContent || '').length : null,
                location_present: Boolean(location),
                location_hidden: location ? location.hidden : null,
                location_disabled: location ? location.disabled : null,
                location_value: location ? String(location.value || '') : null,
                location_option_ids: location ? Array.from(location.options).map((option) => Number(option.value || 0)) : [],
                document_direction: document.documentElement.dir || null,
                viewport_width: window.innerWidth,
                root_present: Boolean(root)
            };
        }""")
    except Exception as exc:
        dom = {"capture_error": type(exc).__name__}
    payload = {
        "dom": dom,
        "probe": probe or {},
        "console_errors": data["console_errors"],
        "page_errors": data["page_errors"],
        "failed_requests": data["failed_requests"],
        "finance_responses": data["finance_responses"],
    }
    os.makedirs(f"{OUT}/logs", exist_ok=True)
    with open(f"{OUT}/logs/finance-diagnostics.json", "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
    print("DISPOSABLE_FINANCE_DIAG=" + json.dumps(payload, ensure_ascii=False), flush=True)
    try:
        os.makedirs(f"{OUT}/screenshots", exist_ok=True)
        page.screenshot(path=f"{OUT}/screenshots/disposable-finance-diagnostics.png", full_page=True)
        print("DIAG finance diagnostic screenshot captured (pixels not inspected)", flush=True)
    except Exception as exc:
        print("ERROR: finance diagnostic screenshot failed: " + type(exc).__name__, flush=True)


def capture_finance_diagnostics(page, data, probe=None):
    # Diagnostics must never replace the acceptance exception or prevent the
    # original UI failure from being reported.
    try:
        _capture_finance_diagnostics(page, data, probe)
    except Exception as exc:
        fallback = {"capture_error": type(exc).__name__}
        print("DISPOSABLE_FINANCE_DIAG=" + json.dumps(fallback), flush=True)


def public_booking(browser, links, fixture):
    stage("public booking normal OTP hold confirm")
    context = browser.new_context(viewport={"width": 390, "height": 844}, locale="fa-IR")
    page = context.new_page()
    page.set_default_timeout(25000)
    responses = []
    page.on("response", lambda response: responses.append(response))
    page.goto(links["booking"], wait_until="domcontentloaded")
    root = page.locator(".cpms-public-booking")
    root.wait_for()
    check("public shortcode rendered", root.count() == 1)
    check("public booking RTL", root.evaluate("el => getComputedStyle(el).direction") == "rtl")

    clinician = root.locator(".cpms-public-booking__clinician")
    clinician.wait_for()
    if clinician.evaluate("el => el.tagName") == "SELECT":
        if clinician.locator("option").count() > 1:
            clinician.select_option(index=1)
    else:
        clinician.first.click()
    slot = root.locator(".cpms-public-booking__slot:not([disabled])").first
    slot.wait_for(state="visible", timeout=30000)
    slot.click()
    quoted = root.locator('[data-role="panel"][data-state="bookable"][aria-busy="false"]')
    quoted.wait_for(state="visible", timeout=30000)
    check("public quote completed before OTP", quoted.count() == 1)
    continue_button = root.locator('[data-role="continue-auth"]')
    continue_button.wait_for(state="visible")
    continue_button.click()
    mobile_input = root.locator('[data-role="otp-mobile"]')
    mobile_input.wait_for(state="visible")
    mobile_input.fill(str(fixture["patient_mobile"]))
    root.locator('[data-auth-action="otp-request"]').click()
    root.locator('[data-role="otp-code"]').wait_for(state="visible")
    otp_response = rest_response(responses, "/otp/request")
    check("normal OTP request route succeeded", otp_response is not None and otp_response.status == 200)

    otp = make_otp_fixture()
    check("protected OTP fixture retrieved without output", not os.path.exists(OTP_FILE) and len(otp) == 6)
    root.locator('[data-role="otp-code"]').fill(otp)
    otp = ""
    root.locator('[data-auth-action="otp-verify"]').click()
    page.wait_for_load_state("domcontentloaded", timeout=30000)
    root = page.locator(".cpms-public-booking")
    confirm = root.locator('[data-role="confirm-btn"]')
    confirm.wait_for(state="visible", timeout=35000)
    verify_response = rest_response(responses, "/otp/verify")
    hold_response = rest_response(responses, "/booking/hold")
    check("normal OTP verify route succeeded", verify_response is not None and verify_response.status == 200)
    check("OTP restored quote and automatic hold",
          root.locator('[data-role="hold-countdown"]').is_visible()
          and hold_response is not None and hold_response.status == 200)
    confirm.click()
    receipt = root.locator('[data-role="receipt"]')
    receipt.wait_for(state="visible", timeout=30000)
    reference = receipt.locator('[data-role="reference-code"]').inner_text().strip()
    confirm_response = rest_response(responses, "/booking/confirm")
    check("public booking confirmed with receipt",
          bool(reference) and confirm_response is not None and confirm_response.status == 200)
    assert_no_horizontal_overflow(page, "public booking mobile has no horizontal overflow")
    page.screenshot(path=f"{OUT}/screenshots/disposable-booking-receipt.png", full_page=True)

    page.goto(links["patient"], wait_until="domcontentloaded")
    page.wait_for_timeout(1200)
    check("patient dashboard after OTP", page.locator("#cpms-patient-portal-shell").get_attribute("data-shell-user") == "patient")
    assert_no_horizontal_overflow(page, "patient portal mobile has no horizontal overflow")
    page.screenshot(path=f"{OUT}/screenshots/disposable-patient-mobile.png", full_page=True)
    return context, page, reference


def reception_arrival(browser, credentials, staff_url, clinic_id, location_id, reference):
    stage("reception check-in and enqueue of the public booking")
    output = wp_eval(r'''
$db = \ClinicCore\Bootstrap\App::db();
$row = $db->fetchRow("SELECT id FROM " . $db->table("cpms_appointments") . " WHERE clinic_id = %d AND reference_code = %s LIMIT 1", [(int) getenv("FIXTURE_CLINIC_ID"), (string) getenv("FIXTURE_REFERENCE")]);
echo "FIXTURE_APPOINTMENT=" . (int) ($row["id"] ?? 0) . PHP_EOL;
''', {"FIXTURE_CLINIC_ID": str(clinic_id), "FIXTURE_REFERENCE": reference})
    marker = next((line for line in output.splitlines() if line.startswith("FIXTURE_APPOINTMENT=")), "")
    appointment_id = int(marker.split("=", 1)[1]) if marker else 0
    check("receipt resolves to exact synthetic appointment", appointment_id > 0)
    if appointment_id <= 0:
        raise RuntimeError("Booked appointment was not found in the synthetic Clinic")

    context, page = open_account(browser, credentials, "receptionist")
    page.goto(with_query(staff_url, **{"cpms-module": "reception"}), wait_until="domcontentloaded")
    clinic_select = page.locator('[data-role="sr-clinic-select"]')
    clinic_select.wait_for(state="visible")
    page.locator(f'[data-role="sr-clinic-select"] option[value="{clinic_id}"]').wait_for(state="attached")
    if clinic_select.input_value() != str(clinic_id):
        clinic_select.select_option(str(clinic_id))
    location_select = page.locator('[data-role="sr-location-select"]')
    page.locator(f'[data-role="sr-location-select"] option[value="{location_id}"]').wait_for(state="attached", timeout=30000)
    location_select.select_option(str(location_id))
    check("reception exact synthetic Clinic and Location selected",
          clinic_select.input_value() == str(clinic_id) and location_select.input_value() == str(location_id))
    arrive = page.locator(f'[data-role="sr-arrive"][data-appointment-id="{appointment_id}"]')
    arrive.wait_for(state="visible", timeout=35000)
    arrive.click()
    arrive.wait_for(state="detached", timeout=35000)

    output = wp_eval(r'''
$db = \ClinicCore\Bootstrap\App::db();
$row = $db->fetchRow("SELECT id, status FROM " . $db->table("cpms_visits") . " WHERE clinic_id = %d AND appointment_id = %d AND active = 1 ORDER BY id DESC LIMIT 1", [(int) getenv("FIXTURE_CLINIC_ID"), (int) getenv("FIXTURE_APPOINTMENT_ID")]);
echo "FIXTURE_ARRIVAL=" . wp_json_encode(["id" => (int) ($row["id"] ?? 0), "status" => (string) ($row["status"] ?? "")]) . PHP_EOL;
''', {"FIXTURE_CLINIC_ID": str(clinic_id), "FIXTURE_APPOINTMENT_ID": str(appointment_id)})
    marker = next((line for line in output.splitlines() if line.startswith("FIXTURE_ARRIVAL=")), "")
    arrival = json.loads(marker.split("=", 1)[1]) if marker else {}
    check("reception checked in and enqueued booked appointment", arrival.get("id", 0) > 0 and arrival.get("status") == "waiting", json.dumps(arrival))
    if arrival.get("id", 0) <= 0 or arrival.get("status") != "waiting":
        raise RuntimeError("Reception arrival did not produce a waiting Visit")
    return context, page, int(arrival["id"])


def doctor_journey(browser, credentials, staff_url, clinic_id, location_id, visit_id):
    stage("doctor call start notes prescription complete")
    context, page = open_account(browser, credentials, "doctor")
    dialogs = []

    def handle_dialog(dialog):
        # Doctor Complete deliberately uses the production confirmation. Do
        # not let Playwright's default dismissal silently cancel the journey.
        dialogs.append(dialog.type)
        if dialog.type == "confirm":
            dialog.accept()
        else:
            dialog.dismiss()

    page.on("dialog", handle_dialog)
    page.goto(with_query(staff_url, **{"cpms-module": "doctor"}), wait_until="domcontentloaded")
    clinic_select = page.locator('[data-role="clinic-select"]')
    clinic_select.wait_for(state="visible")
    page.locator(f'[data-role="clinic-select"] option[value="{clinic_id}"]').wait_for(state="attached")
    if clinic_select.input_value() != str(clinic_id):
        clinic_select.select_option(str(clinic_id))
    location_select = page.locator('[data-role="location-select"]')
    page.locator(f'[data-role="location-select"] option[value="{location_id}"]').wait_for(state="attached", timeout=30000)
    location_select.select_option(str(location_id))
    check("doctor exact synthetic Clinic and Location selected",
          clinic_select.input_value() == str(clinic_id) and location_select.input_value() == str(location_id))
    row_selector = f'[data-role="queue-item"][data-visit-id="{visit_id}"]'
    page.locator(row_selector).wait_for(state="visible", timeout=35000)
    page.locator(f'{row_selector} [data-action="call"]').click()
    page.locator(f'{row_selector}[data-status="called"]').wait_for(state="visible", timeout=30000)
    page.locator(f'{row_selector} [data-action="start"]').click()
    page.locator(f'{row_selector}[data-status="in_consultation"]').wait_for(state="visible", timeout=30000)
    page.locator(row_selector).click()
    page.locator('[data-role="workspace-body"]').wait_for(state="visible", timeout=30000)

    page.fill('[data-role="workspace-content"]', "یادداشت بالینی کاملاً ساختگی برای پذیرش خارجی")
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
    check("doctor completion confirmation accepted", dialogs == ["confirm"])
    check("doctor live clinical journey completed", True)
    page.screenshot(path=f"{OUT}/screenshots/disposable-doctor-complete.png", full_page=True)
    return context, page


def finance_journey(browser, credentials, staff_url, visit_id, clinic_id, location_id):
    stage("accountant invoice payment and reception checkout")
    accountant_context, accountant = open_account(browser, credentials, "accountant")
    diagnostics = attach_finance_diagnostics(accountant)
    finance_url = with_query(staff_url, **{"cpms-module": "finance"})
    accountant.goto(finance_url, wait_until="domcontentloaded")
    accountant.locator('[data-role="finance-root"]').wait_for(state="visible")
    # Probe only non-secret scope/authority/selector metadata. This mirrors the
    # production board's own REST requests and makes a blank eligible list
    # distinguishable from a browser rendering/timing failure.
    probe = accountant.evaluate("""async () => {
        const configNode = document.getElementById('cpms-finance-board-config');
        const config = configNode ? JSON.parse(configNode.textContent || '{}') : {};
        const root = String(config.rest_root || '').replace(/\\/$/, '');
        const baseHeaders = {'X-WP-Nonce': String(config.nonce || ''), 'Accept': 'application/json'};
        const read = async (path, headers) => {
            const response = await fetch(root + path, {method: 'GET', headers, credentials: 'same-origin', cache: 'no-store'});
            const body = await response.json().catch(() => ({}));
            return {status: response.status, body};
        };
        const contextResult = await read('/staff/portal/finance/context', baseHeaders);
        const context = contextResult.body && contextResult.body.data ? contextResult.body.data : {};
        const scopedHeaders = Object.assign({}, baseHeaders, {
            'X-CPMS-Clinic-Id': String(context.clinic_id || ''),
            'X-CPMS-Location-Id': String(context.location_id || '')
        });
        const eligibleResult = await read('/staff/portal/finance/invoice-eligible', scopedHeaders);
        const eligible = eligibleResult.body && eligibleResult.body.data ? eligibleResult.body.data : {};
        return {
            context_status: contextResult.status,
            context_code: String(contextResult.body && contextResult.body.code || ''),
            clinic_id: Number(context.clinic_id || 0),
            location_id: Number(context.location_id || 0),
            can_issue_invoice: context.can_issue_invoice === true,
            context_keys: Object.keys(context).sort(),
            eligible_status: eligibleResult.status,
            eligible_code: String(eligibleResult.body && eligibleResult.body.code || ''),
            eligible_keys: Object.keys(eligible).sort(),
            eligible_row_keys: Array.isArray(eligible.visits) && eligible.visits.length ? Object.keys(eligible.visits[0]).sort() : [],
            eligible_visit_ids: Array.isArray(eligible.visits) ? eligible.visits.map((row) => Number(row.visit_id || 0)).filter((id) => id > 0) : []
        };
    }""")
    check(
        "accountant exact synthetic Clinic and Location plus invoice authority",
        probe["context_status"] == 200 and probe["clinic_id"] == clinic_id
        and probe["location_id"] == location_id and probe["can_issue_invoice"],
        json.dumps(probe, ensure_ascii=False),
    )
    check(
        "accountant live visit is invoice eligible",
        probe["eligible_status"] == 200 and visit_id in probe["eligible_visit_ids"],
        json.dumps(probe, ensure_ascii=False),
    )
    issue = accountant.locator(f'[data-role="finance-issue-open"][data-visit-id="{visit_id}"]')
    capture_finance_diagnostics(accountant, diagnostics, probe)
    try:
        issue.wait_for(state="visible", timeout=35000)
    except Exception:
        capture_finance_diagnostics(accountant, diagnostics, probe)
        raise
    issue.click()
    accountant.fill('[data-role="finance-issue-description"]', "ویزیت آزمایشی مرورگر")
    accountant.fill('[data-role="finance-issue-quantity"]', "1")
    accountant.fill('[data-role="finance-issue-unit-price"]', "1000")
    accountant.locator('[data-role="finance-issue-form"]').evaluate("form => form.requestSubmit()")
    # Finance renders its capture action with data-invoice-id (not the Visit
    # id). Wait for the real board refresh, then resolve that exact invoice.
    accountant.locator('[data-role="finance-pay-open"]').first.wait_for(state="visible", timeout=35000)
    output = wp_eval(r'''
$db = \ClinicCore\Bootstrap\App::db();
$id = (int) $db->fetchValue("SELECT id FROM " . $db->table("cpms_invoices") . " WHERE visit_id = %d ORDER BY id DESC LIMIT 1", [(int) getenv("FIXTURE_VISIT_ID")]);
echo "FIXTURE_INVOICE=" . $id . PHP_EOL;
''', {"FIXTURE_VISIT_ID": str(visit_id)})
    marker = next((line for line in output.splitlines() if line.startswith("FIXTURE_INVOICE=")), "")
    invoice_id = int(marker.split("=", 1)[1]) if marker else 0
    check("finance resolves exact synthetic invoice", invoice_id > 0)
    if invoice_id <= 0:
        raise RuntimeError("Issued browser invoice was not found")
    pay = accountant.locator(f'[data-role="finance-pay-open"][data-invoice-id="{invoice_id}"]')
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
$id = (int) getenv("FIXTURE_VISIT_ID");
$db = \ClinicCore\Bootstrap\App::db();
echo "FIXTURE_OPERATION=" . wp_json_encode([
  "visit" => $db->fetchValue("SELECT status FROM " . $db->table("cpms_visits") . " WHERE id = %d", [$id]),
  "notes" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_clinical_notes") . " WHERE visit_id = %d", [$id]),
  "rx" => $db->fetchValue("SELECT status FROM " . $db->table("cpms_prescriptions") . " WHERE visit_id = %d ORDER BY id DESC LIMIT 1", [$id]),
  "invoice" => $db->fetchValue("SELECT status FROM " . $db->table("cpms_invoices") . " WHERE visit_id = %d ORDER BY id DESC LIMIT 1", [$id]),
  "payments" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_payments") . " p INNER JOIN " . $db->table("cpms_invoices") . " i ON i.id = p.invoice_id WHERE i.visit_id = %d", [$id]),
]) . PHP_EOL;
''', {"FIXTURE_VISIT_ID": str(visit_id)})
    marker = next((line for line in output.splitlines() if line.startswith("FIXTURE_OPERATION=")), "")
    data = json.loads(marker.split("=", 1)[1])
    check("database confirms clinical finance checkout journey",
          data["visit"] == "checked_out" and data["notes"] >= 2 and data["rx"] == "finalized"
          and data["invoice"] == "paid" and data["payments"] >= 1, json.dumps(data))


def main():
    global LAST_FIXTURE
    fixture = protected_json(STATE_FILE)
    LAST_FIXTURE = fixture
    credentials = fixture.get("accounts", {})
    if tuple(credentials.keys()) != ACCOUNTS or fixture.get("status") != "active":
        raise RuntimeError("protected fixture must contain exactly six active accounts")
    passwords = [str(credentials[key]["password"]) for key in ACCOUNTS]
    check("six generated identities", len(credentials) == 6)
    check("six unique CSPRNG passwords", len(set(passwords)) == 6 and all(re.fullmatch(r"[a-f0-9]{48}", p) for p in passwords))
    check("exact executing clinic shortcode", fixture["shortcode"] == f'[cpms_public_booking clinic_id="{fixture["clinic_id"]}"]')
    links = fixture["links"]
    check("five actual portal links", all(links.get(key) for key in ("booking", "patient", "staff", "doctor", "management")))
    os.makedirs(f"{OUT}/screenshots", exist_ok=True)
    os.makedirs(f"{OUT}/logs", exist_ok=True)
    contexts = []
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True, args=["--no-sandbox"])
        try:
            stage("six logins scoped portals and denials")
            organization_url = with_query(links["management"], **{"cpms-org": fixture["organization_id"]})
            clinic_management_url = with_query(links["management"], **{"cpms-clinic": fixture["clinic_id"]})
            portal_targets = {
                "organization_manager": organization_url,
                "clinic_manager": clinic_management_url,
                # The legacy Doctor URL is a real delivered entry; eligible
                # doctors are redirected once to the canonical Staff Portal.
                "doctor": links["doctor"],
                "receptionist": with_query(links["staff"], **{"cpms-module": "reception"}),
                "accountant": with_query(links["staff"], **{"cpms-module": "finance"}),
                "patient": links["patient"],
            }
            for key in ACCOUNTS:
                context, page = open_account(browser, credentials, key)
                contexts.append(context)
                page.goto(portal_targets[key], wait_until="domcontentloaded")
                page.wait_for_timeout(600)
                check(f"login and portal: {key}", "wp-login.php" not in page.url and page.locator("body").count() == 1)
                if key == "doctor":
                    check("doctor module delivered", page.locator('[data-role="queue-list"]').count() == 1)
                if key == "receptionist":
                    check("reception module delivered", page.locator('[data-role="reception-app"]').count() == 1)
                if key == "accountant":
                    check("accountant finance module delivered", page.locator('[data-role="finance-root"]').count() == 1)
                if key == "organization_manager":
                    check("organization management scope delivered",
                          page.locator(f'[data-cpms-org="{fixture["organization_id"]}"]').count() == 1)
                if key == "clinic_manager":
                    check("clinic management scope delivered",
                          page.locator(f'[data-cpms-clinic="{fixture["clinic_id"]}"]').count() >= 1)
                if key == "patient":
                    check("patient portal delivered", page.locator('#cpms-patient-portal-shell[data-shell-user="patient"]').count() == 1)
                    page.goto(with_query(links["staff"], **{"cpms-module": "reception"}), wait_until="domcontentloaded")
                    check("patient denied staff portal", page.locator('[data-role="portal-access-denied"]').count() == 1
                          and page.locator('[data-role="reception-app"]').count() == 0)
                if key in ("receptionist", "accountant"):
                    # The canonical Staff Portal intentionally falls back to
                    # an eligible module for an ineligible selector. Use the
                    # real legacy Doctor entry for its explicit denial contract.
                    page.goto(links["doctor"], wait_until="domcontentloaded")
                    check(f"doctor module denied: {key}", page.locator('[data-role="portal-access-denied"]').count() == 1
                          and page.locator('[data-role="queue-list"]').count() == 0)
                context.close()
                contexts.remove(context)

            patient_context, patient_page, booking_reference = public_booking(browser, links, fixture)
            contexts.append(patient_context)
            arrival_context, arrival_page, live_visit_id = reception_arrival(
                browser, credentials, links["staff"], fixture["clinic_id"], fixture["location_id"], booking_reference
            )
            contexts.append(arrival_context)
            doctor_context, doctor_page = doctor_journey(
                browser, credentials, links["staff"], fixture["clinic_id"], fixture["location_id"], live_visit_id
            )
            contexts.append(doctor_context)
            reception_context, reception_page = finance_journey(
                browser, credentials, links["staff"], live_visit_id, fixture["clinic_id"], fixture["location_id"]
            )
            contexts.append(reception_context)
            verify_operational_db(live_visit_id)

            stage("management review RTL and mobile")
            manager_context, manager = open_account(browser, credentials, "organization_manager")
            manager.goto(organization_url, wait_until="domcontentloaded")
            manager.wait_for_timeout(1000)
            check("organization management review renders exact synthetic scope",
                  "wp-login.php" not in manager.url
                  and manager.locator(f'[data-cpms-org="{fixture["organization_id"]}"]').count() == 1)
            check("management document RTL", manager.locator("html").get_attribute("dir") == "rtl")
            assert_no_horizontal_overflow(manager, "management RTL desktop has no horizontal overflow")
            manager.set_viewport_size({"width": 390, "height": 844})
            assert_no_horizontal_overflow(manager, "management RTL mobile has no horizontal overflow")
            manager.screenshot(path=f"{OUT}/screenshots/disposable-management-mobile.png", full_page=True)
            manager_context.close()
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
        print("DISPOSABLE_DIAG=" + json.dumps(STATE, ensure_ascii=False), flush=True)
        try:
            dump_results()
        except Exception:
            pass
        sys.exit(1)
