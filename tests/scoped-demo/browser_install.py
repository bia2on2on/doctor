#!/usr/bin/env python3
"""Actual wp-admin ZIP upload/activation regression (not WP-CLI).

The CI-only mu-plugin records Plugin_Upgrader::plugin_info(), get_plugins() and
validate_plugin() from inside the real upload request. This script drives the
same screens a Product Owner uses and refuses the embedded-core basename.
"""
from __future__ import annotations

import json
import os
import re
import sys
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ADMIN_USER = os.environ.get("ADMIN_USER", "admin")
ADMIN_PASS = os.environ["ADMIN_PASS"]
ZIP = Path(os.environ.get("CPSD_INSTALL_ZIP", "/tmp/cpsa-ci/cpms-disposable-demo-1.0.0.zip"))
CI_DIR = Path(os.environ.get("CPSD_CI_DIR", "/tmp/cpsa-ci"))
EVIDENCE = Path(os.environ.get("CPSD_EVIDENCE_DIR", str(CI_DIR / "browser")))
PRESERVED_LOGIN = os.environ.get("CPSD_PRESERVED_LOGIN", "cpsd-legacy-01")
ROOT_BASENAME = "cpms-disposable-demo/cpms-disposable-demo.php"
EMBEDDED_BASENAME = "cpms-disposable-demo/core/clinic-practice-management.php"
DIAGNOSTIC = re.compile(r"(Fatal error|Parse error|Uncaught|Warning|Notice|Deprecated)(</b>)?:\s")


