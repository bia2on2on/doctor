#!/usr/bin/env python3
"""Staff Portal Reception Arrival Board proof on the existing Pilot Chromium job.

Real WordPress page, real authenticated secretary session, real REST, and the
existing server authorization/transitions. No new browser framework and no time
freeze. Location-local operational days come from the fixture's two IANA
timezones (Asia/Tehran + Asia/Tokyo).

Evidence lines are PASS/FAIL/INFO/SHOT with booleans and non-sensitive ids.
Passwords, patient names, nonces, and cookies are not printed.
"""

import json
import os
import re
import sys
import time
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
OUT = "pilot-screenshots"
RECEPTION_URL = os.environ.get("RECEPTION_URL", "").strip()
STAFF_URL = os.environ.get("STAFF_PORTAL_URL", "").strip()
FORBIDDEN_CONFIG_KEYS = {"clinician_id", "organization_id", "role", "patient_id", "clinic_id", "mobile", "national_id"}
PERSIAN_RE = re.compile(r"[\u0600-\u06FF]")
CHROME = (
    'id="wpadminbar"',
    'id="adminmenu"',
    'id="wpfooter"',
    "wp-site-blocks",
    "site-header",
    "site-footer",
    "wp-block-template-part",
    "footer",
)

VIEWPORTS = [
    {"vp": "mobile-390", "w": 390, "h": 844},
    {"vp": "tablet-768", "w": 768, "h": 1024},
    {"vp": "desktop-1366", "w": 1366, "h": 768},
]

results = []
failures = []


def ok(key, title, detail=""):
    results.append({"key": key, "status": "PASS"})
    print(f"PASS {key} — {title}" + (f" — {detail}" if detail else ""))


def fail(key, title, err):
    failures.append(key)
    results.append({"key": key, "status": "FAIL"})
    print(f"FAIL {key} — {title} — {err}")


def info(line):
    print(f"INFO {line}")


def shot(page, name):
    os.makedirs(OUT, exist_ok=True)
    page.screenshot(path=f"{OUT}/{name}.png", full_page=True)
    print(f"SHOT {name}")


def parts(name, n):
    raw = os.environ.get(name, "").strip()
    values = [p.strip() for p in raw.split("|")]
    if len(values) < n or not values[0]:
        raise SystemExit(f"{name} must carry {n} pipe-separated parts")
    return values


_sec = parts("RECEPTION_SECRETARY", 3)
SECRETARY = {"login": _sec[0], "password": _sec[1], "user_id": int(_sec[2])}
_doc = parts("RECEPTION_DOCTOR", 3)
DOCTOR = {"login": _doc[0], "password": _doc[1], "user_id": int(_doc[2])}
DOCTOR_URL = os.environ.get("RECEPTION_DOCTOR_URL", "").strip()
_pub = parts("RECEPTION_PUBLIC", 9)
PUB = {
    "clinic": int(_pub[0]),
    "loc_tehran": int(_pub[1]),
    "loc_tokyo": int(_pub[2]),
    "appt_express": int(_pub[3]),
    "appt_plain": int(_pub[4]),
    "appt_third": int(_pub[5]),
    "appt_partial": int(_pub[6]),
    "today_tehran": _pub[7],
    "today_tokyo": _pub[8],
}
_srch = parts("RECEPTION_SEARCH", 3)
SEARCH = {"probe": int(_srch[0]), "foreign": int(_srch[1]), "nid_last4": _srch[2]}


def page_hint(page):
    try:
        path = urlparse(page.url or "").path or "/"
        shell = page.locator("html").get_attribute("data-cpms-staff-portal-shell") or "0"
        user = "0"
        if page.locator("[data-shell-user]").count():
            user = page.locator("[data-shell-user]").first.get_attribute("data-shell-user") or "0"
        return f"path={path} shell={shell} user={user}"
    except Exception:
        return "hint=unavailable"


NAV = {}


def new_page(browser, vp):
    ctx = browser.new_context(
        viewport={"width": vp["w"], "height": vp["h"]},
        locale="fa-IR",
        has_touch=True,
        ignore_https_errors=True,
    )
    page = ctx.new_page()
    page.set_default_timeout(25000)
    state = {"reqs": [], "rest": [], "console": [], "pageerrors": [], "failed": []}
    NAV[id(page)] = {"harness": False, "harness_docs": 0, "product_docs": 0, "rest_total": 0}

    def on_nav_request(req):
        try:
            if not req.is_navigation_request() or req.frame != page.main_frame:
                return
        except Exception:
            return
        nav = NAV[id(page)]
        if nav["harness"]:
            nav["harness_docs"] += 1
        else:
            nav["product_docs"] += 1

    def on_request(req):
        if not req.url.startswith(BASE) or "/clinic/v1" not in req.url:
            return
        headers = {k.lower(): v for k, v in req.headers.items()}
        state["reqs"].append({"method": req.method, "route": route_of(req.url), "headers": headers})

    def on_response(resp):
        if not resp.url.startswith(BASE) or "/clinic/v1" not in resp.url:
            return
        NAV[id(page)]["rest_total"] += 1
        state["rest"].append({"route": route_of(resp.url), "status": resp.status, "method": resp.request.method})

    def safe_error(text):
        if "Failed to fetch" in text:
            return "Failed to fetch"
        match = re.search(r"ERR_[A-Z_]+", text)
        return match.group(0) if match else "other-redacted"

    def on_console(msg):
        if msg.type != "error":
            return
        txt = msg.text[:200]
        if "favicon" in txt or "Failed to load resource" in txt:
            return
        state["console"].append(txt)

    def on_pageerror(err):
        state["pageerrors"].append(str(err)[:200])

    def on_requestfailed(req):
        url = req.url
        if not url.startswith(BASE) or "favicon" in url or ".map" in url:
            return
        failure = req.failure or "failed"
        if "ERR_ABORTED" in failure:
            return
        if "ERR_INTERNET_DISCONNECTED" in failure or "ERR_NETWORK_CHANGED" in failure:
            # Deliberate offline-state probe records its own evidence.
            return
        state["failed"].append(failure[:80])

    page.on("request", on_request)
    page.on("request", on_nav_request)
    page.on("response", on_response)
    page.on("console", on_console)
    page.on("pageerror", on_pageerror)
    page.on("requestfailed", on_requestfailed)
    return ctx, page, state


