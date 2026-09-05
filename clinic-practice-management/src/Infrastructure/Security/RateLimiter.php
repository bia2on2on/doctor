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

        $this->db->query(
            'INSERT INTO ' . $this->db->table('cpms_rate_limits') . ' (window_key, window_id, hits)
             VALUES (%s, %d, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$key, $windowId]
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
     */
    public function cleanup(int $olderThanSec = 86400): int
    {
        // window_id بر مبنای «ساعت» است (intdiv(ts, 3600)) — cutoff باید هم‌واحد باشد
        $cutoff = intdiv(time() - $olderThanSec, 3600);

        return $this->db->execute(
            'DELETE FROM ' . $this->db->table('cpms_rate_limits') . ' WHERE window_id < %d',
            [$cutoff]
        );
    }
}
