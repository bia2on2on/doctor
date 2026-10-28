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

from contextlib import contextmanager
from datetime import datetime, time as dt_time, timedelta, timezone
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import time
from urllib.parse import parse_qs, urlencode, urlparse

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

JOURNEYS = (
    # Machine-readable registry of the journeys this harness implements; matched
    # against the compatibility manifest by .github/scripts/companion-compat.
    "cpdd_install_and_preflight",
    "cpdd_provision_and_credentials",
    "cpdd_persona_logins",
    "cpdd_booking_and_otp",
    "cpdd_reception_arrival",
    "cpdd_doctor_clinical_workflow",
    "cpdd_finance_invoice_payment",
    "cpdd_rtl_and_mobile",
    "cpdd_teardown_and_verify",
)


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


def wp_cli(args: list[str], env_vars: dict | None = None) -> str:
    merged = os.environ.copy()
    if env_vars:
        merged.update(env_vars)
    proc = subprocess.run(
        ["wp", *args, f"--path={WP_DIR}", "--allow-root"],
        capture_output=True,
        text=True,
        timeout=120,
        env=merged,
    )
    if proc.returncode != 0:
        raise RuntimeError(f"wp command failed rc={proc.returncode}: {(proc.stderr or proc.stdout)[:300]}")
    return (proc.stdout or "").strip()


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


def snapshot_wp_database(filename: str) -> str:
    """Create a private, temporary snapshot for destructive plugin-state probes."""
    os.makedirs(OUT, exist_ok=True)
    snapshot = os.path.join(OUT, filename)
    if os.path.exists(snapshot):
        os.remove(snapshot)
    wp_cli(["db", "export", snapshot])
    check("temporary WordPress database snapshot created", os.path.isfile(snapshot) and os.path.getsize(snapshot) > 0)
    return snapshot


def restore_wp_database(snapshot: str) -> None:
    """Restore the exact disposable-site state without re-running production activation hooks."""
    wp_cli(["db", "drop", "--yes"])
    wp_cli(["db", "create"])
    wp_cli(["db", "import", snapshot])
    os.remove(snapshot)


