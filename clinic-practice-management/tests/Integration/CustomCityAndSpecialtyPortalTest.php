<?php
/**
 * Day-2 frontend operations + the province/city and specialty "Other / سایر"
 * improvements, exercised through the REAL published portal dispatch path and the
 * REAL portal template (no mocks, no synthetic markup, real migrations + MySQL).
 *
 * Covered contracts:
 *  - a Clinic Manager (not a WordPress Administrator) enrolls operational staff and
 *    registers Doctors through the frontend Management Portal;
 *  - a city missing from the offline catalogue can be entered manually, is bound to
 *    the selected province, is validated server-side, persists with an explicit
 *    marker and is redisplayed in the manual field (canonical values never mix with
 *    user-defined ones);
 *  - the specialty catalogue persists a canonical label as canonical and a manual
 *    label verbatim, and rejects unusable input before any write;
 *  - a SUSPENDED Clinic accepts no NEW enrollment while existing memberships and
 *    historical records survive untouched;
 *  - an actor without authority in the target Clinic is denied and nothing is written
 *    (cross-Clinic denial).
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Geography\IranProvinceCityCatalog;
use ClinicCore\Frontend\ManagementPortalController;
use ClinicCore\Frontend\ManagementPortalShell;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

final class CustomCityAndSpecialtyPortalTest extends WP_UnitTestCase {

	use RealTableMigrations;

	private int $org_id          = 0;
	private int $org_mgr_id      = 0;
	private int $clinic_mgr_id   = 0;
	private int $clinic_id       = 0;
	private int $other_clinic_id = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->withRealTables( static fn(): array => App::migrations()->migrate() );
		App::resetScope();

		$db  = App::db();
		$now = $db->nowUtcSql();

		$db->insert(
			'cpms_organizations',
			array(
				'name'       => 'Day2 Org ' . bin2hex( random_bytes( 3 ) ),
				'slug'       => 'day2-org-' . bin2hex( random_bytes( 4 ) ),
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$this->org_id     = (int) $db->wpdb_last_insert_id();
		$this->org_mgr_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$db->insert(
			'cpms_organization_memberships',
			array(
				'organization_id'       => $this->org_id,
				'wp_user_id'            => $this->org_mgr_id,
				'role_key'              => 'organization_manager',
				'status'                => 'active',
				'created_by_wp_user_id' => $this->org_mgr_id,
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);

		// Both Clinics are created through the SAME frontend dispatch path the portal
		// uses (Organization authority), never through a direct insert.
		$created = ManagementPortalController::dispatch(
			$this->org_mgr_id,
			'org_create_clinic',
			array(
				'organization_id'     => (string) $this->org_id,
				'name'                => 'Day2 Clinic',
				'slug'                => 'day2-clinic-' . bin2hex( random_bytes( 3 ) ),
				'timezone'            => 'Asia/Tehran',
				'location_name'       => 'Main',
				'address'             => 'خیابان ولیعصر، پلاک ۱',
				'address_province'    => 'تهران',
				'address_city'        => 'تهران',
			)
		);
		self::assertTrue( $created['ok'], 'fixture: valid Tehran clinic is accepted' );
		$this->clinic_id = (int) $db->fetchValue(
			'SELECT id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE organization_id = %d ORDER BY id ASC LIMIT 1',
			array( $this->org_id )
		);

		$created_other = ManagementPortalController::dispatch(
			$this->org_mgr_id,
			'org_create_clinic',
			array(
				'organization_id'  => (string) $this->org_id,
				'name'             => 'Day2 Other Clinic',
				'slug'             => 'day2-other-' . bin2hex( random_bytes( 3 ) ),
				'timezone'         => 'Asia/Tehran',
				'location_name'    => 'Main',
				'address_province' => 'اصفهان',
				'address_city'     => 'اصفهان',
			)
		);
		self::assertTrue( $created_other['ok'], 'fixture: second clinic is accepted' );
		$this->other_clinic_id = (int) $db->fetchValue(
			'SELECT id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE organization_id = %d ORDER BY id DESC LIMIT 1',
			array( $this->org_id )
		);
		self::assertGreaterThan( 0, $this->clinic_id );
		self::assertGreaterThan( 0, $this->other_clinic_id );
		self::assertNotSame( $this->clinic_id, $this->other_clinic_id );

		// A Clinic Manager who is ONLY a WordPress Subscriber: no wp-admin, no
		// automatic tenant authority.
		$this->clinic_mgr_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$designated          = ManagementPortalController::dispatch(
			$this->org_mgr_id,
			'org_designate_clinic_manager',
			array(
				'organization_id' => (string) $this->org_id,
				'clinic_id'       => (string) $this->clinic_id,
				'target_user_id'  => (string) $this->clinic_mgr_id,
			)
		);
		self::assertTrue( $designated['ok'], 'fixture: Clinic Manager designation succeeds — ' . ( $designated['message'] ?? '' ) );

		wp_set_current_user( $this->clinic_mgr_id );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		$_GET = array();
		$db   = App::db();
		foreach ( array( $this->clinic_id, $this->other_clinic_id ) as $cid ) {
			if ( $cid > 0 ) {
				$db->query( 'DELETE FROM ' . $db->table( 'cpms_audit_logs' ) . ' WHERE clinic_id = %d', array( $cid ) );
				$db->query( 'DELETE FROM ' . $db->table( 'cpms_clinicians' ) . ' WHERE clinic_id = %d', array( $cid ) );
				$db->query( 'DELETE FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d', array( $cid ) );
				$db->query( 'DELETE FROM ' . $db->table( 'cpms_locations' ) . ' WHERE clinic_id = %d', array( $cid ) );
			}
		}
		if ( $this->org_id > 0 ) {
			$db->query( 'DELETE FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE organization_id = %d', array( $this->org_id ) );
			$db->query( 'DELETE FROM ' . $db->table( 'cpms_organization_memberships' ) . ' WHERE organization_id = %d', array( $this->org_id ) );
			$db->query( 'DELETE FROM ' . $db->table( 'cpms_organizations' ) . ' WHERE id = %d', array( $this->org_id ) );
		}
		foreach ( array( $this->org_mgr_id, $this->clinic_mgr_id ) as $uid ) {
			if ( $uid > 0 ) {
				wp_delete_user( $uid );
			}
		}
		App::resetScope();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// helpers
	// ------------------------------------------------------------------
	private function stored_clinic_address( int $clinic_id ): string {
		return (string) App::db()->fetchValue(
			'SELECT address FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
			array( $clinic_id )
		);
	}

	private function render_portal( int $clinic_id ): string {
		$_GET = array( ManagementPortalShell::CLINIC_PARAM => (string) $clinic_id );
		if ( function_exists( 'wp_scripts' ) && isset( wp_scripts()->done ) ) {
			wp_scripts()->done = array();
		}
		ob_start();
		include ManagementPortalShell::template_path();
		return (string) ob_get_clean();
	}

	private function enroll_doctor( string $email ): int {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber', 'user_email' => $email ) );
		$result  = ManagementPortalController::dispatch(
			$this->clinic_mgr_id,
			'clinic_staff_enroll',
			array(
				'clinic_id'  => (string) $this->clinic_id,
				'identifier' => $email,
				'role_key'   => 'doctor',
			)
		);
		self::assertTrue( $result['ok'], 'fixture: Clinic Manager enrolls a Doctor — ' . ( $result['message'] ?? '' ) );

		return $user_id;
	}

	// ------------------------------------------------------------------
	// Province/city — "Other / سایر" manual entry through the real portal
	// ------------------------------------------------------------------
	public function testManualCityPersistsThroughThePortalBoundToItsProvince(): void {
		$result = ManagementPortalController::dispatch(
			$this->org_mgr_id,
			'org_update_clinic',
			array(
				'organization_id'     => (string) $this->org_id,
				'clinic_id'           => (string) $this->clinic_id,
				'name'                => 'Day2 Clinic',
				'address'             => 'خیابان آزادی، پلاک ۱۲',
				'address_province'    => 'تهران',
				'address_city'        => IranProvinceCityCatalog::OTHER_CITY_SENTINEL,
				'address_city_custom' => 'پردیس',
			)
		);
		self::assertTrue( $result['ok'], (string) ( $result['message'] ?? '' ) );

		$stored = $this->stored_clinic_address( $this->clinic_id );
		self::assertSame( 'تهران، سایر: پردیس، خیابان آزادی، پلاک ۱۲', $stored );

		$parsed = IranProvinceCityCatalog::parse_address( $stored );
		self::assertSame( 'تهران', $parsed['province'] );
		self::assertSame( '', $parsed['city'], 'a user-defined city is never reported as canonical' );
		self::assertSame( 'پردیس', $parsed['city_custom'] );
		self::assertSame( 'خیابان آزادی، پلاک ۱۲', $parsed['detail'] );

		// The real portal document redisplay: "Other" selected + the typed city in the
		// manual field, and the sentinel itself is never rendered as a city name.
		$html = $this->render_portal( $this->clinic_id );
		self::assertStringContainsString( 'data-cpms-city-custom-input="1"', $html );
		self::assertStringContainsString( 'name="address_city_custom"', $html );
		self::assertStringContainsString( 'value="پردیس"', $html );
		self::assertStringContainsString( 'data-cpms-city-other="1" selected', $html );
		self::assertStringContainsString( '/assets/js/cpms-catalog-fields.js', $html );
		self::assertStringNotContainsString( '>پردیس</option>', $html, 'a manual city must not be injected into the canonical catalogue list' );
	}

	public function testManualCityIsRejectedServerSideWithoutAProvinceOrWithGarbage(): void {
		$before = $this->stored_clinic_address( $this->clinic_id );

		// City without province: refused, durable value untouched.
		$no_province = ManagementPortalController::dispatch(
			$this->org_mgr_id,
			'org_update_clinic',
			array(
				'organization_id'     => (string) $this->org_id,
				'clinic_id'           => (string) $this->clinic_id,
				'name'                => 'Day2 Clinic',
				'address'             => 'خیابان آزمون',
				'address_province'    => '',
				'address_city'        => IranProvinceCityCatalog::OTHER_CITY_SENTINEL,
				'address_city_custom' => 'پردیس',
			)
		);
		self::assertFalse( $no_province['ok'], 'server-side rejection is mandatory (client filtering is never trusted)' );
		self::assertSame( $before, $this->stored_clinic_address( $this->clinic_id ) );

		// Cross-province canonical city: still refused.
		$cross = ManagementPortalController::dispatch(
			$this->org_mgr_id,
			'org_update_clinic',
			array(
				'organization_id'  => (string) $this->org_id,
				'clinic_id'        => (string) $this->clinic_id,
				'name'             => 'Day2 Clinic',
				'address'          => 'خیابان آزمون',
				'address_province' => 'تهران',
				'address_city'     => 'تبریز',
			)
		);
		self::assertFalse( $cross['ok'] );
		self::assertSame( $before, $this->stored_clinic_address( $this->clinic_id ) );

		// Unusable manual text on an explicit "Other" choice: refused, never silently dropped.
		$garbage = ManagementPortalController::dispatch(
			$this->org_mgr_id,
			'org_update_clinic',
			array(
				'organization_id'     => (string) $this->org_id,
				'clinic_id'           => (string) $this->clinic_id,
				'name'                => 'Day2 Clinic',
				'address'             => 'خیابان آزمون',
				'address_province'    => 'تهران',
				'address_city'        => IranProvinceCityCatalog::OTHER_CITY_SENTINEL,
				'address_city_custom' => str_repeat( 'ش', 120 ),
			)
		);
		self::assertFalse( $garbage['ok'] );
		self::assertSame( $before, $this->stored_clinic_address( $this->clinic_id ) );
	}

	// ------------------------------------------------------------------
	// Specialty catalogue — canonical + "Other / سایر" through the portal
	// ------------------------------------------------------------------
	public function testClinicianSpecialtyPersistsCanonicalAndManualLabelsExactly(): void {
		$doctor_id = $this->enroll_doctor( 'day2-doctor-' . bin2hex( random_bytes( 3 ) ) . '@example.com' );

		$canonical = ManagementPortalController::dispatch(
			$this->clinic_mgr_id,
			'clinic_clinician_register',
			array(
				'clinic_id'      => (string) $this->clinic_id,
				'full_name'      => 'دکتر کاننیکال',
				'specialty'      => 'متخصص قلب و عروق',
				'target_user_id' => (string) $doctor_id,
			)
		);
		self::assertTrue( $canonical['ok'], (string) ( $canonical['message'] ?? '' ) );
		self::assertSame(
			'متخصص قلب و عروق',
			(string) App::db()->fetchValue(
				'SELECT specialty FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE clinic_id = %d AND full_name = %s',
				array( $this->clinic_id, 'دکتر کاننیکال' )
			)
		);

		$custom = ManagementPortalController::dispatch(
			$this->clinic_mgr_id,
			'clinic_clinician_register',
			array(
				'clinic_id'        => (string) $this->clinic_id,
				'full_name'        => 'دکتر سایری',
				'specialty'        => '__other__',
				'specialty_custom' => 'فلوشیپ ناباروری',
			)
		);
		self::assertTrue( $custom['ok'], (string) ( $custom['message'] ?? '' ) );
		self::assertSame(
			'فلوشیپ ناباروری',
			(string) App::db()->fetchValue(
				'SELECT specialty FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE clinic_id = %d AND full_name = %s',
				array( $this->clinic_id, 'دکتر سایری' )
			),
			'a user-defined specialty is persisted verbatim and never coerced'
		);

		// The portal re-displays the persisted specialty (proof of round-trip).
		$html = $this->render_portal( $this->clinic_id );
		self::assertStringContainsString( 'فلوشیپ ناباروری', $html );
		self::assertStringContainsString( 'data-cpms-specialty-field="1"', $html );
		self::assertStringContainsString( 'name="specialty_custom"', $html );
		self::assertStringContainsString( '/assets/js/cpms-catalog-fields.js', $html );
	}

	public function testUnusableSpecialtyIsRejectedBeforeAnyWrite(): void {
		$result = ManagementPortalController::dispatch(
			$this->clinic_mgr_id,
			'clinic_clinician_register',
			array(
				'clinic_id'        => (string) $this->clinic_id,
				'full_name'        => 'دکتر نامعتبر',
				'specialty'        => '__other__',
				'specialty_custom' => str_repeat( 'ت', 160 ),
			)
		);
		self::assertFalse( $result['ok'] );
		self::assertSame(
			0,
			(int) App::db()->fetchValue(
				'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE clinic_id = %d AND full_name = %s',
				array( $this->clinic_id, 'دکتر نامعتبر' )
			),
			'nothing is written when the specialty is rejected'
		);
	}

	// ------------------------------------------------------------------
	// Day-2 authorization + lifecycle
	// ------------------------------------------------------------------
	public function testSuspendedClinicRejectsNewEnrollmentButPreservesExistingMembership(): void {
		$doctor_id = $this->enroll_doctor( 'day2-keep-' . bin2hex( random_bytes( 3 ) ) . '@example.com' );
		$db        = App::db();
		self::assertSame(
			'active',
			(string) $db->fetchValue(
				'SELECT status FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d AND wp_user_id = %d',
				array( $this->clinic_id, $doctor_id )
			)
		);

		$suspend = ManagementPortalController::dispatch(
			$this->org_mgr_id,
			'org_clinic_status',
			array(
				'organization_id' => (string) $this->org_id,
				'clinic_id'       => (string) $this->clinic_id,
				'active'          => '0',
			)
		);
		self::assertTrue( $suspend['ok'], 'fixture: Clinic is suspended' );
		self::assertSame(
			'suspended',
			(string) $db->fetchValue( 'SELECT status FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d', array( $this->clinic_id ) )
		);

		// NEW enrollment is refused while suspended.
		$refused = ManagementPortalController::dispatch(
			$this->clinic_mgr_id,
			'clinic_staff_enroll',
			array(
				'clinic_id'  => (string) $this->clinic_id,
				'identifier' => 'day2-late-refused@example.com',
				'role_key'   => 'secretary',
			)
		);
		self::assertFalse( $refused['ok'], 'a suspended Clinic accepts no new staff enrollment' );
		self::assertSame(
			1,
			(int) $db->fetchValue(
				'SELECT COUNT(*) FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d AND wp_user_id <> %d',
				array( $this->clinic_id, $this->clinic_mgr_id )
			),
			'exactly the pre-existing Doctor membership remains — no new staff row'
		);
		self::assertFalse(
			(bool) get_user_by( 'email', 'day2-late-refused@example.com' ),
			'a refused enrollment never provisions an account'
		);

		// Ongoing care is preserved: the existing membership stays ACTIVE and readable,
		// and the Clinic Manager keeps its own authority (suspension is not a lockout).
		self::assertSame(
			'active',
			(string) $db->fetchValue(
				'SELECT status FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d AND wp_user_id = %d',
				array( $this->clinic_id, $doctor_id )
			)
		);

		// Reactivation reopens new demand.
		$reactivate = ManagementPortalController::dispatch(
			$this->org_mgr_id,
			'org_clinic_status',
			array(
				'organization_id' => (string) $this->org_id,
				'clinic_id'       => (string) $this->clinic_id,
				'active'          => '1',
			)
		);
		self::assertTrue( $reactivate['ok'] );
		$after = ManagementPortalController::dispatch(
			$this->clinic_mgr_id,
			'clinic_staff_enroll',
			array(
				'clinic_id'  => (string) $this->clinic_id,
				'identifier' => 'day2-after-' . bin2hex( random_bytes( 3 ) ) . '@example.com',
				'role_key'   => 'accountant',
			)
		);
		self::assertTrue( $after['ok'], 'an active Clinic enrolls an Accountant again — ' . ( $after['message'] ?? '' ) );
	}

	public function testActorWithoutAuthorityInAnotherClinicIsDeniedAndWritesNothing(): void {
		$outsider = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$denied = ManagementPortalController::dispatch(
			$outsider,
			'clinic_clinician_register',
			array(
				'clinic_id'   => (string) $this->clinic_id,
				'full_name'   => 'دکتر بیرون از دامنه',
				'specialty'   => 'متخصص چشم',
			)
		);
		self::assertFalse( $denied['ok'] );
		self::assertSame(
			0,
			(int) App::db()->fetchValue(
				'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE full_name = %s',
				array( 'دکتر بیرون از دامنه' )
			)
		);

		// The Clinic Manager of Clinic A has no authority in Clinic B of the same
		// Organization (Organization membership never implies Clinic authority).
		$cross = ManagementPortalController::dispatch(
			$this->clinic_mgr_id,
			'clinic_staff_enroll',
			array(
				'clinic_id'  => (string) $this->other_clinic_id,
				'identifier' => 'day2-cross-' . bin2hex( random_bytes( 3 ) ) . '@example.com',
				'role_key'   => 'secretary',
			)
		);
		self::assertFalse( $cross['ok'], 'cross-Clinic enrollment must be denied' );
		self::assertSame(
			0,
			(int) App::db()->fetchValue(
				'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d',
				array( $this->other_clinic_id )
			)
		);

		wp_delete_user( $outsider );
	}
}
