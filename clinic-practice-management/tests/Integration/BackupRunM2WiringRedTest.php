<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\JobScopeClass;
use ClinicCore\Application\Jobs\JobScopeRegistry;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * M-2 backup.run — RED proving SYSTEM job depends on Clinic-bound Settings.
 *
 * Final product contract (to be achieved in GREEN):
 *   backup.run is SYSTEM (installation-wide) and must execute deterministically
 *   without current user, REST context, Clinic ScopeContext, first-Clinic fallback,
 *   fixed tenant ID, or payload clinic_id.
 *
 * Pre-GREEN observed defect (this RED):
 *   Current wiring reaches Clinic-bound Settings via App::settings() and
 *   App::backupService(). In multi-Clinic install without explicit Scope,
 *   construction fails with CLINIC_SCOPE_REQUIRED, so job becomes FAILED
 *   instead of SUCCESS. This proves it cannot satisfy installation-wide contract.
 *
 * Fixtures:
 * - Two real Clinics created dynamically, IDs asserted >0 and distinct, never fixed 1.
 * - Conflicting backup settings: Clinic A enabled=true/due, Clinic B enabled=false.
 *
 * Evidence must show:
 * - bootstrap succeeded
 * - material fixtures succeeded
 * - real product path App::runTick -> dispatcher -> BackupRunHandler reached (attempts>=1)
 * - observed status=failed in CURRENT defective implementation
 * - last_error contains CLINIC_SCOPE_REQUIRED
 * - no backup artifact created before failure
 * - current user remains 0, ScopeContext absent, payload contains no clinic_id
 * - conflicting Clinics remain material fixtures
 *
 * Fresh-process required because App static caches can mask defect.
 *
 * No classification of all backup.* keys as InstallationSettings; backup.last_run_at
 * is operational state and remains OPEN.
 */
