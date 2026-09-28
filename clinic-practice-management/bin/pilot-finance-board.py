#!/usr/bin/env python3
"""Real-browser responsive acceptance for the read-only Staff Portal Finance board."""

import os
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
VIEWPORTS = [
    ("mobile", 390, 844),
    ("tablet", 768, 1024),
    ("desktop", 1366, 768),
]
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

        dimensions = page.evaluate(
            "({width: innerWidth, scroll: document.documentElement.scrollWidth, board: document.querySelector('[data-role=finance-board]').getBoundingClientRect().width})"
        )
        require(dimensions["scroll"] <= dimensions["width"] + 1, f"{label}: no horizontal viewport overflow")
        require(dimensions["board"] <= dimensions["width"] + 1, f"{label}: board fits viewport width")
        require("/wp-admin/" not in urlparse(page.url).path, f"{label}: remains in independent front-end Staff Portal")
        page.screenshot(path=str(OUT / f"finance-board-{label}.png"), full_page=True)
        print(f"INFO {label}: {width}x{height}, clinic={CLINIC_ID}, location={LOCATION_ID}")

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
print("PASS Phase 12 Staff Portal Finance board browser acceptance")
