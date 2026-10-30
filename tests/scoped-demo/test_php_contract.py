#!/usr/bin/env python3
"""Static source contract for the scoped-demo implementation.

This is NOT runtime WordPress/database/browser acceptance. It only proves that
the shipped source preserves the strict mode and contains no forbidden broad
deletion or credential/OTP bypass markers.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
COMPANION = ROOT / "tools" / "disposable-demo-installer"
BOOTSTRAP = COMPANION / "cpms-disposable-demo.php"
SCOPED = sorted((COMPANION / "includes").glob("class-cpms-demo-scoped-*.php"))
README = COMPANION / "README.md"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


class ScopedPhpContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.bootstrap = read(BOOTSTRAP)
        cls.all_scoped = "\n".join(read(p) for p in SCOPED)
        cls.readme = read(README)

    def test_all_scoped_classes_are_shipped(self) -> None:
        expected = {
            "class-cpms-demo-scoped-manifest.php",
            "class-cpms-demo-scoped-audit-guard.php",
            "class-cpms-demo-scoped-baseline.php",
            "class-cpms-demo-scoped-preflight.php",
            "class-cpms-demo-scoped-request.php",
            "class-cpms-demo-scoped-otp.php",
            "class-cpms-demo-scoped-provisioner.php",
            "class-cpms-demo-scoped-teardown.php",
            "class-cpms-demo-scoped-admin.php",
            # Stage 1/2 of the ownership work: the sealed append-only ownership
            # ledger, and the only path that creates an additional demo Clinic.
            "class-cpms-demo-scoped-ownership.php",
            "class-cpms-demo-scoped-clinics.php",
            # Stage 1/2 of the tenant-safety work: the server-side tenant write
            # barrier and the recorder of demo-created rows.
            "class-cpms-demo-scoped-guard.php",
            # Recovery: the read-only verdict that decides whether an
            # interrupted provisioning run may be finished additively.
            "class-cpms-demo-scoped-recovery.php",
            # Owner access: the read-only authority matrix (production engine) and
            # the single-use ticket that opens ONE sealed persona's real session.
            "class-cpms-demo-scoped-preview.php",
            "class-cpms-demo-scoped-session.php",
        }
        actual = {p.name for p in SCOPED}
        self.assertEqual(expected, actual)
        for name in expected:
            self.assertIn("includes/" + name, self.bootstrap, name)

    def test_scoped_mode_is_registered_separately(self) -> None:
        self.assertIn("CpmsDemo\\\\ScopedAdmin', 'register_menu'", self.bootstrap)
        self.assertIn("CpmsDemo\\\\ScopedAdmin', 'enqueue_assets'", self.bootstrap)
        self.assertIn("public const PAGE   = 'cpms-scoped-demo';", self.all_scoped)
        self.assertIn("public const ACTION = 'cpsd_action';", self.all_scoped)

    def test_distinct_manifest_and_phrases(self) -> None:
        self.assertIn("cpsd_manifest", self.all_scoped)
        self.assertIn("cpsd_tombstone", self.all_scoped)
        # The typed phrase is reserved for the one irreversible action. Setup,
        # resume and rotation are additive and are protected by capability,
        # nonce and site fingerprint (see ScopedRequest::phrase_required()).
        self.assertIn("REMOVE_ONLY_SCOPED_DEMO_DATA", self.all_scoped)
        self.assertNotIn("I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE", self.all_scoped)
        self.assertNotIn("CREATE_SCOPED_DEMO_ON_TEST_SITE", self.all_scoped)
        self.assertNotIn("RESUME_SCOPED_DEMO_SEAL_STAGE", self.all_scoped)
        request = read(COMPANION / "includes" / "class-cpms-demo-request.php")
        self.assertIn("public function phrase_required( string $action ): bool", request)
        self.assertIn("'' !== $expected_phrase", request)
        self.assertIn("class ScopedRequest extends Request", self.all_scoped)
        self.assertIn("class ScopedPreflight extends Preflight", self.all_scoped)
        self.assertIn("class ScopedManifest extends Manifest", self.all_scoped)

    def test_no_forbidden_broad_deletion_or_bypass(self) -> None:
        forbidden = [
            r"TRUNCATE\s+TABLE",
            r"DELETE\s+FROM\s+[^\n;]*cpms_audit_logs",
            r"cpms_audit_logs[^\n]*DELETE",
            r"define\s*\(\s*['\"]CPMS_DEV_OTP_ECHO",
            r"DELETE\s+FROM[^\n]*WHERE\s+(created_at|request_id|ip_hash)\b",
            r"\$wpdb->query\s*\(\s*['\"]DELETE\s+FROM",
        ]
        for pattern in forbidden:
            self.assertIsNone(re.search(pattern, self.all_scoped, re.IGNORECASE), pattern)

    def test_global_invariants_are_documented_in_code_and_readme(self) -> None:
        for marker in (
            "never deletes",
            "audit-chain",
            "baseline",
            "shared/global",
            "GET_LOCK('cpms_jobs_tick'",
        ):
            self.assertIn(marker, self.all_scoped, marker)
        self.assertIn("Separate scoped mode", self.readme)
        self.assertIn("never deletes `cpms_audit_logs`", self.readme)

    def test_recovered_baseline_is_resealed_before_it_is_verified(self) -> None:
        """A manifest must never be mutated and then verified without re-sealing.

        Recovery adopts a baseline that the interrupted run never sealed into the
        manifest. Any such mutation invalidates the HMAC, so the payload has to be
        persisted again before `preview()` runs its `verify()` call - otherwise an
        otherwise-healthy recovery is refused as MANIFEST_TAMPERED.
        """

        teardown = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")
        self.assertIn("$manifest = $this->adopt_recovered_baseline( $manifest );", teardown)
        self.assertIn("private function adopt_recovered_baseline", teardown)
        # The adopted payload is re-sealed, so verify() sees a matching MAC.
        self.assertIn("return $this->manifest->save( $manifest );", teardown)
        # Adoption runs before the preview that re-verifies the manifest.
        execute = teardown.split("private function do_execute", 1)[1]
        self.assertLess(
            execute.index("adopt_recovered_baseline"),
            execute.index("$this->preview("),
            "recovery adoption must precede the preview that re-verifies the manifest",
        )
        # Nothing may assign a recovered baseline outside the adopting helper.
        self.assertEqual(
            1,
            len(re.findall(r"\$manifest\['baseline'\]\s*=\s*", teardown)),
            "exactly one place may install an adopted baseline",
        )

    def test_outcome_notices_carry_a_stable_identity_and_code(self) -> None:
        """A refusal must be addressable by identity, not by counting divs.

        Both admin pages legitimately show more than one status block (the
        build/mode banner and the outcome of the submitted action). The outcome
        therefore carries `data-cpdd-notice` and, for a refusal, the stable
        machine code, so an assertion (and an operator's support report) can name
        the exact notice instead of selecting "the first error on the page".
        """
        admin = read(COMPANION / "includes" / "class-cpms-demo-admin.php")
        scoped_admin = read(COMPANION / "includes" / "class-cpms-demo-scoped-admin.php")
        for name, source in (("strict", admin), ("scoped", scoped_admin)):
            with self.subTest(admin=name):
                self.assertIn('data-cpdd-notice="', source)
                self.assertIn('''data-cpdd-code="' . esc_attr( $code )''', source)
                self.assertIn("$code  = (string) ( self::$notice['code'] ?? '' );", source)
                # A refusal records its own code; an unmodelled throw is explicit.
                self.assertIn("'code' => $e->demo_code(),", source)
                self.assertIn("'code' => 'UNEXPECTED',", source)

    def test_no_raw_exception_text_reaches_either_admin_page(self) -> None:
        """Requirement I: Persian, no raw PHP exceptions in the UI."""
        for path in (
            COMPANION / "includes" / "class-cpms-demo-admin.php",
            COMPANION / "includes" / "class-cpms-demo-scoped-admin.php",
        ):
            source = read(path)
            unmodelled = source[source.index("catch ( \\Throwable $e )") :]
            unmodelled = unmodelled[: unmodelled.index("}") + 1]
            with self.subTest(file=path.name):
                self.assertNotIn("getMessage()", unmodelled)

    def test_the_browser_drill_selects_notices_by_identity(self) -> None:
        """The drill must not fall back to counting, in either direction.

        Counting can be satisfied by the wrong element (any extra error) and it
        cannot tell the banner from the action outcome, which is exactly how the
        two-notice page broke it. The count stays only as a closed set.
        """
        drill = read(COMPANION / "tests" / "browser-acceptance.py")
        self.assertIn("""[data-cpdd-notice="error"]""", drill)
        self.assertIn('data-cpdd-code', drill)
        self.assertIn("""[data-cpsd-standalone="fault"]""", drill)
        self.assertNotIn('locator(".notice-error").count() >= 1', drill)
        self.assertIn("""locator(".notice-error").count() == 2""", drill)
        self.assertIn('getComputedStyle(el).direction', drill)

    def test_strict_mode_classes_are_still_loaded(self) -> None:
        for name in (
            "class-cpms-demo-preflight.php",
            "class-cpms-demo-provisioner.php",
            "class-cpms-demo-teardown.php",
            "class-cpms-demo-admin.php",
        ):
            self.assertIn("includes/" + name, self.bootstrap, name)



class NonEmptySiteRoutingContract(unittest.TestCase):
    """The operator must be routed to the mode that fits the site.

    Reported defect: on an existing test site the only documented entry point was
    the strict (empty-site) page. Every one of its occupancy checks was red, the
    page offered no route to the scoped console, and its Persian instructions told
    the owner to build another empty WordPress. The scoped mode - written for
    exactly this site - was reachable only by guessing a Tools URL.
    """

    @classmethod
    def setUpClass(cls) -> None:
        cls.bootstrap = read(BOOTSTRAP)
        cls.admin = read(COMPANION / "includes" / "class-cpms-demo-admin.php")
        cls.preflight = read(COMPANION / "includes" / "class-cpms-demo-preflight.php")
        cls.scoped_preflight = read(COMPANION / "includes" / "class-cpms-demo-scoped-preflight.php")
        cls.scoped_admin = read(COMPANION / "includes" / "class-cpms-demo-scoped-admin.php")
        cls.namespace = read(COMPANION / "includes" / "class-cpms-demo-fixture-namespace.php")

    def test_activation_lands_on_the_right_console(self) -> None:
        self.assertTrue("Admin::mark_landing( get_current_user_id() )" in self.bootstrap, "activation does not arm the landing")
        self.assertTrue(
            "add_action( 'admin_init', array( 'CpmsDemo\\\\Admin', 'maybe_land_after_activation' ), 1 )" in self.bootstrap,
            "the landing hook is not registered on admin_init",
        )
        self.assertTrue("public static function maybe_land_after_activation" in self.admin, "landing hook missing")
        # One-shot and non-destructive: consumed before the redirect, GET only.
        self.assertLess(
            self.admin.index("delete_transient( self::LANDING_TRANSIENT );\n\t\tif ( headers_sent() )"),
            self.admin.index("wp_safe_redirect( Dashboard::page_url() );"),
        )
        # The landing never hard-codes a pagenow: the dashboard is a CPMS child
        # menu when that menu exists and a Tools page when it does not, so only
        # its own resolver knows which URL this site serves.
        landing = self.admin[self.admin.index("public static function maybe_land_after_activation") :]
        landing = landing[: landing.index("public static function enqueue_assets")]
        self.assertNotIn("admin.php?page=", landing)
        # The landing stores no mode decision: it always opens the dashboard,
        # which re-reads live state on the request that follows.
        landing = self.admin[self.admin.index("public static function maybe_land_after_activation") :]
        landing = landing[: landing.index("public static function enqueue_assets")]
        self.assertNotIn("setup_page_url(", landing)
        self.assertTrue("'GET' !== strtoupper(" in self.admin, "the landing accepts non-GET requests too")
        self.assertTrue("wp_doing_ajax" in self.admin, "the landing is not excluded from AJAX")
        self.assertTrue("current_user_can( self::CAP )" in self.admin, "the landing does not re-check the capability")

    def test_routing_target_is_decided_from_live_state_only(self) -> None:
        self.assertTrue("public static function setup_target" in self.admin, "setup_target missing")
        self.assertTrue(
            "Preflight::occupancy( (array) $state['checks'], $platform->available() )" in self.admin,
            "the routing target is not derived from the real preflight",
        )
        # A recorded strict fixture is managed on the strict page whatever else the site holds.
        self.assertTrue("! $manifest->exists()" in self.admin, "a recorded strict fixture must keep the strict page")
        # No request input may select the target page.
        target = self.admin[self.admin.index("public static function setup_target") : self.admin.index("public static function setup_page_url")]
        for forbidden in ("$_GET", "$_POST", "$_REQUEST"):
            self.assertFalse(forbidden in target, forbidden)

    def test_occupancy_is_a_pure_function_of_the_real_preflight(self) -> None:
        self.assertTrue("public const OCCUPANCY_CHECKS = array( 'plugins', 'users', 'content' );" in self.preflight, "occupancy input set changed")
        self.assertTrue("public static function occupancy( array $checks, bool $cpms_available ): array" in self.preflight, "occupancy is not a pure function")
        # An unreadable CPMS core is a compatibility failure, never proof of a populated site.
        self.assertTrue(
            "if ( $cpms_available ) {" in self.preflight and "$considered[] = 'cpms_data';" in self.preflight,
            "cpms_data must be considered only when the core is readable",
        )

    def test_strict_page_offers_the_scoped_route(self) -> None:
        self.assertTrue('data-cpdd-route="scoped"' in self.admin, "no route card")
        self.assertTrue('data-cpdd-goto-scoped="1"' in self.admin, "no route button")
        self.assertTrue("self::SCOPED_PAGE" in self.admin, "the route button does not use the shared slug")
        self.assertTrue("public const SCOPED_PAGE = 'cpms-scoped-demo';" in self.admin, "slug constant missing")
        # The duplicated slug must not drift from its owner.
        self.assertTrue("public const PAGE   = 'cpms-scoped-demo';" in self.scoped_admin, "the two slugs drifted")

    def test_strict_instructions_stop_asking_for_another_wordpress(self) -> None:
        self.assertTrue(
            "private static function render_instructions( bool $cpms_menu_available, bool $occupied = false )" in self.admin,
            "instructions do not depend on occupancy",
        )
        self.assertTrue("$first_step = $occupied" in self.admin, "the first step is not occupancy-aware")
        # The empty-site sentence must still exist for an actually empty install.
        self.assertTrue("(\u062a\u06a9\u200c\u0633\u0627\u06cc\u062a\u06cc\u060c HTTPS)" in self.admin,
                        "the empty-site first step was removed")
        self.assertTrue("\u0647\u06cc\u0686 \u0633\u0627\u06cc\u062a \u062a\u0627\u0632\u0647" in self.admin,
                        "the populated-site first step is missing")

    def test_scoped_namespace_check_can_never_block(self) -> None:
        """The prefix is not a collision; an exact collision is, and it is checked
        at provisioning time. The row must therefore always be `pass`."""
        self.assertTrue(
            "public static function namespace_result( array $survey ): array" in self.scoped_preflight,
            "namespace_result missing",
        )
        window = self.scoped_preflight[
            self.scoped_preflight.index("public static function namespace_result") : self.scoped_preflight.index("private function check_queue_lock")
        ]
        self.assertTrue("'status' => 'pass'" in window, "the namespace row is not an unconditional pass")
        self.assertFalse("'fail'" in window, "the namespace row can still fail")
        # The old blanket-prefix refusals must be gone for good.
        for old_shape in (
            "\u06cc\u06a9 \u062d\u0633\u0627\u0628 \u0628\u0627 \u067e\u06cc\u0634\u0648\u0646\u062f cpsd- \u0627\u0632 \u0642\u0628\u0644",
            "\u06cc\u06a9 slug \u0641\u0639\u0627\u0644 \u0628\u0627 \u067e\u06cc\u0634\u0648\u0646\u062f cpsd-",
        ):
            self.assertFalse(old_shape in self.scoped_preflight, "the blanket-prefix refusal is back")
        # And the check still reads live state through the shared survey.
        self.assertTrue(
            "FixtureNamespace::survey( $this->env, $this->platform )" in self.scoped_preflight,
            "the check no longer reads live state",
        )

    def test_namespace_survey_is_read_only(self) -> None:
        for forbidden in (
            "delete_user",
            "wp_delete_user",
            "delete_post",
            "wp_delete_post",
            "option_set",
            "option_delete",
            "insert_row",
            "delete_where",
            "->delete(",
            "wp_update_user",
            "wp_insert_user",
        ):
            self.assertNotIn(forbidden, self.namespace, f"{forbidden} must not appear in the read-only survey")

    def test_namespace_survey_is_shipped_before_it_is_used(self) -> None:
        self.assertTrue("includes/class-cpms-demo-fixture-namespace.php" in self.bootstrap, "the survey is not shipped")
        self.assertLess(
            self.bootstrap.index("includes/class-cpms-demo-fixture-namespace.php"),
            self.bootstrap.index("includes/class-cpms-demo-preflight.php"),
        )

    def test_scoped_page_reports_preserved_residue_without_an_action(self) -> None:
        self.assertTrue("private static function render_preserved_namespace" in self.scoped_admin, "preserved card missing")
        self.assertTrue("self::render_preserved_namespace( $env, $platform );" in self.scoped_admin, "preserved card not wired")
        self.assertTrue('data-cpsd-preserved-namespace=' in self.scoped_admin, 'preserved card has no identity')
        window = self.scoped_admin[
            self.scoped_admin.index("private static function render_preserved_namespace") : self.scoped_admin.index("private static function render_preflight")
        ]
        self.assertTrue("حذف نمی‌شوند" in window, "the card does not say the rows are preserved")
        self.assertTrue("اثبات مالکیت" in window, "the card does not say a name is not proof")
        # The card is informational: no form, no button, no action constant.
        for forbidden in ("<form", "<button", "ScopedRequest::ACTION_", "data-cpsd-form"):
            self.assertNotIn(forbidden, window)

    def test_strict_residue_row_names_a_scoped_leftover(self) -> None:
        self.assertTrue("FixtureNamespace::survey( $this->env, null )" in self.preflight, "the strict residue row does not name scoped leftovers")
        window = self.preflight[self.preflight.index("private function check_manifest_state") :]
        self.assertTrue("بقایای «حالت محدودشده»" in window, "the scoped leftover is not named")
        self.assertTrue("FixtureNamespace::counts_fa( $survey )" in window, "the counts are not rendered")
        # The scoped survey is passed a null platform here: an inactive CPMS must
        # not be queried by the strict residue row.
        self.assertFalse("$this->platform" in window, "an inactive CPMS must not be queried by the residue row")

    def test_single_clinic_gate_is_named_where_the_operator_looks(self) -> None:
        """A single-Clinic host needs a typed acceptance, not a greener table."""
        self.assertTrue(
            "private static function render_setup_summary( ScopedManifest $manifest, array $state, ?array $loaded, string $error, int $clinics = 0 )"
            in self.scoped_admin,
            "the summary card is not clinic-aware",
        )
        self.assertTrue(
            "self::render_setup_summary( $manifest, $state, $loaded, $manifest_error, $clinic_count );" in self.scoped_admin,
            "the clinic count is not passed to the summary",
        )
        self.assertLess(
            self.scoped_admin.index("$clinic_count = (int) $platform->count_all( 'cpms_clinics' );"),
            self.scoped_admin.index("self::render_setup_summary("),
            "the count is read after the card that needs it",
        )
        self.assertTrue('data-cpsd-private-host-hint="1"' in self.scoped_admin, "nothing points at the confirmation")
        self.assertTrue(
            "$needs_private_host && array( 'host_scope' ) === $blocking_id" in self.scoped_admin,
            "the headline does not name the single-Clinic blocker",
        )
        # The gate itself is unchanged: only the sealed acceptance passes it, and
        # the consent card still renders for a single-Clinic host alone.
        self.assertTrue("if ( 1 !== $clinics ) {" in self.scoped_admin, "the consent card is no longer single-Clinic only")
        self.assertTrue("$manifest->private_host_consent()" in self.scoped_admin, "the sealed acceptance is no longer read")
        # The clean-site page names the same confirmation before sending the owner on.
        route = self.admin[self.admin.index('data-cpdd-route="scoped"') : self.admin.index("function render_credentials(")]
        self.assertTrue("\u062a\u06a9\u200c\u062f\u0631\u0645\u0627\u0646\u06af\u0627\u0647\u06cc" in route,
                        "the route card does not mention the single-Clinic confirmation")

    def test_preexisting_cpsd_accounts_are_proved_preserved(self) -> None:
        """Eight pre-existing `cpsd-` accounts are the Owner's real condition.

        A login prefix is not ownership: the drill must seed them, digest them
        (login, e-mail, roles, password hash) and prove they survive provisioning,
        teardown and plugin deletion - and the browser must see them reported.
        """
        driver = read(ROOT / "tests" / "scoped-demo" / "standalone-acceptance.php")
        browser = read(ROOT / "tests" / "scoped-demo" / "browser_install.py")
        self.assertTrue("$cpsd_sa_legacy_digest" in driver, "the drill does not digest the pre-existing accounts")
        self.assertTrue("'cpsd-legacy-' . str_pad(" in driver, "the drill does not seed pre-existing cpsd- accounts")
        self.assertTrue("'user_email'   => $login . '@example.invalid'" in driver,
                        "the seeded accounts must not carry one of this package's own e-mail domains")
        self.assertTrue("pre-existing cpsd- accounts are byte-identical (login, e-mail, roles, password hash)" in driver,
                        "assert mode does not prove the accounts are untouched")
        self.assertTrue("the eight pre-existing cpsd- accounts survived deactivation and deletion" in driver,
                        "after_delete does not prove the accounts survive the plugin lifecycle")
        for forbidden in ("wp_delete_user", "delete_user", "wp_update_user", "set_role("):
            self.assertNotIn(forbidden, driver, f"{forbidden} must never be used on a pre-existing account")
        self.assertTrue('data-cpsd-preserved-namespace' in browser, "the browser does not read the preserved card")
        self.assertTrue('data-cpsd-preserved-user=' in browser, "the browser does not list a preserved account")
        self.assertTrue("page=cpms-scoped-demo" in browser, "the browser does not follow the routing button")

    def test_browser_drill_proves_the_populated_site_route(self) -> None:
        browser = read(ROOT / "tests" / "scoped-demo" / "browser_install.py")
        self.assertTrue('data-cpdd-route="scoped"' in browser, "the browser does not assert the route card")
        self.assertTrue('data-cpdd-goto-scoped="1"' in browser, "the browser does not click the routing button")
        self.assertTrue('data-cpsd-page="1"' in browser, "the browser does not assert the scoped console")
        self.assertTrue('has_text="\u0641\u0636\u0627\u06cc \u0646\u0627\u0645 \u062f\u0645\u0648"' in browser,
                        "the browser does not assert the namespace preflight row")
        # The Plugins-screen assertion must survive one-shot activation redirects.
        self.assertTrue("for _ in range(4):" in browser, "the Plugins screen is asserted on a redirect response")
        # And it must never assert success for a page it did not reach.
        self.assertTrue('if "plugins.php" in page.url:' in browser, "the retry does not check where it landed")



class OwnerIncidentRepairContract(unittest.TestCase):
    """Static contract for the repair path added after the Owner incident.

    It proves only that the shipped source keeps the two safety properties that
    the runtime drills in `tests/scoped-recovery.php` exercise against a real
    database: a failed run never discards durable evidence, and the additive
    repair never fabricates a seal.
    """

    # -- The Owner incident: a failed run must never destroy its own evidence --

    def test_failure_record_merges_durable_evidence_instead_of_overwriting_it(self) -> None:
        """The stale-snapshot overwrite is the defect; it must stay fixed.

        `Provisioner::do_provision()` catches after `after_provision()` has
        already written the sealed payload on disk. Saving the failing stage's
        local `$manifest` over that record is what erased the sealed baseline and
        the ownership map of a real install while its eight synthetic accounts
        stayed alive.
        """
        provisioner = read(COMPANION / "includes" / "class-cpms-demo-provisioner.php")
        self.assertIn("$this->record_failure( $manifest, $stage, $error );", provisioner)
        self.assertNotIn("$this->manifest->save( $manifest );\n\t\t\tif ( $error instanceof", provisioner)
        self.assertIn("protected function record_failure(", provisioner)
        self.assertIn("protected function preserve_evidence(", provisioner)
        merge = provisioner.split("protected function preserve_evidence(", 1)[1]
        for evidence in ("'baseline'", "'owned'", "'audit_chain_at_seal'", "'host_before'"):
            self.assertIn(evidence, merge, evidence)
        # The handler must re-read the durable record, not write over it blind.
        handler = provisioner.split("protected function record_failure(", 1)[1]
        self.assertIn("$this->manifest->load()", handler)
        # A record that fails verification is evidence of a tamper: it must not
        # be replaced by a failure record.
        self.assertIn("return;", handler)

    def test_pending_baseline_is_dropped_only_after_the_portal_policy(self) -> None:
        """Two writers of the same evidence must be ordered by durability.

        `enforce_portal_clinic_policy()` is the call that can throw after the
        seal is durable. Dropping the pending copy before it would leave that
        failure with no baseline anywhere.
        """
        provisioner = read(COMPANION / "includes" / "class-cpms-demo-scoped-provisioner.php")
        body = provisioner.split("private function seal_fixture(", 1)[1]
        body = body.split("private function enforce_portal_clinic_policy(", 1)[0]
        policy = body.find("$this->enforce_portal_clinic_policy( $manifest );")
        clear = body.find("$this->scoped_manifest->clear_pending_baseline();")
        self.assertNotEqual(-1, policy)
        self.assertNotEqual(-1, clear)
        self.assertLess(policy, clear)
        # And the seal must be read back before the pending copy is dropped.
        self.assertLess(body.find("$this->scoped_manifest->save( $manifest );"), policy)
        self.assertIn("$reloaded = $this->scoped_manifest->load();", body)

    def test_parked_fixture_archive_is_sealed_and_never_deleted(self) -> None:
        """Parking is not deletion: the archive is the only surviving evidence."""
        manifest = read(COMPANION / "includes" / "class-cpms-demo-scoped-manifest.php")
        self.assertIn("public const PRESERVED_OPTION = 'cpsd_preserved';", manifest)
        preserve = manifest.split("public function preserve(", 1)[1]
        self.assertIn("MANIFEST_TAMPERED", preserve)
        self.assertIn("persist_verified( self::PRESERVED_OPTION", preserve)
        # The live pointers are released only after the archive write returned.
        self.assertLess(
            preserve.find("$this->write_preserved( $archive );"),
            preserve.find("parent::delete();"),
        )
        writer = manifest.split("private function write_preserved(", 1)[1]
        self.assertIn("persist_verified( self::PRESERVED_OPTION", writer)
        self.assertIn("OPTION_WRITE_FAILED", writer)
        self.assertIn("PRESERVED_INVALID", manifest)
        uninstall = read(COMPANION / "uninstall.php")
        self.assertIn("cpsd_preserved", uninstall)
        self.assertNotIn("delete_option( 'cpsd_preserved' )", uninstall)
        baseline = read(COMPANION / "includes" / "class-cpms-demo-scoped-baseline.php")
        self.assertIn("'cpsd_preserved'", baseline)
        teardown = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")
        self.assertNotIn("cpsd_preserved", teardown)

    def test_activation_is_additive_and_never_fabricates_a_seal(self) -> None:
        """The repaired state must stay distinguishable from a real seal.

        An activation may mark the fixture active, but it may not invent the
        ownership map, the audit-chain snapshot or the baseline: every gate that
        requires a seal has to keep refusing.
        """
        provisioner = read(COMPANION / "includes" / "class-cpms-demo-scoped-provisioner.php")
        activate = provisioner.split("public function activate(", 1)[1]
        activate = activate.split("public function launch(", 1)[0]
        self.assertIn("$payload['activation']", activate)
        self.assertIn("'baseline_unavailable'", activate)
        for fabricated in ("['owned']", "['audit_chain_at_seal']", "['baseline'] ="):
            self.assertNotIn(fabricated + " =", activate, fabricated)
        self.assertNotIn("create_user", activate)
        self.assertNotIn("delete_user", activate)
        self.assertIn("CPSD_DEMO_ACTIVATED_WITHOUT_BASELINE", activate)
        # Activation is gated on the read-only assessment, inside its own lock.
        self.assertIn("ScopedRecovery::VERDICT_USABLE", activate)
        self.assertIn("with_lock(", activate)

    def test_the_one_action_is_a_first_class_request_action(self) -> None:
        """One obvious button, no typed phrase, no new authority."""
        request = read(COMPANION / "includes" / "class-cpms-demo-scoped-request.php")
        self.assertIn("public const ACTION_LAUNCH = 'launch';", request)
        self.assertIn("public const ACTION_FRESH_DEMO = 'fresh_demo';", request)
        launch = request.split("self::ACTION_LAUNCH", 1)[1].split("),", 1)[0]
        self.assertIn("'phrase' => ''", launch)
        for page in ("class-cpms-demo-dashboard.php", "class-cpms-demo-scoped-admin.php"):
            source = read(COMPANION / "includes" / page)
            self.assertIn("ScopedRequest::ACTION_LAUNCH", source, page)
            self.assertIn("ScopedRequest::ACTION_FRESH_DEMO", source, page)
            self.assertIn("$provisioner->launch( $admin_id )", source, page)
            self.assertIn("$provisioner->preserve_and_reprovision( $admin_id )", source, page)

    # -- Passwordless persona entry: a session hand-off, never impersonation ----

    def test_persona_entry_is_scoped_to_the_sealed_fixture(self) -> None:
        """The target comes from the sealed manifest, never from a name pattern."""
        session = read(COMPANION / "includes" / "class-cpms-demo-scoped-session.php")
        self.assertIn("$users[ $persona ] ?? 0", session)
        self.assertIn("'cpsd-' . $persona . '-' . $fixture", session)
        self.assertIn("in_array( $persona, Credentials::PERSONA_ORDER, true )", session)
        # A demo-looking login is never enough on its own.
        self.assertIn("PERSONA_NOT_SEALED", session)
        self.assertNotIn("get_user_by(", session)
        self.assertNotIn("str_starts_with( $login, 'cpsd-' )", session)

    def test_persona_entry_uses_the_wordpress_session_primitive_only(self) -> None:
        session = read(COMPANION / "includes" / "class-cpms-demo-scoped-session.php")
        self.assertIn("$this->env->set_auth_cookie( $target['user_id'] )", session)
        for forbidden in (
            "add_cap(",
            "add_role(",
            "set_role(",
            "wp_set_current_user(",
            "$_SESSION",
            "setcookie(",
            "wp_set_password(",
        ):
            self.assertNotIn(forbidden, session, forbidden)
        env = read(COMPANION / "includes" / "class-cpms-demo-env.php")
        # Exactly one auth-cookie writer in the whole package.
        self.assertEqual(1, env.count("wp_set_auth_cookie("))
        for path in SCOPED + sorted((COMPANION / "includes").glob("class-cpms-demo-*.php")):
            if path.name == "class-cpms-demo-env.php":
                # The single, reviewed writer of the authentication cookie.
                continue
            self.assertNotIn("wp_set_auth_cookie(", read(path), path.name)
            self.assertNotIn("wp_logout(", read(path), path.name)

    def test_persona_entry_ticket_is_single_use_sealed_and_short_lived(self) -> None:
        session = read(COMPANION / "includes" / "class-cpms-demo-scoped-session.php")
        self.assertIn("public const TTL = 300;", session)
        # Consumed before any other check can fail, so a replay never finds it.
        redeem = session.split("public function redeem(", 1)[1].split("public function current(", 1)[0]
        self.assertLess(
            redeem.find("$this->env->option_delete( self::TICKET_OPTION );"),
            redeem.find("$this->verify( $raw )"),
        )
        self.assertLess(
            redeem.find("$this->env->option_delete( self::TICKET_OPTION );"),
            redeem.find("$this->env->set_auth_cookie("),
        )
        self.assertIn("hash_equals(", redeem)
        self.assertIn("ENTRY_TOKEN_EXPIRED", redeem)
        self.assertIn("$this->manifest->load()", redeem)
        # The token is stored only as a digest.
        self.assertIn("'token_hash' => hash( 'sha256', $token )", session)
        # The redirect comes from the sealed fixture, never from the request.
        self.assertIn("'redirect' => $target['portal']", redeem)
        self.assertNotIn("$_GET['redirect", session)
        self.assertNotIn("$_REQUEST", session)

    def test_persona_entry_refuses_administrator_targets(self) -> None:
        session = read(COMPANION / "includes" / "class-cpms-demo-scoped-session.php")
        self.assertIn("$user_id === $admin_id", session)
        self.assertIn("PERSONA_NOT_SAFE", session)
        self.assertIn("'manage_options'", session)

    def test_persona_session_boundary_is_derived_from_sealed_state(self) -> None:
        session = read(COMPANION / "includes" / "class-cpms-demo-scoped-session.php")
        current = session.split("public function current(", 1)[1].split("public static function reset_cache", 1)[0]
        self.assertIn("$this->manifest->load()", current)
        self.assertNotIn("$_COOKIE", current)
        self.assertNotIn("$_GET", current)
        # The visible boundary and the way back are part of the shipped surface.
        self.assertIn("cpsd-demo-persona-session", session)
        self.assertIn("admin_bar_menu", session)
        self.assertIn("'cpsd_persona_exit'", session)

    def test_the_dashboard_offers_eight_buttons_and_changes_no_capability(self) -> None:
        for page in ("class-cpms-demo-dashboard.php", "class-cpms-demo-scoped-admin.php"):
            source = read(COMPANION / "includes" / page)
            self.assertIn("ScopedRequest::ACTION_PERSONA_ENTRY", source, page)
            self.assertNotIn("add_cap(", source, page)
        admin = read(COMPANION / "includes" / "class-cpms-demo-scoped-admin.php")
        launcher = admin.split("public static function render_persona_launcher(", 1)[1]
        launcher = launcher.split("public static function render_persona_access(", 1)[0]
        self.assertIn("Credentials::PERSONA_ORDER", launcher)
        self.assertIn("data-cpsd-open-persona=", launcher)
        self.assertIn("data-cpsd-cookie-note", launcher)
        # The single-use secret is never rendered: the request answers with a redirect.
        self.assertNotIn("$ticket['token']", launcher)
        dashboard = read(COMPANION / "includes" / "class-cpms-demo-dashboard.php")
        self.assertIn("wp_safe_redirect( $ticket['url'] );", dashboard)

    def test_the_authority_matrix_asks_production_and_grants_nothing(self) -> None:
        preview = read(COMPANION / "includes" / "class-cpms-demo-scoped-preview.php")
        self.assertIn("$this->platform->clinical_can(", preview)
        self.assertIn("$this->platform->production_can(", preview)
        for forbidden in ("add_cap(", "user_can( $", "current_user_can("):
            self.assertNotIn(forbidden, preview, forbidden)
        platform = read(COMPANION / "includes" / "class-cpms-demo-platform.php")
        self.assertIn("App::authorization_service()->can(", platform)
        self.assertIn("App::organization_clinic_provisioning_service()->can(", platform)

    def test_the_demo_never_forks_production_business_logic(self) -> None:
        """The demo must consume the production product, never reimplement it.

        This is the requirement "never maintain a duplicated or simulated
        implementation of production features inside the demo", checked on the
        shipped surface rather than trusted. The tokenizer check with a positive
        control lives in `scoped-run-tests.php` section (h); here the question is
        whether the demo *delegates* to the same engines production uses.
        """
        platform = read(COMPANION / "includes" / "class-cpms-demo-platform.php")
        body = platform.split("function clinical_can(", 1)[1].split("\n\t}", 1)[0]
        self.assertIn("App::authorization_service()->can(", body)
        # No local shortcut can answer *yes* before production: every early return
        # is a refusal, and the only way to obtain `true` is the delegation itself.
        returns = [line.strip() for line in body.splitlines() if line.strip().startswith("return ")]
        self.assertGreaterEqual(len(returns), 1, returns)
        self.assertIn("App::authorization_service()->can(", returns[-1], returns)
        for early in returns[:-1]:
            self.assertEqual("return false;", early, f"an early return may only refuse, got {early}")
        tenant = platform.split("function production_can(", 1)[1].split("\n\t}", 1)[0]
        self.assertIn("App::organization_clinic_provisioning_service()->can(", tenant)
        self.assertIn("return", tenant)
        # Authority is never fabricated anywhere in the shipped demo.
        shipped = [COMPANION / "cpms-disposable-demo.php"] + sorted((COMPANION / "includes").glob("*.php"))
        self.assertGreater(len(shipped), 25)
        for path in shipped:
            source = read(path)
            for primitive in ("add_cap(", "remove_cap(", "add_role(", "remove_role(", "WP_Roles"):
                self.assertNotIn(primitive, source, f"{path.name} must not create authority")

    def test_the_demo_capability_map_still_exists_in_the_production_source(self) -> None:
        """A capability rename in production must fail here, not render a matrix
        that confidently asks about a permission the product no longer has."""
        preview = read(COMPANION / "includes" / "class-cpms-demo-scoped-preview.php")
        demo_caps = set(re.findall(r"'(cpms_[a-z0-9_]+)'", preview))
        self.assertEqual(
            {"cpms_patient_read", "cpms_finance_read", "cpms_visit_read", "cpms_org_clinic_update"},
            demo_caps,
        )
        production = "\n".join(
            path.read_text(encoding="utf-8")
            for path in sorted((ROOT / "clinic-practice-management" / "src").rglob("*.php"))
        )
        production_caps = set(re.findall(r"'(cpms_[a-z0-9_]+)'", production))
        self.assertEqual(set(), demo_caps - production_caps,
                         "the demo asks about capabilities production does not define")

    def test_the_new_owner_features_use_production_services_not_copies(self) -> None:
        """Recovery and persona navigation are new demo surfaces; they must reach
        the product through its services and WordPress primitives, never through a
        second implementation of clinical, financial or tenant logic."""
        session = read(COMPANION / "includes" / "class-cpms-demo-scoped-session.php")
        # No SQL in the hand-off at all: it establishes a session, it does not
        # re-derive permissions from tables.
        for primitive in ("$wpdb", "get_users(", "wp_set_current_user(", "set_role("):
            self.assertNotIn(primitive, session, primitive)
        self.assertIn("set_auth_cookie(", session)
        console = read(COMPANION / "includes" / "class-cpms-demo-scoped-admin.php")
        self.assertIn("ScopedRequest::ACTION_PERSONA_ENTRY", console)
        self.assertNotIn("current_user_can( 'cpms_", console)



if __name__ == "__main__":
    unittest.main(verbosity=2)
