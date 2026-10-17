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


def _junit_integrity_issues(root: ET.Element, cases: list[ET.Element]) -> list[str]:
    issues: list[str] = []
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
        "first_failing_identifier": None,
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
            "first_failing_identifier": _first_failure_in_document_order(cases),
        }
    )
    issues.extend(_junit_integrity_issues(root, cases))
    if not cases:
        issues.append("JUnit XML contains no testcase records.")
    result["issues"] = list(dict.fromkeys(issues))
    return _finish_gate(result)


def _first_failure_in_document_order(cases: list[ET.Element]) -> dict[str, str] | None:
    for case in cases:
        if any(local_name(child.tag) in {"failure", "error"} for child in list(case)):
            class_name = normalize_classname(case.attrib.get("classname"))
            safe_class = class_name if class_name in EXPECTED_CLASS_SET else "unrecognized"
            return {
                "class": safe_class,
                "method": _safe_method_name(case.attrib.get("name")),
            }
    return None


def _finish_gate(result: dict[str, Any]) -> dict[str, Any]:
    reasons = list(result["issues"])
    if result["phpunit_exit_code"] is None:
        reasons.append("The PHPUnit process exit code is UNKNOWN.")
    elif result["phpunit_exit_code"] != 0:
        reasons.append("The PHPUnit process exited non-zero.")

    test_cases = result["test_cases"]
    if test_cases is None:
        reasons.append(f"Actual test count is UNKNOWN; at least {MINIMUM_TEST_CASES} is required.")
    elif test_cases < MINIMUM_TEST_CASES:
        reasons.append(f"At least {MINIMUM_TEST_CASES} executed test cases are required.")

    for key, label in (("failures", "failures"), ("errors", "errors"), ("skipped", "skips")):
        value = result[key]
        if value is None:
            reasons.append(f"JUnit {label} count is UNKNOWN; zero is required.")
        elif value != 0:
            reasons.append(f"JUnit must report zero {label}.")

    if result["missing_classes"] is None:
        reasons.append("Expected selected test classes are UNKNOWN because JUnit could not be verified.")
    elif result["missing_classes"]:
        reasons.append("One or more expected selected test classes are missing from JUnit.")

    result["issues"] = list(dict.fromkeys(reasons))
    result["gate_pass"] = not result["issues"] and result["junit_status"] == "valid"
    result["failure_categories"] = _failure_categories(result)
    return result


def _failure_categories(result: dict[str, Any]) -> list[str]:
    """Return fixed allowlisted categories; never classify from raw failure text."""
    categories: list[str] = []
    issues = result["issues"]
    if any(
        "selection configuration" in issue
        or "PHPUnit configuration" in issue
        or "approved 18-file" in issue
        or "expected selected test files" in issue
        for issue in issues
    ):
        categories.append("selection_config_invalid")
    if result["junit_status"] == "missing":
        categories.append("junit_missing")
    elif result["junit_status"] == "malformed":
        categories.append("junit_malformed")
    if any("aggregate counts" in issue or "non-numeric aggregate" in issue for issue in issues):
        categories.append("junit_inconsistent")
    if any("unexpected root element" in issue for issue in issues):
        categories.append("junit_unexpected_format")
    if result["test_cases"] == 0:
        categories.append("junit_empty")
    if (result["failures"] or 0) > 0:
        categories.append("assertion_failure")
    if (result["errors"] or 0) > 0:
        categories.append("test_error")
    if (result["skipped"] or 0) > 0:
        categories.append("skipped_tests")
    if result["test_cases"] is None:
        categories.append("test_count_unknown")
    elif result["test_cases"] < MINIMUM_TEST_CASES:
        categories.append("below_minimum_case_count")
    if result["missing_classes"] is None:
        categories.append("class_inventory_unknown")
    elif result["missing_classes"]:
        categories.append("missing_expected_classes")
    if result["phpunit_exit_code"] is None:
        categories.append("phpunit_exit_unknown")
    elif result["phpunit_exit_code"] != 0:
        categories.append("phpunit_nonzero_exit")
    if not result["gate_pass"] and not categories:
        categories.append("unknown")
    return list(dict.fromkeys(categories))


