<?php

declare(strict_types=1);

namespace ClinicCore\Settings;

use InvalidArgumentException;
use RuntimeException;

/**
 * تنظیمات اسکالر سطح نصب (Phase 2) — `notif.archive_days` و
 * `retention.oplog_days`.
 *
 * تصمیم مصوب: purge سراسری اعلان‌ها یک sweep سطح نصب روی ردیف‌های اعلان
 * است؛ استفاده از تنظیمِ یک Clinic برای purge همهٔ Clinicها tenant-نادرست
 * است. پس `notif.archive_days` پیکربندی سطح نصب است و در wp_options
 * (با `autoload=no`) زندگی می‌کند — نه در `cpms_settings` و نه با هیچ
 * Clinic ساختگی/پیش‌فرض/جاری.
 *
 * M-2 (`cleanup.oplog`): همان منطق برای `retention.oplog_days` — Job در
 * `JobScopeRegistry` طبقهٔ **S** ثبت شده، جدولِ لاگِ عملیاتی ستونِ tenant
 * ندارد و حذف، سن‌محور و نصب‌گسترده است؛ پس پیکربندی هم سطح نصب است.
 * ⛔ این تصمیم به هیچ کلیدِ retention دیگری تعمیم داده نمی‌شود
 * (`notif.archive_days` قرارداد خودش را دارد و `hw.version_*`،
 * `retention.audit_years`/`record_years` و ثابت‌های `cleanup.idem`/
 * `cleanup.rate_limits` خارج از این دامنه‌اند). هیچ مقدارِ تاریخیِ
 * per-Clinic مهاجرت داده نمی‌شود.
 *
 * قواعد:
 *  - بدون Clinic/ScopeContext/کاربر/مستأجرِ درخواست — خواندن و نوشتن فقط
 *    به کلید Option سطح نصب متکی‌اند.
 *  - بدون SQL خام برای wp_options — فقط get/add/update_option وردپرس.
 *  - غایب بودن Option = پیش‌فرض مؤثر فعلی (۹۰)؛ مقدار خرابِ ذخیره‌شده =
 *    بازگشت امن به پیش‌فرض (گارد `is_numeric` — قرارداد مستقر پروژه).
 *  - این برش فقط انتزاع است؛ runtime فعلی (خواندن از Settings per-Clinic)
 *    دست‌نخورده می‌ماند تا تصمیم سیم‌کشیِ جداگانه.
 */
final class InstallationSettings
{
    /**
     * کلید Option وردپرس برای `notif.archive_days` سطح نصب.
     *
     * قرارداد نام‌گذاری مخزن: `cpms_` + snake_case (الگوی `cpms_otp_pepper`،
     * `cpms_role_caps_override` و `cpms_private_storage_migrated`).
     */
    public const OPTION_NOTIF_ARCHIVE_DAYS = 'cpms_notif_archive_days';

    /**
     * پیش‌فرض مؤثر فعلی — هم‌تراز `Settings::DEFAULTS['notif.archive_days']`
     * و fallback صریح `NotificationService::dispatchQueued`.
     */
    public const DEFAULT_NOTIF_ARCHIVE_DAYS = 90;

    /**
     * کلید Option وردپرس برای `retention.oplog_days` سطح نصب.
     *
     * قرارداد نام‌گذاری مخزن: `cpms_` + snake_case با حذف نقطه
     * (`notif.archive_days` → `cpms_notif_archive_days`؛
     * `retention.oplog_days` → `cpms_retention_oplog_days`).
     */
    public const OPTION_OPLOG_RETENTION_DAYS = 'cpms_retention_oplog_days';

    /**
     * پیش‌فرض مؤثر فعلی — هم‌تراز `Settings::DEFAULTS['retention.oplog_days']`
     * و fallback قبلیِ `OpLogCleanupHandler` (۹۰ روز)، بدون تغییر.
     */
    public const DEFAULT_OPLOG_RETENTION_DAYS = 90;

    /**
     * کف معتبر — هم‌تراز `max(1, …)` پیشینِ `OpLogCleanupHandler`.
     *
     * سقف عمداً تعریف نمی‌شود: قرارداد فعلی سقفی ندارد و «اختراع سقف»
     * مجاز نیست.
     */
    private const MIN_OPLOG_RETENTION_DAYS = 1;

    /**
     * کف معتبر — هم‌تراز `max(1, $days)` در
     * `NotificationRepository::purgeArchived`.
     */
    private const MIN_NOTIF_ARCHIVE_DAYS = 1;

