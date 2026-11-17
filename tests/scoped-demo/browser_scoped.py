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
             the cookies and REST nonce are the browser's own; then the real
             possession-only OTP verification drill (challenge -> sanctioned
             code -> wrong-code refusal -> zero-access verify with no cookie or
             identity write -> consumed-code replay refusal), followed by an
             explicit patient-only link + password login booking + idempotent
             replay; then the admin diagnose + teardown forms

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
    selection) from inside the real booking page, then verifies possession-only
    behaviour through the production `/otp/verify` endpoint (zero-access
    envelope, no session cookie, no user/link/patient write, single-use replay
    refusal). Authenticated patient reads, Hold, Confirm and idempotent replay
    then run under the provisioned patient-only WP account after linking it via
    WP-CLI and signing in through `wp-login.php`. The calendar/slot *click* path
    and the patient portal's own OTP form (which multi-Clinic installs refuse
    with CLINIC_SCOPE_REQUIRED by design) are not driven.
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

import contextlib
import io
import json
import os
import re
import subprocess
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


_SAFE_ERROR_CODE_RE = re.compile(r"(?:CLINIC|SCOPED|CPMS)_[A-Z0-9_]{1,56}|rest_[a-z0-9_]{1,56}")
_SAFE_KEY_NAME_RE = re.compile(r"[a-z][a-zA-Z0-9_]{0,47}")
_SAFE_FLAG_NAME_RE = re.compile(r"[a-z][a-z0-9_]{0,63}")
_FORBIDDEN_INT_FLAG_SEGMENTS = frozenset({
    "token", "otp", "code", "nonce", "cookie", "password", "pass",
    "secret", "key", "reference", "mobile", "phone", "ticket", "session",
})
_ALLOWED_COUNT_SUFFIXES = ("_count", "_delta", "_len")
_SECRET_DETAIL_JSON_RE = re.compile(
    r'(["\'](?:hold_token|confirm_token|idempotency_key|idempotency-key|idempotency|'
    r'otp|otp_code|token_id|latest_token_id|nonce|_wpnonce|wp_nonce|x-wp-nonce|rest_nonce|'
    r'cookie|set-cookie|set_cookie|password|user_pass|secret|reference_code|'
    r'cpsd_persona_entry|patient_name|full_name|first_name|last_name|'
    r'national_code|mobile|phone|note_summary|content_text|diagnosis)["\']\s*:\s*)'
    r'(?:"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'|-?\d+)',
    re.IGNORECASE,
)
_SECRET_DETAIL_KV_RE = re.compile(
    r'(\b(?:hold_token|confirm_token|idempotency_key|idempotency-key|'
    r'otp|otp_code|token_id|latest_token_id|nonce|_wpnonce|wp_nonce|x-wp-nonce|rest_nonce|'
    r'cookie|set-cookie|set_cookie|password|user_pass|secret|reference_code|'
    r'cpsd_persona_entry|patient_name|full_name|first_name|last_name|'
    r'national_code|mobile|phone|note_summary|content_text|diagnosis)\s*[=:]\s*)'
    r'(?:"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'|[^\s,;\])\}]+)',
    re.IGNORECASE,
)
_EXPECTED_SURFACE_CONFIG_KEYS = ("nonce", "rest_root")


def safe_error_code(source: object) -> str:
    """Return a matching machine error code or `<nonstandard_code>`."""
    if isinstance(source, dict):
        data = source.get("data")
        raw = data.get("code") if isinstance(data, dict) and "code" in data else source.get("code")
    else:
        raw = source
    if raw is None or raw == "":
        return ""
    if not isinstance(raw, str):
        return "<nonstandard_code>"
    return raw if _SAFE_ERROR_CODE_RE.fullmatch(raw) else "<nonstandard_code>"


def safe_key_names(payload: object) -> list[str]:
    """Return sorted schema key names of a dict payload without any values."""
    if not isinstance(payload, dict):
        return []
    safe: list[str] = []
    has_other = False
    for key in payload.keys():
        if isinstance(key, str) and _SAFE_KEY_NAME_RE.fullmatch(key):
            safe.append(key)
        else:
            has_other = True
    names = sorted(safe)
    if has_other:
        names.append("<nonstandard_key>")
    return names


def _validate_safe_flags(flags: dict[str, object]) -> dict[str, bool | int]:
    """Validate boolean and non-identifier/non-secret integer detail flags."""
    clean: dict[str, bool | int] = {}
    for name, value in flags.items():
        if not isinstance(name, str) or not _SAFE_FLAG_NAME_RE.fullmatch(name):
            raise ValueError(f"unsafe detail flag name: {name!r}")
        if type(value) is bool:
            clean[name] = value
            continue
        if type(value) is int:
            if name == "id" or name.endswith("_id"):
                raise ValueError(f"identifier value disallowed in detail flag: {name}")
            if not name.endswith(_ALLOWED_COUNT_SUFFIXES):
                if set(name.split("_")) & _FORBIDDEN_INT_FLAG_SEGMENTS:
                    raise ValueError(f"secret-like int disallowed in detail flag: {name}")
            clean[name] = value
            continue
        raise ValueError(f"unsafe detail flag type for {name}: {type(value).__name__}")
    return clean


def safe_counts_detail(**flags: bool | int) -> str:
    """Format validated boolean/count check details with no IDs, tokens, or bodies."""
    return json.dumps(_validate_safe_flags(flags))


def safe_response_detail(result: object, **flags: bool | int) -> str:
    """Format safe API response detail: HTTP status, error code, key names, bools, counts."""
    clean_flags = _validate_safe_flags(flags)
    parts: list[str] = []
    if isinstance(result, dict):
        status = result.get("status")
        if type(status) is int:
            parts.append(f"HTTP {status}")
        code = safe_error_code(result)
        if code:
            parts.append(code)
        if "data" in result:
            body = result.get("data")
            if isinstance(body, dict) and isinstance(body.get("data"), dict):
                parts.append(f"keys={json.dumps(safe_key_names(body['data']))}")
            elif isinstance(body, dict) and isinstance(body.get("data"), list):
                parts.append(f"count={len(body['data'])}")
            elif isinstance(body, list):
                parts.append(f"count={len(body)}")
            elif isinstance(body, dict):
                parts.append(f"keys={json.dumps(safe_key_names(body))}")
            else:
                parts.append(f"data_type={type(body).__name__}")
        else:
            parts.append(f"keys={json.dumps(safe_key_names(result))}")
    elif isinstance(result, list):
        parts.append(f"count={len(result)}")
    for name, value in clean_flags.items():
        parts.append(f"{name}={value}")
    return " ".join(parts)


def safe_records_detail(records: object, listed: object) -> str:
    """Format HTTP status and record count without record fields or values."""
    status = records.get("status") if isinstance(records, dict) and type(records.get("status")) is int else 0
    count = len(listed) if isinstance(listed, list) else 0
    return f"HTTP {status} count={count}"


def format_missing_config_diagnostic(probe: object, *, on_login_page: bool = False) -> str:
    """Format bounded non-HTML diagnostics for a missing surface REST config."""
    data = probe if isinstance(probe, dict) else {}
    raw_status = data.get("http_status")
    status_text = str(raw_status) if type(raw_status) is int and 100 <= raw_status <= 599 else "unknown"
    raw_len = data.get("page_length")
    page_len = raw_len if type(raw_len) is int and raw_len >= 0 else 0
    raw_missing = data.get("missing_config_keys")
    if isinstance(raw_missing, list):
        missing = [k for k in _EXPECTED_SURFACE_CONFIG_KEYS if k in raw_missing]
    else:
        missing = list(_EXPECTED_SURFACE_CONFIG_KEYS)
    return "\n".join((
        f"http_status={status_text}",
        f"page_length={page_len}",
        f"config_node_found={bool(data.get('config_node_found'))}",
        f"config_json_valid={bool(data.get('config_json_valid'))}",
        f"window_config_found={bool(data.get('window_config_found'))}",
        f"on_login_page={bool(on_login_page)}",
        f"missing_config_keys={','.join(missing)}",
    )) + "\n"


def format_check_detail(detail: object) -> str:
    """Prevent check() from serializing dict/list values or secret key/value pairs."""
    if detail is None or detail == "":
        return ""
    if isinstance(detail, (dict, list)):
        return safe_response_detail(detail)
    text = str(detail)
    text = _SECRET_DETAIL_JSON_RE.sub(r'\1"<redacted>"', text)
    text = _SECRET_DETAIL_KV_RE.sub(r"\1<redacted>", text)
    return text


def check(name: str, ok: bool, detail: object = "") -> None:
    safe_detail = format_check_detail(detail)
    CHECKS.append({"name": name, "ok": bool(ok), "detail": safe_detail})
    print(("PASS " if ok else "FAIL ") + name + ((" — " + safe_detail) if safe_detail else ""), flush=True)
    if not ok:
        annotate("SCOPED_BROWSER", name + ((" — " + safe_detail) if safe_detail else ""))
        raise AssertionError(name + ": " + safe_detail)


