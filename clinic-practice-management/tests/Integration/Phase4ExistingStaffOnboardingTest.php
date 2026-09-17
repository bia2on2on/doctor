<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Phase 4 — existing WP user onboarding through the current Staff Management
 * write path.
 *
 * The primary test uses the existing StaffManagementPage::upsertUser()
 * boundary and the explicit action later added to that product path. The
 * test-only predecessor used the same boundary with the current create
 * contract; main rejected it with "Sorry, that username already exists!",
 * which was captured as the valid RED before the production change.
 */
final class Phase4ExistingStaffOnboardingTest extends WP_UnitTestCase
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

    public function testClinicBStaffPathAttachesExistingUserWithoutCreatingIdentity(): void
    {
        $clinicA = $this->createClinic('phase4-existing-a');
        $clinicB = $this->createClinic('phase4-existing-b');
        self::assertNotSame($clinicA, $clinicB, 'Clinic A and Clinic B must be distinct dynamic fixtures');

        $managerId = $this->makeUser('phase4_existing_manager', RolesAndCapabilities::ROLE_MANAGER);
        $managerMembershipId = cpms_test_seed_membership($managerId, $clinicB, RolesAndCapabilities::ROLE_MANAGER);
        self::assertGreaterThan(0, $managerMembershipId, 'Clinic-B operator membership must be persisted');
        self::assertTrue(
            App::authorization_service()->can($managerId, $clinicB, RolesAndCapabilities::CONFIG),
            'Clinic-B operator must have the existing scoped CONFIG authorization'
        );

        $userId = $this->makeUser('phase4_existing_user', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicAMembershipId = cpms_test_seed_membership($userId, $clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        self::assertGreaterThan(0, $clinicAMembershipId, 'existing Clinic-A membership must be persisted');

        $clinicianId = App::clinicianRepository()->create(
            $clinicA,
            [
                'full_name' => 'Phase 4 Existing Professional',
                'wp_user_id' => $userId,
                'is_active' => 1,
            ]
        );
        self::assertGreaterThan(0, $clinicianId, 'exactly one professional identity must be persisted for U');

        $existingUser = get_userdata($userId);
        self::assertNotFalse($existingUser, 'existing WP user U must exist before the attach action');
        self::assertSame($userId, (int) $existingUser->ID);
        self::assertSame($clinicA, (int) App::membership_service()->membership_for($clinicA, $userId)['clinic_id']);
        self::assertSame('active', (string) App::membership_service()->membership_for($clinicA, $userId)['status']);
        self::assertNull(
            App::membership_service()->membership_for($clinicB, $userId),
            'U must have no Clinic-B membership before the attach action'
        );
        self::assertSame(
            1,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d',
                [$userId]
            ),
            'U must have exactly one clinician identity before the attach action'
        );
        $homeClinicBefore = (int) App::clinicianRepository()->find($clinicianId)['clinic_id'];
        self::assertSame($clinicA, $homeClinicBefore, 'U clinician home Clinic must be A before the attach action');
        $wpRolesBefore = (array) $existingUser->roles;

        $usersBefore = get_users(['fields' => 'ids', 'number' => -1]);
        wp_set_current_user($managerId);

        // Existing supported product boundary: current StaffManagementPage write
        // path. No new method is called; this is the requested action expressed
        // at the nearest real handler contract available on main.
        $result = StaffManagementPage::upsertUser(
            [
                'mode' => 'attach_existing',
                'clinic_id' => $clinicB,
                'existing_user_id' => $userId,
                'role' => RolesAndCapabilities::ROLE_DOCTOR,
            ],
            $managerId
        );

        self::assertSame(
            '',
            (string) $result['error'],
            'Clinic-B Staff Management must attach the selected existing WP user instead of creating or rejecting a new account'
        );
        self::assertSame($userId, (int) $result['user_id'], 'the product action must return the existing WP user id');
        self::assertSame($usersBefore, get_users(['fields' => 'ids', 'number' => -1]), 'no second WP user may be created');
        $userAfter = get_userdata($userId);
        self::assertNotFalse($userAfter);
        self::assertSame($wpRolesBefore, (array) $userAfter->roles, 'attach must not rewrite the existing WP role');
        self::assertSame($userId, (int) $userAfter->ID, 'the original WP user must remain the same account');
        self::assertSame(
            1,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d',
                [$userId]
            ),
            'the original clinician identity must remain unique'
        );
        self::assertSame($clinicA, (int) App::clinicianRepository()->find($clinicianId)['clinic_id'], 'home Clinic A must remain unchanged');

        $membershipA = App::membership_service()->membership_for($clinicA, $userId);
        $membershipB = App::membership_service()->membership_for($clinicB, $userId);
        self::assertNotNull($membershipA, 'existing Clinic-A membership must be preserved');
        self::assertSame('active', (string) $membershipA['status']);
        self::assertNotNull($membershipB, 'new Clinic-B membership must be persisted');
        self::assertSame('active', (string) $membershipB['status']);
        self::assertSame(RolesAndCapabilities::ROLE_DOCTOR, (string) $membershipB['role_key']);
        self::assertSame(
            2,
            count(App::membership_service()->active_memberships_for_user($userId)),
            'U must have exactly the two intended Clinic memberships and no unrelated membership'
        );
        self::assertTrue(
            App::authorization_service()->can($userId, $clinicB, RolesAndCapabilities::MEDICAL_READ),
            'Clinic-B membership role must retain the doctor capability semantics'
        );
        self::assertFalse(
            App::authorization_service()->can($userId, $clinicB, RolesAndCapabilities::CONFIG),
            'Clinic-B doctor membership must not gain manager CONFIG authorization'
        );
    }

    public function testOperatorWithoutAnyClinicAuthorizationCannotAttachIntoClinicB(): void
    {
        $fixture = $this->createExistingUserFixture();
        $operatorId = $this->makeUser('phase4_no_membership_operator', RolesAndCapabilities::ROLE_MANAGER);
        wp_set_current_user($operatorId);

        $result = StaffManagementPage::upsertUser(
            [
                'mode' => 'attach_existing',
                'clinic_id' => $fixture['clinic_b'],
                'existing_user_id' => $fixture['user_id'],
                'role' => RolesAndCapabilities::ROLE_DOCTOR,
            ],
            $operatorId
        );

        self::assertNotSame('', $result['error'], 'an operator without Clinic-B authorization must be denied');
        self::assertNull(App::membership_service()->membership_for($fixture['clinic_b'], $fixture['user_id']));
        self::assertSame(
            1,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d',
                [$fixture['user_id']]
            ),
            'denied attach must not create a clinician identity'
        );
    }

    public function testClinicAOnlyOperatorCannotMutateClinicB(): void
    {
        $fixture = $this->createExistingUserFixture();
        $operatorId = $this->makeUser('phase4_a_only_operator', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($operatorId, $fixture['clinic_a'], RolesAndCapabilities::ROLE_MANAGER);
        self::assertTrue(
            App::authorization_service()->can($operatorId, $fixture['clinic_a'], RolesAndCapabilities::CONFIG)
        );
        self::assertFalse(
            App::authorization_service()->can($operatorId, $fixture['clinic_b'], RolesAndCapabilities::CONFIG)
        );
        wp_set_current_user($operatorId);

        $result = StaffManagementPage::upsertUser(
            [
                'mode' => 'attach_existing',
                'clinic_id' => $fixture['clinic_b'],
                'existing_user_id' => $fixture['user_id'],
                'role' => RolesAndCapabilities::ROLE_DOCTOR,
            ],
            $operatorId
        );

        self::assertNotSame('', $result['error'], 'Clinic-A-only operator must not mutate Clinic B');
        self::assertNull(App::membership_service()->membership_for($fixture['clinic_b'], $fixture['user_id']));
    }

    public function testAuthorizedTargetUserRejectionsAreEnumerationNeutralAndUnauthorizedLookupStaysScoped(): void
    {
        $fixture = $this->createExistingUserFixture();
        $adminId = $this->makeUser('phase4_enumeration_admin_' . bin2hex(random_bytes(3)), 'administrator');
        $adminBefore = get_userdata($adminId);
        self::assertNotFalse($adminBefore);
        $adminRolesBefore = (array) $adminBefore->roles;
        $clinicBMembershipsBefore = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
            [$fixture['clinic_b']]
        );
        wp_set_current_user($fixture['manager_id']);

        $nonexistent = StaffManagementPage::upsertUser(
            [
                'mode' => 'attach_existing',
                'clinic_id' => $fixture['clinic_b'],
                'existing_user_id' => 999999999,
                'role' => RolesAndCapabilities::ROLE_DOCTOR,
            ],
            $fixture['manager_id']
        );
        $administrator = StaffManagementPage::upsertUser(
            [
                'mode' => 'attach_existing',
                'clinic_id' => $fixture['clinic_b'],
                'existing_user_id' => $adminId,
                'role' => RolesAndCapabilities::ROLE_DOCTOR,
            ],
            $fixture['manager_id']
        );

        self::assertNotSame('', $nonexistent['error']);
        self::assertSame(
            'حساب انتخاب‌شده برای افزودن به این کلینیک قابل استفاده نیست.',
            (string) $nonexistent['error'],
            'target-user rejection must be neutral and must not reveal account existence or role'
        );
        self::assertStringNotContainsString('administrator', (string) $nonexistent['error']);
        self::assertSame((string) $nonexistent['error'], (string) $administrator['error'], 'both target-user failures must be enumeration-neutral');
        self::assertSame(['error', 'generated', 'user_id'], array_keys($nonexistent));
        self::assertSame(['error', 'generated', 'user_id'], array_keys($administrator));
        self::assertSame('', (string) $nonexistent['generated']);
        self::assertSame('', (string) $administrator['generated']);
        self::assertSame(0, (int) $nonexistent['user_id']);
        self::assertSame(0, (int) $administrator['user_id']);
        self::assertSame(
            $clinicBMembershipsBefore,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
                [$fixture['clinic_b']]
            ),
            'neither target-user rejection may create a membership'
        );
        self::assertNull(App::membership_service()->membership_for($fixture['clinic_b'], $adminId));
        self::assertSame(
            $adminRolesBefore,
            (array) get_userdata($adminId)->roles,
            'administrator global role must remain unchanged'
        );

        $unauthorizedId = $this->makeUser('phase4_enumeration_unauthorized_' . bin2hex(random_bytes(3)), RolesAndCapabilities::ROLE_MANAGER);
        wp_set_current_user($unauthorizedId);
        $unauthorizedNonexistent = StaffManagementPage::upsertUser(
            [
                'mode' => 'attach_existing',
                'clinic_id' => $fixture['clinic_b'],
                'existing_user_id' => 999999999,
                'role' => RolesAndCapabilities::ROLE_DOCTOR,
            ],
            $unauthorizedId
        );
        $unauthorizedAdministrator = StaffManagementPage::upsertUser(
            [
                'mode' => 'attach_existing',
                'clinic_id' => $fixture['clinic_b'],
                'existing_user_id' => $adminId,
                'role' => RolesAndCapabilities::ROLE_DOCTOR,
            ],
            $unauthorizedId
        );
        self::assertSame(
            'دسترسی مدیریت پرسنل برای این Clinic مجاز نیست.',
            (string) $unauthorizedNonexistent['error'],
            'authorization must fail before the nonexistent target is looked up'
        );
        self::assertSame(
            (string) $unauthorizedNonexistent['error'],
            (string) $unauthorizedAdministrator['error'],
            'authorization must fail before either target-user lookup'
        );
        self::assertNull(App::membership_service()->membership_for($fixture['clinic_b'], $adminId));
    }

    public function testNonexistentWpUserIsRejectedWithoutPartialMembership(): void
    {
        $fixture = $this->createExistingUserFixture();
        wp_set_current_user($fixture['manager_id']);
        $beforeMemberships = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
            [$fixture['clinic_b']]
        );

        $result = StaffManagementPage::upsertUser(
            [
                'mode' => 'attach_existing',
                'clinic_id' => $fixture['clinic_b'],
                'existing_user_id' => 999999999,
                'role' => RolesAndCapabilities::ROLE_DOCTOR,
            ],
            $fixture['manager_id']
        );

        self::assertNotSame('', $result['error'], 'an unknown WP user must be rejected deterministically');
        self::assertSame(
            $beforeMemberships,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
                [$fixture['clinic_b']]
            ),
            'unknown user rejection must not partially create a Clinic membership'
        );
    }

    public function testExistingActiveMembershipIsAConflictAndNeverDuplicates(): void
    {
        $fixture = $this->createExistingUserFixture(true);
        $existing = App::membership_service()->membership_for($fixture['clinic_b'], $fixture['user_id']);
        self::assertNotNull($existing);
        $beforeClinicianCount = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d',
            [$fixture['user_id']]
        );
        wp_set_current_user($fixture['manager_id']);

        $result = StaffManagementPage::upsertUser(
            [
                'mode' => 'attach_existing',
                'clinic_id' => $fixture['clinic_b'],
                'existing_user_id' => $fixture['user_id'],
                'role' => RolesAndCapabilities::ROLE_DOCTOR,
            ],
            $fixture['manager_id']
        );

        self::assertNotSame('', $result['error'], 'existing Clinic-B membership must be a deterministic conflict');
        $after = App::membership_service()->membership_for($fixture['clinic_b'], $fixture['user_id']);
        self::assertNotNull($after);
        self::assertSame((int) $existing['id'], (int) $after['id'], 'duplicate attach must preserve the original membership row');
        self::assertSame('active', (string) $after['status']);
        self::assertSame(
            $beforeClinicianCount,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d',
                [$fixture['user_id']]
            )
        );
    }

    public function testSuspendedExistingMembershipIsNotDuplicatedOrSilentlyReactivated(): void
    {
        $fixture = $this->createExistingUserFixture(true, true);
        $existing = App::membership_service()->membership_for($fixture['clinic_b'], $fixture['user_id']);
        self::assertNotNull($existing);
        self::assertSame('suspended', (string) $existing['status']);
        wp_set_current_user($fixture['manager_id']);

        $result = StaffManagementPage::upsertUser(
            [
                'mode' => 'attach_existing',
                'clinic_id' => $fixture['clinic_b'],
                'existing_user_id' => $fixture['user_id'],
                'role' => RolesAndCapabilities::ROLE_DOCTOR,
            ],
            $fixture['manager_id']
        );

        self::assertNotSame('', $result['error'], 'suspended membership must require the existing explicit workflow');
        $after = App::membership_service()->membership_for($fixture['clinic_b'], $fixture['user_id']);
        self::assertNotNull($after);
        self::assertSame((int) $existing['id'], (int) $after['id']);
        self::assertSame('suspended', (string) $after['status'], 'attach must not silently reactivate a suspended row');
    }

    public function testDisallowedMembershipRoleIsRejectedBeforeMutation(): void
    {
        $fixture = $this->createExistingUserFixture();
        wp_set_current_user($fixture['manager_id']);
        $beforeMemberships = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
            [$fixture['clinic_b']]
        );

        $result = StaffManagementPage::upsertUser(
            [
                'mode' => 'attach_existing',
                'clinic_id' => $fixture['clinic_b'],
                'existing_user_id' => $fixture['user_id'],
                'role' => 'administrator',
            ],
            $fixture['manager_id']
        );

        self::assertNotSame('', $result['error'], 'administrator is not an allowed membership role');
        self::assertSame(
            $beforeMemberships,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
                [$fixture['clinic_b']]
            )
        );
        self::assertNull(App::membership_service()->membership_for($fixture['clinic_b'], $fixture['user_id']));
    }

    /**
     * @return array{clinic_a:int, clinic_b:int, manager_id:int, user_id:int, clinician_id:int}
     */
    private function createExistingUserFixture(bool $withClinicBMembership = false, bool $suspended = false): array
    {
        $clinicA = $this->createClinic('phase4-fixture-a');
        $clinicB = $this->createClinic('phase4-fixture-b');
        $managerId = $this->makeUser('phase4_fixture_manager_' . bin2hex(random_bytes(3)), RolesAndCapabilities::ROLE_MANAGER);
        $managerMembershipId = cpms_test_seed_membership($managerId, $clinicB, RolesAndCapabilities::ROLE_MANAGER);
        self::assertGreaterThan(0, $managerMembershipId);

        $userId = $this->makeUser('phase4_fixture_user_' . bin2hex(random_bytes(3)), RolesAndCapabilities::ROLE_DOCTOR);
        $clinicAMembershipId = cpms_test_seed_membership($userId, $clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        self::assertGreaterThan(0, $clinicAMembershipId);
        $clinicianId = App::clinicianRepository()->create(
            $clinicA,
            [
                'full_name' => 'Phase 4 Fixture Professional',
                'wp_user_id' => $userId,
                'is_active' => 1,
            ]
        );
        self::assertGreaterThan(0, $clinicianId);

        if ($withClinicBMembership) {
            $clinicBMembershipId = cpms_test_seed_membership($userId, $clinicB, RolesAndCapabilities::ROLE_DOCTOR);
            self::assertGreaterThan(0, $clinicBMembershipId);
            if ($suspended) {
                App::membership_service()->suspend_membership($clinicBMembershipId);
            }
        }

        return [
            'clinic_a' => $clinicA,
            'clinic_b' => $clinicB,
            'manager_id' => $managerId,
            'user_id' => $userId,
            'clinician_id' => $clinicianId,
        ];
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = wp_create_user($login, 'StrongPass123', $login . '@test.local');
        self::assertFalse(is_wp_error($userId), "user {$login} must be created");
        $userId = (int) $userId;
        self::assertGreaterThan(0, $userId);
        wp_update_user(['ID' => $userId, 'role' => $role]);

        return $userId;
    }

    private function createClinic(string $slugPrefix): int
    {
        global $wpdb;

        $organizationId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_organizations WHERE status = \'active\' ORDER BY id ASC LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertGreaterThan(0, $organizationId, 'an active Organization fixture must exist');

        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics
                    (organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $organizationId,
                'Phase 4 ' . $slug,
                $slug,
                'UTC',
                $now,
                $now
            )
        );
        self::assertSame(1, $inserted, 'dynamic Clinic fixture must be inserted');
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'dynamic Clinic fixture must have an id');

        return (int) $wpdb->insert_id;
    }
}
