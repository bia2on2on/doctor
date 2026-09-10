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

    # SELECT ... LIMIT 1 used as implicit first/default Clinic.
    # C6 corrective: narrowed so it no longer fires on an *explicit*,
    # parameterized clinic lookup (`... cpms_clinics ... WHERE id = %d LIMIT 1`).
    # The `(?:(?!%d).)*` guard requires that no bound placeholder appears
    # between the clinics table and LIMIT 1, i.e. the row really is picked
    # "first/default" rather than by an explicit id.
    ("select_first_clinic",    r"SELECT\s+.*\bcpms_clinics\b(?:(?!%d).)*\bLIMIT\s+1\b",           "suspect"),

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
    # NOTE (C6 corrective): `limit_1_generic` (r"LIMIT\s+1\s*") was REMOVED from
    # this list. It was provably shadow-only: no positive pattern can match a
    # bare "LIMIT 1", so the exclude never protected a benign line — it only
    # suppressed real findings on any line that happened to also contain
    # "LIMIT 1" (e.g. the `select_first_clinic` suspect pattern, which by
    # construction always contains "LIMIT 1" and therefore could NEVER fire).
    # Removing it strengthens detection; the "bare LIMIT 1 is benign" property
    # is preserved because no positive pattern matches it — asserted by the
    # self-test case "LIMIT 1" below.
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
# Bound-parameter tenant literal detection (C6 corrective)
#
# Blind spot this closes: the seven post-closure finance defects did not write
# `clinic_id = 1` anywhere. They wrote the *correct-looking* prepared form
#
#     " WHERE clinic_id = %d AND invoice_number LIKE %s",
#     [1, $prefix . '%']
#
# The tenant identifier is a placeholder; the literal tenant ID 1 is bound
# separately, usually on another line. A line-oriented regex over "clinic_id = 1"
# can never see that, which is why the production scan reported CLEAN while all
# seven paths were pinned to Clinic 1 at runtime.
#
# This pass is therefore statement-oriented and tenant-aware:
#   1. strip comments, split into statements;
#   2. expand SQL fragments held in local variables ($where = 'clinic_id = %d' . ...)
#      so predicates built outside the call are still seen;
#   3. rebuild the effective SQL by concatenating the statement's string literals;
#   4. find tenant predicates that use a placeholder — `<alias.>clinic_id = %d`
#      (also organization_id / location_id), plus the Clinic-row selector
#      `... cpms_clinics ... WHERE id = %d ... FOR UPDATE`;
#   5. resolve which positional bind feeds that placeholder and flag it only
#      when the bound argument is the literal 1 / '1'.
#
# Anything that is not the literal 1 — a variable, a property, a call such as
# App::scope()->clinicId — is accepted. Non-tenant placeholders are never
# inspected, so `[1, $limit]` on a `LIMIT %d` or `is_active = %d` stays clean.
# ---------------------------------------------------------------------------

TENANT_PREDICATE_PLACEHOLDER = re.compile(
    r"(?:[A-Za-z_][A-Za-z0-9_]*\s*\.\s*)?"
    r"(?P<col>clinic_id|organization_id|location_id)\s*=\s*%d"
)
PLACEHOLDER = re.compile(r"%[dsf]")
CLINIC_ROW_PREDICATE = re.compile(r"(?<![A-Za-z0-9_.])id\s*=\s*%d")
CLINICS_TABLE = re.compile(r"cpms_clinics\b")
SQL_VAR_ASSIGN = re.compile(r"\$(?P<var>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?P<rhs>[^;]*);", re.S)
STRING_LITERAL = re.compile(r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"", re.S)
LITERAL_ONE = ("1", "'1'", '"1"')


