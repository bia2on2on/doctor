<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Roles\CustomRoleAssignmentException;
use ClinicCore\Application\Roles\CustomRoleAssignmentService;
use ClinicCore\Application\Roles\CustomRoleManagementException;
use ClinicCore\Application\Roles\CustomRoleManagementService;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Tests\Support\ArchivalRaceWorker;
use ClinicCore\Tests\Support\CustomRoleLockWaitFixture;
use RuntimeException;
use Throwable;
use WP_UnitTestCase;

require_once dirname(__DIR__) . '/Support/CustomRoleLockWaitFixture.php';
require_once dirname(__DIR__) . '/Support/ArchivalRaceWorker.php';

/**
 * Genuine independent MySQL sessions: both winner orders and both edit paths.
 *
 * The blocked waiter runs as its own PHP process launched with proc_open()
 * (tests/bin/custom-role-archival-race-worker.php). A pcntl_fork() child would
 * inherit the parent's live MySQL socket, so it could either query the parent's
 * session or destroy it on exit (COM_QUIT) — proven in CI runs 38382945515 and
 * 38402296436. A separately launched process inherits nothing.
 */
final class CustomRoleArchivalConcurrencyTest extends WP_UnitTestCase
{
    use CustomRoleLockWaitFixture;

    private int $clinicId = 0;
    private int $manager = 0;
    private int $target = 0;

