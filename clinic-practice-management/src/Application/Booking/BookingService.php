<?php

declare(strict_types=1);

namespace ClinicCore\Application\Booking;

use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Booking\BookingWindow;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Machine\AppointmentMachine;
use ClinicCore\Domain\Machine\InvalidTransitionException;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Slots\DurationResolver;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use ClinicCore\Infrastructure\Security\Idempotency;
use ClinicCore\Settings\Settings;
use Throwable;

/**
 * سرویس نوبت‌دهی (F3) — قلب Concurrency/Idempotency پروژه.
 *
 * تضمین‌ها (مطابق تصمیمات کارفرما F3):
 *  - **DB-level Double-Booking**: Claim/Book با Conditional UPDATE (شمارنده‌ها) +
 *    Row Lock (FOR UPDATE) در Transaction — تضمین TP-03 در لایه DB (ADR-0004).
 *  - **Idempotency**: `Idempotency-Key` روی confirm/reschedule — Replay = پاسخ Origin.
 *  - **Duration Snapshot**: `appointments.duration_min/slot_end_time` از Slot کپی می‌شود؛
 *    تغییر Default بعدی هیچ نوبت موجودی را تغییر نمی‌دهد (ADR-0017, TP-21).
 *  - **LicenseGate**: هر عملیات Protected از Seam مرکزی — **بدون هیچ Network Call**
 *    مستقیم به License Server (ADR-0023, C3).
 *  - **Audit/OpLog**: Audit = Hash-Chain محافظت‌شده (ADR-0008)؛ OpLog/خطا = Masked
 *    (بدون PHI خام در Log عملیاتی یا پاسخ خطا).
 *  - زمان‌بندی: همه مقادیر UTC در DB (ADR-0013)؛ نمایش Jalali فقط در View (ADR-0013).
 */
final class BookingService
{
    private const ACTIVE_STATUSES = ['pending', 'confirmed'];
    private const EP_CONFIRM = 'booking/confirm';
    private const EP_RESCHEDULE = 'booking/reschedule';

    public function __construct(
        private readonly CpmsDb $db,
        private readonly SlotRepository $slots,
        private readonly AppointmentRepository $appointments,
        private readonly PatientRepository $patients,
        private readonly Settings $settings,
        private readonly LicenseGate $licenseGate,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op,
        private readonly Idempotency $idem,
        private readonly SmsService $sms
    ) {
    }

    // ================= A1 — Availability (Public) =================