@contextmanager
def temporarily_deactivate_cpms(snapshot_name: str):
    """Probe real CPMS-inactive HTTP requests, then restore the disposable DB verbatim."""
    snapshot = snapshot_wp_database(snapshot_name)
    try:
        wp_cli(["plugin", "deactivate", "clinic-practice-management/clinic-practice-management.php"])
        yield
    finally:
        restore_wp_database(snapshot)


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
                const root = document.querySelector('.cpdd, .cpms-public-booking, #cpms-patient-portal-shell, .cpms-portal-shell, [data-cpms-org]') || document.body;
                const dir = root ? getComputedStyle(root).direction : getComputedStyle(document.documentElement).direction;
                return {
                    dir,
                    clientWidth: document.documentElement.clientWidth,
                    scrollWidth: document.documentElement.scrollWidth
                };
            }"""
        )
        check(
            f"{label} RTL direction at {width}px",
            metrics["dir"] == "rtl",
            json.dumps(metrics),
        )
        check(
            f"{label} no horizontal overflow at {width}px",
            metrics["scrollWidth"] <= metrics["clientWidth"] + 2,
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


def wait_out_local_midnight() -> None:
    """Bounded guard so the fixture and the reception board share one local day.

    Provisioning happens inside this browser flow, through an admin POST, so it
    cannot sleep there. The synthetic slot the journey books must be strictly in
    the future, and the appointment it produces must appear on the Staff Portal
    reception board, which is strictly the Location's local "today"
    (ReceptionPortalController::board -> VisitService::today plus today's booked
    rows). In the final minutes of the Asia/Tehran day no slot satisfies both,
    which used to surface as a 35s `sr-arrive` locator timeout - a wall-clock
    artefact, not a product defect.

    Waiting the band out is bounded (<= 13 minutes, once per local day), logged,
    and changes no assertion: it skips nothing, retries nothing and relaxes
    nothing. This job runs in 3-4 minutes against a 45-minute budget.
    """
    min_remaining_seconds = 12 * 60
    max_wait_seconds = 13 * 60
    try:
        from zoneinfo import ZoneInfo

        tehran = ZoneInfo("Asia/Tehran")
    except Exception:  # pragma: no cover - tzdata missing on an exotic runner
        # Asia/Tehran has had no DST since 2022-09-21, so +03:30 is exact.
        tehran = timezone(timedelta(hours=3, minutes=30))
    now = datetime.now(tehran)
    day_end = datetime.combine(now.date() + timedelta(days=1), dt_time(0, 0), tzinfo=tehran)
    remaining = int((day_end - now).total_seconds())
    if remaining >= min_remaining_seconds:
        return
    resume_at = day_end + timedelta(seconds=30)
    wait = int((resume_at - datetime.now(tehran)).total_seconds())
    if wait > max_wait_seconds:
        raise RuntimeError("the bounded local-midnight wait exceeded its cap")
    print(
        f"STAGE local-midnight guard (local_day_remaining={remaining}s wait={max(0, wait)}s "
        f"resume_at={resume_at.isoformat()})",
        flush=True,
    )
    if wait > 0:
        time.sleep(wait)


def main() -> None:
    wait_out_local_midnight()
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

            # 3. Actual WordPress menu registration, legacy URL and CPMS-inactive fallback.
            stage("3. CPMS menu, legacy URL, inactive fallback, and preflight")
            tool_url = f"{BASE}/wp-admin/admin.php?page=cpms-disposable-demo"
            legacy_tool_url = f"{BASE}/wp-admin/tools.php?page=cpms-disposable-demo"
            active_menu_root = admin_page.locator('#adminmenu > li > a[href*="page=cpms-dashboard"]')
            demo_menu_link = admin_page.locator('#adminmenu a[href*="page=cpms-disposable-demo"]')
            check("exactly one CPMS top-level menu uses the unified dashboard slug", active_menu_root.count() == 1 and "مدیریت مطب" in (active_menu_root.inner_text() or ""))
            check("demo is a CPMS submenu, not an extra top-level item", demo_menu_link.count() == 1 and admin_page.locator('#adminmenu > li > a[href*="cpms-disposable-demo"]').count() == 0, demo_menu_link.get_attribute("href") or "")
            check("demo has no visible Tools submenu entry while CPMS is active", admin_page.locator('#adminmenu a[href*="tools.php?page=cpms-disposable-demo"]').count() == 0)
            active_menu_root.hover()
            with admin_page.expect_navigation(wait_until="domcontentloaded"):
                demo_menu_link.click()
            check("browser navigation through the CPMS menu opens the canonical page", "admin.php?page=cpms-disposable-demo" in admin_page.url)

            # The redirect keeps only safe scalar context; unsafe keys and arrays are discarded.
            legacy_with_args = legacy_tool_url + "&clinic_id=23&view=compact&redirect_to=https%3A%2F%2Fevil.test&extra%5B%5D=bad"
            admin_page.goto(legacy_with_args, wait_until="domcontentloaded")
            final_query = parse_qs(urlparse(admin_page.url).query)
            check(
                "legacy Tools URL redirects once to canonical admin.php and preserves safe scalar args",
                urlparse(admin_page.url).path.endswith("/wp-admin/admin.php")
                and final_query.get("page") == ["cpms-disposable-demo"]
                and final_query.get("clinic_id") == ["23"]
                and final_query.get("view") == ["compact"]
                and "redirect_to" not in final_query
                and "extra" not in final_query,
                admin_page.url,
            )
            admin_page.goto(tool_url, wait_until="domcontentloaded")
            check("canonical direct URL renders the companion page", admin_page.locator('.wrap.cpdd[dir="rtl"]').count() == 1)

            # Use a real WP-CLI deactivation, then restore the exact disposable-site DB snapshot.
            # This keeps CPMS deactivation side effects from contaminating later active-path checks.
            with temporarily_deactivate_cpms("cpdd-inactive-empty.sql"):
                inactive_state = json.loads(
                    wp_eval(
                        r"""
                        $env = new \CpmsDemo\Env();
                        $manifest = new \CpmsDemo\Manifest($env);
                        $state = (new \CpmsDemo\Preflight($env, new \CpmsDemo\Platform(), $manifest))->run();
                        echo wp_json_encode([
                            'cpms_loaded' => class_exists(\ClinicCore\Bootstrap\App::class),
                            'cpms_active_plugin' => in_array('clinic-practice-management/clinic-practice-management.php', (array) get_option('active_plugins', []), true),
                            'blocking' => $state['blocking'],
                            'checks' => $state['checks'],
                        ]);
                        """
                    )
                )
                cpms_check = next(row for row in inactive_state["checks"] if row["id"] == "cpms")
                data_check = next(row for row in inactive_state["checks"] if row["id"] == "cpms_data")
                check(
                    "inactive-CPMS preflight safely fails closed with no ClinicCore classes",
                    not inactive_state["cpms_loaded"] and not inactive_state["cpms_active_plugin"]
                    and "cpms" in inactive_state["blocking"] and "cpms_data" in inactive_state["blocking"]
                    and "فعال نیست" in cpms_check["detail"] and "فعال نیست" in data_check["detail"],
                    json.dumps({"cpms_loaded": inactive_state["cpms_loaded"], "cpms": cpms_check["detail"], "data": data_check["detail"]}),
                )

                fallback_url = f"{BASE}/wp-admin/tools.php?page=cpms-disposable-demo"
                fallback_response = admin_page.goto(fallback_url, wait_until="domcontentloaded")
                fallback_link = admin_page.locator('#adminmenu a[href*="tools.php?page=cpms-disposable-demo"]')
                check(
                    "authorized administrator receives the Tools fallback without another top-level menu",
                    fallback_response is not None and fallback_response.status == 200
                    and admin_page.locator('.wrap.cpdd[dir="rtl"]').count() == 1
                    and fallback_link.count() == 1
                    and admin_page.locator('#adminmenu > li > a[href*="page=cpms-dashboard"]').count() == 0
                    and admin_page.locator('#adminmenu > li > a[href*="cpms-disposable-demo"]').count() == 0,
                    f"HTTP {fallback_response.status if fallback_response else 'no response'}",
                )
                inactive_preflight_rows = admin_page.locator('[data-cpdd-preflight] tbody tr').all_inner_texts()
                cpms_inactive_rows = [row for row in inactive_preflight_rows if "CPMS Classic" in row and "فعال نیست" in row]
                cpms_data_inactive_rows = [row for row in cpms_inactive_rows if "دادهٔ بالینی/مالی/نوبت خالی" in row]
                check(
                    "fallback page explains missing CPMS and blocks via visible preflight",
                    admin_page.locator('[data-cpdd-preflight="fail"]').count() == 1
                    and len(cpms_inactive_rows) == 2
                    and len(cpms_data_inactive_rows) == 1
                    and all("FAIL" in row for row in cpms_inactive_rows),
                    json.dumps(cpms_inactive_rows, ensure_ascii=False),
                )

                # A real non-admin account must not reach the Tools fallback directly.
                subscriber_login = "cpdd-fallback-acl-" + secrets.token_hex(5)
                subscriber_password = secrets.token_urlsafe(32)
                subscriber_id = int(
                    wp_eval(
                        r"""
                        $id = wp_create_user(getenv('CPDD_TEST_LOGIN'), getenv('CPDD_TEST_PASSWORD'), getenv('CPDD_TEST_EMAIL'));
                        if (is_wp_error($id)) { echo '0'; return; }
                        (new WP_User((int) $id))->set_role('subscriber');
                        echo (int) $id;
                        """,
                        {
                            "CPDD_TEST_LOGIN": subscriber_login,
                            "CPDD_TEST_PASSWORD": subscriber_password,
                            "CPDD_TEST_EMAIL": subscriber_login + "@example.test",
                        },
                    )
                )
                check("temporary subscriber fixture created for fallback authorization", subscriber_id > 0)
                sub_context = browser.new_context(viewport={"width": 1280, "height": 900}, locale="fa-IR")
                try:
                    sub_page = sub_context.new_page()
                    check("temporary subscriber can authenticate", login_wp(sub_page, subscriber_login, subscriber_password))
                    fallback_denial = sub_page.goto(fallback_url, wait_until="domcontentloaded")
                    check(
                        "subscriber is denied direct Tools fallback URL",
                        fallback_denial is not None and fallback_denial.status == 403
                        and sub_page.locator('form[data-cpdd-form="provision"]').count() == 0,
                        f"HTTP {fallback_denial.status if fallback_denial else 'no response'}",
                    )
                    canonical_denial = sub_page.goto(tool_url, wait_until="domcontentloaded")
                    check(
                        "subscriber is denied canonical admin.php URL while CPMS is inactive",
                        canonical_denial is not None and canonical_denial.status == 403
                        and sub_page.locator('form[data-cpdd-form="provision"]').count() == 0,
                        f"HTTP {canonical_denial.status if canonical_denial else 'no response'}",
                    )
                finally:
                    sub_context.close()
                    wp_cli(["user", "delete", str(subscriber_id), "--yes"])

                # Even a properly authorized administrator cannot provision without CPMS.
                fallback_provision = admin_page.locator('form[data-cpdd-form="provision"]')
                check("fallback retains the existing provisioning confirmation form", fallback_provision.count() == 1)
                fallback_provision.locator('input[name="cpdd_confirm"]').fill("I_UNDERSTAND_THIS_IS_A_DISPOSABLE_EMPTY_SITE")
                fallback_provision.locator('button[type="submit"]').click()
                admin_page.wait_for_load_state("domcontentloaded")
                no_cpms_fixture = json.loads(
                    wp_eval(
                        r"""
                        global $wpdb;
                        echo wp_json_encode([
                            'users' => count(get_users(['fields' => 'ID'])),
                            'manifest' => get_option('cpdd_manifest', null) !== null,
                            'clinics' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}cpms_clinics`"),
                            'patients' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}cpms_patients`"),
                            'appointments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}cpms_appointments`"),
                        ]);
                        """
                    )
                )
                check(
                    "admin provisioning request fails without CPMS and leaves no fixture or extra user",
                    admin_page.locator(".notice-error").count() == 1
                    and admin_page.locator('[data-cpdd-fixture-id]').count() == 0
                    and no_cpms_fixture == {"users": 1, "manifest": False, "clinics": 1, "patients": 0, "appointments": 0},
                    json.dumps(no_cpms_fixture),
                )

            admin_page.goto(tool_url, wait_until="domcontentloaded")
            check("restored active database returns the companion to its CPMS-active state", admin_page.locator('[data-cpdd-preflight="pass"]').count() == 1)

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

            # An existing fixture remains safely readable, but not actionable, while CPMS is inactive.
            fixture_before_inactive = json.loads(
                wp_eval(
                    r"""
                    global $wpdb;
                    $m = get_option('cpdd_manifest');
                    echo wp_json_encode([
                        'fixture_id' => (string) ($m['fixture_id'] ?? ''),
                        'clinics' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}cpms_clinics`"),
                        'patients' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}cpms_patients`"),
                        'appointments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}cpms_appointments`"),
                        'users' => count_users()['total_users'],
                    ]);
                    """
                )
            )
            with temporarily_deactivate_cpms("cpdd-inactive-fixture.sql"):
                inactive_fixture_response = admin_page.goto(fallback_url, wait_until="domcontentloaded")
                inactive_fixture_forms = sum(
                    admin_page.locator(f'form[data-cpdd-form="{action}"]').count()
                    for action in ("provision", "otp", "rotate", "diagnose", "teardown")
                )
                fixture_during_inactive = json.loads(
                    wp_eval(
                        r"""
                        global $wpdb;
                        $m = get_option('cpdd_manifest');
                        echo wp_json_encode([
                            'fixture_id' => (string) ($m['fixture_id'] ?? ''),
                            'clinics' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}cpms_clinics`"),
                            'patients' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}cpms_patients`"),
                            'appointments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}cpms_appointments`"),
                            'users' => count_users()['total_users'],
                        ]);
                        """
                    )
                )
                check(
                    "loaded demo renders a safe CPMS-inactive Tools fallback without action forms",
                    inactive_fixture_response is not None and inactive_fixture_response.status == 200
                    and admin_page.locator('[data-cpdd-cpms-inactive="1"]').count() == 1
                    and inactive_fixture_forms == 0
                    and admin_page.locator('[data-cpdd-fixture-id]').inner_text() == fixture_id,
                )
                check(
                    "CPMS-inactive fallback leaves existing manifest, users, and CPMS rows unchanged",
                    fixture_during_inactive == fixture_before_inactive,
                    json.dumps({"before": fixture_before_inactive, "after": fixture_during_inactive}),
                )
            admin_page.goto(tool_url, wait_until="domcontentloaded")
            check(
                "restored active database preserves the valid fixture and restores active controls",
                admin_page.locator('[data-cpdd-cpms-inactive="1"]').count() == 0
                and admin_page.locator("[data-cpdd-fixture-id]").get_attribute("data-cpdd-fixture-id") == fixture_id
                and admin_page.locator('form[data-cpdd-form="teardown"]').count() == 1,
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
                    # Parent-menu visibility is not authority: both current and legacy
                    # direct URLs must remain unavailable to this non-admin persona.
                    denied_response = p_page.goto(tool_url, wait_until="domcontentloaded")
                    check(
                        "non-admin persona denied direct canonical companion URL",
                        denied_response is not None and denied_response.status == 403
                        and p_page.locator('form[data-cpdd-form="teardown"]').count() == 0,
                        f"HTTP {denied_response.status if denied_response else 'no response'}",
                    )
                    legacy_denied = p_page.goto(legacy_tool_url, wait_until="domcontentloaded")
                    check(
                        "non-admin persona denied legacy Tools URL before redirect",
                        legacy_denied is not None and legacy_denied.status == 403
                        and p_page.locator('form[data-cpdd-form="teardown"]').count() == 0,
                        f"HTTP {legacy_denied.status if legacy_denied else 'no response'}",
                    )
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
            assert_rtl_and_no_overflow(rec_page, "Staff portal")
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
            fl_sel = acc_page.locator('[data-role="finance-location"]')
            acc_page.locator(f'[data-role="finance-location"] option[value="{fixture_meta["location_id"]}"]').wait_for(state="attached", timeout=30000)
            if fl_sel.input_value() != str(fixture_meta["location_id"]):
                fl_sel.select_option(str(fixture_meta["location_id"]))
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
            rfl_sel = rec2_page.locator('[data-role="finance-location"]')
            rec2_page.locator(f'[data-role="finance-location"] option[value="{fixture_meta["location_id"]}"]').wait_for(state="attached", timeout=30000)
            if rfl_sel.input_value() != str(fixture_meta["location_id"]):
                rfl_sel.select_option(str(fixture_meta["location_id"]))
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
