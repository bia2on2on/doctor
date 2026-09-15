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
    public function insertDocument(int $clinic_id, array $row): int
    {
        $row += [
            'clinic_id' => $clinic_id,
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

    // ================= GC — جاروی W (Phase 2 M-2) =================

    /**
     * Phase 2 M-2 (W): شناسهٔ **همهٔ** Clinicهای نصب — برایِ جاروی
     * scope-neutral. ترتیبِ قطعی (ORDER BY id) تا ادامهٔ کار در فراخوانی‌های
     * بعدی پیش‌بینی‌پذیر باشد.
     *
     * @return list<int>
     */
    public function allClinicIds(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id FROM ' . $this->db->table('cpms_clinics') . ' ORDER BY id ASC'
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Phase 2 M-2 (W): انتخابِ صفحاتِ کاندیدای GC **تحتِ سیاستِ یک Clinicِ
     * مشخص** — مالکیتِ پر-ردیف از رابطهٔ دائمیِ
     * `versions.page_id → pages.document_id → documents.clinic_id`
     * (هرگز نه از Scope محیطی/کاربر/payload).
     *
     * یک صفحهٔ کاندیداست اگر حداقل یک نسخهٔ کهنه (created_at < cutoff) داشته
     * باشد که خارج از `keepLast` نسخهٔ آخرِ صفحه است (در وگرنه هیچ ردیفی
     * قابلِ حذف نیست). `LIMIT` واقعی همین‌جاست — کرانِ کران‌دارِ هر
     * فراخوانی (بدونِ OFFSET روی مجموعهٔ درحالِ تغییر، بدونِ cursor دائمی).
     *
     * @param int $clinicId مالکِ دائمیِ ردیف‌هایِ این انتخاب
     * @param string $cutoffSql حدِ سن (DATETIME(3) UTC)
     * @param int $keepLast سیاستِ `hw.version_keep`ِ خودِ همان Clinic
     * @param int $limit سهمِ این Clinic از کرانِ کارِ فراخوانی
     * @return list<int>
     */
    public function gcCandidatePages(int $clinicId, string $cutoffSql, int $keepLast, int $limit): array
    {
        $versions = $this->db->table('cpms_handwriting_page_versions');
        $rows = $this->db->fetchAll(
            'SELECT v.page_id FROM ' . $versions . ' v'
            . ' JOIN ' . $this->db->table('cpms_handwriting_pages') . ' p ON p.id = v.page_id'
            . ' JOIN ' . $this->db->table('cpms_handwriting_documents') . ' d ON d.id = p.document_id'
            . ' WHERE d.clinic_id = %d AND v.created_at < %s'
            . ' GROUP BY v.page_id'
            . ' HAVING MIN(v.version) <= (SELECT COALESCE(MAX(v2.version), 0) FROM ' . $versions
            . ' v2 WHERE v2.page_id = v.page_id) - %d'
            . ' ORDER BY v.page_id ASC'
            . ' LIMIT %d',
            [$clinicId, $cutoffSql, $keepLast, $limit]
        );

        return array_map('intval', array_column($rows, 'page_id'));
    }

    /**
     * Phase 2 M-2 (W): آخرین نسخهٔ یک صفحه — خوانشِ تدافعیِ تازهٔ به‌ازای
     * هر صفحه هنگامِ GC (حذف فقط بر مبنایِ همین مقدار انجام می‌شود).
     */
    public function maxVersionOfPage(int $pageId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COALESCE(MAX(version), 0) FROM ' . $this->db->table('cpms_handwriting_page_versions')
            . ' WHERE page_id = %d',
            [$pageId]
        );
    }

    /**
     * Phase 2 M-2 (W): حذفِ نسخه‌هایِ کهنهٔ **یک صفحه** تحتِ سیاستِ
     * Clinicِ مالکش — ADR-0009: فقط ردیف‌هایی حذف می‌شوند که **هر دو** شرط
     * را داشته باشند (کهنه‌تر از cutoff **و** خارج از `keepLast` نسخهٔ
     * آخر). نسخه‌های تازه هرگز حذف نمی‌شوند.
     *
     * @param string $cutoffSql حدِ سن (DATETIME(3) UTC)
     * @return int تعدادِ ردیف‌هایِ حذف‌شده
     */
    public function purgePageVersions(int $pageId, int $keepLast, string $cutoffSql): int
    {
        $maxVersion = $this->maxVersionOfPage($pageId);
        if ($maxVersion <= $keepLast) {
            return 0;
        }

        return $this->db->execute(
            'DELETE FROM ' . $this->db->table('cpms_handwriting_page_versions')
            . ' WHERE page_id = %d AND version <= %d AND created_at < %s',
            [$pageId, $maxVersion - $keepLast, $cutoffSql]
        );
    }
}
