#!/usr/bin/env python3
"""Fail-closed, sanitized JUnit completeness gate for the MariaDB 10.11 lane.

This reports counts and the statically expected test-class names only. It never
prints or serializes JUnit failure text, test output, SQL, credentials, or fixture
values. It is a reporting/completeness tool, not database compatibility evidence.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any

MINIMUM_TEST_CASES = 120
MAX_DIAGNOSTIC_IDENTIFIERS = 50
EXPECTED_FILES = (
    "tests/Compatibility/MariaDb1011CompatibilityTest.php",
    "tests/Integration/MigrationTest.php",
    "tests/Integration/CustomRoleArchivalMigrationTest.php",
    "tests/Integration/CustomRoleDisplayNameMigrationTest.php",
    "tests/Integration/FirstPracticeSetupTest.php",
    "tests/Integration/ClinicTenantIsolationTest.php",
    "tests/Integration/PatientIdentityFoundationTest.php",
    "tests/Integration/ClinicalFilesScopedAuthorizationTest.php",
    "tests/Integration/FinanceAuthorizationPolicyRegressionTest.php",
    "tests/Integration/FinanceClinicIsolationTest.php",
    "tests/Integration/CustomRoleRepositoryTransactionTest.php",
    "tests/Integration/CustomRoleAuditAtomicityTest.php",
    "tests/Integration/ConcurrencyTest.php",
    "tests/Integration/VisitConcurrencyTest.php",
    "tests/Integration/CustomRoleArchivalConcurrencyTest.php",
    "tests/Integration/CustomRoleLifecycleTest.php",
    "tests/Integration/BackupEngineTest.php",
    "tests/Integration/BackupMultiClinicFilesRestoreGreenTest.php",
)
EXPECTED_CLASSES = tuple(Path(path).stem for path in EXPECTED_FILES)
EXPECTED_CLASS_SET = frozenset(EXPECTED_CLASSES)
PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_SELECTION_CONFIG = PROJECT_ROOT / "phpunit.mariadb-10.11.xml"


def local_name(tag: str) -> str:
    """Return an XML local name, including when a namespace is present."""
    return tag.rsplit("}", 1)[-1]


def normalize_classname(value: str | None) -> str | None:
    """Handle PHP backslashes and PHPUnit/JUnit dot-separated class names."""
    if not value or not value.strip():
        return None
    parts = [part for part in re.split(r"[.\\]+", value.strip().lstrip("\\")) if part]
    return parts[-1] if parts else None


def _selection_inventory(config_path: Path) -> tuple[list[str], int | None]:
    try:
        root = ET.parse(config_path).getroot()
    except (OSError, ET.ParseError):
        return ["The explicit PHPUnit selection configuration is missing or malformed."], None

    if local_name(root.tag) != "phpunit":
        return ["The explicit PHPUnit selection configuration has an unexpected root element."], 0

    files = [
        (element.text or "").strip().replace("\\", "/")
        for element in root.iter()
        if local_name(element.tag) == "file"
    ]
    issues: list[str] = []
    if len(files) != len(EXPECTED_FILES) or len(set(files)) != len(files):
        issues.append("The PHPUnit configuration must list exactly 18 unique test files.")
    if frozenset(files) != frozenset(EXPECTED_FILES):
        issues.append("The PHPUnit configuration does not match the approved 18-file selection.")
    for relative_path in EXPECTED_FILES:
        if not (PROJECT_ROOT / relative_path).is_file():
            issues.append("One or more expected selected test files are missing from the checkout.")
            break
    return issues, len(files)


def _integer_attribute(element: ET.Element, name: str) -> int | None:
    value = element.attrib.get(name)
    if value is None:
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _safe_method_name(value: str | None) -> str:
    """Keep only a PHP-style method identifier; discard data-set values."""
    if not value:
        return "[redacted]"
    static_prefix = re.split(r"\s+with data set\b", value, maxsplit=1, flags=re.IGNORECASE)[0]
    match = re.match(r"^[A-Za-z_][A-Za-z0-9_]*", static_prefix.strip())
    return match.group(0) if match else "[redacted]"


def _event_identifiers(cases: list[ET.Element], tag: str) -> list[dict[str, str]]:
    identifiers: list[dict[str, str]] = []
    for case in cases:
        if not any(local_name(child.tag) == tag for child in list(case)):
            continue
        class_name = normalize_classname(case.attrib.get("classname"))
        safe_class = class_name if class_name in EXPECTED_CLASS_SET else "unrecognized"
        identifiers.append(
            {
                "class": safe_class,
                "method": _safe_method_name(case.attrib.get("name")),
            }
        )
    return identifiers


def _case_counts(cases: list[ET.Element]) -> dict[str, int]:
    counts = {"failures": 0, "errors": 0, "skipped": 0}
    tags = {"failure": "failures", "error": "errors", "skipped": "skipped"}
    for case in cases:
        present = {local_name(child.tag) for child in list(case)}
        for tag, key in tags.items():
            if tag in present:
                counts[key] += 1
    return counts


def _assertion_count(root: ET.Element, cases: list[ET.Element]) -> int | None:
    """Read JUnit's aggregate assertion count without double-counting suites."""
    case_values = [_integer_attribute(case, "assertions") for case in cases]
    if cases and all(value is not None for value in case_values):
        return sum(value or 0 for value in case_values)

    root_value = _integer_attribute(root, "assertions")
    if root_value is not None:
        return root_value

    root_suites = [child for child in list(root) if local_name(child.tag) == "testsuite"]
    if root_suites:
        suite_values = [_integer_attribute(suite, "assertions") for suite in root_suites]
        if all(value is not None for value in suite_values):
            return sum(value or 0 for value in suite_values)

    if local_name(root.tag) == "testsuite":
        return _integer_attribute(root, "assertions")
    return 0 if not cases else None


