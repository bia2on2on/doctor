import io
import json
import os
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

# --- Fixture plugin tree ---------------------------------------------------
# JUnit identifiers are source-controlled: the projector prints Class::method
# only when the class resolves PSR-4 to a real file and the method is declared
# in it. Build a hermetic fake plugin so the tests exercise that verifier.
_FIXTURE = Path(tempfile.mkdtemp(prefix='cpms-fixture-plugin-'))
(_FIXTURE / 'tests' / 'Integration').mkdir(parents=True)
(_FIXTURE / 'src' / 'Application' / 'Authorization').mkdir(parents=True)
(_FIXTURE / 'tests' / 'Integration' / 'FooTest.php').write_text(
    '<?php\nnamespace ClinicCore\\Tests\\Integration;\n'
    'final class FooTest {\n'
    '    public function testDirect(): void {}\n'
    '    public function testQuoted(): void {}\n'
    '}\n', encoding='utf-8')
(_FIXTURE / 'tests' / 'Integration' / 'BarTest.php').write_text(
    '<?php\nnamespace ClinicCore\\Tests\\Integration;\n'
    'final class BarTest {\n'
    '    public function testCase(): void {}\n'
    '}\n', encoding='utf-8')
(_FIXTURE / 'tests' / 'Integration' / 'BazTest.php').write_text(
    '<?php\nnamespace ClinicCore\\Tests\\Integration;\n'
    'final class BazTest {\n'
    '    public function testX(): void {}\n'
    '}\n', encoding='utf-8')
_cap_methods = '\n'.join(
    '    public function testCap%02d(): void {}\n' % i for i in range(1, 13))
(_FIXTURE / 'tests' / 'Integration' / 'CapTest.php').write_text(
    '<?php\nnamespace ClinicCore\\Tests\\Integration;\n'
    'final class CapTest {\n' + _cap_methods + '}\n', encoding='utf-8')
(_FIXTURE / 'src' / 'Application' / 'Authorization' / 'FakeGuardException.php').write_text(
    '<?php\nnamespace ClinicCore\\Application\\Authorization;\n'
    'class FakeGuardException extends \\RuntimeException {}\n', encoding='utf-8')
os.environ['INTEGRATION_EVIDENCE_PLUGIN_DIR'] = str(_FIXTURE)

import integration_evidence as ie  # noqa: E402


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
    FOO = 'ClinicCore\\Tests\\Integration\\FooTest'

    def test_plain(self):
        self.assertEqual(self.FOO + '::testDirect', ie.class_method_ident(self.FOO, 'testDirect'))

    def test_leading_backslash_accepted(self):
        self.assertEqual(self.FOO + '::testDirect', ie.class_method_ident('\\' + self.FOO, 'testDirect'))

    def test_provider_suffix_is_discarded(self):
        self.assertEqual(
            self.FOO + '::testQuoted',
            ie.class_method_ident(self.FOO, 'testQuoted with data set "patient@example.com"'))

    def test_unicode_provider_suffix_is_discarded(self):
        self.assertEqual(
            self.FOO + '::testQuoted',
            ie.class_method_ident(self.FOO, 'testQuoted with data set "دکتر علی احمدی"'))

    def test_provider_suffix_after_hash(self):
        self.assertEqual(
            self.FOO + '::testDirect', ie.class_method_ident(self.FOO, 'testDirect with data set #12345'))

    def test_method_missing_from_source_is_dropped(self):
        self.assertIsNone(ie.class_method_ident(self.FOO, 'testNotDeclaredAnywhere'))

    def test_class_missing_from_source_is_dropped(self):
        self.assertIsNone(ie.class_method_ident('ClinicCore\\Tests\\Integration\\DoesNotExistTest', 'testDirect'))

    def test_unknown_namespace_is_dropped(self):
        self.assertIsNone(ie.class_method_ident('NotThePlugin\\FooTest', 'testDirect'))

    def test_unicode_method_is_dropped(self):
        self.assertIsNone(ie.class_method_ident(self.FOO, 'testدکتر'))

    def test_path_traversal_class_is_dropped(self):
        self.assertIsNone(ie.class_method_ident('../../etc/passwd', 'testX'))
        self.assertIsNone(ie.class_method_ident('ClinicCore/Tests/Integration/FooTest', 'testDirect'))

    def test_null_and_empty(self):
        self.assertIsNone(ie.class_method_ident(None, 'testX'))
        self.assertIsNone(ie.class_method_ident(self.FOO, None))
        self.assertIsNone(ie.class_method_ident('', ''))

    def test_oversized_ident_is_dropped(self):
        self.assertIsNone(ie.class_method_ident('A' * 400, 'testX'))


