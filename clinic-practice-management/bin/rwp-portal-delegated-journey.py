#!/usr/bin/env python3
"""rwp-portal-delegated-journey.py — real-browser DELEGATED non-hospital completion.

Independent Playwright harness (sibling of rwp-portal-hospital-journey.py). It
drives the FRONTEND Management Portal only (`admin_url`/`wp-admin` is never
opened) and proves the independent-audit blocker B2 is fixed:

  * a SEPARATE Subscriber Clinic Manager (no Organization membership) completes
    a general_clinic (multi_doctor_clinic) plan Clinic AND a doctor_practice
    (simple_practice) plan Clinic from the Clinic surface — readiness panel,
    location, clinicians, weekly schedules and the booking policy form are all
    rendered for these NON-hospital flows;
  * the Organization Manager activates each plan atomically afterwards and can
    NEVER silently perform Clinic Manager operations (clinic steps stay
    delegated on the Organization surface);
  * cross-Clinic submissions are refused without disclosure;
  * 390px RTL viewport has no horizontal overflow.

What IS pre-seeded (tenant root only): two Organizations plus one ACTIVE
`organization_manager` membership each for the Organization Manager Subscriber.
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
OUT = os.environ.get("OUT", "rwp-portal-delegated-out")

os.makedirs(f"{OUT}/screenshots", exist_ok=True)
os.makedirs(f"{OUT}/logs", exist_ok=True)

results: list[dict] = []
console_errors: list[str] = []
page_errors: list[str] = []

CRITICAL_RE = re.compile(r"critical error|خطای بحرانی|wp-die-message", re.IGNORECASE)
SENSITIVE_RE = re.compile(
    r"(?i)([\w-]*(?:nonce|password|passwd|cookie|token|authorization|secret)[\w-]*\s*[=:]\s*)[^\s<>\"']+"
)

GENERAL_NAME = "درمانگاه عمومی چندپزشکی"
GENERAL_CLINIC_A = "درمانگاه عمومی الف"
GENERAL_CLINIC_B = "درمانگاه عمومی ب"
GENERAL_SITE = "ساختمان مرکزی"
SOLO_SITE = "ساختمان مرکز"  # FacilitySetupService::DEFAULT_SITE_LABEL (auto-seeded)
SOLO_NAME = "مطب دکتر تک‌نفره"
BOOKING = {"duration": "15", "capacity": "1", "lead": "2", "future": "30", "cancel": "12"}


def redact(value) -> str:
    return SENSITIVE_RE.sub(r"\g<1>[redacted]", str(value))


def check(name: str, ok: bool, detail: str = "") -> bool:
    results.append({"check": name, "ok": bool(ok), "detail": redact(detail)[:400]})
    print(("PASS " if ok else "FAIL ") + name + ((f" — {redact(detail)[:300]}") if detail else ""))
    if not ok:
        # Workflow command: surfaces the failing check + detail as a CI annotation.
        print(f"::error::delegated-journey {name} :: {redact(detail)[:300]}")
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
function cpms_seed_org($db, $wpdb, $slug, $name, $orgUser, $now) {
    $existing = $db->fetchValue('SELECT id FROM ' . $db->table('cpms_organizations') . ' WHERE slug = %s', [$slug]);
    $orgId = (int) $existing;
    if ($orgId <= 0) {
        $wpdb->insert($db->table('cpms_organizations'), [
            'name' => $name,
            'slug' => $slug,
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
    return $orgId;
}
$orgA = cpms_seed_org($db, $wpdb, 'portal-delegated-org', 'سازمان درمانگاه چندپزشکی', $orgUser, $now);
$orgB = cpms_seed_org($db, $wpdb, 'portal-delegated-solo-org', 'سازمان مطب تک‌نفره', $orgUser, $now);
\ClinicCore\Frontend\ManagementPortalShell::ensure_portal_page();
echo 'ORG_ID_A=' . $orgA . PHP_EOL;
echo 'ORG_ID_B=' . $orgB . PHP_EOL;
echo 'PORTAL=' . \ClinicCore\Frontend\ManagementPortalShell::portal_url() . PHP_EOL;
"""

