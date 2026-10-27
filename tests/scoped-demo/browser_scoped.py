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
  * Journeys are driven through the plugin's real REST API with real WordPress
    cookies and per-user REST nonces obtained from the plugin's own admin pages.
    They are not UI click-throughs of every form.
  * clinic_manager and organization_manager use an authorised page journey: the
    plugin exposes no REST nonce on their pages, so no REST call is claimed.
  * Screenshot pixels are not inspected.
"""

from __future__ import annotations

import json
import os
import sys
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


NONCE_JS = """
async () => {
  const out = { nonce: '', source: '' };
  const ok = (value, source) => {
    if (typeof value === 'string' && /^[0-9a-f]{10}$/.test(value.trim())) {
      out.nonce = value.trim();
      out.source = source;
      return true;
    }
    return false;
  };
  // 1) Core's own REST nonce endpoint: cookie-bound, role independent.
  try {
    const response = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', {
      credentials: 'same-origin', headers: { 'Accept': 'text/plain' },
    });
    if (response.ok && ok(await response.text(), 'admin-ajax rest-nonce')) return out;
  } catch (e) { /* fall through to the page globals */ }
  // 2) The plugin's own admin pages print their config inline.
  for (const name of ['CPMS_Q', 'CPMS_DOC', 'CPMS_FIN']) {
    const config = window[name];
    if (config && ok(config.nonce, 'window.' + name)) return out;
  }
  if (window.wpApiSettings && ok(window.wpApiSettings.nonce, 'window.wpApiSettings')) return out;
  // 3) The patient portal prints its config as a JSON script block.
  for (const node of document.querySelectorAll('script[type="application/json"]')) {
    try {
      const parsed = JSON.parse(node.textContent || '{}');
      if (parsed && ok(parsed.nonce, 'inline json config')) return out;
    } catch (e) { /* not our config */ }
  }
  // 4) Last resort: any inline config on the page that carries a wp_rest nonce.
  const match = document.documentElement.innerHTML.match(/"nonce"\s*:\s*"([0-9a-f]{10})"/);
  if (match) ok(match[1], 'inline nonce literal');
  return out;
}
"""


def rest_nonce(page) -> str:
    """A REST nonce bound to *this* browser session, whatever page we are on."""
    info = page.evaluate(NONCE_JS) or {}
    nonce = str(info.get("nonce", "") or "")
    if not nonce:
        detail = f"url={page.url}"
        try:
            snippet = page.content()[:240].replace("\n", " ")
            (EVIDENCE / "nonce-debug.html").write_text(page.content(), encoding="utf-8")
            detail += f" body={snippet!r}"
        except Exception as exc:  # pragma: no cover - diagnostics only
            detail += f" body=<unreadable: {exc}>"
        check(f"REST nonce available for {page.url}", False, detail)
        return ""
    check(f"REST nonce bound to the browser session ({info.get('source')})", True, page.url)
    return nonce


def api(page, nonce: str, method: str, path: str, body: dict | None = None, headers: dict | None = None):
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
        method, headers, credentials: 'same-origin',
        body: body === null ? undefined : body,
      });
      let data = null;
      const text = await response.text();
      try { data = JSON.parse(text); } catch (e) { data = { raw: text.slice(0, 400) }; }
      return { status: response.status, data };
    }
    """
    return page.evaluate(script, [method, REST + path, payload, nonce, headers or {}])


def code_of(result: dict) -> str:
    data = result.get("data") or {}
    return str(data.get("code", "")) if isinstance(data, dict) else ""


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
    check(f"{label}: authorised page renders", (marker in body) and not denied,
          f"url={page.url} marker={marker in body} denied={denied}")


