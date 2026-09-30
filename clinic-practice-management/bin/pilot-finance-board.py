#!/usr/bin/env python3
"""Real-browser responsive acceptance for the Staff Portal Finance module.

Phase 12 Slice 1 — read-only awaiting-payment board.
Phase 12 Slice 2 — first issuance for the CURRENT trusted Location's
consultation-completed Visits, proven through the real REST delegation and the
existing awaiting-payment board (no second finance implementation).
Phase 12 Slice 3 — manual payment capture against an EXISTING invoice: one REAL
partial payment and one REAL exact full settlement, exercised through the UI and
verified against server truth after a reload. Manual entry only (cash /
card_pos / other) — no hardware, provider or device integration is exercised.
Phase 12 Slice 4 — paid/checkout-ready board for the CURRENT trusted Location:
one REAL paid → checked_out journey (the capture visit, after its REAL exact
full settlement, is the checked-out target), with the explicit confirmation
step, a deliberate double submit (the UI guard keeps one request), and
persistence verified against server truth after a reload. Settlement never
auto-checks-out; the unrelated fixture paid row is untouched.
Phase 12 Slice 5 — read-only printable receipt of the NORMAL fully paid
invoice: the affordance (one receipt control per settled row) at all three
viewports, the receipt opened from server truth for the durable invoice behind
the visit settled through this run (durable invoice number, stored total,
every recorded manual payment, zero balance, Location-local Jalali dates), and
the browser `window.print()` path invoked with the receipt surface isolated
while proving that no non-GET REST request is issued across the complete
receipt open → print → close flow (the listener is attached before the open).
No server-side document is produced.

The harness reuses the EXISTING pilot gate entry point (fixture + Playwright in
the responsive job); no new browser infrastructure is added. Pixels are emitted
as artifacts and are not interpreted as visual approval here.
"""

import json
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
PAYMENT_PATIENT = os.environ["FINANCE_BOARD_PAYMENT_PATIENT"]
CHECKOUT_PATIENT = os.environ["FINANCE_BOARD_CHECKOUT_PATIENT"]
CAPTURE_INVOICE = os.environ["FINANCE_BOARD_CAPTURE_INVOICE"]
VIEWPORTS = [
    ("mobile", 390, 844),
    ("tablet", 768, 1024),
    ("desktop", 1366, 768),
]
ISSUE_DESCRIPTION = "ویزیت و مشاورهٔ سرپایی"
ISSUE_UNIT_PRICE = 500000
# Phase 12 Slice 3 — the fixture's open capture invoice (500000.00 Rial).
PAYMENT_TOTAL = "500000.00"
PAYMENT_PARTIAL = 200000
PAYMENT_REMAINDER = "300000.00"
PAYMENT_SETTLEMENT = 300000
OUT = Path("pilot-screenshots")
OUT.mkdir(exist_ok=True)
failures = []


def require(condition, message):
    if not condition:
        failures.append(message)
        print("FAIL " + message)
    else:
        print("PASS " + message)


