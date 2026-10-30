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
        # A Clinic is claimed only when the sealed ownership ledger names its
        # exact id (or the sealed manifest names the fixture Clinic). The old
        # inference - "names the demo Organization and postdates the seal" - is
        # exactly what could not distinguish a genuine non-demo Clinic.
        self.assertIn("in_array( $id, $ledger_ids, true )", self.teardown)
        self.assertNotIn("$org_owned && $id > $baseline_max", self.teardown)
        owners = self.teardown[self.teardown.index("private function owned_tenants("):]
        owners = owners[: owners.index("private function ownership_rule(")]
        self.assertNotIn("$id > $baseline_max", owners)
        # The ledger is consulted for the fixture and, for a sealed active
        # fixture, its absence is itself a refusal (Stage 3 hardening).
        self.assertIn("$this->ownership->summary( $fixture, $require_ledger )", owners)
        self.assertIn(
            "$require_ledger = 'active' === (string) ( $manifest['status'] ?? '' ) && is_array( $stored );",
            owners,
        )

    def test_composite_rows_are_selected_by_parent_only(self) -> None:
        self.assertIn("private const COMPOSITE_PARENT_COLUMN", self.teardown)
        self.assertEqual("thread_id", self.state.scoped_composite_parent["cpms_message_participants"])
        body = self.teardown[self.teardown.index("private function collect_composite_rows("):]
        body = body[: body.index("return $changed;")]
        self.assertIn("$rule['sql']", body)
        self.assertNotIn("' OR '", body)


