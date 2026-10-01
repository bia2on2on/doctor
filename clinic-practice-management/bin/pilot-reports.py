#!/usr/bin/env python3
"""Real-browser acceptance for the existing Staff Portal Reports module.

Covers Slice 1 Average Waiting plus Slice 2 Visit Duration on the EXISTING
pilot gate entry point (fixture + Playwright in the responsive job; no new
browser infrastructure):

  * a reporter with exactly ONE eligible Clinic: no selector, the Clinic is shown
    and the existing `X-CPMS-Clinic-Id` header carries it;
  * a reporter with TWO eligible Clinics: an explicit Clinic select with no
    default; submitting without a Clinic sends NO request; both actions reach
    their existing report endpoints with `from = to = date` and return only that
    Clinic's aggregate;
  * Visit Duration reports only the count and consultation average, clears when
    Clinic/date changes, and shows a dash (not a zero-second average) for no
    samples;
  * a forged selector (an option for a Clinic the reporter is not a member of,
    injected into the DOM) is rejected by the server and shows a coherent error,
    never another Clinic's numbers.

Per viewport (390x844, 768x1024, 1366x768): no whole-page horizontal overflow,
date field / Clinic select / both actions usable inside the viewport, a coherent
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
EMPTY_DATE = os.environ["REPORTS_EMPTY_DATE"]
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
        decoded_url = unquote(request.url)
        route = next((name for name in ("avg_waiting", "visit_duration") if f"reports/{name}" in decoded_url), None)
        if route is not None:
            query = parse_qs(urlparse(request.url).query, keep_blank_values=True)
            state["report_requests"].append(
                {
                    "route": route,
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
                duration_action: box('[data-role=reports-visit-duration-action]'),
                select: box('[data-role=reports-clinic-select]'),
            };
        }"""
    )
    require(metrics["scroll"] <= metrics["inner"] + 1, f"{label} {who}: no whole-page horizontal overflow ({metrics['scroll']} <= {metrics['inner']})")
    for key in ("date", "submit", "duration_action") + (("select",) if who == "multi" else ()):
        box = metrics[key]
        ok = box is not None and box["width"] > 40 and box["height"] >= 30 and box["left"] >= -1 and box["right"] <= metrics["inner"] + 1
        require(ok, f"{label} {who}: {key} control is inside the viewport and large enough to use")


