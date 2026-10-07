#!/usr/bin/env python3
"""Failure-only, bounded isolation of the observed custom-role wp-admin redirect.

This probes one existing authenticated CPMS admin route against plugin subsets;
it does not run the full acceptance suite per plugin and never changes capabilities.
The disposable WordPress site's active_plugins option is restored in finally.
"""

from __future__ import annotations

import itertools
import json
import os
import subprocess
import sys
from pathlib import Path
from urllib.parse import parse_qs, urljoin, urlparse

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

BASE = os.environ.get("WP_URL", "http://localhost:8080").rstrip("/")
WP_DIR = os.environ.get("WP_DIR", "/home/runner/rwp")
OUT = Path("/tmp/acc")
ACTIVE_JSON = OUT / "coexistence-isolate-active.json"
ACTIVE_WRITER = OUT / "coexistence-isolate-set-active.php"
REDIRECT_TRACE = OUT / "coexistence-redirect-trace.jsonl"
MU_DIR = Path(WP_DIR) / "wp-content" / "mu-plugins"
MU_FILE = MU_DIR / "arena-coexistence-redirect-trace.php"

# Stable order makes group splits and subset enumeration reproducible.
PLUGIN_SLUGS = (
    "autodescription",
    "contact-form-7",
    "autoptimize",
    "wordfence",
    "woocommerce",
)
CPMS_SLUG = "clinic-practice-management"
ROUTE = {
    "doctor": {
        "login": os.environ["DOCTOR_USER"],
        "password": os.environ["DOCTOR_PASS"],
        "expected_role": "cpms_doctor",
        "admin_path": "/wp-admin/admin.php?page=cpms-doctor",
        "expected_page": "cpms-doctor",
    },
    "secretary": {
        "login": os.environ["SECRETARY_USER"],
        "password": os.environ["SECRETARY_PASS"],
        "expected_role": "cpms_secretary",
        "admin_path": "/wp-admin/admin.php?page=cpms-patients",
        "expected_page": "cpms-patients",
    },
}
class IsolationError(RuntimeError):
    pass


def run_wp(args: list[str], *, user: str | None = None) -> subprocess.CompletedProcess[str]:
    command = ["wp", f"--path={WP_DIR}", "--allow-root"]
    if user:
        command.append(f"--user={user}")
    command.extend(args)
    result = subprocess.run(command, text=True, capture_output=True, check=False)
    if result.returncode:
        raise IsolationError(f"WP-CLI failed ({' '.join(args[:2])}), exit={result.returncode}; output intentionally suppressed")
    return result


def json_from_wp(code: str, *, user: str | None = None):
    result = run_wp(["eval", code], user=user)
    for line in reversed(result.stdout.splitlines()):
        try:
            return json.loads(line)
        except json.JSONDecodeError:
            continue
    raise IsolationError("WP-CLI JSON diagnostic output was not parseable")


def get_active_plugins() -> list[str]:
    value = json_from_wp("echo wp_json_encode(array_values((array) get_option('active_plugins', [])));")
    if not isinstance(value, list) or not all(isinstance(item, str) for item in value):
        raise IsolationError("active_plugins did not contain a string list")
    return value


def plugin_slug(plugin_file: str) -> str:
    return plugin_file.split("/", 1)[0]


def write_active_plugins(plugin_files: list[str]) -> None:
    ACTIVE_JSON.write_text(json.dumps(plugin_files), encoding="utf-8")
    result = run_wp(["eval-file", str(ACTIVE_WRITER)])
    after = get_active_plugins()
    if after != plugin_files:
        raise IsolationError(f"active_plugins restore/set did not stick: {result.stdout.strip()[:300]}")


def active_plugin_info() -> dict[str, str]:
    result = run_wp(["plugin", "list", "--status=active", "--format=json"])
    try:
        rows = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise IsolationError("WP-CLI active plugin list was not valid JSON") from exc
    return {
        str(row["name"]): str(row.get("version", ""))
        for row in rows
        if row.get("name")
    }


