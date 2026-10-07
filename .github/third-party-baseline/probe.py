#!/usr/bin/env python3
"""Phase 18 third-party compatibility BASELINE harness — evidence probes.

TEST-ONLY CI tooling.  Never loaded by WordPress, never part of the CPMS plugin,
never shipped in a release ZIP.  It exists so that one identical set of probes
runs before and after a subject activation, on any subject, instead of ~40
assertions being duplicated inside the workflow YAML.

Subcommands
    subject   record the frozen subject identity
    wp        WordPress / HTTP / database layer probes (pre or post activation)
    install   retrieve the exact pinned package(s), verify them, activate them
    browser   real-browser probes (Chromium through Playwright)
    finalize  derive the machine-readable subject result from recorded evidence

Evidence model — every recorded check carries:
    status   PASS | FAIL | INFO | FEATURE UNAVAILABLE | UNEXECUTED | NOT RUN
    klass    product-behavior | environment | harness-fixture
             | unavailable-feature | unexecuted
    material whether a FAIL of this check may fail the subject

The subject state is derived, never asserted by hand:
    NOT RUN  commercial subject without lawful owner-supplied material
    FAIL     at least one material check FAILed
    PARTIAL  no material FAIL, but something is FAIL / UNEXECUTED / FEATURE
             UNAVAILABLE
    PASS     every check PASS or INFO

CPMS is never installed here, so taxonomy category A and B are unreachable by
construction and are never emitted; see ``classify()``.
"""

from __future__ import annotations

import argparse
import hashlib
import http.cookiejar
import json
import os
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, List, Optional

PASS = "PASS"
FAIL = "FAIL"
INFO = "INFO"
UNAVAILABLE = "FEATURE UNAVAILABLE"
UNEXECUTED = "UNEXECUTED"
NOT_RUN = "NOT RUN"

PRODUCT = "product-behavior"
ENVIRONMENT = "environment"
HARNESS = "harness-fixture"
UNAVAILABLE_KLASS = "unavailable-feature"
UNEXECUTED_KLASS = "unexecuted"

THIRD_PARTY_BASELINE = "THIRD-PARTY BASELINE FAILURE - no CPMS classification applicable"
WORDPRESS_ORG_PACKAGE = "https://downloads.wordpress.org/plugin/{slug}.{version}.zip"

USER_AGENT = "cpms-phase18-third-party-baseline/1.0 (+github-actions)"
MAX_REDIRECTS = 10
BROWSER_TIMEOUT_MS = 60_000


# --------------------------------------------------------------------------- #
# HTTP
# --------------------------------------------------------------------------- #
class RedirectLoop(Exception):
    """A bounded redirect chain exceeded MAX_REDIRECTS or revisited a URL."""


class _BoundedRedirectHandler(urllib.request.HTTPRedirectHandler):
    """Follows redirects, records the chain, refuses loops."""

    def __init__(self) -> None:
        super().__init__()
        self.chain: List[Dict[str, Any]] = []
        self.looped = False
        self.loop_reason = ""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ANN001
        self.chain.append({"from": req.full_url, "to": newurl, "status": int(code)})
        if len(self.chain) > MAX_REDIRECTS:
            self.looped = True
            self.loop_reason = f"redirect chain exceeded {MAX_REDIRECTS} hops"
            raise RedirectLoop(self.loop_reason)
        if newurl in [hop["to"] for hop in self.chain[:-1]]:
            self.looped = True
            self.loop_reason = f"redirect chain revisited {newurl}"
            raise RedirectLoop(self.loop_reason)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def http_request(
    url: str,
    cookie_jar: Optional[http.cookiejar.CookieJar] = None,
    data: Optional[bytes] = None,
    headers: Optional[Dict[str, str]] = None,
    timeout: int = 60,
) -> Dict[str, Any]:
    """One HTTP exchange with a bounded, recorded redirect chain.

    Never raises for HTTP status codes; transport errors are captured as
    evidence.  Returns ``status``, ``final_url``, ``redirects``, ``chain``,
    ``looped``, ``loop_reason``, ``body`` (first 2000 chars), ``transport_error``.
    """
    handler = _BoundedRedirectHandler()
    # NOTE: never write `cookie_jar or CookieJar()` here. CookieJar defines
    # __len__, so an *empty* jar is falsy and would be silently replaced by a
    # throwaway jar — the admin session cookie would never persist and every
    # subject would fail the administrator-login probe for no real reason.
    if cookie_jar is None:
        cookie_jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(handler,
                                         urllib.request.HTTPCookieProcessor(cookie_jar))
    req_headers = {"User-Agent": USER_AGENT}
    if headers:
        req_headers.update(headers)
    result: Dict[str, Any] = {
        "status": 0,
        "final_url": url,
        "redirects": 0,
        "chain": [],
        "looped": False,
        "loop_reason": "",
        "body": "",
        "transport_error": "",
    }
    try:
        with opener.open(urllib.request.Request(url, data=data, headers=req_headers),
                         timeout=timeout) as response:
            result["status"] = int(response.status)
            result["final_url"] = response.geturl()
            result["body"] = response.read(2000).decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        result["status"] = int(exc.code)
        result["final_url"] = exc.url or url
        try:
            result["body"] = exc.read(2000).decode("utf-8", "replace")
        except Exception:  # pragma: no cover - defensive
            result["body"] = ""
    except RedirectLoop as exc:
        result["looped"] = True
        result["loop_reason"] = str(exc)
    except Exception as exc:  # noqa: BLE001 - transport failure is evidence, not a crash
        result["transport_error"] = f"{type(exc).__name__}: {exc}"
    result["chain"] = handler.chain
    result["redirects"] = len(handler.chain)
    if handler.looped:
        result["looped"] = True
        result["loop_reason"] = result["loop_reason"] or handler.loop_reason
    return result


