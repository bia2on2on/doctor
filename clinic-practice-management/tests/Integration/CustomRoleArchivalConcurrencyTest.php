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

    private int $clinicId;
    private int $manager;
    private int $target;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::resetScope();
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
        parent::tearDown();
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
                &$worker, &$workerPid, &$workerExit, $tablePrefix, &$observer, &$childConnectionId, &$childQuery,
                &$waitEdge, &$waitAfterMutation, &$lockBefore, &$parentTransaction, &$childTransaction,
                &$liveConnectionId, &$selfTransaction): void {
                $locked = (new CustomRoleRepository($db))->definition_for_update($this->clinicId, $key);
                self::assertNotNull($locked);
                self::assertSame('active', $locked['status']);
                $observer = $this->freshMysqli();
                $lockBefore = $this->customRoleDefinitionLockForConnection($observer, $parentConnectionId, $definitionId);
                self::assertIsArray($lockBefore, 'winner must own the exact definition PRIMARY X record lock');

                $worker = ArchivalRaceWorker::launch([
                    'WP_TESTS_DIR' => getenv('WP_TESTS_DIR') ?: '/tmp/wp-tests',
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
                self::assertSame($lockBefore['lock_id'], $waitEdge['blocking_lock_id']);
                self::assertSame($lockBefore['transaction_id'], $waitEdge['blocking_transaction_id']);
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
            fwrite(STDERR, PHP_EOL . 'CUSTOM_ROLE_ARCHIVAL_CONCURRENCY_EVIDENCE ' . json_encode([
                'case' => $winner . '_before_' . $waiter,
                'mysql_schema' => DB_NAME,
                'definition_id' => $definitionId,
                'worker_mechanism' => 'proc_open-independent-process',
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
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        } finally {
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
