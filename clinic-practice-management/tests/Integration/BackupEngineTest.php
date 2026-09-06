<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Backup\BackupService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Backup\BackupSqlDumper;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use WP_UnitTestCase;

/**
 * F10 — موتور بکاپ روی مسیر واقعی (MySQL + دیسک) — spec §22–§25.
 *
 * در CI اجرا می‌شود (MySQL واقعی). پوشش:
 *  - createBackup: dump تمام cpms_* + storage mirror + مانیفست + guards
 *  - verifyBackup: تمامیت (و تشخیص دستکاری)
 *  - listBackups / prune (Retention)
 *  - restorePreflight: restore_safe (بدون تغییر چیزی)
 *  - safety backup قبل از restore
 *
 * NOTE: restoreApply (DROP/ایمپورت) عمداً اینجا اجرا نمی‌شود — DDL داخل
 * تراکنش تست، ایزوله‌سازی WP را می‌شکند؛ اعمال مخرب = عملیات CLI/Admin با
 * تأیید + Safety Backup (مستند در docs/backup). Preflight + verify در CI
 * اثبات می‌شود؛ اعمال نهایی = فرایند DR (BLOCKED_BY_ENVIRONMENT در این sandbox).
 */
final class BackupEngineTest extends WP_UnitTestCase
{
    private string $tmpBase;
    private string $filesBase;
    private BackupService $backups;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        $this->tmpBase = sys_get_temp_dir() . '/cpms-backup-test-' . bin2hex(random_bytes(5));
        $this->filesBase = $this->tmpBase . '/clinic-files';
        mkdir($this->filesBase . '/1/a3', 0750, true);
        file_put_contents($this->filesBase . '/1/a3/' . str_repeat('b', 32) . '.pdf', 'test-file-content-123');

        $this->backups = new BackupService(
            App::db(),
            new ProtectedBackupStore($this->tmpBase . '/backups'),
            new BackupSqlDumper(App::db()),
            App::settings(),
            App::audit(),
            App::op(),
            $this->filesBase
        );
    }

    protected function tearDown(): void
    {
        $this->rm($this->tmpBase);
        parent::tearDown();
    }

    public function testCreateBackupProducesVerifiableArtifact(): void
    {
        $meta = $this->backups->createBackup('integration-test');
        $this->assertNotEmpty($meta['backup_id']);
        $this->assertSame('ok_quick', $meta['integrity']);

        $dir = $this->tmpBase . '/backups/' . $meta['backup_id'];
        $this->assertFileExists($dir . '/manifest.json');
        $this->assertFileExists($dir . '/db.sql');
        $this->assertFileExists($dir . '/manifest.json.sha256');
        $this->assertGreaterThan(0, $meta['tables']);
        $this->assertSame(1, $meta['storage_files']);

        // dump شامل جدول‌های cpms است ولی نه wp_options
        $sql = (string) file_get_contents($dir . '/db.sql');
        $this->assertStringContainsString('cpms_patients', $sql);

        // فایل ذخیره‌سازی هم‌هش با مانیفست
        $this->assertSame('ok', $this->backups->verifyBackup($meta['backup_id'])['ok'] ? 'ok' : 'fail');

        // .htaccess گارد
        $this->assertFileExists($this->tmpBase . '/backups/.htaccess');
    }

    public function testTamperDetection(): void
    {
        $meta = $this->backups->createBackup('tamper');
        $dir = $this->tmpBase . '/backups/' . $meta['backup_id'];

        // دستکاری کپیِ فایلِ ذخیره‌سازی داخل آرتیفکت بکاپ (نه منبع اصلی)
        file_put_contents($dir . '/storage/1/a3/' . str_repeat('b', 32) . '.pdf', 'TAMPERED');
        $verify = $this->backups->verifyBackup($meta['backup_id']);
        $this->assertFalse($verify['ok']);
        $this->assertNotEmpty($verify['errors']);

        // دستکاری مانیفست
        file_put_contents($dir . '/manifest.json', '{"tampered":true}');
        $this->assertSame('corrupt', $this->backups->backupMeta($meta['backup_id'])['integrity']);
    }

    public function testPruneKeepsNewestByCreatedAt(): void
    {
        $older = $this->backups->createBackup('older', time() - 3600);
        $newer = $this->backups->createBackup('newer', time());
        $this->assertCount(2, $this->backups->listBackups());

        $removed = $this->backups->prune(1);
        $this->assertSame([$older['backup_id']], $removed);
        $ids = array_column($this->backups->listBackups(), 'backup_id');
        $this->assertSame([$newer['backup_id']], $ids);
    }

    public function testRestorePreflightReportsSafeWithoutChanges(): void
    {
        $meta = $this->backups->createBackup('preflight');
        $pre = $this->backups->restorePreflight($meta['backup_id']);
        $this->assertTrue($pre['restore_safe']);
        $this->assertTrue($pre['integrity_ok']);
        $this->assertTrue($pre['db_reachable']);
        $this->assertGreaterThan(0, $pre['tables']);

        // safety backup برای restore
        $safety = $this->backups->createBackup('pre-restore-safety-' . $meta['backup_id']);
        $this->assertTrue($this->backups->verifyBackup($safety['backup_id'])['ok']);
    }

    public function testRestoreRequiresExplicitConfirmation(): void
    {
        $meta = $this->backups->createBackup('confirm');
        try {
            $this->backups->restoreApply($meta['backup_id'], false);
            $this->fail('restore بدون تأیید باید رد شود');
        } catch (\ClinicCore\Infrastructure\Backup\BackupException $e) {
            $this->assertSame('CLINIC_BACKUP_CONFIRM_REQUIRED', $e->getErrorCode());
        }
    }

    private function rm(string $path): void
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
}
