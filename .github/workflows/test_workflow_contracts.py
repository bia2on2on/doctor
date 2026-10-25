"""Static and executable contracts for acceptance workflow routing (stdlib only)."""
from pathlib import Path
import json
import os
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = ROOT / ".github" / "workflows"
PUBLISHER = ROOT / ".github" / "scripts" / "post-pr-evidence.sh"
SAFE_PILOT_EVIDENCE = ROOT / "clinic-practice-management" / "bin" / "pilot-safe-evidence.py"


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

    def test_closure_and_pilot_gates_keep_branch_independent_pr_triggers(self):
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

    def test_workflow_contract_suite_is_run_by_ci(self):
        text = workflow("ci.yml")
        self.assertIn("Workflow evidence and acceptance contract tests", text)
        self.assertIn("python3 -m unittest discover -s .github/workflows -p 'test_workflow_contracts.py' -v", text)
        self.assertIn("working-directory: ${{ github.workspace }}", text)
        self.assertIn("name: Pilot benchmark report safety self-tests", text)
        self.assertIn("python3 bin/pilot-bench-report.py --test", text)
        self.assertIn("working-directory: ${{ github.workspace }}/clinic-practice-management", text)

    def test_all_fifteen_evidence_publishers_use_shared_fail_loud_router(self):
        for filename, expected_count in (("closure-gate.yml", 2), ("pilot-gate.yml", 13)):
            with self.subTest(filename=filename):
                text = workflow(filename)
                self.assertEqual(text.count(".github/scripts/post-pr-evidence.sh"), expected_count)
                self.assertNotIn('gh pr list --head "${{ github.ref_name }}"', text)
                self.assertNotRegex(text, r'gh pr comment "\$PR"[^\n]*\|\|')
                self.assertIn("CPMS_PR_NUMBER: ${{ github.event.pull_request.number }}", text)
        helper = PUBLISHER.read_text(encoding="utf-8")
        self.assertIn('case "$EVENT" in', helper)
        self.assertIn('PR_NUMBER=${CPMS_PR_NUMBER:-}', helper)
        self.assertIn("Required evidence comment to PR", helper)
        self.assertNotIn("gh api", helper, "do not broaden commit-comment permissions")
        self.assertIn("cat \"$EVIDENCE\" >> \"$GITHUB_STEP_SUMMARY\"", helper)

    def test_failure_dump_guards_and_bounds_are_preserved(self):
        text = workflow("pilot-gate.yml")
        self.assertEqual(text.count("- name: Debug dump به PR"), 3)
        self.assertEqual(text.count("if: failure()"), 3)
        self.assertEqual(text.count('tail -50 "$f"'), 3)
        self.assertEqual(text.count("bash \"$GITHUB_WORKSPACE/.github/scripts/post-pr-evidence.sh\" /tmp/debug.md"), 3)

    def test_closure_evidence_redacts_full_and_masked_install_identifiers(self):
        text = workflow("closure-gate.yml")
        self.assertIn("$installIdValid = isset($meta['install_id'])", text)
        self.assertIn("unset($meta['install_id'], $meta['install_id_masked']);", text)
        self.assertIn("json_encode($meta)", text)

    def test_benchmark_runs_on_pr_and_retains_other_events_and_measurement_matrix(self):
        text = workflow("pilot-gate.yml")
        self.assertIn("CPMS_PR_NUMBER: ${{ github.event.pull_request.number }}", text)
        self.assertIn(
            "if: ${{ github.event_name == 'pull_request' || github.event_name == 'workflow_dispatch' || github.event_name == 'schedule' || github.event_name == 'push' }}",
            text,
        )
        self.assertIn("- name: Performance benchmark — ab (cold + warm, c=1/10/50/100) → JSON/MD artifact", text)
        self.assertIn("measure cold 'GET /health", text)
        self.assertIn("measure cold 'GET /availability", text)
        self.assertIn("measure cold 'GET /wp-json/", text)
        self.assertEqual(text.count("for C in 10 50 100; do measure warm"), 2)
        self.assertIn("3000 \"$C\" 2", text)
        self.assertIn("1500 \"$C\" 2", text)
        self.assertIn("1000 50 2", text)
        self.assertIn("NO latency threshold is asserted here", text)


