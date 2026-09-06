<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0005 — اعلان (F8):
 *  - `cpms_notifications.read_at` → مدیریت read/unread اعلان‌های Internal
 *    (notifications.md §5 — «Archived بعد از 90 روز (read/unread)»).
 *  - ایندکس `idx_notif_patient` روی (recipient_patient_id, status, created_at)
 *    برای Inbox بیمار و Real-time badge (R2) بدون Scan.
 *
 * تفریقی (Additive) + Idempotent.
 */

return [
    'version' => '2026_09_07_0005',
    'description' => 'Notifications (F8): read_at + patient recipient index',
    'up' => function (CpmsDb $db): void {
        $notifications = $db->table('cpms_notifications');

        $cols = $db->fetchRow("SHOW COLUMNS FROM {$notifications} LIKE 'read_at'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($cols === null) {
            $db->query(
                "ALTER TABLE {$notifications}" .
                " ADD COLUMN `read_at` DATETIME(3) NULL AFTER `delivered_at`"
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $idx = $db->fetchRow("SHOW INDEX FROM {$notifications} WHERE Key_name = 'idx_notif_patient'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($idx === null) {
            $db->query(
                "ALTER TABLE {$notifications} ADD INDEX `idx_notif_patient` (`recipient_patient_id`, `status`, `created_at`)"
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    },
];