def download(url: str, dest: str, attempts: int = 3) -> None:
    """Retrieve a package or raise. No fallback to another version, ever."""
    last = ""
    for attempt in range(1, attempts + 1):
        try:
            request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
            with urllib.request.urlopen(request, timeout=300) as response, \
                    open(dest, "wb") as handle:
                shutil.copyfileobj(response, handle)
            return
        except Exception as exc:  # noqa: BLE001
            last = f"{type(exc).__name__}: {exc}"
            time.sleep(2 * attempt)
    raise RuntimeError(f"retrieval failed after {attempts} attempts: {last}")


def sha256_of(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


# --------------------------------------------------------------------------- #
# Processes
# --------------------------------------------------------------------------- #
def run(cmd: List[str], timeout: int = 300) -> Dict[str, Any]:
    """Run a command capturing output; never raises on a non-zero exit code."""
    try:
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, check=False)
        return {"rc": proc.returncode, "stdout": proc.stdout, "stderr": proc.stderr}
    except Exception as exc:  # noqa: BLE001
        return {"rc": 127, "stdout": "", "stderr": f"{type(exc).__name__}: {exc}"}


def wp_cli(args: List[str], wp_dir: str, timeout: int = 300) -> Dict[str, Any]:
    return run(["wp", *args, f"--path={wp_dir}", "--allow-root"], timeout=timeout)


# --------------------------------------------------------------------------- #
# Evidence store
# --------------------------------------------------------------------------- #
class Store:
    """Append-only evidence store persisted as one JSON file per subject job."""

    def __init__(self, path: str) -> None:
        self.path = path
        self.data: Dict[str, Any] = {"subject": {}, "facts": {}, "checks": []}
        if os.path.isfile(path):
            with open(path, encoding="utf-8") as handle:
                loaded = json.load(handle)
            for key in ("subject", "facts", "checks"):
                if key in loaded:
                    self.data[key] = loaded[key]

    def fact(self, key: str, value: Any) -> None:
        self.data["facts"][key] = value

    def subject(self, **kwargs: Any) -> None:
        self.data["subject"].update(kwargs)

    def record(self, name: str, status: str, klass: str, detail: Any = "",
               material: bool = False, phase: str = "",
               expected: Any = None, observed: Any = None) -> None:
        self.data["checks"].append({
            "name": name,
            "status": status,
            "klass": klass,
            "material": bool(material),
            "phase": phase,
            "detail": detail,
            "expected": expected,
            "observed": observed,
        })

    def check(self, name: str, ok: bool, klass: str, detail: Any = "",
              material: bool = True, phase: str = "",
              expected: Any = None, observed: Any = None) -> bool:
        self.record(name, PASS if ok else FAIL, klass, detail, material, phase,
                    expected, observed)
        return ok

    def save(self) -> None:
        os.makedirs(os.path.dirname(self.path) or ".", exist_ok=True)
        with open(self.path, "w", encoding="utf-8") as handle:
            json.dump(self.data, handle, indent=2)
            handle.write("\n")


# --------------------------------------------------------------------------- #
# Shared probes
# --------------------------------------------------------------------------- #
def admin_credentials() -> tuple[str, str]:
    """Credentials come from the environment — never from argv or a log line."""
    return os.environ.get("TPB_ADMIN_USER", ""), os.environ.get("TPB_ADMIN_PASS", "")


def admin_login_session(base_url: str) -> Dict[str, Any]:
    """Prove a real administrator login and session over plain HTTP."""
    user, password = admin_credentials()
    outcome: Dict[str, Any] = {
        "ok": False, "login_status": 0, "admin_status": 0,
        "admin_final_url": "", "marker_found": False, "error": "",
    }
    if not user or not password:
        outcome["error"] = "TPB_ADMIN_USER/TPB_ADMIN_PASS not provided"
        return outcome

    jar = http.cookiejar.CookieJar()
    # WordPress only accepts the test cookie when the login page issued it.
    http_request(f"{base_url}/wp-login.php", cookie_jar=jar)
    payload = urllib.parse.urlencode({
        "log": user, "pwd": password, "wp-submit": "Log In",
        "redirect_to": f"{base_url}/wp-admin/", "testcookie": "1",
    }).encode()
    login = http_request(f"{base_url}/wp-login.php", cookie_jar=jar, data=payload,
                         headers={"Referer": f"{base_url}/wp-login.php"})
    outcome["login_status"] = login["status"]
    if login["transport_error"]:
        outcome["error"] = login["transport_error"]
        return outcome

    admin = http_request(f"{base_url}/wp-admin/", cookie_jar=jar)
    outcome["admin_status"] = admin["status"]
    outcome["admin_final_url"] = admin["final_url"]
    marker = "wp-admin" in admin["body"] and "wp-login.php" not in admin["final_url"]
    outcome["marker_found"] = marker
    outcome["ok"] = admin["status"] == 200 and marker
    if not outcome["ok"] and not outcome["error"]:
        outcome["error"] = (f"wp-admin returned {admin['status']} at {admin['final_url']}"
                            + ("" if marker else "; auth marker absent"))
    return outcome


def admin_ajax_probe(base_url: str) -> Dict[str, Any]:
    """Prove WordPress-layer admin-ajax reachability.

    ``GET /wp-admin/admin-ajax.php`` with no ``action`` is *expected* to be
    answered by WordPress itself with body ``0`` (HTTP 400 on modern WordPress,
    200 on older).  That is a correct application response, not a failure.  A
    negative control against a path that cannot exist proves the harness can
    tell a WordPress answer apart from an Apache 404.
    """
    probe = http_request(f"{base_url}/wp-admin/admin-ajax.php")
    body = probe["body"].strip()
    negative = http_request(f"{base_url}/wp-admin/__tpb_negative_control__.php")
    return {
        "status": probe["status"],
        "body": body[:80],
        "reached_wp_layer": probe["status"] in (200, 400) and body == "0",
        "transport_error": probe["transport_error"],
        "negative_status": negative["status"],
        "negative_distinguishable": negative["status"] == 404,
    }