def install_temporary_redirect_observer() -> None:
    MU_DIR.mkdir(parents=True, exist_ok=True)
    if MU_FILE.exists():
        raise IsolationError("temporary redirect observer path already exists")
    REDIRECT_TRACE.write_text("", encoding="utf-8")
    REDIRECT_TRACE.chmod(0o666)
    try:
        MU_FILE.write_text(
        r'''<?php
/** Temporary, observation-only redirect trace for the disposable acceptance site. */
add_action('wp_ajax_arena_rwp_coexistence_identity', static function () {
    $user = wp_get_current_user();
    $capabilities = [];
    foreach (['manage_options', 'cpms_queue_read', 'cpms_patient_read', 'cpms_patient_create'] as $cap) {
        $capabilities[$cap] = current_user_can($cap);
    }
    wp_send_json_success([
        'login' => (string) $user->user_login,
        'roles' => array_values((array) $user->roles),
        'capabilities' => $capabilities,
    ]);
});
add_filter('wp_redirect', static function ($location, $status) {
    if (!is_string($location)) {
        return $location;
    }
    $request = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $request_path = (string) (parse_url($request, PHP_URL_PATH) ?: '');
    $query = [];
    parse_str((string) (parse_url($location, PHP_URL_QUERY) ?: ''), $query);
    if (!str_starts_with($request_path, '/wp-admin/') || (string) ($query['page_id'] ?? '') !== '9') {
        return $location;
    }
    $frames = [];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
        $file = (string) ($frame['file'] ?? '');
        if ($file === '' || !str_starts_with($file, WP_PLUGIN_DIR . '/')) {
            continue;
        }
        $relative = substr($file, strlen(WP_PLUGIN_DIR) + 1);
        $symbol = (string) ($frame['class'] ?? '') . (string) ($frame['type'] ?? '') . (string) ($frame['function'] ?? '');
        $row = [
            'plugin' => explode('/', $relative, 2)[0],
            'file' => $relative,
            'symbol' => $symbol,
            'line' => (int) ($frame['line'] ?? 0),
        ];
        $frames[implode('|', $row)] = $row;
    }
    $record = [
        'request_path' => $request_path,
        'destination_page_id' => '9',
        'status' => (int) $status,
        'plugin_frames' => array_values($frames),
    ];
    file_put_contents('/tmp/acc/coexistence-redirect-trace.jsonl', wp_json_encode($record) . PHP_EOL, FILE_APPEND | LOCK_EX);
    return $location;
}, PHP_INT_MAX, 2);
''',
            encoding="utf-8",
        )
        MU_FILE.chmod(0o644)
        lint = subprocess.run(["php", "-l", str(MU_FILE)], text=True, capture_output=True, check=False)
        if lint.returncode:
            raise IsolationError("temporary redirect observer PHP syntax check failed")
    except Exception:
        MU_FILE.unlink(missing_ok=True)
        raise


def sanitized_location(url: str) -> dict[str, str | None]:
    parsed = urlparse(url)
    query = parse_qs(parsed.query)
    page = query.get("page", [None])[0]
    page_id = query.get("page_id", [None])[0]
    return {
        "path": parsed.path,
        "page": page if page and page.startswith("cpms-") else None,
        "page_id": page_id if page_id and page_id.isdigit() else None,
    }


def trace_redirects(page, phase: list[str], records: list[dict]) -> None:
    def on_response(response) -> None:
        if not 300 <= response.status < 400:
            return
        request_path = urlparse(response.url).path
        if "/wp-admin/" not in request_path and not request_path.endswith("/wp-login.php"):
            return
        headers = response.headers
        destination = urljoin(response.url, headers.get("location", ""))
        records.append(
            {
                "phase": phase[0],
                "status": response.status,
                "from": sanitized_location(response.url),
                "to": sanitized_location(destination),
                "x_redirect_by": headers.get("x-redirect-by"),
            }
        )

    page.on("response", on_response)


