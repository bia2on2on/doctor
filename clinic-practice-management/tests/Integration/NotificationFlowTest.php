<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\ApptReminderHandler;
use ClinicCore\Application\Jobs\FollowUpReminderHandler;
use ClinicCore\Application\Jobs\NotifDispatchHandler;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Queue\JobQueue;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * تست‌های اعلان F8 — FR-20.1..20.6 + notifications.md (N-1..N-6).
 *
 * پوشش:
 *  - N-1/N-2: QUEUE.called / QUEUE.ready_payment → INSERT queued (نه blocking)
 *  - N-3: queued → sent توسط Job notif.dispatch (idempotent)
 *  - N-5: Dedupe (تکرار publish = یک اعلان؛ نسل جدید پس از cancel)
 *  - §5: لغو نوبت → Cancel اعلان‌های queued مرتبط (apt:{id})
 *  - FR-20.6: appt.reminder (شب قبل/صبح — Dedupe دو-لایه + Quiet Hours)
 *  - fu.reminder روی suggested_date-1d با reminder_sent_at (idempotent)
 *  - G6: GET /notifications (نقش خود) + read/unread
 *  - R2: GET /rt/notifications (ETag/304 + badge)
 *  - APPT.confirm/cancel → SMS + Internal بیمار (کاتالوگ §3)
 */