def _junit_integrity_issues(root: ET.Element, cases: list[ET.Element], counts: dict[str, int]) -> list[str]:
    issues: list[str] = []
    actual_by_attribute = {
        "tests": len(cases),
        "failures": counts["failures"],
        "errors": counts["errors"],
        "skipped": counts["skipped"],
    }

    if local_name(root.tag) not in {"testsuite", "testsuites"}:
        return ["JUnit XML has an unexpected root element."]

    for element in root.iter():
        tag = local_name(element.tag)
        if tag not in {"testsuite", "testsuites"}:
            continue
        scoped_cases = [child for child in element.iter() if local_name(child.tag) == "testcase"]
        scoped_counts = _case_counts(scoped_cases)
        expected_attributes = {
            "tests": len(scoped_cases),
            "failures": scoped_counts["failures"],
            "errors": scoped_counts["errors"],
            "skipped": scoped_counts["skipped"],
        }
        for attribute, actual in expected_attributes.items():
            raw_value = element.attrib.get(attribute)
            if raw_value is None:
                continue
            try:
                declared = int(raw_value)
            except ValueError:
                issues.append("JUnit XML contains a non-numeric aggregate count.")
                continue
            if declared != actual:
                issues.append("JUnit XML aggregate counts do not match its testcase records.")
                break
    return list(dict.fromkeys(issues))


def analyze_junit(
    junit_path: Path,
    config_path: Path = DEFAULT_SELECTION_CONFIG,
    phpunit_exit_code: int | None = None,
) -> dict[str, Any]:
    """Return a safe report and gate decision; never include raw JUnit messages."""
    selection_issues, configured_file_count = _selection_inventory(config_path)
    issues = list(selection_issues)
    result: dict[str, Any] = {
        "junit_status": "missing",
        "test_cases": None,
        "assertions": None,
        "failures": None,
        "errors": None,
        "skipped": None,
        "executed_class_count": None,
        "executed_expected_classes": None,
        "missing_classes": None,
        "unverified_classes": list(EXPECTED_CLASSES),
        "unexpected_class_count": 0,
        "failure_identifiers": [],
        "error_identifiers": [],
        "skip_identifiers": [],
        "expected_class_count": len(EXPECTED_CLASSES),
        "expected_file_count": len(EXPECTED_FILES),
        "configured_file_count": configured_file_count,
        "phpunit_exit_code": phpunit_exit_code,
        "issues": issues,
        "gate_pass": False,
    }

    try:
        root = ET.parse(junit_path).getroot()
    except FileNotFoundError:
        issues.append("JUnit XML is missing; testcase counts and executed classes are unavailable.")
        result["junit_status"] = "missing"
        result["issues"] = list(dict.fromkeys(issues))
        return _finish_gate(result)
    except (OSError, ET.ParseError):
        issues.append("JUnit XML is malformed or unreadable; testcase counts and executed classes are unavailable.")
        result["junit_status"] = "malformed"
        result["issues"] = list(dict.fromkeys(issues))
        return _finish_gate(result)

    cases = [element for element in root.iter() if local_name(element.tag) == "testcase"]
    counts = _case_counts(cases)
    raw_class_names = [normalize_classname(case.attrib.get("classname")) for case in cases]
    executed_classes = {name for name in raw_class_names if name}
    executed_expected = sorted(EXPECTED_CLASS_SET.intersection(executed_classes))
    missing = sorted(EXPECTED_CLASS_SET.difference(executed_classes))

    result.update(
        {
            "junit_status": "valid",
            "test_cases": len(cases),
            "assertions": _assertion_count(root, cases),
            "failures": counts["failures"],
            "errors": counts["errors"],
            "skipped": counts["skipped"],
            "executed_class_count": len(executed_classes),
            "executed_expected_classes": executed_expected,
            "missing_classes": missing,
            "unverified_classes": [],
            "unexpected_class_count": len(executed_classes.difference(EXPECTED_CLASS_SET)),
            "failure_identifiers": _event_identifiers(cases, "failure"),
            "error_identifiers": _event_identifiers(cases, "error"),
            "skip_identifiers": _event_identifiers(cases, "skipped"),
        }
    )
    issues.extend(_junit_integrity_issues(root, cases, counts))
    if not cases:
        issues.append("JUnit XML contains no testcase records.")
    result["issues"] = list(dict.fromkeys(issues))
    return _finish_gate(result)


