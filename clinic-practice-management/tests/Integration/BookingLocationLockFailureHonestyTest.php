<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * B1 hold — a failed SELECT ... FOR UPDATE on the Location row is a transient
 * SQL failure, not a missing Location. Only the FOR UPDATE read is rewritten:
 * the non-locking preflight reads are left untouched, so the transaction gets
 * as far as the locking helper. Fault injection follows the established
 * add_filter('query') + suppress_errors() pattern in BookingAuditAtomicityTest.
 */
final class BookingLocationLockFailureHonestyTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';
    private const LOG_MESSAGE = 'booking.lock_read_failed';
    private const BUSY_CODE = 'CLINIC_BOOKING_BUSY';
    private const LEGACY_TEXT = 'Location یافت نشد';

    private int $clinicianId = 0;
    private int $patientUserId = 0;
    private int $locationId = 0;
    private int $logBaseline = 0;
    private ?\Closure $fault = null;
    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('booking.hold_ttl_sec', 600);
        wp_set_current_user(0);

        global $wpdb;
        $now    = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $mobile = '09121110001';

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                 (clinic_id, full_name, is_active, created_at, updated_at)
             VALUES (1, %s, 1, %s, %s)',
            'Dr Lock ' . $unique,
            $now,
            $now
        ));
        $this->clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicianId, 'precondition: clinician created');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
             VALUES (1, %s, %s, %s, %s, %s, %s, %s)',
            'MR-LOCK-' . $unique,
            'A',
            'Patient',
            $mobile,
            'active',
            $now,
            $now
        ));
        $patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $patientId, 'precondition: patient created');

        $this->patientUserId = (int) wp_create_user(
            'lock_patient_' . $unique,
            'pass-12345',
            $unique . '@otp.cpms.local'
        );
        $user = get_userdata($this->patientUserId);
        self::assertNotFalse($user, 'precondition: patient WP user created');
        $user->set_role('cpms_patient');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                 (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
             VALUES (1, %d, %d, %s, 1, %s)',
            $patientId,
            $this->patientUserId,
            $mobile,
            $now
        ));
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'precondition: link created');

        $this->locationId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations')
            . ' WHERE clinic_id = 1 AND is_active = 1 LIMIT 1'
        );
        self::assertGreaterThan(0, $this->locationId, 'precondition: seeded active Location');

        $this->logBaseline = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_operational_logs')
            . ' WHERE message = %s',
            [self::LOG_MESSAGE]
        );
    }

    protected function tearDown(): void
    {
        if ($this->fault !== null) {
            remove_filter('query', $this->fault, 5);
            $this->fault = null;
            global $wpdb;
            $wpdb->suppress_errors($this->previousSuppression);
        }
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testFailedLocationLockIsRetryableLoggedAndRolledBack(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot('10:00');
        $this->installFault('error');

        $response = $this->dispatch($this->holdBody($slot));
        self::assertSame(503, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame(self::BUSY_CODE, (string) ($data['code'] ?? ''));
        self::assertNotSame('CLINIC_NOT_FOUND', (string) ($data['code'] ?? ''));
        self::assertStringContainsString('دوباره تلاش', (string) ($data['message'] ?? ''));
        self::assertStringNotContainsString(self::LEGACY_TEXT, (string) ($data['message'] ?? ''));
        self::assertSame(0, $this->activeHolds($slot['id']), 'transaction must roll back');
        self::assertSame(0, $this->heldCount($slot['id']), 'capacity must be untouched');

        $rows = $this->diagnosticLogs();
        self::assertCount($this->logBaseline + 1, $rows, 'one diagnostic after rollback');
        $last    = $rows[count($rows) - 1];
        $context = json_decode((string) $last['context_json'], true);
        self::assertSame('error', (string) $last['level']);
        self::assertIsArray($context);
        self::assertSame('location', (string) ($context['read'] ?? ''));
        self::assertSame($this->locationId, (int) ($context['location_id'] ?? 0));
        self::assertSame(1, (int) ($context['clinic_id'] ?? 0));
        self::assertArrayHasKey('sql_errno', $context);
        self::assertNotSame('', (string) ($context['sql_error'] ?? ''));
        self::assertStringNotContainsString('09121110001', (string) $last['context_json']);
    }

    public function testMissingLocationUnderLockKeepsLegacy404(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot('11:00');
        $this->installFault('missing');

        $response = $this->dispatch($this->holdBody($slot));
        self::assertSame(404, $response->get_status());
        $data = $response->get_data();
        self::assertSame('CLINIC_NOT_FOUND', (string) ($data['code'] ?? ''));
        self::assertSame(self::LEGACY_TEXT, (string) ($data['message'] ?? ''));
        self::assertSame(0, $this->activeHolds($slot['id']));
        self::assertCount($this->logBaseline, $this->diagnosticLogs());
    }

    public function testInactiveLocationUnderLockKeepsLegacy404(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot('13:00');
        $this->installFault('inactive');

        $response = $this->dispatch($this->holdBody($slot));
        self::assertSame(404, $response->get_status());
        $data = $response->get_data();
        self::assertSame('CLINIC_NOT_FOUND', (string) ($data['code'] ?? ''));
        self::assertSame(self::LEGACY_TEXT, (string) ($data['message'] ?? ''));
        self::assertSame(0, $this->activeHolds($slot['id']));
        self::assertCount($this->logBaseline, $this->diagnosticLogs());
    }

    public function testHoldSuccessPathIsUnchanged(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot('14:00');

        $response = $this->dispatch($this->holdBody($slot));
        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertNotEmpty($data['data']['hold_token'] ?? null);
        self::assertSame(1, $this->activeHolds($slot['id']));
        self::assertSame(1, $this->heldCount($slot['id']));
        self::assertCount($this->logBaseline, $this->diagnosticLogs());
    }

    /**
     * Rewrite only the Location FOR UPDATE read. 'error' makes the driver
     * fail, 'missing' returns no row and 'inactive' returns is_active = 0.
     */
    private function installFault(string $mode): void
    {
        global $wpdb;
        self::assertNull($this->fault, 'fault injection must be installed once per test');

        $fault = static function (string $query) use ($mode): string {
            if (
                stripos($query, 'cpms_locations') === false
                || stripos($query, 'FOR UPDATE') === false
            ) {
                return $query;
            }
            $matched = preg_match(
                '/FROM\s+(`?[A-Za-z0-9_]+`?)\s+WHERE\s+id\s*=\s*(\d+)/i',
                $query,
                $parts
            );
            if ($matched !== 1) {
                return $query;
            }
            $table = $parts[1];
            $id    = $parts[2];
            if ($mode === 'error') {
                return 'SELECT id, clinic_id, timezone, is_active FROM ' . $table
                    . ' WHERE cpms_fault_injection_no_such_column = 1 LIMIT 1 FOR UPDATE';
            }
            if ($mode === 'missing') {
                return 'SELECT id, clinic_id, timezone, is_active FROM ' . $table
                    . ' WHERE id = 0 LIMIT 1 FOR UPDATE';
            }

            return 'SELECT id, clinic_id, timezone, 0 AS is_active FROM ' . $table
                . ' WHERE id = ' . $id . ' LIMIT 1 FOR UPDATE';
        };

        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        $this->fault               = $fault;
        add_filter('query', $fault, 5);
    }

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function makeSlot(string $time): array
    {
        global $wpdb;
        $date = gmdate('Y-m-d', time() + 3 * 86400);
        $now  = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                 (clinic_id, location_id, clinician_id, slot_date, slot_time,
                  duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
             VALUES (1, %d, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)',
            $this->locationId,
            $this->clinicianId,
            $date,
            $time,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: slot created');

        return ['id' => $id, 'date' => $date, 'time' => $time];
    }

    /**
     * @param array{id: int, date: string, time: string} $slot
     * @return array<string, mixed>
     */
    private function holdBody(array $slot): array
    {
        return [
            'clinician_id' => $this->clinicianId,
            'slot_date'    => $slot['date'],
            'slot_time'    => $slot['time'],
            'slot_id'      => $slot['id'],
            'location_id'  => $this->locationId,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatch(array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::NS . '/booking/hold');
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function diagnosticLogs(): array
    {
        return App::db()->fetchAll(
            'SELECT id, level, message, context_json FROM '
            . App::db()->table('cpms_operational_logs')
            . ' WHERE message = %s ORDER BY id',
            [self::LOG_MESSAGE]
        );
    }

    private function activeHolds(int $slotId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_slot_holds')
            . ' WHERE slot_id = %d AND status = %s',
            [$slotId, 'active']
        );
    }

    private function heldCount(int $slotId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT held_count FROM ' . App::db()->table('cpms_schedule_slots')
            . ' WHERE id = %d LIMIT 1',
            [$slotId]
        );
    }
}
