#!/usr/bin/env python3
"""
Tenant-hardcode tripwire — scans production runtime code for literal tenant ID 1.

Usage:
    python3 bin/tenant-tripwire.py [--allowlist bin/tenant-tripwire-allowlist.json] [--scan-dir DIR] [--test]

Exit codes:
    0 — no violations (or self-tests pass)
    1 — violations found
    2 — configuration error
"""

import argparse
import json
import os
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

# ---------------------------------------------------------------------------
# Detection patterns — each is (name, regex, category)
# Category: 'hardcode' = literal 1 in tenant context, 'suspect' = needs review
# ---------------------------------------------------------------------------

PATTERNS: list[tuple[str, str, str]] = [
    # SQL WHERE predicates with literal 1 for tenant columns (numeric)
    ("sql_where_clinic_id_1",   r"WHERE\s+.*(?:clinic_id|a\.clinic_id|c\.id)\s*=\s*1\b",        "hardcode"),
    ("sql_where_org_id_1",     r"WHERE\s+.*organization_id\s*=\s*1\b",                           "hardcode"),
    ("sql_where_location_id_1", r"WHERE\s+.*location_id\s*=\s*1\b",                              "hardcode"),

    # SQL WHERE predicates with string '1' for tenant columns
    ("sql_where_clinic_str1",   r"WHERE\s+.*(?:clinic_id|a\.clinic_id)\s*=\s*'1'",               "hardcode"),
    ("sql_where_org_str1",      r"WHERE\s+.*organization_id\s*=\s*'1'",                          "hardcode"),
    ("sql_where_loc_str1",      r"WHERE\s+.*location_id\s*=\s*'1'",                              "hardcode"),

    # PHP variable assignments with literal 1 for tenant (numeric)
    ("php_clinic_id_1",        r"\$\w*(?:clinic|Clinic)\w*\s*=\s*1\s*;",                          "hardcode"),
    ("php_org_id_1",           r"\$\w*(?:organization|Organization|org)\w*_id\s*=\s*1\s*;",        "hardcode"),
    ("php_location_id_1",      r"\$\w*(?:location|Location)\w*_id\s*=\s*1\s*;",                    "hardcode"),

    # PHP variable assignments with string '1' for tenant
    ("php_clinic_str1",        r"\$\w*(?:clinic|Clinic)\w*\s*=\s*'1'\s*;",                         "hardcode"),
    ("php_org_str1",           r"\$\w*(?:organization|Organization|org)\w*_id\s*=\s*'1'\s*;",       "hardcode"),
    ("php_location_str1",      r"\$\w*(?:location|Location)\w*_id\s*=\s*'1'\s*;",                   "hardcode"),

    # Array key => value with literal 1 for tenant
    ("array_clinic_1",         r"'(?:clinic_id|organization_id|location_id)'\s*=>\s*1\b",           "hardcode"),
    ("array_clinic_str1",      r"'(?:clinic_id|organization_id|location_id)'\s*=>\s*'1'",           "hardcode"),

    # Function/method calls with literal 1 as tenant argument
    ("call_queueFor_1",        r"queueFor\s*\(\s*1\s*,",                                         "hardcode"),
    ("call_store_1",           r"store\s*\([^,]*,\s*1\s*,",                                      "hardcode"),

    # Default parameter values for tenant
    ("default_clinic_1",       r"(?:clinic_id|clinicId)\s*[=:]\s*1\b(?!\s*[.,])",                 "hardcode"),
    ("default_org_1",          r"(?:organization_id|organizationId)\s*[=:]\s*1\b",                "hardcode"),

    # INSERT values with literal 1 for tenant columns (numeric)
    ("insert_clinic_1",        r"INSERT\s+INTO\s+.*\(\s*[^)]*(?:clinic_id)[^)]*\)\s*VALUES\s*\([^)]*\b1\b", "hardcode"),
    ("insert_org_1",           r"INSERT\s+INTO\s+.*\(\s*[^)]*(?:organization_id)[^)]*\)\s*VALUES\s*\([^)]*\b1\b", "hardcode"),

    # SELECT ... LIMIT 1 used as implicit first/default Clinic
    ("select_first_clinic",    r"SELECT\s+.*\bcpms_clinics\b.*\bLIMIT\s+1\b",                    "suspect"),

    # Semantic: first/default Clinic resolvers
    ("first_clinic_semantic",  r"(?:first|default)\s*(?:clinic|Clinic)",                           "suspect"),
]

