import io
import json
import os
import sys
import tempfile
import unittest
from contextlib import redirect_stdout

import integration_evidence as ie


CANARIES = [
    'patient@example.com',
    'دکتر علی احمدی',
    '09123456789',
    'DROP TABLE',
    'secret-marker',
    'lock_data-secret',
    '987654321',
    '\x00',
]


def assert_no_canaries(testcase, text):
    for canary in CANARIES:
        testcase.assertNotIn(canary, text)


def run_cli(*argv):
    buffer = io.StringIO()
    with redirect_stdout(buffer):
        code = ie.main(list(argv))
    return code, buffer.getvalue()


def write_tmp(content, suffix='.xml'):
    handle, path = tempfile.mkstemp(suffix=suffix)
    with os.fdopen(handle, 'w', encoding='utf-8') as stream:
        stream.write(content)
    return path


class ClassMethodIdentTest(unittest.TestCase):
    def test_plain(self):
        self.assertEqual('App\\FooTest::testBar', ie.class_method_ident('App\\FooTest', 'testBar'))

    def test_provider_suffix_is_discarded(self):
        self.assertEqual(
            'App\\FooTest::testBar',
            ie.class_method_ident('App\\FooTest', 'testBar with data set "patient@example.com"'))

    def test_unicode_provider_suffix_is_discarded(self):
        self.assertEqual(
            'App\\FooTest::testBar',
            ie.class_method_ident('App\\FooTest', 'testBar with data set "دکتر علی احمدی"'))

    def test_provider_suffix_after_hash(self):
        self.assertEqual(
            'App\\FooTest::testBar', ie.class_method_ident('App\\FooTest', 'testBar with data set #12345'))

    def test_unicode_method_is_dropped(self):
        self.assertIsNone(ie.class_method_ident('App\\FooTest', 'testدکتر'))

    def test_path_traversal_class_is_dropped(self):
        self.assertIsNone(ie.class_method_ident('../../etc/passwd', 'testX'))
        self.assertIsNone(ie.class_method_ident('App/Foo', 'testX'))

    def test_null_and_empty(self):
        self.assertIsNone(ie.class_method_ident(None, 'testX'))
        self.assertIsNone(ie.class_method_ident('App\\FooTest', None))
        self.assertIsNone(ie.class_method_ident('', ''))

    def test_oversized_ident_is_dropped(self):
        self.assertIsNone(ie.class_method_ident('A' * 400, 'testX'))


class CategoryShortTest(unittest.TestCase):
    def test_fqcn(self):
        self.assertEqual('ExpectationFailedException',
                         ie.category_short('PHPUnit\\Framework\\ExpectationFailedException'))

    def test_bare(self):
        self.assertEqual('RuntimeException', ie.category_short('RuntimeException'))

    def test_garbage(self):
        self.assertEqual('other', ie.category_short('Bad Type\\nInjected'))
        self.assertEqual('other', ie.category_short('a' * 200))
        self.assertEqual('other', ie.category_short(None))
        self.assertEqual('other', ie.category_short('..'))


JUNIT_BASIC = '''<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="App\\FooTest" tests="2" assertions="7">
    <testcase name="testDirect" class="App\\FooTest" assertions="3"/>
    <testcase name="testQuoted" class="App\\FooTest" assertions="4">
      <failure type="PHPUnit\\Framework\\ExpectationFailedException">Failed asserting that 'patient@example.com' matches 'DROP TABLE patients'</failure>
    </testcase>
  </testsuite>
</testsuites>'''

JUNIT_PROVIDER = '''<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="App\\BarTest" tests="1" assertions="1">
    <testcase name="testCase with data set &quot;دکتر علی احمدی 09123456789&quot;" class="App\\BarTest" assertions="1">
      <error type="RuntimeException">boom secret-marker</error>
    </testcase>
  </testsuite>
</testsuites>'''

JUNIT_INJECTION = '''<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="App\\BazTest" tests="1" assertions="1">
    <testcase name="testX with data set &quot;line1
line2 12345&quot;" class="..\\..\\secret" assertions="1">
      <failure type="Evil\\nType">multi
line secret-marker</failure>
    </testcase>
  </testsuite>
</testsuites>'''