    /** Clinic count before this test created its own Clinic. */
    private int $clinicCountBefore = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::resetScope();
        $this->clinicCountBefore = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics'));
        $organization = (int) App::db()->fetchValue('SELECT organization_id FROM ' . App::db()->table('cpms_clinics') . ' ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $organization);
        $this->clinicId = $this->makeClinic($organization, 'archive-race');
        $this->manager = $this->makeUser('arc_race_manager_' . bin2hex(random_bytes(3)));
        $this->target = $this->makeUser('arc_race_target_' . bin2hex(random_bytes(3)));
        App::membership_service()->create_membership($this->clinicId, $this->manager, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->create_membership($this->clinicId, $this->target, RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($this->manager);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        App::resetScope();
        $this->removeOwnFixtures();
        parent::tearDown();
    }

    /**
     * Removes exactly the durable fixtures this test created, and proves it.
     *
     * The fixtures are committed on purpose: an independent MySQL session can
     * only see and lock committed rows. That makes cleanup this test's own
     * responsibility — the shared suite database must look as it did before the
     * test, because SystemClinicResolver fails closed when a different number of
     * Clinic rows exists (run 38426092585: clinic_count=5, 417 errors in the
     * Integration suite that followed).
     *
     * Ownership is proven by row identity — the Clinic's own id, its
     * membership/definition/audit rows, and the two WordPress users this test
     * created with their usermeta — never by a global count alone. Every
     * statement is scoped to a key this test created; no broad DELETE or
     * TRUNCATE is performed, and unrelated rows are never touched. A failed
     * statement is reported separately, so a cleanup defect cannot be silent and
     * cannot hide the body's own result (PHPUnit reports both).
     */
    private function removeOwnFixtures(): void
    {
        if ($this->clinicId <= 0) {
            return;
        }
        global $wpdb;
        $db = App::db();
        $failures = [];

        // 1) The WordPress users this fixture created. Deleted first so no
        // membership can outlive its user; their usermeta goes with them.
        $userIds = array_values(array_filter(
            [$this->manager, $this->target],
            static fn (int $id): bool => $id > 0
        ));
        if ($userIds !== []) {
            if (!function_exists('wp_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            foreach ($userIds as $userId) {
                if (get_userdata($userId) !== false) {
                    wp_delete_user($userId);
                }
            }
        }

        // 2) Clinic-owned rows in foreign-key order: audit rows first
        // (cpms_audit_logs.clinic_id references the Clinic without a delete
        // cascade), then the definition (its capability rows cascade) and the
        // memberships (their capability/assignment rows cascade), then the Clinic.
        $statements = [
            'audit rows' => [
                'DELETE FROM ' . $db->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
                [$this->clinicId],
            ],
            'custom-role definitions' => [
                'DELETE FROM ' . $db->table('cpms_custom_role_defs') . ' WHERE clinic_id = %d',
                [$this->clinicId],
            ],
            'memberships' => [
                'DELETE FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
                [$this->clinicId],
            ],
            'clinic' => [
                'DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d',
                [$this->clinicId],
            ],
        ];
        foreach ($statements as $label => [$sql, $params]) {
            if (!$db->query($sql, $params)) {
                $failures[] = $label;
            }
        }

        // 3) Durable: the fixtures were committed, so the removal must be too —
        // the framework's own per-test transaction would otherwise roll the
        // deletes back while a count read inside it still looked clean
        // (run 38427517780: four cases, four Clinic rows left behind).
        if ($wpdb->query('COMMIT') === false) {
            $failures[] = 'commit';
        }
        if ($failures !== []) {
            self::fail(
                'archival race cleanup failed ('
                . implode(', ', $failures)
                . '); the test body result above is unaffected by this cleanup failure'
            );
        }

        // 4) Prove ownership row by row, by the keys this test created.
        $after = $this->ownershipRowCounts($db, $wpdb);
        self::assertSame(
            array_fill_keys(array_keys($after), 0),
            $after,
            'cleanup must remove every row this test owns (by key, not by count)'
        );
        foreach ($userIds as $userId) {
            self::assertFalse(
                get_userdata($userId) !== false,
                'tracked WordPress user ' . $userId . ' must be gone after cleanup'
            );
        }
        $remaining = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertSame(
            $this->clinicCountBefore,
            $remaining,
            'the archival race must leave the shared database exactly as it found it'
        );
    }

    /**
     * Every row this test owns, counted by the key it was created with.
     *
     * @return array<string, int>
     */
    private function ownershipRowCounts(CpmsDb $db, \wpdb $wpdb): array
    {
        $userIds = array_values(array_filter(
            [$this->manager, $this->target],
            static fn (int $id): bool => $id > 0
        ));
        $in = $userIds === [] ? '0' : implode(',', array_map('intval', $userIds));

        return [
            'clinic_rows' => (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d',
                [$this->clinicId]
            ),
            'definition_rows' => (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_custom_role_defs') . ' WHERE clinic_id = %d',
                [$this->clinicId]
            ),
            'definition_capability_rows' => (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_custom_role_capabilities')
                . ' WHERE role_def_id IN (SELECT id FROM ' . $db->table('cpms_custom_role_defs') . ' WHERE clinic_id = %d)',
                [$this->clinicId]
            ),
            'membership_rows' => (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
                [$this->clinicId]
            ),
            'audit_rows' => (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
                [$this->clinicId]
            ),
            'owned_user_rows' => (int) $db->fetchValue(
                'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'users` WHERE ID IN (' . $in . ')'
            ),
            'owned_usermeta_rows' => (int) $db->fetchValue(
                'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'usermeta` WHERE user_id IN (' . $in . ')'
            ),
        ];
    }

    public static function winnerCases(): array
    {
        return [
            'A archive wins; stale assignment denied' => ['archive', 'assign', 'CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_ACTIVE'],
            'B assignment wins; current committed reference refuses archive' => ['assign', 'archive', 'CUSTOM_ROLE_MEMBERSHIP_REFERENCES'],
            'C archive wins; legacy display-name edit denied' => ['archive', 'rename', 'CUSTOM_ROLE_TERMINAL_STATE'],
            'C archive wins; legacy capability replacement denied' => ['archive', 'capabilities', 'CUSTOM_ROLE_TERMINAL_STATE'],
        ];
    }

    /** @dataProvider winnerCases */
    public function testActualDefinitionWaitSerializesTerminalOperations(string $winner, string $waiter, string $expectedReason): void
    {
        self::assertTrue(ArchivalRaceWorker::available(), 'real concurrency is mandatory, never SKIPPED');
        self::assertTrue(class_exists('mysqli'));
        $db = App::db();
        $key = 'arc_race_' . bin2hex(random_bytes(3));
        $definitionId = (new CustomRoleRepository($db))->define($this->clinicId, $key, ['cpms_search'], 'Before race');
        $membership = (new MembershipRepository($db))->find($this->clinicId, $this->target);
        self::assertNotNull($membership);
        $membershipId = (int) $membership['id'];
        $wpRolesBefore = get_userdata($this->target)->roles;
        global $wpdb;
        self::assertNotFalse($wpdb->query('COMMIT'), 'independent sessions require committed fixtures');
        $tablePrefix = (string) $wpdb->prefix;
        $parentConnectionId = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
        $hooks = isset($GLOBALS['wp_filter']['query']) ? clone $GLOBALS['wp_filter']['query'] : null;
        $worker = null;
        $workerPid = 0;
        $startupProbeAfter = [];
        $isolationProbe = [];
        $privacyPins = [];
        $canary = '';
        $faultInjection = ['extension' => '', 'dir' => ''];
        $workerExit = -1;
        $observer = null;
        $childConnectionId = 0;
        $childQuery = '';
        $waitEdge = null;
        $waitAfterMutation = null;
        $lockBefore = null;
        $parentTransaction = null;
        $childTransaction = null;
        $liveConnectionId = 0;
        $selfTransaction = null;
        $outcome = '';
        remove_all_filters('query');
        try {
            $db->transactional(function () use ($db, $key, $definitionId, $winner, $waiter, $parentConnectionId,
                &$worker, &$workerPid, &$workerExit, $tablePrefix, &$startupProbeAfter,
                &$isolationProbe, &$privacyPins, &$canary, &$faultInjection,
                &$observer, &$childConnectionId, &$childQuery,
                &$waitEdge, &$waitAfterMutation, &$lockBefore, &$parentTransaction, &$childTransaction,
                &$liveConnectionId, &$selfTransaction): void {
                $locked = (new CustomRoleRepository($db))->definition_for_update($this->clinicId, $key);
                self::assertNotNull($locked);
                self::assertSame('active', $locked['status']);
                $observer = $this->freshMysqli();
                $lockBefore = $this->customRoleDefinitionLockForConnection($observer, $parentConnectionId, $definitionId);
                self::assertIsArray($lockBefore, 'winner must own the exact definition PRIMARY X record lock');

                // Startup must be non-destructive: probe the exact synthetic fixture
                // rows before the worker starts and again once it is ready.
                $startupProbeBefore = ArchivalRaceWorker::fixtureProbe(
                    $observer,
                    $tablePrefix,
                    $this->target,
                    $this->clinicId,
                    $definitionId
                );
                self::assertSame(
                    ['wp_user_rows' => 1, 'membership_rows' => 1, 'definition_rows' => 1],
                    $startupProbeBefore,
                    'fixture precondition: the exact synthetic rows must exist before the worker starts'
                );

                // Privacy probe. The canary is a secret-like marker that exists
                // only in this process's environment: no channel of the worker
                // may ever show it. The fault identifiers are controlled strings
                // used to force real failure paths inside the worker; they must
                // not reach the protocol or the evidence record either.
                $canary = 'cpms-canary-' . bin2hex(random_bytes(6));
                @putenv('CPMS_UNRELATED_SECRET=' . $canary);
                $_ENV['CPMS_UNRELATED_SECRET'] = $canary;
                $privacyPins = ArchivalRaceWorker::pinProcessEnvironment(array_values(array_diff(
                    ArchivalRaceWorker::PRIVACY_MARKER_VARS,
                    ['CPMS_UNRELATED_SECRET'] // keeps the canary live in this process at launch
                )));
                self::assertNotSame(
                    '',
                    (string) getenv('CPMS_UNRELATED_SECRET'),
                    'the canary must be present in this process when the worker starts'
                );
                $faultInjection = [
                    'extension' => '/nonexistent/cpms-fault-injection-' . bin2hex(random_bytes(4)),
                    'dir' => '/nonexistent/cpms-fault-content-' . bin2hex(random_bytes(4)),
                ];

                $worker = ArchivalRaceWorker::launch([
                    'WP_TESTS_DIR' => getenv('WP_TESTS_DIR') ?: '/tmp/wp-tests',
                    'CPMS_RACE_FAULT_INJECT' => json_encode($faultInjection, JSON_THROW_ON_ERROR),
                    'CPMS_RACE_DB_NAME' => DB_NAME,
                    'CPMS_RACE_DB_USER' => DB_USER,
                    'CPMS_RACE_DB_PASSWORD' => DB_PASSWORD,
                    'CPMS_RACE_DB_HOST' => DB_HOST,
                    'CPMS_RACE_TABLE_PREFIX' => $tablePrefix,
                    'CPMS_RACE_CLINIC_ID' => $this->clinicId,
                    'CPMS_RACE_ROLE_KEY' => $key,
                    'CPMS_RACE_DEFINITION_ID' => $definitionId,
                    'CPMS_RACE_MANAGER_USER_ID' => $this->manager,
                    'CPMS_RACE_TARGET_USER_ID' => $this->target,
                    'CPMS_RACE_OPERATION' => $waiter,
                    'CPMS_RACE_LOCK_WAIT_TIMEOUT' => 20,
                ]);
                $workerPid = $worker->pid();
                self::assertGreaterThan(0, $workerPid, 'the independent waiter must be a real process');
                self::assertNotSame(getmypid(), $workerPid, 'the waiter must never be this process');
                $ready = $worker->nextLine(40.0);
                self::assertIsString($ready, 'independent waiter never became ready: ' . $worker->diagnostics());
                self::assertMatchesRegularExpression('/^READY:[1-9][0-9]*$/', $ready);
                $childConnectionId = (int) substr($ready, strlen('READY:'));
                self::assertNotSame($parentConnectionId, $childConnectionId);

                // Descriptor isolation. The worker reported its own inventory as
                // its first line; every descriptor this process holds beyond
                // stdio must appear there as inert /dev/null, which is what the
                // launcher pinned them to, and the worker must hold no socket of
                // its own before it creates one.
                $isolationProbe = $worker->descriptorIsolationProbe();
                self::assertTrue($isolationProbe['proc_fd'], 'descriptor isolation needs /proc on this host to be proven, never assumed');
                self::assertGreaterThan(0, $isolationProbe['pinned_fd_count'], 'the launcher must hold descriptors to pin');
                self::assertTrue(
                    $isolationProbe['pinned_isolation_proven'],
                    'the launcher descriptors are not /dev/null inside the worker: ' . json_encode($isolationProbe)
                );
                self::assertSame(
                    0,
                    $isolationProbe['worker_socket_count'],
                    'the worker must start with no inherited socket: ' . json_encode($isolationProbe)
                );
                self::assertGreaterThan(
                    0,
                    $isolationProbe['parent_observed_fd_count'],
                    'the parent must be observed holding its own descriptors while the worker runs'
                );

                // Environment: exactly the allowlist, and none of the pinned
                // names — a forwarded name would be visible here even though its
                // value never leaves this process.
                self::assertNotEmpty($isolationProbe['launch_environment_keys'], 'the launcher must send a non-empty environment');
                self::assertSame(
                    $isolationProbe['launch_environment_keys'],
                    $isolationProbe['worker_env_keys'],
                    'the worker environment must be exactly what the launcher sent, never the launcher environment'
                );
                foreach ($isolationProbe['worker_env_keys'] as $envName) {
                    self::assertContains(
                        $envName,
                        ArchivalRaceWorker::ENVIRONMENT_ALLOWLIST,
                        'a non-allowlisted name reached the worker: ' . $envName
                    );
                }
                foreach (array_merge(array_keys($privacyPins), ['CPMS_UNRELATED_SECRET']) as $pinnedName) {
                    self::assertNotContains(
                        $pinnedName,
                        $isolationProbe['worker_env_keys'],
                        'a name this process holds reached the worker environment: ' . $pinnedName
                    );
                }

                $startupProbeAfter = ArchivalRaceWorker::fixtureProbe(
                    $observer,
                    $tablePrefix,
                    $this->target,
                    $this->clinicId,
                    $definitionId
                );
                self::assertSame(
                    $startupProbeBefore,
                    $startupProbeAfter,
                    'worker startup must not alter the WordPress user, its membership or the definition row'
                );
                $queryLine = $worker->nextLine(20.0);
                self::assertIsString($queryLine, 'waiter must signal its actual production locking query, not only method entry: ' . $worker->diagnostics());
                self::assertMatchesRegularExpression('/^LOCK_QUERY:[1-9][0-9]*:[a-f0-9]{64}:[A-Za-z0-9+\/=]+$/', $queryLine);
                $parts = explode(':', $queryLine, 4);
                self::assertSame($childConnectionId, (int) $parts[1]);
                $childQuery = base64_decode($parts[3], true);
                self::assertIsString($childQuery);
                self::assertSame($parts[2], hash('sha256', $childQuery));
                $deadline = microtime(true) + 10;
                do {
                    $waitEdge = $this->customRoleDefinitionWaitEdge($observer, $childConnectionId, $parentConnectionId, $definitionId);
                    if ($waitEdge !== null) {
                        break;
                    }
                    if (!$worker->isRunning()) {
                        self::fail('the independent waiter exited before the performance_schema wait edge was observed: ' . $worker->diagnostics());
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                self::assertIsArray($waitEdge, 'actual performance_schema.data_lock_waits edge is required: ' . json_encode([
                    'winner' => $winner, 'waiter' => $waiter, 'parent_connection_id' => $parentConnectionId,
                    'child_connection_id' => $childConnectionId, 'parent_locks' => $this->customRoleDefinitionLocksForConnection($observer, $parentConnectionId),
                    'child_locks' => $this->customRoleDefinitionLocksForConnection($observer, $childConnectionId),
                ], JSON_THROW_ON_ERROR));
                self::assertSame('WAITING', $waitEdge['requesting_lock_status']);
                self::assertSame('GRANTED', $waitEdge['blocking_lock_status']);
                self::assertSame((string) $definitionId, trim((string) $waitEdge['blocking_lock_data']));
                // MariaDB exposes no InnoDB lock id until a waiter exists; the
                // exact primary-key row ownership was proven behaviourally by
                // mariaDbGrantedDefinitionLock() and is bound to this engine's
                // transaction identity below. MySQL 8 still requires both ids.
                if (!str_starts_with((string) $lockBefore['lock_id'], 'mariadb:behavioural:')) {
                    self::assertSame($lockBefore['lock_id'], $waitEdge['blocking_lock_id']);
                    self::assertSame($lockBefore['transaction_id'], $waitEdge['blocking_transaction_id']);
                }
                // MariaDB binds the blocking transaction by thread id inside
                // the wait-edge query itself (blocking_trx.trx_mysql_thread_id
                // equals the winner connection captured above), so its internal
                // trx-id rendering is not compared across two InnoDB views.
                self::assertSame(
                    (string) $parentConnectionId,
                    (string) $waitEdge['blocking_connection_id']
                );
                self::assertNotSame($waitEdge['requesting_transaction_id'], $waitEdge['blocking_transaction_id']);
                $parentTransaction = $this->innodbTransactionForConnection($observer, $parentConnectionId);
                $childTransaction = $this->innodbTransactionForConnection($observer, $childConnectionId);
                // Runtime identity evidence (assert-only, no behaviour change): the
                // winner's own session must still be the session captured before the
                // fork, and it must still see its own transaction. This separates
                // "session replaced" from "transaction lost on the same session".
                $liveConnectionId = (int) $db->fetchValue('SELECT CONNECTION_ID()');
                $selfTransaction = $db->fetchRow(
                    'SELECT trx_id, trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID() LIMIT 1'
                );
                // The waiter is a separately launched process: it owns no descriptor
                // of this session, so the winner's session and its transaction must
                // survive the whole contention window — asserted, not merely recorded.
                self::assertSame($parentConnectionId, $liveConnectionId, 'the winner session must not be replaced during the race');
                self::assertIsArray($selfTransaction, 'the winner transaction must still exist when read on its own session');
                self::assertIsArray($parentTransaction);
                self::assertIsArray($childTransaction);
                self::assertSame('RUNNING', $parentTransaction['trx_state']);
                self::assertSame('LOCK WAIT', $childTransaction['trx_state']);

                // Execute the production winning operation while the other
                // production operation is already proven blocked on this row.
                $won = $this->operation($db, $winner, $key);
                self::assertTrue($won['changed']);
                $waitAfterMutation = $this->customRoleDefinitionWaitEdge($observer, $childConnectionId, $parentConnectionId, $definitionId);
                self::assertIsArray($waitAfterMutation, 'nested mutation/audit must not release the outer definition lock before commit');
                self::assertSame($waitEdge['blocking_lock_id'], $waitAfterMutation['blocking_lock_id']);
                self::assertSame($waitEdge['requesting_lock_id'], $waitAfterMutation['requesting_lock_id']);
            });
            // Winner status/membership and its audit are now committed. The
            // waiter must read current status/references, not its old snapshot.
            $workerExit = $worker instanceof ArchivalRaceWorker ? $worker->waitForExit(30.0) : -1;
            $waiterDiagnostics = $worker instanceof ArchivalRaceWorker ? $worker->diagnostics() : '';
            self::assertSame(0, $workerExit, 'independent waiter must exit cleanly (rc=' . $workerExit . '): ' . $waiterDiagnostics);
            $outcome = (string) ($worker instanceof ArchivalRaceWorker ? $worker->outcome() : '');
            self::assertNotSame('', $outcome, 'independent waiter reported no outcome (missing evidence is never success): ' . $waiterDiagnostics);
            self::assertSame('denied:' . $expectedReason, $outcome);
            $roleAfter = (new CustomRoleRepository($db))->definition_for($this->clinicId, $key);
            $memberAfter = (new MembershipRepository($db))->find($this->clinicId, $this->target);
            self::assertNotNull($roleAfter);
            self::assertNotNull($memberAfter);
            $archivalAudits = $this->archivalAuditCount($definitionId);
            $assignmentAudits = $this->customRoleAssignmentAuditCount($this->clinicId, $membershipId);
            if ($winner === 'archive') {
                self::assertSame('archived', $roleAfter['status']);
                self::assertSame(2, $roleAfter['version']);
                self::assertSame('Before race', $roleAfter['display_name']);
                self::assertSame(['cpms_search'], $roleAfter['capabilities']);
                self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, $memberAfter['role_key']);
                self::assertSame(1, $archivalAudits);
                self::assertSame(0, $assignmentAudits);
            } else {
                self::assertSame('active', $roleAfter['status']);
                self::assertSame(1, $roleAfter['version']);
                self::assertSame($key, $memberAfter['role_key']);
                self::assertSame(0, $archivalAudits);
                self::assertSame(1, $assignmentAudits);
            }
            clean_user_cache($this->target);
            self::assertSame($wpRolesBefore, get_userdata($this->target)->roles);
            // PHPUnit prints the previous test's progress dot without a trailing
            // newline, so a bare write would concatenate this record onto that
            // dot and the line-oriented CI collector would miss it.
            self::assertMatchesRegularExpression('/^denied:[A-Z_]+$/', $outcome);
            self::assertStringNotContainsString($canary, $waiterDiagnostics, 'the canary must never reach the worker at all');
            $evidence = json_encode([
                'case' => $winner . '_before_' . $waiter,
                'mysql_schema' => DB_NAME,
                'definition_id' => $definitionId,
                'worker_mechanism' => 'proc_open-independent-process',
                'worker_bootstrap' => 'wp-config constants + wp-settings.php (no test installer)',
                'startup_fixture_probe' => $startupProbeAfter,
                'worker_pid' => $workerPid,
                'worker_exit_code' => $workerExit,
                'parent_connection_id' => $parentConnectionId,
                'child_connection_id' => $childConnectionId,
                'parent_connection_id_live_on_own_session' => $liveConnectionId,
                'parent_transaction_visible_on_own_session' => is_array($selfTransaction),
                'parent_transaction_state' => $parentTransaction['trx_state'],
                'child_transaction_state' => $childTransaction['trx_state'],
                'waiter_lock_query' => $childQuery,
                'waiter_lock_query_sha256' => hash('sha256', $childQuery),
                'wait_edge' => $waitEdge,
                'same_wait_edge_through_mutation_and_audit_before_commit' => $waitAfterMutation !== null,
                'child_outcome_after_parent_commit' => $outcome,
                'definition_status_after_commit' => $roleAfter['status'],
                'definition_version_after_commit' => $roleAfter['version'],
                'membership_role_after_commit' => $memberAfter['role_key'],
                'archival_audit_count' => $archivalAudits,
                'assignment_audit_count' => $assignmentAudits,
                'descriptor_isolation' => $isolationProbe,
                'environment_allowlist_verified' => true,
                'privacy_marker_absent' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($canary, $evidence, 'no marker value may enter the evidence record');
            foreach ($faultInjection as $faultMarker) {
                self::assertStringNotContainsString(
                    $faultMarker,
                    $evidence . $childQuery . $outcome,
                    'controlled fault identifiers must not leak into the protocol or the evidence record'
                );
            }
            fwrite(STDERR, PHP_EOL . 'CUSTOM_ROLE_ARCHIVAL_CONCURRENCY_EVIDENCE ' . $evidence . PHP_EOL);
        } finally {
            if ($privacyPins !== []) {
                ArchivalRaceWorker::restoreProcessEnvironment($privacyPins);
            }
            if ($observer instanceof \mysqli && $childConnectionId > 0) {
                $observer->query('KILL ' . $childConnectionId);
            }
            if ($worker instanceof ArchivalRaceWorker) {
                $worker->terminate(); // bounded SIGTERM→SIGKILL + reap: no orphaned waiter
            }
            if ($observer instanceof \mysqli) {
                $observer->close();
            }
            if ($hooks !== null) {
                $GLOBALS['wp_filter']['query'] = $hooks;
            } else {
                unset($GLOBALS['wp_filter']['query']);
            }
            $this->cleanupCommittedRaceFixtures([$this->clinicId], [$this->manager, $this->target], $definitionId);
        }
    }

    private function operation(CpmsDb $db, string $operation, string $key): array
    {
        $memberships = new MembershipRepository($db);
        $roles = new CustomRoleRepository($db);
        $authorization = new AuthorizationService($memberships, $roles);
        $scope = new TrustedClinicEstablisher($db, $memberships);
        $audit = new AuditLogger($db, new OpLogger($db));
        if ($operation === 'assign') {
            return (new CustomRoleAssignmentService($scope, $authorization, $memberships, $roles, $audit, $db))->assign($this->manager, $this->clinicId, $this->target, $key);
        }
        $service = new CustomRoleManagementService($scope, $authorization, $roles, $audit, $db);
        return match ($operation) {
            'archive' => $service->archive($this->manager, $this->clinicId, $key, 1),
            'rename' => $service->rename($this->manager, $this->clinicId, $key, 'Forbidden post-archive name'),
            'capabilities' => $service->replace_capabilities($this->manager, $this->clinicId, $key, []),
            default => throw new RuntimeException('unknown test race operation'),
        };
    }

    private function archivalAuditCount(int $definitionId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d AND action = %s AND resource_type = %s AND resource_id = %d', [$this->clinicId, CustomRoleManagementService::AUDIT_ARCHIVED, CustomRoleManagementService::RESOURCE_TYPE, $definitionId]);
    }
}
