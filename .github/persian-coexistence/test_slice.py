"""Local harness tests/fault injection, NOT real WordPress compatibility proof."""
import argparse
import contextlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import shutil
import tempfile
import subprocess
import threading
import unittest
from unittest.mock import patch
import zipfile

SPEC = importlib.util.spec_from_file_location("coexistence", Path(__file__).with_name("run.py"))
lane = importlib.util.module_from_spec(SPEC)
# The historical tests below assert the frozen five-plugin scenario (WooCommerce
# subject). Pin that explicitly so an ambient COEX_GROUP (CI sets it per job) cannot
# silently change them. Group-specific behaviour is tested via load_group().
with patch.dict(os.environ, {"COEX_GROUP": "persian-five", "COEX_STAGE_B": "true"}):
    SPEC.loader.exec_module(lane)


class SliceTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.env = patch.dict(os.environ, {"TPB_RAW_COMMAND_LOG": "", "GITHUB_STEP_SUMMARY": ""})
        self.env.start()
        self.addCleanup(self.env.stop)
        self.slice = lane.Slice(self.root / "coexistence", str(self.root / "wp"), "http://example.test", "fixture")
        self.slice.init()

    def healthy_a(self):
        store = self.slice.store("A")
        store.check("sentinel", True, lane.probe.PRODUCT)
        store.save()
        lane.save(self.slice.root / "A/result.json", lane.verdict(store, True))

    def timezone_samples(self):
        names = ["after_core_install", "after_locale_setup", "after_timezone_configuration",
                 "before_activation", *[f"after_activation::{s}" for s in lane.PINS], "before_locale_probe", "after_stage_a_probes"]
        return [{"timing": name, "raw_timezone_string": "Asia/Tehran" if i >= 2 else "",
                 "timezone_string": "Asia/Tehran" if i >= 2 else "", "raw_gmt_offset": "0",
                 "effective_gmt_offset": 3.5 if i >= 2 else 0, "php_derived_offset": 3.5, "errors": []}
                for i, name in enumerate(names)]

    def test_named_timezone_allows_raw_zero_without_forcing_it(self):
        lane.save(self.slice.root / "A/timezone.json", self.timezone_samples())
        self.slice.check_timezones()
        self.assertEqual(lane.verdict(self.slice.store("A"), True)["status"], "PASS")

    def test_raw_or_effective_timezone_changes_block_stage_b(self):
        for field, changed in (("raw_gmt_offset", "4"), ("timezone_string", "UTC"),
                               ("raw_timezone_string", "UTC"), ("effective_gmt_offset", 0)):
            with self.subTest(field=field):
                lane.save(self.slice.root / "A/evidence.json", {"subject": {}, "facts": {}, "checks": []})
                samples = self.timezone_samples()
                samples[-2][field] = changed
                lane.save(self.slice.root / "A/timezone.json", samples)
                self.slice.check_timezones()
                result = lane.verdict(self.slice.store("A"), True)
                self.assertEqual(result["status"], "FAIL")
                lane.save(self.slice.root / "A/result.json", result)
                with self.assertRaisesRegex(RuntimeError, "Stage B refused"):
                    self.slice.begin_b()

    def test_missing_timezone_timing_fails_closed(self):
        lane.save(self.slice.root / "A/timezone.json", self.timezone_samples()[:-1])
        self.slice.check_timezones()
        self.assertEqual(lane.verdict(self.slice.store("A"), True)["status"], "FAIL")

    def test_timezone_snapshot_is_bounded_and_preserves_raw_vs_effective(self):
        payload = {"timezone_string": "Asia/Tehran", "effective_gmt_offset": 3.5, "php_derived_offset": 3.5}
        with patch.object(lane.probe, "db_option", side_effect=[(True, "Asia/Tehran", ""), (True, "0", "")]) as db, \
                patch.object(lane.probe, "wp_cli", return_value={"rc": 0, "stdout": "CPMS_TIMEZONE:" + json.dumps(payload)}) as cli:
            sample = self.slice.observe_timezone("before_activation")
        self.assertEqual([c.args[1] for c in db.call_args_list], ["timezone_string", "gmt_offset"])
        self.assertEqual(cli.call_args.args[0][0], "eval")
        self.assertEqual(sample["raw_gmt_offset"], "0")
        self.assertEqual(sample["effective_gmt_offset"], 3.5)
        self.assertEqual(set(sample), {"timing", "captured_at", "errors", "raw_timezone_string", "raw_gmt_offset", *payload})

    def test_stage_a_does_not_write_raw_offset_and_observes_activation_order(self):
        commands, samples = [], []
        def cli(store, name, args):
            commands.append(args)
            return True
        def observe(timing):
            samples.append(timing)
            return {"effective_gmt_offset": 3.5}
        def install(args):
            args.activation_observer("before_activation")
            for slug in lane.PINS:
                args.activation_observer(f"after_activation::{slug}")
            return 0
        with patch.object(self.slice, "cli", side_effect=cli), \
                patch.object(self.slice, "observe_timezone", side_effect=observe), \
                patch.object(lane.probe, "cmd_install", side_effect=install), \
                patch.object(lane.probe, "cmd_wp"), patch.object(lane.probe, "cmd_locale"), \
                patch.object(self.slice, "check_timezones"), patch.object(self.slice, "browser"), \
                patch.object(self.slice, "identity"), patch.object(self.slice, "end_a", return_value=0):
            self.assertEqual(self.slice.stage_a(), 0)
        self.assertEqual(commands, [["language", "core", "install", "fa_IR"],
                                   ["language", "core", "activate", "fa_IR"],
                                   ["option", "update", "timezone_string", "Asia/Tehran"]])
        self.assertEqual(samples, [s["timing"] for s in self.timezone_samples()])

    @unittest.skipUnless(os.environ.get("CPMS_WP_SOURCE"), "unmodified WordPress source not supplied")
    def test_actual_wordpress_timezone_option_semantics(self):
        php = os.environ.get("CPMS_TEST_PHP", "php")
        script = Path(__file__).parent / "tests/timezone-semantics.php"
        result = subprocess.run([php, str(script), os.environ["CPMS_WP_SOURCE"]],
                                capture_output=True, text=True, check=True)
        data = json.loads(result.stdout)
        self.assertEqual(data["initial_effective"], "0")
        self.assertEqual(data["raw_gmt_offset"], "0")
        self.assertEqual(data["effective_gmt_offset"], 3.5)
        self.assertEqual(data["php_derived_offset"], 3.5)
        self.assertTrue(data["wp_cli_update_is_noop"])

    def test_locale_oracle_default_unchanged_and_group_effective_value_gated(self):
        for raw, effective, expected in (("3.5", None, "PASS"), ("0", None, "FAIL"),
                                          ("0", 3.5, "PASS"), ("0", 0, "FAIL"),
                                          ("0", "missing", "UNEXECUTED")):
            with self.subTest(raw=raw, effective=effective):
                args = self.slice.args("A")
                args.store = str(self.root / f"oracle-{raw}-{effective}.json")
                if effective is not None:
                    args.timezone_snapshot = {} if effective == "missing" else {"effective_gmt_offset": effective}
                options = {"WPLANG": "fa_IR", "timezone_string": "Asia/Tehran", "gmt_offset": raw}
                response = {"status": 200, "body": '<html lang="fa-IR" dir="rtl">آزمون</html>',
                            "content_type": "text/html; charset=UTF-8", "transport_error": "",
                            "redirects": 0, "chain": [], "looped": False, "final_url": "http://example.test/"}
                with patch.object(lane.probe, "read_wp_core_version", return_value="7.1.3"), \
                        patch.object(lane.probe, "web_runtime_probe", return_value={"php_version": "8.3.33", "transport_error": ""}), \
                        patch.object(lane.probe, "db_option", side_effect=lambda db, key: (True, options[key], "")), \
                        patch.object(lane.probe, "scalar", return_value=(True, "1", "")), \
                        patch.object(lane.probe, "mysql_query", return_value={"rc": 0, "stdout": "", "stderr": ""}), \
                        patch.object(lane.probe, "run", return_value={"rc": 0, "stdout": "Asia/Tehran 12600 2026-10-08", "stderr": ""}), \
                        patch.object(lane.probe, "http_request", return_value=response), \
                        contextlib.redirect_stdout(io.StringIO()):
                    lane.probe.cmd_locale(args)
                checks = lane.probe.Store(args.store).data["checks"]
                offset_check = next(c for c in checks if c["name"] == "fa_gmt_offset_agrees_with_php")
                self.assertEqual(offset_check["status"], expected)
                if effective is None:
                    self.assertIn("stored gmt_offset", offset_check["detail"])
                self.assertTrue(any(c["name"] == "fa_gmt_offset_recorded" for c in checks))

    def test_installer_default_and_opt_in_activation_observer(self):
        from urllib.parse import urlparse
        for with_observer in (False, True):
            events = []
            args = self.slice.args("A")
            args.store = str(self.root / f"installer-{with_observer}.json")
            args.package_dir = str(self.root)
            args.subject_slug, args.subject_version = "woocommerce", lane.PINS["woocommerce"]
            args.deps = ",".join(f"{s}={v}" for s, v in lane.PINS.items() if s != "woocommerce")
            args.no_retry = True
            if with_observer:
                args.activation_observer = lambda timing: events.append(timing)
            def download(url, dest, **kwargs):
                name = Path(urlparse(url).path).name
                slug = next(s for s in lane.PINS if name.startswith(s + "."))
                with zipfile.ZipFile(dest, "w") as archive:
                    archive.writestr(f"{slug}/{slug}.php", f"<?php\n/*\nPlugin Name: Fixture\nVersion: {lane.PINS[slug]}\n*/")
                return []
            def cli(command, wp_dir, **kwargs):
                if command[1] == "activate":
                    events.append("activate::" + command[2])
                value = lane.PINS[command[2]] if command[1] == "get" else "ok"
                return {"rc": 0, "stdout": value, "stderr": ""}
            headers = [{"slug": s, "version": v, "name": s, "requires_plugins": []} for s, v in lane.PINS.items()]
            with patch.object(lane.probe, "download", side_effect=download), \
                    patch.object(lane.probe, "wp_cli", side_effect=cli), \
                    patch.object(lane.probe, "read_plugin_headers", return_value=headers), \
                    contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(lane.probe.cmd_install(args), 0)
            expected = ["before_activation"] if with_observer else []
            for slug in lane.PINS:
                expected.append("activate::" + slug)
                if with_observer:
                    expected.append("after_activation::" + slug)
            self.assertEqual(events, expected)
            self.assertFalse(lane.blockers(lane.probe.Store(args.store).data["checks"]))

    def test_empty_or_incomplete_is_not_pass(self):
        store = self.slice.store("A")
        self.assertEqual(lane.verdict(store, True)["status"], "FAIL")
        store.check("ok", True, lane.probe.PRODUCT)
        self.assertEqual(lane.verdict(store, False)["status"], "FAIL")

    def test_material_failure_keeps_first_cause(self):
        store = self.slice.store("A")
        store.check("first activation fatal", False, lane.probe.PRODUCT, "original stack")
        store.check("downstream login", False, lane.probe.PRODUCT)
        result = lane.verdict(store, True)
        self.assertEqual(result["first_causal_check"]["detail"], "original stack")
        self.assertEqual(result["status"], "FAIL")

    def test_warnings_recorded_without_becoming_material(self):
        store = self.slice.store("A")
        store.check("bootstrap", True, lane.probe.PRODUCT)
        store.check("warning", False, lane.probe.PRODUCT, material=False)
        result = lane.verdict(store, True)
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(len(result["warnings"]), 1)

    def test_unexecuted_browser_blocks_even_if_nonmaterial(self):
        store = self.slice.store("A")
        store.unexecuted("browser", lane.probe.HARNESS, "missing chromium", "post")
        self.assertEqual(lane.verdict(store, True)["status"], "FAIL")

    def test_missing_stage_a_refuses_b(self):
        with self.assertRaisesRegex(RuntimeError, "Stage B refused"):
            self.slice.begin_b()
        self.assertFalse((self.slice.root / "B/started.json").exists())

    def test_forged_summary_does_not_bypass_material_failure(self):
        self.healthy_a()
        store = self.slice.store("A")
        store.check("fatal", False, lane.probe.PRODUCT)
        store.save()
        with self.assertRaisesRegex(RuntimeError, "Stage B refused"):
            self.slice.begin_b()

    def test_stage_a_failure_never_runs_b(self):
        store = self.slice.store("A")
        store.check("third_party_activation", False, lane.probe.PRODUCT, "first fatal")
        store.save()
        with patch.object(self.slice, "log_bytes", return_value={"debug": b"PHP Fatal error: original\n"}), \
                patch.object(self.slice, "stage_b") as stage_b, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(self.slice.end_a(False), 1)
            self.assertEqual(self.slice.finalize({}), 1)
        stage_b.assert_not_called()
        summary = lane.load(self.slice.root / "summary.json")
        self.assertEqual(summary["stage_a"]["status"], "FAIL")
        self.assertEqual(summary["stage_b"]["status"], "NOT RUN")
        self.assertIn("Stage A failed or was incomplete", summary["stage_b"]["reason"])
        self.assertEqual((self.slice.root / "A/debug.log").read_bytes(), b"PHP Fatal error: original\n")

    def test_pre_activation_warning_not_counted_as_b_error(self):
        self.healthy_a()
        before = b"PHP Warning: third-party baseline\n"
        after = b"PHP Fatal error: after activation\n"
        with patch.object(self.slice, "log_bytes", return_value={"debug": before}):
            self.slice.begin_b()
        with patch.object(self.slice, "log_bytes", return_value={"debug": before + after}):
            self.slice.capture_logs("B")
        self.assertEqual((self.slice.root / "B/debug-before-activation.log").read_bytes(), before)
        self.assertEqual((self.slice.root / "B/debug.log").read_bytes(), after)
        self.assertEqual(lane.verdict(self.slice.store("B"), True)["status"], "FAIL")

    def test_log_rotation_fails_closed_and_preserves_observed_bytes(self):
        self.healthy_a()
        with patch.object(self.slice, "log_bytes", return_value={"debug": b"old\n"}):
            self.slice.begin_b()
        with patch.object(self.slice, "log_bytes", return_value={"debug": b"rotated\n"}):
            self.slice.capture_logs("B")
        result = lane.verdict(self.slice.store("B"), True)
        self.assertEqual(result["first_causal_check"]["name"], "debug_log_prefix_preserved")
        self.assertEqual((self.slice.root / "B/debug.log").read_bytes(), b"rotated\n")

    def test_setup_not_run_is_not_pass(self):
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(self.slice.finalize({}), 1)
        result = lane.load(self.slice.root / "summary.json")
        self.assertEqual(result["stage_a"]["status"], "NOT RUN")
        self.assertEqual(result["stage_b"]["status"], "NOT RUN")

    def test_missing_acceptance_cannot_pass_stage_b(self):
        self.healthy_a()
        with patch.object(self.slice, "log_bytes", return_value={"debug": b""}):
            self.slice.begin_b()
        response = {"status": 200, "body": '{"data":{"ok":true}}'}
        with patch.object(self.slice, "identity"), patch.object(self.slice, "browser"), \
                patch.object(self.slice, "capture_logs"), patch.object(lane.probe, "http_request", return_value=response):
            result = self.slice.stage_b({})
        self.assertEqual(result["status"], "FAIL")
        self.assertTrue(any(c["name"].startswith("acceptance_anchor::") for c in result["blocking_checks"]))

    def test_complete_b_requires_every_step_and_acceptance_anchor(self):
        lane.save(self.slice.root.parent / "results.json", {
            "checks": [{"name": name, "ok": True} for name in lane.REQUIRED_ACCEPTANCE], "failed": 0})
        steps = {name: {"outcome": "success"} for name in
                 ("cpms_install", "migrations", "roles", "booking", "browser_acceptance")}
        def response(url, **kwargs):
            return {"status": 200, "body": '{"data":{"ok":true}}' if "rest_route" in url
                    else '<html dir="rtl" lang="fa-IR"><body>فارسی</body></html>'}
        with patch.object(self.slice, "identity"), patch.object(self.slice, "browser"), \
                patch.object(self.slice, "capture_logs"), patch.object(lane.probe, "http_request", side_effect=response):
            self.assertEqual(self.slice.stage_b(steps)["status"], "PASS")
            steps["migrations"]["outcome"] = "skipped"
            self.assertEqual(self.slice.stage_b(steps)["status"], "FAIL")

    def test_full_patch_pin_and_plugin_drift(self):
        headers = [{"slug": slug, "version": version} for slug, version in lane.PINS.items()]
        def check(mysql, drift=False):
            self.slice.store("A")
            with patch.object(lane.probe, "read_plugin_headers", return_value=headers), \
                    patch.object(lane.probe, "db_active_plugins", return_value=(True, [s + "/main.php" for s in lane.PINS], "")), \
                    patch.object(lane.probe, "scalar", return_value=(True, mysql, "")), \
                    patch.object(lane.probe, "web_runtime_probe", return_value={"php_version": "8.3.30"}), \
                    patch.object(lane.probe, "run", return_value={"rc": 0, "stdout": "8.3.30"}), \
                    patch.object(lane.probe, "read_wp_core_version", return_value="7.1.3"), \
                    patch.object(lane.probe, "db_option", return_value=(True, "fa_IR", "")):
                self.slice.identity("A")
            checks = self.slice.store("A").data["checks"]
            return [c for c in checks if c["name"] == ("unchanged_version::loco-translate" if drift else "mysql_full_version_exact")][-1]
        self.assertEqual(check("8.4.11")["status"], "PASS")
        self.assertEqual(check("8.4.12")["status"], "FAIL")
        self.assertEqual(check("8.4")["status"], "FAIL")
        headers[0]["version"] = "2.8.10"
        self.assertEqual(check("8.4.11", True)["status"], "FAIL")

    def test_exact_zip_identity_rejects_other_version(self):
        for slug, pin in lane.PINS.items():
            path = self.root / f"{slug}.zip"
            for version in (pin, pin + ".999"):
                with zipfile.ZipFile(path, "w") as archive:
                    archive.writestr(f"{slug}/{slug}.php", f"<?php\n/*\nPlugin Name: Fixture {slug}\nVersion: {version}\n*/\n")
                identity = lane.probe.inspect_zip_plugin_identity(str(path), slug, pin)
                self.assertEqual(identity["ok"], version == pin, identity)

    def test_raw_command_output_not_truncated_and_no_argv_recorded(self):
        stub = self.root / "wp"
        stub.write_text("#!/bin/sh\nprintf 'first causal error\\n' >&2\nprintf '%06000d' 0 >&2\nexit 7\n")
        stub.chmod(0o755)
        log = self.root / "commands.jsonl"
        with patch.dict(os.environ, {"PATH": str(self.root) + ":" + os.environ["PATH"], "TPB_RAW_COMMAND_LOG": str(log)}):
            result = lane.probe.wp_cli(["eval", "not-a-real-secret"], str(self.root))
        self.assertEqual(result["rc"], 7)
        raw = json.loads(log.read_text())
        self.assertTrue(raw["stderr"].startswith("first causal error"))
        self.assertGreater(len(raw["stderr"]), 6000)
        self.assertNotIn("not-a-real-secret", log.read_text())

    def test_stage_a_reads_findings_not_zero_probe_exit(self):
        def broken_wp(args):
            store = lane.probe.Store(args.store)
            store.check("combined_group_bootstrap", False, lane.probe.PRODUCT, "first causal fatal")
            store.save()
            return 0  # Deliberate legacy measurement contract.
        with patch.object(self.slice, "cli", return_value=True), \
                patch.object(lane.probe, "run", return_value={"rc": 0, "stdout": "3.5"}), \
                patch.object(lane.probe, "cmd_install", return_value=0), \
                patch.object(lane.probe, "cmd_wp", side_effect=broken_wp), \
                patch.object(lane.probe, "cmd_locale", return_value=0), \
                patch.object(self.slice, "identity"), patch.object(self.slice, "browser"), \
                patch.object(self.slice, "log_bytes", return_value={"debug": b"raw cause"}), \
                contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(self.slice.stage_a(), 1)
        result = lane.load(self.slice.root / "A/result.json")
        self.assertEqual(result["first_causal_check"]["detail"], "first causal fatal")
        with self.assertRaises(RuntimeError):
            self.slice.begin_b()

    def test_install_failure_stops_before_later_probes(self):
        with patch.object(self.slice, "cli", return_value=True), \
                patch.object(lane.probe, "run", return_value={"rc": 0, "stdout": "3.5"}), \
                patch.object(lane.probe, "cmd_install", return_value=1), \
                patch.object(lane.probe, "cmd_wp") as wp, \
                patch.object(self.slice, "log_bytes", return_value={"debug": b""}), \
                contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(self.slice.stage_a(), 1)
        wp.assert_not_called()
        self.assertFalse((self.slice.root / "B/started.json").exists())

    def test_frozen_set_keeps_persian_woocommerce_out(self):
        self.assertEqual(lane.PINS, {
            "loco-translate": "2.8.9", "wp-parsidate": "6.4", "elementor": "4.3.4",
            "persian-elementor": "2.8.4", "woocommerce": "11.2.0"})
        self.assertNotIn("persian-woocommerce", lane.PINS)
        self.assertEqual(lane.PINS["woocommerce"], "11.2.0")


