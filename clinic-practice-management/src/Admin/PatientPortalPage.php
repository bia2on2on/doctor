<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;

/**
 * «نوبت‌های من» — مقصد بیمار بعد از ورود OTP (ADR-0030 / Part 1).
 *
 * مشکل (ممیزی P5): بیمارِ `cpms_patient` بعد از ورود به پیشخوان انگلیسی وردپرس
 * می‌رسید بدون هیچ مقصد فارسی. این صفحه Console ساده بیمار است:
 *  - فقط داده خودش (Ownership — از طریق listMine و cpms_patient_user_links؛ P-5).
 *  - فقط نقشِ بیمارِ خالص آن را می‌بیند/به آن هدایت می‌شود (multi-role پزشک/منشی مستثنی).
 *  - Admin Bar وردپرس برای بیمارِ خالص مخفی می‌شود؛ هر URL دیگری از wp-admin
 *    با GET به همین صفحه هدایت می‌شود (بدون loop، بدون دخالت POST/AJAX/REST).
 *  - رزرو آنلاین (UI) هنوز عرضه نشده — پیام صادقانه + شماره تماس مطب از Settings.
 */
final class PatientPortalPage
{
    private const PAGE_SLUG = 'cpms-patient';

    public static function register(): void
    {
        add_filter('login_redirect', [self::class, 'redirectAfterLogin'], 20, 3);
        add_filter('show_admin_bar', [self::class, 'hideAdminBar'], 20);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'guardWpAdmin']);
    }

    // ================= Helpers =================

    /**
     * آیا کاربر «فقط بیمار» است؟ (نقش cpms_patient بدون نقش ستادی CPMS)
     */
    public static function isPatientOnly(int|\WP_User $user): bool
    {
        if (is_int($user)) {
            $user = get_userdata($user);
            if ($user === false) {
                return false;
            }
        }
        $roles = (array) ($user->roles ?? []);
        if (!in_array(RolesAndCapabilities::ROLE_PATIENT, $roles, true)) {
            return false;
        }

        return !in_array(RolesAndCapabilities::ROLE_DOCTOR, $roles, true)
            && !in_array(RolesAndCapabilities::ROLE_SECRETARY, $roles, true);
    }

    public static function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::PAGE_SLUG);
    }

    // ================= Hooks =================

    /**
     * بعد از Login موفق، بیمارِ خالص به «نوبت‌های من» می‌رود (نه پیشخوان WP).
     */
    public static function redirectAfterLogin(string $redirectTo, string $requested, $user): string
    {
        if ($user instanceof \WP_User && self::isPatientOnly($user)) {
            return self::pageUrl();
        }

        return $redirectTo;
    }

    /**
     * نوار مدیریت وردپرس برای بیمارِ خالص نمایش داده نمی‌شود.
     */
    public static function hideAdminBar(bool $show): bool
    {
        if (is_user_logged_in() && self::isPatientOnly(wp_get_current_user())) {
            return false;
        }

        return $show;
    }

    /**
     * منوی بیمار فقط برای بیمارِ خالص (کاربران ستادی صفحه خودشان را دارند).
     */
    public static function menu(): void
    {
        if (!is_user_logged_in() || !self::isPatientOnly(wp_get_current_user())) {
            return;
        }
        add_menu_page(
            'نوبت‌های من',
            'نوبت‌های من',
            'read',
            self::PAGE_SLUG,
            [self::class, 'render'],
            'dashicons-calendar-alt',
            3
        );
    }

    /**
     * هر GET دیگر از wp-admin برای بیمارِ خالص → «نوبت‌های من».
     * (POST/AJAX/REST/CLI دست نمی‌خورند؛ logout و صفحات خود صفحه مستثنی‌اند.)
     */
    public static function guardWpAdmin(): void
    {
        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron()) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        if (!is_user_logged_in() || !self::isPatientOnly(wp_get_current_user())) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page === self::PAGE_SLUG) {
            return;
        }
        wp_safe_redirect(self::pageUrl());
        exit;
    }

    // ================= Render =================

    public static function render(): void
    {
        if (!is_user_logged_in() || !self::isPatientOnly(wp_get_current_user())) {
            wp_die('دسترسی ندارید', 403);
        }

        $userId = get_current_user_id();
        $today = gmdate('Y-m-d');
        $rows = App::bookingService()->listMine($userId, gmdate('Y-m-d', strtotime('-365 days')), gmdate('Y-m-d', strtotime('+180 days')));

        $upcoming = [];
        $past = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            if ((string) $row['date'] >= $today
                && !in_array($row['status'], ['cancelled_by_patient', 'cancelled_by_staff'], true)) {
                $upcoming[] = $row;
            } else {
                $past[] = $row;
            }
        }
        $phone = (string) App::settings()->get('clinic.phone', '');
        ?>
<div class="wrap" dir="rtl" style="max-width:860px">
    <h1>نوبت‌های من</h1>
    <p class="description">سلام! نوبت‌های ثبت‌شده شما در این صفحه است. تغییرات نوبت با پیامک هم اطلاع داده می‌شود.</p>

    <h2>نوبت‌های پیش‌رو</h2>
    <?php echo self::table($upcoming, 'نوبت پیش‌رویی ندارید.'); ?>

    <h2>تاریخچه</h2>
    <?php echo self::table($past, 'تاریخچه‌ای ثبت نشده است.'); ?>

    <div class="card" style="max-width:840px;padding:8px 16px;margin-top:16px">
        <p>
            برای رزرو، تغییر یا لغو نوبت با مطب در تماس باشید<?php echo $phone !== '' ? ' (تلفن: <b dir="ltr">' . esc_html($phone) . '</b>)' : ''; ?>.
            رزرو آنلاین اینترنتی به‌زودی فعال می‌شود.
        </p>
    </div>
</div>
        <?php
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function table(array $rows, string $emptyText): string
    {
        if ($rows === []) {
            return '<p class="description">' . esc_html($emptyText) . '</p>';
        }
        $statuses = [
            'pending' => 'در انتظار تأیید',
            'confirmed' => 'تأییدشده',
            'cancelled_by_patient' => 'لغو توسط بیمار',
            'cancelled_by_staff' => 'لغو توسط مطب',
            'rescheduled' => 'جابه‌جاشده',
            'completed' => 'انجام‌شده',
            'no_show' => 'عدم حضور',
        ];
        $html = '<table class="widefat striped" style="max-width:840px"><thead><tr>'
            . '<th>تاریخ</th><th>ساعت</th><th>وضعیت</th><th>کد رهگیری</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $status = (string) $r['status'];
            $html .= '<tr>'
                . '<td>' . esc_html((string) ($r['jalali'] ?? Jalali::formatYmd((string) $r['date']))) . '</td>'
                . '<td dir="ltr">' . esc_html((string) $r['time']) . '</td>'
                . '<td>' . esc_html($statuses[$status] ?? $status) . '</td>'
                . '<td dir="ltr">' . esc_html((string) $r['reference_code']) . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }
}
