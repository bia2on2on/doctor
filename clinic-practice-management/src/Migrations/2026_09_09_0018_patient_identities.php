<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0018 — Phase 2 (M-15 — AD-14):
 *
 * `cpms_patient_identities` — هویت بیمار در سطح **Organization**:
 *  - هر Identity یک internal_ref تغییرناپذیر دارد (کلید هویت؛ موبایل فقط
 *    صفت lookup است، نه کلید — AD-14).
 *  - Clinical Patient Record در سطح Clinic ایزوله می‌ماند
 *    (`cpms_patients.clinic_id` دست‌نخورده)؛ `patients.identity_id` اختیاری
 *    است و رکورد بالینی را بین کلینیک‌ها به‌اشتراک نمی‌گذارد.
 *  - هیچ ستون هش‌شده‌ای در این Migration ساخته نمی‌شود (مکانیزم هش lookup =
 *    تصمیم باز مالک؛ در صورت ساخت باید HMAC کلیددار باشد — B-17).
 *  - Seed نمی‌شود (هویت‌ها با primitiveهای سرویس و به‌صورت صریح ساخته می‌شوند).
 */
return [
    'version' => '2026_09_09_0018',
    'description' => 'Phase 2: organization-level patient identities (AD-14) + patients.identity_id',
    'up' => function (CpmsDb $db): void {
        $identities = $db->table('cpms_patient_identities');
        $patients = $db->table('cpms_patients');

        $db->query("CREATE TABLE IF NOT EXISTS {$identities} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` BIGINT UNSIGNED NOT NULL,
            `internal_ref` VARCHAR(40) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_identity_ref` (`internal_ref`),
            KEY `idx_identity_org` (`organization_id`),
            CONSTRAINT `fk_identity_organization` FOREIGN KEY (`organization_id`)
                REFERENCES " . $db->table('cpms_organizations') . " (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $col = $db->fetchRow("SHOW COLUMNS FROM {$patients} LIKE 'identity_id'");
        if ($col === null) {
            $db->query("ALTER TABLE {$patients} ADD COLUMN `identity_id` BIGINT UNSIGNED NULL AFTER `clinic_id`");
        }

        $fk = $db->fetchRow(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = 'fk_patients_identity' AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$patients]
        );
        if ((int) ($fk['n'] ?? 0) === 0) {
            $db->query(
                "ALTER TABLE {$patients} ADD CONSTRAINT `fk_patients_identity`
                 FOREIGN KEY (`identity_id`) REFERENCES {$identities} (`id`) ON DELETE SET NULL"
            );
        }
    },
    'down' => function (CpmsDb $db): void {
        $identities = $db->table('cpms_patient_identities');
        $patients = $db->table('cpms_patients');

        $fk = $db->fetchRow(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = 'fk_patients_identity' AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$patients]
        );
        if ((int) ($fk['n'] ?? 0) > 0) {
            $db->query("ALTER TABLE {$patients} DROP FOREIGN KEY `fk_patients_identity`");
        }
        $col = $db->fetchRow("SHOW COLUMNS FROM {$patients} LIKE 'identity_id'");
        if ($col !== null) {
            $db->query("ALTER TABLE {$patients} DROP COLUMN `identity_id`");
        }
        $db->query("DROP TABLE IF EXISTS {$identities}");
    },
];