def web_runtime_probe(probe_url: str) -> Dict[str, Any]:
    """Read PHP/SAPI/server identity from the Apache probe alias, outside WP."""
    response = http_request(probe_url, timeout=30)
    payload: Dict[str, Any] = {"php_version": "", "php_sapi": "",
                               "server_software": "", "transport_error": response["transport_error"]}
    if response["status"] == 200:
        try:
            parsed = json.loads(response["body"])
            payload.update({
                "php_version": parsed.get("php_version", ""),
                "php_sapi": parsed.get("php_sapi", ""),
                "server_software": parsed.get("server_software", ""),
            })
        except json.JSONDecodeError:
            payload["transport_error"] = "probe response was not JSON"
    else:
        payload["transport_error"] = payload["transport_error"] or f"HTTP {response['status']}"
    return payload


def cpms_absence(wp_dir: str) -> Dict[str, Any]:
    """CPMS must be absent from the filesystem, plugin set, options and tables."""
    plugin_files: List[str] = []
    for root, _dirs, files in os.walk(wp_dir):
        for name in files:
            if name == "clinic-practice-management.php":
                plugin_files.append(os.path.relpath(os.path.join(root, name), wp_dir))

    listing = wp_cli(["plugin", "list", "--format=json"], wp_dir)
    entries: List[str] = []
    if listing["rc"] == 0:
        try:
            for entry in json.loads(listing["stdout"] or "[]"):
                name = str(entry.get("name", ""))
                if "clinic-practice-management" in name or name.startswith("cpms"):
                    entries.append(name)
        except json.JSONDecodeError:
            listing["rc"] = 1

    # CPMS tables are "{wpdb->prefix}{cpms prefix}{name}" (CpmsDb::table), so
    # match any table containing "cpms_" whatever the prefix is.
    tables = wp_cli([
        "db", "query",
        "SELECT COUNT(*) FROM information_schema.tables "
        "WHERE table_schema = DATABASE() AND table_name LIKE '%cpms\\_%'",
        "--skip-column-names",
    ], wp_dir)
    raw_tables = (tables["stdout"] or "").strip()

    options = wp_cli(["option", "list", "--search=*cpms*", "--format=json"], wp_dir)
    raw_options = (options["stdout"] or "").strip()

    return {
        "plugin_dir_present": os.path.isdir(
            os.path.join(wp_dir, "wp-content", "plugins", "clinic-practice-management")),
        "plugin_files_found": plugin_files,
        "plugin_entries_found": entries,
        "table_count": int(raw_tables) if raw_tables.isdigit() else -1,
        "cpms_option_count": (0 if not raw_options else len(json.loads(raw_options)))
        if options["rc"] == 0 else -1,
        "wp_cli_rc": listing["rc"],
    }


def active_plugins(wp_dir: str) -> List[str]:
    listing = wp_cli(["plugin", "list", "--status=active", "--format=json"], wp_dir)
    try:
        return sorted(str(e.get("name", "")) for e in json.loads(listing["stdout"] or "[]"))
    except json.JSONDecodeError:
        return []


def table_count(wp_dir: str) -> int:
    result = wp_cli([
        "db", "query",
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()",
        "--skip-column-names",
    ], wp_dir)
    value = (result["stdout"] or "").strip()
    return int(value) if value.isdigit() else -1


def debug_log_state(wp_dir: str) -> Dict[str, Any]:
    path = os.path.join(wp_dir, "wp-content", "debug.log")
    if not os.path.isfile(path):
        return {"exists": False, "lines": 0, "fatal": 0, "warning": 0, "samples": []}
    with open(path, encoding="utf-8", errors="replace") as handle:
        lines = handle.read().splitlines()
    fatal = [ln for ln in lines if re.search(r"Fatal error|Uncaught", ln)]
    warning = [ln for ln in lines if re.search(r"\b(Warning|Notice|Deprecated)\b", ln)]
    return {"exists": True, "lines": len(lines), "fatal": len(fatal),
            "warning": len(warning), "samples": (fatal + warning)[:20]}


# --------------------------------------------------------------------------- #
# subcommand: subject
# --------------------------------------------------------------------------- #
def cmd_subject(args: argparse.Namespace) -> int:
    store = Store(args.store)
    store.subject(
        id=args.id, name=args.name, slug=args.slug,
        version_requested=args.version, source_type=args.source, kind=args.kind,
        lane=args.lane,
        dependencies=[d for d in (args.deps or "").split(",") if d and d != "-"],
    )
    if args.unavailable_feature:
        # Marked for that one feature only: not a PASS, and not a whole-plugin FAIL.
        store.record(f"feature::{args.unavailable_feature}", UNAVAILABLE, UNAVAILABLE_KLASS,
                     f"{args.unavailable_reason} - FEATURE UNAVAILABLE for this feature only",
                     False)
    store.save()
    print(f"[probe:subject] {args.id} lane={args.lane} deps={store.data['subject']['dependencies']}")
    return 0


