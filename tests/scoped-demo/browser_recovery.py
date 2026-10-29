#!/usr/bin/env python3
"""Real public booking with a WRONG reserved synthetic mobile, then safe refusal.

Runs after the clean persona/teardown journey. It provisions a fresh fixture via
the real admin form, clicks through the public calendar/OTP/hold/confirm UI with
09009999999 (never the sealed patient mobile), then proves cleanup is blocked and
the Persian recovery guidance does not offer an unsafe ownership claim. The OTP
is captured only by the CI only provider wrapper outside the web root; production
verification/rate limits are unchanged.
"""
from __future__ import annotations

import json
import os
import time
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ADMIN_USER = os.environ.get("ADMIN_USER", "admin")
ADMIN_PASS = os.environ["ADMIN_PASS"]
CI_DIR = Path(os.environ.get("CPSD_CI_DIR", "/tmp/cpsa-ci"))
EVIDENCE = Path(os.environ.get("CPSD_EVIDENCE_DIR", str(CI_DIR / "browser")))
WRONG_MOBILE = "09009999999"
CHECKS: list[dict] = []


def annotate(title: str, message: str) -> None:
    flat = str(message).replace("%", "%25").replace("\r", "").replace("\n", " ")
    print(f"::error title={title}::{flat}", flush=True)


def check(name: str, ok: bool, detail: str = "") -> None:
    CHECKS.append({"name": name, "ok": bool(ok), "detail": str(detail)[:400]})
    print(("PASS " if ok else "FAIL ") + name + (f" — {detail}" if detail else ""), flush=True)
    if not ok:
        annotate("CPSA_RECOVERY", name + ((f" — {detail}" if detail else "")))
        raise AssertionError(name + (f": {detail}" if detail else ""))


def submit(page, action: str, phrase: str) -> None:
    form = page.locator(f'form[data-cpsd-form="{action}"]')
    form.wait_for(state="visible")
    form.locator('input[name="cpdd_confirm"]').fill(phrase)
    form.locator('button[type="submit"]').click()
    page.wait_for_load_state("domcontentloaded")


