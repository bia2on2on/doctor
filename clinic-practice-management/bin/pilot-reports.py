#!/usr/bin/env python3
"""Real-browser acceptance for the Staff Portal Reports module (Phase 14 Slice 1).

Covers the multi-Clinic repair on the EXISTING pilot gate entry point (fixture +
Playwright in the responsive job; no new browser infrastructure):

  * a reporter with exactly ONE eligible Clinic: no selector, the Clinic is shown
    and the existing `X-CPMS-Clinic-Id` header carries it;
  * a reporter with TWO eligible Clinics: an explicit Clinic select with no
    default; submitting without a Clinic sends NO request; each valid selection
    reaches `reports/avg_waiting` with `from = to = date` and returns only that
    Clinic's aggregate;
  * a forged selector (an option for a Clinic the reporter is not a member of,
    injected into the DOM) is rejected by the server and shows a coherent error,
    never another Clinic's numbers.

Per viewport (390x844, 768x1024, 1366x768): no whole-page horizontal overflow,
date field / Clinic select / action usable inside the viewport, a coherent
result or error state. Screenshots are written as artifacts only; this script
makes DOM/network assertions and does NOT interpret pixels as visual approval.
"""

import os
import sys
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlparse

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
URL = os.environ["REPORTS_URL"]
DATE = os.environ["REPORTS_DATE"]
ALPHA_ID = os.environ["REPORTS_ALPHA_ID"]
ALPHA_NAME = os.environ["REPORTS_ALPHA_NAME"]
BETA_ID = os.environ["REPORTS_BETA_ID"]
BETA_NAME = os.environ["REPORTS_BETA_NAME"]
GAMMA_ID = os.environ["REPORTS_GAMMA_ID"]
GAMMA_NAME = os.environ["REPORTS_GAMMA_NAME"]
USERS = {
    "multi": (os.environ["REPORTS_MULTI_LOGIN"], os.environ["REPORTS_MULTI_PASS"]),
    "single": (os.environ["REPORTS_SINGLE_LOGIN"], os.environ["REPORTS_SINGLE_PASS"]),
}
VIEWPORTS = [
    ("mobile", 390, 844),
    ("tablet", 768, 1024),
    ("desktop", 1366, 768),
]
OUT = Path("pilot-screenshots")
OUT.mkdir(exist_ok=True)
failures = []
DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")


def require(condition, message):
    if not condition:
        failures.append(message)
        print("FAIL " + message)
    else:
        print("PASS " + message)


def ascii_digits(text):
    return (text or "").translate(DIGITS)


def new_session(playwright, who):
    """One isolated browser context per synthetic reporter, with listeners."""
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(viewport={"width": 1366, "height": 768}, locale="fa-IR")
    page = context.new_page()
    state = {"expect_denied": False, "report_requests": [], "page_errors": [], "console": [], "bad": [], "failed": []}
    page.on("pageerror", lambda error: state["page_errors"].append(str(error)))
    page.on(
        "console",
        lambda m: state["console"].append(m.text) if m.type == "error" and not state["expect_denied"] else None,
    )
    page.on("requestfailed", lambda r: state["failed"].append(r.url))
    page.on(
        "response",
        lambda r: state["bad"].append((r.status, r.url))
        if (r.status >= 500 or (r.status >= 400 and ("/wp-json/" in r.url or "rest_route" in r.url))) and not state["expect_denied"]
        else None,
    )

    def on_request(request):
        if "reports/avg_waiting" in unquote(request.url):
            query = parse_qs(urlparse(request.url).query, keep_blank_values=True)
            state["report_requests"].append(
                {
                    "clinic": request.headers.get("x-cpms-clinic-id"),
                    "from": query.get("from", [None])[0],
                    "to": query.get("to", [None])[0],
                    "method": request.method,
                    "clinic_query": query.get("clinic_id", [None])[0],
                    "location": request.headers.get("x-cpms-location-id") or query.get("location_id", [None])[0],
                }
            )

    page.on("request", on_request)
    login, password = USERS[who]
    page.goto(BASE + "/wp-login.php", wait_until="networkidle")
    page.fill("#user_login", login)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle")
    require("wp-login.php" not in page.url, f"{who}: synthetic reporter login succeeds")
    return browser, context, page, state


