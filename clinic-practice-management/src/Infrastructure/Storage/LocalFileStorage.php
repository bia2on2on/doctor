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

    /**
     * گارد IIS — معادل .htaccess روی وب‌سرور مایکروسافت.
     */
    private const GUARD_WEBCONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n";

    /**
     * یادداشت راه‌اندازی — `.htaccess` روی nginx خوانده نمی‌شود.
     */
    private const GUARD_README = "CPMS protected clinical storage\n\nThis directory holds patient documents. It must never be reachable over HTTP.\n\nApache/IIS: .htaccess and web.config in this directory already deny access.\nnginx IGNORES .htaccess. Add to the server block:\n\n    location ^~ /wp-content/clinic-files/ { deny all; return 404; }\n\nBetter: move this directory outside the document root and point the\n`files.storage_path` setting at the new absolute path.\n";

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
     *
     * Phase 1A: پیش از این مسیر فقط الحاق می‌شد و هیچ کنترلی نبود که
     * نتیجه واقعاً داخل ریشهٔ ذخیره‌سازی بماند. امروز `storage_path` را
     * خودِ store() تولید می‌کند (32 hex) و از ورودی کاربر نمی‌آید، پس
     * پیمایش مسیر قابل بهره‌برداری نبود؛ اما تنها چیزی که بین یک ستون
     * دیتابیس و «خواندن/حذف هر فایلی روی سرور» ایستاده بود، همان فرض بود.
     * حالا محدودسازی صریح است (Defence in Depth): هر مسیری که از ریشه
     * بیرون بزند رد می‌شود.
     */
    public function absolutePath(string $storagePath): ?string
    {
        $safe = $this->containedPath($storagePath);
        if ($safe === null || !is_file($safe)) {
            return null;
        }

        return $safe;
    }

    /**
     * مسیر نرمال‌شده و محدودشده به داخل basePath — یا null.
     *
     * از realpath استفاده نمی‌کنیم چون فایل ممکن است هنوز وجود نداشته
     * باشد؛ نرمال‌سازی نمادین انجام می‌شود و سپس ریشه بررسی می‌شود.
     */
    public function containedPath(string $storagePath): ?string
    {
        $relative = str_replace('\\', '/', ltrim($storagePath, '/'));
        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                // هر تلاش برای بالا رفتن رد می‌شود — نه «pop»، چون
                // `a/../../etc` نباید بی‌سروصدا به چیز دیگری تبدیل شود.
                return null;
            }
            $segments[] = $segment;
        }
        if ($segments === []) {
            return null;
        }

        $full = $this->basePath . '/' . implode('/', $segments);

        // اگر فایل موجود است، Symlink هم نباید از ریشه خارج شود.
        $real = @realpath($full);
        if ($real !== false) {
            $rootReal = @realpath($this->basePath);
            if ($rootReal === false || !str_starts_with($real, rtrim($rootReal, '/') . '/')) {
                return null;
            }

            return $real;
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
        // absolutePath خود محدودسازی ریشه را اعمال می‌کند.
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
        // Phase 1A: تکیه بر .htaccess به تنهایی کافی نیست — nginx آن را
        // نمی‌خواند. گارد IIS و یادداشت راه‌اندازی nginx هم نوشته می‌شود.
        // این‌ها جایگزین پیکربندی وب‌سرور نیستند؛ ریسک باقی‌مانده در
        // گزارش امنیتی Phase 1 ثبت شده است.
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
     * آیا ریشهٔ ذخیره‌سازی داخل DocumentRoot است؟
     *
     * برای هشدار مدیریتی: اگر بله، محافظت به پیکربندی وب‌سرور وابسته است.
     */
    public function isInsideWebRoot(): bool
    {
        $root = defined('ABSPATH') ? @realpath((string) ABSPATH) : false;
        $base = @realpath($this->basePath);
        if ($root === false || $base === false) {
            return false;
        }

        return str_starts_with($base, rtrim($root, '/') . '/');
    }
}
