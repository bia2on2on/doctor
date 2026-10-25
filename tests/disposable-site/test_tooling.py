#!/usr/bin/env python3
"""Offline fail-closed checks for the external disposable-site tooling."""

import ast
import json
import os
from pathlib import Path
import secrets
import struct
import subprocess
import sys
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
TOOL = ROOT / "tests/disposable-site"
PACKAGE_CHECKER = TOOL / "assert-package.py"
EVIDENCE_CHECKER = TOOL / "scan-evidence.py"
BASELINE_SHA = "28435d28febb6a7da67afceacc58350ffd75c5c0"


class PackageProofTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.source = self.root / "source"
        self.source.mkdir()
        for name in ("clinic-practice-management.php", "README.md", "THIRD-PARTY-NOTICES.md", "uninstall.php", "bin/cpms", "src/Bootstrap/App.php", "src/Frontend/PublicBookingShortcode.php", "assets/js/app.js", "templates/portal.php"):
            path = self.source / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("<?php // baseline " + name + "\n", encoding="utf-8")
        self.zip = self.root / "candidate.zip"
        self.output = self.root / "proof.json"
        self.make_zip()

    def tearDown(self):
        self.temp.cleanup()

    def make_zip(self, extra=None, mutate=None, secret=b""):
        with zipfile.ZipFile(self.zip, "w") as archive:
            for path in self.source.rglob("*"):
                if path.is_file():
                    data = path.read_bytes()
                    if mutate and path.name == "App.php":
                        data += mutate
                    archive.writestr("clinic-practice-management/" + path.relative_to(self.source).as_posix(), data)
            archive.writestr("clinic-practice-management/vendor/autoload.php", "<?php return [];\n")
            if extra:
                archive.writestr(extra, b"DemoService" + secret)

    def run_checker(self, *extra):
        return subprocess.run([
            sys.executable, str(PACKAGE_CHECKER), "--zip", str(self.zip),
            "--baseline-source", str(self.source), "--baseline-sha", BASELINE_SHA,
            "--output", str(self.output), *extra,
        ], capture_output=True, text=True, timeout=20)

    def test_exact_baseline_zip_passes(self):
        result = self.run_checker()
        self.assertEqual(result.returncode, 0, result.stderr)
        proof = json.loads(self.output.read_text())
        self.assertTrue(proof["ok"])
        self.assertFalse(proof["generated_secrets_absent"])

    def test_demo_file_fails(self):
        self.make_zip(extra="clinic-practice-management/src/Application/Demo/DemoService.php")
        result = self.run_checker()
        self.assertNotEqual(result.returncode, 0)

    def test_production_behavior_difference_fails(self):
        self.make_zip(mutate=b"// cpms_otp_session_authorized")
        result = self.run_checker()
        self.assertNotEqual(result.returncode, 0)

    def test_generated_credential_in_zip_fails(self):
        password = "a" * 48
        self.make_zip(extra="clinic-practice-management/src/Fixture.php", secret=password.encode())
        state = self.root / "credentials.json"
        state.write_text(json.dumps({"accounts": {key: {"username": key, "password": password if key == "doctor" else key * 48} for key in ("organization_manager", "clinic_manager", "doctor", "receptionist", "accountant", "patient")}}))
        os.chmod(state, 0o600)
        result = self.run_checker("--secret-file", str(state))
        self.assertNotEqual(result.returncode, 0)


class EvidenceProofTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.evidence = self.root / "evidence"
        self.evidence.mkdir()
        self.state = self.root / "state.json"
        self.manifest = self.root / "manifest.json"
        names = ("organization_manager", "clinic_manager", "doctor", "receptionist", "accountant", "patient")
        accounts = {name: {"username": "u-" + secrets.token_hex(12), "password": secrets.token_hex(24)} for name in names}
        self.admin_password = secrets.token_urlsafe(30)
        self.state.write_text(json.dumps({"accounts": accounts}), encoding="utf-8")
        self.manifest.write_text(json.dumps({"admin_password": self.admin_password, "code": f"{secrets.randbelow(1000000):06d}"}), encoding="utf-8")
        os.chmod(self.state, 0o600)
        os.chmod(self.manifest, 0o600)

    def tearDown(self):
        self.temp.cleanup()

    def run_checker(self, *extra):
        return subprocess.run([
            sys.executable, str(EVIDENCE_CHECKER), "--root", str(self.evidence),
            "--state", str(self.state), "--secret-file", str(self.manifest),
            "--require-secret-count", "14", "--output", str(self.root / "report.json"), *extra,
        ], capture_output=True, text=True, timeout=60)

    def add_screenshots(self):
        directory = self.evidence / "screenshots"
        directory.mkdir()
        for name, width in (("disposable-booking-receipt.png", 390), ("disposable-patient-mobile.png", 390), ("disposable-management-mobile.png", 390), ("disposable-doctor-complete.png", 1280), ("disposable-finance-diagnostics.png", 1280)):
            (directory / name).write_bytes(b"\x89PNG\r\n\x1a\n" + struct.pack(">I", 13) + b"IHDR" + struct.pack(">II", width, 900))

    def test_commit_and_evidence_exclusion_passes(self):
        self.add_screenshots()
        result = self.run_checker("--source-ref", "HEAD")
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads((self.root / "report.json").read_text())
        self.assertEqual(report["secret_values_checked"], 14)
        self.assertEqual(report["source_scan"], "PASS")
        self.assertEqual(report["credential_leaks"], 0)

    def test_evidence_leak_fails_without_echoing_secret(self):
        (self.evidence / "leak.txt").write_text(self.admin_password, encoding="utf-8")
        result = self.run_checker()
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn(self.admin_password, result.stderr)


