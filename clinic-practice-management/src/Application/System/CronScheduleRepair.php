<?php

declare( strict_types=1 );

namespace ClinicCore\Application\System;

/**
 * CPMS Classic Admin UX (E) — ترمیمِ idempotentِ رویدادِ WP-Cron صف.
 *
 * مرزهای ایمنی (این کلاس فقط «زمان‌بندی» را تضمین می‌کند):
 *  - هیچ Jobی اجرا نمی‌کند: این کلاس هیچ مسیرِ اجرای صف یا handler را صدا
 *    نمی‌زند. بنابراین activation، رندرِ صفحهٔ سلامت و هر بازدیدِ پیشخوان
 *    نمی‌تواند به‌طورِ ناخواسته کارِ مخرب/مالی/پیامکی/طولانی راه بیندازد.
 *  - هیچ فایل/پیکربندیِ میزبان (wp-config.php، System Cron، پنل هاست) ویرایش
 *    نمی‌شود؛ فقط APIِ رسمیِ WordPress (`wp_next_scheduled`,
 *    `wp_schedule_event`) استفاده می‌شود.
 *  - idempotent: اگر رویداد ثبت شده باشد هیچ کاری انجام نمی‌شود.
 *  - بدونِ Scope/Clinic: صف سطح نصب است و کارگرِ سیستم Clinic محیطی ندارد.
 */
final class CronScheduleRepair {
    /** هوکِ تکرارشوندهٔ صف — همان رشته‌ای که App::boot/activate ثبت می‌کنند. */
    public const HOOK = 'cpms_jobs_tick';

    /** بازهٔ سفارشیِ ثبت‌شده در filter `cron_schedules`. */
    public const RECURRENCE = 'cpms_minute';

    /** transientِ throttleِ ترمیمِ خودکارِ سمتِ مدیریت. */
    private const AUTOREPAIR_THROTTLE = 'cpms_cron_autorepair_seen';

    /** فاصلهٔ بررسیِ خودکار (ثانیه) — بررسیِ ارزان و نه بیشتر از یک‌بار در ساعت. */
    private const AUTOREPAIR_INTERVAL = 3600;

    /**
     * آیا رویداد ثبت شده است؟`null` = قابل‌سنجش نیست (خارج از WordPress).
     */
    public static function is_scheduled(): ?bool {
        if ( ! function_exists( 'wp_next_scheduled' ) ) {
            return null;
        }

        return false !== wp_next_scheduled( self::HOOK );
    }

    /** زمانِ اجرای بعدی (timestamp) یا null. */
    public static function next_scheduled_at(): ?int {
        if ( ! function_exists( 'wp_next_scheduled' ) ) {
            return null;
        }
        $next = wp_next_scheduled( self::HOOK );
        if ( false === $next || ! is_numeric( $next ) ) {
            return null;
        }

        return (int) $next;
    }

    /** رویداد در همین فراخوانی ساخته شد. */
    public const RESULT_SCHEDULED = 'scheduled';

    /** رویداد از قبل موجود بود (کاری لازم نبود). */
    public const RESULT_PRESENT = 'present';

    /** تلاش انجام شد اما رویداد هنوز وجود ندارد — قابل‌تلاشِ دوباره. */
    public const RESULT_FAILED = 'failed';

    /** خارج از WordPress / API زمان‌بندی در دسترس نیست. */
    public const RESULT_UNSUPPORTED = 'unsupported';

    /**
     * تضمینِ وجودِ رویداد — idempotent و بدونِ اجرای هیچ Jobی.
     *
     * @return bool true اگر همین حالا زمان‌بندی شد؛ false اگر از قبل موجود بود
     *              یا امکانِ زمان‌بندی وجود ندارد.
     */
    public static function ensure_scheduled( ?int $now = null ): bool {
        return self::RESULT_SCHEDULED === self::repair( $now );
    }

