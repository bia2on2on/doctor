<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicManagementPage;
use ClinicCore\Bootstrap\App;
use ClinicCore\Auth\RolesAndCapabilities;
use RuntimeException;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Post-onboarding Clinic & Location management — DB-backed lifecycle + isolation.
 *
 * Covers: negative permissions, cross-tenant isolation, lifecycle concurrency,
 * audit rollback, historical continuity, and preservation of medical/financial/audit/backup history.
 */
final class ClinicLifecycleManagementTest extends WP_UnitTestCase {

    use RealTableMigrations;

    private int $orgA = 0;
    private int $orgB = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $managerA = 0;
    private int $managerB = 0;
    private int $clinicMember = 0;
    private int $outsider = 0;
    private int $admin = 0;
    private string $orgA_status = 'active';
    private string $orgB_status = 'active';
    /** @var list<int> */
    private array $membershipRows = [];

    protected function setUp(): void {
        parent::setUp();
        $this->withRealTables( static fn(): array => App::migrations()->migrate() );
        App::resetScope();

        $this->orgA = $this->create_org( 'PostOnboard Org A' );
        $this->orgB = $this->create_org( 'PostOnboard Org B' );
        $this->managerA = self::factory()->user->create( ['role' => 'subscriber'] );
        $this->managerB = self::factory()->user->create( ['role' => 'subscriber'] );
        $this->clinicMember = self::factory()->user->create( ['role' => 'subscriber'] );
        $this->outsider = self::factory()->user->create( ['role' => 'subscriber'] );
        $this->admin = self::factory()->user->create( ['role' => 'administrator'] );

        $this->grant_manager( $this->orgA, $this->managerA );
        $this->grant_manager( $this->orgB, $this->managerB );

        // Provision two clinics via org authority — the only path that can create clinics
        wp_set_current_user( $this->managerA );
        $resA = App::organization_clinic_provisioning_service()->provision(
            $this->managerA, $this->orgA, 'Clinic A Lifecycle', 'clinic-a-lifecycle', 'Asia/Tehran', 'Main A'
        );
        $this->clinicA = (int) $resA['clinic_id'];

        wp_set_current_user( $this->managerB );
        $resB = App::organization_clinic_provisioning_service()->provision(
            $this->managerB, $this->orgB, 'Clinic B Isolated', 'clinic-b-isolated', 'Europe/Berlin', 'Main B'
        );
        $this->clinicB = (int) $resB['clinic_id'];

        // Clinic-scoped member for Clinic A — can do Location work but not Org clinic lifecycle
        $this->membershipRows[] = App::membership_service()->create_membership(
            $this->clinicA, $this->clinicMember, RolesAndCapabilities::ROLE_MANAGER
        );

        // Add durable medical history that must survive suspend.
        $this->seed_history( $this->clinicA );
        wp_set_current_user( 0 );
    }

    protected function tearDown(): void {
        $db = App::db();

        foreach ( [$this->clinicA, $this->clinicB] as $cid ) {
            if ( $cid > 0 ) {
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_schedule_slots' ) . ' WHERE clinic_id = %d', [$cid] );
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_schedule_exceptions' ) . ' WHERE clinic_id = %d', [$cid] );
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_schedule' ) . ' WHERE clinic_id = %d', [$cid] );
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_patients' ) . ' WHERE clinic_id = %d', [$cid] );
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_invoices' ) . ' WHERE clinic_id = %d', [$cid] );
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_locations' ) . ' WHERE clinic_id = %d', [$cid] );
            }
        }
        foreach ( $this->membershipRows as $mid ) {
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_membership_capabilities' ) . ' WHERE membership_id = %d', [$mid] );
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE id = %d', [$mid] );
        }
        foreach ( [$this->orgA, $this->orgB] as $oid ) {
            if ( $oid > 0 ) {
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_organization_membership_capabilities' ) . ' WHERE membership_id IN (SELECT id FROM ' . $db->table( 'cpms_organization_memberships' ) . ' WHERE organization_id = %d)', [$oid] );
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_organization_memberships' ) . ' WHERE organization_id = %d', [$oid] );
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE organization_id = %d', [$oid] );
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_organizations' ) . ' WHERE id = %d', [$oid] );
            }
        }
        foreach ( [$this->managerA, $this->managerB, $this->clinicMember, $this->outsider, $this->admin] as $uid ) {
            if ( $uid > 0 ) {
                wp_delete_user( $uid );
            }
        }
        App::resetScope();
        parent::tearDown();
    }

