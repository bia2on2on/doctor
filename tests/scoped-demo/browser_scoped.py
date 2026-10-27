#!/usr/bin/env python3
"""Chromium acceptance for the scoped demo: admin workflow, six logins, and one
real role-specific journey per persona.

Phases (the CI lane runs them in this order so PHP can export identifiers in
between):

  provision  admin form workflow (mode, preflight, provisioning, one-time
             credentials, reload hardening) and writes persona-credentials.json
  tamper     the sealed manifest is broken out of band (by
             tests/scoped-demo/tamper-manifest.php) and this phase proves the
             scoped admin page refuses it and offers no action at all
  journeys   six independent Chromium logins, then one real workflow per
             persona through the production REST stack, from inside the page so
             the cookies and REST nonce are the browser's own; then a real
             OTP login of the demo patient (challenge -> sanctioned code ->
             verify -> bound identity -> captured booking -> replay); then the
             admin diagnose + teardown forms

Honest scope notes (do not overclaim):
  * Each persona lands on its real production surface (the front-end Staff Portal
    module for reception/doctor/finance, the Patient Portal page, the Management
    Portal page) and reads that surface's own JSON REST config, so the nonce is
    the browser session's own.
  * Journeys call the plugin's real REST API from inside those pages. They are
    not UI click-throughs of every form: button-level interaction, Persian
    layout and drag/drop behaviour are not covered here.
  * The patient OTP journey posts the same `/otp/request` payload the public
    booking widget posts (mobile + the persisted clinician/slot/date/time
    selection) from inside the real booking page, then verifies through the
    production `/otp/verify` endpoint. The calendar/slot *click* path and the
    patient portal's own OTP form (which multi-Clinic installs refuse with
    CLINIC_SCOPE_REQUIRED by design) are not driven.
  * clinic_manager and organization_manager prove the portal renders *and* that
    their fixture membership (not a WordPress role) grants the surface. No REST
    call is claimed for them.
  * Screenshot pixels are not inspected.
"""

from __future__ import annotations

import json
import os
import sys
import uuid
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ADMIN_USER = os.environ.get("ADMIN_USER", "cpsd_admin")
ADMIN_PASS = os.environ["ADMIN_PASS"]
EVIDENCE = Path(os.environ.get("CPSD_EVIDENCE_DIR", "/tmp/cpsd-browser"))
EVIDENCE.mkdir(parents=True, exist_ok=True)
CREDENTIALS_FILE = EVIDENCE / "persona-credentials.json"
TARGETS_FILE = Path(os.environ.get("CPSD_TARGETS_FILE", str(EVIDENCE / "persona-targets.json")))

REST = BASE + "/wp-json/clinic/v1"

CHECKS: list[dict[str, object]] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    CHECKS.append({"name": name, "ok": bool(ok), "detail": detail})
    print(("PASS " if ok else "FAIL ") + name + ((" — " + detail) if detail else ""), flush=True)
    if not ok:
        raise AssertionError(name + ": " + detail)


def submit_form(page, action: str, phrase: str) -> None:
    form = page.locator(f'form[data-cpsd-form="{action}"]')
    check(f"form present: {action}", form.count() == 1)
    form.locator('input[name="cpdd_confirm"]').fill(phrase)
    form.locator("button[type=submit]").click()
    page.wait_for_load_state("domcontentloaded")


def admin_login(browser, width: int = 1280, height: int = 900):
    ctx = browser.new_context(viewport={"width": width, "height": height})
    page = ctx.new_page()
    page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", ADMIN_USER)
    page.fill("#user_pass", ADMIN_PASS)
    page.click("#wp-submit")
    page.wait_for_load_state("domcontentloaded")
    check("admin login", "wp-admin" in page.url or page.locator("#wpadminbar").count() > 0, page.url)
    return ctx, page


def persona_login(browser, username: str, password: str):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    page = ctx.new_page()
    page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", username)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    page.wait_for_load_state("domcontentloaded")
    # The patient role is intentionally redirected to a front-end portal; staff
    # roles normally land in wp-admin. Assert real authentication through the
    # WordPress logged-in cookie rather than assuming one redirect target.
    auth_cookie = any(cookie.get("name", "").startswith("wordpress_logged_in_") for cookie in ctx.cookies())
    no_login_error = page.locator("#login_error").count() == 0
    left_login_page = "wp-login.php" not in page.url
    check(f"independent login: {username.split('-')[1] if '-' in username else username}",
          auth_cookie and no_login_error and left_login_page, page.url)
    return ctx, page


# Every production surface prints its own REST config as a JSON script block.
# These are the real containers (verified against the plugin sources):
#   staff portal doctor module   script.cpms-doctor-portal__config
#   staff portal reception       script#cpms-staff-reception-config
#   staff portal finance         script#cpms-finance-board-config
#   staff portal reports         script#cpms-reports-board-config
#   patient portal               script.cpms-patient-portal__config
CONFIG_SELECTORS = (
    "script.cpms-doctor-portal__config",
    "script#cpms-staff-reception-config",
    "script#cpms-finance-board-config",
    "script#cpms-reports-board-config",
    "script.cpms-patient-portal__config",
)

CONFIG_JS = """
(selectors) => {
  const ok = (value) => typeof value === 'string' && /^[0-9a-f]{10}$/.test(value.trim());
  for (const selector of selectors) {
    const node = document.querySelector(selector);
    if (!node) continue;
    try {
      const parsed = JSON.parse(node.textContent || '{}');
      if (parsed && ok(parsed.nonce)) {
        return {
          nonce: String(parsed.nonce).trim(),
          rest_root: String(parsed.rest_root || ''),
          source: selector,
        };
      }
    } catch (e) { /* not the config we are looking for */ }
  }
  for (const name of ['CPMS_Q', 'CPMS_DOC', 'CPMS_FIN']) {
    const config = window[name];
    if (config && ok(config.nonce)) {
      return { nonce: String(config.nonce).trim(), rest_root: String(config.rest_url || ''), source: 'window.' + name };
    }
  }
  if (window.wpApiSettings && ok(window.wpApiSettings.nonce)) {
    return { nonce: String(window.wpApiSettings.nonce).trim(), rest_root: String(window.wpApiSettings.root || ''), source: 'window.wpApiSettings' };
  }
  return { nonce: '', rest_root: '', source: '' };
}
"""


