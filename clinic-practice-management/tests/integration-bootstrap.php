<?php
/**
 * Bootstrap تست‌های Integration — توسط WP Test Suite (CI) لود می‌شود.
 * استفاده: phpunit --testsuite Integration --bootstrap tests/integration-bootstrap.php
 */

declare(strict_types=1);

$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wp-tests';

if (!is_file($_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "WP test suite not found in {$_tests_dir}\n");
    exit(1);
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/clinic-practice-management.php';
});

require $_tests_dir . '/includes/bootstrap.php';
