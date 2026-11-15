#!/usr/bin/env python3
"""Round 51 — executable gate test for the Integration job's MariaDB pin step.

Extracts the REAL step-7 shell block from .github/workflows/ci.yml (it is
never copied into this test), then runs it under bash with a shim `mysql`
(and a shim tests/bin/install-wp-tests.sh) on PATH in a temp directory.

Cases:
  (a) shim prints a client WARNING on stderr and a matching VERSION() on
      stdout  -> the gate MUST pass (this is the Round-49/50 regression:
      merging stderr into the compared value made the exact pin never match);
  (b) non-matching version on stdout -> gate fails, fixed-vocabulary
      diagnostic only, no shim bytes published;
  (c) mysql exits nonzero -> gate fails, fixed-vocabulary diagnostic only;
  (d) mysql prints nothing, exits 0 -> gate fails (empty != pinned);
  (e) matching stdout + EMPTY stderr + non-zero mysql exit -> gate fails
      via the rc branch (fixed tokens; version prefix matched but rc=0
      is required).

Enforcement: this suite is executed in CI by the ci.yml job
"Tenant Tripwire (hardcode scan)", step "MariaDB step 7 pin gate shell test",
on every pull_request (no `|| true`, no continue-on-error — a failure fails
the job). Needs bash + python3 only; no mysql, no PHP, no WordPress.
The temp dir honors TMPDIR (default system temp); no fixed path is
hard-coded.

Non-vacuity: CI_YML=<path> python3 test_mariadb_pin_step.py <unittest args>
runs the same suite against another workflow file; case (a) MUST fail there
when that file carries the pre-fix step-7 body (command substitution that
merges stderr into the compared value).

This proves SHELL BEHAVIOR ONLY. It does NOT prove what the real runner's
mysql client prints on stdout/stderr, nor that the pinned server is reachable.
"""

import os
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]
CI_YML = Path(os.environ.get('CI_YML', REPO_ROOT / '.github' / 'workflows' / 'ci.yml'))
STEP_NAME = 'Prepare MariaDB 11.4.13 WordPress test suite'
MATCH_VERSION = '11.4.13-MariaDB-1:11.4.13+maria~ubu2404'
NOMATCH_VERSION = '8.0.44-0ubuntu0.24.04.5'
STD0UT_MARKER = 'SHIM_STDOUT_MARKER'
STDERR_MARKER = 'SHIM_STDERR_MARKER'


def extract_step_script(ci_yml_text, step_name):
    """Extract the dedented `run: |` body of one named step, textually."""
    lines = ci_yml_text.splitlines()
    start = None
    needle = '- name: {0}'.format(step_name)
    for index, line in enumerate(lines):
        if line.strip() == needle:
            start = index
            break
    if start is None:
        raise AssertionError('step not found in {0}: {1}'.format(CI_YML, step_name))
    run_index = None
    run_indent = None
    for index in range(start + 1, len(lines)):
        stripped = lines[index].strip()
        if stripped.startswith('- name:'):
            break
        if stripped.startswith('run:'):
            run_index = index
            run_indent = len(lines[index]) - len(lines[index].lstrip())
            break
    if run_index is None:
        raise AssertionError('run block not found for step: {0}'.format(step_name))
    body = []
    for line in lines[run_index + 1:]:
        if line.strip():
            indent = len(line) - len(line.lstrip())
            if indent <= run_indent:
                break
            body.append(line[run_indent + 2:])
        else:
            body.append('')
    return '\n'.join(body)


