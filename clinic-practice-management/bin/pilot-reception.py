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
from urllib.parse import parse_qsl, urlparse

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
# Existing four booked fixture rows remain the exact Slice 1–4 invariant.
# Slice 5 creates one same-day row per booking journey, so this additive counter
# tracks only those durable new rows across the three viewport journeys.
BOOKING_BOARD_ROWS = 4


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
_bk = parts("RECEPTION_BOOKING", 14)
BOOKING = {
    "c1": int(_bk[0]),
    "c2": int(_bk[1]),
    "slots": {"free_a": int(_bk[2]), "free_b": int(_bk[3]), "free_c": int(_bk[4]), "full": int(_bk[5]), "closed": int(_bk[6]), "future": int(_bk[7])},
    "slot_by_vp": {"mobile-390": int(_bk[2]), "tablet-768": int(_bk[3]), "desktop-1366": int(_bk[4])},
    "tomorrow": _bk[8],
    "patients": {"mobile-390": int(_bk[9]), "tablet-768": int(_bk[10]), "desktop-1366": int(_bk[11])},
    "mrn": {"mobile-390": _bk[12] + "MOBILE" + _bk[13], "tablet-768": _bk[12] + "TABLET" + _bk[13], "desktop-1366": _bk[12] + "DESKTOP" + _bk[13]},
}
SLOTS_ROUTE = "/staff/portal/reception/slots"
APPOINTMENTS_ROUTE = "/staff/portal/reception/appointments"
# Captured REST routes keep the WordPress namespace prefix
# (`/clinic/v1/staff/portal/reception/appointments/<id>/cancel`), so the
# reception cancel mutation is identified by its concrete path shape — the
# namespace-prefixed route still ends with this exact suffix.
CANCEL_PATH_RE = re.compile(r"/staff/portal/reception/appointments/[0-9]+/cancel$")
_cx = parts("RECEPTION_CANCEL", 9)
CANCEL = {
    "c1": int(_cx[0]),
    "slots": {"mobile-390": int(_cx[1]), "tablet-768": int(_cx[2]), "desktop-1366": int(_cx[3])},
    "patients": {"mobile-390": int(_cx[4]), "tablet-768": int(_cx[5]), "desktop-1366": int(_cx[6])},
    "mrn": {"mobile-390": _cx[7] + "MOBILE" + _cx[8], "tablet-768": _cx[7] + "TABLET" + _cx[8], "desktop-1366": _cx[7] + "DESKTOP" + _cx[8]},
}
_rs = parts("RECEPTION_RESCHEDULE", 16)
_wi = parts("RECEPTION_WALKIN", 9)
WALKIN = {
    "c1": int(_wi[0]),
    "c2": int(_wi[1]),
    "decoy": int(_wi[2]),
    "patients": {"mobile-390": int(_wi[3]), "tablet-768": int(_wi[4]), "desktop-1366": int(_wi[5]), "partial": int(_wi[6])},
    "mrn": {"mobile-390": _wi[7] + "MOBILE" + _wi[8], "tablet-768": _wi[7] + "TABLET" + _wi[8], "desktop-1366": _wi[7] + "DESKTOP" + _wi[8], "partial": _wi[7] + "PARTIAL" + _wi[8]},
}
# Phase 11 Slice 7 — reschedule within the CURRENT trusted Location. The
# destination is ALWAYS a persisted slot of the CURRENT operational Location
# (cross-Location reschedule is a product decision OUT OF SCOPE): the journey
# books its own source slot through the existing Slice 5 UI, then reschedules
# that confirmed row through the new boundary and proves the old row leaves the
# board, the destination slot is claimed, the released source slot is offered
# again by the existing bounded read and no Visit/queue/payment appears.
RESCHEDULE = {
    "c1": int(_rs[0]),
    "c2": int(_rs[1]),
    "source": {"mobile-390": int(_rs[2]), "tablet-768": int(_rs[3]), "desktop-1366": int(_rs[4])},
    "dest": {"mobile-390": int(_rs[5]), "tablet-768": int(_rs[6]), "desktop-1366": int(_rs[7])},
    "dest_date": {"mobile-390": _rs[8], "tablet-768": _rs[9], "desktop-1366": _rs[10]},
    "patients": {"mobile-390": int(_rs[11]), "tablet-768": int(_rs[12]), "desktop-1366": int(_rs[13])},
    "mrn": {"mobile-390": _rs[14] + "MOBILE" + _rs[15], "tablet-768": _rs[14] + "TABLET" + _rs[15], "desktop-1366": _rs[14] + "DESKTOP" + _rs[15]},
}
# The destination doctor per viewport: MOBILE stays on the SAME clinician,
# TABLET explicitly switches to the OTHER eligible clinician of the same
# Location, DESKTOP stays on the current one with an off-operational-day date.
RESCHEDULE_DEST_DOCTOR = {
    "mobile-390": RESCHEDULE["c1"],
    "tablet-768": RESCHEDULE["c2"],
    "desktop-1366": RESCHEDULE["c1"],
}
RESCHEDULE_PATH_RE = re.compile(r"/staff/portal/reception/appointments/[0-9]+/reschedule$")
UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
WALKIN_ROUTE = "/staff/portal/reception/walk-ins"
CLINICIANS_ROUTE = "/staff/portal/reception/clinicians"


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
        state["reqs"].append(
            {
                "method": req.method,
                "route": route_of(req.url),
                "query": dict(parse_qsl(urlparse(req.url).query, keep_blank_values=True)),
                "headers": headers,
            }
        )

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


def walkin_calls(state):
    return [r for r in state["rest"] if r["route"].rstrip("/").endswith(WALKIN_ROUTE)]


def wait_walkin_state(page, needle, timeout=15000):
    page.wait_for_function(
        """(needle) => { const n = document.querySelector('[data-role="sr-walkin-state"]'); return !!n && (n.textContent || '').indexOf(needle) !== -1; }""",
        arg=needle,
        timeout=timeout,
    )


def walkin_options(page):
    select = page.locator('[data-role="sr-walkin-clinician"]')
    opts = select.locator("option")
    return [opts.nth(i).get_attribute("value") or "" for i in range(opts.count())]


def wait_walkin_many(page, timeout=15000):
    page.wait_for_function(
        """() => { const w = document.querySelector('[data-role="sr-walkin-clinician-wrap"]'); const s = document.querySelector('[data-role="sr-walkin-clinician"]'); return !!w && !w.hidden && !!s && s.options.length > 1; }""",
        timeout=timeout,
    )


def wait_walkin_single(page, timeout=15000):
    page.wait_for_function(
        """() => { const d = document.querySelector('[data-role="sr-walkin-doctor"]'); const w = document.querySelector('[data-role="sr-walkin-clinician-wrap"]'); return !!d && !d.hidden && !!w && w.hidden; }""",
        timeout=timeout,
    )


def select_walkin_patient(page, tag):
    search_input = page.locator('[data-role="sr-search-input"]')
    search_input.fill("")
    search_input.type(WALKIN["mrn"][tag], delay=10)
    wait_search_state(page, "یافت شد")
    pid = WALKIN["patients"][tag]
    if pid not in search_result_ids(page):
        raise RuntimeError(f"walk-in patient {pid} must be found through Slice 2 search")
    page.locator(f'[data-role="sr-search-result"][data-patient-id="{pid}"]').click()
    page.wait_for_selector('[data-role="sr-search-selected"]', state="visible", timeout=5000)
    page.wait_for_selector('[data-role="sr-walkin"]', state="visible", timeout=5000)


def queue_names(page):
    body = page.locator('[data-role="sr-queue-body"]')
    return body.inner_text() or ""


