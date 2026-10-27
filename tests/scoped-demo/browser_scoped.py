#!/usr/bin/env python3
"""Chromium acceptance for the scoped demo: admin workflow, six logins, and one
real role-specific journey per persona.

Phases (the CI lane runs them in this order so PHP can export identifiers in
between):

  provision  admin form workflow (mode, preflight, provisioning, one-time
             credentials, reload hardening) and writes persona-credentials.json
  journeys   six independent Chromium logins, then one real workflow per
             persona through the production REST stack, from inside the page so
             the cookies and REST nonce are the browser's own; then the admin
             diagnose + teardown forms

Honest scope notes (do not overclaim):
  * Each persona lands on its real production surface (the front-end Staff Portal
    module for reception/doctor/finance, the Patient Portal page, the Management
    Portal page) and reads that surface's own JSON REST config, so the nonce is
    the browser session's own.
  * Journeys call the plugin's real REST API from inside those pages. They are
    not UI click-throughs of every form: button-level interaction, Persian
    layout and drag/drop behaviour are not covered here.
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
          f"url={page.url} denied={denied}")
    check(f"{label}: portal shows their own scope", bool(expected) and expected in body,
          f"expected={expected!r} present={expected in body}")


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

    context = api(page, nonce, "GET", "/staff/portal/reception/context", rest_root=root)
    check("receptionist reads the reception context", context["status"] == 200,
          f"HTTP {context['status']} {code_of(context)}")
    ctx_data = payload_of(context)
    location_id = 0
    for candidate in (ctx_data.get("eligible_locations") or []):
        if int(candidate.get("id") or 0) == int(slot.get("location_id") or 0):
            location_id = int(candidate["id"])
            break
    if not location_id and ctx_data.get("eligible_locations"):
        location_id = int((ctx_data["eligible_locations"][0] or {}).get("id") or 0)
    check("receptionist has an eligible operational location", location_id > 0, f"location_id={location_id}")
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
    booked_id = int(booked.get("id") or booked.get("appointment_id") or 0)
    check("the booking returns an appointment id", booked_id > 0, f"id={booked_id}")

    board_after = api(page, nonce, "GET", "/staff/portal/reception/board", headers=headers, rest_root=root)
    check("the new appointment appears on the arrival board",
          board_after["status"] == 200 and str(booked_id) in json.dumps(board_after.get("data"), ensure_ascii=False),
          f"HTTP {board_after['status']} id={booked_id}")

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

    fin_context = api(page, nonce, "GET", "/staff/portal/finance/context", rest_root=root)
    check("accountant reads the finance context", fin_context["status"] == 200,
          f"HTTP {fin_context['status']} {code_of(fin_context)}")
    fin_data = payload_of(fin_context)
    fin_location = 0
    for candidate in (fin_data.get("eligible_locations") or fin_data.get("locations") or []):
        fin_location = int((candidate or {}).get("id") or 0)
        if fin_location:
            break
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

        # The fixture invoice is settled by provisioning, so a fresh capture must be
        # refused as an overpayment rather than silently accepted.
        overpay = api(page, nonce, "POST", f"/staff/portal/finance/invoices/{invoice_id}/payments",
                      {"amount": 1000, "method": "cash"},
                      headers=dict(fin_headers, **{
                          "Idempotency-Key": new_idempotency_key(),
                      }),
                      rest_root=root)
        check("accountant's overpayment is refused against the remaining balance",
              overpay["status"] == 422 and code_of(overpay) == "CLINIC_OVERPAYMENT",
              f"HTTP {overpay['status']} {code_of(overpay)}")

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
    me = api(page, nonce, "GET", "/patient/me", rest_root=root)
    check("patient reads their own portal profile", me["status"] == 200,
          f"HTTP {me['status']} {code_of(me)}")
    records = api(page, nonce, "GET", "/patient/my-records", rest_root=root)
    check("patient reads their own records", records["status"] == 200,
          f"HTTP {records['status']} {code_of(records)}")
    queue_denied = api(page, nonce, "GET", "/staff/portal/reception/board",
                       headers=scope_headers(clinic_id, visit_location), rest_root=root)
    check("patient is refused the staff arrival board", queue_denied["status"] in (401, 403),
          f"HTTP {queue_denied['status']}")
    finance_denied = api(page, nonce, "GET", "/staff/portal/finance/context", rest_root=root)
    check("patient is refused the finance context", finance_denied["status"] in (401, 403),
          f"HTTP {finance_denied['status']}")
    page.screenshot(path=str(EVIDENCE / "journey-patient.png"))
    ctx.close()

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
    check("teardown preview passes", admin.locator('[data-cpsd-diagnostic="pass"]').count() == 1)
    submit_form(admin, "teardown", "REMOVE_ONLY_SCOPED_DEMO_DATA")
    check("authorized teardown clean", admin.locator('[data-cpsd-teardown="clean"]').count() == 1)
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
