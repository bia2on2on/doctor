<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Bootstrap\App;

/** Owner-approved, one-time bootstrap of the first Organization Manager. */
final class OrganizationManagerBootstrapPage {

    public const PAGE          = 'cpms-organization-manager-bootstrap';
    public const ACTION        = 'cpms_bootstrap_organization_manager';
    private const NONCE_ACTION = 'cpms_bootstrap_organization_manager';

    public static function menu(): void {

        // B-1: از «تنظیمات» به «مدیریت مطب». صفحهٔ راه‌اندازیِ یک‌بارمصرف در زیرمنوی
        // اصلی نمایش داده نمی‌شود (کشف از ناوبری بخش‌ها/URL مستقیم) اما ثبت‌شده و
        // قابل دسترسی برای دارندهٔ manage_options باقی می‌ماند.
        add_submenu_page(
            CpmsAdminMenu::parentSlug(),
            'فعال‌سازی مدیر اولیهٔ سازمان',
            'فعال‌سازی مدیر اولیهٔ سازمان',
            'manage_options',
            self::PAGE,
            [self::class, 'render']
        );
        CpmsAdminMenu::hideFromSubmenu( self::PAGE );
    }

    public static function render(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'دسترسی ندارید.', 'cpms' ), 403 );
        }

        $actor_id   = (int) get_current_user_id();
        $notice_key = 'cpms_org_bootstrap_notice_' . $actor_id;
        $notice     = get_transient( $notice_key );
        if ( $notice !== false ) {
            delete_transient( $notice_key );
        }

        $organizations = App::db()->fetchAll(
            'SELECT id, name, status FROM ' . App::db()->table( 'cpms_organizations' ) . ' ORDER BY name, id'
        );

        echo '<div class="wrap cpms-org-manager" dir="rtl"><h1>فعال‌سازی مدیر اولیهٔ سازمان</h1>';
        echo '<p>این عملیات فقط یک عضویت مدیر سازمان را در سازمانی که صریحاً انتخاب می‌کنید فعال می‌کند. نقش مدیر WordPress هیچ دسترسی بالینی، مالی، پروندهٔ بیمار، Clinic یا Location ایجاد نمی‌کند.</p>';
        echo '<p>هیچ سازمان یا کاربری به‌طور پیش‌فرض انتخاب نشده است. اگر سازمان قبلاً مدیر فعال، عضویت مدیریتی متعارض، یا وضعیت غیر‌فعال داشته باشد، عملیات رد می‌شود.</p>';

        if ( is_string( $notice ) && $notice !== '' ) {
            $error = str_starts_with( $notice, 'خطا:' );
            echo '<div class="notice ' . ( $error ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice ) . '</p></div>';
        }

        if ( $organizations === [] ) {
            echo '<div class="notice notice-warning"><p>سازمانی برای انتخاب وجود ندارد. سازمانی به‌طور خودکار ساخته یا انتخاب نمی‌شود.</p></div></div>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        wp_nonce_field( self::NONCE_ACTION );
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="cpms-bootstrap-organization">سازمان مشخص</label></th><td>';
        echo '<select id="cpms-bootstrap-organization" name="organization_id" required>';
        echo '<option value="">— انتخاب صریح سازمان —</option>';
        foreach ( $organizations as $organization ) {
            $label = (string) $organization['name'] . ' — #' . (int) $organization['id'];
            if ( $organization['status'] !== 'active' ) {
                $label .= ' (غیرفعال)';
            }
            echo '<option value="' . (int) $organization['id'] . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></td></tr><tr><th><label for="cpms-bootstrap-user">مدیر سازمانِ موردنظر</label></th><td>';
        wp_dropdown_users(
            [
                'name'              => 'target_user_id',
                'id'                => 'cpms-bootstrap-user',
                'show'              => 'display_name_with_login',
                'show_option_none'  => '— انتخاب صریح کاربر WordPress —',
                'option_none_value' => '0',
                'selected'          => 0,
                'class'             => 'regular-text',
            ]
        );
        echo '<p class="description">فقط همان حساب به عضویت organization_manager در سازمان انتخاب‌شده افزوده می‌شود؛ نقش‌های WordPress تغییر نمی‌کنند.</p>';
        echo '</td></tr><tr><th>تأیید نهایی</th><td>';
        echo '<label><input type="checkbox" name="confirm_bootstrap" value="1" required> تأیید می‌کنم سازمان و حساب مدیر بالا را بررسی کرده‌ام و فعال‌سازی اولیه را صریحاً درخواست می‌کنم.</label>';
        echo '</td></tr></tbody></table>';
        echo '<p><button class="button button-primary" type="submit">فعال‌سازی مدیر اولیه</button></p></form></div>';
    }

    /** Request-bound checks used by the POST handler and directly exercised by integration tests. */
    public static function valid_bootstrap_request( int $actor_id, array $request ): bool {

        if ( $actor_id <= 0 || $actor_id !== (int) get_current_user_id() || ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        if ( ! isset( $request['_wpnonce'] ) || ! is_string( $request['_wpnonce'] ) ) {
            return false;
        }
        if (
            ! self::is_positive_integer( $request['organization_id'] ?? null )
            || ! self::is_positive_integer( $request['target_user_id'] ?? null )
        ) {
            return false;
        }
        if ( ( $request['confirm_bootstrap'] ?? '' ) !== '1' ) {
            return false;
        }

        return wp_verify_nonce( sanitize_text_field( wp_unslash( $request['_wpnonce'] ) ), self::NONCE_ACTION ) !== false;
    }

    private static function is_positive_integer( mixed $value ): bool {

        if ( is_int( $value ) ) {
            return $value > 0;
        }

        return is_string( $value ) && $value !== '' && ctype_digit( $value ) && (int) $value > 0;
    }

    public static function handle(): void {

        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'دسترسی ندارید.', 'cpms' ), 403 );
        }
        // Verify the nonce at the entry point, before any request data is read.
        // check_admin_referer() with $die = false keeps the explicit 403 body
        // while still failing closed on a missing or forged nonce.
        if ( ! check_admin_referer( self::NONCE_ACTION, '_wpnonce' ) ) {
            wp_die( esc_html__( 'درخواست معتبر نیست یا تأیید صریح انجام نشده است.', 'cpms' ), 403 );
        }
        $actor_id = (int) get_current_user_id();
        if ( ! self::valid_bootstrap_request( $actor_id, $_POST ) ) {
            wp_die( esc_html__( 'درخواست معتبر نیست یا تأیید صریح انجام نشده است.', 'cpms' ), 403 );
        }

        $organization_id = absint( $_POST['organization_id'] );
        $target_user_id  = absint( $_POST['target_user_id'] );
        try {
            $result = App::organization_clinic_provisioning_service()->bootstrap_owner(
                $actor_id,
                $organization_id,
                $target_user_id
            );
            $notice = $result['noop']
                ? 'این حساب از قبل مدیر فعالِ یکتای همین سازمان است؛ تغییری انجام نشد.'
                : 'مدیر اولیهٔ سازمان با موفقیت فعال شد. هیچ دسترسی Clinic، بالینی یا مالی اعطا نشد.';
        } catch ( \Throwable $exception ) {
            App::op()->warning(
                'ORGANIZATION_MANAGER_BOOTSTRAP_FAILED',
                [
                    'actor_wp_user_id'  => $actor_id,
                    'organization_id'   => $organization_id,
                    'target_wp_user_id' => $target_user_id,
                    'reason'            => $exception->getMessage(),
                ]
            );
            $notice = 'خطا: فعال‌سازی انجام نشد؛ سازمان یا کاربر نامعتبر، غیرفعال، متعارض یا قبلاً مدیریت‌شده است. هیچ تغییری ثبت نشد.';
        }

        set_transient( 'cpms_org_bootstrap_notice_' . $actor_id, $notice, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }
}
