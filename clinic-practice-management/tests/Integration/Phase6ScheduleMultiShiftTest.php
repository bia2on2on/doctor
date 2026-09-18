<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Phase 6 Slice 4 — «multiple shifts per (Clinic, Location, clinician, weekday),
 * with deterministic overlap rejection».
 *
 * CONTRACT UNDER TEST (replaces the Slice 3 single-row-per-(Location, weekday) rule):
 *
 *  M1. Two NON-overlapping shifts at the same (Clinic, Location, clinician, weekday)
 *      (e.g. 08:00-12:00 AND 16:00-20:00) may both exist; both rows persist.
 *  M2. An OVERLAPPING create for the same (Clinic, Location, clinician, weekday) is
 *      rejected with the DISTINCT machine-readable reason `overlapping_shift`
 *      (under the established CLINIC_VALIDATION_FAILED / HTTP 400 envelope) —
 *      never silently, and operators can tell it apart from `duplicate_schedule_day`.
 *  M3. An EXACT duplicate (same weekday + Location + start_time) remains rejected
 *      with the STABLE `duplicate_schedule_day` reason (existing consumers kept).
 *  M4. Touching boundaries are NOT an overlap: a shift ending 12:00 and one
 *      starting 12:00 must both be allowed.
 *  M5. The SAME overlap rejection applies on UPDATE (stretching a shift into a
 *      neighbour's window is rejected; the victim row is left untouched).
 *  M6. An INACTIVE row never blocks another shift; reactivating a row into an
 *      overlap is rejected (invariant: no two ACTIVE rows overlap). Deactivating
 *      a row is always allowed (the escape hatch).
 *  M7. Slot generation for two non-overlapping shifts produces the UNION of both
 *      shifts' slots, and produces the SAME set when the rows are inserted in
 *      the opposite order (determinism — no row-order dependence).
 *  M8. wp-admin NARROW fail-closed guard (no matrix redesign in this slice):
 *      when MORE THAN ONE row matches (clinician, day, Clinic, Location),
 *      ClinicianAdminPage::save() refuses to guess and writes nothing.
 *
 * PRESERVED (not weakened by the contract flip):
 *  - trusted Clinic comes only from server-side scope, never from the payload;
 *  - location_id stays explicit + authorized (real + active + trusted-Clinic);
 *  - no fake tenant identity (dynamic ids asserted > 1, never seeded 1);
 *  - ONE professional identity across Clinics; Clinic A's rows never leak to B;
 *  - generation stays bounded + idempotent (INSERT IGNORE against u_slot).
 *
 * FIXTURE RULES (same as the Slice 3 suite — no first-row tenant, no fixed ids):
 *  - dynamic Organization + dynamically allocated Clinics/Locations (ids > 1);
 *  - one WP user ↔ exactly one `cpms_clinicians` row (asserted);
 *  - durable memberships through the production primitive
 *    (`cpms_test_seed_membership` → `MembershipService::create_membership`);
 *  - the trusted Clinic arrives via the real staff REST boundary
 *    (`X-CPMS-Clinic-Id`) or an explicit `ClinicScope` — never as payload.
 */
final class Phase6ScheduleMultiShiftTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    /** Primary Location + Clinic timezone (production-realistic). */
    private const TZ_PRIMARY = 'Asia/Tehran';

    /** Secondary Location timezone — deliberately different frame (UTC+14). */
    private const TZ_SECONDARY = 'Pacific/Kiritimati';

    /** Slot-generation horizon for M7 (7 dates ⇒ exactly one matches the weekday). */
    private const HORIZON = 7;

    private int $orgId = 0;

    /** Trusted Clinic. */
    private int $clinicA = 0;

    /** Foreign Clinic (owner of B1). */
    private int $clinicB = 0;

    /** A's primary Location (Asia/Tehran). */
    private int $locA1 = 0;

    /** A's secondary Location (Pacific/Kiritimati). */
    private int $locA2 = 0;

    /** B's primary Location. */
    private int $locB1 = 0;

    /** The single professional identity. */
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

        $this->assertTimezonesAvailable();

        $this->orgId = $this->createOrganization();
        $this->clinicA = $this->createClinic('A');
        $this->clinicB = $this->createClinic('B');
        $this->locA1 = $this->createLocation($this->clinicA, 1, self::TZ_PRIMARY);
        $this->locA2 = $this->createLocation($this->clinicA, 0, self::TZ_SECONDARY);
        $this->locB1 = $this->createLocation($this->clinicB, 1, self::TZ_PRIMARY);

        // ONE globally unique professional identity (home Clinic = A).
        $this->professionalUserId = $this->makeUser('p6s4_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $this->clinicianId = $this->insertClinician($this->clinicA, $this->professionalUserId, 'Dr P6S4 Professional');
        // Durable active participation in BOTH Clinics (SoT = cpms_clinic_memberships).
        self::assertGreaterThan(0, cpms_test_seed_membership($this->professionalUserId, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR));
        self::assertGreaterThan(0, cpms_test_seed_membership($this->professionalUserId, $this->clinicB, RolesAndCapabilities::ROLE_DOCTOR));

        $this->managerAId = $this->makeUser('p6s4_mgr_a', RolesAndCapabilities::ROLE_MANAGER);
        self::assertGreaterThan(0, cpms_test_seed_membership($this->managerAId, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER));
        $this->managerBId = $this->makeUser('p6s4_mgr_b', RolesAndCapabilities::ROLE_MANAGER);
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
        $b1 = $this->locationRow($this->locB1);
        self::assertNotNull($a1, 'precondition: Location A1 row inserted');
        self::assertNotNull($a2, 'precondition: Location A2 row inserted');
        self::assertNotNull($b1, 'precondition: Location B1 row inserted');
        self::assertSame($this->clinicA, (int) $a1['clinic_id'], 'precondition: A1 belongs to Clinic A');
        self::assertSame(1, (int) $a1['is_primary'], 'precondition: A1 is A\'s primary Location');
        self::assertSame(1, (int) $a1['is_active'], 'precondition: A1 active');
        self::assertSame(self::TZ_PRIMARY, (string) $a1['timezone'], 'precondition: A1 timezone');
        self::assertSame($this->clinicA, (int) $a2['clinic_id'], 'precondition: A2 belongs to Clinic A');
        self::assertSame(0, (int) $a2['is_primary'], 'precondition: A2 is NOT the primary Location (non-primary is the point)');
        self::assertSame(1, (int) $a2['is_active'], 'precondition: A2 active');
        self::assertSame(self::TZ_SECONDARY, (string) $a2['timezone'], 'precondition: A2 carries the distinct IANA timezone');
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
        self::assertTrue(
            $this->authz()->can($this->managerAId, $this->clinicA, RolesAndCapabilities::CONFIG),
            'precondition: manager A holds the already-required Phase 3 permission in Clinic A'
        );
        self::assertTrue(
            $this->authz()->can($this->managerBId, $this->clinicB, RolesAndCapabilities::CONFIG),
            'precondition: manager B holds the already-required Phase 3 permission in Clinic B'
        );
    }

    // ==================================================================
    // M1 — 08:00-12:00 AND 16:00-20:00 at the same (Clinic, Location,
    //      clinician, weekday): both must succeed and both rows persist.
    // ==================================================================

    public function testTwoNonOverlappingShiftsAtSameLocationBothPersist(): void
    {
        $morning = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $morning->get_status(), 'M1 precondition: morning shift created (error=' . $this->errorCode($morning) . ')');
        $morningId = (int) ($morning->get_data()['data']['id'] ?? 0);
        self::assertGreaterThan(0, $morningId, 'M1 precondition: morning shift id returned');

        $evening = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 1,
            'start_time' => '16:00',
            'end_time' => '20:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(
            200,
            $evening->get_status(),
            'M1 DEFECT: a second NON-overlapping shift at the same (Clinic, Location, clinician, weekday) must be allowed. '
            . 'Pre-fix it is rejected with duplicate_schedule_day (error=' . $this->errorCode($evening) . ')'
        );
        $eveningId = (int) ($evening->get_data()['data']['id'] ?? 0);
        self::assertGreaterThan(0, $eveningId, 'M1: evening shift id returned');
        self::assertNotSame($morningId, $eveningId, 'M1: two distinct durable rows');

        $rows = $this->scheduleRowsForClinicianDay($this->clinicianId, $this->clinicA, $this->locA1, 1);
        self::assertCount(2, $rows, 'M1: exactly two rows persist for (Clinic A, A1, weekday 1)');
        $starts = array_column($rows, 'start_time');
        sort($starts);
        self::assertSame(['08:00:00', '16:00:00'], $starts, 'M1: both shifts persist with their own windows');
        foreach ($rows as $row) {
            self::assertSame($this->clinicA, (int) $row['clinic_id'], 'M1: row owned by the trusted Clinic A');
            self::assertSame($this->locA1, (int) $row['location_id'], 'M1: row bound to the explicit Location A1');
            self::assertSame(1, (int) $row['is_active'], 'M1: both shifts active');
        }
        self::assertSame(1, $this->countClinicianRowsForUser($this->professionalUserId), 'M1: still ONE professional identity');
    }

    // ==================================================================
    // M2 — overlapping create (10:00-14:00 over 08:00-12:00) is rejected
    //      with the SPECIFIC new reason `overlapping_shift` (400 envelope).
    // ==================================================================

    public function testOverlappingCreateRejectedWithSpecificReason(): void
    {
        $first = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 2,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $first->get_status(), 'M2 precondition: first shift created (error=' . $this->errorCode($first) . ')');

        $overlap = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 2,
            'start_time' => '10:00',
            'end_time' => '14:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);

        self::assertSame(400, $overlap->get_status(), 'M2: an overlapping shift must be rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($overlap), 'M2: established envelope code reused (no new error format)');
        self::assertSame(
            'overlapping_shift',
            (string) ($this->errorEnvelope($overlap)['data']['errors']['start_time,end_time'] ?? ''),
            'M2 DEFECT: the overlap rejection must carry the DISTINCT reason overlapping_shift so operators can tell it apart '
            . 'from duplicate_schedule_day (pre-fix the same request fails for the WRONG reason); envelope='
            . wp_json_encode($this->errorEnvelope($overlap))
        );

        $rows = $this->scheduleRowsForClinicianDay($this->clinicianId, $this->clinicA, $this->locA1, 2);
        self::assertCount(1, $rows, 'M2: the rejected overlap wrote no row');
        self::assertSame('08:00:00', (string) $rows[0]['start_time'], 'M2: the surviving row is the first shift, untouched');
    }

    // ==================================================================
    // M3 — exact duplicate (same weekday + Location + start_time) remains
    //      rejected with the STABLE `duplicate_schedule_day` reason.
    // ==================================================================

    public function testExactDuplicateStartRemainsRejectedWithStableReason(): void
    {
        $first = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 3,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $first->get_status(), 'M3 precondition: first shift created (error=' . $this->errorCode($first) . ')');

        $duplicate = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 3,
            'start_time' => '09:00',
            'end_time' => '14:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);

        self::assertSame(400, $duplicate->get_status(), 'M3: an exact duplicate start_time must remain rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($duplicate), 'M3: stable machine-readable code preserved');
        self::assertSame(
            'duplicate_schedule_day',
            (string) ($this->errorEnvelope($duplicate)['data']['errors']['day_of_week'] ?? ''),
            'M3: stable machine-readable reason preserved for exact duplicates; envelope='
            . wp_json_encode($this->errorEnvelope($duplicate))
        );

        $rows = $this->scheduleRowsForClinicianDay($this->clinicianId, $this->clinicA, $this->locA1, 3);
        self::assertCount(1, $rows, 'M3: the rejected duplicate wrote no row');
    }

    // ==================================================================
    // M4 — touching boundaries (…12:00 then 12:00…) are NOT an overlap.
    // ==================================================================

    public function testTouchingBoundariesAllowed(): void
    {
        $first = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 4,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $first->get_status(), 'M4 precondition: first shift created (error=' . $this->errorCode($first) . ')');

        $touching = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 4,
            'start_time' => '12:00',
            'end_time' => '16:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(
            200,
            $touching->get_status(),
            'M4 DEFECT: touching boundaries (12:00 end / 12:00 start) are NOT an overlap and must be allowed '
            . '(error=' . $this->errorCode($touching) . ')'
        );

        $rows = $this->scheduleRowsForClinicianDay($this->clinicianId, $this->clinicA, $this->locA1, 4);
        self::assertCount(2, $rows, 'M4: both touching shifts persist');
    }

    // ==================================================================
    // M5 — an UPDATE that stretches a shift into a neighbour's window is
    //      rejected with `overlapping_shift`; the row is left untouched.
    //      (Neighbour seeded durably — the service cannot create it pre-fix.)
    // ==================================================================

    public function testUpdateStretchingIntoNeighbourRejected(): void
    {
        $first = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 5,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $first->get_status(), 'M5 precondition: first shift created (error=' . $this->errorCode($first) . ')');
        $firstId = (int) ($first->get_data()['data']['id'] ?? 0);
        self::assertGreaterThan(0, $firstId, 'M5 precondition: first shift id returned');

        $neighbourId = $this->insertScheduleRowRaw($this->clinicA, $this->locA1, 5, '16:00:00', '20:00:00', 1);
        self::assertGreaterThan(0, $neighbourId, 'M5 precondition: durable non-overlapping neighbour row');

        $stretch = $this->dispatch('PUT', self::NS . '/config/schedules/' . $firstId, [
            'end_time' => '17:00',
        ], $this->clinicA, $this->managerAId);

        self::assertSame(
            400,
            $stretch->get_status(),
            'M5 DEFECT: an update stretching a shift into a neighbour\'s window must be rejected '
            . '(pre-fix update() performs no overlap check and succeeds)'
        );
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($stretch), 'M5: established envelope code reused');
        self::assertSame(
            'overlapping_shift',
            (string) ($this->errorEnvelope($stretch)['data']['errors']['start_time,end_time'] ?? ''),
            'M5: update overlap carries the same DISTINCT reason; envelope=' . wp_json_encode($this->errorEnvelope($stretch))
        );

        $row = $this->scheduleRow($firstId);
        self::assertNotNull($row, 'M5: the stretched row still exists');
        self::assertSame('08:00:00', (string) $row['start_time'], 'M5: rejected update wrote nothing (start untouched)');
        self::assertSame('12:00:00', (string) $row['end_time'], 'M5: rejected update wrote nothing (end untouched)');
    }

    // ==================================================================
    // M6 — an INACTIVE row never blocks another shift; reactivating a row
    //      into an overlap is rejected. Deactivation is always allowed.
    // ==================================================================

    public function testInactiveRowDoesNotBlockAndReactivationIntoOverlapRejected(): void
    {
        $active = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 6,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $active->get_status(), 'M6 precondition: active shift created (error=' . $this->errorCode($active) . ')');
        $activeId = (int) ($active->get_data()['data']['id'] ?? 0);
        self::assertGreaterThan(0, $activeId, 'M6 precondition: active shift id returned');

        // An inactive overlapping shift must NOT be blocked by the active one.
        $inactive = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 6,
            'start_time' => '10:00',
            'end_time' => '14:00',
            'is_active' => false,
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(
            200,
            $inactive->get_status(),
            'M6 DEFECT: an INACTIVE overlapping shift must not be blocked (invariant: no two ACTIVE rows overlap) '
            . '(error=' . $this->errorCode($inactive) . ')'
        );
        $inactiveId = (int) ($inactive->get_data()['data']['id'] ?? 0);
        self::assertGreaterThan(0, $inactiveId, 'M6: inactive shift id returned');

        // Reactivating it into the overlap must be rejected.
        $reactivate = $this->dispatch('PUT', self::NS . '/config/schedules/' . $inactiveId, [
            'is_active' => true,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(400, $reactivate->get_status(), 'M6: reactivating a row into an overlap must be rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($reactivate), 'M6: established envelope code reused');
        self::assertSame(
            'overlapping_shift',
            (string) ($this->errorEnvelope($reactivate)['data']['errors']['start_time,end_time'] ?? ''),
            'M6: reactivation overlap carries the same DISTINCT reason; envelope=' . wp_json_encode($this->errorEnvelope($reactivate))
        );
        $stillInactive = $this->scheduleRow($inactiveId);
        self::assertNotNull($stillInactive, 'M6: the reactivation target still exists');
        self::assertSame(0, (int) $stillInactive['is_active'], 'M6: rejected reactivation wrote nothing (still inactive)');

        // Deactivating the active shift is always allowed (the escape hatch)…
        $deactivate = $this->dispatch('PUT', self::NS . '/config/schedules/' . $activeId, [
            'is_active' => false,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $deactivate->get_status(), 'M6: deactivation must always be allowed (error=' . $this->errorCode($deactivate) . ')');

        // …and an existing INACTIVE row never blocks a new ACTIVE shift whose
        // window overlaps it (different start_time — exact duplicates stay rejected).
        $third = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 6,
            'start_time' => '10:30',
            'end_time' => '14:30',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(
            200,
            $third->get_status(),
            'M6: an existing INACTIVE row must not block a new ACTIVE overlapping shift '
            . '(error=' . $this->errorCode($third) . ')'
        );

        $rows = $this->scheduleRowsForClinicianDay($this->clinicianId, $this->clinicA, $this->locA1, 6);
        self::assertCount(3, $rows, 'M6: all three rows persist (two inactive, one active)');
    }

    // ==================================================================
    // M7 — slot generation for two non-overlapping shifts produces the
    //      UNION of both shifts' slots, identically regardless of the row
    //      insertion order (determinism assertion).
    // ==================================================================

    public function testSlotGenerationUnionAndInsertionOrderDeterminism(): void
    {
        // The weekday under test = the weekday of A1-local "tomorrow", so the
        // horizon (7 dates) contains exactly one matching calendar date.
        $reference = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expectedDate = $reference->setTimezone(new DateTimeZone(self::TZ_PRIMARY))->modify('+1 day')->format('Y-m-d');
        $expectedDow = self::toIranianDow((int) (new DateTimeImmutable($expectedDate . ' 12:00:00', new DateTimeZone(self::TZ_PRIMARY)))->format('w'));
        $this->setClinicHorizon($this->clinicA, self::HORIZON);

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Round 1 — morning shift inserted first, evening second.
        $this->createShiftOrFail($expectedDow, '09:00', '10:00', 'M7 round 1: morning shift created');
        $this->createShiftOrFail($expectedDow, '14:00', '15:00', 'M7 DEFECT round 1: evening shift created');
        $afterRound1 = $this->runProductionSlotSweep();
        $round1 = $this->slotDateTimesForClinician($this->clinicianId, $this->clinicA);

        // Round 2 — the SAME two shifts, inserted in the OPPOSITE order.
        $this->deleteSchedulesAndSlotsForClinician($this->clinicianId, $this->clinicA);
        $this->createShiftOrFail($expectedDow, '14:00', '15:00', 'M7 DEFECT round 2: evening shift created first');
        $this->createShiftOrFail($expectedDow, '09:00', '10:00', 'M7 round 2: morning shift created second');
        $afterRound2 = $this->runProductionSlotSweep();
        $round2 = $this->slotDateTimesForClinician($this->clinicianId, $this->clinicA);

        if ($this->localDate($before, self::TZ_PRIMARY) !== $this->localDate($afterRound1, self::TZ_PRIMARY)
            || $this->localDate($before, self::TZ_PRIMARY) !== $this->localDate($afterRound2, self::TZ_PRIMARY)) {
            self::markTestSkipped('INCONCLUSIVE (not a product RED): the Location-local calendar date changed during the sweep');
        }

        $expected = [
            $expectedDate . ' 09:00:00',
            $expectedDate . ' 09:30:00',
            $expectedDate . ' 14:00:00',
            $expectedDate . ' 14:30:00',
        ];
        self::assertSame(
            $expected,
            $round1,
            'M7: round 1 must produce the UNION of both shifts\' slots (09:00/09:30 from 09:00-10:00 + 14:00/14:30 from 14:00-15:00)'
        );
        self::assertSame(
            $expected,
            $round2,
            'M7 DETERMINISM: the opposite insertion order must produce the byte-identical slot set (no row-order dependence)'
        );
        self::assertSame($round1, $round2, 'M7 DETERMINISM: both rounds agree');
    }

    // ==================================================================
    // M8 — wp-admin NARROW fail-closed guard: when two rows match
    //      (clinician, day, Clinic, Location), save() refuses to guess and
    //      writes NOTHING (no silent mis-edit of the wrong shift).
    // ==================================================================

    public function testWpAdminMultiRowSaveTargetsExplicitSecondRow(): void
    {
        $first = $this->insertScheduleRowRaw($this->clinicA, $this->locA1, 0, '08:00:00', '12:00:00', 1);
        $second = $this->insertScheduleRowRaw($this->clinicA, $this->locA1, 0, '16:00:00', '20:00:00', 1);
        self::assertGreaterThan(0, $first, 'precondition: first schedule row inserted');
        self::assertGreaterThan(0, $second, 'precondition: second schedule row inserted');
        $notice = $this->dispatchAdminAction('cpms_schedule_save', [
            'clinician_id' => (string) $this->clinicianId,
            'sched_submit' => '0:' . $this->locA1 . ':second',
            'sched' => [0 => [$this->locA1 => ['second' => [
                'schedule_id' => (string) $second, 'start_time' => '17:00', 'end_time' => '19:00',
                'appointment_duration_min' => '20', 'slot_capacity' => '1', 'is_active' => '1',
            ]]]],
        ], fn () => ClinicianAdminPage::saveSchedules(), $this->managerAId);
        self::assertStringNotContainsString('خطا', $notice, 'explicit row update succeeds: ' . $notice);
        $row1 = $this->scheduleRow($first); $row2 = $this->scheduleRow($second);
        self::assertSame('08:00:00', (string) $row1['start_time']);
        self::assertSame('17:00:00', (string) $row2['start_time']);
    }

    public function testWpAdminMatrixRendersEveryShiftAndAddRow(): void
    {
        $first = $this->insertScheduleRowRaw($this->clinicA, $this->locA1, 0, '08:00:00', '12:00:00', 1);
        $second = $this->insertScheduleRowRaw($this->clinicA, $this->locA1, 0, '16:00:00', '20:00:00', 1);
        self::assertGreaterThan(0, $first); self::assertGreaterThan(0, $second);
        wp_set_current_user($this->managerAId);
        $_GET['clinician_id'] = $this->clinicianId;
        ob_start(); ClinicianAdminPage::render(); $html = (string) ob_get_clean();
        unset($_GET['clinician_id']);
        self::assertStringContainsString('value="' . $first . '"', $html);
        self::assertStringContainsString('value="' . $second . '"', $html);
        self::assertStringContainsString('sched_submit" value="0:' . $this->locA1 . ':new-', $html);
    }

    public function testWpAdminForeignOrMismatchedRowFailsClosed(): void
    {
        $foreign = $this->insertScheduleRowRaw($this->clinicB, $this->locB1, 0, '08:00:00', '12:00:00', 1);
        $local = $this->insertScheduleRowRaw($this->clinicA, $this->locA1, 1, '16:00:00', '20:00:00', 1);
        self::assertGreaterThan(0, $foreign); self::assertGreaterThan(0, $local);
        foreach ([[$foreign, 0, $this->locA1], [$local, 0, $this->locA1]] as [$id, $day, $loc]) {
            $before = $this->scheduleRow((int) $id);
            $notice = $this->dispatchAdminAction('cpms_schedule_save', [
                'clinician_id' => (string) $this->clinicianId, 'sched_submit' => $day . ':' . $loc . ':x',
                'sched' => [$day => [$loc => ['x' => ['schedule_id' => (string) $id, 'start_time' => '09:00', 'end_time' => '10:00', 'is_active' => '1']]]],
            ], fn () => ClinicianAdminPage::saveSchedules(), $this->managerAId);
            self::assertStringContainsString('یافت نشد', $notice);
            self::assertSame($before, $this->scheduleRow((int) $id));
        }
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Timezone-database availability is an ENVIRONMENT precondition, not a
     * product contract — a missing identifier must never be reported as a
     * product RED.
     */
    private function assertTimezonesAvailable(): void
    {
        $all = timezone_identifiers_list();
        foreach ([self::TZ_PRIMARY, self::TZ_SECONDARY] as $tz) {
            self::assertContains($tz, $all, "ENVIRONMENT precondition: PHP timezone database must provide {$tz}");
        }
    }

    /**
     * Create one M7 shift (30-minute grid) through the REAL product contract.
     */
    private function createShiftOrFail(int $dayOfWeek, string $start, string $end, string $message): void
    {
        $created = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => $dayOfWeek,
            'start_time' => $start,
            'end_time' => $end,
            'appointment_duration_min' => 30,
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $created->get_status(), $message . ' (error=' . $this->errorCode($created) . ')');
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

    private function localDate(DateTimeImmutable $utcInstant, string $tz): string
    {
        return $utcInstant->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
    }

    private static function toIranianDow(int $gregorianW): int
    {
        $map = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 0];

        return $map[$gregorianW];
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

    /**
     * REAL production operational path: enqueue → App::runTick → dispatcher →
     * SlotsGenerateHandler.
     */
    private function runProductionSlotSweep(): DateTimeImmutable
    {
        $queue = App::jobs();
        $jobId = $queue->enqueue('slots.generate', [], new DateTimeImmutable('now', new DateTimeZone('UTC')), 3, 3);
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
            throw new \RuntimeException('P6S4_ADMIN_REDIRECT_EXIT_SIMULATED:' . (string) $location);
        };
        add_filter('wp_redirect', $redirect);
        try {
            $handler();
            self::fail('اکشن admin بدون redirect پایان یافت — قرارداد handler نقض شده است');
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

    private function authz(): AuthorizationService
    {
        return new AuthorizationService(new MembershipRepository(App::db()));
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
     * Raw error body — the REST server's serialized WP_Error shape:
     * ['code' => …, 'message' => …, 'data' => [...]].
     *
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

    // ---------------- Fixtures (dynamic ids only) ----------------

    private function createOrganization(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, "active", %s, %s)', // phpcs:ignore
            'P6S4 Org ' . $unique,
            'p6s4-org-' . $unique,
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
            'P6S4 Clinic ' . $tag . ' ' . $unique,
            'p6s4-clinic-' . strtolower($tag) . '-' . $unique,
            self::TZ_PRIMARY,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'precondition: clinic id is dynamic (never the seeded legacy clinic 1)');

        return $id;
    }

    private function createLocation(int $clinicId, int $isPrimary, string $timezone): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                 (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', // phpcs:ignore
            $clinicId,
            'P6S4 Loc ' . $unique,
            'p6s4-loc-' . $unique,
            $timezone,
            $isPrimary,
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
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@p6s4.test');
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

    /**
     * Durable second-shift seeding that bypasses the service — required for
     * RED-time setup (the pre-fix service cannot create a second shift) and
     * for the wp-admin guard setup at any revision.
     */
    private function insertScheduleRowRaw(
        int $clinicId,
        int $locationId,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        int $isActive
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $res = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule
                 (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time,
                  appointment_duration_min, slot_capacity, is_active, created_at, updated_at)
             VALUES (%d, %d, %d, %d, %s, %s, %d, %d, %d, %s, %s)', // phpcs:ignore
            $clinicId,
            $locationId,
            $this->clinicianId,
            $dayOfWeek,
            $startTime,
            $endTime,
            30,
            1,
            $isActive,
            $now,
            $now
        ));
        self::assertNotFalse($res, 'precondition: raw schedule row inserted (' . $wpdb->last_error . ')');
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: raw schedule row id (' . $wpdb->last_error . ')');

        return $id;
    }

    // ---------------- Durable-state probes ----------------

    /**
     * @return array<string, mixed>|null
     */
    private function scheduleRow(int $scheduleId): ?array
    {
        return App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_schedule') . ' WHERE id = %d',
            [$scheduleId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scheduleRowsForClinicianDay(int $clinicianId, int $clinicId, int $locationId, int $dayOfWeek): array
    {
        return App::db()->fetchAll(
            'SELECT * FROM ' . App::db()->table('cpms_schedule') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND location_id = %d AND day_of_week = %d ORDER BY start_time, id',
            [$clinicianId, $clinicId, $locationId, $dayOfWeek]
        );
    }

    /**
     * @return list<string> "Y-m-d H:i:s" pairs, ordered — the M7 determinism probe.
     */
    private function slotDateTimesForClinician(int $clinicianId, int $clinicId): array
    {
        $rows = App::db()->fetchAll(
            'SELECT slot_date, slot_time FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND clinic_id = %d ORDER BY slot_date, slot_time, id',
            [$clinicianId, $clinicId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = (string) $row['slot_date'] . ' ' . (string) $row['slot_time'];
        }

        return $out;
    }

    private function deleteSchedulesAndSlotsForClinician(int $clinicianId, int $clinicId): void
    {
        global $wpdb;
        $slots = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE clinician_id = %d AND clinic_id = %d', // phpcs:ignore
            $clinicianId,
            $clinicId
        ));
        $schedules = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'cpms_schedule WHERE clinician_id = %d AND clinic_id = %d', // phpcs:ignore
            $clinicianId,
            $clinicId
        ));
        self::assertGreaterThan(0, $slots, 'precondition: round slots wiped for the determinism re-run');
        self::assertGreaterThan(0, $schedules, 'precondition: round schedules wiped for the determinism re-run');
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
        // Children first (FK-safe order): schedule rows reference locations;
        // clinician_locations references both clinicians and locations.
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
