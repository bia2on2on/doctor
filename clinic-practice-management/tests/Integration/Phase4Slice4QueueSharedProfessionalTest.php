<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Scope\ClinicScope;
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
 * Phase 4 — Slice 4: queue clinician resolution for a shared professional.
 *
 * Product paths (real REST dispatch, no service shortcut):
 *  - GET /clinic/v1/doctor/today  (doctor self-resolution branch)
 *  - GET /clinic/v1/queue         (staff optional clinician filter branch)
 *  - GET /clinic/v1/rt/queue      (real-time feed, same doctor resolution)
 *
 * Fixture shape: one WP professional user, exactly one clinician identity whose
 * compatibility/home Clinic is A, ACTIVE durable participation in A and B, plus
 * a Clinic-A Visit and a Clinic-B Visit for that same identity. The trusted
 * queue tenant is B (established by the existing REST boundary from an ACTIVE
 * B membership) — never derived from the clinician filter.
 *
 * Expected RED on defective main: VisitService::queueScopeClinicianId() resolves
 * the doctor identity with `clinicians.clinic_id = <trusted clinic>` and validates
 * the staff filter with the same predicate. For a home-A professional under
 * trusted B both lookups miss, so the doctor receives 200 with an empty
 * queue/stats (scopeClinicianId = 0) and the staff filter answers
 * 404 CLINIC_NOT_FOUND — while the identity legitimately participates in B.
 *
 * Read-only slice: no Visit/Appointment mutation is exercised or asserted.
 */
