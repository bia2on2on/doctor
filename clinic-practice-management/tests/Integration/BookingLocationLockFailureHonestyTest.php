<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Driver-level read failures in the public booking path must be honest 503s,
 * never a false "Location/Clinic/Slot not found" 404. Locking and non-locking
 * reads are injected separately with add_filter('query') + suppress_errors(),
 * following BookingAuditAtomicityTest. A genuinely absent or inactive row
 * keeps today's exact 404 envelope, code and Persian text.
 */
final class BookingLocationLockFailureHonestyTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';
    private const LOG_MESSAGE = 'booking.lock_read_failed';
    private const BUSY_CODE = 'CLINIC_BOOKING_BUSY';
    private const BUSY_TEXT = 'سامانه موقتاً مشغول است؛ لطفاً دوباره تلاش کنید.';
    private const LEGACY_TEXT = 'Location یافت نشد';
    private const IDEM_ENDPOINT = 'booking/confirm';

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
        $this->removeFault();
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
        $this->assertBoundedContext($context);
        self::assertStringNotContainsString('09121110001', (string) $last['context_json']);
    }

    public function testSyntheticDriverValuesNeverReachResponseOrStoredDiagnostic(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot('21:00');
        $this->installFault('synthetic_values');

        $response = $this->dispatch($this->holdBody($slot));
        self::assertSame(503, $response->get_status());
        self::assertSame([
            'code' => self::BUSY_CODE,
            'message' => self::BUSY_TEXT,
            'data' => ['status' => 503],
        ], $response->get_data());
        self::assertSame(0, $this->activeHolds($slot['id']), 'transaction must roll back');
        self::assertSame(0, $this->heldCount($slot['id']), 'capacity must be untouched');

        $rows = $this->diagnosticLogs();
        self::assertCount($this->logBaseline + 1, $rows, 'deferred diagnostic after rollback');
        $context = json_decode((string) $rows[count($rows) - 1]['context_json'], true);
        self::assertIsArray($context);
        $this->assertBoundedContext($context);
        self::assertSame('location', $context['read'] ?? null);
        foreach (self::syntheticValues() as $value) {
            self::assertStringNotContainsString($value, (string) $rows[count($rows) - 1]['context_json']);
            // Unescaped Unicode avoids false positives from \u0642 containing "42".
            self::assertStringNotContainsString($value, (string) wp_json_encode($response->get_data(), JSON_UNESCAPED_UNICODE));
        }
    }

    public function testSyntheticDriverValuesNeverReachNonLockingDiagnostic(): void
    {
        $slot = $this->makeSlot('22:00');
        $this->installFault('synthetic_values', 'location_active');

        $response = $this->dispatchQuote($this->holdBody($slot));
        self::assertSame(503, $response->get_status());
        self::assertSame([
            'code' => self::BUSY_CODE,
            'message' => self::BUSY_TEXT,
            'data' => ['status' => 503],
        ], $response->get_data());
        $context = $this->lastDiagnostic();
        $this->assertBoundedContext($context);
        self::assertSame('location_active', $context['read'] ?? null);
        foreach (self::syntheticValues() as $value) {
            self::assertStringNotContainsString($value, (string) wp_json_encode($context, JSON_UNESCAPED_UNICODE));
            self::assertStringNotContainsString($value, (string) wp_json_encode($response->get_data(), JSON_UNESCAPED_UNICODE));
        }
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

    public function testConfirmLockFailureKeepsHoldAndIdempotencyKeyRetryable(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot('15:00');
        $hold = $this->dispatch($this->holdBody($slot));
        self::assertSame(200, $hold->get_status());
        $token = (string) ($hold->get_data()['data']['hold_token'] ?? '');
        self::assertNotSame('', $token);
        $key = $this->uuid();

        $this->installFault('error');
        $busy = $this->dispatchConfirm($token, $key);
        self::assertSame(503, $busy->get_status());
        self::assertSame(self::BUSY_CODE, (string) ($busy->get_data()['code'] ?? ''));
        self::assertSame('active', $this->holdStatus($token), '503 must not release the Hold');
        self::assertSame(1, $this->heldCount($slot['id']), 'capacity must survive the retry');
        self::assertSame(0, $this->idemRows($key), 'pending Idempotency key must be released');

        $context = $this->lastDiagnostic();
        self::assertSame('location', (string) ($context['read'] ?? ''));
        self::assertIsInt($context['sql_errno'] ?? null);
        self::assertGreaterThan(0, (int) ($context['sql_errno'] ?? 0));
        $this->assertBoundedContext($context);

        $this->removeFault();
        $retry = $this->dispatchConfirm($token, $key);
        self::assertSame(200, $retry->get_status(), 'same key must succeed after release');
        self::assertSame('confirmed', (string) ($retry->get_data()['data']['status'] ?? ''));
        self::assertSame('converted', $this->holdStatus($token));
    }

    public function testDiagnosticSqlErrnoIsPositiveInteger(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot('20:00');
        $this->installFault('error');

        $response = $this->dispatch($this->holdBody($slot));
        self::assertSame(503, $response->get_status());
        $context = $this->lastDiagnostic();
        self::assertIsInt($context['sql_errno'] ?? null);
        self::assertGreaterThan(0, (int) ($context['sql_errno'] ?? 0));
        $this->assertBoundedContext($context);
    }

    public function testSlotLockFailureIsRetryable(): void
    {
        $this->assertLockFailure('slot_lock', 'slot');
    }

    public function testOrganizationLockFailureIsRetryable(): void
    {
        $this->assertLockFailure('organization_lock', 'organization');
    }

    public function testClinicLockFailureIsRetryable(): void
    {
        $this->assertLockFailure('clinic_lock', 'clinic');
    }

    public function testPersistedSlotReadFailureIsRetryable(): void
    {
        $this->assertNonLockingFailure('persisted_slot', 'persisted_slot');
    }

    public function testOrganizationActiveReadFailureIsRetryable(): void
    {
        $this->assertNonLockingFailure('organization_active', 'organization_active');
    }

    public function testLocationActiveReadFailureIsRetryable(): void
    {
        $this->assertNonLockingFailure('location_active', 'location_active');
    }

    public function testLocationTimezoneReadFailureIsRetryable(): void
    {
        $this->assertNonLockingFailure('location_timezone', 'location_timezone');
    }

    public function testPersistedSlotAbsentRowKeepsLegacy404(): void
    {
        $this->assertNonLockingAbsent('persisted_slot', 'اسلات با شناسه داده‌شده یافت نشد');
    }

    public function testOrganizationActiveAbsentRowKeepsLegacy404(): void
    {
        $this->assertNonLockingAbsent('organization_active', 'کلینیک یافت نشد');
    }

    public function testLocationActiveAbsentRowKeepsLegacy404(): void
    {
        $this->assertNonLockingAbsent('location_active', 'محل نوبت یافت نشد');
    }

    public function testLocationTimezoneAbsentRowKeepsLegacy404(): void
    {
        $this->assertNonLockingAbsent('location_timezone', self::LEGACY_TEXT);
    }

    public function testQuoteShowsRetryableErrorOnFailedLocationRead(): void
    {
        $slot = $this->makeSlot('19:00');
        $this->installFault('error', 'location_active');

        $response = $this->dispatchQuote($this->holdBody($slot));
        self::assertSame(503, $response->get_status());
        self::assertSame(self::BUSY_CODE, (string) ($response->get_data()['code'] ?? ''));
        self::assertSame('location_active', (string) ($this->lastDiagnostic()['read'] ?? ''));
    }

    private function assertLockFailure(string $target, string $label): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot('16:00');
        $this->installFault('error', $target);

        $response = $this->dispatch($this->holdBody($slot));
        self::assertSame(503, $response->get_status());
        self::assertSame(self::BUSY_CODE, (string) ($response->get_data()['code'] ?? ''));
        self::assertSame(0, $this->activeHolds($slot['id']));
        self::assertSame(0, $this->heldCount($slot['id']));
        self::assertSame($label, (string) ($this->lastDiagnostic()['read'] ?? ''));
    }

    private function assertNonLockingFailure(string $target, string $label): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot('17:00');
        $this->installFault('error', $target);

        $response = $this->dispatch($this->holdBody($slot));
        self::assertSame(503, $response->get_status());
        self::assertSame(self::BUSY_CODE, (string) ($response->get_data()['code'] ?? ''));
        self::assertStringNotContainsString(
            self::LEGACY_TEXT,
            (string) ($response->get_data()['message'] ?? '')
        );
        self::assertSame(0, $this->activeHolds($slot['id']));
        self::assertSame($label, (string) ($this->lastDiagnostic()['read'] ?? ''));
    }

    private function assertNonLockingAbsent(string $target, string $text): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot('18:00');
        $this->installFault('absent', $target);

        $response = $this->dispatch($this->holdBody($slot));
        self::assertSame(404, $response->get_status());
        self::assertSame('CLINIC_NOT_FOUND', (string) ($response->get_data()['code'] ?? ''));
        self::assertSame($text, (string) ($response->get_data()['message'] ?? ''));
        self::assertCount($this->logBaseline, $this->diagnosticLogs());
    }

    /**
     * Rewrite exactly one matched read class. 'error' makes the driver fail,
     * 'missing'/'absent' return no row and 'inactive' returns is_active = 0.
     */
    private function installFault(string $mode, string $target = 'location_lock'): void
    {
        global $wpdb;
        self::assertNull($this->fault, 'fault injection must be installed once per test');

        $fault = static function (string $query) use ($mode, $target): string {
            if (!self::matchesTarget($query, $target)) {
                return $query;
            }
            $table = self::tableFrom($query);
            if ($table === '') {
                return $query;
            }
            if ($mode === 'inactive' && $target === 'location_lock') {
                $id = 0;
                if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $query, $parts) === 1) {
                    $id = (int) $parts[1];
                }

                return 'SELECT id, clinic_id, timezone, 0 AS is_active FROM ' . $table
                    . ' WHERE id = ' . $id . ' LIMIT 1 FOR UPDATE';
            }
            if ($mode === 'synthetic_values') {
                // Syntax error text includes these synthetic literals. No real
                // patient data is used; the driver message must never be stored.
                return 'SELECT 1 FROM ' . $table . " WHERE = 'Li' 42 "
                    . "'synthetic.person@example.test' 'آزمایشی' '李'";
            }
            if ($mode === 'missing' || $mode === 'absent') {
                return 'SELECT 1 FROM ' . $table . ' WHERE 1 = 0 LIMIT 1';
            }

            return 'SELECT 1 FROM ' . $table
                . ' WHERE cpms_fault_injection_no_such_column = 1 LIMIT 1';
        };

        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        $this->fault               = $fault;
        add_filter('query', $fault, 5);
    }

    private function removeFault(): void
    {
        if ($this->fault === null) {
            return;
        }
        global $wpdb;
        remove_filter('query', $this->fault, 5);
        $this->fault = null;
        $wpdb->suppress_errors($this->previousSuppression);
    }

    /** Match one read class, never a whole table, so later reads stay real. */
    private static function matchesTarget(string $query, string $target): bool
    {
        switch ($target) {
            case 'location_lock':
                return stripos($query, 'cpms_locations') !== false
                    && stripos($query, 'FOR UPDATE') !== false;
            case 'organization_lock':
                return stripos($query, 'SELECT id, status FROM') !== false
                    && stripos($query, 'cpms_organizations') !== false
                    && stripos($query, 'FOR UPDATE') !== false;
            case 'clinic_lock':
                return stripos($query, 'SELECT id, organization_id, status FROM') !== false
                    && stripos($query, 'cpms_clinics') !== false
                    && stripos($query, 'FOR UPDATE') !== false;
            case 'slot_lock':
                return stripos($query, 'SELECT * FROM') !== false
                    && stripos($query, 'cpms_schedule_slots') !== false
                    && stripos($query, 'FOR UPDATE') !== false;
            case 'persisted_slot':
                return stripos($query, 'SELECT id, clinic_id, clinician_id FROM') !== false
                    && stripos($query, 'cpms_schedule_slots') !== false;
            case 'organization_active':
                return stripos($query, 'SELECT o.status FROM') !== false;
            case 'location_active':
                return stripos($query, 'SELECT id FROM') !== false
                    && stripos($query, 'cpms_locations') !== false
                    && stripos($query, 'is_active = 1') !== false;
            case 'location_timezone':
                return stripos($query, 'SELECT id, clinic_id, timezone FROM') !== false
                    && stripos($query, 'cpms_locations') !== false
                    && stripos($query, 'WHERE id = ') !== false;
        }

        return false;
    }

    private static function tableFrom(string $query): string
    {
        if (preg_match('/FROM\s+(`?[A-Za-z0-9_]+`?)/i', $query, $parts) === 1) {
            return $parts[1];
        }

        return '';
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

    private function holdStatus(string $token): string
    {
        return (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_slot_holds')
            . ' WHERE token = %s LIMIT 1',
            [$token]
        );
    }

    private function idemRows(string $key): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_idempotency_keys')
            . ' WHERE `key` = %s AND endpoint = %s',
            [$key, self::IDEM_ENDPOINT]
        );
    }

    /** @return list<string> */
    private static function syntheticValues(): array
    {
        return ['Li', '42', 'synthetic.person@example.test', 'آزمایشی', '李'];
    }

    /** @param array<string, mixed> $context */
    private function assertBoundedContext(array $context): void
    {
        self::assertArrayNotHasKey('sql_error', $context);
        self::assertIsInt($context['sql_errno'] ?? null);
        self::assertIsString($context['read'] ?? null);
        foreach ($context as $key => $value) {
            self::assertContains($key, [
                'read', 'sql_errno', 'clinic_id', 'organization_id', 'location_id',
                'clinician_id', 'slot_id', 'hold_id', 'appointment_id',
            ]);
            if ($key !== 'read') {
                self::assertIsInt($value);
            }
        }
    }

    /** @return array<string, mixed> */
    private function lastDiagnostic(): array
    {
        $rows = $this->diagnosticLogs();
        self::assertCount($this->logBaseline + 1, $rows, 'exactly one new diagnostic');
        $context = json_decode((string) $rows[count($rows) - 1]['context_json'], true);
        self::assertIsArray($context);

        return $context;
    }

    private function dispatchConfirm(string $token, string $key): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::NS . '/booking/confirm');
        $request->set_param('hold_token', $token);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('Idempotency-Key', $key);

        return rest_do_request($request);
    }

    /** @param array<string, mixed> $body */
    private function dispatchQuote(array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::NS . '/booking/quote');
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
