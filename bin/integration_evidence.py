#!/usr/bin/env python3
"""Fail-closed projection of Integration CI diagnostics for PUBLIC mirrors.

This is the single boundary through which every Integration CI publication
path (PR comments, step summaries, sanitized artifacts) sees diagnostic
content. Everything printed is one of:

  * a fixed token from an allow-list,
  * a validated integer or boolean,
  * a strictly validated identifier (Class::method, suite name, hex digest,
    SQLSTATE),
  * a count derived from XML attributes or from our own evidence lines.

NEVER printed: raw driver text, exception messages, SQL text or fragments,
fixture values (ids, keys, names), engine/InnoDB dumps, performance_schema
``lock_data``, file paths, stack frames, or any free-text fallback. A field
that fails its validator is DROPPED; a record that fails its schema is
replaced by a fixed token line. Pattern "redaction" of raw text is never
used as the boundary.

Synthetic test fixtures only: nothing here may contain real patient data.

Usage:
  integration_evidence.py junit FILE [--label L] [--phpunit-exit N] [--log FILE]
  integration_evidence.py suites FILE
  integration_evidence.py evidence PREFIX FILE
  integration_evidence.py conn-diag FILE
  integration_evidence.py terminal FILE

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

MAX_IDENTIFIERS = 20
MAX_SUITES = 250
MAX_RECORDS = 10
MAX_CHAIN = 12
MAX_WALK_LEAVES = 40

CLASS_RE = re.compile(r'[A-Za-z0-9_\\]{1,200}\Z')
SUITE_NAME_RE = re.compile(r'[A-Za-z0-9_\\.-]{1,200}\Z')
CLASSSHORT_RE = re.compile(r'[A-Za-z0-9_]{1,80}\Z')
TOKEN_RE = re.compile(r'[A-Za-z0-9_.:#/()-]{1,128}\Z')
CHAIN_TOKEN_RE = re.compile(r'[A-Za-z0-9_\\.:#()-]{1,140}\Z')
HEX64_RE = re.compile(r'[a-f0-9]{64}\Z')
SQLSTATE_RE = re.compile(r'[0-9A-Z]{5}\Z')
TRX_STATE_RE = re.compile(r'[A-Z ]{1,30}\Z')
DENIED_RE = re.compile(r'denied:[A-Za-z0-9_]{1,64}\Z')
ENUM_WORD_RE = re.compile(r'[a-z_]{1,32}\Z')
LOWER_CONSTANT_RE = re.compile(r'[a-z0-9 .+()/_-]{1,120}\Z')
VERSION_RE = re.compile(r'[0-9]+\.[0-9]+\.[0-9]+[0-9A-Za-z.-]{0,32}\Z')
SQL_MODE_RE = re.compile(r'([A-Z][A-Z0-9_]{1,40}(,[A-Z][A-Z0-9_]{1,40})*)?\Z')
UINT_RE = re.compile(r'[0-9]{1,20}\Z')
UPPER_SHORT_RE = re.compile(r'[A-Z]{1,4}\Z')
LOCK_MODE_RE = re.compile(r'[A-Z]{1,4}(,[A-Z0-9_]{1,24}){0,3}\Z')
STATUS_RE = re.compile(r'[A-Z]{1,10}\Z')
TABLE_TOKEN_RE = re.compile(r'[a-z0-9_]{1,80}\Z')
VERB_RE = re.compile(r'(none|other|[a-z]{1,12})\Z')
KEY_RE = re.compile(r'[A-Za-z0-9_]{1,64}\Z')
TYPE_PHP_RE = re.compile(r'(NULL|integer|string|boolean|double)\Z')
TYPE_VALUE_RE = re.compile(r'[A-Z]{1,16}\Z')
FD_VALUE_RE = re.compile(r'(dev-null|pipe:\[[0-9]{1,20}\]|socket:\[[0-9]{1,20}\]|other)\Z')

LABEL_RE = re.compile(r'[a-z0-9_.-]{1,80}\Z')

KNOWN_TABLES = ('cpms_custom_role_defs', 'cpms_custom_role_capabilities', 'cpms_clinics')
CATEGORIES = ('duplicate_entry', 'foreign_key', 'missing_table', 'lock', 'check_constraint', 'other')

DROP = object()


def _int(value):
    if isinstance(value, bool):
        return DROP
    if isinstance(value, int) and -10**15 <= value <= 10**15:
        return value
    if isinstance(value, str) and re.fullmatch(r'-?[0-9]{1,15}', value):
        return int(value)
    return DROP


def _int_nullable(value):
    return None if value is None else _int(value)


def _bool(value):
    return value if isinstance(value, bool) else DROP


def _token(value, pattern, maxlen=128):
    if not isinstance(value, str) or len(value) > maxlen or not value.isascii():
        return DROP
    return value if re.fullmatch(pattern, value) else DROP


def _hex64(value):
    return _token(value, HEX64_RE, 64)


def _trx_state(value):
    if value is None:
        return None
    return _token(value, TRX_STATE_RE, 30)


def _version(value):
    return _token(value, VERSION_RE, 64)


def _sql_mode(value):
    return _token(value, SQL_MODE_RE, 200)


def _label_or_default(label):
    if isinstance(label, str) and LABEL_RE.fullmatch(label):
        return label
    return 'integration'


def _table(value):
    token = _token(value, TABLE_TOKEN_RE, 80)
    if token is DROP:
        return DROP
    short = token.lower()
    for known in KNOWN_TABLES:
        if short == known or short.endswith('_' + known):
            return known
    return DROP


def _put(out, key, value):
    if value is not DROP:
        out[key] = value


def _attr_int(raw):
    if raw is None:
        return None
    if re.fullmatch(r'[0-9]{1,12}', str(raw)):
        return int(raw)
    return None


def class_method_ident(classname, name):
    """Strict `Class::method` extraction; provider suffixes are discarded.

    Accepts PHPUnit JUnit names: plain ``testFoo`` or
    ``testFoo with data set #N`` / ``testFoo with data set "..."`` (the
    suffix may contain arbitrary bytes, which are discarded wholesale).
    Anything else is dropped entirely.
    """
    cls = (classname or '').strip()
    if not CLASS_RE.fullmatch(cls):
        return None
    match = re.fullmatch(r'([A-Za-z0-9_]{1,128})(?: with data set .*)?',
                         (name or '').strip(), re.DOTALL)
    if not match:
        return None
    ident = cls + '::' + match.group(1)
    if len(ident) > 330:
        return None
    return ident


def category_short(typestr):
    """Exception class short name, validated; anything else → 'other'."""
    if not isinstance(typestr, str):
        return 'other'
    candidate = typestr.strip()
    if not re.fullmatch(r'[A-Za-z0-9_\\]{1,200}', candidate):
        return 'other'
    short = candidate.rsplit('\\', 1)[-1]
    return short if CLASSSHORT_RE.fullmatch(short) else 'other'


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
        parts.insert(1, 'phpunit_exit={}'.format(int(args.phpunit_exit)))
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


def cmd_suites(args):
    root = _load_junit(args.junit)
    if root is None:
        print('testsuites_unavailable=1')
        return 0
    printed = 0
    for suite in root.iter('testsuite'):
        if printed >= MAX_SUITES:
            break
        name = (suite.get('name') or '').strip()
        if not SUITE_NAME_RE.fullmatch(name):
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
    _put(out, 'requesting_lock_mode', _token(edge.get('requesting_lock_mode'), LOCK_MODE_RE, 110))
    _put(out, 'blocking_lock_mode', _token(edge.get('blocking_lock_mode'), LOCK_MODE_RE, 110))
    _put(out, 'requesting_lock_status', _token(edge.get('requesting_lock_status'), STATUS_RE, 10))
    _put(out, 'blocking_lock_status', _token(edge.get('blocking_lock_status'), STATUS_RE, 10))
    _put(out, 'table', _table(edge.get('table')))
    _put(out, 'index', _token(edge.get('index'), CLASSSHORT_RE, 32))
    return out


def _proof(proof):
    if proof is None:
        return None
    if not isinstance(proof, dict):
        return DROP
    out = {}
    _put(out, 'errno', _int(proof.get('errno')))
    _put(out, 'statement_sha256', _hex64(proof.get('statement_sha256')))
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
             _trx_state(parent.get('transaction_state_while_child_waited')))
        _put(projected, 'transaction_state_after_deactivation_before_commit',
             _trx_state(parent.get('transaction_state_after_deactivation_before_commit')))
        out['parent'] = projected
    child = obj.get('child')
    if isinstance(child, dict):
        projected = {}
        _put(projected, 'connection_id', _int(child.get('connection_id')))
        _put(projected, 'transaction_id', _int_nullable(child.get('transaction_id')))
        _put(projected, 'assignment_lock_query_sha256',
             _hex64(child.get('assignment_lock_query_sha256')))
        _put(projected, 'requesting_lock_id', _int_nullable(child.get('requesting_lock_id')))
        _put(projected, 'transaction_state_while_waiting',
             _trx_state(child.get('transaction_state_while_waiting')))
        _put(projected, 'transaction_state_after_deactivation_before_commit',
             _trx_state(child.get('transaction_state_after_deactivation_before_commit')))
        _put(projected, 'statement_lock_timeout_errno',
             _int_nullable(child.get('statement_lock_timeout_errno')))
        out['child'] = projected
    source = obj.get('blocking_evidence_source')
    if source in ('innodb_view', 'statement_timeout'):
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
         _token(obj.get('child_outcome_after_parent_commit'), DENIED_RE, 71))
    _put(out, 'definition_status_after_parent_commit',
         _token(obj.get('definition_status_after_parent_commit'), ENUM_WORD_RE, 32))
    _put(out, 'assignment_audit_count_after', _int(obj.get('assignment_audit_count_after')))
    _put(out, 'deactivation_audit_count_after', _int(obj.get('deactivation_audit_count_after')))
    return out


def project_archival(obj):
    out = {}
    _put(out, 'case', _token(obj.get('case'), re.compile(r'[a-z_]{1,64}\Z'), 64))
    _put(out, 'worker_mechanism', _token(obj.get('worker_mechanism'), LOWER_CONSTANT_RE, 120))
    _put(out, 'worker_bootstrap', _token(obj.get('worker_bootstrap'), LOWER_CONSTANT_RE, 120))
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
    _put(out, 'parent_transaction_state', _trx_state(obj.get('parent_transaction_state')))
    _put(out, 'child_transaction_state', _trx_state(obj.get('child_transaction_state')))
    _put(out, 'waiter_lock_query_sha256', _hex64(obj.get('waiter_lock_query_sha256')))
    edge = _edge(obj.get('wait_edge'))
    if edge is not DROP:
        out['wait_edge'] = edge
    _put(out, 'same_wait_edge_through_mutation_and_audit_before_commit',
         _bool(obj.get('same_wait_edge_through_mutation_and_audit_before_commit')))
    _put(out, 'child_outcome_after_parent_commit',
         _token(obj.get('child_outcome_after_parent_commit'), DENIED_RE, 71))
    _put(out, 'definition_status_after_commit',
         _token(obj.get('definition_status_after_commit'), ENUM_WORD_RE, 32))
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
        _put(projected, 'pinned_isolation_proven', _bool(isolation.get('pinned_isolation_proven')))
        fds = isolation.get('worker_fds_beyond_stdio')
        if isinstance(fds, dict):
            fd_out = {}
            for raw_key, raw_value in list(fds.items())[:32]:
                if not KEY_RE.fullmatch(str(raw_key)):
                    continue
                fd_value = _token(raw_value, FD_VALUE_RE, 32)
                if fd_value is DROP:
                    continue
                fd_out[str(raw_key)] = fd_value
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
        elif isinstance(value, int) and not isinstance(value, bool):
            out[key] = value
        elif isinstance(value, str) and UINT_RE.fullmatch(value):
            out[key] = int(value)
    for key in ('insert_php_type', 'update_php_type'):
        _put(out, key, _token(obj.get(key), TYPE_PHP_RE, 8))
    _put(out, 'json_type', _token(obj.get('json_type'), TYPE_VALUE_RE, 16))
    return out


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
        _put(out, 'verb', _token(obj.get('verb'), VERB_RE, 12))
    if 'table' in obj:
        _put(out, 'table', _token(obj.get('table'), TABLE_TOKEN_RE, 80))
    last_query = obj.get('last_query')
    if isinstance(last_query, dict):
        projected = {}
        _put(projected, 'verb', _token(last_query.get('verb'), VERB_RE, 12))
        _put(projected, 'table', _token(last_query.get('table'), TABLE_TOKEN_RE, 80))
        out['last_query'] = projected
    if 'count' in obj:
        _put(out, 'count', _int(obj.get('count')))
    chain = obj.get('chain')
    if isinstance(chain, list):
        projected_chain = []
        for item in chain[:MAX_CHAIN]:
            token = _token(item, CHAIN_TOKEN_RE, 140)
            if token is DROP:
                continue
            projected_chain.append(token)
        out['chain'] = projected_chain
    if len(out) > MAX_WALK_LEAVES:
        return DROP
    return out


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
            lines = handle.readlines()
    except OSError:
        print(name + ' evidence_unavailable=1')
        return 0
    for raw in lines:
        if printed >= MAX_RECORDS:
            break
        line = raw.rstrip('\n')
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
