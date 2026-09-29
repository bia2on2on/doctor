#!/usr/bin/env python3
"""Real-browser responsive acceptance for the Staff Portal Finance module.

Phase 12 Slice 1 — read-only awaiting-payment board.
Phase 12 Slice 2 — first issuance for the CURRENT trusted Location's
consultation-completed Visits, proven through the real REST delegation and the
existing awaiting-payment board (no second finance implementation).

The harness reuses the EXISTING pilot gate entry point (fixture + Playwright in
the responsive job); no new browser infrastructure is added. Pixels are emitted
as artifacts and are not interpreted as visual approval here.
"""

import os
import re
import sys
from pathlib import Path
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
URL = os.environ["FINANCE_BOARD_URL"]
LOGIN = os.environ["FINANCE_BOARD_LOGIN"]
PASSWORD = os.environ["FINANCE_BOARD_PASS"]
CLINIC_ID = os.environ["FINANCE_BOARD_CLINIC_ID"]
LOCATION_ID = os.environ["FINANCE_BOARD_LOCATION_ID"]
INVOICE_PATIENT = os.environ["FINANCE_BOARD_INVOICE_PATIENT"]
NO_INVOICE_PATIENT = os.environ["FINANCE_BOARD_NO_INVOICE_PATIENT"]
ELIGIBLE_PATIENT = os.environ["FINANCE_BOARD_ELIGIBLE_PATIENT"]
VIEWPORTS = [
    ("mobile", 390, 844),
    ("tablet", 768, 1024),
    ("desktop", 1366, 768),
]
ISSUE_DESCRIPTION = "ویزیت و مشاورهٔ سرپایی"
ISSUE_UNIT_PRICE = 500000
OUT = Path("pilot-screenshots")
OUT.mkdir(exist_ok=True)
failures = []


