<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CustomRoleDefinitionsPage;
use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Roles\CustomRoleManagementException;
use ClinicCore\Application\Roles\CustomRoleManagementService;
use ClinicCore\Application\Roles\CustomRoleAssignmentService;
use ClinicCore\Application\Roles\CustomRoleAssignmentException;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use RuntimeException;
use Throwable;
use WP_UnitTestCase;
use ClinicCore\Tests\Support\CustomRoleLockWaitFixture;

require_once dirname(__DIR__) . '/Support/CustomRoleLockWaitFixture.php';

/**
 * Custom Role Management — Slice 4A, reversible deactivation/reactivation.
 *
 * This is integration coverage against the real WordPress/MySQL-backed
 * AuthorizationService, repositories, transaction wrapper, audit log and
 * server-rendered wp-admin surface. It intentionally does not model archive or
 * deletion: existing membership role keys, capabilities and audit history are
 * retained by deactivation.
 */
final class CustomRoleLifecycleTest extends WP_UnitTestCase
{
    use CustomRoleLockWaitFixture;

    private const RACE_SIGNAL_TIMEOUT_SECONDS = 10;
    private const RACE_WAIT_TIMEOUT_SECONDS = 10;
    private const RACE_CHILD_LOCK_WAIT_TIMEOUT_SECONDS = 20;
    private const RACE_POLL_INTERVAL_MICROSECONDS = 20000;
    private int $clinicA;
    private int $clinicB;
    private int $managerA;
    private int $managerB;
    private int $suspendedManager;
    private int $nonMember;
    private int $member;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::resetScope();

