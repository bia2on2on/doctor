<?php
/**
 * Phase 10 Slice 2 (bounded) — Doctor Portal queue actions — TEST-ONLY RED.
 *
 * Slice: Call / Start / Recall / Skip on the Doctor Portal, reusing the
 * EXISTING queue state machine (VisitMachine V4/V6/V5/V7/V8, doctor actor)
 * and the EXISTING REST routes (POST /clinic/v1/visits/{id}/{call,recall,start,skip}).
 *
 * Live context (verified, not assumed):
 * - Phase 10 = IN PROGRESS; Phase 10 Slice 1 = CLOSED via PR #113
 *   (independent read-only Doctor Portal shell + trusted Clinic/Location scope).
 * - Next bounded capability per live SRS (FR-18.3, directly after delivered
 *   FR-18.2) + roadmap: Doctor Portal queue actions — NOT IMPLEMENTED.
 * - Latest migration: 2026_09_20_0022_slot_holds_patient_binding.php (no 0023).
 *
 * GREEN resolution of the two accepted RED gaps (RED head 7dd488e):
 *  (R1) Selected-Location mutation isolation — the mutation guard now checks
 *       the trusted operational Location as well as the trusted Clinic, so a
 *       cross-Location call is denied with 404 CLINIC_NOT_FOUND. Group 5 is
 *       GREEN, including side-effect-free denial (C) and the no-Location
 *       legacy regression (D).
 *  (R2) Doctor Portal queue-row action controls + request wiring are wired in
 *       the portal shell. Group 8 is GREEN without weakening any assertion.
 *
 * All other groups are guards over EXISTING behavior and are expected to PASS:
 *  1. Valid existing queue transitions via real REST (call/start/recall/skip).
 *  2. Invalid transitions + recall limit + skip reason (+ double-transition guard).
 *  3. Own-doctor mutation authority (+ client authority rejection).
 *  4. Clinic isolation (canonical non-enumerating 404 parity on mutations).
 *  6. Nonce / capability / role authorization.
 *  7. Secretary observability after doctor CALL (history + /rt/queue +
 *     QUEUE_CALLED + room contract + bounded fields).
 *
 * Product code: smallest GREEN — Location check in the existing mutation guard
 * plus state-appropriate queue-row controls reusing the existing routes and
 * machine. No migration. No new route. No new state.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase10DoctorPortalQueueActionsRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';
    private const FIXED_UTC = '2026-03-14 10:00:00';
    private const FIXED_UTC_DATE = '2026-03-14';
    private const TZ_TEHRAN = 'Asia/Tehran';

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

        // Deterministic clock seam for the operational day (proven Slice 1 pattern).
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
        $_GET = []; $_POST = []; $_REQUEST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
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

    // ============ Group 1 — valid existing transitions via real REST ============

    public function testGroup1_ValidQueueTransitionsViaRealRest(): void
    {
        $fx = $this->makePortalStage('g1');
        $headers = $this->scopeHeaders($fx['clinic']);
        wp_set_current_user($fx['doctor']);

        // A. waiting -> call -> called (no room).
        $vCall = $this->insertVisit($this->insertPatient($fx['clinic'], 'g1a'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        $rCall = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vCall . '/call', [], $headers);
        self::assertSame(200, $rCall->get_status(), 'G1: call without room works, got ' . $rCall->get_status() . '/' . $this->errCode($rCall));
        self::assertSame('called', $this->payload($rCall)['status']);
        $rowCall = $this->findVisit($vCall);
        self::assertSame('called', (string) $rowCall['status'], 'G1: durable status is called');
        self::assertNotEmpty($rowCall['called_at'], 'G1: called_at stamped');

        // Room contract: optional bounded room flows into existing history/notification contract.
        $vRoom = $this->insertVisit($this->insertPatient($fx['clinic'], 'g1r'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        $rRoom = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vRoom . '/call', ['room' => '3'], $headers);
        self::assertSame(200, $rRoom->get_status(), 'G1: call with room works');
        $histRoom = App::visitService()->history($fx['doctor'], $vRoom);
        self::assertCount(1, $histRoom, 'G1: exactly one history row for the call');
        self::assertSame('called', $histRoom[0]['to_status']);
        self::assertStringContainsString('3', (string) $histRoom[0]['note'], 'G1: room flows into history note');
        $rowRoom = $this->findVisit($vRoom);
        self::assertSame($fx['clinic'], (int) $rowRoom['clinic_id'], 'G1: room creates no Clinic authority');
        self::assertSame($fx['location'], (int) $rowRoom['location_id'], 'G1: room creates no Location authority');

        // B. called -> start -> in_consultation.
        $vStart = $this->insertVisit($this->insertPatient($fx['clinic'], 'g1s'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        $this->assertSame(200, $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vStart . '/call', [], $headers)->get_status());
        $rStart = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vStart . '/start', [], $headers);
        self::assertSame(200, $rStart->get_status(), 'G1: start works, got ' . $rStart->get_status() . '/' . $this->errCode($rStart));
        self::assertSame('in_consultation', $this->payload($rStart)['status']);
        self::assertNotEmpty($this->findVisit($vStart)['consultation_started_at']);

        // C. called -> recall -> waiting (+ recall_count increment).
        $vRecall = $this->insertVisit($this->insertPatient($fx['clinic'], 'g1c'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        $this->assertSame(200, $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vRecall . '/call', [], $headers)->get_status());
        $rRecall = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vRecall . '/recall', [], $headers);
        self::assertSame(200, $rRecall->get_status(), 'G1: recall works, got ' . $rRecall->get_status() . '/' . $this->errCode($rRecall));
        self::assertSame('waiting', $this->payload($rRecall)['status']);
        self::assertSame(1, (int) $this->payload($rRecall)['recall_count'], 'G1: recall_count incremented');
        self::assertSame(1, (int) $this->findVisit($vRecall)['recall_count']);

        // D1. waiting -> skip -> skipped with required reason.
        $vSkipW = $this->insertVisit($this->insertPatient($fx['clinic'], 'g1d'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        $rSkipW = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vSkipW . '/skip', ['reason' => 'بیمار موقتاً خارج شد'], $headers);
        self::assertSame(200, $rSkipW->get_status(), 'G1: skip from waiting works');
        self::assertSame('skipped', $this->payload($rSkipW)['status']);
        self::assertFalse((bool) $this->payload($rSkipW)['active'], 'G1: skipped visit deactivated');
        self::assertSame('بیمار موقتاً خارج شد', (string) $this->findVisit($vSkipW)['skip_reason']);

        // D2. called -> skip -> skipped with required reason.
        $vSkipC = $this->insertVisit($this->insertPatient($fx['clinic'], 'g1e'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        $this->assertSame(200, $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vSkipC . '/call', [], $headers)->get_status());
        $rSkipC = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vSkipC . '/skip', ['reason' => 'عدم پاسخ پس از فراخوان'], $headers);
        self::assertSame(200, $rSkipC->get_status(), 'G1: skip from called works');
        self::assertSame('skipped', $this->payload($rSkipC)['status']);
    }

    // ============ Group 2 — invalid transitions + recall limit + skip reason ============

    public function testGroup2_InvalidTransitionsRecallLimitSkipReason(): void
    {
        $fx = $this->makePortalStage('g2');
        $headers = $this->scopeHeaders($fx['clinic']);
        wp_set_current_user($fx['doctor']);

        // start from waiting => CLINIC_INVALID_TRANSITION / 409.
        $vStartBad = $this->insertVisit($this->insertPatient($fx['clinic'], 'g2a'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        $rStartBad = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vStartBad . '/start', [], $headers);
        self::assertSame(409, $rStartBad->get_status(), 'G2: start from waiting rejected');
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($rStartBad));
        self::assertSame('waiting', (string) $this->findVisit($vStartBad)['status'], 'G2: rejected transition leaves state untouched');

        // recall from waiting => invalid transition.
        $vRecallBad = $this->insertVisit($this->insertPatient($fx['clinic'], 'g2b'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        $rRecallBad = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vRecallBad . '/recall', [], $headers);
        self::assertSame(409, $rRecallBad->get_status(), 'G2: recall from waiting rejected');
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($rRecallBad));

        // call from in_consultation => invalid transition.
        $vConsult = $this->insertVisit($this->insertPatient($fx['clinic'], 'g2c'), $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $rCallConsult = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vConsult . '/call', [], $headers);
        self::assertSame(409, $rCallConsult->get_status(), 'G2: call from in_consultation rejected');
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($rCallConsult));

        // call from skipped => invalid transition.
        $vSkipped = $this->insertVisit($this->insertPatient($fx['clinic'], 'g2d'), $fx['clinician'], $fx['clinic'], $fx['location'], 'skipped');
        $rCallSkipped = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vSkipped . '/call', [], $headers);
        self::assertSame(409, $rCallSkipped->get_status(), 'G2: call from skipped rejected');
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($rCallSkipped));

        // skip without reason => validation failure (missing, empty, blank).
        $vSkipBad = $this->insertVisit($this->insertPatient($fx['clinic'], 'g2e'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        foreach ([[], ['reason' => ''], ['reason' => '   ']] as $i => $body) {
            $rSkipBad = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vSkipBad . '/skip', $body, $headers);
            self::assertSame(422, $rSkipBad->get_status(), 'G2: skip without reason rejected (case ' . $i . ')');
            self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rSkipBad));
        }
        self::assertSame('waiting', (string) $this->findVisit($vSkipBad)['status']);

        // recall over configured maximum => CLINIC_RECALL_LIMIT_REACHED / 409.
        $settings = App::settingsFactory()->forClinic($fx['clinic']);
        $prevMax = $settings->get('queue.max_recalls', 3);
        $settings->set('queue.max_recalls', 1);
        try {
            $vLimit = $this->insertVisit($this->insertPatient($fx['clinic'], 'g2f'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
            $this->assertSame(200, $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vLimit . '/call', [], $headers)->get_status());
            $rRecall1 = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vLimit . '/recall', [], $headers);
            self::assertSame(200, $rRecall1->get_status(), 'G2: first recall within limit');
            $this->assertSame(200, $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vLimit . '/call', [], $headers)->get_status());
            $rRecall2 = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vLimit . '/recall', [], $headers);
            self::assertSame(409, $rRecall2->get_status(), 'G2: recall over maximum rejected');
            self::assertSame('CLINIC_RECALL_LIMIT_REACHED', $this->errCode($rRecall2));
        } finally {
            $settings->set('queue.max_recalls', $prevMax);
            Settings::flushCache();
        }

        // Duplicate/invalid second transition cannot both succeed (machine after lock).
        $vDup = $this->insertVisit($this->insertPatient($fx['clinic'], 'g2g'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        $this->assertSame(200, $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vDup . '/call', [], $headers)->get_status());
        $rDup = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vDup . '/call', [], $headers);
        self::assertSame(409, $rDup->get_status(), 'G2: second call cannot succeed');
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($rDup));
        self::assertSame('called', (string) $this->findVisit($vDup)['status']);
        self::assertSame(0, (int) $this->findVisit($vDup)['recall_count']);
    }

    // ============ Group 3 — own-doctor mutation authority ============

    public function testGroup3_OwnDoctorMutationAuthority(): void
    {
        $fx = $this->makePortalStage('g3');
        $headers = $this->scopeHeaders($fx['clinic']);

        $doctorB = $this->makeUser('g3_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G3 B', $fx['clinic'], 1, $doctorB);
        cpms_test_seed_membership($doctorB, $fx['clinic'], 'cpms_doctor');
        self::assertNotSame($fx['clinician'], $clinicianB, 'G3: distinct linked clinicians');

        // Positive control: own doctor CAN mutate own visit.
        $vOwn = $this->insertVisit($this->insertPatient($fx['clinic'], 'g3a'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        wp_set_current_user($fx['doctor']);
        $rOwn = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vOwn . '/call', [], $headers);
        self::assertSame(200, $rOwn->get_status(), 'G3 positive: own doctor mutates own visit');

        // Another doctor MUST NOT mutate this doctor's visit.
        $vTarget = $this->insertVisit($this->insertPatient($fx['clinic'], 'g3b'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        wp_set_current_user($doctorB);
        $rCross = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vTarget . '/call', [], $headers);
        self::assertSame(403, $rCross->get_status(), 'G3: cross-doctor mutation denied');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rCross));
        self::assertSame('waiting', (string) $this->findVisit($vTarget)['status'], 'G3: denied mutation leaves state untouched');

        // Client MUST NOT create authority via clinician_id/organization_id/role/patient_id.
        $rForge = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $vTarget . '/call', [
            'clinician_id' => $fx['clinician'],
            'organization_id' => 1,
            'role' => RolesAndCapabilities::ROLE_DOCTOR,
            'patient_id' => 1,
        ], $headers);
        self::assertSame(403, $rForge->get_status(), 'G3: forged client authority denied');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rForge));
        self::assertSame('waiting', (string) $this->findVisit($vTarget)['status']);
    }

    // ============ Group 4 — Clinic isolation (404 parity) ============

    public function testGroup4_ClinicIsolation(): void
    {
        $org = $this->insertOrg('G4 Org');
        $clinicA = $this->insertClinicInOrg('G4 Clinic A', $org, self::TZ_TEHRAN);
        $clinicB = $this->insertClinicInOrg('G4 Clinic B', $org, self::TZ_TEHRAN);
        $locA = $this->insertLocation($clinicA, 'G4 Loc A', self::TZ_TEHRAN, 1);
        $locB = $this->insertLocation($clinicB, 'G4 Loc B', self::TZ_TEHRAN, 1);

        $doctor = $this->makeUser('g4_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr G4', $clinicA, 1, $doctor);
        cpms_test_seed_membership($doctor, $clinicA, 'cpms_doctor');
        cpms_test_seed_membership($doctor, $clinicB, 'cpms_doctor');

        // Same linked clinician owns a visit in Clinic B (ownership passes; scope must deny).
        $visitB = $this->insertVisit($this->insertPatient($clinicB, 'g4x'), $clinician, $clinicB, $locB, 'waiting');

        wp_set_current_user($doctor);
        $rWrongClinic = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $visitB . '/call', [], $this->scopeHeaders($clinicA));
        self::assertSame(404, $rWrongClinic->get_status(), 'G4: cross-Clinic mutation denied with 404 parity, got ' . $rWrongClinic->get_status());
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rWrongClinic), 'G4: canonical non-enumerating denial');
        self::assertSame('waiting', (string) $this->findVisit($visitB)['status'], 'G4: denied mutation leaves state untouched');

        $rRightClinic = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $visitB . '/call', [], $this->scopeHeaders($clinicB));
        self::assertSame(200, $rRightClinic->get_status(), 'G4 positive: same-Clinic mutation succeeds');
        self::assertSame('called', (string) $this->findVisit($visitB)['status']);
    }

    // ============ Group 5 — Selected Location isolation — EXPECTED BACKEND RED ============

    public function testGroup5_SelectedLocationMutationIsolation(): void
    {
        $org = $this->insertOrg('G5 Org');
        $clinic = $this->insertClinicInOrg('G5 Clinic', $org, self::TZ_TEHRAN);
        $loc1 = $this->insertLocation($clinic, 'G5 Loc 1', self::TZ_TEHRAN, 1);
        $loc2 = $this->insertLocation($clinic, 'G5 Loc 2', self::TZ_TEHRAN, 0);

        $doctor = $this->makeUser('g5_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr G5', $clinic, 1, $doctor);
        cpms_test_seed_membership($doctor, $clinic, 'cpms_doctor');
        // A secretary recipient makes the C side-effect assertions non-vacuous:
        // each successful CALL emits exactly one QUEUE_CALLED row for her.
        $secretary = $this->makeUser('g5_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');

        $v1 = $this->insertVisit($this->insertPatient($clinic, 'g5a'), $clinician, $clinic, $loc1, 'waiting');
        $v2 = $this->insertVisit($this->insertPatient($clinic, 'g5b'), $clinician, $clinic, $loc2, 'waiting');
        $v3 = $this->insertVisit($this->insertPatient($clinic, 'g5c'), $clinician, $clinic, $loc2, 'waiting');

        wp_set_current_user($doctor);

        // Positive controls: same-Location mutations succeed (existing behavior).
        $r1 = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $v1 . '/call', [], $this->scopeHeaders($clinic, $loc1));
        self::assertSame(200, $r1->get_status(), 'G5 positive: L1 visit under trusted L1 succeeds');
        $r3 = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $v3 . '/call', [], $this->scopeHeaders($clinic, $loc2));
        self::assertSame(200, $r3->get_status(), 'G5 positive: L2 visit under trusted L2 succeeds');

        // GREEN (C): the cross-Location denial must be side-effect free — no
        // status change, no status-history row, no queue notification. Both
        // positive controls above already emitted one QUEUE_CALLED each.
        $notifBefore = count($this->notifRows('queue_called'));
        self::assertSame(2, $notifBefore, 'G5: positive controls emitted exactly two QUEUE_CALLED rows');

        // L2 visit under trusted L1 MUST be denied (same Clinic, same
        // doctor, otherwise valid state) — canonical non-enumerating denial.
        $r2 = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $v2 . '/call', [], $this->scopeHeaders($clinic, $loc1));
        self::assertSame(404, $r2->get_status(), 'G5: cross-Location mutation must be denied, got ' . $r2->get_status() . '/' . $this->errCode($r2));
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($r2));
        self::assertSame('waiting', (string) $this->findVisit($v2)['status'], 'G5: denied mutation leaves state untouched');
        self::assertCount(0, App::visitService()->history($doctor, $v2), 'G5: denied mutation writes no status history');
        self::assertSame($notifBefore, count($this->notifRows('queue_called')), 'G5: denied mutation emits no notification');

        // GREEN (D): trusted Clinic with NO Location in scope keeps the
        // established legacy/shared contract — no global Location rule.
        $v4 = $this->insertVisit($this->insertPatient($clinic, 'g5d'), $clinician, $clinic, $loc2, 'waiting');
        $r4 = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $v4 . '/call', [], $this->scopeHeaders($clinic));
        self::assertSame(200, $r4->get_status(), 'G5 legacy: no-Location scope mutation stays valid');
        self::assertSame('called', (string) $this->findVisit($v4)['status']);
    }

    // ============ Group 6 — nonce / capability / role authorization ============

    public function testGroup6_NonceCapabilityRoleAuthorization(): void
    {
        $fx = $this->makePortalStage('g6');
        $headers = $this->scopeHeaders($fx['clinic']);
        $visit = $this->insertVisit($this->insertPatient($fx['clinic'], 'g6a'), $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');

        // Missing nonce => 403 CLINIC_INVALID_NONCE (established guard order: nonce first).
        wp_set_current_user($fx['doctor']);
        $rNoNonce = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $visit . '/call', [], $headers, false);
        self::assertSame(403, $rNoNonce->get_status(), 'G6: missing nonce blocked');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($rNoNonce));

        // Unauthenticated => 401.
        wp_set_current_user(0);
        $rAnon = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $visit . '/call', [], $headers);
        self::assertSame(401, $rAnon->get_status(), 'G6: unauthenticated blocked');
        self::assertSame('CLINIC_UNAUTHORIZED', $this->errCode($rAnon));

        // Patient role => 403 (no QUEUE_CALL).
        $patientUser = $this->makeUser('g6_patient', RolesAndCapabilities::ROLE_PATIENT);
        wp_set_current_user($patientUser);
        $rPatient = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $visit . '/call', [], $headers);
        self::assertSame(403, $rPatient->get_status(), 'G6: patient role blocked');

        // Secretary (member, no QUEUE_CALL / CONSULT_START) => 403 on call AND start.
        $secretary = $this->makeUser('g6_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($secretary);
        $rSecCall = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $visit . '/call', [], $headers);
        self::assertSame(403, $rSecCall->get_status(), 'G6: secretary cannot call');
        $rSecStart = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $visit . '/start', [], $headers);
        self::assertSame(403, $rSecStart->get_status(), 'G6: secretary cannot start');

        self::assertSame('waiting', (string) $this->findVisit($visit)['status'], 'G6: all denied attempts leave state untouched');
    }

    // ============ Group 7 — secretary observability after doctor CALL ============

    public function testGroup7_SecretaryObservabilityAfterDoctorCall(): void
    {
        $fx = $this->makePortalStage('g7');
        $secretary = $this->makeUser('g7_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], 'cpms_secretary');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        $patientId = $this->insertPatient($fx['clinic'], 'g7a');
        $mobile = (string) App::db()->fetchValue('SELECT mobile FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d', [$patientId]);
        $visit = $this->insertVisit($patientId, $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');

        // Doctor CALL with bounded room text.
        wp_set_current_user($fx['doctor']);
        $rCall = $this->dispatch('POST', '/' . self::REST_NS . '/visits/' . $visit . '/call', ['room' => '3'], $headers);
        self::assertSame(200, $rCall->get_status(), 'G7: doctor call succeeds');

        // Durable visit status/history reflects `called` (+ room in history note).
        self::assertSame('called', (string) $this->findVisit($visit)['status'], 'G7: durable status called');
        $history = App::visitService()->history($secretary, $visit);
        self::assertCount(1, $history, 'G7: one durable history row');
        self::assertSame('called', $history[0]['to_status']);
        self::assertSame('waiting', $history[0]['from_status']);
        self::assertStringContainsString('3', (string) $history[0]['note'], 'G7: room visible in history contract');

        // Secretary-scoped /rt/queue sees the new queue event.
        wp_set_current_user($secretary);
        $rRt = $this->dispatch('GET', '/' . self::REST_NS . '/rt/queue', ['since' => 0], $headers);
        self::assertSame(200, $rRt->get_status(), 'G7: secretary rt/queue readable');
        $events = $this->payload($rRt)['events'] ?? [];
        $matching = array_values(array_filter($events, static fn (array $e): bool => (int) ($e['visit_id'] ?? 0) === $visit));
        self::assertCount(1, $matching, 'G7: secretary sees exactly the call event, got ' . count($events) . ' event(s)');
        self::assertSame('called', $matching[0]['to_status']);
        self::assertStringContainsString('3', (string) $matching[0]['note'], 'G7: room visible in rt event');

        // Existing internal QUEUE_CALLED notification is created (secretary, not actor).
        $rows = $this->notifRows('queue_called');
        self::assertCount(1, $rows, 'G7: one QUEUE_CALLED notification row');
        self::assertSame('internal', $rows[0]['channel']);
        self::assertSame('queued', $rows[0]['status']);
        self::assertSame($secretary, (int) $rows[0]['recipient_wp_user_id'], 'G7: secretary is notified, actor excluded');
        $notifPayload = json_decode((string) $rows[0]['payload_json'], true);
        self::assertSame('فراخوان بیمار', $notifPayload['title']);
        self::assertStringContainsString('Test', (string) $notifPayload['body'], 'G7: patient name in notification');
        self::assertStringContainsString('3', (string) $notifPayload['body'], 'G7: room in notification');

        // Secretary queue payload shows called status with bounded fields (no mobile/national id).
        $rQueue = $this->dispatch('GET', '/' . self::REST_NS . '/queue', [], $headers);
        self::assertSame(200, $rQueue->get_status(), 'G7: secretary queue readable');
        $queue = $this->payload($rQueue)['queue'] ?? [];
        $qMatching = array_values(array_filter($queue, static fn (array $row): bool => (int) ($row['id'] ?? 0) === $visit));
        self::assertCount(1, $qMatching, 'G7: secretary queue shows the called visit');
        self::assertSame('called', $qMatching[0]['status']);
        self::assertArrayHasKey('patient_name', $qMatching[0]);
        self::assertArrayNotHasKey('mobile', $qMatching[0], 'G7: bounded queue fields, no mobile');
        $encoded = (string) wp_json_encode($this->payload($rQueue));
        self::assertStringNotContainsString($mobile, $encoded, 'G7: no mobile leakage');
        self::assertStringNotContainsString('national_id', $encoded, 'G7: no national id leakage');
    }

    // ============ Group 8 — portal action visibility + wiring — GREEN ============

    public function testGroup8_DoctorPortalActionVisibilityAndRequestWiring(): void
    {
        $fx = $this->makePortalStage('g8');

        // The real merged portal still loads (proves the harness reached the product path).
        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'G8: independent Doctor Portal frontend entry must exist');
        $html = $this->renderPortal($fx['doctor'], $url);
        self::assertStringContainsString('cpms-doctor-portal-shell', $html, 'G8: real portal shell rendered');

        $root = dirname(__DIR__, 2);
        $templatePath = $root . '/templates/doctor-portal-shell.php';
        $jsPath = $root . '/assets/js/cpms-doctor-portal.js';
        self::assertFileExists($templatePath, 'G8: portal template exists');
        self::assertFileExists($jsPath, 'G8: portal JS exists');
        $ui = (string) file_get_contents($templatePath) . "\n" . (string) file_get_contents($jsPath);

        // Guards (pass now, must keep passing after GREEN):
        // (a) mutation wiring must never carry client authority.
        $wiringBlocks = [];
        if (preg_match_all('/fetch\s*\([^;]{0,600}visits\/[^;]{0,600}/s', $ui, $m) > 0) {
            $wiringBlocks = $m[0];
        }
        foreach ($wiringBlocks as $block) {
            foreach (['clinician_id', 'organization_id', 'patient_id'] as $key) {
                self::assertStringNotContainsString($key, $block, 'G8 guard: mutation wiring must not send ' . $key);
            }
        }
        // (b) terminal/non-actionable states expose no queue actions.
        foreach (['in_consultation', 'skipped'] as $state) {
            $offset = 0;
            while (false !== ($pos = strpos($ui, $state, $offset))) {
                $window = substr($ui, max(0, $pos - 200), 600);
                self::assertStringNotContainsString('data-action=', $window, 'G8 guard: no action controls near ' . $state);
                $offset = $pos + strlen($state);
            }
        }

        // GREEN: state-driven queue-row action controls + request wiring exist.
        $missing = [];
        foreach (['call' => 'waiting', 'skip' => 'waiting/called', 'start' => 'called', 'recall' => 'called'] as $action => $states) {
            if (!str_contains($ui, 'data-action="' . $action . '"')) {
                $missing[] = $action . ' control for ' . $states;
            }
        }
        if (!str_contains($ui, '/visits/')) {
            $missing[] = 'POST wiring to existing /visits/{id}/{action} routes';
        }
        if (!str_contains($ui, 'X-WP-Nonce') || !str_contains($ui, 'X-CPMS-Clinic-Id') || !str_contains($ui, 'X-CPMS-Location-Id')) {
            $missing[] = 'nonce + Clinic/Location selector headers on mutation wiring';
        }
        self::assertSame([], $missing, 'G8: Doctor Portal queue action controls/wiring missing: ' . implode('; ', $missing));
    }

    // ================= helpers (proven Slice 1 / RestQueue patterns) =================

    /**
     * @return array{clinic: int, location: int, clinician: int, doctor: int}
     */
    private function makePortalStage(string $tag): array
    {
        $org = $this->insertOrg('Stage ' . $tag);
        $clinic = $this->insertClinicInOrg('Stage Clinic ' . $tag, $org, self::TZ_TEHRAN);
        $loc = $this->insertLocation($clinic, 'Stage Loc ' . $tag, self::TZ_TEHRAN, 1);
        $doctor = $this->makeUser('qa_' . $tag . '_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr QA ' . $tag, $clinic, 1, $doctor);
        cpms_test_seed_membership($doctor, $clinic, 'cpms_doctor');
        return ['clinic' => $clinic, 'location' => $loc, 'clinician' => $clinician, 'doctor' => $doctor];
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
     * @return list<array<string, mixed>>
     */
    private function notifRows(string $template): array
    {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'cpms_notifications WHERE template = %s ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $template
            ),
            ARRAY_A
        );
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
        $mrn = 'MR-QA-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2));
        $mobile = '0912' . sprintf('%07d', random_int(1000000, 9999999));
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', $clinicId, $mrn, 'Test', 'Patient ' . substr($mrn, -4), $mobile, 'active', $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertVisit(int $patientId, int $clinicianId, int $clinicId, int $locId, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $active = 'skipped' === $status ? 0 : 1;
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %d, %s, %s)', $clinicId, $locId, $clinicianId, $patientId, 'walk_in', $status, self::FIXED_UTC_DATE, self::FIXED_UTC_DATE . ' 10:00:00', self::FIXED_UTC_DATE . ' 10:00:00', $active, $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function tryResolveDoctorPortalUrl(): ?string
    {
        $classes = ['ClinicCore\\Frontend\\DoctorPortalShell'];
        $methods = ['portal_url', 'frontendPortalUrl', 'frontendUrl', 'doctorPortalFrontendUrl', 'pageUrl'];
        foreach ($classes as $c) {
            if (!class_exists($c)) {
                continue;
            }
            foreach ($methods as $m) {
                if (!is_callable([$c, $m])) {
                    continue;
                }
                try {
                    $u = (string) $c::{$m}();
                    if ($u !== '' && !str_contains($u, '/wp-admin/') && str_contains($u, 'http')) {
                        return $u;
                    }
                } catch (\Throwable $e) {
                    unset($e);
                }
            }
        }
        return null;
    }

    private function renderPortal(int $userId, string $url): string
    {
        wp_set_current_user($userId);
        $path = (string) (wp_parse_url($url, \PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, \PHP_URL_QUERY) ?? '');
        $req = $path . ($query !== '' ? '?' . $query : '');
        if ($req === '') {
            $req = '/';
        }
        $this->go_to($req);
        $baseline = get_stylesheet_directory() . '/page.php';
        if (!is_readable($baseline)) {
            $baseline = get_stylesheet_directory() . '/index.php';
        }
        $tpl = (string) apply_filters('template_include', $baseline);
        self::assertNotSame($baseline, $tpl, 'G8: template_include must intercept with plugin-owned template');
        self::assertFileExists($tpl);
        ob_start();
        include $tpl;
        return (string) ob_get_clean();
    }
}