def route_probe(browser, subset: tuple[str, ...], persona: str) -> dict:
    config = ROUTE[persona]
    context = browser.new_context(viewport={"width": 1280, "height": 800}, locale="fa-IR")
    page = context.new_page()
    phase = ["login"]
    redirects: list[dict] = []
    trace_redirects(page, phase, redirects)
    login_landed = False
    final_response = None
    try:
        page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded", timeout=15000)
        page.fill("#user_login", config["login"])
        page.fill("#user_pass", config["password"])
        page.click("#wp-submit")
        try:
            page.wait_for_url(lambda url: "wp-login.php" not in str(url), wait_until="domcontentloaded", timeout=12000)
        except PlaywrightTimeoutError:
            pass
        login_landed = "wp-login.php" not in urlparse(page.url).path
        login_destination = sanitized_location(page.url)
        # A short-lived, read-only wp_ajax endpoint in the temporary MU observer
        # reports only this session's synthetic login, roles, and selected caps.
        # This verifies the actual browser persona instead of inferring it from
        # the login redirect URL, and never reads cookies or page bodies.
        identity = page.evaluate("""async () => {
          try {
            const response = await fetch('/wp-admin/admin-ajax.php?action=arena_rwp_coexistence_identity', {credentials: 'same-origin'});
            const payload = await response.json();
            return {status: response.status, success: !!payload?.success, data: payload?.data || null};
          } catch (error) {
            return {status: 0, success: false, data: null, error: error?.name || 'fetch-error'};
          }
        }""")
        identity_data = identity.get("data") if isinstance(identity, dict) else None
        actual_roles = set(identity_data.get("roles", [])) if isinstance(identity_data, dict) else set()
        persona_verified = bool(
            identity.get("status") == 200
            and identity.get("success")
            and isinstance(identity_data, dict)
            and identity_data.get("login") == config["login"]
            and actual_roles == {config["expected_role"]}
        )
        phase[0] = "wp-admin-route"
        final_response = page.goto(f"{BASE}{config['admin_path']}", wait_until="domcontentloaded", timeout=20000)
        page.wait_for_timeout(250)
        final = sanitized_location(page.url)
        dom = page.evaluate(
            """() => ({
              adminBar: !!document.querySelector('#wp-admin-bar-my-account'),
              cpmsMenuHrefs: [...document.querySelectorAll('#adminmenu a[href]')]
                .map((a) => a.getAttribute('href'))
                .filter((href) => href && href.includes('cpms-')),
              patientCreateForm: !!document.querySelector('#cp_pat_first')
            })"""
        )
        route_ok = (
            login_landed
            and persona_verified
            and final["path"].startswith("/wp-admin/")
            and final["page"] == config["expected_page"]
        )
        if persona == "doctor":
            route_ok = route_ok and any("page=cpms-doctor" in href for href in dom["cpmsMenuHrefs"])
        else:
            route_ok = route_ok and dom["patientCreateForm"]
        output = {
            "persona": persona,
            "login_landed_outside_login": login_landed,
            "persona_verified_by_authenticated_probe": persona_verified,
            "expected_role": config["expected_role"],
            "persona_identity": identity_data if isinstance(identity_data, dict) else {
                "status": identity.get("status") if isinstance(identity, dict) else None,
                "success": identity.get("success") if isinstance(identity, dict) else False,
                "error": identity.get("error") if isinstance(identity, dict) else "invalid-response",
            },
            "login_destination": login_destination,
            "route_status": final_response.status if final_response else None,
            "admin_destination": final,
            "admin_bar_present": dom["adminBar"],
            "cpms_menu_hrefs": dom["cpmsMenuHrefs"],
            "patient_create_form": dom["patientCreateForm"],
            "route_contract_pass": bool(route_ok),
            "observed_page9_redirect": final["page_id"] == "9" or any(
                entry.get("phase") == "wp-admin-route" and entry.get("to", {}).get("page_id") == "9"
                for entry in redirects
            ),
            "reproduced": bool(
                persona_verified
                and (
                    final["page_id"] == "9"
                    or any(
                        entry.get("phase") == "wp-admin-route" and entry.get("to", {}).get("page_id") == "9"
                        for entry in redirects
                    )
                )
            ),
            "redirects": redirects,
        }
        return output
    finally:
        context.close()