def route_of(url):
    parsed = urlparse(url)
    path = parsed.path or "/"
    for marker in ("/clinic/v1",):
        if marker in path:
            return path[path.index(marker):].split("?", 1)[0].rstrip("/") or "/"
    return path.rstrip("/") or "/"


def login(page, user):
    page.goto(f"{BASE}/wp-login.php", wait_until="networkidle")
    page.fill("#user_login", user["login"])
    page.fill("#user_pass", user["password"])
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle")
    if "wp-login.php" in (page.url or "") and "loggedout" not in (page.url or ""):
        raise RuntimeError("login failed")


def harness_goto(page, url, **kwargs):
    nav = NAV[id(page)]
    nav["harness"] = True
    try:
        return page.goto(url, **kwargs)
    finally:
        nav["harness"] = False


def nav_mark(page):
    nav = NAV[id(page)]
    return {"product_docs": nav["product_docs"], "rest_total": nav["rest_total"]}


def assert_no_product_reload(page, mark, label):
    nav = NAV[id(page)]
    delta = nav["product_docs"] - mark["product_docs"]
    if delta != 0:
        raise RuntimeError(f"{label} product-initiated full-page navigation (count={delta})")
    rest_delta = nav["rest_total"] - mark["rest_total"]
    if rest_delta <= 0:
        raise RuntimeError(f"{label} no REST/AJAX traffic for operational interactions")
    return rest_delta


def status_text(page):
    return (page.locator('[data-role="sr-status"]').inner_text() or "").strip()


def rows(page):
    return page.locator('[data-role="sr-row"]')


def row_of(page, appointment_id):
    return page.locator(f'[data-role="sr-row"][data-appointment-id="{appointment_id}"]')


def select_location(page, location_id):
    page.select_option('[data-role="sr-location-select"]', str(location_id))


def wait_rows_count(page, expected, timeout=15000):
    page.wait_for_function(
        """(n) => document.querySelectorAll('[data-role="sr-row"]').length === n""",
        arg=expected,
        timeout=timeout,
    )


def wait_status_contains(page, needle, timeout=15000):
    page.wait_for_function(
        """(needle) => { const n = document.querySelector('[data-role="sr-status"]'); return !!n && (n.textContent || '').indexOf(needle) !== -1; }""",
        arg=needle,
        timeout=timeout,
    )


def wait_row_text(page, appointment_id, needle, timeout=15000):
    page.wait_for_function(
        """(args) => { const r = document.querySelector('[data-role="sr-row"][data-appointment-id="' + args.id + '"]'); return !!r && (r.textContent || '').indexOf(args.needle) !== -1; }""",
        arg={"id": str(appointment_id), "needle": needle},
        timeout=timeout,
    )


def assert_reception_shell(page):
    if page.locator("html").get_attribute("data-cpms-staff-portal-shell") != "v1":
        raise RuntimeError("shared Staff Portal shell root missing")
    modules = page.locator("[data-cpms-staff-module]")
    ids = [modules.nth(i).get_attribute("data-cpms-staff-module") for i in range(modules.count())]
    # The delivered reception module marker lives in the navigation; the
    # reception <main> carries the module marker as its mount identity. The
    # doctor module must stay absent for this secretary (no clinical module).
    if "doctor" in ids:
        raise RuntimeError(f"secretary must not see the doctor module, got {ids}")
    nav_ids = [
        page.locator('[data-role="staff-module-link"]').nth(i).get_attribute("data-cpms-staff-module")
        for i in range(page.locator('[data-role="staff-module-link"]').count())
    ]
    if nav_ids != ["reception"]:
        raise RuntimeError(f"staff navigation must expose exactly the reception module, got {nav_ids}")
    if not page.locator('[data-role="staff-module-link"]').first.is_visible():
        raise RuntimeError("reception module navigation entry is not visible")
    if page.locator('[data-role="reception-app"]').count() != 1:
        raise RuntimeError("reception app surface missing or duplicated")
    if page.locator('main[data-cpms-staff-module="reception"]').count() != 1:
        raise RuntimeError("reception module <main> marker missing or duplicated")
    if page.locator('[data-shell-user="secretary"]').count() != 1:
        raise RuntimeError("shell user must be the secretary on the reception module")
    if page.locator("main").count() != 1 or page.locator('[role="main"]').count() != 1:
        raise RuntimeError("document must carry exactly one main")
    if page.locator('script[type="application/json"]').count() != 1:
        raise RuntimeError("reception view must publish exactly one runtime config")
    if page.locator('script:not([src]):not([type="application/json"])').count() != 1:
        raise RuntimeError("reception view must carry exactly one inline script")
    if page.locator("#cpms-staff-reception-config").count() != 1:
        raise RuntimeError("reception runtime config missing")
    for sel in CHROME:
        if page.locator(sel).count() != 0:
            raise RuntimeError(f"wp-admin/theme chrome present ({sel})")
    content = page.content()
    if "پورتال پزشک" in content:
        raise RuntimeError("old Doctor Portal identity text present")
    cfg = json.loads(page.locator("#cpms-staff-reception-config").text_content() or "{}")
    if cfg.get("is_reception") is not True:
        raise RuntimeError("portal config is not the reception config")
    if not str(cfg.get("rest_root") or "").endswith("/clinic/v1"):
        raise RuntimeError("portal config is not the staff REST root")
    if not cfg.get("nonce") or not cfg.get("portal_url"):
        raise RuntimeError("portal config lacks nonce/portal_url")
    leaked = [k for k in FORBIDDEN_CONFIG_KEYS if k in cfg]
    if leaked:
        raise RuntimeError(f"published config contains {leaked}")
    title = page.locator('[data-role="portal-header-title"]').inner_text()
    if not PERSIAN_RE.search(title):
        raise RuntimeError("portal title is not Persian")


def assert_authority_headers(state, clinic_id, allowed_locations):
    problems = []
    for req in state["reqs"]:
        if not req["route"].startswith("/clinic/v1"):
            continue
        headers = req["headers"]
        clinic = headers.get("x-cpms-clinic-id", "")
        location = headers.get("x-cpms-location-id", "")
        if clinic and clinic != str(clinic_id):
            problems.append(f"clinic header {clinic}")
        if location and int(location) not in allowed_locations:
            problems.append(f"location header {location}")
    if problems:
        raise RuntimeError(f"unexpected authority selectors on REST calls: {problems[:4]}")


