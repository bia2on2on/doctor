<?php

declare(strict_types=1);

/**
 * Isolated child-process worker exercising WordPress core's real
 * `activate_plugin('clinic-practice-management/clinic-practice-management.php')`
 * flow BEFORE `clinic-practice-management.php` is included (`!defined('CPMS_PLUGIN_DIR')`).
 *
 * Why a child process is required (non-vacuous proof for F1/F5):
 *  - `tests/integration-bootstrap.php` loads `clinic-practice-management.php`
 *    on `muplugins_loaded`, so inside the main PHPUnit process `App::$booted`
 *    is already `true` and `include_once` inside `plugin_sandbox_scrape()` is
 *    a vacuous no-op (and `WP_SANDBOX_SCRAPING` would remain `true` for all
 *    subsequent tests in the process).
 *  - Here WordPress boots cleanly without CPMS, and `activate_plugin()` runs
 *    `plugin_sandbox_scrape()` -> `include_once clinic-practice-management.php`
 *    -> `App::boot()` (with `WP_SANDBOX_SCRAPING === true`) -> `App::activate()`.
 *  - Runs inside an explicit DB transaction that always rolls back in `finally`
 *    so the shared integration database is never mutated.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '0');

$plugin_root = dirname(__DIR__, 2);
$mode        = (string) getenv('CPMS_ACTIVATION_WORKER_MODE');
if (!in_array($mode, ['unsupported', 'supported'], true)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'stage' => 'args', 'error' => 'invalid mode']) . "\n");
    exit(2);
}

$tests_dir   = rtrim((string) getenv('WP_TESTS_DIR'), '/');
$config_path = (string) getenv('WP_TESTS_CONFIG_FILE_PATH');
if ($config_path === '' && $tests_dir !== '') {
    $config_path = $tests_dir . '/wp-tests-config.php';
}
if ($config_path === '' || !is_file($config_path)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'stage' => 'config', 'error' => 'missing wp-tests-config.php']) . "\n");
    exit(2);
}

try {
    ob_start();
    require $config_path;

    // Point WP_PLUGIN_DIR to the parent of `$plugin_root` so core's
    // `validate_plugin('clinic-practice-management/clinic-practice-management.php')`
    // and `plugin_sandbox_scrape()` resolve directly to this checkout.
    if (!defined('WP_PLUGIN_DIR')) {
        define('WP_PLUGIN_DIR', dirname($plugin_root));
    }
    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', false);
    }
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }
    if (!defined('WP_RUN_CORE_TESTS')) {
        define('WP_RUN_CORE_TESTS', true);
    }

    $core_dir = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : rtrim((string) getenv('WP_CORE_DIR'), '/');
    if ($core_dir === '' || !is_file($core_dir . '/wp-settings.php')) {
        ob_end_clean();
        fwrite(STDOUT, json_encode(['ok' => false, 'stage' => 'core', 'error' => 'missing wp-settings.php']) . "\n");
        exit(2);
    }

    require_once $core_dir . '/wp-settings.php';
    require_once $core_dir . '/wp-admin/includes/plugin.php';
    require_once $plugin_root . '/vendor/autoload.php';
    ob_end_clean();
} catch (\Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDOUT, json_encode(['ok' => false, 'stage' => 'bootstrap', 'error' => $e->getMessage()]) . "\n");
    exit(2);
}

// Guard against vacuous pass: CPMS must NOT be loaded yet, and WP_SANDBOX_SCRAPING
// must NOT be defined before `activate_plugin()` runs.
if (defined('CPMS_PLUGIN_DIR') || defined('WP_SANDBOX_SCRAPING')) {
    fwrite(STDOUT, json_encode([
        'ok'    => false,
        'stage' => 'precondition_unloaded',
        'error' => 'CPMS or WP_SANDBOX_SCRAPING was already defined before activate_plugin()',
    ]) . "\n");
    exit(2);
}

// CI matrix lanes running WP 6.7/6.8 against header `Requires at least: 6.9`
// would stop at `validate_plugin_requirements()` before `plugin_sandbox_scrape()`.
if (function_exists('is_wp_version_compatible') && !is_wp_version_compatible('6.9')) {
    $GLOBALS['_wp_tests_wp_version'] = '6.9.0';
    $GLOBALS['wp_version']           = '6.9.0';
}

$plugin_basename = 'clinic-practice-management/clinic-practice-management.php';
$cpms_roles      = [
    \ClinicCore\Auth\RolesAndCapabilities::ROLE_PATIENT,
    \ClinicCore\Auth\RolesAndCapabilities::ROLE_SECRETARY,
    \ClinicCore\Auth\RolesAndCapabilities::ROLE_DOCTOR,
    \ClinicCore\Auth\RolesAndCapabilities::ROLE_ACCOUNTANT,
    \ClinicCore\Auth\RolesAndCapabilities::ROLE_MANAGER,
];

global $wpdb;
$wpdb->query('START TRANSACTION');

try {
    // Establish a clean pre-activation state inside the transaction: plugin
    // inactive, no CPMS roles, no scheduled `cpms_jobs_tick` event.
    $active = (array) get_option('active_plugins', []);
    $active = array_values(array_filter($active, static fn ($p): bool => (string) $p !== $plugin_basename));
    update_option('active_plugins', $active);

    wp_clear_scheduled_hook('cpms_jobs_tick');
    wp_clear_scheduled_hook('cpms_jobs_wake');
    foreach ($cpms_roles as $role_slug) {
        remove_role($role_slug);
    }
    wp_roles()->for_site();

    if (wp_next_scheduled('cpms_jobs_tick') !== false || is_plugin_active($plugin_basename)) {
        throw new \RuntimeException('Failed to establish clean pre-activation state');
    }
    foreach ($cpms_roles as $role_slug) {
        if (get_role($role_slug) !== null) {
            throw new \RuntimeException('Role still present before activation: ' . $role_slug);
        }
    }

    $collect_options = static function () use ($wpdb): array {
        $rows = array_map('strval', (array) $wpdb->get_col("SELECT option_name FROM {$wpdb->options}"));
        sort($rows);
        return $rows;
    };
    $collect_ledger = static function () use ($wpdb): array {
        $table = $wpdb->prefix . 'cpms_schema_migrations';
        $rows  = array_map('strval', (array) $wpdb->get_col("SELECT version FROM {$table} ORDER BY version"));
        return $rows;
    };
    $collect_roles = static function (): array {
        $names = array_keys(wp_roles()->get_names());
        $names = array_map('strval', $names);
        sort($names);
        return $names;
    };
    $count_oplog = static function (string $msg) use ($wpdb): int {
        $table = $wpdb->prefix . 'cpms_operational_logs';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE message = %s", $msg));
    };

    $options_before     = $collect_options();
    $ledger_before      = $collect_ledger();
    $roles_before       = $collect_roles();
    $cron_before        = _get_cron_array();
    $activated_before   = $count_oplog('CPMS_ACTIVATED');
    $unsupported_before = $count_oplog('CPMS_DB_ENGINE_UNSUPPORTED');

    if ($mode === 'unsupported') {
        \ClinicCore\Bootstrap\App::set_db_compatibility_probe_for_tests(
            static fn (): array => \ClinicCore\Infrastructure\Db\DatabaseCompatibilityGate::check(
                '11.8.6-MariaDB',
                'mariadb.org binary distribution'
            )
        );
    } else {
        \ClinicCore\Bootstrap\App::set_db_compatibility_probe_for_tests(
            static fn (): array => \ClinicCore\Infrastructure\Db\DatabaseCompatibilityGate::check(
                '8.0.36',
                'MySQL Community Server - GPL'
            )
        );
    }

    $captured_die = null;
    $die_filter   = static function () use (&$captured_die): callable {
        return static function ($message, $title = '', $args = []) use (&$captured_die): void {
            $captured_die = [
                'message' => is_string($message) ? $message : '',
                'title'   => is_string($title) ? $title : '',
                'args'    => is_array($args) ? $args : [],
            ];
            throw new \RuntimeException('CPMS_WORKER_WP_DIE');
        };
    };
    add_filter('wp_die_handler', $die_filter, PHP_INT_MAX);

    $activate_return = null;
    try {
        $activate_return = activate_plugin($plugin_basename);
    } catch (\RuntimeException $e) {
        if ($e->getMessage() !== 'CPMS_WORKER_WP_DIE') {
            throw $e;
        }
    } finally {
        remove_filter('wp_die_handler', $die_filter, PHP_INT_MAX);
        // Balance ob_start() opened inside activate_plugin() if wp_die threw mid-scrape/hook.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    $present_cpms_roles = [];
    foreach ($cpms_roles as $role_slug) {
        if (get_role($role_slug) !== null) {
            $present_cpms_roles[] = $role_slug;
        }
    }

    $payload = [
        'ok'                       => true,
        'mode'                     => $mode,
        'cpms_loaded'              => defined('CPMS_PLUGIN_DIR'),
        'sandbox_scraping_defined' => defined('WP_SANDBOX_SCRAPING') && WP_SANDBOX_SCRAPING === true,
        'wp_error_code'            => is_wp_error($activate_return) ? $activate_return->get_error_code() : null,
        'wp_error_message'         => is_wp_error($activate_return) ? $activate_return->get_error_message() : null,
        'wp_die'                   => $captured_die,
        'plugin_active'            => is_plugin_active($plugin_basename),
        'jobs_tick_scheduled'      => wp_next_scheduled('cpms_jobs_tick') !== false,
        'present_cpms_roles'       => $present_cpms_roles,
        'roles_unchanged'          => $collect_roles() === $roles_before,
        'options_unchanged'        => $collect_options() === $options_before,
        'ledger_unchanged'         => $collect_ledger() === $ledger_before,
        'cron_unchanged'           => _get_cron_array() === $cron_before,
        'activated_log_delta'      => $count_oplog('CPMS_ACTIVATED') - $activated_before,
        'unsupported_log_delta'    => $count_oplog('CPMS_DB_ENGINE_UNSUPPORTED') - $unsupported_before,
    ];

    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
} catch (\Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDOUT, json_encode(['ok' => false, 'stage' => 'run', 'error' => $e->getMessage()]) . "\n");
    exit(2);
} finally {
    $wpdb->query('ROLLBACK');
}
