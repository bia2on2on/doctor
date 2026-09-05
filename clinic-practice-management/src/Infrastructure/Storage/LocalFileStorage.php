<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Storage;

use RuntimeException;

/**
 * ذخیره‌سازی محافظت‌شده فایل‌های پزشکی — docs/architecture/file-storage.md.
 *
 * **F-1:** هیچ فایلی URL عمومی ندارد — خروجی فقط از Endpoint مجوزیافته
 * (E17 Stream). چیدمان:
 *
 *   {base}/{clinic_id}/{stored_filename[:2]}/{stored_filename}.{ext}
 *
 * - base پیش‌فرض: `wp-content/clinic-files/` (خارج از uploads) با
 *   `.htaccess` (deny) + `index.php` خالی — دو لایه: سرور + Stream مجوزیافته.
 * - اگر زیرساخت مسیر خارج از DocumentRoot بدهد: Setting `files.storage_path`
 *   (مسیر مطلق) — توصیه file-storage.md §2.
 * - نام ذخیره تصادفی (F-2): `{32 hex}.{ext}` — نام اصلی فقط در DB.
 *
 * V1: رمزنگاری هر-فایل تصمیم کارفرما (files.encrypt_at_rest — F10/V1.5)؛
 * لایه فعلی = محافظت ساختاری (خارج uploads + نام تصادفی + deny + Stream).
 */
final class LocalFileStorage
{
    private const GUARD_HTACCESS = "# CPMS protected clinical storage — direct access denied\nRequire all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * مسیر پایه — سازگار با wp-content حتی وقتی ثابت WP_CONTENT_DIR موجود نیست.
     */
    public static function defaultBasePath(): string
    {
        if (defined('WP_CONTENT_DIR')) {
            return rtrim((string) WP_CONTENT_DIR, '/') . '/clinic-files';
        }

        return dirname(__DIR__, 3) . '/clinic-files';
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * ذخیره محتوای فایل با نام تصادفی — مسیر نسبی را برمی‌گرداند (DB).
     *
     * @return string storage_path نسبی مثل `1/a3/a3f9….c2.pdf`
     */
    public function store(string $content, int $clinicId, string $extension): string
    {
        $this->ensureGuards();

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $relative = $clinicId . '/' . substr($storedName, 0, 2) . '/' . $storedName;
        $absolute = $this->basePath . '/' . $relative;

        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('storage mkdir failed: ' . $dir);
        }
        if (file_put_contents($absolute, $content) === false) {
            throw new RuntimeException('storage write failed: ' . $absolute);
        }

        return $relative;
    }

    /**
     * مسیر مطلق برای Stream (E17) — عدم وجود فایل = null (404 سرویس).
     */
    public function absolutePath(string $storagePath): ?string
    {
        $full = $this->basePath . '/' . ltrim($storagePath, '/');
        if ($storagePath === '' || !is_file($full)) {
            return null;
        }

        return $full;
    }

    public function read(string $storagePath): ?string
    {
        $full = $this->absolutePath($storagePath);
        if ($full === null) {
            return null;
        }
        $content = file_get_contents($full);

        return $content === false ? null : $content;
    }

    /**
     * حذف فیزیکی فقط طبق Retention + Approval (F-5) — Soft Delete در Service.
     */
    public function delete(string $storagePath): bool
    {
        $full = $this->absolutePath($storagePath);
        if ($full === null) {
            return false;
        }

        return unlink($full);
    }

    /**
     * گاردهای سرور: `.htaccess` (deny) + `index.php` خالی — Idempotent.
     */
    private function ensureGuards(): void
    {
        if (!is_dir($this->basePath)) {
            if (!mkdir($this->basePath, 0750, true) && !is_dir($this->basePath)) {
                throw new RuntimeException('storage mkdir failed: ' . $this->basePath);
            }
        }
        $ht = $this->basePath . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, self::GUARD_HTACCESS);
        }
        $idx = $this->basePath . '/index.php';
        if (!is_file($idx)) {
            @file_put_contents($idx, "<?php\n// silence is golden\n");
        }
    }
}
