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
final class CronScheduleRepair
{
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

    /**
     * تضمینِ وجودِ رویداد — idempotent و بدونِ اجرای هیچ Jobی.
     *
     * @return bool true اگر همین حالا زمان‌بندی شد؛ false اگر از قبل موجود بود
     *              یا امکانِ زمان‌بندی وجود ندارد.
     */
    public static function ensure_scheduled( ?int $now = null ): bool {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
            return false;
        }
        if ( false !== wp_next_scheduled( self::HOOK ) ) {
            return false;
        }
        $now = null !== $now ? $now : time();

        // همان بازهٔ موجود: یک دقیقه پس از «اکنون» — بدونِ اجرای فوری.
        wp_schedule_event( $now + 60, self::RECURRENCE, self::HOOK );

        return false !== wp_next_scheduled( self::HOOK );
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
        if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
            return false;
        }
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return false;
        }
        if ( function_exists( 'get_transient' ) && false !== get_transient( self::AUTOREPAIR_THROTTLE ) ) {
            return false;
        }
        if ( function_exists( 'set_transient' ) ) {
            set_transient( self::AUTOREPAIR_THROTTLE, 1, self::AUTOREPAIR_INTERVAL );
        }

        return self::ensure_scheduled();
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
