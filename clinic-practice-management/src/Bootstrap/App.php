<?php

declare(strict_types=1);

namespace ClinicCore\Bootstrap;

use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Admin\CpmsAssets;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Admin\LocationAdminPage;
use ClinicCore\Admin\PatientAdminPage;
use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Admin\PrescriptionPrintPage;
use ClinicCore\Admin\RoleCapabilitiesPage;
use ClinicCore\Admin\SettingsAdmin;
use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Admin\SystemPage;
use ClinicCore\Admin\DoctorDashboardPage;
use ClinicCore\Admin\DoctorHandwritingPage;
use ClinicCore\Admin\SecretaryFinancePage;
use ClinicCore\Admin\SecretaryQueuePage;
use ClinicCore\Admin\SmsSettingsPage;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Backup\BackupService;
use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Booking\ScheduleService;
use ClinicCore\Application\Clinic\ClinicProfileService;
use ClinicCore\Application\Clinical\ClinicalService;
use ClinicCore\Application\Clinical\MedicalFileService;
use ClinicCore\Application\Finance\FinanceService;
use ClinicCore\Application\Handwriting\HandwritingService;
use ClinicCore\Application\Location\LocationService;
use ClinicCore\Application\Membership\MembershipService;
use ClinicCore\Application\Patients\PatientIdentityService;
use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Application\Jobs\ApptReminderHandler;
use ClinicCore\Application\Jobs\BackupRunHandler;
use ClinicCore\Application\Jobs\FollowUpReminderHandler;
use ClinicCore\Application\Jobs\HandwritingGcHandler;
use ClinicCore\Application\Jobs\HoldsExpireHandler;
use ClinicCore\Application\Jobs\IdemCleanupHandler;
use ClinicCore\Application\Jobs\OpLogCleanupHandler;
use ClinicCore\Application\Jobs\JobsDispatcher;
use ClinicCore\Application\Jobs\LicenseRefreshHandler;
use ClinicCore\Application\Jobs\NotifDispatchHandler;
use ClinicCore\Application\Jobs\OtpCleanupHandler;
use ClinicCore\Application\Jobs\RateLimitCleanupHandler;
use ClinicCore\Application\Jobs\ReportExportHandler;
use ClinicCore\Application\Jobs\SmsSendJobHandler;
use ClinicCore\Application\Jobs\SlotsGenerateHandler;
use ClinicCore\Application\Jobs\VisitsNoShowHandler;
use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Application\Reports\ExportClinicDeps;
use ClinicCore\Application\Reports\ExportService;
use ClinicCore\Application\Reports\ReportService;
use ClinicCore\Application\System\SystemHealthService;
use ClinicCore\Application\Update\UpdateService;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicensePolicy;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Backup\BackupSqlDumper;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Licensing\HttpVendorGateway;
use ClinicCore\Infrastructure\Licensing\VendorGateway;
use ClinicCore\Infrastructure\Logging\CorrelationId;
use ClinicCore\Infrastructure\Update\WpUpdateBridge;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\ClinicalNoteRepository;
use ClinicCore\Infrastructure\Repository\ClinicianRepository;
use ClinicCore\Infrastructure\Repository\ClinicRepository;
use ClinicCore\Infrastructure\Repository\LocationRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\PatientIdentityRepository;
use ClinicCore\Infrastructure\Repository\FollowUpRepository;
use ClinicCore\Infrastructure\Repository\HandwritingRepository;
use ClinicCore\Infrastructure\Repository\InvoiceRepository;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use ClinicCore\Infrastructure\Repository\MedicalFileRepository;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
use ClinicCore\Infrastructure\Repository\PaymentRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\PrescriptionRepository;
use ClinicCore\Infrastructure\Repository\RecommendationRepository;
use ClinicCore\Infrastructure\Repository\ScheduleRepository;
use ClinicCore\Infrastructure\Repository\ServiceRepository;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Infrastructure\Security\Idempotency;
use ClinicCore\Infrastructure\Security\LoginRateLimiter;
use ClinicCore\Infrastructure\Security\RateLimiter;
use ClinicCore\Infrastructure\Sms\CredentialVault;
use ClinicCore\Infrastructure\Sms\Providers\GenericApiSmsProvider;
use ClinicCore\Infrastructure\Sms\Providers\LogSmsProvider;
use ClinicCore\Infrastructure\Sms\SmsProviderInterface;
use ClinicCore\Infrastructure\Sms\SmsProviderRegistry;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Infrastructure\Storage\PrivateStorageLocation;
use ClinicCore\Infrastructure\Storage\PrivateStorageMigrator;
use ClinicCore\Infrastructure\Update\HttpUpdateMetadataGateway;
use ClinicCore\Migrations\MigrationRunner;
use ClinicCore\Rest\BookingController;
use ClinicCore\Rest\ClinicalController;
use ClinicCore\Rest\FilesController;
use ClinicCore\Rest\FinanceController;
use ClinicCore\Rest\HandwritingController;
use ClinicCore\Rest\HealthController;
use ClinicCore\Rest\NotificationsController;
use ClinicCore\Rest\OtpController;
use ClinicCore\Rest\PatientController;
use ClinicCore\Rest\QueueController;
use ClinicCore\Rest\ReportsController;
use ClinicCore\Rest\RestClinicContext;
use ClinicCore\Rest\ScheduleController;
use ClinicCore\Rest\SmsController;
use ClinicCore\Settings\InstallationSettings;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;

/**
 * نقطه اتصال افزونه به WordPress + DI سبک (singletonهای lazy).
 *
 * اصول:
 *  - Boot در هر Request سبک است؛ Migration فقط در صورت نیاز (idempotent + Lock ساده).
 *  - Business Logic در Template ممنوع (NFR-MAINT-1).
 */
final class App
{
    /** OD-7 — نشانگر پایان مهاجرت ذخیره‌سازی خصوصی. */
    private const PRIVATE_STORAGE_OPTION = 'cpms_private_storage_migrated';

    private const PRIVATE_STORAGE_DONE = '1';

    private static ?CpmsDb $db = null;
    private static ?OpLogger $op = null;
    private static ?AuditLogger $audit = null;
    private static ?JobQueue $jobs = null;
    private static ?RateLimiter $rate = null;
    private static ?LoginRateLimiter $loginRateLimiter = null;
    private static ?Idempotency $idem = null;
    private static ?SettingsFactory $settingsFactory = null;
    private static ?InstallationSettings $installationSettings = null;
    private static ?MigrationRunner $migrations = null;
    private static ?JobsDispatcher $dispatcher = null;
    private static ?SmsProviderRegistry $providers = null;
    private static ?CredentialVault $vault = null;
    private static ?SmsService $smsService = null;
    private static ?LicenseGate $licenseGate = null;
    private static ?VisitService $visitService = null;
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        RolesAndCapabilities::register();

        // Phase 1A — Item 3: محدودسازی نرخ ورود. پیش از این هیچ کنترل
        // Bruteforce ای روی wp-login و احراز هویت REST وجود نداشت.
        self::loginRateLimiter()->register();

        add_filter('rest_request_before_callbacks', [RestClinicContext::class, 'beforeCallbacks'], 10, 3);
        add_filter('rest_request_after_callbacks', [RestClinicContext::class, 'afterCallbacks'], 10, 3);

