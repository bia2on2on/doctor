<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Patients\PatientEnrollmentOptions;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\PostExpirationPolicy;
use ClinicCore\Infrastructure\Repository\PatientEnrollmentRepository;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_UnitTestCase;

/**
 * Server-side destination discovery for the Classic first-enrollment UI.
 *
 * The UI must never receive a Clinic the session is not entitled to, must never
 * infer a default Clinic, and must not learn *why* a destination is unavailable.
 */
final class PatientEnrollmentOptionsTest extends WP_UnitTestCase
{
    use SignedExpirationFixture;

    private int $org;
    private int $destination;
    private int $already_enrolled;
    private int $legacy_linked;
    private int $unrelated_clinic;
    private int $identity;
    private int $user;
    private int $t0;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::replaceExplicitScope( ClinicScope::forClinic( 1 ) );

        $db = App::db();
        $stamp = $db->nowUtcSql();
        $this->user = (int) self::factory()->user->create(
            [ 'role' => RolesAndCapabilities::ROLE_PATIENT, 'user_login' => 'enrollment_options' ]
        );
        $this->t0 = strtotime( '-3 months' );
        $this->now = $this->t0 + DAY_IN_SECONDS;
        $this->org = (int) $db->fetchValue( 'SELECT organization_id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d', [ 1 ] );

        $db->insert( 'cpms_clinics', [
            'organization_id' => $this->org,
            'name' => 'Destination A',
            'slug' => 'enrollment-destination-a',
            'timezone' => 'UTC',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] );
        $this->destination = $db->wpdb_last_insert_id();
        $db->insert( 'cpms_locations', [
            'clinic_id' => $this->destination,
            'name' => 'Destination A location',
            'slug' => 'enrollment-destination-a-location',
            'timezone' => 'UTC',
            'is_primary' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] );

        $db->insert( 'cpms_clinics', [
            'organization_id' => $this->org,
            'name' => 'Already Enrolled',
            'slug' => 'enrollment-already',
            'timezone' => 'UTC',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] );
        $this->already_enrolled = $db->wpdb_last_insert_id();

        $db->insert( 'cpms_clinics', [
            'organization_id' => $this->org,
            'name' => 'Legacy Linked Clinic',
            'slug' => 'enrollment-legacy-linked',
            'timezone' => 'UTC',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] );
        $this->legacy_linked = $db->wpdb_last_insert_id();
        $db->insert( 'cpms_patients', [
            'clinic_id' => $this->legacy_linked,
            'identity_id' => null,
            'mrn' => 'ENROLL-OPTIONS-LEGACY',
            'first_name' => 'Legacy',
            'last_name' => 'Local',
            'mobile' => '09129990003',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] );
        $legacy_patient = $db->wpdb_last_insert_id();
        $db->insert( 'cpms_patient_user_links', [
            'clinic_id' => $this->legacy_linked,
            'patient_id' => $legacy_patient,
            'wp_user_id' => $this->user,
            'mobile_at_link' => '09129990003',
            'linked_at' => $stamp,
        ] );

        $db->insert( 'cpms_organizations', [
            'name' => 'Unrelated Organization',
            'slug' => 'enrollment-unrelated-org',
            'status' => 'active',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] );
        $unrelated_org = $db->wpdb_last_insert_id();
        $db->insert( 'cpms_clinics', [
            'organization_id' => $unrelated_org,
            'name' => 'Foreign Clinic Secret',
            'slug' => 'enrollment-foreign-clinic',
            'timezone' => 'UTC',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] );
        $this->unrelated_clinic = $db->wpdb_last_insert_id();

        $this->identity = App::patient_identity_service()->create_identity( $this->org, '09127770001' );
        App::patient_identity_service()->link_user( $this->org, $this->identity, $this->user, '09127770001' );

