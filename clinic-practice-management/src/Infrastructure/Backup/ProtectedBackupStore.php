<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Backup;

use ClinicCore\Infrastructure\Storage\PrivateStorageLocation;
use ClinicCore\Infrastructure\Storage\StorageConfigurationException;

/**
 * مخزن محلی بکاپ با محافظت از دسترسی عمومی (spec §23):
 *
 *   {base}/{backup_id}/  ← هر بکاپ یک پوشه با مانیفست + db.sql + storage/
 *
 * OD-9 (تصمیم مالک) — دو حالت ساخت، هم‌ارز الگوی OD-7 در `LocalFileStorage`:
 *
 * - `::active($path)` — مخزن **فعال** (قابل نوشتن). ریشه باید بیرون از
 *   DocumentRoot باشد؛ مسیر داخل webroot استثنای `StorageConfigurationException`
 *   با کد `CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT` می‌دهد (Fail-Closed، بدون
 *   fallback بی‌صدایی). بکاپ یک dump کامل پایگاه داده + همان PHI است؛
 *   `.htaccess` روی nginx خوانده نمی‌شود، پس گارد وب‌سرور مرز مجوز نیست.
 * - `::legacySource($path)` — نمای **فقط‌خواندنی** روی ریشهٔ قدیمی (معمولاً
 *   داخل webroot) برای verification/migration/recovery. نوشتن/حذف در این
 *   حالت ممنوع است — وجود بکاپ‌های legacy هرگز آن مسیر را به ذخیره‌سازیِ
 *   فعالِ قابل‌نوشتن تبدیل نمی‌کند.
 *
 * backup_id فقط `[0-9a-z._-]` (بدون path traversal).
 *
 * V1: مقصد محلی. مقصدهای دور (S3/SFTP) = V1.1 (interface آماده؛ Runbook در
 * docs/backup — Remote mirror عملیاتی همچنان مسئولیت Ops است).
 */
final class ProtectedBackupStore
{
    private const GUARD_HTACCESS = "# CPMS protected backups — direct access denied\nRequire all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";

    /**
     * گارد IIS — معادل .htaccess.
     */
    private const GUARD_WEBCONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n";

    /**
     * یادداشت راه‌اندازی — nginx فایل .htaccess را نادیده می‌گیرد.
     */
    private const GUARD_README = "CPMS protected backups\n\nThis directory contains full database dumps and clinical files.\nAs of OD-9 the ACTIVE backup root must live OUTSIDE the document root;\nnew backups are rejected (fail-closed) while this path is configured.\n\nThis directory is therefore a LEGACY, read-only recovery source at best.\nIt must never be reachable over HTTP.\n\nApache/IIS: .htaccess and web.config here already deny access.\nnginx IGNORES .htaccess. Add to the server block:\n\n    location ^~ /wp-content/cpms-backups/ { deny all; return 404; }\n\nFix: move these backups to the private root (idempotent migration does\nthis automatically) and clear/fix the backup.storage_path setting.\n";

    /**
     * @throws StorageConfigurationException اگر ریشهٔ فعال داخل DocumentRoot باشد
     */
    private function __construct(
        private readonly string $basePath,
        private readonly bool $readonly
    ) {
    }

    /**
     * مخزن فعال — تنها مقصد مجاز برای نوشتن بکاپ. Fail-Closed: ریشهٔ داخل
     * DocumentRoot (مقایسهٔ realpath ⇒ symlink هم دنبال می‌شود) رد می‌شود و
     * هیچ fallback بی‌صدایی به مسیر امن وجود ندارد.
     */
    public static function active(string $basePath): self
    {
        $store = new self($basePath, false);
        PrivateStorageLocation::assertOutsideWebRoot(
            $basePath,
            'ذخیره‌سازی بکاپ',
            'CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT'
        );

        return $store;
    }

    /**
     * نمای فقط‌خواندنی روی یک ریشهٔ legacy (معمولاً داخل webroot) — صرفاً
     * مبدأ verification/migration/recovery. هرگز مقصد نوشتن نیست.
     */
    public static function legacySource(string $basePath): self
    {
        return new self($basePath, true);
    }

    /**
     * OD-7 — پیش‌فرض اکنون بیرون از DocumentRoot است. بکاپ‌ها همان PHI ای را
     * دارند که اسناد بالینی دارند و نباید هیچ URLی داشته باشند.
     */
    public static function defaultBasePath(): string
    {
        return PrivateStorageLocation::path('cpms-backups');
    }

    /**
     * ریشهٔ قدیمی داخل DocumentRoot — فقط برای مهاجرت idempotent.
     */
    public static function legacyBasePath(): string
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

    /**
     * آیا این مخزن فقط یک مبدأ legacy خواندنی است؟ (OD-9)
     */
    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    public function ensureGuards(): void
    {
        $this->assertWritable('ensureGuards');
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
        // Phase 1A: یک بکاپ کاملِ پایگاه داده در دسترس مستقیم HTTP یک
        // نشت تمام‌عیار است و `.htaccess` روی nginx خوانده نمی‌شود.
        $webConfig = $this->basePath . '/web.config';
        if (!is_file($webConfig)) {
            @file_put_contents($webConfig, self::GUARD_WEBCONFIG);
        }
        $readme = $this->basePath . '/README-SECURITY.txt';
        if (!is_file($readme)) {
            @file_put_contents($readme, self::GUARD_README);
        }
    }

    /**
     * آیا ریشهٔ بکاپ داخل DocumentRoot است؟ (هشدار مدیریتی)
     */
    public function isInsideWebRoot(): bool
    {
        return PrivateStorageLocation::isInsideWebRoot($this->basePath);
    }

    /**
     * ساخت پوشه‌ی یک بکاپ جدید (فقط آماده‌سازی).
     */
    public function createDir(string $backupId): string
    {
        $this->assertWritable('createDir');
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
        // OD-9: مخزن فقط‌خواندنی هیچ گاردی نمی‌نویسد — فهرست‌کردن یک عمل
        // خواندن است و مبدأ legacy نباید دست‌خورد شود.
        if (!$this->readonly) {
            $this->ensureGuards();
        }
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
        $this->assertWritable('delete');
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

    /**
     * مبدأ legacy فقط‌خواندنی هرگز عملیات نوشتن/حذف انجام نمی‌دهد (OD-9).
     */
    private function assertWritable(string $operation): void
    {
        if ($this->readonly) {
            throw StorageConfigurationException::insideWebRoot(
                $this->basePath,
                'ذخیره‌سازی بکاپ (مبدأ legacy فقط‌خواندنی — عملیات «' . $operation . '» مجاز نیست)',
                'CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT'
            );
        }
    }

    private function assertSafeId(string $backupId): void
    {
        if (!preg_match('/^[0-9a-z][0-9a-z._-]{3,120}$/', $backupId)) {
            throw BackupException::of('CLINIC_BACKUP_INVALID_ID', 'invalid backup id');
        }
    }
}