def assert_hygiene(state, label):
    if state["console"] or state["pageerrors"] or state["failed"]:
        raise RuntimeError(
            f"{label} console/page/network errors: console={len(state['console'])} "
            f"pageerrors={len(state['pageerrors'])} failed={len(state['failed'])}"
        )


def run_journey(browser, vp, arrive_id, expect_queue):
    key = f"reception-{vp['vp']}-arrival"
    stage = "login"
    ctx, page, state = new_page(browser, vp)
    try:
        login(page, SECRETARY)
        stage = "reception-entry"
        resp = harness_goto(page, RECEPTION_URL, wait_until="domcontentloaded")
        if resp is None or resp.status != 200:
            raise RuntimeError(f"reception entry status {getattr(resp, 'status', None)}")
        page.wait_for_selector('[data-role="reception-app"]', state="attached", timeout=15000)
        assert_reception_shell(page)

        stage = "strict-location"
        # N>1 Locations: explicit selection REQUIRED before ANY reception data.
        # Wait for the selector to become VISIBLE (context load is async) so
        # the pre-boot DOM cannot race the assertions.
        page.wait_for_selector('[data-role="sr-location-select"]', state="visible", timeout=15000)
        if page.locator('[data-role="sr-location-select"]').count() != 1:
            raise RuntimeError("multi-Location clinic must render the explicit Location selector")
        if not page.locator('[data-role="sr-location-select"]').is_visible():
            raise RuntimeError("Location selector must be visible when N>1")
        if rows(page).count() != 0:
            raise RuntimeError("no booked rows may render before explicit Location selection")
        wait_status_contains(page, "الزامی")
        shot(page, f"reception-{vp['vp']}-strict-location")

        stage = "board"
        select_location(page, PUB["loc_tehran"])
        wait_rows_count(page, 4)
        date_text = page.locator('[data-role="sr-date"]').inner_text() or ""
        if PUB["today_tehran"] not in date_text:
            raise RuntimeError(f"Tehran board date must be the Location-local day, got {date_text[:60]}")
        got_ids = sorted(
            int(rows(page).nth(i).get_attribute("data-appointment-id"))
            for i in range(rows(page).count())
        )
        want_ids = sorted([PUB["appt_express"], PUB["appt_plain"], PUB["appt_third"], PUB["appt_partial"]])
        if got_ids != want_ids:
            raise RuntimeError(f"board must show exactly today's booked rows for the trusted Location, got {got_ids}")
        if row_of(page, PUB["appt_express"]).locator(".cpms-sr-badge--express").count() != 1:
            raise RuntimeError("express booking must render its badge")
        # Booked-but-not-received clarity (presentation-only): the not-yet-
        # arrived row keeps its real appointment state AND says so clearly.
        own_row_text = row_of(page, arrive_id).inner_text() or ""
        if "هنوز پذیرش نشده" not in own_row_text:
            raise RuntimeError("a booked-but-not-received row must clearly say the patient has not been received yet")
        if "تاییدشده" not in own_row_text and "رزرو شده" not in own_row_text:
            raise RuntimeError("the real appointment state badge must remain alongside the clarification")
        if vp["vp"] == "mobile-390":
            shot(page, "reception-mobile-390-booked-not-received")
        shot(page, f"reception-{vp['vp']}-board")

        stage = "arrival"
        mark = nav_mark(page)
        row_of(page, arrive_id).locator('[data-role="sr-arrive"]').click()
        wait_status_contains(page, "حضور ثبت شد")
        wait_row_text(page, arrive_id, "در انتظار")
        queue_rows = page.locator('[data-role="sr-queue-row"]').count()
        if queue_rows != expect_queue:
            raise RuntimeError(
                f"waiting queue must show {expect_queue} arrived patient(s) after this arrival, got {queue_rows}"
            )
        rest_delta = assert_no_product_reload(page, mark, "arrival")
        arrivals = [r for r in state["rest"] if r["route"].endswith("/reception/arrivals")]
        if not arrivals or arrivals[-1]["status"] != 200 or arrivals[-1]["method"] != "POST":
            raise RuntimeError(f"arrival REST call missing/failed: {arrivals[-3:]}")
        boards = [r for r in state["rest"] if r["route"].endswith("/reception/board") and r["status"] == 200]
        if not boards:
            raise RuntimeError("board REST reads missing")
        shot(page, f"reception-{vp['vp']}-arrival-waiting")
        ok(
            key,
            "reception arrival runs existing check-in + enqueue to waiting",
            f"vp={vp['vp']} rows=4 arrived=1 waiting={expect_queue} rest={rest_delta} reloaded=0",
        )

        stage = "empty"
        select_location(page, PUB["loc_tokyo"])
        wait_rows_count(page, 0)
        wait_status_contains(page, "")  # settle
        empty_text = (page.locator('[data-role="sr-appointments-body"]').inner_text() or "")
        if "ثبت نشده" not in empty_text:
            raise RuntimeError(f"empty Location must render the empty state, got {empty_text[:80]}")
        date_text = page.locator('[data-role="sr-date"]').inner_text() or ""
        if PUB["today_tokyo"] not in date_text:
            raise RuntimeError(f"Tokyo board date must be the Location-local day, got {date_text[:60]}")
        shot(page, f"reception-{vp['vp']}-empty")

        if vp["vp"] == "desktop-1366":
            stage = "error-state"
            ctx.set_offline(True)
            select_location(page, PUB["loc_tehran"])
            wait_status_contains(page, "خطا", timeout=20000)
            shot(page, f"reception-{vp['vp']}-error")
            ctx.set_offline(False)
            select_location(page, PUB["loc_tehran"])
            wait_rows_count(page, 4)

        assert_authority_headers(state, PUB["clinic"], {PUB["loc_tehran"], PUB["loc_tokyo"]})
        assert_hygiene(state, f"reception-{vp['vp']}")
        ok(
            f"reception-{vp['vp']}-shell",
            "reception module renders inside the shared Staff Portal shell",
            f"nav=[reception] cfg=1 script=1 chrome=0",
        )
    except Exception as e:
        try:
            dump = {
                "status_text": (page.locator('[data-role="sr-status"]').inner_text() or "")[:160],
                "rows": rows(page).count(),
                "row_ids": [
                    rows(page).nth(i).get_attribute("data-appointment-id")
                    for i in range(rows(page).count())
                ],
                "rest_tail": [
                    f"{r['method']} {r['route']}={r['status']}" for r in state["rest"][-6:]
                ],
            }
            info(f"fail-dump {vp['vp']} stage={stage} {dump}")
        except Exception:
            pass
        try:
            shot(page, f"reception-FAIL-{key}-{stage}")
        except Exception:
            pass
        fail(key, vp["vp"], f"{e} ({page_hint(page)})")
        raise
    finally:
        ctx.close()


