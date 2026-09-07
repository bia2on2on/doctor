<?php

/**
 * PHPStan bootstrap — ثابت‌هایی که در تحلیل استاتیک در دسترس نیستند.
 *
 * - ثابت‌های WP که stubs تعریف‌شان نمی‌کند (در runtime توسط WP bootstrap ساخته می‌شوند).
 * - ثابت‌های افزونه که در فایل اصلی plugin (clinic-practice-management.php) تعریف می‌شوند
 *   — PHPStan آن فایل را اجرا نمی‌کند.
 * فقط برای تحلیل استاتیک است؛ در runtime هرگز لود نمی‌شود (فقط phpstan.neon).
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/wp/');
}
if (!defined('CPMS_PLUGIN_DIR')) {
    define('CPMS_PLUGIN_DIR', __DIR__ . '/');
}
if (!defined('CPMS_VERSION')) {
    define('CPMS_VERSION', 'dev');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}
if (!defined('MONTH_IN_SECONDS')) {
    define('MONTH_IN_SECONDS', 2592000);
}
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}
