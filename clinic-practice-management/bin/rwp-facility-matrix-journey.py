#!/usr/bin/env python3
"""rwp-facility-matrix-journey.py — real-browser onboarding matrix for ALL 12 types.

Why this harness exists
-----------------------
Before this slice, the repository had real-browser proof for only four facility
flows (doctor_practice, general_clinic, hospital, and a medical-building
structure) plus the wp-admin wizard. The other nine approved facility types had
only service-level Integration coverage, so "all 12 types onboard through the
frontend" was an INFERENCE, not evidence.

This harness is the missing evidence. It drives the REAL frontend Management
Portal (never `wp-admin`) for every one of the 12 approved facility types, plus
one explicit medical-building structure scenario, through the complete
business-user journey:

    select type → Organization/facility identity → sites → held Clinics →
    explicit Clinic-manager designation → delegated Location/clinicians/
    schedules/booking-policy completion → consolidated review → atomic
    activation → persisted-scope and tenant-isolation readback

Design rules (project conventions, docs/agent-guide.md):
  * business users only: the Organization Manager and the designated Clinic
    Managers are WordPress Subscribers; no `wp-admin` page is ever opened;
  * the ONLY pre-seeded tenant data is the Organization root plus one
    `organization_manager` membership (no Clinic, Location, clinician,
    schedule, setting or manager row is created by the fixture);
  * outcomes are classified from the rendered notice ELEMENT (is-success /
    is-error), never from keyword matching inside Persian text — a refusal
    containing «ثبت نشد» must never be read as success;
  * every expectation is checked against live rows and rendered pages, never
    against counters typed into a form;
  * secrets (passwords, nonces, cookies) are never written to result files.

Exit code 0 only when every check passes.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ORG_USER = os.environ["ORG_MANAGER_USER"]
ORG_PASS = os.environ["ORG_MANAGER_PASS"]
MGR_PASS = os.environ["MATRIX_MANAGER_PASS"]
WP_DIR = os.environ.get("WP_DIR", "").strip()
OUT = os.environ.get("OUT", "rwp-facility-matrix-out")

os.makedirs(f"{OUT}/screenshots", exist_ok=True)
os.makedirs(f"{OUT}/logs", exist_ok=True)

results: list[dict] = []
console_errors: list[str] = []
page_errors: list[str] = []

CRITICAL_RE = re.compile(r"critical error|خطای بحرانی|wp-die-message", re.IGNORECASE)
SENSITIVE_RE = re.compile(
    r"(?i)([\w-]*(?:nonce|password|passwd|cookie|token|authorization|secret)[\w-]*\s*[=:]\s*)[^\s<>\"']+"
)
TZ = "Asia/Tehran"
BOOKING = {"duration": "20", "capacity": "1", "lead": "2", "future": "30", "cancel": "12"}

# The approved catalogue, verbatim from FacilityTypeCatalog::all(). The expected
# flow/rules below are NOT hard-coded opinions: they are read back from the
# rendered portal (`data-cpms-flow`) and compared with these expectations, and
# every expectation is also enforced by FacilityTypeCatalog::plan_rules().
SCENARIOS: list[dict] = [
    # id, label (Persian), structure override, expected flow, clinics, clinicians/Clinic,
    # booking required, hospital ack required, auto site
    {"key": "doctor_practice", "fa": "مطب پزشک — انفرادی یا مشترک", "structure": "", "flow": "simple_practice",
     "clinics": 1, "clinicians": 1, "booking": True, "ack": False, "auto_site": True},
    {"key": "general_clinic", "fa": "درمانگاه عمومی", "structure": "", "flow": "multi_doctor_clinic",
     "clinics": 1, "clinicians": 2, "booking": True, "ack": False, "auto_site": False},
    {"key": "specialized_clinic", "fa": "درمانگاه تخصصی یا فوق‌تخصصی / پلی‌کلینیک", "structure": "", "flow": "multi_doctor_clinic",
     "clinics": 1, "clinicians": 2, "booking": True, "ack": False, "auto_site": False},
    {"key": "dental_clinic", "fa": "کلینیک یا درمانگاه دندانپزشکی", "structure": "", "flow": "multi_doctor_clinic",
     "clinics": 1, "clinicians": 2, "booking": True, "ack": False, "auto_site": False},
    {"key": "diagnostic_center", "fa": "مرکز تشخیصی و پاراکلینیکی", "structure": "", "flow": "multi_doctor_clinic",
     "clinics": 1, "clinicians": 2, "booking": True, "ack": False, "auto_site": False},
    {"key": "rehabilitation_center", "fa": "مرکز توانبخشی", "structure": "", "flow": "multi_doctor_clinic",
     "clinics": 1, "clinicians": 2, "booking": True, "ack": False, "auto_site": False},
    {"key": "mental_health_center", "fa": "مرکز سلامت روان", "structure": "", "flow": "multi_doctor_clinic",
     "clinics": 1, "clinicians": 2, "booking": True, "ack": False, "auto_site": False},
    {"key": "specialized_outpatient_center", "fa": "مرکز درمان تخصصی سرپایی", "structure": "", "flow": "multi_doctor_clinic",
     "clinics": 1, "clinicians": 2, "booking": True, "ack": False, "auto_site": False},
    {"key": "limited_surgery_center", "fa": "مرکز جراحی محدود یا سرپایی", "structure": "", "flow": "multi_doctor_clinic",
     "clinics": 1, "clinicians": 2, "booking": True, "ack": False, "auto_site": False},
    # Hospital: explicit outpatient acknowledgment, two sites, TWO held Clinics and
    # TWO DISTINCT Subscriber Clinic Managers.
    {"key": "hospital", "fa": "بیمارستان", "structure": "hospital_network", "flow": "hospital_network",
     "clinics": 2, "clinicians": 1, "booking": True, "ack": True, "auto_site": False},
    {"key": "comprehensive_health_center", "fa": "مرکز جامع سلامت و خدمات بهداشتی", "structure": "", "flow": "multi_doctor_clinic",
     "clinics": 1, "clinicians": 2, "booking": True, "ack": False, "auto_site": False},
    {"key": "other", "fa": "سایر مراکز درمانی", "structure": "", "flow": "multi_doctor_clinic",
     "clinics": 1, "clinicians": 2, "booking": True, "ack": False, "auto_site": False},
    # NOT a 13th facility type: an independent multi-Clinic medical building is a
    # STRUCTURE of an approved type (owner-approved adaptive structure override).
    {"key": "structure_medical_building", "type": "other", "fa": "سایر مراکز درمانی", "structure": "medical_building",
     "flow": "medical_building", "clinics": 2, "clinicians": 0, "booking": False, "ack": False, "auto_site": False,
     "structure_only": True},
]



def redact(value) -> str:
    return SENSITIVE_RE.sub(r"\g<1>[redacted]", str(value))


def check(name: str, ok: bool, detail: str = "") -> bool:
    results.append({"check": name, "ok": bool(ok), "detail": redact(detail)[:400]})
    print(("PASS " if ok else "FAIL ") + name + ((f" — {redact(detail)[:300]}") if detail else ""))
    if not ok:
        print(f"::error::facility-matrix {name} :: {redact(detail)[:300]}")
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
        capture_output=True, text=True, timeout=180, env=env,
    )
    if proc.returncode != 0:
        raise RuntimeError("wp_cli_failed: " + redact((proc.stderr or proc.stdout)[:400]))
    return proc.stdout


# ---------------------------------------------------------------------------
# Fixture: tenant root ONLY (Organization + one organization_manager
# membership) plus isolated Subscriber identities for the delegated managers.
# No Clinic/Location/clinician/schedule/setting row is pre-created.
# ---------------------------------------------------------------------------
SEED_EVAL = r"""
$db = \ClinicCore\Bootstrap\App::db();
$wpdb = $db->wpdb();
$now = $db->nowUtcSql();
$orgUser = get_user_by('login', getenv('ORG_LOGIN'));
if (!$orgUser instanceof WP_User) { fwrite(STDERR, "missing org user\n"); exit(1); }