# --------------------------------------------------------------------------- #
# subcommand: wp
# --------------------------------------------------------------------------- #
def cmd_wp(args: argparse.Namespace) -> int:
    store = Store(args.store)
    phase = args.phase
    base_url = args.wp_url.rstrip("/")
    store.fact(f"phase_{phase}_executed", True)

    # -- runtime / server identity ----------------------------------------- #
    runtime = web_runtime_probe(args.probe_url)
    for key, value in (("web_php_version", runtime["php_version"]),
                       ("web_php_sapi", runtime["php_sapi"]),
                       ("server_software", runtime["server_software"])):
        store.fact(f"{key}_{phase}", value)
    if runtime["transport_error"]:
        store.check(f"web_runtime_probe_{phase}", False, ENVIRONMENT,
                    runtime["transport_error"], True, phase, observed=runtime["transport_error"])
    store.check(f"php_web_version_exact_{phase}",
                runtime["php_version"].startswith(args.expect_php), ENVIRONMENT,
                "the PHP serving HTTP must match the frozen Lane A pin", True, phase,
                args.expect_php, runtime["php_version"])
    store.record(f"server_identity_{phase}", INFO, ENVIRONMENT, {
        "server_software": runtime["server_software"],
        "php_sapi": runtime["php_sapi"],
        "web_php_version": runtime["php_version"],
    }, phase=phase)

    core = wp_cli(["core", "version"], args.wp_dir)
    wp_version = (core["stdout"] or "").strip()
    store.fact(f"wp_core_version_{phase}", wp_version)
    store.check(f"wp_bootstrap_{phase}", core["rc"] == 0 and bool(wp_version), ENVIRONMENT,
                (core["stderr"] or "").strip()[:400], True, phase,
                observed=wp_version or core["stderr"][:200])
    store.check(f"wp_core_version_exact_{phase}", wp_version == args.expect_wp_version,
                ENVIRONMENT, "WordPress core must equal the frozen Lane A pin", True, phase,
                args.expect_wp_version, wp_version)

    cli_version = (wp_cli(["eval", "echo PHP_VERSION;"], args.wp_dir)["stdout"] or "").strip()
    store.fact(f"cli_php_version_{phase}", cli_version)
    store.check(f"php_cli_version_exact_{phase}", cli_version.startswith(args.expect_php),
                ENVIRONMENT, "WP-CLI PHP must match the frozen Lane A pin", True, phase,
                args.expect_php, cli_version)

    mysql = wp_cli(["db", "query", "SELECT VERSION()", "--skip-column-names"], args.wp_dir)
    store.fact(f"mysql_version_{phase}", (mysql["stdout"] or "").strip())
    store.record(f"mysql_version_{phase}", PASS if mysql["rc"] == 0 else FAIL, ENVIRONMENT,
                 "MySQL server version serving this subject", True, phase,
                 observed=(mysql["stdout"] or mysql["stderr"]).strip()[:200])

    tz = (wp_cli(["eval", "echo get_option('timezone_string').'|'.date_default_timezone_get();"],
                 args.wp_dir)["stdout"] or "").strip()
    locale = (wp_cli(["eval", "echo get_option('WPLANG').'|'.get_locale();"],
                     args.wp_dir)["stdout"] or "").strip()
    store.fact(f"timezone_{phase}", tz)
    store.fact(f"locale_{phase}", locale)
    store.record(f"timezone_and_locale_{phase}", PASS, ENVIRONMENT,
                 {"timezone": tz, "locale": locale}, phase=phase)

    roles = wp_cli(["user", "get", os.environ.get("TPB_ADMIN_USER", ""), "--field=roles"],
                   args.wp_dir)
    role_value = (roles["stdout"] or "").strip()
    store.check(f"administrator_login_capability_{phase}",
                roles["rc"] == 0 and "administrator" in role_value, ENVIRONMENT,
                "the baseline administrator must exist with the administrator role", True, phase,
                "administrator", role_value or roles["stderr"][:200])

    # -- plugin set --------------------------------------------------------- #
    plugins = active_plugins(args.wp_dir)
    store.fact(f"active_plugins_{phase}", plugins)
    if phase == "pre":
        store.check("active_plugins_baseline_clean_pre", plugins == [], HARNESS,
                    "a clean baseline has no active plugin before the subject is installed",
                    True, phase, [], plugins)
    else:
        expected = sorted(store.data["facts"].get("expected_active_post", []))
        store.check("subject_active_after_activation",
                    expected and all(p in plugins for p in expected), PRODUCT,
                    "every expected plugin must still be active after activation", True, phase,
                    expected, plugins)

    # -- database ----------------------------------------------------------- #
    count = table_count(args.wp_dir)
    store.fact(f"table_count_{phase}", count)
    store.record(f"table_count_{phase}", PASS if count >= 0 else FAIL, ENVIRONMENT,
                 "table count in this subject's own database", True, phase, observed=count)

    # -- public surfaces ---------------------------------------------------- #
    front = http_request(f"{base_url}/")
    store.fact(f"front_page_status_{phase}", front["status"])
    store.check(f"front_page_http_200_{phase}",
                front["status"] == 200 and not front["transport_error"],
                PRODUCT if phase == "post" else ENVIRONMENT,
                front["transport_error"] or {"final_url": front["final_url"]}, True, phase,
                200, front["status"] or front["transport_error"])
    store.check(f"no_redirect_loop_front_{phase}", not front["looped"],
                PRODUCT if phase == "post" else ENVIRONMENT,
                front["loop_reason"] or {"redirects": front["redirects"],
                                         "chain": front["chain"][:6]}, True, phase,
                observed={"looped": front["looped"], "redirects": front["redirects"]})

    rest = http_request(f"{base_url}/?rest_route=/")
    store.check(f"rest_index_reachable_{phase}",
                rest["status"] == 200 and '"namespaces"' in rest["body"],
                PRODUCT if phase == "post" else ENVIRONMENT,
                {"status": rest["status"], "transport_error": rest["transport_error"]}, True,
                phase, "HTTP 200 JSON index", rest["status"])

    ajax = admin_ajax_probe(base_url)
    store.fact(f"admin_ajax_{phase}", ajax)
    store.check(f"admin_ajax_wp_layer_reachable_{phase}", ajax["reached_wp_layer"],
                PRODUCT if phase == "post" else ENVIRONMENT,
                "admin-ajax with no action must be answered by WordPress itself (body '0', "
                "HTTP 200/400); a correct application response, not a failure", True, phase,
                "HTTP 200/400 with body '0'", {"status": ajax["status"], "body": ajax["body"]})
    store.check(f"admin_ajax_negative_control_{phase}", ajax["negative_distinguishable"],
                HARNESS, "a path that cannot exist must 404, proving that answer is WordPress",
                True, phase, 404, ajax["negative_status"])

    login = admin_login_session(base_url)
    store.fact(f"admin_login_{phase}", login)
    store.check(f"admin_login_session_{phase}", login["ok"],
                PRODUCT if phase == "post" else ENVIRONMENT, login["error"], True, phase,
                "HTTP 200 on /wp-admin/ with auth marker",
                {"login": login["login_status"], "admin": login["admin_status"]})
    if phase == "post":
        store.check("wp_admin_reachable_post", login["admin_status"] == 200, PRODUCT,
                    login["error"], True, phase, 200, login["admin_status"])

    # -- PHP diagnostics ---------------------------------------------------- #
    log = debug_log_state(args.wp_dir)
    store.fact(f"debug_log_{phase}", log)
    summary = {k: log[k] for k in ("exists", "lines", "fatal", "warning")}
    if phase == "pre":
        store.record("php_debug_log_baseline_pre", PASS if log["fatal"] == 0 else FAIL,
                     ENVIRONMENT, "debug-log baseline before the subject is installed",
                     True, phase, observed=summary)
    else:
        store.check("no_php_fatal_after_activation", log["fatal"] == 0, PRODUCT,
                    log["samples"][:10], True, phase, 0, log["fatal"])
        if log["warning"]:
            store.record("php_warnings_after_activation", FAIL, PRODUCT,
                         "non-fatal PHP diagnostics recorded; never fails the subject alone",
                         False, phase, observed=log["warning"])

    # -- CPMS absence ------------------------------------------------------- #
    absence = cpms_absence(args.wp_dir)
    store.fact(f"cpms_absence_{phase}", absence)
    store.check(f"cpms_plugin_directory_absent_{phase}",
                not absence["plugin_dir_present"] and not absence["plugin_files_found"],
                HARNESS, absence["plugin_files_found"], True, phase,
                "no clinic-practice-management directory or plugin file",
                absence["plugin_files_found"])
    store.check(f"cpms_plugin_entry_absent_{phase}",
                not absence["plugin_entries_found"] and absence["wp_cli_rc"] == 0, HARNESS,
                absence["plugin_entries_found"] or absence["wp_cli_rc"], True, phase,
                "wp plugin list succeeds and contains no CPMS entry",
                absence["plugin_entries_found"])
    store.check(f"cpms_tables_absent_{phase}", absence["table_count"] == 0, HARNESS,
                "no CPMS table may exist in this subject's database", True, phase,
                0, absence["table_count"])
    store.check(f"cpms_options_absent_{phase}", absence["cpms_option_count"] == 0, HARNESS,
                "no cpms_* option may exist", True, phase, 0, absence["cpms_option_count"])

    store.save()
    print(f"[probe:wp] phase={phase} checks={len(store.data['checks'])}")
    return 0


