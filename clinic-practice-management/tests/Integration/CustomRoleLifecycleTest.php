<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CustomRoleDefinitionsPage;
use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Roles\CustomRoleManagementException;
use ClinicCore\Application\Roles\CustomRoleManagementService;
use ClinicCore\Application\Roles\CustomRoleAssignmentService;
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
use WP_UnitTestCase;

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
    // committed fixture, following ConcurrencyTest's integration protocol.
    // ---------------------------------------------------------------------

    public function testAssignmentWaitsForLockedDefinitionAndRevalidatesAfterDeactivation(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork is required for the real two-connection MySQL race test');
        }

        // Repository-only seeding keeps this concurrency fixture free of a
        // committed create-audit row; lifecycle auditing is covered separately.
        $raceRoleKey = 'crl_race_' . bin2hex(random_bytes(3));
        $definitionId = (new CustomRoleRepository(App::db()))->define($this->clinicA, $raceRoleKey, ['cpms_search']);
        $targetUser = $this->makeUser('crl_race_target_' . bin2hex(random_bytes(3)));
        $targetMembership = App::membership_service()->create_membership($this->clinicA, $targetUser, RolesAndCapabilities::ROLE_SECRETARY);
        $clinicId = $this->clinicA;
        $managerId = $this->managerA;

        // A forked connection cannot observe the WP test transaction's uncommitted
        // fixtures. Commit them explicitly and clean these fixture rows below.
        global $wpdb;
        $wpdb->query('COMMIT');

        $childPid = 0;
        $childFinished = false;
        $lockWasWaited = false;
        $childOutcome = '';
        $queryHooks = isset($GLOBALS['wp_filter']['query']) ? clone $GLOBALS['wp_filter']['query'] : null;
        [$parentPipe, $childPipe] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsResource($parentPipe);
        self::assertIsResource($childPipe);

        $targetRoleAfter = '';
        $deactivationAuditCount = 0;
        remove_all_filters('query');
        try {
            App::db()->transactional(function () use (
                $clinicId,
                $definitionId,
                $managerId,
                $targetUser,
                $targetMembership,
                $raceRoleKey,
                $parentPipe,
                $childPipe,
                &$childPid,
                &$childFinished,
                &$lockWasWaited,
                &$childOutcome,
                &$targetRoleAfter
            ): void {
                $locked = App::db()->fetchRowForUpdate(
                    'SELECT id, status, version FROM ' . App::db()->table('cpms_custom_role_defs') . ' WHERE id = %d AND clinic_id = %d',
                    [$definitionId, $clinicId]
                );
                self::assertNotNull($locked);
                self::assertSame('active', (string) $locked['status']);

                $childPid = pcntl_fork();
                if ($childPid === -1) {
                    self::fail('pcntl_fork failed');
                }
                if ($childPid === 0) {
                    fclose($parentPipe);
                    $exitCode = 1;
                    $wpdb2 = null;
                    try {
                        $wpdb2 = $this->freshWpdb();
                        $connectionId = (int) $wpdb2->get_var('SELECT CONNECTION_ID()'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                        fwrite($childPipe, 'READY:' . $connectionId . "\n");
                        fflush($childPipe);

                        // The child uses a genuinely independent MySQL connection;
                        // the parent removed the test suite SAVEPOINT query shim.
                        remove_all_filters('query');
                        $db2 = new CpmsDb($wpdb2);
                        $memberships2 = new MembershipRepository($db2);
                        $roles2 = new CustomRoleRepository($db2);
                        $authorization2 = new AuthorizationService($memberships2, $roles2);
                        $assignment2 = new CustomRoleAssignmentService(
                            new TrustedClinicEstablisher($db2, $memberships2),
                            $authorization2,
                            $memberships2,
                            $roles2,
                            new AuditLogger($db2, new OpLogger($db2)),
                            $db2
                        );
                        $outcome = 'unexpected';
                        try {
                            $db2->transactional(function () use ($assignment2, $managerId, $clinicId, $targetUser, $raceRoleKey, &$outcome): void {
                                try {
                                    $assignment2->assign($managerId, $clinicId, $targetUser, $raceRoleKey);
                                    $outcome = 'assigned';
                                } catch (\ClinicCore\Application\Roles\CustomRoleAssignmentException $e) {
                                    $outcome = 'denied:' . $e->reason_code();
                                }
                                throw new \RuntimeException('intentional race-fixture rollback');
                            });
                        } catch (\RuntimeException $e) {
                            if ($e->getMessage() !== 'intentional race-fixture rollback') {
                                throw $e;
                            }
                        }
                        fwrite($childPipe, 'RESULT:' . $outcome . "\n");
                        fflush($childPipe);
                        $exitCode = 0;
                    } catch (\Throwable $e) {
                        fwrite($childPipe, 'ERROR:' . get_class($e) . "\n");
                        fflush($childPipe);
                    }
                    if ($wpdb2 instanceof \wpdb) {
                        $wpdb2->close();
                    }
                    fclose($childPipe);
                    exit($exitCode);
                }

                fclose($childPipe);
                $ready = fgets($parentPipe);
                self::assertIsString($ready);
                self::assertStringStartsWith('READY:', $ready, 'the second MySQL connection must start before the transition');
                $connectionId = (int) substr(trim($ready), 6);
                self::assertGreaterThan(0, $connectionId);

                $deadline = microtime(true) + 3.0;
                while (microtime(true) < $deadline) {
                    $state = App::db()->fetchValue(
                        'SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = %d LIMIT 1',
                        [$connectionId]
                    );
                    if ($state === 'LOCK WAIT') {
                        $lockWasWaited = true;
                        break;
                    }
                    $waited = pcntl_waitpid($childPid, $status, WNOHANG);
                    if ($waited === $childPid) {
                        $childFinished = true;
                        break;
                    }
                    usleep(20_000);
                }

                // Deactivation is the authorized production lifecycle operation,
                // performed while the assignment is observed waiting on the same
                // locked definition row. Status and audit commit atomically.
                $deactivated = $this->deactivate($managerId, $clinicId, $raceRoleKey, 1);
                self::assertSame('inactive', $deactivated['status']);
                $targetRoleAfter = (string) App::db()->fetchValue(
                    'SELECT role_key FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE id = %d',
                    [$targetMembership]
                );
            });
            $deactivationAuditCount = $this->statusAuditCount('CUSTOM_ROLE_DEACTIVATED', $definitionId);

            if (!$childFinished && $childPid > 0) {
                pcntl_waitpid($childPid, $status);
                $childFinished = true;
            }
            stream_set_timeout($parentPipe, 1);
            while (($line = fgets($parentPipe)) !== false) {
                if (str_starts_with($line, 'RESULT:')) {
                    $childOutcome = trim(substr($line, 7));
                }
                if (str_starts_with($line, 'ERROR:')) {
                    $childOutcome = trim($line);
                }
            }
        } finally {
            // Restore WordPress' integration bootstrap query handlers before
            // fixture cleanup and the PHPUnit tearDown rollback.
            if ($queryHooks !== null) {
                $GLOBALS['wp_filter']['query'] = $queryHooks;
            } else {
                unset($GLOBALS['wp_filter']['query']);
            }
            if (!$childFinished && $childPid > 0) {
                pcntl_waitpid($childPid, $childStatus);
            }
            if (is_resource($parentPipe)) {
                fclose($parentPipe);
            }
            if (is_resource($childPipe)) {
                fclose($childPipe);
            }
            $this->cleanupCommittedRaceFixtures(
                [$this->clinicA, $this->clinicB],
                [$managerId, $this->managerB, $this->suspendedManager, $this->member, $this->nonMember, $targetUser],
                $definitionId
            );
        }

        self::assertSame(1, $deactivationAuditCount, 'the committed status transition must have exactly one audit event before test-fixture cleanup');
        self::assertTrue($lockWasWaited, 'the assignment connection must be observed waiting on the locked custom-role row');
        self::assertSame('denied:CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_ACTIVE', $childOutcome, 'the assignment must re-read status after the lock is released');
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, $targetRoleAfter, 'the failed concurrent assignment must not change the existing membership');
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
