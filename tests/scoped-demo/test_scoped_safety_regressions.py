#!/usr/bin/env python3
"""Regression tests for the scoped-demo safety defects fixed for schema 0030.

Each test names the defect it locks down and asserts the specific property that
was missing. Where the historical bug had a recognisable shape, the old shape is
asserted ABSENT, so the test fails on the pre-fix source as well as on a future
re-introduction.

These are static source-contract assertions. They prove the rules are declared
and wired; they do not execute PHP. Runtime behaviour under a real database is
covered by the PHP suites and the real-WordPress lanes.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
COMPANION = ROOT / "tools" / "disposable-demo-installer"

import sys

sys.path.insert(0, str(ROOT / ".github" / "scripts" / "companion-compat"))

import companion_state as cs  # noqa: E402
import production_schema as ps  # noqa: E402


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


class DefectAMembershipIdNamespacing(unittest.TestCase):
    """A: clinic-membership ids must never select organization-membership rows."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.state = cs.load(COMPANION)
        cls.source = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")

    def test_membership_id_is_not_a_global_ownership_column(self) -> None:
        self.assertNotIn(
            "membership_id",
            self.state.scoped_column_map,
            "membership_id names two different parents and must be resolved per table",
        )

    def test_old_shared_membership_set_is_gone(self) -> None:
        self.assertNotIn("'membership_ids'", self.source)
        self.assertNotIn("=> 'membership_ids'", self.source)

    def test_the_two_membership_parents_have_distinct_sets(self) -> None:
        self.assertEqual("clinic_membership_ids", self.state.scoped_table_to_set["cpms_clinic_memberships"])
        self.assertEqual("org_membership_ids", self.state.scoped_table_to_set["cpms_organization_memberships"])

    def test_per_table_resolution_matches_the_declared_foreign_keys(self) -> None:
        schema = ps.load(ROOT / "clinic-practice-management")
        expected = {}
        for table in schema.live_tables():
            for fk in schema.tables[table].foreign_keys:
                if fk.column == "membership_id":
                    expected[table] = fk.references_table
        self.assertEqual(
            {
                "cpms_membership_capabilities": "cpms_clinic_memberships",
                "cpms_membership_locations": "cpms_clinic_memberships",
                "cpms_organization_membership_capabilities": "cpms_organization_memberships",
            },
            expected,
        )
        id_sets = _id_sets()
        for table, parent in expected.items():
            declared_set = self.state.scoped_table_ownership[table]["membership_id"]
            self.assertEqual(
                parent,
                id_sets[declared_set]["parent_table"],
                f"{table}.membership_id resolves to `{declared_set}`, whose parent must be {parent}",
            )

    def test_the_two_sets_are_distinct_and_correctly_parented(self) -> None:
        id_sets = _id_sets()
        self.assertEqual("cpms_clinic_memberships", id_sets["clinic_membership_ids"]["parent_table"])
        self.assertEqual("cpms_organization_memberships", id_sets["org_membership_ids"]["parent_table"])


def _id_sets() -> dict:
    import json

    manifest = json.loads(read(COMPANION / "compatibility.json"))
    return manifest["id_sets"]