# --------------------------------------------------------------------------- #
# subcommand: install
# --------------------------------------------------------------------------- #
def cmd_install(args: argparse.Namespace) -> int:
    """Retrieve the exact pinned package(s), verify them, activate them.

    Fails closed: a retrieval failure is never answered by installing latest or
    another version, and a version mismatch is a hard class D failure.
    """
    store = Store(args.store)
    phase = "install"
    os.makedirs(args.package_dir, exist_ok=True)

    pairs: List[tuple[str, str, str]] = []
    for dep in [d for d in (args.deps or "").split(",") if d and d != "-"]:
        slug, _, version = dep.partition("=")
        pairs.append((slug, version, "dependency"))
    pairs.append((args.subject_slug, args.subject_version, "subject"))

    provenance: List[Dict[str, Any]] = []
    installed: List[str] = []
    hard_fail = False

    for slug, version, role in pairs:
        url = WORDPRESS_ORG_PACKAGE.format(slug=slug, version=version)
        entry: Dict[str, Any] = {
            "slug": slug, "role": role, "requested_version": version,
            "installed_version": "", "source_type": "wordpress_org_versioned_package",
            "source_url": url, "sha256": "", "activation_rc": None, "activation_output": "",
        }
        provenance.append(entry)
        zip_path = os.path.join(args.package_dir, f"{slug}-{version}.zip")
        try:
            download(url, zip_path)
        except RuntimeError as exc:
            store.check(f"package_retrieved::{slug}", False, HARNESS,
                        f"{exc}. Failing closed: no latest-version and no alternate-version "
                        "substitution. Separate C (transport/provider) from D (bad pin).",
                        True, phase, url, str(exc))
            hard_fail = True
            continue
        entry["sha256"] = sha256_of(zip_path)
        store.record(f"package_sha256::{slug}", PASS, HARNESS,
                     "SHA-256 of the exact retrieved package", phase=phase,
                     observed=entry["sha256"])

        install = wp_cli(["plugin", "install", zip_path], args.wp_dir)
        if install["rc"] != 0:
            store.check(f"package_installed::{slug}", False, HARNESS,
                        (install["stdout"] + install["stderr"])[:1200], True, phase, 0,
                        install["rc"])
            hard_fail = True
            os.remove(zip_path)
            continue
        installed_version = (wp_cli(["plugin", "get", slug, "--field=version"],
                                    args.wp_dir)["stdout"] or "").strip()
        entry["installed_version"] = installed_version
        store.check(f"installed_version_exact::{slug}", installed_version == version, HARNESS,
                    "installed version must equal the requested pin exactly", True, phase,
                    version, installed_version)
        if installed_version != version:
            hard_fail = True
        # The package is deleted once installed; it never reaches an artifact.
        os.remove(zip_path)
        installed.append(slug)

    if not hard_fail:
        for slug in installed:
            activation = wp_cli(["plugin", "activate", slug], args.wp_dir)
            for entry in provenance:
                if entry["slug"] == slug:
                    entry["activation_rc"] = activation["rc"]
                    entry["activation_output"] = (activation["stdout"]
                                                  + activation["stderr"])[-4000:]
            store.check(f"activation_succeeded::{slug}", activation["rc"] == 0, PRODUCT,
                        (activation["stdout"] + activation["stderr"])[:800], True, phase,
                        0, activation["rc"])

    # Dependency evidence is read from the exact installed package metadata —
    # branding and naming are never accepted as proof of a dependency.
    headers: List[Dict[str, Any]] = []
    if not hard_fail:
        listing = wp_cli([
            "eval",
            "require_once ABSPATH.'wp-admin/includes/plugin.php';"
            "foreach (get_plugins() as $file => $data) {"
            "echo json_encode(['file'=>$file,"
            "'slug'=>dirname($file)==='.'?basename($file,'.php'):dirname($file),"
            "'name'=>$data['Name'],'version'=>$data['Version'],"
            "'requires_plugins'=>$data['RequiresPlugins']??[],"
            "'requires_php'=>$data['RequiresPHP']??'',"
            "'requires_wp'=>$data['RequiresWP']??'']).\"\\n\";}",
        ], args.wp_dir)
        for line in (listing["stdout"] or "").splitlines():
            line = line.strip()
            if line.startswith("{"):
                try:
                    headers.append(json.loads(line))
                except json.JSONDecodeError:
                    continue
        store.check("plugin_header_metadata_read", listing["rc"] == 0 and bool(headers),
                    HARNESS, "dependency needs must come from the exact package metadata",
                    True, phase, observed=len(headers))
        for header in headers:
            store.record(f"requires_plugins::{header.get('slug')}", INFO, PRODUCT,
                         "declared `Requires Plugins` header of the exact installed package; "
                         "recorded as evidence instead of assuming a dependency",
                         phase=phase, observed=header.get("requires_plugins") or [])

    store.fact("provenance", provenance)
    store.fact("expected_active_post",
               [e["slug"] for e in provenance if e["installed_version"]])
    store.fact("plugin_headers", headers)

    with open(os.path.join(args.package_dir, "provenance.json"), "w", encoding="utf-8") as handle:
        json.dump(provenance, handle, indent=2)
        handle.write("\n")
    store.save()

    print(f"[probe:install] pairs={len(pairs)} installed={installed} hard_fail={hard_fail}")
    return 1 if hard_fail else 0


