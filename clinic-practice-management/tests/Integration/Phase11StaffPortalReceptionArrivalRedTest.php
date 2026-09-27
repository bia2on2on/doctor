<?php
/**
 * Phase 11 Slice 1 (bounded) — Staff Portal Reception Arrival Board — TEST-ONLY RED.
 *
 * Slice: an authorized secretary opens the existing independent CPMS Staff
 * Portal, enters a Reception module, works in ONE trusted Clinic + operational
 * Location, sees today's booked patients for that Location, marks a patient
 * arrived/ready for reception with ONE clear operation that runs the EXISTING
 * authorized transitions in valid order (check-in then enqueue → waiting), and
 * can then see the existing queue status change when the doctor calls/starts
 * using the EXISTING doctor path.
 *
 * Live reconstruction (verified, not assumed — 2026-09-27):
 * - repository root = /home/user/doctor; authoritative main = origin/main =
 *   3497b5d609dd557b297be376c019846951275b15 == current branch base.
 * - open PRs = 0 (no active conflicting reception PR exists).
 * - latest migration = 2026_09_26_0023_handwriting_prescription_paper.php
 *   (this RED adds/reserves no migration).
 * - Phase 10 = FORMALLY CLOSED / TECHNICALLY COMPLETE (BOUNDED);
 *   Phase 11 = NOT STARTED on the live roadmap.
 * - live Staff Portal on main: ClinicCore\Frontend\StaffPortalShell
 *   (PAGE_SLUG cpms-staff-portal) mounts ONLY the doctor module
 *   (MODULE_DOCTOR); no reception module / route / template exists.
 * - live machine: VisitMachine NEW→check_in→CHECKED_IN→enqueue→WAITING with
 *   doctor call/recall/start/skip via the existing QueueController routes.
 *
 * The RED fails ONLY because the reception capability/UI/portal boundary is
 * missing: WordPress REST dispatch succeeds and answers with the canonical
 * missing-route fingerprint on the intended reception paths, and the rendered
 * Staff Portal carries no reception module — while every fixture, migration and
 * render bootstrap below is expected to succeed.
 *
 * TEST GROUP MAP (one assertion family per approved invariant):
 *  G1  Reception module + secretary access (UI + REST) ............ INTENDED RED
 *  G2  Non-secretary / insufficient capability / membership denied  INTENDED RED
 *  G3  Location policy: 0 fail-closed / 1 auto / N>1 explicit ...... INTENDED RED
 *  G4  Foreign / inactive / unassigned Location rejected ........... INTENDED RED
 *  G5  Booked rows limited to trusted Location + Location-local day  INTENDED RED
 *  G6  Arrival = existing check-in then enqueue → waiting .......... INTENDED RED
 *  G7  Cross-Clinic / cross-Location appointment mutation rejected . INTENDED RED
 *  G8  Doctor EXISTING path call/start + secretary reads the state . INTENDED RED
 *  G9  No reception endpoint returns doctor-private clinical data .. INTENDED RED
 *
 * Excluded by design (Phase 11 slice boundary): walk-ins, patient
 * creation/editing/search, appointment creation/reschedule/cancel, schedule
 * management, doctor queue mutations from reception, visit clinical workspace,
 * notes/files/prescriptions, finance, new roles/capabilities, new queue machine,
 * migrations, Phase 11 closure.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase11StaffPortalReceptionArrivalRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';

    /** 18:00 UTC ⇒ Tehran (UTC+3:30) local date 2026-03-14; Tokyo (UTC+9) local date 2026-03-15. */
    private const FIXED_UTC = '2026-03-14 18:00:00';
    private const TEHRAN_DATE = '2026-03-14';
    private const TOKYO_DATE = '2026-03-15';
    private const TZ_TEHRAN = 'Asia/Tehran';
    private const TZ_TOKYO = 'Asia/Tokyo';

    private $filterCb = null;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        App::migrations()->migrate();

        // Deterministic clock seam (proven Phase 10 pattern).
        $fixed = new \DateTimeImmutable(self::FIXED_UTC, new \DateTimeZone('UTC'));
        VisitService::setTestNowUtc($fixed);
        $this->filterCb = static function () use ($fixed): \DateTimeImmutable {
            return $fixed;
        };
        add_filter('cpms_visit_now_utc', $this->filterCb);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        VisitService::setTestNowUtc(null);
        if ($this->filterCb !== null) {
            remove_filter('cpms_visit_now_utc', $this->filterCb);
            $this->filterCb = null;
        }
        parent::tearDown();
    }

    // ============ G1 — Reception module + secretary access ============

    /**
     * A secretary with a valid ACTIVE membership can access Reception:
     * the shared Staff Portal shell exposes the Reception module entry and the
     * reception context/board endpoints answer for the trusted Clinic.
     */
    public function testG1_SecretaryWithValidMembershipCanAccessReception(): void
    {
        $fx = $this->makeReceptionStage('g1');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');

        // UI: the Reception module is mounted inside the existing Staff Portal.
        $html = $this->renderStaffPortal($fx['secretary']);
        self::assertStringContainsString('data-cpms-staff-module="reception"', $html, 'G1: staff navigation must expose the reception module');
        self::assertStringContainsString('data-role="reception-app"', $html, 'G1: reception module surface must render');
        self::assertStringContainsString('data-cpms-staff-portal-shell="v1"', $html, 'G1: reception renders inside the existing shared Staff Portal shell');

        // REST context: trusted Clinic + eligible Location resolution contract.
        wp_set_current_user($fx['secretary']);
        $ctx = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/context', [], $this->scopeHeaders($fx['clinic']));
        self::assertSame(200, $ctx->get_status(), 'G1: context must answer for a valid secretary — got ' . $ctx->get_status() . '/' . $this->errCode($ctx));
        $ctxData = $this->payload($ctx);
        self::assertSame($fx['clinic'], (int) ($ctxData['selected_clinic_id'] ?? 0), 'G1: trusted Clinic is resolved');
        self::assertNotEmpty($ctxData['eligible_locations'] ?? [], 'G1: eligible Locations must be listed for the selector contract');

        // REST board: today's booked rows + read-only queue for the trusted Location.
        $board = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(200, $board->get_status(), 'G1: board must answer — got ' . $board->get_status() . '/' . $this->errCode($board));
        $boardData = $this->payload($board);
        self::assertSame($fx['loc_tehran'], (int) ($boardData['location_id'] ?? 0), 'G1: board is bound to the trusted Location');
        self::assertSame(self::TEHRAN_DATE, (string) ($boardData['date'] ?? ''), 'G1: board exposes the Location-local operational day');
    }

    // ============ G2 — Non-secretary / insufficient capability / membership ============

    /**
     * Doctor, accountant and under-privileged actors are denied; the WP role
     * alone never grants reception authority and ACTIVE membership alone is
     * not permission (clinic-scoped capability denial wins over role preset).
     */
    public function testG2_ReceptionDeniedForNonSecretaryAndInsufficientCapability(): void
    {
        $fx = $this->makeReceptionStage('g2');

        // A. Doctor role — the reception boundary is secretary-only.
        $this->seedMembership($fx['doctor'], $fx['clinic'], 'cpms_doctor');
        wp_set_current_user($fx['doctor']);
        $docCtx = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/context', [], $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $docCtx->get_status(), 'G2: doctor must be denied the reception boundary');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($docCtx), 'G2: doctor denial uses the canonical permission envelope');

        // B. Accountant role with ACTIVE membership — membership alone is not permission.
        $acct = $this->makeUser('qa_g2_acct', RolesAndCapabilities::ROLE_ACCOUNTANT);
        $this->seedMembership($acct, $fx['clinic'], 'cpms_accountant');
        wp_set_current_user($acct);
        $acctBoard = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(403, $acctBoard->get_status(), 'G2: accountant must be denied reception reads');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($acctBoard), 'G2: accountant denial uses the canonical permission envelope');

        // C. Secretary role + ACTIVE membership but clinic-scoped capability DENY —
        //    the reception boundary authorizes per-Clinic capability, not membership.
        $membershipId = $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        App::membership_service()->set_capability($membershipId, RolesAndCapabilities::QUEUE_CHECKIN, 'deny');
        wp_set_current_user($fx['secretary']);
        $arrivals = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => 1,
            'appointment_id' => 1,
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(403, $arrivals->get_status(), 'G2: explicit clinic-scoped capability deny must block arrivals');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($arrivals), 'G2: capability denial uses the canonical permission envelope');

        // D. No nonce — CSRF layer preserved.
        wp_set_current_user($fx['secretary']);
        $anonNonce = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/context', [], $this->scopeHeaders($fx['clinic']), false);
        self::assertSame(403, $anonNonce->get_status(), 'G2: missing nonce must be rejected');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($anonNonce), 'G2: nonce denial code preserved');

        // E. No ACTIVE membership — membership requirement stands.
        $loner = $this->makeUser('qa_g2_loner', RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($loner);
        $lonerCtx = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/context', [], $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $lonerCtx->get_status(), 'G2: secretary without ACTIVE membership must be denied');
    }

    // ============ G3 — Location policy: 0 / 1 / N>1 ============

    /**
     * 0 eligible Locations ⇒ fail closed / no reception data and no mutation;
     * 1 eligible ⇒ auto-resolution is allowed; N>1 ⇒ explicit Location REQUIRED
     * (no first-Location fallback), using the established CLINIC_SCOPE_REQUIRED
     * location_required envelope without leaking eligible ids.
     */
    public function testG3_LocationPolicyZeroOneMany(): void
    {
        // A. 0 eligible Locations (the only Location is inactive).
        $zero = $this->makeReceptionStage('g3zero');
        $this->seedMembership($zero['secretary'], $zero['clinic'], 'cpms_secretary');
        $this->deactivateLocation($zero['loc_tehran']);
        $this->deactivateLocation($zero['loc_tokyo']);
        wp_set_current_user($zero['secretary']);
        $zeroBoard = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($zero['clinic']));
        self::assertSame(200, $zeroBoard->get_status(), 'G3: zero-eligible board answers fail-closed');
        $zeroData = $this->payload($zeroBoard);
        self::assertNull($zeroData['location_id'] ?? null, 'G3: zero eligible ⇒ no Location is resolved');
        self::assertSame([], $zeroData['appointments'] ?? ['x'], 'G3: zero eligible ⇒ no booked rows');
        self::assertSame([], $zeroData['queue'] ?? ['x'], 'G3: zero eligible ⇒ no queue rows');
        $zeroArrival = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => 1,
            'appointment_id' => 1,
        ], $this->scopeHeaders($zero['clinic']));
        self::assertSame(403, $zeroArrival->get_status(), 'G3: zero eligible ⇒ arrival fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($zeroArrival), 'G3: zero-eligible mutation denial envelope');

        // B. 1 eligible Location ⇒ auto-resolution without an explicit selector.
        $one = $this->makeReceptionStage('g3one');
        $this->seedMembership($one['secretary'], $one['clinic'], 'cpms_secretary');
        $this->deactivateLocation($one['loc_tokyo']);
        wp_set_current_user($one['secretary']);
        $oneBoard = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($one['clinic']));
        self::assertSame(200, $oneBoard->get_status(), 'G3: single-eligible board answers');
        $oneData = $this->payload($oneBoard);
        self::assertSame($one['loc_tehran'], (int) ($oneData['location_id'] ?? 0), 'G3: single eligible Location auto-resolves');

        // C. N>1 without explicit Location ⇒ REQUIRED, never a first-Location fallback.
        $many = $this->makeReceptionStage('g3many');
        $this->seedMembership($many['secretary'], $many['clinic'], 'cpms_secretary');
        wp_set_current_user($many['secretary']);
        $manyBoard = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($many['clinic']));
        self::assertSame(400, $manyBoard->get_status(), 'G3: N>1 without explicit Location must be rejected');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($manyBoard), 'G3: N>1 envelope = CLINIC_SCOPE_REQUIRED');
        $manyRaw  = $manyBoard->get_data();
        $manyData = is_array($manyRaw) ? (array) ($manyRaw['data'] ?? $manyRaw) : [];
        self::assertSame('location_id', (string) ($manyData['field'] ?? ''), 'G3: the required field is the Location selector');
        self::assertSame('location_required', (string) ($manyData['reason'] ?? ''), 'G3: the required reason is location_required');
        self::assertArrayNotHasKey('eligible_location_ids', $manyData, 'G3: the denial never leaks eligible ids');
        $manyArrival = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => 1,
            'appointment_id' => 1,
        ], $this->scopeHeaders($many['clinic']));
        self::assertSame(400, $manyArrival->get_status(), 'G3: N>1 without explicit Location blocks arrivals too');

        // D. N>1 with the explicit trusted Location ⇒ selection is honored.
        $manyBoard2 = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($many['clinic'], $many['loc_tokyo']));
        self::assertSame(200, $manyBoard2->get_status(), 'G3: explicit Location selection is honored');
        self::assertSame($many['loc_tokyo'], (int) ($this->payload($manyBoard2)['location_id'] ?? 0), 'G3: the selected Location is bound');
    }

    // ============ G4 — Foreign / inactive / unassigned Location rejected ============

    /**
     * Raw Location ids are selectors only: a Location of another Clinic, an
     * inactive Location, or a Location outside the membership's assignment is
     * rejected non-enumerably with the established scope-unavailable envelope.
     */
    public function testG4_ForeignInactiveUnassignedLocationRejected(): void
    {
        $stageA = $this->makeReceptionStage('g4a');
        $this->seedMembership($stageA['secretary'], $stageA['clinic'], 'cpms_secretary');

        $stageB = $this->makeReceptionStage('g4b');
        wp_set_current_user($stageA['secretary']);

        // A. Foreign Location (other Clinic).
        $foreign = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($stageA['clinic'], $stageB['loc_tehran']));
        self::assertSame(403, $foreign->get_status(), 'G4: foreign Location must be rejected');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($foreign), 'G4: foreign Location envelope');

        // B. Inactive Location in the trusted Clinic.
        $this->deactivateLocation($stageA['loc_tokyo']);
        $inactive = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($stageA['clinic'], $stageA['loc_tokyo']));
        self::assertSame(403, $inactive->get_status(), 'G4: inactive Location must be rejected');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($inactive), 'G4: inactive Location envelope');

        // C. Active Location outside the membership's location assignment.
        $stageC = $this->makeReceptionStage('g4c');
        $membershipId = $this->seedMembership($stageC['secretary'], $stageC['clinic'], 'cpms_secretary');
        App::membership_service()->set_scope_mode($membershipId, 'location', [$stageC['loc_tehran']]);
        wp_set_current_user($stageC['secretary']);
        $unassigned = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($stageC['clinic'], $stageC['loc_tokyo']));
        self::assertSame(403, $unassigned->get_status(), 'G4: unassigned Location must be rejected');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($unassigned), 'G4: unassigned Location envelope');

        // The assigned Location itself remains usable (no collateral lockout).
        $assigned = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($stageC['clinic'], $stageC['loc_tehran']));
        self::assertSame(200, $assigned->get_status(), 'G4: the assigned Location still works');
    }

    // ============ G5 — Booked rows limited to trusted Location + Location-local day ============

    /**
     * Today's booked rows come ONLY from the trusted selected Location and ONLY
     * for the Location-local operational day derived from the Location IANA
     * timezone (not gmdate/WP/Clinic/browser timezone).
     */
    public function testG5_BookedRowsLimitedToTrustedLocationAndLocalDay(): void
    {
        $fx = $this->makeReceptionStage('g5');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');

        // Tehran-local today = 2026-03-14; Tokyo-local today = 2026-03-15.
        $tehranOk = $this->insertAppointment($fx['clinic'], $fx['loc_tehran'], 'g5-th-ok', $this->insertPatient($fx['clinic'], 'g5a'), $fx['clinician'], $this->insertSlot($fx['clinic'], $fx['loc_tehran'], $fx['clinician'], self::TEHRAN_DATE, '22:00:00'), self::TEHRAN_DATE, '22:00:00');
        $tokyoOk  = $this->insertAppointment($fx['clinic'], $fx['loc_tokyo'], 'g5-tk-ok', $this->insertPatient($fx['clinic'], 'g5b'), $fx['clinician'], $this->insertSlot($fx['clinic'], $fx['loc_tokyo'], $fx['clinician'], self::TOKYO_DATE, '04:00:00'), self::TOKYO_DATE, '04:00:00');
        // Decoys on the "other" local day per Location.
        $tehranTomorrow = $this->insertAppointment($fx['clinic'], $fx['loc_tehran'], 'g5-th-tom', $this->insertPatient($fx['clinic'], 'g5c'), $fx['clinician'], $this->insertSlot($fx['clinic'], $fx['loc_tehran'], $fx['clinician'], self::TOKYO_DATE, '10:00:00'), self::TOKYO_DATE, '10:00:00');
        $tokyoYesterday = $this->insertAppointment($fx['clinic'], $fx['loc_tokyo'], 'g5-tk-yd', $this->insertPatient($fx['clinic'], 'g5d'), $fx['clinician'], $this->insertSlot($fx['clinic'], $fx['loc_tokyo'], $fx['clinician'], self::TEHRAN_DATE, '10:00:00'), self::TEHRAN_DATE, '10:00:00');

        wp_set_current_user($fx['secretary']);

        $tehranBoard = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(200, $tehranBoard->get_status(), 'G5: Tehran board answers');
        $tehranData = $this->payload($tehranBoard);
        self::assertSame(self::TEHRAN_DATE, (string) ($tehranData['date'] ?? ''), 'G5: Tehran board date is Location-local (Asia/Tehran)');
        self::assertSame([$tehranOk], $this->appointmentIds($tehranData), 'G5: Tehran board shows exactly its own Location-local-day rows');

        $tokyoBoard = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($fx['clinic'], $fx['loc_tokyo']));
        self::assertSame(200, $tokyoBoard->get_status(), 'G5: Tokyo board answers');
        $tokyoData = $this->payload($tokyoBoard);
        self::assertSame(self::TOKYO_DATE, (string) ($tokyoData['date'] ?? ''), 'G5: Tokyo board date is Location-local (Asia/Tokyo)');
        self::assertSame([$tokyoOk], $this->appointmentIds($tokyoData), 'G5: Tokyo board shows exactly its own Location-local-day rows');

        self::assertNotContains($tehranTomorrow, $this->appointmentIds($tehranData), 'G5: next-day rows never leak into the Tehran board');
        self::assertNotContains($tokyoYesterday, $this->appointmentIds($tokyoData), 'G5: previous-day rows never leak into the Tokyo board');
    }

    // ============ G6 — Arrival = existing check-in then enqueue → waiting ============

    /**
     * The one reception action runs the EXISTING authorized transitions in valid
     * order (VisitMachine check_in then enqueue) and results in durable waiting.
     * When the enqueue stage cannot complete the response must NOT claim full
     * success: the reported outcome matches the durable visit state.
     */
    public function testG6_ArrivalUsesExistingCheckInThenEnqueue(): void
    {
        $fx = $this->makeReceptionStage('g6');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        $patient = $this->insertPatient($fx['clinic'], 'g6a');
        $slot    = $this->insertSlot($fx['clinic'], $fx['loc_tehran'], $fx['clinician'], self::TEHRAN_DATE, '22:00:00');
        $appt    = $this->insertAppointment($fx['clinic'], $fx['loc_tehran'], 'g6-arr', $patient, $fx['clinician'], $slot, self::TEHRAN_DATE, '22:00:00');

        $res = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $patient,
            'appointment_id' => $appt,
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(200, $res->get_status(), 'G6: valid arrival succeeds — got ' . $res->get_status() . '/' . $this->errCode($res));

        $data = $this->payload($res);
        $arrival = (array) ($data['arrival'] ?? []);
        self::assertSame('ok', (string) ($arrival['check_in'] ?? ''), 'G6: the check-in stage is reported');
        self::assertSame('waiting', (string) ($data['visit']['status'] ?? ''), 'G6: the resulting visit is waiting');
        self::assertSame('waiting', (string) ($arrival['outcome'] ?? ''), 'G6: the reported outcome is the durable truth');
        self::assertTrue((bool) ($arrival['complete'] ?? false), 'G6: full success is claimed only when the visit is waiting');

        // Durable state + EXISTING machine sequence in visit history.
        $visitId = (int) ($data['visit']['id'] ?? 0);
        self::assertGreaterThan(0, $visitId, 'G6: visit id is returned');
        $row = $this->findVisit($visitId);
        self::assertSame('waiting', (string) $row['status'], 'G6: durable status is waiting');
        self::assertSame($appt, (int) ($row['appointment_id'] ?? 0), 'G6: the visit keeps the appointment reference');
        self::assertNotEmpty($row['waiting_since'], 'G6: waiting_since is stamped by the existing enqueue transition');
        $apptRow = $this->findAppointment($appt);
        self::assertSame($visitId, (int) ($apptRow['active_visit_id'] ?? 0), 'G6: the appointment points at the active visit');

        $history = App::db()->fetchAll(
            'SELECT from_status, to_status FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d ORDER BY id ASC',
            [$visitId]
        );
        $seq = array_map(static fn (array $h): string => (string) ($h['from_status'] ?? 'null') . '>' . (string) $h['to_status'], is_array($history) ? $history : []);
        self::assertSame(['null>checked_in', 'checked_in>waiting'], $seq, 'G6: existing check_in then enqueue transitions ran in valid order');

        // Honesty: the reported outcome can never contradict the durable visit.
        self::assertSame((string) $row['status'], (string) ($arrival['outcome'] ?? ''), 'G6: reported outcome equals durable status in every case');
    }

    // ============ G7 — Cross-Clinic / cross-Location mutation rejected ============

    /**
     * Appointment selectors are selectors only: an appointment of another
     * Clinic or another Location fails closed at the reception boundary with
     * the canonical non-enumerating fingerprint and no durable side effect.
     */
    public function testG7_CrossClinicAndCrossLocationArrivalRejected(): void
    {
        $stageA = $this->makeReceptionStage('g7a');
        $this->seedMembership($stageA['secretary'], $stageA['clinic'], 'cpms_secretary');
        $stageB = $this->makeReceptionStage('g7b');

        // A. Cross-Clinic appointment under the trusted Clinic A scope.
        $foreignPatient = $this->insertPatient($stageB['clinic'], 'g7f');
        $foreignAppt    = $this->insertAppointment($stageB['clinic'], $stageB['loc_tehran'], 'g7-foreign', $foreignPatient, $stageB['clinician'], $this->insertSlot($stageB['clinic'], $stageB['loc_tehran'], $stageB['clinician'], self::TEHRAN_DATE, '22:00:00'), self::TEHRAN_DATE, '22:00:00');

        wp_set_current_user($stageA['secretary']);
        $crossClinic = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $foreignPatient,
            'appointment_id' => $foreignAppt,
        ], $this->scopeHeaders($stageA['clinic'], $stageA['loc_tehran']));
        self::assertSame(404, $crossClinic->get_status(), 'G7: cross-Clinic appointment is a non-enumerating 404');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($crossClinic), 'G7: cross-Clinic denial envelope');
        self::assertSame(0, $this->visitCountForAppointment($foreignAppt), 'G7: cross-Clinic denial leaves no durable visit');

        // B. Cross-Location appointment in the SAME Clinic (trusted Location L1, appointment at L2).
        $patient = $this->insertPatient($stageA['clinic'], 'g7x');
        $otherLocAppt = $this->insertAppointment($stageA['clinic'], $stageA['loc_tokyo'], 'g7-cross-loc', $patient, $stageA['clinician'], $this->insertSlot($stageA['clinic'], $stageA['loc_tokyo'], $stageA['clinician'], self::TOKYO_DATE, '04:00:00'), self::TOKYO_DATE, '04:00:00');

        $crossLoc = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $patient,
            'appointment_id' => $otherLocAppt,
        ], $this->scopeHeaders($stageA['clinic'], $stageA['loc_tehran']));
        self::assertSame(404, $crossLoc->get_status(), 'G7: cross-Location appointment is a non-enumerating 404');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($crossLoc), 'G7: cross-Location denial envelope');
        self::assertSame(0, $this->visitCountForAppointment($otherLocAppt), 'G7: cross-Location denial leaves no durable visit');

        // C. Mismatched patient selector — the existing pairing check stands.
        $otherPatient = $this->insertPatient($stageA['clinic'], 'g7p');
        $goodAppt     = $this->insertAppointment($stageA['clinic'], $stageA['loc_tehran'], 'g7-good', $patient, $stageA['clinician'], $this->insertSlot($stageA['clinic'], $stageA['loc_tehran'], $stageA['clinician'], self::TEHRAN_DATE, '22:30:00'), self::TEHRAN_DATE, '22:30:00');
        $mismatch = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $otherPatient,
            'appointment_id' => $goodAppt,
        ], $this->scopeHeaders($stageA['clinic'], $stageA['loc_tehran']));
        self::assertGreaterThanOrEqual(400, $mismatch->get_status(), 'G7: mismatched patient selector is rejected');
        self::assertSame(0, $this->visitCountForAppointment($goodAppt), 'G7: mismatched selector leaves no durable visit');
    }

    // ============ G8 — Doctor EXISTING path + secretary reads the state ============

    /**
     * After reception arrival, the doctor continues with the EXISTING doctor
     * queue actions (call/start via the existing QueueController routes and the
     * existing queue machine), and the secretary reads the resulting
     * called / in-consultation states through the reception board.
     */
    public function testG8_DoctorExistingPathCallStartVisibleToSecretary(): void
    {
        $fx = $this->makeReceptionStage('g8');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        $this->seedMembership($fx['doctor'], $fx['clinic'], 'cpms_doctor');

        // Reception arrival.
        wp_set_current_user($fx['secretary']);
        $patient = $this->insertPatient($fx['clinic'], 'g8a');
        $appt    = $this->insertAppointment($fx['clinic'], $fx['loc_tehran'], 'g8-arr', $patient, $fx['clinician'], $this->insertSlot($fx['clinic'], $fx['loc_tehran'], $fx['clinician'], self::TEHRAN_DATE, '22:00:00'), self::TEHRAN_DATE, '22:00:00');
        $arrival = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $patient,
            'appointment_id' => $appt,
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(200, $arrival->get_status(), 'G8: reception arrival succeeds — got ' . $arrival->get_status() . '/' . $this->errCode($arrival));
        $visitId = (int) ($this->payload($arrival)['visit']['id'] ?? 0);
        self::assertGreaterThan(0, $visitId, 'G8: arrival returns the visit');

        // Doctor uses the EXISTING doctor path (no new route/state).
        wp_set_current_user($fx['doctor']);
        $call = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $visitId . '/call', ['room' => '2'], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(200, $call->get_status(), 'G8: existing doctor call route works — got ' . $call->get_status() . '/' . $this->errCode($call));
        self::assertSame('called', (string) ($this->payload($call)['status'] ?? ''), 'G8: the visit is called');

        // Secretary reads the called state from the reception board.
        wp_set_current_user($fx['secretary']);
        $boardCalled = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(200, $boardCalled->get_status(), 'G8: board answers after the doctor call');
        $queue = (array) ($this->payload($boardCalled)['queue'] ?? []);
        $states = array_map(static fn (array $q): string => (string) ($q['status'] ?? ''), $queue);
        self::assertContains('called', $states, 'G8: the secretary sees the called state');

        // Doctor starts the consultation through the existing path.
        wp_set_current_user($fx['doctor']);
        $start = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $visitId . '/start', [], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(200, $start->get_status(), 'G8: existing doctor start route works — got ' . $start->get_status() . '/' . $this->errCode($start));
        self::assertSame('in_consultation', (string) ($this->payload($start)['status'] ?? ''), 'G8: the visit is in consultation');

        // Secretary reads the in-consultation state (read-only).
        wp_set_current_user($fx['secretary']);
        $boardCons = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        $states2 = array_map(static fn (array $q): string => (string) ($q['status'] ?? ''), (array) ($this->payload($boardCons)['queue'] ?? []));
        self::assertContains('in_consultation', $states2, 'G8: the secretary sees the in-consultation state');
    }

    // ============ G9 — Privacy: no doctor-private clinical data ============

    /**
     * Reception payloads carry only what the reception workflow needs: bounded
     * patient presentation + queue status. No notes, prescriptions, files,
     * clinical history, or unnecessary sensitive identifiers appear anywhere in
     * the reception endpoint payloads.
     */
    public function testG9_ReceptionPayloadsCarryNoDoctorPrivateClinicalData(): void
    {
        $fx = $this->makeReceptionStage('g9');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        $patient = $this->insertPatient($fx['clinic'], 'g9a');
        $appt    = $this->insertAppointment($fx['clinic'], $fx['loc_tehran'], 'g9-arr', $patient, $fx['clinician'], $this->insertSlot($fx['clinic'], $fx['loc_tehran'], $fx['clinician'], self::TEHRAN_DATE, '22:00:00'), self::TEHRAN_DATE, '22:00:00');

        $ctx   = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/context', [], $this->scopeHeaders($fx['clinic']));
        $board = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board', [], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        $arr   = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $patient,
            'appointment_id' => $appt,
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));

        self::assertSame(200, $ctx->get_status(), 'G9: context answers');
        self::assertSame(200, $board->get_status(), 'G9: board answers');
        self::assertSame(200, $arr->get_status(), 'G9: arrival answers — got ' . $arr->get_status() . '/' . $this->errCode($arr));

        $forbidden = [
            'note', 'notes', 'clinical_notes', 'doctor_private', 'diagnosis',
            'prescriptions', 'prescription', 'rx_items', 'files', 'file',
            'handwriting', 'stroke_data', 'national_id', 'mobile', 'phone',
            'email', 'address', 'birth_date', 'mrn', 'storage_path',
            'stored_filename', 'history', 'visit_history', 'reason',
        ];
        foreach (['context' => $ctx, 'board' => $board, 'arrival' => $arr] as $label => $res) {
            $this->assertNoForbiddenKeys($this->payload($res), $forbidden, 'G9/' . $label);
        }

        // Board rows are a bounded presentation: appointment rows only carry the
        // fields needed to identify the booked patient safely.
        $apptRows = (array) ($this->payload($board)['appointments'] ?? []);
        self::assertNotEmpty($apptRows, 'G9: the board lists the booked row');
        foreach ($apptRows as $row) {
            $keys = array_keys((array) $row);
            sort($keys);
            self::assertSame(
                ['express', 'id', 'patient_id', 'patient_name', 'status', 'time', 'visit_status'],
                $keys,
                'G9: booked rows expose only the bounded allowlist, got ' . implode(',', $keys)
            );
        }
    }

    // ============ Helpers ============

    /**
     * One trusted Clinic + two Locations (Asia/Tehran + Asia/Tokyo) + secretary,
     * doctor, clinician and deterministic stage ids.
     *
     * @return array<string, int>
     */
    private function makeReceptionStage(string $tag): array
    {
        $org = $this->insertOrg('Rcp Org ' . $tag);
        // Clinic timezone is deliberately NOT the Locations' timezone: the
        // operational day must come from the Location IANA timezone only.
        $clinic = $this->insertClinicInOrg('Rcp Clinic ' . $tag, $org, 'America/New_York');
        $locTehran = $this->insertLocation($clinic, 'Rcp Tehran ' . $tag, self::TZ_TEHRAN, 1);
        $locTokyo  = $this->insertLocation($clinic, 'Rcp Tokyo ' . $tag, self::TZ_TOKYO, 0);
        $doctor     = $this->makeUser('qa_' . $tag . '_rcpdoc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician  = $this->insertClinician('Dr Rcp ' . $tag, $clinic, 1, $doctor);
        $secretary  = $this->makeUser('qa_' . $tag . '_rcpsec', RolesAndCapabilities::ROLE_SECRETARY);
        return [
            'org'         => $org,
            'clinic'      => $clinic,
            'loc_tehran'  => $locTehran,
            'loc_tokyo'   => $locTokyo,
            'doctor'      => $doctor,
            'clinician'   => $clinician,
            'secretary'   => $secretary,
        ];
    }

    private function seedMembership(int $userId, int $clinicId, string $roleKey): int
    {
        return (int) cpms_test_seed_membership($userId, $clinicId, $roleKey);
    }

    /**
     * @return array<string, string>
     */
    private function scopeHeaders(int $clinic, ?int $location = null): array
    {
        $headers = ['X-CPMS-Clinic-Id' => (string) $clinic];
        if ($location !== null) {
            $headers['X-CPMS-Location-Id'] = (string) $location;
        }
        return $headers;
    }

    private function dispatch(string $method, string $route, array $params = [], array $headers = [], bool $withNonce = true): WP_REST_Response
    {
        $r = new WP_REST_Request($method, $route);
        foreach ($params as $k => $v) {
            $r->set_param($k, $v);
        }
        if ($withNonce) {
            $r->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        foreach ($headers as $k => $v) {
            $r->set_header($k, $v);
        }
        return rest_do_request($r);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Response $res): array
    {
        $b = $res->get_data();
        if (is_array($b) && isset($b['data']) && is_array($b['data'])) {
            return $b['data'];
        }
        return is_array($b) ? $b : [];
    }

    private function errCode(WP_REST_Response $res): string
    {
        $b = $res->get_data();
        if ($b instanceof \WP_Error) {
            return (string) $b->get_error_code();
        }
        return (string) (is_array($b) ? ($b['code'] ?? '') : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function findVisit(int $visitId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitId]);
        self::assertIsArray($row, 'visit row must exist');
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function findAppointment(int $apptId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d', [$apptId]);
        self::assertIsArray($row, 'appointment row must exist');
        return $row;
    }

    private function visitCountForAppointment(int $apptId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . ' WHERE appointment_id = %d', [$apptId]);
    }

    /**
     * @param array<string, mixed> $boardData
     * @return list<int>
     */
    private function appointmentIds(array $boardData): array
    {
        $ids = [];
        foreach ((array) ($boardData['appointments'] ?? []) as $row) {
            $ids[] = (int) (is_array($row) ? ($row['id'] ?? 0) : 0);
        }
        sort($ids);
        return $ids;
    }

    /**
     * @param mixed               $node
     * @param list<string>        $forbidden
     */
    private function assertNoForbiddenKeys($node, array $forbidden, string $label): void
    {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            self::assertNotContains((string) $key, $forbidden, $label . ': forbidden key "' . $key . '" leaked');
            $this->assertNoForbiddenKeys($value, $forbidden, $label);
        }
    }

    private function renderStaffPortal(int $userId): string
    {
        wp_set_current_user($userId);
        $url = StaffPortalShell::portal_url();
        self::assertNotEmpty($url, 'the canonical Staff Portal page exists');
        $path = (string) (wp_parse_url($url, \PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, \PHP_URL_QUERY) ?? '');
        // Module selection rides on the request URL (go_to() rebuilds $_GET from it).
        $req = $path . ($query !== '' ? '?' . $query . '&' : '?') . 'cpms-module=reception';
        if ($req === '') {
            $req = '/';
        }
        $this->go_to($req);
        $baseline = get_stylesheet_directory() . '/page.php';
        if (!is_readable($baseline)) {
            $baseline = get_stylesheet_directory() . '/index.php';
        }
        $tpl = (string) apply_filters('template_include', $baseline);
        self::assertNotSame($baseline, $tpl, 'template_include must intercept with the plugin-owned Staff Portal template');
        self::assertFileExists($tpl);
        ob_start();
        include $tpl;
        return (string) ob_get_clean();
    }

    private function makeUser(string $login, string $role): int
    {
        $u = $login . '_' . bin2hex(random_bytes(3));
        $id = (int) wp_create_user($u, 'pass-not-used-123', $u . '@test.local');
        self::assertGreaterThan(0, $id);
        $user = get_userdata($id);
        self::assertNotFalse($user);
        $user->set_role($role);
        return $id;
    }

    private function insertOrg(string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)', $name, 'org-' . bin2hex(random_bytes(3)), 'active', $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertClinicInOrg(string $name, int $orgId, string $tz): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', $orgId, $name, 'cl-' . bin2hex(random_bytes(3)), $tz, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        App::resetScope();
        return $id;
    }

    private function insertLocation(int $clinicId, string $name, string $tz, int $primary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', $clinicId, $name, 'loc-' . bin2hex(random_bytes(3)), $tz, $primary, $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function deactivateLocation(int $locationId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE id = %d', $locationId));
    }

    private function insertClinician(string $name, int $clinicId, int $active, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s)', $clinicId, $name, $wpUserId, $active, $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-RCP-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2));
        $mobile = '0912' . sprintf('%07d', random_int(1000000, 9999999));
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', $clinicId, $mrn, 'Test', 'Patient ' . substr($mrn, -4), $mobile, 'active', $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertSlot(int $clinicId, int $locId, int $clinicianId, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)', $clinicId, $locId, $clinicianId, $date, $time, 20, 1, 1, 0, 1, 'manual', $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertAppointment(int $clinicId, int $locId, string $ref, int $patientId, int $clinicianId, int $slotId, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s, %s)', $clinicId, $locId, $ref . '-' . bin2hex(random_bytes(2)), $patientId, $clinicianId, $slotId, 0, $date, $time, 20, $time, 'confirmed', 0, $now, $now, $now));
        return (int) $wpdb->insert_id;
    }
}
