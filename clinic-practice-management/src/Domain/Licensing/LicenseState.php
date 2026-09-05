<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * حالت‌های License — ADR-0023 (F10) | Seam از F3.
 *
 * قاعده سفت (Baseline §21): Server Unreachable ≠ Invalid.
 * وضعیت `unknown` یعنی «سرور لایسنس پاسخ نداد» — داده‌های موجود Read-Only
 * می‌مانند و داده جدید حذف/تخریب نمی‌شود.
 */
final class LicenseState
{
    public const ACTIVE = 'active';
    public const GRACE = 'grace';       // منقضی اما در Grace Period (Read-Only)
    public const EXPIRED = 'expired';   // منقضی + خارج Grace (Read-Only)
    public const INVALID = 'invalid';   // کلید نامعتبر/غیرفعال (Read-Only)
    public const UNKNOWN = 'unknown';   // Server Unreachable ≠ Invalid (Read-Only موقت)

    /**
     * آیا سیستم در حالت Read-Only است؟ (فعالیت کسب‌وکار جدید مسدود، خوندن/Export آزاد).
     */
    public static function isReadOnly(string $state): bool
    {
        return in_array($state, [self::GRACE, self::EXPIRED, self::INVALID, self::UNKNOWN], true);
    }
}
