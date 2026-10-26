<?php

declare(strict_types=1);

namespace ClinicCore\Bootstrap;

use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Admin\CustomRoleDefinitionsPage;
use ClinicCore\Admin\CpmsActivationRedirect;
use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Admin\CpmsAssets;
use ClinicCore\Admin\Compatibility\WooCommerceAdminAccess;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Admin\FinanceDelegationPage;
use ClinicCore\Admin\FirstPracticeSetupPage;
use ClinicCore\Admin\LocationAdminPage;
use ClinicCore\Admin\PatientAdminPage;
use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Admin\PrescriptionPrintPage;
use ClinicCore\Admin\PublicShortcodeAdminPage;
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
use ClinicCore\Application\Finance\FinanceDelegationService;
use ClinicCore\Application\Finance\FinanceService;
use ClinicCore\Application\Handwriting\HandwritingService;
use ClinicCore\Application\Location\LocationService;
use ClinicCore\Application\Membership\MembershipService;
use ClinicCore\Application\Patients\PatientEnrollmentOptions;
use ClinicCore\Application\Patients\PatientIdentityActivator;
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
use ClinicCore\Application\Jobs\JobWake;
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
use ClinicCore\Application\System\CronScheduleRepair;
use ClinicCore\Application\System\QueueDiagnosis;
use ClinicCore\Application\System\SystemHealthService;
use ClinicCore\Application\Update\UpdateService;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicensePolicy;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Application\Management\ManagementPortalService;
use ClinicCore\Frontend\DoctorPortalShell;
use ClinicCore\Frontend\ManagementPortalShell;
use ClinicCore\Frontend\PublicBookingShortcode;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Backup\BackupSqlDumper;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Licensing\HttpVendorGateway;
use ClinicCore\Infrastructure\Licensing\VendorGateway;
use ClinicCore\Infrastructure\Logging\CorrelationId;
use ClinicCore\Infrastructure\Profiling\RequestProfiler;
use ClinicCore\Infrastructure\Update\WpUpdateBridge;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\ClinicalNoteRepository;
use ClinicCore\Infrastructure\Repository\ClinicianRepository;
use ClinicCore\Infrastructure\Repository\ClinicRepository;
use ClinicCore\Infrastructure\Repository\LocationRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\PatientEnrollmentRepository;
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
use ClinicCore\Rest\DoctorPortalController;
use ClinicCore\Rest\FilesController;
use ClinicCore\Rest\FinanceController;
use ClinicCore\Rest\FinancePortalController;
use ClinicCore\Rest\HandwritingController;
use ClinicCore\Rest\HealthController;
use ClinicCore\Rest\NotificationsController;
use ClinicCore\Rest\OtpController;
use ClinicCore\Rest\PatientController;
use ClinicCore\Rest\QueueController;
use ClinicCore\Rest\ReceptionPortalController;
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
        // Phase 17 profiling: request-profile start marker. A memoized no-op unless
        // the server-side `CPMS_PROFILE=1` flag is present (disposable Pilot runner
        // only); deliberately before the idempotence guard so a repeated boot still
        // restarts the profile exactly like a fresh request would.
        RequestProfiler::mark_boot();

        // ==================================================================
        // Frontend عمومی (خارج از wp-admin) — Phase 8 Slice 1 / FR-3.5 / UC-01
        // ==================================================================
        // سطح read-only و آنونیمِ «دیدن پزشکان و نوبت‌های آزادِ» یک Clinicِ
        // صریحاً پیکربندی‌شده روی فرانت‌اند وردپرس، با shortcode
        // `[cpms_public_booking clinic_id="N"]`.
        //
        // این ثبت عمداً **پیش از** گاردِ idempotence قرار گرفته است. دلیلش
        // فنی است، نه سلیقه‌ای: `add_shortcode()` در `$GLOBALS['shortcode_tags']`
        // ثبت می‌کند که جزو `$wp_filter` نیست، پس اگر هر بخشی از چرخهٔ زندگیِ
        // وردپرس (یا سوئیت تست) آن را بازنشانی کند، گاردِ `$booted` مانعِ
        // ثبتِ مجدد می‌شد و سطح برای بازدیدکننده از دسترس می‌افتاد. خودِ
        // ثبت idempotent است: `add_shortcode()` همان کلید را بازنویسی می‌کند و
        // `add_action()` با callback/priority یکسان در وردپرس dedupe می‌شود، پس
        // فراخوانیِ چندباره هیچ هوکِ تکراری نمی‌سازد.
        //
        // مرزِ مسئولیت: این تنها نقطهٔ تماسِ Bootstrap با این سطح است. رندرِ
        // markup، انتشارِ قراردادِ runtime و انکیوِ شرطیِ assetها همه داخل
        // `ClinicCore\Frontend\*` است (نه این‌جا)، تا Bootstrap باری از
        // HTML/JS/CSS نگیرد. assetهای این سطح کاملاً از `CpmsAssets` (wp-admin)
        // جداست و هیچ REST endpoint جدیدی ثبت نمی‌کند — فقط از A1/A4 موجودِ
        // عمومی استفاده می‌شود.
        PublicBookingShortcode::register();
        DoctorPortalShell::register();
        // Phase 10 — canonical shared operational Staff Portal container. The
        // legacy Doctor Portal entry above remains a compatibility entry (one-time redirect).
        StaffPortalShell::register();
        // Dedicated (non-wp-admin) Management Portal for Organization Managers and
        // Clinic Managers. Authority is durable Organization/Clinic membership +
        // CPMS capabilities; no WordPress business role is read.
        ManagementPortalShell::register();

        if (self::$booted) {
            return;
        }
        self::$booted = true;

        RolesAndCapabilities::register();

        // Phase 1A — Item 3: محدودسازی نرخ ورود. پیش از این هیچ کنترل
        // Bruteforce ای روی wp-login و احراز هویت REST وجود نداشت.
        self::loginRateLimiter()->register();

        add_filter('rest_request_before_callbacks', [RestClinicContext::class, 'beforeCallbacks'], 10, 3);
        // Phase 11 Slice 6 — the reception cancel route owns its trusted Clinic +
        // operational Location exclusively from the server-side reception scope:
        // raw `clinic_id`/`location_id` request parameters are removed for that
        // ONE route before authority establishment (priority 1 < the binder's
        // 10), so a raw selector can neither create/switch scope nor collide
        // with the trusted header scope. Every other route keeps the established
        // RestClinicContext behaviour unchanged.
        add_filter(
            'rest_request_before_callbacks',
            [ReceptionPortalController::class, 'reception_cancel_ignore_scope_selectors'],
            1,
            3
        );
        // Phase 11 Slice 7 — the same narrow, route-local rule for the reception
        // reschedule route: it derives trusted Clinic + operational Location from
        // the server-side reception scope only, so raw `clinic_id`/`location_id`
        // request parameters are removed for that ONE route before authority
        // establishment. RestClinicContext itself stays untouched.
        add_filter(
            'rest_request_before_callbacks',
            [ReceptionPortalController::class, 'reception_reschedule_ignore_scope_selectors'],
            1,
            3
        );
        // Keep the route-specific denial metadata hook alive across REST server resets in WP tests.
        add_filter(
            'rest_request_before_callbacks',
            [ new DoctorPortalController( new \ClinicCore\Infrastructure\Repository\MembershipRepository( self::db() ) ), 'handwriting_location_denial' ],
            11,
            3
        );
        add_filter('rest_request_after_callbacks', [RestClinicContext::class, 'afterCallbacks'], 10, 3);
        // Early scope-binder denials short-circuit before the route callback;
        // mirror their mandatory audit on the final response (idempotent with
        // the controller-level denial via a per-request marker).
        add_filter(
            'rest_post_dispatch',
            [ new DoctorPortalController( new \ClinicCore\Infrastructure\Repository\MembershipRepository( self::db() ) ), 'handwriting_post_dispatch' ],
            10,
            3
        );

        // Phase 17 profiling (measurement only): request-level bounded markers.
        // Each callback self-guards on the server-side flag, so disarmed requests
        // pay one memoized check and observe zero behavior change. `mark_api_init`
        // runs first (@1) so the init segment includes route registration and the
        // migration check; `attach_profile` runs last (@999) so the dispatch segment
        // includes permission + handler + serialization.
        add_action( 'rest_api_init', [RequestProfiler::class, 'mark_api_init'], 1 );
        add_filter( 'rest_pre_dispatch', [RequestProfiler::class, 'mark_dispatch'], 1, 3 );
        add_filter( 'rest_post_dispatch', [RequestProfiler::class, 'attach_profile'], 999, 3 );

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
            ( new DoctorPortalController( new \ClinicCore\Infrastructure\Repository\MembershipRepository( self::db() ) ) )->register_routes();
            // Phase 11 Slices 1–5 — Staff Portal reception boundary (stricter contract lives there).
            ( new ReceptionPortalController( new \ClinicCore\Infrastructure\Repository\MembershipRepository( self::db() ), new \ClinicCore\Infrastructure\Repository\AppointmentRepository( self::db() ), new \ClinicCore\Infrastructure\Repository\SlotRepository( self::db() ) ) )->register_routes();
            // Phase 12 Slices 1–5 — Staff Portal finance boundary. Slice 3 adds the
            // InvoiceRepository the capture guard needs to load the PERSISTED
            // invoice; Slice 4 adds the paid board + portal checkout, which
            // delegates to self::visitService() (no second checkout engine);
            // Slice 5 adds the PaymentRepository the read-only receipt needs to
            // prove the durable settlement from its captured payment rows, plus
            // the PatientRepository it re-reads through to prove the persisted
            // patient is the Visit's patient inside the trusted Clinic before
            // any display identity is rendered.
            // Every mutation delegates to the existing services unchanged.
            ( new FinancePortalController( new MembershipRepository( self::db() ), new VisitRepository( self::db() ), new InvoiceRepository( self::db() ), new PaymentRepository( self::db() ), new PatientRepository( self::db() ) ) )->register_routes();
            // Endpointهای فازهای بعد (F8+) — مطابق API Contract.
        });

        // Cron: اولویت با Cron OS-level (bin/cpms jobs tick) — WP-Cron به‌عنوان Fallback
        add_filter('cron_schedules', static function (array $schedules): array {
            if (!isset($schedules['cpms_minute'])) {
                $schedules['cpms_minute'] = ['interval' => 60, 'display' => 'هر دقیقه (CPMS)'];
            }

            return $schedules;
        });
        // CPMS Classic Admin UX (E) — ترمیمِ idempotent و بدونِ اجرای Job.
        // `ensure_scheduled()` فقط در نبودِ رویداد زمان‌بندی می‌کند و هرگز صف را
        // اجرا نمی‌کند؛ بنابراین مسیرِ boot/activation/رندر صفحه نمی‌تواند کارِ
        // مخرب/مالی/پیامکی/طولانی را به‌طورِ ناخواسته راه بیندازد.
        CronScheduleRepair::ensure_scheduled();

        // ترمیمِ ارزان و throttle‌شده در سمتِ مدیریت (حداکثر یک‌بار در ساعت):
        // اگر رویداد حذف/منقضی شده باشد، اولین بازدیدِ پیشخوان آن را بازمی‌سازد.
        add_action( 'admin_init', static function (): void {
            if ( CronScheduleRepair::self_heal_in_admin() ) {
                // فقط یک رخدادِ observability — بدونِ هیچ اجرای Job.
                self::op()->info( 'CPMS_CRON_SCHEDULE_REPAIRED', [
                    'hook' => CronScheduleRepair::HOOK,
                    'recurrence' => CronScheduleRepair::RECURRENCE,
                ] );
            }
        }, 20 );

        add_action('cpms_jobs_tick', static function (): void {
            self::runTick(20);
        });

        // Phase 17 — fast wake (autonomous fast-start path). One installation-wide
        // single event, requested by `JobQueue::enqueue()` after the job row is
        // committed. Same `App::runTick()` lock/claim machinery as the recurring
        // trigger; the minute-cadenced `cpms_jobs_tick` above is NOT changed and
        // stays the recovery/fallback trigger on hosts where the spawn cannot run.
        add_action(
            JobWake::HOOK,
            static function (): void {
                self::runTick( self::WAKE_TICK_LIMIT );
            }
        );

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

        // Coexistence with a third-party wp-admin lock-down policy (WooCommerce):
        // one scoped filter callback, allowlist + existing page capability only.
        WooCommerceAdminAccess::register();

        // Admin UX — منوی Top-Level «مدیریت مطب» + داشبورد + IA (Chunk A)
        CpmsAdminMenu::register();
        CpmsAssets::register(); // Chunk F — assets اسکوپ‌شدهٔ صفحات CPMS (CSS/JS محلی، فقط در صفحات CPMS)
        CpmsSetupWizard::register(); // Chunk B — راه‌اندازی گام‌به‌گام (self-service, resumable)
        CpmsActivationRedirect::register(); // Phase 3 — one-shot safe redirect to the wizard on the literal post-activation landing (plugins.php only)
        StaffManagementPage::register(); // Chunk C — کاربران و دسترسی‌ها (staff/user management)
        FirstPracticeSetupPage::register(); // Custom Role Slice 3A — راه‌اندازی اولیهٔ مطب (one-shot first-practice bootstrap)
        \ClinicCore\Admin\OrganizationManagerPage::register(); // ADR-0031 extension — Organization-scoped provisioning
        \ClinicCore\Admin\ClinicManagementPage::register(); // Post-onboarding — Clinic settings & lifecycle (Organization-scoped, history-preserving)
        LocationAdminPage::register(); // Phase 4 — شعبه‌ها (Location master data: create + name/timezone update)

        SettingsAdmin::register();
        SystemPage::register();
        ClinicianAdminPage::register(); // ADR-0031 / Part 2 — Setup UI پزشک + برنامه هفتگی
        RoleCapabilitiesPage::register(); // ADR-0030 / Part 1 — مدیریت دسترسی نقش‌ها
        FinanceDelegationPage::register(); // 2026-10-15 — واگذاری محدود مالی حساس (Clinic-scoped، قابل بازگردانی، ممیزی‌شده)
        CustomRoleDefinitionsPage::register(); // Custom Role Slice 2 — نقش‌های سفارشی Clinic-local (تعریف/نام/مجوزها)
        PatientPortalPage::register(); // ADR-0030 / Part 1 — مقصد بیمار بعد از OTP
        PrescriptionPrintPage::register(); // ADR-0031 / Part 2 — چاپ نسخه (P12)
        SmsSettingsPage::register();
        SecretaryQueuePage::register();
        SecretaryFinancePage::register();
        DoctorDashboardPage::register();
        DoctorHandwritingPage::register();
        PatientAdminPage::register(); // Chunk G — Patient Management Entry (operational, capability-driven)
        PublicShortcodeAdminPage::register(); // Classic shortcode helper (manage_options + authorized Clinic selector)
    }

    public static function activate( bool $network_wide = false ): void
    {
        RolesAndCapabilities::register();
        self::db(); // lazy init برای migrate
        self::migrations()->migrate();
        self::ensurePrivateStorage();

        // فقط زمان‌بندی — هیچ Jobی در activation اجرا نمی‌شود.
        CronScheduleRepair::ensure_scheduled();

        self::op()->info('CPMS_ACTIVATED');
        self::scheduleRecurringJobs();

        // Phase 3 — arm the one-shot, Administrator-only first-run redirect.
        // Side-effect-free beyond a short-lived transient: see
        // CpmsActivationRedirect for every safety gate (network-wide/bulk/
        // CLI/REST/cron activation, no identifiable human admin, etc.).
        CpmsActivationRedirect::flag_activation( $network_wide );
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
     *
     * CPMS Classic Admin UX (E) — منبعِ معتبر (authoritative) اکنون
     * **سطح نصب** است (`InstallationSettings`, `autoload=no`). پیش از این،
     * heartbeat فقط به‌صورتِ Settingِ per-Clinic نوشته می‌شد و در نصبِ
     * چند-Clinic `App::settings()` با `CLINIC_SCOPE_REQUIRED` شکست می‌خورد؛
     * در نتیجه صفِ سالم روی نصبِ چند-Clinic همیشه «متوقف» گزارش می‌شد.
     *
     * نوشتنِ قبلیِ per-Clinic به‌صورتِ best-effort حفظ می‌شود تا داده/خواننده‌های
     * موجود (از جمله تحلیلِ راه‌اندازیِ interrupted) بدون تغییر کار کنند؛ اما
     * دیگر شرطِ ثبتِ heartbeat نیست و شکستِ آن هرگز ثبتِ سطح نصب را متوقف نمی‌کند.
     */
    public static function recordTick(): void
    {
        $now = time();

        try {
            self::installationSettings()->setJobsLastTickAt( $now );
        } catch (\Throwable) {
            // بدون وردپرس/درایورِ Option، heartbeat سطح نصب ممکن نیست.
        }

        // سازگاریِ عقب‌رو: heartbeat قدیمیِ Clinic حفظ می‌شود (بدون Scope ⇒ رد).
        try {
            self::settings()->set( 'jobs.last_tick_at', $now );
        } catch ( \Throwable ) {
            // قبل از Migration / در نصبِ چند-Clinic بدون Scope — بی‌اثر
        }
    }

    /**
     * شواهدِ خامِ Queue (heartbeat، شمارنده‌ها، وضعیتِ زمان‌بندی).
     *
     * @return array{
     *     last_tick_at:int,
     *     heartbeat_source:string,
     *     queued:int,
     *     failed:int,
     *     has_activity:bool,
     *     schedule_scheduled:bool|null,
     *     next_scheduled_at:int|null
     * }
     */
    public static function queue_evidence(): array
    {
        $last_tick = self::installationSettings()->getJobsLastTickAt();
        $source    = 'installation';

        if ( $last_tick <= 0 ) {
            // سازگاریِ عقب‌رو: heartbeat قدیمیِ Clinic (تنها اگر Scopeِ معتبر
            // موجود باشد). هیچ Clinic‌ای برای این خواندن ساخته/حدس زده نمی‌شود.
            try {
                $legacy = (int) self::settings()->get( 'jobs.last_tick_at', 0 );
            } catch ( ScopeRequiredException ) {
                $legacy = 0;
            } catch ( \Throwable ) {
                $legacy = 0;
            }
            if ( $legacy > 0 ) {
                $last_tick = $legacy;
                $source    = 'legacy_clinic';
            } else {
                $source = 'none';
            }
        }

        $queued = 0;
        $failed = 0;
        $activity = false;
        try {
            $counts = self::db()->fetchAll(
                'SELECT status, COUNT(*) AS c FROM ' . self::db()->table( 'cpms_jobs' ) . ' GROUP BY status'
            );
            foreach ( $counts as $row ) {
                $status = (string) $row['status'];
                $count  = (int) $row['c'];
                if ( $status === 'queued' ) {
                    $queued = $count;
                } elseif ( $status === 'failed' ) {
                    $failed = $count;
                }
                $activity = true;
            }
        } catch ( \Throwable ) {
            // جدول هنوز ساخته نشده (نصبِ تازه/قبل از Migration): صفر و بدون فعالیت.
            $queued   = 0;
            $failed   = 0;
            $activity = false;
        }

        return [
            'last_tick_at'        => $last_tick,
            'heartbeat_source'    => $source,
            'queued'              => $queued,
            'failed'              => $failed,
            'has_activity'        => $activity || $queued > 0 || $failed > 0,
            'schedule_scheduled'  => CronScheduleRepair::is_scheduled(),
            'next_scheduled_at'   => CronScheduleRepair::next_scheduled_at(),
        ];
    }

    /**
     * تشخیصِ کاملِ Queue/WP-Cron برای UIِ مدیریت و صفحهٔ سلامت.
     *
     * @return array{state:string,severity:string,status:string,label:string,detail:string,guidance:string,repairable:bool}
     */
    public static function queue_diagnosis( int $staleAfterSec = 300): array
    {
        $evidence = self::queue_evidence();

        return QueueDiagnosis::classify( array_merge( $evidence, [
            'now'             => time(),
            'stale_after_sec' => $staleAfterSec,
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
        ] ) );
    }

    /**
     * Health Queue: آخرین tick، شکست‌ها، و تشخیص Stale (توقف Cron/Queue).
     *
     * قراردادِ خروجی **بدون تغییر** است (نقطهٔ عمومیِ `/clinic/v1/health` از همین
     * آرایه تغذیه می‌شود و کلیدِ جدیدی عمومی نمی‌شود)؛ فقط منبعِ heartbeat از
     * Settingِ per-Clinic به سطح نصب منتقل شده و fallbackِ قبلی حفظ شده است.
     *
     * @return array<string, mixed>
     */
    public static function queueHealth( int $staleAfterSec = 300): array
    {
        $evidence  = self::queue_evidence();
        $last_tick = (int) $evidence['last_tick_at'];
        $stale     = $last_tick > 0 && ( time() - $last_tick ) > $staleAfterSec;

        return [
            'last_tick_at' => $last_tick,
            'stale' => $last_tick === 0 ? true : $stale,
            'stale_after_sec' => $staleAfterSec,
            'queued' => (int) $evidence['queued'],
            'failed' => (int) $evidence['failed'],
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
                new MembershipRepository( $db ),
                self::patient_identity_activator()
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
     * سرویس مدیریت نقش سفارشی (Custom Role Slice 2) — Clinic-local،
     * authority از TrustedClinicEstablisher + AuthorizationService می‌آید.
     */
    public static function custom_role_management_service(): \ClinicCore\Application\Roles\CustomRoleManagementService {
        static $service = null;
        if ( $service === null ) {
            $service = new \ClinicCore\Application\Roles\CustomRoleManagementService(
                new \ClinicCore\Application\Scope\TrustedClinicEstablisher( self::db(), new MembershipRepository( self::db() ) ),
                self::authorization_service(),
                new \ClinicCore\Infrastructure\Repository\CustomRoleRepository( self::db() ),
                self::audit(),
                self::db()
            );
        }

        return $service;
    }

    /**
     * سرویس انتساب نقش سفارشی به پرسنل موجود (Custom Role Slice 3B) —
     * membership-only، Clinic-local، bounded-delegation، Auditشده.
     */
    public static function custom_role_assignment_service(): \ClinicCore\Application\Roles\CustomRoleAssignmentService {
        static $service = null;
        if ( $service === null ) {
            $service = new \ClinicCore\Application\Roles\CustomRoleAssignmentService(
                new \ClinicCore\Application\Scope\TrustedClinicEstablisher( self::db(), new MembershipRepository( self::db() ) ),
                self::authorization_service(),
                new MembershipRepository( self::db() ),
                new \ClinicCore\Infrastructure\Repository\CustomRoleRepository( self::db() ),
                self::audit(),
                self::db()
            );
        }

        return $service;
    }

    /**
     * سرویس راه‌اندازی اولیهٔ مطب (Custom Role Slice 3A) — one-shot first-practice
     * bootstrap؛ مجوز نهایی پس از آن فقط از MembershipService/AuthorizationService.
     */
    public static function first_practice_setup_service(): \ClinicCore\Application\Onboarding\FirstPracticeSetupService {
        static $service = null;
        if ( $service === null ) {
            $service = new \ClinicCore\Application\Onboarding\FirstPracticeSetupService(
                self::db(),
                new MembershipRepository( self::db() ),
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
                new MembershipRepository( self::db() ),
                new \ClinicCore\Infrastructure\Repository\CustomRoleRepository( self::db() )
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
                new PatientIdentityRepository( self::db() ),
                self::audit()
            );
        }

        return $service;
    }

    /**
     * فعال‌سازِ هویتِ سازمانیِ بیمار در مسیرهای ساختِ بیمار (Phase 2 — AD-14).
     *
     * scope-neutral: هیچ Clinic/Settingsِ محیطی در زمانِ ساخت حل نمی‌شود؛
     * Clinic همیشه از عملیاتِ فراخوان می‌آید.
     */
    public static function patient_identity_activator(): PatientIdentityActivator {
        static $activator = null;
        if ( $activator === null ) {
            $activator = new PatientIdentityActivator(
                new PatientIdentityRepository( self::db() ),
                self::patient_identity_service()
            );
        }

        return $activator;
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
                self::op(),
                self::patient_identity_activator()
            );
        }

        return $patients;
    }

    /**
     * Read-only server-side options for patient-self first enrollment.
     *
     * Scope-neutral and presentation-only: it never creates a patient, never
     * grants authority and never accepts a Clinic id. The existing
     * `POST /clinic/v1/patient/enroll` remains the sole authority.
     */
    public static function patient_enrollment_options(): PatientEnrollmentOptions {
        static $options = null;
        if ( $options === null ) {
            $options = new PatientEnrollmentOptions(
                new PatientEnrollmentRepository( self::db() ),
                self::licenseService()
            );
        }

        return $options;
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
            self::$licenseGate = new SignedLicenseGate( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Existing singleton property.
                self::licenseService(),
                new \ClinicCore\Infrastructure\Repository\ReturningPatientRepository( self::db() )
            );
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

    /**
     * 2026-10-15 — واگذاری صریح و محدود مجوزهای مالی حساس توسط مدیر کلینیک
     * (Product Decision: docs/decisions/2026-10-15-financial-authorization-policy.md).
     * از همان ذخیره‌سازی Override عضویت و همان موتور مجوز استفاده می‌کند.
     */
    public static function finance_delegation_service(): FinanceDelegationService {
        static $service = null;
        if ( $service === null ) {
            $service = new FinanceDelegationService(
                self::db(),
                new MembershipRepository( self::db() ),
                self::membership_service(),
                self::audit()
            );
        }

        return $service;
    }

    /**
     * Dedicated Management Portal orchestration (reads + authorized writes).
     */
    public static function management_portal_service(): ManagementPortalService {
        static $service = null;
        if ( $service === null ) {
            $service = new ManagementPortalService(
                self::organization_clinic_provisioning_service(),
                self::authorization_service(),
                new \ClinicCore\Application\Scope\TrustedClinicEstablisher( self::db(), new MembershipRepository( self::db() ) ),
                new MembershipRepository( self::db() ),
                new LocationRepository( self::db() ),
                new ClinicianRepository( self::db() ),
                new ClinicRepository( self::db() )
            );
        }

        return $service;
    }

    /**
     * Facility-aware onboarding (hospital network / medical building), same
     * service the admin wizard uses. It authorizes by Organization authority and
     * is transport-agnostic, so the frontend portal can drive it without wp-admin.
     */
    public static function facility_setup_service(): \ClinicCore\Application\Onboarding\FacilitySetupService {
        static $service = null;
        if ( $service === null ) {
            $service = new \ClinicCore\Application\Onboarding\FacilitySetupService(
                self::db(),
                self::organization_clinic_provisioning_service(),
                self::locationService(),
                self::locationRepository(),
                self::authorization_service(),
                self::membership_service(),
                new MembershipRepository( self::db() ),
                self::clinicianRepository(),
                self::scheduleService(),
                self::settingsFactory(),
                self::audit()
            );
        }

        return $service;
    }

    public static function organization_clinic_provisioning_service(): \ClinicCore\Application\Onboarding\OrganizationClinicProvisioningService {
        return new \ClinicCore\Application\Onboarding\OrganizationClinicProvisioningService( self::db(), self::audit() );
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
            // Phase 17 — the queue persists; the fast wake-up is an advisory hint.
            self::$jobs = new JobQueue( self::db(), self::op(), new JobWake() );
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
     * پیکربندیِ Clinic-local **بدونِ الزام به Scope**.
     *
     * برخلاف `settings()` که در نصبِ چند-Clinic بدونِ انتخابِ صریح
     * `CLINIC_SCOPE_REQUIRED` می‌دهد، این متد برای «عملیاتِ سطح نصب» است:
     * اگر Scopeِ معتبری موجود باشد همان پیکربندی برمی‌گردد، وگرنه `null`
     * (بدونِ ساخت/حدسِ Clinic). مصرف‌کننده باید `null` را «پیکربندیِ
     * Clinic-local در این بافت خوانده نمی‌شود» تفسیر کند، نه «پیش‌فرض».
     */
    public static function clinicSettingsOrNull(): ?Settings
    {
        try {
            return self::settings();
        } catch ( ScopeRequiredException ) {
            return null;
        } catch ( \Throwable ) {
            return null;
        }
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
        // Installation-wide update operation must not require an ambient
        // ClinicScope: in a multi-Clinic install no Clinic is inferred.
        $settings = self::clinicSettingsOrNull();

        // CPMS Classic Admin UX (B) — نمونهٔ بدونِ Scope هیچ‌گاه memo نمی‌شود؛
        // وگرنه اولین فراخوانیِ سطحِ نصب، نمونهٔ Clinic-محورِ بعدی را می‌گیرد و
        // کانال/بازهٔ ذخیره‌شدهٔ همان Clinic خوانده نمی‌شود.
        if ( null === $settings ) {
            return new UpdateService(
                null,
                self::licenseService(),
                new HttpUpdateMetadataGateway( [ 'server_url' => '' ] )
            );
        }

        static $updates = null;
        if ( $updates === null ) {
            try {
                $server_url = (string) $settings->get( 'license.server_url', '' );
            } catch ( \Throwable ) {
                $server_url = '';
            }
            $updates = new UpdateService(
                $settings,
                self::licenseService(),
                new HttpUpdateMetadataGateway( [ 'server_url' => $server_url ] )
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
                // Installation-wide health must be readable on a multi-Clinic
                // install with no ambient ClinicScope. Clinic-local settings are
                // passed only when a trusted scope already exists; no Clinic is
                // ever inferred for the report.
                $health = new SystemHealthService(
                    self::db(),
                    self::clinicSettingsOrNull(),
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
     * Phase 17 — سقفِ بستهٔ Tick مسیرِ Fast Wake.
     *
     * رویدادِ `cpms_jobs_wake` یک درخواستِ Loopback کوتاه است؛ برای اینکه یک
     * انفجارِ enqueue (مثلاً ۱۰۰ Job) به‌جای انتظار تا Tick دقیقه‌ای در همان
     * مسیرِ Fast شروع شود، همین یک فراخوانی (همان `App::runTick()` با قفلِ
     * موجود) تا این سقف پردازش می‌کند. تریگرِ دوره‌ای دقیقه‌ای دست‌نخورده است
     * (سقفِ خودش = ۲۰).
     */
    public const WAKE_TICK_LIMIT = 200;

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