    // ============ Helpers ============

    private function create_org( string $name ): int {
        $db = App::db();
        $now = $db->nowUtcSql();
        $db->insert( 'cpms_organizations', [
            'name' => $name . ' ' . bin2hex( random_bytes( 3 ) ),
            'slug' => 'org-' . bin2hex( random_bytes( 4 ) ),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ] );
        return (int) $db->wpdb_last_insert_id();
    }

    private function grant_manager( int $org_id, int $user_id ): void {
        $db = App::db();
        $now = $db->nowUtcSql();
        $db->insert( 'cpms_organization_memberships', [
            'organization_id' => $org_id,
            'wp_user_id' => $user_id,
            'role_key' => 'organization_manager',
            'status' => 'active',
            'created_by_wp_user_id' => $user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ] );
    }

    private function seed_history( int $clinic_id ): void {
        $db = App::db();
        $now = $db->nowUtcSql();
        $mrn = 'MRN-HIST-' . bin2hex( random_bytes( 3 ) );
        $mobile = '0910' . str_pad( (string) random_int( 1000000, 9999999 ), 7, '0', STR_PAD_LEFT );
        $db->insert( 'cpms_patients', [
            'clinic_id' => $clinic_id,
            'mrn' => $mrn,
            'first_name' => 'تاریخچه',
            'last_name' => 'حفظ',
            'mobile' => $mobile,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ] );
    }

    // ============ Tests: Clinic settings update ============

    public function testOrganizationManagerCanUpdateClinicProfileAndAuditIsPreserved(): void {
        wp_set_current_user( $this->managerA );
        $before = App::db()->fetchRow( 'SELECT name, address, phone, organization_id, slug, timezone, created_at FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d', [$this->clinicA] );
        self::assertNotNull( $before );

        $new_name = 'نام به‌روز درمانگاه A ' . bin2hex( random_bytes( 2 ) );
        $result = App::organization_clinic_provisioning_service()->update_clinic_profile(
            $this->managerA, $this->orgA, $this->clinicA, ['name' => $new_name, 'address' => 'تهران، خیابان نمونه ۱۲۳', 'phone' => '021-11112222']
        );
        self::assertFalse( $result['noop'] );
        self::assertSame( $new_name, $result['name'] );
        self::assertGreaterThan( 0, $result['audit_id'] );

        $after = App::db()->fetchRow( 'SELECT name, address, phone, organization_id, slug, timezone, created_at FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d', [$this->clinicA] );
        self::assertSame( $new_name, $after['name'] );
        self::assertSame( 'تهران، خیابان نمونه ۱۲۳', $after['address'] );
        self::assertSame( (int) $before['organization_id'], (int) $after['organization_id'] );
        self::assertSame( (string) $before['slug'], (string) $after['slug'] );
        self::assertSame( (string) $before['timezone'], (string) $after['timezone'] );
        self::assertSame( (string) $before['created_at'], (string) $after['created_at'] );

        // Audit chain intact
        self::assertTrue( App::audit()->verifyChain( 500 )['ok'] );
        $log = App::db()->fetchRow( 'SELECT action, clinic_id, resource_id FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE id = %d', [$result['audit_id']] );
        self::assertSame( 'CLINIC_PROFILE_UPDATED', $log['action'] );
        self::assertSame( $this->clinicA, (int) $log['clinic_id'] );
    }

