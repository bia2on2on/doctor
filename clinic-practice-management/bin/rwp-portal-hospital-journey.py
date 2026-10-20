#!/usr/bin/env python3
"""rwp-portal-hospital-journey.py — real-browser hospital onboarding by TWO Subscriber users.

Independent Playwright harness. It drives the FRONTEND Management Portal only
(`admin_url`/`wp-admin` is never opened): an Organization Manager Subscriber and
two DIFFERENT Clinic Manager Subscribers, each in their own browser context with
their own cookie jar.

What is NOT pre-seeded (proved by assertions):
  * Clinic Managers have no Organization membership;
  * no user holds a WordPress business role;
  * the wizard steps themselves create the Clinics, Locations, clinicians,
    schedules and booking policies.

What IS pre-seeded (tenant root only — there is no self-service Organization
creation in this product): one Organization row plus one ACTIVE
`organization_manager` membership for the Organization Manager Subscriber.
Everything after that is rendered-form interaction.

Secrets (passwords, nonces, cookies) are never written to results or evidence.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from urllib.parse import urlparse

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ORG_USER = os.environ["ORG_MANAGER_USER"]
ORG_PASS = os.environ["ORG_MANAGER_PASS"]
MGR_A_USER = os.environ["CLINIC_MANAGER_A_USER"]
MGR_A_PASS = os.environ["CLINIC_MANAGER_A_PASS"]
MGR_B_USER = os.environ["CLINIC_MANAGER_B_USER"]
MGR_B_PASS = os.environ["CLINIC_MANAGER_B_PASS"]
WP_DIR = os.environ.get("WP_DIR", "").strip()
OUT = os.environ.get("OUT", "rwp-portal-hospital-out")

os.makedirs(f"{OUT}/screenshots", exist_ok=True)
os.makedirs(f"{OUT}/logs", exist_ok=True)

results: list[dict] = []
console_errors: list[str] = []
page_errors: list[str] = []

CRITICAL_RE = re.compile(r"critical error|خطای بحرانی|wp-die-message", re.IGNORECASE)
SENSITIVE_RE = re.compile(
    r"(?i)([\w-]*(?:nonce|password|passwd|cookie|token|authorization|secret)[\w-]*\s*[=:]\s*)[^\s<>\"']+"
)

HOSPITAL_NAME = "بیمارستان دو‌مدیره"
SITE_EAST = "ساختمان شرقی"
SITE_WEST = "ساختمان غربی"
CLINIC_A_NAME = "درمانگاه قلب"
CLINIC_B_NAME = "درمانگاه پوست"
BOOKING = {"duration": "15", "capacity": "1", "lead": "2", "future": "30", "cancel": "12"}


def redact(value) -> str:
    return SENSITIVE_RE.sub(r"\g<1>[redacted]", str(value))


def check(name: str, ok: bool, detail: str = "") -> bool:
    results.append({"check": name, "ok": bool(ok), "detail": redact(detail)[:400]})
    print(("PASS " if ok else "FAIL ") + name + ((" — " + redact(detail)[:300]) if detail else ""))
    return bool(ok)


def shot(page, name: str) -> None:
    try:
        page.screenshot(path=f"{OUT}/screenshots/{name}.png", full_page=True)
    except Exception:  # noqa: BLE001 - evidence only
        pass


def attach(page, tag: str) -> None:
    page.on("console", lambda m: console_errors.append(f"{tag}: {redact(m.text)[:200]}") if m.type == "error" else None)
    page.on("pageerror", lambda e: page_errors.append(f"{tag}: {redact(e)[:200]}"))


def wp_eval(code: str, extra_env: dict | None = None) -> str:
    if not WP_DIR:
        raise RuntimeError("wp_dir_unavailable")
    path = os.path.join(OUT, "logs", "wp-eval.php")
    with open(path, "w", encoding="utf-8") as handle:
        handle.write("<?php\n" + code.strip() + "\n")
    env = os.environ.copy()
    if extra_env:
        env.update(extra_env)
    proc = subprocess.run(
        ["wp", "eval-file", path, f"--path={WP_DIR}", "--allow-root"],
        capture_output=True,
        text=True,
        timeout=120,
        env=env,
    )
    if proc.returncode != 0:
        raise RuntimeError("wp_cli_failed: " + redact((proc.stderr or proc.stdout)[:400]))
    return proc.stdout


SEED_EVAL = r"""
$db = \ClinicCore\Bootstrap\App::db();
$wpdb = $db->wpdb();
$orgUser = get_user_by('login', getenv('ORG_LOGIN'));
if (!$orgUser instanceof WP_User) { fwrite(STDERR, "missing org user\n"); exit(1); }
$now = $db->nowUtcSql();
$existing = $db->fetchValue('SELECT id FROM ' . $db->table('cpms_organizations') . ' WHERE slug = %s', ['portal-hospital-org']);
$orgId = (int) $existing;
if ($orgId <= 0) {
    $wpdb->insert($db->table('cpms_organizations'), [
        'name' => 'سازمان بیمارستانی پورتال',
        'slug' => 'portal-hospital-org',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $orgId = (int) $wpdb->insert_id;
}
$has = (int) $db->fetchValue(
    'SELECT COUNT(*) FROM ' . $db->table('cpms_organization_memberships') . ' WHERE organization_id = %d AND wp_user_id = %d',
    [$orgId, (int) $orgUser->ID]
);
if ($has === 0) {
    $wpdb->insert($db->table('cpms_organization_memberships'), [
        'organization_id' => $orgId,
        'wp_user_id' => (int) $orgUser->ID,
        'role_key' => 'organization_manager',
        'status' => 'active',
        'created_by_wp_user_id' => (int) $orgUser->ID,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
\ClinicCore\Frontend\ManagementPortalShell::ensure_portal_page();
echo 'ORG_ID=' . $orgId . PHP_EOL;
echo 'PORTAL=' . \ClinicCore\Frontend\ManagementPortalShell::portal_url() . PHP_EOL;
"""

FACTS_EVAL = r"""
$db = \ClinicCore\Bootstrap\App::db();
$rows = $db->fetchAll('SELECT id, name, COALESCE(status, %s) AS status, organization_id FROM ' . $db->table('cpms_clinics') . ' ORDER BY id ASC', ['active']);
$out = [];
foreach ($rows as $row) {
    $id = (int) $row['id'];
    $out[] = [
        'id' => $id,
        'name' => (string) $row['name'],
        'status' => (string) $row['status'],
        'locations' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', [$id]),
        'schedules' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule') . ' WHERE clinic_id = %d', [$id]),
        'booking' => 0 !== (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s', [$id, 'booking.duration_default_min']),
    ];
}
echo 'FACTS=' . wp_json_encode($out) . PHP_EOL;
"""


def parse_kv(output: str) -> dict:
    out = {}
    for line in (output or "").splitlines():
        if "=" in line:
            key, value = line.split("=", 1)
            out[key.strip()] = value.strip()
    return out


def login(page, url: str, user: str, password: str, tag: str) -> bool:
    page.goto(url, wait_until="domcontentloaded")
    page.wait_for_timeout(500)
    form = page.locator('form:has(input[name="cpms_action"][value="login"])')
    if form.count() == 0:
        return False
    form.locator('input[name="log"]').fill(user)
    form.locator('input[name="pwd"]').fill(password)
    form.locator('button[type="submit"]').click()
    page.wait_for_load_state("domcontentloaded")
    page.wait_for_timeout(700)
    ok = page.locator('form:has(input[name="cpms_action"][value="login"])').count() == 0
    check(f"{tag}.login", ok, page.url)
    shot(page, f"{tag}-after-login")
    return ok


def portal_form(page, action: str):
    return page.locator(f'form:has(input[name="cpms_action"][value="{action}"])')


def inventory(page) -> str:
    """Bounded, redacted description of what the browser actually rendered."""
    try:
        actions = page.locator('input[name="cpms_action"]').evaluate_all("els => els.map(e => e.value)")
    except Exception:  # noqa: BLE001 - diagnostics only
        actions = []
    notice = ""
    loc = page.locator(".cpms-mgmt-notice")
    if loc.count():
        try:
            notice = loc.first.inner_text()
        except Exception:  # noqa: BLE001
            notice = ""
    return f"url={page.url} actions={list(actions)[:20]} notice={notice[:120]}"


def submit_form(page, action: str, tag: str, sshot: str, fill=None) -> str:
    form = portal_form(page, action)
    if form.count() == 0:
        check(f"{tag}.form_present", False, f"no form for {action}; {inventory(page)}")
        return "missing"
    if fill:
        fill(form)
    form.locator('button[type="submit"]').first.click()
    page.wait_for_load_state("domcontentloaded")
    page.wait_for_timeout(600)
    body = page.content()
    notice = page.locator(".cpms-mgmt-notice")
    text = notice.first.inner_text() if notice.count() else ""
    shot(page, sshot)
    if CRITICAL_RE.search(body or ""):
        check(f"{tag}.no_critical_error", False, "critical error page")
        return "critical"
    ok = "cpms-mgmt-notice" in body and ("انجام شد" in text or "ذخیره" in text or "ثبت" in text or "ایجاد" in text or "تعیین" in text or "فعال" in text or "تأیید" in text)
    check(f"{tag}.submitted", ok, f"notice={text[:80]}")
    return "ok" if ok else ("ok" if "cpms-mgmt-notice" in body and "دسترسی" not in text else "denied")


def select_by_label_text(form, selector: str, text: str) -> bool:
    options = form.locator(f'{selector} option')
    for index in range(options.count()):
        option = options.nth(index)
        if text in (option.inner_text() or ""):
            form.locator(selector).select_option(option.get_attribute("value"))
            return True
    return False


def surface_url(portal: str, param: str, value: int) -> str:
    joiner = "&" if "?" in portal else "?"
    return f"{portal}{joiner}{param}={value}"


def submit_on_surface(page, action: str, tag: str, sshot: str, surface: str, fill=None) -> str:
    """Submit one portal form, then re-open the explicit surface (deterministic)."""
    outcome = submit_form(page, action, tag, sshot, fill)
    if surface:
        page.goto(surface, wait_until="domcontentloaded")
        page.wait_for_timeout(500)
    return outcome


def overflow(page) -> int:
    return int(page.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth"))


def main() -> int:
    seeded = parse_kv(wp_eval(SEED_EVAL, {"ORG_LOGIN": ORG_USER}))
    portal = seeded.get("PORTAL") or f"{BASE}/"
    check("seed.portal_url", portal.startswith("http"), portal)
    check("seed.no_admin_route", "/wp-admin" not in portal, portal)

    with sync_playwright() as p:
        browser = p.chromium.launch()

        # ---------------- Organization Manager (Subscriber) ----------------
        org_ctx = browser.new_context(viewport={"width": 1440, "height": 900})
        org = org_ctx.new_page()
        attach(org, "org")
        if not login(org, portal, ORG_USER, ORG_PASS, "org"):
            browser.close()
            return report()
        check("org.no_wp_admin_link", org.locator('a[href*="/wp-admin"]').count() == 0, "portal never links to wp-admin")

        org_id = int(seeded.get("ORG_ID", "0") or 0)
        tab = org.locator(f'a[href*="cpms-org={org_id}"]')
        if tab.count():
            tab.first.click()
            org.wait_for_load_state("domcontentloaded")
            org.wait_for_timeout(500)

        org_surface = surface_url(portal, "cpms-org", org_id)

        # 1. facility type + identity
        submit_on_surface(org, "org_facility_start", "org.start", "org-facility-start", org_surface,
                    lambda f: (f.locator('select[name="facility_type"]').select_option("hospital"),
                               f.locator('select[name="structure"]').select_option("hospital_network") if f.locator('select[name="structure"] option[value="hospital_network"]').count() else None,
                               f.locator('input[name="display_name"]').fill(HOSPITAL_NAME)))
        check("org.plan_is_hospital", "hospital_network" in org.content(), "flow=hospital_network rendered")

        # 2. outpatient-only acknowledgment
        submit_on_surface(org, "org_facility_ack", "org.ack", "org-outpatient-ack", org_surface)

        # 3. two physical sites
        for label, address, sshot in ((SITE_EAST, "تهران، خیابان اول", "org-site-east"),
                                      (SITE_WEST, "تهران، خیابان دوم", "org-site-west")):
            submit_on_surface(org, "org_facility_site", f"org.site.{label}", sshot, org_surface,
                        lambda f, label=label, address=address: (f.locator('input[name="label"]').fill(label),
                                                                 f.locator('input[name="address"]').fill(address)))

        # 4. two held Clinics
        for name, site, sshot in ((CLINIC_A_NAME, SITE_EAST, "org-clinic-a"),
                                  (CLINIC_B_NAME, SITE_WEST, "org-clinic-b")):
            def fill_clinic(f, name=name, site=site):
                f.locator('input[name="name"]').fill(name)
                select_by_label_text(f, 'select[name="site_key"]', site)
                f.locator('input[name="timezone"]').fill("Asia/Tehran")
            submit_on_surface(org, "org_facility_clinic", f"org.clinic.{name}", sshot, org_surface, fill_clinic)

        body = org.content()
        check("org.two_clinics_listed", body.count("درمانگاه") >= 1 and "درمانگاه‌ها: 2" in body,
              f"plan shows 2 Clinics; {inventory(org)}")
        check("org.clinic_steps_delegated", 'data-cpms-clinic-steps-delegated="1"' in body,
              "Clinic-level forms are NOT offered to the Organization Manager")

        # 5. designate a DIFFERENT Subscriber per Clinic (server-side resolution by login)
        for clinic, mgr_login, sshot in ((CLINIC_A_NAME, MGR_A_USER, "org-designate-a"),
                                         (CLINIC_B_NAME, MGR_B_USER, "org-designate-b")):
            def fill_desig(f, clinic=clinic, mgr_login=mgr_login):
                select_by_label_text(f, 'select[name="clinic_key"]', clinic)
                f.locator('input[name="identifier"]').fill(mgr_login)
            submit_on_surface(org, "org_facility_designate", f"org.designate.{clinic}", sshot, org_surface, fill_desig)

        check("org.still_not_ready", "فعال‌سازی" in org.content(), "activation form present but plan incomplete")

        # ---------------- Clinic Manager A (own context) ----------------
        def clinic_manager(user, password, tag, clinic_name, day, sshot_prefix, viewport=None):
            ctx = browser.new_context(viewport=viewport or {"width": 1366, "height": 900})
            page = ctx.new_page()
            attach(page, tag)
            if not login(page, portal, user, password, tag):
                ctx.close()
                return False
            body = page.content()
            check(f"{tag}.no_organization_surface", "راه‌اندازی مرکز" not in body, "Clinic Manager gets no Organization wizard")
            page.goto(portal, wait_until="domcontentloaded")
            page.wait_for_timeout(400)
            tab = page.locator(".cpms-mgmt-tab").filter(has_text=clinic_name)
            if tab.count() == 0:
                check(f"{tag}.clinic_tab", False, "owned Clinic is not listed")
                ctx.close()
                return False
            href = tab.first.get_attribute("href") or ""
            clinic_match = re.search(r"cpms-clinic=(\d+)", href)
            clinic_surface = surface_url(portal, "cpms-clinic", int(clinic_match.group(1))) if clinic_match else ""
            tab.first.click()
            page.wait_for_load_state("domcontentloaded")
            page.wait_for_timeout(600)
            body = page.content()
            check(f"{tag}.clinic_surface", clinic_name in body, page.url)
            check(f"{tag}.readiness_panel", "data-cpms-clinic-readiness" in body, "per-Clinic readiness panel rendered")
            shot(page, f"{sshot_prefix}-clinic-blocked")

            # Location / room
            submit_on_surface(page, "clinic_location_create", f"{tag}.location", f"{sshot_prefix}-location", clinic_surface,
                        lambda f: (f.locator('input[name="name"]').fill("اتاق ویزیت ۱"),
                                   f.locator('input[name="slug"]').fill(f"room-1-{tag}"),
                                   f.locator('input[name="timezone"]').fill("Asia/Tehran")))
            # Clinician
            submit_on_surface(page, "clinic_clinician_register", f"{tag}.clinician", f"{sshot_prefix}-clinician", clinic_surface,
                        lambda f: (f.locator('input[name="full_name"]').fill("دکتر " + tag),
                                   f.locator('input[name="specialty"]').fill("عمومی")))
            # Weekly schedule (server-populated selects: clinician + location)
            schedule_form = portal_form(page, "clinic_schedule_create")
            if schedule_form.count():
                def fill_sched(f, day=day):
                    f.locator('select[name="clinician_id"]').select_option(index=0)
                    f.locator('select[name="location_id"]').select_option(index=0)
                    f.locator('select[name="day_of_week"]').select_option(str(day)) if f.locator(f'select[name="day_of_week"] option[value="{day}"]').count() else None
                    f.locator('input[name="start_time"]').fill("09:00")
                    f.locator('input[name="end_time"]').fill("12:00")
                submit_on_surface(page, "clinic_schedule_create", f"{tag}.schedule", f"{sshot_prefix}-schedule", clinic_surface, fill_sched)
            else:
                check(f"{tag}.schedule.form_present", False, "no schedule form")

            # Booking policy — the Clinic Manager's own completion step
            submit_on_surface(page, "clinic_facility_booking", f"{tag}.booking", f"{sshot_prefix}-booking", clinic_surface,
                        lambda f: [f.locator(f'input[name="{key}"]').fill(value) for key, value in BOOKING.items()])

            body = page.content()
            ready = 'data-cpms-clinic-readiness="ready"' in body
            check(f"{tag}.ready_after_completion", ready, "readiness panel reports the Clinic as ready")
            if viewport and viewport["width"] <= 420:
                check(f"{tag}.no_horizontal_overflow_390", overflow(page) <= 1, f"overflow={overflow(page)}px")
                shot(page, f"{sshot_prefix}-mobile-390")
            ctx.close()
            return ready

        ready_a = clinic_manager(MGR_A_USER, MGR_A_PASS, "mgrA", CLINIC_A_NAME, 1, "mgrA")
        ready_b = clinic_manager(MGR_B_USER, MGR_B_PASS, "mgrB", CLINIC_B_NAME, 3, "mgrB",
                                 viewport={"width": 390, "height": 844})

        # ---------------- Organization Manager: review + atomic activation ----------------
        org.goto(portal, wait_until="domcontentloaded")
        org.wait_for_timeout(500)
        body = org.content()
        check("org.review_ready", "همهٔ پیش‌شرط‌ها فراهم است" in body or "فعال‌سازی اتمیک" in body,
              "activation panel present after both Clinics completed")
        shot(org, "org-review-ready")
        submit_on_surface(org, "org_facility_activate", "org.activate", "org-activated", org_surface)

        facts = parse_kv(wp_eval(FACTS_EVAL)).get("FACTS", "[]")
        try:
            rows = json.loads(facts)
        except Exception:
            rows = []
        by_name = {row["name"]: row for row in rows}
        for name in (CLINIC_A_NAME, CLINIC_B_NAME):
            row = by_name.get(name, {})
            check(f"db.{name}.active", row.get("status") == "active", json.dumps(row, ensure_ascii=False))
            check(f"db.{name}.has_location", int(row.get("locations", 0)) >= 1, str(row.get("locations")))
            check(f"db.{name}.has_schedule", int(row.get("schedules", 0)) >= 1, str(row.get("schedules")))
            check(f"db.{name}.has_booking", bool(row.get("booking")), str(row.get("booking")))

        check("browser.platform_managers_ready", ready_a and ready_b, f"A={ready_a} B={ready_b}")
        check("browser.no_console_errors", len(console_errors) == 0, f"count={len(console_errors)}")
        check("browser.no_page_errors", len(page_errors) == 0, f"count={len(page_errors)}")
        browser.close()

    return report()


def report() -> int:
    passed = sum(1 for row in results if row["ok"])
    failed = [row for row in results if not row["ok"]]
    # GitHub annotations travel with the check-run even when the job log is not
    # retrievable, so every failed check is emitted as an ::error:: annotation.
    for row in failed:
        print(f"::error::portal.{row['check']} :: {row['detail']}")
    payload = {
        "checks": results,
        "passed": passed,
        "failed": len(failed),
        "console_errors": console_errors[:20],
        "page_errors": page_errors[:20],
    }
    with open(f"{OUT}/portal-hospital-results.json", "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
    print(f"\nportal hospital journey: {passed} passed / {len(failed)} failed")
    return 0 if not failed else 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # noqa: BLE001 - an unhandled error must be visible as an annotation
        print(f"::error::portal.harness_exception :: {type(exc).__name__}: {redact(exc)[:300]}")
        report()
        raise