def _finish_gate(result: dict[str, Any]) -> dict[str, Any]:
    reasons = list(result["issues"])
    if result["phpunit_exit_code"] is None:
        reasons.append("The PHPUnit process exit code was not recorded.")
    elif result["phpunit_exit_code"] != 0:
        reasons.append("The PHPUnit process exited non-zero.")

    test_cases = result["test_cases"]
    if test_cases is None or test_cases < MINIMUM_TEST_CASES:
        reasons.append(f"At least {MINIMUM_TEST_CASES} executed test cases are required.")

    for key, label in (("failures", "failures"), ("errors", "errors"), ("skipped", "skips")):
        value = result[key]
        if value is None or value != 0:
            reasons.append(f"JUnit must report zero {label}.")

    if result["missing_classes"] is None or result["missing_classes"]:
        reasons.append("One or more expected selected test classes are missing or unverifiable.")

    result["issues"] = list(dict.fromkeys(reasons))
    result["gate_pass"] = not result["issues"] and result["junit_status"] == "valid"
    return result


def _display(value: Any) -> str:
    return "unavailable" if value is None else str(value)


def render_markdown(result: dict[str, Any]) -> str:
    status = "PASS" if result["gate_pass"] else "FAIL"
    lines = [
        "## MariaDB 10.11 focused PHPUnit evidence (sanitized)",
        "",
        f"- Completeness gate: **{status}**",
        f"- PHPUnit process exit code: `{_display(result['phpunit_exit_code'])}`",
        f"- JUnit XML: `{result['junit_status']}`",
        f"- Test cases: `{_display(result['test_cases'])}` (minimum {MINIMUM_TEST_CASES})",
        f"- Assertions: `{_display(result['assertions'])}`",
        f"- Failures / errors / skips: `{_display(result['failures'])} / {_display(result['errors'])} / {_display(result['skipped'])}`",
        f"- Executed classes: " + (
            "unavailable (JUnit XML could not be parsed)"
            if result["executed_class_count"] is None
            else f"`{result['executed_class_count']}` total; `{len(result['executed_expected_classes'])}/{result['expected_class_count']}` expected"
        ),
        f"- Configured explicit files: `{_display(result['configured_file_count'])}/{result['expected_file_count']}` expected",
        "- Missing expected classes: " + (
            "unavailable (JUnit XML could not be parsed)"
            if result["missing_classes"] is None
            else (", ".join(f"`{name}`" for name in result["missing_classes"]) if result["missing_classes"] else "none")
        ),
    ]
    for label, key in (("Failure", "failure_identifiers"), ("Error", "error_identifiers"), ("Skip", "skip_identifiers")):
        identifiers = result[key]
        if identifiers:
            shown = identifiers[:MAX_DIAGNOSTIC_IDENTIFIERS]
            values = ", ".join(f"`{item['class']}::{item['method']}`" for item in shown)
            omitted = len(identifiers) - len(shown)
            suffix = f" (+{omitted} omitted)" if omitted else ""
            lines.append(f"- {label} test identifiers (method names only): {values}{suffix}")
    if result["unverified_classes"]:
        lines.append(
            "- Expected classes not verifiable: "
            + ", ".join(f"`{name}`" for name in result["unverified_classes"])
        )
    if result["issues"]:
        lines.extend(["", "Gate details:"])
        lines.extend(f"- {issue}" for issue in result["issues"])
    lines.extend(
        [
            "",
            "Only aggregate JUnit counts and statically expected class names are shown. Test output, failure messages, SQL, credentials, fixture values, and raw logs are intentionally omitted.",
            "",
        ]
    )
    return "\n".join(lines)


def _read_exit_code(path: Path) -> int | None:
    try:
        text = path.read_text(encoding="utf-8").strip()
        return int(text)
    except (OSError, ValueError):
        return None


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--junit", type=Path, required=True)
    parser.add_argument("--selection-config", type=Path, default=DEFAULT_SELECTION_CONFIG)
    parser.add_argument("--phpunit-exit-file", type=Path, required=True)
    parser.add_argument("--summary-file", type=Path)
    parser.add_argument("--json-output", type=Path)
    args = parser.parse_args(argv)

    result = analyze_junit(
        args.junit,
        config_path=args.selection_config,
        phpunit_exit_code=_read_exit_code(args.phpunit_exit_file),
    )
    markdown = render_markdown(result)
    if args.summary_file:
        try:
            with args.summary_file.open("a", encoding="utf-8") as summary:
                summary.write(markdown)
        except OSError:
            print("Unable to write sanitized MariaDB PHPUnit summary.", file=sys.stderr)
            return 1
    else:
        print(markdown)

    if args.json_output:
        try:
            args.json_output.parent.mkdir(parents=True, exist_ok=True)
            args.json_output.write_text(
                json.dumps(result, indent=2, sort_keys=True) + "\n",
                encoding="utf-8",
            )
        except OSError:
            print("Unable to write sanitized MariaDB PHPUnit result JSON.", file=sys.stderr)
            return 1

    return 0 if result["gate_pass"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
