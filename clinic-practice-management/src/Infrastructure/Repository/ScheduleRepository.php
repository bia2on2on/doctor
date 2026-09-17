<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Application\Scope\PrimaryLocationResolver;
use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository برنامه هفتگی + استثنائات — ADR-0021 (Domain-Focused، از F3).
 *
 * - فقط جداول `cpms_schedule` و `cpms_schedule_exceptions` (+ پاکسازی Slotهای
 *   آینده خالی برای Regeneration — ADR-0004 Consequence).
 * - Mass Assignment Protection: Whitelist داخلی — هرگز مستقیم از Request.
 * - Transaction Ownership: Service.
 */
final class ScheduleRepository
{
    private const SCHEDULE_CREATE_FIELDS = [
        'clinic_id', 'location_id', 'clinician_id', 'day_of_week', 'start_time', 'end_time',
        'break_start', 'break_end', 'appointment_duration_min', 'slot_capacity',
        'is_active', 'buffer_pre_min', 'buffer_post_min', 'created_at', 'updated_at',
    ];

    private const SCHEDULE_UPDATE_FIELDS = [
        'start_time', 'end_time', 'break_start', 'break_end',
        'appointment_duration_min', 'slot_capacity', 'is_active',
        'buffer_pre_min', 'buffer_post_min', 'updated_at',
    ];

    private const EXCEPTION_CREATE_FIELDS = [
        'clinic_id', 'clinician_id', 'date', 'type',
        'start_time', 'end_time', 'reason', 'created_by_wp_user_id', 'created_at',
    ];

    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_schedule') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * C7-S2: برنامهٔ هفتگی با شناسه، دامنه‌بندی‌شده به Clinic معتبر — ردیفِ
     * کلینیک دیگر حتی بارگذاری نمی‌شود (پاسخ یکسان با «یافت نشد»).
     * Predicate روی PRIMARY KEY + ستون clinic_id موجود (بدون ایندکس جدید).
     *
     * @return array<string, mixed>|null
     */
    public function findForClinic(int $id, int $clinicId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_schedule') . ' WHERE id = %d AND clinic_id = %d LIMIT 1',
            [$id, $clinicId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByClinicianDay(int $clinicianId, int $dayOfWeek): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_schedule') .
            ' WHERE clinician_id = %d AND day_of_week = %d LIMIT 1',
            [$clinicianId, $dayOfWeek]
        );
    }