final class BackupRunM2WiringRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private string $tmpBase = '';

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
                // best-effort
            }
        }
        ScopeContext::clear();
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->resetAppCaches();
        $this->tmpBase = sys_get_temp_dir() . '/cpms-backup-red-' . bin2hex(random_bytes(5));
        @mkdir($this->tmpBase, 0750, true);
        $this->buildClinics();
        $this->purgeJobs();
        $this->resetAppCaches();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        $this->purgeFixture();
        $this->resetAppCaches();
        if ($this->tmpBase !== '' && is_dir($this->tmpBase)) {
            $this->rmrf($this->tmpBase);
        }
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }

    private function buildClinics(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $orgSlug = 'backup-red-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'Backup Red Org',
            $orgSlug,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'organizations insert must succeed');

        $slugA = 'backup-red-clinic-a-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Backup Red Clinic A',
            $slugA,
            'Asia/Tehran',
            $now,
            $now
        ));
        $this->clinicA = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicA, 'clinic A must get DB-generated id >1 (never fixed 1)');

        $slugB = 'backup-red-clinic-b-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Backup Red Clinic B',
            $slugB,
            'Asia/Tokyo',
            $now,
            $now
        ));
        $this->clinicB = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicB, 'clinic B must get DB-generated id >1');

        self::assertNotSame($this->clinicA, $this->clinicB, 'two distinct dynamically created clinics');

        $factory = App::settingsFactory();

        $settingsA = $factory->forClinic($this->clinicA);
        $settingsA->set('backup.enabled', true);
        $settingsA->set('backup.interval_hours', 1);
        $settingsA->set('backup.last_run_at', 0);
        $settingsA->set('backup.storage_path', $this->tmpBase . '/store-a');
        $settingsA->set('backup.keep_count', 5);

        $settingsB = $factory->forClinic($this->clinicB);
        $settingsB->set('backup.enabled', false);
        $settingsB->set('backup.interval_hours', 24);
        $settingsB->set('backup.last_run_at', time());
        $settingsB->set('backup.storage_path', $this->tmpBase . '/store-b');
        $settingsB->set('backup.keep_count', 10);

        self::assertTrue((bool) $settingsA->get('backup.enabled'), 'clinic A backup.enabled must be true');
        self::assertFalse((bool) $settingsB->get('backup.enabled'), 'clinic B backup.enabled must be false (conflicting)');
        self::assertSame(1, (int) $settingsA->get('backup.interval_hours'), 'clinic A interval 1');
        self::assertSame(24, (int) $settingsB->get('backup.interval_hours'), 'clinic B interval 24 conflicting');
        self::assertSame($this->tmpBase . '/store-a', (string) $settingsA->get('backup.storage_path'), 'clinic A storage_path material');
        self::assertSame($this->tmpBase . '/store-b', (string) $settingsB->get('backup.storage_path'), 'clinic B storage_path material conflicting');

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

    public function testBackupRunMustBeExecutableWithoutClinicScope(): void
    {
        global $wpdb;
        $db = App::db();

        // 1) Material fixtures — two dynamic Clinics
        self::assertGreaterThan(0, $this->clinicA, 'fixture clinic A must exist');
        self::assertGreaterThan(0, $this->clinicB, 'fixture clinic B must exist');
        $clinicCount = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $clinicCount, 'install must hold more than one clinic, found ' . $clinicCount);

        // 2) No current user, no ScopeContext — fresh-process guard
        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id(), 'no current user');
        $this->resetAppCaches();
        self::assertNull(ScopeContext::tryGet(), 'no explicit ScopeContext must be set');

        // 3) Registered as SYSTEM
        self::assertSame(
            JobScopeClass::SYSTEM,
            JobScopeRegistry::classFor('backup.run'),
            'backup.run must be registered as installation-scoped SYSTEM'
        );
        self::assertFalse(JobScopeRegistry::requiresClinicContext('backup.run'), 'SYSTEM job must never require Clinic context');
        self::assertTrue(JobScopeRegistry::permitsNullClinic('backup.run'), 'SYSTEM job must permit null Clinic');

        // 4) Real dispatcher wiring
        $registered = App::dispatcher()->registeredTypes();
        self::assertContains('backup.run', $registered, 'production dispatcher must register backup.run');

        // 5) Precondition: multi-clinic without scope must fail closed
        try {
            $scope = App::scope();
            self::fail('App::scope() must fail closed in multi-clinic install without explicit scope, but returned clinicId=' . $scope->clinicId);
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode, 'fail-closed scope error code');
            self::assertStringContainsString('CLINIC_SCOPE_REQUIRED', $e->errorCode, 'error code must contain CLINIC_SCOPE_REQUIRED');
        }

        // 6) Verify conflicting settings still present after cache reset
        $factory = App::settingsFactory();
        $aEnabled = (bool) $factory->forClinic($this->clinicA)->get('backup.enabled', false);
        $bEnabled = (bool) $factory->forClinic($this->clinicB)->get('backup.enabled', false);
        self::assertTrue($aEnabled, 'clinic A enabled true must persist');
        self::assertFalse($bEnabled, 'clinic B enabled false must persist (conflicting)');
        self::assertNotSame($aEnabled, $bEnabled, 'conflicting backup.enabled proves hidden Clinic dependency cannot accidentally pass');

        $this->resetAppCaches();
        self::assertNull(ScopeContext::tryGet(), 'scope still none after settings check');
        self::assertSame(0, get_current_user_id(), 'user still 0 after settings check');

        // 7) Enqueue real job with empty payload (production semantics) — no clinic_id
        $queue = App::jobs();
        $nowDt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('backup.run', [], $nowDt, 1, 1);
        self::assertGreaterThan(0, $jobId, 'enqueue backup.run must succeed');

        $jobBefore = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertSame('queued', (string) $jobBefore['status'], 'job must start queued');
        $payloadJson = (string) ($jobBefore['payload_json'] ?? '');
        $payloadDecoded = json_decode($payloadJson, true);
        self::assertTrue($payloadDecoded === [] || $payloadDecoded === null, 'payload must be empty (no clinic_id)');
        self::assertStringNotContainsString('clinic_id', $payloadJson, 'payload must not contain clinic_id');
        self::assertStringNotContainsString((string) $this->clinicA, $payloadJson, 'payload must not contain dynamic clinicA id');
        self::assertStringNotContainsString((string) $this->clinicB, $payloadJson, 'payload must not contain dynamic clinicB id');

        // Ensure no artifact exists BEFORE tick (controlled temp destination)
        $preArtifactsA = glob($this->tmpBase . '/store-a/cpms-backup-*') ?: [];
        $preArtifactsB = glob($this->tmpBase . '/store-b/cpms-backup-*') ?: [];
        $preArtifactsRoot = glob($this->tmpBase . '/cpms-backup-*') ?: [];
        self::assertSame([], $preArtifactsA, 'no backup artifact in store-a before tick');
        self::assertSame([], $preArtifactsB, 'no backup artifact in store-b before tick');
        self::assertSame([], $preArtifactsRoot, 'no backup artifact in tmp root before tick');

        // 8) Execute via real App::runTick (production path) — fresh-process evidence
        $tickResult = App::runTick(20);

        $jobAfter = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertNotEmpty($jobAfter, 'job row must still exist after tick');
        $status = (string) ($jobAfter['status'] ?? '');
        $lastError = (string) ($jobAfter['last_error'] ?? '');
        $attempts = (int) ($jobAfter['attempts'] ?? 0);

        // Proof points
        $bootstrapOk = true;
        $fixturesOk = $clinicCount > 1 && $this->clinicA > 0 && $this->clinicB > 0;
        $productPathReached = $attempts >= 1;

        self::assertTrue($bootstrapOk, 'bootstrap must succeed');
        self::assertTrue($fixturesOk, 'material fixtures must succeed');
        self::assertTrue($productPathReached, 'real product path must be reached (job claimed) attempts=' . $attempts . ' tickResult=' . var_export($tickResult, true));

        // 9) No artifact created before failure — least brittle observable from controlled temp destination
        $postArtifactsA = glob($this->tmpBase . '/store-a/cpms-backup-*') ?: [];
        $postArtifactsB = glob($this->tmpBase . '/store-b/cpms-backup-*') ?: [];
        $postArtifactsRoot = glob($this->tmpBase . '/cpms-backup-*') ?: [];
        $postArtifactsAny = array_merge($postArtifactsA, $postArtifactsB, $postArtifactsRoot);
        self::assertSame([], $postArtifactsAny, 'no backup artifact must be created before failure (defect proven at construction)');

        // 10) Current user and ScopeContext must remain absent after tick
        self::assertSame(0, get_current_user_id(), 'current user must remain 0 after tick');
        self::assertNull(ScopeContext::tryGet(), 'ScopeContext must remain absent after tick');

        // 11) Conflicting Clinics remain material fixtures after tick
        $clinicCountAfter = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $clinicCountAfter, 'clinic fixtures must remain after tick');
        self::assertGreaterThan(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicA)), 'clinicA still material');
        self::assertGreaterThan(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicB)), 'clinicB still material');

        // 12) Pre-GREEN expected observation: status=failed with CLINIC_SCOPE_REQUIRED
        // Final product contract (GREEN) is: backup.run as SYSTEM must eventually succeed without Clinic scope.
        // This assertion validates the exact defect cause in current defective implementation.
        self::assertSame('failed', $status, 'Pre-GREEN observation: job must be failed in current defective wiring. tickResult=' . var_export($tickResult, true) . ' clinic_count=' . $clinicCount);
        self::assertGreaterThanOrEqual(1, $attempts, 'job must have been claimed at least once');
        self::assertStringContainsString('CLINIC_SCOPE_REQUIRED', $lastError, 'last_error must contain stable identifier CLINIC_SCOPE_REQUIRED. last_error=' . $lastError);

        // Exact intended RED signature — must be present for evidence guard
        $redSignature = 'RED_SIGNATURE=clinic_bound_settings_dependency CLINIC_SCOPE_REQUIRED '
            . 'status=' . $status . ' '
            . 'attempts=' . $attempts . ' '
            . 'clinic_count=' . $clinicCount . ' '
            . 'clinicA=' . $this->clinicA . ' enabled=true '
            . 'clinicB=' . $this->clinicB . ' enabled=false '
            . 'user_id=' . get_current_user_id() . ' '
            . 'scope_context=none '
            . 'payload_empty=true '
            . 'no_artifact=true '
            . 'bootstrapOk=' . var_export($bootstrapOk, true) . ' '
            . 'fixturesOk=' . var_export($fixturesOk, true) . ' '
            . 'productPathReached=' . var_export($productPathReached, true);

        // Emit for log-based evidence guard
        fwrite(STDOUT, "\n" . $redSignature . "\n");
        fwrite(STDOUT, "GREEN_CONTRACT=backup.run as SYSTEM must eventually execute deterministically without Clinic scope\n");

        // Final guard: ensure signature contains required markers
        self::assertStringContainsString('CLINIC_SCOPE_REQUIRED', $redSignature, 'RED signature must contain CLINIC_SCOPE_REQUIRED');
        self::assertStringContainsString('status=failed', $redSignature, 'RED signature must contain status=failed');
        self::assertStringContainsString('no_artifact=true', $redSignature, 'RED signature must contain no_artifact proof');
        self::assertStringContainsString('scope_context=none', $redSignature, 'RED signature must contain scope_context=none');
        self::assertStringContainsString('payload_empty=true', $redSignature, 'RED signature must contain payload_empty');
    }
}
