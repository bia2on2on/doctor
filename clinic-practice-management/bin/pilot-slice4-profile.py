#!/usr/bin/env python3
"""pilot-slice4-profile.py — Phase 9 Slice 4 Patient Profile on the existing portal pilot.

Uses the production CPMS Patient Portal shell, a real authenticated patient session,
real REST, and the real database. Profile state is not faked in JavaScript.

Fixture env (written by pilot-slice4-profile-fixture.php; not printed):
  PROFILE_ONE=login|pass|user_id|patient_id|link_id|clinic_id
  PROFILE_MULTI=login|pass|user_id|link_a|link_b|patient_a|patient_b|clinic_a|clinic_b|foreign_link|inactive_link

Evidence lines are PASS/FAIL/INFO/SHOT with booleans and non-sensitive ids only.
"""

import json
import os
import re
import subprocess
import sys
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import sync_playwright, expect

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
DB_MAIN = os.environ.get("DB_MAIN", "cpms_main")
DB_PREFIX = os.environ.get("DB_PREFIX", "cpmswp_")
OUT = "pilot-screenshots"
PORTAL_SLUG = "cpms-patient-portal"
PORTAL_PATH = f"/{PORTAL_SLUG}/"
SHELL_ROOT = "#cpms-patient-portal-shell, .cpms-patient-portal-shell, [data-cpms-patient-portal]"
ME_ROUTE = "/clinic/v1/patient/me"
FORBIDDEN_KEYS = {"mobile", "clinic_id", "patient_id", "organization_id", "role", "roles"}
EDITABLE_KEYS = {
    "first_name", "last_name", "national_id", "birth_date", "gender",
    "address", "phone", "emergency_contact_name", "emergency_contact_phone",
}
SAVE_TOKEN = "ADDR-SYN-OK"
INVALID_NID = "0000000000"
PERSIAN_RE = re.compile(r"[\u0600-\u06FF]")

VIEWPORTS = [
    {"vp": "mobile-390", "w": 390, "h": 844},
    {"vp": "tablet-768", "w": 768, "h": 1024},
    {"vp": "laptop-1366", "w": 1366, "h": 768},
]


def _parts(name, n):
    raw = os.environ.get(name, "").strip()
    parts = [p.strip() for p in raw.split("|")]
    if len(parts) < n or not parts[0]:
        raise SystemExit(f"{name} must carry {n} pipe-separated parts")
    return parts


_one = _parts("PROFILE_ONE", 6)
_multi = _parts("PROFILE_MULTI", 11)
ONE = {
    "login": _one[0],
    "password": _one[1],
    "user_id": int(_one[2]),
    "patient_id": int(_one[3]),
    "link_id": int(_one[4]),
    "clinic_id": int(_one[5]),
}
MULTI = {
    "login": _multi[0],
    "password": _multi[1],
    "user_id": int(_multi[2]),
    "link_a": int(_multi[3]),
    "link_b": int(_multi[4]),
    "patient_a": int(_multi[5]),
    "patient_b": int(_multi[6]),
    "clinic_a": int(_multi[7]),
    "clinic_b": int(_multi[8]),
    "foreign_link": int(_multi[9]),
    "inactive_link": int(_multi[10]),
}

# Slice 7 TEST-ONLY RED — My Files on the existing C3/C4/E17 backend.
FILES_STORAGE = os.environ.get("FILES_STORAGE", "").strip()
FILES_A = int(os.environ.get("FILES_A", "0") or 0)
FILES_B = int(os.environ.get("FILES_B", "0") or 0)
_files_one = _parts("FILES_ONE", 4)
FILES_ONE = {
    "visit_file": int(_files_one[0]),
    "up_file": int(_files_one[1]),
    "jalali": _files_one[2],
    "greg": _files_one[3],
}
if not FILES_STORAGE or FILES_A <= 0 or FILES_B <= 0:
    raise SystemExit("FILES_STORAGE/FILES_A/FILES_B must be seeded by the fixture")
PDF_BYTES = b"%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n%%EOF\n"
EVIL_BYTES = b"<?php system($_GET['c']); ?>"

results = []
failures = []


def ok(key, title, detail=""):
    results.append({"key": key, "status": "PASS", "detail": detail})
    print(f"PASS {key} — {title}" + (f" — {detail}" if detail else ""))


def fail(key, title, err):
    failures.append(key)
    results.append({"key": key, "status": "FAIL", "detail": str(err)})
    print(f"FAIL {key} — {title} — {err}")


def shot(name):
    print(f"SHOT {name}")


def db(sql):
    out = subprocess.run(
        ["mysql", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-proot",
         "--batch", "--skip-column-names", "-e", sql, DB_MAIN],
        capture_output=True, text=True, timeout=30,
    )
    if out.returncode != 0:
        safe = re.sub(r"\d{6,}", "[redacted]", (out.stderr or "").strip())[:180]
        raise RuntimeError(f"db query failed: {safe}")
    return out.stdout.strip()


def db1(sql):
    raw = db(sql)
    if not raw:
        return 0
    first = raw.split("\n")[0].strip()
    try:
        return int(float(first))
    except ValueError:
        return 0


def dbs(sql):
    raw = db(sql)
    return raw.split("\n")[0].strip() if raw else ""


def T(name):
    return f"{DB_PREFIX}{name}"


def wp_route(url):
    parsed = urlparse(url)
    flat = {k: v[0] for k, v in parse_qs(parsed.query, keep_blank_values=True).items()}
    route = flat.get("rest_route")
    if route is None:
        m = re.search(r"/wp-json(/.*)?$", parsed.path)
        route = m.group(1) if (m and m.group(1)) else parsed.path
    return route.rstrip("/") or "/"


def assert_profile_url(url):
    route = wp_route(url)
    if url.count("/clinic/v1/clinic/v1") or route.count("/clinic/v1") != 1:
        raise RuntimeError(f"profile URL duplicated the namespace: route={route}")
    if not route.endswith("/patient/me"):
        raise RuntimeError(f"profile URL is not /patient/me: route={route}")
    return route


def patient_col(patient_id, column):
    allowed = {"first_name", "last_name", "address", "national_id", "mobile", "phone"}
    if column not in allowed:
        raise RuntimeError("column not allowed")
    return dbs(f"SELECT {column} FROM {T('cpms_patients')} WHERE id={int(patient_id)}")


def clinic_name(clinic_id):
    return dbs(f"SELECT name FROM {T('cpms_clinics')} WHERE id={int(clinic_id)}")


def profile_audits(patient_id):
    return db1(
        "SELECT COUNT(*) FROM " + T("cpms_audit_logs")
        + f" WHERE action='PATIENT_PROFILE_UPDATED' AND patient_id={int(patient_id)}"
    )