    /**
     * Phase 4 — Slice 1: قاعدهٔ «یک برنامه در هر روز هفته» داخل یک Clinic است
     * (قرارداد یکتایی 0014 = `(clinic_id, location_id, clinician_id, day_of_week,
     * start_time)` — «چند شعبه در یک روز» مجاز است). پیش‌بررسیِ create با
     * دامنهٔ Clinic معتبرِ درخواست انجام می‌شود تا برنامهٔ Clinic دیگر، ثبتِ
     * Clinic دوم را به‌اشتباه «تکراری» نکند.
     *
     * @return array<string, mixed>|null
     */
    public function findByClinicianDayInClinic(int $clinicianId, int $dayOfWeek, int $clinicId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_schedule') .
            ' WHERE clinician_id = %d AND day_of_week = %d AND clinic_id = %d LIMIT 1',
            [$clinicianId, $dayOfWeek, $clinicId]
        );
    }

    /**
     * Phase 4 — Slice 1: خواندنِ برنامهٔ پزشک دامنه‌بندی‌شده به Clinic معتبر —
     * ردیف‌های Clinic دیگر (حتی برای همان پزشکِ چند‌عضویتی) افشا نمی‌شوند.
     *
     * @return list<array<string, mixed>>
     */
    public function listByClinicianInClinic(int $clinicianId, int $clinicId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_schedule') .
            ' WHERE clinician_id = %d AND clinic_id = %d ORDER BY day_of_week, start_time',
            [$clinicianId, $clinicId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByClinician(int $clinicianId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_schedule') .
            ' WHERE clinician_id = %d ORDER BY day_of_week, start_time',
            [$clinicianId]
        );
    }

    /**
     * @param array<string, mixed> $fields فقط فیلدهای Whitelist
     */
    public function create(array $fields): int
    {
        $data = $this->whitelist($fields, self::SCHEDULE_CREATE_FIELDS);

        // Phase 2 (AD-15): برنامهٔ کاری به یک Location گره خورده است. اگر
        // caller صریحاً Location نداده، Location اصلی همان Clinic (deterministic)
        // است — هر Clinic حداقل یک Location دارد.
        if (empty($data['location_id'])) {
            $data['location_id'] = PrimaryLocationResolver::resolve(
                $this->db,
                (int) ($data['clinic_id'] ?? 0)
            );
        }

        $this->db->insert('cpms_schedule', $data);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @param array<string, mixed> $fields فقط فیلدهای Whitelist
     */
    public function update(int $id, array $fields): int
    {
        $data = $this->whitelist($fields, self::SCHEDULE_UPDATE_FIELDS);
        if ($data === []) {
            return 0;
        }

        return $this->db->update('cpms_schedule', $data, ['id' => $id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('cpms_schedule', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findException(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_schedule_exceptions') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * C7-S2: استثنای برنامه با شناسه، دامنه‌بندی‌شده به Clinic معتبر —
     * همان قرارداد findForClinic (404 parity، بدون بارگذاری ردیف خارجی).
     *
     * @return array<string, mixed>|null
     */
    public function findExceptionForClinic(int $id, int $clinicId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_schedule_exceptions') . ' WHERE id = %d AND clinic_id = %d LIMIT 1',
            [$id, $clinicId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listExceptions(int $clinicianId, string $fromDate, string $toDate): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_schedule_exceptions') .
            ' WHERE clinician_id = %d AND date BETWEEN %s AND %s ORDER BY date, start_time',
            [$clinicianId, $fromDate, $toDate]
        );
    }

    /**
     * Phase 4 — Slice 1: استثناهای برنامهٔ پزشک دامنه‌بندی‌شده به Clinic معتبر —
     * تعطیلیِ یک شعبه، شعبهٔ دیگر همان پزشک را نمی‌بندد.
     *
     * @return list<array<string, mixed>>
     */
    public function listExceptionsInClinic(int $clinicianId, int $clinicId, string $fromDate, string $toDate): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_schedule_exceptions') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND date BETWEEN %s AND %s ORDER BY date, start_time',
            [$clinicianId, $clinicId, $fromDate, $toDate]
        );
    }

    /**
     * @param array<string, mixed> $fields فقط فیلدهای Whitelist
     */
    public function createException(array $fields): int
    {
        $data = $this->whitelist($fields, self::EXCEPTION_CREATE_FIELDS);
        $this->db->insert('cpms_schedule_exceptions', $data);

        return $this->db->wpdb_last_insert_id();
    }

    public function deleteException(int $id): int
    {
        return $this->db->delete('cpms_schedule_exceptions', ['id' => $id]);
    }

    /**
     * Phase 6 Slice 1: تعداد Slotهای آیندهٔ «خالی» (بدون رزرو/Hold) یک پزشک
     * در یک Clinic معتبر — Slotهای خالیِ دیگر Clinicهای همان پزشک شمرده
     * نمی‌شوند (ایزولیشن tenant / چندعضویتی مشروع).
     *
     * @param int $clinicId Clinic معتبر و دامنه‌بندی‌کننده (الزامی، از Scope مورد اعتماد)
     */
    public function countFutureEmptySlots(int $clinicianId, int $clinicId, string $fromDate): int
    {
        $value = $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND slot_date > %s AND booked_count = 0 AND held_count = 0',
            [$clinicianId, $clinicId, $fromDate]
        );

        return (int) $value;
    }

    /**
     * Phase 6 Slice 1: تعداد Slotهای آیندهٔ «محافظت‌شده» (دارای رزرو یا Hold)
     * یک پزشک در یک Clinic معتبر.
     *
     * @param int $clinicId Clinic معتبر و دامنه‌بندی‌کننده (الزامی)
     */
    public function countFutureReservedSlots(int $clinicianId, int $clinicId, string $fromDate): int
    {
        $value = $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND slot_date > %s AND (booked_count > 0 OR held_count > 0)',
            [$clinicianId, $clinicId, $fromDate]
        );

        return (int) $value;
    }

    /**
     * Phase 6 Slice 1: Regeneration (ADR-0004) — حذف Slotهای آینده «خالی»
     * (بدون رزرو/Hold) یک پزشک فقط در Clinic معتبرِ عملیات. Slotهای خالی
     * یا محافظت‌شدهٔ Clinic دیگر همان پزشک (مشارکت مشروع چندعضویتی)
     * دست‌نخورده می‌مانند. Slotهای دارای Booking/Hold در همان Clinic هم
     * هرگز حذف نمی‌شوند (Snapshot/امانت داده).
     *
     * @param int $clinicId Clinic معتبر و دامنه‌بندی‌کننده (الزامی)
     *
     * @return int تعداد ردیف‌های حذف‌شده
     */
    public function deleteFutureEmptySlots(int $clinicianId, int $clinicId, string $fromDate): int
    {
        $sql = $this->db->prepare(
            'DELETE FROM ' . $this->db->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND slot_date > %s AND booked_count = 0 AND held_count = 0',
            [$clinicianId, $clinicId, $fromDate]
        );
        $result = $this->db->wpdb()->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

        return $result === false ? 0 : (int) $result;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string>         $whitelist
     * @return array<string, mixed>
     */
    private function whitelist(array $fields, array $whitelist): array
    {
        $out = [];
        foreach ($whitelist as $field) {
            if (array_key_exists($field, $fields)) {
                $out[$field] = $fields[$field];
            }
        }

        return $out;
    }
}