def geometry(page, label, who):
    """Layout assertions that never rely on pixels: overflow and usable controls."""
    metrics = page.evaluate(
        """() => {
            const box = (sel) => { const e = document.querySelector(sel); if (!e) return null; const r = e.getBoundingClientRect(); return {left: r.left, right: r.right, width: r.width, height: r.height}; };
            return {
                inner: innerWidth,
                scroll: document.documentElement.scrollWidth,
                date: box('[data-role=reports-date-input]'),
                submit: box('[data-role=reports-submit]'),
                select: box('[data-role=reports-clinic-select]'),
            };
        }"""
    )
    require(metrics["scroll"] <= metrics["inner"] + 1, f"{label} {who}: no whole-page horizontal overflow ({metrics['scroll']} <= {metrics['inner']})")
    for key in ("date", "submit") + (("select",) if who == "multi" else ()):
        box = metrics[key]
        ok = box is not None and box["width"] > 40 and box["height"] >= 30 and box["left"] >= -1 and box["right"] <= metrics["inner"] + 1
        require(ok, f"{label} {who}: {key} control is inside the viewport and large enough to use")


def submit_report(page, date):
    page.fill('[data-role="reports-date-input"]', date)
    page.click('[data-role="reports-submit"]')


def wait_status(page, predicate_js, label):
    page.wait_for_function(predicate_js, timeout=20000)


def result_text(page):
    result = page.locator('[data-role="reports-result"]')
    return result.inner_text() if result.is_visible() else ""


