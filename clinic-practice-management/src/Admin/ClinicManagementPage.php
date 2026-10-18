<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Onboarding\OrganizationClinicProvisioningService;
use ClinicCore\Bootstrap\App;

/**
 * Post-onboarding Clinic settings & lifecycle — Organization-scoped, RTL Classic.
 *
 * Authority: the EXISTING Organization Manager authority only — a durable active
 * `organization_manager` membership for the exact Organization, resolved through
 * `cpms_org_clinic_create` with DENY > GRANT > preset. This slice introduces no new
 * Organization capability name and no WP Administrator / Clinic-membership shortcut.
 * No first-Clinic auto-selection, no cross-Clinic PHI. Medical, financial, audit and
 * backup history is preserved on suspend — only the Clinic status flag changes.
 */
final class ClinicManagementPage {

    public const PAGE              = 'cpms-clinic-management';
    public const ACTION_UPDATE     = 'cpms_update_organization_clinic';
    public const ACTION_LIFECYCLE  = 'cpms_clinic_lifecycle';
    private const NONCE_UPDATE     = 'cpms_update_organization_clinic';
    private const NONCE_LIFECYCLE  = 'cpms_clinic_lifecycle';
    private const NOTICE_KEY       = 'cpms_clinic_management_notice';

    public static function register(): void {

        add_action( 'admin_menu', [self::class, 'menu'] );
        add_action( 'admin_post_' . self::ACTION_UPDATE, [self::class, 'handle_update'] );
        add_action( 'admin_post_' . self::ACTION_LIFECYCLE, [self::class, 'handle_lifecycle'] );
    }

    public static function menu(): void {

        add_submenu_page(
            OrganizationManagerPage::PAGE,
            'مدیریت درمانگاه‌ها',
            'مدیریت درمانگاه‌ها',
            'read',
            self::PAGE,
            [self::class, 'render']
        );
    }

