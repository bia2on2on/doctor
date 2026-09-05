<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Sms;

/**
 * وضعیت پیام‌های SMS (ADR-0025، الزام §19).
 */
final class SmsMessageStatus
{
    public const QUEUED = 'QUEUED';
    public const SENDING = 'SENDING';
    public const SENT = 'SENT';
    public const DELIVERED = 'DELIVERED';
    public const FAILED = 'FAILED';
    public const RETRYING = 'RETRYING';

    /** وضعیت‌های پایانی — Retry مجدد انجام نمی‌شود. */
    public const TERMINAL = [self::SENT, self::DELIVERED, self::FAILED];

    /** وضعیت‌هایی که «در مسیر» هستند (برای Dedupe). */
    public const IN_FLIGHT = [self::QUEUED, self::SENDING, self::RETRYING];

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function isInFlight(string $status): bool
    {
        return in_array($status, self::IN_FLIGHT, true);
    }

    public static function isValid(string $status): bool
    {
        return in_array(
            $status,
            [self::QUEUED, self::SENDING, self::SENT, self::DELIVERED, self::FAILED, self::RETRYING],
            true
        );
    }
}
