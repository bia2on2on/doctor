<?php

/**
 * Package P3 — defect 6, fail-closed coverage for the Location a booking may use.
 *
 * The public surface may fall back to the pre-change flow for a Clinic that has
 * no eligible Location at all. That fallback must never let a client choose the
 * Location of a booking: the SERVER keeps the final word. This suite pins the
 * two guards that enforce it, for both halves of the public booking path:
 *
 *  - BookingService::assert_requested_location_matches_slot() — a requested
 *    location_id must be the Slot's OWN Location, otherwise 404 CLINIC_NOT_FOUND.
 *  - BookingService::assert_active_location_for_booking() — the Slot's Location
 *    must be a real, ACTIVE Location of the Slot's Clinic, otherwise 404.
 *
 * Covered cases (quote AND hold): (a) a Location that is not the Slot's,
 * (b) an inactive Location (also when the Slot itself sits there), (c) another
 * Clinic's Location. Every rejection must happen BEFORE any hold row or
 * capacity change exists, so a rejected request cannot consume a slot.
 *
 * Positive control: the Slot's own active Location is accepted, so the suite
 * cannot pass by rejecting everything.
 *
 * Read-only with respect to production flows: the suite seeds its own fixture,
 * calls the service, and then only READS counts. No wall-clock value decides an
 * assertion; `slot_date` is derived from the fixture clock offset only.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

final class PkgP3QuoteLocationMismatchTest extends WP_UnitTestCase
{
    private const TZ_TEHRAN = 'Asia/Tehran';

    private string $uid = '';

    private int $org = 0;

    private int $clinic_a = 0;

    private int $clinic_b = 0;

    private int $location_a1 = 0;

    private int $location_a2 = 0;

    private int $location_a3_inactive = 0;

    private int $location_b1 = 0;

    private int $clinician_a = 0;

    private int $patient_user = 0;

    /** @var array{slot_id:int,date:string,time:string} */
    private array $slot_a1 = [];

    /** @var array{slot_id:int,date:string,time:string} */
    private array $slot_a3 = [];

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user( 0 );

        $this->uid = uniqid( 'p3qm', false );
        $this->build_fixture();
    }

    protected function tearDown(): void
    {
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    // =====================================================================
    // quote — the requested Location is never trusted
    // =====================================================================

    public function test_quote_rejects_a_location_that_is_not_the_slots_location(): void
    {
        $this->assert_quote_is_rejected( $this->location_a2 );
    }

    public function test_quote_rejects_an_inactive_location_of_the_same_clinic(): void
    {
        // The Slot itself sits on the inactive Location, so only the ACTIVE
        // Location guard can reject this request.
        $this->assert_quote_is_rejected( $this->location_a3_inactive, $this->slot_a3 );
    }

    public function test_quote_rejects_another_clinics_location(): void
    {
        $this->assert_quote_is_rejected( $this->location_b1 );
    }

    public function test_quote_accepts_the_slots_own_active_location(): void
    {
        $quote = App::bookingService()->quote(
            $this->clinician_a,
            $this->slot_a1['date'],
            $this->slot_a1['time'],
            $this->slot_a1['slot_id'],
            $this->location_a1
        );

        self::assertSame(
            $this->location_a1,
            (int) ( $quote['location_id'] ?? 0 ),
            'The Slot\'s own ACTIVE Location is accepted (positive control).'
        );
        self::assertSame(
            $this->slot_a1['slot_id'],
            (int) ( $quote['slot_id'] ?? 0 ),
            'The quote answers for the requested Slot identity.'
        );
    }

    // =====================================================================
    // hold — same guards, and nothing may be written before them
    // =====================================================================

    public function test_hold_rejects_a_mismatched_location_before_writing_anything(): void
    {
        $this->assert_hold_is_rejected( $this->location_a2 );
    }

    public function test_hold_rejects_an_inactive_location_before_writing_anything(): void
    {
        $this->assert_hold_is_rejected( $this->location_a3_inactive, $this->slot_a3 );
    }

    public function test_hold_rejects_another_clinics_location_before_writing_anything(): void
    {
        $this->assert_hold_is_rejected( $this->location_b1 );
    }

    // =====================================================================
    // assertions
    // =====================================================================

    /** @param array{slot_id:int,date:string,time:string}|null $slot */
    private function assert_quote_is_rejected( int $location_id, ?array $slot = null ): void
    {
        $slot = $slot ?? $this->slot_a1;

        try {
            App::bookingService()->quote(
                $this->clinician_a,
                $slot['date'],
                $slot['time'],
                $slot['slot_id'],
                $location_id
            );
            self::fail( 'A Location that is not the Slot\'s own ACTIVE Location must be rejected.' );
        } catch ( BookingException $e ) {
            self::assertSame( 'CLINIC_NOT_FOUND', $e->errorCode, 'Non-enumerating 404 envelope.' );
            self::assertSame( 404, $e->httpStatus, 'The rejection is an HTTP 404.' );
        }

        self::assertSame( 0, $this->hold_rows( $slot['slot_id'] ), 'A rejected quote writes no hold row.' );
        self::assertSame( 0, $this->held_count( $slot['slot_id'] ), 'A rejected quote holds no capacity.' );
    }

    /** @param array{slot_id:int,date:string,time:string}|null $slot */
    private function assert_hold_is_rejected( int $location_id, ?array $slot = null ): void
    {
        $slot = $slot ?? $this->slot_a1;

        try {
            App::bookingService()->hold(
                $this->patient_user,
                $this->clinician_a,
                $slot['date'],
                $slot['time'],
                $slot['slot_id'],
                null,
                $location_id
            );
            self::fail( 'A hold must never be accepted for a Location the Slot does not own.' );
        } catch ( BookingException $e ) {
            self::assertSame( 'CLINIC_NOT_FOUND', $e->errorCode, 'Non-enumerating 404 envelope.' );
            self::assertSame( 404, $e->httpStatus, 'The rejection is an HTTP 404.' );
        }

        self::assertSame(
            0,
            $this->hold_rows( $slot['slot_id'] ),
            'The rejection happens BEFORE any hold row is written.'
        );
        self::assertSame( 0, $this->held_count( $slot['slot_id'] ), 'No held capacity was consumed.' );
        self::assertSame( 0, $this->booked_count( $slot['slot_id'] ), 'No booked capacity was consumed.' );
    }

    private function hold_rows( int $slot_id ): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_slot_holds WHERE slot_id = %d',
                $slot_id
            )
        );
    }

    private function held_count( int $slot_id ): int
    {
        return $this->slot_counter( 'held_count', $slot_id );
    }

    private function booked_count( int $slot_id ): int
    {
        return $this->slot_counter( 'booked_count', $slot_id );
    }

    private function slot_counter( string $column, int $slot_id ): int
    {
        global $wpdb;

        $column = $column === 'booked_count' ? 'booked_count' : 'held_count';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT ' . $column . ' FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d',
                $slot_id
            )
        );
    }

    // =====================================================================
    // fixture
    // =====================================================================

    private function build_fixture(): void
    {
        $now = gmdate( 'Y-m-d H:i:s' );

        $this->org = $this->insert_row(
            'cpms_organizations',
            [
                'name'       => 'Org P3QM ' . $this->uid,
                'slug'       => 'org-p3qm-' . $this->uid,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        $this->clinic_a = $this->insert_clinic( 'Clinic A P3QM ' . $this->uid, 'clinic-a-p3qm-' . $this->uid, $now );
        $this->clinic_b = $this->insert_clinic( 'Clinic B P3QM ' . $this->uid, 'clinic-b-p3qm-' . $this->uid, $now );

        $this->location_a1 = $this->insert_location( $this->clinic_a, 'Main A1 P3QM', 1, $now );
        $this->location_a2 = $this->insert_location( $this->clinic_a, 'Branch A2 P3QM', 1, $now );
        $this->location_a3_inactive = $this->insert_location( $this->clinic_a, 'Closed A3 P3QM', 0, $now );
        $this->location_b1 = $this->insert_location( $this->clinic_b, 'Main B P3QM', 1, $now );

        $mobile = '0912' . str_pad( (string) random_int( 1, 9999999 ), 7, '0', STR_PAD_LEFT );

        // The OTP user convention lets the service resolve a mobile without a
        // patient-identity fixture; no credential is stored in the fixture.
        $this->patient_user = (int) wp_insert_user(
            [
                'user_login' => 'p3qm-' . $this->uid,
                'user_email' => $mobile . '@otp.cpms.local',
                'user_pass'  => wp_generate_password( 20, true ),
                'role'       => 'subscriber',
            ]
        );
        self::assertGreaterThan( 0, $this->patient_user, 'Fixture patient user must be created.' );

        $clinician_user = (int) wp_insert_user(
            [
                'user_login' => 'p3qm-doc-' . $this->uid,
                'user_email' => 'p3qm-doc-' . $this->uid . '@p3qm.cpms.test',
                'user_pass'  => wp_generate_password( 20, true ),
                'role'       => RolesAndCapabilities::ROLE_DOCTOR,
            ]
        );
        self::assertGreaterThan( 0, $clinician_user, 'Fixture clinician user must be created.' );

        $this->clinician_a = $this->insert_row(
            'cpms_clinicians',
            [
                'clinic_id'  => $this->clinic_a,
                'wp_user_id' => $clinician_user,
                'full_name'  => 'Dr P3QM ' . $this->uid,
                'specialty'  => 'قلب',
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
        );

        $this->insert_row(
            'cpms_clinic_memberships',
            [
                'clinic_id'  => $this->clinic_a,
                'wp_user_id' => $clinician_user,
                'role_key'   => 'doctor',
                'scope_mode' => 'clinic',
                'status'     => 'active',
                'is_primary' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        $this->insert_row(
            'cpms_clinician_locations',
            [
                'clinician_id' => $this->clinician_a,
                'location_id'  => $this->location_a1,
                'is_primary'   => 1,
                'created_at'   => $now,
            ],
            [ '%d', '%d', '%d', '%s' ]
        );

        $date = $this->future_date();
        $this->slot_a1 = $this->insert_slot( $this->location_a1, $date, '10:00:00', $now );
        $this->slot_a3 = $this->insert_slot( $this->location_a3_inactive, $date, '11:00:00', $now );
    }

    private function insert_clinic( string $name, string $slug, string $now ): int
    {
        return $this->insert_row(
            'cpms_clinics',
            [
                'organization_id' => $this->org,
                'name'            => $name,
                'slug'            => $slug,
                'timezone'        => self::TZ_TEHRAN,
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    private function insert_location( int $clinic_id, string $name, int $is_active, string $now ): int
    {
        return $this->insert_row(
            'cpms_locations',
            [
                'clinic_id'  => $clinic_id,
                'name'       => $name . ' ' . $this->uid,
                'slug'       => strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $name ) ) . '-' . $this->uid,
                'address'    => 'Address ' . $name,
                'timezone'   => self::TZ_TEHRAN,
                'is_primary' => 0,
                'is_active'  => $is_active,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );
    }

    /** @return array{slot_id:int,date:string,time:string} */
    private function insert_slot( int $location_id, string $date, string $time, string $now ): array
    {
        $slot_id = $this->insert_row(
            'cpms_schedule_slots',
            [
                'clinic_id'      => $this->clinic_a,
                'location_id'    => $location_id,
                'clinician_id'   => $this->clinician_a,
                'slot_date'      => $date,
                'slot_time'      => $time,
                'duration_min'   => 20,
                'capacity'       => 2,
                'booked_count'   => 0,
                'held_count'     => 0,
                'is_open'        => 1,
                'generated_from' => 'manual',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
        );

        return [ 'slot_id' => $slot_id, 'date' => $date, 'time' => $time ];
    }

    /** A fixed offset keeps the fixture inside the booking window without wall clock. */
    private function future_date(): string
    {
        $tz = new \DateTimeZone( self::TZ_TEHRAN );

        return ( new \DateTimeImmutable( 'now', $tz ) )->modify( '+4 days' )->format( 'Y-m-d' );
    }

    /** @param array<string, mixed> $data @param list<string> $formats */
    private function insert_row( string $table, array $data, array $formats ): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->insert( $wpdb->prefix . $table, $data, $formats );
        self::assertTrue( (bool) $ok, 'fixture insert into ' . $table . ' failed: ' . $wpdb->last_error );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id, 'fixture id must be positive/dynamic.' );

        return $id;
    }
}
