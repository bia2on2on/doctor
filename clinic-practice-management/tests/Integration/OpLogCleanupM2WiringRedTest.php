<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\JobScopeClass;
use ClinicCore\Application\Jobs\JobScopeRegistry;
use ClinicCore\Application\Jobs\OpLogCleanupHandler;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\InstallationSettings;
use WP_UnitTestCase;

/**
 * M-2 cleanup.oplog — قرارداد اجرای scope-neutral + حذفِ کران‌دار.
 *
 * این تست هم‌زمان دو چیز را قفل می‌کند:
 *   ۱) Jobِ سطحِ نصب (`cleanup.oplog` = طبقهٔ **S** در `JobScopeRegistry`)
 *      باید در یک context نصب‌گسترده — **بدونِ کاربر و بدونِ ScopeContext** و
 *      بدونِ Clinic ثابت/مصنوعی — از مسیر واقعیِ `App::runTick()` اجرا شود و
 *      پاک‌سازی را انجام دهد.
 *   ۲) هر اجرا باید حداکثر `OpLogCleanupHandler::DELETE_BATCH_SIZE` ردیفِ
 *      واجدِ شرایط حذف کند و اجرای بعدیِ همان Job پاک‌سازی را ادامه دهد
 *      (بدونِ cursor/OFFSET).
 *
 * تاریخچه: نسخهٔ RED این تست پیش از اصلاح در main بازتولید شد —
 *   `status=failed` با خطای `CLINIC_SCOPE_REQUIRED` از
 *   `App::settings() → App::scope()` در زمانِ ساختِ Handler
 *   (شاهد در PR #41؛ اجرای متمرکز 34870085607 و CI 34870085507).
 * پس از اصلاح (M-2 GREEN) انتظار: `status=success`، حذفِ ردیفِ کهنه،
 * نگه‌داشتنِ ردیفِ تازه و کرانِ حذف.
 *
 * منبعِ پیکربندی (مسیر مصوب): `InstallationSettings::getOplogRetentionDays()`
 * روی wp_options (`cpms_retention_oplog_days`، autoload=no) — هیچ ردیفِ
 * Clinic نوشته نمی‌شود، نه `clinic_id = 0`، نه Clinic مصنوعی، نه مقدارِ
 * تاریخیِ هیچ Clinic. این تست Option را در setUp حذف می‌کند تا پیش‌فرضِ مؤثر
 * (۹۰ روز) صریح و قطعی باشد.
 *
 * کشِ استاتیک / Greenِ کاذب: `SystemClinicResolver::$cached` و کش‌های App در
 * سطحِ فرآیندِ PHP باقی می‌مانند؛ در suite مشترک، Clinicِ کش‌شدهٔ یک تستِ
 * تک‌کلینیکی می‌تواند وابستگیِ Scope را پنهان کند. به همین دلیل تست خودش
 * کش‌ها را می‌بندد و شاهدِ معتبر همان اجرای **متمرکز در فرآیند تازه** است
 * (workflow فقط-شواهد).
 *
 * Fixture: Clinicها و ردیف‌های لاگ به‌صورت دینامیک ساخته می‌شوند (بدونِ ID
 * ثابت، بدونِ فرضِ «ردیفِ اول») و Job با payloadِ خالی — دقیقاً کاری که
 * `scheduleRecurringJobs()` در production می‌کند.
 */
