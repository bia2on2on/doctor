<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * Policy زمانی مجوز (V1 — ADR-0023 §3):
 *  - expiry_grace: پیش‌فرض 7 روز (F10 spec §16)
 *  - unreachable_grace: مهلت استفاده از آخرین وضعیت معتبرِ کش‌شده هنگام قطع
 *    شبکه — متمایز از invalid (spec §15)
 *  - activation_window_days: پنجرهٔ فعال‌سازی نصبِ تازه (تصمیم کارفرما —
 *    پیش‌فرض 7 روز؛ بدون سند معتبر → RESTRICTED)
 *  - migration_grace_days: مهلت مهاجرت نصب pre-F10 (تصمیم کارفرما —
 *    پیش‌فرض 30 روز؛ بدون سند معتبر → RESTRICTED)
 *  - renew_interval / throttle_interval: برای Job refresh با Backoff
 *
 * خالص (Pure): بدون WP/DB/شبکه — واحدتست بدون وابستگی.
 */
final class LicensePolicy
{
    public function __construct(
        public readonly int $expiryGraceDays = 7,
        public readonly int $unreachableGraceDays = 3,
        public readonly int $renewIntervalHours = 24,
        public readonly int $throttleIntervalHours = 6,
        public readonly int $maxClockSkewSeconds = 300,
        public readonly int $activationWindowDays = 7,
        public readonly int $migrationGraceDays = 30,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function expiryGraceSeconds(): int
    {
        return $this->expiryGraceDays * 86400;
    }

    public function unreachableGraceSeconds(): int
    {
        return $this->unreachableGraceDays * 86400;
    }

    public function renewIntervalSeconds(): int
    {
        return $this->renewIntervalHours * 3600;
    }

    public function throttleIntervalSeconds(): int
    {
        return $this->throttleIntervalHours * 3600;
    }

    public function activationWindowSeconds(): int
    {
        return $this->activationWindowDays * 86400;
    }

    public function migrationGraceSeconds(): int
    {
        return $this->migrationGraceDays * 86400;
    }
}
