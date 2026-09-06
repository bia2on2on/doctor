<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * REST-Level Tests صف (F4) — لایه Dispatch واقعی:
 *
 *  - TP-04: CSRF — بدون Nonce → 403 CLINIC_INVALID_NONCE
 *  - TP-09/Authorization: 401 unauthenticated / 403 نقش (بیمار = صف ممنوع)
 *  - TP-07 (بخش Visit): IDOR — بیمار روی منابع صف/ویزیت دیگران
 *  - D1/D6/D7/D8/D16/E3 — Happy path + Error Envelope `CLINIC_*`
 *  - R1 (ADR-0007): events feed + ETag/304 + Rate Limit polling
 */
final class RestQueueTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $clinicianId;
    private int $patientId;
    private int $otherPatientId;
    private int $secretaryUserId;
    private int $doctorUserId;
    private int $patientUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('queue.no_show_grace_minutes', 30);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Rest Queue',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        foreach ([['MR-RQ-0001', '09124440001', 'A'], ['MR-RQ-0002', '09124440002', 'B']] as [$mrn, $mobile, $name]) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                         (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                     VALUES (1, %s, %s, "P", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $mrn,
                    $name,
                    $mobile,
                    $now,
                    $now
                )
            );
            $patientIds[$name] = (int) $wpdb->insert_id;
        }
        $this->patientId = $patientIds['A'];
        $this->otherPatientId = $patientIds['B'];

        $this->patientUserId = $this->makeUser('rq_patient', 'cpms_patient');
        $this->secretaryUserId = $this->makeUser('rq_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('rq_doctor', 'cpms_doctor');

        // F9 (ADR-0027 Minor #3): پزشک متصل به Clinician — گارد مالکیت ویزیت
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_clinicians SET wp_user_id = %d WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->doctorUserId,
                $this->clinicianId
            )
        );
    }

    // ================= TP-04/TP-09 — Nonce/Auth =================

    public function testCheckInWithoutNonceIsCsurfBlocked(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $apptId = $this->makeAppointment($this->patientId, time() + 3600);

        $response = $this->dispatch('POST', self::NS . '/visits/checkin', [
            'patient_id' => $this->patientId,
            'appointment_id' => $apptId,
        ], withNonce: false);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('CLINIC_INVALID_NONCE', $response->get_data()['code'] ?? null);
    }

    public function testCheckInUnauthenticatedIs401(): void
    {
        wp_set_current_user(0);
        $response = $this->dispatch('POST', self::NS . '/visits/walk-in', [
            'patient_id' => $this->patientId,
            'clinician_id' => $this->clinicianId,
        ]);
        $this->assertSame(401, $response->get_status());
        $this->assertSame('CLINIC_UNAUTHORIZED', $response->get_data()['code'] ?? null);
    }

    public function testPatientCannotAccessQueue(): void
    {
        wp_set_current_user($this->patientUserId);
        $response = $this->dispatch('GET', self::NS . '/secretary/today', []);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('CLINIC_PERMISSION_DENIED', $response->get_data()['code'] ?? null);
    }

    public function testPatientCannotCheckIn(): void
    {
        wp_set_current_user($this->patientUserId);
        $response = $this->dispatch('POST', self::NS . '/visits/walk-in', [
            'patient_id' => $this->patientId,
            'clinician_id' => $this->clinicianId,
        ]);
        $this->assertSame(403, $response->get_status());
    }

    // ================= TP-07 — IDOR (بخش Visit) =================

    public function testPatientCannotSeeQueueOfOthers(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $visit = $this->walkInPatientA();

        // بیمار (حتی دارای ویزیت فعال) → همه منابع صف ممنوع (Capability؛
        // صف شامل داده بیماران دیگر است → IDOR در لایه Data-Access بسته می‌شود)
        wp_set_current_user($this->patientUserId);
        $queue = $this->dispatch('GET', self::NS . '/queue', []);
        $this->assertSame(403, $queue->get_status());

        $today = $this->dispatch('GET', self::NS . '/secretary/today', []);
        $this->assertSame(403, $today->get_status());

        $rt = $this->dispatch('GET', self::NS . '/rt/queue', ['since' => 0]);
        $this->assertSame(403, $rt->get_status());

        // اکشن‌های visit هم برای بیمار ممنوع (لایه 2)
        $call = $this->dispatch('POST', self::NS . '/visits/' . $visit['id'] . '/call', []);
        $this->assertSame(403, $call->get_status());
        $status = $this->dispatch('POST', self::NS . '/visits/' . $visit['id'] . '/status', [
            'to_status' => 'cancelled',
            'note' => 'x',
        ]);
        $this->assertSame(403, $status->get_status());
    }

    // ================= D6/D7 — Check-in/Walk-in =================

    public function testCheckInHappyPath(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $apptId = $this->makeAppointment($this->patientId, time() + 3600);

        $response = $this->dispatch('POST', self::NS . '/visits/checkin', [
            'patient_id' => $this->patientId,
            'appointment_id' => $apptId,
        ]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        $this->assertSame('waiting', $data['status']);
        $this->assertSame('scheduled', $data['source']);
        $this->assertSame($apptId, $data['appointment_id']);
    }

    public function testWalkInHappyPath(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $response = $this->dispatch('POST', self::NS . '/visits/walk-in', [
            'patient_id' => $this->patientId,
            'clinician_id' => $this->clinicianId,
        ]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        $this->assertSame('walk_in', $data['source']);
        $this->assertSame('waiting', $data['status']);
    }

    public function testDuplicateActiveVisitReturns409(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $this->walkInPatientA();

        $response = $this->dispatch('POST', self::NS . '/visits/walk-in', [
            'patient_id' => $this->patientId,
            'clinician_id' => $this->clinicianId,
        ]);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('CLINIC_DUPLICATE_ACTIVE_VISIT', $response->get_data()['code'] ?? null);
    }

    // ================= D8 — Transition منشی =================

    public function testSecretaryCannotSetInvalidTargetStatus(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $visit = $this->walkInPatientA();

        $response = $this->dispatch('POST', self::NS . '/visits/' . $visit['id'] . '/status', [
            'to_status' => 'in_consultation', // فقط پزشک — از مسیر منشی ممنوع
        ]);

        $this->assertSame(422, $response->get_status());
        $this->assertSame('CLINIC_VALIDATION_FAILED', $response->get_data()['code'] ?? null);
    }

    public function testSecretaryCancelWithReason(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $visit = $this->walkInPatientA();

        $response = $this->dispatch('POST', self::NS . '/visits/' . $visit['id'] . '/status', [
            'to_status' => 'cancelled',
            'note' => 'بیمار منصرف شد',
        ]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('cancelled', $response->get_data()['data']['status']);
    }

    // ================= E3 — فراخوان پزشک =================

    public function testDoctorCallHappyPath(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $visit = $this->walkInPatientA();

        wp_set_current_user($this->doctorUserId);
        $response = $this->dispatch('POST', self::NS . '/visits/' . $visit['id'] . '/call', ['room' => '3']);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('called', $response->get_data()['data']['status']);
    }

    public function testSecretaryCallIsRejected(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $visit = $this->walkInPatientA();

        // منشی QUEUE_CALL ندارد → لایه Capability
        $response = $this->dispatch('POST', self::NS . '/visits/' . $visit['id'] . '/call', []);
        $this->assertSame(403, $response->get_status());
    }

    // ================= D16 — Checkout =================

    public function testCheckoutWithoutPaymentIsPolicyViolation(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $visit = $this->walkInPatientA();
        $this->advanceToAwaitingPayment((int) $visit['id']);

        $response = $this->dispatch('POST', self::NS . '/visits/' . $visit['id'] . '/checkout', []);
        $this->assertSame(409, $response->get_status());
        $this->assertSame('CLINIC_POLICY_VIOLATION', $response->get_data()['code'] ?? null);
    }

    public function testCheckoutWithWaiveSucceeds(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $visit = $this->walkInPatientA();
        $this->advanceToAwaitingPayment((int) $visit['id']);

        $response = $this->dispatch('POST', self::NS . '/visits/' . $visit['id'] . '/checkout', [
            'waive_invoice' => ['reason' => 'معافیت پرسنلی'],
        ]);
        $this->assertSame(200, $response->get_status());
        $this->assertSame('checked_out', $response->get_data()['data']['status']);
    }

    // ================= D1 — داشبورد =================

    public function testSecretaryTodayDashboard(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $visit = $this->walkInPatientA();

        $response = $this->dispatch('GET', self::NS . '/secretary/today', []);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        $this->assertSame(1, $data['stats']['waiting']);
        $this->assertCount(1, $data['queue']);
        $this->assertSame((int) $visit['id'], (int) $data['queue'][0]['id']);
        $this->assertArrayHasKey('patient_name', $data['queue'][0]);
        $this->assertArrayHasKey('last_event_id', $data);
    }

    // ================= R1 — Real-time Feed (ADR-0007) =================

    public function testRtQueueReturnsEventsSinceAndEtag(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $visit = $this->walkInPatientA();

        $response = $this->dispatch('GET', self::NS . '/rt/queue', ['since' => 0]);
        $this->assertSame(200, $response->get_status());
        $etag = $response->get_headers()['ETag'] ?? null;
        $this->assertNotEmpty($etag);

        $events = $response->get_data()['data']['events'];
        $this->assertGreaterThanOrEqual(2, count($events)); // check_in + enqueue
        $this->assertSame('checked_in', $events[0]['to_status']);

        // ETag/304: بدون تغییر جدید → 304
        $request = new WP_REST_Request('GET', self::NS . '/rt/queue');
        $request->set_param('since', 0);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('If-None-Match', (string) $etag);
        $notModified = rest_do_request($request);
        $this->assertSame(304, $notModified->get_status());

        // رویداد جدید → ETag تغییر می‌کند و since قبلی فقط جدید را می‌دهد
        wp_set_current_user($this->doctorUserId);
        $this->dispatch('POST', self::NS . '/visits/' . $visit['id'] . '/call', []);
        wp_set_current_user($this->secretaryUserId);

        $request2 = new WP_REST_Request('GET', self::NS . '/rt/queue');
        $request2->set_param('since', (int) $response->get_data()['data']['last_event_id']);
        $request2->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $fresh = rest_do_request($request2);
        $this->assertSame(200, $fresh->get_status());
        $newEvents = $fresh->get_data()['data']['events'];
        $this->assertSame('called', $newEvents[0]['to_status']);
        $this->assertNotSame($etag, $fresh->get_headers()['ETag'] ?? null);
    }

    // ================= Helpers =================

    /**
     * @return array<string, mixed>
     */
    private function walkInPatientA(): array
    {
        $response = $this->dispatch('POST', self::NS . '/visits/walk-in', [
            'patient_id' => $this->patientId,
            'clinician_id' => $this->clinicianId,
        ]);
        $this->assertSame(200, $response->get_status());

        return $response->get_data()['data'];
    }

    private function advanceToAwaitingPayment(int $visitId): void
    {
        wp_set_current_user($this->doctorUserId);
        $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/call', []);
        $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/start', []);
        // FR-8.7 (F5): complete فقط با Chief Complaint غیرخالی — پیش‌نیاز را می‌سازیم
        App::clinicalService()->addNote($this->doctorUserId, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'سردرد برای تست تکمیل ویزیت',
        ]);
        $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/complete', []);
        wp_set_current_user($this->secretaryUserId);
        $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/status', [
            'to_status' => 'awaiting_payment',
        ]);
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function makeAppointment(int $patientId, int $atTs): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        // تاریخ و ساعت از «یک» لحظه — نیمه‌شب UTC را با هم طی می‌کنند (date rollover)
        $date = gmdate('Y-m-d', $atTs);
        $slotTime = gmdate('H:i:s', $atTs);

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianId,
                $date,
                $slotTime,
                $now,
                $now
            )
        );
        $slotId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                     (clinic_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time,
                      duration_min, slot_end_time, status, confirmed_at, created_at, updated_at)
                 VALUES (1, %s, %d, %d, %d, %s, %s, 20, %s, "confirmed", %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'RQ-' . bin2hex(random_bytes(6)),
                $patientId,
                $this->clinicianId,
                $slotId,
                $date,
                $slotTime,
                gmdate('H:i:s', strtotime($slotTime) + 1200),
                $now,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatch(string $method, string $route, array $body, bool $withNonce = true): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($withNonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }

        $response = rest_do_request($request);
        if ($response->is_error()) {
            // خطاهای REST → همان envelope استاندارد ما برگردانده می‌شود
        }

        return $response;
    }
}