def selftest_safe_detail() -> dict[str, int]:
    """Pure-logic self-check using synthetic placeholders; leaves CHECKS and stdout untouched."""
    sentinels = {
        "hold_token": "SYNTH_HOLD_9f8e7d6c5b4a",
        "confirm_token": "SYNTH_CONFIRM_1a2b3c4d5e",
        "idempotency_key": "SYNTH_IDEM_778899aabbcc",
        "otp_code": "839204",
        "token_id": 94827163,
        "latest_token_id": 94827164,
        "nonce": "SYNTH_NONCE_ccddeeff00",
        "cookie": "wordpress_logged_in_synth=SYNTH_COOKIE_val99",
        "password": "SYNTH_PASS_zzxxyy1122",
        "reference_code": "SYNTH_REF_445566",
        "patient_name": "SYNTH_NAME_SARA_EXAMPLE",
        "first_name": "SYNTH_FIRST_SARA",
        "last_name": "SYNTH_LAST_EXAMPLE",
        "national_code": "0099887766",
        "mobile": "09001112233",
        "content_text": "SYNTH_RECORD_NOTE_01",
        "visit_reason": "SYNTH_VISIT_REASON_FOLLOWUP",
    }
    sentinel_strings = tuple(str(v) for v in sentinels.values())
    record_named_row = {
        "id": 401,
        "patient_name": sentinels["patient_name"],
        "first_name": sentinels["first_name"],
        "last_name": sentinels["last_name"],
        "national_code": sentinels["national_code"],
        "mobile": sentinels["mobile"],
        "content_text": sentinels["content_text"],
    }
    fake_records = [
        record_named_row,
        {
            "id": 402,
            "patient_name": sentinels["patient_name"],
            "visit_reason": sentinels["visit_reason"],
        },
    ]
    records_list_resp = {"status": 200, "data": {"data": fake_records}}
    records_dict_resp = {"status": 200, "data": {"records": fake_records}}
    hold_envelope = {
        "status": 200,
        "data": {"data": {
            "hold_token": sentinels["hold_token"],
            "expires_at": "2026-11-16T12:00:00Z",
            "slot": {"id": 5},
            **sentinels,
        }},
    }
    bad_code_envelope = {
        "status": 409,
        "data": {
            "code": sentinels["hold_token"],
            "message": sentinels["password"],
            sentinels["nonce"]: sentinels["cookie"],
            "hold_token": sentinels["hold_token"],
        },
    }
    keyed_sentinels = {k: v for k, v in sentinels.items() if k != "visit_reason"}
    cases = [
        ("hold_pass", True, safe_response_detail(hold_envelope, has_hold_token=True)),
        ("hold_fail", False, safe_response_detail(bad_code_envelope, has_hold_token=False)),
        ("records_empty_pass", True, safe_records_detail({"status": 200, "data": []}, [])),
        ("records_isolation_fail", False, safe_records_detail(records_list_resp, fake_records)),
        ("records_dict_fail", False, records_dict_resp),
        ("records_json_fail", False, f"HTTP 200 records={json.dumps([record_named_row])}"),
        ("raw_dict_pass", True, dict(sentinels)),
        ("raw_dict_fail", False, hold_envelope),
        ("list_pass", True, [dict(sentinels)]),
        ("json_str_pass", True, json.dumps(keyed_sentinels)),
        ("json_str_fail", False, json.dumps({"nested": keyed_sentinels})),
        ("kv_str_pass", True, " ".join(f"{k}={v}" for k, v in keyed_sentinels.items())),
        ("counts_pass", True, safe_counts_detail(
            same_token=True, consumed=True, unconsumed=0, tokens_delta=1, verify_ok_delta=1,
        )),
    ]
    saved = list(CHECKS)
    buf = io.StringIO()
    try:
        CHECKS.clear()
        with contextlib.redirect_stdout(buf):
            for label, ok, detail in cases:
                if ok:
                    check(label, True, detail)
                else:
                    try:
                        check(label, False, detail)
                    except AssertionError as exc:
                        err_text = str(exc)
                        for secret in sentinel_strings:
                            if secret in err_text:
                                raise RuntimeError(f"safe detail self-test failed on {label} exception") from None
                    else:
                        raise RuntimeError(f"safe detail self-test failed to raise on {label}")
        captured_stdout = buf.getvalue()
        captured_checks = json.dumps(CHECKS, ensure_ascii=False)
        for secret in sentinel_strings:
            if secret in captured_stdout or secret in captured_checks:
                raise RuntimeError("safe detail self-test detected sentinel in check sink")
        if 'HTTP 200' not in CHECKS[0]["detail"] or '"hold_token"' not in CHECKS[0]["detail"]:
            raise RuntimeError("safe detail self-test lost status or key-name metadata")
        if CHECKS[2]["detail"] != "HTTP 200 count=0" or CHECKS[3]["detail"] != "HTTP 200 count=2":
            raise RuntimeError("safe detail self-test broke status/count records contract")
    finally:
        CHECKS[:] = saved

    synth_html = f"<html><body>{sentinels['patient_name']}<script>{sentinels['nonce']}</script></body></html>"
    diag_text = format_missing_config_diagnostic(
        {
            "http_status": 200,
            "page_length": len(synth_html),
            "config_node_found": True,
            "config_json_valid": False,
            "window_config_found": False,
            "missing_config_keys": ["nonce", "rest_root", sentinels["nonce"]],
            "nonce": sentinels["nonce"],
            "html": synth_html,
        },
        on_login_page=False,
    )
    if "<" in diag_text or "missing_config_keys=nonce,rest_root" not in diag_text or "http_status=200" not in diag_text:
        raise RuntimeError("safe detail self-test broke missing-config diagnostic contract")
    for secret in sentinel_strings:
        if secret in diag_text:
            raise RuntimeError("safe detail self-test detected sentinel in missing-config diagnostic")

    rejected = 0
    bad_flags: tuple[dict[str, object], ...] = (
        {"hold_token": sentinels["hold_token"]},
        {"confirm_token": sentinels["confirm_token"]},
        {"idempotency_key": sentinels["idempotency_key"]},
        {"otp_code": sentinels["otp_code"]},
        {"otp": int(sentinels["otp_code"])},
        {"code": int(sentinels["otp_code"])},
        {"token_id": sentinels["token_id"]},
        {"latest_token_id": sentinels["latest_token_id"]},
        {"link_id": 77},
        {"appointment_id": 88},
        {"id": 99},
        {"nonce": sentinels["nonce"]},
        {"cookie": sentinels["cookie"]},
        {"password": sentinels["password"]},
        {"reference_code": sentinels["reference_code"]},
        {"body": dict(sentinels)},
        {"items": [sentinels["hold_token"]]},
    )
    for bad in bad_flags:
        try:
            safe_response_detail(hold_envelope, **bad)  # type: ignore[arg-type]
        except ValueError:
            rejected += 1
        else:
            raise RuntimeError(f"safe detail self-test accepted unsafe flag {sorted(bad)}")
    return {"cases": len(cases), "rejected_flags": rejected, "sentinels": len(sentinel_strings), "leaks": 0}


# --- Shared in-page dialog (assets/js/cpms-dialog.js) — DUAL-MODE answers ----
# The next product round converts the remaining native window.confirm()/prompt()
# call sites to this in-page dialog. These helpers let the journey answer BOTH
# forms without weakening it: the existing page.on("dialog") handler keeps
# answering the native form, and answer_shared_dialogs() answers the in-page one.
# A step that needs a dialog still FAILS when neither form appears.
DIALOG_OVERLAY = ".cpms-modal-overlay"
DIALOG_ROOT = ".cpms-modal[role='dialog']"
DIALOG_CONFIRM = ".cpms-modal-confirm"
DIALOG_CANCEL = ".cpms-modal-cancel, .cpms-dialog__btn:not(.cpms-dialog__btn--primary):not(.cpms-modal-confirm)"
DIALOG_INPUT = ".cpms-dialog__input"
DIALOG_POLL_MS = 100


def shared_dialog_available(page) -> bool:
    """True when this page shipped the shared in-page dialog asset.

    Probe this BEFORE clicking the trigger: the answer it gives decides whether
    the in-page form or the still-shipped native form owns the confirmation.
    """
    try:
        return bool(page.evaluate("() => !!(window.CpmsDialog && window.CpmsDialog.confirm)"))
    except Exception:
        return False


