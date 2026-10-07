#!/usr/bin/env python3
"""One targeted, observation-only confirmation of the isolated WooCommerce route policy.

Runs only after the existing five-plugin browser smoke fails. The first isolation
pass has already established WooCommerce as the smallest reproducing subset; this
probe records the exact runtime decision, callback stack, capability inputs, and
My Account target without changing the decision. The active_plugins list is restored.
"""

from __future__ import annotations

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
ACTIVE_JSON = OUT / "coexistence-confirm-active.json"
ACTIVE_WRITER = OUT / "coexistence-confirm-set-active.php"
POLICY_TRACE = OUT / "woocommerce-admin-policy.jsonl"
MU_DIR = Path(WP_DIR) / "wp-content" / "mu-plugins"
MU_FILE = MU_DIR / "arena-woocommerce-policy-observer.php"
CPMS = "clinic-practice-management"
WOOCOMMERCE = "woocommerce"
EXPECTED = {
    "doctor": {
        "login": os.environ["DOCTOR_USER"],
        "password": os.environ["DOCTOR_PASS"],
        "role": "cpms_doctor",
        "path": "/wp-admin/admin.php?page=cpms-doctor",
    },
    "secretary": {
        "login": os.environ["SECRETARY_USER"],
        "password": os.environ["SECRETARY_PASS"],
        "role": "cpms_secretary",
        "path": "/wp-admin/admin.php?page=cpms-patients",
    },
}


class ConfirmationError(RuntimeError):
    pass


def wp(args: list[str], *, user: str | None = None) -> subprocess.CompletedProcess[str]:
    command = ["wp", f"--path={WP_DIR}", "--allow-root"]
    if user:
        command.append(f"--user={user}")
    command.extend(args)
    result = subprocess.run(command, text=True, capture_output=True, check=False)
    if result.returncode:
        raise ConfirmationError(f"WP-CLI failed ({' '.join(args[:2])}), exit={result.returncode}; output suppressed")
    return result


def wp_json(code: str, *, user: str | None = None):
    for line in reversed(wp(["eval", code], user=user).stdout.splitlines()):
        try:
            return json.loads(line)
        except json.JSONDecodeError:
            pass
    raise ConfirmationError("WP-CLI JSON diagnostic output was not parseable")


def active_files() -> list[str]:
    active = wp_json("echo wp_json_encode(array_values((array) get_option('active_plugins', [])));")
    if not isinstance(active, list) or not all(isinstance(item, str) for item in active):
        raise ConfirmationError("active_plugins is not a string list")
    return active


def slug(path: str) -> str:
    return path.split("/", 1)[0]


def active_info() -> dict[str, str]:
    try:
        rows = json.loads(wp(["plugin", "list", "--status=active", "--format=json"]).stdout)
    except json.JSONDecodeError as exc:
        raise ConfirmationError("WP-CLI active plugin list is not JSON") from exc
    return {str(row["name"]): str(row.get("version", "")) for row in rows if row.get("name")}


def set_active(files: list[str]) -> None:
    ACTIVE_JSON.write_text(json.dumps(files), encoding="utf-8")
    wp(["eval-file", str(ACTIVE_WRITER)])
    if active_files() != files:
        raise ConfirmationError("active_plugins update/restore did not persist")


