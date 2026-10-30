#!/usr/bin/env python3
"""Chromium acceptance for the scoped demo: admin workflow, independent logins for
every persona, and one real role-specific journey per persona.

Phases (the CI lane runs them in this order so PHP can export identifiers in
between):

  provision  admin form workflow (mode, preflight, provisioning, one-time
             credentials, reload hardening) and writes persona-credentials.json
  tamper     the sealed manifest is broken out of band (by
             tests/scoped-demo/tamper-manifest.php) and this phase proves the
             scoped admin page refuses it and offers no action at all
  journeys   independent Chromium logins, then one real workflow per
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
    their fixture membership (not a WordPress role) grants the surface. The
    organization manager additionally drives the portal's own lifecycle form
    (activate and deactivate), which is a real form submission through the
    production controller. No REST call is claimed for either persona.
  * In a scoped demo the portal may NOT create additional Clinics: the synthetic
    Organization manager carries an explicit DENY for the production
    `cpms_org_clinic_create` capability, and the demo plugin refuses any portal
    POST of `org_create_clinic` at the `template_redirect` boundary. Both are
    server-side. The journey therefore proves the create form is gone AND that a
    crafted POST is refused with the Persian explanation, and the only way to
    obtain a second demo Clinic is the scoped demo page's own form, which seals
    the Clinic's identity into the ownership ledger before the row exists.
  * Screenshot pixels are not inspected.
"""

from __future__ import annotations

import json
import os
import re
import sys
import uuid
from pathlib import Path

from playwright.sync_api import sync_playwright

JOURNEYS = (
    # Machine-readable registry of the journeys this harness implements. The
    # companion compatibility evaluator (.github/scripts/companion-compat)
    # matches manifest `features[].journeys` against these identifiers, so a
    # feature cannot claim coverage that no harness provides.
    "cpsd_admin_provision",
    "cpsd_manifest_tamper_refused",
    "cpsd_persona_receptionist",
    "cpsd_persona_doctor",
    "cpsd_persona_accountant",
    "cpsd_persona_clinic_manager",
    "cpsd_persona_organization_manager",
    "cpsd_org_manager_clinic_lifecycle",
    "cpsd_patient_otp_booking",
    "cpsd_staff_messaging_privacy",
    "cpsd_visit_pricing_invoice",
    "cpsd_admin_teardown",
)

# The personas the scoped fixture provisions and provisions credentials for. The
# credential card is asserted against this list, so adding a persona can never
# silently shrink the proof.
PERSONAS = (
    "organization_manager",
    "clinic_manager",
    "doctor",
    "doctor_second",
    "receptionist",
    "receptionist_second",
    "accountant",
    "patient",
)

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ADMIN_USER = os.environ.get("ADMIN_USER", "cpsd_admin")
ADMIN_PASS = os.environ["ADMIN_PASS"]
EVIDENCE = Path(os.environ.get("CPSD_EVIDENCE_DIR", "/tmp/cpsd-browser"))
EVIDENCE.mkdir(parents=True, exist_ok=True)
CREDENTIALS_FILE = EVIDENCE / "persona-credentials.json"
TARGETS_FILE = Path(os.environ.get("CPSD_TARGETS_FILE", str(EVIDENCE / "persona-targets.json")))

REST = BASE + "/wp-json/clinic/v1"

CHECKS: list[dict[str, object]] = []
# Browser-side evidence collected by individual journeys, published in the result
# file so a reviewer never has to trust a log line alone.
JOURNEY_EVIDENCE: dict[str, object] = {}


def annotate(title: str, message: str) -> None:
    """Publish a GitHub Actions annotation so a CI failure names itself.

    The lane's step logs are not fetchable from this repository's tooling, so a
    bare "process exited 1" would say nothing about which browser assertion
    failed. `::error` lines are parsed from stdout — including through `tee` —
    and land in the check-run annotation.
    """
    flat = message.replace("%", "%25").replace("\r", "").replace("\n", " ")
    print(f"::error title={title}::{flat}", flush=True)


def check(name: str, ok: bool, detail: str = "") -> None:
    CHECKS.append({"name": name, "ok": bool(ok), "detail": detail})
    print(("PASS " if ok else "FAIL ") + name + ((" — " + detail) if detail else ""), flush=True)
    if not ok:
        annotate("SCOPED_BROWSER", name + ((" — " + detail) if detail else ""))
        raise AssertionError(name + ": " + detail)


def submit_form(page, action: str, phrase: str | None = None) -> None:
    """Submit one scoped action, asserting exactly the phrase policy it has.

    The typed phrase is anti-misclick friction, not an authorization control, so
    it now exists only where the action is irreversible (teardown, and the
    private-host acceptance whose phrase is this site's own host name). The drill
    asserts the *presence* of the field where a phrase is required and its
    *absence* everywhere else: filling a field that should not be there, or
    skipping one that should be, is a real regression in either direction.
    """
    form = page.locator(f'form[data-cpsd-form="{action}"]')
    check(f"form present: {action}", form.count() == 1)
    confirm = form.locator('input[name="cpdd_confirm"]')
    if phrase:
        check(f"phrase required: {action}", confirm.count() == 1)
        confirm.fill(phrase)
    else:
        check(f"no typed phrase: {action}", confirm.count() == 0)
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


def persona_login(browser, username: str, password: str, width: int = 1280, height: int = 900):
    ctx = browser.new_context(viewport={"width": width, "height": height})
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
#   staff portal messages        script#cpms-messages-config
#   staff portal pricing         script#cpms-pricing-config
#   patient portal               script.cpms-patient-portal__config
CONFIG_SELECTORS = (
    "script#cpms-messages-config",
    "script#cpms-pricing-config",
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
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      }, extra || {});
      // An EMPTY header is not "no header": WordPress treats a present but
      // unverifiable X-WP-Nonce as a failed cookie check (403
      // rest_cookie_invalid_nonce). Public routes must send no nonce at all.
      if (nonce) { headers['X-WP-Nonce'] = nonce; }
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


def rtl_mobile_check(page, selector: str, label: str, width: int) -> dict:
    """Assert a Persian module is right-to-left and does not overflow a phone.

    The demo is shown to Users on phones, so the two widths the design targets
    (320px and 390px) are measured for real: document direction, document-level
    horizontal overflow, the module root's own overflow, and the root's painted
    box inside the viewport. A horizontally scrolled inner table is fine and is
    not what this measures.
    """
    metrics = page.evaluate(
        """(sel) => {
            const el = document.querySelector(sel);
            const rect = el ? el.getBoundingClientRect() : null;
            const wide = Array.from(document.querySelectorAll('body *'))
                .filter((node) => node.getBoundingClientRect().width > window.innerWidth + 1)
                .slice(0, 3)
                .map((node) => node.tagName.toLowerCase() + '.' + (typeof node.className === 'string' ? node.className : ''));
            return {
                dir: getComputedStyle(document.documentElement).direction,
                bodyDir: getComputedStyle(document.body).direction,
                docScroll: document.documentElement.scrollWidth,
                inner: window.innerWidth,
                rootScroll: el ? el.scrollWidth : 0,
                rootClient: el ? el.clientWidth : 0,
                left: rect ? rect.left : null,
                right: rect ? rect.right : null,
                wide: wide,
            };
        }""",
        selector,
    )
    check(f"{label} @{width}px renders right-to-left",
          metrics["dir"] == "rtl" and metrics["bodyDir"] == "rtl", str(metrics["dir"]))
    check(f"{label} @{width}px does not scroll horizontally",
          int(metrics["docScroll"]) <= int(metrics["inner"]) + 1,
          f"scrollWidth={metrics['docScroll']} viewport={metrics['inner']} wide={metrics['wide']}")
    check(f"{label} @{width}px keeps its module inside the viewport",
          int(metrics["rootScroll"]) <= int(metrics["rootClient"]) + 1
          and metrics["left"] is not None
          and float(metrics["left"]) >= -1
          and float(metrics["right"]) <= int(metrics["inner"]) + 1,
          f"root={metrics['rootScroll']}/{metrics['rootClient']} box={metrics['left']}..{metrics['right']} wide={metrics['wide']}")
    return metrics


