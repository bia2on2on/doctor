<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0011 — Phase 2 (M-03/M-04 — ADR-0031، AD-08/AD-15/P2-D2):
 *
 *  1) `cpms_locations` — شعبه؛ `timezone NOT NULL` (منبع حقیقت عملیاتی زمان).
 *  2) Seed «Location اصلی» برای هر Clinic موجود با قاعدهٔ deterministic مالک
 *     (P2-D2):
 *       الف) `setup.clinic.timezone` اگر موجود و IANA-valid
 *       ب) وگرنه `cpms_clinics.timezone` اگر معتبر
 *       ج) وگرنه `Asia/Tehran`
 *     اگر هر دو منبع معتبر ولی متفاوت باشند: یکی sync نمی‌شود؛ واگرایی در
 *     Operational Log گزارش می‌شود (level=warning، PHASE2_TZ_DIVERGENCE).
 *
 * Idempotent: CREATE IF NOT EXISTS + پرش Clinicهایی که Location دارند.
 */
return [
    'version' => '2026_09_09_0011',
    'description' => 'Phase 2: cpms_locations (timezone NOT NULL) + primary-location seed per clinic (P2-D2)',
    'up' => function (CpmsDb $db): void {
        $locations = $db->table('cpms_locations');

        $db->query("CREATE TABLE IF NOT EXISTS {$locations} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `name` VARCHAR(190) NOT NULL,
            `slug` VARCHAR(190) NOT NULL,
            `address` VARCHAR(255) NULL,
            `phone` VARCHAR(32) NULL,
            `timezone` VARCHAR(64) NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_location_slug` (`clinic_id`, `slug`),
            KEY `idx_location_active` (`clinic_id`, `is_active`),
            CONSTRAINT `fk_locations_clinic` FOREIGN KEY (`clinic_id`)
                REFERENCES {$db->table('cpms_clinics')} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $validTz = static function (string $tz): bool {
            return $tz !== '' && in_array($tz, timezone_identifiers_list(), true);
        };

        $clinics = $db->fetchAll('SELECT id, name, timezone FROM ' . $db->table('cpms_clinics') . ' ORDER BY id');
        foreach ($clinics as $clinic) {
            $clinicId = (int) $clinic['id'];

            $already = $db->fetchRow(
                'SELECT id FROM ' . $locations . ' WHERE clinic_id = %d LIMIT 1',
                [$clinicId]
            );
            if ($already !== null) {
                continue;
            }

            // P2-D2 — منبع اول: setting ویزارد (قصد صریح اپراتور)
            $settingRaw = $db->fetchValue(
                'SELECT value_json FROM ' . $db->table('cpms_settings') .
                ' WHERE clinic_id = %d AND `key` = %s LIMIT 1',
                [$clinicId, 'setup.clinic.timezone']
            );
            $settingTz = '';
            if (is_string($settingRaw) && $settingRaw !== '') {
                $decoded = json_decode($settingRaw, true);
                if (is_string($decoded)) {
                    $settingTz = $decoded;
                }
            }
            $columnTz = (string) $clinic['timezone'];

            $chosen = $validTz($settingTz)
                ? $settingTz
                : ($validTz($columnTz) ? $columnTz : 'Asia/Tehran');

            if ($validTz($settingTz) && $validTz($columnTz) && $settingTz !== $columnTz) {
                $db->insert('cpms_operational_logs', [
                    'level' => 'warning',
                    'message' => 'PHASE2_TZ_DIVERGENCE: setting و ستون Clinic هر دو معتبر ولی متفاوت‌اند؛ طبق قاعدهٔ P2-D2 مقدار setting انتخاب شد و هیچ سمتی sync نشد.',
                    'context_json' => json_encode([
                        'clinic_id' => $clinicId,
                        'setting_timezone' => $settingTz,
                        'column_timezone' => $columnTz,
                        'chosen' => $chosen,
                        'rule' => 'P2-D2: setting > column > Asia/Tehran',
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $db->nowUtcSql(),
                ]);
            }

            $now = $db->nowUtcSql();
            $db->insert('cpms_locations', [
                'clinic_id' => $clinicId,
                'name' => 'محل اصلی',
                'slug' => 'main',
                'timezone' => $chosen,
                'is_primary' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    },
    'down' => function (CpmsDb $db): void {
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_locations'));
    },
];