def strip_php_comments(src: str) -> str:
    """Blank out // # and /* */ comments, preserving offsets, newlines and literals.

    Comment characters are replaced by spaces (not removed) so that byte offsets
    in the returned string map 1:1 onto the original source — the caller reports
    findings by line number.
    """
    out: list[str] = []
    i, n = 0, len(src)
    quote: Optional[str] = None
    while i < n:
        c = src[i]
        if quote is not None:
            out.append(c)
            if c == "\\" and i + 1 < n:
                out.append(src[i + 1])
                i += 2
                continue
            if c == quote:
                quote = None
            i += 1
            continue
        if c in ("'", '"'):
            quote = c
            out.append(c)
            i += 1
            continue
        if c == "/" and i + 1 < n and src[i + 1] == "/":
            while i < n and src[i] != "\n":
                out.append(" ")
                i += 1
            continue
        if c == "#" and not (i + 1 < n and src[i + 1] == "["):
            while i < n and src[i] != "\n":
                out.append(" ")
                i += 1
            continue
        if c == "/" and i + 1 < n and src[i + 1] == "*":
            out.append("  ")
            i += 2
            while i + 1 < n and not (src[i] == "*" and src[i + 1] == "/"):
                out.append("\n" if src[i] == "\n" else " ")
                i += 1
            if i + 1 < n:
                out.append("  ")
                i += 2
            continue
        out.append(c)
        i += 1
    return "".join(out)


def split_statements(src: str) -> list[str]:
    """Split comment-free PHP source into statements on top-level semicolons."""
    stmts: list[str] = []
    buf: list[str] = []
    depth = 0
    i, n = 0, len(src)
    quote: Optional[str] = None
    while i < n:
        c = src[i]
        if quote is not None:
            buf.append(c)
            if c == "\\" and i + 1 < n:
                buf.append(src[i + 1])
                i += 2
                continue
            if c == quote:
                quote = None
            i += 1
            continue
        if c in ("'", '"'):
            quote = c
            buf.append(c)
            i += 1
            continue
        # Only () and [] count: a `;` inside a function/class body is still a
        # statement terminator, so braces must NOT raise the depth here.
        if c in "([":
            depth += 1
        elif c in ")]":
            depth = max(0, depth - 1)
        elif c == ";" and depth == 0:
            stmts.append("".join(buf))
            buf = []
            i += 1
            continue
        buf.append(c)
        i += 1
    if buf:
        stmts.append("".join(buf))
    return [s for s in stmts if s.strip() != ""]


def string_literals(expr: str) -> list[str]:
    """Ordered contents of every single/double quoted literal in expr."""
    out = []
    for m in STRING_LITERAL.finditer(expr):
        out.append(m.group(1) if m.lastindex == 1 else (m.group(2) or ""))
    return out


def extract_sql_vars(src: str) -> dict[str, str]:
    """Map `$var` -> concatenated literal SQL for simple SQL-fragment assignments.

    Assignments are recognised per *statement* (not by a file-wide regex) so that
    a default parameter value such as `function all(bool $onlyActive = false)`
    cannot be mistaken for one. An RHS containing `->` stores a call *result*
    (e.g. `$max = $this->db->fetchValue('SELECT ...', [...])`), not an SQL
    fragment, so those are skipped too.
    """
    out: dict[str, str] = {}
    for stmt in split_statements(src):
        for line in stmt.split("\n"):
            # Line-anchored: in this codebase (WPCS) an assignment statement
            # starts its own line, which keeps `function all(bool $x = false)`
            # out of the picture.
            m = re.match(r"\s*\$(?P<var>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?P<rhs>.+)$", line)
            if m is None:
                continue
            rhs = m.group("rhs")
            if "->" in rhs:
                continue
            lits = string_literals(rhs)
            if not lits:
                continue
            joined = " ".join(lits)
            if "%" not in joined and "clinic_id" not in joined and "_id" not in joined:
                continue
            out[m.group("var")] = joined
    return out


def as_php_literal(text: str) -> str:
    return '"' + text.replace("\\", "\\\\").replace('"', '\\"') + '"'


def expand_sql_vars(stmt: str, sql_vars: dict[str, str]) -> str:
    """Inline known SQL fragments, but only where they sit in a `.` concatenation."""
    for var, lit in sql_vars.items():
        php = as_php_literal(lit)
        esc = re.escape(var)
        stmt = re.sub(r"(\.\s*)\$" + esc + r"\b", lambda m: m.group(1) + php, stmt)
        stmt = re.sub(r"\$" + esc + r"(\s*\.)", lambda m: php + m.group(1), stmt)
    return stmt


