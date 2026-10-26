<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\System\CronScheduleRepair;
use ClinicCore\Application\System\QueueDiagnosis;
use ClinicCore\Application\System\SystemHealthService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Infrastructure\Licensing\LicenseGatewayException;

/**
 * صفحه «CPMS (سیستم)» — F10 (spec §40): وضعیت/فعال‌سازی مجوز، Health/
 * سازگاری، بکاپ (اجرا/فعال‌سازی/حذف/تأیید)، به‌روزرسانی امن و Restore
 * با تأیید صریح. فقط برای دارندگان `cpms_config`.
 *
 * بدون PHI در HTML (ADR-0002)؛ همه‌ی فرم‌ها با Nonce + Capability.
 *
 * Multi-Clinic: بخش‌های سطح نصب (مجوز، بکاپ، Restore و Health) بدون Clinic
 * رندر می‌شوند. تنظیمات به‌روزرسانی در پیکربندی Clinic ذخیره می‌شوند، پس فقط
 * با انتخاب صریح یک Clinic مجاز (عضویت فعال + CONFIG همان Clinic) خوانده/نوشته
 * می‌شوند؛ هیچ Clinic تنها/اولی حدس زده نمی‌شود و ScopeRequiredException به
 * Fatal تبدیل نمی‌شود.
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
        // CPMS Classic Admin UX (E) — ترمیمِ مجاز و Nonceدارِ زمان‌بندیِ صف.
        add_action( 'admin_post_cpms_cron_repair', [self::class, 'cronRepair'] );
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

    public static function render( $health_override = null ): void
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
        $health_error = null;
        // Multi-Clinic: a health probe that needs Clinic configuration is a scope
        // requirement, not a page failure; it gets its own informational notice.
        $health_scope_required = false;
        $backupsError = null;
        try {
            $lic = App::licenseService()->statusMeta();
        } catch (\Throwable $e) {
            $licError = $e->getMessage();
        }
        if ( is_array( $health_override ) ) {
            $health = $health_override;
        } else {
            try {
                $health = App::systemHealthService()->run();
            } catch ( ScopeRequiredException ) {
                // Multi-Clinic install: some health probes read Clinic configuration.
                // No Clinic is inferred; the rest of the page stays available.
                $health_scope_required = true;
            } catch ( \Throwable $e ) {
                $health_error = $e->getMessage();
            }
        }
        try {
            $backups = App::backupService()->listBackups();
        } catch (\Throwable $e) {
            $backupsError = $e->getMessage();
        }
        // Update channel/interval are persisted per Clinic: explicit authorized
        // Clinic only. App::settings() would throw CLINIC_SCOPE_REQUIRED here.
        $actor_user_id       = (int) get_current_user_id();
        $requested_clinic_id = AdminClinicSelector::requested_clinic_id( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display selector; verified against membership + CONFIG
        $clinic_choices      = AdminClinicSelector::choices( $actor_user_id, RolesAndCapabilities::CONFIG );
        $clinic_scope        = AdminClinicSelector::establish( $actor_user_id, $requested_clinic_id, RolesAndCapabilities::CONFIG );
        $selected_clinic_id  = null !== $clinic_scope ? (int) $clinic_scope->clinicId : 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the published scope API.
        $settings            = $selected_clinic_id > 0 ? App::settingsFactory()->forClinic( $selected_clinic_id ) : null;

        $installationSettings = App::installationSettings();

        // CPMS Classic Admin UX (E) — تشخیصِ کاملِ Queue/WP-Cron (سطح نصب،
        // مستقل از Clinic). هیچ Jobی این‌جا اجرا نمی‌شود.
        $queue_diagnosis = null;
        try {
            $queue_diagnosis = App::queue_diagnosis( 300 );
        } catch ( \Throwable $e ) {
            $queue_diagnosis = null;
        }

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
            <?php if ( $health_scope_required ) : ?>
                <div class="notice notice-info inline" data-cpms-health-scope="required"><p>بخشی از بررسی‌های Health به پیکربندی Clinic وابسته است و در نصب چند-Clinic بدون Clinic صریح اجرا نمی‌شود؛ هیچ Clinic پیش‌فرضی حدس زده نشد. بخش‌های سطح نصب (مجوز، بکاپ، بازیابی) در دسترس می‌مانند.</p></div>
            <?php elseif ( $health_error !== null || $health === null ) : ?>
                <div class="notice notice-error inline"><p>⛔ بخش Health با خطا مواجه شد (به‌جای Critical Error صفحه فقط این بخش را نشان نمی‌دهد):
                    <code><?php echo esc_html( $health_error ); ?></code></p></div>
            <?php else : ?>
            <p>Host Capability:
                <strong>
                <?php
                echo esc_html(match ($health['host']['status']) {
                    SystemHealthService::HOST_SUPPORTED => '✅ ' . SystemHealthService::USER_STATUS_READY,
                    SystemHealthService::HOST_SUPPORTED_WITH_WARNINGS => '⚠️ ' . SystemHealthService::USER_STATUS_NEEDS_SETUP,
                    default => '⛔ ' . SystemHealthService::USER_STATUS_CRITICAL,
                });
                ?>
                </strong>
                <?php if ($health['host']['issues'] !== []) : ?>
                    <br><code><?php echo esc_html(implode(' | ', $health['host']['issues'])); ?></code>
                <?php endif; ?>
            </p>
            <?php
            // CPMS Classic Admin UX (G) — چهار گروهِ متمایز: مسدودکننده،
            // هشدارِ قابل‌اقدام، پیکربندیِ اختیاری، سالم. هیچ ردیفی حذف نمی‌شود.
            $groups = SystemHealthService::group_checks( $health['checks'] );
            $sections = [
                'blockers' => [ '⛔ مسدودکننده‌ها', 'notice-error', 'این موارد باید پیش از استفادهٔ عملیاتی رفع شوند.' ],
                'warnings' => [ '⚠️ هشدارهای قابل‌اقدام', 'notice-warning', 'افزونه کار می‌کند، اما این موارد باید بررسی شوند.' ],
                'optional' => [ '➖ پیکربندی اختیاری', 'notice-info', 'برای این موارد پیکربندی انجام نشده است؛ «نبود» به‌خودی‌خود خطا نیست.' ],
            ];
            ?>

            <?php foreach ( $sections as $group_key => $meta ) : ?>
                <?php if ( [] === $groups[ $group_key ] ) : ?>
                    <?php continue; ?>
                <?php endif; ?>
                <h3><?php echo esc_html( $meta[0] ); ?> (<?php echo (int) count( $groups[ $group_key ] ); ?>)</h3>
                <p class="description"><?php echo esc_html( $meta[2] ); ?></p>
                <?php foreach ( $groups[ $group_key ] as $c ) : ?>
                    <?php $g = self::guide( (string) $c['key'], (string) $c['status'], (string) $c['detail'] ); ?>
                    <div class="notice <?php echo esc_attr( $meta[1] ); ?> inline cpms-health-item" style="max-width:100%">
                        <p><strong><?php echo esc_html( self::statusBadge( (string) $c['status'], (string) $c['key'], (string) $c['detail'] ) ); ?> <?php echo esc_html( (string) $c['label'] ); ?></strong></p>
                        <p>🔎 <strong>چی:</strong> <?php echo esc_html($g['what']); ?></p>
                        <p>⚡ <strong>اثر:</strong> <?php echo esc_html($g['impact']); ?></p>
                        <p>🛠 <strong>چه کنم:</strong> <?php echo esc_html($g['action']); ?></p>
                        <details><summary style="cursor:pointer">جزئیات فنی</summary>
                            <pre class="cpms-diag" dir="ltr"><?php echo esc_html( (string) $c['detail'] ); ?></pre>
                        </details>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <?php if ( [] === $groups['blockers'] && [] === $groups['warnings'] ) : ?>
                <p class="description">✅ هیچ مسدودکننده یا هشدار قابل‌اقدامی وجود ندارد.</p>
            <?php endif; ?>

            <h3>✅ بررسی‌های سالم (<?php echo (int) count( $groups['healthy'] ); ?>)</h3>
            <table class="widefat striped cpms-health-table">
                <thead><tr><th>بررسی</th><th>وضعیت</th><th>جزئیات</th></tr></thead>
                <tbody>
                <?php foreach ( $groups['healthy'] as $c) : ?>
                    <tr>
                        <td><?php echo esc_html($c['label']); ?></td>
                        <td><?php echo esc_html( self::statusBadge( $c['status'], $c['key'], $c['detail'] ) ); ?></td>
                        <td class="cpms-wrap-cell"><?php echo esc_html( $c['detail'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( $groups['healthy'] === []) : ?>
                    <tr><td colspan="3">هیچ بررسی سالمی وجود ندارد.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h2>Queue و WP-Cron</h2>
            <?php if ( null === $queue_diagnosis ) : ?>
                <div class="notice notice-error inline"><p>⛔ وضعیت صف خوانده نشد؛ بقیهٔ صفحه مستقل از این بخش کار می‌کند.</p></div>
            <?php else : ?>
                <?php
                $severity_class = match ( (string) $queue_diagnosis['severity'] ) {
                    QueueDiagnosis::SEVERITY_OK      => 'notice-success',
                    QueueDiagnosis::SEVERITY_INFO    => 'notice-info',
                    QueueDiagnosis::SEVERITY_WARNING => 'notice-warning',
                    default                                                          => 'notice-error',
                };
                ?>
                <div class="notice <?php echo esc_attr( $severity_class ); ?> inline cpms-queue-state" style="max-width:100%" data-cpms-queue-state="<?php echo esc_attr( (string) $queue_diagnosis['state'] ); ?>">
                    <p><strong><?php echo esc_html( (string) $queue_diagnosis['label'] ); ?></strong></p>
                    <p><?php echo esc_html( (string) $queue_diagnosis['detail'] ); ?></p>
                    <p>🛠 <strong>چه کنم:</strong> <?php echo esc_html( (string) $queue_diagnosis['guidance'] ); ?></p>
                </div>
                <p class="description"><?php echo esc_html( CronScheduleRepair::scheduler_explanation() ); ?></p>

                <?php if ( (bool) $queue_diagnosis['repairable'] ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px">
                        <?php wp_nonce_field( 'cpms_cron_repair' ); ?>
                        <input type="hidden" name="action" value="cpms_cron_repair">
                        <button class="button button-primary">ترمیم زمان‌بندی صف</button>
                        <span class="description">فقط رویدادِ زمان‌بندیِ WP-Cron بازساخته می‌شود؛ هیچ Jobی همین حالا اجرا نمی‌شود.</span>
                    </form>
                <?php endif; ?>
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
                <label><input type="checkbox" name="enabled" value="1" <?php checked($installationSettings->getBackupEnabled()); ?>> بکاپ دوره‌ای</label>
                &nbsp; فاصله (ساعت):
                <input type="number" name="interval_hours" min="1" max="168" value="<?php echo esc_attr((string) $installationSettings->getBackupIntervalHours()); ?>" size="4">
                &nbsp; نگهداری (نسخه):
                <input type="number" name="keep_count" min="1" max="365" value="<?php echo esc_attr((string) $installationSettings->getBackupKeepCount()); ?>" size="4">
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
                    <tr><td colspan="6"><?php echo CpmsUi::emptyState( '🗄', 'هنوز بکاپی ساخته نشده', 'در صورت فعال بودن بکاپ دوره‌ای، پشتیبان‌گیری خودکار انجام می‌شود. برای بکاپ دستی از فرم بالای فهرست استفاده کنید.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CpmsUi escapes its structured HTML. ?></td></tr>
                <?php endif; ?>
                <?php foreach ($backups as $b) : ?>
                    <tr>
                        <td dir="ltr"><?php echo esc_html((string) $b['backup_id']); ?></td>
                        <td><?php echo esc_html((string) $b['created_at']); ?></td>
                        <td><?php echo esc_html((string) $b['tables'] . '/' . (string) $b['rows']); ?></td>
                        <td><?php echo esc_html((string) $b['storage_files']); ?></td>
                        <td><?php echo esc_html((string) $b['integrity']); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                <?php wp_nonce_field('cpms_backup_verify'); ?>
                                <input type="hidden" name="action" value="cpms_backup_verify">
                                <input type="hidden" name="backup_id" value="<?php echo esc_attr((string) $b['backup_id']); ?>">
                                <button class="button button-small">تأیید کامل</button>
                            </form>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" data-cpms-confirm="حذف این بکاپ؟">
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
            <p class="description">کانال و بازهٔ بررسی به‌روزرسانی در پیکربندی Clinic ذخیره می‌شود؛ برای خواندن یا تغییر آن Clinic را صریحاً انتخاب کنید.</p>
            <?php AdminClinicSelector::render_selector( 'cpms-system', 'cpms_system_clinic', $clinic_choices, $selected_clinic_id, 'Clinic' ); ?>
            <?php if ( null === $settings ) : ?>
                <?php if ( null !== $requested_clinic_id ) : ?>
                    <div class="notice notice-error inline" data-cpms-clinic-scope="denied"><p>Clinic درخواست‌شده معتبر نیست یا عضویت فعال و مجوز CONFIG آن را ندارید؛ هیچ تنظیم Clinic-specific نمایش داده نشد.</p></div>
                <?php elseif ( [] === $clinic_choices ) : ?>
                    <div class="notice notice-info inline" data-cpms-clinic-scope="unavailable"><p>برای تنظیمات Clinic-specific، عضویت فعال و مجوز CONFIG در یک Clinic لازم است. مدیر وردپرس به‌تنهایی مرجع Clinic نیست.</p></div>
                <?php else : ?>
                    <div class="notice notice-info inline" data-cpms-clinic-scope="required"><p>برای تنظیمات Clinic-specific، Clinic را صریحاً از فهرست بالا انتخاب کنید؛ هیچ Clinic پیش‌فرضی حدس زده نمی‌شود.</p></div>
                <?php endif; ?>
            <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px">
                <?php wp_nonce_field('cpms_update_settings'); ?>
                <input type="hidden" name="action" value="cpms_update_settings">
                <input type="hidden" name="clinic_id" value="<?php echo (int) $selected_clinic_id; ?>" data-cpms-clinic-settings="<?php echo (int) $selected_clinic_id; ?>">
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
                <input type="hidden" name="clinic_id" value="<?php echo (int) $selected_clinic_id; ?>">
                <button class="button">بررسی به‌روزرسانی (شبکه)</button>
            </form>
            <?php endif; ?>
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
        $s = App::installationSettings();
        // M-2 GREEN: سطح نصب — بدون Clinic/Scope، با validation 1..168 / 1..365
        $s->setBackupEnabled(isset($_POST['enabled']));
        $s->setBackupIntervalHours(max(1, min(168, (int) ($_POST['interval_hours'] ?? 24))));
        $s->setBackupKeepCount(max(1, min(365, (int) ($_POST['keep_count'] ?? 14))));
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

    /**
     * CPMS Classic Admin UX (E) — ترمیمِ دستیِ زمان‌بندیِ صف.
     *
     * امنیت: `cpms_config` + Nonce (`cpms_cron_repair`). هیچ Jobی اجرا نمی‌شود؛
     * فقط رویدادِ تکرارشوندهٔ WP-Cron در صورتِ نبود بازساخته می‌شود. هیچ فایل یا
     * زمان‌بندِ میزبان تغییر نمی‌کند.
     */
    public static function cronRepair(): void
    {
        self::guard( 'cpms_cron_repair' );

        $was_scheduled = CronScheduleRepair::is_scheduled();
        $repaired      = CronScheduleRepair::ensure_scheduled();

        if ( $repaired ) {
            App::op()->info( 'CPMS_CRON_SCHEDULE_REPAIRED', [
                'hook'   => CronScheduleRepair::HOOK,
                'source' => 'manual-admin',
            ] );
            self::notify( 'زمان‌بندی صف بازسازی شد. هیچ Jobی همین حالا اجرا نشد؛ اولین اجرا با اولین ترافیک/WP-Cron انجام می‌شود.', true );

            return;
        }

        if ( true === $was_scheduled ) {
            self::notify( 'زمان‌بندی صف از قبل موجود بود؛ تغییری لازم نبود.', true );

            return;
        }

        if ( null === $was_scheduled ) {
            self::notify( 'WP-Cron در این محیط قابل‌دسترسی نیست، بنابراین ترمیم خودکار زمان‌بندی انجام نشد. این محدودیت میزبان است، نه نتیجهٔ ترمیم.' );

            return;
        }

        self::notify( 'ترمیم زمان‌بندی موفق نبود: WP-Cron رویداد را نپذیرفت. این محدودیت میزبان است؛ طبق ADR-0016 یک System Cron با `DISABLE_WP_CRON` تنظیم کنید. هیچ تغییری در فایل‌های میزبان انجام نشد.' );
    }

    public static function updateCheck(): void
    {
        self::guard('cpms_update_check');
        $scope = self::authorized_clinic_for_write();
        if ( null === $scope ) {
            self::notify( 'Clinic را صریحاً انتخاب کنید؛ Clinic درخواست‌شده معتبر نیست یا مجوز CONFIG آن را ندارید. هیچ بررسی‌ای انجام نشد.' );
            return;
        }
        // Bind only the verified explicit Clinic, so App::settings() and the
        // UpdateService resolve it instead of guessing a Clinic.
        App::replaceExplicitScope( $scope );
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
        $scope = self::authorized_clinic_for_write();
        if ( null === $scope ) {
            self::notify( 'Clinic را صریحاً انتخاب کنید؛ Clinic درخواست‌شده معتبر نیست یا مجوز CONFIG آن را ندارید. هیچ تنظیمی ذخیره نشد.' );
            return;
        }
        App::replaceExplicitScope( $scope );
        $channel = (string) ($_POST['channel'] ?? 'stable');
        $s = App::settings();
        $s->set('update.channel', in_array($channel, ['stable', 'beta'], true) ? $channel : 'stable');
        $s->set('update.check_interval_hours', max(1, min(168, (int) ($_POST['interval_hours'] ?? 24))));
        self::notify('تنظیمات به‌روزرسانی ذخیره شد', true);
    }

    // ================= helpers =================

    /**
     * Explicit Clinic for Clinic-scoped System writes. The posted clinic_id is
     * only a selector, verified against active membership + CONFIG; it is never
     * inferred from the installation's sole/first Clinic.
     */
    private static function authorized_clinic_for_write(): ?ClinicScope {
        $requested_clinic_id = AdminClinicSelector::requested_clinic_id( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard() before every caller

        return AdminClinicSelector::establish( (int) get_current_user_id(), $requested_clinic_id, RolesAndCapabilities::CONFIG );
    }

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
    public static function guide( string $key, string $status, string $detail = '' ): array
    {
        if ( $key === 'db.mysql_version' ) {
            $maria = SystemHealthService::is_maria_db_compatibility_detail( $detail );

            if ( $maria ) {
                // ادعا فقط بر پایهٔ وضعیتِ واقعیِ ردیف — نه متنِ فارسی.
                if ( $status === SystemHealthService::FAIL ) {
                    return [
                        'what'   => 'نسخهٔ MariaDB این سرور کهنه‌تر از مرزِ سازگاریِ مستندشده (' . SystemHealthService::MARIADB_COMPAT_BOUNDARY . ') است.',
                        'impact' => 'این نسخه در CI این افزونه آزموده نشده و پشتیبانی نمی‌شود؛ ممکن است بخشی از عملیات دیتابیس رفتار متفاوت یا خطا داشته باشد. دسترسی به داده به‌صورت خودکار محدود نمی‌شود.',
                        'action' => 'MariaDB را به سریِ ' . SystemHealthService::MARIADB_COMPAT_BOUNDARY . ' (وصلهٔ آزموده‌شده: ' . SystemHealthService::MARIADB_COMPAT_BOUNDARY_TESTED_PATCH . ') یا نسخهٔ آزموده‌شدهٔ MariaDB ' . SystemHealthService::MARIADB_TESTED_VERSION . ' ارتقا دهید؛ پیش از هر تغییر یک بکاپ کامل بگیرید.',
                    ];
                }

                if ( $status === SystemHealthService::WARNING ) {
                    return [
                        'what'   => 'سرور MariaDB شناسایی شد، اما این نسخهٔ مشخص در CI این افزونه آزموده نشده است.',
                        'impact' => 'افزونه احتمالاً کار می‌کند، اما سازگاریِ کامل برای این نسخه تأیید/ادعا نمی‌شود. این وضعیت دسترسی به داده را محدود نمی‌کند.',
                        'action' => 'اگر امکان دارد از نسخهٔ آزموده‌شدهٔ MariaDB ' . SystemHealthService::MARIADB_TESTED_VERSION . ' یا سریِ مرزِ ' . SystemHealthService::MARIADB_COMPAT_BOUNDARY . ' استفاده کنید؛ پیش از هر تغییر بکاپ بگیرید. MariaDB به‌عنوان MySQL ' . SystemHealthService::MIN_MYSQL_VERSION . ' سنجیده نمی‌شود.',
                    ];
                }

                if ( $status === SystemHealthService::UNKNOWN ) {
                    return [
                        'what'   => 'سرور MariaDB شناسایی شد اما شمارهٔ نسخهٔ آن قابل‌خواندن/تشخیص نیست.',
                        'impact' => 'تأییدِ سازگاری ممکن نیست (fail-closed)؛ این وضعیت دسترسی به داده را محدود نمی‌کند.',
                        'action' => 'با میزبان/پشتیبانی هماهنگ کنید تا نسخهٔ سرور MariaDB خوانا شود، یا آن را به نسخهٔ آزموده‌شدهٔ MariaDB ' . SystemHealthService::MARIADB_TESTED_VERSION . ' / سریِ ' . SystemHealthService::MARIADB_COMPAT_BOUNDARY . ' ارتقا دهید. پیش از هر تغییر بکاپ بگیرید.',
                    ];
                }

                return [
                    'what'   => 'سرور MariaDB در محدودهٔ سازگاریِ مستند و آزموده‌شدهٔ این افزونه است.',
                    'impact' => 'نیازی به اقدام نیست.',
                    'action' => 'بدون اقدام.',
                ];
            }

            if ( $status === SystemHealthService::FAIL ) {
                return [
                    'what'   => 'نسخهٔ سرور MySQL این سایت کمتر از کفِ اعلام‌شده (8.0) است.',
                    'impact' => 'عملیات‌های وابسته به MySQL 8 ممکن است شکست بخورند یا پشتیبانی نشوند.',
                    'action' => 'MySQL را به 8.0 یا بالاتر ارتقا دهید یا از MariaDB ' . SystemHealthService::MARIADB_TESTED_VERSION . '/سریِ ' . SystemHealthService::MARIADB_COMPAT_BOUNDARY . ' که آزموده شده‌اند استفاده کنید؛ پیش از تغییر بکاپ بگیرید.',
                ];
            }

            return [
                'what'   => 'موتورِ سرور دیتابیس قابل شناسایی نیست یا نسخهٔ آن خوانده نشد.',
                'impact' => 'تأییدِ سازگاری ممکن نیست؛ برخی عملیات ممکن است پشتیبانی نشوند. دسترسی به داده محدود نمی‌شود.',
                'action' => 'نسخهٔ سرور دیتابیس را بررسی کنید (SELECT VERSION()) و در صورت نیاز با پشتیبانی تماس بگیرید. پیش از هر تغییر بکاپ کامل بگیرید.',
            ];
        }

        $ok = in_array($status, [SystemHealthService::PASS], true);
        $entries = [
            'wp.version'         => [
                'what'   => 'نسخهٔ وردپرس این سایت کهنه‌تر از کهن‌ترین نسخهٔ پشتیبانی‌شده است، پس روی نسخه‌ای اجرا می‌شود که دیگر آزمایش و پشتیبانی نمی‌شود.',
                'impact' => 'ممکن است خطاها/هشدارهای PHP، ناسازگاری با افزونه‌های دیگر یا حفره‌های امنیتی حل‌نشدهٔ خودِ وردپرس روی سایت باقی بماند. این وضعیت دسترسی به داده را محدود نمی‌کند: پرونده‌های بیماران، نوبت‌ها، سوابق مالی، خروجی‌ها و بکاپ/بازیابی مثل قبل در دسترس‌اند.',
                'action' => 'ابتدا یک بکاپ کامل بگیرید، سپس وردپرس را به آخرین نسخهٔ پایدار به‌روز کنید (پیشخوان → «به‌روزرسانی‌ها» → «به‌روزرسانی خودکار» یا دستی). اگر پیشخوان در دسترس نیست، از پنل هاست یا WP-CLI با دستور «wp core update» استفاده کنید. تا پیش از ارتقا هیچ بخشی از افزونه قفل نمی‌شود و هیچ داده‌ای حذف یا پنهان نمی‌گردد.',
            ],
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
                'what' => 'اجرای صف/WP-Cron نیازمند توجه است (رویدادِ زمان‌بندیِ غایب، اجرای کهنه یا Jobهای شکست‌خورده).',
                'impact' => 'یادآوری‌ها، تولید برنامه‌ها و رویدادهای زمان‌بندی‌شده ممکن است به تأخیر بیفتند یا اجرا نشوند. تاریخچهٔ داده حذف نمی‌شود.',
                'action' => 'در بخش «Queue و WP-Cron» همین صفحه وضعیت دقیق را ببینید؛ اگر رویدادِ زمان‌بندی غایب است دکمهٔ «ترمیم زمان‌بندی صف» را بزنید (هیچ Jobی فوراً اجرا نمی‌شود). برای اجرای قطعی و مستقل از ترافیک، System Cron را با DISABLE_WP_CRON تنظیم کنید (ADR-0016).',
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
            'what'   => 'بررسی با وضعیت «' . SystemHealthService::user_facing_status( $status ) . '» بازگشت.',
            'impact' => 'این مورد در عملکرد کلینیک ممکن است مؤثر باشد.',
            'action' => 'جزئیات فنی را بررسی و در صورت نیاز با پشتیبانی تماس بگیرید.',
        ];

        return $ok ? [
            'what' => 'سالم است.',
            'impact' => 'نیازی به اقدام نیست.',
            'action' => 'بدون اقدام.',
        ] : $entry;
    }

    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    private static function statusBadge( string $status, string $key = '', string $detail = '' ): string
    {
        $is_maria = 'db.mysql_version' === $key
            && SystemHealthService::UNKNOWN === $status
            && SystemHealthService::is_maria_db_compatibility_detail( $detail );

        if ( $is_maria ) {
            return '⚠️ ' . SystemHealthService::USER_STATUS_NEEDS_SETUP;
        }

        return match ( $status ) {
            'pass', SystemHealthService::PASS => '✅ ' . SystemHealthService::USER_STATUS_READY,
            'fail', SystemHealthService::FAIL => '⛔ ' . SystemHealthService::USER_STATUS_CRITICAL,
            'unknown', SystemHealthService::UNKNOWN => '❔ ' . SystemHealthService::USER_STATUS_CRITICAL,
            default => '⚠️ ' . SystemHealthService::USER_STATUS_NEEDS_SETUP,
        };
    }

    private static function licenseLabel(string $status): string
    {
        return match ($status) {
            LicenseStatus::ACTIVE => '✅ فعال',
            LicenseStatus::EXPIRING => '⚠️ نزدیک انقضا',
            LicenseStatus::GRACE => '⚠️ مهلت تمدید',
            LicenseStatus::RESTRICTED => '⛔ محدود',
            LicenseStatus::ACTIVATION_PENDING => '⏳ پنجرهٔ فعال‌سازی ۷ روزه',
            LicenseStatus::ACTIVATION_GRACE => '⏳ مهلت مهاجرت ۳۰ روزه',
            LicenseStatus::DEVELOPMENT => '🧪 حالت توسعه/تست',
            LicenseStatus::NOT_CONFIGURED => '➖ پیکربندی نشده',
            LicenseStatus::UNREACHABLE => '📡 سرور مجوز در دسترس نیست',
            LicenseStatus::INVALID => '⛔ سند نامعتبر',
            LicenseStatus::SUSPENDED => '⛔ تعلیق‌شده',
            LicenseStatus::REVOKED => '⛔ ابطال‌شده',
            LicenseStatus::THROTTLED => '⏱ تحت محدودیت',
            default => '❔ نامشخص',
        };
    }
}
