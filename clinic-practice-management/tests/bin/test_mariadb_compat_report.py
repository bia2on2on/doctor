#!/usr/bin/env python3
"""Focused regression tests for MariaDB PHPUnit selection/reporting (not DB tests)."""

from __future__ import annotations

import json
import re
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from mariadb_compat_report import (  # noqa: E402
    DEFAULT_SELECTION_CONFIG,
    EXPECTED_CLASSES,
    EXPECTED_FILES,
    STARTUP_CATEGORIES,
    STARTUP_STAGES,
    analyze_junit,
    build_annotation,
    main,
    normalize_classname,
    render_markdown,
    _escape_workflow_message,
)


class MariaDbCompatReportTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tempdir = tempfile.TemporaryDirectory()
        self.addCleanup(self.tempdir.cleanup)
        self.work = Path(self.tempdir.name)

    def write_startup_diagnostics(self, name: str, *lines: str) -> Path:
        path = self.work / name
        path.write_text("\n".join(lines) + "\n", encoding="utf-8")
        return path

    def write_junit(
        self,
        path: Path,
        classnames: list[str],
        *,
        assertions_per_case: int = 1,
        declared_tests: int | None = None,
        skipped_index: int | None = None,
        failure_index: int | None = None,
        error_index: int | None = None,
    ) -> Path:
        actual_tests = len(classnames)
        declared = actual_tests if declared_tests is None else declared_tests
        root = ET.Element(
            "testsuites",
            tests=str(declared),
            assertions=str(actual_tests * assertions_per_case),
            failures=str(int(failure_index is not None)),
            errors=str(int(error_index is not None)),
            skipped=str(int(skipped_index is not None)),
        )
        suite = ET.SubElement(
            root,
            "testsuite",
            name="focused",
            tests=str(declared),
            assertions=str(actual_tests * assertions_per_case),
            failures=str(int(failure_index is not None)),
            errors=str(int(error_index is not None)),
            skipped=str(int(skipped_index is not None)),
        )
        for index, classname in enumerate(classnames):
            case = ET.SubElement(
                suite,
                "testcase",
                classname=classname,
                name=f"testCase{index}",
                assertions=str(assertions_per_case),
            )
            if index == skipped_index:
                ET.SubElement(case, "skipped")
            if index == failure_index:
                ET.SubElement(case, "failure")
            if index == error_index:
                ET.SubElement(case, "error")
        ET.ElementTree(root).write(path, encoding="utf-8", xml_declaration=True)
        return path

    def test_php_bootstrap_and_workflow_emit_only_allowlisted_startup_tokens(self) -> None:
        bootstrap = (Path(__file__).resolve().parents[1] / "integration-bootstrap.php").read_text(
            encoding="utf-8"
        )
        workflow = (Path(__file__).resolve().parents[3] / ".github/workflows/mariadb-10.11-compat.yml").read_text(
            encoding="utf-8"
        )
        emitted_stages = set(
            re.findall(r"cpms_mariadb_startup_diagnostic_mark\('([A-Z_]+)'\)", bootstrap)
        )
        emitted_categories = set(
            re.findall(r"cpms_mariadb_startup_diagnostic_fail\('([A-Z_]+)'\)", bootstrap)
        )

        self.assertTrue(emitted_stages)
        self.assertTrue(emitted_stages.issubset(STARTUP_STAGES))
        self.assertTrue(emitted_categories)
        self.assertTrue(emitted_categories.issubset(STARTUP_CATEGORIES))
        self.assertIn("STAGE=PHPUNIT_PROCESS_STARTED", workflow)
        self.assertIn("--startup-diagnostics-file", workflow)
        self.assertNotIn("phpunit-output.private --summary-file", workflow)

    def test_selected_phpunit_config_contains_exactly_all_18_files(self) -> None:
        root = ET.parse(DEFAULT_SELECTION_CONFIG).getroot()
        selected = [
            (element.text or "").strip().replace("\\", "/")
            for element in root.iter()
            if element.tag.rsplit("}", 1)[-1] == "file"
        ]
        self.assertEqual(len(selected), 18)
        self.assertEqual(len(set(selected)), 18)
        self.assertEqual(set(selected), set(EXPECTED_FILES))
        self.assertEqual(len(EXPECTED_CLASSES), 18)

    def test_dropping_selected_files_from_config_is_rejected(self) -> None:
        selection = self.work / "dropped-selection.xml"
        selection.write_text(
            "<phpunit><testsuites><testsuite name=\"focused\"><file>"
            + EXPECTED_FILES[0]
            + "</file></testsuite></testsuites></phpunit>",
            encoding="utf-8",
        )
        classnames = [EXPECTED_CLASSES[index % 18] for index in range(120)]
        junit = self.write_junit(self.work / "full.xml", classnames)
        report = analyze_junit(junit, selection, phpunit_exit_code=0)

        self.assertEqual(report["configured_file_count"], 1)
        self.assertFalse(report["gate_pass"])
        self.assertEqual(report["startup_stage"], "PHPUNIT_CONFIGURATION")
        self.assertEqual(report["startup_category"], "PHPUNIT_CONFIG_INVALID")
        self.assertTrue(any("approved 18-file selection" in issue for issue in report["issues"]))

    def test_dot_and_backslash_classname_formats_normalize_to_expected_class(self) -> None:
        self.assertEqual(
            normalize_classname("ClinicCore.Tests.Integration.MigrationTest"),
            "MigrationTest",
        )
        self.assertEqual(
            normalize_classname(r"ClinicCore\Tests\Integration\MigrationTest"),
            "MigrationTest",
        )

    def test_original_two_tests_twenty_assertions_result_fails_closed(self) -> None:
        junit = self.write_junit(
            self.work / "two-tests.xml",
            [
                "ClinicCore.Tests.Compatibility.MariaDb1011CompatibilityTest",
                "ClinicCore.Tests.Compatibility.MariaDb1011CompatibilityTest",
            ],
            assertions_per_case=10,
        )
        report = analyze_junit(junit, DEFAULT_SELECTION_CONFIG, phpunit_exit_code=0)

        self.assertEqual(report["test_cases"], 2)
        self.assertEqual(report["assertions"], 20)
        self.assertEqual(report["executed_class_count"], 1)
        self.assertEqual(report["executed_expected_classes"], ["MariaDb1011CompatibilityTest"])
        self.assertEqual(len(report["missing_classes"]), 17)
        self.assertFalse(report["gate_pass"])
        self.assertTrue(any("At least 120" in issue for issue in report["issues"]))
        self.assertTrue(any("expected selected test classes" in issue for issue in report["issues"]))

    def test_complete_120_case_report_accepts_dot_and_backslash_names(self) -> None:
        classnames: list[str] = []
        for index in range(120):
            class_name = EXPECTED_CLASSES[index % len(EXPECTED_CLASSES)]
            if index % 2:
                classnames.append("ClinicCore.Tests.Integration." + class_name)
            else:
                classnames.append("ClinicCore\\Tests\\Integration\\" + class_name)
        junit = self.write_junit(self.work / "complete.xml", classnames)
        report = analyze_junit(junit, DEFAULT_SELECTION_CONFIG, phpunit_exit_code=0)

        self.assertTrue(report["gate_pass"], report["issues"])
        self.assertEqual(report["test_cases"], 120)
        self.assertEqual(report["assertions"], 120)
        self.assertEqual(report["executed_class_count"], 18)
        self.assertEqual(len(report["executed_expected_classes"]), 18)
        self.assertEqual(report["missing_classes"], [])

    def test_missing_and_malformed_junit_fail_closed_with_all_classes_missing(self) -> None:
        missing = analyze_junit(self.work / "absent.xml", DEFAULT_SELECTION_CONFIG, 1)
        malformed_path = self.work / "malformed.xml"
        malformed_path.write_text("<testsuites><testsuite>", encoding="utf-8")
        malformed = analyze_junit(malformed_path, DEFAULT_SELECTION_CONFIG, 1)

        for report in (missing, malformed):
            self.assertFalse(report["gate_pass"])
            self.assertIsNone(report["test_cases"])
            self.assertIsNone(report["missing_classes"])
            self.assertEqual(len(report["unverified_classes"]), 18)
            self.assertIsNone(report["executed_class_count"])

        self.assertEqual(missing["junit_status"], "missing")
        self.assertEqual(malformed["junit_status"], "malformed")

    def test_declared_incomplete_junit_counts_are_rejected(self) -> None:
        junit = self.write_junit(
            self.work / "incomplete.xml",
            ["ClinicCore.Tests.Compatibility.MariaDb1011CompatibilityTest"],
            declared_tests=2,
        )
        report = analyze_junit(junit, DEFAULT_SELECTION_CONFIG, phpunit_exit_code=0)
        self.assertFalse(report["gate_pass"])
        self.assertTrue(
            any("aggregate counts do not match" in issue for issue in report["issues"])
        )

    def test_sanitized_summary_excludes_failure_message_sql_and_fixture_values(self) -> None:
        classnames = [EXPECTED_CLASSES[index % 18] for index in range(120)]
        junit = self.write_junit(
            self.work / "sensitive-failure.xml",
            classnames,
            failure_index=0,
        )
        tree = ET.parse(junit)
        failure = tree.find(".//failure")
        self.assertIsNotNone(failure)
        failure.text = "SELECT * FROM wp_patients WHERE name='PHI'; password=fixture-secret"
        testcase = tree.find(".//testcase")
        self.assertIsNotNone(testcase)
        testcase.attrib["name"] = "testPatientRecord with data set PHI-fixture-value"
        tree.write(junit, encoding="utf-8", xml_declaration=True)

        report = analyze_junit(junit, DEFAULT_SELECTION_CONFIG, phpunit_exit_code=1)
        summary = render_markdown(report)
        serialized = json.dumps(report)
        self.assertNotIn("SELECT *", serialized)
        self.assertNotIn("PHI", serialized)
        self.assertNotIn("fixture-secret", serialized)
        self.assertNotIn("PHI-fixture-value", serialized)
        self.assertNotIn("SELECT *", summary)
        self.assertNotIn("PHI", summary)
        self.assertNotIn("fixture-secret", summary)
        self.assertNotIn("PHI-fixture-value", summary)
        self.assertIn("MariaDb1011CompatibilityTest.testPatientRecord", summary)
        self.assertIn("Failures / errors / skips: `1 / 0 / 0`", summary)

    def test_phpunit_failure_before_junit_is_written_reports_unknown_and_stays_red(self) -> None:
        report = analyze_junit(self.work / "never-written.xml", DEFAULT_SELECTION_CONFIG, 255)
        annotation = build_annotation(report)

        self.assertFalse(report["gate_pass"])
        self.assertIsNone(report["test_cases"])
        self.assertIsNone(report["assertions"])
        self.assertIsNone(report["executed_class_count"])
        self.assertIsNone(report["missing_classes"])
        self.assertIn("junit_missing", report["failure_categories"])
        self.assertIn("phpunit_nonzero_exit", report["failure_categories"])
        for field in (
            "exit=255", "tests=UNKNOWN", "assertions=UNKNOWN", "failures=UNKNOWN",
            "errors=UNKNOWN", "skips=UNKNOWN", "executed=UNKNOWN", "missing=UNKNOWN",
            "first=UNKNOWN",
        ):
            self.assertIn(field, annotation)
        self.assertTrue(annotation.startswith("::error title="))

    def test_missing_junit_reports_first_allowlisted_bootstrap_category(self) -> None:
        markers = self.write_startup_diagnostics(
            "wp-library-missing.private",
            "STAGE=PHPUNIT_PROCESS_STARTED",
            "STAGE=INTEGRATION_BOOTSTRAP_ENTRY",
            "STAGE=WP_TEST_LIBRARY_CHECK",
            "CATEGORY=WP_TEST_LIBRARY_MISSING",
            "FAILURE_STAGE=WP_TEST_LIBRARY_CHECK",
        )
        report = analyze_junit(
            self.work / "never-written.xml",
            DEFAULT_SELECTION_CONFIG,
            1,
            startup_diagnostics_path=markers,
        )
        annotation = build_annotation(report)
        summary = render_markdown(report)

        self.assertFalse(report["gate_pass"])
        self.assertEqual(report["startup_stage"], "WP_TEST_LIBRARY_CHECK")
        self.assertEqual(report["startup_category"], "WP_TEST_LIBRARY_MISSING")
        self.assertIn("startup_stage=WP_TEST_LIBRARY_CHECK", annotation)
        self.assertIn("startup_category=WP_TEST_LIBRARY_MISSING", annotation)
        self.assertIn("WP_TEST_LIBRARY_CHECK", summary)

    def test_early_phpunit_exit_before_bootstrap_is_unknown_and_cannot_pass(self) -> None:
        markers = self.write_startup_diagnostics(
            "early-exit.private", "STAGE=PHPUNIT_PROCESS_STARTED"
        )
        report = analyze_junit(
            self.work / "junit-not-created.xml",
            DEFAULT_SELECTION_CONFIG,
            255,
            startup_diagnostics_path=markers,
        )
        annotation = build_annotation(report)

        self.assertFalse(report["gate_pass"])
        self.assertEqual(report["junit_status"], "missing")
        self.assertEqual(report["startup_stage"], "PHPUNIT_BEFORE_BOOTSTRAP")
        self.assertEqual(report["startup_category"], "UNKNOWN_STARTUP_FAILURE")
        self.assertTrue(annotation.startswith("::error title="))

    def test_startup_marker_cannot_override_nonzero_phpunit_exit(self) -> None:
        markers = self.write_startup_diagnostics(
            "complete-but-red.private",
            "STAGE=PHPUNIT_PROCESS_STARTED",
            "STAGE=INTEGRATION_BOOTSTRAP_ENTRY",
            "STAGE=BOOTSTRAP_COMPLETE",
        )
        classnames = [EXPECTED_CLASSES[index % 18] for index in range(120)]
        junit = self.write_junit(self.work / "all-pass-nonzero.xml", classnames)
        report = analyze_junit(
            junit,
            DEFAULT_SELECTION_CONFIG,
            1,
            startup_diagnostics_path=markers,
        )

        self.assertFalse(report["gate_pass"])
        self.assertEqual(report["startup_stage"], "BOOTSTRAP_COMPLETE")
        self.assertEqual(report["startup_category"], "NONE")
        self.assertIn("phpunit_nonzero_exit", report["failure_categories"])

    def test_untrusted_completion_claim_cannot_create_pass(self) -> None:
        markers = self.write_startup_diagnostics(
            "untrusted-completion.private",
            "STAGE=PHPUNIT_PROCESS_STARTED",
            "STAGE=BOOTSTRAP_COMPLETE",
            "STAGE=WORDPRESS_TEST_LIBRARY_BOOTSTRAP",
            "CATEGORY=NONE",
            "FAILURE_STAGE=WORDPRESS_TEST_LIBRARY_BOOTSTRAP",
        )
        classnames = [EXPECTED_CLASSES[index % 18] for index in range(120)]
        junit = self.write_junit(self.work / "all-pass-untrusted.xml", classnames)
        report = analyze_junit(
            junit,
            DEFAULT_SELECTION_CONFIG,
            0,
            startup_diagnostics_path=markers,
        )

        self.assertFalse(report["gate_pass"])
        self.assertEqual(report["startup_diagnostics_status"], "malformed")
        self.assertNotEqual(report["startup_category"], "NONE")

    def test_bootstrap_completion_marker_is_required_for_clean_junit_pass(self) -> None:
        markers = self.write_startup_diagnostics(
            "bootstrap-incomplete.private",
            "STAGE=PHPUNIT_PROCESS_STARTED",
            "STAGE=INTEGRATION_BOOTSTRAP_ENTRY",
        )
        classnames = [EXPECTED_CLASSES[index % 18] for index in range(120)]
        junit = self.write_junit(self.work / "apparently-complete.xml", classnames)
        report = analyze_junit(
            junit,
            DEFAULT_SELECTION_CONFIG,
            0,
            startup_diagnostics_path=markers,
        )

        self.assertFalse(report["gate_pass"])
        self.assertEqual(report["startup_category"], "UNKNOWN_STARTUP_FAILURE")
        self.assertIn("startup_completion_missing", report["failure_categories"])
        self.assertTrue(any("completion marker is missing" in issue for issue in report["issues"]))

    def test_bootstrap_exception_reports_only_fixed_stage_and_category(self) -> None:
        markers = self.write_startup_diagnostics(
            "bootstrap-exception.private",
            "STAGE=PHPUNIT_PROCESS_STARTED",
            "STAGE=INTEGRATION_BOOTSTRAP_ENTRY",
            "STAGE=PHPUNIT_POLYFILLS_REQUIRE",
            "CATEGORY=BOOTSTRAP_REQUIRE_FAILURE",
            "FAILURE_STAGE=PHPUNIT_POLYFILLS_REQUIRE",
        )
        report = analyze_junit(
            self.work / "no-junit.xml",
            DEFAULT_SELECTION_CONFIG,
            255,
            startup_diagnostics_path=markers,
        )
        self.assertFalse(report["gate_pass"])
        self.assertEqual(report["startup_stage"], "PHPUNIT_POLYFILLS_REQUIRE")
        self.assertEqual(report["startup_category"], "BOOTSTRAP_REQUIRE_FAILURE")
        self.assertNotIn("exception", json.dumps(report).lower())

    def test_malicious_raw_phpunit_output_is_never_in_summary_json_or_annotation(self) -> None:
        raw_output = self.work / "phpunit-output.private"
        raw_output.write_text(
            "Bearer secret-token; patient=PHI-731; SELECT password FROM wp_users; "
            "RuntimeException at /tmp/private/path.php:88",
            encoding="utf-8",
        )
        markers = self.write_startup_diagnostics(
            "malicious-output.private",
            "STAGE=PHPUNIT_PROCESS_STARTED",
            "STAGE=INTEGRATION_BOOTSTRAP_ENTRY",
            "STAGE=WORDPRESS_TEST_LIBRARY_BOOTSTRAP",
            "CATEGORY=WORDPRESS_BOOTSTRAP_FAILURE",
            "FAILURE_STAGE=WORDPRESS_TEST_LIBRARY_BOOTSTRAP",
        )
        report = analyze_junit(
            self.work / "no-junit.xml",
            DEFAULT_SELECTION_CONFIG,
            1,
            startup_diagnostics_path=markers,
        )
        summary = render_markdown(report)
        annotation = build_annotation(report)
        serialized = json.dumps(report)
        for secret in ("secret-token", "PHI-731", "SELECT password", "/tmp/private/path.php", "RuntimeException"):
            self.assertNotIn(secret, summary + annotation + serialized)
        self.assertEqual(report["startup_category"], "WORDPRESS_BOOTSTRAP_FAILURE")

    def test_unknown_startup_category_is_replaced_with_unknown_without_leaking_input(self) -> None:
        malicious_category = "RuntimeException PHI ::error title=leak:: /tmp/secret.php"
        markers = self.write_startup_diagnostics(
            "unknown-category.private",
            "STAGE=PHPUNIT_PROCESS_STARTED",
            "STAGE=INTEGRATION_BOOTSTRAP_ENTRY",
            "STAGE=WORDPRESS_TEST_LIBRARY_BOOTSTRAP",
            "CATEGORY=" + malicious_category,
            "FAILURE_STAGE=WORDPRESS_TEST_LIBRARY_BOOTSTRAP",
        )
        report = analyze_junit(
            self.work / "no-junit.xml",
            DEFAULT_SELECTION_CONFIG,
            1,
            startup_diagnostics_path=markers,
        )
        summary = render_markdown(report)
        annotation = build_annotation(report)

        self.assertEqual(report["startup_stage"], "UNKNOWN_BOOTSTRAP_STAGE")
        self.assertEqual(report["startup_category"], "UNKNOWN_STARTUP_FAILURE")
        self.assertNotIn(malicious_category, json.dumps(report) + summary + annotation)
        self.assertNotIn("/tmp/secret.php", summary + annotation)
        self.assertEqual(annotation.count("::"), 2)
        self.assertNotIn("\n", annotation)

    def test_sanitized_annotation_includes_only_safe_startup_tokens(self) -> None:
        markers = self.write_startup_diagnostics(
            "unsafe-marker.private",
            "STAGE=INTEGRATION_BOOTSTRAP_ENTRY",
            "STAGE=UNKNOWN::error title=PHI::",
            "CATEGORY=WORDPRESS_BOOTSTRAP_FAILURE",
            "FAILURE_STAGE=WORDPRESS_TEST_LIBRARY_BOOTSTRAP",
        )
        report = analyze_junit(
            self.work / "no-junit.xml",
            DEFAULT_SELECTION_CONFIG,
            1,
            startup_diagnostics_path=markers,
        )
        annotation = build_annotation(report)

        self.assertEqual(report["startup_diagnostics_status"], "malformed")
        self.assertEqual(report["startup_stage"], "UNKNOWN_BOOTSTRAP_STAGE")
        self.assertEqual(report["startup_category"], "UNKNOWN_STARTUP_FAILURE")
        self.assertTrue(annotation.startswith("::error title="))
        self.assertEqual(annotation.count("::"), 2)
        self.assertNotIn("PHI", annotation)
        self.assertNotIn("\n", annotation)

    def test_cli_writes_summary_json_and_exactly_one_failure_annotation(self) -> None:
        exit_path = self.work / "phpunit-exit.txt"
        summary_path = self.work / "summary.md"
        json_path = self.work / "report.json"
        exit_path.write_text("255\n", encoding="utf-8")
        startup_path = self.write_startup_diagnostics(
            "cli-startup.private",
            "STAGE=PHPUNIT_PROCESS_STARTED",
            "STAGE=INTEGRATION_BOOTSTRAP_ENTRY",
            "STAGE=WP_TEST_LIBRARY_CHECK",
            "CATEGORY=WP_TEST_LIBRARY_MISSING",
            "FAILURE_STAGE=WP_TEST_LIBRARY_CHECK",
        )
        stdout = StringIO()
        with redirect_stdout(stdout):
            exit_status = main(
                [
                    "--junit", str(self.work / "not-created.xml"),
                    "--phpunit-exit-file", str(exit_path),
                    "--startup-diagnostics-file", str(startup_path),
                    "--summary-file", str(summary_path),
                    "--json-output", str(json_path),
                    "--check-annotation",
                ]
            )

        output = stdout.getvalue()
        self.assertEqual(exit_status, 1)
        self.assertEqual(output.count("::"), 2)
        self.assertIn("::error title=MariaDB 10.11 sanitized PHPUnit diagnostic::", output)
        summary = summary_path.read_text(encoding="utf-8")
        self.assertIn("Test cases: `UNKNOWN`", summary)
        self.assertIn("Assertions: `UNKNOWN`", summary)
        self.assertIn("Startup stage / category", summary)
        self.assertIn("WP_TEST_LIBRARY_CHECK", summary)
        self.assertIn("startup_category=WP_TEST_LIBRARY_MISSING", output)
        self.assertIn("Executed expected class names: UNKNOWN", summary)
        self.assertIn("Missing expected class names: UNKNOWN", summary)
        self.assertIn("junit_missing", json.loads(json_path.read_text(encoding="utf-8"))["failure_categories"])
        self.assertNotIn("::warning", output)

    def test_junit_failure_and_error_report_safe_first_test_and_categories(self) -> None:
        classnames = [EXPECTED_CLASSES[index % 18] for index in range(120)]
        junit = self.write_junit(
            self.work / "failure-error.xml",
            classnames,
            failure_index=1,
            error_index=0,
        )
        tree = ET.parse(junit)
        cases = tree.findall(".//testcase")
        cases[0].attrib["name"] = "testFirstError"
        cases[1].attrib["name"] = "testSecondFailure"
        tree.write(junit, encoding="utf-8", xml_declaration=True)

        report = analyze_junit(junit, DEFAULT_SELECTION_CONFIG, 1)
        summary = render_markdown(report)
        annotation = build_annotation(report)
        self.assertEqual(report["failures"], 1)
        self.assertEqual(report["errors"], 1)
        self.assertIn("assertion_failure", report["failure_categories"])
        self.assertIn("test_error", report["failure_categories"])
        self.assertIn("first=MariaDb1011CompatibilityTest.testFirstError", annotation)
        self.assertIn("First failing test identifier", summary)
        self.assertNotIn("irrelevant", summary)

    def test_failure_rdiag_payload_is_allowlisted_and_cannot_inject_commands(self) -> None:
        classnames = [EXPECTED_CLASSES[index % 18] for index in range(120)]
        junit = self.write_junit(self.work / "rdiag.xml", classnames, failure_index=0)
        tree = ET.parse(junit)
        case = tree.findall(".//testcase")[0]
        failure = case.find("failure")
        failure.text = (
            "Did not observe the wait edge. RDIAG=child=running;reqtrx=1;ptrx=1;w=0;pw=0;"
            "wdesc=none;pdesc=i=PRIMARY;t=RECORD;m=X;tb=1;d=1 "
            "::error title=leak:: /tmp/secret.php PHI-77"
        )
        tree.write(junit, encoding="utf-8", xml_declaration=True)

        report = analyze_junit(junit, DEFAULT_SELECTION_CONFIG, 1)
        annotation = build_annotation(report)
        serialized = json.dumps(report)
        summary = render_markdown(report)

        self.assertIn("rdiag=child=running;reqtrx=1;ptrx=1;w=0;pw=0", annotation)
        self.assertIn("d=1", annotation)
        for forbidden in ("PHI-77", "/tmp/secret.php", "title=leak"):
            self.assertNotIn(forbidden, annotation + summary)
        self.assertEqual(annotation.count("::"), 2)
        self.assertNotIn("PHI-77", serialized)

    def test_missing_classes_are_named_in_summary_and_annotation(self) -> None:
        junit = self.write_junit(
            self.work / "first-class-only.xml",
            ["ClinicCore.Tests.Compatibility.MariaDb1011CompatibilityTest"] * 2,
            assertions_per_case=10,
        )
        report = analyze_junit(junit, DEFAULT_SELECTION_CONFIG, 0)
        summary = render_markdown(report)
        annotation = build_annotation(report)

        self.assertEqual(report["test_cases"], 2)
        self.assertEqual(report["assertions"], 20)
        self.assertEqual(len(report["missing_classes"]), 17)
        self.assertIn("missing_expected_classes", report["failure_categories"])
        self.assertIn("MigrationTest", summary)
        self.assertIn("MigrationTest", annotation)
        self.assertFalse(report["gate_pass"])

    def test_malicious_junit_text_cannot_escape_summary_or_annotation(self) -> None:
        classnames = [EXPECTED_CLASSES[index % 18] for index in range(120)]
        junit = self.write_junit(self.work / "malicious.xml", classnames, failure_index=0)
        tree = ET.parse(junit)
        testcase = tree.find(".//testcase")
        failure = tree.find(".//failure")
        self.assertIsNotNone(testcase)
        self.assertIsNotNone(failure)
        testcase.attrib["classname"] = "ClinicCore.Tests.::error title=pwn::UnexpectedClass"
        testcase.attrib["name"] = "testSafe with data set value\n::warning title=pwn::INJECT"
        failure.text = "SELECT * FROM patients; PHI=fixture; ::error title=pwn::INJECT"
        tree.write(junit, encoding="utf-8", xml_declaration=True)

        report = analyze_junit(junit, DEFAULT_SELECTION_CONFIG, 1)
        summary = render_markdown(report)
        annotation = build_annotation(report)
        self.assertEqual(report["failure_identifiers"][0], {"class": "unrecognized", "method": "testSafe"})
        self.assertNotIn("SELECT *", summary + annotation)
        self.assertNotIn("PHI", summary + annotation)
        self.assertNotIn("INJECT", summary + annotation)
        self.assertNotIn("::warning", summary + annotation)
        self.assertEqual(annotation.count("::"), 2)
        self.assertNotIn("\n", annotation)

    def test_workflow_annotation_data_escapes_command_delimiters_and_newlines(self) -> None:
        escaped = _escape_workflow_message("x::y,%\nnext\r")
        self.assertEqual(escaped, "x%3A%3Ay%2C%25%0Anext%0D")

    def test_reporter_stays_red_when_phpunit_exit_is_nonzero_despite_clean_junit(self) -> None:
        classnames = [EXPECTED_CLASSES[index % 18] for index in range(120)]
        junit = self.write_junit(self.work / "all-pass.xml", classnames)
        report = analyze_junit(junit, DEFAULT_SELECTION_CONFIG, 2)
        annotation = build_annotation(report)
        self.assertFalse(report["gate_pass"])
        self.assertIn("phpunit_nonzero_exit", report["failure_categories"])
        self.assertTrue(annotation.startswith("::error title="))

    def test_nonzero_failure_error_or_skip_counts_keep_gate_red(self) -> None:
        classnames = [EXPECTED_CLASSES[index % 18] for index in range(120)]
        junit = self.write_junit(
            self.work / "red.xml",
            classnames,
            skipped_index=1,
            failure_index=2,
            error_index=3,
        )
        report = analyze_junit(junit, DEFAULT_SELECTION_CONFIG, phpunit_exit_code=1)
        self.assertFalse(report["gate_pass"])
        self.assertEqual(report["failures"], 1)
        self.assertEqual(report["errors"], 1)
        self.assertEqual(report["skipped"], 1)


if __name__ == "__main__":
    unittest.main(verbosity=2)
