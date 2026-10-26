<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;

/**
 * منوی واحد «مدیریت مطب» — تنها ورودیِ wp-admin برای همهٔ صفحات CPMS.
 *
 * قواعد (تأییدشدهٔ مالک محصول):
 *  - **دقیقاً یک** منوی Top-Level با عنوان «مدیریت مطب» وجود دارد؛ هیچ صفحهٔ CPMS
 *    دیگر منوی مستقل، یا ورودی در «تنظیمات»/«ابزارها» نمی‌سازد.
 *  - زیرمنو کوتاه و بخش‌محور است: هر بخش یک صفحهٔ مقصد دارد. صفحات جزئی‌تر
 *    (ثبت‌شده اما پنهان از زیرمنو) از طریق «ناوبری بخش‌ها» که بالای هر صفحهٔ
 *    CPMS رندر می‌شود و همچنین از طریق همان صفحات بخش قابل دسترسی‌اند.
 *
 * قواعد امنیتی (تغییرناپذیر):
 *  - capability منو **هرگز** مجوز نمی‌دهد. منوی ظرف با `read` ثبت می‌شود تا
 *    صفحات نقش‌محور (پزشک/منشی/حسابدار/بیمار) زیر آن دیده شوند، اما هر صفحه
 *    capability خودش را دارد و `render()` هر صفحه همچنان مستقل بررسی می‌کند.
 *  - ظرف `read` هرگز به کاربری که به هیچ صفحهٔ CPMS دسترسی ندارد نمایش داده
 *    نمی‌شود (`hideWhenUnreachable()`).
 *  - Parent شدن زیر یک منو هیچ capability اضافه‌ای به فرزند نمی‌دهد: WordPress
 *    برای هر آیتم زیرمنو capability همان آیتم را می‌سنجد.
 */
final class CpmsAdminMenu
{
    /** شناسهٔ منوی واحد (برای سازگاری با کد/مستندات موجود). */
    public const MENU_SLUG = 'cpms';

    /** اسلاگِ صفحهٔ داشبورد = اسلاگِ منوی واحد (landing صفحهٔ Top-Level). */
    public const PAGE_SLUG = CpmsDashboard::PAGE_SLUG;

    /**
     * Capability **فقط** برای ظرف منو.
     *
     * `read` عمداً انتخاب شده تا صفحاتی که با `read` محافظت می‌شوند (مثل
     * سازمان‌ها/نقش‌های سفارشی/واگذاری مالی) زیر همین منو دیده شوند بدون آنکه
     * یک capability گسترده به کاربران داده شود. مجوز واقعی هر صفحه مستقل است.
     */
    public const CONTAINER_CAPABILITY = 'read';

    /**
     * بخش‌ها: کلید => عنوان فارسی. ترتیب همین آرایه، ترتیب نمایش در ناوبری است.
     *
     * @var array<string, string>
     */
    public const SECTIONS = [
        'home'     => 'داشبورد',
        'operations' => 'عملیات مطب',
        'patients' => 'بیماران',
        'finance'  => 'مالی و تسویه',
        'org'      => 'سازمان و درمانگاه',
        'staff'    => 'کاربران و دسترسی',
        'tools'    => 'پیامک و ابزارها',
        'system'   => 'سیستم و راه‌اندازی',
    ];