def answer_shared_dialogs(page, steps, *, shared_expected, timeout_ms, native_seen=None):
    """Answer the SHARED in-page dialog in sequence — one step per dialog.

    steps: [{"action": "accept" | "cancel" | "prompt", "value": "<text>"}].
    native_seen: the caller's own record of native dialogs; when truthy the
        existing native handler already answered this step and we stay out.
    shared_expected: shared_dialog_available(page), probed before the trigger.
    timeout_ms: the caller's EXISTING timeout — never widened here.

    Returns "native" (the native handler owned the step) or "shared". Raises
    RuntimeError when the in-page dialog was expected but never appeared, so the
    step fails instead of passing on a dialog nobody answered.
    """
    if native_seen:
        return "native"
    if not shared_expected:
        return "native"

    overlay = page.locator(DIALOG_OVERLAY)
    budget = max(1, int(timeout_ms / DIALOG_POLL_MS))
    for index, step in enumerate(steps, start=1):
        for _ in range(budget):
            if overlay.count() > 0 and overlay.first.is_visible():
                break
            if native_seen:
                return "native"
            page.wait_for_timeout(DIALOG_POLL_MS)
        else:
            raise RuntimeError(
                "neither the native nor the shared in-page dialog appeared within "
                f"{timeout_ms}ms (dialog {index} of {len(steps)})"
            )
        root = overlay.locator(DIALOG_ROOT).first
        action = step.get("action", "accept")
        if action == "cancel":
            # DIALOG_CANCEL names both cancel markups: the legacy companion
            # class and the shared in-page dialog's unqualified button. Both are
            # probed inside the open dialog root; neither present is a real failure.
            for cancel_selector in DIALOG_CANCEL.split(","):
                cancel_button = root.locator(cancel_selector.strip()).first
                if cancel_button.count() > 0:
                    cancel_button.click()
                    break
            else:
                raise RuntimeError("neither cancel button form appeared in the open dialog")
        elif action == "prompt":
            root.locator(DIALOG_INPUT).first.fill(str(step.get("value", "")))
            root.locator(DIALOG_CONFIRM).first.click()
        else:
            root.locator(DIALOG_CONFIRM).first.click()
        for _ in range(budget):
            if overlay.count() == 0 or not overlay.first.is_visible():
                break
            page.wait_for_timeout(DIALOG_POLL_MS)
    return "shared"


# --- Money and pricing digits (Defect 11) — DUAL-MODE normalization ----------
# The upcoming Defect 11 production change converts staff pricing and finance
# display from raw Latin digits ("500000 ریال") to Persian digits with thousands
# separators ("۵۰۰٬۰۰۰ ریال"). These helpers normalize Persian (۰-۹) and
# Arabic-Indic (٠-٩) digits to ASCII, strip thousands separators (٬ and ,)
# between digits, and map ٫ to . before running the existing checks, so wrong
# amounts and missing "ریال" units still fail.
MONEY_DIGIT_MAP = str.maketrans(
    "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩٫",
    "01234567890123456789.",
)
MONEY_THOUSANDS_RE = re.compile(r"(?<=\d)[\u066c,](?=\d)")
MONEY_NORMALIZE_JS = (
    "(value => String(value == null ? '' : value)"
    ".replace(/[\\u06F0-\\u06F9]/g, d => String(d.charCodeAt(0) - 0x06F0))"
    ".replace(/[\\u0660-\\u0669]/g, d => String(d.charCodeAt(0) - 0x0660))"
    ".replace(/(\\d)[\\u066C,](?=\\d)/g, '$1')"
    ".replace(/\\u066B/g, '.'))"
)


def normalize_money_digits(text) -> str:
    """Normalize Persian/Arabic-Indic digits and number separators to ASCII."""
    if text is None:
        return ""
    return MONEY_THOUSANDS_RE.sub("", str(text).translate(MONEY_DIGIT_MAP))


def has_money_text(text, expected: str) -> bool:
    """True when `expected` appears in normalized `text` with exact number bounds."""
    target = normalize_money_digits(expected)
    if not target:
        raise ValueError("expected money substring must be non-empty")
    prefix = r"(?<![0-9.])" if target[0].isdigit() else ""
    suffix = r"(?![0-9.])" if target[-1].isdigit() else ""
    return bool(re.search(f"{prefix}{re.escape(target)}{suffix}", normalize_money_digits(text)))


def digit_agnostic_suffix_pattern(prefix: str, number: int):
    """Match `<prefix><number>` at end of text in ASCII or Persian/Arabic digits."""
    digits = str(int(number))
    if not digits.isdigit():
        raise ValueError("number must be a non-negative integer")
    fa = "۰۱۲۳۴۵۶۷۸۹"
    ar = "٠١٢٣٤٥٦٧٨٩"
    body = "[\u066c,]?".join(f"[{d}{fa[int(d)]}{ar[int(d)]}]" for d in digits)
    return re.compile(f"{re.escape(prefix)}{body}\\s*$")


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


MISSING_CONFIG_PROBE_JS = """
(selectors) => {
  const okNonce = (value) => typeof value === 'string' && /^[0-9a-f]{10}$/.test(value.trim());
  const okRoot = (value) => typeof value === 'string' && value.trim().length > 0;
  let status = null;
  try {
    const nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    if (nav && Number.isInteger(nav.responseStatus) && nav.responseStatus >= 100 && nav.responseStatus <= 599) {
      status = nav.responseStatus;
    }
  } catch (e) { /* navigation status unavailable */ }
  let nodeFound = false;
  let jsonValid = false;
  let hasNonce = false;
  let hasRoot = false;
  for (const selector of selectors) {
    const node = document.querySelector(selector);
    if (!node) continue;
    nodeFound = true;
    try {
      const parsed = JSON.parse(node.textContent || '');
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        jsonValid = true;
        if (okNonce(parsed.nonce)) hasNonce = true;
        if (okRoot(parsed.rest_root)) hasRoot = true;
      }
    } catch (e) { /* malformed config script */ }
  }
  let winFound = false;
  for (const name of ['CPMS_Q', 'CPMS_DOC', 'CPMS_FIN']) {
    const cfg = window[name];
    if (cfg && typeof cfg === 'object') {
      winFound = true;
      if (okNonce(cfg.nonce)) hasNonce = true;
      if (okRoot(cfg.rest_url)) hasRoot = true;
    }
  }
  if (window.wpApiSettings && typeof window.wpApiSettings === 'object') {
    winFound = true;
    if (okNonce(window.wpApiSettings.nonce)) hasNonce = true;
    if (okRoot(window.wpApiSettings.root)) hasRoot = true;
  }
  const missing = [];
  if (!hasNonce) missing.push('nonce');
  if (!hasRoot) missing.push('rest_root');
  return {
    http_status: status,
    page_length: document.documentElement ? document.documentElement.outerHTML.length : 0,
    config_node_found: nodeFound,
    config_json_valid: jsonValid,
    window_config_found: winFound,
    missing_config_keys: missing,
  };
}
"""


def surface_config(page, label: str) -> dict:
    """Read the REST config the rendered surface itself publishes."""
    config = page.evaluate(CONFIG_JS, list(CONFIG_SELECTORS)) or {}
    nonce = str(config.get("nonce", "") or "")
    if nonce:
        check(f"{label}: surface publishes its own REST nonce ({config.get('source')})", True, page.url)
        return config
    # Write a bounded diagnostic summary (no HTML, page text, or nonce values).
    probe: dict = {}
    on_login = "/wp-login.php" in str(page.url or "")
    try:
        probe = page.evaluate(MISSING_CONFIG_PROBE_JS, list(CONFIG_SELECTORS)) or {}
    except Exception:  # pragma: no cover - diagnostics fallback
        probe = {}
    try:
        diag = format_missing_config_diagnostic(probe, on_login_page=on_login)
        (EVIDENCE / f"no-config-{label.replace(' ', '-')}.html").write_text(diag, encoding="utf-8")
    except Exception:  # pragma: no cover - diagnostics fallback
        pass
    raw_len = probe.get("page_length") if isinstance(probe, dict) else 0
    page_len = raw_len if type(raw_len) is int and raw_len >= 0 else 0
    check(
        f"{label}: surface publishes its own REST nonce",
        False,
        safe_counts_detail(
            page_len=page_len,
            config_node_found=bool(probe.get("config_node_found")) if isinstance(probe, dict) else False,
            on_login_page=on_login,
        ),
    )
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


def auth_cookie_names(ctx) -> list[str]:
    """Every WordPress auth/settings cookie name across all paths (no values)."""
    names = []
    for cookie in ctx.cookies():
        name = str(cookie.get("name", ""))
        if name.startswith("wordpress_") or name.startswith("wp-settings-"):
            names.append(name)
    return sorted(names)


def response_sets_auth_cookie(response) -> bool:
    """True when any raw Set-Cookie header sets a WordPress auth/settings cookie."""
    for header in response.headers_array():
        if str(header.get("name", "")).lower() != "set-cookie":
            continue
        cookie_name = str(header.get("value", "")).split("=", 1)[0].strip()
        if cookie_name.startswith("wordpress_") or cookie_name.startswith("wp-settings-"):
            return True
    return False