# Patterns that must NOT trigger (negative cases — these are benign)
NEGATIVE_EXCLUDES: list[tuple[str, str]] = [
    # is_active = 1, capacity = 1, boolean flags, LIMIT 1 unrelated to tenant
    ("is_active_flag",     r"is_active\s*=\s*1\b"),
    ("capacity_1",         r"capacity\s*=\s*1\b"),
    ("is_open_1",          r"is_open\s*=\s*1\b"),
    ("is_needed_1",        r"is_needed\s*=\s*1\b"),
    ("attempts_1",         r"attempts\s*=\s*1\b"),
    ("recall_count_1",     r"recall_count\s*=\s*1\b"),
    ("max_attempts_1",     r"max_attempts\s*=\s*1\b"),
    ("limit_1_generic",    r"LIMIT\s+1\s*"),
    ("priority_1",         r"priority\s*=\s*1\b"),
    ("wp_user_id_1",       r"wp_user_id\s*=\s*1\b"),
    ("id_1_primary",       r"\bid\s*=\s*1\b"),
    ("booked_count_1",     r"booked_count\s*=\s*1\b"),
    ("held_count_1",       r"held_count\s*=\s*1\b"),
]


@dataclass
class Finding:
    file: str
    line: int
    pattern: str
    category: str
    text: str


def load_allowlist(path: Optional[str]) -> set[str]:
    """Load allowlist entries. Each entry is '{file}:{line}' or just '{file}'."""
    if not path or not os.path.isfile(path):
        return set()
    with open(path) as f:
        data = json.load(f)
    return set(data.get("entries", []))


def is_in_comment(line: str) -> bool:
    stripped = line.lstrip()
    return stripped.startswith("//") or stripped.startswith("#") or stripped.startswith("/*") or stripped.startswith("*")


def scan_file(filepath: str, allowlist: set[str]) -> list[Finding]:
    """Scan a single PHP file for tenant hardcode patterns."""
    findings: list[Finding] = []
    try:
        with open(filepath, encoding="utf-8", errors="replace") as f:
            lines = f.readlines()
    except (OSError, UnicodeDecodeError):
        return findings

    for lineno, line in enumerate(lines, 1):
        # Skip comments — policy: comments are not runtime behavior
        if is_in_comment(line):
            continue

        # Check if this file:line is allowlisted
        key_exact = f"{filepath}:{lineno}"
        key_file = filepath
        if key_exact in allowlist or key_file in allowlist:
            continue

        # First check negative excludes — if line matches a benign pattern, skip
        is_benign = False
        for neg_name, neg_pattern in NEGATIVE_EXCLUDES:
            if re.search(neg_pattern, line):
                is_benign = True
                break
        if is_benign:
            continue

        # Check positive patterns
        for pat_name, pat_regex, category in PATTERNS:
            m = re.search(pat_regex, line)
            if m:
                findings.append(Finding(
                    file=filepath,
                    line=lineno,
                    pattern=pat_name,
                    category=category,
                    text=line.rstrip(),
                ))
                break  # one finding per line

    return findings


def discover_scan_dirs(root: str) -> list[str]:
    """Return production runtime directories to scan."""
    plugin_dir = os.path.join(root, "clinic-practice-management")
    scan_dirs = []
    src_dir = os.path.join(plugin_dir, "src")
    if os.path.isdir(src_dir):
        scan_dirs.append(src_dir)
    # bin scripts if they contain executable PHP
    return scan_dirs


def discover_scan_files(root: str) -> list[str]:
    """Discover all PHP files in scan directories."""
    files = []
    for scan_dir in discover_scan_dirs(root):
        for dirpath, dirnames, filenames in os.walk(scan_dir):
            # Skip tests, vendor, historical migrations
            dirnames[:] = [d for d in dirnames if d not in ("tests", "vendor", "__pycache__", "Migrations")]
            for fn in filenames:
                if fn.endswith(".php"):
                    files.append(os.path.join(dirpath, fn))
    return sorted(files)


# ---------------------------------------------------------------------------
# Self-tests (Section 7)
# ---------------------------------------------------------------------------

POSITIVE_CASES: list[tuple[str, bool]] = [
    # Numeric 1 forms
    ("$clinic_id = 1;",                                           True),
    ("WHERE clinic_id = 1 AND status = 'active'",                 True),
    ("WHERE a.clinic_id = 1",                                     True),
    ("WHERE organization_id = 1",                                 True),
    ("WHERE location_id = 1",                                     True),
    ("$organizationId = 1;",                                      True),
    ("$location_id = 1;",                                         True),
    ("queueFor(1, 'job', $payload)",                              True),
    ("store('key', 1, $data)",                                    True),
    ("$clinicId = 1;",                                            True),
    ("INSERT INTO cpms_clinics (clinic_id) VALUES (1, 'test')",   True),
    # String '1' forms
    ("$clinic_id = '1';",                                         True),
    ("WHERE clinic_id = '1'",                                     True),
    ("WHERE organization_id = '1'",                               True),
    ("WHERE location_id = '1'",                                   True),
    ("'clinic_id' => 1,",                                         True),
    ("'clinic_id' => '1',",                                       True),
    ("'organization_id' => 1,",                                   True),
    ("'location_id' => '1',",                                     True),
]

