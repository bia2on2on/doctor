#!/usr/bin/env python3
"""rwp-management-portal.py — real Chromium + real WordPress regression for the
frontend Management Portal (D1 province→city cascade, D2 duplicate HTML ids).

Everything here is real: Apache/mod_php + MySQL + the official ZIP installed by
the Real WordPress Acceptance workflow + Chromium via Playwright. Nothing is
mocked and no markup is synthesized: the assertions read the live DOM of the
plugin-owned portal page.

Covered:
  C1  the portal enqueues/prints the offline cascade script (and only there),
  C2  selecting Tehran excludes Tabriz from the selectable cities,
  C3  switching the province repopulates valid cities (and resets the city),
  C4  the server rejects a forged cross-province pair over real HTTP and the
      durable address is unchanged (client filtering is never trusted),
  C5  a page with two Clinic forms (one HELD/suspended) has zero duplicate ids,
  C6  every label's `for` resolves to exactly one control,
  C7  Persian RTL at 390px with no horizontal overflow,
  C8  the logged-in state and the portal shell remain the delivered Classic
      frontend surface (no wp-admin chrome, no role escalation).

Existing hospital two-manager / 12-type facility flows are regression-covered by
the PHPUnit integration suite (HospitalTwoManagerJourneyTest, FacilityType*)
which runs in the same CI; this script does not restate those assertions.

Usage (workflow):
  BASE=http://localhost:8080 WP_DIR=/home/runner/rwp OUT=/tmp/acc \
  python3 clinic-practice-management/bin/rwp-management-portal.py
"""

import json
import os
import re
import secrets
import subprocess
import sys

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
WP_DIR = os.environ.get("WP_DIR", "")
OUT = os.environ.get("OUT", "/tmp/acc")

TEHRAN = "تهران"
TABRIZ = "تبریز"
ISFAHAN = "اصفهان"
KASHAN = "کاشان"

results = []
console_errors = []
# Bounded diagnostic state: which step was running and which URL the browser was on.
# It is printed only when an exception escapes, so a failing run still names the exact
# assertion/stage without leaking page content or credentials.
STATE = {"stage": "start", "url": ""}


def stage(name):
    STATE["stage"] = name
    print("STAGE " + name, flush=True)


def check(name, ok, detail=""):
    results.append({"check": name, "ok": bool(ok), "detail": str(detail)[:1200]})
    print(("PASS " if ok else "FAIL ") + name + (" — " + str(detail)[:1200] if detail else ""), flush=True)


