<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0015 — Phase 2 (M-08 — د-۶-۲):
 *
 * `location_id` اختیاری (NULL) برای ۷ جدولی که رابطهٔ مکانی معنایی دارند:
 *   schedule_exceptions (NULL = همهٔ محل‌ها) · prescriptions (سربرگ چاپ) ·
 *   services (NULL = تعرفهٔ همهٔ محل‌ها) · invoices (تفکیک درآمد) ·
 *   payments (محل دریافت وجه) · audit_logs (زمینهٔ رویداد) ·
 *   settings (ستون راهبردی Override شعبه — resolution این فاز تغییر نمی‌کند؛
 *   UNIQUE فعلی (clinic_id, `key`) حفظ می‌شود).
 *
 * Idempotent: SHOW COLUMNS + information_schema.
 */
return [
    'version' => '2026_09_09_0015',
    'description' => 'Phase 2: nullable location_id on 7 semantically location-related tables',
    'up' => function (CpmsDb $db): void {
        $targets = [
            'cpms_schedule_exceptions' => 'fk_schedexc_location',
            'cpms_prescriptions' => 'fk_rx_location',
            'cpms_services' => 'fk_service_location',
            'cpms_invoices' => 'fk_invoice_location',
            'cpms_payments' => 'fk_payment_location',
            'cpms_audit_logs' => 'fk_audit_location',
            'cpms_settings' => 'fk_setting_location',
        ];

        foreach ($targets as $name => $fkName) {
            $t = $db->table($name);

            $col = $db->fetchRow("SHOW COLUMNS FROM {$t} LIKE 'location_id'");
            if ($col === null) {
                $db->query("ALTER TABLE {$t} ADD COLUMN `location_id` BIGINT UNSIGNED NULL AFTER `clinic_id`");
            }

            $fk = $db->fetchRow(
                "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
                   AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$t, $fkName]
            );
            if ((int) ($fk['n'] ?? 0) === 0) {
                $db->query(
                    "ALTER TABLE {$t} ADD CONSTRAINT `{$fkName}`
                     FOREIGN KEY (`location_id`) REFERENCES " . $db->table('cpms_locations') . " (`id`)"
                );
            }
        }
    },
    'down' => function (CpmsDb $db): void {
        $targets = [
            'cpms_schedule_exceptions' => 'fk_schedexc_location',
            'cpms_prescriptions' => 'fk_rx_location',
            'cpms_services' => 'fk_service_location',
            'cpms_invoices' => 'fk_invoice_location',
            'cpms_payments' => 'fk_payment_location',
            'cpms_audit_logs' => 'fk_audit_location',
            'cpms_settings' => 'fk_setting_location',
        ];
        foreach ($targets as $name => $fkName) {
            $t = $db->table($name);
            $fk = $db->fetchRow(
                "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
                   AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$t, $fkName]
            );
            if ((int) ($fk['n'] ?? 0) > 0) {
                $db->query("ALTER TABLE {$t} DROP FOREIGN KEY `{$fkName}`");
            }
            $col = $db->fetchRow("SHOW COLUMNS FROM {$t} LIKE 'location_id'");
            if ($col !== null) {
                $db->query("ALTER TABLE {$t} DROP COLUMN `location_id`");
            }
        }
    },
];