def new_page(browser, run):
    ctx = browser.new_context(
        viewport={"width": run["w"], "height": run["h"]},
        locale="fa-IR",
        ignore_https_errors=True,
    )
    page = ctx.new_page()
    page.set_default_timeout(25000)
    state = {
        "rest": [],
        "puts": [],
        "gets": [],
        "console": [],
        "pageerrors": [],
        "failed": [],
    }

    def on_request(req):
        if not req.url.startswith(BASE) or "/clinic/v1" not in req.url:
            return
        route = wp_route(req.url)
        if route.endswith("/patient/me"):
            headers = {k.lower(): v for k, v in req.headers.items()}
            item = {
                "method": req.method,
                "url": req.url,
                "route": route,
                "has_nonce": bool(headers.get("x-wp-nonce")),
                "body": req.post_data or "",
                "same_origin": urlparse(req.url).netloc == urlparse(BASE).netloc,
            }
            if req.method == "PUT":
                state["puts"].append(item)
            elif req.method == "GET":
                state["gets"].append(item)

    def on_response(resp):
        if not resp.url.startswith(BASE) or "/clinic/v1" not in resp.url:
            return
        state["rest"].append((resp.request.method, wp_route(resp.url), resp.status))

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


def login(page, login_name, password):
    page.goto(f"{BASE}/wp-login.php", wait_until="networkidle")
    page.fill("#user_login", login_name)
    page.fill("#user_pass", password)
    try:
        with page.expect_navigation(timeout=20000):
            page.click("#wp-submit")
    except Exception:
        page.wait_for_load_state("networkidle", timeout=20000)
    page.wait_for_load_state("networkidle")
    if "wp-login.php" in (page.url or ""):
        raise RuntimeError("login failed")
    if PORTAL_SLUG not in (page.url or "") and page.locator(SHELL_ROOT).count() == 0:
        page.goto(f"{BASE}{PORTAL_PATH}", wait_until="networkidle")
    assert_shell(page)


def assert_shell(page):
    url = page.url or ""
    if "/wp-admin/" in url or "page=cpms-patient" in url:
        raise RuntimeError("portal remained under wp-admin")
    page.wait_for_selector(SHELL_ROOT, timeout=15000)
    html = page.content()
    for marker in ('id="wpadminbar"', 'id="adminmenu"', 'id="wpfooter"', "wp-site-blocks", "site-header", "site-footer"):
        if marker in html:
            raise RuntimeError(f"theme or wp-admin chrome present ({marker})")
    if "مدیریت مطب" in html:
        raise RuntimeError("staff navigation present")
    if page.locator('[data-role="portal-header"]').count() < 1:
        raise RuntimeError("CPMS portal header missing")
    if 'data-cpms-patient-portal="shell"' not in html:
        raise RuntimeError("independent CPMS shell marker missing")
    direction = page.evaluate("document.documentElement.dir")
    lang = page.evaluate("document.documentElement.lang")
    if direction != "rtl" or not str(lang).lower().startswith("fa"):
        raise RuntimeError(f"expected RTL fa shell, got dir={direction} lang={lang}")


def open_profile(page):
    nav = page.locator('[data-role="nav-profile"]')
    if nav.count() != 1:
        raise RuntimeError("profile navigation missing")
    nav.click()
    page.wait_for_selector('[data-role="profile-section"]', timeout=10000)
    page.locator('[data-role="profile-section"]').scroll_into_view_if_needed()


def overflow(page):
    sw = page.evaluate("document.documentElement.scrollWidth")
    iw = page.evaluate("window.innerWidth")
    if sw > iw + 1:
        raise RuntimeError(f"horizontal overflow scrollWidth={sw} innerWidth={iw}")
    return sw, iw


def read_config(page):
    node = page.locator("script.cpms-patient-portal__config")
    if node.count() != 1:
        raise RuntimeError(f"exactly one portal config script expected, got {node.count()}")
    # text_content, not inner_text: a JSON <script> has no rendered text.
    raw = node.first.text_content() or ""
    cfg = json.loads(raw)
    for key in ("clinic_id", "patient_id", "organization_id", "role", "roles", "mobile", "national_id"):
        if key in cfg:
            raise RuntimeError(f"published config contains forbidden key {key}")
    root = str(cfg.get("rest_root") or "")
    me_path = str(cfg.get("profile_me_path") or "")
    records_path = str(cfg.get("profile_my_records_path") or "")
    if not root.endswith("/clinic/v1") or root.count("/clinic/v1") != 1:
        raise RuntimeError("rest_root must end in /clinic/v1 exactly once")
    if me_path != "/patient/me" or records_path != "/patient/my-records":
        raise RuntimeError("published profile paths must stay relative")
    if "nonce" not in cfg or not cfg["nonce"]:
        raise RuntimeError("wp_rest nonce missing")
    record_forbidden = FORBIDDEN_KEYS | {
        "birth_date", "address", "phone", "national_id",
        "emergency_contact_name", "emergency_contact_phone",
    }
    for rec in cfg.get("profile_records") or []:
        extra = set(rec) & record_forbidden
        if extra:
            raise RuntimeError(f"profile_records leaked {sorted(extra)}")
    return cfg


def assert_quiet(state, label):
    if state["console"]:
        raise RuntimeError(f"{label} console errors")
    if state["pageerrors"]:
        raise RuntimeError(f"{label} page errors")
    if state["failed"]:
        raise RuntimeError(f"{label} failed requests")


def body_keys(item):
    try:
        payload = json.loads(item["body"] or "{}")
    except json.JSONDecodeError as exc:
        raise RuntimeError("profile PUT body was not JSON") from exc
    if not isinstance(payload, dict):
        raise RuntimeError("profile PUT body was not an object")
    keys = set(payload)
    leaked = keys & FORBIDDEN_KEYS
    if leaked:
        raise RuntimeError(f"profile PUT contained authority keys {sorted(leaked)}")
    unknown = keys - (EDITABLE_KEYS | {"link_id"})
    if unknown:
        raise RuntimeError(f"profile PUT contained unexpected keys {sorted(unknown)}")
    if "link_id" not in keys:
        raise RuntimeError("profile PUT missing link_id selector")
    if not item["has_nonce"] or not item["same_origin"]:
        raise RuntimeError("profile PUT missing same-origin nonce")
    assert_profile_url(item["url"])
    return payload


def keyboard_focus_visible(page, selector):
    focused = page.evaluate(
        """(sel) => {
            const el = document.querySelector(sel);
            if (!el || !el.focus) { return {match: false, outline: '', role: ''}; }
            el.focus({focusVisible: true});
            const style = getComputedStyle(el);
            return {
                match: document.activeElement === el,
                outline: style.outlineWidth || '',
                role: el.getAttribute('data-role') || el.tagName
            };
        }""",
        selector,
    )
    if not focused["match"]:
        raise RuntimeError("profile control did not take focus")
    if (focused["outline"] or "") in ("", "0px"):
        raise RuntimeError("focused profile control has no visible outline")
    return focused["role"]


def save_shot(page, name):
    os.makedirs(OUT, exist_ok=True)
    page.screenshot(path=f"{OUT}/{name}", full_page=True)
    shot(name)