class CategoryShortTest(unittest.TestCase):
    def test_fqcn_native(self):
        self.assertEqual('ExpectationFailedException',
                         ie.category_short('PHPUnit\\Framework\\ExpectationFailedException'))

    def test_bare_native(self):
        self.assertEqual('RuntimeException', ie.category_short('RuntimeException'))

    def test_declared_project_exception_is_accepted(self):
        self.assertEqual('FakeGuardException',
                         ie.category_short('ClinicCore\\Application\\Authorization\\FakeGuardException'))
        self.assertEqual('FakeGuardException', ie.category_short('FakeGuardException'))

    def test_undeclared_exception_is_other(self):
        self.assertEqual('other', ie.category_short('CompletelyMadeUpException'))
        self.assertEqual('other', ie.category_short('ClinicCore\\Nope\\NotDeclaredError'))

    def test_garbage(self):
        self.assertEqual('other', ie.category_short('Bad Type\\nInjected'))
        self.assertEqual('other', ie.category_short('a' * 200))
        self.assertEqual('other', ie.category_short(None))
        self.assertEqual('other', ie.category_short('..'))


FOO = 'ClinicCore\\Tests\\Integration\\FooTest'
BAR = 'ClinicCore\\Tests\\Integration\\BarTest'
BAZ = 'ClinicCore\\Tests\\Integration\\BazTest'

JUNIT_BASIC = '''<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="%s" tests="2" assertions="7">
    <testcase name="testDirect" class="%s" assertions="3"/>
    <testcase name="testQuoted" class="%s" assertions="4">
      <failure type="PHPUnit\\Framework\\ExpectationFailedException">Failed asserting that 'patient@example.com' matches 'DROP TABLE patients'</failure>
    </testcase>
  </testsuite>
</testsuites>''' % (FOO, FOO, FOO)

JUNIT_PROVIDER = '''<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="%s" tests="1" assertions="1">
    <testcase name="testCase with data set &quot;دکتر علی احمدی 09123456789&quot;" class="%s" assertions="1">
      <error type="RuntimeException">boom secret-marker</error>
    </testcase>
  </testsuite>
</testsuites>''' % (BAR, BAR)

JUNIT_INJECTION = '''<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="%s" tests="1" assertions="1">
    <testcase name="testX with data set &quot;line1
line2 12345&quot;" class="..\\..\\secret" assertions="1">
      <failure type="Evil\\nType">multi
line secret-marker</failure>
    </testcase>
  </testsuite>
</testsuites>''' % BAZ