def run_partial_journey(browser, vp):
    """B–E of the acceptance browser evidence: a partial arrival ending in
    checked_in, the visible recovery action/message, one retry reaching
    waiting without a duplicate Visit (server-side proof in the integration
    regression), and a sticky partial status that silent refreshes do not
    erase. Failure injection is the TEST-ONLY cookie-sabotage mu-plugin
    (tests/fixtures/pilot-reception-sabotage-mu.php) — no production hook."""
    from urllib.parse import urlsplit

    key = f"reception-partial-{vp['vp']}"
    stage = "login"
    ctx, page, state = new_page(browser, vp)
    try:
        login(page, SECRETARY)
        stage = "partial-setup"
        resp = harness_goto(page, RECEPTION_URL, wait_until="domcontentloaded")
        if resp is None or resp.status != 200:
            raise RuntimeError(f"reception entry status {getattr(resp, 'status', None)}")
        page.wait_for_selector('[data-role="reception-app"]', state="attached", timeout=15000)
        select_location(page, PUB["loc_tehran"])
        wait_rows_count(page, 4)

        stage = "partial-arrival"
        host = urlsplit(RECEPTION_URL).hostname or ""
        ctx.add_cookies([{"name": "rp_sabotage", "value": "1", "domain": host, "path": "/"}])
        row_of(page, PUB["appt_partial"]).locator('[data-role="sr-arrive"]').click()
        wait_status_contains(page, "قرارگیری در صف انجام نشد")
        arrivals = [r for r in state["rest"] if r["route"].endswith("/reception/arrivals")]
        if not arrivals or arrivals[-1]["status"] < 400 or arrivals[-1]["method"] != "POST":
            raise RuntimeError(f"partial arrival must answer non-success: {arrivals[-3:]}")
        # Disarm WITHOUT touching the WP login cookies (clearing all cookies
        # logs the user out — the REST nonce check then fails with 403).
        ctx.add_cookies([{"name": "rp_sabotage", "value": "0", "domain": host, "path": "/"}])
        shot(page, f"reception-{vp['vp']}-partial")

        stage = "recovery-control"
        # The board re-renders after the failed arrival; wait for the recovery
        # control itself (not just row count) so stale rows cannot race us.
        page.wait_for_selector(
            f'[data-appointment-id="{PUB["appt_partial"]}"] [data-role="sr-recover"]',
            state="attached",
            timeout=15000,
        )
        wait_rows_count(page, 4)
        if row_of(page, PUB["appt_partial"]).locator('[data-role="sr-recover"]').count() != 1:
            raise RuntimeError("checked_in row must expose exactly one recovery action")
        row_text = row_of(page, PUB["appt_partial"]).inner_text() or ""
        if "در انتظار" in row_text:
            raise RuntimeError("partial arrival must end honestly in checked_in, not waiting")

        stage = "sticky-status"
        page.wait_for_timeout(6500)
        status_now = (page.locator('[data-role="sr-status"]').inner_text() or "")
        if "قرارگیری در صف انجام نشد" not in status_now:
            raise RuntimeError("silent refresh erased the actionable partial-failure message")
        if row_of(page, PUB["appt_partial"]).locator('[data-role="sr-recover"]').count() != 1:
            raise RuntimeError("the recovery action must survive silent refreshes")
        shot(page, f"reception-{vp['vp']}-partial-sticky")

        stage = "recovery"
        mark = nav_mark(page)
        row_of(page, PUB["appt_partial"]).locator('[data-role="sr-recover"]').click()
        wait_status_contains(page, "حضور ثبت شد و بیمار در صف انتظار قرار گرفت")
        wait_row_text(page, PUB["appt_partial"], "در انتظار")
        arrivals = [r for r in state["rest"] if r["route"].endswith("/reception/arrivals")]
        if arrivals[-1]["status"] != 200 or arrivals[-1]["method"] != "POST":
            raise RuntimeError(f"the recovery must succeed in one retry: {arrivals[-2:]}")
        if row_of(page, PUB["appt_partial"]).locator('[data-role="sr-arrive"], [data-role="sr-recover"]').count() != 0:
            raise RuntimeError("a queued row must expose no arrival/recovery action")
        if page.locator('[data-role="sr-queue-row"]').count() != 4:
            raise RuntimeError("the waiting queue must show all four arrived patients")
        rest_delta = assert_no_product_reload(page, mark, "recovery")
        shot(page, f"reception-{vp['vp']}-partial-recovered")

        assert_authority_headers(state, PUB["clinic"], {PUB["loc_tehran"], PUB["loc_tokyo"]})
        assert_hygiene(state, f"reception-{vp['vp']}-partial")
        ok(
            key,
            "partial arrival ends checked_in and one retry completes the existing enqueue",
            f"vp={vp['vp']} partial_http=400+ sticky=1 recover_http=200 waiting=4 rest={rest_delta} reloaded=0",
        )
    except Exception as e:
        try:
            dump = {
                "status_text": (page.locator('[data-role="sr-status"]').inner_text() or "")[:160],
                "rows": rows(page).count(),
                "rest_tail": [f"{r['method']} {r['route']}={r['status']}" for r in state["rest"][-6:]],
            }
            info(f"fail-dump {vp['vp']} stage={stage} {dump}")
        except Exception:
            pass
        try:
            shot(page, f"reception-FAIL-{key}-{stage}")
        except Exception:
            pass
        fail(key, vp["vp"], f"{e} ({page_hint(page)})")
        raise
    finally:
        ctx.close()


