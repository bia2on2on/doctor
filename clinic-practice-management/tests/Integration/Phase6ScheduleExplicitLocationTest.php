<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Phase 6 Slice 3 — «Explicit, authorized Location for Schedule creation».
 *
 * CONTRACT UNDER TEST (the modern Scheduling write contract):
 *
 *  1. Schedule creation REQUIRES an explicit `location_id` (T6).
 *  2. The persisted Schedule is bound to EXACTLY that validated Location (T1).
 *  3. Schedule read/list exposes the persisted `location_id` (T2).
 *  4. The Location must be real, ACTIVE, and belong to the TRUSTED Clinic —
 *     which comes from server-side scope, never from payload authority (T5).
 *  5. A foreign / missing / inactive Location fails CLOSED with not-found
 *     parity (404 `CLINIC_NOT_FOUND`, identical envelope) — no cross-Clinic
 *     existence is exposed and no schedule row is written (T5).
 *  6. No silent replacement by Primary/first Location anywhere on the core
 *     creation path (T1/T5/T6).
 *  7. Duplicate-weekday detection is LOCATION-aware: the same professional may
 *     hold the same weekday at two different Locations of the same Clinic
 *     (T3), while the same (Clinic, Location, clinician, weekday) remains
 *     rejected with the stable `duplicate_schedule_day` envelope (T4).
 *     Multi-shift is NOT enabled in this slice.
 *  8. Slot generation inherits the persisted Schedule Location and its
 *     Location-local IANA timezone (T7 — one focused inheritance assertion;
 *     the full temporal semantics are covered by the Slice 2 suites).
 *  9. Shared-professional durable Clinic participation (Phase 4 Slice 1)
 *     remains correct under the new contract (T8).
 *
 * PRE-FIX PRODUCT DEFECT (classification B — pre-existing, reverified live):
 *  - `ScheduleService::create()` never accepts `location_id`;
 *  - `ScheduleRepository::create()` falls back to `PrimaryLocationResolver`
 *    whenever `location_id` is absent, so an explicit non-primary Location
 *    (or a foreign one) is SILENTLY REPLACED by the Clinic's primary Location;
 *  - the duplicate pre-check `findByClinicianDayInClinic()` is Clinic-wide,
 *    so the same weekday at a second Location of the same Clinic is rejected
 *    although the DB unique key (Migration 0014) is already Location-aware.
 *
 * FIXTURE RULES (no first-row tenant, no fixed tenant ids, no fixture bypass):
 *  - dynamic Organization + dynamically allocated Clinics/Locations whose ids
 *    are asserted to be > 1 (never the seeded legacy Clinic 1 / Location 1);
 *  - one WP user ↔ exactly one `cpms_clinicians` row (asserted);
 *  - durable memberships through the production primitive
 *    (`cpms_test_seed_membership` → `MembershipService::create_membership`);
 *  - the trusted Clinic is established through the real staff REST boundary
 *    (`X-CPMS-Clinic-Id`) or an explicit `ClinicScope` for service-level
 *    assertions — never injected as payload authority;
 *  - Location A2 deliberately carries a DIFFERENT IANA timezone from the
 *    Clinic (Pacific/Kiritimati UTC+14 vs Asia/Tehran UTC+3:30) so the slot
 *    inheritance assertion (T7) measures the Location's timezone frame.
 */
final class Phase6ScheduleExplicitLocationTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    /** Primary Location + Clinic timezone (production-realistic). */
    private const TZ_PRIMARY = 'Asia/Tehran';

    /** Secondary Location timezone — deliberately different frame (UTC+14). */
    private const TZ_SECONDARY = 'Pacific/Kiritimati';

    /** Slot-generation horizon for T7 (7 dates ⇒ exactly one matches the weekday). */
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
        $this->professionalUserId = $this->makeUser('p6s3_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $this->clinicianId = $this->insertClinician($this->clinicA, $this->professionalUserId, 'Dr P6S3 Professional');
        // Durable active participation in BOTH Clinics (SoT = cpms_clinic_memberships).
        self::assertGreaterThan(0, cpms_test_seed_membership($this->professionalUserId, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR));
        self::assertGreaterThan(0, cpms_test_seed_membership($this->professionalUserId, $this->clinicB, RolesAndCapabilities::ROLE_DOCTOR));

        $this->managerAId = $this->makeUser('p6s3_mgr_a', RolesAndCapabilities::ROLE_MANAGER);
        self::assertGreaterThan(0, cpms_test_seed_membership($this->managerAId, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER));
        $this->managerBId = $this->makeUser('p6s3_mgr_b', RolesAndCapabilities::ROLE_MANAGER);
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
    // T1 — create explicitly for A2 under trusted Clinic A ⇒ persisted
    //      schedule.location_id == A2 (never the primary A1).
    // ==================================================================

    public function testCreateExplicitlyAtSecondaryLocationPersistsThatLocation(): void
    {
        $created = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 2,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'location_id' => $this->locA2,
        ], $this->clinicA, $this->managerAId);

        self::assertSame(
            200,
            $created->get_status(),
            'T1: explicit Location A2 under trusted Clinic A must succeed (error=' . $this->errorCode($created) . ')'
        );
        $scheduleId = (int) ($created->get_data()['data']['id'] ?? 0);
        self::assertGreaterThan(0, $scheduleId, 'T1: schedule row id returned');

        $row = $this->scheduleRow($scheduleId);
        self::assertNotNull($row, 'T1: durable schedule row exists');
        self::assertSame(
            $this->locA2,
            (int) $row['location_id'],
            'T1 DEFECT: persisted schedule.location_id must be the EXPLICITLY requested A2. '
            . 'Pre-fix, ScheduleRepository silently substitutes the primary Location (PrimaryLocationResolver).'
        );
        self::assertSame($this->clinicA, (int) $row['clinic_id'], 'T1: durable row owned by the trusted Clinic A');
        self::assertSame($this->clinicianId, (int) $row['clinician_id'], 'T1: durable row references the professional identity');
    }

    // ==================================================================
    // T2 — schedule read/list exposes the persisted location_id.
    // ==================================================================

    public function testScheduleReadExposesPersistedLocationId(): void
    {
        $created = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 4,
            'start_time' => '10:00',
            'end_time' => '13:00',
            'location_id' => $this->locA2,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $created->get_status(), 'T2 precondition: explicit A2 create succeeds (error=' . $this->errorCode($created) . ')');
        $scheduleId = (int) ($created->get_data()['data']['id'] ?? 0);

        $list = $this->dispatch('GET', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $list->get_status(), 'T2: scoped read succeeds');

        $rows = $list->get_data()['data'] ?? [];
        self::assertIsArray($rows, 'T2: list returns an array');
        $found = null;
        foreach ($rows as $r) {
            if (is_array($r) && (int) ($r['id'] ?? 0) === $scheduleId) {
                $found = $r;
            }
        }
        self::assertNotNull($found, 'T2: the created row appears in the scoped list');
        self::assertSame(
            $this->locA2,
            (int) ($found['location_id'] ?? 0),
            'T2 DEFECT: the schedule view must expose the persisted location_id (A2)'
        );
    }

    // ==================================================================
    // T3 — same clinician + same weekday at A1 AND A2 of ONE Clinic:
    //      both may exist (duplicate detection is Location-aware).
    // ==================================================================

    public function testSameWeekdayAtTwoLocationsOfOneClinicBothPersist(): void
    {
        $atA1 = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $atA1->get_status(), 'T3: first row (A1) created (error=' . $this->errorCode($atA1) . ')');

        $atA2 = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'location_id' => $this->locA2,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(
            200,
            $atA2->get_status(),
            'T3 DEFECT: the same weekday at a DIFFERENT Location of the same Clinic must be allowed. '
            . 'Pre-fix, the Clinic-wide duplicate pre-check rejects it (error=' . $this->errorCode($atA2) . ')'
        );

        $locations = $this->scheduleLocationsForClinicianDay($this->clinicianId, $this->clinicA, 1);
        self::assertCount(2, $locations, 'T3: exactly two rows for (Clinic A, weekday 1)');
        self::assertContains($this->locA1, $locations, 'T3: the A1 row exists');
        self::assertContains($this->locA2, $locations, 'T3: the A2 row exists');
    }

    // ==================================================================
    // T4 — duplicate same clinician + same weekday at the SAME Location
    //      (A2) remains rejected with the stable envelope. Multi-shift
    //      is NOT enabled in this slice.
    // ==================================================================

    public function testDuplicateSameWeekdaySameLocationRemainsRejected(): void
    {
        $first = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 5,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'location_id' => $this->locA2,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $first->get_status(), 'T4 precondition: first A2 row created (error=' . $this->errorCode($first) . ')');

        $duplicate = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 5,
            'start_time' => '14:00',
            'end_time' => '18:00',
            'location_id' => $this->locA2,
        ], $this->clinicA, $this->managerAId);

        self::assertSame(400, $duplicate->get_status(), 'T4: duplicate at the same Location+weekday must be rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($duplicate), 'T4: stable machine-readable code preserved');
        self::assertSame(
            'duplicate_schedule_day',
            (string) ($this->errorEnvelope($duplicate)['data']['errors']['day_of_week'] ?? ''),
            'T4: stable machine-readable reason preserved; envelope=' . wp_json_encode($this->errorEnvelope($duplicate))
        );

        // The rejected duplicate creates no row; the single row is at A2 (the explicit Location).
        $locations = $this->scheduleLocationsForClinicianDay($this->clinicianId, $this->clinicA, 5);
        self::assertSame(
            [$this->locA2],
            $locations,
            'T4 DEFECT: exactly one row must exist and it must be at the explicit A2. '
            . 'Pre-fix the first row lands at the primary Location instead.'
        );
    }

    // ==================================================================
    // T5 — foreign Location (B1) under trusted Clinic A fails CLOSED
    //      with not-found parity; no schedule is inserted.
    // ==================================================================

    public function testForeignLocationFailsClosedWithNotFoundParity(): void
    {
        // ---- Core contract (service level — the path the wp-admin boundary takes) ----
        $before = $this->countScheduleRows($this->clinicianId, $this->clinicA);

        $foreign = $this->withScope($this->clinicA, function (): ?BookingException {
            try {
                App::scheduleService()->create($this->managerAId, [
                    'clinician_id' => $this->clinicianId,
                    'day_of_week' => 3,
                    'start_time' => '09:00',
                    'end_time' => '12:00',
                    'location_id' => $this->locB1,
                ]);
            } catch (BookingException $e) {
                return $e;
            }

            return null;
        });
        self::assertNotNull(
            $foreign,
            'T5 DEFECT: a Location owned by another Clinic must fail closed at the core contract. '
            . 'Pre-fix it is silently replaced by the primary Location and a schedule row IS written.'
        );
        self::assertSame('CLINIC_NOT_FOUND', $foreign->errorCode, 'T5: not-found parity code (no existence disclosure)');
        self::assertSame(404, $foreign->httpStatus, 'T5: not-found parity status');

        // Parity: a NONEXISTENT Location gets the byte-identical envelope.
        $missing = $this->withScope($this->clinicA, function (): ?BookingException {
            try {
                App::scheduleService()->create($this->managerAId, [
                    'clinician_id' => $this->clinicianId,
                    'day_of_week' => 3,
                    'start_time' => '09:00',
                    'end_time' => '12:00',
                    'location_id' => 999999,
                ]);
            } catch (BookingException $e) {
                return $e;
            }

            return null;
        });
        self::assertNotNull($missing, 'T5: a nonexistent Location must fail closed');
        self::assertSame($foreign->errorCode, $missing->errorCode, 'T5 PARITY: foreign and nonexistent Location share the same code');
        self::assertSame($foreign->httpStatus, $missing->httpStatus, 'T5 PARITY: foreign and nonexistent Location share the same status');
        self::assertSame($foreign->getMessage(), $missing->getMessage(), 'T5 PARITY: foreign and nonexistent Location share the same message (no enumeration)');

        self::assertSame(
            $before,
            $this->countScheduleRows($this->clinicianId, $this->clinicA),
            'T5: the denied foreign-Location create wrote no schedule row (and no regeneration side effect)'
        );

        // ---- REST boundary (established scope-binding semantics) ----
        // Re-anchor the baseline: in a pre-fix RED the service-level attempt
        // above DID write a row; the REST section must measure ITS OWN effect.
        $beforeRest = $this->countScheduleRows($this->clinicianId, $this->clinicA);
        $restForeign = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 3,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'location_id' => $this->locB1,
        ], $this->clinicA, $this->managerAId);
        self::assertNotSame(200, $restForeign->get_status(), 'T5 REST: foreign Location selector must never be accepted');
        $restMissing = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 3,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'location_id' => 999999,
        ], $this->clinicA, $this->managerAId);
        self::assertSame($restForeign->get_status(), $restMissing->get_status(), 'T5 REST PARITY: foreign and nonexistent selector share the same status');
        self::assertSame($this->errorCode($restForeign), $this->errorCode($restMissing), 'T5 REST PARITY: foreign and nonexistent selector share the same code');
        self::assertSame(
            $beforeRest,
            $this->countScheduleRows($this->clinicianId, $this->clinicA),
            'T5 REST: no schedule row was written by the denied foreign-Location request'
        );
    }

    // ==================================================================
    // T6 — missing location_id on the modern create contract fails
    //      CLOSED instead of silently using the Primary Location.
    // ==================================================================

    public function testMissingLocationIdFailsClosedInsteadOfPrimary(): void
    {
        // ---- Core contract (service level) ----
        $thrown = $this->withScope($this->clinicA, function (): ?BookingException {
            try {
                App::scheduleService()->create($this->managerAId, [
                    'clinician_id' => $this->clinicianId,
                    'day_of_week' => 0,
                    'start_time' => '09:00',
                    'end_time' => '12:00',
                ]);
            } catch (BookingException $e) {
                return $e;
            }

            return null;
        });
        self::assertNotNull(
            $thrown,
            'T6 DEFECT: missing location_id must fail closed at the core contract. '
            . 'Pre-fix the row is created silently at the primary Location.'
        );
        self::assertSame('CLINIC_VALIDATION_FAILED', $thrown->errorCode, 'T6: stable validation-failure code');
        self::assertSame(400, $thrown->httpStatus, 'T6: 400 status');
        self::assertSame('required', (string) ($thrown->data['errors']['location_id'] ?? ''), 'T6: stable machine-readable reason location_id=required');
        self::assertSame(0, $this->countScheduleRows($this->clinicianId, $this->clinicA), 'T6: no row written without an explicit Location');

        // ---- REST (modern API shape) ----
        $rest = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 0,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ], $this->clinicA, $this->managerAId);
        self::assertSame(400, $rest->get_status(), 'T6 REST: missing location_id must be rejected (not silently defaulted)');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($rest), 'T6 REST: stable code');
        self::assertSame('required', (string) ($this->errorEnvelope($rest)['data']['errors']['location_id'] ?? ''), 'T6 REST: stable reason');
        self::assertSame(
            0,
            $this->countScheduleRowsAtLocation($this->clinicianId, $this->clinicA, $this->locA1),
            'T6 DEFECT SIGNATURE: the classic silent fallback would have created a row at the PRIMARY Location A1'
        );
    }

    // ==================================================================
    // T7 — slots generated from an A2 schedule inherit location_id = A2
    //      and A2's Location-local timezone frame (focused inheritance).
    // ==================================================================

    public function testSlotsInheritExplicitSecondaryLocationAndItsTimezone(): void
    {
        // The weekday under test = the weekday of A2-local "tomorrow", so the
        // horizon (7 dates) contains exactly one matching calendar date:
        // A2-local tomorrow itself.
        $reference = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expectedDate = $reference->setTimezone(new DateTimeZone(self::TZ_SECONDARY))->modify('+1 day')->format('Y-m-d');
        $expectedDow = self::toIranianDow((int) (new DateTimeImmutable($expectedDate . ' 12:00:00', new DateTimeZone(self::TZ_SECONDARY)))->format('w'));

        // Create the A2 schedule through the REAL product contract.
        $created = $this->withScope($this->clinicA, fn (): array => App::scheduleService()->create(
            $this->managerAId,
            [
                'clinician_id' => $this->clinicianId,
                'day_of_week' => $expectedDow,
                'start_time' => '09:00',
                'end_time' => '10:00',
                'appointment_duration_min' => 30,
                'location_id' => $this->locA2,
            ]
        ));
        $scheduleId = (int) ($created['id'] ?? 0);
        self::assertGreaterThan(0, $scheduleId, 'T7 precondition: A2 schedule created');
        self::assertSame(
            $this->locA2,
            $this->scheduleLocationId($scheduleId),
            'T7 precondition: the schedule row is bound to the explicit A2 (T1 contract)'
        );
        self::assertSame($expectedDow, (int) $this->scheduleRow($scheduleId)['day_of_week'], 'T7 precondition: fixture weekday persisted');

        $this->setClinicHorizon($this->clinicA, self::HORIZON);

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $after = $this->runProductionSlotSweep();
        if ($this->localDate($before, self::TZ_SECONDARY) !== $this->localDate($after, self::TZ_SECONDARY)
            || $this->localDate($before, self::TZ_PRIMARY) !== $this->localDate($after, self::TZ_PRIMARY)) {
            self::markTestSkipped('INCONCLUSIVE (not a product RED): the Location-local calendar date changed during the sweep');
        }

        $slots = App::db()->fetchAll(
            'SELECT id, clinic_id, location_id, clinician_id, slot_date, slot_time FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND clinic_id = %d ORDER BY slot_date, slot_time',
            [$this->clinicianId, $this->clinicA]
        );
        $atA2 = array_values(array_filter($slots, fn (array $r): bool => (int) $r['location_id'] === $this->locA2));
        $atA1 = array_values(array_filter($slots, fn (array $r): bool => (int) $r['location_id'] === $this->locA1));

        self::assertNotEmpty(
            $atA2,
            'T7 DEFECT: the production sweep must generate slots for the A2 schedule at location_id = A2. '
            . 'Pre-fix the schedule row lands at the primary Location, so its slots appear at A1 instead.'
        );
        foreach ($atA2 as $slot) {
            self::assertSame($this->clinicA, (int) $slot['clinic_id'], 'T7: slot tenant = trusted Clinic A');
            self::assertSame($this->clinicianId, (int) $slot['clinician_id'], 'T7: slot professional identity preserved');
        }
        $dates = array_unique(array_map(fn (array $r): string => (string) $r['slot_date'], $atA2));
        self::assertSame(
            [$expectedDate],
            array_values($dates),
            'T7 DEFECT: generated slot_dates must be the A2 LOCAL calendar frame (its own IANA timezone). '
            . 'Expected=[' . $expectedDate . '] actual=[' . implode(',', array_values($dates)) . ']'
        );
        self::assertSame(
            0,
            count($atA1),
            'T7: no slot may be generated for this professional at A1 (A1 holds no schedule row)'
        );

        // Differential frame check (only meaningful when the frames actually
        // differ; the dedicated Slice 2 suites cover the coincident case).
        $clinicFrameDate = $reference->setTimezone(new DateTimeZone(self::TZ_PRIMARY))->modify('+1 day')->format('Y-m-d');
        if ($clinicFrameDate !== $expectedDate) {
            self::assertNotContains(
                $clinicFrameDate,
                $dates,
                'T7: a Clinic-timezone-framed generation would have produced the Clinic-frame date instead of the A2-local date'
            );
        }
    }

    // ==================================================================
    // T8 — existing shared-professional Clinic participation behavior
    //      remains correct under the explicit-Location contract.
    // ==================================================================

    public function testSharedProfessionalClinicParticipationPreserved(): void
    {
        self::assertSame(1, $this->countClinicianRowsForUser($this->professionalUserId), 'T8 precondition: ONE professional identity');
        self::assertSame($this->clinicA, $this->clinicianHomeClinic($this->clinicianId), 'T8 precondition: compatibility home column = A');

        // The professional (home A) is usable in Clinic B (durable ACTIVE
        // membership) through the REAL REST path with an explicit B Location.
        $createB = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 4,
            'start_time' => '14:00',
            'end_time' => '18:00',
            'location_id' => $this->locB1,
        ], $this->clinicB, $this->managerBId);
        self::assertSame(
            200,
            $createB->get_status(),
            'T8: a professional with durable ACTIVE membership in Clinic B must be usable in B (error=' . $this->errorCode($createB) . ')'
        );
        $rowBId = (int) ($createB->get_data()['data']['id'] ?? 0);
        self::assertSame($this->clinicB, $this->scheduleClinicId($rowBId), 'T8: the durable B row is owned by trusted Clinic B');
        self::assertSame($this->locB1, $this->scheduleLocationId($rowBId), 'T8: the B row is bound to the explicit B1');

        // The home Clinic keeps working under the new contract.
        $createA = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 4,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'location_id' => $this->locA1,
        ], $this->clinicA, $this->managerAId);
        self::assertSame(200, $createA->get_status(), 'T8: the home-Clinic A path keeps working (error=' . $this->errorCode($createA) . ')');
        $rowAId = (int) ($createA->get_data()['data']['id'] ?? 0);
        self::assertSame($this->clinicA, $this->scheduleClinicId($rowAId), 'T8: the A row is owned by Clinic A');
        self::assertSame($this->locA1, $this->scheduleLocationId($rowAId), 'T8: the A row is bound to the explicit A1');

        // No identity duplication, no participation mutation, home column intact.
        self::assertSame(1, $this->countClinicianRowsForUser($this->professionalUserId), 'T8: no duplicate professional identity created');
        self::assertSame($this->clinicA, $this->clinicianHomeClinic($this->clinicianId), 'T8: compatibility clinicians.clinic_id untouched');
        self::assertSame(
            2,
            $this->countMembershipsForUser($this->professionalUserId),
            'T8: the schedule path must not create/modify durable participation'
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
    private function assertTimezonesAvailable(): void
    {
        $all = timezone_identifiers_list();
        foreach ([self::TZ_PRIMARY, self::TZ_SECONDARY] as $tz) {
            self::assertContains($tz, $all, "ENVIRONMENT precondition: PHP timezone database must provide {$tz}");
        }
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
            'P6S3 Org ' . $unique,
            'p6s3-org-' . $unique,
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
            'P6S3 Clinic ' . $tag . ' ' . $unique,
            'p6s3-clinic-' . strtolower($tag) . '-' . $unique,
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
            'P6S3 Loc ' . $unique,
            'p6s3-loc-' . $unique,
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
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@p6s3.test');
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

    /**
     * @return list<int> location_ids of the clinician's rows for (clinic, day)
     */
    private function scheduleLocationsForClinicianDay(int $clinicianId, int $clinicId, int $dayOfWeek): array
    {
        $rows = App::db()->fetchAll(
            'SELECT location_id FROM ' . App::db()->table('cpms_schedule') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND day_of_week = %d ORDER BY location_id',
            [$clinicianId, $clinicId, $dayOfWeek]
        );

        return array_map('intval', array_column($rows, 'location_id'));
    }

    private function countScheduleRows(int $clinicianId, int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule') . ' WHERE clinician_id = %d AND clinic_id = %d',
            [$clinicianId, $clinicId]
        );
    }

    private function countScheduleRowsAtLocation(int $clinicianId, int $clinicId, int $locationId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND location_id = %d',
            [$clinicianId, $clinicId, $locationId]
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

    private function countMembershipsForUser(int $wpUserId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE wp_user_id = %d',
            [$wpUserId]
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
