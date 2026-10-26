<?php

declare(strict_types=1);

namespace ClinicCore\Application\System;

use ClinicCore\Application\Backup\BackupService;
use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Application\Update\UpdateService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Db\DatabaseCompatibilityGate;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Settings\InstallationSettings;
use ClinicCore\Settings\Settings;

/**
 * Health/سازگاری سیستم (F10 — spec §10–§13/§40): چک‌های بدون PHI.
 *
 * هر چک: {key, label, status: pass|warning|fail|not_configured|unknown, detail}
 * Host Capability Detection (spec §10–§13):
 *   SUPPORTED / SUPPORTED_WITH_WARNINGS / UNSUPPORTED
 * با دسته‌بندی خالص (قابل واحدتست): failهای حیاتی → UNSUPPORTED؛ فقط
 * warning → SUPPORTED_WITH_WARNINGS.
 *
 * Phase 18 Slice 18-1 — سازگاریِ زمان‌اجرا برای کف‌هایِ اعلام‌شده: دو چکِ
 * `wp.version` (WordPress >= 6.9) و `db.mysql_version` (MySQL >= 8.0) به
 * چک‌هایِ حیاتی اضافه شده‌اند. فقط تشخیص/گزارش است: هیچ قفلِ بالینی، قفلِ
 * داده، محدودیتِ بکاپ/بازیابی یا رفتارِ مخربی اضافه نمی‌شود و نقطهٔ عمومی
 * `/clinic/v1/health` گسترش نمی‌یابد.
 *
 * CPMS Classic Admin UX (D) — تفکیکِ موتور دیتابیس: MySQL و MariaDB دو موتورِ
 * مستقل با نسخه‌بندیِ مستقل‌اند. موتور **فقط** از مدرکِ نسخهٔ سرور تشخیص داده
 * می‌شود (`database_engine()`)، کفِ MySQL 8.0 هرگز روی MariaDB اعمال نمی‌شود و
 * MariaDB به‌عنوان MySQL 8 بازنمایی نمی‌شود. نسخه‌های ادعاشده فقط بر پایهٔ شواهدِ
 * واقعیِ همین مخزن‌اند (CI: `mariadb:11.4.13`؛ لِینِ هفتگی: MariaDB 10.11.19)؛
 * هر نسخهٔ دیگر «آزموده‌نشده» (WARNING) گزارش می‌شود، نه «پشتیبانی‌شده».
 *
 * Live Network تست (سرور لایسنس/خروجی) عمداً اینجا اجرا نمی‌شود — فقط
 * پیکربندی بررسی می‌شود (BLOCKED_BY_ENVIRONMENT برای تأیید زنده).
 */
final class SystemHealthService
{
    public const PASS = 'pass';
    public const WARNING = 'warning';
    public const FAIL = 'fail';
    public const NOT_CONFIGURED = 'not_configured';
    public const UNKNOWN = 'unknown';

    public const HOST_SUPPORTED = 'SUPPORTED';
    public const HOST_SUPPORTED_WITH_WARNINGS = 'SUPPORTED_WITH_WARNINGS';
    public const HOST_UNSUPPORTED = 'UNSUPPORTED';

    /** سه وضعیتِ کاربرپسند (فارسی) برای ارائهٔ سلامت سیستم */
    public const USER_STATUS_READY       = 'آماده استفاده';
    public const USER_STATUS_NEEDS_SETUP = 'نیازمند تنظیم';
    public const USER_STATUS_CRITICAL    = 'مشکل ضروری';

    /**
     * کفِ اعلام‌شدهٔ وردپرس — برگرفته از هدرِ افزونه (`Requires at least: 6.9`)؛
     * عددِ جدیدی اعلام نمی‌شود.
     *
     * بازهٔ پشتیبانی‌شده = سه نسخهٔ اصلیِ پایدارِ آخر (۷.۱، ۷.۰، ۶.۹)؛ کهن‌ترینِ
     * آن‌ها همین کف است. مقدار باید با هدرِ افزونه یکی بماند
     * (`SystemHealthRuntimeCompatibilityTest::testDeclaredFloorsMatchTheAuthoritativeContract`).
     */
    public const MIN_WORDPRESS_VERSION = '6.9';

    /**
     * راهنمایِ فارسیِ ارتقا برای نصب‌های کهنه‌تر از کفِ پشتیبانی‌شده.
     *
     * عمداً فقط **متنِ راهنما** است: هیچ قفل/محدودیت/دسترسی‌زدایی‌ای ایجاد
     * نمی‌کند، هیچ دروازهٔ شبه‌مجوزی (license-like gate) نمی‌سازد و دسترسی به
     * دادهٔ بالینی/مالیِ تاریخی و بکاپ/بازیابی را محدود نمی‌کند (Spec §40).
     * در ردیفِ FAIL چکِ `wp.version` گزارش می‌شود و صفحهٔ سیستم آن را زیر
     * «چه کنم» نشان می‌دهد.
     */
    public const WORDPRESS_UPGRADE_GUIDANCE_FA = 'نسخهٔ وردپرس این سایت کهنه‌تر از کهن‌ترین نسخهٔ پشتیبانی‌شده ('
        . self::MIN_WORDPRESS_VERSION . ') است. '
        . 'پیش از هر کار یک بکاپ کامل بگیرید، سپس از پیشخوان → «به‌روزرسانی‌ها» وردپرس را به آخرین نسخهٔ پایدار به‌روز کنید؛ '
        . 'اگر پیشخوان در دسترس نیست، از پنل هاست یا WP-CLI (`wp core update`) استفاده کنید. '
        . 'تا پیش از ارتقا هیچ دسترسی‌ای گرفته نمی‌شود: پرونده‌های بیماران، نوبت‌ها، سوابق مالی، خروجی‌ها و بکاپ‌ها همچنان قابل مشاهده و بازیابی هستند.';

    /**
     * کفِ اعلام‌شدهٔ MySQL — برگرفته از SRS §2.2 (محیط اجرا)؛ عددِ جدیدی
     * اعلام نمی‌شود.
     *
     * این کف **فقط** برای موتورِ MySQL است. MariaDB یک موتورِ جدا با نسخه‌بندیِ
     * مستقل است و مدرکِ آن هرگز مدرکِ «MySQL ≥ 8.0» نیست؛ سیاستِ MariaDB در
     * ثابت‌های `MARIADB_*` و در `mariadb_version_row()` آمده است.
     */
    public const MIN_MYSQL_VERSION = '8.0';

    /** شناسهٔ ساختاریافتهٔ موتور MySQL — برگرفته از مدرکِ نسخهٔ سرور. */
    public const DB_ENGINE_MYSQL = 'mysql';

    /** شناسهٔ ساختاریافتهٔ موتور MariaDB — برگرفته از مدرکِ نسخهٔ سرور. */
    public const DB_ENGINE_MARIADB = 'mariadb';

