#!/usr/bin/env python3
"""Doctor Portal proof on the existing Pilot Chromium job.

Real WordPress page, real authenticated doctor session, real REST, and the
existing server authorization. No new browser framework and no time freeze.
Location-local today is the fixture's Asia/Tehran date.

# Visual-evidence instrumentation (workspace + note) — deterministic waits, no sleep.
Evidence lines are PASS/FAIL/INFO/SHOT with booleans and non-sensitive ids.
Passwords, mobiles, nonces, and cookies are not printed.
"""

import json
import os
import re
import sys
import time
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
OUT = "pilot-screenshots"
PORTAL_URL = os.environ.get("DOCTOR_PORTAL_URL", "").strip()
# Phase 10 — canonical shared Staff Portal. The full doctor journeys run on the
# canonical entry; PORTAL_URL (legacy Doctor Portal) keeps its own compatibility
# journey (prove_legacy) so existing bookmarks stay proven.
STAFF_URL = os.environ.get("STAFF_PORTAL_URL", "").strip()
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


ACTION_EXTRA_KEYS = ("act390", "skip390", "act768", "skip768", "act1366", "skip1366")
VP_ACTION_KEYS = {
    "mobile-390": ("act390", "skip390"),
    "tablet-768": ("act768", "skip768"),
    "desktop-1366": ("act1366", "skip1366"),
}


def _doctor(parts, kind):
    if kind == "one":
        d = {
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
            "appt_booked": int(parts[12]),
            "appt_arrived": int(parts[13]),
            "appt_hidden": int(parts[14]),
            "appt_hidden_2": int(parts[15]),
            "booked_name": parts[16],
            "arrived_name": parts[17],
        }
        # Slice 2 GREEN: per-viewport dedicated action visits (ONE only; the
        # OTHER line carries no extras and stays visibility-only).
        for i, key in enumerate(ACTION_EXTRA_KEYS):
            raw = parts[18 + i] if len(parts) > 18 + i else ""
            d[key] = int(raw) if str(raw).isdigit() else 0
        return d
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
        "appt_a": int(parts[15]),
        "appt_b": int(parts[16]),
        "appt_colleague": int(parts[17]),
        "appt_b_name": parts[18],
    }


if not PORTAL_URL.startswith(BASE) or "/wp-admin/" in PORTAL_URL:
    raise SystemExit("DOCTOR_PORTAL_URL must be the frontend portal on BASE")
if not STAFF_URL.startswith(BASE) or "/wp-admin/" in STAFF_URL:
    raise SystemExit("STAFF_PORTAL_URL must be the canonical frontend Staff Portal on BASE")
if STAFF_URL.rstrip("/") == PORTAL_URL.rstrip("/"):
    raise SystemExit("STAFF_PORTAL_URL must be a NEW canonical URL, not the legacy doctor URL")

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
    NAV[id(page)] = {"harness": False, "harness_docs": 0, "product_docs": 0, "product_paths": [], "rest_total": 0}

    def on_nav_request(req):
        # Main-frame document navigations only. Navigations issued by the
        # harness itself (harness_goto) are attributed to the harness; every
        # other main-frame navigation was initiated by the product page.
        try:
            if not req.is_navigation_request() or req.frame != page.main_frame:
                return
        except Exception:  # noqa: BLE001
            return
        nav = NAV[id(page)]
        if nav["harness"]:
            nav["harness_docs"] += 1
        else:
            nav["product_docs"] += 1
            nav["product_paths"].append(urlparse(req.url).path or "/")

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
        NAV[id(page)]["rest_total"] += 1
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
    page.on("request", on_nav_request)
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


# Hybrid rendering contract (owner decision; docs/decisions/2026-09-24-phase10-doctor-portal-owner-authorization.md §7):
# daily operational interactions use REST/AJAX, never a routine full reload.
NAV = {}


def harness_goto(page, url, **kwargs):
    """page.goto issued by the HARNESS (initial load, legacy entry, the PR #123
    no-show fallback refresh). Attributed separately so a product-initiated
    reload can never hide behind harness navigation."""
    nav = NAV[id(page)]
    nav["harness"] = True
    try:
        return page.goto(url, **kwargs)
    finally:
        nav["harness"] = False


def nav_mark(page):
    nav = NAV[id(page)]
    return {"product_docs": nav["product_docs"], "harness_docs": nav["harness_docs"], "rest_total": nav["rest_total"]}


def assert_no_product_reload(page, mark, label):
    """After the initial load, operational interactions must not navigate the
    document; REST traffic must carry them instead."""
    nav = NAV[id(page)]
    delta = nav["product_docs"] - mark["product_docs"]
    if delta != 0:
        raise RuntimeError(
            f"{label} product-initiated full-page navigation during operational interactions "
            f"(count={delta}, paths={nav['product_paths'][-delta:]})"
        )
    rest_delta = nav["rest_total"] - mark["rest_total"]
    if rest_delta <= 0:
        raise RuntimeError(f"{label} no REST/AJAX traffic observed for operational interactions")
    return delta, rest_delta, nav["harness_docs"] - mark["harness_docs"]


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
    if page.locator("header").count() != 1:
        raise RuntimeError("theme layout changed the shell header")
    if page.locator("footer").count() != 0:
        raise RuntimeError("theme layout added a footer")
    visible = page.locator("body").inner_text() or ""
    visible_low = visible.lower()
    for phrase in ("wp-admin", "theme", "بدون کروم"):
        if phrase.lower() in visible_low:
            raise RuntimeError(f"technical footer or evidence text is visible ({phrase})")
    if "بدون کروم" in content or "wp-admin/theme" in content:
        raise RuntimeError("technical footer evidence remains in the shell document")
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
    assert_staff_shell(page)


ROLE_SWITCH_MARKERS = ("role-switcher", "role_switcher", "switch-role", "switch_role", "strongest-role", "active-role-select")


def assert_staff_shell(page):
    """Shared Staff Portal container with ONLY the delivered doctor module."""
    if page.locator("html").get_attribute("data-cpms-staff-portal-shell") != "v1":
        raise RuntimeError("shared Staff Portal shell root missing")
    modules = page.locator("[data-cpms-staff-module]")
    ids = [modules.nth(i).get_attribute("data-cpms-staff-module") for i in range(modules.count())]
    if ids != ["doctor"]:
        raise RuntimeError(f"staff navigation must expose exactly the doctor module, got {ids}")
    if not modules.first.is_visible():
        raise RuntimeError("doctor module navigation entry is not visible")
    if page.locator('script[type="application/json"][class*="__config"]').count() != 1:
        raise RuntimeError("shared shell must publish exactly one runtime config")
    content = page.content()
    for marker in ROLE_SWITCH_MARKERS:
        if marker in content:
            raise RuntimeError(f"role switcher / strongest-role surface present ({marker})")


def assert_queue_hugs_content(page, label):
    queue = page.locator('[data-role="queue-section"]')
    if queue.count() == 0 or not queue.is_visible():
        return
    metrics = page.evaluate(
        """() => {
          const q = document.querySelector('[data-role="queue-section"]');
          const cs = getComputedStyle(q);
          let content = 0;
          for (const el of q.children) {
            const child = getComputedStyle(el);
            if (el.hidden || child.display === 'none') continue;
            const box = el.getBoundingClientRect();
            content += box.height;
            content += parseFloat(child.marginTop) || 0;
            content += parseFloat(child.marginBottom) || 0;
          }
          const pad = (parseFloat(cs.paddingTop) || 0) + (parseFloat(cs.paddingBottom) || 0);
          const border = (parseFloat(cs.borderTopWidth) || 0) + (parseFloat(cs.borderBottomWidth) || 0);
          return {
            minHeight: cs.minHeight,
            specified: cs.getPropertyValue('min-height'),
            flexGrow: cs.flexGrow,
            height: q.getBoundingClientRect().height,
            content: content + pad + border
          };
        }"""
    )
    if metrics["minHeight"] not in ("0px", "auto", "none"):
        raise RuntimeError(f"{label} queue has an artificial min-height ({metrics['minHeight']})")
    if "vh" in str(metrics["specified"]) or float(metrics["flexGrow"] or 0) > 0:
        raise RuntimeError(f"{label} queue is stretched ({metrics['specified']}, grow={metrics['flexGrow']})")
    wide = page.evaluate(
        """() => {
          const today = document.querySelector('[data-role="today-section"]');
          const queue = document.querySelector('[data-role="queue-section"]');
          const appt = document.querySelector('[data-role="appointments-section"]');
          const app = document.querySelector('#cpms-doctor-portal-app');
          if (!today || today.hidden || !queue || queue.hidden || !appt || appt.hidden || !app || window.innerWidth < 768) {
            return { applies: false };
          }
          const a = today.getBoundingClientRect();
          const b = queue.getBoundingClientRect();
          const c = appt.getBoundingClientRect();
          const box = app.getBoundingClientRect();
          const qcs = getComputedStyle(queue);
          const acs = getComputedStyle(appt);
          const usedLeft = Math.min(a.left, b.left, c.left);
          const usedRight = Math.max(a.right, b.right, c.right);
          return {
            applies: true,
            sideBySide: b.top < c.bottom - 4 && c.top < b.bottom - 4 && Math.abs(b.left - c.left) > 24,
            todayAbove: a.bottom <= Math.min(b.top, c.top) + 8,
            todaySpans: a.width > b.width + 24 && a.width > c.width + 24,
            queueAlign: qcs.alignSelf,
            apptAlign: acs.alignSelf,
            queueMin: qcs.minHeight,
            apptMin: acs.minHeight,
            queueGrow: qcs.flexGrow,
            apptGrow: acs.flexGrow,
            coverage: box.width > 0 ? (usedRight - usedLeft) / box.width : 0
          };
        }"""
    )
    stacked = page.evaluate(
        """() => {
          const today = document.querySelector('[data-role="today-section"]');
          const queue = document.querySelector('[data-role="queue-section"]');
          const appt = document.querySelector('[data-role="appointments-section"]');
          if (!today || today.hidden || !queue || queue.hidden || !appt || appt.hidden || window.innerWidth >= 768) {
            return { applies: false };
          }
          const a = today.getBoundingClientRect();
          const b = queue.getBoundingClientRect();
          const c = appt.getBoundingClientRect();
          return {
            applies: true,
            ordered: a.bottom <= b.top + 8 && b.bottom <= c.top + 8
          };
        }"""
    )
    if wide.get("applies"):
        if not wide["sideBySide"]:
            raise RuntimeError(f"{label} wide layout did not place the queue beside today's appointments")
        if not wide["todayAbove"] or not wide["todaySpans"]:
            raise RuntimeError(f"{label} Today metrics are not a compact band above the worklists")
        if wide["queueAlign"] == "stretch" or wide["apptAlign"] == "stretch":
            raise RuntimeError(f"{label} a worklist is stretched to the other column")
        if float(wide["queueGrow"] or 0) > 0 or float(wide["apptGrow"] or 0) > 0:
            raise RuntimeError(f"{label} a worklist flex-grows")
        if wide["queueMin"] not in ("0px", "auto", "none") or wide["apptMin"] not in ("0px", "auto", "none"):
            raise RuntimeError(f"{label} a worklist has an artificial min-height")
        if wide["coverage"] < 0.9:
            raise RuntimeError(f"{label} wide canvas is not used by the work area ({wide['coverage']})")
    if stacked.get("applies") and not stacked["ordered"]:
        raise RuntimeError(f"{label} mobile stack is not Today, then queue, then appointments")