def run_one_readonly(browser, run):
    key = f"profile-one-{run['vp']}"
    ctx, page, state = new_page(browser, run)
    stage = "boot"
    try:
        stage = "login"
        login(page, ONE["login"], ONE["password"])
        stage = "nav"
        open_profile(page)
        cfg = read_config(page)
        records = cfg.get("profile_records") or []
        if len(records) != 1 or int(records[0].get("link_id") or 0) != ONE["link_id"]:
            raise RuntimeError("one-record config did not publish the sole server link")
        if page.locator('[data-role="profile-record-select"]').count() != 0:
            raise RuntimeError("one-record profile must not render a selector")
        form = page.locator('[data-role="profile-form"]')
        if form.count() != 1 or not form.is_visible():
            raise RuntimeError("one-record profile form was not visible")
        label = page.locator('[data-role="profile-clinic-label"]').inner_text().strip()
        if label != clinic_name(ONE["clinic_id"]) or label == "":
            raise RuntimeError("clinic context did not match the sole linked record")
        first = page.locator('[data-field="first_name"]').input_value()
        if first != patient_col(ONE["patient_id"], "first_name") or first == "":
            raise RuntimeError("existing profile first name was not populated")
        address = page.locator('[data-field="address"]').input_value()
        if address != patient_col(ONE["patient_id"], "address"):
            raise RuntimeError("existing profile address was not populated")
        nid = page.locator('[data-field="national_id"]')
        if nid.count() != 1 or not nid.is_enabled() or nid.get_attribute("readonly") is not None:
            raise RuntimeError("national id must be visible and editable")
        mobile = page.locator('[data-role="profile-login-mobile"]')
        if mobile.count() != 1 or not mobile.is_visible():
            raise RuntimeError("login mobile display missing")
        if mobile.inner_text().strip() != patient_col(ONE["patient_id"], "mobile"):
            raise RuntimeError("login mobile display did not match the linked record")
        if page.locator('[data-role="profile-form"] input[name="mobile"], [data-role="profile-form"] [data-field="mobile"]').count() != 0:
            raise RuntimeError("editable mobile input present")
        form_html = form.inner_html()
        for needle in ("data-clinic-id=", "data-patient-id=", "data-organization-id=", 'name="clinic_id"', 'name="patient_id"', 'name="role"', 'name="mobile"'):
            if needle in form_html:
                raise RuntimeError(f"form leaked {needle}")
        if state["gets"] or any(call[1].endswith("/patient/me") or call[1].endswith("/patient/my-records") for call in state["rest"]):
            raise RuntimeError("one-record render issued a profile fetch")
        sw, iw = overflow(page)
        focus_role = keyboard_focus_visible(page, '[data-role="nav-profile"]')
        save_box = page.locator('[data-role="profile-save"]').bounding_box() or {}
        if save_box.get("height", 0) < 40 or save_box.get("width", 0) < 40:
            raise RuntimeError(f"save control touch target too small: {save_box}")
        assert_quiet(state, "one-record")
        save_shot(page, f"portal-profile-{run['vp']}-one.png")
        ok(key, "one-record Profile is auto-resolved, populated, and authority-free",
           f"shell=True rtl=True form=1 selector=0 clinic_match=True populated=True mobile_readonly=True nid_editable=True profile_fetches=0 sw={sw} iw={iw} focus={focus_role} touch_h={int(save_box.get('height', 0))}")
    except Exception as exc:
        try:
            save_shot(page, f"portal-profile-{run['vp']}-one-FAIL.png")
        except Exception:
            pass
        fail(key, f"one-record {run['vp']} at {stage}", exc)
    finally:
        ctx.close()


def run_one_mutation(browser):
    key = "profile-one-save"
    run = VIEWPORTS[2]
    ctx, page, state = new_page(browser, run)
    stage = "boot"
    try:
        stage = "login"
        login(page, ONE["login"], ONE["password"])
        open_profile(page)
        before_addr = patient_col(ONE["patient_id"], "address")
        before_nid = patient_col(ONE["patient_id"], "national_id")
        other_addr = patient_col(MULTI["patient_b"], "address")
        audits_before = profile_audits(ONE["patient_id"])
        stage = "save"
        page.locator('[data-field="address"]').fill(SAVE_TOKEN)
        puts_before = len(state["puts"])
        with page.expect_response(lambda r: r.request.method == "PUT" and wp_route(r.url).endswith("/patient/me"), timeout=20000) as info:
            page.locator('[data-role="profile-save"]').click()
        resp = info.value
        if resp.status != 200:
            raise RuntimeError(f"save status {resp.status}")
        if len(state["puts"]) != puts_before + 1:
            raise RuntimeError(f"expected one save PUT, saw {len(state['puts']) - puts_before}")
        payload = body_keys(state["puts"][-1])
        if int(payload.get("link_id") or 0) != ONE["link_id"]:
            raise RuntimeError("save PUT link_id was not the selected record")
        if payload.get("address") != SAVE_TOKEN:
            raise RuntimeError("save PUT did not carry the edited field")
        success = page.locator('[data-role="profile-success"]')
        page.wait_for_function(
            """() => {
                const el = document.querySelector('[data-role="profile-success"]');
                return !!(el && !el.hidden && el.textContent && /[\\u0600-\\u06FF]/.test(el.textContent));
            }""",
            timeout=10000,
        )
        if success.get_attribute("role") != "alert":
            raise RuntimeError("success region is not role=alert")
        if patient_col(ONE["patient_id"], "address") != SAVE_TOKEN:
            raise RuntimeError("database did not persist the selected record")
        if patient_col(MULTI["patient_b"], "address") != other_addr:
            raise RuntimeError("unselected record changed")
        if profile_audits(ONE["patient_id"]) != audits_before + 1:
            raise RuntimeError("successful profile audit was not written")
        save_shot(page, "portal-profile-save-ok.png")
        ok(key + "-ok", "browser save updated only the selected record",
           f"status=200 link_selector=True authority_keys=False persian_alert=True db_selected_changed=True other_unchanged=True audit=+1")

        stage = "invalid"
        audits_after_ok = profile_audits(ONE["patient_id"])
        page.locator('[data-field="national_id"]').fill(INVALID_NID)
        with page.expect_response(lambda r: r.request.method == "PUT" and wp_route(r.url).endswith("/patient/me"), timeout=20000) as bad:
            page.locator('[data-role="profile-save"]').click()
        bad_resp = bad.value
        bad_body = bad_resp.json()
        code = ((bad_body or {}).get("code") or (bad_body or {}).get("data", {}).get("code") or "")
        if bad_resp.status != 400 or "CLINIC_VALIDATION_FAILED" not in json.dumps(bad_body, ensure_ascii=False):
            raise RuntimeError(f"validation status {bad_resp.status} code_present={bool(code)}")
        err = page.locator('[data-role="profile-error"]')
        page.wait_for_function(
            """() => {
                const el = document.querySelector('[data-role="profile-error"]');
                return !!(el && !el.hidden && el.textContent && /[\\u0600-\\u06FF]/.test(el.textContent));
            }""",
            timeout=10000,
        )
        if err.get_attribute("role") != "alert":
            raise RuntimeError("error region is not role=alert")
        if page.locator('[data-field="national_id"]').input_value() != INVALID_NID:
            raise RuntimeError("invalid entry was cleared")
        if page.locator('[data-field="address"]').input_value() != SAVE_TOKEN:
            raise RuntimeError("other entered values were cleared")
        if patient_col(ONE["patient_id"], "national_id") != before_nid:
            raise RuntimeError("invalid save mutated national id")
        if patient_col(ONE["patient_id"], "address") != SAVE_TOKEN:
            raise RuntimeError("invalid save reverted or changed the previous field")
        if profile_audits(ONE["patient_id"]) != audits_after_ok:
            raise RuntimeError("failed validation wrote a profile audit")
        button = page.locator('[data-role="profile-save"]')
        if not button.is_enabled():
            raise RuntimeError("save button stayed disabled")
        active = page.evaluate("document.activeElement && document.activeElement.getAttribute('data-role')")
        if active != "profile-save":
            raise RuntimeError(f"focus was not restored to save, got {active}")
        assert_quiet(state, "save")
        me_calls = [c for c in state["rest"] if c[1].endswith("/patient/me")]
        if me_calls != [("PUT", ME_ROUTE, 200), ("PUT", ME_ROUTE, 400)]:
            raise RuntimeError(f"unexpected profile traffic {me_calls}")
        save_shot(page, "portal-profile-save-invalid.png")
        ok(key + "-invalid", "invalid national id is rejected without mutation",
           "status=400 code=CLINIC_VALIDATION_FAILED persian_alert=True values_kept=True db_unchanged=True audit_unchanged=True button_enabled=True focus=profile-save")
    except Exception as exc:
        try:
            save_shot(page, f"portal-profile-save-FAIL-{stage}.png")
        except Exception:
            pass
        fail(key, f"save/validation at {stage}", exc)
    finally:
        ctx.close()