final class NotificationFlowTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $clinicianId;
    private int $patientId;
    private int $doctorUserId;
    private int $secretaryUserId;
    private int $secretary2UserId;
    private int $patientUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('booking.hold_ttl_sec', 600);
        App::settings()->set('booking.cancel_deadline_hours', 24);

        $this->secretaryUserId = $this->makeUser('nf_sec', 'cpms_secretary');
        $this->secretary2UserId = $this->makeUser('nf_sec2', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('nf_doc', 'cpms_doctor');

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Notify',
                $this->doctorUserId,
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-NF-' . $seq,
                'Notify',
                'P' . $seq,
                '0914' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $this->patientUserId = $this->makeUser('nf_pat', 'cpms_patient');
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (1, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->patientId,
                $this->patientUserId,
                '09140000001',
                $now
            )
        );
    }

    // ================= QUEUE.called (N-1/N-2) =================

    public function testCallEnqueuesInternalNotificationForSecretariesNotActor(): void
    {
        $visitId = $this->makeWaitingVisit();

        App::visitService()->transition($this->doctorUserId, $visitId, 'call', ['room' => 'اتاق ۱']);

        // N-2: در Request فقط INSERT queued — نه ارسال
        $rows = $this->notifRows('queue_called');
        $this->assertCount(2, $rows, 'هر دو منشی اعلان می‌گیرند');
        foreach ($rows as $row) {
            $this->assertSame('internal', $row['channel']);
            $this->assertSame('queued', $row['status']);
            $this->assertNotSame($this->doctorUserId, (int) $row['recipient_wp_user_id'], 'فراخواننده اعلان نمی‌گیرد');
        }
        $recipients = array_map(static fn (array $r): int => (int) $r['recipient_wp_user_id'], $rows);
        $this->assertEqualsCanonicalizing([$this->secretaryUserId, $this->secretary2UserId], $recipients);

        // Payload: نام بیمار + اتاق (بدون PHI اضافی)
        $payload = json_decode((string) $rows[0]['payload_json'], true);
        $this->assertSame('فراخوان بیمار', $payload['title']);
        $this->assertStringContainsString('Notify', $payload['body']);
        $this->assertStringContainsString('اتاق ۱', $payload['body']);
    }

    public function testRecallThenCallCreatesNewGenerationButNoDuplicateWithinGeneration(): void
    {
        $visitId = $this->makeWaitingVisit();

        App::visitService()->transition($this->doctorUserId, $visitId, 'call');   // نسل r0
        App::visitService()->transition($this->doctorUserId, $visitId, 'recall'); // بازگشت به صف (recall_count=1)
        App::visitService()->transition($this->doctorUserId, $visitId, 'call');   // نسل r1

        $rows = $this->notifRows('queue_called');
        $this->assertCount(4, $rows, '۲ منشی × ۲ نسل فراخوان (r0, r1)');
        $dedupes = array_map(static fn (array $r): string => (string) $r['dedupe_key'], $rows);
        $this->assertCount(4, array_unique($dedupes), 'dedupe_key یکتا per منشی per نسل');
    }

    // ================= QUEUE.ready_payment =================

    public function testInvoiceReadyNotifiesOtherSecretaries(): void
    {
        $visitId = $this->makeConsultationCompletedVisit();

        // فاکتورسازی توسط منشی ۱ → اعلان به منشی ۲ (فراخواننده مستثنی)
        App::visitService()->transition($this->secretaryUserId, $visitId, 'invoice_ready');

        $rows = $this->notifRows('queue_ready_payment');
        $this->assertCount(1, $rows);
        $this->assertSame($this->secretary2UserId, (int) $rows[0]['recipient_wp_user_id']);
    }

    // ================= N-3 — notif.dispatch =================

    public function testDispatchJobFlipsQueuedToSentIdempotently(): void
    {
        $visitId = $this->makeWaitingVisit();
        App::visitService()->transition($this->doctorUserId, $visitId, 'call');

        $handler = new NotifDispatchHandler(App::notificationService(), App::exportService());
        $sent = $handler([]);
        $this->assertSame(2, $sent);

        $this->assertSame(0, $this->countRows('cpms_notifications', "template = 'queue_called' AND status = 'queued'"));
        $this->assertSame(2, $this->countRows('cpms_notifications', "template = 'queue_called' AND status = 'sent'"));

        // J-2: اجرای تکراری = بی‌اثر
        $this->assertSame(0, $handler([]));
    }

    // ================= G6 + read/unread + R2 =================

    public function testInboxMarkReadAndRtNotifications(): void
    {
        $visitId = $this->makeWaitingVisit();
        App::visitService()->transition($this->doctorUserId, $visitId, 'call');
        (new NotifDispatchHandler(App::notificationService(), App::exportService()))([]);

        wp_set_current_user($this->secretaryUserId);

        // G6 — Inbox نقش خود
        $res = $this->dispatch('GET', self::NS . '/notifications');
        $this->assertSame(200, $res->get_status());
        $data = $this->payload($res);
        $this->assertCount(1, $data['notifications']);
        $this->assertSame(1, $data['unread_count']);
        $this->assertSame('sent', $data['notifications'][0]['status']);
        $this->assertNull($data['notifications'][0]['read_at']);

        // R2 — Real-time badge + ETag
        $rt = $this->dispatch('GET', self::NS . '/rt/notifications');
        $rtData = $this->payload($rt);
        $this->assertSame(1, $rtData['unread_count']);
        $lastId = (int) $rtData['last_id'];
        $etag = $rt->get_headers()['ETag'] ?? '';
        $this->assertNotEquals('', $etag);

        $notModified = $this->dispatch('GET', self::NS . '/rt/notifications', [], ['If-None-Match' => $etag]);
        $this->assertSame(304, $notModified->get_status());

        // since → رویداد جدید نیست
        $rt2 = $this->dispatch('GET', self::NS . '/rt/notifications?since=' . $lastId);
        $rt2Data = $this->payload($rt2);
        $this->assertSame([], $rt2Data['notifications']);

        // علامت‌گذاری خوانده‌شده — فقط رکوردهای خود گیرنده
        $read = $this->dispatch('POST', self::NS . '/notifications/read', ['ids' => [$lastId]]);
        $this->assertSame(1, $this->payload($read)['marked']);

        $after = $this->dispatch('GET', self::NS . '/notifications');
        $afterData = $this->payload($after);
        $this->assertSame(0, $afterData['unread_count']);
        $this->assertNotNull($afterData['notifications'][0]['read_at']);

        // Inbox منشی دوم همچنان خوانده‌نشده (جداسازی گیرنده — G6 «نقش خود»)
        wp_set_current_user($this->secretary2UserId);
        $other = $this->dispatch('GET', self::NS . '/notifications');
        $this->assertSame(1, $this->payload($other)['unread_count']);
    }

    public function testPatientInboxShowsAppointmentNotificationsWithJalali(): void
    {
        $slot = $this->makeSlot(3, '10:00');
        $hold = App::bookingService()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $appt = App::bookingService()->confirm($hold['hold_token'], $this->patientUserId, 'ویزیت', $this->uuid());
        $apptId = (int) $appt['appointment_id'];

        // APPT.confirmed = SMS + Internal (کاتالوگ §3)
        $this->assertSame(1, $this->countRows('cpms_notifications', "recipient_patient_id = {$this->patientId} AND template = 'appt_confirmed'"));
        $this->assertSame(1, $this->countRows('cpms_sms_messages', "event = 'appointment_confirmed' AND context_type = 'appointment' AND context_id = {$apptId}"));

        wp_set_current_user($this->patientUserId);
        $res = $this->dispatch('GET', self::NS . '/notifications');
        $data = $this->payload($res);
        $this->assertCount(1, $data['notifications']);
        $this->assertSame('نوبت تأیید شد', $data['notifications'][0]['title']);
        // N-6: تاریخ Jalali در لایه Template
        $this->assertStringContainsString(Jalali::formatYmd($slot['date']), $data['notifications'][0]['body']);
    }

    // ================= §5 — لغو نوبت → Cancel queued =================

    public function testCancelAppointmentCancelsQueuedRelatedNotifications(): void
    {
        $slot = $this->makeSlot(3, '10:00');
        $hold = App::bookingService()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $appt = App::bookingService()->confirm($hold['hold_token'], $this->patientUserId, 'ویزیت', $this->uuid());
        $apptId = (int) $appt['appointment_id'];

        // یادآوری queued (شب قبل) برای همین نوبت — مانند Job
        App::notificationService()->publishToPatient(
            $this->patientId,
            'appt_reminder',
            $this->apptVars($slot['date']),
            'apt:' . $apptId . ':remind:eve'
        );
        $this->assertSame(2, $this->countRows('cpms_notifications', "status = 'queued' AND dedupe_key LIKE 'apt:{$apptId}:%'"));

        // لغو توسط بیمار (خارج Deadline)
        App::bookingService()->cancelByPatient($this->patientUserId, $apptId, 'تغییر برنامه');

        // هر دو اعلان queued قبل از لغو (تأیید + یادآوری شب قبل) → cancelled (§5)
        $this->assertSame(1, $this->countRows('cpms_notifications', "status = 'cancelled' AND dedupe_key = 'apt:{$apptId}:appointment_confirmed:p{$this->patientId}'"));
        $this->assertSame(1, $this->countRows('cpms_notifications', "status = 'cancelled' AND dedupe_key = 'apt:{$apptId}:remind:eve'"));

        // اعلان «لغو شد» خودِ بیمار (بعد از Cancel ساخته شده) → queued می‌ماند
        $this->assertSame(1, $this->countRows('cpms_notifications', "status = 'queued' AND template = 'appt_cancelled' AND recipient_patient_id = {$this->patientId}"));

        // N-5 نسل جدید: بعد از cancel، publish با همان کلید → اعلان جدید (نه skip)
        $newId = App::notificationService()->publishToPatient(
            $this->patientId,
            'appt_reminder',
            $this->apptVars($slot['date']),
            'apt:' . $apptId . ':remind:eve'
        );
        $this->assertNotNull($newId);
        $this->assertSame(1, $this->countRows('cpms_notifications', "id = " . (int) $newId . " AND status = 'queued'"));
    }

    // ================= FR-20.6 — appt.reminder =================

    public function testApptReminderJobSendsSmsAndInternalWithDedupe(): void
    {
        $tomorrow = $this->localTomorrow();
        $apptId = $this->makeConfirmedAppointment($tomorrow, '10:00');
        $this->openQuietHours();

        $handler = new ApptReminderHandler(
            App::db(),
            App::settings(),
            App::smsService(),
            App::notificationService(),
            App::op()
        );

        $this->assertSame(1, $handler([]));

        // SMS (در Quiet Hours باز) + Internal بیمار — فاز eve
        $this->assertSame(1, $this->countRows('cpms_sms_messages', "event = 'appointment_reminder' AND context_id = {$apptId}"));
        $this->assertSame(1, $this->countRows('cpms_notifications', "template = 'appt_reminder' AND recipient_patient_id = {$this->patientId}"));

        // J-2: تکرار در همان روز = بدون ارسال جدید (Dedupe دو-لایه)
        $this->assertSame(0, $handler([]));
        $this->assertSame(1, $this->countRows('cpms_sms_messages', "event = 'appointment_reminder' AND context_id = {$apptId}"));
        $this->assertSame(1, $this->countRows('cpms_notifications', "template = 'appt_reminder' AND recipient_patient_id = {$this->patientId}"));
    }

    public function testApptReminderRespectsQuietHoursForSmsOnly(): void
    {
        $tomorrow = $this->localTomorrow();
        $apptId = $this->makeConfirmedAppointment($tomorrow, '11:00');
        $this->closeQuietHours();

        $handler = new ApptReminderHandler(
            App::db(),
            App::settings(),
            App::smsService(),
            App::notificationService(),
            App::op()
        );
        $this->assertSame(1, $handler([]));

        // SMS ارسال نشد (خارج بازه) — اعلان Internal رفت
        $this->assertSame(0, $this->countRows('cpms_sms_messages', "event = 'appointment_reminder' AND context_id = {$apptId}"));
        $this->assertSame(1, $this->countRows('cpms_notifications', "template = 'appt_reminder' AND recipient_patient_id = {$this->patientId}"));

        // داخل بازه که باز شد → SMS می‌رود؛ Internal تکراری نمی‌شود (Dedupe)
        $this->openQuietHours();
        $handler([]);
        $this->assertSame(1, $this->countRows('cpms_sms_messages', "event = 'appointment_reminder' AND context_id = {$apptId}"));
        $this->assertSame(1, $this->countRows('cpms_notifications', "template = 'appt_reminder' AND recipient_patient_id = {$this->patientId}"));
    }

    // ================= fu.reminder =================

    public function testFollowUpReminderJobMarksReminderSentAt(): void
    {
        $visitId = $this->makeConsultationCompletedVisit();
        $fuId = $this->makeFollowUp($visitId, $this->localTomorrow());
        $this->openQuietHours();

        $handler = new FollowUpReminderHandler(
            App::db(),
            App::settings(),
            App::smsService(),
            App::notificationService(),
            App::op()
        );

        $this->assertSame(1, $handler([]));
        $this->assertSame(1, $this->countRows('cpms_sms_messages', "event = 'follow_up_reminder' AND context_id = {$fuId}"));
        $this->assertSame(1, $this->countRows('cpms_notifications', "template = 'followup_reminder' AND recipient_patient_id = {$this->patientId}"));
        $this->assertNotNull($this->scalar("SELECT reminder_sent_at FROM {cpms_follow_ups} WHERE id = {$fuId}"));

        // Idempotency: reminder_sent_at ست شده → تیک بعدی بی‌اثر
        $this->assertSame(0, $handler([]));
    }

    // ================= Recurring scheduling (J-2) =================

    public function testF8JobsAreScheduledRecurring(): void
    {
        App::scheduleRecurringJobs();

        foreach (['notif.dispatch', 'appt.reminder', 'fu.reminder'] as $type) {
            $queued = (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . ' WHERE type = %s AND status = %s',
                [$type, JobQueue::QUEUED]
            );
            $this->assertSame(1, $queued, "job {$type} باید recurring باشد");
        }
    }

    // ================= Helpers =================

    /**
     * @return list<array<string, mixed>>
     */
    private function notifRows(string $template): array
    {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'cpms_notifications WHERE template = %s ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $template
            ),
            ARRAY_A
        );
    }

    private function countRows(string $shortTable, string $where): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . $shortTable . ' WHERE ' . $where // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }

    private function scalar(string $sqlWithBraceTable): mixed
    {
        global $wpdb;
        $sql = preg_replace('/\{([a-z_]+)\}/', $wpdb->prefix . '$1', $sqlWithBraceTable);

        return $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Response $res): array
    {
        $body = $res->get_data();
        if (is_array($body) && array_key_exists('data', $body)) {
            return is_array($body['data']) ? $body['data'] : [];
        }

        return is_array($body) ? $body : [];
    }

    /**
     * @return array<string, string>
     */
    private function apptVars(string $date): array
    {
        return [
            'patient_name' => 'Notify',
            'doctor_name' => 'Dr Notify',
            'appointment_date' => Jalali::formatYmd($date),
            'appointment_time' => '10:00',
            'clinic_name' => 'مطب',
        ];
    }

    private function makeWaitingVisit(): int
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);

        return (int) $visit['id'];
    }

    private function makeConsultationCompletedVisit(): int
    {
        $visitId = $this->makeWaitingVisit();
        App::visitService()->transition($this->doctorUserId, $visitId, 'call');
        App::visitService()->transition($this->doctorUserId, $visitId, 'start');
        App::visitService()->transition($this->doctorUserId, $visitId, 'complete');

        return $visitId;
    }

    private function makeFollowUp(int $visitId, string $suggestedDate): int
    {
        global $wpdb;
        $visit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visitId]
        );
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups
                     (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at)
                 VALUES (1, %d, %d, %d, 1, %s, 30, %s, "pending", %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $visitId,
                (int) $visit['patient_id'],
                (int) $visit['clinician_id'],
                $suggestedDate,
                'پیگیری درمان',
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function makeSlot(int $dayOffset, string $time): array
    {
        global $wpdb;
        $date = gmdate('Y-m-d', time() + $dayOffset * 86400);
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianId,
                $date,
                $time,
                $now,
                $now
            )
        );

        return ['id' => (int) $wpdb->insert_id, 'date' => $date, 'time' => $time];
    }

    /**
     * نوبت confirmed با slot_date دقیقاً $date (بدون UPDATE — Slot مستقیم).
     */
    private function makeConfirmedAppointment(string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianId,
                $date,
                $time,
                $now,
                $now
            )
        );

        $hold = App::bookingService()->hold($this->patientUserId, $this->clinicianId, $date, $time);
        $appt = App::bookingService()->confirm($hold['hold_token'], $this->patientUserId, 'ویزیت', $this->uuid());

        return (int) $appt['appointment_id'];
    }

    private function localTomorrow(): string
    {
        $tz = new \DateTimeZone(App::settings()->clinicTimezone());

        return (new \DateTimeImmutable('now', $tz))->modify('+1 day')->format('Y-m-d');
    }

    /** Quiet Hours باز برای ساعت جاری مطب (تست Deterministic در هر ساعت UTC). */
    private function openQuietHours(): void
    {
        $h = $this->localHour();
        App::settings()->set('notif.quiet_hours_start', sprintf('%02d:00', $h));
        App::settings()->set('notif.quiet_hours_end', sprintf('%02d:00', ($h + 1) % 24));
    }

    /** Quiet Hours بسته برای ساعت جاری مطب (بازه [h+1, h+2) — شامل حالت wrap). */
    private function closeQuietHours(): void
    {
        $h = $this->localHour();
        App::settings()->set('notif.quiet_hours_start', sprintf('%02d:00', ($h + 1) % 24));
        App::settings()->set('notif.quiet_hours_end', sprintf('%02d:00', ($h + 2) % 24));
    }

    private function localHour(): int
    {
        $tz = new \DateTimeZone(App::settings()->clinicTimezone());

        return (int) (new \DateTimeImmutable('now', $tz))->format('G');
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function uuid(): string
    {
        $d = static fn (int $len): string => bin2hex(random_bytes((int) ceil($len / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $d(8), $d(4), substr($d(3), 0, 3), substr($d(4), 0, 4), $d(12));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $body = [], array $headers = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        return rest_do_request($request);
    }
}