class SensitiveEvidenceProjectionTests(unittest.TestCase):
    def render(self, suite, fixture, results):
        with tempfile.TemporaryDirectory(prefix="pilot-safe-evidence-") as tmp:
            tmp_path = Path(tmp)
            env_file = tmp_path / "fixture.env"
            results_file = tmp_path / "results.json"
            env_file.write_text(fixture, encoding="utf-8")
            results_file.write_text(json.dumps(results), encoding="utf-8")
            return subprocess.run(
                [
                    "python3",
                    str(SAFE_PILOT_EVIDENCE),
                    "--suite",
                    suite,
                    "--env-file",
                    str(env_file),
                    "--results-file",
                    str(results_file),
                ],
                cwd=ROOT,
                text=True,
                capture_output=True,
                check=False,
            )

    def test_slice3_fixture_fields_nine_and_ten_and_result_details_are_never_projected(self):
        login = "SYNTHETIC_LOGIN_SENTINEL_DO_NOT_PUBLISH"
        password = "SYNTHETIC_PASSWORD_SENTINEL_DO_NOT_PUBLISH"
        fixture = (
            "SLICE3_PAGE=https://example.invalid/pilot|1|2|3|4|5|6|7|"
            f"{login}|{password}\n"
        )
        safe_results = {
            "summary": {"ok": True, "runs": 2, "failed": []},
            "results": [{"key": "slice3-mobile-390-01", "status": "PASS"}],
        }
        result = self.render("slice3", fixture, safe_results)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Checks: 1 passed; 0 failed", result.stdout)
        self.assertNotIn(login, result.stdout)
        self.assertNotIn(password, result.stdout)

        # Even if a future runner accidentally adds an arbitrary detail field,
        # the renderer rejects the schema and emits only a fixed safe fallback.
        unsafe_results = {
            "summary": {"ok": True, "runs": 2, "failed": []},
            "results": [
                {
                    "key": "slice3-mobile-390-01",
                    "status": "PASS",
                    "detail": f"{login} {password} otp nonce cookie session token",
                }
            ],
        }
        rejected = self.render("slice3", fixture, unsafe_results)
        self.assertNotEqual(rejected.returncode, 0)
        self.assertNotIn(login, rejected.stdout)
        self.assertNotIn(password, rejected.stdout)
        self.assertIn("contents withheld", rejected.stdout)

    def test_portal_cancel_projection_withholds_login_password_and_patient_ids(self):
        login = "PORTAL_LOGIN_SENTINEL_DO_NOT_PUBLISH"
        password = "PORTAL_PASSWORD_SENTINEL_DO_NOT_PUBLISH"
        fixture = (
            "PORTAL_CANCEL=" + login + "|" + password
            + "|12|PATIENT_ID_SENTINEL|21|22|23|24|25|26,27,28|29\n"
        )
        results = {
            "summary": {"ok": True, "runs": 2, "failed": []},
            "results": [{"key": "portal-cancel-mobile-390", "status": "PASS"}],
        }
        result = self.render("portal-cancel", fixture, results)
        self.assertEqual(result.returncode, 0, result.stderr)
        for sentinel in (login, password, "PATIENT_ID_SENTINEL"):
            self.assertNotIn(sentinel, result.stdout)
        self.assertIn("Result: PASS", result.stdout)

    def test_workflow_uses_safe_projection_and_removes_confirmed_raw_identifier_rows(self):
        text = workflow("pilot-gate.yml")
        self.assertIn("pilot-safe-evidence.py --suite slice3", text)
        self.assertIn("pilot-safe-evidence.py --suite portal-cancel", text)
        self.assertNotIn("cat /tmp/slice3.env 2>/dev/null", text)
        self.assertIn('cat /tmp/slice3.env >> "$GITHUB_ENV"', text)
        self.assertNotIn("cut -d'|' -f1,3- /tmp/portal-cancel.env", text)
        self.assertNotIn("PROFILE_PUBLIC", text)
        self.assertNotIn("DOCTOR_PORTAL_PUBLIC", text)
        self.assertIn("PROFILE_FIXTURE_OK", text)
        self.assertIn("DOCTOR_PORTAL_FIXTURE_OK", text)


