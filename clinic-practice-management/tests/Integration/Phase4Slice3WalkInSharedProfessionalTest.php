<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Phase 4 — Slice 3: Walk-in Visit for a shared professional.
 *
 * Product path: POST /clinic/v1/visits/walk-in.
 *
 * The professional has one clinician identity whose compatibility/home Clinic is
 * A and an ACTIVE durable participation in B. An authorized secretary operating
 * under the trusted REST Clinic-B context must be able to create a Clinic-B
 * walk-in for a Clinic-B patient without re-homing or duplicating that identity.
 *
 * Expected RED on defective main: VisitService::walkIn() obtains `$clinicId`
 * from clinicians.clinic_id (A), then compares it with the already-established
 * trusted REST scope (B), producing 404 CLINIC_NOT_FOUND before Visit creation.
 */
final class Phase4Slice3WalkInSharedProfessionalTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';
    private const LOCATION_TZ = 'Asia/Tehran';

    private int $organizationId = 0;

    /** @var array<string, int> */
    private array $clinics = [];

    /** @var array<int, int> Clinic id => primary Location id */
    private array $locations = [];

    private ?int $capturedRestClinicId = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        SystemClinicResolver::flush();
        wp_set_current_user(0);

        $this->organizationId = $this->createOrganization();
        $this->clinics['A'] = $this->createClinic('A');
        $this->clinics['B'] = $this->createClinic('B');
        $this->locations[$this->clinics['A']] = $this->createLocation($this->clinics['A'], 'A');
        $this->locations[$this->clinics['B']] = $this->createLocation($this->clinics['B'], 'B');
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        $this->purgeRows();
        parent::tearDown();
    }

    /**
     * Core acceptance contract and RED witness.
     *
     * This test deliberately asserts every material precondition before the final
     * behavior assertion so a fixture/bootstrap/authentication failure cannot be
     * mistaken for the required product RED.
     */
    public function testAuthorizedStaffCanCreateClinicBWalkInForSharedProfessional(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];

        $professionalUserId = $this->makeUser('p4s3_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($clinicA, $professionalUserId);
        $professionalMembershipA = cpms_test_seed_membership(
            $professionalUserId,
            $clinicA,
            RolesAndCapabilities::ROLE_DOCTOR
        );
        $professionalMembershipB = cpms_test_seed_membership(
            $professionalUserId,
            $clinicB,
            RolesAndCapabilities::ROLE_DOCTOR
        );

        $secretaryUserId = $this->makeUser('p4s3_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $secretaryMembershipB = cpms_test_seed_membership(
            $secretaryUserId,
            $clinicB,
            RolesAndCapabilities::ROLE_SECRETARY
        );
        $patientB = $this->insertPatient($clinicB, 'positive');

        // Clinic-sensitive side-effect witness: A auto-enqueues; B does not.
        // A Clinic mistakenly retained as the operation tenant would therefore
        // produce `waiting`; the trusted B behavior must remain `checked_in`.
        (new Settings(App::db(), $clinicA, App::audit()))->set('queue.auto_enqueue', true);
        (new Settings(App::db(), $clinicB, App::audit()))->set('queue.auto_enqueue', false);
        Settings::flushCache();
        App::settingsFactory()->reset();

        // Material fixture assertions: dynamic Organization/Clinics/Locations.
        self::assertGreaterThan(0, $this->organizationId, 'precondition: Organization inserted');
        self::assertGreaterThan(1, $clinicA, 'precondition: Clinic A is dynamic, never seeded Clinic 1');
        self::assertGreaterThan(1, $clinicB, 'precondition: Clinic B is dynamic, never seeded Clinic 1');
        self::assertNotSame($clinicA, $clinicB, 'precondition: A and B are distinct Clinics');
        self::assertGreaterThan(0, $this->locations[$clinicA], 'precondition: Clinic A primary Location inserted');
        self::assertGreaterThan(0, $this->locations[$clinicB], 'precondition: Clinic B primary Location inserted');

        // One WP identity => exactly one professional/clinician identity; home=A.
        self::assertGreaterThan(0, $professionalUserId, 'precondition: professional WP user created');
        self::assertGreaterThan(0, $clinicianId, 'precondition: clinician row inserted');
        self::assertSame(
            1,
            $this->countClinicianRowsForUser($professionalUserId),
            'precondition: exactly one clinician identity exists for the professional WP user'
        );
        self::assertSame(
            $clinicA,
            $this->clinicianHomeClinic($clinicianId),
            'precondition: clinicians.clinic_id remains compatibility/home Clinic A'
        );
        self::assertGreaterThan(0, $professionalMembershipA, 'precondition: professional membership A inserted');
        self::assertGreaterThan(0, $professionalMembershipB, 'precondition: professional membership B inserted');
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicB, $professionalUserId),
            'precondition: professional has ACTIVE durable participation in Clinic B'
        );
        self::assertTrue(
            (new MembershipRepository(App::db()))->clinician_participates_in($clinicianId, $clinicB),
            'precondition: production participation primitive confirms the shared professional in B'
        );

        // Patient and actor are durably Clinic-B scoped; Phase 3 remains intact.
        self::assertGreaterThan(0, $patientB, 'precondition: Clinic-B patient record inserted');
        self::assertSame($clinicB, $this->patientClinic($patientB), 'precondition: patient clinical record belongs to B');
        self::assertGreaterThan(0, $secretaryMembershipB, 'precondition: secretary membership B inserted');
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicB, $secretaryUserId),
            'precondition: secretary has ACTIVE durable membership in B'
        );
        self::assertTrue(
            user_can($secretaryUserId, RolesAndCapabilities::QUEUE_CHECKIN),
            'precondition: coarse QUEUE_CHECKIN capability exists (defense in depth)'
        );
        self::assertTrue(
            $this->authz()->can($secretaryUserId, $clinicB, RolesAndCapabilities::QUEUE_CHECKIN),
            'precondition: existing Phase 3 scoped QUEUE_CHECKIN authorization succeeds in B'
        );
        self::assertFalse(
            (bool) (new Settings(App::db(), $clinicB))->get('queue.auto_enqueue', true),
            'precondition: Clinic B auto-enqueue is disabled for the side-effect witness'
        );
        self::assertTrue(
            (bool) (new Settings(App::db(), $clinicA))->get('queue.auto_enqueue', false),
            'precondition: Clinic A auto-enqueue differs from B'
        );

        $visitsBefore = $this->countVisitsForPatient($patientB);
        self::assertSame(0, $visitsBefore, 'precondition: no Visit exists for the Clinic-B patient');

        $response = $this->dispatchWalkIn($patientB, $clinicianId, $clinicB, $secretaryUserId);

        self::assertSame(
            $clinicB,
            $this->capturedRestClinicId,
            'precondition: real REST boundary established trusted Clinic B before product callback'
        );
        $this->assertDefectiveMainHomeClinicRejection($response, $visitsBefore, $patientB);

        self::assertSame(
            200,
            $response->get_status(),
            'CONTRACT: shared professional with ACTIVE participation in trusted Clinic B must receive a B walk-in. '
                . 'Defective main returns ' . $response->get_status() . ' ' . $this->errorCode($response)
                . ' because clinicians.clinic_id=A is incorrectly treated as the WalkIn operation tenant.'
        );

        $visitId = (int) ($response->get_data()['data']['id'] ?? 0);
        self::assertGreaterThan(0, $visitId, 'contract: Visit id returned by the real REST product path');
        $visit = $this->visitRow($visitId);
        self::assertNotNull($visit, 'contract: Visit persisted');
        self::assertSame($clinicB, (int) $visit['clinic_id'], 'persisted Visit tenant is trusted Clinic B');
        self::assertSame($clinicianId, (int) $visit['clinician_id'], 'persisted Visit references shared professional');
        self::assertSame($patientB, (int) $visit['patient_id'], 'persisted Visit references Clinic-B patient');
        self::assertSame('walk_in', (string) $visit['source'], 'persisted source is walk_in');
        self::assertSame('checked_in', (string) $visit['status'], 'Clinic-B auto-enqueue=false controls side effect');
        self::assertSame(
            $clinicB,
            $this->locationClinic((int) $visit['location_id']),
            'Visit Location side effect is resolved inside the same trusted Clinic B'
        );
        self::assertSame(1, $this->countHistoryRows($visitId), 'initial checked-in history is linked exactly once');

        $audit = $this->walkInAudit($visitId, $secretaryUserId);
        self::assertNotNull($audit, 'VISIT_WALK_IN audit is queryable for the persisted Visit');
        self::assertSame($clinicB, (int) $audit['clinic_id'], 'VISIT_WALK_IN audit attribution is Clinic B');
        self::assertSame($patientB, (int) $audit['patient_id'], 'audit retains the Clinic-B patient linkage');

        self::assertSame(
            1,
            $this->countClinicianRowsForUser($professionalUserId),
            'contract: WalkIn creates no duplicate clinician identity'
        );
        self::assertSame(
            $clinicA,
            $this->clinicianHomeClinic($clinicianId),
            'contract: compatibility/home Clinic A remains unchanged'
        );
    }

    public function testProfessionalWithoutActiveParticipationInBFailsClosedWithoutVisitMutation(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $professionalUserId = $this->makeUser('p4s3_missing_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($clinicA, $professionalUserId);
        cpms_test_seed_membership($professionalUserId, $clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $secretaryUserId = $this->makeAuthorizedSecretary($clinicB, 'p4s3_missing_sec');
        $patientB = $this->insertPatient($clinicB, 'missing-membership');

        self::assertNull(
            App::membership_service()->membership_for($clinicB, $professionalUserId),
            'precondition: professional has no durable participation row in B'
        );
        self::assertFalse(
            (new MembershipRepository(App::db()))->clinician_participates_in($clinicianId, $clinicB),
            'precondition: production participation primitive rejects missing participation in B'
        );
        self::assertTrue(
            $this->authz()->can($secretaryUserId, $clinicB, RolesAndCapabilities::QUEUE_CHECKIN),
            'precondition: actor authorization succeeds; denial is professional participation only'
        );

        $before = $this->countVisitsForPatient($patientB);
        $response = $this->dispatchWalkIn($patientB, $clinicianId, $clinicB, $secretaryUserId);

        self::assertSame($clinicB, $this->capturedRestClinicId, 'trusted REST Clinic is B');
        self::assertSame(404, $response->get_status(), 'missing ACTIVE participation in B fails closed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response), 'existing 404 parity is preserved');
        self::assertSame($before, $this->countVisitsForPatient($patientB), 'denial causes zero Visit mutation');
        self::assertSame(1, $this->countClinicianRowsForUser($professionalUserId), 'no fallback identity is created');
    }

    public function testSuspendedProfessionalParticipationInBFailsClosedWithoutVisitMutation(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $professionalUserId = $this->makeUser('p4s3_suspended_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($clinicA, $professionalUserId);
        cpms_test_seed_membership($professionalUserId, $clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $membershipB = cpms_test_seed_membership(
            $professionalUserId,
            $clinicB,
            RolesAndCapabilities::ROLE_DOCTOR
        );
        App::membership_service()->suspend_membership($membershipB);
        $secretaryUserId = $this->makeAuthorizedSecretary($clinicB, 'p4s3_suspended_sec');
        $patientB = $this->insertPatient($clinicB, 'suspended-membership');

        self::assertNotNull(
            App::membership_service()->membership_for($clinicB, $professionalUserId),
            'precondition: suspended durable membership row exists in B'
        );
        self::assertNull(
            App::membership_service()->active_membership_for($clinicB, $professionalUserId),
            'precondition: suspended membership is not ACTIVE participation'
        );
        self::assertFalse(
            (new MembershipRepository(App::db()))->clinician_participates_in($clinicianId, $clinicB),
            'precondition: production participation primitive rejects suspended participation'
        );
        self::assertTrue(
            $this->authz()->can($secretaryUserId, $clinicB, RolesAndCapabilities::QUEUE_CHECKIN),
            'precondition: actor remains authorized in B'
        );

        $before = $this->countVisitsForPatient($patientB);
        $response = $this->dispatchWalkIn($patientB, $clinicianId, $clinicB, $secretaryUserId);

        self::assertSame($clinicB, $this->capturedRestClinicId, 'trusted REST Clinic is B');
        self::assertSame(404, $response->get_status(), 'suspended participation in B fails closed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response), 'suspension uses the same 404 parity');
        self::assertSame($before, $this->countVisitsForPatient($patientB), 'suspension denial causes zero Visit mutation');
        self::assertSame(1, $this->countClinicianRowsForUser($professionalUserId), 'no fallback identity is created');
    }

    public function testClinicAPatientUnderTrustedClinicBIsRejectedBeforeVisitCreation(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $professionalUserId = $this->makeUser('p4s3_patient_a_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($clinicA, $professionalUserId);
        cpms_test_seed_membership($professionalUserId, $clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($professionalUserId, $clinicB, RolesAndCapabilities::ROLE_DOCTOR);
        $secretaryUserId = $this->makeAuthorizedSecretary($clinicB, 'p4s3_patient_a_sec');
        $patientA = $this->insertPatient($clinicA, 'cross-clinic-patient');

        self::assertTrue(
            (new MembershipRepository(App::db()))->clinician_participates_in($clinicianId, $clinicB),
            'precondition: professional legitimately participates in B'
        );
        self::assertSame($clinicA, $this->patientClinic($patientA), 'precondition: patient clinical record belongs to A');
        self::assertTrue(
            $this->authz()->can($secretaryUserId, $clinicB, RolesAndCapabilities::QUEUE_CHECKIN),
            'precondition: actor is authorized in trusted B'
        );

        $before = $this->countVisitsForPatient($patientA);
        $response = $this->dispatchWalkIn($patientA, $clinicianId, $clinicB, $secretaryUserId);

        self::assertSame($clinicB, $this->capturedRestClinicId, 'trusted REST Clinic remains B');
        self::assertSame(422, $response->get_status(), 'Clinic-A patient under trusted B keeps validation contract');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($response), 'stable validation code is preserved');
        self::assertSame($before, $this->countVisitsForPatient($patientA), 'patient ownership failure precedes Visit creation');
        self::assertSame(1, $this->countClinicianRowsForUser($professionalUserId), 'professional identity remains singular');
    }

    public function testPhase3DeniesUnauthorizedActorInBWithoutVisitMutation(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $professionalUserId = $this->makeUser('p4s3_denied_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($clinicA, $professionalUserId);
        cpms_test_seed_membership($professionalUserId, $clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($professionalUserId, $clinicB, RolesAndCapabilities::ROLE_DOCTOR);
        $patientB = $this->insertPatient($clinicB, 'unauthorized-actor');

        $deniedUserId = $this->makeUser('p4s3_denied_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $deniedMembership = cpms_test_seed_membership(
            $deniedUserId,
            $clinicB,
            RolesAndCapabilities::ROLE_SECRETARY
        );
        App::membership_service()->set_capability(
            $deniedMembership,
            RolesAndCapabilities::QUEUE_CHECKIN,
            'deny'
        );

        self::assertTrue(
            user_can($deniedUserId, RolesAndCapabilities::QUEUE_CHECKIN),
            'precondition: actor still has coarse global capability'
        );
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicB, $deniedUserId),
            'precondition: actor has ACTIVE B membership so trusted context can be established'
        );
        self::assertFalse(
            $this->authz()->can($deniedUserId, $clinicB, RolesAndCapabilities::QUEUE_CHECKIN),
            'precondition: existing Phase 3 scoped QUEUE_CHECKIN decision is deny'
        );

        $before = $this->countVisitsForPatient($patientB);
        $response = $this->dispatchWalkIn($patientB, $clinicianId, $clinicB, $deniedUserId);

        self::assertSame($clinicB, $this->capturedRestClinicId, 'trusted REST Clinic B is established before guard');
        self::assertSame(403, $response->get_status(), 'Phase 3 authorization denial remains authoritative');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($response), 'existing Phase 3 denial code is preserved');
        self::assertSame($before, $this->countVisitsForPatient($patientB), 'unauthorized request causes zero Visit mutation');
        self::assertSame(1, $this->countClinicianRowsForUser($professionalUserId), 'no duplicate professional identity');
    }

    public function testHomeClinicAWalkInBehaviorRemainsValid(): void
    {
        $clinicA = $this->clinics['A'];
        $professionalUserId = $this->makeUser('p4s3_home_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($clinicA, $professionalUserId);
        $secretaryUserId = $this->makeAuthorizedSecretary($clinicA, 'p4s3_home_sec');
        $patientA = $this->insertPatient($clinicA, 'home-control');
        (new Settings(App::db(), $clinicA))->set('queue.auto_enqueue', false);
        Settings::flushCache();
        App::settingsFactory()->reset();

        self::assertSame($clinicA, $this->clinicianHomeClinic($clinicianId), 'precondition: home Clinic is A');
        self::assertTrue(
            (new MembershipRepository(App::db()))->clinician_participates_in($clinicianId, $clinicA),
            'precondition: established home-Clinic compatibility path remains valid'
        );
        self::assertTrue(
            $this->authz()->can($secretaryUserId, $clinicA, RolesAndCapabilities::QUEUE_CHECKIN),
            'precondition: secretary is authorized in A'
        );

        $response = $this->dispatchWalkIn($patientA, $clinicianId, $clinicA, $secretaryUserId);

        self::assertSame($clinicA, $this->capturedRestClinicId, 'trusted REST Clinic is A');
        self::assertSame(200, $response->get_status(), 'existing home Clinic A WalkIn remains valid');
        $visitId = (int) ($response->get_data()['data']['id'] ?? 0);
        $visit = $this->visitRow($visitId);
        self::assertNotNull($visit, 'home control Visit persisted');
        self::assertSame($clinicA, (int) $visit['clinic_id'], 'home control Visit remains Clinic A owned');
        self::assertSame($clinicianId, (int) $visit['clinician_id'], 'home control uses same clinician identity');
        self::assertSame(1, $this->countClinicianRowsForUser($professionalUserId), 'home path creates no duplicate identity');
    }

    /**
     * On defective main, all setup/auth/scope assertions have already succeeded;
     * the only accepted pre-patch failure is the service ownership envelope and
     * zero Visit mutation. This diagnostic does not weaken the final 200 contract.
     */
    private function assertDefectiveMainHomeClinicRejection(
        WP_REST_Response $response,
        int $visitsBefore,
        int $patientId
    ): void {
        if ($response->get_status() === 200) {
            return;
        }

        self::assertSame(
            'CLINIC_NOT_FOUND',
            $this->errorCode($response),
            'VALID RED diagnostic: request reached WalkIn and failed with existing clinician 404 parity'
        );
        self::assertSame(404, $response->get_status(), 'VALID RED diagnostic: home-Clinic mismatch is a 404');
        self::assertSame(
            $visitsBefore,
            $this->countVisitsForPatient($patientId),
            'VALID RED diagnostic: defective home-Clinic rejection caused zero Visit mutation'
        );
    }

    private function dispatchWalkIn(int $patientId, int $clinicianId, int $clinicId, int $userId): WP_REST_Response
    {
        wp_set_current_user($userId);
        self::assertSame($userId, get_current_user_id(), 'precondition: REST actor authentication established');

        $nonce = wp_create_nonce('wp_rest');
        self::assertSame(1, wp_verify_nonce($nonce, 'wp_rest'), 'precondition: REST nonce authentication succeeds');

        $request = new WP_REST_Request('POST', self::NS . '/visits/walk-in');
        $request->set_param('patient_id', $patientId);
        $request->set_param('clinician_id', $clinicianId);
        $request->set_header('X-WP-Nonce', $nonce);
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);

        $this->capturedRestClinicId = null;
        $probe = function ($response, $handler, $incomingRequest) use ($request) {
            if ($incomingRequest instanceof WP_REST_Request
                && $incomingRequest->get_route() === $request->get_route()) {
                $scope = ScopeContext::tryGet();
                $this->capturedRestClinicId = $scope?->clinicId;
            }

            return $response;
        };
        add_filter('rest_request_before_callbacks', $probe, 11, 3);
        try {
            return rest_do_request($request);
        } finally {
            remove_filter('rest_request_before_callbacks', $probe, 11);
        }
    }

    private function authz(): AuthorizationService
    {
        return new AuthorizationService(new MembershipRepository(App::db()));
    }

    private function createOrganization(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'P4S3 Organization ' . $unique,
            'p4s3-org-' . $unique,
            'active',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: Organization insertion succeeded (' . $wpdb->last_error . ')');
        self::assertSame(
            'active',
            (string) $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = %d',
                $id
            )),
            'fixture: Organization is active'
        );

        return $id;
    }

    private function createClinic(string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics
                 (organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->organizationId,
            'P4S3 Clinic ' . $tag . ' ' . $unique,
            'p4s3-clinic-' . strtolower($tag) . '-' . $unique,
            self::LOCATION_TZ,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'fixture: dynamic Clinic ' . $tag . ' insertion succeeded (' . $wpdb->last_error . ')');
        self::assertSame(
            $this->organizationId,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d',
                $id
            )),
            'fixture: Clinic ' . $tag . ' belongs to explicit Organization'
        );

        return $id;
    }

    private function createLocation(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                 (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
             VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinicId,
            'P4S3 Location ' . $tag . ' ' . $unique,
            'p4s3-location-' . strtolower($tag) . '-' . $unique,
            self::LOCATION_TZ,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: primary Location ' . $tag . ' insertion succeeded (' . $wpdb->last_error . ')');

        return $id;
    }

    private function makeUser(string $prefix, string $role): int
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $userId = (int) wp_create_user($unique, wp_generate_password(24), $unique . '@p4s3.test');
        self::assertGreaterThan(0, $userId, 'fixture: WP user ' . $prefix . ' created');
        $user = get_userdata($userId);
        self::assertNotFalse($user, 'fixture: WP user is queryable');
        $user->set_role($role);
        self::assertContains($role, (array) get_userdata($userId)->roles, 'fixture: WP role assigned');

        return $userId;
    }

    private function makeAuthorizedSecretary(int $clinicId, string $prefix): int
    {
        $userId = $this->makeUser($prefix, RolesAndCapabilities::ROLE_SECRETARY);
        $membershipId = cpms_test_seed_membership(
            $userId,
            $clinicId,
            RolesAndCapabilities::ROLE_SECRETARY
        );
        self::assertGreaterThan(0, $membershipId, 'fixture: secretary membership insertion succeeded');
        self::assertTrue(
            $this->authz()->can($userId, $clinicId, RolesAndCapabilities::QUEUE_CHECKIN),
            'fixture: secretary has existing Phase 3 QUEUE_CHECKIN authorization'
        );

        return $userId;
    }

    private function insertClinician(int $homeClinicId, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                 (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
             VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $homeClinicId,
            'Dr P4S3 Shared Professional',
            $wpUserId,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: single clinician row insertion succeeded (' . $wpdb->last_error . ')');

        return $id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinicId,
            'MR-P4S3-' . strtoupper($tag) . '-' . $unique,
            'P4S3',
            ucfirst($tag),
            '09' . (string) random_int(100000000, 999999999),
            'active',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: patient ' . $tag . ' insertion succeeded (' . $wpdb->last_error . ')');

        return $id;
    }

    private function countClinicianRowsForUser(int $wpUserId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d',
            [$wpUserId]
        );
    }

    private function clinicianHomeClinic(int $clinicianId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d',
            [$clinicianId]
        );
    }

    private function patientClinic(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$patientId]
        );
    }

    private function countVisitsForPatient(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d',
            [$patientId]
        );
    }

    /** @return array<string, mixed>|null */
    private function visitRow(int $visitId): ?array
    {
        return App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visitId]
        );
    }

    private function locationClinic(int $locationId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d',
            [$locationId]
        );
    }

    private function countHistoryRows(int $visitId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d',
            [$visitId]
        );
    }

    /** @return array<string, mixed>|null */
    private function walkInAudit(int $visitId, int $actorUserId): ?array
    {
        return App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_type = %s AND resource_id = %d AND actor_wp_user_id = %d' .
            ' ORDER BY id DESC LIMIT 1',
            ['VISIT_WALK_IN', 'visit', $visitId, $actorUserId]
        );
    }

    private function errorCode(WP_REST_Response $response): string
    {
        $body = $response->get_data();
        if ($body instanceof WP_Error) {
            return (string) $body->get_error_code();
        }

        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
    }

    private function purgeRows(): void
    {
        global $wpdb;
        $organizationId = $this->organizationId;
        if ($organizationId <= 0) {
            return;
        }
        $clinicSubquery = 'SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $organizationId;

        $wpdb->query('DELETE h FROM ' . $wpdb->prefix . 'cpms_visit_status_history h
                      INNER JOIN ' . $wpdb->prefix . 'cpms_visits v ON v.id = h.visit_id
                      WHERE v.clinic_id IN (' . $clinicSubquery . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_audit_logs
                      WHERE clinic_id IN (' . $clinicSubquery . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach (['cpms_visits', 'cpms_settings', 'cpms_patients', 'cpms_clinicians'] as $table) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $table .
                         ' WHERE clinic_id IN (' . $clinicSubquery . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $wpdb->query('DELETE mc FROM ' . $wpdb->prefix . 'cpms_membership_capabilities mc
                      INNER JOIN ' . $wpdb->prefix . 'cpms_clinic_memberships m ON m.id = mc.membership_id
                      WHERE m.clinic_id IN (' . $clinicSubquery . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinic_memberships
                      WHERE clinic_id IN (' . $clinicSubquery . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_locations
                      WHERE clinic_id IN (' . $clinicSubquery . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $organizationId); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = ' . $organizationId); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}