def run_walkin_journey(browser, vp, doctor_key):
    """Phase 11 Slice 4 — walk-in for the selected Clinic patient.

    Tehran (N=2 eligible doctors): explicit choice, no first-row fallback,
    decoy (home-Clinic metadata only) never offered; Tokyo (N=1): shown and
    auto-selected; switching back invalidates the selection; one explicit
    submit reaches waiting through the existing machine with no appointment;
    a resubmission is the bounded duplicate conflict.
    """
    key = f"reception-walkin-{vp['vp']}"
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
        if page.locator('[data-role="sr-walkin"]').is_visible():
            raise RuntimeError("walk-in section must stay hidden until a patient is selected")

        stage = "select-patient"
        mark = nav_mark(page)
        select_walkin_patient(page, vp["vp"])

        stage = "tehran-many"
        wait_walkin_many(page)
        values = walkin_options(page)
        if values[0] != "" or sorted(int(v) for v in values[1:]) != sorted([WALKIN["c1"], WALKIN["c2"]]):
            raise RuntimeError(f"Tehran must offer exactly the two eligible doctors after a blank prompt, got {values}")
        if str(WALKIN["decoy"]) in values:
            raise RuntimeError("home-Clinic-only decoy clinician must never be offered")
        if page.locator('[data-role="sr-walkin-clinician"]').input_value() != "":
            raise RuntimeError("N>1 doctors must not preselect a first-row fallback")
        if not page.locator('[data-role="sr-walkin-submit"]').is_disabled():
            raise RuntimeError("submit must stay disabled until a doctor is chosen")
        if page.locator('[data-role="sr-walkin"] a[href], [data-role="sr-walkin"] [data-role*="appointment"], [data-role="sr-walkin"] [data-role*="invoice"]').count() != 0:
            raise RuntimeError("walk-in section must expose no appointment/finance/navigation controls")
        assert_no_horizontal_overflow(page, "walkin-choose")
        shot(page, f"reception-{vp['vp']}-walkin-choose")

        stage = "tokyo-single"
        select_location(page, PUB["loc_tokyo"])
        wait_rows_count(page, 0)
        wait_walkin_single(page)
        doctor_text = page.locator('[data-role="sr-walkin-doctor"]').inner_text() or ""
        if "Dr Walkin Second" not in doctor_text:
            raise RuntimeError(f"Tokyo must show its single eligible doctor clearly, got {doctor_text[:80]}")
        if page.locator('[data-role="sr-walkin-submit"]').is_disabled():
            raise RuntimeError("single eligible doctor must be auto-selected (submit enabled)")
        assert_no_horizontal_overflow(page, "walkin-single")
        shot(page, f"reception-{vp['vp']}-walkin-single")

        stage = "tehran-reset"
        select_location(page, PUB["loc_tehran"])
        wait_rows_count(page, 4)
        wait_walkin_many(page)
        if page.locator('[data-role="sr-walkin-clinician"]').input_value() != "":
            raise RuntimeError("a Location change must invalidate the doctor selection")
        if not page.locator('[data-role="sr-walkin-submit"]').is_disabled():
            raise RuntimeError("submit must be disabled again after a Location change")

        stage = "submit"
        doctor_id = WALKIN[doctor_key]
        page.select_option('[data-role="sr-walkin-clinician"]', str(doctor_id))
        writes_before = [r for r in state["rest"] if r["method"] != "GET"]
        with page.expect_response(lambda r: r.url and WALKIN_ROUTE in r.url and r.request.method == "POST", timeout=15000) as resp_info:
            page.locator('[data-role="sr-walkin-submit"]').click()
        if resp_info.value.status != 200:
            raise RuntimeError(f"walk-in answered {resp_info.value.status}")
        body = resp_info.value.json()
        data = body.get("data", body) if isinstance(body, dict) else {}
        if (data.get("visit") or {}).get("status") != "waiting" or (data.get("walk_in") or {}).get("complete") is not True:
            raise RuntimeError("walk-in must complete in waiting through the existing enqueue")
        if (data.get("visit") or {}).get("appointment_id") is not None or (data.get("visit") or {}).get("source") != "walk_in":
            raise RuntimeError("walk-in must not create/convert an appointment")
        wait_walkin_state(page, "صف انتظار")
        page.wait_for_function(
            """(name) => { const b = document.querySelector('[data-role="sr-queue-body"]'); return !!b && (b.textContent || '').indexOf(name) !== -1; }""",
            arg={"mobile-390": "Walkin Mobile", "tablet-768": "Walkin Tablet", "desktop-1366": "Walkin Desktop"}[vp["vp"]],
            timeout=15000,
        )
        wait_rows_count(page, 4)
        new_writes = [r for r in state["rest"] if r["method"] != "GET"][len(writes_before):]
        routes = [f"{w['method']} {w['route']}={w['status']}" for w in new_writes]
        if len(new_writes) != 1 or not new_writes[0]["route"].rstrip("/").endswith(WALKIN_ROUTE):
            raise RuntimeError(f"walk-in must be the only write, got {routes}")
        if not page.locator('[data-role="sr-walkin-submit"]').is_disabled():
            raise RuntimeError("a completed walk-in must not stay re-submittable without a change")
        assert_no_horizontal_overflow(page, "walkin-success")
        shot(page, f"reception-{vp['vp']}-walkin-success")

        stage = "duplicate"
        select_location(page, PUB["loc_tokyo"])
        wait_rows_count(page, 0)
        wait_walkin_single(page)
        select_location(page, PUB["loc_tehran"])
        wait_rows_count(page, 4)
        wait_walkin_many(page)
        page.select_option('[data-role="sr-walkin-clinician"]', str(doctor_id))
        with page.expect_response(lambda r: r.url and WALKIN_ROUTE in r.url and r.request.method == "POST", timeout=15000) as dup_info:
            page.locator('[data-role="sr-walkin-submit"]').click()
        if dup_info.value.status != 409:
            raise RuntimeError(f"resubmission must be the bounded duplicate conflict, got {dup_info.value.status}")
        wait_walkin_state(page, "مراجعهٔ فعال")
        dup_text = page.locator('[data-role="sr-walkin-state"]').inner_text() or ""
        if any(tok in dup_text for tok in ("SQL", "visit_id", "cpms_", "Duplicate")):
            raise RuntimeError("duplicate message leaked internals")
        assert_no_horizontal_overflow(page, "walkin-duplicate")
        shot(page, f"reception-{vp['vp']}-walkin-duplicate")

        rest_delta = assert_no_product_reload(page, mark, "walk-in")
        assert_authority_headers(state, PUB["clinic"], {PUB["loc_tehran"], PUB["loc_tokyo"]})
        assert_hygiene(state, key)
        ok(
            key,
            "reception walk-in: 0/1/N doctors, explicit submit reaches waiting, no appointment, bounded duplicate",
            f"vp={vp['vp']} tehran_n=2 tokyo_auto=1 decoy=0 reset=1 waiting=1 appts=4 dup=409 walkin_posts={len(walkin_calls(state))} rest={rest_delta} reloaded=0 overflow=0",
        )
    except Exception as e:
        try:
            dump = {
                "walkin_state": (page.locator('[data-role="sr-walkin-state"]').inner_text() or "")[:120],
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


def run_walkin_partial_journey(browser, vp):
    """Phase 11 Slice 4 — recoverable partial walk-in (TEST-ONLY cookie
    sabotage of the existing enqueue UPDATE): never shown as success, the
    recovery control completes the existing enqueue for the SAME server-derived
    Visit, and a further submit is the bounded duplicate (no second Visit)."""
    from urllib.parse import urlsplit

    key = f"reception-walkin-partial-{vp['vp']}"
    stage = "login"
    ctx, page, state = new_page(browser, vp)
    try:
        login(page, SECRETARY)
        stage = "reception-entry"
        resp = harness_goto(page, RECEPTION_URL, wait_until="domcontentloaded")
        if resp is None or resp.status != 200:
            raise RuntimeError(f"reception entry status {getattr(resp, 'status', None)}")
        page.wait_for_selector('[data-role="reception-app"]', state="attached", timeout=15000)
        page.wait_for_selector('[data-role="sr-location-select"]', state="visible", timeout=15000)
        select_location(page, PUB["loc_tehran"])
        wait_rows_count(page, 4)
        select_walkin_patient(page, "partial")
        wait_walkin_many(page)
        page.select_option('[data-role="sr-walkin-clinician"]', str(WALKIN["c1"]))

        stage = "partial"
        mark = nav_mark(page)
        host = urlsplit(RECEPTION_URL).hostname or ""
        ctx.add_cookies([{"name": "rp_sabotage", "value": "1", "domain": host, "path": "/"}])
        with page.expect_response(lambda r: r.url and WALKIN_ROUTE in r.url and r.request.method == "POST", timeout=15000) as p_info:
            page.locator('[data-role="sr-walkin-submit"]').click()
        ctx.add_cookies([{"name": "rp_sabotage", "value": "0", "domain": host, "path": "/"}])
        if p_info.value.status < 400:
            raise RuntimeError(f"partial walk-in must not answer success, got {p_info.value.status}")
        pbody = p_info.value.json()
        if pbody.get("code") != "CLINIC_WALK_IN_INCOMPLETE" or ((pbody.get("data") or {}).get("visit_status")) != "checked_in":
            raise RuntimeError("partial walk-in must report the durable checked_in state")
        wait_walkin_state(page, "قرارگیری در صف انجام نشد")
        page.wait_for_selector('[data-role="sr-walkin-recover"]', state="visible", timeout=5000)
        if page.locator('[data-role="sr-walkin-submit"]').is_visible():
            raise RuntimeError("partial state must offer recovery, not a fresh submit")
        page.wait_for_timeout(6500)
        if "قرارگیری در صف انجام نشد" not in (page.locator('[data-role="sr-walkin-state"]').inner_text() or ""):
            raise RuntimeError("silent refresh erased the partial walk-in message")
        assert_no_horizontal_overflow(page, "walkin-partial")
        shot(page, f"reception-{vp['vp']}-walkin-partial")

        stage = "recover"
        with page.expect_response(lambda r: r.url and WALKIN_ROUTE in r.url and r.request.method == "POST", timeout=15000) as r_info:
            page.locator('[data-role="sr-walkin-recover"]').click()
        if r_info.value.status != 200:
            raise RuntimeError(f"recovery must succeed in one retry, got {r_info.value.status}")
        rdata = (r_info.value.json() or {}).get("data") or {}
        if (rdata.get("walk_in") or {}).get("created") != "existing" or (rdata.get("visit") or {}).get("status") != "waiting":
            raise RuntimeError("recovery must enqueue the existing server-derived walk-in Visit")
        wait_walkin_state(page, "صف انتظار")
        page.wait_for_function(
            """() => { const b = document.querySelector('[data-role="sr-queue-body"]'); return !!b && (b.textContent || '').indexOf('Walkin Partial') !== -1; }""",
            timeout=15000,
        )
        if page.locator('[data-role="sr-walkin-recover"]').is_visible():
            raise RuntimeError("recovery control must disappear after completion")
        assert_no_horizontal_overflow(page, "walkin-recovered")
        shot(page, f"reception-{vp['vp']}-walkin-recovered")

        rest_delta = assert_no_product_reload(page, mark, "walk-in recovery")
        assert_authority_headers(state, PUB["clinic"], {PUB["loc_tehran"], PUB["loc_tokyo"]})
        assert_hygiene(state, key)
        ok(
            key,
            "partial walk-in stays checked_in (not success) and one recovery completes the existing enqueue",
            f"vp={vp['vp']} partial_http={p_info.value.status} sticky=1 recover_http=200 created=existing rest={rest_delta} reloaded=0",
        )
    except Exception as e:
        try:
            dump = {
                "walkin_state": (page.locator('[data-role="sr-walkin-state"]').inner_text() or "")[:120],
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


def wait_book_state(page, needle, timeout=15000):
    page.wait_for_function(
        """(needle) => { const n = document.querySelector('[data-role="sr-book-state"]'); return !!n && (n.textContent || '').indexOf(needle) !== -1; }""",
        arg=needle,
        timeout=timeout,
    )


def book_slot_ids(page):
    nodes = page.locator('[data-role="sr-book-slot"]')
    return [int(nodes.nth(i).get_attribute("data-slot-id") or 0) for i in range(nodes.count())]


def wait_book_slot(page, slot_id, timeout=15000):
    page.wait_for_function(
        """(id) => Array.from(document.querySelectorAll('[data-role="sr-book-slot"]')).some((n) => n.getAttribute('data-slot-id') === String(id))""",
        arg=int(slot_id),
        timeout=timeout,
    )


# The reception slot read is exactly this namespace-prefixed path; anything else
# is a different operation and must never satisfy a slot-read measurement.
SLOT_READ_PATH = "/clinic/v1" + SLOTS_ROUTE
SLOT_READ_SETTLE_TIMEOUT_MS = 15000


def slot_read_requests(state, since=0):
    """Slot reads INITIATED by the browser, from an initiated-request index.

    Requests are used (not responses) so the measurement is bound to the action
    under test: a stale response arriving later can never be counted, and a
    genuine duplicate GET is never missed.
    """
    return [r for r in state["reqs"][since:] if r["route"].rstrip("/") == SLOT_READ_PATH]


def slot_read_responses(state, since=0):
    return [r for r in state["rest"][since:] if r["route"].rstrip("/") == SLOT_READ_PATH]


def wait_slot_reads_settled(page, state, timeout_ms=SLOT_READ_SETTLE_TIMEOUT_MS):
    """Deterministic baseline barrier: every slot read initiated so far has its
    response recorded, i.e. nothing relevant is still in flight.

    Condition-based and bounded — the poll interval is not a timing assumption.
    Captured history is never cleared, so a real duplicate read still shows up.
    """
    deadline = time.monotonic() + (timeout_ms / 1000.0)
    while True:
        pending = len(slot_read_requests(state)) - len(slot_read_responses(state))
        if pending <= 0:
            return
        if time.monotonic() >= deadline:
            raise RuntimeError(f"slot reads did not settle within {timeout_ms}ms ({pending} still in flight)")
        page.wait_for_timeout(25)


def selected_date_value(page):
    if page is None:
        return ""
    node = page.locator('[data-role="sr-book-date"]')
    return str(node.input_value() or "") if node.count() else ""


def assert_bounded_slot_read(read, location_id, expected_clinician=None, expected_date=None, page=None):
    """One slot read must be a bounded GET of the reception slots endpoint, bound
    to the trusted operational Location and — where the request exposes them — to
    the explicitly selected clinician and date. Nothing client-invented passes."""
    if read["method"] != "GET":
        raise RuntimeError(f"slot reads must be bounded GETs, got {read['method']} {read['route']}")
    if str(read["headers"].get("x-cpms-location-id", "")) != str(location_id):
        raise RuntimeError(
            "the slot read must be bound to the trusted operational Location, got "
            f"{read['headers'].get('x-cpms-location-id')}"
        )
    query = read.get("query") or {}
    if expected_clinician is not None and str(query.get("clinician_id", "")) != str(expected_clinician):
        raise RuntimeError(f"the slot read must be bound to the explicitly selected clinician, got {query}")
    if "date" in query:
        reference = str(expected_date) if expected_date is not None else selected_date_value(page)
        if str(query["date"]) != reference:
            raise RuntimeError(f"the slot read date must match the selected date, got {query['date']} vs {reference}")


def select_booking_patient(page, tag):
    search_input = page.locator('[data-role="sr-search-input"]')
    search_input.fill("")
    search_input.type(BOOKING["mrn"][tag], delay=10)
    wait_search_state(page, "یافت شد")
    pid = BOOKING["patients"][tag]
    if pid not in search_result_ids(page):
        raise RuntimeError(f"booking patient {pid} must be found through the existing Clinic search")
    page.locator(f'[data-role="sr-search-result"][data-patient-id="{pid}"]').click()
    page.wait_for_selector('[data-role="sr-search-selected"]', state="visible", timeout=5000)
    page.wait_for_selector('[data-role="sr-book"]', state="visible", timeout=5000)


def run_booking_journey(browser, vp):
    """Slice 5: selected patient → Location → eligible doctors → persisted slots → confirmed appointment.

    Verifies free/full/closed slot filtering, 0/1/N doctor selection, bounded
    slot reads, Location invalidation, explicit slot_id POST only, honest stale
    full handling, duplicate prevention, same-day board visibility, future
    appointment exclusion from today's board, and absence of Visit/queue side
    effects. Each viewport uses a distinct patient so journeys are independent.
    """
    global BOOKING_BOARD_ROWS
    board_rows = BOOKING_BOARD_ROWS
    selected_slot_id = BOOKING["slot_by_vp"][vp["vp"]]
    key = f"reception-booking-{vp['vp']}"
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
        wait_rows_count(page, board_rows)
        queue_rows_before = page.locator('[data-role="sr-queue-row"]').count()
        if page.locator('[data-role="sr-book"]').is_visible():
            raise RuntimeError("booking section must stay hidden until a patient is selected")

        stage = "select-patient"
        mark = nav_mark(page)
        select_booking_patient(page, vp["vp"])
        page.wait_for_selector('[data-role="sr-book-clinician-wrap"]', state="visible", timeout=15000)

        stage = "tehran-doctors-and-free-slots"
        clinician_select = page.locator('[data-role="sr-book-clinician"]')
        page.wait_for_function(
            """() => { const s = document.querySelector('[data-role="sr-book-clinician"]'); return !!s && s.options.length > 1; }""",
            timeout=15000,
        )
        cvalues = [clinician_select.locator("option").nth(i).get_attribute("value") or "" for i in range(clinician_select.locator("option").count())]
        if cvalues[0] != "" or sorted(int(v) for v in cvalues[1:]) != sorted([BOOKING["c1"], BOOKING["c2"]]):
            raise RuntimeError(f"Tehran must offer exactly eligible doctors and no first-row fallback, got {cvalues}")
        if not page.locator('[data-role="sr-book-submit"]').is_disabled():
            raise RuntimeError("booking submit must be disabled until explicit doctor and slot selections")
        if page.locator('[data-role="sr-book"] input[type="time"]').count() != 0:
            raise RuntimeError("booking must not expose free-form time authority")
        if page.locator('[data-role="sr-book"] a[href], [data-role="sr-book"] [data-role*="invoice"]').count() != 0:
            raise RuntimeError("booking section must expose no navigation/finance actions")

        # Select a real doctor and slot first so the Location switch must
        # actively invalidate a previously valid booking context.
        stage = "location-invalidation-prepare"
        page.select_option('[data-role="sr-book-clinician"]', str(BOOKING["c1"]))
        wait_book_slot(page, selected_slot_id)
        page.locator(f'[data-role="sr-book-slot"][data-slot-id="{selected_slot_id}"]').click()
        if page.locator('[data-role="sr-book-submit"]').is_disabled():
            raise RuntimeError("a concrete selected slot must enable submission before Location invalidation")

        # Location switching clears all previously selected booking context.
        stage = "location-invalidation"
        select_location(page, PUB["loc_tokyo"])
        wait_rows_count(page, 0)
        page.wait_for_function(
            """() => { const d = document.querySelector('[data-role="sr-book-doctor"]'); return !!d && !d.hidden; }""",
            timeout=15000,
        )
        if "Dr Walkin Second" not in (page.locator('[data-role="sr-book-doctor"]').inner_text() or ""):
            raise RuntimeError("Tokyo's sole Location-eligible clinician must be auto-selected clearly")
        if page.locator('[data-role="sr-book-submit"]').is_enabled() or page.locator('[data-role="sr-book-slot"][aria-pressed="true"]').count() != 0:
            raise RuntimeError("Location switch must invalidate the selected slot and disable submission")
        select_location(page, PUB["loc_tehran"])
        wait_rows_count(page, board_rows)
        page.wait_for_selector('[data-role="sr-book-clinician-wrap"]', state="visible", timeout=15000)
        if clinician_select.input_value() != "":
            raise RuntimeError("switching back to Tehran must clear the previous clinician")

        stage = "select-doctor-read-bounded-slots"
        # Deterministic baseline for the measured action:
        #  (a) every slot read initiated so far has SETTLED — a stale/in-flight
        #      response can therefore no longer land inside the measured window;
        #  (b) the initiated-request index is snapshotted, so the measurement is
        #      what THIS action initiates, never responses that merely arrive.
        wait_slot_reads_settled(page, state)
        requests_before = len(state["reqs"])
        page.select_option('[data-role="sr-book-clinician"]', str(BOOKING["c1"]))
        wait_book_slot(page, selected_slot_id)
        offered = book_slot_ids(page)
        if BOOKING["slots"]["full"] in offered or BOOKING["slots"]["closed"] in offered or BOOKING["slots"]["future"] in offered:
            raise RuntimeError(f"only persisted open slots for the selected day may be offered, got {offered}")
        # The fixture's free slots may be filtered if they became past while
        # earlier independent journeys ran; fail honestly with the bounded read.
        if any(BOOKING["slots"][key] not in offered for key in ("free_a", "free_b", "free_c")):
            raise RuntimeError(f"both seeded future-today FREE slots must be offered, got {offered}")
        wait_slot_reads_settled(page, state)
        initiated = slot_read_requests(state, requests_before)
        if len(initiated) != 1:
            raise RuntimeError(
                "one doctor selection must initiate exactly one bounded slot read, got "
                + str([(r["method"], r["route"]) for r in initiated])
            )
        assert_bounded_slot_read(initiated[0], PUB["loc_tehran"], expected_clinician=BOOKING["c1"])
        # No slot read anywhere in this journey may be anything but a bounded GET.
        if any(r["method"] != "GET" for r in slot_read_requests(state)):
            raise RuntimeError("slot reads must be bounded GETs; no per-slot writes/reads")
        assert_no_horizontal_overflow(page, "booking-free-slots")
        shot(page, f"reception-{vp['vp']}-booking-free-slots")

        stage = "same-day-create"
        patient_id = BOOKING["patients"][vp["vp"]]
        patient_before = [r for r in state["rest"] if r["method"] != "GET"]
        page.locator(f'[data-role="sr-book-slot"][data-slot-id="{selected_slot_id}"]').click()
        if page.locator('[data-role="sr-book-submit"]').is_disabled():
            raise RuntimeError("explicit patient + clinician + slot must enable booking")
        # One additional patient-selected write is not expected: only appointment create may write.
        with page.expect_response(lambda r: r.url and APPOINTMENTS_ROUTE in r.url and r.request.method == "POST", timeout=15000) as create_info:
            page.locator('[data-role="sr-book-submit"]').click()
        create_resp = create_info.value
        if create_resp.status != 200:
            raise RuntimeError(f"appointment create answered {create_resp.status}")
        create_json = create_resp.json()
        create_data = create_json.get("data", create_json) if isinstance(create_json, dict) else {}
        appointment = create_data.get("appointment") or {}
        if appointment.get("status") != "confirmed" or not appointment.get("id") or not appointment.get("reference_code"):
            raise RuntimeError(f"appointment must be one confirmed row with the established view, got {appointment}")
        if appointment.get("date") != PUB["today_tehran"] or not appointment.get("time"):
            raise RuntimeError(f"same-day appointment must use the selected persisted slot's operational date/time, got {appointment}")
        post_body = create_resp.request.post_data_json
        if not isinstance(post_body, dict) or int(post_body.get("patient_id") or 0) != patient_id or int(post_body.get("clinician_id") or 0) != BOOKING["c1"] or int(post_body.get("slot_id") or 0) != selected_slot_id:
            raise RuntimeError(f"create request must carry selected patient, eligible doctor and persisted slot_id, got {post_body}")
        if "date" in post_body or "time" in post_body or "slot_date" in post_body or "slot_time" in post_body:
            raise RuntimeError("free-form date/time must never be sent as booking authority")
        if create_data.get("reception", {}).get("on_operational_day") is not True:
            raise RuntimeError("same-day appointment must be marked on the operational day")
        BOOKING_BOARD_ROWS = board_rows + 1
        wait_rows_count(page, BOOKING_BOARD_ROWS)
        board_row = page.locator(f'[data-role="sr-row"][data-appointment-id="{appointment.get("id")}"]')
        if board_row.count() != 1 or "Booking " not in (board_row.inner_text() or ""):
            raise RuntimeError("same-day created appointment must appear on the reception day board")
        wait_book_state(page, "نوبت ثبت شد")
        if page.locator('[data-role="sr-queue-row"]').count() != queue_rows_before:
            raise RuntimeError("appointment creation must not create or alter an existing queue/Visit")
        create_writes = [r for r in state["rest"] if r["method"] != "GET"][len(patient_before):]
        if len(create_writes) != 1 or not create_writes[0]["route"].rstrip("/").endswith(APPOINTMENTS_ROUTE):
            raise RuntimeError(f"booking journey must issue exactly one write, got {create_writes}")
        request = [r for r in state["reqs"] if r["route"].rstrip("/").endswith(APPOINTMENTS_ROUTE) and r["method"] == "POST"][-1]
        # The request-body assertion above proves that slot_id is the only
        # date/time authority carried by the browser.
        assert_no_horizontal_overflow(page, "booking-success")
        shot(page, f"reception-{vp['vp']}-booking-success")

        stage = "duplicate"
        if not page.locator('[data-role="sr-book-submit"]').is_disabled():
            raise RuntimeError("after successful create, the UI must disable submit until a new slot is explicitly selected")
        # Re-select the same concrete slot while it still has one unit of
        # capacity. Duplicate for this same patient must be a bounded conflict,
        # not a second appointment.
        wait_book_slot(page, selected_slot_id)
        page.locator(f'[data-role="sr-book-slot"][data-slot-id="{selected_slot_id}"]').click()
        with page.expect_response(lambda r: r.url and APPOINTMENTS_ROUTE in r.url and r.request.method == "POST", timeout=15000) as duplicate_info:
            page.locator('[data-role="sr-book-submit"]').click()
        if duplicate_info.value.status != 409 or duplicate_info.value.json().get("code") != "CLINIC_DUPLICATE_APPOINTMENT":
            raise RuntimeError(f"same-patient same-slot duplicate must be bounded 409, got {duplicate_info.value.status}")
        wait_book_state(page, "نوبت تکراری ثبت نشد")
        # The UI performs one bounded availability refresh after the explicit
        # duplicate response; finish it before measuring the next date change.
        wait_book_slot(page, selected_slot_id)
        if page.locator('[data-role="sr-row"][data-appointment-id="' + str(appointment.get("id")) + '"]').count() != 1:
            raise RuntimeError("duplicate submission must not create a second board appointment")

        stage = "future-date"
        # Choose the Jalali option by its ISO value; the visible copy remains
        # the server-provided Jalali label. Each date selection initiates one
        # bounded slot read, measured on INITIATED requests from a settled
        # baseline — the same deterministic form as the doctor-selection
        # measurement, never responses that happen to arrive afterwards.
        wait_slot_reads_settled(page, state)
        requests_before = len(state["reqs"])
        page.select_option('[data-role="sr-book-date"]', BOOKING["tomorrow"])
        wait_book_slot(page, BOOKING["slots"]["future"])
        wait_slot_reads_settled(page, state)
        date_reads = slot_read_requests(state, requests_before)
        if len(date_reads) != 1:
            raise RuntimeError(
                "one date selection must initiate exactly one bounded slot read, got "
                + str([(r["method"], r["route"]) for r in date_reads])
            )
        assert_bounded_slot_read(
            date_reads[0],
            PUB["loc_tehran"],
            expected_clinician=BOOKING["c1"],
            expected_date=BOOKING["tomorrow"],
            page=page,
        )
        if BOOKING["slots"]["full"] in book_slot_ids(page) or BOOKING["slots"]["closed"] in book_slot_ids(page):
            raise RuntimeError("full/closed slots must never be offered")
        page.locator(f'[data-role="sr-book-slot"][data-slot-id="{BOOKING["slots"]["future"]}"]').click()
        with page.expect_response(lambda r: r.url and APPOINTMENTS_ROUTE in r.url and r.request.method == "POST", timeout=15000) as future_info:
            page.locator('[data-role="sr-book-submit"]').click()
        if future_info.value.status != 200:
            raise RuntimeError(f"future appointment create answered {future_info.value.status}")
        future_json = future_info.value.json()
        future_data = future_json.get("data", future_json) if isinstance(future_json, dict) else {}
        future_appt = future_data.get("appointment") or {}
        if future_appt.get("status") != "confirmed" or str(future_appt.get("date") or "") != BOOKING["tomorrow"]:
            raise RuntimeError("future booking must remain confirmed on its future operational date")
        if future_data.get("reception", {}).get("on_operational_day") is not False:
            raise RuntimeError("future appointment must be marked off today's operational board")
        wait_rows_count(page, BOOKING_BOARD_ROWS)
        if page.locator(f'[data-role="sr-row"][data-appointment-id="{future_appt.get("id")}"]').count() != 0:
            raise RuntimeError("future appointment must not appear on today's reception board")
        if page.locator('[data-role="sr-queue-row"]').count() != queue_rows_before:
            raise RuntimeError("future appointment must not create or alter an existing Visit/queue row")
        assert_no_horizontal_overflow(page, "booking-future")
        shot(page, f"reception-{vp['vp']}-booking-future")

        # Slice 8: the future booking is discoverable without wp-admin, in the
        # current Location only; cancellation reuses Slice 6's existing route.
        stage = "upcoming-discovery-and-cancel"
        future_id = str(future_appt["id"])
        future_row = page.locator(f'[data-role="sr-upcoming-row"][data-appointment-id="{future_id}"]')
        future_row.wait_for(state="visible", timeout=15000)
        future_text = future_row.inner_text()
        if not future_appt.get("jalali") or str(future_appt["jalali"]) not in future_text or str(future_appt["time"]) not in future_text or "پزشک:" not in future_text or "Booking " not in future_text:
            raise RuntimeError("upcoming row must show patient, doctor, Jalali date and time")
        if page.locator(f'[data-role="sr-row"][data-appointment-id="{future_id}"]').count():
            raise RuntimeError("future row duplicated on today's board")
        assert_no_horizontal_overflow(page, "upcoming-discovery")
        shot(page, f"reception-{vp['vp']}-upcoming-discovery")
        future_row.locator('[data-role="sr-cancel-open"]').click()
        with page.expect_response(lambda r: r.request.method == "POST" and route_of(r.url).rstrip("/").endswith(f"/appointments/{future_id}/cancel"), timeout=15000) as cancel_info:
            future_row.locator('[data-role="sr-cancel-confirm"]').click()
        if cancel_info.value.status != 200:
            raise RuntimeError(f"upcoming cancel returned {cancel_info.value.status}")
        future_row.wait_for(state="detached", timeout=15000)
        if page.locator(f'[data-role="sr-row"][data-appointment-id="{future_id}"]').count():
            raise RuntimeError("cancelled future row cannot appear on today's board")
        assert_no_horizontal_overflow(page, "upcoming-cancel")
        shot(page, f"reception-{vp['vp']}-upcoming-cancel")

        assert_no_product_reload(page, mark, "appointment booking")
        assert_authority_headers(state, PUB["clinic"], {PUB["loc_tehran"], PUB["loc_tokyo"]})
        assert_hygiene(state, key)
        ok(
            key,
            "reception booking: explicit persisted slot → confirmed appointment; no Visit/queue/payment; future stays future",
            f"vp={vp['vp']} free_full_closed=1 location_reset=1 same_day_board=1 future_board=0 duplicate=409 board_rows={BOOKING_BOARD_ROWS} queue_rows={queue_rows_before} slots_reads={len([r for r in state['rest'] if r['route'].rstrip('/').endswith(SLOTS_ROUTE)])} create_posts={len([r for r in state['rest'] if r['route'].rstrip('/').endswith(APPOINTMENTS_ROUTE) and r['method']=='POST'])} reloaded=0 overflow=0",
        )
    except Exception as e:
        try:
            dump = {
                "booking_state": (page.locator('[data-role="sr-book-state"]').inner_text() or "")[:160],
                "slot_ids": book_slot_ids(page),
                "rest_tail": [f"{r['method']} {r['route']}={r['status']}" for r in state["rest"][-12:]],
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


def cancel_posts(state):
    return [
        r
        for r in state["rest"]
        if r["method"] == "POST" and CANCEL_PATH_RE.search(r["route"]) is not None
    ]


def wait_slot_absent(page, slot_id, timeout=15000):
    page.wait_for_function(
        """(id) => !Array.from(document.querySelectorAll('[data-role="sr-book-slot"]')).some((n) => n.getAttribute('data-slot-id') === String(id))""",
        arg=int(slot_id),
        timeout=timeout,
    )


def select_cancel_patient(page, tag):
    search_input = page.locator('[data-role="sr-search-input"]')
    search_input.fill("")
    search_input.type(CANCEL["mrn"][tag], delay=10)
    wait_search_state(page, "یافت شد")
    pid = CANCEL["patients"][tag]
    if pid not in search_result_ids(page):
        raise RuntimeError(f"cancel patient {pid} must be found through the existing Clinic search")
    page.locator(f'[data-role="sr-search-result"][data-patient-id="{pid}"]').click()
    page.wait_for_selector('[data-role="sr-search-selected"]', state="visible", timeout=5000)
    page.wait_for_selector('[data-role="sr-book"]', state="visible", timeout=5000)


def run_cancel_journey(browser, vp):
    """Slice 6: cancel a booked appointment at the trusted Location.

    The journey books its own dedicated capacity-1 slot through the Slice 5 UI
    (so the cancellation has a real, fully-claimed slot to release), then
    cancels that row through the new Reception boundary: explicit action,
    confirmation + OPTIONAL reason (empty on mobile), double-submit safety, one
    explicit mutation, the row leaving the actionable board, the existing
    patient selection preserved, no Visit/queue side effect, and the freed slot
    offered again by the EXISTING bounded slot read.
    """
    tag = vp["vp"]
    slot_id = CANCEL["slots"][tag]
    patient_id = CANCEL["patients"][tag]
    reason = "" if tag == "mobile-390" else f"لغو پذیرش {tag}"
    key = f"reception-cancel-{tag}"
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
        page.wait_for_selector('[data-role="sr-status"]', state="attached", timeout=15000)
        page.wait_for_function(
            """() => document.querySelectorAll('[data-role="sr-row"]').length > 0""",
            timeout=15000,
        )
        rows_before = rows(page).count()
        queue_before = page.locator('[data-role="sr-queue-row"]').count()
        if rows_before < 1 or queue_before < 1:
            raise RuntimeError(f"cancel journey expects booked rows and received queue rows, got {rows_before}/{queue_before}")

        # Booked-not-received rows are the ONLY rows offered cancellation: a
        # received/queue row (and a checked_in recovery row, when present) must
        # carry no cancel control — the backend stays authoritative for every
        # refusal, the board only presents the objectively appropriate action.
        queue_rows = page.locator('[data-role="sr-queue-row"]')
        for i in range(queue_rows.count()):
            if queue_rows.nth(i).locator('[data-role="sr-cancel-open"]').count() != 0:
                raise RuntimeError("queue rows must never expose a cancel action")
        recover = page.locator('[data-role="sr-row"]:has([data-role="sr-recover"])')
        for i in range(recover.count()):
            if recover.nth(i).locator('[data-role="sr-cancel-open"]').count() != 0:
                raise RuntimeError("a checked_in recovery row must not expose a cancel action")

        stage = "book-owned-slot"
        mark = nav_mark(page)
        select_cancel_patient(page, tag)
        page.wait_for_selector('[data-role="sr-book-clinician-wrap"]', state="visible", timeout=15000)
        page.select_option('[data-role="sr-book-clinician"]', str(CANCEL["c1"]))
        # BEFORE the appointment exists the dedicated capacity-1 slot is free…
        wait_book_slot(page, slot_id)
        page.locator(f'[data-role="sr-book-slot"][data-slot-id="{slot_id}"]').click()
        with page.expect_response(lambda r: r.url and APPOINTMENTS_ROUTE in r.url and r.request.method == "POST", timeout=15000) as create_info:
            page.locator('[data-role="sr-book-submit"]').click()
        if create_info.value.status != 200:
            raise RuntimeError(f"pre-cancel booking answered {create_info.value.status}")
        create_data = create_info.value.json()
        create_data = create_data.get("data", create_data) if isinstance(create_data, dict) else {}
        appointment = create_data.get("appointment") or {}
        appt_id = int(appointment.get("id") or 0)
        if appointment.get("status") != "confirmed" or appt_id <= 0:
            raise RuntimeError(f"cancel journey needs one confirmed booked row, got {appointment}")
        # …fully claimed once the journey's own appointment holds it…
        wait_slot_absent(page, slot_id)
        wait_rows_count(page, rows_before + 1)
        row = row_of(page, appt_id)
        if row.count() != 1:
            raise RuntimeError("the freshly booked row must appear on today's actionable board")
        if row.locator('[data-role="sr-cancel-open"]').count() != 1:
            raise RuntimeError("a booked-not-received row must expose exactly one explicit cancel action")
        if row.locator('[data-role="sr-cancel-form"]').count() != 0:
            raise RuntimeError("the confirmation/reason surface must stay closed until the cancel action is chosen")

        stage = "open-confirmation"
        page.locator(f'[data-role="sr-cancel-open"][data-appointment-id="{appt_id}"]').click()
        form = page.locator(f'[data-role="sr-cancel-form"][data-appointment-id="{appt_id}"]')
        if not form.is_visible():
            raise RuntimeError("choosing Cancel must open the compact confirmation surface in place")
        reason_input = page.locator(f'[data-role="sr-cancel-form"][data-appointment-id="{appt_id}"] [data-role="sr-cancel-reason"]')
        if not reason_input.is_visible():
            raise RuntimeError("the optional reason field must be visible")
        if reason == "" and reason_input.input_value() != "":
            raise RuntimeError("the optional reason must start empty")
        if reason != "":
            reason_input.fill(reason)
        assert_no_horizontal_overflow(page, "cancel-confirm")
        shot(page, f"reception-{tag}-cancel-confirm")

        stage = "cancel-booked-row"
        # The unrelated patient selection is captured BEFORE the mutation so the
        # journey can prove the cancellation neither re-creates nor clears it.
        # The panel carries the patient label/meta (never a numeric id), so the
        # id-anchored proof is the pressed search-result row below.
        selected_box = page.locator('[data-role="sr-search-selected"]')
        selected_before = (selected_box.inner_text() or "").strip()
        if not selected_box.is_visible() or not selected_before:
            raise RuntimeError("the cancel journey requires the selected-patient panel before cancelling")
        # Double-submit safety: two synchronous activations must still produce
        # exactly ONE explicit mutation.
        with page.expect_response(
            lambda r: r.url and (APPOINTMENTS_ROUTE + "/" + str(appt_id) + "/cancel") in r.url and r.request.method == "POST",
            timeout=15000,
        ) as cancel_info:
            page.evaluate(
                """(id) => { const b = document.querySelector('[data-role="sr-cancel-confirm"][data-appointment-id="' + id + '"]'); b.click(); b.click(); }""",
                appt_id,
            )
        page.wait_for_function(
            """(id) => { const r = document.querySelector('[data-role="sr-row"][data-appointment-id="' + id + '"]'); return !r; }""",
            arg=appt_id,
            timeout=15000,
        )
        posts = cancel_posts(state)
        if len(posts) != 1 or posts[0]["status"] != 200:
            raise RuntimeError(f"one explicit cancel mutation expected, got {posts}")
        if cancel_info.value.status != 200:
            raise RuntimeError(f"reception cancel answered {cancel_info.value.status}")
        cancel_json = cancel_info.value.json()
        cancel_data = cancel_json.get("data", cancel_json) if isinstance(cancel_json, dict) else {}
        cancelled = cancel_data.get("appointment") or {}
        if sorted(cancel_data.keys()) != ["appointment", "reception"]:
            raise RuntimeError(f"cancel response must stay bounded, got {sorted(cancel_data.keys())}")
        if sorted(cancelled.keys()) != ["appointment_id", "status"] or int(cancelled.get("appointment_id") or 0) != appt_id:
            raise RuntimeError(f"cancelled appointment view must carry only id+status, got {cancelled}")
        if cancelled.get("status") != "cancelled_by_staff":
            raise RuntimeError(f"the existing staff-cancel terminal state is expected, got {cancelled.get('status')}")
        scope = cancel_data.get("reception") or {}
        if int(scope.get("clinic_id") or 0) != PUB["clinic"] or int(scope.get("location_id") or 0) != PUB["loc_tehran"]:
            raise RuntimeError(f"cancel response must echo the trusted server scope, got {scope}")
        body = cancel_info.value.request.post_data_json
        if not isinstance(body, dict):
            raise RuntimeError("cancel request body unavailable for the optional-reason proof")
        if str(body.get("reason") or "") != reason:
            raise RuntimeError(f"the optional reason must be sent exactly as chosen, got {body}")
        if "clinic_id" in body or "location_id" in body:
            raise RuntimeError("raw clinic/location selectors must never be sent as authority")
        wait_status_contains(page, "لغو شد")
        wait_rows_count(page, rows_before)
        if row_of(page, appt_id).count() != 0:
            raise RuntimeError("the cancelled row must leave the actionable board")
        # The cancellation is not an arrival: no queue/Visit row may appear and
        # the unreceived state must not be re-created.
        if page.locator('[data-role="sr-queue-row"]').count() != queue_before:
            raise RuntimeError("cancellation must not create or alter a queue/Visit row")
        # The unrelated patient selection survives the cancellation untouched:
        # same panel text, and the SAME id-anchored search result stays pressed.
        if not selected_box.is_visible() or (selected_box.inner_text() or "").strip() != selected_before:
            raise RuntimeError("cancellation must not create/clear the unrelated patient selection")
        if page.locator(f'[data-role="sr-search-result"][data-patient-id="{patient_id}"][aria-pressed="true"]').count() != 1:
            raise RuntimeError("the explicitly selected patient must stay the selected search result after cancellation")
        assert_no_horizontal_overflow(page, "cancel-success")
        shot(page, f"reception-{tag}-cancel-success")

        stage = "slot-released-through-existing-read"
        # Only the EXISTING bounded slot read can show the release: change the
        # date away and back, then the freed capacity-1 slot must be offered.
        page.select_option('[data-role="sr-book-date"]', BOOKING["tomorrow"])
        wait_book_slot(page, BOOKING["slots"]["future"])
        wait_slot_absent(page, slot_id)
        page.select_option('[data-role="sr-book-date"]', PUB["today_tehran"])
        wait_book_slot(page, slot_id)
        if slot_id not in book_slot_ids(page):
            raise RuntimeError("the released slot must be offered again by the existing slot read")
        if page.locator('[data-role="sr-queue-row"]').count() != queue_before:
            raise RuntimeError("the released slot must not create a Visit/queue row")
        assert_no_horizontal_overflow(page, "cancel-slot-released")

        stage = "writes-bounded"
        writes = [r for r in state["rest"] if r["method"] != "GET"]
        cancels = [r for r in writes if r["route"].endswith("/cancel")]
        creates = [r for r in writes if r["route"].rstrip("/").endswith(APPOINTMENTS_ROUTE)]
        if len(cancels) != 1 or len(creates) != 1 or len(writes) != 2:
            raise RuntimeError(f"cancel journey must issue exactly one create and one cancel write, got {writes}")

        rest_delta = assert_no_product_reload(page, mark, "cancel")
        assert_authority_headers(state, PUB["clinic"], {PUB["loc_tehran"], PUB["loc_tokyo"]})
        assert_hygiene(state, key)
        ok(
            key,
            "reception cancel: explicit action + optional reason → booked row leaves the board, slot released, no Visit/queue",
            f"vp={vp['vp']} reason={'empty' if reason == '' else 'supplied'} create_posts=1 cancel_posts=1 board_rows={rows_before}->{rows_before} queue_rows={queue_before} slot_reoffered=1 selection_kept=1 rest={rest_delta} reloaded=0 overflow=0",
        )
    except Exception as e:
        try:
            dump = {
                "status": status_text(page)[:160],
                "cancel_rows": page.locator('[data-role="sr-row-cancel"]').count(),
                "selected_visible": page.locator('[data-role="sr-search-selected"]').is_visible(),
                "selected_text": (page.locator('[data-role="sr-search-selected"]').inner_text() or "").strip()[:80],
                "pressed_results": page.locator('[data-role="sr-search-result"][aria-pressed="true"]').count(),
                "rest_tail": [f"{r['method']} {r['route']}={r['status']}" for r in state["rest"][-12:]],
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


def reschedule_posts(state):
    return [
        r
        for r in state["rest"]
        if r["method"] == "POST" and RESCHEDULE_PATH_RE.search(r["route"]) is not None
    ]


def reschedule_slot_ids(page):
    nodes = page.locator('[data-role="sr-reschedule-slot"]')
    return [int(nodes.nth(i).get_attribute("data-slot-id") or 0) for i in range(nodes.count())]


def wait_reschedule_slot(page, slot_id, timeout=15000):
    page.wait_for_function(
        """(id) => Array.from(document.querySelectorAll('[data-role="sr-reschedule-slot"]')).some((n) => n.getAttribute('data-slot-id') === String(id))""",
        arg=int(slot_id),
        timeout=timeout,
    )


def wait_reschedule_state(page, needle, timeout=15000):
    page.wait_for_function(
        """(needle) => { const n = document.querySelector('[data-role="sr-reschedule-state"]'); return !!n && (n.textContent || '').indexOf(needle) !== -1; }""",
        arg=needle,
        timeout=timeout,
    )


def select_reschedule_patient(page, tag):
    search_input = page.locator('[data-role="sr-search-input"]')
    search_input.fill("")
    search_input.type(RESCHEDULE["mrn"][tag], delay=10)
    wait_search_state(page, "یافت شد")
    pid = RESCHEDULE["patients"][tag]
    if pid not in search_result_ids(page):
        raise RuntimeError(f"reschedule patient {pid} must be found through the existing Clinic search")
    page.locator(f'[data-role="sr-search-result"][data-patient-id="{pid}"]').click()
    page.wait_for_selector('[data-role="sr-search-selected"]', state="visible", timeout=5000)
    page.wait_for_selector('[data-role="sr-book"]', state="visible", timeout=5000)


def run_reschedule_journey(browser, vp):
    """Slice 7: reschedule a booked appointment WITHIN the CURRENT trusted Location.

    The journey books its own capacity-1 source slot through the existing Slice 5
    UI (so the source row is genuinely confirmed, on today's board and
    not-yet-received), then reschedules it through the new Reception boundary:
    explicit action on the row, current context, destination doctor of the SAME
    Location, a Location-local date, an ALREADY-GENERATED persisted slot of that
    same Location, explicit confirm with the EXISTING Idempotency-Key contract
    (double-click safe), the old row leaving the board, the same-day new row
    appearing through the existing board read (or an off-operational-day
    destination staying OFF today's board), the destination slot claimed and the
    released source slot offered again by the EXISTING bounded read — with no
    Visit/queue/payment and Cancel still a separate distinct action.
    """
    tag = vp["vp"]
    source_slot = RESCHEDULE["source"][tag]
    dest_slot = RESCHEDULE["dest"][tag]
    dest_date = RESCHEDULE["dest_date"][tag]
    dest_doctor = RESCHEDULE_DEST_DOCTOR[tag]
    patient_id = RESCHEDULE["patients"][tag]
    same_day = dest_date == PUB["today_tehran"]
    # The destination candidates are always the EXISTING bounded read of the
    # CURRENT trusted Location; the journey captures exactly that response.
    slot_read_filter = lambda r: r.request.method == "GET" and route_of(r.url) == "/clinic/v1" + SLOTS_ROUTE
    dest_read_value = None
    key = f"reception-reschedule-{tag}"
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
        page.wait_for_function(
            """() => document.querySelectorAll('[data-role="sr-row"]').length > 0""",
            timeout=15000,
        )
        rows_before = rows(page).count()
        queue_before = page.locator('[data-role="sr-queue-row"]').count()
        if rows_before < 1 or queue_before < 1:
            raise RuntimeError(f"reschedule journey expects booked rows and received queue rows, got {rows_before}/{queue_before}")
        # The module never offers another-Location selection inside reschedule.
        if page.locator('[data-role="sr-reschedule-location"]').count() != 0:
            raise RuntimeError("reschedule must not expose another-Location selector")
        if page.locator('[data-role="sr-reschedule-form"]').is_visible():
            raise RuntimeError("the reschedule panel must stay hidden until the explicit action is chosen")

        stage = "book-owned-source"
        mark = nav_mark(page)
        select_reschedule_patient(page, tag)
        page.wait_for_selector('[data-role="sr-book-clinician-wrap"]', state="visible", timeout=15000)
        page.select_option('[data-role="sr-book-clinician"]', str(RESCHEDULE["c1"]))
        wait_book_slot(page, source_slot)
        page.locator(f'[data-role="sr-book-slot"][data-slot-id="{source_slot}"]').click()
        with page.expect_response(lambda r: r.url and APPOINTMENTS_ROUTE in r.url and r.request.method == "POST", timeout=15000) as create_info:
            page.locator('[data-role="sr-book-submit"]').click()
        if create_info.value.status != 200:
            raise RuntimeError(f"source booking answered {create_info.value.status}")
        create_data = create_info.value.json()
        create_data = create_data.get("data", create_data) if isinstance(create_data, dict) else {}
        appointment = create_data.get("appointment") or {}
        source_appt = int(appointment.get("id") or 0)
        if appointment.get("status") != "confirmed" or source_appt <= 0:
            raise RuntimeError(f"the journey needs one confirmed source row, got {appointment}")
        wait_slot_absent(page, source_slot)
        wait_rows_count(page, rows_before + 1)
        source_row = row_of(page, source_appt)
        if source_row.count() != 1:
            raise RuntimeError("the booked source row must be on the reception board")

        stage = "explicit-reschedule-action"
        # Cancel stays a SEPARATE distinct action on the very same row, and only
        # a confirmed not-yet-received row offers the reschedule action.
        if source_row.locator('[data-role="sr-cancel-open"]').count() != 1:
            raise RuntimeError("Cancel must remain a separate action on the booked row")
        if source_row.locator('[data-role="sr-reschedule-open"]').count() != 1:
            raise RuntimeError("a confirmed not-yet-received row must expose exactly one explicit reschedule action")
        if source_row.locator('[data-role="sr-reschedule-form"]').count() != 0:
            raise RuntimeError("the panel must stay closed until the reschedule action is chosen")
        queue_rows = page.locator('[data-role="sr-queue-row"]')
        for i in range(queue_rows.count()):
            if queue_rows.nth(i).locator('[data-role="sr-reschedule-open"]').count() != 0:
                raise RuntimeError("a received/queue row must never expose a reschedule action")
        current_doctor_name = source_row.locator('[data-role="sr-reschedule-open"]').get_attribute("data-clinician-name") or ""
        current_time = (source_row.locator('[data-role="sr-row-time"]').inner_text() or "").strip()
        with page.expect_response(slot_read_filter, timeout=15000) as opened_read:
            source_row.locator('[data-role="sr-reschedule-open"]').click()
        page.wait_for_selector('[data-section="sr-reschedule"]', state="visible", timeout=10000)
        # For a same-day, same-doctor destination this first read IS the
        # destination read; the other viewports replace it below.
        dest_read_value = opened_read.value
        context_text = (page.locator('[data-role="sr-reschedule-context"]').inner_text() or "").strip()
        if f"Reschedule {tag.split('-')[0].capitalize()}" not in context_text or current_time not in context_text:
            raise RuntimeError(f"the panel must show the current patient/time context, got {context_text}")
        if current_doctor_name and current_doctor_name not in context_text:
            raise RuntimeError(f"the panel must show the current doctor context, got {context_text}")

        stage = "destination-doctor"
        page.wait_for_selector('[data-role="sr-reschedule-clinician-wrap"]', state="visible", timeout=15000)
        clinician_select = page.locator('[data-role="sr-reschedule-clinician"]')
        options = [clinician_select.locator("option").nth(i).get_attribute("value") for i in range(clinician_select.locator("option").count())]
        if str(RESCHEDULE["c1"]) not in options or str(RESCHEDULE["c2"]) not in options:
            raise RuntimeError(f"the eligible doctors of the CURRENT Location must be offered, got {options}")
        if clinician_select.input_value() != str(RESCHEDULE["c1"]):
            raise RuntimeError("the current doctor must be the initially selectable destination")
        if dest_doctor != RESCHEDULE["c1"]:
            with page.expect_response(slot_read_filter, timeout=15000) as switched_read:
                clinician_select.select_option(str(dest_doctor))
            dest_read_value = switched_read.value
            # Changing the destination doctor clears the stale slot state.
            if page.locator('[data-role="sr-reschedule-slot"][aria-pressed="true"]').count() != 0:
                raise RuntimeError("changing the destination doctor must clear the previous slot selection")

        stage = "destination-date-and-slot"
        page.wait_for_selector('[data-role="sr-reschedule-date"]', state="visible", timeout=15000)
        date_select = page.locator('[data-role="sr-reschedule-date"]')
        if not same_day:
            if date_select.locator(f'option[value="{dest_date}"]').count() != 1:
                raise RuntimeError("the Location-local destination date must be selectable")
            with page.expect_response(slot_read_filter, timeout=15000) as dated_read:
                date_select.select_option(dest_date)
            dest_read_value = dated_read.value
        elif date_select.input_value() != dest_date:
            raise RuntimeError(f"a same-day destination must default to the operational date, got {date_select.input_value()}")
        wait_reschedule_slot(page, dest_slot)
        # The rendered candidates come from the EXISTING bounded read of the
        # CURRENT Location only: the journey correlates them with that read.
        if dest_read_value is None:
            raise RuntimeError("no existing slot read answered for the destination")
        if dest_read_value.status != 200:
            raise RuntimeError(f"the destination slot read answered {dest_read_value.status}")
        last_slots = dest_read_value.json()
        last_slots = last_slots.get("data", last_slots) if isinstance(last_slots, dict) else {}
        if int(last_slots.get("location_id") or 0) != PUB["loc_tehran"]:
            raise RuntimeError(f"the destination slot read must stay in the CURRENT trusted Location, got {last_slots.get('location_id')}")
        offered = set(int(s.get("slot_id") or 0) for s in (last_slots.get("slots") or []))
        rendered = set(reschedule_slot_ids(page))
        if not rendered or not rendered.issubset(offered):
            raise RuntimeError(f"the panel must render exactly the existing read's slots, rendered={sorted(rendered)} offered={sorted(offered)}")
        page.locator(f'[data-role="sr-reschedule-slot"][data-slot-id="{dest_slot}"]').click()
        selected_text = (page.locator('[data-role="sr-reschedule-selected"]').inner_text() or "").strip()
        dest_time_short = next(
            (str(s.get("time") or "") for s in (last_slots.get("slots") or []) if int(s.get("slot_id") or 0) == dest_slot),
            "",
        )
        if dest_time_short == "" or dest_time_short not in selected_text:
            raise RuntimeError(f"the chosen persisted slot must be shown before submit, got {selected_text}")
        if page.locator('[data-role="sr-reschedule-confirm"]').is_disabled():
            raise RuntimeError("the explicit confirm must be enabled once a persisted destination slot is chosen")

        stage = "explicit-submit-with-idempotency-key"
        with page.expect_response(lambda r: r.url and RESCHEDULE_PATH_RE.search(r.url) is not None and r.request.method == "POST", timeout=20000) as res_info:
            page.evaluate(
                """() => { const b = document.querySelector('[data-role="sr-reschedule-confirm"]'); b.click(); b.click(); }"""
            )
        posts = reschedule_posts(state)
        if len(posts) != 1:
            raise RuntimeError(f"double-submit must stay exactly one explicit reschedule mutation, got {posts}")
        if res_info.value.status != 200:
            raise RuntimeError(f"reception reschedule answered {res_info.value.status}")
        headers = {k.lower(): v for k, v in (res_info.value.request.headers or {}).items()}
        idem = headers.get("idempotency-key", "")
        if not UUID_RE.match(idem or ""):
            raise RuntimeError(f"the reschedule must carry the existing UUID Idempotency-Key contract, got {idem!r}")
        body = res_info.value.request.post_data_json
        if not isinstance(body, dict) or sorted(body.keys()) != ["clinician_id", "slot_id"]:
            raise RuntimeError(f"the reschedule must send only the selected doctor + persisted slot, got {body}")
        if int(body.get("clinician_id") or 0) != dest_doctor or int(body.get("slot_id") or 0) != dest_slot:
            raise RuntimeError(f"the reschedule must send the chosen destination, got {body}")
        if "clinic_id" in body or "location_id" in body or "slot_date" in body or "slot_time" in body:
            raise RuntimeError("raw scope selectors and client date/time must never be sent as authority")
        res_json = res_info.value.json()
        res_data = res_json.get("data", res_json) if isinstance(res_json, dict) else {}
        if sorted(res_data.keys()) != ["appointment", "reception"]:
            raise RuntimeError(f"the reschedule response must stay bounded, got {sorted(res_data.keys())}")
        view = res_data.get("appointment") or {}
        if sorted(view.keys()) != ["appointment_id", "date", "jalali", "previous_appointment_id", "reference_code", "status", "time"]:
            raise RuntimeError(f"the bounded appointment view must carry only the documented keys, got {sorted(view.keys())}")
        if int(view.get("previous_appointment_id") or 0) != source_appt or view.get("status") != "confirmed":
            raise RuntimeError(f"the established old→new relationship is expected, got {view}")
        if view.get("date") != dest_date:
            raise RuntimeError(f"the persisted destination date is expected, got {view.get('date')}")
        scope = res_data.get("reception") or {}
        if sorted(scope.keys()) != ["clinic_id", "location_id", "on_operational_day"]:
            raise RuntimeError(f"the reception scope view must stay bounded, got {sorted(scope.keys())}")
        if int(scope.get("clinic_id") or 0) != PUB["clinic"] or int(scope.get("location_id") or 0) != PUB["loc_tehran"]:
            raise RuntimeError(f"the reschedule must stay in the CURRENT trusted scope, got {scope}")
        if bool(scope.get("on_operational_day")) is not same_day:
            raise RuntimeError(f"the operational-day flag must match the destination date, got {scope}")
        new_appt = int(view.get("appointment_id") or 0)
        if new_appt <= 0:
            raise RuntimeError("the replacement appointment identity is required")

        stage = "board-outcome"
        wait_reschedule_state(page, "کد پیگیری")
        page.wait_for_function(
            """(id) => !document.querySelector('[data-role="sr-row"][data-appointment-id="' + id + '"]')""",
            arg=str(source_appt),
            timeout=15000,
        )
        if same_day:
            page.wait_for_function(
                """(id) => !!document.querySelector('[data-role="sr-row"][data-appointment-id="' + id + '"]')""",
                arg=str(new_appt),
                timeout=15000,
            )
            new_row = row_of(page, new_appt)
            if (new_row.locator('[data-role="sr-row-time"]').inner_text() or "").strip() != (dest_time_short or "")[:5]:
                raise RuntimeError("the same-day board row must show the persisted destination time")
            # The replacement is still NOT received: it keeps the explicit
            # actions a booked row owns (Cancel stays distinct) and no Visit.
            if new_row.locator('[data-role="sr-cancel-open"]').count() != 1 or new_row.locator('[data-role="sr-reschedule-open"]').count() != 1:
                raise RuntimeError("the replacement must stay a booked-not-received row with its own distinct actions")
            wait_rows_count(page, rows_before + 1)
        else:
            if row_of(page, new_appt).count() != 0:
                raise RuntimeError("an off-operational-day destination must stay OFF today's board")
            wait_rows_count(page, rows_before)
        if page.locator('[data-role="sr-queue-row"]').count() != queue_before:
            raise RuntimeError("reschedule must not create or alter a Visit/queue row")

        stage = "claimed-and-released-through-existing-reads"
        # The destination slot is claimed: the existing read no longer offers it.
        page.wait_for_function(
            """(id) => !Array.from(document.querySelectorAll('[data-role="sr-reschedule-slot"]')).some((n) => n.getAttribute('data-slot-id') === String(id))""",
            arg=int(dest_slot),
            timeout=15000,
        )
        # The released source slot is offered again by the EXISTING bounded read.
        if dest_doctor != RESCHEDULE["c1"]:
            page.select_option('[data-role="sr-reschedule-clinician"]', str(RESCHEDULE["c1"]))
        date_select_after = page.locator('[data-role="sr-reschedule-date"]')
        if dest_date == PUB["today_tehran"]:
            date_select_after.select_option(RESCHEDULE["dest_date"]["desktop-1366"])
        date_select_after.select_option(PUB["today_tehran"])
        wait_reschedule_slot(page, source_slot)
        if page.locator('[data-role="sr-queue-row"]').count() != queue_before:
            raise RuntimeError("the released slot must not create a Visit/queue row")
        assert_no_horizontal_overflow(page, "reschedule-outcome")
        shot(page, f"reception-{tag}-reschedule-outcome")

        stage = "writes-bounded"
        writes = [r for r in state["rest"] if r["method"] != "GET"]
        reschedules = [r for r in writes if RESCHEDULE_PATH_RE.search(r["route"]) is not None]
        creates = [r for r in writes if r["route"].rstrip("/").endswith(APPOINTMENTS_ROUTE)]
        if len(reschedules) != 1 or len(creates) != 1 or len(writes) != 2:
            raise RuntimeError(f"reschedule journey must issue exactly one create and one reschedule write, got {writes}")

        rest_delta = assert_no_product_reload(page, mark, "reschedule")
        assert_authority_headers(state, PUB["clinic"], {PUB["loc_tehran"], PUB["loc_tokyo"]})
        assert_hygiene(state, key)
        ok(
            key,
            "reception reschedule: explicit action + persisted same-Location slot → old row rescheduled, destination claimed, source released, no Visit/queue",
            f"vp={vp['vp']} same_day={int(same_day)} dest_doctor={dest_doctor} reschedule_posts=1 create_posts=1 board_rows={rows_before}->{rows(page).count()} queue_rows={queue_before} idem_key=uuid dest_claimed=1 source_reoffered=1 reloaded=0 overflow=0",
        )
    except Exception as e:
        try:
            dump = {
                "reschedule_state": (page.locator('[data-role="sr-reschedule-state"]').inner_text() or "")[:160],
                "panel_visible": page.locator('[data-role="sr-reschedule-form"]').is_visible(),
                "slots": reschedule_slot_ids(page),
                "rest_tail": [f"{r['method']} {r['route']}={r['status']}" for r in state["rest"][-12:]],
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
        # Phase 11 Slice 4 — walk-in for the selected Clinic patient (after
        # Slices 1–3, which stay unchanged).
        for vp, doctor_key in zip(VIEWPORTS, ("c1", "c2", "c1")):
            try:
                run_walkin_journey(browser, vp, doctor_key)
            except Exception:
                hard_fail = True
        try:
            run_walkin_partial_journey(browser, VIEWPORTS[2])
        except Exception:
            hard_fail = True
        # Phase 11 Slice 5 — confirmed appointments from already-generated
        # persisted slots, only after the existing walk-in journeys have kept
        # their exact four-row board invariant.
        for vp in VIEWPORTS:
            try:
                run_booking_journey(browser, vp)
            except Exception:
                hard_fail = True
        # Phase 11 Slice 6 — cancel a booked appointment at the trusted
        # Location. Each journey books its own dedicated capacity-1 slot and
        # then cancels it, so the board row count returns to what it was and the
        # earlier journeys' invariants stay untouched.
        for vp in VIEWPORTS:
            try:
                run_cancel_journey(browser, vp)
            except Exception:
                hard_fail = True
        # Phase 11 Slice 7 — reschedule a booked appointment WITHIN the CURRENT
        # trusted Location. Each journey books its own source slot and moves the
        # row to its own persisted destination slot of the same Location, so the
        # earlier journeys' invariants stay untouched.
        for vp in VIEWPORTS:
            try:
                run_reschedule_journey(browser, vp)
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