def surface_config(page, label: str) -> dict:
    """Read the REST config the rendered surface itself publishes."""
    config = page.evaluate(CONFIG_JS, list(CONFIG_SELECTORS)) or {}
    nonce = str(config.get("nonce", "") or "")
    if nonce:
        check(f"{label}: surface publishes its own REST nonce ({config.get('source')})", True, page.url)
        return config
    # Diagnostics beat a bare assertion: keep the page so the failure is readable.
    try:
        (EVIDENCE / f"no-config-{label.replace(' ', '-')}.html").write_text(page.content(), encoding="utf-8")
    except Exception:  # pragma: no cover - diagnostics only
        pass
    check(f"{label}: surface publishes its own REST nonce", False, f"url={page.url}")
    return {}


# The public booking widget publishes its own JSON config too. For an anonymous
# visitor it deliberately carries NO nonce (only the patient continuation gets
# one), but it always carries the REST root this install actually serves. On a
# plain-permalink site /wp-json/ is not routed at all, so hardcoding it would
# silently post to the front page instead of the API.
BOOKING_CONFIG_JS = """
(sels) => {
  for (const s of sels) {
    const n = document.querySelector(s);
    if (!n) continue;
    try {
      const p = JSON.parse(n.textContent || '{}');
      if (p && typeof p.rest_root === 'string') return p;
    } catch (e) { /* not the config we are looking for */ }
  }
  return {};
}
"""


def booking_surface_config(page) -> dict:
    """The booking widget's own published config (rest_root + OTP paths)."""
    return page.evaluate(BOOKING_CONFIG_JS, ["script.cpms-public-booking__config"]) or {}


def rest_root_from(config: dict) -> str:
    """Absolute REST root for this install, as the surface itself publishes it."""
    return str(config.get("rest_root") or "").rstrip("/") or REST


def is_json_response(result: dict) -> bool:
    """True when the body parsed as JSON rather than falling through to HTML."""
    data = result.get("data")
    return isinstance(data, dict) and "raw" not in data


def api(page, nonce: str, method: str, path: str, body: dict | None = None,
        headers: dict | None = None, rest_root: str = REST):
    """Call the plugin REST API from inside the authenticated page."""
    payload = json.dumps(body) if body is not None else None
    script = """
    async ([method, url, body, nonce, extra]) => {
      const headers = Object.assign({
        'X-WP-Nonce': nonce,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      }, extra || {});
      const response = await fetch(url, {
        method, headers, credentials: 'same-origin', cache: 'no-store',
        body: body === null ? undefined : body,
      });
      let data = null;
      const text = await response.text();
      try { data = JSON.parse(text); } catch (e) { data = { raw: text.slice(0, 400) }; }
      return { status: response.status, data };
    }
    """
    root = (rest_root or REST).rstrip("/")
    return page.evaluate(script, [method, root + path, payload, nonce, headers or {}])


def code_of(result: dict) -> str:
    data = result.get("data") or {}
    if not isinstance(data, dict):
        return ""
    return str(data.get("code", ""))


def payload_of(result: dict) -> dict:
    """The plugin wraps most reads in {"data": ...}; unwrap one level."""
    data = result.get("data")
    if isinstance(data, dict) and isinstance(data.get("data"), dict):
        return data["data"]
    return data if isinstance(data, dict) else {}


def list_of(result: dict) -> list:
    """List-returning routes nest the array: {"data": [...]} or {"data": {"data": [...]}}."""
    body = result.get("data")
    if isinstance(body, dict) and "data" in body:
        body = body["data"]
    return body if isinstance(body, list) else []


def session_cookies(ctx) -> dict:
    """The WordPress logged-in cookie set, keyed by name, for replay comparisons."""
    return {
        cookie.get("name", ""): cookie.get("value", "")
        for cookie in ctx.cookies()
        if str(cookie.get("name", "")).startswith("wordpress_logged_in_")
    }


def new_idempotency_key() -> str:
    """A canonical UUIDv4, the only shape the payment routes accept."""
    return str(uuid.uuid4())


def scope_headers(clinic_id: int, location_id: int = 0) -> dict:
    headers = {"X-CPMS-Clinic-Id": str(int(clinic_id))}
    if location_id:
        headers["X-CPMS-Location-Id"] = str(int(location_id))
    return headers


def provision_phase(browser) -> None:
    ctx, admin = admin_login(browser)

    # Strict disposable mode must still refuse a non-empty site.
    admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-disposable-demo", wait_until="domcontentloaded")
    check("strict disposable mode still refuses non-empty site",
          admin.locator('[data-cpdd-preflight="fail"]').count() == 1)

    admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
    check("scoped admin page renders", admin.locator('[data-cpsd-page="1"]').count() == 1)

    submit_form(admin, "enable", "I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE")
    check("mode activation accepted", admin.locator('form[data-cpsd-form="provision"]').count() == 1)
    check("scoped preflight passes in browser", admin.locator('[data-cpsd-preflight="pass"]').count() == 1)

    submit_form(admin, "provision", "CREATE_SCOPED_DEMO_ON_TEST_SITE")
    credentials_card = admin.locator('[data-cpsd-secret="credentials"]')
    check("credentials shown once", credentials_card.count() == 1)
    rows = credentials_card.locator("tr[data-cpsd-persona]")
    check("six credential rows", rows.count() == 6, f"rows={rows.count()}")
    creds: dict[str, dict[str, str]] = {}
    for i in range(rows.count()):
        row = rows.nth(i)
        persona = row.get_attribute("data-cpsd-persona") or ""
        username = row.locator("td").nth(1).inner_text().strip()
        password = row.locator("textarea.cpdd-pass-field").input_value().strip()
        creds[persona] = {"username": username, "password": password}
    check("all six credentials non-empty", all(v["username"] and v["password"] for v in creds.values()))
    admin.screenshot(path=str(EVIDENCE / "scoped-admin-provisioned.png"))
    CREDENTIALS_FILE.write_text(json.dumps(creds, ensure_ascii=False, indent=2), encoding="utf-8")

    # One-time display: a reload must not repeat credentials.
    admin.reload(wait_until="domcontentloaded")
    check("credentials not persisted after reload", admin.locator('[data-cpsd-secret="credentials"]').count() == 0)
    ctx.close()


