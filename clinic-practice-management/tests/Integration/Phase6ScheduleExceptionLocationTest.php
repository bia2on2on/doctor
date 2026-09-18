<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Phase 6 Slice 6 — «دامنه‌بندی مکانیِ استثناهای برنامه» (Location scoping for
 * schedule exceptions: holiday / leave / blocked / open_override).
 *
 * CONTRACT UNDER TEST — exactly the contract Migration 0015 already documents
 * (`schedule_exceptions` nullable `location_id`, FK `fk_schedexc_location`):
 *
 *  1. `location_id = NULL` ⇒ the exception applies to EVERY Location of the
 *     trusted Clinic (historical rows are all NULL and must keep working); a
 *     NULL here is never a cross-Clinic or global scope.
 *  2. `location_id = <real id>` ⇒ the exception applies ONLY to that Location:
 *     an exception pinned to Location X must NEVER change slot generation for
 *     Location Y of the same Clinic (same professional, same date).
 *  3. A provided `location_id` must be real, ACTIVE and belong to the TRUSTED
 *     Clinic (which comes from server-side scope, never from the payload).
 *     Foreign / nonexistent / inactive all fail CLOSED with not-found parity
 *     (404 `CLINIC_NOT_FOUND`, identical envelope) and write NOTHING — no
 *     existence disclosure, no silent substitution, never the primary Location.
 *  4. `ScheduleService::exceptionView()` exposes the persisted nullable
 *     `location_id`.
 *  5. The wp-admin create form offers the trusted Clinic's Locations plus one
 *     explicit all-Locations choice, and the listing shows which Location each
 *     exception applies to.
 *  6. Clinic isolation is preserved: an exception of Clinic A never affects
 *     Clinic B's generation for the same professional.
 *  7. Exceptions stay deletable by explicit id with unchanged Clinic-scoped
 *     authorization semantics.
 *
 * PRE-FIX PRODUCT DEFECT (classification B — reverified live before writing):
 *  - `ScheduleRepository::EXCEPTION_CREATE_FIELDS` has no `location_id`, so a
 *    selector sent by an operator is silently dropped and the row is always
 *    persisted with `location_id = NULL`;
 *  - `SlotsGenerateHandler::generateDaySlots()` reads exceptions with
 *    `WHERE clinician_id = %d AND clinic_id = %d AND date = %s` (no Location
 *    predicate, `location_id` not even selected) and hands every row of that
 *    date to `SlotGenerator::generateDay()`, so a `leave` closes the
 *    professional's whole day at EVERY Location of the Clinic and an
 *    `open_override` leaks extra open slots into every Location;
 *  - `exceptionView()` does not expose `location_id`;
 *  - the wp-admin exception form/listing has no Location at all.
 *
 * FIXTURE RULES (no first-row tenant, no fixed tenant ids, no fixture bypass):
 *  - dynamic Organization + dynamically allocated Clinics/Locations whose ids
 *    are asserted to be > 1 (never the seeded legacy Clinic 1 / Location 1);
 *  - durable memberships through the production primitive
 *    (`cpms_test_seed_membership` → `MembershipService::create_membership`);
 *  - the trusted Clinic is established through the real staff boundary
 *    (`X-CPMS-Clinic-Id`) or an explicit `ClinicScope` for service-level
 *    assertions — never injected as payload authority;
 *  - material fixture insertions are asserted so a fixture failure reads as
 *    class D, never as a product RED.
 */
final class Phase6ScheduleExceptionLocationTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    /** Location/clinic timezone used by every fixture in this suite. */
    private const TZ = 'Asia/Tehran';

    /** Slot-generation horizon (offsets 1..7 from the Location-local today). */
    private const HORIZON = 7;

    /**
     * Operator-facing label of the explicit «all Locations of this Clinic»
     * (NULL) choice — the SAME literal the wp-admin boundary renders.
     */
    private const ALL_LOCATIONS_LABEL = 'همهٔ محل‌ها';

    private int $orgId = 0;

    /** Trusted Clinic. */
    private int $clinicA = 0;

    /** Foreign Clinic (owner of B1). */
    private int $clinicB = 0;

    /** A's primary ACTIVE Location. */
    private int $locA1 = 0;
    private string $locA1Name = '';

    /** A's secondary ACTIVE Location (non-primary — the point of the slice). */
    private int $locA2 = 0;
    private string $locA2Name = '';

    /** A's INACTIVE Location (must be rejected by the write contract). */
    private int $locAInactive = 0;

    /** B's primary ACTIVE Location (foreign to the trusted Clinic A). */
    private int $locB1 = 0;

    /** The single professional identity (home Clinic A, durable member of both). */
    private int $professionalUserId = 0;
    private int $clinicianId = 0;

    /** Staff actors with the already-required Phase 3 permission. */
    private int $managerAId = 0;
    private int $managerBId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $this->assertTimezoneAvailable();

        $this->orgId = $this->createOrganization();
        $this->clinicA = $this->createClinic('A');
        $this->clinicB = $this->createClinic('B');
        $primaryA = $this->createLocation($this->clinicA, 1, 1, 'A1');
        $secondaryA = $this->createLocation($this->clinicA, 0, 1, 'A2');
        $inactiveA = $this->createLocation($this->clinicA, 0, 0, 'A3');
        $primaryB = $this->createLocation($this->clinicB, 1, 1, 'B1');
        $this->locA1 = $primaryA['id'];
        $this->locA1Name = $primaryA['name'];
        $this->locA2 = $secondaryA['id'];
        $this->locA2Name = $secondaryA['name'];
        $this->locAInactive = $inactiveA['id'];
        $this->locB1 = $primaryB['id'];

        // ONE globally unique professional identity (home Clinic = A).
        $this->professionalUserId = $this->makeUser('p6s6_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $this->clinicianId = $this->insertClinician($this->clinicA, $this->professionalUserId, 'Dr P6S6 Professional');
        // Durable active participation in BOTH Clinics (SoT = cpms_clinic_memberships).
        self::assertGreaterThan(0, cpms_test_seed_membership($this->professionalUserId, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR));
        self::assertGreaterThan(0, cpms_test_seed_membership($this->professionalUserId, $this->clinicB, RolesAndCapabilities::ROLE_DOCTOR));

        $this->managerAId = $this->makeUser('p6s6_mgr_a', RolesAndCapabilities::ROLE_MANAGER);
        self::assertGreaterThan(0, cpms_test_seed_membership($this->managerAId, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER));
        $this->managerBId = $this->makeUser('p6s6_mgr_b', RolesAndCapabilities::ROLE_MANAGER);
        self::assertGreaterThan(0, cpms_test_seed_membership($this->managerBId, $this->clinicB, RolesAndCapabilities::ROLE_MANAGER));
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
    // Fixture topology — material insertions are asserted (a failed
    // insertion must be readable as a fixture failure, NOT a product RED).
    // ==================================================================

    public function testFixtureTopologyPreconditions(): void
    {
        self::assertGreaterThan(1, $this->clinicA, 'precondition: Clinic A id is dynamic (never the seeded legacy Clinic 1)');
        self::assertGreaterThan(1, $this->clinicB, 'precondition: Clinic B id is dynamic (never the seeded legacy Clinic 1)');
        self::assertNotSame($this->clinicA, $this->clinicB, 'precondition: two distinct Clinics');
        self::assertSame($this->orgId, $this->clinicOrganization($this->clinicA), 'precondition: Clinic A bound to the explicit organization');
        self::assertSame($this->orgId, $this->clinicOrganization($this->clinicB), 'precondition: Clinic B bound to the explicit organization');

        $a1 = $this->locationRow($this->locA1);
        $a2 = $this->locationRow($this->locA2);
        $a3 = $this->locationRow($this->locAInactive);
        $b1 = $this->locationRow($this->locB1);
        self::assertNotNull($a1, 'precondition: Location A1 row inserted');
        self::assertNotNull($a2, 'precondition: Location A2 row inserted');
        self::assertNotNull($a3, 'precondition: Location A3 row inserted');
        self::assertNotNull($b1, 'precondition: Location B1 row inserted');
        self::assertSame($this->clinicA, (int) $a1['clinic_id'], 'precondition: A1 belongs to Clinic A');
        self::assertSame(1, (int) $a1['is_primary'], 'precondition: A1 is A\'s primary Location');
        self::assertSame(1, (int) $a1['is_active'], 'precondition: A1 active');
        self::assertSame($this->clinicA, (int) $a2['clinic_id'], 'precondition: A2 belongs to Clinic A');
        self::assertSame(0, (int) $a2['is_primary'], 'precondition: A2 is NOT the primary Location (non-primary is the point)');
        self::assertSame(1, (int) $a2['is_active'], 'precondition: A2 active');
        self::assertSame($this->clinicA, (int) $a3['clinic_id'], 'precondition: A3 is an own Location of the trusted Clinic A');
        self::assertSame(0, (int) $a3['is_active'], 'precondition: A3 is INACTIVE (the fail-closed case of a Location of the trusted Clinic)');
        self::assertSame($this->clinicB, (int) $b1['clinic_id'], 'precondition: B1 belongs to the FOREIGN Clinic B');
        self::assertSame(1, (int) $b1['is_active'], 'precondition: B1 active');

        self::assertSame(1, $this->countClinicianRowsForUser($this->professionalUserId), 'precondition: exactly ONE professional identity for this WP user');
        self::assertSame($this->clinicA, $this->clinicianHomeClinic($this->clinicianId), 'precondition: compatibility home column = Clinic A');
        self::assertNotNull(
            App::membership_service()->active_membership_for($this->clinicA, $this->professionalUserId),
            'precondition: durable ACTIVE membership of the professional in Clinic A'
        );
        self::assertNotNull(
            App::membership_service()->active_membership_for($this->clinicB, $this->professionalUserId),
            'precondition: durable ACTIVE membership of the professional in Clinic B'
        );
        self::assertSame(
            0,
            $this->countExceptionRows($this->clinicianId, $this->clinicA),
            'precondition: no exception rows for this professional in Clinic A before the case'
        );
    }

    // ==================================================================
    // R1 — a `leave` pinned to Location A2 must close ONLY A2: the same
    //      professional/date at A1 stays intact.
    // ==================================================================

    public function testLeavePinnedToLocationClosesOnlyThatLocation(): void
    {
        $date = $this->localDateIn('+1 day');
        $dow = self::iranianDow($date);
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA1, $dow, '09:00:00', '10:00:00', 30), 'precondition: A1 schedule row inserted');
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA2, $dow, '09:00:00', '10:00:00', 30), 'precondition: A2 schedule row inserted');
        $this->setClinicHorizon($this->clinicA, self::HORIZON);

        $this->sweep();
        self::assertSame(['09:00', '09:30'], $this->slotTimes($this->clinicA, $this->locA1, $date), 'precondition: A1 slots generated before the exception');
        self::assertSame(['09:00', '09:30'], $this->slotTimes($this->clinicA, $this->locA2, $date), 'precondition: A2 slots generated before the exception');

        $view = $this->createExceptionViaService($this->clinicA, [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
            'type' => 'leave',
            'location_id' => $this->locA2,
            'reason' => 'P6S6 R1 pinned leave',
        ]);

        $this->sweep();

        self::assertSame(
            ['09:00', '09:30'],
            $this->slotTimes($this->clinicA, $this->locA1, $date),
            'R1 DEFECT: a `leave` pinned to Location A2 must leave the SAME Clinic\'s other Location (A1) '
            . 'INTACT for the same professional/date. Pre-fix the selector is silently dropped '
            . '(location_id = NULL) and SlotGenerator closes the whole day at every Location.'
        );
        self::assertSame(
            [],
            $this->slotTimes($this->clinicA, $this->locA2, $date),
            'R1: a `leave` pinned to Location A2 closes that Location\'s whole day'
        );
        self::assertSame(
            $this->locA2,
            (int) ($view['location_id'] ?? 0),
            'R1 (write contract): the created exception view must expose the persisted Location A2'
        );
        $row = $this->exceptionRow((int) $view['id']);
        self::assertNotNull($row, 'R1: durable exception row exists');
        self::assertSame($this->locA2, (int) $row['location_id'], 'R1 (write contract): durable location_id = A2 (never silently NULL)');
        self::assertSame($this->clinicA, (int) $row['clinic_id'], 'R1: durable row owned by the trusted Clinic A');
    }

    // ==================================================================
    // R2 — a `blocked` range pinned to Location A2 removes only A2's
    //      overlapping slots; A1 keeps its full grid.
    // ==================================================================

    public function testBlockedRangePinnedToLocationRemovesOnlyThatLocationSlots(): void
    {
        $date = $this->localDateIn('+1 day');
        $dow = self::iranianDow($date);
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA1, $dow, '09:00:00', '12:00:00', 60), 'precondition: A1 schedule row inserted');
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA2, $dow, '09:00:00', '12:00:00', 60), 'precondition: A2 schedule row inserted');
        $this->setClinicHorizon($this->clinicA, self::HORIZON);

        $this->sweep();
        self::assertSame(['09:00', '10:00', '11:00'], $this->slotTimes($this->clinicA, $this->locA1, $date), 'precondition: A1 base grid');
        self::assertSame(['09:00', '10:00', '11:00'], $this->slotTimes($this->clinicA, $this->locA2, $date), 'precondition: A2 base grid');

        $this->createExceptionViaService($this->clinicA, [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
            'type' => 'blocked',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'location_id' => $this->locA2,
            'reason' => 'P6S6 R2 pinned blocked',
        ]);

        $this->sweep();

        self::assertSame(
            ['09:00', '10:00', '11:00'],
            $this->slotTimes($this->clinicA, $this->locA1, $date),
            'R2 DEFECT: a `blocked` range pinned to Location A2 must remove ONLY A2\'s overlapping slots; '
            . 'A1 (same Clinic, same professional, same date) must keep its full grid. '
            . 'Pre-fix the range is applied to every Location of the Clinic.'
        );
        self::assertSame(
            ['09:00', '11:00'],
            $this->slotTimes($this->clinicA, $this->locA2, $date),
            'R2: the pinned Location A2 loses exactly the overlapping slot (10:00)'
        );
    }

    // ==================================================================
    // R3 — an `open_override` pinned to Location A1 adds the extra open
    //      slots ONLY at A1 (no leak into A2).
    // ==================================================================

    public function testOpenOverridePinnedToLocationAddsExtraSlotsOnlyAtThatLocation(): void
    {
        $date = $this->localDateIn('+1 day');
        $dow = self::iranianDow($date);
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA1, $dow, '09:00:00', '10:00:00', 30), 'precondition: A1 schedule row inserted');
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA2, $dow, '09:00:00', '10:00:00', 30), 'precondition: A2 schedule row inserted');
        $this->setClinicHorizon($this->clinicA, self::HORIZON);

        $this->sweep();
        self::assertSame(['09:00', '09:30'], $this->slotTimes($this->clinicA, $this->locA1, $date), 'precondition: A1 base grid');
        self::assertSame(['09:00', '09:30'], $this->slotTimes($this->clinicA, $this->locA2, $date), 'precondition: A2 base grid');

        $this->createExceptionViaService($this->clinicA, [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
            'type' => 'open_override',
            'start_time' => '12:00',
            'end_time' => '13:00',
            'location_id' => $this->locA1,
            'reason' => 'P6S6 R3 pinned open_override',
        ]);

        $this->sweep();

        self::assertSame(
            ['09:00', '09:30'],
            $this->slotTimes($this->clinicA, $this->locA2, $date),
            'R3 DEFECT: an `open_override` pinned to Location A1 must add extra open slots ONLY at A1. '
            . 'Pre-fix it leaks into every Location of the Clinic, so A2 gains slots outside its schedule.'
        );
        self::assertSame(
            ['09:00', '09:30', '12:00', '12:30'],
            $this->slotTimes($this->clinicA, $this->locA1, $date),
            'R3: the pinned Location A1 gains exactly the extra open range (12:00-13:00)'
        );
    }

    // ==================================================================
    // R4 — no-regression half: a NULL `leave` (created without a Location)
    //      AND a historical NULL `blocked` row (created before this slice,
    //      never touched by it) still apply to EVERY Location of the
    //      trusted Clinic.
    // ==================================================================

    public function testExceptionWithoutLocationStillAppliesToEveryLocationOfTrustedClinic(): void
    {
        $leaveDate = $this->localDateIn('+1 day');
        $blockedDate = $this->localDateIn('+2 days');
        $leaveDow = self::iranianDow($leaveDate);
        $blockedDow = self::iranianDow($blockedDate);

        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA1, $leaveDow, '09:00:00', '10:00:00', 30), 'precondition: A1 leave-day row');
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA2, $leaveDow, '09:00:00', '10:00:00', 30), 'precondition: A2 leave-day row');
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA1, $blockedDow, '09:00:00', '12:00:00', 60), 'precondition: A1 blocked-day row');
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA2, $blockedDow, '09:00:00', '12:00:00', 60), 'precondition: A2 blocked-day row');
        $this->setClinicHorizon($this->clinicA, self::HORIZON);

        // Historical row shape: inserted exactly like the pre-slice write path
        // (no location_id column at all ⇒ NULL) — never rewritten by this slice.
        $historicalId = $this->insertExceptionRowRaw(
            $this->clinicA,
            $blockedDate,
            'blocked',
            '10:00:00',
            '11:00:00',
            'P6S6 R4 historical NULL blocked'
        );
        self::assertGreaterThan(0, $historicalId, 'precondition: historical NULL blocked row inserted');
        $historical = $this->exceptionRow($historicalId);
        self::assertNotNull($historical, 'precondition: historical row readable');
        self::assertArrayHasKey('location_id', $historical, 'precondition: the 0015 column exists on the table');
        self::assertNull($historical['location_id'], 'precondition: the historical fixture row carries NULL (all-Locations)');

        $view = $this->createExceptionViaService($this->clinicA, [
            'clinician_id' => $this->clinicianId,
            'date' => $leaveDate,
            'type' => 'leave',
            'reason' => 'P6S6 R4 all-locations leave',
        ]);
        $leaveRow = $this->exceptionRow((int) $view['id']);
        self::assertNotNull($leaveRow, 'precondition: the no-Location leave row exists');
        self::assertNull($leaveRow['location_id'], 'precondition: omitting location_id persists NULL (all Locations of THIS Clinic)');

        $this->sweep();

        self::assertSame(
            [],
            $this->slotTimes($this->clinicA, $this->locA1, $leaveDate),
            'R4 (no regression): an all-Locations leave still closes the day at A1'
        );
        self::assertSame(
            [],
            $this->slotTimes($this->clinicA, $this->locA2, $leaveDate),
            'R4 (no regression): an all-Locations leave still closes the day at EVERY Location of the trusted Clinic (A2)'
        );
        self::assertSame(
            ['09:00', '11:00'],
            $this->slotTimes($this->clinicA, $this->locA1, $blockedDate),
            'R4 (no regression): a historical NULL blocked row still removes the overlapping slot at A1'
        );
        self::assertSame(
            ['09:00', '11:00'],
            $this->slotTimes($this->clinicA, $this->locA2, $blockedDate),
            'R4 (no regression): the historical NULL blocked row still removes the overlapping slot at EVERY Location (A2)'
        );

        $after = $this->exceptionRow($historicalId);
        self::assertNotNull($after, 'R4: the historical row is neither deleted nor rewritten');
        self::assertNull($after['location_id'], 'R4: the historical NULL row is left untouched (no backfill)');
        self::assertSame(
            2,
            $this->countExceptionRows($this->clinicianId, $this->clinicA),
            'R4: generation neither duplicates nor invents exception rows'
        );
    }

    // ==================================================================
    // R5 — a FOREIGN Location id fails CLOSED with the not-found parity
    //      envelope and writes NOTHING (service + real REST boundary).
    // ==================================================================

    public function testForeignLocationFailsClosedWithNotFoundParityAndWritesNothing(): void
    {
        $date = $this->localDateIn('+1 day');
        $before = $this->countExceptionRows($this->clinicianId, $this->clinicA);

        $thrown = $this->exceptionServiceError($this->clinicA, [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
            'type' => 'holiday',
            'location_id' => $this->locB1,
        ]);
        self::assertNotNull(
            $thrown,
            'R5 DEFECT: a Location owned by ANOTHER Clinic must fail closed at the core contract. '
            . 'Pre-fix the foreign selector is silently ignored and an all-Locations exception row IS written.'
        );
        self::assertSame('CLINIC_NOT_FOUND', $thrown->errorCode, 'R5: not-found parity code (no existence disclosure)');
        self::assertSame(404, $thrown->httpStatus, 'R5: not-found parity status');
        self::assertSame('محل یافت نشد', $thrown->getMessage(), 'R5: the established not-found message');
        self::assertSame(
            $before,
            $this->countExceptionRows($this->clinicianId, $this->clinicA),
            'R5: the denied foreign-Location create wrote NO exception row (and no regeneration side effect)'
        );

        /*
         * REST boundary (established Slice 3 scope-binding semantics):
         * `location_id` in the request acts as the request's Location selector
         * (RestClinicContext + TrustedClinicEstablisher) BEFORE the service runs,
         * so every bad selector — foreign, nonexistent or inactive — is rejected
         * there with ONE identical fail-closed envelope. What this boundary must
         * guarantee is therefore: never accepted, uniform (no existence
         * disclosure) and never writing. The exact not-found parity envelope of
         * the core contract is asserted on the service level above.
         */
        $rest = $this->restExceptionEnvelope($this->clinicA, [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
            'type' => 'holiday',
            'location_id' => $this->locB1,
        ]);
        self::assertNotSame(
            200,
            $rest['status'],
            'R5 REST: a foreign Location selector must never be accepted; envelope=' . wp_json_encode($rest)
        );
        self::assertStringStartsWith(
            'CLINIC_',
            (string) $rest['code'],
            'R5 REST: the boundary fails closed with a CLINIC_* code (no silent success); envelope=' . wp_json_encode($rest)
        );
        self::assertSame(
            $before,
            $this->countExceptionRows($this->clinicianId, $this->clinicA),
            'R5 REST: no exception row was written by the denied foreign-Location request'
        );
    }

    // ==================================================================
    // R6 — a NONEXISTENT Location id produces the byte-identical envelope
    //      (no enumeration / no existence disclosure).
    // ==================================================================

    public function testNonexistentLocationYieldsTheByteIdenticalEnvelope(): void
    {
        $date = $this->localDateIn('+1 day');
        $base = [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
            'type' => 'holiday',
        ];

        // ---- Core contract (service level) ----
        $missingThrown = $this->exceptionServiceError($this->clinicA, $base + ['location_id' => 999999]);
        self::assertNotNull(
            $missingThrown,
            'R6 DEFECT: a nonexistent Location must fail closed with the established not-found envelope. '
            . 'Pre-fix the selector is silently ignored and the request succeeds.'
        );
        self::assertSame('CLINIC_NOT_FOUND', $missingThrown->errorCode, 'R6: not-found parity code');
        self::assertSame(404, $missingThrown->httpStatus, 'R6: not-found parity status');
        self::assertSame('محل یافت نشد', $missingThrown->getMessage(), 'R6: the established not-found message');

        $foreignThrown = $this->exceptionServiceError($this->clinicA, $base + ['location_id' => $this->locB1]);
        self::assertNotNull($foreignThrown, 'R6 parity precondition: the foreign selector fails closed too');
        self::assertSame($foreignThrown->errorCode, $missingThrown->errorCode, 'R6 PARITY (service): foreign and nonexistent share the code');
        self::assertSame($foreignThrown->httpStatus, $missingThrown->httpStatus, 'R6 PARITY (service): ... and the status');
        self::assertSame(
            $foreignThrown->getMessage(),
            $missingThrown->getMessage(),
            'R6 PARITY (service): ... and the message (no enumeration)'
        );

        // ---- REST boundary (selector = scope selector, uniform rejection) ----
        $foreign = $this->restExceptionEnvelope($this->clinicA, $base + ['location_id' => $this->locB1]);
        $missing = $this->restExceptionEnvelope($this->clinicA, $base + ['location_id' => 999999]);

        self::assertNotSame(
            200,
            $missing['status'],
            'R6 REST DEFECT: a nonexistent Location selector must never be accepted; envelope=' . wp_json_encode($missing)
        );
        self::assertSame(
            $foreign,
            $missing,
            'R6 PARITY: a nonexistent Location id must be BYTE-IDENTICAL (status + code + message) to a foreign one '
            . '— foreign=' . wp_json_encode($foreign) . ' missing=' . wp_json_encode($missing)
        );
        self::assertSame(
            0,
            $this->countExceptionRows($this->clinicianId, $this->clinicA),
            'R6: neither denied request wrote an exception row'
        );
    }

    // ==================================================================
    // R7 — an INACTIVE Location of the TRUSTED Clinic fails closed the
    //      same way (own-Clinic existence is not disclosed either).
    // ==================================================================

    public function testInactiveLocationOfTrustedClinicFailsClosedTheSameWay(): void
    {
        $inactive = $this->locationRow($this->locAInactive);
        self::assertNotNull($inactive, 'precondition: the inactive Location row exists');
        self::assertSame($this->clinicA, (int) $inactive['clinic_id'], 'precondition: it belongs to the TRUSTED Clinic A');
        self::assertSame(0, (int) $inactive['is_active'], 'precondition: it is INACTIVE');

        $date = $this->localDateIn('+1 day');
        $base = [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
            'type' => 'holiday',
        ];

        // ---- Core contract (service level): inactive = not-found parity ----
        $inactiveThrown = $this->exceptionServiceError($this->clinicA, $base + ['location_id' => $this->locAInactive]);
        self::assertNotNull(
            $inactiveThrown,
            'R7 DEFECT: an INACTIVE Location of the trusted Clinic must fail closed like a not-found Location, '
            . 'never be silently substituted by another Location.'
        );
        self::assertSame('CLINIC_NOT_FOUND', $inactiveThrown->errorCode, 'R7: not-found parity code (own-Clinic existence not disclosed)');
        self::assertSame(404, $inactiveThrown->httpStatus, 'R7: not-found parity status');
        self::assertSame('محل یافت نشد', $inactiveThrown->getMessage(), 'R7: the established not-found message');
        $inactiveMissingThrown = $this->exceptionServiceError($this->clinicA, $base + ['location_id' => 999999]);
        self::assertNotNull($inactiveMissingThrown, 'R7 parity precondition: the nonexistent selector fails closed too');
        self::assertSame($inactiveMissingThrown->errorCode, $inactiveThrown->errorCode, 'R7 PARITY (service): inactive and nonexistent share the code');
        self::assertSame($inactiveMissingThrown->httpStatus, $inactiveThrown->httpStatus, 'R7 PARITY (service): ... and the status');
        self::assertSame($inactiveMissingThrown->getMessage(), $inactiveThrown->getMessage(), 'R7 PARITY (service): ... and the message');

        // ---- REST boundary (uniform rejection; no existence disclosure) ----
        $envelope = $this->restExceptionEnvelope($this->clinicA, $base + ['location_id' => $this->locAInactive]);
        self::assertNotSame(
            200,
            $envelope['status'],
            'R7 REST DEFECT: an INACTIVE Location selector must never be accepted; envelope=' . wp_json_encode($envelope)
        );
        self::assertSame(
            $this->restExceptionEnvelope($this->clinicA, $base + ['location_id' => 999999]),
            $envelope,
            'R7 PARITY: inactive and nonexistent share the identical envelope'
        );
        self::assertSame(
            $this->restExceptionEnvelope($this->clinicA, $base + ['location_id' => $this->locB1]),
            $envelope,
            'R7 PARITY: inactive and foreign share the identical envelope'
        );
        self::assertSame(
            0,
            $this->countExceptionRows($this->clinicianId, $this->clinicA),
            'R7: none of the denied attempts wrote an exception row'
        );

        // Positive control: an ACTIVE Location of the trusted Clinic IS accepted
        // (the denial above is a targeted contract, not a blanket refusal).
        $accepted = $this->restExceptionEnvelope($this->clinicA, $base + ['location_id' => $this->locA2]);
        self::assertSame(200, $accepted['status'], 'R7 positive control: an ACTIVE Location of the trusted Clinic is accepted');
        self::assertSame(
            1,
            $this->countExceptionRows($this->clinicianId, $this->clinicA),
            'R7 positive control: exactly the accepted request wrote one row'
        );
    }

    // ==================================================================
    // R8 — exceptionView() exposes the persisted nullable location_id
    //      (create response AND list response; historical NULL included).
    // ==================================================================

    public function testExceptionViewExposesPersistedLocationId(): void
    {
        $pinnedDate = $this->localDateIn('+1 day');
        $allDate = $this->localDateIn('+2 days');
        $historicalDate = $this->localDateIn('+3 days');

        $pinned = $this->dispatch('POST', self::NS . '/config/schedule-exceptions', [
            'clinician_id' => $this->clinicianId,
            'date' => $pinnedDate,
            'type' => 'leave',
            'location_id' => $this->locA2,
            'reason' => 'P6S6 R8 pinned',
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $pinned->get_status(), 'R8 precondition: pinned create succeeds (error=' . $this->errorCode($pinned) . ')');
        $pinnedData = $pinned->get_data()['data'] ?? [];
        self::assertIsArray($pinnedData, 'R8: the create response carries the view');
        self::assertArrayHasKey(
            'location_id',
            $pinnedData,
            'R8 DEFECT: exceptionView() must expose location_id (pinned). keys=' . wp_json_encode(array_keys($pinnedData))
        );
        self::assertSame($this->locA2, (int) $pinnedData['location_id'], 'R8: the pinned Location is exposed');

        $all = $this->dispatch('POST', self::NS . '/config/schedule-exceptions', [
            'clinician_id' => $this->clinicianId,
            'date' => $allDate,
            'type' => 'holiday',
            'reason' => 'P6S6 R8 all-locations',
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $all->get_status(), 'R8 precondition: all-Locations create succeeds (error=' . $this->errorCode($all) . ')');
        $allData = $all->get_data()['data'] ?? [];
        self::assertIsArray($allData, 'R8: the create response carries the view');
        self::assertArrayHasKey('location_id', $allData, 'R8 DEFECT: exceptionView() must expose location_id (all-Locations must be distinguishable from a pinned one)');
        self::assertNull($allData['location_id'], 'R8: an all-Locations exception exposes NULL');

        $historicalId = $this->insertExceptionRowRaw($this->clinicA, $historicalDate, 'leave', null, null, 'P6S6 R8 historical NULL');

        $list = $this->dispatch('GET', self::NS . '/config/schedule-exceptions', [
            'clinician_id' => $this->clinicianId,
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d', time() + 30 * 86400),
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $list->get_status(), 'R8: scoped list succeeds (error=' . $this->errorCode($list) . ')');
        $rows = $list->get_data()['data'] ?? [];
        self::assertIsArray($rows, 'R8: list returns an array');

        $byId = [];
        foreach ($rows as $r) {
            if (is_array($r) && isset($r['id'])) {
                $byId[(int) $r['id']] = $r;
            }
        }
        self::assertArrayHasKey((int) $pinnedData['id'], $byId, 'R8: the pinned exception appears in the list');
        self::assertArrayHasKey((int) $allData['id'], $byId, 'R8: the all-Locations exception appears in the list');

        $listedPinned = $byId[(int) $pinnedData['id']];
        self::assertArrayHasKey('location_id', $listedPinned, 'R8 DEFECT: the list view must expose location_id');
        self::assertSame($this->locA2, (int) $listedPinned['location_id'], 'R8: listed pinned row exposes its Location');

        $listedAll = $byId[(int) $allData['id']];
        self::assertArrayHasKey('location_id', $listedAll, 'R8 DEFECT: the list view must expose location_id (all-Locations)');
        self::assertNull($listedAll['location_id'], 'R8: listed all-Locations row exposes NULL');

        if (isset($byId[$historicalId])) {
            self::assertArrayHasKey('location_id', $byId[$historicalId], 'R8: historical rows are exposed with location_id too');
            self::assertNull($byId[$historicalId]['location_id'], 'R8: a historical row (persisted NULL) reads back as all-Locations');
        } else {
            // The historical fixture date must be inside the requested window;
            // if it is not, that is a fixture/window defect — never a product RED.
            self::fail('fixture defect (class D): the historical exception row is missing from the list window');
        }
    }

    // ==================================================================
    // R9 — wp-admin: the create form offers the trusted Clinic's Locations
    //      plus one explicit all-Locations choice; the listing shows the
    //      Location of every exception (or that it applies to all).
    // ==================================================================

    public function testWpAdminFormOffersClinicLocationsAndListingShowsThem(): void
    {
        $pinnedDate = $this->localDateIn('+1 day');
        $allDate = $this->localDateIn('+2 days');

        $this->createExceptionViaService($this->clinicA, [
            'clinician_id' => $this->clinicianId,
            'date' => $pinnedDate,
            'type' => 'leave',
            'location_id' => $this->locA2,
            'reason' => 'P6S6 R9 pinned',
        ]);
        $this->createExceptionViaService($this->clinicA, [
            'clinician_id' => $this->clinicianId,
            'date' => $allDate,
            'type' => 'holiday',
            'reason' => 'P6S6 R9 all-locations',
        ]);
        self::assertSame(2, $this->countExceptionRows($this->clinicianId, $this->clinicA), 'precondition: two exception rows for the listing');

        wp_set_current_user($this->managerAId);
        $_GET['clinician_id'] = $this->clinicianId;
        ob_start();
        ClinicianAdminPage::render();
        $html = (string) ob_get_clean();
        unset($_GET['clinician_id']);
        self::assertNotSame('', $html, 'precondition: the clinician page rendered under the trusted scope');

        $headingPos = strpos($html, 'استثناها');
        self::assertNotFalse($headingPos, 'precondition: the exceptions section heading is rendered');
        $createMarkerPos = strpos($html, 'value="cpms_exception_create"');
        self::assertNotFalse($createMarkerPos, 'precondition: the create-exception form is rendered');
        $createFormStart = strrpos(substr($html, 0, $createMarkerPos), '<form');
        self::assertNotFalse($createFormStart, 'precondition: the create-exception form tag is rendered');
        $createFormEnd = strpos($html, '</form>', $createMarkerPos);
        self::assertNotFalse($createFormEnd, 'precondition: the create-exception form is closed');

        $createForm = substr($html, (int) $createFormStart, (int) $createFormEnd - (int) $createFormStart);
        $listing = substr($html, $headingPos, (int) $createFormStart - $headingPos);

        self::assertStringContainsString(
            'name="location_id"',
            $createForm,
            'R9 DEFECT: the wp-admin exception create form must offer a Location selector'
        );
        self::assertStringContainsString(
            '<option value="' . $this->locA1 . '"',
            $createForm,
            'R9 DEFECT: the selector must offer the trusted Clinic\'s primary Location A1'
        );
        self::assertStringContainsString(
            '<option value="' . $this->locA2 . '"',
            $createForm,
            'R9 DEFECT: the selector must offer the trusted Clinic\'s Location A2'
        );
        self::assertStringContainsString(
            self::ALL_LOCATIONS_LABEL,
            $createForm,
            'R9 DEFECT: the selector must offer an explicit all-Locations (NULL) choice'
        );
        self::assertStringNotContainsString(
            '<option value="' . $this->locAInactive . '"',
            $createForm,
            'R9: an INACTIVE Location is not offered as a choice (the write contract rejects it)'
        );
        self::assertStringNotContainsString(
            '<option value="' . $this->locB1 . '"',
            $createForm,
            'R9 (tenant isolation): a foreign Clinic\'s Location is never offered'
        );

        self::assertStringContainsString(
            $this->locA2Name,
            $listing,
            'R9 DEFECT: the exceptions listing must SHOW the Location each exception applies to'
        );
        self::assertStringContainsString(
            self::ALL_LOCATIONS_LABEL,
            $listing,
            'R9 DEFECT: the listing must show that an all-Locations exception applies to all Locations'
        );
    }

    // ==================================================================
    // R9 (write path) — the operator's Location choice in the wp-admin form
    //      reaches the service: a selected Location is persisted, the
    //      explicit all-Locations choice persists NULL, and a foreign id
    //      fails closed at the admin boundary without writing anything.
    // ==================================================================

    public function testWpAdminCreateExceptionHonorsTheSelectedLocation(): void
    {
        $pinnedDate = $this->localDateIn('+1 day');
        $allDate = $this->localDateIn('+2 days');

        $pinnedNotice = $this->dispatchAdminAction('cpms_exception_create', [
            'clinician_id' => (string) $this->clinicianId,
            'date' => $pinnedDate,
            'type' => 'leave',
            'location_id' => (string) $this->locA2,
            'reason' => 'P6S6 R9 write pinned',
        ], fn () => ClinicianAdminPage::createException(), $this->managerAId);
        self::assertStringNotContainsString('خطا', $pinnedNotice, 'R9 DEFECT: an authorized create with a Location must succeed; notice="' . $pinnedNotice . '"');
        $pinnedRow = $this->exceptionRowForDate($this->clinicA, $pinnedDate);
        self::assertNotNull($pinnedRow, 'R9: the pinned exception row exists');
        self::assertSame(
            $this->locA2,
            (int) $pinnedRow['location_id'],
            'R9 DEFECT: the Location selected in the wp-admin form must be persisted '
            . '(pre-fix the field is not even rendered and the selector is silently dropped ⇒ NULL).'
        );
        self::assertSame($this->clinicA, (int) $pinnedRow['clinic_id'], 'R9: the row belongs to the trusted Clinic, never to the payload');

        $allNotice = $this->dispatchAdminAction('cpms_exception_create', [
            'clinician_id' => (string) $this->clinicianId,
            'date' => $allDate,
            'type' => 'holiday',
            'location_id' => '',
            'reason' => 'P6S6 R9 write all-locations',
        ], fn () => ClinicianAdminPage::createException(), $this->managerAId);
        self::assertStringNotContainsString('خطا', $allNotice, 'R9: the explicit all-Locations choice must succeed; notice="' . $allNotice . '"');
        $allRow = $this->exceptionRowForDate($this->clinicA, $allDate);
        self::assertNotNull($allRow, 'R9: the all-Locations row exists');
        self::assertNull($allRow['location_id'], 'R9: the explicit all-Locations choice persists NULL (every Location of the trusted Clinic)');

        $before = $this->countExceptionRows($this->clinicianId, $this->clinicA);
        $foreignNotice = $this->dispatchAdminAction('cpms_exception_create', [
            'clinician_id' => (string) $this->clinicianId,
            'date' => $this->localDateIn('+3 days'),
            'type' => 'holiday',
            'location_id' => (string) $this->locB1,
        ], fn () => ClinicianAdminPage::createException(), $this->managerAId);
        self::assertStringContainsString(
            'خطا',
            $foreignNotice,
            'R9: a foreign Location must fail closed at the admin boundary; notice="' . $foreignNotice . '"'
        );
        self::assertSame(
            $before,
            $this->countExceptionRows($this->clinicianId, $this->clinicA),
            'R9: the denied admin create wrote nothing'
        );
    }

    // ==================================================================
    // R10 — Clinic isolation: an exception of Clinic A (even pinned inside
    //       A) never affects Clinic B's generation for the same
    //       professional on the same date.
    // ==================================================================

    public function testCrossClinicExceptionDoesNotAffectOtherClinicGeneration(): void
    {
        $date = $this->localDateIn('+1 day');
        $dow = self::iranianDow($date);
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA1, $dow, '09:00:00', '10:00:00', 30), 'precondition: Clinic A row');
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicB, $this->locB1, $dow, '09:00:00', '10:00:00', 30), 'precondition: Clinic B row');
        $this->setClinicHorizon($this->clinicA, self::HORIZON);
        $this->setClinicHorizon($this->clinicB, self::HORIZON);

        $this->sweep();
        self::assertSame(['09:00', '09:30'], $this->slotTimes($this->clinicA, $this->locA1, $date), 'precondition: Clinic A slots generated');
        self::assertSame(['09:00', '09:30'], $this->slotTimes($this->clinicB, $this->locB1, $date), 'precondition: Clinic B slots generated');

        $this->createExceptionViaService($this->clinicA, [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
            'type' => 'leave',
            'location_id' => $this->locA1,
            'reason' => 'P6S6 R10 Clinic A leave',
        ]);

        $this->sweep();

        self::assertSame(
            [],
            $this->slotTimes($this->clinicA, $this->locA1, $date),
            'R10: the Clinic A exception still closes Clinic A\'s own Location'
        );
        self::assertSame(
            ['09:00', '09:30'],
            $this->slotTimes($this->clinicB, $this->locB1, $date),
            'R10 (Clinic isolation): an exception of Clinic A must never affect Clinic B\'s generation '
            . 'for the same professional/date (legitimate multi-Clinic participation).'
        );
        self::assertSame(
            0,
            $this->countExceptionRows($this->clinicianId, $this->clinicB),
            'R10: the Clinic A operation wrote no exception row in Clinic B'
        );
    }

    // ==================================================================
    // (f) DELETE keeps its explicit-id, Clinic-scoped authorization
    //     semantics while Location information stays visible.
    // ==================================================================

    public function testDeleteByExplicitIdKeepsClinicScopedAuthorization(): void
    {
        $date = $this->localDateIn('+1 day');
        $dow = self::iranianDow($date);
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA1, $dow, '09:00:00', '10:00:00', 30), 'precondition: A1 schedule row');
        self::assertGreaterThan(0, $this->insertScheduleRowRaw($this->clinicA, $this->locA2, $dow, '09:00:00', '10:00:00', 30), 'precondition: A2 schedule row');
        $this->setClinicHorizon($this->clinicA, self::HORIZON);

        $view = $this->createExceptionViaService($this->clinicA, [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
            'type' => 'leave',
            'location_id' => $this->locA2,
            'reason' => 'P6S6 delete contract',
        ]);
        $exceptionId = (int) $view['id'];
        self::assertGreaterThan(0, $exceptionId, 'precondition: exception created');

        $this->sweep();
        self::assertSame([], $this->slotTimes($this->clinicA, $this->locA2, $date), 'precondition: the exception is in effect at A2');

        // Cross-Clinic delete must keep failing closed (unchanged semantics).
        $denied = $this->withScope($this->clinicB, function () use ($exceptionId): ?BookingException {
            try {
                App::scheduleService()->deleteException($this->managerBId, $exceptionId);
            } catch (BookingException $e) {
                return $e;
            }

            return null;
        });
        self::assertNotNull($denied, 'DELETE: an exception of Clinic A must not be deletable from Clinic B\'s scope');
        self::assertSame('CLINIC_NOT_FOUND', $denied->errorCode, 'DELETE: not-found parity code preserved');
        self::assertSame(404, $denied->httpStatus, 'DELETE: not-found parity status preserved');
        self::assertNotNull($this->exceptionRow($exceptionId), 'DELETE: the denied delete changed nothing');

        // Authorized delete by explicit id, inside the trusted Clinic.
        $deleted = $this->withScope($this->clinicA, fn (): array => App::scheduleService()->deleteException($this->managerAId, $exceptionId));
        self::assertSame(true, $deleted['deleted'], 'DELETE: authorized delete reports success');
        self::assertSame($exceptionId, (int) $deleted['id'], 'DELETE: the deleted id is echoed');
        self::assertNull($this->exceptionRow($exceptionId), 'DELETE: the row is gone');

        // The removed exception no longer constrains generation at its Location.
        $this->sweep();
        self::assertSame(
            ['09:00', '09:30'],
            $this->slotTimes($this->clinicA, $this->locA2, $date),
            'DELETE: slots of the previously pinned Location are regenerated after the delete'
        );
        self::assertSame(
            ['09:00', '09:30'],
            $this->slotTimes($this->clinicA, $this->locA1, $date),
            'DELETE: Location A1 is unaffected by the pinned exception\'s removal'
        );
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Timezone-database availability is an ENVIRONMENT precondition, not a
     * product contract — a missing identifier must never be reported as a
     * product RED.
     */
    private function assertTimezoneAvailable(): void
    {
        self::assertContains(self::TZ, timezone_identifiers_list(), 'ENVIRONMENT precondition: PHP timezone database must provide ' . self::TZ);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withScope(int $clinicId, callable $fn)
    {
        $previous = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic($clinicId));
        try {
            return $fn();
        } finally {
            App::replaceExplicitScope($previous);
        }
    }

    /** Location-local calendar date, $offset days from that Location's today. */
    private function localDateIn(string $offset): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::TZ))
            ->modify($offset)
            ->format('Y-m-d');
    }

    /** Gregorian date → Iranian weekday (0=شنبه … 6=جمعه), same mapping as the handler. */
    private static function iranianDow(string $ymd): int
    {
        $map = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 0];
        $w = (int) (new DateTimeImmutable($ymd . ' 12:00:00', new DateTimeZone(self::TZ)))->format('w');

        return $map[$w];
    }

    /**
     * REAL production operational path: enqueue → App::runTick → dispatcher →
     * SlotsGenerateHandler. Skips (never fails) when the Location-local
     * calendar date flips mid-sweep — that would be an INCONCLUSIVE run, not a
     * product RED.
     */
    private function sweep(): void
    {
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $jobId = App::jobs()->enqueue('slots.generate', [], new DateTimeImmutable('now', new DateTimeZone('UTC')), 3, 3);
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

        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tz = new DateTimeZone(self::TZ);
        if ($before->setTimezone($tz)->format('Y-m-d') !== $after->setTimezone($tz)->format('Y-m-d')) {
            self::markTestSkipped('INCONCLUSIVE (not a product RED): the Location-local calendar date changed during the sweep');
        }
    }

    /**
     * Per-Clinic horizon with the SAME production settings key the sweep
     * resolves per row (no ambient scope, no Clinic 1 default).
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

    /** Real staff REST boundary: authenticated actor + nonce + trusted Clinic header. */
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
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function createExceptionViaService(int $clinicId, array $fields): array
    {
        return $this->withScope($clinicId, fn (): array => App::scheduleService()->createException($this->managerAId, $fields));
    }

    /**
     * Real wp-admin boundary (C7 pattern): nonce + capability + redirect-exit
     * simulation, returning the operator-facing notice text.
     *
     * @param array<string, mixed> $post
     */
    private function dispatchAdminAction(string $nonceAction, array $post, callable $handler, int $userId): string
    {
        wp_set_current_user($userId);
        $_POST = $post;
        $_REQUEST['_wpnonce'] = wp_create_nonce($nonceAction);
        delete_transient('cpms_clinic_notice');

        $redirect = static function ($location) {
            throw new \RuntimeException('P6S6_ADMIN_REDIRECT_EXIT_SIMULATED:' . (string) $location);
        };
        add_filter('wp_redirect', $redirect);
        try {
            $handler();
            self::fail('admin action ended without a redirect — the handler contract is broken');
        } catch (\RuntimeException $e) {
            // The full handler path (guard → service → notice → redirect) ran;
            // exit was simulated.
        } finally {
            remove_filter('wp_redirect', $redirect);
            $_POST = [];
            unset($_REQUEST['_wpnonce']);
        }

        return (string) get_transient('cpms_clinic_notice');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exceptionRowForDate(int $clinicId, string $date): ?array
    {
        return App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_schedule_exceptions') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND date = %s LIMIT 1',
            [$this->clinicianId, $clinicId, $date]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function exceptionServiceError(int $clinicId, array $fields): ?BookingException
    {
        return $this->withScope($clinicId, function () use ($fields): ?BookingException {
            try {
                App::scheduleService()->createException($this->managerAId, $fields);
            } catch (BookingException $e) {
                return $e;
            }

            return null;
        });
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, code: string, message: string}
     */
    private function restExceptionEnvelope(int $clinicId, array $fields): array
    {
        $response = $this->dispatch('POST', self::NS . '/config/schedule-exceptions', $fields, $clinicId, $this->managerAId);

        return [
            'status' => $response->get_status(),
            'code' => $this->errorCode($response),
            'message' => (string) ($this->errorEnvelope($response)['message'] ?? ''),
        ];
    }

    private function errorCode(WP_REST_Response $response): string
    {
        $body = $response->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code();
        }

        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function errorEnvelope(WP_REST_Response $response): array
    {
        $body = $response->get_data();
        if ($body instanceof \WP_Error) {
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
     * @return list<string> "H:i" slot times at one Location on one date
     */
    private function slotTimes(int $clinicId, int $locationId, string $date): array
    {
        $rows = App::db()->fetchAll(
            'SELECT slot_time FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND location_id = %d AND slot_date = %s ORDER BY slot_time',
            [$this->clinicianId, $clinicId, $locationId, $date]
        );

        return array_map(static fn (array $r): string => substr((string) $r['slot_time'], 0, 5), $rows);
    }

    private function countExceptionRows(int $clinicianId, int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule_exceptions') .
            ' WHERE clinician_id = %d AND clinic_id = %d',
            [$clinicianId, $clinicId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exceptionRow(int $id): ?array
    {
        return App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_schedule_exceptions') . ' WHERE id = %d',
            [$id]
        );
    }

    // ---------------- Fixtures (dynamic ids only) ----------------

    private function createOrganization(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, "active", %s, %s)', // phpcs:ignore
            'P6S6 Org ' . $unique,
            'p6s6-org-' . $unique,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: organization inserted (' . $wpdb->last_error . ')');

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
            'P6S6 Clinic ' . $tag . ' ' . $unique,
            'p6s6-clinic-' . strtolower($tag) . '-' . $unique,
            self::TZ,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'precondition: clinic id is dynamic (never the seeded legacy clinic 1)');

        return $id;
    }

    /**
     * @return array{id: int, name: string}
     */
    private function createLocation(int $clinicId, int $isPrimary, int $isActive, string $tag): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $name = 'P6S6 Loc ' . $tag . ' ' . $unique;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                 (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %d, %d, %s, %s)', // phpcs:ignore
            $clinicId,
            $name,
            'p6s6-loc-' . strtolower($tag) . '-' . $unique,
            self::TZ,
            $isPrimary,
            $isActive,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: location inserted (' . $wpdb->last_error . ')');

        return ['id' => $id, 'name' => $name];
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@p6s6.test');
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

    private function insertScheduleRowRaw(
        int $clinicId,
        int $locationId,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        int $durationMin
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $res = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule
                 (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time,
                  appointment_duration_min, slot_capacity, is_active, created_at, updated_at)
             VALUES (%d, %d, %d, %d, %s, %s, %d, %d, 1, %s, %s)', // phpcs:ignore
            $clinicId,
            $locationId,
            $this->clinicianId,
            $dayOfWeek,
            $startTime,
            $endTime,
            $durationMin,
            1,
            $now,
            $now
        ));
        self::assertNotFalse($res, 'precondition: schedule row inserted (' . $wpdb->last_error . ')');

        return (int) $wpdb->insert_id;
    }

    /**
     * Historical-shaped exception row: the INSERT deliberately omits
     * `location_id` exactly like the pre-slice write path, so the column keeps
     * its NULL default (all Locations of the Clinic).
     */
    private function insertExceptionRowRaw(
        int $clinicId,
        string $date,
        string $type,
        ?string $startTime,
        ?string $endTime,
        string $reason
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $res = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_exceptions
                 (clinic_id, clinician_id, date, type, start_time, end_time, reason, created_by_wp_user_id, created_at)
             VALUES (%d, %d, %s, %s, %s, %s, %s, %d, %s)', // phpcs:ignore
            $clinicId,
            $this->clinicianId,
            $date,
            $type,
            $startTime,
            $endTime,
            $reason,
            $this->managerAId,
            $now
        ));
        self::assertNotFalse($res, 'precondition: exception row inserted (' . $wpdb->last_error . ')');

        return (int) $wpdb->insert_id;
    }

    // ---------------- Durable-state probes ----------------

    private function clinicOrganization(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT organization_id FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function locationRow(int $locationId): ?array
    {
        return App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d',
            [$locationId]
        );
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
        // Children first (FK-safe order): slots/exceptions/schedules reference
        // locations; clinician_locations references both.
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_schedule_slots t
                      WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_schedule_exceptions t
                      WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_schedule t
                      WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_clinician_locations t
                      JOIN ' . $wpdb->prefix . 'cpms_locations l ON l.id = t.location_id
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = l.clinic_id
                      WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE mc FROM ' . $wpdb->prefix . 'cpms_membership_capabilities mc
                      JOIN ' . $wpdb->prefix . 'cpms_clinic_memberships m ON m.id = mc.membership_id
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = m.clinic_id
                      WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE m FROM ' . $wpdb->prefix . 'cpms_clinic_memberships m
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = m.clinic_id
                      WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_clinicians t
                      WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_locations t
                      WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = ' . $org); // phpcs:ignore
    }
}
