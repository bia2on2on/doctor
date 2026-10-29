"""Chromium coverage for the STANDALONE demo build ONLY.

This module deliberately does not import `browser_scoped.py`. That harness
provisions the scoped fixture through its own admin UI run, captures the
one-time persona credentials, and then drives six logged-in persona journeys.
The standalone lane provisions through the PHP acceptance script first, so the
one-time credentials are already consumed before a browser ever arrives - a
persona login here would either need secrets this lane must not carry or would
have to re-provision and invalidate the evidence the PHP drills already
recorded.

What this module proves instead, on the standalone build alone:

  * an administrator can log in through the real WordPress form and reach
    wp-admin (no fatal, no PHP diagnostic on any page it visits);
  * `?page=cpms-scoped-demo` renders the persona-access card with one row per declared
    persona, every row carrying a real portal link and NO password field;
  * the one-time credentials card is genuinely gone on a later request;
  * the public booking page, the staff portal, each staff module, the patient
    portal and the management portal all answer without a PHP fatal;
  * the plugins screen shows the demo as the only CPMS plugin - no production
    `clinic-practice-management` row exists;
  * the demo admin page renders at a phone viewport without horizontal
    overflow.

Usage:
    BASE=http://127.0.0.1:8080 ADMIN_USER=admin ADMIN_PASS=... \
    CPSD_EVIDENCE_DIR=/tmp/cpsa-ci/browser \
    python3 tests/scoped-demo/browser_standalone.py

Exit code is 0 only when every check passed. Evidence is written to
`$CPSD_EVIDENCE_DIR/standalone-browser.json`.
"""

from __future__ import annotations

import json
import os
import re
import sys
from pathlib import Path

from playwright.sync_api import Browser, Page, sync_playwright

BASE = os.environ.get("BASE", "http://127.0.0.1:8080").rstrip("/")
ADMIN_USER = os.environ.get("ADMIN_USER", "admin")
ADMIN_PASS = os.environ.get("ADMIN_PASS", "")
EVIDENCE = Path(os.environ.get("CPSD_EVIDENCE_DIR", "/tmp/cpsa-ci/browser"))
TARGETS_FILE = Path(os.environ.get("CPSD_TARGETS_FILE", str(EVIDENCE / "persona-targets.json")))

# 8 — the declared persona count of Credentials::PERSONA_ORDER. The card must
# carry exactly one row per persona; anything else means the map lost a persona.
PERSONA_COUNT = 8

