<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Licensing\LicenseDecision;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Infrastructure\Security\Idempotency;
use ClinicCore\Infrastructure\Sms\CredentialVault;
use ClinicCore\Infrastructure\Sms\SmsProviderRegistry;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use Throwable;
use WP_UnitTestCase;

/**
 * Phase 7 Slice 2 — RED: Appointment Invariant I-3 (`HAS_ACTIVE_VISIT`)
 * and check-in serialization against cancel / reschedule.
 *
 * CONTRACT UNDER TEST (docs/state-machines/appointment.md §4, I-3):
 *
 *   T5 (patient cancel), T6 (staff cancel) and T7 (reschedule) are FORBIDDEN
 *   while the appointment has a genuinely ACTIVE Visit; the stable product
 *   error is `HAS_ACTIVE_VISIT`. A non-null `active_visit_id` alone is NOT
 *   evidence of an active Visit: ending a Visit through the documented
 *   lifecycle (V9 cancel) leaves the pointer behind, and the appointment
 *   operation must stay reachable in that state.
 *
 * ESTABLISHED ENGINEERING CONTRACT (Slice 2, serialization):
 *
 *   `VisitService::checkIn()` locks only the patient row and reads the
 *   appointment with a plain (non-locking) `find()`; `BookingService::cancel()`
 *   and `BookingService::reschedule()` lock the appointment row but never look
 *   at the Visit. Concurrently, one of the two operations observes a stale
 *   appointment state and the pair can commit a forbidden terminal state:
 *
 *     - a `cancelled_by_patient` / `rescheduled` appointment that carries a
 *       LIVE Visit (the patient was actually checked in), and/or
 *     - a replacement appointment created although the patient was checked in.
 *
 *   GREEN must serialize check-in and the appointment operation on the
 *   appointment row (or an equivalent DB-level guarantee). The concurrency
 *   tests below accept EITHER legal serialization winner and reject only
 *   states that are impossible after real serialization.
 *
 * RED SCOPE: this suite is expected to FAIL on the current HEAD — the failures
 * are the evidence that I-3 / serialization is missing. Bootstrap, fixture
 * inserts, FK/SQL and child processes must stay clean (no fixture/harness
 * failure may masquerade as RED evidence).
 *
 * CONCURRENCY EVIDENCE CLASSIFICATION (the two race tests):
 *
 *   1. HARNESS integrity — fork/connection/bootstrap failure or a child that
 *      produced no outcome: such an attempt proves nothing and fails loudly as
 *      a harness failure (an invalid RED, never product evidence);
 *   2. INVARIANT violated — a terminal appointment (cancelled/rescheduled)
 *      still carrying a LIVE Visit, a replacement written while the original
 *      stays confirmed, or drifted slot counters: RED evidence;
 *   3. PRODUCT ENVELOPE violated — a contender surfaced a raw PHP/DB failure
 *      instead of `ok` or a documented error code (e.g. `HAS_ACTIVE_VISIT`):
 *      RED evidence, because without serialization on the appointment row a
 *      contender keeps executing on state the other contender has already
 *      invalidated (observed on HEAD: a statement rolled back by a lock
 *      deadlock being ignored, and the loser reporting an unrelated 404).
 *
 *   Either legal serialization winner is accepted; no winner is assumed.
 */
final class Phase7Slice2ActiveVisitInvariantRedTest extends WP_UnitTestCase
{
    /** Location timezone (production-realistic; matches the other Phase suites). */
    private const TZ = 'Asia/Tehran';

    /** Barrier lead time before contenders fire (seconds). */
    private const BARRIER_SEC = 1.5;

    /** Barrier-synchronized attempts per binary race (fresh appointment each). */
    private const RACE_ATTEMPTS = 3;

    /** Live Visit statuses (mirrors VisitService::ACTIVE_VISIT_STATUSES). */
    private const LIVE_VISIT_STATUSES = [
        'checked_in',
        'waiting',
        'called',
        'in_consultation',
        'consultation_completed',
        'awaiting_payment',
        'paid',
    ];

    private int $orgId = 0;
    private int $clinicId = 0;
    private int $locationId = 0;
    private int $clinicianId = 0;
    private int $patientId = 0;
    private int $secretaryUserId = 0;
    private int $doctorUserId = 0;
    private int $patientUserId = 0;

    /** @var list<int> */
    private array $userIds = [];

    private string $fileTag = '';

    /**
     * High-water marks for the two GLOBAL tables this suite writes through
     * product code (`cpms_jobs` via the job queue, `cpms_operational_logs`
     * via OpLogger). Neither table has a `clinic_id` column, so the
     * Clinic-scoped purge below cannot find them — leaving rows behind would
     * poison later queue-dependent suites (dispatcher tick budget / queue
     * assertions). Only rows created by THIS test are removed.
     */
    private int $jobsHighWater = 0;
    private int $opLogsHighWater = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $this->fileTag = 'p7s2-' . bin2hex(random_bytes(5));
        $this->jobsHighWater = (int) App::db()->fetchValue(
            'SELECT COALESCE(MAX(id), 0) FROM ' . App::db()->table('cpms_jobs')
        );
        $this->opLogsHighWater = (int) App::db()->fetchValue(
            'SELECT COALESCE(MAX(id), 0) FROM ' . App::db()->table('cpms_operational_logs')
        );

