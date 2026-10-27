#!/usr/bin/env python3
"""Chromium browser acceptance for scoped demo admin workflow and six logins.

This is a bounded browser test: real wp-admin form submissions and six
independent Chromium login contexts. It does NOT yet exercise every clinical,
reception, booking, finance or OTP workflow; those remain separate acceptance
items and must not be claimed from this script.
"""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ADMIN_USER = os.environ.get("ADMIN_USER", "cpsd_admin")
ADMIN_PASS = os.environ["ADMIN_PASS"]
EVIDENCE = Path(os.environ.get("CPSD_EVIDENCE_DIR", "/tmp/cpsd-browser"))
EVIDENCE.mkdir(parents=True, exist_ok=True)

CHECKS: list[dict[str, object]] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    CHECKS.append({"name": name, "ok": bool(ok), "detail": detail})
    print(("PASS " if ok else "FAIL ") + name + ((" — " + detail) if detail else ""), flush=True)
    if not ok:
        raise AssertionError(name + ": " + detail)


def submit_form(page, action: str, phrase: str) -> None:
    form = page.locator(f'form[data-cpsd-form="{action}"]')
    check(f"form present: {action}", form.count() == 1)
    form.locator('input[name="cpdd_confirm"]').fill(phrase)
    form.locator("button[type=submit]").click()
    page.wait_for_load_state("domcontentloaded")


def main() -> int:
    with sync_playwright() as p:
        browser = p.chromium.launch()
        try:
            admin_ctx = browser.new_context(viewport={"width": 1280, "height": 900})
            admin = admin_ctx.new_page()
            admin.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
            admin.fill("#user_login", ADMIN_USER)
            admin.fill("#user_pass", ADMIN_PASS)
            admin.click("#wp-submit")
            admin.wait_for_load_state("domcontentloaded")
            check("admin login", "wp-admin" in admin.url or admin.locator("#wpadminbar").count() > 0, admin.url)

            # Strict disposable mode must still refuse a non-empty site.
            admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-disposable-demo", wait_until="domcontentloaded")
            strict_fail = admin.locator('[data-cpdd-preflight="fail"]').count() == 1
            check("strict disposable mode still refuses non-empty site", strict_fail)

            admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
            check("scoped admin page renders", admin.locator('[data-cpsd-page="1"]').count() == 1)

            submit_form(admin, "enable", "I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE")
            check("mode activation accepted", admin.locator('form[data-cpsd-form="provision"]').count() == 1)
            check("scoped preflight passes in browser", admin.locator('[data-cpsd-preflight="pass"]').count() == 1)

            submit_form(admin, "provision", "CREATE_SCOPED_DEMO_ON_TEST_SITE")
            credentials_card = admin.locator('[data-cpsd-secret="credentials"]')
            check("credentials shown once", credentials_card.count() == 1)
            rows = credentials_card.locator("tr[data-cpsd-persona]")
            check("six credential rows", rows.count() == 6, f"rows={rows.count()}")
            creds: dict[str, dict[str, str]] = {}
            for i in range(rows.count()):
                row = rows.nth(i)
                persona = row.get_attribute("data-cpsd-persona") or ""
                username = row.locator("td").nth(1).inner_text().strip()
                password = row.locator("textarea.cpdd-pass-field").input_value().strip()
                creds[persona] = {"username": username, "password": password}
            check("all six credentials non-empty", all(v["username"] and v["password"] for v in creds.values()))
            admin.screenshot(path=str(EVIDENCE / "scoped-admin-provisioned.png"))

            # One-time display: a reload must not repeat credentials.
            admin.reload(wait_until="domcontentloaded")
            check("credentials not persisted after reload", admin.locator('[data-cpsd-secret="credentials"]').count() == 0)

            # Six independent browser contexts with real WP authentication.
            for persona, values in creds.items():
                ctx = browser.new_context(viewport={"width": 390, "height": 844})
                page = ctx.new_page()
                page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
                page.fill("#user_login", values["username"])
                page.fill("#user_pass", values["password"])
                page.click("#wp-submit")
                page.wait_for_load_state("domcontentloaded")
                logged_in = "wp-admin" in page.url and page.locator("#wpbody-content").count() > 0
                check(f"independent login: {persona}", logged_in, page.url)
                ctx.close()

            submit_form(admin, "diagnose", "RUN_READ_ONLY_SCOPED_DIAGNOSTIC")
            check("teardown preview passes", admin.locator('[data-cpsd-diagnostic="pass"]').count() == 1)
            submit_form(admin, "teardown", "REMOVE_ONLY_SCOPED_DEMO_DATA")
            check("authorized teardown clean", admin.locator('[data-cpsd-teardown="clean"]').count() == 1)
            admin.screenshot(path=str(EVIDENCE / "scoped-admin-teardown.png"))

            result = {
                "ok": all(bool(c["ok"]) for c in CHECKS),
                "checks": CHECKS,
                "base": BASE,
                "personas": sorted(creds.keys()),
                "limitations": [
                    "No per-role clinical/reception/finance/OTP workflow exercised by this script",
                    "Screenshot pixels not inspected",
                    "No cross-Clinic REST authorization drill in this script",
                ],
            }
            (EVIDENCE / "browser-result.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
            print("CPSD_BROWSER_RESULT=" + json.dumps({"ok": result["ok"], "checks": len(CHECKS)}, ensure_ascii=False), flush=True)
            return 0 if result["ok"] else 1
        except Exception as exc:
            result = {"ok": False, "checks": CHECKS, "error": f"{type(exc).__name__}: {exc}"}
            (EVIDENCE / "browser-result.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
            print("CPSD_BROWSER_RESULT=" + json.dumps(result, ensure_ascii=False), flush=True)
            return 1
        finally:
            browser.close()


if __name__ == "__main__":
    sys.exit(main())