def run_queue_states_journey(browser, vp):
    """Called / in-consultation visual evidence. The reception patient is put
    into waiting through the established flow, then the EXISTING authorized
    Doctor module (real doctor login, real queue actions on the real Doctor
    Portal page) calls and starts that patient; the Reception board is
    observed showing both existing queue states. No direct DB mutation."""
    key = f"reception-queue-states-{vp['vp']}"
    stage = "secretary-setup"
    ctx, page, state = new_page(browser, vp)
    dctx = None
    try:
        login(page, SECRETARY)
        resp = harness_goto(page, RECEPTION_URL, wait_until="domcontentloaded")
        if resp is None or resp.status != 200:
            raise RuntimeError(f"reception entry status {getattr(resp, 'status', None)}")
        page.wait_for_selector('[data-role="reception-app"]', state="attached", timeout=15000)
        select_location(page, PUB["loc_tehran"])
        wait_rows_count(page, 4)
        # The recovered partial-arrival patient is in waiting via the
        # established check-in + enqueue flow (previous journey).
        wait_row_text(page, PUB["appt_partial"], "در انتظار")

        stage = "doctor-call"
        dctx, dpage, dstate = new_page(browser, vp)
        login(dpage, DOCTOR)
        resp = harness_goto(dpage, DOCTOR_URL, wait_until="domcontentloaded")
        if resp is None or resp.status != 200:
            raise RuntimeError(f"doctor portal entry status {getattr(resp, 'status', None)}")
        dpage.wait_for_selector('script.cpms-doctor-portal__config', state="attached", timeout=15000)
        # The Doctor Portal enforces the SAME Location policy: 0 => fail closed,
        # 1 => auto, N>1 => explicit REQUIRED. Drive its existing selector when
        # the queue is not auto-bound.
        try:
            dpage.wait_for_selector('[data-role="queue-item"]', state="attached", timeout=8000)
        except Exception:
            loc_sel = dpage.locator('[data-role="location-select"]')
            loc_sel.wait_for(state="visible", timeout=10000)
            dpage.select_option('[data-role="location-select"]', str(PUB["loc_tehran"]))
            dpage.wait_for_selector('[data-role="queue-item"]', state="attached", timeout=20000)
        # Bind "that patient" across the two real UIs: reception row name →
        # the doctor queue row of the same patient (visit id from the DOM).
        name = (row_of(page, PUB["appt_partial"]).locator(".cpms-sr-name").inner_text() or "").strip()
        target = dpage.locator('[data-role="queue-item"]').filter(has_text=name)
        if target.count() != 1:
            raise RuntimeError(f"doctor queue must hold exactly one row for this patient, got {target.count()}")
        vid = target.get_attribute("data-visit-id") or ""
        if not vid:
            raise RuntimeError("doctor queue row must carry its visit id")
        target.locator('[data-action="call"]').click()
        dpage.wait_for_function(
            """([vid, st]) => {
              const el = document.querySelector('[data-role="queue-item"][data-visit-id="' + vid + '"]');
              return !!el && el.getAttribute('data-status') === st;
            }""",
            arg=[vid, "called"],
            timeout=20000,
        )

        stage = "reception-called"
        wait_row_text(page, PUB["appt_partial"], "فراخوانی‌شده")
        shot(page, f"reception-{vp['vp']}-called")

        stage = "doctor-start"
        target.locator('[data-action="start"]').click()
        dpage.wait_for_function(
            """([vid, st]) => {
              const el = document.querySelector('[data-role="queue-item"][data-visit-id="' + vid + '"]');
              return !!el && el.getAttribute('data-status') === st;
            }""",
            arg=[vid, "in_consultation"],
            timeout=20000,
        )

        stage = "reception-in-consultation"
        wait_row_text(page, PUB["appt_partial"], "در ویزیت")
        shot(page, f"reception-{vp['vp']}-in-consultation")

        assert_authority_headers(state, PUB["clinic"], {PUB["loc_tehran"], PUB["loc_tokyo"]})
        assert_hygiene(state, f"reception-{vp['vp']}-queue-states")
        assert_hygiene(dstate, f"doctor-{vp['vp']}-queue-states")
        ok(
            key,
            "reception board shows the existing doctor queue states after real doctor actions",
            f"vp={vp['vp']} called=1 in_consultation=1 visit={vid}",
        )
    except Exception as e:
        try:
            dump = {
                "status_text": (page.locator('[data-role="sr-status"]').inner_text() or "")[:160],
                "rows": rows(page).count(),
                "rest_tail": [f"{r['method']} {r['route']}={r['status']}" for r in state["rest"][-6:]],
                "doctor_rows": dpage.locator('[data-role="queue-item"]').count() if dctx is not None else -1,
                "doctor_rest": [f"{r['method']} {r['route']}={r['status']}" for r in dstate["rest"][-6:]] if dctx is not None else [],
            }
            info(f"fail-dump {vp['vp']} stage={stage} {dump}")
        except Exception:
            pass
        try:
            shot(page, f"reception-FAIL-{key}-{stage}")
        except Exception:
            pass
        fail(key, vp["vp"], f"{e} ({page_hint(page)})")
        raise
    finally:
        if dctx is not None:
            dctx.close()
        ctx.close()


SEARCH_ROUTE = "/staff/portal/reception/patients/search"
CREATE_ROUTE = "/staff/portal/reception/patients"


def search_calls(state):
    return [r for r in state["rest"] if r["route"].endswith(SEARCH_ROUTE)]


def search_result_ids(page):
    items = page.locator('[data-role="sr-search-result"]')
    return [int(items.nth(i).get_attribute("data-patient-id") or 0) for i in range(items.count())]


def wait_search_state(page, needle, timeout=15000):
    page.wait_for_function(
        """(needle) => { const n = document.querySelector('[data-role="sr-search-state"]'); return !!n && (n.textContent || '').indexOf(needle) !== -1; }""",
        arg=needle,
        timeout=timeout,
    )


def assert_no_horizontal_overflow(page, label):
    over = page.evaluate(
        "() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - document.documentElement.clientWidth"
    )
    if over > 1:
        raise RuntimeError(f"{label} horizontal overflow {over}px")


