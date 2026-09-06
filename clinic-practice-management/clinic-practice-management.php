<?php
/**
 * Plugin Name:       Clinic Practice Management (CPMS)
 * Plugin URI:        https://example.local/clinic-practice-management
 * Description:       سیستم اختصاصی مدیریت مطب و پرونده الکترونیک بیمار (نوبت‌دهی، ویزیت، مالی، Audit). داده‌های پزشکی در جداول اختصاصی cpms_* — نه wp_posts.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            CPMS
 * License:           proprietary
 * Text Domain:       cpms
 *
 * @package ClinicCore
 */

defined('ABSPATH') || exit;

define('CPMS_VERSION', '1.0.0');
define('CPMS_PLUGIN_FILE', __FILE__);
define('CPMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CPMS_PLUGIN_URL', plugin_dir_url(__FILE__));

// PSR-4 autoloader (composer نبود → autoload سبک داخلی)
spl_autoload_register(function (string $class): void {
    $prefix = 'ClinicCore\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = CPMS_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use ClinicCore\Bootstrap\App;

// Correlation ID برای Trace (Audit + OpLog + هدر REST) — Baseline §26 / M10.
// تعریف در سطح فایل اصلی (نه داخل hook): در WP Test Suite/CLI هیچ درخواستی
// وجود ندارد و init اجرا نمی‌شود؛ helper باید همیشه در دسترس باشد. کلاینت
// می‌تواند X-CPMS-Correlation-Id بفرستد (فقط Whitelist کاراکتر؛ بدون PHI).
if (!function_exists('cpms_request_id')) {
    function cpms_request_id(): ?string {
        static $id = null;
        if ($id === null) {
            $header = $_SERVER['HTTP_X_CPMS_CORRELATION_ID'] ?? null;
            $id = \ClinicCore\Infrastructure\Logging\CorrelationId::fromHeader(
                is_string($header) ? $header : null
            );
        }

        return $id;
    }
}
if (!function_exists('cpms_session_id')) {
    function cpms_session_id(): ?string {
        return session_id() ?: null;
    }
}

register_activation_hook(__FILE__, [App::class, 'activate']);
register_deactivation_hook(__FILE__, [App::class, 'deactivate']);

App::boot();
