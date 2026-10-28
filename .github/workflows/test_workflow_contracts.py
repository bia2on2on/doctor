"""Static and executable contracts for acceptance workflow routing (stdlib only)."""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

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

    def test_disposable_demo_lane_is_path_scoped_to_its_own_files(self):
        """The lane must not act as a global gate: it runs only for changes to
        its own files, and its isolation decision is made by an executable,
        unit-tested script rather than an inline conditional."""
        text = workflow("disposable-demo-installer.yml")
        events = text.split("on:\n", 1)[1].split("\npermissions:", 1)[0]

        self.assertEqual(events.count("    paths:\n"), 2, events)
        for path in (
            "'.github/workflows/disposable-demo-installer.yml'",
            "'.github/scripts/companion-isolation.py'",
            "'tools/disposable-demo-installer/**'",
            "'bin/build-demo-installer.sh'",
            "'.github/actions/playwright-browsers/**'",
            "'docs/demo-setup-fa.md'",
        ):
            self.assertEqual(events.count(path), 2, path)
        self.assertIn("workflow_dispatch:", events)

        # The old inline guard let mixed changes through as NOT-APPLICABLE.
        self.assertNotIn("NOT-APPLICABLE", text)
        self.assertIn("Companion/production isolation (fail closed on mixed changes)", text)
        self.assertIn(
            ".github/scripts/companion-isolation.py", text,
            "the isolation decision must come from the unit-tested script",
        )

    def test_admin_narrow_acceptance_is_real_chromium_at_320_and_390(self):
        """B-3 must be proven in a real browser at 320px and 390px for the ten
        named slugs — not asserted from markup."""
        text = workflow("pilot-gate.yml")
        self.assertIn("Admin narrow RTL acceptance (Chromium — 320/390 × 10 slugs)", text)
        responsive_job = text.split("  responsive:\n", 1)[1].split("\n  # ====", 1)[0]
        self.assertIn("python3 bin/pilot-admin-narrow.py | tee /tmp/admin-narrow.log", responsive_job)
        self.assertIn('test "${PIPESTATUS[0]}" -eq 0', responsive_job)

        script = (ROOT / "clinic-practice-management" / "bin" / "pilot-admin-narrow.py").read_text(encoding="utf-8")
        # Two narrow widths, exactly.
        self.assertIn('("narrow-320", 320, 800)', script)
        self.assertIn('("narrow-390", 390, 844)', script)
        for slug in (
            "cpms-dashboard",
            "cpms-settings",
            "cpms-system",
            "cpms-sms",
            "cpms-custom-roles",
            "cpms-finance-delegation",
            "cpms-organizations",
            "cpms-organization-managers",
            "cpms-organization-manager-bootstrap",
            "cpms-shortcodes",
        ):
            self.assertIn(f'("{slug}"', script, slug)
        # Persian RTL, overflow, keyboard and role-denial assertions must exist.
        for needle in ('rtl', "scrollWidth", "keyboard-reaches-section-nav", "ROLE_MATRIX"):
            self.assertIn(needle, script)
        self.assertIn("FAIL admin narrow RTL acceptance", script)

    def test_org_manager_narrow_screen_stylesheet_keeps_the_assets_contract(self):
        """The two organization-manager pages must be fixed with their own
        scoped stylesheet, not by widening CpmsAssets::PAGES."""
        assets = (ROOT / "clinic-practice-management" / "src" / "Admin" / "CpmsAssets.php").read_text(encoding="utf-8")
        allowlist = assets.split("private const PAGES = [", 1)[1].split("];", 1)[0]
        self.assertNotIn("'cpms-organization-managers'", allowlist)
        self.assertNotIn("'cpms-organization-manager-bootstrap'", allowlist)

        css = (ROOT / "clinic-practice-management" / "assets" / "css" / "cpms-org-manager.css").read_text(encoding="utf-8")
        self.assertIn("body.cpms-org-manager", css)
        self.assertIn("@media (max-width: 782px)", css)
        self.assertIn("width: 100%", css)
        self.assertIn("overflow-x: auto", css)
        self.assertNotIn("overflow: hidden", css)

    def test_lane_runs_its_own_contract_and_security_suite(self):
        """A workflow-only edit must not be able to disable the lane's controls."""
        text = workflow("disposable-demo-installer.yml")
        static_job = text.split("  static:\n", 1)[1].split("\n  real-wordpress:", 1)[0]
        self.assertIn(
            "python3 -m unittest discover -s .github/workflows -p 'test_workflow_contracts.py' -v",
            static_job,
        )


