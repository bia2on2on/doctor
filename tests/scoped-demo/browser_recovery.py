#!/usr/bin/env python3
"""Real wrong-mobile OTP + non-fixture Hold refusal + blocked cleanup drill.

Under PR #340 (possession-only OTP):
  1. Anonymous public booking requests a live challenge for reserved synthetic
     `09009999999` (`/clinic/v1/otp/request`) and verifies it
     (`/clinic/v1/otp/verify`). Verify returns HTTP 200 with the complete
     zero-access envelope (`user_id=0`, `patient_links=[]`,
     `is_new_user=false`, `session_issued=false`), sets no WordPress auth or
     settings cookie, writes no `wp_users`, `cpms_patient_user_links` or
     `cpms_patients` row, audits `OTP_VERIFY_OK` (`actor_wp_user_id=0`) with
     zero `LOGIN_SUCCESS`, consumes the challenge (single-use replay rejected
     with `CLINIC_OTP_INVALID`), and keeps the booking page anonymous.
  2. To keep proving the scoped tenant write barrier on the real public booking
     Hold route without weakening any check, an explicit non-fixture
     patient-only WP account with a patient link signs in via `wp-login.php`
     and drives the same slot. `ScopedTenantGuard` refuses
     `/clinic/v1/booking/hold` with HTTP 403 `SCOPED_DEMO_TENANT_WRITE_REFUSED`
     and Persian refusal copy, offering no confirm button or receipt and
     creating zero hold, appointment or patient rows. The helper link and user
     are then removed so zero helper rows remain.
  3. Read-only cleanup preview (`diagnose`) and destructive cleanup
     (`teardown`) refuse with `SCOPED_FOREIGN_TENANT_ROW` on the genuine
     unattributable `09009999999` OTP/SMS residue alone, and Persian guidance
     offers no unsafe ownership claim or audit deletion.
"""
from __future__ import annotations

import json
import os
import secrets
import subprocess
import time
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ADMIN_USER = os.environ.get("ADMIN_USER", "admin")
ADMIN_PASS = os.environ["ADMIN_PASS"]
CI_DIR = Path(os.environ.get("CPSD_CI_DIR", "/tmp/cpsa-ci"))
EVIDENCE = Path(os.environ.get("CPSD_EVIDENCE_DIR", str(CI_DIR / "browser")))
WRONG_MOBILE = "09009999999"
BARRIER_CODE = "SCOPED_DEMO_TENANT_WRITE_REFUSED"
CHECKS: list[dict] = []


def annotate(title: str, message: str) -> None:
    flat = str(message).replace("%", "%25").replace("\r", "").replace("\n", " ")
    print(f"::error title={title}::{flat}", flush=True)


def check(name: str, ok: bool, detail: str = "") -> None:
    CHECKS.append({"name": name, "ok": bool(ok), "detail": str(detail)[:400]})
    print(("PASS " if ok else "FAIL ") + name + (f" — {detail}" if detail else ""), flush=True)
    if not ok:
        annotate("CPSA_RECOVERY", name + ((f" — {detail}" if detail else "")))
        raise AssertionError(name + (f": {detail}" if detail else ""))


def submit(page, action: str, phrase: str | None = None) -> None:
    """Submit one scoped action, asserting exactly the phrase policy it has.

    Setup and recovery are additive and are therefore one-click; only teardown is
    irreversible and still demands its typed phrase. Asserting both directions
    keeps a regression from slipping through as a merely cosmetic difference.
    """
    form = page.locator(f'form[data-cpsd-form="{action}"]')
    form.wait_for(state="visible")
    confirm = form.locator('input[name="cpdd_confirm"]')
    if phrase:
        check(f"phrase required: {action}", confirm.count() == 1)
        confirm.fill(phrase)
    else:
        check(f"no typed phrase: {action}", confirm.count() == 0)
    form.locator('button[type="submit"]').click()
    page.wait_for_load_state("domcontentloaded")


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