final class Phase4Slice4QueueSharedProfessionalTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $organizationId = 0;

    /** @var array<string, int> */
    private array $clinics = [];

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

    // ==================================================================
    // Positive RED 1 — doctor self-resolution under trusted Clinic B
    // ==================================================================

    public function testDoctorSeesOnlyTrustedClinicBQueueForSharedProfessionalIdentity(): void
    {
        $fixture = $this->sharedProfessionalFixture();

        self::assertSame(
            1,
            $this->countClinicianRowsForUser($fixture['professionalUserId']),
            'precondition: exactly one clinician identity exists for the professional WP user'
        );
        self::assertSame(
            $this->clinics['A'],
            $this->clinicianHomeClinic($fixture['clinicianId']),
            'precondition: clinicians.clinic_id remains compatibility/home Clinic A'
        );
        self::assertTrue(
            (new MembershipRepository(App::db()))->clinician_participates_in($fixture['clinicianId'], $this->clinics['B']),
            'precondition: production participation primitive confirms ACTIVE participation in B'
        );
        self::assertTrue(
            user_can($fixture['professionalUserId'], RolesAndCapabilities::QUEUE_READ),
            'precondition: coarse QUEUE_READ capability exists (defense in depth)'
        );
        self::assertTrue(
            $this->authz()->can($fixture['professionalUserId'], $this->clinics['B'], RolesAndCapabilities::QUEUE_READ),
            'precondition: existing Phase 3 scoped QUEUE_READ authorization succeeds in B'
        );

        $response = $this->dispatchRead('GET', self::NS . '/doctor/today', [], $this->clinics['B'], $fixture['professionalUserId']);

        self::assertSame(
            $this->clinics['B'],
            $this->capturedRestClinicId,
            'precondition: real REST boundary established trusted Clinic B before the product callback'
        );
        self::assertSame(
            200,
            $response->get_status(),
            'precondition: authenticated + Phase 3 authorized doctor reaches the queue dashboard'
        );

        $this->assertDefectiveMainEmptyDoctorQueue($response);

        $data = $this->payload($response);
        $queueIds = array_map(static fn (array $v): int => (int) $v['id'], $data['queue']);

        self::assertContains(
            $fixture['visitB']['id'],
            $queueIds,
            'CONTRACT: the shared professional\'s Clinic-B Visit must be visible under trusted Clinic B. '
                . 'Defective main returns an empty queue because doctor resolution incorrectly requires '
                . 'clinicians.clinic_id = B for a home-A identity that actively participates in B.'
        );
        self::assertNotContains($fixture['visitA']['id'], $queueIds, 'Clinic-A Visit must never appear under trusted B');
        self::assertNotContains($fixture['visitB2']['id'], $queueIds, 'another clinician\'s Visit stays outside the doctor scope');
        self::assertCount(1, $queueIds, 'the doctor sees exactly the own-scope queue rows');
        self::assertSame($fixture['clinicianId'], (int) $data['queue'][0]['clinician_id'], 'row belongs to the shared identity');

        self::assertSame(1, $data['stats']['total'], 'stats reflect only the own-scope rows inside trusted B');
        self::assertSame(1, $data['stats']['waiting'], 'waiting count is own-scope only');
        self::assertSame(1, $data['stats']['walk_in_today'], 'walk-in count is own-scope only');
        self::assertSame(0, $data['stats']['appointments_today'], 'no appointment row exists for the own scope');
        self::assertSame(
            $fixture['visitB']['max_history_id'],
            (int) $data['last_event_id'],
            'ETag/last_event_id is narrowed to the own scope inside trusted B'
        );
        self::assertLessThan(
            $fixture['visitB2']['max_history_id'],
            (int) $data['last_event_id'],
            'own-scope last_event_id stays below the whole-Clinic watermark'
        );

        self::assertSame(
            1,
            $this->countClinicianRowsForUser($fixture['professionalUserId']),
            'contract: queue resolution creates no duplicate clinician identity'
        );
        self::assertSame(
            $this->clinics['A'],
            $this->clinicianHomeClinic($fixture['clinicianId']),
            'contract: compatibility/home Clinic A remains untouched'
        );
    }

    // ==================================================================
    // Positive RED 2 — staff optional clinician filter under trusted Clinic B
    // ==================================================================

    public function testStaffClinicianFilterResolvesSharedProfessionalUnderTrustedClinicB(): void
    {
        $fixture = $this->sharedProfessionalFixture();

        self::assertTrue(
            $this->authz()->can($fixture['secretaryUserId'], $this->clinics['B'], RolesAndCapabilities::QUEUE_READ),
            'precondition: existing Phase 3 scoped QUEUE_READ authorization succeeds for the staff actor in B'
        );
        self::assertTrue(
            (new MembershipRepository(App::db()))->clinician_participates_in($fixture['clinicianId'], $this->clinics['B']),
            'precondition: filtered clinician has ACTIVE participation in trusted B'
        );

        $response = $this->dispatchRead(
            'GET',
            self::NS . '/queue',
            ['clinician_id' => $fixture['clinicianId']],
            $this->clinics['B'],
            $fixture['secretaryUserId']
        );

        self::assertSame(
            $this->clinics['B'],
            $this->capturedRestClinicId,
            'precondition: real REST boundary established trusted Clinic B before the product callback'
        );
        $this->assertDefectiveMainClinicianFilterRejection($response);

        self::assertSame(
            200,
            $response->get_status(),
            'CONTRACT: an active professional with ACTIVE participation in trusted B must be a valid filter. '
                . 'Defective main answers ' . $response->get_status() . ' ' . $this->errorCode($response)
                . ' because the filter is validated against clinicians.clinic_id = B.'
        );

        $data = $this->payload($response);
        $queueIds = array_map(static fn (array $v): int => (int) $v['id'], $data['queue']);

        self::assertContains($fixture['visitB']['id'], $queueIds, 'the shared professional\'s Clinic-B Visit is visible');
        self::assertNotContains($fixture['visitA']['id'], $queueIds, 'Clinic-A Visit must never appear under trusted B');
        self::assertNotContains($fixture['visitB2']['id'], $queueIds, 'the filter narrows to the requested clinician');
        self::assertCount(1, $queueIds, 'the filter yields exactly that clinician\'s Clinic-B queue');
        self::assertSame(1, $data['stats']['total'], 'stats honour the same narrowing');
    }

    // ==================================================================
    // Real-time path for the doctor (same resolution, no ETag expansion)
    // ==================================================================

    public function testDoctorRealtimeFeedUnderTrustedClinicBCarriesOnlyOwnScopeEvents(): void
    {
        $fixture = $this->sharedProfessionalFixture();

        self::assertTrue(
            $this->authz()->can($fixture['professionalUserId'], $this->clinics['B'], RolesAndCapabilities::QUEUE_READ),
            'precondition: doctor keeps Phase 3 QUEUE_READ in B for the feed path'
        );

        $response = $this->dispatchRead(
            'GET',
            self::NS . '/rt/queue',
            ['since' => 0],
            $this->clinics['B'],
            $fixture['professionalUserId']
        );

        self::assertSame($this->clinics['B'], $this->capturedRestClinicId, 'trusted Clinic B is established for the feed');
        self::assertSame(200, $response->get_status(), 'authorized doctor reaches the real-time feed');

        $data = $this->payload($response);
        $visitIds = array_map(static fn (array $e): int => (int) $e['visit_id'], $data['events']);

        self::assertContains(
            $fixture['visitB']['id'],
            $visitIds,
            'CONTRACT: the shared professional\'s Clinic-B feed events must be delivered under trusted B. '
                . 'Defective main returns an empty event list for the same home-Clinic resolution reason.'
        );
        self::assertNotContains($fixture['visitA']['id'], $visitIds, 'Clinic-A events never cross the Clinic boundary');
        self::assertNotContains($fixture['visitB2']['id'], $visitIds, 'other clinicians\' events stay out of the doctor feed');
        self::assertSame(
            $fixture['visitB']['max_history_id'],
            (int) $data['last_event_id'],
            'feed watermark is the own-scope watermark inside trusted B'
        );
    }

    // ==================================================================
    // Negative controls — existing ADR-0030 behavior must be preserved
    // ==================================================================

    /** Control A — doctor without any clinician identity. */
    public function testDoctorWithoutClinicianIdentityRetainsEmptyQueueUnderTrustedClinicB(): void
    {
        $doctorUserId = $this->makeUser('p4s4_unlinked', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($doctorUserId, $this->clinics['B'], RolesAndCapabilities::ROLE_DOCTOR);
        $otherVisit = $this->insertVisit($this->clinics['B'], $this->insertClinician($this->clinics['B'], null), 'unlinked-other');

        self::assertSame(0, $this->countClinicianRowsForUser($doctorUserId), 'precondition: no clinician identity for this WP user');
        self::assertTrue(
            $this->authz()->can($doctorUserId, $this->clinics['B'], RolesAndCapabilities::QUEUE_READ),
            'precondition: actor is authorized in B; only the identity link is missing'
        );

        $response = $this->dispatchRead('GET', self::NS . '/doctor/today', [], $this->clinics['B'], $doctorUserId);

        self::assertSame($this->clinics['B'], $this->capturedRestClinicId, 'trusted Clinic B is established');
        self::assertSame(200, $response->get_status(), 'ADR-0030: an unlinked doctor still receives a dashboard');
        $data = $this->payload($response);
        self::assertSame([], $data['queue'], 'ADR-0030: empty queue, never the whole Clinic');
        self::assertSame(0, $data['stats']['total'], 'ADR-0030: zero stats, never the whole Clinic');
        self::assertSame(0, (int) $data['last_event_id'], 'ADR-0030: zero watermark');
        self::assertGreaterThan(0, $otherVisit['id'], 'control: a visible Clinic-B Visit existed and stayed hidden');
    }

    /** Control A/B — inactive identity: participation primitive is false, scope stays empty. */
    public function testDoctorWithInactiveIdentityRetainsEmptyQueueUnderTrustedClinicB(): void
    {
        $doctorUserId = $this->makeUser('p4s4_inactive', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($this->clinics['A'], $doctorUserId, false);
        cpms_test_seed_membership($doctorUserId, $this->clinics['A'], RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($doctorUserId, $this->clinics['B'], RolesAndCapabilities::ROLE_DOCTOR);
        $this->insertVisit($this->clinics['B'], $clinicianId, 'inactive-identity');

        self::assertSame(1, $this->countClinicianRowsForUser($doctorUserId), 'precondition: exactly one identity exists');
        self::assertFalse(
            (new MembershipRepository(App::db()))->clinician_participates_in($clinicianId, $this->clinics['B']),
            'precondition: participation primitive rejects an inactive identity'
        );

        $response = $this->dispatchRead('GET', self::NS . '/doctor/today', [], $this->clinics['B'], $doctorUserId);

        self::assertSame($this->clinics['B'], $this->capturedRestClinicId, 'trusted Clinic B is established');
        self::assertSame(200, $response->get_status(), 'inactive identity keeps the dashboard contract');
        $data = $this->payload($response);
        self::assertSame([], $data['queue'], 'inactive identity resolves to an empty scope, not the whole Clinic');
        self::assertSame(0, $data['stats']['total'], 'inactive identity yields zero stats');
    }

    /**
     * Control B — suspended participation in B.
     *
     * Suspension is rejected by the pre-existing REST scope boundary
     * (no ACTIVE membership ⇒ CLINIC_SCOPE_UNAVAILABLE), so the queue is never
     * even computed. The service-level scope is asserted separately with an
     * explicitly established Clinic-B scope to prove the fail-closed result.
     */
    public function testDoctorWithSuspendedParticipationInBNeverSeesClinicQueue(): void
    {
        $doctorUserId = $this->makeUser('p4s4_suspended', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($this->clinics['A'], $doctorUserId);
        cpms_test_seed_membership($doctorUserId, $this->clinics['A'], RolesAndCapabilities::ROLE_DOCTOR);
        $membershipB = cpms_test_seed_membership($doctorUserId, $this->clinics['B'], RolesAndCapabilities::ROLE_DOCTOR);
        App::membership_service()->suspend_membership($membershipB);
        $visitB = $this->insertVisit($this->clinics['B'], $clinicianId, 'suspended-participation');

        self::assertNotNull(
            App::membership_service()->membership_for($this->clinics['B'], $doctorUserId),
            'precondition: suspended durable membership row exists in B'
        );
        self::assertNull(
            App::membership_service()->active_membership_for($this->clinics['B'], $doctorUserId),
            'precondition: suspended membership is not ACTIVE participation'
        );
        self::assertFalse(
            (new MembershipRepository(App::db()))->clinician_participates_in($clinicianId, $this->clinics['B']),
            'precondition: participation primitive rejects suspended participation in B'
        );

        $response = $this->dispatchRead('GET', self::NS . '/doctor/today', [], $this->clinics['B'], $doctorUserId);
        self::assertSame(403, $response->get_status(), 'existing scope boundary denies a suspended B membership');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($response), 'existing denial code is preserved');

        // Service-level proof: even with a legitimately established Clinic-B
        // scope, suspended participation resolves to the empty scope.
        wp_set_current_user($doctorUserId);
        App::replaceExplicitScope(ClinicScope::forClinic($this->clinics['B']));
        try {
            $today = App::visitService()->today($doctorUserId);
        } finally {
            App::replaceExplicitScope(null);
        }

        self::assertSame([], $today['queue'], 'suspended participation yields an empty queue, never Clinic-wide rows');
        self::assertSame(0, $today['stats']['total'], 'suspended participation yields zero stats');
        self::assertGreaterThan(0, $visitB['id'], 'control: a visible Clinic-B Visit existed and stayed hidden');
    }

    /** Control C — staff filter for a clinician without ACTIVE participation in B. */
    public function testStaffFilterByNonParticipatingClinicianFailsClosedWith404(): void
    {
        $secretaryUserId = $this->makeAuthorizedSecretary($this->clinics['B'], 'p4s4_np_sec');
        $otherDoctor = $this->makeUser('p4s4_np_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $otherClinician = $this->insertClinician($this->clinics['A'], $otherDoctor);
        cpms_test_seed_membership($otherDoctor, $this->clinics['A'], RolesAndCapabilities::ROLE_DOCTOR);

        self::assertNull(
            App::membership_service()->membership_for($this->clinics['B'], $otherDoctor),
            'precondition: filtered professional has no durable participation row in B'
        );
        self::assertFalse(
            (new MembershipRepository(App::db()))->clinician_participates_in($otherClinician, $this->clinics['B']),
            'precondition: participation primitive rejects the missing B participation'
        );

        $response = $this->dispatchRead(
            'GET',
            self::NS . '/queue',
            ['clinician_id' => $otherClinician],
            $this->clinics['B'],
            $secretaryUserId
        );

        self::assertSame($this->clinics['B'], $this->capturedRestClinicId, 'trusted Clinic B is established');
        self::assertSame(404, $response->get_status(), 'non-participating clinician fails closed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response), 'existing 404 parity is preserved');
    }

    /** Control D — staff filter for a clinician with suspended participation in B. */
    public function testStaffFilterBySuspendedClinicianParticipationFailsClosedWith404(): void
    {
        $secretaryUserId = $this->makeAuthorizedSecretary($this->clinics['B'], 'p4s4_susp_sec');
        $otherDoctor = $this->makeUser('p4s4_susp_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $otherClinician = $this->insertClinician($this->clinics['A'], $otherDoctor);
        cpms_test_seed_membership($otherDoctor, $this->clinics['A'], RolesAndCapabilities::ROLE_DOCTOR);
        $otherMembershipB = cpms_test_seed_membership($otherDoctor, $this->clinics['B'], RolesAndCapabilities::ROLE_DOCTOR);
        App::membership_service()->suspend_membership($otherMembershipB);

        self::assertNull(
            App::membership_service()->active_membership_for($this->clinics['B'], $otherDoctor),
            'precondition: suspended membership is not ACTIVE participation'
        );
        self::assertFalse(
            (new MembershipRepository(App::db()))->clinician_participates_in($otherClinician, $this->clinics['B']),
            'precondition: participation primitive rejects suspended participation'
        );

        $response = $this->dispatchRead(
            'GET',
            self::NS . '/queue',
            ['clinician_id' => $otherClinician],
            $this->clinics['B'],
            $secretaryUserId
        );

        self::assertSame($this->clinics['B'], $this->capturedRestClinicId, 'trusted Clinic B is established');
        self::assertSame(404, $response->get_status(), 'suspended participation fails closed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response), 'suspension keeps the same 404 parity');
    }

    /** Control E — staff filter for a nonexistent or inactive clinician. */
    public function testStaffFilterByNonexistentOrInactiveClinicianKeepsExisting404Parity(): void
    {
        $secretaryUserId = $this->makeAuthorizedSecretary($this->clinics['B'], 'p4s4_missing_sec');
        $inactiveClinician = $this->insertClinician($this->clinics['B'], null, false);

        $missing = $this->dispatchRead(
            'GET',
            self::NS . '/queue',
            ['clinician_id' => 99999991],
            $this->clinics['B'],
            $secretaryUserId
        );
        self::assertSame($this->clinics['B'], $this->capturedRestClinicId, 'trusted Clinic B is established');
        self::assertSame(404, $missing->get_status(), 'nonexistent clinician keeps 404 parity');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($missing), 'stable error code for a missing clinician');

        $inactive = $this->dispatchRead(
            'GET',
            self::NS . '/queue',
            ['clinician_id' => $inactiveClinician],
            $this->clinics['B'],
            $secretaryUserId
        );
        self::assertSame(404, $inactive->get_status(), 'inactive clinician keeps 404 parity');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($inactive), 'stable error code for an inactive clinician');
    }

    /** Control F — staff without a filter sees the whole trusted Clinic B, never Clinic A. */
    public function testStaffWithoutClinicianFilterSeesWholeTrustedClinicBOnly(): void
    {
        $fixture = $this->sharedProfessionalFixture();

        $response = $this->dispatchRead('GET', self::NS . '/queue', [], $this->clinics['B'], $fixture['secretaryUserId']);

        self::assertSame($this->clinics['B'], $this->capturedRestClinicId, 'trusted Clinic B is established');
        self::assertSame(200, $response->get_status(), 'unfiltered staff queue stays available');

        $data = $this->payload($response);
        $queueIds = array_map(static fn (array $v): int => (int) $v['id'], $data['queue']);

        self::assertContains($fixture['visitB']['id'], $queueIds, 'shared professional row is part of the Clinic-B queue');
        self::assertContains($fixture['visitB2']['id'], $queueIds, 'the other Clinic-B clinician row is visible without a filter');
        self::assertNotContains($fixture['visitA']['id'], $queueIds, 'Clinic-A rows are never visible under trusted B');
        self::assertSame(2, $data['stats']['total'], 'unfiltered stats are the whole trusted Clinic B');
    }

    /** Control G — Phase 3 denial stays authoritative. */
    public function testUnauthorizedActorInBKeepsPhase3Denial(): void
    {
        $fixture = $this->sharedProfessionalFixture();
        $deniedUserId = $this->makeUser('p4s4_denied', RolesAndCapabilities::ROLE_SECRETARY);
        $deniedMembership = cpms_test_seed_membership($deniedUserId, $this->clinics['B'], RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->set_capability($deniedMembership, RolesAndCapabilities::QUEUE_READ, 'deny');

        self::assertTrue(
            user_can($deniedUserId, RolesAndCapabilities::QUEUE_READ),
            'precondition: actor still has the coarse global capability'
        );
        self::assertFalse(
            $this->authz()->can($deniedUserId, $this->clinics['B'], RolesAndCapabilities::QUEUE_READ),
            'precondition: existing Phase 3 scoped decision is deny'
        );

        $response = $this->dispatchRead(
            'GET',
            self::NS . '/queue',
            ['clinician_id' => $fixture['clinicianId']],
            $this->clinics['B'],
            $deniedUserId
        );

        self::assertSame($this->clinics['B'], $this->capturedRestClinicId, 'trusted Clinic B is established before the guard');
        self::assertSame(403, $response->get_status(), 'Phase 3 authorization denial remains authoritative');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($response), 'existing Phase 3 denial code is preserved');
    }

    /** Control H — the shared professional keeps exactly one identity across both paths. */
    public function testSharedProfessionalIdentityRemainsExactlyOneAfterQueueResolution(): void
    {
        $fixture = $this->sharedProfessionalFixture();

        $this->dispatchRead('GET', self::NS . '/doctor/today', [], $this->clinics['B'], $fixture['professionalUserId']);
        $this->dispatchRead(
            'GET',
            self::NS . '/queue',
            ['clinician_id' => $fixture['clinicianId']],
            $this->clinics['B'],
            $fixture['secretaryUserId']
        );

        self::assertSame(
            1,
            $this->countClinicianRowsForUser($fixture['professionalUserId']),
            'read-only queue resolution never creates a duplicate clinician identity'
        );
        self::assertSame(
            $this->clinics['A'],
            $this->clinicianHomeClinic($fixture['clinicianId']),
            'read-only queue resolution never re-homes the compatibility Clinic'
        );
        self::assertSame(
            3,
            $this->countVisitsForClinics(),
            'read-only slice performs no Visit mutation'
        );
    }

    /** Adjacent-path preservation — home-Clinic doctor scope is unchanged (ADR-0030). */
    public function testHomeClinicDoctorScopeRemainsOwnVisitsOnlyUnderTrustedClinicA(): void
    {
        $doctorUserId = $this->makeUser('p4s4_home_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($this->clinics['A'], $doctorUserId);
        cpms_test_seed_membership($doctorUserId, $this->clinics['A'], RolesAndCapabilities::ROLE_DOCTOR);
        $colleague = $this->insertClinician($this->clinics['A'], null);
        $ownVisit = $this->insertVisit($this->clinics['A'], $clinicianId, 'home-own');
        $colleagueVisit = $this->insertVisit($this->clinics['A'], $colleague, 'home-colleague');

        self::assertSame(
            $this->clinics['A'],
            $this->clinicianHomeClinic($clinicianId),
            'precondition: home Clinic is A'
        );
        self::assertTrue(
            (new MembershipRepository(App::db()))->clinician_participates_in($clinicianId, $this->clinics['A']),
            'precondition: home-Clinic compatibility path stays valid'
        );

        $response = $this->dispatchRead('GET', self::NS . '/doctor/today', [], $this->clinics['A'], $doctorUserId);

        self::assertSame($this->clinics['A'], $this->capturedRestClinicId, 'trusted Clinic A is established');
        self::assertSame(200, $response->get_status(), 'home-Clinic doctor dashboard stays available');

        $data = $this->payload($response);
        $queueIds = array_map(static fn (array $v): int => (int) $v['id'], $data['queue']);

        self::assertContains($ownVisit['id'], $queueIds, 'the doctor sees the own Clinic-A Visit');
        self::assertNotContains($colleagueVisit['id'], $queueIds, 'the doctor never implicitly sees a colleague\'s rows');
        self::assertSame(1, $data['stats']['total'], 'home-Clinic stats remain own-scope only');
    }

    // ==================================================================
    // Fixture
    // ==================================================================

    /**
     * Material fixture for the shared professional contract.
     *
     * @return array{
     *     professionalUserId: int,
     *     clinicianId: int,
     *     secretaryUserId: int,
     *     patientA: int,
     *     patientB: int,
     *     visitA: array{id: int, max_history_id: int},
     *     visitB: array{id: int, max_history_id: int},
     *     visitB2: array{id: int, max_history_id: int}
     * }
     */
    private function sharedProfessionalFixture(): array
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];

        $professionalUserId = $this->makeUser('p4s4_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($clinicA, $professionalUserId);
        $membershipA = cpms_test_seed_membership($professionalUserId, $clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $membershipB = cpms_test_seed_membership($professionalUserId, $clinicB, RolesAndCapabilities::ROLE_DOCTOR);

        $secretaryUserId = $this->makeAuthorizedSecretary($clinicB, 'p4s4_sec');

        $patientA = $this->insertPatient($clinicA, 'a');
        $patientB = $this->insertPatient($clinicB, 'b');
        $patientB2 = $this->insertPatient($clinicB, 'b2');
        $colleagueB = $this->insertClinician($clinicB, null);

        // Insertion order matters: the second Clinic-B Visit gets the highest
        // history ids, so an own-scope watermark is provably below the whole-Clinic one.
        $visitA = $this->insertVisit($clinicA, $clinicianId, 'clinic-a', $patientA);
        $visitB = $this->insertVisit($clinicB, $clinicianId, 'clinic-b', $patientB);
        $visitB2 = $this->insertVisit($clinicB, $colleagueB, 'clinic-b-colleague', $patientB2);

        self::assertGreaterThan(0, $this->organizationId, 'precondition: Organization inserted');
        self::assertGreaterThan(1, $clinicA, 'precondition: Clinic A is dynamic, never seeded Clinic 1');
        self::assertGreaterThan(1, $clinicB, 'precondition: Clinic B is dynamic, never seeded Clinic 1');
        self::assertNotSame($clinicA, $clinicB, 'precondition: A and B are distinct Clinics');
        self::assertGreaterThan(0, $professionalUserId, 'precondition: professional WP user created');
        self::assertGreaterThan(0, $clinicianId, 'precondition: clinician row inserted');
        self::assertSame($clinicA, $this->clinicianHomeClinic($clinicianId), 'precondition: home Clinic is A');
        self::assertGreaterThan(0, $membershipA, 'precondition: professional membership A inserted');
        self::assertGreaterThan(0, $membershipB, 'precondition: professional membership B inserted');
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicA, $professionalUserId),
            'precondition: ACTIVE durable membership in A'
        );
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicB, $professionalUserId),
            'precondition: ACTIVE durable membership in B'
        );
        self::assertGreaterThan(0, $secretaryUserId, 'precondition: Clinic-B staff actor created');
        self::assertSame($clinicA, $this->patientClinic($patientA), 'precondition: Clinic-A patient owns its record');
        self::assertSame($clinicB, $this->patientClinic($patientB), 'precondition: Clinic-B patient owns its record');
        self::assertGreaterThan($visitA['id'], 0, 'precondition: Clinic-A Visit inserted for the shared identity');
        self::assertSame($clinicA, $this->visitClinic($visitA['id']), 'precondition: Clinic-A Visit is Clinic-A owned');
        self::assertSame($clinicianId, $this->visitClinician($visitA['id']), 'precondition: Clinic-A Visit uses the shared identity');
        self::assertGreaterThan($visitB['id'], 0, 'precondition: Clinic-B Visit inserted for the shared identity');
        self::assertSame($clinicB, $this->visitClinic($visitB['id']), 'precondition: Clinic-B Visit is Clinic-B owned');
        self::assertSame($clinicianId, $this->visitClinician($visitB['id']), 'precondition: Clinic-B Visit uses the shared identity');
        self::assertSame($clinicB, $this->visitClinic($visitB2['id']), 'precondition: colleague Visit is Clinic-B owned');
        self::assertLessThan($visitB['max_history_id'], $visitB2['max_history_id'], 'precondition: history ordering witness holds');

        return [
            'professionalUserId' => $professionalUserId,
            'clinicianId' => $clinicianId,
            'secretaryUserId' => $secretaryUserId,
            'patientA' => $patientA,
            'patientB' => $patientB,
            'visitA' => $visitA,
            'visitB' => $visitB,
            'visitB2' => $visitB2,
        ];
    }

    private function makeAuthorizedSecretary(int $clinicId, string $prefix): int
    {
        $userId = $this->makeUser($prefix, RolesAndCapabilities::ROLE_SECRETARY);
        $membershipId = cpms_test_seed_membership($userId, $clinicId, RolesAndCapabilities::ROLE_SECRETARY);
        self::assertGreaterThan(0, $membershipId, 'fixture: staff membership insertion succeeded');
        self::assertTrue(
            $this->authz()->can($userId, $clinicId, RolesAndCapabilities::QUEUE_READ),
            'fixture: staff actor has existing Phase 3 QUEUE_READ authorization'
        );

        return $userId;
    }

    /**
     * @return array{id: int, max_history_id: int}
     */
    private function insertVisit(int $clinicId, int $clinicianId, string $tag, ?int $patientId = null): array
    {
        global $wpdb;
        $patient = $patientId ?? $this->insertPatient($clinicId, $tag);
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                 (clinic_id, clinician_id, patient_id, appointment_id, source, status, visit_date,
                  check_in_at, waiting_since, created_at, updated_at)
             VALUES (%d, %d, %d, NULL, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinicId,
            $clinicianId,
            $patient,
            'walk_in',
            'waiting',
            gmdate('Y-m-d'),
            $now,
            $now,
            $now,
            $now
        ));
        $visitId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $visitId, 'fixture: Visit ' . $tag . ' insertion succeeded (' . $wpdb->last_error . ')');

        // checked_in (from_status NULL) then waiting — the queue statuses feed.
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visit_status_history
                 (visit_id, from_status, to_status, changed_at, actor_wp_user_id, actor_role)
             VALUES (%d, NULL, %s, %s, NULL, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $visitId,
            'checked_in',
            $now,
            'secretary'
        ));
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'fixture: initial history row inserted for Visit ' . $tag);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visit_status_history
                 (visit_id, from_status, to_status, changed_at, actor_wp_user_id, actor_role)
             VALUES (%d, %s, %s, %s, NULL, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $visitId,
            'checked_in',
            'waiting',
            $now,
            'secretary'
        ));
        $maxHistory = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $maxHistory, 'fixture: queue history row inserted for Visit ' . $tag);

        return ['id' => $visitId, 'max_history_id' => $maxHistory];
    }

    /**
     * wp_user_id stays NULL (never 0) for an unlinked clinician: u_clinician_user
     * is UNIQUE and MySQL ignores NULLs, so several unlinked rows stay legal.
     */
    private function insertClinician(int $homeClinicId, ?int $wpUserId, bool $active = true): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $name = 'Dr P4S4 ' . bin2hex(random_bytes(3));
        if ($wpUserId === null) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, NULL, %d, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $homeClinicId,
                $name,
                $active ? 1 : 0,
                $now,
                $now
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, %d, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $homeClinicId,
                $name,
                $wpUserId,
                $active ? 1 : 0,
                $now,
                $now
            ));
        }
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: clinician row insertion succeeded (' . $wpdb->last_error . ')');

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
            'MR-P4S4-' . strtoupper($tag) . '-' . $unique,
            'P4S4',
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

    private function createOrganization(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'P4S4 Organization ' . $unique,
            'p4s4-org-' . $unique,
            'active',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: Organization insertion succeeded (' . $wpdb->last_error . ')');
        self::assertSame(
            'active',
            (string) $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
            'P4S4 Clinic ' . $tag . ' ' . $unique,
            'p4s4-clinic-' . strtolower($tag) . '-' . $unique,
            'Asia/Tehran',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'fixture: dynamic Clinic ' . $tag . ' insertion succeeded (' . $wpdb->last_error . ')');
        self::assertSame(
            $this->organizationId,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id
            )),
            'fixture: Clinic ' . $tag . ' belongs to the explicit Organization'
        );

        return $id;
    }

    private function makeUser(string $prefix, string $role): int
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $userId = (int) wp_create_user($unique, wp_generate_password(24), $unique . '@p4s4.test');
        self::assertGreaterThan(0, $userId, 'fixture: WP user ' . $prefix . ' created');
        $user = get_userdata($userId);
        self::assertNotFalse($user, 'fixture: WP user is queryable');
        $user->set_role($role);
        self::assertContains($role, (array) get_userdata($userId)->roles, 'fixture: WP role assigned');

        return $userId;
    }

    // ==================================================================
    // Readers / assertions
    // ==================================================================

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

    private function visitClinic(int $visitId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visitId]
        );
    }

    private function visitClinician(int $visitId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT clinician_id FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visitId]
        );
    }

    private function countVisitsForClinics(): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . ' WHERE clinic_id IN (%d, %d)',
            [$this->clinics['A'], $this->clinics['B']]
        );
    }

    /**
     * VALID RED diagnostic — every bootstrap/fixture/authentication/scope/Phase 3
     * assertion has already passed when this runs, so the only accepted pre-patch
     * outcome is an empty own-scope dashboard (scopeClinicianId resolved to 0).
     */
    private function assertDefectiveMainEmptyDoctorQueue(WP_REST_Response $response): void
    {
        $data = $this->payload($response);
        if ($data['queue'] !== []) {
            return;
        }

        self::assertSame(0, $data['stats']['total'], 'VALID RED diagnostic: defective main resolves the doctor scope to zero');
        self::assertSame(0, (int) $data['last_event_id'], 'VALID RED diagnostic: defective main resolves the watermark to zero');
    }

    /**
     * VALID RED diagnostic — the accepted pre-patch outcome is the existing
     * clinician 404 parity, proving the request reached the staff filter branch.
     */
    private function assertDefectiveMainClinicianFilterRejection(WP_REST_Response $response): void
    {
        if ($response->get_status() === 200) {
            return;
        }

        self::assertSame(404, $response->get_status(), 'VALID RED diagnostic: home-Clinic mismatch is a 404');
        self::assertSame(
            'CLINIC_NOT_FOUND',
            $this->errorCode($response),
            'VALID RED diagnostic: the staff filter branch was reached and rejected the shared professional'
        );
    }

    /**
     * @return array{queue: list<array<string, mixed>>, stats: array<string, int>, last_event_id: int, events?: list<array<string, mixed>>}
     */
    private function payload(WP_REST_Response $response): array
    {
        $body = $response->get_data();
        self::assertIsArray($body, 'response body is the standard envelope');
        self::assertIsArray($body['data'] ?? null, 'envelope carries a data payload');
        $data = $body['data'];

        return [
            'queue' => is_array($data['queue'] ?? null) ? array_values($data['queue']) : [],
            'stats' => is_array($data['stats'] ?? null) ? $data['stats'] : [],
            'last_event_id' => (int) ($data['last_event_id'] ?? 0),
            'events' => is_array($data['events'] ?? null) ? array_values($data['events']) : [],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dispatchRead(string $method, string $route, array $params, int $clinicId, int $userId): WP_REST_Response
    {
        wp_set_current_user($userId);
        self::assertSame($userId, get_current_user_id(), 'precondition: REST actor authentication established');

        $nonce = wp_create_nonce('wp_rest');
        self::assertSame(1, wp_verify_nonce($nonce, 'wp_rest'), 'precondition: REST nonce authentication succeeds');

        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
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
        foreach (['cpms_visits', 'cpms_patients', 'cpms_clinicians'] as $table) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $table .
                         ' WHERE clinic_id IN (' . $clinicSubquery . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $wpdb->query('DELETE mc FROM ' . $wpdb->prefix . 'cpms_membership_capabilities mc
                      INNER JOIN ' . $wpdb->prefix . 'cpms_clinic_memberships m ON m.id = mc.membership_id
                      WHERE m.clinic_id IN (' . $clinicSubquery . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinic_memberships
                      WHERE clinic_id IN (' . $clinicSubquery . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $organizationId); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = ' . $organizationId); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}