def is_zero_access_otp_envelope(data: object) -> bool:
    """Exact PR #340 possession-only /otp/verify data envelope contract."""
    if not isinstance(data, dict):
        return False
    user_id = data.get("user_id")
    links = data.get("patient_links")
    return (
        set(data.keys()) == {"user_id", "patient_links", "is_new_user", "session_issued"}
        and type(user_id) is int
        and user_id == 0
        and isinstance(links, list)
        and len(links) == 0
        and data.get("is_new_user") is False
        and data.get("session_issued") is False
    )


def is_wp_auth_cookie_name(name: str) -> bool:
    """True for WordPress logged-in, secure-auth, and auth cookies (excludes test probe)."""
    return name.startswith("wordpress_") and name != "wordpress_test_cookie"


def _is_wp_settings_cookie_name(name: str) -> bool:
    return name.startswith("wp-settings-")


def canonical_wp_cookie_records(
    cookies: object,
    predicate,
) -> list[tuple[str, str, str, str, float, bool, bool, str]]:
    """Sorted cookie tuples preserving real values and multi-path entries."""
    if not isinstance(cookies, list):
        raise AssertionError("cookie snapshot must be a list")
    records: list[tuple[str, str, str, str, float, bool, bool, str]] = []
    for raw in cookies:
        if not isinstance(raw, dict):
            raise AssertionError("cookie entry must be a dict")
        name = raw.get("name")
        value = raw.get("value")
        if not isinstance(name, str) or not isinstance(value, str):
            raise AssertionError("cookie name and value must be strings")
        if not predicate(name):
            continue
        raw_expires = raw.get("expires", -1)
        if isinstance(raw_expires, bool) or not isinstance(raw_expires, (int, float)):
            raise AssertionError("cookie expires must be numeric")
        records.append((
            name,
            str(raw.get("domain", "")),
            str(raw.get("path", "")),
            value,
            float(raw_expires),
            bool(raw.get("secure", False)),
            bool(raw.get("httpOnly", False)),
            str(raw.get("sameSite", "")),
        ))
    return sorted(records)


def _cookie_slot_multiset(records: list[tuple]) -> dict[tuple[str, str, str], int]:
    counts: dict[tuple[str, str, str], int] = {}
    for rec in records:
        slot = (rec[0], rec[1], rec[2])
        counts[slot] = counts.get(slot, 0) + 1
    return counts


def assert_wp_auth_cookies_unchanged(before_cookies: object, after_cookies: object) -> dict:
    """Compare WordPress auth and settings cookie names, attributes, and values.

    Requires at least one non-empty `wordpress_logged_in_*` cookie and at least
    one non-empty `wordpress_sec_*` or `wordpress_*` auth cookie in the baseline,
    then asserts exact record equality after replay without emitting raw cookie
    values in return payloads or exception messages.
    """
    before_auth = canonical_wp_cookie_records(before_cookies, is_wp_auth_cookie_name)
    after_auth = canonical_wp_cookie_records(after_cookies, is_wp_auth_cookie_name)
    before_logged_in = [r for r in before_auth if r[0].startswith("wordpress_logged_in_")]
    before_sec = [r for r in before_auth if r[0].startswith("wordpress_sec_")]
    before_plain_auth = [
        r for r in before_auth
        if not r[0].startswith("wordpress_logged_in_") and not r[0].startswith("wordpress_sec_")
    ]
    if not before_logged_in:
        raise AssertionError("baseline missing wordpress_logged_in_* cookie")
    if not before_sec and not before_plain_auth:
        raise AssertionError("baseline missing wordpress_sec_* or wordpress_* auth cookie")
    if any(not rec[3].strip() or rec[3].strip().lower() == "deleted" for rec in before_auth):
        raise AssertionError("baseline has blank or deleted WordPress auth cookie value")

    if after_auth != before_auth:
        b_slots = _cookie_slot_multiset(before_auth)
        a_slots = _cookie_slot_multiset(after_auth)
        removed_count = sum(max(0, n - a_slots.get(s, 0)) for s, n in b_slots.items())
        added_count = sum(max(0, n - b_slots.get(s, 0)) for s, n in a_slots.items())
        b_vals: dict[tuple[str, str, str], list[str]] = {}
        a_vals: dict[tuple[str, str, str], list[str]] = {}
        for r in before_auth:
            b_vals.setdefault((r[0], r[1], r[2]), []).append(r[3])
        for r in after_auth:
            a_vals.setdefault((r[0], r[1], r[2]), []).append(r[3])
        value_changed = sum(1 for s in (set(b_vals) & set(a_vals)) if b_vals[s] != a_vals[s])
        raise AssertionError(
            f"WordPress auth cookies changed: before={len(before_auth)} after={len(after_auth)} "
            f"removed={removed_count} added={added_count} value_changed={value_changed} "
            f"attr_changed={removed_count == 0 and added_count == 0 and value_changed == 0}"
        )

    before_settings = canonical_wp_cookie_records(before_cookies, _is_wp_settings_cookie_name)
    after_settings = canonical_wp_cookie_records(after_cookies, _is_wp_settings_cookie_name)
    if after_settings != before_settings:
        raise AssertionError(
            f"WordPress settings cookies changed: before={len(before_settings)} after={len(after_settings)}"
        )

    return {
        "auth_count": len(before_auth),
        "logged_in_count": len(before_logged_in),
        "sec_count": len(before_sec),
        "auth_variant_count": len(before_plain_auth),
        "settings_count": len(before_settings),
    }


def request_carries_wp_logged_in_cookie(cookie_header: object, before_cookies: object) -> bool:
    """True when outgoing Cookie header includes an exact baseline logged-in name=value."""
    if not isinstance(cookie_header, str) or not cookie_header.strip():
        return False
    baseline_logged_in = [
        (rec[0], rec[3])
        for rec in canonical_wp_cookie_records(before_cookies, is_wp_auth_cookie_name)
        if rec[0].startswith("wordpress_logged_in_") and rec[3].strip()
    ]
    if not baseline_logged_in:
        return False
    sent: dict[str, list[str]] = {}
    for part in cookie_header.split(";"):
        item = part.strip()
        if "=" not in item:
            continue
        key, val = item.split("=", 1)
        sent.setdefault(key.strip(), []).append(val)
    return any(val in sent.get(name, []) for name, val in baseline_logged_in)


def assert_same_otp_refusal_class(anon_refusal: object, auth_refusal: object) -> dict:
    """Compare authenticated OTP replay refusal status and error shape to anonymous replay."""
    if not isinstance(anon_refusal, dict) or not isinstance(auth_refusal, dict):
        raise AssertionError("OTP refusal responses must be dicts")
    anon_status = anon_refusal.get("status")
    auth_status = auth_refusal.get("status")
    if type(anon_status) is not int or type(auth_status) is not int:
        raise AssertionError("OTP refusal status must be int")
    if anon_status not in (400, 401, 422) or auth_status != anon_status:
        raise AssertionError(f"OTP refusal status mismatch: anon={anon_status} auth={auth_status}")
    if not is_json_response(anon_refusal) or not is_json_response(auth_refusal):
        raise AssertionError("OTP refusal body is not a JSON object")
    anon_body = anon_refusal["data"]
    auth_body = auth_refusal["data"]
    anon_code = code_of(anon_refusal)
    auth_code = code_of(auth_refusal)
    if anon_code != "CLINIC_OTP_INVALID" or auth_code != anon_code:
        raise AssertionError(
            f"OTP refusal code mismatch: anon={safe_error_code(anon_code)!r} auth={safe_error_code(auth_code)!r}"
        )
    anon_msg = anon_body.get("message")
    auth_msg = auth_body.get("message")
    if (
        not isinstance(anon_msg, str) or not anon_msg.strip()
        or not isinstance(auth_msg, str) or not auth_msg.strip()
    ):
        raise AssertionError("OTP refusal message missing")
    anon_data = anon_body.get("data")
    auth_data = auth_body.get("data")
    if not isinstance(anon_data, dict) or not isinstance(auth_data, dict):
        raise AssertionError("OTP refusal data object missing")
    if anon_data.get("status") != anon_status or auth_data.get("status") != auth_status:
        raise AssertionError("OTP refusal data.status does not match HTTP status")
    if set(auth_body.keys()) != set(anon_body.keys()):
        raise AssertionError(
            f"OTP refusal keys differ: anon={safe_key_names(anon_body)} auth={safe_key_names(auth_body)}"
        )
    forbidden = {"session_issued", "user_id", "patient_links", "is_new_user"}
    if (forbidden & set(auth_body.keys())) or (forbidden & set(auth_data.keys())):
        raise AssertionError("OTP refusal exposed verify-success fields")
    return {"status": auth_status, "code": auth_code}


