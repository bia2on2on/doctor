<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Onboarding\OrganizationClinicProvisioningService;
use ClinicCore\Bootstrap\App;

/** Organization-scoped manager grants/revocations; authority is rechecked in the service. */
final class OrganizationManagerMembershipPage {

    public const PAGE          = 'cpms-organization-managers';
    public const ACTION        = 'cpms_manage_organization_manager';
    private const NONCE_ACTION = 'cpms_manage_organization_manager';

    public static function menu(): void {

        add_submenu_page(
            OrganizationManagerPage::PAGE,
            'مدیران سازمان',
            'مدیران سازمان',
            'read',
            self::PAGE,
            [self::class, 'render']
        );
    }

    public static function render(): void {

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'دسترسی ندارید.', 'cpms' ), 403 );
        }
        $actor_id      = (int) get_current_user_id();
        $organizations = App::organization_clinic_provisioning_service()->organizations_for_manager(
            $actor_id,
            OrganizationClinicProvisioningService::MEMBERSHIP_CAPABILITY
        );
        $notice_key    = 'cpms_org_membership_notice_' . $actor_id;
        $notice        = get_transient( $notice_key );
        if ( $notice !== false ) {
            delete_transient( $notice_key );
        }

        echo '<div class="wrap" dir="rtl"><h1>مدیران سازمان</h1>';
        echo '<p>اعطا و لغو فقط برای سازمانی انجام می‌شود که مجوز `cpms_org_membership_manage` مؤثر دارید. این عضویت، مجوز Clinic، بالینی، مالی یا پروندهٔ بیمار نمی‌دهد.</p>';
        if ( is_string( $notice ) && $notice !== '' ) {
            $error = str_starts_with( $notice, 'خطا:' );
            echo '<div class="notice ' . ( $error ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice ) . '</p></div>';
        }
        if ( $organizations === [] ) {
            echo '<div class="notice notice-warning"><p>برای این حساب، مجوز فعال مدیریت عضویت سازمانی وجود ندارد. نقش مدیر WordPress به‌تنهایی کافی نیست.</p></div></div>';
            return;
        }

        foreach ( $organizations as $organization ) {
            $organization_id = (int) $organization['id'];
            echo '<section class="cpms-org-manager-section"><h2>' . esc_html( $organization['name'] ) . '</h2>';
            $rows = App::db()->fetchAll(
                'SELECT wp_user_id, status FROM ' . App::db()->table( 'cpms_organization_memberships' )
                . ' WHERE organization_id = %d AND role_key = %s ORDER BY status, wp_user_id',
                [$organization_id, 'organization_manager']
            );
            echo '<ul>';
            foreach ( $rows as $row ) {
                $user_id = (int) $row['wp_user_id'];
                $user    = get_userdata( $user_id );
                $label   = $user === false ? 'کاربر ناموجود' : $user->display_name . ' (' . $user->user_login . ')';
                $status  = $row['status'] === 'active' ? 'فعال' : 'معلق';
                echo '<li>' . esc_html( $label ) . ' — ' . esc_html( $status );
                if ( $row['status'] === 'active' && $user_id !== $actor_id ) {
                    self::render_action_form( $organization_id, $user_id, false, 'لغو عضویت مدیر' );
                } elseif ( $row['status'] !== 'active' ) {
                    self::render_action_form( $organization_id, $user_id, true, 'فعال‌سازی مجدد مدیر' );
                }
                echo '</li>';
            }
            echo '</ul>';
            self::render_grant_form( $organization_id );
            echo '</section>';
        }
        echo '</div>';
    }

    private static function render_grant_form( int $organization_id ): void {

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cpms-org-manager-grant">';
        wp_nonce_field( self::NONCE_ACTION );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="organization_id" value="' . esc_attr( (string) $organization_id ) . '">';
        echo '<input type="hidden" name="active" value="1">';
        $select_id = 'cpms-org-manager-target-' . $organization_id;
        echo '<label for="' . esc_attr( $select_id ) . '">کاربر موردنظر برای اعطای نقش</label> ';
        wp_dropdown_users(
            [
                'name'              => 'target_user_id',
                'id'                => $select_id,
                'show'              => 'display_name_with_login',
                'show_option_none'  => '— انتخاب کاربر برای اعطا —',
                'option_none_value' => '0',
                'selected'          => 0,
            ]
        );
        echo '<button type="submit" class="button">اعطای نقش مدیر سازمان</button></form>';
    }

    private static function render_action_form( int $organization_id, int $user_id, bool $active, string $label ): void {

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cpms-org-manager-action">';
        wp_nonce_field( self::NONCE_ACTION );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="organization_id" value="' . esc_attr( (string) $organization_id ) . '">';
        echo '<input type="hidden" name="target_user_id" value="' . esc_attr( (string) $user_id ) . '">';
        echo '<input type="hidden" name="active" value="' . ( $active ? '1' : '0' ) . '">';
        echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button></form>';
    }

    public static function handle(): void {

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'دسترسی ندارید.', 'cpms' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION );
        $actor_id        = (int) get_current_user_id();
        $organization_id = isset( $_POST['organization_id'] ) ? absint( $_POST['organization_id'] ) : 0;
        $target_user_id  = isset( $_POST['target_user_id'] ) ? absint( $_POST['target_user_id'] ) : 0;
        $active_value    = isset( $_POST['active'] ) ? sanitize_text_field( wp_unslash( $_POST['active'] ) ) : '';

        if ( $active_value !== '0' && $active_value !== '1' ) {
            $notice = 'خطا: درخواست نامعتبر است.';
        } else {
            try {
                App::organization_clinic_provisioning_service()->set_manager(
                    $actor_id,
                    $organization_id,
                    $target_user_id,
                    $active_value === '1'
                );
                $notice = 'تغییر عضویت مدیر سازمان ثبت شد.';
            } catch ( \Throwable $exception ) {
                $notice = 'خطا: تغییر انجام نشد؛ مجوز، سازمان، کاربر یا وضعیت عضویت معتبر نیست.';
            }
        }
        set_transient( 'cpms_org_membership_notice_' . $actor_id, $notice, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }
}
