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
import time
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


def server_appointment_row(state, appt_id):
    """The expected appointment from the /doctor/today body the page rendered."""
    rows = today_data(state).get("appointments")
    if not isinstance(rows, list):
        raise RuntimeError("appointments payload missing from /doctor/today")
    for row in rows:
        if int(row.get("id") or 0) == appt_id:
            return row
    raise RuntimeError(f"appointment {appt_id} is missing from the server payload")


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


def assert_appointment_presentation(page, state, appt_id, patient_name, label):
    """Bind the rendered appointment row to the ACTUAL server-returned status.

    The appointment is identified by its stable fixture id, its status is read
    from the server-returned /doctor/today body the page rendered from, checked
    against the established status contract, mapped through the bounded Doctor
    Portal label contract, and only then compared with the DOM: same patient,
    same ``data-status``, and the ``status-<status>`` badge carrying that label.
    A confirmed -> no_show transition by the no-show job is therefore valid,
    while a missing, hidden, foreign, stale, or unrecognized presentation is
    not. The bounded wait tolerates render latency only, never a mismatch.
    """
    deadline = time.monotonic() + APPT_PRESENTATION_WAIT_SECONDS
    server_status = ""
    expected_label = ""
    dom = {"present": False, "visible": False}
    while True:
        server_status, expected_label = expected_appointment_label(
            server_appointment_row(state, appt_id)
        )
        dom = dom_appointment_row(page, appt_id)
        if (
            dom.get("present")
            and dom.get("visible")
            and dom.get("name") == patient_name
            and dom.get("status") == server_status
            and any(
                f"status-{server_status}" in str(badge.get("cls") or "").split()
                and expected_label in str(badge.get("text") or "")
                for badge in dom.get("badges") or []
            )
        ):
            return server_status, expected_label
        if time.monotonic() >= deadline:
            break
        page.wait_for_timeout(APPT_PRESENTATION_POLL_MS)
    if not dom.get("present"):
        raise RuntimeError(f"{label} expected appointment {appt_id} is not rendered")
    if not dom.get("visible"):
        raise RuntimeError(f"{label} expected appointment {appt_id} is hidden")
    if dom.get("name") != patient_name:
        raise RuntimeError(f"{label} appointment {appt_id} shows the wrong patient")
    if dom.get("status") != server_status:
        raise RuntimeError(
            f"{label} UI/server appointment status mismatch: "
            f"dom={dom.get('status')!r} server={server_status!r}"
        )
    raise RuntimeError(
        f"{label} appointment {appt_id} status {server_status!r} "
        f"is not rendered as its Doctor Portal label"
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
                page.goto(PORTAL_URL, wait_until="domcontentloaded")
        state["context"] = _json(ctx_info.value, "context")
        state["todays"].append(_json(today_info.value, "today"))
    else:
        with page.expect_response(context_pred, timeout=25000) as ctx_info:
            page.goto(PORTAL_URL, wait_until="domcontentloaded")
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


def today_queue_visit(page, doctor, vid, label):
    """Fresh server truth: state['todays'] only captures the initial load and
    explicit selections, never the journey's own reloads."""
    t = portal_fetch(page, doctor, "GET", "/doctor/today")
    if t["status"] != 200:
        raise RuntimeError(f"{label} today reread failed: {t['status']}")
    for row in (payload(t["body"]).get("queue") or []):
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
        assert_queue_hugs_content(page, vp["vp"])
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
        # row must present the ACTUAL server-returned status (the no-show job may
        # legitimately have moved it confirmed -> no_show), never a hardcoded one.
        booked_status, booked_label = assert_appointment_presentation(
            page, state, doctor["appt_booked"], doctor["booked_name"], vp["vp"]
        )
        arrived_status = ""
        if doctor["appt_arrived"]:
            arrived_status, _ = assert_appointment_presentation(
                page, state, doctor["appt_arrived"], doctor["arrived_name"], vp["vp"]
            )
            arrived = page.locator(f'[data-appointment-id="{doctor["appt_arrived"]}"]')
            if arrived.get_attribute("data-visit-status") != "checked_in":
                raise RuntimeError("checked-in appointment did not keep the existing visit status")
            if doctor["arrived_name"] not in section_text or "پذیرش‌شده" not in section_text:
                raise RuntimeError("checked-in appointment label is not visible")
            booked = page.locator(f'[data-appointment-id="{doctor["appt_booked"]}"]')
            if booked.get_attribute("data-visit-status"):
                raise RuntimeError("booked appointment invented a visit status")
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