def submit_report(page, date, report="avg_waiting"):
    page.fill('[data-role="reports-date-input"]', date)
    button = '[data-role="reports-visit-duration-action"]' if report == "visit_duration" else '[data-role="reports-submit"]'
    page.click(button)


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
        duration_action = page.locator('[data-role="reports-visit-duration-action"]')
        require(duration_action.count() == 1 and duration_action.is_visible(), f"{label} multi: Visit Duration action is reachable")
        duration_description = page.locator('[data-role="reports-visit-duration-description"]').inner_text()
        require("consultation_started_at" in duration_description and "consultation_completed_at" in duration_description and "visit_date" in duration_description, f"{label} multi: Visit Duration and recorded-date semantics are explicit")
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

        # Slice 2 Visit Duration uses the same selector/date and existing report route.
        duration_result = page.locator('[data-role="reports-visit-duration-result"]')
        before = len(state["report_requests"])
        submit_report(page, DATE, "visit_duration")
        duration_result.wait_for(state="visible", timeout=20000)
        req = state["report_requests"][-1]
        require(len(state["report_requests"]) == before + 1 and req["route"] == "visit_duration" and req["clinic"] == BETA_ID and req["from"] == DATE and req["to"] == DATE and req["method"] == "GET", f"{label} multi: Visit Duration uses its existing endpoint, Beta header and from=to={DATE}")
        require(req["location"] is None and req["clinic_query"] is None, f"{label} multi: Visit Duration sends neither a Location id nor raw clinic_id")
        text = ascii_digits(duration_result.inner_text())
        count = ascii_digits(page.locator('[data-role="reports-visit-duration-count"]').inner_text())
        avg = ascii_digits(page.locator('[data-role="reports-visit-duration-average"]').inner_text())
        require(BETA_NAME in text and ALPHA_NAME not in text and GAMMA_NAME not in text, f"{label} multi: Visit Duration labels the selected Clinic only")
        require(count.strip() == "1" and "5" in avg and "دقیقه" in avg, f"{label} multi: Beta consultation aggregate is 1 sample / 5 minutes (got '{count.strip()}' / '{avg.strip()}')")
        require("SYN-RPD-" not in page.content() and "Patient" not in duration_result.inner_text(), f"{label} multi: Visit Duration exposes no patient data")
        geometry(page, label + " Visit Duration Beta result", "multi")
        page.screenshot(path=str(OUT / f"reports-multi-duration-beta-{label}.png"), full_page=True)

        # Switching Clinic clears both prior report regions and their values.
        select.select_option(ALPHA_ID)
        require(duration_result.is_hidden() and page.locator('[data-role="reports-visit-duration-count"]').inner_text() == "" and page.locator('[data-role="reports-visit-duration-average"]').inner_text() == "", f"{label} multi: Clinic switch clears stale Visit Duration data immediately")
        before = len(state["report_requests"])
        submit_report(page, DATE, "visit_duration")
        duration_result.wait_for(state="visible", timeout=20000)
        req = state["report_requests"][-1]
        require(len(state["report_requests"]) == before + 1 and req["route"] == "visit_duration" and req["clinic"] == ALPHA_ID and req["from"] == DATE and req["to"] == DATE, f"{label} multi: Alpha Visit Duration uses the selected Clinic and from=to={DATE}")
        text = ascii_digits(duration_result.inner_text())
        count = ascii_digits(page.locator('[data-role="reports-visit-duration-count"]').inner_text())
        avg = ascii_digits(page.locator('[data-role="reports-visit-duration-average"]').inner_text())
        require(ALPHA_NAME in text and BETA_NAME not in text and GAMMA_NAME not in text, f"{label} multi: Alpha Visit Duration is labelled with Alpha only")
        require(count.strip() == "2" and "15" in avg and "دقیقه" in avg, f"{label} multi: Alpha consultation aggregate is 2 samples / 15 minutes (got '{count.strip()}' / '{avg.strip()}')")
        geometry(page, label + " Visit Duration Alpha result", "multi")
        page.screenshot(path=str(OUT / f"reports-multi-duration-alpha-{label}.png"), full_page=True)

        if label == "desktop":
            # Deliver a genuine old-Clinic API response only AFTER the selector
            # changes, proving the UI ticket also rejects late (not just aborted) data.
            page.evaluate(
                """() => {
                    const nativeFetch = window.fetch.bind(window);
                    window.__holdNextVisitDuration = true;
                    window.__heldVisitDurationSettled = false;
                    window.__releaseHeldVisitDuration = null;
                    window.fetch = (resource, options) => {
                        const url = typeof resource === 'string' ? resource : resource.url;
                        if (!window.__holdNextVisitDuration || !url.includes('/reports/visit_duration')) return nativeFetch(resource, options);
                        window.__holdNextVisitDuration = false;
                        const lateOptions = Object.assign({}, options || {});
                        delete lateOptions.signal;
                        return new Promise((resolve, reject) => {
                            window.__releaseHeldVisitDuration = () => {
                                window.__releaseHeldVisitDuration = null;
                                nativeFetch(resource, lateOptions).then(response => {
                                    window.__heldVisitDurationSettled = true;
                                    resolve(response);
                                }, error => {
                                    window.__heldVisitDurationSettled = true;
                                    reject(error);
                                });
                            };
                        });
                    };
                }"""
            )
            before = len(state["report_requests"])
            page.click('[data-role="reports-visit-duration-action"]')
            page.wait_for_function("typeof window.__releaseHeldVisitDuration === 'function'", timeout=20000)
            select.select_option(BETA_ID)
            require(duration_result.is_hidden() and page.locator('[data-role="reports-visit-duration-count"]').inner_text() == "" and page.locator('[data-role="reports-visit-duration-average"]').inner_text() == "", "desktop multi: Clinic change clears data while an old Visit Duration request is pending")
            require(len(state["report_requests"]) == before, "desktop multi: delayed request has not been sent before the Clinic switch")
            page.evaluate("window.__releaseHeldVisitDuration()")
            page.wait_for_function("window.__heldVisitDurationSettled === true", timeout=20000)
            req = state["report_requests"][-1]
            require(req["route"] == "visit_duration" and req["clinic"] == ALPHA_ID and req["from"] == DATE and req["to"] == DATE, "desktop multi: the delayed response is for the prior Alpha selection")
            require(duration_result.is_hidden() and page.locator('[data-role="reports-visit-duration-count"]').inner_text() == "" and page.locator('[data-role="reports-visit-duration-average"]').inner_text() == "", "desktop multi: a late Alpha response cannot repopulate results after switching to Beta")

        # Changing the stored visit date also clears the old aggregate; no-sample output has no average.
        select.select_option(BETA_ID)
        page.fill('[data-role="reports-date-input"]', EMPTY_DATE)
        require(duration_result.is_hidden() and page.locator('[data-role="reports-visit-duration-count"]').inner_text() == "" and page.locator('[data-role="reports-visit-duration-average"]').inner_text() == "", f"{label} multi: date change clears stale Visit Duration data immediately")
        before = len(state["report_requests"])
        submit_report(page, EMPTY_DATE, "visit_duration")
        duration_result.wait_for(state="visible", timeout=20000)
        req = state["report_requests"][-1]
        require(len(state["report_requests"]) == before + 1 and req["route"] == "visit_duration" and req["clinic"] == BETA_ID and req["from"] == EMPTY_DATE and req["to"] == EMPTY_DATE, f"{label} multi: zero-sample request uses Beta and the same explicit empty date for from/to")
        count = ascii_digits(page.locator('[data-role="reports-visit-duration-count"]').inner_text())
        avg = page.locator('[data-role="reports-visit-duration-average"]').inner_text().strip()
        require(count.strip() == "0" and avg == "—", f"{label} multi: zero samples show count 0 and a dash, not a zero-second average")
        require("0 ثانیه" not in avg and page.locator('[data-role="reports-status"]').get_attribute("data-kind") != "error", f"{label} multi: zero-sample state is not presented as an error or zero seconds")
        geometry(page, label + " Visit Duration empty result", "multi")
        page.screenshot(path=str(OUT / f"reports-multi-duration-empty-{label}.png"), full_page=True)

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
    submit_report(page, DATE, "visit_duration")
    page.wait_for_function("document.querySelector('[data-role=reports-status]').dataset.kind === 'error' && !document.querySelector('[data-role=reports-visit-duration-action]').disabled", timeout=20000)
    state["expect_denied"] = False
    status = page.locator('[data-role="reports-status"]').inner_text()
    require(len(state["report_requests"]) == 1 and state["report_requests"][0]["route"] == "visit_duration" and state["report_requests"][0]["clinic"] == GAMMA_ID, "forged selector: Visit Duration actually sends the forged Clinic id")
    require(page.locator('[data-role="reports-result"]').is_hidden() and page.locator('[data-role="reports-visit-duration-result"]').is_hidden(), "forged selector: server rejects it — no numbers are shown")
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

        before = len(state["report_requests"])
        submit_report(page, DATE, "visit_duration")
        duration_result = page.locator('[data-role="reports-visit-duration-result"]')
        duration_result.wait_for(state="visible", timeout=20000)
        req = state["report_requests"][-1]
        require(len(state["report_requests"]) == before + 1 and req["route"] == "visit_duration" and req["clinic"] == ALPHA_ID and req["from"] == DATE and req["to"] == DATE, f"{label} single: Visit Duration sends exactly one request with the fixed Clinic and from=to={DATE}")
        count = ascii_digits(page.locator('[data-role="reports-visit-duration-count"]').inner_text())
        avg = ascii_digits(page.locator('[data-role="reports-visit-duration-average"]').inner_text())
        require(count.strip() == "2" and "15" in avg and "دقیقه" in avg, f"{label} single: Visit Duration aggregate is 2 samples / 15 minutes (got '{count.strip()}' / '{avg.strip()}')")
        require("SYN-RPD-" not in page.content() and "Patient" not in duration_result.inner_text(), f"{label} single: Visit Duration exposes no patient data")
        geometry(page, label + " Visit Duration result", "single")
        page.screenshot(path=str(OUT / f"reports-single-duration-{label}.png"), full_page=True)

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
print("PASS Phase 14 Staff Portal Reports browser acceptance (Average Waiting + Visit Duration; N=2 explicit, N=1 fixed, stale/empty/forged cases)")
