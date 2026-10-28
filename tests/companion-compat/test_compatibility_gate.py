#!/usr/bin/env python3
"""Regression tests for the companion compatibility gate.

These tests run with the standard library only and execute in seconds, so they
are part of the static lane for every pull request.

Two kinds of assertion live here:

* **live assertions** — the repository's current production plugin and companion
  satisfy the compatibility contract;
* **detector assertions** — each check still *fails* on the shape of the bug it
  exists to catch. A gate that cannot fail is not a gate, so the historical
  defect shapes are reconstructed in memory and handed to the check functions.

These are NOT WordPress runtime evidence. Ownership and deletion correctness
under a real database is proven by the PHP suites and the real-WordPress lanes.
"""

from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / ".github" / "scripts" / "companion-compat"
sys.path.insert(0, str(SCRIPTS))

import companion_state as cs  # noqa: E402
import evaluate  # noqa: E402
import production_features as pf  # noqa: E402
import production_schema as ps  # noqa: E402

MANIFEST = ROOT / "tools" / "disposable-demo-installer" / "compatibility.json"
COMPANION = ROOT / "tools" / "disposable-demo-installer"
PLUGIN = ROOT / "clinic-practice-management"

COMPOSITE_TABLES = {
    "cpms_message_participants",
    "cpms_visit_consultation_fees",
    "cpms_visit_fee_snapshot_failures",
}
SCHEMA_0030_TABLES = {
    "cpms_message_threads",
    "cpms_message_participants",
    "cpms_messages",
    "cpms_doctor_consultation_fees",
    "cpms_visit_consultation_fees",
    "cpms_visit_charge_items",
    "cpms_visit_fee_snapshot_failures",
}


def load_manifest() -> dict:
    return json.loads(MANIFEST.read_text(encoding="utf-8"))


class DerivedProductionFacts(unittest.TestCase):
    """The production side is derived, never hand-maintained."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.schema = ps.load(PLUGIN)
        cls.surface = pf.load(PLUGIN)

    def test_schema_head_matches_the_pinned_contract(self) -> None:
        self.assertEqual("2026_10_27_0030", self.schema.head)
        self.assertEqual(30, len(self.schema.versions))
        self.assertEqual(60, len(self.schema.live_tables()))

    def test_schema_0030_tables_are_discovered(self) -> None:
        live = self.schema.live_tables()
        self.assertTrue(SCHEMA_0030_TABLES.issubset(live))
        for table in SCHEMA_0030_TABLES:
            self.assertEqual("2026_10_27_0030", self.schema.tables[table].introduced_by)

    def test_tables_without_a_surrogate_id_are_identified(self) -> None:
        without_id = {
            name for name in self.schema.live_tables() if not self.schema.tables[name].surrogate_id
        }
        self.assertEqual(
            COMPOSITE_TABLES | {"cpms_rate_limits", "cpms_schema_migrations"},
            without_id,
        )

    def test_membership_id_is_the_only_schema_wide_ambiguous_column(self) -> None:
        parents: dict[str, set[str]] = {}
        for name in self.schema.live_tables():
            for fk in self.schema.tables[name].foreign_keys:
                parents.setdefault(fk.column, set()).add(fk.references_table)
        ambiguous = {column: sorted(p) for column, p in parents.items() if len(p) > 1}
        self.assertEqual(
            {"membership_id": ["cpms_clinic_memberships", "cpms_organization_memberships"]},
            ambiguous,
        )

    def test_role_and_capability_surface_is_derived(self) -> None:
        self.assertEqual(5, len(self.surface.roles))
        self.assertEqual(46, len(self.surface.capabilities))
        self.assertIn("cpms_doctor", self.surface.roles)

    def test_rest_surface_includes_the_schema_0030_endpoints(self) -> None:
        paths = self.surface.route_paths()
        self.assertIn("clinic/v1/staff/portal/messages/threads", paths)
        self.assertIn("clinic/v1/staff/portal/pricing/default-fee", paths)
        self.assertEqual([], self.surface.unparsed)


class CompanionDeclaredState(unittest.TestCase):
    """The companion's declared state matches the derived production facts."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.state = cs.load(COMPANION)
        cls.manifest = load_manifest()

    def test_schema_pin_is_current(self) -> None:
        self.assertEqual("2026_10_27_0030", self.state.schema_version)

    def test_inventory_is_the_manifest_inventory(self) -> None:
        self.assertEqual(sorted(self.manifest["tables"]), sorted(self.state.known_tables))
        self.assertEqual(50, len(self.state.operational_tables))
        self.assertEqual(10, len(self.state.system_tables))
        self.assertEqual(60, len(self.state.known_tables))

    def test_membership_id_sets_are_namespaced(self) -> None:
        self.assertNotIn("membership_id", self.state.scoped_column_map)
        self.assertEqual(
            "clinic_membership_ids",
            self.state.scoped_table_to_set["cpms_clinic_memberships"],
        )
        self.assertEqual(
            "org_membership_ids",
            self.state.scoped_table_to_set["cpms_organization_memberships"],
        )
        self.assertEqual(
            "org_membership_ids",
            self.state.scoped_table_ownership["cpms_organization_membership_capabilities"][
                "membership_id"
            ],
        )

    def test_composite_key_tables_are_implemented(self) -> None:
        self.assertEqual(
            {
                "cpms_message_participants": ["thread_id", "wp_user_id"],
                "cpms_visit_consultation_fees": ["visit_id"],
                "cpms_visit_fee_snapshot_failures": ["visit_id"],
            },
            self.state.composite_key_tables,
        )

    def test_volatile_options_are_narrow_and_justified(self) -> None:
        self.assertEqual(
            {"cpms_jobs_last_tick_at", "cpms_backup_last_run_at"},
            set(self.state.baseline_volatile_options),
        )
        for reason in self.state.baseline_volatile_options.values():
            self.assertGreater(len(reason), 20, "every volatile option needs a written justification")

    def test_no_source_element_is_unparsed(self) -> None:
        self.assertEqual([], self.state.unparsed)