def check_wp_auth_cookies_unchanged(name: str, before_cookies: object, after_cookies: object) -> dict:
    """Run assert_wp_auth_cookies_unchanged inside check() without logging cookie values."""
    try:
        summary = assert_wp_auth_cookies_unchanged(before_cookies, after_cookies)
    except AssertionError as exc:
        check(name, False, str(exc))
        raise
    check(name, True, json.dumps(summary))
    return summary


def check_same_otp_refusal_class(name: str, anon_refusal: object, auth_refusal: object) -> dict:
    """Run assert_same_otp_refusal_class inside check()."""
    try:
        summary = assert_same_otp_refusal_class(anon_refusal, auth_refusal)
    except AssertionError as exc:
        check(name, False, str(exc))
        raise
    check(name, True, f"HTTP {summary['status']} {summary['code']}")
    return summary


def wp_eval_json(marker: str, code: str, env_extra: dict | None = None) -> dict:
    """Run a bounded WP-CLI PHP snippet and return the single JSON object for `marker`."""
    wp_dir = os.environ.get("WP_DIR", "").strip()
    if not wp_dir:
        raise RuntimeError("WP_DIR is required for database invariant checks")
    merged = os.environ.copy()
    if env_extra:
        merged.update(env_extra)
    proc = subprocess.run(
        ["wp", "eval", code, f"--path={wp_dir}", "--allow-root"],
        capture_output=True,
        text=True,
        timeout=90,
        env=merged,
    )
    if proc.returncode != 0:
        raise RuntimeError(
            f"WP-CLI eval ({marker}) failed rc={proc.returncode} "
            f"stderr_len={len(proc.stderr or '')} stdout_len={len(proc.stdout or '')}"
        )
    prefix = marker + "="
    line = next((row for row in (proc.stdout or "").splitlines() if row.startswith(prefix)), "")
    if not line:
        raise RuntimeError(f"WP-CLI eval ({marker}) returned no marked JSON line")
    parsed = json.loads(line[len(prefix):])
    if not isinstance(parsed, dict):
        raise RuntimeError(f"WP-CLI eval ({marker}) root must be an object")
    return parsed


def otp_db_snapshot(clinic_id: int, mobile: str) -> dict:
    """Read non-secret row counts and latest token state for OTP checks."""
    return wp_eval_json(
        "CPSD_OTP_COUNTS",
        r'''
$db = \ClinicCore\Bootstrap\App::db();
$wpdb = $db->wpdb();
$cid = (int) getenv("CPSD_SNAP_CLINIC_ID");
$mob = (string) getenv("CPSD_SNAP_MOBILE");
$tok = $db->fetchRow(
    "SELECT id, attempts, consumed_at FROM " . $db->table("cpms_otp_tokens")
    . " WHERE clinic_id = %d AND mobile = %s ORDER BY id DESC LIMIT 1",
    [$cid, $mob]
);
$last_ok = $db->fetchRow(
    "SELECT actor_wp_user_id FROM " . $db->table("cpms_audit_logs")
    . " WHERE clinic_id = %d AND action = %s ORDER BY id DESC LIMIT 1",
    [$cid, "OTP_VERIFY_OK"]
);
$last_actor = ($last_ok !== null && array_key_exists("actor_wp_user_id", $last_ok) && $last_ok["actor_wp_user_id"] !== null)
    ? (int) $last_ok["actor_wp_user_id"]
    : null;
echo "CPSD_OTP_COUNTS=" . wp_json_encode([
    "users" => (int) $db->fetchValue("SELECT COUNT(*) FROM {$wpdb->users}"),
    "links" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_patient_user_links")),
    "clinic_links" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_patient_user_links") . " WHERE clinic_id = %d", [$cid]),
    "patients" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_patients")),
    "clinic_patients" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_patients") . " WHERE clinic_id = %d", [$cid]),
    "tokens" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_otp_tokens") . " WHERE clinic_id = %d AND mobile = %s", [$cid, $mob]),
    "unconsumed" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_otp_tokens") . " WHERE clinic_id = %d AND mobile = %s AND consumed_at IS NULL", [$cid, $mob]),
    "latest_token_id" => $tok === null ? 0 : (int) $tok["id"],
    "latest_attempts" => $tok === null ? -1 : (int) $tok["attempts"],
    "latest_consumed" => $tok !== null && $tok["consumed_at"] !== null && (string) $tok["consumed_at"] !== "",
    "verify_ok" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_audit_logs") . " WHERE clinic_id = %d AND action = %s", [$cid, "OTP_VERIFY_OK"]),
    "last_verify_actor" => $last_actor,
    "login_success" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_audit_logs") . " WHERE action = %s", ["LOGIN_SUCCESS"]),
    "holds" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_slot_holds") . " WHERE clinic_id = %d", [$cid]),
    "appointments" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_appointments") . " WHERE clinic_id = %d", [$cid]),
]) . PHP_EOL;
''',
        {"CPSD_SNAP_CLINIC_ID": str(clinic_id), "CPSD_SNAP_MOBILE": mobile},
    )


def link_fixture_patient_account(clinic_id: int, patient_id: int, wp_user_id: int, mobile: str) -> dict:
    """Explicitly link the provisioned patient-only WP account to the fixture patient."""
    return wp_eval_json(
        "CPSD_PATIENT_LINK",
        r'''
$db = \ClinicCore\Bootstrap\App::db();
$cid = (int) getenv("CPSD_LINK_CLINIC_ID");
$pid = (int) getenv("CPSD_LINK_PATIENT_ID");
$uid = (int) getenv("CPSD_LINK_WP_USER_ID");
$mob = (string) getenv("CPSD_LINK_MOBILE");
$user = get_userdata($uid);
if (!$user) {
    throw new RuntimeException("synthetic patient WP user missing");
}
$roles = array_values((array) $user->roles);
$clinic_mem = (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_clinic_memberships") . " WHERE wp_user_id = %d", [$uid]);
$org_mem = (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_organization_memberships") . " WHERE wp_user_id = %d", [$uid]);
$is_admin = user_can($uid, "manage_options");
if ($roles !== [\ClinicCore\Auth\RolesAndCapabilities::ROLE_PATIENT] || $clinic_mem !== 0 || $org_mem !== 0 || $is_admin) {
    throw new RuntimeException("synthetic patient account is not patient-only");
}
$pat = $db->fetchRow("SELECT id, status FROM " . $db->table("cpms_patients") . " WHERE id = %d AND clinic_id = %d LIMIT 1", [$pid, $cid]);
if ($pat === null || (string) ($pat["status"] ?? "") !== "active") {
    throw new RuntimeException("fixture patient is missing or inactive");
}
$ok = $db->insert("cpms_patient_user_links", [
    "clinic_id" => $cid,
    "patient_id" => $pid,
    "wp_user_id" => $uid,
    "mobile_at_link" => $mob,
    "is_primary" => 1,
    "linked_at" => $db->nowUtcSql(),
]);
$link_id = $db->wpdb_last_insert_id();
if (!$ok || $link_id <= 0) {
    throw new RuntimeException("patient link insert failed");
}
echo "CPSD_PATIENT_LINK=" . wp_json_encode([
    "link_id" => $link_id,
    "roles" => $roles,
    "clinic_memberships" => $clinic_mem,
    "org_memberships" => $org_mem,
    "is_admin" => $is_admin,
    "clinic_links" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_patient_user_links") . " WHERE clinic_id = %d", [$cid]),
]) . PHP_EOL;
''',
        {
            "CPSD_LINK_CLINIC_ID": str(clinic_id),
            "CPSD_LINK_PATIENT_ID": str(patient_id),
            "CPSD_LINK_WP_USER_ID": str(wp_user_id),
            "CPSD_LINK_MOBILE": mobile,
        },
    )


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
    # Screenshot omitted: this POST response displays live one-time persona passwords.
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
    # Screenshot omitted: ACTION_LAUNCH displays live rotated persona passwords above.

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
    # Screenshot omitted: second ACTION_LAUNCH rotates and displays live persona passwords.

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


