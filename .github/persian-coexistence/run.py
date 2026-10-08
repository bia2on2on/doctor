#!/usr/bin/env python3
"""Bounded orchestration, not a second acceptance/browser implementation."""
import argparse
from datetime import datetime, timezone
import importlib.util
import json
import os
from pathlib import Path
import pwd
import grp
import re
import stat
import subprocess
import sys
import traceback
import urllib.error
import urllib.parse
import urllib.request
import uuid

SPEC = importlib.util.spec_from_file_location(
    "baseline", Path(__file__).resolve().parents[1] / "third-party-baseline/probe.py")
probe = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(probe)

# Selectable pinned groups. The reusable workflow passes COEX_GROUP; the default
# is the original accepted five-plugin group, so that run is unchanged. Each group
# has its own evidence root and artifact, and its own subject (installed last).
GROUPS = {
    "persian-five": {
        "pins": {
            "loco-translate": "2.8.9",
            "wp-parsidate": "6.4",
            "elementor": "4.3.4",
            "persian-elementor": "2.8.4",
            "woocommerce": "11.2.0",
        },
        "subject": "woocommerce",
        "root": "coexistence",
        "artifact": "persian-coexistence",
        "title": "Persian five-plugin coexistence",
        "not_run": {},
    },
    "security-authentication": {
        "pins": {
            "wordfence": "9.0.2",
            "really-simple-ssl": "9.8.3",
        },
        "subject": "wordfence",
        "root": "coexistence-security-authentication",
        "artifact": "persian-coexistence-security-authentication",
        "title": "Persian security/authentication group (wordfence + really-simple-ssl)",
        "not_run": {
            "security plugin hardening": "NOT RUN — Wordfence firewall/2FA and Really Simple SSL HTTPS enforcement are not configured or exercised; default settings only, never weakened",
        },
    },
    # One combined lane: all ten approved plugins installed into the same clean
    # WordPress and activated together (cmd_install installs every pin first and
    # only then activates the whole set; nothing is deactivated along the way).
    # Yoast SEO and Rank Math both ship competing SEO stacks and stay at their
    # own defaults: neither is configured to disable or defer to the other, so a
    # material clash is measured, never tuned away.
    "combined-ten": {
        "pins": {
            "wordpress-seo": "28.6",
            "seo-by-rank-math": "1.0.280",
            "litespeed-cache": "7.9.1",
            "autoptimize": "3.1.16",
            "user-role-editor": "4.66.2",
            "advanced-custom-fields": "6.8.10",
            "wp-crontrol": "1.21.2",
            "redirection": "5.10.1",
            "polylang": "3.8.10",
            "contact-form-7": "6.2",
        },
        "subject": "contact-form-7",
        "root": "coexistence-combined-ten",
        "artifact": "persian-coexistence-combined-ten",
        "title": "Persian combined ten-plugin coexistence (approved combined group)",
        "not_run": {
            "competing SEO plugin configuration": "NOT RUN — wordpress-seo 28.6 and seo-by-rank-math 1.0.280 both keep default settings; neither is disabled, unhooked or configured to defer to the other, so their raw coexistence is what is measured",
            "authored third-party configuration": "NOT RUN — no redirect rules, Polylang languages/strings, ACF field groups, User Role Editor role or capability edits and no WP Crontrol cron edits are authored; default activation behaviour only, never weakened",
            "Contact Form 7 submission handling": "NOT RUN — no form submission is posted and no mail path exists in the isolated fixture; only default activation, bootstrap and front/admin serving are measured",
        },
        "unavailable": {
            "LiteSpeed Cache server-level page cache": "this lane serves Apache, and that cache requires a LiteSpeed web server, so it can be neither enabled nor benchmarked here. Any such runtime finding is preserved as recorded evidence; no web server, plugin setting or product behaviour is changed to hide it",
            "outbound mail/MTA": "the isolated runner has no MTA/SMTP, so no plugin mail delivery (including Contact Form 7) is observable",
        },
    },
    # CPMS-free activation/permission diagnosis. This is an independent clean
    # WordPress runner and is deliberately never eligible for Stage B.
    "persian-woocommerce-diagnosis": {
        "pins": {
            "woocommerce": "11.2.0",
            "persian-woocommerce": "10.0.5",
        },
        "subject": "persian-woocommerce",
        "root": "coexistence-persian-woocommerce-diagnosis",
        "artifact": "persian-coexistence-persian-woocommerce-diagnosis",
        "title": "Persian WooCommerce activation and permission diagnosis (CPMS absent)",
        "stage_b_enabled": False,
        "diagnostic": "persian-woocommerce-permissions",
        "not_run": {
            "CPMS coexistence": "NOT RUN — CPMS is absent by design in this diagnostic; no CPMS compatibility claim is possible",
        },
    },
    "persian-woocommerce-sms": {
        "pins": {
            "woocommerce": "11.2.0",
            "persian-woocommerce-sms": "7.2.3",
        },
        "subject": "persian-woocommerce-sms",
        "root": "coexistence-persian-woocommerce-sms",
        "artifact": "persian-coexistence-persian-woocommerce-sms",
        "title": "Persian WooCommerce SMS + WooCommerce + CPMS coexistence",
        "not_run": {
            "real SMS delivery": "NOT RUN — no live provider is configured and no successful provider delivery evidence exists; activation is not delivery evidence",
        },
    },
    # Exact 19 free subjects from the authoritative third-party-baseline matrix.
    # WooCommerce precedes its Persian add-ons during activation; all packages
    # are still installed before any plugin is activated, and CF7 remains last.
    "combined-nineteen": {
        "pins": {
            "woocommerce": "11.2.0",
            "persian-woocommerce": "10.0.5",
            "persian-woocommerce-sms": "7.2.3",
            "litespeed-cache": "7.9.1",
            "wordpress-seo": "28.6",
            "seo-by-rank-math": "1.0.280",
            "elementor": "4.3.4",
            "persian-elementor": "2.8.4",
            "wp-parsidate": "6.4",
            "wordfence": "9.0.2",
            "really-simple-ssl": "9.8.3",
            "redirection": "5.10.1",
            "polylang": "3.8.10",
            "user-role-editor": "4.66.2",
            "autoptimize": "3.1.16",
            "advanced-custom-fields": "6.8.10",
            "wp-crontrol": "1.21.2",
            "loco-translate": "2.8.9",
            "contact-form-7": "6.2",
        },
        "subject": "contact-form-7",
        "root": "coexistence-combined-nineteen",
        "artifact": "persian-coexistence-combined-nineteen",
        "title": "Persian final all-19-plugin combined compatibility scenario",
        "not_run": {
            "real SMS delivery": "NOT RUN — no live provider is configured and no successful provider delivery evidence exists; activation is not delivery evidence",
            "authored third-party configuration": "NOT RUN — plugin-specific forms, rules, custom fields, translations, roles, cron actions, security services and cache settings are not authored or weakened; default activation behaviour only",
            "Contact Form 7 submission/email delivery": "NOT RUN — no submission is posted and the isolated runner has no MTA/SMTP",
        },
        "unavailable": {
            "LiteSpeed Cache server-level page cache": "this lane serves Apache, and LSCache server-level page caching requires a LiteSpeed web server; the plugin remains installed and active, and no setting or web server is changed to hide this feature limitation",
        },
    },
}
GROUP = os.environ.get("COEX_GROUP", "persian-five")
if GROUP not in GROUPS:
    raise SystemExit(f"unknown COEX_GROUP {GROUP!r}; expected one of {sorted(GROUPS)}")
PINS = GROUPS[GROUP]["pins"]
SUBJECT = GROUPS[GROUP]["subject"]
FATAL = re.compile(r"PHP (?:Fatal error|Parse error|Error:)|Uncaught|There has been a critical error")
REQUIRED_ACCEPTANCE = (
    "admin.cpms-system.health_rendered", "secretary.patients.create_success",
    "secretary.patients.search_results", "public-booking.http200",
    "public-booking.a1_http200", "public-booking.a1_reached_product_route",
    "public-booking.a4_http200", "public-booking.rtl",
    "doctor.coexistence.doctor_page.served_on_cpms_admin_screen",
    "doctor.coexistence.doctor_patients_page.served_on_cpms_admin_screen",
    "secretary.coexistence.queue_page.served_on_cpms_admin_screen",
    "secretary.coexistence.patients_page.served_on_cpms_admin_screen",
    "manager.coexistence.system_page.served_on_cpms_admin_screen",
    "accountant.coexistence.finance_page.served_on_cpms_admin_screen",
    "doctor.doctor-denied-woo-admin.not_granted",
    "secretary.secretary-denied-woo-admin.not_granted",
    "manager.manager-denied-woo-admin.not_granted",
    "accountant.accountant-denied-woo-admin.not_granted",
    "doctor.doctor-denied-core-posts.not_granted",
    "secretary.secretary-denied-core-settings.not_granted",
    "doctor.doctor-denied-spoofed-page.not_granted",
    "browser.no_console_errors", "browser.no_page_errors",
)
NOT_RUN = {
    "Clinic A/B isolation": "NOT RUN — reused fixture has only one Clinic; persona isolation is not tenancy proof",
    "synthetic broken migration": "NOT RUN — bounded slice verifies real activation/schema only",
    "standalone shell/theme switching": "NOT RUN — separate existing control, outside this group",
    "Location timezone override/isolation matrix": "NOT RUN — existing booking fixture reads trusted Location timezone; site locale/timezone is not authority",
}
NOT_RUN.update(GROUPS[GROUP]["not_run"])
# Structurally unavailable features of the frozen lane. These are declared scope
# limits, not measured results: an actual runtime FEATURE UNAVAILABLE finding
# stays visible in the evidence and is never worked around by reconfiguration.
UNAVAILABLE = GROUPS[GROUP].get("unavailable", {})
STAGE_B_ENABLED = (GROUPS[GROUP].get("stage_b_enabled", True)
                   and os.environ.get("COEX_STAGE_B", "true").lower() in {"1", "true", "yes"})

