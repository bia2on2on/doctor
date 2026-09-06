<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * وضعیت مجوز — مدل رفتاری F10 (ADR-0023).
 *
 * ترتیب قطعی برای مقایسه «شدت»:
 *   ACTIVE < EXPIRING < GRACE < RESTRICTED < {INVALID, SUSPENDED, REVOKED}
 *
 * سازگاری با F3 (LicenseState ثابت‌ها حفظ شده): EXPIRED → RESTRICTED در
 * مدل جدید (نام‌گذاری رفتاری). THROTTLED = همه‌چیز مجاز است ولی refresh
 * با پالیسی عقب‌افتادگی اجرا شود (بدون نیاز به کاربر).
 */
final class LicenseStatus
{
    public const ACTIVE = LicenseState::ACTIVE; // 'active' (میراث F3 حفظ شد)
    public const EXPIRING = 'expiring'; // جدید F10 (LicenseState فاقد آن بود)
    public const GRACE = LicenseState::GRACE; // 'grace' (میراث F3 حفظ شد)
    public const RESTRICTED = 'restricted'; // جانشین رفتاری EXPIRED میراث F3
    public const SUSPENDED = 'suspended';
    public const REVOKED = 'revoked';
    public const INVALID = LicenseState::INVALID; // 'invalid' (میراث F3)
    public const UNREACHABLE = 'unreachable'; // جانشین UNKNOWN میراث F3
    public const THROTTLED = 'throttled';

    public const VALID = [
        self::ACTIVE,
        self::EXPIRING,
        self::GRACE,
        self::RESTRICTED,
        self::SUSPENDED,
        self::REVOKED,
        self::INVALID,
        self::UNREACHABLE,
        self::THROTTLED,
    ];

    /**
     * آیا اجازه «فعالیت تجاری مستقل جدید» (ثبت نوبت/ویزیت جدید/بیمار جدید/
     * فاکتور جدید/فعال‌سازی منابع جدید) داده می‌شود؟
     */
    public static function allowsNewBusiness(string $status): bool
    {
        return in_array($status, [self::ACTIVE, self::EXPIRING, self::GRACE], true);
    }

    /**
     * آیا سیستم در حالت Read-Only برای فعالیت جدید است؟ (خواندن/تاریخچه/Export آزاد).
     */
    public static function isRestricted(string $status): bool
    {
        return !self::allowsNewBusiness($status);
    }

    /**
     * حالت‌های «نهاییِ نیازمند اقدام انسانی/داده جدید» (همیشه با داده‌ی
     * امضاشده‌ی معتبر می‌آیند — نه صرف‌اً به‌خاطر قطع شبکه).
     */
    public static function isBlockingRenewal(string $status): bool
    {
        return in_array($status, [self::RESTRICTED, self::SUSPENDED, self::REVOKED, self::INVALID], true);
    }

    /**
     * ترتیب شدت — برای تصمیم‌گیری وقتی چند دلیل هم‌زمان داریم.
     *
     * @return int هرچه بیشتر = شدیدتر
     */
    public static function severity(string $status): int
    {
        return match ($status) {
            self::ACTIVE => 0,
            self::EXPIRING => 1,
            self::GRACE => 2,
            self::THROTTLED => 3,
            self::RESTRICTED => 4,
            self::UNREACHABLE => 5,
            self::INVALID => 6,
            self::SUSPENDED => 7,
            self::REVOKED => 8,
            default => 9,
        };
    }
}
