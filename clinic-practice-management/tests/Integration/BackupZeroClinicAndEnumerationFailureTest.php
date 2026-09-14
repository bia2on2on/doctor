<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\InstallationSettings;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Application\Backup\BackupService;
use ClinicCore\Infrastructure\Backup\BackupSqlDumper;
use WP_UnitTestCase;

/**
 * Focused tests for zero-Clinic and enumeration-failure contracts.
 *
 * Proves:
 * - enumeration query failure FAILS CLOSED, no successful incomplete backup
 * - zero-Clinic state does NOT manufacture owner 0, database-only backup valid (0 files)
 * - normal multi-Clinic still works (covered elsewhere but sanity)
 */
final class BackupZeroClinicAndEnumerationFailureTest extends WP_UnitTestCase
{
    private string $tmpBase = '';
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

        $this->tmpBase = sys_get_temp_dir() . '/cpms-backup-zero-' . bin2hex(random_bytes(5));
        $this->backupRoot = $this->tmpBase . '/backups';
        @mkdir($this->tmpBase, 0750, true);
        @mkdir($this->backupRoot, 0750, true);

        $this->purgeInstallationOptions();
        $this->resetAppCaches();
        wp_set_current_user(0);
        ScopeContext::clear();
    }

    protected function tearDown(): void
    {
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

    public function testZeroClinicDoesNotManufactureOwnerZeroAndAllowsDatabaseOnlyBackup(): void
    {
        // Ensure no clinics exist for this test — delete all clinics and organizations
        // Note: other tests run in transaction, but we explicitly purge
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        $wpdb->query('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE `key` = \"files.storage_path\"');
        $wpdb->query('DELETE FROM ' . $db->table('cpms_clinics'));
        $wpdb->query('DELETE FROM ' . $db->table('cpms_organizations'));
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->resetAppCaches();
        wp_set_current_user(0);
        ScopeContext::clear();

        // Verify zero clinics
        $count = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertSame(0, $count, 'zero clinics for this test');

        $inst = App::installationSettings();
        $inst->setBackupEnabled(true);
        $inst->setBackupIntervalHours(1);
        $inst->setBackupKeepCount(5);
        $inst->setBackupStoragePath($this->backupRoot);
        $inst->setBackupLastRunAt(0);

        $backupService = App::backupService();

        // Use reflection to inspect enumerateActiveClinicalStorageRoots directly
        $ref = new \ReflectionClass($backupService);
        $method = $ref->getMethod('enumerateActiveClinicalStorageRoots');
        $method->setAccessible(true);
        $roots = $method->invoke($backupService);

        // Must be empty, NOT containing owner 0
        self::assertIsArray($roots);
        self::assertCount(0, $roots, 'zero clinics must yield empty roots map, not synthetic owner 0');
        // Ensure no [0] anywhere in map values
        foreach ($roots as $base => $cids) {
            self::assertNotContains(0, $cids, 'no synthetic owner 0 in roots map');
        }

        // Create backup — should succeed as database-only (0 storage files)
        $meta = $backupService->createBackup('zero-clinic-db-only');
        self::assertNotEmpty($meta['backup_id']);
        self::assertSame(0, $meta['storage_files'], 'zero clinics => database-only backup with 0 storage files');

        $dir = $this->backupRoot . '/' . $meta['backup_id'];
        self::assertFileExists($dir . '/manifest.json');
        $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        self::assertSame(0, $manifest['storage']['count'] ?? null);
        self::assertSame([], $manifest['storage']['files'] ?? null);

        // Ensure no file with owner 0 exists in artifact
        $storageDir = $dir . '/storage';
        if (is_dir($storageDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($storageDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $rel = ltrim(substr($f->getPathname(), strlen($storageDir)), '/');
                    $firstSeg = explode('/', $rel, 2)[0] ?? '';
                    self::assertNotSame('0', $firstSeg, 'no owner 0 file in backup artifact');
                    self::assertTrue(is_numeric($firstSeg) && (int) $firstSeg > 0, 'if any file exists, it must have valid clinicId >0');
                }
            }
        }

        fwrite(STDOUT, "\nGREEN_ZERO_CLINIC_DB_ONLY=ok no owner 0, storage 0\n");
    }

    public function testEnumerationFailureFailsClosed(): void
    {
        $this->resetAppCaches();
        wp_set_current_user(0);
        ScopeContext::clear();

        $inst = App::installationSettings();
        $inst->setBackupEnabled(true);
        $inst->setBackupIntervalHours(1);
        $inst->setBackupKeepCount(5);
        $inst->setBackupStoragePath($this->backupRoot);
        $inst->setBackupLastRunAt(0);

        // Create a mock CpmsDb that throws on fetchAll for clinics
        $realDb = App::db();
        $mockDb = new class($realDb) extends \ClinicCore\Infrastructure\Db\CpmsDb {
            private \ClinicCore\Infrastructure\Db\CpmsDb $real;
            public function __construct(\ClinicCore\Infrastructure\Db\CpmsDb $real) {
                $this->real = $real;
            }
            public function __call(string $name, array $args) {
                return $this->real->$name(...$args);
            }
            public function fetchAll(string $sql, array $params = []): array {
                if (str_contains($sql, 'cpms_clinics')) {
                    throw new \RuntimeException('simulated DB failure for enumeration');
                }
                return $this->real->fetchAll($sql, $params);
            }
            public function fetchRow(string $sql, array $params = []): ?array {
                return $this->real->fetchRow($sql, $params);
            }
            public function fetchValue(string $sql, array $params = []): mixed {
                return $this->real->fetchValue($sql, $params);
            }
            public function table(string $name): string {
                return $this->real->table($name);
            }
            public function query(string $sql, array $params = []): mixed {
                return $this->real->query($sql, $params);
            }
            public function transactional(callable $fn): void {
                $this->real->transactional($fn);
            }
            public function nowUtcSql(): string {
                return $this->real->nowUtcSql();
            }
        };

        // Build BackupService with mock DB
        $store = ProtectedBackupStore::active($this->backupRoot);
        $dumper = new BackupSqlDumper($realDb);
        $backupService = new BackupService(
            $mockDb,
            $store,
            $dumper,
            App::installationSettings(),
            App::audit(),
            App::op(),
            sys_get_temp_dir() . '/fake-files-base'
        );

        $ref = new \ReflectionClass($backupService);
        $method = $ref->getMethod('enumerateActiveClinicalStorageRoots');
        $method->setAccessible(true);

        $failedClosed = false;
        $code = '';
        try {
            $method->invoke($backupService);
        } catch (\ClinicCore\Infrastructure\Backup\BackupException $e) {
            $failedClosed = true;
            $code = $e->getErrorCode();
            self::assertSame('CLINIC_BACKUP_ENUMERATION_FAILED', $code);
        }

        self::assertTrue($failedClosed, 'enumeration failure must FAIL CLOSED with stable code');

        // Also ensure createBackup fails closed, not creating successful incomplete backup
        $failedCreate = false;
        try {
            $backupService->createBackup('should-fail');
        } catch (\ClinicCore\Infrastructure\Backup\BackupException $e) {
            $failedCreate = true;
            self::assertSame('CLINIC_BACKUP_ENUMERATION_FAILED', $e->getErrorCode());
        }
        self::assertTrue($failedCreate, 'createBackup must fail closed on enumeration failure');

        // Ensure no successful backup with valid manifest exists (incomplete dir may exist but not valid)
        $ids = $store->listIds();
        $validCount = 0;
        foreach ($ids as $id) {
            $dir = $store->dirOf($id);
            if (is_file($dir . '/manifest.json') && is_file($dir . '/manifest.json.sha256')) {
                $validCount++;
            }
        }
        self::assertSame(0, $validCount, 'no successful backup with valid manifest should exist on enumeration failure');

        fwrite(STDOUT, "\nGREEN_ENUMERATION_FAILURE_FAIL_CLOSED=ok code={$code}\n");
    }
}
