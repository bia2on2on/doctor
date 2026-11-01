"""Small, non-sensitive helpers for the full-integration CI summary."""
from __future__ import annotations

import re
import xml.etree.ElementTree as ET

SUMMARY = re.compile(r'^(OK \(|OK, but|FAILURES!|ERRORS!|WARNINGS!)')


def junit_stats(root: ET.Element) -> dict[str, int]:
    # Count individual cases, not leaf suites: PHPUnit 9 emits both direct
    # cases and nested data-provider suites under the same class suite.
    cases = root.findall('.//testcase')
    return {
        'cases': len(cases),
        'assertions': sum(int(case.get('assertions', '0')) for case in cases),
        'failures': sum(case.find('failure') is not None for case in cases),
        'errors': sum(case.find('error') is not None for case in cases),
        'skipped': sum(case.find('skipped') is not None for case in cases),
    }


def terminal_summary_printed(path: str) -> bool:
    # Stream all lines, matching the same anchors as the fail-closed guard.
    # Shutdown diagnostics can be much longer than 4,000 characters.
    with open(path, 'r', errors='ignore') as handle:
        return any(SUMMARY.search(line) for line in handle)