def run_multi(browser, run, capture_before, capture_after):
    key = f"profile-multi-{run['vp']}"
    ctx, page, state = new_page(browser, run)
    stage = "boot"
    try:
        stage = "login"
        login(page, MULTI["login"], MULTI["password"])
        open_profile(page)
        cfg = read_config(page)
        published = sorted(int(r.get("link_id") or 0) for r in (cfg.get("profile_records") or []))
        expected = sorted([MULTI["link_a"], MULTI["link_b"]])
        if published != expected:
            raise RuntimeError("config records were not exactly the two active links")
        select = page.locator('[data-role="profile-record-select"]')
        if select.count() != 1 or not select.is_visible():
            raise RuntimeError("N>1 selector missing")
        if select.input_value() != "":
            raise RuntimeError("selector auto-selected a record")
        options = page.locator('[data-role="profile-record-option"]')
        if options.count() != 2:
            raise RuntimeError(f"selector option count {options.count()}")
        link_ids = sorted(int(options.nth(i).get_attribute("data-link-id") or 0) for i in range(2))
        if link_ids != expected:
            raise RuntimeError("option link ids were not the server links")
        option_html = " ".join(options.nth(i).evaluate("el => el.outerHTML") for i in range(2))
        if "data-clinic-id=" in option_html or "data-patient-id=" in option_html:
            raise RuntimeError("selector option published raw authority")
        for lid in (MULTI["foreign_link"], MULTI["inactive_link"]):
            if page.locator(f'[data-link-id="{lid}"]').count() != 0:
                raise RuntimeError("foreign or inactive link rendered")
        labels = [options.nth(i).inner_text() for i in range(2)]
        name_a = clinic_name(MULTI["clinic_a"])
        name_b = clinic_name(MULTI["clinic_b"])
        if not any(name_a and name_a in text for text in labels) or not any(name_b and name_b in text for text in labels):
            raise RuntimeError("clinic labels were not visible on the options")
        if page.locator('[data-role="profile-form"]').count() != 0:
            raise RuntimeError("editable form rendered before explicit selection")
        if state["gets"]:
            raise RuntimeError("selector render fetched profile before a choice")
        box = select.bounding_box() or {}
        if box.get("height", 0) < 40:
            raise RuntimeError(f"selector touch target too small: {box}")
        if capture_before:
            save_shot(page, "portal-profile-multi-before.png")
        gets_before = len(state["gets"])
        stage = "select-b"
        with page.expect_response(lambda r: r.request.method == "GET" and wp_route(r.url).endswith("/patient/me"), timeout=20000) as info:
            select.select_option(str(MULTI["link_b"]))
        got = info.value
        if got.status != 200:
            raise RuntimeError(f"select GET status {got.status}")
        if len(state["gets"]) != gets_before + 1:
            raise RuntimeError("selection issued more than one profile GET")
        get_item = state["gets"][-1]
        assert_profile_url(get_item["url"])
        if f"link_id={MULTI['link_b']}" not in get_item["url"]:
            raise RuntimeError("GET did not carry the selected link_id")
        if not get_item["has_nonce"] or not get_item["same_origin"]:
            raise RuntimeError("GET missing same-origin nonce")
        expected_b = patient_col(MULTI["patient_b"], "first_name")
        # Response arrival is not the page's then() handler. Wait for the DOM.
        page.wait_for_function(
            """(expected) => {
                const input = document.querySelector('[data-field="first_name"]');
                const label = document.querySelector('[data-role="profile-clinic-label"]');
                const ctx = label && label.closest('[data-role="profile-context"]');
                return !!(input && input.value === expected && ctx && !ctx.hidden && label.textContent.trim() !== '');
            }""",
            arg=expected_b,
            timeout=10000,
        )
        label = page.locator('[data-role="profile-clinic-label"]').inner_text().strip()
        if label != name_b:
            raise RuntimeError("clinic context did not switch to record B")
        if page.locator('[data-field="first_name"]').input_value() != expected_b:
            raise RuntimeError("fields did not show record B")
        if page.locator('[data-field="first_name"]').input_value() == patient_col(MULTI["patient_a"], "first_name"):
            raise RuntimeError("fields still showed record A")
        page.wait_for_timeout(1200)
        if len(state["gets"]) != gets_before + 1:
            raise RuntimeError("profile GET repeated after selection (poll or N+1)")
        my_records = [c for c in state["rest"] if c[1].endswith("/patient/my-records")]
        if my_records:
            raise RuntimeError("frontend fetched my-records per interaction")
        page.reload(wait_until="networkidle")
        open_profile(page)
        # Server HTML is the proof. Chromium may restore a control value locally;
        # that is not a persisted Clinic/record.
        server_selected = page.evaluate(
            """() => {
                const sel = document.querySelector('[data-role="profile-record-select"]');
                if (!sel) { return null; }
                return Array.from(sel.options).filter((o) => o.hasAttribute('selected')).map((o) => o.getAttribute('value'));
            }"""
        )
        if server_selected != [""]:
            raise RuntimeError(f"server HTML preselected a record after reload: {server_selected}")
        assert_quiet(state, "multi")
        sw, iw = overflow(page)
        if capture_after:
            page.locator('[data-role="profile-record-select"]').select_option(str(MULTI["link_b"]))
            page.wait_for_function(
                """(name) => {
                    const el = document.querySelector('[data-role="profile-clinic-label"]');
                    const ctx = el && el.closest('[data-role="profile-context"]');
                    return !!(ctx && !ctx.hidden && el.textContent.trim() === name);
                }""",
                arg=name_b,
                timeout=10000,
            )
            save_shot(page, "portal-profile-multi-b.png")
        ok(key, "N>1 selector lists only active links and loads the explicit choice",
           f"options=2 foreign_absent=True inactive_absent=True auto_selected=False form_before=False get_status=200 route_ns_once=True link_selector=True clinic_is_b=True fields_are_b=True poll_extra=0 my_records_gets=0 reload_clears=True sw={sw} iw={iw} touch_h={int(box.get('height', 0))} pretty={('/wp-json/' in get_item['url'])}")
    except Exception as exc:
        try:
            save_shot(page, f"portal-profile-{run['vp']}-multi-FAIL.png")
        except Exception:
            pass
        fail(key, f"multi {run['vp']} at {stage}", exc)
    finally:
        ctx.close()


