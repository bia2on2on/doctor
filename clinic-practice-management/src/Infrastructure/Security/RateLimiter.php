<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Security;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Rate Limit با Window ثابت و اتمیک (INSERT ... ON DUPLICATE KEY UPDATE).
 *
 * جدول cpms_rate_limits (جدول زیرساختی #37 — گزارش F1).
 * کلیدها: 'otp:{mobile}', 'otp-ip:{ip}', 'booking:{userId}', 'login:{ip}', 'upload:{userId}', ...
 */
final class RateLimiter
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return array{allowed: bool, remaining: int, reset_at: int}
     */
    public function hit(string $key, int $maxPerWindow, int $windowSec): array
    {
        $maxPerWindow = max(1, $maxPerWindow);
        $windowSec = max(1, $windowSec);
        $windowId = intdiv(time(), $windowSec);
        $resetAt = ($windowId + 1) * $windowSec;

        // window_sec هم‌ردیف ذخیره می‌شود تا cleanup مستقل از واحد پنجره
        // عمل کند (F1-1)؛ در UPDATE هم self-heal می‌شود (ردیف‌های قدیمی).
        $this->db->query(
            'INSERT INTO ' . $this->db->table('cpms_rate_limits') . ' (window_key, window_id, window_sec, hits)
             VALUES (%s, %d, %d, 1)
             ON DUPLICATE KEY UPDATE
                 window_sec = VALUES(window_sec),
                 hits = hits + 1',
            [$key, $windowId, $windowSec]
        );

        $hits = (int) $this->db->fetchValue(
            'SELECT hits FROM ' . $this->db->table('cpms_rate_limits') . ' WHERE window_key = %s AND window_id = %d',
            [$key, $windowId]
        );

        return [
            'allowed' => $hits <= $maxPerWindow,
            'remaining' => max(0, $maxPerWindow - $hits),
            'reset_at' => $resetAt,
        ];
    }

    /**
     * پاک‌سازی پنجره‌های قدیمی (Job روزانه).
     *
     * F1-1: window_id در واحدِ windowSecِ هر limiter محاسبه می‌شود (نه ساعت
     * ثابت)، پس cutoff باید مستقل از واحد باشد: شروع پنجره
     * (window_id * window_sec) باید قبل از (now - olderThanSec) باشد.
     */
    public function cleanup(int $olderThanSec = 86400): int
    {
        $cutoffTs = time() - max(1, $olderThanSec);

        return $this->db->execute(
            'DELETE FROM ' . $this->db->table('cpms_rate_limits') . ' WHERE window_id * window_sec < %d',
            [$cutoffTs]
        );
    }
}
