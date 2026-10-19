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
// Raw SQL against the first Clinic row only — deliberately NOT
// App::settings()/App::scope(), which throw CLINIC_SCOPE_REQUIRED when
// more than one Clinic exists (exactly the ambiguous multi-Clinic
// persona this very script also exercises later); db_snapshot() must
// stay usable at every point in the script regardless of Clinic count.
$firstClinicId = is_array($clinic) ? (int) $clinic["id"] : 0;
$completedRaw = $firstClinicId > 0 ? $db->fetchValue(
    "SELECT value_json FROM " . $db->table("cpms_settings") . " WHERE clinic_id = %d AND `key` = %s",
    [$firstClinicId, "setup.completed"]
) : null;
$setupCompleted = $completedRaw !== null && json_decode((string) $completedRaw, true) === true;
$payload = [
    "schema" => (string) $schema,
    "setup_completed" => $setupCompleted,
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
} elseif ($kind === "multiclinic_manager") {
    // PR #253: explicit Clinic selection evidence. Requires the multiclinic fixture.
    $second = $db->fetchRow(
        "SELECT id FROM " . $db->table("cpms_clinics") . " WHERE id <> %d ORDER BY id DESC LIMIT 1",
        [$clinicId]
    );
    if (!is_array($second)) { fwrite(STDERR, "no second clinic\n"); exit(1); }
    $secondId = (int) $second["id"];
    $staff = get_user_by("login", (string) getenv("SECOND_ADMIN_USER"));
    if (!$staff instanceof WP_User) { fwrite(STDERR, "missing staff\n"); exit(1); }
    $svc = \ClinicCore\Bootstrap\App::membership_service();
    foreach ([$clinicId, $secondId] as $cid) {
        if ($svc->membership_for($cid, (int) $staff->ID) === null) {
            $svc->create_membership($cid, (int) $staff->ID, \ClinicCore\Auth\RolesAndCapabilities::ROLE_MANAGER);
        }
    }
    foreach ([[$clinicId, "FP Dr First Clinic"], [$secondId, "FP Dr Second Clinic"]] as $row) {
        $wpdb->insert(
            $db->table("cpms_clinicians"),
            ["clinic_id" => $row[0], "full_name" => $row[1], "is_active" => 1, "created_at" => $now, "updated_at" => $now]
        );
    }
    echo "FP_FIXTURE=multiclinic_manager first=" . $clinicId . " second=" . $secondId . PHP_EOL;
} elseif ($kind === "facility") {
    // PR #253: top-down Organization facility setup evidence (hospital + medical
    // building). Two fresh Organizations whose ONLY manager is SECOND_ADMIN_USER,
    // plus plain WordPress users chosen explicitly (by login) inside the journey.
    $mgr = get_user_by("login", (string) getenv("SECOND_ADMIN_USER"));
    if (!$mgr instanceof WP_User) { fwrite(STDERR, "missing manager\n"); exit(1); }
    $out = [];
    foreach (["hospital" => "FP Hospital Org", "building" => "FP Medical Building Org"] as $key => $label) {
        $wpdb->insert($db->table("cpms_organizations"), ["name" => $label, "slug" => "fp-" . $key . "-" . bin2hex(random_bytes(3)), "status" => "active", "created_at" => $now, "updated_at" => $now]);
        $orgId = (int) $wpdb->insert_id;
        if ($orgId <= 0) { fwrite(STDERR, "organization insert failed\n"); exit(1); }
        $wpdb->insert($db->table("cpms_organization_memberships"), ["organization_id" => $orgId, "wp_user_id" => (int) $mgr->ID, "role_key" => "organization_manager", "status" => "active", "created_by_wp_user_id" => (int) $mgr->ID, "created_at" => $now, "updated_at" => $now]);
        $out[] = $key . "=" . $orgId;
    }
    foreach (["doctor" => "fpfacdoc", "mgra" => "fpfacmgra", "mgrb" => "fpfacmgrb"] as $key => $prefix) {
        $login = $prefix . bin2hex(random_bytes(2));
        $uid = wp_create_user($login, wp_generate_password(24), $login . "@acc.local");
        if (is_wp_error($uid)) { fwrite(STDERR, "user create failed\n"); exit(1); }
        $out[] = $key . "=" . $login;
    }
    echo "FP_FIXTURE=facility " . implode(" ", $out) . PHP_EOL;
} else {
    fwrite(STDERR, "unknown fixture\n");
    exit(1);
}
'''
    return wp_eval(code, {"FP_FIXTURE": kind})


def facility_db_facts(org_id):
    """Live DB facts of one Organization created through the facility journey."""
    code = r'''