class StaticBoundaryTest(unittest.TestCase):
    def test_no_wordpress_auto_registration(self):
        for path in (TOOL / "wp-cli").glob("*.php"):
            source = path.read_text(encoding="utf-8")
            for registration in ("add_action(", "add_filter(", "add_shortcode(", "register_rest_route("):
                self.assertNotIn(registration, source, f"{path.name} registers {registration}")
            self.assertNotIn("define('CPMS_DEV_OTP_ECHO'", source)
            self.assertNotIn('define("CPMS_DEV_OTP_ECHO"', source)

    def test_browser_context_and_secret_boundaries(self):
        source = (TOOL / "browser-acceptance.py").read_text(encoding="utf-8")
        tree = ast.parse(source)
        open_account = next(node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == "open_account")
        calls = [ast.unparse(node) for node in ast.walk(open_account) if isinstance(node, ast.Call)]
        self.assertTrue(any("browser.new_context" in call for call in calls))
        self.assertTrue(any("context.cookies" in call for call in calls))
        self.assertNotIn("ADMIN_PASS", source)
        self.assertNotIn("DemoService", source)
        self.assertNotIn("data-demo-account", source)
        self.assertIn("protected_json(OTP_FILE)", source)
        self.assertIn("os.unlink(OTP_FILE)", source)

    def test_browser_php_eval_and_dialog_contracts(self):
        source = (TOOL / "browser-acceptance.py").read_text(encoding="utf-8")
        tree = ast.parse(source)
        for node in ast.walk(tree):
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id == "wp_eval" and node.args and isinstance(node.args[0], ast.Constant):
                # WP-CLI eval() receives these raw strings verbatim. Two
                # namespace slashes would be a PHP parse error, not escaping.
                self.assertNotIn("\\\\", node.args[0].value)
        self.assertIn("dialog.accept()", source)
        self.assertIn('data-invoice-id="{invoice_id}"', source)

    def test_workflow_and_sms_schema_regressions(self):
        workflow = (ROOT / ".github/workflows/disposable-site-acceptance.yml").read_text(encoding="utf-8")
        for line in workflow.splitlines():
            if "${APACHE_LOG_DIR}" in line:
                prefix = line.split("${APACHE_LOG_DIR}", 1)[0]
                self.assertTrue(prefix.endswith("\\"), line)
                self.assertFalse(prefix.endswith("\\\\"), line)
        verify = (TOOL / "wp-cli/verify.php").read_text(encoding="utf-8")
        self.assertNotIn("provider, consumed_at FROM", verify)
        otp = (TOOL / "wp-cli/otp.php").read_text(encoding="utf-8")
        self.assertIn("consumed_at IS NULL", otp)
        self.assertIn("$updated = $db->execute(", otp)
        self.assertNotIn("$updated = $db->query(", otp)
        self.assertIn('CPMS_DISPOSABLE_GENERATED_SECRETS', workflow)
        self.assertIn('--source-ref "$HEAD_SHA"', workflow)
        self.assertIn('--require-secret-count 14', workflow)
        self.assertIn('package-proof-full.json', workflow)

    def test_all_tooling_is_outside_installable_plugin(self):
        self.assertFalse(TOOL.is_relative_to(ROOT / "clinic-practice-management"))
        production = ROOT / "clinic-practice-management"
        for forbidden in ("src/Application/Demo/DemoService.php", "src/Admin/DemoSetupPage.php", "src/Infrastructure/Sms/Providers/DemoSmsProvider.php", "bin/rwp-demo-acceptance.php", "bin/rwp-demo-acceptance.py"):
            self.assertFalse((production / forbidden).exists())


if __name__ == "__main__":
    unittest.main()