    /**
     * فهرست کامل صفحات CPMS — منبعِ واحدِ حقیقت برای منو و ناوبری.
     *
     * هر ردیف: slug => [برچسب, بخش, capability]. `visible` یعنی زیرمنوی مستقیم
     * زیر «مدیریت مطب»؛ بقیه از ناوبری بخش‌ها (و URL مستقیم) در دسترس‌اند.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3?: bool}>
     */
    public const PAGES = [
        // --- داشبورد -------------------------------------------------------
        'cpms-dashboard' => [ 'داشبورد', 'home', RolesAndCapabilities::CONFIG, true ],
        // --- عملیات مطب ----------------------------------------------------
        'cpms-queue' => [ 'صف امروز', 'operations', RolesAndCapabilities::QUEUE_READ, true ],
        'cpms-doctor' => [ 'امروز پزشک', 'operations', RolesAndCapabilities::QUEUE_READ, true ],
        'cpms-handwriting' => [ 'دست‌خط', 'operations', RolesAndCapabilities::NOTE_CREATE, false ],
        'cpms-prescription-print' => [ 'چاپ نسخه', 'operations', RolesAndCapabilities::RX_READ, false ],
        // --- بیماران -------------------------------------------------------
        'cpms-patients' => [ 'بیماران', 'patients', RolesAndCapabilities::PATIENT_READ, true ],
        // --- مالی ----------------------------------------------------------
        'cpms-finance' => [ 'مالی و تسویه', 'finance', RolesAndCapabilities::FINANCE_READ, true ],
        'cpms-finance-delegation' => [ 'واگذاری مالی حساس', 'finance', 'read', false ],
        // --- سازمان و درمانگاه ---------------------------------------------
        'cpms-organizations' => [ 'سازمان‌ها', 'org', 'read', true ],
        'cpms-clinic-management' => [ 'مدیریت درمانگاه‌ها', 'org', 'read', false ],
        'cpms-organization-managers' => [ 'مدیران سازمان', 'org', 'read', false ],
        'cpms-organization-manager-bootstrap' => [ 'فعال‌سازی مدیر اولیهٔ سازمان', 'org', 'manage_options', false ],
        'cpms-facility-setup' => [ 'راه‌اندازی مرکز چنددرمانگاهی', 'org', 'read', false ],
        'cpms-locations' => [ 'شعبه‌ها و محل‌ها', 'org', RolesAndCapabilities::CONFIG, true ],
        // --- کاربران و دسترسی ----------------------------------------------
        'cpms-staff' => [ 'کاربران و دسترسی‌ها', 'staff', RolesAndCapabilities::CONFIG, true ],
        'cpms-clinicians' => [ 'پزشکان و برنامه کاری', 'staff', RolesAndCapabilities::CONFIG, true ],
        'cpms-roles' => [ 'مجوزهای پیشرفته', 'staff', 'manage_options', true ],
        'cpms-custom-roles' => [ 'نقش‌های سفارشی', 'staff', 'read', false ],
        // --- پیامک و ابزارها -----------------------------------------------
        'cpms-sms' => [ 'پیامک و اعلان‌ها', 'tools', RolesAndCapabilities::SMS_CONFIG, true ],
        'cpms-shortcodes' => [ 'کدهای کوتاه و نمایش در سایت', 'tools', 'manage_options', false ],
        // --- سیستم و راه‌اندازی ---------------------------------------------
        'cpms-system' => [ 'سلامت سیستم', 'system', RolesAndCapabilities::CONFIG, true ],
        'cpms-settings' => [ 'فنی و لاگ', 'system', RolesAndCapabilities::CONFIG, true ],
        'cpms-wizard' => [ 'راه‌اندازی', 'system', RolesAndCapabilities::CONFIG, true ],
        'cpms-first-practice-setup' => [ 'راه‌اندازی مطب من', 'system', 'manage_options', false ],
    ];

    /** والد مشترک همهٔ صفحات CPMS در wp-admin. */
    public static function parentSlug(): string
    {
        return CpmsDashboard::PAGE_SLUG;
    }

    /**
     * ردیف‌های ثبت‌شده اما پنهان از زیرمنو (slug => row) — این‌جا نگه داشته
     * می‌شوند تا «ثبت‌شده بودن» قابلِ بازرسی بماند.
     *
     * @var array<string, array<int, mixed>>
     */
    private static array $hiddenRows = [];

