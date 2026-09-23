<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Frontend\PatientPortalShell;

/**
 * «نوبت‌های من» — محتوای پورتال بیمار (ADR-0030 / Part 1 + Phase 9 Slices 1–3).
 *
 * Phase 9 Slice 3: مقصد روزانهٔ بیمارِ خالص دیگر wp-admin نیست.
 * ارائهٔ مستقلِ frontend:
 *   `ClinicCore\Frontend\PatientPortalShell`
 *   (WordPress Page + template_include + standalone full-document template).
 *
 * این کلاس مالکِ **محتوای** پورتال می‌ماند (نوبت‌ها، لغو، اعلان‌ها، config/nonce)
 * و از قالبِ standalone یا (legacy) callback منوی wp-admin قابل فراخوانی است.
 *
 *  - فقط داده خودش (Ownership — listMine + cpms_patient_user_links؛ P-5).
 *  - فقط نقشِ بیمارِ خالص (multi-role پزشک/منشی مستثنی).
 *  - Admin Bar برای بیمارِ خالص مخفی؛ هر GET از wp-admin → frontend portal
 *    (از جمله legacy page=cpms-patient — بدون early-return داخل wp-admin).
 *  - POST/AJAX/REST/CLI دست‌نخورده.
 *
 * Phase 9 Slice 1 — لغو نوبت (B4) + Slice 2 — اعلان‌های داخلی (G6/R2b):
 *  بدون تغییر قرارداد محتوا؛ فقط محل ارائه به frontend shell منتقل شد.
 */
final class PatientPortalPage
{
    /** Legacy wp-admin menu slug (no longer the pure-patient home as of Slice 3). */
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

    /** مسیرِ موجودِ R2b نسبت به ریشهٔ namespace؛ بدنهٔ `{"all":true}` همهٔ اعلان‌های خودِ کاربر را خوانده می‌کند. */
    private const NOTIFICATIONS_READ_PATH = '/notifications/read';

    /** سقفِ ثابتِ ردیف‌های اعلان در یک رندر (همان پیش‌فرضِ `limit` در G6) — کوئری همیشه bounded است. */
    private const NOTIFICATIONS_LIMIT = 50;

    /** نشانگرهای بخش اعلان‌ها (قرارداد UI/Test — Slice 2). */
    private const NOTIFICATIONS_SECTION_ROLE  = 'notifications-section';
    private const NOTIFICATIONS_BADGE_ROLE    = 'notifications-unread-badge';
    private const NOTIFICATIONS_MARK_ALL_ROLE = 'notifications-mark-all-read';
    private const NOTIFICATIONS_ERROR_ROLE    = 'notifications-error';
    private const NOTIFICATION_ROW_ROLE       = 'notification-row';