class CompanionIsolationTests(unittest.TestCase):
    """Executable tests for the companion/production isolation rule."""

    SCRIPT = ROOT / ".github" / "scripts" / "companion-isolation.py"

    def _run(self, paths):
        import subprocess
        import tempfile

        with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as handle:
            handle.write("\n".join(paths) + "\n")
            listing = handle.name
        try:
            return subprocess.run(
                [sys.executable, str(self.SCRIPT), "--changed-files", listing],
                capture_output=True, text=True, check=False,
            )
        finally:
            os.unlink(listing)

    def test_production_only_change_is_allowed(self):
        result = self._run(["clinic-practice-management/src/Admin/SystemPage.php"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("companion-isolation: PASS", result.stdout)

    def test_companion_only_change_is_allowed_when_production_is_untouched(self):
        result = self._run(["tools/disposable-demo-installer/uninstall.php"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("companion-isolation: PASS", result.stdout)

    def test_mixed_companion_and_production_change_fails_closed(self):
        result = self._run([
            "tools/disposable-demo-installer/uninstall.php",
            "clinic-practice-management/src/Admin/SystemPage.php",
        ])
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("companion-isolation: FAIL", result.stdout)
        self.assertIn("mixed change", result.stdout)

    def test_build_script_counts_as_companion(self):
        result = self._run([
            "bin/build-demo-installer.sh",
            "clinic-practice-management/src/Bootstrap/App.php",
        ])
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)

    def test_workflow_only_change_is_allowed(self):
        result = self._run([".github/workflows/disposable-demo-installer.yml"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_unreadable_change_list_fails_closed(self):
        import subprocess

        result = subprocess.run(
            [sys.executable, str(self.SCRIPT), "--changed-files", "/nonexistent/changed.txt"],
            capture_output=True, text=True, check=False,
        )
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)

    def test_docs_only_change_is_out_of_scope(self):
        result = self._run(["docs/demo-setup-fa.md"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_compatibility_automation_counts_as_the_lane_side(self):
        """A gate that is rewritten together with production proves nothing.

        The companion-compat scripts, the scoped-demo tests and the lane
        workflows are executable evidence about the same boundary, so they are
        classified rather than silently ignored.
        """
        for path in (
            ".github/scripts/companion-compat/evaluate.py",
            "tests/companion-compat/test_compatibility_gate.py",
            "tests/scoped-demo/test_scoped_safety_regressions.py",
            "tests/disposable-site/test_x.py",
        ):
            result = self._run([path, "clinic-practice-management/src/Bootstrap/App.php"])
            self.assertEqual(result.returncode, 1, path + ": " + result.stdout)
            self.assertIn("mixed change", result.stdout)

    def test_compatibility_automation_alone_is_allowed(self):
        result = self._run([
            ".github/scripts/companion-compat/evaluate.py",
            "tests/scoped-demo/test_scoped_safety_regressions.py",
        ])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("compatibility files: 2", result.stdout)

    def test_lane_workflows_are_classified(self):
        result = self._run([".github/workflows/scoped-demo.yml", ".github/workflows/companion-compat.yml"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("lane files: 2", result.stdout)

    def test_scoped_demo_documentation_is_classified(self):
        result = self._run(["docs/scoped-demo/ownership-map.md"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("documentation files: 1", result.stdout)

    def test_unclassified_lane_file_fails_closed(self):
        result = self._run(["tools/some-new-lane-tool.sh"])
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("unclassified lane file", result.stdout)
        mixed = self._run([
            "tools/some-new-lane-tool.sh",
            "clinic-practice-management/src/Bootstrap/App.php",
        ])
        self.assertEqual(mixed.returncode, 1, mixed.stdout + mixed.stderr)

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


class FixtureEnvironmentContractTests(unittest.TestCase):
    def test_doctor_portal_internal_finance_handoff_is_restored_without_public_projection(self):
        doctor_fixture = (
            ROOT / "clinic-practice-management" / "tests" / "fixtures" / "pilot-doctor-portal-fixture.php"
        ).read_text(encoding="utf-8")
        finance_fixture = (
            ROOT / "clinic-practice-management" / "tests" / "fixtures" / "pilot-finance-board-fixture.php"
        ).read_text(encoding="utf-8")
        finance_browser = (
            ROOT / "clinic-practice-management" / "bin" / "pilot-finance-board.py"
        ).read_text(encoding="utf-8")
        pilot = workflow("pilot-gate.yml")

        self.assertRegex(
            doctor_fixture,
            r"\$financeContext\s*=\s*implode\(\s*'\|'\s*,\s*\[\s*\$url\s*,\s*"
            r"\(string\)\s*\$oneClinic\s*,\s*\(string\)\s*\$oneLoc\s*\]\s*\)\s*;",
        )
        self.assertIn("'DOCTOR_PORTAL_PUBLIC=' . $financeContext", doctor_fixture)
        self.assertIn("getenv('DOCTOR_PORTAL_PUBLIC')", finance_fixture)
        self.assertIn("$clinicId = (int) $portalFixture[1]", finance_fixture)
        self.assertIn("$locationId = (int) $portalFixture[2]", finance_fixture)

        doctor_setup = pilot.split("- name: Doctor Portal fixture", 1)[1].split("\n      - name:", 1)[0]
        self.assertIn('cat /tmp/doctor-portal.env >> "$GITHUB_ENV"', doctor_setup)
        self.assertIn("grep '^DOCTOR_PORTAL_FIXTURE_OK '", doctor_setup)
        self.assertNotIn("log_tail=", doctor_setup)
        self.assertNotIn("tail -c", doctor_setup)
        self.assertNotIn("DOCTOR_PORTAL_PUBLIC", doctor_setup)

        finance_setup = pilot.split("- name: Phase 12 Finance board fixture", 1)[1].split("\n      - name:", 1)[0]
        self.assertIn('cat /tmp/finance-board.env >> "$GITHUB_ENV"', finance_setup)
        self.assertIn("Finance board fixture environment loaded; values withheld.", finance_setup)
        self.assertNotIn("grep '^FINANCE_BOARD_URL='", finance_setup)
        self.assertIn("fixture values and raw diagnostics withheld", finance_setup)
        self.assertNotIn("log_tail=", finance_setup)
        self.assertNotIn("tail -c", finance_setup)

        finance_browser_step = pilot.split("- name: Phase 12 Finance board — real browser", 1)[1].split("\n      - name:", 1)[0]
        self.assertIn("dynamic diagnostics withheld", finance_browser_step)
        self.assertNotIn("fail_lines=", finance_browser_step)

        evidence = pilot.split("- name: Post Doctor Portal evidence to PR", 1)[1].split("\n      - name:", 1)[0]
        self.assertNotIn("DOCTOR_PORTAL_PUBLIC", evidence)
        self.assertNotIn("doctor-portal.env", evidence)
        self.assertIn("DOCTOR_PORTAL_FIXTURE_OK", evidence)

        self.assertNotIn("FINANCE_BOARD_ACCOUNTANT_PUBLIC", finance_fixture)
        self.assertIn("FINANCE_BOARD_ACCOUNTANT_FIXTURE_OK", finance_fixture)
        self.assertIn("FINANCE_BOARD_FIXTURE_OK", finance_fixture)
        self.assertNotIn('print("INFO browser error: " + error', finance_browser)
        self.assertNotIn('print("INFO request failed: " + url', finance_browser)
        self.assertIn("browser diagnostics:", finance_browser)


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


class CompanionCompatibilityGateContractTests(unittest.TestCase):
    """The demo-coverage gate must be a REQUIRED, fail-closed check.

    The defect these tests pin: the `--gate all` step carried
    `continue-on-error: true` and a shell `|| true`, so a declared demo GAP - the
    exact state `compatibility_complete=false` describes - left the required pull
    request check green. A release could then ship from an incomplete companion
    while every check looked healthy.
    """

    FILE = "companion-compat.yml"

    def step_containing(self, text: str, needle: str) -> str:
        """Return the YAML step block that contains `needle`."""
        lines = text.splitlines()
        start = None
        for index, line in enumerate(lines):
            if needle in line:
                start = index
                break
        self.assertIsNotNone(start, f"no step contains {needle!r}")
        begin = start
        while begin > 0 and not lines[begin].lstrip().startswith("- name:"):
            begin -= 1
        end = start + 1
        while end < len(lines):
            stripped = lines[end]
            if stripped.lstrip().startswith("- name:") or (
                stripped.strip() and not stripped.startswith(" " * 7) and not stripped.startswith(" " * 6)
            ):
                break
            end += 1
        return "\n".join(lines[begin:end])

    def test_demo_coverage_step_cannot_be_ignored(self):
        text = workflow(self.FILE)
        self.assertNotRegex(
            text,
            r"(?m)^\s*continue-on-error:\s*true\s*$",
            "a required gate must not be allowed to fail quietly",
        )
        block = self.step_containing(text, "--gate all")
        self.assertNotIn("|| true", block, block)
        self.assertIn("status=$?", block, block)  # the code is captured, then re-raised below
        self.assertIn('if [ "$status" -ne 0 ]', block, block)
        self.assertIn('exit "$status"', block, block)
        self.assertIn("::error title=COMPANION_COMPAT", block, block)

    def test_gate_all_step_rechecks_the_report_itself(self):
        """Defence in depth: even a regressed exit code cannot turn a GAP green."""
        block = self.step_containing(workflow(self.FILE), "--gate all")
        self.assertIn("report['counts']['blocker']", block, block)
        self.assertIn("report['counts']['gap']", block, block)
        self.assertIn("compatibility_complete", block, block)
        self.assertIn("sys.exit(1)", block, block)

    def test_manifest_sync_check_is_not_swallowed(self):
        text = workflow(self.FILE)
        block = self.step_containing(text, "generate.py --root . --check")
        self.assertNotIn("|| true", block, block)
        self.assertIn("--check", block, block)

    def test_the_gate_command_fails_on_an_injected_gap(self):
        """Executable negative contract: the gate the workflow runs fails on a GAP.

        A temporary root is assembled by symlinking the real sources and copying
        the manifest with one demo-required feature stripped of its journeys. The
        same evaluator the workflow calls must then exit non-zero for `--gate all`
        while `--gate compatibility` still passes - so the failure really is the
        demo-coverage gap, not a broken tree.
        """
        evaluator = ROOT / ".github" / "scripts" / "companion-compat" / "evaluate.py"
        manifest_path = ROOT / "tools" / "disposable-demo-installer" / "compatibility.json"
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        feature = next(f for f in manifest["features"] if f.get("journeys"))
        stripped = dict(feature)
        stripped["journeys"] = []
        manifest["features"] = [stripped if f is feature else f for f in manifest["features"]]

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "repo"
            root.mkdir()
            # The heavy production tree is only READ, so it is symlinked; the
            # companion tree is copied so its manifest can be mutated.
            for name in ("clinic-practice-management", ".github", "tests", "docs"):
                target = ROOT / name
                if target.exists():
                    os.symlink(target, root / name)
            shutil.copytree(ROOT / "tools", root / "tools", symlinks=False)
            (root / "tools" / "disposable-demo-installer" / "compatibility.json").write_text(
                json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
            )

            report = Path(tmp) / "report.json"
            all_result = subprocess.run(
                [sys.executable, str(evaluator), "--root", str(root), "--gate", "all", "--report", str(report)],
                capture_output=True, text=True, timeout=300,
            )
            self.assertEqual(1, all_result.returncode, all_result.stdout + all_result.stderr)
            self.assertRegex(all_result.stdout, r"compatibility_complete: (false|False)")
            data = json.loads(report.read_text(encoding="utf-8"))
            self.assertFalse(data["compatibility_complete"])
            self.assertGreaterEqual(data["counts"]["gap"], 1)
            compat = subprocess.run(
                [sys.executable, str(evaluator), "--root", str(root), "--gate", "compatibility", "--quiet"],
                capture_output=True, text=True, timeout=300,
            )
            self.assertEqual(0, compat.returncode, compat.stdout + compat.stderr)

    def test_auto_sync_opens_a_reviewable_pr_and_never_merges(self):
        text = workflow("companion-sync.yml")
        self.assertIn("gh pr create", text)
        for forbidden in ("gh pr merge", "--auto", "--admin", "git tag", "gh release create"):
            self.assertNotIn(forbidden, text, forbidden)
        self.assertIn("not** auto-merged", text)
        events = text.split("on:\n", 1)[1].split("\npermissions:", 1)[0]
        self.assertNotIn("pull_request_target", events)
        self.assertRegex(events, r"(?m)^  push:\n    branches: \[main\]$")


if __name__ == "__main__":
    unittest.main()
