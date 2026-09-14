<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\InstallationSettings;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use WP_UnitTestCase;

/**
 * RED focused: backup.run scope-neutral but fallback to single clinic-files path
 * omits active clinical files from Clinics with differing storage roots.
 *
 * Contract: installation-wide backup must include all intended active clinical
 * files across all Clinics regardless of differing legitimate roots.
 *
 * This test must FAIL with current fallback because it omits at least one file.
 */
final class BackupMultiClinicFilesRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private string $tmpBase = '';
    private string $rootA = '';
    private string $rootB = '';
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

        $this->tmpBase = sys_get_temp_dir() . '/cpms-backup-multi-' . bin2hex(random_bytes(5));
        $this->rootA = $this->tmpBase . '/clinic-a-files';
        $this->rootB = $this->tmpBase . '/clinic-b-files';
        $this->backupRoot = $this->tmpBase . '/backups';

        @mkdir($this->tmpBase, 0750, true);
        @mkdir($this->rootA, 0750, true);
        @mkdir($this->rootB, 0750, true);
        @mkdir($this->backupRoot, 0750, true);

        $this->buildClinics();
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

    private function buildClinics(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $orgSlug = 'backup-multi-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'Backup Multi Org',
            $orgSlug,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId);

        $slugA = 'backup-multi-clinic-a-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Backup Multi Clinic A',
            $slugA,
            'Asia/Tehran',
            $now,
            $now
        ));
        $this->clinicA = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicA);

        $slugB = 'backup-multi-clinic-b-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Backup Multi Clinic B',
            $slugB,
            'Asia/Tehran',
            $now,
            $now
        ));
        $this->clinicB = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicB);
        self::assertNotSame($this->clinicA, $this->clinicB);

        // Set differing active storage roots per clinic
        $factory = App::settingsFactory();
        $settingsA = $factory->forClinic($this->clinicA);
        $settingsA->set('files.storage_path', $this->rootA);

        $settingsB = $factory->forClinic($this->clinicB);
        $settingsB->set('files.storage_path', $this->rootB);

        self::assertSame($this->rootA, $settingsA->get('files.storage_path'));
        self::assertSame($this->rootB, $settingsB->get('files.storage_path'));

        // Create identifiable fixtures in both active roots
        $fileAPath = $this->rootA . '/' . $this->clinicA . '/ab';
        @mkdir($fileAPath, 0750, true);
        file_put_contents($fileAPath . '/fileA-clinicA.txt', 'CONTENT-CLINIC-A-' . $this->clinicA);

        $fileBPath = $this->rootB . '/' . $this->clinicB . '/cd';
        @mkdir($fileBPath, 0750, true);
        file_put_contents($fileBPath . '/fileB-clinicB.txt', 'CONTENT-CLINIC-B-' . $this->clinicB);

        self::assertFileExists($fileAPath . '/fileA-clinicA.txt', 'fixture A must exist');
        self::assertFileExists($fileBPath . '/fileB-clinicB.txt', 'fixture B must exist');

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

    public function testInstallationWideBackupMustIncludeAllActiveClinicFiles(): void
    {
        // Assert setup materially
        self::assertGreaterThan(0, $this->clinicA);
        self::assertGreaterThan(0, $this->clinicB);
        self::assertNotSame($this->clinicA, $this->clinicB);
        self::assertFileExists($this->rootA . '/' . $this->clinicA . '/ab/fileA-clinicA.txt');
        self::assertFileExists($this->rootB . '/' . $this->clinicB . '/cd/fileB-clinicB.txt');
        self::assertNotSame($this->rootA, $this->rootB);

        // No user, no ScopeContext
        wp_set_current_user(0);
        $this->resetAppCaches();
        self::assertSame(0, get_current_user_id());
        self::assertNull(ScopeContext::tryGet());

        // Multi-clinic without scope must fail closed for App::scope()
        try {
            $scope = App::scope();
            self::fail('App::scope() must fail closed in multi-clinic without explicit scope, got ' . $scope->clinicId);
        } catch (\ClinicCore\Application\Scope\ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode);
        }

        $this->resetAppCaches();
        wp_set_current_user(0);
        ScopeContext::clear();

        // Configure installation-level backup
        $inst = App::installationSettings();
        $inst->setBackupEnabled(true);
        $inst->setBackupIntervalHours(1);
        $inst->setBackupKeepCount(5);
        $inst->setBackupStoragePath($this->backupRoot);
        $inst->setBackupLastRunAt(0);

        self::assertSame($this->backupRoot, $inst->getBackupStoragePath());

        // Exercise real installation-wide backup creation path without user/ScopeContext
        $backupService = App::backupService();
        $meta = $backupService->createBackup('multi-clinic-test');

        self::assertNotEmpty($meta['backup_id'], 'backup_id must exist');
        $backupId = $meta['backup_id'];
        $backupDir = $this->backupRoot . '/' . $backupId;

        self::assertFileExists($backupDir . '/manifest.json');
        self::assertFileExists($backupDir . '/db.sql');
        self::assertFileExists($backupDir . '/manifest.json.sha256');

        // Read manifest storage list
        $manifestRaw = json_decode((string) file_get_contents($backupDir . '/manifest.json'), true);
        self::assertIsArray($manifestRaw);
        $storageFiles = $manifestRaw['storage']['files'] ?? [];
        self::assertIsArray($storageFiles);

        $paths = array_column($storageFiles, 'path');
        // Expected to contain both fixtures
        $expectedA = $this->clinicA . '/ab/fileA-clinicA.txt';
        $expectedB = $this->clinicB . '/cd/fileB-clinicB.txt';

        // For debugging, output what we have
        fwrite(STDOUT, "\nBACKUP_ID=$backupId\n");
        fwrite(STDOUT, "ROOT_A={$this->rootA} CLINIC_A={$this->clinicA}\n");
        fwrite(STDOUT, "ROOT_B={$this->rootB} CLINIC_B={$this->clinicB}\n");
        fwrite(STDOUT, "BACKUP_DIR=$backupDir\n");
        fwrite(STDOUT, "STORAGE_FILES=" . implode(',', $paths) . "\n");

        // The contract: successful backup must include all intended active files
        // With current fallback (single default path), this will FAIL — RED
        self::assertContains($expectedA, $paths, "backup must include file from Clinic A ($expectedA) — RED if omitted due to single-path fallback");
        self::assertContains($expectedB, $paths, "backup must include file from Clinic B ($expectedB) — RED if omitted due to single-path fallback");

        // Also check physical files exist in backup artifact
        self::assertFileExists($backupDir . '/storage/' . $expectedA, 'physical file A in backup');
        self::assertFileExists($backupDir . '/storage/' . $expectedB, 'physical file B in backup');

        // Preserve tenant identity: files remain distinguishable, no overwrite
        self::assertNotSame($expectedA, $expectedB);
        $contentA = file_get_contents($backupDir . '/storage/' . $expectedA);
        $contentB = file_get_contents($backupDir . '/storage/' . $expectedB);
        self::assertStringContainsString('CONTENT-CLINIC-A', $contentA);
        self::assertStringContainsString('CONTENT-CLINIC-B', $contentB);
        self::assertNotSame($contentA, $contentB);

        // GREEN signature for future
        fwrite(STDOUT, "GREEN_SIGNATURE=multi_clinic_backup_includes_all_active_files\n");
    }
}