def main() -> int:
    EVIDENCE.mkdir(parents=True, exist_ok=True)
    evidence: dict = {}
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch()
            admin_ctx = browser.new_context(viewport={"width": 1366, "height": 900})
            admin = admin_ctx.new_page()
            admin.set_default_timeout(45000)
            admin.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
            admin.fill("#user_login", ADMIN_USER)
            admin.fill("#user_pass", ADMIN_PASS)
            admin.click("#wp-submit")
            admin.wait_for_load_state("domcontentloaded")
            admin.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
            if admin.locator('form[data-cpsd-form="enable"]').count():
                submit(admin, "enable", "I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE")
            submit(admin, "provision", "CREATE_SCOPED_DEMO_ON_TEST_SITE")
            check("fresh recovery fixture provisioned through admin form", admin.locator('[data-cpsd-owner-guide="1"]').count() == 1)
            booking_url = admin.locator('[data-cpsd-owner-guide="1"] a.button-primary').get_attribute("href") or ""
            check("Owner guide exposes the public booking link", booking_url.startswith("http"), booking_url)

            ctx = browser.new_context(viewport={"width": 390, "height": 844})
            page = ctx.new_page()
            page.set_default_timeout(45000)
            page.goto(booking_url, wait_until="networkidle")
            # The fixture has two Locations. The public widget is location-first;
            # choose the primary offered Location before asking for availability.
            picker = page.locator('[data-role="location-picker"]')
            if picker.count() and picker.is_visible():
                option = page.locator('[data-role="location-option"]:not([disabled])').first
                option.wait_for(state="visible")
                option.click()
                page.wait_for_load_state("networkidle")
            with page.expect_response(lambda r: "/clinic/v1/availability" in r.url) as a1:
                page.locator(".cpms-public-booking__clinician").first.click()
            check("public availability A1 succeeds", a1.value.status == 200, str(a1.value.status))
            slot = page.locator(".cpms-public-booking__slot").first
            slot.wait_for(state="visible")
            slot_id = int(slot.get_attribute("data-slot-id") or 0)
            slot_time = slot.get_attribute("data-slot-time") or ""
            check("public slot uses a realistic Iranian clinic hour", slot_time[:5] in ("09:00", "09:20", "09:40", "14:00", "14:20", "14:40"), slot_time)
            with page.expect_response(lambda r: "/clinic/v1/booking/quote" in r.url) as a4:
                slot.click()
            check("public quote A4 succeeds", a4.value.status == 200, str(a4.value.status))
            page.locator('[data-role="continue-auth"]').click()
            page.locator('[data-role="otp-mobile"]').fill(WRONG_MOBILE)
            with page.expect_response(lambda r: "/clinic/v1/otp/request" in r.url) as a2:
                page.locator('[data-auth-action="otp-request"]').click()
            check("wrong-mobile OTP request goes through production A2", a2.value.status == 200, str(a2.value.status))
            page.locator('[data-role="otp-code"]').wait_for(state="visible")

            otp_path = CI_DIR / "private" / "synthetic-otp.json"
            code = ""
            for _ in range(40):
                if otp_path.is_file():
                    code = str(json.loads(otp_path.read_text(encoding="utf-8")).get(WRONG_MOBILE, ""))
                if len(code) == 6:
                    break
                time.sleep(0.25)
            check("CI-only provider captured the reserved synthetic challenge", len(code) == 6, "code not printed")
            page.locator('[data-role="otp-code"]').fill(code)
            with page.expect_response(lambda r: "/clinic/v1/otp/verify" in r.url) as a3:
                with page.expect_navigation():
                    page.locator('[data-auth-action="otp-verify"]').click()
            check("production OTP verification issues a real session", a3.value.status == 200, str(a3.value.status))
            page.wait_for_load_state("networkidle")
            page.locator('[data-role="confirm-btn"]').wait_for(state="visible")
            if page.locator('[data-role="names-form"]').is_visible():
                page.locator('[data-role="patient-first-name"]').fill("آزمایشی")
                page.locator('[data-role="patient-last-name"]').fill("شماره‌اشتباه")
            with page.expect_response(lambda r: "/clinic/v1/booking/confirm" in r.url) as b2:
                page.locator('[data-role="confirm-btn"]').click()
            check("wrong-mobile public booking confirms through production B2", b2.value.status in (200, 201), str(b2.value.status))
            page.locator('[data-role="receipt"]').wait_for(state="visible")
            reference = page.locator('[data-role="reference-code"]').inner_text().strip()
            check("public booking receipt has a reference code", bool(reference), "reference recorded")
            page.screenshot(path=str(EVIDENCE / "wrong-mobile-receipt.png"), full_page=True)
            evidence.update({"slot_id": slot_id, "slot_time": slot_time, "reference_code": reference, "mobile": WRONG_MOBILE})
            ctx.close()

            admin.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
            submit(admin, "diagnose", "RUN_READ_ONLY_SCOPED_DIAGNOSTIC")
            diag = admin.locator('[data-cpsd-diagnostic="fail"]')
            check("cleanup preview refuses unattributable wrong-mobile rows", diag.count() == 1 and "SCOPED_FOREIGN_TENANT_ROW" in diag.inner_text(),
                  diag.inner_text()[:300] if diag.count() else "no fail card")
            submit(admin, "teardown", "REMOVE_ONLY_SCOPED_DEMO_DATA")
            notice = admin.locator('[data-cpdd-notice="error"]')
            blocked_code = notice.get_attribute("data-cpdd-code") if notice.count() else ""
            check("destructive cleanup is blocked, not silently successful", blocked_code in ("SCOPED_FOREIGN_TENANT_ROW", "TEARDOWN_BLOCKED")
                  and admin.locator('[data-cpsd-teardown="clean"]').count() == 0, str(blocked_code))
            guide = admin.locator('[data-cpsd-owner-guide="1"]').inner_text()
            check("Persian guidance explains no unsafe ownership claim or audit deletion", "ادعای مالکیت" in guide and "حسابرسی" in guide, guide[:200])
            admin.screenshot(path=str(EVIDENCE / "wrong-mobile-cleanup-blocked.png"), full_page=True)
            evidence["blocked_code"] = blocked_code
            admin_ctx.close()
            browser.close()
    except Exception as exc:  # noqa: BLE001
        check("wrong-mobile recovery drill completed", False, f"{type(exc).__name__}: {exc}")

    ok = all(c["ok"] for c in CHECKS)
    result = {"ok": ok, "checks": CHECKS, "evidence": evidence, "limitation": "Unknown anonymous rows are preserved; no automatic ownership claim is offered."}
    (EVIDENCE / "recovery-browser.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print("CPSA_RECOVERY_RESULT=" + json.dumps({"ok": ok, "checks": len(CHECKS), "blocked_code": evidence.get("blocked_code", "")}, ensure_ascii=False), flush=True)
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