        add_action('rest_api_init', static function (): void {
            (new HealthController())->register_routes();
            (new OtpController(self::otpService()))->register_routes();
            (new SmsController(self::smsService()))->register_routes();
            (new BookingController(self::bookingService()))->register_routes();
            (new PatientController(self::patientService()))->register_routes();
            (new QueueController(self::visitService()))->register_routes();
            (new ScheduleController(self::scheduleService()))->register_routes();
            (new ClinicalController(self::clinicalService()))->register_routes();
            (new FilesController(self::medicalFileService()))->register_routes();
            (new FinanceController(self::financeService()))->register_routes();
            (new HandwritingController(self::handwritingService()))->register_routes();
            (new NotificationsController(self::notificationService()))->register_routes();
            (new ReportsController(self::reportService(), self::exportService()))->register_routes();
            // Endpointهای فازهای بعد (F8+) — مطابق API Contract.
        });

        // Cron: اولویت با Cron OS-level (bin/cpms jobs tick) — WP-Cron به‌عنوان Fallback
        add_filter('cron_schedules', static function (array $schedules): array {
            if (!isset($schedules['cpms_minute'])) {
                $schedules['cpms_minute'] = ['interval' => 60, 'display' => 'هر دقیقه (CPMS)'];
            }

            return $schedules;
        });
        if (!wp_next_scheduled('cpms_jobs_tick')) {
            wp_schedule_event(time() + 60, 'cpms_minute', 'cpms_jobs_tick');
        }
        add_action('cpms_jobs_tick', static function (): void {
            self::runTick(20);
        });

        // Migration خودکار و ایمن (idempotent) — هنگام admin_init و rest_api_init
        add_action('admin_init', static function (): void {
            self::ensureMigrated();
        });
        add_action('rest_api_init', static function (): void {
            self::ensureMigrated();
        });

        // F10 — به‌روزرسانی امن (ADR-0029): فقط slug خودمان؛ کش‌شده؛ بدون شبکه در
        // صفحات عادی. صحت sha256 بسته پیش از نصب (upgrader_pre_download).
        $updateBridge = self::wpUpdateBridge();
        add_filter('pre_set_site_transient_update_plugins', [$updateBridge, 'injectUpdatePlugins']);
        add_filter('plugins_api', [$updateBridge, 'injectPluginInfo'], 10, 3);
        add_filter('upgrader_pre_download', [$updateBridge, 'verifyPackageBeforeInstall'], 10, 4);

        // Correlation helperها (cpms_request_id/cpms_session_id) در فایل اصلی
        // افزونه تعریف می‌شوند — خارج از boot تا در همه Contextها (CLI، Test،
        // درخواست‌های زودهنگام) قطعاً موجود باشند.

        // Admin UX — منوی Top-Level «مدیریت مطب» + داشبورد + IA (Chunk A)
        CpmsAdminMenu::register();
        CpmsAssets::register(); // Chunk F — assets اسکوپ‌شدهٔ صفحات CPMS (CSS/JS محلی، فقط در صفحات CPMS)
        CpmsSetupWizard::register(); // Chunk B — راه‌اندازی گام‌به‌گام (self-service, resumable)
        StaffManagementPage::register(); // Chunk C — کاربران و دسترسی‌ها (staff/user management)
        LocationAdminPage::register(); // Phase 4 — شعبه‌ها (Location master data: create + name/timezone update)