def has_route(url: str, route: str) -> bool:
    encoded = route.replace("/", "%2F")
    return route in url or encoded in url


def wp_eval_json(marker: str, code: str, env_extra: dict | None = None) -> dict:
    """Run a bounded WP-CLI PHP snippet and return the JSON object for `marker`."""
    wp_dir = os.environ.get("WP_DIR", "").strip()
    if not wp_dir:
        raise RuntimeError("WP_DIR is required for recovery database checks")
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
        raise RuntimeError(f"WP-CLI eval ({marker}) failed rc={proc.returncode}: {(proc.stderr or proc.stdout)[:240]}")
    prefix = marker + "="
    line = next((row for row in (proc.stdout or "").splitlines() if row.startswith(prefix)), "")
    if not line:
        raise RuntimeError(f"WP-CLI eval ({marker}) returned no marked JSON line")
    parsed = json.loads(line[len(prefix):])
    if not isinstance(parsed, dict):
        raise RuntimeError(f"WP-CLI eval ({marker}) root must be an object")
    return parsed


def active_fixture_ids() -> dict:
    return wp_eval_json(
        "CPSA_FIXTURE",
        r'''
$live = (new \CpmsDemo\ScopedManifest(new \CpmsDemo\Env()))->load();
if (!is_array($live) || (string) ($live["status"] ?? "") !== "active") {
    throw new RuntimeException("no active recovery fixture");
}
echo "CPSA_FIXTURE=" . wp_json_encode([
    "clinic_id" => (int) ($live["ids"]["clinic_id"] ?? 0),
    "patient_id" => (int) ($live["ids"]["patient_id"] ?? 0),
]) . PHP_EOL;
''',
    )


def recovery_db_snapshot(clinic_id: int, mobile: str) -> dict:
    return wp_eval_json(
        "CPSA_REC_COUNTS",
        r'''
$db = \ClinicCore\Bootstrap\App::db();
$wpdb = $db->wpdb();
$cid = (int) getenv("CPSA_SNAP_CLINIC_ID");
$mob = (string) getenv("CPSA_SNAP_MOBILE");
$tok = $db->fetchRow(
    "SELECT attempts FROM " . $db->table("cpms_otp_tokens")
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
echo "CPSA_REC_COUNTS=" . wp_json_encode([
    "users" => (int) $db->fetchValue("SELECT COUNT(*) FROM {$wpdb->users}"),
    "links" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_patient_user_links")),
    "patients" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_patients")),
    "tokens" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_otp_tokens") . " WHERE clinic_id = %d AND mobile = %s", [$cid, $mob]),
    "unconsumed" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_otp_tokens") . " WHERE clinic_id = %d AND mobile = %s AND consumed_at IS NULL", [$cid, $mob]),
    "attempts" => $tok === null ? -1 : (int) $tok["attempts"],
    "sms" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_sms_messages") . " WHERE clinic_id = %d AND recipient = %s AND event = %s AND context_type = %s", [$cid, $mob, "otp", "otp_token"]),
    "verify_ok" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_audit_logs") . " WHERE clinic_id = %d AND action = %s", [$cid, "OTP_VERIFY_OK"]),
    "last_verify_actor" => $last_actor,
    "login_success" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_audit_logs") . " WHERE action = %s", ["LOGIN_SUCCESS"]),
    "holds" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_slot_holds") . " WHERE clinic_id = %d", [$cid]),
    "appointments" => (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_appointments") . " WHERE clinic_id = %d", [$cid]),
]) . PHP_EOL;
''',
        {"CPSA_SNAP_CLINIC_ID": str(clinic_id), "CPSA_SNAP_MOBILE": mobile},
    )