def install_observer() -> None:
    MU_DIR.mkdir(parents=True, exist_ok=True)
    if MU_FILE.exists():
        raise ConfirmationError("temporary WooCommerce observer path already exists")
    POLICY_TRACE.write_text("", encoding="utf-8")
    POLICY_TRACE.chmod(0o666)
    try:
        MU_FILE.write_text(
            r'''<?php
/** Temporary read-only identity/policy observer; removed after this failed-only probe. */
add_action('wp_ajax_arena_rwp_coexistence_identity', static function () {
    $user = wp_get_current_user();
    $caps = [];
    foreach (['manage_options','edit_posts','manage_woocommerce','view_admin_dashboard','cpms_queue_read','cpms_patient_read','cpms_patient_create'] as $cap) {
        $caps[$cap] = current_user_can($cap);
    }
    file_put_contents('/tmp/acc/woocommerce-admin-policy.jsonl', wp_json_encode([
        'kind' => 'identity_probe',
        'user_login' => (string) $user->user_login,
        'roles' => array_values((array) $user->roles),
    ]) . PHP_EOL, FILE_APPEND | LOCK_EX);
    wp_send_json_success([
        'login' => (string) $user->user_login,
        'roles' => array_values((array) $user->roles),
        'capabilities' => $caps,
    ]);
});
add_filter('woocommerce_prevent_admin_access', static function ($prevent_access) {
    $request = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) (parse_url($request, PHP_URL_PATH) ?: '');
    if (!str_starts_with($path, '/wp-admin/') || str_ends_with($path, '/admin-ajax.php') || str_ends_with($path, '/admin-post.php')) {
        return $prevent_access;
    }
    $user = wp_get_current_user();
    $caps = [];
    foreach (['manage_options','edit_posts','manage_woocommerce','view_admin_dashboard','cpms_queue_read','cpms_patient_read','cpms_patient_create'] as $cap) {
        $caps[$cap] = current_user_can($cap);
    }
    $myaccount_page_id = (int) get_option('woocommerce_myaccount_page_id', 0);
    $method = null;
    if (class_exists('WC_Admin') && method_exists('WC_Admin', 'prevent_admin_access')) {
        $reflection = new ReflectionMethod('WC_Admin', 'prevent_admin_access');
        $file = (string) $reflection->getFileName();
        $method = [
            'class' => $reflection->getDeclaringClass()->getName(),
            'method' => $reflection->getName(),
            'file' => str_starts_with($file, WP_PLUGIN_DIR . '/') ? substr($file, strlen(WP_PLUGIN_DIR) + 1) : basename($file),
            'start_line' => $reflection->getStartLine(),
            'end_line' => $reflection->getEndLine(),
        ];
    }
    $stack = [];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32) as $frame) {
        $file = (string) ($frame['file'] ?? '');
        $symbol = (string) ($frame['class'] ?? '') . (string) ($frame['type'] ?? '') . (string) ($frame['function'] ?? '');
        if (str_contains($file, '/plugins/woocommerce/')) {
            $stack[] = ['file' => substr($file, strpos($file, '/plugins/woocommerce/') + 1), 'symbol' => $symbol, 'line' => (int) ($frame['line'] ?? 0)];
        }
    }
    $record = [
        'request_path' => $path,
        'request_page' => isset($_GET['page']) && str_starts_with((string) $_GET['page'], 'cpms-') ? (string) $_GET['page'] : null,
        'user_login' => (string) $user->user_login,
        'roles' => array_values((array) $user->roles),
        'capabilities' => $caps,
        'woocommerce_prevent_admin_access_final' => (bool) $prevent_access,
        'myaccount_page_id_option' => (string) $myaccount_page_id,
        'runtime_woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : null,
        'redirect_method' => $method,
        'woocommerce_stack' => $stack,
    ];
    file_put_contents('/tmp/acc/woocommerce-admin-policy.jsonl', wp_json_encode($record) . PHP_EOL, FILE_APPEND | LOCK_EX);
    return $prevent_access; // observation only: preserve WooCommerce's value unchanged.
}, PHP_INT_MAX, 1);
''',
            encoding="utf-8",
        )
        MU_FILE.chmod(0o644)
        lint = subprocess.run(["php", "-l", str(MU_FILE)], text=True, capture_output=True, check=False)
        if lint.returncode:
            raise ConfirmationError("temporary policy observer PHP syntax check failed")
    except Exception:
        MU_FILE.unlink(missing_ok=True)
        raise