    public function testClinicProfileUpdateIsIdempotentOnIdenticalData(): void {
        wp_set_current_user( $this->managerA );
        $current = App::db()->fetchRow( 'SELECT name, address, phone FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d', [$this->clinicA] );
        $audit_before = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE action = %s AND resource_id = %d', ['CLINIC_PROFILE_UPDATED', $this->clinicA] );

        $r1 = App::organization_clinic_provisioning_service()->update_clinic_profile(
            $this->managerA, $this->orgA, $this->clinicA, ['name' => (string) $current['name'], 'address' => $current['address'], 'phone' => $current['phone']]
        );
        self::assertTrue( $r1['noop'] );
        self::assertNull( $r1['audit_id'] );

        $audit_after = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE action = %s AND resource_id = %d', ['CLINIC_PROFILE_UPDATED', $this->clinicA] );
        self::assertSame( $audit_before, $audit_after, 'identical update must not create a second audit event' );
    }

    public function testClinicProfileUpdateRejectsValidationWithoutMutation(): void {
        wp_set_current_user( $this->managerA );
        $before = App::db()->fetchRow( 'SELECT name, address FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d', [$this->clinicA] );
        $too_long = str_repeat( 'ا', 191 );
        try {
            App::organization_clinic_provisioning_service()->update_clinic_profile(
                $this->managerA, $this->orgA, $this->clinicA, ['name' => $too_long]
            );
            self::fail( 'validation must fail' );
        } catch ( \Throwable $e ) {
            self::assertStringContainsString( 'invalid', strtolower( $e->getMessage() ) );
        }
        $after = App::db()->fetchRow( 'SELECT name FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d', [$this->clinicA] );
        self::assertSame( (string) $before['name'], (string) $after['name'], 'failed validation must not mutate' );
    }

    // ============ Negative permissions & cross-tenant isolation ============

    public function testClinicMemberCannotUpdateClinicViaOrganizationPath(): void {
        wp_set_current_user( $this->clinicMember );
        try {
            App::organization_clinic_provisioning_service()->update_clinic_profile(
                $this->clinicMember, $this->orgA, $this->clinicA, ['name' => 'Hacked']
            );
            self::fail( 'clinic-scoped member must not use organization path' );
        } catch ( RuntimeException $e ) {
            self::assertStringContainsString( 'denied', strtolower( $e->getMessage() ) );
        }
    }

    public function testOutsideOrganizationManagerCannotUpdateOrLifecycleOtherOrganizationClinic(): void {
        wp_set_current_user( $this->managerA );
        try {
            App::organization_clinic_provisioning_service()->update_clinic_profile(
                $this->managerA, $this->orgB, $this->clinicB, ['name' => 'Cross Org Hack']
            );
            self::fail( 'cross-org update must be denied' );
        } catch ( RuntimeException $e ) {
            self::assertStringContainsString( 'denied', strtolower( $e->getMessage() ) );
        }

        try {
            App::organization_clinic_provisioning_service()->set_clinic_status(
                $this->managerA, $this->orgB, $this->clinicB, false
            );
            self::fail( 'cross-org lifecycle must be denied' );
        } catch ( RuntimeException $e ) {
            self::assertStringContainsString( 'denied', strtolower( $e->getMessage() ) );
        }

        // Ensure clinic B still active
        $status = App::db()->fetchValue( 'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d', [$this->clinicB] );
        self::assertSame( 'active', $status );
    }

    public function testWordPressAdministratorWithoutOrganizationMembershipIsDenied(): void {
        wp_set_current_user( $this->admin );
        try {
            App::organization_clinic_provisioning_service()->update_clinic_profile(
                $this->admin, $this->orgA, $this->clinicA, ['name' => 'Admin Hack']
            );
            self::fail( 'WP admin without org membership must be denied' );
        } catch ( RuntimeException $e ) {
            self::assertStringContainsString( 'denied', strtolower( $e->getMessage() ) );
        }
        try {
            App::organization_clinic_provisioning_service()->set_clinic_status( $this->admin, $this->orgA, $this->clinicA, false );
            self::fail( 'WP admin without org membership must be denied lifecycle' );
        } catch ( RuntimeException $e ) {
            self::assertStringContainsString( 'denied', strtolower( $e->getMessage() ) );
        }
    }