    /** @var callable(string, mixed): mixed */
    private $readOption;

    /** @var callable(string, mixed): void */
    private $writeOption;

    /**
     * @param callable(string, mixed): mixed|null $readOption تزریق تست
     *     (الگوی `SystemHealthService::$now`)؛ پیش‌فرض: `get_option` واقعی.
     * @param callable(string, mixed): void|null $writeOption تزریق تست؛
     *     پیش‌فرض: `add_option`/`update_option` واقعی با `autoload=no`.
     */
    public function __construct(?callable $readOption = null, ?callable $writeOption = null)
    {
        $this->readOption = $readOption ?? static fn (string $key, mixed $default): mixed =>
            function_exists('get_option') ? get_option($key, $default) : $default;
        $this->writeOption = $writeOption ?? static function (string $key, mixed $value): void {
            if (!function_exists('add_option') || !function_exists('update_option')) {
                throw new RuntimeException('نوشتن تنظیم سطح نصب بدون وردپرس ممکن نیست.');
            }
            // الگوی cpms_otp_pepper: ساخت با autoload=no؛ اگر بود، به‌روزرسانی
            // (پارامتر سوم false یعنی اگر در مسابقه هم ساخته نشده بود، no بماند).
            if (!add_option($key, $value, '', 'no')) {
                update_option($key, $value, false);
            }
        };
    }

    /**
     * روزهای نگهداری اعلان‌های Internal — سطح نصب.
     *
     * غایب/غیرعددی/کمتر از ۱ → پیش‌فرض امن (۹۰). توجه: رفتار فعلیِ cast
     * کورِ `(int)` برای مقدار خراب به purge تهاجمی ۱روزه می‌رسید؛ این
     * انتزاع عمداً fail-safe است.
     */
    public function getNotifArchiveDays(): int
    {
        $raw = ($this->readOption)(self::OPTION_NOTIF_ARCHIVE_DAYS, self::DEFAULT_NOTIF_ARCHIVE_DAYS);
        if (!is_numeric($raw)) {
            return self::DEFAULT_NOTIF_ARCHIVE_DAYS;
        }
        $days = (int) $raw;

        return $days >= self::MIN_NOTIF_ARCHIVE_DAYS ? $days : self::DEFAULT_NOTIF_ARCHIVE_DAYS;
    }

    /**
     * @throws InvalidArgumentException اگر کمتر از ۱ روز باشد (fail-closed؛
     *     هیچ نوشته‌ای به ذخیره‌سازی نمی‌رسد).
     */
    public function setNotifArchiveDays(int $days): void
    {
        if ($days < self::MIN_NOTIF_ARCHIVE_DAYS) {
            throw new InvalidArgumentException('روزهای نگهداری اعلان باید دست‌کم ۱ باشد.');
        }
        ($this->writeOption)(self::OPTION_NOTIF_ARCHIVE_DAYS, $days);
    }

    /**
     * روزهای نگهداری لاگ عملیاتی — **سطح نصب** (M-2).
     *
     * غایب/غیرعددی/کمتر از ۱ → پیش‌فرض امن (۹۰ روز). مقدارِ خراب **هرگز**
     * به حذفِ تهاجمی‌تر تبدیل نمی‌شود و هیچ Clinic/Scope/کاربری خوانده نمی‌شود.
     */
    public function getOplogRetentionDays(): int
    {
        $raw = ($this->readOption)(self::OPTION_OPLOG_RETENTION_DAYS, self::DEFAULT_OPLOG_RETENTION_DAYS);
        if (!is_numeric($raw)) {
            return self::DEFAULT_OPLOG_RETENTION_DAYS;
        }
        $days = (int) $raw;

        return $days >= self::MIN_OPLOG_RETENTION_DAYS ? $days : self::DEFAULT_OPLOG_RETENTION_DAYS;
    }

    /**
     * @throws InvalidArgumentException اگر کمتر از ۱ روز باشد (fail-closed؛
     *     هیچ نوشته‌ای به ذخیره‌سازی نمی‌رسد).
     */
    public function setOplogRetentionDays(int $days): void
    {
        if ($days < self::MIN_OPLOG_RETENTION_DAYS) {
            throw new InvalidArgumentException('روزهای نگهداری لاگ عملیاتی باید دست‌کم ۱ باشد.');
        }
        ($this->writeOption)(self::OPTION_OPLOG_RETENTION_DAYS, $days);
    }
}