def load_group(group, stage_b=True):
    """Fresh run.py module bound to one workflow group/Stage B selector.
    group=None loads with COEX_GROUP unset, exercising the real default."""
    with patch.dict(os.environ, {"COEX_STAGE_B": str(stage_b).lower()}):
        if group is None:
            os.environ.pop("COEX_GROUP", None)
        else:
            os.environ["COEX_GROUP"] = group
        spec = importlib.util.spec_from_file_location(f"coexistence_{group}", Path(__file__).with_name("run.py"))
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
    return module


def authoritative_subject_pins():
    """Read only kind=subject pins from the frozen third-party baseline matrix."""
    matrix_path = Path(__file__).parents[1] / "workflows/third-party-baseline.yml"
    text = matrix_path.read_text(encoding="utf-8")
    matrix = text.split("      matrix:\n", 1)[1]
    section = matrix.split("        include:\n", 1)[1].split("    services:\n", 1)[0]
    pins = {}
    for entry in re.split(r"(?m)^          - id: ", section)[1:]:
        kind = re.search(r"(?m)^            kind:\s*(\S+)", entry)
        if not kind or kind.group(1) != "subject":
            continue
        slug = re.search(r"(?m)^            slug:\s*['\"]?([^\s'\"]+)", entry)
        version = re.search(r"(?m)^            version:\s*['\"]?([^\s'\"]+)", entry)
        if not slug or not version:
            raise AssertionError("authoritative subject matrix entry lacks slug/version")
        pins[slug.group(1)] = version.group(1)
    return pins


class GroupSelectionTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)

    FROZEN_FIVE = {"loco-translate": "2.8.9", "wp-parsidate": "6.4", "elementor": "4.3.4",
                   "persian-elementor": "2.8.4", "woocommerce": "11.2.0"}

    def test_default_group_is_the_unchanged_five_plugin_scenario(self):
        for group in ("persian-five", None):  # explicit selection and COEX_GROUP unset
            with self.subTest(group=group):
                module = load_group(group)
                self.assertEqual(module.GROUP, "persian-five")
                self.assertEqual(module.SUBJECT, "woocommerce")
                self.assertEqual(module.PINS, self.FROZEN_FIVE)
                self.assertEqual(module.GROUPS["persian-five"]["root"], "coexistence")
                self.assertEqual(module.GROUPS["persian-five"]["artifact"], "persian-coexistence")
        self.assertEqual(lane.PINS, self.FROZEN_FIVE)

    def test_security_group_pins_exactly_the_approved_two_plugins(self):
        module = load_group("security-authentication")
        self.assertEqual(module.PINS, {"wordfence": "9.0.2", "really-simple-ssl": "9.8.3"})
        self.assertEqual(module.SUBJECT, "wordfence")
        self.assertEqual(module.GROUPS["security-authentication"]["root"], "coexistence-security-authentication")
        self.assertEqual(module.GROUPS["security-authentication"]["artifact"],
                         "persian-coexistence-security-authentication")
        # The security group must not inherit or disturb the five-plugin pins.
        for slug in module.PINS:
            self.assertNotIn(slug, load_group("persian-five").PINS)
        self.assertIn("security plugin hardening", module.NOT_RUN)
        self.assertNotIn("security plugin hardening", load_group("persian-five").NOT_RUN)
        for key in ("Clinic A/B isolation", "synthetic broken migration"):
            self.assertIn(key, module.NOT_RUN)

    def test_combined_active_set_is_authoritative_matrix_except_quarantined_plugin(self):
        module = load_group("combined-nineteen")
        expected = authoritative_subject_pins()
        # The authoritative matrix itself is unchanged and still holds 19 free
        # subjects, Persian WooCommerce 10.0.5 included.
        self.assertEqual(len(expected), 19)
        self.assertEqual(expected["persian-woocommerce"], "10.0.5")
        # The Phase 19 owner decision quarantines exactly one subject from this
        # active scenario; every other plugin keeps its exact authoritative pin.
        self.assertEqual(len(module.PINS), 18)
        self.assertEqual(module.PINS, {slug: version for slug, version in expected.items()
                                       if slug != "persian-woocommerce"})
        self.assertNotIn("persian-woocommerce", module.PINS)
        self.assertEqual(module.SUBJECT, "contact-form-7")
        # The historical identity survives untouched so Phase 18 all-19 evidence
        # remains addressable under its original names.
        self.assertEqual(module.GROUPS["combined-nineteen"]["root"], "coexistence-combined-nineteen")
        self.assertEqual(module.GROUPS["combined-nineteen"]["artifact"],
                         "persian-coexistence-combined-nineteen")
        self.assertTrue(module.STAGE_B_ENABLED)
        self.assertNotIn("wp-rocket", module.PINS)
        self.assertNotIn("gravityforms", module.PINS)
        self.assertEqual(module.PINS["woocommerce"], "11.2.0")
        self.assertEqual(module.PINS["persian-woocommerce-sms"], "7.3.1")
        # Exclusion narrows only this combined scenario: the independent Persian
        # WooCommerce lanes keep their exact pins and CPMS-free/Stage-B boundaries.
        diagnosis = load_group("persian-woocommerce-diagnosis")
        self.assertEqual(diagnosis.PINS, {"woocommerce": "11.2.0", "persian-woocommerce": "10.0.5"})
        self.assertFalse(diagnosis.STAGE_B_ENABLED)
        self.assertEqual(module.GROUPS["combined-nineteen"]["title"],
                         "Persian combined compatibility scenario (18 active plugins; "
                         "Persian WooCommerce 10.0.5 quarantined)")

    def test_new_sms_and_diagnostic_groups_have_independent_pins_and_b_boundaries(self):
        sms = load_group("persian-woocommerce-sms")
        self.assertEqual(sms.PINS, {"woocommerce": "11.2.0",
                                    "persian-woocommerce-sms": "7.3.1"})
        self.assertEqual(sms.SUBJECT, "persian-woocommerce-sms")
        self.assertTrue(sms.STAGE_B_ENABLED)
        sms_a_only = load_group("persian-woocommerce-sms", stage_b=False)
        self.assertFalse(sms_a_only.STAGE_B_ENABLED)
        self.assertIn("real SMS delivery", sms.NOT_RUN)
        self.assertIn("no live provider", sms.NOT_RUN["real SMS delivery"])

        diagnosis = load_group("persian-woocommerce-diagnosis")
        self.assertEqual(diagnosis.PINS, {"woocommerce": "11.2.0",
                                          "persian-woocommerce": "10.0.5"})
        self.assertFalse(diagnosis.STAGE_B_ENABLED)
        self.assertIn("CPMS coexistence", diagnosis.NOT_RUN)
        self.assertNotIn("permission experiment", diagnosis.NOT_RUN)

        roots = {module.GROUPS[group]["root"] for module, group in (
            (sms, "persian-woocommerce-sms"), (diagnosis, "persian-woocommerce-diagnosis"),
            (load_group("combined-nineteen"), "combined-nineteen"))}
        artifacts = {module.GROUPS[group]["artifact"] for module, group in (
            (sms, "persian-woocommerce-sms"), (diagnosis, "persian-woocommerce-diagnosis"),
            (load_group("combined-nineteen"), "combined-nineteen"))}
        self.assertEqual(len(roots), 3)
        self.assertEqual(len(artifacts), 3)

    def test_new_workflow_jobs_use_independent_groups_and_diagnostic_disables_stage_b(self):
        workflow = (Path(__file__).parents[1] / "workflows/persian-coexistence.yml").read_text()
        for job in ("coexistence-persian-woocommerce-diagnosis",
                    "coexistence-persian-woocommerce-sms", "coexistence-combined-nineteen"):
            self.assertIn(f"  {job}:\n", workflow)
        self.assertIn("coexistence_group: persian-woocommerce-diagnosis\n      coexistence_stage_b: false", workflow)
        self.assertIn("coexistence_group: persian-woocommerce-sms\n      coexistence_stage_b: true", workflow)
        self.assertIn("coexistence_group: combined-nineteen\n      coexistence_stage_b: true", workflow)

    def test_unknown_group_fails_closed_at_import(self):
        with self.assertRaises(SystemExit):
            load_group("no-such-group")

    def test_security_installer_installs_dependency_before_subject(self):
        module = load_group("security-authentication")
        from urllib.parse import urlparse
        events = []
        args = self.slice_args(module)
        args.subject_slug, args.subject_version = module.SUBJECT, module.PINS[module.SUBJECT]
        args.deps = ",".join(f"{s}={v}" for s, v in module.PINS.items() if s != module.SUBJECT)
        args.activation_observer = lambda timing: events.append(timing)

        def download(url, dest, **kwargs):
            name = Path(urlparse(url).path).name
            slug = next(s for s in module.PINS if name.startswith(s + "."))
            with zipfile.ZipFile(dest, "w") as archive:
                archive.writestr(f"{slug}/{slug}.php", f"<?php\n/*\nPlugin Name: Fixture\nVersion: {module.PINS[slug]}\n*/")
            return []

        def cli(command, wp_dir, **kwargs):
            if command[1] == "activate":
                events.append("activate::" + command[2])
            value = module.PINS[command[2]] if command[1] == "get" else "ok"
            return {"rc": 0, "stdout": value, "stderr": ""}

        headers = [{"slug": s, "version": v, "name": s, "requires_plugins": []} for s, v in module.PINS.items()]
        with patch.object(module.probe, "download", side_effect=download), \
                patch.object(module.probe, "wp_cli", side_effect=cli), \
                patch.object(module.probe, "read_plugin_headers", return_value=headers), \
                contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(module.probe.cmd_install(args), 0)
        self.assertEqual(events, ["before_activation",
                                  "activate::really-simple-ssl", "after_activation::really-simple-ssl",
                                  "activate::wordfence", "after_activation::wordfence"])
        self.assertFalse(module.blockers(module.probe.Store(args.store).data["checks"]))

    def slice_args(self, module):
        args = argparse.Namespace(store=str(self.root / "security-installer.json"), wp_dir="", wp_url="",
                                  db_name="", package_dir=str(self.root), no_retry=True)
        return args


class CombinedTenGroupTests(unittest.TestCase):
    """The single approved ten-plugin combined scenario.

    Local harness/group-selection proof only: no real WordPress, MySQL, Apache or
    browser runs here, so none of this is a compatibility claim.
    """

    APPROVED_TEN = {
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
    }

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.module = load_group("combined-ten")
        self.env = patch.dict(os.environ, {"TPB_RAW_COMMAND_LOG": "", "GITHUB_STEP_SUMMARY": ""})
        self.env.start()
        self.addCleanup(self.env.stop)

    def slice(self, name="combined"):
        lane_slice = self.module.Slice(self.root / name, str(self.root / "wp"),
                                       "http://example.test", "fixture")
        lane_slice.init()
        return lane_slice

    def test_combined_group_pins_exactly_the_approved_ten_plugins(self):
        self.assertEqual(self.module.PINS, self.APPROVED_TEN)
        self.assertEqual(len(self.module.PINS), 10)
        self.assertEqual(self.module.SUBJECT, "contact-form-7")
        self.assertEqual(self.module.GROUPS["combined-ten"]["root"], "coexistence-combined-ten")
        self.assertEqual(self.module.GROUPS["combined-ten"]["artifact"],
                         "persian-coexistence-combined-ten")
        # Persian WooCommerce stays out of this group; its existing third-party
        # baseline FAIL must remain the visible, unchanged finding elsewhere.
        self.assertNotIn("persian-woocommerce", self.module.PINS)
        five, security = load_group("persian-five"), load_group("security-authentication")
        for slug in self.module.PINS:
            self.assertNotIn(slug, five.PINS)
            self.assertNotIn(slug, security.PINS)
        # The combined group's own scope limits are not inherited by the others.
        self.assertIn("competing SEO plugin configuration", self.module.NOT_RUN)
        self.assertIn("authored third-party configuration", self.module.NOT_RUN)
        for other in (five, security):
            self.assertNotIn("competing SEO plugin configuration", other.NOT_RUN)
        # Shared inherited scope limits stay in force for this group too.
        for key in ("Clinic A/B isolation", "synthetic broken migration"):
            self.assertIn(key, self.module.NOT_RUN)

    def test_preserved_groups_and_their_evidence_roots_are_unchanged(self):
        five, security = load_group("persian-five"), load_group("security-authentication")
        self.assertEqual(five.PINS, {"loco-translate": "2.8.9", "wp-parsidate": "6.4",
                                     "elementor": "4.3.4", "persian-elementor": "2.8.4",
                                     "woocommerce": "11.2.0"})
        self.assertEqual(five.SUBJECT, "woocommerce")
        self.assertEqual(five.GROUPS["persian-five"]["root"], "coexistence")
        self.assertEqual(five.GROUPS["persian-five"]["artifact"], "persian-coexistence")
        self.assertEqual(five.UNAVAILABLE, {})
        self.assertEqual(security.PINS, {"wordfence": "9.0.2", "really-simple-ssl": "9.8.3"})
        self.assertEqual(security.SUBJECT, "wordfence")
        self.assertEqual(security.GROUPS["security-authentication"]["root"],
                         "coexistence-security-authentication")
        self.assertEqual(security.GROUPS["security-authentication"]["artifact"],
                         "persian-coexistence-security-authentication")
        groups = [five.GROUPS["persian-five"], security.GROUPS["security-authentication"],
                  self.module.GROUPS["combined-ten"]]
        # Three separate evidence roots and artifacts: no group overwrites another.
        self.assertEqual(len({g["root"] for g in groups}), 3)
        self.assertEqual(len({g["artifact"] for g in groups}), 3)

    def test_all_ten_are_installed_together_and_none_is_ever_deactivated(self):
        from urllib.parse import urlparse
        calls, events = [], []
        args = argparse.Namespace(store=str(self.root / "combined-install.json"), wp_dir="", wp_url="",
                                  db_name="", package_dir=str(self.root), no_retry=True)
        args.subject_slug = self.module.SUBJECT
        args.subject_version = self.module.PINS[self.module.SUBJECT]
        args.deps = ",".join(f"{s}={v}" for s, v in self.module.PINS.items() if s != self.module.SUBJECT)
        args.activation_observer = lambda timing: events.append(timing)

        def download(url, dest, **kwargs):
            name = Path(urlparse(url).path).name
            slug = next(s for s in self.module.PINS if name.startswith(s + "."))
            with zipfile.ZipFile(dest, "w") as archive:
                archive.writestr(f"{slug}/{slug}.php",
                                 f"<?php\n/*\nPlugin Name: Fixture\nVersion: {self.module.PINS[slug]}\n*/")
            return []

        def cli(command, wp_dir, **kwargs):
            calls.append(command[1])
            if command[1] == "activate":
                events.append("activate::" + command[2])
            value = self.module.PINS[command[2]] if command[1] == "get" else "ok"
            return {"rc": 0, "stdout": value, "stderr": ""}

        headers = [{"slug": s, "version": v, "name": s, "requires_plugins": []}
                   for s, v in self.module.PINS.items()]
        with patch.object(self.module.probe, "download", side_effect=download), \
                patch.object(self.module.probe, "wp_cli", side_effect=cli), \
                patch.object(self.module.probe, "read_plugin_headers", return_value=headers), \
                contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(self.module.probe.cmd_install(args), 0)
        installs = [i for i, c in enumerate(calls) if c == "install"]
        activates = [i for i, c in enumerate(calls) if c == "activate"]
        self.assertEqual(len(installs), 10)
        self.assertEqual(len(activates), 10)
        # The approved combination is one simultaneously active set: every plugin
        # is installed before any activation starts, and nothing is deactivated to
        # simulate a clean combination.
        self.assertGreater(min(activates), max(installs))
        self.assertNotIn("deactivate", calls)
        self.assertEqual(events, ["before_activation"] + [event for slug in self.module.PINS
                                                          for event in (f"activate::{slug}",
                                                                        f"after_activation::{slug}")])
        store = self.module.probe.Store(args.store)
        self.assertEqual(sorted(store.data["facts"]["expected_active_post"]), sorted(self.APPROVED_TEN))
        self.assertFalse(self.module.blockers(store.data["checks"]))

    def identity_checks(self, name, headers, active):
        lane_slice = self.slice(name)
        with patch.object(self.module.probe, "read_plugin_headers", return_value=headers), \
                patch.object(self.module.probe, "db_active_plugins", return_value=active), \
                patch.object(self.module.probe, "scalar", return_value=(True, "8.4.11", "")), \
                patch.object(self.module.probe, "web_runtime_probe", return_value={"php_version": "8.3.30"}), \
                patch.object(self.module.probe, "run", return_value={"rc": 0, "stdout": "8.3.30"}), \
                patch.object(self.module.probe, "read_wp_core_version", return_value="7.1.3"), \
                patch.object(self.module.probe, "db_option", return_value=(True, "fa_IR", "")):
            lane_slice.identity("A")
        return lane_slice

    def test_every_exact_version_is_required_and_drift_blocks_stage_a(self):
        exact = [{"slug": s, "version": v} for s, v in self.module.PINS.items()]
        active = (True, [f"{s}/{s}.php" for s in self.module.PINS], "")
        for name, drift in (("exact", {}), ("one-drift", {"polylang": "3.8.9"}),
                            ("seo-drift", {"wordpress-seo": "28.7", "seo-by-rank-math": "1.0.281"})):
            with self.subTest(drift=drift):
                headers = [{"slug": h["slug"], "version": drift.get(h["slug"], h["version"])} for h in exact]
                lane_slice = self.identity_checks(name, headers, active)
                drifted = sorted(c["name"] for c in lane_slice.store("A").data["checks"]
                                 if c["name"].startswith("unchanged_version::") and c["status"] == "FAIL")
                self.assertEqual(drifted, sorted(f"unchanged_version::{s}" for s in drift))
                self.assertEqual(self.module.verdict(lane_slice.store("A"), True)["status"],
                                 "FAIL" if drift else "PASS")

    def test_nine_of_ten_active_is_not_the_approved_combination(self):
        exact = [{"slug": s, "version": v} for s, v in self.module.PINS.items()]
        short = (True, [f"{s}/{s}.php" for s in self.module.PINS if s != "wp-crontrol"], "")
        lane_slice = self.identity_checks("short-active", exact, short)
        checks = {c["name"]: c for c in lane_slice.store("A").data["checks"]}
        self.assertEqual(checks["exact_active_group"]["status"], "FAIL")
        result = self.module.verdict(lane_slice.store("A"), True)
        self.assertEqual(result["status"], "FAIL")
        self.assertEqual(result["first_causal_check"]["name"], "exact_active_group")

    def test_stage_a_failure_blocks_stage_b_for_the_combined_group(self):
        lane_slice = self.slice("interlock")
        store = lane_slice.store("A")
        store.check("combined_group_bootstrap", False, self.module.probe.PRODUCT, "first causal fatal")
        store.save()
        result = self.module.verdict(store, True)
        self.assertEqual(result["status"], "FAIL")
        self.assertEqual(result["first_causal_check"]["detail"], "first causal fatal")
        self.module.save(lane_slice.root / "A/result.json", result)
        with self.assertRaisesRegex(RuntimeError, "Stage B refused"):
            lane_slice.begin_b()
        self.assertFalse((lane_slice.root / "B/started.json").exists())

    def test_group_baseline_is_machine_readable_and_declares_unavailable_features(self):
        summary = json.loads((self.slice("baseline").root / "summary.json").read_text())
        self.assertEqual(summary["group"], "combined-ten")
        self.assertEqual(summary["pins"], self.APPROVED_TEN)
        self.assertEqual(summary["stage_a"]["status"], "NOT RUN")
        self.assertEqual(summary["stage_b"]["status"], "NOT RUN")
        self.assertEqual(summary["environment"], {"wordpress": "7.1.3", "php": "8.3",
                                                  "mysql": "8.4.11", "locale": "fa_IR"})
        self.assertIn("LiteSpeed Cache server-level page cache", summary["unavailable_features"])
        self.assertIn("outbound mail/MTA", summary["unavailable_features"])

    def test_finalize_keeps_stage_verdicts_separate_and_reports_unavailable_features(self):
        lane_slice = self.slice("finalize")
        store = lane_slice.store("A")
        store.check("sentinel", True, self.module.probe.PRODUCT)
        store.save()
        self.module.save(lane_slice.root / "A/result.json", self.module.verdict(store, True))
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(lane_slice.finalize({}), 1)  # Stage B NOT RUN is never PASS
        text = (lane_slice.root / "summary.md").read_text()
        self.assertIn("Stage A (CPMS absent): **PASS**", text)
        self.assertIn("Stage B (after CPMS activation): **NOT RUN**", text)
        self.assertIn("FEATURE UNAVAILABLE — LiteSpeed Cache server-level page cache", text)
        self.assertEqual(json.loads((lane_slice.root / "summary.json").read_text())["stage_b"]["status"],
                         "NOT RUN")