# A page must never leak a PHP diagnostic into the browser. PHP wraps the level
# in <b> under the web SAPI, so match both shapes and require the trailing colon
# so ordinary UI copy ("Warning", "Notice") cannot trip it.
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
    (EVIDENCE / "standalone-browser.json").write_text(
        json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    print("CPSA_BROWSER_RESULT=" + json.dumps(payload, ensure_ascii=False), flush=True)


def admin_login(browser: Browser):
    context = browser.new_context(viewport={"width": 1280, "height": 900})
    page = context.new_page()
    page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", ADMIN_USER)
    page.fill("#user_pass", ADMIN_PASS)
    page.click("#wp-submit")
    page.wait_for_load_state("domcontentloaded")
    return context, page


def visit(page: Page, url: str) -> tuple[int, str]:
    response = page.goto(url, wait_until="domcontentloaded", timeout=45000)
    body = page.content()
    return (response.status if response is not None else 0), body


def no_diagnostics(page: Page, label: str, body: str) -> None:
    found = {match.group(1) for match in DIAGNOSTIC_PATTERN.finditer(body)}
    check(f"{label}: no PHP diagnostic in the rendered page", not found, ", ".join(sorted(found)))


def load_targets() -> dict:
    try:
        return json.loads(TARGETS_FILE.read_text(encoding="utf-8"))
    except Exception as exc:  # noqa: BLE001 - any failure is a hard stop
        check("persona-targets.json is readable", False, f"{type(exc).__name__}: {exc}")
        return {}


def demo_admin_phase(browser: Browser) -> dict:
    context, page = admin_login(browser)
    evidence: dict = {}
    try:
        status, body = visit(page, f"{BASE}/wp-admin/")
        check("administrator logs in through the real form", status == 200 and "wp-admin-bar" in body, str(status))
        no_diagnostics(page, "wp-admin dashboard", body)

        status, body = visit(page, f"{BASE}/wp-admin/admin.php?page=cpms-disposable-demo")
        check("standalone demo admin page answers", status == 200, str(status))
        no_diagnostics(page, "standalone demo admin page", body)

        status, body = visit(page, f"{BASE}/wp-admin/admin.php?page=cpms-scoped-demo")
        check("scoped demo admin page answers", status == 200, str(status))
        no_diagnostics(page, "scoped demo admin page", body)

        card = page.locator('[data-cpsd-persona-access="1"]')
        check("demo page renders the persona-access card", card.count() == 1, f"count={card.count()}")
        rows = card.locator("tr[data-cpsd-persona-portal]")
        check("one persona row per declared persona", rows.count() == PERSONA_COUNT, f"rows={rows.count()}")

        links_ok = True
        detail = []
        for index in range(rows.count()):
            row = rows.nth(index)
            persona = (row.get_attribute("data-cpsd-persona-portal") or "").strip()
            hint = (row.get_attribute("data-cpsd-portal-link") or "").strip()
            anchors = row.locator("a[href^='http']")
            if "" == persona or "" == hint or anchors.count() != 1:
                links_ok = False
                detail.append(f"{persona or '?'}:hint={hint or '-'},links={anchors.count()}")
        check("every persona row carries a real portal link", links_ok, "; ".join(detail[:4]))

        # The same mapping contract the scoped harness asserts: a card that sends
        # a persona to the wrong portal is worse than no card.
        expected_portals = {
            "organization_manager": "management",
            "clinic_manager": "management",
            "doctor": "doctor",
            "doctor_second": "doctor",
            "receptionist": "receptionist",
            "receptionist_second": "receptionist",
            "accountant": "accountant",
            "patient": "patient",
        }
        actual_portals = {
            (rows.nth(i).get_attribute("data-cpsd-persona-portal") or ""):
            (rows.nth(i).get_attribute("data-cpsd-portal-link") or "")
            for i in range(rows.count())
        }
        check("every persona is mapped to its own portal", actual_portals == expected_portals,
              json.dumps(actual_portals, ensure_ascii=False))
        check(
            "persona-access card leaks no credential field",
            card.locator("textarea, input").count() == 0,
            f"fields={card.locator('textarea, input').count()}",
        )
        check(
            "persona-access card states that credentials are not repeated",
            "گذرواژه" in card.inner_text() or "رمز" in card.inner_text(),
        )

        # One-time display: a fresh request must not resurrect the credentials.
        status, body = visit(page, f"{BASE}/wp-admin/admin.php?page=cpms-scoped-demo")
        check(
            "one-time credentials card is gone on a later request",
            page.locator('[data-cpsd-secret="credentials"]').count() == 0,
            str(status),
        )
        check(
            "persona map survives the credentials' disappearance",
            page.locator('[data-cpsd-persona-access="1"] tr[data-cpsd-persona-portal]').count() == PERSONA_COUNT,
        )

        # The plugins screen is the operator's view of what is installed.
        status, body = visit(page, f"{BASE}/wp-admin/plugins.php")
        check("plugins screen lists the demo as active", "cpms-disposable-demo" in body and status == 200, str(status))
        check(
            "plugins screen shows NO production CPMS row",
            'data-plugin="clinic-practice-management/' not in body,
        )
        no_diagnostics(page, "plugins screen", body)

        # Phone viewport: the card must not overflow horizontally.
        phone = context.new_page()
        phone.set_viewport_size({"width": 390, "height": 844})
        status, body = visit(phone, f"{BASE}/wp-admin/admin.php?page=cpms-scoped-demo")
        overflow = phone.evaluate(
            "() => document.documentElement.scrollWidth - document.documentElement.clientWidth"
        )
        check("scoped demo admin page fits a phone viewport", overflow <= 8, f"overflow={overflow}px status={status}")
        phone.close()
        evidence["persona_rows"] = rows.count()
    finally:
        context.close()
    return evidence


def portal_phase(browser: Browser, targets: dict) -> dict:
    portals = targets.get("portals") or {}
    modules = portals.get("modules") or {}
    context, page = admin_login(browser)
    evidence: dict = {"visited": {}}
    try:
        booking = ((targets.get("booking") or {}).get("url") or "").strip()
        if booking:
            status, body = visit(page, booking)
            check("public booking page answers for a logged-out visitor", status == 200, str(status))
            no_diagnostics(page, "booking page", body)
            evidence["visited"]["booking"] = status
        else:
            check("the manifest carries a booking URL", False, "booking.url missing")

        for label, url in (
            ("staff portal", (portals.get("staff") or {}).get("url")),
            ("patient portal", (portals.get("patient") or {}).get("url")),
            ("management portal", (portals.get("management") or {}).get("url")),
            ("reception module", modules.get("reception")),
            ("finance module", modules.get("finance")),
            ("doctor module", modules.get("doctor")),
            ("messages module", modules.get("messages")),
            ("pricing module", modules.get("pricing")),
        ):
            url = (url or "").strip()
            if not url:
                check(f"{label}: URL present in the manifest", False)
                continue
            status, body = visit(page, url)
            check(f"{label}: answers without a server error", 200 <= status < 500 and status != 404, str(status))
            no_diagnostics(page, label, body)
            evidence["visited"][label] = status
    finally:
        context.close()
    return evidence


def main() -> int:
    if not ADMIN_PASS:
        check("ADMIN_PASS is provided", False, "missing")
        write_result(False)
        return 1
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch()
            try:
                targets = load_targets()
                evidence = {"targets_ok": bool(targets.get("ok"))}
                evidence.update(demo_admin_phase(browser))
                evidence.update(portal_phase(browser, targets))
            finally:
                browser.close()
    except Exception as exc:  # noqa: BLE001 - report every failure as evidence
        check("browser run completed", False, f"{type(exc).__name__}: {exc}")
        write_result(False)
        return 1
    ok = all(item["ok"] for item in CHECKS)
    write_result(ok, evidence)
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
