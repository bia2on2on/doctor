<?php
declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * RED (TEST-ONLY / evidence-only) — `notif.dispatch` must be executable by a
 * scope-neutral installation-wide (W) worker.
 *
 * Contract under test (narrow, one line):
 *   A worker with **no** current WP user, **no** REST/request Clinic context and
 *   **no** ScopeContext must be able to run the registered production job
 *   `notif.dispatch` end-to-end through the real worker path
 *   (App::runTick → App::dispatcher() → the production registration closure).
 *
 * Current production wiring (main @ 0347ea08a2972c03ddfc333e68269865670950a6):
 *   App::dispatcher() registers the type as
 *     (new NotifDispatchHandler(App::notificationService(), App::exportService()))($payload)
 *   and App::notificationService() eagerly builds its service with
 *   App::settings() → App::scope() → SystemClinicResolver::resolve()
 *   ⇒ in a multi-Clinic install without request/user Scope this throws
 *   CLINIC_SCOPE_REQUIRED **before** any dispatch work happens.
 *
 * Expected RED on current main: job status = failed (fail-fast, maxAttempts=1),
 *   last_error = the fail-closed scope message, attempts = 1.
 * Post-GREEN (a separate future slice — NOT this task): job status = success.
 *
 * Scope discipline of this RED:
 *   - no product change and no product-policy decision is made here;
 *   - purge/retention (`notif.archive_days`) semantics are deliberately NOT
 *     asserted: installation-wide vs per-Clinic retention is unresolved and is
 *     reported as an architecture constraint instead of invented semantics;
 *   - no clinic_id=1, no clinic_id=0, no fake System Clinic, no first-row
 *     assumption, no payload→ScopeContext binding, no current user as tenant.
 *
 * False-GREEN guard (why the extra probe below exists):
 *   App::notificationService() memoizes its instance in a **function-level
 *   static** that no test can reset. A warm instance — built earlier in the same
 *   PHP process while a Clinic scope existed — would let the production closure
 *   construct the handler and silently mask the defect (the job would then even
 *   "succeed"). This RED therefore (a) proves the cache is cold before the tick
 *   (the probe throws before any assignment, so it neither warms the cache nor
 *   changes the path under test), and (b) only accepts a **scope-attributed**
 *   failure as valid RED (`RED_SIGNATURE=scope_dependency`).
 */
final class NotifDispatchM2WiringRedTest extends WP_UnitTestCase
{
    /** شناسه‌های واقعیِ fixture — پویا (AUTO_INCREMENT) و بدون شناسهٔ ثابت. */
    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;

    private function resetAppCaches(): void
    {
        $refClass = new \ReflectionClass(App::class);
        foreach ([
            'db', 'op', 'audit', 'jobs', 'rate', 'loginRateLimiter', 'idem',
            'settingsFactory', 'migrations', 'dispatcher', 'providers', 'vault',
            'smsService', 'licenseGate', 'visitService',
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
            } catch (\Throwable $e) {
                // factory ساختنی نیست — کش داده‌ای ندارد
            }
        }
    }

