<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository یادداشت‌های بالینی + نسخه‌ها — ADR-0021 (فقط Data-Access).
 *
 * جداول: `cpms_clinical_notes` + `cpms_clinical_note_versions` (append-only، K-6).
 *
 * **FR-8.4 / TP-08 — Enforcement سطح Query:** همه متدهای خواندن، پارامتر
 * `?array $visibility` دارند؛ فراخواننده (Service) بر اساس نقش فیلتر را تعیین
 * می‌کند و Repository آن را داخل WHERE اعمال می‌کند — `doctor_private` هرگز
 * به Secretary/Patient نشت نمی‌کند (حتی اگر لایه‌های بالاتر اشتباه کنند).
 *
 * ایندکس‌ها: idx_note_visit (visit_id, category)، idx_note_patient
 * (patient_id, created_at)، idx_note_vis (patient_id, visibility).
 */
final class ClinicalNoteRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $now = $this->db->nowUtc();
        $row += [
            'clinic_id' => 1,
            'content_html' => null,
            'version' => 1,
            'correction_of_note_id' => null,
            'change_reason' => null,
            'is_archived' => 0,
            'updated_by_wp_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->db->insert('cpms_clinical_notes', $row);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = $this->db->nowUtcSql();
        $this->db->update('cpms_clinical_notes', $data, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_clinical_notes') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * Row Lock — Correction/نسخه‌بندی Race-safe (داخل Transaction).
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchRowForUpdate(
            'SELECT * FROM ' . $this->db->table('cpms_clinical_notes') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * یادداشت‌های یک ویزیت — با فیلتر Visibility (FR-8.4).
     *
     * @param list<string>|null $visibility null = همه (فقط Doctor/E7)
     * @return list<array<string, mixed>>
     */
    public function forVisit(int $visitId, ?array $visibility = null, bool $includeArchived = false): array
    {
        [$where, $params] = $this->visibilityWhere('visit_id = %d', [$visitId], $visibility, $includeArchived);

        return $this->fetchAllOrdered($where, $params);
    }

    /**
     * یادداشت‌های یک بیمار (پرونده/E7 + جستجو) — با فیلتر Visibility.
     *
     * @param list<string>|null $visibility
     * @return list<array<string, mixed>>
     */
    public function forPatient(int $patientId, ?array $visibility = null, ?int $limit = 200): array
    {
        [$where, $params] = $this->visibilityWhere('patient_id = %d', [$patientId], $visibility, false);
        $sql = 'SELECT * FROM ' . $this->db->table('cpms_clinical_notes') .
            ' WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT %d';
        $params[] = $limit ?? 200;

        return $this->db->fetchAll($sql, $params) ?: [];
    }

    /**
     * آیا ویزیت یادداشتی از category داده‌شده دارد؟ (Validation کامل‌شدن — FR-8.7)
     */
    public function visitHasCategory(int $visitId, string $category): bool
    {
        $n = $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_clinical_notes') .
            ' WHERE visit_id = %d AND category = %s AND is_archived = 0',
            [$visitId, $category]
        );

        return (int) $n > 0;
    }

    /**
     * جستجوی متن یادداشت‌ها (E18) — Visibility فیلتر می‌شود (Role-Aware).
     *
     * @param list<string>|null $visibility
     * @return list<array<string, mixed>>
     */
    public function search(int $clinicId, string $q, ?array $visibility, string $from, string $to, int $limit = 25): array
    {
        [$where, $params] = $this->visibilityWhere('clinic_id = %d', [$clinicId], $visibility, false);
        $sql = 'SELECT * FROM ' . $this->db->table('cpms_clinical_notes') .
            ' WHERE ' . $where . ' AND content_text LIKE %s' .
            ' AND created_at >= %s AND created_at < %s' .
            ' ORDER BY created_at DESC, id DESC LIMIT %d';
        $params[] = '%' . self::escapeLike($q) . '%';
        $params[] = $from . ' 00:00:00';
        $params[] = $to . ' 23:59:59.999';
        $params[] = $limit;

        return $this->db->fetchAll($sql, $params) ?: [];
    }

    // ================= نسخه‌ها (append-only — FR-8.5 / K-6) =================

    /**
     * Snapshot نسخه — هرگز Update/Delete نمی‌شود (K-6).
     *
     * @param array<string, mixed> $row
     */
    public function insertVersion(int $noteId, array $row): void
    {
        $row += [
            'note_id' => $noteId,
            'change_reason' => null,
            'created_at' => $this->db->nowUtcSql(),
        ];
        $this->db->insert('cpms_clinical_note_versions', $row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versionsFor(int $noteId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_clinical_note_versions') .
            ' WHERE note_id = %d ORDER BY version DESC, id DESC',
            [$noteId]
        ) ?: [];
    }

    // ================= Helpers =================

    /**
     * @param list<string> $params
     *
     * @return array{0: string, 1: list<string>}
     */
    private function visibilityWhere(string $baseWhere, array $params, ?array $visibility, bool $includeArchived): array
    {
        $where = $baseWhere;
        if ($visibility !== null) {
            $quoted = array_map(static fn (string $v): string => "'" . esc_sql($v) . "'", $visibility);
            $where .= ' AND visibility IN (' . implode(',', $quoted) . ')';
        }
        if (!$includeArchived) {
            $where .= ' AND is_archived = 0';
        }

        return [$where, $params];
    }

    /**
     * @param list<string> $params
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAllOrdered(string $where, array $params): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_clinical_notes') .
            ' WHERE ' . $where . ' ORDER BY created_at ASC, id ASC',
            $params
        ) ?: [];
    }

    /** LIKE wildcardهای کاربر را خنثی می‌کند (فرار کامل در prepare). */
    private static function escapeLike(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }
}