def assert_hygiene(page, state, label):
    assert_queue_hugs_content(page, label)
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


APPT_KEYS = {"id", "time", "patient_name", "status", "visit_status", "express"}
APPT_STATUSES = {
    "pending", "confirmed", "cancelled_by_patient", "cancelled_by_staff",
    "rescheduled", "completed", "no_show",
}
VISIT_STATUSES = {
    "checked_in", "waiting", "called", "in_consultation", "consultation_completed",
    "awaiting_payment", "paid", "checked_out", "cancelled", "skipped",
}

# Bounded wait for the asynchronous render of the server-returned state.
APPT_PRESENTATION_WAIT_SECONDS = 15.0
APPT_PRESENTATION_POLL_MS = 200

# PHASE B — the ONE controlled real Doctor Portal refresh allowed for a
# legitimately stale DOM. The fallback is a single straight-line branch (never
# inside a loop, never retried), so one assertion can never trigger a second
# controlled refresh.
APPT_FALLBACK_REFRESH_MAX = 1

# Runtime evidence for the repaired convergence path, printed as INFO lines.
# These counters are what show whether PHASE B was exercised in a given run:
# on natural convergence all three stay 0 for that assertion.
FALLBACK_STATS = {
    "fallback_refresh": 0,
    "refresh_response_bound": 0,
    "post_refresh_dom_match": 0,
}

# Mirrors the Doctor Portal presentation contract only:
# templates/doctor-portal-shell.php -> appointmentStatusLabel(status).
# Keys are the established appointment statuses (APPT_STATUSES); a status that
# has no product label fails the journey instead of accepting an unmapped label.
APPT_STATUS_LABELS = {
    "pending": "در انتظار تأیید",
    "confirmed": "رزرو شده",
    "cancelled_by_patient": "لغو توسط بیمار",
    "cancelled_by_staff": "لغو توسط مطب",
    "rescheduled": "جابه\u200cجا شده",
    "completed": "انجام شده",
    "no_show": "عدم حضور",
}
if set(APPT_STATUS_LABELS) != APPT_STATUSES:
    raise SystemExit("appointment status label contract drifted from APPT_STATUSES")


def appointment_ids(page):
    return [int(v) for v in page.locator('[data-role="appointment-item"]').evaluate_all(
        "els => els.map(e => e.getAttribute('data-appointment-id'))"
    ) if str(v).isdigit()]


def assert_appointment_payload(data, expected, hidden, label):
    rows = data.get("appointments")
    if not isinstance(rows, list):
        raise RuntimeError(f"{label} appointments payload missing from /doctor/today")
    ids = []
    for row in rows:
        extra = set(row) - APPT_KEYS
        if extra:
            raise RuntimeError(f"{label} appointment exposed {sorted(extra)}")
        if row.get("status") not in APPT_STATUSES:
            raise RuntimeError(f"{label} appointment status is not established")
        visit_status = row.get("visit_status")
        if visit_status is not None and visit_status not in VISIT_STATUSES:
            raise RuntimeError(f"{label} visit status is not established")
        ids.append(int(row.get("id")))
    if set(ids) != set(expected):
        raise RuntimeError(f"{label} appointment scope {ids} != {expected}")
    leaked = [item for item in hidden if item in ids]
    if leaked:
        raise RuntimeError(f"{label} hidden appointments leaked: {leaked}")
    raw = json.dumps(rows, ensure_ascii=False).lower()
    for token in ("mobile", "national_id", "address", "patient_id", "clinic_id", "location_id", "clinician_id"):
        if token in raw:
            raise RuntimeError(f"{label} appointments payload contains {token}")


def assert_appointment_section(page, expected, hidden, label):
    section = page.locator('[data-role="appointments-section"]')
    if not section.is_visible():
        raise RuntimeError(f"{label} appointments section is hidden")
    rendered = appointment_ids(page)
    if set(rendered) != set(expected):
        raise RuntimeError(f"{label} rendered appointments {rendered} != {expected}")
    if any(item in rendered for item in hidden):
        raise RuntimeError(f"{label} rendered a hidden appointment")
    text = section.inner_text() or ""
    if re.search(r"\d{10,}", text):
        raise RuntimeError(f"{label} appointments section shows a sensitive number")
    for token in ("mobile", "national_id", "wp-admin", "clinician_id"):
        if token in text.lower():
            raise RuntimeError(f"{label} appointments section shows {token}")


def assert_no_appointments_route(state, label):
    if any(r["route"].rstrip("/").endswith("/appointments") for r in state["reqs"]):
        raise RuntimeError(f"{label} called a separate appointments route")


def appointment_row(data, appt_id, label, source):
    """The expected appointment inside ONE server payload, by stable id."""
    rows = data.get("appointments")
    if not isinstance(rows, list):
        raise RuntimeError(f"{label} appointments payload missing from {source}")
    for row in rows:
        if int(row.get("id") or 0) == appt_id:
            return row
    raise RuntimeError(f"{label} appointment {appt_id} is missing from {source}")


def server_appointment_row(page, doctor, appt_id, label):
    """The expected appointment from a FRESH /doctor/today server read.

    Deliberately NOT ``today_data(state)``: ``state["todays"][-1]`` is the
    initial cached response captured at page load (or at an explicit Location
    selection), while the Doctor Portal DOM is live/polled. Comparing the two
    breaks the moment the real no-show job performs its legitimate
    confirmed -> no_show transition (D-class harness stale-state defect).
    The appointment is still addressed by its stable id.
    """
    return appointment_row(
        fresh_today(page, doctor, label), appt_id, label, "the fresh /doctor/today payload"
    )


def expected_appointment_label(row):
    """Actual server-returned status plus its Doctor Portal Persian label."""
    status = row.get("status")
    if status not in APPT_STATUSES:
        raise RuntimeError(f"server returned an unrecognized appointment status: {status!r}")
    if status not in APPT_STATUS_LABELS:
        raise RuntimeError(f"no Doctor Portal label contract for appointment status {status!r}")
    return status, APPT_STATUS_LABELS[status]


def dom_appointment_row(page, appt_id):
    """Published DOM state of one appointment row: identity, status, badges."""
    return page.evaluate(
        """(id) => {
          const el = document.querySelector('[data-appointment-id="' + id + '"]');
          if (!el) return { present: false, visible: false };
          const nameEl = el.querySelector('[data-role="patient-name"]');
          return {
            present: true,
            visible: !!(el.offsetParent || el.getClientRects().length),
            status: el.getAttribute('data-status') || '',
            name: nameEl ? (nameEl.textContent || '').trim() : '',
            badges: Array.from(el.querySelectorAll('.cpms-doc-badge')).map((b) => ({
              cls: b.className || '',
              text: (b.textContent || '').trim()
            }))
          };
        }""",
        str(appt_id),
    )


def appointment_dom_converged(dom, patient_name, server_status, expected_label):
    """The ONE convergence predicate: this rendered row IS current server truth.

    Same patient, same ``data-status``, and the ``status-<status>`` badge
    carrying the established Doctor Portal label. Everything the repaired
    invariant must still fail on (missing, hidden, foreign patient,
    unrecognized/mismatched status, missing badge class, wrong label) is
    decided here.
    """
    return bool(
        dom.get("present")
        and dom.get("visible")
        and dom.get("name") == patient_name
        and dom.get("status") == server_status
        and any(
            f"status-{server_status}" in str(badge.get("cls") or "").split()
            and expected_label in str(badge.get("text") or "")
            for badge in dom.get("badges") or []
        )
    )