def page_journey(page, label: str, url: str, marker: str) -> None:
    page.goto(url, wait_until="domcontentloaded")
    body = page.content()
    denied = "دسترسی ندارید" in body or "You do not have sufficient permissions" in body
    check(f"{label}: authorised page renders",
          bool(url) and (marker in body) and not denied,
          f"url={page.url} marker={marker in body} denied={denied}")


# The management portal renders the same "no active management access" card for an
# account without a membership, so the marker alone proves nothing.
MANAGEMENT_DENIED = "دسترسی مدیریتی فعال نیست"


def management_journey(page, label: str, url: str, expected: str) -> None:
    page.goto(url, wait_until="domcontentloaded")
    body = page.content()
    denied = MANAGEMENT_DENIED in body or "دسترسی ندارید" in body
    check(f"{label}: management surface is granted by the fixture membership",
          bool(url) and not denied and "cpms-mgmt-card" in body,
          f"url={page.url} denied={denied} cards={page.locator('.cpms-mgmt-card').count()} "
          f"text={page.locator('.cpms-mgmt-shell').inner_text()[:300] if page.locator('.cpms-mgmt-shell').count() else ''}")
    check(f"{label}: portal shows their own scope", bool(expected) and expected in body,
          f"expected={expected!r} present={expected in body}")