class JunitCommandTest(unittest.TestCase):
    def test_counts_and_failing_ids(self):
        path = write_tmp(JUNIT_BASIC)
        try:
            code, out = run_cli('junit', path, '--label', 'full_integration_suite',
                                '--phpunit-exit', '1')
        finally:
            os.unlink(path)
        self.assertEqual(0, code)
        self.assertIn('stage=full_integration_suite', out)
        self.assertIn('phpunit_exit=1', out)
        self.assertIn('tests=2', out)
        self.assertIn('assertions=7', out)
        self.assertIn('failures=1', out)
        self.assertIn('errors=0', out)
        self.assertIn('App\\FooTest::testQuoted', out)
        self.assertIn('ExpectationFailedException=1', out)
        assert_no_canaries(self, out)

    def test_provider_suffix_values_never_appear(self):
        path = write_tmp(JUNIT_PROVIDER)
        try:
            code, out = run_cli('junit', path)
        finally:
            os.unlink(path)
        self.assertIn('App\\BarTest::testCase', out)
        assert_no_canaries(self, out)

    def test_malicious_class_and_multiline_failure_text_never_appear(self):
        path = write_tmp(JUNIT_INJECTION)
        try:
            code, out = run_cli('junit', path)
        finally:
            os.unlink(path)
        assert_no_canaries(self, out)
        for line in out.splitlines():
            self.assertNotIn('\n', line)

    def test_malformed_xml_fails_closed(self):
        path = write_tmp('<testsuites><testsuite>')
        try:
            code, out = run_cli('junit', path, '--label', 'broken')
        finally:
            os.unlink(path)
        self.assertEqual(0, code)
        self.assertIn('stage=broken junit_unavailable=1', out)

    def test_wrong_root_fails_closed(self):
        path = write_tmp('<html><body>secret-marker</body></html>')
        try:
            code, out = run_cli('junit', path)
        finally:
            os.unlink(path)
        self.assertIn('junit_unavailable=1', out)
        assert_no_canaries(self, out)

    def test_suites_projection(self):
        path = write_tmp(JUNIT_BASIC)
        try:
            code, out = run_cli('suites', path)
        finally:
            os.unlink(path)
        self.assertIn('testsuite name=App\\FooTest tests=2 assertions=7', out)
        assert_no_canaries(self, out)

    def test_missing_file_fails_closed(self):
        code, out = run_cli('junit', '/nonexistent/nope.xml')
        self.assertEqual(0, code)
        self.assertIn('junit_unavailable=1', out)


LIFECYCLE_GOOD = {
    'mysql_version': '8.0.46',
    'mysql_schema': 'wordpress_test secret-marker',
    'assignment_lock_query': "SELECT ... WHERE id = 987654321 AND clinic_id = 16 FOR UPDATE /* secret-marker */",
    'parent': {
        'connection_id': 111,
        'transaction_id': 222,
        'definition_lock_id': 333,
        'lock_after_deactivation_before_commit': 444,
        'transaction_state_while_child_waited': 'LOCK WAIT',
        'transaction_state_after_deactivation_before_commit': 'ACTIVE',
    },
    'child': {
        'connection_id': 555,
        'transaction_id': 666,
        'assignment_lock_query_sha256': 'a' * 64,
        'requesting_lock_id': 777,
        'transaction_state_while_waiting': 'LOCK WAIT',
        'transaction_state_after_deactivation_before_commit': 'ACTIVE',
        'statement_lock_timeout_errno': 1205,
    },
    'blocking_evidence_source': 'innodb_view',
    'wait_edge': {
        'requesting_connection_id': 555,
        'blocking_connection_id': 111,
        'requesting_lock_id': 777,
        'blocking_lock_id': 333,
        'requesting_lock_mode': 'X',
        'requesting_lock_status': 'WAITING',
        'blocking_lock_mode': 'X',
        'blocking_lock_status': 'GRANTED',
        'table': 'wptests_cpms_custom_role_defs',
        'index': 'PRIMARY',
        'lock_data': 'lock_data-secret 987654321',
    },
    'same_wait_edge_after_deactivation_before_commit': True,
    'child_outcome_after_parent_commit': 'denied:DEFINITION_NOT_ACTIVE',
    'definition_status_after_parent_commit': 'inactive',
    'membership_role_after': 'cpms_secretary',
    'wordpress_roles_before': ['doctor'],
    'wordpress_roles_after': ['administrator'],
    'assignment_audit_count_after': 0,
    'deactivation_audit_count_after': 1,
}