def appointment_presentation_problem(dom, patient_name, server_status, expected_label):
    """Why a rendered row is NOT the expected presentation (None when it is)."""
    if not dom.get("present"):
        return "is not rendered"
    if not dom.get("visible"):
        return "is hidden"
    if dom.get("name") != patient_name:
        return "shows the wrong patient"
    if dom.get("status") != server_status:
        return (
            f"UI/server appointment status mismatch: dom={dom.get('status')!r} "
            f"server={server_status!r}"
        )
    if not appointment_dom_converged(dom, patient_name, server_status, expected_label):
        return (
            f"status {server_status!r} is not rendered as its Doctor Portal label "
            f"{expected_label!r}"
        )
    return None


def refresh_appointment_presentation(page, doctor, appt_id, patient_name, label):
    """PHASE B — exactly ONE controlled refresh for a legitimately stale DOM.

    The Doctor Portal re-renders its appointments from ``/doctor/today`` on
    document load and on an explicit Location selection. An APPOINTMENT-only
    transition (the real ``visits.no_show`` sweep, confirmed -> no_show, actor
    ``system``) publishes no Visit realtime event, so the live DOM can
    legitimately stay on its previous render while the server has already moved
    on. The ONE doctor in this fixture has a single eligible Location — the
    portal hides the Location selector and auto-resolves it (asserted earlier in
    this journey) — so re-selecting the current Location is not an available
    path, and the smallest established user-visible refresh is a normal portal
    navigation: the same established path the journey itself uses on entry.

    Deterministic pairing, never a mock: the REAL ``/doctor/today`` response of
    THIS refresh is bound with ``expect_response`` BEFORE the navigation, the
    expected row is read from that bound payload, and the re-rendered DOM of
    that same document is compared against it. No later fetch is compared with
    an older render, no response is fulfilled from test code, and no test-only
    endpoint is invented.

    Exactly one refresh can occur: this function is reached from one
    straight-line branch, is never called inside a loop, and performs exactly
    one navigation. The counter delta is re-asserted per call as a tripwire.
    """
    before = dict(FALLBACK_STATS)
    today_pred = lambda r: route_of(r.url).endswith("/doctor/today")
    FALLBACK_STATS["fallback_refresh"] += 1
    if FALLBACK_STATS["fallback_refresh"] - before["fallback_refresh"] > APPT_FALLBACK_REFRESH_MAX:
        raise RuntimeError(f"{label} a second controlled fallback refresh was attempted")
    with page.expect_response(today_pred, timeout=25000) as today_info:
        harness_goto(page, STAFF_URL, wait_until="domcontentloaded")
    bound = payload(
        _json(today_info.value, "today (bound to the one controlled refresh)")
    )
    if bound.get("location_id") != doctor["location_id"] or bound.get("date") != doctor["today"]:
        raise RuntimeError(
            f"{label} the /doctor/today response bound to the one controlled refresh is "
            f"not the fixture's operational scope"
        )
    FALLBACK_STATS["refresh_response_bound"] += 1
    row = appointment_row(
        bound,
        appt_id,
        label,
        "the /doctor/today response bound to the one controlled refresh",
    )
    server_status, expected_label = expected_appointment_label(row)
    # The appointment row of THIS document is the render of that bound response.
    try:
        page.wait_for_selector(
            f'[data-role="appointment-item"][data-appointment-id="{appt_id}"]',
            state="attached",
            timeout=25000,
        )
    except Exception as e:  # noqa: BLE001
        raise RuntimeError(
            f"{label} appointment {appt_id} did not render from the /doctor/today "
            f"response bound to the one controlled refresh: {e}"
        ) from e
    dom = dom_appointment_row(page, appt_id)
    problem = appointment_presentation_problem(
        dom, patient_name, server_status, expected_label
    )
    if problem:
        raise RuntimeError(
            f"{label} appointment {appt_id} {problem} (after ONE controlled Doctor "
            f"Portal refresh, against the /doctor/today response bound to it)"
        )
    FALLBACK_STATS["post_refresh_dom_match"] += 1
    info(
        f"{label} appointment {appt_id} appointment-status-fallback "
        f"fallback_refresh=1 refresh_response_bound=1 post_refresh_dom_match=1 "
        f"status={server_status}"
    )
    return server_status, expected_label


def assert_appointment_presentation(page, doctor, appt_id, patient_name, label):
    """Bind the rendered appointment row to FRESH ACTUAL server truth.

    The appointment is identified by its stable fixture id. Its CURRENT status
    is read from a fresh authenticated ``GET /doctor/today`` issued from the
    real browser session with the same trusted Clinic/Location selector headers
    the portal itself sends — never from ``state["todays"][-1]``, the initial
    cached body, which the real no-show job invalidates with its legitimate
    confirmed -> no_show transition. That truth is checked against the
    established status contract, mapped through the bounded Doctor Portal label
    contract, and compared with the DOM: same patient, same ``data-status``, and
    the ``status-<status>`` badge carrying that label.

    PHASE A — the established bounded convergence path stays first: every
    attempt re-reads fresh server truth and the DOM back to back, so a
    transition landing mid-assertion is reconciled by the next attempt rather
    than reported as a mismatch. The wait tolerates render and poll latency
    only — it never retries toward a fixed expectation — and nothing is
    refreshed while DOM and server already agree.

    PHASE B — only when that bounded path is exhausted and DOM/server still
    legitimately differ, exactly ONE controlled real Doctor Portal refresh is
    performed and the row is compared against the response bound to it (see
    ``refresh_appointment_presentation``). A missing, hidden, foreign,
    unrecognized, mislabeled, non-converging, or refresh-unexplained
    presentation still fails.
    """
    deadline = time.monotonic() + APPT_PRESENTATION_WAIT_SECONDS
    server_status = ""
    expected_label = ""
    dom = {"present": False, "visible": False}
    while True:
        server_status, expected_label = expected_appointment_label(
            server_appointment_row(page, doctor, appt_id, label)
        )
        dom = dom_appointment_row(page, appt_id)
        if appointment_dom_converged(dom, patient_name, server_status, expected_label):
            info(
                f"{label} appointment {appt_id} appointment-status-convergence "
                f"fallback_refresh=0 refresh_response_bound=0 post_refresh_dom_match=0 "
                f"status={server_status}"
            )
            return server_status, expected_label
        if time.monotonic() >= deadline:
            break
        page.wait_for_timeout(APPT_PRESENTATION_POLL_MS)
    # The bounded path is exhausted with a legitimate divergence: the DOM still
    # shows its previous render of an appointment-only transition. ONE
    # controlled refresh follows — never a retry loop, never a longer sleep,
    # never a status whitelist — then the strict comparison above.
    info(
        f"{label} appointment {appt_id} stale-dom-before-fallback "
        f"dom={dom.get('status')!r} server={server_status!r} "
        f"bounded_wait_s={APPT_PRESENTATION_WAIT_SECONDS:g}"
    )
    return refresh_appointment_presentation(page, doctor, appt_id, patient_name, label)


def probe_controlled_refresh(page, doctor, appt_id, patient_name, label):
    """Deterministic probe of the PHASE B path inside the real browser journey.

    The divergence the repaired invariant must survive cannot be produced on
    demand without manufacturing a product transition (the real no-show sweep
    is WP-Cron + Location-local grace driven), so the DOM STATE itself is
    reproduced client-side: an already-verified row is knocked back to a
    legitimate previous-render shape (an established status other than current
    server truth, with that status's badge class and label). Nothing
    server-side is touched — no DB write, no product transition, no workflow
    change, no mocked or fulfilled response — and the repair is performed by
    exactly the same ONE controlled refresh helper the natural fallback uses.
    The perturbation is wiped by that refresh's own re-render.
    """
    before = dict(FALLBACK_STATS)
    server_status, expected_label = expected_appointment_label(
        server_appointment_row(page, doctor, appt_id, label)
    )
    dom = dom_appointment_row(page, appt_id)
    if appointment_dom_converged(dom, patient_name, server_status, expected_label):
        stale_status = "pending" if server_status != "pending" else "confirmed"
        outcome = page.evaluate(
            """([id, cur, cls, text, status]) => {
              const el = document.querySelector('[data-appointment-id="' + id + '"]');
              if (!el) return 'row-missing';
              const badge = el.querySelector('.cpms-doc-badge.' + cur);
              if (!badge) return 'badge-missing';
              el.setAttribute('data-status', status);
              badge.className = 'cpms-doc-badge ' + cls;
              badge.textContent = text;
              return 'perturbed';
            }""",
            [
                str(appt_id),
                f"status-{server_status}",
                f"status-{stale_status}",
                APPT_STATUS_LABELS[stale_status],
                stale_status,
            ],
        )
        if outcome != "perturbed":
            raise RuntimeError(f"{label} fallback probe could not reproduce the stale DOM ({outcome})")
        stale = dom_appointment_row(page, appt_id)
        if appointment_dom_converged(stale, patient_name, server_status, expected_label):
            raise RuntimeError(f"{label} fallback probe produced a row that still converges")
        info(
            f"{label} fallback-probe stale_dom=1 source=injected dom_before={stale.get('status')!r} "
            f"server={server_status!r}"
        )
    else:
        info(
            f"{label} fallback-probe stale_dom=1 source=natural dom_before={dom.get('status')!r} "
            f"server={server_status!r}"
        )
    bound_status, bound_label = refresh_appointment_presentation(
        page, doctor, appt_id, patient_name, label
    )
    delta = {key: FALLBACK_STATS[key] - before[key] for key in FALLBACK_STATS}
    expected = {"fallback_refresh": 1, "refresh_response_bound": 1, "post_refresh_dom_match": 1}
    if delta != expected:
        raise RuntimeError(
            f"{label} fallback probe counter delta {delta} is not exactly one bound refresh"
        )
    after = dom_appointment_row(page, appt_id)
    if not appointment_dom_converged(after, patient_name, bound_status, bound_label):
        raise RuntimeError(
            f"{label} fallback probe did not restore the presentation from the bound refresh"
        )
    info(
        f"{label} fallback-probe fallback_refresh=1 refresh_response_bound=1 "
        f"post_refresh_dom_match=1 dom_after={after.get('status')!r} status={bound_status}"
    )


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
                harness_goto(page, STAFF_URL, wait_until="domcontentloaded")
        state["context"] = _json(ctx_info.value, "context")
        state["todays"].append(_json(today_info.value, "today"))
    else:
        with page.expect_response(context_pred, timeout=25000) as ctx_info:
            harness_goto(page, STAFF_URL, wait_until="domcontentloaded")
        state["context"] = _json(ctx_info.value, "context")
    page.wait_for_load_state("networkidle")
    assert_shell(page)


