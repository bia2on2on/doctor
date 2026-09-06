<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Backup;

/**
 * مخزن محلی بکاپ با محافظت از دسترسی عمومی (spec §23):
 *
 *   {base}/{backup_id}/  ← هر بکاپ یک پوشه با مانیفست + db.sql + storage/
 *
 * - root پیش‌فرض: `{WP_CONTENT_DIR}/cpms-backups` با `.htaccess` (deny) +
 *   `index.php` خالی — دقیقاً الگوی LocalFileStorage (دو لایه).
 * - backup_id فقط `[0-9a-z._-]` (بدون path traversal).
 *
 * V1: مقصد محلی. مقصدهای دور (S3/SFTP) = V1.1 (interface آماده؛ Runbook در
 * docs/backup — Remote mirror عملیاتی همچنان مسئولیت Ops است).
 */
final class ProtectedBackupStore
{
    private const GUARD_HTACCESS = "# CPMS protected backups — direct access denied\nRequire all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";

    public function __construct(private readonly string $basePath)
    {
    }

    public static function defaultBasePath(): string
    {
        if (defined('WP_CONTENT_DIR')) {
            return rtrim((string) WP_CONTENT_DIR, '/') . '/cpms-backups';
        }

        return dirname(__DIR__, 3) . '/cpms-backups';
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function ensureGuards(): void
    {
        if (!is_dir($this->basePath)) {
            if (!mkdir($this->basePath, 0750, true) && !is_dir($this->basePath)) {
                throw BackupException::of('CLINIC_BACKUP_IO', 'backup dir mkdir failed: ' . $this->basePath);
            }
        }
        $ht = $this->basePath . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, self::GUARD_HTACCESS);
        }
        $idx = $this->basePath . '/index.php';
        if (!is_file($idx)) {
            @file_put_contents($idx, "<?php\n// silence\n");
        }
    }

    /**
     * ساخت پوشه‌ی یک بکاپ جدید (فقط آماده‌سازی).
     */
    public function createDir(string $backupId): string
    {
        $this->assertSafeId($backupId);
        $this->ensureGuards();
        $dir = $this->basePath . '/' . $backupId;
        if (is_dir($dir)) {
            throw BackupException::of('CLINIC_BACKUP_EXISTS', 'backup already exists: ' . $backupId);
        }
        if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw BackupException::of('CLINIC_BACKUP_IO', 'backup mkdir failed: ' . $backupId);
        }

        return $dir;
    }

    public function dirOf(string $backupId): string
    {
        $this->assertSafeId($backupId);

        return $this->basePath . '/' . $backupId;
    }

    public function exists(string $backupId): bool
    {
        $this->assertSafeId($backupId);

        return is_dir($this->basePath . '/' . $backupId);
    }

    /**
     * @return list<string> idهای بکاپ (نزولی — جدیدترین اول)
     */
    public function listIds(): array
    {
        $this->ensureGuards();
        $ids = [];
        foreach ((array) glob($this->basePath . '/*') as $entry) {
            if (!is_dir($entry)) {
                continue;
            }
            $id = basename($entry);
            if ($id === '' || $id[0] === '.') {
                continue;
            }
            $ids[] = $id;
        }
        rsort($ids);

        return $ids;
    }

    public function delete(string $backupId): void
    {
        $this->assertSafeId($backupId);
        $dir = $this->basePath . '/' . $backupId;
        if (!is_dir($dir)) {
            throw BackupException::of('CLINIC_BACKUP_NOT_FOUND', 'backup not found: ' . $backupId);
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function assertSafeId(string $backupId): void
    {
        if (!preg_match('/^[0-9a-z][0-9a-z._-]{3,120}$/', $backupId)) {
            throw BackupException::of('CLINIC_BACKUP_INVALID_ID', 'invalid backup id');
        }
    }
}
