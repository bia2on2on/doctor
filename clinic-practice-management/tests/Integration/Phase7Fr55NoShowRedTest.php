<?php
/**
 * Phase 7 — FR-5.5: No-show completion (TEST-ONLY RED suite).
 *
 * Contract under test (docs/state-machines/appointment.md — T8, I-3;
 * docs/api/error-codes.md):
 *
 *  MANUAL (secretary, capability `cpms_appt_no_show`, trusted explicit
 *  Clinic scope, established REST mutation surface):
 *   - confirmed appointment → no_show; `no_show_at` written;
 *   - non-empty reason required (established validation semantics);
 *   - reason preserved in the `APPOINTMENT_NO_SHOW` audit evidence;
 *   - `appointments.reason` (booking reason) NOT overwritten;
 *   - no invented reason length limit (asserted by using a natural reason);
 *   - slot booked/held counters unchanged;
 *   - genuinely active Visit → `HAS_ACTIVE_VISIT` / HTTP 409 (I-3);
 *   - a STALE `active_visit_id` (pointing at an inactive/terminal Visit)
 *     alone must NOT block; successful T8 clears it;
 *   - cross-Clinic fails closed with established parity;
 *   - repeat no-show from terminal state → `CLINIC_INVALID_TRANSITION`;
 *   - missing capability → denied, zero mutation.
 *
 *  AUTOMATIC (existing sweep, configurable grace — default 30 min;
 *  Location timezone is the operational time source; bounds/cursor
 *  semantics unchanged):
 *   - before grace untouched; after grace (no active Visit) → no_show;
 *   - genuinely active Visit blocks; stale pointer must NOT block
 *     (current HEAD defect: candidate query + service guard both skip it);
 *   - automatic T8 sends no patient notification; counters unchanged.
 *
 *  CONCURRENCY (check-in vs no-show on the same appointment row):
 *   - terminal no_show must never end with a genuinely active bound Visit;
 *   - one representative real-DB race with genuinely independent
 *     processes/connections (pcntl fork; each child builds its own fresh
 *     wpdb connection and removes the test-only SAVEPOINT query-rewrite so
 *     its commits are real; final state read on a fresh mysqli — never the
 *     parent's REPEATABLE-READ snapshot).
 *   - The no-show side is the AUTOMATIC sweep — the only FR-5.5 no-show
 *     path that exists at current HEAD (the manual surface itself is part
 *     of the RED; calling a not-yet-existing method would be a child
 *     fatal = harness failure, not product evidence).
 *   - BOTH workers fire at the SAME barrier instant (zero offset) — a
 *     genuine simultaneous start competing for the same appointment row
 *     lock. A scheduling offset/lead alone is NOT accepted as concurrency
 *     evidence: each child records wall-clock `call_started_at` /
 *     `call_finished_at` around its real product call, and the test
 *     asserts a strict in-flight overlap of the two call windows
 *     (max(starts) < min(ends)) — structurally guaranteed in a true lock
 *     race, because the loser blocks INSIDE its call window on the
 *     winner's appointment row lock and proceeds only after the winner
 *     commits. The final state matching one of the two legal serial
 *     orders is the second half of the proof: the loser observed the
 *     winner's committed row state, which is impossible without having
 *     waited on the winner's row lock.
 *   - Independence evidence recorded per child: distinct child PIDs
 *     (≠ parent PID, ≠ each other), own fresh wpdb connection
 *     (check_connection), and the test-only query-filter census
 *     (present before, removed after).
 *   - Both legal serialization orders are asserted; no sequential
 *     execution is manufactured as concurrency evidence (if an attempt
 *     shows no in-flight overlap, the attempt fails as a harness defect).
 *
 * RED classification at current HEAD (cf1ace1 — no manual no-show
 * surface exists anywhere; the sweep skips stale pointers):
 *   - T1, T2, T3, T4, T5, T7, T9  → fail: 404 `rest_no_route` where the
 *     contract envelope (200 / 400 / 403 / 404-CLINIC_NOT_FOUND / 409) is
 *     expected — the manual no-show REST surface is missing.
 *   - T8 → fail: stale `active_visit_id` blocks the automatic sweep —
 *     the two-layered stale-pointer defect.
 *   - T12 → fail: check-in binds a walk-in Visit to a terminal no_show
 *     appointment (forbidden terminal state) when the sweep wins.
 *   - T6, T10, T11 → PASS on HEAD — positive controls for behavior that
 *     must be preserved (sweep skips genuinely active Visit; grace
 *     boundary + no notification + counters; Location-timezone semantics).
 *
 * GREEN (out of scope for this change — test-only RED) must add:
 *   - manual no-show: REST `POST /clinic/v1/appointments/{id}/no-show`
 *     through the established nonce → capability (`cpms_appt_no_show`) →
 *     trusted Clinic scope architecture, with service-layer validation,
 *     I-3 guard, T8, audit-with-reason, and pointer clearing;
 *   - the stale-pointer fix for the automatic sweep;
 *   - the check-in binding guard for terminal appointments.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseDecision;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase7Fr55NoShowRedTest extends WP_UnitTestCase {
    private const NS = '/clinic/v1';

    /** Operational timezone of the main fixture locations (no DST). */
    private const TZ = 'Asia/Tehran';
    /** UTC+5, no DST — positive-offset trap for the Location-tz semantics. */
    private const TZ_POS = 'Asia/Karachi';
    /** UTC-8, no DST — negative-offset trap for the Location-tz semantics. */
    private const TZ_NEG = 'Pacific/Pitcairn';

    /** Live Visit statuses — mirrors VisitMachine::ACTIVE_STATUSES (I-3). */
    private const LIVE_VISIT_STATUSES = [
        'checked_in', 'waiting', 'called', 'in_consultation',
        'consultation_completed', 'awaiting_payment', 'paid',
    ];

    /** Race harness: barrier countdown before the simultaneous fire. */
    private const BARRIER_SEC = 1.5;
    private const RACE_ATTEMPTS = 3;

    private string $fileTag;

    private int $orgA = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locationA = 0;
    private int $locationB = 0;
    private int $locationPos = 0;
    private int $locationNeg = 0;
    private int $clinicianA = 0;
    private int $clinicianB = 0;
    private int $patientA = 0;
    private int $patientB = 0;
    private int $secretaryA = 0;

    /** @var int[] */
    private array $userIds = [];

    private int $jobsHighWater = 0;
    private int $oplogsHighWater = 0;

    // ================= Lifecycle =================

    protected function set_up(): void {
        parent::set_up();
        set_time_limit(300);

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $this->fileTag = 'p7nss-' . substr(md5(uniqid('', true)), 0, 10);

        global $wpdb;
        $this->jobsHighWater = (int) $wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM ' . $wpdb->prefix . 'cpms_jobs'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->oplogsHighWater = (int) $wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM ' . $wpdb->prefix . 'cpms_operational_logs'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $now = $this->nowUtcSql();
        $uid = uniqid('p7nss', false);

        $this->orgA = $this->insertOrganization('Org FR55 ' . $uid, 'org-fr55-' . $uid, $now);
        $this->clinicA = $this->insertClinic($this->orgA, 'Clinic A FR55 ' . $uid, 'clinic-a-fr55-' . $uid, $now);
        $this->clinicB = $this->insertClinic($this->orgA, 'Clinic B FR55 ' . $uid, 'clinic-b-fr55-' . $uid, $now);
        $this->locationA = $this->insertLocation($this->clinicA, 'Main A FR55', 'main-a-fr55-' . $uid, self::TZ, 1, $now);
        $this->locationB = $this->insertLocation($this->clinicB, 'Main B FR55', 'main-b-fr55-' . $uid, self::TZ, 1, $now);
        $this->locationPos = $this->insertLocation($this->clinicA, 'Branch +5 FR55', 'branch-pos-fr55-' . $uid, self::TZ_POS, 0, $now);
        $this->locationNeg = $this->insertLocation($this->clinicA, 'Branch -8 FR55', 'branch-neg-fr55-' . $uid, self::TZ_NEG, 0, $now);

        $this->clinicianA = $this->insertClinician($this->clinicA, 'Dr. A FR55 ' . $uid, $now);
        $this->clinicianB = $this->insertClinician($this->clinicB, 'Dr. B FR55 ' . $uid, $now);
        $this->patientA = $this->insertPatient($this->clinicA, 'MR-FR55-A-' . $uid, $now);
        $this->patientB = $this->insertPatient($this->clinicB, 'MR-FR55-B-' . $uid, $now);

        $this->secretaryA = $this->makeUser('p7nss-sec-' . $uid, RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($this->secretaryA, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY);

        // Service-level assertions attribute audit rows to the fixture Clinic.
        ScopeContext::set(ClinicScope::forClinic($this->clinicA));

        // Committed fixture: visible to forked race children (their own DB
        // connections) — the test transaction would hide it from them.
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    protected function tear_down(): void {
        $this->purgeFixture();
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);
        parent::tear_down();
    }

    // ================= T1 — manual happy path (also REST reachability) =================

    public function testManualNoShowHappyPathPersistsT8ReasonAuditAndCounters(): void {
        $auth = new AuthorizationService(new MembershipRepository(App::db()));
        self::assertTrue(
            $auth->can($this->secretaryA, $this->clinicA, RolesAndCapabilities::APPT_NO_SHOW),
            'precondition: cpms_appt_no_show must be granted to the secretary by default'
        );

        $reason = 'patient-never-arrived FR55-' . substr(uniqid('', true), -8);
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(180),
            'hp',
            'existing-booking-reason FR55-' . substr(uniqid('', true), -8)
        );
        $before = $this->slotRow($seed['slot_id']);

        $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], $reason);

        self::assertSame(
            200,
            $response->get_status(),
            'manual no-show must be reachable through the established REST mutation surface '
            . '(POST ' . self::NS . '/appointments/{id}/no-show, nonce + cpms_appt_no_show + '
            . 'trusted Clinic scope). On HEAD: 404 rest_no_route = missing manual surface. body: '
            . wp_json_encode($response->get_data())
        );
        self::assertIsArray($response->get_data());

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('no_show', $appt['status'], 'confirmed → no_show (T8)');
        self::assertNotNull($appt['no_show_at'], 'no_show_at must be written on T8 success');
        self::assertNull($appt['active_visit_id'], 'a pointer-less appointment must stay pointer-less');
        self::assertSame(
            $seed['reason'],
            $appt['reason'],
            'appointments.reason (booking reason) must NOT be overwritten by the no-show reason'
        );
        $this->assertNoShowAuditWithReason($seed['id'], $reason);

        $after = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $before['booked_count'], (int) $after['booked_count'], 'slot booked counter must be unchanged by no-show');
        self::assertSame((int) $before['held_count'], (int) $after['held_count'], 'slot held counter must be unchanged by no-show');
    }

    // ================= T2 — empty / whitespace reason =================

    public function testManualNoShowEmptyAndWhitespaceReasonRejectedWithZeroMutation(): void {
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(180),
            'er'
        );
        $slotBefore = $this->slotRow($seed['slot_id']);

        $cases = ['', '   '];
        foreach ($cases as $i => $badReason) {
            $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], $badReason);

            self::assertContains(
                $response->get_status(),
                [400, 422],
                "empty/whitespace reason (case {$i}) must be rejected with the established "
                . 'validation semantics (CLINIC_VALIDATION_FAILED). On HEAD: 404 rest_no_route = '
                . 'missing manual surface. body: ' . wp_json_encode($response->get_data())
            );
            $this->assertClinicError($response, 'CLINIC_VALIDATION_FAILED', "empty/whitespace reason (case {$i})");

            $appt = $this->appointmentRow($seed['id']);
            self::assertSame('confirmed', $appt['status'], "zero mutation (case {$i})");
            self::assertNull($appt['no_show_at'], "zero mutation (case {$i})");
            self::assertSame($seed['reason'], $appt['reason'], "zero mutation (case {$i})");
            self::assertSame(0, $this->noShowAuditCount($seed['id']), "no APPOINTMENT_NO_SHOW audit (case {$i})");
        }

        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T3 — missing cpms_appt_no_show capability =================

    public function testMissingCpmsApptNoShowCapabilityRejectedWithZeroMutation(): void {
        $membership = App::membership_service()->active_membership_for($this->clinicA, $this->secretaryA);
        self::assertNotNull($membership, 'precondition: active secretary membership');
        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::APPT_NO_SHOW, 'deny');
        global $wpdb;
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // The denial is membership-scoped: the coarse WP cap stays granted.
        self::assertTrue(user_can($this->secretaryA, RolesAndCapabilities::APPT_NO_SHOW), 'precondition: coarse WP cap remains');
        $auth = new AuthorizationService(new MembershipRepository(App::db()));
        self::assertFalse($auth->can($this->secretaryA, $this->clinicA, RolesAndCapabilities::APPT_NO_SHOW), 'precondition: scoped cap denied');

        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(180),
            'mc'
        );
        $slotBefore = $this->slotRow($seed['slot_id']);

        $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], 'no-show attempt without capability');

        self::assertSame(
            403,
            $response->get_status(),
            'missing cpms_appt_no_show must be denied (403 CLINIC_PERMISSION_DENIED). '
            . 'On HEAD: 404 rest_no_route = missing manual surface. body: ' . wp_json_encode($response->get_data())
        );
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED', 'missing capability');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('confirmed', $appt['status'], 'zero mutation');
        self::assertNull($appt['no_show_at'], 'zero mutation');
        self::assertSame($seed['reason'], $appt['reason'], 'zero mutation');
        self::assertSame(0, $this->noShowAuditCount($seed['id']), 'no APPOINTMENT_NO_SHOW audit');
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T4 — cross-Clinic + missing trusted scope =================

    public function testCrossClinicAndMissingTrustedScopeFailClosedWithZeroMutation(): void {
        $seedB = $this->seedConfirmedAppointment(
            $this->clinicB,
            $this->locationB,
            $this->clinicianB,
            $this->patientB,
            $this->pastSlotSpec(180),
            'xc'
        );
        $slotBefore = $this->slotRow($seedB['slot_id']);

        // (a) Valid trusted scope (Clinic A) against a Clinic B appointment —
        // established cross-Clinic parity: 404 CLINIC_NOT_FOUND.
        $r1 = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seedB['id'], 'cross-clinic attempt');
        self::assertSame(
            404,
            $r1->get_status(),
            'cross-Clinic no-show must fail closed (404 CLINIC_NOT_FOUND parity). '
            . 'On HEAD: rest_no_route = missing manual surface. body: ' . wp_json_encode($r1->get_data())
        );
        $this->assertClinicError($r1, 'CLINIC_NOT_FOUND', 'cross-clinic parity');

        // (b) Trusted scope is REQUIRED: a scope header naming Clinic B, where
        // the secretary has no membership, cannot be established.
        $r2 = $this->dispatchNoShow($this->secretaryA, $this->clinicB, $seedB['id'], 'scope attempt');
        self::assertSame(
            403,
            $r2->get_status(),
            'a trusted explicit Clinic scope is required (403 CLINIC_SCOPE_UNAVAILABLE). '
            . 'On HEAD: rest_no_route = missing manual surface. body: ' . wp_json_encode($r2->get_data())
        );
        $this->assertClinicError($r2, 'CLINIC_SCOPE_UNAVAILABLE', 'missing trusted scope');
        $data2 = $r2->get_data();
        self::assertSame('membership', $data2['data']['reason'] ?? null, 'unavailable scope reason must be membership-scoped');

        $appt = $this->appointmentRow($seedB['id']);
        self::assertSame('confirmed', $appt['status'], 'zero mutation');
        self::assertNull($appt['no_show_at'], 'zero mutation');
        self::assertSame($seedB['reason'], $appt['reason'], 'zero mutation');
        self::assertSame(0, $this->noShowAuditCount($seedB['id']), 'no APPOINTMENT_NO_SHOW audit');
        $slotAfter = $this->slotRow($seedB['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T5 — genuinely active Visit blocks the manual op =================

    public function testGenuinelyActiveVisitRejectsManualNoShowWithHasActiveVisit(): void {
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->futureSlotSpec(3, '10:00:00'),
            'av'
        );
        $visit = App::visitService()->checkIn($this->secretaryA, $this->patientA, $seed['id']);
        self::assertGreaterThan(0, (int) ($visit['id'] ?? 0), 'precondition: check-in of an upcoming appointment creates a live Visit');
        $slotBefore = $this->slotRow($seed['slot_id']);

        $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], 'no-show over an active visit');

        self::assertSame(
            409,
            $response->get_status(),
            'a genuinely active Visit must block the manual no-show with 409 HAS_ACTIVE_VISIT (I-3). '
            . 'On HEAD: 404 rest_no_route = missing manual surface. body: ' . wp_json_encode($response->get_data())
        );
        $this->assertClinicError($response, 'HAS_ACTIVE_VISIT', 'active-visit guard');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('confirmed', $appt['status'], 'zero mutation');
        self::assertNull($appt['no_show_at'], 'zero mutation');
        self::assertSame((int) $visit['id'], (int) ($appt['active_visit_id'] ?? 0), 'pointer untouched');
        $live = $this->liveVisitsForAppointment($seed['id']);
        self::assertCount(1, $live, 'the live Visit must remain live');
        self::assertSame((int) $visit['id'], (int) $live[0]['id']);
        self::assertSame(0, $this->noShowAuditCount($seed['id']), 'no APPOINTMENT_NO_SHOW audit');
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T6 — positive control: active Visit blocks the sweep =================

    public function testGenuinelyActiveVisitBlocksAutomaticSweep(): void {
        // Upcoming (today, +2h) appointment inside the sweep's date window but
        // before grace — so the ONLY thing that can protect it is the active
        // Visit (I-3), never the grace boundary.
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->soonSlotSpec(2),
            'as'
        );
        $visit = App::visitService()->checkIn($this->secretaryA, $this->patientA, $seed['id']);
        self::assertGreaterThan(0, (int) ($visit['id'] ?? 0), 'precondition: live Visit bound');
        $slotBefore = $this->slotRow($seed['slot_id']);

        $res = App::visitService()->processNoShows(null, 0);
        self::assertIsArray($res, 'sweep must return its summary');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('confirmed', $appt['status'], 'a genuinely active Visit must protect the appointment from the sweep (I-3)');
        self::assertNull($appt['no_show_at'], 'no_show_at must stay null');
        $live = $this->liveVisitsForAppointment($seed['id']);
        self::assertCount(1, $live, 'the live Visit must remain live');
        self::assertSame((int) $visit['id'], (int) $live[0]['id']);
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T7 — manual no-show with a STALE pointer =================

    public function testManualNoShowSucceedsWithStalePointerAndClearsIt(): void {
        $reason = 'patient-did-not-come FR55-' . substr(uniqid('', true), -8);
        $seed = $this->seedStalePointerPastAppointment('msp');

        $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], $reason);

        self::assertSame(
            200,
            $response->get_status(),
            'a STALE active_visit_id (pointing at an inactive/terminal Visit) must NOT block '
            . 'the manual no-show. On HEAD: 404 rest_no_route = missing manual surface. body: '
            . wp_json_encode($response->get_data())
        );

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('no_show', $appt['status'], 'confirmed → no_show (T8)');
        self::assertNotNull($appt['no_show_at'], 'no_show_at must be written');
        self::assertNull($appt['active_visit_id'], 'a legitimately successful T8 must clear the stale active_visit_id');
        self::assertSame(0, count($this->liveVisitsForAppointment($seed['id'])), 'no live Visit involved');
        $this->assertNoShowAuditWithReason($seed['id'], $reason);
        self::assertSame($seed['reason'], $appt['reason'], 'appointments.reason must be preserved');
    }

    // ================= T8 — automatic sweep with a STALE pointer (defect RED) =================

    public function testAutomaticSweepSucceedsWithStalePointerAndClearsIt(): void {
        $seed = $this->seedStalePointerPastAppointment('asp');
        $slotBefore = $this->slotRow($seed['slot_id']);

        $res = App::visitService()->processNoShows(null, 0);
        self::assertIsArray($res, 'sweep must return its summary');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame(
            'no_show',
            $appt['status'],
            'FR-5.5 stale-pointer defect: a past-grace confirmed appointment whose '
            . 'active_visit_id points at an INACTIVE/terminal Visit must be swept to no_show — '
            . 'a stale pointer alone must not block the automatic sweep. '
            . 'On HEAD the candidate query (active_visit_id IS NULL) and the per-row service '
            . 'guard both skip it, leaving status=' . $appt['status'] . ' (processed=' . (int) ($res['processed'] ?? 0) . ')'
        );
        self::assertNotNull($appt['no_show_at'], 'no_show_at must be written on automatic T8');
        self::assertNull($appt['active_visit_id'], 'a legitimately successful automatic T8 must clear the stale active_visit_id');
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T9 — repeat no-show from terminal state =================

    public function testRepeatNoShowFromTerminalStateRejectedWithInvalidTransition(): void {
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(180),
            'rp'
        );
        $slotBefore = $this->slotRow($seed['slot_id']);

        // Legitimately terminal first: the sweep marks it no_show.
        App::visitService()->processNoShows(null, 0);
        $afterSweep = $this->appointmentRow($seed['id']);
        self::assertSame('no_show', $afterSweep['status'], 'precondition: the sweep legitimately marked the appointment no_show');
        self::assertNotNull($afterSweep['no_show_at'], 'precondition: no_show_at written by the sweep');
        $auditCountBefore = $this->noShowAuditCount($seed['id']);
        self::assertGreaterThanOrEqual(1, $auditCountBefore, 'precondition: sweep audit row present');

        // Repeat manual no-show from the terminal state.
        $response = $this->dispatchNoShow($this->secretaryA, $this->clinicA, $seed['id'], 'repeat no-show attempt');

        self::assertSame(
            409,
            $response->get_status(),
            'repeat no-show from a terminal state must be rejected (409 CLINIC_INVALID_TRANSITION). '
            . 'On HEAD: 404 rest_no_route = missing manual surface. body: ' . wp_json_encode($response->get_data())
        );
        $this->assertClinicError($response, 'CLINIC_INVALID_TRANSITION', 'terminal repeat');

        $appt = $this->appointmentRow($seed['id']);
        self::assertSame('no_show', $appt['status'], 'no further mutation');
        self::assertSame($afterSweep['no_show_at'], $appt['no_show_at'], 'no_show_at must not be rewritten');
        self::assertSame($auditCountBefore, $this->noShowAuditCount($seed['id']), 'no further APPOINTMENT_NO_SHOW audit');
        $slotAfter = $this->slotRow($seed['slot_id']);
        self::assertSame((int) $slotBefore['booked_count'], (int) $slotAfter['booked_count'], 'slot booked counter unchanged');
        self::assertSame((int) $slotBefore['held_count'], (int) $slotAfter['held_count'], 'slot held counter unchanged');
    }

    // ================= T10 — automatic regression controls (positive) =================

    public function testAutomaticSweepGraceBoundaryNoNotificationAndNoCounterDrift(): void {
        // Inside the default 30-minute grace → untouched.
        $beforeGrace = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(10),
            'bg'
        );
        // Past the default grace, no active Visit → no_show.
        $afterGrace = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(180),
            'ag'
        );
        $slotBeforeB = $this->slotRow($beforeGrace['slot_id']);
        $slotAfterB = $this->slotRow($afterGrace['slot_id']);

        $res = App::visitService()->processNoShows(null, 0);
        self::assertIsArray($res, 'sweep must return its summary');

        $b = $this->appointmentRow($beforeGrace['id']);
        self::assertSame('confirmed', $b['status'], 'before-grace appointment must stay confirmed');
        self::assertNull($b['no_show_at'], 'before-grace appointment must keep no_show_at null');

        $a = $this->appointmentRow($afterGrace['id']);
        self::assertSame('no_show', $a['status'], 'past-grace appointment without an active Visit must be swept');
        self::assertNotNull($a['no_show_at'], 'no_show_at must be written on automatic T8');

        // Slot counters are untouched by the sweep.
        $slotAfter1 = $this->slotRow($beforeGrace['slot_id']);
        $slotAfter2 = $this->slotRow($afterGrace['slot_id']);
        self::assertSame((int) $slotBeforeB['booked_count'], (int) $slotAfter1['booked_count'], 'before-grace slot booked unchanged');
        self::assertSame((int) $slotBeforeB['held_count'], (int) $slotAfter1['held_count'], 'before-grace slot held unchanged');
        self::assertSame((int) $slotAfterB['booked_count'], (int) $slotAfter2['booked_count'], 'after-grace slot booked unchanged');
        self::assertSame((int) $slotAfterB['held_count'], (int) $slotAfter2['held_count'], 'after-grace slot held unchanged');

        // Automatic T8 sends no (newly invented) patient notification.
        global $wpdb;
        $notices = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_notifications WHERE clinic_id = %d', $this->clinicA) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(0, $notices, 'automatic no-show must not emit patient notifications');
        $sms = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_sms_messages WHERE clinic_id = %d', $this->clinicA) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(0, $sms, 'automatic no-show must not emit SMS');
    }

    // ================= T11 — Location timezone = operational time source (positive) =================

    public function testAutomaticSweepUsesLocationTimezoneAsOperationalTimeSource(): void {
        // Case X (UTC+5 branch): the slot is 45 minutes past in the Location
        // timezone → past the 30-minute grace → must be swept. A naive
        // UTC interpretation of the stored local wall-clock would see the
        // slot ~4h in the future and (wrongly) leave it confirmed.
        $caseX = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationPos,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(45, self::TZ_POS),
            'tzx'
        );
        // Case Y (UTC-8 branch): the slot is 10 minutes past in the Location
        // timezone → inside the grace → must stay confirmed. A naive UTC
        // interpretation of the stored local wall-clock would see the slot
        // ~8h in the past and (wrongly) mark it no_show.
        $caseY = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationNeg,
            $this->clinicianA,
            $this->patientA,
            $this->pastSlotSpec(10, self::TZ_NEG),
            'tzy'
        );

        App::visitService()->processNoShows(null, 0);

        $x = $this->appointmentRow($caseX['id']);
        self::assertSame('no_show', $x['status'], 'UTC+5 case: 45 minutes past in the Location timezone is past the 30-minute grace');
        self::assertNotNull($x['no_show_at']);

        $y = $this->appointmentRow($caseY['id']);
        self::assertSame('confirmed', $y['status'], 'UTC-8 case: 10 minutes past in the Location timezone is inside the 30-minute grace');
        self::assertNull($y['no_show_at']);
    }

    // ================= T12 — concurrency: check-in vs no-show (real-DB race) =================

    public function testConcurrentCheckInAndNoShowNeverLeaveLiveVisitOnNoShowAppointment(): void {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available in this CI environment.');
        }

        $observations = [];
        for ($attempt = 1; $attempt <= self::RACE_ATTEMPTS; $attempt++) {
            // Past-grace confirmed appointment: a genuine sweep candidate.
            $seed = $this->seedConfirmedAppointment(
                $this->clinicA,
                $this->locationA,
                $this->clinicianA,
                $this->patientA,
                $this->pastSlotSpec(180 + 60 * ($attempt - 1)),
                'rc' . $attempt
            );

            // Genuinely independent workers firing at the SAME barrier
            // instant (zero offset): the no-show sweep (the FR-5.5 no-show
            // path that exists on HEAD) and the secretary check-in compete
            // for the same appointment row lock. Both serialization orders
            // are legal; in-flight overlap is asserted below.
            $workers = [
                ['role' => 'no_show', 'offset' => 0.0],
                ['role' => 'check_in', 'offset' => 0.0],
            ];
            $outcomes = $this->runRace($seed['id'], $workers, $attempt);
            $state = $this->raceState($seed['id'], $seed['slot_id']);

            $observations[] = [
                'attempt' => $attempt,
                'outcomes' => $outcomes,
                'state' => $state,
            ];
            $this->assertRaceOutcomeEnvelopesOnly($outcomes, 'attempt ' . $attempt);
            $this->assertRaceIndependenceAndOverlap($outcomes, 'attempt ' . $attempt);
            $this->assertRaceLegal($state, $outcomes, 'attempt ' . $attempt);
        }

        self::assertSame(self::RACE_ATTEMPTS, count($observations), 'race evidence must exist for every attempt');
    }

    private function assertRaceLegal(array $state, array $outcomes, string $context): void {
        $status = $state['status'];
        $live = $state['live_visits'];
        $pointer = $state['active_visit_id'];

        if ($status === 'no_show') {
            // (B) no-show won: the terminal state must remain internally
            // consistent — never a genuinely active Visit bound to the
            // terminal appointment, and the stale pointer cleared.
            self::assertSame(
                0,
                count($live),
                "{$context}: FORBIDDEN terminal state — a no_show appointment carries "
                . count($live) . ' genuinely active bound Visit(s): ' . wp_json_encode($live)
            );
            self::assertNull(
                $pointer,
                "{$context}: a legitimately successful T8 must clear active_visit_id; pointer=" . var_export($pointer, true)
            );
            self::assertNotNull($state['no_show_at'], "{$context}: no_show_at must be written by the winning T8");
            // The check-in may have won the alternate (ER-06 unbound walk-in)
            // or been rejected — both are legal against a terminal state; the
            // terminal-state assertions above are what matter.
            self::assertContains(
                $outcomes['check_in']['result'] ?? null,
                ['ok', 'rejected'],
                "{$context}: check-in outcome must be a legal product envelope"
            );
        } elseif ($status === 'confirmed') {
            // (A) check-in won: the appointment is unharmed with its live Visit.
            self::assertSame('ok', $outcomes['check_in']['result'] ?? null, "{$context}: the check-in must complete as a product call");
            self::assertCount(1, $live, "{$context}: order A must leave exactly one live bound Visit");
            self::assertSame((int) $live[0]['id'], (int) $pointer, "{$context}: the pointer must reference the live Visit");
            self::assertSame(
                (int) ($outcomes['check_in']['visit_id'] ?? 0),
                (int) $live[0]['id'],
                "{$context}: the check-in result must be the live bound Visit"
            );
        } else {
            $this->fail("{$context}: unexpected terminal status {$status} (expected confirmed or no_show)");
        }

        // Unrelated slot counters must never drift.
        self::assertSame(1, $state['slot_booked'], "{$context}: slot booked counter must stay 1");
        self::assertSame(0, $state['slot_held'], "{$context}: slot held counter must stay 0");
    }

    // ================= Concurrency harness (real-DB, independent connections) =================

    /**
     * Fork two genuinely independent worker processes, each with its own
     * fresh wpdb connection (never the parent's: forked sockets plus the
     * parent's open test transaction would corrupt the evidence). Both
     * workers wait for a shared barrier (absolute time + file signal), then
     * run their real product call at barrier + offset.
     *
     * @param array<int, array{role: string, offset: float}> $workers
     *
     * @return array<string, array<string, mixed>> role => outcome
     */
    private function runRace(int $appointmentId, array $workers, int $attempt): array {
        $pids = [];
        $fireAt = microtime(true) + self::BARRIER_SEC;

        foreach ($workers as $i => $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $last = error_get_last();
                $this->fail('harness fatal (fork failed: ' . ($last['message'] ?? 'unknown') . ')');
            }
            if ($pid === 0) {
                $this->raceWorker($worker['role'], (float) $worker['offset'], $fireAt, $appointmentId, $attempt, $i);
                exit(0); // child-only — unreachable
            }
            $pids[] = $pid;
        }

        $barrier = $this->fileTag . '-barrier-' . $attempt;
        @file_put_contents($barrier, (string) $fireAt); // children poll for this file

        $outcomes = [];
        $alive = $pids;
        while ($alive !== []) {
            foreach ($alive as $idx => $pid) {
                $status = 0;
                $r = pcntl_waitpid($pid, $status);
                if ($r === $pid) {
                    if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                        $this->fail('harness fatal: child [' . $workers[$idx]['role'] . '] exited abnormally (status=' . $status . ')');
                    }
                    unset($alive[$idx]);
                }
            }
            if ($alive !== []) {
                usleep(1000);
            }
        }

        foreach (array_values($workers) as $i => $worker) {
            $file = $this->fileTag . '-outcome-' . $attempt . '-' . $i;
            $raw = @file_get_contents($file);
            @unlink($file);
            if ($raw === false || $raw === '') {
                $this->fail('harness fatal: child [' . $worker['role'] . '] wrote no outcome (fork/bootstrap failure — not a product error)');
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || !isset($decoded['result'])) {
                $this->fail('harness fatal: child [' . $worker['role'] . '] wrote malformed outcome (not a product error)');
            }
            $outcomes[$worker['role']] = $decoded;
        }
        @unlink($barrier);

        return $outcomes;
    }

    /**
     * Child body: fresh independent DB connection + real service wiring,
     * barrier wait, product call, outcome file, exit.
     *
     * The outcome records the independence/evidence fields the parent
     * asserts on: child PID, own-wpdb connection, the test-only query-
     * filter census (before/after removal), and wall-clock call windows.
     */
    private function raceWorker(string $role, float $offset, float $fireAt, int $appointmentId, int $attempt, int $index): void {
        global $wpdb;
        $own = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $own->set_prefix($wpdb->prefix);
        // The test-only SAVEPOINT query-rewrite must NOT apply to the child:
        // its transactions must be real commits visible to other connections.
        $filtersBefore = has_filter('query');
        remove_all_filters('query');
        $filtersAfter = has_filter('query');
        if (property_exists($own, 'has_connected') && $own->has_connected) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
            @$own->close();
        }
        $own->has_connected = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
        @$own->init_charset();
        if (!$own->check_connection()) {
            @file_put_contents(
                $this->fileTag . '-outcome-' . $attempt . '-' . $index,
                json_encode(['role' => $role, 'result' => 'fatal', 'error' => 'wpdb connection failed in child'])
            );
            exit(1); // non-zero = harness fatal (not a product error)
        }

        ScopeContext::set(ClinicScope::forClinic($this->clinicA));
        $cpms = new CpmsDb($own);
        $op = new OpLogger($cpms);
        $audit = new AuditLogger($cpms, $op);
        $factory = new SettingsFactory($cpms, $audit);
        $visits = new VisitService(
            $cpms,
            new VisitRepository($cpms),
            new AppointmentRepository($cpms),
            $factory,
            $audit,
            new Phase7Fr55AllowAllLicenseGate(),
            $op,
            null,
            new MembershipRepository($cpms)
        );

        $target = $fireAt + $offset;
        while (true) {
            if (@is_file($this->fileTag . '-barrier-' . $attempt) && microtime(true) >= $target) {
                break;
            }
            usleep(250);
        }

        $startedAt = microtime(true);
        $outcome = ['role' => $role, 'appointment_id' => $appointmentId, 'result' => 'exception', 'error' => 'not reached'];
        try {
            if ($role === 'no_show') {
                $res = $visits->processNoShows(null, 0);
                $outcome = [
                    'role' => $role,
                    'appointment_id' => $appointmentId,
                    'result' => 'ok',
                    'processed' => (int) ($res['processed'] ?? 0),
                ];
            } else { // check_in
                $v = $visits->checkIn($this->secretaryA, $this->patientA, $appointmentId);
                if (is_array($v) && !isset($v['id'])) {
                    // Legal product rejection envelope (the service catches its
                    // own exceptions) — distinct from a raw exception.
                    $outcome = [
                        'role' => $role,
                        'appointment_id' => $appointmentId,
                        'result' => 'rejected',
                        'error' => 'check-in rejected: ' . (string) ($v['error_code'] ?? 'unknown') . ' — ' . (string) ($v['message'] ?? ''),
                    ];
                } else {
                    $outcome = [
                        'role' => $role,
                        'appointment_id' => $appointmentId,
                        'result' => 'ok',
                        'visit_id' => (int) ($v['id'] ?? 0),
                        'visit_status' => (string) ($v['status'] ?? ''),
                    ];
                }
            }
        } catch (Throwable $e) {
            $outcome = ['role' => $role, 'appointment_id' => $appointmentId, 'result' => 'exception', 'error' => get_class($e) . ': ' . $e->getMessage()];
        }
        $finishedAt = microtime(true);

        // Independence / overlap evidence (asserted by the parent):
        $outcome['pid'] = getmypid();
        $outcome['own_wpdb_connected'] = true;
        $outcome['query_filters_before'] = is_array($filtersBefore) ? count($filtersBefore) : 0;
        $outcome['query_filters_after'] = is_array($filtersAfter) ? count($filtersAfter) : 0;
        $outcome['call_started_at'] = $startedAt;
        $outcome['call_finished_at'] = $finishedAt;

        @file_put_contents($this->fileTag . '-outcome-' . $attempt . '-' . $index, json_encode($outcome));
        exit(0); // child-only — unreachable
    }

    /**
     * Final-state read on a fresh mysqli — the parent's wpdb holds a
     * REPEATABLE-READ snapshot from before the children committed.
     *
     * @return array<string, mixed>
     */
    private function raceState(int $appointmentId, int $slotId): array {
        $conn = $this->freshMysqli();
        $db = App::db();

        $appt = $this->mysqliRow(
            $conn,
            'SELECT status, active_visit_id, no_show_at FROM ' . $db->table('cpms_appointments') . ' WHERE id = ' . (int) $appointmentId
        );
        $live = $this->mysqliAll(
            $conn,
            'SELECT id, status, active FROM ' . $db->table('cpms_visits')
            . ' WHERE appointment_id = ' . (int) $appointmentId
            . ' AND active = 1 AND status IN (' . implode(',', array_map(static fn (string $s): string => "'" . $s . "'", self::LIVE_VISIT_STATUSES)) . ')'
        );
        $slot = $this->mysqliRow(
            $conn,
            'SELECT booked_count, held_count FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = ' . (int) $slotId
        );
        $conn->close();

        return [
            'status' => (string) ($appt['status'] ?? ''),
            'active_visit_id' => $appt['active_visit_id'] !== null ? (int) $appt['active_visit_id'] : null,
            'no_show_at' => $appt['no_show_at'] ?? null,
            'live_visits' => $live,
            'slot_booked' => (int) ($slot['booked_count'] ?? -1),
            'slot_held' => (int) ($slot['held_count'] ?? -1),
        ];
    }

    private function assertRaceOutcomeEnvelopesOnly(array $outcomes, string $context): void {
        foreach ($outcomes as $role => $o) {
            self::assertNotSame(
                'fatal',
                $o['result'] ?? null,
                "{$context} [{$role}]: child bootstrap fatal is a harness failure, not product evidence"
            );
            self::assertNotSame(
                'exception',
                $o['result'] ?? null,
                "{$context} [{$role}]: a raw exception escaping the product call is not a legal product envelope: "
                . (string) ($o['error'] ?? '')
            );
        }
        // The sweep has no rejection path — it must always complete.
        self::assertSame('ok', $outcomes['no_show']['result'] ?? null, "{$context}: the sweep must complete as a product call");
    }

    /**
     * Concurrency-evidence assertions (attempt-level):
     *  1) genuinely independent PROCESSES — distinct child PIDs, none equal
     *     to the parent PID;
     *  2) genuinely independent DB CONNECTIONS — each child connected its
     *     own fresh wpdb, and the test-only query-rewrite (SAVEPOINT
     *     filter) was present in the forked child and removed there, so the
     *     child's commits are real commits visible to other connections;
     *  3) genuine IN-FLIGHT competition — strict wall-clock overlap of the
     *     two product-call windows (the loser blocks inside its own window
     *     on the winner's appointment row lock).
     */
    private function assertRaceIndependenceAndOverlap(array $outcomes, string $context): void {
        $ci = $outcomes['check_in'];
        $ns = $outcomes['no_show'];

        // (1) independent processes.
        $parentPid = getmypid();
        self::assertIsInt($ci['pid'] ?? null, "{$context}: check-in child must record its PID");
        self::assertIsInt($ns['pid'] ?? null, "{$context}: no-show child must record its PID");
        self::assertNotSame($parentPid, $ci['pid'], "{$context}: check-in must run in a child process");
        self::assertNotSame($parentPid, $ns['pid'], "{$context}: no-show must run in a child process");
        self::assertNotSame($ci['pid'], $ns['pid'], "{$context}: the workers must be distinct processes");

        // (2) independent DB connections + real (non-savepoint) commits.
        self::assertTrue((bool) ($ci['own_wpdb_connected'] ?? false), "{$context}: check-in child must own its DB connection");
        self::assertTrue((bool) ($ns['own_wpdb_connected'] ?? false), "{$context}: no-show child must own its DB connection");
        foreach (['check_in' => $ci, 'no_show' => $ns] as $role => $o) {
            self::assertGreaterThanOrEqual(
                1,
                (int) ($o['query_filters_before'] ?? 0),
                "{$context}: [{$role}] the test-only query-rewrite must have been present in the forked child"
            );
            self::assertSame(
                0,
                (int) ($o['query_filters_after'] ?? 0),
                "{$context}: [{$role}] the test-only query-rewrite must be removed in the child (real commits, not parent savepoints)"
            );
        }

        // (3) in-flight overlap of the two product-call windows.
        $starts = [
            'check_in' => (float) ($ci['call_started_at'] ?? 0),
            'no_show' => (float) ($ns['call_started_at'] ?? 0),
        ];
        $ends = [
            'check_in' => (float) ($ci['call_finished_at'] ?? 0),
            'no_show' => (float) ($ns['call_finished_at'] ?? 0),
        ];
        foreach (['check_in', 'no_show'] as $role) {
            self::assertGreaterThan(0.0, $starts[$role], "{$context}: [{$role}] call window must have a start timestamp");
            self::assertGreaterThan(0.0, $ends[$role] - $starts[$role], "{$context}: [{$role}] call window must have positive duration");
            self::assertLessThan(60.0, $ends[$role] - $starts[$role], "{$context}: [{$role}] call window must not be a hung call");
        }

        $latestStart = max($starts['check_in'], $starts['no_show']);
        $earliestEnd = min($ends['check_in'], $ends['no_show']);
        $overlap = $earliestEnd - $latestStart;
        $windows = sprintf(
            'check_in=[%.6f, %.6f] no_show=[%.6f, %.6f]',
            $starts['check_in'],
            $ends['check_in'],
            $starts['no_show'],
            $ends['no_show']
        );
        self::assertGreaterThan(
            0.0,
            $overlap,
            "{$context}: NO in-flight overlap of the two product calls (windows: {$windows}) — "
            . 'one call finished before the other started, i.e. the attempt was effectively '
            . 'sequential and is NOT concurrency evidence. Both workers fire at the same '
            . 'barrier instant; in a genuine lock race the loser blocks inside its own '
            . 'window on the winner\'s appointment row lock, so overlap must be positive.'
        );
    }

    private function freshMysqli(): \mysqli {
        // DB_HOST may carry a port ("127.0.0.1:3306") — parse it like wpdb does.
        $host = DB_HOST;
        $port = null;
        if (str_contains((string) DB_HOST, ':')) {
            [$host, $portPart] = explode(':', (string) DB_HOST, 2);
            $port = (int) $portPart;
        }

        $mysqli = @new \mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port);
        if ($mysqli->connect_errno !== 0) {
            $this->fail('harness fatal: cannot open independent DB connection: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    private function mysqliRow(\mysqli $conn, string $sql): array {
        $res = $conn->query($sql);
        if ($res === false) {
            $this->fail('fresh-connection read failed: ' . $conn->error . ' | ' . $sql);
        }
        $row = $res->fetch_assoc();
        $res->free();

        return is_array($row) ? (array) $row : [];
    }

    private function mysqliAll(\mysqli $conn, string $sql): array {
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

    // ================= REST dispatch + envelope assertions =================

    private function dispatchNoShow(int $userId, int $clinicId, int $appointmentId, ?string $reason): WP_REST_Response {
        wp_set_current_user($userId);
        $request = new WP_REST_Request('POST', self::NS . '/appointments/' . $appointmentId . '/no-show');
        if ($reason !== null) {
            $request->set_param('reason', $reason);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);

        $response = rest_do_request($request);
        self::assertInstanceOf(WP_REST_Response::class, $response, 'REST dispatch must not hard-fail');

        return $response;
    }

    private function assertClinicError(WP_REST_Response $response, string $code, string $context = ''): void {
        self::assertIsArray($response->get_data(), $context . ' — response body must be an array');
        $data = $response->get_data();
        self::assertSame($code, $data['code'] ?? null, $context . ' — unexpected error code');
        self::assertSame($response->get_status(), $data['data']['status'] ?? null, $context . ' — envelope status must mirror the HTTP status');
    }

    // ================= Fixture seeding =================

    private function insertOrganization(string $name, string $slug, string $now): int {
        global $wpdb;
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_organizations',
            ['name' => $name, 'slug' => $slug, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['%s', '%s', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'organization insert failed: ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'organization id must be dynamic');

        return $id;
    }

    private function insertClinic(int $orgId, string $name, string $slug, string $now): int {
        global $wpdb;
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_clinics',
            ['organization_id' => $orgId, 'name' => $name, 'slug' => $slug, 'timezone' => self::TZ, 'created_at' => $now, 'updated_at' => $now],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'clinic insert failed: ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'clinic id must be dynamic (never clinic 1)');

        return $id;
    }

    private function insertLocation(int $clinicId, string $name, string $slug, string $tz, int $primary, string $now): int {
        global $wpdb;
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_locations',
            [
                'clinic_id' => $clinicId,
                'name' => $name,
                'slug' => $slug,
                'timezone' => $tz,
                'is_primary' => $primary,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'location insert failed: ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'location id must be positive');

        return $id;
    }

    private function insertClinician(int $clinicId, string $name, string $now): int {
        global $wpdb;
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_clinicians',
            ['clinic_id' => $clinicId, 'full_name' => $name, 'created_at' => $now, 'updated_at' => $now],
            ['%d', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'clinician insert failed: ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'clinician id must be positive');

        return $id;
    }

    private function insertPatient(int $clinicId, string $mrn, string $now): int {
        global $wpdb;
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_patients',
            [
                'clinic_id' => $clinicId,
                'mrn' => $mrn,
                'first_name' => 'Patient',
                'last_name' => 'FR55',
                'mobile' => '09' . substr(strrev(substr(md5($mrn), 0, 10)), 0, 10),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'patient insert failed: ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient id must be positive');

        return $id;
    }

    private function makeUser(string $login, string $role): int {
        $uid = (int) wp_create_user($login, wp_generate_password(12), $login . '@example.test');
        self::assertGreaterThan(0, $uid, 'user must be created');
        $user = new \WP_User($uid);
        $user->set_role($role);
        $this->userIds[] = $uid;

        return $uid;
    }

    /**
     * @return array{date: string, time: string}
     */
    private function futureSlotSpec(int $daysAhead, string $time, string $tz = self::TZ): array {
        $d = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($tz))
            ->add(new DateInterval('P' . (int) $daysAhead . 'D'));

        return ['date' => $d->format('Y-m-d'), 'time' => $time];
    }

    /**
     * Slot spec $hoursAhead hours from now in the Location timezone.
     *
     * @return array{date: string, time: string}
     */
    private function soonSlotSpec(int $hoursAhead): array {
        $d = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::TZ))
            ->add(new DateInterval('PT' . (int) $hoursAhead . 'H'));

        return ['date' => $d->format('Y-m-d'), 'time' => $d->format('H:i:s')];
    }

    /**
     * Slot spec $minutesAgo minutes before now in the Location timezone.
     *
     * @return array{date: string, time: string}
     */
    private function pastSlotSpec(int $minutesAgo, string $tz = self::TZ): array {
        $d = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($tz))
            ->sub(new DateInterval('PT' . (int) $minutesAgo . 'M'));

        return ['date' => $d->format('Y-m-d'), 'time' => $d->format('H:i:s')];
    }

    /**
     * @param array{date: string, time: string} $spec
     *
     * @return array{id: int, date: string, time: string}
     */
    private function insertSlot(int $clinicId, int $locationId, int $clinicianId, array $spec, int $booked): array {
        global $wpdb;
        $now = $this->nowUtcSql();
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_schedule_slots',
            [
                'clinic_id' => $clinicId,
                'location_id' => $locationId,
                'clinician_id' => $clinicianId,
                'slot_date' => $spec['date'],
                'slot_time' => $spec['time'],
                'duration_min' => 30,
                'capacity' => 1,
                'booked_count' => $booked,
                'held_count' => 0,
                'is_open' => 1,
                'generated_from' => 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'slot insert failed (unique/FK?): ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'slot id must be positive');

        return ['id' => $id, 'date' => $spec['date'], 'time' => $spec['time']];
    }

    /**
     * @return array{id: int, slot_id: int, reason: ?string}
     */
    private function seedConfirmedAppointment(
        int $clinicId,
        int $locationId,
        int $clinicianId,
        int $patientId,
        array $spec,
        string $suffix,
        ?string $reason = null
    ): array {
        if ($reason === null) {
            $reason = 'existing-booking-reason FR55-' . substr(uniqid('', true), -8);
        }
        $slot = $this->insertSlot($clinicId, $locationId, $clinicianId, $spec, 1);

        global $wpdb;
        $now = $this->nowUtcSql();
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_appointments',
            [
                'clinic_id' => $clinicId,
                'location_id' => $locationId,
                'reference_code' => 'FR55-' . strtoupper(substr(uniqid($suffix, true), -12)),
                'clinician_id' => $clinicianId,
                'patient_id' => $patientId,
                'slot_id' => $slot['id'],
                'slot_date' => $spec['date'],
                'slot_time' => $spec['time'],
                'status' => 'confirmed',
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'appointment insert failed (unique/FK?): ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $apptId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $apptId, 'appointment id must be positive');

        return ['id' => $apptId, 'slot_id' => $slot['id'], 'reason' => $reason];
    }

    /**
     * Past confirmed appointment whose `active_visit_id` points at an
     * INACTIVE/terminal Visit — the documented post-V9-cancel stale-pointer
     * state (check-in alone cannot leave a past appointment in this state:
     * a late check-in takes the ER-06 lazy-no-show path).
     *
     * @return array{id: int, slot_id: int, visit_id: int, reason: ?string}
     */
    private function seedStalePointerPastAppointment(string $suffix): array {
        $spec = $this->pastSlotSpec(180);
        $seed = $this->seedConfirmedAppointment(
            $this->clinicA,
            $this->locationA,
            $this->clinicianA,
            $this->patientA,
            $spec,
            $suffix
        );

        global $wpdb;
        $now = $this->nowUtcSql();
        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_visits',
            [
                'clinic_id' => $this->clinicA,
                'location_id' => $this->locationA,
                'clinician_id' => $this->clinicianA,
                'patient_id' => $this->patientA,
                'appointment_id' => $seed['id'],
                'source' => 'scheduled',
                'status' => 'cancelled',
                'visit_date' => $spec['date'],
                'check_in_at' => $now,
                'cancel_reason' => 'lifecycle cancel (V9)',
                'active' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'stale visit insert failed (FK?): ' . $wpdb->last_error); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $visitId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $visitId, 'stale visit id must be positive');

        $upd = $wpdb->query(
            $wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_appointments SET active_visit_id = %d WHERE id = %d', $visitId, $seed['id']) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(1, (int) $upd, 'pointer update must affect exactly one row');
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // Precondition: the pointer is stale (referenced Visit is inactive/terminal).
        $appt = $this->appointmentRow($seed['id']);
        self::assertSame($visitId, (int) $appt['active_visit_id'], 'precondition: stale pointer set');
        self::assertSame(0, count($this->liveVisitsForAppointment($seed['id'])), 'precondition: referenced Visit is NOT live');

        return ['id' => $seed['id'], 'slot_id' => $seed['slot_id'], 'visit_id' => $visitId, 'reason' => $seed['reason']];
    }

    // ================= State reads =================

    /**
     * @return array<string, mixed>|null
     */
    private function appointmentRow(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, status, reason, active_visit_id, no_show_at FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id
            )
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function slotRow(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, booked_count, held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id
            )
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liveVisitsForAppointment(int $apptId): array {
        global $wpdb;
        $in = implode(',', array_fill(0, count(self::LIVE_VISIT_STATUSES), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, status, active FROM ' . $wpdb->prefix . 'cpms_visits WHERE appointment_id = %d AND active = 1 AND status IN (' . $in . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                array_merge([$apptId], self::LIVE_VISIT_STATUSES)
            )
        );

        return $rows === null ? [] : array_map(static fn ($r): array => (array) $r, $rows);
    }

    private function noShowAuditCount(int $apptId): int {
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s AND resource_type = %s AND resource_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                ['APPOINTMENT_NO_SHOW', 'appointment', $apptId]
            )
        );

        return (int) $count;
    }

    private function assertNoShowAuditWithReason(int $apptId, string $reason): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT before_json, after_json, meta_json FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s AND resource_type = %s AND resource_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                ['APPOINTMENT_NO_SHOW', 'appointment', $apptId]
            )
        );
        self::assertNotEmpty(
            $rows,
            'the APPOINTMENT_NO_SHOW audit row must exist and carry the reason in its evidence (before/after/meta JSON)'
        );

        $haystack = '';
        foreach ($rows as $row) {
            $haystack .= ' ' . (string) $row->before_json . ' ' . (string) $row->after_json . ' ' . (string) $row->meta_json;
        }
        self::assertStringContainsString($reason, $haystack, 'the no-show reason must be preserved in the APPOINTMENT_NO_SHOW audit evidence');
    }

    // ================= Cleanup (committed fixture → manual purge) =================

    private function purgeFixture(): void {
        if ($this->orgA <= 0) {
            return;
        }

        global $wpdb;
        $p = static fn (string $table): string => $wpdb->prefix . $table;

        // FK-safe order (proven in the Slice 2 RED suite): children first,
        // Clinic-scoped master data last. Membership capability/location rows
        // cascade from the membership row — no direct delete needed.
        $this->purgeVisitHistory($p);
        $this->purgeVisits($p);
        $this->purgeByClinic($p, 'cpms_notifications');
        $this->purgeByClinic($p, 'cpms_sms_messages');
        // GLOBAL tables (no clinic_id): remove only what this test produced.
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $p('cpms_jobs') . ' WHERE id > %d', $this->jobsHighWater)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $p('cpms_operational_logs') . ' WHERE id > %d', $this->oplogsHighWater)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->purgeByClinic($p, 'cpms_idempotency_keys');
        $this->purgeByClinic($p, 'cpms_audit_logs');
        $this->purgeByClinic($p, 'cpms_appointments');
        $this->purgeByClinic($p, 'cpms_schedule_slots');
        $this->purgeByClinic($p, 'cpms_patient_user_links');
        $this->purgeByClinic($p, 'cpms_clinic_memberships');
        $this->purgeByClinic($p, 'cpms_patients');
        $this->purgeByClinic($p, 'cpms_clinicians');
        $this->purgeByClinic($p, 'cpms_locations');
        $this->purgeByClinic($p, 'cpms_settings');
        $this->purgeByClinic($p, 'cpms_clinics');
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $p('cpms_organizations') . ' WHERE id = %d', $this->orgA)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // The fixture is committed (race-children visibility) — finalize.
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // Users are deleted after the COMMIT (committed cleanup).
        foreach ($this->userIds as $userId) {
            $wpdb->delete($wpdb->usermeta, ['user_id' => $userId], ['%d']);
            $wpdb->delete($wpdb->users, ['ID' => $userId], ['%d']);
        }
    }

    /**
     * @param callable(string): string $p
     */
    private function purgeVisitHistory(callable $p): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE h FROM ' . $p('cpms_visit_status_history') . ' h INNER JOIN ' . $p('cpms_visits') . ' v ON v.id = h.visit_id WHERE v.clinic_id IN (%d, %d)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                [$this->clinicA, $this->clinicB]
            )
        );
    }

    /**
     * @param callable(string): string $p
     */
    private function purgeVisits(callable $p): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . $p('cpms_visits') . ' WHERE clinic_id IN (%d, %d)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                [$this->clinicA, $this->clinicB]
            )
        );
    }

    /**
     * @param callable(string): string $p
     */
    private function purgeByClinic(callable $p, string $table): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . $p($table) . ' WHERE clinic_id IN (%d, %d)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                [$this->clinicA, $this->clinicB]
            )
        );
    }

    private function nowUtcSql(): string {
        return gmdate('Y-m-d H:i:s');
    }
}

/**
 * Test-local license gate (fork-safe): pure, no external call, always
 * allowed. Production uses the wired gate; this one only removes license
 * noise from the race children.
 */
final class Phase7Fr55AllowAllLicenseGate implements LicenseGate {
    public function assert(string $operation, array $context = []): LicenseDecision {
        return LicenseDecision::allow();
    }

    public function state(): string {
        return 'active';
    }

    public function isReadOnly(): bool {
        return false;
    }
}
