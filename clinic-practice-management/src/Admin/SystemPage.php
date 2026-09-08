<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\System\SystemHealthService;
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
        add_action('admin_post_cpms_restore_preflight', [self::class, 'restorePreflight']);
        add_action('admin_post_cpms_update_check', [self::class, 'updateCheck']);
        add_action('admin_post_cpms_update_settings', [self::class, 'updateSettings']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            CpmsAdminMenu::parentSlug(),
            'سلامت سیستم',
            'سلامت سیستم',
            'cpms_config',
            'cpms-system',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('cpms_config')) {
            wp_die('دسترسی ندارید');
        }
        // فیکس گزارش نصب واقعی (D1): صفحهٔ وضعیت باید آخرین نقطهٔ قابل‌رؤیت
        // باشد — شکست هر سرویس وابسته (مثل ساخت پوشهٔ بکاپ روی میزبانِ
        // غیرقابل‌نوشتن) نباید کل صفحه را به Critical Error بکشاند؛ هر بخش
        // مستقلاً خطای خودش را نشان می‌دهد و بقیه صفحه رندر می‌شود.
        /** @var array<string, mixed>|null $lic */
        $lic = null;
        /** @var array{checks: list<array{key:string,label:string,status:string,detail:string}>, host: array{status:string, issues:list<string>}}|null $health */
        $health = null;
        /** @var list<array<string, mixed>> $backups */
        $backups = [];
        $licError = null;
        $healthError = null;
        $backupsError = null;
        try {
            $lic = App::licenseService()->statusMeta();
        } catch (\Throwable $e) {
            $licError = $e->getMessage();
        }
        try {
            $health = App::systemHealthService()->run();
        } catch (\Throwable $e) {
            $healthError = $e->getMessage();
        }
        try {
            $backups = App::backupService()->listBackups();
        } catch (\Throwable $e) {
            $backupsError = $e->getMessage();
        }
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
            <?php if ($healthError !== null || $health === null) : ?>
                <div class="notice notice-error inline"><p>⛔ بخش Health با خطا مواجه شد (به‌جای Critical Error صفحه فقط این بخش را نشان نمی‌دهد):
                    <code><?php echo esc_html($healthError); ?></code></p></div>
            <?php else : ?>
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
            <?php $faults = []; $okRows = []; foreach ($health['checks'] as $c) : ?>
                <?php ($c['status'] === SystemHealthService::PASS) ? $okRows[] = $c : $faults[] = $c; ?>
            <?php endforeach; ?>

            <?php if ($faults !== []) : ?>
                <h3>⚠️ موارد نیازمند توجه</h3>
                <?php foreach ($faults as $c) : $g = self::guide((string) $c['key'], (string) $c['status']); ?>
                    <div class="notice notice-warning inline" style="max-width:1000px">
                        <p><strong><?php echo esc_html(self::statusBadge((string) $c['status'])); ?> <?php echo esc_html((string) $c['label']); ?></strong></p>
                        <p>🔎 <strong>چی:</strong> <?php echo esc_html($g['what']); ?></p>
                        <p>⚡ <strong>اثر:</strong> <?php echo esc_html($g['impact']); ?></p>
                        <p>🛠 <strong>چه کنم:</strong> <?php echo esc_html($g['action']); ?></p>
                        <details><summary style="cursor:pointer">جزئیات فنی</summary>
                            <pre style="direction:ltr;text-align:left"><?php echo esc_html((string) $c['detail']); ?></pre>
                        </details>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($faults === []) : ?>
                <p class="description">✅ همهٔ بررسی‌های وضعیت سالم است.</p>
            <?php endif; ?>

            <h3>بررسی‌های سالم</h3>
            <table class="widefat striped">
                <thead><tr><th>بررسی</th><th>وضعیت</th><th>جزئیات</th></tr></thead>
                <tbody>
                <?php foreach ($okRows as $c) : ?>
                    <tr>
                        <td><?php echo esc_html($c['label']); ?></td>
                        <td><?php echo esc_html(self::statusBadge($c['status'])); ?></td>
                        <td><?php echo esc_html($c['detail']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($okRows === []) : ?>
                    <tr><td colspan="3">هیچ بررسی سالمی وجود ندارد.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h2>مجوز</h2>
            <?php if ($licError !== null || $lic === null) : ?>
                <div class="notice notice-error inline"><p>⛔ وضعیت مجوز قابل خواندن نیست:
                    <code><?php echo esc_html((string) $licError); ?></code></p></div>
            <?php else : ?>
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
            <?php endif; ?>

            <h2>بکاپ (فایل + دیتابیس cpms_*)</h2>
            <?php if ($backupsError !== null) : ?>
                <div class="notice notice-error inline"><p>⛔ فهرست بکاپ‌ها قابل خواندن نیست (مثلاً پوشهٔ قابل‌نوشتن نیست):
                    <code><?php echo esc_html($backupsError); ?></code></p></div>
            <?php endif; ?>
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
                    <tr><td colspan="6"><?php echo CpmsUi::emptyState('🗄', 'هنوز بکاپی ساخته نشده', 'برای امنیت اطلاعات، در صورت فعال بودن بکاپ دوره‌ای خودکار ساخته می‌شود؛ یا همین حالا با دکمهٔ زیر بکاپ دستی بگیرید.', 'اجرای بکاپ دستی', admin_url('admin.php?page=cpms-system')); ?></td></tr>
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
                            <form method="post" style="display:inline" data-cpms-confirm="حذف این بکاپ؟">
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

            <h2>Restore (بازیابی — مخرب؛ preflight + Safety Backup)</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px">
                <?php wp_nonce_field('cpms_restore_preflight'); ?>
                <input type="hidden" name="action" value="cpms_restore_preflight">
                <p>۱) ابتدا <strong>preflight</strong> را اجرا کنید تا ببینید دقیقاً چه چیزی (جدول‌ها، ردیف‌ها، فایل‌ها، یکپارچگی) بازگردانی می‌شود و آیا امن است:</p>
                <p><label>Backup ID: <input type="text" name="backup_id" required dir="ltr"></label>
                <button class="button">🔍 Preflight</button></p>
            </form>
            <div class="notice notice-warning inline" style="max-width:720px">
                <p><strong>⛔ هشدار:</strong> بازیابی، جدول‌های <code>cpms_*</code> را از بکاپ بازمی‌گرداند و دادهٔ فعلی آن‌ها را جایگزین می‌کند (فایل‌های پیوست هم در صورت وجود در بکاپ). <strong>WP Core هرگز دست نمی‌خورد.</strong> قبل از اعمال، یک <strong>Safety Backup</strong> خودکار ساخته می‌شود.</p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  data-cpms-confirm="بازیابی، دادهٔ فعلی جدول‌های cpms_* را جایگزین می‌کند و ابتدا Safety Backup ساخته می‌شود. آیا مطمئن هستید؟">
                <?php wp_nonce_field('cpms_restore_apply'); ?>
                <input type="hidden" name="action" value="cpms_restore_apply">
                <p><label>Backup ID: <input type="text" name="backup_id" required dir="ltr"></label></p>
                <p><label><input type="checkbox" name="ack" value="1" required> می‌پذیرم که دادهٔ فعلی جدول‌های cpms_* جایگزین می‌شود و توضیح هشدار بالا را خوانده‌ام.</label></p>
                <p><label>برای تأیید، عبارت <code>RESTORE</code> را تایپ کنید: <input type="text" name="confirm_text" required autocomplete="off"></label></p>
                <button class="button button-secondary">بازیابی</button>
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
        // F1-4: updated_by برای Audit تغییرات Config
        $uid = get_current_user_id();
        $s->set('backup.enabled', isset($_POST['enabled']), $uid);
        $s->set('backup.interval_hours', max(1, min(168, (int) ($_POST['interval_hours'] ?? 24))), $uid);
        $s->set('backup.keep_count', max(1, min(365, (int) ($_POST['keep_count'] ?? 14))), $uid);
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
        if (empty($_POST['ack'])) {
            self::notify('باید تیک «تأیید هشدار» را بزنید — Restore انجام نشد');
        }
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

    /**
     * Chunk E — preflight بازیابی: پیش از هر اقدام مخرب، دقیقاً نشان می‌دهد چه چیزی
     * بازگردانی می‌شود (جدول/ردیف/فایل) و آیا امن است (یکپارچگی + دیتابیس). بدون تغییر داده.
     */
    public static function restorePreflight(): void
    {
        self::guard('cpms_restore_preflight');
        $id = trim((string) ($_POST['backup_id'] ?? ''));
        try {
            $p = App::backupService()->restorePreflight($id);
            $msg = 'Preflight ' . $id . ': جدول‌ها=' . $p['tables'] . '، ردیف‌ها=' . $p['rows'] . '، فایل‌ها=' . $p['storage_files'] .
                ' — یکپارچگی ' . ($p['integrity_ok'] ? 'سالم ✅' : 'ناسالم ⛔') . '، دیتابیس ' . ($p['db_reachable'] ? 'برقرار ✅' : 'ناموجود ⛔') .
                '، ایمن برای بازیابی=' . ($p['restore_safe'] ? 'بله ✅' : 'خیر ⛔');
            self::notify($msg, (bool) $p['restore_safe']);
        } catch (\Throwable $e) {
            self::notify('Preflight ناموفق: ' . $e->getMessage());
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
        wp_safe_redirect(admin_url('admin.php?page=cpms-system'));
        exit;
    }

    /**
     * راهنمای انسانیشدهٔ هر fault برای Health — «چه»، «اثر»، «چه کنم». خالص و قابل
     * تست (بدون WP/DB). برای کلید/وضعیت ناشناختهٔ عمومی برمی‌گرداند تا صفحه هیچ‌وقت
     * خالی نماند.
     *
     * @return array{what:string, impact:string, action:string}
     */
    public static function guide(string $key, string $status): array
    {
        $ok = in_array($status, [SystemHealthService::PASS], true);
        $entries = [
            'php.version' => [
                'what' => 'نسخهٔ PHP میزبان کمتر از حداقل مجاز (8.1) است.',
                'impact' => 'کل افزونه و رزرو/امنیت ممکن است پایدار اجرا نشود و امضای اسناد (sodium) دچار مشکل شود.',
                'action' => 'با هاست خود هماهنگ کنید و PHP را به 8.1 یا بالاتر ارتقا دهید.',
            ],
            'php.sodium' => [
                'what' => 'افزونهٔ PHP ext/sodium در دسترس نیست.',
                'impact' => 'تأیید امضای اسناد مجوز و انتشار ممکن نیست (fail-closed) — ممکن است فعال‌سازی مجوز مسدود شود.',
                'action' => 'در PHP میزبان، افزونهٔ sodium را فعال کنید (یا از هاست بخواهید فعالش کند).',
            ],
            'php.memory' => [
                'what' => 'محدودیت حافظهٔ PHP (memory_limit) کمتر از ۱۲۸M است.',
                'impact' => 'عملیات سنگین (بکاپ/Restore، گزارش، تولید Slot) ممکن است با OOM قطع شود.',
                'action' => 'memory_limit را به حداقل ۱۲۸M و در صورت امکان ۲۵۶M افزایش دهید.',
            ],
            'db.reachable' => [
                'what' => 'اتصال به دیتابیس برقرار نیست.',
                'impact' => 'هیچ‌کدام از داده‌های کلینیک (نوبت، پزشک، بیمار) در دسترس نخواهد بود؛ افزونه عملاً از کار می‌افتد.',
                'action' => 'مشخصات اتصال به دیتابیس را در wp-config.php بررسی و ارتباط پایگاه داده را برقرار کنید.',
            ],
            'db.migrated' => [
                'what' => 'ساختار جداول (Migration) با نسخهٔ مورد انتظار مطابقت ندارد (به‌روز نشده/ناقص).',
                'impact' => 'جداول/ستون‌های لازم ممکن است غایب باشند و عملیات با خطای «جدول یافت نشد» شکست بخورد.',
                'action' => 'به‌روزرسانی افزونه را نصب و اجرا کنید تا Migration‌ها تکمیل شوند؛ در صورت ادامه مشکل با پشتیبانی تماس بگیرید.',
            ],
            'db.tables' => [
                'what' => 'هیچ جدولی از جداول cpms_* یافت نشد.',
                'impact' => 'داده‌ها هنوز ساخته/مهاجرت نشده‌اند؛ افزونه عملیاتی نیست.',
                'action' => 'راه‌اندازی (Wizard) را کامل کنید یا Migration را از سطح افزونه اجرا کنید.',
            ],
            'cron.jobs' => [
                'what' => 'صف Cron/Jobs متوقف یا در حالت ناپایدار است.',
                'impact' => 'یادآوری‌ها، تولید برنامه‌ها و رویدادهای زمان‌بندی‌شده اجرا نمی‌شوند (عملکرد تدریجی از بین می‌رود).',
                'action' => 'System Cron را با DISABLE_WP_CRON فعال کنید (ADR-0016) یا WP-Cron را تنظیم کنید.',
            ],
            'storage.files' => [
                'what' => 'محل ذخیرهٔ فایل‌های پزشکی در دسترس/قابل‌نوشتن نیست.',
                'impact' => 'آپلود/دریافت فایل‌های پزشکی و دست‌خط شکست می‌خورد.',
                'action' => 'مجوزهای پوشهٔ storage را برای وب‌سرور قابل‌نوشتن کنید و فضا/مسیر را بررسی کنید.',
            ],
            'storage.backups' => [
                'what' => 'محل ذخیرهٔ بکاپ در دسترس/قابل‌نوشتن نیست.',
                'impact' => 'ساخت/بازیابی بکاپ ممکن نیست (ریسک از دست رفتن داده در خرابی).',
                'action' => 'پوشهٔ بکاپ را قابل‌نوشتن کنید و مسیر در تنظیمات «بکاپ» را اصلاح کنید.',
            ],
            'license.state' => [
                'what' => 'وضعیت مجوز فعال/معتبر نیست.',
                'impact' => 'ممکن است دسترسی به بخش‌های محافظت‌شده (رزرو، نوبت، پرونده) محدود یا مجوز نیاز به تمدید داشته باشد.',
                'action' => 'مجوز را از بخش «مجوز» فعال/تمدید کنید؛ در صورت قطع سرور، از فعال‌سازی آفلاین/سند استفاده کنید.',
            ],
            'license.gateway' => [
                'what' => 'سرور مجوز پیکربندی نشده است.',
                'impact' => 'فعال‌سازی/تأیید آنلاین ممکن نیست (فعال‌سازی آفلاین هنوز ممکن است).',
                'action' => 'در صورت نیاز به فعال‌سازی آنلاین، آدرس سرور مجوز را تنظیم کنید؛ در غیر این صورت از سند آفلاین استفاده کنید.',
            ],
            'backup.enabled' => [
                'what' => 'بکاپ دوره‌ای غیرفعال است یا آخرین اجرا قدیمی است.',
                'impact' => 'در صورت خرابی/حمله، بازیابی اطلاعات با ریسک از دست رفتن داده همراه است.',
                'action' => 'بکاپ دوره‌ای را از بخش «بکاپ» فعال کنید و «اجرای دستی» را انجام دهید تا چرخهٔ سالم برقرار شود.',
            ],
            'update.entitlement' => [
                'what' => 'سند مجوز، ویژگی به‌روزرسانی امن (updates) را نمی‌دهد.',
                'impact' => 'نسخه‌های امن جدید را نمی‌توانید نصب کنید (ریسک امنیتی).',
                'action' => 'مجوز را با نسخهٔ دارای پوشش به‌روزرسانی تمدید/ارتقا دهید.',
            ],
            'https.active' => [
                'what' => 'سایت روی HTTPS اجرا نمی‌شود.',
                'impact' => 'ارتباط مرورگر/API در تولید رمزنگاری نمی‌شود (مخصوصاً برای داده‌های پزشکی حساس).',
                'action' => 'SSL/TLS را روی دامنه فعال کنید و سایت را روی HTTPS بیاورید.',
            ],
        ];
        $entry = $entries[$key] ?? [
            'what' => 'بررسی با وضعیت «' . ($status === SystemHealthService::PASS ? 'PASS' : $status) . '» بازگشت.',
            'impact' => 'این مورد در عملکرد کلینیک ممکن است مؤثر باشد.',
            'action' => 'جزئیات فنی را بررسی و در صورت نیاز با پشتیبانی تماس بگیرید.',
        ];

        return $ok ? [
            'what' => 'سالم است.',
            'impact' => 'نیازی به اقدام نیست.',
            'action' => 'بدون اقدام.',
        ] : $entry;
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
