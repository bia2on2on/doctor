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
 * RED contract: the input uses the existing StaffManagementPage::upsertUser()
 * boundary. It deliberately does not call a future helper or service. The
 * current form/handler has only create/update semantics, so the requested
 * existing-user attach must be rejected or attempt a new WP user. The green
 * implementation will add an explicit existing-user action at this same
 * product boundary.
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

        $usersBefore = get_users(['fields' => 'ids', 'number' => -1]);
        wp_set_current_user($managerId);

        // Existing supported product boundary: current StaffManagementPage write
        // path. No new method is called; this is the requested action expressed
        // at the nearest real handler contract available on main.
        $result = StaffManagementPage::upsertUser(
            [
                'mode' => 'create',
                'clinic_id' => $clinicB,
                'existing_user_id' => $userId,
                'username' => (string) $existingUser->user_login,
                'display_name' => (string) $existingUser->display_name,
                'email' => (string) $existingUser->user_email,
                'role' => RolesAndCapabilities::ROLE_DOCTOR,
                'password' => '',
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
        self::assertSame($userId, (int) get_userdata($userId)->ID, 'the original WP user must remain the same account');
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