class MariaDBPinStepTest(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp(prefix='pr336-step7-'))
        bin_dir = self.tmp / 'shimbin'
        bin_dir.mkdir()
        install_stub = self.tmp / 'tests' / 'bin'
        install_stub.mkdir(parents=True)
        self.install_record = self.tmp / 'install-args.txt'
        stub = install_stub / 'install-wp-tests.sh'
        stub.write_text(
            '#!/bin/bash\n'
            'printf \'%s\\n\' "$@" > "{0}"\n'
            'echo {1}\n'.format(self.install_record, STD0UT_MARKER),
            encoding='utf-8')
        stub.chmod(stub.stat().st_mode | stat.S_IXUSR)
        self.script = extract_step_script(CI_YML.read_text(encoding='utf-8'), STEP_NAME)
        self.mysql_path = bin_dir / 'mysql'
        self.env = dict(os.environ)
        self.env['PATH'] = str(bin_dir) + os.pathsep + self.env.get('PATH', '')

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def _write_mysql_shim(self, body):
        self.mysql_path.write_text('#!/bin/bash\n' + body, encoding='utf-8')
        self.mysql_path.chmod(self.mysql_path.stat().st_mode | stat.S_IXUSR)

    def _run_step(self):
        return subprocess.run(
            ['bash', '-c', self.script],
            cwd=str(self.tmp),
            env=self.env,
            capture_output=True,
            text=True,
            timeout=60,
        )

    def _assert_no_shim_bytes(self, result):
        combined = result.stdout + result.stderr
        for token in (MATCH_VERSION, NOMATCH_VERSION, STD0UT_MARKER, STDERR_MARKER):
            self.assertNotIn(token, combined,
                             'shim bytes leaked into step output')

    def test_a_matching_version_with_stderr_warning_passes_gate(self):
        self._write_mysql_shim(
            'echo {0}-1>&2\n'
            'echo {1}\n'.format(STDERR_MARKER, MATCH_VERSION))
        result = self._run_step()
        self._assert_no_shim_bytes(result)
        self.assertEqual(0, result.returncode,
                         'gate must pass when stdout matches the pin; stderr is separate (got rc={0})'.format(result.returncode))
        self.assertIn('MariaDB server matched pinned 11.4.13', result.stdout)
        self.assertTrue(self.install_record.exists(),
                        'install-wp-tests.sh must be invoked after the gate passes')
        self.assertEqual(
            ['wordpress_mariadb', 'root', 'root', '127.0.0.1', '7.1.3', '3307'],
            self.install_record.read_text(encoding='utf-8').splitlines())

    def test_b_non_matching_version_fails_with_fixed_diagnostics_only(self):
        self._write_mysql_shim('echo {0}\n'.format(NOMATCH_VERSION))
        result = self._run_step()
        self.assertNotEqual(0, result.returncode)
        combined = result.stdout + result.stderr
        self.assertIn('MariaDB server does not match pinned 11.4.13', combined)
        for token in ('stdout_empty=false', 'stdout_starts_with_expected_prefix=false',
                      'stdout_line_count=1', 'stderr_nonempty=false'):
            self.assertIn(token, combined)
        self._assert_no_shim_bytes(result)
        self.assertFalse(self.install_record.exists(),
                         'install-wp-tests.sh must NOT run when the gate fails')

    def test_c_client_failure_fails_with_fixed_diagnostics_only(self):
        self._write_mysql_shim(
            'echo {0}-broken-client 1>&2\n'
            'exit 1\n'.format(STDERR_MARKER))
        result = self._run_step()
        self.assertNotEqual(0, result.returncode)
        combined = result.stdout + result.stderr
        self.assertIn('MariaDB server does not match pinned 11.4.13', combined)
        for token in ('stdout_empty=true', 'stdout_starts_with_expected_prefix=false',
                      'stdout_line_count=0', 'stderr_nonempty=true'):
            self.assertIn(token, combined)
        self._assert_no_shim_bytes(result)
        self.assertFalse(self.install_record.exists())

    def test_e_matching_stdout_with_nonzero_rc_fails_via_rc_branch(self):
        # Round 52 LOW-4.1: isolates the rc != 0 branch even when the stdout
        # prefix matches the pin and stderr is empty. The gate must still
        # fail, with fixed tokens only (prefix token stays true).
        self._write_mysql_shim('echo {0}\nexit 3\n'.format(MATCH_VERSION))
        result = self._run_step()
        self.assertNotEqual(0, result.returncode)
        combined = result.stdout + result.stderr
        self.assertIn('MariaDB server does not match pinned 11.4.13', combined)
        for token in ('stdout_empty=false',
                      'stdout_starts_with_expected_prefix=true',
                      'stdout_line_count=1',
                      'stderr_nonempty=false'):
            self.assertIn(token, combined)
        self._assert_no_shim_bytes(result)
        self.assertFalse(self.install_record.exists())

    def test_d_empty_output_fails_with_fixed_diagnostics_only(self):
        self._write_mysql_shim('exit 0\n')
        result = self._run_step()
        self.assertNotEqual(0, result.returncode)
        combined = result.stdout + result.stderr
        self.assertIn('MariaDB server does not match pinned 11.4.13', combined)
        for token in ('stdout_empty=true', 'stdout_starts_with_expected_prefix=false',
                      'stdout_line_count=0', 'stderr_nonempty=false'):
            self.assertIn(token, combined)
        self._assert_no_shim_bytes(result)
        self.assertFalse(self.install_record.exists())


if __name__ == '__main__':
    unittest.main()