def patient_otp_journey(browser, targets: dict) -> None:
    """Real OTP login of the demo patient, then one captured write and its replay.

    Nothing here bypasses a production boundary: the challenge is created by the
    public `/otp/request` route, the code is disclosed only through the scoped
    companion's administrator form (which rebinds the hash of that live
    challenge and never mints a token or a session), and the login, the patient
    read, the hold and the confirm all go through the delivered REST stack.
    """
    clinic_id = int(targets.get("clinic_id") or 0)
    patient_id = int(targets.get("patient_id") or 0)
    patient_wp = int(targets.get("patient_wp_user_id") or 0)
    mobile = str(targets.get("patient_mobile") or "")
    booking = targets.get("booking") or {}
    slot = targets.get("patient_slot") or {}
    fixture_slot = targets.get("slot") or {}
    portals = targets.get("portals") or {}
    foreign = targets.get("foreign") or {}
    patient_url = str((portals.get("patient") or {}).get("url") or "")

    check("the fixture exports the synthetic patient mobile",
          len(mobile) == 11 and mobile.startswith("09"), f"mobile_len={len(mobile)}")
    check("the fixture exports a usable synthetic WP identity for the patient",
          patient_wp > 0 and patient_id > 0, f"wp={patient_wp} patient={patient_id}")
    check("the fixture exports the public booking page it created",
          bool(booking.get("url")) and int(booking.get("id") or 0) > 0,
          json.dumps(booking, ensure_ascii=False))
    check("the fixture reserves a separate bookable slot for the patient",
          bool(slot.get("id")) and int(slot.get("id") or 0) != int(fixture_slot.get("id") or 0),
          json.dumps({"patient_slot": slot, "reception_slot": fixture_slot}, ensure_ascii=False))

    # ------------------------------------------------ anonymous public page
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    page = ctx.new_page()
    page.goto(str(booking.get("url")), wait_until="domcontentloaded")
    check("the anonymous public booking page renders its own OTP step",
          page.locator('[data-role="otp-mobile"]').count() == 1
          and page.locator('[data-role="otp-code"]').count() == 1, f"url={page.url}")
    check("the anonymous visitor starts without a WordPress session",
          session_cookies(ctx) == {}, str(sorted(session_cookies(ctx))))

    booking_cfg = booking_surface_config(page)
    booking_root = rest_root_from(booking_cfg)
    check("the booking widget publishes the REST root this install serves",
          bool(booking_cfg.get("rest_root")), f"root={booking_cfg.get('rest_root')!r}")
    otp_request_path = str(booking_cfg.get("otp_request_path") or "/otp/request")
    otp_verify_path = str(booking_cfg.get("otp_verify_path") or "/otp/verify")
    check("the anonymous widget publishes the OTP continuation paths it uses",
          otp_request_path.endswith("/otp/request") and otp_verify_path.endswith("/otp/verify"),
          f"request={otp_request_path} verify={otp_verify_path}")

    requested = api(page, "", "POST", otp_request_path, {
        "mobile": mobile,
        "purpose": "login",
        "clinician_id": int(slot.get("clinician_id") or 0),
        "slot_id": int(slot.get("id") or 0),
        "slot_date": str(slot.get("date") or ""),
        "slot_time": str(slot.get("time") or ""),
    }, rest_root=booking_root)
    check("the public OTP request creates a challenge bound to the fixture Clinic",
          requested["status"] == 200 and is_json_response(requested),
          f"HTTP {requested['status']} {code_of(requested)} "
          f"{json.dumps(requested.get('data'), ensure_ascii=False)[:200]}")

    # ------------------------------- sanctioned code disclosure (admin form)
    ctx_a, admin = admin_login(browser)
    admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
    submit_form(admin, "otp", "SHOW_SCOPED_DEMO_OTP")
    otp_node = admin.locator("[data-cpsd-otp-code]")
    code = otp_node.inner_text().strip() if otp_node.count() else ""
    check("the scoped admin form shows the code of that live challenge",
          len(code) == 6 and code.isdigit(), f"code_len={len(code)}")
    admin.screenshot(path=str(EVIDENCE / "scoped-admin-otp.png"))
    ctx_a.close()

    # --------------------------------------------- wrong code fails closed
    wrong = "000000" if code != "000000" else "111111"
    refused = api(page, "", "POST", otp_verify_path,
                  {"mobile": mobile, "code": wrong}, rest_root=booking_root)
    check("a wrong OTP code is refused by the production verify path",
          refused["status"] in (400, 401, 422) and is_json_response(refused)
          and code_of(refused) == "CLINIC_OTP_INVALID",
          f"HTTP {refused['status']} {code_of(refused)}")
    check("the refused code issued no WordPress session", session_cookies(ctx) == {},
          str(sorted(session_cookies(ctx))))

    # --------------------------------------------------- real OTP login
    verified = api(page, "", "POST", otp_verify_path,
                   {"mobile": mobile, "code": code}, rest_root=booking_root)
    verified_data = payload_of(verified)
    check("the production verify path issues a session for the OTP code",
          verified["status"] == 200 and is_json_response(verified)
          and verified_data.get("session_issued") is True,
          f"HTTP {verified['status']} {code_of(verified)} "
          f"{json.dumps(verified_data, ensure_ascii=False)[:300]}")
    check("OTP log-in reuses the existing synthetic identity (no new WP user)",
          verified_data.get("is_new_user") is False and int(verified_data.get("user_id") or 0) == patient_wp,
          f"user_id={verified_data.get('user_id')} is_new_user={verified_data.get('is_new_user')}")
    links = verified_data.get("patient_links") if isinstance(verified_data.get("patient_links"), list) else []
    check("the OTP login bound exactly the synthetic patient to that account",
          len(links) == 1 and int((links[0] or {}).get("id") or 0) == patient_id,
          json.dumps(links, ensure_ascii=False)[:300])
    logged_in = session_cookies(ctx)
    check("the browser now holds the WordPress session cookie issued by OTP",
          bool(logged_in), str(sorted(logged_in)))

    # ------------------------------------- the bound identity reads its own data
    page.goto(patient_url, wait_until="domcontentloaded")
    check("the OTP session renders the patient portal",
          page.locator("script.cpms-patient-portal__config").count() == 1, f"url={page.url}")
    cfg = surface_config(page, "otp patient")
    nonce = str(cfg.get("nonce", ""))
    root = str(cfg.get("rest_root", "") or REST)

    me = api(page, nonce, "GET", "/patient/me", rest_root=root)
    me_data = payload_of(me)
    check("the OTP-bound patient reads their own record",
          me["status"] == 200 and int(me_data.get("id") or 0) == patient_id,
          f"HTTP {me['status']} {code_of(me)} id={me_data.get('id')}")
    check("the record returned is the synthetic identity, not another person",
          str(me_data.get("mobile") or "") == mobile,
          f"mobile_match={str(me_data.get('mobile') or '') == mobile}")
    records = api(page, nonce, "GET", "/patient/my-records", rest_root=root)
    listed = list_of(records)
    check("the OTP-bound patient sees exactly one linked record",
          records["status"] == 200 and len(listed) == 1
          and int((listed[0] or {}).get("patient_id") or 0) == patient_id
          and int((listed[0] or {}).get("clinic_id") or 0) == clinic_id,
          f"HTTP {records['status']} {json.dumps(listed, ensure_ascii=False)[:300]}")
    foreign_patient = int(foreign.get("patient_id") or 0)
    check("no foreign patient leaks into the OTP session",
          foreign_patient == 0
          or all(int((row or {}).get("patient_id") or 0) != foreign_patient for row in listed),
          f"foreign_patient={foreign_patient} listed={json.dumps(listed, ensure_ascii=False)[:300]}")

    # ------------------------------------------- replay of the consumed code
    replay = api(page, "", "POST", otp_verify_path,
                 {"mobile": mobile, "code": code}, rest_root=booking_root)
    check("replaying the consumed OTP code is refused (single use)",
          replay["status"] in (400, 401, 422) and code_of(replay) == "CLINIC_OTP_INVALID",
          f"HTTP {replay['status']} {code_of(replay)}")
    check("the replay did not replace the browser session", session_cookies(ctx) == logged_in,
          "cookie set changed")

    # --------------------------- captured write + idempotent replay, as the patient
    hold = api(page, nonce, "POST", "/booking/hold", {
        "clinician_id": int(slot.get("clinician_id") or 0),
        "slot_id": int(slot.get("id") or 0),
        "slot_date": str(slot.get("date") or ""),
        "slot_time": str(slot.get("time") or ""),
    }, rest_root=root)
    hold_data = payload_of(hold)
    hold_token = str(hold_data.get("hold_token") or "")
    check("the OTP-bound patient holds a real slot",
          hold["status"] == 200 and bool(hold_token),
          f"HTTP {hold['status']} {code_of(hold)} {json.dumps(hold_data, ensure_ascii=False)[:200]}")

    key = new_idempotency_key()
    confirm_body = {"hold_token": hold_token, "reason": "scoped demo patient journey"}
    captured = api(page, nonce, "POST", "/booking/confirm", confirm_body,
                   headers={"Idempotency-Key": key}, rest_root=root)
    captured_data = payload_of(captured)
    appointment_id = int(captured_data.get("appointment_id") or captured_data.get("id") or 0)
    check("the captured booking is committed once",
          captured["status"] == 200 and appointment_id > 0,
          f"HTTP {captured['status']} {code_of(captured)} "
          f"{json.dumps(captured_data, ensure_ascii=False)[:200]}")
    check("the committed appointment is a new row, not the fixture appointment",
          appointment_id != int(targets.get("appointment_id") or 0),
          f"appointment={appointment_id} fixture={targets.get('appointment_id')}")

    replayed = api(page, nonce, "POST", "/booking/confirm", confirm_body,
                   headers={"Idempotency-Key": key}, rest_root=root)
    replayed_data = payload_of(replayed)
    check("replaying the identical request returns the same appointment",
          replayed["status"] == 200
          and int(replayed_data.get("appointment_id") or replayed_data.get("id") or 0) == appointment_id,
          f"HTTP {replayed['status']} {code_of(replayed)} "
          f"{json.dumps(replayed_data, ensure_ascii=False)[:200]}")

    mine = api(page, nonce, "GET", "/appointments/mine", rest_root=root)
    mine_list = list_of(mine)
    slot_date = str(slot.get("date") or "")
    slot_time = str(slot.get("time") or "")[:5]
    same_slot = [row for row in mine_list
                 if isinstance(row, dict) and str(row.get("date") or "") == slot_date
                 and str(row.get("time") or "")[:5] == slot_time]
    check("exactly one appointment exists for the captured slot after the replay",
          mine["status"] == 200 and len(same_slot) == 1
          and int(same_slot[0].get("id") or 0) == appointment_id,
          f"HTTP {mine['status']} matches={json.dumps(same_slot, ensure_ascii=False)[:300]}")

    page.screenshot(path=str(EVIDENCE / "journey-patient-otp.png"))
    ctx.close()


