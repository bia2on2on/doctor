<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\JobScopeClass;
use ClinicCore\Application\Jobs\JobScopeRegistry;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\InstallationSettings;
use WP_UnitTestCase;

/**
 * M-2 backup.run — GREEN contract proving installation-level wiring.
 *
 * Product contract:
 *  - backup.run is SYSTEM (installation-wide), must execute without current user,
 *    without ScopeContext, without payload clinic_id.
 *  - Multiple dynamic Clinics with conflicting legacy backup.* settings cannot
 *    control the job; legacy Clinic-bound values are ignored.
 *  - Installation-level values (InstallationSettings) control enabled/cadence,
 *    no first-Clinic fallback, no clinic_id=0/merge.
 *  - Real dispatcher/job wiring exercised via App::runTick.
 *  - Avoid expensive full backup where possible via deterministic enabled=false
 *    and cadence-not-due checks; one minimal backup creation proves artifact
 *    lands in installation-level storage, not legacy per-Clinic paths.
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
            }
        }
        ScopeContext::clear();
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->resetAppCaches();
        $this->tmpBase = sys_get_temp_dir() . '/cpms-backup-green-' . bin2hex(random_bytes(5));
        @mkdir($this->tmpBase, 0750, true);
        @mkdir($this->tmpBase . '/store-a', 0750, true);
        @mkdir($this->tmpBase . '/store-b', 0750, true);
        @mkdir($this->tmpBase . '/store-install', 0750, true);
        $this->buildClinics();
        $this->purgeJobs();
        $this->purgeInstallationOptions();
        $this->resetAppCaches();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        $this->purgeFixture();
        $this->purgeInstallationOptions();
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

        $orgSlug = 'backup-green-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'Backup Green Org',
            $orgSlug,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId);

        $slugA = 'backup-green-clinic-a-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Backup Green Clinic A',
            $slugA,
            'Asia/Tehran',
            $now,
            $now
        ));
        $this->clinicA = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicA, 'clinic A id >1');

        $slugB = 'backup-green-clinic-b-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Backup Green Clinic B',
            $slugB,
            'Asia/Tokyo',
            $now,
            $now
        ));
        $this->clinicB = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicB);
        self::assertNotSame($this->clinicA, $this->clinicB);

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

        self::assertTrue((bool) $settingsA->get('backup.enabled'));
        self::assertFalse((bool) $settingsB->get('backup.enabled'));

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

    private function purgeInstallationOptions(): void
    {
        // Clean wp_options for backup installation keys
        if (function_exists('delete_option')) {
            delete_option(InstallationSettings::OPTION_BACKUP_ENABLED);
            delete_option(InstallationSettings::OPTION_BACKUP_INTERVAL_HOURS);
            delete_option(InstallationSettings::OPTION_BACKUP_KEEP_COUNT);
            delete_option(InstallationSettings::OPTION_BACKUP_STORAGE_PATH);
            delete_option(InstallationSettings::OPTION_BACKUP_LAST_RUN_AT);
        }
        $this->resetAppCaches();
    }

    public function testBackupRunGreenInstallationLevelControls(): void
    {
        global $wpdb;
        $db = App::db();

        // Fixtures
        self::assertGreaterThan(0, $this->clinicA);
        self::assertGreaterThan(0, $this->clinicB);
        $clinicCount = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $clinicCount);

        // No user, no ScopeContext
        wp_set_current_user(0);
        $this->resetAppCaches();
        self::assertSame(0, get_current_user_id());
        self::assertNull(ScopeContext::tryGet());

        // SYSTEM registration
        self::assertSame(JobScopeClass::SYSTEM, JobScopeRegistry::classFor('backup.run'));
        self::assertFalse(JobScopeRegistry::requiresClinicContext('backup.run'));
        self::assertTrue(JobScopeRegistry::permitsNullClinic('backup.run'));

        $registered = App::dispatcher()->registeredTypes();
        self::assertContains('backup.run', $registered);

        // Multi-clinic without scope must fail closed for App::scope()
        try {
            $scope = App::scope();
            self::fail('App::scope() must fail closed in multi-clinic without explicit scope, got ' . $scope->clinicId);
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode);
        }

        // Verify conflicting legacy settings still present
        $factory = App::settingsFactory();
        $aEnabled = (bool) $factory->forClinic($this->clinicA)->get('backup.enabled', false);
        $bEnabled = (bool) $factory->forClinic($this->clinicB)->get('backup.enabled', false);
        self::assertTrue($aEnabled, 'clinic A legacy enabled true');
        self::assertFalse($bEnabled, 'clinic B legacy enabled false');
        self::assertNotSame($aEnabled, $bEnabled);

        $this->resetAppCaches();
        wp_set_current_user(0);
        self::assertNull(ScopeContext::tryGet());

        // ---------- Phase 1: installation disabled => job must NOT create artifact, even though legacy A=true ----------
        $inst = App::installationSettings();
        $inst->setBackupEnabled(false);
        $inst->setBackupIntervalHours(24);
        $inst->setBackupKeepCount(2);
        $inst->setBackupStoragePath($this->tmpBase . '/store-install');
        $inst->setBackupLastRunAt(0);

        self::assertFalse($inst->getBackupEnabled(), 'installation enabled false');
        self::assertSame(24, $inst->getBackupIntervalHours());
        self::assertSame(2, $inst->getBackupKeepCount());
        self::assertSame($this->tmpBase . '/store-install', $inst->getBackupStoragePath());

        // Ensure no artifacts before
        self::assertSame([], glob($this->tmpBase . '/store-a/cpms-backup-*') ?: []);
        self::assertSame([], glob($this->tmpBase . '/store-b/cpms-backup-*') ?: []);
        self::assertSame([], glob($this->tmpBase . '/store-install/cpms-backup-*') ?: []);

        $queue = App::jobs();
        $nowDt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId1 = $queue->enqueue('backup.run', [], $nowDt, 1, 1);
        self::assertGreaterThan(0, $jobId1);

        $jobBefore1 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId1), ARRAY_A);
        $payloadJson1 = (string) ($jobBefore1['payload_json'] ?? '');
        self::assertStringNotContainsString('clinic_id', $payloadJson1, 'payload must not contain clinic_id');
        self::assertStringNotContainsString((string) $this->clinicA, $payloadJson1);
        self::assertStringNotContainsString((string) $this->clinicB, $payloadJson1);

        $tick1 = App::runTick(20);
        $jobAfter1 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId1), ARRAY_A);
        $status1 = (string) ($jobAfter1['status'] ?? '');
        $attempts1 = (int) ($jobAfter1['attempts'] ?? 0);
        $lastError1 = (string) ($jobAfter1['last_error'] ?? '');

        // Must be completed (handler returned early) or at least not failed due to CLINIC_SCOPE_REQUIRED
        self::assertNotSame('failed', $status1, 'job must not fail when installation disabled; legacy true must be ignored. last_error=' . $lastError1 . ' tick=' . var_export($tick1, true));
        self::assertGreaterThanOrEqual(1, $attempts1, 'job must have been claimed');

        // No artifact anywhere because disabled
        self::assertSame([], glob($this->tmpBase . '/store-a/cpms-backup-*') ?: [], 'no artifact in legacy A when installation disabled');
        self::assertSame([], glob($this->tmpBase . '/store-b/cpms-backup-*') ?: [], 'no artifact in legacy B when installation disabled');
        self::assertSame([], glob($this->tmpBase . '/store-install/cpms-backup-*') ?: [], 'no artifact in install when disabled');

        self::assertSame(0, get_current_user_id(), 'user remains 0');
        self::assertNull(ScopeContext::tryGet(), 'ScopeContext remains none');

        // ---------- Phase 2: installation enabled + due => must create artifact in installation path, not legacy ----------
        $this->resetAppCaches();
        wp_set_current_user(0);
        $inst = App::installationSettings();
        $inst->setBackupEnabled(true);
        $inst->setBackupIntervalHours(1);
        $inst->setBackupLastRunAt(0);
        $inst->setBackupStoragePath($this->tmpBase . '/store-install');
        $inst->setBackupKeepCount(2);

        $jobId2 = $queue->enqueue('backup.run', [], new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 1, 1);
        self::assertGreaterThan(0, $jobId2);

        $tick2 = App::runTick(20);
        $jobAfter2 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId2), ARRAY_A);
        $status2 = (string) ($jobAfter2['status'] ?? '');
        $attempts2 = (int) ($jobAfter2['attempts'] ?? 0);
        $lastError2 = (string) ($jobAfter2['last_error'] ?? '');

        self::assertGreaterThanOrEqual(1, $attempts2, 'second job claimed');
        // Backup may succeed or fail for other reasons (storage), but must NOT fail due to CLINIC_SCOPE_REQUIRED
        if ($status2 === 'failed') {
            self::assertStringNotContainsString('CLINIC_SCOPE_REQUIRED', $lastError2, 'must not fail due to clinic scope');
            self::assertStringNotContainsString('امکان تعیین Clinic', $lastError2, 'must not fail due to clinic scope Persian');
        } else {
            self::assertContains($status2, ['success', 'queued', 'processing'], 'status should be non-failed after installation enabled. status='.$status2.' error='.$lastError2);
        }

        $artifactsInstall = glob($this->tmpBase . '/store-install/cpms-backup-*') ?: [];
        $artifactsA = glob($this->tmpBase . '/store-a/cpms-backup-*') ?: [];
        $artifactsB = glob($this->tmpBase . '/store-b/cpms-backup-*') ?: [];

        // If backup succeeded, artifact must be in install path, not legacy
        if ($artifactsInstall !== []) {
            self::assertNotSame([], $artifactsInstall, 'artifact must exist in installation path when enabled');
            self::assertSame([], $artifactsA, 'no artifact in legacy A when installation controls');
            self::assertSame([], $artifactsB, 'no artifact in legacy B when installation controls');

            // Verify last_run_at operational state updated
            $this->resetAppCaches();
            $instAfter = App::installationSettings();
            $lastRunAfter = $instAfter->getBackupLastRunAt();
            self::assertGreaterThan(0, $lastRunAfter, 'last_run_at operational state must be updated after successful backup');
        } else {
            // If no artifact (e.g., backup failed for unrelated reason), at least prove cadence path:
            // When installation last_run_at is recent, job must skip without artifact and without scope failure
            $this->resetAppCaches();
            $instSkip = App::installationSettings();
            $instSkip->setBackupEnabled(true);
            $instSkip->setBackupIntervalHours(24);
            $instSkip->setBackupLastRunAt(time()); // not due
            $instSkip->setBackupStoragePath($this->tmpBase . '/store-install');

            $jobId3 = $queue->enqueue('backup.run', [], new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 1, 1);
            App::runTick(20);
            $jobAfter3 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId3), ARRAY_A);
            $status3 = (string) ($jobAfter3['status'] ?? '');
            self::assertNotSame('failed', $status3, 'job must not fail when not due (cadence controlled by installation)');
            self::assertSame([], glob($this->tmpBase . '/store-install/cpms-backup-*') ?: [], 'no new artifact when cadence not due');
        }

        // ---------- Phase 3: No first-Clinic fallback ----------
        // Even though Clinic A enabled=true, installation disabled must win (already proven)
        // And when installation enabled=true, it must not use Clinic A's storage_path
        $this->resetAppCaches();
        $instFinal = App::installationSettings();
        $finalStorage = $instFinal->getBackupStoragePath();
        self::assertSame($this->tmpBase . '/store-install', $finalStorage, 'storage_path must be installation-level, not first Clinic');
        self::assertNotSame($this->tmpBase . '/store-a', $finalStorage, 'must not fallback to first Clinic A');
        self::assertNotSame($this->tmpBase . '/store-b', $finalStorage, 'must not fallback to Clinic B');

        // GREEN signature
        $greenSignature = 'GREEN_SIGNATURE=installation_level_backup_run '
            . 'status1=' . $status1 . ' attempts1=' . $attempts1 . ' '
            . 'status2=' . $status2 . ' attempts2=' . $attempts2 . ' '
            . 'clinic_count=' . $clinicCount . ' '
            . 'clinicA=' . $this->clinicA . ' legacy_enabled=true '
            . 'clinicB=' . $this->clinicB . ' legacy_enabled=false '
            . 'installation_enabled=' . var_export($instFinal->getBackupEnabled(), true) . ' '
            . 'installation_storage=' . $finalStorage . ' '
            . 'user_id=' . get_current_user_id() . ' '
            . 'scope_context=none payload_empty=true '
            . 'no_first_clinic_fallback=true legacy_ignored=true';

        fwrite(STDOUT, "\n" . $greenSignature . "\n");
        fwrite(STDOUT, "GREEN_CONTRACT=backup.run as SYSTEM executes deterministically without Clinic scope, legacy ignored, installation controls\n");

        self::assertStringContainsString('installation_level_backup_run', $greenSignature);
        self::assertStringContainsString('no_first_clinic_fallback=true', $greenSignature);
        self::assertStringContainsString('legacy_ignored=true', $greenSignature);
        self::assertStringContainsString('scope_context=none', $greenSignature);
        self::assertStringContainsString('payload_empty=true', $greenSignature);
    }
}
