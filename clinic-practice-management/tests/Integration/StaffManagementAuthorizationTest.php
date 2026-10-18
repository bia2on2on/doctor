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
        $invalidUnderUnauthorized = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'clinic_id' => $clinicId,
            'username' => '',
            'display_name' => '',
            'email' => 'not-an-email',
            'role' => 'administrator',
            'password' => 'x',
        ], $adminId);
        self::assertSame( $result['error'], $invalidUnderUnauthorized['error'], 'authorization denial takes precedence over invalid profile and role fields' );
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

    public function testStaffAndRoleFormsRequireExplicitClinicSelection(): void
    {
        $clinicId = $this->createClinic('staff-read-no-default');
        $managerId = $this->makeUser('staff_read_no_default_manager', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($managerId, $clinicId, RolesAndCapabilities::ROLE_MANAGER);
        $fixture = $this->seedStaffPersonFixture($clinicId, 'staff_read_no_default');
        wp_set_current_user($managerId);

        $previousGet = $_GET;
        try {
            unset($_GET['clinic_id']);
            $_GET['page'] = StaffManagementPage::PAGE_SLUG;
            $unselected = $this->renderStaffPage();
            self::assertStringContainsString('Clinic را صریحاً از فهرست بالا انتخاب کنید', $unselected, 'the sole active membership is not treated as a UI default');
            self::assertStringNotContainsString($fixture['display_name'], $unselected, 'staff rows stay hidden before Clinic selection');
            self::assertStringNotContainsString('name="action" value="cpms_staff_save"', $unselected, 'no staff write form is emitted without explicit Clinic selection');
            self::assertStringNotContainsString('cpms_staff_assign_custom_role', $unselected, 'no custom-role assignment form is emitted without explicit Clinic selection');

            $_GET['clinic_id'] = (string) $clinicId;
            $selected = $this->renderStaffPage();
            self::assertStringContainsString($fixture['display_name'], $selected, 'explicitly selected Clinic rows are visible');
            self::assertStringContainsString('name="action" value="cpms_staff_save"', $selected, 'staff/role forms are available only in explicit scope');
            self::assertStringContainsString('_wpnonce', $selected, 'staff and role forms include WordPress nonce fields');
            self::assertStringContainsString('id="u_role"', $selected, 'built-in membership role assignment remains available');
        } finally {
            $_GET = $previousGet;
        }
    }

    public function testCustomRoleAssignmentRejectsInvalidNonceBeforeMembershipMutation(): void
    {
        $clinicId = $this->createClinic( 'staff-invalid-assignment-nonce' );
        $managerId = $this->makeUser( 'staff_invalid_nonce_manager', RolesAndCapabilities::ROLE_MANAGER );
        cpms_test_seed_membership( $managerId, $clinicId, RolesAndCapabilities::ROLE_MANAGER );
        $targetId = $this->makeUser( 'staff_invalid_nonce_target', RolesAndCapabilities::ROLE_SECRETARY );
        cpms_test_seed_membership( $targetId, $clinicId, RolesAndCapabilities::ROLE_SECRETARY );
        wp_set_current_user( $managerId );

        $roleKey = 'sin_nonce_' . bin2hex( random_bytes( 2 ) );
        App::custom_role_management_service()->create( $managerId, $clinicId, $roleKey, 'Nonce test role', [ 'cpms_patient_read' ] );
        $beforeMembership = App::membership_service()->membership_for( $clinicId, $targetId );
        self::assertNotNull( $beforeMembership );
        $db = App::db();
        $auditBefore = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_audit_logs' ) . ' WHERE clinic_id = %d', [ $clinicId ] );

        $oldPost    = $_POST;
        $oldRequest = $_REQUEST;
        $_POST      = [
            'clinic_id'       => (string) $clinicId,
            'target_user_id'  => (string) $targetId,
            'custom_role_key' => $roleKey,
            '_wpnonce'        => 'invalid-staff-assignment-nonce',
        ];
        $_REQUEST = $_POST;
        $dieFilter = static fn ( $handler ) => static function ( $message ): void {
            throw new \RuntimeException( 'staff assignment nonce denied' );
        };
        add_filter( 'wp_die_handler', $dieFilter );
        try {
            try {
                StaffManagementPage::assign_custom_role();
                self::fail( 'invalid custom-role assignment nonce must stop the handler' );
            } catch ( \RuntimeException $exception ) {
                self::assertSame( 'staff assignment nonce denied', $exception->getMessage() );
            }
        } finally {
            remove_filter( 'wp_die_handler', $dieFilter );
            $_POST    = $oldPost;
            $_REQUEST = $oldRequest;
        }

        self::assertSame( (string) $beforeMembership['role_key'], (string) App::membership_service()->membership_for( $clinicId, $targetId )['role_key'], 'nonce denial preserves the membership role' );
        self::assertSame( $auditBefore, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_audit_logs' ) . ' WHERE clinic_id = %d', [ $clinicId ] ), 'nonce denial emits no assignment audit event' );
    }

    public function testStaffWritesRejectMissingClinicEvenForUniqueMembership(): void
    {
        $clinicId = $this->createClinic('staff-authz-no-default');
        $managerId = $this->makeUser('staff_authz_no_default_manager', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($managerId, $clinicId, RolesAndCapabilities::ROLE_MANAGER);
        $targetId = $this->makeUser('staff_authz_no_default_target', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($targetId, $clinicId, RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($managerId);

        $create = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'staff_authz_no_default_new',
            'display_name' => 'No default Clinic target',
            'email' => 'staff-authz-no-default-new@test.local',
            'role' => RolesAndCapabilities::ROLE_SECRETARY,
            'password' => 'StrongPass123',
        ], $managerId);
        self::assertNotSame('', $create['error'], 'write without Clinic selector is denied');
        self::assertStringContainsString('صریحاً انتخاب کنید', $create['error']);
        self::assertFalse(get_user_by('login', 'staff_authz_no_default_new'), 'no user is created under an inferred Clinic');

        $toggle = StaffManagementPage::toggleUser($targetId, 'deactivate', $managerId);
        self::assertNotSame('', $toggle['error'], 'membership status write without Clinic selector is denied');
        self::assertSame('active', (string) App::membership_service()->membership_for($clinicId, $targetId)['status'], 'no inferred-context mutation occurred');
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

        // The explicitly selected Clinic A is only actor context: it cannot
        // authorize a mutation when durable target membership belongs to Clinic B.
        $result = StaffManagementPage::toggleUser($targetId, 'deactivate', $managerId, $clinicA);

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

        $deactivate = StaffManagementPage::toggleUser($targetId, 'deactivate', $managerId, $clinicId);
        self::assertSame('', $deactivate['error']);
        self::assertSame('suspended', (string) App::membership_service()->membership_for($clinicId, $targetId)['status']);

        $activate = StaffManagementPage::toggleUser($targetId, 'activate', $managerId, $clinicId);
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
