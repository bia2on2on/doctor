<?php
declare(strict_types=1);

/**
 * Doctor dashboard past-visits digits (display only).
 *
 * The `past_visits` record payload now carries a display-only `jalali_date`
 * (Persian digits) while the stored `visit_date` (Gregorian DATE) is unchanged.
 *
 * Integration-only: requires a real WordPress test database, so it cannot run
 * locally (this environment is PHP-WASM only). It runs in the required CI
 * Integration job:
 *   php -d memory_limit=1G vendor/bin/phpunit --no-configuration
 *     --log-junit /tmp/phpunit-int-junit.xml
 *     --bootstrap tests/integration-bootstrap.php tests/Integration
 *   (ci.yml ~809-811)
 *
 * Mutation that makes this fail: remove the `jalali_date` line from
 * ClinicalService::record() past_visits mapping, or render `visit_date` raw
 * in the dashboard without `displayDigits()`.
 */

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;
use WP_UnitTestCase;

final class DoctorDashboardPastVisitsDigitsTest extends WP_UnitTestCase
{
    private const SHARED_RECORD = 'clinic/v1/visits/%d/record';
    private const VISIT_DATE = '2026-03-21'; // Jalali 1405/01/01 -> Persian ۱۴۰۵/۰۱/۰۱
    private const TZ_TEHRAN = 'Asia/Tehran';

    protected function setUp(): void {
        parent::setUp();
        wp_set_current_user( 0 );
        ScopeContext::clear();
        App::resetScope();
        App::migrations()->migrate();
    }

    protected function tearDown(): void {
        wp_set_current_user( 0 );
        $_GET = []; $_POST = []; $_REQUEST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
        ScopeContext::clear();
        App::resetScope();
        parent::tearDown();
    }

    public function test_past_visits_payload_carries_jalali_date_and_keeps_visit_date(): void {
        $stage = $this->makeStage();
        $patientId = $this->insertPatient( $stage['clinic'], 'pv' );
        $pastVisitId = $this->insertVisit(
            $patientId, $stage['clinician'], $stage['clinic'], $stage['location'],
            'checked_out', self::VISIT_DATE
        );
        $currentVisitId = $this->insertVisit(
            $patientId, $stage['clinician'], $stage['clinic'], $stage['location'],
            'consultation_completed', self::VISIT_DATE
        );

        wp_set_current_user( $stage['doctor'] );
        $headers = $this->scopeHeaders( $stage['clinic'], $stage['location'] );
        $r = $this->dispatch( 'GET', '/' . sprintf( self::SHARED_RECORD, $currentVisitId ), [], $headers );
        self::assertSame( 200, $r->get_status(), 'record endpoint must succeed' );
        $payload = $this->payload( $r );
        self::assertIsArray( $payload['past_visits'] ?? null, 'past_visits must be a list' );

        $found = false;
        foreach ( $payload['past_visits'] as $pv ) {
            if ( (int) $pv['id'] === $pastVisitId ) {
                $found = true;
                self::assertSame( self::VISIT_DATE, (string) $pv['visit_date'], 'stored visit_date unchanged' );
                self::assertSame(
                    Jalali::format_display_ymd( self::VISIT_DATE ),
                    (string) $pv['jalali_date'],
                    'jalali_date is the Persian Jalali of the same visit date'
                );
                break;
            }
        }
        self::assertTrue( $found, 'past visit present in record payload' );

        // Stored row untouched (display only).
        $row = App::db()->fetchRow(
            'SELECT visit_date FROM ' . App::db()->table( 'cpms_visits' ) . ' WHERE id = %d',
            [ $pastVisitId ]
        );
        self::assertNotNull( $row );
        self::assertSame( self::VISIT_DATE, (string) $row['visit_date'] );
    }

    // ================= fixtures (mirror Phase10DoctorPortalVisitWorkspaceRedTest) =================