DIAGNOSTIC_REDIRECT_CODES = frozenset({301, 302, 303, 307, 308})
PHP_WARNING_PATTERN = re.compile(r"\b(?:PHP )?(?:Warning|Notice|Deprecated|User Warning)\b", re.IGNORECASE)
PHP_FATAL_PATTERN = re.compile(
    r"(?:PHP )?(?:Fatal error|Parse error)|PHP Error:|Uncaught|There has been a critical error",
    re.IGNORECASE,
)


def _name_for_uid(uid):
    try:
        return pwd.getpwuid(int(uid)).pw_name
    except (KeyError, TypeError, ValueError):
        return str(uid)


def _name_for_gid(gid):
    try:
        return grp.getgrgid(int(gid)).gr_name
    except (KeyError, TypeError, ValueError):
        return str(gid)


def filesystem_metadata(path):
    """Return bounded lstat ownership/mode evidence without following symlinks."""
    try:
        info = os.lstat(path)
    except FileNotFoundError:
        return {"exists": False}
    except OSError as exc:
        return {"exists": None, "error": f"{type(exc).__name__}: {exc}"[:300]}
    mode = stat.S_IMODE(info.st_mode)
    return {
        "exists": True,
        "uid": info.st_uid,
        "owner": _name_for_uid(info.st_uid),
        "gid": info.st_gid,
        "group": _name_for_gid(info.st_gid),
        "mode_octal": format(mode, "04o"),
        "mode_symbolic": stat.filemode(info.st_mode),
        "is_symlink": stat.S_ISLNK(info.st_mode),
        "is_directory": stat.S_ISDIR(info.st_mode),
        "is_regular_file": stat.S_ISREG(info.st_mode),
        "size_bytes": info.st_size,
        "inode": info.st_ino,
        "mtime_ns": info.st_mtime_ns,
    }


