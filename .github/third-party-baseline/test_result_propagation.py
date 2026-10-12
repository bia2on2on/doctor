#!/usr/bin/env python3
"""Deterministic self-tests for the third-party baseline RESULT-PROPAGATION contract.

TEST-ONLY CI tooling.  No WordPress, no MySQL, no Apache, no network, no browser:
every fixture here is a hand-written evidence store and the assertions are about
exit status and derived state, so this suite is fast, hermetic and reproducible.

WHAT IT PROVES (the defect this suite exists for)
    A material post-activation FAIL used to be recorded in the subject artifact
    and mirrored into an ``::error::`` annotation — and an annotation never fails
    a step — so the subject job concluded ``success`` and a green workflow did not
    prove that every subject passed.  These tests pin the corrected contract:

      * clean subject PASS            -> green aggregate, state PASS
      * legitimate PARTIAL            -> green, state PARTIAL, never rendered PASS
      * material post-activation FAIL -> subject job red (non-zero exit)
      * missing required evidence     -> subject job red (non-zero exit)
      * infrastructure provisioning
        failure (class C)             -> subject job red, still class C, never
                                         mislabelled as a compatibility failure
      * aggregate propagation         -> the summary job goes red on any subject
                                         FAIL or on malformed/missing evidence,
                                         while still publishing the artifacts
      * workflow wiring               -> the exit status, not the annotation, is
                                         what the workflow acts on

CLI-level, not in-process: the contract is an exit status, so the tests run the
real commands the workflow runs and read the real artifacts they write.

Run:  python3 .github/third-party-baseline/test_result_propagation.py
"""

from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
PROBE = HERE / "probe.py"
REPORT = HERE / "report.py"
WORKFLOW = HERE.parent / "workflows" / "third-party-baseline.yml"


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


probe = load_module("tpb_probe", PROBE)
report = load_module("tpb_report", REPORT)

ENV = {
    **os.environ,
    "GITHUB_SERVER_URL": "https://github.com",
    "GITHUB_REPOSITORY": "bia2on2on/doctor",
    "GITHUB_RUN_ID": "0",
    "GITHUB_STEP_SUMMARY": "",
}


def check(name: str, status: str, klass: str, material: bool = False,
          detail: str = "") -> dict:
    """One recorded evidence check, in exactly the shape ``Store.record`` writes."""
    return {"name": name, "status": status, "klass": klass, "material": material,
            "phase": "post", "detail": detail, "expected": None, "observed": None}


PASS_CHECK = check("front_page_http_200_post", probe.PASS, probe.PRODUCT)
ENV_PASS_CHECK = check("wp_core_version_post", probe.PASS, probe.ENVIRONMENT)


class PropagationCase(unittest.TestCase):
    """Shared fixture helpers; every test is hermetic under its own temp dir."""

    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory(prefix="tpb-propagation-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)

    def store(self, subject_id: str, checks: list, facts: dict | None = None,
              kind: str = "subject") -> Path:
        path = self.root / subject_id / "evidence.json"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps({
            "subject": {"id": subject_id, "kind": kind, "slug": subject_id,
                        "version_requested": "0.0.0"},
            "facts": {"phase_post_executed": True, **(facts or {})},
            "checks": checks,
        }, indent=2) + "\n", encoding="utf-8")
        return path

    def finalize(self, subject_id: str, store: Path, extra=()):
        """Run the real CLI exactly as the workflow does; return (exit, artifact)."""
        out = self.root / subject_id / "subject-result.json"
        completed = subprocess.run(
            [sys.executable, str(PROBE), "finalize", "--store", str(store),
             "--out", str(out), "--subject-id", subject_id, "--deps", "-", *extra],
            capture_output=True, text=True, env=ENV, check=False)
        result = json.loads(out.read_text(encoding="utf-8")) if out.exists() else {}
        return completed, result

    def aggregate(self, subject_dirs: list, extra=()):
        """Run the real aggregate CLI over real ``finalize`` output."""
        results_dir, out_dir = self.root / "results", self.root / "summary"
        results_dir.mkdir(exist_ok=True)
        for subject_id in subject_dirs:
            target = results_dir / subject_id
            target.mkdir(exist_ok=True)
            source = self.root / subject_id / "subject-result.json"
            (target / "subject-result.json").write_text(
                source.read_text(encoding="utf-8"), encoding="utf-8")
        completed = subprocess.run(
            [sys.executable, str(REPORT), "--results-dir", str(results_dir),
             "--out-dir", str(out_dir), "--lane", "A", "--head-sha", "0" * 40,
             "--run-url", "https://example.invalid/run/0", *extra],
            capture_output=True, text=True, env=ENV, check=False)
        summary = (out_dir / "summary.json")
        markdown = (out_dir / "summary.md")
        return (completed,
                json.loads(summary.read_text(encoding="utf-8")) if summary.exists() else {},
                markdown.read_text(encoding="utf-8") if markdown.exists() else "")