    private function makeStage(): array {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $org = (int) $wpdb->insert(
            $wpdb->prefix . 'cpms_organizations',
            [ 'name' => 'Org pv', 'slug' => 'org-pv-' . bin2hex( random_bytes( 3 ) ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
        self::assertGreaterThan( 0, $org );
        $clinic = (int) $wpdb->insert(
            $wpdb->prefix . 'cpms_clinics',
            [ 'organization_id' => $org, 'name' => 'Clinic pv', 'slug' => 'cl-pv-' . bin2hex( random_bytes( 3 ) ), 'timezone' => self::TZ_TEHRAN, 'created_at' => $now, 'updated_at' => $now ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
        self::assertGreaterThan( 0, $clinic );
        $loc = (int) $wpdb->insert(
            $wpdb->prefix . 'cpms_locations',
            [ 'clinic_id' => $clinic, 'name' => 'Loc pv', 'slug' => 'loc-pv-' . bin2hex( random_bytes( 3 ) ), 'timezone' => self::TZ_TEHRAN, 'is_primary' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ],
            [ '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );
        self::assertGreaterThan( 0, $loc );
        $doctor = $this->makeUser( 'qa_pv_doc', \ClinicCore\Auth\RolesAndCapabilities::ROLE_DOCTOR );
        $clinician = (int) $wpdb->insert(
            $wpdb->prefix . 'cpms_clinicians',
            [ 'clinic_id' => $clinic, 'full_name' => 'Dr QA pv', 'wp_user_id' => $doctor, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ],
            [ '%d', '%s', '%d', '%d', '%s', '%s' ]
        );
        self::assertGreaterThan( 0, $clinician );
        cpms_test_seed_membership( $doctor, $clinic, 'cpms_doctor' );
        App::resetScope();

        return [ 'clinic' => $clinic, 'location' => $loc, 'clinician' => $clinician, 'doctor' => $doctor ];
    }

    private function scopeHeaders( int $clinic, ?int $location = null ): array {
        $headers = [ 'X-CPMS-Clinic-Id' => (string) $clinic ];
        if ( $location !== null ) {
            $headers['X-CPMS-Location-Id'] = (string) $location;
        }
        return $headers;
    }

    private function dispatch( string $method, string $route, array $params = [], array $headers = [], bool $withNonce = true ): \WP_REST_Response {
        $r = new \WP_REST_Request( $method, $route );
        foreach ( $params as $k => $v ) {
            $r->set_param( $k, $v );
        }
        if ( $withNonce ) {
            $r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        }
        foreach ( $headers as $k => $v ) {
            $r->set_header( $k, $v );
        }
        return rest_do_request( $r );
    }

    private function payload( \WP_REST_Response $res ): array {
        $b = $res->get_data();
        if ( is_array( $b ) && isset( $b['data'] ) && is_array( $b['data'] ) ) {
            return $b['data'];
        }
        return is_array( $b ) ? $b : [];
    }

    private function makeUser( string $login, string $role ): int {
        $u = $login . '_' . bin2hex( random_bytes( 3 ) );
        $id = (int) wp_create_user( $u, 'pass-not-used-123', $u . '@test.local' );
        self::assertGreaterThan( 0, $id );
        $user = get_userdata( $id );
        self::assertNotFalse( $user );
        $user->set_role( $role );
        return $id;
    }

    private function insertPatient( int $clinicId, string $tag ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-PV-' . strtoupper( $tag ) . '-' . bin2hex( random_bytes( 2 ) );
        $mobile = '0912' . sprintf( '%07d', random_int( 1000000, 9999999 ) );
        $wpdb->query( $wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
            $clinicId, $mrn, 'Test', 'Patient ' . substr( $mrn, -4 ), $mobile, 'active', $now, $now
        ) );
        return (int) $wpdb->insert_id;
    }

    private function insertVisit(
        int $patientId,
        int $clinicianId,
        int $clinicId,
        int $locId,
        string $status,
        string $visitDate
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $active = 'skipped' === $status ? 0 : 1;
        $checkIn = $visitDate . ' 10:00:00';
        $wpdb->query( $wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %d, %s, %s)',
            $clinicId, $locId, $clinicianId, $patientId, 'walk_in', $status, $visitDate, $checkIn, $checkIn, $active, $now, $now
        ) );
        return (int) $wpdb->insert_id;
    }
}