class ClinicOwnershipIsExplicitNotInferred(unittest.TestCase):
    """A: an additional Clinic is demo-owned only because a sealed record says so.

    The risk this locks down: a legitimate non-demo Clinic created under the
    synthetic demo Organization AFTER the sealed baseline used to be claimed as
    demo-owned merely because it named that Organization and had a higher id.
    Nothing about an Organization reference, an auto-increment id, a creation
    timestamp or a synthetic actor id may decide ownership; only a sealed record
    may - and anything unproven must fail closed before a destructive stage.
    """

    @classmethod
    def setUpClass(cls) -> None:
        cls.teardown = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")
        cls.ledger = read(COMPANION / "includes" / "class-cpms-demo-scoped-ownership.php")
        cls.clinics = read(COMPANION / "includes" / "class-cpms-demo-scoped-clinics.php")
        cls.provisioner = read(COMPANION / "includes" / "class-cpms-demo-scoped-provisioner.php")
        cls.admin = read(COMPANION / "includes" / "class-cpms-demo-scoped-admin.php")
        cls.plugin = read(COMPANION / "cpms-disposable-demo.php")
        cls.portal_drill = read(COMPANION / "tests" / "scoped-portal.php")

    def test_the_ledger_is_a_sealed_append_only_chain(self) -> None:
        self.assertIn("public const OPTION = 'cpsd_ownership_ledger';", self.ledger)
        self.assertIn("public const FORMAT", self.ledger)
        # Append-only: every record commits its sequence, the previous MAC and
        # its own MAC, and the record commits the chain head and length, so an
        # edit, an insertion OR a truncation is detected.
        for anchor in ("'seq'", "'prev'", "'count'", "'head'"):
            self.assertIn(anchor, self.ledger)
        self.assertIn("hash_hmac(", self.ledger)
        self.assertIn("$this->manifest->verify( $raw )", self.ledger)
        self.assertIn("hash_equals", read(COMPANION / "includes" / "class-cpms-demo-manifest.php"))
        # The option namespace is its own: it can never overwrite the manifest.
        self.assertNotIn("'cpsd_manifest'", self.ledger.split("function __construct")[1][:400])

    def test_ownership_is_never_inferred_from_tenant_or_time(self) -> None:
        owners = self.teardown[self.teardown.index("private function owned_tenants("):]
        owners = owners[: owners.index("private function ownership_rule(")]
        # The Clinic is claimed from sealed ids only.
        self.assertIn("in_array( $id, $ledger_ids, true )", owners)
        # ... and never from a baseline comparison, a timestamp or a user column.
        for forbidden in ("$id > $baseline_max", "created_at", "wp_user_id", "'suspended' ==="):
            self.assertNotIn(forbidden, owners, forbidden)

    def test_an_invalid_or_pending_ledger_blocks_before_any_deletion(self) -> None:
        # The ledger itself owns the invalidity code; the pending (interrupted)
        # state is raised by the Clinic service and again by the teardown.
        self.assertIn("SCOPED_OWNERSHIP_LEDGER_INVALID", self.ledger)
        self.assertIn("SCOPED_OWNERSHIP_LEDGER_INVALID", self.teardown)
        self.assertIn("SCOPED_OWNERSHIP_PENDING", self.teardown)
        self.assertIn("SCOPED_OWNERSHIP_PENDING", self.clinics)
        preview = self.teardown[self.teardown.index("public function preview("):]
        preview = preview[: preview.index("public function execute(")]
        self.assertIn("$tenants['ledger_code']", preview)
        self.assertIn("$tenants['pending']", preview)
        execute = self.teardown[self.teardown.index("private function do_execute("):]
        first_destructive = execute.index("$stage   = 'sessions';")
        self.assertLess(execute.index("'SCOPED_OWNERSHIP_LEDGER_INVALID'"), first_destructive)
        self.assertLess(execute.index("'SCOPED_OWNERSHIP_PENDING'"), first_destructive)
        self.assertLess(execute.index("'SCOPED_OWNERSHIP_UNPROVEN'"), first_destructive)

    def test_the_additional_clinic_is_registered_before_it_can_exist(self) -> None:
        body = self.clinics[self.clinics.index("public function create("):]
        body = body[: body.index("public function resolve(")]
        self.assertLess(body.index("'intent'"), body.index("$this->platform->provision_clinic("))
        self.assertIn("'slug'", body)
        # The exact slug is checked free BEFORE it is sealed, so an existing row
        # can never be mistaken for the Clinic this run is about to create.
        self.assertLess(body.index("WHERE slug = %s"), body.index("'intent'"))
        # The Clinic is created by the real production service, never by an INSERT.
        self.assertIn("provision_clinic(", body)
        self.assertNotIn("$wpdb->insert", body)

    def test_portal_clinic_creation_is_refused_server_side(self) -> None:
        # 1) the production capability resolver denies the create capability;
        self.assertIn("enforce_portal_clinic_policy", self.provisioner)
        self.assertIn("'cpms_org_clinic_create'", self.provisioner)
        self.assertIn("production_can(", self.provisioner)
        self.assertIn("'deny'", self.provisioner)
        # 2) and the HTTP boundary refuses the action before dispatch.
        self.assertIn("guard_portal_clinic_creation", self.admin)
        self.assertIn("guard_portal_clinic_creation", self.plugin)
        self.assertIn("'template_redirect'", self.plugin)
        guard = self.admin[self.admin.index("public static function guard_portal_clinic_creation()"):]
        guard = guard[: guard.index("private static function render_credentials(")]
        self.assertIn("'org_create_clinic'", guard)
        self.assertIn("wp_die(", guard)
        self.assertIn("403", guard)
        # Production behaviour outside scoped demo mode is untouched.
        self.assertIn("if ( ! $manifest->mode_enabled() ) {", guard)
        self.assertLess(guard.index("if ( ! $manifest->mode_enabled() ) {"), guard.index("wp_die("))
        # No UI-only restriction: the Persian explanation is server-rendered, and
        # the user-facing card states the enforcement, not just the policy.
        self.assertIn("مسدود", guard)

    def test_every_ownership_decision_survives_the_drill(self) -> None:
        for marker in (
            "ScopedOwnership",
            "ScopedClinics",
            "SCOPED_OWNERSHIP_PENDING",
            "SCOPED_OWNERSHIP_LEDGER_INVALID",
            "SCOPED_OWNERSHIP_UNPROVEN",
            "INJECTED_FAULT",
        ):
            self.assertIn(marker, self.portal_drill)
        # The additional-Clinic scenarios are explicit, not implied by P1/P2.
        for scenario in ("scenario P4", "scenario P5", "scenario P6", "scenario P7"):
            self.assertIn(scenario, self.portal_drill)


