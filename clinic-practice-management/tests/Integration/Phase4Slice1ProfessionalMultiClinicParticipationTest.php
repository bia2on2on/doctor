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
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Phase 4 — Slice 1: Professional Identity Multi-Clinic Participation.
 *
 * CONTRACT UNDER TEST (professional/clinician identity, NOT actor authorization):
 *
 *  1. One WP user keeps ONE professional (clinician) identity — `u_clinician_user`
 *     (Migration 0007) is preserved; no duplicate profile may be created merely
 *     because the same professional also works in another Clinic.
 *  2. A professional may participate in several Clinics through durable ACTIVE
 *     `cpms_clinic_memberships`. `cpms_clinicians.clinic_id` is compatibility /
 *     HOME-Clinic data (documented target model, `phase0.5-target-model.md`
 *     §د-۶-۳) — it is NOT the participation/authorization boundary.
 *  3. Therefore a professional with durable ACTIVE membership in Clinic B must be
 *     usable in Clinic B through the REAL production schedule path (REST create,
 *     REST scoped read, and the `slots.generate` operational sweep), while the
 *     Clinic-A compatibility column stays untouched.
 *  4. Without an ACTIVE membership in Clinic B the same path must FAIL CLOSED
 *     with the existing 404 parity envelope (`CLINIC_NOT_FOUND` — «پزشک یافت نشد»)
 *     and zero durable side effects.
 *  5. Phase 3 authorization is unchanged: the actor still needs an ACTIVE
 *     membership in the trusted Clinic + the exact scoped permission
 *     (`cpms_config` here) — the professional's participation never authorizes
 *     the actor.
 *  6. Raw payload/header tenant values are never trusted: the trusted Clinic comes
 *     only from the established Phase 3 scope path (`RestClinicContext` →
 *     `TrustedClinicEstablisher`); a conflicting payload `clinic_id` is rejected.
 *
 * EXPECTED RED ON DEFECTIVE MAIN (the ownership assumption is the defect):
 *  - `ScheduleService::requireClinicianForTrustedClinic()` /
 *    `requireClinicianWithinTrustedClinic()` compare the trusted Clinic with
 *    `cpms_clinicians.clinic_id` (the HOME Clinic) and reject the request with
 *    `CLINIC_NOT_FOUND` (404) — so tests 1..3 are RED.
 *  - `SlotsGenerateHandler` skips every schedule row whose `clinic_id` differs
 *    from `clinicians.clinic_id` (`SLOTS_GEN_SKIP_CLINIC_MISMATCH`) and scopes
 *    Schedule exceptions by clinician only — so test 4 is RED (zero slots in B,
 *    and Clinic A's exception would suppress Clinic B generation).
 *
 * Fixture rules (no first-row tenant, no fixed tenant ids, no fixture bypass):
 *  - dynamic Organization (status=active) + dynamically created Clinics whose ids
 *    are asserted to be > 1 (never the seeded legacy Clinic 1);
 *  - one WP user ↔ exactly one `cpms_clinicians` row (asserted);
 *  - durable memberships created through the production primitive
 *    (`cpms_test_seed_membership` → `MembershipService::create_membership`);
 *  - the trusted Clinic is always established through the real staff REST boundary
 *    (`X-CPMS-Clinic-Id`), never injected into the service call;
 *  - authorization is never bypassed to reach the product path.
 */
final class Phase4Slice1ProfessionalMultiClinicParticipationTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    /** One temporal frame for both Clinics — timezone semantics are NOT under test here. */
    private const LOCATION_TZ = 'Asia/Tehran';

    private int $orgId = 0;

    /** @var array<string, int> clinic tag => clinic id */
    private array $clinics = [];

    /** @var array<int, int> clinic id => primary location id */
    private array $locations = [];

    /** The single professional identity used by most tests. */
    private int $professionalUserId = 0;
    private int $clinicianId = 0;

    /** Staff actor that legitimately holds `cpms_config` in the trusted Clinic. */
    private int $managerUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $this->orgId = $this->createOrganization();
        $this->clinics['A'] = $this->createClinic('A');
        $this->clinics['B'] = $this->createClinic('B');
        $this->locations[$this->clinics['A']] = $this->createLocation($this->clinics['A']);
        $this->locations[$this->clinics['B']] = $this->createLocation($this->clinics['B']);
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
    // 1 + A + C + D — write path: participation through durable membership
    // ==================================================================

    /**
     * The CORE acceptance contract: the SAME professional identity (ONE clinician
     * row, compatibility home Clinic = A) with durable ACTIVE membership in Clinic
     * B is usable in Clinic B through the real REST schedule-create path, while the
     * Clinic A behavior (control) keeps working and no second identity appears.
     */
    public function testProfessionalWithActiveMembershipInClinicBIsUsableThroughScheduleCreatePath(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedManager([$clinicA, $clinicB]);

        // ---- Fixture assertions (a failed insertion is NOT a RED) ----
        self::assertGreaterThan(1, $clinicA, 'precondition: Clinic A id is dynamic (never seeded Clinic 1)');
        self::assertGreaterThan(1, $clinicB, 'precondition: Clinic B id is dynamic (never seeded Clinic 1)');
        self::assertNotSame($clinicA, $clinicB, 'precondition: two distinct Clinics');
        self::assertSame(
            1,
            $this->countClinicianRowsForUser($this->professionalUserId),
            'precondition: exactly ONE clinician/professional identity exists for this WP user'
        );
        self::assertSame(
            $clinicA,
            $this->clinicianHomeClinic($this->clinicianId),
            'precondition: compatibility clinicians.clinic_id = Clinic A (home Clinic)'
        );
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicA, $this->professionalUserId),
            'precondition: durable ACTIVE membership of the professional in Clinic A'
        );
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicB, $this->professionalUserId),
            'precondition: durable ACTIVE membership of the professional in Clinic B'
        );
        self::assertTrue(
            $this->authz()->can($this->managerUserId, $clinicB, RolesAndCapabilities::CONFIG),
            'precondition: requesting actor holds the already-required Phase 3 permission in Clinic B'
        );
        self::assertTrue(
            $this->authz()->can($this->managerUserId, $clinicA, RolesAndCapabilities::CONFIG),
            'precondition: requesting actor holds the same Phase 3 permission in Clinic A'
        );

        // ---- Control (D): the pre-existing home-Clinic behavior keeps working ----
        $controlA = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 2,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $clinicA, $this->managerUserId);
        self::assertSame(
            200,
            $controlA->get_status(),
            'CONTROL: creating the weekly schedule in the professional\'s home Clinic A must keep working '
                . '(error=' . $this->errorCode($controlA) . ')'
        );
        $controlId = (int) ($controlA->get_data()['data']['id'] ?? 0);
        self::assertGreaterThan(0, $controlId, 'CONTROL: Clinic A schedule row id returned');
        self::assertSame($clinicA, $this->scheduleClinicId($controlId), 'CONTROL: Clinic A row keeps clinic_id = A');
        self::assertSame(
            (int) $this->locations[$clinicA],
            $this->scheduleLocationId($controlId),
            'CONTROL: Clinic A row is bound to Clinic A\'s Location'
        );

        // ---- CONTRACT: the same professional is usable in Clinic B ----
        $createB = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 5,
            'start_time' => '14:00',
            'end_time' => '18:00',
        ], $clinicB, $this->managerUserId);
        $this->assertOwnershipRejectionIsTheOnlyPreFixFailure($createB, 'Clinic B schedule create');

        self::assertSame(
            200,
            $createB->get_status(),
            'CONTRACT: a professional with durable ACTIVE membership in Clinic B must be usable in Clinic B. '
                . 'Defective main rejects this with 404 ' . $this->errorCode($createB)
                . ' because clinicians.clinic_id (home Clinic A) is treated as the participation boundary.'
        );
        $scheduleIdB = (int) ($createB->get_data()['data']['id'] ?? 0);
        self::assertGreaterThan(0, $scheduleIdB, 'CONTRACT: Clinic B schedule row id returned');
        self::assertSame(
            (int) $this->clinicianId,
            (int) ($createB->get_data()['data']['clinician_id'] ?? 0),
            'CONTRACT: the created Clinic B row references the SAME professional identity'
        );
        self::assertSame(
            $clinicB,
            $this->scheduleClinicId($scheduleIdB),
            'CONTRACT: the durable row is owned by the trusted Clinic B — no tenant inference from '
                . 'first row / home Clinic / fixed id'
        );
        self::assertSame(
            (int) $this->locations[$clinicB],
            $this->scheduleLocationId($scheduleIdB),
            'CONTRACT: the Clinic B row is bound to Clinic B\'s Location (AD-15)'
        );

        // ---- C: still exactly one professional identity; home column untouched ----
        self::assertSame(
            1,
            $this->countClinicianRowsForUser($this->professionalUserId),
            'CONTRACT: no duplicate professional identity may be created for another Clinic'
        );
        self::assertSame(
            $clinicA,
            $this->clinicianHomeClinic($this->clinicianId),
            'CONTRACT: compatibility clinicians.clinic_id must stay Clinic A (historical ownership unchanged)'
        );
        self::assertSame(
            2,
            $this->countMembershipsForUser($this->professionalUserId),
            'CONTRACT: the schedule path must not create/modify durable participation'
        );
    }

    /**
     * Real-world weekday overlap: the same professional may hold a weekly schedule
     * on the SAME weekday in two different Clinics — Migration 0014 already
     * redefined uniqueness to `(clinic_id, location_id, clinician_id, day_of_week,
     * start_time)` exactly for that ("چند شعبه در یک روز"), so the application-level
     * pre-check must be evaluated inside the trusted Clinic, not across the
     * professional's whole identity. The one-row-per-weekday rule WITHIN a Clinic
     * stays in force.
     */
    public function testSameProfessionalMayHoldTheSameWeekdayInAnotherClinic(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedManager([$clinicA, $clinicB]);

        $inA = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 3,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $clinicA, $this->managerUserId);
        self::assertSame(200, $inA->get_status(), 'CONTROL: Clinic A weekday row created');

        $inB = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 3,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $clinicB, $this->managerUserId);
        $this->assertOwnershipRejectionIsTheOnlyPreFixFailure($inB, 'Clinic B same-weekday create');
        self::assertSame(
            200,
            $inB->get_status(),
            'CONTRACT: the same professional must be schedulable on the same weekday in another Clinic '
                . 'where durable ACTIVE membership exists (error=' . $this->errorCode($inB) . ')'
        );

        self::assertSame(1, $this->countScheduleRows($this->clinicianId, $clinicA), 'Clinic A keeps exactly one row for that weekday');
        self::assertSame(1, $this->countScheduleRows($this->clinicianId, $clinicB), 'Clinic B holds its own row for the same weekday');
        self::assertSame(1, $this->countClinicianRowsForUser($this->professionalUserId), 'still ONE professional identity');

        // The pre-existing per-Clinic rule is preserved (not removed, not weakened).
        $duplicateInB = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 3,
            'start_time' => '16:00',
            'end_time' => '20:00',
        ], $clinicB, $this->managerUserId);
        self::assertSame(400, $duplicateInB->get_status(), 'duplicate weekday WITHIN the same Clinic must still be rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($duplicateInB), 'duplicate rejection keeps its stable code');
        // Envelope shape of a WP_Error serialized by the REST server:
        // ['code' => …, 'message' => …, 'data' => ['errors' => …, 'status' => …]]
        // (same accessor contract as RestScheduleTest::assertClinicError's data.status).
        $envelope = $this->errorEnvelope($duplicateInB);
        self::assertSame(
            'duplicate_schedule_day',
            (string) ($envelope['data']['errors']['day_of_week'] ?? ''),
            'duplicate rejection keeps its machine-readable reason; envelope=' . wp_json_encode($envelope)
        );
        self::assertSame(1, $this->countScheduleRows($this->clinicianId, $clinicB), 'rejected duplicate created no row');
    }

    // ==================================================================
    // 1 + F — read path: scoped to the trusted Clinic
    // ==================================================================

    /**
     * Once cross-Clinic participation is legitimate, the scoped read must expose
     * ONLY the trusted Clinic's rows for that professional: Clinic A's weekly
     * schedule must never appear in a Clinic B context (and vice versa). The rows
     * are seeded durably and read through the real REST list route.
     */
    public function testScopedScheduleReadReturnsOnlyTheTrustedClinicRowsForTheSharedProfessional(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedManager([$clinicA, $clinicB]);

        $rowA = $this->insertScheduleRow($clinicA, (int) $this->locations[$clinicA], 1, '09:00:00', '12:00:00');
        $rowB = $this->insertScheduleRow($clinicB, (int) $this->locations[$clinicB], 2, '14:00:00', '18:00:00');
        self::assertGreaterThan(0, $rowA, 'precondition: durable Clinic A schedule row');
        self::assertGreaterThan(0, $rowB, 'precondition: durable Clinic B schedule row');
        self::assertSame(
            2,
            $this->countScheduleRowsForClinician($this->clinicianId),
            'precondition: the professional durably holds schedule rows in two Clinics'
        );

        $readA = $this->dispatch('GET', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
        ], $clinicA, $this->managerUserId);
        $this->assertOwnershipRejectionIsTheOnlyPreFixFailure($readA, 'Clinic A scoped read');
        self::assertSame(200, $readA->get_status(), 'Clinic A scoped read must return the professional\'s Clinic A schedule');
        $idsA = $this->scheduleIdsFromResponse($readA);
        self::assertContains($rowA, $idsA, 'Clinic A read must contain the Clinic A row');
        self::assertNotContains($rowB, $idsA, 'Clinic A read must NOT leak the Clinic B row of the same professional');

        $readB = $this->dispatch('GET', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
        ], $clinicB, $this->managerUserId);
        $this->assertOwnershipRejectionIsTheOnlyPreFixFailure($readB, 'Clinic B scoped read');
        self::assertSame(200, $readB->get_status(), 'Clinic B scoped read must return the professional\'s Clinic B schedule');
        $idsB = $this->scheduleIdsFromResponse($readB);
        self::assertContains($rowB, $idsB, 'Clinic B read must contain the Clinic B row');
        self::assertNotContains($rowA, $idsB, 'Clinic B read must NOT leak the Clinic A row of the same professional');
    }

    // ==================================================================
    // 3 — operational use: the real slots.generate sweep
    // ==================================================================

    /**
     * Lifecycle step "operational use": a schedule row owned by Clinic B for a
     * professional who durably participates in Clinic B must actually generate
     * Clinic B slots through the REAL production sweep (enqueue → runTick →
     * dispatcher → `SlotsGenerateHandler`).
     *
     * The same fixture also proves exception scoping: a holiday exception created
     * in Clinic A for that date must keep closing Clinic A's own day (preserved
     * semantics) and must NOT suppress Clinic B's generation for the same date.
     */
    public function testSlotsGenerateHonorsParticipatingProfessionalInClinicBAndScopesExceptions(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedManager([$clinicA, $clinicB]);

        $locationA = (int) $this->locations[$clinicA];
        $locationB = (int) $this->locations[$clinicB];

        $this->setClinicHorizon($clinicA, 7);
        $this->setClinicHorizon($clinicB, 7);

        // Every weekday covered in BOTH Clinics → the per-date assertion measures
        // ownership/participation, never weekday coverage.
        for ($dow = 0; $dow <= 6; $dow++) {
            self::assertGreaterThan(
                0,
                $this->insertScheduleRow($clinicA, $locationA, $dow, '09:00:00', '10:00:00'),
                'precondition: Clinic A schedule row for weekday ' . $dow
            );
            self::assertGreaterThan(
                0,
                $this->insertScheduleRow($clinicB, $locationB, $dow, '11:00:00', '12:00:00'),
                'precondition: Clinic B schedule row for weekday ' . $dow
            );
        }

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $targetDate = $before->setTimezone(new DateTimeZone(self::LOCATION_TZ))->modify('+1 day')->format('Y-m-d');

        // Clinic A closes that day for this clinician — a Clinic-A-local fact.
        self::assertGreaterThan(
            0,
            $this->insertExceptionRow($clinicA, $this->clinicianId, $targetDate, 'holiday', $this->managerUserId),
            'precondition: Clinic A holiday exception inserted'
        );

        $after = $this->runProductionSlotSweep();
        if ($before->setTimezone(new DateTimeZone(self::LOCATION_TZ))->format('Y-m-d')
            !== $after->setTimezone(new DateTimeZone(self::LOCATION_TZ))->format('Y-m-d')) {
            self::markTestSkipped('INCONCLUSIVE (not a product RED): the Location-local calendar date changed during the sweep');
        }

        self::assertSame(
            0,
            $this->countSlots($this->clinicianId, $clinicA, $locationA, $targetDate),
            'PRESERVED SEMANTICS: the Clinic A holiday exception must still close Clinic A\'s own generated day'
        );
        $slotsInB = $this->countSlots($this->clinicianId, $clinicB, $locationB, $targetDate);
        self::assertGreaterThan(
            0,
            $slotsInB,
            'CONTRACT (operational use): a Clinic B schedule row belonging to a professional who durably '
                . 'participates in Clinic B must generate Clinic B slots. Defective main generates 0: '
                . 'SlotsGenerateHandler skips every row whose clinic_id differs from clinicians.clinic_id '
                . '(home Clinic A) and scopes exceptions by clinician only.'
        );
    }

    // ==================================================================
    // 2 — B/F: no ACTIVE participation in the trusted Clinic ⇒ fail closed
    // ==================================================================

    /**
     * Fail-closed parity: a professional WITHOUT durable membership in Clinic B
     * must be indistinguishable from a missing professional in Clinic B's context
     * (404 parity) even though the requesting actor IS fully authorized in B — and
     * with zero durable side effects.
     */
    public function testProfessionalWithoutMembershipInTrustedClinicFailsClosedWithExistingParity(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA]);
        $this->seedManager([$clinicA, $clinicB]);

        self::assertNull(
            App::membership_service()->active_membership_for($clinicB, $this->professionalUserId),
            'precondition: the professional has NO durable membership in Clinic B'
        );
        self::assertTrue(
            $this->authz()->can($this->managerUserId, $clinicB, RolesAndCapabilities::CONFIG),
            'precondition: the actor is legitimately authorized in Clinic B (denial is about participation, not the actor)'
        );

        $before = $this->countScheduleRows($this->clinicianId, $clinicB);
        $response = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 4,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $clinicB, $this->managerUserId);

        self::assertSame(404, $response->get_status(), 'cross-Clinic use without durable ACTIVE participation must fail closed with 404 parity');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response), 'existing fail-closed envelope preserved');
        self::assertSame(
            $before,
            $this->countScheduleRows($this->clinicianId, $clinicB),
            'denied cross-Clinic use must leave zero durable side effects'
        );
        self::assertSame(1, $this->countClinicianRowsForUser($this->professionalUserId), 'no duplicate identity created as a fallback');
    }

    /**
     * SUSPENDED (durable but inactive) participation must deny exactly like missing
     * participation — the professional identity is never silently re-homed.
     */
    public function testSuspendedMembershipInTrustedClinicFailsClosed(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedManager([$clinicA, $clinicB]);

        $membershipB = App::membership_service()->membership_for($clinicB, $this->professionalUserId);
        self::assertNotNull($membershipB, 'precondition: durable Clinic B membership exists');
        App::membership_service()->suspend_membership((int) $membershipB['id']);
        self::assertNull(
            App::membership_service()->active_membership_for($clinicB, $this->professionalUserId),
            'precondition: Clinic B membership is suspended (inactive)'
        );

        $response = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 6,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $clinicB, $this->managerUserId);

        self::assertSame(404, $response->get_status(), 'inactive durable participation must fail closed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response), 'suspension uses the same fail-closed envelope as missing participation');
        self::assertSame(0, $this->countScheduleRows($this->clinicianId, $clinicB), 'suspended participation created no Clinic B row');

        // The home Clinic keeps working (no behavior removal for the existing path).
        $home = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 6,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $clinicA, $this->managerUserId);
        self::assertSame(200, $home->get_status(), 'PRESERVED: the home Clinic A path is unaffected by Clinic B suspension');
    }

    // ==================================================================
    // E — Phase 3 authorization is intact (participation ≠ authorization)
    // ==================================================================

    /**
     * The professional's legitimate participation in Clinic B must never authorize
     * an actor that Phase 3 denies: (a) scoped DENY of `cpms_config`, (b) global
     * capability without any Clinic B membership.
     */
    public function testPhase3DeniesUnauthorizedActorsEvenWhenTheProfessionalParticipates(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);

        // (a) scoped explicit DENY on cpms_config in Clinic B
        $deniedActor = $this->makeUser('p4s1_deny_mgr', RolesAndCapabilities::ROLE_MANAGER);
        $deniedMembership = cpms_test_seed_membership($deniedActor, $clinicB, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->set_capability($deniedMembership, RolesAndCapabilities::CONFIG, 'deny');
        self::assertTrue(user_can($deniedActor, RolesAndCapabilities::CONFIG), 'precondition: global coarse cpms_config present');
        self::assertNotNull(App::membership_service()->active_membership_for($clinicB, $deniedActor), 'precondition: ACTIVE membership');
        self::assertFalse($this->authz()->can($deniedActor, $clinicB, RolesAndCapabilities::CONFIG), 'precondition: scoped cpms_config DENIED');

        $responseDenied = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 0,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $clinicB, $deniedActor);
        self::assertSame(403, $responseDenied->get_status(), 'scoped deny must still forbid the Clinic B schedule write');
        self::assertSame(0, $this->countScheduleRows($this->clinicianId, $clinicB), 'denied actor created no row');

        // (b) global capability (WP administrator) but NO Clinic B membership
        $noMembershipActor = $this->makeUser('p4s1_nm_adm', 'administrator');
        self::assertTrue(user_can($noMembershipActor, RolesAndCapabilities::CONFIG), 'precondition: global coarse cpms_config present');
        self::assertNull(App::membership_service()->active_membership_for($clinicB, $noMembershipActor), 'precondition: NO Clinic B membership');

        $responseNoMembership = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $clinicB, $noMembershipActor);
        self::assertSame(403, $responseNoMembership->get_status(), 'no durable membership ⇒ no trusted Clinic B context ⇒ denied');
        self::assertSame(0, $this->countScheduleRows($this->clinicianId, $clinicB), 'unauthorized actor created no row');
    }

    /**
     * F — the trusted Clinic never comes from the payload: a request whose clinic
     * header and `clinic_id` parameter disagree is rejected by the established
     * Phase 3 boundary, and no tenant is inferred from the payload, the home
     * Clinic, the first row, or a fixed id.
     */
    public function testRawPayloadClinicIdIsNeverTrustedAsTheRequestScope(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedManager([$clinicA, $clinicB]);

        $conflicting = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'clinic_id' => $clinicA, // payload attempts to move the tenant
            'day_of_week' => 2,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $clinicB, $this->managerUserId);

        self::assertNotSame(200, $conflicting->get_status(), 'a conflicting payload clinic_id must never be accepted');
        self::assertSame(422, $conflicting->get_status(), 'existing Phase 3 disagreement envelope preserved (422)');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($conflicting), 'stable machine-readable code preserved');
        self::assertSame(0, $this->countScheduleRows($this->clinicianId, $clinicA), 'no row written into the payload Clinic');
        self::assertSame(0, $this->countScheduleRows($this->clinicianId, $clinicB), 'no row written into the header Clinic');

        // Same trusted path, consistent identifiers ⇒ exactly the trusted Clinic is used.
        $consistent = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'clinic_id' => $clinicB,
            'day_of_week' => 2,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $clinicB, $this->managerUserId);
        $this->assertOwnershipRejectionIsTheOnlyPreFixFailure($consistent, 'consistent payload/header create');
        self::assertSame(200, $consistent->get_status(), 'consistent identifiers must succeed through the same path');
        self::assertSame(
            $clinicB,
            $this->scheduleClinicId((int) ($consistent->get_data()['data']['id'] ?? 0)),
            'the durable row is bound to the trusted Clinic, not to a fixed/first Clinic'
        );
        self::assertSame(0, $this->countScheduleRows($this->clinicianId, $clinicA), 'nothing was written into Clinic A');
    }

    // ==================================================================
    // RED diagnostics
    // ==================================================================

    /**
     * RED diagnostic (does NOT weaken the contract): on defective main the request
     * passes nonce + capability + Phase 3 scoped authorization + trusted-scope
     * establishment and is rejected INSIDE the production service by the durable
     * ownership check. Asserting that shape proves the observed failure is the
     * ownership assumption — not a bootstrap/fixture/authentication failure.
     */
    private function assertOwnershipRejectionIsTheOnlyPreFixFailure(WP_REST_Response $response, string $context): void
    {
        if ($response->get_status() === 200) {
            return;
        }

        self::assertSame(
            'CLINIC_NOT_FOUND',
            $this->errorCode($response),
            $context . ': pre-fix rejection must be the durable-ownership envelope — any other code means the '
                . 'product path was not reached (auth/scope/fixture failure, NOT this RED)'
        );
        self::assertSame(
            404,
            $response->get_status(),
            $context . ': ownership rejection must keep the existing 404 parity'
        );
    }

    // ==================================================================
    // Fixtures (dynamic ids only — no first-row tenant, no fixed tenant id)
    // ==================================================================

    /**
     * ONE WP user ⇒ ONE clinician/professional identity (home Clinic = A) plus the
     * requested durable ACTIVE memberships, created through the production
     * membership primitive.
     *
     * @param list<int> $membershipClinics
     */
    private function seedProfessional(array $membershipClinics): void
    {
        $this->professionalUserId = $this->makeUser('p4s1_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $this->clinicianId = $this->insertClinician($this->clinics['A'], $this->professionalUserId, 'Dr P4S1 Professional');

        foreach ($membershipClinics as $clinicId) {
            self::assertGreaterThan(
                0,
                cpms_test_seed_membership($this->professionalUserId, (int) $clinicId, RolesAndCapabilities::ROLE_DOCTOR),
                'fixture: durable membership created in Clinic ' . (int) $clinicId
            );
        }
    }

    /**
     * Staff actor that legitimately holds the already-required Phase 3 permission
     * (`cpms_config` preset of the manager role) in the requested Clinics.
     *
     * @param list<int> $membershipClinics
     */
    private function seedManager(array $membershipClinics): void
    {
        $this->managerUserId = $this->makeUser('p4s1_mgr', RolesAndCapabilities::ROLE_MANAGER);
        foreach ($membershipClinics as $clinicId) {
            self::assertGreaterThan(
                0,
                cpms_test_seed_membership($this->managerUserId, (int) $clinicId, RolesAndCapabilities::ROLE_MANAGER),
                'fixture: manager membership created in Clinic ' . (int) $clinicId
            );
        }
    }

    private function createOrganization(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore
            'P4S1 Org ' . $unique,
            'p4s1-org-' . $unique,
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

    private function createClinic(string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics
                 (organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore
            $this->orgId,
            'P4S1 Clinic ' . $tag . ' ' . $unique,
            'p4s1-clinic-' . strtolower($tag) . '-' . $unique,
            self::LOCATION_TZ,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'precondition: clinic id is dynamic (never the seeded legacy clinic 1)');
        self::assertSame(
            $this->orgId,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d',
                $id
            )),
            'precondition: clinic bound to the explicit organization'
        );

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
            'P4S1 Loc ' . $unique,
            'p4s1-loc-' . $unique,
            self::LOCATION_TZ,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: primary location inserted (' . $wpdb->last_error . ')');

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@p4s1.test');
        self::assertGreaterThan(0, $userId, 'precondition: wp user ' . $login);
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $userId;
    }

    private function insertClinician(int $clinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                 (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
             VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore
            $clinicId,
            $name,
            $wpUserId,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: clinician row (' . $wpdb->last_error . ')');

        return $id;
    }

    private function insertScheduleRow(
        int $clinicId,
        int $locationId,
        int $dayOfWeek,
        string $startTime,
        string $endTime
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule
                 (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time,
                  appointment_duration_min, slot_capacity, is_active, created_at, updated_at)
             VALUES (%d, %d, %d, %d, %s, %s, 30, 1, 1, %s, %s)', // phpcs:ignore
            $clinicId,
            $locationId,
            $this->clinicianId,
            $dayOfWeek,
            $startTime,
            $endTime,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        if ($id <= 0) {
            self::fail('fixture: schedule row insert failed (' . $wpdb->last_error . ')');
        }

        return $id;
    }

    private function insertExceptionRow(int $clinicId, int $clinicianId, string $date, string $type, int $actorUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_exceptions
                 (clinic_id, clinician_id, date, type, reason, created_by_wp_user_id, created_at)
             VALUES (%d, %d, %s, %s, %s, %d, %s)', // phpcs:ignore
            $clinicId,
            $clinicianId,
            $date,
            $type,
            'P4S1 fixture exception',
            $actorUserId,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        if ($id <= 0) {
            self::fail('fixture: schedule exception insert failed (' . $wpdb->last_error . ')');
        }

        return $id;
    }

    /**
     * Per-Clinic horizon with the SAME production settings key the sweep resolves
     * per row (no ambient scope, no Clinic 1 default).
     */
    private function setClinicHorizon(int $clinicId, int $days): void
    {
        Settings::flushCache();
        (new Settings(App::db(), $clinicId, App::audit()))->set('booking.max_future_days', $days);
        Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        App::settingsFactory()->reset();
        self::assertSame(
            $days,
            (int) (new Settings(App::db(), $clinicId, App::audit()))->get('booking.max_future_days', 30),
            'precondition: per-Clinic horizon persisted'
        );
    }

    // ==================================================================
    // Execution + durable-state probes
    // ==================================================================

    /**
     * REAL production operational path: enqueue → App::runTick → dispatcher →
     * SlotsGenerateHandler.
     */
    private function runProductionSlotSweep(): DateTimeImmutable
    {
        $queue = App::jobs();
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $jobId = $queue->enqueue('slots.generate', [], $before, 3, 1);
        self::assertGreaterThan(0, $jobId, 'precondition: slots.generate enqueued');

        App::runTick(20);

        $job = App::db()->fetchRow(
            'SELECT status, last_error FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
        self::assertSame(
            'success',
            (string) ($job['status'] ?? ''),
            'PRECONDITION (M-2 wiring): slots.generate must complete successfully through the real production path. '
                . 'last_error=' . (string) ($job['last_error'] ?? '')
        );

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Real staff REST boundary: authenticated actor + nonce + explicit trusted
     * Clinic header (the path production staff requests take).
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

    /**
     * بدنهٔ خام پاسخ خطا — همان شکل سریال‌شدهٔ WP_Error توسط REST server:
     * ['code' => …, 'message' => …, 'data' => [...]].
     *
     * @return array<string, mixed>
     */
    private function errorEnvelope(WP_REST_Response $response): array
    {
        $body = $response->get_data();
        if ($body instanceof WP_Error) {
            $data = $body->get_error_data();

            return [
                'code' => (string) $body->get_error_code(),
                'message' => (string) $body->get_error_message(),
                'data' => is_array($data) ? $data : [],
            ];
        }

        return is_array($body) ? $body : [];
    }

    /**
     * @return list<int>
     */
    private function scheduleIdsFromResponse(WP_REST_Response $response): array
    {
        $rows = $response->get_data()['data'] ?? [];
        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $ids[] = (int) ($row['id'] ?? 0);
            }
        }

        return $ids;
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

    private function countMembershipsForUser(int $wpUserId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE wp_user_id = %d',
            [$wpUserId]
        );
    }

    private function countScheduleRows(int $clinicianId, int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule') . ' WHERE clinician_id = %d AND clinic_id = %d',
            [$clinicianId, $clinicId]
        );
    }

    private function countScheduleRowsForClinician(int $clinicianId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule') . ' WHERE clinician_id = %d',
            [$clinicianId]
        );
    }

    private function scheduleClinicId(int $scheduleId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_schedule') . ' WHERE id = %d',
            [$scheduleId]
        );
    }

    private function scheduleLocationId(int $scheduleId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT location_id FROM ' . App::db()->table('cpms_schedule') . ' WHERE id = %d',
            [$scheduleId]
        );
    }

    private function countSlots(int $clinicianId, int $clinicId, int $locationId, string $date): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND location_id = %d AND slot_date = %s',
            [$clinicianId, $clinicId, $locationId, $date]
        );
    }

    /**
     * Cleanup by the real schema — only the rows of THIS test's Organization
     * (belt and braces on top of the WP test-suite transaction rollback).
     */
    private function purgeRows(): void
    {
        global $wpdb;
        $org = (int) $this->orgId;
        if ($org <= 0) {
            return;
        }
        foreach ([
            'cpms_schedule_slots',
            'cpms_schedule_exceptions',
            'cpms_schedule',
            'cpms_clinicians',
        ] as $table) {
            $wpdb->query('DELETE t FROM ' . $wpdb->prefix . $table . ' t
                          WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore
        }
        $wpdb->query('DELETE mc FROM ' . $wpdb->prefix . 'cpms_membership_capabilities mc
                      JOIN ' . $wpdb->prefix . 'cpms_clinic_memberships m ON m.id = mc.membership_id
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = m.clinic_id
                      WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE m FROM ' . $wpdb->prefix . 'cpms_clinic_memberships m
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = m.clinic_id
                      WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_clinician_locations t
                      JOIN ' . $wpdb->prefix . 'cpms_locations l ON l.id = t.location_id
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = l.clinic_id
                      WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_locations t
                      WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = ' . $org); // phpcs:ignore
    }
}