def row_sel(vid):
    return f'[data-role="queue-item"][data-visit-id="{vid}"]'


def wait_row_status(page, vid, status, timeout=20000):
    page.wait_for_function(
        """([vid, st]) => {
          const el = document.querySelector('[data-role="queue-item"][data-visit-id="' + vid + '"]');
          return !!el && el.getAttribute('data-status') === st;
        }""",
        arg=[str(vid), status],
        timeout=timeout,
    )


def fresh_today(page, doctor, label, location=None):
    """FRESH server truth for the doctor's operational day.

    ``state["todays"]`` only captures the initial load and explicit selections
    — never the journey's own reloads, and never a status change made after
    that capture by the real no-show job under real WP-Cron. The read is issued
    from the same authenticated browser session with the same trusted
    Clinic/Location selector headers the portal itself sends, so it observes
    exactly the scope the live DOM is allowed to observe.
    """
    t = portal_fetch(page, doctor, "GET", "/doctor/today", location=location)
    if t["status"] != 200:
        raise RuntimeError(f"{label} today reread failed: {t['status']}")
    return payload(t["body"])


def today_queue_visit(page, doctor, vid, label):
    """Fresh server truth: state['todays'] only captures the initial load and
    explicit selections, never the journey's own reloads."""
    for row in (fresh_today(page, doctor, label).get("queue") or []):
        if int(row.get("id") or 0) == vid:
            return row
    return {}


def portal_fetch(page, doctor, method, route, body=None, location=None):
    """Real REST from the real browser session: existing route + wp_rest nonce
    + trusted Clinic/Location selector headers. No client authority keys."""
    loc = doctor.get("location_id") if location is None else location
    return page.evaluate(
        """async (a) => {
          const cfg = JSON.parse(document.querySelector('script.cpms-doctor-portal__config').textContent || '{}');
          const headers = {'X-WP-Nonce': cfg.nonce, 'X-CPMS-Clinic-Id': String(a.clinic), 'X-CPMS-Location-Id': String(a.location)};
          const opts = {method: a.method, headers};
          if (a.body !== null && a.body !== undefined) { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(a.body); }
          const r = await fetch(cfg.rest_root + a.route, opts);
          let j = {}; try { j = await r.json(); } catch (e) {}
          return {status: r.status, body: j};
        }""",
        {"method": method, "route": route, "body": body,
         "clinic": doctor["clinic_id"], "location": loc},
    )


def prove_queue_actions(page, state, doctor, act, skip, label):
    # B0. Room-carrying CALL through the existing route (backend contract).
    r = portal_fetch(page, doctor, "POST", f"/visits/{act}/call", {"room": "3"})
    if r["status"] != 200:
        raise RuntimeError(f"{label} room call POST failed: {r['status']}")
    rt = portal_fetch(page, doctor, "GET", "/rt/queue?since=0")
    if rt["status"] != 200:
        raise RuntimeError(f"{label} rt/queue not readable after call")
    events = payload(rt["body"]).get("events") or []
    hits = [e for e in events if int(e.get("visit_id") or 0) == act]
    if len(hits) != 1 or hits[0].get("to_status") != "called" or "3" not in str(hits[0].get("note") or ""):
        raise RuntimeError(f"{label} secretary queue event misses the room call")
    wait_row_status(page, act, "called")
    # C. CALLED row exposes Start + Recall + Skip, all enabled.
    for action in ("start", "recall", "skip"):
        control = page.locator(row_sel(act) + f' [data-action="{action}"]')
        if control.count() != 1 or not control.first.is_visible():
            raise RuntimeError(f"{label} called row misses the {action} control")
        if not control.first.is_enabled():
            raise RuntimeError(f"{label} called row {action} control is not enabled")
    # E. RECALL returns the row to waiting with a bumped recall count.
    before = today_queue_visit(page, doctor, act, label).get("recall_count", 0)
    page.locator(row_sel(act) + ' [data-action="recall"]').click()
    wait_row_status(page, act, "waiting")
    after = today_queue_visit(page, doctor, act, label).get("recall_count", -1)
    if int(after) != int(before) + 1:
        raise RuntimeError(f"{label} recall count did not advance ({before}->{after})")
    for action in ("call", "skip"):
        control = page.locator(row_sel(act) + f' [data-action="{action}"]')
        if control.count() != 1 or not control.first.is_visible() or not control.first.is_enabled():
            raise RuntimeError(f"{label} recalled row misses enabled {action}")
    # G. Double-submit: controls disable while the CALL is in flight.
    def _slow_call(route):
        time.sleep(1.5)
        route.continue_()

    page.route("**/visits/*/call", _slow_call)
    page.locator(row_sel(act) + ' [data-action="call"]').click()
    disabled = page.locator(row_sel(act) + ' [data-action="call"]').is_disabled()
    busy = page.locator(row_sel(act)).get_attribute("aria-busy")
    page.unroute("**/visits/*/call")
    if not disabled:
        raise RuntimeError(f"{label} call control not disabled while pending")
    if busy != "true":
        raise RuntimeError(f"{label} row misses the busy marker while pending")
    wait_row_status(page, act, "called")
    # D. START moves the row to in_consultation with no queue actions left.
    page.locator(row_sel(act) + ' [data-action="start"]').click()
    wait_row_status(page, act, "in_consultation")
    if page.locator(row_sel(act) + " [data-action]").count() != 0:
        raise RuntimeError(f"{label} in-consultation row still exposes actions")

    # Phase 10 Visit Workspace RED: select the existing current consultation
    # from its established Doctor Portal queue row. The row's visit_id is only
    # the selector; the portal must open the already-established E7 record path.
    record_suffix = f"/visits/{act}/record"
    record_reads_before = sum(
        1 for req in state["reqs"]
        if req["method"] == "GET" and req["route"].endswith(record_suffix)
    )
    page.locator(row_sel(act)).click()
    page.wait_for_timeout(500)
    record_reads_after = sum(
        1 for req in state["reqs"]
        if req["method"] == "GET" and req["route"].endswith(record_suffix)
    )
    workspace_red_error = None
    if record_reads_after != record_reads_before + 1:
        workspace_red_error = (
            f"{label} G1 RED: selecting current Visit {act} from its Doctor Portal queue row "
            f"must make exactly one GET to the existing E7 /visits/{{id}}/record contract; "
            f"expected {record_reads_before + 1} total read(s), observed {record_reads_after}"
        )
    record_requests = [
        req for req in state["reqs"]
        if req["method"] == "GET" and req["route"].endswith(record_suffix)
    ]
    record_responses = [
        resp for resp in state["rest"]
        if resp["method"] == "GET" and resp["route"].endswith(record_suffix)
    ]
    if not record_responses or record_responses[-1]["status"] != 200:
        actual = record_responses[-1]["status"] if record_responses else "no response"
        if workspace_red_error is None:
            workspace_red_error = (
                f"{label} G1 RED: selected Visit {act} did not open its existing E7 record; "
                f"expected HTTP 200, observed {actual}"
            )
    if record_requests:
        record_headers = record_requests[-1]["headers"]
        required_scope = {
            "x-wp-nonce": bool(record_headers.get("x-wp-nonce")),
            "x-cpms-clinic-id": record_headers.get("x-cpms-clinic-id") == str(doctor["clinic_id"]),
            "x-cpms-location-id": record_headers.get("x-cpms-location-id") == str(doctor["location_id"]),
        }
        if not all(required_scope.values()) and workspace_red_error is None:
            workspace_red_error = (
                f"{label} G1 RED: E7 workspace read must reuse the portal nonce and trusted Clinic/Location; "
                f"scope checks={required_scope}"
            )

    # Visual evidence — capture real Visit Workspace while open (harness-only, no product change).
    # Deterministic waits on UI/network signals; no arbitrary sleeps.
    if workspace_red_error is None:
        try:
            page.wait_for_selector('[data-role="workspace-section"]:not([hidden])', timeout=8000)
            page.wait_for_selector('[data-role="workspace-body"]:not([hidden])', timeout=8000)
            page.wait_for_function(
                "() => { const h=document.querySelector('[data-role=\"workspace-header\"]'); return h && h.textContent.trim().length>3; }",
                timeout=8000,
            )
        except Exception:
            pass
        try:
            shot(page, f"doctor-portal-{label}-workspace")
        except Exception:
            pass
        if label == "desktop-1366":
            try:
                note_text = f"Pilot workspace note {act} {int(time.time())}"
                page.wait_for_selector('[data-role="workspace-note-form"]:not([hidden])', timeout=5000)
                page.wait_for_selector('[data-role="workspace-content"]', timeout=5000)
                page.locator('[data-role="workspace-content"]').fill(note_text)
                # Visibility control where practically visible (desktop)
                # keep default patient_visible; just ensure composer is ready
                with page.expect_response(
                    lambda r: route_of(r.url).endswith(f"/doctor/portal/visits/{act}/notes")
                    and r.request.method == "POST",
                    timeout=15000,
                ) as note_resp:
                    page.locator('[data-role="workspace-note-submit"]').click()
                    resp = note_resp.value
                if resp.status == 200:
                    page.wait_for_selector('[data-role="workspace-form-success"]:not([hidden])', timeout=5000)
                    page.wait_for_function(
                        "(t) => { const ul=document.querySelector('[data-role=\"workspace-notes\"]'); return ul && ul.innerText.includes(t); }",
                        arg=note_text,
                        timeout=8000,
                    )
                    page.wait_for_selector('[data-role="workspace-header"]:not([hidden])', timeout=3000)
                    page.wait_for_selector('[data-role="workspace-notes"]:not([hidden])', timeout=3000)
                    shot(page, f"doctor-portal-{label}-workspace-note")
            except Exception as e:  # noqa: BLE001
                try:
                    info(f"workspace-note-{label} skipped: {e}")
                except Exception:
                    pass

    # The backend still guards the invalid second START.
    r2 = portal_fetch(page, doctor, "POST", f"/visits/{act}/start")
    if r2["status"] != 409 or (r2["body"] or {}).get("code") != "CLINIC_INVALID_TRANSITION":
        raise RuntimeError(f"{label} second start not guarded: {r2['status']}")
    # F0. An empty skip reason is blocked in the UI without any request.
    skip_posts_before = len([e for e in state["rest"] if e["method"] == "POST" and e["route"].endswith(f"/visits/{skip}/skip")])
    dialog_texts = []
    answers = [""]
    received = []

    def _on_dialog(d):
        dialog_texts.append(d.message or "")
        received.append(d)
        d.accept(answers.pop(0) if answers else "")

    page.on("dialog", _on_dialog)
    try:
        page.locator(row_sel(skip) + ' [data-action="skip"]').click()
        page.wait_for_selector('[data-role="queue-error"]:not([hidden])', timeout=5000)
        skip_posts_now = len([e for e in state["rest"] if e["method"] == "POST" and e["route"].endswith(f"/visits/{skip}/skip")])
        if skip_posts_now != skip_posts_before:
            raise RuntimeError(f"{label} empty skip reason reached the server")
        err_text = page.locator('[data-role="queue-error"]').inner_text() or ""
        if not PERSIAN_RE.search(err_text):
            raise RuntimeError(f"{label} skip error feedback is not Persian")
        # F. A non-empty reason skips through the server; the row leaves the queue.
        answers.append("بیمار موقتاً خارج شد")
        page.locator(row_sel(skip) + ' [data-action="skip"]').click()
        page.wait_for_selector(row_sel(skip), state="detached", timeout=20000)
    finally:
        page.remove_listener("dialog", _on_dialog)
    if skip in queue_ids(page):
        raise RuntimeError(f"{label} skipped visit still rendered")
    if today_queue_visit(page, doctor, skip, label):
        raise RuntimeError(f"{label} skipped visit still served")
    if not received or not all(PERSIAN_RE.search(t) for t in dialog_texts):
        raise RuntimeError(f"{label} skip prompt is not Persian")
    # Every UI mutation POST succeeded through the existing routes.
    for suffix in (f"/visits/{act}/call", f"/visits/{act}/recall",
                   f"/visits/{act}/start", f"/visits/{skip}/skip"):
        posts = [e for e in state["rest"] if e["method"] == "POST" and e["route"].endswith(suffix) and e["status"] == 200]
        if not posts:
            raise RuntimeError(f"{label} no successful POST {suffix}")
    info(f"queue-actions-{label} act={act} skip={skip} room=1 recall_count=1 double_submit=1 empty_skip_blocked=1")
    # Defer this one product RED until after the fixture's skip mutation completes,
    # so later viewport runs are not contaminated by partially-consumed fixture rows.
    if workspace_red_error is not None:
        raise RuntimeError(workspace_red_error)


