<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Package B — defect 3 regression: a regular staff-booked appointment must
 * never be stored as walk-in express.
 *
 * Why this fixture is clock-independent (no production clock seam exists here):
 * `BookingService::createByStaff()` decides "is today" with
 * `$slot_date === gmdate( 'Y-m-d' )` — a UTC calendar-day string comparison.
 * `BookingService::now()` and `assertWindowWithTimezone()` read the real UTC
 * clock, and the only test clock seam in the codebase
 * (`VisitService::setTestNowUtc()`) is not consulted by BookingService. So this
 * test does NOT freeze the clock and does NOT add a seam to production code.
 *
 * Instead, the fixture uses a Location with a fixed UTC-11 offset
 * (`Pacific/Pago_Pago`) and books `slot_date = gmdate( 'Y-m-d' )` at wall time
 * `23:00:00`. That wall time always resolves to a UTC instant on the NEXT day
 * (at least 10 hours after any instant on the current UTC day), so the booking
 * window (min lead 0, max 60 days) accepts the slot at EVERY wall-clock time,
 * including 21:00-23:59 Asia/Tehran (UTC 17:30-20:29). There is no `+3 hours`
 * computation and no midnight wrap in the slot tuple.
 *
 * The stored slot_date still equals the UTC day that createByStaff() compares,
 * so the defective `is_today ? 1 : 0` branch is exercised. The result (0 or 1)
 * depends on the calendar date, never on the hour of day.
 */
final class PackageBStaffBookingNotWalkInExpressTest extends WP_UnitTestCase
{
    private const TZ = 'Pacific/Pago_Pago';
    private const SLOT_TIME = '23:00:00';

    private int $clinic_id;
    private int $location_id;
    private int $clinician_id;
    private int $patient_id;
    private int $slot_id;
    private int $secretary_user_id;
    private string $slot_date;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();

        $now = App::db()->nowUtcSql();

        $organization_id      = $this->insert_organization( $now );
        $this->clinic_id      = $this->insert_clinic( $organization_id, $now );
        $this->location_id    = $this->insert_location( $this->clinic_id, $now );
        $this->clinician_id   = $this->insert_clinician( $this->clinic_id, $now );
        $this->patient_id     = $this->insert_patient( $this->clinic_id, $now );
        $this->secretary_user_id = $this->insert_staff_user();

        // UTC day string: identical source to the production "is today" check.
        $this->slot_date = gmdate( 'Y-m-d' );
        $this->slot_id   = $this->insert_slot( $now );

        App::replaceExplicitScope( ClinicScope::forClinic( $this->clinic_id ) );
        App::settings()->set( 'booking.min_lead_hours', 2 );
        App::settings()->set( 'booking.max_future_days', 60 );
    }

    protected function tearDown(): void
    {
        App::resetScope();
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    public function test_staff_booked_same_day_appointment_is_not_walk_in_express(): void
    {
        $view = App::bookingService()->createByStaff(
            $this->secretary_user_id,
            $this->patient_id,
            $this->clinician_id,
            $this->slot_date,
            self::SLOT_TIME,
            'regular staff booking',
            $this->slot_id
        );

        self::assertSame( 'confirmed', $view['status'] );
        $appointment_id = (int) ( $view['appointment_id'] ?? 0 );
        self::assertGreaterThan( 0, $appointment_id );

        // Intended red assertion before the production fix: createByStaff()
        // writes 1 whenever slot_date equals gmdate( 'Y-m-d' ).
        self::assertFalse(
            (bool) ( $view['is_walkin_express'] ?? true ),
            'a staff-booked same-day appointment must not be walk-in express'
        );

        global $wpdb;
        $persisted = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT is_walkin_express FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d',
                $appointment_id
            )
        );
        self::assertSame( 0, $persisted, 'stored is_walkin_express must be 0' );

        // The doctor-day reader must not present the row as express.
        $repo = new AppointmentRepository( App::db() );
        $rows = $repo->listForDoctorOperationalDay(
            $this->clinic_id,
            $this->location_id,
            $this->clinician_id,
            $this->slot_date
        );
        $express = null;
        foreach ( $rows as $row ) {
            if ( (int) ( $row['id'] ?? 0 ) === $appointment_id ) {
                $express = $row['express'] ?? null;
                break;
            }
        }
        self::assertNotNull( $express, 'the staff-booked appointment must appear in the operational day list' );
        self::assertFalse( $express, 'the reader must not map is_walkin_express = 0 to express' );
    }

    private function insert_organization( string $now ): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'cpms_organizations',
            [
                'name'       => 'Package B Org',
                'slug'       => 'pkgb-org-' . bin2hex( random_bytes( 3 ) ),
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id );

        return $id;
    }

    private function insert_clinic( int $organization_id, string $now ): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'cpms_clinics',
            [
                'organization_id' => $organization_id,
                'name'            => 'Package B Clinic',
                'slug'            => 'pkgb-cl-' . bin2hex( random_bytes( 3 ) ),
                'timezone'        => 'Asia/Tehran',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id );

        return $id;
    }

    private function insert_location( int $clinic_id, string $now ): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'cpms_locations',
            [
                'clinic_id'  => $clinic_id,
                'name'       => 'Package B Location',
                'slug'       => 'pkgb-loc-' . bin2hex( random_bytes( 3 ) ),
                'timezone'   => self::TZ,
                'is_primary' => 1,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id );

        return $id;
    }

    private function insert_clinician( int $clinic_id, string $now ): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'cpms_clinicians',
            [
                'clinic_id'  => $clinic_id,
                'full_name'  => 'Dr Package B',
                // NULL avoids UNIQUE KEY u_clinician_user; MySQL UNIQUE ignores
                // NULLs (migration 0007 documents this explicitly).
                'wp_user_id' => null,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id );

        return $id;
    }

    private function insert_patient( int $clinic_id, string $now ): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'cpms_patients',
            [
                'clinic_id'  => $clinic_id,
                'mrn'        => 'MR-PB-' . bin2hex( random_bytes( 3 ) ),
                'first_name' => 'Package',
                'last_name'  => 'B Patient',
                'mobile'     => '0912' . sprintf( '%07d', random_int( 1000000, 9999999 ) ),
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id );

        return $id;
    }

    private function insert_staff_user(): int
    {
        $login   = 'pkgb_staff_' . bin2hex( random_bytes( 3 ) );
        $created = wp_create_user( $login, 'pass-not-used-123', $login . '@test.local' );
        $id      = is_int( $created ) ? $created : 0;
        self::assertGreaterThan( 0, $id );

        return $id;
    }

    private function insert_slot( string $now ): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'cpms_schedule_slots',
            [
                'clinic_id'      => $this->clinic_id,
                'location_id'    => $this->location_id,
                'clinician_id'   => $this->clinician_id,
                'slot_date'      => $this->slot_date,
                'slot_time'      => self::SLOT_TIME,
                'duration_min'   => 20,
                'capacity'       => 1,
                'booked_count'   => 0,
                'held_count'     => 0,
                'is_open'        => 1,
                'generated_from' => 'manual',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id );

        return $id;
    }
}
