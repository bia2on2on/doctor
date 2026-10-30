#!/usr/bin/env python3
"""Real wrong-mobile booking attempt — and the tenant write barrier's refusal.

WHAT WAS WRONG AND WHY THIS FILE CHANGED
----------------------------------------
The previous version waited for the final confirm button after verifying an OTP
for 09009999999, and treated its absence as a failure. It cannot appear, and must
not (`state=bookable` + the Persian barrier text is what both database engines
actually produced on PR #283's head):

  1. `OtpService::verify()` provisions a REAL WordPress user for the unknown
     mobile and calls `wp_set_auth_cookie()` — the visitor is now an
     authenticated, non-fixture actor.
  2. The next public-booking write is `/clinic/v1/booking/hold` against the demo
     Clinic, so the scoped tenant guard refuses it with HTTP 403
     `SCOPED_DEMO_TENANT_WRITE_REFUSED` (real actors must never write into the
     demo tenant). The widget sets `panelState(bookable)` from the successful
     read-only quote and then only renders the server's refusal message, so the
     panel legitimately stays `bookable` while no Hold token exists.
  3. Therefore: no Hold token, no confirm affordance, no appointment.

Waiting for the button was asserting that the barrier had been bypassed. This
drill asserts the opposite, which is strictly stronger: if the Hold ever
SUCCEEDS, a real actor just wrote into the demo tenant and the drill FAILS.

The rest of the journey is unchanged: it provisions a fresh fixture through the
real admin form, uses only the reserved synthetic mobile (never the sealed
patient mobile), proves the read-only preview names the unattributable rows, that
the destructive cleanup is blocked, and that the Persian guidance offers no
unsafe ownership claim. The OTP is captured only by the CI-only provider wrapper
outside the web root; production verification/rate limits are unchanged.
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
BARRIER_CODE = "SCOPED_DEMO_TENANT_WRITE_REFUSED"
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


def submit(page, action: str, phrase: str | None = None) -> None:
    """Submit one scoped action, asserting exactly the phrase policy it has.

    Setup and recovery are additive and are therefore one-click; only teardown is
    irreversible and still demands its typed phrase. Asserting both directions
    keeps a regression from slipping through as a merely cosmetic difference.
    """
    form = page.locator(f'form[data-cpsd-form="{action}"]')
    form.wait_for(state="visible")
    confirm = form.locator('input[name="cpdd_confirm"]')
    if phrase:
        check(f"phrase required: {action}", confirm.count() == 1)
        confirm.fill(phrase)
    else:
        check(f"no typed phrase: {action}", confirm.count() == 0)
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
                submit(admin, "enable")
            submit(admin, "provision")
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
            clinician_button = page.locator(".cpms-public-booking__clinician").first
            clinician_id = clinician_button.get_attribute("data-clinician-id") or ""
            with page.expect_response(lambda r: "/clinic/v1/availability" in r.url) as a1:
                clinician_button.click()
            check("public availability A1 succeeds", a1.value.status == 200, str(a1.value.status))
            slot = page.locator(".cpms-public-booking__slot").first
            slot.wait_for(state="visible")
            slot_id = int(slot.get_attribute("data-slot-id") or 0)
            slot_time = slot.get_attribute("data-slot-time") or ""
            check("public slot carries an explicit server slot id", slot_id > 0, str(slot_id))
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
            # The widget preserves the selection across the OTP reload, but the
            # patient-mode Hold is only started by its own quote/selection path.
            # Re-drive the SAME server slot through the real UI instead of assuming
            # a hidden restore timing; this creates no OTP request and bypasses no
            # production hold/confirm policy.
            picker = page.locator('[data-role="location-picker"]')
            if picker.count() and picker.is_visible():
                option = page.locator('[data-role="location-option"]:not([disabled])').first
                option.wait_for(state="visible")
                option.click()
                page.wait_for_load_state("networkidle")
            with page.expect_response(lambda r: "/clinic/v1/availability" in r.url) as a1b:
                page.locator(f'.cpms-public-booking__clinician[data-clinician-id="{clinician_id}"]').first.click()
            check("patient-mode availability after OTP succeeds", a1b.value.status == 200, str(a1b.value.status))
            same_slot = page.locator(f'.cpms-public-booking__slot[data-slot-id="{slot_id}"]').first
            same_slot.wait_for(state="visible")

            # In patient mode a successful read-only quote starts the Hold by
            # itself (`applyVerdict` -> `beginHold`), so every Hold response is
            # recorded from BEFORE the click rather than by clicking twice. The
            # handler only stores the response object: calling a sync Playwright
            # method from inside a dispatcher callback is not safe.
            holds: list = []

            def record_hold(response) -> None:
                if "/clinic/v1/booking/hold" in response.url:
                    holds.append(response)

            page.on("response", record_hold)
            with page.expect_response(lambda r: "/clinic/v1/booking/quote" in r.url) as a4b:
                same_slot.click()
            check("patient-mode quote of the same slot succeeds", a4b.value.status == 200, str(a4b.value.status))
            for _ in range(120):
                if holds:
                    break
                page.wait_for_timeout(250)
            hold_status = holds[0].status if holds else 0
            try:
                hold_payload = holds[0].json() if holds else {}
            except Exception:  # noqa: BLE001 - a non-JSON body is itself informative
                hold_payload = {}
            hold_code = str((hold_payload or {}).get("code") or "")
            evidence["hold_status"] = hold_status
            evidence["hold_code"] = hold_code
            evidence["appointment_created"] = False
            check(
                "the tenant write barrier REFUSES the Hold of a real (non-fixture) visitor",
                hold_status == 403 and hold_code == BARRIER_CODE,
                f"status={hold_status} code={hold_code or '(none)'}",
            )
            status_panel = page.locator('[data-role="continue-status"]')
            status_text = status_panel.inner_text()[:300] if status_panel.count() else ""
            # The exact sentence `ScopedTenantGuard::write_refusal_message()` returns,
            # so the assertion follows the product text instead of guessing at it.
            refusal_marker = "\u062d\u0633\u0627\u0628\u200c\u0647\u0627\u06cc \u0633\u0627\u062e\u062a\u06af\u06cc"
            evidence["barrier_text_present"] = refusal_marker in status_text
            check(
                "the refusal is explained in Persian on the booking page",
                refusal_marker in status_text,
                status_text or "no status text",
            )
            check(
                "no confirm affordance and no receipt are offered after the refused Hold",
                page.locator('[data-role="confirm-btn"]:visible').count() == 0
                and page.locator('[data-role="receipt"]:visible').count() == 0,
            )
            page.screenshot(path=str(EVIDENCE / "wrong-mobile-barrier-refused.png"), full_page=True)
            evidence.update({"slot_id": slot_id, "slot_time": slot_time, "mobile": WRONG_MOBILE})
            ctx.close()

            admin.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
            submit(admin, "diagnose")
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
    evidence.setdefault("hold_refused", True)
    result = {
        "ok": ok,
        "checks": CHECKS,
        "evidence": evidence,
        "limitation": "Unknown anonymous rows are preserved; no automatic ownership claim is offered. "
                      "The demo tenant write barrier refuses every real (non-fixture) actor by design, "
                      "so a wrong-mobile booking can never reach Hold/confirm.",
    }
    (EVIDENCE / "recovery-browser.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print("CPSA_RECOVERY_RESULT=" + json.dumps(
        {"ok": ok, "checks": len(CHECKS), "hold_status": evidence.get("hold_status"),
         "hold_code": evidence.get("hold_code"), "blocked_code": evidence.get("blocked_code", "")},
        ensure_ascii=False), flush=True)
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
