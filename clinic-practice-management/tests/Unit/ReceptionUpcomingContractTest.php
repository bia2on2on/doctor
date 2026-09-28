<?php
/** Bounded structural controls for the Reception future-list integration. */
declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReceptionUpcomingContractTest extends TestCase {
    private function source( string $relative ): string {
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
        self::assertIsString( $source );
        return $source;
    }

    public function testReadModelIsBoundedScopedOrderedAndMinimal(): void {
        $source = $this->source( 'src/Infrastructure/Repository/AppointmentRepository.php' );
        $start = strpos( $source, 'public function list_for_reception_upcoming(' );
        self::assertNotFalse( $start );
        $method = substr( $source, $start, strpos( $source, 'public function clinician_labels_for_reception_operational_day', $start ) - $start );
        foreach ( [ 'a.clinic_id = %d', 'a.location_id = %d', 'a.slot_date > %s', 'a.slot_date <= %s', "a.status IN ('pending', 'confirmed')", 'a.slot_date ASC, a.slot_time ASC, a.id ASC', 'LIMIT 500', 'c.full_name AS clinician_name', "'patient_name'", "'jalali'" ] as $required ) {
            self::assertStringContainsString( $required, $method );
        }
        foreach ( [ 'mobile', 'national_id', 'reference_code', 'clinical', 'clinicians.clinic_id' ] as $private ) {
            self::assertStringNotContainsString( $private, $method );
        }
    }

    public function testPortalUsesExistingAuthorityAndMutationRoutesWithoutPollingUpcoming(): void {
        $controller = $this->source( 'src/Rest/ReceptionPortalController.php' );
        self::assertStringContainsString( "'/staff/portal/reception/upcoming'", $controller );
        self::assertStringContainsString( 'RolesAndCapabilities::APPT_READ', $controller );
        self::assertStringContainsString( 'list_for_reception_upcoming( $clinic_id, $location_id, $today, $through )', $controller );
        self::assertStringContainsString( '$this->booking_horizon_days( $clinic_id )', $controller );
        $ui = $this->source( 'templates/staff-reception.php' );
        foreach ( [ 'نوبت‌های آینده', 'sr-upcoming-row', 'sr-upcoming-state', 'loadUpcoming', 'escapeHtml(row.jalali)', 'escapeHtml(doctor.name)', '/staff/portal/reception/upcoming', '/cancel', '/reschedule', 'Idempotency-Key' ] as $required ) {
            self::assertStringContainsString( $required, $ui );
        }
        self::assertSame( 1, substr_count( $ui, 'function poll()' ) );
        self::assertStringNotContainsString( 'setTimeout(loadUpcoming', $ui );
    }
}