        SettingsAdmin::register();
        SystemPage::register();
        ClinicianAdminPage::register(); // ADR-0031 / Part 2 — Setup UI پزشک + برنامه هفتگی
        RoleCapabilitiesPage::register(); // ADR-0030 / Part 1 — مدیریت دسترسی نقش‌ها
        PatientPortalPage::register(); // ADR-0030 / Part 1 — مقصد بیمار بعد از OTP
        PrescriptionPrintPage::register(); // ADR-0031 / Part 2 — چاپ نسخه (P12)
        SmsSettingsPage::register();
        SecretaryQueuePage::register();
        SecretaryFinancePage::register();
        DoctorDashboardPage::register();
        DoctorHandwritingPage::register();
        PatientAdminPage::register(); // Chunk G — Patient Management Entry (operational, capability-driven)
    }

    public static function activate(): void
    {
        RolesAndCapabilities::register();
        self::db(); // lazy init برای migrate
        self::migrations()->migrate();
        self::ensurePrivateStorage();

        if (!wp_next_scheduled('cpms_jobs_tick')) {
            wp_schedule_event(time() + 60, 'cpms_minute', 'cpms_jobs_tick');
        }

        self::op()->info('CPMS_ACTIVATED');
        self::scheduleRecurringJobs();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('cpms_jobs_tick');
    }

    private static function ensureMigrated(): void
    {
        $lock = 'cpms_migrate_lock';
        if (get_transient($lock)) {
            return;
        }
        set_transient($lock, 1, 30);
        try {
            self::migrations()->migrate();
            self::ensurePrivateStorage();
        } finally {
            delete_transient($lock);
        }
    }

    /**
     * OD-7 — انتقال یک‌بارهٔ فایل‌های بالینی و بکاپ‌ها از ریشهٔ قدیمیِ داخل
     * DocumentRoot به ریشهٔ خصوصی.
     *
     * فراخوانی در هر درخواست ادمین/REST رخ می‌دهد، پس باید در حالت «انجام‌شده»
     * عملاً رایگان باشد: یک خواندن Option و تمام. مهاجرت خودش idempotent است،
     * ولی Option از پیمایش بی‌مورد پوشه هم جلوگیری می‌کند.
     *
     * اگر اپراتور مسیر را صراحتاً با Setting تعیین کرده باشد، دست نمی‌زنیم —
     * تصمیم او بر پیش‌فرض مقدم است.
     *
     * شکست جزئی مسدودکننده نیست: Option فقط وقتی ست می‌شود که هیچ خطایی نمانده
     * باشد، پس درخواست بعدی دوباره تلاش می‌کند و فایل‌های موفق تکرار نمی‌شوند.
     */
    private static function ensurePrivateStorage(): void
    {
        if ((string) get_option(self::PRIVATE_STORAGE_OPTION, '') === self::PRIVATE_STORAGE_DONE) {
            return;
        }

        $migrator = new PrivateStorageMigrator();
        $clean = true;

        $pairs = [];
        // `files.storage_path` یک Settingِ per-Clinic است و این متد در
        // `rest_api_init` هم صدا زده می‌شود — جایی که هنوز هیچ Clinicِ معتبری
        // برقرار نیست. Clinic‌ای ساخته/حدس زده نمی‌شود: وقتی Clinicِ معتبری در
        // دسترس نباشد، کلِ این انتقالِ یک‌باره به درخواستی که دارد (`admin_init`
        // یا درخواستِ RESTِ دارای Scope) موکول می‌شود. انتقال idempotent است و تا
        // وقتی کاملاً تمیز تمام نشود Optionِ «انجام‌شده» ثبت نمی‌شود، پس موکول
        // کردن آن هیچ وضعیتی را بدتر نمی‌کند — فقط دیرتر انجام می‌شود.
        try {
            $filesStoragePath = trim((string) self::settings()->get('files.storage_path', ''));
        } catch (ScopeRequiredException) {
            return;
        }
        if ($filesStoragePath === '') {
            $pairs[] = [LocalFileStorage::legacyBasePath(), LocalFileStorage::defaultBasePath(), 'clinic-files'];
        }
        $backupConfigured = trim(self::installationSettings()->getBackupStoragePath());
        if ($backupConfigured === '') {
            $pairs[] = [ProtectedBackupStore::legacyBasePath(), ProtectedBackupStore::defaultBasePath(), 'cpms-backups'];
        } elseif (PrivateStorageLocation::isInsideWebRoot($backupConfigured)) {
            // OD-9 — ریشهٔ بکاپِ پیکربندی‌شده داخل DocumentRoot است: محتوایش
            // بکاپ legacy داخل webroot است و به ریشهٔ خصوصی منتقل می‌شود
            // (idempotent؛ تأیید sha256 پیش از حذف مبدأ؛ تعارض بدون overwrite).
            // خودِ Setting عمداً تغییر نمی‌کند (تصمیم اپراتور است) — نوشتنِ
            // جدید Fail-Closed می‌ماند تا مسیر اصلاح شود؛ ریشهٔ legacy قدیمی
            // هم (اگر جدا از مسیر پیکربندی‌شده باشد) به همین مقصد می‌رود.
            $pairs[] = [$backupConfigured, ProtectedBackupStore::defaultBasePath(), 'cpms-backups-unsafe-config'];
            if (!self::samePath($backupConfigured, ProtectedBackupStore::legacyBasePath())) {
                $pairs[] = [ProtectedBackupStore::legacyBasePath(), ProtectedBackupStore::defaultBasePath(), 'cpms-backups'];
            }
        }

        foreach ($pairs as [$legacy, $private, $label]) {
            $report = $migrator->migrate($legacy, $private);
            if ($report['moved'] > 0 || $report['already'] > 0 || $report['failed'] > 0 || $report['conflict'] > 0) {
                self::op()->info('CPMS_PRIVATE_STORAGE_MIGRATION', [
                    'area' => $label,
                    'from' => $legacy,
                    'to' => $private,
                    'moved' => $report['moved'],
                    'already' => $report['already'],
                    'conflict' => $report['conflict'],
                    'failed' => $report['failed'],
                    'errors' => array_slice($report['errors'], 0, 10),
                ]);
            }
            if ($report['failed'] > 0 || $report['conflict'] > 0) {
                $clean = false;
            }
        }

        if ($clean) {
            update_option(self::PRIVATE_STORAGE_OPTION, self::PRIVATE_STORAGE_DONE, false);
        }
    }

    /** مقایسهٔ نرمال‌شدهٔ دو مسیر (بدون اثر اسلش انتهایی/ویندوزی). */
    private static function samePath(string $a, string $b): bool
    {
        $norm = static function (string $p): string {
            return rtrim(str_replace('\\', '/', $p), '/');
        };

        return $norm($a) === $norm($b);
    }

    /**
     * ثبت آخرین tick (برای Health Check — ADR-0016).
     */
    public static function recordTick(): void
    {
        try {
            self::settings()->set('jobs.last_tick_at', time());
        } catch (\Throwable) {
            // قبل از Migration ممکن است جدول نباشد
        }
    }

    /**
     * Health Queue: آخرین tick، شکست‌ها، و تشخیص Stale (توقف Cron/Queue).
     *
     * @return array<string, mixed>
     */
    public static function queueHealth(int $staleAfterSec = 300): array
    {
        // شمارنده‌های صف سطحِ نصب‌اند و به Scope نیاز ندارند؛ فقط
        // `jobs.last_tick_at` یک Settingِ per-Clinic است. وقتی هیچ Clinicِ
        // معتبری در دسترس نیست (مثلاً پویشِ ناشناسِ `/health` روی نصبِ
        // چند-Clinicه)، Clinic‌ای **ساخته/حدس زده نمی‌شود**: مقدار «نامعلوم»
        // (0 ⇒ stale) گزارش می‌شود. این جهتِ محافظه‌کارانه برای یک خواندنِ
        // observability است و endpoint را — که باید همیشه reachable باشد —
        // در دسترس نگه می‌دارد. رفتارِ تک‌Clinic و دارای‌Scope کاملاً همان است.
        $lastTick = 0;
        try {
            $lastTick = (int) self::settings()->get('jobs.last_tick_at', 0);
        } catch (ScopeRequiredException) {
            $lastTick = 0;
        }
        $stale = $lastTick > 0 && (time() - $lastTick) > $staleAfterSec;

        $counts = self::db()->fetchAll(
            'SELECT status, COUNT(*) AS c FROM ' . self::db()->table('cpms_jobs') . ' GROUP BY status'
        );
        $byStatus = [];
        foreach ($counts as $row) {
            $byStatus[(string) $row['status']] = (int) $row['c'];
        }

        return [
            'last_tick_at' => $lastTick,
            'stale' => $lastTick === 0 ? true : $stale,
            'stale_after_sec' => $staleAfterSec,
            'queued' => $byStatus['queued'] ?? 0,
            'failed' => $byStatus['failed'] ?? 0,
        ];
    }

    public static function bookingService(): BookingService
    {
        static $booking = null;
        if ($booking === null) {
            $db = self::db();
            $booking = new BookingService(
                $db,
                new SlotRepository($db),
                new AppointmentRepository($db),
                new PatientRepository($db),
                // Scope-neutral construction: BookingService پیکربندی را در
                // زمانِ هر عملیات و از Clinicِ **معتبرِ همان عملیات** می‌خواند
                // (پزشک/نوبتِ پایدار، یا Scopeِ معتبرِ کارکنان) — نه از یک
                // Clinicِ محیطی که در زمانِ ثبتِ مسیرهای REST حل شده باشد.
                self::settingsFactory(),
                self::licenseGate(),
                self::audit(),
                self::op(),
                self::idem(),
                self::smsService(),
                self::notificationService(),
                new MembershipRepository($db)
            );
        }

        return $booking;
    }

    /**
     * M-2 visits.no_show — scope-neutral construction.
     *
     * Previously this method called self::settings() (arg 9) and self::notificationService() (arg 7),
     * both of which eagerly resolved ambient Clinic via App::scope() and threw CLINIC_SCOPE_REQUIRED
     * in multi-Clinic no-Scope workers (PHP evaluates args left-to-right, so arg 7 threw before arg 9).
     *
     * Now it is truly scope-neutral:
     * - No App::settings() / App::scope() call at construction time;
     * - NotificationService is resolved per-Clinic via factory from durable row data (visit.clinic_id).
     */
    public static function visitService(): VisitService
    {
        if (self::$visitService === null) {
            $db = self::db();
            $op = self::op();
            self::$visitService = new VisitService(
                $db,
                new VisitRepository($db),
                new AppointmentRepository($db),
                self::settingsFactory(),
                self::audit(),
                self::licenseGate(),
                $op,
                static fn (int $clinicId): NotificationService => new NotificationService(
                    $db,
                    new NotificationRepository($db),
                    new MembershipRepository($db),
                    self::settingsFactory(),
                    // Clinicِ صریحِ مالکِ عملیات — resolver ثابت.
                    static fn (): int => $clinicId,
                    $op
                ),
                new MembershipRepository($db)
            );
        }

        return self::$visitService;
    }

    public static function scheduleService(): ScheduleService
    {
        static $schedule = null;
        if ($schedule === null) {
            $db = self::db();
            $schedule = new ScheduleService(
                $db,
                new ScheduleRepository($db),
                new MembershipRepository($db),
                self::locationRepository(),
                self::jobs(),
                self::audit(),
                self::op()
            );
        }

        return $schedule;
    }

    /**
     * سرویس بالینی (F5) — E7–E15 + C5–C7.
     */
    public static function clinicianRepository(): ClinicianRepository
    {
        static $repo = null;
        if ($repo === null) {
            $repo = new ClinicianRepository(self::db());
        }

        return $repo;
    }

    public static function clinicRepository(): ClinicRepository
    {
        static $repo = null;
        if ($repo === null) {
            $repo = new ClinicRepository(self::db());
        }

        return $repo;
    }

    public static function clinicProfileService(): ClinicProfileService
    {
        static $service = null;
        if ($service === null) {
            $service = new ClinicProfileService(
                self::db(),
                self::clinicRepository(),
                self::authorization_service(),
                self::audit()
            );
        }

        return $service;
    }

    /**
     * ریپوی canonical شعبه‌ها (cpms_locations) — Phase 4 Location master data.
     */
    public static function locationRepository(): LocationRepository
    {
        static $repo = null;
        if ($repo === null) {
            $repo = new LocationRepository(self::db());
        }

        return $repo;
    }

    /**
     * سرویس Location master data — CREATE + UPDATE name/timezone
     * (trusted Clinic + CONFIG scoped + IANA timezone + parity-safe denial).
     */
    public static function locationService(): LocationService
    {
        static $service = null;
        if ($service === null) {
            $service = new LocationService(
                self::db(),
                self::locationRepository(),
                self::authorization_service(),
                self::audit()
            );
        }

        return $service;
    }

    /**
     * سرویس عضویت (Phase 2 — C4 primitives / P2-D1).
     */
    public static function membership_service(): MembershipService {
        static $service = null;
        if ( $service === null ) {
            $service = new MembershipService(
                self::db(),
                new MembershipRepository( self::db() )
            );
        }

        return $service;
    }

    /**
     * سرویس مجوز کلینیک‌محور (Phase 3 Slice 1 — AuthorizationService foundation).
     * Reusable, typed, fail-closed, بدون وابستگی به Scope محیطی.
     */
    public static function authorization_service(): \ClinicCore\Application\Authorization\AuthorizationService
    {
        static $service = null;
        if ($service === null) {
            $service = new \ClinicCore\Application\Authorization\AuthorizationService(
                new MembershipRepository(self::db())
            );
        }

        return $service;
    }

    /**
     * سرویس هویت بیمار (Phase 2 — C5 foundation / AD-14).
     */
    public static function patient_identity_service(): PatientIdentityService {
        static $service = null;
        if ( $service === null ) {
            $service = new PatientIdentityService(
                self::db(),
                new PatientIdentityRepository( self::db() )
            );
        }

        return $service;
    }

    public static function clinicalService(): ClinicalService
    {
        static $clinical = null;
        if ($clinical === null) {
            $db = self::db();
            $clinical = new ClinicalService(
                $db,
                new VisitRepository($db),
                self::visitService(),
                new ClinicalNoteRepository($db),
                new PrescriptionRepository($db),
                new RecommendationRepository($db),
                new FollowUpRepository($db),
                // Scope-neutral construction: policy از ردیفِ پایدارِ ویزیت خوانده می‌شود.
                self::settingsFactory(),
                self::audit(),
                new PatientRepository($db),
                new MedicalFileRepository($db)
            );
        }

        return $clinical;
    }

    /**
     * سرویس مالی (F6) — D12–D18 + P3 + G2 (تعرفه‌ها).
     */
    public static function financeService(): FinanceService
    {
        static $finance = null;
        if ($finance === null) {
            $db = self::db();
            $finance = new FinanceService(
                $db,
                new ServiceRepository($db),
                new InvoiceRepository($db),
                new PaymentRepository($db),
                new VisitRepository($db),
                self::visitService(),
                new PatientRepository($db),
                self::audit()
            );
        }

        return $finance;
    }

    /**
     * سرویس دست‌خط پزشک (F7) — FR-9.1..9.3 / ADR-0009 / ADR-0014.
     */
    public static function handwritingService(): HandwritingService
    {
        static $handwriting = null;
        if ($handwriting === null) {
            $db = self::db();
            $handwriting = new HandwritingService(
                $db,
                new HandwritingRepository($db),
                new VisitRepository($db),
                // Phase 2 M-2 (W): سیاستِ per-Clinic از SettingsFactory حل
                // می‌شود — سرویسِ دست‌خط دیگر به Scope محیطی وابسته نیست.
                self::settingsFactory(),
                self::audit(),
                new Idempotency($db)
            );
        }

        return $handwriting;
    }

    /**
     * سرویس اعلان (F8) — N-1..N-6 (Internal + هم‌راهی SMS پایپ‌لاین موجود).
     */
    public static function notificationService(): NotificationService
    {
        static $notifications = null;
        if ($notifications === null) {
            $notifications = new NotificationService(
                self::db(),
                new NotificationRepository(self::db()),
                new MembershipRepository(self::db()),
                // Scope-neutral construction (الگوی SmsService).
                self::settingsFactory(),
                static fn (): int => self::scope()->clinicId,
                self::op()
            );
        }

        return $notifications;
    }

    /**
     * Storage محافظت‌شده (خارج webroot) — الگوی medicalFileService:
     * عمداً بدون کش تا Setting files.storage_path تغییرپذیر بماند.
     */
    public static function localFileStorage(): LocalFileStorage
    {
        return new LocalFileStorage(self::fileStoragePath(self::settings()));
    }

    /**
     * سرویس گزارش (F8 — FR-19.2: ۱۲ گزارش، Scope سرور-side).
     */
    public static function reportService(): ReportService
    {
        // Scope-neutral construction (الگوی SmsService).
        return new ReportService(
            self::db(),
            self::settingsFactory(),
            static fn (): int => self::scope()->clinicId,
            self::audit()
        );
    }

    /**
     * سرویس Export گزارش (F8 — FR-19.3: async + CSV + Audit + دانلود محافظت‌شده).
     *
     * **Phase 2 (Slice 1B.1) — Scope-Neutral:** ساختِ این سرویس **هیچ** Clinic‌ای
     * نمی‌خواهد و هیچ خواندنِ Settings/فایل/پیکربندیِ tenant-دار انجام نمی‌دهد.
     * وابستگی‌های Clinic-دار از طریقِ یک کارخانهٔ `\Closure` **به‌صورت lazy** و
     * فقط برای Clinic‌ای حل می‌شوند که یک مرزِ قابلِ اعتماد تعیین کرده است
     * (`ExportService::trustedClinicId()` در REST، یا
     * `clinicIdFromJobPayload()` **به‌همراهِ** `requireClinicMembership()` در Job).
     *
     * این جایگزینِ الگوی حذف‌شدهٔ `bindPayloadClinicScope()` است: پیش‌تر
     * `dispatcher()` مجبور بود `clinic_id` **خامِ** payload را به `ScopeContext`
     * موردِ اعتماد bind کند تا فقط بتواند سرویس را بسازد — یعنی context پیش از
     * مجوز. اکنون اصلاً نیازی به آن bind نیست.
     */
    public static function exportService(): ExportService
    {
        return new ExportService(
            self::db(),
            new NotificationRepository(self::db()),
            new MembershipRepository(self::db()),
            self::jobs(),
            static fn (int $clinicId): ExportClinicDeps => self::exportClinicDeps($clinicId),
            self::audit(),
            self::op(),
            // Phase 3 Slice 4 — مرزِ مجوزِ Clinic-scoped (REST و Job).
            self::authorization_service()
        );
    }

    /**
     * بستهٔ وابستگی‌های Clinic-دارِ Export — **فقط** با شناسهٔ Clinicِ از‌پیش‌تعیین‌شده.
     *
     * `Settings` از `SettingsFactory` با کلیدِ `clinicId` می‌آید (نه از `App::scope()`)،
     * پس این متد هیچ وابستگی‌ای به Scope جاری ندارد و در worker بدونِ کاربر هم
     * درست کار می‌کند.
     */
    public static function exportClinicDeps(int $clinicId): ExportClinicDeps
    {
        $settings = self::settingsFactory()->forClinic($clinicId);

        return new ExportClinicDeps(
            new ReportService(
                self::db(),
                self::settingsFactory(),
                static fn (): int => $clinicId,
                self::audit()
            ),
            new NotificationService(
                self::db(),
                new NotificationRepository(self::db()),
                new MembershipRepository(self::db()),
                self::settingsFactory(),
                static fn (): int => $clinicId,
                self::op()
            ),
            new LocalFileStorage(self::fileStoragePath($settings)),
            $settings
        );
    }

    /**
     * ریشهٔ ذخیره‌سازیِ فایل برای یک `Settings` مشخص — بدونِ اتکا به Scope.
     */
    private static function fileStoragePath(Settings $settings): string
    {
        $configured = trim((string) $settings->get('files.storage_path', ''));

        return $configured !== '' ? $configured : LocalFileStorage::defaultBasePath();
    }

    /**
     * سرویس فایل‌های پزشکی (F5) — E16/E17 + C3/C4.
     *
     * مسیر ذخیره: Setting `files.storage_path` (مطلق، خارج DocumentRoot —
     * توصیه file-storage.md) یا پیش‌فرض `wp-content/clinic-files` با
     * .htaccess deny + index.php خالی.
     */
    public static function medicalFileService(): MedicalFileService
    {
        // عمداً بدون کشِ سرویس: مسیر ذخیره باید در هر عملیات تازه باشد —
        // میخ‌کردنش به Clinic/lحظهٔ bootstrap تغییرِ files.storage_path را
        // بی‌اثر می‌کرد. resolver زیر همان خواندن را در زمانِ عملیات انجام
        // می‌دهد و هم‌زمان ساخت را scope-neutral نگه می‌دارد (الگوی SmsService).
        $storageResolver = static function (int $clinicId): LocalFileStorage {
            $configured = trim((string) self::settingsFactory()->forClinic($clinicId)->get('files.storage_path', ''));

            return new LocalFileStorage($configured !== '' ? $configured : LocalFileStorage::defaultBasePath());
        };

        return new MedicalFileService(
            new MedicalFileRepository(self::db()),
            $storageResolver,
            self::settingsFactory(),
            self::audit()
        );
    }

    public static function patientService(): PatientService
    {
        static $patients = null;
        if ($patients === null) {
            $db = self::db();
            $patients = new PatientService(
                $db,
                new PatientRepository($db),
                // وابستگیِ Settings حذف شد: این سرویس هرگز از آن نمی‌خواند و
                // نگه‌داشتنش تنها دلیلِ حل‌کردنِ Clinicِ محیطی در زمانِ ساخت بود.
                self::licenseGate(),
                self::audit(),
                self::op()
            );
        }

        return $patients;
    }

    public static function otpService(): OtpService
    {
        static $otp = null;
        if ($otp === null) {
            $otp = new OtpService(
                self::db(),
                // Scope-neutral construction (الگوی SmsService): پیکربندی در
                // زمانِ عملیات حل می‌شود، نه در زمانِ ثبتِ مسیرهای REST.
                self::settingsFactory(),
                static fn (): int => self::scope()->clinicId,
                self::rate(),
                self::audit(),
                self::op(),
                self::smsService(),
                self::jobs()
            );
        }

        return $otp;
    }

    /**
     * Registry Providerهای SMS + Adapterهای داخلی + Hook افزونه‌پذیری (ADR-0025).
     */
    public static function providers(): SmsProviderRegistry
    {
        if (self::$providers === null) {
            $registry = new SmsProviderRegistry();
            $registry->register(new LogSmsProvider(self::op()));
            // Phase 2 (§A-3 / RT-6): `sms.generic` **per-Clinic** است. اگر همین‌جا
            // خوانده می‌شد، پیکربندیِ Clinicِ bootstrap برای کلِ فرآیند freeze
            // می‌شد. اکنون یک Closure پاس می‌شود که در **لحظهٔ استفاده** و برای
            // Clinicِ فعالِ همان عملیات حل می‌شود. نتیجهٔ جانبیِ مهم: ساختِ
            // registry دیگر به Scope نیاز ندارد (RT-4).
            $registry->register(new GenericApiSmsProvider(
                static fn (): array => (array) self::settings()->get('sms.generic', [])
            ));
            if (function_exists('do_action')) {
                do_action('cpms_sms_provider', $registry);
            }
            self::$providers = $registry;
        }

        return self::$providers;
    }

    public static function vault(): CredentialVault
    {
        if (self::$vault === null) {
            self::$vault = new CredentialVault();
        }

        return self::$vault;
    }

    /**
     * LicenseGate (Seam — ADR-0023): F10 = Gate واقعی (SignedLicenseGate).
     * Business Services تغییر نمی‌کنند؛ وضعیت از state محلیِ امضاشده خوانده
     * می‌شود — **ممنوع:** Network Call به License Server در مسیر Booking
     * (فقط Job refresh شبکه می‌رود).
     *
     * نصب بدون سند معتبر → پنجرهٔ فعال‌سازی (تصمیم کارفرما): نصب تازه
     * ACTIVATION_PENDING (۷ روز) / نصب pre-F10 ACTIVATION_GRACE (۳۰ روز)؛
     * پایان پنجره بدون سند → RESTRICTED. حالت توسعه فقط صریح (CPMS_DEV_MODE
     * یا فیلتر cpms_license_dev_mode). ایمنی بیمار هرگز قفل نمی‌شود (§1).
     */
    public static function licenseGate(): LicenseGate
    {
        if (self::$licenseGate === null) {
            self::$licenseGate = new SignedLicenseGate(self::licenseService());
        }

        return self::$licenseGate;
    }

    /**
     * سرویس لایسنس (F10) — وضعیت محلی + همگام‌سازی با سرور فروشنده (Job).
     */
    public static function licenseService(): LicenseService
    {
        static $licenses = null;
        if ($licenses === null) {
            $licenses = new LicenseService(
                new LicenseRepository(self::db()),
                self::licenseGateway(),
                self::db(),
                new LicensePolicy()
            );
        }

        return $licenses;
    }

    public static function licenseGateway(): VendorGateway
    {
        static $gateway = null;
        if ($gateway === null) {
            try {
                $serverUrl = (string) self::settings()->get('license.server_url', '');
            } catch (\Throwable) {
                $serverUrl = ''; // قبل از Migration — غیرفعال (NOT_CONFIGURED)
            }
            $gateway = new HttpVendorGateway(['server_url' => $serverUrl]);
        }

        return $gateway;
    }

    public static function smsService(): SmsService
    {
        if (self::$smsService === null) {
            // Phase 2 (§5-D / RT-4 / RT-6): این سرویس دیگر یک `Settings`
            // Clinic-مشخص را در لحظهٔ ساخت نمی‌گیرد، بلکه کارخانهٔ per-Clinic +
            // یک resolverِ scope می‌گیرد. پس:
            //  (۱) ساختنش به هیچ Clinic‌ای نیاز ندارد ⇒ مرزِ tick نمی‌افتد؛
            //  (۲) singleton بودنش بی‌خطر است ⇒ Clinicِ bootstrap میخ نمی‌شود.
            self::$smsService = new SmsService(
                self::db(),
                self::settingsFactory(),
                self::providers(),
                self::vault(),
                self::audit(),
                self::op(),
                self::jobs(),
                static fn (): int => self::scope()->clinicId
            );
        }

        return self::$smsService;
    }

    // ============ DI ============

    public static function db(): CpmsDb
    {
        if (self::$db === null) {
            global $wpdb;
            self::$db = new CpmsDb($wpdb);
        }

        return self::$db;
    }

    public static function op(): OpLogger
    {
        if (self::$op === null) {
            self::$op = new OpLogger(self::db());
        }

        return self::$op;
    }

    public static function audit(): AuditLogger
    {
        if (self::$audit === null) {
            self::$audit = new AuditLogger(self::db(), self::op());
        }

        return self::$audit;
    }

    public static function jobs(): JobQueue
    {
        if (self::$jobs === null) {
            self::$jobs = new JobQueue(self::db(), self::op());
        }

        return self::$jobs;
    }

    public static function loginRateLimiter(): LoginRateLimiter
    {
        if (self::$loginRateLimiter === null) {
            self::$loginRateLimiter = new LoginRateLimiter(self::rate(), self::op());
        }

        return self::$loginRateLimiter;
    }

    public static function rate(): RateLimiter
    {
        if (self::$rate === null) {
            self::$rate = new RateLimiter(self::db());
        }

        return self::$rate;
    }

    public static function idem(): Idempotency
    {
        if (self::$idem === null) {
            self::$idem = new Idempotency(self::db());
        }

        return self::$idem;
    }

    public static function settings(): Settings
    {
        // F1-4: AuditLogger تزریق می‌شود تا هر تغییر Setting (قبل/بعد + کاربر) Audit شود.
        // Phase 2: Clinic پیش‌فرضِ Settings از Scope حل می‌شود (نه literal 1) —
        // در نصب تک‌کلینیکی همان Clinic تنها؛ در حالت مبهم CLINIC_SCOPE_REQUIRED.
        //
        // Phase 2 (RT-6): عمداً **بدون memo**. این متد در هر فراخوانی از Scopeِ
        // جاری مشتق می‌شود و کشِ واقعی در `SettingsFactory` با کلیدِ `clinicId`
        // است (§5-D-2). پیش از این، نمونهٔ میخ‌شده در `App::$settings` باعث
        // می‌شد Clinicِ bootstrap برای کلِ فرآیندِ PHP پیکربندی بدهد و
        // `A → B → A` در گامِ B همان A را برگرداند. هزینهٔ حذفِ memo یک
        // lookup آرایه‌ای است (نه Query) — `Settings::$cache` هم per-Clinic است.
        return self::settingsFactory()->forClinic(self::scope()->clinicId);
    }

    /**
     * کارخانهٔ `Settings` per-Clinic (§5-D-2) — نقطهٔ یکتای ساختِ پیکربندی.
     *
     * ساختنش به هیچ Clinic/Scope‌ای نیاز ندارد، پس سرویس‌هایی که آن را نگه
     * می‌دارند scope-neutral می‌مانند و مرزِ tick با `CLINIC_SCOPE_REQUIRED`
     * نمی‌افتد (RT-4).
     */
    public static function settingsFactory(): SettingsFactory
    {
        if (self::$settingsFactory === null) {
            self::$settingsFactory = new SettingsFactory(self::db(), self::audit());
        }

        return self::$settingsFactory;
    }

    /**
     * تنظیمات اسکالر سطح نصب (Phase 2) — فعلاً فقط `notif.archive_days`.
     *
     * بدون Clinic/Scope/کاربر؛ خواندن/نوشتن از wp_options با `autoload=no`.
     * کاملاً خنثی نسبت به Scope است، پس singleton بودنش بی‌خطر است (هیچ
     * Clinicِ bootstrapای میخ نمی‌شود).
     */
    public static function installationSettings(): InstallationSettings
    {
        if (self::$installationSettings === null) {
            self::$installationSettings = new InstallationSettings();
        }

        return self::$installationSettings;
    }

    /**
     * Scope فعال درخواست جاری — Phase 2 (ADR-0031).
     *
     * ترتیب: Scope صریحِ درخواست (ScopeContext) → Resolution سیستمی
     * (دقیقاً یک Clinic؛ در غیر این صورت Fail-Closed). هیچ مقدار ثابتی
     * به‌عنوان «کلینیک پیش‌فرض» وجود ندارد (AD-13).
     */
    public static function scope(): ClinicScope
    {
        $explicit = ScopeContext::tryGet();
        if ($explicit !== null) {
            return $explicit;
        }

        return SystemClinicResolver::resolve(self::db());
    }

    /**
     * باطل‌سازی کش‌های Scope (تست‌ها / تغییر دادهٔ Clinic در طول فرآیند).
     */
    public static function resetScope(): void
    {
        ScopeContext::clear();
        SystemClinicResolver::flush();
        // C6 (bug 1 census): cache تنظیمات per-clinic است و با تغییر Scope باید
        // باطل شود. Phase 2: `settings()` دیگر نمونهٔ میخ‌شده ندارد و هر بار از
        // Scope مشتق می‌شود، پس فقط کشِ **داده** لازم است خالی شود.
        Settings::flushCache();
    }

    /**
     * تعویض Scope صریح بدون flush رزولور سیستمی — مرز REST/Job تو در تو.
     */
    public static function replaceExplicitScope(?ClinicScope $scope): void
    {
        if ($scope instanceof ClinicScope) {
            ScopeContext::set($scope);
        } else {
            ScopeContext::clear();
        }
        Settings::flushCache();
    }

    public static function migrations(): MigrationRunner
    {
        if (self::$migrations === null) {
            self::$migrations = new MigrationRunner(
                self::db(),
                self::op(),
                CPMS_PLUGIN_DIR . 'src/Migrations'
            );
        }

        return self::$migrations;
    }

    /**
     * سرویس بکاپ/بازیابی (F10 — spec §22–§25). مقصد = ProtectedBackupStore
     * محلی؛ Remote (S3/SFTP) = V1.1 (Runbook در docs/backup).
     *
     * OD-9 — ریشهٔ فعال بکاپ باید بیرون از DocumentRoot باشد. اگر Setting
     * `backup.storage_path` (یا ثابت CPMS_PRIVATE_STORAGE_DIR) به مسیری داخل
     * webroot اشاره کند، مسیر **عوض نمی‌شود** و مخزن به‌صورت صریح به یک
     * «مبدأ legacy فقط‌خواندنی» تنزل می‌یابد: خواندن برای verification و
     * recovery ادامه دارد، ولی هر نوشتن (بکاپ جدید/حذف) Fail-Closed خطا
     * می‌دهد تا اپراتور مسیر را اصلاح کند. هیچ fallback بی‌صدایی نیست.
     */
    public static function backupService(): BackupService
    {
        // M-2 GREEN: backup.storage_path سطح نصب (InstallationSettings) است،
        // نه Clinic-bound. عمداً بدون کش تا تغییر مسیر بلافاصله اثر کند.
        $installation = self::installationSettings();
        $configured = trim($installation->getBackupStoragePath());
        $base = $configured !== '' ? $configured : ProtectedBackupStore::defaultBasePath();
        $store = PrivateStorageLocation::isInsideWebRoot($base)
            ? ProtectedBackupStore::legacySource($base)
            : ProtectedBackupStore::active($base);

        // Multi-Clinic File Roots (M-2 blocker fix):
        // منبع معتبر ریشه‌های فعال بالینی: cpms_clinics + cpms_settings
        // (files.storage_path per-Clinic). BackupService خودش از DB همهٔ
        // ریشه‌های فعال را می‌آورد و duplicate را یک‌بار جمع می‌کند؛ پس
        // اینجا فقط یک ریشهٔ پیش‌فرضِ امن به‌عنوان fallback/injected
        // برای سازگاری با تست‌های قدیمی می‌دهیم تا Job هرگز
        // CLINIC_SCOPE_REQUIRED نگیرد و تمام فایل‌های فعال پوشش داده شوند.
        $filesBase = PrivateStorageLocation::path('clinic-files');

        return new BackupService(
            self::db(),
            $store,
            new BackupSqlDumper(self::db()),
            $installation,
            self::audit(),
            self::op(),
            $filesBase
        );
    }

    /**
     * سرویس به‌روزرسانی امن (ADR-0029) — بررسی دستی/کش؛ بدون شبکه در
     * صفحات عادی. Entitlement از LicenseService (feature `updates`).
     */
    public static function updateService(): UpdateService
    {
        static $updates = null;
        if ($updates === null) {
            try {
                $serverUrl = (string) self::settings()->get('license.server_url', '');
            } catch (\Throwable) {
                $serverUrl = '';
            }
            $updates = new UpdateService(
                self::settings(),
                self::licenseService(),
                new HttpUpdateMetadataGateway(['server_url' => $serverUrl])
            );
        }

        return $updates;
    }

    /**
     * پل هوک‌های به‌روزرسانی وردپرس (F10 / ADR-0029) — transient + plugins_api +
     * صحت بسته پیش از نصب. بدون حالت؛ singleton برای یک‌بار ثبت هوک.
     */
    public static function wpUpdateBridge(): WpUpdateBridge
    {
        static $bridge = null;
        if ($bridge === null) {
            // Phase 2 (رگرسیون 1f8b36d): سرویس Lazy — App::boot نباید
            // Scope/Settings را resolve کند (نصبِ پیش از Migration / bootstrap
            // تست‌ها). Provider فقط داخل هوک‌ها صدا زده می‌شود و آنجا هم
            // fail-soft است (WpUpdateBridge).
            $bridge = new WpUpdateBridge(static fn (): UpdateService => self::updateService());
        }

        return $bridge;
    }

    /**
     * Health/سازگاری سیستم (F10 — spec §40). بدون PHI.
     */
    public static function systemHealthService(): SystemHealthService
        {
            static $health = null;
            if ($health === null) {
                $health = new SystemHealthService(
                    self::db(),
                    self::settings(),
                    self::installationSettings(),
                    self::licenseService(),
                    self::backupService(),
                    self::updateService(),
                    self::op()
                );
            }

            return $health;
        }

    public static function dispatcher(): JobsDispatcher
    {
        if (self::$dispatcher === null) {
            $queue = self::jobs();
            $db = self::db();
            $op = self::op();
            // Phase 2 (Slice 1B.1 — یافتهٔ بازبینی M-3): enforcementِ طبقه‌بندیِ
            // T/S/W اکنون **پیش‌فرضِ** `JobsDispatcher` است. پس production با
            // همان ساختِ معمولی fail-closed است و نمی‌تواند «تصادفاً» یک
            // dispatcherِ سهل‌گیر بسازد؛ opt-out فقط با درخواستِ صریحِ
            // زیرساختِ عمومیِ تست ممکن است.
            $dispatcher = new JobsDispatcher($queue, $op);

            // Phase 2 (RT-4 / RT-14) — ساختِ Handlerها **lazy** است.
            //
            // پیش از این، همهٔ Handlerها (و با آن‌ها `self::settings()` →
            // `App::scope()`) در همین‌جا و در لحظهٔ **ساختِ dispatcher** حل
            // می‌شدند. در نصبِ چندکلینیکیِ بدونِ کاربر/scope، کلِ tick پیش از هر
            // `claim()` با `CLINIC_SCOPE_REQUIRED` می‌افتاد — fail-closed ولی
            // خشن/سراسری.
            //
            // اکنون ساختِ Handler به داخلِ callableِ ثبت‌شده منتقل شده، یعنی
            // **داخلِ `try/catch` موجودِ هر handler در `JobsDispatcher::tick()`**.
            // نتیجه بدونِ دست زدن به `tick()`:
            //   - ساختِ dispatcher به هیچ Clinic‌ای نیاز ندارد (RT-4)؛
            //   - شکستِ scopeِ **یک** job فقط همان job را `failed` می‌کند و jobهای
            //     بی‌ارتباطِ همان tick اجرا می‌شوند (RT-14).
            //
            // وابستگی‌های واقعاً سطح نصب (db/op/queue/rate/idem/vault/providers)
            // بدون scope ساخته می‌شوند؛ وابستگی‌های Clinic-دار فقط در لحظهٔ اجرای
            // همان job حل می‌شوند.
            $dispatcher
                ->register('holds.expire', static function (array $payload) use ($db): void {
                    (new HoldsExpireHandler($db))($payload);
                })
                ->register('cleanup.otp', static function (array $payload) use ($db): void {
                    (new OtpCleanupHandler($db))($payload);
                })
                ->register('cleanup.rate_limits', static function (array $payload): void {
                    (new RateLimitCleanupHandler(self::rate()))([]);
                })
                ->register('cleanup.idem', static function (array $payload): void {
                    (new IdemCleanupHandler(self::idem()))([]);
                })
                ->register('cleanup.oplog', static function (array $payload) use ($db): void {
                    // S (installation-scoped) — scope-neutral، مثل notif.dispatch:
                    // روزهای retention از InstallationSettings (wp_options) می‌آید؛
                    // هیچ App::scope()/Settingsِ Clinic/کاربر/ScopeContext‌ای لمس نمی‌شود.
                    (new OpLogCleanupHandler($db, self::installationSettings()))($payload);
                })
                ->register('slots.generate', static function (array $payload) use ($db, $op): void {
                    (new SlotsGenerateHandler($db, self::settingsFactory(), $op))($payload);
                })
                ->register('sms.send', static function (array $payload): void {
                    // T: Clinic از مالکیتِ ردیفِ پیام حل می‌شود (SmsService).
                    (new SmsSendJobHandler(self::smsService()))($payload);
                })
                ->register('visits.no_show', static function (array $payload): void {
                    (new VisitsNoShowHandler(self::visitService(), self::jobs(), self::db(), self::op()))($payload);
                })
                ->register('handwriting.gc', static function (array $payload): void {
                    (new HandwritingGcHandler(self::handwritingService()))($payload);
                })
                ->register('notif.dispatch', static function (array $payload) use ($db): void {
                    // W-sweep scope-neutral: dispatch (queued->sent) + archive
                    // retention both run through NotificationRepository; the
                    // retention window comes from InstallationSettings
                    // (installation-level wp_options) — no App::scope(), no
                    // Clinic Settings, no user/request context.
                    (new NotifDispatchHandler(
                        new NotificationRepository($db),
                        self::installationSettings(),
                        self::exportService()
                    ))($payload);
                })
                ->register('appt.reminder', static function (array $payload) use ($db, $op): void {
                    // W-sweep: each Appointment owns its Clinic/Location. The
                    // handler therefore receives only scope-neutral services and
                    // resolves the per-Clinic NotificationService from persisted
                    // appointment data, never from ambient App::scope().
                    (new ApptReminderHandler(
                        $db,
                        self::smsService(),
                        static fn (int $clinicId): NotificationService => new NotificationService(
                            $db,
                            new NotificationRepository($db),
                            new MembershipRepository($db),
                            self::settingsFactory(),
                            static fn (): int => $clinicId,
                            $op
                        ),
                        self::jobs(),
                        $op
                    ))($payload);
                })
                ->register('fu.reminder', static function (array $payload) use ($db, $op): void {
                    // M-2 fu.reminder — scope-neutral + starvation continuation
                    // Per-Clinic NotificationService is resolved from durable row clinic_id via factory,
                    // matching proven patterns for visits.no_show, appt.reminder, slots.generate.
                    // Continuation uses existing job type fu.reminder and payload_json, no new type.
                    (new FollowUpReminderHandler(
                        $db,
                        self::settingsFactory(),
                        self::smsService(),
                        static fn (int $clinicId): NotificationService => new NotificationService(
                            $db,
                            new NotificationRepository($db),
                            new MembershipRepository($db),
                            self::settingsFactory(),
                            static fn (): int => $clinicId,
                            $op
                        ),
                        $op,
                        self::jobs()
                    ))($payload);
                })
                ->register('report.export', static function (array $payload): void {
                    // T: Clinic از `payload_json.clinic_id` — ولی payload یک
                    // «انتخابِ عملیات» است، نه مجوز و نه contextِ موردِ اعتماد.
                    // ساختِ `ExportService` دیگر **هیچ** Clinic‌ای نمی‌خواهد
                    // (scope-neutral)، پس اینجا هیچ scope‌ای از payload bind
                    // نمی‌شود. اعتبارسنجیِ عددی، **مجوزِ عضویتِ فعال** و سپس
                    // حلِ وابستگی‌های Clinic همگی داخل `ExportService::generate()`
                    // و به همان ترتیب انجام می‌شود.
                    (new ReportExportHandler(self::exportService()))($payload);
                })
                ->register('license.refresh', static function (array $payload) use ($op): void {
                    (new LicenseRefreshHandler(self::licenseService(), $op))($payload);
                })
                ->register('backup.run', static function (array $payload) use ($op): void {
                    (new BackupRunHandler(self::backupService(), self::installationSettings(), $op))($payload);
                });

            self::$dispatcher = $dispatcher;
        }

        return self::$dispatcher;
    }

    /**
     * Schedule Jobهای دوره‌ای (بعد از activation) — V1: فوری + یادآوری روزانه.
     */
    /**
     * جاب‌های تکرارشونده — **Idempotent**: اگر نسخه Queued از نوع جاب در صف
     * باشد، نسخه جدید ثبت نمی‌شود (tick هر دقیقه صدا می‌زند).
     *
     * @var array<string, int> type => priority
     */
    /** F1-7 — قفل یکپارچهٔ Tick: WP-Cron و `bin/cpms jobs tick` همان مکانیزم MySQL دارند. */
    public const TICK_LOCK = 'cpms_jobs_tick';

    /**
     * Phase 2 (RT-12): عمومی شد تا گارْدِ drift بتواند **بدون Reflection** این
     * منبعِ زمان‌بندی را با registry زمانِ اجرا (`JobsDispatcher::registeredTypes()`)
     * و با `JobScopeRegistry` مقایسه کند. فقط تغییرِ visibility — بدون تغییرِ
     * رفتار.
     *
     * @var array<string, int> type => priority
     */
    public const RECURRING_JOBS = [
        'holds.expire' => 8,
        'slots.generate' => 3,
        'visits.no_show' => 5, // FR-5.5 — no-show خودکار نوبت‌ها
        'handwriting.gc' => 2, // ADR-0009 — سیاست نگهداری نسخه‌ها (idempotent هر Tick)
        'notif.dispatch' => 6, // F8 N-2/N-3 — queued→sent + Retention (idempotent هر Tick)
        'appt.reminder' => 4, // F8 FR-20.6 — یادآوری نوبت (Dedupe دو-لایه)
        'fu.reminder' => 4, // F8 — یادآوری Follow-Up (reminder_sent_at)
        // F9 — پاک‌سازی Retention (قبلاً Handlerها ثبت بودند اما زمان‌بندی نمی‌شدند)
        'cleanup.otp' => 1,
        'cleanup.rate_limits' => 1,
        'cleanup.idem' => 1, // Idempotency::cleanup — رشد بی‌کران را می‌بندد
        'cleanup.oplog' => 1, // F1-5 — Retention لاگ عملیاتی (retention.oplog_days؛ پیش‌فرض ۹۰ روز)
        // F10 — refresh مجوز: هر Tick چک می‌شود ولی شبکه فقط در refreshDue
        // (Backoff بر اساس شکست‌های پیاپی) لمس می‌شود — ADR-0023/ADR-0016
        'license.refresh' => 9,
        // F10 — بکاپ دوره‌ای: هر Tick چک می‌شود ولی فقط در صورت
        // backup.enabled + سررسید اجرا می‌شود (spec §22–§24)
        'backup.run' => 1,
    ];

    /**
     * یک چرخه کامل Runner — مسیر واحد برای WP-Cron و `bin/cpms jobs tick`:
     * ثبت Heartbeat + زمان‌بندی مجدد (Idempotent) جاب‌های دوره‌ای + پردازش صف.
     *
     * Regression (Pilot Gate): bin/cpms قبلاً scheduleRecurringJobs را صدا
     * نمی‌زد → در استقرار system-cron (J-4) جاب‌های دوره‌ای فقط یک‌بار
     * (بعد از Activate) اجرا و بعد برای همیشه متوقف می‌شدند (FR-5.5).
     *
     * F1-7 — قفل یکپارچه: قبل از این، GET_LOCK فقط در مسیر CLI بود و WP-Cron
     * بدون قفل Tick می‌کرد → Runnerهای دو SAPI می‌توانستند هم‌زمان اجرا شوند.
     * اکنون قفل در همین متد و روی هر دو مسیر است (تابع GET_LOCK سرور MySQL —
     * مستقل از Transaction/اتصال). Skip → `-1` (پیام CLI با isTickLocked()).
     */
    public static function runTick(int $limit = 20): int
    {
        $db = self::db();
        $got = $db->fetchValue('SELECT GET_LOCK(%s, 0)', [self::TICK_LOCK]);
        if ((int) $got !== 1) {
            return -1; // Runner دیگری فعال است
        }

        try {
            self::recordTick();
            // جاب‌های تکرارشونده را (Idempotent) دوباره زمان‌بندی کن — بدون
            // این، هر جاب فقط یک‌بار (بعد از Activate) اجرا می‌شد (FR-5.5).
            self::scheduleRecurringJobs();

            return self::dispatcher()->tick($limit);
        } finally {
            $db->query('SELECT RELEASE_LOCK(%s)', [self::TICK_LOCK]);
        }
    }

    /**
     * F1-7 — آیا Tick دیگری (هر SAPI) الان قفل را گرفته؟ برای پیام CLI.
     * داخلِ خودِ Tick هم true برمی‌گرداند (قفل روی اتصالِ خودِ فرآیند است).
     */
    public static function isTickLocked(): bool
    {
        try {
            $free = self::db()->fetchValue('SELECT IS_FREE_LOCK(%s)', [self::TICK_LOCK]);

            return (int) $free !== 1;
        } catch (\Throwable) {
            return false; // قبل از Migration/بدون DB — گزارش «قفل» بی‌معناست
        }
    }

    public static function scheduleRecurringJobs(): void
    {
        $queue = self::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach (self::RECURRING_JOBS as $type => $priority) {
            // Queue-hardening: treat both QUEUED and PROCESSING as active.
            // Prevents duplicate root amplification while a chain is in flight.
            // After chain fully finishes (no queued/processing), fresh root is allowed.
            $alreadyActive = self::db()->fetchValue(
                'SELECT id FROM ' . self::db()->table('cpms_jobs') . ' WHERE type = %s AND status IN (%s, %s) LIMIT 1',
                [$type, \ClinicCore\Infrastructure\Queue\JobQueue::QUEUED, \ClinicCore\Infrastructure\Queue\JobQueue::PROCESSING]
            );
            if ($alreadyActive === null) {
                $queue->enqueue($type, [], $now, priority: $priority);
            }
        }
    }
}
