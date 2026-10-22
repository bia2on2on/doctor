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

use ClinicCore\Auth\RolesAndCapabilities;
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
				'role_key'   => RolesAndCapabilities::ROLE_DOCTOR,
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
				'address_city_custom' => 'شهرک نوین',
			)
		);
		self::assertTrue( $result['ok'], (string) ( $result['message'] ?? '' ) );

		$stored = $this->stored_clinic_address( $this->clinic_id );
		self::assertSame( 'تهران، سایر: شهرک نوین، خیابان آزادی، پلاک ۱۲', $stored );

		$parsed = IranProvinceCityCatalog::parse_address( $stored );
		self::assertSame( 'تهران', $parsed['province'] );
		self::assertSame( '', $parsed['city'], 'a user-defined city is never reported as canonical' );
		self::assertSame( 'شهرک نوین', $parsed['city_custom'] );
		self::assertSame( 'خیابان آزادی، پلاک ۱۲', $parsed['detail'] );

		// The real portal document redisplay: "Other" selected + the typed city in the
		// manual field, and the sentinel itself is never rendered as a city name.
		$html = $this->render_portal( $this->clinic_id );
		self::assertStringContainsString( 'data-cpms-city-custom-input="1"', $html );
		self::assertStringContainsString( 'name="address_city_custom"', $html );
		self::assertStringContainsString( 'value="شهرک نوین"', $html );
		self::assertStringContainsString( 'data-cpms-city-other="1" selected', $html );
		self::assertStringContainsString( '/assets/js/cpms-catalog-fields.js', $html );
		self::assertStringNotContainsString( '>شهرک نوین</option>', $html, 'a manual city must not be injected into the canonical catalogue list' );
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
				'address_city_custom' => 'شهرک نوین',
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
				// One submitted field, exactly like the rendered form: the catalogue
				// <select> is a nameless picker, so a manual label is posted as-is.
				'specialty' => 'فلوشیپ ناباروری',
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
		// The wire contract every acceptance harness depends on (facility matrix,
		// delegated journey and hospital journey all fill input[name="specialty"]).
		self::assertStringContainsString( 'name="specialty"', $html );
		self::assertStringNotContainsString( 'name="specialty_custom"', $html );
		self::assertStringContainsString( '/assets/js/cpms-catalog-fields.js', $html );
	}

	public function testUnusableSpecialtyIsRejectedBeforeAnyWrite(): void {
		$result = ManagementPortalController::dispatch(
			$this->clinic_mgr_id,
			'clinic_clinician_register',
			array(
				'clinic_id'        => (string) $this->clinic_id,
				'full_name'        => 'دکتر نامعتبر',
				'specialty' => str_repeat( 'ت', 160 ),
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
	/**
	 * Lifecycle pin for Clinic suspension.
	 *
	 * `org_create_clinic` provisions an ACTIVE Clinic: OrganizationClinicProvisioningService
	 * sets `status='suspended'` only under `if ( $held )`, i.e. on the facility-plan path
	 * (`provision_held`). That held-before-activation lifecycle — where the designated Clinic
	 * Manager staffs the Clinic and the Organization Manager then activates it atomically — is
	 * pinned by the facility-matrix lane (bin/rwp-facility-matrix-journey.py:595-596, "every
	 * plan Clinic is created HELD (suspended) before activation"), not by this fixture.
	 *
	 * Asserted here: suspension never revokes, rewrites or hides an existing legitimate
	 * membership, is not a lockout for ongoing care, and reactivation restores new demand.
	 * Whether a *penalty* suspension should also block NEW enrollment is an open product
	 * decision — cpms_clinics has no held column, so "held pending activation" and "suspended
	 * after activation" are indistinguishable — and is deliberately not encoded either way.
	 */
	public function testSuspensionNeverRevokesAnExistingMembershipAndReactivationRestoresNewDemand(): void {
		$db = App::db();

		self::assertSame(
			'active',
			(string) $db->fetchValue( 'SELECT status FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d', array( $this->clinic_id ) ),
			'fixture: org_create_clinic provisions an ACTIVE Clinic (only provision_held inserts suspended)'
		);

		$doctor_id = $this->enroll_doctor( 'day2-keep-' . bin2hex( random_bytes( 3 ) ) . '@example.com' );
		self::assertSame(
			'active',
			(string) $db->fetchValue(
				'SELECT status FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d AND wp_user_id = %d',
				array( $this->clinic_id, $doctor_id )
			),
			'the designated Clinic Manager enrolls a Doctor with the real cpms_doctor role key'
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
		self::assertTrue( $suspend['ok'], 'fixture: the Organization Manager suspends the Clinic' );
		self::assertSame(
			'suspended',
			(string) $db->fetchValue( 'SELECT status FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d', array( $this->clinic_id ) )
		);

		// This Clinic was operational before the suspension (set_clinic_status wrote the
		// CLINIC_DEACTIVATED lifecycle row in the same transaction), so the NEW-demand
		// boundary applies on the portal surface too - with an operator-facing reason,
		// never a generic failure.
		$refused = ManagementPortalController::dispatch(
			$this->clinic_mgr_id,
			'clinic_staff_enroll',
			array(
				'clinic_id'  => (string) $this->clinic_id,
				'identifier' => 'day2-late-' . bin2hex( random_bytes( 3 ) ) . '@example.com',
				'role_key'   => RolesAndCapabilities::ROLE_SECRETARY,
			)
		);
		self::assertFalse( $refused['ok'], 'a previously active, now suspended Clinic refuses NEW staff enrollment' );
		self::assertStringContainsString( 'معلق', (string) ( $refused['message'] ?? '' ), 'the refusal carries the lifecycle reason' );
		self::assertSame(
			2,
			(int) $db->fetchValue(
				'SELECT COUNT(*) FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d',
				array( $this->clinic_id )
			),
			'the refused enrollment wrote no membership row'
		);

		// Existing legitimate memberships survive suspension untouched: nothing is
		// rewritten, revoked or added, and ongoing care stays manageable.
		self::assertSame(
			'active',
			(string) $db->fetchValue(
				'SELECT status FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d AND wp_user_id = %d',
				array( $this->clinic_id, $doctor_id )
			),
			'suspension never rewrites an existing membership'
		);
		self::assertSame(
			2,
			(int) $db->fetchValue(
				'SELECT COUNT(*) FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d',
				array( $this->clinic_id )
			),
			'exactly the Clinic Manager and the Doctor participate — nothing added, nothing removed'
		);
		$membership_id = (int) $db->fetchValue(
			'SELECT id FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d AND wp_user_id = %d',
			array( $this->clinic_id, $doctor_id )
		);
		self::assertGreaterThan( 0, $membership_id );
		// A suspended Clinic is not a lockout: the Clinic Manager keeps lifecycle
		// authority over the existing membership, so ongoing care stays manageable.
		$pause = ManagementPortalController::dispatch(
			$this->clinic_mgr_id,
			'clinic_staff_status',
			array(
				'clinic_id'     => (string) $this->clinic_id,
				'membership_id' => (string) $membership_id,
				'active'        => '0',
			)
		);
		self::assertTrue( $pause['ok'], 'the manager can still suspend a membership — ' . (string) ( $pause['message'] ?? '' ) );
		$keep_care = ManagementPortalController::dispatch(
			$this->clinic_mgr_id,
			'clinic_staff_status',
			array(
				'clinic_id'     => (string) $this->clinic_id,
				'membership_id' => (string) $membership_id,
				'active'        => '1',
			)
		);
		self::assertTrue( $keep_care['ok'], 'and reactivate it — ' . (string) ( $keep_care['message'] ?? '' ) );
		self::assertSame(
			'active',
			(string) $db->fetchValue(
				'SELECT status FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE id = %d',
				array( $membership_id )
			),
			'the existing legitimate membership is usable again, unchanged in identity'
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
				'role_key'   => RolesAndCapabilities::ROLE_ACCOUNTANT,
			)
		);
		self::assertTrue( $after['ok'], 'an active Clinic enrolls an Accountant again — ' . (string) ( $after['message'] ?? '' ) );
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
				'role_key'   => RolesAndCapabilities::ROLE_SECRETARY,
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
