<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Support\ObjectCacheDeleteRecorder;
use WP_UnitTestCase;

/**
 * D6 — wp-admin Staff Management audit atomicity (real-MySQL fault injection).
 *
 * PROVEN DEFECT this suite pins down: StaffManagementPage wrote the WordPress
 * user / Clinic membership / role-parking meta first and called
 * AuditLogger::log() only afterwards, so a failed audit INSERT left the staff
 * change durably committed without its mandatory audit row (and the boundary
 * still reported success).
 *
 * REQUIRED CONTRACT (asserted here):
 *  1. save (create), attach and toggle (deactivate/activate) each commit their
 *     writes and their mandatory audit event in ONE CpmsDb::transactional
 *     boundary (SAVEPOINT nesting inside the WP test transaction);
 *  2. a forced audit INSERT failure rolls every write back — including a
 *     freshly created WordPress user and role-parking usermeta;
 *  3. the returned array carries an error, never a false success;
 *  4. the SAVEPOINT stack returns to its baseline depth;
 *  5. the same authorized operation succeeds after the fault is removed.
 *
 * Fault injection is a real MySQL 1054 produced by rewriting only INSERTs into
 * cpms_audit_logs through the documented wpdb 'query' filter.
 */
final class StaffManagementAuditAtomicityTest extends WP_UnitTestCase
{
    private const META_PREV_ROLE = 'cpms_previous_role';

    private int $clinicId;
    private int $adminId;

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

        // D7: observe production cache eviction directly. A test-side
        // wp_cache_flush() would mask stale ghost identities; the recorder
        // delegates to the real cache and pins the exact evicted keys/groups.
        $this->cache = ObjectCacheDeleteRecorder::install();

        $this->clinicId = $this->createClinic();
        $this->adminId = self::factory()->user->create(['role' => 'administrator']);
        cpms_test_seed_membership($this->adminId, $this->clinicId, RolesAndCapabilities::ROLE_MANAGER);
        wp_set_current_user($this->adminId);
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        $this->cache->restore();
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1 — save/create user.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringUserCreationRollsBackUserAndMembership(): void
    {
        $depthBefore = $this->savepointDepth();
        $login = 'smaa_create_' . bin2hex(random_bytes(3));
        $email = 'smaa-create-' . bin2hex(random_bytes(4)) . '@example.test';

        $this->failAuditInserts();
        $result = StaffManagementPage::upsertUser([
            'clinic_id' => $this->clinicId,
            'mode' => 'create',
            'username' => $login,
            'display_name' => 'Atomic create',
            'email' => $email,
            'role' => RolesAndCapabilities::ROLE_DOCTOR,
            'password' => 'StrongPass123',
        ], $this->adminId);
        $this->restoreAuditInserts();
        // D7: production cache rollback — exact WP-owned keys, never a global
        // flush. The ghost must be unresolvable through normal lookup paths
        // without any test-side cache clearing.
        self::assertSame(0, $this->cache->flushCount(), 'rollback must never global-flush the object cache');
        self::assertContains($login, $this->cache->deletedKeysForGroup('userlogins'), 'login slug key evicted after rollback');
        self::assertContains($email, $this->cache->deletedKeysForGroup('useremail'), 'email key evicted after rollback');
        self::assertNotEmpty($this->cache->deletedKeysForGroup('users'), 'users group entry evicted after rollback');
        foreach ($this->cache->deletedKeysForGroup('users') as $evictedId) {
            self::assertTrue($this->cache->deleted($evictedId, 'user_meta'), 'matching user_meta key evicted after rollback');
        }

        self::assertNotSame('', $result['error'], 'no false success when the audit cannot be persisted');
        self::assertFalse(get_user_by('login', $login), 'the WordPress user must have been rolled back');
        self::assertFalse(get_user_by('email', $email));
        self::assertSame(0, $this->auditCount('STAFF_USER_CREATED'));
        self::assertSame($depthBefore, $this->savepointDepth());

        // The same create succeeds once the fault is removed.
        $login = 'smaa_ok_' . bin2hex(random_bytes(3));
        $email = 'smaa-ok-' . bin2hex(random_bytes(4)) . '@example.test';
        $retry = StaffManagementPage::upsertUser([
            'clinic_id' => $this->clinicId,
            'mode' => 'create',
            'username' => $login,
            'display_name' => 'Atomic create ok',
            'email' => $email,
            'role' => RolesAndCapabilities::ROLE_DOCTOR,
            'password' => 'StrongPass123',
        ], $this->adminId);
        self::assertSame('', $retry['error']);
        $user = get_user_by('login', $login);
        self::assertNotFalse($user);
        self::assertSame(1, $this->auditCount('STAFF_USER_CREATED'));
        self::assertNotNull($this->membershipFor((int) $user->ID));
    }

