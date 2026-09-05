<?php
/**
 * Migration 0003 — ماژول پیامک: جدول عملیاتی Messageها (ADR-0025).
 *
 * cpms_sms_messages:
 *  - Statusها: QUEUED/SENDING/SENT/DELIVERED/FAILED/RETRYING
 *  - dedupe_key: جلوگیری از ارسال تکراری (UNIQUE — فقط یک رکورد فعال/موفق در هر Context/روز)
 *  - vars_json: متغیرهای Template (برای Retry بدون بازسازی)
 *
 * تفریقی (Additive).
 */

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

return [
    'version' => '2026_09_05_0003',
    'description' => 'SMS module: messages log/queue table (ADR-0025)',
    'up' => function (CpmsDb $db): void {
        $t = $db->table('cpms_sms_messages');
        $db->query("CREATE TABLE IF NOT EXISTS {$t} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `event` VARCHAR(40) NOT NULL,
            `recipient` VARCHAR(20) NOT NULL,
            `message` TEXT NOT NULL,
            `vars_json` TEXT NULL,
            `provider` VARCHAR(40) NULL,
            `template_id` VARCHAR(80) NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'QUEUED',
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
            `provider_msg_id` VARCHAR(128) NULL,
            `failure_code` VARCHAR(64) NULL,
            `dedupe_key` CHAR(64) NULL,
            `context_type` VARCHAR(40) NULL,
            `context_id` BIGINT UNSIGNED NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dedupe` (`dedupe_key`),
            KEY `ix_status_updated` (`status`, `updated_at`),
            KEY `ix_event_created` (`event`, `created_at`),
            KEY `ix_context` (`context_type`, `context_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },

    'down' => function (CpmsDb $db): void {
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_sms_messages'));
    },
];