        global $wpdb;
        $organizationId = (int) $wpdb->get_var(
            'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertGreaterThan(0, $organizationId, 'an active Organization fixture must exist');

        $this->clinicA = $this->makeClinic($organizationId, 'crlA');
        $this->clinicB = $this->makeClinic($organizationId, 'crlB');
        $this->managerA = $this->makeUser('crl_mgr_a_' . bin2hex(random_bytes(3)));
        $this->managerB = $this->makeUser('crl_mgr_b_' . bin2hex(random_bytes(3)));
        $this->suspendedManager = $this->makeUser('crl_mgr_sus_' . bin2hex(random_bytes(3)));
        $this->nonMember = $this->makeUser('crl_non_' . bin2hex(random_bytes(3)));
        $this->member = $this->makeUser('crl_member_' . bin2hex(random_bytes(3)));

        App::membership_service()->create_membership($this->clinicA, $this->managerA, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->create_membership($this->clinicB, $this->managerB, RolesAndCapabilities::ROLE_MANAGER);
        $suspendedId = App::membership_service()->create_membership($this->clinicA, $this->suspendedManager, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->suspend_membership($suspendedId);
        App::membership_service()->create_membership($this->clinicA, $this->member, RolesAndCapabilities::ROLE_SECRETARY);

        wp_set_current_user($this->managerA);
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        wp_set_current_user(0);
        App::resetScope();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Authorized deactivation: status only; memberships and explicit effects
    // remain in place, no WordPress-global role mutation.
    // ---------------------------------------------------------------------

    public function testAuthorizedManagerDeactivatesRoleWithoutChangingMembershipOverridesOrWordPressRoles(): void
    {
        $role = $this->createRole('crl_staff', ['cpms_patient_read', 'cpms_search']);
        $membershipRepository = new MembershipRepository(App::db());
        $memberMembership = $membershipRepository->find($this->clinicA, $this->member);
        self::assertNotNull($memberMembership);
        $membershipId = (int) $memberMembership['id'];
        $membershipRepository->update(['role_key' => 'crl_staff'], ['id' => $membershipId]);
        $membershipRepository->set_capability($membershipId, 'cpms_patient_read', 'deny');
        $membershipRepository->set_capability($membershipId, 'cpms_report_read', 'grant');
        $beforeOverrides = $membershipRepository->capabilities_for($membershipId);
        $beforeRoles = (array) get_userdata($this->member)->roles;

        self::assertTrue(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_search'));
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_patient_read'), 'membership DENY outranks the active role');
        self::assertTrue(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_report_read'), 'an explicit GRANT is independent of the role');

        $result = $this->deactivate($this->managerA, $this->clinicA, 'crl_staff', 1);

        self::assertSame('inactive', $result['status']);
        self::assertSame(2, $result['version']);
        self::assertTrue($result['changed']);
        self::assertTrue(App::authorization_service()->can($this->managerA, $this->clinicA, RolesAndCapabilities::CONFIG));
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_search'), 'inactive definitions supply no role-derived authority');
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_patient_read'), 'the explicit DENY remains effective');
        self::assertTrue(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_report_read'), 'the explicit GRANT remains governed by AuthorizationService');

        $storedRole = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_staff');
        self::assertNotNull($storedRole, 'deactivation preserves the custom role definition');
        self::assertSame($role['id'], $storedRole['id']);
        self::assertSame(['cpms_patient_read', 'cpms_search'], $storedRole['capabilities']);
        self::assertSame('crl_staff', (string) App::db()->fetchValue(
            'SELECT role_key FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE id = %d',
            [$membershipId]
        ), 'deactivation must retain the existing membership role key');
        self::assertSame($beforeOverrides, $membershipRepository->capabilities_for($membershipId), 'GRANT/DENY rows must be unchanged');
        self::assertSame($beforeRoles, (array) get_userdata($this->member)->roles, 'no WordPress-global role may be changed');
        self::assertSame(1, $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', (int) $role['id']));
    }

    public function testReactivationRestoresOnlyRoleDerivedAuthorityAndKeepsExplicitDenyPrecedence(): void
    {
        $this->createRole('crl_restore', ['cpms_search', 'cpms_patient_read']);
        $membershipRepository = new MembershipRepository(App::db());
        $memberMembership = $membershipRepository->find($this->clinicA, $this->member);
        self::assertNotNull($memberMembership);
        $membershipId = (int) $memberMembership['id'];
        $membershipRepository->update(['role_key' => 'crl_restore'], ['id' => $membershipId]);
        $membershipRepository->set_capability($membershipId, 'cpms_search', 'deny');

        $this->deactivate($this->managerA, $this->clinicA, 'crl_restore', 1);
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_patient_read'));
        $result = $this->reactivate($this->managerA, $this->clinicA, 'crl_restore', 2);

        self::assertSame('active', $result['status']);
        self::assertSame(3, $result['version']);
        self::assertTrue($result['changed']);
        self::assertTrue(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_patient_read'), 'only the persisted role-derived capability returns');
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_search'), 'an explicit DENY still has precedence');
        self::assertSame(1, $this->statusAuditCount('CUSTOM_ROLE_REACTIVATED', (int) $this->definitionId('crl_restore')));
    }

    public function testReactivationRechecksTheActorsCurrentCapabilityDelegationAuthority(): void
    {
        $role = $this->createRole('crl_fresh', ['cpms_patient_read']);
        $this->deactivate($this->managerA, $this->clinicA, 'crl_fresh', 1);
        $managerMembership = (new MembershipRepository(App::db()))->find_active($this->clinicA, $this->managerA);
        self::assertNotNull($managerMembership);
        $memberships = new MembershipRepository(App::db());
        $memberships->set_capability((int) $managerMembership['id'], 'cpms_patient_read', 'deny');
        $auditBefore = $this->statusAuditCount('CUSTOM_ROLE_REACTIVATED', (int) $role['id']);

        $this->expectLifecycleFailure('reactivate', $this->managerA, $this->clinicA, 'crl_fresh', 2, CustomRoleManagementException::class, 'CUSTOM_ROLE_CAPABILITY_NOT_DELEGABLE');

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_fresh');
        self::assertNotNull($stored);
        self::assertSame('inactive', $stored['status']);
        self::assertSame(2, $stored['version'], 'a refused reactivation cannot advance the version');
        self::assertSame($auditBefore, $this->statusAuditCount('CUSTOM_ROLE_REACTIVATED', (int) $role['id']));

        $memberships->remove_capability((int) $managerMembership['id'], 'cpms_patient_read');
        $restored = $this->reactivate($this->managerA, $this->clinicA, 'crl_fresh', 2);
        self::assertSame('active', $restored['status']);
        self::assertSame(3, $restored['version']);
    }

    public function testUnauthorizedForeignClinicSuspendedAndNonmemberActorsCannotChangeStatus(): void
    {
        $roleA = $this->createRole('crl_local', ['cpms_search']);
        $this->service()->create($this->managerB, $this->clinicB, 'crl_foreign', 'Foreign role', ['cpms_search']);

        $this->expectLifecycleFailure('deactivate', $this->member, $this->clinicA, 'crl_local', 1, AuthorizationException::class);
        $this->expectLifecycleFailure('deactivate', $this->nonMember, $this->clinicA, 'crl_local', 1, ScopeRequiredException::class);
        $this->expectLifecycleFailure('deactivate', $this->suspendedManager, $this->clinicA, 'crl_local', 1, ScopeRequiredException::class);
        $this->expectLifecycleFailure('deactivate', $this->managerA, $this->clinicB, 'crl_foreign', 1, ScopeRequiredException::class);

        $storedA = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_local');
        $storedB = (new CustomRoleRepository(App::db()))->definition_for($this->clinicB, 'crl_foreign');
        self::assertNotNull($storedA);
        self::assertNotNull($storedB);
        self::assertSame('active', $storedA['status']);
        self::assertSame(1, $storedA['version']);
        self::assertSame('active', $storedB['status'], 'a Clinic-A actor cannot change a Clinic-B definition');
        self::assertSame(1, $storedB['version']);
        self::assertSame(0, $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', (int) $roleA['id']));
    }

    public function testBuiltInRoleKeysAreNotAddressableThroughCustomRoleLifecycle(): void
    {
        $this->expectLifecycleFailure(
            'deactivate',
            $this->managerA,
            $this->clinicA,
            RolesAndCapabilities::ROLE_MANAGER,
            1,
            CustomRoleManagementException::class,
            CustomRoleManagementException::CODE_INVALID_ROLE_KEY
        );
        self::assertTrue(App::authorization_service()->can($this->managerA, $this->clinicA, RolesAndCapabilities::CONFIG));
    }

    public function testRepeatedSameStatusRequestsAreNoOpsAndStaleVersionFailsSafely(): void
    {
        $role = $this->createRole('crl_idempotent', ['cpms_search']);
        $first = $this->deactivate($this->managerA, $this->clinicA, 'crl_idempotent', 1);
        $auditAfterFirst = $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', (int) $role['id']);
        $second = $this->deactivate($this->managerA, $this->clinicA, 'crl_idempotent', 2);

        self::assertTrue($first['changed']);
        self::assertFalse($second['changed']);
        self::assertSame(2, $second['version']);
        self::assertSame($auditAfterFirst, $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', (int) $role['id']));

        $this->expectLifecycleFailure('reactivate', $this->managerA, $this->clinicA, 'crl_idempotent', 1, CustomRoleManagementException::class, 'CUSTOM_ROLE_STALE_VERSION');
        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_idempotent');
        self::assertNotNull($stored);
        self::assertSame('inactive', $stored['status']);
        self::assertSame(2, $stored['version']);
        self::assertSame(0, $this->statusAuditCount('CUSTOM_ROLE_REACTIVATED', (int) $role['id']));

        $active = $this->reactivate($this->managerA, $this->clinicA, 'crl_idempotent', 2);
        $activeNoOp = $this->reactivate($this->managerA, $this->clinicA, 'crl_idempotent', 3);
        self::assertTrue($active['changed']);
        self::assertFalse($activeNoOp['changed']);
        self::assertSame(3, $activeNoOp['version']);
        self::assertSame(1, $this->statusAuditCount('CUSTOM_ROLE_REACTIVATED', (int) $role['id']));
    }

    public function testAuditPersistenceFailureRollsBackStatusAndVersion(): void
    {
        $role = $this->createRole('crl_audit', ['cpms_search']);
        $this->failAuditInserts();
        $thrown = null;
        try {
            $this->deactivate($this->managerA, $this->clinicA, 'crl_audit', 1);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            $this->restoreAuditInserts();
        }

        self::assertNotNull($thrown, 'a failed audit INSERT must fail the lifecycle operation');
        self::assertStringContainsString('audit', strtolower($thrown->getMessage()));
        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_audit');
        self::assertNotNull($stored);
        self::assertSame('active', $stored['status']);
        self::assertSame(1, $stored['version']);
        self::assertSame(0, $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', (int) $role['id']));
    }

    // ---------------------------------------------------------------------
    // Genuine MySQL concurrency proof: independent WPDB connections and a
    // committed fixture, verified with the exact Performance Schema wait-edge
    // pattern used by SchedulingLocationLocalBoundaryRedTest.
    // ---------------------------------------------------------------------

    public function testAssignmentWaitsForLockedDefinitionAndRevalidatesAfterDeactivation(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair') || !class_exists('mysqli')) {
            self::markTestSkipped('Requires pcntl, stream_socket_pair, and MySQLi for independent MySQL connections.');
        }

        // Keep the repository-seeded definition free of a create-audit row. The
        // target is an existing active membership, as the assignment contract requires.
        $raceRoleKey = 'crl_race_' . bin2hex(random_bytes(3));
        $definitionId = (new CustomRoleRepository(App::db()))->define($this->clinicA, $raceRoleKey, ['cpms_search']);
        $targetUser = $this->makeUser('crl_race_target_' . bin2hex(random_bytes(3)));
        $targetMembershipId = App::membership_service()->create_membership(
            $this->clinicA,
            $targetUser,
            RolesAndCapabilities::ROLE_SECRETARY
        );
        self::assertGreaterThan(0, $targetMembershipId);
        $clinicId = $this->clinicA;
        $managerId = $this->managerA;
        $targetWpUserBefore = get_userdata($targetUser);
        self::assertInstanceOf(\WP_User::class, $targetWpUserBefore);
        $targetWpRolesBefore = (array) $targetWpUserBefore->roles;

        // A forked connection cannot observe the WP test transaction's uncommitted
        // fixtures. Commit them explicitly and clean only these fixture rows below.
        global $wpdb;
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $parentConnectionId = (int) App::db()->wpdb()->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        self::assertGreaterThan(0, $parentConnectionId);

        $childPid = 0;
        $childReaped = false;
        $childStatus = 0;
        $childConnectionId = 0;
        $childOutcome = '';
        $childQueryFingerprint = '';
        $childLockQuery = '';
        $parentPipe = null;
        $observer = null;
        $mysqlVersion = '';
        $parentLockBefore = null;
        $parentLockAfter = null;
        $waitEvidence = null;
        $waitEvidenceAfterTransition = null;
        $parentTransactionWhileWaiting = null;
        $childTransactionWhileWaiting = null;
        $parentTransactionAfterTransition = null;
        $childTransactionAfterTransition = null;
        $targetRoleAfter = '';
        $definitionStatusAfter = '';
        $targetWpRolesAfter = [];
        $deactivationAuditCount = 0;
        $assignmentAuditCount = 0;
        $queryHooks = isset($GLOBALS['wp_filter']['query']) ? clone $GLOBALS['wp_filter']['query'] : null;

        // Keep the race independent of the test-bootstrap query shims. B installs
        // a one-shot observer for its actual target locking query below.
        remove_all_filters('query');

        try {
            App::db()->transactional(function () use (
                $clinicId,
                $definitionId,
                $managerId,
                $targetUser,
                $raceRoleKey,
                $parentConnectionId,
                &$childPid,
                &$childReaped,
                &$childStatus,
                &$childConnectionId,
                &$childQueryFingerprint,
                &$childLockQuery,
                &$parentPipe,
                &$observer,
                &$mysqlVersion,
                &$parentLockBefore,
                &$parentLockAfter,
                &$waitEvidence,
                &$waitEvidenceAfterTransition,
                &$parentTransactionWhileWaiting,
                &$childTransactionWhileWaiting,
                &$parentTransactionAfterTransition,
                &$childTransactionAfterTransition
            ): void {
                $activeParentConnectionId = (int) App::db()->wpdb()->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                self::assertSame($parentConnectionId, $activeParentConnectionId, 'The locking transaction must remain on the captured parent connection.');

                $lockedDefinition = App::db()->fetchRowForUpdate(
                    'SELECT id, status, version FROM ' . App::db()->table('cpms_custom_role_defs') . ' WHERE id = %d AND clinic_id = %d',
                    [$definitionId, $clinicId]
                );
                self::assertIsArray($lockedDefinition);
                self::assertSame('active', $lockedDefinition['status']);

                // Observe from a third, independent connection. This proves A
                // already owns the exact primary-key record before B is forked.
                $observer = $this->freshMysqli();
                $versionResult = $observer->query('SELECT VERSION()');
                if ($versionResult === false) {
                    throw new RuntimeException('Could not read the CI MySQL server version: ' . $observer->error);
                }
                $versionRow = $versionResult->fetch_row();
                $versionResult->free();
                $mysqlVersion = (string) ($versionRow[0] ?? 'unknown');
                $parentLockBefore = $this->customRoleDefinitionLockForConnection(
                    $observer,
                    $parentConnectionId,
                    $definitionId
                );
                self::assertIsArray(
                    $parentLockBefore,
                    'Parent A must hold a granted X record lock on the exact custom-role definition row before child B starts.'
                );

                $pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if ($pipes === false) {
                    throw new RuntimeException('Could not create the parent/child synchronization pipe.');
                }

                $childPid = pcntl_fork();
                if ($childPid === -1) {
                    fclose($pipes[0]);
                    fclose($pipes[1]);
                    throw new RuntimeException('Could not fork the independent MySQL assignment connection.');
                }

                if ($childPid === 0) {
                    fclose($pipes[0]);
                    $childPipe = $pipes[1];
                    $wpdb2 = null;
                    try {
                        $wpdb2 = $this->freshWpdb();
                        $connectionId = (int) $wpdb2->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        if ($connectionId <= 0) {
                            throw new RuntimeException('Child B did not receive a valid MySQL connection id.');
                        }

                        // B is a separate WPDB/MySQL session; remove inherited test
                        // query shims, but keep the production locking query intact.
                        remove_all_filters('query');
                        if ($wpdb2->query('SET SESSION innodb_lock_wait_timeout = ' . self::RACE_CHILD_LOCK_WAIT_TIMEOUT_SECONDS) === false) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                            throw new RuntimeException('Could not bound child B\'s InnoDB row-lock wait: ' . $wpdb2->last_error);
                        }
                        $db2 = new CpmsDb($wpdb2);
                        $memberships2 = new MembershipRepository($db2);
                        $roles2 = new CustomRoleRepository($db2);
                        $authorization2 = new AuthorizationService($memberships2, $roles2);
                        $assignmentService = new CustomRoleAssignmentService(
                            new TrustedClinicEstablisher($db2, $memberships2),
                            $authorization2,
                            $memberships2,
                            $roles2,
                            new AuditLogger($db2, new OpLogger($db2)),
                            $db2
                        );
                        $roleDefinitionsTable = strtolower($db2->table('cpms_custom_role_defs'));
                        $lockQuerySent = false;

                        // Signal only when B is about to execute the production
                        // target-row SELECT ... FOR UPDATE, not merely before assign().
                        add_filter(
                            'query',
                            static function ($query) use (
                                $childPipe,
                                $connectionId,
                                $roleDefinitionsTable,
                                $definitionId,
                                $clinicId,
                                &$lockQuerySent
                            ) {
                                $sql = strtolower(trim((string) preg_replace('/\s+/', ' ', trim((string) $query))));
                                $expectedPredicate = 'where id = ' . $definitionId . ' and clinic_id = ' . $clinicId;
                                if (
                                    !$lockQuerySent
                                    && str_contains($sql, 'from ' . $roleDefinitionsTable . ' ')
                                    && str_contains($sql, $expectedPredicate)
                                    && preg_match('/\bfor\s+update\s*$/', $sql) === 1
                                ) {
                                    $lockQuerySent = true;
                                    $fingerprint = hash('sha256', $sql);
                                    $queryBase64 = base64_encode($sql);
                                    fwrite($childPipe, 'LOCK_QUERY:' . $connectionId . ':' . $fingerprint . ':' . $queryBase64 . "\n");
                                    fflush($childPipe);
                                }

                                return $query;
                            },
                            0
                        );

                        $outcome = 'assigned';
                        try {
                            // Keep an unexpected success reversible; the production
                            // service itself still owns its atomic savepoint/audit path.
                            $db2->transactional(function () use (
                                $assignmentService,
                                $managerId,
                                $clinicId,
                                $targetUser,
                                $raceRoleKey,
                                $childPipe,
                                $connectionId,
                                &$outcome
                            ): void {
                                fwrite($childPipe, 'READY:' . $connectionId . "\n");
                                fflush($childPipe);
                                try {
                                    $assignmentService->assign($managerId, $clinicId, $targetUser, $raceRoleKey);
                                    $outcome = 'assigned';
                                } catch (CustomRoleAssignmentException $exception) {
                                    $outcome = 'denied:' . $exception->reason_code();
                                }

                                throw new RuntimeException('intentional race-fixture rollback');
                            });
                        } catch (RuntimeException $exception) {
                            if ($exception->getMessage() !== 'intentional race-fixture rollback') {
                                throw $exception;
                            }
                        }

                        if (!$lockQuerySent) {
                            $outcome = 'missing-target-lock-query:' . $outcome;
                        }
                        fwrite($childPipe, 'RESULT:' . $outcome . "\n");
                        fflush($childPipe);
                        if ($wpdb2 instanceof \wpdb) {
                            $wpdb2->close();
                        }
                        fclose($childPipe);
                        exit(0);
                    } catch (Throwable $exception) {
                        fwrite($childPipe, 'ERROR:' . get_class($exception) . ':' . $exception->getMessage() . "\n");
                        fflush($childPipe);
                        if ($wpdb2 instanceof \wpdb) {
                            $wpdb2->close();
                        }
                        fclose($childPipe);
                        exit(1);
                    }
                }

                fclose($pipes[1]);
                $parentPipe = $pipes[0];

                $readyLine = $this->readRaceLine($parentPipe, self::RACE_SIGNAL_TIMEOUT_SECONDS);
                self::assertIsString($readyLine, 'Child B did not report its independent connection as ready within the bounded timeout.');
                self::assertMatchesRegularExpression('/^READY:[1-9][0-9]*$/', $readyLine);
                $childConnectionId = (int) substr($readyLine, strlen('READY:'));
                self::assertNotSame($parentConnectionId, $childConnectionId, 'A and B must use genuinely independent MySQL connections.');

                $queryLine = $this->readRaceLine($parentPipe, self::RACE_SIGNAL_TIMEOUT_SECONDS);
                if (!is_string($queryLine)) {
                    $diagnostics = [
                        'mysql_version' => $mysqlVersion,
                        'parent_connection_id' => $parentConnectionId,
                        'child_connection_id' => $childConnectionId,
                        'parent_lock_before_child' => $parentLockBefore,
                        'parent_transaction' => $this->innodbTransactionForConnection($observer, $parentConnectionId),
                        'child_transaction' => $this->innodbTransactionForConnection($observer, $childConnectionId),
                        'parent_definition_locks' => $this->customRoleDefinitionLocksForConnection($observer, $parentConnectionId),
                        'child_definition_locks' => $this->customRoleDefinitionLocksForConnection($observer, $childConnectionId),
                        'matching_wait_edge' => $this->customRoleDefinitionWaitEdge(
                            $observer,
                            $childConnectionId,
                            $parentConnectionId,
                            $definitionId
                        ),
                    ];
                    self::fail(
                        'Child B did not signal its target assignment SELECT ... FOR UPDATE within the bounded timeout. Evidence: '
                        . json_encode($diagnostics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    );
                }
                self::assertMatchesRegularExpression('/^LOCK_QUERY:[1-9][0-9]*:[a-f0-9]{64}:[A-Za-z0-9+\/=]+$/', $queryLine);
                $queryParts = explode(':', $queryLine, 4);
                self::assertSame($childConnectionId, (int) $queryParts[1], 'The query-arrival signal must come from the same child connection observed by MySQL.');
                $childQueryFingerprint = $queryParts[2];
                $decodedQuery = base64_decode($queryParts[3], true);
                self::assertIsString($decodedQuery, 'The child must provide its normalized locking SQL for evidence.');
                $childLockQuery = $decodedQuery;
                self::assertSame($childQueryFingerprint, hash('sha256', $childLockQuery));
                self::assertStringContainsString(
                    'from ' . strtolower(App::db()->table('cpms_custom_role_defs')) . ' ',
                    $childLockQuery
                );
                self::assertStringContainsString(
                    'where id = ' . $definitionId . ' and clinic_id = ' . $clinicId,
                    $childLockQuery
                );
                self::assertStringEndsWith('for update', $childLockQuery);

                // Poll current Performance Schema state. The interval only
                // throttles observation; success requires the exact lock-wait edge.
                $deadline = microtime(true) + self::RACE_WAIT_TIMEOUT_SECONDS;
                do {
                    $waitEvidence = $this->customRoleDefinitionWaitEdge(
                        $observer,
                        $childConnectionId,
                        $parentConnectionId,
                        $definitionId
                    );
                    if ($waitEvidence !== null) {
                        break;
                    }

                    $waited = pcntl_waitpid($childPid, $childStatus, WNOHANG);
                    if ($waited === $childPid) {
                        $childReaped = true;
                        break;
                    }
                    if ($waited === -1) {
                        throw new RuntimeException('Could not inspect the child process while awaiting the MySQL lock edge.');
                    }

                    usleep(self::RACE_POLL_INTERVAL_MICROSECONDS);
                } while (microtime(true) < $deadline);

                if ($waitEvidence === null) {
                    $diagnostics = [
                        'mysql_version' => $mysqlVersion,
                        'parent_connection_id' => $parentConnectionId,
                        'child_connection_id' => $childConnectionId,
                        'child_lock_query_sha256' => $childQueryFingerprint,
                        'parent_lock_before_child' => $parentLockBefore,
                        'parent_transaction' => $this->innodbTransactionForConnection($observer, $parentConnectionId),
                        'child_transaction' => $this->innodbTransactionForConnection($observer, $childConnectionId),
                        'parent_definition_locks' => $this->customRoleDefinitionLocksForConnection($observer, $parentConnectionId),
                        'child_definition_locks' => $this->customRoleDefinitionLocksForConnection($observer, $childConnectionId),
                        'wait_edge' => null,
                    ];
                    self::fail(
                        'Did not observe B blocked by A on the exact custom-role definition row before the bounded deadline. Evidence: '
                        . json_encode($diagnostics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    );
                }

                self::assertSame((string) $childConnectionId, (string) $waitEvidence['requesting_connection_id']);
                self::assertSame((string) $parentConnectionId, (string) $waitEvidence['blocking_connection_id']);
                self::assertTrue(
                    $waitEvidence['requesting_lock_data'] === null
                    || trim((string) $waitEvidence['requesting_lock_data']) === (string) $definitionId,
                    'B must request the same definition record held by A when MySQL exposes requester LOCK_DATA.'
                );
                self::assertSame((string) $definitionId, trim((string) $waitEvidence['blocking_lock_data']));
                self::assertSame('WAITING', $waitEvidence['requesting_lock_status']);
                self::assertSame('GRANTED', $waitEvidence['blocking_lock_status']);
                if (!str_starts_with((string) $parentLockBefore['lock_id'], 'mariadb:behavioural:')) {
                    self::assertSame($parentLockBefore['lock_id'], $waitEvidence['blocking_lock_id']);
                    self::assertSame($parentLockBefore['transaction_id'], $waitEvidence['blocking_transaction_id']);
                }
                // The MariaDB wait-edge query already pins the blocking
                // transaction to the parent connection thread id.
                self::assertSame((string) $parentConnectionId, (string) $waitEvidence['blocking_connection_id']);
                self::assertNotSame($waitEvidence['requesting_transaction_id'], $waitEvidence['blocking_transaction_id']);

                $parentTransactionWhileWaiting = $this->innodbTransactionForConnection($observer, $parentConnectionId);
                $childTransactionWhileWaiting = $this->innodbTransactionForConnection($observer, $childConnectionId);
                self::assertIsArray($parentTransactionWhileWaiting, 'Parent A must still have an open InnoDB transaction while B waits.');
                self::assertIsArray($childTransactionWhileWaiting, 'Child B must still have an open InnoDB transaction while its assignment waits.');
                self::assertSame('RUNNING', $parentTransactionWhileWaiting['trx_state']);
                self::assertSame('LOCK WAIT', $childTransactionWhileWaiting['trx_state']);

                $deactivated = $this->deactivate($managerId, $clinicId, $raceRoleKey, 1);
                self::assertSame('inactive', $deactivated['status']);

                // The nested lifecycle operation must not release A's outer row
                // lock; B must remain blocked until A commits status and audit.
                $parentLockAfter = $this->customRoleDefinitionLockForConnection(
                    $observer,
                    $parentConnectionId,
                    $definitionId
                );
                $waitEvidenceAfterTransition = $this->customRoleDefinitionWaitEdge(
                    $observer,
                    $childConnectionId,
                    $parentConnectionId,
                    $definitionId
                );
                self::assertIsArray($parentLockAfter, 'A must continue holding the definition record lock after deactivation and before commit.');
                self::assertIsArray($waitEvidenceAfterTransition, 'B must remain blocked by A until A commits the deactivation.');
                self::assertSame($parentLockBefore['lock_id'], $parentLockAfter['lock_id']);
                self::assertSame($waitEvidence['requesting_lock_id'], $waitEvidenceAfterTransition['requesting_lock_id']);
                self::assertSame($waitEvidence['blocking_lock_id'], $waitEvidenceAfterTransition['blocking_lock_id']);

                $parentTransactionAfterTransition = $this->innodbTransactionForConnection($observer, $parentConnectionId);
                $childTransactionAfterTransition = $this->innodbTransactionForConnection($observer, $childConnectionId);
                self::assertIsArray($parentTransactionAfterTransition);
                self::assertIsArray($childTransactionAfterTransition);
                self::assertSame('RUNNING', $parentTransactionAfterTransition['trx_state']);
                self::assertSame('LOCK WAIT', $childTransactionAfterTransition['trx_state']);
            });

            // The callback has returned and A has committed. B can now finish its
            // locking read and must validate the current, inactive row state.
            if (!$childReaped && $childPid > 0) {
                $waited = pcntl_waitpid($childPid, $childStatus);
                if ($waited !== $childPid) {
                    throw new RuntimeException('Could not reap child B after parent A committed.');
                }
                $childReaped = true;
            }

            $resultLines = [];
            if (is_resource($parentPipe)) {
                stream_set_timeout($parentPipe, self::RACE_SIGNAL_TIMEOUT_SECONDS);
                while (($line = fgets($parentPipe)) !== false) {
                    $resultLines[] = rtrim($line, "\r\n");
                }
                fclose($parentPipe);
                $parentPipe = null;
            }
            foreach ($resultLines as $line) {
                if (str_starts_with($line, 'RESULT:')) {
                    $childOutcome = substr($line, strlen('RESULT:'));
                } elseif (str_starts_with($line, 'ERROR:')) {
                    $childOutcome = $line;
                }
            }

            self::assertTrue(pcntl_wifexited($childStatus), 'Child B must exit normally after A commits. Child result: ' . $childOutcome);
            self::assertSame(0, pcntl_wexitstatus($childStatus), 'Child B must not encounter a connection or infrastructure error. Child result: ' . $childOutcome);
            self::assertSame(
                'denied:' . CustomRoleAssignmentException::CODE_DEFINITION_NOT_ACTIVE,
                $childOutcome,
                'B must resume after A commits, read the authoritative inactive status, and deny the stale assignment.'
            );

            $definitionStatusAfter = (string) App::db()->fetchValue(
                'SELECT status FROM ' . App::db()->table('cpms_custom_role_defs') . ' WHERE id = %d AND clinic_id = %d',
                [$definitionId, $clinicId]
            );
            $targetRoleAfter = (string) App::db()->fetchValue(
                'SELECT role_key FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE id = %d AND clinic_id = %d',
                [$targetMembershipId, $clinicId]
            );
            clean_user_cache($targetUser);
            $targetWpUserAfter = get_userdata($targetUser);
            self::assertInstanceOf(\WP_User::class, $targetWpUserAfter);
            $targetWpRolesAfter = (array) $targetWpUserAfter->roles;
            $deactivationAuditCount = $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', $definitionId);
            $assignmentAuditCount = $this->customRoleAssignmentAuditCount($clinicId, $targetMembershipId);

            self::assertSame('inactive', $definitionStatusAfter, 'A must commit the definition status before B resumes.');
            self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, $targetRoleAfter, 'A denied assignment must not mutate the existing membership role.');
            self::assertSame($targetWpRolesBefore, $targetWpRolesAfter, 'Assignment must never mutate the target WordPress-global roles.');
            self::assertSame(0, $assignmentAuditCount, 'A denied assignment must not append a role-assigned audit event.');
            self::assertSame(1, $deactivationAuditCount, 'A must commit exactly one deactivation audit event with its status change.');

            fwrite(
                STDERR,
                'CUSTOM_ROLE_LIFECYCLE_CONCURRENCY_EVIDENCE '
                . json_encode(
                    [
                        'mysql_version' => $mysqlVersion,
                        'mysql_schema' => DB_NAME,
                        'assignment_lock_query' => $childLockQuery,
                        'parent' => [
                            'connection_id' => $parentConnectionId,
                            'transaction_id' => $parentLockBefore['transaction_id'],
                            'definition_lock_id' => $parentLockBefore['lock_id'],
                            'lock_after_deactivation_before_commit' => $parentLockAfter['lock_id'],
                            'transaction_state_while_child_waited' => $parentTransactionWhileWaiting['trx_state'],
                            'transaction_state_after_deactivation_before_commit' => $parentTransactionAfterTransition['trx_state'],
                        ],
                        'child' => [
                            'connection_id' => $childConnectionId,
                            'transaction_id' => $waitEvidence['requesting_transaction_id'],
                            'assignment_lock_query_sha256' => $childQueryFingerprint,
                            'requesting_lock_id' => $waitEvidence['requesting_lock_id'],
                            'transaction_state_while_waiting' => $childTransactionWhileWaiting['trx_state'],
                            'transaction_state_after_deactivation_before_commit' => $childTransactionAfterTransition['trx_state'],
                        ],
                        'wait_edge' => [
                            'requesting_connection_id' => $waitEvidence['requesting_connection_id'],
                            'blocking_connection_id' => $waitEvidence['blocking_connection_id'],
                            'requesting_lock_id' => $waitEvidence['requesting_lock_id'],
                            'blocking_lock_id' => $waitEvidence['blocking_lock_id'],
                            'requesting_lock_mode' => $waitEvidence['requesting_lock_mode'],
                            'requesting_lock_status' => $waitEvidence['requesting_lock_status'],
                            'blocking_lock_mode' => $waitEvidence['blocking_lock_mode'],
                            'blocking_lock_status' => $waitEvidence['blocking_lock_status'],
                            'table' => App::db()->table('cpms_custom_role_defs'),
                            'index' => 'PRIMARY',
                            'lock_data' => $waitEvidence['requesting_lock_data'],
                        ],
                        'same_wait_edge_after_deactivation_before_commit' => $waitEvidenceAfterTransition !== null,
                        'child_outcome_after_parent_commit' => $childOutcome,
                        'definition_status_after_parent_commit' => $definitionStatusAfter,
                        'membership_role_after' => $targetRoleAfter,
                        'wordpress_roles_before' => $targetWpRolesBefore,
                        'wordpress_roles_after' => $targetWpRolesAfter,
                        'assignment_audit_count_after' => $assignmentAuditCount,
                        'deactivation_audit_count_after' => $deactivationAuditCount,
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
                . PHP_EOL
            );
        } finally {
            if ($observer instanceof \mysqli) {
                $observer->close();
            }

            if ($childPid > 0 && !$childReaped) {
                pcntl_waitpid($childPid, $childStatus);
                $childReaped = true;
            }

            if (is_resource($parentPipe)) {
                fclose($parentPipe);
            }

            // Restore WordPress' integration bootstrap query handlers before
            // fixture cleanup and PHPUnit tearDown rollback.
            if ($queryHooks !== null) {
                $GLOBALS['wp_filter']['query'] = $queryHooks;
            } else {
                unset($GLOBALS['wp_filter']['query']);
            }

            $this->cleanupCommittedRaceFixtures(
                [$this->clinicA, $this->clinicB],
                [$managerId, $this->managerB, $this->suspendedManager, $this->member, $this->nonMember, $targetUser],
                $definitionId
            );
        }
    }

    // ---------------------------------------------------------------------
    // wp-admin entry/action contract: scoped managers need no WP-global CONFIG;
    // status posts retain nonce validation and the trusted Clinic service gate.
    // ---------------------------------------------------------------------

    public function testClinicScopedManagerCanReachThePageAndRenderedControlsHaveNonceAndConsequences(): void
    {
        $this->createRole('crl_ui', ['cpms_search']);
        wp_set_current_user($this->managerA);
        self::assertFalse(current_user_can(RolesAndCapabilities::CONFIG), 'the clinic manager fixture has no WordPress-global CONFIG grant');

        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        CustomRoleDefinitionsPage::menu();
        $found = false;
        foreach ($GLOBALS['menu'] as $item) {
            if (is_array($item) && ($item[2] ?? '') === CustomRoleDefinitionsPage::PAGE_SLUG) {
                $found = true;
                self::assertSame('read', $item[1] ?? null, 'the standalone entry is only gated by ordinary wp-admin read access');
            }
        }
        self::assertTrue($found, 'an authorized Clinic-local manager must have a reachable management-page entry');

        ob_start();
        CustomRoleDefinitionsPage::render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('cpms_custom_role_deactivate', $html);
        self::assertStringNotContainsString('cpms_custom_role_reactivate', $html, 'an active definition offers deactivation, not reactivation');
        self::assertStringContainsString('عضویت', $html, 'the explanation must say that existing staff assignments are retained');
        self::assertStringContainsString('confirm', $html);
        self::assertStringContainsString('_wpnonce', $html);
        self::assertStringContainsString('version', strtolower($html));
        self::assertStringContainsString('cpms_search', $html);

        $this->deactivate($this->managerA, $this->clinicA, 'crl_ui', 1);
        ob_start();
        CustomRoleDefinitionsPage::render();
        $inactiveHtml = (string) ob_get_clean();
        self::assertStringContainsString('غیرفعال', $inactiveHtml);
        self::assertStringContainsString('cpms_custom_role_reactivate', $inactiveHtml);
    }

    public function testInvalidStatusNonceStopsTheAdminActionBeforeMutation(): void
    {
        $this->createRole('crl_nonce', ['cpms_search']);
        wp_set_current_user($this->managerA);
        $oldPost = $_POST;
        $oldRequest = $_REQUEST;
        $_POST = [
            'action' => 'cpms_custom_role_deactivate',
            'cpms_clinic_id' => (string) $this->clinicA,
            'role_key' => 'crl_nonce',
            'version' => '1',
            '_wpnonce' => 'invalid-nonce',
        ];
        $_REQUEST = $_POST;
        $dieHandler = static function ($message): void {
            throw new \RuntimeException('wp_die reached: ' . (is_scalar($message) ? (string) $message : 'message'));
        };
        $dieFilter = static function ($handler) use ($dieHandler) {
            return $dieHandler;
        };
        add_filter('wp_die_handler', $dieFilter);
        try {
            try {
                CustomRoleDefinitionsPage::handle_deactivate();
                self::fail('an invalid nonce must stop the state-changing action');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('wp_die reached', $e->getMessage());
            }
        } finally {
            remove_filter('wp_die_handler', $dieFilter);
            $_POST = $oldPost;
            $_REQUEST = $oldRequest;
        }

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crl_nonce');
        self::assertNotNull($stored);
        self::assertSame('active', $stored['status']);
        self::assertSame(1, $stored['version']);
    }

    // ---------------------------------------------------------------------
    // Test helpers.
    // ---------------------------------------------------------------------

    private function service(): CustomRoleManagementService
    {
        return App::custom_role_management_service();
    }

    /** @param list<string> $capabilities */
    private function createRole(string $roleKey, array $capabilities): array
    {
        return $this->service()->create($this->managerA, $this->clinicA, $roleKey, 'Lifecycle ' . $roleKey, $capabilities);
    }

    /** @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} */
    private function deactivate(int $actor, int $clinicId, string $roleKey, int $expectedVersion): array
    {
        return $this->transition('deactivate', $actor, $clinicId, $roleKey, $expectedVersion);
    }

    /** @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} */
    private function reactivate(int $actor, int $clinicId, string $roleKey, int $expectedVersion): array
    {
        return $this->transition('reactivate', $actor, $clinicId, $roleKey, $expectedVersion);
    }

    /** @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} */
    private function transition(string $method, int $actor, int $clinicId, string $roleKey, int $expectedVersion): array
    {
        $service = $this->service();
        self::assertTrue(method_exists($service, $method), 'Slice 4A must implement the authorized ' . $method . ' operation');
        /** @var array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} $result */
        $result = call_user_func([$service, $method], $actor, $clinicId, $roleKey, $expectedVersion);

        return $result;
    }

    private function expectLifecycleFailure(
        string $method,
        int $actor,
        int $clinicId,
        string $roleKey,
        int $version,
        string $exceptionClass,
        ?string $reason = null
    ): void {
        try {
            $this->transition($method, $actor, $clinicId, $roleKey, $version);
            self::fail('the lifecycle mutation must be refused');
        } catch (\Throwable $e) {
            self::assertInstanceOf($exceptionClass, $e);
            if ($reason !== null) {
                self::assertSame($reason, method_exists($e, 'reason_code') ? $e->reason_code() : null);
            }
        }
    }

    /** @param list<string> $capabilities */
    private function statusAuditCount(string $action, int $definitionId): int
    {
        global $wpdb;
        $table = App::db()->table('cpms_audit_logs');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE action = %s AND clinic_id = %d AND resource_type = %s AND resource_id = %d",
            $action,
            $this->clinicA,
            'custom_role_definition',
            $definitionId
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function definitionId(string $roleKey): int
    {
        return (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_custom_role_defs') . ' WHERE clinic_id = %d AND role_key = %s',
            [$this->clinicA, $roleKey]
        );
    }

    private function failAuditInserts(): void
    {
        global $wpdb;
        self::assertNull($this->auditFault, 'the audit fault must not be installed twice');
        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\\A\\s*INSERT\\s+INTO\\s+(\\S+)/i', $sql, $matches) === 1
                && str_contains($matches[1], 'cpms_audit_logs')) {
                return 'INSERT INTO ' . $matches[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }

            return $query;
        };
        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        add_filter('query', $fault, 5);
        $this->auditFault = $fault;
    }

    private function restoreAuditInserts(): void
    {
        if ($this->auditFault === null) {
            return;
        }
        remove_filter('query', $this->auditFault, 5);
        $this->auditFault = null;
        global $wpdb;
        $wpdb->suppress_errors($this->previousSuppression);
    }

    private function freshMysqli(): \mysqli
    {
        global $wpdb;
        try {
            $connection = new \mysqli($wpdb->dbhost, $wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname);
        } catch (\mysqli_sql_exception $exception) {
            throw new RuntimeException('Could not open the independent Performance Schema observer connection.', 0, $exception);
        }
        if ($connection->connect_errno !== 0) {
            throw new RuntimeException('Could not open the independent Performance Schema observer connection: ' . $connection->connect_error);
        }

        if (!$connection->set_charset('utf8mb4')) {
            throw new RuntimeException('Could not set the observer connection character set: ' . $connection->error);
        }

        return $connection;
    }

    /**
     * @param resource $pipe
     */
    private function readRaceLine($pipe, int $timeoutSeconds): ?string
    {
        if (!is_resource($pipe)) {
            return null;
        }

        stream_set_timeout($pipe, $timeoutSeconds);
        $line = fgets($pipe);
        $metadata = stream_get_meta_data($pipe);
        if ($line === false || ($metadata['timed_out'] ?? false)) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    private function customRoleAssignmentAuditCount(int $clinicId, int $membershipId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE clinic_id = %d AND action = %s AND resource_type = %s AND resource_id = %d',
            [
                $clinicId,
                CustomRoleAssignmentService::AUDIT_ASSIGNED,
                CustomRoleAssignmentService::RESOURCE_TYPE,
                $membershipId,
            ]
        );
    }

    private function freshWpdb(): \wpdb
    {
        global $wpdb;
        $connection = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $connection->set_prefix($wpdb->prefix);
        $connection->show_errors(false);

        return $connection;
    }

    /**
     * Test-only teardown for the committed two-connection fixture. The test
     * asserts the deactivation event before this cleanup; production lifecycle
     * paths never delete audit records, definitions, memberships or overrides.
     *
     * @param list<int> $clinicIds
     * @param list<int> $userIds
     */
    private function cleanupCommittedRaceFixtures(array $clinicIds, array $userIds, int $definitionId): void
    {
        $db = App::db();
        $clinicId = (int) ($clinicIds[0] ?? 0);
        if ($clinicId > 0) {
            // Remove only this ephemeral test event so the integration suite's
            // global hash-chain fixtures and Clinic-count assumptions are reset.
            $db->query(
                'DELETE FROM ' . $db->table('cpms_audit_logs') .
                ' WHERE clinic_id = %d AND action = %s AND resource_type = %s AND resource_id = %d',
                [$clinicId, 'CUSTOM_ROLE_DEACTIVATED', 'custom_role_definition', $definitionId]
            );
        }
        $db->query(
            'DELETE FROM ' . $db->table('cpms_custom_role_capabilities') . ' WHERE role_def_id = %d',
            [$definitionId]
        );
        $db->query(
            'DELETE FROM ' . $db->table('cpms_custom_role_defs') . ' WHERE id = %d',
            [$definitionId]
        );
        foreach ($clinicIds as $clinicId) {
            $memberships = $db->fetchAll(
                'SELECT id FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
                [$clinicId]
            );
            foreach ($memberships as $membership) {
                $membershipId = (int) ($membership['id'] ?? 0);
                if ($membershipId > 0) {
                    $db->query('DELETE FROM ' . $db->table('cpms_membership_capabilities') . ' WHERE membership_id = %d', [$membershipId]);
                    $db->query('DELETE FROM ' . $db->table('cpms_membership_locations') . ' WHERE membership_id = %d', [$membershipId]);
                    $db->query('DELETE FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE id = %d', [$membershipId]);
                }
            }
        }
        foreach ($userIds as $userId) {
            wp_delete_user($userId);
        }
        foreach ($clinicIds as $clinicId) {
            $db->query('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [$clinicId]);
        }
    }

    private function makeClinic(int $organizationId, string $prefix): int
    {
        global $wpdb;
        $slug = $prefix . '-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $organizationId,
            'Lifecycle test ' . $slug,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $login, string $role = 'subscriber'): int
    {
        $id = self::factory()->user->create(['user_login' => $login, 'role' => $role]);
        self::assertGreaterThan(0, $id);

        return (int) $id;
    }
}