def provision_phase(browser) -> None:
    ctx, admin = admin_login(browser)

    # Strict disposable mode must still refuse a non-empty site.
    admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-disposable-demo", wait_until="domcontentloaded")
    check("strict disposable mode still refuses non-empty site",
          admin.locator('[data-cpdd-preflight="fail"]').count() == 1)

    admin.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
    check("scoped admin page renders", admin.locator('[data-cpsd-page="1"]').count() == 1)

    submit_form(admin, "enable")
    check("mode activation accepted", admin.locator('form[data-cpsd-form="provision"]').count() == 1)
    check("scoped preflight passes in browser", admin.locator('[data-cpsd-preflight="pass"]').count() == 1)

    submit_form(admin, "provision")
    credentials_card = admin.locator('[data-cpsd-secret="credentials"]')
    check("credentials shown once", credentials_card.count() == 1)
    rows = credentials_card.locator("tr[data-cpsd-persona]")
    check("one credential row per persona", rows.count() == len(PERSONAS), f"rows={rows.count()}")
    creds: dict[str, dict[str, str]] = {}
    for i in range(rows.count()):
        row = rows.nth(i)
        persona = row.get_attribute("data-cpsd-persona") or ""
        username = row.locator("td").nth(1).inner_text().strip()
        password = row.locator("textarea.cpdd-pass-field").input_value().strip()
        creds[persona] = {"username": username, "password": password}
    check("all credentials non-empty", all(v["username"] and v["password"] for v in creds.values()))
    check("every declared persona received credentials",
          sorted(creds.keys()) == sorted(PERSONAS), json.dumps(sorted(creds.keys())))
    admin.screenshot(path=str(EVIDENCE / "scoped-admin-provisioned.png"))
    CREDENTIALS_FILE.write_text(json.dumps(creds, ensure_ascii=False, indent=2), encoding="utf-8")

    # The Owner's one-click dashboard: one page that says what is true and offers
    # the next safe action. It is the first screen after activation, so it is
    # exercised over real HTTP with a real browser, not only in the unit harness.
    check("the dashboard is reachable by its own slug",
          admin.goto(f"{BASE}/wp-admin/admin.php?page=cpms-demo", wait_until="domcontentloaded") is not None)
    check("the dashboard reports the sealed demo as ready",
          admin.locator('[data-cpms-demo-state="ready"]').count() == 1)
    dash_rows = admin.locator('[data-cpsd-persona-portal]')
    check("the dashboard maps every persona", dash_rows.count() == len(PERSONAS), f"rows={dash_rows.count()}")
    dash_keys = sorted({dash_rows.nth(i).get_attribute("data-cpsd-persona-portal") or ""
                        for i in range(dash_rows.count())})
    check("the dashboard names the same eight personas as the fixture", dash_keys == sorted(PERSONAS), json.dumps(dash_keys))
    dash_rotate = admin.locator('form[data-cpsd-form="rotate_credentials"]')
    check("the dashboard offers credential rotation in one click", dash_rotate.count() == 1)
    check("dashboard rotation asks for no typed phrase",
          dash_rotate.locator('input[name="cpdd_confirm"]').count() == 0)
    check("the dashboard never offers the destructive action",
          admin.locator('form[data-cpsd-form="teardown"]').count() == 0)
    check("the dashboard links the advanced console",
          admin.locator('[data-cpms-demo-console="1"]').count() >= 1)
    admin.screenshot(path=str(EVIDENCE / "dashboard-ready.png"))

    # The Product Owner's map of the demo: one row per persona, each naming its
    # portal and linking straight to it - and NO password anywhere in it.
    access = admin.locator('[data-cpsd-persona-access="1"]')
    check("the persona-access card is rendered", access.count() == 1)
    access_rows = access.locator("tr[data-cpsd-persona-portal]")
    check("the persona-access card covers every persona", access_rows.count() == len(PERSONAS),
          f"rows={access_rows.count()}")
    access_keys = [access_rows.nth(i).get_attribute("data-cpsd-persona-portal") or ""
                   for i in range(access_rows.count())]
    check("every persona is named and mapped to a portal",
          sorted(k for k in access_keys if k) == sorted(PERSONAS), json.dumps(access_keys))
    links_ok = True
    for i in range(access_rows.count()):
        row = access_rows.nth(i)
        href = row.locator("a").first.get_attribute("href") if row.locator("a").count() else ""
        if not href or not href.startswith("http"):
            links_ok = False
    check("every persona row links to a real portal URL", links_ok)
    # The portal hint is a data contract between the card and the persona: a
    # wrong mapping sends the Owner to a screen the persona cannot use.
    expected_portals = {
        "organization_manager": "management",
        "clinic_manager": "management",
        "doctor": "doctor",
        "doctor_second": "doctor",
        "receptionist": "receptionist",
        "receptionist_second": "receptionist",
        "accountant": "accountant",
        "patient": "patient",
    }
    actual_portals = {
        (access_rows.nth(i).get_attribute("data-cpsd-persona-portal") or ""):
        (access_rows.nth(i).get_attribute("data-cpsd-portal-link") or "")
        for i in range(access_rows.count())
    }
    check("every persona is mapped to its own portal", actual_portals == expected_portals,
          json.dumps(actual_portals, ensure_ascii=False))
    rows_described = all(
        len((access_rows.nth(i).locator("td").nth(1).inner_text() or "").strip()) > 0
        and len((access_rows.nth(i).locator("td").nth(2).inner_text() or "").strip()) > 0
        for i in range(access_rows.count())
    )
    check("every persona row states portal and purpose", rows_described)
    check("the persona-access card exposes no password field",
          access.locator("textarea, input").count() == 0,
          f"inputs={access.locator('textarea, input').count()}")
    JOURNEY_EVIDENCE["persona_access"] = {"rows": access_rows.count(), "keys": access_keys}

    # One-time display: a reload must not repeat credentials, but the persona map
    # is persistent (it contains no secret).
    admin.reload(wait_until="domcontentloaded")
    check("credentials not persisted after reload", admin.locator('[data-cpsd-secret="credentials"]').count() == 0)
    check("the persona map persists across reloads",
          admin.locator('[data-cpsd-persona-access="1"]').count() == 1)
    ctx.close()


