#!/usr/bin/env python3
"""rwp-first-practice.py — real-browser first-practice onboarding acceptance.

Repository-native Playwright harness (same isolation contract as rwp-acceptance.py).
Target: a clean WordPress administrator on an official CPMS ZIP, with zero Clinic
memberships, against the isolated MariaDB used by the calling workflow.

Does not seed manager/doctor fixtures before the one-shot bootstrap. Synthetic
practice data only. Passwords, tokens, nonces, and cookies are never written to
results or published evidence.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ADMIN_USER = os.environ["ADMIN_USER"]
ADMIN_PASS = os.environ["ADMIN_PASS"]
SUBSCRIBER_USER = os.environ["SUBSCRIBER_USER"]
SUBSCRIBER_PASS = os.environ["SUBSCRIBER_PASS"]
SECOND_ADMIN_USER = os.environ["SECOND_ADMIN_USER"]
SECOND_ADMIN_PASS = os.environ["SECOND_ADMIN_PASS"]
OUT = os.environ.get("OUT", "rwp-first-practice-out")
WP_DIR = os.environ.get("WP_DIR", "").strip()

os.makedirs(f"{OUT}/screenshots", exist_ok=True)
os.makedirs(f"{OUT}/logs", exist_ok=True)

results = []
console_errors = []
page_errors = []

CRITICAL_RE = re.compile(r"critical error|خطای بحرانی|wp-die-message", re.IGNORECASE)
SENSITIVE_EVIDENCE_RE = re.compile(
    r"(?i)([\w-]*(?:nonce|password|passwd|cookie|token|authorization|secret)[\w-]*\s*[=:]\s*)[^\s<>\"']+"
)

PRACTICE_NAME = "مطب پذیرش آزمایشی"
PRACTICE_PHONE = "021-55551234"
PRACTICE_ADDRESS = "تهران، خیابان آزمایش، پلاک ۱"
WIZARD_NAME = "مطب پذیرش پس از بوت‌استرپ"
WIZARD_PHONE = "021-77778888"
WIZARD_ADDRESS = "تهران، میدان ونک، پلاک ۲"

LOGIN_NAV_TIMEOUT_MS = 15000


def redact_evidence(value):
    return SENSITIVE_EVIDENCE_RE.sub(r"\g<1>[redacted]", str(value))


def check(name, ok, detail=""):
    safe_detail = redact_evidence(detail)[:1200]
    results.append((name, bool(ok), safe_detail))
    print(("PASS " if ok else "FAIL ") + name + (" — " + safe_detail if safe_detail else ""), flush=True)


def is_cpms_url(url):
    host = (url or "").split("?", 1)[0]
    if "admin.php" not in host and "plugins.php" not in host and "index.php" not in host:
        if "/wp-admin/" not in (url or ""):
            return False
    return "page=cpms" in (url or "") or "plugins.php" in (url or "") or "/wp-admin/" in (url or "")


def attach_watchers(page, tag):
    def on_console(m):
        if m.type == "error":
            loc = ""
            try:
                loc = m.location.get("url", "") or ""
            except Exception:
                loc = ""
            console_errors.append((tag, f"{m.text} <{loc}>", page.url))

    def on_pageerror(e):
        page_errors.append((tag, str(e), page.url))

    page.on("console", on_console)
    page.on("pageerror", on_pageerror)


def new_persona_context(browser, width=1440, height=900):
    """Fresh BrowserContext per persona (cookie-jar isolation — test infrastructure)."""
    return browser.new_context(viewport={"width": width, "height": height}, locale="fa-IR")


def login(page, user, password, tag):
    attach_watchers(page, tag)
    page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", user)
    page.fill("#user_pass", password)
    try:
        page.click("#wp-submit")
    except PlaywrightTimeoutError:
        check(f"{tag}.login", False, "submit timeout")
        return False
    try:
        page.wait_for_url(
            lambda u: "wp-login.php" not in u,
            wait_until="domcontentloaded",
            timeout=LOGIN_NAV_TIMEOUT_MS,
        )
    except PlaywrightTimeoutError:
        pass
    ok = "wp-login.php" not in page.url or "loggedout" in page.url
    check(f"{tag}.login", ok, "settled outside wp-login" if ok else "still on wp-login")
    return ok


def shot(page, name):
    page.screenshot(path=f"{OUT}/screenshots/{name}.png", full_page=True)


def write_safe_page_record(tag, name, status, critical_error, extra=None):
    record = {
        "screen": name,
        "http_status": int(status),
        "critical_error": bool(critical_error),
        "url_path": urlparse(page_url_safe(extra)).path if extra else "",
    }
    if extra and "query" in extra:
        record["query_keys"] = sorted(extra["query"])
    with open(f"{OUT}/logs/{tag}-{name}.json", "w") as f:
        json.dump(record, f, ensure_ascii=False, indent=2)


def page_url_safe(extra):
    if isinstance(extra, dict):
        return extra.get("url", "")
    return ""


def goto_admin(page, tag, path, shot_name):
    resp = page.goto(f"{BASE}/wp-admin/{path}", wait_until="domcontentloaded")
    page.wait_for_timeout(800)
    status = resp.status if resp else 0
    body = page.content()
    shot(page, shot_name)
    crit = CRITICAL_RE.search(body or "")
    parsed = urlparse(page.url)
    write_safe_page_record(
        tag,
        shot_name,
        status,
        crit is not None,
        {"url": page.url, "query": parse_qs(parsed.query)},
    )
    check(f"{tag}.{shot_name}.http200", status == 200, f"HTTP {status}")
    check(f"{tag}.{shot_name}.no_critical_error", crit is None, f"critical={crit is not None}")
    return status, body


def wp_eval(code, extra_env=None):
    if not WP_DIR:
        raise RuntimeError("wp_dir_unavailable")
    env = os.environ.copy()
    if extra_env:
        env.update(extra_env)
    eval_path = os.path.join(OUT, "logs", "wp-eval-snippet.php")
    os.makedirs(os.path.dirname(eval_path), exist_ok=True)
    with open(eval_path, "w", encoding="utf-8") as handle:
        handle.write("<?php\n" + code.strip() + "\n")
    try:
        result = subprocess.run(
            ["wp", "eval-file", eval_path, f"--path={WP_DIR}", "--allow-root"],
            capture_output=True,
            text=True,
            timeout=45,
            env=env,
        )
    except Exception as cop:
        raise RuntimeError("wp_cli_" + type(cop).__name__) from None
    if result.returncode != 0:
        err = redact_evidence((result.stderr or result.stdout or "")[:400])
        raise RuntimeError("wp_cli_failed:" + err)
    return result.stdout


def debug_log_path():
    if not WP_DIR:
        return ""
    return os.path.join(WP_DIR, "wp-content", "debug.log")


def uncaught_debug_excerpt(max_chars=800):
    """Return redacted fatal/uncaught lines from WP debug.log (empty if none)."""
    path = debug_log_path()
    if not path or not os.path.isfile(path):
        return ""
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as handle:
            text = handle.read()
    except OSError:
        return ""
    lines = [
        ln for ln in text.splitlines()
        if re.search(r"PHP Fatal error|Uncaught|There has been a critical error", ln)
    ]
    return redact_evidence("\n".join(lines[-12:]))[:max_chars]


def db_snapshot():
    code = r'''
$db = \ClinicCore\Bootstrap\App::db();
$clinic = $db->fetchRow(
    "SELECT id, name, phone, address, first_setup_claimed_at FROM " . $db->table("cpms_clinics") . " ORDER BY id ASC LIMIT 1"
);
$memberships = $db->fetchAll(
    "SELECT wp_user_id, role_key, status, scope_mode FROM " . $db->table("cpms_clinic_memberships") . " ORDER BY id ASC"
);
$audit = (int) $db->fetchValue(
    "SELECT COUNT(*) FROM " . $db->table("cpms_audit_logs") . " WHERE action = %s",
    ["PRACTICE_FIRST_SETUP"]
);
$clinicians = (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_clinicians"));
$clinicCount = (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_clinics"));
$admin = get_user_by("login", getenv("ADMIN_USER"));
$adminRoles = ($admin instanceof WP_User) ? array_values($admin->roles) : [];
$schema = \ClinicCore\Bootstrap\App::migrations()->currentVersion();
$payload = [
    "schema" => (string) $schema,
    "clinic_count" => $clinicCount,
    "clinic_id" => is_array($clinic) ? (int) $clinic["id"] : 0,
    "clinic_name" => is_array($clinic) ? (string) $clinic["name"] : "",
    "clinic_phone" => is_array($clinic) ? (string) ($clinic["phone"] ?? "") : "",
    "clinic_address" => is_array($clinic) ? (string) ($clinic["address"] ?? "") : "",
    "claimed" => is_array($clinic) && $clinic["first_setup_claimed_at"] !== null,
    "memberships" => is_array($memberships) ? $memberships : [],
    "membership_count" => is_array($memberships) ? count($memberships) : 0,
    "audit_count" => $audit,
    "clinician_count" => $clinicians,
    "admin_wp_roles" => $adminRoles,
    "admin_id" => ($admin instanceof WP_User) ? (int) $admin->ID : 0,
];
echo "FP_DB=" . wp_json_encode($payload) . PHP_EOL;
'''
    output = wp_eval(code)
    marker = next((line for line in output.splitlines() if line.startswith("FP_DB=")), "")
    if not marker:
        raise RuntimeError("db_snapshot_missing")
    data = json.loads(marker.split("=", 1)[1])
    if not isinstance(data, dict):
        raise RuntimeError("db_snapshot_invalid")
    return data


def assert_unclaimed(tag):
    snap = db_snapshot()
    check(f"{tag}.db.unclaimed", snap.get("claimed") is False, "first_setup_claimed_at must still be NULL")
    check(f"{tag}.db.zero_memberships", int(snap.get("membership_count") or 0) == 0, f"count={snap.get('membership_count')}")
    check(f"{tag}.db.zero_clinicians", int(snap.get("clinician_count") or 0) == 0, f"count={snap.get('clinician_count')}")
    check(f"{tag}.db.zero_first_setup_audit", int(snap.get("audit_count") or 0) == 0, f"count={snap.get('audit_count')}")
    return snap


def create_nonce_for(login_name):
    code = r'''
$login = (string) getenv("FP_NONCE_USER");
$user = get_user_by("login", $login);
if (!$user instanceof WP_User) { fwrite(STDERR, "missing user\n"); exit(1); }
wp_set_current_user((int) $user->ID);
echo "FP_NONCE=" . wp_create_nonce("cpms_first_practice_setup") . PHP_EOL;
echo "FP_UID=" . (int) $user->ID . PHP_EOL;
'''
    output = wp_eval(code, {"FP_NONCE_USER": login_name})
    nonce = next((line.split("=", 1)[1] for line in output.splitlines() if line.startswith("FP_NONCE=")), "")
    uid = next((line.split("=", 1)[1] for line in output.splitlines() if line.startswith("FP_UID=")), "0")
    if not nonce:
        raise RuntimeError("nonce_missing")
    return nonce, int(uid)


def post_admin_first_practice(page, nonce, clinic_id, confirm, name):
    """Authenticated browser POST to the real admin-post handler. Values are not logged."""
    js = """
    ([actionUrl, nonce, clinicId, confirm, name]) => {
      const f = document.createElement("form");
      f.method = "POST";
      f.action = actionUrl;
      const fields = {
        action: "cpms_first_practice_setup",
        _wpnonce: nonce,
        clinic_id: String(clinicId),
        practice_name: name,
        phone: "021-00000000",
        address: "synthetic"
      };
      if (confirm) { fields.confirm_identity = "1"; }
      for (const [k, v] of Object.entries(fields)) {
        const i = document.createElement("input");
        i.type = "hidden";
        i.name = k;
        i.value = v;
        f.appendChild(i);
      }
      document.body.appendChild(f);
      f.submit();
    }
    """
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        page.evaluate(js, [f"{BASE}/wp-admin/admin-post.php", nonce, int(clinic_id), bool(confirm), name])


def apply_fixture(kind):
    code = r'''
$kind = (string) getenv("FP_FIXTURE");
$db = \ClinicCore\Bootstrap\App::db();
$wpdb = $db->wpdb();
$clinic = $db->fetchRow("SELECT id FROM " . $db->table("cpms_clinics") . " ORDER BY id ASC LIMIT 1");
if (!is_array($clinic)) { fwrite(STDERR, "no clinic\n"); exit(1); }
$clinicId = (int) $clinic["id"];
$now = $db->nowUtcSql();

if ($kind === "reset_unclaimed") {
    $wpdb->query("DELETE FROM " . $db->table("cpms_clinic_memberships"));
    $wpdb->update($db->table("cpms_clinics"), ["first_setup_claimed_at" => null], ["id" => $clinicId]);
    echo "FP_FIXTURE=reset_unclaimed clinic=" . $clinicId . PHP_EOL;
} elseif ($kind === "suspended") {
    $wpdb->query("DELETE FROM " . $db->table("cpms_clinic_memberships"));
    $wpdb->update($db->table("cpms_clinics"), ["first_setup_claimed_at" => null], ["id" => $clinicId]);
    $staffLogin = (string) getenv("SECOND_ADMIN_USER");
    $staff = get_user_by("login", $staffLogin);
    if (!$staff instanceof WP_User) { fwrite(STDERR, "missing staff\n"); exit(1); }
    $memId = \ClinicCore\Bootstrap\App::membership_service()->create_membership(
        $clinicId,
        (int) $staff->ID,
        \ClinicCore\Auth\RolesAndCapabilities::ROLE_MANAGER
    );
    \ClinicCore\Bootstrap\App::membership_service()->suspend_membership((int) $memId);
    echo "FP_FIXTURE=suspended clinic=" . $clinicId . " membership=" . (int) $memId . PHP_EOL;
} elseif ($kind === "multiclinic") {
    $orgId = (int) $db->fetchValue(
        "SELECT organization_id FROM " . $db->table("cpms_clinics") . " WHERE id = %d",
        [$clinicId]
    );
    $slug = "fp-second-" . bin2hex(random_bytes(2));
    $wpdb->insert(
        $db->table("cpms_clinics"),
        [
            "organization_id" => $orgId,
            "name" => "FP Second Isolated",
            "slug" => $slug,
            "timezone" => "Asia/Tehran",
            "created_at" => $now,
            "updated_at" => $now,
        ]
    );
    echo "FP_FIXTURE=multiclinic second=" . (int) $wpdb->insert_id . PHP_EOL;
} else {
    fwrite(STDERR, "unknown fixture\n");
    exit(1);
}
'''
    return wp_eval(code, {"FP_FIXTURE": kind})


def body_has(body, needle):
    return needle in (body or "")


def query_page(url):
    return parse_qs(urlparse(url).query).get("page", [""])[0]


def query_step(url):
    return parse_qs(urlparse(url).query).get("step", [""])[0]


def denied_response(page, status, body):
    text = (body or "")
    path = urlparse(page.url).path
    return (
        status in (401, 403)
        or "دسترسی ندارید" in text
        or "not allowed to access this page" in text.lower()
        or "wp-die-message" in text
        or not path.startswith("/wp-admin/")
        or "wp-login.php" in page.url
    )


# ---------------------------------------------------------------------------
# Browser flow
# ---------------------------------------------------------------------------
with sync_playwright() as p:
    browser = p.chromium.launch()
    baseline = assert_unclaimed("precondition")
    clinic_id = int(baseline.get("clinic_id") or 0)
    check("precondition.single_clinic", clinic_id > 0 and int(baseline.get("clinic_count") or 0) == 1,
          f"clinic_id_set={clinic_id > 0}")
    check("precondition.schema_0027", baseline.get("schema") == "2026_10_13_0027",
          f"schema={baseline.get('schema')}")
    check("precondition.admin_is_administrator_only",
          baseline.get("admin_wp_roles") == ["administrator"],
          "wp-global roles recorded as names only")

    # --- Administrator: navigation, wizard-before, dedicated page ---
    admin_ctx = new_persona_context(browser)
    admin_page = admin_ctx.new_page()
    if login(admin_page, ADMIN_USER, ADMIN_PASS, "admin"):
        # The install notice is one-shot (transient). Capture it on the first
        # authenticated admin landing — a second navigation would consume it.
        if "/wp-admin/" not in urlparse(admin_page.url).path:
            admin_page.goto(f"{BASE}/wp-admin/index.php", wait_until="domcontentloaded")
            admin_page.wait_for_timeout(800)
        body = admin_page.content()
        shot(admin_page, "wp-admin-after-login")
        notice = admin_page.locator(".notice").filter(has_text="CPMS نصب شد")
        check("admin.onboarding_notice.visible",
              notice.count() >= 1 and body_has(body, "شروع راه‌اندازی"),
              "install notice rendered on first admin landing")
        check("admin.onboarding_notice.points_to_unified_wizard",
              notice.locator('a[href*="page=cpms-wizard"]').count() >= 1,
              "notice routes to the single visible setup wizard (bootstrap is embedded there)")
        check("admin.onboarding_notice.no_duplicate_setup_entry",
              notice.locator('a[href*="page=cpms-first-practice-setup"]').count() == 0,
              "notice must not advertise a second setup entry point")
        check("admin.after_login.no_critical_error",
              CRITICAL_RE.search(body or "") is None, "admin remains accessible")

        status, body = goto_admin(admin_page, "admin", "admin.php?page=cpms-dashboard", "cpms-dashboard")
        check("admin.dashboard.heading", body_has(body, "مدیریت مطب"), "dashboard rendered")
        dash_cta = admin_page.locator('.wrap a[href*="page=cpms-wizard"]')
        dash_fp = admin_page.locator('.wrap a[href*="page=cpms-first-practice-setup"]')
        check("admin.dashboard.setup_points_to_unified_wizard",
              dash_cta.count() >= 1,
              "dashboard setup CTA routes to the single visible setup wizard")
        check("admin.dashboard.no_duplicate_setup_entry",
              dash_fp.count() == 0,
              "dashboard body must not advertise a second setup entry point")

        status, body = goto_admin(admin_page, "admin", "plugins.php", "plugins")
        plugin_row = admin_page.locator("#the-list tr").filter(has_text="Clinic Practice Management")
        check("admin.plugin_action.setup_link",
              plugin_row.count() >= 1
              and plugin_row.locator('a[href*="page=cpms-wizard"]').count() >= 1,
              "plugin action link routes to the single visible setup wizard")
        check("admin.plugin_action.no_duplicate_setup_entry",
              plugin_row.count() >= 1
              and plugin_row.locator('a[href*="page=cpms-first-practice-setup"]').count() == 0,
              "plugin action must not advertise a second setup entry point")

        status, body = goto_admin(
            admin_page, "admin",
            "admin.php?page=cpms-wizard&step=clinic",
            "cpms-wizard-clinic-before",
        )
        check("admin.wizard_clinic.before.http_on_wizard",
              query_page(admin_page.url) == "cpms-wizard" and query_step(admin_page.url) == "clinic",
              "direct clinic URL stayed on the wizard")
        check("admin.wizard_clinic.before.first_practice_guidance",
              body_has(body, "راه‌اندازی اولین مطب") or body_has(body, "راه‌اندازی مطب من"),
              "first-practice guidance visible")
        check("admin.wizard_clinic.before.no_clinic_save_form",
              admin_page.locator('input[name="clinic_name"]').count() == 0,
              "misleading Clinic save form must not render")
        check("admin.wizard_clinic.before.no_permission_dead_end",
              "دسترسی لازم را ندارید" not in (body or "") and "اعتبارسنجی ناموفق" not in (body or ""),
              "no authorization dead end")
        check("admin.wizard_clinic.before.bootstrap_form_embedded",
              admin_page.locator('input[name="confirm_identity"]').count() == 1,
              "first-practice bootstrap form is embedded in the wizard (single journey)")

        status, body = goto_admin(
            admin_page, "admin",
            "admin.php?page=cpms-first-practice-setup",
            "cpms-first-practice-setup",
        )
        check("admin.first_practice.heading", body_has(body, "راه‌اندازی مطب من"), "dedicated page heading")
        form = admin_page.locator('form[method="post"][action*="admin-post.php"]')
        check("admin.first_practice.single_form", form.count() == 1, f"forms={form.count()}")
        check("admin.first_practice.action",
              form.locator('input[name="action"]').get_attribute("value") == "cpms_first_practice_setup",
              "admin-post action")
        check("admin.first_practice.nonce",
              form.locator('input[name="_wpnonce"]').count() == 1, "nonce present (value not recorded)")
        check("admin.first_practice.clinic_id",
              form.locator('input[name="clinic_id"]').count() == 1
              and (form.locator('input[name="clinic_id"]').get_attribute("value") or "") == str(clinic_id),
              "server-derived clinic selector")
        check("admin.first_practice.confirm_checkbox",
              form.locator('input[name="confirm_identity"]').count() == 1, "explicit confirmation")
        check("admin.first_practice.practice_fields",
              form.locator('input[name="practice_name"]').count() == 1
              and form.locator('input[name="phone"]').count() == 1
              and form.locator('input[name="address"]').count() == 1,
              "essential practice fields")
        check("admin.first_practice.one_shot_copy",
              body_has(body, "اقدام یک‌باره") or body_has(body, "مدیر مطب"),
              "one-shot manager guidance")

    # --- Unauthorized subscriber: independent context ---
    sub_ctx = new_persona_context(browser)
    sub_page = sub_ctx.new_page()
    if login(sub_page, SUBSCRIBER_USER, SUBSCRIBER_PASS, "subscriber"):
        before_c, before_p = len(console_errors), len(page_errors)
        resp = sub_page.goto(
            f"{BASE}/wp-admin/admin.php?page=cpms-first-practice-setup",
            wait_until="domcontentloaded",
        )
        sub_page.wait_for_timeout(600)
        status = resp.status if resp else 0
        body = sub_page.content()
        shot(sub_page, "subscriber-denied-first-practice")
        check("subscriber.first_practice.denied",
              denied_response(sub_page, status, body),
              f"HTTP {status}; nothing granted")
        check("subscriber.first_practice.no_bootstrap_form",
              sub_page.locator('input[name="confirm_identity"]').count() == 0,
              "unauthorized actor must not see the claim form")
        # Expected 403 / wp-die must not poison the CPMS console gate.
        del console_errors[before_c:]
        del page_errors[before_p:]
        assert_unclaimed("subscriber.after_denied")
    sub_page.close()
    sub_ctx.close()

    # --- Missing confirmation + invalid nonce (admin, still unclaimed) ---
    if "wp-login.php" not in admin_page.url:
        goto_admin(admin_page, "admin", "admin.php?page=cpms-first-practice-setup", "cpms-first-practice-before-negatives")
        form = admin_page.locator('form[method="post"][action*="admin-post.php"]')
        if form.count() == 1:
            admin_page.locator('input[name="confirm_identity"]').evaluate("el => el.removeAttribute('required')")
            admin_page.locator('input[name="practice_name"]').fill(PRACTICE_NAME)
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                form.locator('button[type="submit"]').click()
            after = admin_page.content()
            shot(admin_page, "admin-missing-confirmation")
            check("admin.missing_confirmation.notice",
                  body_has(after, "تأیید هویت مطب الزامی است"),
                  "server rejected missing confirmation")
            check("admin.missing_confirmation.stayed_on_setup",
                  query_page(admin_page.url) == "cpms-wizard",
                  "redirected back to the unified wizard")
            assert_unclaimed("admin.missing_confirmation")

            goto_admin(admin_page, "admin", "admin.php?page=cpms-first-practice-setup", "cpms-first-practice-before-bad-nonce")
            form = admin_page.locator('form[method="post"][action*="admin-post.php"]')
            admin_page.locator('input[name="_wpnonce"]').evaluate("el => { el.value = 'invalid-nonce-value'; }")
            admin_page.locator('input[name="confirm_identity"]').check()
            admin_page.locator('input[name="practice_name"]').fill(PRACTICE_NAME)
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                form.locator('button[type="submit"]').click()
            after = admin_page.content()
            shot(admin_page, "admin-invalid-nonce")
            check("admin.invalid_nonce.notice",
                  body_has(after, "درخواست نامعتبر است"),
                  "server rejected invalid nonce")
            check("admin.invalid_nonce.stayed_on_setup",
                  query_page(admin_page.url) == "cpms-wizard",
                  "redirected back to the unified wizard")
            assert_unclaimed("admin.invalid_nonce")

        # --- Real successful admin-post submit ---
        goto_admin(admin_page, "admin", "admin.php?page=cpms-first-practice-setup", "cpms-first-practice-before-claim")
        form = admin_page.locator('form[method="post"][action*="admin-post.php"]')
        check("admin.claim.form_ready", form.count() == 1, f"forms={form.count()}")
        if form.count() == 1:
            admin_page.locator('input[name="confirm_identity"]').check()
            admin_page.locator('input[name="practice_name"]').fill(PRACTICE_NAME)
            admin_page.locator('input[name="phone"]').fill(PRACTICE_PHONE)
            admin_page.locator('input[name="address"]').fill(PRACTICE_ADDRESS)
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                form.locator('button[type="submit"]').click()
            shot(admin_page, "admin-after-claim")
            landed = parse_qs(urlparse(admin_page.url).query)
            check("admin.claim.redirect_staff",
                  landed.get("page") == ["cpms-staff"] and landed.get("cpms_first_setup") == ["done"],
                  "admin-post redirected to staff with done marker")
            check("admin.claim.success_notice",
                  body_has(admin_page.content(), "با موفقیت راه‌اندازی شد")
                  or query_page(admin_page.url) == "cpms-staff",
                  "bootstrap continuation")
            check("admin.claim.no_permission_error",
                  "دسترسی لازم را ندارید" not in admin_page.content()
                  and "اعتبارسنجی ناموفق" not in admin_page.content(),
                  "no permission error after claim")

        claimed = db_snapshot()
        check("admin.claim.db.claimed", claimed.get("claimed") is True, "first-setup marker consumed")
        check("admin.claim.db.one_membership", int(claimed.get("membership_count") or 0) == 1,
              f"count={claimed.get('membership_count')}")
        memberships = claimed.get("memberships") or []
        mem = memberships[0] if memberships else {}
        check("admin.claim.db.manager_role",
              str(mem.get("role_key") or "") == "cpms_manager", "built-in manager preset")
        check("admin.claim.db.active_status",
              str(mem.get("status") or "") == "active", "membership active")
        check("admin.claim.db.clinic_scope",
              str(mem.get("scope_mode") or "") == "clinic", "clinic scope")
        check("admin.claim.db.actor_is_admin",
              int(mem.get("wp_user_id") or 0) == int(claimed.get("admin_id") or -1),
              "membership belongs to the installing administrator")
        check("admin.claim.db.practice_name",
              claimed.get("clinic_name") == PRACTICE_NAME, "synthetic name persisted")
        check("admin.claim.db.practice_phone",
              claimed.get("clinic_phone") == PRACTICE_PHONE, "synthetic phone persisted")
        check("admin.claim.db.practice_address",
              claimed.get("clinic_address") == PRACTICE_ADDRESS, "synthetic address persisted")
        check("admin.claim.db.one_audit", int(claimed.get("audit_count") or 0) == 1, "mandatory audit")
        check("admin.claim.db.no_clinician", int(claimed.get("clinician_count") or 0) == 0,
              "no automatic clinician")
        check("admin.claim.db.wp_role_unchanged",
              claimed.get("admin_wp_roles") == ["administrator"],
              "WordPress-global role unchanged")

        # --- Post-bootstrap wizard clinic ---
        status, body = goto_admin(
            admin_page, "admin",
            "admin.php?page=cpms-wizard&step=clinic",
            "cpms-wizard-clinic-after",
        )
        check("admin.wizard_clinic.after.on_clinic_step",
              query_page(admin_page.url) == "cpms-wizard" and query_step(admin_page.url) == "clinic",
              "clinic step loaded")
        check("admin.wizard_clinic.after.profile_form",
              admin_page.locator('input[name="clinic_name"]').count() == 1,
              "clinic profile form rendered")
        check("admin.wizard_clinic.after.no_permission_error",
              "دسترسی لازم را ندارید" not in (body or ""),
              "no permission error")
        loaded_name = admin_page.locator('input[name="clinic_name"]').input_value() if admin_page.locator('input[name="clinic_name"]').count() else ""
        check("admin.wizard_clinic.after.loads_claimed_name",
              loaded_name == PRACTICE_NAME, "canonical name loaded (value not logged)")
        clinic_form = admin_page.locator('form[method="post"][action*="admin-post.php"]')
        if clinic_form.count() == 1 and admin_page.locator('input[name="clinic_name"]').count() == 1:
            clinic_form.locator('input[name="clinic_name"]').fill(WIZARD_NAME)
            clinic_form.locator('input[name="clinic_address"]').fill(WIZARD_ADDRESS)
            clinic_form.locator('input[name="clinic_phone"]').fill(WIZARD_PHONE)
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                clinic_form.locator('button[type="submit"]').click()
            after_save = admin_page.content()
            shot(admin_page, "cpms-wizard-clinic-saved")
            check("admin.wizard_clinic.after.save_success",
                  body_has(after_save, "ذخیره شد."), "saving succeeded")
            check("admin.wizard_clinic.after.no_permission_on_save",
                  "دسترسی لازم را ندارید" not in after_save, "no permission error on save")
            check("admin.wizard_clinic.after.progressed",
                  admin_page.locator('[data-cpms-wizard-step="booking"][aria-current="step"]').count() == 1
                  or query_step(admin_page.url) == "booking",
                  "wizard progressed")
            check("admin.wizard_clinic.after.no_redirect_loop",
                  query_page(admin_page.url) == "cpms-wizard",
                  "stayed on wizard after POST")
            # Revisit clinic step — no loop, persisted values.
            status, body = goto_admin(
                admin_page, "admin",
                "admin.php?page=cpms-wizard&step=clinic",
                "cpms-wizard-clinic-revisit",
            )
            check("admin.wizard_clinic.revisit.no_loop",
                  query_page(admin_page.url) == "cpms-wizard" and query_step(admin_page.url) == "clinic",
                  "revisit did not loop")
            persisted = (
                admin_page.locator('input[name="clinic_name"]').input_value() == WIZARD_NAME
                and admin_page.locator('input[name="clinic_phone"]').input_value() == WIZARD_PHONE
                and admin_page.locator('input[name="clinic_address"]').input_value() == WIZARD_ADDRESS
            ) if admin_page.locator('input[name="clinic_name"]').count() else False
            check("admin.wizard_clinic.revisit.persisted", persisted, "canonical profile persisted")

        # --- Adaptive onboarding: facility choice drives doctors step ---
        goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=facility", "cpms-wizard-facility-after")
        check("admin.adaptive.facility_options",
              admin_page.locator('input[name="facility_type"]').count() == 12,
              "all twelve facility types are real radio choices")
        fac_form = admin_page.locator('form:has(input[name="facility_type"])')
        if fac_form.count() >= 1:
            fac_form.locator('input[name="facility_type"][value="doctor_practice"]').check()
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                fac_form.locator('button[type="submit"]').first.click()
            goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=doctors", "cpms-wizard-doctors-after")
            check("admin.adaptive.doctors_intended_field",
                  admin_page.locator('input[name="intended_clinicians"]').count() == 1,
                  "intended clinician count is asked in the doctors step")
            doc_form = admin_page.locator('form:has(input[name="intended_clinicians"])')
            if doc_form.count() >= 1:
                doc_form.locator('input[name="intended_clinicians"]').fill("1")
                with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                    doc_form.locator('button[type="submit"]').first.click()
                # No clinician registered yet: verified progression must
                # keep the user on the doctors step with a Persian error.
                check("admin.adaptive.incomplete_doctors_blocks",
                      query_step(admin_page.url) == "doctors" and "هنوز کامل نیست" in admin_page.content(),
                      "declaring a number alone does not complete the doctors step")
            # Interruption/resume: revisiting the wizard keeps the facility choice.
            goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=facility", "cpms-wizard-facility-resume")
            check("admin.adaptive.facility_resumes",
                  admin_page.locator('input[name="facility_type"][value="doctor_practice"]:checked').count() == 1,
                  "facility selection resumes after interruption")

        # Replay via UI: form must be gone for the claiming admin.
        status, body = goto_admin(
            admin_page, "admin",
            "admin.php?page=cpms-first-practice-setup",
            "cpms-first-practice-after-claim",
        )
        check("admin.replay.form_absent",
              admin_page.locator('input[name="confirm_identity"]').count() == 0,
              "one-shot form consumed")
        check("admin.replay.already_setup_copy",
              body_has(body, "قبلاً راه‌اندازی") or body_has(body, "در دسترس نیست"),
              "already-setup guidance")

    admin_page.close()
    admin_ctx.close()

    # --- Second administrator replay (independent context) ---
    admin2_ctx = new_persona_context(browser)
    admin2_page = admin2_ctx.new_page()
    if login(admin2_page, SECOND_ADMIN_USER, SECOND_ADMIN_PASS, "admin2"):
        status, body = goto_admin(
            admin2_page, "admin2",
            "admin.php?page=cpms-first-practice-setup",
            "admin2-first-practice-replay",
        )
        check("admin2.replay.form_absent",
              admin2_page.locator('input[name="confirm_identity"]').count() == 0,
              "second admin cannot reopen the form")
        try:
            nonce, _uid = create_nonce_for(SECOND_ADMIN_USER)
            before = db_snapshot()
            post_admin_first_practice(admin2_page, nonce, clinic_id, True, "مطب مهاجم دوم")
            shot(admin2_page, "admin2-replay-post")
            after = db_snapshot()
            check("admin2.replay.post_denied_no_new_membership",
                  int(after.get("membership_count") or 0) == int(before.get("membership_count") or 0),
                  "replay provisioned nothing")
            check("admin2.replay.name_unchanged",
                  after.get("clinic_name") == before.get("clinic_name"),
                  "practice name unchanged")
        except Exception as exc:
            check("admin2.replay.post_denied_no_new_membership", False, type(exc).__name__)
    admin2_page.close()
    admin2_ctx.close()

    # --- Suspended-membership takeover (isolated fixture, after evidence captured) ---
    try:
        apply_fixture("suspended")
        sus_ctx = new_persona_context(browser)
        sus_page = sus_ctx.new_page()
        if login(sus_page, ADMIN_USER, ADMIN_PASS, "admin-suspended"):
            status, body = goto_admin(
                sus_page, "admin-suspended",
                "admin.php?page=cpms-first-practice-setup",
                "suspended-first-practice",
            )
            check("suspended.form_absent",
                  sus_page.locator('input[name="confirm_identity"]').count() == 0,
                  "suspended membership blocks bootstrap UI")
            nonce, _uid = create_nonce_for(ADMIN_USER)
            before = db_snapshot()
            post_admin_first_practice(sus_page, nonce, clinic_id, True, "مطب با عضویت معلق")
            shot(sus_page, "suspended-post")
            after = db_snapshot()
            check("suspended.takeover_denied",
                  int(after.get("membership_count") or 0) == int(before.get("membership_count") or 0),
                  "no additional membership")
            check("suspended.admin_wp_role_unchanged",
                  after.get("admin_wp_roles") == ["administrator"],
                  "WP role unchanged")
        sus_page.close()
        sus_ctx.close()
    except Exception as exc:
        check("suspended.takeover_denied", False, type(exc).__name__)

    # --- Multi-Clinic takeover (isolated fixture) ---
    # A WordPress recovery/critical-error screen is NOT fail-closed authorization.
    try:
        apply_fixture("reset_unclaimed")
        apply_fixture("multiclinic")
        multi_ctx = new_persona_context(browser)
        multi_page = multi_ctx.new_page()
        if login(multi_page, ADMIN_USER, ADMIN_PASS, "admin-multiclinic"):
            for path, shot_name in (
                ("index.php", "multiclinic-wp-admin"),
                ("plugins.php", "multiclinic-plugins"),
                ("admin.php?page=cpms-dashboard", "multiclinic-dashboard"),
                ("admin.php?page=cpms-wizard&step=clinic", "multiclinic-wizard-clinic"),
                ("admin.php?page=cpms-first-practice-setup", "multiclinic-first-practice"),
            ):
                status, body = goto_admin(multi_page, "admin-multiclinic", path, shot_name)
                excerpt = uncaught_debug_excerpt()
                check(
                    f"admin-multiclinic.{shot_name}.no_uncaught_php",
                    excerpt == "",
                    excerpt or "debug.log has no uncaught/fatal lines",
                )
                check(
                    f"admin-multiclinic.{shot_name}.no_recovery_screen",
                    "There has been a critical error" not in (body or "")
                    and "خطای بحرانی" not in (body or ""),
                    f"HTTP {status}",
                )
            status, body = goto_admin(
                multi_page, "admin-multiclinic",
                "admin.php?page=cpms-first-practice-setup",
                "multiclinic-first-practice-feedback",
            )
            check("multiclinic.form_absent",
                  multi_page.locator('input[name="confirm_identity"]').count() == 0,
                  "multi-clinic installation is never bootstrap-claimable")
            check("multiclinic.no_clinic_save_form",
                  multi_page.locator('input[name="clinic_name"]').count() == 0,
                  "no Clinic save form on the bootstrap page")
            check(
                "multiclinic.actionable_authorization_feedback",
                body_has(body, "راه‌اندازی اولیه در دسترس نیست")
                or multi_page.locator('[data-cpms-scope-required="1"]').count() >= 1,
                "clear authorization feedback, not a crash",
            )
            nonce, _uid = create_nonce_for(ADMIN_USER)
            before = db_snapshot()
            post_admin_first_practice(multi_page, nonce, clinic_id, True, "مطب چندکلینیکی")
            shot(multi_page, "multiclinic-post")
            after = db_snapshot()
            check("multiclinic.takeover_denied",
                  int(after.get("membership_count") or 0) == 0
                  and int(before.get("membership_count") or 0) == 0,
                  "no first-row fallback membership")
        multi_page.close()
        multi_ctx.close()
    except Exception as cop:
        check("multiclinic.takeover_denied", False, type(cop).__name__)

    browser.close()

# ---------------------------------------------------------------------------
# Diagnostics (redacted)
# ---------------------------------------------------------------------------
with open(f"{OUT}/logs/browser-console-errors.log", "w") as f:
    for tag, msg, url in console_errors:
        f.write(f"[{tag}] {redact_evidence(msg)} <{redact_evidence(url)}>\n")
with open(f"{OUT}/logs/browser-page-errors.log", "w") as f:
    for tag, msg, url in page_errors:
        f.write(f"[{tag}] {redact_evidence(msg)} <{redact_evidence(url)}>\n")

cm_errors = [e for e in console_errors if is_cpms_url(e[2])]
pg_errors = [e for e in page_errors if is_cpms_url(e[2])]
check("browser.no_console_errors", len(cm_errors) == 0,
      f"{len(cm_errors)} console errors on CPMS/admin pages (messages suppressed)")
check("browser.no_page_errors", len(pg_errors) == 0,
      f"{len(pg_errors)} page errors on CPMS/admin pages (messages suppressed)")

failed = [r for r in results if not r[1]]
with open(f"{OUT}/results.json", "w") as f:
    json.dump(
        {
            "checks": [{"name": n, "ok": ok, "detail": d} for n, ok, d in results],
            "failed": len(failed),
            "passed": len(results) - len(failed),
            "console_errors_on_cpms": len(cm_errors),
            "page_errors_on_cpms": len(pg_errors),
        },
        f,
        ensure_ascii=False,
        indent=2,
    )

print(f"\n== rwp-first-practice: {len(results) - len(failed)} passed / {len(failed)} failed ==", flush=True)
sys.exit(1 if failed else 0)