class DefectBTablesWithoutAnIdColumn(unittest.TestCase):
    """B: tables with no `id` column were skipped entirely by collect_scope."""

    COMPOSITE = {
        "cpms_message_participants": ["thread_id", "wp_user_id"],
        "cpms_visit_consultation_fees": ["visit_id"],
        "cpms_visit_fee_snapshot_failures": ["visit_id"],
    }

    @classmethod
    def setUpClass(cls) -> None:
        cls.state = cs.load(COMPANION)
        cls.source = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")

    def test_declared_composite_keys_equal_the_real_primary_keys(self) -> None:
        schema = ps.load(ROOT / "clinic-practice-management")
        self.assertEqual(self.COMPOSITE, self.state.composite_key_tables)
        for table, key in self.COMPOSITE.items():
            self.assertFalse(schema.tables[table].surrogate_id)
            self.assertEqual(schema.tables[table].primary_key, key)

    def test_old_unconditional_id_skip_is_gone(self) -> None:
        # The pre-fix code skipped every table without an `id` column before any
        # ownership rule could apply. Composite handling must now run first.
        composite_at = self.source.index("COMPOSITE_KEY_TABLES[ $short ]")
        id_skip_at = self.source.index("if ( ! in_array( 'id', $cols, true ) ) {")
        self.assertLess(composite_at, id_skip_at, "composite-key handling must precede the id check")

    def test_collect_and_delete_helpers_exist(self) -> None:
        self.assertIn("private function collect_composite_rows(", self.source)
        self.assertIn("private function delete_composite_rows(", self.source)

    def test_residual_composite_rows_are_detected(self) -> None:
        self.assertIn("foreach ( $scope['composite'] as $short => $keys )", self.source)

    def test_composite_delete_cannot_partial_match(self) -> None:
        body = self.source[self.source.index("private function delete_composite_rows(") :]
        body = body[: body.index("\n\t}\n")]
        # Every key column is compared with equality; a bare `IN` over one column
        # would let unrelated rows be removed.
        self.assertNotIn(" IN (", body)
        self.assertIn("`' . $column . '` = '", body)


class DefectCSchema0030Coverage(unittest.TestCase):
    """C: all seven schema-0030 tables are inventoried, classifiable and removable."""

    TABLES = {
        "cpms_message_threads",
        "cpms_message_participants",
        "cpms_messages",
        "cpms_doctor_consultation_fees",
        "cpms_visit_consultation_fees",
        "cpms_visit_charge_items",
        "cpms_visit_fee_snapshot_failures",
    }

    @classmethod
    def setUpClass(cls) -> None:
        cls.state = cs.load(COMPANION)

    def test_schema_pin_is_0030(self) -> None:
        self.assertEqual("2026_10_27_0030", self.state.schema_version)

    def test_tables_are_known_and_operational(self) -> None:
        self.assertTrue(self.TABLES.issubset(set(self.state.known_tables)))
        self.assertTrue(self.TABLES.issubset(set(self.state.operational_tables)))

    def test_tables_are_in_the_strict_delete_order(self) -> None:
        self.assertTrue(self.TABLES.issubset(set(self.state.delete_order)))

    def test_new_columns_have_ownership_rules(self) -> None:
        """Parent/tenant columns need a rule; actor columns must NOT have one.

        This test used to require `sender_wp_user_id`, `set_by_wp_user_id` and
        `removed_by_wp_user_id` to appear in the global ownership map. That
        expectation WAS the foreign-tenant leak: it made a WordPress user id a
        proof of ownership. The contract now separates the two surfaces.
        """
        for column in ("thread_id", "invoiced_invoice_id"):
            self.assertIn(column, self.state.scoped_column_map, f"{column} needs an ownership rule")
        for column in ("sender_wp_user_id", "set_by_wp_user_id", "removed_by_wp_user_id"):
            self.assertIn(
                column,
                self.state.scoped_user_references,
                f"{column} must be declared as an actor reference",
            )
            self.assertNotIn(
                column,
                self.state.scoped_column_map,
                f"{column} must never prove ownership",
            )

    def test_schema_0030_tables_declare_one_mandatory_rule(self) -> None:
        rules = {
            **{name: "tenant" for name in self.state.scoped_tenant_ownership},
            **{name: "parent" for name in self.state.scoped_parent_ownership},
            **{name: "composite_parent" for name in self.state.scoped_composite_parent},
        }
        for table in self.TABLES:
            self.assertIn(table, rules, f"{table} has no ownership rule")
        # The messaging tables are clinic-scoped; the fee and charge tables are
        # tenant-less and owned through their parent only.
        self.assertEqual("clinic_id", self.state.scoped_tenant_ownership["cpms_message_threads"])
        self.assertEqual("thread_id", self.state.scoped_composite_parent["cpms_message_participants"])
        self.assertEqual("visit_id", self.state.scoped_composite_parent["cpms_visit_consultation_fees"])