def tamper_phase(browser) -> None:
    """The scoped admin surface must refuse a manifest whose HMAC no longer holds."""
    ctx, admin = admin_login(browser)
    admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
    check("the scoped admin page still renders while the manifest is tampered",
          admin.locator('[data-cpsd-page="1"]').count() == 1, f"url={admin.url}")
    wrap_text = admin.locator(".wrap.cpdd").inner_text() if admin.locator(".wrap.cpdd").count() else ""
    check("the tampered manifest is surfaced with its own refusal code",
          "MANIFEST_TAMPERED" in wrap_text, wrap_text[:400])
    check("a tampered manifest offers no scoped action at all",
          admin.locator("form[data-cpsd-form]").count() == 0,
          f"forms={admin.locator('form[data-cpsd-form]').count()} text={wrap_text[:400]}")
    check("no diagnostic or teardown result is rendered for a tampered manifest",
          admin.locator("[data-cpsd-diagnostic], [data-cpsd-teardown]").count() == 0,
          wrap_text[:400])
    admin.screenshot(path=str(EVIDENCE / "scoped-admin-tampered.png"))
    ctx.close()


def journeys_phase(browser) -> None:
    creds = json.loads(CREDENTIALS_FILE.read_text(encoding="utf-8"))
    targets = json.loads(TARGETS_FILE.read_text(encoding="utf-8"))
    ok_targets = bool(targets.get("ok"))
    check("persona targets exported by PHP", ok_targets, str(targets.get("error", "")))

    clinic_id = int(targets.get("clinic_id") or 0)
    visit = targets.get("visit") or {}
    visit_id = int(visit.get("id") or targets.get("visit_id") or 0)
    visit_location = int(visit.get("location_id") or 0)
    invoice_id = int(targets.get("invoice_id") or 0)
    payment_id = int(targets.get("payment_id") or 0)
    slot = targets.get("slot") or {}
    foreign = targets.get("foreign") or {}
    portals = targets.get("portals") or {}
    modules = portals.get("modules") or {}

    check("the fixture exposes the reception module URL", bool(modules.get("reception")), str(modules.get("reception", "")))
    check("the fixture exposes the finance module URL", bool(modules.get("finance")), str(modules.get("finance", "")))
    check("the fixture exposes the doctor module URL", bool(modules.get("doctor")), str(modules.get("doctor", "")))
    check("the fixture exposes the patient portal page", bool((portals.get("patient") or {}).get("url")), str(portals.get("patient", {})))
    check("the fixture exposes the management portal page", bool((portals.get("management") or {}).get("url")), str(portals.get("management", {})))

    # ---------------- receptionist: arrival board + a real booking
    ctx, page = persona_login(browser, creds["receptionist"]["username"], creds["receptionist"]["password"])
    page.goto(modules["reception"], wait_until="domcontentloaded")
    check("receptionist opens the reception module", page.locator("#cpms-staff-reception-config").count() == 1,
          f"url={page.url}")
    cfg = surface_config(page, "receptionist")
    nonce = str(cfg.get("nonce", ""))
    root = str(cfg.get("rest_root", "") or REST)

    # The context route only resolves eligible Locations for an EXPLICITLY
    # selected Clinic (X-CPMS-Clinic-Id), exactly like the delivered selector.
    context = api(page, nonce, "GET", "/staff/portal/reception/context",
                  headers=scope_headers(clinic_id), rest_root=root)
    check("receptionist reads the reception context", context["status"] == 200,
          f"HTTP {context['status']} {code_of(context)}")
    ctx_data = payload_of(context)
    expected_location = int(slot.get("location_id") or 0)
    eligible = [int((row or {}).get("id") or 0) for row in (ctx_data.get("eligible_locations") or [])]
    check("receptionist's demo Clinic is in their reception scope",
          any(int((row or {}).get("id") or 0) == clinic_id for row in (ctx_data.get("clinics") or [])),
          f"clinics={ctx_data.get('clinics')}")
    location_id = expected_location if expected_location in eligible else (eligible[0] if eligible else 0)
    check("receptionist has an eligible operational location", location_id > 0,
          f"eligible={eligible} slot_location={expected_location}")
    headers = scope_headers(clinic_id, location_id)

    board = api(page, nonce, "GET", "/staff/portal/reception/board", headers=headers, rest_root=root)
    check("receptionist reads the arrival board", board["status"] == 200,
          f"HTTP {board['status']} {code_of(board)}")

    check("the fixture exported a bookable slot", bool(slot.get("id")), json.dumps(slot, ensure_ascii=False))
    booking = api(page, nonce, "POST", "/staff/portal/reception/appointments", {
        "patient_id": int(targets["patient_id"]),
        "clinician_id": int(slot.get("clinician_id") or targets["clinician_id"]),
        "slot_id": int(slot.get("id") or 0),
        "reason": "scoped demo reception journey",
    }, headers=headers, rest_root=root)
    check("receptionist books a real appointment", booking["status"] in (200, 201),
          f"HTTP {booking['status']} {code_of(booking)}")
    booked = payload_of(booking)
    appointment = booked.get("appointment") if isinstance(booked.get("appointment"), dict) else booked
    booked_id = int(appointment.get("id") or booked.get("id") or 0)
    check("the booking returns an appointment id", booked_id > 0, f"id={booked_id}")

    board_after = api(page, nonce, "GET", "/staff/portal/reception/board", headers=headers, rest_root=root)
    board_body = board_after.get("data") if isinstance(board_after.get("data"), dict) else {}
    board_date = str(board_body.get("date") or "")
    board_rows = board_body.get("appointments") if isinstance(board_body.get("appointments"), list) else []
    board_ids = [int(row.get("id") or 0) for row in board_rows if isinstance(row, dict)]
    slot_date = str(slot.get("date") or "")
    if slot_date == board_date:
        check("the new appointment appears on today's arrival board",
              board_after["status"] == 200 and booked_id in board_ids,
              f"HTTP {board_after['status']} id={booked_id} board_date={board_date} ids={board_ids[:20]}")
    else:
        # The board endpoint is hardcoded to the operational TODAY (it takes no
        # date parameter), so a booking on a later day can never appear in it.
        # That is the delivered behaviour, so assert it explicitly instead of
        # pretending the board covers the week (and instead of a substring search
        # over JSON, which matches an id inside any other number).
        check("the arrival board is scoped to today and excludes the future booking",
              board_after["status"] == 200 and "" != board_date and booked_id not in board_ids,
              f"HTTP {board_after['status']} id={booked_id} board_date={board_date} "
              f"slot_date={slot_date} ids={board_ids[:20]}")

    # Owner policy 2026-10-15: the secretary may capture a payment but never void.
    if payment_id:
        secretary_void = api(page, nonce, "POST", f"/payments/{payment_id}/void",
                             {"clinic_id": clinic_id, "reason": "scoped demo secretary boundary"},
                             headers=headers, rest_root=root)
        check("receptionist is refused a payment void (default-deny policy)",
              secretary_void["status"] in (401, 403),
              f"HTTP {secretary_void['status']} {code_of(secretary_void)}")
    page.screenshot(path=str(EVIDENCE / "journey-receptionist.png"))
    ctx.close()

    # ---------------- doctor: read the Visit record, then author a real note
    ctx, page = persona_login(browser, creds["doctor"]["username"], creds["doctor"]["password"])
    page.goto(modules["doctor"], wait_until="domcontentloaded")
    check("doctor opens the doctor module", page.locator("script.cpms-doctor-portal__config").count() == 1,
          f"url={page.url}")
    cfg = surface_config(page, "doctor")
    nonce = str(cfg.get("nonce", ""))
    root = str(cfg.get("rest_root", "") or REST)
    doc_headers = scope_headers(clinic_id, visit_location)

    record = api(page, nonce, "GET", f"/doctor/portal/visits/{visit_id}/record",
                 headers=doc_headers, rest_root=root)
    check("doctor reads the visit record", record["status"] == 200,
          f"HTTP {record['status']} {code_of(record)}")

    note = api(page, nonce, "POST", f"/doctor/portal/visits/{visit_id}/notes", {
        "category": "clinical_note",
        "visibility": "patient_visible",
        "content_text": "یادداشت آزمایشی دمو — هرگز داده واقعی نیست.",
    }, headers=doc_headers, rest_root=root)
    check("doctor writes a clinical note", note["status"] in (200, 201),
          f"HTTP {note['status']} {code_of(note)}")

    record_after = api(page, nonce, "GET", f"/doctor/portal/visits/{visit_id}/record",
                       headers=doc_headers, rest_root=root)
    check("the clinical note is in the visit record",
          record_after["status"] == 200 and "یادداشت آزمایشی دمو" in json.dumps(
              record_after.get("data"), ensure_ascii=False),
          f"HTTP {record_after['status']}")

    # Capability boundary: the doctor role has no PAYMENT_CREATE.
    if invoice_id:
        capture = api(page, nonce, "POST", f"/invoices/{invoice_id}/payments",
                      {"clinic_id": clinic_id, "amount": 10, "method": "cash"},
                      headers=dict(doc_headers, **{"Idempotency-Key": "cpsd-doctor-boundary"}),
                      rest_root=root)
        check("doctor is refused a payment capture", capture["status"] in (401, 403),
              f"HTTP {capture['status']} {code_of(capture)}")
    page.screenshot(path=str(EVIDENCE / "journey-doctor.png"))
    ctx.close()

    # ---------------- accountant: finance board + real money rules
    ctx, page = persona_login(browser, creds["accountant"]["username"], creds["accountant"]["password"])
    page.goto(modules["finance"], wait_until="domcontentloaded")
    check("accountant opens the finance module", page.locator("#cpms-finance-board-config").count() == 1,
          f"url={page.url}")
    cfg = surface_config(page, "accountant")
    nonce = str(cfg.get("nonce", ""))
    root = str(cfg.get("rest_root", "") or REST)

    # The invoice is stamped with the Visit's Location, and the mutation route
    # refuses a same-Clinic invoice from another Location, so select that one.
    fin_context = api(page, nonce, "GET", "/staff/portal/finance/context",
                      headers=scope_headers(clinic_id, visit_location), rest_root=root)
    check("accountant reads the finance context", fin_context["status"] == 200,
          f"HTTP {fin_context['status']} {code_of(fin_context)}")
    fin_data = payload_of(fin_context)
    fin_locations = [int((row or {}).get("id") or 0) for row in (fin_data.get("locations") or [])]
    fin_location = int(fin_data.get("location_id") or 0)
    if not fin_location and fin_locations:
        fin_location = fin_locations[0]
    check("the accountant finance scope exposes an operational location",
          fin_location > 0, f"locations={fin_locations} selected={fin_location}")
    check("the accountant can capture payments in this scope",
          bool(fin_data.get("can_capture_payment")), f"can_capture_payment={fin_data.get('can_capture_payment')}")
    fin_headers = scope_headers(clinic_id, fin_location)

    awaiting = api(page, nonce, "GET", "/staff/portal/finance/awaiting-payment",
                   headers=fin_headers, rest_root=root)
    check("accountant reads the awaiting-payment board", awaiting["status"] == 200,
          f"HTTP {awaiting['status']} {code_of(awaiting)}")

    if invoice_id:
        invoice = api(page, nonce, "GET", f"/invoices/{invoice_id}", headers=fin_headers, rest_root=root)
        check("accountant reads the synthetic invoice", invoice["status"] == 200,
              f"HTTP {invoice['status']} {code_of(invoice)}")

        # Real contract: a missing/malformed Idempotency-Key never reaches the service.
        bad_key = api(page, nonce, "POST", f"/staff/portal/finance/invoices/{invoice_id}/payments",
                      {"amount": 100, "method": "cash"},
                      headers=dict(fin_headers, **{"Idempotency-Key": "not-a-uuid"}),
                      rest_root=root)
        check("accountant's malformed Idempotency-Key is refused",
              bad_key["status"] == 400 and code_of(bad_key) == "CLINIC_VALIDATION_FAILED",
              f"HTTP {bad_key['status']} {code_of(bad_key)}")

        # Provisioning settles this invoice, so the service must refuse a further
        # capture: either the closed-invoice guard (409) or the balance guard (422),
        # never a silent second payment.
        settled = api(page, nonce, "POST", f"/staff/portal/finance/invoices/{invoice_id}/payments",
                      {"amount": 1000, "method": "cash"},
                      headers=dict(fin_headers, **{
                          "Idempotency-Key": new_idempotency_key(),
                      }),
                      rest_root=root)
        check("accountant cannot capture against a settled invoice",
              settled["status"] in (409, 422)
              and code_of(settled) in ("CLINIC_INVOICE_NOT_MODIFIABLE", "CLINIC_OVERPAYMENT"),
              f"HTTP {settled['status']} {code_of(settled)}")

    if foreign.get("invoice_id"):
        cross = api(page, nonce, "GET", f"/invoices/{int(foreign['invoice_id'])}",
                    headers=fin_headers, rest_root=root)
        check("browser cross-Clinic read of a pre-existing invoice is denied",
              cross["status"] in (403, 404), f"HTTP {cross['status']} {code_of(cross)}")

    # Capability boundary: the accountant holds no clinical note authority.
    if visit_id:
        note_denied = api(page, nonce, "POST", f"/doctor/portal/visits/{visit_id}/notes",
                          {"category": "clinical_note", "visibility": "patient_visible",
                           "content_text": "should never be written"},
                          headers=fin_headers, rest_root=root)
        check("accountant is refused a clinical note", note_denied["status"] in (401, 403),
              f"HTTP {note_denied['status']} {code_of(note_denied)}")
    page.screenshot(path=str(EVIDENCE / "journey-accountant.png"))
    ctx.close()

    # ---------------- patient: their own portal only
    ctx, page = persona_login(browser, creds["patient"]["username"], creds["patient"]["password"])
    patient_url = str((portals.get("patient") or {}).get("url") or "")
    page.goto(patient_url, wait_until="domcontentloaded")
    check("patient opens the patient portal",
          page.locator("script.cpms-patient-portal__config").count() == 1, f"url={page.url}")
    cfg = surface_config(page, "patient")
    nonce = str(cfg.get("nonce", ""))
    root = str(cfg.get("rest_root", "") or REST)
    # The ONLY production writer of cpms_patient_user_links is the mobile-OTP
    # enrollment flow, and this companion deliberately never reads or echoes an
    # OTP code. The demo patient account is therefore authenticated but not
    # bound to a patient record, so both routes must fail CLOSED: an empty record
    # list and a non-enumerating 404, never somebody else's data.
    me = api(page, nonce, "GET", "/patient/me", rest_root=root)
    check("unbound demo patient gets a non-enumerating 404 for /patient/me",
          me["status"] == 404 and code_of(me) == "CLINIC_NOT_FOUND",
          f"HTTP {me['status']} {code_of(me)}")
    records = api(page, nonce, "GET", "/patient/my-records", rest_root=root)
    # A list-returning route: `success()` wraps it as {"data": [...]}, so the
    # value is a bare array and payload_of (a dict helper) would hide it.
    record_body = (records.get("data") or {})
    if isinstance(record_body, dict) and "data" in record_body:
        record_body = record_body["data"]
    if isinstance(record_body, list):
        listed = record_body
    elif isinstance(record_body, dict):
        listed = record_body.get("records") or []
    else:
        listed = []
    check("unbound demo patient lists no patient records",
          records["status"] == 200 and listed == [],
          f"HTTP {records['status']} records={json.dumps(record_body, ensure_ascii=False)[:200]}")
    queue_denied = api(page, nonce, "GET", "/staff/portal/reception/board",
                       headers=scope_headers(clinic_id, visit_location), rest_root=root)
    check("patient is refused the staff arrival board", queue_denied["status"] in (401, 403),
          f"HTTP {queue_denied['status']} {code_of(queue_denied)}")
    # Scope first, capability second: the staff finance route refuses an
    # unscoped call outright (400), and even with a valid Clinic header it
    # refuses the patient on capability (403). Neither may leak finance data.
    unscoped = api(page, nonce, "GET", "/staff/portal/finance/context", rest_root=root)
    check("patient's finance context fails closed",
          unscoped["status"] == 400 and code_of(unscoped) == "CLINIC_SCOPE_REQUIRED",
          f"HTTP {unscoped['status']} {code_of(unscoped)}")
    # A patient request is never silently promoted to a staff-scoped one: the
    # REST scope boundary only binds a trusted Clinic for staff, so even a
    # perfectly valid Clinic header cannot produce a finance scope - and the
    # refusal carries no scope or finance data.
    scoped = api(page, nonce, "GET", "/staff/portal/finance/context",
                 headers=scope_headers(clinic_id), rest_root=root)
    scoped_body = scoped.get("data") if isinstance(scoped.get("data"), dict) else {}
    leaked = [key for key in ("clinic_id", "locations", "location_id", "can_capture_payment")
              if key in scoped_body]
    check("a valid Clinic header cannot give a patient a staff finance scope",
          scoped["status"] == 400 and code_of(scoped) == "CLINIC_SCOPE_REQUIRED" and not leaked,
          f"HTTP {scoped['status']} {code_of(scoped)} leaked={leaked}")
    page.screenshot(path=str(EVIDENCE / "journey-patient.png"))
    ctx.close()

    # --------------- the patient again, this time bound by a real OTP login
    patient_otp_journey(browser, targets)

    # ---------------- clinic manager: the management portal for their own Clinic
    management_url = str((portals.get("management") or {}).get("url") or "")
    ctx, page = persona_login(browser, creds["clinic_manager"]["username"], creds["clinic_manager"]["password"])
    management_journey(page, "clinic manager", management_url, str(targets.get("clinic_name") or ""))
    page.screenshot(path=str(EVIDENCE / "journey-clinic-manager.png"))
    ctx.close()

    # ---------------- organization manager: the same portal, Organization surface
    ctx, page = persona_login(browser, creds["organization_manager"]["username"],
                              creds["organization_manager"]["password"])
    management_journey(page, "organization manager", management_url,
                       str(targets.get("organization_name") or ""))
    page.screenshot(path=str(EVIDENCE / "journey-organization-manager.png"))
    ctx.close()

    # ---------------- admin: read-only diagnostic then authorised teardown
    ctx, admin = admin_login(browser)
    admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
    submit_form(admin, "diagnose", "RUN_READ_ONLY_SCOPED_DIAGNOSTIC")
    check("the read-only diagnostic page publishes the scoped surface",
          admin.locator('[data-cpsd-page="1"]').count() == 1, f"url={admin.url}")
    diag = admin.locator("[data-cpsd-diagnostic]")
    diag_verdict = (diag.get_attribute("data-cpsd-diagnostic") or "") if diag.count() else ""
    diag_text = diag.inner_text() if diag.count() else ""
    check("the read-only preview publishes a verdict", diag_verdict in ("pass", "fail"),
          f"verdict={diag_verdict!r} text={diag_text[:400]!r}")
    check("teardown preview passes", diag_verdict == "pass",
          f"verdict={diag_verdict!r} problems={diag_text[:400]!r}")
    submit_form(admin, "teardown", "REMOVE_ONLY_SCOPED_DEMO_DATA")
    check("authorized teardown clean", admin.locator('[data-cpsd-teardown="clean"]').count() == 1,
          f"teardown_card={admin.locator('[data-cpsd-teardown]').count()} "
          f"text={admin.locator('.wrap.cpdd').inner_text()[:300] if admin.locator('.wrap.cpdd').count() else ''}")
    admin.screenshot(path=str(EVIDENCE / "scoped-admin-teardown.png"))
    ctx.close()