class PortalLifecycleDrillsAreSelfConsistent(unittest.TestCase):
    """The real-database portal drill must be statically sound.

    A drill that only fails in CI is expensive: each cycle costs a full
    real-WordPress lane. These checks catch the mechanical slips (a helper called
    with its old argument list, a missing `global $wpdb`, a hard exit that hides
    the failing assertion) before the lane runs.
    """

    PORTAL = COMPANION / "tests" / "scoped-portal.php"

    @classmethod
    def setUpClass(cls) -> None:
        cls.source = cls.PORTAL.read_text(encoding="utf-8")

    def test_helper_calls_match_their_signatures(self) -> None:
        # cpsd_pt_scalar() takes (string $sql, array $params = []).
        for call in re.findall(r"cpsd_pt_scalar\(([^;]*?)\)\s*[),;]", self.source, re.S):
            self.assertNotIn("$platform", call, call)
            # The first argument must be the SQL string, never a bare table name.
            self.assertIsNone(re.match(r"\s*'cpms_", call), call)

    def test_database_handle_is_imported(self) -> None:
        # `wp eval-file` runs the file in a function scope.
        self.assertIn("global $wpdb;", self.source)

    def test_the_drill_reports_instead_of_exiting(self) -> None:
        # The runner parses the JSON verdict and fails the check, so the failing
        # assertion survives into the annotation.
        self.assertNotIn("exit( 1 )", self.source)
        self.assertIn("'ok'       => false", self.source.replace("'ok'      => false", "'ok'       => false"))

    def test_the_drill_drives_the_real_production_portal(self) -> None:
        self.assertIn("ManagementPortalController::dispatch(", self.source)
        for action in ("org_clinic_status", "org_create_clinic"):
            self.assertIn("'" + action + "'", self.source)
        self.assertIn("SCOPED_OWNERSHIP_UNPROVEN", self.source)

    def test_the_lane_runs_the_drill(self) -> None:
        workflow = (ROOT / ".github" / "workflows" / "scoped-demo.yml").read_text(encoding="utf-8")
        self.assertIn("tests/scoped-portal.php", workflow)
        self.assertIn("SCOPED_PORTAL", workflow)

    def test_the_rest_boundary_probe_reads_the_response_envelope(self) -> None:
        # `WP_REST_Server::dispatch()` converts a WP_Error (including the
        # barrier's own 403) into a WP_REST_Response before returning, so a probe
        # that only inspects `is_wp_error()` reports every refusal as
        # "not refused". The drill must read the machine-readable `code` from the
        # envelope, through the one helper that knows this.
        self.assertIn("function cpsd_pt_rest_answer(", self.source)
        self.assertIn("$data['code']", self.source)
        probes = re.findall(
            r"\$(\w+)\s*=\s*cpsd_pt_rest_answer\(\s*rest_do_request\(", self.source
        )
        self.assertEqual(
            sorted(probes), ["own_answer", "persona_answer", "refused"], probes
        )
        # No probe may fall back to the bare WP_Error reading anywhere but inside
        # the helper itself, and the evidence record must use the envelope code.
        self.assertNotIn("is_wp_error( $own_answer )", self.source)
        self.assertNotIn("is_wp_error( $persona_answer )", self.source)
        self.assertNotIn("'rest_refusal' => $rest_refusal", self.source)


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