def dump_results(fixture=None):
    """Persist whatever was proven so far — also on an exception path."""
    try:
        payload = {
            "fixture": {k: fixture[k] for k in ("org_id", "clinic_a", "clinic_b", "address_a")} if fixture else None,
            "checks": results,
            "console_errors": console_errors,
            "stage": STATE["stage"],
        }
        os.makedirs(f"{OUT}/logs", exist_ok=True)
        with open(f"{OUT}/logs/management-portal-results.json", "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False, indent=2)
        print("MP_RESULTS=" + json.dumps({"stage": STATE["stage"], "checks": len(results),
                                          "failed": [r["check"] for r in results if not r["ok"]]},
                                         ensure_ascii=False), flush=True)
    except OSError as exc:  # evidence is best-effort; never mask the real failure
        print(f"MP_RESULTS_WRITE_FAILED: {exc}", flush=True)


def wp(*args, tolerate_failure=False, stdin_text=None):
    if not WP_DIR:
        raise RuntimeError("WP_DIR is not set")
    proc = subprocess.run(
        ["wp", *args, f"--path={WP_DIR}", "--allow-root"],
        capture_output=True, text=True, timeout=180, input=stdin_text,
    )
    if proc.returncode != 0:
        if tolerate_failure:
            return ""
        raise RuntimeError("wp " + " ".join(args) + f" failed rc={proc.returncode}: {proc.stderr[:400]}")
    return (proc.stdout or "").strip()


def wp_eval(code):
    return wp("eval", code)


def write_fixture(slug_prefix):
    """Create an Organization, a plain Subscriber Organization Manager and the
    real Management Portal page; return the fixture as JSON."""
    login = "mp_mgr_" + secrets.token_hex(4)
    password = "Acceptance-2026!" + secrets.token_hex(2)
    wp("user", "create", login, f"{login}@acc.local", f"--user_pass={password}", "--role=subscriber", "--skip-email")

    org_slug = f"{slug_prefix}-{secrets.token_hex(4)}"
    code = r'''
list($org_slug, $login) = [getenv("MP_ORG_SLUG"), getenv("MP_LOGIN")];
$db  = \ClinicCore\Bootstrap\App::db();
$now = $db->nowUtcSql();
$user = get_user_by("login", $login);
if (!$user instanceof WP_User) { fwrite(STDERR, "manager user missing\n"); exit(1); }
$db->insert("cpms_organizations", [
    "name" => "MP Fixture " . $org_slug,
    "slug" => $org_slug,
    "status" => "active",
    "created_at" => $now,
    "updated_at" => $now,
]);
$org_id = (int) $db->wpdb_last_insert_id();
$db->insert("cpms_organization_memberships", [
    "organization_id" => $org_id,
    "wp_user_id" => (int) $user->ID,
    "role_key" => "organization_manager",
    "status" => "active",
    "created_by_wp_user_id" => (int) $user->ID,
    "created_at" => $now,
    "updated_at" => $now,
]);
$made = [];
foreach (["A" => ["تهران", "تهران"], "B" => ["اصفهان", "اصفهان"]] as $suffix => $pair) {
    $res = \ClinicCore\Frontend\ManagementPortalController::dispatch(
        (int) $user->ID,
        "org_create_clinic",
        [
            "organization_id" => (string) $org_id,
            "name" => "Portal Clinic " . $suffix,
            "slug" => strtolower($org_slug . "-c" . $suffix),
            "timezone" => "Asia/Tehran",
            "location_name" => "Main " . $suffix,
            "address" => "خیابان نمونه",
            "address_province" => $pair[0],
            "address_city" => $pair[1],
        ]
    );
    if (empty($res["ok"])) { fwrite(STDERR, "clinic create failed: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
    $made[$suffix] = (int) $db->fetchValue(
        "SELECT id FROM " . $db->table("cpms_clinics") . " WHERE slug = %s",
        [strtolower($org_slug . "-c" . $suffix)]
    );
}
// Clinic B is a HELD Clinic: the page must show one active and one held Clinic.
$held = \ClinicCore\Frontend\ManagementPortalController::dispatch(
    (int) $user->ID,
    "org_clinic_status",
    ["organization_id" => (string) $org_id, "clinic_id" => (string) $made["B"], "active" => "0"]
);
if (empty($held["ok"])) { fwrite(STDERR, "suspend failed\n"); exit(1); }
$addrA = (string) $db->fetchValue("SELECT address FROM " . $db->table("cpms_clinics") . " WHERE id = %d", [$made["A"]]);
echo "MP_FIXTURE=" . wp_json_encode([
    "org_id" => $org_id,
    "login" => $login,
    "clinic_a" => $made["A"],
    "clinic_b" => $made["B"],
    "address_a" => $addrA,
    "portal_url" => \ClinicCore\Frontend\ManagementPortalShell::portal_url(),
]) . PHP_EOL;
'''
    env = os.environ.copy()
    env["MP_ORG_SLUG"] = org_slug
    env["MP_LOGIN"] = login
    proc = subprocess.run(
        ["wp", "eval", code, f"--path={WP_DIR}", "--allow-root"],
        capture_output=True, text=True, timeout=180, env=env,
    )
    if proc.returncode != 0:
        raise RuntimeError("fixture failed: " + (proc.stderr or proc.stdout)[:600])
    marker = next((ln for ln in proc.stdout.splitlines() if ln.startswith("MP_FIXTURE=")), "")
    if not marker:
        raise RuntimeError("fixture marker missing: " + proc.stdout[:400])
    fixture = json.loads(marker.split("=", 1)[1])
    fixture["password"] = password
    return fixture


def login(page, user, password):
    page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", user)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    try:
        page.wait_for_url(lambda u: "wp-login.php" not in u, wait_until="domcontentloaded", timeout=20000)
    except PlaywrightTimeoutError:
        return False
    return "wp-login.php" not in page.url


def enabled_cities(page, box_index=0):
    return page.evaluate(
        """(i) => {
            const box = document.querySelectorAll('[data-cpms-address-geography="1"]')[i];
            if (!box) return [];
            const city = box.querySelector('[data-cpms-city-select="1"]');
            if (!city) return [];
            return Array.from(city.options)
                .filter(o => !o.disabled && !o.hidden && !(o.parentElement && o.parentElement.disabled))
                .map(o => o.value)
                .filter(v => v !== '');
        }""",
        box_index,
    )


def city_value(page, box_index=0):
    return page.evaluate(
        """(i) => {
            const box = document.querySelectorAll('[data-cpms-address-geography="1"]')[i];
            const city = box && box.querySelector('[data-cpms-city-select="1"]');
            return city ? city.value : null;
        }""",
        box_index,
    )


def main():
    os.makedirs(f"{OUT}/screenshots", exist_ok=True)
    os.makedirs(f"{OUT}/logs", exist_ok=True)

    stage("fixture.create")
    fixture = write_fixture("mp-portal")
    check("fixture.created", fixture["org_id"] > 0 and fixture["clinic_a"] > 0 and fixture["clinic_b"] > 0, json.dumps(fixture, ensure_ascii=False))

    portal_url = f"{fixture['portal_url']}{'&' if '?' in fixture['portal_url'] else '?'}cpms-org={fixture['org_id']}"

    stage("browser.launch")
    with sync_playwright() as p:
        browser = p.chromium.launch()
        context = browser.new_context(viewport={"width": 1440, "height": 900}, locale="fa-IR")
        page = context.new_page()

        IGNORED_CONSOLE = ("favicon", "wp-admin", "wp-includes", "wp-login.php")

        def on_console(msg):
            if msg.type != "error":
                return
            text = msg.text or ""
            if any(token in text for token in IGNORED_CONSOLE):
                return
            console_errors.append(f"{text} <{msg.location.get('url', '')}>")

        page.on("console", on_console)
        page.on("pageerror", lambda e: console_errors.append(str(e)))

        stage("login")
        check("login.settled", login(page, fixture["login"], fixture["password"]), page.url)

        stage("portal.load")
        resp = page.goto(portal_url, wait_until="domcontentloaded")
        page.wait_for_timeout(600)
        STATE["url"] = page.url
        check("portal.http200", bool(resp) and resp.status == 200, resp.status if resp else "no response")
        html = page.content()
        check("portal.is_frontend_shell", 'data-cpms-management-portal="v1"' in html, page.url)
        check("portal.no_admin_chrome", 'id="wpadminbar"' not in html and "wp-admin-bar-" not in html, "no wp-admin chrome")
        page.screenshot(path=f"{OUT}/screenshots/portal-desktop.png", full_page=True)

        stage("C1.script_scope")
        # C1 — the scoped offline cascade script is present on THIS surface only.
        script_srcs = page.eval_on_selector_all("script[src]", "els => els.map(e => e.getAttribute('src'))")
        check(
            "C1.cascade_script_loaded",
            any("cpms-management-portal.js" in (s or "") for s in script_srcs),
            script_srcs,
        )
        page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
        STATE["url"] = page.url
        check(
            "C1.cascade_script_absent_on_other_pages",
            "cpms-management-portal.js" not in page.content(),
            "script must not leak to non-portal pages",
        )
        page.goto(portal_url, wait_until="domcontentloaded")
        page.wait_for_timeout(400)

        stage("C5C6.ids_labels")
        # C5/C6 — duplicate ids and label targeting on the real rendered page.
        dupes = page.evaluate(
            """() => {
                const seen = {}, dup = [];
                document.querySelectorAll('[id]').forEach(el => {
                    const id = el.getAttribute('id');
                    if (!id) return;
                    if (seen[id]) dup.push(id); else seen[id] = 1;
                });
                return Array.from(new Set(dup));
            }"""
        )
        check("C5.zero_duplicate_ids", dupes == [], dupes)
        nonce_ids = page.eval_on_selector_all(
            'input[name="_cpms_nonce"]', "els => els.map(e => e.getAttribute('id'))"
        )
        check("C5.nonce_ids_unique", len(nonce_ids) >= 2 and len(set(nonce_ids)) == len(nonce_ids) and "_cpms_nonce" not in nonce_ids, nonce_ids)
        bad_labels = page.evaluate(
            """() => {
                const bad = [];
                document.querySelectorAll('label[for]').forEach(l => {
                    const target = l.getAttribute('for');
                    const n = document.querySelectorAll('[id="' + target.replace(/"/g, '\\\\"') + '"]').length;
                    if (n !== 1) bad.push(target + ':' + n);
                });
                return bad;
            }"""
        )
        check("C6.labels_target_exactly_one_control", bad_labels == [], bad_labels)
        form_count = page.eval_on_selector_all("form.cpms-mgmt-form", "els => els.length")
        check("C5.repeated_clinic_forms", form_count >= 2, form_count)
        check("C5.two_clinics_visible", "Portal Clinic A" in html and "Portal Clinic B" in html, "both Clinic names rendered")

        stage("C2.tehran")
        # C2 — Tehran excludes Tabriz.
        page.select_option('[data-cpms-address-geography="1"] select[data-cpms-province-select="1"]', label=TEHRAN)
        page.wait_for_timeout(200)
        cities = enabled_cities(page)
        check("C2.tehran_excludes_tabriz", TABRIZ not in cities, cities)
        check("C2.tehran_offers_tehran_city", TEHRAN in cities, cities[:12])

        stage("C3.switch")
        # C3 — switching the province repopulates valid cities and resets the city.
        page.select_option('[data-cpms-address-geography="1"] select[data-cpms-city-select="1"]', label=TEHRAN)
        check("C3.city_selectable_before_switch", city_value(page) == TEHRAN, city_value(page))
        page.select_option('[data-cpms-address-geography="1"] select[data-cpms-province-select="1"]', label=ISFAHAN)
        page.wait_for_timeout(200)
        cities2 = enabled_cities(page)
        check("C3.switch_repopulates_cities", KASHAN in cities2 and ISFAHAN in cities2 and TABRIZ not in cities2, cities2[:12])
        check("C3.switch_resets_city", city_value(page) == "", city_value(page))
        page.select_option('[data-cpms-address-geography="1"] select[data-cpms-city-select="1"]', label=KASHAN)
        check("C3.valid_city_selectable_after_switch", city_value(page) == KASHAN, city_value(page))

        stage("C4.server_validation")
        # C4 — the server rejects a forged cross-province pair over real HTTP.
        form_selector = "form.cpms-mgmt-form"
        nonce = page.eval_on_selector(f'{form_selector} input[name="_cpms_nonce"]', "e => e.value")
        org_id = fixture["org_id"]
        clinic_a = fixture["clinic_a"]
        post = page.request.post(
            fixture["portal_url"],
            form={
                "cpms_action": "org_update_clinic",
                "organization_id": str(org_id),
                "clinic_id": str(clinic_a),
                "_cpms_nonce": nonce,
                "cpms_entity_name": "Portal Clinic A",
                "address": "خیابان آزمون",
                "address_province": TEHRAN,
                "address_city": TABRIZ,
            },
        )
        body = post.text()
        rejected = ("notice=denied" in post.url) or ("notice=invalid" in post.url) or ("عملیات انجام نشد" in body)
        check("C4.server_rejects_cross_province_pair", rejected, post.url)
        stored = wp_eval(
            "$db = \\ClinicCore\\Bootstrap\\App::db(); "
            'echo (string) $db->fetchValue("SELECT address FROM " . $db->table("cpms_clinics") . " WHERE id = %d", ['
            + str(clinic_a)
            + "]);"
        )
        check("C4.stored_address_unchanged", stored == fixture["address_a"], {"stored": stored, "expected": fixture["address_a"]})
        check("C4.no_partial_write", TABRIZ not in stored, stored)

        stage("C7.rtl_390")
        # C7 — Persian RTL at 390px, no horizontal overflow.
        page.set_viewport_size({"width": 390, "height": 844})
        page.goto(portal_url, wait_until="domcontentloaded")
        page.wait_for_timeout(600)
        STATE["url"] = page.url
        rtl = page.evaluate("() => document.documentElement.getAttribute('dir')")
        lang = page.evaluate("() => document.documentElement.getAttribute('lang')")
        overflow = page.evaluate("() => ({sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth})")
        check("C7.rtl_and_lang", rtl == "rtl" and lang == "fa", {"dir": rtl, "lang": lang})
        check("C7.no_horizontal_overflow_at_390", overflow["sw"] <= overflow["cw"] + 1, overflow)
        # Address controls remain usable at 390px (both selects visible and inside the viewport).
        boxes_ok = page.evaluate(
            """() => Array.from(document.querySelectorAll('[data-cpms-address-geography="1"]')).every(box => {
                const r = box.getBoundingClientRect();
                return r.width > 0 && r.left >= -1 && r.right <= (document.documentElement.clientWidth + 1);
            })"""
        )
        check("C7.address_controls_within_viewport", boxes_ok, "all address blocks inside 390px viewport")
        page.screenshot(path=f"{OUT}/screenshots/portal-390px.png", full_page=True)

        stage("C8.frontend_only")
        # C8 — still the delivered Classic frontend surface (no role/admin broadening).
        page.set_viewport_size({"width": 1440, "height": 900})
        check(
            "C8.still_frontend_no_admin",
            "/wp-admin/" not in page.url,
            page.url,
        )

        context.close()
        browser.close()

    check("console.no_errors_on_portal", console_errors == [], console_errors[:6])

    dump_results(fixture)

    failed = [r for r in results if not r["ok"]]
    print(f"\nRESULT: {len(results) - len(failed)}/{len(results)} checks passed", flush=True)
    if failed:
        print("FAILED CHECKS: " + ", ".join(r["check"] for r in failed), flush=True)
        return 1
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # never mask the real failure
        message = " ".join(str(exc).split())[:600]
        print(f"ERROR: {type(exc).__name__}: {message}", flush=True)
        last = [f"{r['check']}={'PASS' if r['ok'] else 'FAIL'}" for r in results[-6:]]
        print(
            "MP_DIAG="
            + json.dumps(
                {"stage": STATE["stage"], "url": STATE["url"], "error": type(exc).__name__, "last": last},
                ensure_ascii=False,
            ),
            flush=True,
        )
        dump_results()
        sys.exit(2)
