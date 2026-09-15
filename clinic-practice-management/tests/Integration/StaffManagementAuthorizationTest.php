<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Phase 3 Slice 2 — RED coverage for the current StaffManagementPage write boundary.
 *
 * These tests intentionally exercise the existing pure backend write methods. They
 * are not tests for a future helper: before the AuthorizationService is applied,
 * the existing methods really create/update/deactivate users without Clinic
 * authorization.
 */
final class StaffManagementAuthorizationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::resetScope();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testGlobalAdministratorWithoutClinicMembershipCannotCreateStaff(): void
    {
        $clinicId = $this->createClinic('staff-authz-admin');
        $adminId = $this->makeUser('staff_authz_admin', 'administrator');
        wp_set_current_user($adminId);

        $result = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'clinic_id' => $clinicId,
            'username' => 'staff_authz_admin_target',
            'display_name' => 'Unauthorized administrator target',
            'email' => 'staff-authz-admin-target@test.local',
            'role' => 'cpms_secretary',
            'password' => 'StrongPass123',
        ], $adminId);

        self::assertNotSame('', $result['error'], 'global administrator without Clinic membership must be denied');
        self::assertFalse(get_user_by('login', 'staff_authz_admin_target'), 'denied create must not persist a WP user');
    }

    public function testManagerInClinicACannotUpdateDurableMembershipInClinicB(): void
    {
        $clinicA = $this->createClinic('staff-authz-a');
        $clinicB = $this->createClinic('staff-authz-b');
        $managerId = $this->makeUser('staff_authz_manager_a', 'cpms_manager');
        cpms_test_seed_membership($managerId, $clinicA, 'cpms_manager');

        $targetId = $this->makeUser('staff_authz_target_b', 'cpms_secretary');
        cpms_test_seed_membership($targetId, $clinicB, 'cpms_secretary');
        wp_set_current_user($managerId);

        $result = StaffManagementPage::upsertUser([
            'mode' => 'update',
            'clinic_id' => $clinicA,
            'user_id' => $targetId,
            'display_name' => 'Cross-clinic update must not apply',
            'email' => 'cross-clinic-update@test.local',
            'role' => 'cpms_secretary',
            'password' => '',
        ], $managerId);

        self::assertNotSame('', $result['error'], 'Clinic A manager must not update a durable Clinic B membership');
        $target = get_userdata($targetId);
        self::assertNotFalse($target);
        self::assertSame('staff_authz_target_b', (string) $target->display_name, 'cross-clinic update must not persist');
        self::assertSame('staff_authz_target_b@test.local', (string) $target->user_email, 'cross-clinic email update must not persist');
    }

    public function testSuspendedManagerCannotCreateStaff(): void
    {
        $clinicId = $this->createClinic('staff-authz-suspended');
        $managerId = $this->makeUser('staff_authz_suspended_manager', 'cpms_manager');
        $membershipId = cpms_test_seed_membership($managerId, $clinicId, 'cpms_manager');
        App::membership_service()->suspend_membership($membershipId);
        wp_set_current_user($managerId);

        $result = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'clinic_id' => $clinicId,
            'username' => 'staff_authz_suspended_target',
            'display_name' => 'Suspended manager target',
            'email' => 'staff-authz-suspended-target@test.local',
            'role' => 'cpms_secretary',
            'password' => 'StrongPass123',
        ], $managerId);

        self::assertNotSame('', $result['error'], 'suspended manager must be denied');
        self::assertFalse(get_user_by('login', 'staff_authz_suspended_target'), 'suspended manager must not create a user');
    }

    public function testActiveManagerWithScopedPermissionCanCreateStaffInItsClinic(): void
    {
        $clinicId = $this->createClinic('staff-authz-allowed');
        $managerId = $this->makeUser('staff_authz_allowed_manager', 'cpms_manager');
        cpms_test_seed_membership($managerId, $clinicId, 'cpms_manager');
        wp_set_current_user($managerId);

        $result = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'clinic_id' => $clinicId,
            'username' => 'staff_authz_allowed_target',
            'display_name' => 'Allowed manager target',
            'email' => 'staff-authz-allowed-target@test.local',
            'role' => 'cpms_secretary',
            'password' => 'StrongPass123',
        ], $managerId);

        self::assertSame('', $result['error'], 'active manager with cpms_config in the target Clinic must remain allowed');
        $targetId = (int) $result['user_id'];
        self::assertGreaterThan(0, $targetId);
        $targetMembership = App::membership_service()->membership_for($clinicId, $targetId);
        self::assertNotNull($targetMembership, 'successful staff create must establish durable target Clinic membership');
        self::assertSame('active', (string) $targetMembership['status']);
        self::assertSame('cpms_secretary', (string) $targetMembership['role_key']);
    }

    public function testManagerCannotToggleTargetMembershipInAnotherClinic(): void
    {
        $clinicA = $this->createClinic('staff-authz-toggle-a');
        $clinicB = $this->createClinic('staff-authz-toggle-b');
        $managerId = $this->makeUser('staff_authz_toggle_manager', 'cpms_manager');
        cpms_test_seed_membership($managerId, $clinicA, 'cpms_manager');
        $targetId = $this->makeUser('staff_authz_toggle_target', 'cpms_secretary');
        cpms_test_seed_membership($targetId, $clinicB, 'cpms_secretary');
        wp_set_current_user($managerId);

        // The current public helper has no Clinic parameter, so this proves the
        // existing unique-membership path cannot silently treat the target as
        // belonging to the actor's Clinic.
        $result = StaffManagementPage::toggleUser($targetId, 'deactivate', $managerId);

        self::assertNotSame('', $result['error'], 'cross-clinic toggle must be denied');
        self::assertContains('cpms_secretary', (array) get_userdata($targetId)->roles);
        self::assertSame('active', (string) App::membership_service()->membership_for($clinicB, $targetId)['status']);
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'StrongPass123', $login . '@test.local');
        self::assertGreaterThan(0, $userId, "user {$login} must be created");
        wp_update_user(['ID' => $userId, 'role' => $role]);

        return $userId;
    }

    private function createClinic(string $slugPrefix): int
    {
        global $wpdb;

        $organizationId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_organizations ORDER BY id ASC LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertGreaterThan(0, $organizationId, 'an active organization fixture must exist');

        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $organizationId,
                'Staff authz ' . $slug,
                $slug,
                'UTC',
                $now,
                $now
            )
        );

        $clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $clinicId, 'dynamic Clinic fixture must be persisted');

        return $clinicId;
    }
}
