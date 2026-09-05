<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository توصیه‌های درمانی — ADR-0021. جدول `cpms_recommendations`.
 * فرقی با FollowUpRepository ندارد جز دامنه — جدا نگه‌اش داشتیم چون
 * چرخه عمر/فیلترهای متفاوتی دارند (توصیه Immutable؛ Follow-up وضعیت دارد).
 */
final class RecommendationRepository
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
            'is_patient_visible' => 1,
            'created_at' => $this->db->nowUtcSql(),
        ];
        $this->db->insert('cpms_recommendations', $row);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * توصیه‌های ویزیت — E7/E12. نمای بیمار: فقط patient_visible (فیلتر Service).
     *
     * @return list<array<string, mixed>>
     */
    public function forVisit(int $visitId, ?bool $onlyPatientVisible = null): array
    {
        $where = 'visit_id = %d';
        $params = [$visitId];
        if ($onlyPatientVisible !== null) {
            $where .= ' AND is_patient_visible = %d';
            $params[] = $onlyPatientVisible ? 1 : 0;
        }

        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_recommendations') .
            ' WHERE ' . $where . ' ORDER BY id ASC',
            $params
        ) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forPatient(int $patientId, ?bool $onlyPatientVisible = null, int $limit = 100): array
    {
        $where = 'patient_id = %d';
        $params = [$patientId];
        if ($onlyPatientVisible !== null) {
            $where .= ' AND is_patient_visible = %d';
            $params[] = $onlyPatientVisible ? 1 : 0;
        }
        $params[] = $limit;

        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_recommendations') .
            ' WHERE ' . $where . ' ORDER BY id DESC LIMIT %d',
            $params
        ) ?: [];
    }
}
