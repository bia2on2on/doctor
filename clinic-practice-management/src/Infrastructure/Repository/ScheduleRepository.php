<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

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
        'clinic_id', 'clinician_id', 'day_of_week', 'start_time', 'end_time',
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
     * Regeneration (ADR-0004): حذف Slotهای آینده «خالی» (بدون رزرو/Hold) یک پزشک
     * تا بازتولید از برنامه جدید ممکن شود. Slotهای دارای Booking/Hold دست‌نخورده
     * می‌مانند (Snapshot/امانت داده).
     *
     * @return int تعداد ردیف‌های حذف‌شده
     */
    public function deleteFutureEmptySlots(int $clinicianId, string $fromDate): int
    {
        $sql = $this->db->prepare(
            'DELETE FROM ' . $this->db->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND slot_date > %s AND booked_count = 0 AND held_count = 0',
            [$clinicianId, $fromDate]
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
