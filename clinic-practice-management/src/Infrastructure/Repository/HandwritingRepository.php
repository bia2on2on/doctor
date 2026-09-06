<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository دست‌خط (cpms_handwriting_documents / _pages / _page_versions)
 * — F7 / F1–F3 / ADR-0009 (یک صفحه = یک Row؛ Strokeها JSON فشرده).
 */
final class HandwritingRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    // ================= Documents =================

    /**
     * @param array<string, mixed> $row
     */
    public function insertDocument(array $row): int
    {
        $row += [
            'clinic_id' => 1,
            'title' => null,
            'page_count' => 0,
            'created_at' => $this->db->nowUtcSql(),
            'updated_at' => $this->db->nowUtcSql(),
        ];
        if (!$this->db->insert('cpms_handwriting_documents', $row)) {
            throw new \RuntimeException('cpms_handwriting_documents insert failed');
        }

        return $this->db->wpdb_last_insert_id();
    }

    public function updateDocument(int $id, array $data): void
    {
        $data['updated_at'] = $this->db->nowUtcSql();
        $this->db->update('cpms_handwriting_documents', $data, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDocument(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_handwriting_documents') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * آخرین Document ویزیت (UI: باز کردن مجدد همان سند).
     *
     * @return array<string, mixed>|null
     */
    public function latestDocumentForVisit(int $visitId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_handwriting_documents') .
            ' WHERE visit_id = %d ORDER BY id DESC LIMIT 1',
            [$visitId]
        );
    }

    // ================= Pages =================

    /**
     * @param array<string, mixed> $row
     */
    public function insertPage(array $row): int
    {
        $row += [
            'stroke_data' => '',
            'stroke_count' => 0,
            'preview_png' => null,
            'preview_pdf' => null,
            'background_template' => 'lined',
            'background_attachment_id' => null,
            'client_revision' => 0,
            'last_saved_at' => null,
            'version' => 1,
            'updated_at' => $this->db->nowUtcSql(),
        ];
        if (!$this->db->insert('cpms_handwriting_pages', $row)) {
            throw new \RuntimeException('cpms_handwriting_pages insert failed');
        }

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * ذخیره صفحه = یک UPDATE (ADR-0009 — NFR-PERF-4).
     *
     * @param array<string, mixed> $data
     */
    public function updatePage(int $id, array $data): void
    {
        $data['updated_at'] = $this->db->nowUtcSql();
        $this->db->update('cpms_handwriting_pages', $data, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPage(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_handwriting_pages') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pagesForDocument(int $documentId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_handwriting_pages') .
            ' WHERE document_id = %d ORDER BY page_index ASC',
            [$documentId]
        ) ?: [];
    }

    // ================= Versions (append-only — K-6) =================

    /**
     * @param array<string, mixed> $row
     */
    public function insertVersion(array $row): void
    {
        $row += ['created_at' => $this->db->nowUtcSql()];
        $this->db->insert('cpms_handwriting_page_versions', $row);
    }

    /**
     * شمار نسخه‌های یک صفحه (سیاست نگهداری ADR-0009).
     */
    public function countVersions(int $pageId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_handwriting_page_versions') . ' WHERE page_id = %d',
            [$pageId]
        );
    }

    /**
     * پاک‌سازی نسخه‌های قدیمی (handwriting.gc): قدیمی‌تر از maxAgeDays
     * **و** فراتر از keep آخرین نسخه — نسخه‌های تازه هرگز حذف نمی‌شوند.
     *
     * دو مرحله‌ای: صفحات دارای نسخه قدیمی (ایندکس created_at) → DELETE به‌ازای صفحه.
     */
    public function purgeOldVersions(int $keepLast, int $maxAgeDays): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $maxAgeDays * 86400) . '.000';

        $pageRows = $this->db->fetchAll(
            'SELECT DISTINCT page_id FROM ' . $this->db->table('cpms_handwriting_page_versions') .
            ' WHERE created_at < %s',
            [$cutoff]
        );

        $deleted = 0;
        foreach (array_column($pageRows, 'page_id') as $pid) {
            $pid = (int) $pid;
            $maxVersion = (int) $this->db->fetchValue(
                'SELECT MAX(version) FROM ' . $this->db->table('cpms_handwriting_page_versions') . ' WHERE page_id = %d',
                [$pid]
            );
            if ($maxVersion <= $keepLast) {
                continue;
            }
            $deleted += $this->db->execute(
                'DELETE FROM ' . $this->db->table('cpms_handwriting_page_versions') .
                ' WHERE page_id = %d AND version <= %d AND created_at < %s',
                [$pid, $maxVersion - $keepLast, $cutoff]
            );
        }

        return $deleted;
    }
}
