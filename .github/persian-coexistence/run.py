#!/usr/bin/env python3
"""Bounded orchestration, not a second acceptance/browser implementation."""
import argparse
from datetime import datetime, timezone
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
import traceback

SPEC = importlib.util.spec_from_file_location(
    "baseline", Path(__file__).resolve().parents[1] / "third-party-baseline/probe.py")
probe = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(probe)

PINS = {
    "loco-translate": "2.8.9",
    "wp-parsidate": "6.4",
    "elementor": "4.3.4",
    "persian-elementor": "2.8.4",
    "woocommerce": "11.2.0",
}
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


class Slice:
    def __init__(self, root, wp_dir, url, db_name):
        self.root = Path(root)
        self.wp_dir, self.url, self.db_name = wp_dir, url, db_name
        self.root.mkdir(parents=True, exist_ok=True)
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
        save(self.root / "summary.json", {
            "stage_a": {"status": "NOT RUN"}, "stage_b": {"status": "NOT RUN"},
            "pins": PINS, "not_run": NOT_RUN,
            "source_sha": os.environ.get("GITHUB_SHA", "local"),
            "candidate_head_sha": os.environ.get("COEX_HEAD_SHA", "local"),
            "base_sha": os.environ.get("COEX_BASE_SHA", ""),
            "environment": {"wordpress": "7.1.3", "php": "8.3", "mysql": "8.4.11", "locale": "fa_IR"},
            "claim": "No coexistence acceptance until both stages have complete runtime evidence",
        })

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

    def stage_a(self):
        store = self.store("A")
        store.subject(id="persian-five", kind="plugin-group", pins=PINS, cpms="ABSENT")
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
        args.subject_slug, args.subject_version = "woocommerce", PINS["woocommerce"]
        args.deps = ",".join(f"{s}={v}" for s, v in PINS.items() if s != "woocommerce")
        args.package_dir, args.no_retry = str(self.root / "A"), True
        args.activation_observer = self.observe_timezone
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
        result = verdict(self.store("A"), complete)
        result["classification"] = ("MATERIALLY HEALTHY GROUP" if result["status"] == "PASS"
                                    else "PRE-CPMS GROUP/ENVIRONMENT FAILURE — no CPMS attribution")
        save(self.root / "A/result.json", result)
        if result["status"] != "PASS":
            probe.annotate("error", "Stage A blocked; Stage B NOT RUN. See A/result.json and raw logs.")
        return 0 if result["status"] == "PASS" else 1

    def begin_b(self):
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
        summary["stage_b"] = {"status": "NOT RUN", "reason": "CPMS installation/activation boundary not reached"}
        if (self.root / "B/started.json").exists():
            try:
                summary["stage_b"] = self.stage_b(steps)
            except Exception:
                (self.root / "B/exception.txt").write_text(traceback.format_exc())
                summary["stage_b"] = {"status": "FAIL", "reason": "incomplete Stage B; see B/exception.txt"}
        summary["not_run"] = NOT_RUN
        save(self.root / "summary.json", summary)
        text = ("## Persian five-plugin coexistence\n"
                f"- Stage A (CPMS absent): **{summary['stage_a']['status']}**\n"
                f"- Stage B (after CPMS activation): **{summary['stage_b']['status']}**\n"
                "- Workflow success is not product acceptance. No union of individual baselines is assumed.\n"
                "- Raw evidence: `persian-coexistence-wp_` artifact, `coexistence/A` and `coexistence/B`.\n"
                + "".join(f"- {name}: {reason}\n" for name, reason in NOT_RUN.items()))
        (self.root / "summary.md").write_text(text)
        print(text)
        if os.environ.get("GITHUB_STEP_SUMMARY"):
            with open(os.environ["GITHUB_STEP_SUMMARY"], "a") as handle:
                handle.write(text)
        return 0 if all(summary[s]["status"] == "PASS" for s in ("stage_a", "stage_b")) else 1


def main():
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument("command", choices=("init", "stage-a", "begin-b", "finalize"))
    args = parser.parse_args()
    lane = Slice("/tmp/acc/coexistence", os.environ["WP_DIR"], os.environ["WP_URL"], os.environ["DB_NAME"])
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
