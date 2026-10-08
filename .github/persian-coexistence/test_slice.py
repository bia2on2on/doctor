"""Local harness tests/fault injection, NOT real WordPress compatibility proof."""
import contextlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import patch
import zipfile

SPEC = importlib.util.spec_from_file_location("coexistence", Path(__file__).with_name("run.py"))
lane = importlib.util.module_from_spec(SPEC)
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