    /** موتورِ نامشخص — هرگز به‌صورت MySQL یا MariaDB تفسیر نمی‌شود. */
    public const DB_ENGINE_UNKNOWN = 'unknown';

    /**
     * نسخهٔ MariaDB که واقعاً در CI این مخزن اجرا و آزموده می‌شود:
     * `.github/workflows/ci.yml` سرویسِ `mariadb:11.4.13` را بالا می‌آورد و
     * مجموعهٔ migration/onboarding روی همان سرور اجرا می‌شود.
     *
     * این «نسخهٔ آزمایش‌شده» است، نه «همهٔ نسخه‌های 11.4».
     */
    public const MARIADB_TESTED_VERSION = '11.4.13';

    /**
     * مرزِ سازگاریِ مستندشدهٔ MariaDB = سریِ **10.11** (سریِ توصیه‌شدهٔ رسمیِ
     * وردپرس). `.github/workflows/mariadb-10.11-compat.yml` این سری را هفتگی
     * روی MariaDB 10.11.19 با WordPress واقعی اجرا می‌کند.
     */
    public const MARIADB_COMPAT_BOUNDARY = '10.11';

    /** وصلهٔ دقیقِ سریِ 10.11 که به‌صورتِ دوره‌ای آزموده می‌شود. */
    public const MARIADB_COMPAT_BOUNDARY_TESTED_PATCH = '10.11.19';

    /** چک‌های حیاتی برای پشتیبانی میزبان */
    private const CRITICAL_KEYS = [
        'php.version',
        'wp.version',
        'db.reachable',
        'db.mysql_version',
        'db.migrated',
        'storage.files',
        'storage.backups',
        'cron.jobs',
    ];

    /** @var callable|null (برای تست/تزریق) */
    private $now = null;

    /**
     * @param Settings|null $settings پیکربندیِ Clinic-local. `null` یعنی هیچ
     *        ClinicScope معتبری در دسترس نیست (نصبِ چند-Clinic بدونِ انتخابِ
     *        صریح). گزارشِ سلامت سطح نصب در این حالت هم کامل کار می‌کند؛
     *        ردیف‌هایی که واقعاً Clinic-local‌اند صادقانه «پیکربندیِ Clinic-local
     *        خوانده نشد» گزارش می‌شوند و هیچ Clinic‌ای حدس زده نمی‌شود.
     */
    public function __construct(
        private readonly CpmsDb $db,
        private readonly ?Settings $settings,
        private readonly InstallationSettings $installationSettings,
        private readonly LicenseService $licenses,
        private readonly BackupService $backups,
        private readonly UpdateService $updates,
        private readonly OpLogger $op,
        ?callable $now = null
    ) {
        $this->now = $now;
    }

    private function ts(): int
    {
        return $this->now !== null ? (int) ($this->now)() : time();
    }

    /**
     * @return array{checks: list<array{key:string,label:string,status:string,detail:string}>, host: array{status:string, issues:list<string>}}
     */
    public function run(): array
    {
        $checks = [];
        $add = function (string $key, string $label, string $status, string $detail = '') use (&$checks): void {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
        };

        // ---------- PHP ----------
        $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
        $add('php.version', 'PHP نسخه', $phpOk ? self::PASS : self::FAIL, PHP_VERSION);
        $add(
            'php.sodium',
            'PHP ext/sodium',
            extension_loaded('sodium') ? self::PASS : self::WARNING,
            extension_loaded('sodium') ? 'امضای مجوز/انتشار در دسترس است' : 'غایب — تأیید امضای اسناد مجوز/انتشار ممکن نیست (fail-closed)'
        );
        $mem = (int) ini_get('memory_limit');
        $add('php.memory', 'PHP memory_limit', $mem <= 0 || $mem >= 128 ? self::PASS : self::WARNING, ini_get('memory_limit') ?: '?');

        // D3: guidance for release ZIP larger than default upload limits (no silent raise).
        // WordPress shows a generic "link expired" when POST exceeds post_max_size.
        $upload_check = self::upload_limits_check();
        $add( $upload_check['key'], $upload_check['label'], $upload_check['status'], $upload_check['detail'] );

        // کفِ اعلام‌شدهٔ وردپرس (spec §40) — فقط گزارش، بدون PHI.
        $wp_check = self::wordpress_version_check( $this->running_wordpress_version() );
        $add(
            $wp_check['key'],
            $wp_check['label'],
            $wp_check['status'],
            $wp_check['detail']
        );

        // ---------- دیتابیس ----------
        $dbOk = false;
        try {
            $dbOk = (int) $this->db->fetchValue('SELECT 1') === 1;
        } catch (\Throwable) {
            $dbOk = false;
        }
        $add('db.reachable', 'دیتابیس', $dbOk ? self::PASS : self::FAIL, $dbOk ? 'اتصال برقرار است' : 'عدم دسترسی به دیتابیس');

        // کفِ اعلام‌شدهٔ MySQL (SRS §2.2) — فقط گزارش، بدون PHI.
        $db_check = self::database_server_version_check( $this->database_server_version() );
        $add(
            $db_check['key'],
            $db_check['label'],
            $db_check['status'],
            $db_check['detail']
        );

        $migrated = false;
        $schemaVersion = '';
        try {
            $migrated = $this->isMigrated();
            $schemaVersion = (string) (App::migrations()->currentVersion() ?? '');
        } catch (\Throwable) {
            $migrated = false;
        }
        $add('db.migrated', 'Migration Schema', $migrated ? self::PASS : self::FAIL, $schemaVersion);

        $tables = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s",
            [$this->db->dbPrefix() . 'cpms_%']
        );
        $add('db.tables', 'جداول cpms_', $tables > 0 ? self::PASS : self::FAIL, (string) $tables);

        // ---------- Cron / Jobs (spec §10–§13) ----------
        // CPMS Classic Admin UX (E) — وضعیت از تشخیصِ کاملِ Queue/WP-Cron
        // می‌آید: نصبِ تازه، رویدادِ زمان‌بندیِ غایب، اجرای کهنه، Jobهای
        // شکست‌خورده و WP-Cronِ سالمِ متکی به ترافیک از هم تفکیک می‌شوند.
        // WP-Cronِ سالم دیگر «خطای بحرانیِ پیکربندی» نیست.
        $cron = App::queue_diagnosis( 300 );
        $add( 'cron.jobs', 'Cron/Queue', (string) $cron['status'], (string) $cron['label'] . ' | ' . (string) $cron['detail'] );