class SubjectStateTests(PropagationCase):

    def test_clean_subject_pass_is_green(self):
        store = self.store("clean-subject", [PASS_CHECK, ENV_PASS_CHECK,
                                             check("cpms_tables_absent_post", probe.PASS, probe.HARNESS)])
        completed, result = self.finalize("clean-subject", store)
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertEqual(result["state"], probe.PASS)
        self.assertIs(result["ci_gate"]["blocking"], False)
        self.assertEqual(result["ci_gate"]["non_blocking_findings"], [])

    def test_legitimate_partial_is_non_blocking_and_never_pass(self):
        checks = [
            PASS_CHECK,
            # Recorded-only findings: exactly what a real PARTIAL subject carries.
            check("php_warnings_after_activation", probe.FAIL, probe.PRODUCT,
                  detail="non-fatal PHP diagnostics recorded; never fails the subject alone"),
            check("feature::server-level page caching (LSCache)", probe.UNAVAILABLE,
                  probe.UNAVAILABLE_KLASS,
                  detail="needs a LiteSpeed web server; this lane runs Apache/mod_php"),
            check("mysql_auth_mechanism_post", probe.UNEXECUTED, probe.UNEXECUTED_KLASS,
                  detail="the running server did not expose the auth plugin"),
        ]
        completed, result = self.finalize("partial-subject", self.store("partial-subject", checks))
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertEqual(result["state"], "PARTIAL")
        self.assertNotEqual(result["state"], probe.PASS)
        self.assertIs(result["ci_gate"]["blocking"], False)
        self.assertIn("php_warnings_after_activation=FAIL", result["ci_gate"]["non_blocking_findings"])
        self.assertIn("mysql_auth_mechanism_post=UNEXECUTED",
                      result["ci_gate"]["non_blocking_findings"])
        self.assertIn("STATE=PARTIAL", completed.stdout)

    def test_feature_unavailable_alone_is_partial_not_pass_and_not_fail(self):
        checks = [PASS_CHECK, check("feature::server-level page caching (LSCache)",
                                    probe.UNAVAILABLE, probe.UNAVAILABLE_KLASS)]
        completed, result = self.finalize("unavailable-feature", self.store("unavail", checks))
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertEqual(result["state"], "PARTIAL")
        self.assertEqual(result["taxonomy"], [])

    def test_material_post_activation_fail_fails_the_subject_job(self):
        checks = [PASS_CHECK, ENV_PASS_CHECK,
                  check("front_page_http_200_post", probe.FAIL, probe.PRODUCT,
                        material=True, detail="the front page did not answer HTTP 200")]
        completed, result = self.finalize("material-fail", self.store("material-fail", checks))
        self.assertNotEqual(completed.returncode, 0,
                            "a material post-activation FAIL must fail the subject job")
        self.assertEqual(result["state"], probe.FAIL)
        self.assertIs(result["ci_gate"]["blocking"], True)
        self.assertEqual(result["taxonomy"], [probe.THIRD_PARTY_BASELINE])
        self.assertIn("STATE=FAIL", completed.stdout)

    def test_missing_required_evidence_fails_closed(self):
        # The post-activation phase never ran: the subject carries no compatibility
        # evidence at all. That must never be green.
        store = self.store("no-post-evidence", [PASS_CHECK],
                           facts={"phase_post_executed": False})
        completed, result = self.finalize("no-post-evidence", store)
        self.assertNotEqual(completed.returncode, 0)
        self.assertEqual(result["state"], probe.FAIL)
        gap = [c for c in result["checks"] if c["name"] == "post_activation_probes_executed"]
        self.assertEqual(len(gap), 1)
        self.assertEqual(gap[0]["status"], probe.FAIL)
        self.assertIs(gap[0]["material"], True)

    def test_infrastructure_provisioning_failure_blocks_and_stays_class_C(self):
        checks = [PASS_CHECK,
                  check("web_php_version_post", probe.FAIL, probe.ENVIRONMENT, material=True,
                        detail="the lane PHP did not match the pin")]
        completed, result = self.finalize("infra-fail", self.store("infra-fail", checks))
        self.assertNotEqual(completed.returncode, 0)
        self.assertEqual(result["state"], probe.FAIL)
        self.assertEqual(result["taxonomy"], ["C (infrastructure/environment)"])
        self.assertNotIn(probe.THIRD_PARTY_BASELINE, result["taxonomy"])

    def test_harness_fixture_failure_blocks_as_class_D(self):
        checks = [check("plugin_header_metadata_read", probe.FAIL, probe.HARNESS, material=True)]
        completed, result = self.finalize("harness-fail", self.store("harness-fail", checks))
        self.assertNotEqual(completed.returncode, 0)
        self.assertEqual(result["taxonomy"], ["D (test/test-infrastructure/fixture defect)"])

    def test_locale_baseline_product_failure_is_never_called_third_party(self):
        checks = [check("fa_front_page_locale_and_rtl", probe.FAIL, probe.PRODUCT, material=True)]
        store = self.store("locale-baseline-fa-ir", checks, kind="locale-baseline")
        completed, result = self.finalize("locale-baseline-fa-ir", store)
        self.assertNotEqual(completed.returncode, 0)
        self.assertEqual(result["taxonomy"], [probe.LOCALE_BASELINE])

    def test_not_run_is_neither_pass_nor_failure(self):
        store = self.store("wp-rocket", [], facts={})
        completed, result = self.finalize(
            "wp-rocket", store, extra=["--not-run", "OWNER-SUPPLIED LICENSED PACKAGE REQUIRED"])
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertEqual(result["state"], probe.NOT_RUN)
        self.assertNotEqual(result["state"], probe.PASS)
        self.assertIs(result["ci_gate"]["blocking"], False)


