<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Bootstrap\App;

/**
 * صفحه «CPMS (فنی)» در wp-admin — فقط برای cpms_config (فنی).
 *
 * هیچ داده پزشکی اینجا نمایش داده نمی‌شود (ADR-0002).
 * V1: وضعیت + تنظیمات کلیدی؛ فرم کامل تنظیمات در F2+.
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
        add_management_page('CPMS (فنی)', 'CPMS (فنی)', 'cpms_config', 'cpms-settings', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can('cpms_config')) {
            wp_die('دسترسی ندارید');
        }
        $settings = App::settings();
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
                <tr><th>OTP TTL (ثانیه)</th><td><?php echo esc_html((string) $settings->get('otp.ttl_sec')); ?></td></tr>
                <tr><th>Hold نوبت (ثانیه)</th><td><?php echo esc_html((string) $settings->get('booking.hold_ttl_sec')); ?></td></tr>
                <tr><th>Grace No-Show (دقیقه)</th><td><?php echo esc_html((string) $settings->get('queue.no_show_grace_minutes')); ?></td></tr>
                <tr><th>بازه رزرو (روز)</th><td><?php echo esc_html((string) $settings->get('booking.max_future_days')); ?></td></tr>
                <tr><th>Retention Audit (سال)</th><td><?php echo esc_html((string) $settings->get('retention.audit_years')); ?></td></tr>
                <tr><th>Retention پرونده (سال)</th><td><?php echo esc_html((string) $settings->get('retention.record_years')); ?></td></tr>
                <tr><th>SMS Provider</th><td><?php echo esc_html((string) $settings->get('sms.provider')); ?></td></tr>
                <tr><th>SMS Quiet Hours</th><td><?php echo esc_html((string) $settings->get('notif.quiet_hours_start') . ' – ' . (string) $settings->get('notif.quiet_hours_end')); ?></td></tr>
                <tr><th>Retention اعلان‌ها (روز)</th><td><?php echo esc_html((string) $settings->get('notif.archive_days')); ?></td></tr>
                <tr><th>سقف بازه گزارش (روز)</th><td><?php echo esc_html((string) $settings->get('reports.max_range_days')); ?></td></tr>
            </table>
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
        // فرم ویدیویی در F2+ — در اینجا فقط Hook آماده است.
        wp_safe_redirect(wp_get_referer() ?: admin_url('tools.php?page=cpms-settings'));
        exit;
    }

    private static function tableCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'cpms_%'"); // phpcs:ignore
    }
}
