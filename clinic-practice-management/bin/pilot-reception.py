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
_pub = parts("RECEPTION_PUBLIC", 7)
PUB = {
    "clinic": int(_pub[0]),
    "loc_tehran": int(_pub[1]),
    "loc_tokyo": int(_pub[2]),
    "appt_express": int(_pub[3]),
    "appt_plain": int(_pub[4]),
    "today_tehran": _pub[5],
    "today_tokyo": _pub[6],
}


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


def run_journey(browser, vp):
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
        wait_rows_count(page, 2)
        date_text = page.locator('[data-role="sr-date"]').inner_text() or ""
        if PUB["today_tehran"] not in date_text:
            raise RuntimeError(f"Tehran board date must be the Location-local day, got {date_text[:60]}")
        got_ids = sorted(
            int(rows(page).nth(i).get_attribute("data-appointment-id"))
            for i in range(rows(page).count())
        )
        want_ids = sorted([PUB["appt_express"], PUB["appt_plain"]])
        if got_ids != want_ids:
            raise RuntimeError(f"board must show exactly today's booked rows for the trusted Location, got {got_ids}")
        if row_of(page, PUB["appt_express"]).locator(".cpms-sr-badge--express").count() != 1:
            raise RuntimeError("express booking must render its badge")
        shot(page, f"reception-{vp['vp']}-board")

        stage = "arrival"
        mark = nav_mark(page)
        row_of(page, PUB["appt_express"]).locator('[data-role="sr-arrive"]').click()
        wait_status_contains(page, "حضور ثبت شد")
        wait_row_text(page, PUB["appt_express"], "در انتظار")
        if page.locator('[data-role="sr-queue-row"]').count() != 1:
            raise RuntimeError("waiting queue must show exactly the arrived patient")
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
            f"vp={vp['vp']} rows=2 arrived=1 waiting=1 rest={rest_delta} reloaded=0",
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
            wait_rows_count(page, 2)

        assert_authority_headers(state, PUB["clinic"], {PUB["loc_tehran"], PUB["loc_tokyo"]})
        assert_hygiene(state, f"reception-{vp['vp']}")
        ok(
            f"reception-{vp['vp']}-shell",
            "reception module renders inside the shared Staff Portal shell",
            f"nav=[reception] cfg=1 script=1 chrome=0",
        )
    except Exception as e:
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
        for vp in VIEWPORTS:
            try:
                run_journey(browser, vp)
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
