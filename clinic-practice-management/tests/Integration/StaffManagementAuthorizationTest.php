<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Phase 3 Slice 2 — StaffManagementPage read and write authorization coverage.
 *
 * The read tests execute render() itself so a regression in Clinic resolution,
 * WordPress user queries, or clinician/person lookup is observable in rendered HTML.
 * The write tests exercise the existing pure backend write methods.
 */
final class StaffManagementAuthorizationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::resetScope();
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        App::resetScope();
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];
        parent::tearDown();
    }

    public function testGlobalAdministratorWithoutClinicMembershipRendersNoClinicStaffOrPersonData(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $clinicA = $this->createClinic('staff-read-admin-a');
        $clinicB = $this->createClinic('staff-read-admin-b');
        $staffALogin = 'read_admin_a_' . $suffix;
        $staffAId = $this->makeUser($staffALogin, RolesAndCapabilities::ROLE_SECRETARY);
        $this->seedActiveMembership($staffAId, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        $doctorBLogin = 'read_admin_b_' . $suffix;
        $doctorBId = $this->makeUser($doctorBLogin, RolesAndCapabilities::ROLE_DOCTOR);
        $this->seedActiveMembership($doctorBId, $clinicB, RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianBName = 'READ-ADMIN-CLINIC-B-PERSON-' . $suffix;
        $this->createClinician($clinicB, $doctorBId, $clinicianBName);

        $adminId = $this->makeUser('read_admin_actor_' . $suffix, 'administrator');
        wp_set_current_user($adminId);
        self::assertTrue(current_user_can('manage_options'), 'fixture administrator must have manage_options');
        self::assertTrue(current_user_can(RolesAndCapabilities::CONFIG), 'fixture administrator must have cpms_config');
        self::assertSame([], $this->activeClinicIdsFor($adminId), 'administrator fixture must have zero active Clinic memberships');

        $html = $this->renderStaffPage(null);

        $this->assertRenderReached($html);
        $this->assertNoRenderedMarkers(
            [$staffALogin, $staffALogin . '@test.local', $doctorBLogin, $doctorBLogin . '@test.local', $clinicianBName],
            $html,
            'installation administrator without Clinic membership must not receive Clinic A/B staff or clinician/person data'
        );
    }

    public function testClinicAManagerRequestingClinicBCannotRenderClinicBStaffOrPersonData(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $clinicA = $this->createClinic('staff-read-cross-a');
        $clinicB = $this->createClinic('staff-read-cross-b');
        $managerId = $this->makeUser('read_cross_manager_' . $suffix, RolesAndCapabilities::ROLE_MANAGER);
        $this->seedActiveMembership($managerId, $clinicA, RolesAndCapabilities::ROLE_MANAGER);
        $staffAId = $this->makeUser('read_cross_a_' . $suffix, RolesAndCapabilities::ROLE_SECRETARY);
        $this->seedActiveMembership($staffAId, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        $doctorBLogin = 'read_cross_b_' . $suffix;
        $doctorBId = $this->makeUser($doctorBLogin, RolesAndCapabilities::ROLE_DOCTOR);
        $this->seedActiveMembership($doctorBId, $clinicB, RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianBName = 'READ-CROSS-CLINIC-B-PERSON-' . $suffix;
        $this->createClinician($clinicB, $doctorBId, $clinicianBName);

        wp_set_current_user($managerId);
        self::assertTrue(current_user_can(RolesAndCapabilities::CONFIG), 'Clinic A manager fixture must have cpms_config');
        self::assertSame([$clinicA], $this->activeClinicIdsFor($managerId), 'actor must be authorized only in Clinic A');
        self::assertNull(App::membership_service()->membership_for($clinicB, $managerId), 'actor must have no Clinic B membership');

        $html = $this->renderStaffPage($clinicB);

        $this->assertRenderReached($html);
        $this->assertNoRenderedMarkers(
            [$doctorBLogin, $doctorBLogin . '@test.local', $clinicianBName],
            $html,
            'Clinic A-only manager must not render a requested Clinic B staff/person fixture'
        );
    }

    public function testAuthorizedClinicAManagerRendersOnlyExplicitClinicAStaffAndPersonData(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $clinicA = $this->createClinic('staff-read-allowed-a');
        $clinicB = $this->createClinic('staff-read-allowed-b');
        $managerId = $this->makeUser('read_allowed_manager_' . $suffix, RolesAndCapabilities::ROLE_MANAGER);
        $this->seedActiveMembership($managerId, $clinicA, RolesAndCapabilities::ROLE_MANAGER);
        $doctorALogin = 'read_allowed_a_' . $suffix;
        $doctorAId = $this->makeUser($doctorALogin, RolesAndCapabilities::ROLE_DOCTOR);
        $this->seedActiveMembership($doctorAId, $clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianAName = 'READ-ALLOWED-CLINIC-A-PERSON-' . $suffix;
        $this->createClinician($clinicA, $doctorAId, $clinicianAName);
        $staffBLogin = 'read_allowed_b_' . $suffix;
        $staffBId = $this->makeUser($staffBLogin, RolesAndCapabilities::ROLE_SECRETARY);
        $this->seedActiveMembership($staffBId, $clinicB, RolesAndCapabilities::ROLE_SECRETARY);

        wp_set_current_user($managerId);
        self::assertSame([$clinicA], $this->activeClinicIdsFor($managerId), 'manager fixture must be authorized only in Clinic A');

        $html = $this->renderStaffPage($clinicA);

        $this->assertRenderReached($html);
        self::assertStringContainsString($doctorALogin, $html, 'explicitly authorized Clinic A staff must remain visible');
        self::assertStringContainsString($doctorALogin . '@test.local', $html, 'Clinic A staff email must remain visible');
        self::assertStringContainsString($clinicianAName, $html, 'Clinic A clinician/person link must remain visible');
        $this->assertNoRenderedMarkers(
            [$staffBLogin, $staffBLogin . '@test.local'],
            $html,
            'authorized Clinic A rendering must not include Clinic B staff data'
        );
    }

    public function testMultiClinicManagerWithoutExplicitSelectionRendersNoClinicRows(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $clinicA = $this->createClinic('staff-read-multi-a');
        $clinicB = $this->createClinic('staff-read-multi-b');
        $managerId = $this->makeUser('read_multi_manager_' . $suffix, RolesAndCapabilities::ROLE_MANAGER);
        $this->seedActiveMembership($managerId, $clinicA, RolesAndCapabilities::ROLE_MANAGER);
        $this->seedActiveMembership($managerId, $clinicB, RolesAndCapabilities::ROLE_MANAGER);
        $staffALogin = 'read_multi_a_' . $suffix;
        $staffAId = $this->makeUser($staffALogin, RolesAndCapabilities::ROLE_SECRETARY);
        $this->seedActiveMembership($staffAId, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        $staffBLogin = 'read_multi_b_' . $suffix;
        $staffBId = $this->makeUser($staffBLogin, RolesAndCapabilities::ROLE_SECRETARY);
        $this->seedActiveMembership($staffBId, $clinicB, RolesAndCapabilities::ROLE_SECRETARY);

        wp_set_current_user($managerId);
        self::assertEqualsCanonicalizing([$clinicA, $clinicB], $this->activeClinicIdsFor($managerId));

        $html = $this->renderStaffPage(null);

        $this->assertRenderReached($html);
        $this->assertNoRenderedMarkers(
            [$staffALogin, $staffBLogin],
            $html,
            'multi-Clinic manager must explicitly select an authorized Clinic before any Clinic rows render'
        );
    }

    public function testNonPositiveReadClinicSelectionIsInvalidAndRendersNoClinicRows(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $clinicA = $this->createClinic('staff-read-invalid-a');
        $managerId = $this->makeUser('read_invalid_manager_' . $suffix, RolesAndCapabilities::ROLE_MANAGER);
        $this->seedActiveMembership($managerId, $clinicA, RolesAndCapabilities::ROLE_MANAGER);
        $staffALogin = 'read_invalid_a_' . $suffix;
        $staffAId = $this->makeUser($staffALogin, RolesAndCapabilities::ROLE_SECRETARY);
        $this->seedActiveMembership($staffAId, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($managerId);

        $html = $this->renderStaffPage(-$clinicA);

        $this->assertRenderReached($html);
        $this->assertNoRenderedMarkers(
            [$staffALogin, $staffALogin . '@test.local'],
            $html,
            'clinic_id <= 0 must be invalid read context, not normalized into a Clinic selection'
        );
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

    private function renderStaffPage(int|string|null $requestedClinicId): string
    {
        $_GET = ['page' => StaffManagementPage::PAGE_SLUG];
        if ($requestedClinicId !== null) {
            $_GET['clinic_id'] = (string) $requestedClinicId;
        }

        ob_start();
        try {
            StaffManagementPage::render();

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
    }

    private function assertRenderReached(string $html): void
    {
        self::assertStringContainsString(
            '<h1>کاربران و دسترسی‌ها</h1>',
            $html,
            'real StaffManagementPage render path must complete before read assertions'
        );
    }

    /** @param list<string> $markers */
    private function assertNoRenderedMarkers(array $markers, string $html, string $message): void
    {
        $observed = array_values(array_filter(
            $markers,
            static fn (string $marker): bool => str_contains($html, $marker)
        ));

        self::assertSame([], $observed, $message . '; observed forbidden markers: ' . implode(', ', $observed));
    }

    /** @return list<int> */
    private function activeClinicIdsFor(int $userId): array
    {
        $clinicIds = array_map(
            static fn (array $membership): int => (int) ($membership['clinic_id'] ?? 0),
            App::membership_service()->active_memberships_for_user($userId)
        );
        sort($clinicIds);

        return $clinicIds;
    }

    private function seedActiveMembership(int $userId, int $clinicId, string $role): int
    {
        $membershipId = cpms_test_seed_membership($userId, $clinicId, $role);
        self::assertGreaterThan(0, $membershipId, 'membership fixture must be materially persisted');
        $membership = App::membership_service()->membership_for($clinicId, $userId);
        self::assertNotNull($membership, 'persisted Clinic membership fixture must be queryable');
        self::assertSame('active', (string) $membership['status']);
        self::assertSame($role, (string) $membership['role_key']);

        return $membershipId;
    }

    private function createClinician(int $clinicId, int $userId, string $fullName): int
    {
        global $wpdb;

        $now = App::db()->nowUtcSql();
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $fullName,
                $userId,
                $now,
                $now
            )
        );
        self::assertSame(1, $inserted, 'clinician/person fixture must be materially persisted');
        $clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $clinicianId, 'clinician/person fixture must have a persisted id');

        return $clinicianId;
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
