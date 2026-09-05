<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Booking\BookingException;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * TP-03/TP-20 — جریان کامل رزرو (F3):
 * quote → hold → confirm (+Idempotency) → mine/cancel/reschedule + staff create.
 *
 * تضمین‌های کلیدی که اینجا تست می‌شوند:
 *  - Double-Booking: شمارنده‌ها هرگز ظرفیت را رد نمی‌کنند (DB-level).
 *  - Idempotent Replay: تکرار confirm با همان کلید = پاسخ Origin (بدون Appointment دوم).
 *  - Hold Expiry: آزادسازی ظرفیت + خطای CLINIC_HOLD_EXPIRED.
 *  - Duration Snapshot: تغییر Default بعد از رزرو، نوبت موجود را تغییر نمی‌دهد (TP-21).
 *  - Policy لغو: داخل Window = CLINIC_POLICY_VIOLATION.
 *  - Patient Auto-Creation برای کاربر جدید (OTP mobile).
 *  - Transaction Rollback: شکست Confirm → ظرفیت آزاد می‌شود.
 */
final class BookingFlowTest extends WP_UnitTestCase
{
    private int $clinicianId;
    private int $patientUserId;
    private int $otherUserId;

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
                'Dr Test',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        // بیمار + کاربر
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Test", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-TEST-0001',
                '09120000001',
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;