        // ----- Committed dynamic fixture (never the seeded legacy Clinic 1) -----
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
                 VALUES (%s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'P7S2 Org ' . $unique,
                'p7s2-org-' . $unique,
                $now,
                $now
            )
        );
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'precondition: organization inserted (' . $wpdb->last_error . ')');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->orgId,
                'P7S2 Clinic ' . $unique,
                'p7s2-clinic-' . $unique,
                self::TZ,
                $now,
                $now
            )
        );
        $this->clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicId, 'precondition: clinic id is dynamic (never clinic 1)');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                'P7S2 Loc ' . $unique,
                'p7s2-loc-' . $unique,
                self::TZ,
                $now,
                $now
            )
        );
        $this->locationId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->locationId, 'precondition: location inserted (' . $wpdb->last_error . ')');

        $this->secretaryUserId = $this->makeUser('p7s2_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        $this->doctorUserId = $this->makeUser('p7s2_doctor', RolesAndCapabilities::ROLE_DOCTOR);
        $this->patientUserId = $this->makeUser('p7s2_patient', 'subscriber');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                'Dr P7S2 Active Visit',
                $this->doctorUserId,
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicianId, 'precondition: clinician row (' . $wpdb->last_error . ')');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                'MR-P7S2-' . $unique,
                'Active',
                'Visit Patient',
                '0912' . random_int(1000000, 9999999),
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->patientId, 'precondition: patient row (' . $wpdb->last_error . ')');

        // Patient ownership (T5 path: userHasPatient) — material relation, not a request id.
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (%d, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $this->patientId,
                $this->patientUserId,
                '09120000000',
                $now
            )
        );

        // Staff relations through the production primitive (durable membership).
        self::assertGreaterThan(
            0,
            cpms_test_seed_membership($this->secretaryUserId, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY),
            'precondition: secretary membership'
        );
        self::assertGreaterThan(
            0,
            cpms_test_seed_membership($this->doctorUserId, $this->clinicId, RolesAndCapabilities::ROLE_DOCTOR),
            'precondition: doctor membership'
        );

        // Per-Clinic settings consumed by both services (children read them from the DB).
        $settings = App::settingsFactory()->forClinic($this->clinicId);
        $settings->set('queue.auto_enqueue', true);
        $settings->set('queue.max_recalls', 2);
        $settings->set('booking.cancel_deadline_hours', 24);
        $settings->set('booking.reschedule_deadline_hours', 24);
        $settings->set('booking.min_lead_hours', 2);

        // The trusted Clinic is the server-side EXPLICIT scope (what the REST
        // boundary establishes) — never a payload value.
        ScopeContext::set(ClinicScope::forClinic($this->clinicId));

        // Independent connections (children) must SEE the fixture.
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    protected function tearDown(): void
    {
        // Everything above is COMMITTED (tearDown ROLLBACK is a no-op for it):
        // manual, FK-safe cleanup.
        $this->purgeFixture();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ==================================================================
    // 1 — T5 (patient cancel) is forbidden while the Visit is active.
    // ==================================================================

    public function testPatientCancelIsRejectedWithHasActiveVisitWhileVisitIsActive(): void
    {
        $appt = $this->seedConfirmedAppointment(3, '10:00:00', 'i1');
        $visit = $this->checkIn($appt['id']);
        $this->assertLiveVisitBinding($appt['id'], (int) $visit['id']);

        $error = $this->captureBookingError(
            function () use ($appt): void {
                $this->bookingService()->cancelByPatient($this->patientUserId, $appt['id'], 'انصراف بیمار');
            },
            'I-3 / T5'
        );

        self::assertSame('HAS_ACTIVE_VISIT', $error->errorCode, 'I-3: T5 must be forbidden while a Visit is active');
        self::assertGreaterThanOrEqual(400, $error->httpStatus, 'I-3: stable 4xx product envelope');
        self::assertLessThan(500, $error->httpStatus, 'I-3: stable 4xx product envelope');

        // No partial mutation: appointment, slot counter and Visit stay untouched.
        $apptRow = $this->appointmentRow($appt['id']);
        self::assertSame('confirmed', (string) $apptRow['status'], 'I-3 rejection must not mutate the appointment');
        self::assertSame(1, (int) $this->slotRow($appt['slot_id'])['booked_count'], 'I-3 rejection must not release the slot');
        $this->assertLiveVisitBinding($appt['id'], (int) $visit['id']);
    }

    // ==================================================================
    // 2 — T6 (staff cancel) is forbidden while the Visit is active.
    // ==================================================================

    public function testStaffCancelIsRejectedWithHasActiveVisitWhileVisitIsActive(): void
    {
        $appt = $this->seedConfirmedAppointment(3, '10:00:00', 'i2');
        $visit = $this->checkIn($appt['id']);
        $this->assertLiveVisitBinding($appt['id'], (int) $visit['id']);

        $error = $this->captureBookingError(
            function () use ($appt): void {
                $this->bookingService()->cancelByStaff($this->secretaryUserId, $appt['id'], 'لغو توسط منشی');
            },
            'I-3 / T6'
        );

        self::assertSame('HAS_ACTIVE_VISIT', $error->errorCode, 'I-3: T6 must be forbidden while a Visit is active');
        self::assertGreaterThanOrEqual(400, $error->httpStatus, 'I-3: stable 4xx product envelope');
        self::assertLessThan(500, $error->httpStatus, 'I-3: stable 4xx product envelope');

        self::assertSame('confirmed', (string) $this->appointmentRow($appt['id'])['status'], 'I-3 rejection must not mutate the appointment');
        self::assertSame(1, (int) $this->slotRow($appt['slot_id'])['booked_count'], 'I-3 rejection must not release the slot');
        $this->assertLiveVisitBinding($appt['id'], (int) $visit['id']);
    }

    // ==================================================================
    // 3 — T7 (reschedule) is forbidden and creates NO replacement.
    // ==================================================================

    public function testRescheduleIsRejectedWithHasActiveVisitAndCreatesNoReplacement(): void
    {
        $appt = $this->seedConfirmedAppointment(3, '10:00:00', 'i3');
        $dest = $this->seedOpenSlot(5, '11:00:00', 'i3');
        $visit = $this->checkIn($appt['id']);
        $this->assertLiveVisitBinding($appt['id'], (int) $visit['id']);

        $error = $this->captureBookingError(
            function () use ($appt, $dest): void {
                $this->bookingService()->reschedule(
                    $this->patientUserId,
                    $appt['id'],
                    $this->clinicianId,
                    $dest['date'],
                    $dest['time'],
                    'p7s2-i3-' . bin2hex(random_bytes(4)),
                    $dest['id']
                );
            },
            'I-3 / T7'
        );

        self::assertSame('HAS_ACTIVE_VISIT', $error->errorCode, 'I-3: T7 must be forbidden while a Visit is active');
        self::assertGreaterThanOrEqual(400, $error->httpStatus, 'I-3: stable 4xx product envelope');
        self::assertLessThan(500, $error->httpStatus, 'I-3: stable 4xx product envelope');

        $apptRow = $this->appointmentRow($appt['id']);
        self::assertSame('confirmed', (string) $apptRow['status'], 'T7 rejection must leave the appointment confirmed');
        self::assertNull($apptRow['rescheduled_to'], 'T7 rejection must not link a replacement');
        self::assertSame(0, $this->replacementCount($appt['id']), 'T7 rejection must not create a replacement appointment');
        self::assertSame(1, (int) $this->slotRow($appt['slot_id'])['booked_count'], 'T7 rejection must not release the old slot');
        self::assertSame(0, (int) $this->slotRow($dest['id'])['booked_count'], 'T7 rejection must not book the destination slot');
        $this->assertLiveVisitBinding($appt['id'], (int) $visit['id']);
    }

    // ==================================================================
    // 4 — A STALE pointer (Visit no longer active) must not block T6.
    // ==================================================================

    public function testStaleActiveVisitPointerDoesNotBlockStaffCancel(): void
    {
        $appt = $this->seedConfirmedAppointment(3, '10:00:00', 'i4');
        $visit = $this->checkIn($appt['id']);
        $this->endVisitThroughLifecycle((int) $visit['id'], 'بیمار قبل از پایان منصرف شد');

        // The documented lifecycle leaves the pointer behind: that alone is
        // not evidence of an active Visit.
        self::assertSame(
            (int) $visit['id'],
            (int) $this->appointmentRow($appt['id'])['active_visit_id'],
            'precondition: stale non-null active_visit_id (visit-cancel leaves the pointer)'
        );
        self::assertSame(0, count($this->liveVisitsForAppointment($appt['id'])), 'precondition: no live Visit remains');

        $result = $this->bookingService()->cancelByStaff($this->secretaryUserId, $appt['id'], 'لغو پس از پایان ویزیت');

        self::assertSame('cancelled_by_staff', (string) $result['status'], 'T6 must stay reachable once the Visit is no longer active');
        $apptRow = $this->appointmentRow($appt['id']);
        self::assertSame('cancelled_by_staff', (string) $apptRow['status'], 'stale pointer must not block the cancel');
        self::assertSame(0, (int) $this->slotRow($appt['slot_id'])['booked_count'], 'cancel releases the slot exactly once');
    }

    // ==================================================================
    // 5 — After the lifecycle legitimately ends the Visit, T7 is reachable.
    // ==================================================================

    public function testRescheduleIsReachableAfterVisitLifecycleEndsTheActiveVisit(): void
    {
        $appt = $this->seedConfirmedAppointment(3, '10:00:00', 'i5');
        $dest = $this->seedOpenSlot(5, '12:00:00', 'i5');
        $visit = $this->checkIn($appt['id']);
        $this->endVisitThroughLifecycle((int) $visit['id'], 'ویزیت بدون مراجعه پایان یافت');

        $result = $this->bookingService()->reschedule(
            $this->patientUserId,
            $appt['id'],
            $this->clinicianId,
            $dest['date'],
            $dest['time'],
            'p7s2-i5-' . bin2hex(random_bytes(4)),
            $dest['id']
        );

        self::assertSame('confirmed', (string) $result['status'], 'T7 must stay reachable once the Visit is no longer active');
        self::assertSame($appt['id'], (int) $result['previous_appointment_id'], 'replacement must reference the original appointment');

        $old = $this->appointmentRow($appt['id']);
        self::assertSame('rescheduled', (string) $old['status'], 'original appointment becomes rescheduled');
        self::assertSame((int) $result['appointment_id'], (int) $old['rescheduled_to'], 'two-way reference must be written');
        self::assertSame(1, $this->replacementCount($appt['id']), 'exactly one replacement appointment');
        self::assertSame(0, count($this->liveVisitsForAppointment($appt['id'])), 'no live Visit may be attached to the rescheduled appointment');
        self::assertSame(0, (int) $this->slotRow($appt['slot_id'])['booked_count'], 'old slot released');
        self::assertSame(1, (int) $this->slotRow($dest['id'])['booked_count'], 'destination slot booked');
    }

    // ==================================================================
    // 6 — A REJECTED reschedule must not consume / stick the idempotency key.
    // ==================================================================

    public function testRejectedRescheduleDoesNotConsumeIdempotencyKey(): void
    {
        $appt = $this->seedConfirmedAppointment(3, '10:00:00', 'i6');
        $dest = $this->seedOpenSlot(5, '13:00:00', 'i6');
        $visit = $this->checkIn($appt['id']);
        $key = 'p7s2-idem-' . bin2hex(random_bytes(8));

        $error = $this->captureBookingError(
            function () use ($appt, $dest, $key): void {
                $this->bookingService()->reschedule(
                    $this->patientUserId,
                    $appt['id'],
                    $this->clinicianId,
                    $dest['date'],
                    $dest['time'],
                    $key,
                    $dest['id']
                );
            },
            'I-3 / T7 + idempotency'
        );
        self::assertSame('HAS_ACTIVE_VISIT', $error->errorCode, 'I-3: T7 must be forbidden while a Visit is active');

        // The Visit ends through the documented lifecycle; the SAME key must
        // still be retryable (a rejected operation must not consume the key).
        $this->endVisitThroughLifecycle((int) $visit['id'], 'پایان ویزیت برای تلاش مجدد');

        $result = $this->bookingService()->reschedule(
            $this->patientUserId,
            $appt['id'],
            $this->clinicianId,
            $dest['date'],
            $dest['time'],
            $key,
            $dest['id']
        );

        self::assertSame('confirmed', (string) $result['status'], 'the retried reschedule must succeed, not replay a stuck key');
        self::assertSame($appt['id'], (int) $result['previous_appointment_id'], 'retry must create the real replacement');
        self::assertGreaterThan(0, (int) $result['appointment_id'], 'retry must return the new appointment id');
        self::assertSame('rescheduled', (string) $this->appointmentRow($appt['id'])['status'], 'original appointment becomes rescheduled');
        self::assertSame(1, $this->replacementCount($appt['id']), 'exactly one replacement appointment');
        self::assertSame(1, (int) $this->slotRow($dest['id'])['booked_count'], 'destination slot booked exactly once');
    }

    // ==================================================================
    // 7 — Race: check-in vs patient cancel (independent processes + connections).
    // ==================================================================

    public function testConcurrentCheckInAndPatientCancelNeverLeaveLiveVisitOnCancelledAppointment(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available (CI Linux has it)');
        }

        $observations = [];
        for ($attempt = 1; $attempt <= self::RACE_ATTEMPTS; $attempt++) {
            // Unique slot identity per attempt (u_slot covers clinician+location+date+time).
            $appt = $this->seedConfirmedAppointment(3, sprintf('%02d:00:00', 9 + $attempt), 'raceA' . $attempt);
            $observations[] = [
                'case' => 'checkIn-vs-cancel attempt ' . $attempt,
                'outcomes' => $this->runRace(
                    [
                        ['role' => 'check_in', 'offset' => 0.0],
                        ['role' => 'cancel', 'offset' => 0.0],
                    ],
                    $appt['id']
                ),
                'state' => $this->raceState($appt['id'], $appt['slot_id']),
            ];
        }

        $this->assertRaceLegal($observations);
    }

    // ==================================================================
    // 8 — Race: check-in vs reschedule (independent processes + connections).
    // ==================================================================

    public function testConcurrentCheckInAndRescheduleNeverLeaveLiveVisitOnRescheduledAppointment(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available (CI Linux has it)');
        }

        $observations = [];
        for ($attempt = 1; $attempt <= self::RACE_ATTEMPTS; $attempt++) {
            // Unique slot identity per attempt (u_slot covers clinician+location+date+time).
            $appt = $this->seedConfirmedAppointment(3, sprintf('%02d:00:00', 7 + $attempt), 'raceB' . $attempt);
            $dest = $this->seedOpenSlot(5, sprintf('%02d:30:00', 12 + $attempt), 'raceB' . $attempt);
            $observations[] = [
                'case' => 'checkIn-vs-reschedule attempt ' . $attempt,
                'outcomes' => $this->runRace(
                    [
                        ['role' => 'check_in', 'offset' => 0.0],
                        [
                            'role' => 'reschedule',
                            'offset' => 0.0,
                            'idem_key' => 'p7s2-race-' . bin2hex(random_bytes(6)),
                            'dest_slot_id' => $dest['id'],
                            'dest_date' => $dest['date'],
                            'dest_time' => $dest['time'],
                        ],
                    ],
                    $appt['id']
                ),
                'state' => $this->raceState($appt['id'], $appt['slot_id'], $dest['id']),
            ];
        }

        $this->assertRaceLegal($observations);
    }

    /**
     * Every observed attempt must be a LEGAL serialization result:
     *
     *   - a terminal appointment (cancelled/rescheduled) never carries a LIVE
     *     Visit and never a half-written replacement;
     *   - slot counters describe exactly the state the Clinic reached;
     *   - the losing operation carries the documented product error
     *     (`HAS_ACTIVE_VISIT` when the Visit won) — never a raw DB failure.
     *
     * Either winner is accepted; the loser must be a clean product rejection.
     *
     * @param list<array{case: string, outcomes: list<array<string, mixed>>, state: array<string, mixed>}> $observations
     */
    private function assertRaceLegal(array $observations): void
    {
        foreach ($observations as $observation) {
            $case = (string) $observation['case'];
            $outcomes = $observation['outcomes'];
            $state = $observation['state'];
            $dump = ' state=' . json_encode($state) . ' outcomes=' . json_encode($outcomes);

            // 1) Harness integrity: a child that never executed invalidates the
            //    observation (fork/connection/bootstrap failure).
            $this->assertHarnessIntact($outcomes, $case, $dump);

            $status = (string) $state['status'];
            $live = count($state['live_visits']);
            $replacements = count($state['replacements']);
            $checkInWon = (string) ($outcomes[0]['result'] ?? '') === 'ok';

            // 2) THE invariant (INDEPENDENT of which contender won): a terminal
            //    appointment never carries a LIVE Visit.
            if (in_array($status, ['cancelled_by_patient', 'cancelled_by_staff', 'rescheduled'], true)) {
                self::assertSame(
                    0,
                    $live,
                    $case . ': a terminal appointment must not carry a LIVE Visit.' . $dump
                );
            }

            // 3) Both contenders must resolve through the STABLE PRODUCT
            //    envelope. A raw PHP/DB failure escaping the product call is a
            //    direct consequence of the missing serialization (HEAD leaves
            //    the losers' statements unprotected and does not surface the
            //    failed statement as a product error) — never a clean rejection.
            $this->assertProductEnvelopeOnly($outcomes, $case, $dump);

            if ($status === 'cancelled_by_patient') {
                self::assertSame('ok', (string) ($outcomes[1]['result'] ?? ''), $case . ': the cancel winner reports ok.' . $dump);
                self::assertNotSame('ok', (string) ($outcomes[0]['result'] ?? ''), $case . ': check-in must not also win.' . $dump);
                self::assertSame(0, (int) $state['old_slot_booked'], $case . ': slot counter must follow the cancelled appointment.' . $dump);
            } elseif ($status === 'rescheduled') {
                self::assertSame('ok', (string) ($outcomes[1]['result'] ?? ''), $case . ': the reschedule winner reports ok.' . $dump);
                self::assertNotSame('ok', (string) ($outcomes[0]['result'] ?? ''), $case . ': check-in must not also win.' . $dump);
                self::assertSame(1, $replacements, $case . ': exactly one replacement appointment.' . $dump);
                self::assertSame((int) $state['rescheduled_to'], (int) $state['replacements'][0]['id'], $case . ': two-way reference must be written.' . $dump);
                self::assertSame(0, (int) $state['old_slot_booked'], $case . ': old slot released exactly once.' . $dump);
                self::assertSame(1, (int) $state['dest_slot_booked'], $case . ': destination slot booked exactly once.' . $dump);
            } elseif ($status === 'confirmed') {
                self::assertSame(0, $replacements, $case . ': no replacement may exist while the original stays confirmed.' . $dump);
                if ($checkInWon) {
                    self::assertSame(1, $live, $case . ': a successful check-in leaves exactly one live Visit.' . $dump);
                    self::assertSame((int) $state['active_visit_id'], (int) $state['live_visits'][0]['id'], $case . ': pointer must reference the live Visit.' . $dump);
                    self::assertSame(1, (int) $state['old_slot_booked'], $case . ': booked slot must stay booked while the Visit is live.' . $dump);
                    if ($state['dest_slot_booked'] !== null) {
                        self::assertSame(0, (int) $state['dest_slot_booked'], $case . ': destination slot must stay free.' . $dump);
                    }
                    self::assertSame('error', (string) ($outcomes[1]['result'] ?? ''), $case . ': the losing appointment operation must fail with a product error.' . $dump);
                    self::assertSame('HAS_ACTIVE_VISIT', (string) ($outcomes[1]['code'] ?? ''), $case . ': the losing appointment operation must carry the documented I-3 code.' . $dump);
                } else {
                    self::assertSame(0, $live, $case . ': no live Visit without a successful check-in.' . $dump);
                }
            } else {
                self::fail($case . ': unexpected appointment status after the race: ' . $status . $dump);
            }
        }
    }

    // ==================================================================
    // Services (direct construction — same wiring as App::* factories)
    // ==================================================================

    private function visitService(): VisitService
    {
        $db = App::db();

        return new VisitService(
            $db,
            new VisitRepository($db),
            new AppointmentRepository($db),
            App::settingsFactory(),
            App::audit(),
            new Phase7Slice2AllowAllLicenseGate(),
            App::op(),
            null,
            new MembershipRepository($db)
        );
    }

    private function bookingService(): BookingService
    {
        $db = App::db();

        return new BookingService(
            $db,
            new SlotRepository($db),
            new AppointmentRepository($db),
            new PatientRepository($db),
            App::settingsFactory()->forClinic($this->clinicId),
            new Phase7Slice2AllowAllLicenseGate(),
            App::audit(),
            App::op(),
            App::idem(),
            App::smsService(),
            null,
            new MembershipRepository($db)
        );
    }

    /**
     * Real product entry point — check-in through VisitService.
     *
     * @return array<string, mixed> Visit view
     */
    private function checkIn(int $appointmentId): array
    {
        return $this->visitService()->checkIn($this->secretaryUserId, $this->patientId, $appointmentId);
    }

    /**
     * Runs a product call, expecting the stable Booking error envelope.
     *
     * @param callable(): void $call
     */
    private function captureBookingError(callable $call, string $context): BookingException
    {
        try {
            $call();
        } catch (BookingException $e) {
            return $e;
        } catch (Throwable $e) {
            $this->fail(
                $context . ': expected the stable Booking error envelope, got '
                . get_class($e) . ': ' . $e->getMessage()
            );
        }

        $this->fail($context . ': expected a BookingException — the operation succeeded.');
    }

    // ==================================================================
    // Fixture seeding (committed) + binding evidence
    // ==================================================================

    /**
     * @return array{date: string, time: string}
     */
    private function futureSlotSpec(int $daysAhead, string $time): array
    {
        $dt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $daysAhead . ' days')
            ->setTimezone(new \DateTimeZone(self::TZ));

        return ['date' => $dt->format('Y-m-d'), 'time' => $time];
    }

    /**
     * Confirmed appointment on a booked slot (capacity 1) — committed.
     *
     * @return array{id: int, slot_id: int, date: string, time: string}
     */
    private function seedConfirmedAppointment(int $daysAhead, string $time, string $tag): array
    {
        global $wpdb;
        $spec = $this->futureSlotSpec($daysAhead, $time);
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $this->locationId,
                $this->clinicianId,
                $spec['date'],
                $spec['time'],
                $now,
                $now
            )
        );
        $slotId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $slotId, 'precondition: slot inserted (' . $wpdb->last_error . ')');

        $endTime = (new \DateTimeImmutable($spec['date'] . ' ' . $spec['time'], new \DateTimeZone(self::TZ)))
            ->add(new \DateInterval('PT20M'))->format('H:i:s');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                     (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time,
                      duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)
                 VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, "confirmed", 0, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $this->locationId,
                'P7S2-' . $tag . '-' . bin2hex(random_bytes(4)),
                $this->patientId,
                $this->clinicianId,
                $slotId,
                $spec['date'],
                $spec['time'],
                $endTime,
                $now,
                $now,
                $now
            )
        );
        $apptId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $apptId, 'precondition: appointment inserted (' . $wpdb->last_error . ')');

        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $this->assertAppointmentBinding($apptId, $slotId);

        return ['id' => $apptId, 'slot_id' => $slotId, 'date' => $spec['date'], 'time' => $spec['time']];
    }

    /**
     * Empty destination slot (capacity 1, no booking) — committed.
     *
     * @return array{id: int, date: string, time: string}
     */
    private function seedOpenSlot(int $daysAhead, string $time, string $tag): array
    {
        global $wpdb;
        $spec = $this->futureSlotSpec($daysAhead, $time);
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $this->locationId,
                $this->clinicianId,
                $spec['date'],
                $spec['time'],
                $now,
                $now
            )
        );
        $slotId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $slotId, 'precondition: destination slot ' . $tag . ' (' . $wpdb->last_error . ')');

        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return ['id' => $slotId, 'date' => $spec['date'], 'time' => $spec['time']];
    }

    /**
     * The fixture must materially prove the Clinic/patient/slot relation.
     */
    private function assertAppointmentBinding(int $apptId, int $slotId): void
    {
        $appt = $this->appointmentRow($apptId);
        self::assertSame($this->clinicId, (int) $appt['clinic_id'], 'fixture: appointment belongs to the fixture Clinic');
        self::assertSame($this->patientId, (int) $appt['patient_id'], 'fixture: appointment belongs to the fixture patient');
        self::assertSame($slotId, (int) $appt['slot_id'], 'fixture: appointment points at the seeded slot');
        self::assertSame('confirmed', (string) $appt['status'], 'fixture: appointment starts confirmed');
    }

    private function assertLiveVisitBinding(int $apptId, int $visitId): void
    {
        $appt = $this->appointmentRow($apptId);
        $visit = $this->visitRow($visitId);

        self::assertSame($visitId, (int) $appt['active_visit_id'], 'fixture: appointment.active_visit_id -> live Visit');
        self::assertSame(1, (int) $visit['active'], 'fixture: Visit is active');
        self::assertSame($apptId, (int) $visit['appointment_id'], 'fixture: Visit references the appointment');
        self::assertContains((string) $visit['status'], self::LIVE_VISIT_STATUSES, 'fixture: Visit is in a live queue status');
    }

    // ==================================================================
    // Parent-side reads (shared test connection)
    // ==================================================================

    /**
     * @return array<string, mixed>
     */
    private function appointmentRow(int $apptId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d LIMIT 1',
            [$apptId]
        );
        self::assertNotNull($row, 'appointment #' . $apptId . ' must exist');

        return (array) $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function visitRow(int $visitId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d LIMIT 1',
            [$visitId]
        );
        self::assertNotNull($row, 'visit #' . $visitId . ' must exist');

        return (array) $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function slotRow(int $slotId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d LIMIT 1',
            [$slotId]
        );
        self::assertNotNull($row, 'slot #' . $slotId . ' must exist');

        return (array) $row;
    }

    /**
     * Live Visits bound to the appointment (the exact set I-3 must consider).
     *
     * @return list<array<string, mixed>>
     */
    private function liveVisitsForAppointment(int $apptId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::LIVE_VISIT_STATUSES), '%s'));

        return App::db()->fetchAll(
            'SELECT * FROM ' . App::db()->table('cpms_visits') . '
             WHERE appointment_id = %d AND active = 1 AND status IN (' . $placeholders . ')
             ORDER BY id',
            array_merge([$apptId], self::LIVE_VISIT_STATUSES)
        );
    }

    /**
     * Replacements created from this appointment (T7 writes rescheduled_from).
     */
    private function replacementCount(int $apptId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE rescheduled_from = %d',
            [$apptId]
        );
    }

    // ==================================================================
    // Lifecycle + cleanup
    // ==================================================================

    /**
     * Ends the Visit through the documented lifecycle (V9 cancel): the row
     * stays, `active` becomes 0, and the appointment pointer is left behind.
     */
    private function endVisitThroughLifecycle(int $visitId, string $reason): void
    {
        $this->visitService()->transition($this->secretaryUserId, $visitId, 'cancel', ['reason' => $reason]);

        $visit = $this->visitRow($visitId);
        self::assertSame(0, (int) $visit['active'], 'lifecycle: Visit is no longer active');
        self::assertSame('cancelled', (string) $visit['status'], 'lifecycle: Visit status is cancelled');
    }

    private function purgeFixture(): void
    {
        if ($this->clinicId <= 0) {
            return;
        }

        global $wpdb;
        $c = (int) $this->clinicId;

        // FK-safe order: children first, Clinic-scoped master data last.
        $wpdb->query($wpdb->prepare('DELETE h FROM ' . $wpdb->prefix . 'cpms_visit_status_history h JOIN ' . $wpdb->prefix . 'cpms_visits v ON v.id = h.visit_id WHERE v.clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_visits WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_notifications WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_sms_messages WHERE clinic_id = %d', $c));
        // GLOBAL tables (no clinic_id): remove only what this test produced.
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_jobs WHERE id > %d', $this->jobsHighWater));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_operational_logs WHERE id > %d', $this->opLogsHighWater));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_idempotency_keys WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_appointments WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_patient_user_links WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_patients WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_settings WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', $c));
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = %d', (int) $this->orgId));
        }
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        foreach ($this->userIds as $userId) {
            $wpdb->delete($wpdb->usermeta, ['user_id' => $userId], ['%d']);
            $wpdb->delete($wpdb->users, ['ID' => $userId], ['%d']);
        }
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@p7s2.test');
        self::assertGreaterThan(0, $userId, 'precondition: wp user ' . $login);
        $user = get_userdata($userId);
        self::assertNotFalse($user, 'precondition: wp user object ' . $login);
        $user->set_role($role);
        $this->userIds[] = $userId;

        return $userId;
    }

    // ==================================================================
    // Concurrency harness (pcntl_fork + independent wpdb per child +
    // absolute-time barrier — the established repo pattern)
    // ==================================================================

    /**
     * Fork one child per worker; every child opens its OWN connection, wires
     * the REAL product services, aligns on a shared absolute time and then
     * fires the product call. Outcomes are collected from temp files.
     *
     * @param list<array<string, mixed>> $workers
     *
     * @return list<array<string, mixed>> one outcome per worker
     */
    private function runRace(array $workers, int $appointmentId): array
    {
        $fireAt = microtime(true) + self::BARRIER_SEC;
        $pids = [];
        $files = [];
        $crashed = [];

        foreach ($workers as $i => $worker) {
            $files[$i] = sys_get_temp_dir() . '/cpms-p7s2-race-' . $this->fileTag . '-' . $i . '.json';
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                // Child: NEVER returns (an escaped exception would make the
                // copied PHPUnit continue and re-run the suite inside the child).
                $this->raceChild($fireAt, $files[$i], $worker, $appointmentId);
            }
            $pids[$i] = $pid;
        }

        foreach ($pids as $i => $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $crashed[] = $i;
            }
        }

        $outcomes = [];
        foreach ($workers as $i => $worker) {
            if (in_array($i, $crashed, true)) {
                $outcomes[] = ['result' => 'fatal', 'detail' => 'child crashed (exit != 0, no outcome file)', 'worker' => $worker];
                continue;
            }
            $raw = @file_get_contents($files[$i]);
            @unlink($files[$i]);
            $decoded = $raw === false ? null : json_decode((string) $raw, true);
            $outcomes[] = is_array($decoded)
                ? $decoded
                : ['result' => 'fatal', 'detail' => 'no readable outcome file', 'worker' => $worker];
        }

        return $outcomes;
    }

    /**
     * Child body: fresh connection -> explicit scope -> real services ->
     * barrier -> one production mutation -> outcome file -> exit(0).
     *
     * @param array<string, mixed> $worker
     */
    private function raceChild(float $fireAt, string $file, array $worker, int $appointmentId): void
    {
        $wdb = null;
        try {
            global $wpdb;
            $wdb = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            if (!method_exists($wdb, 'set_prefix')) {
                exit(9);
            }
            $wdb->set_prefix($wpdb->prefix);
            $wpdb = $wdb;
            // The integration bootstrap maps `/*cpms*/` transaction SQL to
            // SAVEPOINTs (parent test transaction). Children need REAL
            // transactions on their own connection.
            remove_all_filters('query');
            $wdb->has_connected = false;
            $wdb->init_charset();
            $wdb->check_connection();

            ScopeContext::set(ClinicScope::forClinic($this->clinicId));

            $cpms = new CpmsDb($wdb);
            $op = new OpLogger($cpms);
            $audit = new AuditLogger($cpms, $op);
            $factory = new SettingsFactory($cpms, $audit);
            $gate = new Phase7Slice2AllowAllLicenseGate();

            while (microtime(true) < $fireAt + (float) ($worker['offset'] ?? 0.0)) {
                usleep(250);
            }

            $outcome = ['result' => 'unknown'];
            try {
                $outcome = $this->raceWorker($worker, $cpms, $factory, $audit, $op, $gate, $appointmentId);
            } catch (VisitException $e) {
                $outcome = [
                    'result' => 'error',
                    'role' => (string) ($worker['role'] ?? ''),
                    'code' => $e->errorCode,
                    'http' => $e->httpStatus,
                    'message' => $e->getMessage(),
                ];
            } catch (BookingException $e) {
                $outcome = [
                    'result' => 'error',
                    'role' => (string) ($worker['role'] ?? ''),
                    'code' => $e->errorCode,
                    'http' => $e->httpStatus,
                    'message' => $e->getMessage(),
                    'trace' => $this->bookingErrorTrace($e),
                ];
            } catch (Throwable $e) {
                // A raw (non-product) failure inside the child is harness
                // evidence: report WHERE it happened, never just the message.
                $outcome = [
                    'result' => 'fatal',
                    'role' => (string) ($worker['role'] ?? ''),
                    'detail' => get_class($e) . ': ' . $e->getMessage(),
                    'at' => basename($e->getFile()) . ':' . $e->getLine(),
                    'trace' => $this->throwableTrace($e),
                ];
            }
            file_put_contents($file, (string) json_encode($outcome, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            @file_put_contents(
                $file,
                (string) json_encode(
                    [
                        'result' => 'fatal',
                        'role' => (string) ($worker['role'] ?? ''),
                        'detail' => 'child bootstrap: ' . get_class($e) . ': ' . $e->getMessage(),
                        'at' => basename($e->getFile()) . ':' . $e->getLine(),
                        'trace' => $this->throwableTrace($e),
                    ],
                    JSON_UNESCAPED_UNICODE
                )
            );
        }
        if ($wdb instanceof \wpdb) {
            @$wdb->close();
        }
        exit(0);
    }

    /**
     * One REAL product call per worker role, on the child's own connection.
     *
     * @param array<string, mixed> $worker
     *
     * @return array<string, mixed>
     */
    private function raceWorker(
        array $worker,
        CpmsDb $cpms,
        SettingsFactory $factory,
        AuditLogger $audit,
        OpLogger $op,
        LicenseGate $gate,
        int $appointmentId
    ): array {
        $role = (string) ($worker['role'] ?? '');

        if ($role === 'check_in') {
            $visits = new VisitService(
                $cpms,
                new VisitRepository($cpms),
                new AppointmentRepository($cpms),
                $factory,
                $audit,
                $gate,
                $op,
                null,
                new MembershipRepository($cpms)
            );
            $visit = $visits->checkIn($this->secretaryUserId, $this->patientId, $appointmentId);

            return [
                'result' => 'ok',
                'role' => 'check_in',
                'visit_id' => (int) $visit['id'],
                'visit_status' => (string) $visit['status'],
            ];
        }

        if ($role === 'cancel' || $role === 'reschedule') {
            $sms = new SmsService(
                $cpms,
                $factory,
                new SmsProviderRegistry(),
                new CredentialVault(),
                $audit,
                $op,
                new JobQueue($cpms, $op),
                fn (): int => $this->clinicId
            );
            $booking = new BookingService(
                $cpms,
                new SlotRepository($cpms),
                new AppointmentRepository($cpms),
                new PatientRepository($cpms),
                $factory->forClinic($this->clinicId),
                $gate,
                $audit,
                $op,
                new Idempotency($cpms),
                $sms,
                null,
                new MembershipRepository($cpms)
            );

            if ($role === 'cancel') {
                $result = $booking->cancelByPatient($this->patientUserId, $appointmentId, 'p7s2 race cancel');

                return ['result' => 'ok', 'role' => 'cancel', 'appointment_status' => (string) $result['status']];
            }

            $result = $booking->reschedule(
                $this->patientUserId,
                $appointmentId,
                $this->clinicianId,
                (string) $worker['dest_date'],
                (string) $worker['dest_time'],
                (string) $worker['idem_key'],
                (int) $worker['dest_slot_id']
            );

            return ['result' => 'ok', 'role' => 'reschedule', 'appointment_id' => (int) $result['appointment_id']];
        }

        return ['result' => 'fatal', 'detail' => 'unknown worker role: ' . $role];
    }

    /**
     * Compact origin trace for a Booking error (diagnoses which product guard
     * rejected the call — the message alone is shared by several guards).
     *
     * @return list<string>
     */
    private function bookingErrorTrace(BookingException $e): array
    {
        return $this->throwableTrace($e);
    }

    /**
     * Compact call chain for any Throwable (child diagnostics).
     *
     * @return list<string>
     */
    private function throwableTrace(Throwable $e): array
    {
        $out = [];
        foreach (array_slice($e->getTrace(), 0, 8) as $frame) {
            $out[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '')
                . ' @ ' . basename((string) ($frame['file'] ?? '?')) . ':' . (string) ($frame['line'] ?? '?');
        }

        return $out;
    }

    /**
     * Final committed state, read on a FRESH connection (the parent test
     * connection holds a stale REPEATABLE-READ snapshot).
     *
     * @return array<string, mixed>
     */
    private function raceState(int $apptId, int $oldSlotId, int $destSlotId = 0): array
    {
        $conn = $this->freshMysqli();
        $db = App::db();

        $appt = $this->mysqliRow(
            $conn,
            'SELECT id, status, active_visit_id, rescheduled_to FROM ' . $db->table('cpms_appointments') . ' WHERE id = ' . (int) $apptId
        );
        $liveVisits = $this->mysqliAll(
            $conn,
            'SELECT id, status, active FROM ' . $db->table('cpms_visits')
            . ' WHERE appointment_id = ' . (int) $apptId . ' AND active = 1'
        );
        $replacements = $this->mysqliAll(
            $conn,
            'SELECT id, status, rescheduled_from FROM ' . $db->table('cpms_appointments')
            . ' WHERE rescheduled_from = ' . (int) $apptId
        );
        $oldSlot = $this->mysqliRow(
            $conn,
            'SELECT id, booked_count FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = ' . (int) $oldSlotId
        );
        $destSlot = $destSlotId > 0
            ? $this->mysqliRow($conn, 'SELECT id, booked_count FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = ' . (int) $destSlotId)
            : null;
        $conn->close();

        return [
            'id' => (int) ($appt['id'] ?? 0),
            'status' => (string) ($appt['status'] ?? ''),
            'active_visit_id' => $appt['active_visit_id'] !== null ? (int) $appt['active_visit_id'] : null,
            'rescheduled_to' => $appt['rescheduled_to'] !== null ? (int) $appt['rescheduled_to'] : null,
            'live_visits' => $liveVisits,
            'replacements' => $replacements,
            'old_slot_booked' => (int) ($oldSlot['booked_count'] ?? -1),
            'dest_slot_booked' => $destSlot !== null ? (int) ($destSlot['booked_count'] ?? -1) : null,
        ];
    }

    private function freshMysqli(): \mysqli
    {
        // DB_HOST may carry a port ("127.0.0.1:3306") — parse it like wpdb does.
        $host = DB_HOST;
        $port = null;
        if (str_contains((string) DB_HOST, ':')) {
            [$host, $portPart] = explode(':', (string) DB_HOST, 2);
            $port = (int) $portPart;
        }

        $mysqli = @new \mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port);
        if ($mysqli->connect_errno !== 0) {
            $this->markTestSkipped('Cannot open independent DB connection: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    /**
     * @return array<string, mixed>
     */
    private function mysqliRow(\mysqli $conn, string $sql): array
    {
        $res = $conn->query($sql);
        if ($res === false) {
            $this->fail('fresh-connection read failed: ' . $conn->error . ' | ' . $sql);
        }
        $row = $res->fetch_assoc();
        $res->free();

        return is_array($row) ? (array) $row : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mysqliAll(\mysqli $conn, string $sql): array
    {
        $res = $conn->query($sql);
        if ($res === false) {
            $this->fail('fresh-connection read failed: ' . $conn->error . ' | ' . $sql);
        }
        $rows = [];
        while (($row = $res->fetch_assoc()) !== null) {
            $rows[] = (array) $row;
        }
        $res->free();

        return $rows;
    }

    /**
     * HARNESS INTEGRITY — a child that never ran (fork/connection failure, no
     * outcome file, non-zero exit) invalidates the observation: such a RED is
     * NOT evidence about the product and must be treated as an invalid RED.
     *
     * @param list<array<string, mixed>> $outcomes
     */
    private function assertHarnessIntact(array $outcomes, string $case, string $dump): void
    {
        $broken = [];
        foreach ($outcomes as $outcome) {
            if (($outcome['result'] ?? '') !== 'fatal') {
                continue;
            }
            $detail = (string) ($outcome['detail'] ?? '');
            $harnessLevel = str_contains($detail, 'child crashed')
                || str_contains($detail, 'no readable outcome file')
                || str_contains($detail, 'child bootstrap:')
                || str_contains($detail, 'unknown worker role');
            if ($harnessLevel) {
                $broken[] = $outcome;
            }
        }

        self::assertSame(
            [],
            $broken,
            $case . ': harness failure — a forked contender never reached the product call: '
            . json_encode($broken) . $dump
        );
    }

    /**
     * PRODUCT ENVELOPE — every contender must resolve through the stable
     * product outcome (`ok` or the documented error envelope). A raw PHP/DB
     * failure escaping the product call is a CONTRACT violation on HEAD: the
     * missing serialization leaves one contender operating on state another
     * contender already invalidated (e.g. a statement failing after a lock
     * deadlock and the code continuing on the rolled-back transaction), so the
     * operation is neither rejected with the documented code nor completed.
     *
     * @param list<array<string, mixed>> $outcomes
     */
    private function assertProductEnvelopeOnly(array $outcomes, string $case, string $dump): void
    {
        $raw = array_values(array_filter(
            $outcomes,
            static fn (array $o): bool => ($o['result'] ?? '') === 'fatal'
        ));

        self::assertSame(
            [],
            $raw,
            $case . ': the concurrent operation must resolve through the stable product envelope'
            . ' (ok or a documented error code such as HAS_ACTIVE_VISIT), but surfaced a raw failure.'
            . $dump
        );
    }
}

/**
 * Gate تزریقی تست — تصمیم ثابت، بدون I/O (قرارداد ADR-0023).
 * نام مستقل تا در اجرای کل Suite با FakeLicenseGate فایل‌های دیگر تداخل نکند.
 */
final class Phase7Slice2AllowAllLicenseGate implements LicenseGate
{
    public function assert(string $operation, array $context = []): LicenseDecision
    {
        return LicenseDecision::allow();
    }

    public function state(): string
    {
        return 'active';
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
