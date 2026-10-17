#!/usr/bin/env python3
"""Focused regression tests for MariaDB PHPUnit selection/reporting (not DB tests)."""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from mariadb_compat_report import (  # noqa: E402
    DEFAULT_SELECTION_CONFIG,
    EXPECTED_CLASSES,
    EXPECTED_FILES,
    analyze_junit,
    normalize_classname,
    render_markdown,
)


class MariaDbCompatReportTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tempdir = tempfile.TemporaryDirectory()
        self.addCleanup(self.tempdir.cleanup)
        self.work = Path(self.tempdir.name)

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
        self.assertIn("MariaDb1011CompatibilityTest::testPatientRecord", summary)
        self.assertIn("Failures / errors / skips: `1 / 0 / 0`", summary)

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