def _display(value: Any) -> str:
    return "UNKNOWN" if value is None else str(value)


def _first_failing_identifier(result: dict[str, Any]) -> str:
    first = result.get("first_failing_identifier")
    if isinstance(first, dict):
        return f"{first['class']}.{first['method']}"
    return "UNKNOWN"


def _expected_class_list(value: list[str] | None) -> str:
    if value is None:
        return "UNKNOWN"
    return ", ".join(value) if value else "none"


def render_markdown(result: dict[str, Any]) -> str:
    status = "PASS" if result["gate_pass"] else "FAIL"
    executed_classes = result["executed_expected_classes"]
    lines = [
        "## MariaDB 10.11 focused PHPUnit evidence (sanitized)",
        "",
        f"- Completeness gate: **{status}**",
        f"- Failure category: `{'+'.join(result.get('failure_categories', [])) or 'none'}`",
        f"- PHPUnit process exit code: `{_display(result['phpunit_exit_code'])}`",
        f"- JUnit XML: `{result['junit_status']}`",
        f"- Test cases: `{_display(result['test_cases'])}` (minimum {MINIMUM_TEST_CASES})",
        f"- Assertions: `{_display(result['assertions'])}`",
        f"- Failures / errors / skips: `{_display(result['failures'])} / {_display(result['errors'])} / {_display(result['skipped'])}`",
        f"- Executed classes: " + (
            "UNKNOWN (JUnit XML could not be parsed)"
            if result["executed_class_count"] is None
            else f"`{result['executed_class_count']}` total; `{len(executed_classes)}/{result['expected_class_count']}` expected"
        ),
        "- Executed expected class names: " + _expected_class_list(executed_classes),
        f"- Configured explicit files: `{_display(result['configured_file_count'])}/{result['expected_file_count']}` expected",
        "- Missing expected class names: " + _expected_class_list(result["missing_classes"]),
        f"- First failing test identifier: `{_first_failing_identifier(result)}`",
    ]
    for label, key in (("Failure", "failure_identifiers"), ("Error", "error_identifiers"), ("Skip", "skip_identifiers")):
        identifiers = result[key]
        if identifiers:
            shown = identifiers[:MAX_DIAGNOSTIC_IDENTIFIERS]
            values = ", ".join(f"`{item['class']}.{item['method']}`" for item in shown)
            omitted = len(identifiers) - len(shown)
            suffix = f" (+{omitted} omitted)" if omitted else ""
            lines.append(f"- {label} test identifiers (method names only): {values}{suffix}")
    if result["unverified_classes"]:
        lines.append(
            "- Expected classes not verifiable: "
            + ", ".join(result["unverified_classes"])
        )
    if result["issues"]:
        lines.extend(["", "Gate details:"])
        lines.extend(f"- {issue}" for issue in result["issues"])
    lines.extend(
        [
            "",
            "Only aggregate JUnit counts, allowlisted class/method identifiers, and fixed failure categories are shown. Test output, failure messages, SQL, credentials, fixture values, and raw logs are intentionally omitted.",
            "",
        ]
    )
    return "\n".join(lines)


def _escape_workflow_message(value: str) -> str:
    """Escape workflow-command data syntax; caller supplies only allowlisted data."""
    return (
        value.replace("%", "%25")
        .replace("\r", "%0D")
        .replace("\n", "%0A")
        .replace(":", "%3A")
        .replace(",", "%2C")
    )


