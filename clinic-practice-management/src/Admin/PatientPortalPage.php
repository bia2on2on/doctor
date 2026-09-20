<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Scope\ScopeRequiredException;
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
 *
 * Phase 9 Slice 1 — لغو نوبت توسط خود بیمار (UI روی مسیرِ موجود B4):
 *  - فقط ردیف‌های «پیش‌رو» با وضعیتِ سرورشناختهٔ `confirmed` دکمهٔ لغو دارند؛ هیچ
 *    وضعیت دیگری (pending/rescheduled/completed/no_show/تاریخچه) affordance ندارد.
 *  - دکمه فقط `data-appointment-id` را حمل می‌کند — همان شناسه‌ای که
 *    `POST clinic/v1/appointments/{id}/cancel` لازم دارد. هیچ clinic_id/patient_id
 *    و هیچ محاسبهٔ مهلتِ لغو سمت کلاینت نیست: مالکیت، نقش، Clinic، گذار وضعیت،
 *    ویزیت فعال، سیاستِ مهلت، آزادسازی ظرفیت، Audit و پیامک همگی همان مرجعِ
 *    نهاییِ BookingController/BookingService (B4) می‌مانند.
 *  - پیکربندیِ runtime (ریشهٔ REST سازگار با Plain/Pretty permalink، الگوی مسیر
 *    B4 و nonce `wp_rest`) به‌صورت JSON script-safe منتشر می‌شود؛ بدون PHI.
 *  - JS جداگانه و کوچک (`assets/js/cpms-patient-portal.js`) فقط روی همین صفحه
 *    انکیو می‌شود تا هیچ صفحهٔ مدیریتی دیگری تحت تأثیر قرار نگیرد.
 */
final class PatientPortalPage
{
    private const PAGE_SLUG = 'cpms-patient';

    /** hook suffix صفحهٔ سطح-بالای `cpms-patient` (خروجی add_menu_page). */
    private const HOOK_SUFFIX = 'toplevel_page_cpms-patient';

    /** handle اسکریپتِ اختصاصیِ پورتال بیمار — جدا از `cpms-admin`. */
    private const JS_HANDLE = 'cpms-patient-portal';

    /** namespace REST موجود — همان B4. */
    private const REST_NAMESPACE = 'clinic/v1';

    /** الگوی مسیرِ B4 نسبت به ریشهٔ namespace؛ `{id}` سمت کلاینت جایگزین می‌شود. */
    private const CANCEL_PATH = '/appointments/{id}/cancel';

    /** نشانگرِ کنترلِ لغو (قرارداد UI/Test) — فقط روی ردیف‌های `confirmed`. */
    private const CANCEL_ACTION_ROLE = 'cancel-appointment';

    /** کلاسِ `<script type="application/json">` حاملِ پیکربندیِ امن. */
    private const CONFIG_CLASS = 'cpms-patient-portal__config';

