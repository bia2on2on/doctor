<?php
/**
 * Dedicated Management Portal — business access without wp-admin.
 *
 * Contract under test (real migrations, real MySQL, no mocks):
 *  1. an ordinary WordPress Subscriber with a valid ACTIVE Organization
 *     membership manages its Organization through the frontend portal only;
 *  2. an ordinary WordPress Subscriber with a valid ACTIVE Clinic membership
 *     manages its Clinic (profile, Locations, staff membership role) through the
 *     frontend portal only;
 *  3. an explicit membership DENY blocks the workflow;
 *  4. forged and cross-Clinic/cross-Organization identifiers fail closed;
 *  5. a WordPress Administrator alone gains no management, clinical or financial
 *     authority;
 *  6. no portal action depends on /wp-admin or on a WordPress business role.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Management\ManagementPortalService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\ManagementPortalController;
use ClinicCore\Frontend\ManagementPortalShell;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

final class ManagementPortalTest extends WP_UnitTestCase {

    use RealTableMigrations;

    private int $orgA = 0;
    private int $orgB = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $orgManager = 0;
    private int $clinicManager = 0;
    private int $staffMember = 0;
    private int $outsider = 0;
    private int $admin = 0;
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void {
        parent::setUp();
        $this->withRealTables( static fn(): array => App::migrations()->migrate() );
        App::resetScope();

        $this->orgA = $this->create_org( 'Mgmt Org A' );
        $this->orgB = $this->create_org( 'Mgmt Org B' );

        $this->orgManager    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->clinicManager = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->staffMember   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->outsider      = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->admin         = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->userIds       = [ $this->orgManager, $this->clinicManager, $this->staffMember, $this->outsider, $this->admin ];

        $this->grant_org_manager( $this->orgA, $this->orgManager );

        // The Organization Manager provisions both Clinics through the very same
        // frontend dispatch path the portal uses.
        $createdA = ManagementPortalController::dispatch(
            $this->orgManager,
            'org_create_clinic',
            [
                'organization_id' => (string) $this->orgA,
                'name'            => 'Mgmt Clinic A',
                'slug'            => 'mgmt-clinic-a',
                'timezone'        => 'Asia/Tehran',
                'location_name'   => 'Main A',
            ]
        );
        self::assertTrue( $createdA['ok'], 'fixture: Organization Manager creates Clinic A through the portal' );
        $this->clinicA = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE slug = %s',
            [ 'mgmt-clinic-a' ]
        );

        $createdB = ManagementPortalController::dispatch(
            $this->orgManager,
            'org_create_clinic',
            [
                'organization_id' => (string) $this->orgA,
                'name'            => 'Mgmt Clinic B',
                'slug'            => 'mgmt-clinic-b',
                'timezone'        => 'Asia/Tehran',
                'location_name'   => 'Main B',
            ]
        );
        self::assertTrue( $createdB['ok'], 'fixture: Organization Manager creates Clinic B through the portal' );
        $this->clinicB = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE slug = %s',
            [ 'mgmt-clinic-b' ]
        );
        self::assertGreaterThan( 0, $this->clinicA );
        self::assertGreaterThan( 0, $this->clinicB );

        // Clinic A manager: an ordinary Subscriber with a durable ACTIVE
        // cpms_manager membership. No WordPress role, no global capability.
        App::membership_service()->create_membership( $this->clinicA, $this->clinicManager, RolesAndCapabilities::ROLE_MANAGER );
        App::membership_service()->create_membership( $this->clinicA, $this->staffMember, RolesAndCapabilities::ROLE_SECRETARY );

        // Isolation fixture: the outsider is a manager of a DIFFERENT Organization.
        $this->grant_org_manager( $this->orgB, $this->outsider );

        wp_set_current_user( 0 );
    }

    protected function tearDown(): void {
        $db = App::db();
        foreach ( [ $this->clinicA, $this->clinicB ] as $cid ) {
            if ( $cid <= 0 ) {
                continue;
            }
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_audit_logs' ) . ' WHERE clinic_id = %d', [ $cid ] );
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d', [ $cid ] );
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_locations' ) . ' WHERE clinic_id = %d', [ $cid ] );
        }
        foreach ( [ $this->orgA, $this->orgB ] as $oid ) {
            if ( $oid <= 0 ) {
                continue;
            }
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_organization_memberships' ) . ' WHERE organization_id = %d', [ $oid ] );
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE organization_id = %d', [ $oid ] );
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_organizations' ) . ' WHERE id = %d', [ $oid ] );
        }
        foreach ( $this->userIds as $uid ) {
            if ( $uid > 0 ) {
                wp_delete_user( $uid );
            }
        }
        App::resetScope();
        parent::tearDown();
    }

    // ==================================================================
    // 1. Organization Manager on a plain Subscriber
    // ==================================================================

    public function test_subscriber_with_organization_membership_manages_organization_through_portal(): void {
        $service = App::management_portal_service();

        $organizations = $service->organizations( $this->orgManager );
        self::assertCount( 1, $organizations, 'only the Organization with durable management authority is offered' );
        self::assertSame( $this->orgA, $organizations[0]['id'] );
        self::assertTrue( $organizations[0]['can_create'] );
        self::assertTrue( $organizations[0]['can_lifecycle'] );
        self::assertTrue( $organizations[0]['can_designate'] );

        $overview = $service->organization_overview( $this->orgManager, $this->orgA );
        self::assertNotNull( $overview );
        self::assertCount( 2, $overview['clinics'], 'the overview lists the Organization Clinics' );
        foreach ( $overview['clinics'] as $clinic_row ) {
            self::assertSame( $this->orgA, (int) App::db()->fetchValue(
                'SELECT organization_id FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
                [ (int) $clinic_row['id'] ]
            ), 'no Clinic of another Organization can appear' );
        }

        // Profile update through the portal (Organization capability).
        $updated = ManagementPortalController::dispatch(
            $this->orgManager,
            'org_update_clinic',
            [
                'organization_id' => (string) $this->orgA,
                'clinic_id'       => (string) $this->clinicB,
                'name'            => 'Mgmt Clinic B Renamed',
                'address'         => 'Org-managed address',
                'phone'           => '02100000000',
            ]
        );
        self::assertTrue( $updated['ok'] );
        self::assertSame( 'Mgmt Clinic B Renamed', App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $this->clinicB ]
        ) );

        // Lifecycle: disable and re-enable (history is never removed).
        $disabled = ManagementPortalController::dispatch(
            $this->orgManager,
            'org_clinic_status',
            [ 'organization_id' => (string) $this->orgA, 'clinic_id' => (string) $this->clinicB, 'active' => '0' ]
        );
        self::assertTrue( $disabled['ok'] );
        self::assertSame( 'suspended', App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $this->clinicB ]
        ) );
        $reenabled = ManagementPortalController::dispatch(
            $this->orgManager,
            'org_clinic_status',
            [ 'organization_id' => (string) $this->orgA, 'clinic_id' => (string) $this->clinicB, 'active' => '1' ]
        );
        self::assertTrue( $reenabled['ok'] );

        // Designate a Clinic manager (explicit, never implicit).
        $designated = ManagementPortalController::dispatch(
            $this->orgManager,
            'org_designate_clinic_manager',
            [ 'organization_id' => (string) $this->orgA, 'clinic_id' => (string) $this->clinicB, 'target_user_id' => (string) $this->outsider ]
        );
        self::assertTrue( $designated['ok'] );
        $membership = App::membership_service()->membership_for( $this->clinicB, $this->outsider );
        self::assertNotNull( $membership );
        self::assertSame( RolesAndCapabilities::ROLE_MANAGER, (string) $membership['role_key'] );

        // Organization manager grant is Organization-scoped only.
        $granted = ManagementPortalController::dispatch(
            $this->orgManager,
            'org_set_manager',
            [ 'organization_id' => (string) $this->orgA, 'target_user_id' => (string) $this->staffMember, 'active' => '1' ]
        );
        self::assertTrue( $granted['ok'] );

        // The actor stayed an ordinary Subscriber with no global CPMS capability.
        $user = get_userdata( $this->orgManager );
        self::assertContains( 'subscriber', (array) $user->roles );
        self::assertFalse( $user->has_cap( RolesAndCapabilities::CONFIG ) );
        self::assertFalse( $user->has_cap( RolesAndCapabilities::MEDICAL_READ ) );
    }

    // ==================================================================
    // 2. Clinic Manager on a plain Subscriber
    // ==================================================================

    public function test_subscriber_with_clinic_membership_manages_clinic_through_portal(): void {
        $service = App::management_portal_service();

        $clinics = $service->clinics( $this->clinicManager );
        self::assertCount( 1, $clinics, 'only the Clinic with durable ACTIVE membership + CONFIG is offered' );
        self::assertSame( $this->clinicA, $clinics[0]['id'] );

        $workspace = $service->clinic_workspace( $this->clinicManager, $this->clinicA );
        self::assertNotNull( $workspace );
        self::assertSame( 'Mgmt Clinic A', $workspace['clinic']['name'] );
        self::assertNotSame( [], $workspace['locations'], 'the primary Location is visible' );

        // Profile update.
        $profile = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_update_profile',
            [ 'clinic_id' => (string) $this->clinicA, 'name' => 'Clinic A (portal)', 'address' => 'Portal address', 'phone' => '02111111111' ]
        );
        self::assertTrue( $profile['ok'] );
        self::assertSame( 'Clinic A (portal)', App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $this->clinicA ]
        ) );

        // Location creation + deactivation.
        $location = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_location_create',
            [ 'clinic_id' => (string) $this->clinicA, 'name' => 'Branch 2', 'slug' => 'branch-2', 'timezone' => 'Asia/Tehran' ]
        );
        self::assertTrue( $location['ok'] );
        $location_id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d AND slug = %s',
            [ $this->clinicA, 'branch-2' ]
        );
        self::assertGreaterThan( 0, $location_id );

        $deactivated = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_location_active',
            [ 'clinic_id' => (string) $this->clinicA, 'location_id' => (string) $location_id, 'active' => '0' ]
        );
        self::assertTrue( $deactivated['ok'] );
        self::assertSame( 0, (int) App::db()->fetchValue(
            'SELECT is_active FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE id = %d',
            [ $location_id ]
        ) );

        // Staff membership role assignment (Clinic-local membership data only).
        $role_change = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_role',
            [ 'clinic_id' => (string) $this->clinicA, 'target_user_id' => (string) $this->staffMember, 'role_key' => RolesAndCapabilities::ROLE_ACCOUNTANT ]
        );
        self::assertTrue( $role_change['ok'] );
        $membership = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $membership );
        self::assertSame( RolesAndCapabilities::ROLE_ACCOUNTANT, (string) $membership['role_key'] );

        // A WordPress role can never be assigned through the portal.
        $rejected = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_role',
            [ 'clinic_id' => (string) $this->clinicA, 'target_user_id' => (string) $this->staffMember, 'role_key' => 'administrator' ]
        );
        self::assertFalse( $rejected['ok'] );
        self::assertSame( RolesAndCapabilities::ROLE_ACCOUNTANT, (string) App::membership_service()->membership_for( $this->clinicA, $this->staffMember )['role_key'] );

        // Actor stayed a plain Subscriber.
        self::assertContains( 'subscriber', (array) get_userdata( $this->clinicManager )->roles );
    }

    // ==================================================================
    // 3. Explicit DENY
    // ==================================================================

    public function test_explicit_membership_deny_blocks_the_clinic_workflow(): void {
        $membership = App::membership_service()->active_membership_for( $this->clinicA, $this->clinicManager );
        self::assertNotNull( $membership );
        ( new MembershipRepository( App::db() ) )->set_capability( (int) $membership['id'], RolesAndCapabilities::CONFIG, 'deny' );

        self::assertNull(
            App::management_portal_service()->clinic_workspace( $this->clinicManager, $this->clinicA ),
            'DENY wins over the manager preset'
        );

        $result = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_update_profile',
            [ 'clinic_id' => (string) $this->clinicA, 'name' => 'Should not apply' ]
        );
        self::assertFalse( $result['ok'] );
        self::assertSame( 'Mgmt Clinic A', App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $this->clinicA ]
        ) );

        // Suspended Organization membership is equally fail-closed.
        App::db()->query(
            'UPDATE ' . App::db()->table( 'cpms_organization_memberships' ) . " SET status = 'suspended' WHERE organization_id = %d AND wp_user_id = %d",
            [ $this->orgA, $this->orgManager ]
        );
        self::assertSame( [], App::management_portal_service()->organizations( $this->orgManager ) );
        self::assertNull( App::management_portal_service()->organization_overview( $this->orgManager, $this->orgA ) );
    }

    // ==================================================================
    // 4. Forged / cross-tenant identifiers
    // ==================================================================

    public function test_forged_and_cross_tenant_ids_fail_closed(): void {
        $service = App::management_portal_service();

        // Clinic manager of A cannot act on B, even with a perfectly formed id.
        $foreign = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_update_profile',
            [ 'clinic_id' => (string) $this->clinicB, 'name' => 'Hijacked' ]
        );
        self::assertFalse( $foreign['ok'] );
        self::assertNotSame( 'Hijacked', App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $this->clinicB ]
        ) );

        // Location of B supplied under Clinic A.
        $locationB = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d LIMIT 1',
            [ $this->clinicB ]
        );
        self::assertGreaterThan( 0, $locationB );
        $cross_location = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_location_active',
            [ 'clinic_id' => (string) $this->clinicA, 'location_id' => (string) $locationB, 'active' => '0' ]
        );
        self::assertFalse( $cross_location['ok'] );

        // Staff target who is not an ACTIVE member of the trusted Clinic.
        $cross_staff = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_role',
            [ 'clinic_id' => (string) $this->clinicA, 'target_user_id' => (string) $this->admin, 'role_key' => RolesAndCapabilities::ROLE_DOCTOR ]
        );
        self::assertFalse( $cross_staff['ok'] );

        // Organization authorized for A cannot touch B.
        $cross_org = ManagementPortalController::dispatch(
            $this->outsider,
            'org_update_clinic',
            [ 'organization_id' => (string) $this->orgA, 'clinic_id' => (string) $this->clinicA, 'name' => 'Hijacked by outsider' ]
        );
        self::assertFalse( $cross_org['ok'] );

        // Unknown action is never executed.
        $unknown = ManagementPortalController::dispatch(
            $this->orgManager,
            'org_delete_everything',
            [ 'organization_id' => (string) $this->orgA ]
        );
        self::assertFalse( $unknown['ok'] );

        self::assertNull( $service->organization_overview( $this->outsider, $this->orgA ) );
        self::assertNull( $service->clinic_workspace( $this->outsider, $this->clinicA ) );
    }

    // ==================================================================
    // 5. WordPress Administrator alone
    // ==================================================================

    public function test_wordpress_administrator_alone_has_no_management_clinical_or_financial_authority(): void {
        $service = App::management_portal_service();

        self::assertSame( [], $service->organizations( $this->admin ) );
        self::assertSame( [], $service->clinics( $this->admin ) );
        self::assertFalse( $service->has_any_surface( $this->admin ) );
        self::assertNull( $service->organization_overview( $this->admin, $this->orgA ) );
        self::assertNull( $service->clinic_workspace( $this->admin, $this->clinicA ) );

        self::assertFalse( ManagementPortalController::dispatch(
            $this->admin,
            'org_create_clinic',
            [ 'organization_id' => (string) $this->orgA, 'name' => 'Admin Clinic', 'slug' => 'admin-clinic', 'timezone' => 'Asia/Tehran' ]
        )['ok'] );
        self::assertSame( 0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE slug = %s',
            [ 'admin-clinic' ]
        ) );

        $auth = App::authorization_service();
        foreach ( [ RolesAndCapabilities::MEDICAL_READ, RolesAndCapabilities::FINANCE_READ, RolesAndCapabilities::PATIENT_READ, RolesAndCapabilities::CONFIG ] as $cap ) {
            self::assertFalse( $auth->can( $this->admin, $this->clinicA, $cap ), 'administrator gains no Clinic authority from its WordPress role: ' . $cap );
        }
    }

    // ==================================================================
    // 6. No wp-admin dependency, no WordPress-role read
    // ==================================================================

    public function test_portal_surface_never_requires_wp_admin(): void {
        $page_id = ManagementPortalShell::ensure_portal_page();
        self::assertGreaterThan( 0, $page_id, 'the portal owns a published Page' );
        self::assertSame( 'publish', get_post_status( $page_id ) );

        $url = ManagementPortalShell::portal_url();
        self::assertStringNotContainsString( '/wp-admin', $url );

        $template = file_get_contents( ManagementPortalShell::template_path() );
        self::assertIsString( $template );
        self::assertStringNotContainsString( '/wp-admin', $template );
        self::assertStringNotContainsString( 'admin-post.php', $template );

        $controller_source = file_get_contents( ( new \ReflectionClass( ManagementPortalController::class ) )->getFileName() );
        self::assertIsString( $controller_source );
        self::assertStringNotContainsString( 'admin_post', $controller_source );
        self::assertStringNotContainsString( 'current_user_can', $controller_source );

        $service_source = file_get_contents( ( new \ReflectionClass( ManagementPortalService::class ) )->getFileName() );
        self::assertIsString( $service_source );
        self::assertStringNotContainsString( 'current_user_can', $service_source );
        self::assertStringNotContainsString( 'user_can(', $service_source );
        self::assertStringNotContainsString( 'has_cap', $service_source );
    }

    // ============ Helpers ============

    private function create_org( string $name ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert( 'cpms_organizations', [
            'name'       => $name . ' ' . bin2hex( random_bytes( 3 ) ),
            'slug'       => 'mgmt-org-' . bin2hex( random_bytes( 4 ) ),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ] );

        return (int) $db->wpdb_last_insert_id();
    }

    private function grant_org_manager( int $org_id, int $user_id ): void {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert( 'cpms_organization_memberships', [
            'organization_id'       => $org_id,
            'wp_user_id'            => $user_id,
            'role_key'              => 'organization_manager',
            'status'                => 'active',
            'created_by_wp_user_id' => $user_id,
            'created_at'            => $now,
            'updated_at'            => $now,
        ] );
    }
}