class LifecycleProjectionTest(unittest.TestCase):
    def test_good_record_keeps_bounded_fields(self):
        projected = ie.project_lifecycle(LIFECYCLE_GOOD)
        self.assertEqual('8.0.46', projected['mysql_version'])
        self.assertEqual('innodb_view', projected['blocking_evidence_source'])
        self.assertEqual(111, projected['parent']['connection_id'])
        self.assertEqual('a' * 64, projected['child']['assignment_lock_query_sha256'])
        self.assertEqual('cpms_custom_role_defs', projected['wait_edge']['table'])
        self.assertTrue(projected['same_wait_edge_after_deactivation_before_commit'])

    def test_raw_sql_and_lock_data_are_dropped(self):
        projected = ie.project_lifecycle(LIFECYCLE_GOOD)
        flat = json.dumps(projected)
        assert_no_canaries(self, flat)
        self.assertNotIn('"assignment_lock_query":', flat)
        self.assertNotIn('lock_data', flat)
        self.assertNotIn('mysql_schema', flat)
        self.assertNotIn('membership_role_after', flat)
        self.assertNotIn('wordpress_roles', flat)

    def test_wrong_types_are_dropped_not_coerced(self):
        bad = dict(LIFECYCLE_GOOD)
        bad['parent'] = {'connection_id': 'not-an-int secret-marker'}
        bad['child'] = {'connection_id': True}
        bad['blocking_evidence_source'] = 'definitely-not-an-enum'
        projected = ie.project_lifecycle(bad)
        self.assertNotIn('connection_id', projected.get('parent', {}))
        self.assertNotIn('connection_id', projected.get('child', {}))
        self.assertNotIn('blocking_evidence_source', projected)
        assert_no_canaries(self, json.dumps(projected))

    def test_denied_token_is_enforced(self):
        bad = dict(LIFECYCLE_GOOD)
        bad['child_outcome_after_parent_commit'] = 'denied:DRO P TABLE'
        self.assertNotIn('child_outcome_after_parent_commit', ie.project_lifecycle(bad))
        ok = dict(LIFECYCLE_GOOD)
        ok['child_outcome_after_parent_commit'] = 'denied:CODE_DEFINITION_NOT_ACTIVE'
        self.assertEqual('denied:CODE_DEFINITION_NOT_ACTIVE',
                         ie.project_lifecycle(ok)['child_outcome_after_parent_commit'])

    def test_trx_state_rejects_values_and_unicode(self):
        bad = dict(LIFECYCLE_GOOD)
        parent = dict(LIFECYCLE_GOOD['parent'])
        parent['transaction_state_while_child_waited'] = 'LOCK WAIT; DROP TABLE patients'
        bad['parent'] = parent
        projected = ie.project_lifecycle(bad)
        self.assertNotIn('transaction_state_while_child_waited', projected['parent'])

    def test_evidence_command_drops_bad_json_lines(self):
        log = 'CUSTOM_ROLE_LIFECYCLE_CONCURRENCY_EVIDENCE {not json secret-marker\n' \
              + 'CUSTOM_ROLE_LIFECYCLE_CONCURRENCY_EVIDENCE ' \
              + json.dumps(LIFECYCLE_GOOD) + '\n'
        path = write_tmp(log, '.log')
        try:
            code, out = run_cli('evidence', 'CUSTOM_ROLE_LIFECYCLE_CONCURRENCY_EVIDENCE', path)
        finally:
            os.unlink(path)
        self.assertEqual(0, code)
        self.assertIn('record_dropped=invalid_json', out)
        assert_no_canaries(self, out)
        self.assertIn('"mysql_version":"8.0.46"', out)

    def test_evidence_command_without_prefix_is_error(self):
        path = write_tmp('', '.log')
        try:
            code, out = run_cli('evidence', 'NOT_A_KNOWN_PREFIX', path)
        finally:
            os.unlink(path)
        self.assertEqual(2, code)


