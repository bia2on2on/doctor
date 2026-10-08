"""Local harness tests/fault injection, NOT real WordPress compatibility proof."""
import argparse
import contextlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import io
import json
import os
from pathlib import Path
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
with patch.dict(os.environ, {"COEX_GROUP": "persian-five"}):
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


def load_group(group):
    """Fresh run.py module bound to one COEX_GROUP (the workflow's selector).
    group=None loads with COEX_GROUP unset, exercising the real default."""
    with patch.dict(os.environ, {}):
        if group is None:
            os.environ.pop("COEX_GROUP", None)
        else:
            os.environ["COEX_GROUP"] = group
        spec = importlib.util.spec_from_file_location(f"coexistence_{group}", Path(__file__).with_name("run.py"))
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
    return module


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


if __name__ == "__main__":
    unittest.main()