def provision_non_fixture_patient(clinic_id: int, patient_id: int, password: str) -> dict:
    """Create a patient-only non-fixture WP account with a patient link (never prints password)."""
    return wp_eval_json(
        "CPSA_VISITOR",
        r'''
$db = \ClinicCore\Bootstrap\App::db();
$cid = (int) getenv("CPSA_VISITOR_CLINIC_ID");
$pid = (int) getenv("CPSA_VISITOR_PATIENT_ID");
$pass = (string) getenv("CPSA_VISITOR_PASS");
$live = (new \CpmsDemo\ScopedManifest(new \CpmsDemo\Env()))->load();
if (!is_array($live)) {
    throw new RuntimeException("manifest missing");
}
$sealed_users = array_map("intval", array_values((array) ($live["ids"]["user_ids"] ?? [])));
$login = "cpms-recovery-visitor-" . bin2hex(random_bytes(6));
$uid = wp_insert_user([
    "user_login" => $login,
    "user_pass" => $pass,
    "user_email" => $login . "@example.invalid",
    "display_name" => "بازدیدکننده غیردمو",
    "role" => \ClinicCore\Auth\RolesAndCapabilities::ROLE_PATIENT,
]);
if (is_wp_error($uid) || (int) $uid <= 0) {
    throw new RuntimeException("failed to create non-fixture patient user");
}
$uid = (int) $uid;
$user = get_userdata($uid);
$roles = $user ? array_values((array) $user->roles) : [];
$mem = (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_clinic_memberships") . " WHERE wp_user_id = %d", [$uid])
     + (int) $db->fetchValue("SELECT COUNT(*) FROM " . $db->table("cpms_organization_memberships") . " WHERE wp_user_id = %d", [$uid]);
$ok = $db->insert("cpms_patient_user_links", [
    "clinic_id" => $cid,
    "patient_id" => $pid,
    "wp_user_id" => $uid,
    "mobile_at_link" => "09009999998",
    "is_primary" => 1,
    "linked_at" => $db->nowUtcSql(),
]);
$link_id = $db->wpdb_last_insert_id();
if (!$ok || $link_id <= 0) {
    (new \CpmsDemo\Env())->delete_user($uid);
    throw new RuntimeException("failed to create non-fixture patient link");
}
echo "CPSA_VISITOR=" . wp_json_encode([
    "user_id" => $uid,
    "login" => $login,
    "link_id" => $link_id,
    "roles" => $roles,
    "in_manifest" => in_array($uid, $sealed_users, true),
    "is_admin" => user_can($uid, "manage_options"),
    "memberships" => $mem,
]) . PHP_EOL;
''',
        {
            "CPSA_VISITOR_CLINIC_ID": str(clinic_id),
            "CPSA_VISITOR_PATIENT_ID": str(patient_id),
            "CPSA_VISITOR_PASS": password,
        },
    )


def cleanup_non_fixture_patient(user_id: int, link_id: int) -> dict:
    """Remove the temporary helper link and WP user so only real wrong-mobile OTP/SMS remain."""
    return wp_eval_json(
        "CPSA_VISITOR_CLEAN",
        r'''
$db = \ClinicCore\Bootstrap\App::db();
$uid = (int) getenv("CPSA_CLEAN_USER_ID");
$lid = (int) getenv("CPSA_CLEAN_LINK_ID");
$deleted_links = $lid > 0 ? $db->delete("cpms_patient_user_links", ["id" => $lid, "wp_user_id" => $uid]) : 0;
$deleted_user = $uid > 0 ? (new \CpmsDemo\Env())->delete_user($uid) : false;
echo "CPSA_VISITOR_CLEAN=" . wp_json_encode([
    "deleted_links" => (int) $deleted_links,
    "deleted_user" => (bool) $deleted_user,
]) . PHP_EOL;
''',
        {"CPSA_CLEAN_USER_ID": str(user_id), "CPSA_CLEAN_LINK_ID": str(link_id)},
    )


def password_login(ctx, page, username: str, password: str, label: str) -> None:
    page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", username)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    page.wait_for_url(lambda url: "wp-login.php" not in url, wait_until="domcontentloaded", timeout=20000)
    logged_in = any(str(c.get("name", "")).startswith("wordpress_logged_in_") for c in ctx.cookies())
    check(
        f"native password login succeeded: {label}",
        logged_in and page.locator("#login_error").count() == 0 and "wp-login.php" not in page.url,
        page.url,
    )