def repair_phase(browser) -> None:
    """The Owner's actual starting point: baseline lost, eight portals alive.

    Precondition: `tests/scoped-demo/owner-incident.php` (mode `stage`) has put
    the site into the state a real install reported - manifest `failed`, the
    sealed pre-provisioning baseline gone, eight `cpsd-…` accounts and every
    synthetic row present, teardown refusing BASELINE_MISSING and the preflight
    refusing a second fixture.

    This phase proves the only thing the Owner needed: ONE Persian action on the
    page they already had, after which the eight real portals are reachable, with
    no SQL, no user deletion and no plugin disabled - and that clicking it twice
    changes nothing.
    """
    ctx, admin = admin_login(browser)
    dashboard = f"{BASE}/wp-admin/admin.php?page=cpms-demo"
    admin.goto(dashboard, wait_until="domcontentloaded")

    check("the incident state is reported as usable, not as a dead end",
          admin.locator('[data-cpms-demo-state="usable"]').count() == 1)
    check("the blocked teardown is stated on the page",
          admin.locator('[data-cpms-demo-teardown-blocked="BASELINE_MISSING"]').count() == 1)
    launch = admin.locator('form[data-cpsd-form="launch"]')
    check("the one obvious action is offered", launch.count() == 1)
    check("the additive repair asks for no typed phrase",
          launch.locator('input[name="cpdd_confirm"]').count() == 0)
    check("the page offers no destructive action",
          admin.locator('form[data-cpsd-form="teardown"]').count() == 0)
    check("the additive repair is not mistaken for a resume",
          admin.locator('form[data-cpsd-form="resume"]').count() == 0)
    admin.screenshot(path=str(EVIDENCE / "repair-incident-page.png"))

    submit_form(admin, "launch")
    notice = admin.locator('.notice-success')
    check("the one action reports success", notice.count() >= 1)
    check("the page now says the demo is activated",
          admin.locator('[data-cpms-demo-activated="1"]').count() == 1)

    credentials_card = admin.locator('[data-cpms-demo-secret="credentials"]')
    check("the one action shows the eight one-time passwords", credentials_card.count() == 1)
    rows = credentials_card.locator("tr[data-cpsd-persona]")
    check("one credential row per persona", rows.count() == len(PERSONAS), f"rows={rows.count()}")
    creds: dict[str, dict[str, str]] = {}
    for i in range(rows.count()):
        row = rows.nth(i)
        persona = row.get_attribute("data-cpsd-persona") or ""
        username = row.locator("td").nth(1).inner_text().strip()
        password = row.locator("textarea.cpdd-pass-field").input_value().strip()
        creds[persona] = {"username": username, "password": password}
    check("the rotated credentials are complete",
          sorted(creds.keys()) == sorted(PERSONAS) and all(v["username"] and v["password"] for v in creds.values()),
          json.dumps(sorted(creds.keys())))

    access = admin.locator('[data-cpsd-persona-access="1"]')
    check("the eight portal links are on the same page", access.count() == 1)
    access_rows = access.locator("tr[data-cpsd-persona-portal]")
    check("the portal map covers every persona", access_rows.count() == len(PERSONAS),
          f"rows={access_rows.count()}")
    doctor_link = ""
    for i in range(access_rows.count()):
        row = access_rows.nth(i)
        if (row.get_attribute("data-cpsd-persona-portal") or "") == "doctor":
            doctor_link = row.locator("a").first.get_attribute("href") or ""
    check("the doctor portal link is a real URL", doctor_link.startswith("http"), doctor_link)
    admin.screenshot(path=str(EVIDENCE / "repair-portals.png"))

    # The purpose of the whole exercise: a rotated password must really open the
    # real production portal of that persona.
    if creds.get("doctor", {}).get("username") and doctor_link:
        doc_ctx, doc_page = persona_login(browser, creds["doctor"]["username"], creds["doctor"]["password"])
        doc_page.goto(doctor_link, wait_until="domcontentloaded")
        body = doc_page.content()
        denied = "دسترسی ندارید" in body or "You do not have sufficient permissions" in body
        check("the doctor portal renders for the rotated password",
              doc_page.locator("script.cpms-doctor-portal__config").count() >= 1 and not denied,
              f"url={doc_page.url} denied={denied}")
        doc_page.screenshot(path=str(EVIDENCE / "repair-doctor-portal.png"))
        doc_ctx.close()
    else:
        check("the doctor portal was opened with the rotated password", False, "no doctor credentials")

    # A second click must be a no-op: same fixture, same portals, no new rows.
    admin.goto(dashboard, wait_until="domcontentloaded")
    submit_form(admin, "launch")
    check("a second click keeps the same demo activated",
          admin.locator('[data-cpms-demo-activated="1"]').count() == 1)
    check("a second click still maps every persona",
          admin.locator('tr[data-cpsd-persona-portal]').count() == len(PERSONAS),
          f"rows={admin.locator('tr[data-cpsd-persona-portal]').count()}")
    check("a second click still offers no destructive action",
          admin.locator('form[data-cpsd-form="teardown"]').count() == 0)
    admin.screenshot(path=str(EVIDENCE / "repair-second-click.png"))

    JOURNEY_EVIDENCE["repair"] = {
        "activated": True,
        "personas": sorted(creds.keys()),
        "doctor_portal": doctor_link,
    }
    ctx.close()