NEGATIVE_CASES: list[tuple[str, bool]] = [
    # (code_line, should_be_detected_as_benign — i.e., should NOT trigger)
    ("is_active = 1",                                             False),
    ("capacity = 1",                                              False),
    ("is_open = 1",                                               False),
    ("LIMIT 1",                                                   False),
    ("wp_user_id = 1",                                            False),
    ("id = 1",                                                    False),
    ("priority = 1",                                              False),
    ("booked_count = 1",                                          False),
    ("held_count = 1",                                            False),
    ("is_needed = 1",                                             False),
    ("attempts = 1",                                              False),
    ("max_attempts = 1",                                          False),
    ("recall_count = 1",                                          False),
    ("// clinic_id = 1 — historical migration fixture",           False),
    ("/* clinic_id = 1 */",                                       False),
]


def run_self_tests() -> tuple[int, int]:
    """Run detector self-tests. Returns (passes, failures)."""
    passes = 0
    failures = 0
    allowlist: set[str] = set()

    print("=== Positive cases (must detect) ===")
    for code, should_detect in POSITIVE_CASES:
        found = False
        # Skip comments
        if is_in_comment(code):
            found = False
        else:
            for neg_name, neg_pattern in NEGATIVE_EXCLUDES:
                if re.search(neg_pattern, code):
                    found = False
                    break
            else:
                for pat_name, pat_regex, category in PATTERNS:
                    if re.search(pat_regex, code):
                        found = True
                        break

        if found == should_detect:
            passes += 1
            print(f"  PASS: {code[:80]}")
        else:
            failures += 1
            print(f"  FAIL: {code[:80]}  (detected={found}, expected={should_detect})")

    print("\n=== Negative cases (must NOT detect) ===")
    for code, should_not_detect in NEGATIVE_CASES:
        found = False
        if is_in_comment(code):
            found = False
        else:
            for neg_name, neg_pattern in NEGATIVE_EXCLUDES:
                if re.search(neg_pattern, code):
                    found = False
                    break
            else:
                for pat_name, pat_regex, category in PATTERNS:
                    if re.search(pat_regex, code):
                        found = True
                        break

        if found == should_not_detect:
            passes += 1
            print(f"  PASS: {code[:80]}")
        else:
            failures += 1
            print(f"  FAIL: {code[:80]}  (detected={found}, expected={should_not_detect})")

    return passes, failures


def main() -> int:
    parser = argparse.ArgumentParser(description="Tenant-hardcode tripwire detector")
    parser.add_argument("--allowlist", help="Path to allowlist JSON file")
    parser.add_argument("--scan-dir", help="Repository root to scan (default: script parent)")
    parser.add_argument("--test", action="store_true", help="Run self-tests")
    args = parser.parse_args()

    if args.test:
        passes, failures = run_self_tests()
        print(f"\n{'='*60}")
        print(f"Self-tests: {passes} passed, {failures} failed")
        if failures > 0:
            return 1
        return 0

    root = args.scan_dir or os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    allowlist = load_allowlist(args.allowlist)

    files = discover_scan_files(root)
    if not files:
        print(f"ERROR: No PHP files found in {root}", file=sys.stderr)
        return 2

    all_findings: list[Finding] = []
    for fpath in files:
        all_findings.extend(scan_file(fpath, allowlist))

    hardcodes = [f for f in all_findings if f.category == "hardcode"]
    suspects = [f for f in all_findings if f.category == "suspect"]

    if hardcodes:
        print(f"VIOLATIONS: {len(hardcodes)} tenant hardcode(s) found\n")
        for f in hardcodes:
            print(f"  [{f.pattern}] {f.file}:{f.line}")
            print(f"    {f.text}\n")

    if suspects:
        print(f"SUSPECTS: {len(suspects)} pattern(s) need review\n")
        for f in suspects:
            print(f"  [{f.pattern}] {f.file}:{f.line}")
            print(f"    {f.text}\n")

    if not hardcodes and not suspects:
        print("CLEAN: no tenant hardcodes found in production runtime")
        print(f"  Scanned {len(files)} files")
        print(f"  Allowlist: {len(allowlist)} entries")

    summary = {
        "files_scanned": len(files),
        "hardcodes": len(hardcodes),
        "suspects": len(suspects),
        "allowlist_entries": len(allowlist),
    }
    print(f"\nSummary: {json.dumps(summary)}")

    return 1 if hardcodes else 0


if __name__ == "__main__":
    sys.exit(main())