def build_annotation(result: dict[str, Any], output_error: bool = False) -> str:
    """Build exactly one bounded, sanitized GitHub workflow-command annotation."""
    level = "notice" if result["gate_pass"] and not output_error else "error"
    categories = list(result.get("failure_categories", []))
    if output_error:
        categories.append("evidence_output_failure")
    category = "+".join(dict.fromkeys(categories)) or "none"
    executed_classes = result["executed_expected_classes"]
    if executed_classes is None:
        executed = "UNKNOWN"
    else:
        executed = "|".join(executed_classes) if executed_classes else "none"
    missing = result["missing_classes"]
    if missing is None:
        missing_text = "UNKNOWN"
    else:
        missing_text = "|".join(missing) if missing else "none"

    message = (
        f"category={category}; exit={_display(result['phpunit_exit_code'])}; "
        f"tests={_display(result['test_cases'])}; assertions={_display(result['assertions'])}; "
        f"failures={_display(result['failures'])}; errors={_display(result['errors'])}; "
        f"skips={_display(result['skipped'])}; "
        f"classes={_display(result['executed_class_count'])}/{result['expected_class_count']}; "
        f"executed={executed}; missing={missing_text}; "
        f"first={_first_failing_identifier(result)}"
    )
    message = _escape_workflow_message(message)
    if len(message) > 1800:
        # Keep check annotations bounded; the complete sanitized class inventory is in the step summary.
        message = _escape_workflow_message(
            f"category={category}; exit={_display(result['phpunit_exit_code'])}; "
            f"tests={_display(result['test_cases'])}; assertions={_display(result['assertions'])}; "
            f"failures={_display(result['failures'])}; errors={_display(result['errors'])}; "
            f"skips={_display(result['skipped'])}; missing_count={len(missing or []) if missing is not None else 'UNKNOWN'}; "
            f"first={_first_failing_identifier(result)}"
        )
    title = "MariaDB 10.11 sanitized PHPUnit diagnostic"
    return f"::{level} title={title}::{message}"


def _read_exit_code(path: Path) -> int | None:
    try:
        text = path.read_text(encoding="utf-8").strip()
        value = int(text)
        return value if 0 <= value <= 255 else None
    except (OSError, ValueError):
        return None


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--junit", type=Path, required=True)
    parser.add_argument("--selection-config", type=Path, default=DEFAULT_SELECTION_CONFIG)
    parser.add_argument("--phpunit-exit-file", type=Path, required=True)
    parser.add_argument("--summary-file", type=Path)
    parser.add_argument("--json-output", type=Path)
    parser.add_argument("--check-annotation", action="store_true")
    args = parser.parse_args(argv)

    exit_code = _read_exit_code(args.phpunit_exit_file)
    try:
        result = analyze_junit(
            args.junit,
            config_path=args.selection_config,
            phpunit_exit_code=exit_code,
        )
    except Exception:
        # Never echo an unfiltered parser/exception message into logs or annotations.
        result = {
            "junit_status": "unavailable",
            "test_cases": None,
            "assertions": None,
            "failures": None,
            "errors": None,
            "skipped": None,
            "executed_class_count": None,
            "executed_expected_classes": None,
            "missing_classes": None,
            "unverified_classes": list(EXPECTED_CLASSES),
            "unexpected_class_count": None,
            "failure_identifiers": [],
            "error_identifiers": [],
            "skip_identifiers": [],
            "first_failing_identifier": None,
            "expected_class_count": len(EXPECTED_CLASSES),
            "expected_file_count": len(EXPECTED_FILES),
            "configured_file_count": None,
            "phpunit_exit_code": exit_code,
            "issues": ["Sanitized reporter failed internally; diagnostic details are withheld."],
            "gate_pass": False,
            "failure_categories": ["reporter_internal_error"],
        }

    markdown = render_markdown(result)
    output_error = False
    if args.summary_file:
        try:
            with args.summary_file.open("a", encoding="utf-8") as summary:
                summary.write(markdown)
        except OSError:
            print("Unable to write sanitized MariaDB PHPUnit summary.", file=sys.stderr)
            output_error = True
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
            output_error = True

    if args.check_annotation:
        print(build_annotation(result, output_error=output_error))

    return 0 if result["gate_pass"] and not output_error else 1


if __name__ == "__main__":
    raise SystemExit(main())