class GateBehaviour(unittest.TestCase):
    """The gate passes for compatibility and reports the declared demo gaps."""

    def test_compatibility_scope_passes(self) -> None:
        result = evaluate.evaluate(ROOT, gate="compatibility")
        self.assertTrue(result["ok"], evaluate.render(result))
        self.assertEqual(0, result["counts"]["blocker"])

    def test_declared_demo_gaps_block_the_full_gate(self) -> None:
        """A demo-required feature without a harness journey is a GAP, and a GAP
        fails `--gate all` while `--gate compatibility` still passes.

        The repository itself is expected to be complete, so the gap is injected
        rather than borrowed from whatever state the manifest happens to be in:
        the check must stay a real check even after every feature is covered.
        """
        root = self._root_with_manifest(self._without_journeys("doctor_visit_pricing"))
        result = evaluate.evaluate(root, gate="all")
        self.assertFalse(result["ok"])
        self.assertFalse(result["compatibility_complete"])
        gap_ids = {
            finding["message"]
            for finding in result["findings"]
            if finding["severity"] == "gap" and finding["check"] == "demo_coverage"
        }
        self.assertTrue(
            any("doctor_visit_pricing" in message for message in gap_ids),
            json.dumps(sorted(gap_ids)),
        )
        self.assertEqual(0, evaluate.main(["--root", str(root), "--gate", "compatibility", "--quiet"]))
        self.assertEqual(1, evaluate.main(["--root", str(root), "--gate", "all", "--quiet"]))

    def test_repository_is_complete_for_both_gates(self) -> None:
        """The committed companion really covers every demo-required feature."""
        result = evaluate.evaluate(ROOT, gate="all")
        self.assertTrue(result["ok"], json.dumps(result["findings"])[:2000])
        self.assertTrue(result["compatibility_complete"])
        self.assertEqual([], result["summary"]["demo_required_features_without_journeys"])

    def test_entrypoint_exit_codes(self) -> None:
        self.assertEqual(0, evaluate.main(["--root", str(ROOT), "--gate", "compatibility", "--quiet"]))
        self.assertEqual(0, evaluate.main(["--root", str(ROOT), "--gate", "all", "--quiet"]))

    # -- helpers ---------------------------------------------------------

    @staticmethod
    def _without_journeys(feature_id: str):
        def mutate(manifest: dict) -> dict:
            for feature in manifest.get("features") or []:
                if feature.get("id") == feature_id:
                    feature["journeys"] = []
            return manifest

        return mutate

    def _root_with_manifest(self, mutate) -> Path:
        """A throwaway repository root whose only difference is the manifest."""
        import shutil
        import tempfile

        root = Path(tempfile.mkdtemp(prefix="cpdd-gate-"))
        self.addCleanup(shutil.rmtree, root, True)
        (root / "clinic-practice-management").symlink_to(PLUGIN, target_is_directory=True)
        (root / "tests").symlink_to(ROOT / "tests", target_is_directory=True)
        # The schema-pin scan reads the workflow files, so the throwaway root
        # needs them too; everything else about the checkout is shared.
        (root / ".github").symlink_to(ROOT / ".github", target_is_directory=True)
        companion = root / "tools" / "disposable-demo-installer"
        companion.mkdir(parents=True)
        for entry in sorted(COMPANION.iterdir()):
            if entry.name == "compatibility.json":
                continue
            (companion / entry.name).symlink_to(
                entry, target_is_directory=entry.is_dir()
            )
        manifest = mutate(load_manifest())
        (companion / "compatibility.json").write_text(
            json.dumps(manifest, indent=2, sort_keys=True, ensure_ascii=False) + "\n",
            encoding="utf-8",
        )
        return root

    def test_manifest_is_deterministic(self) -> None:
        """The committed manifest must equal a fresh derivation, byte for byte.

        The sync workflow regenerates this file; non-deterministic output would
        produce a reviewable-looking diff on every run.
        """
        import author_manifest

        regenerated = author_manifest.build(ROOT)
        self.assertEqual(
            json.dumps(regenerated, indent=2, sort_keys=True, ensure_ascii=False) + "\n",
            MANIFEST.read_text(encoding="utf-8"),
        )