def run_visits_readonly(browser, run):
    """Slice 5 TEST-ONLY RED: actual shell/JS/REST, no mocked clinical response."""
    key = "visits-list-detail-selector-" + run["vp"]
    ctx, page, state = new_page(browser, run)
    requests = []
    page.on("request", lambda req: requests.append(req) if wp_route(req.url).startswith("/clinic/v1/visits") else None)
    try:
        visit_a, visit_b = [int(v) for v in os.environ["VISITS_PAIR"].split("|")]
        assert db1(f"SELECT COUNT(*) FROM {T('cpms_visits')} WHERE id IN ({visit_a},{visit_b})") == 2
        login(page, MULTI["login"], MULTI["password"])
        assert_shell(page)
        ok("visits-bootstrap", "authenticated production shell and two durable visit fixtures reached")
        nav = page.locator('[data-role="nav-visits"]')
        assert nav.count() == 1, "My Visits navigation missing on production shell"
        nav.click()
        section = page.locator('[data-role="visits-section"]')
        selector = section.locator('[data-role="visits-record-select"]')
        assert selector.input_value() == "", "primary/first record selected implicitly"
        assert section.locator('[data-role="visit-open"]').count() == 0
        assert not requests, "Clinic-specific visits requested before selection"
        options = selector.locator('option').evaluate_all("els => els.map(e => e.value).filter(Boolean)")
        assert set(options) == {str(MULTI["link_a"]), str(MULTI["link_b"])}
        with page.expect_response(lambda r: wp_route(r.url) == "/clinic/v1/visits") as listing:
            selector.select_option(str(MULTI["link_b"]))
        assert listing.value.status == 200
        assert [v["id"] for v in listing.value.json()["data"]["visits"]] == [visit_b]
        context = section.locator('[data-role="visits-context"]')
        expect(context).to_contain_text(clinic_name(MULTI["clinic_b"]))
        expect(context).to_contain_text("SynB")
        opener = section.locator(f'[data-role="visit-open"][data-visit-id="{visit_b}"]')
        expect(opener).to_contain_text(os.environ["VISITS_JALALI"])
        assert os.environ["VISITS_DATE"] not in opener.inner_text()
        assert not re.search(r"\b[0-9]{4}-[0-9]{2}-[0-9]{2}\b", opener.inner_text())
        with page.expect_response(lambda r: wp_route(r.url) == f"/clinic/v1/visits/{visit_b}") as detail:
            opener.click()
        assert detail.value.status == 200
        pane = section.locator('[data-role="visits-detail"]')
        pane.get_by_text("SYN-VISIBLE-B", exact=False).wait_for(state="visible")
        heading = pane.locator("h2")
        expect(heading).to_contain_text(os.environ["VISITS_JALALI"])
        assert os.environ["VISITS_DATE"] not in heading.inner_text()
        assert not re.search(r"\b[0-9]{4}-[0-9]{2}-[0-9]{2}\b", heading.inner_text())
        ok("visits-jalali-" + run["vp"], "JS list/detail show PHP Jalali display field; raw Gregorian absent",
           "expected=" + os.environ["VISITS_JALALI"])
        text = pane.inner_text()
        for forbidden in ("SYN-PRIVATE-", "SYN-INTERNAL-CORRECTION", "SYN-VISIBLE-A", "checked_out", "walk_in"):
            assert forbidden not in text, f"unresolved/private field displayed: {forbidden}"
        assert section.locator('form, [contenteditable="true"], [data-role="visits-edit"], [data-role="visits-delete"]').count() == 0
        for req in requests:
            assert req.method == "GET", "Visits must be read-only"
            assert req.headers.get("x-wp-nonce"), "existing wp_rest nonce required"
            params = parse_qs(urlparse(req.url).query)
            assert params.get("link_id") == [str(MULTI["link_b"])], "selection must travel with list AND detail"
            assert not (set(params) & {"clinic_id", "patient_id", "organization_id", "role"})
            assert not req.post_data, "GET must not send authority in a request body"
        assert len(requests) >= 2, "both existing C5/C6 paths must execute"
        overflow(page)
        save_shot(page, f"portal-visits-{run['vp']}-detail.png")
        # A context switch must clear B detail, not leave stale Clinic history.
        with page.expect_response(lambda r: wp_route(r.url) == "/clinic/v1/visits"):
            selector.select_option(str(MULTI["link_a"]))
        section.locator(f'[data-role="visit-open"][data-visit-id="{visit_a}"]').wait_for(state="visible")
        assert "SYN-VISIBLE-B" not in section.inner_text(), "stale B detail remained after switching to A"
        assert clinic_name(MULTI["clinic_a"]) in context.inner_text()
        assert not state["console"] and not state["pageerrors"] and not state["failed"], f"console/page/network hygiene broken: console={state['console']} pageerrors={state['pageerrors']} failed={state['failed']}"
        assert all(status == 200 for method, route, status in state["rest"] if route.startswith("/clinic/v1/visits"))
        overflow(page)
        save_shot(page, f"portal-visits-{run['vp']}-switch.png")
        # Actual server denial (no mocked response): remove the nonce on detail.
        def invalidate_nonce(route):
            headers = dict(route.request.headers)
            headers["x-wp-nonce"] = "invalid-test-nonce"
            route.continue_(headers=headers)
        page.route(f"**/visits/{visit_a}?*", invalidate_nonce)
        with page.expect_response(lambda r: wp_route(r.url) == f"/clinic/v1/visits/{visit_a}") as denied:
            section.locator(f'[data-role="visit-open"][data-visit-id="{visit_a}"]').click()
        assert denied.value.status == 403
        # Core WP may reject a bad cookie nonce before the plugin permission callback.
        assert denied.value.json()["code"] in {"rest_cookie_invalid_nonce", "CLINIC_INVALID_NONCE"}
        expect(section.locator('[data-role="visits-error"]')).to_be_visible()
        expect(pane).to_be_empty()
        save_shot(page, f"portal-visits-{run['vp']}-error.png")
        assert not state["console"] and not state["pageerrors"] and not state["failed"], f"console/page/network hygiene broken: console={state['console']} pageerrors={state['pageerrors']} failed={state['failed']}"
        ok(key, "B list/detail, nonce + selector-only GETs, switch clears detail, real nonce denial, RTL, no overflow/JS/network failures")
    except Exception as exc:
        fail(key, "My Visits read-only vertical contract", exc)
    finally:
        ctx.close()


