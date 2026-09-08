<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Storage;

/**
 * OD-7 (تصمیم مالک — گزینهٔ A) — محل قطعی ذخیره‌سازی خصوصی.
 *
 * تا پیش از این، هم اسناد بالینی و هم بکاپ‌ها زیر `wp-content/` می‌نشستند،
 * یعنی **داخل DocumentRoot**. هیچ کد PHP ای آن مسیرها را رهگیری نمی‌کند، پس
 * تنها چیزی که جلوی بایت‌ها را می‌گرفت پیکربندی وب‌سرور بود: `.htaccess` روی
 * Apache کار می‌کند و **nginx آن را نادیده می‌گیرد** — و nginx یک پیکربندی
 * پشتیبانی‌شدهٔ صریح این پروژه است. نام فایل ۳۲ کاراکتری تصادفی فقط
 * «حدس‌ناپذیری» است، نه «مجوز»؛ URL می‌تواند از Referrer، لاگ پروکسی،
 * تاریخچهٔ مرورگر یا اشتراک‌گذاری نشت کند.
 *
 * راه‌حل قطعی، بیرون بردن داده از DocumentRoot است تا اصلاً URLی وجود نداشته
 * باشد. `.htaccess` / `web.config` / راهنمای nginx از این پس فقط
 * Defense in Depth برای نصب‌های قدیمی‌اند، نه خط دفاع اصلی.
 *
 * ## ترتیب قطعی تصمیم
 *
 * ۱. ثابت `CPMS_PRIVATE_STORAGE_DIR` (اگر تعریف و ناتهی) — کنترل کامل اپراتور.
 * ۲. در غیر این صورت `dirname(ABSPATH) . '/cpms-private'` — والدِ ریشهٔ
 *    وردپرس. در چیدمان استاندارد که DocumentRoot برابر ABSPATH است، این مسیر
 *    بیرون از DocumentRoot می‌افتد.
 * ۳. اگر `ABSPATH` تعریف نشده باشد (Unit Test)، والدِ پوشهٔ افزونه.
 *
 * توجه: Settingهای `files.storage_path` و `backup.storage_path` همچنان بالاتر
 * از این کلاس تصمیم می‌گیرند و در `App` اعمال می‌شوند؛ این کلاس فقط **پیش‌فرض**
 * را تعیین می‌کند.
 */
final class PrivateStorageLocation
{
    public const CONSTANT = 'CPMS_PRIVATE_STORAGE_DIR';

    /**
     * ریشهٔ خصوصی — بدون ساخت پوشه، بدون اثر جانبی.
     */
    public static function root(): string
    {
        if (defined(self::CONSTANT)) {
            $configured = trim((string) constant(self::CONSTANT));
            if ($configured !== '') {
                return rtrim(str_replace('\\', '/', $configured), '/');
            }
        }

        if (defined('ABSPATH')) {
            return rtrim(str_replace('\\', '/', dirname(rtrim((string) ABSPATH, '/\\'))), '/') . '/cpms-private';
        }

        return rtrim(str_replace('\\', '/', dirname(__DIR__, 3)), '/') . '/cpms-private';
    }

    /**
     * یک زیرشاخهٔ نام‌دار از ریشهٔ خصوصی.
     */
    public static function path(string $name): string
    {
        return self::root() . '/' . trim($name, '/');
    }

    /**
     * دروازهٔ Fail-Closed برای هر ریشهٔ ذخیره‌سازی **فعال**.
     *
     * اصل امنیتی (تصمیم مالک): «Private clinical storage must remain outside
     * the effective web/document root.» پس یک مسیر داخل DocumentRoot نه
     * پذیرفته می‌شود و نه با هشدار تحمل می‌شود و نه بی‌سروصدا با پیش‌فرض
     * جایگزین می‌شود — چون هر سه حالت یعنی داده در جایی می‌نشیند که
     * ممکن است با URL خوانده شود.
     *
     * ⚠️ این تابع فقط برای ریشه‌های **فعال** است. مهاجرت اجازه دارد مسیر
     * قدیمیِ داخل webroot را به‌عنوان **مبدأ فقط‌خواندنی** بخواند؛ آن مسیر
     * هرگز از این دروازه عبور نمی‌کند و هرگز به ذخیره‌سازی فعال تبدیل
     * نمی‌شود.
     *
     * @throws StorageConfigurationException
     */
    public static function assertOutsideWebRoot(string $path, string $what): string
    {
        if (self::isInsideWebRoot($path)) {
            throw StorageConfigurationException::insideWebRoot($path, $what);
        }

        return $path;
    }

    /**
     * آیا این مسیر داخل DocumentRoot وردپرس است — یعنی بالقوه از طریق HTTP
     * قابل دسترس؟
     *
     * مقایسه روی `realpath` انجام می‌شود تا Symlink و `..` نتوانند نتیجه را
     * فریب دهند. اگر مسیر هنوز ساخته نشده باشد، نزدیک‌ترین والدِ موجود
     * ارزیابی می‌شود.
     */
    public static function isInsideWebRoot(string $path): bool
    {
        if (!defined('ABSPATH')) {
            return false;
        }
        $root = @realpath((string) ABSPATH);
        if ($root === false) {
            return false;
        }

        $probe = $path;
        $resolved = @realpath($probe);
        while ($resolved === false) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return false;
            }
            $probe = $parent;
            $resolved = @realpath($probe);
        }

        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $resolved = rtrim(str_replace('\\', '/', $resolved), '/') . '/';

        return str_starts_with($resolved, $root);
    }
}