def main() -> int:
    EVIDENCE.mkdir(parents=True, exist_ok=True)
    checks: list[dict] = []

    def check(name: str, ok: bool, detail: str = "") -> None:
        checks.append({"name": name, "ok": bool(ok), "detail": str(detail)[:400]})
        print(("PASS " if ok else "FAIL ") + name + (f" — {detail}" if detail else ""), flush=True)

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch()
            ctx = browser.new_context(viewport={"width": 1366, "height": 900})
            page = ctx.new_page()
            page.set_default_timeout(45000)
            page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
            page.fill("#user_login", ADMIN_USER)
            page.fill("#user_pass", ADMIN_PASS)
            page.click("#wp-submit")
            page.wait_for_load_state("domcontentloaded")
            check("administrator authenticated through wp-login.php", "wp-admin" in page.url, page.url)

            page.goto(f"{BASE}/wp-admin/plugin-install.php?tab=upload", wait_until="domcontentloaded")
            page.set_input_files('input[type="file"]', str(ZIP))
            page.click("#install-plugin-submit")
            page.wait_for_load_state("domcontentloaded")
            body = page.content()
            check("upload screen reports successful installation", "Plugin installed successfully" in body or "افزونه با موفقیت نصب شد" in body,
                  page.locator("body").inner_text()[:300])
            check("upload screen has no PHP diagnostic", not DIAGNOSTIC.search(body))
            activate = page.locator('a[href*="action=activate"]').first
            activate.wait_for(state="visible")
            href = activate.get_attribute("href") or ""
            plugin = parse_qs(urlparse(href).query).get("plugin", [""])[0]
            check("WordPress offers the OUTER demo bootstrap for activation", plugin == ROOT_BASENAME, plugin)
            check("WordPress never offers the embedded core basename", plugin != EMBEDDED_BASENAME and EMBEDDED_BASENAME not in body, plugin)
            page.screenshot(path=str(EVIDENCE / "admin-upload-installed.png"), full_page=True)
            activate.click()
            page.wait_for_load_state("domcontentloaded")
            activated_body = page.content()
            check("activation screen has no PHP diagnostic", not DIAGNOSTIC.search(activated_body))
            # The Owner's dead end: activation dropped them on Plugins and the only
            # demo entry point looked like the clean-site page. Activation must land
            # on a demo console instead, and on a NON-EMPTY site that console is the
            # scoped one (the strict page is for a fresh, empty WordPress only).
            landed = page.url
            check("activation lands the operator on a demo console instead of the Plugins screen",
                  "page=cpms-scoped-demo" in landed or "page=cpms-disposable-demo" in landed, landed)
            check("a populated site is routed to the scoped demo console", "page=cpms-scoped-demo" in landed, landed)
            # One-shot activation redirects (this demo's own landing, and the embedded
            # core's first-run wizard) can each steal exactly one request. Ask for the
            # Plugins screen until WordPress actually serves it, so the assertion below
            # is about the real screen and not about who redirected first.
            for _ in range(4):
                page.goto(f"{BASE}/wp-admin/plugins.php", wait_until="domcontentloaded")
                if "plugins.php" in page.url:
                    break
            row = page.locator('tr[data-plugin="cpms-disposable-demo/cpms-disposable-demo.php"]')
            check("outer demo plugin is active on Plugins screen", row.count() == 1 and "active" in (row.get_attribute("class") or ""),
                  row.get_attribute("class") if row.count() else page.url)
            check("embedded core is not a second plugin row", page.locator(f'tr[data-plugin="{EMBEDDED_BASENAME}"]').count() == 0)
            page.screenshot(path=str(EVIDENCE / "admin-upload-activated.png"), full_page=True)

            info_path = CI_DIR / "admin-install-info.json"
            info = json.loads(info_path.read_text(encoding="utf-8")) if info_path.is_file() else {}
            check("real Plugin_Upgrader::plugin_info() selected the outer bootstrap", info.get("plugin_info") == ROOT_BASENAME,
                  json.dumps(info, ensure_ascii=False))
            check("get_plugins() discovered exactly one header in the installed directory", info.get("discovered_count") == 1,
                  str(info.get("discovered")))
            check("validate_plugin() accepts the outer bootstrap", info.get("validate_plugin") == 0, str(info.get("validate_plugin")))

            tools_link = page.locator('#menu-tools a[href*="page=cpms-scoped-demo"]')
            check("administrator navigation exposes the scoped demo under Tools", tools_link.count() == 1)

            # The clean-site screen must not be a dead end on a populated site: it
            # has to SAY that this site is not empty and hand over the scoped route.
            page.goto(f"{BASE}/wp-admin/admin.php?page=cpms-disposable-demo", wait_until="domcontentloaded")
            strict_body = page.content()
            check("clean-site screen has no PHP diagnostic on a populated site", not DIAGNOSTIC.search(strict_body))
            route_card = page.locator('[data-cpdd-route="scoped"]')
            check("clean-site screen routes a populated site to the scoped console", route_card.count() == 1, page.url)
            goto = page.locator('[data-cpdd-goto-scoped="1"]')
            href = (goto.get_attribute("href") if goto.count() else "") or ""
            check("the routing button links to the scoped demo page", goto.count() == 1 and "page=cpms-scoped-demo" in href, href)
            page.screenshot(path=str(EVIDENCE / "admin-populated-route.png"), full_page=True)
            if goto.count():
                goto.first.click()
                page.wait_for_load_state("domcontentloaded")
            check("the routing button really opens the scoped console", page.locator('[data-cpsd-page="1"]').count() == 1, page.url)
            # Nobody's existing cpsd- prefixed account may be deleted, blocked on,
            # or silently adopted: it is reported, and the preflight row passes.
            preserved = page.locator("[data-cpsd-preserved-namespace]")
            preserved_total = preserved.get_attribute("data-cpsd-preserved-namespace") if preserved.count() else "0"
            check("pre-existing cpsd- accounts are reported on the console, not removed",
                  preserved.count() == 1 and int(preserved_total or 0) >= 8, preserved_total or "missing")
            check(f"the pre-existing {PRESERVED_LOGIN} account is listed for a human",
                  page.locator(f'[data-cpsd-preserved-user="{PRESERVED_LOGIN}"]').count() == 1)
            namespace_row = page.locator('[data-cpsd-preflight] tr', has_text="فضای نام دمو")
            check("a pre-existing cpsd- account does not block the scoped preflight",
                  namespace_row.count() == 1 and "PASS" in namespace_row.first.inner_text(),
                  namespace_row.first.inner_text()[:200] if namespace_row.count() else "row missing")
            page.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
            check("scoped demo Tools page renders for the administrator", page.locator('[data-cpsd-page="1"]').count() == 1, page.url)
            check("scoped demo Tools page has no PHP diagnostic", not DIAGNOSTIC.search(page.content()))
            page.screenshot(path=str(EVIDENCE / "admin-tools-demo.png"), full_page=True)
            ctx.close()
            browser.close()
    except Exception as exc:  # noqa: BLE001
        check("admin upload/activation completed", False, f"{type(exc).__name__}: {exc}")

    ok = all(c["ok"] for c in checks)
    result = {"ok": ok, "checks": checks, "method": "wp-admin upload + Activate Plugin", "expected_basename": ROOT_BASENAME}
    (EVIDENCE / "admin-install-browser.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print("CPSA_ADMIN_INSTALL_RESULT=" + json.dumps({"ok": ok, "checks": len(checks)}, ensure_ascii=False), flush=True)
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