ARCHIVAL_GOOD = {
    'case': 'archive_before_assign',
    'mysql_schema': 'wordpress_test secret-marker',
    'definition_id': 987654321,
    'worker_mechanism': 'proc_open-independent-process',
    'worker_bootstrap': 'wp-config constants + wp-settings.php (no test installer)',
    'startup_fixture_probe': {'wp_user_rows': 1, 'membership_rows': 1, 'definition_rows': 1},
    'worker_pid': 4242,
    'worker_exit_code': 0,
    'parent_connection_id': 101,
    'child_connection_id': 102,
    'parent_connection_id_live_on_own_session': True,
    'parent_transaction_visible_on_own_session': True,
    'parent_transaction_state': 'LOCK WAIT',
    'child_transaction_state': 'LOCK WAIT',
    'waiter_lock_query': "SELECT 1 /* secret-marker 987654321 */",
    'waiter_lock_query_sha256': 'b' * 64,
    'wait_edge': {
        'requesting_connection_id': 102,
        'blocking_connection_id': 101,
        'requesting_lock_id': 5,
        'blocking_lock_id': 6,
        'requesting_lock_mode': 'X',
        'blocking_lock_mode': 'X',
        'requesting_lock_status': 'WAITING',
        'blocking_lock_status': 'GRANTED',
        'table': 'wptests_cpms_custom_role_defs',
        'index': 'PRIMARY',
        'lock_data': 'lock_data-secret',
    },
    'same_wait_edge_through_mutation_and_audit_before_commit': True,
    'child_outcome_after_parent_commit': 'denied:DEFINITION_NOT_ACTIVE',
    'definition_status_after_commit': 'archived',
    'definition_version_after_commit': 2,
    'membership_role_after_commit': 'cpms_secretary',
    'archival_audit_count': 1,
    'assignment_audit_count': 0,
    'descriptor_isolation': {
        'proc_fd': True,
        'pinned_fd_count': 3,
        'pinned_fds': [5, 6, 7],
        'pinned_isolation_proven': True,
        'worker_fds_beyond_stdio': {'5': 'dev-null', '6': 'pipe:[123456]', '7': 'evil value with spaces'},
        'worker_socket_count': 2,
        'worker_env_keys': ['MYSQL_ROOT_PASSWORD', 'secret-marker'],
        'launch_environment_keys': ['PATH'],
        'parent_observed_fd_count': 9,
    },
    'environment_allowlist_verified': True,
    'privacy_marker_absent': True,
}


class ArchivalProjectionTest(unittest.TestCase):
    def test_good_record(self):
        projected = ie.project_archival(ARCHIVAL_GOOD)
        self.assertEqual('archive_before_assign', projected['case'])
        self.assertEqual({'wp_user_rows': 1, 'membership_rows': 1, 'definition_rows': 1},
                         projected['startup_fixture_probe'])
        self.assertEqual([5, 6, 7], projected['descriptor_isolation']['pinned_fds'])
        self.assertEqual({'5': 'dev-null', '6': 'pipe:[123456]'},
                         projected['descriptor_isolation']['worker_fds_beyond_stdio'])

    def test_raw_sql_ids_and_env_names_are_dropped(self):
        projected = ie.project_archival(ARCHIVAL_GOOD)
        flat = json.dumps(projected)
        assert_no_canaries(self, flat)
        for banned in ('definition_id', 'mysql_schema', '"waiter_lock_query":', 'membership_role_after_commit',
                       'worker_env_keys', 'launch_environment_keys', 'MYSQL_ROOT_PASSWORD', 'lock_data'):
            self.assertNotIn(banned, flat)

    def test_evidence_command_end_to_end(self):
        log = ('noise\nCUSTOM_ROLE_ARCHIVAL_CONCURRENCY_EVIDENCE '
               + json.dumps(ARCHIVAL_GOOD) + '\n') * 3
        path = write_tmp(log, '.log')
        try:
            code, out = run_cli('evidence', 'CUSTOM_ROLE_ARCHIVAL_CONCURRENCY_EVIDENCE', path)
        finally:
            os.unlink(path)
        self.assertEqual(0, code)
        self.assertEqual(3, out.count('CUSTOM_ROLE_ARCHIVAL_CONCURRENCY_EVIDENCE '))
        assert_no_canaries(self, out)


class HeartbeatProjectionTest(unittest.TestCase):
    GOOD = {'server': '8.0.46-0ubuntu0.24.04.1', 'sql_mode': 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES',
            'insert_writer': None, 'insert_php_type': 'NULL',
            'update_writer': '0', 'update_php_type': 'string', 'json_type': 'INTEGER'}

    def test_good_record(self):
        projected = ie.project_heartbeat(self.GOOD)
        self.assertNotIn('server', projected, 'server version is environment data we do not publish')
        self.assertEqual('ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES', projected['sql_mode'])
        self.assertIsNone(projected['insert_writer'])
        self.assertEqual(0, projected['update_writer'])
        self.assertEqual('INTEGER', projected['json_type'])

    def test_free_text_is_dropped(self):
        bad = dict(self.GOOD)
        bad['sql_mode'] = 'STRICT_TRANS_TABLES, دکتر'
        bad['insert_php_type'] = 'NULL; DROP TABLE patients'
        bad['json_type'] = 'INTEGER; DROP'
        projected = ie.project_heartbeat(bad)
        self.assertNotIn('sql_mode', projected)
        self.assertNotIn('insert_php_type', projected)
        self.assertNotIn('json_type', projected)

    def test_evidence_command(self):
        path = write_tmp('HEARTBEAT_PERSISTENCE=' + json.dumps(self.GOOD) + '\n', '.log')
        try:
            code, out = run_cli('evidence', 'HEARTBEAT_PERSISTENCE', path)
        finally:
            os.unlink(path)
        self.assertIn('HEARTBEAT_PERSISTENCE {', out)
        assert_no_canaries(self, out)


