<?php

declare(strict_types=1);

/** Independent-process worker for competing first-manager bootstrap requests. */

ini_set('display_errors', '0');
ini_set('log_errors', '0');

$plugin_root = dirname(__DIR__, 2);
$tests_dir = rtrim((string) getenv('WP_TESTS_DIR'), '/');
$config_path = (string) getenv('WP_TESTS_CONFIG_FILE_PATH');
if ($config_path === '' && $tests_dir !== '') {
    $config_path = $tests_dir . '/wp-tests-config.php';
}
if ($config_path === '' || !is_file($config_path)) {
    fwrite(STDOUT, "ERROR:CONFIG\n");
    exit(2);
}

try {
    ob_start();
    require $config_path;
    $core_dir = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : '';
    if ($core_dir === '') {
        $core_dir = rtrim((string) getenv('WP_CORE_DIR'), '/');
    }
    if ($core_dir === '' || !is_file($core_dir . '/wp-settings.php')) {
        fwrite(STDOUT, "ERROR:CORE\n");
        exit(2);
    }
    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', false);
    }
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }
    require_once $core_dir . '/wp-settings.php';
    require_once $plugin_root . '/vendor/autoload.php';
    ob_end_clean();
} catch (\Throwable) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDOUT, "ERROR:BOOTSTRAP\n");
    exit(2);
}

$actor_id = (int) getenv('CPMS_BOOTSTRAP_ACTOR_ID');
$organization_id = (int) getenv('CPMS_BOOTSTRAP_ORGANIZATION_ID');
$target_user_id = (int) getenv('CPMS_BOOTSTRAP_TARGET_ID');
$ready_file = (string) getenv('CPMS_BOOTSTRAP_READY_FILE');
$go_file = (string) getenv('CPMS_BOOTSTRAP_GO_FILE');
if ($actor_id <= 0 || $organization_id <= 0 || $target_user_id <= 0 || $ready_file === '' || $go_file === '') {
    fwrite(STDOUT, "ERROR:TASK\n");
    exit(2);
}
wp_set_current_user($actor_id);
if (file_put_contents($ready_file, 'ready', LOCK_EX) === false) {
    fwrite(STDOUT, "ERROR:READY\n");
    exit(2);
}
$deadline = microtime(true) + 15.0;
while (!is_file($go_file) && microtime(true) < $deadline) {
    usleep(10000);
}
if (!is_file($go_file)) {
    fwrite(STDOUT, "ERROR:GATE\n");
    exit(2);
}

try {
    $result = \ClinicCore\Bootstrap\App::organization_clinic_provisioning_service()->bootstrap_owner(
        $actor_id,
        $organization_id,
        $target_user_id
    );
    fwrite(STDOUT, $result['noop'] ? "RESULT:NOOP\n" : "RESULT:CREATED\n");
    exit(0);
} catch (\Throwable) {
    fwrite(STDOUT, "RESULT:CONFLICT\n");
    exit(0);
}