    public static function render(): void {

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'دسترسی ندارید.', 'cpms' ), 403 );
        }

        $actor_id = (int) get_current_user_id();
        $service  = App::organization_clinic_provisioning_service();

        $notice_key = self::NOTICE_KEY . '_' . $actor_id;
        $notice     = get_transient( $notice_key );
        if ( $notice !== false ) {
            delete_transient( $notice_key );
        }

        // Existing Organization Manager authority only: cpms_org_clinic_create
        // (DENY > GRANT > preset). No new Organization capability name is introduced
        // by this slice, and no WP Administrator / Clinic membership shortcut exists.
        $organizations = $service->organizations_for_manager( $actor_id, OrganizationClinicProvisioningService::CAPABILITY );

        $selected_org_id    = isset( $_GET['organization_id'] ) ? absint( $_GET['organization_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $selected_clinic_id = isset( $_GET['clinic_id'] ) ? absint( $_GET['clinic_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        echo '<div class="wrap" dir="rtl"><h1>مدیریت درمانگاه‌ها — تنظیم و چرخهٔ حیات</h1>';
        echo '<p class="description">تنظیم نام/نشانی/تلفن و فعال/غیرفعال‌سازی درمانگاه با اختیار صریح مدیر سازمان برای همان سازمان. افزودن درمانگاه ≠ افزودن شعبه: درمانگاه موجودیت مستقل در سازمان است، شعبه مکان عملیاتی داخل درمانگاه است. غیرفعال‌سازی هیچ پروندهٔ پزشکی، مالی، ممیزی یا پشتیبان را حذف نمی‌کند — فقط پرچم وضعیت تغییر می‌کند.</p>';

        if ( is_string( $notice ) && $notice !== '' ) {
            $is_error = str_starts_with( $notice, 'خطا' );
            echo '<div class="notice ' . ( $is_error ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        if ( $organizations === [] ) {
            echo '<div class="notice notice-warning"><p>برای این حساب، عضویت فعال مدیر سازمان با مجوز مدیریت درمانگاه ثبت نشده است. دسترسی مدیر وردپرس یا عضویت درمانگاه به‌تنهایی کافی نیست.</p></div></div>';
            return;
        }

        // Organization selector — no default auto-selection
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
        echo '<label for="cpms_cm_org">سازمان (انتخاب صریح)</label> ';
        echo '<select id="cpms_cm_org" name="organization_id" required><option value="">— انتخاب سازمان —</option>';
        foreach ( $organizations as $org ) {
            echo '<option value="' . esc_attr( (string) $org['id'] ) . '" ' . selected( $selected_org_id, (int) $org['id'], false ) . '>' . esc_html( $org['name'] ) . '</option>';
        }
        echo '</select> <button class="button">اعمال سازمان</button>';
        echo '<p class="description">انتخاب فقط context نمایشی است؛ هر تغییر روی سرور دوباره مجازبودن عضویت سازمانی را بررسی می‌کند.</p>';
        echo '</form>';

        if ( $selected_org_id <= 0 ) {
            echo '<div class="notice notice-info"><p>لطفاً ابتدا سازمان را به‌صورت صریح انتخاب کنید — هیچ سازمان پیش‌فرضی وجود ندارد.</p></div>';
            // Facility catalog note
            self::render_catalog_note();
            echo '</div>';
            return;
        }

        // Verify actor actually manages this org
        $is_manager = false;
        foreach ( $organizations as $org ) {
            if ( (int) $org['id'] === $selected_org_id ) {
                $is_manager = true;
                break;
            }
        }
        if ( ! $is_manager ) {
            echo '<div class="notice notice-error"><p>سازمان انتخاب‌شده برای این حساب مجاز نیست.</p></div></div>';
            return;
        }

        $clinics = $service->clinics_for_organization( $actor_id, $selected_org_id );
        echo '<h2>درمانگاه‌های سازمان انتخاب‌شده</h2>';
        if ( $clinics === [] ) {
            echo '<p>در این سازمان هنوز درمانگاهی ثبت نشده. ایجاد درمانگاه: <a href="' . esc_url( admin_url( 'admin.php?page=' . OrganizationManagerPage::PAGE ) ) . '">مدیریت سازمان و درمانگاه‌ها</a></p>';
            self::render_catalog_note();
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped" role="presentation"><thead><tr><th>نام</th><th>شناسه</th><th>منطقهٔ زمانی</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
        foreach ( $clinics as $clinic ) {
            $status_badge = $clinic['status'] === 'active' ? '<span class="cpms-badge cpms-ok">فعال</span>' : '<span class="cpms-badge cpms-danger">غیرفعال</span>';
            $active       = $clinic['status'] === 'active';
            echo '<tr><td>' . esc_html( $clinic['name'] ) . '</td><td class="ltr">' . esc_html( $clinic['slug'] ) . '</td><td class="ltr">' . esc_html( $clinic['timezone'] ) . '</td><td>' . wp_kses( $status_badge, array( 'span' => array( 'class' => array() ) ) ) . '</td><td>';
            echo '<a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&organization_id=' . $selected_org_id . '&clinic_id=' . $clinic['id'] ) ) . '">تنظیم و چرخهٔ حیات</a> ';
            // Lifecycle button inline
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-inline-start:6px">';
            wp_nonce_field( self::NONCE_LIFECYCLE );
            echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_LIFECYCLE ) . '">';
            echo '<input type="hidden" name="organization_id" value="' . esc_attr( (string) $selected_org_id ) . '">';
            echo '<input type="hidden" name="clinic_id" value="' . esc_attr( (string) $clinic['id'] ) . '">';
            echo '<input type="hidden" name="active" value="' . esc_attr( $active ? '0' : '1' ) . '">';
            echo '<button class="button button-small" data-cpms-confirm="' . esc_attr( $active ? 'آیا از غیرفعال‌سازی این درمانگاه اطمینان دارید؟ سابقهٔ پزشکی/مالی/ممیزی حذف نمی‌شود.' : 'فعال‌سازی مجدد درمانگاه؟' ) . '">' . esc_html( $active ? 'غیرفعال‌سازی' : 'فعال‌سازی مجدد' ) . '</button>';
            echo '</form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><span data-cpms-clinic-lifecycle-hint="1">هر تغییر وضعیت در یک تراکنشِ قفل‌شده ثبت و زنجیرهٔ ممیزی آن نگهداری می‌شود؛ اجرای هم‌زمان امن است و تلاش تکراری با همان وضعیت بدون رویداد تکراری ممیزی انجام می‌شود.</span> مدیریت <strong>شعبه‌ها</strong> (ایجاد شعبهٔ دوم مطب/مرکز) جداگانه و Clinic-scoped است: <a href="' . esc_url( admin_url( 'admin.php?page=cpms-locations' ) ) . '">شعبه‌ها و محل‌ها</a> — در آن‌جا Clinic به‌صورت صریح و با مجوز Clinic انتخاب می‌شود.</p>';

        if ( $selected_clinic_id > 0 ) {
            $clinic_row = null;
            foreach ( $clinics as $c ) {
                if ( $c['id'] === $selected_clinic_id ) {
                    $clinic_row = $c;
                    break;
                }
            }
            if ( $clinic_row !== null ) {
                // Load full row for address/phone
                $full = App::db()->fetchRow(
                    'SELECT name, address, phone, status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                    [$selected_clinic_id]
                );
                echo '<h2>ویرایش تنظیمات درمانگاه: ' . esc_html( $clinic_row['name'] ) . '</h2>';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:640px">';
                wp_nonce_field( self::NONCE_UPDATE );
                echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_UPDATE ) . '">';
                echo '<input type="hidden" name="organization_id" value="' . esc_attr( (string) $selected_org_id ) . '">';
                echo '<input type="hidden" name="clinic_id" value="' . esc_attr( (string) $selected_clinic_id ) . '">';
                echo '<table class="form-table"><tbody>';
                echo '<tr><th><label for="cm_name">نام درمانگاه *</label></th><td><input id="cm_name" name="name" required maxlength="190" class="regular-text" value="' . esc_attr( (string) ( $full['name'] ?? '' ) ) . '"></td></tr>';
                echo '<tr><th><label for="cm_address">نشانی</label></th><td><input id="cm_address" name="address" maxlength="255" class="regular-text" value="' . esc_attr( (string) ( $full['address'] ?? '' ) ) . '"><p class="description">حداکثر ۲۵۵ نویسه؛ خالی = بدون نشانی.</p></td></tr>';
                echo '<tr><th><label for="cm_phone">تلفن</label></th><td><input id="cm_phone" name="phone" maxlength="32" class="regular-text ltr" value="' . esc_attr( (string) ( $full['phone'] ?? '' ) ) . '"><p class="description">حداکثر ۳۲ نویسه.</p></td></tr>';
                echo '<tr><th>شناسه (slug)</th><td class="ltr">' . esc_html( $clinic_row['slug'] ) . ' <code>(غیرقابل تغییر — کلید یکتا)</code></td></tr>';
                echo '<tr><th>منطقهٔ زمانی</th><td class="ltr">' . esc_html( $clinic_row['timezone'] ) . ' <code>(ثابت — حقیقت عملیاتی شعبه‌ها از Location است)</code></td></tr>';
                echo '<tr><th>وضعیت</th><td>' . esc_html( $clinic_row['status'] === 'active' ? 'فعال' : 'غیرفعال (تاریخچه حفظ می‌شود)' ) . '</td></tr>';
                echo '</tbody></table>';
                echo '<p><button class="button button-primary">ذخیرهٔ تنظیمات</button> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&organization_id=' . $selected_org_id ) ) . '">انصراف</a></p>';
                echo '</form>';
            }
        }

        self::render_catalog_note();
        echo '</div>';
    }

    private static function render_catalog_note(): void {

        echo '<div class="notice notice-info" style="margin-top:20px"><p><strong>کاتالوگ دوازده‌نوعی مرکز:</strong> ' . esc_html( 'این صفحه مرز مستأجر را تغییر نمی‌دهد؛ نوع مرکز فقط انتخاب نمایشی/پیکربندی است.' ) . '</p>';
        echo '<p class="description">گونه‌های مصوب (۱۲ مورد): مطب پزشک، درمانگاه عمومی، درمانگاه تخصصی/پلی‌کلینیک، دندانپزشکی، تشخیصی/پاراکلینیکی، توانبخشی، سلامت روان، درمان تخصصی سرپایی، جراحی محدود/سرپایی، بیمارستان، مرکز جامع سلامت، سایر مراکز درمانی. جریان‌های «ساختمان پزشکی» و «بیمارستان/شبکه» در ویزارد هنوز به‌صورت خودکار قادر به پیکربندی چند-Clinic نیستند؛ ایجاد چند-Clinic فقط از مسیر <em>مدیریت سازمان</em> با اختیار صریح مجاز است. انتقالِ خودکارِ نوع مرکز (مثلاً مطب → بیمارستان) بدون تصمیم محصولیِ مصوب پشتیبانی نمی‌شود — دادهٔ موجود به تاریخچه تبدیل نمی‌شود و بخش بستری/HIS همچنان پشتیبانی نمی‌شود.</p>';
        echo '<p class="description">تفاوت روشن: <strong>افزودن درمانگاه</strong> موجودیت Clinic جدید در سازمان می‌سازد (با مکان اصلی اتمیک). <strong>افزودن شعبه</strong> مکان عملیاتی جدید داخل یک Clinic موجود می‌سازد — Clinic انتخابی به‌صورت صریح و با مجوز Clinic تعیین می‌شود و timezone آن حقیقت عملیاتی همان شعبه است.</p></div>';
    }

    // ================= Handlers =================

    public static function handle_update(): void {

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'دسترسی ندارید.', 'cpms' ), 403 );
        }
        check_admin_referer( self::NONCE_UPDATE );

        $actor_id        = (int) get_current_user_id();
        $organization_id = isset( $_POST['organization_id'] ) ? absint( $_POST['organization_id'] ) : 0;
        $clinic_id       = isset( $_POST['clinic_id'] ) ? absint( $_POST['clinic_id'] ) : 0;
        $name            = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $address         = array_key_exists( 'address', $_POST ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : null;
        $phone           = array_key_exists( 'phone', $_POST ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : null;

        // Normalize empty to null for phone/address handling inside service (it does too)
        if ( $address === '' ) {
            $address = null;
        }
        if ( $phone === '' ) {
            $phone = null;
        }

        try {
            $result = App::organization_clinic_provisioning_service()->update_clinic_profile(
                $actor_id,
                $organization_id,
                $clinic_id,
                ['name' => $name, 'address' => $address, 'phone' => $phone]
            );
            $notice = $result['noop'] ? 'تنظیمات بدون تغییر باقی ماند.' : 'تنظیمات درمانگاه با موفقیت به‌روز شد.';
        } catch ( \Throwable $e ) {
            $notice = 'خطا: به‌روزرسانی تنظیمات انجام نشد؛ سازمان/درمانگاه/مجوز یا داده‌ها معتبر نیستند و تغییری ثبت نشد.';
        }

        set_transient( self::NOTICE_KEY . '_' . $actor_id, $notice, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&organization_id=' . $organization_id . '&clinic_id=' . $clinic_id ) );
        exit;
    }

    public static function handle_lifecycle(): void {

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'دسترسی ندارید.', 'cpms' ), 403 );
        }
        check_admin_referer( self::NONCE_LIFECYCLE );

        $actor_id        = (int) get_current_user_id();
        $organization_id = isset( $_POST['organization_id'] ) ? absint( $_POST['organization_id'] ) : 0;
        $clinic_id       = isset( $_POST['clinic_id'] ) ? absint( $_POST['clinic_id'] ) : 0;
        $active_value    = isset( $_POST['active'] ) ? sanitize_text_field( wp_unslash( $_POST['active'] ) ) : '';

        $notice = 'خطا: درخواست نامعتبر است.';
        if ( $active_value === '0' || $active_value === '1' ) {
            try {
                $result = App::organization_clinic_provisioning_service()->set_clinic_status(
                    $actor_id,
                    $organization_id,
                    $clinic_id,
                    $active_value === '1'
                );
                $notice = $result['noop']
                    ? 'وضعیت قبلاً همین مقدار بود؛ تغییری ثبت نشد.'
                    : ( $active_value === '1' ? 'درمانگاه با موفقیت فعال شد.' : 'درمانگاه غیرفعال شد — تاریخچهٔ پزشکی/مالی/ممیزی/پشتیبان حذف نشد.' );
            } catch ( \Throwable $e ) {
                $notice = 'خطا: تغییر وضعیت انجام نشد؛ سازمان/درمانگاه/مجوز معتبر نیست یا خطای پایگاه داده رخ داد.';
            }
        }

        set_transient( self::NOTICE_KEY . '_' . $actor_id, $notice, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&organization_id=' . $organization_id ) );
        exit;
    }

    /**
     * Pure boundary for tests — no redirect/exit, no capability assumption beyond service.
     *
     * @param array<string,mixed> $in {organization_id, clinic_id, name, address, phone}
     * @return array{error:string, noop:bool, clinic_id:int}
     */
    public static function update_clinic_for_test( array $in, int $actor_id ): array {

        if ( $actor_id <= 0 || ! is_user_logged_in() || (int) get_current_user_id() !== $actor_id ) {
            return ['error' => 'کاربر احراز هویت‌شده معتبر نیست.', 'noop' => false, 'clinic_id' => 0];
        }
        $org_id    = (int) ( $in['organization_id'] ?? 0 );
        $clinic_id = (int) ( $in['clinic_id'] ?? 0 );
        try {
            $res = App::organization_clinic_provisioning_service()->update_clinic_profile(
                $actor_id,
                $org_id,
                $clinic_id,
                [
                    'name'    => (string) ( $in['name'] ?? '' ),
                    'address' => array_key_exists( 'address', $in ) ? $in['address'] : null,
                    'phone'   => array_key_exists( 'phone', $in ) ? $in['phone'] : null,
                ]
            );

            return ['error' => '', 'noop' => (bool) $res['noop'], 'clinic_id' => (int) $res['clinic_id']];
        } catch ( \Throwable $e ) {
            return ['error' => $e->getMessage(), 'noop' => false, 'clinic_id' => 0];
        }
    }

    /**
     * Pure lifecycle boundary for tests.
     *
     * @return array{error:string, noop:bool, clinic_id:int, status:string}
     */
    public static function lifecycle_for_test( int $actor_id, int $org_id, int $clinic_id, bool $active ): array {

        if ( $actor_id <= 0 || ! is_user_logged_in() || (int) get_current_user_id() !== $actor_id ) {
            return ['error' => 'کاربر احراز هویت‌شده معتبر نیست.', 'noop' => false, 'clinic_id' => 0, 'status' => ''];
        }
        try {
            $res = App::organization_clinic_provisioning_service()->set_clinic_status( $actor_id, $org_id, $clinic_id, $active );

            return ['error' => '', 'noop' => (bool) $res['noop'], 'clinic_id' => (int) $res['clinic_id'], 'status' => (string) $res['status']];
        } catch ( \Throwable $e ) {
            return ['error' => $e->getMessage(), 'noop' => false, 'clinic_id' => 0, 'status' => ''];
        }
    }
}
