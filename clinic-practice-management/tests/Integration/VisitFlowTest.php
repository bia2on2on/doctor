<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use WP_UnitTestCase;

/**
 * TP-19 + J-* — جریان کامل مراجعه/صف (F4):
 *
 *  - V1 Check-in با نوبت → Visit + Enqueue خودکار (FR-6.1) + active_visit_id (D-6)
 *  - V2 Walk-in بدون نوبت
 *  - J-5: ویزیت فعال تکراری ممنوع → CLINIC_DUPLICATE_ACTIVE_VISIT
 *  - ER-06/FR-5.5: Check-in دیرهنگام → no_show نوبت + Visit فوری Walk-in-like (ارجاع)
 *  - V4/V5/V6/V7/V10/V14: کل چرخه صف تا خروج + T9 (نوبت مرجع completed)
 *  - J-6: سقف Recall → CLINIC_RECALL_LIMIT_REACHED
 *  - J-3: تاریخچه append-only با هر transition
 *  - J-4: نوبت فوری (express) در سر صف
 *  - FR-5.5: Sweep خودکار no_show (idempotent + فقط بدون ویزیت فعال)
 */
final class VisitFlowTest extends WP_UnitTestCase
{
    private int $clinicianId;
    private int $patientId;
    private int $secretaryUserId;
    private int $doctorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('queue.no_show_grace_minutes', 30);
        App::settings()->set('queue.max_recalls', 2);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Visit Test',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Queue", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-VISIT-0001',
                '09123330001',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $this->secretaryUserId = $this->makeUser('visit_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('visit_doctor', 'cpms_doctor');
    }

    // ================= V1 — Check-in =================

    public function testCheckInCreatesVisitAndAutoEnqueues(): void
    {
        $apptId = $this->makeAppointment($this->patientId, $this->clinicianId, gmdate('Y-m-d'), gmdate('H:i:s', time() + 3600));

        $visit = App::visitService()->checkIn($this->secretaryUserId, $this->patientId, $apptId);

        $this->assertSame('waiting', $visit['status']); // Enqueue خودکار (FR-6.1)
        $this->assertSame('scheduled', $visit['source']);
        $this->assertNotEmpty($visit['waiting_since']);

        // D-6: رابطه دوطرفه
        $appt = App::db()->fetchRow(
            'SELECT active_visit_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$apptId]
        );
        $this->assertSame((int) $visit['id'], (int) $appt['active_visit_id']);

        // J-3: check-in + enqueue → دو رخداد تاریخچه
        $history = App::visitService()->history($this->secretaryUserId, (int) $visit['id']);
        $this->assertCount(2, $history);
        $this->assertSame('checked_in', $history[0]['to_status']);
        $this->assertSame('waiting', $history[1]['to_status']);
        $this->assertSame('system', $history[1]['actor_role']);
    }

    public function testDuplicateActiveVisitIsRejected(): void
    {
        $apptId = $this->makeAppointment($this->patientId, $this->clinicianId, gmdate('Y-m-d'), gmdate('H:i:s', time() + 3600));
        App::visitService()->checkIn($this->secretaryUserId, $this->patientId, $apptId);

        // J-5: دومین Check-in همان بیمار×پزشک در همان روز
        try {
            App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
            $this->fail('Expected CLINIC_DUPLICATE_ACTIVE_VISIT');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_DUPLICATE_ACTIVE_VISIT', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
        }
    }

    public function testLateCheckInMarksNoShowAndCreatesWalkInLikeVisit(): void
    {
        // نوبت 2 ساعت پیش — از Grace (30 دقیقه) گذشته (ER-06/TP-19)
        $slot = gmdate('H:i:s', time() - 7200);
        $apptId = $this->makeAppointment($this->patientId, $this->clinicianId, gmdate('Y-m-d'), $slot);

        $visit = App::visitService()->checkIn($this->secretaryUserId, $this->patientId, $apptId);

        $this->assertSame('walk_in', $visit['source']); // Walk-in-like
        $this->assertSame($apptId, $visit['appointment_id']); // ارجاع حفظ می‌شود

        $appt = App::db()->fetchRow(
            'SELECT status, no_show_at FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$apptId]
        );
        $this->assertSame('no_show', $appt['status']);
        $this->assertNotEmpty($appt['no_show_at']);
    }

    public function testCheckInOnNoShowAppointmentCreatesWalkInLikeVisit(): void
    {
        $apptId = $this->makeAppointment($this->patientId, $this->clinicianId, gmdate('Y-m-d'), gmdate('H:i:s', time() - 7200));
        // قبلاً توسط Cron نوبت no_show شده
        App::db()->update('cpms_appointments', ['status' => 'no_show', 'no_show_at' => App::db()->nowUtcSql()], ['id' => $apptId]);

        $visit = App::visitService()->checkIn($this->secretaryUserId, $this->patientId, $apptId);
        $this->assertSame('walk_in', $visit['source']);
        $this->assertSame('waiting', $visit['status']);
    }