# --------------------------------------------------------------------------- #
# subcommand: browser
# --------------------------------------------------------------------------- #
def cmd_browser(args: argparse.Namespace) -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError as exc:
        store = Store(args.store)
        store.record("browser_probe", UNEXECUTED, UNEXECUTED_KLASS,
                     f"Playwright unavailable: {exc}", False, args.phase)
        store.save()
        print("[probe:browser] UNEXECUTED - Playwright unavailable")
        return 0

    store = Store(args.store)
    phase = args.phase
    base_url = args.wp_url.rstrip("/")
    user, password = admin_credentials()

    console_errors: List[str] = []
    page_errors: List[str] = []
    failed_important: List[str] = []
    failed_other: List[str] = []
    notices: List[str] = []
    screenshots: List[str] = []
    nav: Dict[str, Any] = {}
    login: Dict[str, Any] = {"ok": False, "final_url": "", "error": ""}
    ajax: Dict[str, Any] = {}
    rest_status = 0

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(args=["--no-sandbox"])
        context = browser.new_context(viewport={"width": 1366, "height": 900},
                                      user_agent=USER_AGENT)
        page = context.new_page()
        page.on("console", lambda m: console_errors.append(m.text[:300])
                if m.type == "error" else None)
        page.on("pageerror", lambda e: page_errors.append(str(e)[:300]))

        def on_request_failed(request) -> None:  # noqa: ANN001
            entry = f"{request.resource_type} {request.url[:200]}"
            important = request.url.startswith(base_url) and request.resource_type in (
                "document", "script", "stylesheet", "xhr", "fetch")
            (failed_important if important else failed_other).append(entry)

        page.on("requestfailed", on_request_failed)

        def walk_redirects(response) -> List[Dict[str, Any]]:  # noqa: ANN001
            chain: List[Dict[str, Any]] = []
            seen = set()
            current = response.request.redirected_from if response else None
            while current is not None and current.url not in seen:
                seen.add(current.url)
                chain.append({"url": current.url[:200]})
                current = current.redirected_from
            return chain

        try:
            response = page.goto(f"{base_url}/", wait_until="domcontentloaded",
                                 timeout=BROWSER_TIMEOUT_MS)
            nav = {"front_status": response.status if response else 0,
                   "front_url": page.url, "front_redirects": walk_redirects(response)}
            page.screenshot(path=os.path.join(args.out_dir, "front.png"))
            screenshots.append("front.png")

            page.goto(f"{base_url}/wp-login.php", wait_until="domcontentloaded",
                      timeout=BROWSER_TIMEOUT_MS)
            page.fill("input[name='log']", user)
            page.fill("input[name='pwd']", password)
            page.click("input#wp-submit")
            page.wait_for_load_state("domcontentloaded", timeout=BROWSER_TIMEOUT_MS)
            login["final_url"] = page.url
            login["ok"] = "/wp-admin/" in page.url and "wp-login.php" not in page.url
            if not login["ok"]:
                login["error"] = f"landed on {page.url}"
            else:
                page.screenshot(path=os.path.join(args.out_dir, "wp-admin.png"))
                screenshots.append("wp-admin.png")
                # Setup wizards / HTTPS recommendations / license and enrollment
                # prompts are RECORDED here. They are never dismissed, bypassed or
                # answered, and no product setting is weakened to reach green.
                for element in page.query_selector_all(".notice, .updated, .error"):
                    text = (element.inner_text() or "").strip()
                    if text:
                        notices.append(text[:300])

            rest = page.goto(f"{base_url}/?rest_route=/", wait_until="domcontentloaded",
                             timeout=BROWSER_TIMEOUT_MS)
            rest_status = rest.status if rest else 0

            api = context.request.get(f"{base_url}/wp-admin/admin-ajax.php",
                                      timeout=BROWSER_TIMEOUT_MS)
            body = api.text().strip()
            ajax = {"status": api.status, "body": body[:80],
                    "reached_wp_layer": api.status in (200, 400) and body == "0"}
        except Exception as exc:  # noqa: BLE001 - a browser failure is evidence
            store.check("browser_probe_executed", False, HARNESS,
                        f"{type(exc).__name__}: {exc}"[:600], True, phase)
            browser.close()
            store.save()
            return 0
        browser.close()

    store.fact(f"browser_{phase}", {
        "nav": nav, "login": login, "rest_status": rest_status, "admin_ajax": ajax,
        "console_errors": console_errors[:50], "page_errors": page_errors[:50],
        "failed_important_requests": failed_important[:50],
        "failed_other_requests": failed_other[:50],
        "notices": notices[:30], "screenshots": screenshots,
    })
    store.check("browser_front_page_render", nav.get("front_status") == 200, PRODUCT, nav,
                True, phase, 200, nav.get("front_status"))
    store.check("browser_no_redirect_loop",
                len(nav.get("front_redirects") or []) <= MAX_REDIRECTS, PRODUCT,
                nav.get("front_redirects"), True, phase,
                observed=len(nav.get("front_redirects") or []))
    store.check("browser_admin_login_session", login["ok"], PRODUCT, login["error"], True,
                phase, observed=login["final_url"])
    store.check("browser_rest_index", rest_status == 200, PRODUCT,
                "REST index must load in a real browser", True, phase, 200, rest_status)
    store.check("browser_admin_ajax_wp_layer", ajax.get("reached_wp_layer") is True, PRODUCT,
                "admin-ajax answered by WordPress with body '0' is a correct response", True,
                phase, observed=ajax)
    store.check("browser_no_uncaught_page_errors", not page_errors, PRODUCT, page_errors[:10],
                True, phase, [], page_errors[:10])
    for name, findings, detail in (
        ("browser_console_errors", console_errors,
         "console errors recorded; never fails the subject on its own"),
        ("browser_failed_important_requests", failed_important,
         "same-origin document/script/stylesheet/xhr/fetch requests failed"),
    ):
        if findings:
            store.record(name, FAIL, PRODUCT, detail, False, phase, observed=findings[:20])
    if failed_other:
        store.record("browser_failed_external_requests", INFO, PRODUCT,
                     "external-service requests failed; expected on a network-restricted "
                     "runner and never turned into a product failure", phase=phase,
                     observed=failed_other[:20])
    if notices:
        store.record("admin_notices_recorded", INFO, PRODUCT,
                     "setup wizards / HTTPS recommendations / license and enrollment prompts "
                     "recorded only - not bypassed, not answered, no setting weakened",
                     phase=phase, observed=notices[:20])

    store.save()
    print(f"[probe:browser] console_errors={len(console_errors)} page_errors={len(page_errors)}")
    return 0