def journeys_phase(browser) -> None:
    creds = json.loads(CREDENTIALS_FILE.read_text(encoding="utf-8"))
    targets = json.loads(TARGETS_FILE.read_text(encoding="utf-8"))
    check("persona targets exported by PHP", bool(targets.get("ok")), str(targets.get("error", "")))
    clinic_id = int(targets["clinic_id"])
    visit_id = int(targets["visit_id"])
    invoice_id = int(targets["invoice_id"])
    payment_id = int(targets["payment_id"])
    slot = targets.get("slot") or {}
    foreign = targets.get("foreign") or {}

    # ---------------- receptionist: read today's queue, book, read it back
    ctx, page = persona_login(browser, creds["receptionist"]["username"], creds["receptionist"]["password"])
    page.goto(f"{BASE}/wp-admin/admin.php?page=cpms-queue", wait_until="domcontentloaded")
    check("receptionist opens the queue page", page.locator("#cpms-queue-wrap").count() == 1,
          f"url={page.url}")
    nonce = rest_nonce(page)
    today = api(page, nonce, "GET", f"/secretary/today?clinic_id={clinic_id}")
    check("receptionist reads today's queue", today["status"] == 200, f"HTTP {today['status']}")
    check("receptionist booking has a free synthetic slot", bool(slot.get("date")), json.dumps(slot))
    booking = api(page, nonce, "POST", "/appointments", {
        "clinic_id": clinic_id,
        "patient_id": int(targets["patient_id"]),
        "clinician_id": int(targets["clinician_id"]),
        "slot_date": slot.get("date"),
        "slot_time": slot.get("time"),
        "slot_id": int(slot.get("id") or 0),
        "reason": "scoped demo reception journey",
    })
    check("receptionist books a real appointment", booking["status"] in (200, 201), f"HTTP {booking['status']} {code_of(booking)}")
    booked_id = 0
    data = booking.get("data") or {}
    if isinstance(data, dict):
        booked_id = int(data.get("id") or (data.get("appointment") or {}).get("id") or 0)
    check("booked appointment id is returned", booked_id > 0, f"id={booked_id}")
    listing = api(page, nonce, "GET", f"/appointments?clinician_id={int(targets['clinician_id'])}&date={slot.get('date')}&clinic_id={clinic_id}")
    check("receptionist reads the appointment list back", listing["status"] == 200, f"HTTP {listing['status']}")
    check("the booked appointment is in the list", str(booked_id) in json.dumps(listing.get("data")), f"id={booked_id}")
    # Capability boundary: the queue role must not reach finance surfaces.
    finance = api(page, nonce, "GET", f"/finance/summary?clinic_id={clinic_id}")
    check("receptionist is refused the finance summary", finance["status"] in (401, 403),
          f"HTTP {finance['status']}")
    invoice_read = api(page, nonce, "GET", f"/invoices/{invoice_id}?clinic_id={clinic_id}")
    check("receptionist is refused an invoice read", invoice_read["status"] in (401, 403),
          f"HTTP {invoice_read['status']}")
    page.screenshot(path=str(EVIDENCE / "journey-receptionist.png"))
    ctx.close()

    # ---------------- doctor: read the visit record, add a clinical note
    ctx, page = persona_login(browser, creds["doctor"]["username"], creds["doctor"]["password"])
    page.goto(f"{BASE}/wp-admin/admin.php?page=cpms-doctor", wait_until="domcontentloaded")
    check("doctor opens the doctor page", page.locator("#cpms-doctor-portal-shell, .cpms-doctor-portal-shell").count() >= 1,
          f"url={page.url}")
    nonce = rest_nonce(page)
    record = api(page, nonce, "GET", f"/visits/{visit_id}/record?clinic_id={clinic_id}")
    check("doctor reads the visit record", record["status"] == 200, f"HTTP {record['status']} {code_of(record)}")
    note = api(page, nonce, "POST", f"/visits/{visit_id}/notes", {
        "clinic_id": clinic_id,
        "category": "clinical_note",
        "visibility": "patient_visible",
        "content_text": "یادداشت آزمایشی دمو — هرگز داده واقعی نیست.",
    })
    check("doctor writes a clinical note", note["status"] in (200, 201), f"HTTP {note['status']} {code_of(note)}")
    notes = api(page, nonce, "GET", f"/visits/{visit_id}/notes?clinic_id={clinic_id}")
    check("doctor reads the note list back", notes["status"] == 200, f"HTTP {notes['status']}")
    # Capability boundary: clinical roles cannot capture money.
    payment_denied = api(page, nonce, "POST", f"/invoices/{invoice_id}/payments",
                         {"clinic_id": clinic_id, "amount": 10, "method": "cash"},
                         headers={"Idempotency-Key": "cpsd-doctor-boundary"})
    check("doctor is refused a payment capture", payment_denied["status"] in (401, 403),
          f"HTTP {payment_denied['status']} {code_of(payment_denied)}")
    page.screenshot(path=str(EVIDENCE / "journey-doctor.png"))
    ctx.close()

    # ---------------- accountant: invoice, void, payment, idempotent replay
    ctx, page = persona_login(browser, creds["accountant"]["username"], creds["accountant"]["password"])
    page.goto(f"{BASE}/wp-admin/admin.php?page=cpms-finance", wait_until="domcontentloaded")
    check("accountant opens the finance page", page.locator(".cpms-finance-board").count() >= 1,
          f"url={page.url}")
    nonce = rest_nonce(page)
    invoice = api(page, nonce, "GET", f"/invoices/{invoice_id}?clinic_id={clinic_id}")
    check("accountant reads the invoice", invoice["status"] == 200, f"HTTP {invoice['status']} {code_of(invoice)}")
    if payment_id:
        fixture_void = api(page, nonce, "POST", f"/payments/{payment_id}/void",
                           {"clinic_id": clinic_id, "reason": "scoped demo finance journey"})
        check("accountant voids the fixture payment", fixture_void["status"] in (200, 201),
              f"HTTP {fixture_void['status']} {code_of(fixture_void)}")
    idem = "cpsd-journey-" + str(targets["fixture_id"])[:16]
    payment = api(page, nonce, "POST", f"/invoices/{invoice_id}/payments",
                  {"clinic_id": clinic_id, "amount": 1000, "method": "cash", "transaction_ref": "SCPED-JOURNEY"},
                  headers={"Idempotency-Key": idem})
    check("accountant records a fresh payment", payment["status"] in (200, 201), f"HTTP {payment['status']} {code_of(payment)}")
    payment_data = payment.get("data") or {}
    fresh_id = int(payment_data.get("payment_id") or 0)
    check("the new payment id is reported as payment_id", fresh_id > 0, f"payment_id={fresh_id}")
    replay = api(page, nonce, "POST", f"/invoices/{invoice_id}/payments",
                 {"clinic_id": clinic_id, "amount": 1000, "method": "cash", "transaction_ref": "SCPED-JOURNEY"},
                 headers={"Idempotency-Key": idem})
    replay_data = replay.get("data") or {}
    check("the replayed payment is idempotent (HTTP 200, flagged replay)",
          replay["status"] == 200 and bool(replay_data.get("idempotent_replay")),
          f"HTTP {replay['status']} replay={replay_data.get('idempotent_replay')}")
    if fresh_id:
        void = api(page, nonce, "POST", f"/payments/{fresh_id}/void",
                   {"clinic_id": clinic_id, "reason": "scoped demo finance journey"})
        check("accountant voids the payment it just captured", void["status"] in (200, 201),
              f"HTTP {void['status']} {code_of(void)}")
    summary = api(page, nonce, "GET", f"/finance/summary?clinic_id={clinic_id}")
    check("accountant reads the finance summary", summary["status"] == 200, f"HTTP {summary['status']}")
    if foreign.get("invoice_id"):
        cross = api(page, nonce, "GET", f"/invoices/{int(foreign['invoice_id'])}?clinic_id={clinic_id}")
        check("browser cross-Clinic read of a pre-existing invoice is denied",
              cross["status"] in (403, 404), f"HTTP {cross['status']}")
    page.screenshot(path=str(EVIDENCE / "journey-accountant.png"))
    ctx.close()

    # ---------------- patient: front-end portal read
    ctx, page = persona_login(browser, creds["patient"]["username"], creds["patient"]["password"])
    portal_url = f"{BASE}/?page_id={int(targets.get('portal_page_id') or 0)}"
    page.goto(portal_url, wait_until="domcontentloaded")
    config_text = page.evaluate(
        "() => { const el = document.querySelector('script.cpms-patient-portal__config'); return el ? el.textContent : null; }"
    )
    check("patient portal config is rendered", bool(config_text))
    portal = json.loads(config_text or "{}")
    nonce = str(portal.get("nonce", "")) or rest_nonce(page)
    check("patient REST nonce from the portal config", len(nonce) > 0)
    me = api(page, nonce, "GET", "/patient/me")
    check("patient reads their own portal profile", me["status"] == 200, f"HTTP {me['status']} {code_of(me)}")
    records = api(page, nonce, "GET", "/patient/my-records")
    check("patient reads their own records", records["status"] == 200, f"HTTP {records['status']} {code_of(records)}")
    # The portal session must not open staff surfaces.
    queue_denied = api(page, nonce, "GET", "/secretary/today")
    check("patient is refused the staff queue", queue_denied["status"] in (401, 403),
          f"HTTP {queue_denied['status']}")
    finance_denied = api(page, nonce, "GET", "/finance/summary")
    check("patient is refused the finance summary", finance_denied["status"] in (401, 403),
          f"HTTP {finance_denied['status']}")
    page.screenshot(path=str(EVIDENCE / "journey-patient.png"))
    ctx.close()

    # ---------------- clinic manager: authorised page journey
    ctx, page = persona_login(browser, creds["clinic_manager"]["username"], creds["clinic_manager"]["password"])
    page_journey(page, "clinic manager", f"{BASE}/wp-admin/admin.php?page=cpms-patients", "cpms")
    page.screenshot(path=str(EVIDENCE / "journey-clinic-manager.png"))
    ctx.close()

    # ---------------- organization manager: authorised page journey
    ctx, page = persona_login(browser, creds["organization_manager"]["username"], creds["organization_manager"]["password"])
    page_journey(page, "organization manager", f"{BASE}/wp-admin/admin.php?page=cpms-organizations", "سازمان")
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
            "Journeys use the plugin REST API from an authenticated browser page, not every UI form",
            "clinic_manager/organization_manager journeys assert page renders, not REST journeys",
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