class DetectorsStillFail(unittest.TestCase):
    """Reconstruct historical defect shapes and prove each check rejects them."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.schema = ps.load(PLUGIN)
        cls.state = cs.load(COMPANION)
        cls.manifest = load_manifest()

    def _report(self):
        return evaluate.Report()

    def test_membership_id_collision_is_rejected(self) -> None:
        """Defect A: one shared membership_ids set fed by clinic memberships."""
        state = cs.load(COMPANION)
        state.scoped_column_map = dict(state.scoped_column_map)
        state.scoped_column_map["membership_id"] = "membership_ids"
        report = self._report()
        evaluate.check_ownership_rules(self.schema, state, self.manifest, report)
        checks = {f.check for f in report.findings if f.severity == evaluate.BLOCKER}
        self.assertIn("ownership_ambiguity", checks)
        message = " ".join(f.message for f in report.findings if f.check == "ownership_ambiguity")
        self.assertIn("cpms_organization_memberships", message)

    def test_missing_composite_key_rule_is_rejected(self) -> None:
        """Defect B: tables without an `id` column were skipped entirely."""
        state = cs.load(COMPANION)
        state.composite_key_tables = {}
        report = self._report()
        evaluate.check_ownership_rules(self.schema, state, self.manifest, report)
        blocked = {
            f.message
            for f in report.findings
            if f.check == "composite_key" and f.severity == evaluate.BLOCKER
        }
        for table in COMPOSITE_TABLES:
            self.assertTrue(
                any(table in message for message in blocked),
                f"{table} must be reported when its composite-key rule is missing",
            )

    def test_undeclared_volatile_option_is_rejected(self) -> None:
        """Defect E: broad exemption of shared options hid unauthorised changes."""
        state = cs.load(COMPANION)
        state.baseline_volatile_options = dict(state.baseline_volatile_options)
        state.baseline_volatile_options["cpms_role_caps_override"] = "made up"
        report = self._report()
        evaluate.check_volatile_options(state, self.manifest, report)
        messages = " ".join(f.message for f in report.findings)
        self.assertIn("cpms_role_caps_override", messages)
        self.assertTrue(any(f.severity == evaluate.BLOCKER for f in report.findings))

    def test_unknown_production_table_is_rejected(self) -> None:
        """Unknown tables must never pass silently."""
        manifest = json.loads(json.dumps(self.manifest))
        manifest["tables"].pop("cpms_message_threads")
        report = self._report()
        evaluate.check_table_inventory(self.schema, self.state, manifest, report)
        messages = " ".join(f.message for f in report.findings if f.check == "table_inventory")
        self.assertIn("cpms_message_threads", messages)

    def test_stale_schema_pin_is_rejected(self) -> None:
        state = cs.load(COMPANION)
        state.schema_version = "2026_10_18_0029"
        report = self._report()
        evaluate.check_schema_head(self.schema, state, self.manifest, report)
        self.assertTrue(
            any(f.check == "schema_head" and f.severity == evaluate.BLOCKER for f in report.findings)
        )

    def test_missing_teardown_coverage_is_rejected(self) -> None:
        state = cs.load(COMPANION)
        state.delete_order = [t for t in state.delete_order if t != "cpms_visit_charge_items"]
        report = self._report()
        evaluate.check_teardown_coverage(self.schema, state, self.manifest, report)
        self.assertTrue(
            any(
                "cpms_visit_charge_items" in f.message and f.severity == evaluate.BLOCKER
                for f in report.findings
            )
        )

    def test_stale_workflow_schema_pin_is_rejected(self) -> None:
        """A lane's hardcoded schema assertion must match the derived head.

        Both companion lanes asserted `2026_10_18_0029` long after production
        moved to `2026_10_27_0030`. Because their path filters exclude the
        production subtree they only ran on a companion change, so the staleness
        surfaced as a red lane on an unrelated pull request instead of being
        evaluated here.
        """
        manifest = load_manifest()
        scan = manifest["schema_pin_scan"]
        self.assertTrue(scan["files"])
        report = self._report()
        evaluate.check_stale_workflow_pins(self.schema, manifest, report, ROOT)
        self.assertEqual(
            [], [f.message for f in report.findings], "the committed workflows must be current"
        )

        stale = json.loads(json.dumps(manifest))
        stale["schema_pin_scan"]["allowed_historical_versions"] = []
        stale["schema_pin_scan"]["files"] = [
            rel for rel in scan["files"] if rel.endswith("scoped-demo.yml")
        ]
        target = ROOT / stale["schema_pin_scan"]["files"][0]
        original = target.read_text(encoding="utf-8")
        try:
            target.write_text(
                original.replace("2026_10_27_0030", "2026_10_18_0029").replace(
                    "2026_10_27_0030", "2026_10_18_0029"
                ),
                encoding="utf-8",
            )
            report = self._report()
            evaluate.check_stale_workflow_pins(self.schema, stale, report, ROOT)
            messages = " ".join(f.message for f in report.findings)
            self.assertIn("2026_10_18_0029", messages)
            self.assertTrue(any(f.severity == evaluate.BLOCKER for f in report.findings))
        finally:
            target.write_text(original, encoding="utf-8")

    def test_unimplemented_safety_invariant_is_rejected(self) -> None:
        manifest = json.loads(json.dumps(self.manifest))
        for invariant in manifest["safety_invariants"]:
            if invariant["id"] == "foreign_identity_link_guard":
                invariant["requires"] = ["THIS_TOKEN_DOES_NOT_EXIST"]
        report = self._report()
        evaluate.check_safety_invariants(ROOT, manifest, report)
        self.assertTrue(
            any(f.severity == evaluate.BLOCKER for f in report.findings),
            "a safety invariant that is no longer implemented must block",
        )


class JourneyContract(unittest.TestCase):
    """A feature may not claim a journey that no harness implements."""

    def test_both_harnesses_declare_journeys(self) -> None:
        manifest = load_manifest()
        report = evaluate.Report()
        discovered: set[str] = set()
        for relpath in manifest["sources"]["browser_journeys"]:
            path = ROOT / relpath
            self.assertTrue(path.is_file(), relpath)
            discovered |= evaluate._declared_journeys(path.read_text(encoding="utf-8"), relpath, report)
        self.assertTrue(discovered)
        self.assertEqual([], [f.message for f in report.findings])

    def test_every_declared_journey_exists(self) -> None:
        manifest = load_manifest()
        report = evaluate.Report()
        discovered: set[str] = set()
        for relpath in manifest["sources"]["browser_journeys"]:
            path = ROOT / relpath
            discovered |= evaluate._declared_journeys(path.read_text(encoding="utf-8"), relpath, report)
        declared = {
            journey
            for feature in manifest["features"]
            for journey in feature.get("journeys") or []
        }
        self.assertEqual(set(), declared - discovered)


if __name__ == "__main__":
    unittest.main(verbosity=2)