    public static function register(): void
    {
        add_filter('login_redirect', [self::class, 'redirectAfterLogin'], 20, 3);
        add_filter('show_admin_bar', [self::class, 'hideAdminBar'], 20);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'guardWpAdmin']);
        add_action( 'admin_enqueue_scripts', [self::class, 'enqueue_assets'] );
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

    /**
     * اسکریپتِ لغو نوبت — فقط روی همین صفحه و فقط برای بیمارِ خالص.
     *
     * گیت دوگانه (hook suffix + نقش) یعنی هیچ صفحهٔ مدیریتی دیگری (و هیچ
     * کاربر ستادی‌ای) این فایل را بار نمی‌کند؛ `cpms-admin` (CpmsAssets) هم
     * دست‌نخورده می‌ماند. بدون وابستگی به handle دیگر: اگر روزی `cpms-admin`
     * روی این صفحه نباشد، اسکریپتِ لغو همچنان چاپ می‌شود (fail-open برای
     * خودِ کنترل، fail-closed برای هر صفحهٔ دیگر).
     */
    public static function enqueue_assets( string $hook_suffix ): void {
        if ( $hook_suffix !== self::HOOK_SUFFIX ) {
            return;
        }
        if ( ! is_user_logged_in() || ! self::isPatientOnly( wp_get_current_user() ) ) {
            return;
        }
        $base = \defined( 'CPMS_PLUGIN_URL' ) && CPMS_PLUGIN_URL !== '' ? rtrim( (string) CPMS_PLUGIN_URL, '/' ) : '';
        if ( $base === '' ) {
            return;
        }

        wp_enqueue_script(
            self::JS_HANDLE,
            $base . '/assets/js/cpms-patient-portal.js',
            [],
            \defined( 'CPMS_VERSION' ) ? CPMS_VERSION : 'dev',
            true
        );
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
        // شماره تماس از Settingsِ Clinicِ محیطی. بیمار عضو هیچ Clinicی نیست، پس در
        // نصب چندکلینیکی Scope مبهم است (CLINIC_SCOPE_REQUIRED)؛ در آن حالت به‌جای
        // حدس‌زدن یک Clinic، خطِ تماس حذف می‌شود (همان الگوی degrade در App).
        try {
            $phone = (string) App::settings()->get( 'clinic.phone', '' );
        } catch ( ScopeRequiredException ) {
            $phone = '';
        }
        ?>
<div class="wrap cpms-patient-portal" dir="rtl" style="max-width:860px">
    <h1>نوبت‌های من</h1>
    <p class="description">سلام! نوبت‌های ثبت‌شده شما در این صفحه است. تغییرات نوبت با پیامک هم اطلاع داده می‌شود.</p>

    <h2>نوبت‌های پیش‌رو</h2>
    <p class="description">نوبت‌های تأییدشده را می‌توانید تا پیش از مهلتِ تعیین‌شدهٔ مطب همین‌جا لغو کنید.</p>
        <?php echo self::table( $upcoming, 'نوبت پیش‌رویی ندارید.', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup از پیش escape شده (esc_html/esc_attr در table()/cancel_button()) ?>
    <div class="notice notice-error inline cpms-patient-portal__error" role="alert" data-role="cancel-error" hidden><p></p></div>

    <h2>تاریخچه</h2>
        <?php echo self::table( $past, 'تاریخچه‌ای ثبت نشده است.', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup از پیش escape شده (esc_html در table()) ?>

    <div class="card" style="max-width:840px;padding:8px 16px;margin-top:16px">
        <p>
            برای رزرو یا تغییر نوبت، و لغو نوبت‌هایی که دکمهٔ لغو ندارند، با مطب در تماس باشید<?php echo $phone !== '' ? ' (تلفن: <b dir="ltr">' . esc_html( $phone ) . '</b>)' : ''; ?>.
            رزرو آنلاین اینترنتی به‌زودی فعال می‌شود.
        </p>
    </div>
        <?php echo self::config_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON script-safe (wp_json_encode + JSON_HEX_TAG|JSON_HEX_AMP) و class با esc_attr ?>
</div>
        <?php
    }

    /**
     * پیکربندیِ امنِ runtime برای اسکریپت لغو — فقط سه کلید:
     *  - `rest_root`: `rest_url('clinic/v1')` بدون اسلش انتهایی (در Plain permalink
     *    شامل `index.php?rest_route=/clinic/v1` است؛ ترکیب مسیر سمت کلاینت با
     *    همان الگوی `apiUrl()` انجام می‌شود، نه الحاقِ ساده).
     *  - `cancel_path`: الگوی مسیر B4 با `{id}`.
     *  - `nonce`: `wp_rest` — همان مرزِ CSRF که `cancelPermission` می‌سنجد.
     * عمداً هیچ clinic_id/patient_id/PHI منتشر نمی‌شود؛ مرجعِ مالکیت و Clinic سرور است.
     * `JSON_HEX_TAG|JSON_HEX_AMP` خروجی را script-safe می‌کند (`</` و `&` escape).
     */
    private static function config_script(): string {
        $json = wp_json_encode(
            [
                'rest_root'   => untrailingslashit( rest_url( self::REST_NAMESPACE ) ),
                'cancel_path' => self::CANCEL_PATH,
                'nonce'       => wp_create_nonce( 'wp_rest' ),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        );
        if ( ! is_string( $json ) || $json === '' ) {
            return '';
        }

        return '<script type="application/json" class="' . esc_attr( self::CONFIG_CLASS ) . '">' . $json . '</script>';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param bool $with_actions ستون «عملیات» فقط برای جدول پیش‌رو؛ دکمهٔ لغو فقط روی `confirmed`.
     */
    private static function table( array $rows, string $empty_text, bool $with_actions ): string {
        if ($rows === []) {
            return '<p class="description">' . esc_html( $empty_text ) . '</p>';
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
        // `cpms-table-responsive` + `data-label`: الگوی موجود cpms-admin.css — در
        // viewport باریک (≤782px) هر ردیف کارت می‌شود؛ بدون overflow افقی.
        $html = '<table class="widefat striped cpms-table-responsive" style="max-width:840px"><thead><tr>'
            . '<th>تاریخ</th><th>ساعت</th><th>وضعیت</th><th>کد رهگیری</th>'
            . ( $with_actions ? '<th>عملیات</th>' : '' )
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $status = (string) $r['status'];
            $jalali = (string) ( $r['jalali'] ?? Jalali::formatYmd( (string) $r['date'] ) );
            $time   = (string) $r['time'];
            $html .= '<tr>'
                . '<td data-label="تاریخ">' . esc_html( $jalali ) . '</td>'
                . '<td data-label="ساعت"><span dir="ltr">' . esc_html( $time ) . '</span></td>'
                . '<td data-label="وضعیت">' . esc_html( $statuses[ $status ] ?? $status ) . '</td>'
                . '<td data-label="کد رهگیری"><span dir="ltr">' . esc_html( (string) $r['reference_code'] ) . '</span></td>';
            if ( $with_actions ) {
                $html .= '<td class="cpms-actions-cell" data-label="عملیات">'
                    . ( $status === 'confirmed' ? self::cancel_button( (int) $r['id'], $jalali, $time ) : '<span aria-hidden="true">—</span>' )
                    . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * دکمهٔ واقعی (`<button type="button">` ⇒ فعال‌سازی با کیبورد) — فقط شناسهٔ نوبت
     * را حمل می‌کند. `data-cpms-confirm`: تأییدِ قابل‌دسترسِ موجود در cpms-admin.js
     * برای اقدام‌های برگشت‌ناپذیر (UX، نه authorization — مرجع همچنان B4 است).
     */
    private static function cancel_button( int $appointment_id, string $jalali, string $time ): string {
        $label = 'لغو نوبت ' . $jalali . ' ساعت ' . $time;

        return '<button type="button" class="button button-small cpms-patient-portal__cancel"'
            . ' data-role="' . esc_attr( self::CANCEL_ACTION_ROLE ) . '"'
            . ' data-appointment-id="' . $appointment_id . '"'
            . ' aria-label="' . esc_attr( $label ) . '"'
            . ' data-cpms-confirm="' . esc_attr( 'نوبت ' . $jalali . ' ساعت ' . $time . ' لغو شود؟ این کار قابل بازگشت نیست.' ) . '"'
            . '>لغو نوبت</button>';
    }
}
