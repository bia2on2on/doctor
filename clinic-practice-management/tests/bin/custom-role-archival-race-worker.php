<?php

declare(strict_types=1);

/**
 * Independent archival-race waiter — launched as its own PHP process by
 * CustomRoleArchivalConcurrencyTest through proc_open().
 *
 * Descriptor isolation, with sources:
 *   - A pcntl_fork() child inherits the parent's *live* MySQL handle: on
 *     graceful exit it sends COM_QUIT on the socket the parent still uses and
 *     silently replaces the parent's session mid-transaction (observed in CI),
 *     which is why the fork implementation was removed.
 *   - proc_open() does not close unrelated descriptors in the child: it closes
 *     only the parent ends of the pipes it created and dup2()s the child ends
 *     (ext/standard/proc_open.c, close_parentends_of_pipes()). Only those parent
 *     ends are FD_CLOEXEC (same file, make_descriptor_cloexec()).
 *   - PHP creates the MySQLi socket with a bare socket() and never sets
 *     FD_CLOEXEC (main/streams/xp_socket.c, php_tcp_sockop_connect()), so a
 *     parent-held session socket would otherwise survive execvp() into this
 *     process as a raw descriptor that php://fd/N could wrap.
 *   - The launcher therefore maps every descriptor the parent holds above stdio
 *     to the /dev/null descriptor: the descriptor-spec key is the *child's* fd
 *     number, so the child dup2()s /dev/null over each of them before execvp().
 *     This process starts with stdio, those inert /dev/null descriptors, and
 *     nothing that was a socket or a pipe in the launcher — reported by the
 *     FD_PROBE line, which the parent asserts against. Any further descriptor it
 *     holds is one it opened for itself.
 *
 * Contract with the parent (stdout, one line per message; nothing else is ever
 * written to stdout — the WordPress bootstrap output is buffered away):
 *   FD_PROBE:<json>                           descriptor/env inventory, first line
 *   READY:<connection_id>                     own session established
 *   LOCK_QUERY:<conn_id>:<sha256>:<base64>    the real production locking query ran
 *   RESULT:<outcome>                          operation finished (exit 0)
 *   ERROR:<CODE>[:ref=<8 hex>]                fatal, exit 1
 *
 * The error protocol is sanitized: the code is from a fixed vocabulary and the
 * reference is a digest of the code, the exception class and the message
 * *length* only — never of the message text — so caller-controlled or
 * secret-bearing text can never leave this process through the protocol.
 *
 * Parameters arrive through the environment, never argv — no credentials or
 * identifiers appear in the process list. WordPress core is loaded through the
 * existing configuration file plus wp-settings.php (the same file a real
 * wp-config.php loads); the WordPress *test* bootstrap is never used, because it
 * shells out to install.php and drops every core table. The plugin and its
 * migrations are NOT loaded (services are constructed explicitly, exactly as the
 * test does), so the worker cannot migrate the database the parent is locking.
 */

$pluginRoot = dirname(__DIR__, 2);

/*
 * Raw PHP error text is never emitted. Uncaught error messages can carry
 * connection strings, SQL fragments or file contents, and they would land in
 * stderr — which is public CI evidence. Failures travel as sanitized codes.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '0');

/** @return string */
function cpms_race_env(string $name, string $default = ''): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
}