        // ---------- Storage (spec §22/§23) ----------
        $files_storage = $this->storage_status( $this->filesBase() );
        $add( 'storage.files', 'Storage فایل‌های پزشکی', $files_storage['status'], $files_storage['detail'] );
        // OD-9 — ریشهٔ بکاپ داخل DocumentRoot دیگر WARNING نیست: یک dump کامل
        // پایگاه داده داخل webroot یک پیکربندی **رد‌شده** است (نوشتن Fail-Closed
        // می‌شود) و سلامت سیستم باید صادقانه FAIL گزارش دهد.
        $backups_storage = $this->storage_status( $this->backups->store()->basePath() );
        $add( 'storage.backups', 'Storage بکاپ', $backups_storage['status'], $backups_storage['detail'] );

        // ---------- License (ADR-0023) ----------
        $state = $this->licenses->currentState();
        $license_status = match ( $state['status'] ) {
            LicenseStatus::ACTIVE => self::PASS,
            LicenseStatus::EXPIRING, LicenseStatus::GRACE => self::WARNING,
            LicenseStatus::ACTIVATION_PENDING, LicenseStatus::ACTIVATION_GRACE => self::WARNING,
            LicenseStatus::DEVELOPMENT => self::WARNING,
            LicenseStatus::NOT_CONFIGURED => self::NOT_CONFIGURED,
            default => self::FAIL, // RESTRICTED/SUSPENDED/REVOKED/INVALID/UNREACHABLE
        };
        $license_detail = $state['reason'] !== '' ? $state['reason'] : '';
        if ($state['expires_at'] !== null && $state['status'] !== LicenseStatus::NOT_CONFIGURED) {
            $license_detail = ( $license_detail !== '' ? $license_detail . ' | ' : '' ) . 'مهلت/انقضا ' . gmdate( 'Y-m-d', (int) $state['expires_at'] );
        }
        $add( 'license.state', 'مجوز', $license_status, $license_detail );
        $license_server_url = (string) $this->clinic_setting( 'license.server_url', '' );
        if ( null === $this->settings ) {
            // `license.server_url` در پیکربندیِ Clinic-local ذخیره می‌شود؛ در
            // نصبِ چند-Clinic بدونِ انتخابِ صریح Clinic خوانده نمی‌شود. هیچ
            // Clinic‌ای حدس زده نمی‌شود و ادعای «پیکربندی‌نشده» هم ساخته نمی‌شود.
            $add(
                'license.gateway',
                'سرور مجوز',
                self::NOT_CONFIGURED,
                'پیکربندیِ Clinic-local — بدونِ انتخابِ صریح Clinic خوانده نمی‌شود؛ فعال‌سازی دستی/آفلاین مستقل از این ردیف ممکن است'
            );
        } else {
            $add(
                'license.gateway',
                'سرور مجوز',
                $license_server_url !== '' ? self::PASS : self::NOT_CONFIGURED,
                $license_server_url !== '' ? 'پیکربندی‌شده' : 'فعال‌سازی دستی/آفلاین ممکن است'
            );
        }

        // ---------- Backup ---------- (M-2 GREEN: سطح نصب)
        $bkEnabled = $this->installationSettings->getBackupEnabled();
        $lastRun = $this->installationSettings->getBackupLastRunAt();
        if (!$bkEnabled) {
            $add('backup.enabled', 'بکاپ دوره‌ای', self::NOT_CONFIGURED, 'غیرفعال — در «CPMS (سیستم)» فعال کنید (spec §22)');
        } else {
            $ageH = $lastRun > 0 ? (int) (($this->ts() - $lastRun) / 3600) : -1;
            $status = $lastRun > 0 && $ageH <= $this->installationSettings->getBackupIntervalHours() ? self::PASS : self::WARNING;
            $add('backup.enabled', 'بکاپ دوره‌ای', $status, $lastRun > 0 ? 'آخرین: ' . $ageH . ' ساعت پیش' : 'هنوز اجرا نشده');
        }

        // ---------- Update ----------
        if (!$this->updates->isUpdateEntitled()) {
            $add('update.entitlement', 'به‌روزرسانی', self::FAIL, 'سند مجوز feature `updates` را نمی‌دهد');
        } else {
            $add('update.entitlement', 'به‌روزرسانی', self::PASS, 'مجاز');
        }

        // ---------- HTTPS ----------
        $add('https.active', 'HTTPS', function_exists('is_ssl') && is_ssl() ? self::PASS : self::WARNING, function_exists('is_ssl') && is_ssl() ? 'فعال' : 'روی HTTP — برای تولید HTTPS الزامی است');

        // ---------- Host Capability ----------
        $host = self::classifyHost($checks);