def run_visits_one(browser, run):
    key = "visits-one-" + run["vp"]
    ctx, page, state = new_page(browser, run)
    try:
        visit = int(os.environ["VISITS_ONE"])
        login(page, ONE["login"], ONE["password"])
        page.locator('[data-role="nav-visits"]').click()
        section = page.locator('[data-role="visits-section"]')
        assert section.locator('[data-role="visits-record-select"]').count() == 0
        expect(section.locator('[data-role="visits-context"]')).to_contain_text(clinic_name(ONE["clinic_id"]))
        sole_list = section.locator('[data-role="visits-list"]')
        expect(sole_list).to_contain_text(os.environ["VISITS_JALALI"])
        assert os.environ["VISITS_DATE"] not in sole_list.inner_text()
        assert not re.search(r"\b[0-9]{4}-[0-9]{2}-[0-9]{2}\b", sole_list.inner_text())
        ok("visits-jalali-sole-" + run["vp"], "sole-record SSR list shows PHP Jalali display field; raw Gregorian absent",
           "expected=" + os.environ["VISITS_JALALI"])
        with page.expect_response(lambda r: wp_route(r.url) == f"/clinic/v1/visits/{visit}") as response:
            section.locator(f'[data-role="visit-open"][data-visit-id="{visit}"]').click()
        assert response.value.status == 200
        request = response.value.request
        params = parse_qs(urlparse(request.url).query)
        assert params.get("link_id") == [str(ONE["link_id"])]
        assert not (set(params) & {"clinic_id", "patient_id", "organization_id", "role"})
        assert request.method == "GET" and request.headers.get("x-wp-nonce") and not request.post_data
        section.get_by_text("SYN-VISIBLE-ONE", exact=False).wait_for(state="visible")
        assert "SYN-PRIVATE" not in section.inner_text()
        assert not state["console"] and not state["pageerrors"] and not state["failed"], f"console/page/network hygiene broken: console={state['console']} pageerrors={state['pageerrors']} failed={state['failed']}"
        overflow(page)
        save_shot(page, f"portal-visits-{run['vp']}-one.png")
        ok(key, "sole linked record auto-resolves list/detail; real patient shell, nonce, selector-only GET, RTL, no overflow/JS/network failures")
    except Exception as exc:
        fail(key, "one-record My Visits", exc)
    finally:
        ctx.close()


def run_prescriptions_readonly(browser, run):
    """Slice 6 TEST-ONLY RED: actual shell/JS/REST, no mocked clinical response."""
    key = "prescriptions-list-selector-" + run["vp"]
    ctx, page, state = new_page(browser, run)
    requests = []
    page.on("request", lambda req: requests.append(req) if wp_route(req.url).startswith("/clinic/v1/prescriptions") else None)
    try:
        rx_a, rx_b = [int(v) for v in os.environ["RX_PAIR"].split("|")]
        assert db1(f"SELECT COUNT(*) FROM {T('cpms_prescriptions')} WHERE id IN ({rx_a},{rx_b})") == 2
        login(page, MULTI["login"], MULTI["password"])
        assert_shell(page)
        ok("prescriptions-bootstrap", "authenticated production shell and two durable prescription fixtures reached")
        nav = page.locator('[data-role="nav-prescriptions"]')
        assert nav.count() == 1, "My Prescriptions navigation missing on production shell"
        nav.click()
        section = page.locator('[data-role="prescriptions-section"]')
        selector = section.locator('[data-role="prescriptions-record-select"]')
        assert selector.input_value() == "", "primary/first record selected implicitly"
        assert section.locator('[data-role="prescription-item"]').count() == 0
        assert not requests, "Clinic-specific prescriptions requested before selection"
        options = selector.locator('option').evaluate_all("els => els.map(e => e.value).filter(Boolean)")
        assert set(options) == {str(MULTI["link_a"]), str(MULTI["link_b"])}
        with page.expect_response(lambda r: wp_route(r.url) == "/clinic/v1/prescriptions") as listing:
            selector.select_option(str(MULTI["link_b"]))
        assert listing.value.status == 200
        context = section.locator('[data-role="prescriptions-context"]')
        expect(context).to_contain_text(clinic_name(MULTI["clinic_b"]))
        expect(context).to_contain_text("SynB")
        rx_list = section.locator('[data-role="prescriptions-list"]')
        expect(rx_list).to_contain_text("SYN-GENERIC-B")
        expect(rx_list).to_contain_text(os.environ["VISITS_JALALI"])
        assert os.environ["VISITS_DATE"] not in rx_list.inner_text()
        assert not re.search(r"\b[0-9]{4}-[0-9]{2}-[0-9]{2}\b", rx_list.inner_text())
        text = rx_list.inner_text()
        for forbidden in ("SYN-PRIVATE-", "SYN-INTERNAL-CORRECTION", "SYN-GENERIC-A", "draft", "voided"):
            assert forbidden not in text, f"unresolved/private field displayed: {forbidden}"
        assert section.locator('form, [contenteditable="true"], [data-role="prescriptions-edit"], [data-role="prescriptions-delete"], [data-role="prescriptions-refill"]').count() == 0
        for req in requests:
            assert req.method == "GET", "Prescriptions must be read-only"
            assert req.headers.get("x-wp-nonce"), "existing wp_rest nonce required"
            params = parse_qs(urlparse(req.url).query)
            assert params.get("link_id") == [str(MULTI["link_b"])], "selection must travel with request"
            assert not (set(params) & {"clinic_id", "patient_id", "organization_id", "role"})
            assert not req.post_data, "GET must not send authority in a request body"
        overflow(page)
        save_shot(page, f"portal-prescriptions-{run['vp']}-b.png")
        # Context switch must clear B prescriptions and load A.
        with page.expect_response(lambda r: wp_route(r.url) == "/clinic/v1/prescriptions"):
            selector.select_option(str(MULTI["link_a"]))
        section.locator('[data-role="prescriptions-list"]').get_by_text("SYN-GENERIC-A", exact=False).wait_for(state="visible")
        assert "SYN-GENERIC-B" not in section.inner_text(), "stale B prescriptions remained after switching to A"
        assert clinic_name(MULTI["clinic_a"]) in context.inner_text()
        assert not state["console"] and not state["pageerrors"] and not state["failed"], f"console/page/network hygiene broken: console={state['console']} pageerrors={state['pageerrors']} failed={state['failed']}"
        assert all(status == 200 for method, route, status in state["rest"] if route.startswith("/clinic/v1/prescriptions"))
        overflow(page)
        save_shot(page, f"portal-prescriptions-{run['vp']}-switch.png")
        # Server denial on invalid nonce:
        def invalidate_nonce(route):
            headers = dict(route.request.headers)
            headers["x-wp-nonce"] = "invalid-test-nonce"
            route.continue_(headers=headers)
        page.route("**/clinic/v1/prescriptions?*", invalidate_nonce)
        selector.select_option(str(MULTI["link_b"]))
        expect(section.locator('[data-role="prescriptions-error"]')).to_be_visible()
        save_shot(page, f"portal-prescriptions-{run['vp']}-error.png")
        assert not state["console"] and not state["pageerrors"] and not state["failed"], f"console/page/network hygiene broken: console={state['console']} pageerrors={state['pageerrors']} failed={state['failed']}"
        ok(key, "B list, nonce + selector-only GETs, switch clears detail, real nonce denial, RTL, no overflow/JS/network failures")
    except Exception as exc:
        fail(key, "My Prescriptions read-only vertical contract", exc)
    finally:
        ctx.close()