    public function testCheckInOnCancelledAppointmentIsRejected(): void
    {
        $apptId = $this->makeAppointment($this->patientId, $this->clinicianId, gmdate('Y-m-d'), gmdate('H:i:s', time() + 3600));
        App::db()->update('cpms_appointments', ['status' => 'cancelled_by_staff'], ['id' => $apptId]);

        try {
            App::visitService()->checkIn($this->secretaryUserId, $this->patientId, $apptId);
            $this->fail('Expected CLINIC_INVALID_APPOINTMENT_STATE');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_INVALID_APPOINTMENT_STATE', $e->errorCode);
        }
    }

    public function testCheckInWrongPatientIsRejected(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Other", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-VISIT-0002',
                '09123330002',
                $now,
                $now
            )
        );
        $otherPatientId = (int) $wpdb->insert_id;

        $apptId = $this->makeAppointment($this->patientId, $this->clinicianId, gmdate('Y-m-d'), gmdate('H:i:s', time() + 3600));

        try {
            App::visitService()->checkIn($this->secretaryUserId, $otherPatientId, $apptId);
            $this->fail('Expected CLINIC_PERMISSION_DENIED');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
        }
    }

    // ================= V2 — Walk-in =================

    public function testWalkInCreatesQueuedVisit(): void
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);

        $this->assertSame('walk_in', $visit['source']);
        $this->assertSame('waiting', $visit['status']);
        $this->assertNull($visit['appointment_id']);
    }

    // ================= چرخه صف (V4..V14 + T9) =================

    public function testFullQueueLifecycleThroughCheckout(): void
    {
        $apptId = $this->makeAppointment($this->patientId, $this->clinicianId, gmdate('Y-m-d'), gmdate('H:i:s', time() + 3600));
        $visit = App::visitService()->checkIn($this->secretaryUserId, $this->patientId, $apptId);
        $visitId = (int) $visit['id'];

        // V4: فراخوان — فقط پزشک
        $visit = App::visitService()->transition($this->doctorUserId, $visitId, 'call', ['room' => '3']);
        $this->assertSame('called', $visit['status']);

        // V5: شروع ویزیت
        $visit = App::visitService()->transition($this->doctorUserId, $visitId, 'start', []);
        $this->assertSame('in_consultation', $visit['status']);

        // V10: پایان ویزیت
        $visit = App::visitService()->transition($this->doctorUserId, $visitId, 'complete', []);
        $this->assertSame('consultation_completed', $visit['status']);

        // V11: فاکتور آماده (منشی) — در F6 فاکتور واقعی
        $visit = App::visitService()->transition($this->secretaryUserId, $visitId, 'invoice_ready', []);
        $this->assertSame('awaiting_payment', $visit['status']);

        // V13: معافیت با دلیل
        $visit = App::visitService()->transition($this->secretaryUserId, $visitId, 'waive', ['reason' => 'ویزیت رایگان']);
        $this->assertSame('checked_out', $visit['status']);

        // T9: نوبت مرجع completed
        $appt = App::db()->fetchRow(
            'SELECT status, active_visit_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$apptId]
        );
        $this->assertSame('completed', $appt['status']);
        $this->assertNull($appt['active_visit_id']);

        // J-3: کل تاریخچه — check_in, enqueue, call, start, complete, invoice_ready, waive
        $this->assertCount(7, App::visitService()->history($this->secretaryUserId, $visitId));
    }

    public function testCallBySecretaryIsRejectedByMachine(): void
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);

        try {
            App::visitService()->transition($this->secretaryUserId, (int) $visit['id'], 'call', []);
            $this->fail('Expected CLINIC_INVALID_TRANSITION');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_INVALID_TRANSITION', $e->errorCode);
        }
    }

    public function testRecallLimitIsEnforced(): void
    {
        App::settings()->set('queue.max_recalls', 2);
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
        $visitId = (int) $visit['id'];

        // call → recall → call → recall (سقف) → call → recall = رد
        for ($i = 0; $i < 2; $i++) {
            App::visitService()->transition($this->doctorUserId, $visitId, 'call', []);
            $v = App::visitService()->transition($this->doctorUserId, $visitId, 'recall', []);
            $this->assertSame('waiting', $v['status']);
            $this->assertSame($i + 1, (int) $v['recall_count']);
        }
        App::visitService()->transition($this->doctorUserId, $visitId, 'call', []);
        try {
            App::visitService()->transition($this->doctorUserId, $visitId, 'recall', []);
            $this->fail('Expected CLINIC_RECALL_LIMIT_REACHED');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_RECALL_LIMIT_REACHED', $e->errorCode);
        }
    }

    public function testSkipRequiresReasonAndDeactivates(): void
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
        $visitId = (int) $visit['id'];

        try {
            App::visitService()->transition($this->doctorUserId, $visitId, 'skip', []);
            $this->fail('Expected CLINIC_VALIDATION_FAILED');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }

        $v = App::visitService()->transition($this->doctorUserId, $visitId, 'skip', ['reason' => 'بیمار موقتاً خارج شد']);
        $this->assertSame('skipped', $v['status']);
        $this->assertSame(0, (int) $v['active']);
    }

    public function testCancelRequiresReason(): void
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
        $visitId = (int) $visit['id'];

        try {
            App::visitService()->transition($this->secretaryUserId, $visitId, 'cancel', []);
            $this->fail('Expected CLINIC_VALIDATION_FAILED');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }

        $v = App::visitService()->transition($this->secretaryUserId, $visitId, 'cancel', ['reason' => 'ثبت اشتباه']);
        $this->assertSame('cancelled', $v['status']);
    }

    // ================= J-4 — اولویت نوبت فوری =================

    public function testExpressAppointmentIsHeadOfQueue(): void
    {
        // بیمار عادی اول می‌آید
        $normal = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);

        // بیمار دوم با نوبت فوری (express)
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Express", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-VISIT-0003',
                '09123330003',
                $now,
                $now
            )
        );
        $expressPatientId = (int) $wpdb->insert_id;
        $apptId = $this->makeAppointment($expressPatientId, $this->clinicianId, gmdate('Y-m-d'), gmdate('H:i:s', time() + 1800), isWalkinExpress: 1);
        $express = App::visitService()->checkIn($this->secretaryUserId, $expressPatientId, $apptId);

        $repo = new VisitRepository(App::db());
        $queue = $repo->queueFor(1, null, ['waiting']);
        $this->assertCount(2, $queue);
        $this->assertSame((int) $express['id'], (int) $queue[0]['id']); // سر صف
        $this->assertSame((int) $normal['id'], (int) $queue[1]['id']);
    }

    // ================= FR-5.5 — Sweep خودکار =================

    public function testNoShowSweepMarksOnlyUnvisitedLateAppointments(): void
    {
        $late = $this->makeAppointment($this->patientId, $this->clinicianId, gmdate('Y-m-d'), gmdate('H:i:s', time() - 7200));
        $upcoming = $this->makeAppointment($this->patientId, $this->clinicianId, gmdate('Y-m-d'), gmdate('H:i:s', time() + 3600));
        // نوبت دیرهنگام با ویزیت فعال → نباید no_show شود (زمان یکتا — UNIQUE u_slot)
        $visitedLate = $this->makeAppointment($this->patientId, $this->clinicianId, gmdate('Y-m-d'), gmdate('H:i:s', time() - 7140));
        // بیمار دیگری برای نوبت سوم (قانون J-5 همان بیمار)
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Sweep", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-VISIT-0004',
                '09123330004',
                $now,
                $now
            )
        );
        $sweepPatientId = (int) $wpdb->insert_id;
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_appointments SET patient_id = %d WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $sweepPatientId,
                $visitedLate
            )
        );
        App::visitService()->checkIn($this->secretaryUserId, $sweepPatientId, $visitedLate);

        $count = App::visitService()->processNoShows();

        $this->assertSame(1, $count); // فقط نوبت دیرهنگام بدون ویزیت
        $status = static fn (int $id): string => (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$id]
        );
        $this->assertSame('no_show', $status($late));
        $this->assertSame('confirmed', $status($upcoming));
        $this->assertSame('no_show', $status($visitedLate)); // از check-in دیرهنگام (ER-06)

        // Idempotent: اجرای دوباره هیچ نوبت جدیدی نمی‌گیرد
        $this->assertSame(0, App::visitService()->processNoShows());
    }

    // ================= Helpers =================

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function makeAppointment(
        int $patientId,
        int $clinicianId,
        string $slotDate,
        string $slotTime,
        int $isWalkinExpress = 0
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        // Slot واقعی — FK appointments.slot_id
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicianId,
                $slotDate,
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
                      duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)
                 VALUES (1, %s, %d, %d, %d, %s, %s, 20, %s, "confirmed", %d, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'VF-' . bin2hex(random_bytes(6)),
                $patientId,
                $clinicianId,
                $slotId,
                $slotDate,
                $slotTime,
                gmdate('H:i:s', strtotime($slotTime) + 1200),
                $isWalkinExpress,
                $now,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }
}
