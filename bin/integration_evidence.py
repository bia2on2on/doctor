#!/usr/bin/env python3
"""Fail-closed projection of Integration CI diagnostics for PUBLIC mirrors.

This is the single boundary through which every Integration CI publication
path (PR comments, step summaries, sanitized artifacts) sees diagnostic
content. Everything printed is one of:

  * a fixed token from an allow-list,
  * a validated integer or boolean,
  * a SOURCE-CONTROLLED identifier (Class::method, suite name) verified
    against the checked-out tree, or
  * a count derived from XML attributes or from our own evidence lines.

NEVER printed: raw driver text, exception messages, SQL text or fragments,
fixture values (ids, keys, names), engine/InnoDB dumps, performance_schema
``lock_data``, file paths, stack frames, or any free-text fallback. A field
that fails its validator is DROPPED; a record that fails its schema is
replaced by a fixed token line. Pattern "redaction" of raw text is never
used as the boundary. Query fingerprints (SHA-256) are deliberately NOT
published: hashing is not a privacy boundary, and dictionary confirmation of
a known statement shape is possible from a digest.

Dynamic vocabularies (statuses, categories, verbs, table names, outcome
codes, ...) are EXACT fixed sets taken from the product source. Anything
outside the set is dropped — never rewritten, never "best effort".

JUnit identifiers are source-controlled: a failing test is printed as
``Class::method`` only when ``Class`` resolves through the repository's
PSR-4 mapping to a real file and ``method`` is declared in that file.
Otherwise ``(unknown)`` is printed. Exception categories are likewise a
fixed vocabulary of short names (PHP natives + PHPUnit + this repository's
declared exception/error classes) collected from the source tree; anything
else becomes ``other``.

Synthetic test fixtures only: nothing here may contain real patient data.

Usage:
  integration_evidence.py junit FILE [--label L] [--phpunit-exit N] [--log FILE]
  integration_evidence.py suites FILE
  integration_evidence.py evidence PREFIX FILE
  integration_evidence.py conn-diag FILE
  integration_evidence.py terminal FILE
  integration_evidence.py red1-probe FILE

Exit codes: 0 = projection printed (possibly "nothing available" tokens);
2 = usage error only. Diagnostics never change the CI verdict.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

MAX_IDENTIFIERS = 20
MAX_SUITES = 250
MAX_RECORDS = 10
MAX_CHAIN = 12
MAX_WALK_LEAVES = 40
MAX_SOURCE_BYTES = 8 * 1024 * 1024

CLASS_RE = re.compile(r'[A-Za-z0-9_\\]{1,200}\Z')
SUITE_NAME_RE = re.compile(r'[A-Za-z0-9_/\\.-]{1,200}\Z')
CLASSSHORT_RE = re.compile(r'[A-Za-z0-9_]{1,80}\Z')
LABEL_RE = re.compile(r'[a-z0-9_.-]{1,80}\Z')
# Dotted bounded integers ONLY (three 1-3 digit components). No letters, no
# suffix, no build metadata: a suffix can never be validated as more than a
# pattern, so it is omitted fail-closed (MariaDB build strings drop out).
MYSQL_VERSION_RE = re.compile(r'[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\Z')
FD_KEY_RE = re.compile(r'[0-9]{1,3}\Z')
INT_BOUND = 10 ** 15
# Signed/unsigned tokens; canonicality is enforced in _canonical_int (no
# leading zeros, no '-0'), length is checked before int() is ever called.
_SIGNED_INT_TOKEN_RE = re.compile(r'-?[0-9]{1,17}\Z')
_UNSIGNED_INT_TOKEN_RE = re.compile(r'[0-9]{1,16}\Z')
# A chain entry is a priority followed by a code identifier. Parentheses,
# dots, dashes, spaces, slashes and quotes are all impossible, so no
# payload can hitchhike in an identifier.
CHAIN_ENTRY_RE = re.compile(
    r'[0-9]{1,4}:'
    r'(closure#[0-9]{1,3}|'
    r'[A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*(::[A-Za-z_][A-Za-z0-9_]*)?)'
    r'\Z')

# ---------------------------------------------------------------------------
# Fixed vocabularies, taken from the product source tree.
# ---------------------------------------------------------------------------

KNOWN_TABLES = (
    'cpms_appointments', 'cpms_audit_logs', 'cpms_audit_seq',
    'cpms_clinic_memberships', 'cpms_clinical_note_versions',
    'cpms_clinical_notes', 'cpms_clinician_locations', 'cpms_clinicians',
    'cpms_clinics', 'cpms_custom_role_capabilities', 'cpms_custom_role_defs',
    'cpms_doctor_consultation_fees', 'cpms_drug_reference', 'cpms_follow_ups',
    'cpms_handwriting_documents', 'cpms_handwriting_page_versions',
    'cpms_handwriting_pages', 'cpms_idempotency_keys', 'cpms_invoice_items',
    'cpms_invoices', 'cpms_jobs', 'cpms_license_install', 'cpms_license_state',
    'cpms_locations', 'cpms_medical_attachments', 'cpms_membership_capabilities',
    'cpms_membership_locations', 'cpms_message_participants',
    'cpms_message_threads', 'cpms_messages', 'cpms_notifications',
    'cpms_ocr_jobs', 'cpms_operational_logs',
    'cpms_organization_membership_capabilities', 'cpms_organization_memberships',
    'cpms_organizations', 'cpms_otp_tokens', 'cpms_patient_identities',
    'cpms_patient_identity_links', 'cpms_patient_merges',
    'cpms_patient_user_links', 'cpms_patients',
    'cpms_payment_adjustments', 'cpms_payments', 'cpms_prescription_items',
    'cpms_prescriptions', 'cpms_rate_limits', 'cpms_recommendations',
    'cpms_schedule', 'cpms_schedule_exceptions', 'cpms_schedule_slots',
    'cpms_schema_migrations', 'cpms_services', 'cpms_settings',
    'cpms_slot_holds', 'cpms_sms_messages',
    'cpms_visit_charge_items', 'cpms_visit_consultation_fees',
    'cpms_visit_fee_snapshot_failures', 'cpms_visit_status_history',
    'cpms_visits',
)

SQL_VERBS = frozenset({
    'select', 'insert', 'update', 'delete', 'replace', 'create', 'alter',
    'drop', 'truncate', 'start', 'commit', 'rollback', 'savepoint', 'release',
    'set', 'show', 'use', 'other', 'none',
})

TRX_STATES = frozenset({
    'RUNNING', 'LOCK WAIT', 'ROLLING BACK', 'COMMITTING',
})

LOCK_STATUSES = frozenset({'WAITING', 'GRANTED'})

# InnoDB lock_mode strings published by information_schema on MySQL 8 /
# MariaDB 11.4 (uppercase, comma-separated). Anything else is dropped.
LOCK_MODES = frozenset({
    'S', 'X', 'IS', 'IX',
    'S,GAP', 'X,GAP',
    'S,REC_NOT_GAP', 'X,REC_NOT_GAP',
    'S,INSERT_INTENTION', 'X,INSERT_INTENTION',
    'X,REC_NOT_GAP,INSERT_INTENTION',
    'AUTO_INC',
})

DEFINITION_STATUSES = frozenset({'active', 'inactive', 'archived'})

BLOCKING_EVIDENCE_SOURCES = frozenset({'innodb_view', 'statement_timeout'})

# Result codes the two race-condition tests can report. Exactly the set of
# CUSTOM_ROLE_* reason codes returned by CustomRoleAssignmentException /
# CustomRoleManagementException (reason_code()) plus the 'assigned' success outcome.
DENIAL_CODES = frozenset({
    'CUSTOM_ROLE_ASSIGNMENT_INVALID_ROLE_KEY',
    'CUSTOM_ROLE_ASSIGNMENT_TARGET_MEMBERSHIP_REQUIRED',
    'CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_FOUND',
    'CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_ACTIVE',
    'CUSTOM_ROLE_ASSIGNMENT_CAPABILITY_NOT_DELEGABLE',
    'CUSTOM_ROLE_ASSIGNMENT_MEMBERSHIP_UPDATE_FAILED',
    'CUSTOM_ROLE_INVALID_ROLE_KEY',
    'CUSTOM_ROLE_INVALID_DISPLAY_NAME',
    'CUSTOM_ROLE_CAPABILITY_NOT_ALLOWED',
    'CUSTOM_ROLE_CAPABILITY_NOT_DELEGABLE',
    'CUSTOM_ROLE_NOT_FOUND',
    'CUSTOM_ROLE_KEY_CONFLICT',
    'CUSTOM_ROLE_STALE_VERSION',
    'CUSTOM_ROLE_TERMINAL_STATE',
    'CUSTOM_ROLE_INVALID_STATE',
    'CUSTOM_ROLE_MEMBERSHIP_REFERENCES',
})
DENIED_OUTCOMES = frozenset({'assigned'}) | frozenset('denied:' + code for code in DENIAL_CODES)

ARCHIVAL_CASES = frozenset({
    'archive_before_assign', 'assign_before_archive',
    'archive_before_rename', 'archive_before_capabilities',
})

WORKER_MECHANISMS = frozenset({'proc_open-independent-process'})
WORKER_BOOTSTRAPS = frozenset({'wp-config constants + wp-settings.php (no test installer)'})
FD_KINDS = frozenset({'dev-null', 'pipe', 'socket', 'other'})
INDEX_NAMES = frozenset({'PRIMARY'})

SQL_MODES = frozenset({
    # MySQL 8.0 and MariaDB 11.4 global/session sql_mode vocabularies.
    'REAL_AS_FLOAT', 'PIPES_AS_CONCAT', 'ANSI_QUOTES', 'IGNORE_SPACE',
    'ONLY_FULL_GROUP_BY', 'ANSI', 'NO_ZERO_IN_DATE', 'NO_ZERO_DATE',
    'ERROR_FOR_DIVISION_BY_ZERO', 'TRADITIONAL', 'NO_AUTO_CREATE_USER',
    'NO_ENGINE_SUBSTITUTION', 'ORACLE', 'STRICT_TRANS_TABLES',
    'STRICT_ALL_TABLES', 'NO_DIR_IN_CREATE', 'NO_FIELD_OPTIONS',
    'NO_KEY_OPTIONS', 'NO_TABLE_OPTIONS', 'PAD_CHAR_TO_FULL_LENGTH',
    'NO_UNSIGNED_SUBTRACTION', 'POSTGRESQL', 'DB2', 'MAXDB', 'MSSQL',
    'NO_BACKSLASH_ESCAPES', 'HIGH_NOT_PRECEDENCE', 'ALLOW_INVALID_DATES',
    'TIME_TRUNCATE_FRACTIONAL', 'SIMULTANEOUS_ASSIGNMENT',
})

PHP_TYPES = frozenset({'NULL', 'integer', 'string', 'boolean', 'double'})

JSON_VALUE_TYPES = frozenset({
    'NULL', 'BOOLEAN', 'INTEGER', 'DOUBLE', 'DECIMAL', 'STRING',
    'DATETIME', 'OBJECT', 'ARRAY',
})

# REST `code` values the RED-1 probe may publish. Everything else → unavailable.
#
# Taken from source (2026-11-09), exact members only:
#  * WP REST dispatch failures the probe can hit before routing:
#    rest_no_route (404 pre-routing — the RED-1 signature), rest_invalid_param
#    and rest_missing_callback_param (request-arg validation),
#    rest_invalid_handler / rest_forbidden / rest_not_logged_in /
#    rest_forbidden_context (server/permission failures).
#  * The product's own booking-envelope vocabulary reachable on the probe's two
#    anonymous endpoints (BookingException::of(...) across src/Application/Booking,
#    ScopeRequiredException::apiCode(), and BookingController::wrap()'s Throwable
#    fallback): every code a healthy handler can legitimately return. Success
#    envelopes (['data' => ...]) carry NO `code` key — `unavailable` then means
#    "no code in the envelope", never "validation failed".
REST_CODES = frozenset({
    'rest_no_route', 'rest_invalid_param', 'rest_missing_callback_param',
    'rest_invalid_handler', 'rest_forbidden', 'rest_not_logged_in',
    'rest_forbidden_context',
    'CLINIC_SCOPE_REQUIRED', 'CLINIC_SCOPE_UNAVAILABLE',
    'CLINIC_NOT_FOUND', 'CLINIC_PERMISSION_DENIED', 'CLINIC_UNAUTHORIZED',
    # Source-verified product booking-envelope codes (BookingException / scope):
    'CLINIC_VALIDATION_FAILED', 'CLINIC_INTERNAL_ERROR', 'CLINIC_HOLD_EXPIRED',
    'CLINIC_POLICY_VIOLATION', 'CLINIC_PATIENT_SELECTION_REQUIRED',
    'CLINIC_INVALID_TRANSITION', 'CLINIC_SLOT_AMBIGUOUS',
    'CLINIC_DUPLICATE_IN_FLIGHT', 'CLINIC_SLOT_TAKEN', 'CLINIC_SUSPENDED',
    'CLINIC_DUPLICATE_APPOINTMENT',
})

# PHP native + PHPUnit exception/error short names. The repository's own
# declared exception/error classes are added from the source tree at runtime.
PHP_AND_PHPUNIT_EXCEPTIONS = frozenset({
    'Exception', 'Error', 'TypeError', 'ValueError', 'ArithmeticError',
    'CompileError', 'ParseError', 'AssertionError', 'DivisionByZeroError',
    'LogicException', 'RuntimeException', 'InvalidArgumentException',
    'BadFunctionCallException', 'BadMethodCallException', 'DomainException',
    'LengthException', 'OutOfBoundsException', 'OutOfRangeException',
    'OverflowException', 'RangeException', 'UnderflowException',
    'UnexpectedValueException',
    'ExpectationFailedException', 'RiskyTestError', 'RiskyTestWarning',
    'IncompleteTestError', 'SkippedTestError', 'SkippedTestSuiteError',
    'Warning', 'TestFailedException',
})

RED1_STEPS = frozenset({'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8'})
RED1_VERDICTS = frozenset({'pass', 'red', 'invalid'})
RED1_INVALID_REASONS = frozenset({
    'precondition', 'plugin_file', 'autoload', 'test_library', 'core',
    'database', 'bootstrap', 'migration',
})

DROP = object()

_REPO_ROOT = Path(__file__).resolve().parents[1]


def _plugin_dir() -> Path:
    override = os.environ.get('INTEGRATION_EVIDENCE_PLUGIN_DIR')
    if override:
        return Path(override)
    return _REPO_ROOT / 'clinic-practice-management'


def _canonical_int(value, allow_negative=True, allow_str=True):
    """The one canonical bounded integer validator for projector fields.

    Accepts only:
      * a JSON/Python int (bool excluded) with magnitude <= INT_BOUND; or
      * a raw string (never trimmed) of ASCII digits, optionally led by '-',
        canonical in form: no leading zeros (except the value '0'), no
        leading '-0', no '+' sign, no spaces or unicode digits
        (allow_str=False rejects the string surface entirely — used for the
        source-stage chain counters, which are only ever JSON ints and must
        fail closed on anything else).
    Length is checked before int() is called, so a hostile multi-kilobyte
    digit string is rejected without ever reaching the int parser. Returns
    the int, or None (callers map None to their existing DROP/absent
    token). Python ints from JSON pass the same INT_BOUND bound as strings.
    """
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        if not allow_negative and value < 0:
            return None
        return value if -INT_BOUND <= value <= INT_BOUND else None
    if not allow_str:
        return None
    if not isinstance(value, str):
        return None
    if len(value) > 17:
        return None
    token_re = _SIGNED_INT_TOKEN_RE if allow_negative else _UNSIGNED_INT_TOKEN_RE
    if not token_re.fullmatch(value):
        return None
    negative = value.startswith('-')
    digits = value[1:] if negative else value
    if len(digits) > 1 and digits[0] == '0':
        return None  # leading zeros are not canonical
    if negative and digits == '0':
        return None  # '-0' is not canonical
    parsed = int(value)
    if abs(parsed) > INT_BOUND:
        return None
    return parsed


def _int(value):
    parsed = _canonical_int(value, allow_negative=True)
    return parsed if parsed is not None else DROP


def _int_nullable(value):
    return None if value is None else _int(value)


def _bool(value):
    return value if isinstance(value, bool) else DROP


def _exact(value, vocabulary, maxlen):
    """Exact membership in a fixed vocabulary — never a pattern."""
    if not isinstance(value, str) or len(value) > maxlen:
        return DROP
    return value if value in vocabulary else DROP


def _version(value):
    if not isinstance(value, str):
        return DROP
    candidate = value.strip()
    return candidate if MYSQL_VERSION_RE.fullmatch(candidate) else DROP


def _sql_mode(value):
    if not isinstance(value, str) or not value or len(value) > 200:
        return DROP
    members = value.split(',')
    if any(member not in SQL_MODES for member in members):
        return DROP
    return ','.join(members)


def _label_or_default(label):
    if isinstance(label, str) and LABEL_RE.fullmatch(label):
        return label
    return 'integration'


def _table(value):
    if not isinstance(value, str):
        return DROP
    token = value.strip()
    if not token or len(token) > 120:
        return DROP
    if token != token.lower():
        # Fixed vocabulary is lowercase; anything else is not a known table.
        return DROP
    for known in KNOWN_TABLES:
        if token == known or token.endswith('_' + known):
            return known
    return DROP


def _plain_table(value):
    token = _table(value)
    if token is not DROP:
        return token
    if isinstance(value, str):
        lowered = value.strip().lower()
        if lowered in ('none', 'other'):
            return lowered
    return DROP


def _index(value):
    return _exact(value, INDEX_NAMES, 32)


def _put(out, key, value):
    if value is not DROP:
        out[key] = value


def _attr_int(raw):
    if raw is None:
        return None
    return _canonical_int(raw, allow_negative=False)


def _read_bounded(path, cap=MAX_SOURCE_BYTES):
    try:
        if path.stat().st_size > cap:
            return None
        return path.read_text(encoding='utf-8', errors='ignore')
    except OSError:
        return None


_PSR4_PREFIXES = (
    ('ClinicCore\\Tests\\', 'tests'),
    ('ClinicCore\\', 'src'),
)

_FILE_TEXT_CACHE: dict[Path, str | None] = {}


def _verified_class_file(fqcn):
    """Resolve an FQCN to a real file inside the plugin via PSR-4."""
    cls = (fqcn or '').strip()
    if cls.startswith('\\'):
        cls = cls[1:]
    if not CLASS_RE.fullmatch(cls):
        return None
    base = _plugin_dir().resolve()
    for prefix, sub in _PSR4_PREFIXES:
        if not cls.startswith(prefix):
            continue
        rel = Path(cls[len(prefix):].replace('\\', '/') + '.php')
        path = (base / sub / rel)
        try:
            resolved = path.resolve()
            resolved.relative_to(base)
        except (ValueError, OSError):
            return None
        return resolved if resolved.is_file() else None
    return None


def _verified_method(class_file, method):
    text = _FILE_TEXT_CACHE.get(class_file)
    if class_file not in _FILE_TEXT_CACHE:
        text = _read_bounded(class_file)
        _FILE_TEXT_CACHE[class_file] = text
    if not text:
        return False
    return re.search(r'function\s+' + re.escape(method) + r'\s*\(', text) is not None


def class_method_ident(classname, name):
    """Source-controlled `Class::method` extraction.

    Returns the identifier only when ``classname`` resolves through the
    repository's PSR-4 mapping to a real file inside the plugin and
    ``name`` (with any provider suffix discarded) is declared there.
    Anything else returns None.
    """
    cls = (classname or '').strip()
    if cls.startswith('\\'):
        cls = cls[1:]
    if not CLASS_RE.fullmatch(cls):
        return None
    match = re.fullmatch(r'([A-Za-z0-9_]{1,128})(?: with data set .*)?',
                         (name or '').strip(), re.DOTALL)
    if not match:
        return None
    method = match.group(1)
    class_file = _verified_class_file(cls)
    if class_file is None or not _verified_method(class_file, method):
        return None
    ident = cls + '::' + method
    return ident


_CATEGORY_CACHE = None


def _exception_categories():
    global _CATEGORY_CACHE
    if _CATEGORY_CACHE is not None:
        return _CATEGORY_CACHE
    categories = set(PHP_AND_PHPUNIT_EXCEPTIONS)
    src = _plugin_dir() / 'src'
    for path in src.rglob('*.php'):
        text = _read_bounded(path)
        if not text:
            continue
        for match in re.finditer(
                r'\b(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*(?:Exception|Error))\b',
                text):
            categories.add(match.group(1))
    _CATEGORY_CACHE = frozenset(categories)
    return _CATEGORY_CACHE


def category_short(typestr):
    """Exception class short name; anything outside the known set → 'other'."""
    if not isinstance(typestr, str):
        return 'other'
    candidate = typestr.strip()
    if not re.fullmatch(r'[A-Za-z0-9_\\]{1,200}', candidate):
        return 'other'
    short = candidate.rsplit('\\', 1)[-1]
    if not CLASSSHORT_RE.fullmatch(short):
        return 'other'
    return short if short in _exception_categories() else 'other'


def _load_junit(path):
    try:
        root = ET.parse(path).getroot()
    except Exception:
        return None
    if root.tag not in ('testsuites', 'testsuite'):
        return None
    return root


def junit_totals(root):
    cases = root.findall('.//testcase')
    assertions = 0
    for case in cases:
        raw = case.get('assertions')
        parsed = _attr_int(raw)
        assertions += parsed if parsed is not None else 0
    return {
        'cases': len(cases),
        'assertions': assertions,
        'failures': sum(1 for c in cases if c.find('failure') is not None),
        'errors': sum(1 for c in cases if c.find('error') is not None),
        'skipped': sum(1 for c in cases if c.find('skipped') is not None),
    }


def failing_entries(root):
    entries = []
    for case in root.findall('.//testcase'):
        for tag in ('error', 'failure'):
            node = case.find(tag)
            if node is None:
                continue
            ident = class_method_ident(case.get('class'), case.get('name'))
            category = category_short(node.get('type') or tag)
            entries.append((ident or '(unknown)', category))
    return entries


_SUMMARY_RE = re.compile(r'^(OK \(|OK, but|FAILURES!|ERRORS!|WARNINGS!)')


def terminal_summary(path):
    try:
        with open(path, 'r', errors='ignore') as handle:
            for line in handle:
                if _SUMMARY_RE.search(line):
                    return 'yes'
    except OSError:
        return 'unavailable'
    return 'no'


def cmd_junit(args):
    label = _label_or_default(args.label)
    root = _load_junit(args.junit)
    if root is None:
        print('stage={} junit_unavailable=1'.format(label))
        return 0
    totals = junit_totals(root)
    parts = [
        'stage={}'.format(label),
        'tests={}'.format(totals['cases']),
        'assertions={}'.format(totals['assertions']),
        'failures={}'.format(totals['failures']),
        'errors={}'.format(totals['errors']),
        'skipped={}'.format(totals['skipped']),
    ]
    if args.phpunit_exit is not None:
        phpunit_exit = _canonical_int(args.phpunit_exit, allow_negative=False)
        parts.insert(1, 'phpunit_exit={}'.format(
            phpunit_exit if phpunit_exit is not None else 'unavailable'))
    if args.log:
        parts.append('terminal_summary_printed={}'.format(terminal_summary(args.log)))
    print(' '.join(parts))

    identifiers = []
    categories = {}
    for ident, category in failing_entries(root):
        if len(identifiers) < MAX_IDENTIFIERS and ident not in identifiers:
            identifiers.append(ident)
        categories[category] = categories.get(category, 0) + 1
    print('failing_tests_capped_{}={}'.format(
        MAX_IDENTIFIERS, ';'.join(identifiers) if identifiers else 'none'))
    if categories:
        print('error_categories={}'.format(
            ' '.join('{}={}'.format(k, categories[k]) for k, v in sorted(categories.items()))))
    else:
        print('error_categories=none')
    return 0


def _verified_suite_name(name):
    name = (name or '').strip()
    if name == 'default':
        return name
    if not SUITE_NAME_RE.fullmatch(name):
        return None
    # Path-named suite: '/', a .php suffix, or an absolute/drive-letter path.
    # Class-named suites carry single backslashes (namespace separators)
    # and are verified via PSR-4 below.
    looks_path = ('/' in name or name.endswith('.php') or
                  name.startswith('/') or
                  re.match(r'[A-Za-z]:[/\\]', name) is not None)
    if looks_path:
        candidate = Path(name)
        if not candidate.is_absolute():
            candidate = _plugin_dir() / candidate
        try:
            resolved = candidate.resolve()
            resolved.relative_to(_plugin_dir().resolve())
        except (ValueError, OSError):
            return None
        if resolved.suffix == '.php' and resolved.is_file():
            return name
        return None
    # Class-named suite: must be a declared class in the plugin source.
    return name if _verified_class_file(name) is not None else None


def cmd_suites(args):
    root = _load_junit(args.junit)
    if root is None:
        print('testsuites_unavailable=1')
        return 0
    printed = 0
    omitted = 0
    for suite in root.iter('testsuite'):
        name = _verified_suite_name(suite.get('name'))
        if name is None:
            continue
        if printed >= MAX_SUITES:
            omitted += 1
            continue
        tests = _attr_int(suite.get('tests'))
        assertions = _attr_int(suite.get('assertions'))
        print('testsuite name={} tests={} assertions={}'.format(
            name,
            tests if tests is not None else '?',
            assertions if assertions is not None else '?'))
        printed += 1
    if printed == 0:
        print('testsuites_unavailable=1')
    if omitted:
        print('testsuites_truncated={}'.format(omitted))
    return 0


def _edge(edge):
    if edge is None:
        return None
    if not isinstance(edge, dict):
        return DROP
    out = {}
    _put(out, 'requesting_connection_id', _int(edge.get('requesting_connection_id')))
    _put(out, 'blocking_connection_id', _int(edge.get('blocking_connection_id')))
    _put(out, 'requesting_lock_id', _int_nullable(edge.get('requesting_lock_id')))
    _put(out, 'blocking_lock_id', _int_nullable(edge.get('blocking_lock_id')))
    _put(out, 'requesting_lock_mode', _exact(edge.get('requesting_lock_mode'), LOCK_MODES, 48))
    _put(out, 'blocking_lock_mode', _exact(edge.get('blocking_lock_mode'), LOCK_MODES, 48))
    _put(out, 'requesting_lock_status', _exact(edge.get('requesting_lock_status'), LOCK_STATUSES, 10))
    _put(out, 'blocking_lock_status', _exact(edge.get('blocking_lock_status'), LOCK_STATUSES, 10))
    _put(out, 'table', _table(edge.get('table')))
    _put(out, 'index', _index(edge.get('index')))
    return out


def _proof(proof):
    if proof is None:
        return None
    if not isinstance(proof, dict):
        return DROP
    out = {}
    _put(out, 'errno', _int(proof.get('errno')))
    # statement_sha256 is deliberately NOT published (hashing is not a boundary).
    return out


def project_lifecycle(obj):
    out = {}
    _put(out, 'mysql_version', _version(obj.get('mysql_version')))
    parent = obj.get('parent')
    if isinstance(parent, dict):
        projected = {}
        _put(projected, 'connection_id', _int(parent.get('connection_id')))
        _put(projected, 'transaction_id', _int(parent.get('transaction_id')))
        _put(projected, 'definition_lock_id', _int_nullable(parent.get('definition_lock_id')))
        _put(projected, 'lock_after_deactivation_before_commit',
             _int_nullable(parent.get('lock_after_deactivation_before_commit')))
        _put(projected, 'transaction_state_while_child_waited',
             _exact(parent.get('transaction_state_while_child_waited'), TRX_STATES, 30))
        _put(projected, 'transaction_state_after_deactivation_before_commit',
             _exact(parent.get('transaction_state_after_deactivation_before_commit'), TRX_STATES, 30))
        out['parent'] = projected
    child = obj.get('child')
    if isinstance(child, dict):
        projected = {}
        _put(projected, 'connection_id', _int(child.get('connection_id')))
        _put(projected, 'transaction_id', _int_nullable(child.get('transaction_id')))
        # assignment_lock_query_sha256 deliberately dropped (hashing is not a boundary).
        _put(projected, 'requesting_lock_id', _int_nullable(child.get('requesting_lock_id')))
        _put(projected, 'transaction_state_while_waiting',
             _exact(child.get('transaction_state_while_waiting'), TRX_STATES, 30))
        _put(projected, 'transaction_state_after_deactivation_before_commit',
             _exact(child.get('transaction_state_after_deactivation_before_commit'), TRX_STATES, 30))
        _put(projected, 'statement_lock_timeout_errno',
             _int_nullable(child.get('statement_lock_timeout_errno')))
        out['child'] = projected
    source = _exact(obj.get('blocking_evidence_source'), BLOCKING_EVIDENCE_SOURCES, 32)
    if source is not DROP:
        out['blocking_evidence_source'] = source
    edge = _edge(obj.get('wait_edge'))
    if edge is not DROP:
        out['wait_edge'] = edge
    proof = _proof(obj.get('statement_block_proof'))
    if proof is not DROP:
        out['statement_block_proof'] = proof
    _put(out, 'same_wait_edge_after_deactivation_before_commit',
         _bool(obj.get('same_wait_edge_after_deactivation_before_commit')))
    _put(out, 'child_outcome_after_parent_commit',
         _exact(obj.get('child_outcome_after_parent_commit'), DENIED_OUTCOMES, 96))
    _put(out, 'definition_status_after_parent_commit',
         _exact(obj.get('definition_status_after_parent_commit'), DEFINITION_STATUSES, 32))
    _put(out, 'assignment_audit_count_after', _int(obj.get('assignment_audit_count_after')))
    _put(out, 'deactivation_audit_count_after', _int(obj.get('deactivation_audit_count_after')))
    return out


def project_archival(obj):
    out = {}
    _put(out, 'case', _exact(obj.get('case'), ARCHIVAL_CASES, 64))
    _put(out, 'worker_mechanism', _exact(obj.get('worker_mechanism'), WORKER_MECHANISMS, 64))
    _put(out, 'worker_bootstrap', _exact(obj.get('worker_bootstrap'), WORKER_BOOTSTRAPS, 80))
    probe = obj.get('startup_fixture_probe')
    if isinstance(probe, dict):
        projected = {}
        for key in ('wp_user_rows', 'membership_rows', 'definition_rows'):
            _put(projected, key, _int(probe.get(key)))
        out['startup_fixture_probe'] = projected
    _put(out, 'worker_pid', _int(obj.get('worker_pid')))
    _put(out, 'worker_exit_code', _int(obj.get('worker_exit_code')))
    _put(out, 'parent_connection_id', _int(obj.get('parent_connection_id')))
    _put(out, 'child_connection_id', _int(obj.get('child_connection_id')))
    _put(out, 'parent_connection_id_live_on_own_session',
         _bool(obj.get('parent_connection_id_live_on_own_session')))
    _put(out, 'parent_transaction_visible_on_own_session',
         _bool(obj.get('parent_transaction_visible_on_own_session')))
    _put(out, 'parent_transaction_state',
         _exact(obj.get('parent_transaction_state'), TRX_STATES, 30))
    _put(out, 'child_transaction_state',
         _exact(obj.get('child_transaction_state'), TRX_STATES, 30))
    # waiter_lock_query_sha256 deliberately dropped (hashing is not a boundary).
    edge = _edge(obj.get('wait_edge'))
    if edge is not DROP:
        out['wait_edge'] = edge
    _put(out, 'same_wait_edge_through_mutation_and_audit_before_commit',
         _bool(obj.get('same_wait_edge_through_mutation_and_audit_before_commit')))
    _put(out, 'child_outcome_after_parent_commit',
         _exact(obj.get('child_outcome_after_parent_commit'), DENIED_OUTCOMES, 96))
    _put(out, 'definition_status_after_commit',
         _exact(obj.get('definition_status_after_commit'), DEFINITION_STATUSES, 32))
    _put(out, 'definition_version_after_commit',
         _int(obj.get('definition_version_after_commit')))
    _put(out, 'archival_audit_count', _int(obj.get('archival_audit_count')))
    _put(out, 'assignment_audit_count', _int(obj.get('assignment_audit_count')))
    isolation = obj.get('descriptor_isolation')
    if isinstance(isolation, dict):
        projected = {}
        _put(projected, 'proc_fd', _bool(isolation.get('proc_fd')))
        _put(projected, 'pinned_fd_count', _int(isolation.get('pinned_fd_count')))
        pinned = isolation.get('pinned_fds')
        if isinstance(pinned, list):
            projected['pinned_fds'] = [v for v in (_int(item) for item in pinned[:32])
                                       if v is not DROP]
        _put(projected, 'pinned_isolation_proven',
             _bool(isolation.get('pinned_isolation_proven')))
        fds = isolation.get('worker_fds_beyond_stdio')
        if isinstance(fds, dict):
            fd_out = {}
            for raw_key, raw_value in list(fds.items())[:32]:
                if not FD_KEY_RE.fullmatch(str(raw_key)):
                    continue
                value = str(raw_value)
                if value in FD_KINDS:
                    fd_out[str(raw_key)] = value
            projected['worker_fds_beyond_stdio'] = fd_out
        _put(projected, 'worker_socket_count', _int_nullable(isolation.get('worker_socket_count')))
        _put(projected, 'parent_observed_fd_count', _int(isolation.get('parent_observed_fd_count')))
        out['descriptor_isolation'] = projected
    _put(out, 'environment_allowlist_verified', _bool(obj.get('environment_allowlist_verified')))
    _put(out, 'privacy_marker_absent', _bool(obj.get('privacy_marker_absent')))
    return out


def project_heartbeat(obj):
    out = {}
    _put(out, 'sql_mode', _sql_mode(obj.get('sql_mode')))
    for key in ('insert_writer', 'update_writer'):
        value = obj.get(key)
        if value is None:
            out[key] = None
            continue
        parsed = _canonical_int(value, allow_negative=True)
        if parsed is not None:
            out[key] = parsed
    for key in ('insert_php_type', 'update_php_type'):
        _put(out, key, _exact(obj.get(key), PHP_TYPES, 8))
    _put(out, 'json_type', _exact(obj.get('json_type'), JSON_VALUE_TYPES, 16))
    return out


def _verb(value):
    if not isinstance(value, str):
        return DROP
    candidate = value.strip().lower()
    return candidate if candidate in SQL_VERBS else DROP


_FUNCTION_NAME_CACHE: dict[str, bool] = {}


def _verified_function_name(name):
    """True when ``name`` is declared in the plugin source tree (src/ or tests/).

    Bare callback identifiers are only publishable when a declaration exists in
    this repository — a pattern-shaped name that appears nowhere in source is
    data, not a source-controlled identifier.
    """
    cached = _FUNCTION_NAME_CACHE.get(name)
    if cached is not None:
        return cached
    if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,127}', name):
        _FUNCTION_NAME_CACHE[name] = False
        return False
    pattern = re.compile(r'function\s+' + re.escape(name) + r'\s*\(')
    found = False
    root = _plugin_dir()
    for sub in ('src', 'tests'):
        base = root / sub
        if not base.is_dir():
            continue
        for path in base.rglob('*.php'):
            text = _read_bounded(path)
            if text and pattern.search(text):
                found = True
                break
        if found:
            break
    if not found:
        # Plugin entry points at the root (e.g. clinic-practice-management.php).
        for path in root.glob('*.php'):
            if not path.is_file():
                continue
            text = _read_bounded(path)
            if text and pattern.search(text):
                found = True
                break
    _FUNCTION_NAME_CACHE[name] = found
    return found


_CHAIN_CLASS_RE = re.compile(
    r'([A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*)::[A-Za-z_][A-Za-z0-9_]*\Z')
_CHAIN_BARE_RE = re.compile(r'[A-Za-z_][A-Za-z0-9_]{0,127}\Z')
_CHAIN_CLOSURE_RE = re.compile(r'closure#[0-9]{1,3}\Z')


def _chain(item):
    """Validate one chain entry; returns (verdict, token).

    verdict: 'ok' | 'shape' | 'unverified'.
    'shape'       — does not parse as priority:token (or exceeds bounds).
    'unverified'  — parses, but the identifier is not declared in the source
                    tree (PSR-4 file + declared method, or a declared bare
                    function) and is therefore not source-controlled data.
    'ok'          — publishable (bounded int priority + verified identifier,
                    a closure#bounded-int token, or the fixed 'external' token
                    for an identifier deliberately reported as unverified).
    """
    if not isinstance(item, str) or len(item) > 160:
        return ('shape', None)
    if not CHAIN_ENTRY_RE.fullmatch(item):
        return ('shape', None)
    _priority, _, shape = item.partition(':')
    if shape == 'external':
        return ('ok', item)
    if _CHAIN_CLOSURE_RE.fullmatch(shape):
        return ('ok', item)
    if '::' in shape:
        cls, _, method = shape.rpartition('::')
        if class_method_ident(cls, method) is not None:
            return ('ok', item)
        return ('unverified', None)
    if _CHAIN_BARE_RE.fullmatch(shape) and _verified_function_name(shape):
        return ('ok', item)
    return ('unverified', None)


def project_connection_diag(obj):
    if not isinstance(obj, dict):
        return DROP
    out = {}
    leaves = ('ready', 'dbh_is_mysqli', 'last_error_present', 'cached_first_scalar_is_literal_1')
    ints = ('thread_id', 'mysql_errno', 'rows_affected', 'num_rows', 'insert_id',
            'mysqli_insert_id', 'cached_result_rows', 'has_auto_increment',
            'next_auto_increment', 'row_exists_for_key', 'temp_shadow_rows',
            'row_exists', 'status_completed', 'status_in_consultation',
            'rows_for_scope_and_key', 'positive_key_rows', 'key_is_positive')
    for key in leaves:
        if key in obj:
            _put(out, key, _bool(obj.get(key)))
    for key in ints:
        if key in obj:
            _put(out, key, _int(obj.get(key)))
    if 'verb' in obj:
        _put(out, 'verb', _verb(obj.get('verb')))
    if 'table' in obj:
        _put(out, 'table', _plain_table(obj.get('table')))
    last_query = obj.get('last_query')
    if isinstance(last_query, dict):
        projected = {}
        _put(projected, 'verb', _verb(last_query.get('verb')))
        _put(projected, 'table', _plain_table(last_query.get('table')))
        out['last_query'] = projected
    if 'count' in obj:
        _put(out, 'count', _int(obj.get('count')))
    chain = obj.get('chain')
    if isinstance(chain, list):
        projected_chain = []
        dropped_shape = 0
        dropped_unverified = 0
        for item in chain[:MAX_CHAIN]:
            verdict, token = _chain(item)
            if verdict == 'ok':
                projected_chain.append(token)
            elif verdict == 'shape':
                dropped_shape += 1
            else:
                dropped_unverified += 1
        out['chain'] = projected_chain
        # Merge projector-side drops into the same fixed-token counters the
        # source stage passed through (each stage counts only its own drops).
        # JSON ints only, fail-closed otherwise (existing projector
        # contract: non-int source counters are ignored, never coerced).
        source_shape = _canonical_int(obj.get('chain_dropped_shape'),
                                      allow_negative=False, allow_str=False)
        source_unverified = _canonical_int(obj.get('chain_dropped_unverified'),
                                           allow_negative=False, allow_str=False)
        source_shape = 0 if source_shape is None else source_shape
        source_unverified = 0 if source_unverified is None else source_unverified
        total_shape = source_shape + dropped_shape
        total_unverified = source_unverified + dropped_unverified
        if total_shape:
            out['chain_dropped_shape'] = total_shape
        if total_unverified:
            out['chain_dropped_unverified'] = total_unverified
        if total_shape + total_unverified:
            out['chain_dropped'] = total_shape + total_unverified
    if len(out) > MAX_WALK_LEAVES:
        return DROP
    return out


def _rest_code(value):
    return _exact(value, REST_CODES, 48)


_BASENAME_CACHE = None


def _known_basenames():
    """All .php basenames present in the checked-out plugin tree.

    A published file basename must correspond to a real source file in this
    repository — a character pattern alone is never sufficient. Bounded and
    cached for the process.
    """
    global _BASENAME_CACHE
    if _BASENAME_CACHE is not None:
        return _BASENAME_CACHE
    names = set()
    root = _plugin_dir()
    try:
        for path in root.rglob('*.php'):
            if path.is_file():
                names.add(path.name)
            if len(names) >= 20000:
                break
    except OSError:
        pass
    _BASENAME_CACHE = frozenset(names)
    return _BASENAME_CACHE


def _basename(value):
    if not isinstance(value, str) or not value or len(value) > 80:
        return DROP
    if value != Path(value).name:
        return DROP
    if not re.fullmatch(r'[A-Za-z0-9_.-]{1,80}', value):
        return DROP
    return value if value in _known_basenames() else DROP


def _exception_class(value):
    if not isinstance(value, str):
        return DROP
    short = value.strip().rsplit('\\', 1)[-1]
    return short if short in _exception_categories() or short in PHP_AND_PHPUNIT_EXCEPTIONS else DROP


RED1_FATAL_RE = re.compile(
    r'(PHP\s+(Fatal|Parse) error|\bUncaught\b|There has been a critical error)', re.IGNORECASE)


def cmd_red1_probe(args):
    """Project the process-fresh RED-1 probe log to fixed-schema lines only."""
    steps = {}
    http = []
    outcomes = []
    fatal = None
    fatal_detected = False
    saw_any_valid = False
    try:
        with open(args.log, 'r', errors='ignore') as handle:
            lines = []
            for index, raw in enumerate(handle):
                if index >= 20000:
                    break
                lines.append(raw)
    except OSError:
        print('red1_projection_unavailable=1')
        return 0
    for raw in lines:
        line = raw.rstrip('\n')
        if line.startswith('RED1_PROBE v1 '):
            body = line[len('RED1_PROBE v1 '):]
            fields = {}
            ok = True
            for token in body.split(' '):
                if '=' not in token:
                    ok = False
                    break
                key, value = token.split('=', 1)
                if not re.fullmatch(r'[a-z_]{1,32}', key):
                    ok = False
                    break
                fields[key] = value
            if not ok:
                continue
            event = fields.get('event')
            if event == 'start' and len(fields) == 1:
                saw_any_valid = True
                continue
            if event == 'step':
                step = fields.get('step')
                result = fields.get('result')
                if step not in RED1_STEPS or result not in ('pass', 'fail'):
                    continue
                row = {'result': result}
                if result == 'fail':
                    klass = _exception_class(fields.get('class'))
                    row['class'] = klass if klass is not DROP else 'other'
                    file_field = _basename(fields.get('file'))
                    row['file'] = file_field if file_field is not DROP else 'unknown'
                    row['line'] = _attr_int(fields.get('line')) or 0
                steps[step] = row
                saw_any_valid = True
                continue
            if event == 'http':
                step = fields.get('step')
                status = _attr_int(fields.get('status'))
                code = _rest_code(fields.get('code'))
                if step not in RED1_STEPS or status is None or not (100 <= status <= 599):
                    continue
                http.append((step, status, code if code is not DROP else 'unavailable'))
                saw_any_valid = True
                continue
            if event == 'outcome':
                outcome = {}
                outcome['verdict'] = fields.get('verdict') if fields.get('verdict') in RED1_VERDICTS else None
                outcome['invalid_reason'] = fields.get('reason') if fields.get('reason') in RED1_INVALID_REASONS else None
                outcome['steps_total'] = _attr_int(fields.get('steps'))
                outcome['steps_passed'] = _attr_int(fields.get('passed'))
                outcome['steps_failed'] = _attr_int(fields.get('failed'))
                candidate = fields.get('first_failure')
                outcome['first_failure'] = candidate if candidate in RED1_STEPS else None
                if outcome['verdict'] is None:
                    continue
                if len(outcomes) < 8:
                    outcomes.append(outcome)
                saw_any_valid = True
                continue
            if event == 'fatal':
                klass = _exception_class(fields.get('class'))
                file_field = _basename(fields.get('file'))
                fatal = {
                    'class': klass if klass is not DROP else 'other',
                    'file': file_field if file_field is not DROP else 'unknown',
                    'line': _attr_int(fields.get('line')) or 0,
                }
                saw_any_valid = True
                continue
            continue
        if RED1_FATAL_RE.search(line):
            fatal_detected = True
    if not saw_any_valid:
        print('red1_projection_unavailable=1')
        if fatal_detected:
            print('red1_php_fatal=detected')
        return 0
    for step in sorted(RED1_STEPS):
        row = steps.get(step)
        if row is None:
            continue
        if row['result'] == 'pass':
            print('red1_step step={} result=pass'.format(step))
        else:
            print('red1_step step={} result=fail class={} file={} line={}'.format(
                step, row['class'], row['file'], row['line']))
    for step, status, code in http:
        print('red1_http step={} status={} code={}'.format(step, status, code))
    if outcomes:
        for outcome in outcomes:
            if outcome['verdict'] == 'invalid':
                print('red1_outcome verdict=invalid reason={}'.format(
                    outcome['invalid_reason'] or 'precondition'))
            else:
                print('red1_outcome verdict={} steps={} passed={} failed={}{}'.format(
                    outcome['verdict'],
                    outcome['steps_total'] if outcome['steps_total'] is not None else len(steps),
                    outcome['steps_passed'] if outcome['steps_passed'] is not None else sum(
                        1 for row in steps.values() if row['result'] == 'pass'),
                    outcome['steps_failed'] if outcome['steps_failed'] is not None else sum(
                        1 for row in steps.values() if row['result'] == 'fail'),
                    (' first_failure=' + outcome['first_failure']) if outcome['first_failure'] else ''))

    elif fatal is not None:
        print('red1_fatal_event class={} file={} line={}'.format(fatal['class'], fatal['file'], fatal['line']))
    elif fatal_detected:
        print('red1_php_fatal=detected')
    return 0


EVIDENCE_PREFIXES = {
    'CUSTOM_ROLE_LIFECYCLE_CONCURRENCY_EVIDENCE': (
        'CUSTOM_ROLE_LIFECYCLE_CONCURRENCY_EVIDENCE', project_lifecycle),
    'CUSTOM_ROLE_ARCHIVAL_CONCURRENCY_EVIDENCE': (
        'CUSTOM_ROLE_ARCHIVAL_CONCURRENCY_EVIDENCE', project_archival),
    'HEARTBEAT_PERSISTENCE': ('HEARTBEAT_PERSISTENCE', project_heartbeat),
    'CPMS_CONN_DIAG': ('CPMS_CONN_DIAG', project_connection_diag),
}


def _evidence_prefix(name):
    normalized = (name or '').strip().rstrip('=')
    entry = EVIDENCE_PREFIXES.get(normalized)
    if entry is None:
        return None
    return entry


def cmd_evidence(args):
    entry = _evidence_prefix(args.prefix)
    if entry is None:
        known = ', '.join(sorted(EVIDENCE_PREFIXES))
        print('unknown_evidence_prefix=1 known={}'.format(known))
        return 2
    name, projector = entry
    prefix = name + ('=' if name == 'HEARTBEAT_PERSISTENCE' else ' ')
    printed = 0
    try:
        with open(args.log, 'r', errors='ignore') as handle:
            lines = []
            for index, raw in enumerate(handle):
                if index >= 20000:
                    break
                lines.append(raw)
    except OSError:
        print(name + ' evidence_unavailable=1')
        return 0
    omitted = 0
    for raw in lines:
        line = raw.rstrip('\n')
        if printed >= MAX_RECORDS:
            # Every prefix-matching line would have produced exactly one
            # output line; count the ones dropped by the cap so the
            # truncation is visible (mirrors cmd_suites' testsuites_truncated).
            if line.startswith(prefix):
                omitted += 1
            continue
        if not line.startswith(prefix):
            continue
        try:
            obj = json.loads(line[len(prefix):])
        except Exception:
            print(name + ' record_dropped=invalid_json')
            printed += 1
            continue
        if not isinstance(obj, dict):
            print(name + ' record_dropped=schema_mismatch')
            printed += 1
            continue
        projected = projector(obj)
        if projected is DROP or not projected:
            print(name + ' record_dropped=schema_mismatch')
            printed += 1
            continue
        print(name + ' ' + json.dumps(projected, sort_keys=True,
                                     separators=(',', ':'), ensure_ascii=True))
        printed += 1
    if printed == 0:
        print(name + ' evidence_absent=1')
    if omitted:
        print(name + ' evidence_truncated={}'.format(omitted))
    return 0


def cmd_annotate(args):
    """Emit GitHub ::error:: annotations for failing tests.

    Idents are source-verified (PSR-4 + declared method) exactly like the
    junit projection; unverifiable idents are dropped, never printed.
    At most --limit lines, starting at --offset; always exit 0.
    """
    root = _load_junit(args.junit)
    if root is None:
        return 0
    offset = max(0, args.offset)
    limit = min(max(0, args.limit), 20)
    seen = []
    for ident, _category in failing_entries(root):
        if ident == '(unknown)':
            continue
        if ident not in seen:
            seen.append(ident)
    for ident in seen[offset:offset + limit]:
        print('::error::{}'.format(ident))
    return 0


def cmd_conn_diag(args):
    return cmd_evidence(args)


def cmd_terminal(args):
    print('terminal_summary_printed={}'.format(terminal_summary(args.log)))
    return 0


def build_parser():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)

    p = sub.add_parser('junit')
    p.add_argument('junit')
    p.add_argument('--label', default='integration')
    p.add_argument('--phpunit-exit', type=int, default=None)
    p.add_argument('--log', default=None)
    p.set_defaults(func=cmd_junit)

    p = sub.add_parser('suites')
    p.add_argument('junit')
    p.set_defaults(func=cmd_suites)

    p = sub.add_parser('evidence')
    p.add_argument('prefix')
    p.add_argument('log')
    p.set_defaults(func=cmd_evidence)

    p = sub.add_parser('conn-diag')
    p.add_argument('log')
    p.set_defaults(func=cmd_conn_diag)

    p = sub.add_parser('terminal')
    p.add_argument('log')
    p.set_defaults(func=cmd_terminal)

    p = sub.add_parser('red1-probe')
    p.add_argument('log')
    p.set_defaults(func=cmd_red1_probe)

    p = sub.add_parser('annotate')
    p.add_argument('junit')
    p.add_argument('--offset', type=int, default=0)
    p.add_argument('--limit', type=int, default=10)
    p.set_defaults(func=cmd_annotate)
    return parser


def main(argv=None):
    parser = build_parser()
    args = parser.parse_args(argv)
    if args.command == 'conn-diag':
        args.prefix = 'CPMS_CONN_DIAG '
    try:
        return args.func(args)
    except Exception:
        # Fail-closed: never crash a CI step, never leak internals.
        print('evidence_projection_error=1')
        return 0


if __name__ == '__main__':
    sys.exit(main())