class AggregatePropagationTests(PropagationCase):

    def build(self, subject_id: str, checks: list, facts: dict | None = None, kind="subject"):
        store = self.store(subject_id, checks, facts=facts, kind=kind)
        completed, result = self.finalize(subject_id, store)
        self.assertTrue(result, f"{subject_id} produced no artifact: {completed.stderr}")

    def test_aggregate_is_green_without_a_material_failure(self):
        self.build("clean-subject", [PASS_CHECK, ENV_PASS_CHECK])
        self.build("partial-subject", [PASS_CHECK,
                                       check("php_warnings_after_activation", probe.FAIL,
                                             probe.PRODUCT, detail="recorded only")])
        completed, summary, markdown = self.aggregate(["clean-subject", "partial-subject"])
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertEqual(summary["totals"], {"FAIL": 0, "PARTIAL": 1, "PASS": 1, "NOT RUN": 0})
        self.assertIn("- gate: **no material subject FAIL.", markdown)
        self.assertIn("PARTIAL — recorded, non-blocking, never rendered as PASS", markdown)
        # PARTIAL is rendered as PARTIAL, never as a PASS row.
        partial_row = [line for line in markdown.splitlines() if "| `partial-subject` |" in line]
        self.assertEqual(len(partial_row), 1)
        self.assertTrue(partial_row[0].startswith("| PARTIAL |"), partial_row[0])

    def test_aggregate_goes_red_on_a_material_subject_fail(self):
        self.build("clean-subject", [PASS_CHECK])
        self.build("material-fail", [check("front_page_http_200_post", probe.FAIL,
                                           probe.PRODUCT, material=True)])
        completed, summary, markdown = self.aggregate(["clean-subject", "material-fail"])
        self.assertNotEqual(completed.returncode, 0,
                            "the aggregate must go red when a subject says FAIL")
        self.assertEqual(summary["totals"]["FAIL"], 1)
        self.assertIn("| FAIL | `material-fail` |", markdown)
        self.assertIn("- gate: **RED", markdown)
        self.assertIn("::error::subject material-fail STATE=FAIL", completed.stdout)

    def test_aggregate_goes_red_on_missing_or_malformed_evidence(self):
        self.build("clean-subject", [PASS_CHECK])
        broken = self.root / "results" / "broken-subject"
        broken.mkdir(parents=True, exist_ok=True)
        (broken / "subject-result.json").write_text("{not json", encoding="utf-8")
        completed, _summary, _markdown = self.aggregate(["clean-subject"])
        self.assertNotEqual(completed.returncode, 0)
        self.assertIn("unreadable subject result", completed.stdout)

    def test_aggregate_refuses_an_empty_campaign(self):
        completed, _summary, _markdown = self.aggregate([])
        self.assertNotEqual(completed.returncode, 0)
        self.assertIn("refusing to publish an empty summary", completed.stdout)

    def test_aggregate_publishes_evidence_even_when_red(self):
        self.build("material-fail", [check("front_page_http_200_post", probe.FAIL,
                                           probe.PRODUCT, material=True)])
        completed, summary, markdown = self.aggregate(["material-fail"])
        self.assertNotEqual(completed.returncode, 0)
        self.assertTrue(summary, "summary.json must still be written for a red campaign")
        self.assertIn("`material-fail`", markdown)


