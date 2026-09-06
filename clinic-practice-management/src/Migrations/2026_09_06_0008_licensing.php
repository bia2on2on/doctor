<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0008 — F10 Licensing local state (ADR-0023).
 *
 * cpms_license_install: شناسه‌ی نصب با آنتروپی بالا (هرگز فقط دامنه) —
 *                      در اولین بوت ساخته می‌شود (توسط LicenseService).
 * cpms_license_state : سند مجوزِ امضاشده‌ی محلی (فقط داده‌ی تجاریِ کنترل‌پلین؛
 *                      بدون هیچ PHI — ADR-0028).
 *
 * امنیت/رفتار:
 *  - همه‌ی مراحل Idempotent (CREATE TABLE IF NOT EXISTS + SHOW INDEX) —
 *    اجرای مجدد پس از شکست امن است.
 *  - down(): فقط DROP جدول‌های خود افزونه (مجوز «قفل» نیست؛ داده‌ی پزشکی
 *    در هیچ‌یک از این دو جدول نیست و DROP آنها به داده‌ی بالینی آسیب نمی‌زند).
 *    بازگشت این جدول‌ها = نصب دوباره باید دوباره فعال‌سازی شود (طبیعی).
 */
return [
    'version' => '2026_09_06_0008',
    'description' => 'F10 licensing local state: install identity + signed license doc',
    'up' => function (CpmsDb $db): void {
        $install = $db->table('cpms_license_install');
        $state = $db->table('cpms_license_state');

        $db->query("CREATE TABLE IF NOT EXISTS {$install} (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `install_id` CHAR(32) NOT NULL,
            `environment` VARCHAR(16) NOT NULL DEFAULT 'production',
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_lic_install_id` (`install_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // یک ردیفِ تکی (id=1) — سند جاری مجوز. «نداشتن ردیف» = فعال‌سازی نشده.
        $db->query("CREATE TABLE IF NOT EXISTS {$state} (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `license_id` VARCHAR(64) NOT NULL DEFAULT '',
            `install_id` CHAR(32) NOT NULL DEFAULT '',
            `payload_json` MEDIUMTEXT NULL,
            `signature_b64` VARCHAR(256) NULL,
            `verified_at` DATETIME(3) NULL,
            `last_refresh_attempt_at` DATETIME(3) NULL,
            `last_refresh_error` VARCHAR(255) NULL,
            `refresh_fail_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_lic_state_install` (`install_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
    'down' => function (CpmsDb $db): void {
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_license_state'));
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_license_install'));
    },
];
