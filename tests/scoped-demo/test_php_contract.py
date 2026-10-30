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
        self.assertIn("I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE", self.all_scoped)
        self.assertIn("REMOVE_ONLY_SCOPED_DEMO_DATA", self.all_scoped)
        self.assertIn("CREATE_SCOPED_DEMO_ON_TEST_SITE", self.all_scoped)
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
            self.admin.index("wp_safe_redirect( self::setup_page_url("),
        )
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
        route = self.admin[self.admin.index('data-cpdd-route="scoped"') : self.admin.index("private static function render_credentials")]
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


if __name__ == "__main__":
    unittest.main(verbosity=2)