CAP = 'ClinicCore\\Tests\\Integration\\CapTest'
JUNIT_CAP = '''<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="%s" tests="12" assertions="12">
%s  </testsuite>
</testsuites>''' % (CAP, ''.join(
    '    <testcase name="testCap%02d" class="%s" assertions="1">'
    '<failure type="RuntimeException">boom</failure>'
    '</testcase>\n' % (i, CAP) for i in range(1, 13)))


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
        self.assertIn(FOO + '::testQuoted', out)
        self.assertIn('ExpectationFailedException=1', out)
        assert_no_canaries(self, out)

    def test_provider_suffix_values_never_appear(self):
        path = write_tmp(JUNIT_PROVIDER)
        try:
            code, out = run_cli('junit', path)
        finally:
            os.unlink(path)
        self.assertIn(BAR + '::testCase', out)
        assert_no_canaries(self, out)

    def test_malicious_class_and_multiline_failure_text_never_appear(self):
        path = write_tmp(JUNIT_INJECTION)
        try:
            code, out = run_cli('junit', path)
        finally:
            os.unlink(path)
        assert_no_canaries(self, out)
        self.assertIn('(unknown)', out)
        for line in out.splitlines():
            self.assertNotIn('\n', line)

    def test_undeclared_exception_category_is_other(self):
        junit = JUNIT_PROVIDER.replace('RuntimeException', 'VendorMagic\\TotallyUnknownError')
        path = write_tmp(junit)
        try:
            code, out = run_cli('junit', path)
        finally:
            os.unlink(path)
        self.assertIn('error_categories=other=1', out)
        assert_no_canaries(self, out)

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

    def test_suites_projection_verified_class(self):
        path = write_tmp(JUNIT_BASIC)
        try:
            code, out = run_cli('suites', path)
        finally:
            os.unlink(path)
        self.assertIn('testsuite name=%s tests=2 assertions=7' % FOO, out)
        assert_no_canaries(self, out)

    def test_suites_projection_default_literal(self):
        junit = JUNIT_BASIC.replace('name="%s"' % FOO, 'name="default"')
        path = write_tmp(junit)
        try:
            code, out = run_cli('suites', path)
        finally:
            os.unlink(path)
        self.assertIn('testsuite name=default', out)

    def test_suites_projection_drops_unverifiable_names(self):
        junit = JUNIT_BASIC.replace('name="%s"' % FOO, 'name="/etc/passwd"')
        path = write_tmp(junit)
        try:
            code, out = run_cli('suites', path)
        finally:
            os.unlink(path)
        self.assertIn('testsuites_unavailable=1', out)
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
        'transaction_state_after_deactivation_before_commit': 'RUNNING',
    },
    'child': {
        'connection_id': 555,
        'transaction_id': 666,
        'assignment_lock_query_sha256': 'a' * 64,
        'requesting_lock_id': 777,
        'transaction_state_while_waiting': 'LOCK WAIT',
        'transaction_state_after_deactivation_before_commit': 'RUNNING',
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
    'child_outcome_after_parent_commit': 'denied:CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_ACTIVE',
    'definition_status_after_parent_commit': 'inactive',
    'membership_role_after': 'cpms_secretary',
    'wordpress_roles_before': ['doctor'],
    'wordpress_roles_after': ['administrator'],
    'assignment_audit_count_after': 0,
    'deactivation_audit_count_after': 1,
    'statement_block_proof': {'errno': 1205, 'statement_sha256': 'c' * 64},
}