        return ['checks' => $checks, 'host' => $host];
    }

    /**
     * چکِ سازگاریِ نسخهٔ وردپرسِ در حال اجرا با کفِ اعلام‌شده (Spec §40).
     *
     * خالص و قابل‌واحدتست (بدون WP/DB). منبعِ نسخه در `run()` از
     * `get_bloginfo('version')` می‌آید — همان منبعی که `BackupService` و
     * `LicenseService` برای گزارش نسخهٔ وردپرس استفاده می‌کنند.
     *
     * مدرکِ ناموجود/ناخوانا/ناتجزیه‌پذیر ⇒ `UNKNOWN` (هرگز `PASS` —
     * fail-closed؛ ادعایِ تأییدنشده تولید نمی‌شود).
     *
     * زیرِ کف ⇒ `FAIL` و متنِ ردیف شاملِ `WORDPRESS_UPGRADE_GUIDANCE_FA` است
     * (راهنمایِ فارسیِ ارتقا). این راهنما فقط متن است: هیچ قفل/محدودیتِ
     * دسترسی و هیچ اثرِ بالینی/مالی ندارد.
     *
     * @return array{key:string,label:string,status:string,detail:string}
     */
    public static function wordpress_version_check( ?string $version ): array {
        $parsed = self::parse_version( $version );

        if ( null === $parsed ) {
            return [
                'key'    => 'wp.version',
                'label'  => 'نسخهٔ وردپرس',
                'status' => self::UNKNOWN,
                'detail' => 'نسخهٔ وردپرس در دسترس نیست یا قابل‌تشخیص نیست — برآورده‌شدنِ کفِ '
                    . self::MIN_WORDPRESS_VERSION . ' تأییدپذیر نیست',
            ];
        }

        // زیرِ کف ⇒ FAIL + راهنمایِ فارسیِ ارتقا. راهنما صرفاً متن است و هیچ اثری
        // بر دسترسی به داده (بالینی/مالی/بکاپ) ندارد — تشخیص/گزارش، نه دروازه.
        if ( ! version_compare( $parsed, self::MIN_WORDPRESS_VERSION, '>=' ) ) {
            return [
                'key'    => 'wp.version',
                'label'  => 'نسخهٔ وردپرس',
                'status' => self::FAIL,
                'detail' => trim( (string) $version ) . ' — کفِ اعلام‌شده: ' . self::MIN_WORDPRESS_VERSION
                    . ' | راهنمای ارتقا: ' . self::WORDPRESS_UPGRADE_GUIDANCE_FA,
            ];
        }

        return [
            'key'    => 'wp.version',
            'label'  => 'نسخهٔ وردپرس',
            'status' => self::PASS,
            'detail' => trim( (string) $version ) . ' — کفِ اعلام‌شده: ' . self::MIN_WORDPRESS_VERSION,
        ];
    }

    /**
     * تشخیصِ موتورِ دیتابیس **فقط** از مدرکِ نسخهٔ سرور (`SELECT VERSION()`).
     *
     * خالص و قابل‌واحدتست. سه مقدار ممکن است: `mysql`، `mariadb`، `unknown`.
     * هیچ حدس/پیکربندی/فهرستِ بسته‌ای ورودی نیست — تنها مدرکِ خودِ سرور.
     *
     * `unknown` یعنی مدرک ناخوانا/ناتجزیه‌پذیر است، نه «MySQL» (fail-closed).
     */
    public static function database_engine( ?string $version ): string {
        $raw = trim( (string) $version );

        if ( '' === $raw ) {
            return self::DB_ENGINE_UNKNOWN;
        }

        // نشانگرِ «MariaDB» در رشتهٔ VERSION() تنها مدرکِ قطعیِ موتورِ MariaDB است.
        if ( false !== stripos( $raw, 'mariadb' ) ) {
            return self::DB_ENGINE_MARIADB;
        }

        if ( 1 === preg_match( '/^\d+(?:\.\d+)*/', $raw ) ) {
            return self::DB_ENGINE_MYSQL;
        }

        return self::DB_ENGINE_UNKNOWN;
    }

    /**
     * شمارهٔ نسخهٔ سرور، نرمال‌شده برای مقایسه — یا null اگر قابل‌استخراج نباشد.
     *
     * MySQL:  `8.0.33-0ubuntu0.22.04.1` ⇒ `8.0.33`
     * MariaDB: `10.11.2-MariaDB` ⇒ `10.11.2`
     * MariaDB (کلاینتِ قدیمی): `5.5.5-10.11.2-MariaDB-log` ⇒ `10.11.2`
     *          پیشوندِ `5.5.5-` یک پیشوندِ پروتکلِ سازگاری است، نه نسخهٔ سرور؛
     *          خواندنِ آن به‌عنوان نسخه، سرورِ 10.11 را «5.5» گزارش می‌کند.
     */
    public static function database_version_number( ?string $version, ?string $engine = null ): ?string {
        $raw = trim( (string) $version );

        if ( '' === $raw ) {
            return null;
        }

        $engine = null !== $engine ? $engine : self::database_engine( $raw );

        if ( self::DB_ENGINE_MARIADB === $engine && 1 === preg_match( '/^5\.5\.5-(.+)$/', $raw, $matches ) ) {
            $raw = (string) $matches[1];
        }

        if ( 1 !== preg_match( '/\d+(?:\.\d+)*/', $raw, $matches ) ) {
            return null;
        }

        return (string) $matches[0];
    }

    /** برچسبِ کاربرپسندِ ردیفِ نسخهٔ دیتابیس، بر پایهٔ موتورِ تشخیص‌داده‌شده. */
    public static function database_version_label( string $engine ): string {
        return match ( $engine ) {
            self::DB_ENGINE_MARIADB => 'نسخهٔ سرور دیتابیس (MariaDB)',
            self::DB_ENGINE_MYSQL   => 'نسخهٔ سرور دیتابیس (MySQL)',
            default                 => 'نسخهٔ سرور دیتابیس',
        };
    }

    /**
     * چکِ نسخهٔ سرور دیتابیس — تفکیک‌شده بر پایهٔ موتور.
     *
     * خالص و قابل‌واحدتست. منبعِ نسخه در `run()` خودِ سرور است
     * (`SELECT VERSION()`)، چون فقط سرور می‌تواند دربارهٔ خودش مدرک بدهد.
     *
     * قراردادها:
     *  - MySQL: کفِ اعلام‌شده `MIN_MYSQL_VERSION` (8.0) — زیرِ کف ⇒ FAIL.
     *  - MariaDB: مدرکِ MariaDB **هرگز** مدرکِ «MySQL 8» نیست و ادعایِ چنین
     *    چیزی تولید نمی‌شود. وضعیت از شواهدِ واقعیِ همین مخزن می‌آید:
     *      • `11.4.13` (نسخهٔ آزموده‌شده در CI) ⇒ PASS
     *      • سریِ `10.11` (مرزِ سازگاریِ مستند/آزمودهٔ هفتگی؛ وصلهٔ آزموده‌شده
     *        `10.11.19`) ⇒ PASS
     *      • کهنه‌تر از مرزِ 10.11 ⇒ FAIL (پشتیبانی نمی‌شود)
     *      • هر نسخهٔ دیگر (مثلاً 10.6، 11.0–11.3، 11.4.x≠13، 11.5+، 12.x)
     *        ⇒ WARNING (آزموده‌نشده — پشتیبانی ادعا نمی‌شود، شکست هم ادعا نمی‌شود)
     *  - مدرکِ ناخوانا/ناتجزیه‌پذیر ⇒ UNKNOWN (fail-closed؛ هرگز PASS).
     *
     * `detail` همیشه با یک شناسهٔ ساختاریِ انگلیسی (`mysql:`/`mariadb:`) شروع
     * می‌شود تا مصرف‌کننده‌ها به متنِ فارسیِ ترجمه‌پذیر وابسته نشوند.
     *
     * @return array{key:string,label:string,status:string,detail:string}
     */
    public static function database_server_version_check( ?string $version ): array {
        $raw    = trim( (string) $version );
        $engine = self::database_engine( $raw );
        $parsed = self::database_version_number( $raw, $engine );
        $label  = self::database_version_label( $engine );

        if ( self::DB_ENGINE_UNKNOWN === $engine ) {
            // مدرکِ ناخوانا/ناتجزیه‌پذیر: هیچ قضاوتی روی کفِ MySQL انجام نمی‌شود
            // (fail-closed). استخراجِ یک عددِ تصادفی از رشتهٔ نامعتبر، نسخهٔ
            // ساختگی می‌سازد و می‌تواند FAIL کاذب تولید کند.
            return [
                'key'    => 'db.mysql_version',
                'label'  => 'نسخهٔ سرور دیتابیس',
                'status' => self::UNKNOWN,
                'detail' => 'نسخهٔ سرور دیتابیس در دسترس نیست یا قابل‌تشخیص نیست — برآورده‌شدنِ کفِ MySQL '
                    . self::MIN_MYSQL_VERSION . ' تأییدپذیر نیست',
            ];
        }

        if ( self::DB_ENGINE_MARIADB === $engine ) {
            if ( null === $parsed ) {
                // D5/D6 — مدرکِ نامعتبر/نامشخص «آماده» نیست و دروازهٔ مهاجرت
                // (migration 0027) هم آن را fail-closed رد می‌کند (انطباقِ regex دقیق). بنابراین اینجا UNKNOWNِ «هشدارگونه» ساخته نمی‌شود.
                return [
                    'key'    => 'db.mysql_version',
                    'label'  => $label,
                    'status' => self::FAIL,
                    'detail' => 'mariadb:unknown_version | ' . ( '' !== $raw ? $raw : 'نامشخص' )
                        . ' | MariaDB شناسایی شد اما شمارهٔ نسخهٔ آن قابل‌استخراج نیست — سازگاری تأییدپذیر نیست'
                        . ' و دروازهٔ مهاجرت/فعال‌سازی (migration 0027) نسخهٔ ناشناخته را Fail-Closed رد می‌کند'
                        . ' | نسخه‌های آزموده‌شده: MariaDB ' . self::MARIADB_TESTED_VERSION
                        . ' و سریِ ' . self::MARIADB_COMPAT_BOUNDARY,
                ];
            }

            return self::mariadb_version_row( $raw, $parsed );
        }

        if ( null === $parsed ) {
            return [
                'key'    => 'db.mysql_version',
                'label'  => $label,
                'status' => self::UNKNOWN,
                'detail' => 'نسخهٔ سرور دیتابیس در دسترس نیست یا قابل‌تشخیص نیست — برآورده‌شدنِ کفِ MySQL '
                    . self::MIN_MYSQL_VERSION . ' تأییدپذیر نیست',
            ];
        }

        return [
            'key'    => 'db.mysql_version',
            'label'  => $label,
            'status' => version_compare( $parsed, self::MIN_MYSQL_VERSION, '>=' ) ? self::PASS : self::FAIL,
            'detail' => 'mysql:detected | ' . $raw . ' — کفِ اعلام‌شده: MySQL ' . self::MIN_MYSQL_VERSION,
        ];
    }

    /**
     * ردیفِ MariaDB — بر پایهٔ شواهدِ واقعیِ همین مخزن (نه حدس/گمان).
     *
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private static function mariadb_version_row( string $raw, string $parsed ): array {
        $label    = self::database_version_label( self::DB_ENGINE_MARIADB );
        $boundary = self::MARIADB_COMPAT_BOUNDARY;
        $tested   = self::MARIADB_TESTED_VERSION;
        $patch    = self::MARIADB_COMPAT_BOUNDARY_TESTED_PATCH;

        // تصمیمِ PASS/FAIL از **همان دروازه‌ای** می‌آید که مهاجرت استفاده می‌کند؛
        // مقایسهٔ نسخه‌ای در این کلاس دیگر مرجع نیست (فقط برای متنِ توضیح).
        $gate_accepts = DatabaseCompatibilityGate::accepts_mariadb_version( $raw );
        $engine_note  = ' | MariaDB به‌عنوان MySQL ' . self::MIN_MYSQL_VERSION . ' تفسیر نمی‌شود (موتورِ مستقل با نسخه‌بندیِ مستقل)';

        if ( version_compare( $parsed, $boundary, '<' ) ) {
            return [
                'key'    => 'db.mysql_version',
                'label'  => $label,
                'status' => self::FAIL,
                'detail' => 'mariadb:below_boundary | ' . $raw
                    . ' — کهنه‌تر از مرزِ سازگاریِ مستند: MariaDB ' . $boundary
                    . ' (آزموده‌شده: ' . $patch . ') یا نسخهٔ آزموده‌شدهٔ MariaDB ' . $tested
                    . $engine_note,
            ];
        }

        if ( $gate_accepts && version_compare( $parsed, $tested, '==' ) ) {
            return [
                'key'    => 'db.mysql_version',
                'label'  => $label,
                'status' => self::PASS,
                'detail' => 'mariadb:tested | ' . $raw
                    . ' — نسخهٔ MariaDB آزموده‌شده در CI این افزونه: ' . $tested . $engine_note,
            ];
        }

        if ( $gate_accepts ) {
            return [
                'key'    => 'db.mysql_version',
                'label'  => $label,
                'status' => self::PASS,
                'detail' => 'mariadb:compatibility_boundary | ' . $raw
                    . ' — سریِ ' . $boundary . ' مرزِ سازگاریِ مستند و آزمودهٔ این افزونه است (وصلهٔ آزموده‌شده: '
                    . $patch . ')' . $engine_note,
            ];
        }

        // D1/D6 — هم‌خوانیِ صریح با دروازهٔ واقعیِ migration 0027.
        //
        // دروازه فقط `10.11.x` و `11.4.13` را می‌پذیرد و بقیه را fail-closed رد
        // می‌کند (فعال‌سازی/Migration شکست می‌خورد). بنابراین این ردیف نمی‌تواند
        // WARNING ("پشتیبانی‌شده با هشدار") باشد: چنین نسخه‌ای در عمل **پشتیبانی
        // نمی‌شود** تا وقتی واقعاً آزموده و دروازه گسترش یابد. صادقانه FAIL، با
        // تفکیکِ واضح از «آزموده‌شده» و «زیرِ مرز».
        return [
            'key'    => 'db.mysql_version',
            'label'  => $label,
            'status' => self::FAIL,
            'detail' => 'mariadb:unverified | ' . $raw
                . ' — این نسخهٔ MariaDB در CI این افزونه آزموده نشده و دروازهٔ مهاجرت/فعال‌سازی (migration 0027)'
                . ' آن را Fail-Closed رد می‌کند؛ نسخه‌های آزموده‌شده: MariaDB '
                . $tested . ' و سریِ ' . $boundary . ' (وصلهٔ آزموده‌شده: ' . $patch . ')'
                . $engine_note,
        ];
    }

    /**
     * Conservative HOST policy, NOT the release ZIP size. The installable ZIP
     * measured 10,302,900 bytes in the reviewed PR CI runs; the outer Actions
     * artifact is a different archive. Require 32 MiB upload and 40 MiB POST
     * (multipart headroom) for this policy. Future releases must be measured
     * independently; they may exceed this floor.
     */
    public const MIN_UPLOAD_BYTES = 32 * 1024 * 1024;
    public const MIN_POST_BYTES   = 40 * 1024 * 1024;

    public const ZIP_SIZE_GUIDANCE_FA = 'بستهٔ انتشار CPMS (حاوی runtime vendor و کاتالوگ آفلاین ۳۱ استان) از سقف پیش‌فرض بسیاری از هاست‌ها بزرگ‌تر است. '
        . 'اگر حجم POST از post_max_size بیشتر شود، وردپرس بدون توضیحِ علت خطای گمراه‌کنندهٔ «پیوند منقضی شده» را نشان می‌دهد؛ '
        . 'این محدودیت در سطح میزبان/PHP است و افزونه آن را بی‌صدا تغییر نمی‌دهد. '
        . 'برای نصب: حجم ZIP قابل‌نصب را اندازه بگیرید؛ upload_max_filesize را بالاتر از حجم آن و post_max_size را بالاتر از مجموع آپلود و سربار درخواست در php.ini/.user.ini یا پنل هاست تنظیم کنید، '
        . 'یا ZIP را از طریق FTP/پنل فایل یا WP-CLI (wp plugin install --activate file.zip) بارگذاری کنید. '
        . 'مقدار مجاز آپلود را می‌توانید در «رسانه ← افزودن» یا همین صفحهٔ سلامت ببینید.';

    /**
     * Check whether PHP upload limits can carry the release ZIP (D3).
     * Pure and unit-testable: uses injected ini strings or live ini_get().
     *
     * @return array{key:string,label:string,status:string,detail:string}
     */
    public static function upload_limits_check( ?string $post_max = null, ?string $upload_max = null ): array {
        $raw_post   = null !== $post_max ? $post_max : (string) ini_get( 'post_max_size' );
        $raw_upload = null !== $upload_max ? $upload_max : (string) ini_get( 'upload_max_filesize' );

        $post_bytes   = self::parse_bytes( $raw_post );
        $upload_bytes = self::parse_bytes( $raw_upload );
        $label_post   = trim( $raw_post ) !== '' ? trim( $raw_post ) : 'نامشخص';
        $label_up     = trim( $raw_upload ) !== '' ? trim( $raw_upload ) : 'نامشخص';
        $detail       = 'post_max_size=' . $label_post . ' (' . self::describe_bytes( $post_bytes ) . ')'
            . ' | upload_max_filesize=' . $label_up . ' (' . self::describe_bytes( $upload_bytes ) . ')'
            . ' | آستانهٔ احتیاطی (نه اندازهٔ ZIP): آپلود ' . self::format_bytes( self::MIN_UPLOAD_BYTES )
            . ' / POST ' . self::format_bytes( self::MIN_POST_BYTES );

        // null = unreadable/unparseable → WARNING (unknown is never a false PASS).
        // 0 = explicitly unlimited (PHP treats "0" as no limit).
        $ok_post   = null !== $post_bytes && ( 0 === $post_bytes || $post_bytes >= self::MIN_POST_BYTES );
        $ok_upload = null !== $upload_bytes && ( 0 === $upload_bytes || $upload_bytes >= self::MIN_UPLOAD_BYTES );
        $status    = ( $ok_post && $ok_upload ) ? self::PASS : self::WARNING;
        if ( $status !== self::PASS ) {
            $detail .= ' | ' . self::ZIP_SIZE_GUIDANCE_FA;
        }
        if ( null === $post_bytes || null === $upload_bytes ) {
            $detail .= ' | مقدار یکی از سقف‌ها قابل خواندن/تجزیه نبود؛ وضعیت نامشخص (fail-safe، نه PASS).';
        }

        return [ 'key' => 'php.upload_limits', 'label' => 'محدودیت آپلود ZIP (post_max_size / upload_max_filesize)', 'status' => $status, 'detail' => $detail ];
    }

    private static function describe_bytes( ?int $bytes ): string {
        if ( null === $bytes ) {
            return 'نامشخص';
        }
        if ( 0 === $bytes ) {
            return 'بی‌سقف';
        }

        return self::format_bytes( $bytes );
    }

    /**
     * Parse a PHP ini size string (e.g. "8M", "128M", "2G", "0") to bytes.
     * Returns 0 for an explicit unlimited value ("0", "0M") and null when the
     * value is empty or unparseable (unknown — never silently "unlimited").
     */
    private static function parse_bytes( string $value ): ?int {
        $v = trim( $value );
        if ( '' === $v ) {
            return null;
        }
        if ( '0' === $v ) {
            return 0;
        }
        $unit = strtolower( substr( $v, -1 ) );
        $num  = $v;
        $mult = 1;
        if ( ctype_alpha( $unit ) ) {
            $num  = trim( substr( $v, 0, -1 ) );
            $mult = match ( $unit ) {
                'g' => 1024 * 1024 * 1024,
                'm' => 1024 * 1024,
                'k' => 1024,
                default => 1,
            };
            if ( ! is_numeric( $num ) ) {
                return null;
            }
        }
        if ( ! is_numeric( $num ) ) {
            return null;
        }
        $bytes = (int) ( (float) $num * $mult );

        return $bytes < 0 ? null : $bytes;
    }

    private static function format_bytes( int $bytes ): string {
        if ( $bytes >= 1024 * 1024 * 1024 ) {
            return round( $bytes / ( 1024 * 1024 * 1024 ), 1 ) . 'G';
        }
        if ( $bytes >= 1024 * 1024 ) {
            return round( $bytes / ( 1024 * 1024 ), 1 ) . 'M';
        }
        if ( $bytes >= 1024 ) {
            return round( $bytes / 1024, 1 ) . 'K';
        }
        return (string) $bytes . 'B';
    }

    /**
     * دسته‌بندی خالص وضعیت میزبان — واحدتست.
     *
     * @param list<array{key:string,label:string,status:string,detail:string}> $checks
     *
     * @return array{status:string, issues:list<string>}
     */
    /**
     * شناساییِ ساختاریافتهٔ MariaDB در مدرکِ سازگاریِ دیتابیس.
     *
     * مدرکِ MariaDB مدرکِ «MySQL ≥ 8.0» نیست، اما نبودِ مدرکِ MySQL-اختصاصی
     * نباید به‌تنهایی کلِ میزبان را UNSUPPORTED کند. شناسایی بر پایهٔ شناسهٔ
     * ساختاریافتهٔ `mariadb:` یا نشانگرِ انگلیسیِ MariaDB انجام می‌شود، نه
     * متنِ فارسیِ تشخیص.
     */
    public static function is_maria_db_compatibility_detail( string $detail ): bool {
        $normalized = strtolower( trim( $detail ) );

        return str_starts_with( $normalized, 'mariadb:' )
            || str_contains( $normalized, 'mariadb' );
    }

    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    public static function isMariaDbCompatibilityDetail( string $detail ): bool {
        return self::is_maria_db_compatibility_detail( $detail );
    }

    public static function classifyHost(array $checks): array
    {
        $issues = [];
        $warnings = [];
        foreach ($checks as $c) {
            if (!in_array($c['key'], self::CRITICAL_KEYS, true)) {
                continue;
            }
            // CPMS Classic Admin UX (D) — ردیفِ db.mysql_version بر پایهٔ موتور
            // قضاوت می‌شود: MySQL با کفِ 8.0، MariaDB فقط با نسخه‌هایی که
            // **دروازهٔ مهاجرت** (migration 0027) واقعاً می‌پذیرد.
            //
            // پیش از این یک استثنای ویژه، هر ردیفِ MariaDB با وضعیتِ UNKNOWN را
            // (پیشوندِ `mariadb:` یا حتی فقط واژهٔ MariaDB در متن) به «هشدار»
            // تنزل می‌داد؛ نتیجه: مدرکِ نامشخص/ناتجزیه‌پذیر میزبان را
            // «پشتیبانی‌شده با هشدار» نشان می‌داد در حالی که مهاجرت همان نسخه را
            // fail-closed رد می‌کند. این استثنا حذف شد (D5/D6): مدرکِ نامشخص یا
            // نسخهٔ آزموده‌نشده، مسدودکننده است و صادقانه در `issues` می‌آید.
            if ( $c['status'] === self::FAIL || $c['status'] === self::UNKNOWN ) {
                $issues[] = $c['key'] . ': ' . ( $c['detail'] ?? '' );
            } elseif ( $c['status'] === self::WARNING ) {
                $warnings[] = $c['key'];
            }
        }
        if ($issues !== []) {
            return ['status' => self::HOST_UNSUPPORTED, 'issues' => $issues];
        }

        return [
            'status' => $warnings !== [] ? self::HOST_SUPPORTED_WITH_WARNINGS : self::HOST_SUPPORTED,
            'issues' => $warnings !== [] ? $warnings : [],
        ];
    }

    /**
     * CPMS Classic Admin UX (G) — گروه‌بندیِ خالصِ ردیف‌های سلامت برای ارائه.
     *
     * چهار گروهِ متمایز، بدونِ هیچ حذف یا «سبزکردنِ» نمایشی:
     *  - `blockers`  : وضعیتِ FAIL/UNKNOWN (و هر وضعیتِ ناشناخته — احتیاطی)
     *  - `warnings`  : WARNING (قابل‌اقدام)
     *  - `optional`  : NOT_CONFIGURED (پیکربندیِ اختیاری)
     *  - `healthy`   : PASS
     *
     * هیچ ردیفی دور ریخته نمی‌شود: مجموعِ چهار گروه همیشه برابرِ ورودی است.
     *
     * @param list<array{key:string,label:string,status:string,detail:string}> $checks
     *
     * @return array{
     *     blockers:list<array{key:string,label:string,status:string,detail:string}>,
     *     warnings:list<array{key:string,label:string,status:string,detail:string}>,
     *     optional:list<array{key:string,label:string,status:string,detail:string}>,
     *     healthy:list<array{key:string,label:string,status:string,detail:string}>
     * }
     */
    public static function group_checks( array $checks ): array {
        $groups = [
            'blockers' => [],
            'warnings' => [],
            'optional' => [],
            'healthy'  => [],
        ];

        foreach ( $checks as $check ) {
            $status = (string) ( $check['status'] ?? '' );

            // نگاشت **تک‌به‌یک** و بدونِ استثنای پنهان:
            //   NOT_CONFIGURED ⇒ optional، FAIL/UNKNOWN ⇒ blockers.
            //
            // پیش از این یک استثنای ویژه هر `db.mysql_version` با وضعیتِ UNKNOWN
            // را (اگر متنش MariaDB را ذکر می‌کرد) به «هشدار» می‌برد؛ یعنی همان
            // ادعای «مدرکِ نامشخص = قابل‌قبول» که در `classifyHost()` هم حذف شد.
            // نکتهٔ مهم: `NOT_CONFIGURED` هرگز برای ریشهٔ **الزامیِ** ذخیره‌سازی
            // صادر نمی‌شود (`storage_status()` نبودِ پوشه را FAIL می‌دهد) و برای
            // ردیف‌های واقعاً اختیاری (مثل سرور مجوز یا بکاپِ غیرفعال) می‌ماند.
            $group = match ( $status ) {
                self::PASS           => 'healthy',
                self::WARNING        => 'warnings',
                self::NOT_CONFIGURED => 'optional',
                self::FAIL           => 'blockers',
                self::UNKNOWN        => 'blockers',
                default              => 'blockers',
            };

            $groups[ $group ][] = $check;
        }

        return $groups;
    }

    /**
     * نگاشتِ وضعیتِ داخلی به یکی از سه وضعیتِ کاربرپسند (فارسی).
     */
    public static function user_facing_status( string $status, string $key = '', string $detail = '' ): string {
        // `$key`/`$detail` برای سازگاریِ امضای منتشرشده نگه داشته شده‌اند؛
        // وضعیتِ کاربرپسند فقط از `$status` می‌آید تا هیچ ردیفی با متنِ فارسیِ
        // خودش «سبک‌تر» از واقعیت نمایش داده نشود.
        unset( $key, $detail );

        return match ( $status ) {
            self::PASS => self::USER_STATUS_READY,
            self::FAIL, self::UNKNOWN => self::USER_STATUS_CRITICAL,
            default => self::USER_STATUS_NEEDS_SETUP,
        };
    }

    /**
     * نگاشتِ وضعیتِ میزبان به یکی از سه وضعیتِ کاربرپسند (فارسی).
     */
    public static function user_facing_host_status( string $status ): string {
        return match ( $status ) {
            self::HOST_SUPPORTED => self::USER_STATUS_READY,
            self::HOST_SUPPORTED_WITH_WARNINGS => self::USER_STATUS_NEEDS_SETUP,
            default => self::USER_STATUS_CRITICAL,
        };
    }

    // Aliases for compatibility with callers/tests that use shorter names.
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    public static function statusLabel( string $status ): string {
        return self::user_facing_status( $status );
    }

    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    public static function hostStatusLabel( string $status ): string {
        return self::user_facing_host_status( $status );
    }

    // ================= private =================

    /**
     * نسخهٔ وردپرسِ در حال اجرا (منبعِ زنده) یا null اگر قابل‌تشخیص نباشد.
     *
     * `get_bloginfo()` بیرون از وردپرس وجود ندارد؛ نبودش «نسخهٔ نامعلوم»
     * است، نه «نسخهٔ تأییدشده».
     */
    private function running_wordpress_version(): ?string {
        if ( ! function_exists( 'get_bloginfo' ) ) {
            return null;
        }

        $version = get_bloginfo( 'version' );

        return is_string( $version ) && '' !== trim( $version ) ? trim( $version ) : null;
    }

    /**
     * نسخهٔ سرور دیتابیس از خودِ سرور (`SELECT VERSION()`) یا null اگر
     * قابل‌خواندن نباشد.
     *
     * فقط یک برچسبِ نسخه خوانده می‌شود — بدون PHI، بدون دادهٔ tenant و بدون
     * هیچ اثرِ تغییردهنده (read-only).
     */
    private function database_server_version(): ?string {
        try {
            $version = $this->db->fetchValue( 'SELECT VERSION()' );
        } catch ( \Throwable ) {
            return null;
        }

        return is_string( $version ) && '' !== trim( $version ) ? trim( $version ) : null;
    }

    /**
     * استخراجِ بخشِ عددیِ آغازینِ یک رشتهٔ نسخه — کوچک و قطعی.
     *
     * `8.0.33-0ubuntu0.22.04.1` ⇒ `8.0.33` · `6.7.2` ⇒ `6.7.2` ·
     * `banana` و `''` ⇒ null (یعنی غیرقابل‌اتکا، نه «کمتر از کف»).
     */
    private static function parse_version( ?string $version ): ?string {
        if ( null === $version ) {
            return null;
        }

        $trimmed = trim( $version );

        if ( '' === $trimmed ) {
            return null;
        }

        if ( 1 !== preg_match( '/^\d+(?:\.\d+)*/', $trimmed, $m ) ) {
            return null;
        }

        return $m[0];
    }

    private function isMigrated(): bool
    {
        $latest = '';
        foreach ((array) glob(CPMS_PLUGIN_DIR . 'src/Migrations/????_??_??_????_*.php') as $f) {
            $latest = max($latest, substr(basename($f), 0, 15));
        }
        $applied = App::migrations()->currentVersion();

        return $latest !== '' && $applied !== null && $applied >= $latest;
    }

    /**
     * خواندنِ یک Settingِ Clinic-local **بدونِ الزام به Scope**.
     *
     * وقتی ClinicScope معتبری در دسترس نیست (`$settings === null`) یا خواندن
     * شکست می‌خورد، پیش‌فرضِ امنِ همان کلید برمی‌گردد — نه یک مقدارِ Clinicِ
     * حدس‌زده‌شده. این تنها نقطهٔ مجازِ خواندنِ پیکربندیِ Clinic-local در این
     * سرویس است.
     */
    private function clinic_setting( string $key, mixed $fallback = null ): mixed {
        if ( null === $this->settings ) {
            return $fallback;
        }

        try {
            return $this->settings->get( $key, $fallback );
        } catch ( \Throwable ) {
            return $fallback;
        }
    }

    private function filesBase(): string
    {
        $configured = trim( (string) $this->clinic_setting( 'files.storage_path', '' ) );
        if ($configured !== '') {
            return $configured;
        }

        // OD-7 — پیش‌فرض بیرون از DocumentRoot است. پیش از این اینجا مسیر
        // قدیمی گزارش می‌شد و سلامت سیستم دربارهٔ ریشه‌ای حرف می‌زد که دیگر
        // ذخیره‌سازی فعال نبود.
        return LocalFileStorage::defaultBasePath();
    }

    /**
     * وضعیت محافظت یک ریشهٔ ذخیره‌سازی.
     *
     * Phase 1A — تصحیح صحت گزارش: پیش از این صرفِ وجود `.htaccess` نتیجهٔ
     * `PASS` می‌داد. `.htaccess` روی nginx خوانده نمی‌شود و روی هیچ
     * وب‌سروری «مجوز» نیست، فقط یک لایهٔ دفاعی مکمل است.
     *
     * `PASS` فقط وقتی داده می‌شود که ریشه بیرون از DocumentRoot باشد.
     */
    /**
     * وضعیت یک ریشهٔ **فعالِ الزامی** ذخیره‌سازی.
     *
     * اصلاحِ نقصِ پذیرش (C/F): پیش از این «پوشه وجود ندارد» ⇒
     * `NOT_CONFIGURED` بود؛ `group_checks()` آن وضعیت را به گروهِ
     * «پیکربندیِ اختیاری» می‌برد. یعنی یک ریشهٔ **الزامیِ** بالینی در نصبِ تازه
     * (یا روی میزبانِ غیرقابل‌نوشتن) به‌صورت «اختیاری و سالم» نمایش داده می‌شد،
     * در حالی که `classifyHost()` همان کلید را در `CRITICAL_KEYS` می‌شمارد.
     *
     * اکنون: نبودِ پوشه ⇒ `FAIL` با جزئیاتِ قابل‌اقدام. `PrivateStorageInitializer`
     * این پوشه‌ها را در فعال‌سازی/ادمین می‌سازد؛ اگر میزبان اجازه ندهد، همین
     * ردیف صادقانه مسدودکننده می‌ماند تا مشکل پنهان نشود.
     *
     * @return array{status:string,detail:string}
     */
    private function storage_status( string $path ): array {
        if ( $path === '' ) {
            return [
                'status' => self::FAIL,
                'detail' => 'مسیر ذخیره‌سازی خالی است؛ هیچ ریشهٔ فعالی پیکربندی نشده',
            ];
        }
        if ( ! is_dir( $path ) ) {
            return [
                'status' => self::FAIL,
                'detail' => 'ریشهٔ ذخیره‌سازی وجود ندارد: ' . $path
                    . ' — از «سلامت سیستم» دکمهٔ «راه‌اندازی ریشهٔ ذخیره‌سازی» را اجرا کنید؛'
                    . ' اگر میزبان اجازهٔ ساخت پوشه را نداد، والدِ پوشه را برای وب‌سرور قابل‌نوشتن کنید',
            ];
        }
        if ( ! wp_is_writable( $path ) ) {
            return [
                'status' => self::FAIL,
                'detail' => 'ریشهٔ ذخیره‌سازی برای وب‌سرور قابل‌نوشتن نیست: ' . $path,
            ];
        }
        if ( $this->isInsideWebRoot( $path ) ) {
            // گاردها (در صورت وجود) Defence in Depth هستند، نه Authorization.
            // از OD-7 (بالینی) و OD-9 (بکاپ) به بعد ریشهٔ داخل DocumentRoot یک
            // پیکربندی **رد‌شده** است — نوشتن Fail-Closed می‌شود — پس صادقانه FAIL.
            return [
                'status' => self::FAIL,
                'detail' => 'ریشهٔ ذخیره‌سازی داخل DocumentRoot است و نوشتن در آن Fail-Closed رد می‌شود: ' . $path,
            ];
        }

        // بیرون از DocumentRoot — گارد وب‌سرور فقط لایهٔ مکمل است.
        return [ 'status' => self::PASS, 'detail' => $path ];
    }

    /**
     * آیا مسیر داخل DocumentRoot وردپرس است (یعنی بالقوه از طریق HTTP قابل دسترس)؟
     */
    private function isInsideWebRoot(string $path): bool
    {
        $root = defined('ABSPATH') ? @realpath((string) ABSPATH) : false;
        $base = @realpath($path);
        if ($root === false || $base === false) {
            return false;
        }

        return str_starts_with($base, rtrim($root, '/') . '/');
    }
}