class CombinedNineteenGroupTests(unittest.TestCase):
    """Exact free-plugin identity and fail-closed interlock; no runtime claim."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.module = load_group("combined-nineteen")

    def test_all_active_packages_install_before_any_activation(self):
        from urllib.parse import urlparse

        calls, events = [], []
        args = argparse.Namespace(store=str(self.root / "install.json"), wp_dir="", wp_url="",
                                  db_name="fixture", package_dir=str(self.root), no_retry=True)
        args.subject_slug = self.module.SUBJECT
        args.subject_version = self.module.PINS[self.module.SUBJECT]
        args.deps = ",".join(f"{slug}={version}" for slug, version in self.module.PINS.items()
                             if slug != self.module.SUBJECT)
        args.activation_observer = lambda timing: events.append(timing)

        def download(url, dest, **_kwargs):
            name = Path(urlparse(url).path).name
            slug = next(item for item in self.module.PINS if name.startswith(item + "."))
            with zipfile.ZipFile(dest, "w") as archive:
                archive.writestr(f"{slug}/{slug}.php",
                                 f"<?php\n/*\nPlugin Name: Fixture\nVersion: {self.module.PINS[slug]}\n*/")
            return []

        def cli(command, _wp_dir, **_kwargs):
            operation = command[1]
            if operation == "install":
                package = Path(command[2]).name
                slug = next(item for item in self.module.PINS if package.startswith(item + "-"))
                calls.append((operation, slug))
                return {"rc": 0, "stdout": "ok", "stderr": ""}
            if operation == "get":
                return {"rc": 0, "stdout": self.module.PINS[command[2]], "stderr": ""}
            if operation == "activate":
                calls.append((operation, command[2]))
                events.append("activate::" + command[2])
                return {"rc": 0, "stdout": "ok", "stderr": ""}
            return {"rc": 0, "stdout": "ok", "stderr": ""}

        headers = [{"slug": slug, "version": version, "name": slug, "requires_plugins": []}
                   for slug, version in self.module.PINS.items()]
        with patch.object(self.module.probe, "download", side_effect=download), \
                patch.object(self.module.probe, "wp_cli", side_effect=cli), \
                patch.object(self.module.probe, "read_plugin_headers", return_value=headers), \
                contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(self.module.probe.cmd_install(args), 0)

        installs = [index for index, call in enumerate(calls) if call[0] == "install"]
        activates = [index for index, call in enumerate(calls) if call[0] == "activate"]
        self.assertEqual(len(installs), 18)
        self.assertEqual(len(activates), 18)
        self.assertEqual(len(self.module.PINS), 18)
        self.assertGreater(min(activates), max(installs))
        active_order = [calls[index][1] for index in activates]
        self.assertEqual(active_order[0], "woocommerce")
        self.assertEqual(active_order[-1], "contact-form-7")
        # Quarantined by the Phase 19 decision: never installed nor activated in
        # this active scenario; its independent lanes own that measurement.
        self.assertNotIn("persian-woocommerce", active_order)
        self.assertLess(active_order.index("woocommerce"), active_order.index("persian-woocommerce-sms"))
        self.assertLess(active_order.index("elementor"), active_order.index("persian-elementor"))
        self.assertNotIn("deactivate", [call[0] for call in calls])
        self.assertEqual(events[0], "before_activation")
        self.assertEqual(events[-1], "after_activation::contact-form-7")
        store = self.module.probe.Store(args.store)
        self.assertEqual(set(store.data["facts"]["expected_active_post"]), set(self.module.PINS))
        self.assertFalse(self.module.blockers(store.data["checks"]))

    def identity_slice(self, name, headers, active):
        lane_slice = self.module.Slice(self.root / name, str(self.root / "wp"),
                                       "http://example.test", "fixture")
        lane_slice.init()
        with patch.object(self.module.probe, "read_plugin_headers", return_value=headers), \
                patch.object(self.module.probe, "db_active_plugins", return_value=active), \
                patch.object(self.module.probe, "scalar", return_value=(True, "8.4.11", "")), \
                patch.object(self.module.probe, "web_runtime_probe", return_value={
                    "php_version": "8.3.30", "transport_error": ""}), \
                patch.object(self.module.probe, "run", return_value={"rc": 0, "stdout": "8.3.30", "stderr": ""}), \
                patch.object(self.module.probe, "read_wp_core_version", return_value="7.1.3"), \
                patch.object(self.module.probe, "db_option", return_value=(True, "fa_IR", "")):
            lane_slice.identity("A")
        return lane_slice

    def test_identity_requires_exactly_all_active_plugins_at_exact_versions(self):
        exact = [{"slug": slug, "version": version} for slug, version in self.module.PINS.items()]
        active = (True, [f"{slug}/{slug}.php" for slug in self.module.PINS], "")
        exact_slice = self.identity_slice("exact", exact, active)
        self.assertEqual(self.module.verdict(exact_slice.store("A"), True)["status"], "PASS")

        omitted = (True, [f"{slug}/{slug}.php" for slug in self.module.PINS
                          if slug != "persian-woocommerce-sms"], "")
        omitted_slice = self.identity_slice("omitted", exact, omitted)
        omitted_checks = {item["name"]: item for item in omitted_slice.store("A").data["checks"]}
        self.assertEqual(omitted_checks["exact_active_group"]["status"], "FAIL")

        # The active set must also fail closed if the quarantined plugin somehow
        # reappears: exclusion is enforced by the exact identity, not by luck.
        quarantined = (True, [f"{slug}/{slug}.php" for slug in self.module.PINS]
                       + ["persian-woocommerce/persian-woocommerce.php"], "")
        quarantine_slice = self.identity_slice("quarantined-active", exact, quarantined)
        quarantine_checks = {item["name"]: item for item in quarantine_slice.store("A").data["checks"]}
        self.assertEqual(quarantine_checks["exact_active_group"]["status"], "FAIL")
        self.assertEqual(self.module.verdict(quarantine_slice.store("A"), True)["status"], "FAIL")

        drifted = [{"slug": item["slug"],
                    "version": "7.9.0" if item["slug"] == "litespeed-cache" else item["version"]}
                   for item in exact]
        drift_slice = self.identity_slice("drift", drifted, active)
        drift_checks = {item["name"]: item for item in drift_slice.store("A").data["checks"]}
        self.assertEqual(drift_checks["unchanged_version::litespeed-cache"]["status"], "FAIL")
        self.assertEqual(self.module.verdict(drift_slice.store("A"), True)["status"], "FAIL")

    def test_material_stage_a_failure_refuses_b_and_preserves_first_cause(self):
        lane_slice = self.module.Slice(self.root / "interlock", str(self.root / "wp"),
                                       "http://example.test", "fixture")
        lane_slice.init()
        store = lane_slice.store("A")
        store.check("persian_woocommerce_front_redirect_loop", False,
                    self.module.probe.PRODUCT, "first observed causal failure")
        store.check("downstream_browser", False, self.module.probe.PRODUCT, "later finding")
        store.save()
        result = self.module.verdict(store, True)
        self.assertEqual(result["first_causal_check"]["name"],
                         "persian_woocommerce_front_redirect_loop")
        self.module.save(lane_slice.root / "A/result.json", result)
        with self.assertRaisesRegex(RuntimeError, "Stage B refused"):
            lane_slice.begin_b()
        self.assertFalse((lane_slice.root / "B/started.json").exists())

    def test_stage_a_failure_visibility_is_bounded_sanitized_and_keeps_failure(self):
        lane_slice = self.module.Slice(self.root / "visibility", str(self.root / "wp"),
                                       "http://example.test", "fixture")
        lane_slice.init()
        sensitive = ("Authorization: Bearer test-token-DO-NOT-EMIT; Cookie=session-DO-NOT-EMIT; "
                     "https://user:pass@example.invalid/?patient=Jane-Doe")
        store = lane_slice.store("A")
        store.check("mysql_full_version_exact", False, self.module.probe.ENVIRONMENT,
                    sensitive, expected="8.4.11", observed="8.4.10")
        store.check("unchanged_version::woocommerce", False, self.module.probe.HARNESS,
                    sensitive, expected="11.2.0", observed=["11.1.9"])
        store.unexecuted("browser_session_check", self.module.probe.HARNESS, sensitive, "post")
        store.save()
        result = self.module.verdict(store, True)
        self.assertEqual(result["status"], "FAIL")
        self.module.save(lane_slice.root / "A/result.json", result)

        summary_file = self.root / "github-step-summary.md"
        annotations = []
        with patch.dict(os.environ, {"GITHUB_STEP_SUMMARY": str(summary_file)}), \
                patch.object(self.module.probe, "annotate",
                             side_effect=lambda level, message: annotations.append((level, message))), \
                patch.object(lane_slice, "stage_b") as stage_b, \
                contextlib.redirect_stdout(io.StringIO()):
            exit_code = lane_slice.finalize({})

        self.assertEqual(exit_code, 1, "a material Stage A failure must remain nonzero")
        stage_b.assert_not_called()
        self.assertFalse((lane_slice.root / "B/started.json").exists())
        summary = (lane_slice.root / "summary.md").read_text()
        step_summary = summary_file.read_text()
        self.assertIn("Stage A blocker visibility", summary)
        self.assertIn("group=combined-nineteen", annotations[0][1])
        # annotations[0] is the preserved Stage A blocker `error`; annotations[1]
        # is the new single per-lane verdict annotation. No third annotation may
        # appear, so the lane stays one concise report instead of comment spam.
        self.assertEqual(len(annotations), 2)
        self.assertEqual(annotations[0][0], "error")
        self.assertEqual(annotations[1][0], "error",
                         "a FAIL stage must never be annotated below error level")
        self.assertIn("Stage A (CPMS absent)=FAIL", annotations[1][1])
        self.assertIn("Stage B (CPMS active)=NOT RUN", annotations[1][1])
        self.assertIn("mysql_full_version_exact", annotations[1][1])
        visible = ("\n".join(message for _, message in annotations)
                   + "\n" + summary + "\n" + step_summary)
        for marker in ("Stage A=FAIL", "Stage B=NOT RUN", "mysql_full_version_exact",
                       "unchanged_version::woocommerce", "browser_session_check",
                       "UNEXECUTED (blocks)", "expected=8.4.11; observed=8.4.10"):
            self.assertIn(marker, visible)
        for forbidden in ("test-token-DO-NOT-EMIT", "session-DO-NOT-EMIT", "Authorization:",
                          "Cookie=", "example.invalid", "patient=Jane-Doe", "Jane-Doe"):
            self.assertNotIn(forbidden, visible)
        self.assertLessEqual(len(annotations[0][1]), self.module._STAGE_A_ANNOTATION_LIMIT)
        preserved = self.module.load(lane_slice.root / "A/result.json")
        self.assertEqual(preserved["blocking_checks"][0]["detail"], sensitive)
        self.assertEqual(preserved["blocking_checks"][2]["observed"], sensitive[:400])
        self.assertIn("Stage B (after CPMS activation): **NOT RUN**", summary)

    def test_visibility_caps_annotation_and_ordered_summary_for_many_blockers(self):
        store = self.module.probe.Store(str(self.root / "many-evidence.json"))
        for index in range(50):
            store.check(f"synthetic_blocker_{index:02}", False, self.module.probe.PRODUCT)
        result = self.module.verdict(store, True)
        visibility = self.module.stage_a_failure_visibility(result, "NOT RUN")

        self.assertIsNotNone(visibility)
        self.assertLessEqual(len(visibility["annotation"]), self.module._STAGE_A_ANNOTATION_LIMIT)
        self.assertLessEqual(len(visibility["markdown"]), self.module._STAGE_A_SUMMARY_LIMIT)
        self.assertIn("ordered_blockers(50)", visibility["annotation"])
        self.assertIn("synthetic_blocker_00", visibility["annotation"])
        self.assertIn("synthetic_blocker_01", visibility["annotation"])
        self.assertLess(visibility["annotation"].index("synthetic_blocker_00"),
                        visibility["annotation"].index("synthetic_blocker_01"))
        self.assertIn("synthetic_blocker_00", visibility["markdown"])
        self.assertIn("synthetic_blocker_31", visibility["markdown"])
        self.assertNotIn("synthetic_blocker_32", visibility["markdown"])
        self.assertIn("18 additional blockers omitted", visibility["markdown"])
        self.assertIn("full ordered list remains in `A/result.json`", visibility["markdown"])

    def test_healthy_stage_a_and_b_keep_pass_result_without_failure_annotation(self):
        # A healthy lane must not gain a failure annotation. It does gain exactly
        # one `notice` verdict annotation, because the whole point of the change is
        # that the real verdict and the NOT RUN scope limits are discoverable from
        # the check interface without opening an artifact.
        lane_slice = self.module.Slice(self.root / "healthy", str(self.root / "wp"),
                                       "http://example.test", "fixture")
        lane_slice.init()
        store = lane_slice.store("A")
        store.check("sentinel", True, self.module.probe.PRODUCT)
        result = self.module.verdict(store, True)
        self.assertEqual(result["status"], "PASS")
        self.module.save(lane_slice.root / "A/result.json", result)
        self.module.save(lane_slice.root / "B/started.json", {"stage_a": "PASS"})
        annotations = []
        summary_file = self.root / "healthy-step-summary.md"
        with patch.dict(os.environ, {"GITHUB_STEP_SUMMARY": str(summary_file)}), \
                patch.object(self.module.probe, "annotate",
                             side_effect=lambda level, message: annotations.append((level, message))), \
                patch.object(lane_slice, "stage_b", return_value={"status": "PASS"}) as stage_b, \
                contextlib.redirect_stdout(io.StringIO()):
            exit_code = lane_slice.finalize({})

        self.assertEqual(exit_code, 0)
        stage_b.assert_called_once_with({})
        self.assertEqual([level for level, _ in annotations], ["notice"],
                         "a healthy lane must emit exactly one notice, never error/warning")
        verdict_text = annotations[0][1]
        self.assertIn("group=combined-nineteen", verdict_text)
        self.assertIn("Stage A (CPMS absent)=PASS", verdict_text)
        self.assertIn("Stage B (CPMS active)=PASS", verdict_text)
        self.assertIn("NOT RUN scope limits=", verdict_text)
        self.assertIn("workflow success is not product acceptance", verdict_text)
        self.assertNotIn("=FAIL", verdict_text)
        self.assertLessEqual(len(verdict_text), 1600)
        healthy_summary = summary_file.read_text()
        self.assertIn("Stage A (CPMS absent): **PASS**", healthy_summary)
        self.assertIn("Stage B (after CPMS activation): **PASS**", healthy_summary)
        self.assertNotIn("Stage A blocker visibility", healthy_summary)
        self.assertIn("coexistence_verdict group=`combined-nineteen`", healthy_summary)


class PermissionDiagnosisTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.module = load_group("persian-woocommerce-diagnosis")
        self.slice = self.module.Slice(self.root / "diagnosis", str(self.root / "wp"),
                                       "http://example.test", "fixture")
        self.slice.init()

    def test_phase_parser_separates_php_warnings_from_material_fatals(self):
        result = self.module.php_diagnostic_summary(
            "PHP Warning: permission warning\nDeprecated: old call\nPHP Fatal error: redirect bootstrap failed")
        self.assertEqual(result["warning_count"], 2)
        self.assertEqual(result["fatal_count"], 1)
        self.assertIn("PHP Fatal error: redirect bootstrap failed", result["fatal_samples"])

    def test_third_party_failure_is_not_automatically_classified_as_d(self):
        store = self.slice.store("A")
        store.check("third_party_activation_redirect_loop", False,
                    self.module.probe.PRODUCT, "bounded canonical chain evidence")
        store.save()
        with patch.object(self.slice, "log_bytes", return_value={"debug": b"", "apache": b""}), \
                contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(self.slice.end_a(True), 0)
        result = self.module.load(self.slice.root / "A/result.json")
        self.assertEqual(result["status"], "FAIL")
        self.assertTrue(result["measurement_complete"])
        self.assertIsNone(result["failure_category"])
        summary = self.module.load(self.slice.root / "summary.json")
        self.assertIn("Permission diagnosis only", summary["claim"])

    def test_stage_b_is_prohibited_even_after_a_forged_healthy_stage_a(self):
        store = self.slice.store("A")
        store.check("all_required_probes", True, self.module.probe.PRODUCT)
        store.save()
        self.module.save(self.slice.root / "A/result.json", self.module.verdict(store, True))
        with self.assertRaisesRegex(RuntimeError, "Stage B prohibited"):
            self.slice.begin_b()
        self.assertFalse((self.slice.root / "B/started.json").exists())

    def test_permission_experiment_is_not_run_or_mutated_when_not_required(self):
        plugins = self.root / "wp/wp-content/plugins/persian-woocommerce"
        plugins.mkdir(parents=True)
        marker = plugins / ".activated"
        marker.write_text("canonical-marker")
        before = marker.stat().st_mode
        snapshot = {"sentinels": [], "sentinel_exists": False}
        result = self.slice.run_permission_layout_experiment(snapshot)
        self.assertEqual(result["status"], "NOT RUN")
        self.assertFalse(result["canonical_results_substituted"])
        self.assertEqual(marker.read_text(), "canonical-marker")
        self.assertEqual(marker.stat().st_mode, before)
        self.assertFalse((self.root / "wp-permission-experiment").exists())

    def test_required_permission_experiment_changes_only_cloned_directory_and_cleans_up(self):
        canonical_wp = Path(self.slice.wp_dir)
        plugin_dir = canonical_wp / "wp-content/plugins/persian-woocommerce"
        plugin_dir.mkdir(parents=True)
        plugin_dir.chmod(0o755)
        marker = plugin_dir / ".activated"
        marker.write_text("canonical marker")
        marker.chmod(0o640)
        (canonical_wp / "wp-config.php").write_text(
            "<?php\ndefine( 'DB_NAME', 'fixture' );\n", encoding="utf-8")
        (canonical_wp / "wp-content/debug.log").write_text("before\n", encoding="utf-8")
        canonical_mode = plugin_dir.stat().st_mode
        canonical_marker = marker.read_bytes()
        snapshot = self.slice.sentinel_snapshot()
        self.slice._diagnostic["canonical_unlink_test"] = {"can_remove_sentinel": False}
        gid = os.getgid()
        self.slice._diagnostic["web_server_identity"] = {
            "effective_user": "www-data", "effective_gid": gid,
        }
        clone_wp = canonical_wp.with_name(canonical_wp.name + "-permission-experiment")
        commands, tee_inputs, database_sql = [], [], []

        def fake_run(command, **_kwargs):
            commands.append(command)
            if len(command) >= 3 and command[1:3] == ["cp", "-a"]:
                shutil.copytree(command[-2], command[-1], copy_function=shutil.copy2)
            elif command[:2] == ["mysql", "-h"] and "-e" in command:
                statement = command[command.index("-e") + 1]
                database_sql.append(statement)
            elif command[:2] == ["sudo", "cat"] and command[-1] == "/etc/apache2/ports.conf":
                return {"rc": 0, "stdout": "Listen 8080\nListen 8081\n", "stderr": ""}
            elif command[:2] == ["sudo", "cat"]:
                return {"rc": 0, "stdout": "", "stderr": ""}
            elif "chgrp" in command:
                os.chown(command[-1], -1, int(command[-2]))
            elif "chmod" in command:
                os.chmod(command[-1], int(command[command.index("--") + 1], 8))
            elif "install" in command:
                target = Path(command[-1])
                target.write_bytes(b"")
                target.chmod(int(command[command.index("-m") + 1], 8))
            elif "-u" in command and "rm" in command:
                Path(command[-1]).unlink(missing_ok=True)
            elif "rm" in command and "-rf" in command:
                shutil.rmtree(command[-1], ignore_errors=False)
            elif "rm" in command and "-f" in command and "arena-unlink-canary" in command[-1]:
                Path(command[-1]).unlink(missing_ok=True)
            return {"rc": 0, "stdout": "", "stderr": ""}

        def fake_subprocess_run(command, **kwargs):
            if command[0] == "mysqldump":
                return subprocess.CompletedProcess(command, 0, b"fixture dump", b"")
            if command[0] == "mysql":
                return subprocess.CompletedProcess(command, 0, b"", b"")
            if command[:2] == ["sudo", "tee"]:
                tee_inputs.append((command, kwargs.get("input", "")))
                target = command[-1]
                if target == str(clone_wp / "wp-config.php"):
                    Path(target).write_text(kwargs["input"], encoding="utf-8")
                return subprocess.CompletedProcess(command, 0, kwargs.get("input", ""), "")
            raise AssertionError(f"unexpected subprocess: {command}")

        response = lambda url, timeout=20: {"request_url": url, "status": 200,
                                             "location": "", "content_type": "text/html",
                                             "transport_error": ""}
        with patch.object(self.module.probe, "scalar", return_value=(True, "0", "")), \
                patch.object(self.module.probe, "run", side_effect=fake_run), \
                patch.object(self.module.subprocess, "run", side_effect=fake_subprocess_run), \
                patch.object(self.module, "diagnostic_http_once", side_effect=response):
            experiment = self.slice.run_permission_layout_experiment(snapshot)

        self.assertEqual(experiment["status"], "COMPLETE", experiment.get("reason"))
        self.assertFalse(experiment["canonical_results_substituted"])
        self.assertTrue(experiment["cleanup"]["all_created_resources_removed"])
        self.assertFalse(clone_wp.exists())
        self.assertEqual(plugin_dir.stat().st_mode, canonical_mode)
        self.assertEqual(marker.read_bytes(), canonical_marker)
        self.assertEqual(len(experiment["permission_changes"]), 1)
        change = experiment["permission_changes"][0]
        self.assertEqual(change["path_relative_to_cloned_wp"], "wp-content/plugins/persian-woocommerce")
        self.assertEqual(change["before"]["mode_octal"], "0755")
        self.assertEqual(change["after"]["mode_octal"], "0775")
        self.assertIn("group-write bit added", change["exact_changes"])
        self.assertTrue(change["files_changed"] is False)
        self.assertTrue(change["plugin_source_changed"] is False)
        self.assertTrue(change["canonical_environment_changed"] is False)
        self.assertTrue(change["application_authorization_or_roles_changed"] is False)
        self.assertTrue(experiment["environment"]["is_separate_disposable_clone"])
        self.assertEqual(experiment["web_unlink_test"]["can_remove_sentinel"], True)
        self.assertTrue(any("fixture_pwperm" in statement for statement in database_sql))
        self.assertTrue(any("DROP DATABASE" in statement for statement in database_sql))
        clone_mode_commands = [command for command in commands if "chmod" in command or "chgrp" in command]
        self.assertEqual(len(clone_mode_commands), 2)
        for command in clone_mode_commands:
            self.assertTrue(Path(command[-1]).is_relative_to(clone_wp))
        self.assertTrue(any("fixture_pwperm" in text for _command, text in tee_inputs))

    def test_unlink_permission_uses_disposable_canary_not_actual_sentinel(self):
        plugins = self.root / "wp/wp-content/plugins/persian-woocommerce"
        plugins.mkdir(parents=True)
        marker = plugins / ".activated"
        marker.write_text("must survive")
        marker.chmod(0o640)
        snapshot = self.slice.sentinel_snapshot()
        created_canaries = []

        def fake_run(command, **_kwargs):
            if "install" in command:
                target = Path(command[-1])
                target.write_bytes(b"")
                mode_index = command.index("-m") + 1
                target.chmod(int(command[mode_index], 8))
                created_canaries.append(target)
                return {"rc": 0, "stdout": "", "stderr": ""}
            if "-u" in command:
                return {"rc": 1, "stdout": "", "stderr": "Permission denied"}
            if "rm" in command and "-f" in command:
                Path(command[-1]).unlink(missing_ok=True)
                return {"rc": 0, "stdout": "", "stderr": ""}
            return {"rc": 0, "stdout": "", "stderr": ""}

        identity = {"effective_user": "www-data", "effective_uid": 33, "effective_gid": 33}
        with patch.object(self.module.probe, "run", side_effect=fake_run):
            observed = self.slice.test_web_user_can_remove_sentinel(snapshot, identity)
        self.assertTrue(observed["attempted"])
        self.assertFalse(observed["can_remove_sentinel"])
        self.assertTrue(observed["sentinel_preserved"])
        self.assertEqual(marker.read_text(), "must survive")
        self.assertTrue(created_canaries)
        self.assertFalse(created_canaries[0].exists())
        self.assertIn("actual .activated file was never removed", observed["method"])


class HTTPTests(unittest.TestCase):
    def test_real_redirect_evidence_and_single_attempt_failure(self):
        class Handler(BaseHTTPRequestHandler):
            requests = []
            def do_GET(self):
                self.requests.append(self.path)
                if self.path == "/redirect":
                    self.send_response(302)
                    self.send_header("Location", "/ok")
                    self.end_headers()
                else:
                    self.send_response(503 if self.path == "/fail" else 200)
                    self.end_headers()
                    self.wfile.write(b"raw body")
            def log_message(self, *args):
                pass
        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            url = f"http://127.0.0.1:{server.server_port}"
            response = lane.probe.http_request(url + "/redirect")
            self.assertEqual(response["status"], 200)
            self.assertEqual(response["body"], "raw body")
            self.assertEqual(response["chain"][0]["status"], 302)
            with tempfile.TemporaryDirectory() as tmp, patch.object(lane.probe.time, "sleep") as sleep:
                with self.assertRaises(lane.probe.RetrievalError):
                    lane.probe.download(url + "/fail", str(Path(tmp) / "pin.zip"), no_retry=True)
                sleep.assert_not_called()
            self.assertEqual(Handler.requests.count("/fail"), 1)
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_diagnostic_redirect_loop_is_bounded_and_preserves_each_http_status(self):
        module = load_group("persian-woocommerce-diagnosis")

        class Handler(BaseHTTPRequestHandler):
            requests = []
            def do_GET(self):
                self.requests.append(self.path)
                self.send_response(302)
                self.send_header("Location", "/two" if self.path == "/one" else "/one")
                self.end_headers()
            def log_message(self, *args):
                pass

        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            callbacks = []
            start = f"http://127.0.0.1:{server.server_port}/one"
            result = module.diagnostic_navigation(
                start, max_redirects=5,
                after_response=lambda index, response: callbacks.append((index, response["status"])))
            self.assertEqual(result["initial_status"], 302)
            self.assertEqual(result["final_status"], 302)
            self.assertEqual([response["status"] for response in result["responses"]], [302, 302])
            self.assertEqual(result["redirect_count"], 2)
            self.assertTrue(result["looped_or_bounded"])
            self.assertEqual(result["stopped_reason"], "redirect target revisited")
            self.assertEqual(Handler.requests, ["/one", "/two"])
            self.assertEqual(callbacks, [(0, 302), (1, 302)])
        finally:
            server.shutdown()
            server.server_close()
            thread.join()


if __name__ == "__main__":
    unittest.main()


class D3CoexistenceProbeTests(unittest.TestCase):
    """Isolated D3 fault injection: real assertions, never a vacuous green.

    These are local harness/fault-injection tests with every external command
    stubbed. They prove the probe's decision logic and its refusal paths; they are
    NOT WordPress compatibility evidence and make no runtime claim.
    """

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.module = load_group("combined-nineteen")
        self.wp = self.root / "rwp"
        self.migrations = self.wp / self.module.D3_MIGRATIONS_RELPATH
        self.migrations.mkdir(parents=True)
        (self.migrations / "2026_10_13_0027_latest.php").write_text("<?php\n")
        (self.wp / "wp-config.php").write_text(
            "<?php\ndefine( 'DB_NAME', 'cpms_acc' );\ndefine( 'DB_USER', 'root' );\n")
        self.clone = self.root / ("rwp" + self.module.D3_CLONE_SUFFIX)
        self.clone_db = "cpms_acc" + self.module.D3_DB_SUFFIX
        self.canonical = {
            "schema_migrations_rows": "31",
            "schema_migrations_versions_md5": "aaa",
            "migrations_max_version": "2026_10_13_0027",
            "cpms_table_count": "31",
            "wp_config_sha256": "cfg",
            "migrations_files": ["2026_10_13_0027_latest.php"],
        }

    def slice_for(self):
        return self.module.Slice(self.root / "lane", str(self.wp),
                                 "http://example.test", "cpms_acc")

    def install_stubs(self, cli_rc=1, cli_text="", probe_json=None, clone_recorded="0",
                      clone_max="2026_10_13_0027", fingerprint_after=None,
                      clone_exists=False, db_exists="0", cpms_active=True,
                      fail_cleanup=()):
        """Stub every external effect; return the recorded command log."""
        module = self.module
        calls = []
        # CRITICAL: `probe.annotate` prints a real GitHub Actions workflow command.
        # The `Lint` step runs this suite inside every acceptance lane, so an
        # unstubbed annotate turns a deliberately-exercised refusal path into a
        # genuine `[failure]` annotation on that lane's check run -- exactly the
        # misleading evidence this harness must never produce. Capture instead.
        self.annotations = []
        if probe_json is None:
            probe_json = {"attempted": True, "thrown": "RuntimeException: SQL",
                          "recorded_in_wp": 0}

        def db_active_plugins(_db):
            active = ["woocommerce/woocommerce.php"]
            if cpms_active:
                active.append("clinic-practice-management/clinic-practice-management.php")
            return True, sorted(active), ""

        def scalar(sql, db_name):
            calls.append(("scalar", db_name, sql))
            if "information_schema.schemata" in sql:
                return True, db_exists, ""
            if "COUNT(*)" in sql and module.D3_VERSION in sql:
                return True, clone_recorded, ""
            if "MAX(version)" in sql:
                return True, clone_max, ""
            return True, self.canonical["schema_migrations_rows"], ""

        def run(cmd, timeout=300):
            calls.append(("run", tuple(str(part) for part in cmd)))
            if cmd[:3] == ["sudo", "cp", "-a"]:
                if clone_exists:
                    return {"rc": 1, "stdout": "", "stderr": "exists"}
                shutil.copytree(self.wp, self.clone, symlinks=True)
                return {"rc": 0, "stdout": "", "stderr": ""}
            if cmd[0] == "sudo" and cmd[1] == "cat":
                return {"rc": 0, "stdout": cli_text, "stderr": ""}
            if cmd[:3] == ["sudo", "rm", "-rf"]:
                target = Path(cmd[-1])
                if target != self.clone:
                    return {"rc": 1, "stdout": "", "stderr": "refused: not the clone"}
                if "remove_clone_tree" in fail_cleanup:
                    # Simulated failure: the leftover clone must stay visible, so a
                    # later run's refusal path is what actually protects it.
                    return {"rc": 1, "stdout": "", "stderr": "simulated removal failure"}
                shutil.rmtree(target, ignore_errors=True)
                return {"rc": 0, "stdout": "", "stderr": ""}
            if cmd[:3] == ["sudo", "rm", "-f"]:
                if "remove_injected" in fail_cleanup:
                    return {"rc": 1, "stdout": "", "stderr": "simulated removal failure"}
                Path(cmd[-1]).unlink(missing_ok=True)
                return {"rc": 0, "stdout": "", "stderr": ""}
            if cmd[0] == "mysql" and any("DROP DATABASE" in str(part) for part in cmd):
                if "drop_clone_db" in fail_cleanup:
                    return {"rc": 1, "stdout": "", "stderr": "simulated DROP failure"}
                return {"rc": 0, "stdout": "", "stderr": ""}
            return {"rc": 0, "stdout": "", "stderr": ""}

        def wp_cli(args, wp_dir, timeout=300):
            calls.append(("wp_cli", tuple(args), wp_dir))
            result_path = Path(wp_dir).parent / "lane" / "D3" / "probe-result.json"
            match = re.search(r"\$path = '([^']+)'", args[1] if len(args) > 1 else "")
            probe_file = Path(args[1]) if len(args) > 1 else None
            if probe_file is not None and probe_file.is_file():
                match = re.search(r"\$path = '([^']+)'", probe_file.read_text())
            if match:
                target = Path(match.group(1))
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(json.dumps(probe_json))
            del result_path
            return {"rc": cli_rc, "stdout": cli_text, "stderr": cli_text}

        def subprocess_run(cmd, **kwargs):
            calls.append(("subprocess", tuple(str(part) for part in cmd[:4])))
            completed = subprocess.CompletedProcess(cmd, 0, stdout=b"", stderr=b"")
            if "tee" in cmd:
                Path(cmd[-1]).write_text(kwargs.get("input") or "")
            if cmd[0] == "mysqldump":
                completed.stdout = b"-- dump\n"
            return completed

        patches = [
            patch.object(module.probe, "db_active_plugins", db_active_plugins),
            patch.object(module.probe, "scalar", scalar),
            patch.object(module.probe, "run", run),
            patch.object(module.probe, "wp_cli", wp_cli),
            patch.object(module.probe, "annotate",
                         lambda level, message: self.annotations.append((level, message))),
            patch.object(module, "print", lambda *a, **k: None),
            patch.object(module, "subprocess", type("S", (), {"run": staticmethod(subprocess_run),
                                                             "CompletedProcess": subprocess.CompletedProcess})),
            patch.object(module, "d3_fingerprint",
                         side_effect=lambda db, wp: (dict(self.canonical) if wp == str(self.wp)
                                                     and db == "cpms_acc" and calls.count("fp") == 0
                                                     else dict(fingerprint_after or self.canonical))),
        ]
        # Deterministic before/after fingerprint sequencing.
        sequence = [dict(self.canonical), dict(fingerprint_after or self.canonical)]
        patches[-1] = patch.object(module, "d3_fingerprint", side_effect=lambda db, wp: sequence.pop(0))
        for item in patches:
            item.start()
            self.addCleanup(item.stop)
        return calls

    def test_probe_is_enabled_only_for_the_heaviest_group_with_stage_b(self):
        self.assertTrue(self.module.d3_enabled("combined-nineteen", True))
        for group in ("persian-five", "security-authentication", "combined-ten",
                      "persian-woocommerce-diagnosis", "persian-woocommerce-sms"):
            self.assertFalse(self.module.d3_enabled(group, True), group)
        self.assertFalse(self.module.d3_enabled("combined-nineteen", False))
        # Every other lane therefore keeps its truthful NOT RUN declaration.
        for group in ("persian-five", "combined-ten", "persian-woocommerce-sms"):
            self.assertIn("synthetic broken migration", load_group(group).NOT_RUN)

    def test_isolated_database_name_is_refused_when_unsafe(self):
        self.assertEqual(self.module.d3_isolated_db_name("cpms_acc"), "cpms_acc_d3probe")
        self.assertIsNone(self.module.d3_isolated_db_name("a" * 62))
        self.assertIsNone(self.module.d3_isolated_db_name("bad-name; DROP"))
        self.assertIsNone(self.module.d3_isolated_db_name(""))

    def test_unproven_attempt_is_never_a_pass_even_with_zero_rows(self):
        """The core anti-vacuous-green contract."""
        self.install_stubs(cli_rc=0, cli_text="", probe_json={"attempted": False,
                                                              "thrown": None,
                                                              "recorded_in_wp": None},
                           clone_recorded="0")
        result = self.slice_for().d3_probe()
        self.assertEqual(result["status"], "FAIL",
                         "zero recorded rows without a proven attempt is not a PASS")
        self.assertTrue(result["isolation_proof"],
                        "the canonical environment was still untouched")
        names = [c["name"] for c in self.module.load(
            self.root / "lane/D3/evidence.json")["checks"]]
        self.assertIn("d3_migration_attempt_proven", names)
        self.assertIn("d3_broken_version_not_recorded_direct_sql", names)
        evidence = self.module.load(self.root / "lane/D3/evidence.json")["checks"]
        attempt = next(c for c in evidence if c["name"] == "d3_migration_attempt_proven")
        self.assertEqual(attempt["status"], self.module.probe.FAIL)

    def test_attempt_proof_accepts_throwable_or_injected_marker(self):
        proven, how = self.module.d3_attempt_proven({"thrown": "RuntimeException: x"}, "", "")
        self.assertTrue(proven)
        self.assertIn("probe throwable", how)
        proven, how = self.module.d3_attempt_proven({}, "SQL: CREATE TABLE syntax broken (((", "")
        self.assertTrue(proven)
        self.assertIn("wp-cli output", how)
        proven, how = self.module.d3_attempt_proven({}, "", "2999_01_01_9999 in bootstrap")
        self.assertTrue(proven)
        self.assertIn("clone debug.log", how)
        proven, how = self.module.d3_attempt_proven({}, "unrelated plugin warning", "notice")
        self.assertFalse(proven)
        self.assertIn("never proven", how)

    def test_happy_path_passes_and_confines_injection_to_the_clone(self):
        calls = self.install_stubs(cli_rc=1, cli_text="RuntimeException: SQLSTATE[42000]")
        result = self.slice_for().d3_probe()
        self.assertEqual(result["status"], "PASS", result)
        self.assertTrue(result["isolation_proof"])
        self.assertEqual(result["canonical"]["changed_keys"], [])
        # The injected file went to the clone only, and the clone was cleaned up.
        self.assertFalse((self.wp / self.module.D3_MIGRATIONS_RELPATH
                          / self.module.D3_FILE_NAME).exists())
        self.assertFalse(self.clone.exists())
        self.assertTrue(any(kind == "run" and "rm" in str(payload) and "--" in str(payload)
                            for kind, *payload in [(c[0], c[1:]) for c in calls
                                                   if c[0] == "run"]))
        self.assertTrue(any(kind == "wp_cli" and str(self.clone) in str(payload)
                            for kind, payload in [(c[0], c[1:]) for c in calls
                                                  if c[0] == "wp_cli"]))
        # No plugin was skipped: the hook race is answered by direct SQL, not by
        # disabling WooCommerce.
        for entry in calls:
            self.assertNotIn("--skip-plugins", str(entry))

    def test_canonical_mutation_is_a_fail_not_a_warning(self):
        mutated = dict(self.canonical)
        mutated["schema_migrations_rows"] = "32"
        self.install_stubs(cli_rc=1, cli_text="2999_01_01_9999", fingerprint_after=mutated)
        result = self.slice_for().d3_probe()
        self.assertEqual(result["status"], "FAIL")
        self.assertFalse(result["isolation_proof"])
        self.assertIn("schema_migrations_rows", result["canonical"]["changed_keys"])

    def test_recorded_broken_version_is_a_product_fail(self):
        self.install_stubs(cli_rc=1, cli_text="ok", clone_recorded="1")
        result = self.slice_for().d3_probe()
        self.assertEqual(result["status"], "FAIL")
        evidence = self.module.load(self.root / "lane/D3/evidence.json")["checks"]
        recorded = next(c for c in evidence
                        if c["name"] == "d3_broken_version_not_recorded_direct_sql")
        self.assertEqual(recorded["status"], self.module.probe.FAIL)
        self.assertEqual(recorded["klass"], self.module.probe.PRODUCT)

    def test_existing_clone_path_is_refused_without_any_copy(self):
        self.clone.mkdir(parents=True)
        calls = self.install_stubs(cli_rc=1, cli_text="x")
        result = self.slice_for().d3_probe()
        self.assertEqual(result["status"], "FAIL")
        self.assertFalse(any(kind == "run" and "cp" in str(payload)
                             for kind, *payload in [(c[0], c[1:]) for c in calls
                                                    if c[0] == "run"]))
        evidence = self.module.load(self.root / "lane/D3/evidence.json")["checks"]
        self.assertTrue(any(c["status"] == self.module.probe.UNEXECUTED for c in evidence),
                        "a refusal must stay UNEXECUTED, never a silent pass")
        self.assertEqual([level for level, _ in self.annotations], ["error"],
                         "a refusal must annotate loudly, but only through the stub")
        self.assertIn("could not complete", self.annotations[0][1])

    def finalize_over_written_d3_result(self):
        """Run finalize against the D3/result.json the probe just wrote.

        Proves the end-to-end contract rather than only the probe's own return
        value: a red D3 result must turn the whole lane red and must never be
        published as coverage.
        """
        lane_slice = self.slice_for()
        lane_slice.init()
        store = lane_slice.store("A")
        store.check("sentinel", True, self.module.probe.PRODUCT)
        self.module.save(lane_slice.root / "A/result.json", self.module.verdict(store, True))
        self.module.save(lane_slice.root / "B/started.json", {"stage_a": "PASS"})
        with patch.object(lane_slice, "stage_b", return_value={"status": "PASS"}), \
                patch.object(self.module.probe, "annotate", lambda *a: None), \
                contextlib.redirect_stdout(io.StringIO()):
            exit_code = lane_slice.finalize({})
        return exit_code, self.module.load(lane_slice.root / "summary.json")

    def test_pre_existing_disposable_database_is_refused_without_touching_it(self):
        """Adversarial: a leftover disposable DB must cause a safe refusal.

        Four properties, each asserted from the recorded command/SQL log rather
        than from the result dict alone: no overwrite, no DROP of the pre-existing
        database, no mutation of the canonical database, and no false PASS.
        """
        calls = self.install_stubs(db_exists="1")
        result = self.slice_for().d3_probe()

        # --- (4) no false PASS -------------------------------------------------
        self.assertEqual(result["status"], "FAIL")
        self.assertIsNot(result["status"], "PASS")
        self.assertFalse(result["measured"], "nothing was measured, so nothing is proven")
        self.assertIn("d3_clone_db_isolation", result["blocking_checks"])
        evidence = self.module.load(self.root / "lane/D3/evidence.json")["checks"]
        refusal = next(c for c in evidence if c["name"] == "d3_clone_db_isolation")
        self.assertEqual(refusal["status"], self.module.probe.UNEXECUTED,
                         "a refusal stays UNEXECUTED; it is never reclassified as PASS")
        self.assertTrue(any(c["status"] == self.module.probe.UNEXECUTED
                            for c in self.module.blockers(evidence)))
        # End to end: the lane goes red and keeps its NOT RUN declaration.
        exit_code, summary = self.finalize_over_written_d3_result()
        self.assertEqual(exit_code, 1, "a refused D3 probe must never conclude green")
        self.assertEqual(summary["d3_probe"]["status"], "FAIL")
        self.assertFalse(summary["d3_probe"]["measured"])
        self.assertIn("synthetic broken migration", summary["not_run"],
                      "a refusal must NOT be published as D3 coverage")
        self.assertIn("refused or aborted before measuring",
                      summary["not_run"]["synthetic broken migration"])

        # --- (1) no overwrite: nothing was copied, created, dumped or restored --
        shell = [" ".join(entry[1]) for entry in calls if entry[0] == "run"]
        spawned = [" ".join(entry[1]) for entry in calls if entry[0] == "subprocess"]
        wp_cli = [entry for entry in calls if entry[0] == "wp_cli"]
        self.assertEqual(wp_cli, [], "no WP-CLI invocation may follow a refusal")
        for forbidden in ("cp -a", "CREATE DATABASE", "DROP DATABASE", "rm -rf", "rm -f"):
            self.assertFalse(any(forbidden in command for command in shell), forbidden)
        for forbidden in ("mysqldump", "tee", "mysql"):
            self.assertFalse(any(forbidden in command for command in spawned), forbidden)
        self.assertFalse(self.clone.exists(), "no disposable tree may be created")
        self.assertFalse((self.clone.parent / (self.clone.name + "-extra")).exists())

        # --- (2) no DROP of the pre-existing database -------------------------
        self.assertEqual(result["cleanup"], {},
                         "cleanup must not run at all, so the pre-existing database "
                         "cannot be dropped by a probe that never created it")
        self.assertFalse(any("DROP" in command.upper() for command in shell + spawned))

        # --- (3) no mutation of the canonical database ------------------------
        sql = [entry[2] for entry in calls if entry[0] == "scalar"]
        self.assertTrue(sql, "the refusal must still have read the schema list")
        for statement in sql:
            self.assertTrue(statement.lstrip().upper().startswith("SELECT"),
                            f"only read-only SQL may be issued: {statement}")
        for write in ("INSERT", "UPDATE", "DELETE", "DROP", "ALTER", "CREATE",
                      "TRUNCATE", "REPLACE", "RENAME"):
            for statement in sql:
                self.assertNotIn(write, statement.upper())
        # The probe never even reached the disposable schema's own tables.
        self.assertFalse(any(self.module.D3_SCHEMA_TABLE in statement for statement in sql))
        self.assertEqual(result["canonical"]["changed_keys"], [])
        self.assertTrue(result["isolation_proof"],
                        "the canonical environment is provably untouched by a refusal")
        untouched = next(c for c in evidence
                         if c["name"] == "d3_canonical_environment_untouched")
        self.assertEqual(untouched["status"], self.module.probe.PASS)

    def test_drop_database_cleanup_failure_is_material_blocking_and_cannot_pass(self):
        """Adversarial: a failed DROP DATABASE must block, even though D3 itself passed."""
        self.install_stubs(cli_rc=1, cli_text="RuntimeException: SQLSTATE[42000]",
                           fail_cleanup=("drop_clone_db",))
        result = self.slice_for().d3_probe()
        evidence = self.module.load(self.root / "lane/D3/evidence.json")["checks"]
        by_name = {c["name"]: c for c in evidence}

        # The real D3 property genuinely held...
        self.assertEqual(by_name["d3_broken_version_not_recorded_direct_sql"]["status"],
                         self.module.probe.PASS)
        self.assertEqual(by_name["d3_migration_attempt_proven"]["status"],
                         self.module.probe.PASS)
        self.assertEqual(by_name["d3_canonical_environment_untouched"]["status"],
                         self.module.probe.PASS)
        self.assertTrue(result["measured"])

        # ...and the cleanup failure still makes the whole probe FAIL.
        cleanup_check = by_name["d3_cleanup_complete"]
        self.assertEqual(cleanup_check["status"], self.module.probe.FAIL)
        self.assertTrue(cleanup_check["material"],
                        "a cleanup failure must be material, not a recorded-only warning")
        self.assertIn("d3_cleanup_complete",
                      [c["name"] for c in self.module.blockers(evidence)])
        self.assertEqual(result["status"], "FAIL")
        self.assertIn("d3_cleanup_complete", result["blocking_checks"])
        self.assertEqual(result["cleanup"]["drop_clone_db"], 1)
        self.assertEqual(result["cleanup"]["remove_clone_tree"], 0)
        self.assertFalse(self.clone.exists(), "the tree removal really did succeed")

        exit_code, summary = self.finalize_over_written_d3_result()
        self.assertEqual(exit_code, 1, "a leftover disposable database must redden the lane")
        self.assertEqual(summary["d3_probe"]["status"], "FAIL")
        # Measured, so the NOT RUN declaration is correctly dropped — but the lane
        # is red, so this can never be read as a green D3.
        self.assertNotIn("synthetic broken migration", summary["not_run"])
        self.assertIn("D3 broken-migration probe=FAIL",
                      self.module.coexistence_verdict_annotation(summary, summary["d3_probe"])[1])

    def test_clone_directory_cleanup_failure_is_material_blocking_and_cannot_pass(self):
        """Adversarial: a failed disposable-directory removal must block and stay visible."""
        self.install_stubs(cli_rc=1, cli_text=self.module.D3_VERSION,
                           fail_cleanup=("remove_clone_tree",))
        result = self.slice_for().d3_probe()
        evidence = self.module.load(self.root / "lane/D3/evidence.json")["checks"]
        by_name = {c["name"]: c for c in evidence}

        self.assertEqual(by_name["d3_broken_version_not_recorded_direct_sql"]["status"],
                         self.module.probe.PASS)
        cleanup_check = by_name["d3_cleanup_complete"]
        self.assertEqual(cleanup_check["status"], self.module.probe.FAIL)
        self.assertTrue(cleanup_check["material"])
        self.assertEqual(result["status"], "FAIL")
        self.assertIn("d3_cleanup_complete", result["blocking_checks"])
        self.assertEqual(result["cleanup"]["remove_clone_tree"], 1)
        self.assertEqual(result["cleanup"]["drop_clone_db"], 0)
        self.assertTrue(self.clone.exists(),
                        "a failed removal must leave the leftover visible, never hide it")

        exit_code, summary = self.finalize_over_written_d3_result()
        self.assertEqual(exit_code, 1)
        self.assertEqual(summary["d3_probe"]["status"], "FAIL")
        # The leftover clone is exactly what the refusal path protects against next
        # run, so the two tests are complementary rather than redundant.
        self.assertTrue(self.clone.exists())

    def test_sibling_clone_guard_rejects_malformed_suffixes_and_paths(self):
        """The replacement guard must be live where the old prefix check was dead."""
        guard = self.module.d3_assert_sibling_clone
        canonical = Path("/a/rwp")
        guard(canonical, Path("/a/rwp-d3probe"))  # the real shape must be accepted
        for suffix in ("", "/", "\\", "a/b", "a\\b", "x\0y", 3):
            with self.subTest(suffix=repr(suffix)):
                with self.assertRaises(RuntimeError):
                    guard(canonical, Path("/a/rwp-d3probe"), suffix)
        for clone in (canonical, Path("/b/rwp-d3probe"), Path("/a"),
                      Path("/a/rwp/child"), Path("/a/other-d3probe")):
            with self.subTest(clone=str(clone)):
                with self.assertRaises(RuntimeError):
                    guard(canonical, clone)
        with self.assertRaises(RuntimeError):
            guard("/a/rwp", "/a/rwp-d3probe")  # non-Path operands are refused too

    def test_clone_guard_is_re_asserted_at_the_destructive_call_site(self):
        """The old code checked the path once and then ran `rm -rf` on it unchecked."""
        self.install_stubs(cli_rc=1, cli_text="RuntimeException: x")
        real = self.module.d3_assert_sibling_clone
        invocations = []

        def counting(canonical, clone, suffix=None):
            invocations.append((str(canonical), str(clone)))
            return real(canonical, clone, suffix)

        with patch.object(self.module, "d3_assert_sibling_clone", counting):
            result = self.slice_for().d3_probe()
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(len(invocations), 2,
                         "once where the path is computed, once immediately before `rm -rf`")
        self.assertEqual(invocations[0], invocations[1])
        resolved = self.wp.resolve()
        expected_clone = str(resolved.with_name(resolved.name + self.module.D3_CLONE_SUFFIX))
        self.assertTrue(all(clone == expected_clone for _, clone in invocations), invocations)
        self.assertTrue(all(canonical == str(resolved) for canonical, _ in invocations),
                        invocations)

    def test_inactive_cpms_keeps_the_probe_not_run(self):
        self.install_stubs(cpms_active=False)
        result = self.slice_for().d3_probe()
        self.assertEqual(result["status"], "NOT RUN")
        self.assertIn("not active", result["reason"])

    def test_finalize_drops_the_not_run_claim_only_when_the_probe_really_ran(self):
        lane_slice = self.slice_for()
        lane_slice.init()
        store = lane_slice.store("A")
        store.check("sentinel", True, self.module.probe.PRODUCT)
        self.module.save(lane_slice.root / "A/result.json", self.module.verdict(store, True))
        self.module.save(lane_slice.root / "B/started.json", {"stage_a": "PASS"})
        self.module.save(lane_slice.root / "D3/result.json",
                         {"status": "PASS", "measured": True, "isolation_proof": True,
                          "injected_version": self.module.D3_VERSION,
                          "canonical": {"changed_keys": []},
                          "attempt_proof": "probe throwable recorded"})
        with patch.object(lane_slice, "stage_b", return_value={"status": "PASS"}), \
                contextlib.redirect_stdout(io.StringIO()):
            exit_code = lane_slice.finalize({})
        self.assertEqual(exit_code, 0)
        summary = self.module.load(lane_slice.root / "summary.json")
        self.assertNotIn("synthetic broken migration", summary["not_run"])
        self.assertEqual(summary["d3_probe"]["status"], "PASS")
        self.assertTrue(summary["d3_probe"]["isolation_proof"])
        self.assertIn("D3 synthetic broken-migration probe", (lane_slice.root / "summary.md").read_text())

        # A probe that never ran must leave the NOT RUN declaration untouched.
        other = self.module.Slice(self.root / "lane2", str(self.wp), "http://example.test", "cpms_acc")
        other.init()
        store = other.store("A")
        store.check("sentinel", True, self.module.probe.PROBE if hasattr(self.module.probe, "PROBE")
                    else self.module.probe.PRODUCT)
        self.module.save(other.root / "A/result.json", self.module.verdict(store, True))
        self.module.save(other.root / "B/started.json", {"stage_a": "PASS"})
        self.module.save(other.root / "D3/result.json", {"status": "NOT RUN", "reason": "no"})
        with patch.object(other, "stage_b", return_value={"status": "PASS"}), \
                contextlib.redirect_stdout(io.StringIO()):
            other.finalize({})
        self.assertIn("synthetic broken migration",
                      self.module.load(other.root / "summary.json")["not_run"])

    def test_a_failed_d3_probe_turns_the_lane_red(self):
        lane_slice = self.slice_for()
        lane_slice.init()
        store = lane_slice.store("A")
        store.check("sentinel", True, self.module.probe.PRODUCT)
        self.module.save(lane_slice.root / "A/result.json", self.module.verdict(store, True))
        self.module.save(lane_slice.root / "B/started.json", {"stage_a": "PASS"})
        self.module.save(lane_slice.root / "D3/result.json",
                         {"status": "FAIL", "measured": True, "isolation_proof": False,
                          "canonical": {"changed_keys": []}, "blocking_checks": ["x"]})
        with patch.object(lane_slice, "stage_b", return_value={"status": "PASS"}), \
                contextlib.redirect_stdout(io.StringIO()):
            exit_code = lane_slice.finalize({})
        self.assertEqual(exit_code, 1, "a red D3 probe must never be published as green")


class VerdictAnnotationTests(unittest.TestCase):
    """One bounded, sanitized verdict annotation per lane — never a fake PASS."""

    def setUp(self):
        self.module = load_group("combined-nineteen")

    def test_pass_with_not_run_scope_stays_notice_and_lists_limits(self):
        summary = {"stage_a": {"status": "PASS", "measurement_complete": True},
                   "stage_b": {"status": "PASS"},
                   "not_run": {"Clinic A/B isolation": "NOT RUN — one Clinic"},
                   "unavailable_features": {"LiteSpeed Cache server-level page cache": "x"}}
        level, text = self.module.coexistence_verdict_annotation(summary)
        self.assertEqual(level, "notice")
        self.assertIn("Stage A (CPMS absent)=PASS", text)
        self.assertIn("Stage B (CPMS active)=PASS", level + text)
        self.assertIn("NOT RUN scope limits=1", text)
        self.assertIn("Clinic A/B isolation", text)
        self.assertIn("FEATURE UNAVAILABLE=1", text)
        self.assertIn("workflow success is not product acceptance", text)
        self.assertNotIn("=FAIL", text)

    def test_failed_stage_is_annotated_at_error_level(self):
        summary = {"stage_a": {"status": "FAIL", "first_causal_check":
                               {"name": "mysql_full_version_exact", "status": "FAIL",
                                "material": True, "detail": "secret-token DO-NOT-EMIT"}},
                   "stage_b": {"status": "NOT RUN"}, "not_run": {}, "unavailable_features": {}}
        level, text = self.module.coexistence_verdict_annotation(summary)
        self.assertEqual(level, "error")
        self.assertIn("Stage A (CPMS absent)=FAIL", text)
        self.assertIn("first_causal_check=mysql_full_version_exact", text)
        self.assertNotIn("DO-NOT-EMIT", text, "free-text detail must never be emitted")

    def test_unknown_or_missing_verdicts_never_render_as_pass(self):
        for summary in ({}, {"stage_a": None, "stage_b": None},
                        {"stage_a": {"status": "PASS"}, "stage_b": {"status": "green"}}):
            level, text = self.module.coexistence_verdict_annotation(summary)
            self.assertIn(level, {"warning", "error"})
            self.assertIn("UNKNOWN", text)
            self.assertNotIn("Stage B (CPMS active)=PASS", text)

    def test_annotation_is_bounded_and_carries_the_d3_verdict(self):
        summary = {"stage_a": {"status": "PASS"}, "stage_b": {"status": "PASS"},
                   "not_run": {f"limit {index}": "x" for index in range(40)},
                   "unavailable_features": {}}
        d3 = {"status": "PASS", "isolation_proof": True}
        level, text = self.module.coexistence_verdict_annotation(summary, d3)
        self.assertIn("D3 broken-migration probe=PASS", text)
        self.assertLessEqual(len(text), 1600)
        self.assertIn("NOT RUN scope limits=40", text)
        self.assertIn("+", text, "omitted NOT RUN entries must stay visible as a count")
        d3_unproven = {"status": "PASS", "isolation_proof": False}
        _, text = self.module.coexistence_verdict_annotation(summary, d3_unproven)
        self.assertIn("isolation proof incomplete", text)