class LifecycleProjectionTest(unittest.TestCase):
    def test_good_record_keeps_bounded_fields(self):
        projected = ie.project_lifecycle(LIFECYCLE_GOOD)
        self.assertEqual('8.0.46', projected['mysql_version'])
        self.assertEqual('innodb_view', projected['blocking_evidence_source'])
        self.assertEqual(111, projected['parent']['connection_id'])
        self.assertEqual('cpms_custom_role_defs', projected['wait_edge']['table'])
        self.assertEqual('PRIMARY', projected['wait_edge']['index'])
        self.assertTrue(projected['same_wait_edge_after_deactivation_before_commit'])
        self.assertEqual('denied:CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_ACTIVE',
                         projected['child_outcome_after_parent_commit'])

    def test_raw_sql_lock_data_and_hashes_are_dropped(self):
        projected = ie.project_lifecycle(LIFECYCLE_GOOD)
        flat = json.dumps(projected)
        assert_no_canaries(self, flat)
        for banned in ('assignment_lock_query', 'statement_sha256', 'statement_block_proof":{"errno":1205,"statement_sha256"',
                       'sha256', 'lock_data', 'mysql_schema',
                       'membership_role_after', 'wordpress_roles'):
            self.assertNotIn(banned, flat)
        self.assertEqual({'errno': 1205}, projected['statement_block_proof'])

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

    def test_denied_token_is_enforced_exactly(self):
        bad = dict(LIFECYCLE_GOOD)
        bad['child_outcome_after_parent_commit'] = 'denied:DRO P TABLE'
        self.assertNotIn('child_outcome_after_parent_commit', ie.project_lifecycle(bad))
        bad2 = dict(LIFECYCLE_GOOD)
        bad2['child_outcome_after_parent_commit'] = 'denied:patient_mary_zoozle'
        self.assertNotIn('child_outcome_after_parent_commit', ie.project_lifecycle(bad2))
        bad3 = dict(LIFECYCLE_GOOD)
        bad3['child_outcome_after_parent_commit'] = 'assigned; patient_mary'
        self.assertNotIn('child_outcome_after_parent_commit', ie.project_lifecycle(bad3))
        ok = dict(LIFECYCLE_GOOD)
        ok['child_outcome_after_parent_commit'] = 'denied:CUSTOM_ROLE_TERMINAL_STATE'
        self.assertEqual('denied:CUSTOM_ROLE_TERMINAL_STATE',
                         ie.project_lifecycle(ok)['child_outcome_after_parent_commit'])
        assigned = dict(LIFECYCLE_GOOD)
        assigned['child_outcome_after_parent_commit'] = 'assigned'
        self.assertEqual('assigned', ie.project_lifecycle(assigned)['child_outcome_after_parent_commit'])

    def test_trx_state_rejects_values_and_unicode(self):
        bad = dict(LIFECYCLE_GOOD)
        parent = dict(LIFECYCLE_GOOD['parent'])
        parent['transaction_state_while_child_waited'] = 'LOCK WAIT; DROP TABLE patients'
        bad['parent'] = parent
        projected = ie.project_lifecycle(bad)
        self.assertNotIn('transaction_state_while_child_waited', projected['parent'])

    def test_lock_status_rejects_unknown_values(self):
        bad = dict(LIFECYCLE_GOOD)
        edge = dict(LIFECYCLE_GOOD['wait_edge'])
        edge['requesting_lock_status'] = 'PLANNED'
        bad['wait_edge'] = edge
        self.assertNotIn('requesting_lock_status', ie.project_lifecycle(bad)['wait_edge'])

    def test_unknown_table_dropped(self):
        bad = dict(LIFECYCLE_GOOD)
        edge = dict(LIFECYCLE_GOOD['wait_edge'])
        edge['table'] = 'wptests_cpms_secret_patient_rows'
        bad['wait_edge'] = edge
        self.assertNotIn('table', ie.project_lifecycle(bad)['wait_edge'])

    def test_unknown_definition_status_dropped(self):
        bad = dict(LIFECYCLE_GOOD)
        bad['definition_status_after_parent_commit'] = 'superadmin'
        self.assertNotIn('definition_status_after_parent_commit', ie.project_lifecycle(bad))

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
    'child_outcome_after_parent_commit': 'denied:CUSTOM_ROLE_TERMINAL_STATE',
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
        'worker_fds_beyond_stdio': {'5': 'dev-null', '6': 'pipe:[123456]', '7': 'evil value with spaces', 'fd:': 'other'},
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
        self.assertEqual('proc_open-independent-process', projected['worker_mechanism'])
        self.assertEqual({'wp_user_rows': 1, 'membership_rows': 1, 'definition_rows': 1},
                         projected['startup_fixture_probe'])
        self.assertEqual([5, 6, 7], projected['descriptor_isolation']['pinned_fds'])
        # pipe:[N] and unknown shapes carry kernel metadata we do not publish;
        # only the fixed categories and dev-null pass.
        self.assertEqual({'5': 'dev-null'}, projected['descriptor_isolation']['worker_fds_beyond_stdio'])

    def test_fd_categories_pass_when_exact(self):
        good = json.loads(json.dumps(ARCHIVAL_GOOD))
        good['descriptor_isolation']['worker_fds_beyond_stdio'] = {'5': 'dev-null', '6': 'pipe', '8': 'socket', '9': 'other'}
        projected = ie.project_archival(good)
        self.assertEqual({'5': 'dev-null', '6': 'pipe', '8': 'socket', '9': 'other'},
                         projected['descriptor_isolation']['worker_fds_beyond_stdio'])

    def test_raw_sql_ids_env_names_and_hashes_are_dropped(self):
        projected = ie.project_archival(ARCHIVAL_GOOD)
        flat = json.dumps(projected)
        assert_no_canaries(self, flat)
        for banned in ('definition_id', 'mysql_schema', '"waiter_lock_query":', 'waiter_lock_query_sha256',
                       'membership_role_after_commit', 'sha256',
                       'worker_env_keys', 'launch_environment_keys', 'MYSQL_ROOT_PASSWORD', 'lock_data'):
            self.assertNotIn(banned, flat)

    def test_unknown_case_mechanism_bootstrap_dropped(self):
        bad = json.loads(json.dumps(ARCHIVAL_GOOD))
        bad['case'] = 'attacker_selected_case'
        bad['worker_mechanism'] = '/bin/bash -c patient@example.com'
        bad['worker_bootstrap'] = 'proc/self/fd/12 patient@example.com'
        projected = ie.project_archival(bad)
        self.assertNotIn('case', projected)
        self.assertNotIn('worker_mechanism', projected)
        self.assertNotIn('worker_bootstrap', projected)
        assert_no_canaries(self, json.dumps(projected))

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

    def test_unknown_sql_mode_member_drops_whole_mode(self):
        bad = dict(self.GOOD)
        bad['sql_mode'] = 'STRICT_TRANS_TABLES,MADE_UP_MODE'
        self.assertNotIn('sql_mode', ie.project_heartbeat(bad))

    def test_oracle_mode_combo_is_accepted(self):
        ok = dict(self.GOOD)
        ok['sql_mode'] = 'PIPES_AS_CONCAT,ANSI_QUOTES,IGNORE_SPACE,ORACLE'
        self.assertEqual('PIPES_AS_CONCAT,ANSI_QUOTES,IGNORE_SPACE,ORACLE',
                         ie.project_heartbeat(ok)['sql_mode'])

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
        payload = {'count': 3, 'chain': ['10:ClinicCore\\Tests\\Support\\WpSqlFailureLedger::observe',
                                         '20:closure#123',
                                         '2147483647:TooBigPriority',
                                         'not a token with spaces secret-marker'] + ['1:X'] * 30}
        projected = ie.project_connection_diag(payload)
        self.assertIn('20:closure#123', projected['chain'])
        self.assertIn('10:ClinicCore\\Tests\\Support\\WpSqlFailureLedger::observe', projected['chain'])
        self.assertNotIn('2147483647:TooBigPriority', projected['chain'])
        self.assertNotIn('not a token with spaces secret-marker', projected['chain'])
        self.assertLessEqual(len(projected['chain']), 12)
        self.assertGreaterEqual(projected.get('chain_dropped', 0), 2)

    def test_chain_entry_must_not_carry_free_text(self):
        for hostile in ('10:Foo::bar(patient@example.com)',
                        '10:config.dbpassword',
                        '10:../../secret',
                        '10:Foo\\Bar::baz;DROP',
                        '10:دکتر',
                        '10:closure#',
                        'closure#abc'):
            payload = {'chain': [hostile]}
            projected = ie.project_connection_diag(payload)
            self.assertEqual([], projected.get('chain'), hostile)

    def test_table_vocabulary_exact(self):
        payload = {'table': 'cpms_patients', 'last_query': {'verb': 'select', 'table': 'cpms_patients'}}
        projected = ie.project_connection_diag(payload)
        self.assertEqual('cpms_patients', projected['table'])
        for hostile in ('cpms_patient_dumps', 'cpms_patients; DROP', 'CPMS_PATIENTS', 'information_schema', 'mysql'):
            payload = {'table': hostile}
            projected = ie.project_connection_diag(payload)
            self.assertNotIn('table', projected, hostile)

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


