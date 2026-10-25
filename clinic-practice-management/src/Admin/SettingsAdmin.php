<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;

/**
 * صفحه «CPMS (فنی و لاگ)» در wp-admin — فقط برای cpms_config (فنی).
 *
 * هیچ داده پزشکی اینجا نمایش داده نمی‌شود (ADR-0002).
 * V1: وضعیت + تنظیمات کلیدی؛ فرم کامل تنظیمات در F2+.
 *
 * Multi-Clinic: اطلاعات سطح نصب (نسخه، Schema، جداول، صف، Retention اعلان‌ها)
 * بدون Clinic نمایش داده می‌شود. تنظیمات Clinic-specific فقط پس از انتخاب
 * صریح یک Clinic مجاز (عضویت فعال + CONFIG همان Clinic) خوانده می‌شود؛ هیچ
 * Clinic تنها/اولی حدس زده نمی‌شود و App::settings() (که در نصب چند-Clinic
 * CLINIC_SCOPE_REQUIRED می‌دهد) این‌جا صدا زده نمی‌شود.
 */
final class SettingsAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_save_settings', [self::class, 'save']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            CpmsAdminMenu::parentSlug(),
            'فنی و لاگ',
            'فنی و لاگ',
            'cpms_config',
            'cpms-settings',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('cpms_config')) {
            wp_die('دسترسی ندارید');
        }
        $version = App::migrations()->currentVersion();
        $tables = self::tableCount();
        $queue = App::queueHealth();
        global $wpdb;
        $failedRows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, type, last_error, created_at FROM ' . $wpdb->prefix . 'cpms_jobs WHERE status = %s ORDER BY id DESC LIMIT 5', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'failed'
            ),
            ARRAY_A
        );

        // Clinic-specific configuration requires an explicit, authorized Clinic.
        $actor_user_id       = (int) get_current_user_id();
        $requested_clinic_id = AdminClinicSelector::requested_clinic_id( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display selector; verified against membership + CONFIG
        $clinic_choices      = AdminClinicSelector::choices( $actor_user_id, RolesAndCapabilities::CONFIG );
        $clinic_scope        = AdminClinicSelector::establish( $actor_user_id, $requested_clinic_id, RolesAndCapabilities::CONFIG );
        $selected_clinic_id  = null !== $clinic_scope ? (int) $clinic_scope->clinicId : 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the published scope API.
        $settings            = $selected_clinic_id > 0 ? App::settingsFactory()->forClinic( $selected_clinic_id ) : null;
        ?>
        <div class="wrap" dir="rtl">
            <h1>CPMS — وضعیت فنی</h1>
            <?php if ($queue['stale']) : ?>
                <div class="notice notice-error"><p>⚠️ Queue/Cron متوقف به نظر می‌رسد — آخرین tick:
                    <?php echo esc_html($queue['last_tick_at'] ? gmdate('Y-m-d H:i:s', (int) $queue['last_tick_at']) . ' UTC' : 'هرگز'); ?>
                    — بررسی System Cron (ADR-0016) و `bin/cpms health`</p></div>
            <?php endif; ?>
            <?php if ($failedRows !== []) : ?>
                <h2>Jobهای شکست‌خورده اخیر</h2>
                <table class="widefat striped" dir="ltr">
                    <thead><tr><th>ID</th><th>Type</th><th>Error</th><th>Created</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($failedRows as $job) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $job['id']); ?></td>
                            <td><?php echo esc_html((string) $job['type']); ?></td>
                            <td><?php echo esc_html((string) $job['last_error']); ?></td>
                            <td><?php echo esc_html((string) $job['created_at']); ?></td>
                            <td><code>bin/cpms jobs retry <?php echo esc_html((string) $job['id']); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <table class="form-table" role="presentation">
                <tr><th>آخرین tick Queue</th><td><?php echo esc_html($queue['last_tick_at'] ? gmdate('Y-m-d H:i:s', (int) $queue['last_tick_at']) . ' UTC' : 'هرگز'); ?></td></tr>
                <tr><th>Jobهای failed</th><td><?php echo esc_html((string) $queue['failed']); ?></td></tr>
                <tr><th>نسخه افزونه</th><td><?php echo esc_html(defined('CPMS_VERSION') ? CPMS_VERSION : 'dev'); ?></td></tr>
                <tr><th>نسخه Schema</th><td><?php echo esc_html((string) $version); ?></td></tr>
                <tr><th>تعداد جداول cpms_</th><td><?php echo esc_html((string) $tables); ?></td></tr>
                <tr><th>Retention اعلان‌ها (روز)</th><td><?php echo esc_html( (string) App::installationSettings()->getNotifArchiveDays() ); ?></td></tr>
            </table>

            <h2>تنظیمات کلیدی Clinic</h2>
            <?php AdminClinicSelector::render_selector( 'cpms-settings', 'cpms_settings_clinic', $clinic_choices, $selected_clinic_id, 'Clinic' ); ?>
            <?php if ( null === $settings ) : ?>
                <?php if ( null !== $requested_clinic_id ) : ?>
                    <div class="notice notice-error inline" data-cpms-clinic-scope="denied"><p>Clinic درخواست‌شده معتبر نیست یا عضویت فعال و مجوز CONFIG آن را ندارید؛ هیچ تنظیم Clinic-specific نمایش داده نشد.</p></div>
                <?php elseif ( [] === $clinic_choices ) : ?>
                    <div class="notice notice-info inline" data-cpms-clinic-scope="unavailable"><p>برای مشاهدهٔ تنظیمات Clinic-specific، عضویت فعال و مجوز CONFIG در یک Clinic لازم است. مدیر وردپرس به‌تنهایی مرجع Clinic نیست.</p></div>
                <?php else : ?>
                    <div class="notice notice-info inline" data-cpms-clinic-scope="required"><p>برای مشاهدهٔ تنظیمات Clinic-specific، Clinic را صریحاً از فهرست بالا انتخاب کنید؛ هیچ Clinic پیش‌فرضی حدس زده نمی‌شود.</p></div>
                <?php endif; ?>
            <?php else : ?>
            <table class="form-table" role="presentation" data-cpms-clinic-settings="<?php echo (int) $selected_clinic_id; ?>">
                <tr><th>OTP TTL (ثانیه)</th><td><?php echo esc_html((string) $settings->get('otp.ttl_sec')); ?></td></tr>
                <tr><th>Hold نوبت (ثانیه)</th><td><?php echo esc_html((string) $settings->get('booking.hold_ttl_sec')); ?></td></tr>
                <tr><th>Grace No-Show (دقیقه)</th><td><?php echo esc_html((string) $settings->get('queue.no_show_grace_minutes')); ?></td></tr>
                <tr><th>بازه رزرو (روز)</th><td><?php echo esc_html((string) $settings->get('booking.max_future_days')); ?></td></tr>
                <tr><th>Retention Audit (سال)</th><td><?php echo esc_html((string) $settings->get('retention.audit_years')); ?></td></tr>
                <tr><th>Retention پرونده (سال)</th><td><?php echo esc_html((string) $settings->get('retention.record_years')); ?></td></tr>
                <tr><th>SMS Provider</th><td><?php echo esc_html((string) $settings->get('sms.provider')); ?></td></tr>
                <tr><th>SMS Quiet Hours</th><td><?php echo esc_html((string) $settings->get('notif.quiet_hours_start') . ' – ' . (string) $settings->get('notif.quiet_hours_end')); ?></td></tr>
                <tr><th>سقف بازه گزارش (روز)</th><td><?php echo esc_html((string) $settings->get('reports.max_range_days')); ?></td></tr>
            </table>

				<?php
				// نمایش عمومی (فهرست discovery) — رضختمصریِ per-Clinic؛ پیش‌فرض
				// fail-closed (false) و فقط همین‌جا (با scope انتخابشده و nonce
				// موجودِ cpms_save_settings) قابل تغییر است. بدون consent، هیچ
				// Clinicی در `[cpms_public_booking clinic_id="all"]` فهرست نمی‌شود.
				?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cpms-public-listing-form>
                <input type="hidden" name="action" value="cpms_save_settings" />
                <?php wp_nonce_field( 'cpms_save_settings' ); ?>
                <input type="hidden" name="cpms_public_listing_form" value="1" />
                <input type="hidden" name="clinic_id" value="<?php echo (int) $selected_clinic_id; ?>" />
                <h3>نمایش در فهرست عمومی نوبت‌دهی</h3>
                <p>
                    <label>
                        <input type="checkbox" name="public_listing_enabled" value="1" <?php checked( (bool) $settings->get( 'public.listing_enabled', false ) ); ?> />
                        این Clinic در فهرست عمومی <code>[cpms_public_booking clinic_id="all"]</code> نمایش داده شود
                    </label>
                </p>
                <p class="description">پیش‌فرض «خاموش» است؛ بدون رضختمصریِ صریح این‌جا، هیچ فهرست‌گذاری عمومی وجود ندارد. شرایط هم‌زمان: Organization فعال، Clinic فعال (تعلیق‌نشده) و دست‌کم یک Location فعال.</p>
                <p><button type="submit" class="button button-primary">ذخیرهٔ نمایش عمومی</button></p>
            </form>
            <?php endif; ?>
            <p class="description">
                یادآوری: این بخش صرفاً فنی است. دسترسی به PHI/Audit نیازمند Capability صریح
                (cpms_medical_read / cpms_audit_read) روی کاربر خاص است — نه صرفاً نقش مدیر وردپرس.
            </p>
        </div>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('cpms_config') || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'cpms_save_settings')) {
            wp_die('اعتبارسنجی ناموفق');
        }

        // نمایش عمومی (فهرست discovery) — تنها نوشتنِ consent از این hook.
        // فقط وقتی فرمِ اختصاصی همین بخش ارسال شده، با scopeِ دوباره
        // استقراریافتهٔ CONFIG (همان AdminClinicSelector::establish صفحهٔ
        // render) و همان nonce — در غیر این صورت هیچ نوشتنی رخ نمی‌دهد.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified on the line above
        $posted_clinic_id = isset( $_POST['clinic_id'] ) ? absint( wp_unslash( (string) $_POST['clinic_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified on the line above
        if ( isset( $_POST['cpms_public_listing_form'] ) && $posted_clinic_id > 0 ) {
            $actor_user_id = (int) get_current_user_id();
            try {
                $clinic_scope = AdminClinicSelector::establish( $actor_user_id, $posted_clinic_id, RolesAndCapabilities::CONFIG );
            } catch ( \Throwable ) {
                $clinic_scope = null;
            }
            if ( null !== $clinic_scope ) {
                ( new Settings( App::db(), (int) $clinic_scope->clinicId, App::audit() ) ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the published scope API.
                    ->set( 'public.listing_enabled', isset( $_POST['public_listing_enabled'] ), $actor_user_id );
            }
        }

        // فرم ویدیویی در F2+ — در اینجا فقط Hook آماده است.
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=cpms-settings'));
        exit;
    }

    private static function tableCount(): int
    {
        global $wpdb;

        // فیکس گزارش نصب واقعی (D2): جداول واقعی `{prefix}cpms_*` هستند
        // (CpmsDb::table) — الگوی بدون prefix همیشه 0 برمی‌گرداند.
        // همان الگوی SystemHealthService::run (dbPrefix + 'cpms_%').
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s",
            $wpdb->prefix . 'cpms_%'
        ));
    }
}
