<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * REST-Level Tests (F3) — لایه Dispatch واقعی (نه فراخوانی مستقیم Service):
 *
 *  - TP-04: CSRF — موتانت بدون Nonce → 403 CLINIC_INVALID_NONCE
 *  - Authorization: 401 unauthenticated / 403 نقش/Capability (TP-09)
 *  - Error Envelope: شکل استاندارد `CLINIC_*` (Contract §0 / ADR-0019)
 *  - TP-07 (بخش Appointment): IDOR — بیمار B روی نوبت بیمار A
 *  - Idempotency-Key الزامی (B2/B5) + Replay
 *  - Rate Limit (booking 10/hr) → 429 CLINIC_RATE_LIMITED
 *  - Success Envelope: {data} + هدر X-CPMS-Correlation-Id (M10)
 */
final class RestBookingTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $clinicianId;
    private int $patientUserId;
    private int $otherPatientUserId;
    private int $secretaryUserId;
    private int $patientId;
    private int $otherPatientId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('booking.hold_ttl_sec', 600);
        App::settings()->set('booking.cancel_deadline_hours', 24);
        App::settings()->set('booking.reschedule_deadline_hours', 24);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Rest Test',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        // بیمار A
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "A", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-REST-0001',
                '09121110001',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        // بیمار B
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "B", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-REST-0002',
                '09121110002',
                $now,
                $now
            )
        );
        $this->otherPatientId = (int) $wpdb->insert_id;

        // کاربران (نقش صریح — wp_create_user نقش را default می‌گذارد)
        $this->patientUserId = $this->makeUser('rest_patient_a', 'cpms_patient', '09121110001@otp.cpms.local');
        $this->otherPatientUserId = $this->makeUser('rest_patient_b', 'cpms_patient', '09121110002@otp.cpms.local');
        $this->secretaryUserId = $this->makeUser('rest_secretary', 'cpms_secretary', 'sec@test.local');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (1, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->patientId,
                $this->patientUserId,
                '09121110001',
                $now
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (1, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->otherPatientId,
                $this->otherPatientUserId,
                '09121110002',
                $now
            )
        );

        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ---------- TP-04: Nonce ----------

    public function testHoldWithoutNonceRejected(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot(3, '10:00');

        $response = $this->dispatch('POST', self::NS . '/booking/hold', [
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
        ], withNonce: false);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_INVALID_NONCE');
    }

    public function testHoldWithInvalidNonceRejected(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot(3, '10:00');

        $request = $this->request('POST', self::NS . '/booking/hold', [
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
        ]);
        $request->set_header('X-WP-Nonce', 'garbage-nonce-value');
        $response = rest_do_request($request);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_INVALID_NONCE');
    }

    // ---------- Authorization ----------

    public function testHoldUnauthenticatedRejected(): void
    {
        wp_set_current_user(0);
        $slot = $this->makeSlot(3, '10:00');

        $response = $this->dispatch('POST', self::NS . '/booking/hold', [
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
        ]);

        $this->assertSame(401, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_UNAUTHORIZED');
    }

    public function testHoldByNonPatientRoleRejectedAndAudited(): void
    {
        // منشی نقش بیمار ندارد و نباید از مسیر بیمار (B1) رزرو کند
        wp_set_current_user($this->secretaryUserId);
        $slot = $this->makeSlot(3, '10:00');

        $response = $this->dispatch('POST', self::NS . '/booking/hold', [
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED');

        // Audit تلاش غیرمجاز ثبت شده است (TP-07/TP-09)
        $count = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') .
            " WHERE action = 'FORBIDDEN_ACCESS_ATTEMPT' AND actor_wp_user_id = %d",
            [$this->secretaryUserId]
        );
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testStaffListByPatientRejected(): void
    {
        wp_set_current_user($this->patientUserId);

        $response = $this->dispatch('GET', self::NS . '/appointments', [
            'clinician_id' => $this->clinicianId,
            'date' => gmdate('Y-m-d'),
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED');
    }

    // ---------- Happy path + Envelope ----------

    public function testHoldConfirmEnvelopeAndCorrelationHeader(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot(3, '10:00');

        $holdResponse = $this->dispatch('POST', self::NS . '/booking/hold', [
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
        ]);
        $this->assertSame(200, $holdResponse->get_status());
        $holdData = $holdResponse->get_data();
        $this->assertArrayHasKey('data', $holdData);
        $this->assertNotEmpty($holdData['data']['hold_token']);
        $this->assertNotEmpty($holdData['data']['expires_at']);
        $this->assertSame($this->clinicianId, $holdData['data']['slot']['clinician_id']);
        // M10: Correlation ID در Response
        $this->assertNotEmpty(
            $holdResponse->get_headers()['X-CPMS-Correlation-Id'] ?? null,
            'headers=' . wp_json_encode($holdResponse->get_headers())
            . ' fn_exists=' . var_export(function_exists('cpms_request_id'), true)
        );

        $key = $this->uuid();
        $confirmResponse = $this->dispatch('POST', self::NS . '/booking/confirm', [
            'hold_token' => $holdData['data']['hold_token'],
        ], idempotencyKey: $key);
        $this->assertSame(200, $confirmResponse->get_status());
        $confirmData = $confirmResponse->get_data();
        $this->assertSame('confirmed', $confirmData['data']['status']);
        $this->assertMatchesRegularExpression('/^AP-\d{8}-\d{2}$/', $confirmData['data']['reference_code']);
        $appointmentId = (int) $confirmData['data']['id'];

        // Replay با همان کلید = همان Appointment (بدون رکورد دوم)
        $replay = $this->dispatch('POST', self::NS . '/booking/confirm', [
            'hold_token' => $holdData['data']['hold_token'],
        ], idempotencyKey: $key);
        $this->assertSame(200, $replay->get_status());
        $this->assertSame($appointmentId, (int) $replay->get_data()['data']['id']);

        $count = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$appointmentId]
        );
        $this->assertSame(1, $count);
    }

    public function testConfirmWithoutIdempotencyKeyRejected(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot(3, '10:00');
        $hold = $this->holdViaService($slot);

        $response = $this->dispatch('POST', self::NS . '/booking/confirm', [
            'hold_token' => $hold['hold_token'],
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_VALIDATION_FAILED');
    }

    public function testRescheduleWithoutIdempotencyKeyRejected(): void
    {
        wp_set_current_user($this->patientUserId);
        $appt = $this->confirmedAppointmentViaService();
        $newSlot = $this->makeSlot(5, '12:00');

        $response = $this->dispatch('POST', self::NS . '/appointments/' . $appt['id'] . '/reschedule', [
            'slot_date' => $newSlot['date'],
            'slot_time' => $newSlot['time'],
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_VALIDATION_FAILED');
    }

    // ---------- TP-07 (IDOR — Appointment) ----------

    public function testPatientCannotConfirmOthersHold(): void
    {
        wp_set_current_user($this->patientUserId);
        $slot = $this->makeSlot(3, '10:00');
        $hold = $this->holdViaService($slot);

        // بیمار B با Hold بیمار A
        wp_set_current_user($this->otherPatientUserId);
        $response = $this->dispatch('POST', self::NS . '/booking/confirm', [
            'hold_token' => $hold['hold_token'],
        ], idempotencyKey: $this->uuid());

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED');
    }

    public function testPatientCannotCancelOthersAppointment(): void
    {
        $appt = $this->confirmedAppointmentViaService(); // مالک: بیمار A

        wp_set_current_user($this->otherPatientUserId);
        $response = $this->dispatch('POST', self::NS . '/appointments/' . $appt['id'] . '/cancel', [
            'reason' => 'test',
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED');

        // وضعیت نوبت تغییری نکرده
        $status = (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [(int) $appt['id']]
        );
        $this->assertSame('confirmed', $status);
    }

    public function testPatientCannotRescheduleOthersAppointment(): void
    {
        $appt = $this->confirmedAppointmentViaService();
        $newSlot = $this->makeSlot(5, '12:00');

        wp_set_current_user($this->otherPatientUserId);
        $response = $this->dispatch('POST', self::NS . '/appointments/' . $appt['id'] . '/reschedule', [
            'slot_date' => $newSlot['date'],
            'slot_time' => $newSlot['time'],
        ], idempotencyKey: $this->uuid());

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED');
    }

    // ---------- Staff (D9/D10) ----------

    public function testStaffCreateAndListViaRest(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $slot = $this->makeSlot(1, '09:00'); // فردا — بدون min-lead برای Staff

        $create = $this->dispatch('POST', self::NS . '/appointments', [
            'patient_id' => $this->patientId,
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'reason' => 'ویزیت حضوری',
        ]);
        $this->assertSame(200, $create->get_status());
        $this->assertSame('confirmed', $create->get_data()['data']['status']);
        // فردا = آینده → is_walkin_express فقط برای روز جاری
        $this->assertSame(0, (int) $create->get_data()['data']['is_walkin_express']);
        $appointmentId = (int) $create->get_data()['data']['id'];

        // تکرار همان Patient/Slot → 409
        $dup = $this->dispatch('POST', self::NS . '/appointments', [
            'patient_id' => $this->patientId,
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
        ]);
        $this->assertSame(409, $dup->get_status());
        $this->assertClinicError($dup, 'CLINIC_DUPLICATE_APPOINTMENT');

        // D9 لیست روز
        $list = $this->dispatch('GET', self::NS . '/appointments', [
            'clinician_id' => $this->clinicianId,
            'date' => $slot['date'],
        ]);
        $this->assertSame(200, $list->get_status());
        $ids = array_map(static fn ($r) => (int) $r['id'], $list->get_data()['data']);
        $this->assertContains($appointmentId, $ids);
    }

    // ---------- Rate Limit ----------

    public function testHoldRateLimitedAfterTenPerHour(): void
    {
        wp_set_current_user($this->patientUserId);

        $times = ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00'];
        $last = null;
        foreach ($times as $time) {
            $slot = $this->makeSlot(3, $time);
            $last = $this->dispatch('POST', self::NS . '/booking/hold', [
                'clinician_id' => $this->clinicianId,
                'slot_date' => $slot['date'],
                'slot_time' => $slot['time'],
            ]);
            if ($time !== '13:00') {
                $this->assertSame(200, $last->get_status(), "hold at {$time} should be allowed");
            }
        }

        $this->assertSame(429, $last->get_status());
        $this->assertClinicError($last, 'CLINIC_RATE_LIMITED');
    }

    // ---------- Helpers ----------

    private function makeUser(string $login, string $role, string $email): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $email);
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function makeSlot(int $dayOffset, string $time, int $capacity = 1): array
    {
        global $wpdb;
        $date = gmdate('Y-m-d', time() + $dayOffset * 86400);
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, %s, 20, %d, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianId,
                $date,
                $time,
                $capacity,
                $now,
                $now
            )
        );

        return ['id' => (int) $wpdb->insert_id, 'date' => $date, 'time' => $time];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatch(string $method, string $route, array $body = [], bool $withNonce = true, ?string $idempotencyKey = null): WP_REST_Response
    {
        $request = $this->request($method, $route, $body);
        if ($withNonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        if ($idempotencyKey !== null) {
            $request->set_header('Idempotency-Key', $idempotencyKey);
        }

        return rest_do_request($request);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $route, array $body): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    /**
     * Envelope استاندارد: data.code = CLINIC_* (ADR-0019) + data.status.
     */
    private function assertClinicError(WP_REST_Response $response, string $code): void
    {
        $data = $response->get_data();
        $this->assertIsArray($data, 'Error envelope must be an array');
        $this->assertArrayHasKey('code', $data, 'Error envelope must carry machine-readable code');
        $this->assertSame($code, $data['code']);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame($response->get_status(), $data['data']['status'] ?? null);
    }

    /**
     * @return array{hold_token: string}
     */
    private function holdViaService(array $slot): array
    {
        return App::bookingService()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
    }

    /**
     * @return array{id: int}
     */
    private function confirmedAppointmentViaService(): array
    {
        $slot = $this->makeSlot(4, '11:00');
        $hold = $this->holdViaService($slot);
        $view = App::bookingService()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());

        return ['id' => (int) $view['id']];
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