def sessions_phase(browser) -> None:
    """Eight passwordless transitions, real sessions, and one honest boundary.

    For each persona the ADMIN session issues a single-use ticket (the POST the
    Owner's button sends), and the ticket is redeemed in a SEPARATE browser
    context. That is what makes the isolation real rather than claimed: a different
    cookie jar, so the administrator session in the first context is provably still
    the administrator's afterwards. (Two tabs of one browser share cookies; the page
    says so, and this drill never pretends otherwise.)

    It also proves the abuse cases: a persona cannot open the admin dashboard, an
    unauthenticated POST cannot obtain a ticket, a redeemed ticket cannot be replayed
    and cannot be replayed against another persona.
    """
    admin_ctx, admin = admin_login(browser)
    dashboard = f"{BASE}/wp-admin/admin.php?page=cpms-demo"
    admin.goto(dashboard, wait_until="domcontentloaded")

    launcher = admin.locator('[data-cpsd-persona-launcher="1"]')
    check("the dashboard shows the persona launcher", launcher.count() == 1)
    tiles = launcher.locator("[data-cpsd-persona-tile]")
    check("the launcher is eight tiles", tiles.count() == len(PERSONAS), f"tiles={tiles.count()}")
    check("each tile carries its own one-click button",
          launcher.locator("[data-cpsd-open-persona]").count() == len(PERSONAS))
    check("the cookie-sharing limitation is stated on the page",
          launcher.locator('[data-cpsd-cookie-note="1"]').count() == 1)
    authority = admin.locator('[data-cpsd-authority="1"]')
    check("the read-only authority matrix is rendered", authority.count() == 1)
    admin_row = authority.locator("[data-cpsd-authority-admin]")
    check("the matrix names the administrator row", admin_row.count() == 1)
    admin_allows = authority.locator("[data-cpsd-admin-allow]")
    granted = [admin_allows.nth(i).get_attribute("data-cpsd-admin-allow") for i in range(admin_allows.count())]
    check("being an administrator grants no clinical/financial/tenant/messaging authority",
          admin_allows.count() == 4 and all(v == "0" for v in granted), json.dumps(granted))

    # Read the nonce + fingerprint + action once: they are the same for every tile.
    form0 = launcher.locator('[data-cpsd-persona-form="doctor"]')
    check("the per-persona form is a POST to the dashboard",
          form0.count() == 1 and (form0.get_attribute("target") or "") == "_blank")
    tokens = {name: form0.locator(f'input[name="{name}"]').input_value()
              for name in ("cpsd_action", "cpdd_nonce", "cpdd_site")}

    transitions: dict[str, str] = {}
    for persona in PERSONAS:
        # Issue the ticket with the ADMIN session, without following the redirect.
        post = admin.request.post(
            f"{BASE}/wp-admin/admin.php?page=cpms-demo",
            form={**tokens, "cpsd_persona": persona},
            max_redirects=0,
        )
        location = post.headers.get("location", "")
        check(f"admin can issue a ticket for {persona}",
              post.status in (302, 303) and "cpsd_persona_entry=" in location,
              f"status={post.status} location={location[:80]}")
        if "cpsd_persona_entry=" not in location:
            continue
        ticket = location if location.startswith("http") else BASE + location

        # Redeem in a SEPARATE context: its own cookie jar.
        persona_ctx = browser.new_context(viewport={"width": 1280, "height": 900})
        persona_page = persona_ctx.new_page()
        persona_page.goto(ticket, wait_until="domcontentloaded")
        check(f"{persona}: the ticket lands on its own portal",
              "/wp-login.php" not in persona_page.url, persona_page.url)

        # The boundary must be visible on the persona's own surface. The
        # server-rendered banner is authoritative (it does not depend on the theme
        # rendering an admin bar); the admin-bar node is accepted as a second form.
        banner = persona_page.locator('[data-cpsd-session-boundary="1"]')
        bar_node = persona_page.locator("#wp-admin-bar-cpsd-demo-persona-session")
        check(f"{persona}: the session boundary is visible",
              banner.count() + bar_node.count() >= 1,
              f"banner={banner.count()} admin_bar={bar_node.count()} url={persona_page.url}")
        check(f"{persona}: the boundary names the persona it belongs to",
              banner.count() != 1 or (banner.get_attribute("data-cpsd-persona") == persona),
              banner.get_attribute("data-cpsd-persona") if banner.count() == 1 else "")
        account = persona_ctx.cookies()
        check(f"{persona}: the context holds that account's own WordPress session",
              any(c.get("name", "").startswith("wordpress_logged_in_") for c in account))

        # The persona cannot reach the administrator dashboard. The property that
        # matters is that the launcher, its forms and its nonce never render for a
        # persona - not which language WordPress chooses for its denial page.
        persona_page.goto(dashboard, wait_until="domcontentloaded")
        body = persona_page.content()
        check(f"{persona}: the administrator dashboard is denied to a persona",
              'data-cpsd-persona-launcher' not in body and 'cpsd_persona_entry' not in body,
              persona_page.url)

        # A persona cannot mint a ticket - not for itself and not for another
        # persona. This is the cross-persona escalation attempt in its simplest form.
        if persona == PERSONAS[0]:
            escalated = persona_page.request.post(
                f"{BASE}/wp-admin/admin.php?page=cpms-demo",
                form={**tokens, "cpsd_persona": "clinic_manager"},
                max_redirects=0,
            )
            check("a persona cannot mint a ticket for another persona",
                  "cpsd_persona_entry=" not in escalated.headers.get("location", ""),
                  f"status={escalated.status} location={escalated.headers.get('location', '')[:80]}")

        # Cross-role denial on the administrator console as well.
        persona_page.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
        console_body = persona_page.content()
        check(f"{persona}: the scoped console is denied to a persona",
              'data-cpsd-page="1"' not in console_body
              and 'data-cpsd-persona-access' not in console_body
              and 'cpsd_action' not in console_body,
              persona_page.url)
        # The obvious, secure way back: the boundary node ends THIS session and
        # leaves the account with nothing. It must not hand out administrator
        # authority on the way out either.
        transitions[persona] = persona_page.url
        persona_page.goto(f"{BASE}/", wait_until="domcontentloaded")
        exit_link = persona_page.locator('[data-cpsd-session-exit="1"]')
        if exit_link.count() == 0:
            exit_link = persona_page.locator("#wp-admin-bar-cpsd-demo-persona-session-exit a")
        check(f"{persona}: the one-click way back is visible", exit_link.count() == 1)
        if exit_link.count() == 1:
            # A real click on the real link. If a theme overlay intercepts the
            # pointer, follow the same href rather than giving up: the server side
            # of the exit (nonce, session check, logout) is what is under test.
            try:
                exit_link.click(timeout=5000)
            except Exception:  # noqa: BLE001 - any interception falls back to its href
                persona_page.goto(exit_link.get_attribute("href"), wait_until="domcontentloaded")
            persona_page.wait_for_load_state("domcontentloaded")
            check(f"{persona}: the session ends and reports it",
                  "cpsd_persona_ended=1" in persona_page.url, persona_page.url)
            check(f"{persona}: ending the session leaves no WordPress session behind",
                  not any(c.get("name", "").startswith("wordpress_logged_in_")
                          for c in persona_ctx.cookies()))
            persona_page.goto(dashboard, wait_until="domcontentloaded")
            check(f"{persona}: after ending the session the dashboard demands a login",
                  "/wp-login.php" in persona_page.url, persona_page.url)
        persona_ctx.close()

    check("all eight transitions completed", sorted(transitions.keys()) == sorted(PERSONAS),
          json.dumps(sorted(transitions.keys())))

    # Replay: the very first ticket was already consumed; the store holds only the
    # most recent one, so reusing a stale URL must never authenticate anybody.
    replay_ctx = browser.new_context()
    replay_page = replay_ctx.new_page()
    first_persona = PERSONAS[0]
    stale = admin.request.post(
        f"{BASE}/wp-admin/admin.php?page=cpms-demo",
        form={**tokens, "cpsd_persona": first_persona},
        max_redirects=0,
    ).headers.get("location", "")
    # Consume it once, then try the same URL again in a fresh context.
    used_ctx = browser.new_context()
    used_page = used_ctx.new_page()
    used_page.goto(stale if stale.startswith("http") else BASE + stale, wait_until="domcontentloaded")
    check("the ticket was valid the first time it was used",
          "/wp-login.php" not in used_page.url, used_page.url)
    replay_page.goto(stale if stale.startswith("http") else BASE + stale, wait_until="domcontentloaded")
    check("a consumed ticket is refused on replay",
          "cpsd_persona_error=" in replay_page.url and "/wp-login.php" in replay_page.url,
          replay_page.url)
    check("the replay established no session",
          not any(c.get("name", "").startswith("wordpress_logged_in_") for c in replay_ctx.cookies()))
    replay_ctx.close()
    used_ctx.close()

    # Unauthenticated visitor: no ticket, no session.
    anon_ctx = browser.new_context()
    anon_page = anon_ctx.new_page()
    anon = anon_page.request.post(
        f"{BASE}/wp-admin/admin.php?page=cpms-demo",
        form={**tokens, "cpsd_persona": "doctor"},
        max_redirects=0,
    )
    check("an unauthenticated POST cannot obtain a ticket",
          "cpsd_persona_entry=" not in anon.headers.get("location", ""),
          f"status={anon.status} location={anon.headers.get('location', '')[:80]}")
    check("an unauthenticated POST created no session",
          not any(c.get("name", "").startswith("wordpress_logged_in_") for c in anon_ctx.cookies()))
    anon_ctx.close()

    # The administrator session survived every transition above.
    admin.goto(dashboard, wait_until="domcontentloaded")
    check("the administrator is still signed in after eight persona transitions",
          admin.locator('[data-cpsd-persona-launcher="1"]').count() == 1)
    check("the administrator still sees the eight buttons",
          admin.locator("[data-cpsd-open-persona]").count() == len(PERSONAS))
    JOURNEY_EVIDENCE["persona_sessions"] = {"transitions": sorted(transitions.keys()),
                                            "admin_authority": granted}
    admin_ctx.close()


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


