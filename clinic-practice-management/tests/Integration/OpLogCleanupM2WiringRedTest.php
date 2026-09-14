<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\JobScopeClass;
use ClinicCore\Application\Jobs\JobScopeRegistry;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * M-2 cleanup.oplog — قرارداد اجرای scope-neutral برای Job سطحِ نصب.
 *
 * ثابت می‌کند wiring تولیدیِ `cleanup.oplog` (طبقهٔ ثبت‌شده: **S** =
 * installation-scoped در `JobScopeRegistry`) باید در یک context نصب‌گسترده
 * **بدونِ کاربر و بدونِ ScopeContext** بسازد و اجرا شود و همان پاک‌سازیِ
 * retention را واقعاً انجام دهد.
 *
 * عیبِ وضعیتِ جاری (main = 39e9a73):
 *   App::dispatcher() برای `cleanup.oplog` مسیر زیر را ثبت می‌کند
 *     (new OpLogCleanupHandler($db, self::settings()))($payload)
 *   و `App::settings()` تنظیمات را از Scope جاری حل می‌کند
 *     (`App::settings() → App::scope() → SystemClinicResolver::resolve()`).
 *   در نصبِ چند-Clinic بدونِ Scope صریح، `SystemClinicResolver` به‌صورت
 *   Fail-Closed `CLINIC_SCOPE_REQUIRED` می‌اندازد ⇒ Handler حتی ساخته نمی‌شود
 *   و Job با `max_attempts = 1` به status `failed` می‌رسد.
 *
 * RED موردانتظار (پیش از اصلاح): status = failed، last_error = پیامِ شکستِ
 *   Scope، tick واقعی اجرا شده ولی کارِ پاک‌سازی انجام نشده است.
 * GREEN موردانتظار (پس از اصلاح): status = success و همان‌جا ردیفِ قدیمی‌تر از
 *   retention حذف و ردیفِ تازه نگه داشته شود — بدونِ هیچ Clinic/کاربر.
 *
 * قراردادِ آیندهٔ پیکربندی (بدونِ پیاده‌سازی در این تسک):
 *   روزهای retention باید از پیکربندی **سطحِ نصب** بیاید (`InstallationSettings`
 *   روی wp_options — همان الگوی مصوبِ `notif.archive_days`)، نه از Settingsِ
 *   Clinic. این تست **هیچ** مقدارِ Clinic را نمی‌نویسد: نه ردیفِ Clinic، نه
 *   `clinic_id = 0`، نه Clinic مصنوعی و نه مقدارِ تاریخیِ هیچ Clinic. پیش‌فرضِ
 *   مصوب از روی `Settings::DEFAULTS` فقط **خوانده و ثبت** می‌شود (۹۰ روز) —
 *   بدونِ اختراعِ مقدار/سقفِ جدید.
 *
 * کشِ استاتیک / Greenِ کاذب (اهمیتِ طبقه‌بندی):
 *   `SystemClinicResolver::$cached` یک ClinicScope را در سطحِ فرآیندِ PHP
 *   کش می‌کند. در suite مشترکِ Integration، تستی که پیش‌تر روی نصبِ
 *   تک‌کلینیکی Scope حل کرده باشد، می‌تواند Clinicِ کهنه را برگرداند و
 *   وابستگیِ Scope را **پنهان** کند (Greenِ کاذب). به همین دلیل: (۱) این تست
 *   پیش از tick صریحاً `App::resetScope()`/`SystemClinicResolver::flush()`
 *   و کش‌های App را می‌بندد؛ (۲) شاهدِ معتبر فقط اجرای **متمرکز در یک فرآیندِ
 *   PHP تازه** است (مسیرِ workflow فقط-شواهد)، نه سبزیِ کلیِ suite مشترک.
 *
 * Fixture: Clinicها به‌صورت دینامیک ساخته می‌شوند (بدونِ ID ثابت، بدونِ
 * فرضِ «ردیفِ اول»). `App::runTick()` واقعی استفاده می‌شود و Job با payloadِ
 * **خالی** — دقیقاً همان کاری که `scheduleRecurringJobs()` در production
 * می‌کند.
 */
