#!/usr/bin/env python3
"""
Tenant-hardcode tripwire — scans production runtime code for literal tenant ID 1.

Usage:
    python3 bin/tenant-tripwire.py [--allowlist bin/tenant-tripwire-allowlist.json] [--scan-dir DIR] [--test]

Exit codes:
    0 — no violations (or self-tests pass)
    1 — violations found
    2 — configuration error

Detection layers
----------------
1. Line patterns (historical): literal tenant id written directly in SQL /
   PHP source on a single line.
2. Prepared-statement analysis (C6 post-closure corrective): the tenant
   predicate is a *placeholder* (`%d`) and the literal tenant id `1` is bound
   separately — possibly several lines below the SQL. Layer 1 is blind to this
   by construction, which is exactly how seven production finance paths kept a
   runtime `clinic_id = 1` assumption after C6 closure.

Benign-pattern handling
-----------------------
Benign patterns ("is_active = 1", "LIMIT 1", ...) suppress a positive match
only when they cover the match's own literal-`1` anchor — never the whole
line. The previous line-wide skip let any unrelated `= 1` on the same line
hide a genuine tenant hardcode.
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
# Tenant vocabulary — the only thing that makes this detector tenant-aware.
# Nothing else in this file knows what a "tenant" is.
# ---------------------------------------------------------------------------

#: Tenant foreign-key columns (final segment of a possibly alias-qualified name).
TENANT_COLUMNS = ("clinic_id", "organization_id", "location_id")

#: Tables whose own primary key *is* a tenant identifier.
TENANT_TABLES = ("cpms_clinics", "cpms_organizations", "cpms_locations")

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

    # SELECT ... LIMIT 1 used as implicit first/default Clinic.
    #
    # Deliberately narrowed to the *unparameterized* row pick: a query that
    # reaches a cpms_clinics row with no WHERE at all is choosing "some clinic"
    # implicitly. `WHERE id = %d LIMIT 1` is a normal keyed lookup and must not
    # be reported (that variant produced 7 false positives and, because the old
    # line-wide `limit_1_generic` skip always matched `LIMIT 1`, this pattern
    # was unreachable — a dead pattern — before the corrective).
    ("select_first_clinic",    r"SELECT\s+(?:(?!\bWHERE\b)[^;])*?(?<![A-Za-z0-9])cpms_clinics\b(?:(?!\bWHERE\b)[^;])*?\bLIMIT\s+1\b", "suspect"),

    # Semantic: first/default Clinic resolvers
    ("first_clinic_semantic",  r"(?:first|default)\s*(?:clinic|Clinic)",                           "suspect"),
]

# Patterns that must NOT trigger (negative cases — these are benign).
#
# Each column pattern is anchored with `(?<![\w.$])` so that it matches only a
# bare column name. Without it, `id_1_primary` also matched the `id = 1` inside
# `c.id = 1` — an alias-qualified tenant primary key that
# `sql_where_clinic_id_1` explicitly wants to catch — and silently shadowed it.
NEGATIVE_EXCLUDES: list[tuple[str, str]] = [
    # is_active = 1, capacity = 1, boolean flags, LIMIT 1 unrelated to tenant
    ("is_active_flag",     r"(?<![\w.$])is_active\s*=\s*1\b"),
    ("capacity_1",         r"(?<![\w.$])capacity\s*=\s*1\b"),
    ("is_open_1",          r"(?<![\w.$])is_open\s*=\s*1\b"),
    ("is_needed_1",        r"(?<![\w.$])is_needed\s*=\s*1\b"),
    ("attempts_1",         r"(?<![\w.$])attempts\s*=\s*1\b"),
    ("recall_count_1",     r"(?<![\w.$])recall_count\s*=\s*1\b"),
    ("max_attempts_1",     r"(?<![\w.$])max_attempts\s*=\s*1\b"),
    ("limit_1_generic",    r"\bLIMIT\s+1\b"),
    ("priority_1",         r"(?<![\w.$])priority\s*=\s*1\b"),
    ("wp_user_id_1",       r"(?<![\w.$])wp_user_id\s*=\s*1\b"),
    ("id_1_primary",       r"(?<![\w.$])id\s*=\s*1\b"),
    ("booked_count_1",     r"(?<![\w.$])booked_count\s*=\s*1\b"),
    ("held_count_1",       r"(?<![\w.$])held_count\s*=\s*1\b"),
]

#: Patterns whose signal is semantic rather than a bare literal `1`, so the
#: benign-anchor rule must not be applied to them.
ANCHOR_EXEMPT: set[str] = {"select_first_clinic", "first_clinic_semantic"}


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


# ---------------------------------------------------------------------------
# Benign-span helpers
# ---------------------------------------------------------------------------

_LITERAL_1 = re.compile(r"(?<![\w.])1\b|'1'|\"1\"")


def _benign_spans(line: str) -> list[tuple[int, int]]:
    return [m.span() for _, pat in NEGATIVE_EXCLUDES for m in re.finditer(pat, line)]


def _is_shadowed(line: str, span: tuple[int, int], pattern_name: str) -> bool:
    """True when a benign pattern covers *this match's* literal-1 anchors.

    Anchor-scoped, not line-wide: an unrelated `is_active = 1` further along the
    same line must not hide `clinic_id = 1`. A match with no literal-1 anchor is
    never suppressed here (its signal is structural, not the digit).
    """
    if pattern_name in ANCHOR_EXEMPT:
        return False

    anchors = [m.span() for m in _LITERAL_1.finditer(line, span[0], span[1])]
    if not anchors:
        return False

    benign = _benign_spans(line)
    return all(any(b[0] <= a[0] and a[1] <= b[1] for b in benign) for a in anchors)


# ---------------------------------------------------------------------------
# Layer 2 — prepared-statement analysis
# ---------------------------------------------------------------------------

#: CpmsDb entry points that carry (sql, params).
_PREPARED_CALL = re.compile(
    r"->\s*(?:fetchAll|fetchRow|fetchValue|fetchRowForUpdate|prepare|query|execute)\s*\("
)
_STRING_LIT = re.compile(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"")
_PLACEHOLDER = re.compile(r"%(?:%|d|s|f|i)")
#: Column immediately to the left of a placeholder, e.g. `pay.clinic_id = `.
_COLUMN_BEFORE = re.compile(
    r"([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)"
    r"\s*(?:<=>|!=|<>|>=|<=|=|>|\bLIKE\b|\bIN\b\s*\()\s*$",
    re.IGNORECASE,
)
#: `cpms_clinics` whether written as the bare source literal or already carrying
#: the WordPress table prefix (`wp_cpms_clinics`).
_TENANT_TABLE = re.compile(r"(?<![A-Za-z0-9])cpms_(?:clinics|organizations|locations)\b")

#: `literal | "literal" | $variable` — the three things a SQL region is made of.
_SQL_TOKEN = re.compile(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|\$(\w+)")

#: Simple single-line assignment, used to resolve SQL assembled into a variable
#: (e.g. `$where = 'clinic_id = %d' . ...; ... ' WHERE ' . $where . ...`).
#: Deliberately line-bounded: an unbounded `[^;]*` body runs across newlines and
#: swallows PHP signature defaults (`function f(bool $onlyActive = false)`),
#: which both invents variables and hides the real ones.
_VAR_ASSIGN_LINE = re.compile(r"^\s*\$(\w+)\s*=\s*(.+?);\s*$")


def _match_close(src: str, open_idx: int) -> int:
    """Index just past the paren matching the '(' at open_idx (-1 if unbalanced)."""
    depth = 0
    i = open_idx
    n = len(src)
    while i < n:
        ch = src[i]
        if ch in "'\"":
            m = _STRING_LIT.match(src, i)
            if m is None:
                return -1
            i = m.end()
            continue
        if ch == "/" and src.startswith("//", i):
            nl = src.find("\n", i)
            i = n if nl < 0 else nl
            continue
        if ch == "/" and src.startswith("/*", i):
            end = src.find("*/", i + 2)
            i = n if end < 0 else end + 2
            continue
        if ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
            if depth == 0:
                return i + 1
        i += 1
    return -1


def _top_level_groups(src: str, open_ch: str, close_ch: str) -> list[tuple[int, int]]:
    """Spans of depth-0 `open_ch ... close_ch` groups outside string literals."""
    groups: list[tuple[int, int]] = []
    depth = 0
    start = -1
    i = 0
    n = len(src)
    while i < n:
        ch = src[i]
        if ch in "'\"":
            m = _STRING_LIT.match(src, i)
            if m is None:
                return groups
            i = m.end()
            continue
        if ch == open_ch:
            if depth == 0:
                start = i
            depth += 1
        elif ch == close_ch:
            if depth > 0:
                depth -= 1
                if depth == 0 and start >= 0:
                    groups.append((start, i + 1))
                    start = -1
        i += 1
    return groups


def _split_top_level(src: str) -> list[str]:
    """Split a bracket body on depth-0 commas, respecting strings/brackets."""
    parts: list[str] = []
    depth = 0
    cur = ""
    i = 0
    n = len(src)
    while i < n:
        ch = src[i]
        if ch in "'\"":
            m = _STRING_LIT.match(src, i)
            if m is None:
                break
            cur += src[i:m.end()]
            i = m.end()
            continue
        if ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
        if ch == "," and depth == 0:
            parts.append(cur)
            cur = ""
            i += 1
            continue
        cur += ch
        i += 1
    if cur.strip():
        parts.append(cur)
    return parts


def _is_literal_one(element: str) -> bool:
    return element.strip() in ("1", "'1'", '"1"')


def _string_map(src: str) -> dict[str, str]:
    """Single-assignment string variables of a file, keyed by name.

    Only variables assigned exactly once are resolved: a re-assigned variable
    could mean two different SQL fragments and guessing would manufacture
    findings. Unresolvable variables simply contribute nothing.
    """
    counts: dict[str, int] = {}
    bodies: dict[str, str] = {}
    for raw in src.splitlines():
        if is_in_comment(raw):
            continue
        m = _VAR_ASSIGN_LINE.match(raw)
        if m is None:
            continue
        name = m.group(1)
        counts[name] = counts.get(name, 0) + 1
        bodies[name] = m.group(2)

    return {n: b for n, b in bodies.items() if counts[n] == 1}


def _sql_text(region: str, varmap: Optional[dict[str, str]] = None, depth: int = 0) -> str:
    """Rebuild the SQL of a call region from its literals (and known variables).

    Order is preserved: `$where` is substituted where it appears, so the
    placeholder sequence stays aligned with the params array.
    """
    parts: list[str] = []
    for m in _SQL_TOKEN.finditer(region):
        token = m.group(0)
        if token.startswith("$"):
            name = m.group(1) or ""
            if varmap is not None and depth < 2 and name in varmap:
                parts.append(_sql_text(varmap[name], varmap, depth + 1))
            continue
        parts.append(token[1:-1])

    return "".join(parts)


def _placeholder_columns(sql: str) -> list[Optional[str]]:
    """Column bound to each real placeholder, in order (`%%` is not one)."""
    columns: list[Optional[str]] = []
    for m in _PLACEHOLDER.finditer(sql):
        if m.group(0) == "%%":
            continue
        col = _COLUMN_BEFORE.search(sql[:m.start()])
        columns.append(col.group(1) if col else None)
    return columns


def _tenant_targets(sql: str, columns: list[Optional[str]]) -> set[int]:
    """Indexes of placeholders that bind a tenant identifier."""
    on_tenant_table = _TENANT_TABLE.search(sql) is not None
    targets: set[int] = set()
    for idx, column in enumerate(columns):
        if column is None:
            continue
        leaf = column.rsplit(".", 1)[-1]
        if leaf in TENANT_COLUMNS:
            targets.add(idx)
        elif leaf == "id" and on_tenant_table:
            # `SELECT id FROM cpms_clinics WHERE id = %d FOR UPDATE` — the row
            # lock / row pick of a tenant table is a tenant identifier.
            targets.add(idx)
    return targets


def scan_prepared(src: str, path: str) -> list[Finding]:
    """Find prepared calls where a tenant placeholder is bound to literal 1."""
    findings: list[Finding] = []
    varmap = _string_map(src)
    for call in _PREPARED_CALL.finditer(src):
        open_idx = call.end() - 1
        close = _match_close(src, open_idx)
        if close < 0:
            continue
        window = src[open_idx + 1:close - 1]

        # The params array is the last depth-0 `[...]` of the call; everything
        # before it is the SQL region.
        groups = _top_level_groups(window, "[", "]")
        if not groups:
            continue
        params_start, params_end = groups[-1]
        elements = _split_top_level(window[params_start + 1:params_end - 1])
        if not elements:
            continue

        sql = _sql_text(window[:params_start], varmap)
        if "%d" not in sql and "%s" not in sql:
            continue

        targets = _tenant_targets(sql, _placeholder_columns(sql))
        if not targets:
            continue

        for idx in sorted(targets):
            if idx >= len(elements) or not _is_literal_one(elements[idx]):
                continue
            element_start = _element_offset(window, params_start, elements, idx)
            line = src.count("\n", 0, open_idx + 1 + element_start) + 1
            findings.append(Finding(
                file=path,
                line=line,
                pattern="prepared_tenant_placeholder_literal_1",
                category="hardcode",
                text=(
                    "tenant placeholder bound to literal 1 — "
                    + " ".join(sql.split())[:220]
                    + "  ||  params: ["
                    + ", ".join(e.strip() for e in elements)[:160]
                    + "]"
                ),
            ))
            break  # one finding per prepared call
    return findings


def _element_offset(window: str, params_start: int, elements: list[str], idx: int) -> int:
    """Byte offset (relative to `window`) of the idx-th top-level element."""
    body_start = params_start + 1
    depth = 0
    seen = 0
    i = 0
    n = len(window)
    while i < n:
        ch = window[i]
        if ch in "'\"":
            m = _STRING_LIT.match(window, i)
            if m is None:
                break
            i = m.end()
            continue
        if ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
        elif ch == "," and depth == 0:
            seen += 1
            if seen == idx:
                return i + 1
        i += 1
    return body_start


# ---------------------------------------------------------------------------
# Source scanning (shared by production scan and self-tests)
# ---------------------------------------------------------------------------

def scan_source(src: str, path: str, allowlist: set[str]) -> list[Finding]:
    """Scan PHP source for tenant hardcodes (both layers)."""
    findings: list[Finding] = []
    lines = src.splitlines(keepends=True)

    for lineno, line in enumerate(lines, 1):
        # Skip comments — policy: comments are not runtime behavior
        if is_in_comment(line):
            continue

        # Check if this file:line is allowlisted
        if f"{path}:{lineno}" in allowlist or path in allowlist:
            continue

        bare = line.rstrip("\r\n")
        for pat_name, pat_regex, category in PATTERNS:
            m = re.search(pat_regex, bare)
            if m is None:
                continue
            if _is_shadowed(bare, m.span(), pat_name):
                continue
            findings.append(Finding(
                file=path, line=lineno, pattern=pat_name,
                category=category, text=bare.rstrip(),
            ))
            break  # one finding per line

    prepared = scan_prepared(src, path)
    if prepared:
        reported = {f.line for f in findings}
        findings.extend(f for f in prepared if f.line not in reported)

    return findings


def scan_file(filepath: str, allowlist: set[str]) -> list[Finding]:
    """Scan a single PHP file for tenant hardcode patterns."""
    try:
        with open(filepath, encoding="utf-8", errors="replace") as f:
            src = f.read()
    except (OSError, UnicodeDecodeError):
        return []

    return scan_source(src, filepath, allowlist)


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
    # Shadowing regressions (C6 post-closure corrective): a benign flag on the
    # same line, or an alias-qualified tenant primary key, must not hide it.
    ("WHERE clinic_id = 1 AND is_active = 1",                     True),
    ("WHERE a.clinic_id = 1 ORDER BY id ASC LIMIT 1",             True),
    ("WHERE c.id = 1",                                            True),
    ("SELECT * FROM cpms_invoices WHERE clinic_id = 1 AND priority = 1", True),
    ("'clinic_id' => 1, 'is_active' => 1,",                       True),
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

# --- Layer 2: prepared statements (tenant placeholder + separately bound 1) ---
#
# Each case is a realistic CpmsDb call site. `expect` = should be reported.
# The seven historical finance defects are reproduced verbatim in shape.

PREPARED_CASES: list[tuple[str, bool]] = [
    # ---- MUST be rejected (historical bad patterns) -------------------------
    # ServiceRepository::all() — single-line array
    (
        "        return $this->db->fetchAll(\n"
        "            'SELECT * FROM ' . $this->db->table('cpms_services') .\n"
        "            ' WHERE clinic_id = %d ORDER BY name ASC LIMIT 500',\n"
        "            [1]\n"
        "        ) ?: [];\n",
        True,
    ),
    # InvoiceRepository::nextInvoiceNumber() — literal 1 first in a 2-element array
    (
        "        $max = $this->db->fetchValue(\n"
        "            'SELECT MAX(invoice_number) FROM ' . $this->db->table('cpms_invoices') .\n"
        "            \" WHERE clinic_id = %d AND invoice_number LIKE %s\",\n"
        "            [1, $prefix . '%']\n"
        "        );\n",
        True,
    ),
    # PaymentRepository::revenueSummary() — 3 placeholders, literal 1 first
    (
        "        $rows = $this->db->fetchAll(\n"
        "            'SELECT method, amount FROM ' . $this->db->table('cpms_payments') .\n"
        "            \" WHERE clinic_id = %d AND status IN ('captured', 'refunded')\" .\n"
        "            ' AND paid_at >= %s AND paid_at < %s',\n"
        "            [1, $fromDate . ' 00:00:00', $toDate . ' 23:59:59.999']\n"
        "        ) ?: [];\n",
        True,
    ),
    # PaymentRepository::forRange() — alias-qualified tenant column + LIMIT %d
    (
        "        return $this->db->fetchAll(\n"
        "            'SELECT pay.* FROM ' . $this->db->table('cpms_payments') . ' pay' .\n"
        "            ' WHERE pay.clinic_id = %d' .\n"
        "            ' AND pay.paid_at >= %s AND pay.paid_at < %s' .\n"
        "            ' ORDER BY pay.id DESC LIMIT %d',\n"
        "            [1, $fromDate . ' 00:00:00', $toDate . ' 23:59:59.999', $limit]\n"
        "        ) ?: [];\n",
        True,
    ),
    # InvoiceRepository::openInvoices() — JOIN, tenant predicate first
    (
        "        return $this->db->fetchAll(\n"
        "            'SELECT i.*, p.mrn FROM ' . $this->db->table('cpms_invoices') . ' i' .\n"
        "            ' JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = i.patient_id' .\n"
        "            \" WHERE i.clinic_id = %d AND i.status IN ('open', 'partial')\" .\n"
        "            ' ORDER BY i.id DESC LIMIT %d',\n"
        "            [1, $limit]\n"
        "        ) ?: [];\n",
        True,
    ),
    # FinanceService::lockClinic() — Clinic row lock via tenant-table PK
    (
        "        $this->db->fetchRowForUpdate(\n"
        "            'SELECT id FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',\n"
        "            [1]\n"
        "        );\n",
        True,
    ),
    # ServiceRepository::all() — the tenant predicate lives in a local variable,
    # so the call site itself shows no placeholder. The detector resolves the
    # single-assignment variable rather than giving up (7th historical site).
    (
        "    public function all(bool $onlyActive = false): array\n"
        "    {\n"
        "        $where = 'clinic_id = %d' . ($onlyActive ? ' AND is_active = 1' : '');\n"
        "\n"
        "        return $this->db->fetchAll(\n"
        "            'SELECT * FROM ' . $this->db->table('cpms_services') .\n"
        "            ' WHERE ' . $where . ' ORDER BY name ASC LIMIT 500',\n"
        "            [1]\n"
        "        ) ?: [];\n"
        "    }\n",
        True,
    ),
    # literal 1 in a non-first position, string form
    (
        "        $row = $this->db->fetchRow(\n"
        "            'SELECT * FROM ' . $this->db->table('cpms_invoices') .\n"
        "            ' WHERE status = %s AND clinic_id = %d',\n"
        "            ['open', '1']\n"
        "        );\n",
        True,
    ),
    # organization_id / location_id variants
    (
        "        $n = $this->db->fetchValue(\n"
        "            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_locations') .\n"
        "            ' WHERE clinic_id = %d AND organization_id = %d',\n"
        "            [$clinicId, 1]\n"
        "        );\n",
        True,
    ),

    # ---- MUST remain accepted (legitimate code) -----------------------------
    # explicit tenant variable — the shape this corrective migrates the code to
    (
        "        $where = 'clinic_id = %d' . ($onlyActive ? ' AND is_active = 1' : '');\n"
        "\n"
        "        return $this->db->fetchAll(\n"
        "            'SELECT * FROM ' . $this->db->table('cpms_services') .\n"
        "            ' WHERE ' . $where . ' ORDER BY name ASC LIMIT 500',\n"
        "            [$clinicId]\n"
        "        ) ?: [];\n",
        False,
    ),
    (
        "        return $this->db->fetchAll(\n"
        "            'SELECT * FROM ' . $this->db->table('cpms_services') .\n"
        "            ' WHERE clinic_id = %d ORDER BY name ASC LIMIT 500',\n"
        "            [$clinicId]\n"
        "        ) ?: [];\n",
        False,
    ),
    (
        "        $max = $this->db->fetchValue(\n"
        "            'SELECT MAX(invoice_number) FROM ' . $this->db->table('cpms_invoices') .\n"
        "            \" WHERE clinic_id = %d AND invoice_number LIKE %s\",\n"
        "            [$clinicId, $prefix . '%']\n"
        "        );\n",
        False,
    ),
    (
        "        $this->db->fetchRowForUpdate(\n"
        "            'SELECT id FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',\n"
        "            [$clinicId]\n"
        "        );\n",
        False,
    ),
    # legitimate integer 1 bound to a non-tenant column
    (
        "        $rows = $this->db->fetchAll(\n"
        "            'SELECT id FROM ' . $this->db->table('cpms_services') .\n"
        "            ' WHERE clinic_id = %d AND is_active = %d',\n"
        "            [$clinicId, 1]\n"
        "        ) ?: [];\n",
        False,
    ),
    (
        "        $row = $this->db->fetchRow(\n"
        "            'SELECT id FROM ' . $this->db->table('cpms_slots') .\n"
        "            ' WHERE slot_id = %d AND capacity = %d LIMIT 1',\n"
        "            [$slotId, 1]\n"
        "        );\n",
        False,
    ),
    # non-tenant table primary key bound to 1 is not a tenant statement
    (
        "        $row = $this->db->fetchRow(\n"
        "            'SELECT * FROM ' . $this->db->table('cpms_invoices') . ' WHERE id = %d LIMIT 1',\n"
        "            [$invoiceId]\n"
        "        );\n",
        False,
    ),
    # unparameterized aggregate over the tenant table (exactly-one resolver)
    (
        "        $count = (int) $db->fetchValue(\n"
        "            'SELECT COUNT(*) FROM ' . $db->table('cpms_clinics')\n"
        "        );\n",
        False,
    ),
    # no params array at all
    (
        "        $rows = $this->db->fetchAll(\n"
        "            'SELECT id FROM ' . $this->db->table('cpms_clinics') . ' WHERE is_active = 1'\n"
        "        );\n",
        False,
    ),
    # params passed by variable — undecidable, must not guess
    (
        "        $rows = $this->db->fetchAll($sql, $params);\n",
        False,
    ),
]

# --- Layer 1 semantic suspects: implicit "first clinic" row picks ------------

SELECT_FIRST_CASES: list[tuple[str, bool]] = [
    # unparameterized clinic row pick — the historical "default clinic" shape
    ("SELECT id FROM wp_cpms_clinics LIMIT 1",                                  True),
    ("SELECT * FROM cpms_clinics ORDER BY id ASC LIMIT 1",                      True),
    # keyed lookups are ordinary and must not be reported
    ("'SELECT id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',", False),
    ("'SELECT name FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',", False),
]


def _line_detects(code: str) -> bool:
    """Layer-1 verdict for a single source line (self-test helper)."""
    if is_in_comment(code):
        return False
    for pat_name, pat_regex, _category in PATTERNS:
        m = re.search(pat_regex, code)
        if m is not None and not _is_shadowed(code, m.span(), pat_name):
            return True
    return False


def run_self_tests() -> tuple[int, int]:
    """Run detector self-tests. Returns (passes, failures)."""
    passes = 0
    failures = 0

    def check(code: str, expected: bool, detected: bool) -> None:
        nonlocal passes, failures
        label = " ".join(code.split())[:78]
        if detected == expected:
            passes += 1
            print(f"  PASS: {label}")
        else:
            failures += 1
            print(f"  FAIL: {label}  (detected={detected}, expected={expected})")

    print("=== Positive cases (must detect) ===")
    for code, should_detect in POSITIVE_CASES:
        check(code, should_detect, _line_detects(code))

    print("\n=== Negative cases (must NOT detect) ===")
    for code, should_not_detect in NEGATIVE_CASES:
        check(code, should_not_detect, _line_detects(code))

    print("\n=== Prepared-statement cases (tenant placeholder + bound literal 1) ===")
    for code, expected in PREPARED_CASES:
        found = scan_source(code, "selftest.php", set())
        check(code, expected, any(f.pattern == "prepared_tenant_placeholder_literal_1" for f in found))

    print("\n=== Implicit first-Clinic row picks ===")
    for code, expected in SELECT_FIRST_CASES:
        found = [f for f in scan_source(code, "selftest.php", set())
                 if f.pattern == "select_first_clinic"]
        check(code, expected, bool(found))

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