$org = (int) getenv("FP_ORG");
$db = \ClinicCore\Bootstrap\App::db();
$actor = get_user_by("login", (string) getenv("SECOND_ADMIN_USER"));
$actorId = $actor instanceof WP_User ? (int) $actor->ID : 0;
$clinics = $db->fetchAll("SELECT id, status FROM " . $db->table("cpms_clinics") . " WHERE organization_id = %d", [$org]);
$active = 0; $locations = 0; $schedules = 0; $managers = 0; $actorMembers = 0;
foreach ($clinics as $c) {
    $cid = (int) $c["id"];
    if ((string) $c["status"] === "active") { $active++; }
    $locations += (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_locations") . " WHERE clinic_id = %d", [$cid]);
    $schedules += (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_schedule") . " WHERE clinic_id = %d", [$cid]);
    $managers += (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_clinic_memberships") . " WHERE clinic_id = %d AND role_key = %s AND status = %s", [$cid, "cpms_manager", "active"]);
    $actorMembers += (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_clinic_memberships") . " WHERE clinic_id = %d AND wp_user_id = %d", [$cid, $actorId]);
}
$profiles = (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_clinicians") . " c INNER JOIN " . $db->table("cpms_clinics") . " k ON k.id = c.clinic_id WHERE k.organization_id = %d", [$org]);
$scheduleClinics = (int) $db->fetchValue("SELECT COUNT(DISTINCT s.clinic_id) FROM " . $db->table("cpms_schedule") . " s INNER JOIN " . $db->table("cpms_clinics") . " k ON k.id = s.clinic_id WHERE k.organization_id = %d", [$org]);
echo "clinics=" . count($clinics) . " active=" . $active . " locations=" . $locations . " schedules=" . $schedules . " schedule_clinics=" . $scheduleClinics . " managers=" . $managers . " actor_members=" . $actorMembers . " profiles=" . $profiles . PHP_EOL;
'''
    out = wp_eval(code, {"FP_ORG": str(org_id)})
    return dict(tok.split("=", 1) for tok in (out or "").split() if "=" in tok)


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


FACILITY_PAGE = "admin.php?page=cpms-facility-setup"


def facility_url(org_id, step):
    return f"{FACILITY_PAGE}&organization_id={org_id}&step={step}"


def facility_submit(page, tag, op, shot_name):
    """Submit one facility form (POST → admin-post → PRG) and return the notice kind."""
    form = page.locator(f'form[data-cpms-facility-form="{op}"]')
    if form.count() != 1:
        check(f"{tag}.{shot_name}.form_present", False, f"op={op} forms={form.count()}")
        return ""
    with page.expect_navigation(wait_until="domcontentloaded"):
        form.locator('button[type="submit"]').click()
    page.wait_for_timeout(300)
    shot(page, shot_name)
    body = page.content()
    check(f"{tag}.{shot_name}.no_critical_error", CRITICAL_RE.search(body or "") is None, "after PRG")
    notice = page.locator("[data-cpms-facility-notice]")
    return (notice.first.get_attribute("data-cpms-facility-notice") or "") if notice.count() else ""


def facility_option_values(page, selector):
    values = []
    options = page.locator(f"{selector} option")
    for i in range(options.count()):
        value = options.nth(i).get_attribute("value") or ""
        if value:
            values.append(value)
    return values


def run_hospital_facility_journey(page, tag, org, fac):
    goto_admin(page, tag, FACILITY_PAGE, "facility-org-choice")
    org_select = page.locator("#cpms_facility_org")
    check(f"{tag}.org_choice.explicit_no_default", org_select.count() == 1 and org_select.input_value() == "",
          "Organization selector present without a default")

    goto_admin(page, tag, facility_url(org, "identity"), "facility-hospital-identity")
    page.check('input[name="facility_type"][value="hospital"]')
    page.fill("#cpms_facility_display_name", "بیمارستان آزمون پذیرش")
    check(f"{tag}.hospital.start", facility_submit(page, tag, "start", "facility-hospital-started") == "ok",
          "facility type chosen first + Organization identity saved")
    check(f"{tag}.hospital.flow", page.locator('[data-cpms-facility-flow="hospital_network"]').count() == 1,
          "hospital outpatient flow resolved from the facility type")
    check(f"{tag}.hospital.inpatient_unsupported", page.locator('[data-cpms-inpatient-unsupported="1"]').count() == 1,
          "inpatient/HIS explicitly unsupported")
    page.check('input[name="outpatient_ack"]')
    check(f"{tag}.hospital.outpatient_ack", facility_submit(page, tag, "ack", "facility-hospital-ack") == "ok",
          "explicit outpatient-only acknowledgment")

    for label, address in (("ساختمان شرقی", "تهران، خیابان یکم"), ("ساختمان غربی", "تهران، خیابان دوم")):
        page.fill("#cpms_facility_site_label", label)
        page.fill("#cpms_facility_site_address", address)
        facility_submit(page, tag, "site", "facility-hospital-site")
    check(f"{tag}.hospital.two_sites", page.locator('[data-cpms-facility-sites="2"]').count() == 1,
          "two physical sites recorded as descriptive metadata")

    goto_admin(page, tag, facility_url(org, "clinics"), "facility-hospital-clinics")
    for index, name in ((1, "درمانگاه قلب"), (2, "درمانگاه پوست")):
        page.fill("#cpms_facility_clinic_name", name)
        page.select_option("#cpms_facility_clinic_site", index=index)
        page.select_option("#cpms_facility_timezone", "Asia/Tehran")
        check(f"{tag}.hospital.clinic_{index}", facility_submit(page, tag, "clinic", f"facility-hospital-clinic-{index}") == "ok",
              "outpatient Clinic created with its primary Location (held until activation)")
    check(f"{tag}.hospital.two_clinics", page.locator('[data-cpms-facility-clinics="2"]').count() == 1,
          "two outpatient Clinics in the Organization")

    goto_admin(page, tag, facility_url(org, "managers"), "facility-hospital-managers")
    keys = facility_option_values(page, "#cpms_facility_manager_clinic")
    check(f"{tag}.hospital.clinic_choice_no_default", len(keys) == 2 and page.locator("#cpms_facility_manager_clinic").input_value() == "",
          f"explicit Clinic choice without default ({len(keys)} Clinics)")
    for index in (1, 2):
        page.select_option("#cpms_facility_manager_clinic", index=index)
        page.fill("#cpms_facility_user_login", SECOND_ADMIN_USER)
        check(f"{tag}.hospital.manager_{index}", facility_submit(page, tag, "manager", f"facility-hospital-manager-{index}") == "ok",
              "explicit, audited Clinic manager designation")

    goto_admin(page, tag, facility_url(org, "rooms"), "facility-hospital-rooms")
    for index, name in ((1, "اتاق ویزیت قلب ۱"), (2, "اتاق ویزیت پوست ۱")):
        page.select_option("#cpms_facility_room_clinic", index=index)
        page.fill("#cpms_facility_room_name", name)
        page.select_option("#cpms_facility_room_site", index=index)
        check(f"{tag}.hospital.room_{index}", facility_submit(page, tag, "room", f"facility-hospital-room-{index}") == "ok",
              "consultation room created as a Location of that Clinic")

    goto_admin(page, tag, facility_url(org, "clinicians"), "facility-hospital-clinicians")
    for index, key in ((1, keys[0] if keys else ""), (2, keys[1] if len(keys) > 1 else "")):
        page.select_option("#cpms_facility_clinician_clinic", index=index)
        page.fill("#cpms_facility_user_login", fac.get("doctor", ""))
        page.fill("#cpms_facility_full_name", "دکتر مشترک پذیرش")
        page.check(f'[data-cpms-room-group="{key}"] input[value="primary"]')
        check(f"{tag}.hospital.clinician_{index}", facility_submit(page, tag, "clinician", f"facility-hospital-clinician-{index}") == "ok",
              "clinician assigned to this Clinic and its Location (one profile, explicit membership)")

    goto_admin(page, tag, facility_url(org, "staff"), "facility-hospital-staff")
    check(f"{tag}.hospital.staff_handoff", page.locator('a[href*="page=cpms-staff"][href*="clinic_id="]').count() == 2,
          "staff roles/permissions handed off per explicitly selected Clinic")

    goto_admin(page, tag, facility_url(org, "schedules"), "facility-hospital-schedules")
    for index, day, start, end in ((1, "1", "08:00", "12:00"), (2, "3", "14:00", "18:00")):
        page.select_option("#cpms_facility_schedule_clinic", index=index)
        page.select_option("#cpms_facility_schedule_clinician", index=1)
        page.select_option("#cpms_facility_schedule_room", "primary")
        page.select_option("#cpms_facility_schedule_day", day)
        page.fill("#cpms_facility_schedule_start", start)
        page.fill("#cpms_facility_schedule_end", end)
        check(f"{tag}.hospital.schedule_{index}", facility_submit(page, tag, "schedule", f"facility-hospital-schedule-{index}") == "ok",
              "separate weekly schedule for the shared clinician in this Clinic")

    goto_admin(page, tag, facility_url(org, "booking"), "facility-hospital-booking")
    for index in (1, 2):
        page.select_option("#cpms_facility_booking_clinic", index=index)
        check(f"{tag}.hospital.booking_{index}", facility_submit(page, tag, "booking", f"facility-hospital-booking-{index}") == "ok",
              "per-Clinic booking policy and capacity")

    goto_admin(page, tag, facility_url(org, "review"), "facility-hospital-review")
    check(f"{tag}.hospital.review_ready", page.locator('[data-cpms-facility-review="ready"]').count() == 1,
          "consolidated review computed from live rows is ready")
    check(f"{tag}.hospital.review_two_clinics", page.locator("[data-cpms-review-clinic]").count() == 2, "both Clinics reviewed")
    page.check('input[name="confirm"]')
    check(f"{tag}.hospital.activate", facility_submit(page, tag, "activate", "facility-hospital-activated") == "ok",
          "explicit activation after review")
    check(f"{tag}.hospital.activated_marker", page.locator('[data-cpms-facility-activated="1"]').count() == 1, "activation recorded")


def run_building_facility_journey(page, tag, org, fac):
    goto_admin(page, tag, facility_url(org, "identity"), "facility-building-identity")
    page.check('input[name="facility_type"][value="other"]')
    page.select_option("#cpms_facility_structure", "medical_building")
    page.fill("#cpms_facility_display_name", "مجتمع پزشکی آزمون پذیرش")
    check(f"{tag}.building.start", facility_submit(page, tag, "start", "facility-building-started") == "ok",
          "medical building chosen explicitly")
    check(f"{tag}.building.flow", page.locator('[data-cpms-facility-flow="medical_building"]').count() == 1, "medical building flow")

    goto_admin(page, tag, facility_url(org, "sites"), "facility-building-sites")
    page.fill("#cpms_facility_site_label", "ساختمان مجتمع")
    page.fill("#cpms_facility_site_address", "اصفهان، خیابان آزمون")
    facility_submit(page, tag, "site", "facility-building-site")

    goto_admin(page, tag, facility_url(org, "clinics"), "facility-building-clinics")
    for index, name in ((1, "مطب دندانپزشکی مستقل"), (2, "مطب چشم‌پزشکی مستقل")):
        page.fill("#cpms_facility_clinic_name", name)
        page.select_option("#cpms_facility_clinic_site", index=1)
        page.select_option("#cpms_facility_timezone", "Asia/Tehran")
        check(f"{tag}.building.clinic_{index}", facility_submit(page, tag, "clinic", f"facility-building-clinic-{index}") == "ok",
              "independent Clinic created")

    goto_admin(page, tag, facility_url(org, "managers"), "facility-building-managers")
    for index, login_key in ((1, "mgra"), (2, "mgrb")):
        page.select_option("#cpms_facility_manager_clinic", index=index)
        page.fill("#cpms_facility_user_login", fac.get(login_key, ""))
        check(f"{tag}.building.manager_{index}", facility_submit(page, tag, "manager", f"facility-building-manager-{index}") == "ok",
              "distinct explicit manager per independent Clinic")

    goto_admin(page, tag, facility_url(org, "rooms"), "facility-building-rooms")
    page.select_option("#cpms_facility_room_clinic", index=1)
    page.fill("#cpms_facility_room_name", "اتاق بدون مجوز")
    page.select_option("#cpms_facility_room_site", index=1)
    check(f"{tag}.building.org_manager_cannot_configure_clinic",
          facility_submit(page, tag, "room", "facility-building-room-denied") == "error",
          "Organization authority alone cannot configure an independent Clinic")

    goto_admin(page, tag, facility_url(org, "review"), "facility-building-review")
    check(f"{tag}.building.review_ready", page.locator('[data-cpms-facility-review="ready"]').count() == 1, "review ready")
    check(f"{tag}.building.no_clinic_access_notice", page.locator("[data-cpms-review-no-clinic-access]").count() == 2,
          "no silent Clinic access for the Organization Manager")
    page.check('input[name="confirm"]')
    check(f"{tag}.building.activate", facility_submit(page, tag, "activate", "facility-building-activated") == "ok", "activation")


# ---------------------------------------------------------------------------
# Browser flow
# ---------------------------------------------------------------------------
with sync_playwright() as p:
    browser = p.chromium.launch()
    baseline = assert_unclaimed("precondition")
    clinic_id = int(baseline.get("clinic_id") or 0)
    check("precondition.single_clinic", clinic_id > 0 and int(baseline.get("clinic_count") or 0) == 1,
          f"clinic_id_set={clinic_id > 0}")
    check("precondition.schema_0029", baseline.get("schema") == "2026_10_18_0029",
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

        # --- Real successful admin-post submit, in unified-wizard order ---
        goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard", "cpms-wizard-before-facility")
        initial_facility = admin_page.locator('form:has(input[name="action"][value="cpms_wizard_facility"])')
        check("admin.fresh.facility_before_bootstrap", initial_facility.count() == 1,
              "choose personal practice before establishing the manager membership")
        if initial_facility.count() == 1:
            initial_facility.locator('input[name="facility_type"][value="doctor_practice"]').check()
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                initial_facility.locator('button[type="submit"]').first.click()
        check("admin.fresh.unified_bootstrap_form",
              admin_page.locator('input[name="confirm_identity"]').count() == 1
              and query_page(admin_page.url) == "cpms-wizard",
              "secure bootstrap submitted from the unified wizard, not the legacy hidden URL")
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
                  admin_page.locator('[data-cpms-wizard-step="locations"][aria-current="step"]').count() == 1
                  or query_step(admin_page.url) == "locations",
                  "personal-practice Clinic step progresses to Locations")
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

            # Complete the personal-practice journey before changing facility
            # type for the existing multi-doctor regression journey.
            from rwp_personal import run_personal
            run_personal(admin_page, check, goto_admin, wp_eval, shot, clinic_id)

            # --- Journey B: multi-doctor Clinic with real clinicians ---
            goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=facility", "cpms-wizard-facility-multi")
            fac2 = admin_page.locator('form:has(input[name="facility_type"])')
            if fac2.count() >= 1:
                fac2.locator('input[name="facility_type"][value="general_clinic"]').check()
                fac2.locator('select[name="structure"]').select_option("multi_doctor")
                with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                    fac2.locator('button[type="submit"]').first.click()
                goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=doctors", "cpms-wizard-doctors-multi")
                doc2 = admin_page.locator('form:has(input[name="intended_clinicians"])')
                check("admin.multi.doctors_field", doc2.count() >= 1, "intended count field present for multi-doctor flow")
                if doc2.count() >= 1:
                    doc2.locator('input[name="intended_clinicians"]').fill("2")
                    with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                        doc2.locator('button[type="submit"]').first.click()
                    check("admin.multi.incomplete_blocks",
                          query_step(admin_page.url) == "doctors" and "هنوز کامل نیست" in admin_page.content(),
                          "fewer than two actual clinicians cannot satisfy intended count of two")
                # Register two real clinicians via the established page.
                for idx, cname in enumerate(["پزشک آزمون یک", "پزشک آزمون دو"], start=1):
                    goto_admin(admin_page, "admin", "admin.php?page=cpms-clinicians", f"cpms-clinicians-create-{idx}")
                    cform = admin_page.locator('form:has(input[name="full_name"])')
                    if cform.count() >= 1:
                        cform.first.locator('input[name="full_name"]').first.fill(cname)
                        with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                            cform.first.locator('button[type="submit"], input[type="submit"]').first.click()
                snap = db_snapshot()
                check("admin.multi.clinicians_persisted", int(snap.get("clinician_count") or 0) >= 2,
                      f"clinician_count={snap.get('clinician_count')}")
                goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=doctors", "cpms-wizard-doctors-multi-done")
                doc3 = admin_page.locator('form:has(input[name="intended_clinicians"])')
                if doc3.count() >= 1:
                    check("admin.multi.intended_resumes",
                          doc3.locator('input[name="intended_clinicians"]').input_value() == "2",
                          "intended count restored after interruption")
                    doc3.locator('input[name="intended_clinicians"]').fill("2")
                    with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                        doc3.locator('button[type="submit"]').first.click()
                    check("admin.multi.complete_progresses",
                          "هنوز کامل نیست" not in admin_page.content(),
                          "verified clinician count lets the doctors step complete")
                goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=facility", "cpms-wizard-facility-multi-resume")
                check("admin.multi.facility_resumes",
                      admin_page.locator('input[name="facility_type"][value="general_clinic"]:checked').count() == 1,
                      "multi-doctor facility selection resumes")

        # --- All twelve facility types: real browser selection + the exact
        # facility-aware "اطلاعات <واحد>" step-2 heading (single source of
        # truth: FacilityTypeCatalog::step_title()/type_profile()). Two of
        # the twelve (doctor_practice, general_clinic) already received a
        # full multi-step journey above (Journeys A/B); this loop gives the
        # remaining ten REAL browser evidence too — selection, the facility-
        # aware heading, and interruption/resume — rather than leaving them
        # only code-inspected or integration-tested. doctor_practice/
        # general_clinic are included again here as a cheap cross-check that
        # the loop itself agrees with the dedicated journeys above.
        FACILITY_UNIT_BY_ID = {
            "doctor_practice": "مطب",
            "general_clinic": "درمانگاه",
            "specialized_clinic": "درمانگاه",
            "dental_clinic": "کلینیک دندانپزشکی",
            "diagnostic_center": "مرکز تشخیصی",
            "rehabilitation_center": "مرکز توانبخشی",
            "mental_health_center": "مرکز سلامت روان",
            "specialized_outpatient_center": "مرکز درمان سرپایی",
            "limited_surgery_center": "مرکز جراحی محدود",
            "hospital": "مرکز درمانی",
            "comprehensive_health_center": "مرکز جامع سلامت",
            "other": "مرکز",
        }
        pre_matrix_snapshot = db_snapshot()
        pre_matrix_clinic_count = int(pre_matrix_snapshot.get("clinic_count") or 0)
        for facility_id, expected_unit in FACILITY_UNIT_BY_ID.items():
            goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=facility",
                       f"cpms-wizard-facility-matrix-{facility_id}")
            mform = admin_page.locator('form:has(input[name="facility_type"])')
            if mform.count() < 1:
                check(f"admin.facility_matrix.{facility_id}.form_present", False, "facility form missing")
                continue
            mform.locator(f'input[name="facility_type"][value="{facility_id}"]').check()
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                mform.locator('button[type="submit"]').first.click()
            goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=clinic",
                       f"cpms-wizard-clinic-matrix-{facility_id}")
            # NOTE: the page also renders a flow-summary card (<h2>{picker
            # label}</h2>, e.g. "مطب پزشک — انفرادی یا مشترک") ABOVE the
            # clinic-info card's own <h2>مشخصات ...</h2> — select the
            # "مشخصات" heading specifically, not simply the first <h2>.
            heading_loc = admin_page.locator("h2", has_text="مشخصات")
            heading = heading_loc.first.inner_text() if heading_loc.count() else ""
            check(f"admin.facility_matrix.{facility_id}.exact_heading",
                  heading.strip() == f"مشخصات {expected_unit}",
                  f"got heading={heading!r}, expected={'مشخصات ' + expected_unit!r}")
            # Interruption/resume: the facility choice must survive a
            # revisit without a redirect loop and without silently falling
            # back to a DIFFERENT (incorrect) facility type or Clinic.
            goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=facility",
                       f"cpms-wizard-facility-matrix-resume-{facility_id}")
            check(f"admin.facility_matrix.{facility_id}.resumes_same_type",
                  admin_page.locator(f'input[name="facility_type"][value="{facility_id}"]:checked').count() == 1,
                  "facility selection resumed as the SAME type just chosen (no silent fallback to a different one)")
        post_matrix_snapshot = db_snapshot()
        check("admin.facility_matrix.no_incorrect_automatic_clinic_selection",
              int(post_matrix_snapshot.get("clinic_count") or 0) == pre_matrix_clinic_count,
              "cycling through all twelve facility types must never auto-create or switch to a different Clinic "
              f"(before={pre_matrix_clinic_count}, after={post_matrix_snapshot.get('clinic_count')})")

        # --- Hospital-specific: outpatient-only acknowledgment is ENFORCED,
        # not cosmetic (REAL browser submit attempt, not just code reading). ---
        goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=facility", "cpms-wizard-facility-hospital")
        hform = admin_page.locator('form:has(input[name="facility_type"])')
        if hform.count() >= 1:
            hform.locator('input[name="facility_type"][value="hospital"]').check()
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                hform.locator('button[type="submit"]').first.click()
        goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=health", "cpms-wizard-health-hospital")
        ack_box = admin_page.locator('#cpms_outpatient_only_ack')
        check("admin.hospital.health_step_renders", ack_box.count() == 1,
              "outpatient-only acknowledgment checkbox is present for the hospital flow")
        if ack_box.count() == 1:
            check("admin.hospital.ack_unchecked_by_default", ack_box.is_checked() is False,
                  "the acknowledgment must never be pre-checked for the admin")
            health_form = admin_page.locator('form:has(#cpms_outpatient_only_ack)')
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                health_form.locator('button[type="submit"]').first.click()
            check("admin.hospital.unchecked_ack_blocks_submission",
                  query_step(admin_page.url) == "health"
                  and "تأیید صریح و تازه" in admin_page.content(),
                  "submitting the health step WITHOUT checking the acknowledgment must be rejected with the exact Persian message")
            ack_box.check()
            health_form2 = admin_page.locator('form:has(#cpms_outpatient_only_ack)')
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                health_form2.locator('button[type="submit"]').first.click()
            check("admin.hospital.checked_ack_progresses",
                  "تأیید صریح و تازه" not in admin_page.content(),
                  "checking the acknowledgment and resubmitting must be accepted")

        # --- Medical-building structure: multi-Clinic auto-provisioning
        # stays explicitly DENIED (fail-closed), verified by actually
        # reaching the finish step and attempting the real submit, not just
        # reading the template conditionally. ---
        goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=facility", "cpms-wizard-facility-medbldg")
        mb_form = admin_page.locator('form:has(input[name="facility_type"])')
        if mb_form.count() >= 1:
            mb_form.locator('input[name="facility_type"][value="other"]').check()
            if mb_form.locator('select[name="structure"]').count() == 1:
                mb_form.locator('select[name="structure"]').select_option("medical_building")
            with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                mb_form.locator('button[type="submit"]').first.click()
        goto_admin(admin_page, "admin", "admin.php?page=cpms-wizard&step=finish", "cpms-wizard-finish-medbldg")
        finish_card = admin_page.locator('[data-cpms-finish-ready]')
        check("admin.medical_building.finish_card_present", finish_card.count() == 1, "finish card renders")
        if finish_card.count() == 1:
            check("admin.medical_building.not_ready",
                  finish_card.get_attribute("data-cpms-finish-ready") == "0",
                  "medical_building structure must never report finish-ready")
            check("admin.medical_building.blocked_message_shown",
                  "به‌شکل ایمن مسدود است" in admin_page.content(),
                  "the explicit fail-closed denial message must be visible")
            finish_form = admin_page.locator('form:has(input[name="step"][value="finish"])')
            pre_finish_snapshot = db_snapshot()
            if finish_form.count() == 1:
                with admin_page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
                    finish_form.locator('button[type="submit"]').first.click()
            post_finish_snapshot = db_snapshot()
            # NOTE: setup.completed may already be true from the earlier
            # doctor_practice journey (Journey A) run in this same session —
            # that historical flag is intentionally preserved by this
            # product (see docs/onboarding-facility-wizard.md) and is NOT
            # itself evidence of a bypass. The real assertion is that THIS
            # blocked submit attempt makes no change at all.
            check("admin.medical_building.submit_does_not_complete_setup",
                  post_finish_snapshot.get("setup_completed") == pre_finish_snapshot.get("setup_completed"),
                  "attempting the real finish submit while blocked must never change setup.completed "
                  f"(before={pre_finish_snapshot.get('setup_completed')}, after={post_finish_snapshot.get('setup_completed')})")

        # --- Real Jalali date-ENTRY widget: interactive typing + popup pick,
        # on a REAL calendar-date field reached through an authenticated
        # admin browser session (the clinician schedule-exception date field
        # created in Journey B above), not a unit-test stand-in. Exercises
        # the EXACT regression the independent review reported. ---
        goto_admin(admin_page, "admin", "admin.php?page=cpms-clinicians", "cpms-clinicians-jalali-listing")
        manage_link = admin_page.locator('a:has-text("مدیریت برنامه")').first
        if manage_link.count() == 1:
            href = manage_link.get_attribute("href") or ""
            manage_path = href.split("/wp-admin/", 1)[-1] if "/wp-admin/" in href else ""
            if manage_path:
                goto_admin(admin_page, "admin", manage_path, "cpms-clinicians-jalali-manage")
            jalali_field = admin_page.locator('.cpms-jalali-field').first
            native_date = jalali_field.locator('#cpms-exception-date')
            jalali_entry = jalali_field.locator('.cpms-jalali-entry')
            check("admin.jalali_widget.enhanced_on_real_page",
                  jalali_field.count() == 1 and native_date.count() == 1 and jalali_entry.count() == 1,
                  "the real page actually loaded the Jalali entry companion wrapping the native date input")
            if jalali_field.count() == 1 and native_date.count() == 1 and jalali_entry.count() == 1:
                status_el = jalali_field.locator('.cpms-jalali-status')
                trigger_btn = jalali_field.locator('.cpms-jalali-trigger')

                # REGRESSION: typing the exact invalid date from the
                # independent review must block submission and must NEVER
                # leave a stale/intermediate Gregorian value in the native
                # input that gets silently committed.
                jalali_entry.fill("1405/12/30")
                jalali_entry.blur()
                admin_page.wait_for_timeout(150)
                native_value_after_invalid = native_date.input_value()
                is_valid_after_invalid = admin_page.evaluate(
                    "document.getElementById('cpms-exception-date').checkValidity()"
                )
                check("admin.jalali_widget.invalid_entry_leaves_native_empty",
                      native_value_after_invalid == "",
                      f"REGRESSION CHECK: native value after typing invalid 1405/12/30 = {native_value_after_invalid!r} (must stay empty, never a stale committed date)")
                check("admin.jalali_widget.invalid_entry_blocks_submission",
                      is_valid_after_invalid is False,
                      "the native input's constraint validity must be false (blocking submit) while the typed Jalali date is invalid")
                check("admin.jalali_widget.invalid_entry_status_message",
                      "معتبر نیست" in (status_el.inner_text() if status_el.count() else ""),
                      "an accessible Persian status message must explain the invalid day")

                # POSITIVE: a real, valid (leap-Esfand-30) typed date commits
                # the exact correct Gregorian value and clears the block.
                jalali_entry.fill("")
                jalali_entry.fill("1401/12/30")
                jalali_entry.blur()
                admin_page.wait_for_timeout(150)
                native_value_after_valid = native_date.input_value()
                is_valid_after_valid = admin_page.evaluate(
                    "document.getElementById('cpms-exception-date').checkValidity()"
                )
                check("admin.jalali_widget.valid_entry_commits_exact_gregorian_date",
                      native_value_after_valid == "2023-03-20",
                      f"typing 1401/12/30 (a real leap-Esfand-30 date) must commit exactly 2023-03-20, got {native_value_after_valid!r}")
                check("admin.jalali_widget.valid_entry_unblocks_submission",
                      is_valid_after_valid is True,
                      "a confirmed valid typed date must clear the submission block")

                # Popup pick: an independent entry path must also commit a
                # real, valid date.
                if trigger_btn.count() == 1:
                    trigger_btn.click()
                    day_buttons = admin_page.locator('.cpms-jalali-popup[role="dialog"] .cpms-jalali-day:not(.cpms-jalali-day-empty)')
                    check("admin.jalali_widget.popup_opens_with_days", day_buttons.count() > 0, "the accessible calendar popup renders real day cells")
                    if day_buttons.count() > 0:
                        day_buttons.first.click()
                        admin_page.wait_for_timeout(150)
                        popup_native_value = native_date.input_value()
                        check("admin.jalali_widget.popup_pick_commits_valid_date",
                              bool(re.match(r"^\d{4}-\d{2}-\d{2}$", popup_native_value or "")),
                              f"picking a day from the popup must commit a valid Gregorian ISO date, got {popup_native_value!r}")
        else:
            check("admin.jalali_widget.manage_link_present", False, "no clinician row with a schedule link was found to exercise the Jalali widget")

        # --- Responsive Persian RTL presentation: the same admin page must
        # render RTL and without horizontal overflow at a real mobile
        # viewport (representative check, not an exhaustive device matrix —
        # the dedicated Responsive-smoke job covers broader viewport
        # coverage for the public booking surface). ---
        check("admin.responsive.wrap_is_rtl",
              admin_page.evaluate("(function(){var w=document.querySelector('.wrap[dir=\"rtl\"]'); return !!w;})()"),
              "the CPMS admin wrap must declare dir=\"rtl\"")
        admin_page.set_viewport_size({"width": 390, "height": 844})
        admin_page.reload(wait_until="domcontentloaded")
        admin_page.wait_for_timeout(300)
        overflow = admin_page.evaluate(
            "document.documentElement.scrollWidth - document.documentElement.clientWidth"
        )
        check("admin.responsive.mobile_no_horizontal_overflow",
              overflow <= 8,
              f"mobile viewport (390px) horizontal overflow = {overflow}px (must be negligible)")
        admin_page.set_viewport_size({"width": 1440, "height": 900})

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
                ("admin.php?page=cpms-system", "multiclinic-system"),
                ("admin.php?page=cpms-settings", "multiclinic-settings"),
                ("admin.php?page=cpms-clinicians", "multiclinic-clinicians"),
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

    # --- Multi-Clinic manager: explicit Clinic selection (PR #253) ---
    # A manager with ACTIVE memberships in two Clinics must choose a Clinic
    # explicitly; data is bound to the verified choice; a forged id discloses
    # nothing; installation info renders without a Clinic.
    try:
        fixture_out = apply_fixture("multiclinic_manager")
        ids = dict(tok.split("=", 1) for tok in (fixture_out or "").split() if "=" in tok)
        first_id = ids.get("first", "")
        second_id = ids.get("second", "")
        check("multiclinic_manager.fixture", first_id.isdigit() and second_id.isdigit() and first_id != second_id,
              f"first={first_id} second={second_id}")
        mgr_ctx = new_persona_context(browser)
        mgr_page = mgr_ctx.new_page()
        if login(mgr_page, SECOND_ADMIN_USER, SECOND_ADMIN_PASS, "manager-multiclinic"):
            status, body = goto_admin(mgr_page, "manager-multiclinic", "admin.php?page=cpms-clinicians", "multiclinic-clinicians-choose")
            select = mgr_page.locator("#cpms_clinicians_clinic")
            options = select.locator("option").evaluate_all("els => els.map(el => el.value).filter(Boolean)") if select.count() == 1 else []
            check("multiclinic_manager.clinicians.explicit_selector",
                  sorted(options) == sorted([first_id, second_id])
                  and mgr_page.locator('[data-cpms-clinic-scope="required"]').count() == 1,
                  f"authorized options={options}")
            check("multiclinic_manager.clinicians.no_default",
                  select.count() == 1 and select.input_value() == ""
                  and not body_has(body, "FP Dr First Clinic") and not body_has(body, "FP Dr Second Clinic"),
                  "no Clinic is guessed and no clinician data is shown before selection")
            if select.count() == 1 and second_id in options:
                select.select_option(second_id)
                with mgr_page.expect_navigation(wait_until="domcontentloaded"):
                    select.evaluate("el => el.form.submit()")
                mgr_page.wait_for_timeout(500)
                body = mgr_page.content()
                shot(mgr_page, "multiclinic-clinicians-second")
                check("multiclinic_manager.clinicians.selected_clinic_only",
                      mgr_page.locator(f'[data-cpms-active-clinic="{second_id}"]').count() == 1
                      and body_has(body, "FP Dr Second Clinic") and not body_has(body, "FP Dr First Clinic"),
                      "only the explicitly selected Clinic's clinicians are shown")
                check("multiclinic_manager.clinicians.forms_carry_clinic",
                      mgr_page.locator(f'input[name="clinic_id"][value="{second_id}"]').count() >= 1,
                      "mutation forms carry the verified Clinic")
            status, body = goto_admin(mgr_page, "manager-multiclinic",
                                      "admin.php?page=cpms-clinicians&clinic_id=2147483647", "multiclinic-clinicians-forged")
            check("multiclinic_manager.clinicians.forged_denied",
                  mgr_page.locator('[data-cpms-clinic-scope="denied"]').count() == 1
                  and not body_has(body, "FP Dr First Clinic") and not body_has(body, "FP Dr Second Clinic"),
                  "forged Clinic id is denied without disclosure")
            status, body = goto_admin(mgr_page, "manager-multiclinic", "admin.php?page=cpms-settings", "multiclinic-manager-settings")
            check("multiclinic_manager.settings.installation_info_without_clinic",
                  body_has(body, "تعداد جداول")
                  and mgr_page.locator('[data-cpms-clinic-scope="required"]').count() == 1
                  and mgr_page.locator("[data-cpms-clinic-settings]").count() == 0,
                  "installation info renders; Clinic settings need explicit selection")
            status, body = goto_admin(mgr_page, "manager-multiclinic",
                                      f"admin.php?page=cpms-settings&clinic_id={second_id}", "multiclinic-manager-settings-second")
            check("multiclinic_manager.settings.explicit_clinic",
                  mgr_page.locator(f'[data-cpms-clinic-settings="{second_id}"]').count() == 1,
                  "Clinic-specific settings for the explicit Clinic only")
            status, body = goto_admin(mgr_page, "manager-multiclinic", "admin.php?page=cpms-system", "multiclinic-manager-system")
            check("multiclinic_manager.system.renders_with_selector",
                  body_has(body, "وضعیت Health") and mgr_page.locator("#cpms_system_clinic").count() == 1,
                  "System page renders on a multi-Clinic install and offers explicit selection")
        mgr_page.close()
        mgr_ctx.close()

        mob_ctx = new_persona_context(browser, 390, 844)
        mob_page = mob_ctx.new_page()
        if login(mob_page, SECOND_ADMIN_USER, SECOND_ADMIN_PASS, "manager-multiclinic-mobile"):
            for path, shot_name in (
                ("admin.php?page=cpms-clinicians", "multiclinic-selector-mobile"),
                (f"admin.php?page=cpms-clinicians&clinic_id={second_id}", "multiclinic-clinicians-mobile"),
            ):
                goto_admin(mob_page, "manager-multiclinic-mobile", path, shot_name)
                rtl = mob_page.locator('.wrap[dir="rtl"]').count() >= 1
                overflow = mob_page.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth")
                check(f"multiclinic_manager.mobile.{shot_name}.rtl_no_overflow", rtl and overflow <= 8,
                      f"rtl={rtl} overflow={overflow}px at 390px")
        mob_page.close()
        mob_ctx.close()
    except Exception as cop:
        check("multiclinic_manager.explicit_selection", False, type(cop).__name__)

    # --- Top-down Organization facility setup (PR #253) ---
    # Hospital outpatient network + medical building through the real admin page:
    # explicit Organization/Clinic/site/user choices, real entities, Clinics held
    # until the consolidated review, Organization authority != Clinic authority.
    try:
        fac = dict(tok.split("=", 1) for tok in (apply_fixture("facility") or "").split() if "=" in tok)
        hospital_org = fac.get("hospital", "")
        building_org = fac.get("building", "")
        check("facility.fixture", hospital_org.isdigit() and building_org.isdigit() and bool(fac.get("doctor")),
              f"hospital={hospital_org} building={building_org}")
        fac_ctx = new_persona_context(browser)
        fac_page = fac_ctx.new_page()
        if login(fac_page, SECOND_ADMIN_USER, SECOND_ADMIN_PASS, "facility-org-manager"):
            run_hospital_facility_journey(fac_page, "facility-org-manager", hospital_org, fac)
            run_building_facility_journey(fac_page, "facility-org-manager", building_org, fac)
            goto_admin(fac_page, "facility-org-manager", f"{FACILITY_PAGE}&organization_id=2147483647", "facility-forged-org")
            check("facility.forged_organization_denied",
                  fac_page.locator('[data-cpms-facility-setup="org-denied"]').count() == 1
                  and fac_page.locator("[data-cpms-facility-form]").count() == 0,
                  "a forged Organization id discloses nothing")
        fac_page.close()
        fac_ctx.close()

        facts = facility_db_facts(hospital_org)
        check("facility.hospital.db_entities",
              facts.get("clinics") == "2" and facts.get("active") == "2" and facts.get("locations") == "4"
              and facts.get("schedules") == "2" and facts.get("schedule_clinics") == "2"
              and facts.get("managers") == "2" and facts.get("profiles") == "1",
              " ".join(f"{k}={v}" for k, v in sorted(facts.items())))
        facts = facility_db_facts(building_org)
        check("facility.building.db_entities",
              facts.get("clinics") == "2" and facts.get("active") == "2" and facts.get("locations") == "2"
              and facts.get("managers") == "2" and facts.get("actor_members") == "0",
              " ".join(f"{k}={v}" for k, v in sorted(facts.items())))

        adm_ctx = new_persona_context(browser)
        adm_page = adm_ctx.new_page()
        if login(adm_page, ADMIN_USER, ADMIN_PASS, "facility-wp-admin"):
            goto_admin(adm_page, "facility-wp-admin", f"{FACILITY_PAGE}&organization_id={hospital_org}", "facility-wp-admin-denied")
            check("facility.wp_administrator_without_membership_denied",
                  adm_page.locator("[data-cpms-facility-form]").count() == 0
                  and adm_page.locator("[data-cpms-facility-flow]").count() == 0,
                  "WordPress Administrator status grants no Organization setup authority")
        adm_page.close()
        adm_ctx.close()

        fac_sub_ctx = new_persona_context(browser)
        sub_fac_page = fac_sub_ctx.new_page()
        if login(sub_fac_page, SUBSCRIBER_USER, SUBSCRIBER_PASS, "facility-subscriber"):
            sub_fac_page.goto(f"{BASE}/wp-admin/{FACILITY_PAGE}&organization_id={hospital_org}", wait_until="domcontentloaded")
            sub_fac_page.wait_for_timeout(500)
            shot(sub_fac_page, "facility-subscriber-denied")
            check("facility.subscriber_denied",
                  sub_fac_page.locator("[data-cpms-facility-form]").count() == 0
                  and sub_fac_page.locator("[data-cpms-facility-flow]").count() == 0,
                  "no Organization membership → no setup data or forms")
        sub_fac_page.close()
        fac_sub_ctx.close()

        fac_mob_ctx = new_persona_context(browser, 390, 844)
        fac_mob_page = fac_mob_ctx.new_page()
        if login(fac_mob_page, SECOND_ADMIN_USER, SECOND_ADMIN_PASS, "facility-org-manager-mobile"):
            for step, shot_name in (("review", "facility-review-mobile"), ("clinicians", "facility-clinicians-mobile"),
                                    ("schedules", "facility-schedules-mobile")):
                goto_admin(fac_mob_page, "facility-org-manager-mobile", facility_url(hospital_org, step), shot_name)
                rtl = fac_mob_page.locator('.wrap[dir="rtl"]').count() >= 1
                overflow = fac_mob_page.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth")
                check(f"facility.mobile.{shot_name}.rtl_no_overflow", rtl and overflow <= 8,
                      f"rtl={rtl} overflow={overflow}px at 390px")
        fac_mob_page.close()
        fac_mob_ctx.close()
    except Exception as cop:
        check("facility.journeys", False, type(cop).__name__)

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


def error_locations(errors):
    """Privacy-safe locations of browser errors: persona tag, error class and admin page/step only."""
    where = []
    for tag, msg, url in errors[:5]:
        parsed = urlparse(url or "")
        query = parse_qs(parsed.query)
        kind = re.sub(r"[^A-Za-z]", "", str(msg).split(":", 1)[0])[:40] or "Error"
        where.append(f"[{tag}] {kind} {parsed.path} page={query.get('page', [''])[0]} step={query.get('step', [''])[0]}")
    return ("; at: " + " | ".join(where)) if where else ""


check("browser.no_page_errors", len(pg_errors) == 0,
      f"{len(pg_errors)} page errors on CPMS/admin pages (messages suppressed)" + error_locations(pg_errors))

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