final class OpLogCleanupM2WiringRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;

    /** @var list<string> پیام‌های ردیف‌های ساخته‌شدهٔ این تست (برای پاک‌سازی) */
    private array $oplogMessages = [];

    /**
     * صفر کردنِ کش‌های سطحِ فرآیند که reset عمومی ندارند (class props).
     */
    private function resetAppCaches(): void
    {
        $refClass = new \ReflectionClass(App::class);
        foreach ([
            'db', 'op', 'audit', 'jobs', 'rate', 'loginRateLimiter', 'idem',
            'settingsFactory', 'installationSettings', 'migrations', 'dispatcher',
            'providers', 'vault', 'smsService', 'licenseGate', 'visitService',
        ] as $propName) {
            if ($refClass->hasProperty($propName)) {
                $prop = $refClass->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        if (method_exists(App::class, 'settingsFactory')) {
            try {
                App::settingsFactory()->reset();
            } catch (\Throwable) {
                // reset فقط تلاشِ بهترین-effort است
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->resetAppCaches();
        $this->buildClinics();
        $this->purgeJobs();
        $this->resetAppCaches();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        $this->purgeOperationalLogs();
        $this->purgeFixture();
        $this->resetAppCaches();
        parent::tearDown();
    }

    /**
     * دو Clinic مجزا با شناسهٔ تولیدشده توسط DB — هیچ ID ثابتی در تست نیست.
     */
    private function buildClinics(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $orgSlug = 'oplog-red-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'OpLog Red Org',
            $orgSlug,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'organizations insert must succeed');

        foreach (['A' => 'Asia/Tehran', 'B' => 'Asia/Tokyo'] as $suffix => $tz) {
            $slug = 'oplog-red-clinic-' . strtolower($suffix) . '-' . bin2hex(random_bytes(4));
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
                $this->orgId,
                'OpLog Red Clinic ' . $suffix,
                $slug,
                $tz,
                $now,
                $now
            ));
            $id = (int) $wpdb->insert_id;
            self::assertGreaterThan(1, $id, 'clinic ' . $suffix . ' must get a DB-generated id > 1 (never a fixed id)');
            if ($suffix === 'A') {
                $this->clinicA = $id;
            } else {
                $this->clinicB = $id;
            }
        }

        self::assertGreaterThan(0, $this->clinicA, 'clinic A fixture');
        self::assertGreaterThan(0, $this->clinicB, 'clinic B fixture');
        self::assertNotSame($this->clinicA, $this->clinicB, 'two distinct dynamically created clinics');

        $this->resetAppCaches();
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([$this->clinicA, $this->clinicB] as $clinicId) {
            if ($clinicId > 0) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $clinicId));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $clinicId));
            }
        }
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', $this->orgId));
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->resetAppCaches();
    }

    private function purgeJobs(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN (\'visits.no_show\',\'slots.generate\',\'holds.expire\',\'cleanup.otp\',\'cleanup.rate_limits\',\'cleanup.idem\',\'cleanup.oplog\',\'handwriting.gc\',\'notif.dispatch\',\'appt.reminder\',\'fu.reminder\',\'license.refresh\',\'backup.run\',\'report.export\',\'sms.send\')');
    }

    private function purgeOperationalLogs(): void
    {
        global $wpdb;
        $db = App::db();
        foreach ($this->oplogMessages as $message) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_operational_logs') . ' WHERE message = %s', $message));
        }
        $this->oplogMessages = [];
    }

    private function insertOpLog(string $message, string $createdAt): int
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_operational_logs') . ' (level, message, context_json, created_at) VALUES (%s, %s, NULL, %s)',
            'info',
            $message,
            $createdAt
        ));
        $id = (int) $wpdb->insert_id;
        $this->oplogMessages[] = $message;

        return $id;
    }

    private function countOpLog(string $message): int
    {
        global $wpdb;
        $db = App::db();

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_operational_logs') . ' WHERE message = %s',
            $message
        ));
    }

    /**
     * RED اصلی: اجرای واقعیِ `cleanup.oplog` از مسیر production در context
     * نصب‌گسترده (چند Clinic، بدون کاربر، بدون ScopeContext).
     */
    public function testCleanupOplogMustRunScopeNeutralInInstallationContext(): void
    {
        global $wpdb;
        $db = App::db();

        // ---------------------------------------------------------------
        // ۱) Fixture مادی: چند Clinic واقعی با شناسهٔ تولیدشده
        // ---------------------------------------------------------------
        self::assertGreaterThan(0, $this->clinicA, 'fixture clinic A must exist');
        self::assertGreaterThan(0, $this->clinicB, 'fixture clinic B must exist');
        $clinicCount = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $clinicCount, 'install must hold more than one clinic, found ' . $clinicCount);

        // ---------------------------------------------------------------
        // ۲) عدمِ کاربر و عدمِ ScopeContext
        // ---------------------------------------------------------------
        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id(), 'no current user');
        $this->resetAppCaches();
        self::assertNull(ScopeContext::tryGet(), 'no explicit ScopeContext must be set');

        // ---------------------------------------------------------------
        // ۳) قراردادِ ثبت‌شده: cleanup.oplog طبقهٔ S (سطحِ نصب) است
        // ---------------------------------------------------------------
        self::assertSame(
            JobScopeClass::SYSTEM,
            JobScopeRegistry::classFor('cleanup.oplog'),
            'cleanup.oplog must be registered as installation-scoped (S)'
        );
        self::assertFalse(
            JobScopeRegistry::requiresClinicContext('cleanup.oplog'),
            'an S job must never require Clinic context'
        );
        self::assertTrue(
            JobScopeRegistry::permitsNullClinic('cleanup.oplog'),
            'an S job must run with no Clinic'
        );

        // ---------------------------------------------------------------
        // ۴) مسیرِ واقعیِ production ثبت شده است (dispatcher با گاردِ fail-closed)
        // ---------------------------------------------------------------
        $registered = App::dispatcher()->registeredTypes();
        self::assertContains('cleanup.oplog', $registered, 'production dispatcher must register cleanup.oplog');

        // ---------------------------------------------------------------
        // ۵) امروز منبعِ پیکربندی به Clinic گره خورده است: بدونِ Scope می‌بندد
        // ---------------------------------------------------------------
        try {
            $scope = App::scope();
            self::fail(
                'App::scope() must fail closed in a multi-clinic install without explicit scope, '
                    . 'but returned clinicId=' . $scope->clinicId
            );
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode, 'fail-closed scope error code');
        }

        // ---------------------------------------------------------------
        // ۶) ثبتِ دقیقِ پیش‌فرضِ مصوب (فقط خواندن — هیچ ردیفِ Clinic نوشته نمی‌شود)
        // ---------------------------------------------------------------
        self::assertSame(
            90,
            Settings::DEFAULTS['retention.oplog_days'],
            'approved default of retention.oplog_days must be recorded exactly (90)'
        );

        // ---------------------------------------------------------------
        // ۷) Fixture مادیِ جدولِ لاگِ عملیاتی: یک ردیفِ کهنه + یک ردیفِ تازه
        // ---------------------------------------------------------------
        $tag = bin2hex(random_bytes(4));
        $oldMessage = 'oplog-red-old-' . $tag;
        $recentMessage = 'oplog-red-recent-' . $tag;
        $oldCreatedAt = gmdate('Y-m-d H:i:s', time() - 200 * 86400) . '.000';
        $recentCreatedAt = gmdate('Y-m-d H:i:s', time() - 5 * 86400) . '.000';

        $this->purgeOperationalLogs();
        self::assertSame(0, $this->countOpLog($oldMessage), 'fixture must start clean (old)');
        self::assertSame(0, $this->countOpLog($recentMessage), 'fixture must start clean (recent)');

        self::assertGreaterThan(0, $this->insertOpLog($oldMessage, $oldCreatedAt), 'old oplog row inserted');
        self::assertGreaterThan(0, $this->insertOpLog($recentMessage, $recentCreatedAt), 'recent oplog row inserted');
        self::assertSame(1, $this->countOpLog($oldMessage), 'material fixture: exactly one out-of-retention row');
        self::assertSame(1, $this->countOpLog($recentMessage), 'material fixture: exactly one in-retention row');
        self::assertLessThan(
            gmdate('Y-m-d H:i:s', time() - 90 * 86400) . '.000',
            $oldCreatedAt,
            'old row must be older than the approved 90-day retention window'
        );

        // ---------------------------------------------------------------
        // ۸) Enqueue واقعیِ همان Job (payloadِ خالی = semantics زمان‌بندِ دوره‌ای)
        // ---------------------------------------------------------------
        $queue = App::jobs();
        $nowDt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('cleanup.oplog', [], $nowDt, 1, 1);
        self::assertGreaterThan(0, $jobId, 'enqueue of cleanup.oplog must succeed');

        $jobBefore = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d',
            $jobId
        ), ARRAY_A);
        self::assertNotEmpty($jobBefore, 'enqueued job row must exist');
        self::assertSame('queued', (string) $jobBefore['status'], 'job must start queued');
        self::assertSame('[]', (string) $jobBefore['payload_json'], 'recurring semantics: empty payload');
        self::assertSame(1, (int) $jobBefore['max_attempts'], 'single attempt for a deterministic verdict');
        self::assertSame(0, (int) $jobBefore['attempts'], 'job must not have been claimed yet');

        // ---------------------------------------------------------------
        // ۹) اجرای واقعی از طریق App::runTick() — مسیر production
        // ---------------------------------------------------------------
        $tickResult = App::runTick(20);

        $jobAfter = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d',
            $jobId
        ), ARRAY_A);
        self::assertNotEmpty($jobAfter, 'job row must still exist after tick');

        $status = (string) ($jobAfter['status'] ?? '');
        $lastError = (string) ($jobAfter['last_error'] ?? '');
        $attempts = (int) ($jobAfter['attempts'] ?? 0);
        $oldRemaining = $this->countOpLog($oldMessage);
        $recentRemaining = $this->countOpLog($recentMessage);

        $evidence = 'status=' . $status
            . ' last_error=' . $lastError
            . ' attempts=' . $attempts
            . ' max_attempts=' . (int) ($jobAfter['max_attempts'] ?? 0)
            . ' tickResult=' . var_export($tickResult, true)
            . ' old_row_remaining=' . $oldRemaining
            . ' recent_row_remaining=' . $recentRemaining
            . ' clinic_count=' . $clinicCount;

        // (الف) انتسابِ شکست: خطا باید همان وابستگیِ Scope باشد — نه
        // NO_HANDLER، نه خطای fixture/DB. این ادعا هیچ markerی چاپ نمی‌کند.
        $scopeAttribution = $lastError !== ''
            && (str_contains($lastError, 'CLINIC_SCOPE_REQUIRED')
                || str_contains($lastError, 'امکان تعیین Clinic فعال'));
        self::assertTrue(
            $scopeAttribution,
            'RED_ATTRIBUTION_FAILED: job failure is not attributable to the Clinic-scope dependency '
                . '(no scope error in last_error). ' . $evidence
        );

        // (ب) Job واقعاً توسط همان tick ساخته و Claim شده است.
        self::assertSame(
            1,
            $attempts,
            'job must have been claimed exactly once by the real tick (not 0 = never claimed, not 2 = double run). '
                . $evidence
        );

        // (ج) قراردادِ هدف: Job سطحِ نصب باید بدونِ Clinic scope موفق شود.
        self::assertSame(
            'success',
            $status,
            'RED_SIGNATURE=scope_dependency :: cleanup.oplog is registered installation-scoped (S) and must '
                . 'execute without Clinic scope, but the production worker failed on current wiring '
                . '(App::settings() -> App::scope() -> CLINIC_SCOPE_REQUIRED at handler construction). '
                . $evidence
        );

        // (د) اثرِ مادی: ردیفِ قدیمی‌تر از retention حذف و ردیفِ تازه حفظ شود.
        self::assertSame(
            0,
            $oldRemaining,
            'out-of-retention operational log row must be deleted by the installation-scoped worker. ' . $evidence
        );
        self::assertSame(
            1,
            $recentRemaining,
            'in-retention operational log row must be kept. ' . $evidence
        );
    }
}
