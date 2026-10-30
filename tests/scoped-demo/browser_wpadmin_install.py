#!/usr/bin/env python3
"""Mission A — the REAL wp-admin installation path of the standalone demo ZIP.

A real Chromium session, logged in through the WordPress form, performs what
the owner performs on a real host:

    Plugins → Add New → Upload Plugin → (choose the ZIP) → Install Now
            → "Plugin installed successfully." → Activate Plugin
            → plugins.php shows the plugin as active.

What is asserted (and recorded as evidence):

  * the installer result page reports success and offers "Activate Plugin";
  * the activation link produced by Plugin_Installer_Skin::after() — which is
    `Plugin_Upgrader::plugin_info()` rendered into HTML — targets
    `cpms-disposable-demo/cpms-disposable-demo.php`, NOT the embedded production
    bootstrap `cpms-disposable-demo/core/clinic-practice-management.php`;
  * activating through that link does not fail with "The plugin does not have a
    valid header" (validate_plugin → no_plugin_header), nor with a fatal;
  * the plugins screen afterwards shows the demo active, exactly once, with no
    `clinic-practice-management` row, and no PHP diagnostic leaked into any of
    the rendered pages;
  * the site title and the pre-existing front page still render.

Usage:
    BASE=http://localhost:8080 ADMIN_USER=... ADMIN_PASS=... \
    CPSA_ZIP=/tmp/cpsa-ci/cpms-disposable-demo-1.0.0.zip \
    CPSD_EVIDENCE_DIR=/tmp/cpsa-ci/browser \
    python3 tests/scoped-demo/browser_wpadmin_install.py

Exit code is 0 only when every check passed. Evidence is written to
`$CPSD_EVIDENCE_DIR/wpadmin-install.json`; screenshots of the three decisive
screens (upload form, install result, plugins screen) are stored next to it.
No credential is ever written to the evidence.
"""

from __future__ import annotations

import json
import os
import re
import sys
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlparse

from playwright.sync_api import Browser, Page, sync_playwright

BASE = os.environ.get("BASE", "http://127.0.0.1:8080").rstrip("/")
ADMIN_USER = os.environ.get("ADMIN_USER", "admin")
ADMIN_PASS = os.environ.get("ADMIN_PASS", "")
ZIP = Path(os.environ.get("CPSA_ZIP", "/tmp/cpsa-ci/cpms-disposable-demo-1.0.0.zip"))
EVIDENCE = Path(os.environ.get("CPSD_EVIDENCE_DIR", "/tmp/cpsa-ci/browser"))
EXPECT_ACTIVE = os.environ.get("CPSA_EXPECT_ACTIVE", "1") == "1"

PLUGIN_DIR = "cpms-disposable-demo"
EXPECTED_FILE = f"{PLUGIN_DIR}/{PLUGIN_DIR}.php"
NESTED_FILE = f"{PLUGIN_DIR}/core/clinic-practice-management.php"

DIAGNOSTIC_PATTERN = re.compile(
    r"(Fatal error|Parse error|Uncaught|Warning|Notice|Deprecated)(</b>)?:\s"
)

CHECKS: list[dict] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    CHECKS.append({"name": name, "ok": bool(ok), "detail": str(detail)[:400]})
    print(("PASS " if ok else "FAIL ") + name + (f" — {detail}" if detail else ""), flush=True)


