#!/usr/bin/env python3
"""Doctor Portal proof on the existing Pilot Chromium job.

Real WordPress page, real authenticated doctor session, real REST, and the
existing server authorization. No new browser framework and no time freeze.
Location-local today is the fixture's Asia/Tehran date.

Evidence lines are PASS/FAIL/INFO/SHOT with booleans and non-sensitive ids.
Passwords, mobiles, nonces, and cookies are not printed.
"""

import json
import os
import re
import sys
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
OUT = "pilot-screenshots"
PORTAL_URL = os.environ.get("DOCTOR_PORTAL_URL", "").strip()
FORBIDDEN_KEYS = {"clinician_id", "organization_id", "role", "patient_id"}
ALLOWED_AUTHORITY_HEADERS = {"x-cpms-clinic-id", "x-cpms-location-id"}
PERSIAN_RE = re.compile(r"[\u0600-\u06FF]")
CHROME = (
    'id="wpadminbar"',
    'id="adminmenu"',
    'id="wpfooter"',
    "wp-site-blocks",
    "site-header",
    "site-footer",
    "wp-block-template-part",
)

VIEWPORTS = [
    {"vp": "mobile-390", "w": 390, "h": 844, "shot": "doctor-portal-mobile-390-one"},
    {"vp": "tablet-768", "w": 768, "h": 1024, "shot": "doctor-portal-tablet-768-one"},
    {"vp": "desktop-1366", "w": 1366, "h": 768, "shot": "doctor-portal-desktop-1366-one"},
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


def page_hint(page):
    try:
        path = urlparse(page.url or "").path or "/"
        shell = page.locator("html").get_attribute("data-cpms-doctor-portal-shell") or "0"
        user = "0"
        if page.locator("[data-shell-user]").count():
            user = page.locator("[data-shell-user]").first.get_attribute("data-shell-user") or "0"
        denied = 1 if page.locator('[data-role="portal-access-denied"]').count() else 0
        return f"path={path} shell={shell} user={user} denied={denied}"
    except Exception:
        return "hint=unavailable"


def info(line):
    print(f"INFO {line}")


def shot(page, name):
    os.makedirs(OUT, exist_ok=True)
    page.screenshot(path=f"{OUT}/{name}.png", full_page=True)
    print(f"SHOT {name}")


def _parts(name, n):
    raw = os.environ.get(name, "").strip()
    parts = [p.strip() for p in raw.split("|")]
    if len(parts) < n or not parts[0]:
        raise SystemExit(f"{name} must carry {n} pipe-separated parts")
    return parts


def _doctor(parts, kind):
    if kind == "one":
        return {
            "login": parts[0],
            "password": parts[1],
            "user_id": int(parts[2]),
            "clinician_id": int(parts[3]),
            "clinic_id": int(parts[4]),
            "location_id": int(parts[5]),
            "visit_own": int(parts[6]),
            "visit_other": int(parts[7]),
            "today": parts[8],
            "clinic_name": parts[9],
            "location_name": parts[10],
            "clinician_name": parts[11],
        }
    return {
        "login": parts[0],
        "password": parts[1],
        "user_id": int(parts[2]),
        "clinician_id": int(parts[3]),
        "clinic_id": int(parts[4]),
        "loc_a": int(parts[5]),
        "loc_b": int(parts[6]),
        "visit_a": int(parts[7]),
        "visit_b": int(parts[8]),
        "visit_other": int(parts[9]),
        "inactive_loc": int(parts[10]),
        "today": parts[11],
        "clinic_name": parts[12],
        "loc_a_name": parts[13],
        "loc_b_name": parts[14],
    }


if not PORTAL_URL.startswith(BASE) or "/wp-admin/" in PORTAL_URL:
    raise SystemExit("DOCTOR_PORTAL_URL must be the frontend portal on BASE")

ONE = _doctor(_parts("DOCTOR_ONE", 12), "one")
OTHER = _doctor(_parts("DOCTOR_OTHER", 12), "one")
MULTI = _doctor(_parts("DOCTOR_MULTI", 15), "multi")


def payload(body):
    if not isinstance(body, dict):
        raise RuntimeError("REST body was not an object")
    data = body.get("data")
    if isinstance(data, dict):
        return data
    return body


def route_of(url):
    parsed = urlparse(url)
    flat = {k: v[0] for k, v in parse_qs(parsed.query, keep_blank_values=True).items()}
    route = flat.get("rest_route")
    if route:
        return route.split("?", 1)[0].rstrip("/") or "/"
    marker = "/clinic/v1"
    if marker in parsed.path:
        return parsed.path[parsed.path.index(marker):].split("?", 1)[0].rstrip("/") or "/"
    return parsed.path.rstrip("/") or "/"


def new_page(browser, vp):
    ctx = browser.new_context(
        viewport={"width": vp["w"], "height": vp["h"]},
        locale="fa-IR",
        ignore_https_errors=True,
    )
    page = ctx.new_page()
    page.set_default_timeout(25000)
    state = {"reqs": [], "rest": [], "context": None, "locations": None, "todays": [], "console": [], "pageerrors": [], "failed": []}

    def on_request(req):
        if not req.url.startswith(BASE) or "/clinic/v1" not in req.url:
            return
        headers = {k.lower(): v for k, v in req.headers.items()}
        state["reqs"].append({
            "method": req.method,
            "url": req.url,
            "route": route_of(req.url),
            "headers": headers,
            "body": req.post_data or "",
        })

    def on_response(resp):
        if not resp.url.startswith(BASE) or "/clinic/v1" not in resp.url:
            return
        state["rest"].append({
            "route": route_of(resp.url),
            "status": resp.status,
            "method": resp.request.method,
        })

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
        state["failed"].append(failure[:80])

    page.on("request", on_request)
    page.on("response", on_response)
    page.on("console", on_console)
    page.on("pageerror", on_pageerror)
    page.on("requestfailed", on_requestfailed)
    return ctx, page, state


def login(page, doctor):
    page.goto(f"{BASE}/wp-login.php", wait_until="networkidle")
    page.fill("#user_login", doctor["login"])
    page.fill("#user_pass", doctor["password"])
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle")
    if "wp-login.php" in (page.url or "") and "loggedout" not in (page.url or ""):
        raise RuntimeError("login failed")


def reset_net(state):
    for key in ("reqs", "rest", "todays", "console", "pageerrors", "failed"):
        state[key].clear()
    state["context"] = None
    state["locations"] = None


def authority_problems(reqs, clinic_id, allowed_locations):
    problems = []
    for req in reqs:
        parsed = urlparse(req["url"])
        qs = {k.lower(): v[0] for k, v in parse_qs(parsed.query, keep_blank_values=True).items()}
        for key in qs:
            if key in FORBIDDEN_KEYS:
                problems.append(f"query {key} on {req['route']}")
        if "clinic_id" in qs and not req["route"].endswith("/doctor/portal/locations"):
            problems.append(f"clinic_id query outside location selector on {req['route']}")
        if "clinic_id" in qs and qs["clinic_id"] != str(clinic_id):
            problems.append("location selector clinic_id is not the membership clinic")
        if req["body"]:
            try:
                body = json.loads(req["body"])
            except Exception:
                body = None
            keys = body.keys() if isinstance(body, dict) else []
            for key in keys:
                if str(key).lower() in FORBIDDEN_KEYS:
                    problems.append(f"body {key} on {req['route']}")
            if body is None:
                low = req["body"].lower()
                for key in FORBIDDEN_KEYS:
                    if key in low:
                        problems.append(f"body text {key} on {req['route']}")
        for key, value in req["headers"].items():
            if key in FORBIDDEN_KEYS or key in {
                "x-cpms-clinician-id", "x-cpms-organization-id", "x-cpms-role", "x-cpms-patient-id",
            }:
                problems.append(f"header {key} on {req['route']}")
            if key.startswith("x-cpms-") and key not in ALLOWED_AUTHORITY_HEADERS:
                problems.append(f"unexpected authority header {key} on {req['route']}")
        clinic_header = req["headers"].get("x-cpms-clinic-id")
        if clinic_header is not None and clinic_header != str(clinic_id):
            problems.append(f"clinic header is not the trusted clinic on {req['route']}")
        loc_header = req["headers"].get("x-cpms-location-id")
        if loc_header is not None and int(loc_header) not in allowed_locations:
            problems.append(f"location header is not an eligible location on {req['route']}")
    return problems


def assert_shell(page):
    url = page.url or ""
    if "/wp-admin/" in url or "wp-login.php" in url:
        raise RuntimeError("portal remained under wp-admin or login")
    page.wait_for_selector('html[data-cpms-doctor-portal-shell="v1"]', timeout=15000)
    html = page.locator("html")
    if html.get_attribute("dir") != "rtl" or html.get_attribute("lang") != "fa":
        raise RuntimeError("document is not Persian RTL")
    direction = page.evaluate("getComputedStyle(document.documentElement).direction")
    if direction != "rtl":
        raise RuntimeError(f"computed direction is {direction}")
    if page.locator('[data-shell-user="doctor"]').count() != 1:
        raise RuntimeError("authenticated doctor shell marker missing")
    content = page.content()
    for marker in CHROME:
        if marker in content:
            raise RuntimeError(f"theme or wp-admin chrome present ({marker})")
    if page.locator("header").count() != 1 or page.locator("footer").count() != 1:
        raise RuntimeError("theme layout added a header or footer")
    title = page.locator('[data-role="portal-header-title"]').inner_text()
    if not PERSIAN_RE.search(title):
        raise RuntimeError("portal title is not Persian")
    cfg_raw = page.locator("script.cpms-doctor-portal__config").text_content() or ""
    cfg = json.loads(cfg_raw)
    if cfg.get("is_doctor") is not True or not str(cfg.get("rest_root") or "").endswith("/clinic/v1"):
        raise RuntimeError("portal config is not the doctor REST root")
    if not cfg.get("nonce"):
        raise RuntimeError("wp_rest nonce missing")
    leaked = [k for k in FORBIDDEN_KEYS | {"clinic_id"} if k in cfg]
    if leaked:
        raise RuntimeError(f"published config contains {leaked}")


def assert_hygiene(page, state, label):
    sw = page.evaluate("document.documentElement.scrollWidth")
    iw = page.evaluate("window.innerWidth")
    if sw > iw + 1:
        raise RuntimeError(f"{label} horizontal overflow scrollWidth={sw} innerWidth={iw}")
    font = page.evaluate(
        "parseFloat(getComputedStyle(document.querySelector('[data-role=\"portal-header-title\"]')).fontSize)"
    )
    if font < 16:
        raise RuntimeError(f"{label} title is not readable ({font}px)")
    logout = page.locator('[data-role="portal-logout"]')
    box = logout.bounding_box()
    if not box or box["height"] < 40 or box["width"] < 40:
        raise RuntimeError(f"{label} logout control is below a touch target")
    if state["console"] or state["pageerrors"] or state["failed"]:
        raise RuntimeError(f"{label} console/page/network hygiene broken")
    return sw, iw


def queue_ids(page):
    return [int(v) for v in page.locator('[data-role="queue-item"]').evaluate_all(
        "els => els.map(e => e.getAttribute('data-visit-id'))"
    ) if str(v).isdigit()]


def today_data(state):
    if not state["todays"]:
        raise RuntimeError("today response body missing")
    return payload(state["todays"][-1])


def _json(resp, label):
    if resp.status != 200:
        raise RuntimeError(f"{label} HTTP {resp.status}")
    return resp.json()


def goto_portal(page, state, expect_today):
    reset_net(state)
    context_pred = lambda r: route_of(r.url).endswith("/doctor/portal/context")
    if expect_today:
        today_pred = lambda r: route_of(r.url).endswith("/doctor/today")
        with page.expect_response(context_pred, timeout=25000) as ctx_info:
            with page.expect_response(today_pred, timeout=25000) as today_info:
                page.goto(PORTAL_URL, wait_until="domcontentloaded")
        state["context"] = _json(ctx_info.value, "context")
        state["todays"].append(_json(today_info.value, "today"))
    else:
        with page.expect_response(context_pred, timeout=25000) as ctx_info:
            page.goto(PORTAL_URL, wait_until="domcontentloaded")
        state["context"] = _json(ctx_info.value, "context")
    page.wait_for_load_state("networkidle")
    assert_shell(page)


def prove_one(browser, doctor, vp, shot_name=None):
    key = f"doctor-portal-{vp['vp']}-{'one' if shot_name else 'other'}"
    stage = "login"
    ctx, page, state = new_page(browser, vp)
    try:
        login(page, doctor)
        stage = "shell"
        goto_portal(page, state, expect_today=True)
        page.wait_for_function(
            """(name) => {
              const el = document.querySelector('[data-role="context-title"]');
              return !!(el && el.textContent && el.textContent.includes(name));
            }""",
            arg=doctor["clinician_name"],
            timeout=20000,
        )
        page.wait_for_selector(
            f'[data-role="queue-item"][data-visit-id="{doctor["visit_own"]}"]',
            timeout=20000,
        )
        if shot_name:
            shot(page, shot_name)
        stage = "auto-resolve"
        ctx_body = payload(state["context"] or {})
        if ctx_body.get("selected_clinic_id") != doctor["clinic_id"]:
            raise RuntimeError("one clinic did not auto-resolve")
        if ctx_body.get("selected_location_id") != doctor["location_id"]:
            raise RuntimeError("one location did not auto-resolve")
        if (ctx_body.get("doctor") or {}).get("clinician_id") != doctor["clinician_id"]:
            raise RuntimeError("server clinician identity mismatch")
        if page.locator('[data-role="clinic-selector-wrap"]').is_visible():
            raise RuntimeError("clinic selector visible for one clinic")
        if page.locator('[data-role="location-selector-wrap"]').is_visible():
            raise RuntimeError("location selector visible for one location")
        clinic_text = page.locator('[data-role="context-clinic"]').inner_text()
        loc_text = page.locator('[data-role="context-location"]').inner_text()
        if doctor["clinic_name"] not in clinic_text or doctor["location_name"] not in loc_text:
            raise RuntimeError("clinic/location identity not visible")
        if "Asia/Tehran" not in loc_text:
            raise RuntimeError("location timezone not visible")
        stage = "today-queue"
        data = today_data(state)
        if data.get("date") != doctor["today"] or data.get("location_id") != doctor["location_id"]:
            raise RuntimeError("today operational date or location mismatch")
        if (data.get("stats") or {}).get("waiting") != 1:
            raise RuntimeError("today waiting count is not the doctor's own row")
        ids = [int(row.get("id")) for row in (data.get("queue") or [])]
        if doctor["visit_own"] not in ids or doctor["visit_other"] in ids:
            raise RuntimeError(f"queue isolation failed: {ids}")
        if any(int(row.get("clinician_id") or 0) != doctor["clinician_id"] for row in data.get("queue") or []):
            raise RuntimeError("queue row clinician was not the server identity")
        if set(queue_ids(page)) != {doctor["visit_own"]}:
            raise RuntimeError("rendered queue does not match the server queue")
        if doctor["today"] not in (page.locator('[data-role="today-date"]').inner_text() or ""):
            raise RuntimeError("today date not rendered")
        stage = "authority"
        problems = authority_problems(state["reqs"], doctor["clinic_id"], {doctor["location_id"]})
        if problems:
            raise RuntimeError("client authority rejected: " + "; ".join(problems[:4]))
        today_reqs = [r for r in state["reqs"] if r["route"].endswith("/doctor/today")]
        if not today_reqs:
            raise RuntimeError("today request missing")
        if any("clinician_id" in r["url"] or "clinician_id" in r["body"] for r in state["reqs"]):
            raise RuntimeError("clinician_id was sent as client authority")
        stage = "hygiene"
        sw, iw = assert_hygiene(page, state, vp["vp"])
        ok(
            key,
            "independent shell, one location auto-resolves, own queue only, selector authority",
            f"clinic={doctor['clinic_id']} location={doctor['location_id']} own={doctor['visit_own']} other_hidden=1 clinician_id_sent=0 sw={sw} iw={iw}",
        )
        info(
            f"{key} shell=1 rtl=1 auto_clinic=1 auto_location=1 own_visit=1 other_visit=0 clinician_id_sent=0"
        )
    except Exception as e:  # noqa: BLE001
        try:
            shot(page, f"doctor-portal-FAIL-{key}-{stage}")
        except Exception:
            pass
        fail(key, vp["vp"], f"{e} ({page_hint(page)})")
        raise
    finally:
        ctx.close()


def prove_multi(browser, doctor, vp, shots=False):
    key = f"doctor-portal-{vp['vp']}-multi"
    stage = "login"
    ctx, page, state = new_page(browser, vp)
    try:
        login(page, doctor)
        stage = "before"
        goto_portal(page, state, expect_today=False)
        page.wait_for_selector('[data-role="location-selector-wrap"]:not([hidden])', timeout=20000)
        if shots:
            shot(page, "doctor-portal-multi-before-selection")
        ctx_body = payload(state["context"] or {})
        if ctx_body.get("selected_clinic_id") != doctor["clinic_id"]:
            raise RuntimeError("multi clinic did not resolve the sole membership")
        if ctx_body.get("selected_location_id") not in (None, 0, ""):
            raise RuntimeError("multi location was auto-selected by context")
        eligible = [int(row.get("id")) for row in (ctx_body.get("eligible_locations") or [])]
        if set(eligible) != {doctor["loc_a"], doctor["loc_b"]}:
            raise RuntimeError(f"eligible locations are not exactly A and B: {eligible}")
        if doctor["inactive_loc"] in eligible:
            raise RuntimeError("inactive location was eligible")
        if page.locator('[data-role="clinic-selector-wrap"]').is_visible():
            raise RuntimeError("clinic selector visible for one clinic")
        select = page.locator('[data-role="location-select"]')
        if select.input_value() != "":
            raise RuntimeError("location selector auto-selected a value")
        option_values = select.locator("option").evaluate_all("els => els.map(e => e.value)")
        if str(doctor["loc_a"]) not in option_values or str(doctor["loc_b"]) not in option_values:
            raise RuntimeError("both eligible locations must be offered")
        if str(doctor["inactive_loc"]) in option_values:
            raise RuntimeError("inactive location was offered")
        if option_values and option_values[0] != "":
            raise RuntimeError("first option is not the empty placeholder")
        if page.locator('[data-role="today-section"]').is_visible() or page.locator('[data-role="queue-section"]').is_visible():
            raise RuntimeError("operational sections visible before location selection")
        if any(r["route"].endswith("/doctor/today") or r["route"].endswith("/rt/queue") for r in state["reqs"]):
            raise RuntimeError("today or queue was requested before location selection")
        if any(r["route"].endswith("/doctor/today") and r["status"] == 200 for r in state["rest"]):
            raise RuntimeError("today succeeded before location selection")
        loc_box = select.bounding_box()
        if not loc_box or loc_box["height"] < 40:
            raise RuntimeError("location selector is below a touch target")
        stage = "after"
        with page.expect_response(lambda r: route_of(r.url).endswith("/doctor/today"), timeout=20000) as today_info:
            select.select_option(str(doctor["loc_b"]))
        state["todays"].append(_json(today_info.value, "today"))
        page.wait_for_function(
            """(visitId) => !!document.querySelector('[data-role="queue-item"][data-visit-id="' + visitId + '"]')""",
            arg=str(doctor["visit_b"]),
            timeout=20000,
        )
        if shots:
            shot(page, "doctor-portal-multi-after-selection")
        if select.input_value() != str(doctor["loc_b"]):
            raise RuntimeError("location B was not the explicit selection")
        data = today_data(state)
        if data.get("location_id") != doctor["loc_b"] or data.get("date") != doctor["today"]:
            raise RuntimeError("today after selection is not location B")
        ids = [int(row.get("id")) for row in (data.get("queue") or [])]
        if set(ids) != {doctor["visit_b"]}:
            raise RuntimeError(f"location or doctor leak after selection: {ids}")
        if doctor["visit_a"] in ids or doctor["visit_other"] in ids:
            raise RuntimeError("location A or the other doctor leaked into location B")
        if set(queue_ids(page)) != {doctor["visit_b"]}:
            raise RuntimeError("rendered queue does not match location B")
        b_reqs = [
            r for r in state["reqs"]
            if r["route"].endswith("/doctor/today") or r["route"].endswith("/rt/queue")
        ]
        if not b_reqs or any(r["headers"].get("x-cpms-location-id") != str(doctor["loc_b"]) for r in b_reqs):
            raise RuntimeError("operational requests were not bound to location B")
        stage = "authority"
        problems = authority_problems(
            state["reqs"], doctor["clinic_id"], {doctor["loc_a"], doctor["loc_b"]}
        )
        if problems:
            raise RuntimeError("client authority rejected: " + "; ".join(problems[:4]))
        if any("clinician_id" in r["url"] or "clinician_id" in r["body"] for r in state["reqs"]):
            raise RuntimeError("clinician_id was sent as client authority")
        stage = "hygiene"
        sw, iw = assert_hygiene(page, state, vp["vp"])
        ok(
            key,
            "multi-location requires explicit B; today and queue stay on B",
            f"clinic={doctor['clinic_id']} A={doctor['loc_a']} B={doctor['loc_b']} visitB={doctor['visit_b']} leak=0 clinician_id_sent=0 sw={sw} iw={iw}",
        )
        info(f"{key} before_today=0 primary_auto_selected=0 after_location=B other_doctor=0 clinician_id_sent=0")
    except Exception as e:  # noqa: BLE001
        try:
            shot(page, f"doctor-portal-FAIL-{key}-{stage}")
        except Exception:
            pass
        fail(key, vp["vp"], f"{e} ({page_hint(page)})")
        raise
    finally:
        ctx.close()


def main():
    os.makedirs(OUT, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for vp in VIEWPORTS:
            try:
                prove_one(browser, ONE, vp, vp["shot"])
            except Exception:
                continue
        desktop = VIEWPORTS[2]
        try:
            prove_one(browser, OTHER, desktop, None)
        except Exception:
            pass
        try:
            prove_multi(browser, MULTI, desktop, shots=True)
        except Exception:
            pass
        try:
            prove_multi(browser, MULTI, VIEWPORTS[0], shots=False)
        except Exception:
            pass
        browser.close()
    print(json.dumps({"ok": not failures, "failed": failures}, ensure_ascii=False))
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
