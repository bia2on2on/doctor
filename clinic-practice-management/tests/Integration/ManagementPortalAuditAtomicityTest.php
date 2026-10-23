<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Support\ObjectCacheDeleteRecorder;
use WP_UnitTestCase;

/**
 * D6 — Management Portal audit atomicity (real-MySQL fault injection).
 *
 * PROVEN DEFECT this suite pins down: the Management Portal performed the
 * business mutation (membership suspension, role assignment, enrollment,
 * capability DENY, location scope, Location/Clinician/Schedule writes) with
 * autocommit and called AuditLogger::log() only afterwards, so a failed audit
 * INSERT left the mutation durably committed with no audit row — and the
 * service still returned a success response.
 *
 * REQUIRED CONTRACT (asserted here):
 *  1. every mandatory-audited portal mutation and its audit event share one
 *     transaction boundary (CpmsDb::transactional + SAVEPOINT nesting);
 *  2. a forced audit INSERT failure rolls the business mutation back;
 *  3. the response is never a false success;
 *  4. the test-suite SAVEPOINT stack returns to its baseline depth (clean
 *     transaction cleanup, no leaked/aborted transaction);
 *  5. once the fault is removed, a later authorized operation succeeds and
 *     its audit row is written, and the hash chain stays valid;
 *  6. authorization and lifecycle refusals are unchanged.
 *
 * Fault injection is a real MySQL 1054 ("Unknown column") produced by rewriting
 * only INSERTs into cpms_audit_logs through the documented wpdb 'query' filter,
 * exactly like CustomRoleAuditAtomicityTest.
 */
final class ManagementPortalAuditAtomicityTest extends WP_UnitTestCase
{
    private int $clinicId;
    private int $managerUserId;
    private int $targetUserId;
    private int $targetMembershipId;
    private int $locationId;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    private ObjectCacheDeleteRecorder $cache;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        // D7: record production cache eviction directly; no test-side flushes.
        $this->cache = ObjectCacheDeleteRecorder::install();