def run_prescriptions_one(browser, run):
    key = "prescriptions-one-" + run["vp"]
    ctx, page, state = new_page(browser, run)
    try:
        rx = int(os.environ["RX_ONE"])
        login(page, ONE["login"], ONE["password"])
        page.locator('[data-role="nav-prescriptions"]').click()
        section = page.locator('[data-role="prescriptions-section"]')
        assert section.locator('[data-role="prescriptions-record-select"]').count() == 0
        expect(section.locator('[data-role="prescriptions-context"]')).to_contain_text(clinic_name(ONE["clinic_id"]))
        sole_list = section.locator('[data-role="prescriptions-list"]')
        expect(sole_list).to_contain_text(os.environ["VISITS_JALALI"])
        expect(sole_list).to_contain_text("SYN-GENERIC-ONE")
        assert os.environ["VISITS_DATE"] not in sole_list.inner_text()
        assert not re.search(r"\b[0-9]{4}-[0-9]{2}-[0-9]{2}\b", sole_list.inner_text())
        assert "SYN-PRIVATE" not in section.inner_text()
        assert not state["console"] and not state["pageerrors"] and not state["failed"], f"console/page/network hygiene broken: console={state['console']} pageerrors={state['pageerrors']} failed={state['failed']}"
        overflow(page)
        save_shot(page, f"portal-prescriptions-{run['vp']}-one.png")
        ok(key, "sole linked record auto-resolves prescription list; real patient shell, nonce, selector-only GET, RTL, no overflow/JS/network failures")
    except Exception as exc:
        fail(key, "one-record My Prescriptions", exc)
    finally:
        ctx.close()


def run_files_one(browser, run):
    key = "files-one-" + run["vp"]
    ctx, page, state = new_page(browser, run)
    try:
        up_name = "SYN-FILES-ONE-BROWSER-" + run["vp"] + ".pdf"
        login(page, ONE["login"], ONE["password"])
        assert db1(
            f"SELECT COUNT(*) FROM {T('cpms_medical_attachments')}"
            f" WHERE id IN ({FILES_ONE['visit_file']},{FILES_ONE['up_file']}) AND deleted_at IS NULL"
        ) == 2
        ok("files-bootstrap", "authenticated production shell and durable sole-record file fixtures reached")
        nav = page.locator('[data-role="nav-files"]')
        assert nav.count() == 1, "exactly one My Files navigation item expected"
        nav.click()
        section = page.locator('[data-role="files-section"]')
        section.wait_for(state="visible")
        assert section.locator('[data-role="files-record-select"]').count() == 0, "sole record must auto-resolve without a selector"
        expect(section.locator('[data-role="files-context"]')).to_contain_text(clinic_name(ONE["clinic_id"]))
        items = section.locator('[data-role="file-item"]')
        assert items.count() == 2, "sole-record SSR list shows exactly the two patient-visible files"
        expect(section.locator('[data-role="files-list"]')).to_contain_text("SYN-FILES-ONE.pdf")
        expect(section.locator('[data-role="files-list"]')).to_contain_text("SYN-FILES-ONE-UP.pdf")
        # Fail-closed Jalali: visit-linked row carries the trusted Jalali date,
        # the visit-less upload row carries no date at all (never a guess).
        visit_row = section.locator('[data-role="file-item"]', has_text="SYN-FILES-ONE.pdf").first
        up_row = section.locator('[data-role="file-item"]', has_text="SYN-FILES-ONE-UP.pdf").first
        assert FILES_ONE["jalali"] in visit_row.inner_text(), "visit-linked row must show the trusted Jalali date"
        up_text = up_row.inner_text()
        assert FILES_ONE["greg"] not in up_text, "visit-less upload row leaked a raw Gregorian date"
        assert not re.search(r"\d{4}/\d{2}/\d{2}", up_text), "visit-less upload row shows a fabricated Jalali date"
        assert "SYN-PRIVATE" not in section.inner_text() and "SYN-DELETED" not in section.inner_text(), \
            "private/deleted rows leaked into the sole-record section"
        # Protected download through the existing E17 stream (membership authority).
        assert int(visit_row.locator('[data-role="file-download"]').get_attribute("data-file-id")) == FILES_ONE["visit_file"], \
            "download button must target the SSR visit-linked file id"
        with page.expect_response(lambda r: wp_route(r.url) == f"/clinic/v1/files/{FILES_ONE['visit_file']}/stream") as dl:
            visit_row.locator('[data-role="file-download"]').click()
        assert dl.value.status == 200, f"protected download expected 200, got {dl.value.status}"
        assert str(dl.value.headers.get("content-disposition", "")).startswith("attachment"), "stream must stay attachment"
        # Successful patient upload through the existing C3 route.
        up_input = section.locator('[data-role="files-upload-input"]')
        assert up_input.count() == 1, "patient upload control expected for the sole record"
        with page.expect_response(lambda r: r.request.method == "POST" and wp_route(r.url).endswith("/files")) as up:
            up_input.set_input_files({"name": up_name, "mimeType": "application/pdf", "buffer": PDF_BYTES})
            section.locator('[data-role="files-upload-button"]').click()
        assert up.value.status == 201, f"sole-record upload expected 201, got {up.value.status}"
        expect(section.locator('[data-role="files-upload-success"]')).to_be_visible()
        expect(section.locator('[data-role="files-list"]')).to_contain_text(up_name)
        stored = dbs(
            f"SELECT storage_path FROM {T('cpms_medical_attachments')}"
            f" WHERE patient_id={ONE['patient_id']} AND original_filename='{up_name}' AND visibility='patient_visible'"
            f" AND deleted_at IS NULL ORDER BY id DESC LIMIT 1"
        )
        assert stored and re.fullmatch(r"[0-9a-f]{32}\.pdf", stored.split("/")[-1]), "randomized stored filename expected"
        assert os.path.isfile(os.path.join(FILES_STORAGE, stored)), "uploaded file must live in the protected storage"
        assert not state["console"] and not state["pageerrors"] and not state["failed"], f"console/page/network hygiene broken: console={state['console']} pageerrors={state['pageerrors']} failed={state['failed']}"
        overflow(page)
        save_shot(page, f"portal-files-{run['vp']}-one.png")
        ok(key, "sole record: SSR list, trusted Jalali only, protected download, C3 upload, RTL, no overflow/JS/network failures")
    except Exception as exc:
        fail(key, "one-record My Files vertical contract", exc)
    finally:
        # Viewport isolation: this run uploads one extra patient-visible file on
        # the sole record; remove this viewport's row + physical file so the next
        # viewport's SSR list stays exactly the two seeded files.
        try:
            isolate_name = "SYN-FILES-ONE-BROWSER-" + run["vp"] + ".pdf"
            isolate_path = dbs(
                f"SELECT storage_path FROM {T('cpms_medical_attachments')}"
                f" WHERE original_filename='{isolate_name}' ORDER BY id DESC LIMIT 1"
            )
            db(f"DELETE FROM {T('cpms_medical_attachments')} WHERE original_filename='{isolate_name}'")
            if isolate_path:
                physical = os.path.join(FILES_STORAGE, isolate_path)
                if os.path.isfile(physical):
                    os.remove(physical)
        except Exception:
            pass
        ctx.close()


