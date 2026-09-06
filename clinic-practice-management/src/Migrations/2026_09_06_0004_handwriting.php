<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0004 — دست‌خط (F7):
 *  - `cpms_handwriting_pages.background_attachment_id` → Annotation روی تصویر
 *    (FR-9.2): تصویر پس‌زمینه = پیوست پزشکی موجود (E16)؛ FK RESTRICT.
 *  - ایندکس `idx_hwversion_created` روی `cpms_handwriting_page_versions`
 *    برای جاب پاک‌سازی روزانه (handwriting.gc — سیاست نگهداری ADR-0009).
 *
 * تفریقی (Additive) + Idempotent.
 */

return [
    'version' => '2026_09_06_0004',
    'description' => 'Handwriting (F7): page background attachment + GC index',
    'up' => function (CpmsDb $db): void {
        $pages = $db->table('cpms_handwriting_pages');
        $attachments = $db->table('cpms_medical_attachments');
        $versions = $db->table('cpms_handwriting_page_versions');

        $cols = $db->fetchRow("SHOW COLUMNS FROM {$pages} LIKE 'background_attachment_id'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($cols === null) {
            $db->query(
                "ALTER TABLE {$pages}" .
                " ADD COLUMN `background_attachment_id` BIGINT UNSIGNED NULL AFTER `background_template`," .
                " ADD CONSTRAINT `fk_hwpage_bg` FOREIGN KEY (`background_attachment_id`)" .
                " REFERENCES {$attachments} (`id`) ON DELETE SET NULL"
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $idx = $db->fetchRow("SHOW INDEX FROM {$versions} WHERE Key_name = 'idx_hwversion_created'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($idx === null) {
            $db->query("ALTER TABLE {$versions} ADD INDEX `idx_hwversion_created` (`created_at`)"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    },

    'down' => function (CpmsDb $db): void {
        // Additive — در محیط توسعه کافی است؛ Production: Restore از Backup.
    },
];