    // ------------------------------------------------------------------
    // 2 — attach existing user.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringAttachRollsBackMembership(): void
    {
        $existing = self::factory()->user->create(['role' => 'subscriber']);
        $depthBefore = $this->savepointDepth();

        $this->failAuditInserts();
        $result = StaffManagementPage::attachExistingUser([
            'clinic_id' => $this->clinicId,
            'existing_user_id' => $existing,
            'role' => RolesAndCapabilities::ROLE_SECRETARY,
        ], $this->adminId);
        $this->restoreAuditInserts();

        self::assertNotSame('', $result['error']);
        self::assertNull($this->membershipFor($existing), 'the membership must have been rolled back');
        self::assertSame(0, $this->auditCount('STAFF_USER_ATTACHED'));
        self::assertSame($depthBefore, $this->savepointDepth());

        $retry = StaffManagementPage::attachExistingUser([
            'clinic_id' => $this->clinicId,
            'existing_user_id' => $existing,
            'role' => RolesAndCapabilities::ROLE_SECRETARY,
        ], $this->adminId);
        self::assertSame('', $retry['error']);
        self::assertNotNull($this->membershipFor($existing));
        self::assertSame(1, $this->auditCount('STAFF_USER_ATTACHED'));
    }

    // ------------------------------------------------------------------
    // 3 — toggle deactivate.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringDeactivationRollsBackSuspensionAndRoleParking(): void
    {
        $userId = $this->createHealthyDoctor('smaa_deact');
        $membershipId = (int) $this->membershipFor($userId)['id'];
        $depthBefore = $this->savepointDepth();

        $this->failAuditInserts();
        $result = StaffManagementPage::toggleUser($userId, 'deactivate', $this->adminId, $this->clinicId);
        $this->restoreAuditInserts();
        // D7: the rolled-back role/meta cache entries must be evicted by
        // production code (id-scoped), not by a test-side global flush.
        self::assertSame(0, $this->cache->flushCount(), 'rollback must never global-flush the object cache');
        self::assertTrue($this->cache->deleted($userId, 'users'), 'users entry evicted after suspension rollback');
        self::assertTrue($this->cache->deleted($userId, 'user_meta'), 'user_meta entry evicted after suspension rollback');

        self::assertNotSame('', $result['error']);
        self::assertSame('active', (string) $this->membershipFor($userId)['status'], 'the suspension must have been rolled back');
        self::assertContains(RolesAndCapabilities::ROLE_DOCTOR, (array) get_userdata($userId)->roles, 'the WordPress role parking must have been rolled back');
        self::assertSame('', (string) get_user_meta($userId, self::META_PREV_ROLE, true), 'no role-parking meta may survive');
        self::assertSame(0, $this->auditCountForUser('STAFF_USER_DEACTIVATED', $userId));
        self::assertSame($depthBefore, $this->savepointDepth());

        $retry = StaffManagementPage::toggleUser($userId, 'deactivate', $this->adminId, $this->clinicId);
        self::assertSame('', $retry['error']);
        self::assertSame('suspended', (string) $this->membershipFor($userId)['status']);
        self::assertContains('subscriber', (array) get_userdata($userId)->roles);
        self::assertSame(RolesAndCapabilities::ROLE_DOCTOR, (string) get_user_meta($userId, self::META_PREV_ROLE, true));
        self::assertSame(1, $this->auditCountForUser('STAFF_USER_DEACTIVATED', $userId));
        self::assertGreaterThan(0, $membershipId);
    }