    /**
     * تقویم آزاد (Jalali UI) — `{days:[{date, jalali, slots:[{time, capacity_left, duration_min}]}]}`.
     *
     * @return array{days: list<array<string, mixed>>}
     */
    public function availability(int $clinicianId, string $fromDate, string $toDate): array
    {
        $this->requireClinician($clinicianId);

        $from = $this->parseYmd($fromDate, 'from');
        $to = $this->parseYmd($toDate, 'to');
        $today = gmdate('Y-m-d');
        if ($from < $today) {
            $from = $today;
        }
        $spanDays = (int) (($this->ts($to) - $this->ts($from)) / 86400) + 1;
        if ($spanDays < 1 || $spanDays > (int) $this->settings->get('booking.max_future_days', 60) + 2) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'بازه تاریخ نامعتبر است (حداکثر ۶۰ روز)');
        }

        $rows = $this->slots->availability(1, $clinicianId, $from, $to, $today, gmdate('H:i:s'));

        $grouped = [];
        foreach ($rows as $row) {
            $date = (string) $row['slot_date'];
            $grouped[$date][] = [
                'time' => substr((string) $row['slot_time'], 0, 5),
                'capacity_left' => (int) $row['capacity_left'],
                'duration_min' => (int) $row['duration_min'],
            ];
        }

        $days = [];
        foreach ($grouped as $date => $slots) {
            $days[] = [
                'date' => $date,
                'jalali' => Jalali::formatYmd($date),
                'slots' => $slots,
            ];
        }

        return ['days' => $days];
    }

    private function parseYmd(string $value, string $label): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', "{$label} باید به فرمت YYYY-MM-DD باشد");
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', "{$label} نامعتبر است");
        }

        return $value;
    }

    private function ts(string $ymd): int
    {
        return (new \DateTimeImmutable($ymd, new \DateTimeZone('UTC')))->getTimestamp();
    }

    // ================= A4 — Quote (Public) =================

    /**
     * @return array{available: bool, capacity_left: int}
     */
    public function quote(int $clinicianId, string $slotDate, string $slotTime): array
    {
        $this->requireClinician($clinicianId);
        $this->assertWindow($slotDate, $slotTime, (int) $this->settings->get('booking.min_lead_hours', 2));

        $slot = $this->slots->findByClinicianSlot(1, $clinicianId, $slotDate, $slotTime);
        if ($slot === null || (int) $slot['is_open'] !== 1) {
            return ['available' => false, 'capacity_left' => 0];
        }

        $left = (int) $slot['capacity'] - (int) $slot['booked_count'] - (int) $slot['held_count'];

        return ['available' => $left > 0, 'capacity_left' => max(0, $left)];
    }

    // ================= B1 — Hold =================

    /**
     * @return array{hold_token: string, expires_at: string, slot: array<string, mixed>}
     */
    public function hold(int $wpUserId, int $clinicianId, string $slotDate, string $slotTime): array
    {
        $this->assertLicense(LicenseGate::OP_APPOINTMENT_BOOK);
        $this->assertWindow($slotDate, $slotTime, (int) $this->settings->get('booking.min_lead_hours', 2));

        $mobile = $this->mobileForUser($wpUserId);
        if ($mobile === null) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'پروفایل شما ناقص است — ابتدا موبایل خود را تأیید کنید (OTP)');
        }

        $slot = $this->slots->findByClinicianSlot(1, $clinicianId, $slotDate, $slotTime);
        if ($slot === null || (int) $slot['is_open'] !== 1) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'اسلات انتخابی یافت نشد', 404);
        }

        // N-4: Hold Active موجود همان بیمار/اسلات → Idempotent (بازگردانی همان Token)
        $existing = $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_slot_holds') .
            ' WHERE holder_wp_user_id = %d AND slot_id = %d AND status = %s AND expires_at > %s LIMIT 1',
            [$wpUserId, (int) $slot['id'], 'active', $this->db->nowUtcSql()]
        );
        if ($existing !== null) {
            return [
                'hold_token' => (string) $existing['token'],
                'expires_at' => (string) $existing['expires_at'],
                'slot' => $this->slotView((int) $slot['id'], $slot),
            ];
        }

        $ttl = (int) $this->settings->get('booking.hold_ttl_sec', 600);
        $token = self::uuid4();
        $expiresAt = (new \DateTimeImmutable('+ ' . $ttl . ' seconds', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.000');

        $this->db->transactional(function () use ($slot, $wpUserId, $mobile, $token, $expiresAt): void {
            if (!$this->slots->atomicHold((int) $slot['id'])) {
                throw BookingException::of('CLINIC_SLOT_TAKEN', 'اسلات در لحظه انتخاب پر شد — اسلات دیگری انتخاب کنید', 409);
            }
            $this->db->insert('cpms_slot_holds', [
                'clinic_id' => 1,
                'slot_id' => (int) $slot['id'],
                'holder_wp_user_id' => $wpUserId,
                'holder_mobile' => $mobile,
                'token' => $token,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'created_at' => $this->db->nowUtcSql(),
            ]);
        });

        $holdId = $this->db->wpdb_last_insert_id();
        $this->audit('HOLD_CREATED', $wpUserId, 'patient', 'slot_hold', $holdId, null, null, [
            'mobile' => MobileValidator::mask($mobile),
            'slot_id' => (int) $slot['id'],
            'slot_date' => $slotDate,
            'slot_time' => $slotTime,
        ]);
        $this->op->info('booking.hold', [
            'wp_user_id' => $wpUserId,
            'slot_id' => (int) $slot['id'],
            'hold_id' => $holdId,
        ]);

        return [
            'hold_token' => $token,
            'expires_at' => $expiresAt,
            'slot' => $this->slotView((int) $slot['id'], $slot),
        ];
    }

    // ================= B2 — Confirm (Idempotent) =================

    /**
     * @return array{reference_code: string, appointment_id: int, slot: array<string, mixed>, status: string}
     */
    public function confirm(string $holdToken, int $wpUserId, ?string $reason, ?string $idemKey): array
    {
        if (!is_string($idemKey) || $idemKey === '') {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'هدر Idempotency-Key برای این عملیات الزامی است');
        }

        $check = $this->idem->check($idemKey, self::EP_CONFIRM, $wpUserId, null);
        if ($check['is_replay']) {
            return $this->replayOrInFlight($check, $wpUserId);
        }

        $now = $this->now();
        $nowSql = $this->db->nowUtcSql();

        $hold = $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_slot_holds') . ' WHERE token = %s LIMIT 1',
            [$holdToken]
        );
        if ($hold === null) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'جلسه رزرو یافت نشد', 404);
        }
        if ((int) $hold['holder_wp_user_id'] !== $wpUserId) {
            $this->audit('FORBIDDEN_ACCESS_ATTEMPT', $wpUserId, 'patient', 'slot_hold', (int) $hold['id'], null, null, null, [
                'mobile' => MobileValidator::mask((string) ($hold['holder_mobile'] ?? '')),
            ]);
            throw BookingException::of('CLINIC_PERMISSION_DENIED', 'به این جلسه رزرو دسترسی ندارید', 403);
        }

        if ((string) $hold['status'] !== 'active') {
            if ((string) $hold['status'] === 'converted') {
                // با کلید متفاوت تکرار → پاسخ همان Appointment
                $appt = $this->findActiveByUserSlot($wpUserId, (int) $hold['slot_id']);
                if ($appt !== null) {
                    return $this->appointmentView($appt);
                }
            }
            throw BookingException::of('CLINIC_HOLD_EXPIRED', 'جلسه رزرو منقضی/بسته شده — دوباره Slot انتخاب کنید', 422);
        }
        if ($this->toDateTime((string) $hold['expires_at']) <= $now) {
            $this->db->query(
                'UPDATE ' . $this->db->table('cpms_slot_holds') . " SET status = 'expired' WHERE id = %d AND status = 'active' AND expires_at <= %s",
                [(int) $hold['id'], $nowSql]
            );
            $this->slots->releaseHold((int) $hold['slot_id']);
            throw BookingException::of('CLINIC_HOLD_EXPIRED', 'مهلت رزرو تمام شده — دوباره Slot انتخاب کنید', 422);
        }

        $this->assertLicense(LicenseGate::OP_APPOINTMENT_BOOK);

        $mobile = (string) $hold['holder_mobile'];
        $patient = $this->patients->findByMobile(1, $mobile);
        if ($patient === null) {
            // N-1: کاربر جدید (OTP verified) — Patient Record Minimal در زمان confirm ساخته می‌شود
            $patient = $this->createMinimalPatient($mobile, $wpUserId);
        }
        $patientId = (int) $patient['id'];
        $slotId = (int) $hold['slot_id'];
        $holdId = (int) $hold['id'];

        try {
            [$apptId, $appt, $slot] = $this->db->transactional(function () use (
                $holdId, $slotId, $patientId, $wpUserId, $reason
            ): array {
                $slot = $this->slots->findForUpdate($slotId);
                if ($slot === null || (int) $slot['is_open'] !== 1) {
                    throw BookingException::of('CLINIC_SLOT_TAKEN', 'اسلات دیگر در دسترس نیست', 409);
                }
                $dup = $this->appointments->findActiveForPatientSlot($patientId, $slotId, self::ACTIVE_STATUSES);
                if ($dup !== null) {
                    throw BookingException::of('CLINIC_DUPLICATE_APPOINTMENT', 'شما قبلاً در این ساعت نوبت دارید', 409);
                }
                if (!$this->slots->atomicClaim($slotId)) {
                    throw BookingException::of('CLINIC_SLOT_TAKEN', 'اسلات در لحظه نهایی پر شد', 409);
                }

                $duration = (int) $slot['duration_min'];
                $endTime = DurationResolver::slotEndTime((string) $slot['slot_time'], $duration);
                $ref = $this->referenceCode((string) $slot['slot_date']);
                $nowSql = $this->db->nowUtcSql();

                $apptId = $this->appointments->create([
                    'clinic_id' => 1,
                    'reference_code' => $ref,
                    'clinician_id' => (int) $slot['clinician_id'],
                    'patient_id' => $patientId,
                    'slot_id' => $slotId,
                    'slot_date' => (string) $slot['slot_date'],
                    'slot_time' => (string) $slot['slot_time'],
                    'duration_min' => $duration,        // Snapshot (ADR-0017)
                    'slot_end_time' => $endTime,        // Snapshot
                    'wp_user_id' => $wpUserId,
                    'reason' => is_string($reason) && $reason !== '' ? mb_substr($reason, 0, 255) : null,
                    'status' => 'confirmed',
                    'is_walkin_express' => 0,
                    'booked_at' => $nowSql,
                    'confirmed_at' => $nowSql,
                    'created_at' => $nowSql,
                    'updated_at' => $nowSql,
                ]);

                $this->machineCheck('new', 'book_final', 'patient');

                $this->db->query(
                    'UPDATE ' . $this->db->table('cpms_slot_holds') . " SET status = 'converted' WHERE id = %d AND status = 'active'",
                    [$holdId]
                );

                $appt = $this->appointments->find($apptId);

                return [$apptId, $appt, $slot];
            });
        } catch (Throwable $e) {
            // آزادسازی ظرفیت Hold + کلید Idempotency (خارج از Transaction رول‌بک‌شده)
            $this->db->query(
                'UPDATE ' . $this->db->table('cpms_slot_holds') . " SET status = 'released' WHERE id = %d AND status = 'active'",
                [$holdId]
            );
            $this->slots->releaseHold($slotId);
            $this->idem->release($idemKey, self::EP_CONFIRM, $wpUserId, null);
            throw $this->toBookingException($e);
        }

        $view = $this->appointmentView($appt);
        $this->idem->complete($idemKey, self::EP_CONFIRM, $wpUserId, null, 200, $view);

        $this->audit('APPOINTMENT_CREATED', $wpUserId, 'patient', 'appointment', $apptId, $patientId, null, $view, [
            'mobile' => MobileValidator::mask($mobile),
        ]);
        $this->op->info('booking.confirmed', ['appointment_id' => $apptId, 'slot_id' => $slotId, 'wp_user_id' => $wpUserId]);

        $this->sendAppointmentSms(SmsEvents::APPT_CONFIRMED, $mobile, $appt);

        return $view;
    }

    // ================= B6 — Resume =================

    /**
     * @return array{hold_token: string, status: string, expires_at?: string, slot?: array<string, mixed>}
     */
    public function resume(string $holdToken, int $wpUserId): array
    {
        $hold = $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_slot_holds') . ' WHERE token = %s LIMIT 1',
            [$holdToken]
        );
        if ($hold === null || (int) $hold['holder_wp_user_id'] !== $wpUserId) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'جلسه رزرو یافت نشد', 404);
        }

        if ((string) $hold['status'] === 'converted') {
            return ['hold_token' => $holdToken, 'status' => 'converted'];
        }
        if ((string) $hold['status'] !== 'active' || $this->toDateTime((string) $hold['expires_at']) <= $this->now()) {
            $this->db->query(
                'UPDATE ' . $this->db->table('cpms_slot_holds') . " SET status = 'expired' WHERE id = %d AND status = 'active'",
                [(int) $hold['id']]
            );
            $this->slots->releaseHold((int) $hold['slot_id']);

            throw BookingException::of('CLINIC_HOLD_EXPIRED', 'جلسه رزرو منقضی شده — دوباره Slot انتخاب کنید', 422);
        }

        $slot = $this->slots->find((int) $hold['slot_id']);

        return [
            'hold_token' => $holdToken,
            'status' => 'active',
            'expires_at' => (string) $hold['expires_at'],
            'slot' => $slot !== null ? $this->slotView((int) $slot['id'], $slot) : null,
        ];
    }

    // ================= B3 — Mine =================

    /**
     * @return list<array<string, mixed>>
     */
    public function listMine(int $wpUserId, string $fromDate, string $toDate): array
    {
        $patientIds = $this->patientIdsForUser($wpUserId);
        if ($patientIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($patientIds), '%d'));
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_appointments') .
            " WHERE patient_id IN ({$placeholders}) AND slot_date BETWEEN %s AND %s ORDER BY slot_date, slot_time",
            array_merge($patientIds, [$fromDate, $toDate])
        );

        return $this->mapAppointmentViews($rows);
    }

    // ================= B4 — Cancel (Patient) =================

    /**
     * @return array{appointment_id: int, status: string}
     */
    public function cancelByPatient(int $wpUserId, int $appointmentId, ?string $reason): array
    {
        return $this->cancel($wpUserId, $appointmentId, $reason, 'patient');
    }

    // ================= B5 — Reschedule (Patient) =================

    /**
     * @return array{appointment_id: int, reference_code: string, slot: array<string, mixed>, status: string, previous_appointment_id: int}
     */
    public function reschedule(int $wpUserId, int $appointmentId, int $newClinicianId, string $newDate, string $newTime, ?string $idemKey): array
    {
        if (!is_string($idemKey) || $idemKey === '') {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'هدر Idempotency-Key برای این عملیات الزامی است');
        }

        $check = $this->idem->check($idemKey, self::EP_RESCHEDULE, $wpUserId, $appointmentId);
        if ($check['is_replay']) {
            return $this->replayOrInFlight($check, $wpUserId);
        }

        $this->assertLicense(LicenseGate::OP_APPOINTMENT_RESCHEDULE);

        try {
            [$oldAppt, $newApptId, $newSlot] = $this->db->transactional(function () use (
                $wpUserId, $appointmentId, $newClinicianId, $newDate, $newTime
            ): array {
                $appt = $this->appointments->findForUpdate($appointmentId);
                if ($appt === null) {
                    throw BookingException::of('CLINIC_NOT_FOUND', 'نوبت یافت نشد', 404);
                }
                if (!$this->userHasPatient($wpUserId, (int) $appt['patient_id'])) {
                    $this->audit('FORBIDDEN_ACCESS_ATTEMPT', $wpUserId, 'patient', 'appointment', $appointmentId, (int) $appt['patient_id'], null, null, [
                        'mobile' => MobileValidator::mask((string) ($appt['patient_mobile'] ?? '')),
                    ]);
                    throw BookingException::of('CLINIC_PERMISSION_DENIED', 'به این نوبت دسترسی ندارید', 403);
                }

                $toState = $this->machineCheck((string) $appt['status'], 'reschedule', 'patient');

                // Policy نوبت فعلی (حداقل X ساعت قبل — SRS FR-4.10)
                $err = BookingWindow::checkCancel(
                    (string) $appt['slot_date'],
                    (string) $appt['slot_time'],
                    $this->now(),
                    (int) $this->settings->get('booking.reschedule_deadline_hours', 24)
                );
                if ($err !== null) {
                    throw BookingException::of('CLINIC_POLICY_VIOLATION', 'زمان جابه‌جایی این نوبت گذشته است (سیاست مطب)', 409);
                }
                // Window اسلات جدید
                $this->assertWindow($newDate, $newTime, (int) $this->settings->get('booking.min_lead_hours', 2));

                $oldSlotId = (int) $appt['slot_id'];
                $newSlot = $this->slots->findByClinicianSlot(1, $newClinicianId, $newDate, $newTime);
                if ($newSlot === null || (int) $newSlot['is_open'] !== 1) {
                    throw BookingException::of('CLINIC_NOT_FOUND', 'اسلات مقصد یافت نشد', 404);
                }
                $newSlotId = (int) $newSlot['id'];

                // قفل هر دو اسلات — مرتب بر اساس id (پیشگیری از Deadlock)
                [$first, $second] = $oldSlotId < $newSlotId ? [$oldSlotId, $newSlotId] : [$newSlotId, $oldSlotId];
                $this->slots->findForUpdate($first);
                $this->slots->findForUpdate($second);

                $dup = $this->appointments->findActiveForPatientSlot((int) $appt['patient_id'], $newSlotId, self::ACTIVE_STATUSES);
                if ($dup !== null && (int) $dup['id'] !== $appointmentId) {
                    throw BookingException::of('CLINIC_DUPLICATE_APPOINTMENT', 'در اسلات مقصد نوبت Active دارید', 409);
                }
                if ((int) $newSlot['capacity'] - (int) $newSlot['booked_count'] - (int) $newSlot['held_count'] <= 0) {
                    throw BookingException::of('CLINIC_SLOT_TAKEN', 'اسلات مقصد پر شد — اسلات دیگری انتخاب کنید', 409);
                }

                $this->slots->releaseBooking($oldSlotId);
                if (!$this->slots->atomicBook($newSlotId)) {
                    throw BookingException::of('CLINIC_SLOT_TAKEN', 'اسلات مقصد در لحظه نهایی پر شد', 409);
                }

                $duration = (int) $newSlot['duration_min'];
                $endTime = DurationResolver::slotEndTime($newTime, $duration);
                $nowSql = $this->db->nowUtcSql();
                $newApptId = $this->appointments->create([
                    'clinic_id' => 1,
                    'reference_code' => $this->referenceCode($newDate),
                    'clinician_id' => $newClinicianId,
                    'patient_id' => (int) $appt['patient_id'],
                    'slot_id' => $newSlotId,
                    'slot_date' => $newDate,
                    'slot_time' => $newTime,
                    'duration_min' => $duration,        // Snapshot اسلات جدید
                    'slot_end_time' => $endTime,
                    'wp_user_id' => $wpUserId,
                    'reason' => (string) $appt['reason'],
                    'status' => 'confirmed',
                    'is_walkin_express' => (int) $appt['is_walkin_express'],
                    'rescheduled_from' => $appointmentId,
                    'booked_at' => $nowSql,
                    'confirmed_at' => $nowSql,
                    'created_at' => $nowSql,
                    'updated_at' => $nowSql,
                ]);

                $this->appointments->updateStatus($appointmentId, [
                    'status' => $toState,
                    'rescheduled_to' => $newApptId,
                    'updated_at' => $nowSql,
                ]);

                return [$appt, $newApptId, $newSlot];
            });
        } catch (Throwable $e) {
            $this->idem->release($idemKey, self::EP_RESCHEDULE, $wpUserId, $appointmentId);
            throw $this->toBookingException($e);
        }

        $newAppt = $this->appointments->find($newApptId);
        $view = $this->appointmentView($newAppt);
        $response = array_merge($view, ['previous_appointment_id' => $appointmentId]);
        $this->idem->complete($idemKey, self::EP_RESCHEDULE, $wpUserId, $appointmentId, 200, $response);

        $this->audit('APPOINTMENT_RESCHEDULED', $wpUserId, 'patient', 'appointment', $newApptId, (int) $oldAppt['patient_id'], null, $view, [
            'from_appointment_id' => $appointmentId,
            'from_date' => (string) $oldAppt['slot_date'],
            'from_time' => (string) $oldAppt['slot_time'],
        ]);
        $this->op->info('booking.rescheduled', ['old' => $appointmentId, 'new' => $newApptId, 'wp_user_id' => $wpUserId]);

        $patient = $this->patients->find((int) $oldAppt['patient_id']);
        if ($patient !== null) {
            $this->sendAppointmentSms(SmsEvents::APPT_RESCHEDULED, (string) $patient['mobile'], $newAppt);
        }

        return $response;
    }

    // ================= D10 — Staff Create =================

    /**
     * @return array<string, mixed>
     */
    public function createByStaff(int $actorUserId, int $patientId, int $clinicianId, string $slotDate, string $slotTime, ?string $reason): array
    {
        $this->assertLicense(LicenseGate::OP_APPOINTMENT_BOOK);

        $patient = $this->patients->find($patientId);
        if ($patient === null || (string) $patient['status'] !== 'active') {
            throw BookingException::of('CLINIC_NOT_FOUND', 'بیمار یافت نشد', 404);
        }
        $this->requireClinician($clinicianId);
        // N-3: Staff (حضوری/فوری) بدون min-lead — فقط Window + افق آینده
        $this->assertWindow($slotDate, $slotTime, 0);

        try {
            [$apptId, $appt, $slot] = $this->db->transactional(function () use (
                $patientId, $clinicianId, $slotDate, $slotTime, $reason, $actorUserId
            ): array {
                $slot = $this->slots->findByClinicianSlot(1, $clinicianId, $slotDate, $slotTime);
                if ($slot === null || (int) $slot['is_open'] !== 1) {
                    throw BookingException::of('CLINIC_NOT_FOUND', 'اسلات یافت نشد — ممکن است Schedule پزشک برای این روز تعریف نشده باشد', 404);
                }
                $slotId = (int) $slot['id'];
                $this->slots->findForUpdate($slotId);

                $dup = $this->appointments->findActiveForPatientSlot($patientId, $slotId, self::ACTIVE_STATUSES);
                if ($dup !== null) {
                    throw BookingException::of('CLINIC_DUPLICATE_APPOINTMENT', 'بیمار در این ساعت نوبت Active دارد', 409);
                }
                if ((int) $slot['capacity'] - (int) $slot['booked_count'] <= 0) {
                    throw BookingException::of('CLINIC_SLOT_TAKEN', 'ظرفیت این اسلات تکمیل است', 409);
                }
                if (!$this->slots->atomicBook($slotId)) {
                    throw BookingException::of('CLINIC_SLOT_TAKEN', 'ظرفیت این اسلات تکمیل شد', 409);
                }

                $duration = (int) $slot['duration_min'];
                $nowSql = $this->db->nowUtcSql();
                $isToday = $slotDate === gmdate('Y-m-d');
                $apptId = $this->appointments->create([
                    'clinic_id' => 1,
                    'reference_code' => $this->referenceCode($slotDate),
                    'clinician_id' => $clinicianId,
                    'patient_id' => $patientId,
                    'slot_id' => $slotId,
                    'slot_date' => $slotDate,
                    'slot_time' => $slotTime,
                    'duration_min' => $duration,
                    'slot_end_time' => DurationResolver::slotEndTime($slotTime, $duration),
                    'reason' => is_string($reason) && $reason !== '' ? mb_substr($reason, 0, 255) : null,
                    'status' => 'confirmed', // N-2: create→pending→confirm در یک operation (مطابه State Machine)
                    'is_walkin_express' => $isToday ? 1 : 0,
                    'booked_at' => $nowSql,
                    'confirmed_at' => $nowSql,
                    'created_at' => $nowSql,
                    'updated_at' => $nowSql,
                ]);

                $this->machineCheck('new', 'create', 'secretary');
                $this->machineCheck('pending', 'confirm', 'secretary');

                $appt = $this->appointments->find($apptId);

                return [$apptId, $appt, $slot];
            });
        } catch (Throwable $e) {
            throw $this->toBookingException($e);
        }

        $view = $this->appointmentView($appt);
        $this->audit('APPOINTMENT_CREATED', $actorUserId, 'staff', 'appointment', $apptId, $patientId, null, $view);
        $this->op->info('booking.staff_created', ['appointment_id' => $apptId, 'actor' => $actorUserId]);

        return $view;
    }

    // ================= D11 — Staff Cancel =================

    /**
     * @return array{appointment_id: int, status: string}
     */
    public function cancelByStaff(int $actorUserId, int $appointmentId, ?string $reason): array
    {
        // منشی: بدون محدودیت Deadline (SRS FR-5.3 — در محدوده مجوز)
        return $this->cancel($actorUserId, $appointmentId, $reason, 'staff');
    }

    // ================= D9 — Staff List =================

    /**
     * @return list<array<string, mixed>>
     */
    public function listForClinician(int $clinicianId, string $date, ?string $status): array
    {
        $this->requireClinician($clinicianId);
        $rows = $this->appointments->listByClinicianDate(1, $clinicianId, $date);
        if ($status !== null && $status !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (string) $r['status'] === $status));
        }

        return $this->mapAppointmentViews($rows);
    }

    // ================= Internal =================

    /**
     * @return array{appointment_id: int, status: string}
     */
    private function cancel(int $wpUserId, int $appointmentId, ?string $reason, string $actor): array
    {
        $this->assertLicense(LicenseGate::OP_APPOINTMENT_CANCEL);

        [$appt, $toState, $slotId] = $this->db->transactional(function () use ($wpUserId, $appointmentId, $reason, $actor): array {
            $appt = $this->appointments->findForUpdate($appointmentId);
            if ($appt === null) {
                throw BookingException::of('CLINIC_NOT_FOUND', 'نوبت یافت نشد', 404);
            }
            if ($actor === 'patient' && !$this->userHasPatient($wpUserId, (int) $appt['patient_id'])) {
                $this->audit('FORBIDDEN_ACCESS_ATTEMPT', $wpUserId, 'patient', 'appointment', $appointmentId, (int) $appt['patient_id'], null, null, [
                    'mobile' => MobileValidator::mask((string) ($appt['patient_mobile'] ?? '')),
                ]);
                throw BookingException::of('CLINIC_PERMISSION_DENIED', 'به این نوبت دسترسی ندارید', 403);
            }

            $toState = $this->machineCheck((string) $appt['status'], 'cancel', $actor);

            if ($actor === 'patient') {
                // SRS FR-4.9: حداقل X ساعت قبل از شروع (Configurable — بدون اثر Retroactive)
                $err = BookingWindow::checkCancel(
                    (string) $appt['slot_date'],
                    (string) $appt['slot_time'],
                    $this->now(),
                    (int) $this->settings->get('booking.cancel_deadline_hours', 24)
                );
                if ($err !== null) {
                    throw BookingException::of('CLINIC_POLICY_VIOLATION', 'زمان لغو این نوبت گذشته است (سیاست مطب)', 409);
                }
            }

            $slotId = (int) $appt['slot_id'];
            $nowSql = $this->db->nowUtcSql();
            $this->appointments->updateStatus($appointmentId, [
                'status' => $toState,
                'cancelled_at' => $nowSql,
                'cancel_reason' => is_string($reason) && $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'cancelled_by_wp_user_id' => $wpUserId,
                'updated_at' => $nowSql,
            ]);
            $this->slots->releaseBooking($slotId);

            return [$appt, $toState, $slotId];
        });

        $this->audit(
            'APPOINTMENT_CANCELLED',
            $wpUserId,
            $actor === 'patient' ? 'patient' : 'staff',
            'appointment',
            $appointmentId,
            (int) $appt['patient_id'],
            ['status' => (string) $appt['status']],
            ['status' => $toState, 'reason' => is_string($reason) ? mb_substr($reason, 0, 255) : null]
        );
        $this->op->info('booking.cancelled', ['appointment_id' => $appointmentId, 'by' => $wpUserId, 'actor' => $actor]);

        if ((string) $appt['status'] === 'confirmed') {
            $patient = $this->patients->find((int) $appt['patient_id']);
            if ($patient !== null && $actor === 'patient') {
                $this->sendAppointmentSms(SmsEvents::APPT_CANCELLED, (string) $patient['mobile'], $appt);
            }
        }

        return ['appointment_id' => $appointmentId, 'status' => $toState];
    }

    private function machineCheck(string $from, string $event, string $actor): string
    {
        try {
            return AppointmentMachine::create()->machine()->assert($from, $event, $actor);
        } catch (InvalidTransitionException) {
            throw BookingException::of('CLINIC_INVALID_TRANSITION', 'این عمل برای وضعیت فعلی نوبت مجاز نیست', 409);
        }
    }

    private function assertWindow(string $slotDate, string $slotTime, int $minLeadHours): void
    {
        $err = BookingWindow::checkRequest(
            $slotDate,
            $slotTime,
            $this->now(),
            $minLeadHours,
            (int) $this->settings->get('booking.max_future_days', 60)
        );
        if ($err === BookingWindow::CODE_INVALID) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'تاریخ/ساعت نوبت نامعتبر است');
        }
        if ($err !== null) {
            throw BookingException::of('CLINIC_POLICY_VIOLATION', 'بازه انتخابی خارج از Window رزرو است (حداقل ' . $minLeadHours . ' ساعت؛ حداکثر ' . (int) $this->settings->get('booking.max_future_days', 60) . ' روز)', 409);
        }
    }

    private function assertLicense(string $operation): void
    {
        $decision = $this->licenseGate->assert($operation);
        if (!$decision->allowed) {
            $this->op->warning('license.blocked', ['operation' => $operation, 'reason' => $decision->reason]);
            throw BookingException::of('CLINIC_LICENSE_BLOCKED', 'سیستم در حالت Read-Only است (مجازت) — عملیات جدید مجاز نیست', 503);
        }
    }

    private function requireClinician(int $clinicianId): void
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM ' . $this->db->table('cpms_clinicians') . ' WHERE id = %d AND is_active = 1 LIMIT 1',
            [$clinicianId]
        );
        if ($row === null) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'پزشک یافت نشد', 404);
        }
    }

    /**
     * Mobile بیمار برای Hold: (1) Patient Link primary → (2) Email پترن F2 → (3) null.
     */
    private function mobileForUser(int $wpUserId): ?string
    {
        $primary = $this->db->fetchRow(
            'SELECT p.mobile FROM ' . $this->db->table('cpms_patient_user_links') . ' l
             JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = l.patient_id
             WHERE l.wp_user_id = %d AND p.status = %s
             ORDER BY l.is_primary DESC, l.id ASC LIMIT 1',
            [$wpUserId, 'active']
        );
        if ($primary !== null) {
            return (string) $primary['mobile'];
        }

        $email = $this->db->fetchValue(
            'SELECT user_email FROM ' . $this->db->dbPrefix() . 'users WHERE ID = %d LIMIT 1',
            [$wpUserId]
        );
        if (is_string($email) && str_ends_with($email, '@otp.cpms.local')) {
            return substr($email, 0, -strlen('@otp.cpms.local'));
        }

        return null;
    }

    /**
     * N-1: ساخت Patient Record Minimal برای کاربر جدید (OTP verified).
     *
     * @return array<string, mixed>
     */
    private function createMinimalPatient(string $mobile, int $wpUserId): array
    {
        $nowSql = $this->db->nowUtcSql();
        $id = $this->patients->create([
            'clinic_id' => 1,
            'mrn' => $this->generateMrn(),
            'first_name' => '',
            'last_name' => '',
            'mobile' => $mobile,
            'status' => 'active',
            'created_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);
        $this->db->insert('cpms_patient_user_links', [
            'clinic_id' => 1,
            'patient_id' => $id,
            'wp_user_id' => $wpUserId,
            'mobile_at_link' => $mobile,
            'is_primary' => 1,
            'linked_at' => $nowSql,
        ]);
        $this->audit('PATIENT_CREATED', $wpUserId, 'patient', 'patient', $id, $id, null, ['auto' => 'booking_confirm', 'mobile' => MobileValidator::mask($mobile)]);

        return (array) $this->patients->find($id);
    }

    /**
     * @return list<int>
     */
    private function patientIdsForUser(int $wpUserId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT patient_id FROM ' . $this->db->table('cpms_patient_user_links') .
            ' WHERE wp_user_id = %d ORDER BY is_primary DESC, id ASC',
            [$wpUserId]
        );

        return array_map(static fn (array $r): int => (int) $r['patient_id'], $rows);
    }

    private function userHasPatient(int $wpUserId, int $patientId): bool
    {
        $v = $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_patient_user_links') .
            ' WHERE wp_user_id = %d AND patient_id = %d',
            [$wpUserId, $patientId]
        );

        return (int) $v > 0;
    }

    private function findActiveByUserSlot(int $wpUserId, int $slotId): ?array
    {
        $patientIds = $this->patientIdsForUser($wpUserId);
        if ($patientIds === []) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($patientIds), '%d'));

        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_appointments') .
            " WHERE slot_id = %d AND patient_id IN ({$placeholders}) AND status IN ('pending','confirmed') ORDER BY id DESC LIMIT 1",
            array_merge([$slotId], $patientIds)
        );
    }

    /**
     * N-6: `MR-{YYMMDD}-{5char}` — Retry روی Unique Constraint.
     */
    private function generateMrn(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $mrn = 'MR-' . gmdate('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
            $exists = $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = 1 AND mrn = %s',
                [$mrn]
            );
            if ((int) $exists === 0) {
                return $mrn;
            }
        }
        throw new BookingException('CLINIC_VALIDATION_FAILED', 'خطا در ساخت MRN — دوباره تلاش کنید', 500);
    }

    private function referenceCode(string $slotDate): string
    {
        $base = 'AP-' . str_replace('-', '', $slotDate);
        $count = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_appointments') . ' WHERE slot_date = %s',
            [$slotDate]
        );
        $candidate = $base . '-' . str_pad((string) ($count + 1), 2, '0', STR_PAD_LEFT);

        for ($i = 0; $i < 5; $i++) {
            $exists = $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->db->table('cpms_appointments') . ' WHERE reference_code = %s',
                [$candidate]
            );
            if ((int) $exists === 0) {
                return $candidate;
            }
            $candidate = $base . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
        }

        return $candidate;
    }

    /**
     * SMS رویداد نوبت (بعد از Commit) — متغیرهای استاندارد ADR-0025.
     *
     * @param array<string, mixed> $appt
     */
    private function sendAppointmentSms(string $event, string $mobile, array $appt): void
    {
        try {
            $doctor = $this->db->fetchRow(
                'SELECT full_name FROM ' . $this->db->table('cpms_clinicians') . ' WHERE id = %d LIMIT 1',
                [(int) $appt['clinician_id']]
            );
            $clinic = (string) $this->db->fetchValue(
                'SELECT name FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = 1 LIMIT 1'
            );
            $patient = $this->patients->find((int) $appt['patient_id']);
            $patientName = $patient !== null
                ? trim((string) $patient['first_name'] . ' ' . (string) $patient['last_name'])
                : '';
            if ($patientName === '') {
                $patientName = 'بیمار گرامی';
            }

            $this->sms->sendEvent(
                $event,
                $mobile,
                [
                    'patient_name' => $patientName,
                    'doctor_name' => (string) ($doctor['full_name'] ?? 'پزشک'),
                    'appointment_date' => Jalali::formatYmd((string) $appt['slot_date']),
                    'appointment_time' => substr((string) $appt['slot_time'], 0, 5),
                    'clinic_name' => $clinic !== '' ? $clinic : 'مطب',
                ],
                'appointment',
                (int) $appt['id']
            );
        } catch (Throwable $e) {
            // SMS هرگز نباید Booking را شکست بدهد — Job/Retry خودش مدیریت می‌کند
            $this->op->warning('booking.sms_failed', ['event' => $event, 'appointment_id' => (int) $appt['id'], 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function appointmentView(array $row): array
    {
        $date = (string) $row['slot_date'];

        return [
            'id' => (int) $row['id'],
            // API Contract B2/B5: کلید پاسخ Confirm/Reschedule = appointment_id
            'appointment_id' => (int) $row['id'],
            'reference_code' => (string) $row['reference_code'],
            'date' => $date,
            'time' => substr((string) $row['slot_time'], 0, 5),
            'jalali' => Jalali::formatYmd($date),
            'jalali_time' => substr((string) $row['slot_time'], 0, 5),
            'status' => (string) $row['status'],
            'duration_min' => (int) ($row['duration_min'] ?? 0),
            'reason' => $row['reason'] ?? null,
            'is_walkin_express' => (bool) ($row['is_walkin_express'] ?? 0),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function mapAppointmentViews(array $rows): array
    {
        return array_map(fn (array $r): array => $this->appointmentView($r), $rows);
    }

    /**
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private function slotView(int $slotId, array $slot): array
    {
        $date = (string) $slot['slot_date'];
        $doctor = $this->db->fetchRow(
            'SELECT id, full_name FROM ' . $this->db->table('cpms_clinicians') . ' WHERE id = %d LIMIT 1',
            [(int) $slot['clinician_id']]
        );

        return [
            'slot_id' => $slotId,
            'clinician_id' => (int) $slot['clinician_id'],
            'clinician_name' => (string) ($doctor['full_name'] ?? ''),
            'date' => $date,
            'time' => substr((string) $slot['slot_time'], 0, 5),
            'jalali' => Jalali::formatYmd($date),
            'jalali_time' => substr((string) $slot['slot_time'], 0, 5),
            'duration_min' => (int) $slot['duration_min'],
            'capacity_left' => max(0, (int) $slot['capacity'] - (int) $slot['booked_count'] - (int) $slot['held_count']),
        ];
    }

    /**
     * @param array{is_replay: bool, response: array<string,mixed>|null, response_code: int|null} $check
     * @return array<string, mixed>
     */
    private function replayOrInFlight(array $check, int $wpUserId): array
    {
        if (($check['response_code'] ?? null) === 409 && ($check['response']['error'] ?? null) === 'CLINIC_DUPLICATE_IN_FLIGHT') {
            throw BookingException::of('CLINIC_DUPLICATE_IN_FLIGHT', 'درخواست شما در حال پردازش است — تکرار نکنید', 409);
        }
        $response = $check['response'] ?? [];

        return is_array($response) ? $response : [];
    }

    private function toBookingException(Throwable $e): BookingException
    {
        if ($e instanceof BookingException) {
            return $e;
        }
        if ($e instanceof InvalidTransitionException) {
            return BookingException::of('CLINIC_INVALID_TRANSITION', 'این عمل برای وضعیت فعلی نوبت مجاز نیست', 409);
        }
        $this->op->error('booking.unexpected_error', ['error' => $e->getMessage()]);

        return BookingException::of('CLINIC_INTERNAL_ERROR', 'خطای داخلی — دوباره تلاش کنید', 500);
    }

    private function audit(
        string $action,
        ?int $wpUserId,
        string $role,
        string $resourceType,
        ?int $resourceId,
        ?int $patientId,
        ?array $before,
        ?array $after,
        array $meta = []
    ): void {
        $this->audit->log(
            $action,
            ['wp_user_id' => $wpUserId, 'role' => $role],
            $resourceType,
            $resourceId,
            $patientId,
            $before,
            $after,
            $meta
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function toDateTime(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