def array_literals(stmt: str) -> list[str]:
    """Contents of every `[ ... ]` array literal (subscripts such as $m[1] skipped)."""
    out: list[str] = []
    i, n = 0, len(stmt)
    quote: Optional[str] = None
    while i < n:
        c = stmt[i]
        if quote is not None:
            if c == "\\":
                i += 2
                continue
            if c == quote:
                quote = None
            i += 1
            continue
        if c in ("'", '"'):
            quote = c
            i += 1
            continue
        if c == "[":
            prev = stmt[:i].rstrip()
            if prev and (prev[-1].isalnum() or prev[-1] in "_)$]"):
                i += 1  # subscript, not a literal
                continue
            depth = 1
            j = i + 1
            while j < n and depth > 0:
                cj = stmt[j]
                if cj in ("'", '"'):
                    q = cj
                    j += 1
                    while j < n:
                        if stmt[j] == "\\":
                            j += 2
                            continue
                        if stmt[j] == q:
                            break
                        j += 1
                elif cj == "[":
                    depth += 1
                elif cj == "]":
                    depth -= 1
                    if depth == 0:
                        break
                j += 1
            out.append(stmt[i + 1:j])
            i = j + 1
            continue
        i += 1
    return out


def split_top_commas(expr: str) -> list[str]:
    """Split a bind array body on depth-0 commas, respecting quotes/brackets."""
    parts: list[str] = []
    buf: list[str] = []
    depth = 0
    i, n = 0, len(expr)
    quote: Optional[str] = None
    while i < n:
        c = expr[i]
        if quote is not None:
            buf.append(c)
            if c == "\\" and i + 1 < n:
                buf.append(expr[i + 1])
                i += 2
                continue
            if c == quote:
                quote = None
            i += 1
            continue
        if c in ("'", '"'):
            quote = c
            buf.append(c)
            i += 1
            continue
        if c in "([{":
            depth += 1
        elif c in ")]}":
            depth -= 1
        elif c == "," and depth == 0:
            parts.append("".join(buf))
            buf = []
            i += 1
            continue
        buf.append(c)
        i += 1
    parts.append("".join(buf))
    return [p.strip() for p in parts]


def bind_index(sql: str, pos: int) -> int:
    """Positional index of the placeholder that starts at offset `pos`."""
    return len(PLACEHOLDER.findall(sql[:pos]))


def detect_bound_tenant_literals(stmt: str, sql_vars: dict[str, str]) -> list[tuple[str, int]]:
    """Return (detector name, offset in stmt) for literal tenant ID 1 bound to a
    tenant placeholder. The offset lets the caller report the exact source line.
    """
    expanded = expand_sql_vars(stmt, sql_vars)
    sql = "".join(string_literals(expanded))
    if sql == "":
        return []
    bodies = [a for a in array_literals(expanded) if a.strip() != ""]
    if not bodies:
        return []
    bind = split_top_commas(bodies[-1])
    if not bind:
        return []

    hits: dict[str, str] = {}
    for m in TENANT_PREDICATE_PLACEHOLDER.finditer(sql):
        idx = bind_index(sql, m.start())
        if idx < len(bind) and bind[idx] in LITERAL_ONE:
            hits.setdefault("bound_tenant_literal_1", m.group(0))
    if CLINICS_TABLE.search(sql) is not None:
        for m in CLINIC_ROW_PREDICATE.finditer(sql):
            idx = bind_index(sql, m.start())
            if idx < len(bind) and bind[idx] in LITERAL_ONE:
                hits.setdefault("bound_clinic_row_literal_1", m.group(0))

    out: list[tuple[str, int]] = []
    for name, predicate in sorted(hits.items()):
        # Point at the predicate as written in the source (it may be split across
        # string literals, so fall back to the statement start).
        col = predicate.split("=")[0].strip().split(".")[-1].strip()
        rel = stmt.find(col + " = %d")
        if rel < 0:
            rel = stmt.find(col + "= %d")
        out.append((name, rel if rel >= 0 else 0))
    return out


def scan_file_bound_params(filepath: str, src: str, allowlist: set[str]) -> list[Finding]:
    """Statement-oriented scan for tenant ID 1 bound through a placeholder."""
    findings: list[Finding] = []
    clean = strip_php_comments(src)
    sql_vars = extract_sql_vars(clean)
    src_lines = src.splitlines()
    offset = 0
    for stmt in split_statements(clean):
        start = clean.find(stmt, offset)
        if start < 0:
            start = offset
        offset = start + len(stmt) + 1
        for name, rel in detect_bound_tenant_literals(stmt, sql_vars):
            line_no = src.count("\n", 0, start + rel) + 1
            key_exact = f"{filepath}:{line_no}"
            if key_exact in allowlist or filepath in allowlist:
                continue
            text = src_lines[line_no - 1].strip() if line_no <= len(src_lines) else stmt.strip()
            findings.append(Finding(
                file=filepath,
                line=line_no,
                pattern=name,
                category="hardcode",
                text=text,
            ))
    return findings