# --------------------------------------------------------------------------- #
# subcommand: finalize
# --------------------------------------------------------------------------- #
def classify(check: Dict[str, Any]) -> str:
    """Map a material failure to the frozen failure taxonomy.

    CPMS is never installed here, so category A (regression from current CPMS
    work) and B (pre-existing CPMS defect) are unreachable by construction and
    are never emitted.  Category E does not exist and is never invented.
    """
    if check["klass"] == ENVIRONMENT:
        return "C (infrastructure/environment)"
    if check["klass"] == HARNESS:
        return "D (test/test-infrastructure/fixture defect)"
    if check["klass"] == PRODUCT:
        return THIRD_PARTY_BASELINE
    return "unclassified"


def cmd_finalize(args: argparse.Namespace) -> int:
    store = Store(args.store)
    facts = store.data["facts"]
    subject = store.data["subject"]
    subject.setdefault("id", args.subject_id)

    if not args.not_run and facts.get("phase_post_executed") is not True:
        # A job that never reached the post-activation probes produced no
        # compatibility evidence. Record that gap loudly instead of reporting a
        # subject as passing on evidence it never collected.
        store.record("post_activation_probes_executed", FAIL, HARNESS,
                     "the post-activation probe phase never ran, so no compatibility "
                     "evidence exists for this subject in this environment",
                     True, "post", "post probes executed", "post probes absent")

    checks = store.data["checks"]
    counts: Dict[str, int] = {}
    for check in checks:
        counts[check["status"]] = counts.get(check["status"], 0) + 1

    if args.not_run:
        state, reason = NOT_RUN, args.not_run
    else:
        material_failures = [c for c in checks if c["material"] and c["status"] == FAIL]
        soft = [c for c in checks if c["status"] in (UNAVAILABLE, UNEXECUTED)
                or (c["status"] == FAIL and not c["material"])]
        if material_failures:
            state, reason = FAIL, ""
        elif soft:
            state = "PARTIAL"
            reason = "; ".join(sorted({f"{c['name']}={c['status']}" for c in soft}))[:600]
        else:
            state, reason = PASS, ""

    taxonomy = sorted({classify(c) for c in checks if c["status"] == FAIL and c["material"]})
    forbidden = [code for code in taxonomy if code[:2] in ("A ", "B ", "E ")]
    if forbidden:  # pragma: no cover - guarded invariant
        raise SystemExit(f"refusing to emit forbidden taxonomy category: {forbidden}")

    pre_tables, post_tables = facts.get("table_count_pre"), facts.get("table_count_post")
    env = os.environ
    run_url = (f"{env.get('GITHUB_SERVER_URL', '')}/{env.get('GITHUB_REPOSITORY', '')}"
               f"/actions/runs/{env.get('GITHUB_RUN_ID', '')}")
    result = {
        "schema": "cpms-phase18-third-party-baseline/v1",
        "subject": subject,
        "state": state,
        "reason": reason,
        "counts": counts,
        "taxonomy": taxonomy,
        "table_counts": {
            "before_activation": pre_tables,
            "after_activation": post_tables,
            "delta": (post_tables - pre_tables)
            if isinstance(pre_tables, int) and isinstance(post_tables, int) else None,
        },
        "cpms_absence": {"before": facts.get("cpms_absence_pre"),
                         "after": facts.get("cpms_absence_post")},
        "environment": {
            "lane": subject.get("lane", args.lane),
            "wordpress": facts.get("wp_core_version_post", facts.get("wp_core_version_pre")),
            "php_web": facts.get("web_php_version_post", facts.get("web_php_version_pre")),
            "php_cli": facts.get("cli_php_version_post", facts.get("cli_php_version_pre")),
            "php_sapi": facts.get("web_php_sapi_post", facts.get("web_php_sapi_pre")),
            "server_software": facts.get("server_software_post",
                                         facts.get("server_software_pre")),
            "mysql": facts.get("mysql_version_post", facts.get("mysql_version_pre")),
            "timezone": facts.get("timezone_post", facts.get("timezone_pre")),
            "locale": facts.get("locale_post", facts.get("locale_pre")),
        },
        "checks": checks,
        "facts": facts,
        # Deterministic follow-up rerun evidence. Any material FAIL must be
        # repeated once in a fresh environment before causal attribution; a
        # workflow_dispatch run of this workflow gives exactly that, because one
        # subject is one job with a fresh runner, container and WordPress.
        "reproduction": {
            "command": "gh workflow run third-party-baseline.yml",
            "note": "one subject == one job == fresh runner + fresh MySQL + fresh WordPress",
            "pinned_environment": {
                "wordpress": args.expect_wp_version, "php": args.expect_php,
                "mysql_image": args.mysql_image, "server": args.server,
            },
            "subject": args.subject_id, "dependencies": args.deps,
        },
        "github": {
            "run_id": env.get("GITHUB_RUN_ID", ""),
            "run_attempt": env.get("GITHUB_RUN_ATTEMPT", ""),
            "job": env.get("GITHUB_JOB", ""),
            "head_sha": env.get("GITHUB_SHA", ""),
            "ref": env.get("GITHUB_REF", ""),
            "run_url": run_url,
        },
    }

    os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
    with open(args.out, "w", encoding="utf-8") as handle:
        json.dump(result, handle, indent=2)
        handle.write("\n")
    store.save()

    print(f"[probe:finalize] {args.subject_id} -> {state}")
    if reason:
        print(f"[probe:finalize] reason: {reason}")
    for code in taxonomy:
        print(f"[probe:finalize] taxonomy: {code}")
    return 0