    /**
     * صفحات legacy که قبلاً در Tools/Settings بودند و باید به منوی واحد هدایت شوند.
     *
     * @var array<int, string>
     */
    private const LEGACY_REHOMED = [
        'cpms-system',
        'cpms-settings',
        'cpms-clinicians',
        'cpms-roles',
        'cpms-sms',
        'cpms-shortcodes',
        'cpms-organization-manager-bootstrap',
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 1);
        // ترتیب/پنهان‌سازی زیرمنو پس از ثبت همهٔ صفحات (اولویت پیش‌فرض 10).
        add_action('admin_menu', [self::class, 'orderSubmenu'], 50);
        add_action('admin_menu', [self::class, 'hideWhenUnreachable'], 999);
        // Legacy redirect باید در admin_menu اجرا شود نه admin_init: چون wp-admin/menu.php
        // در انتهای خود user_can_access_admin_page() را اجرا می‌کند و اگر صفحه اصلاً در
        // menu ثبت نشده باشد wp_die(403) می‌دهد — این کار قبل از admin_init اتفاق می‌افتد.
        // بنابراین redirectLegacy باید در admin_menu (قبل از آن بررسی) بتواند مسیر قدیمی را
        // به «مدیریت مطب» هدایت کند وگرنه مدیر به جای محتوا خطای 403 می‌بیند.
        add_action('admin_menu', [self::class, 'redirectLegacy'], 5);
        add_action('in_admin_header', [self::class, 'renderSectionNav'], 20);
        add_filter('plugin_action_links_' . self::basename(), [self::class, 'actionLinks']);
        add_action('admin_notices', [self::class, 'onboardingNotice']);
    }

    public static function basename(): string
    {
        return plugin_basename(defined('CPMS_PLUGIN_FILE') ? CPMS_PLUGIN_FILE : __FILE__);
    }

    /**
     * ثبت منوی واحد: **یک** Top-Level. زیرمنوها را هر صفحهٔ CPMS خودش زیر
     * `parentSlug()` ثبت می‌کند (capability و callback نزدِ صاحبِ صفحه می‌ماند).
     */
    public static function menu(): void
    {
        add_menu_page(
            'مدیریت مطب',
            'مدیریت مطب',
            self::CONTAINER_CAPABILITY,
            self::parentSlug(),
            [CpmsDashboard::class, 'render'],
            'dashicons-building',
            3
        );

        // آیتم تکراری‌اسلاگ: کلیک روی آیتم Top-Level به داشبورد می‌رود و
        // WordPress آیتم تکراری نمی‌سازد.
        add_submenu_page(
            self::parentSlug(),
            'داشبورد',
            'داشبورد',
            RolesAndCapabilities::CONFIG,
            self::PAGE_SLUG,
            [CpmsDashboard::class, 'render']
        );
    }

    /**
     * یک صفحهٔ «جزئی» را ثبت‌شده اما نامرئی در زیرمنو می‌کند.
     *
     * چرا: صفحات جزئی باید زیر «مدیریت مطب» ثبت شوند تا WordPress مجوز و
     * hookname درست را بسازد، اما فهرست زیرمنو نباید بیست‌وچند آیتم تخت شود.
     * کشفِ آن‌ها از طریق «ناوبری بخش‌ها» (renderSectionNav) و URL مستقیم است.
     *
     * نکتهٔ امنیتی: حذف از `$GLOBALS['submenu']` فقط نمایش است؛ مجوز صفحه
     * همان capability ثبت‌شدهٔ خودش است و `$_registered_pages` دست‌نخورده می‌ماند.
     */
    public static function hideFromSubmenu(string $slug): void
    {
        $parent = self::parentSlug();
        if (!isset($GLOBALS['submenu'][$parent]) || !is_array($GLOBALS['submenu'][$parent])) {
            return;
        }

        foreach ($GLOBALS['submenu'][$parent] as $key => $row) {
            if (is_array($row) && ( $row[2] ?? '' ) === $slug) {
                // نگه‌داشتنِ ردیف برای بازرسی/تست: «پنهان از زیرمنو» ≠ «ثبت‌نشده».
                self::$hiddenRows[$slug] = $row;
                unset($GLOBALS['submenu'][$parent][$key]);
            }
        }

        $GLOBALS['submenu'][$parent] = array_values($GLOBALS['submenu'][$parent]);
    }

    /**
     * ردیف‌های ثبت‌شده‌ای که عمداً از زیرمنو پنهان شده‌اند (slug => row).
     *
     * فقط برای بازرسیِ تست/ابزار؛ هیچ مسیر تصمیم‌گیری محصولی به آن وابسته نیست.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function hiddenSubmenuRows(): array
    {
        return self::$hiddenRows;
    }

    /** پاک‌سازیِ ردیف‌های پنهان (تست‌ها بین سناریوها آلوده نشوند). */
    public static function resetHiddenRows(): void
    {
        self::$hiddenRows = [];
    }

    /**
     * مرتب‌سازی زیرمنو بر اساس بخش‌ها و حذفِ دفاعیِ صفحاتِ نامرئی.
     *
     * آخرین مرحلهٔ admin_menu تا همهٔ ثبت‌کننده‌ها (اولویت پیش‌فرض 10) اجرا شده
     * باشند. ثبت‌های ناشناخته (افزونهٔ سوم) حفظ می‌شوند و انتهای فهرست می‌آیند.
     */
    public static function orderSubmenu(): void
    {
        $parent = self::parentSlug();
        if (!isset($GLOBALS['submenu'][$parent]) || !is_array($GLOBALS['submenu'][$parent])) {
            return;
        }

        $rows = [];
        foreach ($GLOBALS['submenu'][$parent] as $row) {
            if (is_array($row) && isset($row[2])) {
                $rows[(string) $row[2]] = $row;
            }
        }

        $ordered = [];
        foreach (self::PAGES as $slug => $page) {
            if (!isset($rows[$slug]) || empty($page[3])) {
                continue;
            }
            $ordered[] = $rows[$slug];
            unset($rows[$slug]);
        }
        foreach ($rows as $slug => $row) {
            if (isset(self::PAGES[$slug])) {
                continue; // صفحهٔ شناخته‌شدهٔ «نامرئی»
            }
            $ordered[] = $row;
        }

        $GLOBALS['submenu'][$parent] = $ordered;
    }

    /**
     * صفحاتی که زیرمنوی مستقیم می‌گیرند (به ترتیب بخش‌ها) — برای تست/مستندسازی.
     *
     * @return list<string>
     */
    public static function visibleSlugs(): array
    {
        $slugs = [];
        foreach (self::SECTIONS as $section => $label) {
            foreach (self::PAGES as $slug => $page) {
                if ($page[1] === $section && !empty($page[3])) {
                    $slugs[] = $slug;
                }
            }
        }

        return $slugs;
    }

    /**
     * ظرف منو را از کاربری که به هیچ صفحهٔ CPMS دسترسی ندارد پنهان می‌کند.
     *
     * بدون این، capability ظرف (`read`) منوی «مدیریت مطب» را به هر کاربر
     * واردشده نشان می‌داد. این تابع **فقط نمایش** را تغییر می‌دهد؛ حذف منو
     * هیچ capability‌ای اضافه/کم نمی‌کند و بررسی مجوز هر صفحه سرجای خود است.
     */
    public static function hideWhenUnreachable(): void
    {
        if (self::hasAnyAccessiblePage()) {
            return;
        }

        remove_menu_page(self::parentSlug());
    }

    /** آیا کاربر جاری حداقل به یک صفحهٔ CPMS دسترسی دارد؟ */
    public static function hasAnyAccessiblePage(): bool
    {
        foreach (self::PAGES as $page) {
            if (current_user_can((string) $page[2])) {
                return true;
            }
        }

        return false;
    }

    /**
     * صفحات قابل‌دسترسِ کاربر جاری، گروه‌بندی‌شده بر اساس بخش.
     *
     * @return array<string, array<string, string>> section => slug => label
     */
    public static function accessibleSections(): array
    {
        $groups = [];
        foreach (self::SECTIONS as $section => $section_label) {
            foreach (self::PAGES as $slug => $page) {
                if ($page[1] !== $section || !current_user_can((string) $page[2])) {
                    continue;
                }
                $groups[$section][$slug] = (string) $page[0];
            }
        }

        return $groups;
    }

    /**
     * ناوبری بخش‌ها — بالای هر صفحهٔ CPMS رندر می‌شود.
     *
     * چرا اینجا و نه در هر صفحه: صفحات CPMS پانزده‌تا هستند و تکرار یک فراخوانی
     * در همهٔ آن‌ها ناسازگاری می‌سازد؛ یک هوک، یک رفتار.
     */
    public static function renderSectionNav(): void
    {
        $current = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط تشخیص صفحهٔ نمایشی
        // معیار «صفحهٔ CPMS» رَجیستریِ همین کلاس است، نه allowlistِ assets:
        // دو صفحهٔ مدیریت سازمان عمداً از آن allowlist بیرون‌اند (قرارداد
        // IranGeographyAddressFormsTest) اما باید ناوبریِ بخش‌ها را هم داشته باشند.
        if ($current === '' || !isset(self::PAGES[$current])) {
            return;
        }

        $groups = self::accessibleSections();
        if ($groups === []) {
            return;
        }

        echo '<nav class="cpms-sectionnav" dir="rtl" aria-label="' . esc_attr('ناوبری بخش‌های مدیریت مطب') . '">';
        foreach ($groups as $section => $pages) {
            echo '<div class="cpms-sectionnav__group">';
            echo '<span class="cpms-sectionnav__title">' . esc_html((string) (self::SECTIONS[$section] ?? $section)) . '</span>';
            echo '<span class="cpms-sectionnav__links">';
            foreach ($pages as $slug => $label) {
                $is_current = $slug === $current;
                echo '<a class="cpms-sectionnav__link' . ($is_current ? ' is-current' : '') . '"'
                    . ($is_current ? ' aria-current="page"' : '')
                    . ' href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '">'
                    . esc_html($label) . '</a>';
            }
            echo '</span></div>';
        }
        echo '</nav>';
    }

    /**
     * Redirect قدیمی (backward-compat): اگر کسی به tools.php?page=cpms-* یا
     * options-general.php?page=cpms-* برود، به صفحهٔ جدیدِ تحت «مدیریت مطب» هدایت می‌شود.
     */
    public static function redirectLegacy(): void
    {
        if (!is_admin() || !isset($_GET['page'])) {
            return;
        }
        $page = sanitize_key(wp_unslash($_GET['page']));
        $allowed = self::LEGACY_REHOMED;
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
     * True when the current user is a WordPress administrator (`manage_options`)
     * and the installation has a single eligible, unclaimed initial Clinic
     * awaiting the one-shot first-practice bootstrap.
     */
    public static function is_first_practice_setup_pending(): bool
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        try {
            return App::first_practice_setup_service()->first_setup_candidate() !== null;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * setup.completed for admin chrome. null means Clinic scope is missing or
     * ambiguous — callers must not guess a Clinic and must not throw.
     */
    public static function setup_completed_flag(): ?bool
    {
        try {
            return (bool) App::settings()->get( 'setup.completed', false );
        } catch ( ScopeRequiredException ) {
            return null;
        }
    }

    public static function ambiguous_clinic_guidance(): string
    {
        return 'امکان تعیین مطب فعال وجود ندارد. نصب چندمطب است و انتخاب صریح مطب لازم است. راه‌اندازی اولیه در این حالت در دسترس نیست.';
    }

    /**
     * Canonical onboarding/setup URL: routes eligible first-run WordPress
     * administrators to the dedicated first-practice bootstrap before the
     * established Clinic-management wizard.
     */
    public static function setup_url(): string
    {
        // Single visible onboarding entry point: the CPMS wizard. The
        // wizard embeds the first-practice bootstrap/recovery panel (via
        // FirstPracticeSetupService) when the installation is eligible.
        $slug = CpmsSetupWizard::PAGE_SLUG;

        return admin_url( 'admin.php?page=' . $slug );
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
            $setup_flag    = self::setup_completed_flag();
            $setup_pending = $setup_flag === false;
            $primary_label = $setup_pending ? 'راه‌اندازی' : 'داشبورد CPMS';
            $primary_url   = $setup_pending
                ? self::setup_url()
                : admin_url('admin.php?page=' . CpmsDashboard::PAGE_SLUG);
            array_unshift( $links, '<a href="' . esc_url( $primary_url ) . '">' . esc_html( $primary_label ) . '</a>' );
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page-slug check, not a trusted input value.
        if ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === CpmsSetupWizard::PAGE_SLUG ) {
            // Never render (and never consume the one-shot flag) ON the setup
            // wizard itself: a "install complete, start setup" banner is
            // redundant while already standing on that exact page, and the
            // automatic post-activation redirect (CpmsActivationRedirect)
            // lands the admin here directly -- without this guard, that very
            // landing would silently burn the one-shot `cpms_onboarding_seen`
            // transient and the notice would never show again on the
            // dashboard the admin actually sees afterward.
            return;
        }
        $setup_flag = self::setup_completed_flag();
        if ( $setup_flag === true ) {
            return;
        }
        if (get_transient('cpms_onboarding_seen')) {
            return;
        }
        set_transient('cpms_onboarding_seen', 1, DAY_IN_SECONDS);
        if ( $setup_flag === null ) {
            printf(
                '<div class="notice notice-warning is-dismissible" data-cpms-scope-required="1"><p>%s</p></div>',
                esc_html( self::ambiguous_clinic_guidance() )
            );
            return;
        }
        printf(
            '<div class="notice notice-info is-dismissible"><p><strong>CPMS نصب شد</strong> — برای شروع، راه‌اندازی اولیه را تکمیل کنید. <a class="button button-primary" href="%s">شروع راه‌اندازی</a> <a class="button" href="%s">سلامت سیستم</a> <a class="button" href="%s">راهنما</a></p></div>',
            esc_url( self::setup_url() ),
            esc_url(admin_url('admin.php?page=cpms-system')),
            esc_url(admin_url('admin.php?page=cpms-system'))
        );
    }
}
