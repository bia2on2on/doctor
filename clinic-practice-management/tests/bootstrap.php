<?php
/**
 * Bootstrap تست — Unit: خالص (بدون WP).
 * Integration: توسط WP Test Suite باجی (tests/integration-bootstrap.php) مدیریت می‌شود.
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(function (string $class): void {
        $prefix = 'ClinicCore\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $base = str_starts_with($class, 'ClinicCore\\Tests\\') ? __DIR__ . '/' : __DIR__ . '/../src/';
        $relative = substr($class, strlen($prefix));
        $file = $base . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