function cpms_race_emit(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

/**
 * Sanitized terminal failure: a fixed code plus an opaque reference.
 *
 * @param string          $code  one of the codes below, never free text
 * @param \Throwable|null $cause used only for its class name and message length
 */
function cpms_race_fail(string $code, ?\Throwable $cause = null): void
{
    $ref = '';
    if ($cause instanceof \Throwable) {
        $ref = ':ref=' . substr(
            hash('sha256', $code . '|' . get_class($cause) . '|' . strlen($cause->getMessage())),
            0,
            8
        );
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    cpms_race_emit('ERROR:' . $code . $ref);
    exit(1);
}

/**
 * Descriptor and environment inventory, taken before this process opens
 * anything of its own.
 *
 * Descriptors are reported as classes only ("dev-null", "socket", "pipe",
 * "regular") — no paths, no link targets. The environment is reported as sorted
 * *names* only, bounded, and never as values.
 *
 * @return array<string, mixed>
 */
function cpms_race_descriptor_probe(): array
{
    $probe = [
        'proc_fd' => false,
        'fds_beyond_stdio' => [],
        'socket_count' => -1,
        'env_keys' => [],
    ];

    $names = getenv();
    if (is_array($names)) {
        $names = array_values(array_filter(array_keys($names), static function ($name): bool {
            return is_string($name) && preg_match('/^[A-Z][A-Z0-9_]{0,39}$/', $name) === 1;
        }));
        sort($names);
        $probe['env_keys'] = array_slice($names, 0, 32);
    }

    $dir = '/proc/self/fd';
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return $probe;
    }

    $probe['proc_fd'] = true;
    $probe['socket_count'] = 0;
    $classes = [];
    foreach ($entries as $entry) {
        if (!is_string($entry) || !ctype_digit($entry)) {
            continue;
        }
        $fd = (int) $entry;
        if ($fd <= 2) {
            continue;
        }
        $target = @readlink($dir . '/' . $fd);
        if (!is_string($target)) {
            continue; // already closed again (the directory handle scandir used)
        }
        $class = 'regular';
        if ($target === '/dev/null') {
            $class = 'dev-null';
        } elseif (str_starts_with($target, 'socket:')) {
            $class = 'socket';
        } elseif (str_starts_with($target, 'pipe:')) {
            $class = 'pipe';
        }
        $classes[(string) $fd] = $class;
        if ($class === 'socket') {
            $probe['socket_count']++;
        }
    }
    $probe['fds_beyond_stdio'] = $classes;

    return $probe;
}

$probeJson = json_encode(cpms_race_descriptor_probe());
cpms_race_emit('FD_PROBE:' . (is_string($probeJson) ? $probeJson : '{"proc_fd":false}'));

/*
 * Controlled fault injection, used by the launcher's privacy tests: the marker
 * values are applied to a real runtime setting (include_path) and to real
 * environment configuration, and provoke a genuine user-level warning. Because
 * raw error text is suppressed above, the marker must not appear on any channel.
 */
$faultRaw = cpms_race_env('CPMS_RACE_FAULT_INJECT');
if ($faultRaw !== '') {
    $faultSpec = json_decode($faultRaw, true);
    if (is_array($faultSpec)) {
        $faultExtension = isset($faultSpec['extension']) && is_string($faultSpec['extension'])
            ? trim($faultSpec['extension'])
            : '';
        $faultDir = isset($faultSpec['dir']) && is_string($faultSpec['dir']) ? trim($faultSpec['dir']) : '';
        if ($faultDir !== '') {
            @putenv('WP_CONTENT_DIR=' . $faultDir);
            $_ENV['WP_CONTENT_DIR'] = $faultDir;
            @putenv('WP_PLUGIN_DIR=' . $faultDir);
            $_ENV['WP_PLUGIN_DIR'] = $faultDir;
        }
        if ($faultExtension !== '') {
            ini_set('include_path', $faultExtension);
            trigger_error('cpms-race-fault-injection ' . $faultExtension, E_USER_WARNING);
        }
    }
}

$testsDir = rtrim(cpms_race_env('WP_TESTS_DIR', '/tmp/wordpress-test-lib'), '/');
$configPath = cpms_race_env('WP_TESTS_CONFIG_FILE_PATH');
if ($configPath === '' && is_file($testsDir . '/wp-tests-config.php')) {
    $configPath = $testsDir . '/wp-tests-config.php';
}
if ($configPath === '' || !is_file($configPath)) {
    cpms_race_fail('CONFIG_NOT_FOUND');
}

$autoload = $pluginRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    cpms_race_fail('AUTOLOADER_MISSING');
}

/*
 * WordPress core is loaded the way a real installation loads it: the
 * configuration file defines ABSPATH, the database constants and $table_prefix;
 * wp-settings.php is then required once. The WordPress *test* bootstrap is deliberately NOT used —
 * tests/phpunit/includes/bootstrap.php shells out to install.php (line 266),
 * which drops every core table via $wpdb->tables() and reinstalls WordPress
 * (install.php lines 66-85). That destroyed the WordPress test users of the
 * parent suite's shared database (run 38419079529: CPMS fixtures intact, WP users
 * gone). No installer, no test framework, no migrations run here.
 */
try {
    ob_start();
    require $configPath;
    $coreDir = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : '';
    if ($coreDir === '' || !is_file($coreDir . '/wp-settings.php')) {
        $envCore = rtrim(cpms_race_env('WP_CORE_DIR'), '/');
        if ($envCore !== '' && is_file($envCore . '/wp-settings.php')) {
            $coreDir = $envCore;
            define('ABSPATH', $envCore . '/');
        }
    }
    if ($coreDir === '' || !is_file($coreDir . '/wp-settings.php')) {
        cpms_race_fail('CORE_UNAVAILABLE');
    }
    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', false);
    }
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true); // no cron spawn from a short-lived worker
    }
    require_once $coreDir . '/wp-settings.php';
    require_once $autoload;
    ob_end_clean();
} catch (\Throwable $bootstrapFailure) {
    cpms_race_fail('BOOTSTRAP_FAILED', $bootstrapFailure);
}

