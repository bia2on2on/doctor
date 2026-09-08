<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0010 — Phase 2 (M-01/M-02/M-02b — ADR-0031، AD-02/AD-03):
 *
 *  1) `cpms_organizations` — سازمان (لایهٔ اجباری Core؛ بدون مسیر NULL).
 *  2) Seed سازمان پیش‌فرض (نام از Clinic موجود — ردیف واقعی، نه مقدار کد).
 *  3) `cpms_clinics.organization_id` — سه‌مرحله‌ای (ADD NULL → UPDATE →
 *     MODIFY NOT NULL) + FK. هر Clinic دقیقاً به یک Organization تعلق دارد.
 *
 * Idempotent: CREATE IF NOT EXISTS + SHOW COLUMNS + وجودشناسی FK.
 */
return [
    'version' => '2026_09_09_0010',
    'description' => 'Phase 2: cpms_organizations + clinics.organization_id NOT NULL + FK (ADR-0031)',
    'up' => function (CpmsDb $db): void {
        $orgs = $db->table('cpms_organizations');
        $clinics = $db->table('cpms_clinics');

        // ---------- 1) جدول سازمان‌ها ----------
        $db->query("CREATE TABLE IF NOT EXISTS {$orgs} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(190) NOT NULL,
            `slug` VARCHAR(190) NOT NULL,
            `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_org_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ---------- 2) Seed سازمان پیش‌فرض (ردیف واقعی — AD-02 اجباری) ----------
        $existingOrg = $db->fetchRow("SELECT id FROM {$orgs} ORDER BY id LIMIT 1");
        if ($existingOrg === null) {
            $clinic = $db->fetchRow("SELECT name FROM {$clinics} ORDER BY id LIMIT 1");
            $orgName = is_array($clinic) && trim((string) $clinic['name']) !== ''
                ? trim((string) $clinic['name'])
                : 'سازمان پیش‌فرض';
            $now = $db->nowUtcSql();
            $db->insert('cpms_organizations', [
                'id' => 1,
                'name' => $orgName,
                'slug' => 'default',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $orgId = 1;
        } else {
            $orgId = (int) $existingOrg['id'];
        }

        // ---------- 3) clinics.organization_id — سه‌مرحله‌ای (NOT NULL) ----------
        $col = $db->fetchRow("SHOW COLUMNS FROM {$clinics} LIKE 'organization_id'");
        if ($col === null) {
            $db->query("ALTER TABLE {$clinics} ADD COLUMN `organization_id` BIGINT UNSIGNED NULL AFTER `timezone`");
        }
        $db->query("UPDATE {$clinics} SET organization_id = %d WHERE organization_id IS NULL", [$orgId]);

        $col = $db->fetchRow("SHOW COLUMNS FROM {$clinics} LIKE 'organization_id'");
        if ($col !== null && strtoupper((string) $col['Null']) === 'YES') {
            $db->query("ALTER TABLE {$clinics} MODIFY `organization_id` BIGINT UNSIGNED NOT NULL");
        }

        $fk = $db->fetchRow(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = 'fk_clinics_organization' AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$clinics]
        );
        if ((int) ($fk['n'] ?? 0) === 0) {
            $db->query(
                "ALTER TABLE {$clinics} ADD CONSTRAINT `fk_clinics_organization`
                 FOREIGN KEY (`organization_id`) REFERENCES {$orgs} (`id`)"
            );
        }
    },
    'down' => function (CpmsDb $db): void {
        $orgs = $db->table('cpms_organizations');
        $clinics = $db->table('cpms_clinics');

        $fk = $db->fetchRow(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = 'fk_clinics_organization' AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$clinics]
        );
        if ((int) ($fk['n'] ?? 0) > 0) {
            $db->query("ALTER TABLE {$clinics} DROP FOREIGN KEY `fk_clinics_organization`");
        }
        $col = $db->fetchRow("SHOW COLUMNS FROM {$clinics} LIKE 'organization_id'");
        if ($col !== null) {
            $db->query("ALTER TABLE {$clinics} DROP COLUMN `organization_id`");
        }
        $db->query("DROP TABLE IF EXISTS {$orgs}");
    },
];
