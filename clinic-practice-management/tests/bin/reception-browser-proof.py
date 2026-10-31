#!/usr/bin/env python3
"""Synthetic-only WordPress admin recovery journey for the isolated upgrade DB.

No usernames, passwords, cookies, nonces, patient data, page bodies or raw
errors are printed or retained. Separate browser contexts isolate personas.
"""
import json
import os
import subprocess
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = os.environ.get("RECEPTION_PROOF_URL", "http://localhost:8080/upgrade-only").rstrip("/")
FIXTURE = Path("/tmp/cpms-reception-proof-fixture.json")
stage = "fixture"


def require(condition, code):
    if not condition:
        raise AssertionError(code)


def db_count(sql):
    result = subprocess.run(
        ["mysql", "--protocol=TCP", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-proot",
         "--batch", "--skip-column-names", "cpms_mtx_upgrade", "-e", sql],
        capture_output=True, text=True, timeout=20, check=False,
    )
    require(result.returncode == 0, "database-query")
    return int(result.stdout.strip())


def login(page, login_name, password):
    page.goto(BASE + "/wp-login.php", wait_until="domcontentloaded")
    page.locator("#user_login").fill(login_name)
    page.locator("#user_pass").fill(password)
    page.locator("#wp-submit").click()
    page.wait_for_load_state("domcontentloaded")
    require("wp-login.php" not in page.url, "login")


def main():
    global stage
    cfg = json.loads(FIXTURE.read_text(encoding="utf-8"))
    # Numeric SQL values are validated and originate in the private fixture.
    ids = {key: int(cfg[key]) for key in ("clinic_a", "clinic_b", "a1", "a2", "b1", "b2", "clinician", "browser_clinician")}
    require(all(value > 0 for value in ids.values()), "fixture-ids")
    page_url = BASE + "/wp-admin/admin.php?page=cpms-reception-recovery&clinic_id=" + str(ids["clinic_a"])
    foreign_sql = (
        "SELECT COUNT(*) FROM wp_cpms_clinician_locations WHERE clinician_id=" + str(ids["clinician"])
        + " AND location_id=" + str(ids["b1"]) + " AND is_primary=1"
    )
    browser_sql = (
        "SELECT COUNT(*) FROM wp_cpms_clinician_locations WHERE clinician_id=" + str(ids["browser_clinician"])
        + " AND location_id=" + str(ids["a1"])
    )
    audit_sql = "SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='CLINICIAN_LOCATION_RECOVERY'"
    require(db_count(foreign_sql) == 1 and db_count(browser_sql) == 0, "fixture-state")
    before_audit = db_count(audit_sql)

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        # Each viewport receives a fresh authenticated context, no shared cookies.
        for label, width, height in (("desktop", 1366, 768), ("mobile", 390, 844)):
            stage = label + "-login"
            context = browser.new_context(viewport={"width": width, "height": height})
            page = context.new_page()
            page.set_default_timeout(20000)
            login(page, cfg["manager_login"], cfg["password"])
            stage = label + "-render"
            response = page.goto(page_url, wait_until="domcontentloaded")
            require(response is not None and response.status == 200, "admin-status")
            content = page.locator(".cpms-reception-recovery")
            require(content.count() == 1 and content.get_attribute("dir") == "rtl", "rtl")
            require(content.evaluate("element => getComputedStyle(element).direction") == "rtl", "rtl-computed")
            require(content.locator("h1").is_visible() and "بازیابی پذیرش" in content.locator("h1").inner_text(), "persian-heading")
            form = content.locator(".cpms-recovery-form")
            require(form.is_visible(), "assignment-form")
            bounds = form.bounding_box()
            require(bounds is not None and bounds["x"] >= -1 and bounds["x"] + bounds["width"] <= width + 1, "form-overflow")
            require(page.locator('input[name="location_ids[]"][value="' + str(ids["b2"]) + '"]').count() == 0, "foreign-location-visible")
            # The main action must be on-screen and usable after scrolling at each size.
            page.locator("#cpms_rr_clinician").select_option(str(ids["browser_clinician"]))
            page.locator('input[name="location_ids[]"][value="' + str(ids["a1"]) + '"]').check()
            stage = label + "-submit"
            page.locator('.cpms-recovery-form button[type="submit"]').click()
            page.wait_for_load_state("domcontentloaded")
            notice = page.locator(".cpms-reception-recovery .notice-success")
            require(notice.count() == 1 and notice.is_visible(), "success-notice")
            require(db_count(browser_sql) == 1 and db_count(foreign_sql) == 1, "assignment-state")
            expected_audit = before_audit + 1
            require(db_count(audit_sql) == expected_audit, "idempotent-audit-count")
            if label == "mobile":
                require("قبلاً ثبت شده" in notice.inner_text(), "retry-notice")
            context.close()

        stage = "foreign-tamper"
        manager_context = browser.new_context(viewport={"width": 1366, "height": 768})
        manager_page = manager_context.new_page()
        manager_page.set_default_timeout(20000)
        login(manager_page, cfg["manager_login"], cfg["password"])
        manager_page.goto(page_url, wait_until="domcontentloaded")
        manager_page.locator("#cpms_rr_clinician").select_option(str(ids["clinician"]))
        manager_page.evaluate(
            """id => { const form=document.querySelector('.cpms-recovery-form');
            const input=document.createElement('input'); input.type='hidden';
            input.name='location_ids[]'; input.value=String(id); form.appendChild(input); }""",
            ids["b2"],
        )
        manager_page.locator('.cpms-recovery-form button[type="submit"]').click()
        manager_page.wait_for_load_state("domcontentloaded")
        require(manager_page.locator(".cpms-reception-recovery .notice-error").is_visible(), "foreign-not-denied")
        require(db_count(foreign_sql) == 1 and db_count(audit_sql) == before_audit + 1, "foreign-mutated")
        manager_context.close()

        stage = "admin-no-membership"
        admin_context = browser.new_context(viewport={"width": 1366, "height": 768})
        admin_page = admin_context.new_page()
        admin_page.set_default_timeout(20000)
        login(admin_page, cfg["admin_login"], cfg["password"])
        admin_page.goto(page_url, wait_until="domcontentloaded")
        require(admin_page.locator(".cpms-recovery-form").count() == 0, "admin-without-membership")
        require("دسترسی" in admin_page.locator("body").inner_text(), "admin-denial-message")
        admin_context.close()
        browser.close()
    print("RECEPTION_BROWSER_PROOF desktop=ok mobile=ok rtl=ok persian=ok assignment=ok retry=ok foreign_tamper=denied wp_admin_without_membership=denied")


if __name__ == "__main__":
    try:
        main()
    except Exception:
        # The stage is a fixed literal; raw exceptions can contain user data.
        print("::error title=CPMS reception browser proof::failed stage=" + stage, file=sys.stderr)
        sys.exit(1)