class WorkflowWiringTests(unittest.TestCase):
    """Structural guard: the workflow must act on the exit status, not an annotation.

    Textual on purpose — this tooling has no YAML dependency (PyYAML is not part
    of the harness), and the wiring being asserted is a handful of explicit
    command lines. A missing line here means the gate silently lost its
    propagation again, which is precisely the regression these tests exist for.
    """

    def setUp(self) -> None:
        self.text = WORKFLOW.read_text(encoding="utf-8")
        self.steps = {}
        current = None
        for line in self.text.splitlines():
            if line.strip().startswith("- name: "):
                current = line.strip()[len("- name: "):]
                self.steps[current] = []
            elif current is not None:
                self.steps[current].append(line)

    def step(self, prefix: str) -> str:
        body = next((body for name, body in self.steps.items() if name.startswith(prefix)), None)
        self.assertIsNotNone(body, f"workflow step missing: {prefix!r}")
        return "\n".join(body)

    def test_subject_job_re_raises_the_finalize_exit_code(self):
        body = self.step("Finalize the machine-readable subject result")
        self.assertIn("probe.py finalize", body)
        self.assertIn('exit "$rc"', body,
                      "the finalize step must re-raise the probe exit code; an "
                      "annotation alone never fails a step")

    def test_aggregate_job_is_fail_closed_on_the_matrix_result(self):
        body = self.step("Assert every subject leg concluded success")
        self.assertIn("needs.subject-baseline.result", body)
        self.assertIn("exit 1", body)

    def test_aggregate_job_re_raises_the_report_exit_code(self):
        body = self.step("Build the human-readable summary")
        self.assertIn("report.py", body)
        self.assertIn('exit "$rc"', body)

    def test_summary_artifact_is_published_even_when_the_aggregate_is_red(self):
        body = self.step("Summary artifact")
        self.assertIn("if: always()", body)

    def test_this_suite_is_run_by_the_workflow(self):
        self.assertIn("test_result_propagation.py", self.text)


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromModule(sys.modules[__name__])
    outcome = unittest.TextTestRunner(verbosity=2).run(suite)
    failed = len(outcome.failures) + len(outcome.errors) + len(outcome.unexpectedSuccesses)
    total = outcome.testsRun
    print(f"[test_result_propagation] self-tests "
          f"{'PASS' if not failed else 'FAILED'}: {total - failed}/{total}")
    sys.exit(1 if failed else 0)
