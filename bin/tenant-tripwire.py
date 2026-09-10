#!/usr/bin/env python3
"""
Tenant-hardcode tripwire — scans production runtime code for literal tenant ID 1.

Usage:
    python3 bin/tenant-tripwire.py [--allowlist bin/tenant-tripwire-allowlist.json] [--scan-dir DIR] [--test]

Exit codes:
    0 — no violations (or self-tests pass)
    1 — violations found
    2 — configuration error

Post-closure corrective (Class D detector hardening):
  A. bound-parameter tenant literal — `%d`/`%s` tenant placeholder bound to
     literal 1 (multi-line windowed detection, tenant-aware).
  B. `select_first_clinic` was unconditionally shadowed by generic LIMIT-1
     handling — the blanket `limit_1_generic` benign rule is removed (bare
     LIMIT 1 matches no positive pattern, so legitimate LIMIT 1 stays clean)
     and first/default-clinic selection on cpms_clinics is reported.
  C. qualified `c.id = 1` was swallowed by the generic primary-ID benign rule —
     `id_1_primary` no longer matches qualified/dot-prefixed identifiers and
     never applies on the tenant table itself.
  D. line-level benign suppression could exempt a real tenant hardcode sharing
     the line with an unrelated benign predicate — a positive is now suppressed
     only when a benign match overlaps the positive's own tenant-literal core.
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
    ("sql_where_org_id_1",     r"WHERE\s+.*organization_id\s*=\s*1\b",                          "hardcode"),
    ("sql_where_location_id_1", r"WHERE\s+.*location_id\s*=\s*1\b",                              "hardcode"),

    # SQL WHERE predicates with string '1' for tenant columns
    ("sql_where_clinic_str1",   r"WHERE\s+.*(?:clinic_id|a\.clinic_id)\s*=\s*'1'",               "hardcode"),
    ("sql_where_org_str1",      r"WHERE\s+.*organization_id\s*=\s*'1'",                          "hardcode"),
    ("sql_where_loc_str1",      r"WHERE\s+.*location_id\s*=\s*'1'",                              "hardcode"),

    # Literal 1 primary-key lookup on the tenant table itself (numeric)
    ("clinics_pk_literal_1",    r"cpms_clinics[^;\n]*?\bid\s*=\s*1\b",                           "hardcode"),

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

# Tenant-literal CORE inside a positive match — suppression applies only when a
# benign match overlaps this core (fix D). Patterns without an entry use the
# whole positive-match span as their core. `select_first_clinic` has no `= 1`
# core and is handled specially (legit PK-by-placeholder lookups stay clean).
CORE_REGEX: dict[str, str] = {
    "sql_where_clinic_id_1":   r"(?:clinic_id|c\.id)\s*=\s*1\b",
    "sql_where_org_id_1":      r"organization_id\s*=\s*1\b",
    "sql_where_location_id_1": r"location_id\s*=\s*1\b",
    "sql_where_clinic_str1":   r"clinic_id\s*=\s*'1'",
    "sql_where_org_str1":      r"organization_id\s*=\s*'1'",
    "sql_where_loc_str1":      r"location_id\s*=\s*'1'",
    "clinics_pk_literal_1":    r"\bid\s*=\s*1\b",
    "insert_clinic_1":         r"VALUES\s*\([^)]*\b1\b",
    "insert_org_1":            r"VALUES\s*\([^)]*\b1\b",
}

# Bound-parameter tenant detection (fix A) — tenant placeholder in SQL text plus
# a literal-1 binding nearby (same line or up to LOOKBACK lines above the
# binding). Each entry: (pattern name, placeholder regex).
BOUND_PLACEHOLDERS: list[tuple[str, str]] = [
    ("bound_clinic_param_1",   r"clinic_id\s*=\s*%[ds]\b"),
    ("bound_org_param_1",      r"organization_id\s*=\s*%[ds]\b"),
    ("bound_location_param_1", r"location_id\s*=\s*%[ds]\b"),
]
# Row-lock shape on the tenant table: cpms_clinics + id placeholder + [1].
BOUND_CLINICS_ID_PLACEHOLDER = r"cpms_clinics"
BOUND_CLINICS_ID_PREDICATE = r"\bid\s*=\s*%[ds]\b"
BOUND_CLINICS_PATTERN = "bound_clinics_id_param_1"
# A param-array element binding literal 1: `[1]`, `[1, ...]` (short array) or
# `array(1, ...)` — but NOT `[1 => ...]` (integer key, unrelated to binding).
BOUND_LITERAL_1 = r"(?:(?<![\w$])\[\s*1\s*[,}\]]|array\(\s*1\s*,)"
BOUND_LOOKBACK = 7

# Legit PK-by-placeholder lookup on the tenant table suppresses ONLY the
# `select_first_clinic` suspect (a parameterized row lock is not first/default
# selection) — it never suppresses hardcode patterns.
CLINICS_PK_PARAM_LOOKUP = r"cpms_clinics.*\bid\s*=\s*%[ds]\b"

# Patterns that must NOT trigger (negative cases — these are benign).
# Each entry: (name, regex, guard). The benign rule is DISABLED on lines where
# `guard` (a regex) matches — e.g. the generic primary-ID rule never applies
# on the tenant table itself (fix C).
NEGATIVE_EXCLUDES: list[tuple[str, str, Optional[str]]] = [
    # is_active = 1, capacity = 1, boolean flags — NOTE: no generic LIMIT rule.
    # (fix B: blanket `limit_1_generic` unconditionally shadowed the
    # first-clinic suspect. Bare LIMIT 1 matches no positive pattern, so
    # legitimate LIMIT 1 queries stay clean without a benign rule.)
    ("is_active_flag",     r"is_active\s*=\s*1\b", None),
    ("capacity_1",         r"capacity\s*=\s*1\b", None),
    ("is_open_1",          r"is_open\s*=\s*1\b", None),
    ("is_needed_1",        r"is_needed\s*=\s*1\b", None),
    ("attempts_1",         r"attempts\s*=\s*1\b", None),
    ("recall_count_1",     r"recall_count\s*=\s*1\b", None),
    ("max_attempts_1",     r"max_attempts\s*=\s*1\b", None),
    ("priority_1",         r"priority\s*=\s*1\b", None),
    ("wp_user_id_1",       r"wp_user_id\s*=\s*1\b", None),
    # fix C: not qualified (`c.id`), not on the tenant table (guard).
    ("id_1_primary",       r"(?<![\w.])id\s*=\s*1\b", r"cpms_clinics"),
    ("booked_count_1",     r"booked_count\s*=\s*1\b", None),
    ("held_count_1",       r"held_count\s*=\s*1\b", None),
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


def _spans_overlap(a: tuple[int, int], b: tuple[int, int]) -> bool:
    return a[0] < b[1] and b[0] < a[1]


def _enabled_negatives(line: str) -> list[tuple[str, tuple[int, int]]]:
    """Benign matches active on this line (guards may disable a rule)."""
    out: list[tuple[str, tuple[int, int]]] = []
    for neg_name, neg_pattern, guard in NEGATIVE_EXCLUDES:
        if guard is not None and re.search(guard, line):
            continue
        m = re.search(neg_pattern, line)
        if m:
            out.append((neg_name, (m.start(), m.end())))
    return out


def _core_span(pat_name: str, line: str, match: "re.Match[str]") -> tuple[int, int]:
    core = CORE_REGEX.get(pat_name)
    if core is None:
        return (match.start(), match.end())
    m = re.search(core, line)
    if m:
        return (m.start(), m.end())
    return (match.start(), match.end())


def decide_line(line: str) -> Optional[tuple[str, str]]:
    """Single-line decision: returns (pattern_name, category) or None.

    A positive finding is suppressed only when an enabled benign rule overlaps
    the positive's own tenant-literal core (fix D).
    """
    if is_in_comment(line):
        return None
    negatives = _enabled_negatives(line)
    for pat_name, pat_regex, category in PATTERNS:
        m = re.search(pat_regex, line)
        if not m:
            continue
        if pat_name == "select_first_clinic":
            # A parameterized PK lookup/lock on the tenant table is legitimate
            # row access, not first/default selection.
            if re.search(CLINICS_PK_PARAM_LOOKUP, line):
                continue
            return (pat_name, category)
        core = _core_span(pat_name, line, m)
        suppressed = any(_spans_overlap(core, span) for _, span in negatives)
        if not suppressed:
            return (pat_name, category)
    return None


def decide_bound_params(lines: list[str], bind_idx: int) -> Optional[str]:
    """Windowed bound-parameter decision (fix A).

    `bind_idx` is the index of a line binding literal 1. Looks back up to
    BOUND_LOOKBACK lines for a tenant-identity placeholder. Returns the bound
    pattern name or None.
    """
    bind_line = lines[bind_idx]
    bm = re.search(BOUND_LITERAL_1, bind_line)
    if bm is None:
        return None
    core = (bm.start(), bm.end())
    # Benign rules apply only when overlapping the `[1]` binding itself.
    for neg_name, span in _enabled_negatives(bind_line):
        if _spans_overlap(core, span):
            return None
    start = max(0, bind_idx - BOUND_LOOKBACK)
    window = "\n".join(lines[start:bind_idx + 1])
    for pat_name, placeholder in BOUND_PLACEHOLDERS:
        if re.search(placeholder, window):
            return pat_name
    if re.search(BOUND_CLINICS_ID_PLACEHOLDER, window) and re.search(BOUND_CLINICS_ID_PREDICATE, window):
        return BOUND_CLINICS_PATTERN
    return None


def scan_lines(filepath: str, lines: list[str], allowlist: set[str]) -> list[Finding]:
    """Scan lines (1-based numbering) for tenant hardcode patterns."""
    findings: list[Finding] = []
    reported: set[int] = set()  # one finding per line
    for idx, line in enumerate(lines):
        lineno = idx + 1
        key_exact = f"{filepath}:{lineno}"
        if key_exact in allowlist or filepath in allowlist:
            continue
        if is_in_comment(line):
            continue
        hit = decide_line(line)
        if hit is not None:
            pat_name, category = hit
            findings.append(Finding(
                file=filepath, line=lineno, pattern=pat_name,
                category=category, text=line.rstrip(),
            ))
            reported.add(lineno)
    for idx, line in enumerate(lines):
        lineno = idx + 1
        if lineno in reported:
            continue
        key_exact = f"{filepath}:{lineno}"
        if key_exact in allowlist or filepath in allowlist:
            continue
        if is_in_comment(line):
            continue
        if not re.search(BOUND_LITERAL_1, line):
            continue
        pat = decide_bound_params(lines, idx)
        if pat is not None:
            findings.append(Finding(
                file=filepath, line=lineno, pattern=pat,
                category="hardcode", text=line.rstrip(),
            ))
            reported.add(lineno)
    return findings


def scan_file(filepath: str, allowlist: set[str]) -> list[Finding]:
    """Scan a single PHP file for tenant hardcode patterns."""
    try:
        with open(filepath, encoding="utf-8", errors="replace") as f:
            lines = f.readlines()
    except (OSError, UnicodeDecodeError):
        return []
    return scan_lines(filepath, lines, allowlist)


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
# Self-tests — each case is (lines, expected_pattern_or_None, description).
# expected None = must be clean. Multi-line cases exercise windowed detection.
# ---------------------------------------------------------------------------

SELF_TEST_CASES: list[tuple[list[str], Optional[str], str]] = [
    # ---- historical positive forms (must detect) ----
    (["$clinic_id = 1;"], "php_clinic_id_1", "php numeric assign"),
    (["WHERE clinic_id = 1 AND status = 'active'"], "sql_where_clinic_id_1", "sql where numeric"),
    (["WHERE a.clinic_id = 1"], "sql_where_clinic_id_1", "sql qualified alias"),
    (["WHERE organization_id = 1"], "sql_where_org_id_1", "sql org numeric"),
    (["WHERE location_id = 1"], "sql_where_location_id_1", "sql location numeric"),
    (["$organizationId = 1;"], "default_org_1", "org camel default (default_org rule)"),
    (["$location_id = 1;"], "php_location_id_1", "php location numeric"),
    (["queueFor(1, 'job', $payload)"], "call_queueFor_1", "call queueFor"),
    (["store('key', 1, $data)"], "call_store_1", "call store"),
    (["$clinicId = 1;"], "php_clinic_id_1", "php camel numeric"),
    (["INSERT INTO cpms_clinics (clinic_id) VALUES (1, 'test')"], "insert_clinic_1", "insert numeric"),
    (["$clinic_id = '1';"], "php_clinic_str1", "php numeric-string assign"),
    (["WHERE clinic_id = '1'"], "sql_where_clinic_str1", "sql where string"),
    (["WHERE organization_id = '1'"], "sql_where_org_str1", "sql org string"),
    (["WHERE location_id = '1'"], "sql_where_loc_str1", "sql location string"),
    (["'clinic_id' => 1,"], "array_clinic_1", "array numeric"),
    (["'clinic_id' => '1',"], "array_clinic_str1", "array string"),
    (["'organization_id' => 1,"], "array_clinic_1", "array org numeric"),
    (["'location_id' => '1',"], "array_clinic_str1", "array location string"),
    # ---- fix A: bound-parameter tenant literal (must detect) ----
    (["$db->fetchAll('SELECT * FROM t WHERE clinic_id = %d', [1]);"],
     "bound_clinic_param_1", "bound compact same-line"),
    (["$where = 'clinic_id = %d' . ($onlyActive ? ' AND is_active = 1' : '');",
      "return $db->fetchAll(",
      "    'SELECT * FROM t WHERE ' . $where,",
      "    [1]",
      ");"],
     "bound_clinic_param_1", "bound historical ServiceRepository::all shape"),
    (["$max = $db->fetchValue(",
      "    'SELECT MAX(n) FROM t WHERE clinic_id = %d AND n LIKE %s',",
      "    [1, $prefix . '%']",
      ");"],
     "bound_clinic_param_1", "bound historical nextNumber shape"),
    (["$db->fetchRowForUpdate(",
      "    'SELECT id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',",
      "    [1]",
      ");"],
     "bound_clinics_id_param_1", "bound historical lockClinic shape"),
    (["$rows = $db->fetchAll('SELECT * FROM t WHERE pay.clinic_id = %d', [1, $a, $b]);"],
     "bound_clinic_param_1", "bound table-alias qualifier"),
    (["$x = $db->fetchAll('SELECT * FROM t WHERE organization_id = %d', [1]);"],
     "bound_org_param_1", "bound org"),
    (["$x = $db->fetchAll('SELECT * FROM t WHERE location_id = %d', [1]);"],
     "bound_location_param_1", "bound location"),
    (["$x = $db->fetchAll('SELECT * FROM t WHERE clinic_id = %s', array(1, $a));"],
     "bound_clinic_param_1", "bound array() syntax + %s"),
    # ---- fix B: first/default clinic selection (must suspect, not vanish) ----
    (["$row = $db->fetchRow('SELECT * FROM cpms_clinics ORDER BY id ASC LIMIT 1');"],
     "select_first_clinic", "first-clinic select survives LIMIT handling"),
    # ---- fix B companion: literal PK on tenant table (must hardcode) ----
    (["$row = $db->fetchRow('SELECT * FROM cpms_clinics WHERE id = 1');"],
     "clinics_pk_literal_1", "literal PK on tenant table"),
    # ---- fix C: qualified tenant ID (must detect) ----
    (["$row = $db->fetchRow('SELECT * FROM t c WHERE c.id = 1');"],
     "sql_where_clinic_id_1", "qualified c.id survives primary-ID rule"),
    (["$row = $db->fetchRow('SELECT * FROM t c WHERE c.id = 1 LIMIT 1');"],
     "sql_where_clinic_id_1", "qualified c.id with LIMIT"),
    # ---- fix D: tenant hardcode + benign flag same line (must detect) ----
    (["$rows = $db->fetchAll('SELECT * FROM t WHERE clinic_id = 1 AND is_active = 1');"],
     "sql_where_clinic_id_1", "hardcode plus benign flag same line"),
    (["$rows = $db->fetchAll('SELECT * FROM t WHERE clinic_id = 1 LIMIT 1');"],
     "sql_where_clinic_id_1", "hardcode plus LIMIT 1 same line"),
    # ---- historical negative forms (must stay clean) ----
    (["is_active = 1"], None, "benign flag"),
    (["capacity = 1"], None, "benign capacity"),
    (["is_open = 1"], None, "benign is_open"),
    (["LIMIT 1"], None, "bare LIMIT 1"),
    (["SELECT * FROM t ORDER BY id DESC LIMIT 1"], None, "legit LIMIT 1 query"),
    (["wp_user_id = 1"], None, "benign wp_user"),
    (["id = 1"], None, "bare primary-ID lookup"),
    (["SELECT * FROM t WHERE id = 1"], None, "legit PK lookup non-tenant table"),
    (["priority = 1"], None, "benign priority"),
    (["booked_count = 1"], None, "benign booked_count"),
    (["held_count = 1"], None, "benign held_count"),
    (["is_needed = 1"], None, "benign is_needed"),
    (["attempts = 1"], None, "benign attempts"),
    (["max_attempts = 1"], None, "benign max_attempts"),
    (["recall_count = 1"], None, "benign recall_count"),
    (["// clinic_id = 1 — historical migration fixture"], None, "comment line"),
    (["/* clinic_id = 1 */"], None, "block comment"),
    # ---- fixed-code shapes (must stay clean) ----
    (["$db->fetchRowForUpdate(",
      "    'SELECT id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',",
      "    [$clinicId]",
      ");"], None, "fixed lock: parameterized PK lock stays clean"),
    (["$rows = $db->fetchAll('SELECT * FROM t WHERE clinic_id = %d', [$clinicId]);"],
     None, "fixed repo: variable binding stays clean"),
    (["$clinicId = $scope->clinicId;"], None, "explicit tenant variable"),
    (["$clinic_id = (int) $row['clinic_id'];"], None, "tenant variable from row"),
    (["$sql .= ' ORDER BY created_at ASC LIMIT %d';", "$rows = $db->fetchAll($sql, [$limit]);"],
     None, "LIMIT placeholder with variable"),
    (["$where = 'clinic_id = %d';",
      "$map = [1 => 'one', 2 => 'two'];"],
     None, "integer array key near placeholder is not a binding"),
    (["$n = $db->fetchValue('SELECT COUNT(*) FROM t WHERE is_active = %d', [1]);"],
     None, "literal 1 bound to non-tenant flag"),
    (["$max = $db->fetchValue('SELECT MAX(n) FROM t WHERE clinic_id = %d', [$c]);",
      "$seq = 0;",
      "if (is_string($max) && preg_match('/^(\\d+)$/', $max, $m) === 1) {",
      "    $seq = (int) $m[1];",
      "}"],
     None, "regex group read near placeholder is not a binding"),
    (["$v = $row[1];"], None, "bare variable offset read"),
]


def run_self_tests() -> tuple[int, int]:
    """Run detector self-tests. Returns (passes, failures)."""
    passes = 0
    failures = 0

    print("=== Self-test cases (must detect / must stay clean) ===")
    for lines, expected, desc in SELF_TEST_CASES:
        findings = scan_lines("<self-test>", [ln + "\n" for ln in lines], set())
        got = findings[0].pattern if findings else None
        if got == expected:
            passes += 1
            print(f"  PASS: [{expected or 'clean'}] {desc}")
        else:
            failures += 1
            print(f"  FAIL: {desc}  (detected={got}, expected={expected})")
            for ln in lines:
                print(f"        | {ln[:100]}")

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