def pick_primary_location(page) -> None:
    picker = page.locator('[data-role="location-picker"]')
    if picker.count() and picker.is_visible():
        option = page.locator('[data-role="location-option"]:not([disabled])').first
        option.wait_for(state="visible")
        option.click()
        page.wait_for_load_state("networkidle")


def main() -> int:
    EVIDENCE.mkdir(parents=True, exist_ok=True)
    evidence: dict = {}
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch()
            admin_ctx = browser.new_context(viewport={"width": 1366, "height": 900})
            admin = admin_ctx.new_page()
            admin.set_default_timeout(45000)
            admin.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
            admin.fill("#user_login", ADMIN_USER)
            admin.fill("#user_pass", ADMIN_PASS)
            admin.click("#wp-submit")
            admin.wait_for_load_state("domcontentloaded")
            admin.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
            if admin.locator('form[data-cpsd-form="enable"]').count():
                submit(admin, "enable")
            submit(admin, "provision")
            check("fresh recovery fixture provisioned through admin form", admin.locator('[data-cpsd-owner-guide="1"]').count() == 1)
            booking_url = admin.locator('[data-cpsd-owner-guide="1"] a.button-primary').get_attribute("href") or ""
            check("Owner guide exposes the public booking link", booking_url.startswith("http"), booking_url)

            fixture_ids = active_fixture_ids()
            clinic_id = int(fixture_ids.get("clinic_id") or 0)
            demo_patient_id = int(fixture_ids.get("patient_id") or 0)
            check("active recovery fixture resolves clinic and demo patient ids",
                  clinic_id > 0 and demo_patient_id > 0,
                  f"clinic={clinic_id} patient={demo_patient_id}")
            before_otp = recovery_db_snapshot(clinic_id, WRONG_MOBILE)

            # ---- 1. Anonymous wrong-mobile possession-only OTP journey ------
            ctx = browser.new_context(viewport={"width": 390, "height": 844})
            try:
                page = ctx.new_page()
                page.set_default_timeout(45000)
                page.goto(booking_url, wait_until="networkidle")
                check("anonymous recovery visitor starts with zero auth cookies",
                      auth_cookie_names(ctx) == [], str(auth_cookie_names(ctx)))
                # The fixture has two Locations. The public widget is location-first;
                # choose the primary offered Location before asking for availability.
                pick_primary_location(page)
                clinician_button = page.locator(".cpms-public-booking__clinician").first
                clinician_id = clinician_button.get_attribute("data-clinician-id") or ""
                with page.expect_response(lambda r: has_route(r.url, "/clinic/v1/availability")) as a1:
                    clinician_button.click()
                check("public availability A1 succeeds", a1.value.status == 200, str(a1.value.status))
                slot = page.locator(".cpms-public-booking__slot").first
                slot.wait_for(state="visible")
                slot_id = int(slot.get_attribute("data-slot-id") or 0)
                slot_time = slot.get_attribute("data-slot-time") or ""
                check("public slot carries an explicit server slot id", slot_id > 0, str(slot_id))
                check("public slot uses a realistic Iranian clinic hour", slot_time[:5] in ("09:00", "09:20", "09:40", "14:00", "14:20", "14:40"), slot_time)
                with page.expect_response(lambda r: has_route(r.url, "/clinic/v1/booking/quote")) as a4:
                    slot.click()
                check("public quote A4 succeeds", a4.value.status == 200, str(a4.value.status))
                page.locator('[data-role="continue-auth"]').click()
                page.locator('[data-role="otp-mobile"]').fill(WRONG_MOBILE)
                with page.expect_response(lambda r: has_route(r.url, "/clinic/v1/otp/request")) as a2:
                    page.locator('[data-auth-action="otp-request"]').click()
                check("wrong-mobile OTP request goes through production A2 with no auth cookie",
                      a2.value.status == 200
                      and auth_cookie_names(ctx) == []
                      and not response_sets_auth_cookie(a2.value),
                      str(a2.value.status))
                page.locator('[data-role="otp-code"]').wait_for(state="visible")

                before_verify = recovery_db_snapshot(clinic_id, WRONG_MOBILE)
                check("wrong-mobile OTP request persists unconsumed token and SMS with zero identity rows",
                      before_verify["tokens"] == before_otp["tokens"] + 1
                      and before_verify["unconsumed"] == 1
                      and before_verify["sms"] >= before_otp["sms"] + 1
                      and before_verify["users"] == before_otp["users"]
                      and before_verify["links"] == before_otp["links"]
                      and before_verify["patients"] == before_otp["patients"],
                      json.dumps({"tokens": before_verify["tokens"], "sms": before_verify["sms"]}))

                otp_path = CI_DIR / "private" / "synthetic-otp.json"
                code = ""
                for _ in range(40):
                    if otp_path.is_file():
                        code = str(json.loads(otp_path.read_text(encoding="utf-8")).get(WRONG_MOBILE, ""))
                    if len(code) == 6:
                        break
                    time.sleep(0.25)
                check("CI-only provider captured the reserved synthetic challenge", len(code) == 6, "code not printed")

                wrong_code = "000000" if code != "000000" else "111111"
                page.locator('[data-role="otp-code"]').fill(wrong_code)
                with page.expect_response(
                    lambda r: r.request.method == "POST" and has_route(r.url, "/clinic/v1/otp/verify")
                ) as a3_wrong:
                    page.locator('[data-auth-action="otp-verify"]').click()
                page.locator('[data-role="panel"][aria-busy="false"]').wait_for(state="attached")
                wrong_resp = a3_wrong.value
                try:
                    wrong_json = wrong_resp.json()
                except Exception:  # noqa: BLE001
                    wrong_json = {}
                wrong_err = str((wrong_json or {}).get("code") or "") if isinstance(wrong_json, dict) else ""
                after_wrong = recovery_db_snapshot(clinic_id, WRONG_MOBILE)
                check("wrong OTP code for wrong-mobile is refused without cookie or DB writes",
                      wrong_resp.status in (400, 401, 422)
                      and wrong_err == "CLINIC_OTP_INVALID"
                      and auth_cookie_names(ctx) == []
                      and not response_sets_auth_cookie(wrong_resp)
                      and after_wrong["unconsumed"] == 1
                      and after_wrong["attempts"] == before_verify["attempts"] + 1
                      and after_wrong["users"] == before_verify["users"]
                      and after_wrong["links"] == before_verify["links"]
                      and after_wrong["patients"] == before_verify["patients"]
                      and after_wrong["verify_ok"] == before_verify["verify_ok"]
                      and after_wrong["login_success"] == before_verify["login_success"],
                      f"status={wrong_resp.status} code={wrong_err}")

                page.locator('[data-role="otp-code"]').fill(code)
                code = ""

                with page.expect_response(
                    lambda r: r.request.method == "POST" and has_route(r.url, "/clinic/v1/otp/verify")
                ) as a3:
                    page.locator('[data-auth-action="otp-verify"]').click()
                page.locator('[data-role="panel"][aria-busy="false"]').wait_for(state="attached")
                verify_resp = a3.value
                try:
                    verify_json = verify_resp.json()
                except Exception:  # noqa: BLE001
                    verify_json = {}
                verify_data = verify_json.get("data") if isinstance(verify_json, dict) else None
                check("production OTP verification returns the HTTP 200 zero-access envelope",
                      verify_resp.status == 200 and is_zero_access_otp_envelope(verify_data),
                      f"status={verify_resp.status} data={json.dumps(verify_data, ensure_ascii=False)[:240]}")
                check("wrong-mobile OTP verify sets no WordPress auth or settings cookie",
                      auth_cookie_names(ctx) == [] and not response_sets_auth_cookie(verify_resp),
                      f"cookies={auth_cookie_names(ctx)}")

                auth_status = page.locator('[data-role="auth-status"]')
                auth_status.wait_for(state="visible")
                auth_status_text = auth_status.inner_text()[:240]
                check("public booking stays anonymous and shows possession-only notice without reload",
                      page.locator('[data-role="otp-code"]').get_attribute("aria-invalid") == "true"
                      and "ورود با کد یک‌بارمصرف در حال حاضر فعال نیست" in auth_status_text
                      and page.locator('[data-role="confirm-btn"]:visible').count() == 0
                      and page.locator('[data-role="receipt"]:visible').count() == 0,
                      auth_status_text)

                after_verify = recovery_db_snapshot(clinic_id, WRONG_MOBILE)
                check("wrong-mobile OTP verify creates no wp_users, patient link, patient, hold or appointment row",
                      after_verify["users"] == after_wrong["users"]
                      and after_verify["links"] == after_wrong["links"]
                      and after_verify["patients"] == after_wrong["patients"]
                      and after_verify["holds"] == after_wrong["holds"]
                      and after_verify["appointments"] == after_wrong["appointments"],
                      json.dumps({"before": after_wrong, "after": after_verify}, ensure_ascii=False)[:350])
                check("wrong-mobile OTP verify consumes challenge and writes possession audit without LOGIN_SUCCESS",
                      after_verify["tokens"] == after_wrong["tokens"]
                      and after_verify["unconsumed"] == 0
                      and after_verify["verify_ok"] == after_wrong["verify_ok"] + 1
                      and type(after_verify["last_verify_actor"]) is int
                      and after_verify["last_verify_actor"] == 0
                      and after_verify["login_success"] == after_wrong["login_success"],
                      json.dumps({
                          "unconsumed": after_verify["unconsumed"],
                          "verify_ok_delta": after_verify["verify_ok"] - after_wrong["verify_ok"],
                          "last_verify_actor": after_verify["last_verify_actor"],
                      }))

                with page.expect_response(
                    lambda r: r.request.method == "POST" and has_route(r.url, "/clinic/v1/otp/verify")
                ) as a3_replay:
                    page.locator('[data-auth-action="otp-verify"]').click()
                page.locator('[data-role="panel"][aria-busy="false"]').wait_for(state="attached")
                replay_resp = a3_replay.value
                try:
                    replay_json = replay_resp.json()
                except Exception:  # noqa: BLE001
                    replay_json = {}
                replay_code = str((replay_json or {}).get("code") or "") if isinstance(replay_json, dict) else ""
                after_replay = recovery_db_snapshot(clinic_id, WRONG_MOBILE)
                check("replaying the consumed wrong-mobile OTP code is refused with no side effects",
                      replay_resp.status in (400, 401, 422)
                      and replay_code == "CLINIC_OTP_INVALID"
                      and auth_cookie_names(ctx) == []
                      and not response_sets_auth_cookie(replay_resp)
                      and after_replay["users"] == after_verify["users"]
                      and after_replay["links"] == after_verify["links"]
                      and after_replay["patients"] == after_verify["patients"]
                      and after_replay["verify_ok"] == after_verify["verify_ok"]
                      and after_replay["login_success"] == after_verify["login_success"],
                      f"status={replay_resp.status} code={replay_code}")
            finally:
                ctx.close()

            # ---- 2. Non-fixture signed-in patient Hold barrier proof --------
            visitor_pass = secrets.token_hex(24)
            visitor = provision_non_fixture_patient(clinic_id, demo_patient_id, visitor_pass)
            try:
                check("explicit non-fixture visitor is a patient-only WP account outside the manifest",
                      int(visitor.get("user_id") or 0) > 0
                      and int(visitor.get("link_id") or 0) > 0
                      and visitor.get("roles") == ["cpms_patient"]
                      and visitor.get("in_manifest") is False
                      and visitor.get("is_admin") is False
                      and visitor.get("memberships") == 0,
                      json.dumps({
                          "roles": visitor.get("roles"),
                          "in_manifest": visitor.get("in_manifest"),
                          "is_admin": visitor.get("is_admin"),
                          "memberships": visitor.get("memberships"),
                      }))
                before_hold = recovery_db_snapshot(clinic_id, WRONG_MOBILE)
                visitor_ctx = browser.new_context(viewport={"width": 390, "height": 844})
                try:
                    visitor_page = visitor_ctx.new_page()
                    visitor_page.set_default_timeout(45000)
                    password_login(visitor_ctx, visitor_page, str(visitor["login"]), visitor_pass, "non-fixture-patient")
                    visitor_pass = ""
                    visitor_page.goto(booking_url, wait_until="networkidle")
                    pick_primary_location(visitor_page)
                    with visitor_page.expect_response(lambda r: has_route(r.url, "/clinic/v1/availability")) as a1b:
                        visitor_page.locator(f'.cpms-public-booking__clinician[data-clinician-id="{clinician_id}"]').first.click()
                    check("patient-mode availability for non-fixture patient succeeds",
                          a1b.value.status == 200, str(a1b.value.status))
                    same_slot = visitor_page.locator(f'.cpms-public-booking__slot[data-slot-id="{slot_id}"]').first
                    same_slot.wait_for(state="visible")

                    # In patient mode a successful read-only quote starts the Hold by
                    # itself (`applyVerdict` -> `beginHold`), so record Hold responses
                    # before clicking and inspect bodies outside the callback.
                    holds: list = []

                    def record_hold(response) -> None:
                        if has_route(response.url, "/clinic/v1/booking/hold"):
                            holds.append(response)

                    visitor_page.on("response", record_hold)
                    with visitor_page.expect_response(lambda r: has_route(r.url, "/clinic/v1/booking/quote")) as a4b:
                        same_slot.click()
                    check("patient-mode quote of the same slot succeeds", a4b.value.status == 200, str(a4b.value.status))
                    for _ in range(120):
                        if holds:
                            break
                        visitor_page.wait_for_timeout(250)
                    hold_status = holds[0].status if holds else 0
                    try:
                        hold_payload = holds[0].json() if holds else {}
                    except Exception:  # noqa: BLE001 - a non-JSON body is itself informative
                        hold_payload = {}
                    hold_code = str((hold_payload or {}).get("code") or "")
                    evidence["hold_status"] = hold_status
                    evidence["hold_code"] = hold_code
                    evidence["appointment_created"] = False
                    check(
                        "the tenant write barrier REFUSES the Hold of a real (non-fixture) visitor",
                        hold_status == 403 and hold_code == BARRIER_CODE,
                        f"status={hold_status} code={hold_code or '(none)'}",
                    )
                    status_panel = visitor_page.locator('[data-role="continue-status"]')
                    if status_panel.count():
                        try:
                            status_panel.wait_for(state="visible", timeout=5000)
                        except Exception:  # noqa: BLE001 - checked explicitly below
                            pass
                    status_text = status_panel.inner_text()[:300] if status_panel.count() else ""
                    refusal_marker = "\u062d\u0633\u0627\u0628\u200c\u0647\u0627\u06cc \u0633\u0627\u062e\u062a\u06af\u06cc"
                    evidence["barrier_text_present"] = refusal_marker in status_text
                    check(
                        "the refusal is explained in Persian on the booking page",
                        refusal_marker in status_text,
                        status_text or "no status text",
                    )
                    check(
                        "no confirm affordance and no receipt are offered after the refused Hold",
                        visitor_page.locator('[data-role="confirm-btn"]:visible').count() == 0
                        and visitor_page.locator('[data-role="receipt"]:visible').count() == 0,
                    )
                    after_hold = recovery_db_snapshot(clinic_id, WRONG_MOBILE)
                    check(
                        "the refused Hold persisted zero slot holds, appointments or patients",
                        after_hold["holds"] == before_hold["holds"]
                        and after_hold["appointments"] == before_hold["appointments"]
                        and after_hold["patients"] == before_hold["patients"],
                        json.dumps({"holds": after_hold["holds"], "appointments": after_hold["appointments"]}),
                    )
                    visitor_page.screenshot(path=str(EVIDENCE / "wrong-mobile-barrier-refused.png"), full_page=True)
                    evidence.update({"slot_id": slot_id, "slot_time": slot_time, "mobile": WRONG_MOBILE})
                finally:
                    visitor_pass = ""
                    visitor_ctx.close()
            finally:
                visitor_pass = ""
                cleaned = cleanup_non_fixture_patient(int(visitor.get("user_id") or 0), int(visitor.get("link_id") or 0))

            after_cleanup = recovery_db_snapshot(clinic_id, WRONG_MOBILE)
            check(
                "temporary non-fixture helper removed cleanly while real wrong-mobile OTP/SMS remain",
                cleaned.get("deleted_links") == 1
                and cleaned.get("deleted_user") is True
                and after_cleanup["users"] == before_otp["users"]
                and after_cleanup["links"] == before_otp["links"]
                and after_cleanup["patients"] == before_otp["patients"]
                and after_cleanup["tokens"] == before_otp["tokens"] + 1
                and after_cleanup["unconsumed"] == 0
                and after_cleanup["sms"] >= before_otp["sms"] + 1,
                json.dumps({"tokens": after_cleanup["tokens"], "sms": after_cleanup["sms"]}),
            )

            # ---- 3. Blocked cleanup proof on genuine wrong-mobile residue ---
            admin.goto(f"{BASE}/wp-admin/tools.php?page=cpms-scoped-demo", wait_until="domcontentloaded")
            submit(admin, "diagnose")
            diag = admin.locator('[data-cpsd-diagnostic="fail"]')
            check("cleanup preview refuses unattributable wrong-mobile rows", diag.count() == 1 and "SCOPED_FOREIGN_TENANT_ROW" in diag.inner_text(),
                  diag.inner_text()[:300] if diag.count() else "no fail card")
            submit(admin, "teardown", "REMOVE_ONLY_SCOPED_DEMO_DATA")
            notice = admin.locator('[data-cpdd-notice="error"]')
            blocked_code = notice.get_attribute("data-cpdd-code") if notice.count() else ""
            check("destructive cleanup is blocked, not silently successful", blocked_code in ("SCOPED_FOREIGN_TENANT_ROW", "TEARDOWN_BLOCKED")
                  and admin.locator('[data-cpsd-teardown="clean"]').count() == 0, str(blocked_code))
            guide = admin.locator('[data-cpsd-owner-guide="1"]').inner_text()
            check("Persian guidance explains no unsafe ownership claim or audit deletion", "ادعای مالکیت" in guide and "حسابرسی" in guide, guide[:200])
            admin.screenshot(path=str(EVIDENCE / "wrong-mobile-cleanup-blocked.png"), full_page=True)
            evidence["blocked_code"] = blocked_code
            admin_ctx.close()
            browser.close()
    except Exception as exc:  # noqa: BLE001
        check("wrong-mobile recovery drill completed", False, f"{type(exc).__name__}: {exc}")

    ok = all(c["ok"] for c in CHECKS)
    evidence.setdefault("hold_refused", True)
    result = {
        "ok": ok,
        "checks": CHECKS,
        "evidence": evidence,
        "limitation": "Unknown anonymous OTP/SMS rows are preserved; no automatic ownership claim is offered. "
                      "OTP verification is possession-only (zero-access, no cookie, no identity writes), and "
                      "the demo tenant write barrier refuses every real (non-fixture) patient on Hold.",
    }
    (EVIDENCE / "recovery-browser.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print("CPSA_RECOVERY_RESULT=" + json.dumps(
        {"ok": ok, "checks": len(CHECKS), "hold_status": evidence.get("hold_status"),
         "hold_code": evidence.get("hold_code"), "blocked_code": evidence.get("blocked_code", "")},
        ensure_ascii=False), flush=True)
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