class ConnectionDiagProjectionTest(unittest.TestCase):
    GOOD = {'ready': True, 'dbh_is_mysqli': True, 'thread_id': 4242,
            'last_query': {'verb': 'insert', 'table': 'cpms_custom_role_defs'},
            'last_error_present': True, 'mysql_errno': 1213, 'rows_affected': 0,
            'num_rows': 0, 'insert_id': 0, 'mysqli_insert_id': 0,
            'cached_result_rows': 1, 'cached_first_scalar_is_literal_1': True}

    def test_good_record(self):
        projected = ie.project_connection_diag(self.GOOD)
        self.assertEqual(4242, projected['thread_id'])
        self.assertEqual({'verb': 'insert', 'table': 'cpms_custom_role_defs'}, projected['last_query'])

    def test_unknown_keys_and_bad_scalars_are_dropped(self):
        payload = dict(self.GOOD)
        payload['last_query'] = {'verb': 'insert; DROP TABLE patients', 'table': 'x; secret-marker'}
        payload['patient_mrn'] = '12345'
        projected = ie.project_connection_diag(payload)
        self.assertEqual({}, projected['last_query'])
        self.assertNotIn('patient_mrn', projected)
        assert_no_canaries(self, json.dumps(projected))

    def test_chain_tokens_filtered_and_capped(self):
        payload = {'count': 3, 'chain': ['2147483647:ClinicCore\\Tests\\Support\\WpSqlFailureLedger::observe',
                                         '20:Closure#123',
                                         'not a token with spaces secret-marker'] + ['1:X'] * 30}
        projected = ie.project_connection_diag(payload)
        self.assertIn('20:Closure#123', projected['chain'])
        self.assertNotIn('not a token with spaces secret-marker', projected['chain'])
        self.assertLessEqual(len(projected['chain']), 12)

    def test_conn_diag_cli(self):
        log = 'CPMS_CONN_DIAG ' + json.dumps(self.GOOD) + '\nCPMS_CONN_DIAG not-json\n'
        path = write_tmp(log, '.log')
        try:
            code, out = run_cli('conn-diag', path)
        finally:
            os.unlink(path)
        self.assertEqual(0, code)
        self.assertIn('"thread_id":4242', out)
        self.assertIn('record_dropped=invalid_json', out)
        assert_no_canaries(self, out)


class OutputShapeTest(unittest.TestCase):
    def test_all_outputs_are_single_line_ascii(self):
        cases = [
            ('junit', JUNIT_INJECTION, '.xml'),
            ('junit', JUNIT_PROVIDER, '.xml'),
            ('junit', JUNIT_BASIC, '.xml'),
        ]
        for command, content, suffix in cases:
            path = write_tmp(content, suffix)
            try:
                code, out = run_cli(command, path)
            finally:
                os.unlink(path)
            self.assertTrue(out.isascii())
            for line in out.splitlines():
                self.assertLessEqual(len(line), 500)

    def test_evidence_output_json_parses(self):
        log = 'HEARTBEAT_PERSISTENCE=' + json.dumps(HeartbeatProjectionTest.GOOD) + '\n'
        path = write_tmp(log, '.log')
        try:
            code, out = run_cli('evidence', 'HEARTBEAT_PERSISTENCE', path)
        finally:
            os.unlink(path)
        payload = out.split(' ', 1)[1]
        self.assertEqual('ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES', json.loads(payload)['sql_mode'])


class TerminalSummaryTest(unittest.TestCase):
    def test_yes_no_unavailable(self):
        ok = write_tmp('noise\nOK (3 tests, 9 assertions)\n', '.log')
        bad = write_tmp('FAILURES!\n', '.log')
        empty = write_tmp('', '.log')
        try:
            self.assertIn('yes', run_cli('terminal', ok)[1])
            self.assertIn('yes', run_cli('terminal', bad)[1])
            self.assertIn('no', run_cli('terminal', empty)[1])
        finally:
            for p in (ok, bad, empty):
                os.unlink(p)


if __name__ == '__main__':
    unittest.main()