def detect_bound_tenant_literals_in_snippet(code: str) -> bool:
    """Self-test helper: does this PHP snippet bind literal tenant ID 1?"""
    clean = strip_php_comments(code)
    sql_vars = extract_sql_vars(clean)
    return any(
        detect_bound_tenant_literals(stmt, sql_vars)
        for stmt in split_statements(clean)
    )


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


# ---------------------------------------------------------------------------
# Self-tests for the bound-parameter pass (C6 corrective)
#
# POSITIVE_BOUND = the historical defect shapes: a tenant placeholder with the
# literal tenant ID 1 bound separately. These MUST be rejected.
# NEGATIVE_BOUND = legitimate uses of the integer 1 and explicit tenant
# variables. These MUST be accepted.
# ---------------------------------------------------------------------------

POSITIVE_BOUND_CASES: list[str] = [
    # 1. ServiceRepository::all() — historic shape (single-line bind)
    """
    return $this->db->fetchAll(
        'SELECT * FROM ' . $this->db->table('cpms_services') .
        ' WHERE clinic_id = %d ORDER BY name ASC LIMIT 500',
        [1]
    ) ?: [];
    """,
    # 2. InvoiceRepository::nextInvoiceNumber() — multiline prepared call, bind array
    #    mixes the tenant literal with a non-tenant value.
    """
    $max = $this->db->fetchValue(
        'SELECT MAX(invoice_number) FROM ' . $this->db->table('cpms_invoices') .
        " WHERE clinic_id = %d AND invoice_number LIKE %s",
        [1, $prefix . '%']
    );
    """,
    # 3. PaymentRepository::nextPaymentNumber()
    """
    $max = $this->db->fetchValue(
        'SELECT MAX(payment_number) FROM ' . $this->db->table('cpms_payments') .
        " WHERE clinic_id = %d AND payment_number LIKE %s",
        [1, $prefix . '%']
    );
    """,
    # 4. PaymentRepository::revenueSummary() — several binds after the tenant one
    """
    $rows = $this->db->fetchAll(
        'SELECT method, amount FROM ' . $this->db->table('cpms_payments') .
        " WHERE clinic_id = %d AND status IN ('captured', 'refunded')" .
        ' AND paid_at >= %s AND paid_at < %s',
        [1, $fromDate . ' 00:00:00', $toDate . ' 23:59:59.999']
    ) ?: [];
    """,
    # 5. PaymentRepository::forRange() — aliased tenant predicate + LIMIT %d
    """
    return $this->db->fetchAll(
        'SELECT pay.* FROM ' . $this->db->table('cpms_payments') . ' pay' .
        ' JOIN ' . $this->db->table('cpms_invoices') . ' inv ON inv.id = pay.invoice_id' .
        ' WHERE pay.clinic_id = %d' .
        ' ORDER BY pay.id DESC LIMIT %d',
        [1, $limit]
    ) ?: [];
    """,
    # 6. InvoiceRepository::openInvoices() — JOIN leaking patient name/MRN
    """
    return $this->db->fetchAll(
        'SELECT i.*, p.first_name, p.mrn FROM ' . $this->db->table('cpms_invoices') . ' i' .
        ' JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = i.patient_id' .
        " WHERE i.clinic_id = %d AND i.status IN ('open', 'partial')" .
        ' ORDER BY i.id DESC LIMIT %d',
        [1, $limit]
    ) ?: [];
    """,
    # 7. FinanceService::lockClinic() — Clinic row lock/select pattern
    """
    $this->db->fetchRowForUpdate(
        'SELECT id FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',
        [1]
    );
    """,
    # 8. SQL fragment held in a variable and concatenated into the call —
    #    the predicate is not textually inside the call at all.
    """
    $where = 'clinic_id = %d' . ($onlyActive ? ' AND is_active = 1' : '');
    return $this->db->fetchAll(
        'SELECT * FROM ' . $this->db->table('cpms_services') .
        ' WHERE ' . $where . ' ORDER BY name ASC LIMIT 500',
        [1]
    ) ?: [];
    """,
    # 9. organization_id / location_id variants
    """
    $rows = $this->db->fetchAll('SELECT * FROM t WHERE organization_id = %d', [1]);
    """,
    """
    $rows = $this->db->fetchAll('SELECT * FROM t WHERE location_id = %d', [1]);
    """,
    # 10. string '1' bound to a tenant placeholder
    """
    $rows = $this->db->fetchAll('SELECT * FROM t WHERE clinic_id = %d', ['1']);
    """,
]