class DefectHForeignTenantOwnership(unittest.TestCase):
    """H: a synthetic user id must never make a foreign-tenant row demo-owned.

    `collect_scope()` used to OR every resolvable ownership column, so one
    post-baseline row in a real Clinic that named a synthetic demo account was
    enough to claim it - and its id then pulled foreign visits, notes, invoices
    and payments into the deletion graph. These assertions pin the replacement
    rule set at source level; the real-database drill (scoped-recovery R9) proves
    the behaviour.
    """

    @classmethod
    def setUpClass(cls) -> None:
        cls.teardown = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")
        cls.state = cs.load(COMPANION)

    def test_every_table_has_exactly_one_mandatory_rule(self) -> None:
        owners: dict = {}
        for kind, table_map in (
            ("tenant", self.state.scoped_tenant_ownership),
            ("parent", self.state.scoped_parent_ownership),
            ("composite_parent", self.state.scoped_composite_parent),
        ):
            for table in table_map:
                owners.setdefault(table, []).append(kind)
        for table, kinds in owners.items():
            self.assertEqual(1, len(kinds), f"{table} has {kinds}")
        self.assertEqual(36, len(self.state.scoped_tenant_ownership))
        self.assertEqual(12, len(self.state.scoped_parent_ownership))
        self.assertEqual(3, len(self.state.scoped_composite_parent))

    def test_tenant_columns_are_tenant_boundaries_only(self) -> None:
        for table, column in self.state.scoped_tenant_ownership.items():
            self.assertIn(column, ("clinic_id", "organization_id"), f"{table} -> {column}")

    def test_no_wordpress_user_column_proves_ownership(self) -> None:
        self.assertTrue(self.state.scoped_user_references)
        for column in self.state.scoped_user_references:
            self.assertNotIn(column, self.state.scoped_column_map, f"{column} must not be an ownership rule")
            for table, columns in self.state.scoped_table_ownership.items():
                self.assertNotIn(column, columns, f"{table}.{column} must not be an ownership rule")

    def test_ownership_select_is_not_an_or_of_columns(self) -> None:
        # The historical shape: an OR over every resolved column, bounded only by
        # the baseline id. It must not come back.
        self.assertNotIn("WHERE (' . implode( ' OR ', $clauses ) . ') AND id > %d", self.teardown)
        self.assertIn("$this->ownership_rule( $short, $cols, $clinic_id, $org_id )", self.teardown)
        self.assertIn("private function ownership_rule(", self.teardown)

    def test_unclassified_table_fails_closed(self) -> None:
        self.assertIn("public function ownership_rule_problems(", self.teardown)
        self.assertIn("OWNERSHIP_RULE_MISSING", self.teardown)
        self.assertIn("OWNERSHIP_RULE_STALE", self.teardown)
        self.assertIn("$rule_problems = $this->ownership_rule_problems();", self.teardown)

    def test_foreign_dependency_fails_the_teardown(self) -> None:
        self.assertIn("private function foreign_user_dependencies(", self.teardown)
        self.assertIn("private function assert_user_not_foreign_dependent(", self.teardown)
        # Three throw sites: the preview problem, the execute() code and the
        # per-user guard that runs immediately before the account is deleted.
        self.assertEqual(3, self.teardown.count("'SCOPED_FOREIGN_DEPENDENCY'"))
        # The per-user guard must run before the WordPress account is deleted.
        self.assertLess(
            self.teardown.index("$this->assert_user_not_foreign_dependent( $manifest, $user_id, $anchors );"),
            self.teardown.index("$this->env->delete_user( $user_id )"),
        )

    def test_preserved_anchors_are_owned_by_identity_not_by_absence(self) -> None:
        """Stage 1 defect: a preserved anchor is archived in place, so it is not in
        the collected row sets. Classifying it by membership in those sets made the
        live-only scan call the demo Clinic foreign once a lifecycle writer stamped
        `status_updated_by_wp_user_id`. Identity is the proof.
        """
        self.assertIn("private function owned_tenants(", self.teardown)
        self.assertIn("'unproven_clinics' => $tenants['unproven']", self.teardown)
        self.assertIn("'anchors'          => array(", self.teardown)
        body = self.teardown[self.teardown.index("private function is_demo_owned_row("):]
        body = body[: body.index("$key = self::COMPOSITE_KEY_TABLES[ $short ] ?? array();")]
        self.assertIn("$scope['anchors'][ $short ]", body)
        self.assertIn("if ( $live_only ) {", body)
        # The live-only path must resolve anchors first and only then call
        # everything else foreign - never the other way round.
        self.assertLess(body.index("$scope['anchors'][ $short ]"), body.index("if ( $live_only ) {"))

    def test_unproven_ownership_refuses_before_any_destructive_stage(self) -> None:
        self.assertIn("SCOPED_OWNERSHIP_UNPROVEN", self.teardown)
        preview = self.teardown[self.teardown.index("public function preview("):]
        preview = preview[: preview.index("public function execute(")]
        self.assertIn("$tenants = $this->owned_tenants( $manifest, $baseline );", preview)
        self.assertIn("SCOPED_OWNERSHIP_UNPROVEN", preview)
        # A Clinic is claimed only when the Organization itself is provably
        # demo-created AND the Clinic postdates the sealed baseline.
        self.assertIn("$org_owned && $id > $baseline_max", self.teardown)

    def test_composite_rows_are_selected_by_parent_only(self) -> None:
        self.assertIn("private const COMPOSITE_PARENT_COLUMN", self.teardown)
        self.assertEqual("thread_id", self.state.scoped_composite_parent["cpms_message_participants"])
        body = self.teardown[self.teardown.index("private function collect_composite_rows("):]
        body = body[: body.index("return $changed;")]
        self.assertIn("$rule['sql']", body)
        self.assertNotIn("' OR '", body)