def run_search_journey(browser, vp):
    """Phase 11 Slice 2 — read-only Clinic patient search inside Reception.
    Idle/too-short, debounced search, masked national ID, Clinic isolation,
    Location-neutral results, read-only selection (no write traffic, no
    action controls), no-results state, no overflow, board still intact."""
    key = f"reception-search-{vp['vp']}"
    stage = "login"
    ctx, page, state = new_page(browser, vp)
    try:
        login(page, SECRETARY)
        stage = "reception-entry"
        resp = harness_goto(page, RECEPTION_URL, wait_until="domcontentloaded")
        if resp is None or resp.status != 200:
            raise RuntimeError(f"reception entry status {getattr(resp, 'status', None)}")
        page.wait_for_selector('[data-role="reception-app"]', state="attached", timeout=15000)
        assert_reception_shell(page)
        page.wait_for_selector('[data-role="sr-location-select"]', state="visible", timeout=15000)
        select_location(page, PUB["loc_tehran"])
        wait_rows_count(page, 4)
        if not (page.locator('[data-role="sr-clinic"]').inner_text() or "").strip():
            raise RuntimeError("trusted Clinic context chip must stay visible")

        stage = "idle"
        search_input = page.locator('[data-role="sr-search-input"]')
        if not search_input.is_visible():
            raise RuntimeError("search input must be visible inside Reception")
        if page.locator('[data-role="sr-search"] label[for="cpms-sr-search-input"]').count() != 1:
            raise RuntimeError("search input must carry a visible Persian label")
        wait_search_state(page, "دست‌کم ۲")
        search_input.fill("P")
        page.wait_for_timeout(900)
        if search_calls(state):
            raise RuntimeError("a one-character query must not hit the server")
        wait_search_state(page, "دست‌کم ۲")
        assert_no_horizontal_overflow(page, "idle")
        shot(page, f"reception-{vp['vp']}-search-idle")

        stage = "results"
        mark = nav_mark(page)
        search_input.fill("")
        search_input.type("Probe", delay=60)
        wait_search_state(page, "یافت شد")
        calls = search_calls(state)
        if not calls or len(calls) > 2:
            raise RuntimeError(f"debounced typing must issue 1..2 search calls, got {len(calls)}")
        if any(c["method"] != "GET" or c["status"] != 200 for c in calls):
            raise RuntimeError(f"search calls must be successful GETs: {calls}")
        ids = search_result_ids(page)
        if ids != [SEARCH["probe"]]:
            raise RuntimeError(f"search must return exactly the trusted-Clinic probe, got {ids}")
        if SEARCH["foreign"] in ids:
            raise RuntimeError("foreign-Clinic patient leaked into Reception search")
        result_text = page.locator('[data-role="sr-search-result"]').first.inner_text() or ""
        if "***" + SEARCH["nid_last4"] not in result_text:
            raise RuntimeError("national ID must render in the established masked form")
        assert_no_horizontal_overflow(page, "results")
        shot(page, f"reception-{vp['vp']}-search-results")

        stage = "selection"
        board_before = page.locator('[data-role="sr-appointments-body"]').inner_text() or ""
        writes_before = [r for r in state["rest"] if r["method"] != "GET"]
        page.locator(f'[data-role="sr-search-result"][data-patient-id="{SEARCH["probe"]}"]').click()
        page.wait_for_selector('[data-role="sr-search-selected"]', state="visible", timeout=5000)
        if page.locator('[data-role="sr-search-result"][aria-pressed="true"]').count() != 1:
            raise RuntimeError("exactly one result must be marked selected")
        selected = page.locator('[data-role="sr-search-selected"]')
        if "***" + SEARCH["nid_last4"] not in (selected.inner_text() or ""):
            raise RuntimeError("selected state must keep the masked national ID")
        buttons = selected.locator("button")
        roles = [buttons.nth(i).get_attribute("data-role") for i in range(buttons.count())]
        if roles != ["sr-search-clear"]:
            raise RuntimeError(f"selected state must expose no action besides clearing, got {roles}")
        if page.locator('[data-role="sr-search"] [data-role="sr-arrive"], [data-role="sr-search"] [data-role="sr-recover"], [data-role="sr-search"] a[href]').count() != 0:
            raise RuntimeError("search panel must not expose arrival/navigation actions")
        page.wait_for_timeout(600)
        writes_after = [r for r in state["rest"] if r["method"] != "GET"]
        if len(writes_after) != len(writes_before):
            raise RuntimeError("selecting a search result must not issue any write request")
        board_after = page.locator('[data-role="sr-appointments-body"]').inner_text() or ""
        if board_before != board_after:
            raise RuntimeError("selecting a search result must not change the Arrival Board")
        assert_no_horizontal_overflow(page, "selected")
        shot(page, f"reception-{vp['vp']}-search-selected")

        stage = "location-neutral"
        select_location(page, PUB["loc_tokyo"])
        wait_rows_count(page, 0)
        with page.expect_response(lambda r: SEARCH_ROUTE in r.url, timeout=15000) as resp_info:
            page.locator('[data-role="sr-search-submit"]').click()
        if resp_info.value.status != 200:
            raise RuntimeError(f"search from the other Location answered {resp_info.value.status}")
        wait_search_state(page, "یافت شد")
        if search_result_ids(page) != [SEARCH["probe"]]:
            raise RuntimeError("Location selection must not change the Clinic search result")
        if not page.locator('[data-role="sr-search-selected"]').is_visible():
            raise RuntimeError("the read-only selection must survive a Location switch")
        select_location(page, PUB["loc_tehran"])
        wait_rows_count(page, 4)

        stage = "no-results"
        search_input.fill("zzqx-no-such-patient")
        wait_search_state(page, "یافت نشد")
        if page.locator('[data-role="sr-search-result"]').count() != 0:
            raise RuntimeError("no-results state must render no result rows")
        assert_no_horizontal_overflow(page, "no-results")
        shot(page, f"reception-{vp['vp']}-search-no-results")

        stage = "clear"
        page.locator('[data-role="sr-search-clear"]').click()
        page.wait_for_selector('[data-role="sr-search-selected"]', state="hidden", timeout=5000)

        rest_delta = assert_no_product_reload(page, mark, "search")
        if any(r["method"] != "GET" for r in state["rest"]):
            raise RuntimeError("the search journey must be read-only (GET only)")
        assert_authority_headers(state, PUB["clinic"], {PUB["loc_tehran"], PUB["loc_tokyo"]})
        assert_hygiene(state, key)
        ok(
            key,
            "reception clinic patient search is bounded, masked, Location-neutral and read-only",
            f"vp={vp['vp']} results=1 foreign=0 masked=1 selected=1 writes=0 search_calls={len(search_calls(state))} rest={rest_delta} reloaded=0 overflow=0",
        )
    except Exception as e:
        try:
            dump = {
                "search_state": (page.locator('[data-role="sr-search-state"]').inner_text() or "")[:120],
                "results": page.locator('[data-role="sr-search-result"]').count(),
                "rest_tail": [f"{r['method']} {r['route']}={r['status']}" for r in state["rest"][-6:]],
            }
            info(f"fail-dump {vp['vp']} stage={stage} {dump}")
        except Exception:
            pass
        try:
            shot(page, f"reception-FAIL-{key}-{stage}")
        except Exception:
            pass
        fail(key, vp["vp"], f"{e} ({page_hint(page)})")
        raise
    finally:
        ctx.close()