NEGATIVE_BOUND_CASES: list[str] = [
    # Explicit tenant variable — the accepted shape.
    """
    return $this->db->fetchAll(
        'SELECT * FROM ' . $this->db->table('cpms_services') .
        ' WHERE clinic_id = %d ORDER BY name ASC LIMIT 500',
        [$clinic_id]
    ) ?: [];
    """,
    # Trusted scope property / call — also accepted.
    """
    $rows = $this->db->fetchAll('SELECT * FROM t WHERE clinic_id = %d', [App::scope()->clinicId]);
    """,
    """
    $rows = $this->db->fetchAll('SELECT * FROM t WHERE clinic_id = %d', [$this->scope->clinicId]);
    """,
    # Legitimate integer 1 on a NON-tenant placeholder: is_active = %d
    """
    $rows = $this->db->fetchAll('SELECT * FROM t WHERE is_active = %d', [1]);
    """,
    # Legitimate integer 1 on LIMIT %d while the tenant slot is a variable —
    # this is exactly the historic `[1, $limit]` shape with the tenant fixed.
    """
    return $this->db->fetchAll(
        'SELECT * FROM ' . $this->db->table('cpms_invoices') . ' i' .
        " WHERE i.clinic_id = %d AND i.status IN ('open', 'partial')" .
        ' ORDER BY i.id DESC LIMIT %d',
        [$clinic_id, $limit]
    ) ?: [];
    """,
    # Tenant slot correct, a later non-tenant slot legitimately bound to 1.
    """
    $rows = $this->db->fetchAll(
        'SELECT * FROM t WHERE clinic_id = %d AND is_active = %d',
        [$clinic_id, 1]
    );
    """,
    # Regex capture-group subscript `$m[1]` must not be read as a bind array.
    """
    if (is_string($max) && preg_match('/^INV-\\d{6}-(\\d{3,})$/', $max, $m) === 1) {
        $seq = (int) $m[1];
    }
    """,
    # Clinic row locked by an explicit parameter — accepted.
    """
    $this->db->fetchRowForUpdate(
        'SELECT id FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',
        [$clinicId]
    );
    """,
    # SQL fragment in a variable, bound through a variable.
    """
    $where = 'clinic_id = %d' . ($onlyActive ? ' AND is_active = 1' : '');
    return $this->db->fetchAll(
        'SELECT * FROM ' . $this->db->table('cpms_services') .
        ' WHERE ' . $where . ' ORDER BY name ASC LIMIT 500',
        [$clinic_id]
    ) ?: [];
    """,
    # No tenant placeholder at all — a bare integer 1 is irrelevant.
    """
    $rows = $this->db->fetchAll('SELECT * FROM t WHERE priority = %d ORDER BY id LIMIT 1', [1]);
    """,
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

    print("\n=== Bound-parameter positives (tenant literal 1 bound via %d — must detect) ===")
    for idx, code in enumerate(POSITIVE_BOUND_CASES, 1):
        found = detect_bound_tenant_literals_in_snippet(code)
        label = " ".join(code.split())[:78]
        if found:
            passes += 1
            print(f"  PASS: bound#{idx}: {label}")
        else:
            failures += 1
            print(f"  FAIL: bound#{idx}: {label}  (detected=False, expected=True)")

    print("\n=== Bound-parameter negatives (legitimate 1 / explicit tenant — must NOT detect) ===")
    for idx, code in enumerate(NEGATIVE_BOUND_CASES, 1):
        found = detect_bound_tenant_literals_in_snippet(code)
        label = " ".join(code.split())[:78]
        if not found:
            passes += 1
            print(f"  PASS: bound-neg#{idx}: {label}")
        else:
            failures += 1
            print(f"  FAIL: bound-neg#{idx}: {label}  (detected=True, expected=False)")

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
        try:
            with open(fpath, encoding="utf-8", errors="replace") as fh:
                src = fh.read()
        except OSError:
            continue
        all_findings.extend(scan_file_bound_params(fpath, src, allowlist))

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