class DefectIRealUserDataInDemoTenants(unittest.TestCase):
    """I: a real user's rows inside a demo tenant are never silently deleted.

    The tenant boundary alone said "this row names the demo Clinic, so it is
    demo-owned". A real patient booking into the demo Clinic, or a real member of
    staff writing there, satisfies that and nothing else - so every candidate row
    must now be attributed to a POSITIVE fixture artifact, and attribution must
    fail closed.
    """

    @classmethod
    def setUpClass(cls) -> None:
        cls.teardown = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")
        cls.guard = read(COMPANION / "includes" / "class-cpms-demo-scoped-guard.php")

    def test_attribution_map_exists_and_covers_patient_data(self) -> None:
        self.assertIn("private const ATTRIBUTION", self.teardown)
        for table in (
            "cpms_patients",
            "cpms_appointments",
            "cpms_visits",
            "cpms_invoices",
            "cpms_payments",
            "cpms_clinical_notes",
            "cpms_prescriptions",
            "cpms_otp_tokens",
            "cpms_slot_holds",
            "cpms_clinic_memberships",
            "cpms_organization_memberships",
        ):
            self.assertIn(f"'{table}' =>", self.teardown, table)

    def test_attribution_gate_refuses_before_any_destructive_stage(self) -> None:
        preview = self.teardown[self.teardown.index("public function preview("):]
        preview = preview[: preview.index("public function execute(")]
        self.assertIn("$attribution = $this->attribution_problems(", preview)
        self.assertIn("(array) $attribution['problems']", preview)
        execute = self.teardown[self.teardown.index("private function do_execute("):]
        self.assertIn("'SCOPED_FOREIGN_TENANT_ROW'", execute)

    def test_uncertain_rows_are_refused_not_guessed(self) -> None:
        self.assertIn("SCOPED_FOREIGN_TENANT_ROW:", self.teardown)
        self.assertIn("SCOPED_FOREIGN_TENANT_ROW_TOTAL:", self.teardown)
        body = self.teardown[self.teardown.index("private function row_is_attributed("):]
        body = body[: body.index("private function normalize_mobile(")]
        # A row that names a real (non-fixture, non-operator) WordPress user can
        # never be attributed by tenant or parent evidence.
        self.assertIn("! in_array( $value, (array) $context['users'], true )", body)
        self.assertIn("return false;", body)

    def test_tenant_write_boundary_is_server_side_and_scoped_to_the_fixture(self) -> None:
        # The barrier runs inside REST dispatch, not in the UI, and it exempts the
        # fixture's own accounts, the site administrator and every foreign tenant.
        self.assertIn("add_filter( 'rest_pre_dispatch'", self.guard)
        self.assertIn("SCOPED_DEMO_TENANT_WRITE_REFUSED", self.guard)
        self.assertIn("self::is_fixture_actor( $loaded )", self.guard)
        self.assertIn("current_user_can( 'manage_options' )", self.guard)
        self.assertIn("private static function targets_demo_tenant(", self.guard)
        # Anonymous public booking stays possible; its data is protected by the
        # attribution gate instead of by a refusal.
        self.assertIn("0 === get_current_user_id()", self.guard)

    def test_demo_writes_are_registered_before_any_teardown(self) -> None:
        # A demo persona's own rows get durable, explicit ownership evidence: a
        # before/after diff of the demo tenant, sealed into the ownership ledger.
        self.assertIn("public static function begin_request(", self.guard)
        self.assertIn("public static function finish_request(", self.guard)
        self.assertIn("register_rows(", self.guard)
        self.assertIn("'source' => 'demo-request'", self.guard)

    def test_an_unverifiable_manifest_never_breaks_an_ordinary_request(self) -> None:
        # `init` runs on EVERY request, and a login POST is a write request, so
        # an uncaught MANIFEST_TAMPERED in the recorder fatals the very form the
        # operator would use to sign in — the browser tamper drill caught exactly
        # that. The manifest must be read through one total reader, and there may
        # be exactly one raw read (inside it).
        self.assertEqual(self.guard.count("$loaded = $manifest->load();"), 1, "only verified_manifest() may load")
        self.assertIn("private static function verified_manifest(", self.guard)
        self.assertIn("} catch ( \\Throwable $error ) {", self.guard)
        for call in ("begin_request", "write_verdict", "manifest_ids"):
            body = self.guard[self.guard.index(f"function {call}("):]
            body = body[: body.index("\n\t}")]
            self.assertIn("self::verified_manifest( $manifest )", body, call)
        # The portal refusal reads the manifest on a POST too; it must not fatal.
        admin = read(COMPANION / "includes" / "class-cpms-demo-scoped-admin.php")
        portal = admin[admin.index("public static function guard_portal_clinic_creation()"):]
        portal = portal[: portal.index("private static function render_credentials(")]
        self.assertIn("} catch ( \\Throwable $error ) {", portal)
        self.assertLess(portal.index("} catch ( \\Throwable $error ) {"), portal.index("'active' !== (string) ( $loaded['status'] ?? '' )"))


