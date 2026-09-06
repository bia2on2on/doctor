<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository پیگیری‌ها (Follow-up) — ADR-0021. جدول `cpms_follow_ups`.
 *
 * وضعیت‌ها: pending → booked (نوبت لینک شد) / done / cancelled.
 * ایندکس idx_fu_due (status, suggested_date) برای Job یادآوری (F8).
 */
final class FollowUpRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $row += [
            'clinic_id' => 1,
            'is_needed' => 1,
            'suggested_date' => null,
            'interval_days' => null,
            'reason' => null,
            'status' => 'pending',
            'linked_appointment_id' => null,
            'reminder_sent_at' => null,
            'created_at' => $this->db->nowUtcSql(),
        ];
        $this->db->insert('cpms_follow_ups', $row);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->db->update('cpms_follow_ups', $data, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_follow_ups') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * پیگیری ویزیت — آخرین اول (E7/E13).
     *
     * @return list<array<string, mixed>>
     */
    public function forVisit(int $visitId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_follow_ups') .
            ' WHERE visit_id = %d ORDER BY id DESC',
            [$visitId]
        ) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forPatient(int $patientId, int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_follow_ups') .
            ' WHERE patient_id = %d ORDER BY id DESC LIMIT %d',
            [$patientId, $limit]
        ) ?: [];
    }

    /**
     * پیگیری‌های سررسیدِ pending (برای Job یادآوری — F8) و داشبورد.
     *
     * @return list<array<string, mixed>>
     */
    public function duePending(int $clinicId, string $untilDate, int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_follow_ups') .
            ' WHERE clinic_id = %d AND status = %s AND suggested_date IS NOT NULL AND suggested_date <= %s' .
            ' ORDER BY suggested_date ASC LIMIT %d',
            [$clinicId, 'pending', $untilDate, $limit]
        ) ?: [];
    }
}