FACTS_EVAL = r"""
$db = \ClinicCore\Bootstrap\App::db();
$out = [];
foreach ([(int) getenv('ORG_ID_A'), (int) getenv('ORG_ID_B')] as $orgId) {
    $rows = $db->fetchAll('SELECT id, name, COALESCE(status, %s) AS status FROM ' . $db->table('cpms_clinics') . ' WHERE organization_id = %d ORDER BY id ASC', ['active', $orgId]);
    $clinics = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $clinics[] = [
            'id' => $id,
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'locations' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', [$id]),
            'schedules' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule') . ' WHERE clinic_id = %d', [$id]),
            'booking' => 0 !== (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s', [$id, 'booking.duration_default_min']),
        ];
    }
    $out[] = ['org' => $orgId, 'clinics' => $clinics];
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
    try:
        invalid = form.evaluate(
            "f => Array.from(f.elements).filter(e => e.willValidate && !e.checkValidity())"
            ".map(e => (e.name || e.id || e.type) + '=' + (e.value || ''))"
        )
    except Exception:  # noqa: BLE001 - diagnostics only
        invalid = []
    if invalid:
        check(f"{tag}.native_validation", False, "browser refused submit: " + ", ".join(invalid[:6]))
        return "invalid"
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
    check(f"{tag}.submitted", ok, f"notice={text[:80]}; {inventory(page)}")
    return "ok" if ok else ("ok" if "cpms-mgmt-notice" in body and "دسترسی" not in text else "denied")


def select_by_label_text(form, selector: str, text: str) -> bool:
    options = form.locator(f"{selector} option")
    for index in range(options.count()):
        option = options.nth(index)
        try:
            candidate = option.text_content() or option.inner_text() or ""
        except Exception:  # noqa: BLE001
            candidate = ""
        if text.strip() and text.strip() in (candidate or ""):
            form.locator(selector).select_option(option.get_attribute("value"))
            return True
    return False


def surface_url(portal: str, param: str, value: int) -> str:
    joiner = "&" if "?" in portal else "?"
    return f"{portal}{joiner}{param}={value}"


def submit_on_surface(page, action: str, tag: str, sshot: str, surface: str, fill=None) -> str:
    outcome = submit_form(page, action, tag, sshot, fill)
    if surface:
        page.goto(surface, wait_until="domcontentloaded")
        page.wait_for_timeout(500)
    return outcome


def overflow(page) -> int:
    return int(page.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth"))


def open_org_surface(org, portal: str, org_id: int, tag: str) -> str:
    tab = org.locator(f'a[href*="cpms-org={org_id}"]')
    if tab.count():
        tab.first.click()
        org.wait_for_load_state("domcontentloaded")
        org.wait_for_timeout(500)
    surface = surface_url(portal, "cpms-org", org_id)
    org.goto(surface, wait_until="domcontentloaded")
    org.wait_for_timeout(400)
    check(f"{tag}.org_surface", f"cpms-org={org_id}" in org.url, org.url)
    return surface


def org_start_plan(org, org_surface: str, tag: str, facility_type: str, structure: str, name: str) -> None:
    submit_on_surface(
        org, "org_facility_start", f"{tag}.start", f"{tag}-start", org_surface,
        lambda f: (
            f.locator('select[name="facility_type"]').select_option(facility_type),
            f.locator('select[name="structure"]').select_option(structure),
            f.locator('input[name="display_name"]').fill(name),
        ),
    )


def org_create_site(org, org_surface: str, tag: str, label: str, address: str) -> None:
    submit_on_surface(
        org, "org_facility_site", f"{tag}.site", f"{tag}-site", org_surface,
        lambda f: (f.locator('input[name="label"]').fill(label), f.locator('input[name="address"]').fill(address)),
    )


def org_create_clinic(org, org_surface: str, tag: str, name: str, site_label: str) -> None:
    def fill(f):
        f.locator('input[name="cpms_entity_name"]').fill(name)
        select_by_label_text(f, 'select[name="site_key"]', site_label)
        f.locator('input[name="timezone"]').fill("Asia/Tehran")

    submit_on_surface(org, "org_facility_clinic", f"{tag}.clinic", f"{tag}-clinic", org_surface, fill)


def org_designate(org, org_surface: str, tag: str, clinic_name: str, manager_login: str) -> None:
    def fill(f):
        ok = select_by_label_text(f, 'select[name="clinic_key"]', clinic_name)
        check(f"{tag}.select", ok, f"clinic_key label={clinic_name} selected={ok}")
        f.locator('input[name="identifier"]').fill(manager_login)

    submit_on_surface(org, "org_facility_designate", f"{tag}.designate", f"{tag}-designate", org_surface, fill)


def delegated_clinic_manager(browser, portal: str, user: str, password: str, tag: str,
                             clinic_name: str, day_start: int, n_clinicians: int,
                             viewport=None) -> bool:
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
    clinic_id = int(clinic_match.group(1)) if clinic_match else 0
    tab.first.click()
    page.wait_for_load_state("domcontentloaded")
    page.wait_for_timeout(600)
    body = page.content()
    check(f"{tag}.clinic_surface", clinic_name in body, page.url)
    # B2: the readiness card must render for NON-hospital flows.
    check(f"{tag}.readiness_panel", "data-cpms-clinic-readiness" in body, "per-Clinic readiness panel rendered")
    check(f"{tag}.booking_form_present", portal_form(page, "clinic_facility_booking").count() > 0,
          "delegated booking form rendered for a non-hospital flow")
    shot(page, f"{tag}-clinic-blocked")

    submit_on_surface(page, "clinic_location_create", f"{tag}.location", f"{tag}-location", clinic_surface,
                      lambda f: (f.locator('input[name="cpms_entity_name"]').fill(f"اتاق ویزیت {tag}"),
                                 f.locator('input[name="slug"]').fill(f"room-{tag}-{clinic_id}"),
                                 f.locator('input[name="timezone"]').fill("Asia/Tehran")))

    for index in range(n_clinicians):
        submit_on_surface(page, "clinic_clinician_register", f"{tag}.clinician{index}", f"{tag}-clinician{index}", clinic_surface,
                          lambda f, index=index: (f.locator('input[name="full_name"]').fill(f"دکتر {tag} {index + 1}"),
                                                  f.locator('input[name="specialty"]').fill("عمومی")))

    for index in range(n_clinicians):
        schedule_form = portal_form(page, "clinic_schedule_create")
        if schedule_form.count() == 0:
            check(f"{tag}.schedule{index}.form_present", False, "no schedule form")
            continue

        def fill_sched(f, day=day_start + index, index=index):
            f.locator('select[name="clinician_id"]').select_option(index=index)
            f.locator('select[name="location_id"]').select_option(index=0)
            if f.locator(f'select[name="day_of_week"] option[value="{day % 7}"]').count():
                f.locator('select[name="day_of_week"]').select_option(str(day % 7))
            f.locator('input[name="start_time"]').fill("09:00")
            f.locator('input[name="end_time"]').fill("12:00")

        submit_on_surface(page, "clinic_schedule_create", f"{tag}.schedule{index}", f"{tag}-schedule{index}", clinic_surface, fill_sched)

    submit_on_surface(page, "clinic_facility_booking", f"{tag}.booking", f"{tag}-booking", clinic_surface,
                      lambda f: [f.locator(f'input[name="{key}"]').fill(value) for key, value in BOOKING.items()])

    body = page.content()
    ready = 'data-cpms-clinic-readiness="ready"' in body
    check(f"{tag}.ready_after_completion", ready, "readiness panel reports the Clinic as ready")
    if viewport and viewport["width"] <= 420:
        check(f"{tag}.no_horizontal_overflow_390", overflow(page) <= 1, f"overflow={overflow(page)}px")
        shot(page, f"{tag}-mobile-390")
    ctx.close()
    return ready and clinic_id > 0


def main() -> int:
    seeded = parse_kv(wp_eval(SEED_EVAL, {"ORG_LOGIN": ORG_USER}))
    portal = seeded.get("PORTAL") or f"{BASE}/"
    org_a = int(seeded.get("ORG_ID_A", "0") or 0)
    org_b = int(seeded.get("ORG_ID_B", "0") or 0)
    check("seed.portal_url", portal.startswith("http"), portal)
    check("seed.two_organizations", org_a > 0 and org_b > 0, f"a={org_a} b={org_b}")

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

        # ================ Section A: general_clinic (multi_doctor) ================
        surface_a = open_org_surface(org, portal, org_a, "orgA")
        org_start_plan(org, surface_a, "orgA", "general_clinic", "multi_doctor", GENERAL_NAME)
        body = org.content()
        check("orgA.plan_is_multi_doctor", "multi_doctor_clinic" in body or "چندپزشکی" in body, "flow rendered")
        org_create_site(org, surface_a, "orgA", GENERAL_SITE, "تهران، خیابان اول")
        org_create_clinic(org, surface_a, "orgA", GENERAL_CLINIC_A, GENERAL_SITE)
        org_create_clinic(org, surface_a, "orgA", GENERAL_CLINIC_B, GENERAL_SITE)
        body = org.content()
        check("orgA.two_clinics", "درمانگاه‌ها: 2" in body, f"plan shows 2 Clinics; {inventory(org)}")
        check("orgA.clinic_steps_delegated", "data-cpms-clinic-steps-delegated=\"1\"" in body,
              "Clinic-level forms are NOT offered to the Organization Manager")
        check("orgA.no_booking_form", portal_form(org, "clinic_facility_booking").count() == 0,
              "the Organization Manager cannot silently write a Clinic booking policy")

        org_designate(org, surface_a, "orgA", GENERAL_CLINIC_A, MGR_A_USER)
        org_designate(org, surface_a, "orgA", GENERAL_CLINIC_B, MGR_B_USER)

        ready_a = delegated_clinic_manager(browser, portal, MGR_A_USER, MGR_A_PASS, "mgrA",
                                           GENERAL_CLINIC_A, 0, 2, viewport={"width": 390, "height": 844})
        ready_b = delegated_clinic_manager(browser, portal, MGR_B_USER, MGR_B_PASS, "mgrB",
                                           GENERAL_CLINIC_B, 3, 2)
        check("mgrA.delegated_completion", bool(ready_a), "Clinic A completed by its delegated manager")
        check("mgrB.delegated_completion", bool(ready_b), "Clinic B completed by its delegated manager")

        # Cross-Clinic refusal: manager A forges Clinic B's booking form.
        cross_ctx = browser.new_context(viewport={"width": 1366, "height": 900})
        cross = cross_ctx.new_page()
        attach(cross, "cross")
        if login(cross, portal, MGR_A_USER, MGR_A_PASS, "cross"):
            cross.goto(portal, wait_until="domcontentloaded")
            cross.wait_for_timeout(400)
            tab = cross.locator(".cpms-mgmt-tab").filter(has_text=GENERAL_CLINIC_A)
            tab.first.click()
            cross.wait_for_load_state("domcontentloaded")
            cross.wait_for_timeout(500)
            form = portal_form(cross, "clinic_facility_booking")
            forged = False
            if form.count():
                def fill_forged(f):
                    nonlocal forged
                    for key, value in BOOKING.items():
                        f.locator(f'input[name="{key}"]').fill(value)
                    forged = f.evaluate(
                        "f => { const el = f.querySelector('input[name=\"clinic_id\"]');"
                        " if (!el) return false; el.value = String(parseInt(el.value, 10) + 100000); return true; }"
                    )

                outcome = submit_form(cross, "clinic_facility_booking", "cross.forged_booking", "cross-forged", fill_forged)
                check("cross.forged_booking_refused", forged and outcome != "ok",
                      f"forged={forged} outcome={outcome}; {inventory(cross)}")
            else:
                check("cross.forged_booking_refused", False, "booking form missing on own Clinic")
        cross_ctx.close()

        # Organization Manager: review + atomic activation of Section A.
        org.goto(surface_a, wait_until="domcontentloaded")
        org.wait_for_timeout(500)
        body = org.content()
        check("orgA.review_ready", "فعال‌سازی اتمیک" in body or "همهٔ پیش‌شرط‌ها فراهم است" in body,
              "activation panel present after both delegated Clinics completed")
        shot(org, "orgA-review-ready")
        submit_on_surface(org, "org_facility_activate", "orgA.activate", "orgA-activate", surface_a)
        body = org.content()
        check("orgA.activated", "اتمیک فعال شد" in body or "فعال‌شده" in body, inventory(org))

        # ================ Section B: doctor_practice (simple_practice) ================
        surface_b = open_org_surface(org, portal, org_b, "orgB")
        org_start_plan(org, surface_b, "orgB", "doctor_practice", "single", SOLO_NAME)
        body = org.content()
        check("orgB.plan_is_simple", "simple_practice" in body or "مطب" in body, "compact flow rendered")
        org_create_clinic(org, surface_b, "orgB", SOLO_NAME, SOLO_SITE)
        body = org.content()
        check("orgB.clinic_steps_delegated", "data-cpms-clinic-steps-delegated=\"1\"" in body,
              "Clinic-level forms stay delegated for the compact flow")
        org_designate(org, surface_b, "orgB", SOLO_NAME, MGR_A_USER)

        ready_solo = delegated_clinic_manager(browser, portal, MGR_A_USER, MGR_A_PASS, "mgrSolo",
                                              SOLO_NAME, 2, 1)
        check("mgrSolo.delegated_completion", bool(ready_solo), "solo Clinic completed by its delegated manager")

        org.goto(surface_b, wait_until="domcontentloaded")
        org.wait_for_timeout(500)
        body = org.content()
        check("orgB.review_ready", "فعال‌سازی اتمیک" in body or "همهٔ پیش‌شرط‌ها فراهم است" in body,
              "activation panel present after the delegated solo Clinic completed")
        submit_on_surface(org, "org_facility_activate", "orgB.activate", "orgB-activate", surface_b)
        body = org.content()
        check("orgB.activated", "اتمیک فعال شد" in body or "فعال‌شده" in body, inventory(org))

        org_ctx.close()
        browser.close()

    facts = parse_kv(wp_eval(FACTS_EVAL, {"ORG_ID_A": str(org_a), "ORG_ID_B": str(org_b)}))
    try:
        rows = json.loads(facts.get("FACTS", "[]"))
    except Exception:  # noqa: BLE001
        rows = []
    flat = [c for org_rows in rows for c in org_rows.get("clinics", [])]
    for clinic in flat:
        check(f"db.{clinic['name']}.active", clinic["status"] == "active", json.dumps(clinic, ensure_ascii=False))
        check(f"db.{clinic['name']}.has_location", clinic["locations"] >= 1, str(clinic["locations"]))
        check(f"db.{clinic['name']}.has_schedule", clinic["schedules"] >= 1, str(clinic["schedules"]))
        check(f"db.{clinic['name']}.has_booking", clinic["booking"] is True, str(clinic["booking"]))
    check("db.clinic_count", len(flat) >= 3, f"clinics={len(flat)}")

    check("browser.no_console_errors", len(console_errors) == 0, f"count={len(console_errors)}")
    check("browser.no_page_errors", len(page_errors) == 0, f"count={len(page_errors)}")
    return report()


def report() -> int:
    failed = [r for r in results if not r["ok"]]
    print(f"\nPORTAL DELEGATED JOURNEY passed={len(results) - len(failed)} failed={len(failed)}")
    for entry in results:
        print(("PASS " if entry["ok"] else "FAIL ") + entry["check"] + (f" — {entry['detail']}" if entry["detail"] else ""))
    with open(f"{OUT}/results.json", "w", encoding="utf-8") as handle:
        json.dump({"results": results, "console_errors": console_errors, "page_errors": page_errors}, handle, ensure_ascii=False, indent=2)
    return 1 if failed else 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except PlaywrightTimeoutError as exc:
        print(f"FAIL playwright_timeout: {redact(exc)}")
        print(f"::error::delegated-journey playwright_timeout :: {redact(exc)[:300]}")
        sys.exit(2)
    except Exception as exc:  # noqa: BLE001
        print(f"FAIL harness_error: {redact(exc)}")
        print(f"::error::delegated-journey harness_error :: {type(exc).__name__}: {redact(exc)[:300]}")
        sys.exit(3)
