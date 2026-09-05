<?php
/**
 * Migration 0002 — مدت قابل تنظیم + Buffer (تصمیم کارفرما F1-D4 / ADR-0017).
 *
 * - cpms_appointments: + duration_min / slot_end_time (Snapshot در زمان Booking — تغییر
 *   تنظیمات بعدی NEVER اثر After-the-fact ندارد).
 * - cpms_schedule: + buffer_pre_min / buffer_post_min (آماده V2؛ V1: NULL = غیرفعال).
 * - cpms_services: + duration_min (لایه Override خدمات).
 *
 * تفریقی (Additive) — بدون Redesign.
 */

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * @return bool
 */
function cpms_m0002_column_exists(CpmsDb $db, string $shortTable, string $column): bool
{
    $full = $db->table('cpms_' . $shortTable);
    $count = $db->fetchValue(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
        [$full, $column]
    );

    return (int) $count > 0;
}

return [
    'version' => '2026_09_05_0002',
    'description' => 'Appointment duration resolution + buffers (ADR-0017)',
    'up' => function (CpmsDb $db): void {
        // ---- appointments: snapshot مدت/پایان ----
        if (!cpms_m0002_column_exists($db, 'appointments', 'duration_min')) {
            $appts = $db->table('cpms_appointments');
            $db->query("ALTER TABLE {$appts}
                ADD COLUMN `duration_min` SMALLINT UNSIGNED NOT NULL DEFAULT 20 AFTER `slot_time`,
                ADD COLUMN `slot_end_time` TIME NOT NULL DEFAULT '00:00:00' AFTER `duration_min`");
        }

        // Backfill از Slot (یک‌باره — فقط رکوردهای بدون مقدار)
        $db->query(
            'UPDATE ' . $db->table('cpms_appointments') . ' a
             JOIN ' . $db->table('cpms_schedule_slots') . ' s ON s.id = a.slot_id
             SET a.duration_min = s.duration_min,
                 a.slot_end_time = ADDTIME(a.slot_time, SEC_TO_TIME(s.duration_min * 60))
             WHERE a.duration_min = 20 AND a.slot_end_time = \'00:00:00\''
        );

        // ---- schedule: buffers (آماده V2) ----
        if (!cpms_m0002_column_exists($db, 'schedule', 'buffer_pre_min')) {
            $db->query(
                'ALTER TABLE ' . $db->table('cpms_schedule') . "
                ADD COLUMN `buffer_pre_min` SMALLINT UNSIGNED NULL AFTER `slot_capacity`,
                ADD COLUMN `buffer_post_min` SMALLINT UNSIGNED NULL AFTER `buffer_pre_min`"
            );
        }

        // ---- services: override مدت ----
        if (!cpms_m0002_column_exists($db, 'services', 'duration_min')) {
            $db->query(
                'ALTER TABLE ' . $db->table('cpms_services') .
                ' ADD COLUMN `duration_min` SMALLINT UNSIGNED NULL AFTER `price`'
            );
        }
    },

    'down' => function (CpmsDb $db): void {
        $pairs = [
            ['appointments', 'slot_end_time'],
            ['appointments', 'duration_min'],
            ['schedule', 'buffer_post_min'],
            ['schedule', 'buffer_pre_min'],
            ['services', 'duration_min'],
        ];
        foreach ($pairs as [$table, $column]) {
            if (cpms_m0002_column_exists($db, $table, $column)) {
                $db->query('ALTER TABLE ' . $db->table('cpms_' . $table) . " DROP COLUMN `{$column}`");
            }
        }
    },
];