def run_files_multi(browser, run):
    key = "files-multi-" + run["vp"]
    ctx, page, state = new_page(browser, run)
    try:
        requests = []
        page.on(
            "request",
            lambda req: requests.append(req)
            if wp_route(req.url).startswith("/clinic/v1/patients/") and wp_route(req.url).endswith("/files")
            else None,
        )
        login(page, MULTI["login"], MULTI["password"])
        assert db1(f"SELECT COUNT(*) FROM {T('cpms_medical_attachments')} WHERE id IN ({FILES_A},{FILES_B})") == 2, \
            "multi bootstrap fixtures missing"
        nav = page.locator('[data-role="nav-files"]')
        assert nav.count() == 1
        nav.click()
        section = page.locator('[data-role="files-section"]')
        section.wait_for(state="visible")
        selector = section.locator('[data-role="files-record-select"]')
        assert selector.count() == 1, "N>1 must expose the explicit record selector"
        assert section.locator('[data-role="files-list"]').count() == 0, "no file list before explicit selection"
        assert section.locator('[data-role="file-item"]').count() == 0, "no files before explicit selection"
        assert section.locator('[data-role="files-upload-input"]').count() == 0, "no upload target before explicit selection"
        assert not requests, "Clinic-specific files requested before selection"
        opts = selector.locator("option")
        values = [opts.nth(i).get_attribute("value") for i in range(opts.count())]
        assert values[0] == "" and values[1:] == [str(MULTI["link_a"]), str(MULTI["link_b"])], values
        for i in range(opts.count()):
            assert opts.nth(i).get_attribute("selected") is None, "never preselect primary/first"
        # Select record B: only B files, selected-record isolation.
        with page.expect_response(
            lambda r: wp_route(r.url).endswith("/files") and f"link_id={MULTI['link_b']}" in r.url
        ) as listing:
            selector.select_option(str(MULTI["link_b"]))
        body = listing.value.json()["data"]
        names = [f["original_filename"] for f in body["files"]]
        assert "SYN-FILES-B.pdf" in names and "SYN-FILES-B-UP.pdf" in names, names
        assert not any(n.startswith("SYN-FILES-A") or n.startswith("SYN-FILES-ONE") for n in names), "record isolation broken"
        assert not any(n.startswith("SYN-PRIVATE") or n.startswith("SYN-DELETED") for n in names), "visibility broken"
        b_row = section.locator('[data-role="file-item"]', has_text="SYN-FILES-B.pdf").first
        assert int(b_row.locator('[data-role="file-download"]').get_attribute("data-file-id")) == FILES_B
        # Explicit selection is required BEFORE upload as well.
        up_input = section.locator('[data-role="files-upload-input"]')
        assert up_input.count() == 1, "upload target appears only after explicit record selection"
        up_name = "SYN-FILES-B-BROWSER-" + run["vp"] + ".pdf"
        with page.expect_response(
            lambda r: r.request.method == "POST" and wp_route(r.url).endswith("/files") and f"link_id={MULTI['link_b']}" in r.url
        ) as up:
            up_input.set_input_files({"name": up_name, "mimeType": "application/pdf", "buffer": PDF_BYTES})
            section.locator('[data-role="files-upload-button"]').click()
        assert up.value.status == 201, f"selected-record upload expected 201, got {up.value.status}"
        expect(section.locator('[data-role="files-upload-success"]')).to_be_visible()
        expect(section.locator('[data-role="files-list"]')).to_contain_text(up_name)
        uploaded_patient = db1(
            f"SELECT patient_id FROM {T('cpms_medical_attachments')}"
            f" WHERE original_filename='{up_name}' ORDER BY id DESC LIMIT 1"
        )
        assert uploaded_patient == MULTI["patient_b"], "upload must land on the explicitly selected record"
        # Rejected invalid upload (PHP disguised as JPG) stays rejected.
        with page.expect_response(
            lambda r: r.request.method == "POST" and wp_route(r.url).endswith("/files")
        ) as bad:
            up_input.set_input_files({"name": "evil.jpg", "mimeType": "image/jpeg", "buffer": EVIL_BYTES})
            section.locator('[data-role="files-upload-button"]').click()
        assert bad.value.status == 400, f"invalid upload expected 400, got {bad.value.status}"
        expect(section.locator('[data-role="files-upload-error"]')).to_be_visible()
        assert db1(
            f"SELECT COUNT(*) FROM {T('cpms_medical_attachments')} WHERE original_filename='evil.jpg'"
        ) == 0, "invalid upload must not persist"
        # Protected download of the selected record's file.
        with page.expect_response(lambda r: wp_route(r.url) == f"/clinic/v1/files/{FILES_B}/stream") as dl:
            b_row.locator('[data-role="file-download"]').click()
        assert dl.value.status == 200, f"protected download expected 200, got {dl.value.status}"
        assert str(dl.value.headers.get("content-disposition", "")).startswith("attachment")
        # Context switch: selecting A must swap the visible list (no mixing).
        with page.expect_response(
            lambda r: wp_route(r.url).endswith("/files") and f"link_id={MULTI['link_a']}" in r.url
        ):
            selector.select_option(str(MULTI["link_a"]))
        expect(section.locator('[data-role="files-list"]')).to_contain_text("SYN-FILES-A.pdf")
        assert "SYN-FILES-B.pdf" not in section.inner_text(), "stale B files remained after switching to A"
        assert not state["console"] and not state["pageerrors"] and not state["failed"], f"console/page/network hygiene broken: console={state['console']} pageerrors={state['pageerrors']} failed={state['failed']}"
        overflow(page)
        save_shot(page, f"portal-files-{run['vp']}-multi.png")
        ok(key, "N>1: explicit selection gates list and upload, record isolation, C3 upload ok/invalid rejected, protected download, RTL, no overflow/JS/network failures")
    except Exception as exc:
        fail(key, "multi-record My Files vertical contract", exc)
    finally:
        ctx.close()


def main():
    os.makedirs(OUT, exist_ok=True)
    print("INFO plain_permalink_browser=NOT_RUN pretty_pilot=expected")
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for run in VIEWPORTS:
            run_one_readonly(browser, run)
        run_one_mutation(browser)
        for run in VIEWPORTS:
            run_multi(
                browser,
                run,
                capture_before=(run["vp"] == "laptop-1366"),
                capture_after=(run["vp"] == "laptop-1366"),
            )
        for run in VIEWPORTS:
            run_visits_one(browser, run)
            run_visits_readonly(browser, run)
            run_prescriptions_one(browser, run)
            run_prescriptions_readonly(browser, run)
            run_files_one(browser, run)
            run_files_multi(browser, run)
        browser.close()
    summary = {"ok": not failures, "failed": failures}
    print(json.dumps(summary, ensure_ascii=False))
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
