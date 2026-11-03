<?php
/**
 * The real Doctor and Staff Portal shells must print the SHARED in-page dialog.
 *
 * Evidence that motivated this gate: `DoctorPortalShell::enqueue_for_portal()`
 * and `StaffPortalShell::enqueue_for_portal()` already enqueue `cpms-ui-text`
 * and `cpms-dialog`, but both standalone templates deliberately omit
 * wp_head()/wp_footer() and print only hand-written script lists, so
 * `window.CpmsDialog` was undefined on those surfaces. The converted doctor
 * module inline script calls `window.CpmsDialog.confirm/prompt` while it parses,
 * so the two handles must be printed in the HEAD, in dependency order, before
 * that inline script.
 *
 * Locks:
 *  1. Both rendered shells print cpms-ui-text BEFORE cpms-dialog, both inside
 *     <head> and before the first inline-script use of window.CpmsDialog.
 *  2. The handles keep their dependency order and head group after rendering.
 *  3. tests/js/doctor-shell-dialog.spec.js passes in Node against the real
 *     shipped functions — a missing node binary is a FAIL, never a skip (the
 *     exact mechanism of PkgPatientShellDialogTest / PkgNativeDialogsTest).
 *
 * The dialog is UX only: authorization stays server-side (nonce + capability +
 * scope), and a missing/cancelled dialog must never mean "confirmed".
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\DoctorPortalShell;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

final class PkgDoctorStaffShellDialogTest extends WP_UnitTestCase {

	private const UI_TEXT_JS = 'assets/js/cpms-ui-text.js';
	private const DIALOG_JS  = 'assets/js/cpms-dialog.js';
	private const SPEC_JS    = 'js/doctor-shell-dialog.spec.js';
	private const TZ_TEHRAN  = 'Asia/Tehran';

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		ScopeContext::clear();
		App::resetScope();
		SystemClinicResolver::flush();
		Settings::flushCache();
		App::migrations()->migrate();
		$this->reset_asset_state();
	}

	protected function tearDown(): void {
		$this->reset_asset_state();
		wp_set_current_user( 0 );
		$_GET                      = [];
		$_POST                     = [];
		$_REQUEST                  = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ScopeContext::clear();
		App::resetScope();
		SystemClinicResolver::flush();
		Settings::flushCache();
		parent::tearDown();
	}

	/** Let wp_print_* behave like a fresh HTTP request between simulated renders. */
	private function reset_asset_state(): void {
		$handles = [
			StaffPortalShell::UI_TEXT_HANDLE,
			StaffPortalShell::DIALOG_HANDLE,
			StaffPortalShell::JALALI_HANDLE,
			DoctorPortalShell::CSS_HANDLE,
			DoctorPortalShell::JS_HANDLE,
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

	private function make_user( string $login, string $role ): int {
		$created = wp_create_user( $login, 'pass-not-used-123', $login . '@test.local' );
		self::assertFalse( is_wp_error( $created ), 'precondition: WP user created.' );
		$id = (int) $created;
		self::assertGreaterThan( 0, $id );
		$user = get_userdata( $id );
		self::assertNotFalse( $user );
		$user->set_role( $role );
		return $id;
	}

	/** Eligible doctor with one active Clinic + Location, as in the Phase 10 fixtures. */
	private function make_doctor_stage( string $tag ): int {
		global $wpdb;
		$now  = App::db()->nowUtcSql();
		$uniq = $tag . '_' . bin2hex( random_bytes( 3 ) );

		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations '
			. '(name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
			'Dialog Org ' . $uniq, 'org-' . $uniq, 'active', $now, $now
		) );
		$org_id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $org_id );

		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics '
			. '(organization_id, name, slug, timezone, created_at, updated_at) '
			. 'VALUES (%d, %s, %s, %s, %s, %s)',
			$org_id, 'Dialog Clinic ' . $uniq, 'cl-' . $uniq,
			self::TZ_TEHRAN, $now, $now
		) );
		$clinic_id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $clinic_id );
		App::resetScope();

		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $wpdb->prefix . 'cpms_locations '
			. '(clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) '
			. 'VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
			$clinic_id, 'Dialog Loc ' . $uniq, 'loc-' . $uniq,
			self::TZ_TEHRAN, $now, $now
		) );
		self::assertGreaterThan( 0, (int) $wpdb->insert_id );

		$doctor_id = $this->make_user( 'pkgdsd_' . $uniq, RolesAndCapabilities::ROLE_DOCTOR );
		self::assertGreaterThan(
			0,
			cpms_test_seed_membership( $doctor_id, $clinic_id, RolesAndCapabilities::ROLE_DOCTOR )
		);

		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians '
			. '(clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) '
			. 'VALUES (%d, %s, %d, 1, %s, %s)',
			$clinic_id, 'Dr ' . $uniq, $doctor_id, $now, $now
		) );
		self::assertGreaterThan( 0, (int) $wpdb->insert_id );

		return $doctor_id;
	}

	/** Render a standalone CPMS portal shell through template_include. */
	private function render_shell( int $user_id, string $url ): string {
		wp_set_current_user( $user_id );
		$path         = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '/' );
		$qs           = (string) ( wp_parse_url( $url, PHP_URL_QUERY ) ?? '' );
		$request_uri  = ( '' !== $path ? $path : '/' ) . ( '' !== $qs ? '?' . $qs : '' );
		$previous_get = $_GET;
		try {
			$this->go_to( $request_uri );
			$baseline = get_stylesheet_directory() . '/page.php';
			if ( ! is_readable( $baseline ) ) {
				$baseline = get_stylesheet_directory() . '/index.php';
			}
			$template = (string) apply_filters( 'template_include', $baseline );
			self::assertNotSame( $baseline, $template, 'template_include must intercept the portal.' );
			self::assertFileExists( $template );
			ob_start();
			include $template;
			return (string) ob_get_clean();
		} finally {
			$_GET = $previous_get;
		}
	}

	/** Assert the two handles are printed in <head>, in order, before inline use. */
	private function assert_head_dialog( string $html, string $label ): void {
		$ui_text   = strpos( $html, self::UI_TEXT_JS );
		$dialog    = strpos( $html, self::DIALOG_JS );
		$head_end  = strpos( $html, '</head>' );
		$first_use = strpos( $html, 'window.CpmsDialog' );
		self::assertNotFalse( $ui_text, $label . ': must print ' . self::UI_TEXT_JS . '.' );
		self::assertNotFalse( $dialog, $label . ': must print ' . self::DIALOG_JS . '.' );
		self::assertNotFalse( $head_end, $label . ': must render a </head>.' );
		self::assertNotFalse( $first_use, $label . ': inline script must use CpmsDialog.' );
		self::assertLessThan( $dialog, $ui_text, $label . ': ui-text before dialog.' );
		self::assertLessThan( $head_end, $ui_text, $label . ': ui-text in <head>.' );
		self::assertLessThan( $head_end, $dialog, $label . ': dialog in <head>.' );
		self::assertLessThan( $first_use, $ui_text, $label . ': assets before inline use.' );
		self::assertLessThan( $first_use, $dialog, $label . ': assets before inline use.' );
	}

	/**
	 * Both shells print the shared dialog in the HEAD before the doctor module's
	 * inline script uses window.CpmsDialog.
	 */
	public function test_both_shells_print_ui_text_then_dialog_in_head(): void {
		$doctor_id = $this->make_doctor_stage( 'head' );

		$staff_page_id = StaffPortalShell::ensure_portal_page();
		self::assertGreaterThan( 0, $staff_page_id );
		$this->reset_asset_state();
		$staff_html = $this->render_shell(
			$doctor_id,
			home_url( '/?page_id=' . $staff_page_id )
		);
		self::assertStringContainsString(
			'data-role="workspace-section"',
			$staff_html,
			'precondition: doctor module mounted in the canonical shell.'
		);
		$this->assert_head_dialog( $staff_html, 'StaffPortalShell' );

		$doctor_page_id = DoctorPortalShell::ensure_portal_page();
		self::assertGreaterThan( 0, $doctor_page_id );
		$this->reset_asset_state();
		$doctor_html = $this->render_shell(
			0,
			home_url( '/?page_id=' . $doctor_page_id )
		);
		self::assertStringContainsString(
			'data-cpms-doctor-portal-shell="v1"',
			$doctor_html,
			'precondition: standalone doctor shell rendered.'
		);
		$this->assert_head_dialog( $doctor_html, 'DoctorPortalShell' );
	}

	/** Registration order + head group are the load-bearing contract. */
	public function test_dialog_handles_keep_dependency_order_and_head_group(): void {
		$doctor_id     = $this->make_doctor_stage( 'deps' );
		$staff_page_id = StaffPortalShell::ensure_portal_page();
		$this->render_shell( $doctor_id, home_url( '/?page_id=' . $staff_page_id ) );

		self::assertTrue(
			wp_script_is( StaffPortalShell::UI_TEXT_HANDLE, 'enqueued' ),
			'cpms-ui-text must be enqueued.'
		);
		self::assertTrue(
			wp_script_is( StaffPortalShell::DIALOG_HANDLE, 'enqueued' ),
			'cpms-dialog must be enqueued.'
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
	}

	/** Node gate for the converted-functions spec — missing node = FAIL, not skip. */
	public function test_doctor_shell_dialog_js_spec_passes_with_node(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		self::assertNotSame( '', $node, 'node binary must be available in CI.' );

		$spec = dirname( __DIR__ ) . '/' . self::SPEC_JS;
		self::assertFileExists( $spec );
		$output    = [];
		$exit_code = 0;
		exec( $node . ' ' . escapeshellarg( $spec ) . ' 2>&1', $output, $exit_code );

		self::assertSame(
			0,
			$exit_code,
			"doctor-shell-dialog.spec.js failed:\n" . implode( "\n", $output )
		);
	}

	/** Guard against a silent pass: the spec must exercise the real shipped source. */
	public function test_spec_is_wired_to_the_shipped_shell(): void {
		$spec = dirname( __DIR__ ) . '/' . self::SPEC_JS;
		self::assertFileExists( $spec );
		$src = (string) file_get_contents( $spec );
		self::assertStringContainsString( 'templates/doctor-portal-shell.php', $src );
		self::assertStringContainsString( 'CpmsDialog', $src );
		self::assertStringContainsString( 'Promise.resolve(null)', $src );
		self::assertStringContainsString( 'Promise.resolve(false)', $src );
	}
}
