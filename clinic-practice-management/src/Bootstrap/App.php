<?php

declare(strict_types=1);

namespace ClinicCore\Bootstrap;

use ClinicCore\Admin\SettingsAdmin;
use ClinicCore\Admin\DoctorDashboardPage;
use ClinicCore\Admin\DoctorHandwritingPage;
use ClinicCore\Admin\SecretaryFinancePage;
use ClinicCore\Admin\SecretaryQueuePage;
use ClinicCore\Admin\SmsSettingsPage;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Booking\ScheduleService;
use ClinicCore\Application\Clinical\ClinicalService;
use ClinicCore\Application\Clinical\MedicalFileService;
use ClinicCore\Application\Finance\FinanceService;
use ClinicCore\Application\Handwriting\HandwritingService;
use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Application\Jobs\ApptReminderHandler;
use ClinicCore\Application\Jobs\FollowUpReminderHandler;
use ClinicCore\Application\Jobs\HandwritingGcHandler;
use ClinicCore\Application\Jobs\HoldsExpireHandler;
use ClinicCore\Application\Jobs\JobsDispatcher;
use ClinicCore\Application\Jobs\IdemCleanupHandler;
use ClinicCore\Application\Jobs\NotifDispatchHandler;
use ClinicCore\Application\Jobs\OtpCleanupHandler;
use ClinicCore\Application\Jobs\RateLimitCleanupHandler;
use ClinicCore\Application\Jobs\ReportExportHandler;
use ClinicCore\Application\Jobs\SmsSendJobHandler;
use ClinicCore\Application\Jobs\SlotsGenerateHandler;
use ClinicCore\Application\Jobs\VisitsNoShowHandler;
use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Application\Reports\ExportService;
use ClinicCore\Application\Reports\ReportService;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Licensing\ActiveLicenseGate;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\CorrelationId;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\ClinicalNoteRepository;
use ClinicCore\Infrastructure\Repository\FollowUpRepository;
use ClinicCore\Infrastructure\Repository\HandwritingRepository;
use ClinicCore\Infrastructure\Repository\InvoiceRepository;
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
use ClinicCore\Infrastructure\Security\RateLimiter;
use ClinicCore\Infrastructure\Sms\CredentialVault;
use ClinicCore\Infrastructure\Sms\Providers\GenericApiSmsProvider;
use ClinicCore\Infrastructure\Sms\Providers\LogSmsProvider;
use ClinicCore\Infrastructure\Sms\SmsProviderInterface;
use ClinicCore\Infrastructure\Sms\SmsProviderRegistry;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
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
use ClinicCore\Rest\ScheduleController;
use ClinicCore\Rest\SmsController;
use ClinicCore\Settings\Settings;

/**
 * نقطه اتصال افزونه به WordPress + DI سبک (singletonهای lazy).
 *
 * اصول:
 *  - Boot در هر Request سبک است؛ Migration فقط در صورت نیاز (idempotent + Lock ساده).
 *  - Business Logic در Template ممنوع (NFR-MAINT-1).
 */
final class App
{
    private static ?CpmsDb $db = null;
    private static ?OpLogger $op = null;
    private static ?AuditLogger $audit = null;
    private static ?JobQueue $jobs = null;
    private static ?RateLimiter $rate = null;
    private static ?Idempotency $idem = null;
    private static ?Settings $settings = null;
    private static ?MigrationRunner $migrations = null;
    private static ?JobsDispatcher $dispatcher = null;
    private static ?SmsProviderRegistry $providers = null;
    private static ?CredentialVault $vault = null;
    private static ?SmsService $smsService = null;
    private static ?LicenseGate $licenseGate = null;
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        RolesAndCapabilities::register();

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

        // Correlation helperها (cpms_request_id/cpms_session_id) در فایل اصلی
        // افزونه تعریف می‌شوند — خارج از boot تا در همه Contextها (CLI، Test،
        // درخواست‌های زودهنگام) قطعاً موجود باشند.

