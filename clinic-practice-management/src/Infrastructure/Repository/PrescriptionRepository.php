<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository نسخه (Prescription) + آیتم‌ها — ADR-0021.
 *
 * جداول: `cpms_prescriptions` (status: draft/finalized/voided) +
 * `cpms_prescription_items` (CASCADE به نسخه).
 *
 * شماره نسخه (FR-11.1): `RX-NNNNNN` یکتا در کلینیک — توسط Service با
 * شمارنده Settings-محور تولید و اینجا فقط یکتایی DB (u_rx_number) پشتیبان است.
 */
final class PrescriptionRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $now = $this->db->nowUtcSql();
        $row += [
            'status' => 'draft',
            'is_patient_visible' => 1,
            'void_reason' => null,
            'correction_of_prescription_id' => null,
            'finalized_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->db->insert('cpms_prescriptions', $row);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = $this->db->nowUtcSql();
        $this->db->update('cpms_prescriptions', $data, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_prescriptions') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * Row Lock — نهایی‌سازی/ابطال Race-safe.
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchRowForUpdate(
            'SELECT * FROM ' . $this->db->table('cpms_prescriptions') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insertItem(int $prescriptionId, array $row): void
    {
        $row += [
            'prescription_id' => $prescriptionId,
            'drug_ref_id' => null,
            'brand_name' => null,
            'strength' => null,
            'form' => 'tablet',
            'dose' => null,
            'frequency' => null,
            'route' => 'oral',
            'duration_days' => null,
            'instructions' => null,
            'source' => 'manual',
            'ocr_job_id' => null,
            'sort_order' => 0,
            'created_at' => $this->db->nowUtcSql(),
        ];
        $this->db->insert('cpms_prescription_items', $row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemsFor(int $prescriptionId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_prescription_items') .
            ' WHERE prescription_id = %d ORDER BY sort_order ASC, id ASC',
            [$prescriptionId]
        ) ?: [];
    }

    /**
     * نسخه‌های یک ویزیت — E7 (پزشک همه را می‌بیند).
     *
     * @return list<array<string, mixed>>
     */
    public function forVisit(int $visitId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_prescriptions') .
            ' WHERE visit_id = %d ORDER BY id DESC',
            [$visitId]
        ) ?: [];
    }

    /**
     * نسخه‌های بیمار — فیلتر `is_patient_visible` برای نمای بیمار (C7/FR-11.6).
     *
     * @return list<array<string, mixed>>
     */
    public function forPatient(int $patientId, ?bool $onlyPatientVisible, int $limit = 100): array
    {
        $where = 'patient_id = %d';
        $params = [$patientId];
        if ($onlyPatientVisible !== null) {
            $where .= ' AND is_patient_visible = %d';
            $params[] = $onlyPatientVisible ? 1 : 0;
        }
        $params[] = $limit;

        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_prescriptions') .
            ' WHERE ' . $where . ' ORDER BY id DESC LIMIT %d',
            $params
        ) ?: [];
    }

    /**
     * شماره بعدی نسخه (RX-NNNNNN) — از MAX فعلی + 1 (داخل Transaction فراخوانی شود).
     */
    public function nextPrescriptionNumber(): string
    {
        $max = $this->db->fetchValue(
            'SELECT MAX(CAST(SUBSTRING_INDEX(prescription_number, %s, -1) AS UNSIGNED)) FROM ' .
            $this->db->table('cpms_prescriptions'),
            ['-']
        );

        return sprintf('RX-%06d', (int) $max + 1);
    }

    /**
     * جستجوی نسخه بر اساس دارو (E18) — نام ژنریک/برند.
     *
     * @return list<array<string, mixed>>
     */
    public function searchByDrug(int $clinicId, string $q, string $from, string $to, int $limit = 25): array
    {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

        return $this->db->fetchAll(
            'SELECT DISTINCT rx.* FROM ' . $this->db->table('cpms_prescriptions') . ' rx' .
            ' JOIN ' . $this->db->table('cpms_prescription_items') . ' i ON i.prescription_id = rx.id' .
            ' WHERE rx.clinic_id = %d AND (i.generic_name LIKE %s OR i.brand_name LIKE %s)' .
            ' AND rx.created_at >= %s AND rx.created_at < %s' .
            ' ORDER BY rx.id DESC LIMIT %d',
            [$clinicId, $like, $like, $from . ' 00:00:00', $to . ' 23:59:59.999', $limit]
        ) ?: [];
    }
}
