import os
import tempfile
import unittest
import xml.etree.ElementTree as ET

from integration_diagnostics import junit_stats, terminal_summary_printed


class IntegrationDiagnosticsTest(unittest.TestCase):
    def test_nested_suites_with_direct_and_data_provider_cases(self):
        root = ET.fromstring('''<testsuites><testsuite tests="3" assertions="9">
          <testcase name="direct" assertions="4"/>
          <testsuite tests="2" assertions="5"><testcase name="a" assertions="2"/>
          <testcase name="b" assertions="3"><failure/></testcase></testsuite>
        </testsuite></testsuites>''')
        self.assertEqual({'cases': 3, 'assertions': 9, 'failures': 1, 'errors': 0, 'skipped': 0}, junit_stats(root))

    def test_summary_before_long_shutdown_output(self):
        with tempfile.NamedTemporaryFile(mode='w', delete=False) as handle:
            handle.write('OK (3 tests, 9 assertions)\n' + 'x' * 6000)
            path = handle.name
        try:
            self.assertTrue(terminal_summary_printed(path))
            with open(path, 'w') as handle:
                handle.write('prefix OK (3 tests)\n' + 'x' * 6000)
            self.assertFalse(terminal_summary_printed(path))
        finally:
            os.unlink(path)


if __name__ == '__main__':
    unittest.main()
