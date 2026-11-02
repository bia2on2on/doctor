<?php
/**
 * The real Patient Portal shell must print the SHARED in-page dialog.
 *
 * Evidence that motivated this gate:
 *  - `PatientPortalShell::enqueue_for_portal()` printed ONLY the portal handle,
 *    so `window.CpmsDialog` never existed on the standalone frontend shell and
 *    `assets/js/cpms-patient-portal.js` fell back to its portal-owned modal.
 *  - The shipped Chromium journey `bin/pilot-slice-portal-cancel.py` waits for
 *    `.cpms-modal[role="dialog"]` and clicks `.cpms-modal-confirm`, while
 *    `bin/rwp-acceptance.py::capture_confirm()` needs `.cpms-modal-overlay` and a
 *    focused element whose class contains `confirm`.
 *
 * This test locks the production wiring:
 *  1. The shell HTML prints `cpms-ui-text` before `cpms-dialog`, both in <head>
 *     and before the footer portal script (same dependency order the doctor
 *     shell registers: cpms-dialog depends on cpms-ui-text).
 *  2. The handles keep that dependency order after registration.
 *  3. `tests/js/pkg-patient-shell-dialog.spec.js` passes in Node against the real
 *     shipped dialog asset — missing node is a FAIL, never a skip (the exact
 *     mechanism of PkgNativeDialogsTest / PkgP1JalaliAssetsTest).
 *
 * The dialog asset is UX only: authorization stays server-side (nonce +
 * capability + scope) and a missing dialog must never mean "confirmed".
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

final class PkgPatientShellDialogTest extends WP_UnitTestCase {

	private const DIALOG_JS = 'assets/js/cpms-dialog.js';
	private const SPEC_JS   = 'js/pkg-patient-shell-dialog.spec.js';

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		ScopeContext::clear();
		App::resetScope();
		Settings::flushCache();
		App::migrations()->migrate();
		$this->reset_asset_state();
	}

	protected function tearDown(): void {
		$this->reset_asset_state();
		wp_set_current_user( 0 );
		$_GET = [];
		ScopeContext::clear();
		App::resetScope();
		Settings::flushCache();
		parent::tearDown();
	}

	/**
	 * Drop registration/queue/printed state so `wp_print_*` behaves like a fresh
	 * HTTP request between simulated renders.
	 */
	private function reset_asset_state(): void {
		$handles = [
			StaffPortalShell::UI_TEXT_HANDLE,
			StaffPortalShell::DIALOG_HANDLE,
			StaffPortalShell::JALALI_HANDLE,
			PatientPortalShell::CSS_HANDLE,
			PatientPortalShell::JS_HANDLE,
		];

		$styles  = wp_styles();
		$scripts = wp_scripts();
		foreach ( $handles as $handle ) {
			wp_dequeue_style( $handle );
			wp_dequeue_script( $handle );
			$styles->done  = array_values( array_diff( $styles->done, [ $handle ] ) );
			$scripts->done = array_values( array_diff( $scripts->done, [ $handle ] ) );
		}
	}

	private function make_patient_user( string $tag ): int {
		$login = 'pkgpsd_' . $tag . '_' . uniqid( '', false );
		$id    = (int) wp_create_user( $login, 'pass-not-used-123', $login . '@test.local' );
		self::assertGreaterThan( 0, $id, 'precondition: WP user created.' );
		$user = get_userdata( $id );
		self::assertNotFalse( $user );
		$user->set_role( RolesAndCapabilities::ROLE_PATIENT );

		return $id;
	}

	/**
	 * Render the standalone Patient Portal shell through `template_include`,
	 * exactly like PkgP1JalaliAssetsTest::render_shell().
	 */
	private function render_patient_shell( int $user_id ): string {
		wp_set_current_user( $user_id );
		$page_id = PatientPortalShell::ensure_portal_page();
		self::assertGreaterThan( 0, $page_id );
		$target       = home_url( '/?page_id=' . $page_id );
		$path         = (string) ( wp_parse_url( $target, PHP_URL_PATH ) ?? '/' );
		$qs           = (string) ( wp_parse_url( $target, PHP_URL_QUERY ) ?? '' );
		$request_uri  = ( '' !== $path ? $path : '/' ) . ( '' !== $qs ? '?' . $qs : '' );
		$previous_get = $_GET;

		try {
			$this->go_to( $request_uri );
			$_GET['page_id'] = (string) $page_id;
			$baseline = get_stylesheet_directory() . '/page.php';
			if ( ! is_readable( $baseline ) ) {
				$baseline = get_stylesheet_directory() . '/index.php';
			}
			$template = (string) apply_filters( 'template_include', $baseline );
			self::assertNotSame( $baseline, $template, 'template_include must intercept portal.' );
			self::assertFileExists( $template );

			ob_start();
			include $template;

			return (string) ob_get_clean();
		} finally {
			$_GET = $previous_get;
		}
	}

	/**
	 * The shell must print cpms-ui-text BEFORE cpms-dialog, both in <head> and
	 * before the footer portal script — otherwise the browser evaluates a dialog
	 * that has no text helper (its only dependency).
	 */
	public function test_patient_shell_prints_ui_text_then_dialog(): void {
		$user_id = $this->make_patient_user( 'order' );
		$html    = $this->render_patient_shell( $user_id );

		$ui_text = strpos( $html, 'assets/js/cpms-ui-text.js' );
		$dialog  = strpos( $html, 'assets/js/cpms-dialog.js' );
		$portal  = strpos( $html, 'assets/js/cpms-patient-portal.js' );

		self::assertNotFalse( $ui_text, 'the patient shell must print assets/js/cpms-ui-text.js.' );
		self::assertNotFalse( $dialog, 'the patient shell must print assets/js/cpms-dialog.js.' );
		self::assertNotFalse( $portal, 'the patient shell must still print the portal script.' );
		self::assertLessThan(
			$dialog,
			$ui_text,
			'cpms-ui-text must be printed before cpms-dialog (cpms-dialog depends on it).'
		);
		self::assertLessThan(
			$portal,
			$dialog,
			'the shared dialog must be printed before the footer portal script that uses it.'
		);

		$head_end = strpos( $html, '</head>' );
		self::assertNotFalse( $head_end, 'the standalone shell must keep a </head>.' );
		self::assertLessThan(
			$head_end,
			$dialog,
			'the dialog loads in the head, like the staff/doctor shells register it.'
		);
	}

	/**
	 * Registration order + group are the load-bearing contract: the dialog is a
	 * head handle whose dependency list contains the text helper.
	 */
	public function test_dialog_handles_keep_dependency_order_and_head_group(): void {
		$user_id = $this->make_patient_user( 'deps' );
		$this->render_patient_shell( $user_id );

		self::assertTrue(
			wp_script_is( StaffPortalShell::UI_TEXT_HANDLE, 'enqueued' ),
			'cpms-ui-text must be enqueued on the patient shell.'
		);
		self::assertTrue(
			wp_script_is( StaffPortalShell::DIALOG_HANDLE, 'enqueued' ),
			'cpms-dialog must be enqueued on the patient shell.'
		);

		$dialog = wp_scripts()->registered[ StaffPortalShell::DIALOG_HANDLE ] ?? null;
		self::assertNotNull( $dialog, 'cpms-dialog must be registered.' );
		self::assertStringContainsString( '/assets/js/cpms-dialog.js', (string) $dialog->src );
		self::assertContains( StaffPortalShell::UI_TEXT_HANDLE, (array) $dialog->deps );
		self::assertSame(
			0,
			(int) wp_scripts()->get_data( StaffPortalShell::DIALOG_HANDLE, 'group' ),
			'the dialog stays a head handle.'
		);
		self::assertSame(
			1,
			(int) wp_scripts()->get_data( PatientPortalShell::JS_HANDLE, 'group' ),
			'the portal script stays in the footer.'
		);
	}

	/**
	 * Node gate for the compatibility-class spec — EXACT PkgNativeDialogsTest
	 * mechanism: missing node = FAIL (assertNotSame), never a skip.
	 */
	public function test_patient_shell_dialog_js_spec_passes_with_node(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		self::assertNotSame( '', $node, 'node binary must be available in CI.' );

		$spec = dirname( __DIR__ ) . '/' . self::SPEC_JS;
		self::assertFileExists( $spec );

		$output   = [];
		$exit_code = 0;
		exec( $node . ' ' . escapeshellarg( $spec ) . ' 2>&1', $output, $exit_code );

		self::assertSame(
			0,
			$exit_code,
			"pkg-patient-shell-dialog.spec.js failed:\n" . implode( "\n", $output )
		);
	}

	/**
	 * Guard against a silent pass, and against a "compatibility class" that
	 * replaces the shipped token instead of adding to it.
	 */
	public function test_spec_is_wired_and_dialog_markup_is_additive(): void {
		$spec = dirname( __DIR__ ) . '/' . self::SPEC_JS;
		self::assertFileExists( $spec );
		$spec_src = (string) file_get_contents( $spec );
		self::assertStringContainsString( self::DIALOG_JS, $spec_src );
		self::assertStringContainsString( 'pilot-slice-portal-cancel.py', $spec_src );

		$dialog_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . self::DIALOG_JS );
		self::assertStringContainsString( 'cpms-modal-overlay', $dialog_js );
		self::assertStringContainsString( 'cpms-modal-confirm', $dialog_js );
		self::assertStringContainsString(
			'cpms-dialog cpms-modal',
			$dialog_js,
			'the dialog root must keep cpms-dialog and add cpms-modal (additive only).'
		);
	}
}
