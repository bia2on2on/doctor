"""Static trigger/matrix contract checks for acceptance workflows (stdlib only)."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = ROOT / ".github" / "workflows"


def workflow(name):
    return (WORKFLOWS / name).read_text(encoding="utf-8")


class WorkflowContractTests(unittest.TestCase):
    def test_facility_matrix_runs_on_main_push_and_all_supported_databases(self):
        text = workflow("facility-matrix-acceptance.yml")
        events = text.split("on:\n", 1)[1].split("\nconcurrency:", 1)[0]
        self.assertRegex(events, r"(?m)^  pull_request:\n    branches: \[main\]$")
        self.assertRegex(events, r"(?m)^  push:\n    branches: \[main\]$")
        matrix = text.split("      matrix:\n", 1)[1].split("\n\n    services:", 1)[0]
        self.assertIn("database: mariadb", matrix)
        self.assertIn("image: mariadb:11.4.13", matrix)
        self.assertIn("database: mysql", matrix)
        self.assertIn("image: mysql:8", matrix)
        self.assertIn("name: Facility matrix (${{ matrix.database }})", text)
        self.assertIn("cancel-in-progress: ${{ github.ref != 'refs/heads/main' }}", text)
        self.assertIn("main-run-{0}', github.run_id)", text)

    def test_closure_and_pilot_gates_cover_arbitrary_pr_branch_names_once(self):
        for filename in ("closure-gate.yml", "pilot-gate.yml"):
            with self.subTest(filename=filename):
                text = workflow(filename)
                events = text.split("on:\n", 1)[1].split("\nconcurrency:", 1)[0]
                self.assertRegex(events, r"(?m)^  pull_request:\n    branches: \[main\]$")
                self.assertRegex(events, r"(?m)^  push:\n    branches: \[main\]$")
                self.assertNotIn("arena/**", events)
                self.assertIn("cancel-in-progress: ${{ github.ref != 'refs/heads/main' }}", text)
        self.assertIn("name: Closure Gate (GO-LIVE evidence closure)", workflow("closure-gate.yml"))
        self.assertIn("name: Pilot/Staging Readiness Gate", workflow("pilot-gate.yml"))

    def test_persian_wc_diagnosis_is_named_and_acceptance_requires_stage_b(self):
        text = workflow("persian-coexistence.yml")
        self.assertIn(
            "name: Persian WooCommerce 10.0.7 Stage-A-only diagnosis (CPMS intentionally absent)",
            text,
        )
        acceptance = text.split("  coexistence-persian-woocommerce-acceptance:\n", 1)[1]
        acceptance = acceptance.split("\n  coexistence-persian-woocommerce-sms:", 1)[0]
        self.assertIn("coexistence_group: persian-woocommerce-acceptance", acceptance)
        self.assertIn("coexistence_stage_b: true", acceptance)
        self.assertRegex(acceptance, r"(?m)^    uses: \.\/\.github\/workflows\/real-wp-acceptance\.yml$")
        self.assertRegex(acceptance, r"(?m)^    name: Persian WooCommerce 10\.0\.7 \+ CPMS Stage A/B acceptance$")
        reusable = workflow("real-wp-acceptance.yml")
        self.assertIn("run.py begin-b", reusable)
        self.assertIn("id: cpms_install", reusable)
        self.assertIn("run.py finalize", reusable)


if __name__ == "__main__":
    unittest.main()