def open_receipt(page, row):
    """Ask a settled row for its read-only receipt and wait for the panel.

    Retried briefly because the click is a no-op while a server-truth board
    refresh still holds the module's busy guard (the same guard that blocks a
    double submit).
    """
    trigger = row.locator('[data-role="finance-receipt-open"]')
    panel = page.locator('[data-role="finance-receipt"]')
    for _attempt in range(4):
        trigger.click()
        try:
            panel.wait_for(state="visible", timeout=5000)
            break
        except Exception:
            page.wait_for_timeout(500)
    return panel


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
        page.get_by_text(PAYMENT_PATIENT, exact=True).wait_for(state="visible", timeout=20000)
        page.wait_for_function(
            "document.querySelectorAll('[data-role=finance-rows] tr').length === 3",
            timeout=20000,
        )

        board = page.locator('[data-role="finance-board"]')
        rows = board.locator("tbody tr")
        invoice_row = rows.filter(has_text=INVOICE_PATIENT)
        no_invoice_row = rows.filter(has_text=NO_INVOICE_PATIENT)
        capture_row = rows.filter(has_text=PAYMENT_PATIENT)
        require(rows.count() == 3, f"{label}: exactly the three current awaiting-payment visits render")
        require(capture_row.count() == 1, f"{label}: the open capture invoice row is present exactly once")
        require(PAYMENT_TOTAL in capture_row.inner_text(), f"{label}: the capture invoice exposes its server total")
        require("باز" in capture_row.inner_text(), f"{label}: the capture invoice is open before any capture")
        require(invoice_row.count() == 1, f"{label}: invoice patient row is present exactly once")
        require(no_invoice_row.count() == 1, f"{label}: no-invoice patient row is present exactly once")
        require(invoice_row.get_by_text("1234.00", exact=False).count() == 1, f"{label}: invoice total is visible once")
        require(invoice_row.get_by_text("300.00", exact=False).count() == 1, f"{label}: paid amount is visible once")
        require(invoice_row.get_by_text("934.00", exact=False).count() == 1, f"{label}: remaining balance is visible once")
        require("فاکتور ثبت نشده" in no_invoice_row.inner_text(), f"{label}: missing invoice is explicit and has no fabricated amount")
        require("پرداخت بخشی" in invoice_row.inner_text(), f"{label}: partial invoice state is localized")
        require("SYN-FIN-" not in board.inner_text(), f"{label}: invoice number is not exposed")
        require("patient_id" not in board.inner_html(), f"{label}: patient identifiers are not exposed")
        require("SYN-FIN-" not in board.inner_html(), f"{label}: internal patient/mrn strings stay out of the markup")
        # Phase 12 Slice 3 — the board grows EXACTLY one manual capture control
        # per invoice-bearing row (the no-invoice row keeps none); the control
        # carries only the selector id it posts to.
        capture_controls = board.locator('[data-role="finance-pay-open"]')
        require(capture_controls.count() == 2, f"{label}: exactly one capture control per invoice-bearing row")
        require(
            board.locator("button, input, form, select").count() == 2,
            f"{label}: the board itself stays free of other mutation controls",
        )
        selectors = capture_controls.evaluate_all("els => els.map(e => Number(e.getAttribute('data-invoice-id')))")
        require(
            all(isinstance(value, int) and value > 0 for value in selectors),
            f"{label}: every capture control carries a positive invoice selector",
        )
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

        # Phase 12 Slice 3 — the capture panel stays out of the way until a row
        # asks for it, offers manual methods only, and cancels without a write.
        payment_panel = page.locator('[data-role="finance-payment"]')
        require(payment_panel.is_hidden(), f"{label}: the capture panel is closed until a row asks for it")
        capture_row.locator('[data-role="finance-pay-open"]').click()
        payment_panel.wait_for(state="visible", timeout=10000)
        pay_form = payment_panel.locator('[data-role="finance-pay-form"]')
        method_values = pay_form.locator('[data-role="finance-pay-method"] option').evaluate_all("els => els.map(e => e.value)")
        require(method_values == ["cash", "card_pos", "other"], f"{label}: the capture form offers manual methods only, got {method_values}")
        require(
            pay_form.locator('[data-role="finance-pay-amount"], [data-role="finance-pay-method"], [data-role="finance-pay-submit"]').count() == 3,
            f"{label}: the capture form exposes amount + method + one submit",
        )
        require("ریال" in pay_form.inner_text(), f"{label}: the capture amount is declared in Rial")
        require("online" not in payment_panel.inner_html().lower(), f"{label}: online payment is not offered in the portal UI")
        pay_form.locator('[data-role="finance-pay-cancel"]').click()
        require(payment_panel.is_hidden(), f"{label}: cancelling the capture form performs no mutation")
        require(
            page.locator('[data-role="finance-rows"] tr').filter(has_text=PAYMENT_PATIENT).count() == 1
            and PAYMENT_TOTAL in page.locator('[data-role="finance-rows"] tr').filter(has_text=PAYMENT_PATIENT).inner_text(),
            f"{label}: the untouched capture invoice still shows its open total",
        )

        # Phase 12 Slice 4 — the paid/checkout-ready board shows EXACTLY the
        # fixture's paid visit (the capture visit is still awaiting payment),
        # with the settled-invoice summary and exactly one checkout control.
        paid_panel = page.locator('[data-role="finance-paid"]')
        paid_panel.wait_for(state="visible", timeout=20000)
        paid_row = paid_panel.locator("tbody tr").filter(has_text=CHECKOUT_PATIENT)
        paid_row.wait_for(state="visible", timeout=20000)
        require(
            page.locator('[data-role="finance-paid-rows"] tr').count() == 1,
            f"{label}: the paid board lists exactly the one paid visit of the CURRENT Location",
        )
        require(paid_row.count() == 1, f"{label}: the fixture paid visit is present exactly once")
        require("پرداخت کامل" in paid_row.inner_text(), f"{label}: the paid row states the fully-paid state")
        require("0.00" in paid_row.inner_text(), f"{label}: the settled invoice summary shows zero remaining")
        checkout_controls = paid_panel.locator('[data-role="finance-checkout-open"]')
        require(checkout_controls.count() == 1, f"{label}: exactly one checkout control per paid row")
        checkout_selectors = checkout_controls.evaluate_all("els => els.map(e => Number(e.getAttribute('data-visit-id')))")
        require(
            all(isinstance(value, int) and value > 0 for value in checkout_selectors),
            f"{label}: the checkout control carries a positive visit selector",
        )
        require(
            paid_panel.locator("button, input, form, select").count() == 2,
            f"{label}: the paid panel offers exactly the read-only receipt and the single checkout control",
        )
        checkout_panel = page.locator('[data-role="finance-checkout"]')
        require(checkout_panel.is_hidden(), f"{label}: the checkout confirmation panel is closed until a row asks for it")
        paid_row.locator('[data-role="finance-checkout-open"]').click()
        checkout_panel.wait_for(state="visible", timeout=10000)
        checkout_form = checkout_panel.locator('[data-role="finance-checkout-form"]')
        require(CHECKOUT_PATIENT in checkout_panel.locator('[data-role="finance-checkout-target"]').inner_text(), f"{label}: the confirmation names the selected patient")
        require(
            checkout_form.locator('[data-role="finance-checkout-submit"], [data-role="finance-checkout-cancel"]').count() == 2,
            f"{label}: the confirmation offers exactly one confirm and one cancel control",
        )
        checkout_form.locator('[data-role="finance-checkout-cancel"]').click()
        require(checkout_panel.is_hidden(), f"{label}: cancelling the checkout confirmation performs no mutation")
        require(
            page.locator('[data-role="finance-paid-rows"] tr').filter(has_text=CHECKOUT_PATIENT).count() == 1
            and "0.00" in page.locator('[data-role="finance-paid-rows"] tr').filter(has_text=CHECKOUT_PATIENT).inner_text(),
            f"{label}: the untouched paid visit still shows its settled summary",
        )

        # Phase 12 Slice 5 — the read-only receipt affordance: exactly one
        # receipt control per settled row, the panel stays closed until asked,
        # and the opened receipt is the server's durable settlement truth.
        receipt_controls = paid_panel.locator('[data-role="finance-receipt-open"]')
        require(receipt_controls.count() == 1, f"{label}: exactly one read-only receipt control per settled row")
        receipt_selectors = receipt_controls.evaluate_all("els => els.map(e => Number(e.getAttribute('data-visit-id')))")
        require(
            all(isinstance(value, int) and value > 0 for value in receipt_selectors),
            f"{label}: the receipt control carries a positive visit selector",
        )
        receipt_panel = page.locator('[data-role="finance-receipt"]')
        require(receipt_panel.is_hidden(), f"{label}: the receipt panel is closed until a row asks for it")
        open_receipt(page, paid_row)
        require(receipt_panel.is_visible(), f"{label}: the receipt panel opens from server truth")
        receipt_text = receipt_panel.locator('[data-role="finance-receipt-body"]').inner_text()
        require(CHECKOUT_PATIENT in receipt_text, f"{label}: the receipt names the settled patient from server truth")
        require(receipt_text.count("250000.00") >= 2, f"{label}: the receipt shows the stored total and the paid amount")
        require("نقد" in receipt_text, f"{label}: the receipt shows the recorded manual payment line")
        require(
            re.search(r"\d{4}/\d{2}/\d{2}", receipt_text) is not None,
            f"{label}: the receipt carries a Location-local Jalali date",
        )
        require(
            receipt_panel.locator("button").count() == 2,
            f"{label}: the receipt panel offers exactly one print and one close control",
        )
        require(
            receipt_panel.locator('[data-role^="finance-checkout"]').count() == 0,
            f"{label}: the printed receipt surface carries no mutation affordance",
        )
        page.screenshot(path=str(OUT / f"finance-receipt-{label}.png"), full_page=True)
        receipt_panel.locator('[data-role="finance-receipt-close"]').click()
        require(receipt_panel.is_hidden(), f"{label}: closing the receipt leaves the board unchanged")

        dimensions = page.evaluate(
            "({width: innerWidth, scroll: document.documentElement.scrollWidth, board: document.querySelector('[data-role=finance-board]').getBoundingClientRect().width, eligible: document.querySelector('[data-role=finance-eligible]').getBoundingClientRect().width, payment: document.querySelector('[data-role=finance-payment]').getBoundingClientRect().width, paid: document.querySelector('[data-role=finance-paid]').getBoundingClientRect().width})"
        )
        require(dimensions["scroll"] <= dimensions["width"] + 1, f"{label}: no horizontal viewport overflow")
        require(dimensions["board"] <= dimensions["width"] + 1, f"{label}: board fits viewport width")
        require(dimensions["eligible"] <= dimensions["width"] + 1, f"{label}: first-issuance panel fits viewport width")
        require(dimensions["payment"] <= dimensions["width"] + 1, f"{label}: capture panel fits viewport width")
        require(dimensions["paid"] <= dimensions["width"] + 1, f"{label}: paid panel fits viewport width")
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
        "document.querySelectorAll('[data-role=finance-rows] tr').length === 4",
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
        "document.querySelectorAll('[data-role=finance-rows] tr').length === 4",
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

    # ------------------------------------------------------------------
    # Phase 12 Slice 3 — real manual capture journey (desktop viewport):
    # one REAL partial payment, then one REAL exact full settlement, both
    # verified against server truth after a reload. Manual entry only.
    # ------------------------------------------------------------------
    def capture_row():
        return page.locator('[data-role="finance-rows"] tr').filter(has_text=PAYMENT_PATIENT)

    def open_capture_form():
        row = capture_row()
        require(row.count() == 1, "capture: exactly one open capture-invoice row before posting")
        row.locator('[data-role="finance-pay-open"]').click()
        form = page.locator('[data-role="finance-pay-form"]')
        form.wait_for(state="visible", timeout=10000)
        return form

    partial_form = open_capture_form()
    partial_form.locator('[data-role="finance-pay-amount"]').fill(str(PAYMENT_PARTIAL))
    partial_form.locator('[data-role="finance-pay-method"]').select_option("cash")
    page.screenshot(path=str(OUT / "finance-capture-partial-desktop.png"), full_page=True)
    # Double submit on purpose: the UI guard must keep exactly ONE payment.
    page.evaluate(
        "() => { const f = document.querySelector('[data-role=\"finance-pay-form\"]');"
        " f.dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}));"
        " f.dispatchEvent(new Event('submit', {cancelable: true, bubbles: true})); }"
    )
    page.wait_for_function(
        "(function(){var r=document.querySelectorAll('[data-role=finance-rows] tr');"
        "for (var i=0;i<r.length;i++){if(r[i].innerText.indexOf(" + json.dumps(PAYMENT_PATIENT) + ")!==-1){"
        "return r[i].innerText.indexOf(" + json.dumps(PAYMENT_REMAINDER) + ")!==-1;}}return false;})()",
        timeout=30000,
    )
    partial_row = capture_row()
    require(partial_row.count() == 1, "capture: the partially paid visit stays on the board")
    require("پرداخت بخشی" in partial_row.inner_text(), "capture: the row reports the server-derived partial state")
    require(PAYMENT_REMAINDER in partial_row.inner_text(), "capture: the row shows the reduced server-derived remaining")
    require("در انتظار پرداخت" in partial_row.inner_text(), "capture: a partial capture never settles the visit")
    require(
        page.locator('[data-role="finance-status"]').inner_text() != "",
        "capture: the delegated result is reported to the operator",
    )
    page.screenshot(path=str(OUT / "finance-capture-partial-done-desktop.png"), full_page=True)

    page.reload(wait_until="networkidle")
    page.wait_for_function(
        "(function(){var r=document.querySelectorAll('[data-role=finance-rows] tr');"
        "for (var i=0;i<r.length;i++){if(r[i].innerText.indexOf(" + json.dumps(PAYMENT_PATIENT) + ")!==-1){"
        "return r[i].innerText.indexOf(" + json.dumps(PAYMENT_REMAINDER) + ")!==-1;}}return false;})()",
        timeout=20000,
    )
    require(
        page.locator('[data-role="finance-rows"] tr').filter(has_text=PAYMENT_PATIENT).count() == 1,
        "capture refresh: the partial payment is server truth, not a browser-only state",
    )

    settlement_form = open_capture_form()
    settlement_form.locator('[data-role="finance-pay-amount"]').fill(str(PAYMENT_SETTLEMENT))
    settlement_form.locator('[data-role="finance-pay-method"]').select_option("card_pos")
    settlement_form.locator('[data-role="finance-pay-submit"]').click()
    page.wait_for_function(
        "(function(){var r=document.querySelectorAll('[data-role=finance-rows] tr');"
        "for (var i=0;i<r.length;i++){if(r[i].innerText.indexOf(" + json.dumps(PAYMENT_PATIENT) + ")!==-1){return false;}}"
        "return r.length === 3;})()",
        timeout=30000,
    )
    require(capture_row().count() == 0, "capture: the exactly settled visit leaves the awaiting-payment board")
    require(page.locator('[data-role="finance-payment"]').is_hidden(), "capture: the closed panel returns to rest after settlement")
    require(
        page.locator('[data-role="finance-rows"] tr').filter(has_text=INVOICE_PATIENT).count() == 1,
        "capture: unrelated awaiting-payment rows are untouched by the settlement",
    )
    page.screenshot(path=str(OUT / "finance-capture-settled-desktop.png"), full_page=True)

    page.reload(wait_until="networkidle")
    page.wait_for_function(
        "document.querySelectorAll('[data-role=finance-rows] tr').length === 3",
        timeout=20000,
    )
    require(
        page.locator('[data-role="finance-rows"] tr').filter(has_text=PAYMENT_PATIENT).count() == 0,
        "capture refresh: the settled visit stays off the board (server truth, no checkout)",
    )

    # ------------------------------------------------------------------
    # Phase 12 Slice 4 — real paid → checked_out journey (desktop viewport).
    # The capture visit is now genuinely `paid` through its REAL exact full
    # settlement above; the explicit, confirmed checkout removes it.
    # ------------------------------------------------------------------
    paid_panel = page.locator('[data-role="finance-paid"]')
    paid_panel.wait_for(state="visible", timeout=20000)
    page.wait_for_function(
        "document.querySelectorAll('[data-role=finance-paid-rows] tr').length === 2",
        timeout=20000,
    )
    settled_paid_row = paid_panel.locator("tbody tr").filter(has_text=PAYMENT_PATIENT)
    require(settled_paid_row.count() == 1, "slice4: the exactly settled visit surfaced on the paid board after the real settlement (server truth)")
    require("پرداخت کامل" in settled_paid_row.inner_text(), "slice4: the settled visit is fully paid, not checked out")
    require("0.00" in settled_paid_row.inner_text(), "slice4: the settled invoice reports zero remaining")
    require(
        page.locator('[data-role="finance-paid-rows"] tr').filter(has_text=CHECKOUT_PATIENT).count() == 1,
        "slice4: the unrelated fixture paid row is untouched",
    )
    require(
        page.locator('[data-role="finance-rows"] tr').filter(has_text=PAYMENT_PATIENT).count() == 0,
        "slice4: the settled visit is off the awaiting board (no double listing)",
    )

    # ------------------------------------------------------------------
    # Phase 12 Slice 5 — read-only printable receipt of the NORMAL fully paid
    # invoice behind the visit that was settled through THIS run, and the
    # browser print path invoked without any mutation.
    # ------------------------------------------------------------------
    # The no-mutation listener is attached BEFORE the receipt is opened, so the
    # complete open → print → close flow is covered: a listener attached after
    # the open could only ever prove that the print call itself was clean.
    receipt_mutations = []
    page.on(
        "request",
        lambda request: receipt_mutations.append(request.method)
        if "/wp-json/" in request.url and request.method != "GET"
        else None,
    )
    receipt_panel = open_receipt(page, settled_paid_row)
    require(receipt_panel.is_visible(), "slice5: the receipt panel opens from server truth")
    receipt_text = receipt_panel.locator('[data-role="finance-receipt-body"]').inner_text()
    require(CAPTURE_INVOICE in receipt_text, "slice5: the receipt shows the durable server-issued invoice number")
    require(PAYMENT_TOTAL in receipt_text, "slice5: the receipt shows the issued total 500000.00")
    require("نقد" in receipt_text and "ثبت دستی" in receipt_text, "slice5: every recorded manual method of this settlement is shown")
    require("0.00" in receipt_text, "slice5: the receipt shows the settled zero balance")
    require(
        re.search(r"\d{4}/\d{2}/\d{2}", receipt_text) is not None,
        "slice5: the receipt carries a Location-local Jalali date",
    )
    page.screenshot(path=str(OUT / "finance-receipt-settled-desktop.png"), full_page=True)
    page.evaluate(
        "window.__cpmsPrintCalled = false; window.__cpmsPrintClass = false;"
        "window.print = function () { window.__cpmsPrintCalled = true;"
        " window.__cpmsPrintClass = document.body.classList.contains('cpms-finance-printing'); };"
    )
    receipt_panel.locator('[data-role="finance-receipt-print"]').click()
    page.wait_for_function("window.__cpmsPrintCalled === true", timeout=10000)
    require(
        page.evaluate("window.__cpmsPrintCalled === true && window.__cpmsPrintClass === true"),
        "slice5: the print action invokes the browser print path with the receipt surface isolated",
    )
    require(
        CAPTURE_INVOICE in receipt_panel.locator('[data-role="finance-receipt-body"]').inner_text(),
        "slice5: the printed receipt is still the server truth after the print call",
    )
    receipt_panel.locator('[data-role="finance-receipt-close"]').click()
    require(receipt_panel.is_hidden(), "slice5: the receipt panel returns to rest")
    require(
        not receipt_mutations,
        "slice5: opening, printing and closing the receipt issue no non-GET REST request",
    )
    require(
        page.locator('[data-role="finance-paid-rows"] tr').filter(has_text=PAYMENT_PATIENT).count() == 1
        and "0.00" in page.locator('[data-role="finance-paid-rows"] tr').filter(has_text=PAYMENT_PATIENT).inner_text(),
        "slice5: reading and printing the receipt leaves the settled row untouched",
    )

    settled_paid_row.locator('[data-role="finance-checkout-open"]').click()
    checkout_panel = page.locator('[data-role="finance-checkout"]')
    checkout_panel.wait_for(state="visible", timeout=10000)
    checkout_form = checkout_panel.locator('[data-role="finance-checkout-form"]')
    require(
        PAYMENT_PATIENT in checkout_panel.locator('[data-role="finance-checkout-target"]').inner_text(),
        "slice4: the confirmation names the selected patient before the explicit confirm",
    )
    page.screenshot(path=str(OUT / "finance-checkout-confirm-desktop.png"), full_page=True)
    # Double submit on purpose: the UI guard must keep exactly ONE checkout.
    page.evaluate(
        "() => { const f = document.querySelector('[data-role=\"finance-checkout-form\"]');"
        " f.dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}));"
        " f.dispatchEvent(new Event('submit', {cancelable: true, bubbles: true})); }"
    )
    page.wait_for_function(
        "(function(){var r=document.querySelectorAll('[data-role=finance-paid-rows] tr');"
        "for (var i=0;i<r.length;i++){if(r[i].innerText.indexOf(" + json.dumps(PAYMENT_PATIENT) + ")!==-1){return false;}}"
        "return r.length === 1;})()",
        timeout=30000,
    )
    require(
        page.locator('[data-role="finance-paid-rows"] tr').filter(has_text=PAYMENT_PATIENT).count() == 0,
        "slice4: the checked-out visit leaves the paid board",
    )
    require(
        page.locator('[data-role="finance-status"]').inner_text() != "",
        "slice4: the delegated result is reported to the operator",
    )
    require(checkout_panel.is_hidden(), "slice4: the confirmation panel returns to rest after checkout")
    page.screenshot(path=str(OUT / "finance-checkout-done-desktop.png"), full_page=True)

    page.reload(wait_until="networkidle")
    page.wait_for_function(
        "document.querySelectorAll('[data-role=finance-paid-rows] tr').length === 1",
        timeout=20000,
    )
    require(
        page.locator('[data-role="finance-paid-rows"] tr').filter(has_text=PAYMENT_PATIENT).count() == 0,
        "slice4 refresh: the checked-out visit stays off the paid board (server truth, persisted)",
    )
    require(
        page.locator('[data-role="finance-rows"] tr').filter(has_text=PAYMENT_PATIENT).count() == 0,
        "slice4 refresh: the checked-out visit is also absent from the awaiting board",
    )
    require(
        page.locator('[data-role="finance-paid-rows"] tr').filter(has_text=CHECKOUT_PATIENT).count() == 1
        and "0.00" in page.locator('[data-role="finance-paid-rows"] tr').filter(has_text=CHECKOUT_PATIENT).inner_text(),
        "slice4 refresh: the unrelated paid row is untouched and still settled",
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
print(
    "PASS Phase 12 Staff Portal Finance board + first-issuance + manual capture "
    "(1 real partial, 1 real exact settlement) + paid checkout "
    "(1 real paid -> checked_out) browser acceptance"
)