    public static function register(): void
    {
        // Frontend independent shell (Slice 3) — Page + template_include + standalone template.
        PatientPortalShell::register();

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

    /**
     * Production pure-patient destination — frontend Patient Portal URL
     * (never under /wp-admin/ as of Phase 9 Slice 3).
     *
     * Established public API name `pageUrl` (camelCase) is retained for
     * discovery / login_redirect callers; snake_case alias below.
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- established public API `pageUrl` (Slice 0–3 contract).
    public static function pageUrl(): string {
        return self::page_url();
    }

    /** Snake_case alias of pageUrl() for WPCS-conformant call sites. */
    public static function page_url(): string {
        return PatientPortalShell::portal_url();
    }

    // ================= Hooks =================

    /**
     * بعد از Login موفق، بیمارِ خالص به پورتال frontend می‌رود (نه پیشخوان WP).
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
     * منوی legacy wp-admin — دیگر مقصد روزانه نیست؛ guard همهٔ GETها را به
     * frontend می‌فرستد. ثبت منو برای سازگاری/ردیابی باقی می‌ماند.
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
     * هر GET از wp-admin برای بیمارِ خالص → پورتال frontend
     * (شامل legacy page=cpms-patient — بدون early-return داخل wp-admin).
     * POST/AJAX/REST/CLI دست نمی‌خورند.
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

        $user_id = get_current_user_id();

        $today = gmdate('Y-m-d');

        $rows = App::bookingService()->listMine( $user_id, gmdate( 'Y-m-d', strtotime( '-365 days' ) ), gmdate( 'Y-m-d', strtotime( '+180 days' ) ) );

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
        // اعلان‌های داخلیِ خودِ بیمار — همان G6 (NotificationService::inbox): گیرنده
        // سرور-side از پیوندِ Patientِ کاربرِ جاری حل می‌شود؛ سه کوئریِ bounded (پیوند،
        // فهرست با LIMIT، شمارِ خوانده‌نشده) و هیچ کوئریِ per-notification. بیمارِ
        // بدونِ پیوند در نصب چندکلینیکی ⇒ Scope مبهم ⇒ همان degrade (بخشِ خالی).
        try {
            $inbox = App::notificationService()->inbox( get_current_user_id(), false, self::NOTIFICATIONS_LIMIT );
        } catch ( ScopeRequiredException ) {
            $inbox = [
                'notifications' => [],
                'unread_count'  => 0,
            ];
        }
        $clinic_tz = self::clinic_timezone();

        // Phase 9 Slice 4: Profile data — single my-records fetch (P-5) per page render.
        $profile_records = [];

        $profile_initial = null;

        $login_mobile = '';

        try {
            $profile_records = App::patientService()->linked_records( $user_id );

            if ( ! is_array( $profile_records ) ) {
                $profile_records = [];
            }

            if ( count( $profile_records ) === 1 ) {
                $sole = $profile_records[0];

                $me_full = App::patientService()->me( $user_id, (int) $sole['link_id'] );

                if ( is_array( $me_full ) ) {
                    $me_whitelisted  = self::whitelist_me_for_client( $me_full );
                    $profile_initial = [
                        'record' => $sole,
                        'me'     => $me_whitelisted,
                    ];
                    $login_mobile    = (string) ( $me_full['mobile'] ?? '' );
                }
            } elseif ( count( $profile_records ) > 1 ) {
                try {
                    $me_any = App::patientService()->me( $user_id, (int) $profile_records[0]['link_id'] );

                    if ( is_array( $me_any ) ) {
                        $login_mobile = (string) ( $me_any['mobile'] ?? '' );
                    }
                } catch ( \Throwable $e ) {
                    $login_mobile = '';
                }
            }
        } catch ( \Throwable $e ) {
            $profile_records = [];

            $profile_initial = null;

            $login_mobile = '';
        }

        $html = '<div class="wrap cpms-patient-portal" dir="rtl" style="max-width:860px">';

        $html .= '<section class="cpms-pp-section" data-role="appointments-section" id="appointments" aria-labelledby="cpms-pp-appointments-heading">';
        $html .= '<h1 id="cpms-pp-appointments-heading">نوبت‌های من</h1>';
        $html .= '<p class="description">سلام! نوبت‌های ثبت‌شده شما در این صفحه است. تغییرات نوبت با پیامک هم اطلاع داده می‌شود.</p>';
        $html .= '<h2>نوبت‌های پیش‌رو</h2>';
        $html .= '<p class="description">نوبت‌های تأییدشده را می‌توانید تا پیش از مهلتِ تعیین‌شدهٔ مطب همین‌جا لغو کنید.</p>';
        $html .= self::table( $upcoming, 'نوبت پیش‌رویی ندارید.', true );
        $html .= '<div class="notice notice-error inline cpms-patient-portal__error" role="alert" data-role="cancel-error" hidden><p></p></div>';
        $html .= self::notifications_section( $inbox, $clinic_tz );
        $html .= '<h2>تاریخچه</h2>';
        $html .= self::table( $past, 'تاریخچه‌ای ثبت نشده است.', false );
        $html .= '<div class="card" style="max-width:840px;padding:8px 16px;margin-top:16px"><p>برای رزرو یا تغییر نوبت، و لغو نوبت‌هایی که دکمهٔ لغو ندارند، با مطب در تماس باشید';

        if ( $phone !== '' ) {
            $html .= ' (تلفن: <b dir="ltr">' . esc_html( $phone ) . '</b>)';
        }

        $html .= '. رزرو آنلاین اینترنتی به‌زودی فعال می‌شود.</p></div>';
        $html .= '</section>';
        $html .= self::profile_section( $profile_records, $profile_initial, $login_mobile );
        $html .= self::visits_section( $profile_records, $user_id );
        $html .= self::prescriptions_section( $profile_records, $user_id );
        $html .= self::config_script( $profile_records, $profile_initial );
        $html .= '</div>';

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup از پیش escape شده
    }


    /** Read-only Visits. Only a sole eligible record is resolved during rendering. */
    private static function visits_section( array $records, int $user_id ): string {
        $html  = '<section class="cpms-pp-section" id="visits" data-role="visits-section" aria-labelledby="cpms-visits-heading">';
        $html .= '<h1 id="cpms-visits-heading">' . esc_html__( 'ویزیت‌های من', 'cpms' ) . '</h1>';
        $html .= '<p data-role="visits-empty-state"' . ( count( $records ) > 0 ? ' hidden' : '' ) . '>' . esc_html__( 'پرونده فعالی به حساب شما متصل نیست.', 'cpms' ) . '</p>';
        if ( count( $records ) > 1 ) {
            $html .= '<label for="cpms-visits-record">' . esc_html__( 'پرونده مطب را انتخاب کنید', 'cpms' ) . '</label>';
            $html .= '<select id="cpms-visits-record" data-role="visits-record-select"><option value="">' . esc_html__( 'انتخاب پرونده…', 'cpms' ) . '</option>';
            foreach ( $records as $record ) {
                $html .= '<option value="' . esc_attr( (string) $record['link_id'] ) . '">' . esc_html( $record['clinic_name'] . ' — ' . $record['patient_display_name'] . ' — ' . $record['mrn'] ) . '</option>';
            }
            $html .= '</select>';
        }
        $sole  = count( $records ) === 1 ? $records[0] : null;
        $html .= '<p data-role="visits-context">' . ( $sole ? esc_html( $sole['clinic_name'] . ' — ' . $sole['patient_display_name'] . ' — ' . $sole['mrn'] ) : '' ) . '</p>';
        $html .= '<p role="status" data-role="visits-loading" hidden>' . esc_html__( 'در حال دریافت…', 'cpms' ) . '</p>';
        $error = '';
        $items = '';
        if ( $sole ) {
            try {
                $list = App::clinicalService()->patientVisits( $user_id, null, null, (int) $sole['link_id'] );
                foreach ( $list['visits'] as $visit ) {
                    $items .= '<li><button type="button" class="cpms-pp-btn cpms-pp-btn--ghost" data-role="visit-open" data-visit-id="' . esc_attr( (string) $visit['id'] ) . '">' . esc_html( $visit['visit_jalali'] . ' — ' . $visit['clinician_name'] ) . '</button></li>';
                }
                if ( $items === '' ) {
                    $items = '<li>' . esc_html__( 'ویزیتی ثبت نشده است.', 'cpms' ) . '</li>';
                }
            } catch ( \ClinicCore\Application\Clinical\ClinicalException $exception ) {
                $error = $exception->getMessage();
            }
        }
        $html .= '<p role="alert" data-role="visits-error"' . ( $error === '' ? ' hidden' : '' ) . '>' . esc_html( $error ) . '</p>';
        $html .= '<ul data-role="visits-list">' . $items . '</ul>';
        $html .= '<div data-role="visits-detail" aria-live="polite"></div></section>';
        return $html;
    }

    /** Read-only Prescriptions. Only a sole eligible record is resolved during rendering. */
    private static function prescriptions_section( array $records, int $user_id ): string {
        $html  = '<section class="cpms-pp-section" id="prescriptions" data-role="prescriptions-section" aria-labelledby="cpms-prescriptions-heading">';
        $html .= '<h1 id="cpms-prescriptions-heading">' . esc_html__( 'نسخه‌های من', 'cpms' ) . '</h1>';
        $html .= '<p data-role="prescriptions-empty-state"' . ( count( $records ) > 0 ? ' hidden' : '' ) . '>' . esc_html__( 'پرونده فعالی به حساب شما متصل نیست.', 'cpms' ) . '</p>';
        if ( count( $records ) > 1 ) {
            $html .= '<label for="cpms-prescriptions-record">' . esc_html__( 'پرونده مطب را انتخاب کنید', 'cpms' ) . '</label>';
            $html .= '<select id="cpms-prescriptions-record" data-role="prescriptions-record-select"><option value="">' . esc_html__( 'انتخاب پرونده…', 'cpms' ) . '</option>';
            foreach ( $records as $record ) {
                $html .= '<option value="' . esc_attr( (string) $record['link_id'] ) . '">' . esc_html( $record['clinic_name'] . ' — ' . $record['patient_display_name'] . ' — ' . $record['mrn'] ) . '</option>';
            }
            $html .= '</select>';
        }
        $sole  = count( $records ) === 1 ? $records[0] : null;
        $html .= '<p data-role="prescriptions-context">' . ( $sole ? esc_html( $sole['clinic_name'] . ' — ' . $sole['patient_display_name'] . ' — ' . $sole['mrn'] ) : '' ) . '</p>';
        $html .= '<p role="status" data-role="prescriptions-loading" hidden>' . esc_html__( 'در حال دریافت…', 'cpms' ) . '</p>';
        $error = '';
        $items = '';
        if ( $sole ) {
            try {
                $list = App::clinicalService()->patientPrescriptions( $user_id, (int) $sole['link_id'] );
                foreach ( $list['prescriptions'] as $rx ) {
                    $items .= '<li data-role="prescription-item">';
                    $items .= '<div class="cpms-pp-prescription__header">' . esc_html( $rx['prescription_number'] . ' — ' . ( $rx['created_at_jalali'] ?? '' ) ) . '</div>';
                    if ( ! empty( $rx['items'] ) ) {
                        $items .= '<ul class="cpms-pp-prescription__items">';
                        foreach ( $rx['items'] as $med ) {
                            $parts = [];
                            if ( ! empty( $med['generic_name'] ) ) {
                                $parts[] = (string) $med['generic_name'];
                            }
                            if ( ! empty( $med['brand_name'] ) ) {
                                $parts[] = '(' . (string) $med['brand_name'] . ')';
                            }
                            if ( ! empty( $med['strength'] ) ) {
                                $parts[] = (string) $med['strength'];
                            }
                            if ( ! empty( $med['form'] ) ) {
                                $parts[] = (string) $med['form'];
                            }
                            if ( ! empty( $med['dose'] ) ) {
                                $parts[] = (string) $med['dose'];
                            }
                            if ( ! empty( $med['frequency'] ) ) {
                                $parts[] = (string) $med['frequency'];
                            }
                            if ( ! empty( $med['route'] ) ) {
                                $parts[] = (string) $med['route'];
                            }
                            if ( ! empty( $med['duration_days'] ) ) {
                                $parts[] = (int) $med['duration_days'] . ' روز';
                            }
                            $item_line = implode( ' — ', $parts );
                            if ( ! empty( $med['instructions'] ) ) {
                                $item_line .= ' — دستور: ' . (string) $med['instructions'];
                            }
                            $items .= '<li>' . esc_html( $item_line ) . '</li>';
                        }
                        $items .= '</ul>';
                    }
                    $items .= '</li>';
                }
                if ( $items === '' ) {
                    $items = '<li>' . esc_html__( 'نسخه‌ای ثبت نشده است.', 'cpms' ) . '</li>';
                }
            } catch ( \ClinicCore\Application\Clinical\ClinicalException $exception ) {
                $error = $exception->getMessage();
            }
        }
        $html .= '<p role="alert" data-role="prescriptions-error"' . ( $error === '' ? ' hidden' : '' ) . '>' . esc_html( $error ) . '</p>';
        $html .= '<ul data-role="prescriptions-list">' . $items . '</ul>';
        $html .= '</section>';

        return $html;
    }

    /**
     * پیکربندیِ امنِ runtime برای اسکریپت پورتال — فقط چهار کلید:
     *  - `rest_root`: `rest_url('clinic/v1')` بدون اسلش انتهایی (در Plain permalink
     *    شامل `index.php?rest_route=/clinic/v1` است؛ ترکیب مسیر سمت کلاینت با
     *    همان الگوی `apiUrl()` انجام می‌شود، نه الحاقِ ساده).
     *  - `cancel_path`: الگوی مسیر B4 با `{id}`.
     *  - `notifications_read_path`: مسیرِ موجودِ R2b (POST `{"all":true}`) — Slice 2.
     *  - `nonce`: `wp_rest` — همان مرزِ CSRF که `cancelPermission`/`permission` می‌سنجند.
     * عمداً هیچ clinic_id/patient_id/PHI منتشر نمی‌شود؛ مرجعِ مالکیت و Clinic سرور است.
     * `JSON_HEX_TAG|JSON_HEX_AMP` خروجی را script-safe می‌کند (`</` و `&` escape).
     */
    /**
     * پیکربندیِ امنِ runtime برای اسکریپت پورتال — Slice 1/2/4 keys:
     *  - `rest_root`: `rest_url('clinic/v1')` بدون اسلش انتهایی (در Plain permalink
     *    شامل `index.php?rest_route=/clinic/v1` است؛ ترکیب مسیر سمت کلاینت با
     *    همان الگوی `apiUrl()` انجام می‌شود، نه الحاقِ ساده).
     *  - `cancel_path`: الگوی مسیر B4 با `{id}`.
     *  - `notifications_read_path`: مسیرِ موجودِ R2b (POST `{"all":true}`) — Slice 2.
     *  - `profile_my_records_path`/`profile_me_path`/`my_records_path`/`me_path`: مسیرهای Patient Profile (Slice 4) — نسبی به NS، بدون پیشوند تکراری.
     *  - `profile_records`: لینک‌های فعال کاربر (link_id + clinic_name + patient_display_name + mrn + is_primary) — صرفاً برای نمایش در سلکتور؛ clinic_id/patient_id سرور-ساید enforcement.
     *  - `profile_initial`: رکورد + me در حالت تک‌پیوند (N=1) تا فرم بدون درخواست اضافی لود شود.
     *  - `nonce`: `wp_rest` — همان مرزِ CSRF که `cancelPermission`/`permission` می‌سنجند.
     * `JSON_HEX_TAG|JSON_HEX_AMP` خروجی را script-safe می‌کند (`</` و `&` escape).
     *
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed>|null        $initial
     */
    private static function config_script( array $records = [], ?array $initial = null ): string {
        $profile_records_payload = [];

        if ( is_array( $records ) ) {
            foreach ( $records as $r ) {
                $profile_records_payload[] = [
                    'link_id'              => (int) $r['link_id'],
                    'clinic_name'          => (string) ( $r['clinic_name'] ?? '' ),
                    'patient_display_name' => (string) ( $r['patient_display_name'] ?? '' ),
                    'mrn'                  => (string) ( $r['mrn'] ?? '' ),
                    'is_primary'           => (bool) ( $r['is_primary'] ?? false ),
                ];
            }
        }

        $profile_initial_payload = null;

        if ( is_array( $initial ) && isset( $initial['record'], $initial['me'] ) ) {
            $profile_initial_payload = [
                'record' => [
                    'link_id'              => (int) ( $initial['record']['link_id'] ?? 0 ),
                    'clinic_name'          => (string) ( $initial['record']['clinic_name'] ?? '' ),
                    'patient_display_name' => (string) ( $initial['record']['patient_display_name'] ?? '' ),
                    'mrn'                  => (string) ( $initial['record']['mrn'] ?? '' ),
                    'is_primary'           => (bool) ( $initial['record']['is_primary'] ?? false ),
                ],
                'me'     => $initial['me'],
            ];
        }

        $json = wp_json_encode(
            [
                'rest_root'               => untrailingslashit( rest_url( self::REST_NAMESPACE ) ),
                'cancel_path'             => self::CANCEL_PATH,
                'notifications_read_path' => self::NOTIFICATIONS_READ_PATH,
                'profile_my_records_path' => '/patient/my-records',
                'profile_me_path'         => '/patient/me',
                'visits_path'             => '/visits',
                'visit_detail_path'       => '/visits/{id}',
                'prescriptions_path'      => '/prescriptions',
                'my_records_path'         => '/patient/my-records',
                'me_path'                 => '/patient/me',
                'profile_records'         => $profile_records_payload,
                'profile_initial'         => $profile_initial_payload,
                'nonce'                   => wp_create_nonce( 'wp_rest' ),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        );

        if ( ! is_string( $json ) || $json === '' ) {
            return '';
        }

        return '<script type="application/json" class="' . esc_attr( self::CONFIG_CLASS ) . '">' . $json . '</script>';
    }


    /**
     * بخشِ اعلان‌های داخلی (Slice 2) — سرور-رندر از پاسخِ واقعیِ G6؛ بدون polling.
     * نشانِ خوانده‌نشده و کنترلِ «خواندنِ همه» فقط وقتی شمارِ خوانده‌نشده > ۰ است
     * رندر می‌شوند (در صفر اصلاً وجود ندارند، نه اینکه پنهان یا غیرفعال باشند).
     *
     * @param array<string, mixed> $inbox خروجیِ NotificationService::inbox (notifications + unread_count).
     */
    /**
     * Slice 4: بخش «پروفایل».
     *   - 0 پیوند: empty state امن (بدون فرم، بدون دکمهٔ ایجاد/ارتباط).
     *   - 1 پیوند: auto-select، فرم مستقیم (سلکتور در DOM نمی‌آید).
     *   - N>1 پیوند: سلکتور بدون پیش‌انتخاب؛ فرم تا انتخاب explicit کاربر غیرفعال است.
     *
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed>|null        $initial
     */
    private static function profile_section( array $records, ?array $initial, string $login_mobile ): string {
        $count = is_array( $records ) ? count( $records ) : 0;

        $html = '<section class="cpms-pp-section cpms-pp-profile" data-role="profile-section" id="profile" aria-labelledby="cpms-pp-profile-heading">';

        $html .= '<h1 id="cpms-pp-profile-heading">پروفایل</h1>';
        $html .= '<p class="description">اطلاعات پروندهٔ پزشکی شما در این بخش قابل مشاهده و ویرایش است.</p>';

        if ( $count === 0 ) {
            $html .= '<div class="cpms-empty" data-role="profile-empty-state">';
            $html .= '<p><strong>هنوز پرونده‌ای برای شما به این حساب متصل نیست.</strong></p>';
            $html .= '<p>اگر قبلاً نوبت ثبت کرده‌اید و پرونده‌تان را نمی‌بینید، لطفاً با مطب تماس بگیرید.</p>';
            $html .= '</div>';
            $html .= '</section>';

            return $html;
        }

        $is_single_with_initial = ( $count === 1 && is_array( $initial ) );

        $hidden_attr = $is_single_with_initial ? '' : ' hidden';

        $clinic_name = '';

        $mrn_text = '';

        if ( $is_single_with_initial ) {
            $clinic_name = (string) ( $initial['record']['clinic_name'] ?? '' );

            $mrn_raw = (string) ( $initial['record']['mrn'] ?? '' );

            $mrn_text = $mrn_raw !== '' ? 'MRN: ' . $mrn_raw : '';
        }

        $html .= '<div class="cpms-pp-profile__context" data-role="profile-context"' . $hidden_attr . '>';
        $html .= '<span class="cpms-pp-profile__context-label">مطب فعال:</span>';
        $html .= '<span class="cpms-pp-profile__context-clinic" data-role="profile-clinic-label">' . esc_html( $clinic_name ) . '</span>';
        $html .= '<span class="cpms-pp-profile__context-sep" aria-hidden="true">·</span>';
        $html .= '<span class="cpms-pp-profile__context-mrn" data-role="profile-mrn">' . esc_html( $mrn_text ) . '</span>';
        $html .= '</div>';

        if ( $count > 1 ) {
            $html .= '<div class="cpms-pp-profile__selector">';
            $html .= '<label for="cpms-pp-record-select">مطب / پرونده:</label>';
            $html .= '<select id="cpms-pp-record-select" data-role="profile-record-select" aria-describedby="cpms-pp-record-select-hint">';
            $html .= '<option value="" selected>' . esc_html( 'یکی از مطب‌های مرتبط را انتخاب کنید…' ) . '</option>';

            foreach ( $records as $r ) {
                $link_id_attr = esc_attr( (string) $r['link_id'] );

                $clinic = (string) ( $r['clinic_name'] ?? '' );

                $display = (string) ( $r['patient_display_name'] ?? '' );
                $display = $display !== '' ? $display : 'بیمار';

                $mrn = (string) ( $r['mrn'] ?? '' );

                $label = $clinic . ' — ' . $display . ' (MRN: ' . $mrn . ')';

                $html .= '<option data-role="profile-record-option" data-link-id="' . $link_id_attr . '" value="' . $link_id_attr . '">' . esc_html( $label ) . '</option>';
            }

            $html .= '</select>';
            $html .= '<p id="cpms-pp-record-select-hint" class="description">ویرایش فقط برای پروندهٔ انتخاب‌شده اعمال می‌شود.</p>';
            $html .= '</div>';
        }

        $html .= '<div class="cpms-pp-profile__form-wrap" data-role="profile-form-wrap"';

        if ( $count === 1 && is_array( $initial ) ) {
            $html .= '>';
            $html .= self::profile_form_markup( $initial, $login_mobile );
            $html .= '</div>';
        } else {
            $html .= ' hidden></div>';
        }

        $html .= '</section>';

        return $html;
    }

    /**
     * Whitelist me-payload to ME_EDITABLE (+ mobile for read-only display) so no
     * internal identifiers (id/mrn/clinic_id/...) leak into client config.
     *
     * @param array<string, mixed> $me
     * @return array<string, mixed>
     */
    private static function whitelist_me_for_client( array $me ): array {
        $out = [];

        foreach ( PatientService::ME_EDITABLE as $field ) {
            $out[ $field ] = $me[ $field ] ?? null;
        }

        $out['mobile'] = (string) ( $me['mobile'] ?? '' );

        return $out;
    }

    /**
     * فرمِ ویرایش پرونده (Slice 4) — در N=1 سرور-رندر می‌شود؛ در N>1 همین markup
     * را جاوااسکریپت روی انتخاب explicit سمت کلاینت می‌سازد. فیلدها دقیقاً
     * PatientService::ME_EDITABLE هستند.
     *
     * @param array<string,mixed>|null $initial ['record' => …, 'me' => …]
     */
    private static function profile_form_markup( ?array $initial, string $login_mobile ): string {
        $me = is_array( $initial ) && isset( $initial['me'] ) && is_array( $initial['me'] ) ? $initial['me'] : [];

        $record = is_array( $initial ) && isset( $initial['record'] ) && is_array( $initial['record'] ) ? $initial['record'] : [];

        $link_id = (int) ( $record['link_id'] ?? 0 );

        $fields = [
            'first_name'              => [
                'label'        => 'نام',
                'type'         => 'text',
                'autocomplete' => 'given-name',
            ],
            'last_name'               => [
                'label'        => 'نام خانوادگی',
                'type'         => 'text',
                'autocomplete' => 'family-name',
            ],
            'national_id'             => [
                'label'        => 'کد ملی',
                'type'         => 'text',
                'inputmode'    => 'numeric',
                'autocomplete' => 'off',
                'dir'          => 'ltr',
            ],
            'birth_date'              => [
                'label'       => 'تاریخ تولد',
                'type'        => 'text',
                'placeholder' => 'YYYY-MM-DD',
                'dir'         => 'ltr',
            ],
            'gender'                  => [
                'label'   => 'جنسیت',
                'type'    => 'select',
                'options' => [
                    ''       => 'انتخاب کنید',
                    'male'   => 'مرد',
                    'female' => 'زن',
                    'other'  => 'سایر',
                ],
            ],
            'address'                 => [
                'label' => 'آدرس',
                'type'  => 'textarea',
                'full'  => true,
            ],
            'phone'                   => [
                'label'        => 'تلفن ثابت',
                'type'         => 'tel',
                'autocomplete' => 'tel',
                'dir'          => 'ltr',
            ],
            'emergency_contact_name'  => [
                'label' => 'نام تماس اضطراری',
                'type'  => 'text',
            ],
            'emergency_contact_phone' => [
                'label' => 'تلفن تماس اضطراری',
                'type'  => 'tel',
                'dir'   => 'ltr',
            ],
        ];

        $html = '<form class="cpms-pp-profile__form" data-role="profile-form" novalidate>';

        $html .= '<input type="hidden" name="link_id" data-role="profile-link-id" value="' . esc_attr( (string) $link_id ) . '">';

        $html .= '<div class="notice inline cpms-pp-profile__notice cpms-pp-profile__notice--success" data-role="profile-success" hidden role="alert"></div>';

        $html .= '<div class="notice inline cpms-pp-profile__notice cpms-pp-profile__notice--error" data-role="profile-error" hidden role="alert"></div>';

        $mobile_hidden = $login_mobile !== '' ? '' : ' hidden';

        $html .= '<div class="cpms-pp-profile__mobile" data-role="profile-mobile-row"' . $mobile_hidden . '>';
        $html .= '<span class="cpms-pp-profile__mobile-label">شماره موبایل ورود</span>';
        $html .= '<div data-role="profile-login-mobile" class="cpms-pp-profile__mobile-value" dir="ltr">' . esc_html( $login_mobile ) . '</div>';
        $html .= '<p class="description">این شماره موبایل هویت ورود شماست و از این بخش قابل تغییر نیست. برای تغییر با مطب تماس بگیرید.</p>';
        $html .= '</div>';
        $html .= '<div class="cpms-pp-profile__grid">';

        foreach ( $fields as $name => $spec ) {
            $full = ! empty( $spec['full'] ) ? ' cpms-pp-field--full' : '';

            $initial_val = array_key_exists( $name, $me ) && $me[ $name ] !== null ? (string) $me[ $name ] : '';

            $html .= '<div class="cpms-pp-field' . esc_attr( $full ) . '">';
            $html .= '<label for="cpms-pp-' . esc_attr( $name ) . '">' . esc_html( $spec['label'] ) . '</label>';

            if ( $spec['type'] === 'select' ) {
                $html .= '<select id="cpms-pp-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" data-field="' . esc_attr( $name ) . '">';

                foreach ( (array) $spec['options'] as $opt_val => $opt_label ) {
                    $selected = (string) $opt_val === $initial_val ? ' selected' : '';

                    $html .= '<option value="' . esc_attr( (string) $opt_val ) . '"' . $selected . '>' . esc_html( (string) $opt_label ) . '</option>';
                }

                $html .= '</select>';
            } elseif ( $spec['type'] === 'textarea' ) {
                $attr_str = '';

                foreach ( [ 'placeholder', 'autocomplete', 'inputmode', 'dir' ] as $attr ) {
                    if ( isset( $spec[ $attr ] ) ) {
                        $attr_str .= ' ' . esc_attr( $attr ) . '="' . esc_attr( (string) $spec[ $attr ] ) . '"';
                    }
                }

                $html .= '<textarea id="cpms-pp-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" data-field="' . esc_attr( $name ) . '" rows="2"' . $attr_str . '>' . esc_textarea( $initial_val ) . '</textarea>';
            } else {
                $attr_str = '';

                foreach ( [ 'placeholder', 'autocomplete', 'inputmode', 'dir' ] as $attr ) {
                    if ( isset( $spec[ $attr ] ) ) {
                        $attr_str .= ' ' . esc_attr( $attr ) . '="' . esc_attr( (string) $spec[ $attr ] ) . '"';
                    }
                }

                $html .= '<input id="cpms-pp-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $spec['type'] ) . '" data-field="' . esc_attr( $name ) . '" value="' . esc_attr( $initial_val ) . '"' . $attr_str . '>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '<div class="cpms-pp-profile__actions">';
        $html .= '<button type="submit" class="cpms-pp-btn cpms-pp-btn--primary" data-role="profile-save">ذخیرهٔ اطلاعات</button>';
        $html .= '</div>';
        $html .= '</form>';

        return $html;
    }

    /**
     * بخشِ اعلان‌های داخلی (Slice 2) — سرور-رندر از پاسخِ واقعیِ G6؛ بدون polling.
     */
    private static function notifications_section( array $inbox, \DateTimeZone $tz ): string {
        $rows   = is_array( $inbox['notifications'] ?? null ) ? $inbox['notifications'] : [];
        $unread = (int) ( $inbox['unread_count'] ?? 0 );

        $html = '<section class="cpms-patient-portal__notifications" data-role="' . esc_attr( self::NOTIFICATIONS_SECTION_ROLE ) . '" aria-labelledby="cpms-patient-portal-notifications-title">'
            . '<h2 id="cpms-patient-portal-notifications-title">اعلان‌ها';
        if ( $unread > 0 ) {
            $html .= ' <span class="cpms-badge cpms-warn" data-role="' . esc_attr( self::NOTIFICATIONS_BADGE_ROLE ) . '" data-unread-count="' . $unread . '">' . $unread . ' خوانده‌نشده</span>';
        }
        $html .= '</h2>';

        if ( $rows === [] ) {
            return $html
                . '<div class="cpms-empty"><span class="cpms-empty-icon" aria-hidden="true">🔔</span>'
                . '<span class="cpms-empty-title">هنوز اعلانی ندارید</span>'
                . '<span class="cpms-empty-desc">تغییرات نوبت‌ها و یادآوری‌های مطب همین‌جا نمایش داده می‌شود.</span></div>'
                . '</section>';
        }

        $html .= '<p class="description">آخرین تغییرات نوبت‌ها و یادآوری‌های مطب؛ موارد خوانده‌نشده با نشانِ «جدید» مشخص می‌شوند.</p>';
        if ( $unread > 0 ) {
            $html .= '<p><button type="button" class="button" data-role="' . esc_attr( self::NOTIFICATIONS_MARK_ALL_ROLE ) . '">علامت‌گذاری همه به‌عنوان خوانده‌شده</button></p>';
        }
        $html .= '<div class="notice notice-error inline cpms-patient-portal__error" role="alert" data-role="' . esc_attr( self::NOTIFICATIONS_ERROR_ROLE ) . '" hidden><p></p></div>'
            . '<ul class="cpms-patient-portal__notification-list">';
        foreach ( $rows as $row ) {
            if ( is_array( $row ) ) {
                $html .= self::notification_row( $row, $tz );
            }
        }

        return $html . '</ul></section>';
    }

    /**
     * یک ردیفِ اعلان — عنوان/متن از سرور (esc_html)، وضعیتِ خوانده/نخوانده در
     * `data-read` («0»/«1») و برچسبِ متنی؛ فقط شناسهٔ خودِ اعلان منتشر می‌شود.
     *
     * @param array<string, mixed> $row یک ردیفِ پاسخِ G6 (id/title/body/read_at/created_at).
     */
    private static function notification_row( array $row, \DateTimeZone $tz ): string {
        $is_read = ! empty( $row['read_at'] );

        return '<li class="cpms-patient-portal__notification' . ( $is_read ? ' is-read' : ' is-unread' ) . '"'
            . ' data-role="' . esc_attr( self::NOTIFICATION_ROW_ROLE ) . '"'
            . ' data-notification-id="' . (int) ( $row['id'] ?? 0 ) . '" data-read="' . ( $is_read ? '1' : '0' ) . '">'
            . '<div class="cpms-patient-portal__notification-head">'
            . '<strong>' . esc_html( (string) ( $row['title'] ?? '' ) ) . '</strong>'
            . ( $is_read ? '<span class="cpms-badge">خوانده‌شده</span>' : '<span class="cpms-badge cpms-warn">جدید</span>' )
            . '</div>'
            . '<p>' . esc_html( (string) ( $row['body'] ?? '' ) ) . '</p>'
            . self::notification_time( (string) ( $row['created_at'] ?? '' ), $tz )
            . '</li>';
    }

    /**
     * زمانِ ثبتِ اعلان (UTC در DB) → `<time datetime="ISO-8601">` با برچسبِ جلالی و
     * ساعتِ محلیِ Clinic — همان قالبِ جدول‌های همین صفحه (`1405/07/14` و `HH:MM`, dir=ltr).
     */
    private static function notification_time( string $created_at_utc, \DateTimeZone $tz ): string {
        $utc = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $created_at_utc, new \DateTimeZone( 'UTC' ) );
        if ( $utc === false ) {
            return '';
        }
        $local = $utc->setTimezone( $tz );

        return '<time class="description" datetime="' . esc_attr( $utc->format( DATE_ATOM ) ) . '">'
            . '<span dir="ltr">' . esc_html( Jalali::formatYmd( $local->format( 'Y-m-d' ) ) . ' ' . $local->format( 'H:i' ) ) . '</span></time>';
    }

    /**
     * منطقهٔ زمانیِ نمایش: تنظیمِ Clinicِ محیطی؛ اگر Scope مبهم باشد (بیمار عضو
     * هیچ Clinicی نیست) یا مقدار نامعتبر باشد، منطقهٔ زمانیِ سایت — یک بار در هر رندر.
     */
    private static function clinic_timezone(): \DateTimeZone {
        try {
            return new \DateTimeZone( App::settings()->clinicTimezone() );
        } catch ( \Exception ) {
            return wp_timezone();
        }
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
     * را حمل می‌کند. `data-cpms-confirm`: تأییدِ قابل‌دسترس در
     * `assets/js/cpms-patient-portal.js` (و legacy cpms-admin.js در wp-admin)
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
