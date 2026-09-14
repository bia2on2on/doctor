<?php

declare(strict_types=1);

namespace ClinicCore\Settings;

use InvalidArgumentException;
use RuntimeException;

/**
 * تنظیمات اسکالر سطح نصب (Phase 2) — `notif.archive_days`,
 * `retention.oplog_days` و پیکربندی/حالتِ عملیاتیِ بکاپِ سطح نصب
 * (`backup.enabled`, `backup.interval_hours`, `backup.keep_count`,
 * `backup.storage_path`, `backup.last_run_at`).
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
 *
 * M-2 (`backup.run`): بکاپِ `cpms_*` نصب‌گسترده است (نه per-Clinic) و Job آن
 * در `JobScopeRegistry` طبقهٔ **S** ثبت شده. پس چهار کلیدِ پیکربندیِ بکاپ
 * (`enabled`, `interval_hours`, `keep_count`, `storage_path`) و یک کلیدِ
 * حالتِ عملیاتیِ `last_run_at` هم سطح نصب هستند و در wp_options زندگی می‌کنند.
 * `last_run_at` حالتِ عملیاتی است، نه پیکربندیِ کاربر — تفکیکِ معنایی در
 * accessorها صریح است (config vs operational-state). هیچ مقدارِ تاریخیِ
 * per-Clinic مهاجرت داده نمی‌شود (استفاده از اولین Clinic، Clinic 1،
 * clinic_id=0 یا merge مقادیر متضاد ممنوع).
 *
 * قواعد:
 *  - بدون Clinic/ScopeContext/کاربر/مستأجرِ درخواست — خواندن و نوشتن فقط
 *    به کلید Option سطح نصب متکی‌اند.
 *  - بدون SQL خام برای wp_options — فقط get/add/update_option وردپرس.
 *  - غایب بودن Option = پیش‌فرض مؤثر فعلی؛ مقدار خرابِ ذخیره‌شده =
 *    بازگشت امن به پیش‌فرض (گارد `is_numeric`/`is_bool` — قرارداد مستقر پروژه).
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

    // ------------------------------------------------------------------
    // M-2 backup.run — پیکربندی سطح نصب (۴ کلید) + حالت عملیاتی (۱ کلید)
    // ------------------------------------------------------------------

    /** @var string کلید Option برای backup.enabled — پیش‌فرض false (خاموش، فعال‌سازی آگاهانه) */
    public const OPTION_BACKUP_ENABLED = 'cpms_backup_enabled';
    public const DEFAULT_BACKUP_ENABLED = false;

    /** @var string کلید Option برای backup.interval_hours — پیش‌فرض 24، بازه 1..168 (هم‌تراز SystemPage) */
    public const OPTION_BACKUP_INTERVAL_HOURS = 'cpms_backup_interval_hours';
    public const DEFAULT_BACKUP_INTERVAL_HOURS = 24;
    private const MIN_BACKUP_INTERVAL_HOURS = 1;
    private const MAX_BACKUP_INTERVAL_HOURS = 168;

    /** @var string کلید Option برای backup.keep_count — پیش‌فرض 14، بازه 1..365 */
    public const OPTION_BACKUP_KEEP_COUNT = 'cpms_backup_keep_count';
    public const DEFAULT_BACKUP_KEEP_COUNT = 14;
    private const MIN_BACKUP_KEEP_COUNT = 1;
    private const MAX_BACKUP_KEEP_COUNT = 365;

    /** @var string کلید Option برای backup.storage_path — پیش‌فرض '' = ریشه خصوصی بیرون DocumentRoot */
    public const OPTION_BACKUP_STORAGE_PATH = 'cpms_backup_storage_path';
    public const DEFAULT_BACKUP_STORAGE_PATH = '';

    /** @var string کلید Option برای backup.last_run_at — حالت عملیاتی، پیش‌فرض 0 */
    public const OPTION_BACKUP_LAST_RUN_AT = 'cpms_backup_last_run_at';
    public const DEFAULT_BACKUP_LAST_RUN_AT = 0;
    private const MIN_BACKUP_LAST_RUN_AT = 0;

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

    // ================= Backup — پیکربندی سطح نصب (۴ کلید) =================

    /**
     * آیا بکاپ دوره‌ای فعال است — سطح نصب.
     *
     * پیش‌فرض: false (خاموش — فعال‌سازی آگاهانه در Admin/CLI).
     * غایب → false؛ مقدار خراب (غیرقابل تفسیر) → false (fail-safe).
     */
    public function getBackupEnabled(): bool
    {
        $raw = ($this->readOption)(self::OPTION_BACKUP_ENABLED, self::DEFAULT_BACKUP_ENABLED);
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw !== 0;
        }
        if (is_string($raw)) {
            $lower = strtolower(trim($raw));
            if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return self::DEFAULT_BACKUP_ENABLED;
    }

    public function setBackupEnabled(bool $enabled): void
    {
        ($this->writeOption)(self::OPTION_BACKUP_ENABLED, $enabled ? 1 : 0);
    }

    /**
     * فاصلهٔ بکاپ دوره‌ای (ساعت) — سطح نصب.
     *
     * پیش‌فرض: 24؛ بازهٔ معتبر: 1..168 (هم‌تراز SystemPage).
     * غایب/غیرعددی/خارج بازه → پیش‌فرض امن 24.
     */
    public function getBackupIntervalHours(): int
    {
        $raw = ($this->readOption)(self::OPTION_BACKUP_INTERVAL_HOURS, self::DEFAULT_BACKUP_INTERVAL_HOURS);
        if (!is_numeric($raw)) {
            return self::DEFAULT_BACKUP_INTERVAL_HOURS;
        }
        $hours = (int) $raw;
        if ($hours < self::MIN_BACKUP_INTERVAL_HOURS || $hours > self::MAX_BACKUP_INTERVAL_HOURS) {
            return self::DEFAULT_BACKUP_INTERVAL_HOURS;
        }

        return $hours;
    }

    /**
     * @throws InvalidArgumentException اگر خارج بازه 1..168 باشد (fail-closed).
     */
    public function setBackupIntervalHours(int $hours): void
    {
        if ($hours < self::MIN_BACKUP_INTERVAL_HOURS || $hours > self::MAX_BACKUP_INTERVAL_HOURS) {
            throw new InvalidArgumentException('فاصلهٔ بکاپ باید بین ۱ تا ۱۶۸ ساعت باشد.');
        }
        ($this->writeOption)(self::OPTION_BACKUP_INTERVAL_HOURS, $hours);
    }

    /**
     * تعداد نسخه‌های نگهداری‌شده — سطح نصب.
     *
     * پیش‌فرض: 14؛ بازهٔ معتبر: 1..365 (هم‌تراز SystemPage).
     * غایب/غیرعددی/خارج بازه → پیش‌فرض امن 14.
     */
    public function getBackupKeepCount(): int
    {
        $raw = ($this->readOption)(self::OPTION_BACKUP_KEEP_COUNT, self::DEFAULT_BACKUP_KEEP_COUNT);
        if (!is_numeric($raw)) {
            return self::DEFAULT_BACKUP_KEEP_COUNT;
        }
        $keep = (int) $raw;
        if ($keep < self::MIN_BACKUP_KEEP_COUNT || $keep > self::MAX_BACKUP_KEEP_COUNT) {
            return self::DEFAULT_BACKUP_KEEP_COUNT;
        }

        return $keep;
    }

    /**
     * @throws InvalidArgumentException اگر خارج بازه 1..365 باشد.
     */
    public function setBackupKeepCount(int $keep): void
    {
        if ($keep < self::MIN_BACKUP_KEEP_COUNT || $keep > self::MAX_BACKUP_KEEP_COUNT) {
            throw new InvalidArgumentException('تعداد نگهداری بکاپ باید بین ۱ تا ۳۶۵ باشد.');
        }
        ($this->writeOption)(self::OPTION_BACKUP_KEEP_COUNT, $keep);
    }

    /**
     * مسیر ذخیره‌سازی بکاپ — سطح نصب.
     *
     * پیش‌فرض: '' = ریشهٔ خصوصی بیرون DocumentRoot (OD-7/OD-9).
     * غایب → ''؛ مقدار خراب (غیر رشته) → ''.
     */
    public function getBackupStoragePath(): string
    {
        $raw = ($this->readOption)(self::OPTION_BACKUP_STORAGE_PATH, self::DEFAULT_BACKUP_STORAGE_PATH);
        if (!is_string($raw)) {
            return self::DEFAULT_BACKUP_STORAGE_PATH;
        }

        return trim($raw);
    }

    public function setBackupStoragePath(string $path): void
    {
        ($this->writeOption)(self::OPTION_BACKUP_STORAGE_PATH, trim($path));
    }

    // ================= Backup — حالت عملیاتی سطح نصب (۱ کلید) =================

    /**
     * آخرین زمان اجرای موفق بکاپ (timestamp) — **حالت عملیاتی سطح نصب**، نه پیکربندی.
     *
     * این مقدار توسط سیستم و به‌تکرار نوشته می‌شود (هر بکاپ موفق) و جدا در
     * Operational Log ثبت می‌شود؛ ورودش به Audit جدول ۱۰ساله = سیل رکورد.
     * پس در `InstallationSettings` به‌عنوان accessor عملیاتی صریح نگهداری می‌شود،
     * نه به‌عنوان پیکربندی کاربر.
     *
     * پیش‌فرض: 0 (هنوز اجرا نشده)؛ غایب/غیرعددی/منفی → 0.
     */
    public function getBackupLastRunAt(): int
    {
        $raw = ($this->readOption)(self::OPTION_BACKUP_LAST_RUN_AT, self::DEFAULT_BACKUP_LAST_RUN_AT);
        if (!is_numeric($raw)) {
            return self::DEFAULT_BACKUP_LAST_RUN_AT;
        }
        $ts = (int) $raw;

        return $ts >= self::MIN_BACKUP_LAST_RUN_AT ? $ts : self::DEFAULT_BACKUP_LAST_RUN_AT;
    }

    /**
     * ثبت زمان آخرین بکاپ موفق — حالت عملیاتی.
     *
     * @throws InvalidArgumentException اگر منفی باشد (fail-closed).
     */
    public function setBackupLastRunAt(int $timestamp): void
    {
        if ($timestamp < self::MIN_BACKUP_LAST_RUN_AT) {
            throw new InvalidArgumentException('زمان آخرین بکاپ نمی‌تواند منفی باشد.');
        }
        ($this->writeOption)(self::OPTION_BACKUP_LAST_RUN_AT, $timestamp);
    }
}