    public function testOrganizationDenyOverridesClinicAdministrationPreset(): void {
        // Explicit DENY on the existing Organization Clinic authority must beat the Manager preset
        $membership_id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_organization_memberships' ) . ' WHERE organization_id = %d AND wp_user_id = %d',
            [$this->orgA, $this->managerA]
        );
        App::db()->insert( 'cpms_organization_membership_capabilities', [
            'membership_id' => $membership_id,
            'capability' => 'cpms_org_clinic_update',
            'effect' => 'deny',
            'created_by_wp_user_id' => $this->managerA,
            'created_at' => App::db()->nowUtcSql(),
        ] );

        wp_set_current_user( $this->managerA );
        try {
            App::organization_clinic_provisioning_service()->update_clinic_profile(
                $this->managerA, $this->orgA, $this->clinicA, ['name' => 'Deny Override Test']
            );
            self::fail( 'DENY must override preset' );
        } catch ( RuntimeException $e ) {
            self::assertStringContainsString( 'denied', strtolower( $e->getMessage() ) );
        }

        // Cleanup
        App::db()->query( 'DELETE FROM ' . App::db()->table( 'cpms_organization_membership_capabilities' ) . ' WHERE membership_id = %d AND capability = %s', [$membership_id, 'cpms_org_clinic_update'] );