try {
    $clinicId     = (int) cpms_race_env('CPMS_RACE_CLINIC_ID');
    $definitionId = (int) cpms_race_env('CPMS_RACE_DEFINITION_ID');
    $managerId    = (int) cpms_race_env('CPMS_RACE_MANAGER_USER_ID');
    $targetUserId = (int) cpms_race_env('CPMS_RACE_TARGET_USER_ID');
    $roleKey      = cpms_race_env('CPMS_RACE_ROLE_KEY');
    $operation    = cpms_race_env('CPMS_RACE_OPERATION');
    $prefix       = cpms_race_env('CPMS_RACE_TABLE_PREFIX', 'wptests_');
    $lockTimeout  = max(1, (int) cpms_race_env('CPMS_RACE_LOCK_WAIT_TIMEOUT', '20'));

    if ($clinicId <= 0 || $definitionId <= 0 || $managerId <= 0 || $roleKey === '' || $operation === '') {
        cpms_race_fail('TASK_INCOMPLETE');
    }

    $own = new \wpdb(
        cpms_race_env('CPMS_RACE_DB_USER'),
        cpms_race_env('CPMS_RACE_DB_PASSWORD'),
        cpms_race_env('CPMS_RACE_DB_NAME'),
        cpms_race_env('CPMS_RACE_DB_HOST', '127.0.0.1')
    );
    $own->set_prefix($prefix);
    $own->suppress_errors(false);

    // The test-only SAVEPOINT query rewrite must never apply here: this worker's
    // transactions must be real commits visible to the parent's observer session.
    remove_all_filters('query');
    if (!$own->check_connection(false)) {
        cpms_race_fail('CONNECT_FAILED');
    }
    $own->query('SET SESSION innodb_lock_wait_timeout = ' . $lockTimeout);

    $db  = new \ClinicCore\Infrastructure\Db\CpmsDb($own);
    $id  = (int) $own->get_var('SELECT CONNECTION_ID()');
    if ($id <= 0) {
        cpms_race_fail('CONNECTION_IDENTITY_MISSING');
    }

    $definitionsTable = strtolower($db->table('cpms_custom_role_defs'));
    $predicate        = 'where id = ' . $definitionId . ' and clinic_id = ' . $clinicId;
    $signalSent       = false;
    add_filter('query', static function ($query) use ($id, $definitionsTable, $predicate, &$signalSent) {
        $sql = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $query)));
        if (!$signalSent && str_contains($sql, 'from ' . $definitionsTable . ' ')
            && str_contains($sql, $predicate) && preg_match('/\bfor\s+update\s*$/', $sql) === 1) {
            $signalSent = true;
            cpms_race_emit('LOCK_QUERY:' . $id . ':' . hash('sha256', $sql) . ':' . base64_encode($sql));
        }

        return $query;
    }, 0);

    cpms_race_emit('READY:' . $id);

    $memberships   = new \ClinicCore\Infrastructure\Repository\MembershipRepository($db);
    $roles         = new \ClinicCore\Infrastructure\Repository\CustomRoleRepository($db);
    $authorization = new \ClinicCore\Application\Authorization\AuthorizationService($memberships, $roles);
    $scope         = new \ClinicCore\Application\Scope\TrustedClinicEstablisher($db, $memberships);
    $audit         = new \ClinicCore\Infrastructure\Audit\AuditLogger(
        $db,
        new \ClinicCore\Infrastructure\Logging\OpLogger($db)
    );

    $outcome = 'unexpected-success';
    try {
        $db->transactional(function () use (
            $db, $operation, $roleKey, $managerId, $clinicId, $targetUserId,
            $scope, $authorization, $memberships, $roles, $audit, &$outcome
        ): void {
            try {
                if ($operation === 'assign') {
                    (new \ClinicCore\Application\Roles\CustomRoleAssignmentService(
                        $scope,
                        $authorization,
                        $memberships,
                        $roles,
                        $audit,
                        $db
                    ))->assign($managerId, $clinicId, $targetUserId, $roleKey);
                } else {
                    $service = new \ClinicCore\Application\Roles\CustomRoleManagementService(
                        $scope,
                        $authorization,
                        $roles,
                        $audit,
                        $db
                    );
                    match ($operation) {
                        'archive' => $service->archive($managerId, $clinicId, $roleKey, 1),
                        'rename' => $service->rename($managerId, $clinicId, $roleKey, 'Forbidden post-archive name'),
                        'capabilities' => $service->replace_capabilities($managerId, $clinicId, $roleKey, []),
                        default => throw new \RuntimeException('cpms-race-unknown-operation'),
                    };
                }
            } catch (\ClinicCore\Application\Roles\CustomRoleAssignmentException
                | \ClinicCore\Application\Roles\CustomRoleManagementException $denied) {
                $outcome = 'denied:' . $denied->reason_code();
            }
            // The waiter must stay reversible: its work is never committed.
            throw new \RuntimeException('intentional archival race rollback');
        });
    } catch (\RuntimeException $rollback) {
        if ($rollback->getMessage() !== 'intentional archival race rollback') {
            if ($rollback->getMessage() === 'cpms-race-unknown-operation') {
                cpms_race_fail('UNKNOWN_OPERATION');
            }
            cpms_race_fail('ROLLBACK_FAILED', $rollback);
        }
    }

    if (!$signalSent) {
        cpms_race_fail('LOCK_QUERY_MISSING');
    }

    cpms_race_emit('RESULT:' . $outcome);
    exit(0);
} catch (\Throwable $failure) {
    cpms_race_fail('UNEXPECTED', $failure);
}