def is_create_path(url):
    path = (urlparse(url).path or "").rstrip("/")
    return path.endswith(CREATE_ROUTE)


def create_calls(state):
    return [r for r in state["rest"] if r["route"].rstrip("/").endswith(CREATE_ROUTE)]


def make_nid(seed):
    body = f"{(100000000 + (int(seed) % 800000000)):09d}"[:9]
    total = sum(int(body[i]) * (10 - i) for i in range(9))
    rem = total % 11
    check = 0 if rem == 0 else (1 if rem == 1 else 11 - rem)
    nid = body + str(check)
    if len(set(nid)) == 1:
        return make_nid(int(seed) + 19)
    return nid


def run_create_journey(browser, vp):
    """Phase 11 Slice 3 — create a Clinic patient then read-only select.

    Search miss exposes create; small form; successful create becomes the
    existing selected presentation (masked national ID); no automatic
    Visit/appointment/queue; duplicate mobile and national-ID conflict stay
    bounded product errors; clear-selection and Slice 2 search still work.
    """
    key = f"reception-create-{vp['vp']}"
    stage = "login"
    ctx, page, state = new_page(browser, vp)
    try:
        login(page, SECRETARY)
        stage = "reception-entry"
        resp = harness_goto(page, RECEPTION_URL, wait_until="domcontentloaded")
        if resp is None or resp.status != 200:
            raise RuntimeError(f"reception entry status {getattr(resp, 'status', None)}")
        page.wait_for_selector('[data-role="reception-app"]', state="attached", timeout=15000)
        assert_reception_shell(page)
        page.wait_for_selector('[data-role="sr-location-select"]', state="visible", timeout=15000)
        select_location(page, PUB["loc_tehran"])
        wait_rows_count(page, 4)

        stage = "search-miss"
        mark = nav_mark(page)
        board_before = page.locator('[data-role="sr-appointments-body"]').inner_text() or ""
        search_input = page.locator('[data-role="sr-search-input"]')
        search_input.fill("zzqx-create-miss")
        wait_search_state(page, "یافت نشد")
        create_open = page.locator('[data-role="sr-create-open"]')
        if not create_open.is_visible():
            raise RuntimeError("create action must appear after a search miss")
        assert_no_horizontal_overflow(page, "create-miss")
        shot(page, f"reception-{vp['vp']}-create-miss")

        stage = "form"
        create_open.click()
        page.wait_for_selector('[data-role="sr-create"]', state="visible", timeout=5000)
        for role in ("sr-create-first-name", "sr-create-last-name", "sr-create-mobile"):
            loc = page.locator(f'[data-role="{role}"]')
            if not loc.is_visible():
                raise RuntimeError(f"required field {role} must be visible")
        if not page.locator('[data-role="sr-create-national-id"]').is_visible():
            raise RuntimeError("optional national ID field must be present")
        assert_no_horizontal_overflow(page, "create-form")
        shot(page, f"reception-{vp['vp']}-create-form")

        stage = "success"
        vp_n = {"mobile-390": 1, "tablet-768": 2, "desktop-1366": 3}[vp["vp"]]
        stamp = int(time.time()) % 100000
        mobile = f"0935{(stamp * 10 + vp_n) % 10000000:07d}"
        mobile2 = f"0936{(stamp * 10 + vp_n) % 10000000:07d}"
        nid = make_nid(stamp * 10 + vp_n)
        page.locator('[data-role="sr-create-first-name"]').fill("ساخته")
        page.locator('[data-role="sr-create-last-name"]').fill("پذیرش")
        page.locator('[data-role="sr-create-mobile"]').fill(mobile)
        page.locator('[data-role="sr-create-national-id"]').fill(nid)
        page.locator('[data-role="sr-create-birth-date"]').fill("1985-04-01")
        page.locator('[data-role="sr-create-gender"]').select_option("female")
        writes_before = [r for r in state["rest"] if r["method"] != "GET"]
        with page.expect_response(lambda r: is_create_path(r.url) and r.request.method == "POST", timeout=15000) as resp_info:
            page.locator('[data-role="sr-create-submit"]').click()
        created = resp_info.value
        if created.status != 200:
            raise RuntimeError(f"reception create answered {created.status}")
        page.wait_for_selector('[data-role="sr-search-selected"]', state="visible", timeout=8000)
        wait_search_state(page, "ثبت شد")
        selected = page.locator('[data-role="sr-search-selected"]')
        selected_text = selected.inner_text() or ""
        if "***" + nid[-4:] not in selected_text:
            raise RuntimeError("created selection must show the masked national ID")
        if "ساخته" not in selected_text:
            raise RuntimeError("created selection must show the new patient name")
        if page.locator('[data-role="sr-create"]').is_visible():
            raise RuntimeError("create form must close after success")
        writes_after = [r for r in state["rest"] if r["method"] != "GET"]
        new_writes = writes_after[len(writes_before) :]
        routes = [f"{w['method']} {w['route']}={w['status']}" for w in new_writes]
        if len(new_writes) != 1 or new_writes[0]["method"] != "POST" or not new_writes[0]["route"].rstrip("/").endswith(CREATE_ROUTE):
            raise RuntimeError(f"create must be the only write, got {routes}")
        if any("arrivals" in w["route"] or "/visits" in w["route"] or "appointments" in w["route"] or "queue" in w["route"] for w in new_writes):
            raise RuntimeError("create must not trigger arrival/visit/appointment/queue writes")
        board_after = page.locator('[data-role="sr-appointments-body"]').inner_text() or ""
        if board_before != board_after:
            raise RuntimeError("successful create must not change the Arrival Board")
        assert_no_horizontal_overflow(page, "create-success")
        shot(page, f"reception-{vp['vp']}-create-success")

        stage = "duplicate-mobile"
        page.locator('[data-role="sr-create-open"]').click()
        page.wait_for_selector('[data-role="sr-create"]', state="visible", timeout=5000)
        page.locator('[data-role="sr-create-first-name"]').fill("تکراری")
        page.locator('[data-role="sr-create-last-name"]').fill("موبایل")
        page.locator('[data-role="sr-create-mobile"]').fill(mobile)
        page.locator('[data-role="sr-create-national-id"]').fill("")
        with page.expect_response(lambda r: is_create_path(r.url) and r.request.method == "POST", timeout=15000) as dup_info:
            page.locator('[data-role="sr-create-submit"]').click()
        if dup_info.value.status == 200:
            raise RuntimeError("duplicate mobile must not succeed")
        page.wait_for_function(
            """() => { const n = document.querySelector('[data-role="sr-create-error"]'); return !!n && !n.hidden && (n.textContent || '').indexOf('موبایل') !== -1; }""",
            timeout=8000,
        )
        dup_err = page.locator('[data-role="sr-create-error"]').inner_text() or ""
        if not dup_err.strip():
            raise RuntimeError("duplicate mobile must show a bounded error")
        if any(tok in dup_err for tok in ("SQL", "Duplicate", "u_pat_", "cpms_patients")):
            raise RuntimeError("duplicate mobile error leaked internals")
        if page.locator('[data-role="sr-create-mobile"]').input_value() != mobile:
            raise RuntimeError("failed create must preserve the entered mobile")
        if "ساخته" not in (page.locator('[data-role="sr-search-selected"]').inner_text() or ""):
            raise RuntimeError("failed duplicate must not replace the already created selection")
        assert_no_horizontal_overflow(page, "create-dup-mobile")
        shot(page, f"reception-{vp['vp']}-create-dup-mobile")

        stage = "nid-conflict"
        page.locator('[data-role="sr-create-first-name"]').fill("تکراری")
        page.locator('[data-role="sr-create-last-name"]').fill("کدملی")
        page.locator('[data-role="sr-create-mobile"]').fill(mobile2)
        page.locator('[data-role="sr-create-national-id"]').fill(nid)
        with page.expect_response(lambda r: is_create_path(r.url) and r.request.method == "POST", timeout=15000) as nid_info:
            page.locator('[data-role="sr-create-submit"]').click()
        if nid_info.value.status == 200:
            raise RuntimeError("national-ID conflict must not succeed")
        page.wait_for_function(
            """() => { const n = document.querySelector('[data-role="sr-create-error"]'); return !!n && !n.hidden && (n.textContent || '').indexOf('کد ملی') !== -1; }""",
            timeout=8000,
        )
        nid_err = page.locator('[data-role="sr-create-error"]').inner_text() or ""
        if any(tok in nid_err for tok in ("SQL", "Duplicate", "u_pat_nid", "cpms_patients")):
            raise RuntimeError("national-ID conflict leaked internals")
        if page.locator('[data-role="sr-create"]').is_hidden():
            raise RuntimeError("national-ID conflict must keep the form open")
        assert_no_horizontal_overflow(page, "create-nid")
        shot(page, f"reception-{vp['vp']}-create-nid")

        stage = "clear"
        page.locator('[data-role="sr-search-clear"]').click()
        page.wait_for_selector('[data-role="sr-search-selected"]', state="hidden", timeout=5000)

        stage = "search-after"
        search_input.fill("")
        search_input.type("Probe", delay=40)
        wait_search_state(page, "یافت شد")
        if search_result_ids(page) != [SEARCH["probe"]]:
            raise RuntimeError("Slice 2 search must still find the probe after create")
        wait_rows_count(page, 4)

        rest_delta = assert_no_product_reload(page, mark, "create")
        assert_authority_headers(state, PUB["clinic"], {PUB["loc_tehran"], PUB["loc_tokyo"]})
        assert_hygiene(state, key)
        ok(
            key,
            "reception clinic patient create is bounded, masked and does not auto-queue",
            f"vp={vp['vp']} create=1 dup=1 nid=1 masked=1 writes_create={len(create_calls(state))} rest={rest_delta} reloaded=0 overflow=0",
        )
    except Exception as e:
        try:
            dump = {
                "search_state": (page.locator('[data-role="sr-search-state"]').inner_text() or "")[:120],
                "create_error": (page.locator('[data-role="sr-create-error"]').inner_text() or "")[:120],
                "rest_tail": [f"{r['method']} {r['route']}={r['status']}" for r in state["rest"][-8:]],
            }
            info(f"fail-dump {vp['vp']} stage={stage} {dump}")
        except Exception:
            pass
        try:
            shot(page, f"reception-FAIL-{key}-{stage}")
        except Exception:
            pass
        fail(key, vp["vp"], f"{e} ({page_hint(page)})")
        raise
    finally:
        ctx.close()


