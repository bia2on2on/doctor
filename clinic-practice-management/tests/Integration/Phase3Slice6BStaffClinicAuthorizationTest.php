<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Phase 3 Slice 6B — RED contracts: Clinic-scoped staff authorization for the
 * remaining CURRENT staff Clinic surfaces (Queue, Schedule, staff Booking
 * operations, staff Patient operations).
 *
 * These tests express the SECURITY CONTRACT and are intentionally NOT weakened:
 * every CURRENT staff Clinic operation requires an ACTIVE durable membership +
 * the exact scoped permission (explicit deny > explicit grant > membership role
 * preset > deny), and durable Clinic-B objects must be indistinguishable from
 * missing objects for an actor scoped to Clinic A (404 parity, zero mutation).
 *
 * They must remain RED on defective main (global coarse WP capability alone
 * currently authorizes these staff operations; durable object ownership is not
 * compared against the trusted Clinic) and must turn GREEN with the Slice 6B
 * production patch (RestBase::requireClinicPermission + service-level durable
 * object ownership guards).
 *
 * Selected highest-value RED contracts (not one per endpoint):
 *  - RED A — staff Patient access (global coarse cap + active membership + scoped deny)
 *  - RED B — Queue + Schedule write (global coarse cap + active membership + scoped deny)
 *  - RED C — staff Booking create (global coarse cap + active membership + scoped deny)
 *  - RED D — cross-Clinic durable objects (visit transition + staff appointment cancel)
 * plus allow/separation matrix tests that must stay green throughout (legitimate
 * staff, patient-self flows, public availability, no-membership denial at the
 * trusted-context boundary, multi-Clinic independence).
 *
 * Fixture rules (no first-row tenant, no fixed tenant ids):
 *  - dynamic Organization with status=active + asserted insertion;
 *  - dynamic Clinics under that Organization with asserted linkage;
 *  - dynamic users with unique logins/emails;
 *  - Clinic context always established explicitly via X-CPMS-Clinic-Id (the
 *    same trusted path production staff requests use).
 */
