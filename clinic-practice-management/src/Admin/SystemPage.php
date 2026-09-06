<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Infrastructure\Licensing\LicenseGatewayException;

/**
 * صفحه «CPMS (سیستم)» — F10 (spec §40): وضعیت/فعال‌سازی مجوز، Health/
 * سازگاری، بکاپ (اجرا/فعال‌سازی/حذف/تأیید)، به‌روزرسانی امن و Restore
 * با تأیید صریح. فقط برای دارندگان `cpms_config`.
 *
 * بدون PHI در HTML (ADR-0002)؛ همه‌ی فرم‌ها با Nonce + Capability.
 */
final class SystemPage
{
    private const NOTICE_KEY = 'cpms_sys_notice';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_license_activate', [self::class, 'licenseActivate']);
        add_action('admin_post_cpms_license_offline', [self::class, 'licenseOffline']);
        add_action('admin_post_cpms_backup_save', [self::class, 'backupSave']);
        add_action('admin_post_cpms_backup_run', [self::class, 'backupRun']);
        add_action('admin_post_cpms_backup_delete', [self::class, 'backupDelete']);
        add_action('admin_post_cpms_backup_verify', [self::class, 'backupVerify']);
        add_action('admin_post_cpms_restore_apply', [self::class, 'restoreApply']);
        add_action('admin_post_cpms_update_check', [self::class, 'updateCheck']);
        add_action('admin_post_cpms_update_settings', [self::class, 'updateSettings']);
    }

    public static function menu(): void
    {
        add_management_page('CPMS (سیستم)', 'CPMS (سیستم)', 'cpms_config', 'cpms-system', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can('cpms_config')) {
            wp_die('دسترسی ندارید');
        }
        $lic = App::licenseService()->statusMeta();
        $health = App::systemHealthService()->run();
        $backups = App::backupService()->listBackups();
        $settings = App::settings();
        $notice = get_transient(self::NOTICE_KEY);
        if ($notice !== false) {
            delete_transient(self::NOTICE_KEY);
        }
        ?>
        <div class="wrap" dir="rtl">
            <h1>CPMS — سیستم، مجوز، بکاپ و به‌روزرسانی</h1>
            <?php if (is_string($notice) && $notice !== '') : ?>
                <div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <h2>وضعیت Health / سازگاری میزبان</h2>
            <p>Host Capability:
                <strong>
                <?php
                echo esc_html(match ($health['host']['status']) {
                    SystemHealthService::HOST_SUPPORTED => '✅ SUPPORTED',
                    SystemHealthService::HOST_SUPPORTED_WITH_WARNINGS => '⚠️ SUPPORTED_WITH_WARNINGS',
                    default => '⛔ UNSUPPORTED',
                });
                ?>
                </strong>
                <?php if ($health['host']['issues'] !== []) : ?>
                    <br><code><?php echo esc_html(implode(' | ', $health['host']['issues'])); ?></code>
                <?php endif; ?>
            </p>
            <table class="widefat striped">
                <thead><tr><th>بررسی</th><th>وضعیت</th><th>جزئیات</th></tr></thead>
                <tbody>
                <?php foreach ($health['checks'] as $c) : ?>
                    <tr>
                        <td><?php echo esc_html($c['label']); ?></td>
                        <td><?php echo esc_html(self::statusBadge($c['status'])); ?></td>
                        <td><?php echo esc_html($c['detail']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>مجوز</h2>
            <table class="form-table" role="presentation">
                <tr><th>وضعیت</th><td><strong><?php echo esc_html(self::licenseLabel($lic['status'])); ?></strong>
                    <?php echo esc_html($lic['reason'] !== '' ? ' (' . $lic['reason'] . ')' : ''); ?></td></tr>
                <tr><th>نصب</th><td dir="ltr"><?php echo esc_html((string) $lic['install_id_masked']); ?></td></tr>
                <tr><th>شناسه مجوز</th><td dir="ltr"><?php echo esc_html((string) $lic['license_id']); ?></td></tr>
                <?php if (($lic['activation_window_type'] ?? null) !== null && ($lic['configured'] ?? true) === false) : ?>
                    <tr><th>پنجرهٔ فعال‌سازی</th><td dir="ltr"><?php echo esc_html($lic['activation_window_type'] === 'migration' ? 'migration (۳۰ روز)' : 'fresh (۷ روز)'); ?></td></tr>
                <?php endif; ?>
                <?php if ($lic['expires_at'] !== null) : ?>
                    <tr><th>انقضا/مهلت (UTC)</th><td><?php echo esc_html(gmdate('Y-m-d H:i', (int) $lic['expires_at'])); ?></td></tr>
                <?php endif; ?>
                <tr><th>Refresh</th><td><?php echo esc_html((string) ($lic['last_refresh_error'] !== null && $lic['last_refresh_error'] !== '' ? 'خطا: ' . $lic['last_refresh_error'] : ($lic['verified_at'] !== null ? 'آخرین تأیید: ' . $lic['verified_at'] : '—'))); ?></td></tr>
            </table>
            <?php if (in_array($lic['status'], [LicenseStatus::NOT_CONFIGURED, LicenseStatus::ACTIVATION_PENDING, LicenseStatus::ACTIVATION_GRACE, LicenseStatus::UNREACHABLE, LicenseStatus::INVALID, LicenseStatus::RESTRICTED], true)) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('cpms_license_activate'); ?>
                    <input type="hidden" name="action" value="cpms_license_activate">
                    <label>License Key:
                        <input type="text" name="license_key" required autocomplete="off" style="direction:ltr"></label>
                    <button class="button button-primary">فعال‌سازی (سرور)</button>
                </form>
                <details><summary>فعال‌سازی آفلاین/دستی (سند امضاشده)</summary>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('cpms_license_offline'); ?>
                        <input type="hidden" name="action" value="cpms_license_offline">
                        <p><textarea name="payload_json" rows="6" cols="80" placeholder='{"product":"cpms", …}' required style="direction:ltr"></textarea></p>
                        <p><input type="text" name="signature_b64" size="100" placeholder="signature (base64)" required style="direction:ltr"></p>
                        <button class="button">فعال‌سازی با سند</button>
                    </form>
                </details>
            <?php else : ?>
                <p class="description">سند مجوز معتبر است. Refresh دوره‌ای خودکار است؛ عدم دسترسی شبکه ≠ نامعتبر.</p>
            <?php endif; ?>

            <h2>بکاپ (فایل + دیتابیس cpms_*)</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px">
                <?php wp_nonce_field('cpms_backup_save'); ?>
                <input type="hidden" name="action" value="cpms_backup_save">
                <label><input type="checkbox" name="enabled" value="1" <?php checked((bool) $settings->get('backup.enabled')); ?>> بکاپ دوره‌ای</label>
                &nbsp; فاصله (ساعت):
                <input type="number" name="interval_hours" min="1" max="168" value="<?php echo esc_attr((string) $settings->get('backup.interval_hours')); ?>" size="4">
                &nbsp; نگهداری (نسخه):
                <input type="number" name="keep_count" min="1" max="365" value="<?php echo esc_attr((string) $settings->get('backup.keep_count')); ?>" size="4">
                <button class="button">ذخیره تنظیمات</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px">
                <?php wp_nonce_field('cpms_backup_run'); ?>
                <input type="hidden" name="action" value="cpms_backup_run">
                <button class="button button-primary">اجرای بکاپ دستی</button>
            </form>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>زمان (UTC)</th><th>جدول‌ها/ردیف‌ها</th><th>فایل‌ها</th><th>یکپارچگی</th><th></th></tr></thead>
                <tbody>
                <?php if ($backups === []) : ?>
                    <tr><td colspan="6">بکاپی موجود نیست.</td></tr>
                <?php endif; ?>
                <?php foreach ($backups as $b) : ?>
                    <tr>
                        <td dir="ltr"><?php echo esc_html((string) $b['backup_id']); ?></td>
                        <td><?php echo esc_html((string) $b['created_at']); ?></td>
                        <td><?php echo esc_html((string) $b['tables'] . '/' . (string) $b['rows']); ?></td>
                        <td><?php echo esc_html((string) $b['storage_files']); ?></td>
                        <td><?php echo esc_html((string) $b['integrity']); ?></td>
                        <td>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field('cpms_backup_verify'); ?>
                                <input type="hidden" name="action" value="cpms_backup_verify">
                                <input type="hidden" name="backup_id" value="<?php echo esc_attr((string) $b['backup_id']); ?>">
                                <button class="button button-small">تأیید کامل</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('حذف این بکاپ؟')">
                                <?php wp_nonce_field('cpms_backup_delete'); ?>
                                <input type="hidden" name="action" value="cpms_backup_delete">
                                <input type="hidden" name="backup_id" value="<?php echo esc_attr((string) $b['backup_id']); ?>">
                                <button class="button button-small">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Restore (بازیابی — مخرب؛ با Safety Backup خودکار)</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('Restore جدول‌های cpms_* را از بکاپ بازمی‌گرداند. ابتدا Safety Backup ساخته می‌شود. ادامه؟')">
                <?php wp_nonce_field('cpms_restore_apply'); ?>
                <input type="hidden" name="action" value="cpms_restore_apply">
                <label>Backup ID: <input type="text" name="backup_id" required dir="ltr"></label>
                &nbsp; برای تأیید «RESTORE» تایپ کنید:
                <input type="text" name="confirm_text" required>
                <button class="button button-secondary">بازیابی</button>
                <p class="description">فقط جدول‌های cpms_* بازگردانی می‌شوند (WP Core دست نمی‌خورد). Preflight قبل از هر اقدامی اجرا می‌شود.</p>
            </form>

            <h2>به‌روزرسانی امن (ADR-0029)</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px">
                <?php wp_nonce_field('cpms_update_settings'); ?>
                <input type="hidden" name="action" value="cpms_update_settings">
                <label>Channel:
                    <select name="channel">
                        <option value="stable" <?php selected((string) $settings->get('update.channel'), 'stable'); ?>>stable</option>
                        <option value="beta" <?php selected((string) $settings->get('update.channel'), 'beta'); ?>>beta</option>
                    </select></label>
                &nbsp; بررسی هر (ساعت):
                <input type="number" name="interval_hours" min="1" max="168" value="<?php echo esc_attr((string) $settings->get('update.check_interval_hours')); ?>">
                <button class="button">ذخیره</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('cpms_update_check'); ?>
                <input type="hidden" name="action" value="cpms_update_check">
                <button class="button">بررسی به‌روزرسانی (شبکه)</button>
            </form>
        </div>
        <?php
    }

    // ================= handlers =================

    public static function licenseActivate(): void
    {
        self::guard('cpms_license_activate');
        $key = trim((string) ($_POST['license_key'] ?? ''));
        if ($key === '') {
            self::notify('License Key خالی است');
        }
        try {
            $meta = App::licenseService()->activateWithKey($key);
            self::notify('فعال‌سازی موفق — وضعیت: ' . (string) $meta['status'], true);
        } catch (LicenseGatewayException $e) {
            self::notify('فعال‌سازی ناموفق: ' . $e->apiCode() . ' — ' . $e->getMessage());
        }
    }

    public static function licenseOffline(): void
    {
        self::guard('cpms_license_offline');
        try {
            $meta = App::licenseService()->activateWithDocument(
                (string) ($_POST['payload_json'] ?? ''),
                trim((string) ($_POST['signature_b64'] ?? ''))
            );
            self::notify('فعال‌سازی با سند موفق — وضعیت: ' . (string) $meta['status'], true);
        } catch (LicenseGatewayException $e) {
            self::notify('سند نامعتبر: ' . $e->apiCode() . ' — ' . $e->getMessage());
        }
    }

    public static function backupSave(): void
    {
        self::guard('cpms_backup_save');
        $s = App::settings();
        $s->set('backup.enabled', isset($_POST['enabled']));
        $s->set('backup.interval_hours', max(1, min(168, (int) ($_POST['interval_hours'] ?? 24))));
        $s->set('backup.keep_count', max(1, min(365, (int) ($_POST['keep_count'] ?? 14))));
        self::notify('تنظیمات بکاپ ذخیره شد', true);
    }

    public static function backupRun(): void
    {
        self::guard('cpms_backup_run');
        try {
            $meta = App::backupService()->createBackup('manual-admin');
            self::notify('بکاپ ساخته شد: ' . (string) $meta['backup_id'], true);
        } catch (\Throwable $e) {
            self::notify('بکاپ ناموفق: ' . $e->getMessage());
        }
    }

    public static function backupDelete(): void
    {
        self::guard('cpms_backup_delete');
        try {
            App::backupService()->deleteBackup(trim((string) ($_POST['backup_id'] ?? '')));
            self::notify('بکاپ حذف شد', true);
        } catch (\Throwable $e) {
            self::notify('حذف ناموفق: ' . $e->getMessage());
        }
    }

    public static function backupVerify(): void
    {
        self::guard('cpms_backup_verify');
        $id = trim((string) ($_POST['backup_id'] ?? ''));
        $v = App::backupService()->verifyBackup($id);
        self::notify('تأیید بکاپ ' . $id . ': ' . ($v['ok'] ? 'سالم ✅' : 'ناسالم ⛔ ' . implode('; ', $v['errors'])), $v['ok']);
    }

    public static function restoreApply(): void
    {
        self::guard('cpms_restore_apply');
        $id = trim((string) ($_POST['backup_id'] ?? ''));
        $confirm = trim((string) ($_POST['confirm_text'] ?? ''));
        if ($confirm !== 'RESTORE') {
            self::notify('عبارت تأیید اشتباه است — Restore انجام نشد');
        }
        try {
            $pre = App::backupService()->restoreApply($id, true);
            self::notify('Restore انجام شد (backup ' . $id . ') — Safety Backup ساخته شد', true);
        } catch (\Throwable $e) {
            self::notify('Restore ناموفق (هیچ تغییری اعمال نشد مگر Safety Backup): ' . $e->getMessage());
        }
    }

    public static function updateCheck(): void
    {
        self::guard('cpms_update_check');
        $channel = (string) App::settings()->get('update.channel', 'stable');
        $r = App::updateService()->checkForUpdates(true, $channel);
        if (($r['available'] ?? false) === true) {
            self::notify('نسخه جدید موجود: ' . (string) $r['version'] . ' — از صفحه به‌روزرسانی وردپرس نصب کنید', true);
        } else {
            self::notify('نسخه جدیدی موجود نیست / ' . (string) ($r['reason'] ?? '') . ' — به‌روزرسانی امن (ADR-0029)');
        }
    }

    public static function updateSettings(): void
    {
        self::guard('cpms_update_settings');
        $channel = (string) ($_POST['channel'] ?? 'stable');
        $s = App::settings();
        $s->set('update.channel', in_array($channel, ['stable', 'beta'], true) ? $channel : 'stable');
        $s->set('update.check_interval_hours', max(1, min(168, (int) ($_POST['interval_hours'] ?? 24))));
        self::notify('تنظیمات به‌روزرسانی ذخیره شد', true);
    }

    // ================= helpers =================

    private static function guard(string $action): void
    {
        if (!current_user_can('cpms_config') || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], $action)) {
            wp_die('اعتبارسنجی ناموفق');
        }
    }

    private static function notify(string $message, bool $success = false): void
    {
        set_transient(self::NOTICE_KEY, ($success ? '✅ ' : '⛔ ') . $message, 60);
        wp_safe_redirect(admin_url('tools.php?page=cpms-system'));
        exit;
    }

    private static function statusBadge(string $status): string
    {
        return match ($status) {
            'pass' => '✅ PASS',
            'warning' => '⚠️ WARNING',
            'fail' => '⛔ FAIL',
            'not_configured' => '➖ NOT_CONFIGURED',
            default => '❔ UNKNOWN',
        };
    }

    private static function licenseLabel(string $status): string
    {
        return match ($status) {
            LicenseStatus::ACTIVE => '✅ ACTIVE (فعال)',
            LicenseStatus::EXPIRING => '⚠️ EXPIRING (نزدیک انقضا)',
            LicenseStatus::GRACE => '⚠️ GRACE (مهلت تمدید)',
            LicenseStatus::RESTRICTED => '⛔ RESTRICTED (محدود)',
            LicenseStatus::ACTIVATION_PENDING => '⏳ ACTIVATION_PENDING (پنجرهٔ فعال‌سازی ۷ روزه)',
            LicenseStatus::ACTIVATION_GRACE => '⏳ ACTIVATION_GRACE (مهلت مهاجرت ۳۰ روزه)',
            LicenseStatus::DEVELOPMENT => '🧪 DEVELOPMENT (حالت توسعه/تست صریح)',
            LicenseStatus::NOT_CONFIGURED => '➖ NOT_CONFIGURED',
            LicenseStatus::UNREACHABLE => '📡 UNREACHABLE (سرور مجوز در دسترس نیست)',
            LicenseStatus::INVALID => '⛔ INVALID (سند نامعتبر)',
            LicenseStatus::SUSPENDED => '⛔ SUSPENDED',
            LicenseStatus::REVOKED => '⛔ REVOKED',
            LicenseStatus::THROTTLED => '⏱ THROTTLED',
            default => strtoupper((string) $status),
        };
    }
}
