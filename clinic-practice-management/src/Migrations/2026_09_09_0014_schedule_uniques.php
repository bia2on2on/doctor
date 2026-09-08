<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;
use RuntimeException;

/**
 * Migration 0014 — Phase 2 (M-07 — نقاط برگشت‌ناپذیر):
 *
 *  1) `cpms_schedule`: `u_sched_day (clinician_id, day_of_week)`
 *     → `u_sched_slot (clinic_id, location_id, clinician_id, day_of_week, start_time)`
 *     ⇒ چند شعبه در یک روز و چند شیفت در یک شعبه ممکن می‌شود.
 *  2) `cpms_schedule_slots`: `u_slot (clinician_id, slot_date, slot_time)`
 *     → `u_slot (location_id, clinician_id, slot_date, slot_time)`
 *
 * ⚠️ تنها Migration واقعاً برگشت‌ناپذیر فاز ۲ — down() کلید قدیم را بازمی‌سازد
 * (فقط تا وقتی داده نقض نکرده باشد).
 *
 * ایمنی: Preflight تکرارها (الگوی 0007 — fail-loud، بدون تغییر داده)؛
 * چون کلیدهای جدید فوق‌مجموعهٔ کلیدهای قدیم‌اند، در حالت عادی تکراری وجود
 * ندارد؛ preflight صرفاً گارد است. Idempotent با SHOW INDEX.
 */
return [
    'version' => '2026_09_09_0014',
    'description' => 'Phase 2: location-scoped schedule/slot UNIQUE keys (irreversible point)',
    'up' => function (CpmsDb $db): void {
        $sched = $db->table('cpms_schedule');
        $slots = $db->table('cpms_schedule_slots');

        // ---------- Preflight (fail-loud) ----------
        $schedDups = $db->fetchAll(
            "SELECT clinic_id, location_id, clinician_id, day_of_week, start_time, COUNT(*) AS n
             FROM {$sched}
             GROUP BY clinic_id, location_id, clinician_id, day_of_week, start_time
             HAVING n > 1 LIMIT 5"
        );
        if (is_array($schedDups) && $schedDups !== []) {
            $detail = implode('; ', array_map(
                static fn (array $r): string => sprintf(
                    'clinic=%d loc=%d clinician=%d dow=%s start=%s x%d',
                    (int) $r['clinic_id'],
                    (int) $r['location_id'],
                    (int) $r['clinician_id'],
                    (string) $r['day_of_week'],
                    (string) $r['start_time'],
                    (int) $r['n']
                ),
                $schedDups
            ));
            throw new RuntimeException(
                "Migration 0014 aborted: duplicate rows under target u_sched_slot [{$detail}] — " .
                'resolve manually, then re-run. No data was changed.'
            );
        }

        $slotDups = $db->fetchAll(
            "SELECT location_id, clinician_id, slot_date, slot_time, COUNT(*) AS n
             FROM {$slots}
             GROUP BY location_id, clinician_id, slot_date, slot_time
             HAVING n > 1 LIMIT 5"
        );
        if (is_array($slotDups) && $slotDups !== []) {
            $detail = implode('; ', array_map(
                static fn (array $r): string => sprintf(
                    'loc=%d clinician=%d date=%s time=%s x%d',
                    (int) $r['location_id'],
                    (int) $r['clinician_id'],
                    (string) $r['slot_date'],
                    (string) $r['slot_time'],
                    (int) $r['n']
                ),
                $slotDups
            ));
            throw new RuntimeException(
                "Migration 0014 aborted: duplicate rows under target u_slot [{$detail}] — " .
                'resolve manually, then re-run. No data was changed.'
            );
        }

        // ---------- schedule ----------
        $hasOld = $db->fetchRow("SHOW INDEX FROM {$sched} WHERE Key_name = 'u_sched_day'");
        if ($hasOld !== null) {
            $db->query("ALTER TABLE {$sched} DROP INDEX `u_sched_day`");
        }
        $hasNew = $db->fetchRow("SHOW INDEX FROM {$sched} WHERE Key_name = 'u_sched_slot'");
        if ($hasNew === null) {
            $db->query(
                "ALTER TABLE {$sched} ADD UNIQUE KEY `u_sched_slot`
                 (`clinic_id`, `location_id`, `clinician_id`, `day_of_week`, `start_time`)"
            );
        }
        $hasLookup = $db->fetchRow("SHOW INDEX FROM {$sched} WHERE Key_name = 'idx_sched_lookup'");
        if ($hasLookup === null) {
            $db->query(
                "ALTER TABLE {$sched} ADD KEY `idx_sched_lookup` (`clinician_id`, `day_of_week`, `is_active`)"
            );
        }

        // ---------- schedule_slots ----------
        $hasOld = $db->fetchRow("SHOW INDEX FROM {$slots} WHERE Key_name = 'u_slot'");
        if ($hasOld !== null) {
            $db->query("ALTER TABLE {$slots} DROP INDEX `u_slot`");
        }
        $hasNew = $db->fetchRow("SHOW INDEX FROM {$slots} WHERE Key_name = 'u_slot'");
        if ($hasNew === null) {
            $db->query(
                "ALTER TABLE {$slots} ADD UNIQUE KEY `u_slot`
                 (`location_id`, `clinician_id`, `slot_date`, `slot_time`)"
            );
        }
    },
    'down' => function (CpmsDb $db): void {
        $sched = $db->table('cpms_schedule');
        $slots = $db->table('cpms_schedule_slots');

        $hasNew = $db->fetchRow("SHOW INDEX FROM {$sched} WHERE Key_name = 'u_sched_slot'");
        if ($hasNew !== null) {
            $db->query("ALTER TABLE {$sched} DROP INDEX `u_sched_slot`");
        }
        $hasOld = $db->fetchRow("SHOW INDEX FROM {$sched} WHERE Key_name = 'u_sched_day'");
        if ($hasOld === null) {
            $db->query("ALTER TABLE {$sched} ADD UNIQUE KEY `u_sched_day` (`clinician_id`, `day_of_week`)");
        }

        $hasNew = $db->fetchRow("SHOW INDEX FROM {$slots} WHERE Key_name = 'u_slot'");
        if ($hasNew !== null) {
            $db->query("ALTER TABLE {$slots} DROP INDEX `u_slot`");
        }
        $hasOld = $db->fetchRow("SHOW INDEX FROM {$slots} WHERE Key_name = 'u_slot'");
        if ($hasOld === null) {
            $db->query("ALTER TABLE {$slots} ADD UNIQUE KEY `u_slot` (`clinician_id`, `slot_date`, `slot_time`)");
        }
    },
];
