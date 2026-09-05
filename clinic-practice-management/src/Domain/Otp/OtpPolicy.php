<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Otp;

use DateTimeImmutable;

/**
 * سیاست OTP — مطابق docs/security/auth-authorization.md (ADR-0006).
 *
 * TTL, Max Attempts, Cooldown, Daily Max, Lockout — همه خالص و قابل تست.
 * هش کد: فقط در این مکان (SHA-256(code + pepper))؛ کد خام هرگز ذخیره/لاگ نمی‌شود.
 */
final class OtpPolicy
{
    public const REASON_OK = 'ok';
    public const REASON_COOLDOWN = 'cooldown';
    public const REASON_DAILY_LIMIT = 'daily_limit';
    public const REASON_LOCKED = 'locked';

    public function __construct(
        private readonly int $ttlSeconds = 300,
        private readonly int $maxAttempts = 5,
        private readonly int $cooldownSeconds = 60,
        private readonly int $dailyMaxCodes = 3,
        private readonly int $lockoutSeconds = 900,
    ) {
    }

    /**
     * آیا می‌توان کد جدید ارسال کرد؟
     *
     * @return array{ok: bool, reason: string}
     */
    public function canSend(OtpState $state, DateTimeImmutable $now): array
    {
        $state->resetIfUnlocked($now);

        if ($state->isLocked($now)) {
            return ['ok' => false, 'reason' => self::REASON_LOCKED];
        }
        if ($state->sentToday >= $this->dailyMaxCodes) {
            return ['ok' => false, 'reason' => self::REASON_DAILY_LIMIT];
        }
        if ($state->lastSentAt !== null && $now->getTimestamp() - $state->lastSentAt->getTimestamp() < $this->cooldownSeconds) {
            return ['ok' => false, 'reason' => self::REASON_COOLDOWN];
        }

        return ['ok' => true, 'reason' => self::REASON_OK];
    }

    /**
     * ثبت ارسال موفق.
     */
    public function registerSend(OtpState $state, DateTimeImmutable $now): OtpState
    {
        $state->lastSentAt = $now;
        $state->sentToday += 1;

        return $state;
    }

    /**
     * ثبت تلاش نادرست — در صورت رسیدن به حد، قفل.
     */
    public function registerFailedAttempt(OtpState $state, DateTimeImmutable $now): OtpState
    {
        $state->resetIfUnlocked($now);
        $state->attempts += 1;

        if ($state->attempts >= $this->maxAttempts) {
            $state->lockedUntil = $now->modify('+' . $this->lockoutSeconds . ' seconds');
        }

        return $state;
    }

    public function isCodeExpired(DateTimeImmutable $codeIssuedAt, DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $codeIssuedAt->getTimestamp() >= $this->ttlSeconds;
    }

    public function remainingAttempts(OtpState $state): int
    {
        return max(0, $this->maxAttempts - $state->attempts);
    }

    /**
     * کد را تولید می‌کند (CSPRNG) — همیشه دقیقاً $length رقم.
     */
    public static function generateCode(int $length = 6): string
    {
        $max = (int) str_repeat('9', $length);

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    /**
     * هش کد برای ذخیره — SHA-256(code + pepper). مقایسه با hash_equals در لایه Infra.
     */
    public static function hashCode(string $code, string $pepper): string
    {
        return hash('sha256', $code . $pepper);
    }
}
