<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Otp;

use DateTimeImmutable;

/**
 * وضعیت فعلی OTP برای یک (mobile, purpose) — خالص، بدون DB.
 */
final class OtpState
{
    /**
     * @param int $attempts         تلاش‌های نادرستِ متوالی (از آخر unlock)
     * @param DateTimeImmutable|null $lockedUntil قفل تا کِی (null = بدون قفل)
     * @param DateTimeImmutable|null $lastSentAt آخرین ارسال کد
     * @param int $sentToday         تعداد کدهای ارسالی امروز (بازه محلی کلینیک)
     */
    public function __construct(
        public int $attempts = 0,
        public ?DateTimeImmutable $lockedUntil = null,
        public ?DateTimeImmutable $lastSentAt = null,
        public int $sentToday = 0,
    ) {
    }

    /**
     * اگر قفل تمام شده باشد، شمارنده‌ها را برای دور جدید صفر می‌کند.
     */
    public function resetIfUnlocked(DateTimeImmutable $now): self
    {
        if ($this->lockedUntil !== null && $this->lockedUntil <= $now) {
            $this->lockedUntil = null;
            $this->attempts = 0;
        }

        return $this;
    }

    public function isLocked(DateTimeImmutable $now): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil > $now;
    }
}
