<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0013 — Phase 2 (M-06 — AD-15):
 *
 * `location_id` برای ۴ جدول عملیاتی (schedule, schedule_slots, appointments,
 * visits) — **NOT NULL** با بک‌فیل به Location اصلیِ همان Clinic
 * (سه‌مرحله‌ای: ADD NULL → UPDATE → MODIFY NOT NULL → ADD FK).
 *
 * Scheduling/Appointment هرگز حالت «بدون Location» ندارد (AD-15).
 * Idempotent: SHOW COLUMNS + وجودشناسی FK/INDEX در information_schema.
 */
return [
    'version' => '2026_09_09_0013',
    'description' => 'Phase 2: location_id NOT NULL on schedule/slots/appointments/visits (AD-15)',
    'up' => function (CpmsDb $db): void {
        $tables = [
            'cpms_schedule' => 'fk_schedule_location',
            'cpms_schedule_slots' => 'fk_schedslot_location',
            'cpms_appointments' => 'fk_appt_location',
            'cpms_visits' => 'fk_visit_location',
        ];

        foreach (array_keys($tables) as $name) {
            $t = $db->table($name);

            $col = $db->fetchRow("SHOW COLUMNS FROM {$t} LIKE 'location_id'");
            if ($col === null) {
                $db->query("ALTER TABLE {$t} ADD COLUMN `location_id` BIGINT UNSIGNED NULL AFTER `clinic_id`");
            }

            // بک‌فیل deterministic: Location اصلی همان Clinic
            $db->query(
                "UPDATE {$t} SET location_id = (
                    SELECT l.id FROM " . $db->table('cpms_locations') . " l
                    WHERE l.clinic_id = {$t}.clinic_id AND l.is_primary = 1
                    ORDER BY l.id LIMIT 1
                 ) WHERE location_id IS NULL"
            );

            $col = $db->fetchRow("SHOW COLUMNS FROM {$t} LIKE 'location_id'");
            if ($col !== null && strtoupper((string) $col['Null']) === 'YES') {
                $db->query("ALTER TABLE {$t} MODIFY `location_id` BIGINT UNSIGNED NOT NULL");
            }
        }

        foreach ($tables as $name => $fkName) {
            $t = $db->table($name);
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

            $idx = $db->fetchRow(
                'SELECT COUNT(*) AS n FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
                [$t, 'idx_' . str_replace('cpms_', '', $name) . '_location']
            );
            if ((int) ($idx['n'] ?? 0) === 0) {
                $short = str_replace('cpms_', '', $name);
                $db->query("ALTER TABLE {$t} ADD KEY `idx_{$short}_location` (`location_id`)");
            }
        }
    },
    'down' => function (CpmsDb $db): void {
        $tables = [
            'cpms_schedule' => 'fk_schedule_location',
            'cpms_schedule_slots' => 'fk_schedslot_location',
            'cpms_appointments' => 'fk_appt_location',
            'cpms_visits' => 'fk_visit_location',
        ];
        foreach ($tables as $name => $fkName) {
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