        SettingsAdmin::register();
        SmsSettingsPage::register();
        SecretaryQueuePage::register();
        SecretaryFinancePage::register();
        DoctorDashboardPage::register();
        DoctorHandwritingPage::register();
    }

    public static function activate(): void
    {
        RolesAndCapabilities::register();
        self::db(); // lazy init برای migrate
        self::migrations()->migrate();

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
        } finally {
            delete_transient($lock);
        }
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
        $lastTick = (int) self::settings()->get('jobs.last_tick_at', 0);
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
                self::settings(),
                self::licenseGate(),
                self::audit(),
                self::op(),
                self::idem(),
                self::smsService(),
                self::notificationService()
            );
        }

        return $booking;
    }

    public static function visitService(): VisitService
    {
        static $visits = null;
        if ($visits === null) {
            $db = self::db();
            $visits = new VisitService(
                $db,
                new VisitRepository($db),
                new AppointmentRepository($db),
                self::settings(),
                self::audit(),
                self::licenseGate(),
                self::notificationService(),
                self::op()
            );
        }

        return $visits;
    }

    public static function scheduleService(): ScheduleService
    {
        static $schedule = null;
        if ($schedule === null) {
            $db = self::db();
            $schedule = new ScheduleService(
                $db,
                new ScheduleRepository($db),
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
                self::settings(),
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
                self::settings(),
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
                self::settings(),
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
        $configured = trim((string) self::settings()->get('files.storage_path', ''));

        return new LocalFileStorage($configured !== '' ? $configured : LocalFileStorage::defaultBasePath());
    }

    /**
     * سرویس گزارش (F8 — FR-19.2: ۱۲ گزارش، Scope سرور-side).
     */
    public static function reportService(): ReportService
    {
        return new ReportService(self::db(), self::settings(), self::audit());
    }

    /**
     * سرویس Export گزارش (F8 — FR-19.3: async + CSV + Audit + دانلود محافظت‌شده).
     */
    public static function exportService(): ExportService
    {
        return new ExportService(
            self::db(),
            self::reportService(),
            self::notificationService(),
            new NotificationRepository(self::db()),
            self::localFileStorage(),
            self::jobs(),
            self::settings(),
            self::audit(),
            self::op()
        );
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
        // عمداً بدون کش: مسیر ذخیره از Setting خوانده می‌شود و باید در هر
        // ساخت (Request/تست) تازه باشد — singleton مسیر اولین boot را قفل
        // می‌کرد و تغییر files.storage_path بی‌اثر می‌شد. ساخت Object سبک است.
        $configured = trim((string) self::settings()->get('files.storage_path', ''));
        $storage = new LocalFileStorage($configured !== '' ? $configured : LocalFileStorage::defaultBasePath());

        return new MedicalFileService(
            new MedicalFileRepository(self::db()),
            $storage,
            self::settings(),
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
                self::settings(),
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
                self::settings(),
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
            $registry->register(new GenericApiSmsProvider((array) self::settings()->get('sms.generic', [])));
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
     * LicenseGate (Seam — ADR-0023/C3): F3 = همیشه ACTIVE.
     * در F10 با Gate واقعی جایگزین می‌شود — Business Services تغییر نمی‌کنند.
     * **ممنوع:** Network Call به License Server در مسیر Booking (فقط خواندن وضعیت local).
     */
    public static function licenseGate(): LicenseGate
    {
        if (self::$licenseGate === null) {
            self::$licenseGate = new ActiveLicenseGate();
        }

        return self::$licenseGate;
    }

    public static function smsService(): SmsService
    {
        if (self::$smsService === null) {
            self::$smsService = new SmsService(
                self::db(),
                self::settings(),
                self::providers(),
                self::vault(),
                self::audit(),
                self::op(),
                self::jobs()
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
        if (self::$settings === null) {
            self::$settings = new Settings(self::db());
        }

        return self::$settings;
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

    public static function dispatcher(): JobsDispatcher
    {
        if (self::$dispatcher === null) {
            $queue = self::jobs();
            $db = self::db();
            $settings = self::settings();
            $op = self::op();
            $dispatcher = new JobsDispatcher($queue, $op);
            $dispatcher
                ->register('holds.expire', new HoldsExpireHandler($db))
                ->register('cleanup.otp', new OtpCleanupHandler($db))
                ->register('cleanup.rate_limits', new RateLimitCleanupHandler(self::rate()))
                ->register('cleanup.idem', new IdemCleanupHandler(self::idem()))
                ->register('slots.generate', new SlotsGenerateHandler($db, $settings, $op))
                ->register('sms.send', new SmsSendJobHandler(self::smsService()))
                ->register('visits.no_show', new VisitsNoShowHandler(self::visitService()))
                ->register('handwriting.gc', new HandwritingGcHandler(self::handwritingService()))
                ->register('notif.dispatch', new NotifDispatchHandler(self::notificationService(), self::exportService()))
                ->register('appt.reminder', new ApptReminderHandler($db, $settings, self::smsService(), self::notificationService(), $op))
                ->register('fu.reminder', new FollowUpReminderHandler($db, $settings, self::smsService(), self::notificationService(), $op))
                ->register('report.export', new ReportExportHandler(self::exportService()));

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
    private const RECURRING_JOBS = [
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
    ];

    /**
     * یک چرخه کامل Runner — مسیر واحد برای WP-Cron و `bin/cpms jobs tick`:
     * ثبت Heartbeat + زمان‌بندی مجدد (Idempotent) جاب‌های دوره‌ای + پردازش صف.
     *
     * Regression (Pilot Gate): bin/cpms قبلاً scheduleRecurringJobs را صدا
     * نمی‌زد → در استقرار system-cron (J-4) جاب‌های دوره‌ای فقط یک‌بار
     * (بعد از Activate) اجرا و بعد برای همیشه متوقف می‌شدند (FR-5.5).
     */
    public static function runTick(int $limit = 20): int
    {
        self::recordTick();
        // جاب‌های تکرارشونده را (Idempotent) دوباره زمان‌بندی کن — بدون
        // این، هر جاب فقط یک‌بار (بعد از Activate) اجرا می‌شد (FR-5.5).
        self::scheduleRecurringJobs();

        return self::dispatcher()->tick($limit);
    }

    public static function scheduleRecurringJobs(): void
    {
        $queue = self::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach (self::RECURRING_JOBS as $type => $priority) {
            $alreadyQueued = self::db()->fetchValue(
                'SELECT id FROM ' . self::db()->table('cpms_jobs') . ' WHERE type = %s AND status = %s LIMIT 1',
                [$type, \ClinicCore\Infrastructure\Queue\JobQueue::QUEUED]
            );
            if ($alreadyQueued === null) {
                $queue->enqueue($type, [], $now, priority: $priority);
            }
        }
    }
}