        $db->insert( 'cpms_patients', [
            'clinic_id' => 1,
            'identity_id' => $this->identity,
            'mrn' => 'ENROLL-OPTIONS-SOURCE',
            'first_name' => 'SourceOnlyFirstName',
            'last_name' => 'SourceOnlyLastName',
            'mobile' => '09127770001',
            'medical_history' => 'SOURCE-ONLY-PRIVATE-HISTORY',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] );
        $source = $db->wpdb_last_insert_id();
        $db->insert( 'cpms_patients', [
            'clinic_id' => $this->already_enrolled,
            'identity_id' => $this->identity,
            'mrn' => 'ENROLL-OPTIONS-ALREADY',
            'first_name' => 'Local',
            'last_name' => 'Already',
            'mobile' => '09127770001',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] );
        $db->insert( 'cpms_clinicians', [
            'clinic_id' => 1,
            'full_name' => 'Source clinician',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] );
        $clinician = $db->wpdb_last_insert_id();
        $location = (int) $db->fetchValue( 'SELECT id FROM ' . $db->table( 'cpms_locations' ) . ' WHERE clinic_id = %d AND is_primary = 1', [ 1 ] );
        $db->insert( 'cpms_visits', [
            'clinic_id' => 1,
            'location_id' => $location,
            'patient_id' => $source,
            'clinician_id' => $clinician,
            'status' => 'consultation_completed',
            'visit_date' => gmdate( 'Y-m-d', $this->t0 - DAY_IN_SECONDS ),
            'check_in_at' => gmdate( 'Y-m-d H:i:s', $this->t0 - DAY_IN_SECONDS ),
            'consultation_completed_at' => gmdate( 'Y-m-d H:i:s', $this->t0 - 1 ),
            'active' => 0,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] );

