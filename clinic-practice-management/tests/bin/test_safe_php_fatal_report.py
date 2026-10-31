"""Executable privacy and failure-status tests for the CI PHP-fatal reporter."""
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

REPORTER = Path(__file__).with_name("safe-php-fatal-report.py")


class SafePhpFatalReportTest(unittest.TestCase):
    def run_report(self, text: str, check: bool = True):
        with tempfile.TemporaryDirectory() as directory:
            raw = Path(directory) / "raw.log"
            summary = Path(directory) / "summary.md"
            raw.write_text(text, encoding="utf-8")
            command = [sys.executable, str(REPORTER), str(raw), "--summary", str(summary)]
            if check:
                command.append("--check")
            result = subprocess.run(command, capture_output=True, text=True, check=False)
            return result, summary.read_text(encoding="utf-8") if summary.exists() else ""

    def test_exact_undefined_wordpress_menu_fatal_is_exposed(self):
        result, summary = self.run_report(
            "[31-Oct-2026] PHP Fatal error: Uncaught Error: Call to undefined function "
            "add_submenu_page() in /synthetic/wp-content/plugins/clinic-practice-management/"
            "src/Admin/ClinicReceptionRecoveryPage.php:38\n"
            "Stack trace: #0 secret OTP_SECRET_123456\n"
        )
        expected = ("PHP Fatal error: Uncaught Error: Call to undefined function add_submenu_page() "
                    "in clinic-practice-management/src/Admin/ClinicReceptionRecoveryPage.php on line 38")
        self.assertEqual(1, result.returncode)
        self.assertIn(expected, result.stdout)
        self.assertIn(expected, summary)
        self.assertIn("::error title=CPMS PHP fatal::", result.stdout)
        self.assertNotIn("OTP_SECRET_123456", result.stdout + summary)
        self.assertNotIn("/synthetic/wp-content", result.stdout + summary)

    def test_unknown_exception_and_stack_values_are_not_published(self):
        secret = "PASSWORD_secret_PATIENT_009_OTP_123456"
        result, summary = self.run_report(
            "PHP Fatal error: Uncaught RuntimeException: " + secret +
            " in /some/private/location.php:12\n#0 " + secret + "\n"
        )
        self.assertEqual(1, result.returncode)
        self.assertIn("RuntimeException", result.stdout)
        self.assertIn("redacted", result.stdout)
        self.assertNotIn(secret, result.stdout + summary + result.stderr)
        self.assertNotIn("/some/private/location.php", result.stdout + summary)

    def test_attacker_controlled_path_and_workflow_commands_are_not_echoed(self):
        secret = "::warning::PATIENT_PRIVATE"
        result, summary = self.run_report(
            "PHP Fatal error: Uncaught Error: Call to undefined function add_submenu_page() "
            "in /tmp/clinic-practice-management/src/" + secret + ".php:3\n"
        )
        self.assertEqual(1, result.returncode)
        self.assertNotIn(secret, result.stdout + summary)
        self.assertIn("add_submenu_page()", result.stdout)

    def test_no_fatal_passes_without_copying_other_log_lines(self):
        result, summary = self.run_report("private password and OTP 123456, no fatal\n")
        self.assertEqual(0, result.returncode)
        self.assertNotIn("123456", result.stdout + summary)
        self.assertEqual("", summary)

    def test_generic_critical_error_is_failure_not_silent_pass(self):
        result, summary = self.run_report("There has been a critical error on this website.")
        self.assertEqual(1, result.returncode)
        self.assertIn("fatal or critical error", result.stdout)
        self.assertIn("redacted", summary)

    def test_nonchecking_mode_does_not_replace_the_command_status(self):
        result, _ = self.run_report("PHP Fatal error: Uncaught Error: redacted", check=False)
        self.assertEqual(0, result.returncode)
        self.assertIn("::error", result.stdout)


if __name__ == "__main__":
    unittest.main()
