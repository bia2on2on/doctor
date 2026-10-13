<?php

declare(strict_types=1);

/**
 * Independent archival-race waiter — launched as its own PHP process by
 * CustomRoleArchivalConcurrencyTest through proc_open().
 *
 * Why a separate process instead of pcntl_fork():
 *   A fork inherits every live descriptor of the parent, including the parent's
 *   MySQL socket. The child then either queries that session (corrupting the
 *   parent's in-flight responses) or, on graceful exit, closes the inherited
 *   mysqli handle — which sends COM_QUIT on the socket the parent still holds and
 *   silently replaces the parent's session mid-transaction. Both shapes were
 *   observed in CI. A process started with proc_open() shares nothing: this
 *   worker opens its own MySQL connection, so the parent keeps ownership of its
 *   transaction and row lock no matter when or how the worker exits.
 *
 * Contract with the parent (stdout, one line per message; nothing else is ever
 * written to stdout — the WordPress test bootstrap output is buffered away):
 *   READY:<connection_id>                     own session established
 *   LOCK_QUERY:<conn_id>:<sha256>:<base64>    the real production locking query ran
 *   RESULT:<outcome>                          operation finished (exit 0)
 *   ERROR:<class>:<message>                   fatal, exit 1
 *
 * Parameters arrive through the environment, never argv — no credentials or
 * identifiers appear in the process list. The WordPress test bootstrap is loaded
 * for its wpdb/utility layer only; the plugin and its migrations are NOT loaded
 * (services are constructed explicitly, exactly as the test does), so the worker
 * cannot run migrations against the database the parent is locking.
 */

$pluginRoot = dirname(__DIR__, 2);

/** @return string */
function cpms_race_env(string $name, string $default = ''): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
}

/**
 * @param array<string, mixed> $payload
 */
function cpms_race_emit(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

$testsDir = rtrim(cpms_race_env('WP_TESTS_DIR', '/tmp/wordpress-tests-lib'), '/');
$configPath = cpms_race_env('WP_TESTS_CONFIG_FILE_PATH');
if ($configPath === '' && is_file($testsDir . '/wp-tests-config.php')) {
    $configPath = $testsDir . '/wp-tests-config.php';
}

if (!is_file($testsDir . '/includes/functions.php')) {
    cpms_race_emit('ERROR:RuntimeException:wp test suite not found');
    exit(1);
}
if ($configPath === '' || !is_file($configPath)) {
    cpms_race_emit('ERROR:RuntimeException:wp tests config not found');
    exit(1);
}
putenv('WP_TESTS_CONFIG_FILE_PATH=' . $configPath);

$autoload = $pluginRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    cpms_race_emit('ERROR:RuntimeException:plugin autoloader not found');
    exit(1);
}

try {
    // Buffer the bootstrap output: only protocol lines may reach stdout.
    ob_start();
    require_once $testsDir . '/includes/functions.php';
    require_once $testsDir . '/includes/bootstrap.php';
    require_once $autoload;
    ob_end_clean();
} catch (\Throwable $bootstrapFailure) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    cpms_race_emit('ERROR:' . get_class($bootstrapFailure) . ':' . $bootstrapFailure->getMessage());
    exit(1);
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
        throw new \RuntimeException('worker task parameters are incomplete');
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
        throw new \RuntimeException('worker could not open an independent database connection');
    }
    $own->query('SET SESSION innodb_lock_wait_timeout = ' . $lockTimeout);

    $db  = new \ClinicCore\Infrastructure\Db\CpmsDb($own);
    $id  = (int) $own->get_var('SELECT CONNECTION_ID()');
    if ($id <= 0) {
        throw new \RuntimeException('worker has no observable MySQL connection identity');
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
                        default => throw new \RuntimeException('unknown race operation'),
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
            throw $rollback;
        }
    }

    if (!$signalSent) {
        throw new \RuntimeException('waiter never executed the real target definition locking query');
    }

    cpms_race_emit('RESULT:' . $outcome);
    exit(0);
} catch (\Throwable $failure) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    cpms_race_emit('ERROR:' . get_class($failure) . ':' . $failure->getMessage());
    exit(1);
}