        remove_all_filters( 'cpms_license_dev_mode' );
        $this->installSignedExpiration( $this->t0 );
        wp_set_current_user( $this->user );
    }

    protected function tearDown(): void
    {
        remove_all_filters( 'cpms_license_public_key' );
        remove_all_filters( 'cpms_license_dev_mode' );
        wp_set_current_user( 0 );
        App::resetScope();
        parent::tearDown();
    }

    private function options(): PatientEnrollmentOptions
    {
        return new PatientEnrollmentOptions(
            new PatientEnrollmentRepository( App::db() ),
            App::licenseService(),
            fn (): int => $this->now
        );
    }

    /** @return array<string, mixed> */
    private function for_user( int $user = 0 ): array
    {
        return $this->options()->for_user( $user > 0 ? $user : $this->user );
    }

    /**
     * Clinic lifecycle at the published-options boundary: a suspended Clinic must disappear
     * from PatientEnrollmentOptions::for_user() and return after reactivation, while the
     * verified-identity requirement, license eligibility and cross-Organization privacy stay
     * exactly as they were. Uses the real verified identity fixture from setUp(), so this is
     * the endpoint-facing projection, not only the repository query.
     */
    public function testSuspendedClinicIsNotPublishedAsDestinationAndReturnsAfterReactivation(): void
    {
        $db = App::db();

        $before = $this->for_user();
        $this->assertSame( PatientEnrollmentOptions::STATE_ELIGIBLE, $before['state'], 'control: the verified identity is eligible before suspension' );
        $names_before = array_column( $before['clinics'], 'name' );
        $this->assertContains( 'Destination A', $names_before, 'control: an active destination Clinic is published' );
        $this->assertNotContains( 'Foreign Clinic Secret', $names_before, 'cross-Organization privacy is unchanged by this test' );

        $db->query(
            'UPDATE ' . $db->table( 'cpms_clinics' ) . ' SET status = %s WHERE id = %d',
            [ 'suspended', (int) $this->destination ]
        );

        $during = $this->for_user();
        $names_during = array_column( $during['clinics'] ?? [], 'name' );
        $this->assertNotContains( 'Destination A', $names_during, 'a suspended Clinic must not be offered as an enrollment destination' );
        $this->assertNotContains( 'Foreign Clinic Secret', $names_during, 'a suspended sibling must never widen publication to another Organization' );
        $this->assertSame( [], $during['clinics'] ?? [], 'with its only destination suspended, no Clinic is published (no fallback, no existence oracle)' );

        $db->query(
            'UPDATE ' . $db->table( 'cpms_clinics' ) . ' SET status = %s WHERE id = %d',
            [ 'active', (int) $this->destination ]
        );

        $after = $this->for_user();
        $this->assertSame( PatientEnrollmentOptions::STATE_ELIGIBLE, $after['state'], 'eligibility is restored with the Clinic, not by weakening a check' );
        $this->assertContains( 'Destination A', array_column( $after['clinics'], 'name' ), 'the destination must return after reactivation' );
    }

    public function testOnlyAuthorizedUnenrolledDestinationClinicsArePublished(): void
    {
        $result = $this->for_user();

        $this->assertSame( PatientEnrollmentOptions::STATE_ELIGIBLE, $result['state'] );
        $this->assertSame( [ 'id' => $this->destination, 'name' => 'Destination A' ], $result['clinics'][0] ?? null );
        $names = array_column( $result['clinics'], 'name' );
        $this->assertContains( 'Destination A', $names );
        $this->assertNotContains( 'Already Enrolled', $names, 'An identity with a local record must not be offered a duplicate enrollment.' );
        $this->assertNotContains( 'Foreign Clinic Secret', $names, 'A Clinic in another Organization is never exposed.' );
        $this->assertNotContains( 'Legacy Linked Clinic', $names, 'A Clinic where the user already has an active local link must not be offered as a second record.' );
        $this->assertNotContains( 'First Clinic', $names, 'The source Clinic already has a local record for this identity.' );
        $this->assertCount( 1, $result['clinics'] );
    }

    public function testProjectionContainsNoIdentityPatientEncounterOrPhiFields(): void
    {
        $encoded = (string) wp_json_encode( $this->for_user() );

        foreach ( [ 'identity_id', 'organization_id', 'patient_id', 'wp_user_id', 'mrn', 'first_name', 'last_name', 'mobile', 'medical_history', 'consultation_completed_at', 'expires_at', 't0' ] as $key ) {
            $this->assertStringNotContainsString( '"' . $key . '"', $encoded, $key . ' must not leave the server.' );
        }
        $this->assertStringNotContainsString( 'SOURCE-ONLY-PRIVATE-HISTORY', $encoded );
        $this->assertStringNotContainsString( 'SourceOnlyFirstName', $encoded );
        $this->assertStringNotContainsString( '09127770001', $encoded );
    }

    public function testNoIdentityLinkIsUnverifiedAndPublishesNoClinic(): void
    {
        $plain = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT, 'user_login' => 'enrollment_unverified' ] );
        $result = $this->for_user( $plain );

        $this->assertSame( PatientEnrollmentOptions::STATE_UNVERIFIED, $result['state'] );
        $this->assertSame( [], $result['clinics'] );
    }

    public function testAmbiguousOrganizationIdentityFailsClosed(): void
    {
        $second = App::patient_identity_service()->create_identity( $this->org, '09127770001' );
        App::patient_identity_service()->link_user( $this->org, $second, $this->user, '09127770001' );

        $result = $this->for_user();

        $this->assertSame( PatientEnrollmentOptions::STATE_UNVERIFIED, $result['state'] );
        $this->assertSame( [], $result['clinics'] );
    }

    public function testSuspendedOrganizationPublishesNoClinic(): void
    {
        App::db()->update( 'cpms_organizations', [ 'status' => 'suspended' ], [ 'id' => $this->org ] );

        $result = $this->for_user();

        $this->assertSame( PatientEnrollmentOptions::STATE_UNAVAILABLE, $result['state'] );
        $this->assertSame( [], $result['clinics'] );
    }

    public function testAlreadyEnrolledEverywhereIsNoDestination(): void
    {
        $db = App::db();
        $stamp = $db->nowUtcSql();
        $db->insert( 'cpms_organizations', [ 'name' => 'Sole Clinic Org', 'slug' => 'enrollment-sole-org', 'status' => 'active', 'created_at' => $stamp, 'updated_at' => $stamp ] );
        $org = $db->wpdb_last_insert_id();
        $db->insert( 'cpms_clinics', [ 'organization_id' => $org, 'name' => 'Sole Clinic', 'slug' => 'enrollment-sole-clinic', 'timezone' => 'UTC', 'created_at' => $stamp, 'updated_at' => $stamp ] );
        $clinic = $db->wpdb_last_insert_id();
        $identity = App::patient_identity_service()->create_identity( $org, '09128880002' );
        $user = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_PATIENT, 'user_login' => 'enrollment_no_destination' ] );
        App::patient_identity_service()->link_user( $org, $identity, $user, '09128880002' );
        $db->insert( 'cpms_patients', [
            'clinic_id' => $clinic, 'identity_id' => $identity, 'mrn' => 'SOLE-ONLY',
            'first_name' => 'Sole', 'last_name' => 'Patient', 'mobile' => '09128880002',
            'created_at' => $stamp, 'updated_at' => $stamp,
        ] );

        $result = $this->for_user( $user );

        $this->assertSame( PatientEnrollmentOptions::STATE_NO_DESTINATION, $result['state'] );
        $this->assertSame( [], $result['clinics'] );
    }

    public function testPostExpirationRequiresStrictPreT0ReturningEvidence(): void
    {
        App::db()->delete( 'cpms_visits', [ 'patient_id' => (int) App::db()->fetchValue( 'SELECT id FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE clinic_id = %d AND identity_id = %d', [ 1, $this->identity ] ) ] );

        $result = $this->for_user();

        $this->assertSame( PatientEnrollmentOptions::STATE_UNAVAILABLE, $result['state'] );
        $this->assertSame( [], $result['clinics'] );
    }

    public function testPatientSelfDoesNotInheritTheOneMonthReceptionWindow(): void
    {
        App::db()->delete( 'cpms_visits', [ 'patient_id' => (int) App::db()->fetchValue( 'SELECT id FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE clinic_id = %d AND identity_id = %d', [ 1, $this->identity ] ) ] );
        $this->now = PostExpirationPolicy::deadline( $this->t0, 1 ) - 1;

        $result = $this->for_user();

        $this->assertSame( PatientEnrollmentOptions::STATE_UNAVAILABLE, $result['state'], 'New/unverified patients are reception-only after T0, not online-self-enrollable.' );
        $this->assertSame( [], $result['clinics'] );
    }

    public function testSixMonthWindowIsHalfOpenAndStrictlyServerComputed(): void
    {
        $deadline = PostExpirationPolicy::deadline( $this->t0, 6 );

        $this->now = $deadline - 1;
        $this->assertSame( PatientEnrollmentOptions::STATE_ELIGIBLE, $this->for_user()['state'] );

        $this->now = $deadline;
        $result = $this->for_user();
        $this->assertSame( PatientEnrollmentOptions::STATE_UNAVAILABLE, $result['state'] );
        $this->assertSame( [], $result['clinics'] );
    }

    public function testActiveLicenseDoesNotRequireReturningEvidenceButStillNeedsVerifiedIdentity(): void
    {
        $this->installSignedExpiration( time() + 365 * DAY_IN_SECONDS );
        $this->now = time();

        $result = $this->for_user();

        $this->assertSame( PatientEnrollmentOptions::STATE_ELIGIBLE, $result['state'] );
        $this->assertNotEmpty( $result['clinics'] );
    }

    public function testPreviewNeverCreatesAPatientAppointmentVisitOrLink(): void
    {
        $db = App::db();
        $before = [];
        foreach ( [ 'cpms_patients', 'cpms_patient_user_links', 'cpms_patient_identity_links', 'cpms_appointments', 'cpms_visits' ] as $table ) {
            $before[ $table ] = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( $table ) );
        }

        $this->for_user();

        foreach ( $before as $table => $count ) {
            $this->assertSame( $count, (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( $table ) ), $table . ' must be unchanged by a read-only preview.' );
        }
    }
}
