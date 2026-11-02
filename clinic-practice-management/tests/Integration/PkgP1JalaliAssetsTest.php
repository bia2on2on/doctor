<?php
/**
 * Package P1 — Jalali date picker asset loading, scoping, and dynamic-input
 * enhancement across standalone portals.
 *
 * Verifies:
 *   1. StaffPortalShell enqueues and prints `cpms-jalali-date` (CSS + JS) with
 *      `body.cpms-jalali-scope` and `dir="rtl"` on every module that renders an
 *      `input[type="date"]` (`doctor`, `reception`, `finance`, `reports`).
 *   2. Standalone DoctorPortalShell enqueues and prints `cpms-jalali-date`
 *      (CSS + JS) with `body.cpms-jalali-scope` and `dir="rtl"` for an
 *      authenticated doctor rendering `#cpms-doc-ws-fu-date`.
 *   3. Standalone PatientPortalShell enqueues and prints `cpms-jalali-date`
 *      (CSS + JS) with `body.cpms-jalali-scope` and `dir="rtl"` for a pure
 *      patient rendering `#cpms-pp-birth_date`.
 *   4. Surfaces without any `input[type="date"]` (`ManagementPortalShell`,
 *      `PublicBookingShortcode`, anonymous portal views, and `StaffPortalShell`
 *      `messages` module) do not enqueue or print `cpms-jalali-date`.
 *   5. `tests/js/pkg-p1-jalali-dynamic.spec.js` passes in Node against the
 *      real shipped `assets/js/cpms-jalali-date.js`.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\DoctorPortalShell;
use ClinicCore\Frontend\ManagementPortalShell;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Frontend\PublicBookingAssets;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

final class PkgP1JalaliAssetsTest extends WP_UnitTestCase {

	private const TZ_TEHRAN = 'Asia/Tehran';

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

	public function test_staff_portal_date_modules_enqueue_and_print_jalali_assets(): void {
		$fx = $this->build_portal_stage( 'sp_mods' );

		$cases = [
			StaffPortalShell::MODULE_DOCTOR    => [
				'user_id'      => $fx['doctor_id'],
				'input_marker' => 'id="cpms-doc-ws-fu-date"',
			],
			StaffPortalShell::MODULE_RECEPTION => [
				'user_id'      => $fx['secretary_id'],
				'input_marker' => 'id="cpms-sr-create-birth-date"',
			],
			StaffPortalShell::MODULE_FINANCE   => [
				'user_id'      => $fx['accountant_id'],
				'input_marker' => 'data-role="finance-recon-from"',
			],
			StaffPortalShell::MODULE_REPORTS   => [
				'user_id'      => $fx['manager_id'],
				'input_marker' => 'data-role="reports-date-input"',
			],
		];

		$staff_page_id = StaffPortalShell::ensure_portal_page();
		self::assertGreaterThan( 0, $staff_page_id );
		$portal_url = home_url( '/?page_id=' . $staff_page_id );
		foreach ( $cases as $module_id => $spec ) {
			$this->reset_asset_state();
			$html = $this->render_shell(
				$spec['user_id'],
				$portal_url,
				[ StaffPortalShell::MODULE_PARAM => $module_id ]
			);

			self::assertStringContainsString(
				'dir="rtl"',
				$html,
				"StaffPortalShell ({$module_id}): document must carry dir=\"rtl\"."
			);
			self::assertStringContainsString(
				$spec['input_marker'],
				$html,
				"StaffPortalShell ({$module_id}): expected date input marker must render."
			);
			self::assertStringContainsString(
				'type="date"',
				$html,
				"StaffPortalShell ({$module_id}): module must render a real input[type=\"date\"]."
			);
			self::assertTrue(
				wp_style_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
				"StaffPortalShell ({$module_id}): cpms-jalali-date style must be enqueued."
			);
			self::assertTrue(
				wp_script_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
				"StaffPortalShell ({$module_id}): cpms-jalali-date script must be enqueued."
			);
			self::assertStringContainsString(
				'cpms-jalali-scope',
				$html,
				"StaffPortalShell ({$module_id}): body must carry cpms-jalali-scope."
			);
			self::assertStringContainsString(
				'assets/css/cpms-jalali-date.css',
				$html,
				"StaffPortalShell ({$module_id}): standalone HTML must print cpms-jalali-date.css."
			);
			self::assertStringContainsString(
				'assets/js/cpms-jalali-date.js',
				$html,
				"StaffPortalShell ({$module_id}): standalone HTML must print cpms-jalali-date.js."
			);
		}
	}

	public function test_doctor_portal_shell_loads_jalali_assets_for_doctor(): void {
		$fx             = $this->build_portal_stage( 'doc_standalone' );
		$doctor_page_id = DoctorPortalShell::ensure_portal_page();
		self::assertGreaterThan( 0, $doctor_page_id );
		$html = $this->render_shell(
			$fx['doctor_id'],
			home_url( '/?page_id=' . $doctor_page_id )
		);

		self::assertStringContainsString( 'dir="rtl"', $html );
		self::assertStringContainsString( 'id="cpms-doc-ws-fu-date"', $html );
		self::assertStringContainsString( 'type="date"', $html );
		self::assertTrue(
			wp_style_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
			'DoctorPortalShell: cpms-jalali-date style must be enqueued for an active doctor.'
		);
		self::assertTrue(
			wp_script_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
			'DoctorPortalShell: cpms-jalali-date script must be enqueued for an active doctor.'
		);
		self::assertStringContainsString(
			'cpms-jalali-scope',
			$html,
			'DoctorPortalShell: body must carry cpms-jalali-scope so widget CSS applies.'
		);
		self::assertStringContainsString(
			'assets/css/cpms-jalali-date.css',
			$html,
			'DoctorPortalShell: standalone HTML must print cpms-jalali-date.css.'
		);
		self::assertStringContainsString(
			'assets/js/cpms-jalali-date.js',
			$html,
			'DoctorPortalShell: standalone HTML must print cpms-jalali-date.js.'
		);
	}

	public function test_patient_portal_shell_loads_jalali_assets_for_patient(): void {
		$fx = $this->build_portal_stage( 'pat_shell' );
		self::assertTrue(
			PatientPortalPage::isPatientOnly( $fx['patient_user_id'] ),
			'precondition: patient user holds pure cpms_patient role.'
		);

		$patient_page_id = PatientPortalShell::ensure_portal_page();
		self::assertGreaterThan( 0, $patient_page_id );
		$html = $this->render_shell(
			$fx['patient_user_id'],
			home_url( '/?page_id=' . $patient_page_id )
		);

		self::assertStringContainsString( 'dir="rtl"', $html );
		self::assertStringContainsString( 'id="cpms-pp-birth_date"', $html );
		self::assertStringContainsString( 'type="date"', $html );
		self::assertTrue(
			wp_style_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
			'PatientPortalShell: cpms-jalali-date style must be enqueued for pure patient.'
		);
		self::assertTrue(
			wp_script_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
			'PatientPortalShell: cpms-jalali-date script must be enqueued for pure patient.'
		);
		self::assertStringContainsString(
			'cpms-jalali-scope',
			$html,
			'PatientPortalShell: body must carry cpms-jalali-scope so widget CSS applies.'
		);
		self::assertStringContainsString(
			'assets/css/cpms-jalali-date.css',
			$html,
			'PatientPortalShell: standalone HTML must print cpms-jalali-date.css.'
		);
		self::assertStringContainsString(
			'assets/js/cpms-jalali-date.js',
			$html,
			'PatientPortalShell: standalone HTML must print cpms-jalali-date.js.'
		);
	}

	public function test_surfaces_without_date_inputs_do_not_load_jalali_assets(): void {
		$fx = $this->build_portal_stage( 'no_date' );

		// 1) ManagementPortalShell has no input[type="date"].
		$this->reset_asset_state();
		$mgmt_page_id = ManagementPortalShell::ensure_portal_page();
		self::assertGreaterThan( 0, $mgmt_page_id );
		$mgmt_html = $this->render_shell(
			$fx['manager_id'],
			home_url( '/?page_id=' . $mgmt_page_id )
		);
		ManagementPortalShell::enqueue_for_portal();
		self::assertStringNotContainsString( 'type="date"', $mgmt_html );
		self::assertFalse(
			wp_style_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
			'ManagementPortalShell must not enqueue cpms-jalali-date style.'
		);
		self::assertFalse(
			wp_script_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
			'ManagementPortalShell must not enqueue cpms-jalali-date script.'
		);
		self::assertStringNotContainsString( 'cpms-jalali-date.js', $mgmt_html );

		// 2) PublicBookingShortcode ([clinic_booking]) uses slot selects, no input[type="date"].
		$this->reset_asset_state();
		$booking_page_id = (int) self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Public Booking P1',
				'post_content' => '[clinic_booking]',
			]
		);
		self::assertGreaterThan( 0, $booking_page_id );
		$this->go_to( home_url( '/?page_id=' . $booking_page_id ) );
		PublicBookingAssets::enqueueForCurrentPage();
		$booking_html = (string) do_shortcode( '[clinic_booking]' );
		self::assertStringNotContainsString( 'type="date"', $booking_html );
		self::assertFalse(
			wp_style_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
			'PublicBookingShortcode must not enqueue cpms-jalali-date style.'
		);
		self::assertFalse(
			wp_script_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
			'PublicBookingShortcode must not enqueue cpms-jalali-date script.'
		);

		// 3) Anonymous login views and StaffPortalShell messages module have no input[type="date"].
		$patient_page_id = PatientPortalShell::ensure_portal_page();
		$doctor_page_id  = DoctorPortalShell::ensure_portal_page();
		$staff_page_id   = StaffPortalShell::ensure_portal_page();
		self::assertGreaterThan( 0, $patient_page_id );
		self::assertGreaterThan( 0, $doctor_page_id );
		self::assertGreaterThan( 0, $staff_page_id );

		$non_date_views = [
			'patient_anon'   => [ 0, home_url( '/?page_id=' . $patient_page_id ), [] ],
			'doctor_anon'    => [ 0, home_url( '/?page_id=' . $doctor_page_id ), [] ],
			'staff_anon'     => [ 0, home_url( '/?page_id=' . $staff_page_id ), [] ],
			'staff_messages' => [
				$fx['secretary_id'],
				home_url( '/?page_id=' . $staff_page_id ),
				[ StaffPortalShell::MODULE_PARAM => StaffPortalShell::MODULE_MESSAGES ],
			],
		];

		foreach ( $non_date_views as $label => $view ) {
			$this->reset_asset_state();
			$view_html = $this->render_shell( $view[0], $view[1], $view[2] );
			self::assertStringNotContainsString(
				'type="date"',
				$view_html,
				"{$label}: precondition — surface renders no input[type=\"date\"]."
			);
			self::assertFalse(
				wp_style_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
				"{$label}: must not enqueue cpms-jalali-date style when no date input exists."
			);
			self::assertFalse(
				wp_script_is( StaffPortalShell::JALALI_HANDLE, 'enqueued' ),
				"{$label}: must not enqueue cpms-jalali-date script when no date input exists."
			);
			self::assertStringNotContainsString(
				'cpms-jalali-date.js',
				$view_html,
				"{$label}: must not print cpms-jalali-date.js when no date input exists."
			);
		}
	}

	public function test_dynamic_jalali_date_js_spec_passes(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		self::assertNotSame( '', $node, 'node binary must be available in CI.' );

		$spec = dirname( __DIR__ ) . '/js/pkg-p1-jalali-dynamic.spec.js';
		self::assertFileExists( $spec );

		$output    = [];
		$exit_code = 0;
		exec( $node . ' ' . escapeshellarg( $spec ) . ' 2>&1', $output, $exit_code );

		self::assertSame(
			0,
			$exit_code,
			"pkg-p1-jalali-dynamic.spec.js failed:\n" . implode( "\n", $output )
		);
	}

	/**
	 * Render a standalone CPMS portal shell through `template_include`.
	 *
	 * @param int                   $user_id User ID (0 for anonymous).
	 * @param string                $url     Portal permalink.
	 * @param array<string, string> $query   Extra GET parameters.
	 */
	private function render_shell( int $user_id, string $url, array $query = [] ): string {
		wp_set_current_user( $user_id );
		$target       = [] !== $query ? add_query_arg( $query, $url ) : $url;
		$path         = (string) ( wp_parse_url( $target, PHP_URL_PATH ) ?? '/' );
		$qs           = (string) ( wp_parse_url( $target, PHP_URL_QUERY ) ?? '' );
		$request_uri  = ( '' !== $path ? $path : '/' ) . ( '' !== $qs ? '?' . $qs : '' );
		$previous_get = $_GET;

		try {
			$this->go_to( $request_uri );
			foreach ( $query as $k => $v ) {
				$_GET[ $k ] = $v;
			}
			$baseline = get_stylesheet_directory() . '/page.php';
			if ( ! is_readable( $baseline ) ) {
				$baseline = get_stylesheet_directory() . '/index.php';
			}
			$template = (string) apply_filters( 'template_include', $baseline );
			self::assertNotSame(
				$baseline,
				$template,
				'template_include must intercept portal request.'
			);
			self::assertFileExists( $template );

			ob_start();
			include $template;

			return (string) ob_get_clean();
		} finally {
			$_GET = $previous_get;
		}
	}

	/**
	 * Reset queued and printed handle state between simulated requests so
	 * `wp_print_styles` / `wp_print_scripts` behave like a fresh HTTP request.
	 */
	private function reset_asset_state(): void {
		$handles = [
			StaffPortalShell::JALALI_HANDLE,
			StaffPortalShell::UI_TEXT_HANDLE,
			StaffPortalShell::DIALOG_HANDLE,
			DoctorPortalShell::CSS_HANDLE,
			DoctorPortalShell::JS_HANDLE,
			DoctorPortalShell::HANDWRITING_HANDLE,
			PatientPortalShell::CSS_HANDLE,
			PatientPortalShell::JS_HANDLE,
			ManagementPortalShell::JS_HANDLE,
			ManagementPortalShell::CATALOG_JS_HANDLE,
			PublicBookingAssets::HANDLE,
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

	/**
	 * Seed one Clinic stage with distinct persisted users and fixture IDs > 0.
	 *
	 * @return array{
	 *     org_id: int,
	 *     clinic_id: int,
	 *     location_id: int,
	 *     clinician_id: int,
	 *     doctor_id: int,
	 *     secretary_id: int,
	 *     accountant_id: int,
	 *     manager_id: int,
	 *     patient_user_id: int,
	 *     patient_id: int,
	 *     patient_link_id: int
	 * }
	 */
	private function build_portal_stage( string $tag ): array {
		global $wpdb;
		$now  = App::db()->nowUtcSql();
		$uniq = $tag . '_' . bin2hex( random_bytes( 3 ) );

		$ok = $wpdb->insert(
			$wpdb->prefix . 'cpms_organizations',
			[
				'name'       => 'P1 Org ' . $uniq,
				'slug'       => 'p1-org-' . bin2hex( random_bytes( 3 ) ),
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);
		self::assertTrue( (bool) $ok, 'organization insert failed: ' . $wpdb->last_error );
		$org_id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $org_id );

		$ok = $wpdb->insert(
			$wpdb->prefix . 'cpms_clinics',
			[
				'organization_id' => $org_id,
				'name'            => 'P1 Clinic ' . $uniq,
				'slug'            => 'p1-cl-' . bin2hex( random_bytes( 3 ) ),
				'timezone'        => self::TZ_TEHRAN,
				'created_at'      => $now,
				'updated_at'      => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
		self::assertTrue( (bool) $ok, 'clinic insert failed: ' . $wpdb->last_error );
		$clinic_id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $clinic_id );
		App::resetScope();

		$ok = $wpdb->insert(
			$wpdb->prefix . 'cpms_locations',
			[
				'clinic_id'  => $clinic_id,
				'name'       => 'P1 Loc ' . $uniq,
				'slug'       => 'p1-loc-' . bin2hex( random_bytes( 3 ) ),
				'timezone'   => self::TZ_TEHRAN,
				'is_primary' => 1,
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
		);
		self::assertTrue( (bool) $ok, 'location insert failed: ' . $wpdb->last_error );
		$location_id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $location_id );

		$doctor_id     = $this->make_user(
			'p1_doc_' . $uniq,
			RolesAndCapabilities::ROLE_DOCTOR
		);
		$secretary_id  = $this->make_user(
			'p1_sec_' . $uniq,
			RolesAndCapabilities::ROLE_SECRETARY
		);
		$accountant_id = $this->make_user(
			'p1_acc_' . $uniq,
			RolesAndCapabilities::ROLE_ACCOUNTANT
		);
		$manager_id    = $this->make_user(
			'p1_mgr_' . $uniq,
			RolesAndCapabilities::ROLE_MANAGER
		);

		$doc_mem_id = cpms_test_seed_membership(
			$doctor_id,
			$clinic_id,
			RolesAndCapabilities::ROLE_DOCTOR
		);
		$sec_mem_id = cpms_test_seed_membership(
			$secretary_id,
			$clinic_id,
			RolesAndCapabilities::ROLE_SECRETARY
		);
		$acc_mem_id = cpms_test_seed_membership(
			$accountant_id,
			$clinic_id,
			RolesAndCapabilities::ROLE_ACCOUNTANT
		);
		$mgr_mem_id = cpms_test_seed_membership(
			$manager_id,
			$clinic_id,
			RolesAndCapabilities::ROLE_MANAGER
		);
		self::assertGreaterThan( 0, $doc_mem_id );
		self::assertGreaterThan( 0, $sec_mem_id );
		self::assertGreaterThan( 0, $acc_mem_id );
		self::assertGreaterThan( 0, $mgr_mem_id );

		// Distinct non-zero wp_user_id on clinician row (never reused across clinicians).
		$ok = $wpdb->insert(
			$wpdb->prefix . 'cpms_clinicians',
			[
				'clinic_id'  => $clinic_id,
				'full_name'  => 'Dr P1 ' . $uniq,
				'wp_user_id' => $doctor_id,
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%d', '%d', '%s', '%s' ]
		);
		self::assertTrue( (bool) $ok, 'clinician insert failed: ' . $wpdb->last_error );
		$clinician_id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $clinician_id );

		$patient_user_id = $this->make_user(
			'p1_pat_' . $uniq,
			RolesAndCapabilities::ROLE_PATIENT
		);
		$mobile          = '0912' . sprintf( '%07d', random_int( 1000000, 9999999 ) );
		$mrn             = 'MR-P1-' . strtoupper( bin2hex( random_bytes( 3 ) ) );

		$ok = $wpdb->insert(
			$wpdb->prefix . 'cpms_patients',
			[
				'clinic_id'  => $clinic_id,
				'mrn'        => $mrn,
				'first_name' => 'Sara',
				'last_name'  => 'P1',
				'mobile'     => $mobile,
				'birth_date' => '2012-03-20',
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		self::assertTrue( (bool) $ok, 'patient insert failed: ' . $wpdb->last_error );
		$patient_id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $patient_id );

		$ok = $wpdb->insert(
			$wpdb->prefix . 'cpms_patient_user_links',
			[
				'clinic_id'      => $clinic_id,
				'patient_id'     => $patient_id,
				'wp_user_id'     => $patient_user_id,
				'mobile_at_link' => $mobile,
				'is_primary'     => 1,
				'linked_at'      => $now,
			],
			[ '%d', '%d', '%d', '%s', '%d', '%s' ]
		);
		self::assertTrue( (bool) $ok, 'patient_user_links insert failed: ' . $wpdb->last_error );
		$patient_link_id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $patient_link_id );

		return [
			'org_id'          => $org_id,
			'clinic_id'       => $clinic_id,
			'location_id'     => $location_id,
			'clinician_id'    => $clinician_id,
			'doctor_id'       => $doctor_id,
			'secretary_id'    => $secretary_id,
			'accountant_id'   => $accountant_id,
			'manager_id'      => $manager_id,
			'patient_user_id' => $patient_user_id,
			'patient_id'      => $patient_id,
			'patient_link_id' => $patient_link_id,
		];
	}

	private function make_user( string $login, string $role ): int {
		$user_id = (int) wp_create_user( $login, 'pass-not-used-123', $login . '@test.local' );
		self::assertGreaterThan( 0, $user_id );
		$user = get_userdata( $user_id );
		self::assertNotFalse( $user );
		$user->set_role( $role );

		return $user_id;
	}
}