    // ------------------------------------------------------------------
    // 4 — toggle activate.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringActivationKeepsSuspensionIntact(): void
    {
        $userId = $this->createHealthyDoctor('smaa_act');
        $deactivate = StaffManagementPage::toggleUser($userId, 'deactivate', $this->adminId, $this->clinicId);
        self::assertSame('', $deactivate['error']);
        self::assertSame('suspended', (string) $this->membershipFor($userId)['status']);

        $depthBefore = $this->savepointDepth();
        $this->failAuditInserts();
        $result = StaffManagementPage::toggleUser($userId, 'activate', $this->adminId, $this->clinicId);
        $this->restoreAuditInserts();
        // D7: production evicts the rewritten users/user_meta entries after
        // the reactivation rollback; no global flush is permitted or needed.
        self::assertSame(0, $this->cache->flushCount(), 'rollback must never global-flush the object cache');
        self::assertTrue($this->cache->deleted($userId, 'users'), 'users entry evicted after reactivation rollback');
        self::assertTrue($this->cache->deleted($userId, 'user_meta'), 'user_meta entry evicted after reactivation rollback');

        self::assertNotSame('', $result['error']);
        self::assertSame('suspended', (string) $this->membershipFor($userId)['status'], 'the reactivation must have been rolled back');
        self::assertContains('subscriber', (array) get_userdata($userId)->roles, 'the parked WordPress role must stay parked');
        self::assertSame(RolesAndCapabilities::ROLE_DOCTOR, (string) get_user_meta($userId, self::META_PREV_ROLE, true), 'the restore meta must survive the rollback');
        self::assertSame(0, $this->auditCountForUser('STAFF_USER_ACTIVATED', $userId));
        self::assertSame($depthBefore, $this->savepointDepth());

        $retry = StaffManagementPage::toggleUser($userId, 'activate', $this->adminId, $this->clinicId);
        self::assertSame('', $retry['error']);
        self::assertSame('active', (string) $this->membershipFor($userId)['status']);
        self::assertContains(RolesAndCapabilities::ROLE_DOCTOR, (array) get_userdata($userId)->roles);
        self::assertSame(1, $this->auditCountForUser('STAFF_USER_ACTIVATED', $userId));
        self::assertTrue(App::audit()->verifyChain(500)['ok'], 'the hash chain must remain valid');
    }

    // ================= helpers =================

    private function createHealthyDoctor(string $prefix): int
    {
        $login = $prefix . '_' . bin2hex(random_bytes(3));
        $result = StaffManagementPage::upsertUser([
            'clinic_id' => $this->clinicId,
            'mode' => 'create',
            'username' => $login,
            'display_name' => 'Doctor ' . $login,
            'email' => $login . '@example.test',
            'role' => RolesAndCapabilities::ROLE_DOCTOR,
            'password' => 'StrongPass123',
        ], $this->adminId);
        self::assertSame('', $result['error'], 'fixture: healthy doctor creation');

        $user = get_user_by('login', $login);
        self::assertNotFalse($user);

        return (int) $user->ID;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function membershipFor(int $userId): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d AND wp_user_id = %d LIMIT 1',
            [$this->clinicId, $userId]
        );

        return is_array($row) ? $row : null;
    }

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND clinic_id = %d',
            [$action, $this->clinicId]
        );
    }

    private function auditCountForUser(string $action, int $userId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_id = %d',
            [$action, $userId]
        );
    }

    private function savepointDepth(): int
    {
        return is_array($GLOBALS['__cpms_sp_stack'] ?? null) ? count($GLOBALS['__cpms_sp_stack']) : 0;
    }

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

    private function createClinic(): int
    {
        global $wpdb;

        $organizationId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_organizations ORDER BY id ASC LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertGreaterThan(0, $organizationId, 'organization fixture must exist');

        $slug = 'staff-atomicity-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $organizationId,
                'Staff atomicity ' . $slug,
                $slug,
                'UTC',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }
}