    /**
     * نسخهٔ قابل‌تفکیکِ `ensure_scheduled()` — چهار نتیجهٔ متمایز.
     *
     * چرا لازم است: `ensure_scheduled()` نمی‌تواند «از قبل موجود بود» را از
     * «تلاش کردم و نشد» جدا کند. بدونِ این تفکیک، throttleِ خودترمیمی روی یک
     * **شکست** هم ست می‌شد و ترمیم تا یک ساعت بی‌صدا سرکوب می‌شد؛ لاگ هم فقط
     * وقتی نوشته می‌شد که ترمیم موفق باشد، پس شکست هرگز دیده نمی‌شد.
     *
     * @return self::RESULT_* یکی از چهار ثابت
     */
    public static function repair( ?int $now = null ): string {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
            return self::RESULT_UNSUPPORTED;
        }
        if ( false !== wp_next_scheduled( self::HOOK ) ) {
            return self::RESULT_PRESENT;
        }
        $now = null !== $now ? $now : time();

        // همان بازهٔ موجود: یک دقیقه پس از «اکنون» — بدونِ اجرای فوری.
        wp_schedule_event( $now + 60, self::RECURRENCE, self::HOOK );

        return false !== wp_next_scheduled( self::HOOK ) ? self::RESULT_SCHEDULED : self::RESULT_FAILED;
    }

    /**
     * ترمیمِ خودکارِ سمتِ مدیریت — ارزان، throttle‌شده و فقط در wp-admin.
     *
     * چرا throttle: WP-Cron در هر بازدیدِ پیشخوان یک `wp_next_scheduled()` است
     * (خواندنِ Optionِ autoload‌شدهٔ `cron`)؛ برای پرهیز از هر کارِ تکراری روی
     * هر درخواستِ مدیریتی، حداکثر یک‌بار در `AUTOREPAIR_INTERVAL` بررسی می‌شود.
     *
     * @return bool true اگر در همین فراخوانی ترمیم شد.
     */
    public static function self_heal_in_admin(): bool {
        return self::RESULT_SCHEDULED === self::self_heal_in_admin_result();
    }

    /**
     * نسخهٔ قابل‌تفکیکِ خودترمیمیِ سمتِ مدیریت.
     *
     * قاعدهٔ throttle: فقط وقتی «حالتِ خوبِ شناخته‌شده» داریم — یعنی رویداد
     * موجود است یا در همین فراخوانی ساخته شد — نشانگر ست می‌شود. اگر تلاش
     * شکست بخورد یا API در دسترس نباشد، **نشانگر ست نمی‌شود** تا درخواستِ
     * مدیریتیِ بعدی دوباره تلاش کند (خطای پایدار نباید تا ابد کش شود).
     *
     * @return self::RESULT_* یکی از چهار ثابت؛ خارج از wp-admin ⇒ RESULT_UNSUPPORTED
     */
    public static function self_heal_in_admin_result(): string {
        if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
            return self::RESULT_UNSUPPORTED;
        }
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return self::RESULT_UNSUPPORTED;
        }
        if ( function_exists( 'get_transient' ) && false !== get_transient( self::AUTOREPAIR_THROTTLE ) ) {
            return self::RESULT_PRESENT;
        }

        $result = self::repair();

        if ( function_exists( 'set_transient' )
            && ( self::RESULT_SCHEDULED === $result || self::RESULT_PRESENT === $result ) ) {
            set_transient( self::AUTOREPAIR_THROTTLE, 1, self::AUTOREPAIR_INTERVAL );
        }

        return $result;
    }

    /** توضیحِ فارسی و صادقانهٔ روشِ زمان‌بندی — برای نمایش در UI. */
    public static function scheduler_explanation(): string {
        $disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

        if ( $disabled ) {
            return 'DISABLE_WP_CRON فعال است؛ اجرای صف به System Cron میزبان سپرده شده است (ADR-0016).'
                . ' افزونه هیچ زمان‌بند یا فایلِ میزبان را تغییر نمی‌دهد.';
        }

        return 'WP-Cron بدونِ زمان‌بندِ خارجی فقط با ترافیکِ سایت اجرا می‌شود: تا وقتی بازدیدکننده یا بازدیدِ پیشخوان نباشد،'
            . ' اجرای صف ممکن است به تأخیر بیفتد. برای اجرای قطعی، System Cron با `DISABLE_WP_CRON` تنظیم کنید (ADR-0016).'
            . ' افزونه هیچ فایلِ میزبان (wp-config.php، cron سیستم، پنل هاست) را تغییر نمی‌دهد.';
    }
}
