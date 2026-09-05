<?php

declare(strict_types=1);

namespace ClinicCore\Bootstrap;

use ClinicCore\Admin\SettingsAdmin;
use ClinicCore\Admin\SmsSettingsPage;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Application\Jobs\HoldsExpireHandler;
use ClinicCore\Application\Jobs\JobsDispatcher;
use ClinicCore\Application\Jobs\OtpCleanupHandler;
use ClinicCore\Application\Jobs\RateLimitCleanupHandler;
use ClinicCore\Application\Jobs\SmsSendJobHandler;
use ClinicCore\Application\Jobs\SlotsGenerateHandler;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Licensing\ActiveLicenseGate;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\CorrelationId;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use ClinicCore\Infrastructure\Security\Idempotency;
use ClinicCore\Infrastructure\Security\RateLimiter;
use ClinicCore\Infrastructure\Sms\CredentialVault;
use ClinicCore\Infrastructure\Sms\Providers\GenericApiSmsProvider;
use ClinicCore\Infrastructure\Sms\Providers\LogSmsProvider;
use ClinicCore\Infrastructure\Sms\SmsProviderInterface;
use ClinicCore\Infrastructure\Sms\SmsProviderRegistry;
use ClinicCore\Migrations\MigrationRunner;
use ClinicCore\Rest\BookingController;
use ClinicCore\Rest\HealthController;
use ClinicCore\Rest\OtpController;
use ClinicCore\Rest\PatientController;
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
            // Endpointهای فازهای بعد (F4+) — مطابق API Contract.
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
            self::recordTick();
            self::dispatcher()->tick(20);
        });

        // Migration خودکار و ایمن (idempotent) — هنگام admin_init و rest_api_init
        add_action('admin_init', static function (): void {
            self::ensureMigrated();
        });
        add_action('rest_api_init', static function (): void {
            self::ensureMigrated();
        });

        // Correlation ID برای Trace (Audit + OpLog + هدر REST) — Baseline §26 / M10.
        // کلاینت می‌تواند X-CPMS-Correlation-Id بفرستد (فقط Whitelist کاراکتر؛ بدون
        // PHI/Credential) — در غیر این‌صورت Server-Generated.
        add_filter('init', static function (): void {
            if (!function_exists('cpms_request_id')) {
                function cpms_request_id(): ?string {
                    static $id = null;
                    if ($id === null) {
                        $header = $_SERVER['HTTP_X_CPMS_CORRELATION_ID'] ?? null;

                        $id = CorrelationId::fromHeader(is_string($header) ? $header : null);
                    }

                    return $id;
                }
            }
            if (!function_exists('cpms_session_id')) {
                function cpms_session_id(): ?string {
                    return session_id() ?: null;
                }
            }
        });

        SettingsAdmin::register();
        SmsSettingsPage::register();
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
                self::smsService()
            );
        }

        return $booking;
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
                ->register('slots.generate', new SlotsGenerateHandler($db, $settings, $op))
                ->register('sms.send', new SmsSendJobHandler(self::smsService()));

            self::$dispatcher = $dispatcher;
        }

        return self::$dispatcher;
    }

    /**
     * Schedule Jobهای دوره‌ای (بعد از activation) — V1: فوری + یادآوری روزانه.
     */
    public static function scheduleRecurringJobs(): void
    {
        $queue = self::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $queue->enqueue('holds.expire', [], $now, priority: 8);
        $queue->enqueue('slots.generate', [], $now, priority: 3);
    }
}