    private function assertDispatcherCacheFresh(): void
    {
        $refClass = new \ReflectionClass(App::class);
        if ($refClass->hasProperty('dispatcher')) {
            $prop = $refClass->getProperty('dispatcher');
            $prop->setAccessible(true);
            self::assertNull($prop->getValue(), 'App::$dispatcher cache must be null/fresh before the tick');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->resetAppCaches();
        $this->buildFixture();
        $this->purgeJobs();
        $this->resetAppCaches();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        $this->purgeFixture();
        $this->resetAppCaches();
        parent::tearDown();
    }

    /**
     * حداقلِ fixture مادی: یک سازمان + دو Clinic واقعیِ مجزا با شناسهٔ پویا.
     */
    private function buildFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'ND Wiring Org',
            'nd-wiring-org-' . bin2hex(random_bytes(4)),
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'fixture: organization must be inserted (material)');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'ND Wiring Clinic A',
            'nd-wiring-clinic-a-' . bin2hex(random_bytes(4)),
            'Europe/Berlin',
            $now,
            $now
        ));
        $this->clinicA = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicA, 'fixture: Clinic A must be inserted (material)');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'ND Wiring Clinic B',
            'nd-wiring-clinic-b-' . bin2hex(random_bytes(4)),
            'Asia/Tokyo',
            $now,
            $now
        ));
        $this->clinicB = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicB, 'fixture: Clinic B must be inserted (material)');

        self::assertNotSame($this->clinicA, $this->clinicB, 'fixture: two distinct Clinics');
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();

        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([$this->clinicA, $this->clinicB] as $clinicId) {
            if ($clinicId <= 0) {
                continue;
            }
            foreach ([
                'cpms_notifications', 'cpms_sms_messages', 'cpms_settings',
                'cpms_clinicians', 'cpms_locations', 'cpms_clinic_memberships',
            ] as $table) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table($table) . ' WHERE clinic_id = %d', $clinicId));
            }
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $clinicId));
        }
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', $this->orgId));
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
    }

    /**
     * جدول Job فقط برای همین تست خالی می‌شود تا Tick قطعی و قابل‌انتساب بماند.
     */
    private function purgeJobs(): void
    {
        global $wpdb;
        $wpdb->query('DELETE FROM ' . App::db()->table('cpms_jobs'));
    }

    /**
     * همهٔ جاب‌های تکرارشونده **به‌جز** notif.dispatch با run_after آینده enqueue
     * می‌شوند (قابلیت عادی صف: جاب تأخیری). دلیل: `App::runTick()` در ابتدای هر
     * Tick جاب‌های تکرارشونده را زمان‌بندی می‌کند؛ این کار Tick را روی همان Jobِ
     * مورد بررسی محدود نگه می‌دارد و Evidence را از شکست‌های نامرتبط پاک می‌کند.
     */
    private function neutralizeOtherRecurringJobs(): int
    {
        $queue = App::jobs();
        $later = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 hour');
        $enqueued = 0;
        foreach (array_keys(App::RECURRING_JOBS) as $type) {
            if ($type === 'notif.dispatch') {
                continue;
            }
            $id = $queue->enqueue($type, [], $later, 1, 1);
            self::assertGreaterThan(0, $id, 'delayed recurring fixture must be enqueued: ' . $type);
            $enqueued++;
        }

        return $enqueued;
    }

    public function testScopeNeutralWorkerMustBeAbleToExecuteNotifDispatch(): void
    {
        global $wpdb;
        $db = App::db();

        // ---------- 1) fixture مادی ----------
        self::assertGreaterThan(0, $this->clinicA, 'material fixture: Clinic A');
        self::assertGreaterThan(0, $this->clinicB, 'material fixture: Clinic B');
        $clinicCount = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $clinicCount, 'multi-Clinic precondition: total clinics=' . $clinicCount);

        // ---------- 2) زمینهٔ Worker: بدون Scope و بدون کاربر ----------
        $this->resetAppCaches();
        self::assertNull(ScopeContext::tryGet(), 'no ScopeContext must survive into the worker');
        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id(), 'no current WP user');
        $this->assertDispatcherCacheFresh();

        // ---------- 3) پیش‌شرط Fail-Closed: Scope ضمنی در دسترس نیست ----------
        try {
            $scope = App::scope();
            self::fail(
                'App::scope() must fail closed with CLINIC_SCOPE_REQUIRED in a multi-Clinic no-Scope worker, got clinicId='
                . $scope->clinicId
            );
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode, 'scope resolution must fail closed');
        }

        // ---------- 4) گارد ضدِ Greenِ کاذب: کشِ App::notificationService سرد باشد ----------
        // اگر این فراخوانی نمونه برگرداند، یعنی یک نمونهٔ گرم (ساخته‌شده با Scope
        // یک Clinic دیگر در همین فرآیند) وجود دارد و این اجرا **قابل استناد نیست**.
        try {
            $warm = App::notificationService();
            self::fail(
                'precondition (invalid evidence, class D): App::notificationService() returned an instance instead of failing closed; '
                . 'the function-static cache was already warm in this PHP process, which would mask the wiring defect. class='
                . get_class($warm)
            );
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode, 'cold-cache probe must fail closed');
        }

        // ---------- 5) Enqueue با معناشناسی واقعیِ صف ----------
        $this->purgeJobs();
        $neutralized = $this->neutralizeOtherRecurringJobs();
        self::assertSame(
            count(App::RECURRING_JOBS) - 1,
            $neutralized,
            'every recurring type except notif.dispatch must be neutralized (delayed)'
        );

        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('notif.dispatch', [], $now, App::RECURRING_JOBS['notif.dispatch'], 1);
        self::assertGreaterThan(0, $jobId, 'notif.dispatch enqueue must succeed');

        $jobBefore = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId),
            ARRAY_A
        );
        self::assertNotEmpty($jobBefore, 'job row must exist after enqueue');
        self::assertSame('queued', (string) $jobBefore['status'], 'job must be queued before the tick');
        self::assertSame(1, (int) $jobBefore['max_attempts'], 'fail-fast: max_attempts=1 so the outcome is terminal');

        // ---------- 6) مسیر واقعی Worker ----------
        $tickResult = App::runTick(1);

        $jobAfter = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId),
            ARRAY_A
        );
        self::assertNotEmpty($jobAfter, 'job row must still exist after the tick');

        $status = (string) ($jobAfter['status'] ?? '');
        $lastError = (string) ($jobAfter['last_error'] ?? '');
        $attempts = (int) ($jobAfter['attempts'] ?? 0);
        $startedAt = (string) ($jobAfter['started_at'] ?? '');

        // ---------- 7) معیارهای اعتبارِ RED ----------
        self::assertGreaterThanOrEqual(
            1,
            $attempts,
            'valid RED requires the job to be claimed by App::runTick (attempts>=1); status=' . $status
        );
        self::assertNotSame('', $startedAt, 'valid RED requires the job to be started by the worker; status=' . $status);
        self::assertStringNotContainsString('NO_HANDLER', $lastError, 'valid RED must not be NO_HANDLER');
        self::assertStringNotContainsString('SQLSTATE', $lastError, 'valid RED must not be a DB-layer failure');

        $pathReached = $attempts >= 1 && $startedAt !== '';
        $scopeAttributed = $status === 'failed'
            && str_contains($lastError, 'Clinic')
            && str_contains($lastError, 'Scope');
        $marker = !$pathReached
            ? 'RED_SIGNATURE=production_path_not_reached'
            : ($scopeAttributed ? 'RED_SIGNATURE=scope_dependency' : 'RED_SIGNATURE=unattributed');

        // ---------- 8) قراردادِ مورد بررسی ----------
        self::assertSame(
            'success',
            $status,
            'INTENDED CONTRACT: a scope-neutral W worker must execute notif.dispatch without request/user Clinic scope. '
            . $marker
            . ' status=' . $status
            . ' last_error=' . $lastError
            . ' attempts=' . $attempts
            . ' tickResult=' . var_export($tickResult, true)
            . ' clinics=' . $clinicCount
        );
    }
}