        $this->patientUserId = (int) wp_create_user('patient_1', 'pass-12345', 'p1@test.local', ['role' => 'cpms_patient']);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (1, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $patientId,
                $this->patientUserId,
                '09120000001',
                $now
            )
        );

        // کاربر دوم (بدون Patient — برای تست Auto-Creation)
        $this->otherUserId = (int) wp_create_user('patient_2', 'pass-12345', '09120000002@otp.cpms.local', ['role' => 'cpms_patient']);
    }

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function makeSlot(int $dayOffset, string $time, int $capacity = 1, int $duration = 20): array
    {
        global $wpdb;
        $date = gmdate('Y-m-d', time() + $dayOffset * 86400);
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, %s, %d, %d, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianId,
                $date,
                $time,
                $duration,
                $capacity,
                $now,
                $now
            )
        );

        return ['id' => (int) $wpdb->insert_id, 'date' => $date, 'time' => $time];
    }

    private function booking()
    {
        return App::bookingService();
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ---------- A4 ----------

    public function testQuoteShowsCapacity(): void
    {
        $slot = $this->makeSlot(3, '10:00', 2);
        $result = $this->booking()->quote($this->clinicianId, $slot['date'], $slot['time']);
        $this->assertTrue($result['available']);
        $this->assertSame(2, $result['capacity_left']);

        // نوبت در گذشته = خارج Window
        $this->expectException(BookingException::class);
        $this->booking()->quote($this->clinicianId, gmdate('Y-m-d', time() - 86400), '10:00');
    }

    // ---------- B1/B2 ----------

    public function testHoldThenConfirmFullFlow(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1, 25);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $this->assertNotEmpty($hold['hold_token']);
        $this->assertSame(25, $hold['slot']['duration_min']);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(1, (int) $row['held_count']);

        $key = $this->uuid();
        $result = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, 'ویزیت عمومی', $key);
        $this->assertNotEmpty($result['reference_code']);
        $this->assertSame('confirmed', $result['status']);
        $this->assertSame(25, $result['duration_min'], 'Duration باید Snapshot شود (25، نه Default کلینیک)');

        $slotRow = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count, held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(1, (int) $slotRow['booked_count']);
        $this->assertSame(0, (int) $slotRow['held_count']);

        $appt = $wpdb->get_row(
            $wpdb->prepare('SELECT duration_min, slot_end_time, status FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', (int) $result['appointment_id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(25, (int) $appt['duration_min']);
        $this->assertSame('10:25:00', $appt['slot_end_time']);
    }

    public function testDoubleBookingPrevented(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        // Hold دوم (بیمار دیگر / کاربر دوم) باید SLOT_TAKEN باشد
        $this->expectException(BookingException::class);
        try {
            $this->booking()->hold($this->otherUserId, $this->clinicianId, $slot['date'], $slot['time']);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_SLOT_TAKEN', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
            throw $e;
        }
    }

    public function testConfirmIdempotentReplay(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $key = $this->uuid();

        $first = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $key);
        $second = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $key);

        $this->assertSame($first['reference_code'], $second['reference_code']);
        $this->assertSame($first['appointment_id'], $second['appointment_id']);

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_appointments WHERE slot_id = %d', $slot['id']) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $this->assertSame(1, $count, 'Replay نباید Appointment دوم بسازد');

        $slotRow = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(1, (int) $slotRow['booked_count']);
    }

    public function testConfirmWithoutIdempotencyKeyRejected(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        $this->expectException(BookingException::class);
        try {
            $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, null);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }

    public function testHoldExpiredReleasesCapacity(): void
    {
        global $wpdb;
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_slot_holds SET expires_at = %s WHERE token = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                gmdate('Y-m-d H:i:s', time() - 10) . '.000',
                $hold['hold_token']
            )
        );

        $this->expectException(BookingException::class);
        try {
            $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_HOLD_EXPIRED', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
            throw $e;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(0, (int) $row['held_count'], 'ظرفیت Hold منقضی باید آزاد شود');
    }

    public function testConfirmOwnershipEnforced(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        $this->expectException(BookingException::class);
        try {
            $this->booking()->confirm($hold['hold_token'], $this->otherUserId, null, $this->uuid());
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            $this->assertSame(403, $e->httpStatus);
            throw $e;
        }
    }

    public function testPatientAutoCreatedForNewUser(): void
    {
        global $wpdb;
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->otherUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $result = $this->booking()->confirm($hold['hold_token'], $this->otherUserId, null, $this->uuid());

        $patient = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT p.* FROM ' . $wpdb->prefix . 'cpms_patient_user_links' . ' l
                 JOIN ' . $wpdb->prefix . 'cpms_patients' . ' p ON p.id = l.patient_id
                 WHERE l.wp_user_id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->otherUserId
            ),
            ARRAY_A
        );
        $this->assertNotNull($patient, 'Patient Record باید برای کاربر جدید ساخته شود');
        $this->assertSame('09120000002', $patient['mobile']);
        $this->assertMatchesRegularExpression('/^MR-\d{6}-[A-Z0-9]{5}$/', (string) $patient['mrn']);
        $this->assertSame((int) $result['appointment_id'], (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'cpms_appointments WHERE patient_id = %d', (int) $patient['id']) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        ));
    }

    // ---------- B3/B4/B5/B6 ----------

    public function testListMine(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());

        $list = $this->booking()->listMine($this->patientUserId, gmdate('Y-m-d', time() - 86400), gmdate('Y-m-d', time() + 86400 * 30));
        $this->assertCount(1, $list);
        $this->assertSame($slot['date'], $list[0]['date']);
        $this->assertNotEmpty($list[0]['jalali']);
    }

    public function testCancelPolicyWindow(): void
    {
        // نوبت 3 روز بعد (خارج از Window لغو 24h — wait: 72h > 24h → مجاز)
        $slotFar = $this->makeSlot(3, '10:00', 1);
        $holdFar = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slotFar['date'], $slotFar['time']);
        $far = $this->booking()->confirm($holdFar['hold_token'], $this->patientUserId, null, $this->uuid());

        $result = $this->booking()->cancelByPatient($this->patientUserId, (int) $far['appointment_id'], 'تغییر برنامه');
        $this->assertSame('cancelled_by_patient', $result['status']);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slotFar['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(0, (int) $row['booked_count'], 'لغو باید ظرفیت آزاد کند');

        // نوبت 30 ساعت بعد → داخل Window لغو 24h → ممنوع
        $slotNear = $this->makeSlot(2, '23:00', 1);
        $holdNear = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slotNear['date'], $slotNear['time']);
        $near = $this->booking()->confirm($holdNear['hold_token'], $this->patientUserId, null, $this->uuid());

        $this->expectException(BookingException::class);
        try {
            $this->booking()->cancelByPatient($this->patientUserId, (int) $near['appointment_id'], null);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_POLICY_VIOLATION', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
            throw $e;
        }
    }

    public function testRescheduleMovesSlots(): void
    {
        global $wpdb;
        $oldSlot = $this->makeSlot(3, '10:00', 1, 20);
        $newSlot = $this->makeSlot(5, '14:00', 1, 40);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $oldSlot['date'], $oldSlot['time']);
        $appt = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());

        $result = $this->booking()->reschedule(
            $this->patientUserId,
            (int) $appt['appointment_id'],
            $this->clinicianId,
            $newSlot['date'],
            '14:00',
            $this->uuid()
        );
        $this->assertNotSame($appt['appointment_id'], $result['appointment_id']);
        $this->assertSame((int) $appt['appointment_id'], $result['previous_appointment_id']);
        $this->assertSame(40, $result['duration_min'], 'Snapshot اسلات جدید باید 40 باشد');

        $oldRow = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $oldSlot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $newRow = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $newSlot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(0, (int) $oldRow['booked_count'], 'اسلات قبلی باید آزاد شود');
        $this->assertSame(1, (int) $newRow['booked_count'], 'اسلات جدید باید Book شود');

        $oldAppt = $wpdb->get_row(
            $wpdb->prepare('SELECT status, rescheduled_to FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', (int) $appt['appointment_id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame('rescheduled', $oldAppt['status']);
        $this->assertSame((int) $result['appointment_id'], (int) $oldAppt['rescheduled_to']);
    }

    public function testResumeAfterDisconnect(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        $resumed = $this->booking()->resume($hold['hold_token'], $this->patientUserId);
        $this->assertSame('active', $resumed['status']);
        $this->assertSame($hold['hold_token'], $resumed['hold_token']);
        $this->assertSame($slot['id'], $resumed['slot']['slot_id']);
    }

    // ---------- Snapshot (TP-21) ----------

    public function testDurationSnapshotNotAffectedBySettingsChange(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1, 20);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $appt = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());

        // تغییر Default کلینیک بعد از رزرو
        App::settings()->set('booking.duration_default_min', 45);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT duration_min FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', (int) $appt['appointment_id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(20, (int) $row['duration_min'], 'تغییر Default نباید نوبت ثبت‌شده را تغییر دهد (TP-21)');
    }

    // ---------- Staff (D9/D10/D11) ----------

    public function testStaffCreateDuplicateAndListAndCancel(): void
    {
        global $wpdb;
        $slot = $this->makeSlot(1, '10:00', 1);
        $patientId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'cpms_patients WHERE mrn = %s', 'MR-TEST-0001') // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        $created = $this->booking()->createByStaff($this->patientUserId, $patientId, $this->clinicianId, $slot['date'], '10:00', 'فوری');
        $this->assertSame('confirmed', $created['status']);

        // تکراری
        try {
            $this->booking()->createByStaff($this->patientUserId, $patientId, $this->clinicianId, $slot['date'], '10:00', null);
            $this->fail('Duplicate باید رد شود');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_DUPLICATE_APPOINTMENT', $e->errorCode);
        }

        // لیست روز (D9)
        $list = $this->booking()->listForClinician($this->clinicianId, $slot['date'], null);
        $this->assertCount(1, $list);
        $this->assertSame($created['reference_code'], $list[0]['reference_code']);

        // لغو توسط منشی (D11) — بدون محدودیت Deadline
        $result = $this->booking()->cancelByStaff($this->patientUserId, (int) $created['appointment_id'], 'درخواست بیمار');
        $this->assertSame('cancelled_by_staff', $result['status']);

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(0, (int) $row['booked_count']);
    }
}