class EvidenceTargetRuntimeTests(unittest.TestCase):
    """Execute the real publisher with a deterministic fake gh executable."""

    def run_publisher(self, event, ref_name, *, pr_number="", candidates=None, fail_comment=False, fail_list=False):
        with tempfile.TemporaryDirectory(prefix="evidence-target-test-") as tmp:
            tmp_path = Path(tmp)
            fake_bin = tmp_path / "bin"
            fake_bin.mkdir()
            gh = fake_bin / "gh"
            gh.write_text(
                "#!/usr/bin/env python3\n"
                "import json, os, pathlib, sys\n"
                "args = sys.argv[1:]\n"
                "with open(os.environ['GH_TEST_CALLS'], 'a', encoding='utf-8') as f:\n"
                "    f.write(json.dumps(args) + '\\n')\n"
                "if args[:2] == ['pr', 'list'] and os.environ.get('GH_TEST_FAIL_LIST') == '1':\n"
                "    print('simulated PR lookup failure', file=sys.stderr)\n"
                "    sys.exit(22)\n"
                "elif args[:2] == ['pr', 'list']:\n"
                "    print(os.environ.get('GH_TEST_CANDIDATES', '[]'))\n"
                "elif args[:2] == ['pr', 'comment'] and os.environ.get('GH_TEST_FAIL_COMMENT') == '1':\n"
                "    print('simulated comment transport failure', file=sys.stderr)\n"
                "    sys.exit(23)\n"
                "elif args[:2] != ['pr', 'comment']:\n"
                "    print('unexpected gh command: ' + repr(args), file=sys.stderr)\n"
                "    sys.exit(24)\n",
                encoding="utf-8",
            )
            gh.chmod(0o755)
            evidence = tmp_path / "evidence.md"
            evidence.write_text("synthetic safe evidence\n", encoding="utf-8")
            calls_file = tmp_path / "gh-calls.jsonl"
            summary = tmp_path / "summary.md"
            summary.touch()
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{fake_bin}{os.pathsep}{env.get('PATH', '')}",
                    "GH_TEST_CALLS": str(calls_file),
                    "GH_TEST_CANDIDATES": json.dumps(candidates or []),
                    "GH_TEST_FAIL_COMMENT": "1" if fail_comment else "0",
                    "GH_TEST_FAIL_LIST": "1" if fail_list else "0",
                    "GITHUB_EVENT_NAME": event,
                    "GITHUB_REPOSITORY": "bia2on2on/doctor",
                    "GITHUB_REPOSITORY_OWNER": "bia2on2on",
                    "GITHUB_REF_NAME": ref_name,
                    "GITHUB_STEP_SUMMARY": str(summary),
                    "CPMS_PR_NUMBER": pr_number,
                }
            )
            result = subprocess.run(
                ["bash", str(PUBLISHER), str(evidence)],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )
            calls = [json.loads(line) for line in calls_file.read_text().splitlines()] if calls_file.exists() else []
            return result, calls, summary.read_text(encoding="utf-8")

    def test_pull_request_uses_event_number_not_merge_ref_lookup(self):
        result, calls, summary = self.run_publisher("pull_request", "269/merge", pr_number="269")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0][:6], ["pr", "comment", "269", "--repo", "bia2on2on/doctor", "--body-file"])
        self.assertTrue(Path(calls[0][6]).is_absolute())
        self.assertFalse(any(call[:2] == ["pr", "list"] for call in calls))
        self.assertEqual(summary, "")

    def test_main_push_schedule_and_main_dispatch_use_summary_not_an_inferred_pr(self):
        for event in ("push", "schedule", "workflow_dispatch"):
            with self.subTest(event=event):
                result, calls, summary = self.run_publisher(event, "main")
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(calls, [])
                self.assertEqual(summary, "synthetic safe evidence\n")

    def test_manual_source_branch_resolves_only_one_exact_same_repository_target(self):
        candidates = [
            {"number": 269, "headRefName": "arena/topic", "baseRefName": "main", "headRepositoryOwner": {"login": "bia2on2on"}},
            {"number": 991, "headRefName": "other", "baseRefName": "main", "headRepositoryOwner": {"login": "bia2on2on"}},
            {"number": 992, "headRefName": "arena/topic", "baseRefName": "main", "headRepositoryOwner": {"login": "fork-owner"}},
            {"number": 993, "headRefName": "arena/topic", "baseRefName": "develop", "headRepositoryOwner": {"login": "bia2on2on"}},
        ]
        result, calls, summary = self.run_publisher("workflow_dispatch", "arena/topic", candidates=candidates)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(calls[0][:2], ["pr", "list"])
        self.assertIn("--head", calls[0])
        self.assertEqual(calls[0][calls[0].index("--head") + 1], "arena/topic")
        self.assertEqual(calls[1][:3], ["pr", "comment", "269"])
        self.assertEqual(summary, "")

    def test_unrelated_or_ambiguous_pr_is_never_commented(self):
        unrelated = [
            {"number": 991, "headRefName": "different-branch", "baseRefName": "main", "headRepositoryOwner": {"login": "bia2on2on"}},
            {"number": 992, "headRefName": "arena/topic", "baseRefName": "main", "headRepositoryOwner": {"login": "other-owner"}},
        ]
        result, calls, summary = self.run_publisher("workflow_dispatch", "arena/topic", candidates=unrelated)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0][:2], ["pr", "list"])
        self.assertNotIn("comment", [call[1] for call in calls if len(call) > 1])
        self.assertEqual(summary, "synthetic safe evidence\n")
        self.assertIn("no PR comment will be attempted", result.stdout)

        duplicate = [
            {"number": n, "headRefName": "arena/topic", "baseRefName": "main", "headRepositoryOwner": {"login": "bia2on2on"}}
            for n in (269, 270)
        ]
        result, calls, _ = self.run_publisher("workflow_dispatch", "arena/topic", candidates=duplicate)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 1)

    def test_required_pr_comment_failure_is_nonzero_and_evidence_remains_in_summary(self):
        result, calls, summary = self.run_publisher("pull_request", "269/merge", pr_number="269", fail_comment=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(calls[0][:3], ["pr", "comment", "269"])
        self.assertEqual(summary, "synthetic safe evidence\n")
        self.assertIn("Required evidence comment to PR #269 failed", result.stderr)

    def test_manual_target_lookup_failure_is_not_silenced(self):
        result, calls, summary = self.run_publisher("workflow_dispatch", "arena/topic", fail_list=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0][:2], ["pr", "list"])
        self.assertNotIn("comment", [call[1] for call in calls if len(call) > 1])
        self.assertEqual(summary, "")
        self.assertIn("simulated PR lookup failure", result.stderr)

    def test_invalid_pull_request_number_fails_closed_without_any_comment(self):
        result, calls, summary = self.run_publisher("pull_request", "269/merge", pr_number="")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(calls, [])
        self.assertEqual(summary, "")


if __name__ == "__main__":
    unittest.main()