class _NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    """Expose one HTTP response at a time for sentinel snapshots between hops."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ANN001
        return None


def diagnostic_http_once(url, timeout=20):
    """One GET without automatic redirect following; no response body is retained."""
    request = urllib.request.Request(url, headers={"User-Agent": probe.USER_AGENT})
    opener = urllib.request.build_opener(_NoRedirectHandler())
    result = {"request_url": url, "status": 0, "location": "", "content_type": "",
              "transport_error": ""}
    try:
        with opener.open(request, timeout=timeout) as response:
            result.update({"status": int(response.status),
                           "location": response.headers.get("Location", ""),
                           "content_type": response.headers.get("Content-Type", "")})
    except urllib.error.HTTPError as exc:
        result.update({"status": int(exc.code),
                       "location": exc.headers.get("Location", "") if exc.headers else "",
                       "content_type": exc.headers.get("Content-Type", "") if exc.headers else ""})
    except Exception as exc:  # noqa: BLE001 - transport findings stay evidence
        result["transport_error"] = f"{type(exc).__name__}: {exc}"[:400]
    return result


def diagnostic_navigation(start_url, first_response=None, max_redirects=10, after_response=None):
    """Follow same-origin GET redirects manually with an explicit hop ceiling."""
    first = first_response or diagnostic_http_once(start_url)
    if first_response is None and after_response is not None:
        after_response(0, first)
    origin = urllib.parse.urlsplit(start_url)
    seen = {start_url}
    current = first
    responses = [first]
    hops = []
    looped = False
    stopped_reason = "terminal response"
    redirect_count = 0

    while current["status"] in DIAGNOSTIC_REDIRECT_CODES and current["location"]:
        target = urllib.parse.urljoin(current["request_url"], current["location"])
        parsed_target = urllib.parse.urlsplit(target)
        hop = {"from": current["request_url"], "status": current["status"],
               "location": current["location"], "to": target, "followed": False}
        if (parsed_target.scheme, parsed_target.netloc) != (origin.scheme, origin.netloc):
            hop["stop_reason"] = "external redirect not followed"
            hops.append(hop)
            stopped_reason = hop["stop_reason"]
            break
        if target in seen:
            hop["loop"] = True
            hops.append(hop)
            looped = True
            stopped_reason = "redirect target revisited"
            break
        if redirect_count >= max_redirects:
            hop["stop_reason"] = "redirect ceiling reached"
            hops.append(hop)
            looped = True
            stopped_reason = hop["stop_reason"]
            break
        seen.add(target)
        redirect_count += 1
        hop["followed"] = True
        hops.append(hop)
        current = diagnostic_http_once(target)
        responses.append(current)
        if after_response is not None:
            after_response(len(responses) - 1, current)

    if current["status"] in DIAGNOSTIC_REDIRECT_CODES and current["location"] and not looped:
        # A final response still pointing onward after the hop ceiling is a loop
        # or an overlong chain, even when its next target was not requested.
        if redirect_count >= max_redirects:
            looped = True
            stopped_reason = "redirect ceiling reached"

    return {
        "start_url": start_url,
        "initial_status": first["status"],
        "final_status": current["status"],
        "redirect_count": len(hops),
        "redirect_chain": hops[: max_redirects + 1],
        "looped_or_bounded": looped,
        "stopped_reason": stopped_reason,
        "transport_errors": [r["transport_error"] for r in responses if r["transport_error"]],
        "responses": responses[: max_redirects + 1],
    }


def php_diagnostic_summary(text):
    lines = text.splitlines()
    warnings = [line[:1000] for line in lines if PHP_WARNING_PATTERN.search(line)]
    fatals = [line[:1000] for line in lines if PHP_FATAL_PATTERN.search(line)]
    return {"warning_count": len(warnings), "fatal_count": len(fatals),
            "warning_samples": warnings[:12], "fatal_samples": fatals[:12]}


# Only these public option values are emitted; no credentials, config or DB dump.
TIMEZONE_EVAL = """
echo 'CPMS_TIMEZONE:' . wp_json_encode([
    'timezone_string' => get_option('timezone_string'),
    'effective_gmt_offset' => get_option('gmt_offset'),
    'php_derived_offset' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->getOffset() / 3600,
]);
"""


def save(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")


def load(path, default=None):
    return json.loads(path.read_text()) if path.exists() else default


def blockers(checks):
    # Probe commands deliberately return zero after collecting findings. Their
    # exit status is NOT the Stage A gate. Even non-material UNEXECUTED blocks.
    return [c for c in checks if c["status"] == probe.UNEXECUTED
            or (c["material"] and c["status"] != probe.PASS)]


def verdict(store, complete):
    checks = store.data["checks"]
    failed = blockers(checks)
    return {
        "status": "PASS" if complete and checks and not failed else "FAIL",
        "complete": complete,
        "first_causal_check": failed[0] if failed else None,
        "blocking_checks": failed,
        "warnings": [c for c in checks if c["status"] == probe.FAIL and not c["material"]],
    }


_SAFE_CHECK_NAME = re.compile(r"[A-Za-z0-9_.:/-]{1,160}")
_SAFE_VERSION = re.compile(r"[0-9]{1,4}(?:[.][0-9A-Za-z-]{1,20}){1,3}")
_SAFE_LOCALE = re.compile(r"[a-z]{2,3}_[A-Z]{2}")
_SAFE_PLUGIN_FILE = re.compile(r"[A-Za-z0-9_.-]{1,100}[.]php")
_STAGE_A_ANNOTATION_LIMIT = 1400
_STAGE_A_ANNOTATION_BLOCKERS = 8
_STAGE_A_SUMMARY_BLOCKERS = 32
_STAGE_A_CHECK_NAME_LIMIT = 64
_STAGE_A_SUMMARY_LIMIT = 6000


def _is_stage_a_blocker(check):
    if not isinstance(check, dict):
        return False
    return (check.get("status") == probe.UNEXECUTED
            or (bool(check.get("material")) and check.get("status") != probe.PASS))


def _visible_check_name(check):
    name = check.get("name") if isinstance(check, dict) else None
    if not isinstance(name, str) or not _SAFE_CHECK_NAME.fullmatch(name):
        return "[check-name omitted]"
    if len(name) > _STAGE_A_CHECK_NAME_LIMIT:
        return name[:_STAGE_A_CHECK_NAME_LIMIT - 3] + "..."
    return name


def _safe_version_list(value):
    values = value if isinstance(value, (list, tuple)) else [value]
    if not values or len(values) > 4:
        return None
    if any(not isinstance(item, str) or not _SAFE_VERSION.fullmatch(item) for item in values):
        return None
    return list(values)


def _safe_plugin_names(value, paths=False):
    if not isinstance(value, (list, tuple)) or len(value) > len(PINS) + 1:
        return None
    allowed = set(PINS) | {"clinic-practice-management"}
    result = []
    for item in value:
        if not isinstance(item, str):
            return None
        if paths:
            pieces = item.split("/", 1)
            if (len(pieces) != 2 or pieces[0] not in allowed
                    or not _SAFE_PLUGIN_FILE.fullmatch(pieces[1])):
                return None
            result.append(pieces[0])
        else:
            if item not in allowed:
                return None
            result.append(item)
    return result


def _safe_expected_observed(check):
    """Format only allowlisted, non-sensitive identity/version/count values.

    Never inspect or emit ``detail``: it can contain raw command output, URLs,
    logs, credentials, or user-provided content. Unknown schemas are omitted.
    """
    name = check.get("name", "")
    expected, observed = check.get("expected"), check.get("observed")

    if name == "exact_active_group":
        expected_names = _safe_plugin_names(expected)
        observed_names = _safe_plugin_names(observed, paths=True)
        if expected_names is not None and observed_names is not None:
            missing = sorted(set(expected_names) - set(observed_names))
            unexpected = sorted(set(observed_names) - set(expected_names))
            missing_text = ",".join(missing) if missing else "none"
            unexpected_text = ",".join(unexpected) if unexpected else "none"
            return (f"expected_active={len(expected_names)} pinned slugs; "
                    f"observed_active={len(observed_names)} paths; "
                    f"missing={missing_text}; unexpected={unexpected_text}")

    version_check = re.fullmatch(r"unchanged_version::([a-z0-9-]+)", name)
    if version_check and version_check.group(1) in PINS:
        expected_versions = _safe_version_list(expected)
        observed_versions = _safe_version_list(observed)
        if expected_versions is not None and observed_versions is not None:
            return (f"expected={','.join(expected_versions)}; "
                    f"observed={','.join(observed_versions)}")

    if name in {"mysql_full_version_exact", "wp_full_version_exact"}:
        expected_versions = _safe_version_list(expected)
        observed_versions = _safe_version_list(observed)
        if expected_versions is not None and observed_versions is not None:
            return (f"expected={','.join(expected_versions)}; "
                    f"observed={','.join(observed_versions)}")

    if name == "locale_exact" and expected == "fa_IR":
        if isinstance(observed, str) and _SAFE_LOCALE.fullmatch(observed):
            return f"expected={expected}; observed={observed}"

    if (name.startswith("no_php_fatal::")
            or name.startswith("activation_output_no_php_fatal::")):
        if (isinstance(expected, int) and not isinstance(expected, bool)
                and isinstance(observed, int) and not isinstance(observed, bool)
                and 0 <= expected <= 100000 and 0 <= observed <= 100000):
            return f"expected_count={expected}; observed_count={observed}"

    return None


def stage_a_failure_visibility(stage_a, stage_b_status):
    """Build bounded, sanitized visibility for the all-19 Stage A block only."""
    if GROUP != "combined-nineteen" or not isinstance(stage_a, dict):
        return None
    if stage_a.get("status") == "PASS" and stage_a.get("complete") is True:
        return None

    stage_status = stage_a.get("status")
    if stage_status not in {"PASS", "FAIL", "NOT RUN"}:
        stage_status = "UNKNOWN"
    if stage_b_status not in {"PASS", "FAIL", "NOT RUN"}:
        stage_b_status = "UNKNOWN"

    raw_blockers = stage_a.get("blocking_checks", [])
    ordered = [check for check in raw_blockers if _is_stage_a_blocker(check)] \
        if isinstance(raw_blockers, list) else []
    first = stage_a.get("first_causal_check")
    if not _is_stage_a_blocker(first):
        first = ordered[0] if ordered else None

    first_name = _visible_check_name(first) if first else "not recorded"
    first_status = first.get("status") if isinstance(first, dict) else ""
    if first_status not in {probe.FAIL, probe.UNEXECUTED}:
        first_status = "UNKNOWN"
    first_material = "material" if first and bool(first.get("material")) else "non-material"
    first_text = f"{first_name} [{first_status}; {first_material}]" if first else first_name

    def blocker_text(check):
        name = _visible_check_name(check)
        status = check.get("status")
        if status == probe.UNEXECUTED:
            label = "UNEXECUTED (blocks)"
        elif status == probe.FAIL and bool(check.get("material")):
            label = "MATERIAL FAIL"
        else:
            label = "BLOCKED"
        return f"{name} [{label}]"

    annotation_items = [blocker_text(check) for check in ordered[:_STAGE_A_ANNOTATION_BLOCKERS]]
    annotation_list = ", ".join(annotation_items) if annotation_items else "none recorded"
    omitted_annotation = max(0, len(ordered) - len(annotation_items))
    if omitted_annotation:
        annotation_list += f", +{omitted_annotation} more in A/result.json"

    safe_detail = _safe_expected_observed(first) if first else None
    annotation = (f"Stage A block | group={GROUP} | Stage A={stage_status} | "
                  f"Stage B={stage_b_status} | first_causal_check={first_text} | "
                  f"ordered_blockers({len(ordered)})={annotation_list}")
    if safe_detail:
        annotation += f" | safe expected/observed: {safe_detail}"
    if len(annotation) > _STAGE_A_ANNOTATION_LIMIT:
        annotation = annotation[:_STAGE_A_ANNOTATION_LIMIT - 3] + "..."

    lines = [
        "### Stage A blocker visibility",
        f"- Group: `{GROUP}`",
        f"- Stage A: **{stage_status}**",
        f"- First causal check recorded: `{first_text}`",
        f"- Ordered material FAIL / UNEXECUTED blockers ({len(ordered)}):",
    ]
    if ordered:
        for index, check in enumerate(ordered[:_STAGE_A_SUMMARY_BLOCKERS], 1):
            lines.append(f"  {index}. `{blocker_text(check)}`")
        remaining = max(0, len(ordered) - _STAGE_A_SUMMARY_BLOCKERS)
        if remaining:
            lines.append(f"  - {remaining} additional blockers omitted here; full ordered list remains in `A/result.json`.")
    else:
        lines.append("  - No check-level blocker was recorded; Stage A did not complete successfully.")
    if safe_detail:
        lines.append(f"- Safe expected/observed values for the first blocker: `{safe_detail}`")
    else:
        lines.append("- Expected/observed details omitted: no allowlisted safe scalar values were available.")
    lines.append(f"- Stage B: **{stage_b_status}**")
    markdown = "\n".join(lines)
    if len(markdown) > _STAGE_A_SUMMARY_LIMIT:
        markdown = markdown[:_STAGE_A_SUMMARY_LIMIT - 70] + "\n- Summary bounded; full evidence remains in `A/result.json`."

    return {"annotation": annotation, "markdown": markdown}


class Slice:
    def __init__(self, root, wp_dir, url, db_name):
        self.root = Path(root)
        self.wp_dir, self.url, self.db_name = wp_dir, url, db_name
        self.root.mkdir(parents=True, exist_ok=True)
        self._diagnostic = None
        self._diagnostic_log_previous = {}
        if GROUPS[GROUP].get("diagnostic") == "persian-woocommerce-permissions":
            self._diagnostic = {
                "schema": "cpms-persian-woocommerce-permission-diagnosis/v1",
                "canonical_environment": "WP 7.1.3 / PHP 8.3 / MySQL 8.4.11 / fa_IR; CPMS absent",
                "pins": PINS,
                "wp_cli_identity": {},
                "web_server_identity": {},
                "activation": {},
                "sentinel_timeline": [],
                "php_log_phases": [],
                "http_navigations": [],
                "assessment": {
                    "failure_category": None,
                    "reason": "No A/B/C/D category is assigned from third-party or permission evidence alone; review the preserved canonical and experiment observations.",
                },
            }
        os.environ["TPB_ADMIN_USER"] = os.environ.get("ADMIN_USER", "")
        os.environ["TPB_ADMIN_PASS"] = os.environ.get("ADMIN_PASS", "")

    def store(self, stage):
        folder = self.root / stage
        folder.mkdir(exist_ok=True)
        return probe.Store(str(folder / "evidence.json"))

    def args(self, stage):
        return argparse.Namespace(
            store=self.store(stage).path, wp_dir=self.wp_dir, wp_url=self.url,
            db_name=self.db_name, probe_url="http://localhost:8081/",
            phase="post", out_dir=str(self.root / stage), expect_dir="rtl", require_computed_rtl=True,
            expect_wp_version="7.1.3", expect_php="8.3", expect_mysql="",
            expect_mysql_major="8", expect_locale="fa_IR", expect_timezone="Asia/Tehran",
            # Unlike a plugin-free locale control, a combined group may have
            # legitimate onboarding redirects. Preserve chains as warnings;
            # final status, real authentication and redirect loops still gate.
            record_only_redirects=True)

    def init(self):
        stage_b = {"status": "NOT RUN"}
        claim = "No coexistence acceptance until both stages have complete runtime evidence"
        if not STAGE_B_ENABLED:
            if GROUPS[GROUP].get("stage_b_enabled") is False:
                stage_b["reason"] = "CPMS Stage B is prohibited for this CPMS-free diagnostic"
                claim = "Permission diagnosis only; CPMS is absent and no CPMS compatibility claim is made"
            else:
                stage_b["reason"] = "CPMS Stage B was explicitly disabled for this run"
                claim = "Stage A only; no CPMS compatibility claim is made because Stage B was disabled"
        initial = {
            "stage_a": {"status": "NOT RUN"}, "stage_b": stage_b,
            "group": GROUP, "pins": PINS, "not_run": NOT_RUN,
            "unavailable_features": UNAVAILABLE,
            "source_sha": os.environ.get("GITHUB_SHA", "local"),
            "candidate_head_sha": os.environ.get("COEX_HEAD_SHA", "local"),
            "base_sha": os.environ.get("COEX_BASE_SHA", ""),
            "environment": {"wordpress": "7.1.3", "php": "8.3", "mysql": "8.4.11", "locale": "fa_IR"},
            "claim": claim,
        }
        save(self.root / "summary.json", initial)
        if self._diagnostic is not None:
            self.save_diagnostic()

    def cli(self, store, name, args):
        result = probe.wp_cli(args, self.wp_dir)
        store.check(name, result["rc"] == 0, probe.ENVIRONMENT, result)
        store.save()
        return result["rc"] == 0

    def log_bytes(self):
        logs = {}
        for name, path in {
            "debug": Path(self.wp_dir) / "wp-content/debug.log",
            "apache": Path("/var/log/apache2/acc-error.log"),
        }.items():
            # Apache log directory is root/adm-only. Never change its permissions.
            result = probe.run(["sudo", "cat", str(path)])
            if result["rc"]:
                raise RuntimeError(f"cannot preserve {name} log: {result['stderr']}")
            logs[name] = result["stdout"].encode("utf-8")
        return logs

    def capture_logs(self, stage, boundary=False):
        store = self.store(stage)
        for name, raw in self.log_bytes().items():
            if stage == "B" and not boundary:
                before = (self.root / "B" / f"{name}-before-activation.log").read_bytes()
                unchanged = raw.startswith(before)
                store.check(f"{name}_log_prefix_preserved", unchanged, probe.HARNESS,
                            "No rotation/truncation may silently hide post-activation errors")
                # On truncation retain full observed log as evidence, but fail closed.
                raw = raw[len(before):] if unchanged else raw
            suffix = "-before-activation" if boundary else ""
            (self.root / stage / f"{name}{suffix}.log").write_bytes(raw)
            if not boundary:
                text = raw.decode("utf-8", errors="replace")
                store.check(f"{name}_no_fatal", not FATAL.search(text), probe.PRODUCT,
                            f"Full raw log: {stage}/{name}.log")
                store.fact(f"{name}_warnings", [line for line in text.splitlines()
                           if re.search(r"\b(?:Warning|Notice|Deprecated)\b", line)])
        store.save()

    def identity(self, stage):
        store = self.store(stage)
        headers = probe.read_plugin_headers(self.wp_dir)
        active_ok, active, error = probe.db_active_plugins(self.db_name)
        expected = set(PINS) | ({"clinic-practice-management"} if stage == "B" else set())
        store.check("exact_active_group", active_ok and {p.split('/')[0] for p in active} == expected,
                    probe.HARNESS, error, expected=sorted(expected), observed=active)
        for slug, version in PINS.items():
            versions = [h["version"] for h in headers if h["slug"] == slug]
            store.check(f"unchanged_version::{slug}", versions == [version], probe.HARNESS,
                        expected=version, observed=versions)
        ok, version, error = probe.scalar("SELECT VERSION()", self.db_name)
        # The legacy --expect-mysql probe compares major.minor only. Leave the
        # historical campaign untouched; this slice asserts the full patch pin.
        store.check("mysql_full_version_exact", ok and version == "8.4.11", probe.ENVIRONMENT,
                    error, expected="8.4.11", observed=version)
        runtime = probe.web_runtime_probe("http://localhost:8081/")
        cli = probe.run(["php", "-r", "echo PHP_VERSION;"])
        store.check("php_web_cli_8_3", runtime["php_version"].startswith("8.3.")
                    and cli["rc"] == 0 and cli["stdout"].startswith("8.3."), probe.ENVIRONMENT,
                    {"web": runtime, "cli": cli})
        store.check("wp_full_version_exact", probe.read_wp_core_version(self.wp_dir) == "7.1.3",
                    probe.ENVIRONMENT, expected="7.1.3",
                    observed=probe.read_wp_core_version(self.wp_dir))
        ok, locale, error = probe.db_option(self.db_name, "WPLANG")
        store.check("locale_exact", ok and locale == "fa_IR", probe.ENVIRONMENT, error,
                    expected="fa_IR", observed=locale)
        store.save()

    def browser(self, stage):
        os.environ["TPB_RAW_BROWSER_LOG"] = str(self.root / stage / "browser-events.jsonl")
        probe.cmd_browser(self.args(stage))
        store = self.store(stage)
        facts = store.data["facts"].get("browser_post", {})
        store.check("important_browser_requests_healthy", bool(facts)
                    and not facts.get("failed_important_requests"), probe.PRODUCT, facts)
        store.save()

    def observe_timezone(self, timing):
        sample = {"timing": timing, "captured_at": datetime.now(timezone.utc).isoformat()}
        errors = []
        result = probe.wp_cli(["eval", TIMEZONE_EVAL], self.wp_dir)
        # Read persisted rows after this bootstrap as well, so a write triggered
        # by the diagnostic request itself cannot hide behind an earlier SQL read.
        for option in ("timezone_string", "gmt_offset"):
            ok, value, error = probe.db_option(self.db_name, option)
            sample["raw_" + option] = value if ok else None
            if not ok:
                errors.append(f"raw {option} unavailable: {error}")
        try:
            if result["rc"] != 0:
                raise ValueError(f"WP bootstrap/eval rc={result['rc']}; see commands.jsonl")
            body = result["stdout"].split("CPMS_TIMEZONE:", 1)[1]
            observed, _ = json.JSONDecoder().raw_decode(body)
            for key in ("timezone_string", "effective_gmt_offset", "php_derived_offset"):
                sample[key] = observed[key]
        except (ValueError, IndexError, KeyError, TypeError) as exc:
            errors.append(str(exc))
        sample["errors"] = errors
        path = self.root / "A/timezone.json"
        save(path, [*load(path, []), sample])
        return sample

    def check_timezones(self):
        samples = load(self.root / "A/timezone.json", [])
        store = self.store("A")
        required = {"after_core_install", "after_locale_setup", "after_timezone_configuration",
                    "before_activation", "before_locale_probe", "after_stage_a_probes"} | {f"after_activation::{s}" for s in PINS}
        store.check("timezone_timing_complete", required <= {s["timing"] for s in samples},
                    probe.HARNESS, "Missing diagnostic evidence is not a pass")
        configured = None
        for sample in samples:
            timing = sample["timing"]
            if timing == "after_timezone_configuration":
                configured = sample
            store.check(f"timezone_snapshot_readable::{timing}", not sample["errors"],
                        probe.HARNESS, sample)
            if configured is None:
                continue  # Initial defaults / locale setup are observations, not the configured fixture.
            store.check(f"timezone_settings_stable::{timing}",
                        sample.get("raw_timezone_string") == "Asia/Tehran"
                        and sample.get("timezone_string") == "Asia/Tehran"
                        and sample.get("raw_gmt_offset") == configured.get("raw_gmt_offset"),
                        probe.PRODUCT, sample,
                        expected={"timezone_string": "Asia/Tehran", "raw_gmt_offset": configured.get("raw_gmt_offset")})
            try:
                agrees = abs(float(sample["effective_gmt_offset"]) - float(sample["php_derived_offset"])) < 0.000001
            except (KeyError, TypeError, ValueError):
                agrees = False
            store.check(f"timezone_effective_offset::{timing}", agrees, probe.ENVIRONMENT, sample)
        store.save()

    def save_diagnostic(self, target=None):
        # Experiment evidence is nested under the canonical object; always publish
        # the full tree so a later disposable run cannot replace baseline evidence.
        payload = self._diagnostic if self._diagnostic is not None else target
        if payload is not None:
            save(self.root / "A/diagnostic.json", payload)

    def sentinel_snapshot(self, wp_dir=None):
        """Read .activated marker and subject-directory metadata without mutation."""
        root = Path(wp_dir or self.wp_dir)
        plugin_dir = root / "wp-content/plugins/persian-woocommerce"
        plugins_root = root / "wp-content/plugins"
        matches = []
        if plugins_root.is_dir():
            for current, dirs, files in os.walk(plugins_root, followlinks=False):
                dirs[:] = [name for name in dirs
                           if not os.path.islink(os.path.join(current, name))]
                for name in (*files, *(d for d in dirs if d == ".activated")):
                    if name != ".activated":
                        continue
                    path = Path(current) / name
                    try:
                        relative = str(path.relative_to(root))
                    except ValueError:
                        relative = path.name
                    try:
                        subject_marker = path.is_relative_to(plugin_dir)
                    except AttributeError:  # Python < 3.9 fallback for local tools
                        subject_marker = str(path).startswith(str(plugin_dir) + os.sep)
                    matches.append({
                        "path_relative_to_wp": relative,
                        "under_persian_woocommerce": subject_marker,
                        "metadata": filesystem_metadata(path),
                    })
        matches.sort(key=lambda item: item["path_relative_to_wp"])
        return {
            "captured_at": datetime.now(timezone.utc).isoformat(),
            "plugin_directory": {
                "path_relative_to_wp": "wp-content/plugins/persian-woocommerce",
                **filesystem_metadata(plugin_dir),
            },
            "sentinel_exists": any(item["under_persian_woocommerce"] for item in matches),
            "sentinels": matches,
        }

    def read_web_server_identity(self):
        """Ask a separate Apache/mod_php endpoint for the serving process EUID."""
        response = probe.http_request("http://localhost:8081/identity.php", body_limit=1200)
        result = {"status": response["status"], "transport_error": response["transport_error"],
                  "effective_uid": None, "effective_user": "", "effective_gid": None,
                  "effective_group": "", "php_sapi": ""}
        if response["status"] == 200:
            try:
                payload = json.loads(response["body"])
                for field in ("effective_uid", "effective_user", "effective_gid",
                              "effective_group", "php_sapi"):
                    result[field] = payload.get(field)
            except (ValueError, TypeError):
                result["transport_error"] = "identity endpoint did not return valid JSON"
        return result

    def source_marker_references(self, wp_dir=None):
        """Read-only source locator for the sentinel name; the package is untouched."""
        plugin_dir = Path(wp_dir or self.wp_dir) / "wp-content/plugins/persian-woocommerce"
        references = []
        if not plugin_dir.is_dir():
            return references
        for path in sorted(plugin_dir.rglob("*.php")):
            try:
                if path.is_symlink() or path.stat().st_size > 2_000_000:
                    continue
                lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
            except OSError:
                continue
            for number, line in enumerate(lines, 1):
                if ".activated" in line:
                    references.append({
                        "file_relative_to_plugin": str(path.relative_to(plugin_dir)),
                        "line": number,
                        "source_excerpt": line.strip()[:500],
                    })
                    if len(references) >= 40:
                        return references
        return references

    def capture_diagnostic_logs(self, phase, wp_dir=None, apache_log=None,
                                previous=None, output_dir=None, target=None):
        """Save full phase snapshots and warning/fatal deltas; fail closed on rotation."""
        target = target or self._diagnostic
        root = Path(wp_dir or self.wp_dir)
        apache_log = apache_log or "/var/log/apache2/acc-error.log"
        output_dir = Path(output_dir or (self.root / "A/diagnostic/logs/canonical"))
        output_dir.mkdir(parents=True, exist_ok=True)
        previous = self._diagnostic_log_previous if previous is None else previous
        phase_name = re.sub(r"[^A-Za-z0-9_.-]+", "_", phase)
        entries = {}
        current = {}
        for name, path in (
            ("wp_debug_log", root / "wp-content/debug.log"),
            ("apache_error_log", Path(apache_log)),
        ):
            available, raw, error = False, b"", ""
            if name == "wp_debug_log":
                try:
                    raw = path.read_bytes()
                    available = True
                except OSError as exc:
                    error = f"{type(exc).__name__}: {exc}"[:300]
            else:
                result = probe.run(["sudo", "cat", str(path)], timeout=30)
                if result["rc"] == 0:
                    raw, available = result["stdout"].encode("utf-8"), True
                else:
                    error = (result["stderr"] or f"sudo cat exited {result['rc']}")[:300]
            if available:
                (output_dir / f"{phase_name}.{name}.log").write_bytes(raw)
                prior = previous.get(name)
                if prior is None:
                    delta, delta_available, prefix_preserved = raw, True, None
                    delta_basis = "first captured snapshot; includes setup preceding this phase"
                elif raw.startswith(prior):
                    delta, delta_available, prefix_preserved = raw[len(prior):], True, True
                    delta_basis = "append-only bytes since previous captured phase"
                else:
                    delta, delta_available, prefix_preserved = raw, False, False
                    delta_basis = "log rotated or truncated; full observed bytes preserved, phase delta unavailable"
                current[name] = raw
                full_findings = php_diagnostic_summary(raw.decode("utf-8", errors="replace"))
                delta_findings = (php_diagnostic_summary(delta.decode("utf-8", errors="replace"))
                                  if delta_available else None)
            else:
                delta_available, prefix_preserved, delta_basis = False, None, "log unavailable"
                full_findings, delta_findings = None, None
            entries[name] = {
                "path": str(path), "available": available,
                "bytes": len(raw) if available else None,
                "prefix_preserved": prefix_preserved,
                "delta_available": delta_available,
                "delta_basis": delta_basis,
                "full_log_findings": full_findings,
                "phase_delta_findings": delta_findings,
                "error": error,
            }
        previous.update(current)
        if target is not None:
            target.setdefault("php_log_phases", []).append({"phase": phase, "logs": entries})
            self.save_diagnostic(target)
        return entries

    def capture_diagnostic_context(self, phase, wp_dir=None, apache_log=None,
                                  previous=None, output_dir=None, target=None,
                                  response=None):
        target = target or self._diagnostic
        timeline = target.setdefault("sentinel_timeline", [])
        snapshot = self.sentinel_snapshot(wp_dir)
        record = {"phase": phase, **snapshot}
        if response is not None:
            record["http_response"] = {
                key: response.get(key) for key in
                ("request_url", "status", "location", "content_type", "transport_error")
            }
        timeline.append(record)
        self.capture_diagnostic_logs(phase, wp_dir, apache_log, previous, output_dir, target)
        return record

    def observe_diagnostic_activation(self, timing):
        if timing == "before_activation":
            euid, egid = os.geteuid(), os.getegid()
            self._diagnostic["wp_cli_identity"] = {
                "effective_uid": euid, "effective_user": _name_for_uid(euid),
                "effective_gid": egid, "effective_group": _name_for_gid(egid),
                "supplementary_groups": [
                    {"gid": gid, "group": _name_for_gid(gid)} for gid in os.getgroups()
                ],
                "execution": "WP-CLI subprocess inherits this runner identity; --allow-root does not change it",
            }
            self._diagnostic["web_server_identity"] = self.read_web_server_identity()
        # First snapshot immediately after each WP-CLI activation command, before
        # the timezone WP-CLI observation can itself bootstrap the activated plugin.
        immediate = self.capture_diagnostic_context(timing)
        if timing == "after_activation::persian-woocommerce":
            unlink = self.test_web_user_can_remove_sentinel(
                immediate, self._diagnostic.get("web_server_identity", {}))
            self._diagnostic["canonical_unlink_test"] = unlink
            if (immediate.get("sentinel_exists")
                    and unlink.get("can_remove_sentinel") is False):
                self.run_permission_layout_experiment(immediate)
            self.save_diagnostic()
        self.observe_timezone(timing)
        self.capture_diagnostic_context(f"{timing}::after_wp_cli_observation")

    def test_web_user_can_remove_sentinel(self, sentinel_snapshot, web_identity, wp_dir=None):
        """Test unlink authority with a matching disposable canary, never the marker."""
        root = Path(wp_dir or self.wp_dir)
        candidates = [item for item in sentinel_snapshot.get("sentinels", [])
                      if item.get("under_persian_woocommerce")
                      and item.get("metadata", {}).get("exists")]
        if not candidates:
            return {"attempted": False, "can_remove_sentinel": None,
                    "reason": "no .activated sentinel exists; no canary was created"}
        sentinel = candidates[0]
        metadata = sentinel["metadata"]
        web_user = web_identity.get("effective_user")
        if (not web_user or not re.fullmatch(r"[A-Za-z0-9_.-]{1,64}", str(web_user))
                or metadata.get("uid") is None or metadata.get("gid") is None
                or metadata.get("mode_octal") is None):
            return {"attempted": False, "can_remove_sentinel": None,
                    "reason": "web-server identity or sentinel metadata is unavailable"}
        sentinel_path = root / sentinel["path_relative_to_wp"]
        canary = sentinel_path.parent / f".arena-unlink-canary-{uuid.uuid4().hex}"
        before = filesystem_metadata(sentinel_path)
        mode = metadata["mode_octal"]
        create = probe.run(["sudo", "install", "-m", mode, "-o", str(metadata["uid"]),
                            "-g", str(metadata["gid"]), "/dev/null", str(canary)], timeout=30)
        if create["rc"] != 0:
            cleanup = probe.run(["sudo", "rm", "-f", "--", str(canary)], timeout=30)
            return {"attempted": False, "can_remove_sentinel": None,
                    "reason": "could not create a disposable same-parent canary",
                    "create_error": (create["stderr"] or "install failed")[:300],
                    "canary_cleanup_exit_code": cleanup["rc"],
                    "sentinel_preserved": filesystem_metadata(sentinel_path) == before}
        attempt = probe.run(["sudo", "-u", str(web_user), "rm", "--", str(canary)], timeout=30)
        cleanup = probe.run(["sudo", "rm", "-f", "--", str(canary)], timeout=30)
        after = filesystem_metadata(sentinel_path)
        return {
            "attempted": True,
            "can_remove_sentinel": attempt["rc"] == 0,
            "method": "web-server user attempted to unlink a disposable canary in the exact sentinel parent, with matching owner/group/mode; the actual .activated file was never removed",
            "web_user": web_user,
            "sentinel_relative_path": sentinel["path_relative_to_wp"],
            "canary_unlink_exit_code": attempt["rc"],
            "canary_unlink_error": (attempt["stderr"] or "")[:300],
            "canary_cleanup_exit_code": cleanup["rc"],
            "sentinel_preserved": all(
                before.get(field) == after.get(field)
                for field in ("exists", "uid", "gid", "mode_octal", "size_bytes", "inode",
                              "mtime_ns", "is_directory", "is_regular_file")
            ),
            "sentinel_before": before,
            "sentinel_after": after,
        }

    def run_navigation_sequence(self, name, wp_dir, base_url, target, previous,
                                output_dir, apache_log):
        start_url = base_url.rstrip("/") + "/"
        first = diagnostic_http_once(start_url)
        self.capture_diagnostic_context(
            f"{name}_after_first_http_request", wp_dir, apache_log, previous,
            output_dir, target, response=first)

        def after_redirect(index, response):
            self.capture_diagnostic_context(
                f"{name}_after_redirect_request_{index}", wp_dir, apache_log, previous,
                output_dir, target, response=response)

        navigation = diagnostic_navigation(start_url, first_response=first,
                                           max_redirects=probe.MAX_REDIRECTS,
                                           after_response=after_redirect)
        sequence = {"name": name, "first_response": first, "navigation": navigation}
        target.setdefault("http_navigations", []).append(sequence)
        self.save_diagnostic(target)
        return sequence

    def run_canonical_diagnostic_requests(self):
        previous = self._diagnostic_log_previous
        output = self.root / "A/diagnostic/logs/canonical"
        sequences = []
        for index in range(1, 4):
            sequences.append(self.run_navigation_sequence(
                f"canonical_request_{index}", self.wp_dir, self.url,
                self._diagnostic, previous, output, "/var/log/apache2/acc-error.log"))
        return sequences

    def merge_diagnostic_log_checks(self):
        store = self.store("A")
        for phase in self._diagnostic.get("php_log_phases", []):
            for source, log in phase["logs"].items():
                check_name = f"diagnostic_log_delta::{phase['phase']}::{source}"
                if not log["available"] or not log["delta_available"]:
                    store.unexecuted(check_name, probe.HARNESS,
                                     log.get("error") or log.get("delta_basis", "log delta unavailable"),
                                     phase["phase"])
                    continue
                findings = log["phase_delta_findings"] or {}
                fatal_count = findings.get("fatal_count", 0)
                store.check(f"no_php_fatal::{phase['phase']}::{source}", fatal_count == 0,
                            probe.PRODUCT,
                            {"fatal_count": fatal_count,
                             "samples": findings.get("fatal_samples", [])},
                            True, phase["phase"], 0, fatal_count)
                warning_count = findings.get("warning_count", 0)
                if warning_count:
                    store.record(f"php_warnings::{phase['phase']}::{source}", probe.FAIL,
                                 probe.PRODUCT,
                                 {"warning_count": warning_count,
                                  "samples": findings.get("warning_samples", [])},
                                 False, phase["phase"], 0, warning_count)
        store.save()

    def run_permission_layout_experiment(self, sentinel_snapshot):
        """Run a permission-only counterfactual in a disposable WP/DB/vhost clone."""
        experiment = {
            "status": "NOT RUN",
            "canonical_results_substituted": False,
            "permission_changes": [],
            "http_navigations": [],
            "sentinel_timeline": [],
            "php_log_phases": [],
        }
        self._diagnostic["permission_experiment"] = experiment
        sentinels = [item for item in sentinel_snapshot.get("sentinels", [])
                     if item.get("under_persian_woocommerce")
                     and item.get("metadata", {}).get("exists")]
        unlink = self._diagnostic.get("canonical_unlink_test", {})
        if not sentinels or unlink.get("can_remove_sentinel") is not False:
            experiment["reason"] = "not necessary: canonical sentinel is absent or web-server unlink permission was not disproven"
            self.save_diagnostic()
            return experiment

        identity = self._diagnostic.get("web_server_identity", {})
        web_user, web_gid = identity.get("effective_user"), identity.get("effective_gid")
        if (not web_user or not re.fullmatch(r"[A-Za-z0-9_.-]{1,64}", str(web_user))
                or type(web_gid) is not int or web_gid < 0):
            experiment.update({"status": "UNEXECUTED",
                               "reason": "web-server EUID/GID is unavailable; no permission change attempted"})
            self.save_diagnostic()
            return experiment

        canonical_wp = Path(self.wp_dir)
        clone_wp = canonical_wp.with_name(canonical_wp.name + "-permission-experiment")
        clone_db = f"{self.db_name}_pwperm"
        if len(clone_db) > 64 or not re.fullmatch(r"[A-Za-z0-9_]+", clone_db):
            experiment.update({"status": "UNEXECUTED",
                               "reason": "canonical database name cannot be safely isolated; no permission change attempted"})
            self.save_diagnostic()
            return experiment
        clone_url = "http://localhost:8082"
        apache_log = "/var/log/apache2/acc-permission-experiment-error.log"
        site_conf = "/etc/apache2/sites-available/acc-permission-experiment.conf"
        ports_conf = "/etc/apache2/ports.conf"
        output = self.root / "A/diagnostic/logs/permission-experiment"
        output.mkdir(parents=True, exist_ok=True)
        previous = {}
        original_ports = ""
        ports_conf_captured = False
        database_created = False
        clone_created = False
        vhost_created = False
        operation_error = ""
        cleanup = {}
        try:
            if clone_wp.exists():
                raise RuntimeError("refusing to overwrite an existing disposable WordPress path")
            exists, count, error = probe.scalar(
                f"SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='{clone_db}'",
                self.db_name)
            if not exists or count != "0":
                raise RuntimeError(error or "refusing to overwrite an existing disposable database")

            copy = probe.run(["sudo", "cp", "-a", str(canonical_wp), str(clone_wp)], timeout=120)
            if copy["rc"] != 0:
                raise RuntimeError(f"WordPress copy failed: {(copy['stderr'] or '')[:400]}")
            clone_created = True
            create_db = probe.run(["mysql", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-proot",
                                   "-e", f"CREATE DATABASE `{clone_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"])
            if create_db["rc"] != 0:
                raise RuntimeError(f"disposable database creation failed: {(create_db['stderr'] or '')[:400]}")
            database_created = True

            dump = subprocess.run(
                ["mysqldump", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-proot",
                 "--default-character-set=utf8mb4", "--single-transaction", self.db_name],
                capture_output=True, timeout=120, check=False,
            )
            if dump.returncode != 0:
                raise RuntimeError(f"canonical database snapshot failed: {dump.stderr.decode(errors='replace')[:400]}")
            restore = subprocess.run(
                ["mysql", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-proot",
                 "--default-character-set=utf8mb4", clone_db],
                input=dump.stdout, capture_output=True, timeout=120, check=False,
            )
            if restore.returncode != 0:
                raise RuntimeError(f"disposable database restore failed: {restore.stderr.decode(errors='replace')[:400]}")

            config = clone_wp / "wp-config.php"
            original_config = config.read_text(encoding="utf-8")
            db_name_pattern = re.compile(
                r"(?m)^define\(\s*'DB_NAME'\s*,\s*'"
                + re.escape(self.db_name) + r"'\s*\);\s*$")
            updated_config, substitutions = db_name_pattern.subn(
                f"define( 'DB_NAME', '{clone_db}' );", original_config, count=1)
            if substitutions != 1:
                raise RuntimeError("could not isolate cloned wp-config.php to the disposable database")
            config_write = subprocess.run(["sudo", "tee", str(config)], input=updated_config,
                                          text=True, capture_output=True, check=False)
            if config_write.returncode != 0:
                raise RuntimeError("could not write the cloned wp-config.php")

            site_sql = ("UPDATE wp_options SET option_value='http://localhost:8082' "
                        "WHERE option_name IN ('home','siteurl');")
            update_site = probe.run(["mysql", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-proot",
                                     clone_db, "-e", site_sql])
            if update_site["rc"] != 0:
                raise RuntimeError(f"cloned site URL update failed: {(update_site['stderr'] or '')[:400]}")

            ports_read = probe.run(["sudo", "cat", ports_conf], timeout=30)
            if ports_read["rc"] != 0:
                raise RuntimeError("could not read Apache listener configuration")
            original_ports = ports_read["stdout"]
            ports_conf_captured = True
            if not re.search(r"(?m)^\s*Listen\s+8082\s*$", original_ports):
                add_listener = subprocess.run(["sudo", "tee", "-a", ports_conf],
                                              input="\nListen 8082\n", text=True,
                                              capture_output=True, check=False)
                if add_listener.returncode != 0:
                    raise RuntimeError("could not add disposable Apache listener")
            vhost = (
                "<VirtualHost *:8082>\n"
                f"    DocumentRoot {clone_wp}\n"
                f"    <Directory {clone_wp}>\n"
                "        AllowOverride All\n"
                "        Require all granted\n"
                "    </Directory>\n"
                f"    ErrorLog {apache_log}\n"
                "    CustomLog /var/log/apache2/acc-permission-experiment-access.log combined\n"
                "</VirtualHost>\n"
            )
            write_vhost = subprocess.run(["sudo", "tee", site_conf], input=vhost, text=True,
                                         capture_output=True, check=False)
            if write_vhost.returncode != 0:
                raise RuntimeError("could not write disposable Apache virtual host")
            vhost_created = True
            enable = probe.run(["sudo", "a2ensite", "acc-permission-experiment"], timeout=30)
            if enable["rc"] != 0:
                raise RuntimeError(f"could not enable disposable Apache vhost: {(enable['stderr'] or '')[:300]}")
            probe.run(["sudo", "touch", apache_log], timeout=30)
            configtest = probe.run(["sudo", "apache2ctl", "configtest"], timeout=30)
            if configtest["rc"] != 0:
                raise RuntimeError(f"Apache rejected disposable vhost: {(configtest['stderr'] or '')[:300]}")
            reload_apache = probe.run(["sudo", "service", "apache2", "reload"], timeout=30)
            if reload_apache["rc"] != 0:
                raise RuntimeError(f"Apache reload failed: {(reload_apache['stderr'] or '')[:300]}")

            marker = sentinels[0]
            marker_parent_rel = str(Path(marker["path_relative_to_wp"]).parent)
            permission_dir = clone_wp / marker_parent_rel
            before = filesystem_metadata(permission_dir)
            old_mode = int(before.get("mode_octal", "0000"), 8)
            new_mode = old_mode | stat.S_IWGRP | stat.S_IXGRP
            chgrp = probe.run(["sudo", "chgrp", "--", str(web_gid), str(permission_dir)], timeout=30)
            chmod = probe.run(["sudo", "chmod", "--", format(new_mode, "04o"), str(permission_dir)], timeout=30)
            if chgrp["rc"] != 0 or chmod["rc"] != 0:
                raise RuntimeError("could not apply the narrowly scoped disposable directory permission change")
            after = filesystem_metadata(permission_dir)
            added_mode_bits = new_mode & ~old_mode
            added_permissions = []
            if added_mode_bits & stat.S_IWGRP:
                added_permissions.append("group-write bit added")
            if added_mode_bits & stat.S_IXGRP:
                added_permissions.append("group-execute bit added")
            experiment["permission_changes"] = [{
                "path_relative_to_cloned_wp": marker_parent_rel,
                "before": before, "after": after,
                "exact_changes": [f"group set to Apache effective GID {web_gid}",
                                  f"mode {before.get('mode_octal')} -> {after.get('mode_octal')}",
                                  *(added_permissions or ["requested group-write+execute bits were already present"])],
                "files_changed": False,
                "plugin_source_changed": False,
                "canonical_environment_changed": False,
                "application_authorization_or_roles_changed": False,
            }]
            experiment["environment"] = {
                "wordpress": "7.1.3", "php": "8.3", "mysql": "8.4.11", "locale": "fa_IR",
                "wordpress_path": str(clone_wp), "database": clone_db, "url": clone_url,
                "is_separate_disposable_clone": True,
            }
            self.capture_diagnostic_logs(
                "permission_experiment_before_http", clone_wp, apache_log, previous,
                output, experiment)
            clone_marker_state = self.sentinel_snapshot(clone_wp)
            experiment["web_unlink_test"] = self.test_web_user_can_remove_sentinel(
                clone_marker_state, identity, clone_wp)
            clone_unlink = experiment["web_unlink_test"]
            if (not clone_unlink.get("attempted")
                    or clone_unlink.get("sentinel_preserved") is not True
                    or clone_unlink.get("canary_cleanup_exit_code") != 0):
                raise RuntimeError("cloned same-parent canary/unlink measurement or cleanup did not complete")
            experiment["status"] = "COMPLETE"
            for index in range(1, 4):
                self.run_navigation_sequence(
                    f"permission_experiment_request_{index}", clone_wp, clone_url,
                    experiment, previous, output, apache_log)
            final = self.sentinel_snapshot(clone_wp)
            experiment["final_sentinel_state"] = final
            experiment["observed_redirect_loops"] = [
                item["navigation"]["looped_or_bounded"]
                for item in experiment["http_navigations"]
            ]
            experiment["comparison_note"] = (
                "This is a separate disposable permission-layout experiment. Its HTTP or sentinel result is never substituted for the canonical baseline."
            )
            self._diagnostic["permission_experiment"] = experiment
            self.save_diagnostic()
        except Exception as exc:  # noqa: BLE001 - preserve the canonical result
            operation_error = f"{type(exc).__name__}: {exc}"[:800]
            experiment["status"] = "UNEXECUTED"
            experiment["reason"] = operation_error
        finally:
            if vhost_created:
                cleanup["disable_vhost"] = probe.run(
                    ["sudo", "a2dissite", "acc-permission-experiment"], timeout=30)["rc"]
                cleanup["remove_vhost_file"] = probe.run(["sudo", "rm", "-f", "--", site_conf], timeout=30)["rc"]
            if ports_conf_captured:
                restore_ports = subprocess.run(["sudo", "tee", ports_conf], input=original_ports,
                                               text=True, capture_output=True, check=False)
                cleanup["restore_ports_conf"] = restore_ports.returncode
            if database_created:
                cleanup["drop_disposable_database"] = probe.run(
                    ["mysql", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-proot",
                     "-e", f"DROP DATABASE IF EXISTS `{clone_db}`;"])["rc"]
            if clone_created:
                cleanup["remove_disposable_wordpress"] = probe.run(
                    ["sudo", "rm", "-rf", "--", str(clone_wp)], timeout=120)["rc"]
            if vhost_created or ports_conf_captured:
                cleanup["reload_canonical_apache"] = probe.run(
                    ["sudo", "service", "apache2", "reload"], timeout=30)["rc"]
            expected_cleanup = [key for key in (
                "disable_vhost", "remove_vhost_file", "restore_ports_conf",
                "drop_disposable_database", "remove_disposable_wordpress",
                "reload_canonical_apache") if key in cleanup]
            cleanup_ok = bool(expected_cleanup) and all(cleanup[key] == 0 for key in expected_cleanup)
            cleanup["all_created_resources_removed"] = cleanup_ok
            experiment["cleanup"] = cleanup
            if experiment.get("status") == "COMPLETE" and not cleanup_ok:
                experiment["status"] = "UNEXECUTED"
                experiment["reason"] = "permission observations completed, but disposable-environment cleanup failed"
            self._diagnostic["permission_experiment"] = experiment
            if experiment.get("status") == "UNEXECUTED":
                self._diagnostic["assessment"]["permission_experiment"] = "UNEXECUTED — see permission_experiment.reason and cleanup evidence"
            self.save_diagnostic()
        return experiment

    def stage_a_permission_diagnosis(self, args):
        """Canonical CPMS-free pair run plus a conditional disposable permission clone."""
        result = probe.cmd_install(args)
        if result:
            self.capture_diagnostic_context("after_package_install_failure")
            self._diagnostic["activation"]["package_install_exit_code"] = result
            self.save_diagnostic()
            self.merge_diagnostic_log_checks()
            return self.end_a(False)

        store = self.store("A")
        provenance = store.data["facts"].get("provenance", [])
        self._diagnostic["activation"]["plugins"] = []
        for item in provenance:
            slug = item.get("slug", "unknown")
            activation_output = item.get("activation_output", "")
            activation_diagnostics = php_diagnostic_summary(activation_output)
            activation_phase = f"after_activation::{slug}"
            self._diagnostic["activation"]["plugins"].append({
                "slug": slug,
                "requested_version": item.get("requested_version"),
                "installed_version": item.get("installed_version"),
                "package_sha256": item.get("sha256"),
                "wp_cli_exit_code": item.get("activation_rc"),
                "wp_cli_output": activation_output,
                "php_diagnostics": activation_diagnostics,
            })
            store.check(f"activation_output_no_php_fatal::{slug}",
                        activation_diagnostics["fatal_count"] == 0,
                        probe.PRODUCT, activation_diagnostics,
                        True, activation_phase, 0, activation_diagnostics["fatal_count"])
            if activation_diagnostics["warning_count"]:
                store.record(f"activation_output_php_warnings::{slug}", probe.FAIL,
                             probe.PRODUCT, activation_diagnostics, False,
                             activation_phase, 0, activation_diagnostics["warning_count"])
        self._diagnostic["package_provenance"] = [
            {key: item.get(key) for key in
             ("slug", "requested_version", "installed_version", "sha256", "source_type",
              "activation_rc")}
            for item in provenance
        ]
        self._diagnostic["source_marker_references"] = self.source_marker_references()
        latest_activation = next((item for item in reversed(self._diagnostic["sentinel_timeline"])
                                  if item["phase"] == "after_activation::persian-woocommerce"), None)
        if latest_activation is None:
            latest_activation = self.sentinel_snapshot()
            self._diagnostic["sentinel_timeline"].append(
                {"phase": "after_activation::persian-woocommerce_missing_observer", **latest_activation})

        if "canonical_unlink_test" not in self._diagnostic:
            self._diagnostic["canonical_unlink_test"] = self.test_web_user_can_remove_sentinel(
                latest_activation, self._diagnostic.get("web_server_identity", {}))
        if "permission_experiment" not in self._diagnostic:
            self._diagnostic["permission_experiment"] = {
                "status": "NOT RUN",
                "canonical_results_substituted": False,
                "reason": "not necessary: canonical sentinel absent or the web-server unlink test did not establish a permission barrier",
            }
            self.save_diagnostic()

        self.run_canonical_diagnostic_requests()
        store = self.store("A")
        experiment = self._diagnostic.get("permission_experiment", {})
        if experiment.get("status") == "UNEXECUTED":
            store.unexecuted("conditional_permission_layout_experiment", probe.HARNESS,
                             experiment.get("reason", "the required disposable permission experiment did not complete"),
                             "permission-experiment")
        else:
            store.record("permission_layout_experiment_scope", probe.INFO, probe.HARNESS,
                         experiment.get("reason", experiment.get("status", "")),
                         phase="permission-experiment", observed=experiment.get("status"))
        web_identity = self._diagnostic.get("web_server_identity", {})
        identity_ok = (web_identity.get("status") == 200
                       and type(web_identity.get("effective_uid")) is int
                       and bool(web_identity.get("effective_user"))
                       and type(web_identity.get("effective_gid")) is int
                       and bool(web_identity.get("effective_group"))
                       and web_identity.get("php_sapi") == "apache2handler")
        store.check("apache_effective_user_measured", identity_ok, probe.HARNESS,
                    "effective PHP UID/user must come from the separate Apache/mod_php endpoint",
                    True, "before_activation", observed=web_identity)
        store.check("wp_cli_identity_measured",
                    self._diagnostic.get("wp_cli_identity", {}).get("effective_uid") is not None,
                    probe.HARNESS, "WP-CLI inherits the recorded runner identity",
                    True, "before_activation", observed=self._diagnostic.get("wp_cli_identity"))
        timeline_phases = {item["phase"] for item in self._diagnostic["sentinel_timeline"]}
        required_phases = {
            "before_activation", "after_activation::woocommerce",
            "after_activation::persian-woocommerce", "canonical_request_1_after_first_http_request",
            "canonical_request_2_after_first_http_request",
            "canonical_request_3_after_first_http_request",
        }
        store.check("sentinel_timeline_complete", required_phases <= timeline_phases,
                    probe.HARNESS, "missing sentinel snapshots are not inferred as absence",
                    True, "activation-and-http", sorted(required_phases), sorted(timeline_phases))
        unlink = self._diagnostic.get("canonical_unlink_test", {})
        if latest_activation.get("sentinel_exists"):
            store.check("web_user_canary_test_completed",
                        unlink.get("attempted") is True
                        and unlink.get("can_remove_sentinel") in (True, False),
                        probe.HARNESS, unlink,
                        True, "after_activation::persian-woocommerce",
                        "same-parent canary unlink observed", unlink.get("can_remove_sentinel"))
            store.record("web_user_sentinel_unlink_capability", probe.INFO, probe.HARNESS,
                         "A same-parent canary with matching metadata was used; the actual sentinel was not removed",
                         phase="after_activation::persian-woocommerce",
                         observed={"can_remove_sentinel": unlink.get("can_remove_sentinel"),
                                   "method": unlink.get("method"),
                                   "sentinel_path": unlink.get("sentinel_relative_path")})
            store.check("web_user_canary_did_not_mutate_sentinel",
                        unlink.get("sentinel_preserved") is True,
                        probe.HARNESS, unlink,
                        True, "after_activation::persian-woocommerce",
                        "actual sentinel preserved", unlink.get("sentinel_preserved"))
            if unlink.get("canary_cleanup_exit_code") is not None:
                store.check("web_user_canary_cleanup_succeeded",
                            unlink.get("canary_cleanup_exit_code") == 0,
                            probe.HARNESS, unlink.get("canary_cleanup_exit_code"),
                            True, "after_activation::persian-woocommerce", 0,
                            unlink.get("canary_cleanup_exit_code"))
        for sequence in self._diagnostic.get("http_navigations", []):
            navigation = sequence["navigation"]
            healthy = (navigation["final_status"] == 200
                       and not navigation["looped_or_bounded"]
                       and not navigation["transport_errors"])
            store.check(f"diagnostic_front_navigation::{sequence['name']}", healthy,
                        probe.PRODUCT,
                        "bounded same-origin navigation; full status/hops are in diagnostic.json",
                        True, sequence["name"],
                        {"final_status": 200, "looped_or_bounded": False},
                        {"final_status": navigation["final_status"],
                         "looped_or_bounded": navigation["looped_or_bounded"],
                         "redirect_count": navigation["redirect_count"],
                         "stopped_reason": navigation["stopped_reason"]})
        absence = probe.cpms_absence(self.wp_dir, self.db_name)
        store.check("cpms_absent_through_permission_diagnosis",
                    not absence["plugin_dir_present"] and not absence["plugin_files_found"]
                    and not absence["plugin_entries_found"] and absence["table_count"] == 0
                    and absence["cpms_option_count"] == 0,
                    probe.HARNESS, absence, True, "after-activation")
        store.save()

        # Existing third-party baseline probes still run on the canonical pair.
        probe.cmd_wp(args)
        args.timezone_snapshot = self.observe_timezone("before_locale_probe")
        probe.cmd_locale(args)
        self.browser("A")
        self.identity("A")
        self.observe_timezone("after_stage_a_probes")
        self.capture_diagnostic_context("after_standard_third_party_probes")
        self.check_timezones()
        self.merge_diagnostic_log_checks()
        return self.end_a(True)

    def stage_a(self):
        store = self.store("A")
        store.subject(id=GROUP, kind="plugin-group", pins=PINS, cpms="ABSENT")
        store.save()
        os.environ["TPB_RAW_COMMAND_LOG"] = str(self.root / "A/commands.jsonl")
        self.observe_timezone("after_core_install")
        # Fixture timezone only; no CPMS Location exists yet. Never copied into
        # Location data. Reused public booking fixture reads its Location row.
        for name, command in (
            ("fa_pack_installed", ["language", "core", "install", "fa_IR"]),
            ("fa_pack_active", ["language", "core", "activate", "fa_IR"]),
            ("fixture_site_timezone", ["option", "update", "timezone_string", "Asia/Tehran"]),
        ):
            if not self.cli(store, name, command):
                return self.end_a(False)
            if name == "fa_pack_active":
                self.observe_timezone("after_locale_setup")
        # A named timezone is authoritative in WordPress. Do not force a raw
        # offset: get_option('gmt_offset') is dynamically filtered by core.
        self.observe_timezone("after_timezone_configuration")
        args = self.args("A")
        args.subject_slug, args.subject_version = SUBJECT, PINS[SUBJECT]
        args.deps = ",".join(f"{s}={v}" for s, v in PINS.items() if s != SUBJECT)
        args.package_dir, args.no_retry = str(self.root / "A"), True
        args.activation_observer = (self.observe_diagnostic_activation
                                    if self._diagnostic is not None else self.observe_timezone)
        if self._diagnostic is not None:
            return self.stage_a_permission_diagnosis(args)
        if probe.cmd_install(args):
            return self.end_a(False)
        probe.cmd_wp(args)
        args.timezone_snapshot = self.observe_timezone("before_locale_probe")
        probe.cmd_locale(args)
        self.browser("A")
        self.identity("A")
        self.observe_timezone("after_stage_a_probes")
        self.check_timezones()
        return self.end_a(True)

    def end_a(self, complete):
        self.capture_logs("A")
        store = self.store("A")
        result = verdict(store, complete)
        measurement_complete = (complete and not any(
            check["status"] == probe.UNEXECUTED for check in store.data["checks"]))
        result["measurement_complete"] = measurement_complete
        if self._diagnostic is not None:
            result["classification"] = (
                "CPMS-FREE third-party/filesystem diagnosis — no CPMS attribution and no automatic A/B/C/D assignment"
            )
            result["failure_category"] = None
            result["diagnostic_evidence"] = "A/diagnostic.json"
            self._diagnostic["assessment"]["canonical_stage_a_status"] = result["status"]
            self._diagnostic["assessment"]["failure_category"] = None
            self.save_diagnostic()
            save(self.root / "A/result.json", result)
            if result["status"] != "PASS":
                probe.annotate("warning", "CPMS-free diagnosis recorded Stage A FAIL or warning; this is measured evidence, not a CPMS classification. Stage B is disabled by design.")
            return 0 if measurement_complete else 1

        result["classification"] = ("MATERIALLY HEALTHY GROUP" if result["status"] == "PASS"
                                    else "PRE-CPMS GROUP/ENVIRONMENT FAILURE — no CPMS attribution")
        save(self.root / "A/result.json", result)
        if result["status"] != "PASS":
            probe.annotate("error", "Stage A blocked; Stage B NOT RUN. See A/result.json and raw logs.")
        return 0 if result["status"] == "PASS" else 1

    def begin_b(self):
        if not STAGE_B_ENABLED:
            reason = ("this scenario is CPMS-free by design"
                      if GROUPS[GROUP].get("stage_b_enabled") is False
                      else "the caller explicitly disabled Stage B")
            raise RuntimeError(f"Stage B prohibited: {reason}")
        result = load(self.root / "A/result.json", {})
        if result.get("status") != "PASS" or verdict(self.store("A"), result.get("complete"))["status"] != "PASS":
            raise RuntimeError("Stage B refused: Stage A lacks complete materially healthy evidence")
        self.capture_logs("B", boundary=True)
        # Marker only after logs are durably preserved, before wp plugin install.
        save(self.root / "B/started.json", {"stage_a": "PASS", "boundary": "before CPMS install/activate"})

    def stage_b(self, steps):
        os.environ["TPB_RAW_COMMAND_LOG"] = str(self.root / "B/commands.jsonl")
        store = self.store("B")
        for name in ("cpms_install", "migrations", "roles", "booking", "browser_acceptance"):
            outcome = steps.get(name, {}).get("outcome", "NOT RUN")
            store.check(f"existing_harness::{name}", outcome == "success", probe.HARNESS, outcome)
        results = load(self.root.parent / "results.json", {})
        checks = results.get("checks", [])
        store.check("existing_acceptance_complete", bool(checks) and all(c["ok"] for c in checks)
                    and results.get("failed") == 0, probe.PRODUCT,
                    "See ../results.json and ../acceptance.log (unaltered existing harness)")
        passed = {c["name"] for c in checks if c["ok"]}
        for name in REQUIRED_ACCEPTANCE:
            store.check(f"acceptance_anchor::{name}", name in passed, probe.HARNESS,
                        "Missing/skipped role or smoke evidence is not a pass")
        health = probe.http_request(f"{self.url}/?rest_route=/clinic/v1/health")
        try:
            healthy = json.loads(health["body"]).get("data", {}).get("ok") is True
        except (ValueError, AttributeError):
            healthy = False
        store.check("cpms_rest_health", health["status"] == 200 and healthy, probe.PRODUCT, health)
        for page in ("/", "/wp-login.php"):
            response = probe.http_request(self.url + page, body_limit=probe.LOCALE_BODY_LIMIT)
            attrs = probe.html_root_attributes(response["body"])
            store.check(f"served_fa_rtl::{page}", response["status"] == 200
                        and attrs.get("lang") == "fa-IR" and attrs.get("dir") == "rtl", probe.PRODUCT,
                        {"attributes": attrs, "response": response})
        store.save()
        self.browser("B")
        self.identity("B")
        self.capture_logs("B")
        result = verdict(self.store("B"), True)
        result["classification"] = "POST-CPMS OBSERVATION — not automatic CPMS defect attribution"
        save(self.root / "B/result.json", result)
        return result

    def finalize(self, steps):
        summary = load(self.root / "summary.json", {})
        stage_a = load(self.root / "A/result.json")
        if stage_a is None:
            try:
                self.capture_logs("A")
            except Exception:
                (self.root / "A/log-capture-error.txt").write_text(traceback.format_exc())
            # Setup or an interrupted probe never becomes acceptance.
            stage_a = {"status": "NOT RUN", "reason": "Stage A did not complete; inspect workflow/setup evidence"}
            if self.store("A").data["checks"]:
                stage_a = verdict(self.store("A"), False)
        summary["stage_a"] = stage_a
        if GROUPS[GROUP].get("stage_b_enabled") is False:
            stage_b_reason = "CPMS Stage B is prohibited; this diagnostic remains CPMS-free"
        elif not STAGE_B_ENABLED:
            stage_b_reason = "CPMS Stage B was explicitly disabled for this run"
        elif stage_a is None or stage_a.get("status") != "PASS" or stage_a.get("complete") is not True:
            stage_b_reason = "Stage A failed or was incomplete; CPMS build, installation and activation did not run"
        else:
            stage_b_reason = "CPMS installation/activation boundary not reached"
        summary["stage_b"] = {"status": "NOT RUN", "reason": stage_b_reason}
        if (self.root / "B/started.json").exists():
            try:
                summary["stage_b"] = self.stage_b(steps)
            except Exception:
                (self.root / "B/exception.txt").write_text(traceback.format_exc())
                summary["stage_b"] = {"status": "FAIL", "reason": "incomplete Stage B; see B/exception.txt"}
        summary["not_run"] = NOT_RUN
        summary["unavailable_features"] = UNAVAILABLE
        if self._diagnostic is not None:
            summary["diagnostic_evidence"] = "A/diagnostic.json"
            summary["failure_taxonomy"] = {
                "category": None,
                "reason": "Third-party/plugin and permission observations are evidence; no CPMS A/B attribution or D classification is inferred from permission contribution alone.",
            }
        save(self.root / "summary.json", summary)
        text = (f"## {GROUPS[GROUP]['title']}\n"
                f"- Stage A (CPMS absent): **{summary['stage_a']['status']}**\n"
                f"- Stage B (after CPMS activation): **{summary['stage_b']['status']}**\n"
                "- Workflow success is not product acceptance. No union of individual baselines is assumed.\n"
                f"- Raw evidence: `{GROUPS[GROUP]['artifact']}-wp_` artifact, `{GROUPS[GROUP]['root']}/A` and `{GROUPS[GROUP]['root']}/B`.\n"
                + "".join(f"- {name}: {reason}\n" for name, reason in NOT_RUN.items()))
        if UNAVAILABLE:
            text += "".join(f"- FEATURE UNAVAILABLE — {name}: {reason}\n"
                            for name, reason in UNAVAILABLE.items())
        visibility = stage_a_failure_visibility(summary["stage_a"], summary["stage_b"].get("status"))
        if visibility:
            text += "\n" + visibility["markdown"] + "\n"
            probe.annotate("error", visibility["annotation"])
        (self.root / "summary.md").write_text(text)
        print(text)
        if os.environ.get("GITHUB_STEP_SUMMARY"):
            with open(os.environ["GITHUB_STEP_SUMMARY"], "a") as handle:
                handle.write(text)
        if not STAGE_B_ENABLED:
            return 0 if summary["stage_a"].get("measurement_complete") is True else 1
        return 0 if all(summary[s]["status"] == "PASS" for s in ("stage_a", "stage_b")) else 1


def main():
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument("command", choices=("init", "stage-a", "begin-b", "finalize"))
    args = parser.parse_args()
    lane = Slice(f"/tmp/acc/{GROUPS[GROUP]['root']}", os.environ["WP_DIR"], os.environ["WP_URL"], os.environ["DB_NAME"])
    try:
        if args.command == "finalize":
            return lane.finalize(json.loads(os.environ.get("COEX_STEPS", "{}")))
        return getattr(lane, args.command.replace("-", "_"))() or 0
    except Exception:
        stage = "B" if args.command == "begin-b" else "A"
        store = lane.store(stage)
        detail = traceback.format_exc()
        (lane.root / stage / "exception.txt").write_text(detail)
        store.check("orchestration_exception", False, probe.HARNESS, detail)
        store.save()
        probe.annotate("error", f"{args.command} did not complete; preserved {stage}/exception.txt")
        return 1


if __name__ == "__main__":
    sys.exit(main())