def write_result(ok: bool, extra: dict | None = None) -> None:
    result = {
        "ok": bool(ok) and all(bool(c["ok"]) for c in CHECKS),
        "checks": CHECKS,
        "base": BASE,
        "limitations": [
            "Journeys call the plugin REST API from the persona's own portal surface, not every UI form",
            "clinic_manager/organization_manager assert the management portal surface, not REST journeys",
            "The patient OTP journey posts the widget's own /otp/request payload from inside the real booking page; the calendar/slot click path and the patient portal's OTP form (which multi-Clinic installs refuse with CLINIC_SCOPE_REQUIRED) are not driven",
            "Password-only patient login stays unbound by design: cpms_patient_user_links is written only by the mobile-OTP enrollment flow, which is exercised through the production /otp/verify route",
            "Screenshot pixels not inspected",
        ],
    }
    if extra:
        result.update(extra)
    phase = str(result.get("phase", ""))
    (EVIDENCE / f"browser-result-{phase}.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    (EVIDENCE / "browser-result.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print("CPSD_BROWSER_RESULT=" + json.dumps({"ok": result["ok"], "checks": len(CHECKS), "phase": result.get("phase", "")},
                                              ensure_ascii=False), flush=True)


def main() -> int:
    phase = sys.argv[1] if len(sys.argv) > 1 else "all"
    ok = True
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch()
            try:
                if phase in ("all", "provision"):
                    provision_phase(browser)
                if phase in ("all", "tamper"):
                    tamper_phase(browser)
                if phase in ("all", "journeys"):
                    journeys_phase(browser)
            finally:
                browser.close()
    except Exception as exc:
        ok = False
        result = {"ok": False, "phase": phase, "checks": CHECKS, "error": f"{type(exc).__name__}: {exc}"}
        (EVIDENCE / f"browser-result-{phase}.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
        print("CPSD_BROWSER_RESULT=" + json.dumps({"ok": False, "phase": phase, "error": result["error"]}, ensure_ascii=False),
              flush=True)
        return 1
    write_result(ok, {"phase": phase})
    return 0


if __name__ == "__main__":
    sys.exit(main())