def safe_url(url: str) -> dict[str, str | None]:
    parsed = urlparse(url)
    query = parse_qs(parsed.query)
    page = query.get("page", [None])[0]
    page_id = query.get("page_id", [None])[0]
    return {
        "path": parsed.path,
        "page": page if page and page.startswith("cpms-") else None,
        "page_id": page_id if page_id and page_id.isdigit() else None,
    }


def route_check(browser, persona: str) -> dict:
    spec = EXPECTED[persona]
    context = browser.new_context(viewport={"width": 1280, "height": 800}, locale="fa-IR")
    page = context.new_page()
    redirects = []
    phase = ["login"]

    def on_response(response) -> None:
        if not 300 <= response.status < 400:
            return
        path = urlparse(response.url).path
        if "/wp-admin/" not in path and not path.endswith("/wp-login.php"):
            return
        destination = urljoin(response.url, response.headers.get("location", ""))
        redirects.append({
            "phase": phase[0],
            "status": response.status,
            "from": safe_url(response.url),
            "to": safe_url(destination),
            "x_redirect_by": response.headers.get("x-redirect-by"),
        })

    page.on("response", on_response)
    try:
        page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded", timeout=15000)
        page.fill("#user_login", spec["login"])
        page.fill("#user_pass", spec["password"])
        page.click("#wp-submit")
        try:
            page.wait_for_url(lambda url: "wp-login.php" not in str(url), wait_until="domcontentloaded", timeout=10000)
        except PlaywrightTimeoutError:
            pass
        login_destination = safe_url(page.url)
        identity = page.evaluate("""async () => {
          try {
            const response = await fetch('/wp-admin/admin-ajax.php?action=arena_rwp_coexistence_identity', {credentials: 'same-origin'});
            const payload = await response.json();
            return {status: response.status, success: !!payload?.success, data: payload?.data || null};
          } catch (error) { return {status: 0, success: false, error: error?.name || 'fetch-error'}; }
        }""")
        identity_data = identity.get("data") if isinstance(identity, dict) else None
        identity_ok = bool(
            isinstance(identity, dict)
            and identity.get("status") == 200
            and identity.get("success")
            and isinstance(identity_data, dict)
            and identity_data.get("login") == spec["login"]
            and set(identity_data.get("roles", [])) == {spec["role"]}
        )
        phase[0] = "wp-admin-route"
        response = page.goto(f"{BASE}{spec['path']}", wait_until="domcontentloaded", timeout=20000)
        final = safe_url(page.url)
        return {
            "persona": persona,
            "expected_role": spec["role"],
            "identity_verified": identity_ok,
            "identity": identity_data,
            "login_destination": login_destination,
            "route_status": response.status if response else None,
            "admin_destination": final,
            "page9_redirect": final["page_id"] == "9" or any(
                row.get("phase") == "wp-admin-route" and row.get("to", {}).get("page_id") == "9" for row in redirects
            ),
            "redirects": redirects,
        }
    finally:
        context.close()


def read_policy_trace() -> list[dict]:
    if not POLICY_TRACE.exists():
        return []
    rows = []
    for line in POLICY_TRACE.read_text(encoding="utf-8").splitlines():
        try:
            rows.append(json.loads(line))
        except json.JSONDecodeError:
            continue
    return rows