with sync_playwright() as playwright:
    # ---------------- N = 2 eligible Clinics ----------------
    browser, context, page, state = new_session(playwright, "multi")
    for label, width, height in VIEWPORTS:
        page.set_viewport_size({"width": width, "height": height})
        state["report_requests"].clear()
        page.goto(URL, wait_until="networkidle")
        page.locator('[data-role="reports-root"]').wait_for(state="visible", timeout=20000)
        select = page.locator('[data-role="reports-clinic-select"]')
        require(select.count() == 1 and select.is_visible(), f"{label} multi: explicit Clinic select is shown for N>1")
        options = select.locator("option")
        values = options.evaluate_all("els => els.map(e => e.value)")
        require(values == ["", ALPHA_ID, BETA_ID], f"{label} multi: placeholder + exactly the two member Clinics (no foreign Clinic)")
        require(select.input_value() == "", f"{label} multi: no Clinic is pre-selected (no first-Clinic fallback)")
        require(GAMMA_NAME not in page.locator('[data-role="reports-root"]').inner_html(), f"{label} multi: the non-member Clinic is not offered")
        require(page.locator('[data-role="reports-date-input"]').input_value() == "", f"{label} multi: no default date")
        require(page.locator("[data-role=reports-root] [data-role*=location]").count() == 0, f"{label} multi: no Location selector")
        require(page.locator("[data-role=reports-root] canvas, [data-role=reports-root] a[href*=export], [data-role=reports-root] a[href*=print]").count() == 0, f"{label} multi: no chart/export/print surface")
        require(len(state["report_requests"]) == 0, f"{label} multi: page load runs no report")
        geometry(page, label, "multi")

        # Date entered but NO Clinic chosen: fail closed client-side, no request.
        submit_report(page, DATE)
        wait_status(page, "document.querySelector('[data-role=reports-status]').dataset.kind === 'error'", label)
        require(page.locator('[data-role="reports-result"]').is_hidden(), f"{label} multi: no result without a Clinic")
        require(len(state["report_requests"]) == 0, f"{label} multi: submit without a Clinic sends NO request")

        # Alpha.
        select.select_option(ALPHA_ID)
        submit_report(page, DATE)
        page.locator('[data-role="reports-result"]').wait_for(state="visible", timeout=20000)
        text = ascii_digits(result_text(page))
        req = state["report_requests"][-1]
        require(req["clinic"] == ALPHA_ID and req["from"] == DATE and req["to"] == DATE and req["method"] == "GET", f"{label} multi: Alpha request carries the selector header and from=to={DATE}")
        require(req["location"] is None and req["clinic_query"] is None, f"{label} multi: no Location id and no raw clinic_id query")
        require(ALPHA_NAME in text and BETA_NAME not in text and GAMMA_NAME not in text, f"{label} multi: result is labelled with the selected Clinic only")
        count = ascii_digits(page.locator('[data-role="reports-count"]').inner_text())
        avg = ascii_digits(page.locator('[data-role="reports-avg"]').inner_text())
        require(count.strip() == "2" and "7" in avg and "30" in avg, f"{label} multi: Alpha aggregate is 2 visits / 7 min 30 s (got '{count.strip()}' / '{avg.strip()}')")
        require("SYN-RP-" not in page.content() and "Patient" not in page.locator('[data-role="reports-root"]').inner_text(), f"{label} multi: aggregate only — no patient data")
        geometry(page, label + " result", "multi")
        page.screenshot(path=str(OUT / f"reports-multi-alpha-{label}.png"), full_page=True)

        # Switching the Clinic clears the stale result immediately.
        select.select_option(BETA_ID)
        require(page.locator('[data-role="reports-result"]').is_hidden(), f"{label} multi: changing the Clinic hides the previous Clinic's numbers")
        submit_report(page, DATE)
        page.locator('[data-role="reports-result"]').wait_for(state="visible", timeout=20000)
        text = ascii_digits(result_text(page))
        req = state["report_requests"][-1]
        require(req["clinic"] == BETA_ID and req["from"] == DATE and req["to"] == DATE, f"{label} multi: Beta request carries the Beta selector and from=to={DATE}")
        count = ascii_digits(page.locator('[data-role="reports-count"]').inner_text())
        avg = ascii_digits(page.locator('[data-role="reports-avg"]').inner_text())
        require(BETA_NAME in text and ALPHA_NAME not in text, f"{label} multi: result is labelled with Beta")
        require(count.strip() == "1" and "2" in avg and "30" not in avg, f"{label} multi: Beta aggregate is 1 visit / 2 min (not blended with Alpha) (got '{count.strip()}' / '{avg.strip()}')")
        page.screenshot(path=str(OUT / f"reports-multi-beta-{label}.png"), full_page=True)

        # Empty date with a Clinic selected: coherent error, no request.
        before = len(state["report_requests"])
        page.fill('[data-role="reports-date-input"]', "")
        page.click('[data-role="reports-submit"]')
        wait_status(page, "document.querySelector('[data-role=reports-status]').dataset.kind === 'error'", label)
        require(len(state["report_requests"]) == before, f"{label} multi: empty date sends no request (no implicit today)")
        require(page.locator('[data-role="reports-submit"]').is_enabled(), f"{label} multi: the action stays usable after a client-side error")

    # Forged selector: an option for a Clinic this reporter is NOT a member of.
    page.set_viewport_size({"width": 1366, "height": 768})
    page.goto(URL, wait_until="networkidle")
    page.locator('[data-role="reports-clinic-select"]').wait_for(state="visible", timeout=20000)
    page.evaluate(
        """(id) => { const s = document.querySelector('[data-role=reports-clinic-select]'); const o = document.createElement('option'); o.value = id; o.textContent = 'forged'; s.appendChild(o); }""",
        GAMMA_ID,
    )
    page.locator('[data-role="reports-clinic-select"]').select_option(GAMMA_ID)
    state["report_requests"].clear()
    state["expect_denied"] = True
    submit_report(page, DATE)
    page.wait_for_function("document.querySelector('[data-role=reports-status]').dataset.kind === 'error' && !document.querySelector('[data-role=reports-submit]').disabled", timeout=20000)
    state["expect_denied"] = False
    status = page.locator('[data-role="reports-status"]').inner_text()
    require(len(state["report_requests"]) == 1 and state["report_requests"][0]["clinic"] == GAMMA_ID, "forged selector: the request was actually sent with the forged Clinic id")
    require(page.locator('[data-role="reports-result"]').is_hidden(), "forged selector: server rejects it — no numbers are shown")
    require("در دسترس نیست" in status or "مجاز نیست" in status, "forged selector: a coherent localized error is shown")
    require("900" not in page.locator('[data-role="reports-root"]').inner_text() and ascii_digits(page.locator('[data-role="reports-status"]').inner_text()).count("15") == 0, "forged selector: the foreign Clinic's aggregate never appears")
    page.screenshot(path=str(OUT / "reports-forged-selector-desktop.png"), full_page=True)

    require(not state["page_errors"], "multi: no uncaught browser exceptions")
    require(not state["console"], "multi: no browser console errors")
    require(not state["bad"], "multi: no unexpected failed HTTP/API responses")
    require(not state["failed"], "multi: no failed browser requests")
    for error in state["page_errors"] + state["console"]:
        print("INFO browser error: " + error[:300])
    for status_code, url in state["bad"]:
        print(f"INFO HTTP {status_code}: {url[:300]}")
    context.close()
    browser.close()

    # ---------------- N = 1 eligible Clinic ----------------
    browser, context, page, state = new_session(playwright, "single")
    for label, width, height in VIEWPORTS:
        page.set_viewport_size({"width": width, "height": height})
        state["report_requests"].clear()
        page.goto(URL, wait_until="networkidle")
        page.locator('[data-role="reports-root"]').wait_for(state="visible", timeout=20000)
        require(page.locator("[data-role=reports-root] select").count() == 0, f"{label} single: no selector for exactly one eligible Clinic")
        fixed = page.locator('[data-role="reports-clinic-fixed"]')
        require(fixed.count() == 1 and ALPHA_NAME in fixed.inner_text() and fixed.get_attribute("data-clinic-id") == ALPHA_ID, f"{label} single: the one eligible Clinic is shown")
        require(page.locator('[data-role="reports-date-input"]').input_value() == "", f"{label} single: no default date")
        geometry(page, label, "single")
        submit_report(page, DATE)
        page.locator('[data-role="reports-result"]').wait_for(state="visible", timeout=20000)
        req = state["report_requests"][-1]
        require(len(state["report_requests"]) == 1 and req["clinic"] == ALPHA_ID and req["from"] == DATE and req["to"] == DATE, f"{label} single: exactly one request, Clinic header = the eligible Clinic, from=to={DATE}")
        count = ascii_digits(page.locator('[data-role="reports-count"]').inner_text())
        require(count.strip() == "2" and BETA_NAME not in result_text(page) and GAMMA_NAME not in result_text(page), f"{label} single: Alpha aggregate only (2 visits)")
        geometry(page, label + " result", "single")
        page.screenshot(path=str(OUT / f"reports-single-{label}.png"), full_page=True)

    require(not state["page_errors"], "single: no uncaught browser exceptions")
    require(not state["console"], "single: no browser console errors")
    require(not state["bad"], "single: no unexpected failed HTTP/API responses")
    require(not state["failed"], "single: no failed browser requests")
    for error in state["page_errors"] + state["console"]:
        print("INFO browser error: " + error[:300])
    context.close()
    browser.close()

if failures:
    print(f"FAILURES {len(failures)}")
    sys.exit(1)
print("PASS Phase 14 Staff Portal Reports multi-Clinic browser acceptance (N=2 explicit selection, N=1 auto, forged selector rejected)")
