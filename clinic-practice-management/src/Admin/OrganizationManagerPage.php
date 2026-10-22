<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Geography\IranProvinceCityCatalog;

/** Classic RTL admin screen; selectors are display-only and revalidated by the domain service. */
final class OrganizationManagerPage {

    public const PAGE = 'cpms-organizations';

    public static function register(): void {

        add_action( 'admin_menu', [ self::class, 'menu' ] );
        add_action( 'admin_post_cpms_provision_organization_clinic', [ self::class, 'handle' ] );
        add_action( 'admin_menu', [ OrganizationManagerBootstrapPage::class, 'menu' ] );
        add_action( 'admin_post_' . OrganizationManagerBootstrapPage::ACTION, [ OrganizationManagerBootstrapPage::class, 'handle' ] );
        add_action( 'admin_menu', [ OrganizationManagerMembershipPage::class, 'menu' ] );
        add_action( 'admin_post_' . OrganizationManagerMembershipPage::ACTION, [ OrganizationManagerMembershipPage::class, 'handle' ] );
        add_action( 'admin_menu', [ FacilitySetupPage::class, 'menu' ] );
        add_action( 'admin_post_' . FacilitySetupPage::ACTION, [ FacilitySetupPage::class, 'handle' ] );
    }

    public static function menu(): void {

        add_menu_page(
            'سازمان‌ها و درمانگاه‌ها',
            'سازمان‌ها',
            'read',
            self::PAGE,
            [ self::class, 'render' ],
            'dashicons-building',
            4
        );
    }

    public static function render(): void {

        $service       = App::organization_clinic_provisioning_service();
        $actor_id      = (int) get_current_user_id();
        $organizations = $service->organizations_for_manager( $actor_id );

        echo '<div class="wrap" dir="rtl"><h1>مدیریت سازمان و درمانگاه‌ها</h1>';
        if ( ! $organizations ) {
            echo '<div class="notice notice-warning"><p>';
            echo 'برای این حساب، عضویت فعال و مجازِ مدیر سازمان ثبت نشده است. دسترسی مدیر وردپرس به‌تنهایی مجوز ایجاد درمانگاه نیست.';
            if ( current_user_can( 'manage_options' ) ) {
                echo ' برای فعال‌سازی نخستین مدیر، فقط در صورت تصمیم صریح Owner از صفحهٔ فعال‌سازی اولیه استفاده کنید.';
                echo ' <a href="' . esc_url( admin_url( 'options-general.php?page=' . OrganizationManagerBootstrapPage::PAGE ) ) . '">فعال‌سازی مدیر اولیهٔ سازمان</a>';
            }
            echo '</p></div></div>';
            return;
        }

        echo '<p>این اختیار فقط برای ایجاد درمانگاه در سازمان انتخاب‌شده است و دسترسی بالینی، مالی یا پرونده‌ای ایجاد نمی‌کند.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="cpms_provision_organization_clinic">';
        wp_nonce_field( 'cpms_provision_organization_clinic' );
        echo '<table class="form-table"><tbody><tr><th><label for="org">سازمان</label></th><td>';
        echo '<select id="org" name="organization_id" required><option value="">انتخاب صریح سازمان</option>';
        foreach ( $organizations as $organization ) {
            echo '<option value="' . esc_attr( (string) $organization['id'] ) . '">';
            echo esc_html( $organization['name'] ) . '</option>';
        }
        echo '</select></td></tr>';

        $fields = [
            'name'          => ['نام درمانگاه', 190, true],
            'slug'          => ['شناسهٔ یکتا (لاتین)', 190, true],
            'timezone'      => ['منطقهٔ زمانی IANA', 64, true],
            'location_name' => ['نام مکان اصلی', 190, false],
            'address'       => ['نشانی', 255, false],
            'phone'         => ['تلفن', 32, false],
        ];
        foreach ( $fields as $key => [$label, $max_length, $required] ) {
            echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
            if ( $key === 'address' ) {
                AddressSelectorField::render( '', 'org_clinic_address', 'address_province', 'address_city', 'address' );
            }
            echo '<input class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '"';
            echo ' maxlength="' . esc_attr( (string) $max_length ) . '"';
            echo $required ? ' required' : '';
            echo '></td></tr>';
        }
        echo '</tbody></table><p><button class="button button-primary">ایجاد درمانگاه و مکان اصلی</button></p></form>';
        echo '<h2>درمانگاه‌های سازمان‌های مجاز</h2><ul>';
        foreach ( $service->clinics_for_manager( $actor_id ) as $clinic ) {
            echo '<li>' . esc_html( $clinic['name'] ) . ' (' . esc_html( $clinic['slug'] ) . ')</li>';
        }
        echo '</ul></div>';
    }

    public static function handle(): void {

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'دسترسی ندارید', 'cpms' ), 403 );
        }
        if (
            ! isset( $_POST['_wpnonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cpms_provision_organization_clinic' )
        ) {
            wp_die( esc_html__( 'درخواست نامعتبر است', 'cpms' ), 403 );
        }

        try {
            $raw_address      = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : null;
            $address_province    = isset( $_POST['address_province'] ) ? sanitize_text_field( wp_unslash( $_POST['address_province'] ) ) : '';
            $address_city        = isset( $_POST['address_city'] ) ? sanitize_text_field( wp_unslash( $_POST['address_city'] ) ) : '';
            $address_city_custom = isset( $_POST['address_city_custom'] ) ? sanitize_text_field( wp_unslash( $_POST['address_city_custom'] ) ) : '';
            $resolved_address    = IranProvinceCityCatalog::resolve_submitted_address( $raw_address, $address_province, $address_city, $address_city_custom );
            App::organization_clinic_provisioning_service()->provision(
                (int) get_current_user_id(),
                isset( $_POST['organization_id'] ) ? absint( $_POST['organization_id'] ) : 0,
                isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
                isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '',
                isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : '',
                isset( $_POST['location_name'] ) ? sanitize_text_field( wp_unslash( $_POST['location_name'] ) ) : null,
                $resolved_address,
                isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : null
            );
        } catch ( \Throwable $exception ) {
            wp_die( esc_html__( 'ایجاد درمانگاه انجام نشد؛ دسترسی یا داده‌ها معتبر نیستند و تغییری ثبت نشد.', 'cpms' ), 403 );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&created=1' ) );
        exit;
    }
}