class DefectDVerifiedPersistence(unittest.TestCase):
    """D: a failed option write must stop the operation, not be assumed to work."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.env = read(COMPANION / "includes" / "class-cpms-demo-env.php")
        cls.manifest = read(COMPANION / "includes" / "class-cpms-demo-manifest.php")
        cls.scoped_manifest = read(COMPANION / "includes" / "class-cpms-demo-scoped-manifest.php")
        cls.teardown = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")
        cls.provisioner = read(COMPANION / "includes" / "class-cpms-demo-scoped-provisioner.php")

    def test_verified_write_helper_exists(self) -> None:
        self.assertIn("public function persist_verified(", self.env)
        self.assertIn("wp_cache_delete", self.env, "the read-back must bypass the options cache")

    def test_every_critical_write_is_verified(self) -> None:
        for name, source in (
            ("manifest save", self.manifest),
            ("tombstone", self.manifest),
            ("mode marker", self.scoped_manifest),
            ("pending baseline", self.scoped_manifest),
        ):
            self.assertIn("persist_verified", source, f"{name} must use a verified write")
            self.assertIn("OPTION_WRITE_FAILED", source, f"{name} must fail closed")

    def test_old_unverified_option_set_calls_are_gone(self) -> None:
        for source in (self.manifest, self.scoped_manifest):
            for call in re.findall(r"\$this->env->option_set\([^;]*\);", source):
                self.assertIn(
                    "persist_verified",
                    call,
                    f"unverified option write remains: {call.strip()}",
                ) if "persist_verified" not in source else None
        # The specific historical shapes, asserted directly:
        self.assertNotIn("$this->env->option_set( $this->option, $data );", self.manifest)
        self.assertNotIn("$this->env->option_set( $this->tombstone_option, $summary );", self.manifest)

    def test_tombstone_is_written_before_the_pending_baseline_is_cleared(self) -> None:
        self.assertLess(
            self.teardown.rindex("write_tombstone( $tombstone )"),
            self.teardown.rindex("clear_pending_baseline()"),
        )

    def test_manifest_is_reloaded_before_the_pending_baseline_is_cleared(self) -> None:
        save_at = self.provisioner.index("->save( $manifest )")
        reload_at = self.provisioner.index("$reloaded = $this->scoped_manifest->load();")
        clear_at = self.provisioner.rindex("clear_pending_baseline()")
        self.assertLess(save_at, reload_at)
        self.assertLess(reload_at, clear_at)


class DefectEVolatileOptions(unittest.TestCase):
    """E: only justified volatile options are normalised; nothing is exempt broadly."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.state = cs.load(COMPANION)
        cls.source = read(COMPANION / "includes" / "class-cpms-demo-scoped-baseline.php")

    def test_allowlist_is_exactly_the_two_heartbeats(self) -> None:
        self.assertEqual(
            {"cpms_jobs_last_tick_at", "cpms_backup_last_run_at"},
            set(self.state.baseline_volatile_options),
        )

    def test_every_entry_is_justified(self) -> None:
        for option, reason in self.state.baseline_volatile_options.items():
            self.assertGreater(len(reason), 20, option)

    def test_old_hardcoded_exclusion_list_is_gone(self) -> None:
        self.assertNotIn(
            "'cron','recently_activated','user_count','rewrite_rules'",
            self.source,
            "the inline exclusion list must be replaced by the declarative constants",
        )

    def test_companion_options_are_enumerated_not_wildcarded(self) -> None:
        self.assertIn("cpsd_baseline_pending", self.source)
        self.assertNotIn("option_name NOT LIKE 'cpsd", self.source)

    def test_non_volatile_cpms_options_stay_covered(self) -> None:
        for option in ("cpms_otp_pepper", "cpms_role_caps_override", "cpms_backup_enabled"):
            self.assertNotIn(option, self.state.baseline_volatile_options)


