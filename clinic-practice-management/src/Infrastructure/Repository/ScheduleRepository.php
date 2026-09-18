<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;
use RuntimeException;

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

    /*
     * Phase 6 Slice 6: `location_id` بخشی از Whitelist درج است (قرارداد
     * Migration 0015) — `null` یعنی همهٔ Locationهای Clinic معتبر و عدد یعنی
     * فقط همان Location. اعتبارسنجی (واقعی/فعال/مالکیت Clinic) در Service است؛
     * اینجا فقط اجازهٔ عبور مقدارِ از قبل‌اعتبارسنجی‌شده به جدول داده می‌شود و
     * هیچ جایگزینی/پیش‌فرضی ساخته نمی‌شود.
     */
    private const EXCEPTION_CREATE_FIELDS = [
        'clinic_id', 'location_id', 'clinician_id', 'date', 'type',
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
     * واکشی ردیف برنامه همراه با قفل FOR UPDATE.
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchRowForUpdate(
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
     * Phase 6 — Slice 4 (multi-shift): همهٔ ردیف‌های یک محدودهٔ
     * (Clinic معتبر، Location، پزشک، روز هفته) — سرویس روی همین مجموعه
     * «تکرارِ دقیقِ start_time» و «همپوشانیِ پنجرهٔ ACTIVEها» را مقایسه می‌کند
     * (پوشش داده‌شده با کلید یکتای 0014 — بدون ایندکس جدید). جایگزینِ یابندهٔ
     * تک‌ردیفهٔ Slice 3 (حذف شد — یک LIMIT 1 در اینجا فرضِ منسوخِ
     * «یک ردیف در هر روز هفته» را بازمی‌گرداند).
     *
     * @return list<array<string, mixed>>
     */
    public function listByClinicianDayInClinicAndLocation(int $clinicianId, int $dayOfWeek, int $clinicId, int $locationId): array
    {
        return $this->db->fetchAll(
            'SELECT id, start_time, end_time, is_active FROM ' . $this->db->table('cpms_schedule') .
            ' WHERE clinician_id = %d AND day_of_week = %d AND clinic_id = %d AND location_id = %d ORDER BY start_time, id',
            [$clinicianId, $dayOfWeek, $clinicId, $locationId]
        );
    }

    /**
     * واکشی تمام شیفت‌های یک پزشک در یک روز/محل/کلینیک با قفل FOR UPDATE.
     *
     * @return list<array<string, mixed>>
     */
    public function listByClinicianDayInClinicAndLocationForUpdate(int $clinicianId, int $dayOfWeek, int $clinicId, int $locationId): array
    {
        return $this->db->fetchAllForUpdate(
            'SELECT id, start_time, end_time, is_active FROM ' . $this->db->table('cpms_schedule') .
            ' WHERE clinician_id = %d AND day_of_week = %d AND clinic_id = %d AND location_id = %d ORDER BY start_time, id',
            [$clinicianId, $dayOfWeek, $clinicId, $locationId]
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

        /*
         * Phase 6 Slice 3: هیچ fallback به Location اصلی (یا هر Location دیگر)
         * در مسیر create باقی نمانده است. `location_id` حالا ورودیِ الزامیِ
         * قرارداد است که سرویس در برابر Clinic معتبرِ Scope اعتبارسنجی
         * کرده است (real + active + clinic match). ستون NOT NULL است
         * (Migration 0013)؛ مقادیر بدون/نامعتبر اینجا به‌صورت آشکار می‌شکنند —
         * یعنی برنامه‌نویسیِ caller را — نه با جایگزینیِ بی‌صدا پنهان می‌شوند.
         */
        if ((int) ($data['location_id'] ?? 0) <= 0) {
            throw new RuntimeException('schedule create requires an explicit validated location_id (no fallback)');
        }

        $ok = $this->db->insert('cpms_schedule', $data);
        $id = $this->db->wpdb_last_insert_id();
        if (!$ok || $id <= 0) {
            throw new RuntimeException('failed to insert schedule row: ' . $this->db->wpdb()->last_error);
        }

        return $id;
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
     * Phase 6 Slice 2: Locationهای متمایزی که Slot برای این پزشک در این
     * Clinic دارند — مبنای cohortبندیِ مرز «امروز محلی» (slot_date مقدار
     * wall-clock محلیِ Location است؛ هر cohort مرز خودش را لازم دارد).
     *
     * @return list<int>
     */
    public function distinctClinicianSlotLocationIds(int $clinicianId, int $clinicId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT location_id FROM ' . $this->db->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND location_id IS NOT NULL',
            [$clinicianId, $clinicId]
        );

        return array_map('intval', array_column($rows, 'location_id'));
    }

    /**
     * Phase 6 Slice 1/2: تعداد Slotهای آیندهٔ «خالی» (بدون رزرو/Hold) یک پزشک
     * در یک Clinic معتبر و یک cohort مکانی — $fromDate باید «امروزِ محلیِ
     * همان Location» باشد (نه gmdate سرور). Slotهای دیگر Clinicها/Locationها
     * شمرده نمی‌شوند (ایزولیشن tenant / چندعضویتی مشروع).
     *
     * @param int $clinicId Clinic معتبر و دامنه‌بندی‌کننده (الزامی، از Scope مورد اعتماد)
     */
    public function countFutureEmptySlots(int $clinicianId, int $clinicId, int $locationId, string $fromDate): int
    {
        $value = $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND location_id = %d AND slot_date > %s AND booked_count = 0 AND held_count = 0',
            [$clinicianId, $clinicId, $locationId, $fromDate]
        );

        return (int) $value;
    }

    /**
     * Phase 6 Slice 1/2: تعداد Slotهای آیندهٔ «محافظت‌شده» (دارای رزرو یا Hold)
     * یک پزشک در یک Clinic معتبر و یک cohort مکانی — مرز، «امروزِ محلیِ همان
     * Location» است.
     *
     * @param int $clinicId Clinic معتبر و دامنه‌بندی‌کننده (الزامی)
     */
    public function countFutureReservedSlots(int $clinicianId, int $clinicId, int $locationId, string $fromDate): int
    {
        $value = $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND location_id = %d AND slot_date > %s AND (booked_count > 0 OR held_count > 0)',
            [$clinicianId, $clinicId, $locationId, $fromDate]
        );

        return (int) $value;
    }

    /**
     * Phase 6 Slice 1/2: Regeneration (ADR-0004) — حذف Slotهای آینده «خالی»
     * (بدون رزرو/Hold) یک پزشک فقط در Clinic معتبرِ عملیات و فقط در cohort
     * یک Location — $fromDate باید «امروزِ محلیِ همان Location» باشد تا
     * امروزِ محلیِ Locationهای دیگر هرگز ناخواسته حذف نشوند. Slotهای خالی
     * یا محافظت‌شدهٔ Clinic دیگر همان پزشک (مشارکت مشروع چندعضویتی)
     * دست‌نخورده می‌مانند. Slotهای دارای Booking/Hold در همان Clinic هم
     * هرگز حذف نمی‌شوند (Snapshot/امانت داده).
     *
     * @param int $clinicId Clinic معتبر و دامنه‌بندی‌کننده (الزامی)
     *
     * @return int تعداد ردیف‌های حذف‌شده
     */
    public function deleteFutureEmptySlots(int $clinicianId, int $clinicId, int $locationId, string $fromDate): int
    {
        $sql = $this->db->prepare(
            'DELETE FROM ' . $this->db->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND location_id = %d AND slot_date > %s AND booked_count = 0 AND held_count = 0',
            [$clinicianId, $clinicId, $locationId, $fromDate]
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