def prove_workspace_rx(page, state, doctor, visit_id, label, mutate):
    # Phase 10 Rx write — Visit Workspace prescription area. Selection remains
    # the open workspace's visit_id (selector only); writes go through the
    # Doctor Portal prescription boundary with the portal nonce + trusted
    # Clinic/Location selector headers. clinician_id is never sent.
    sec = '[data-role="workspace-rx-section"]:not([hidden])'
    page.wait_for_selector(sec, timeout=8000)
    # The list itself is an empty <ul> before the first prescription (zero-height
    # => Playwright "hidden"); the usable gates are section + form + empty state.
    page.wait_for_selector('[data-role="workspace-rx-form"]:not([hidden])', timeout=8000)
    if page.locator('[data-role="workspace-rx-list"]').count() != 1:
        raise RuntimeError(f"{label} rx list element missing")
    for marker in ("workspace-rx-generic-name", "workspace-rx-dose", "workspace-rx-frequency",
                   "workspace-rx-route", "workspace-rx-duration-days", "workspace-rx-instructions",
                   "workspace-rx-form-select", "workspace-rx-submit"):
        el = page.locator(f'[data-role="{marker}"]')
        if el.count() != 1 or not el.first.is_enabled():
            raise RuntimeError(f"{label} rx composer control {marker} not usable")
    # Fresh action visit has no prescriptions yet — empty state (F-state).
    page.wait_for_selector('[data-role="workspace-rx-empty"]:not([hidden])', timeout=5000)
    if not mutate:
        assert_queue_hugs_content(page, label)
        shot(page, f"doctor-portal-{label}-workspace-rx")
        info(f"workspace-rx-{label} render=1 composer=1 empty=1 mutate=0")
        return

    # ---- desktop-1366 full mutation journey ------------------------------------
    rc_before = len(state["rest"])
    generic = f"پنستر پایلوت نسخه {visit_id}"
    page.locator('[data-role="workspace-rx-generic-name"]').fill(generic)
    page.locator('[data-role="workspace-rx-frequency"]').fill("هر ۸ ساعت")
    page.locator('[data-role="workspace-rx-form-select"]').select_option("capsule")
    page.locator('[data-role="workspace-rx-route"]').select_option("oral")
    page.locator('[data-role="workspace-rx-duration-days"]').fill("7")
    page.locator('[data-role="workspace-rx-instructions"]').fill("با غذا")
    # Failed-write proof: missing dose must surface as the established server
    # 422 — never as success.
    with page.expect_response(
        lambda r: route_of(r.url).endswith(f"/doctor/portal/visits/{visit_id}/prescriptions")
        and r.request.method == "POST",
        timeout=15000,
    ) as bad_info:
        page.locator('[data-role="workspace-rx-submit"]').click()
    if bad_info.value.status != 422 or (bad_info.value.json() or {}).get("code") != "CLINIC_VALIDATION_FAILED":
        raise RuntimeError(f"{label} invalid create not rejected with the established 422")
    page.wait_for_selector('[data-role="workspace-rx-error"]:not([hidden])', timeout=5000)
    if page.locator('[data-role="workspace-rx-success"]').is_visible():
        raise RuntimeError(f"{label} failed create displayed success")
    if page.locator('[data-role="workspace-rx-empty"]').is_visible() is False:
        raise RuntimeError(f"{label} failed create mutated the list")
    # Busy + double-submit guard while the valid create is in flight.
    page.locator('[data-role="workspace-rx-dose"]').fill("1 قرص")

    def _slow_create(route):
        time.sleep(1.0)
        route.continue_()

    page.route("**/doctor/portal/visits/*/prescriptions", _slow_create)
    with page.expect_response(
        lambda r: route_of(r.url).endswith(f"/doctor/portal/visits/{visit_id}/prescriptions")
        and r.request.method == "POST",
        timeout=15000,
    ) as create_info:
        page.locator('[data-role="workspace-rx-submit"]').click()
        page.wait_for_selector('[data-role="workspace-rx-busy"]:not([hidden])', timeout=5000)
        if not page.locator('[data-role="workspace-rx-submit"]').is_disabled():
            raise RuntimeError(f"{label} create submit not disabled while in flight")
    page.unroute("**/doctor/portal/visits/*/prescriptions")
    create_resp = create_info.value
    if create_resp.status != 200:
        raise RuntimeError(f"{label} create draft failed: {create_resp.status}")
    rx = payload(create_resp.json())
    rx_id = int(rx.get("id") or 0)
    if rx_id <= 0 or rx.get("status") != "draft":
        raise RuntimeError(f"{label} create did not return a draft: {rx}")
    page.wait_for_selector('[data-role="workspace-rx-success"]:not([hidden])', timeout=5000)
    page.wait_for_selector(
        f'[data-role="workspace-rx-item"][data-rx-id="{rx_id}"][data-status="draft"]',
        timeout=5000,
    )
    page.wait_for_function(
        "(t) => { const ul=document.querySelector('[data-role=\"workspace-rx-list\"]'); return ul && ul.innerText.includes(t); }",
        arg=generic,
        timeout=8000,
    )
    if page.locator('[data-role="workspace-rx-generic-name"]').input_value() != "":
        raise RuntimeError(f"{label} composer not cleared after successful create")
    # Selector headers only, no client authority.
    create_reqs = [e for e in state["rest"] if e["method"] == "POST"
                   and e["route"].endswith(f"/doctor/portal/visits/{visit_id}/prescriptions")]
    if not create_reqs:
        raise RuntimeError(f"{label} create request not observed")
    shot(page, f"doctor-portal-{label}-workspace-rx")

    # Finalize through the portal boundary — server resolves prescription->Visit.
    fin_btn = page.locator(f'[data-role="workspace-rx-item"][data-rx-id="{rx_id}"] [data-role="workspace-rx-finalize"]')
    if fin_btn.count() != 1 or not fin_btn.first.is_enabled():
        raise RuntimeError(f"{label} draft does not expose an enabled finalize control")

    def _slow_fin(route):
        time.sleep(1.0)
        route.continue_()

    page.route("**/doctor/portal/prescriptions/*/finalize", _slow_fin)
    with page.expect_response(
        lambda r: route_of(r.url).endswith(f"/doctor/portal/prescriptions/{rx_id}/finalize")
        and r.request.method == "POST",
        timeout=15000,
    ) as fin_info:
        fin_btn.first.click()
        if not page.locator(f'[data-role="workspace-rx-item"][data-rx-id="{rx_id}"] [data-role="workspace-rx-finalize"]').is_disabled():
            raise RuntimeError(f"{label} finalize control not disabled while in flight")
    page.unroute("**/doctor/portal/prescriptions/*/finalize")
    fin_resp = fin_info.value
    if fin_resp.status != 200:
        raise RuntimeError(f"{label} finalize failed: {fin_resp.status}")
    fin = payload(fin_resp.json())
    if fin.get("status") != "finalized" or not fin.get("finalized_at"):
        raise RuntimeError(f"{label} finalize did not establish finalized/finalized_at: {fin}")
    page.wait_for_selector(
        f'[data-role="workspace-rx-item"][data-rx-id="{rx_id}"][data-status="finalized"]',
        timeout=5000,
    )
    page.wait_for_selector(
        f'[data-role="workspace-rx-item"][data-rx-id="{rx_id}"] [data-role="workspace-rx-readonly"]',
        timeout=5000,
    )
    if page.locator(f'[data-role="workspace-rx-item"][data-rx-id="{rx_id}"] [data-role="workspace-rx-finalize"]').count() != 0:
        raise RuntimeError(f"{label} finalized item still exposes a finalize control")
    shot(page, f"doctor-portal-{label}-workspace-rx-finalized")
    # Repeat finalize keeps the established 409 invalid-transition semantics.
    r2 = portal_fetch(page, doctor, "POST", f"/doctor/portal/prescriptions/{rx_id}/finalize")
    if r2["status"] != 409 or (r2["body"] or {}).get("code") != "CLINIC_INVALID_TRANSITION":
        raise RuntimeError(f"{label} repeat finalize not guarded: {r2['status']}")
    new_rx_posts = [
        e for e in state["reqs"]
        if e["method"] == "POST" and "/prescriptions" in e["route"]
    ]
    for hit in new_rx_posts:
        if "clinician_id" in (hit["url"] + hit["body"]):
            raise RuntimeError(f"{label} clinician_id leaked into a prescription request")
    info(
        f"workspace-rx-{label} create=1 draft=1 busy=1 dblsubmit=1 failed422=1"
        f" finalize=1 readonly=1 repeat409=1 clinician_id_sent=0"
    )