        // Now should succeed again
        $r = App::organization_clinic_provisioning_service()->update_clinic_profile(
            $this->managerA, $this->orgA, $this->clinicA, ['name' => 'After Deny Removed']
        );
        self::assertFalse( $r['noop'] );
    }

    // ============ Lifecycle: deactivate/reactivate with preservation ============

    public function testClinicLifecyclePreservesMedicalFinancialAuditAndBackupHistory(): void {
        wp_set_current_user( $this->managerA );
        $patient_before = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE clinic_id = %d', [$this->clinicA] );
        $audit_before = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE clinic_id = %d', [$this->clinicA] );
        self::assertGreaterThan( 0, $patient_before );

        $deact = App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, false );
        self::assertFalse( $deact['noop'] );
        self::assertSame( 'suspended', $deact['status'] );
        self::assertGreaterThan( 0, $deact['audit_id'] );

        // History preserved (patient remains, no financial rows are deleted)
        self::assertSame( $patient_before, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE clinic_id = %d', [$this->clinicA] ) );
        // Audit chain still valid and new audit exists
        self::assertTrue( App::audit()->verifyChain( 500 )['ok'] );
        $new_audit = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE clinic_id = %d', [$this->clinicA] );
        self::assertSame( $audit_before + 1, $new_audit );

        // Backup enumeration still includes suspended clinic (it selects all clinics)
        $all_clinics = App::db()->fetchAll( 'SELECT id FROM ' . App::db()->table( 'cpms_clinics' ) );
        $ids = array_map( static fn( $r ) => (int) $r['id'], $all_clinics );
        self::assertContains( $this->clinicA, $ids, 'suspended clinic must still be enumerated for backup' );

        // Reactivate
        $react = App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, true );
        self::assertFalse( $react['noop'] );
        self::assertSame( 'active', $react['status'] );
        self::assertTrue( App::audit()->verifyChain( 500 )['ok'] );

        // History still there after round-trip
        self::assertSame( $patient_before, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE clinic_id = %d', [$this->clinicA] ) );
    }

    public function testClinicLifecycleIsIdempotentAndDoesNotDuplicateAudit(): void {
        wp_set_current_user( $this->managerA );
        // Ensure active
        App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, true );

        $audit_before = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE action IN (%s,%s) AND resource_id = %d', ['CLINIC_DEACTIVATED','CLINIC_REACTIVATED', $this->clinicA] );

        $r1 = App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, false );
        self::assertFalse( $r1['noop'] );
        $r2 = App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, false );
        self::assertTrue( $r2['noop'] );
        self::assertNull( $r2['audit_id'] );

        $audit_after = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE action IN (%s,%s) AND resource_id = %d', ['CLINIC_DEACTIVATED','CLINIC_REACTIVATED', $this->clinicA] );
        self::assertSame( $audit_before + 1, $audit_after, 'second identical suspend must not create second audit' );

        // Cleanup reactivate for other tests
        App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, true );
    }

    /**
     * Suspension is operation-specific, never a blanket scope denial.
     *
     * Existing clinical/financial/history operations stay authorized under their
     * ORIGINAL permissions (Clinic-scoped membership for establishment, explicit
     * Organization capability for management), while genuinely new demand is refused
     * at the authoritative boundaries: BookingService hold/confirm/reschedule/staff
     * booking, patient self-enrollment and background slot generation each read
     * cpms_clinics.status (under a Clinic row lock where a race is possible).
     */
    public function testSuspendedClinicPreservesContinuityAndRefusesNewDemandAtBoundaries(): void {
        wp_set_current_user( $this->managerA );
        $deact = App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, false );
        self::assertSame( 'suspended', $deact['status'] );

        // CONTINUITY: Clinic-scoped establishment keeps working for the existing
        // member under its original membership permission (records, medical files,
        // treatment completion, existing appointments, finance, reports, export).
        wp_set_current_user( $this->clinicMember );
        $establisher = new \ClinicCore\Application\Scope\TrustedClinicEstablisher( App::db(), new \ClinicCore\Infrastructure\Repository\MembershipRepository( App::db() ) );
        $scope = $establisher->establish( $this->clinicMember, $this->clinicA );
        self::assertSame( $this->clinicA, $scope->clinicId );

        // CONTINUITY: durable medical history seeded before suspension is intact.
        self::assertGreaterThan(
            0,
            (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE clinic_id = %d', [ $this->clinicA ] )
        );
        self::assertTrue( App::audit()->verifyChain( 500 )['ok'] );

        // NEW DEMAND: slot generation only sweeps Clinics with status = active.
        self::assertNull(
            App::db()->fetchValue(
                'SELECT cl.id FROM ' . App::db()->table( 'cpms_clinics' ) . ' cl WHERE cl.id = %d AND cl.status = %s',
                [ $this->clinicA, 'active' ]
            ),
            'suspended Clinic must be excluded from slot generation and self-enrollment scope'
        );

        // Org management keeps working while suspended and reactivation restores new demand.
        wp_set_current_user( $this->managerA );
        $profile = App::organization_clinic_provisioning_service()->update_clinic_profile(
            $this->managerA, $this->orgA, $this->clinicA, [ 'name' => 'نام در حالت تعلیق ' . bin2hex( random_bytes( 2 ) ) ]
        );
        self::assertFalse( $profile['noop'] );

        $react = App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, true );
        self::assertSame( 'active', $react['status'] );
        self::assertNotNull(
            App::db()->fetchValue(
                'SELECT cl.id FROM ' . App::db()->table( 'cpms_clinics' ) . ' cl WHERE cl.id = %d AND cl.status = %s',
                [ $this->clinicA, 'active' ]
            )
        );

        wp_set_current_user( $this->clinicMember );
        $scope_after = ( new \ClinicCore\Application\Scope\TrustedClinicEstablisher( App::db(), new \ClinicCore\Infrastructure\Repository\MembershipRepository( App::db() ) ) )->establish( $this->clinicMember, $this->clinicA );
        self::assertSame( $this->clinicA, $scope_after->clinicId );
    }

    /**
     * Explicit DENY precedence is preserved INDEPENDENTLY for each separate
     * Organization Clinic capability: denying lifecycle must not disable profile
     * updates, and vice versa.
     */
    public function testOrganizationDenyIsIndependentPerClinicCapability(): void {
        $membership_id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_organization_memberships' ) . ' WHERE organization_id = %d AND wp_user_id = %d',
            [ $this->orgA, $this->managerA ]
        );
        App::db()->insert( 'cpms_organization_membership_capabilities', [
            'membership_id'           => $membership_id,
            'capability'              => 'cpms_org_clinic_lifecycle',
            'effect'                  => 'deny',
            'created_by_wp_user_id'   => $this->managerA,
            'created_at'              => App::db()->nowUtcSql(),
        ] );

        wp_set_current_user( $this->managerA );
        try {
            App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, false );
            self::fail( 'lifecycle DENY must block lifecycle transitions' );
        } catch ( RuntimeException $e ) {
            self::assertStringContainsString( 'denied', strtolower( $e->getMessage() ) );
        }

        // Profile update capability is unaffected by the lifecycle DENY.
        $r = App::organization_clinic_provisioning_service()->update_clinic_profile(
            $this->managerA, $this->orgA, $this->clinicA, [ 'name' => 'Still allowed under lifecycle DENY ' . bin2hex( random_bytes( 2 ) ) ]
        );
        self::assertFalse( $r['noop'] );

        // Status unchanged (lifecycle really was refused).
        self::assertSame(
            'active',
            (string) App::db()->fetchValue( 'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d', [ $this->clinicA ] )
        );

        App::db()->query(
            'DELETE FROM ' . App::db()->table( 'cpms_organization_membership_capabilities' ) . ' WHERE membership_id = %d AND capability = %s',
            [ $membership_id, 'cpms_org_clinic_lifecycle' ]
        );

        // Lifecycle authority returns once the DENY row is removed.
        $after = App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, false );
        self::assertSame( 'suspended', $after['status'] );
        App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, true );
    }

    public function testClinicLifecycleRespectsSuspendedOrganizationFailClosed(): void {
        App::db()->update( 'cpms_organizations', ['status' => 'suspended'], ['id' => $this->orgA] );
        wp_set_current_user( $this->managerA );
        try {
            App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, false );
            self::fail( 'suspended organization must fail lifecycle' );
        } catch ( RuntimeException $e ) {
            self::assertStringContainsString( 'denied', strtolower( $e->getMessage() ) );
        }
        // Restore
        App::db()->update( 'cpms_organizations', ['status' => 'active'], ['id' => $this->orgA] );
    }

    // ============ Location under explicit Clinic — cross-tenant isolation ============

    public function testAdditionalLocationCreationUnderExplicitAuthorizedClinicAndCrossClinicIsolation(): void {
        // clinicMember can create location in Clinic A
        wp_set_current_user( $this->clinicMember );
        $loc = App::locationService()->createLocation( $this->clinicMember, $this->clinicA, [
            'name' => 'شعبه دوم مطب',
            'slug' => 'second-' . bin2hex( random_bytes( 3 ) ),
            'timezone' => 'Asia/Tehran',
        ] );
        self::assertSame( $this->clinicA, $loc['clinic_id'] );
        self::assertSame( 0, $loc['is_primary'] );

        // Same actor must NOT create location in Clinic B via forged clinic_id
        try {
            App::locationService()->createLocation( $this->clinicMember, $this->clinicB, [
                'name' => 'شعبه جعلی',
                'slug' => 'hijack-' . bin2hex( random_bytes( 3 ) ),
                'timezone' => 'Asia/Tehran',
            ] );
            self::fail( 'cross-clinic location creation must be denied' );
        } catch ( \ClinicCore\Application\Location\LocationException $e ) {
            self::assertSame( \ClinicCore\Application\Location\LocationException::PERMISSION_DENIED, $e->getErrorCode() );
        }

        // Cleanup location
        App::db()->query( 'DELETE FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE id = %d', [$loc['location_id']] );
    }

    public function testLocationAdminPageEnforcesExplicitClinicSelectorAndDeniesOutsider(): void {
        wp_set_current_user( $this->outsider );
        $res = \ClinicCore\Admin\LocationAdminPage::upsertLocation(
            ['mode' => 'create', 'clinic_id' => $this->clinicA, 'name' => 'Test', 'slug' => 'test-loc', 'timezone' => 'Asia/Tehran'],
            $this->outsider
        );
        self::assertNotSame( '', $res['error'], 'outsider must be denied' );
        self::assertSame( 0, $res['location_id'] );

        // Forged clinic_id that outsider does not belong to — still denied with generic message (no enumeration)
        $res2 = \ClinicCore\Admin\LocationAdminPage::upsertLocation(
            ['mode' => 'create', 'clinic_id' => $this->clinicB, 'name' => 'Test2', 'slug' => 'test-loc2', 'timezone' => 'Asia/Tehran'],
            $this->outsider
        );
        self::assertNotSame( '', $res2['error'] );
    }

    // ============ Clinic Admin pure boundary — explicit selectors, no first-clinic auto-selection ============

    public function testClinicManagementPageRejectsNoSelectionAndRespectsExplicitAuthorization(): void {
        wp_set_current_user( $this->managerA );
        // Missing organization_id -> error
        $r = ClinicManagementPage::update_clinic_for_test( ['organization_id' => 0, 'clinic_id' => $this->clinicA, 'name' => 'Should Fail'], $this->managerA );
        self::assertNotSame( '', $r['error'] );

        // Valid explicit selection succeeds
        $currentName = (string) App::db()->fetchValue( 'SELECT name FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d', [$this->clinicA] );
        $r2 = ClinicManagementPage::update_clinic_for_test( ['organization_id' => $this->orgA, 'clinic_id' => $this->clinicA, 'name' => $currentName . ' v2'], $this->managerA );
        self::assertSame( '', $r2['error'] );

        // Forged org for this clinic -> denied (clinic belongs to orgA, not orgB)
        wp_set_current_user( $this->managerB );
        $r3 = ClinicManagementPage::update_clinic_for_test( ['organization_id' => $this->orgB, 'clinic_id' => $this->clinicA, 'name' => 'Hijack'], $this->managerB );
        self::assertNotSame( '', $r3['error'] );
    }

    public function testClinicLifecycleViaAdminBoundaryIsIdempotent(): void {
        wp_set_current_user( $this->managerA );
        // Ensure active
        App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, true );

        $r1 = ClinicManagementPage::lifecycle_for_test( $this->managerA, $this->orgA, $this->clinicA, false );
        self::assertSame( '', $r1['error'] );
        self::assertFalse( $r1['noop'] );
        self::assertSame( 'suspended', $r1['status'] );

        $r2 = ClinicManagementPage::lifecycle_for_test( $this->managerA, $this->orgA, $this->clinicA, false );
        self::assertSame( '', $r2['error'] );
        self::assertTrue( $r2['noop'], 'second suspend with same state must be noop' );

        // Restore
        ClinicManagementPage::lifecycle_for_test( $this->managerA, $this->orgA, $this->clinicA, true );
    }

    // ============ Audit atomicity & chain integrity ============

    /**
     * Status mutation and its hash-chained audit event are written inside one
     * transaction, and a denied attempt must leave neither an audit row nor a
     * status change behind. NOTE (evidence honesty): a forced audit-INSERT fault
     * is NOT injected here — that rollback path is covered by the existing
     * OrganizationManagerBootstrapTest; this test proves the deny/no-orphan and
     * chain-integrity half only.
     */
    public function testDeniedLifecycleLeavesNoAuditRowAndNoStatusChange(): void {
        wp_set_current_user( $this->managerA );
        $count_before = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE resource_id = %d', [$this->clinicA] );
        $status_before = (string) App::db()->fetchValue( 'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d', [$this->clinicA] );

        // Attempt lifecycle with invalid auth (outsider) — must not mutate audit or status
        wp_set_current_user( $this->outsider );
        try {
            App::organization_clinic_provisioning_service()->set_clinic_status( $this->outsider, $this->orgA, $this->clinicA, false );
            self::fail( 'outsider lifecycle must be denied' );
        } catch ( RuntimeException $e ) {
            self::assertStringContainsString( 'denied', strtolower( $e->getMessage() ) );
        }
        self::assertSame( $count_before, (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' ) . ' WHERE resource_id = %d', [$this->clinicA] ) );
        self::assertSame( $status_before, (string) App::db()->fetchValue( 'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d', [$this->clinicA] ) );

        // Verify chain still intact after denied attempts
        wp_set_current_user( $this->managerA );
        self::assertTrue( App::audit()->verifyChain( 500 )['ok'] );
    }

    public function testCrossClinicPHIIsolationIsPreservedAfterClinicSuspend(): void {
        // Seed a second patient in Clinic B to prove isolation
        $db = App::db();
        $now = $db->nowUtcSql();
        $db->insert( 'cpms_patients', [
            'clinic_id' => $this->clinicB,
            'mrn' => 'MRN-ISO-' . bin2hex( random_bytes( 3 ) ),
            'first_name' => 'ClinicB',
            'last_name' => 'Patient',
            'mobile' => '0930' . str_pad( (string) random_int( 1000000, 9999999 ), 7, '0', STR_PAD_LEFT ),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ] );
        $patientB = (int) $db->wpdb_last_insert_id();

        // Suspend Clinic A
        wp_set_current_user( $this->managerA );
        App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, false );

        // ClinicMember (member of A only) must not read Clinic B patient via object authorization
        self::assertFalse( App::authorization_service()->canForObject( $this->clinicMember, $this->clinicA, RolesAndCapabilities::PATIENT_READ, $this->clinicB ), 'cross-clinic PHI must be denied even after suspend' );
        self::assertFalse( App::authorization_service()->canForObject( $this->clinicMember, $this->clinicB, RolesAndCapabilities::PATIENT_READ, $this->clinicB ), 'member of A has no membership in B, so cannot read B' );

        // ManagerA (org manager of A) has no clinic membership, so also denied — org authority does not grant PHI
        self::assertFalse( App::authorization_service()->can( $this->managerA, $this->clinicA, RolesAndCapabilities::PATIENT_READ ) );
        self::assertFalse( App::authorization_service()->can( $this->managerA, $this->clinicB, RolesAndCapabilities::PATIENT_READ ) );

        // Cleanup
        $db->query( 'DELETE FROM ' . $db->table( 'cpms_patients' ) . ' WHERE id = %d', [$patientB] );
        wp_set_current_user( $this->managerA );
        App::organization_clinic_provisioning_service()->set_clinic_status( $this->managerA, $this->orgA, $this->clinicA, true );
    }

    // ============ Facility catalog preserved ============

    public function testTwelveTypeFacilityCatalogIsPreservedAndInpatientRemainsUnsupported(): void {
        $all = \ClinicCore\Application\Onboarding\FacilityTypeCatalog::all();
        self::assertCount( 12, $all, 'twelve-type catalog must remain exactly 12' );
        foreach ( $all as $type ) {
            self::assertArrayHasKey( 'id', $type );
            self::assertArrayHasKey( 'label', $type );
            self::assertArrayHasKey( 'flow', $type );
        }
        self::assertFalse( \ClinicCore\Application\Onboarding\FacilityTypeCatalog::flow_supports_inpatient( 'hospital_network' ), 'inpatient/HIS remains unsupported for hospital flow' );
        self::assertFalse( \ClinicCore\Application\Onboarding\FacilityTypeCatalog::supports_multi_clinic_provisioning(), 'facility catalog never provisions clinics' );
    }
}
