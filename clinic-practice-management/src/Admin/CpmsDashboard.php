<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseStatus;

/**
 * داشبورد CPMS — ورودی منسجم «مدیریت مطب».
 *
 * Role-Aware: فقط کارت‌هایی نمایش داده می‌شوند که کاربرِ فعلی Capability آن را دارد.
 * کوئری‌ها bounded هستند و هر بخش در try/catch مستقل است (الگوی SystemPage) تا
 * شکست یک سرویس، کل صفحه را به Critical Error نکشاند. بدون PHI.
 */
final class CpmsDashboard
{
    public const PAGE_SLUG = 'cpms-dashboard';

    /** وضعیت‌هایی که روی badge مجوز «سبز/مطلوب» نشان داده می‌شوند. */
    private const OK_LICENSE_STATUSES = [
        LicenseStatus::ACTIVE,
        LicenseStatus::DEVELOPMENT,
        LicenseStatus::ACTIVATION_PENDING,
        LicenseStatus::ACTIVATION_GRACE,
    ];

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::PAGE_SLUG);
    }

    public static function render(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG)) {
            wp_die('دسترسی ندارید');
        }

        $setupComplete = (bool) App::settings()->get('setup.completed', false);
        $health = self::safe(fn () => App::systemHealthService()->run());
        $queue = self::safe(fn () => App::queueHealth());
        $license = self::safe(fn () => App::licenseService()->statusMeta());
        $backups = self::safe(fn () => App::backupService()->listBackups());
        $sms = self::safe(fn () => App::smsService()->status());
        $todayAppointments = self::safe(fn () => self::todayAppointments());
        $activeDoctors = self::safe(fn () => self::activeDoctors());
        $schemaVersion = self::safe(fn () => App::migrations()->currentVersion());
        ?>
        <div class="wrap" dir="rtl">
            <h1>مدیریت مطب</h1>
            <p class="cpms-desc">نمای کلی وضعیت مطب. هر کارت فقط برای کاربرانی نمایش داده می‌شود که مجوز آن بخش را دارند.</p>

            <?php if (!$setupComplete) : ?>
                <div class="notice notice-info"><p>
                    🚀 <strong>CPMS نصب شد</strong> — برای شروع، راه‌اندازی اولیه را تکمیل کنید.
                </p>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=cpms-wizard')); ?>">شروع راه‌اندازی</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-system')); ?>">سلامت سیستم</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-system')); ?>">راهنما</a>
                </p>
                </div>
            <?php endif; ?>

            <div class="cpms-cards">
                <?php if (current_user_can(RolesAndCapabilities::CONFIG)) : ?>
                    <div class="cpms-card">
                        <h2>وضعیت راه‌اندازی</h2>
                        <p>
                            <?php if ($setupComplete) : ?>
                                <span class="cpms-badge cpms-ok">تکمیل شده</span>
                            <?php else : ?>
                                <span class="cpms-badge cpms-warn">در انتظار راه‌اندازی</span>
                                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-wizard')); ?>">ادامه راه‌اندازی</a>
                            <?php endif; ?>
                        </p>
                        <p class="description">نسخه دیتابیس: <code><?php echo esc_html((string) ($schemaVersion ?? '—')); ?></code>؛ نسخه افزونه: <code><?php echo esc_html(defined('CPMS_VERSION') ? CPMS_VERSION : 'dev'); ?></code></p>
                    </div>
                <?php endif; ?>

                <?php if (current_user_can(RolesAndCapabilities::APPT_READ)) : ?>
                    <div class="cpms-card">
                        <h2>نوبت‌های امروز</h2>
                        <p class="cpms-big"><?php echo esc_html((string) ($todayAppointments ?? 0)); ?></p>
                        <p class="description">نوبت‌های امروز (در انتظار / تأییدشده)</p>
                    </div>
                <?php endif; ?>

                <?php if (current_user_can(RolesAndCapabilities::QUEUE_READ)) : ?>
                    <div class="cpms-card">
                        <h2>صف فعلی</h2>
                        <p class="cpms-big"><?php echo esc_html((string) ($queue['queued'] ?? 0)); ?></p>
                        <p class="description">
                            <?php if (($queue['stale'] ?? true) === true) : ?>
                                <span class="cpms-badge cpms-warn">⚠️ Cron/Queue ممکن است متوقف باشد</span>
                            <?php else : ?>
                                <span class="cpms-badge cpms-ok">در حال اجرا</span>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if (current_user_can(RolesAndCapabilities::CONFIG)) : ?>
                    <div class="cpms-card">
                        <h2>پزشکان فعال</h2>
                        <p class="cpms-big"><?php echo esc_html((string) ($activeDoctors ?? 0)); ?></p>
                        <p class="description">پزشکان فعال</p>
                    </div>
                <?php endif; ?>

                <?php if (current_user_can(RolesAndCapabilities::CONFIG)) : ?>
                    <div class="cpms-card">
                        <h2>وضعیت مجوز</h2>
                        <p>
                            <?php if ($license === null) : ?>
                                <span class="cpms-badge cpms-warn">نامشخص</span>
                            <?php else : ?>
                                <span class="cpms-badge <?php echo in_array((string) ($license['status'] ?? ''), self::OK_LICENSE_STATUSES, true) ? 'cpms-ok' : 'cpms-warn'; ?>">
                                    <?php echo esc_html(self::licenseLabel((string) ($license['status'] ?? ''))); ?>
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if (current_user_can(RolesAndCapabilities::CONFIG)) : ?>
                    <div class="cpms-card">
                        <h2>بکاپ</h2>
                        <?php if (empty($backups)) : ?>
                            <p><span class="cpms-badge cpms-warn">هنوز بکاپی ثبت نشده</span></p>
                            <p class="description"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-system')); ?>">ایجاد اولین بکاپ</a></p>
                        <?php else : ?>
                            <p class="cpms-big"><?php echo esc_html((string) count($backups)); ?></p>
                            <p class="description">آخرین بکاپ: <code><?php echo esc_html((string) ($backups[0]['created_at'] ?? '—')); ?></code></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (current_user_can(RolesAndCapabilities::SMS_CONFIG)) : ?>
                    <div class="cpms-card">
                        <h2>وضعیت پیامک</h2>
                        <p>
                            <?php if ($sms === null) : ?>
                                <span class="cpms-badge cpms-warn">نامشخص</span>
                            <?php else : ?>
                                <span class="cpms-badge <?php echo !empty($sms['provider']) ? 'cpms-ok' : 'cpms-warn'; ?>">
                                    <?php echo !empty($sms['provider']) ? 'متصل' : 'نیاز به تنظیم'; ?>
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if (current_user_can(RolesAndCapabilities::CONFIG) && is_array($health)) : ?>
                    <div class="cpms-card">
                        <h2>سلامت سیستم</h2>
                        <?php
                        $issues = 0;
                        foreach (($health['checks'] ?? []) as $c) {
                            if (($c['status'] ?? '') !== 'pass') {
                                ++$issues;
                            }
                        }
                        ?>
                        <p>
                            <span class="cpms-badge <?php echo $issues === 0 ? 'cpms-ok' : 'cpms-warn'; ?>">
                                <?php echo $issues === 0 ? 'سالم' : ($issues . ' مورد نیازمند توجه'); ?>
                            </span>
                        </p>
                        <p class="description"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-system')); ?>">مشاهده جزئیات</a></p>
                    </div>
                <?php endif; ?>
            </div>

            <style>
                .cpms-desc{color:#646970;margin-top:0}
                .cpms-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-top:18px}
                .cpms-card{border:1px solid #dcdcde;background:#fff;border-radius:8px;padding:16px 18px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
                .cpms-card h2{margin:0 0 8px;font-size:15px}
                .cpms-big{font-size:30px;font-weight:600;margin:0 0 4px;line-height:1.2}
                .cpms-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:500}
                .cpms-ok{background:#e6f2e6;color:#1a5b2a}
                .cpms-warn{background:#fcf0e1;color:#8a5a12}
                .cpms-card .description{margin:6px 0 0;font-size:12.5px}
                .cpms-card .button{margin-top:6px}
                @media(max-width:782px){.cpms-cards{grid-template-columns:1fr}}
            </style>
        </div>
        <?php
    }

    private static function todayAppointments(): int
    {
        try {
            $db = App::db();
            $count = $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_appointments') . ' WHERE slot_date = CURRENT_DATE AND status IN (%s, %s)',
                ['pending', 'confirmed']
            );

            return is_numeric($count) ? (int) $count : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function activeDoctors(): int
    {
        try {
            $db = App::db();
            $count = $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_clinicians') . ' WHERE is_active = 1'
            );

            return is_numeric($count) ? (int) $count : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function licenseLabel(string $status): string
    {
        return match ($status) {
            LicenseStatus::ACTIVE => 'فعال',
            LicenseStatus::EXPIRING => 'در حال انقضا',
            LicenseStatus::GRACE => 'مهلت',
            LicenseStatus::RESTRICTED => 'محدود',
            LicenseStatus::SUSPENDED => 'معلق',
            LicenseStatus::REVOKED => 'ابطال‌شده',
            LicenseStatus::INVALID => 'نامعتبر',
            LicenseStatus::UNREACHABLE => 'عدم دسترسی',
            LicenseStatus::THROTTLED => 'تحت محدودیت',
            LicenseStatus::ACTIVATION_PENDING => 'در انتظار فعال‌سازی',
            LicenseStatus::ACTIVATION_GRACE => 'مهلت فعال‌سازی',
            LicenseStatus::DEVELOPMENT => 'توسعه',
            LicenseStatus::NOT_CONFIGURED, '' => 'پیکربندی نشده',
            default => 'نامشخص',
        };
    }
}