def main():
    if not RECEPTION_URL or not STAFF_URL:
        raise SystemExit("RECEPTION_URL / STAFF_PORTAL_URL must be set by the fixture")
    with sync_playwright() as p:
        browser = p.chromium.launch()
        hard_fail = False
        per_vp_arrival = [
            (PUB["appt_express"], 1),
            (PUB["appt_plain"], 2),
            (PUB["appt_third"], 3),
        ]
        for vp, (arrive_id, expect_queue) in zip(VIEWPORTS, per_vp_arrival):
            try:
                run_journey(browser, vp, arrive_id, expect_queue)
            except Exception:
                hard_fail = True
        try:
            run_partial_journey(browser, VIEWPORTS[0])
        except Exception:
            hard_fail = True
        try:
            run_queue_states_journey(browser, VIEWPORTS[2])
        except Exception:
            hard_fail = True
        # Phase 11 Slice 2 — read-only Clinic patient search at all widths
        # (after the Slice 1 journeys, which stay unchanged).
        for vp in VIEWPORTS:
            try:
                run_search_journey(browser, vp)
            except Exception:
                hard_fail = True
        # Phase 11 Slice 3 — create Clinic patient then read-only select.
        for vp in VIEWPORTS:
            try:
                run_create_journey(browser, vp)
            except Exception:
                hard_fail = True
        browser.close()
    print("---")
    print(f"SUMMARY pass={sum(1 for r in results if r['status'] == 'PASS')} fail={len(failures)}")
    for key in failures:
        print(f"SUMMARY-FAIL {key}")
    sys.exit(1 if (failures or hard_fail) else 0)


if __name__ == "__main__":
    main()
