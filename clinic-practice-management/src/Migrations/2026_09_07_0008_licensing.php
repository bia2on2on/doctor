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
// آیا دادهٔ واقعیِ کسب‌وکارِ pre-F10 وجود دارد؟ (نصبِ قدیمی در حال ارتقا —
// برای انتخاب نوع پنجرهٔ فعال‌سازی: migration=۳۰ روز، fresh=۷ روز)
$legacyDataExists = static function (CpmsDb $db): bool {
    foreach (['cpms_patients', 'cpms_visits', 'cpms_appointments', 'cpms_settings', 'cpms_clinicians', 'cpms_audit_logs'] as $t) {
        try {
            $hit = $db->fetchValue('SELECT 1 FROM ' . $db->table($t) . ' LIMIT 1');
            if ($hit !== null && $hit !== false) {
                return true;
            }
        } catch (\Throwable) {
            // جدول موجود نیست — ادامه
        }
    }

    return false;
};

return [
    'version' => '2026_09_07_0008',
    'description' => 'F10 licensing local state: install identity + signed license doc + activation window',
    'up' => function (CpmsDb $db) use ($legacyDataExists): void {
        $install = $db->table('cpms_license_install');
        $state = $db->table('cpms_license_state');

        $db->query("CREATE TABLE IF NOT EXISTS {$install} (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `install_id` CHAR(32) NOT NULL,
            `environment` VARCHAR(16) NOT NULL DEFAULT 'production',
            `activation_window_started_at` DATETIME(3) NULL,
            `activation_window_type` VARCHAR(16) NOT NULL DEFAULT 'fresh',
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

        // ===== پنجرهٔ فعال‌سازی (تصمیم کارفرما) =====
        // زمان شروع پنجره همین‌جاست persist می‌شود (anti-reset: deactivate/
        // reactivate/reinstall هرگز آن را از نو شروع نمی‌کند؛ ON DUPLICATE فقط
        // updated_at را تازه می‌کند). نوع پنجره:
        //   fresh     → نصب تازه (۷ روز) — جدول‌های کسب‌وکار pre-F10 داده ندارند
        //   migration → نصب pre-F10 با دادهٔ واقعی (۳۰ روز مهلت مهاجرت)
        $now = $db->nowUtcSql();
        $type = $legacyDataExists($db) ? 'migration' : 'fresh';
        $installId = bin2hex(random_bytes(16));
        $db->query(
            'INSERT INTO ' . $install .
            ' (id, install_id, environment, activation_window_started_at, activation_window_type, created_at, updated_at)' .
            ' VALUES (1, %s, %s, %s, %s, %s, %s)' .
            ' ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
            [$installId, 'production', $now, $type, $now, $now]
        );
    },
    'down' => function (CpmsDb $db): void {
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_license_state'));
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_license_install'));
    },
];
