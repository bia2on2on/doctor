<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\InstallationSettings;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use WP_UnitTestCase;

/**
 * Focused executable test exposing invalid fallback: clinicId==0 -> firstActiveBase (Clinic1).
 *
 * Proves non-Clinic/ambiguous artifact cannot be silently restored into arbitrary Clinic root.
 * Must FAIL CLOSED with stable machine-readable error, no guessing.
 *
 * Classification D: sentinel-closure.txt was test infra, not valid production artifact.
 * Production restore must fail closed for ambiguous paths.
 */
final class BackupAmbiguousRestoreFailsClosedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicA = 0;
    private string $tmpBase = '';
    private string $rootA = '';
    private string $backupRoot = '';

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
        ScopeContext::clear();
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->resetAppCaches();

        $this->tmpBase = sys_get_temp_dir() . '/cpms-backup-ambig-' . bin2hex(random_bytes(5));
        $this->rootA = $this->tmpBase . '/clinic-a-files';
        $this->backupRoot = $this->tmpBase . '/backups';

        @mkdir($this->tmpBase, 0750, true);
        @mkdir($this->rootA, 0750, true);
        @mkdir($this->backupRoot, 0750, true);

        $this->buildClinic();
        $this->purgeInstallationOptions();
        $this->resetAppCaches();
        wp_set_current_user(0);
        ScopeContext::clear();
    }

    protected function tearDown(): void
    {
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

    private function buildClinic(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $orgSlug = 'backup-ambig-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'Backup Ambig Org',
            $orgSlug,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;

        $slugA = 'backup-ambig-clinic-a-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Backup Ambig Clinic A',
            $slugA,
            'Asia/Tehran',
            $now,
            $now
        ));
        $this->clinicA = (int) $wpdb->insert_id;

        $factory = App::settingsFactory();
        $settingsA = $factory->forClinic($this->clinicA);
        $settingsA->set('files.storage_path', $this->rootA);

        $this->resetAppCaches();
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        if ($this->clinicA > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicA));
        }
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', $this->orgId));
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->resetAppCaches();
    }

    private function purgeInstallationOptions(): void
    {
        if (function_exists('delete_option')) {
            delete_option(InstallationSettings::OPTION_BACKUP_ENABLED);
            delete_option(InstallationSettings::OPTION_BACKUP_INTERVAL_HOURS);
            delete_option(InstallationSettings::OPTION_BACKUP_KEEP_COUNT);
            delete_option(InstallationSettings::OPTION_BACKUP_STORAGE_PATH);
            delete_option(InstallationSettings::OPTION_BACKUP_LAST_RUN_AT);
        }
        $this->resetAppCaches();
    }

    public function testAmbiguousRestoreFailsClosedNotSilentIntoClinicRoot(): void
    {
        wp_set_current_user(0);
        $this->resetAppCaches();
        ScopeContext::clear();

        $inst = App::installationSettings();
        $inst->setBackupStoragePath($this->backupRoot);

        $backupService = App::backupService();

        // Prepare fake storage artifact with ambiguous file (no clinic_id prefix)
        $fakeBackupStorageDir = $this->tmpBase . '/fake-backup-storage';
        @mkdir($fakeBackupStorageDir, 0750, true);
        // Create source file that would be in backup artifact
        $ambiguousRel = 'sentinel-closure.txt'; // D-class: no clinic_id
        file_put_contents($fakeBackupStorageDir . '/' . $ambiguousRel, 'ORIGINAL-SENTINEL-CONTENT');

        $files = [
            ['path' => $ambiguousRel, 'size' => 24, 'sha256' => hash('sha256', 'ORIGINAL-SENTINEL-CONTENT')],
        ];

        $ref = new \ReflectionClass($backupService);
        $method = $ref->getMethod('restoreFiles');
        $method->setAccessible(true);

        $failedClosed = false;
        $errorCode = '';
        try {
            $method->invoke($backupService, $fakeBackupStorageDir, $files);
        } catch (\ClinicCore\Infrastructure\Backup\BackupException $e) {
            $failedClosed = true;
            $errorCode = $e->getErrorCode();
            // Must be stable machine-readable error, not generic IO
            self::assertTrue(
                in_array($errorCode, ['CLINIC_BACKUP_AMBIGUOUS_OWNER', 'CLINIC_BACKUP_INVALID_PATH'], true),
                'ambiguous must fail closed with stable error, got: ' . $errorCode
            );
        }

        self::assertTrue($failedClosed, 'restore of ambiguous non-Clinic file must FAIL CLOSED, not silently restore into arbitrary Clinic root');

        // Prove it was NOT silently restored into Clinic A root (firstActiveBase fallback prohibited)
        $maybeRestored = $this->rootA . '/' . $ambiguousRel;
        self::assertFileDoesNotExist($maybeRestored, 'ambiguous file must NOT be silently restored into Clinic A root (firstActiveBase fallback prohibited)');

        // Also test another ambiguous form: first segment not numeric
        $ambiguousRel2 = 'not-a-clinic/file.txt';
        @mkdir($fakeBackupStorageDir . '/not-a-clinic', 0750, true);
        file_put_contents($fakeBackupStorageDir . '/' . $ambiguousRel2, 'AMBIGUOUS');
        $files2 = [
            ['path' => $ambiguousRel2, 'size' => 9, 'sha256' => hash('sha256', 'AMBIGUOUS')],
        ];

        $failedClosed2 = false;
        try {
            $method->invoke($backupService, $fakeBackupStorageDir, $files2);
        } catch (\ClinicCore\Infrastructure\Backup\BackupException $e) {
            $failedClosed2 = true;
            self::assertTrue(
                in_array($e->getErrorCode(), ['CLINIC_BACKUP_AMBIGUOUS_OWNER', 'CLINIC_BACKUP_INVALID_PATH'], true),
                'ambiguous must fail closed, got: ' . $e->getErrorCode()
            );
        }
        self::assertTrue($failedClosed2, 'restore of non-numeric first segment must FAIL CLOSED');

        $maybeRestored2 = $this->rootA . '/' . $ambiguousRel2;
        self::assertFileDoesNotExist($maybeRestored2, 'non-numeric first segment must NOT be restored into arbitrary Clinic root');

        fwrite(STDOUT, "\nGREEN_AMBIGUOUS_FAIL_CLOSED=ok code={$errorCode} sentinel not in clinic root\n");
    }

    public function testBackupSkipsNonClinicFiles(): void
    {
        // Create a file directly in clinic root (non-clinical, no clinic_id) — should be skipped, not backed up
        $nonClinicFile = $this->rootA . '/sentinel-closure.txt';
        file_put_contents($nonClinicFile, 'SHOULD_BE_SKIPPED');

        // Create valid clinical file
        $validRel = $this->clinicA . '/ab/valid.txt';
        $validAbs = $this->rootA . '/' . $validRel;
        @mkdir(dirname($validAbs), 0750, true);
        file_put_contents($validAbs, 'VALID-CONTENT');

        wp_set_current_user(0);
        $this->resetAppCaches();
        ScopeContext::clear();

        $inst = App::installationSettings();
        $inst->setBackupEnabled(true);
        $inst->setBackupIntervalHours(1);
        $inst->setBackupKeepCount(5);
        $inst->setBackupStoragePath($this->backupRoot);
        $inst->setBackupLastRunAt(0);

        $backupService = App::backupService();
        $meta = $backupService->createBackup('skip-non-clinic-test');
        $backupDir = $this->backupRoot . '/' . $meta['backup_id'];

        self::assertFileDoesNotExist($backupDir . '/storage/sentinel-closure.txt', 'non-clinic file directly in base must NOT be included in backup (D-class)');
        self::assertFileExists($backupDir . '/storage/' . $validRel, 'valid clinical file must be included');

        fwrite(STDOUT, "\nGREEN_BACKUP_SKIPS_NON_CLINIC=ok\n");
    }
}