def patient_otp_journey(browser, targets: dict, creds: dict) -> None:
    """Possession-only OTP proof + linked patient password booking and replay.

    1. Anonymous visitor on the real booking page runs `/otp/request`, discloses
       the live challenge code via the scoped admin form, proves a wrong code is
       refused with `CLINIC_OTP_INVALID`, proves the valid code returns HTTP 200
       with the complete PR #340 zero-access envelope (`user_id=0`,
       `patient_links=[]`, `is_new_user=false`, `session_issued=false`), sets no
       auth cookie of any scheme, creates no `wp_users`, `cpms_patient_user_links`
       or `cpms_patients` row, records the `OTP_VERIFY_OK` (`actor_wp_user_id=0`)
       possession audit without `LOGIN_SUCCESS`, consumes the token, and refuses
       anonymous replay of the consumed code.
    2. The provisioned patient-only WP account is explicitly linked to the
       fixture patient via WP-CLI, signed in through `wp-login.php`, checks
       clinical fail-closed reads, replays the already-consumed OTP inside that
       authenticated browser context while verifying refusal equivalence, no WP
       auth/settings `Set-Cookie`, and unchanged auth/settings cookie names and
       values, and then completes `/booking/hold`, `/booking/confirm`,
       idempotent replay, and `/appointments/mine` under the same session.
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

    selftest_safe_detail()
    before_req = otp_db_snapshot(clinic_id, mobile)
    consumed_code = ""
    try:
        # -------------------------------------------- anonymous public page
        ctx = browser.new_context(viewport={"width": 1280, "height": 900})
        try:
            page = ctx.new_page()
            page.goto(str(booking.get("url")), wait_until="domcontentloaded")
            check("the anonymous public booking page renders its own OTP step",
                  page.locator('[data-role="otp-mobile"]').count() == 1
                  and page.locator('[data-role="otp-code"]').count() == 1, f"url={page.url}")
            check("the anonymous visitor starts without a WordPress session",
                  auth_cookie_names(ctx) == [], str(auth_cookie_names(ctx)))

            booking_cfg = booking_surface_config(page)
            booking_root = rest_root_from(booking_cfg)
            check("the booking widget publishes the REST root this install serves",
                  bool(booking_cfg.get("rest_root")), f"root={booking_cfg.get('rest_root')!r}")
            otp_request_path = str(booking_cfg.get("otp_request_path") or "/otp/request")
            otp_verify_path = str(booking_cfg.get("otp_verify_path") or "/otp/verify")
            check("the anonymous widget publishes the OTP continuation paths it uses",
                  otp_request_path.endswith("/otp/request") and otp_verify_path.endswith("/otp/verify"),
                  f"request={otp_request_path} verify={otp_verify_path}")

            with page.expect_response(
                lambda r: r.request.method == "POST" and ("/otp/request" in r.url or "%2Fotp%2Frequest" in r.url),
                timeout=25000,
            ) as req_resp_info:
                requested = api(page, "", "POST", otp_request_path, {
                    "mobile": mobile,
                    "purpose": "login",
                    "clinician_id": int(slot.get("clinician_id") or 0),
                    "slot_id": int(slot.get("id") or 0),
                    "slot_date": str(slot.get("date") or ""),
                    "slot_time": str(slot.get("time") or ""),
                }, rest_root=booking_root)
            check("the public OTP request creates a challenge bound to the fixture Clinic",
                  requested["status"] == 200 and is_json_response(requested)
                  and auth_cookie_names(ctx) == [] and not response_sets_auth_cookie(req_resp_info.value),
                  safe_response_detail(
                      requested,
                      cookie_count=len(auth_cookie_names(ctx)),
                      wp_set_cookie=response_sets_auth_cookie(req_resp_info.value),
                  ))
            after_req = otp_db_snapshot(clinic_id, mobile)
            challenge_token_id = int(after_req.get("latest_token_id") or 0)
            check("OTP request persists one unconsumed token and creates no user, link or patient",
                  after_req["tokens"] == before_req["tokens"] + 1
                  and challenge_token_id > int(before_req.get("latest_token_id") or 0)
                  and after_req["latest_consumed"] is False
                  and after_req["unconsumed"] == 1
                  and after_req["users"] == before_req["users"]
                  and after_req["links"] == before_req["links"]
                  and after_req["patients"] == before_req["patients"]
                  and after_req["login_success"] == before_req["login_success"],
                  safe_counts_detail(
                      tokens_delta=after_req["tokens"] - before_req["tokens"],
                      token_advanced=challenge_token_id > int(before_req.get("latest_token_id") or 0),
                      consumed=bool(after_req["latest_consumed"]),
                      unconsumed=after_req["unconsumed"],
                      users_delta=after_req["users"] - before_req["users"],
                      links_delta=after_req["links"] - before_req["links"],
                      patients_delta=after_req["patients"] - before_req["patients"],
                      login_success_delta=after_req["login_success"] - before_req["login_success"],
                  ))

            # --------------------------- sanctioned code disclosure (admin form)
            ctx_a, admin = admin_login(browser)
            try:
                admin.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
                submit_form(admin, "otp")
                otp_card = admin.locator('[data-cpsd-secret="otp"]')
                otp_node = otp_card.locator("[data-cpsd-otp-code]")
                otp_card_count = otp_card.count()
                otp_node_count = otp_node.count()
                code = otp_node.inner_text().strip() if otp_node_count else ""
                # Screenshot omitted: this POST response renders the live OTP code.
                check("the scoped admin form shows the code of that live challenge",
                      otp_card_count == 1 and otp_node_count == 1
                      and len(code) == 6 and code.isdigit(),
                      safe_counts_detail(
                          otp_card_count=otp_card_count,
                          otp_node_count=otp_node_count,
                          code_len=len(code),
                      ))
            finally:
                ctx_a.close()

            # ----------------------------------------- wrong code fails closed
            before_wrong = otp_db_snapshot(clinic_id, mobile)
            wrong = "000000" if code != "000000" else "111111"
            with page.expect_response(
                lambda r: r.request.method == "POST" and ("/otp/verify" in r.url or "%2Fotp%2Fverify" in r.url),
                timeout=25000,
            ) as wrong_resp_info:
                refused = api(page, "", "POST", otp_verify_path,
                              {"mobile": mobile, "code": wrong}, rest_root=booking_root)
            after_wrong = otp_db_snapshot(clinic_id, mobile)
            check("a wrong OTP code is refused by the production verify path",
                  refused["status"] in (400, 401, 422) and is_json_response(refused)
                  and code_of(refused) == "CLINIC_OTP_INVALID"
                  and after_wrong["latest_token_id"] == challenge_token_id
                  and after_wrong["latest_consumed"] is False
                  and after_wrong["unconsumed"] == 1
                  and after_wrong["latest_attempts"] == before_wrong["latest_attempts"] + 1
                  and after_wrong["verify_ok"] == before_wrong["verify_ok"],
                  safe_response_detail(
                      refused,
                      same_token=after_wrong["latest_token_id"] == challenge_token_id,
                      attempts=after_wrong["latest_attempts"],
                  ))
            check("the refused code issued no WordPress session and wrote no identity row",
                  auth_cookie_names(ctx) == []
                  and not response_sets_auth_cookie(wrong_resp_info.value)
                  and after_wrong["users"] == before_wrong["users"]
                  and after_wrong["links"] == before_wrong["links"]
                  and after_wrong["patients"] == before_wrong["patients"],
                  str(auth_cookie_names(ctx)))

            # ------------------------ real possession-only OTP verification
            before_verify = after_wrong
            with page.expect_response(
                lambda r: r.request.method == "POST" and ("/otp/verify" in r.url or "%2Fotp%2Fverify" in r.url),
                timeout=25000,
            ) as verify_resp_info:
                verified = api(page, "", "POST", otp_verify_path,
                               {"mobile": mobile, "code": code}, rest_root=booking_root)
            verified_data = payload_of(verified)
            check("the production verify path returns the HTTP 200 zero-access envelope",
                  verified["status"] == 200 and is_json_response(verified)
                  and is_zero_access_otp_envelope(verified_data),
                  safe_response_detail(verified, zero_access=is_zero_access_otp_envelope(verified_data)))
            check("OTP verification sets no WordPress auth or settings cookie",
                  auth_cookie_names(ctx) == []
                  and not response_sets_auth_cookie(verify_resp_info.value),
                  f"cookies={auth_cookie_names(ctx)}")
            after_verify = otp_db_snapshot(clinic_id, mobile)
            check("OTP verification creates no wp_users, patient link, patient, hold or appointment row",
                  after_verify["users"] == before_verify["users"]
                  and after_verify["links"] == before_verify["links"]
                  and after_verify["clinic_links"] == 0
                  and after_verify["patients"] == before_verify["patients"]
                  and after_verify["clinic_patients"] == before_verify["clinic_patients"]
                  and after_verify["holds"] == before_verify["holds"]
                  and after_verify["appointments"] == before_verify["appointments"],
                  safe_counts_detail(
                      users_delta=after_verify["users"] - before_verify["users"],
                      links_delta=after_verify["links"] - before_verify["links"],
                      clinic_links=after_verify["clinic_links"],
                      patients_delta=after_verify["patients"] - before_verify["patients"],
                      clinic_patients_delta=after_verify["clinic_patients"] - before_verify["clinic_patients"],
                      holds_delta=after_verify["holds"] - before_verify["holds"],
                      appointments_delta=after_verify["appointments"] - before_verify["appointments"],
                  ))
            check("OTP verification consumes the challenge and audits possession without LOGIN_SUCCESS",
                  after_verify["tokens"] == before_verify["tokens"]
                  and after_verify["latest_token_id"] == challenge_token_id
                  and after_verify["latest_consumed"] is True
                  and after_verify["unconsumed"] == 0
                  and after_verify["verify_ok"] == before_verify["verify_ok"] + 1
                  and type(after_verify["last_verify_actor"]) is int
                  and after_verify["last_verify_actor"] == 0
                  and after_verify["login_success"] == before_verify["login_success"],
                  safe_counts_detail(
                      same_token=after_verify["latest_token_id"] == challenge_token_id,
                      consumed=bool(after_verify["latest_consumed"]),
                      unconsumed=after_verify["unconsumed"],
                      verify_ok_delta=after_verify["verify_ok"] - before_verify["verify_ok"],
                      last_verify_actor=after_verify["last_verify_actor"],
                      login_success_delta=after_verify["login_success"] - before_verify["login_success"],
                  ))

            # ----------------------------------- anonymous replay of consumed code
            with page.expect_response(
                lambda r: r.request.method == "POST" and ("/otp/verify" in r.url or "%2Fotp%2Fverify" in r.url),
                timeout=25000,
            ) as replay_resp_info:
                replay = api(page, "", "POST", otp_verify_path,
                             {"mobile": mobile, "code": code}, rest_root=booking_root)
            # Keep the consumed code in local memory for the authenticated-session
            # replay check below; wiped in the outer finally and not logged.
            consumed_code = code
            code = ""
            after_replay = otp_db_snapshot(clinic_id, mobile)
            check("replaying the consumed OTP code is refused (single use)",
                  replay["status"] in (400, 401, 422) and is_json_response(replay)
                  and code_of(replay) == "CLINIC_OTP_INVALID",
                  safe_response_detail(replay))
            check("the replay issued no session cookie and wrote no identity or audit row",
                  auth_cookie_names(ctx) == []
                  and not response_sets_auth_cookie(replay_resp_info.value)
                  and after_replay["latest_token_id"] == challenge_token_id
                  and after_replay["latest_consumed"] is True
                  and after_replay["unconsumed"] == 0
                  and after_replay["users"] == after_verify["users"]
                  and after_replay["links"] == after_verify["links"]
                  and after_replay["patients"] == after_verify["patients"]
                  and after_replay["verify_ok"] == after_verify["verify_ok"]
                  and after_replay["login_success"] == after_verify["login_success"],
                  f"cookies={auth_cookie_names(ctx)}")
        finally:
            ctx.close()

        # ----- explicit patient-only link + native password login continuation
        linked = link_fixture_patient_account(clinic_id, patient_id, patient_wp, mobile)
        after_link = otp_db_snapshot(clinic_id, mobile)
        check("fixture patient-only WP account is explicitly linked without adding users or patients",
              int(linked.get("link_id") or 0) > 0
              and linked.get("roles") == ["cpms_patient"]
              and linked.get("clinic_memberships") == 0
              and linked.get("org_memberships") == 0
              and linked.get("is_admin") is False
              and after_link["users"] == after_replay["users"]
              and after_link["links"] == after_replay["links"] + 1
              and after_link["clinic_links"] == 1
              and after_link["patients"] == after_replay["patients"],
              safe_counts_detail(
                  has_link=int(linked.get("link_id") or 0) > 0,
                  patient_role_only=linked.get("roles") == ["cpms_patient"],
                  clinic_memberships=int(linked.get("clinic_memberships") or 0),
                  org_memberships=int(linked.get("org_memberships") or 0),
                  is_admin=bool(linked.get("is_admin")),
                  users_delta=after_link["users"] - after_replay["users"],
                  links_delta=after_link["links"] - after_replay["links"],
                  clinic_links=after_link["clinic_links"],
                  patients_delta=after_link["patients"] - after_replay["patients"],
              ))

        patient_ctx, patient_page = persona_login(
            browser, creds["patient"]["username"], creds["patient"]["password"]
        )
        try:
            patient_page.goto(patient_url, wait_until="domcontentloaded")
            portal_cfg_count = patient_page.locator("script.cpms-patient-portal__config").count()
            check("the linked patient session renders the patient portal",
                  portal_cfg_count == 1,
                  safe_counts_detail(config_count=portal_cfg_count))
            cfg = surface_config(patient_page, "linked patient")
            nonce = str(cfg.get("nonce", ""))
            root = str(cfg.get("rest_root", "") or REST)

            # The demo patient row carries a synthetic cross-Clinic IDENTITY, and
            # the delivered clinical reads require the WordPress user to be linked
            # to that IDENTITY, not merely to the patient row
            # (clinical_access_identity_ predicate). Linking
            # cpms_patient_user_links alone does not create an identity link, so
            # both reads stay fail-closed.
            me = api(patient_page, nonce, "GET", "/patient/me", rest_root=root)
            check("the linked patient's clinical read stays fail-closed without an identity link",
                  me["status"] == 404 and code_of(me) == "CLINIC_NOT_FOUND",
                  safe_response_detail(me))
            records = api(patient_page, nonce, "GET", "/patient/my-records", rest_root=root)
            listed = list_of(records)
            check("the linked patient's record list stays empty for the same reason",
                  records["status"] == 200 and listed == [],
                  safe_response_detail(records, record_count=len(listed)))

            # --- authenticated replay of the earlier-consumed OTP (session held)
            before_auth_replay = otp_db_snapshot(clinic_id, mobile)
            check("challenge replayed inside patient session is the earlier consumed OTP token",
                  len(consumed_code) == 6
                  and consumed_code.isdigit()
                  and before_auth_replay["tokens"] == after_verify["tokens"]
                  and before_auth_replay["latest_token_id"] == challenge_token_id
                  and before_auth_replay["latest_consumed"] is True
                  and before_auth_replay["unconsumed"] == 0,
                  safe_counts_detail(
                      same_token=before_auth_replay["latest_token_id"] == challenge_token_id,
                      consumed=bool(before_auth_replay["latest_consumed"]),
                      unconsumed=before_auth_replay["unconsumed"],
                  ))
            cookies_before_auth_replay = patient_ctx.cookies()

            # 1) Public-route call shape (no X-WP-Nonce; browser sends live session cookies).
            with patient_page.expect_response(
                lambda r: r.request.method == "POST" and ("/otp/verify" in r.url or "%2Fotp%2Fverify" in r.url),
                timeout=25000,
            ) as auth_replay_resp_info:
                auth_replay = api(patient_page, "", "POST", otp_verify_path,
                                  {"mobile": mobile, "code": consumed_code}, rest_root=booking_root)
            auth_replay_resp = auth_replay_resp_info.value
            cookies_after_auth_replay = patient_ctx.cookies()
            sent_cookie_public = request_carries_wp_logged_in_cookie(
                auth_replay_resp.request.header_value("cookie"),
                cookies_before_auth_replay,
            )
            check_same_otp_refusal_class(
                "authenticated OTP replay is refused with the same status and error class as anonymous replay",
                replay,
                auth_replay,
            )
            check("authenticated OTP replay sent the live session cookie and received no WP Set-Cookie",
                  sent_cookie_public and not response_sets_auth_cookie(auth_replay_resp),
                  f"sent_logged_in={sent_cookie_public} wp_set_cookie={response_sets_auth_cookie(auth_replay_resp)}")
            check_wp_auth_cookies_unchanged(
                "authenticated OTP replay did not change WordPress auth or settings cookie names or values",
                cookies_before_auth_replay,
                cookies_after_auth_replay,
            )

            # 2) REST-nonce call shape (keeps get_current_user_id() == patient_wp on the server).
            with patient_page.expect_response(
                lambda r: r.request.method == "POST" and ("/otp/verify" in r.url or "%2Fotp%2Fverify" in r.url),
                timeout=25000,
            ) as auth_nonce_replay_resp_info:
                auth_nonce_replay = api(patient_page, nonce, "POST", otp_verify_path,
                                        {"mobile": mobile, "code": consumed_code}, rest_root=booking_root)
            consumed_code = ""
            auth_nonce_replay_resp = auth_nonce_replay_resp_info.value
            cookies_after_nonce_replay = patient_ctx.cookies()
            sent_cookie_nonce = request_carries_wp_logged_in_cookie(
                auth_nonce_replay_resp.request.header_value("cookie"),
                cookies_before_auth_replay,
            )
            sent_rest_nonce = bool(nonce) and (auth_nonce_replay_resp.request.header_value("x-wp-nonce") == nonce)
            check_same_otp_refusal_class(
                "REST-authenticated OTP replay (with X-WP-Nonce) matches anonymous refusal class",
                replay,
                auth_nonce_replay,
            )
            check("REST-authenticated OTP replay sent cookie + nonce and received no WP Set-Cookie",
                  sent_cookie_nonce and sent_rest_nonce
                  and not response_sets_auth_cookie(auth_nonce_replay_resp),
                  f"sent_logged_in={sent_cookie_nonce} sent_nonce={sent_rest_nonce} "
                  f"wp_set_cookie={response_sets_auth_cookie(auth_nonce_replay_resp)}")
            check_wp_auth_cookies_unchanged(
                "REST-authenticated OTP replay kept WordPress auth and settings cookie names and values unchanged",
                cookies_before_auth_replay,
                cookies_after_nonce_replay,
            )
            after_auth_replay = otp_db_snapshot(clinic_id, mobile)
            check("authenticated OTP replay wrote no identity, booking, or audit row",
                  after_auth_replay["latest_token_id"] == challenge_token_id
                  and after_auth_replay["latest_consumed"] is True
                  and after_auth_replay["unconsumed"] == 0
                  and after_auth_replay["users"] == before_auth_replay["users"]
                  and after_auth_replay["links"] == before_auth_replay["links"]
                  and after_auth_replay["patients"] == before_auth_replay["patients"]
                  and after_auth_replay["holds"] == before_auth_replay["holds"]
                  and after_auth_replay["appointments"] == before_auth_replay["appointments"]
                  and after_auth_replay["verify_ok"] == before_auth_replay["verify_ok"]
                  and after_auth_replay["login_success"] == before_auth_replay["login_success"],
                  json.dumps({
                      "verify_ok_delta": after_auth_replay["verify_ok"] - before_auth_replay["verify_ok"],
                      "login_success_delta": after_auth_replay["login_success"] - before_auth_replay["login_success"],
                  }))

            # ------------------- captured write + idempotent replay, as the patient
            hold = api(patient_page, nonce, "POST", "/booking/hold", {
                "clinician_id": int(slot.get("clinician_id") or 0),
                "slot_id": int(slot.get("id") or 0),
                "slot_date": str(slot.get("date") or ""),
                "slot_time": str(slot.get("time") or ""),
            }, rest_root=root)
            hold_data = payload_of(hold)
            hold_token = str(hold_data.get("hold_token") or "")
            check("the linked patient holds a real slot",
                  hold["status"] == 200 and bool(hold_token),
                  safe_response_detail(hold, has_hold_token=bool(hold_token)))

            key = new_idempotency_key()
            confirm_body = {"hold_token": hold_token, "reason": "scoped demo patient journey"}
            captured = api(patient_page, nonce, "POST", "/booking/confirm", confirm_body,
                           headers={"Idempotency-Key": key}, rest_root=root)
            captured_data = payload_of(captured)
            appointment_id = int(captured_data.get("appointment_id") or captured_data.get("id") or 0)
            # No first_name/last_name is sent; under PR #340 confirm resolves the
            # caller's linked active Patient rather than creating a cpms_patients row.
            check("the captured booking is committed once for the linked patient "
                  "(no names sent, and no new patient row is created)",
                  captured["status"] == 200 and appointment_id > 0,
                  safe_response_detail(captured, has_appointment=appointment_id > 0))
            check("the committed appointment is a new row, not the fixture appointment",
                  appointment_id != int(targets.get("appointment_id") or 0),
                  safe_counts_detail(
                      distinct_from_fixture=appointment_id != int(targets.get("appointment_id") or 0),
                      has_appointment=appointment_id > 0,
                  ))

            replayed = api(patient_page, nonce, "POST", "/booking/confirm", confirm_body,
                           headers={"Idempotency-Key": key}, rest_root=root)
            replayed_data = payload_of(replayed)
            replayed_appointment_id = int(replayed_data.get("appointment_id") or replayed_data.get("id") or 0)
            check("replaying the identical request returns the same appointment",
                  replayed["status"] == 200
                  and replayed_appointment_id == appointment_id,
                  safe_response_detail(replayed, same_appointment=replayed_appointment_id == appointment_id))

            after_booking = otp_db_snapshot(clinic_id, mobile)
            booked_row = wp_eval_json(
                "CPSD_BOOKED_APPT",
                r'''
$db = \ClinicCore\Bootstrap\App::db();
$row = $db->fetchRow(
    "SELECT clinic_id, patient_id, slot_id, status FROM " . $db->table("cpms_appointments") . " WHERE id = %d LIMIT 1",
    [(int) getenv("CPSD_APPT_ID")]
);
echo "CPSD_BOOKED_APPT=" . wp_json_encode([
    "clinic_id" => (int) ($row["clinic_id"] ?? 0),
    "patient_id" => (int) ($row["patient_id"] ?? 0),
    "slot_id" => (int) ($row["slot_id"] ?? 0),
    "status" => (string) ($row["status"] ?? ""),
]) . PHP_EOL;
''',
                {"CPSD_APPT_ID": str(appointment_id)},
            )
            check("confirm and replay committed one appointment on the existing linked patient row",
                  after_booking["appointments"] == after_link["appointments"] + 1
                  and after_booking["patients"] == after_link["patients"]
                  and after_booking["users"] == after_link["users"]
                  and after_booking["links"] == after_link["links"]
                  and booked_row["clinic_id"] == clinic_id
                  and booked_row["patient_id"] == patient_id
                  and booked_row["slot_id"] == int(slot.get("id") or 0)
                  and booked_row["status"] == "confirmed",
                  safe_counts_detail(
                      appointments_delta=after_booking["appointments"] - after_link["appointments"],
                      patients_delta=after_booking["patients"] - after_link["patients"],
                      users_delta=after_booking["users"] - after_link["users"],
                      links_delta=after_booking["links"] - after_link["links"],
                      clinic_match=booked_row["clinic_id"] == clinic_id,
                      patient_match=booked_row["patient_id"] == patient_id,
                      slot_match=booked_row["slot_id"] == int(slot.get("id") or 0),
                      confirmed=booked_row["status"] == "confirmed",
                  ))

            mine = api(patient_page, nonce, "GET", "/appointments/mine", rest_root=root)
            mine_list = list_of(mine)
            slot_date = str(slot.get("date") or "")
            slot_time = str(slot.get("time") or "")[:5]
            same_slot = [row for row in mine_list
                         if isinstance(row, dict) and str(row.get("date") or "") == slot_date
                         and str(row.get("time") or "")[:5] == slot_time]
            same_appointment = len(same_slot) == 1 and int(same_slot[0].get("id") or 0) == appointment_id
            check("exactly one appointment exists for the captured slot after the replay",
                  mine["status"] == 200 and same_appointment,
                  safe_response_detail(
                      mine,
                      match_count=len(same_slot),
                      same_appointment=same_appointment,
                  ))
            check_wp_auth_cookies_unchanged(
                "patient session cookies stay unchanged after post-replay authenticated /appointments/mine",
                cookies_before_auth_replay,
                patient_ctx.cookies(),
            )

            patient_page.screenshot(path=str(EVIDENCE / "journey-patient-otp.png"))
        finally:
            patient_ctx.close()
    finally:
        consumed_code = ""


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
    # Before any cpms_patient_user_links row exists, the provisioned demo
    # patient account is authenticated but unlinked (and under PR #340 OTP
    # verify no longer writes links either), so both clinical routes must fail
    # CLOSED: an empty record list and a non-enumerating 404, never somebody
    # else's data.
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
          safe_records_detail(records, listed))
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

    # ---- possession-only OTP drill, then explicitly linked patient booking
    patient_otp_journey(browser, targets, creds)

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
        has_text=digit_agnostic_suffix_pattern("ویزیت ", pricing_visit),
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
          and has_money_text(stats, "500000 ریال")
          and has_money_text(stats, "750000 ریال")
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
    # DUAL-MODE: three sequential window.prompt()s today; the next product round
    # collects the same three values with three sequential shared in-page prompts.
    # The native handler above is untouched — when it consumed nothing, the values
    # are collected from the in-page dialog instead, and the step still fails if
    # neither form appears within the same 45s the assertions below already use.
    edit_shared = shared_dialog_available(page)
    answers_before = list(answers)
    page.locator('[data-role="pricing-charges"] tr').first.locator("button", has_text="اصلاح").click()
    native_prompts = len(answers_before) - len(answers)
    answer_shared_dialogs(
        page,
        [{"action": "prompt", "value": value} for value in answers],
        shared_expected=edit_shared,
        timeout_ms=45000,
        native_seen=native_prompts > 0,
    )
    page.wait_for_function(
        "() => document.querySelector('[data-role=\"pricing-charges\"]')"
        ".textContent.includes('اصلاح‌شده')",
        timeout=45000,
    )
    page.wait_for_function(
        f"() => {{ const norm = {MONEY_NORMALIZE_JS};"
        " const rows = document.querySelectorAll('[data-role=\"pricing-charges\"] tr');"
        " if (rows.length !== 1) return false;"
        " const cells = rows[0].querySelectorAll('td');"
        " return cells.length > 2 && norm(cells[2].textContent).trim() === '2'; }",
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
        has_text=digit_agnostic_suffix_pattern("ویزیت ", pricing_visit),
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
            "Unlinked password patient login fails closed; /otp/verify is checked as possession-only (zero-access envelope, no cookie, no identity writes, single-use replay refusal in anonymous and authenticated patient contexts), and authenticated patient booking runs after linking the patient-only WP account via WP-CLI",
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