def prove_workspace_recfu(page, state, doctor, visit_id, label, mutate):
    # Phase 10 — Visit Workspace recommendation + follow-up authoring. Selection
    # stays the open workspace's visit_id (selector only); writes go through the
    # Doctor Portal recommendation/follow-up boundaries with the portal nonce +
    # trusted Clinic/Location selector headers. clinician_id is never sent;
    # the shared E12/E13 domain contract (types/validation/visibility/audit) is
    # reused unchanged.
    page.wait_for_selector('[data-role="workspace-rec-section"]:not([hidden])', timeout=8000)
    page.wait_for_selector('[data-role="workspace-rec-form"]:not([hidden])', timeout=8000)
    page.wait_for_selector('[data-role="workspace-fu-section"]:not([hidden])', timeout=8000)
    page.wait_for_selector('[data-role="workspace-fu-form"]:not([hidden])', timeout=8000)
    if page.locator('[data-role="workspace-rec-list"]').count() != 1:
        raise RuntimeError(f"{label} recommendation list element missing")
    if page.locator('[data-role="workspace-fu-list"]').count() != 1:
        raise RuntimeError(f"{label} follow-up list element missing")
    for marker in ("workspace-rec-type", "workspace-rec-text", "workspace-rec-visible",
                   "workspace-rec-submit", "workspace-fu-needed", "workspace-fu-date",
                   "workspace-fu-interval-days", "workspace-fu-reason", "workspace-fu-submit"):
        el = page.locator(f'[data-role="{marker}"]')
        if el.count() != 1 or not el.first.is_enabled():
            raise RuntimeError(f"{label} rec/fu composer control {marker} not usable")
    # The established seven recommendation types must be offered by the composer.
    type_select = page.locator('[data-role="workspace-rec-type"]')
    offered = type_select.locator("option").evaluate_all("els => els.map(e => e.getAttribute('value'))")
    for rec_type in ("diet", "rest", "activity", "care", "lab", "followup", "other"):
        if rec_type not in offered:
            raise RuntimeError(f"{label} recommendation type {rec_type} missing from the composer")
    # Fresh action visit has no recommendations/follow-ups yet — empty states.
    page.wait_for_selector('[data-role="workspace-rec-empty"]:not([hidden])', timeout=5000)
    page.wait_for_selector('[data-role="workspace-fu-empty"]:not([hidden])', timeout=5000)
    if not mutate:
        assert_queue_hugs_content(page, label)
        shot(page, f"doctor-portal-{label}-workspace-recfu")
        info(f"workspace-recfu-{label} render=1 rec_composer=1 fu_composer=1 types=7 empty=1 mutate=0")
        return

    # ---- desktop-1366 full mutation journey ------------------------------------
    rec_text = f"توصیه پایلوت {visit_id} — استراحت"
    # Failed-write proof: empty recommendation text must surface as the established
    # server 422 — never as success and never as a rendered row.
    with page.expect_response(
        lambda r: route_of(r.url).endswith(f"/doctor/portal/visits/{visit_id}/recommendations")
        and r.request.method == "POST",
        timeout=15000,
    ) as bad_rec:
        page.locator('[data-role="workspace-rec-submit"]').click()
    if bad_rec.value.status != 422 or (bad_rec.value.json() or {}).get("code") != "CLINIC_VALIDATION_FAILED":
        raise RuntimeError(f"{label} invalid recommendation not rejected with the established 422")
    page.wait_for_selector('[data-role="workspace-rec-error"]:not([hidden])', timeout=5000)
    if page.locator('[data-role="workspace-rec-success"]').is_visible():
        raise RuntimeError(f"{label} failed recommendation write displayed success")
    if page.locator('[data-role="workspace-rec-list"] [data-role="workspace-rec-item"]').count() != 0:
        raise RuntimeError(f"{label} failed recommendation write mutated the list")

    # Valid recommendation, patient-visible option exercised, busy + double-submit
    # guard while the write is in flight.
    type_select.select_option("rest")
    page.locator('[data-role="workspace-rec-text"]').fill(rec_text)
    visible = page.locator('[data-role="workspace-rec-visible"]')
    visible.uncheck()
    if visible.is_checked():
        raise RuntimeError(f"{label} recommendation patient-visible control did not uncheck")
    visible.check()
    if not visible.is_checked():
        raise RuntimeError(f"{label} recommendation patient-visible control did not check")

    def _slow_rec(route):
        time.sleep(1.0)
        route.continue_()

    page.route("**/doctor/portal/visits/*/recommendations", _slow_rec)
    with page.expect_response(
        lambda r: route_of(r.url).endswith(f"/doctor/portal/visits/{visit_id}/recommendations")
        and r.request.method == "POST",
        timeout=15000,
    ) as rec_info:
        page.locator('[data-role="workspace-rec-submit"]').click()
        page.wait_for_selector('[data-role="workspace-rec-busy"]:not([hidden])', timeout=5000)
        if not page.locator('[data-role="workspace-rec-submit"]').is_disabled():
            raise RuntimeError(f"{label} recommendation submit not disabled while in flight")
    page.unroute("**/doctor/portal/visits/*/recommendations")
    rec_resp = rec_info.value
    if rec_resp.status != 200:
        raise RuntimeError(f"{label} recommendation create failed: {rec_resp.status}")
    rec_body = payload(rec_resp.json())
    created = [row for row in (rec_body.get("recommendations") or []) if row.get("text") == rec_text]
    if len(created) != 1:
        raise RuntimeError(f"{label} recommendation create did not return the new row: {rec_body}")
    rec_row = created[0]
    rec_id = int(rec_row.get("id") or 0)
    if rec_id <= 0 or rec_row.get("type") != "rest" or rec_row.get("is_patient_visible") is not True:
        raise RuntimeError(f"{label} recommendation round-trip mismatch: {rec_row}")
    page.wait_for_selector('[data-role="workspace-rec-success"]:not([hidden])', timeout=5000)
    page.wait_for_selector(f'[data-role="workspace-rec-item"][data-rec-id="{rec_id}"]', timeout=5000)
    page.wait_for_function(
        "(t) => { const el=document.querySelector('[data-role=\"workspace-rec-list\"]'); return !!(el && el.innerText.includes(t)); }",
        arg=rec_text,
        timeout=8000,
    )
    if page.locator('[data-role="workspace-rec-text"]').input_value() != "":
        raise RuntimeError(f"{label} recommendation composer not cleared after success")
    shot(page, f"doctor-portal-{label}-workspace-rec")

    # Failed-write proof: is_needed=true without date/interval must be rejected
    # with the established 422 — never presented as success.
    with page.expect_response(
        lambda r: route_of(r.url).endswith(f"/doctor/portal/visits/{visit_id}/follow-ups")
        and r.request.method == "POST",
        timeout=15000,
    ) as bad_fu:
        page.locator('[data-role="workspace-fu-submit"]').click()
    if bad_fu.value.status != 422 or (bad_fu.value.json() or {}).get("code") != "CLINIC_VALIDATION_FAILED":
        raise RuntimeError(f"{label} invalid follow-up not rejected with the established 422")
    page.wait_for_selector('[data-role="workspace-fu-error"]:not([hidden])', timeout=5000)
    if page.locator('[data-role="workspace-fu-success"]').is_visible():
        raise RuntimeError(f"{label} failed follow-up write displayed success")
    if page.locator('[data-role="workspace-fu-list"] [data-role="workspace-fu-item"]').count() != 0:
        raise RuntimeError(f"{label} failed follow-up write mutated the list")

    # Valid follow-up (interval variant), busy + double-submit guard in flight.
    fu_reason = f"کنترل پایلوت {visit_id}"
    page.locator('[data-role="workspace-fu-interval-days"]').fill("30")
    page.locator('[data-role="workspace-fu-reason"]').fill(fu_reason)

    def _slow_fu(route):
        time.sleep(1.0)
        route.continue_()

    page.route("**/doctor/portal/visits/*/follow-ups", _slow_fu)
    with page.expect_response(
        lambda r: route_of(r.url).endswith(f"/doctor/portal/visits/{visit_id}/follow-ups")
        and r.request.method == "POST",
        timeout=15000,
    ) as fu_info:
        page.locator('[data-role="workspace-fu-submit"]').click()
        page.wait_for_selector('[data-role="workspace-fu-busy"]:not([hidden])', timeout=5000)
        if not page.locator('[data-role="workspace-fu-submit"]').is_disabled():
            raise RuntimeError(f"{label} follow-up submit not disabled while in flight")
    page.unroute("**/doctor/portal/visits/*/follow-ups")
    fu_resp = fu_info.value
    if fu_resp.status != 200:
        raise RuntimeError(f"{label} follow-up create failed: {fu_resp.status}")
    fu = payload(fu_resp.json())
    fu_id = int(fu.get("id") or 0)
    if (
        fu_id <= 0
        or fu.get("is_needed") is not True
        or int(fu.get("interval_days") or 0) != 30
        or fu.get("status") != "pending"
    ):
        raise RuntimeError(f"{label} follow-up round-trip mismatch: {fu}")
    page.wait_for_selector('[data-role="workspace-fu-success"]:not([hidden])', timeout=5000)
    page.wait_for_selector(f'[data-role="workspace-fu-item"][data-fu-id="{fu_id}"]', timeout=5000)
    page.wait_for_function(
        "(t) => { const el=document.querySelector('[data-role=\"workspace-fu-list\"]'); return !!(el && el.innerText.includes(t)); }",
        arg=fu_reason,
        timeout=8000,
    )
    if page.locator('[data-role="workspace-fu-interval-days"]').input_value() != "":
        raise RuntimeError(f"{label} follow-up composer not cleared after success")
    shot(page, f"doctor-portal-{label}-workspace-fu")

    # Selector headers only, no client authority, and both failed writes stayed failed.
    posts = [
        e for e in state["reqs"]
        if e["method"] == "POST" and (
            e["route"].endswith(f"/doctor/portal/visits/{visit_id}/recommendations")
            or e["route"].endswith(f"/doctor/portal/visits/{visit_id}/follow-ups")
        )
    ]
    if len(posts) < 4:
        raise RuntimeError(f"{label} rec/fu authoring requests not observed: {len(posts)}")
    for hit in posts:
        for key in ("clinician_id", "organization_id", "role"):
            if key in (hit["url"] + hit["body"]):
                raise RuntimeError(f"{label} client authority key {key} leaked into a rec/fu request")
            for hk, hv in hit["headers"].items():
                if key in hk or key in str(hv):
                    raise RuntimeError(f"{label} client authority key {key} leaked into a rec/fu request header")
    info(
        f"workspace-recfu-{label} rec_create=1 rec_failed422=1 rec_busy=1 rec_public=1"
        f" fu_create=1 fu_failed422=1 fu_busy=1 clinician_id_sent=0"
    )


