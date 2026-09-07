<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;

/**
 * منوی Top-Level منسجم «مدیریت مطب» + فعالیت‌های IA.
 *
 * اهداف:
 * - یک منوی Top-Level واحد برای صفحات مدیریتی/فنی می‌سازد (در برابر صفحات پراکنده Tools/Settings).
 * - صفحاتی که قبلاً در Tools یا Settings بودند به زیر همین منو منتقل می‌شوند (slug حفظ می‌شود؛
 *   مسیرهای قدیمی از طریق redirectLegacy به صفحه جدید هدایت می‌شوند).
 * - نقش‌ها بر اساس Capability (نه hard-code) منو را می‌بینند؛ «پنهان‌کردن منو ≠ authorize».
 * - منوهای نقش‌محور موجود (دکتر / منشی / بیمار) دست‌نخورده می‌مانند تا کار نقش خودشان را انجام دهند.
 */
final class CpmsAdminMenu
{
    public const MENU_SLUG = 'cpms';

    /**
     * صفحات مدیریتی/فنی که قبلاً در Tools/Settings پراکنده بودند و باید زیر «مدیریت مطب» بیایند.
     *
     * @var array<string, string> slug => title
     */
    private const REHOME = [
        'cpms-system' => 'سلامت سیستم',
        'cpms-settings' => 'فنی و لاگ',
        'cpms-clinicians' => 'پزشکان و برنامه کاری',
        'cpms-roles' => 'کاربران و دسترسی‌ها',
        'cpms-sms' => 'پیامک و اعلان‌ها',
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 1);
        // Legacy redirect باید در admin_menu اجرا شود نه admin_init: چون wp-admin/menu.php
        // در انتهای خود user_can_access_admin_page() را اجرا می‌کند و اگر صفحه اصلاً در
        // menu ثبت نشده باشد wp_die(403) می‌دهد — این کار قبل از admin_init اتفاق می‌افتد.
        // بنابراین redirectLegacy باید در admin_menu (قبل از آن بررسی) بتواند مسیر قدیمی را
        // به «مدیریت مطب» هدایت کند وگرنه مدیر به جای محتوا خطای 403 می‌بیند.
        add_action('admin_menu', [self::class, 'redirectLegacy'], 5);
        add_filter('plugin_action_links_' . self::basename(), [self::class, 'actionLinks']);
        add_action('admin_notices', [self::class, 'onboardingNotice']);
    }

    public static function basename(): string
    {
        return plugin_basename(defined('CPMS_PLUGIN_FILE') ? CPMS_PLUGIN_FILE : __FILE__);
    }

    public static function menu(): void
    {
        // Top-Level «مدیریت مطب» — صفحه پیش‌فرض (landing) = داشبورد.
        add_menu_page(
            'مدیریت مطب',
            'مدیریت مطب',
            RolesAndCapabilities::CONFIG,
            CpmsDashboard::PAGE_SLUG,
            [CpmsDashboard::class, 'render'],
            'dashicons-building',
            3
        );

        // زیرمنوی «داشبورد» با همان slug → کلیک روی آیتم Top-Level به داشبورد می‌رود
        // و WordPress آیتم تکراری نمی‌سازد.
        add_submenu_page(
            CpmsDashboard::PAGE_SLUG,
            'داشبورد',
            'داشبورد',
            RolesAndCapabilities::CONFIG,
            CpmsDashboard::PAGE_SLUG,
            [CpmsDashboard::class, 'render']
        );
    }

    /**
     * والد منو برای زیرمنوهای «مدیریت مطب» (چهارمین آرگومان add_submenu_page).
     */
    public static function parentSlug(): string
    {
        return CpmsDashboard::PAGE_SLUG;
    }

    /**
     * Redirect قدیمی (backward-compat): اگر کسی به tools.php?page=cpms-* یا
     * options-general.php?page=cpms-sms برود، به صفحه جدیدِ تحت «مدیریت مطب» هدایت می‌شود.
     */
    public static function redirectLegacy(): void
    {
        if (!is_admin() || !isset($_GET['page'])) {
            return;
        }
        $page = sanitize_key(wp_unslash($_GET['page']));
        $allowed = array_keys(self::REHOME);
        $allowed[] = CpmsDashboard::PAGE_SLUG;
        if (!in_array($page, $allowed, true)) {
            return;
        }
        global $pagenow;
        if (($pagenow ?? '') === 'tools.php' || ($pagenow ?? '') === 'options-general.php') {
            wp_safe_redirect(admin_url('admin.php?page=' . $page));
            exit;
        }
    }

    /**
     * Plugin action links — فقط لینک‌های مجاز (capability-driven).
     *
     * @param array<int, string> $links
     *
     * @return array<int, string>
     */
    public static function actionLinks(array $links): array
    {
        if (current_user_can(RolesAndCapabilities::CONFIG)) {
            $setupPending = !(bool) App::settings()->get('setup.completed', false);
            $primaryLabel = $setupPending ? 'راه‌اندازی' : 'داشبورد CPMS';
            $primaryUrl = $setupPending
                ? admin_url('admin.php?page=cpms-wizard')
                : admin_url('admin.php?page=' . CpmsDashboard::PAGE_SLUG);
            array_unshift($links, '<a href="' . esc_url($primaryUrl) . '">' . esc_html($primaryLabel) . '</a>');
            $links[] = '<a href="' . esc_url(admin_url('admin.php?page=cpms-settings')) . '">تنظیمات</a>';
        }

        return $links;
    }

    /**
     * Notification نصب — برای مدیرِ مجاز، فقط تا وقتی setup کامل نشده (بدون spam).
     */
    public static function onboardingNotice(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && $screen instanceof \WP_Screen && $screen->id === 'plugins') {
            return;
        }
        if ((bool) App::settings()->get('setup.completed', false)) {
            return;
        }
        if (get_transient('cpms_onboarding_seen')) {
            return;
        }
        set_transient('cpms_onboarding_seen', 1, DAY_IN_SECONDS);
        printf(
            '<div class="notice notice-info is-dismissible"><p><strong>CPMS نصب شد</strong> — برای شروع، راه‌اندازی اولیه را تکمیل کنید. <a class="button button-primary" href="%s">شروع راه‌اندازی</a> <a class="button" href="%s">سلامت سیستم</a> <a class="button" href="%s">راهنما</a></p></div>',
            esc_url(admin_url('admin.php?page=cpms-wizard')),
            esc_url(admin_url('admin.php?page=cpms-system')),
            esc_url(admin_url('admin.php?page=cpms-system'))
        );
    }
}