def lifecycle_phase(browser, creds: dict, targets: dict, management_url: str) -> dict:
    """The synthetic Organization manager drives the portal's own lifecycle forms.

    Browser half of two properties:

    * the Organization manager deactivates and reactivates the demo Clinic through
      the production `org_clinic_status` form (which stamps
      `status_updated_by_wp_user_id` with that synthetic account), and the admin
      teardown that follows must still complete;
    * the portal can NOT create a second Clinic in scoped demo mode. The
      production resolver denies `cpms_org_clinic_create` to this persona, so the
      create form is not rendered at all, and a crafted POST of the same action
      is refused by the demo plugin's `template_redirect` guard with a Persian
      explanation instead of reaching the controller.
    """
    clinic_id = int(targets.get("clinic_id") or 0)
    organization_id = int(targets.get("organization_id") or 0)
    check("lifecycle: the demo Clinic and Organization ids are known",
          clinic_id > 1 and organization_id > 1, f"clinic={clinic_id} org={organization_id}")

    evidence: dict[str, object] = {"clinic_id": clinic_id, "organization_id": organization_id}
    ctx, page = persona_login(browser, creds["organization_manager"]["username"],
                              creds["organization_manager"]["password"])
    try:
        # The Organization overview is an explicitly selected surface: the portal
        # reads `cpms-org` from the query string (ManagementPortalShell::ORG_PARAM),
        # so the forms only exist on that URL.
        sep = "&" if "?" in management_url else "?"
        url = f"{management_url}{sep}cpms-org={organization_id}"
        check("lifecycle: the management portal renders for the Organization manager", bool(management_url),
              management_url or "no portal url")
        page.goto(url, wait_until="domcontentloaded")
        body = page.content()
        check("lifecycle: the Organization overview rendered for the manager",
              "cpms-mgmt-shell" in body and MANAGEMENT_DENIED not in body, page.url)
        check("lifecycle: the Organization manager is not denied the portal",
              MANAGEMENT_DENIED not in body and "دسترسی ندارید" not in body, url)

        status_form = page.locator(
            'form.cpms-mgmt-form'
            f':has(input[name="cpms_action"][value="org_clinic_status"])'
            f':has(input[name="clinic_id"][value="{clinic_id}"])'
        )
        check("lifecycle: the portal renders the demo Clinic's lifecycle form",
              status_form.count() == 1, f"forms={status_form.count()}")
        deactivate = status_form.locator('input[name="active"]')
        check("lifecycle: the lifecycle form offers deactivation of an active Clinic",
              deactivate.count() == 1 and deactivate.get_attribute("value") == "0",
              f"active={deactivate.get_attribute('value') if deactivate.count() else None}")
        status_form.locator("button[type=submit]").click()
        page.wait_for_load_state("domcontentloaded")
        after = page.content()
        check("lifecycle: the production controller deactivated the demo Clinic",
              "درمانگاه غیرفعال شد" in after, page.url)
        page.goto(url, wait_until="domcontentloaded")
        reactivate_form = page.locator(
            'form.cpms-mgmt-form'
            f':has(input[name="cpms_action"][value="org_clinic_status"])'
            f':has(input[name="clinic_id"][value="{clinic_id}"])'
        )
        check("lifecycle: the portal now offers reactivation",
              reactivate_form.count() == 1
              and reactivate_form.locator('input[name="active"]').get_attribute("value") == "1",
              f"forms={reactivate_form.count()}")
        reactivate_form.locator("button[type=submit]").click()
        page.wait_for_load_state("domcontentloaded")
        check("lifecycle: the production controller reactivated the demo Clinic",
              "درمانگاه فعال شد" in page.content(), page.url)
        evidence["status_round_trip"] = True
        page.goto(url, wait_until="domcontentloaded")

        # ---------------- the portal may NOT create a second Clinic
        slug = "cpsd-forbidden-" + uuid.uuid4().hex[:10]
        create_form = page.locator(
            'form.cpms-mgmt-form:has(input[name="cpms_action"][value="org_create_clinic"])'
        )
        check("lifecycle: scoped mode does not render the create-Clinic form for the manager",
              create_form.count() == 0, f"forms={create_form.count()}")

        # A crafted POST (bypassing the UI entirely) must be refused by the
        # server-side guard, never dispatched to the production controller.
        response = page.context.request.post(
            url,
            form={
                "cpms_action": "org_create_clinic",
                "organization_id": str(organization_id),
                "cpms_entity_name": "درمانگاه غیرمجاز چرخهٔ عمر",
                "slug": slug,
                "timezone": "Asia/Tehran",
                "location_name": "شعبه غیرمجاز",
            },
        )
        refused_body = response.text()
        check("lifecycle: a crafted create-Clinic POST is refused with an explicit status",
              response.status >= 400, f"HTTP {response.status}")
        check("lifecycle: the refusal explains the scoped-demo ownership policy in Persian",
              "دموی محدودشده" in refused_body and "مسدود" in refused_body,
              refused_body[:300])

        # Nothing was created, and the portal still lists only the demo Clinic.
        page.goto(url, wait_until="domcontentloaded")
        check("lifecycle: the refused attempt created no Clinic under the demo Organization",
              "درمانگاه غیرمجاز چرخهٔ عمر" not in page.content(), slug)
        check("lifecycle: the manager still sees their own Clinic surface",
              "cpms-mgmt-shell" in page.content(), page.url)
        evidence["portal_clinic_create_refused"] = True
        page.screenshot(path=str(EVIDENCE / "journey-org-manager-lifecycle.png"))
    finally:
        ctx.close()
    return evidence


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
    admin.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
    submit_form(admin, "otp")
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

    # The demo patient row carries a synthetic cross-Clinic IDENTITY, and the
    # delivered clinical reads require the WordPress user to be linked to that
    # IDENTITY, not merely to the patient row (clinical_access_identity_
    # predicate). OTP writes cpms_patient_user_links only; it does not create an
    # identity link. Both reads must therefore stay fail-closed even for the
    # now-bound session, and asserting exactly that is the honest form: the
    # binding itself is proven by the production verify response above (and by
    # the booking below), not by pretending the portal now exposes the record.
    me = api(page, nonce, "GET", "/patient/me", rest_root=root)
    check("the bound patient's clinical read stays fail-closed without an identity link",
          me["status"] == 404 and code_of(me) == "CLINIC_NOT_FOUND",
          f"HTTP {me['status']} {code_of(me)}")
    records = api(page, nonce, "GET", "/patient/my-records", rest_root=root)
    listed = list_of(records)
    check("the bound patient's record list stays empty for the same reason",
          records["status"] == 200 and listed == [],
          f"HTTP {records['status']} {json.dumps(listed, ensure_ascii=False)[:200]}")

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
    # No first_name/last_name is sent, and the delivered new-patient path
    # REQUIRES both (requireNewPatientNames -> 400). A 200 here therefore proves
    # the confirm resolved the OTP-bound existing patient instead of creating a
    # fresh one from this session's mobile.
    check("the captured booking is committed once for the OTP-bound patient "
          "(no names sent, so the new-patient path was not taken)",
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
    admin.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
    # The whole body is used as the failure detail: when the page does not render
    # its own container, the reason (a PHP fatal, a WP error screen, a redirect)
    # is only visible in the text, and this lane's logs are not readable
    # afterwards.
    body_text = ""
    try:
        body_text = admin.locator("body").inner_text()[:600]
    except Exception:  # noqa: BLE001 - the detail must never mask the assertion.
        body_text = ""
    check("the scoped admin page still renders while the manifest is tampered",
          admin.locator('[data-cpsd-page="1"]').count() == 1,
          f"url={admin.url} body={body_text}")
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
    # The envelope is {"success":..,"data":{..}}; payload_of() unwraps it.
    board_body = payload_of(board_after)
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

    # ---------------- Iran V1 (schema 0030): private staff messaging + visit pricing
    iran_v1_phase(browser, creds, targets)
    rtl_mobile_phase(browser, creds, targets)

    # ---------------- Organization manager: portal lifecycle + a second Clinic
    # Driven BEFORE the teardown below, so the teardown proves the reported
    # defect is gone: a lifecycle stamp naming a synthetic account must not make
    # the preserved demo Clinic look like a foreign dependency.
    JOURNEY_EVIDENCE["org_manager_lifecycle"] = lifecycle_phase(
        browser, creds, targets, management_url
    )

    # ---------------- admin: registered additional Clinic, diagnostic, teardown
    ctx, admin = admin_login(browser)
    admin.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
    # The ONLY way to obtain a second demo Clinic: the demo page's own form, which
    # seals the Clinic's identity into the append-only ownership ledger BEFORE the
    # row exists. The portal itself refuses the same action (see lifecycle_phase).
    policy_card = admin.locator('[data-cpsd-ownership="1"]')
    check("the scoped demo page explains the portal restriction in Persian",
          policy_card.count() == 1 and "مسدود" in policy_card.inner_text(),
          policy_card.inner_text()[:300] if policy_card.count() else "no policy card")
    submit_form(admin, "add_clinic")
    check("the demo created and registered an additional Clinic",
          "درمانگاه نمایشی دوم ساخته و مالکیت آن در دفتر مهرشدهٔ دمو ثبت شد" in admin.content(),
          admin.url)
    admin.screenshot(path=str(EVIDENCE / "scoped-admin-ownership.png"))
    admin.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
    submit_form(admin, "diagnose")
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


def iran_v1_phase(browser, creds: dict, targets: dict) -> None:
    """`cpsd_staff_messaging_privacy` and `cpsd_visit_pricing_invoice`.

    Both journeys are driven through the delivered staff-portal modules of the
    SCOPED fixture (a seeded, non-empty host site), because the properties they
    prove are properties of the shipped UI:

    * privacy is only shown by which rows a real participant is rendered — the
      second doctor and the second receptionist are active members of the same
      Clinic, so "not in the thread" must mean "sees nothing", not "is refused";
    * the fee-snapshot rule is only shown by the pricing forms, which must keep
      the check-in snapshot and never reprice an established Visit when the
      doctor's default fee changes.
    """
    iran = targets.get("iran_v1") or {}
    modules = ((targets.get("portals") or {}).get("modules") or {})
    clinic_id = int(targets.get("clinic_id") or 0)
    private_thread = int(iran.get("private_thread") or 0)
    # persona-targets.php exports the manifest's keyed thread map, so JSON gives
    # an OBJECT here. Read it by the fixture's documented key order instead of
    # iterating, which would yield the keys themselves.
    raw_threads = iran.get("threads") or {}
    if isinstance(raw_threads, dict):
        threads = [
            int(raw_threads[key])
            for key in ("doctor_receptionist", "doctor_finance", "receptionist_finance")
            if key in raw_threads
        ]
    else:
        threads = [int(value) for value in raw_threads]
    pricing_visit = int(iran.get("pricing_visit_id") or 0)
    pricing_location = int(iran.get("pricing_visit_location_id") or 0)

    check("the scoped fixture seals the three private staff threads",
          private_thread > 0 and len(threads) == 3, json.dumps(threads))
    check("the scoped fixture seals the pricing visit and its Location",
          pricing_visit > 0 and pricing_location > 0,
          f"visit={pricing_visit} location={pricing_location}")

    # ================= private staff messaging =================
    ctx, page = persona_login(browser, creds["doctor"]["username"], creds["doctor"]["password"])
    page.goto(str(modules.get("messages") or ""), wait_until="domcontentloaded")
    check("doctor opens the staff messaging module",
          page.locator('[data-role="messages-root"]').count() == 1, f"url={page.url}")
    threads_node = page.locator('[data-role="messages-threads"]')
    threads_node.wait_for(state="visible", timeout=45000)
    thread_rows = threads_node.locator("li button")
    thread_rows.first.wait_for(state="visible", timeout=45000)
    # Three threads exist; the doctor participates in exactly two of them.
    check("the doctor sees exactly their two private threads",
          thread_rows.count() == 2, f"rendered={thread_rows.count()} sealed={len(threads)}")
    check("the doctor's unread badge is rendered",
          page.locator('[data-role="messages-unread-total"]').is_visible())

    thread_rows.first.click()
    bubbles = page.locator('[data-role="messages-list"] .cpms-msg__bubble')
    bubbles.first.wait_for(state="visible", timeout=45000)
    seeded = bubbles.count()
    check("opening a conversation renders its seeded messages", seeded >= 2, str(seeded))
    page.fill('[data-role="messages-body"]', "پیام آزمایشی دموی محدود — داده فرضی")
    page.locator('[data-role="messages-form"]').evaluate("form => form.requestSubmit()")
    page.wait_for_function(
        "n => document.querySelectorAll('[data-role=\"messages-list\"] .cpms-msg__bubble').length > n",
        arg=seeded,
        timeout=45000,
    )
    check("the doctor sends a message through the real form", True, "bubble appended")

    cfg = surface_config(page, "doctor messaging")
    msg_root = rest_root_from(cfg)
    # A thread that does not exist must be refused with the same non-revealing
    # 404 the service returns, never a distinguishable answer.
    guessed = api(page, str(cfg.get("nonce", "")), "GET",
                  f"/staff/portal/messages/threads/{max(threads) + 9000}/messages",
                  headers=scope_headers(clinic_id), rest_root=msg_root)
    check("a guessed thread id is refused with the non-revealing 404",
          guessed["status"] == 404 and code_of(guessed) == "CLINIC_NOT_FOUND",
          f"HTTP {guessed['status']} {code_of(guessed)}")
    # The fixture seals its threads in a documented order:
    #   0: doctor <-> receptionist   1: doctor <-> finance   2: receptionist <-> finance
    # so the doctor owns exactly 0 and 1, and 2 is a real thread they are not in.
    for owned in threads[:2]:
        readable = api(page, str(cfg.get("nonce", "")), "GET",
                       f"/staff/portal/messages/threads/{owned}/messages",
                       headers=scope_headers(clinic_id), rest_root=msg_root)
        check(f"the doctor reads their own thread {owned}",
              readable["status"] == 200, f"HTTP {readable['status']} {code_of(readable)}")
    outsider = api(page, str(cfg.get("nonce", "")), "GET",
                   f"/staff/portal/messages/threads/{threads[2]}/messages",
                   headers=scope_headers(clinic_id), rest_root=msg_root)
    check("the receptionist <-> finance thread is refused like a missing one",
          outsider["status"] == 404 and code_of(outsider) == "CLINIC_NOT_FOUND",
          f"HTTP {outsider['status']} {code_of(outsider)} thread={threads[2]}")
    ctx.close()

    for intruder in ("receptionist_second", "doctor_second"):
        ctx, page = persona_login(browser, creds[intruder]["username"], creds[intruder]["password"])
        page.goto(str(modules.get("messages") or ""), wait_until="domcontentloaded")
        intruder_threads = page.locator('[data-role="messages-threads"]')
        intruder_threads.wait_for(state="visible", timeout=45000)
        page.wait_for_function(
            "() => (document.querySelector('[data-role=\"messages-threads\"]') || {}).textContent"
            ".indexOf('هنوز گفتگویی وجود ندارد') !== -1",
            timeout=45000,
        )
        check(f"{intruder} (a real member of the Clinic) sees no private conversation",
              intruder_threads.locator("li button").count() == 0
              and not page.locator('[data-role="messages-unread-total"]').is_visible(),
              f"threads={intruder_threads.locator('li button').count()}")
        ctx.close()

    # ================= visit pricing -> invoice -> collection =================
    ctx, page = persona_login(browser, creds["doctor_second"]["username"], creds["doctor_second"]["password"])
    page.goto(str(modules.get("pricing") or ""), wait_until="domcontentloaded")
    check("the second doctor opens the pricing module",
          page.locator('[data-role="pricing-root"]').count() == 1, f"url={page.url}")
    page.locator('[data-role="pricing-context-label"]').wait_for(state="visible", timeout=45000)
    page.wait_for_function(
        "() => !document.querySelector('[data-role=\"pricing-context-label\"]').textContent"
        ".includes('در حال دریافت')",
        timeout=45000,
    )
    visit_button = page.locator(
        '[data-role="pricing-visits"] li button',
        has_text=re.compile(rf"ویزیت {pricing_visit}\s*$"),
    )
    visit_button.wait_for(state="visible", timeout=45000)
    visit_button.click()
    page.locator('[data-role="pricing-billing"]').wait_for(state="visible", timeout=45000)
    page.wait_for_function(
        "() => document.querySelector('[data-role=\"pricing-visit-title\"]')"
        ".textContent.includes('صورتحساب')",
        timeout=45000,
    )
    stats = page.locator('[data-role="pricing-stats"]').inner_text() or ""
    # The doctor's CURRENT default is the LATER value; the Visit must still show
    # the fee captured at check-in, with the no-reprice policy stated.
    check("the projection shows the check-in snapshot fee and the no-reprice policy",
          "تعرفه ثبتشده هنگام پذیرش" in stats
          and "500000 ریال" in stats
          and "750000 ریال" in stats
          and "سیاست تعرفه" in stats,
          stats[:400])

    page.fill('[data-role="pricing-charge-description"]', "خدمت درمانی آزمایشی دمو")
    page.fill('[data-role="pricing-charge-amount"]', "200000")
    page.fill('[data-role="pricing-charge-quantity"]', "1")
    page.locator('[data-role="pricing-charge-form"]').evaluate("form => form.requestSubmit()")
    page.wait_for_function(
        "() => document.querySelector('[data-role=\"pricing-charges\"]')"
        ".textContent.includes('خدمت درمانی آزمایشی دمو')",
        timeout=45000,
    )
    check("the doctor adds an itemized charge through the real form", True, "row rendered")

    # Editing a charge is a genuine UI action: three sequential window.prompt()s.
    answers = ["خدمت درمانی آزمایشی دمو (اصلاح‌شده)", "200000", "2"]

    def answer_prompt(dialog) -> None:
        if dialog.type == "prompt" and answers:
            dialog.accept(answers.pop(0))
        else:
            dialog.accept()

    page.on("dialog", answer_prompt)
    page.locator('[data-role="pricing-charges"] tr').first.locator("button", has_text="اصلاح").click()
    page.wait_for_function(
        "() => document.querySelector('[data-role=\"pricing-charges\"]')"
        ".textContent.includes('اصلاح‌شده')",
        timeout=45000,
    )
    page.wait_for_function(
        "() => { const rows = document.querySelectorAll('[data-role=\"pricing-charges\"] tr');"
        " if (rows.length !== 1) return false;"
        " const cells = rows[0].querySelectorAll('td');"
        " return cells.length > 2 && cells[2].textContent.trim() === '2'; }",
        timeout=45000,
    )
    check("the doctor edits the charge quantity through the real UI", True, "quantity cell is 2")
    page.remove_listener("dialog", answer_prompt)

    page.fill('[data-role="pricing-override-amount"]', "620000")
    page.locator('[data-role="pricing-override-form"]').evaluate("form => form.requestSubmit()")
    page.wait_for_function(
        "() => document.querySelector('[data-role=\"pricing-stats\"]')"
        ".textContent.includes('تعرفه اختصاصی پزشک')",
        timeout=45000,
    )
    check("the doctor applies a per-visit fee override through the real form", True, "visit_override")
    page.screenshot(path=str(EVIDENCE / "journey-pricing-doctor.png"))
    ctx.close()

    ctx, page = persona_login(browser, creds["receptionist"]["username"], creds["receptionist"]["password"])
    page.goto(str(modules.get("finance") or ""), wait_until="domcontentloaded")
    check("the receptionist opens the finance module",
          page.locator("#cpms-finance-board-config").count() == 1, f"url={page.url}")
    location_select = page.locator('[data-role="finance-location"]')
    location_select.wait_for(state="visible", timeout=45000)
    page.locator(f'[data-role="finance-location"] option[value="{pricing_location}"]').wait_for(
        state="attached", timeout=45000
    )
    if location_select.input_value() != str(pricing_location):
        location_select.select_option(str(pricing_location))
    billing_open = page.locator(
        f'[data-role="finance-billing-open"][data-visit-id="{pricing_visit}"]'
    )
    billing_open.wait_for(state="visible", timeout=60000)
    billing_open.click()
    page.locator('[data-role="finance-billing"]').wait_for(state="visible", timeout=45000)
    page.wait_for_function(
        "() => document.querySelector('[data-role=\"finance-billing-charges\"]')"
        ".textContent.includes('اصلاح‌شده')",
        timeout=45000,
    )
    breakdown = page.locator('[data-role="finance-billing"]').inner_text() or ""
    check("the receptionist sees the doctor's override and the itemized charge",
          "تعرفه اختصاصی ویزیت" in breakdown and "اصلاح‌شده" in breakdown,
          breakdown[:220])

    issue = page.locator('[data-role="finance-billing-auto"]')
    check("the receptionist may issue the invoice from the doctor pricing", issue.is_visible())
    issue.click()
    page.wait_for_function(
        "() => document.querySelector('[data-role=\"finance-billing-items\"]')"
        ".textContent.includes('ویزیت و مشاوره')",
        timeout=60000,
    )
    lines = page.locator('[data-role="finance-billing-items"]').inner_text() or ""
    check("the finance invoice carries the consultation and the treatment line",
          "ویزیت و مشاوره" in lines and "اصلاح‌شده" in lines, lines[:220])

    cfg = surface_config(page, "receptionist finance")
    fin_root = rest_root_from(cfg)
    fin_nonce = str(cfg.get("nonce", ""))
    fin_headers = scope_headers(clinic_id, pricing_location)

    # The Visit's ACTIVE invoice is read back through the same production route
    # the finance UI uses; the awaiting-payment board is a Location-local,
    # today-only projection, so it is not a safe identity source here.
    invoice_id = 0
    invoice_state: dict = {}
    for _ in range(30):
        answered = api(page, fin_nonce, "GET", f"/visits/{pricing_visit}/invoice",
                       headers=fin_headers, rest_root=fin_root)
        if answered["status"] == 200:
            body = payload_of(answered)
            invoice_id = int(body.get("id") or 0)
            invoice_state = {
                "status": str(body.get("status") or ""),
                "total": int(round(float(body.get("total") or 0))),
                "paid": int(round(float(body.get("paid_amount") or 0))),
                "balance": int(round(float(body.get("balance") or 0))),
            }
            if invoice_id:
                break
        page.wait_for_timeout(500)
    check("the browser-issued pricing invoice is recorded against the Visit",
          invoice_id > 0 and invoice_state.get("total") == 1020000,
          f"id={invoice_id} state={json.dumps(invoice_state)}")

    pay_open = page.locator(f'[data-role="finance-pay-open"][data-invoice-id="{invoice_id}"]')
    pay_open.wait_for(state="visible", timeout=60000)
    pay_open.click()
    page.fill('[data-role="finance-pay-amount"]', "300000")
    page.select_option('[data-role="finance-pay-method"]', "cash")
    page.fill('[data-role="finance-pay-ref"]', "SYNTHETIC-SCOPED-PARTIAL")
    page.locator('[data-role="finance-pay-form"]').evaluate("form => form.requestSubmit()")

    partial: dict = {}

    def read_invoice() -> dict:
        answered = api(page, fin_nonce, "GET", f"/visits/{pricing_visit}/invoice",
                       headers=fin_headers, rest_root=fin_root)
        body = payload_of(answered)
        return {
            "status_label": str(body.get("status") or ""),
            "id": int(body.get("id") or 0),
            "total": int(round(float(body.get("total") or 0))),
            "paid": int(round(float(body.get("paid_amount") or 0))),
            "balance": int(round(float(body.get("balance") or 0))),
        }

    for _ in range(40):
        partial = read_invoice()
        if partial.get("paid") == 300000:
            break
        page.wait_for_timeout(500)
    check("the partial payment is persisted against the pricing invoice",
          partial.get("paid") == 300000 and partial.get("balance") == 720000,
          json.dumps(partial))

    pay_open.click()
    page.fill('[data-role="finance-pay-amount"]', "720000")
    page.select_option('[data-role="finance-pay-method"]', "card_pos")
    page.fill('[data-role="finance-pay-ref"]', "SYNTHETIC-SCOPED-FINAL")
    page.locator('[data-role="finance-pay-form"]').evaluate("form => form.requestSubmit()")

    settled: dict = {}
    for _ in range(40):
        settled = read_invoice()
        if settled.get("paid") == 1020000:
            break
        page.wait_for_timeout(500)
    check("the pricing invoice settles to a zero balance",
          settled.get("paid") == 1020000 and settled.get("balance") == 0,
          json.dumps(settled))
    page.screenshot(path=str(EVIDENCE / "journey-pricing-finance.png"))
    ctx.close()

    # The finalized Visit is read-only in the doctor module: the charge and
    # override actions must be disabled, never silently accepted.
    ctx, page = persona_login(browser, creds["doctor_second"]["username"], creds["doctor_second"]["password"])
    page.goto(str(modules.get("pricing") or ""), wait_until="domcontentloaded")
    page.locator('[data-role="pricing-root"]').wait_for(state="visible", timeout=45000)
    page.locator('[data-role="pricing-context-label"]').wait_for(state="visible", timeout=45000)
    page.wait_for_function(
        "() => !document.querySelector('[data-role=\"pricing-context-label\"]').textContent"
        ".includes('در حال دریافت')",
        timeout=45000,
    )
    frozen = page.locator(
        '[data-role="pricing-visits"] li button',
        has_text=re.compile(rf"ویزیت {pricing_visit}\s*$"),
    )
    frozen.wait_for(state="visible", timeout=45000)
    frozen.click()
    page.locator('[data-role="pricing-billing"]').wait_for(state="visible", timeout=45000)
    page.wait_for_function(
        "() => document.querySelector('[data-role=\"pricing-charge-add\"]')"
        ".disabled === true",
        timeout=45000,
    )
    check("a settled Visit is frozen for the doctor (no new charge, no override)",
          page.locator('[data-role="pricing-charge-add"]').is_disabled()
          and page.locator('[data-role="pricing-override-save"]').is_disabled(),
          "charge-add and override-save disabled")
    ctx.close()


def rtl_mobile_phase(browser, creds: dict, targets: dict) -> None:
    """320px and 390px, right-to-left, no horizontal overflow.

    Runs against the two schema-0030 modules the demo is judged on, in a real
    browser at the two widths the templates declare breakpoints for.
    """
    modules = ((targets.get("portals") or {}).get("modules") or {})
    evidence: dict = {}
    for width in (320, 390):
        for persona, module_key, selector, label in (
            ("doctor", "messages", '[data-role="messages-root"]', "staff messaging"),
            ("doctor_second", "pricing", '[data-role="pricing-root"]', "visit pricing"),
        ):
            url = str(modules.get(module_key) or "")
            check(f"the fixture exposes the {label} module URL for the {width}px check", bool(url), url)
            ctx, page = persona_login(
                browser, creds[persona]["username"], creds[persona]["password"],
                width=width, height=800,
            )
            page.goto(url, wait_until="domcontentloaded")
            check(f"{label} root renders at {width}px",
                  page.locator(selector).count() == 1, f"url={page.url}")
            page.locator(selector).wait_for(state="visible", timeout=45000)
            metrics = rtl_mobile_check(page, selector, label, width)
            page.screenshot(path=str(EVIDENCE / f"rtl-{module_key}-{width}.png"))
            evidence[f"{module_key}@{width}"] = {
                "dir": metrics["dir"],
                "viewport": metrics["inner"],
                "doc_scroll": metrics["docScroll"],
            }
            ctx.close()
    (EVIDENCE / "rtl-mobile.json").write_text(
        json.dumps({"ok": True, "widths": [320, 390], "evidence": evidence}, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )


def write_result(ok: bool, extra: dict | None = None) -> None:
    result = {
        "ok": bool(ok) and all(bool(c["ok"]) for c in CHECKS),
        "checks": CHECKS,
        "base": BASE,
        "limitations": [
            "Journeys call the plugin REST API from the persona's own portal surface, not every UI form",
            "clinic_manager/organization_manager assert the management portal surface, not REST journeys",
            "staff messaging and visit pricing ARE driven through the delivered modules (thread list, thread view, send, charge add/edit dialog, override, invoice, partial and final payment); only the pricing visit-list pagination and the finance receipt print view are not exercised",
            "The patient OTP journey posts the widget's own /otp/request payload from inside the real booking page; the calendar/slot click path and the patient portal's OTP form (which multi-Clinic installs refuse with CLINIC_SCOPE_REQUIRED) are not driven",
            "Password-only patient login stays unbound by design: cpms_patient_user_links is written only by the mobile-OTP enrollment flow, which is exercised through the production /otp/verify route",
            "Screenshot pixels not inspected",
        ],
    }
    if extra:
        result.update(extra)
    if JOURNEY_EVIDENCE:
        result["journey_evidence"] = JOURNEY_EVIDENCE
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
                if phase in ("all", "repair"):
                    repair_phase(browser)
                if phase in ("all", "sessions"):
                    sessions_phase(browser)
            finally:
                browser.close()
    except Exception as exc:
        ok = False
        result = {"ok": False, "phase": phase, "checks": CHECKS, "error": f"{type(exc).__name__}: {exc}"}
        (EVIDENCE / f"browser-result-{phase}.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
        annotate("SCOPED_BROWSER", f"{phase}: {result['error']}")
        print("CPSD_BROWSER_RESULT=" + json.dumps({"ok": False, "phase": phase, "error": result["error"]}, ensure_ascii=False),
              flush=True)
        return 1
    write_result(ok, {"phase": phase})
    return 0


if __name__ == "__main__":
    sys.exit(main())