def write_result(ok: bool, extra: dict | None = None) -> None:
    EVIDENCE.mkdir(parents=True, exist_ok=True)
    payload = {"ok": bool(ok), "checks": CHECKS, "evidence": extra or {}}
    (EVIDENCE / "wpadmin-install.json").write_text(
        json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    print("CPSA_WPADMIN_INSTALL_RESULT=" + json.dumps(payload, ensure_ascii=False), flush=True)


def no_diagnostics(label: str, body: str) -> None:
    found = {match.group(1) for match in DIAGNOSTIC_PATTERN.finditer(body)}
    check(f"{label}: no PHP diagnostic in the rendered page", not found, ", ".join(sorted(found)))


def shot(page: Page, name: str) -> None:
    try:
        EVIDENCE.mkdir(parents=True, exist_ok=True)
        page.screenshot(path=str(EVIDENCE / f"wpadmin-install-{name}.png"), full_page=True)
    except Exception:  # noqa: BLE001 - screenshots are evidence, never a reason to fail
        pass


def admin_login(browser: Browser):
    context = browser.new_context(viewport={"width": 1280, "height": 900}, locale="fa-IR")
    page = context.new_page()
    page.set_default_timeout(45000)
    page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", ADMIN_USER)
    page.fill("#user_pass", ADMIN_PASS)
    page.click("#wp-submit")
    page.wait_for_load_state("domcontentloaded")
    return context, page


def plugin_from_activate_href(href: str) -> str:
    query = parse_qs(urlparse(href).query)
    return unquote(query.get("plugin", [""])[0])


def run(browser: Browser) -> dict:
    evidence: dict = {"zip": ZIP.name, "zip_bytes": ZIP.stat().st_size if ZIP.is_file() else 0}
    check("the package to upload exists", ZIP.is_file(), str(ZIP))
    context, page = admin_login(browser)
    try:
        response = page.goto(f"{BASE}/wp-admin/", wait_until="domcontentloaded")
        body = page.content()
        check("administrator logs in through the real form", response is not None and response.status == 200 and "wp-admin-bar" in body)

        # ---- Plugins → Add New → Upload Plugin -------------------------------
        response = page.goto(f"{BASE}/wp-admin/plugin-install.php?tab=upload", wait_until="domcontentloaded")
        body = page.content()
        check("Plugins → Add New → Upload Plugin screen answers 200", response is not None and response.status == 200, str(response.status if response else "none"))
        no_diagnostics("upload screen", body)
        form = page.locator("form.wp-upload-form")
        check("the WordPress upload form is present", form.count() == 1)
        shot(page, "1-upload-form")

        page.set_input_files("#pluginzip", str(ZIP))
        # Install Now = the real POST to update.php?action=upload-plugin.
        with page.expect_navigation(wait_until="domcontentloaded", timeout=120000):
            page.click("#install-plugin-submit")
        result_body = page.content()
        shot(page, "2-install-result")
        evidence["install_result_url"] = page.url
        no_diagnostics("install result screen", result_body)
        check("install result: WordPress reports the plugin was installed", "Plugin installed successfully" in result_body or "با موفقیت نصب شد" in result_body, page.url)
        check("install result: no header/validation failure is reported", "does not have a valid header" not in result_body and "no_plugin_header" not in result_body)
        check("install result: no destination-folder failure is reported", "Destination folder already exists" not in result_body, "")

        activate = page.locator("a.activate-now, a.button-primary[href*='action=activate']").first
        has_activate = activate.count() == 1
        check("install result: an 'Activate Plugin' action is offered", has_activate)
        href = (activate.get_attribute("href") or "") if has_activate else ""
        target = plugin_from_activate_href(href)
        evidence["activate_href_plugin"] = target
        check(
            "plugin_info() behind the Activate link targets cpms-disposable-demo/cpms-disposable-demo.php",
            target == EXPECTED_FILE,
            target or "(no href)",
        )
        check("the Activate link does NOT target the embedded production bootstrap", target != NESTED_FILE and "core/clinic-practice-management.php" not in target, target)

        # ---- Activate Plugin ---------------------------------------------------
        if has_activate and target == EXPECTED_FILE and EXPECT_ACTIVE:
            with page.expect_navigation(wait_until="domcontentloaded", timeout=120000):
                activate.click()
            after_body = page.content()
            evidence["after_activate_url"] = page.url
            shot(page, "3-plugins-screen")
            no_diagnostics("plugins screen after activation", after_body)
            check("activation: WordPress did not report an invalid header", "does not have a valid header" not in after_body)
            check("activation: WordPress did not report a fatal error", "fatal error" not in after_body.lower() and "could not be activated" not in after_body)
            check("activation: no 'unexpected output' notice", "unexpected output" not in after_body)
            activated_notice = ("Plugin activated" in after_body) or ("activate=true" in page.url) or ("فعال شد" in after_body)
            check("activation: the plugins screen confirms activation", activated_notice, page.url)
            row = page.locator(f"tr[data-plugin='{EXPECTED_FILE}']")
            check("plugins screen: exactly one row for the standalone demo", row.count() == 1, str(row.count()))
            row_class = (row.first.get_attribute("class") or "") if row.count() == 1 else ""
            check("plugins screen: the standalone demo row is active", "active" in row_class.split() and "inactive" not in row_class.split(), row_class)
            check("plugins screen: no row for the embedded bootstrap", page.locator(f"tr[data-plugin='{NESTED_FILE}']").count() == 0)
            check("plugins screen: no production clinic-practice-management row", page.locator("tr[data-slug='clinic-practice-management']").count() == 0 and "clinic-practice-management/clinic-practice-management.php" not in after_body)
            check("plugins screen: no invalid-plugin notice for the package", "plugin file does not exist" not in after_body.lower() and "has been deactivated due to an error" not in after_body)

            # The demo admin pages must be reachable right after activation.
            response = page.goto(f"{BASE}/wp-admin/admin.php?page=cpms-disposable-demo", wait_until="domcontentloaded")
            body = page.content()
            check("demo admin page answers 200 after wp-admin activation", response is not None and response.status == 200, str(response.status if response else "none"))
            no_diagnostics("demo admin page", body)
            response = page.goto(f"{BASE}/wp-admin/admin.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
            body = page.content()
            check("scoped demo admin page answers 200 after wp-admin activation (no 403)", response is not None and response.status == 200, str(response.status if response else "none"))
            no_diagnostics("scoped demo admin page", body)
            response = page.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
            check(
                "legacy Tools URL of the scoped page redirects to the canonical page instead of 403",
                response is not None and response.status == 200 and "page=cpms-scoped-demo" in page.url and "tools.php" not in page.url,
                f"{response.status if response else 'none'} -> {page.url}",
            )

        # ---- The existing site still renders ----------------------------------
        response = page.goto(f"{BASE}/", wait_until="domcontentloaded")
        front = page.content()
        check("front page still renders after installation", response is not None and response.status == 200 and "<html" in front.lower())
        no_diagnostics("front page", front)
    finally:
        context.close()
    return evidence


def main() -> int:
    if not ADMIN_PASS:
        check("ADMIN_PASS provided", False, "missing")
        write_result(False)
        return 1
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        try:
            evidence = run(browser)
        except Exception as exc:  # noqa: BLE001 - a crash is a failed acceptance, recorded as such
            check("wp-admin installation run completed without an exception", False, f"{type(exc).__name__}: {exc}")
            evidence = {}
        finally:
            browser.close()
    ok = all(item["ok"] for item in CHECKS) and bool(CHECKS)
    write_result(ok, evidence)
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