final class Phase3Slice6BStaffClinicAuthorizationTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $orgId = 0;
    /** @var array<string,int> clinic tag -> id */
    private array $clinics = [];
    /** @var array<int,int> clinicId -> primary location_id */
    private array $locations = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $this->orgId = $this->createOrganization();
        $this->clinics['A'] = $this->createClinic($this->orgId, 'p3s6b-a');
        $this->clinics['B'] = $this->createClinic($this->orgId, 'p3s6b-b');
        $this->locations[$this->clinics['A']] = $this->createLocation($this->clinics['A']);
        $this->locations[$this->clinics['B']] = $this->createLocation($this->clinics['B']);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();
        \ClinicCore\Settings\Settings::flushCache();
        $this->purgeRows();
        parent::tearDown();
    }

    // ================================================================
    // RED A — STAFF PATIENT ACCESS
    // ================================================================

    /**
     * RED A (read): secretary with the global coarse patient capability and an
     * ACTIVE membership, but an explicit scoped DENY on cpms_patient_read, must
     * be denied the staff patient read — and the record must not leak.
     *
     * Defective main: global WP capability alone authorizes the operation.
     */
    public function testRedAStaffPatientReadDeniedDespiteGlobalCapAndActiveMembership(): void
    {
        $clinicA = $this->clinics['A'];
        $patientId = $this->insertPatient($clinicA);

        $actor = $this->makeUser('p3s6b_pat_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $membershipId = cpms_test_seed_membership($actor, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->set_capability($membershipId, RolesAndCapabilities::PATIENT_READ, 'deny');

        // RED preconditions: coarse global capability + active durable membership + scoped deny.
        self::assertTrue(
            user_can($actor, RolesAndCapabilities::PATIENT_READ),
            'precondition: global coarse cpms_patient_read present (WP role secretary)'
        );
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicA, $actor),
            'precondition: ACTIVE durable membership in clinic A'
        );
        self::assertFalse(
            $this->authz()->can($actor, $clinicA, RolesAndCapabilities::PATIENT_READ),
            'precondition: scoped cpms_patient_read DENIED by membership capability override'
        );

        $read = $this->dispatch('GET', self::NS . '/patients/' . $patientId, [], $clinicA, $actor);
        self::assertSame(
            403,
            $read->get_status(),
            'RED A (read): scoped deny must forbid staff patient read — global coarse capability alone must not authorize it'
        );
        $flat = wp_json_encode($read->get_data());
        self::assertStringNotContainsString('MR-P3S6B-', (string) $flat, 'denied read must not leak patient MRN');
        self::assertStringNotContainsString('0912', (string) $flat, 'denied read must not leak patient mobile');
    }

    /**
     * RED A (write): same actor shape with a scoped DENY on cpms_patient_create
     * must be denied staff patient creation — with zero durable side effects.
     *
     * Defective main: the patient row is created.
     */
    public function testRedAStaffPatientCreateDeniedWithZeroDurableSideEffects(): void
    {
        $clinicA = $this->clinics['A'];

        $actor = $this->makeUser('p3s6b_patc_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $membershipId = cpms_test_seed_membership($actor, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->set_capability($membershipId, RolesAndCapabilities::PATIENT_CREATE, 'deny');

        self::assertTrue(
            user_can($actor, RolesAndCapabilities::PATIENT_CREATE),
            'precondition: global coarse cpms_patient_create present (WP role secretary)'
        );
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicA, $actor),
            'precondition: ACTIVE durable membership in clinic A'
        );
        self::assertFalse(
            $this->authz()->can($actor, $clinicA, RolesAndCapabilities::PATIENT_CREATE),
            'precondition: scoped cpms_patient_create DENIED by membership capability override'
        );

        $patientsBefore = $this->countPatientsInClinic($clinicA);
        $create = $this->dispatch('POST', self::NS . '/patients', [
            'first_name' => 'Denied',
            'last_name' => 'ByScope',
            'mobile' => '0912' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
        ], $clinicA, $actor);
        self::assertSame(
            403,
            $create->get_status(),
            'RED A (create): scoped deny must forbid staff patient create — defective main creates the patient row'
        );
        self::assertSame(
            $patientsBefore,
            $this->countPatientsInClinic($clinicA),
            'RED A (create): denied write must leave zero durable side effects'
        );
    }

    // ================================================================
    // RED B — QUEUE + SCHEDULE WRITE
    // ================================================================

    /**
     * RED B (walk-in): secretary with the global coarse capability and an ACTIVE
     * membership, but an explicit scoped DENY on cpms_queue_checkin, must be
     * denied the queue write — with zero durable mutations.
     *
     * Defective main: the visit row is created.
     */
    public function testRedBQueueWalkInDeniedDespiteScopedDenyZeroMutation(): void
    {
        $clinicA = $this->clinics['A'];
        $patientId = $this->insertPatient($clinicA);
        $clinicianId = $this->insertClinician($clinicA, 0, 'Dr P3S6B Queue');

        $secretary = $this->makeUser('p3s6b_q_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $secMembership = cpms_test_seed_membership($secretary, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->set_capability($secMembership, RolesAndCapabilities::QUEUE_CHECKIN, 'deny');

        self::assertTrue(
            user_can($secretary, RolesAndCapabilities::QUEUE_CHECKIN),
            'precondition: global coarse cpms_queue_checkin present (WP role secretary)'
        );
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicA, $secretary),
            'precondition: ACTIVE durable membership in clinic A'
        );
        self::assertFalse(
            $this->authz()->can($secretary, $clinicA, RolesAndCapabilities::QUEUE_CHECKIN),
            'precondition: scoped cpms_queue_checkin DENIED'
        );

        $visitsBefore = $this->countVisitsForPatient($patientId);
        $walkIn = $this->dispatch('POST', self::NS . '/visits/walk-in', [
            'patient_id' => $patientId,
            'clinician_id' => $clinicianId,
        ], $clinicA, $secretary);
        self::assertSame(
            403,
            $walkIn->get_status(),
            'RED B (walk-in): scoped deny must forbid queue write — defective main creates the visit row'
        );
        self::assertSame(
            $visitsBefore,
            $this->countVisitsForPatient($patientId),
            'RED B (walk-in): denied write must leave zero durable side effects'
        );
    }

    /**
     * RED B (transition): scoped DENY on cpms_queue_advance must forbid the
     * secretary status transition — status unchanged, zero history rows.
     *
     * Defective main: the durable visit row is mutated.
     */
    public function testRedBQueueStatusTransitionDeniedDespiteScopedDenyZeroMutation(): void
    {
        $clinicA = $this->clinics['A'];
        $locationA = $this->locations[$clinicA];
        $patientId = $this->insertPatient($clinicA);
        $clinicianId = $this->insertClinician($clinicA, 0, 'Dr P3S6B Queue T');
        $visitId = $this->insertVisit($clinicA, $locationA, $clinicianId, $patientId, 'waiting');

        $secretary = $this->makeUser('p3s6b_qs_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $secMembership = cpms_test_seed_membership($secretary, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->set_capability($secMembership, RolesAndCapabilities::QUEUE_ADVANCE, 'deny');

        self::assertTrue(
            user_can($secretary, RolesAndCapabilities::QUEUE_ADVANCE),
            'precondition: global coarse cpms_queue_advance present (WP role secretary)'
        );
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicA, $secretary),
            'precondition: ACTIVE durable membership in clinic A'
        );
        self::assertFalse(
            $this->authz()->can($secretary, $clinicA, RolesAndCapabilities::QUEUE_ADVANCE),
            'precondition: scoped cpms_queue_advance DENIED'
        );

        $historyBefore = $this->countVisitHistory($visitId);
        $status = $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/status', [
            'to_status' => 'cancelled',
            'note' => 'scoped deny probe',
        ], $clinicA, $secretary);
        self::assertSame(
            403,
            $status->get_status(),
            'RED B (transition): scoped deny must forbid queue status write — defective main mutates the visit'
        );
        self::assertSame(
            'waiting',
            $this->visitStatus($visitId),
            'RED B (transition): denied write must not change visit status'
        );
        self::assertSame(
            $historyBefore,
            $this->countVisitHistory($visitId),
            'RED B (transition): denied write must leave zero durable history rows'
        );
    }

    /**
     * RED B (schedule): administrator with the global coarse cpms_config and an
     * ACTIVE membership, but an explicit scoped DENY on cpms_config, must be
     * denied the weekly schedule write — with zero durable mutations.
     *
     * Defective main: the schedule row is created (and slots regenerate).
     */
    public function testRedBScheduleCreateDeniedDespiteScopedDenyZeroMutation(): void
    {
        $clinicA = $this->clinics['A'];

        $admin = $this->makeUser('p3s6b_cfg_adm', 'administrator');
        $admMembership = cpms_test_seed_membership($admin, $clinicA, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->set_capability($admMembership, RolesAndCapabilities::CONFIG, 'deny');

        self::assertTrue(
            user_can($admin, RolesAndCapabilities::CONFIG),
            'precondition: global coarse cpms_config present (WP administrator)'
        );
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicA, $admin),
            'precondition: ACTIVE durable membership in clinic A'
        );
        self::assertFalse(
            $this->authz()->can($admin, $clinicA, RolesAndCapabilities::CONFIG),
            'precondition: scoped cpms_config DENIED by membership capability override'
        );

        $scheduleClinician = $this->insertClinician($clinicA, 0, 'Dr P3S6B Schedule');
        $schedulesBefore = $this->countSchedulesForClinician($scheduleClinician);
        $create = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $scheduleClinician,
            'day_of_week' => random_int(0, 6),
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $clinicA, $admin);
        self::assertSame(
            403,
            $create->get_status(),
            'RED B (schedule): scoped deny must forbid schedule write — defective main creates the schedule row'
        );
        self::assertSame(
            $schedulesBefore,
            $this->countSchedulesForClinician($scheduleClinician),
            'RED B (schedule): denied write must leave zero durable side effects'
        );
    }

    // ================================================================
    // RED C — BOOKING STAFF CREATE
    // ================================================================

    /**
     * RED C: explicitly STAFF appointment creation (POST /appointments — the
     * secretary D10 path, NOT patient-self/public booking) with the global coarse
     * capability and an ACTIVE membership, but an explicit scoped DENY on
     * cpms_appt_create, must be denied — with no appointment mutation and the
     * slot left untouched.
     *
     * Defective main: the appointment is created and the slot is booked.
     */
    public function testRedCBookingStaffCreateDeniedDespiteScopedDenyNoAppointmentMutation(): void
    {
        $clinicA = $this->clinics['A'];
        $locationA = $this->locations[$clinicA];
        $patientId = $this->insertPatient($clinicA);
        $clinicianId = $this->insertClinician($clinicA, 0, 'Dr P3S6B Booking');
        $slot = $this->insertSlot($clinicA, $locationA, $clinicianId, 1, '10:00:00');

        $actor = $this->makeUser('p3s6b_bk_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $membershipId = cpms_test_seed_membership($actor, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->set_capability($membershipId, RolesAndCapabilities::APPT_CREATE, 'deny');

        self::assertTrue(
            user_can($actor, RolesAndCapabilities::APPT_CREATE),
            'precondition: global coarse cpms_appt_create present (WP role secretary)'
        );
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicA, $actor),
            'precondition: ACTIVE durable membership in clinic A'
        );
        self::assertFalse(
            $this->authz()->can($actor, $clinicA, RolesAndCapabilities::APPT_CREATE),
            'precondition: scoped cpms_appt_create DENIED by membership capability override'
        );

        $apptsBefore = $this->countAppointmentsForPatient($patientId);
        $create = $this->dispatch('POST', self::NS . '/appointments', [
            'patient_id' => $patientId,
            'clinician_id' => $clinicianId,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['id'],
            'reason' => 'staff create scoped-deny probe',
        ], $clinicA, $actor);
        self::assertSame(
            403,
            $create->get_status(),
            'RED C: scoped deny must forbid STAFF appointment create — defective main books the appointment'
        );
        self::assertSame(
            $apptsBefore,
            $this->countAppointmentsForPatient($patientId),
            'RED C: denied write must leave zero durable appointment side effects'
        );
        self::assertSame(
            0,
            $this->slotBookedCount((int) $slot['id']),
            'RED C: denied write must not book the slot'
        );
    }

    // ================================================================
    // RED D — CROSS-CLINIC DURABLE OBJECTS
    // ================================================================

    /**
     * RED D (visit): an actor legitimately authorized in Clinic A (active
     * membership, fully privileged secretary there, NO membership in B) attempts
     * to transition a durable Clinic-B visit through the staff route. The
     * cross-Clinic object mismatch must deny before read/write with the same
     * envelope as a missing object (404 parity) and zero mutation.
     *
     * Defective main: the Clinic-B visit row is mutated.
     */
    public function testRedDCrossClinicVisitTransitionDeniedZeroMutation(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $locationB = $this->locations[$clinicB];

        $actor = $this->makeUser('p3s6b_xv_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($actor, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        self::assertNull(
            App::membership_service()->active_membership_for($clinicB, $actor),
            'precondition: actor has NO membership in clinic B'
        );
        self::assertTrue(
            $this->authz()->can($actor, $clinicA, RolesAndCapabilities::QUEUE_ADVANCE),
            'precondition: actor is legitimately authorized for queue advance in clinic A'
        );

        $patientB = $this->insertPatient($clinicB);
        $clinicianB = $this->insertClinician($clinicB, 0, 'Dr P3S6B B Visit');
        $visitB = $this->insertVisit($clinicB, $locationB, $clinicianB, $patientB, 'waiting');
        $historyBefore = $this->countVisitHistory($visitB);

        $transition = $this->dispatch('POST', self::NS . '/visits/' . $visitB . '/status', [
            'to_status' => 'cancelled',
            'note' => 'cross-clinic probe',
        ], $clinicA, $actor);
        self::assertSame(
            404,
            $transition->get_status(),
            'RED D (visit): Clinic-B visit must be indistinguishable from missing for a Clinic-A actor — defective main mutates it'
        );
        self::assertSame(
            'waiting',
            $this->visitStatus($visitB),
            'RED D (visit): cross-Clinic denied write must not change visit status'
        );
        self::assertSame(
            $historyBefore,
            $this->countVisitHistory($visitB),
            'RED D (visit): cross-Clinic denied write must leave zero durable history rows'
        );
    }

    /**
     * RED D (appointment): the same Clinic-A actor attempts the STAFF cancel of
     * a durable Clinic-B appointment. Cross-Clinic mismatch must deny with 404
     * parity and zero mutation.
     *
     * Defective main: the Clinic-B appointment is cancelled.
     */
    public function testRedDCrossClinicStaffAppointmentCancelDeniedZeroMutation(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $locationB = $this->locations[$clinicB];

        $actor = $this->makeUser('p3s6b_xa_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($actor, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        self::assertNull(
            App::membership_service()->active_membership_for($clinicB, $actor),
            'precondition: actor has NO membership in clinic B'
        );
        self::assertTrue(
            user_can($actor, RolesAndCapabilities::APPT_CANCEL),
            'precondition: global coarse cpms_appt_cancel present (WP role secretary)'
        );
        self::assertTrue(
            $this->authz()->can($actor, $clinicA, RolesAndCapabilities::APPT_CANCEL),
            'precondition: actor is legitimately authorized for appt cancel in clinic A'
        );

        $patientB = $this->insertPatient($clinicB);
        $clinicianB = $this->insertClinician($clinicB, 0, 'Dr P3S6B B Appt');
        $slotB = $this->insertSlot($clinicB, $locationB, $clinicianB, 2, '11:00:00');
        $apptB = $this->insertAppointment($clinicB, $locationB, $patientB, $clinicianB, $slotB);

        $cancel = $this->dispatch('POST', self::NS . '/appointments/' . $apptB . '/cancel', [
            'reason' => 'cross-clinic probe',
        ], $clinicA, $actor);
        self::assertSame(
            404,
            $cancel->get_status(),
            'RED D (appointment): Clinic-B appointment must be indistinguishable from missing for a Clinic-A actor — defective main cancels it'
        );
        self::assertSame(
            'confirmed',
            $this->appointmentStatus($apptB),
            'RED D (appointment): cross-Clinic denied write must not change appointment status'
        );
        self::assertSame(
            0,
            $this->slotBookedCount((int) $slotB['id']),
            'RED D (appointment): cross-Clinic denied write must not release/book the slot'
        );
    }

    // ================================================================
    // ALLOW / SEPARATION MATRIX (must stay green throughout)
    // ================================================================

    /**
     * Legitimate staff (active membership, no deny) keeps working in the correct
     * Clinic: walk-in succeeds, staff patient read succeeds.
     */
    public function testAllowLegitimateSecretaryInCorrectClinic(): void
    {
        $clinicA = $this->clinics['A'];
        $patientId = $this->insertPatient($clinicA);
        $clinicianId = $this->insertClinician($clinicA, 0, 'Dr P3S6B Allow');

        $secretary = $this->makeUser('p3s6b_ok_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        self::assertTrue(
            $this->authz()->can($secretary, $clinicA, RolesAndCapabilities::QUEUE_CHECKIN),
            'precondition: legitimate secretary has scoped queue checkin in clinic A'
        );

        $walkIn = $this->dispatch('POST', self::NS . '/visits/walk-in', [
            'patient_id' => $patientId,
            'clinician_id' => $clinicianId,
        ], $clinicA, $secretary);
        self::assertSame(200, $walkIn->get_status(), 'legitimate staff walk-in must keep working');
        self::assertSame('waiting', $walkIn->get_data()['data']['status'] ?? null);

        $read = $this->dispatch('GET', self::NS . '/patients/' . $patientId, [], $clinicA, $secretary);
        self::assertSame(200, $read->get_status(), 'legitimate staff patient read must keep working');
    }

    /**
     * Same actor, two Clinics: scoped deny only in A — independent authorization
     * per Clinic (contract: same actor may be independently authorized in
     * multiple Clinics; deny in one must not leak into the other).
     */
    public function testMultiClinicSameActorScopedIndependence(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $locationB = $this->locations[$clinicB];

        $actor = $this->makeUser('p3s6b_multi_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $membershipA = cpms_test_seed_membership($actor, $clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($actor, $clinicB, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->set_capability($membershipA, RolesAndCapabilities::QUEUE_READ, 'deny');

        self::assertFalse($this->authz()->can($actor, $clinicA, RolesAndCapabilities::QUEUE_READ), 'deny in A');
        self::assertTrue($this->authz()->can($actor, $clinicB, RolesAndCapabilities::QUEUE_READ), 'allowed in B');

        $patientB = $this->insertPatient($clinicB);
        $clinicianB = $this->insertClinician($clinicB, 0, 'Dr P3S6B Multi B');
        $visitB = $this->insertVisit($clinicB, $locationB, $clinicianB, $patientB, 'waiting');

        $inA = $this->dispatch('GET', self::NS . '/queue', [], $clinicA, $actor);
        self::assertSame(403, $inA->get_status(), 'scoped deny in clinic A must deny the queue read there');

        $inB = $this->dispatch('GET', self::NS . '/queue', [], $clinicB, $actor);
        self::assertSame(200, $inB->get_status(), 'same actor must remain authorized in clinic B');
        $ids = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $inB->get_data()['data']['queue'] ?? []
        );
        self::assertContains($visitB, $ids, 'clinic B queue read must return the clinic B visit');
    }

    /**
     * Patient-self flows are NOT routed through staff Clinic authorization:
     * a patient (no staff membership at all) keeps reading/updating their own
     * profile, and public availability stays public.
     */
    public function testPatientSelfAndPublicFlowsUnaffected(): void
    {
        $clinicA = $this->clinics['A'];
        $patientId = $this->insertPatient($clinicA);

        $patientUser = $this->makeUser('p3s6b_self_pat', RolesAndCapabilities::ROLE_PATIENT);
        $this->linkPatientUser($clinicA, $patientId, $patientUser);
        self::assertNull(
            App::membership_service()->active_membership_for($clinicA, $patientUser),
            'precondition: patient has NO staff membership (patient-self is separate from staff membership)'
        );

        $me = $this->dispatch('GET', self::NS . '/patient/me', [], $clinicA, $patientUser);
        self::assertSame(200, $me->get_status(), 'patient-self /patient/me must not be routed through staff Clinic authorization');
        self::assertSame($patientId, (int) ($me->get_data()['data']['id'] ?? 0));

        // Public availability remains public (no authentication, no membership).
        wp_set_current_user(0);
        $request = new WP_REST_Request('GET', self::NS . '/availability');
        $request->set_param('clinician_id', $this->insertClinician($clinicA, 0, 'Dr P3S6B Public'));
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status(), 'public availability must remain public');
    }

    /**
     * Installation administrator WITHOUT Clinic membership has no automatic
     * queue access: denied at the trusted-context boundary (context is not
     * authorization; membership is not authorization).
     */
    public function testNoMembershipGlobalCapsDeniedAtTrustedContextBoundary(): void
    {
        $clinicA = $this->clinics['A'];
        $admin = $this->makeUser('p3s6b_nm_adm', 'administrator');
        $adminUser = get_userdata($admin);
        self::assertNotFalse($adminUser);
        $adminUser->add_cap(RolesAndCapabilities::QUEUE_READ);

        self::assertTrue(user_can($admin, RolesAndCapabilities::QUEUE_READ), 'precondition: global coarse cap present');
        self::assertNull(
            App::membership_service()->active_membership_for($clinicA, $admin),
            'precondition: no durable membership'
        );

        $res = $this->dispatch('GET', self::NS . '/queue', [], $clinicA, $admin);
        self::assertSame(403, $res->get_status(), 'no active membership => no trusted Clinic context => denied');
        $flat = wp_json_encode($res->get_data());
        self::assertStringNotContainsString('"queue"', (string) $flat, 'no queue rows may be exposed');
    }

    // ================================================================
    // Fixtures (dynamic, no first-row tenant, no fixed tenant ids)
    // ================================================================

    private function createOrganization(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore
            'P3S6B Org ' . $unique,
            'p3s6b-org-' . $unique,
            'active',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: organization inserted (' . $wpdb->last_error . ')');
        self::assertSame(
            'active',
            (string) $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = %d',
                $id
            )),
            'precondition: organization status is active'
        );

        return $id;
    }

    private function createClinic(int $orgId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics
                 (organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore
            $orgId,
            'P3S6B Clinic ' . $tag . ' ' . $unique,
            'p3s6b-clinic-' . $tag . '-' . $unique,
            'Asia/Tehran',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'precondition: clinic id is dynamic (never the seeded legacy clinic 1)');
        self::assertSame($orgId, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d',
            $id
        )), 'precondition: clinic bound to explicit organization');

        return $id;
    }

    private function createLocation(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                 (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
             VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore
            $clinicId,
            'P3S6B Loc ' . $unique,
            'p3s6b-loc-' . $unique,
            'Asia/Tehran',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: location inserted (' . $wpdb->last_error . ')');

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@p3s6b.test');
        self::assertGreaterThan(0, $userId, 'precondition: wp user ' . $login);
        $u = get_userdata($userId);
        self::assertNotFalse($u);
        $u->set_role($role);

        return $userId;
    }

    private function insertClinician(int $clinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $linkedUserId = $wpUserId > 0 ? $wpUserId : (int) self::factory()->user->create(['role' => 'subscriber']);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                 (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
             VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore
            $clinicId,
            $name,
            $linkedUserId,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: clinician row (' . $wpdb->last_error . ')');

        return $id;
    }

    private function insertPatient(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000000, 9999999);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore
            $clinicId,
            'MR-P3S6B-' . $seq,
            'P3S6B',
            'Pat' . $seq,
            '0912' . str_pad((string) ($seq % 10000000), 7, '0', STR_PAD_LEFT),
            'active',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: patient row (' . $wpdb->last_error . ')');

        return $id;
    }

    private function linkPatientUser(int $clinicId, int $patientId, int $wpUserId): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                 (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
             VALUES (%d, %d, %d, %s, 1, %s)', // phpcs:ignore
            $clinicId,
            $patientId,
            $wpUserId,
            '0912' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
            $now
        ));
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'precondition: patient link row');
    }

    private function insertVisit(int $clinicId, int $locationId, int $clinicianId, int $patientId, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        // Use Location-local operational date to match Today filtering
        $tz_row = $wpdb->get_var($wpdb->prepare('SELECT timezone FROM ' . $wpdb->prefix . 'cpms_locations WHERE id = %d', $locationId));
        $tz_name = is_string($tz_row) && $tz_row !== '' ? $tz_row : 'Asia/Tehran';
        try {
            $tz = new \DateTimeZone($tz_name);
            $date = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
        } catch ( \Throwable $e ) {
            $date = gmdate('Y-m-d');
        }
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                 (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date,
                  check_in_at, waiting_since, called_at, active, created_at, updated_at)
             VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore
            $clinicId,
            $locationId,
            $clinicianId,
            $patientId,
            'walk_in',
            $status,
            $date,
            $date . ' 10:00:00.000',
            $date . ' 10:00:00.000',
            $date . ' 10:05:00.000',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: visit row (' . $wpdb->last_error . ')');

        return $id;
    }

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function insertSlot(int $clinicId, int $locationId, int $clinicianId, int $dayOffset, string $time): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $date = gmdate('Y-m-d', time() + $dayOffset * 86400);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                 (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
             VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore
            $clinicId,
            $locationId,
            $clinicianId,
            $date,
            $time,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: slot row (' . $wpdb->last_error . ')');

        return ['id' => $id, 'date' => $date, 'time' => substr($time, 0, 5)];
    }

    private function insertAppointment(
        int $clinicId,
        int $locationId,
        int $patientId,
        int $clinicianId,
        array $slot
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $endTime = gmdate('H:i:s', strtotime($slot['time'] . ':00 +20 minutes'));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                 (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time,
                  duration_min, slot_end_time, status, confirmed_at, created_at, updated_at)
             VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, %s, %s, %s, %s)', // phpcs:ignore
            $clinicId,
            $locationId,
            'P3S6B-' . bin2hex(random_bytes(6)),
            $patientId,
            $clinicianId,
            (int) $slot['id'],
            $slot['date'],
            $slot['time'] . ':00',
            $endTime,
            'confirmed',
            $now,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: appointment row (' . $wpdb->last_error . ')');

        return $id;
    }

    // ================================================================
    // Durable-state probes
    // ================================================================

    private function authz(): AuthorizationService
    {
        return new AuthorizationService(new MembershipRepository(App::db()));
    }

    private function countPatientsInClinic(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d',
            [$clinicId]
        );
    }

    private function countVisitsForPatient(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d',
            [$patientId]
        );
    }

    private function countVisitHistory(int $visitId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d',
            [$visitId]
        );
    }

    private function visitStatus(int $visitId): string
    {
        return (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visitId]
        );
    }

    private function countSchedulesForClinician(int $clinicianId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule') . ' WHERE clinician_id = %d',
            [$clinicianId]
        );
    }

    private function countAppointmentsForPatient(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE patient_id = %d',
            [$patientId]
        );
    }

    private function appointmentStatus(int $appointmentId): string
    {
        return (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$appointmentId]
        );
    }

    private function slotBookedCount(int $slotId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT booked_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        );
    }

    /**
     * Dispatch a real REST request as $userId with an explicit trusted Clinic
     * header (the same path production staff requests take).
     *
     * @param array<string, mixed> $params
     */
    private function dispatch(string $method, string $route, array $params, int $clinicId, int $userId): WP_REST_Response
    {
        wp_set_current_user($userId);
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);

        return rest_do_request($request);
    }

    /**
     * Cleanup بر اساس اسکیمای واقعی (زیرساخت تست) — فقط ردیف‌های Organization
     * خود این تست؛ فرزندانِ دارای FK اول حذف می‌شوند (تاریخچهٔ ویزیت join می‌شود
     * چون ستون clinic_id ندارد).
     */
    private function purgeRows(): void
    {
        global $wpdb;
        $org = (int) $this->orgId;
        $wpdb->query('DELETE h FROM ' . $wpdb->prefix . 'cpms_visit_status_history h
                      JOIN ' . $wpdb->prefix . 'cpms_visits v ON v.id = h.visit_id
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = v.clinic_id
                      WHERE c.organization_id = ' . $org); // phpcs:ignore
        foreach ([
            'cpms_notifications',
            'cpms_appointments',
            'cpms_visits',
            'cpms_schedule_slots',
            'cpms_schedule',
            'cpms_clinicians',
            'cpms_patient_user_links',
            'cpms_patients',
            'cpms_clinic_memberships',
            'cpms_locations',
        ] as $table) {
            $wpdb->query('DELETE t FROM ' . $wpdb->prefix . $table . ' t
                          WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore
        }
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = ' . $org); // phpcs:ignore
    }
}