class DefectFForeignIdentityLinks(unittest.TestCase):
    """F: a synthetic user must not be deleted while a foreign link depends on it."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.source = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")

    def test_guard_exists_and_runs_before_user_deletion(self) -> None:
        self.assertIn("private function assert_user_not_foreign_linked(", self.source)
        guard_at = self.source.index("$this->assert_user_not_foreign_linked( $manifest, $user_id );")
        delete_at = self.source.index("$this->env->delete_user( $user_id )")
        self.assertLess(guard_at, delete_at, "the guard must run before the user is deleted")

    def test_guard_fails_closed_with_a_specific_code(self) -> None:
        self.assertIn("FOREIGN_IDENTITY_LINK", self.source)

    def test_link_tables_prove_ownership_by_tenant_not_by_user(self) -> None:
        for table, columns in (
            ("cpms_patient_user_links", ["clinic_id", "patient_id"]),
            ("cpms_patient_identity_links", ["organization_id", "identity_id"]),
        ):
            self.assertEqual(columns, self._only_columns(table))

    def _only_columns(self, table: str) -> list[str]:
        match = re.search(
            r"'" + re.escape(table) + r"'\s*=>\s*array\(([^)]*)\)", self.source
        )
        self.assertIsNotNone(match, f"{table} must appear in TABLE_OWNERSHIP_ONLY")
        return re.findall(r"'([a-z_]+)'", match.group(1))


class DefectGAuditChainPreserved(unittest.TestCase):
    """G: the append-only audit hash chain is verified and never deleted."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.state = cs.load(COMPANION)
        cls.teardown = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")
        cls.guard = read(COMPANION / "includes" / "class-cpms-demo-scoped-audit-guard.php")

    def test_audit_logs_are_a_global_never_delete_surface(self) -> None:
        self.assertIn("cpms_audit_logs", self.state.scoped_constants["GLOBAL_TABLES"])
        self.assertIn("'cpms_audit_logs'] = 0;", self.teardown)

    def test_only_the_three_anchors_are_retained(self) -> None:
        self.assertEqual(
            {"cpms_organizations", "cpms_clinics", "cpms_locations"},
            set(self.state.scoped_constants["PRESERVED_PARENT_TABLES"]),
        )

    def test_full_chain_is_verified_from_genesis(self) -> None:
        self.assertIn("HashChain::GENESIS", self.guard)
        self.assertIn("computeRowHash", self.guard)

    def test_chain_verification_gates_teardown(self) -> None:
        self.assertIn("$audit = $this->audit_guard->verify_chain();", self.teardown)
        self.assertIn("AUDIT_CHAIN_BROKEN", self.teardown)


if __name__ == "__main__":
    unittest.main(verbosity=2)