def read_redirect_trace() -> list[dict]:
    if not REDIRECT_TRACE.exists():
        return []
    rows = []
    for line in REDIRECT_TRACE.read_text(encoding="utf-8").splitlines():
        try:
            rows.append(json.loads(line))
        except json.JSONDecodeError:
            continue
    return rows[-20:]


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    pin_path = OUT / "coexistence-plugin-pins.tsv"
    if not pin_path.is_file():
        raise IsolationError("pinned plugin manifest is missing")
    pins = {
        fields[0]: fields[1]
        for line in pin_path.read_text(encoding="utf-8").splitlines()
        if len(fields := line.split()) >= 2
    }
    if tuple(pins) != PLUGIN_SLUGS:
        raise IsolationError(f"unexpected plugin manifest/order: {list(pins)}")

    original = get_active_plugins()
    present = {plugin_slug(path) for path in original}
    required = set(PLUGIN_SLUGS) | {CPMS_SLUG}
    missing = required - present
    unexpected = present - required
    if missing or unexpected:
        raise IsolationError(f"active plugin control mismatch: missing={sorted(missing)} unexpected={sorted(unexpected)}")
    original_info = active_plugin_info()
    if set(original_info) != required:
        raise IsolationError(f"WP-CLI active-list mismatch before isolation: {sorted(original_info)}")
    for slug, expected_version in pins.items():
        if original_info.get(slug) != expected_version:
            raise IsolationError(f"pre-isolation version drift for {slug}: {original_info.get(slug)} != {expected_version}")
    print("ISOLATION full-five-control-active=" + json.dumps(dict(sorted(original_info.items())), sort_keys=True), flush=True)

    candidate_active = lambda subset: [
        path for path in original if plugin_slug(path) == CPMS_SLUG or plugin_slug(path) in subset
    ]

    ACTIVE_WRITER.write_text(
        "<?php\n"
        "$target = json_decode((string) file_get_contents('/tmp/acc/coexistence-isolate-active.json'), true);\n"
        "if (!is_array($target)) { throw new RuntimeException('invalid active-plugin subset'); }\n"
        "update_option('active_plugins', $target);\n"
        "$actual = array_values((array) get_option('active_plugins', []));\n"
        "if ($actual !== array_values($target)) { throw new RuntimeException('active-plugin subset did not persist'); }\n"
        "echo wp_json_encode($actual) . PHP_EOL;\n",
        encoding="utf-8",
    )

    cache: dict[tuple[str, ...], dict] = {}
    created_observer = False
    browser = None
    outcome = 0
    try:
        install_temporary_redirect_observer()
        created_observer = True
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch()
            def probe(subset: tuple[str, ...], persona: str = "doctor") -> dict:
                key = tuple(sorted(subset))
                if key in cache and persona == "doctor":
                    return cache[key]
                write_active_plugins(candidate_active(set(key)))
                active_info = active_plugin_info()
                actual_names = set(active_info)
                expected_names = set(key) | {CPMS_SLUG}
                if actual_names != expected_names:
                    raise IsolationError(f"subset activation mismatch: expected={sorted(expected_names)} actual={sorted(actual_names)}")
                for slug in key:
                    if active_info.get(slug) != pins[slug]:
                        raise IsolationError(f"subset version drift for {slug}: {active_info.get(slug)} != {pins[slug]}")
                if not active_info.get(CPMS_SLUG):
                    raise IsolationError("CPMS active plugin version was not reported")
                REDIRECT_TRACE.write_text("", encoding="utf-8")
                REDIRECT_TRACE.chmod(0o666)
                result = route_probe(browser, key, persona)
                result["active_slugs"] = sorted(expected_names)
                result["active_plugin_versions"] = dict(sorted(active_info.items()))
                result["redirect_callback_trace"] = read_redirect_trace()
                print("ISOLATION " + json.dumps(result, ensure_ascii=False, sort_keys=True), flush=True)
                if persona == "doctor":
                    cache[key] = result
                return result

            full = tuple(PLUGIN_SLUGS)
            positive = probe(full)
            if not positive["persona_verified_by_authenticated_probe"]:
                print("ISOLATION UNRESOLVED: five-plugin browser persona identity was not verified", flush=True)
            elif not positive["reproduced"]:
                print(
                    "ISOLATION UNRESOLVED: five-plugin control did not reproduce the observed page_id=9 redirect "
                    "(route_contract_pass=" + str(positive["route_contract_pass"]) + ")",
                    flush=True,
                )
            else:
                empty = probe(())
                print(
                    "ISOLATION baseline=CPMS-only route_contract_pass="
                    + str(empty["route_contract_pass"])
                    + " reproduced=" + str(empty["reproduced"]),
                    flush=True,
                )
                smallest: tuple[str, ...] | None = None
                search_incomplete = False
                if not empty["persona_verified_by_authenticated_probe"]:
                    search_incomplete = True
                    print("ISOLATION UNRESOLVED: CPMS-only browser persona identity was not verified", flush=True)
                elif empty["reproduced"]:
                    smallest = ()

                if smallest is None and not search_incomplete:
                    # Predeclared binary split records whether either broad group
                    # is independently sufficient before cardinality-minimal search.
                    halves = (tuple(PLUGIN_SLUGS[:3]), tuple(PLUGIN_SLUGS[3:]))
                    for index, half in enumerate(halves, 1):
                        group = probe(half)
                        print(
                            f"ISOLATION group-{index}={list(half)} "
                            f"persona_verified={group['persona_verified_by_authenticated_probe']} "
                            f"reproduced={group['reproduced']}",
                            flush=True,
                        )

                    # Enumerate by increasing cardinality, deterministic manifest
                    # order. This proves a smallest-cardinality reproducer, without
                    # running the full acceptance matrix once per plugin.
                    for size in range(1, len(PLUGIN_SLUGS) + 1):
                        for combination in itertools.combinations(PLUGIN_SLUGS, size):
                            result = probe(combination)
                            if not result["persona_verified_by_authenticated_probe"]:
                                search_incomplete = True
                                print(
                                    "ISOLATION UNRESOLVED: browser persona could not be verified for subset="
                                    + json.dumps(list(combination)),
                                    flush=True,
                                )
                                break
                            if result["reproduced"]:
                                smallest = tuple(combination)
                                break
                        if smallest is not None or search_incomplete:
                            break
                if smallest is None and not search_incomplete:
                    # Full set was already a verified positive failure control.
                    smallest = full
                if smallest is None:
                    print("ISOLATION UNRESOLVED: no cardinality-minimal subset could be established", flush=True)
                else:
                    print("ISOLATION smallest-cardinality-doctor-reproducer=" + json.dumps(list(smallest)), flush=True)
                    secretary = probe(smallest, "secretary")
                    print("ISOLATION secretary-confirmation=" + json.dumps(secretary, ensure_ascii=False, sort_keys=True), flush=True)

                    # Show effective role capabilities and registered menus for the
                    # reproducing set, without dumping page bodies or user data.
                    write_active_plugins(candidate_active(set(smallest)))
                    cap_code = (
                        "$u = wp_get_current_user(); $r = $u->roles[0] ?? ''; $role = $r !== '' ? get_role($r) : null; "
                        "$caps = ['manage_options','cpms_queue_read','cpms_patient_read','cpms_patient_create']; "
                        "$out = ['roles' => $u->roles, 'caps' => [], 'cpms_menu_slugs' => [], 'cpms_submenus' => [], 'user_has_cap_callbacks' => []]; "
                        "foreach ($caps as $c) { $out['caps'][$c] = ['role' => $role !== null && !empty($role->capabilities[$c]), 'user' => current_user_can($c)]; } "
                        "do_action('admin_menu'); "
                        "foreach (($GLOBALS['menu'] ?? []) as $m) { $s = (string) ($m[2] ?? ''); if (str_contains($s, 'cpms-')) { $out['cpms_menu_slugs'][] = ['slug' => $s, 'cap' => (string) ($m[1] ?? '')]; } } "
                        "foreach (($GLOBALS['submenu'] ?? []) as $parent => $items) { foreach ($items as $m) { $s = (string) ($m[2] ?? ''); if (str_contains($s, 'cpms-')) { $out['cpms_submenus'][] = ['parent' => (string) $parent, 'slug' => $s, 'cap' => (string) ($m[1] ?? '')]; } } } "
                        "$hook = $GLOBALS['wp_filter']['user_has_cap'] ?? null; if ($hook instanceof WP_Hook) { foreach ($hook->callbacks as $priority => $callbacks) { foreach ($callbacks as $entry) { $fn = $entry['function']; if (is_string($fn)) { $name = $fn; } elseif (is_array($fn)) { $name = (is_object($fn[0]) ? get_class($fn[0]) : $fn[0]) . '::' . $fn[1]; } else { $name = 'closure'; } $out['user_has_cap_callbacks'][] = ['name' => $name, 'priority' => (int) $priority]; } } } "
                        "echo wp_json_encode($out) . PHP_EOL;"
                    )
                    for persona in ("doctor", "secretary"):
                        role_state = json_from_wp(cap_code, user=ROUTE[persona]["login"])
                        print(
                            "ISOLATION candidate-runtime-roles " + persona + " "
                            + json.dumps(role_state, ensure_ascii=False, sort_keys=True),
                            flush=True,
                        )
                    if not secretary["persona_verified_by_authenticated_probe"]:
                        print("ISOLATION UNRESOLVED: secretary browser identity could not be verified for the candidate subset", flush=True)
                    elif not secretary["reproduced"]:
                        print("ISOLATION UNRESOLVED: the doctor reproducer did not reproduce for the verified secretary route", flush=True)
    finally:
        # Restore the original five-plugin active option even if a probe fails.
        try:
            write_active_plugins(original)
            final_info = active_plugin_info()
            final_names = set(final_info)
            if final_names != required:
                raise IsolationError(f"failed to restore full plugin set: {sorted(final_names)}")
            for slug, expected_version in pins.items():
                if final_info.get(slug) != expected_version:
                    raise IsolationError(f"restored version drift for {slug}: {final_info.get(slug)} != {expected_version}")
            print("ISOLATION restored-full-plugin-set=" + json.dumps(dict(sorted(final_info.items())), sort_keys=True), flush=True)
        except Exception as restore_error:  # do not mask the original diagnostic failure
            print(f"ISOLATION RESTORE ERROR: {type(restore_error).__name__}: {restore_error}", flush=True)
            outcome = 1
        if browser is not None:
            try:
                browser.close()
            except Exception:
                pass
        if created_observer:
            try:
                MU_FILE.unlink(missing_ok=True)
            except OSError as cleanup_error:
                print(f"ISOLATION observer cleanup failed: {cleanup_error}", flush=True)
                outcome = 1
        ACTIVE_JSON.unlink(missing_ok=True)
        ACTIVE_WRITER.unlink(missing_ok=True)
    return outcome


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"ISOLATION ERROR: {type(exc).__name__}: {exc}", flush=True)
        sys.exit(1)
