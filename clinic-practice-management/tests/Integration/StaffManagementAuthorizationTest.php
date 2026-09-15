<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Auth\RolesAndCapabilities;
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
        unset($_GET['clinic_id'], $_GET['edit'], $_GET['page']);
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

    public function testPasswordResetCannotCrossClinicTargetOwnership(): void
    {
        $clinicA = $this->createClinic('staff-authz-password-a');
        $clinicB = $this->createClinic('staff-authz-password-b');
        $managerId = $this->makeUser('staff_authz_password_manager', 'cpms_manager');
        cpms_test_seed_membership($managerId, $clinicA, 'cpms_manager');
        $targetId = $this->makeUser('staff_authz_password_target', 'cpms_secretary');
        cpms_test_seed_membership($targetId, $clinicB, 'cpms_secretary');
        wp_set_current_user($managerId);

        $result = StaffManagementPage::initiatePasswordReset($targetId, $managerId, $clinicA);

        self::assertNotSame('', $result['error'], 'password reset must use durable target Clinic ownership');
    }

    public function testSameManagerCanManageTwoClinicsOnlyWithExplicitClinicSelection(): void
    {
        $clinicA = $this->createClinic('staff-authz-multi-a');
        $clinicB = $this->createClinic('staff-authz-multi-b');
        $managerId = $this->makeUser('staff_authz_multi_manager', 'cpms_manager');
        cpms_test_seed_membership($managerId, $clinicA, 'cpms_manager');
        cpms_test_seed_membership($managerId, $clinicB, 'cpms_manager');
        wp_set_current_user($managerId);

        $in = [
            'mode' => 'create',
            'username' => 'staff_authz_multi_target_a',
            'display_name' => 'Multi clinic target A',
            'email' => 'staff-authz-multi-a@test.local',
            'role' => 'cpms_secretary',
            'password' => 'StrongPass123',
        ];
        $resultA = StaffManagementPage::upsertUser(array_merge($in, ['clinic_id' => $clinicA]), $managerId);
        $resultB = StaffManagementPage::upsertUser(array_merge($in, [
            'clinic_id' => $clinicB,
            'username' => 'staff_authz_multi_target_b',
            'display_name' => 'Multi clinic target B',
            'email' => 'staff-authz-multi-b@test.local',
        ]), $managerId);

        self::assertSame('', $resultA['error']);
        self::assertSame('', $resultB['error']);
        self::assertNotNull(App::membership_service()->membership_for($clinicA, (int) $resultA['user_id']));
        self::assertNotNull(App::membership_service()->membership_for($clinicB, (int) $resultB['user_id']));
    }

    public function testActiveManagerCanSuspendAndReactivateOwnClinicMembership(): void
    {
        $clinicId = $this->createClinic('staff-authz-toggle-allowed');
        $managerId = $this->makeUser('staff_authz_toggle_allowed_manager', 'cpms_manager');
        cpms_test_seed_membership($managerId, $clinicId, 'cpms_manager');
        $targetId = $this->makeUser('staff_authz_toggle_allowed_target', 'cpms_secretary');
        cpms_test_seed_membership($targetId, $clinicId, 'cpms_secretary');
        wp_set_current_user($managerId);

        $deactivate = StaffManagementPage::toggleUser($targetId, 'deactivate', $managerId);
        self::assertSame('', $deactivate['error']);
        self::assertSame('suspended', (string) App::membership_service()->membership_for($clinicId, $targetId)['status']);

        $activate = StaffManagementPage::toggleUser($targetId, 'activate', $managerId);
        self::assertSame('', $activate['error']);
        self::assertSame('active', (string) App::membership_service()->membership_for($clinicId, $targetId)['status']);
    }

    public function testAdministratorWithoutClinicMembershipCannotRenderClinicStaffOrPersonRows(): void
    {
        $clinicA = $this->createClinic('staff-read-admin-a');
        $clinicB = $this->createClinic('staff-read-admin-b');
        $fixtureA = $this->seedStaffPersonFixture($clinicA, 'staff_read_admin_a');
        $fixtureB = $this->seedStaffPersonFixture($clinicB, 'staff_read_admin_b');

        $adminId = $this->makeUser('staff_read_global_admin', 'administrator');
        wp_set_current_user($adminId);
        unset($_GET['clinic_id']);

        $html = $this->renderStaffPage();
        $leakedMarkers = array_values(array_filter(
            array_merge($fixtureA['markers'], $fixtureB['markers']),
            static fn (string $marker): bool => str_contains($html, $marker)
        ));

        self::assertSame([], $leakedMarkers, 'administrator without an active Clinic membership must not observe Clinic staff/person fixtures');
    }

    public function testClinicAOnlyManagerCannotRenderClinicBRowsFromSubmittedClinicSelection(): void
    {
        $clinicA = $this->createClinic('staff-read-cross-a');
        $clinicB = $this->createClinic('staff-read-cross-b');
        $fixtureA = $this->seedStaffPersonFixture($clinicA, 'staff_read_cross_a');
        $fixtureB = $this->seedStaffPersonFixture($clinicB, 'staff_read_cross_b');

        $managerId = $this->makeUser('staff_read_cross_manager', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($managerId, $clinicA, RolesAndCapabilities::ROLE_MANAGER);
        wp_set_current_user($managerId);
        $_GET['clinic_id'] = (string) $clinicB;

        $html = $this->renderStaffPage();
        $leakedMarkers = array_values(array_filter(
            array_merge($fixtureA['markers'], $fixtureB['markers']),
            static fn (string $marker): bool => str_contains($html, $marker)
        ));

        self::assertSame([], $leakedMarkers, 'a Clinic A-only manager must not render rows after submitting Clinic B');
    }

    public function testAuthorizedManagerRendersOnlyExplicitlySelectedClinicRows(): void
    {
        $clinicA = $this->createClinic('staff-read-allowed-a');
        $clinicB = $this->createClinic('staff-read-allowed-b');
        $fixtureA = $this->seedStaffPersonFixture($clinicA, 'staff_read_allowed_a');
        $fixtureB = $this->seedStaffPersonFixture($clinicB, 'staff_read_allowed_b');

        $managerId = $this->makeUser('staff_read_allowed_manager', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($managerId, $clinicA, RolesAndCapabilities::ROLE_MANAGER);
        wp_set_current_user($managerId);
        $_GET['clinic_id'] = (string) $clinicA;

        $html = $this->renderStaffPage();

        self::assertStringContainsString($fixtureA['display_name'], $html, 'authorized Clinic A manager must see Clinic A staff');
        self::assertStringContainsString($fixtureA['clinician_name'], $html, 'authorized Clinic A manager must see the Clinic A person fixture');
        foreach ($fixtureB['markers'] as $marker) {
            self::assertStringNotContainsString($marker, $html, 'Clinic A manager must not see Clinic B staff/person data');
        }
    }

    /**
     * @return array{display_name:string, clinician_name:string, markers:list<string>}
     */
    private function seedStaffPersonFixture(int $clinicId, string $prefix): array
    {
        global $wpdb;

        $userId = $this->makeUser($prefix . '_user', RolesAndCapabilities::ROLE_DOCTOR);
        $displayName = $prefix . ' Display';
        $email = $prefix . '@test.local';
        wp_update_user([
            'ID' => $userId,
            'display_name' => $displayName,
            'user_email' => $email,
        ]);
        $membershipId = cpms_test_seed_membership($userId, $clinicId, RolesAndCapabilities::ROLE_DOCTOR);
        self::assertGreaterThan(0, $membershipId, 'dynamic Clinic staff membership fixture must be persisted');

        $clinicianName = $prefix . ' Person';
        $now = App::db()->nowUtcSql();
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                    (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $clinicianName,
                $userId,
                $now,
                $now
            )
        );
        self::assertSame(1, $inserted, 'dynamic Clinic person fixture must be persisted');

        return [
            'display_name' => $displayName,
            'clinician_name' => $clinicianName,
            'markers' => [$prefix . '_user', $displayName, $email, $clinicianName],
        ];
    }

    private function renderStaffPage(): string
    {
        ob_start();
        try {
            StaffManagementPage::render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
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
