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
        // Delegation bound: the manager must already hold the accountant preset.
        $this->grant_caps( $this->clinicA, $this->clinicManager, array_keys( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_ACCOUNTANT ) ) );
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

    // ==================================================================
    // 7. Clinic Manager: staff enrollment, membership lifecycle, Location
    //    scope, capability rules, clinician + schedule registration.
    //    All negative cases must fail closed without touching storage.
    // ==================================================================

    public function test_subscriber_clinic_manager_enrolls_staff_and_provisions_only_a_subscriber(): void {
        $email = 'cpms-staff-' . bin2hex( random_bytes( 4 ) ) . '@example.test';

        // Delegation bound: a plain manager does NOT hold the secretary preset,
        // so it may not hand that role out (review P0-2).
        self::assertFalse( ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_enroll',
            array( 'clinic_id' => (string) $this->clinicA, 'identifier' => $email, 'role_key' => RolesAndCapabilities::ROLE_SECRETARY )
        )['ok'], 'a manager cannot delegate a role whose capabilities it does not hold' );
        self::assertFalse( get_user_by( 'email', $email ), 'a refused enrollment must not provision an account' );

        // Once the manager actually holds the secretary capability set (explicit
        // membership grants), delegation is allowed and stays Clinic-scoped.
        $this->grant_caps( $this->clinicA, $this->clinicManager, array_keys( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_SECRETARY ) ) );

        $result = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_enroll',
            array( 'clinic_id' => (string) $this->clinicA, 'identifier' => $email, 'role_key' => RolesAndCapabilities::ROLE_SECRETARY )
        );
        self::assertTrue( $result['ok'], 'an ACTIVE Clinic manager may enroll staff through the portal' );

        $created = get_user_by( 'email', $email );
        self::assertInstanceOf( \WP_User::class, $created );
        $this->userIds[] = (int) $created->ID;

        $roles = (array) $created->roles;
        self::assertSame( array( 'subscriber' ), array_values( $roles ), 'only a plain Subscriber account may be provisioned' );
        self::assertFalse( $created->has_cap( RolesAndCapabilities::CONFIG ), 'no global CPMS capability is granted by enrollment' );
        self::assertFalse( $created->has_cap( RolesAndCapabilities::MEDICAL_READ ) );

        $membership = App::membership_service()->membership_for( $this->clinicA, (int) $created->ID );
        self::assertNotNull( $membership );
        self::assertSame( 'active', (string) $membership['status'] );
        self::assertSame( RolesAndCapabilities::ROLE_SECRETARY, (string) $membership['role_key'] );

        // The engine (not a WP role) now authorizes the enrolled secretary, and
        // only inside this Clinic.
        self::assertTrue( App::authorization_service()->can( (int) $created->ID, $this->clinicA, RolesAndCapabilities::APPT_CREATE ) );
        self::assertFalse( App::authorization_service()->can( (int) $created->ID, $this->clinicA, RolesAndCapabilities::CONFIG ) );
        self::assertFalse( App::authorization_service()->can( (int) $created->ID, $this->clinicB, RolesAndCapabilities::APPT_CREATE ) );
    }

    public function test_staff_enrollment_is_clinic_scoped_and_never_overwrites(): void {
        // An existing account is linked, not re-roled.
        $existing = get_userdata( $this->outsider );
        self::assertInstanceOf( \WP_User::class, $existing );

        $this->grant_caps( $this->clinicA, $this->clinicManager, array_keys( RolesAndCapabilities::capsMap( RolesAndCapabilities::ROLE_ACCOUNTANT ) ) );

        $linked = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_enroll',
            array( 'clinic_id' => (string) $this->clinicA, 'identifier' => (string) $existing->user_login, 'role_key' => RolesAndCapabilities::ROLE_ACCOUNTANT )
        );
        self::assertTrue( $linked['ok'] );
        $membership = App::membership_service()->membership_for( $this->clinicA, $this->outsider );
        self::assertNotNull( $membership );
        self::assertSame( RolesAndCapabilities::ROLE_ACCOUNTANT, (string) $membership['role_key'] );
        $user = get_userdata( $this->outsider );
        self::assertInstanceOf( \WP_User::class, $user );
        self::assertSame( array( 'subscriber' ), array_values( (array) $user->roles ), 'linking never changes the WordPress role' );

        $duplicate = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_enroll',
            array( 'clinic_id' => (string) $this->clinicA, 'identifier' => (string) $existing->user_login, 'role_key' => RolesAndCapabilities::ROLE_MANAGER )
        );
        self::assertFalse( $duplicate['ok'], 'an existing membership is never silently overwritten' );
        $unchanged = App::membership_service()->membership_for( $this->clinicA, $this->outsider );
        self::assertNotNull( $unchanged );
        self::assertSame( RolesAndCapabilities::ROLE_ACCOUNTANT, (string) $unchanged['role_key'] );

        // A manager of Clinic A cannot enroll into a Clinic it does not manage,
        // even though the Clinic exists and the actor is a legitimate manager.
        $forged = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_enroll',
            array( 'clinic_id' => (string) $this->clinicB, 'identifier' => (string) $existing->user_login, 'role_key' => RolesAndCapabilities::ROLE_SECRETARY )
        );
        self::assertFalse( $forged['ok'] );
        self::assertNull( App::membership_service()->membership_for( $this->clinicB, $this->outsider ), 'no cross-Clinic membership may be created' );

        // A WordPress role is never accepted as a membership role.
        $wp_role = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_enroll',
            array( 'clinic_id' => (string) $this->clinicA, 'identifier' => 'someone' . bin2hex( random_bytes( 3 ) ) . '@example.test', 'role_key' => 'administrator' )
        );
        self::assertFalse( $wp_role['ok'], 'WordPress role keys are not assignable through the portal' );
    }

    public function test_membership_suspension_is_a_durable_uncached_deny(): void {
        $membership = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $membership );
        $membership_id = (int) $membership['id'];
        self::assertTrue( App::authorization_service()->can( $this->staffMember, $this->clinicA, RolesAndCapabilities::APPT_CREATE ) );

        $suspended = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_status',
            array( 'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) $membership_id, 'active' => '0' )
        );
        self::assertTrue( $suspended['ok'] );
        self::assertFalse(
            App::authorization_service()->can( $this->staffMember, $this->clinicA, RolesAndCapabilities::APPT_CREATE ),
            'a suspended membership is a durable deny'
        );

        $workspace = App::management_portal_service()->clinic_workspace( $this->clinicManager, $this->clinicA );
        self::assertNotNull( $workspace );
        $statuses = array();
        foreach ( $workspace['staff'] as $row ) {
            $statuses[ (int) $row['wp_user_id'] ] = (string) $row['status'];
        }
        self::assertSame( 'suspended', $statuses[ $this->staffMember ] ?? '' );

        $reactivated = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_status',
            array( 'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) $membership_id, 'active' => '1' )
        );
        self::assertTrue( $reactivated['ok'] );
        self::assertTrue(
            App::authorization_service()->can( $this->staffMember, $this->clinicA, RolesAndCapabilities::APPT_CREATE ),
            'reactivation restores authority without any WordPress role change'
        );
    }

    public function test_cross_clinic_membership_ids_fail_closed(): void {
        // The outsider manages Clinic B; Clinic A's manager must not be able to
        // reach that membership through Clinic A.
        App::membership_service()->create_membership( $this->clinicB, $this->outsider, RolesAndCapabilities::ROLE_SECRETARY );
        $foreign = App::membership_service()->membership_for( $this->clinicB, $this->outsider );
        self::assertNotNull( $foreign );
        $foreign_id = (int) $foreign['id'];

        foreach ( array(
            array( 'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) $foreign_id, 'active' => '0' ),
        ) as $payload ) {
            $result = ManagementPortalController::dispatch( $this->clinicManager, 'clinic_staff_status', $payload );
            self::assertFalse( $result['ok'], 'a membership of another Clinic is never addressable' );
        }

        $still_active = App::membership_service()->membership_for( $this->clinicB, $this->outsider );
        self::assertNotNull( $still_active );
        self::assertSame( 'active', (string) $still_active['status'], 'no cross-Clinic suspension may occur' );

        $capability = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_capability',
            array(
                'clinic_id'     => (string) $this->clinicA,
                'membership_id' => (string) $foreign_id,
                'capability'    => RolesAndCapabilities::PATIENT_READ,
                'effect'        => 'deny',
            )
        );
        self::assertFalse( $capability['ok'] );
        self::assertSame( array(), ( new MembershipRepository( App::db() ) )->capabilities_for( $foreign_id ) );
    }

    public function test_capability_rules_are_bounded_by_the_actors_own_authority(): void {
        $membership = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $membership );
        $membership_id = (int) $membership['id'];

        // DENY of a capability the actor holds is allowed and takes effect.
        $denied = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_capability',
            array(
                'clinic_id'     => (string) $this->clinicA,
                'membership_id' => (string) $membership_id,
                'capability'    => RolesAndCapabilities::PATIENT_READ,
                'effect'        => 'deny',
            )
        );
        self::assertTrue( $denied['ok'] );
        self::assertFalse( App::authorization_service()->can( $this->staffMember, $this->clinicA, RolesAndCapabilities::PATIENT_READ ) );

        // Clearing the rule restores the role preset.
        $cleared = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_capability',
            array(
                'clinic_id'     => (string) $this->clinicA,
                'membership_id' => (string) $membership_id,
                'capability'    => RolesAndCapabilities::PATIENT_READ,
                'effect'        => 'clear',
            )
        );
        self::assertTrue( $cleared['ok'] );
        self::assertTrue( App::authorization_service()->can( $this->staffMember, $this->clinicA, RolesAndCapabilities::PATIENT_READ ) );

        // A manager may not hand out clinical authority it does not hold.
        $escalation = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_capability',
            array(
                'clinic_id'     => (string) $this->clinicA,
                'membership_id' => (string) $membership_id,
                'capability'    => RolesAndCapabilities::PRIVATE_NOTE_READ,
                'effect'        => 'grant',
            )
        );
        self::assertFalse( $escalation['ok'], 'delegation beyond the actor authority is refused' );
        self::assertFalse( App::authorization_service()->can( $this->staffMember, $this->clinicA, RolesAndCapabilities::PRIVATE_NOTE_READ ) );

        // An unknown capability name is refused outright.
        self::assertFalse(
            ManagementPortalController::dispatch(
                $this->clinicManager,
                'clinic_staff_capability',
                array(
                    'clinic_id'     => (string) $this->clinicA,
                    'membership_id' => (string) $membership_id,
                    'capability'    => 'cpms_not_a_capability',
                    'effect'        => 'grant',
                )
            )['ok']
        );
    }

    public function test_location_scope_assignments_require_active_locations_of_the_same_clinic(): void {
        $membership = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $membership );
        $membership_id = (int) $membership['id'];

        $own = App::db()->fetchAll(
            'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d AND is_active = 1',
            array( $this->clinicA )
        );
        self::assertNotEmpty( $own, 'fixture: Clinic A has an active Location' );
        $own_location = (int) $own[0]['id'];

        $foreign = App::db()->fetchAll(
            'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d',
            array( $this->clinicB )
        );
        self::assertNotEmpty( $foreign, 'fixture: Clinic B has a Location' );

        $rejected = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_locations',
            array(
                'clinic_id'     => (string) $this->clinicA,
                'membership_id' => (string) $membership_id,
                'location_ids'  => (string) (int) $foreign[0]['id'],
            )
        );
        self::assertFalse( $rejected['ok'], 'a Location of another Clinic can never be attached' );
        self::assertSame( array(), ( new MembershipRepository( App::db() ) )->location_ids_for( $membership_id ) );

        $accepted = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_locations',
            array(
                'clinic_id'     => (string) $this->clinicA,
                'membership_id' => (string) $membership_id,
                'location_ids'  => (string) $own_location,
            )
        );
        self::assertTrue( $accepted['ok'] );
        self::assertSame( array( $own_location ), ( new MembershipRepository( App::db() ) )->location_ids_for( $membership_id ) );
        $scoped = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $scoped );
        self::assertSame( 'location', (string) $scoped['scope_mode'] );

        // Empty selection means Clinic-wide again (explicit, never implicit).
        $reset = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_locations',
            array( 'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) $membership_id, 'location_ids' => '' )
        );
        self::assertTrue( $reset['ok'] );
        $wide = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $wide );
        self::assertSame( 'clinic', (string) $wide['scope_mode'] );
    }

    public function test_clinician_registration_and_schedule_are_clinic_scoped(): void {
        $registered = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_clinician_register',
            array( 'clinic_id' => (string) $this->clinicA, 'full_name' => 'Dr Portal', 'specialty' => 'General' )
        );
        self::assertTrue( $registered['ok'] );

        $clinician_id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE clinic_id = %d ORDER BY id DESC LIMIT 1',
            array( $this->clinicA )
        );
        self::assertGreaterThan( 0, $clinician_id );
        self::assertSame( $this->clinicA, (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE id = %d',
            array( $clinician_id )
        ) );

        // Linking a WordPress account that has no ACTIVE membership here fails.
        self::assertFalse(
            ManagementPortalController::dispatch(
                $this->clinicManager,
                'clinic_clinician_register',
                array( 'clinic_id' => (string) $this->clinicA, 'full_name' => 'Dr Outsider', 'target_user_id' => (string) $this->admin )
            )['ok'],
            'an account without an ACTIVE membership cannot be linked to a clinician'
        );

        // A clinician of another Clinic can never be scheduled through this Clinic.
        $foreign_clinician = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE clinic_id = %d LIMIT 1',
            array( $this->clinicB )
        );
        $own_location = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d AND is_active = 1 LIMIT 1',
            array( $this->clinicA )
        );
        self::assertGreaterThan( 0, $own_location );

        if ( $foreign_clinician <= 0 ) {
            $foreign_clinician = $clinician_id + 1000; // unknown id is equally fail-closed
        }
        self::assertFalse(
            ManagementPortalController::dispatch(
                $this->clinicManager,
                'clinic_schedule_create',
                array(
                    'clinic_id'    => (string) $this->clinicA,
                    'clinician_id' => (string) $foreign_clinician,
                    'location_id'  => (string) $own_location,
                    'day_of_week'  => '1',
                    'start_time'   => '09:00',
                    'end_time'     => '12:00',
                )
            )['ok'],
            'a clinician outside the trusted Clinic is never schedulable'
        );

        $scheduled = ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_schedule_create',
            array(
                'clinic_id'    => (string) $this->clinicA,
                'clinician_id' => (string) $clinician_id,
                'location_id'  => (string) $own_location,
                'day_of_week'  => '1',
                'start_time'   => '09:00',
                'end_time'     => '12:00',
            )
        );
        self::assertTrue( $scheduled['ok'], 'the publishing ScheduleService contract accepts a valid portal schedule' );
        self::assertSame( $this->clinicA, (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table( 'cpms_schedule' ) . ' WHERE clinician_id = %d ORDER BY id DESC LIMIT 1',
            array( $clinician_id )
        ) );
    }

    public function test_administrator_alone_cannot_reach_any_clinic_management_workflow(): void {
        $membership = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $membership );

        foreach ( array(
            array( 'action' => 'clinic_staff_enroll', 'input' => array( 'clinic_id' => (string) $this->clinicA, 'identifier' => 'admin-probe-' . bin2hex( random_bytes( 3 ) ) . '@example.test', 'role_key' => RolesAndCapabilities::ROLE_SECRETARY ) ),
            array( 'action' => 'clinic_staff_status', 'input' => array( 'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) (int) $membership['id'], 'active' => '0' ) ),
            array( 'action' => 'clinic_staff_capability', 'input' => array( 'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) (int) $membership['id'], 'capability' => RolesAndCapabilities::PATIENT_READ, 'effect' => 'grant' ) ),
            array( 'action' => 'clinic_staff_locations', 'input' => array( 'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) (int) $membership['id'], 'location_ids' => '1' ) ),
            array( 'action' => 'clinic_clinician_register', 'input' => array( 'clinic_id' => (string) $this->clinicA, 'full_name' => 'Dr Admin' ) ),
        ) as $case ) {
            $result = ManagementPortalController::dispatch( $this->admin, $case['action'], $case['input'] );
            self::assertFalse( $result['ok'], 'Administrator alone must fail closed for ' . $case['action'] );
        }

        $still_active = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $still_active );
        self::assertSame( 'active', (string) $still_active['status'] );
        self::assertSame( array(), ( new MembershipRepository( App::db() ) )->capabilities_for( (int) $membership['id'] ) );
        self::assertFalse( App::authorization_service()->can( $this->admin, $this->clinicA, RolesAndCapabilities::CONFIG ) );
        self::assertFalse( App::authorization_service()->can( $this->admin, $this->clinicA, RolesAndCapabilities::PATIENT_READ ) );
    }

    // ==================================================================
    // 8. Facility-aware onboarding from the frontend portal (Organization
    //    Manager on a plain Subscriber). The admin wizard keeps working: this
    //    exercises the same published service through the portal boundary.
    // ==================================================================

    public function test_subscriber_org_manager_runs_hospital_setup_from_the_portal(): void {
        $plan_started = ManagementPortalController::dispatch(
            $this->orgManager,
            'org_facility_start',
            array(
                'organization_id' => (string) $this->orgA,
                'facility_type'   => 'hospital',
                'structure'       => 'hospital_network',
                'display_name'    => 'Portal Hospital',
            )
        );
        self::assertTrue( $plan_started['ok'], 'an Organization Manager starts the hospital plan in the frontend' );

        $plan = App::management_portal_service()->facility_plan( $this->orgManager, $this->orgA );
        self::assertIsArray( $plan );
        self::assertSame( 'hospital_network', (string) $plan['flow'] );
        self::assertSame( 0, (int) $plan['outpatient_ack_by'] );

        // Hospital safety step: no Clinic may be created before the explicit
        // outpatient-only acknowledgment.
        $blocked_clinic = ManagementPortalController::dispatch(
            $this->orgManager,
            'org_facility_clinic',
            array( 'organization_id' => (string) $this->orgA, 'name' => 'Blocked Clinic', 'site_key' => '', 'timezone' => 'Asia/Tehran' )
        );
        self::assertFalse( $blocked_clinic['ok'], 'the hospital outpatient acknowledgment is enforced, not decorative' );

        self::assertTrue( ManagementPortalController::dispatch(
            $this->orgManager,
            'org_facility_ack',
            array( 'organization_id' => (string) $this->orgA )
        )['ok'] );

        self::assertTrue( ManagementPortalController::dispatch(
            $this->orgManager,
            'org_facility_site',
            array( 'organization_id' => (string) $this->orgA, 'label' => 'Portal Building', 'address' => 'Somewhere 1' )
        )['ok'] );

        $plan = App::management_portal_service()->facility_plan( $this->orgManager, $this->orgA );
        self::assertIsArray( $plan );
        self::assertCount( 1, $plan['sites'] );
        $site_key = (string) $plan['sites'][0]['key'];

        self::assertTrue( ManagementPortalController::dispatch(
            $this->orgManager,
            'org_facility_clinic',
            array( 'organization_id' => (string) $this->orgA, 'name' => 'Portal Hospital Clinic', 'site_key' => $site_key, 'timezone' => 'Asia/Tehran' )
        )['ok'] );

        $plan = App::management_portal_service()->facility_plan( $this->orgManager, $this->orgA );
        self::assertIsArray( $plan );
        self::assertCount( 1, $plan['clinics'] );
        $clinic_key = (string) $plan['clinics'][0]['key'];
        $clinic_id  = (int) $plan['clinics'][0]['clinic_id'];
        self::assertGreaterThan( 0, $clinic_id );

        // The held Clinic belongs to this Organization and is NOT live yet.
        self::assertSame( $this->orgA, (int) App::db()->fetchValue(
            'SELECT organization_id FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            array( $clinic_id )
        ) );
        self::assertNotSame( 'active', (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            array( $clinic_id )
        ), 'managed Clinics stay suspended until the atomic activation' );

        // Explicit Clinic-manager designation through the portal.
        self::assertTrue( ManagementPortalController::dispatch(
            $this->orgManager,
            'org_facility_designate',
            array( 'organization_id' => (string) $this->orgA, 'clinic_key' => $clinic_key, 'target_user_id' => (string) $this->clinicManager )
        )['ok'] );
        $designated = App::membership_service()->membership_for( $clinic_id, $this->clinicManager );
        self::assertNotNull( $designated );
        self::assertSame( 'active', (string) $designated['status'] );
        self::assertSame( RolesAndCapabilities::ROLE_MANAGER, (string) $designated['role_key'] );
        // Organization authority never becomes Clinic authority implicitly.
        self::assertFalse( App::authorization_service()->can( $this->orgManager, $clinic_id, RolesAndCapabilities::CONFIG ) );

        $review = App::management_portal_service()->review_facility_setup( $this->orgManager, $this->orgA );
        self::assertIsArray( $review );
        self::assertFalse( $review['ready'], 'a hospital without clinicians, schedules and a booking policy is not ready' );
        self::assertNotEmpty( $review['blocking'] );

        $refused = ManagementPortalController::dispatch(
            $this->orgManager,
            'org_facility_activate',
            array( 'organization_id' => (string) $this->orgA )
        );
        self::assertFalse( $refused['ok'], 'activation fails closed while the review is blocking' );
        self::assertNotSame( 'active', (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            array( $clinic_id )
        ), 'a refused activation must not activate any Clinic' );

        // Cross-Organization isolation: the manager of Organization B cannot
        // touch Organization A's plan through any portal action.
        foreach ( array(
            array( 'action' => 'org_facility_ack', 'input' => array( 'organization_id' => (string) $this->orgA ) ),
            array( 'action' => 'org_facility_site', 'input' => array( 'organization_id' => (string) $this->orgA, 'label' => 'Hijack', 'address' => '' ) ),
            array( 'action' => 'org_facility_activate', 'input' => array( 'organization_id' => (string) $this->orgA ) ),
        ) as $case ) {
            self::assertFalse(
                ManagementPortalController::dispatch( $this->outsider, $case['action'], $case['input'] )['ok'],
                'another Organization manager must fail closed for ' . $case['action']
            );
        }
        $untouched = App::management_portal_service()->facility_plan( $this->orgManager, $this->orgA );
        self::assertIsArray( $untouched );
        self::assertCount( 1, $untouched['sites'], 'no foreign manager may add to the plan' );

        // A Clinic Manager (no Organization authority) cannot run the plan.
        self::assertFalse( ManagementPortalController::dispatch(
            $this->clinicManager,
            'org_facility_site',
            array( 'organization_id' => (string) $this->orgA, 'label' => 'Clinic Manager Site', 'address' => '' )
        )['ok'], 'Clinic authority is not Organization authority' );

        // Cleanup: the plan Clinic is removed with its Organization in tearDown.
        $this->userIds = array_values( array_unique( $this->userIds ) );
    }

    public function test_hospital_facility_activation_is_atomic_from_the_portal(): void {
        $service = App::management_portal_service();

        self::assertTrue( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_start', array(
            'organization_id' => (string) $this->orgA,
            'facility_type'   => 'hospital',
            'structure'       => 'hospital_network',
            'display_name'    => 'Atomic Hospital',
        ) )['ok'] );
        self::assertTrue( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_ack', array( 'organization_id' => (string) $this->orgA ) )['ok'] );
        self::assertTrue( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_site', array(
            'organization_id' => (string) $this->orgA,
            'label'           => 'Atomic Building',
            'address'         => 'Street 2',
        ) )['ok'] );

        $plan = $service->facility_plan( $this->orgManager, $this->orgA );
        self::assertIsArray( $plan );
        $site_key = (string) $plan['sites'][0]['key'];

        self::assertTrue( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_clinic', array(
            'organization_id' => (string) $this->orgA,
            'name'            => 'Atomic Clinic',
            'site_key'        => $site_key,
            'timezone'        => 'Asia/Tehran',
        ) )['ok'] );

        $plan = $service->facility_plan( $this->orgManager, $this->orgA );
        self::assertIsArray( $plan );
        $clinic_key = (string) $plan['clinics'][0]['key'];
        $clinic_id  = (int) $plan['clinics'][0]['clinic_id'];
        self::assertGreaterThan( 0, $clinic_id );

        // Explicit self-designation is what gives this actor Clinic CONFIG; the
        // Organization role alone never does (see the previous test).
        self::assertTrue( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_designate', array(
            'organization_id' => (string) $this->orgA,
            'clinic_key'      => $clinic_key,
            'target_user_id'  => (string) $this->orgManager,
        ) )['ok'] );
        self::assertTrue( App::authorization_service()->can( $this->orgManager, $clinic_id, RolesAndCapabilities::CONFIG ) );

        self::assertTrue( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_room', array(
            'organization_id' => (string) $this->orgA,
            'clinic_key'      => $clinic_key,
            'name'            => 'Room A1',
            'site_key'        => $site_key,
        ) )['ok'] );

        // P1-7: the whole readiness path is driven through PORTAL CONTROLLER
        // ACTIONS only — no direct call into FacilitySetupService.
        $assigned = ManagementPortalController::dispatch( $this->orgManager, 'org_facility_clinician', array(
            'organization_id' => (string) $this->orgA,
            'clinic_key'      => $clinic_key,
            'target_user_id'  => (string) $this->staffMember,
            'full_name'       => 'Dr Facility',
            'room_key'        => array( 'primary' ),
        ) );
        self::assertTrue( $assigned['ok'], 'clinician assignment through the portal' );

        $selectors = $service->facility_selectors( $this->orgManager, $this->orgA );
        self::assertNotEmpty( $selectors, 'the portal exposes server-populated Clinic/room/clinician selectors' );
        self::assertSame( $clinic_key, (string) $selectors[0]['key'] );
        self::assertNotEmpty( $selectors[0]['clinicians'], 'assigned clinician is selectable' );
        $clinician_id = (int) $selectors[0]['clinicians'][0]['id'];

        $scheduled = ManagementPortalController::dispatch( $this->orgManager, 'org_facility_schedule', array(
            'organization_id' => (string) $this->orgA,
            'clinic_key'      => $clinic_key,
            'clinician_id'    => (string) $clinician_id,
            'room_key'        => 'primary',
            'day_of_week'     => '1',
            'start_time'      => '09:00',
            'end_time'        => '12:00',
        ) );
        self::assertTrue( $scheduled['ok'], 'weekly schedule through the portal' );

        $booking = ManagementPortalController::dispatch( $this->orgManager, 'org_facility_booking', array(
            'organization_id' => (string) $this->orgA,
            'clinic_key'      => $clinic_key,
            'duration'        => '20',
            'capacity'        => '1',
            'lead'            => '2',
            'future'          => '30',
            'cancel'          => '6',
        ) );
        self::assertTrue( $booking['ok'], 'booking policy through the portal' );

        $review = $service->review_facility_setup( $this->orgManager, $this->orgA );
        self::assertIsArray( $review );
        self::assertTrue( $review['ready'], 'review must be satisfied only by live rows: ' . implode( ' | ', $review['blocking'] ) );

        // A foreign Organization manager cannot activate this plan.
        self::assertFalse( ManagementPortalController::dispatch( $this->outsider, 'org_facility_activate', array( 'organization_id' => (string) $this->orgA ) )['ok'] );
        self::assertNotSame( 'active', (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            array( $clinic_id )
        ) );

        $activated = ManagementPortalController::dispatch( $this->orgManager, 'org_facility_activate', array( 'organization_id' => (string) $this->orgA ) );
        self::assertTrue( $activated['ok'], 'a ready hospital plan activates through the portal' );
        self::assertSame( 'active', (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            array( $clinic_id )
        ) );

        $after = $service->facility_plan( $this->orgManager, $this->orgA );
        self::assertIsArray( $after );
        self::assertNotSame( '', (string) $after['activated_at'], 'the plan records the activation receipt' );
    }

    // ==================================================================
    // 9. P0 hardening regressions (independent review)
    // ==================================================================

    public function test_non_manager_capability_mutation_is_refused_before_lookup(): void {
        // The secretary holds real scoped capabilities (PATIENT_READ…) but no
        // CONFIG, so it must not be able to mutate anybody's capability rules —
        // and the refusal must happen without touching storage.
        $target = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $target );
        self::assertTrue( App::authorization_service()->can( $this->staffMember, $this->clinicA, RolesAndCapabilities::PATIENT_READ ) );
        self::assertFalse( App::authorization_service()->can( $this->staffMember, $this->clinicA, RolesAndCapabilities::CONFIG ) );

        self::assertFalse( ManagementPortalController::dispatch(
            $this->staffMember,
            'clinic_staff_capability',
            array(
                'clinic_id'     => (string) $this->clinicA,
                'membership_id' => (string) (int) $target['id'],
                'capability'    => RolesAndCapabilities::PATIENT_READ,
                'effect'        => 'grant',
            )
        )['ok'], 'a non-manager may never mutate membership capabilities' );

        self::assertSame( array(), ( new MembershipRepository( App::db() ) )->capabilities_for( (int) $target['id'] ) );

        // A non-manager also cannot suspend/reactivate, change roles, or move scope.
        foreach ( array(
            array( 'action' => 'clinic_staff_status', 'input' => array( 'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) (int) $target['id'], 'active' => '0' ) ),
            array( 'action' => 'clinic_staff_role', 'input' => array( 'clinic_id' => (string) $this->clinicA, 'target_user_id' => (string) $this->clinicManager, 'role_key' => RolesAndCapabilities::ROLE_PATIENT ) ),
            array( 'action' => 'clinic_staff_locations', 'input' => array( 'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) (int) $target['id'], 'location_ids' => '' ) ),
            array( 'action' => 'clinic_staff_enroll', 'input' => array( 'clinic_id' => (string) $this->clinicA, 'identifier' => 'nope-' . bin2hex( random_bytes( 3 ) ) . '@example.test', 'role_key' => RolesAndCapabilities::ROLE_PATIENT ) ),
        ) as $case ) {
            self::assertFalse(
                ManagementPortalController::dispatch( $this->staffMember, $case['action'], $case['input'] )['ok'],
                'non-manager must fail closed for ' . $case['action']
            );
        }

        $still = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $still );
        self::assertSame( 'active', (string) $still['status'] );
    }

    public function test_self_role_escalation_is_refused(): void {
        $before = App::membership_service()->membership_for( $this->clinicA, $this->clinicManager );
        self::assertNotNull( $before );
        self::assertSame( RolesAndCapabilities::ROLE_MANAGER, (string) $before['role_key'] );
        self::assertFalse( App::authorization_service()->can( $this->clinicManager, $this->clinicA, RolesAndCapabilities::MEDICAL_READ ) );

        foreach ( array( RolesAndCapabilities::ROLE_DOCTOR, RolesAndCapabilities::ROLE_SECRETARY, RolesAndCapabilities::ROLE_ACCOUNTANT ) as $role_key ) {
            self::assertFalse( ManagementPortalController::dispatch(
                $this->clinicManager,
                'clinic_staff_role',
                array( 'clinic_id' => (string) $this->clinicA, 'target_user_id' => (string) $this->clinicManager, 'role_key' => $role_key )
            )['ok'], 'an actor may never change its own membership role' );
        }

        $after = App::membership_service()->membership_for( $this->clinicA, $this->clinicManager );
        self::assertNotNull( $after );
        self::assertSame( RolesAndCapabilities::ROLE_MANAGER, (string) $after['role_key'], 'self role unchanged' );
        self::assertFalse( App::authorization_service()->can( $this->clinicManager, $this->clinicA, RolesAndCapabilities::MEDICAL_READ ), 'no clinical authority was acquired' );

        // Self-suspension is refused as well (no lock-out, no self-reactivation).
        self::assertFalse( ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_status',
            array( 'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) (int) $after['id'], 'active' => '0' )
        )['ok'] );
    }

    public function test_forbidden_clinical_and_financial_role_delegation_is_refused(): void {
        $target = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $target );

        // The plain manager does not hold the doctor/accountant presets.
        self::assertFalse( ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_role',
            array( 'clinic_id' => (string) $this->clinicA, 'target_user_id' => (string) $this->staffMember, 'role_key' => RolesAndCapabilities::ROLE_DOCTOR )
        )['ok'], 'a manager may not hand out clinical authority it does not hold' );
        self::assertFalse( ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_role',
            array( 'clinic_id' => (string) $this->clinicA, 'target_user_id' => (string) $this->staffMember, 'role_key' => RolesAndCapabilities::ROLE_ACCOUNTANT )
        )['ok'], 'a manager may not hand out financial authority it does not hold' );

        $unchanged = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $unchanged );
        self::assertSame( RolesAndCapabilities::ROLE_SECRETARY, (string) $unchanged['role_key'] );
        self::assertFalse( App::authorization_service()->can( $this->staffMember, $this->clinicA, RolesAndCapabilities::MEDICAL_READ ) );
        // The secretary preset does hold FINANCE_READ, but the owner policy denies
        // void/refund; neither that nor any clinical capability may be added here.
        self::assertFalse( App::authorization_service()->can( $this->staffMember, $this->clinicA, RolesAndCapabilities::PAYMENT_REFUND ) );

        // A role whose preset is a subset of the actor's own caps is delegable.
        self::assertTrue( ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_staff_role',
            array( 'clinic_id' => (string) $this->clinicA, 'target_user_id' => (string) $this->staffMember, 'role_key' => RolesAndCapabilities::ROLE_PATIENT )
        )['ok'], 'a role carrying no authority beyond the actor is delegable' );
        $patient = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $patient );
        self::assertSame( RolesAndCapabilities::ROLE_PATIENT, (string) $patient['role_key'] );
        self::assertFalse( App::authorization_service()->can( $this->staffMember, $this->clinicA, RolesAndCapabilities::PATIENT_READ ) );
    }

    public function test_location_mutation_requires_scoped_management_before_lookup(): void {
        $location_id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d AND is_active = 1 LIMIT 1',
            array( $this->clinicA )
        );
        self::assertGreaterThan( 0, $location_id );

        // A secretary with an ACTIVE membership (but no CONFIG) is refused.
        foreach ( array(
            array( 'action' => 'clinic_location_update', 'input' => array( 'clinic_id' => (string) $this->clinicA, 'location_id' => (string) $location_id, 'name' => 'Hijacked' ) ),
            array( 'action' => 'clinic_location_active', 'input' => array( 'clinic_id' => (string) $this->clinicA, 'location_id' => (string) $location_id, 'active' => '0' ) ),
        ) as $case ) {
            self::assertFalse(
                ManagementPortalController::dispatch( $this->staffMember, $case['action'], $case['input'] )['ok'],
                'a non-manager must fail closed for ' . $case['action']
            );
        }
        self::assertSame( 'active', (string) App::db()->fetchValue(
            'SELECT CASE WHEN is_active = 1 THEN %s ELSE %s END FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE id = %d',
            array( 'active', 'inactive', $location_id )
        ) );
        self::assertNotSame( 'Hijacked', (string) App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE id = %d',
            array( $location_id )
        ) );

        // Same refusal shape for a foreign Location id (no existence probe).
        $foreign_location = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d LIMIT 1',
            array( $this->clinicB )
        );
        self::assertFalse( ManagementPortalController::dispatch(
            $this->clinicManager,
            'clinic_location_update',
            array( 'clinic_id' => (string) $this->clinicA, 'location_id' => (string) $foreign_location, 'name' => 'Cross Clinic' )
        )['ok'] );
        self::assertNotSame( 'Cross Clinic', (string) App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE id = %d',
            array( $foreign_location )
        ) );
    }

    public function test_sensitive_portal_changes_write_hash_chained_audit_rows(): void {
        $before = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE clinic_id = %d', array( $this->clinicA ) );

        $member = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $member );
        $membership_id = (int) $member['id'];

        self::assertTrue( ManagementPortalController::dispatch( $this->clinicManager, 'clinic_staff_status', array(
            'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) $membership_id, 'active' => '0',
        ) )['ok'] );
        self::assertTrue( ManagementPortalController::dispatch( $this->clinicManager, 'clinic_staff_status', array(
            'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) $membership_id, 'active' => '1',
        ) )['ok'] );
        self::assertTrue( ManagementPortalController::dispatch( $this->clinicManager, 'clinic_staff_capability', array(
            'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) $membership_id,
            'capability' => RolesAndCapabilities::PATIENT_READ, 'effect' => 'deny',
        ) )['ok'] );
        self::assertTrue( ManagementPortalController::dispatch( $this->clinicManager, 'clinic_staff_locations', array(
            'clinic_id' => (string) $this->clinicA, 'membership_id' => (string) $membership_id, 'location_ids' => '',
        ) )['ok'] );
        self::assertTrue( ManagementPortalController::dispatch( $this->clinicManager, 'clinic_staff_role', array(
            'clinic_id' => (string) $this->clinicA, 'target_user_id' => (string) $this->staffMember, 'role_key' => RolesAndCapabilities::ROLE_PATIENT,
        ) )['ok'] );

        $rows = App::db()->fetchAll(
            'SELECT action, clinic_id, actor_wp_user_id, prev_hash, row_hash, before_json, after_json'
            . ' FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE clinic_id = %d ORDER BY id ASC',
            array( $this->clinicA )
        );
        self::assertGreaterThan( $before, count( $rows ) );

        $actions = array_map( static fn( array $row ): string => (string) $row['action'], $rows );
        foreach ( array( 'MEMBERSHIP_SUSPENDED', 'MEMBERSHIP_REACTIVATED', 'MEMBERSHIP_CAPABILITY_CHANGED', 'MEMBERSHIP_SCOPE_CHANGED', 'MEMBERSHIP_ROLE_CHANGED' ) as $expected ) {
            self::assertContains( $expected, $actions, 'portal mutation must be audited: ' . $expected );
        }

        // The hash chain is global (one append-only sequence), so linkage is
        // verified over the recent window in id order: each row's prev_hash must
        // equal the immediately preceding row's row_hash.
        $chain = App::db()->fetchAll(
            'SELECT id, prev_hash, row_hash FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' ORDER BY id DESC LIMIT 60'
        );
        $chain = array_reverse( $chain );
        $previous = '';
        foreach ( $chain as $row ) {
            self::assertSame( 64, strlen( (string) $row['row_hash'] ) );
            if ( '' !== $previous ) {
                self::assertSame( $previous, (string) $row['prev_hash'], 'audit chain must stay linked at id ' . (string) $row['id'] );
            }
            $previous = (string) $row['row_hash'];
        }

        $deny_row = null;
        foreach ( $rows as $row ) {
            if ( 'MEMBERSHIP_CAPABILITY_CHANGED' === (string) $row['action'] ) {
                $deny_row = $row;
            }
        }
        self::assertNotNull( $deny_row );
        self::assertSame( $this->clinicManager, (int) $deny_row['actor_wp_user_id'] );
        self::assertStringContainsString( 'deny', (string) $deny_row['after_json'] );
    }

    public function test_organization_authority_alone_cannot_create_clinic_rooms(): void {
        self::assertTrue( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_start', array(
            'organization_id' => (string) $this->orgA,
            'facility_type'   => 'general_clinic',
            'structure'       => 'medical_building',
            'display_name'    => 'Boundary Building',
        ) )['ok'] );
        self::assertTrue( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_site', array(
            'organization_id' => (string) $this->orgA, 'label' => 'Boundary Site', 'address' => 'B 1',
        ) )['ok'] );

        $plan = App::management_portal_service()->facility_plan( $this->orgManager, $this->orgA );
        self::assertIsArray( $plan );
        $site_key = (string) $plan['sites'][0]['key'];
        self::assertTrue( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_clinic', array(
            'organization_id' => (string) $this->orgA, 'name' => 'Boundary Clinic', 'site_key' => $site_key, 'timezone' => 'Asia/Tehran',
        ) )['ok'] );

        $plan = App::management_portal_service()->facility_plan( $this->orgManager, $this->orgA );
        self::assertIsArray( $plan );
        $clinic_key = (string) $plan['clinics'][0]['key'];
        $clinic_id  = (int) $plan['clinics'][0]['clinic_id'];

        // P1-8: room creation is a Clinic operation. Organization authority alone
        // must not be silently converted into Clinic authority.
        self::assertFalse( App::authorization_service()->can( $this->orgManager, $clinic_id, RolesAndCapabilities::CONFIG ) );
        self::assertFalse( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_room', array(
            'organization_id' => (string) $this->orgA, 'clinic_key' => $clinic_key, 'name' => 'Room No Authority', 'site_key' => $site_key,
        ) )['ok'], 'Organization authority alone cannot create Clinic rooms' );
        $locations = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d',
            array( $clinic_id )
        );
        self::assertSame( 1, $locations, 'only the primary Location exists' );

        // After an explicit designation the same actor holds delegated Clinic
        // authority and the room step succeeds.
        self::assertTrue( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_designate', array(
            'organization_id' => (string) $this->orgA, 'clinic_key' => $clinic_key, 'target_user_id' => (string) $this->orgManager,
        ) )['ok'] );
        self::assertTrue( App::authorization_service()->can( $this->orgManager, $clinic_id, RolesAndCapabilities::CONFIG ) );
        self::assertTrue( ManagementPortalController::dispatch( $this->orgManager, 'org_facility_room', array(
            'organization_id' => (string) $this->orgA, 'clinic_key' => $clinic_key, 'name' => 'Room Delegated', 'site_key' => $site_key,
        ) )['ok'], 'delegated Clinic authority may create the Clinic room' );
        self::assertSame( 2, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d',
            array( $clinic_id )
        ) );
    }

    public function test_clinician_entry_eligibility_is_cpms_data_not_a_wordpress_role(): void {
        $facility = App::facility_setup_service();
        $has_cpms = new \ReflectionMethod( $facility, 'has_cpms_entry' );
        $has_cpms->setAccessible( true );

        // An ACTIVE manager membership with CPMS CONFIG is CPMS-entry eligible
        // regardless of the account being a plain WordPress Subscriber.
        self::assertTrue( $has_cpms->invoke( $facility, $this->clinicManager, RolesAndCapabilities::ROLE_MANAGER, $this->clinicA ) );
        $user = get_userdata( $this->clinicManager );
        self::assertInstanceOf( \WP_User::class, $user );
        self::assertSame( array( 'subscriber' ), array_values( (array) $user->roles ) );
        self::assertFalse( $user->has_cap( RolesAndCapabilities::CONFIG ), 'no WordPress-global capability is involved' );

        // No membership → not eligible, even for a WordPress Administrator.
        self::assertFalse( $has_cpms->invoke( $facility, $this->admin, RolesAndCapabilities::ROLE_MANAGER, $this->clinicA ) );
        // Suspended membership → not eligible.
        $member = App::membership_service()->membership_for( $this->clinicA, $this->staffMember );
        self::assertNotNull( $member );
        App::membership_service()->suspend_membership( (int) $member['id'] );
        self::assertFalse( $has_cpms->invoke( $facility, $this->staffMember, RolesAndCapabilities::ROLE_SECRETARY, $this->clinicA ) );
    }

    public function test_multi_clinic_clinician_is_visible_in_secondary_clinic(): void {
        // A clinician profile lives in Clinic B but the account holds an ACTIVE
        // membership in Clinic A: it must be visible AND schedulable in A.
        $doctor = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        $this->userIds[] = $doctor;
        App::membership_service()->create_membership( $this->clinicB, $doctor, RolesAndCapabilities::ROLE_DOCTOR );
        App::membership_service()->create_membership( $this->clinicA, $doctor, RolesAndCapabilities::ROLE_DOCTOR );

        $clinician_id = App::clinicianRepository()->create( $this->clinicB, array(
            'full_name'  => 'Dr Multi',
            'wp_user_id' => $doctor,
            'is_active'  => 1,
        ) );
        self::assertGreaterThan( 0, $clinician_id );

        // Visible in the secondary Clinic's workspace.
        $workspace = App::management_portal_service()->clinic_workspace( $this->clinicManager, $this->clinicA );
        self::assertNotNull( $workspace );
        $ids = array_map( static fn( array $row ): int => (int) $row['id'], $workspace['clinicians'] );
        self::assertContains( $clinician_id, $ids, 'a clinician serving a secondary Clinic must be visible there' );
        $seen_row = null;
        foreach ( $workspace['clinicians'] as $row ) {
            if ( (int) $row['id'] === $clinician_id ) {
                $seen_row = $row;
            }
        }
        self::assertNotNull( $seen_row );
        self::assertFalse( (bool) $seen_row['home_clinic_matches'], 'home Clinic is elsewhere' );
        self::assertSame( $this->clinicB, (int) $seen_row['home_clinic'] );

        // ... and schedulable in the secondary Clinic through the portal.
        $location_id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d AND is_active = 1 LIMIT 1',
            array( $this->clinicA )
        );
        self::assertTrue( ManagementPortalController::dispatch( $this->clinicManager, 'clinic_schedule_create', array(
            'clinic_id'    => (string) $this->clinicA,
            'clinician_id' => (string) $clinician_id,
            'location_id'  => (string) $location_id,
            'day_of_week'  => '2',
            'start_time'   => '10:00',
            'end_time'     => '11:00',
        ) )['ok'], 'secondary-Clinic schedule through the portal' );
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

    /**
     * Explicit membership capability grants (fixture for the delegation bound).
     *
     * @param list<string> $caps
     */
    private function grant_caps( int $clinic_id, int $user_id, array $caps ): void {
        $membership = App::membership_service()->membership_for( $clinic_id, $user_id );
        self::assertNotNull( $membership );
        foreach ( $caps as $cap ) {
            App::membership_service()->set_capability( (int) $membership['id'], (string) $cap, 'grant' );
        }
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
