<?php

declare(strict_types=1);

namespace ClinicCore\Application\Booking;

use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\ScheduleRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * سرویس مدیریت برنامه هفتگی و استثنائات (F3 — SRS FR-3.1/FR-3.2، Contract G1).
 *
 * دامنه: Technical Config — Capability `cpms_config` (Admin فنی؛ Matrix §4.4).
 * هیچ PHI در ورودی/خروجی نیست.
 *
 * Regeneration (ADR-0004 Consequence):
 *  هر تغییر برنامه/استثنا → Slotهای آینده «خالی» (booked=0, held=0) پزشک حذف و
 *  Job `slots.generate` فوری enqueue می‌شود (بازتولید Idempotent با INSERT IGNORE).
 *  Slotهای دارای رزرو/Hold هرگز حذف نمی‌شوند.
 *
 * LicenseGate: عملیات Config جزو عملیات Protected نیست (Seam فقط برای
 * Booking/Patient/Visit/Invoice — ADR-0023). F10 در صورت نیاز اضافه می‌کند.
 */
final class ScheduleService
{
    private const DAY_TYPES = ['holiday', 'leave', 'blocked', 'open_override'];
    private const FULL_DAY_TYPES = ['holiday', 'leave'];
    private const RANGE_TYPES = ['blocked', 'open_override'];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly ScheduleRepository $schedules,
        private readonly JobQueue $jobs,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op
    ) {
    }

    // ================= G1 — Weekly Schedule =================

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $clinicianId): array
    {
        $this->requireClinician($clinicianId);

        return array_map(
            fn (array $r): array => $this->scheduleView($r),
            $this->schedules->listByClinician($clinicianId)
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function create(int $actorUserId, array $fields): array
    {
        $clinicianId = $this->intField($fields, 'clinician_id');
        $this->requireClinician($clinicianId);

        $day = $this->intField($fields, 'day_of_week');
        if ($day < 0 || $day > 6) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'روز هفته نامعتبر است (0=شنبه … 6=جمعه)', 400, ['errors' => ['day_of_week' => 'range_0_6']]);
        }
        if ($this->schedules->findByClinicianDay($clinicianId, $day) !== null) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'برای این روز هفته قبلاً برنامه ثبت شده — از ویرایش استفاده کنید', 400, ['errors' => ['day_of_week' => 'duplicate_schedule_day']]);
        }

        $data = $this->validatedScheduleFields($fields);
        $nowSql = $this->db->nowUtcSql();
        $id = $this->schedules->create($data + [
            'clinic_id' => 1,
            'clinician_id' => $clinicianId,
            'day_of_week' => $day,
            'created_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);

        $view = $this->scheduleView((array) $this->schedules->find($id));
        $this->audit('SCHEDULE_CREATED', $actorUserId, 'schedule', $id, null, null, $view);
        $this->op->info('config.schedule_created', ['schedule_id' => $id, 'clinician_id' => $clinicianId, 'actor' => $actorUserId]);
        $this->regenerate($clinicianId);

        return $view;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function update(int $actorUserId, int $id, array $fields): array
    {
        $current = $this->schedules->find($id);
        if ($current === null) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'برنامه یافت نشد', 404);
        }

        $data = $this->validatedScheduleFields($fields, (array) $current);
        if ($data !== []) {
            $data['updated_at'] = $this->db->nowUtcSql();
            $this->schedules->update($id, $data);
        }

        $updated = (array) $this->schedules->find($id);
        $view = $this->scheduleView($updated);
        $this->audit('SCHEDULE_UPDATED', $actorUserId, 'schedule', $id, null, $this->scheduleView($current), $view);
        $this->op->info('config.schedule_updated', ['schedule_id' => $id, 'actor' => $actorUserId]);
        $this->regenerate((int) $current['clinician_id']);

        return $view;
    }

    /**
     * @return array{id: int, deleted: true}
     */
    public function delete(int $actorUserId, int $id): array
    {
        $current = $this->schedules->find($id);
        if ($current === null) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'برنامه یافت نشد', 404);
        }

        $this->schedules->delete($id);
        $this->audit('SCHEDULE_DELETED', $actorUserId, 'schedule', $id, null, $this->scheduleView($current), null);
        $this->op->info('config.schedule_deleted', ['schedule_id' => $id, 'actor' => $actorUserId]);
        $this->regenerate((int) $current['clinician_id']);

        return ['id' => $id, 'deleted' => true];
    }

    // ================= G1b — Schedule Exceptions (SRS FR-3.2) =================

    /**
     * @return list<array<string, mixed>>
     */
    public function listExceptions(int $clinicianId, string $fromDate, string $toDate): array
    {
        $this->requireClinician($clinicianId);
        $from = $this->parseYmd($fromDate, 'from');
        $to = $this->parseYmd($toDate, 'to');
        if ($to < $from) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'بازه تاریخ نامعتبر است');
        }

        return array_map(
            fn (array $r): array => $this->exceptionView($r),
            $this->schedules->listExceptions($clinicianId, $from, $to)
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function createException(int $actorUserId, array $fields): array
    {
        $clinicianId = $this->intField($fields, 'clinician_id');
        $this->requireClinician($clinicianId);

        $date = $this->parseYmd((string) ($fields['date'] ?? ''), 'date');
        if ($date < gmdate('Y-m-d')) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'تاریخ استثنا باید امروز یا آینده باشد', 400, ['errors' => ['date' => 'past_date']]);
        }

        $type = (string) ($fields['type'] ?? '');
        if (!in_array($type, self::DAY_TYPES, true)) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'نوع استثنا نامعتبر است', 400, ['errors' => ['type' => 'invalid_type']]);
        }

        $startTime = isset($fields['start_time']) && $fields['start_time'] !== '' ? $this->parseTime((string) $fields['start_time'], 'start_time') : null;
        $endTime = isset($fields['end_time']) && $fields['end_time'] !== '' ? $this->parseTime((string) $fields['end_time'], 'end_time') : null;

        if (in_array($type, self::RANGE_TYPES, true)) {
            // blocked / open_override: بازه الزامی
            if ($startTime === null || $endTime === null) {
                throw BookingException::of('CLINIC_VALIDATION_FAILED', 'برای این نوع استثنا، ساعت شروع و پایان الزامی است', 400, ['errors' => ['start_time,end_time' => 'required_for_range_type']]);
            }
            if ($endTime <= $startTime) {
                throw BookingException::of('CLINIC_VALIDATION_FAILED', 'ساعت پایان باید بعد از شروع باشد', 400, ['errors' => ['end_time' => 'must_be_after_start']]);
            }
        } else {
            // holiday / leave: کل روز — بازه معنا ندارد
            $startTime = null;
            $endTime = null;
        }

        $reason = isset($fields['reason']) && is_string($fields['reason']) && $fields['reason'] !== ''
            ? mb_substr($fields['reason'], 0, 255)
            : null;

        $id = $this->schedules->createException([
            'clinic_id' => 1,
            'clinician_id' => $clinicianId,
            'date' => $date,
            'type' => $type,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'reason' => $reason,
            'created_by_wp_user_id' => $actorUserId,
            'created_at' => $this->db->nowUtcSql(),
        ]);

        $view = $this->exceptionView((array) $this->schedules->findException($id));
        $this->audit('SCHEDULE_EXCEPTION_CREATED', $actorUserId, 'schedule_exception', $id, null, null, $view);
        $this->op->info('config.schedule_exception_created', ['exception_id' => $id, 'clinician_id' => $clinicianId, 'actor' => $actorUserId]);
        $this->regenerate($clinicianId);

        return $view;
    }

    /**
     * @return array{id: int, deleted: true}
     */
    public function deleteException(int $actorUserId, int $id): array
    {
        $current = $this->schedules->findException($id);
        if ($current === null) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'استثنا یافت نشد', 404);
        }

        $this->schedules->deleteException($id);
        $this->audit('SCHEDULE_EXCEPTION_DELETED', $actorUserId, 'schedule_exception', $id, null, $this->exceptionView($current), null);
        $this->op->info('config.schedule_exception_deleted', ['exception_id' => $id, 'actor' => $actorUserId]);
        $this->regenerate((int) $current['clinician_id']);

        return ['id' => $id, 'deleted' => true];
    }

    // ================= Internal =================

    /**
     * اعتبارسنجی فیلدهای برنامه (Create/Update مشترک) — Merge روی مقادیر فعلی.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function validatedScheduleFields(array $fields, ?array $current = null): array
    {
        $out = [];

        $start = isset($fields['start_time']) && $fields['start_time'] !== ''
            ? $this->parseTime((string) $fields['start_time'], 'start_time')
            : ($current['start_time'] ?? null);
        $end = isset($fields['end_time']) && $fields['end_time'] !== ''
            ? $this->parseTime((string) $fields['end_time'], 'end_time')
            : ($current['end_time'] ?? null);
        $start = $start !== null ? substr((string) $start, 0, 8) : null;
        $end = $end !== null ? substr((string) $end, 0, 8) : null;

        if ($start === null || $end === null) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'ساعت شروع و پایان برنامه الزامی است', 400, ['errors' => ['start_time,end_time' => 'required']]);
        }
        if ($end <= $start) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'ساعت پایان باید بعد از شروع باشد', 400, ['errors' => ['end_time' => 'must_be_after_start']]);
        }

        $breakStart = isset($fields['break_start']) && $fields['break_start'] !== ''
            ? $this->parseTime((string) $fields['break_start'], 'break_start')
            : ($current['break_start'] ?? null);
        $breakEnd = isset($fields['break_end']) && $fields['break_end'] !== ''
            ? $this->parseTime((string) $fields['break_end'], 'break_end')
            : ($current['break_end'] ?? null);
        $breakStart = $breakStart !== null ? substr((string) $breakStart, 0, 8) : null;
        $breakEnd = $breakEnd !== null ? substr((string) $breakEnd, 0, 8) : null;
        if (($breakStart === null) !== ($breakEnd === null)) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'استراحت باید هم شروع و هم پایان داشته باشد (یا هیچ‌کدام)', 400, ['errors' => ['break_start,break_end' => 'pair_required']]);
        }
        if ($breakStart !== null && $breakEnd !== null) {
            if ($breakEnd <= $breakStart || $breakStart < $start || $breakEnd > $end) {
                throw BookingException::of('CLINIC_VALIDATION_FAILED', 'بازه استراحت باید داخل ساعات کاری باشد', 400, ['errors' => ['break_start,break_end' => 'outside_work_hours']]);
            }
        }

        $duration = array_key_exists('appointment_duration_min', $fields)
            ? (int) $fields['appointment_duration_min']
            : (isset($current['appointment_duration_min']) ? (int) $current['appointment_duration_min'] : 20);
        if ($duration < 5 || $duration > 240) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'مدت نوبت باید بین ۵ تا ۲۴۰ دقیقه باشد', 400, ['errors' => ['appointment_duration_min' => 'range_5_240']]);
        }

        $capacity = array_key_exists('slot_capacity', $fields)
            ? (int) $fields['slot_capacity']
            : (isset($current['slot_capacity']) ? (int) $current['slot_capacity'] : 1);
        if ($capacity < 1 || $capacity > 50) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'ظرفیت اسلات باید بین ۱ تا ۵۰ باشد', 400, ['errors' => ['slot_capacity' => 'range_1_50']]);
        }

        $isActive = array_key_exists('is_active', $fields)
            ? (int) (bool) $fields['is_active']
            : (isset($current['is_active']) ? (int) $current['is_active'] : 1);

        $out['start_time'] = $start;
        $out['end_time'] = $end;
        $out['break_start'] = $breakStart;
        $out['break_end'] = $breakEnd;
        $out['appointment_duration_min'] = $duration;
        $out['slot_capacity'] = $capacity;
        $out['is_active'] = $isActive;

        return $out;
    }

    /**
     * Regeneration: حذف Slotهای آینده خالی + Job فوری تولید.
     */
    private function regenerate(int $clinicianId): void
    {
        try {
            $removed = $this->schedules->deleteFutureEmptySlots($clinicianId, gmdate('Y-m-d'));
            $this->jobs->enqueue(
                'slots.generate',
                ['source' => 'manual'], // ENUM generated_from: lazy|cron|manual
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                priority: 3
            );
            if ($removed > 0) {
                $this->op->info('config.schedule_regenerated', ['clinician_id' => $clinicianId, 'removed_empty_slots' => $removed]);
            }
        } catch (\Throwable $e) {
            // Regeneration هرگز نباید تغییر Config را شکست بدهد — Job روزانه/Cron خودش جبران می‌کند
            $this->op->warning('config.schedule_regen_failed', ['clinician_id' => $clinicianId, 'error' => $e->getMessage()]);
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
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function scheduleView(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'clinician_id' => (int) $row['clinician_id'],
            'day_of_week' => (int) $row['day_of_week'],
            'start_time' => substr((string) $row['start_time'], 0, 5),
            'end_time' => substr((string) $row['end_time'], 0, 5),
            'break_start' => $row['break_start'] !== null ? substr((string) $row['break_start'], 0, 5) : null,
            'break_end' => $row['break_end'] !== null ? substr((string) $row['break_end'], 0, 5) : null,
            'appointment_duration_min' => (int) $row['appointment_duration_min'],
            'slot_capacity' => (int) $row['slot_capacity'],
            'is_active' => (bool) $row['is_active'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function exceptionView(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'clinician_id' => (int) $row['clinician_id'],
            'date' => (string) $row['date'],
            'type' => (string) $row['type'],
            'start_time' => $row['start_time'] !== null ? substr((string) $row['start_time'], 0, 5) : null,
            'end_time' => $row['end_time'] !== null ? substr((string) $row['end_time'], 0, 5) : null,
            'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
        ];
    }

    private function parseYmd(string $value, string $label): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value) !== 1) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', "{$label} باید به فرمت YYYY-MM-DD باشد");
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value, new DateTimeZone('UTC'));
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', "{$label} نامعتبر است");
        }

        return $value;
    }

    private function parseTime(string $value, string $label): string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value) !== 1) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', "{$label} باید به فرمت HH:MM باشد", 400, ['errors' => [$label => 'invalid_time']]);
        }
        $parts = explode(':', $value);

        return sprintf('%02d:%02d:00', (int) $parts[0], (int) $parts[1]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function intField(array $fields, string $key): int
    {
        $value = $fields[$key] ?? null;
        if ($value === null || $value === '' || !is_numeric($value)) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', "فیلد {$key} الزامی است", 400, ['errors' => [$key => 'required']]);
        }

        return (int) $value;
    }

    private function audit(
        string $action,
        int $wpUserId,
        string $resourceType,
        int $resourceId,
        ?int $patientId,
        ?array $before,
        ?array $after
    ): void {
        $this->audit->log(
            $action,
            ['wp_user_id' => $wpUserId, 'role' => 'staff'],
            $resourceType,
            $resourceId,
            $patientId,
            $before,
            $after
        );
    }
}
