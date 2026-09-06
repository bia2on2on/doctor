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
 *
 * حالت‌های «پیش از فعال‌سازی» (تصمیم کارفرما — نباید با هم ادغام شوند):
 *  - NOT_CONFIGURED      : دفاعی — ردیف نصب/پنجره موجود نیست (قبل از Migration
 *                          یا محیط ناقص). باز، ولی Health/Admin برجسته می‌کند.
 *  - ACTIVATION_PENDING  : نصب تازه — پنجرهٔ فعال‌سازی (پیش‌فرض ۷ روز).
 *                          Setup/فعالیت راه‌اندازی مجاز؛ پایان پنجره بدون سند
 *                          معتبر → RESTRICTED.
 *  - ACTIVATION_GRACE    : نصب pre-F10 که به licensing جدید مهاجرت کرده —
 *                          مهلت ۳۰ روزه (بدون اختلال ناگهانی برای مشتری موجود).
 *  - DEVELOPMENT         : حالت توسعه/تست صریح (فقط constant/filter مستند —
 *                          بدون تشخیص خودکار محیط؛ بدون unlock مخفی).
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

    public const NOT_CONFIGURED = 'not_configured';
    public const ACTIVATION_PENDING = 'activation_pending';
    public const ACTIVATION_GRACE = 'activation_grace';
    public const DEVELOPMENT = 'development';

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
        self::NOT_CONFIGURED,
        self::ACTIVATION_PENDING,
        self::ACTIVATION_GRACE,
        self::DEVELOPMENT,
    ];

    /**
     * وضعیت‌های «پیش از داشتن سند مجوزِ معتبر» (فعال‌سازی لازم است) —
     * برای سیاست‌هایی مثل دسترسی به به‌روزرسانی در دورهٔ Setup/توسعه.
     */
    public const PRE_ACTIVATION = [
        self::NOT_CONFIGURED,
        self::ACTIVATION_PENDING,
        self::ACTIVATION_GRACE,
    ];

    /**
     * آیا اجازه «فعالیت تجاری مستقل جدید» (ثبت نوبت/ویزیت جدید/بیمار جدید/
     * فاکتور جدید/فعال‌سازی منابع جدید) داده می‌شود؟
     *
     * NOT_CONFIGURED/ACTIVATION_PENDING/ACTIVATION_GRACE (پیش از فعال‌سازی و
     * داخل پنجره) = مجاز ولی «فریادِ» Setup؛ ایمنی بیمار و یکپارچگی داده هرگز
     * به‌خاطر فعال‌سازی‌نشدن قفل نمی‌شود (الویت §1) — Health/Admin برجسته
     * نشان می‌دهد و پایان پنجره بدون سند = RESTRICTED (تصمیم کارفرما).
     * DEVELOPMENT = صریح و مستند (constant/filter) — هرگز خودکار.
     */
    public static function allowsNewBusiness(string $status): bool
    {
        return in_array($status, [
            self::ACTIVE,
            self::EXPIRING,
            self::GRACE,
            self::NOT_CONFIGURED,
            self::ACTIVATION_PENDING,
            self::ACTIVATION_GRACE,
            self::DEVELOPMENT,
        ], true);
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
            self::NOT_CONFIGURED => 0,
            self::ACTIVATION_PENDING => 0,
            self::ACTIVATION_GRACE => 0,
            self::DEVELOPMENT => 0,
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
