<?php
/**
 * Frontend hospital onboarding completed by TWO separate Subscriber identities.
 *
 * Contract under test (real migrations, real MySQL, no mocks, no wp-admin):
 *  1. an ordinary WordPress Subscriber holding Organization authority creates the
 *     outpatient hospital, its physical sites and TWO held Clinics;
 *  2. the same Organization Manager designates a DIFFERENT Subscriber Clinic
 *     Manager for each Clinic — WordPress roles are never changed;
 *  3. each Clinic Manager completes their OWN Clinic (Locations/rooms,
 *     clinicians, weekly schedules, booking policy) through the frontend portal
 *     actions, holding NO Organization authority;
 *  4. the Organization Manager reviews the real completed setup and activates
 *     both Clinics atomically;
 *  5. fail-closed scoped authorization is preserved: Organization authority
 *     grants no implicit Clinic rights, a Clinic Manager cannot act on the other
 *     Clinic or on Organization steps, and a WordPress Administrator gains
 *     nothing.
 *
 * Every write below goes through ManagementPortalController::dispatch() — the
 * exact controller path the rendered frontend forms post to.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Management\ManagementPortalService;
use ClinicCore\Application\Onboarding\FacilitySetupException;
use ClinicCore\Application\Onboarding\FacilitySetupService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\ManagementPortalController;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

final class HospitalTwoManagerJourneyTest extends WP_UnitTestCase {

    use RealTableMigrations;

    private const TZ = 'Asia/Tehran';

    private int $org = 0;
    private int $orgManager = 0;
    private int $managerA = 0;
    private int $managerB = 0;
    private int $admin = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->withRealTables( static fn(): array => App::migrations()->migrate() );
        App::resetScope();

        $this->org        = $this->create_org( 'Hospital Two Manager Org' );
        $this->orgManager = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->managerA   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->managerB   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->admin      = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->grant_org_manager( $this->org, $this->orgManager );
    }

    protected function tearDown(): void {
        App::resetScope();
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    public function testTwoSubscriberManagersCompleteTheHospitalAndTheOrganizationManagerActivatesItAtomically(): void {
        $service = App::management_portal_service();

        // ------------------------------------------------------------------
        // 1. Organization Manager: identity, acknowledgment, sites, two Clinics
        // ------------------------------------------------------------------
        self::assertTrue( $this->act( $this->orgManager, 'org_facility_start', [
            'organization_id' => (string) $this->org,
            'facility_type'   => 'hospital',
            'structure'       => '',
            'display_name'    => 'بیمارستان دو‌مدیره',
        ] ), 'the Organization Manager starts the hospital plan' );

        self::assertTrue( $this->act( $this->orgManager, 'org_facility_ack', [
            'organization_id' => (string) $this->org,
        ] ), 'the outpatient-only acknowledgment is recorded' );

        foreach ( [ 'ساختمان شرقی' => 'تهران، خیابان اول', 'ساختمان غربی' => 'تهران، خیابان دوم' ] as $label => $address ) {
            self::assertTrue( $this->act( $this->orgManager, 'org_facility_site', [
                'organization_id' => (string) $this->org,
                'label'           => $label,
                'address'         => $address,
            ] ), 'site "' . $label . '" is created through the portal' );
        }

        // Site keys come from the same server-side plan the rendered <select> uses.
        $plan  = $service->facility_plan( $this->orgManager, $this->org );
        self::assertIsArray( $plan );
        self::assertCount( 2, $plan['sites'] );
        $siteKeys = array_values( array_map( static fn( array $row ): string => (string) $row['key'], $plan['sites'] ) );

        foreach ( [ [ 'درمانگاه قلب', $siteKeys[0] ], [ 'درمانگاه پوست', $siteKeys[1] ] ] as $index => $clinic ) {
            self::assertTrue( $this->act( $this->orgManager, 'org_facility_clinic', [
                'organization_id' => (string) $this->org,
                'name'            => $clinic[0],
                'site_key'        => $clinic[1],
                'timezone'        => self::TZ,
            ] ), 'held Clinic ' . ( $index + 1 ) . ' is created' );
        }

        $plan = $service->facility_plan( $this->orgManager, $this->org );
        self::assertIsArray( $plan );
        self::assertCount( 2, $plan['clinics'] );
        $clinicKeyA = (string) $plan['clinics'][0]['key'];
        $clinicKeyB = (string) $plan['clinics'][1]['key'];
        $clinicA    = (int) $plan['clinics'][0]['clinic_id'];
        $clinicB    = (int) $plan['clinics'][1]['clinic_id'];
        self::assertGreaterThan( 0, $clinicA );
        self::assertGreaterThan( 0, $clinicB );
        self::assertSame( 'suspended', $this->clinic_status( $clinicA ), 'a held Clinic accepts no new demand before activation' );
        self::assertSame( 'suspended', $this->clinic_status( $clinicB ) );

        // ------------------------------------------------------------------
        // 2. A DIFFERENT Subscriber Clinic Manager per Clinic
        // ------------------------------------------------------------------
        // Manager A is designated by LOGIN (server-side resolution, no hand-typed id).
        self::assertTrue( $this->act( $this->orgManager, 'org_facility_designate', [
            'organization_id' => (string) $this->org,
            'clinic_key'      => $clinicKeyA,
            'identifier'      => (string) get_userdata( $this->managerA )->user_login,
        ] ), 'manager A is designated for Clinic A by login' );
        // A non-existent identifier is refused and designates nobody.
        self::assertFalse( $this->act( $this->orgManager, 'org_facility_designate', [
            'organization_id' => (string) $this->org,
            'clinic_key'      => $clinicKeyA,
            'identifier'      => 'no-such-manager-' . bin2hex( random_bytes( 3 ) ),
        ] ), 'an unknown identifier designates nobody' );
        self::assertTrue( $this->act( $this->orgManager, 'org_facility_designate', [
            'organization_id' => (string) $this->org,
            'clinic_key'      => $clinicKeyB,
            'target_user_id'  => (string) $this->managerB,
        ] ), 'manager B is designated for Clinic B' );

        foreach ( [ $this->managerA, $this->managerB ] as $manager ) {
            self::assertSame( [ 'subscriber' ], array_values( (array) get_userdata( $manager )->roles ), 'designation never changes a WordPress role' );
            self::assertFalse( user_can( $manager, RolesAndCapabilities::PATIENT_READ ), 'no global capability is granted to a Clinic Manager' );
        }
        $selectors = $service->facility_selectors( $this->orgManager, $this->org );
        self::assertCount( 2, $selectors );
        foreach ( $selectors as $selector ) {
            self::assertFalse( (bool) $selector['can_configure'], 'the Organization Manager may configure no Clinic step' );
        }
        self::assertSame( 0, count( $service->organizations( $this->managerA ) ), 'a Clinic Manager holds no Organization authority' );
        self::assertNull( $service->organization_overview( $this->managerA, $this->org ), 'Organization surface stays closed to a Clinic Manager' );

        // ------------------------------------------------------------------
        // 3. Each Clinic Manager completes their OWN Clinic
        // ------------------------------------------------------------------
        foreach ( [ [ $this->managerA, $clinicA, 'دکتر الف' ], [ $this->managerB, $clinicB, 'دکتر ب' ] ] as $pair ) {
            [ $manager, $clinicId, $doctorName ] = $pair;

            self::assertTrue( $this->act( $manager, 'clinic_location_create', [
                'clinic_id' => (string) $clinicId,
                'name'      => 'اتاق ویزیت ۱',
                'slug'      => 'room-1-' . $clinicId,
                'timezone'  => self::TZ,
            ] ), 'the Clinic Manager creates a Location/room in their own Clinic' );

            self::assertTrue( $this->act( $manager, 'clinic_clinician_register', [
                'clinic_id'  => (string) $clinicId,
                'full_name'  => $doctorName,
                'specialty'  => 'عمومی',
                'target_user_id' => '0',
            ] ), 'the Clinic Manager registers a clinician in their own Clinic' );

            $locationId  = (int) App::db()->fetchValue(
                'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d ORDER BY id ASC LIMIT 1',
                [ $clinicId ]
            );
            $clinicianId = (int) App::db()->fetchValue(
                'SELECT id FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE clinic_id = %d AND full_name = %s LIMIT 1',
                [ $clinicId, $doctorName ]
            );
            self::assertGreaterThan( 0, $locationId );
            self::assertGreaterThan( 0, $clinicianId );

            self::assertTrue( $this->act( $manager, 'clinic_schedule_create', [
                'clinic_id'    => (string) $clinicId,
                'clinician_id' => (string) $clinicianId,
                'location_id'  => (string) $locationId,
                'day_of_week'  => '1',
                'start_time'   => '08:00',
                'end_time'     => '12:00',
            ] ), 'the Clinic Manager adds a weekly schedule in their own Clinic' );

            self::assertTrue( $this->act( $manager, 'clinic_facility_booking', [
                'clinic_id' => (string) $clinicId,
                'duration'  => '15',
                'capacity'  => '1',
                'lead'      => '2',
                'future'    => '30',
                'cancel'    => '12',
            ] ), 'the Clinic Manager saves the booking policy of their own Clinic' );

            // Readiness is the Clinic Manager's OWN view and is computed from rows.
            $readiness = $service->clinic_facility_readiness( $manager, $clinicId );
            self::assertIsArray( $readiness );
            self::assertSame( $clinicId, (int) $readiness['clinic_id'] );
            self::assertTrue( $readiness['hospital'], 'the plan flow is visible without Organization authority' );
            self::assertTrue( $readiness['ready'], implode( ' | ', (array) $readiness['blocking'] ) );
        }

        // ------------------------------------------------------------------
        // 4. Fail-closed scoped authorization is preserved
        // ------------------------------------------------------------------
        // (a) A Clinic Manager cannot act on the OTHER Clinic.
        self::assertFalse( $this->act( $this->managerA, 'clinic_facility_booking', [
            'clinic_id' => (string) $clinicB,
            'duration'  => '30',
            'capacity'  => '5',
            'lead'      => '1',
            'future'    => '10',
            'cancel'    => '1',
        ] ), 'manager A cannot write Clinic B booking policy' );
        self::assertSame( 15, (int) ( new \ClinicCore\Settings\Settings( App::db(), $clinicB, App::audit() ) )->get( 'booking.duration_default_min' ),
            'Clinic B keeps its own policy (15), untouched by manager A' );

        self::assertFalse( $this->act( $this->managerB, 'clinic_location_create', [
            'clinic_id' => (string) $clinicA,
            'name'      => 'اتاق نفوذی',
            'slug'      => 'intruder-room',
            'timezone'  => self::TZ,
        ] ), 'manager B cannot create a Location in Clinic A' );

        self::assertFalse( $this->act( $this->managerA, 'org_facility_activate', [
            'organization_id' => (string) $this->org,
        ] ), 'a Clinic Manager holds no Organization lifecycle authority' );
        self::assertSame( 'suspended', $this->clinic_status( $clinicA ), 'no activation happened' );

        // (b) Organization authority buys NO implicit Clinic right: the Organization
        //     Manager is not a member of either Clinic, so the wizard's Clinic-level
        //     steps refuse them.
        $locationsBefore = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d',
            [ $clinicA ]
        );
        self::assertFalse( $this->act( $this->orgManager, 'org_facility_room', [
            'organization_id' => (string) $this->org,
            'clinic_key'      => $clinicKeyA,
            'name'            => 'اتاق سازمانی',
        ] ), 'the Organization Manager cannot add a room without a Clinic membership' );
        self::assertSame( $locationsBefore, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d',
            [ $clinicA ]
        ), 'no Location was created by the Organization Manager' );

        // (c) A WordPress Administrator with no CPMS membership gets nothing.
        self::assertFalse( $this->act( $this->admin, 'org_facility_activate', [
            'organization_id' => (string) $this->org,
        ] ) );
        self::assertFalse( $this->act( $this->admin, 'clinic_facility_booking', [
            'clinic_id' => (string) $clinicA,
            'duration'  => '15',
            'capacity'  => '1',
            'lead'      => '2',
            'future'    => '30',
            'cancel'    => '12',
        ] ) );

        // (d) FacilitySetupService itself refuses a suspended/none member even when
        //     the plan key is known (defense in depth behind the portal).
        $outsider = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $orgId    = $this->org;
        $this->expectFacilityRefusal( static function () use ( $orgId, $clinicKeyA, $outsider ): void {
            App::facility_setup_service()->save_booking_policy(
                $outsider,
                $orgId,
                $clinicKeyA,
                [ 'duration' => '15', 'capacity' => '1', 'lead' => '2', 'future' => '30', 'cancel' => '12' ]
            );
        } );

        // ------------------------------------------------------------------
        // 5. Organization Manager reviews the REAL state and activates atomically
        // ------------------------------------------------------------------
        $review = $service->review_facility_setup( $this->orgManager, $this->org );
        self::assertIsArray( $review );
        self::assertTrue( $review['ready'], implode( ' | ', (array) $review['blocking'] ) );
        self::assertCount( 2, $review['clinics'] );

        self::assertTrue( $this->act( $this->orgManager, 'org_facility_activate', [
            'organization_id' => (string) $this->org,
        ] ), 'the Organization Manager activates the plan' );

        self::assertSame( 'active', $this->clinic_status( $clinicA ) );
        self::assertSame( 'active', $this->clinic_status( $clinicB ) );
        $plan = $service->facility_plan( $this->orgManager, $this->org );
        self::assertIsArray( $plan );
        self::assertNotSame( '', (string) $plan['activated_at'] );

        // Both Clinics really became bookable surfaces: a slot generator sees an
        // active weekly schedule row in each Clinic.
        foreach ( [ $clinicA, $clinicB ] as $clinicId ) {
            self::assertSame( 1, (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_schedule' ) . ' WHERE clinic_id = %d AND is_active = 1',
                [ $clinicId ]
            ), 'Clinic ' . $clinicId . ' has an active weekly schedule' );
        }

        self::assertTrue( App::audit()->verifyChain( 5000 )['ok'], 'the audit chain stays valid across both identities' );
    }

    public function testAtomicActivationRollsBackEveryClinicWhenOneClinicIsNotReady(): void {
        $service = App::management_portal_service();
        $this->seedHospitalPlan( $service );

        $plan = $service->facility_plan( $this->orgManager, $this->org );
        self::assertIsArray( $plan );
        $clinicA = (int) $plan['clinics'][0]['clinic_id'];
        $clinicB = (int) $plan['clinics'][1]['clinic_id'];

        // Complete only Clinic A.
        $this->complete_clinic( $this->managerA, $clinicA );

        $review = $service->review_facility_setup( $this->orgManager, $this->org );
        self::assertIsArray( $review );
        self::assertFalse( $review['ready'], 'the missing Clinic B blocks the whole activation' );

        self::assertFalse( $this->act( $this->orgManager, 'org_facility_activate', [
            'organization_id' => (string) $this->org,
        ] ), 'activation is refused while any Clinic is incomplete' );

        self::assertSame( 'suspended', $this->clinic_status( $clinicA ), 'Clinic A is NOT left active by a failed activation' );
        self::assertSame( 'suspended', $this->clinic_status( $clinicB ) );
    }

    // ----------------------------------------------------------------------
    // helpers
    // ----------------------------------------------------------------------

    /**
     * Run one frontend portal action exactly as the rendered form would.
     *
     * @param array<string,string> $input
     */
    private function act( int $actor, string $action, array $input ): bool {
        wp_set_current_user( $actor );
        $result = ManagementPortalController::dispatch( $actor, $action, $input );

        return is_array( $result ) && true === ( $result['ok'] ?? false );
    }

    private function seedHospitalPlan( ManagementPortalService $service ): void {
        self::assertTrue( $this->act( $this->orgManager, 'org_facility_start', [
            'organization_id' => (string) $this->org,
            'facility_type'   => 'hospital',
            'structure'       => '',
            'display_name'    => 'بیمارستان اتمی',
        ] ) );
        self::assertTrue( $this->act( $this->orgManager, 'org_facility_ack', [
            'organization_id' => (string) $this->org,
        ] ) );
        self::assertTrue( $this->act( $this->orgManager, 'org_facility_site', [
            'organization_id' => (string) $this->org,
            'label'           => 'ساختمان اصلی',
            'address'         => 'تهران، خیابان اصلی',
        ] ) );
        $plan     = $service->facility_plan( $this->orgManager, $this->org );
        self::assertIsArray( $plan );
        $siteKey  = (string) $plan['sites'][0]['key'];
        foreach ( [ 'درمانگاه یک', 'درمانگاه دو' ] as $name ) {
            self::assertTrue( $this->act( $this->orgManager, 'org_facility_clinic', [
                'organization_id' => (string) $this->org,
                'name'            => $name,
                'site_key'        => $siteKey,
                'timezone'        => self::TZ,
            ] ) );
        }
        $plan = $service->facility_plan( $this->orgManager, $this->org );
        self::assertIsArray( $plan );
        self::assertTrue( $this->act( $this->orgManager, 'org_facility_designate', [
            'organization_id' => (string) $this->org,
            'clinic_key'      => (string) $plan['clinics'][0]['key'],
            'target_user_id'  => (string) $this->managerA,
        ] ) );
        self::assertTrue( $this->act( $this->orgManager, 'org_facility_designate', [
            'organization_id' => (string) $this->org,
            'clinic_key'      => (string) $plan['clinics'][1]['key'],
            'target_user_id'  => (string) $this->managerB,
        ] ) );
    }

    private function complete_clinic( int $manager, int $clinicId ): void {
        self::assertTrue( $this->act( $manager, 'clinic_location_create', [
            'clinic_id' => (string) $clinicId,
            'name'      => 'اتاق ۱',
            'slug'      => 'atomic-room-' . $clinicId,
            'timezone'  => self::TZ,
        ] ) );
        self::assertTrue( $this->act( $manager, 'clinic_clinician_register', [
            'clinic_id'      => (string) $clinicId,
            'full_name'      => 'دکتر کامل',
            'specialty'      => '',
            'target_user_id' => '0',
        ] ) );
        $locationId  = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d ORDER BY id ASC LIMIT 1',
            [ $clinicId ]
        );
        $clinicianId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE clinic_id = %d ORDER BY id DESC LIMIT 1',
            [ $clinicId ]
        );
        self::assertTrue( $this->act( $manager, 'clinic_schedule_create', [
            'clinic_id'    => (string) $clinicId,
            'clinician_id' => (string) $clinicianId,
            'location_id'  => (string) $locationId,
            'day_of_week'  => '2',
            'start_time'   => '09:00',
            'end_time'     => '11:00',
        ] ) );
        self::assertTrue( $this->act( $manager, 'clinic_facility_booking', [
            'clinic_id' => (string) $clinicId,
            'duration'  => '20',
            'capacity'  => '1',
            'lead'      => '2',
            'future'    => '30',
            'cancel'    => '12',
        ] ) );
    }

    /**
     * @param callable():void $call
     */
    private function expectFacilityRefusal( callable $call ): void {
        $refused = false;
        try {
            $call();
        } catch ( FacilitySetupException $e ) {
            $refused = true;
        }
        self::assertTrue( $refused, 'FacilitySetupService refuses the step without Clinic authority' );
    }

    private function clinic_status( int $clinicId ): string {
        return (string) App::db()->fetchValue(
            'SELECT COALESCE(status, %s) FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ 'active', $clinicId ]
        );
    }

    private function create_org( string $name ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert( 'cpms_organizations', [
            'name'       => $name . ' ' . bin2hex( random_bytes( 3 ) ),
            'slug'       => 'hosp-two-' . bin2hex( random_bytes( 4 ) ),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ] );

        return (int) $db->wpdb_last_insert_id();
    }

    private function grant_org_manager( int $orgId, int $userId ): void {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert( 'cpms_organization_memberships', [
            'organization_id'       => $orgId,
            'wp_user_id'            => $userId,
            'role_key'              => 'organization_manager',
            'status'                => 'active',
            'created_by_wp_user_id' => $userId,
            'created_at'            => $now,
            'updated_at'            => $now,
        ] );
    }
}