RED1_GOOD = '''RED1_PROBE v1 event=start
process pid line that must be ignored 123 patient@example.com
RED1_PROBE v1 event=step step=S1 result=pass
RED1_PROBE v1 event=step step=S2 result=pass
RED1_PROBE v1 event=step step=S3 result=pass
RED1_PROBE v1 event=step step=S4 result=pass
RED1_PROBE v1 event=step step=S5 result=fail class=RuntimeException file=SystemClinicResolver.php line=151
RED1_PROBE v1 event=step step=S6 result=fail class=RuntimeException file=HealthController.php line=88
RED1_PROBE v1 event=http step=S7 status=404 code=rest_no_route
RED1_PROBE v1 event=http step=S8 status=404 code=CLINIC_SCOPE_REQUIRED
RED1_PROBE v1 event=outcome verdict=red steps=8 passed=4 failed=4 first_failure=S5
'''


class Red1ProbeCommandTest(unittest.TestCase):
    def test_good_probe_log_projects_fixed_schema(self):
        path = write_tmp(RED1_GOOD, '.log')
        try:
            code, out = run_cli('red1-probe', path)
        finally:
            os.unlink(path)
        self.assertEqual(0, code)
        self.assertIn('red1_step step=S5 result=fail class=RuntimeException file=SystemClinicResolver.php line=151', out)
        self.assertIn('red1_http step=S7 status=404 code=rest_no_route', out)
        self.assertIn('red1_http step=S8 status=404 code=CLINIC_SCOPE_REQUIRED', out)
        self.assertIn('red1_outcome verdict=red steps=8 passed=4 failed=4 first_failure=S5', out)
        assert_no_canaries(self, out)
        for line in out.splitlines():
            self.assertFalse(line.startswith('process pid'), line)

    def test_hostile_code_and_class_are_dropped(self):
        log = ('RED1_PROBE v1 event=start\n'
               'RED1_PROBE v1 event=http step=S7 status=404 code=patient@example.com\n'
               'RED1_PROBE v1 event=http step=S8 status=404 code=CLINIC_SCOPE_REQUIRED\n'
               'RED1_PROBE v1 event=step step=S5 result=fail class=TotallyMadeUpException file=../../etc/passwd line=1\n'
               'RED1_PROBE v1 event=outcome verdict=red steps=8 passed=4 failed=4 first_failure=S5\n')
        path = write_tmp(log, '.log')
        try:
            code, out = run_cli('red1-probe', path)
        finally:
            os.unlink(path)
        self.assertIn('red1_http step=S7 status=404 code=unavailable', out)
        self.assertIn('red1_http step=S8 status=404 code=CLINIC_SCOPE_REQUIRED', out)
        self.assertIn('class=other', out)
        self.assertIn('file=unknown', out)
        assert_no_canaries(self, out)

    def test_raw_fatal_text_is_never_echoed(self):
        log = ('PHP Fatal error:  Uncaught RuntimeException: patient@example.com in /home/runner/work/doctor/x.php:99\n'
               'Stack trace:\n#0 /home/runner/work/doctor/x.php(99): patient@example.com()\n'
               'thrown in /home/runner/work/doctor/x.php on line 99\n')
        path = write_tmp(log, '.log')
        try:
            code, out = run_cli('red1-probe', path)
        finally:
            os.unlink(path)
        assert_no_canaries(self, out)
        self.assertIn('red1_projection_unavailable=1', out)
        self.assertIn('red1_php_fatal=detected', out)

    def test_fatal_event_line_passes_fixed_schema(self):
        log = ('RED1_PROBE v1 event=start\n'
               'PHP Fatal error:  Uncaught TypeError: secret-marker in /x.php:7\n'
               'RED1_PROBE v1 event=fatal class=TypeError file=bootstrap.php line=7\n')
        path = write_tmp(log, '.log')
        try:
            code, out = run_cli('red1-probe', path)
        finally:
            os.unlink(path)
        self.assertIn('red1_fatal_event class=TypeError file=bootstrap.php line=7', out)
        assert_no_canaries(self, out)

    def test_invalid_verdict_with_fixed_reason_only(self):
        log = ('RED1_PROBE v1 event=start\n'
               'RED1_PROBE v1 event=outcome verdict=invalid reason=database\n'
               'RED1_PROBE v1 event=outcome verdict=invalid reason=patient@example.com\n')
        path = write_tmp(log, '.log')
        try:
            code, out = run_cli('red1-probe', path)
        finally:
            os.unlink(path)
        # two outcome lines, both projected; the forged reason falls back to a fixed token
        self.assertIn('red1_outcome verdict=invalid reason=database', out)
        self.assertIn('red1_outcome verdict=invalid reason=precondition', out)
        assert_no_canaries(self, out)

    def test_verdict_pass(self):
        log = ('RED1_PROBE v1 event=start\n'
               + ''.join('RED1_PROBE v1 event=step step=%s result=pass\n' % s for s in ('S1','S2','S3','S4','S5','S6','S7','S8'))
               + 'RED1_PROBE v1 event=outcome verdict=pass steps=8 passed=8 failed=0\n')
        path = write_tmp(log, '.log')
        try:
            code, out = run_cli('red1-probe', path)
        finally:
            os.unlink(path)
        self.assertIn('red1_outcome verdict=pass steps=8 passed=8 failed=0', out)

    def test_forged_outcome_verdict_drops(self):
        log = ('RED1_PROBE v1 event=start\n'
               'RED1_PROBE v1 event=outcome verdict=patient@example.com steps=1 passed=1 failed=0\n')
        path = write_tmp(log, '.log')
        try:
            code, out = run_cli('red1-probe', path)
        finally:
            os.unlink(path)
        assert_no_canaries(self, out)
        # no valid outcome → nothing beyond the start line; exit still 0
        self.assertNotIn('red1_outcome', out)

    def test_unknown_events_and_keys_are_ignored(self):
        log = ('RED1_PROBE v1 event=start\n'
               'RED1_PROBE v1 event=step step=S9 result=pass\n'
               'RED1_PROBE v1 event=mystery key=patient@example.com\n'
               'RED1_PROBE v1 event=step step=S3 result=pass extra=patient@example.com\n'
               'RED1_PROBE v1 garbage line without equals signs patient@example.com\n'
               'RED1_PROBE v1 event=outcome verdict=pass steps=8 passed=8 failed=0\n')
        path = write_tmp(log, '.log')
        try:
            code, out = run_cli('red1-probe', path)
        finally:
            os.unlink(path)
        # extra= is tolerated inside key parsing but the key 'extra' is unknown;
        # S9/mystery lines are dropped; the well-formed S3 step survives.
        assert_no_canaries(self, out)

    def test_missing_log_fails_closed(self):
        code, out = run_cli('red1-probe', '/nonexistent/probe.log')
        self.assertEqual(0, code)
        self.assertIn('red1_projection_unavailable=1', out)