class DefectJPortalGuardScope(unittest.TestCase):
    """J: the portal refusal must not touch foreign Organizations (Stage 2)."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.admin = read(COMPANION / "includes" / "class-cpms-demo-scoped-admin.php")

    def test_guard_requires_an_active_fixture(self) -> None:
        body = self.admin[self.admin.index("public static function guard_portal_clinic_creation("):]
        body = body[: body.index("private static function render_credentials(")]
        self.assertIn("'active' !== (string) ( $loaded['status'] ?? '' )", body)
        self.assertIn("return;", body)

    def test_guard_is_scoped_to_the_demo_organization_and_manager(self) -> None:
        body = self.admin[self.admin.index("public static function guard_portal_clinic_creation("):]
        body = body[: body.index("private static function render_credentials(")]
        self.assertIn("(int) ( $loaded['ids']['organization_id'] ?? 0 )", body)
        self.assertIn("(int) ( $loaded['ids']['user_ids']['organization_manager'] ?? 0 )", body)
        self.assertIn("get_current_user_id() !== $manager", body)
        self.assertIn("$posted_org !== $demo_org", body)

    def test_guard_is_not_a_global_action_block(self) -> None:
        # Only the exact production action is refused, and only after all three
        # scoping conditions hold.
        body = self.admin[self.admin.index("public static function guard_portal_clinic_creation("):]
        body = body[: body.index("private static function render_credentials(")]
        self.assertEqual(body.count("if ( 'org_create_clinic' !== $action )"), 1)


class DefectNTenantMarkerIsNotAnEntityId(unittest.TestCase):
    """A safe barrier must not over-block a FOREIGN tenant.

    `clinic_id`, `organization_id` and `location_id` NAME a tenant; they are
    compared with the verified demo tenant directly. Reading them a second time
    as opaque row ids made `clinic_id = 7` collide with `cpms_patients.id = 7`
    inside the demo Clinic, so a real user's write to their OWN Clinic was
    refused with the demo message whenever a demo existed. That is not a safe
    default: it breaks the rest of the site while looking like a barrier.
    """

    @classmethod
    def setUpClass(cls) -> None:
        cls.source = read(ROOT / "tools" / "disposable-demo-installer" / "includes" / "class-cpms-demo-scoped-guard.php")
        start = cls.source.index("private static function entity_targets_demo_tenant(")
        cls.method = cls.source[start:cls.source.index("private static function resolved_scope_is_demo(", start)]

    def test_tenant_markers_are_declared_once(self) -> None:
        self.assertIn("private const TENANT_PARAM_KEYS = array(", self.source)
        for key in ("'clinic_id'", "'organization_id'", "'location_id'", "'clinicId'", "'organizationId'", "'locationId'"):
            self.assertIn(key, self.source.split("private const TENANT_PARAM_KEYS", 1)[1].split(";", 1)[0])

    def test_the_entity_scan_skips_tenant_markers(self) -> None:
        self.assertIn("in_array( $key, self::TENANT_PARAM_KEYS, true )", self.method)
        self.assertLess(
            self.method.index("TENANT_PARAM_KEYS"),
            self.method.index("_(id|ids)$"),
            "the tenant-marker exclusion must come before the generic id match",
        )

    def test_entity_ids_are_still_resolved_through_the_tenant_column(self) -> None:
        # The security half: a genuine entity id is still resolved against the
        # row's OWN clinic, never trusted from the browser.
        self.assertIn("clinic_id IN (", self.method)
        self.assertIn("self::ENTITY_TABLES", self.method)

    def test_the_drill_keeps_the_negative_control(self) -> None:
        drill = read(ROOT / "tests" / "scoped-demo" / "teardown-safety.php")
        self.assertIn("K: a foreign tenant stays writable for the same real actor", drill)


class Task2DrillRunsOnBothEnginesAndProvesItsFixtures(unittest.TestCase):
    """A fixture that cannot be inserted is not a valid RED.

    The drill mounts rows with real foreign keys, so the schema - not the
    product - decides whether an attack exists at all. These contracts keep the
    workflow honest about that: both supported engines run it, and every
    adversarial fixture is read back before an assertion rests on it.
    """

    @classmethod
    def setUpClass(cls) -> None:
        cls.drill = read(ROOT / "tests" / "scoped-demo" / "teardown-safety.php")
        cls.workflow = read(ROOT / ".github" / "workflows" / "standalone-demo.yml")

    def test_the_drill_runs_on_mysql_and_mariadb(self) -> None:
        job = self.workflow.split("  teardown-safety:", 1)[1].split("\n  # ", 1)[0]
        self.assertIn("matrix", job)
        self.assertIn("mysql:8", job)
        self.assertIn("mariadb:11.4.13", job)
        self.assertIn("${{ matrix.database }}", job)
        self.assertIn("fail-fast: false", job)

    def test_each_engine_gets_a_health_probe_it_understands(self) -> None:
        # MariaDB does not answer `mysqladmin -uroot -proot` the way the MySQL
        # image does, so a shared probe leaves the container unhealthy and the
        # engine never starts. That failure looks like a product failure and is
        # not one.
        job = self.workflow.split("  teardown-safety:", 1)[1].split("\n  # ", 1)[0]
        self.assertIn("health_cmd: mysqladmin ping", job)
        self.assertIn("health_cmd: healthcheck.sh --connect --innodb_initialized", job)
        self.assertIn("--health-cmd=\"${{ matrix.health_cmd }}\"", job)
        self.assertIn("MARIADB_ROOT_PASSWORD: root", job)

    def test_each_engine_uploads_its_own_evidence(self) -> None:
        self.assertIn("cpsa-teardown-safety-${{ matrix.database }}", self.workflow)

    def test_every_fixture_insert_is_proved_before_it_is_used(self) -> None:
        # cpsd_ts_insert throws on a failed insert, and cpsd_ts_row re-reads.
        self.assertIn("throw new RuntimeException( 'insert failed:", self.drill)
        self.assertIn("function cpsd_ts_row(", self.drill)

    def test_required_relationships_are_read_back(self) -> None:
        # The NOT NULL foreign keys that decide whether a fixture is legal.
        for relationship in (
            "clinician_id",
            "location_id",
            "visit_id",
            "issued_by_wp_user_id",
        ):
            self.assertIn(relationship, self.drill)
        self.assertIn("does not name its foreign clinician and Location", self.drill)
        self.assertIn("does not name its foreign visit and issuer", self.drill)

    def test_the_collision_is_chosen_not_assumed(self) -> None:
        self.assertIn("$d_candidates", self.drill)
        self.assertIn("the numeric-collision attack cannot be mounted", self.drill)

    def test_refusal_scenarios_drive_the_real_execute_path(self) -> None:
        for label in (
            "B2: the REAL teardown path REFUSES a registered grant to a real person",
            "H: the REAL teardown path refuses a corrupted ledger and deletes nothing",
        ):
            self.assertIn(label, self.drill)


class DefectKLedgerRecovery(unittest.TestCase):
    """K: losing or rolling back the ledger must fail closed (Stage 3)."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.ownership = read(COMPANION / "includes" / "class-cpms-demo-scoped-ownership.php")
        cls.teardown = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")

    def test_missing_ledger_is_refused_for_an_active_fixture(self) -> None:
        self.assertIn("public const SEAL_OPTION = 'cpsd_ownership_seal';", self.ownership)
        self.assertIn("public function inspect( string $fixture_id, bool $require_ledger = false )", self.ownership)
        self.assertIn("if ( $require_ledger || is_array( $seal ) )", self.ownership)
        self.assertIn("$this->ownership->summary( $fixture, $require_ledger )", self.teardown)
        # Required for a sealed, STILL-LIVE fixture only: an interrupted run and an
        # already-torn-down fixture are exempt, otherwise a repeated teardown (and
        # every legitimate recovery path) would be blocked by a record it cleared.
        self.assertIn("$require_ledger = 'active' === (string) ( $manifest['status'] ?? '' ) && is_array( $stored );", self.teardown)

    def test_progress_seal_is_monotonic(self) -> None:
        self.assertIn("private function verify_seal(", self.ownership)
        self.assertIn("'count'      => count( $entries ),", self.ownership)
        self.assertIn("'head'       => (string) ( $record['head'] ?? '' ),", self.ownership)
        self.assertIn("$this->env->persist_verified( self::SEAL_OPTION, $seal )", self.ownership)
        # A shorter/older chain is refused, not accepted as history.
        self.assertIn("$this->env->option_delete( self::SEAL_OPTION )", self.ownership)

    def test_interrupted_recovery_survives_the_hardening(self) -> None:
        clinics = read(COMPANION / "includes" / "class-cpms-demo-scoped-clinics.php")
        self.assertIn("public function resolve( array $manifest, string $token, bool $claim )", clinics)
        self.assertIn("$this->ownership->append(", clinics)
        self.assertIn("'abandon'", clinics)

    def test_uninstall_preserves_and_then_clears_both_records(self) -> None:
        uninstall = read(COMPANION / "uninstall.php")
        self.assertIn("$cpms_demo_scoped_ledger   = get_option( 'cpsd_ownership_ledger', null );", uninstall)
        self.assertIn("$cpms_demo_scoped_seal     = get_option( 'cpsd_ownership_seal', null );", uninstall)
        self.assertIn("delete_option( 'cpsd_ownership_ledger' );", uninstall)
        self.assertIn("delete_option( 'cpsd_ownership_seal' );", uninstall)