# --------------------------------------------------------------------------- #
def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Phase 18 third-party compatibility baseline evidence probes.")
    sub = parser.add_subparsers(dest="command", required=True)

    subject_p = sub.add_parser("subject", help="record the frozen subject identity")
    subject_p.add_argument("--store", required=True)
    subject_p.add_argument("--id", required=True)
    subject_p.add_argument("--name", required=True)
    subject_p.add_argument("--slug", required=True)
    subject_p.add_argument("--version", required=True)
    subject_p.add_argument("--source", required=True)
    subject_p.add_argument("--kind", required=True)
    subject_p.add_argument("--deps", default="-")
    subject_p.add_argument("--lane", default=os.environ.get("LANE", "A"))
    subject_p.add_argument("--unavailable-feature", default="")
    subject_p.add_argument("--unavailable-reason", default="")
    subject_p.set_defaults(func=cmd_subject)

    wp_p = sub.add_parser("wp", help="WordPress / HTTP / database probes")
    wp_p.add_argument("--store", required=True)
    wp_p.add_argument("--phase", required=True, choices=["pre", "post"])
    wp_p.add_argument("--wp-dir", required=True)
    wp_p.add_argument("--wp-url", required=True)
    wp_p.add_argument("--probe-url", required=True)
    wp_p.add_argument("--expect-wp-version", required=True)
    wp_p.add_argument("--expect-php", required=True)
    wp_p.set_defaults(func=cmd_wp)

    install_p = sub.add_parser("install", help="retrieve, verify and activate exact pins")
    install_p.add_argument("--store", required=True)
    install_p.add_argument("--wp-dir", required=True)
    install_p.add_argument("--package-dir", required=True)
    install_p.add_argument("--subject-slug", required=True)
    install_p.add_argument("--subject-version", required=True)
    install_p.add_argument("--deps", default="-")
    install_p.set_defaults(func=cmd_install)

    browser_p = sub.add_parser("browser", help="real-browser probes")
    browser_p.add_argument("--store", required=True)
    browser_p.add_argument("--phase", required=True)
    browser_p.add_argument("--wp-url", required=True)
    browser_p.add_argument("--out-dir", required=True)
    browser_p.set_defaults(func=cmd_browser)

    final_p = sub.add_parser("finalize", help="derive the machine-readable subject result")
    final_p.add_argument("--store", required=True)
    final_p.add_argument("--out", required=True)
    final_p.add_argument("--subject-id", required=True)
    final_p.add_argument("--not-run", default="")
    final_p.add_argument("--deps", default="-")
    final_p.add_argument("--lane", default=os.environ.get("LANE", "A"))
    final_p.add_argument("--expect-wp-version", default=os.environ.get("WP_VERSION", ""))
    final_p.add_argument("--expect-php", default=os.environ.get("PHP_VERSION", ""))
    final_p.add_argument("--mysql-image", default="mysql:8")
    final_p.add_argument("--server", default="apache2-mod-php")
    final_p.set_defaults(func=cmd_finalize)

    return parser


def main() -> int:
    args = build_parser().parse_args()
    return int(args.func(args))


if __name__ == "__main__":
    sys.exit(main())
