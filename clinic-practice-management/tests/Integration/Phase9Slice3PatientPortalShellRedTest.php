<?php

/**
 * Phase 9 Slice 3 — Independent CPMS Patient Portal Shell (TEST-ONLY RED).
 *
 * ===========================================================================
 * SCOPE (owner-directed Slice 3 — smallest production transition)
 * ===========================================================================
 *
 * Transition the pure-patient destination from the wp-admin Patient console
 * (`PatientPortalPage`, slug `cpms-patient`) to an independent CPMS frontend
 * Patient Portal shell, per the owner-issued architecture contract:
 *
 *   docs/decisions/2026-09-21-phase9-patient-portal-owner-policy.md
 *
 * Contract name (owner quote):
 *   "Three Environments + One CPMS Core + Shared WordPress Authentication
 *    Foundation + Independent CPMS Portals + Hybrid Rendering + Server-side
 *    Authorization."
 *
 * Production presentation target established by PR #98 (proof ONLY — merged
 * on main; NOT product code):
 *   WordPress Page
 *   + plugin-owned `template_include` interception
 *   + plugin-owned standalone full-document CPMS template.
 *
 * This RED references that merged mechanism. It does NOT invent a second
 * theme-independence framework and does NOT treat fixture filenames
 * (`cpms-shell-proof`, proof themes, …) as product requirements.
 *
 * NOT in scope (NOT implemented, NOT asserted as product code here):
 *   - any production Patient Portal shell/page/template (GREEN);
 *   - Staff Portal;
 *   - Profile / Visits / Prescriptions / Files sections;
 *   - migration 0023 (latest on disk remains 0022; none is reserved);
 *   - any new REST endpoint;
 *   - any new authentication/token/session system (browser stays on the
 *     WordPress session + `wp_rest` nonce);
 *   - Android/iOS authentication;
 *   - pixel-perfect visual design (owner visual approval is a later GREEN
 *     browser-evidence step);
 *   - speculative performance suites beyond the structural asset-scoping guard.
 *
 * ===========================================================================
 * LIVE STATE AT RED AUTHORING (independently fetched, 2026-09-21)
 * ===========================================================================
 *
 * - authoritative main = 352a093649265063fc055585fed7bacafbb37453
 *   (Merge pull request #98 — architecture decision + standalone-shell proof)
 * - open PRs = 0
 * - workspace = clean (tracked + untracked: none)
 * - latest migration on disk = 2026_09_20_0022_slot_holds_patient_binding.php
 *   (no 0023 exists; none is created or reserved by this RED)
 * - Phase 9 = IN PROGRESS; Slices 1–2 = CLOSED; PR #98 architecture/proof = CLOSED
 * - no Phase9Slice3* product or test file existed on main before this RED
 * - PatientPortalPage today:
 *     pageUrl()            → admin.php?page=cpms-patient
 *     redirectAfterLogin() → pageUrl() (wp-admin console)
 *     guardWpAdmin()       → early-return keeps pure patients on page=cpms-patient;
 *                            other wp-admin GETs redirect to that same console
 *     render()             → appointments + self-cancel + notifications + config
 *                            (Slices 1–2) inside wp-admin chrome
 * - no production frontend Patient Portal entry/shell exists
 *
 * ===========================================================================
 * SLICE 3 CONTRACT (what GREEN must satisfy — defined by this file)
 * ===========================================================================
 *
 *  S1  FRONTEND PATIENT PORTAL ENTRY + INDEPENDENT CPMS SHELL
 *      For a real pure `cpms_patient` user a production frontend entry exists
 *      (not under /wp-admin/). Requesting it with the normal WordPress session:
 *        - CPMS owns the full HTML document (`<!DOCTYPE html>` … `</html>`);
 *        - active Theme header/footer markers are absent (positive control:
 *          the #98 control themes still emit their markers when rendered alone);
 *        - wp-admin chrome is absent (no wpadminbar / adminmenu / wpfooter);
 *        - a stable CPMS Patient Portal root marker is present;
 *        - structural landmarks may include: independent CPMS header, patient
 *          navigation landmark, main content landmark, footer; document is RTL;
 *        - existing accepted Patient Portal content remains available inside the
 *          shell (appointments section + notifications section);
 *        - existing REST config/nonce (`cpms-patient-portal__config` with
 *          rest_root + nonce verifying as `wp_rest`) remains usable;
 *        - no Staff/Admin navigation is exposed;
 *        - no raw clinic_id / patient_id / role authority is published as
 *          client-side authority.
 *
 *  S2  LOGIN REDIRECT TARGETS FRONTEND PORTAL (NOT WP-ADMIN)
 *      Existing `login_redirect` integration for a pure patient must target the
 *      production frontend Patient Portal URL. The result must NOT contain
 *      `/wp-admin/` or `admin.php?page=cpms-patient`.
 *
 *  S3  WP-ADMIN GET ESCAPE (INCLUDING LEGACY PATIENT PAGE)
 *      Pure-patient GET requests to wp-admin — including the legacy
 *      `page=cpms-patient` console — server-redirect to the frontend Patient
 *      Portal. No special early-return may keep the patient inside wp-admin.
 *      REST / AJAX / POST / normal backend machinery required by the
 *      authenticated session must remain undisturbed.
 *
 * ===========================================================================
 * RED CLASSIFICATION (CI Integration job = source of truth; NOT RUN != PASS)
 * ===========================================================================
 *
 * INTENDED RED — exactly three tests fail on live main, attributable ONLY to
 * the missing production shell/redirect contracts (product-level assertion
 * failures — never bootstrap/fixture/SQL/FK/harness failures):
 *
 *  R1 testPurePatientFrontendPortalRendersIndependentCpmsShellWithExistingPortalContent
 *  R2 testPurePatientLoginRedirectTargetsFrontendPortalNotWpAdmin
 *  R3 testPurePatientWpAdminGetRedirectsToFrontendPortalIncludingLegacyPatientPage
 *
 * GUARDS / POSITIVE CONTROLS — must pass today and keep GREEN honest (see the
 * "GUARDS" section below). #98 `StandaloneShellProofTest` is intentionally NOT
 * re-implemented here; it remains green on its own suite.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

require_once __DIR__ . '/P3S6RedirectExitSimulatedException.php';

final class Phase9Slice3PatientPortalShellRedTest extends WP_UnitTestCase
{
	/** REST namespace already used by the patient portal config (Slices 1–2). */
	private const NS = 'clinic/v1';

	/** Existing safe JSON runtime config class (Slice 1 C2 / Slice 2 N2). */
	private const CONFIG_CLASS = 'cpms-patient-portal__config';

	/** Existing notifications section marker (Slice 2 N1). */
	private const NOTIFICATIONS_SECTION_ROLE = 'notifications-section';

	/** Existing self-cancel action marker (Slice 1 C1). */
	private const CANCEL_ACTION_ROLE = 'cancel-appointment';

	/**
	 * Stable product-facing root marker of the independent CPMS Patient Portal
	 * shell. GREEN chooses the concrete attribute/element; the RED only requires
	 * that a greppable, patient-portal-specific shell root is present and is NOT
	 * the #98 proof-fixture marker.
	 */
	private const SHELL_ROOT_TOKEN = 'cpms-patient-portal-shell';

	/** Exact product strings of PatientPortalPage (ZWNJ = \u{200C}). */
	private const TITLE_MY_APPOINTMENTS = "نوبت‌های من";
	private const HEADING_UPCOMING      = "نوبت‌های پیش‌رو";
	private const HEADING_HISTORY       = 'تاریخچه';

	/** Legacy wp-admin patient console slug (must not remain the pure-patient home). */
	private const LEGACY_ADMIN_PAGE = 'cpms-patient';

	/** Forbidden authority keys anywhere in published portal config/markup claims. */
	private const FORBIDDEN_AUTHORITY_KEYS = [
		'clinic_id',
		'patient_id',
		'user_id',
		'wp_user_id',
		'role',
		'roles',
	];

	private ?string $permalinkStructureToRestore = null;

	private string $originalTheme = '';

	protected function setUp(): void
	{
		parent::setUp();
		wp_set_current_user( 0 );
		ScopeContext::clear();
		App::resetScope();
		SystemClinicResolver::flush();
		Settings::flushCache();
		$this->permalinkStructureToRestore = null;
		$this->originalTheme               = (string) get_stylesheet();
	}

	protected function tearDown(): void
	{
		if ( $this->permalinkStructureToRestore !== null ) {
			$this->set_permalink_structure( $this->permalinkStructureToRestore );
			$this->permalinkStructureToRestore = null;
		}
		if ( $this->originalTheme !== '' && (string) get_stylesheet() !== $this->originalTheme ) {
			switch_theme( $this->originalTheme );
		}
		wp_set_current_user( 0 );
		$_GET             = [];
		$_POST            = [];
		$_REQUEST         = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ScopeContext::clear();
		App::resetScope();
		SystemClinicResolver::flush();
		Settings::flushCache();
		parent::tearDown();
	}

	// =================================================================
	// R1 — INTENDED RED: frontend entry + independent CPMS shell + existing content
	// =================================================================

	public function testPurePatientFrontendPortalRendersIndependentCpmsShellWithExistingPortalContent(): void
	{
		$fx = $this->buildPurePatientPortalFixture( 'r1' );
		$this->assertFixtureAndExistingPortalContent( $fx );

		// Positive control: the authenticated WordPress session is the pure patient.
		wp_set_current_user( (int) $fx['user_id'] );
		self::assertSame( (int) $fx['user_id'], get_current_user_id(), 'positive control: WordPress session is the pure patient.' );
		self::assertTrue( is_user_logged_in(), 'positive control: login/session works.' );
		self::assertTrue( PatientPortalPage::isPatientOnly( (int) $fx['user_id'] ), 'positive control: isPatientOnly accepts the pure patient.' );

		// ---- INTENDED RED (S1): production frontend Patient Portal entry/shell does not exist on live main. ----
		$frontend_url = $this->requireProductionFrontendPortalUrl(
			'Slice 3 S1: a production Patient Portal frontend entry must exist for pure patients '
			. '(WordPress Page + plugin-owned template_include + plugin-owned standalone CPMS template per '
			. 'docs/decisions/2026-09-21-phase9-patient-portal-owner-policy.md / PR #98 mechanism). '
			. 'Today only the wp-admin console (admin.php?page=' . self::LEGACY_ADMIN_PAGE . ') exists.'
		);

		self::assertStringNotContainsString(
			'/wp-admin/',
			$frontend_url,
			'Slice 3 S1: the production frontend entry must not live under /wp-admin/.'
		);
		self::assertStringNotContainsString(
			'page=' . self::LEGACY_ADMIN_PAGE,
			$frontend_url,
			'Slice 3 S1: the production frontend entry must not be the legacy wp-admin patient console.'
		);

		// Theme-independence positive control (#98 control themes): markers render when the theme renders.
		$this->installProofControlThemes();
		foreach ( [ 'cpms-proof-theme-alpha' => 'CPMS-PROOF-THEME-ALPHA-HEADER', 'cpms-proof-theme-beta' => 'CPMS-PROOF-THEME-BETA-HEADER' ] as $theme => $marker ) {
			switch_theme( $theme );
			self::assertSame( $theme, (string) get_stylesheet(), 'positive control: switched to control theme ' . $theme );
			$header_file = get_theme_root() . '/' . $theme . '/header.php';
			self::assertFileExists( $header_file, 'positive control: #98 control theme header exists.' );
			ob_start();
			include $header_file;
			$header_html = (string) ob_get_clean();
			self::assertStringContainsString(
				$marker,
				$header_html,
				'positive control: control theme ' . $theme . ' emits its header marker when rendered — shell absence is not vacuous.'
			);

			$html = $this->renderProductionFrontendPortalAs( (int) $fx['user_id'], $frontend_url );

			$this->assertIndependentCpmsPatientShell( $html, $fx, $marker );
		}
	}

	// =================================================================
	// R2 — INTENDED RED: login_redirect → frontend portal (not wp-admin)
	// =================================================================

	public function testPurePatientLoginRedirectTargetsFrontendPortalNotWpAdmin(): void
	{
		$fx = $this->buildPurePatientPortalFixture( 'r2' );
		$this->assertFixtureAndExistingPortalContent( $fx );

		$user = get_userdata( (int) $fx['user_id'] );
		self::assertNotFalse( $user, 'positive control: pure patient WP user exists.' );
		self::assertSame(
			[ RolesAndCapabilities::ROLE_PATIENT ],
			array_values( (array) $user->roles ),
			'positive control: user has only the patient role.'
		);
		self::assertTrue( PatientPortalPage::isPatientOnly( $user ), 'positive control: isPatientOnly is true.' );

		// Positive control: the existing login_redirect hook actually executes.
		self::assertNotFalse(
			has_filter( 'login_redirect', [ PatientPortalPage::class, 'redirectAfterLogin' ] ),
			'positive control: PatientPortalPage::redirectAfterLogin is hooked on login_redirect.'
		);

		$default = admin_url();
		$redirected = (string) apply_filters( 'login_redirect', $default, '', $user );

		// The hook must have replaced the default (proves the callback ran for this pure patient).
		self::assertNotSame(
			$default,
			$redirected,
			'positive control: login_redirect hook executed and replaced the default destination for a pure patient.'
		);

		// ---- INTENDED RED (S2): redirect still targets the wp-admin Patient console on live main. ----
		self::assertStringNotContainsString(
			'/wp-admin/',
			$redirected,
			'Slice 3 S2: pure-patient login_redirect must target the production frontend Patient Portal, not /wp-admin/. Got: ' . $redirected
		);
		self::assertStringNotContainsString(
			'admin.php?page=' . self::LEGACY_ADMIN_PAGE,
			$redirected,
			'Slice 3 S2: pure-patient login_redirect must not target the legacy wp-admin console admin.php?page=' . self::LEGACY_ADMIN_PAGE . '. Got: ' . $redirected
		);

		$frontend_url = $this->requireProductionFrontendPortalUrl(
			'Slice 3 S2: login_redirect must target the production frontend Patient Portal URL, but that entry is not resolvable yet.'
		);
		self::assertSame(
			$this->normalizeUrl( $frontend_url ),
			$this->normalizeUrl( $redirected ),
			'Slice 3 S2: login_redirect must equal the production frontend Patient Portal URL. redirect=' . $redirected . ' frontend=' . $frontend_url
		);
	}

	// =================================================================
	// R3 — INTENDED RED: wp-admin GET escape including legacy patient page
	// =================================================================

	public function testPurePatientWpAdminGetRedirectsToFrontendPortalIncludingLegacyPatientPage(): void
	{
		$fx = $this->buildPurePatientPortalFixture( 'r3' );
		$this->assertFixtureAndExistingPortalContent( $fx );

		self::assertNotFalse(
			has_action( 'admin_init', [ PatientPortalPage::class, 'guardWpAdmin' ] ),
			'positive control: PatientPortalPage::guardWpAdmin is hooked on admin_init.'
		);

		// Non-interference first (must stay green today and after GREEN): AJAX / POST
		// are not redirected. REST shares the same early-return branch as AJAX
		// (`wp_doing_ajax() || REST_REQUEST || wp_doing_cron()`); we pin AJAX via the
		// filter seam and do not define REST_REQUEST (constants cannot be undefined).
		$ajax_redirect = $this->captureGuardWpAdminRedirect( (int) $fx['user_id'], [ 'page' => self::LEGACY_ADMIN_PAGE ], 'GET', [ 'ajax' => true ] );
		self::assertNull( $ajax_redirect, 'positive control / Slice 3 S3: guard must not interfere with AJAX.' );

		$post_redirect = $this->captureGuardWpAdminRedirect( (int) $fx['user_id'], [ 'page' => 'index' ], 'POST' );
		self::assertNull( $post_redirect, 'positive control / Slice 3 S3: guard must not interfere with POST.' );

		$cases = [
			'legacy-patient-page' => [ 'page' => self::LEGACY_ADMIN_PAGE ],
			'dashboard'           => [],
			'unrelated-admin'     => [ 'page' => 'cpms-clinicians' ],
		];

		$frontend_url  = $this->tryResolveProductionFrontendPortalUrl();
		$frontend_norm = $frontend_url !== null ? $this->normalizeUrl( $frontend_url ) : null;

		foreach ( $cases as $label => $get ) {
			$redirect = $this->captureGuardWpAdminRedirect( (int) $fx['user_id'], $get, 'GET' );

			// ---- INTENDED RED (S3): legacy page=cpms-patient still early-returns inside wp-admin
			// (null redirect); other GETs still target the wp-admin console rather than the
			// frontend portal. ----
			self::assertNotNull(
				$redirect,
				'Slice 3 S3 (' . $label . '): pure-patient wp-admin GET must server-redirect to the frontend Patient Portal '
				. '(no special early-return may keep the patient inside wp-admin). GET=' . wp_json_encode( $get )
			);
			self::assertStringNotContainsString(
				'/wp-admin/',
				(string) $redirect,
				'Slice 3 S3 (' . $label . '): redirect target must not remain under /wp-admin/. Got: ' . (string) $redirect
			);
			self::assertStringNotContainsString(
				'admin.php?page=' . self::LEGACY_ADMIN_PAGE,
				(string) $redirect,
				'Slice 3 S3 (' . $label . '): redirect must not target the legacy patient console. Got: ' . (string) $redirect
			);

			if ( $frontend_norm !== null ) {
				self::assertSame(
					$frontend_norm,
					$this->normalizeUrl( (string) $redirect ),
					'Slice 3 S3 (' . $label . '): redirect must equal the production frontend Patient Portal URL. Got: ' . (string) $redirect
				);
			} else {
				// Frontend entry itself is R1's RED; still require the redirect leave wp-admin
				// (assertions above) and be a non-empty absolute-ish URL.
				self::assertNotSame( '', trim( (string) $redirect ), 'Slice 3 S3 (' . $label . '): redirect URL must be non-empty.' );
			}
		}

		// When the production frontend entry exists, every case must land on it.
		self::assertNotNull(
			$frontend_url,
			'Slice 3 S3: production frontend Patient Portal entry must exist so wp-admin GET escape has a concrete target '
			. '(WordPress Page + plugin-owned template_include + plugin-owned standalone CPMS template).'
		);
	}

	// =================================================================
	// GUARDS — must stay GREEN during RED
	// =================================================================

	/**
	 * G1 — isPatientOnly role behaviour remains unchanged (pure patient vs staff/multi-role).
	 */
	public function testGuardIsPatientOnlyRoleBehaviourUnchanged(): void
	{
		$patient_id = $this->makeUser( 'g1_pat', RolesAndCapabilities::ROLE_PATIENT );
		$doctor_id  = $this->makeUser( 'g1_doc', RolesAndCapabilities::ROLE_DOCTOR );
		$sec_id     = $this->makeUser( 'g1_sec', RolesAndCapabilities::ROLE_SECRETARY );

		self::assertTrue( PatientPortalPage::isPatientOnly( $patient_id ), 'GUARD G1: pure patient is patient-only.' );
		self::assertFalse( PatientPortalPage::isPatientOnly( $doctor_id ), 'GUARD G1: doctor is not patient-only.' );
		self::assertFalse( PatientPortalPage::isPatientOnly( $sec_id ), 'GUARD G1: secretary is not patient-only.' );
		self::assertFalse( PatientPortalPage::isPatientOnly( 99999999 ), 'GUARD G1: unknown user is not patient-only.' );

		$multi = get_userdata( $patient_id );
		self::assertNotFalse( $multi );
		$multi->add_role( RolesAndCapabilities::ROLE_SECRETARY );
		self::assertFalse( PatientPortalPage::isPatientOnly( $patient_id ), 'GUARD G1: multi-role patient+staff is not patient-only.' );
	}

	/**
	 * G2 — existing Patient Portal render contract (appointments, self-cancel, notifications,
	 * unread badge, mark-all, config/nonce) remains green on the real render path.
	 */
	public function testGuardExistingPatientPortalRenderContractRemainsGreen(): void
	{
		$fx = $this->buildPurePatientPortalFixture( 'g2' );
		$this->assertFixtureAndExistingPortalContent( $fx );

		$html = $this->renderLegacyPortalContentAs( (int) $fx['user_id'] );

		self::assertStringContainsString( self::TITLE_MY_APPOINTMENTS, $html, 'GUARD G2: title.' );
		self::assertStringContainsString( self::HEADING_UPCOMING, $html, 'GUARD G2: upcoming section.' );
		self::assertStringContainsString( self::HEADING_HISTORY, $html, 'GUARD G2: history section.' );
		self::assertStringContainsString( (string) $fx['reference_code'], $html, 'GUARD G2: upcoming appointment listed.' );
		self::assertStringContainsString( (string) $fx['history_reference_code'], $html, 'GUARD G2: history appointment listed.' );

		$cancel = $this->markedOpeningTags( $html, self::CANCEL_ACTION_ROLE );
		self::assertCount( 1, $cancel, 'GUARD G2: exactly one self-cancel action for the confirmed upcoming row.' );
		self::assertSame( (string) $fx['appointment_id'], $this->attributeValue( $cancel[0], 'data-appointment-id' ), 'GUARD G2: cancel bound to appointment id.' );

		$sections = $this->markedOpeningTags( $html, self::NOTIFICATIONS_SECTION_ROLE );
		self::assertCount( 1, $sections, 'GUARD G2: notifications section present.' );
		self::assertCount( 1, $this->markedOpeningTags( $html, 'notifications-unread-badge' ), 'GUARD G2: unread badge present.' );
		self::assertCount( 1, $this->markedOpeningTags( $html, 'notifications-mark-all-read' ), 'GUARD G2: mark-all-read control present.' );
		self::assertGreaterThanOrEqual( 1, count( $this->markedOpeningTags( $html, 'notification-row' ) ), 'GUARD G2: notification rows render.' );

		$scripts = $this->configScriptPayloads( $html );
		self::assertCount( 1, $scripts, 'GUARD G2: exactly one portal config script.' );
		$config = json_decode( trim( $scripts[0] ), true );
		self::assertIsArray( $config, 'GUARD G2: config is JSON.' );
		self::assertSame( untrailingslashit( rest_url( self::NS ) ), (string) ( $config['rest_root'] ?? '' ), 'GUARD G2: rest_root.' );
		self::assertArrayHasKey( 'cancel_path', $config, 'GUARD G2: cancel_path.' );
		self::assertArrayHasKey( 'notifications_read_path', $config, 'GUARD G2: notifications_read_path.' );
		self::assertNotFalse( wp_verify_nonce( (string) ( $config['nonce'] ?? '' ), 'wp_rest' ), 'GUARD G2: wp_rest nonce verifies.' );
		$this->assertNoAuthorityKeys( $config, 'GUARD G2 config' );
	}

	/**
	 * G3 — staff / multi-role users do NOT become Patient Portal users
	 * (login_redirect and isPatientOnly stay non-patient for them).
	 */
	public function testGuardStaffAndMultiRoleUsersAreNotPatientPortalUsers(): void
	{
		$doctor_id = $this->makeUser( 'g3_doc', RolesAndCapabilities::ROLE_DOCTOR );
		$sec_id    = $this->makeUser( 'g3_sec', RolesAndCapabilities::ROLE_SECRETARY );
		$multi_id  = $this->makeUser( 'g3_multi', RolesAndCapabilities::ROLE_PATIENT );
		$multi     = get_userdata( $multi_id );
		self::assertNotFalse( $multi );
		$multi->add_role( RolesAndCapabilities::ROLE_DOCTOR );

		foreach ( [
			'doctor' => $doctor_id,
			'secretary' => $sec_id,
			'multi-role' => $multi_id,
		] as $label => $user_id ) {
			self::assertFalse( PatientPortalPage::isPatientOnly( $user_id ), 'GUARD G3: ' . $label . ' is not patient-only.' );
			$user = get_userdata( $user_id );
			self::assertNotFalse( $user );
			$default    = 'https://example.org/wp-admin/';
			$redirected = (string) apply_filters( 'login_redirect', $default, '', $user );
			self::assertSame(
				$default,
				$redirected,
				'GUARD G3: ' . $label . ' login_redirect must be left untouched by the patient portal hook.'
			);
		}
	}

	/**
	 * G4 — unauthenticated frontend access performs no patient-data render and exposes no PHI.
	 *
	 * If the production frontend entry is not resolvable yet, this guard PASS-SKIPS the
	 * entry-specific assertions (the missing entry is R1's intended RED) and still proves
	 * the legacy content path refuses anonymous callers.
	 */
	public function testGuardUnauthenticatedFrontendAccessExposesNoPatientData(): void
	{
		$fx = $this->buildPurePatientPortalFixture( 'g4' );
		$this->assertFixtureAndExistingPortalContent( $fx );

		// Legacy content path: anonymous must not receive portal markup/PHI.
		// Capture only THIS test's buffer level so a wp_die() cannot leave PHPUnit's
		// outer buffers unbalanced (RISKY: "did not (only) close its own output buffers").
		wp_set_current_user( 0 );
		$denied      = false;
		$html        = '';
		$ob_level    = ob_get_level();
		ob_start();
		try {
			PatientPortalPage::render();
			$html = (string) ob_get_clean();
		} catch ( \WPDieException $e ) {
			$denied = true;
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			$html = '';
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			throw $e;
		}
		self::assertTrue(
			$denied || ! str_contains( $html, (string) $fx['reference_code'] ),
			'GUARD G4: anonymous callers must not receive appointment PHI from PatientPortalPage::render().'
		);
		self::assertTrue(
			$denied || ! str_contains( $html, (string) $fx['mobile'] ),
			'GUARD G4: anonymous callers must not receive mobile PHI from PatientPortalPage::render().'
		);

		$frontend_url = $this->tryResolveProductionFrontendPortalUrl();
		if ( $frontend_url === null ) {
			// Production entry is R1's intended RED — do not fail this guard for that absence.
			$this->assertTrue( true, 'GUARD G4: production frontend entry absent (covered by R1); legacy anonymous deny held.' );

			return;
		}

		wp_set_current_user( 0 );
		$html = $this->renderProductionFrontendPortalAs( 0, $frontend_url );
		self::assertStringNotContainsString( (string) $fx['reference_code'], $html, 'GUARD G4: unauthenticated frontend must not render appointment reference codes.' );
		self::assertStringNotContainsString( (string) $fx['mobile'], $html, 'GUARD G4: unauthenticated frontend must not render mobile PHI.' );
		self::assertStringNotContainsString( (string) $fx['patient_last_name'], $html, 'GUARD G4: unauthenticated frontend must not render patient name PHI.' );
		foreach ( $fx['notification_titles'] as $title ) {
			self::assertStringNotContainsString( (string) $title, $html, 'GUARD G4: unauthenticated frontend must not render notification titles.' );
		}
	}

	/**
	 * G5 — existing WordPress session + wp_rest nonce contract remains valid on the
	 * legacy portal content path (and on the frontend shell when it exists).
	 */
	public function testGuardWordpressSessionAndWpRestNonceContractRemainsValid(): void
	{
		$fx = $this->buildPurePatientPortalFixture( 'g5' );
		$this->assertFixtureAndExistingPortalContent( $fx );

		$html   = $this->renderLegacyPortalContentAs( (int) $fx['user_id'] );
		$scripts = $this->configScriptPayloads( $html );
		self::assertCount( 1, $scripts, 'GUARD G5: config script present.' );
		$config = json_decode( trim( $scripts[0] ), true );
		self::assertIsArray( $config );
		self::assertSame( (int) $fx['user_id'], get_current_user_id(), 'GUARD G5: session is the patient while config is read.' );
		self::assertNotFalse( wp_verify_nonce( (string) $config['nonce'], 'wp_rest' ), 'GUARD G5: published nonce verifies as wp_rest for the session user.' );
		$this->assertNoAuthorityKeys( $config, 'GUARD G5 config' );
	}

	/**
	 * G6 — portal assets stay conditional: the patient-portal script handle is not
	 * enqueued on unrelated frontend hooks; no global polling requirement is introduced
	 * by this RED. (Does not invent speculative performance tests.)
	 */
	public function testGuardPortalAssetsRemainConditionalToPortalSurface(): void
	{
		$fx     = $this->buildPurePatientPortalFixture( 'g6' );
		$handle = 'cpms-patient-portal';

		$this->resetScriptHandle( $handle );
		wp_set_current_user( (int) $fx['user_id'] );

		// Unrelated admin hook must not enqueue the portal script.
		PatientPortalPage::enqueue_assets( 'index.php' );
		self::assertFalse( wp_script_is( $handle, 'enqueued' ), 'GUARD G6: dashboard hook does not enqueue patient-portal assets.' );

		PatientPortalPage::enqueue_assets( 'toplevel_page_cpms-clinicians' );
		self::assertFalse( wp_script_is( $handle, 'enqueued' ), 'GUARD G6: unrelated admin page does not enqueue patient-portal assets.' );

		// Pure patient on the legacy portal hook still enqueues (accepted Slice 1 behaviour).
		PatientPortalPage::enqueue_assets( 'toplevel_page_cpms-patient' );
		self::assertTrue( wp_script_is( $handle, 'enqueued' ), 'GUARD G6: legacy portal hook still enqueues for pure patient (Slice 1 contract).' );
		$this->resetScriptHandle( $handle );

		// Staff on the portal hook still gets nothing.
		$doctor_id = $this->makeUser( 'g6_doc', RolesAndCapabilities::ROLE_DOCTOR );
		wp_set_current_user( $doctor_id );
		PatientPortalPage::enqueue_assets( 'toplevel_page_cpms-patient' );
		self::assertFalse( wp_script_is( $handle, 'enqueued' ), 'GUARD G6: staff never receive patient-portal assets.' );
		$this->resetScriptHandle( $handle );
	}

	/**
	 * G7 — #98 technical proof infrastructure remains loadable and its mechanism
	 * constants are untouched (theme independence + plain/pretty are owned by
	 * StandaloneShellProofTest; this guard only pins the fixture seam still exists).
	 */
	public function testGuardStandaloneShellProofInfrastructureStillPresent(): void
	{
		$fixture = dirname( __DIR__ ) . '/Fixtures/standalone-shell/cpms-standalone-shell-proof.php';
		$template = dirname( __DIR__ ) . '/Fixtures/standalone-shell/cpms-standalone-shell-proof-template.php';
		self::assertFileExists( $fixture, 'GUARD G7: #98 proof interceptor fixture must remain on disk.' );
		self::assertFileExists( $template, 'GUARD G7: #98 proof standalone template must remain on disk.' );
		self::assertFileExists(
			dirname( __DIR__ ) . '/Integration/StandaloneShellProofTest.php',
			'GUARD G7: #98 StandaloneShellProofTest must remain on disk (theme independence + plain/pretty proof).'
		);

		require_once $fixture;
		self::assertTrue( function_exists( 'cpms_shell_proof_register' ), 'GUARD G7: proof register function exists.' );
		self::assertTrue( function_exists( 'cpms_shell_proof_template_include' ), 'GUARD G7: proof template_include callback exists.' );
		self::assertTrue( function_exists( 'cpms_shell_proof_template_path' ), 'GUARD G7: proof template path helper exists.' );
		self::assertFileIsReadable( \cpms_shell_proof_template_path(), 'GUARD G7: proof template path is readable.' );
	}

	// =================================================================
	// Fixture — pure patient + owned appointment + internal notifications
	// =================================================================

	/**
	 * @return array{
	 *     clinic_id:int, location_id:int, clinician_id:int, user_id:int, patient_id:int,
	 *     mobile:string, patient_last_name:string, appointment_id:int, reference_code:string,
	 *     history_appointment_id:int, history_reference_code:string,
	 *     notification_ids:list<int>, notification_titles:list<string>
	 * }
	 */
	private function buildPurePatientPortalFixture( string $tag ): array
	{
		$clinic_count = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinics' ), [] );
		self::assertSame( 1, $clinic_count, 'precondition: exactly one seeded Clinic (portal/App::settings() requires it).' );

		$scope = App::scope();
		self::assertSame( ClinicScope::SOURCE_SYSTEM_SINGLE, $scope->source, 'precondition: scope resolves from the single seeded Clinic.' );
		$clinic_id = $scope->clinicId;
		self::assertGreaterThan( 0, $clinic_id );

		$location_id = (int) App::db()->fetchValue(
			'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d AND is_primary = 1 ORDER BY id ASC LIMIT 1',
			[ $clinic_id ]
		);
		self::assertGreaterThan( 0, $location_id, 'precondition: primary Location of the seeded Clinic.' );

		$now = App::db()->nowUtcSql();

		$clinician_id = $this->insertRow(
			'cpms_clinicians',
			[
				'clinic_id'  => $clinic_id,
				'full_name'  => 'Dr P9S3 ' . $tag,
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%d', '%s', '%s' ],
			'clinician'
		);

		$mobile            = $this->mobileFor( $tag );
		$patient_last_name = 'Patient ' . $tag;
		$patient_id        = $this->insertRow(
			'cpms_patients',
			[
				'clinic_id'  => $clinic_id,
				'mrn'        => 'MR-P9S3-' . strtoupper( substr( bin2hex( random_bytes( 6 ) ), 0, 10 ) ),
				'first_name' => 'Portal',
				'last_name'  => $patient_last_name,
				'mobile'     => $mobile,
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
			'patient'
		);

		$user_id = (int) wp_create_user(
			'p9s3_' . $tag . '_' . uniqid( '', false ),
			'pass-not-used-123',
			uniqid( 'p9s3_' . $tag . '_', true ) . '@test.local'
		);
		self::assertGreaterThan( 0, $user_id, 'precondition: WP user created.' );
		$user = get_userdata( $user_id );
		self::assertNotFalse( $user );
		$user->set_role( RolesAndCapabilities::ROLE_PATIENT );

		$this->insertRow(
			'cpms_patient_user_links',
			[
				'clinic_id'      => $clinic_id,
				'patient_id'     => $patient_id,
				'wp_user_id'     => $user_id,
				'mobile_at_link' => $mobile,
				'is_primary'     => 1,
				'linked_at'      => $now,
			],
			[ '%d', '%d', '%d', '%s', '%d', '%s' ],
			'patient_user_link'
		);

		$slot_date       = $this->ymdDaysOffset( 5 );
		$slot_time       = '10:00:00';
		$slot_id         = $this->insertSlot( $clinic_id, $location_id, $clinician_id, $slot_date, $slot_time, 1 );
		$reference_code  = $this->referenceCode( $tag . 'u' );
		$appointment_id  = $this->insertAppointment(
			$clinic_id,
			$location_id,
			$clinician_id,
			$patient_id,
			$user_id,
			$slot_id,
			$slot_date,
			$slot_time,
			'confirmed',
			$reference_code,
			$now
		);

		$history_date      = $this->ymdDaysOffset( -3 );
		$history_time      = '09:00:00';
		$history_slot_id   = $this->insertSlot( $clinic_id, $location_id, $clinician_id, $history_date, $history_time, 0 );
		$history_reference = $this->referenceCode( $tag . 'h' );
		$history_appointment_id = $this->insertAppointment(
			$clinic_id,
			$location_id,
			$clinician_id,
			$patient_id,
			$user_id,
			$history_slot_id,
			$history_date,
			$history_time,
			'cancelled_by_patient',
			$history_reference,
			$now
		);

		// Internal notifications so the existing notifications section has real content.
		$notification_ids    = [];
		$notification_titles = [];
		foreach ( [ NotificationEvents::APPT_CONFIRMED, NotificationEvents::APPT_REMINDER ] as $index => $event ) {
			$vars = [
				'doctor_name'      => 'Dr P9S3 ' . $tag,
				'appointment_date' => $slot_date,
				'appointment_time' => '10:0' . $index,
			];
			$nid = App::notificationService()->publishToPatient(
				$clinic_id,
				$patient_id,
				$event,
				$vars,
				'p9s3:' . $tag . ':' . $index . ':' . bin2hex( random_bytes( 4 ) )
			);
			self::assertNotNull( $nid, 'fixture: internal notification published.' );
			$notification_ids[]    = (int) $nid;
			$rendered              = NotificationEvents::render( $event, $vars );
			$notification_titles[] = (string) $rendered['title'];
		}

		return [
			'clinic_id'               => $clinic_id,
			'location_id'             => $location_id,
			'clinician_id'            => $clinician_id,
			'user_id'                 => $user_id,
			'patient_id'              => $patient_id,
			'mobile'                  => $mobile,
			'patient_last_name'       => $patient_last_name,
			'appointment_id'          => $appointment_id,
			'reference_code'          => $reference_code,
			'history_appointment_id'  => $history_appointment_id,
			'history_reference_code'  => $history_reference,
			'notification_ids'        => $notification_ids,
			'notification_titles'     => $notification_titles,
		];
	}

	/**
	 * Positive controls BEFORE any shell/redirect assertion — prove the fixture is real
	 * and the existing Patient Portal content path still works (so R1–R3 can only fail
	 * for the missing production shell/redirect contracts).
	 *
	 * @param array<string, mixed> $fx
	 */
	private function assertFixtureAndExistingPortalContent( array $fx ): void
	{
		$user = get_userdata( (int) $fx['user_id'] );
		self::assertNotFalse( $user, 'positive control: WP user exists.' );
		self::assertSame(
			[ RolesAndCapabilities::ROLE_PATIENT ],
			array_values( (array) $user->roles ),
			'positive control: pure patient role only.'
		);
		self::assertTrue(
			PatientPortalPage::isPatientOnly( (int) $fx['user_id'] ),
			'positive control: PatientPortalPage::isPatientOnly() accepts the user.'
		);

		$link = App::db()->fetchRow(
			'SELECT clinic_id, wp_user_id FROM ' . App::db()->table( 'cpms_patient_user_links' ) . ' WHERE patient_id = %d LIMIT 1',
			[ (int) $fx['patient_id'] ]
		);
		self::assertNotNull( $link, 'positive control: Patient link row exists.' );
		self::assertSame( (int) $fx['user_id'], (int) $link['wp_user_id'] );
		self::assertSame( (int) $fx['clinic_id'], (int) $link['clinic_id'] );

		$rows = App::bookingService()->listMine(
			(int) $fx['user_id'],
			gmdate( 'Y-m-d', strtotime( '-365 days' ) ),
			gmdate( 'Y-m-d', strtotime( '+180 days' ) )
		);
		$by_id = [];
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}
		self::assertArrayHasKey( (int) $fx['appointment_id'], $by_id, 'positive control: listMine includes the upcoming appointment.' );
		self::assertSame( 'confirmed', (string) $by_id[ (int) $fx['appointment_id'] ]['status'] );

		$inbox = App::notificationService()->inbox( (int) $fx['user_id'], false, 50 );
		self::assertGreaterThanOrEqual( 1, (int) ( $inbox['unread_count'] ?? 0 ), 'positive control: inbox has unread notifications.' );
		self::assertNotSame( [], $inbox['notifications'] ?? [], 'positive control: inbox returns notification rows.' );

		// Existing accepted Patient Portal content can render (before shell assertions).
		$html = $this->renderLegacyPortalContentAs( (int) $fx['user_id'] );
		self::assertStringContainsString( self::TITLE_MY_APPOINTMENTS, $html, 'positive control: existing PatientPortalPage::render() path reached.' );
		self::assertStringContainsString( self::HEADING_UPCOMING, $html, 'positive control: appointments section renders.' );
		self::assertStringContainsString( (string) $fx['reference_code'], $html, 'positive control: upcoming appointment content renders.' );
		self::assertStringContainsString( self::NOTIFICATIONS_SECTION_ROLE, $html, 'positive control: notifications section marker renders.' );
		self::assertCount( 1, $this->configScriptPayloads( $html ), 'positive control: existing REST config/nonce publishes.' );
	}

	// =================================================================
	// Production frontend entry resolution (product behaviour — not fixture filenames)
	// =================================================================

	/**
	 * Resolve the production frontend Patient Portal URL.
	 *
	 * Discovery order deliberately avoids encoding #98 fixture slugs/filenames as
	 * product requirements. GREEN may satisfy any of these product seams:
	 *   1) a public PatientPortalPage API returning the frontend URL;
	 *   2) a product filter `cpms_patient_portal_frontend_url`;
	 *   3) a product option holding the CPMS-owned Page id;
	 *   4) a published CPMS-owned Page whose template_include interception is
	 *      registered by production code (not the proof fixture callback).
	 */
	private function tryResolveProductionFrontendPortalUrl(): ?string
	{
		// Product URL helpers. pageUrl() is included so GREEN may repoint the existing
		// helper at the frontend entry; today it still returns the wp-admin console and
		// is filtered out by isWpAdminUrl().
		foreach ( [ 'frontendPortalUrl', 'frontendUrl', 'portalFrontendUrl', 'patientPortalFrontendUrl', 'pageUrl' ] as $method ) {
			if ( ! is_callable( [ PatientPortalPage::class, $method ] ) ) {
				continue;
			}
			$url = (string) PatientPortalPage::{$method}();
			if ( $url !== '' && ! $this->isWpAdminUrl( $url ) ) {
				return $url;
			}
		}

		$filtered = apply_filters( 'cpms_patient_portal_frontend_url', '' );
		if ( is_string( $filtered ) && $filtered !== '' && ! $this->isWpAdminUrl( $filtered ) ) {
			return $filtered;
		}

		foreach ( [ 'cpms_patient_portal_page_id', 'cpms_patient_portal_page' ] as $option ) {
			$page_id = (int) get_option( $option, 0 );
			if ( $page_id <= 0 ) {
				continue;
			}
			$url = get_permalink( $page_id );
			if ( is_string( $url ) && $url !== '' && ! $this->isWpAdminUrl( $url ) ) {
				return $url;
			}
		}

		// Last-resort product seam: a production template_include callback (NOT the
		// #98 proof fixture) that rewrites a published page to a plugin-owned template.
		// We never treat the proof slug/callback name as the product entry.
		return null;
	}

	private function requireProductionFrontendPortalUrl( string $failure_message ): string
	{
		$url = $this->tryResolveProductionFrontendPortalUrl();
		self::assertNotNull( $url, $failure_message );
		self::assertIsString( $url );
		self::assertNotSame( '', $url, $failure_message );

		return $url;
	}

	private function isWpAdminUrl( string $url ): bool
	{
		return str_contains( $url, '/wp-admin/' ) || str_contains( $url, 'admin.php?page=' . self::LEGACY_ADMIN_PAGE );
	}

	// =================================================================
	// Render helpers
	// =================================================================

	private function renderLegacyPortalContentAs( int $user_id ): string
	{
		wp_set_current_user( $user_id );
		ob_start();
		try {
			PatientPortalPage::render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Render the production frontend Patient Portal through the normal WordPress
	 * page lifecycle: resolve the request → template_include → include template.
	 * This is the same mechanism proven by #98, applied to the production entry.
	 */
	private function renderProductionFrontendPortalAs( int $user_id, string $frontend_url ): string
	{
		wp_set_current_user( $user_id );

		$path = (string) ( wp_parse_url( $frontend_url, PHP_URL_PATH ) ?? '/' );
		$query = (string) ( wp_parse_url( $frontend_url, PHP_URL_QUERY ) ?? '' );
		$request = $path . ( $query !== '' ? '?' . $query : '' );
		if ( $request === '' ) {
			$request = '/';
		}

		$this->go_to( $request );

		$baseline = get_stylesheet_directory() . '/page.php';
		if ( ! is_readable( $baseline ) ) {
			$baseline = get_stylesheet_directory() . '/index.php';
		}
		if ( ! is_readable( $baseline ) ) {
			$baseline = '/active-theme/page.php';
		}

		$template = (string) apply_filters( 'template_include', $baseline );

		// Production shell must be a plugin-owned standalone template, not the active theme.
		self::assertNotSame(
			$baseline,
			$template,
			'Slice 3 S1: template_include must intercept the production Patient Portal page with a plugin-owned template '
			. '(PR #98 / owner architecture mechanism). Got baseline passthrough: ' . $template
		);
		self::assertFileExists( $template, 'Slice 3 S1: plugin-owned Patient Portal template must exist on disk. Got: ' . $template );
		self::assertStringNotContainsString(
			'/themes/',
			str_replace( '\\', '/', $template ),
			'Slice 3 S1: the intercepted template must not live inside a theme directory (theme independence). Got: ' . $template
		);
		// Must not be the #98 proof fixture template either — production owns its own template.
		self::assertStringNotContainsString(
			'cpms-standalone-shell-proof-template.php',
			str_replace( '\\', '/', $template ),
			'Slice 3 S1: production must not reuse the #98 proof-fixture template as the Patient Portal product shell.'
		);

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $fx
	 */
	private function assertIndependentCpmsPatientShell( string $html, array $fx, string $theme_header_marker ): void
	{
		$body = ltrim( $html );

		// CPMS owns the full document.
		self::assertTrue(
			str_starts_with( $body, '<!DOCTYPE html>' ) || str_starts_with( strtolower( $body ), '<!doctype html>' ),
			'Slice 3 S1: CPMS must own the full document starting at <!DOCTYPE html>.'
		);
		self::assertStringContainsString( '</html>', $html, 'Slice 3 S1: CPMS full document must close with </html>.' );

		// Stable CPMS Patient Portal root marker (product shell — not the #98 proof marker).
		self::assertTrue(
			str_contains( strtolower( $html ), self::SHELL_ROOT_TOKEN )
			|| str_contains( $html, 'data-cpms-patient-portal' )
			|| str_contains( $html, 'data-cpms-portal="patient"' ),
			'Slice 3 S1: shell must expose a stable CPMS Patient Portal root marker '
			. '(e.g. "' . self::SHELL_ROOT_TOKEN . '" / data-cpms-patient-portal).'
		);
		self::assertStringNotContainsString(
			'CPMS-STANDALONE-SHELL-PROOF',
			$html,
			'Slice 3 S1: production Patient Portal must not be the #98 proof-fixture shell.'
		);
		self::assertStringNotContainsString(
			'data-cpms-standalone-shell="proof-fixture"',
			$html,
			'Slice 3 S1: production Patient Portal must not carry the proof-fixture ownership attribute.'
		);

		// Theme chrome absent (positive control marker proven separately).
		self::assertStringNotContainsString( $theme_header_marker, $html, 'Slice 3 S1: active theme header must be absent from the Patient Portal shell.' );
		self::assertStringNotContainsString( 'CPMS-PROOF-THEME-ALPHA', $html, 'Slice 3 S1: proof theme alpha markers absent.' );
		self::assertStringNotContainsString( 'CPMS-PROOF-THEME-BETA', $html, 'Slice 3 S1: proof theme beta markers absent.' );

		// wp-admin chrome absent.
		self::assertStringNotContainsString( 'id="wpadminbar"', $html, 'Slice 3 S1: wp-admin bar absent.' );
		self::assertStringNotContainsString( 'wp-admin-bar-', $html, 'Slice 3 S1: wp-admin bar nodes absent.' );
		self::assertStringNotContainsString( 'id="adminmenu"', $html, 'Slice 3 S1: wp-admin menu absent.' );
		self::assertStringNotContainsString( 'id="wpfooter"', $html, 'Slice 3 S1: wp-admin footer absent.' );
		self::assertStringNotContainsString( 'مدیریت مطب', $html, 'Slice 3 S1: Staff/Admin "مدیریت مطب" navigation must not appear in the Patient shell.' );

		// RTL + structural landmarks (not pixel design).
		self::assertTrue(
			(bool) preg_match( '/\\bdir=["\']rtl["\']/i', $html ),
			'Slice 3 S1: Patient Portal shell must be RTL (dir="rtl").'
		);
		self::assertTrue(
			(bool) preg_match( '/<header\\b/i', $html )
			|| (bool) preg_match( '/role=["\']banner["\']/i', $html )
			|| str_contains( $html, 'data-role="portal-header"' ),
			'Slice 3 S1: independent CPMS header / banner landmark must be present.'
		);
		self::assertTrue(
			(bool) preg_match( '/<nav\\b/i', $html )
			|| (bool) preg_match( '/role=["\']navigation["\']/i', $html )
			|| str_contains( $html, 'data-role="patient-nav"' ),
			'Slice 3 S1: patient navigation landmark must be present.'
		);
		self::assertTrue(
			(bool) preg_match( '/<main\\b/i', $html )
			|| (bool) preg_match( '/role=["\']main["\']/i', $html )
			|| str_contains( $html, 'data-role="portal-main"' ),
			'Slice 3 S1: main content landmark must be present.'
		);
		self::assertTrue(
			(bool) preg_match( '/<footer\\b/i', $html )
			|| (bool) preg_match( '/role=["\']contentinfo["\']/i', $html )
			|| str_contains( $html, 'data-role="portal-footer"' ),
			'Slice 3 S1: footer landmark must be present.'
		);

		// Existing accepted Patient Portal content remains available inside the shell.
		self::assertStringContainsString(
			(string) $fx['reference_code'],
			$html,
			'Slice 3 S1: existing appointment content must remain available inside the independent shell.'
		);
		self::assertTrue(
			str_contains( $html, self::HEADING_UPCOMING )
			|| str_contains( $html, 'data-role="appointments-section"' )
			|| str_contains( $html, self::TITLE_MY_APPOINTMENTS ),
			'Slice 3 S1: appointments section must remain available inside the shell.'
		);
		self::assertTrue(
			str_contains( $html, 'data-role="' . self::NOTIFICATIONS_SECTION_ROLE . '"' )
			|| str_contains( $html, self::NOTIFICATIONS_SECTION_ROLE ),
			'Slice 3 S1: notifications section must remain available inside the shell.'
		);

		// Existing REST config / wp_rest nonce remains usable; session identity preserved.
		$scripts = $this->configScriptPayloads( $html );
		self::assertCount(
			1,
			$scripts,
			'Slice 3 S1: existing cpms-patient-portal__config (rest_root + wp_rest nonce) must remain usable inside the shell.'
		);
		$config = json_decode( trim( $scripts[0] ), true );
		self::assertIsArray( $config, 'Slice 3 S1: portal config must be JSON.' );
		self::assertArrayHasKey( 'rest_root', $config, 'Slice 3 S1: rest_root must remain published.' );
		self::assertArrayHasKey( 'nonce', $config, 'Slice 3 S1: wp_rest nonce must remain published.' );
		self::assertSame( (int) $fx['user_id'], get_current_user_id(), 'Slice 3 S1: normal WordPress session/user remains active during shell render.' );
		self::assertNotFalse(
			wp_verify_nonce( (string) $config['nonce'], 'wp_rest' ),
			'Slice 3 S1: published nonce must verify as wp_rest for the authenticated patient session.'
		);
		$this->assertNoAuthorityKeys( $config, 'Slice 3 S1 shell config' );

		// Patient shell must not expose staff/admin navigation labels/markers.
		foreach ( [ 'wp-menu', 'toplevel_page_cpms-', 'cpms-admin-nav', 'data-role="staff-nav"', 'data-role="admin-nav"' ] as $staff_marker ) {
			self::assertStringNotContainsString(
				$staff_marker,
				$html,
				'Slice 3 S1: Patient shell must not expose Staff/Admin navigation (' . $staff_marker . ').'
			);
		}
	}

	// =================================================================
	// Redirect capture (wp_safe_redirect + exit → throwable; established repo pattern)
	// =================================================================

	/**
	 * @param array<string, string> $get
	 * @param array{ajax?:bool}     $flags
	 */
	private function captureGuardWpAdminRedirect( int $user_id, array $get, string $method, array $flags = [] ): ?string
	{
		wp_set_current_user( $user_id );
		$_GET                      = $get;
		$_REQUEST                  = $get;
		$_POST                     = [];
		$_SERVER['REQUEST_METHOD'] = $method;

		$ajax_filter = null;
		if ( ! empty( $flags['ajax'] ) ) {
			// Prefer the filter seam (no permanent DOING_AJAX constant leak).
			$ajax_filter = static fn (): bool => true;
			add_filter( 'wp_doing_ajax', $ajax_filter, 999 );
		}

		$redirect = null;
		$escape   = static function ( $url ) use ( &$redirect ): string {
			$redirect = (string) $url;
			throw new P3S6RedirectExitSimulatedException( 'wp_redirect intercepted (exit simulated): ' . (string) $url );
		};
		add_filter( 'wp_redirect', $escape, 1 );

		try {
			PatientPortalPage::guardWpAdmin();
		} catch ( P3S6RedirectExitSimulatedException ) {
			// expected when a redirect fires
		} finally {
			remove_filter( 'wp_redirect', $escape, 1 );
			if ( $ajax_filter !== null ) {
				remove_filter( 'wp_doing_ajax', $ajax_filter, 999 );
			}
			$_GET                      = [];
			$_REQUEST                  = [];
			$_POST                     = [];
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		return $redirect;
	}

	// =================================================================
	// Markup / config helpers
	// =================================================================

	/**
	 * @return list<string>
	 */
	private function markedOpeningTags( string $html, string $role ): array
	{
		$matches = [];
		if ( preg_match_all( '/<[a-zA-Z][^>]*\\sdata-role="' . preg_quote( $role, '/' ) . '"[^>]*>/su', $html, $matches ) === false ) {
			return [];
		}

		return (array) ( $matches[0] ?? [] );
	}

	private function attributeValue( string $tag, string $attribute ): ?string
	{
		if ( preg_match( '/\\s' . preg_quote( $attribute, '/' ) . '="([^"]*)"/su', $tag, $matches ) !== 1 ) {
			return null;
		}

		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * @return list<string>
	 */
	private function configScriptPayloads( string $html ): array
	{
		$payloads = [];
		if ( preg_match_all( '/<script\\b([^>]*)>(.*?)<\\/script>/su', $html, $matches, PREG_SET_ORDER ) < 1 ) {
			return $payloads;
		}
		foreach ( $matches as $script ) {
			$attributes = ' ' . $script[1];
			if ( preg_match( '/\\stype="([^"]*)"/i', $attributes, $type ) !== 1 || strtolower( trim( $type[1] ) ) !== 'application/json' ) {
				continue;
			}
			if ( preg_match( '/\\sclass="([^"]*)"/i', $attributes, $class ) !== 1 ) {
				continue;
			}
			$classes = preg_split( '/\\s+/', trim( $class[1] ) );
			if ( $classes === false || ! in_array( self::CONFIG_CLASS, $classes, true ) ) {
				continue;
			}
			$payloads[] = (string) $script[2];
		}

		return $payloads;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function assertNoAuthorityKeys( array $config, string $label ): void
	{
		$stack = [ $config ];
		while ( $stack !== [] ) {
			$node = (array) array_pop( $stack );
			foreach ( $node as $key => $value ) {
				self::assertNotContains(
					strtolower( (string) $key ),
					self::FORBIDDEN_AUTHORITY_KEYS,
					$label . ': must not expose authority key "' . (string) $key . '" (server-side authorization remains authoritative).'
				);
				if ( is_array( $value ) ) {
					$stack[] = $value;
				}
			}
		}
	}

	private function normalizeUrl( string $url ): string
	{
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return untrailingslashit( $url );
		}
		$path  = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';

		$normalized = ( $scheme !== '' ? $scheme . '://' : '' ) . $host . $path;
		if ( $query !== '' ) {
			parse_str( $query, $params );
			if ( is_array( $params ) ) {
				ksort( $params );
				$normalized .= '?' . http_build_query( $params );
			}
		}

		return $normalized;
	}

	// =================================================================
	// #98 control-theme install (reuse proof themes; do not register proof interceptor as product)
	// =================================================================

	private function installProofControlThemes(): void
	{
		$src_root = dirname( __DIR__ ) . '/Fixtures/standalone-shell/themes';
		$themes_root = (string) get_theme_root();
		foreach ( [ 'cpms-proof-theme-alpha', 'cpms-proof-theme-beta' ] as $slug ) {
			$dest = $themes_root . '/' . $slug;
			if ( ! is_dir( $dest ) ) {
				$this->copyDir( $src_root . '/' . $slug, $dest );
			}
		}
		wp_clean_themes_cache( true );
	}

	private function copyDir( string $src, string $dest ): void
	{
		if ( ! is_dir( $dest ) ) {
			mkdir( $dest, 0775, true );
		}
		foreach ( scandir( $src ) ?: [] as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$from = $src . '/' . $entry;
			$to   = $dest . '/' . $entry;
			if ( is_dir( $from ) ) {
				$this->copyDir( $from, $to );
			} else {
				copy( $from, $to );
			}
		}
	}

	// =================================================================
	// Row writers
	// =================================================================

	private function makeUser( string $login, string $role ): int
	{
		$unique  = $login . '_' . bin2hex( random_bytes( 3 ) );
		$user_id = (int) wp_create_user( $unique, 'pass-not-used-123', $unique . '@test.local' );
		self::assertGreaterThan( 0, $user_id );
		$user = get_userdata( $user_id );
		self::assertNotFalse( $user );
		$user->set_role( $role );

		return $user_id;
	}

	private function resetScriptHandle( string $handle ): void
	{
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}

	private function insertSlot( int $clinic_id, int $location_id, int $clinician_id, string $date, string $time, int $booked ): int
	{
		$now = App::db()->nowUtcSql();

		return $this->insertRow(
			'cpms_schedule_slots',
			[
				'clinic_id'      => $clinic_id,
				'location_id'    => $location_id,
				'clinician_id'   => $clinician_id,
				'slot_date'      => $date,
				'slot_time'      => $time,
				'duration_min'   => 20,
				'capacity'       => 1,
				'booked_count'   => $booked,
				'held_count'     => 0,
				'is_open'        => 1,
				'generated_from' => 'manual',
				'created_at'     => $now,
				'updated_at'     => $now,
			],
			[ '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ],
			'slot'
		);
	}

	private function insertAppointment(
		int $clinic_id,
		int $location_id,
		int $clinician_id,
		int $patient_id,
		int $user_id,
		int $slot_id,
		string $date,
		string $time,
		string $status,
		string $reference_code,
		string $now
	): int {
		$end_time    = ( new \DateTimeImmutable( $date . ' ' . $time, new \DateTimeZone( 'Asia/Tehran' ) ) )
			->add( new \DateInterval( 'PT20M' ) )->format( 'H:i:s' );
		$is_cancelled = str_starts_with( $status, 'cancelled_' );

		return $this->insertRow(
			'cpms_appointments',
			[
				'clinic_id'         => $clinic_id,
				'location_id'       => $location_id,
				'reference_code'    => $reference_code,
				'patient_id'        => $patient_id,
				'clinician_id'      => $clinician_id,
				'slot_id'           => $slot_id,
				'wp_user_id'        => $user_id,
				'slot_date'         => $date,
				'slot_time'         => $time,
				'duration_min'      => 20,
				'slot_end_time'     => $end_time,
				'status'            => $status,
				'is_walkin_express' => 0,
				'confirmed_at'      => $now,
				'cancelled_at'      => $is_cancelled ? $now : null,
				'created_at'        => $now,
				'updated_at'        => $now,
			],
			[ '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ],
			'appointment(' . $status . ')'
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string>         $formats
	 */
	private function insertRow( string $table, array $data, array $formats, string $label ): int
	{
		global $wpdb;
		$ok = $wpdb->insert( $wpdb->prefix . $table, $data, $formats );
		self::assertTrue( (bool) $ok, 'fixture ' . $label . ' insert failed: ' . $wpdb->last_error );
		$insert_id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $insert_id, 'fixture ' . $label . ' id must be positive/dynamic.' );

		return $insert_id;
	}

	private function referenceCode( string $suffix ): string
	{
		return 'P9S3' . strtoupper( preg_replace( '/[^a-z0-9]/i', '', $suffix ) ?? '' ) . bin2hex( random_bytes( 4 ) );
	}

	private function mobileFor( string $suffix ): string
	{
		$hash = substr( md5( 'p9s3-' . $suffix ), 0, 7 );
		$hash = strtr( $hash, [ 'a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6' ] );

		return '0918' . substr( $hash, 0, 7 );
	}

	private function ymdDaysOffset( int $days ): string
	{
		return gmdate( 'Y-m-d', time() + $days * 86400 );
	}
}