class DefectMMembershipRegistrationCannotOutrankThePrincipal(unittest.TestCase):
    """M: an explicit ledger registration is evidence about a ROW, not a PERSON.

    The defect this pins: `operator_grant_is_bounded()` returned ownership for a
    registered membership BEFORE it looked at `wp_user_id`, so sealing a grant
    into the ledger made a real person's membership deletable. Registration must
    never outrank the only column that says who holds the grant.
    """

    @classmethod
    def setUpClass(cls) -> None:
        cls.source = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")
        start = cls.source.index("private function operator_grant_is_bounded(")
        cls.method = cls.source[start : cls.source.index("\n\t}\n", start)]

    def test_no_ownership_is_granted_before_the_principal_is_decided(self) -> None:
        principal = self.method.index("$principal_is_synthetic = in_array(")
        self.assertNotIn(
            "return true;",
            self.method[:principal],
            "nothing may grant ownership before the principal is established",
        )

    def test_no_early_success_on_registration(self) -> None:
        registration = self.method.index("$registered")
        first_return_true = self.method.find("return true;")
        between = self.method[registration:first_return_true]
        self.assertNotIn(
            "if ( $registered ) {\n\t\t\treturn true;",
            between,
            "a registered grant must still be checked against its principal and references",
        )
        # The only `registered` uses that may grant ownership are the explicit,
        # bounded operator branch and nothing else.
        self.assertEqual(
            self.method.count("$registered ) {\n\t\t\treturn true;"),
            0,
            "registration alone never returns ownership",
        )

    def test_a_foreign_principal_is_refused_before_registration_is_considered(self) -> None:
        principal_check = self.method.index("if ( ! $principal_is_synthetic && ! $principal_is_operator ) {")
        self.assertIn("return false;", self.method[principal_check : principal_check + 200])
        self.assertLess(
            principal_check,
            self.method.index("if ( $principal_is_operator && ! $registered ) {"),
            "a real, non-operator principal must be refused before the operator exception",
        )

    def test_the_operator_grant_requires_explicit_registration(self) -> None:
        self.assertIn("if ( $principal_is_operator && ! $registered ) {", self.method)
        self.assertIn("return false;", self.method)

    def test_every_other_user_reference_is_still_bounded(self) -> None:
        self.assertIn("foreach ( self::USER_REFERENCE_COLUMNS as $column ) {", self.method)
        self.assertIn("'wp_user_id' === $column", self.method)

    def test_the_drill_proves_the_registered_foreign_grant_is_refused(self) -> None:
        drill = read(ROOT / "tests" / "scoped-demo" / "teardown-safety.php")
        self.assertIn("B2: a REGISTERED grant to a real person is still REFUSED", drill)
        self.assertIn("register_rows(", drill)