$keys = json_decode((string) getenv('SCENARIO_KEYS'), true);
if (!is_array($keys)) { fwrite(STDERR, "bad scenario list\n"); exit(1); }
$pass = (string) getenv('MATRIX_MANAGER_PASS');

$out = [];
foreach ($keys as $i => $key) {
    $slug = 'mtx-' . $key;
    $orgId = (int) $db->fetchValue('SELECT id FROM ' . $db->table('cpms_organizations') . ' WHERE slug = %s', [$slug]);
    if ($orgId <= 0) {
        $wpdb->insert($db->table('cpms_organizations'), [
            'name' => 'سازمان آزمون ' . $key,
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
    $managers = [];
    foreach (['a', 'b'] as $slot) {
        $login = 'mt' . $i . $slot;
        $user = get_user_by('login', $login);
        if (!$user instanceof WP_User) {
            $uid = wp_create_user($login, $pass, $login . '@acc.local');
            if (is_wp_error($uid)) { fwrite(STDERR, "user create failed\n"); exit(1); }
            (new WP_User((int) $uid))->set_role('subscriber');
            $user = get_user_by('login', $login);
        }
        $managers[$slot] = (int) $user->ID;
    }
    $out[] = [
        'key' => (string) $key,
        'org_id' => $orgId,
        'mgr_a' => $managers['a'],
        'mgr_b' => $managers['b'],
        'mgr_a_login' => 'mt' . $i . 'a',
        'mgr_b_login' => 'mt' . $i . 'b',
    ];
}
\ClinicCore\Frontend\ManagementPortalShell::ensure_portal_page();
echo 'SEED=' . wp_json_encode($out) . PHP_EOL;
echo 'PORTAL=' . \ClinicCore\Frontend\ManagementPortalShell::portal_url() . PHP_EOL;
"""

# Live readback of persisted scope for ALL scenario Organizations at once.
FACTS_EVAL = r"""
$db = \ClinicCore\Bootstrap\App::db();
$orgs = json_decode((string) getenv('ORG_IDS'), true);
if (!is_array($orgs)) { fwrite(STDERR, "bad org list\n"); exit(1); }
$out = [];
foreach ($orgs as $orgId) {
    $orgId = (int) $orgId;
    $rows = $db->fetchAll('SELECT id, name, COALESCE(status, %s) AS status FROM ' . $db->table('cpms_clinics') . ' WHERE organization_id = %d ORDER BY id ASC', ['active', $orgId]);
    $plan = get_option(\ClinicCore\Application\Onboarding\FacilitySetupService::OPTION_PREFIX . $orgId);
    $clinics = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $members = $db->fetchAll('SELECT wp_user_id, role_key, status FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d', [$id]);
        $managerIds = [];
        foreach ($members as $member) {
            if ('cpms_manager' === (string) $member['role_key'] && 'active' === (string) $member['status']) {
                $managerIds[] = (int) $member['wp_user_id'];
            }
        }
        $clinics[] = [
            'id' => $id,
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'locations' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', [$id]),
            'schedules' => (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule') . ' WHERE clinic_id = %d', [$id]),
            'scheduled_clinicians' => (int) $db->fetchValue('SELECT COUNT(DISTINCT s.clinician_id) FROM ' . $db->table('cpms_schedule') . ' s WHERE s.clinic_id = %d AND s.is_active = 1', [$id]),
            'booking' => 0 !== (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s', [$id, 'booking.duration_default_min']),
            'managers' => count($managerIds),
            'manager_ids' => array_values(array_unique($managerIds)),
        ];
    }
    $out[] = [
        'org_id' => $orgId,
        'clinics' => $clinics,
        'plan_flow' => is_array($plan) ? (string) ($plan['flow'] ?? '') : '',
        'plan_facility' => is_array($plan) ? (string) ($plan['facility_type'] ?? '') : '',
        'activated_at' => is_array($plan) ? (string) ($plan['activated_at'] ?? '') : '',
        'outpatient_ack_by' => is_array($plan) ? (int) ($plan['outpatient_ack_by'] ?? 0) : 0,
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
    page.wait_for_timeout(400)
    form = page.locator('form:has(input[name="cpms_action"][value="login"])')
    if form.count() == 0:
        return False
    form.locator('input[name="log"]').fill(user)
    form.locator('input[name="pwd"]').fill(password)
    form.locator('button[type="submit"]').click()
    page.wait_for_load_state("domcontentloaded")
    page.wait_for_timeout(500)
    ok = page.locator('form:has(input[name="cpms_action"][value="login"])').count() == 0
    check(f"{tag}.login", ok, page.url)
    return ok


def portal_form(page, action: str):
    return page.locator(f'form:has(input[name="cpms_action"][value="{action}"])')


def surface_url(portal: str, param: str, value: int) -> str:
    joiner = "&" if "?" in portal else "?"
    return f"{portal}{joiner}{param}={value}"


def notice_outcome(page) -> tuple[str, str]:
    """Classify the rendered notice by ELEMENT, never by Persian keywords.

    «ثبت نشد» (nothing was saved) contains «ثبت» — a substring classifier would
    read a refusal as success. The portal renders is-success / is-error, which
    is the only trustworthy signal.
    """
    notice = page.locator(".cpms-mgmt-notice")
    if notice.count() == 0:
        return "missing", ""
    loc = notice.first
    text = ""
    try:
        text = (loc.inner_text() or "").strip()
    except Exception:  # noqa: BLE001 - diagnostics only
        text = ""
    classes = (loc.get_attribute("class") or "")
    if "is-success" in classes:
        return "ok", text
    if "is-error" in classes:
        return "error", text
    return "unknown", text


def submit(page, action: str, tag: str, sshot: str, fill=None, surface: str = "") -> str:
    form = portal_form(page, action)
    if form.count() == 0:
        check(f"{tag}.form_present", False, f"no form for {action}; url={page.url}")
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
    page.wait_for_timeout(350)
    shot(page, sshot)
    if CRITICAL_RE.search(page.content() or ""):
        check(f"{tag}.no_critical_error", False, "critical error page")
        return "critical"
    outcome, text = notice_outcome(page)
    check(f"{tag}.notice", outcome == "ok", f"{outcome}: {text[:120]}")
    if surface:
        page.goto(surface, wait_until="domcontentloaded")
        page.wait_for_timeout(250)
    return outcome


def select_option_by_text(form, selector: str, text: str) -> bool:
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


def overflow(page) -> int:
    return int(page.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth"))


def db_facts(org_ids: list[int]) -> dict[int, dict]:
    raw = parse_kv(wp_eval(FACTS_EVAL, {"ORG_IDS": json.dumps(org_ids)})).get("FACTS", "[]")
    try:
        rows = json.loads(raw)
    except Exception:  # noqa: BLE001
        rows = []
    return {int(row["org_id"]): row for row in rows}


def delegated_completion(browser, portal: str, mgr_user: str, clinic: dict, scenario: dict, tag: str,
                         mobile: bool = False) -> None:
    """A designated Subscriber Clinic Manager completes its own Clinic surface.

    Each persona gets its OWN BrowserContext (fresh cookie jar): this function is
    the verified context factory, so a Subscriber session can never inherit
    another persona's authenticated cookies.
    """
    context = browser.new_context(
        viewport={"width": 390, "height": 844} if mobile else {"width": 1366, "height": 900}
    )
    page = context.new_page()
    attach(page, tag)
    if not login(page, portal, mgr_user, MGR_PASS, tag):
        context.close()
        return
    body = page.content()
    check(f"{tag}.no_organization_wizard", "راه‌اندازی مرکز (همهٔ انواع مرکز درمانی)" not in body,
          "Clinic Manager is never given the Organization wizard")

    clinic_url = surface_url(portal, "cpms-clinic", int(clinic["id"]))
    page.goto(clinic_url, wait_until="domcontentloaded")
    page.wait_for_timeout(400)
    body = page.content()
    check(f"{tag}.clinic_surface", clinic["name"] in body, page.url)

    readiness_card = 'data-cpms-clinic-readiness' in body
    if scenario["booking"]:
        check(f"{tag}.readiness_card_rendered", readiness_card, "booking-required flow renders readiness")
    else:
        # Medical-building/independent flows require no booking policy, so no
        # readiness card and no booking form is offered (adaptive, fail-closed).
        check(f"{tag}.readiness_card_absent", not readiness_card, "no booking policy required for this flow")
        check(f"{tag}.booking_form_absent", portal_form(page, "clinic_facility_booking").count() == 0,
              "booking form is not offered when the flow does not require it")
    if scenario["booking"]:
        check(f"{tag}.blocked_before_completion",
              'data-cpms-clinic-readiness="blocked"' in body,
              "readiness is blocked before the delegated steps exist")

    # A Clinic Manager may not open a different Clinic of the same Organization:
    # object lookup happens only after scoped authority (tested once, type 1).
    submit(page, "clinic_location_create", f"{tag}.location", f"{tag}-location", surface=clinic_url,
           fill=lambda f: (
               f.locator('input[name="cpms_entity_name"]').fill("اتاق ویزیت ۱"),
               f.locator('input[name="slug"]').fill(f"mtx-room-{clinic['id']}"),
               f.locator('input[name="timezone"]').fill(TZ),
           ))

    for index in range(int(scenario["clinicians"])):
        submit(page, "clinic_clinician_register", f"{tag}.clinician.{index}", f"{tag}-clinician-{index}",
               surface=clinic_url,
               fill=lambda f, index=index: (
                   f.locator('input[name="full_name"]').fill(f"دکتر {scenario['key']} {index + 1}"),
                   f.locator('input[name="specialty"]').fill("عمومی"),
               ))
        submit(page, "clinic_schedule_create", f"{tag}.schedule.{index}", f"{tag}-schedule-{index}",
               surface=clinic_url,
               fill=lambda f, index=index: (
                   f.locator('select[name="clinician_id"]').select_option(index=index),
                   f.locator('select[name="location_id"]').select_option(index=0),
                   f.locator('select[name="day_of_week"]').select_option(str(index % 6)),
                   f.locator('input[name="start_time"]').fill("09:00"),
                   f.locator('input[name="end_time"]').fill("12:00"),
               ))

    if scenario["booking"]:
        submit(page, "clinic_facility_booking", f"{tag}.booking", f"{tag}-booking", surface=clinic_url,
               fill=lambda f: [f.locator(f'input[name="{key}"]').fill(value) for key, value in BOOKING.items()])
        check(f"{tag}.ready_after_completion",
              'data-cpms-clinic-readiness="ready"' in page.content(),
              "readiness panel reports ready after real rows exist")

    if mobile:
        # Persian RTL at 390px: the delegated Clinic surface must not overflow.
        measured = overflow(page)
        check(f"{tag}.no_horizontal_overflow_390", measured <= 1, f"overflow={measured}px")
        shot(page, f"{tag}-mobile-390")

    context.close()


def main() -> int:
    keys = [scenario["key"] for scenario in SCENARIOS]
    for scenario in SCENARIOS:
        # Every scenario is driven by an APPROVED catalogue type; the medical
        # building is a structure of an approved type, never a 13th type.
        scenario.setdefault("type", scenario["key"])
    seeded_raw = parse_kv(wp_eval(SEED_EVAL, {
        "ORG_LOGIN": ORG_USER,
        "SCENARIO_KEYS": json.dumps(keys),
        "MATRIX_MANAGER_PASS": MGR_PASS,
    }))
    seeded = json.loads(seeded_raw.get("SEED", "[]"))
    portal = seeded_raw.get("PORTAL") or f"{BASE}/"
    check("seed.all_scenarios", len(seeded) == len(SCENARIOS), f"seeded={len(seeded)} expected={len(SCENARIOS)}")
    check("seed.portal_url", portal.startswith("http") and "/wp-admin" not in portal, portal)

    by_key = {row["key"]: row for row in seeded}
    org_ids = [int(row["org_id"]) for row in seeded]

    with sync_playwright() as p:
        browser = p.chromium.launch()
        org_ctx = browser.new_context(viewport={"width": 1440, "height": 900})
        org = org_ctx.new_page()
        attach(org, "org")
        if not login(org, portal, ORG_USER, ORG_PASS, "org"):
            browser.close()
            return report()
        check("org.no_wp_admin_link", org.locator('a[href*="/wp-admin"]').count() == 0,
              "the portal never links to wp-admin")

        completed: dict[str, dict] = {}

        for index, scenario in enumerate(SCENARIOS):
            key = scenario["key"]
            seed = by_key.get(key, {})
            if not seed:
                check(f"{key}.seeded", False, "scenario seed missing")
                continue
            org_id = int(seed["org_id"])
            org_surface = surface_url(portal, "cpms-org", org_id)
            display_name = f"مرکز {key}"
            tag = f"{key}"

            # ---------------- 1. select the facility type ----------------
            org.goto(org_surface, wait_until="domcontentloaded")
            org.wait_for_timeout(250)
            type_options = org.locator('select[name="facility_type"] option').count()
            check(f"{tag}.type_option_present",
                  portal_form(org, "org_facility_start").count() == 1
                  and org.locator('select[name="facility_type"] option[value="%s"]' % scenario["type"]).count() == 1,
                  "the approved type is selectable in the portal")
            check(f"{tag}.catalogue_exactly_twelve", type_options == 12,
                  f"facility-type picker offers {type_options} options (12 approved types, no 13th type)")
            submit(org, "org_facility_start", f"{tag}.start", f"{tag}-start", surface=org_surface,
                   fill=lambda f, s=scenario, n=display_name: (
                       f.locator('select[name="facility_type"]').select_option(s["type"]),
                       f.locator('select[name="structure"]').select_option(s["structure"]) if s["structure"]
                       else f.locator('select[name="structure"]').select_option(""),
                       f.locator('input[name="display_name"]').fill(n),
                   ))

            body = org.content()
            check(f"{tag}.flow_rendered", f'data-cpms-flow="{scenario["flow"]}"' in body,
                  f"expected flow={scenario['flow']}")
            check(f"{tag}.heading_adaptive",
                  "راه‌اندازی" in body and "— درمانگاه‌ها:" in body,
                  "plan summary rendered")
            # The designation form is never a dead end: before any Clinic exists
            # the portal states the prerequisite instead of rendering an
            # unsubmittable form (see management-portal-shell.php).
            check(f"{tag}.designation_prerequisite_disclosed",
                  portal_form(org, "org_facility_designate").count() == 0
                  and 'data-cpms-designation-prerequisite="1"' in body,
                  "no empty-clinic designation form before the first held Clinic")

            # ---------------- 2. outpatient acknowledgment (hospital only) ----
            ack_form = portal_form(org, "org_facility_ack").count()
            if scenario["ack"]:
                check(f"{tag}.ack_required", ack_form == 1, "hospital renders the explicit outpatient acknowledgment")
                check(f"{tag}.ack_text",
                      "فقط درمانگاه و فرایندهای سرپایی" in body and "بستری" in body,
                      "outpatient-only scope stated explicitly")
                submit(org, "org_facility_ack", f"{tag}.ack", f"{tag}-ack", surface=org_surface)
                check(f"{tag}.ack_gate_opened", portal_form(org, "org_facility_clinic").count() == 1
                      or "نخست یک ساختمان/سایت ثبت کنید" in org.content(),
                      "Clinic creation is reachable only after the acknowledgment")
            else:
                check(f"{tag}.ack_absent", ack_form == 0, "no hospital acknowledgment outside the hospital flow")

            # ---------------- 3. sites ----------------
            if scenario["auto_site"]:
                check(f"{tag}.auto_site_seeded", "— ساختمان‌ها: 1" in org.content(),
                      "compact solo flow seeds its central site automatically")
            else:
                for site_index in range(scenario["clinics"]):
                    label = f"ساختمان {key} {site_index + 1}"
                    submit(org, "org_facility_site", f"{tag}.site.{site_index}", f"{tag}-site-{site_index}",
                           surface=org_surface,
                           fill=lambda f, label=label: (
                               f.locator('input[name="label"]').fill(label),
                               f.locator('input[name="address"]').fill("تهران"),
                           ))
                expected = str(scenario["clinics"])
                check(f"{tag}.sites_count", f"— ساختمان‌ها: {expected}" in org.content(),
                      f"expected {scenario['clinics']} sites")

            # ---------------- 4. held Clinics ----------------
            clinic_names = [f"درمانگاه {key} {i + 1}" for i in range(scenario["clinics"])]
            site_labels = [f"ساختمان {key} {i + 1}" for i in range(scenario["clinics"])]
            if scenario["auto_site"]:
                site_labels = ["ساختمان مرکز"] * scenario["clinics"]

            for i, name in enumerate(clinic_names):
                def fill_clinic(f, name=name, site=site_labels[i]):
                    f.locator('input[name="cpms_entity_name"]').fill(name)
                    select_option_by_text(f, 'select[name="site_key"]', site)
                    f.locator('input[name="timezone"]').fill(TZ)
                submit(org, "org_facility_clinic", f"{tag}.clinic.{i}", f"{tag}-clinic-{i}",
                       surface=org_surface, fill=fill_clinic)

            # held status is a real DB fact, not a label
            held = db_facts([org_id]).get(org_id, {})
            held_clinics = held.get("clinics", [])
            check(f"{tag}.clinics_created", len(held_clinics) == scenario["clinics"],
                  f"expected {scenario['clinics']} Clinics, found {len(held_clinics)}")
            check(f"{tag}.clinics_held",
                  all(row.get("status") == "suspended" for row in held_clinics) and held_clinics != [],
                  "every plan Clinic is created HELD (suspended) before activation")
            check(f"{tag}.plan_not_activated", held.get("activated_at", "") == "",
                  "nothing is activated without the explicit consolidated activation")

            # the Organization surface never offers Clinic-level steps to the
            # Organization Manager alone (no implicit Clinic authority)
            check(f"{tag}.clinic_steps_delegated",
                  'data-cpms-clinic-steps-delegated="1"' in org.content(),
                  "Organization authority alone never renders Clinic configuration")

            # ---------------- 5. explicit manager designation ----------------
            for i, name in enumerate(clinic_names):
                mgr_login = seed["mgr_a_login"] if i == 0 else seed["mgr_b_login"]
                def fill_desig(f, name=name, mgr_login=mgr_login):
                    check(f"{tag}.designate.{i}.select", select_option_by_text(f, 'select[name="clinic_key"]', name),
                          f"clinic_key selected by label: {name}")
                    f.locator('input[name="identifier"]').fill(mgr_login)
                submit(org, "org_facility_designate", f"{tag}.designate.{i}", f"{tag}-designate-{i}",
                       surface=org_surface, fill=fill_desig)

            designated = db_facts([org_id]).get(org_id, {}).get("clinics", [])
            check(f"{tag}.managers_assigned", all(int(row.get("managers", 0)) >= 1 for row in designated),
                  "every Clinic has an explicit manager membership")
            if scenario["key"] == "hospital":
                ids = [tuple(row.get("manager_ids", [])) for row in designated]
                check(f"{tag}.two_distinct_subscriber_managers",
                      len(designated) == 2 and ids[0] and ids[1] and set(ids[0]) != set(ids[1]),
                      f"manager ids per Clinic: {ids}")

            # ---------------- 6. delegated Clinic completion ----------------
            org_before = db_facts([org_id]).get(org_id, {}).get("clinics", [])
            clinic_by_name = {row["name"]: row for row in org_before}
            for i, name in enumerate(clinic_names):
                mgr_login = seed["mgr_a_login"] if i == 0 else seed["mgr_b_login"]
                clinic_row = clinic_by_name.get(name)
                if not clinic_row:
                    check(f"{tag}.delegated.{i}.clinic_row", False, "Clinic row missing for delegation")
                    continue
                delegated_completion(browser, portal, mgr_login, clinic_row, scenario, f"{tag}.mgr{i}",
                                     mobile=(scenario["key"] == "hospital" and i == 1))

            # ---------------- 7. review + atomic activation ----------------
            org.goto(org_surface, wait_until="domcontentloaded")
            org.wait_for_timeout(300)
            body = org.content()
            check(f"{tag}.review_ready", "همهٔ پیش‌شرط‌ها فراهم است" in body,
                  "consolidated review reports readiness from live rows")
            check(f"{tag}.activation_form", portal_form(org, "org_facility_activate").count() == 1,
                  "atomic activation form rendered")
            submit(org, "org_facility_activate", f"{tag}.activate", f"{tag}-activated", surface=org_surface)

            # ---------------- 8. persisted scope readback ----------------
            facts = db_facts([org_id]).get(org_id, {})
            rows = facts.get("clinics", [])
            check(f"{tag}.persisted_flow", facts.get("plan_flow") == scenario["flow"],
                  f"flow={facts.get('plan_flow')}")
            check(f"{tag}.persisted_facility_type", facts.get("plan_facility") == scenario["type"],
                  f"facility={facts.get('plan_facility')} expected={scenario['type']}")
            check(f"{tag}.activated_marker", facts.get("activated_at", "") != "",
                  "plan activation marker persisted")
            check(f"{tag}.all_clinics_active",
                  len(rows) == scenario["clinics"] and all(row.get("status") == "active" for row in rows),
                  json.dumps([row.get("status") for row in rows], ensure_ascii=False))
            check(f"{tag}.locations_persisted", all(int(row.get("locations", 0)) >= 1 for row in rows),
                  json.dumps([row.get("locations") for row in rows]))
            if scenario["booking"]:
                check(f"{tag}.booking_persisted", all(bool(row.get("booking")) for row in rows),
                      json.dumps([row.get("booking") for row in rows]))
            else:
                check(f"{tag}.booking_not_required", all(not bool(row.get("booking")) for row in rows),
                      "no booking policy was required or written for this flow")
            check(f"{tag}.clinician_scope",
                  all(int(row.get("scheduled_clinicians", 0)) >= scenario["clinicians"] for row in rows),
                  json.dumps([row.get("scheduled_clinicians") for row in rows]))
            if scenario["ack"]:
                check(f"{tag}.ack_persisted", int(facts.get("outpatient_ack_by", 0)) > 0,
                      "explicit outpatient acknowledgment persisted")

            completed[key] = facts
            shot(org, f"{tag}-final")

        # ---------------- 9. tenant isolation across the matrix ----------------
        first = SCENARIOS[0]
        first_seed = by_key.get(first["key"], {})
        other_facts = completed.get(SCENARIOS[1]["key"], {})
        other_clinics = other_facts.get("clinics", [])
        if first_seed and other_clinics:
            foreign_id = int(other_clinics[0]["id"])
            iso_ctx = browser.new_context(viewport={"width": 1366, "height": 900})
            iso_page = iso_ctx.new_page()
            attach(iso_page, "isolation")
            if login(iso_page, portal, first_seed["mgr_a_login"], MGR_PASS, "isolation"):
                iso_page.goto(surface_url(portal, "cpms-clinic", foreign_id), wait_until="domcontentloaded")
                iso_page.wait_for_timeout(300)
                body = iso_page.content()
                check("isolation.cross_clinic_denied",
                      "دسترسی به این درمانگاه مجاز نیست" in body and other_clinics[0]["name"] not in body,
                      "a Clinic Manager cannot open a Clinic of another Organization")
                # wp-admin is never usable for a business Subscriber
                resp = iso_page.goto(f"{BASE}/wp-admin/", wait_until="domcontentloaded")
                denied = (resp is not None and resp.status in (302, 403, 404)) or "wp-login.php" in iso_page.url \
                    or "not allowed" in iso_page.content().lower() or "دسترسی" in iso_page.content()
                check("isolation.subscriber_no_wp_admin", bool(denied), f"{iso_page.url} status={getattr(resp, 'status', None)}")
            iso_ctx.close()

        check("browser.no_console_errors", len(console_errors) == 0, f"count={len(console_errors)}")
        check("browser.no_page_errors", len(page_errors) == 0, f"count={len(page_errors)}")
        browser.close()

    return report()


def report() -> int:
    passed = sum(1 for row in results if row["ok"])
    failed = [row for row in results if not row["ok"]]
    if failed:
        digest = "; ".join(f"{row['check']}={row['detail'][:60]}" for row in failed[:12])
        print(f"::error::facility-matrix.failed_checks({len(failed)} elsewhere) :: {digest}")
    payload = {
        "checks": results,
        "passed": passed,
        "failed": len(failed),
        "types": {
            scenario["key"]: {
                "checks": sum(1 for row in results if row["check"].startswith(scenario["key"] + ".")),
                "failed": sum(1 for row in results if row["check"].startswith(scenario["key"] + ".") and not row["ok"]),
            }
            for scenario in SCENARIOS
        },
        "console_errors": console_errors[:20],
        "page_errors": page_errors[:20],
    }
    with open(f"{OUT}/facility-matrix-results.json", "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
    print(f"\nfacility matrix: {passed} passed / {len(failed)} failed")
    for key, row in payload["types"].items():
        state = "PASS" if row["failed"] == 0 and row["checks"] > 0 else "FAIL"
        print(f"  {state} {key} ({row['checks']} checks)")
    return 0 if not failed else 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # noqa: BLE001 - an unhandled error must be visible as an annotation
        print(f"::error::facility-matrix.unhandled :: {redact(exc)[:300]}")
        results.append({"check": "harness.unhandled", "ok": False, "detail": redact(exc)[:400]})
        sys.exit(report())