class OutputShapeTest(unittest.TestCase):
    def test_all_outputs_are_single_line_ascii(self):
        cases = [
            ('junit', JUNIT_INJECTION, '.xml'),
            ('junit', JUNIT_PROVIDER, '.xml'),
            ('junit', JUNIT_BASIC, '.xml'),
            ('red1-probe', RED1_GOOD, '.log'),
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

    def test_sha256_never_appears_anywhere(self):
        log = ('CUSTOM_ROLE_LIFECYCLE_CONCURRENCY_EVIDENCE ' + json.dumps(LIFECYCLE_GOOD) + '\n'
               + 'CUSTOM_ROLE_ARCHIVAL_CONCURRENCY_EVIDENCE ' + json.dumps(ARCHIVAL_GOOD) + '\n')
        path = write_tmp(log, '.log')
        try:
            for prefix in ('CUSTOM_ROLE_LIFECYCLE_CONCURRENCY_EVIDENCE', 'CUSTOM_ROLE_ARCHIVAL_CONCURRENCY_EVIDENCE'):
                code, out = run_cli('evidence', prefix, path)
                self.assertNotIn('sha256', out)
        finally:
            os.unlink(path)


class AnnotateCommandTest(unittest.TestCase):
    def test_caps_at_limit_and_offset_splits(self):
        path = write_tmp(JUNIT_CAP)
        try:
            code0, out0 = run_cli('annotate', path, '--offset', '0', '--limit', '10')
            code1, out1 = run_cli('annotate', path, '--offset', '10', '--limit', '10')
            code2, out2 = run_cli('annotate', path, '--offset', '12', '--limit', '10')
        finally:
            os.unlink(path)
        self.assertEqual(0, code0)
        self.assertEqual(0, code1)
        self.assertEqual(0, code2)
        first = out0.splitlines()
        second = out1.splitlines()
        self.assertEqual(10, len(first))
        self.assertEqual(2, len(second))
        self.assertEqual('', out2)
        for index, line in enumerate(first + second):
            self.assertEqual('::error::%s::testCap%02d' % (CAP, index + 1), line)

    def test_unverifiable_idents_are_never_printed(self):
        path = write_tmp(JUNIT_INJECTION)
        try:
            code, out = run_cli('annotate', path)
        finally:
            os.unlink(path)
        self.assertEqual(0, code)
        self.assertEqual('', out)
        assert_no_canaries(self, out)

    def test_malformed_xml_exits_zero_without_output(self):
        path = write_tmp('<testsuites><testsuite>')
        try:
            code, out = run_cli('annotate', path)
        finally:
            os.unlink(path)
        self.assertEqual(0, code)
        self.assertEqual('', out)

    def test_limit_is_clamped_to_twenty(self):
        path = write_tmp(JUNIT_CAP)
        try:
            code, out = run_cli('annotate', path, '--offset', '0', '--limit', '50')
        finally:
            os.unlink(path)
        self.assertEqual(0, code)
        self.assertEqual(12, len(out.splitlines()))


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