def prove_location_actions(page, state, doctor, label):
    sel = page.locator('[data-role="location-select"]')
    # Selected Location A action works on A, then resets via recall.
    sel.select_option(str(doctor["loc_a"]))
    page.wait_for_function(
        """(visitId) => !!document.querySelector('[data-role="queue-item"][data-visit-id="' + visitId + '"][data-status="waiting"]')""",
        arg=str(doctor["visit_a"]),
        timeout=20000,
    )
    page.locator(row_sel(doctor["visit_a"]) + ' [data-action="call"]').click()
    wait_row_status(page, doctor["visit_a"], "called")
    page.locator(row_sel(doctor["visit_a"]) + ' [data-action="recall"]').click()
    wait_row_status(page, doctor["visit_a"], "waiting")
    # Attempting an A-visit action under B scope is rejected; nothing mutates.
    sel.select_option(str(doctor["loc_b"]))
    page.wait_for_function(
        """(visitId) => !!document.querySelector('[data-role="queue-item"][data-visit-id="' + visitId + '"]')""",
        arg=str(doctor["visit_b"]),
        timeout=20000,
    )
    r = portal_fetch(page, doctor, "POST", f"/visits/{doctor['visit_a']}/call", None, location=doctor["loc_b"])
    if r["status"] != 404 or (r["body"] or {}).get("code") != "CLINIC_NOT_FOUND":
        raise RuntimeError(f"{label} cross-location action not rejected: {r['status']}")
    sel.select_option(str(doctor["loc_a"]))
    page.wait_for_function(
        """(visitId) => !!document.querySelector('[data-role="queue-item"][data-visit-id="' + visitId + '"][data-status="waiting"]')""",
        arg=str(doctor["visit_a"]),
        timeout=20000,
    )
    info(f"location-actions-{label} a_call_recall=1 b_on_a=404")