        global $wpdb;
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $orgId, 'organization fixture must exist');

        $this->clinicId = $this->makeClinic($orgId, 'mpaa');
        $this->managerUserId = $this->makeUser('mpaa_mgr_' . bin2hex(random_bytes(2)));
        $this->targetUserId = $this->makeUser('mpaa_staff_' . bin2hex(random_bytes(2)));

        App::membership_service()->create_membership($this->clinicId, $this->managerUserId, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->create_membership($this->clinicId, $this->targetUserId, RolesAndCapabilities::ROLE_SECRETARY);

        $membership = App::db()->fetchRow(
            'SELECT id FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d AND wp_user_id = %d LIMIT 1',
            [$this->clinicId, $this->targetUserId]
        );
        self::assertIsArray($membership);
        $this->targetMembershipId = (int) $membership['id'];

        $this->locationId = $this->makeLocation($this->clinicId, 'mpaa-loc-' . bin2hex(random_bytes(3)));
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        $this->cache->restore();
        App::resetScope();
        parent::tearDown();
    }

    private function portal(): \ClinicCore\Application\Management\ManagementPortalService
    {
        return App::management_portal_service();
    }

    private function membershipStatus(): string
    {
        return (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE id = %d',
            [$this->targetMembershipId]
        );
    }

    private function membershipRoleKey(): string
    {
        return (string) App::db()->fetchValue(
            'SELECT role_key FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE id = %d',
            [$this->targetMembershipId]
        );
    }

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_id = %d',
            [$action, $this->targetMembershipId]
        );
    }

    private function savepointDepth(): int
    {
        return is_array($GLOBALS['__cpms_sp_stack'] ?? null) ? count($GLOBALS['__cpms_sp_stack']) : 0;
    }

    // ------------------------------------------------------------------
    // 1 — suspension / reactivation with a forced audit failure.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringSuspensionRollsBackAndReportsNoSuccess(): void
    {
        self::assertSame('active', $this->membershipStatus());
        $depthBefore = $this->savepointDepth();
        $auditBefore = $this->auditCount('MEMBERSHIP_SUSPENDED');

        $this->failAuditInserts();
        $response = $this->portal()->set_staff_status($this->managerUserId, $this->clinicId, $this->targetMembershipId, false);
        $this->restoreAuditInserts();

        self::assertFalse($response['ok'], 'a failed audit must never produce a success response');
        self::assertSame('active', $this->membershipStatus(), 'the suspension must have been rolled back');
        self::assertSame($auditBefore, $this->auditCount('MEMBERSHIP_SUSPENDED'), 'no suspension audit row may be persisted');
        self::assertSame($depthBefore, $this->savepointDepth(), 'savepoint/transaction depth must return to baseline');

        // Subsequent authorized operation succeeds once the fault is removed.
        $retry = $this->portal()->set_staff_status($this->managerUserId, $this->clinicId, $this->targetMembershipId, false);
        self::assertTrue($retry['ok'], 'the connection must stay usable after the rolled-back failure');
        self::assertSame('suspended', $this->membershipStatus());
        self::assertSame($auditBefore + 1, $this->auditCount('MEMBERSHIP_SUSPENDED'));
        self::assertSame($depthBefore, $this->savepointDepth());
    }

    public function testAuditFailureDuringReactivationRollsBackAndReportsNoSuccess(): void
    {
        $suspend = $this->portal()->set_staff_status($this->managerUserId, $this->clinicId, $this->targetMembershipId, false);
        self::assertTrue($suspend['ok']);
        self::assertSame('suspended', $this->membershipStatus());

        $depthBefore = $this->savepointDepth();
        $auditBefore = $this->auditCount('MEMBERSHIP_REACTIVATED');

        $this->failAuditInserts();
        $response = $this->portal()->set_staff_status($this->managerUserId, $this->clinicId, $this->targetMembershipId, true);
        $this->restoreAuditInserts();

        self::assertFalse($response['ok']);
        self::assertSame('suspended', $this->membershipStatus(), 'the reactivation must have been rolled back');
        self::assertSame($auditBefore, $this->auditCount('MEMBERSHIP_REACTIVATED'));
        self::assertSame($depthBefore, $this->savepointDepth());

        $retry = $this->portal()->set_staff_status($this->managerUserId, $this->clinicId, $this->targetMembershipId, true);
        self::assertTrue($retry['ok']);
        self::assertSame('active', $this->membershipStatus());
        self::assertSame($auditBefore + 1, $this->auditCount('MEMBERSHIP_REACTIVATED'));
        self::assertTrue(App::audit()->verifyChain(500)['ok'], 'the hash chain must remain valid');
    }

    // ------------------------------------------------------------------
    // 2 — staff-role assignment.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringBuiltInRoleAssignmentRollsBack(): void
    {
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, $this->membershipRoleKey());
        $depthBefore = $this->savepointDepth();

        $this->failAuditInserts();
        $response = $this->portal()->assign_built_in_role($this->managerUserId, $this->clinicId, $this->targetUserId, RolesAndCapabilities::ROLE_DOCTOR);
        $this->restoreAuditInserts();

        self::assertFalse($response['ok']);
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, $this->membershipRoleKey(), 'the role change must have been rolled back');
        self::assertSame(0, $this->auditCount('MEMBERSHIP_ROLE_CHANGED'));
        self::assertSame($depthBefore, $this->savepointDepth());

        $retry = $this->portal()->assign_built_in_role($this->managerUserId, $this->clinicId, $this->targetUserId, RolesAndCapabilities::ROLE_DOCTOR);
        self::assertTrue($retry['ok']);
        self::assertSame(RolesAndCapabilities::ROLE_DOCTOR, $this->membershipRoleKey());
        self::assertSame(1, $this->auditCount('MEMBERSHIP_ROLE_CHANGED'));
    }

    // ------------------------------------------------------------------
    // 3 — staff enrollment (including the provisioned WordPress subscriber).
    // ------------------------------------------------------------------

    public function testAuditFailureDuringEnrollmentRollsBackMembershipAndProvisionedUser(): void
    {
        $email = 'mpaa-new-' . bin2hex(random_bytes(4)) . '@example.test';
        $depthBefore = $this->savepointDepth();

        $this->failAuditInserts();
        $response = $this->portal()->enroll_staff($this->managerUserId, $this->clinicId, $email, RolesAndCapabilities::ROLE_ACCOUNTANT);
        $this->restoreAuditInserts();
        // D7: production cache rollback for the provisioned subscriber —
        // narrow WP-owned key eviction, never a global wp_cache_flush().
        self::assertSame(0, $this->cache->flushCount(), 'rollback must never global-flush the object cache');
        self::assertContains($email, $this->cache->deletedKeysForGroup('useremail'), 'provisioned email cache key evicted after rollback');
        self::assertNotEmpty($this->cache->deletedKeysForGroup('userlogins'), 'provisioned login cache key evicted after rollback');
        self::assertNotEmpty($this->cache->deletedKeysForGroup('users'), 'users group entry evicted after rollback');
        foreach ($this->cache->deletedKeysForGroup('users') as $evictedId) {
            self::assertTrue($this->cache->deleted($evictedId, 'user_meta'), 'matching user_meta key evicted after rollback');
        }

        self::assertFalse($response['ok'], 'enrollment must fail closed when its audit cannot be persisted');
        // Resolves false WITHOUT any test-side flush: production invalidation
        // plus the durable rollback agree the ghost subscriber does not exist.
        self::assertFalse(get_user_by('email', $email), 'the provisioned WordPress subscriber must be rolled back too');
        self::assertSame(2, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
            [$this->clinicId]
        ), 'only the two fixture memberships may remain');
        self::assertSame(0, $this->auditCountFor('STAFF_MEMBERSHIP_CREATED'));
        self::assertSame($depthBefore, $this->savepointDepth());

        // The same enrollment succeeds once the fault is removed.
        $retry = $this->portal()->enroll_staff($this->managerUserId, $this->clinicId, $email, RolesAndCapabilities::ROLE_ACCOUNTANT);
        self::assertTrue($retry['ok']);
        $user = get_user_by('email', $email);
        self::assertNotFalse($user);
        self::assertSame(1, $this->auditCountFor('STAFF_MEMBERSHIP_CREATED'));
    }

    // ------------------------------------------------------------------
    // 4 — capability DENY override.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringCapabilityDenyRollsBack(): void
    {
        $capability = RolesAndCapabilities::CONFIG;
        $overrideBefore = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_membership_capabilities') . ' WHERE membership_id = %d AND capability = %s',
            [$this->targetMembershipId, $capability]
        );
        $depthBefore = $this->savepointDepth();

        $this->failAuditInserts();
        $response = $this->portal()->set_staff_capability($this->managerUserId, $this->clinicId, $this->targetMembershipId, $capability, 'deny');
        $this->restoreAuditInserts();

        self::assertFalse($response['ok']);
        $overrideAfter = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_membership_capabilities') . ' WHERE membership_id = %d AND capability = %s',
            [$this->targetMembershipId, $capability]
        );
        self::assertSame($overrideBefore, $overrideAfter, 'the DENY override must have been rolled back');
        self::assertSame(0, $this->auditCount('MEMBERSHIP_CAPABILITY_CHANGED'));
        self::assertSame($depthBefore, $this->savepointDepth());

        $retry = $this->portal()->set_staff_capability($this->managerUserId, $this->clinicId, $this->targetMembershipId, $capability, 'deny');
        self::assertTrue($retry['ok']);
        self::assertSame(1, $this->auditCount('MEMBERSHIP_CAPABILITY_CHANGED'));
    }

    // ------------------------------------------------------------------
    // 5 — location assignment (membership scope).
    // ------------------------------------------------------------------

    public function testAuditFailureDuringLocationAssignmentRollsBack(): void
    {
        self::assertSame('clinic', (string) App::db()->fetchValue(
            'SELECT scope_mode FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE id = %d',
            [$this->targetMembershipId]
        ));
        $depthBefore = $this->savepointDepth();

        $this->failAuditInserts();
        $response = $this->portal()->set_staff_locations($this->managerUserId, $this->clinicId, $this->targetMembershipId, [$this->locationId]);
        $this->restoreAuditInserts();

        self::assertFalse($response['ok']);
        self::assertSame('clinic', (string) App::db()->fetchValue(
            'SELECT scope_mode FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE id = %d',
            [$this->targetMembershipId]
        ), 'the scope change must have been rolled back');
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_membership_locations') . ' WHERE membership_id = %d',
            [$this->targetMembershipId]
        ), 'no location link may survive the rollback');
        self::assertSame(0, $this->auditCount('MEMBERSHIP_SCOPE_CHANGED'));
        self::assertSame($depthBefore, $this->savepointDepth());

        $retry = $this->portal()->set_staff_locations($this->managerUserId, $this->clinicId, $this->targetMembershipId, [$this->locationId]);
        self::assertTrue($retry['ok']);
        self::assertSame(1, $this->auditCount('MEMBERSHIP_SCOPE_CHANGED'));
    }

    // ------------------------------------------------------------------
    // 6 — authorization stays fail-closed while the fault machinery is armed.
    // ------------------------------------------------------------------

    public function testOutsiderIsRefusedEvenWithAuditFaultArmed(): void
    {
        $outsider = $this->makeUser('mpaa_out_' . bin2hex(random_bytes(2)));
        $depthBefore = $this->savepointDepth();

        $this->failAuditInserts();
        $response = $this->portal()->set_staff_status($outsider, $this->clinicId, $this->targetMembershipId, false);
        $this->restoreAuditInserts();

        self::assertFalse($response['ok']);
        self::assertSame('active', $this->membershipStatus(), 'a refused actor must never change state');
        self::assertSame($depthBefore, $this->savepointDepth());
    }

    // ================= fault injection (mirrors CustomRoleAuditAtomicityTest) =================

    private function failAuditInserts(): void
    {
        global $wpdb;

        self::assertNull($this->auditFault, 'the audit fault must not be installed twice');

        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\A\s*INSERT\s+INTO\s+(\S+)/i', $sql, $m) === 1
                && str_contains($m[1], 'cpms_audit_logs')) {
                return 'INSERT INTO ' . $m[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
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

    private function auditCountFor(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND clinic_id = %d',
            [$action, $this->clinicId]
        );
    }

    // ================= fixtures =================

    private function makeClinic(int $orgId, string $prefix): int
    {
        global $wpdb;
        $slug = $prefix . '-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $orgId,
            'Portal Atomicity ' . $slug,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function makeLocation(int $clinicId, string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinicId,
            'Atomicity Location ' . $slug,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $login): int
    {
        $userId = (int) wp_create_user($login, wp_generate_password(24), $login . '@mpaa.test');
        self::assertGreaterThan(0, $userId, "user {$login} created");
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role('subscriber');
        }

        return $userId;
    }
}
