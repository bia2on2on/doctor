<?php
/**
 * 2026-09-07 — Migration 0009: cpms_rate_limits.window_sec
 *
 * Reason (finding F1-1):
 *   RateLimiter::cleanup() computed its cutoff in the HOUR unit
 *   (intdiv(time() - olderThanSec, 3600)), while window_id is computed in the
 *   unit of each limiter's own windowSec. Consequences:
 *     - the live daily OTP window (windowSec=86400) was deleted on every
 *       run → the daily OTP attempt limit was ineffective, and
 *     - small-window rows (60 s) were never deleted → cpms_rate_limits
 *       grew unbounded.
 *   The fix stores the window duration next to each row so cleanup can
 *   compute expiry independently of the window unit:
 *       window_id * window_sec  <  (now - olderThanSec)
 *
 * Compatibility:
 *  - additive only (ADD COLUMN); no data rewrite.
 *  - existing rows get window_sec = 3600 — exactly the unit the old code
 *    assumed, so behavior is neutral for legacy rows (treated as hour
 *    windows, as before).
 *  - RateLimiter::hit() now writes the true window_sec on every insert and
 *    self-heals it on update, so rows created before this migration are
 *    corrected on first reuse.
 *
 * Idempotency: guarded SHOW COLUMNS probe.
 */

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

return [
    'version' => '2026_09_07_0009',
    'description' => 'cpms_rate_limits: add window_sec (unit-agnostic cleanup, F1-1)',
    'up' => function (CpmsDb $db): void {
        $table = $db->table('cpms_rate_limits');
        // probe با fetchRow (query() فقط bool برمی‌گرداند — با آن، شرط
        // «ستون موجود است» همیشه برقرار می‌شد و ALTER هرگز اجرا نمی‌شد).
        $col = $db->fetchRow("SHOW COLUMNS FROM {$table} LIKE 'window_sec'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($col === null) {
            $db->query(
                "ALTER TABLE {$table}
                 ADD COLUMN window_sec INT UNSIGNED NOT NULL DEFAULT 3600"
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    },
    'down' => function (CpmsDb $db): void {
        // Deliberate no-op: dropping the column would lose the window duration
        // and re-break unit-agnostic cleanup. The column is safe to keep.
    },
];
