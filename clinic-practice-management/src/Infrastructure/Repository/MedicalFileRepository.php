<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository پیوست‌های پزشکی — ADR-0021. جدول `cpms_medical_attachments`.
 * Soft Delete با `deleted_at` (FR-13.6) — هیچ متدی رکورد حذف‌شده را
 * برنمی‌گرداند مگر صریحاً خواسته شود.
 */
final class MedicalFileRepository
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
            'visit_id' => null,
            'metadata_json' => null,
            'deleted_at' => null,
            'created_at' => $this->db->nowUtcSql(),
        ];
        $this->db->insert('cpms_medical_attachments', $row);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_medical_attachments') .
            ' WHERE id = %d AND deleted_at IS NULL LIMIT 1',
            [$id]
        );
    }

    /**
     * فایل‌های بیمار — نمای بیمار فقط patient_visible (فیلتر Service/Query).
     *
     * @return list<array<string, mixed>>
     */
    public function forPatient(int $patientId, ?bool $onlyPatientVisible = null, int $limit = 100): array
    {
        $where = 'patient_id = %d AND deleted_at IS NULL';
        $params = [$patientId];
        if ($onlyPatientVisible !== null) {
            $where .= ' AND visibility = %s';
            $params[] = $onlyPatientVisible ? 'patient_visible' : 'doctor_private';
        }
        $params[] = $limit;

        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_medical_attachments') .
            ' WHERE ' . $where . ' ORDER BY id DESC LIMIT %d',
            $params
        ) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forVisit(int $visitId, int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_medical_attachments') .
            ' WHERE visit_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT %d',
            [$visitId, $limit]
        ) ?: [];
    }

    public function softDelete(int $id): void
    {
        $this->db->update('cpms_medical_attachments', ['deleted_at' => $this->db->nowUtcSql()], ['id' => $id]);
    }
}