def require(condition, message):
    if not condition:
        failures.append(message)
        print("FAIL " + message)
    else:
        print("PASS " + message)


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(viewport={"width": 1366, "height": 768}, locale="fa-IR")
    page = context.new_page()
    page_errors = []
    console_errors = []
    failed_requests = []
    bad_responses = []
    page.on("pageerror", lambda error: page_errors.append(str(error)))
    page.on(
        "console",
        lambda message: console_errors.append(message.text) if message.type == "error" else None,
    )
    page.on("requestfailed", lambda request: failed_requests.append(request.url))
    page.on(
        "response",
        lambda response: bad_responses.append((response.status, response.url))
        if response.status >= 500 or (response.status >= 400 and "/wp-json/" in response.url)
        else None,
    )

    page.goto(BASE + "/wp-login.php", wait_until="networkidle")
    page.fill("#user_login", LOGIN)
    page.fill("#user_pass", PASSWORD)
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle")
    require("wp-login.php" not in page.url, "synthetic Secretary login succeeds")

    for label, width, height in VIEWPORTS:
        page.set_viewport_size({"width": width, "height": height})
        page.goto(URL, wait_until="networkidle")
        page.locator('[data-role="finance-board"]').wait_for(state="visible", timeout=20000)
        page.get_by_text(INVOICE_PATIENT, exact=True).wait_for(state="visible", timeout=20000)
        page.get_by_text(NO_INVOICE_PATIENT, exact=True).wait_for(state="visible", timeout=20000)
        page.wait_for_function(
            "document.querySelectorAll('[data-role=finance-rows] tr').length === 2",
            timeout=20000,
        )

        board = page.locator('[data-role="finance-board"]')
        rows = board.locator("tbody tr")
        invoice_row = rows.filter(has_text=INVOICE_PATIENT)
        no_invoice_row = rows.filter(has_text=NO_INVOICE_PATIENT)
        require(rows.count() == 2, f"{label}: exactly the two current awaiting-payment visits render")
        require(invoice_row.count() == 1, f"{label}: invoice patient row is present exactly once")
        require(no_invoice_row.count() == 1, f"{label}: no-invoice patient row is present exactly once")
        require(invoice_row.get_by_text("1234.00", exact=False).count() == 1, f"{label}: invoice total is visible once")
        require(invoice_row.get_by_text("300.00", exact=False).count() == 1, f"{label}: paid amount is visible once")
        require(invoice_row.get_by_text("934.00", exact=False).count() == 1, f"{label}: remaining balance is visible once")
        require("فاکتور ثبت نشده" in no_invoice_row.inner_text(), f"{label}: missing invoice is explicit and has no fabricated amount")
        require("پرداخت بخشی" in invoice_row.inner_text(), f"{label}: partial invoice state is localized")
        require("SYN-FIN-" not in board.inner_text(), f"{label}: invoice number is not exposed")
        require("patient_id" not in board.inner_html() and "invoice_id" not in board.inner_html(), f"{label}: identifiers are not exposed")
        require(board.locator("button, input, form").count() == 0, f"{label}: board exposes no mutation controls")
        require(page.locator("[data-role='finance-location']").is_hidden(), f"{label}: trusted single Location auto-resolves")

        # Phase 12 Slice 2 — first-issuance panel (read-only inspection here; the
        # real issuance journey runs once after the responsive loop).
        eligible = page.locator('[data-role="finance-eligible"]')
        eligible.wait_for(state="visible", timeout=20000)
        eligible_row = eligible.locator("tbody tr").filter(has_text=ELIGIBLE_PATIENT)
        eligible_row.wait_for(state="visible", timeout=20000)
        require(eligible_row.count() == 1, f"{label}: exactly the one consultation-completed visit is offered for first issuance")
        require("مشاوره تمام‌شده" in eligible_row.inner_text(), f"{label}: the issuance list states the consultation-completed status")
        open_button = eligible_row.locator('[data-role="finance-issue-open"]')
        require(open_button.count() == 1, f"{label}: the eligible row exposes exactly one first-issuance control")
        open_button.click()
        form = eligible.locator('[data-role="finance-issue-form"]')
        form.wait_for(state="visible", timeout=10000)
        require(form.locator("input").count() == 3, f"{label}: item composition is description + quantity + unit price only (no tax/discount field)")
        require(form.locator("button").count() == 2, f"{label}: exactly one submit and one cancel control")
        require("ریال" in form.inner_text(), f"{label}: amounts are declared in Rial")
        require("card_pos" not in eligible.inner_html().lower(), f"{label}: payment-method/device modeling is not exposed as integration")
        require("کارتخوان" not in eligible.inner_text(), f"{label}: no card-terminal affordance is rendered")
        form.locator('[data-role="finance-issue-cancel"]').click()
        require(form.is_hidden(), f"{label}: cancelling hides the first-issuance form without a server mutation")
        require(
            page.locator('[data-role="finance-eligible-rows"] tr').filter(has_text=ELIGIBLE_PATIENT).count() == 1,
            f"{label}: cancelling leaves the eligible visit untouched",
        )

        dimensions = page.evaluate(
            "({width: innerWidth, scroll: document.documentElement.scrollWidth, board: document.querySelector('[data-role=finance-board]').getBoundingClientRect().width, eligible: document.querySelector('[data-role=finance-eligible]').getBoundingClientRect().width})"
        )
        require(dimensions["scroll"] <= dimensions["width"] + 1, f"{label}: no horizontal viewport overflow")
        require(dimensions["board"] <= dimensions["width"] + 1, f"{label}: board fits viewport width")
        require(dimensions["eligible"] <= dimensions["width"] + 1, f"{label}: first-issuance panel fits viewport width")
        require("/wp-admin/" not in urlparse(page.url).path, f"{label}: remains in independent front-end Staff Portal")
        page.screenshot(path=str(OUT / f"finance-board-{label}.png"), full_page=True)
        print(f"INFO {label}: {width}x{height}, clinic={CLINIC_ID}, location={LOCATION_ID}")

    # ------------------------------------------------------------------
    # Phase 12 Slice 2 — real first issuance journey (desktop viewport).
    # The invoice itself is created by the EXISTING finance service through the
    # portal route; the UI only re-reads server truth afterwards.
    # ------------------------------------------------------------------
    page.set_viewport_size({"width": 1366, "height": 768})
    page.goto(URL, wait_until="networkidle")
    eligible = page.locator('[data-role="finance-eligible"]')
    eligible.wait_for(state="visible", timeout=20000)
    page.wait_for_function(
        "document.querySelectorAll('[data-role=finance-eligible-rows] tr').length === 1",
        timeout=20000,
    )
    journey_row = eligible.locator("tbody tr").filter(has_text=ELIGIBLE_PATIENT)
    require(journey_row.count() == 1, "journey: the eligible consultation-completed visit is offered before issuance")
    journey_row.locator('[data-role="finance-issue-open"]').click()
    form = eligible.locator('[data-role="finance-issue-form"]')
    form.wait_for(state="visible", timeout=10000)
    form.locator('[data-role="finance-issue-description"]').fill(ISSUE_DESCRIPTION)
    form.locator('[data-role="finance-issue-quantity"]').fill("1")
    form.locator('[data-role="finance-issue-unit-price"]').fill(str(ISSUE_UNIT_PRICE))
    page.screenshot(path=str(OUT / "finance-issue-form-desktop.png"), full_page=True)
    form.locator('[data-role="finance-issue-submit"]').click()

    page.wait_for_function(
        "(function(){var t=document.querySelector('[data-role=finance-eligible-rows]');"
        "return t.querySelectorAll('tr').length === 1 && t.innerText.indexOf(arguments[0]) === -1;})()",
        arg=ELIGIBLE_PATIENT,
        timeout=30000,
    )
    page.wait_for_function(
        "document.querySelectorAll('[data-role=finance-rows] tr').length === 3",
        timeout=30000,
    )
    status_text = page.locator('[data-role="finance-status"]').inner_text()
    require("صادر شد" in status_text, "journey: issuance reports the delegated result")
    require(re.search(r"INV-\d{6}-\d{3,}", status_text) is not None, "journey: the delegated invoice number is reported")
    issued_row = page.locator('[data-role="finance-rows"] tr').filter(has_text=ELIGIBLE_PATIENT)
    require(issued_row.count() == 1, "journey: the issued visit appears once on the existing awaiting-payment board")
    require("500000.00" in issued_row.inner_text(), "journey: the board shows the server-authoritative total")
    require("باز" in issued_row.inner_text(), "journey: the newly issued invoice is open")
    require(
        page.locator('[data-role="finance-eligible-rows"] tr').filter(has_text=ELIGIBLE_PATIENT).count() == 0,
        "journey: the issued visit left the first-issuance list",
    )
    page.screenshot(path=str(OUT / "finance-issue-completed-desktop.png"), full_page=True)

    page.reload(wait_until="networkidle")
    page.wait_for_function(
        "document.querySelectorAll('[data-role=finance-rows] tr').length === 3",
        timeout=20000,
    )
    require(
        page.locator('[data-role="finance-eligible-rows"] tr').filter(has_text=ELIGIBLE_PATIENT).count() == 0,
        "refresh: the issued visit is still absent from the first-issuance list (server truth)",
    )
    require(
        page.locator('[data-role="finance-rows"] tr').filter(has_text=ELIGIBLE_PATIENT).count() == 1,
        "refresh: the issued visit is still on the awaiting-payment board (server truth)",
    )

    require(not page_errors, "no uncaught browser exceptions")
    require(not console_errors, "no browser console errors")
    require(not bad_responses, "no failed HTTP/API responses")
    require(not failed_requests, "no failed browser requests")
    for error in page_errors + console_errors:
        print("INFO browser error: " + error[:300])
    for status, url in bad_responses:
        print(f"INFO HTTP {status}: {url[:300]}")
    for url in failed_requests:
        print("INFO request failed: " + url[:300])

    context.close()
    browser.close()

if failures:
    print(f"FAILURES {len(failures)}")
    sys.exit(1)
print("PASS Phase 12 Staff Portal Finance board + first-issuance browser acceptance")