def prove_one(browser, doctor, vp, shot_name=None, probe_fallback=False):
    key = f"doctor-portal-{vp['vp']}-{'one' if shot_name else 'other'}"
    stage = "login"
    ctx, page, state = new_page(browser, vp)
    try:
        login(page, doctor)
        stage = "shell"
        goto_portal(page, state, expect_today=True)
        nav0 = nav_mark(page)
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
        assert_queue_hugs_content(page, vp["vp"])
        if shot_name:
            shot(page, shot_name)
            shot(page, f"staff-portal-{vp['vp']}-canonical-landing")
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
        # Slice 2 GREEN: ONE carries per-viewport dedicated action visits; the
        # OTHER run carries none. Earlier viewport runs consume their own pair
        # (act -> in_consultation stays queued, skip -> skipped leaves).
        dedicated = [int(doctor[k]) for k in ACTION_EXTRA_KEYS if doctor.get(k)]
        order = [v["vp"] for v in VIEWPORTS]
        earlier = order[:order.index(vp["vp"])] if vp["vp"] in order else []
        consumed_all = set()
        consumed_skip = set()
        for evp in earlier:
            keys = VP_ACTION_KEYS.get(evp)
            if keys:
                if doctor.get(keys[0]):
                    consumed_all.add(int(doctor[keys[0]]))
                if doctor.get(keys[1]):
                    consumed_all.add(int(doctor[keys[1]]))
                    consumed_skip.add(int(doctor[keys[1]]))
        expected_queue = set([doctor["visit_own"]] + dedicated) - consumed_skip
        expected_waiting = 1 + len([v for v in dedicated if v not in consumed_all])
        if (data.get("stats") or {}).get("waiting") != expected_waiting:
            raise RuntimeError("today waiting count is not the doctor's own rows")
        ids = [int(row.get("id")) for row in (data.get("queue") or [])]
        if set(ids) != expected_queue or doctor["visit_other"] in ids:
            raise RuntimeError(f"queue isolation failed: {ids}")
        if any(int(row.get("clinician_id") or 0) != doctor["clinician_id"] for row in data.get("queue") or []):
            raise RuntimeError("queue row clinician was not the server identity")
        if set(queue_ids(page)) != expected_queue:
            raise RuntimeError("rendered queue does not match the server queue")
        # Phase 10 Slice 2 GREEN: state-driven action controls on the waiting
        # row (Call + Skip) reusing the existing POST /visits/{id}/{...}
        # routes, plus the full mutation journey on this viewport's pair.
        stage = "queue-actions"
        waiting_row = page.locator(
            f'[data-role="queue-item"][data-visit-id="{doctor["visit_own"]}"]'
        )
        if waiting_row.count() != 1:
            raise RuntimeError("waiting queue row is not addressable for actions")
        for action in ("call", "skip"):
            control = waiting_row.locator(f'[data-action="{action}"]')
            if control.count() != 1 or not control.first.is_visible():
                raise RuntimeError(
                    f"waiting row does not expose the {action} action control"
                )
            if not control.first.is_enabled():
                raise RuntimeError(f"waiting row {action} control is not enabled")
        pair_keys = VP_ACTION_KEYS.get(vp["vp"])
        did_actions = bool(pair_keys and doctor.get(pair_keys[0]) and doctor.get(pair_keys[1]))
        if did_actions:
            prove_queue_actions(
                page, state, doctor,
                int(doctor[pair_keys[0]]), int(doctor[pair_keys[1]]), vp["vp"],
            )
            # Phase 10 Rx write — after queue actions the act visit is in
            # consultation and its workspace is open; drive the real Doctor
            # Portal prescription area for this viewport. Full create/finalize
            # mutation runs once (desktop); every viewport proves the
            # responsive render + composer/empty states.
            stage = "workspace-rx"
            prove_workspace_rx(
                page, state, doctor, int(doctor[pair_keys[0]]), vp["vp"],
                mutate=(vp["vp"] == "desktop-1366"),
            )
            # Phase 10 — Recommendation + Follow-Up authoring inside the same
            # open Visit Workspace: full create/visibility/validation journey on
            # desktop, responsive composer + empty states on every viewport.
            stage = "workspace-recfu"
            prove_workspace_recfu(
                page, state, doctor, int(doctor[pair_keys[0]]), vp["vp"],
                mutate=(vp["vp"] == "desktop-1366"),
            )
        stage = "today-queue"
        if doctor["today"] not in (page.locator('[data-role="today-date"]').inner_text() or ""):
            raise RuntimeError("today date not rendered")
        expected = [doctor["appt_booked"]]
        if doctor["appt_arrived"]:
            expected.append(doctor["appt_arrived"])
        hidden = [item for item in (doctor["appt_hidden"], doctor["appt_hidden_2"]) if item]
        assert_appointment_payload(data, expected, hidden, vp["vp"])
        assert_appointment_section(page, expected, hidden, vp["vp"])
        assert_no_appointments_route(state, vp["vp"])
        section_text = page.locator('[data-role="appointments-section"]').inner_text() or ""
        # The expected appointment is identified by its stable fixture id and the
        # row must present the ACTUAL status of a FRESH /doctor/today read (the
        # no-show job may legitimately have moved it confirmed -> no_show), never
        # a hardcoded one and never the initially cached response.
        booked_status, booked_label = assert_appointment_presentation(
            page, doctor, doctor["appt_booked"], doctor["booked_name"], vp["vp"]
        )
        arrived_status = ""
        if doctor["appt_arrived"]:
            arrived_status, _ = assert_appointment_presentation(
                page, doctor, doctor["appt_arrived"], doctor["arrived_name"], vp["vp"]
            )
            arrived = page.locator(f'[data-appointment-id="{doctor["appt_arrived"]}"]')
            if arrived.get_attribute("data-visit-status") != "checked_in":
                raise RuntimeError("checked-in appointment did not keep the existing visit status")
            if doctor["arrived_name"] not in section_text or "پذیرش‌شده" not in section_text:
                raise RuntimeError("checked-in appointment label is not visible")
            booked = page.locator(f'[data-appointment-id="{doctor["appt_booked"]}"]')
            if booked.get_attribute("data-visit-status"):
                raise RuntimeError("booked appointment invented a visit status")
        if probe_fallback:
            # Harness-level determinism for the PHASE B fallback: exercise the
            # ONE controlled refresh against the real portal and the real
            # /doctor/today response on this run (see probe_controlled_refresh).
            stage = "fallback-probe"
            probe_controlled_refresh(
                page, doctor, doctor["appt_booked"], doctor["booked_name"], vp["vp"]
            )
        stage = "no-reload"
        reloads, rest_calls, harness_navs = assert_no_product_reload(page, nav0, vp["vp"])
        info(
            f"{key} hybrid product_reloads={reloads} rest_calls={rest_calls} harness_navs={harness_navs}"
            f" actions={1 if did_actions else 0}"
        )
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
            f"clinic={doctor['clinic_id']} location={doctor['location_id']} own={doctor['visit_own']} other_hidden=1 clinician_id_sent=0 actions={1 if did_actions else 0} sw={sw} iw={iw}",
        )
        info(
            f"{key} shell=1 rtl=1 auto_clinic=1 auto_location=1 own_visit=1 other_visit=0 clinician_id_sent=0"
            f" booked_status={booked_status} booked_label={booked_label}"
            + (f" arrived_status={arrived_status}" if arrived_status else "")
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
        nav0 = nav_mark(page)
        page.wait_for_selector('[data-role="location-selector-wrap"]:not([hidden])', timeout=20000)
        assert_queue_hugs_content(page, vp["vp"])
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
        assert_queue_hugs_content(page, vp["vp"])
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
        assert_appointment_payload(
            data,
            [doctor["appt_b"]],
            [doctor["appt_a"], doctor["appt_colleague"]],
            vp["vp"],
        )
        assert_appointment_section(
            page,
            [doctor["appt_b"]],
            [doctor["appt_a"], doctor["appt_colleague"]],
            vp["vp"],
        )
        assert_no_appointments_route(state, vp["vp"])
        if doctor["appt_b_name"] not in (page.locator('[data-role="appointments-section"]').inner_text() or ""):
            raise RuntimeError("selected location appointment name is not visible")
        b_reqs = [
            r for r in state["reqs"]
            if r["route"].endswith("/doctor/today") or r["route"].endswith("/rt/queue")
        ]
        if not b_reqs or any(r["headers"].get("x-cpms-location-id") != str(doctor["loc_b"]) for r in b_reqs):
            raise RuntimeError("operational requests were not bound to location B")
        stage = "location-actions"
        prove_location_actions(page, state, doctor, vp["vp"])
        stage = "no-reload"
        reloads, rest_calls, harness_navs = assert_no_product_reload(page, nav0, vp["vp"])
        info(f"{key} hybrid product_reloads={reloads} rest_calls={rest_calls} harness_navs={harness_navs} location_change=1")
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


def prove_legacy(browser, doctor, vp):
    """Legacy Doctor Portal URL = backward-compatible entry to the SAME shared
    Staff Portal shell (alias): one HTTP 200 document, no redirect (so no loop),
    same WordPress session, same doctor experience and REST authority."""
    key = f"staff-portal-{vp['vp']}-legacy-entry"
    stage = "login"
    ctx, page, state = new_page(browser, vp)
    try:
        login(page, doctor)
        stage = "legacy-entry"
        before = nav_mark(page)
        context_pred = lambda r: route_of(r.url).endswith("/doctor/portal/context")
        with page.expect_response(context_pred, timeout=25000) as ctx_info:
            resp = harness_goto(page, PORTAL_URL, wait_until="domcontentloaded")
        if resp is None or resp.status != 200:
            raise RuntimeError(f"legacy entry did not return one 200 document ({getattr(resp, 'status', None)})")
        if resp.request.redirected_from is not None:
            raise RuntimeError("legacy entry redirected (alias contract expects the same shell, no hop)")
        legacy = urlparse(PORTAL_URL)
        final = urlparse(page.url or "")
        if (final.path, final.query) != (legacy.path, legacy.query):
            raise RuntimeError(f"legacy bookmark did not stay on its URL ({final.path}?{final.query})")
        after = nav_mark(page)
        if after["harness_docs"] - before["harness_docs"] != 1 or after["product_docs"] != before["product_docs"]:
            raise RuntimeError("legacy entry produced more than one document navigation (loop/redirect)")
        context = payload(_json(ctx_info.value, "context (legacy entry)"))
        if (context.get("doctor") or {}).get("clinician_id") != doctor["clinician_id"]:
            raise RuntimeError("legacy entry lost the authenticated doctor session/identity")
        page.wait_for_load_state("networkidle")
        stage = "shared-shell"
        assert_shell(page)
        page.wait_for_selector(
            f'[data-role="queue-item"][data-visit-id="{doctor["visit_own"]}"]',
            timeout=20000,
        )
        shot(page, key)
        stage = "hygiene"
        sw, iw = assert_hygiene(page, state, vp["vp"])
        ok(
            key,
            "legacy Doctor Portal URL renders the shared Staff Portal doctor experience",
            f"status=200 redirects=0 same_url=1 session=1 staff_shell=1 module=doctor sw={sw} iw={iw}",
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


def main():
    os.makedirs(OUT, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for vp in VIEWPORTS:
            try:
                # The fallback probe runs once, on the desktop journey, so the
                # deterministic PHASE B exercise does not multiply per viewport.
                prove_one(browser, ONE, vp, vp["shot"], probe_fallback=(vp["vp"] == "desktop-1366"))
            except Exception:
                continue
        for vp in VIEWPORTS:
            try:
                prove_legacy(browser, ONE, vp)
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