final class OpLogCleanupM2WiringRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;

    /** @var list<string> پیام‌های دقیقِ ردیف‌های ساخته‌شدهٔ این تست */
    private array $oplogMessages = [];

    /** @var list<string> پیشوندهای پیامِ دسته‌های ساخته‌شده (برای پاک‌سازی) */
    private array $oplogPrefixes = [];

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
        \ClinicCore\Settings\Settings::flushCache();
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
        delete_option(InstallationSettings::OPTION_OPLOG_RETENTION_DAYS);
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
        delete_option(InstallationSettings::OPTION_OPLOG_RETENTION_DAYS);
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
        foreach ($this->oplogPrefixes as $prefix) {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . $db->table('cpms_operational_logs') . ' WHERE message LIKE %s',
                $wpdb->esc_like($prefix) . '%'
            ));
        }
        $this->oplogMessages = [];
        $this->oplogPrefixes = [];
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

    /**
     * درجِ دسته‌ایِ ردیف‌های کهنه با یک پیشوندِ یکتا (چند تا Insert، هر کدام
     * چند ردیف) — تعداد درج‌شده برگردانده و بیرون assert می‌شود.
     */
    private function insertOpLogBatch(string $prefix, int $count, string $createdAt): int
    {
        global $wpdb;
        $db = App::db();
        $table = $db->table('cpms_operational_logs');
        $inserted = 0;

        foreach (array_chunk(range(1, $count), 50) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $i) {
                $values[] = '(%s, %s, NULL, %s)';
                $params[] = 'info';
                $params[] = $prefix . $i;
                $params[] = $createdAt;
            }
            $ok = $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'INSERT INTO ' . $table . ' (level, message, context_json, created_at) VALUES ' . implode(', ', $values),
                ...$params
            ));
            self::assertNotFalse($ok, 'bulk oplog insert chunk must succeed');
            $inserted += count($chunk);
        }

        $this->oplogPrefixes[] = $prefix;

        return $inserted;
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

    private function countOpLogByPrefix(string $prefix): int
    {
        global $wpdb;
        $db = App::db();

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_operational_logs') . ' WHERE message LIKE %s',
            $wpdb->esc_like($prefix) . '%'
        ));
    }

    /** تعداد کلِ ردیف‌های واجدِ شرایط (قدیمی‌تر از پنجرهٔ retention فعلی). */
    private function countEligibleOpLogs(int $days = 90): int
    {
        global $wpdb;
        $db = App::db();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400) . '.000';

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_operational_logs') . ' WHERE created_at < %s',
            $cutoff
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function enqueueCleanupJob(): int
    {
        $queue = App::jobs();
        $nowDt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $queue->enqueue('cleanup.oplog', [], $nowDt, 1, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function jobRow(int $jobId): array
    {
        global $wpdb;
        $db = App::db();

        return (array) $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d',
            $jobId
        ), ARRAY_A);
    }

    /**
     * قرارداد کامل: scope-neutral بودن + پاک‌سازی + کرانِ حذف + ادامهٔ تکرارشونده.
     */
    public function testCleanupOplogMustRunScopeNeutralWithBoundedDeletion(): void
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
        // ۵) پیش‌شرط: نصبِ چند-Clinic بدونِ Scope باید Fail-Closed بماند
        //    (این قرارداد پس از اصلاح هم باید برقرار باشد؛ Job نباید به آن تکیه کند)
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
        // ۶) منبعِ پیکربندی: سطح نصب، بدونِ Clinic؛ پیش‌فرض مؤثر = ۹۰
        // ---------------------------------------------------------------
        self::assertFalse(
            get_option(InstallationSettings::OPTION_OPLOG_RETENTION_DAYS, false),
            'precondition: installation option must be absent (defaults apply)'
        );
        self::assertSame(
            90,
            App::installationSettings()->getOplogRetentionDays(),
            'approved effective default of retention.oplog_days must stay 90'
        );

        // ---------------------------------------------------------------
        // ۷) Fixture مادیِ لاگ: یک ردیفِ کهنه + یک ردیفِ تازه
        // ---------------------------------------------------------------
        $tag = bin2hex(random_bytes(4));
        $oldMessage = 'oplog-red-old-' . $tag;
        $recentMessage = 'oplog-red-recent-' . $tag;
        $oldCreatedAt = gmdate('Y-m-d H:i:s', time() - 200 * 86400) . '.000';
        $recentCreatedAt = gmdate('Y-m-d H:i:s', time() - 5 * 86400) . '.000';

        self::assertGreaterThan(0, $this->insertOpLog($oldMessage, $oldCreatedAt), 'old oplog row inserted');
        self::assertGreaterThan(0, $this->insertOpLog($recentMessage, $recentCreatedAt), 'recent oplog row inserted');
        self::assertSame(1, $this->countOpLog($oldMessage), 'material fixture: exactly one out-of-retention row');
        self::assertSame(1, $this->countOpLog($recentMessage), 'material fixture: exactly one in-retention row');

        // ---------------------------------------------------------------
        // ۸) Enqueue واقعی + اجرا از مسیر App::runTick()
        // ---------------------------------------------------------------
        $jobId = $this->enqueueCleanupJob();
        self::assertGreaterThan(0, $jobId, 'enqueue of cleanup.oplog must succeed');
        self::assertSame('queued', (string) $this->jobRow($jobId)['status'], 'job must start queued');

        $tickOne = App::runTick(20);
        $jobOne = $this->jobRow($jobId);
        self::assertSame(1, (int) $jobOne['attempts'], 'job #1 must be claimed exactly once. tickResult=' . var_export($tickOne, true));

        $statusOne = (string) $jobOne['status'];
        $oldRemaining = $this->countOpLog($oldMessage);
        self::assertSame(
            'success',
            $statusOne,
            'RED_SIGNATURE=scope_dependency :: cleanup.oplog (installation-scoped S) must execute without Clinic scope. '
                . 'status=' . $statusOne
                . ' last_error=' . (string) $jobOne['last_error']
                . ' attempts=' . (int) $jobOne['attempts']
                . ' tickResult=' . var_export($tickOne, true)
                . ' clinic_count=' . $clinicCount
                . ' old_row_remaining=' . $oldRemaining
                . ' recent_row_remaining=' . $this->countOpLog($recentMessage)
        );
        self::assertSame(0, $oldRemaining, 'out-of-retention row must be deleted by the installation-scoped worker');
        self::assertSame(1, $this->countOpLog($recentMessage), 'in-retention row must be kept');

        // ---------------------------------------------------------------
        // ۹) کرانِ حذف: بیش از سقف ردیفِ واجدِ شرایط → اجرای بعدی ادامه می‌دهد
        // ---------------------------------------------------------------
        $batch = OpLogCleanupHandler::DELETE_BATCH_SIZE;
        self::assertGreaterThan(0, $batch, 'delete batch must be a positive constant');

        $bulk = $batch + 2;
        $bulkPrefix = 'oplog-red-bulk-' . $tag . '-';
        $bulkCreatedAt = gmdate('Y-m-d H:i:s', time() - 2000 * 86400) . '.000';

        self::assertSame($bulk, $this->insertOpLogBatch($bulkPrefix, $bulk, $bulkCreatedAt), 'bulk fixture must be inserted');
        self::assertSame($bulk, $this->countOpLogByPrefix($bulkPrefix), 'material fixture: bulk eligible rows present');
        $eligibleBefore = $this->countEligibleOpLogs();
        self::assertGreaterThan($batch, $eligibleBefore, 'eligible rows must exceed the batch bound');
        self::assertSame(
            $bulk,
            $eligibleBefore,
            'precondition: only our bulk rows are eligible (deterministic continuation fixture, no foreign old rows)'
        );

        $jobIdTwo = $this->enqueueCleanupJob();
        self::assertGreaterThan(0, $jobIdTwo, 'second enqueue must succeed');
        $tickTwo = App::runTick(20);
        $jobTwo = $this->jobRow($jobIdTwo);
        self::assertSame('success', (string) $jobTwo['status'], 'second bounded invocation must succeed. status=' . (string) $jobTwo['status'] . ' last_error=' . (string) $jobTwo['last_error']);
        self::assertSame(1, (int) $jobTwo['attempts'], 'second invocation claimed exactly once. tickResult=' . var_export($tickTwo, true));

        $oursAfterFirst = $this->countOpLogByPrefix($bulkPrefix);
        $eligibleAfter = $this->countEligibleOpLogs();
        $deletedThisRun = $eligibleBefore - $eligibleAfter;

        self::assertGreaterThanOrEqual(1, $oursAfterFirst, 'at least one eligible old row must remain after one bounded invocation');
        self::assertLessThanOrEqual($batch, $deletedThisRun, 'deleted rows per invocation must not exceed the batch bound');
        self::assertGreaterThan(0, $deletedThisRun, 'a bounded invocation must delete at least one eligible row');
        self::assertSame($batch, $deletedThisRun, 'one invocation must delete exactly the batch bound when more rows are eligible');
        self::assertSame($bulk - $batch, $oursAfterFirst, 'the remainder of the eligible fixture must stay for the next recurring run');
        self::assertSame(1, $this->countOpLog($recentMessage), 'recent row must survive the bounded invocation');

        // ---------------------------------------------------------------
        // ۱۰) ادامه به‌صورت تکرارشونده (بدونِ cursor/OFFSET): اجرای بعدی بقیه را می‌برد
        // ---------------------------------------------------------------
        $jobIdThree = $this->enqueueCleanupJob();
        self::assertGreaterThan(0, $jobIdThree, 'third enqueue must succeed');
        $tickThree = App::runTick(20);
        $jobThree = $this->jobRow($jobIdThree);
        self::assertSame('success', (string) $jobThree['status'], 'continuation invocation must succeed. last_error=' . (string) $jobThree['last_error']);

        $oursAfterSecond = $this->countOpLogByPrefix($bulkPrefix);
        self::assertLessThan($oursAfterFirst, $oursAfterSecond, 'a recurring invocation must make progress on the remaining eligible rows');
        self::assertSame(0, $oursAfterSecond, 'recurring invocations must continue the bounded cleanup (no cursor needed)');
        self::assertSame(1, $this->countOpLog($recentMessage), 'recent row must still survive');

        // شاهدِ ماشین‌خوان برای workflow فقط-شواهد (فرآیند تازه)
        fwrite(
            STDOUT,
            "\nGREEN_EVIDENCE cleanup.oplog"
            . ' status=success'
            . ' attempts=1'
            . ' tickResult=' . var_export($tickOne, true)
            . ' clinic_count=' . $clinicCount
            . ' user_id=' . get_current_user_id()
            . ' scope_context=none'
            . ' batch=' . $batch
            . ' ours_before=' . $bulk
            . ' ours_deleted_first=' . ($bulk - $oursAfterFirst)
            . ' ours_after_first=' . $oursAfterFirst
            . ' eligible_deleted_first=' . $deletedThisRun
            . ' ours_after_second=' . $oursAfterSecond
            . ' recent_remaining=1'
            . "\n"
        );
    }
}