def main() -> int:
    pin_path = OUT / "coexistence-plugin-pins.tsv"
    if not pin_path.is_file():
        raise ConfirmationError("pinned plugin manifest is missing")
    pins = {
        fields[0]: fields[1]
        for line in pin_path.read_text(encoding="utf-8").splitlines()
        if len(fields := line.split()) >= 2
    }
    if pins.get(WOOCOMMERCE) != "10.3.8":
        raise ConfirmationError("unexpected WooCommerce pin for policy confirmation")

    original = active_files()
    original_slugs = {slug(item) for item in original}
    required = set(pins) | {CPMS}
    if not required.issubset(original_slugs):
        raise ConfirmationError("full-five plugin set was not active before policy confirmation")
    original_info = active_info()
    if original_info.get(WOOCOMMERCE) != pins[WOOCOMMERCE] or not original_info.get(CPMS):
        raise ConfirmationError("full-five active plugin version control did not match")

    ACTIVE_WRITER.write_text(
        "<?php\n"
        "$target = json_decode((string) file_get_contents('/tmp/acc/coexistence-confirm-active.json'), true);\n"
        "if (!is_array($target)) { throw new RuntimeException('invalid active plugin set'); }\n"
        "update_option('active_plugins', $target);\n"
        "$actual = array_values((array) get_option('active_plugins', []));\n"
        "if ($actual !== array_values($target)) { throw new RuntimeException('active plugin set did not persist'); }\n"
        "echo wp_json_encode($actual) . PHP_EOL;\n",
        encoding="utf-8",
    )

    browser = None
    observer_installed = False
    restore_error = None
    try:
        install_observer()
        observer_installed = True
        target = [path for path in original if slug(path) in {CPMS, WOOCOMMERCE}]
        set_active(target)
        info = active_info()
        if set(info) != {CPMS, WOOCOMMERCE} or info.get(WOOCOMMERCE) != pins[WOOCOMMERCE]:
            raise ConfirmationError(f"WooCommerce-only subset mismatch: {dict(sorted(info.items()))}")
        print("CONFIRM active-slugs-and-versions=" + json.dumps(dict(sorted(info.items())), sort_keys=True), flush=True)

        with sync_playwright() as playwright:
            browser = playwright.chromium.launch()
            results = []
            for persona in ("doctor", "secretary"):
                POLICY_TRACE.write_text("", encoding="utf-8")
                POLICY_TRACE.chmod(0o666)
                route = route_check(browser, persona)
                evidence = {
                    "route": route,
                    "woocommerce_policy_calls": read_policy_trace(),
                }
                results.append(evidence)
                print("CONFIRM " + json.dumps(evidence, ensure_ascii=False, sort_keys=True), flush=True)
        for evidence in results:
            route = evidence["route"]
            policy_calls = evidence["woocommerce_policy_calls"]
            if not route["identity_verified"]:
                print("CONFIRM UNRESOLVED: browser persona identity not verified", flush=True)
                continue
            relevant = [row for row in policy_calls if row.get("user_login") == route["identity"].get("login")]
            matched = any(
                row.get("woocommerce_prevent_admin_access_final") is True
                and row.get("myaccount_page_id_option") == "9"
                and any(frame.get("symbol") == "WC_Admin->prevent_admin_access" for frame in row.get("woocommerce_stack", []))
                for row in relevant
            )
            if matched and route["page9_redirect"]:
                print("CONFIRM PROVEN: WooCommerce WC_Admin::prevent_admin_access returned true and the runtime My Account target matches the observed page_id=9 redirect", flush=True)
            else:
                print("CONFIRM UNRESOLVED: WooCommerce policy decision/stack did not explain the observed redirect", flush=True)
    finally:
        try:
            set_active(original)
            restored = active_info()
            if set(restored) != required or restored.get(WOOCOMMERCE) != pins[WOOCOMMERCE]:
                raise ConfirmationError("full plugin set/version failed to restore")
            print("CONFIRM restored-full-plugin-set=" + json.dumps(dict(sorted(restored.items())), sort_keys=True), flush=True)
        except Exception as exc:
            restore_error = exc
            print(f"CONFIRM RESTORE ERROR: {type(exc).__name__}", flush=True)
        if browser is not None:
            try:
                browser.close()
            except Exception:
                pass
        if observer_installed:
            MU_FILE.unlink(missing_ok=True)
        ACTIVE_JSON.unlink(missing_ok=True)
        ACTIVE_WRITER.unlink(missing_ok=True)
    return 1 if restore_error else 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"CONFIRM ERROR: {type(exc).__name__}: {exc}", flush=True)
        sys.exit(1)
