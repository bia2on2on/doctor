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
              kind: str = "subject", subject: dict | None = None) -> Path:
        path = self.root / subject_id / "evidence.json"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps({
            "subject": {"id": subject_id, "kind": kind, "slug": subject_id,
                        "version_requested": "0.0.0", **(subject or {})},
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

    def deliver(self, subject_id: str, directory: str | None = None) -> Path:
        """Copy one produced artifact into the results tree the way the job does.

        ``directory`` overrides the artifact directory name so identity
        consistency cases can be exercised (the job's own layout is
        ``tpb-<subject-id>``).
        """
        target = self.root / "results" / (directory or subject_id)
        target.mkdir(parents=True, exist_ok=True)
        (target / "subject-result.json").write_text(
            (self.root / subject_id / "subject-result.json").read_text(encoding="utf-8"),
            encoding="utf-8")
        return target / "subject-result.json"

    def write_matrix(self, subject_ids: list) -> Path:
        """The authoritative expectation, in the shape the real workflow uses."""
        path = self.root / "matrix.yml"
        body = "".join(f"          - id: {subject_id}\n            name: {subject_id}\n"
                       for subject_id in subject_ids)
        path.write_text("name: fixture\non:\n  push:\n    branches: [main]\n"
                        "jobs:\n  subject-baseline:\n"
                        "    strategy:\n      matrix:\n        include:\n" + body +
                        "    runs-on: ubuntu-latest\n", encoding="utf-8")
        return path

    def report_run(self, expected: list | None = None, extra=()):
        """Run the real aggregate CLI over whatever is in the results tree.

        ``expected`` is the matrix expectation; by default it is whatever was
        delivered, so tests that are not about reconciliation stay focused.
        """
        results_dir, out_dir = self.root / "results", self.root / "summary"
        results_dir.mkdir(exist_ok=True)
        if expected is None:
            expected = sorted(p.name for p in results_dir.iterdir() if p.is_dir())
        matrix = self.write_matrix(list(expected))
        completed = subprocess.run(
            [sys.executable, str(REPORT), "--results-dir", str(results_dir),
             "--out-dir", str(out_dir), "--expect-matrix", str(matrix),
             "--lane", "A", "--head-sha", "0" * 40,
             "--run-url", "https://example.invalid/run/0", *extra],
            capture_output=True, text=True, env=ENV, check=False)
        summary = (out_dir / "summary.json")
        markdown = (out_dir / "summary.md")
        return (completed,
                json.loads(summary.read_text(encoding="utf-8")) if summary.exists() else {},
                markdown.read_text(encoding="utf-8") if markdown.exists() else "")

    def aggregate(self, subject_dirs: list, expected: list | None = None, extra=()):
        """Deliver artifacts, then run the real aggregate CLI over them."""
        for subject_id in subject_dirs:
            self.deliver(subject_id)
        return self.report_run(expected, extra)


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


class DiagnosticExceptionTests(PropagationCase):
    """The one owner-approved diagnostic exception: exact signature or nothing.

    The historical signature is the one recorded independently in the published
    per-subject evidence of the `main` merge SHA `b925f873…`: slug
    `persian-woocommerce`, version `10.0.5`, dependency `woocommerce=11.2.0`, and
    these seven material product-class post-activation check identifiers (plus one
    recorded-only, non-material PHP-warning finding). The identifiers — not any
    message text and not a failure count — are what the policy pins.
    """

    MATERIAL_FAILURES = (
        "admin_ajax_wp_layer_reachable_post",
        "admin_login_session_post",
        "front_page_http_200_post",
        "no_redirect_loop_front_post",
        "rest_index_reachable_post",
        "wp_admin_reachable_post",
        "wp_cli_bootstrap_post",
    )
    SUBJECT = {"slug": "persian-woocommerce", "version_requested": "10.0.5",
               "dependencies": ["woocommerce=11.2.0"]}
    # Exactly what cmd_browser writes on a product leg: --expect-dir is empty
    # there, so `document` stays {} and that is legitimate, not malformed.
    FACTS = {"browser_post": {
        "nav": {"front_status": 200, "front_url": "http://localhost:8080/",
                "front_redirects": []},
        "login": {"ok": True, "final_url": "http://localhost:8080/wp-admin/", "error": ""},
        "rest_status": 200,
        "admin_ajax": {"status": 200, "body": "0", "reached_wp_layer": True},
        "document": {},
    }}

    def historical_checks(self, material=None, extra=()):
        material = self.MATERIAL_FAILURES if material is None else material
        checks = [check(name, probe.FAIL, probe.PRODUCT, material=True,
                        detail="recorded historical failure")
                  for name in material]
        # Read through the policy so the fixture cannot pin a different contract
        # than the harness; the explicit policy test below pins the policy itself.
        policy = getattr(probe, "DIAGNOSTIC_EXCEPTION", {"required_checks": ()})
        checks += [check(name, probe.PASS, probe.PRODUCT)
                   for name in sorted(policy.get("required_checks", ()))]
        # cmd_browser derives these two material verdicts unconditionally from the
        # facts it records, so authentic browser evidence always carries them. A
        # fixture without them could not be a consistent cmd_browser execution.
        checks += [check("browser_admin_login_session", probe.PASS, probe.PRODUCT,
                         material=True),
                   check("browser_admin_ajax_wp_layer", probe.PASS, probe.PRODUCT,
                         material=True)]
        checks.append(check("php_warnings_after_activation", probe.FAIL, probe.PRODUCT,
                            detail="non-fatal PHP diagnostics recorded; recorded only"))
        return checks + list(extra)

    def run_case(self, checks=None, subject=None, facts=None, kind="subject"):
        store = self.store("persian-woocommerce",
                           self.historical_checks() if checks is None else checks,
                           facts=dict(self.FACTS) if facts is None else facts,
                           kind=kind, subject=dict(self.SUBJECT) if subject is None else subject)
        return self.finalize("persian-woocommerce", store)

    def assert_blocking(self, completed, result, why=""):
        self.assertNotEqual(completed.returncode, 0, f"must block: {why}")
        self.assertEqual(result["state"], probe.FAIL, "raw state must stay FAIL")
        self.assertIs(result["ci_gate"]["blocking"], True, why)
        self.assertIs(result["ci_gate"]["diagnostic_exception"]["applies"], False, why)

    def test_policy_pins_identity_identifiers_and_probe_evidence(self):
        policy = probe.DIAGNOSTIC_EXCEPTION
        self.assertEqual(policy["slug"], "persian-woocommerce")
        self.assertEqual(policy["version"], "10.0.5")
        self.assertEqual(tuple(policy["dependencies"]), ("woocommerce=11.2.0",))
        self.assertEqual(set(policy["material_failures"]), set(self.MATERIAL_FAILURES))
        self.assertEqual(tuple(policy["required_facts"]),
                         ("phase_post_executed", "browser_post"))
        self.assertEqual(set(policy["required_checks"]), {
            "php_web_version_exact_post", "wp_core_version_exact_post",
            "timezone_and_locale_post", "administrator_login_capability_post",
            "admin_ajax_negative_control_post", "cpms_tables_absent_post",
            "cpms_options_absent_post", "browser_front_page_render",
            "browser_rest_index", "browser_no_uncaught_page_errors"})

    # -- the accepted signature --------------------------------------------- #
    def test_exact_historical_signature_is_diagnostic_only_not_blocking(self):
        completed, result = self.run_case()
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        # Raw result preserved: state, counts and taxonomy are untouched.
        self.assertEqual(result["state"], probe.FAIL)
        self.assertNotEqual(result["state"], probe.PASS)
        # 7 accepted material failures + the recorded-only PHP-warning finding,
        # plus the two material browser verdicts cmd_browser always derives from
        # its own facts (both PASS in the accepted signature).
        self.assertEqual(result["counts"]["FAIL"], 8)
        self.assertEqual(result["counts"]["PASS"], 12)
        self.assertEqual(result["taxonomy"], [probe.THIRD_PARTY_BASELINE])
        self.assertIs(result["ci_gate"]["blocking"], False)
        self.assertEqual(result["ci_gate"]["disposition"], "diagnostic-only-nonblocking-fail")
        self.assertIs(result["ci_gate"]["diagnostic_exception"]["applies"], True)
        self.assertIn("STATE=FAIL", completed.stdout)
        self.assertIn("NOT a compatibility PASS", completed.stdout)

    # -- every way the signature can differ must block ----------------------- #
    def test_same_slug_other_version_blocks(self):
        completed, result = self.run_case(subject={**self.SUBJECT, "version_requested": "10.0.6"})
        self.assert_blocking(completed, result, "another version")

    def test_other_slug_same_version_and_signature_blocks(self):
        completed, result = self.run_case(subject={**self.SUBJECT, "slug": "wp-parsidate"})
        self.assert_blocking(completed, result, "another plugin")

    def test_changed_dependency_identity_blocks(self):
        completed, result = self.run_case(
            subject={**self.SUBJECT, "dependencies": ["woocommerce=11.3.0"]})
        self.assert_blocking(completed, result, "another dependency version")

    def test_incomplete_accepted_signature_blocks(self):
        completed, result = self.run_case(
            checks=self.historical_checks(material=self.MATERIAL_FAILURES[:-1]))
        self.assert_blocking(completed, result, "one accepted failure is missing")

    def test_additional_material_failure_blocks(self):
        extra = check("browser_front_page_render", probe.FAIL, probe.PRODUCT, material=True)
        completed, result = self.run_case(checks=self.historical_checks(extra=[extra]))
        self.assert_blocking(completed, result, "an additional material failure")

    def test_provisioning_failure_c_blocks(self):
        extra = check("php_web_version_exact_post", probe.FAIL, probe.ENVIRONMENT, material=True)
        completed, result = self.run_case(checks=self.historical_checks(extra=[extra]))
        self.assert_blocking(completed, result, "an environment failure")
        self.assertIn("C (infrastructure/environment)", result["taxonomy"])

    def test_harness_fixture_failure_d_blocks(self):
        extra = check("plugin_header_metadata_read", probe.FAIL, probe.HARNESS, material=True)
        completed, result = self.run_case(checks=self.historical_checks(extra=[extra]))
        self.assert_blocking(completed, result, "a harness/fixture failure")
        self.assertIn("D (test/test-infrastructure/fixture defect)", result["taxonomy"])

    def test_missing_post_activation_phase_blocks(self):
        # The real harness records nothing when the phase never ran; an explicit
        # False is the same evidence and is what the store helper can express.
        completed, result = self.run_case(
            facts={"phase_post_executed": False, "browser_post": {"nav": {}}})
        self.assert_blocking(completed, result, "the post phase never ran")

    def test_missing_browser_probe_evidence_blocks(self):
        completed, result = self.run_case(facts={})
        self.assert_blocking(completed, result,
                             "no positive evidence that the browser probe executed")

    def test_missing_required_probe_verdict_blocks(self):
        checks = [c for c in self.historical_checks() if c["name"] != "browser_rest_index"]
        completed, result = self.run_case(checks=checks)
        self.assert_blocking(completed, result, "a required probe verdict is absent")

    def test_reproduced_failure_names_without_probe_evidence_block(self):
        # Identical failure identifiers, identical version, but no probe-execution
        # evidence at all: the exception must not be reachable by name alone.
        completed, result = self.run_case(
            checks=[check(name, probe.FAIL, probe.PRODUCT, material=True)
                    for name in self.MATERIAL_FAILURES], facts={})
        self.assert_blocking(completed, result, "no probe-execution evidence")

    # -- BLOCKER 1: evidence must be substantive, not merely named ----------- #
    def test_empty_browser_evidence_blocks(self):
        completed, result = self.run_case(facts={"browser_post": {}})
        self.assert_blocking(completed, result, "an empty browser evidence object")

    def test_truncated_browser_evidence_blocks(self):
        completed, result = self.run_case(facts={"browser_post": {"nav": {}, "rest_status": 0}})
        self.assert_blocking(completed, result, "a truncated browser evidence object")

    def test_unexecuted_required_probe_blocks(self):
        checks = [row if row["name"] != "browser_rest_index" else
                  check("browser_rest_index", probe.UNEXECUTED, probe.UNEXECUTED_KLASS,
                        detail="the probe could not run")
                  for row in self.historical_checks()]
        completed, result = self.run_case(checks=checks)
        self.assert_blocking(completed, result, "an UNEXECUTED required probe")

    def test_required_probe_recorded_on_the_wrong_phase_blocks(self):
        checks = [row if row["name"] != "cpms_tables_absent_post" else
                  {**row, "phase": "pre"} for row in self.historical_checks()]
        completed, result = self.run_case(checks=checks)
        self.assert_blocking(completed, result, "a required verdict that is not post-activation")

    def test_missing_substantive_verdict_blocks(self):
        # The row exists by name but carries no verdict fields at all.
        checks = [row for row in self.historical_checks() if row["name"] != "cpms_options_absent_post"]
        checks.append({"name": "cpms_options_absent_post", "status": probe.PASS,
                       "phase": "post"})
        completed, result = self.run_case(checks=checks)
        self.assert_blocking(completed, result, "a malformed required verdict")

    def test_malformed_evidence_rows_block(self):
        checks = self.historical_checks()
        checks.append("not-a-row")
        checks.append({**check("browser_front_page_render", "SUCCESS", probe.PRODUCT),
                       "name": "browser_admin_ajax_wp_layer"})
        completed, result = self.run_case(checks=checks)
        self.assert_blocking(completed, result, "malformed rows / out-of-contract status")

    # -- BLOCKER 1 (values): the recorded browser record must be producer-shaped -- #
    def test_valid_producer_shaped_browser_evidence_is_accepted(self):
        completed, result = self.run_case()  # FACTS is the real product-leg record
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertIs(result["ci_gate"]["diagnostic_exception"]["applies"], True)

    def test_populated_document_record_from_the_locale_probe_is_accepted(self):
        # The locale leg's shape: the RTL branch of cmd_browser populates it.
        facts = {"browser_post": {**self.FACTS["browser_post"], "document": {
            "dir": "rtl", "lang": "fa-IR", "url": "http://localhost:8080/wp-admin/",
            "text": {"persian_char_count": 120}}}}
        completed, result = self.run_case(facts=facts)
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertIs(result["ci_gate"]["diagnostic_exception"]["applies"], True)

    def test_wrong_typed_nav_blocks(self):
        for nav in ("http://localhost:8080/", [], {"front_status": 200},
                    {"front_status": "200", "front_url": "http://x/", "front_redirects": []},
                    {"front_status": 200, "front_url": "", "front_redirects": []},
                    {"front_status": 200, "front_url": "http://x/", "front_redirects": "none"},
                    {"front_status": 0, "front_url": "http://x/", "front_redirects": []},
                    {"front_status": True, "front_url": "http://x/", "front_redirects": []}):
            with self.subTest(nav=nav):
                completed, result = self.run_case(
                    facts={"browser_post": {**self.FACTS["browser_post"], "nav": nav}})
                self.assert_blocking(completed, result, f"nav={nav!r}")

    def test_wrong_typed_rest_status_blocks(self):
        for rest_status in ("200", True, 0, None, 200.0):
            with self.subTest(rest_status=rest_status):
                completed, result = self.run_case(
                    facts={"browser_post": {**self.FACTS["browser_post"],
                                            "rest_status": rest_status}})
                self.assert_blocking(completed, result, f"rest_status={rest_status!r}")

    def test_malformed_login_evidence_blocks(self):
        # Wrong types and missing recorded fields only. `{"ok": False, ...}` is the
        # producer's own failure-branch shape and is deliberately NOT asserted as
        # malformed: no required verdict is derived from `login.ok`, and a browser
        # login that did not complete is a real observation, not corrupt evidence.
        cases = [{}, "ok", {"ok": True}, {"ok": "yes", "final_url": "http://x/", "error": ""},
                 {"ok": True, "final_url": 5, "error": ""},
                 {"ok": True, "final_url": "", "error": ""},
                 {"ok": True, "final_url": "http://x/", "error": 5},
                 {"ok": None, "final_url": "http://x/", "error": ""}]
        for login in cases:
            with self.subTest(login=login):
                completed, result = self.run_case(
                    facts={"browser_post": {**self.FACTS["browser_post"], "login": login}})
                self.assert_blocking(completed, result, f"login={login!r}")

    def test_malformed_admin_ajax_evidence_blocks(self):
        # Record shape only: the observed admin-ajax outcome is not asserted by any
        # required verdict, so `{"status": 0, "body": "", "reached_wp_layer": False}`
        # (the producer's own request-failure branch) must stay acceptable as evidence
        # that the probe ran, while truncated or mistyped records must not be.
        cases = [{}, "200", {"status": "200", "body": "0", "reached_wp_layer": True},
                 {"status": 200, "body": 0, "reached_wp_layer": True},
                 {"status": 200, "body": "0", "reached_wp_layer": "yes"},
                 {"status": True, "body": "0", "reached_wp_layer": True},
                 {"body": "0", "reached_wp_layer": True}]
        for ajax in cases:
            with self.subTest(admin_ajax=ajax):
                completed, result = self.run_case(
                    facts={"browser_post": {**self.FACTS["browser_post"], "admin_ajax": ajax}})
                self.assert_blocking(completed, result, f"admin_ajax={ajax!r}")

    def test_malformed_document_evidence_blocks(self):
        # `{"dir": "", "url": ...}` is deliberately NOT here: the producer records an
        # empty direction when the page declares none (and its error branch sets it),
        # so requiring a non-empty value would be an invented expectation.
        for document in ("rtl", [], {"dir": 1, "url": "http://x/"}, {"dir": "ltr"},
                         {"dir": "ltr", "lang": "en-US"},
                         {"dir": "ltr", "lang": "en-US", "url": ""}):
            with self.subTest(document=document):
                completed, result = self.run_case(
                    facts={"browser_post": {**self.FACTS["browser_post"],
                                            "document": document}})
                self.assert_blocking(completed, result, f"document={document!r}")

    # -- the browser facts and the verdicts derived from them must agree ------ #
    # cmd_browser derives `browser_admin_login_session` from `login.ok` and
    # `browser_admin_ajax_wp_layer` from `admin_ajax.reached_wp_layer`, always and
    # unconditionally, so a genuine evidence record carries both verdicts and they
    # cannot disagree with the facts. Accepting evidence that no cmd_browser run
    # could have produced is the defect these cases pin.
    def reasons(self, result):
        return result["ci_gate"]["diagnostic_exception"]["reasons"]

    def checks_without(self, *names):
        return [row for row in self.historical_checks() if row["name"] not in names]

    def checks_with(self, *replacement):
        """Replace rows by identifier, so no duplicate identifier is introduced."""
        replaced = {row["name"] for row in replacement}
        return ([row for row in self.historical_checks() if row["name"] not in replaced]
                + list(replacement))

    def failed_login_facts(self):
        return {"browser_post": {
            **self.FACTS["browser_post"],
            "login": {"ok": False, "final_url": "http://localhost:8080/wp-login.php",
                      "error": "the login form is absent from wp-login.php after activation"}}}

    def failed_admin_ajax_facts(self):
        return {"browser_post": {
            **self.FACTS["browser_post"],
            "admin_ajax": {"status": 0, "body": "", "reached_wp_layer": False}}}

    def test_consistent_login_success_fact_and_verdict_is_accepted(self):
        completed, result = self.run_case()
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertIs(result["ci_gate"]["diagnostic_exception"]["applies"], True)
        rows = {row["name"]: row["status"] for row in result["checks"]}
        self.assertEqual(rows["browser_admin_login_session"], probe.PASS)

    def test_contradictory_login_fact_and_verdict_blocks(self):
        # The recorded verdict says PASS while the fact it was derived from says the
        # session did not complete: no cmd_browser execution produces that pair.
        completed, result = self.run_case(facts=self.failed_login_facts())
        self.assert_blocking(completed, result, "login.ok False against a PASS verdict")
        self.assertTrue(any("contradicts the recorded browser_admin_login_session" in reason
                            for reason in self.reasons(result)))
        self.assertFalse(any("outside the accepted signature" in reason
                             for reason in self.reasons(result)),
                         "this blocks because the evidence is inconsistent, not because a "
                         "failure was added")

    def test_missing_login_verdict_blocks(self):
        completed, result = self.run_case(checks=self.checks_without("browser_admin_login_session"))
        self.assert_blocking(completed, result, "the derived login verdict was not recorded")
        self.assertTrue(any("browser_admin_login_session" in reason
                            for reason in self.reasons(result)))

    def test_consistent_login_failure_fact_and_verdict_blocks_as_an_extra_material_failure(self):
        # Consistent, but the failure is not one of the seven approved identifiers,
        # so it is an additional material failure and blocks on its own. It is NOT
        # reported as a contradiction, and the login operation is never required to
        # succeed for the accepted signature.
        checks = self.checks_with(check("browser_admin_login_session", probe.FAIL,
                                        probe.PRODUCT, material=True,
                                        detail="the login form is absent"))
        completed, result = self.run_case(checks=checks, facts=self.failed_login_facts())
        self.assert_blocking(completed, result, "a material FAIL outside the accepted seven")
        self.assertTrue(any("outside the accepted signature" in reason
                            and "browser_admin_login_session" in reason
                            for reason in self.reasons(result)))
        self.assertFalse(any("contradicts the recorded browser_admin_login_session" in reason
                             for reason in self.reasons(result)))

    def test_consistent_admin_ajax_success_fact_and_verdict_is_accepted(self):
        completed, result = self.run_case()
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertIs(result["ci_gate"]["diagnostic_exception"]["applies"], True)
        rows = {row["name"]: row["status"] for row in result["checks"]}
        self.assertEqual(rows["browser_admin_ajax_wp_layer"], probe.PASS)

    def test_contradictory_admin_ajax_fact_and_verdict_blocks(self):
        completed, result = self.run_case(facts=self.failed_admin_ajax_facts())
        self.assert_blocking(completed, result,
                             "reached_wp_layer False against a PASS verdict")
        self.assertTrue(any("contradicts the recorded browser_admin_ajax_wp_layer" in reason
                            for reason in self.reasons(result)))

    def test_missing_admin_ajax_verdict_blocks(self):
        completed, result = self.run_case(checks=self.checks_without("browser_admin_ajax_wp_layer"))
        self.assert_blocking(completed, result, "the derived admin-ajax verdict was not recorded")
        self.assertTrue(any("browser_admin_ajax_wp_layer" in reason
                            for reason in self.reasons(result)))

    def test_consistent_admin_ajax_failure_fact_and_verdict_blocks_as_an_extra_material_failure(self):
        checks = self.checks_with(check("browser_admin_ajax_wp_layer", probe.FAIL,
                                        probe.PRODUCT, material=True,
                                        detail="admin-ajax did not reach WordPress"))
        completed, result = self.run_case(checks=checks, facts=self.failed_admin_ajax_facts())
        self.assert_blocking(completed, result, "a material FAIL outside the accepted seven")
        self.assertTrue(any("outside the accepted signature" in reason
                            and "browser_admin_ajax_wp_layer" in reason
                            for reason in self.reasons(result)))
        self.assertFalse(any("contradicts the recorded browser_admin_ajax_wp_layer" in reason
                             for reason in self.reasons(result)))

    def test_genuinely_additional_material_failure_blocks(self):
        # A further browser verdict the producer emits from the same facts, outside
        # the approved seven: consistent facts do not make it acceptable.
        extra = check("browser_no_redirect_loop", probe.FAIL, probe.PRODUCT, material=True)
        completed, result = self.run_case(checks=self.historical_checks(extra=[extra]))
        self.assert_blocking(completed, result, "a genuinely additional material FAIL")

    def test_authentic_historical_evidence_remains_eligible_and_retains_raw_fail(self):
        completed, result = self.run_case()
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertEqual(result["state"], probe.FAIL)
        self.assertEqual(result["counts"]["FAIL"], 8)
        self.assertIs(result["ci_gate"]["diagnostic_exception"]["applies"], True)
        # The seven approved product failures are still recorded as material FAILs:
        # the exception never requires a failing operation to succeed.
        failed = {row["name"] for row in result["checks"]
                  if row["status"] == probe.FAIL and row["material"]}
        self.assertEqual(failed, set(self.MATERIAL_FAILURES))

    def test_incomplete_browser_evidence_blocks(self):
        for missing in ("nav", "login", "rest_status", "admin_ajax", "document"):
            with self.subTest(missing=missing):
                facts = {"browser_post": {k: v for k, v in self.FACTS["browser_post"].items()
                                          if k != missing}}
                completed, result = self.run_case(facts=facts)
                self.assert_blocking(completed, result, f"missing browser key {missing!r}")

    def test_placeholder_browser_values_cannot_pass(self):
        # `nav.front_status` and `rest_status` are the two browser values a required
        # verdict is derived from (`browser_front_page_render`, `browser_rest_index`),
        # so a fact that disagrees with the PASSes recorded beside it is inconsistent
        # evidence and must block. No invented expectation is added: these are exactly
        # the values those two verdicts assert.
        placeholders = (
            {"nav": {"front_status": 1, "front_url": "http://x/", "front_redirects": []}},
            {"nav": {"front_status": 302, "front_url": "http://x/", "front_redirects": []}},
            {"rest_status": 1},
            {"rest_status": 404},
        )
        for override in placeholders:
            with self.subTest(override=override):
                completed, result = self.run_case(
                    facts={"browser_post": {**self.FACTS["browser_post"], **override}})
                self.assert_blocking(completed, result, f"placeholder {override!r}")

    def test_required_probes_are_disjoint_from_the_accepted_failures(self):
        policy = probe.DIAGNOSTIC_EXCEPTION
        self.assertEqual(set(policy["material_failures"]) & set(policy["required_checks"]),
                         set(), "an expected FAIL must never double as an executed-probe proof")

    # -- BLOCKER 2: duplicate identifiers ------------------------------------ #
    def test_duplicate_approved_identifier_blocks(self):
        checks = self.historical_checks()
        checks.append(check("front_page_http_200_post", probe.FAIL, probe.PRODUCT,
                            material=True, detail="duplicated row"))
        completed, result = self.run_case(checks=checks)
        self.assert_blocking(completed, result, "a duplicated approved identifier")
        self.assertIn("front_page_http_200_post",
                      result["ci_gate"]["diagnostic_exception"]["duplicate_identifiers"])

    def test_duplicate_identifier_cannot_hide_a_class_c_failure(self):
        # An environment failure first, then a product/post row with the same
        # identifier: a name-keyed lookup keeps only the last row.
        first = check("front_page_http_200_post", probe.FAIL, probe.ENVIRONMENT, material=True,
                      detail="infrastructure failure recorded first")
        checks = [first] + self.historical_checks()
        completed, result = self.run_case(checks=checks)
        self.assert_blocking(completed, result, "a class C failure hidden behind a duplicate")
        self.assertIn("C (infrastructure/environment)", result["taxonomy"])

    def test_duplicate_identifier_cannot_hide_a_class_d_failure(self):
        first = check("wp_cli_bootstrap_post", probe.FAIL, probe.HARNESS, material=True,
                      detail="harness failure recorded first")
        checks = [first] + self.historical_checks()
        completed, result = self.run_case(checks=checks)
        self.assert_blocking(completed, result, "a class D failure hidden behind a duplicate")
        self.assertIn("D (test/test-infrastructure/fixture defect)", result["taxonomy"])

    def test_unrelated_duplicate_identifier_also_blocks(self):
        checks = self.historical_checks()
        checks.append(check("php_warnings_after_activation", probe.FAIL, probe.PRODUCT,
                            detail="recorded twice"))
        completed, result = self.run_case(checks=checks)
        self.assert_blocking(completed, result, "ambiguous duplicated evidence")

    # -- non-FAIL states never acquire the exception -------------------------- #
    def test_clean_pass_and_partial_never_apply_the_exception(self):
        for label, checks in (
                ("pass", [PASS_CHECK]),
                ("partial", [PASS_CHECK, check("php_warnings_after_activation", probe.FAIL,
                                               probe.PRODUCT, detail="recorded only")])):
            store = self.store(f"{label}-subject", checks,
                               facts=dict(self.FACTS), subject=dict(self.SUBJECT))
            completed, result = self.finalize(f"{label}-subject", store)
            self.assertEqual(completed.returncode, 0, label)
            self.assertIs(result["ci_gate"]["blocking"], False, label)
            self.assertIs(result["ci_gate"]["diagnostic_exception"]["applies"], False, label)
            if label == "partial":
                self.assertEqual(result["state"], "PARTIAL")
                self.assertNotEqual(result["state"], probe.PASS)


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
        completed, summary, markdown = self.aggregate([])
        self.assertNotEqual(completed.returncode, 0)
        self.assertIn("no readable subject result was delivered", completed.stdout)
        self.assertTrue(summary, "the failure summary must be written even for an empty campaign")
        self.assertTrue(markdown, "summary.md must be written even for an empty campaign")

    def diagnostic_subject(self, subject_id="persian-woocommerce", **overrides):
        case = DiagnosticExceptionTests("test_exact_historical_signature_is_diagnostic_only_not_blocking")
        case.root = self.root  # reuse this test's temporary directory
        store = self.store(subject_id, case.historical_checks(),
                           facts=dict(case.FACTS), subject={**case.SUBJECT, **overrides})
        completed, result = self.finalize(subject_id, store)
        self.assertTrue(result, f"{subject_id} produced no artifact: {completed.stderr}")

    def test_aggregate_accepts_the_diagnostic_exception_and_preserves_raw_fail_counts(self):
        self.diagnostic_subject()
        self.build("clean-subject", [PASS_CHECK, ENV_PASS_CHECK])
        completed, summary, markdown = self.aggregate(["persian-woocommerce", "clean-subject"])
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        # The raw FAIL subject is preserved and reported as itself, never merged.
        self.assertEqual(summary["totals"]["FAIL"], 1)
        self.assertEqual(summary["totals"]["PASS"], 1)
        self.assertEqual(summary["dispositions"]["fail_subjects"], ["persian-woocommerce"])
        self.assertEqual(summary["dispositions"]["accepted_diagnostic_exceptions"],
                         ["persian-woocommerce"])
        self.assertEqual(summary["dispositions"]["blocking_failures"], [])
        fail_row = [line for line in markdown.splitlines() if "| `persian-woocommerce` |" in line]
        self.assertEqual(len(fail_row), 1)
        self.assertTrue(fail_row[0].startswith("| FAIL |"), fail_row[0])
        self.assertIn("diagnostic-only", markdown)
        self.assertIn("NOT a compatibility PASS", markdown)

    def test_aggregate_blocks_on_a_changed_signature_even_if_the_artifact_claims_otherwise(self):
        # Defence in depth: the artifact's own boolean is not trusted. The
        # delivered evidence carries one extra material failure, so the aggregate
        # must re-derive "blocking" and refuse.
        self.diagnostic_subject()
        path = self.deliver("persian-woocommerce")
        result = json.loads(path.read_text(encoding="utf-8"))
        result["checks"].append(check("browser_front_page_render", probe.FAIL, probe.PRODUCT,
                                      material=True))
        self.assertIs(result["ci_gate"]["blocking"], False)  # producer marker claims otherwise
        path.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
        completed, summary, _markdown = self.report_run()
        self.assertNotEqual(completed.returncode, 0)
        self.assertEqual(summary["dispositions"]["blocking_failures"], ["persian-woocommerce"])
        self.assertEqual(summary["dispositions"]["accepted_diagnostic_exceptions"], [])

    def test_aggregate_blocks_on_a_fail_subject_without_a_gate_marker(self):
        self.build("material-fail", [check("front_page_http_200_post", probe.FAIL,
                                           probe.PRODUCT, material=True)])
        path = self.deliver("material-fail")
        result = json.loads(path.read_text(encoding="utf-8"))
        result.pop("ci_gate", None)  # a producer that never emitted a disposition
        path.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
        completed, summary, _markdown = self.report_run()
        self.assertNotEqual(completed.returncode, 0)
        self.assertEqual(summary["dispositions"]["blocking_failures"], ["material-fail"])

    # -- BLOCKER 3: campaign completeness against the workflow matrix --------- #
    def test_expected_subjects_are_read_from_the_real_workflow_matrix(self):
        ids = report.expected_subjects(str(WORKFLOW))
        self.assertEqual(len(ids), 21, "the campaign declares 21 matrix legs")
        self.assertEqual(len(set(ids)), len(ids), "matrix ids must be unique")
        for subject_id in ("persian-woocommerce", "locale-baseline-fa-ir",
                           "dep-control-woocommerce", "woocommerce"):
            self.assertIn(subject_id, ids)

    def test_aggregate_blocks_on_a_missing_expected_subject(self):
        self.build("clean-subject", [PASS_CHECK])
        self.build("second-subject", [PASS_CHECK])
        self.deliver("clean-subject")  # second subject never delivers a result
        completed, summary, _markdown = self.report_run(
            expected=["clean-subject", "second-subject"])
        self.assertNotEqual(completed.returncode, 0, "a missing expected subject must block")
        self.assertIn("missing expected subject result", completed.stdout)
        self.assertEqual(summary["campaign"]["missing_subjects"], ["second-subject"])

    def test_aggregate_blocks_on_an_unexpected_subject(self):
        self.build("clean-subject", [PASS_CHECK])
        self.build("stowaway-subject", [PASS_CHECK])
        self.deliver("clean-subject")
        self.deliver("stowaway-subject")
        completed, summary, _markdown = self.report_run(expected=["clean-subject"])
        self.assertNotEqual(completed.returncode, 0, "an unexpected subject must block")
        self.assertIn("unexpected subject result", completed.stdout)
        self.assertEqual(summary["campaign"]["unexpected_subjects"], ["stowaway-subject"])

    def test_aggregate_blocks_on_a_duplicate_subject(self):
        self.build("clean-subject", [PASS_CHECK])
        self.deliver("clean-subject")
        # Same subject delivered twice under the production artifact layout.
        self.deliver("clean-subject", directory="tpb-clean-subject")
        completed, _summary, _markdown = self.report_run(expected=["clean-subject"])
        self.assertNotEqual(completed.returncode, 0, "a duplicated subject must block")
        self.assertIn("duplicate subject result", completed.stdout)

    def test_aggregate_blocks_on_inconsistent_subject_and_file_identity(self):
        self.build("clean-subject", [PASS_CHECK])
        self.deliver("clean-subject", directory="a-different-name")
        completed, _summary, _markdown = self.report_run(expected=["clean-subject"])
        self.assertNotEqual(completed.returncode, 0, "an identity mismatch must block")
        self.assertIn("identity mismatch", completed.stdout)

    def test_aggregate_accepts_the_production_artifact_layout(self):
        self.build("clean-subject", [PASS_CHECK])
        self.deliver("clean-subject", directory="tpb-clean-subject")
        completed, summary, _markdown = self.report_run(expected=["clean-subject"])
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertEqual(summary["campaign"]["delivered_subjects"], ["clean-subject"])
        self.assertEqual(summary["campaign"]["missing_subjects"], [])

    def test_aggregate_blocks_when_the_matrix_is_not_declared(self):
        self.build("clean-subject", [PASS_CHECK])
        self.deliver("clean-subject")
        self.write_matrix([])
        completed, _summary, _markdown = self.report_run(expected=[])
        self.assertNotEqual(completed.returncode, 0, "an empty expectation must block")

    # -- BLOCKER 2: an empty or unreadable campaign still publishes its failure -- #
    def test_zero_delivered_results_fails_and_publishes_the_failure_summary(self):
        results_dir, out_dir = self.root / "results", self.root / "summary"
        results_dir.mkdir(parents=True, exist_ok=True)
        self.write_matrix(["expected-one", "expected-two"])
        completed = subprocess.run(
            [sys.executable, str(REPORT), "--results-dir", str(results_dir),
             "--out-dir", str(out_dir), "--expect-matrix", str(self.root / "matrix.yml"),
             "--lane", "A", "--head-sha", "0" * 40,
             "--run-url", "https://example.invalid/run/0"],
            capture_output=True, text=True, env=ENV, check=False)
        self.assertNotEqual(completed.returncode, 0, "an empty campaign must fail")
        summary_path, markdown_path = out_dir / "summary.json", out_dir / "summary.md"
        self.assertTrue(summary_path.is_file(), "summary.json must exist for an empty campaign")
        self.assertTrue(markdown_path.is_file(), "summary.md must exist for an empty campaign")
        summary = json.loads(summary_path.read_text(encoding="utf-8"))
        markdown = markdown_path.read_text(encoding="utf-8")
        self.assertEqual(summary["subjects"], [], "nothing may be fabricated")
        self.assertEqual(summary["totals"], {"FAIL": 0, "PARTIAL": 0, "PASS": 0, "NOT RUN": 0})
        self.assertEqual(summary["campaign"]["missing_subjects"], ["expected-one", "expected-two"])
        self.assertIn("CAMPAIGN FAILED", markdown)
        self.assertIn("no readable subject result was delivered", markdown)
        self.assertIn("`expected-one`", markdown)
        for state in ("| PASS |", "| PARTIAL |", "| NOT RUN |", "| FAIL |"):
            self.assertNotIn(state, markdown, f"an empty campaign must not claim any {state}")

    def test_wholly_unreadable_results_fail_and_publish_the_failure_summary(self):
        results_dir, out_dir = self.root / "results", self.root / "summary"
        broken = results_dir / "tpb-broken-subject"
        broken.mkdir(parents=True, exist_ok=True)
        (broken / "subject-result.json").write_text("{not json", encoding="utf-8")
        self.write_matrix(["broken-subject"])
        completed = subprocess.run(
            [sys.executable, str(REPORT), "--results-dir", str(results_dir),
             "--out-dir", str(out_dir), "--expect-matrix", str(self.root / "matrix.yml"),
             "--lane", "A", "--head-sha", "0" * 40,
             "--run-url", "https://example.invalid/run/0"],
            capture_output=True, text=True, env=ENV, check=False)
        self.assertNotEqual(completed.returncode, 0)
        summary = json.loads((out_dir / "summary.json").read_text(encoding="utf-8"))
        markdown = (out_dir / "summary.md").read_text(encoding="utf-8")
        self.assertEqual(summary["subjects"], [])
        self.assertTrue(any("unreadable subject result" in problem for problem in summary["problems"]))
        self.assertTrue(any("no readable subject result was delivered" in problem
                            for problem in summary["problems"]))
        self.assertIn("CAMPAIGN FAILED", markdown)
        self.assertIn("unreadable subject result", markdown)
        self.assertIn("`broken-subject`", markdown)

    def test_failure_summary_survives_unreadable_and_missing_subjects_together(self):
        self.build("clean-subject", [PASS_CHECK])
        self.deliver("clean-subject")
        broken = self.root / "results" / "tpb-broken"
        broken.mkdir(parents=True, exist_ok=True)
        (broken / "subject-result.json").write_text("{}", encoding="utf-8")
        completed, summary, markdown = self.report_run(expected=["clean-subject", "never-ran"])
        self.assertNotEqual(completed.returncode, 0)
        self.assertEqual(summary["campaign"]["missing_subjects"], ["never-ran"])
        self.assertTrue(any("missing required keys" in problem for problem in summary["problems"]))
        self.assertIn("| `clean-subject` |", markdown)  # available evidence preserved

    def test_complete_expected_campaign_is_green_and_reports_reconciliation(self):
        self.build("clean-subject", [PASS_CHECK, ENV_PASS_CHECK])
        self.build("partial-subject", [PASS_CHECK,
                                       check("php_warnings_after_activation", probe.FAIL,
                                             probe.PRODUCT, detail="recorded only")])
        completed, summary, markdown = self.aggregate(
            ["clean-subject", "partial-subject"],
            expected=["clean-subject", "partial-subject"])
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertEqual(summary["campaign"]["expected_subjects"],
                         ["clean-subject", "partial-subject"])
        self.assertEqual(summary["campaign"]["missing_subjects"], [])
        self.assertEqual(summary["campaign"]["unexpected_subjects"], [])
        self.assertIn("- campaign: 2/2 expected matrix subject results delivered", markdown)

    def test_campaign_validation_still_publishes_the_artifacts_when_red(self):
        self.build("clean-subject", [PASS_CHECK])
        self.deliver("clean-subject")
        completed, summary, markdown = self.report_run(expected=["clean-subject", "absent-one"])
        self.assertNotEqual(completed.returncode, 0)
        self.assertTrue(summary, "summary.json must survive a failed reconciliation")
        self.assertIn("missing expected subject result", completed.stdout)
        self.assertEqual(summary["campaign"]["missing_subjects"], ["absent-one"])
        self.assertIn("| `clean-subject` |", markdown)
        self.assertIn("- campaign: 1/2 expected matrix subject results delivered", markdown)

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

    def test_aggregate_step_reconciles_against_the_workflow_matrix(self):
        body = self.step("Build the human-readable summary")
        self.assertIn("--expect-matrix .github/workflows/third-party-baseline.yml", body,
                      "the aggregate must read the expected subjects from the matrix itself")

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
