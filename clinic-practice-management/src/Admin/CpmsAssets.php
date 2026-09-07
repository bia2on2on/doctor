<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

/**
 * Assets اسکوپ‌شدهٔ صفحات CPMS (Chunk F).
 *
 * هدف عملکردی:
 *  - CSS/JS فقط وقتی لود می‌شود که صفحهٔ فعلی جزو صفحات CPMS است (allowlist)؛ به همین
 *    دلیل هیچ کندی سراسری در wp-admin و هیچ تداخلی با صفحات دیگر/افزونه‌ها وجود ندارد.
 *  - CSS با body.cpms-admin اسکوپ است؛ بنابراین استایل‌ها فقط صفحات CPMS را می‌گیرند.
 *  - بدون فراخوانی شبکه‌ای اضافه (فایل‌های محلی، بدون CDN/API) و بدون framework سنگین.
 *
 * این کلاس فقط «ثبت/انکیو assets» است و هیچ منطق business ندارد.
 */
final class CpmsAssets
{
    /** اسلاگ‌های معتبر صفحات CPMS (dashboard + wizard + مدیریتی + نقش‌محور). */
    private const PAGES = [
        'cpms-dashboard',
        'cpms-wizard',
        'cpms-staff',
        'cpms-clinicians',
        'cpms-roles',
        'cpms-system',
        'cpms-settings',
        'cpms-sms',
        'cpms-queue',
        'cpms-finance',
        'cpms-doctor',
        'cpms-handwriting',
        'cpms-prescription-print',
        'cpms-patient',
    ];

    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
    }

    /**
     * آیا اسلاگ صفحهٔ فعلی جزو صفحات CPMS (allowlist) است؟
     *
     * این متد فقط «کدام اسلاگ» را تشخیص می‌دهد؛ اینکه واقعاً در ناحیهٔ مدیریت هستیم
     * را خودِ هوک‌ها تضمین می‌کنند (admin_enqueue_scripts / admin_body_class فقط در
     * wp-admin اجرا می‌شوند). به همین دلیل گیت is_admin() این‌جا تکراری است و حذف
     * شده تا متد قابل تست باشد (در WP_UnitTestCase ثابت WP_ADMIN تعریف نشده و
     * is_admin() همیشه false برمی‌گرداند). مجوزهای backend در guard() خود صفحه‌ها
     * (current_user_can + nonce) اعمال می‌شوند و دست‌نخورده‌اند.
     */
    public static function isCpmsPage(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط تشخیص صفحهٔ نمایشی
        return $page !== '' && in_array($page, self::PAGES, true);
    }

    public static function enqueue(): void
    {
        if (!self::isCpmsPage()) {
            return;
        }
        $base = \defined('CPMS_PLUGIN_URL') && CPMS_PLUGIN_URL !== '' ? rtrim((string) CPMS_PLUGIN_URL, '/') : '';
        if ($base === '') {
            return;
        }

        wp_enqueue_style('cpms-admin', $base . '/assets/css/cpms-admin.css', [], \defined('CPMS_VERSION') ? CPMS_VERSION : 'dev');
        wp_enqueue_script('cpms-admin', $base . '/assets/js/cpms-admin.js', [], \defined('CPMS_VERSION') ? CPMS_VERSION : 'dev', true);
    }

    /** body class اسکوپِ CSS — فقط در صفحات CPMS. */
    public static function bodyClass(string $classes): string
    {
        if (!self::isCpmsPage()) {
            return $classes;
        }

        return trim($classes . ' cpms-admin');
    }
}
