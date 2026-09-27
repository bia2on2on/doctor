<?php
/**
 * Phase 11 Slice 1 — Staff Portal Reception — BLOCKER-REGRESSION RED.
 *
 * Blocker (independent acceptance, A-class): UNRECOVERABLE PARTIAL ARRIVAL.
 * The two-stage reception action (EXISTING check-in then EXISTING enqueue) can
 * commit stage 1 (visit checked_in) and fail stage 2 (enqueue → waiting). The
 * current retry re-runs check-in, hits CLINIC_DUPLICATE_ACTIVE_VISIT and can
 * never recover, and the UI disables the arrival control on the checked_in row.
 *
 * This is a blocker-regression RED on the accepted slice contract — NOT the
 * original product RED (that one stays intact in
 * Phase11StaffPortalReceptionArrivalRedTest, G1–G9 untouched).
 *
 * Failure injection is TEST-ONLY and minimal: an existing WordPress `query`
 * filter sabotages exactly the one UPDATE that writes the waiting transition
 * (table cpms_visits + value 'waiting'). No production test hook exists or is
 * added. The two-stage surface itself is the EXISTING per-Clinic product knob
 * queue.auto_enqueue=false (FR-6.1 keeps the auto path; the reception
 * controller runs the explicit enqueue stage when check-in returns checked_in).
 *
 * PROOF MAP
 *  R1  Partial arrival: stage 1 commits, stage 2 fails — HTTP truthfully NOT
 *      success, durable state honestly checked_in, exactly one Visit. ...... RED
 *  R2  Retry through the SAME reception workflow: no second check-in, only the
 *      existing enqueue transition on the existing visit, reaches waiting. . RED
 *  R3  Recovery path fails closed for cross-Location / cross-Clinic / foreign
 *      appointment; a client-supplied visit id is never authority. ......... guard
 *  R4  Already-waiting rows expose no valid retry (fail closed, no new Visit) guard
 *  R5  UI contract pins: checked_in recovery control + sticky partial-failure
 *      message that a silent board refresh must not erase. ................ RED
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
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase11ReceptionPartialArrivalRecoveryRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';

    /** 18:00 UTC ⇒ Tehran (UTC+3:30) local date 2026-03-14. */
    private const FIXED_UTC  = '2026-03-14 18:00:00';
    private const TEHRAN_DATE = '2026-03-14';
    private const TZ_TEHRAN  = 'Asia/Tehran';
    private const TZ_TOKYO   = 'Asia/Tokyo';

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

    // ============ R1 — Partial arrival is honest, durable, bounded ============

    /**
     * Stage 1 succeeds and stage 2 (enqueue) fails: the response must NOT
     * claim success, must report the partial checked_in outcome in a bounded
     * way, and the durable state must be exactly one Visit in checked_in.
     */
    public function testR1_PartialArrivalIsTruthfulAndLeavesOneCheckedInVisit(): void
    {
        $fx = $this->stageR('r1');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $this->setAutoEnqueue($fx['clinic'], false);

        $patient = $this->insertPatient($fx['clinic'], 'r1a');
        $slot    = $this->insertSlot($fx['clinic'], $fx['loc_tehran'], $fx['clinician'], self::TEHRAN_DATE, '22:00:00');
        $appt    = $this->insertAppointment($fx['clinic'], $fx['loc_tehran'], 'r1-arr', $patient, $fx['clinician'], $slot, self::TEHRAN_DATE, '22:00:00');

        $res = $this->withSabotagedEnqueue(function () use ($patient, $appt, $fx) {
            return $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
                'patient_id'     => $patient,
                'appointment_id' => $appt,
            ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        });

        // HTTP/product truthfulness — the partial outcome is never a success.
        self::assertGreaterThanOrEqual(400, $res->get_status(), 'R1: a partial arrival must not claim success at the HTTP level — got ' . $res->get_status());
        self::assertSame(
            'CLINIC_ARRIVAL_INCOMPLETE',
            $this->errCode($res),
            'R1: the partial outcome carries its own non-generic code — got=' . $this->errCode($res) . ' body=' . substr((string) wp_json_encode((array) $res->get_data()), 0, 300)
        );

        // Bounded, non-sensitive partial report.
        $data    = $this->errorData($res);
        $arrival = (array) ($data['arrival'] ?? []);
        self::assertFalse((bool) ($arrival['complete'] ?? true), 'R1: complete must be false on the partial outcome');
        self::assertSame('ok', (string) ($arrival['check_in'] ?? ''), 'R1: the committed check-in stage is reported');
        self::assertSame('failed', (string) ($arrival['enqueue'] ?? ''), 'R1: the failed enqueue stage is reported');
        self::assertSame('checked_in', (string) ($arrival['outcome'] ?? ''), 'R1: the reported outcome is the durable truth');
        self::assertSame('checked_in', (string) ($data['visit_status'] ?? ''), 'R1: the durable visit state is exposed for the recovery UX');

        // Durable state: exactly one Visit, honestly checked_in.
        self::assertSame(1, $this->visitCountForAppointment($appt), 'R1: exactly one visit exists after the partial');
        $visitId = (int) ($data['visit_id'] ?? 0);
        self::assertGreaterThan(0, $visitId, 'R1: the partial report identifies the existing visit');
        $row = $this->findVisit($visitId);
        self::assertSame('checked_in', (string) $row['status'], 'R1: stage 1 committed and stage 2 left no lie behind');
        $apptRow = $this->findAppointment($appt);
        self::assertSame($visitId, (int) ($apptRow['active_visit_id'] ?? 0), 'R1: the appointment points at the existing valid active visit');
    }

    // ============ R2 — One retry recovers; no duplicate check-in ============

    /**
     * Retry through the SAME reception workflow on the same authorized
     * appointment must not create/check-in a duplicate Visit; it must execute
     * only the existing enqueue transition on the server-derived existing
     * visit and reach waiting.
     */
    public function testR2_RetryUsesExistingVisitAndEnqueueOnly(): void
    {
        $fx = $this->stageR('r2');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $this->setAutoEnqueue($fx['clinic'], false);

        $patient = $this->insertPatient($fx['clinic'], 'r2a');
        $slot    = $this->insertSlot($fx['clinic'], $fx['loc_tehran'], $fx['clinician'], self::TEHRAN_DATE, '22:00:00');
        $appt    = $this->insertAppointment($fx['clinic'], $fx['loc_tehran'], 'r2-arr', $patient, $fx['clinician'], $slot, self::TEHRAN_DATE, '22:00:00');

        // Produce the real partial state (stage 1 committed, stage 2 failed).
        $this->withSabotagedEnqueue(function () use ($patient, $appt, $fx) {
            return $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
                'patient_id'     => $patient,
                'appointment_id' => $appt,
            ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        });
        self::assertSame(1, $this->visitCountForAppointment($appt), 'R2: the partial left exactly one visit');
        $visitId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_visits') . ' WHERE appointment_id = %d LIMIT 1',
            [$appt]
        );

        // Server-side derivation pin: before any retry runs, the existing
        // valid active visit must be visible through the trusted appointment
        // + Clinic + selected Location relationship (the recovery authority).
        $lookup = App::db()->fetchRow(
            'SELECT id, status FROM ' . App::db()->table('cpms_visits') . ' WHERE appointment_id = %d AND clinic_id = %d AND location_id = %d AND active = 1 ORDER BY id DESC LIMIT 1',
            [$appt, $fx['clinic'], $fx['loc_tehran']]
        );
        self::assertIsArray($lookup, 'R2-pre: the existing active visit must be visible to the trusted-relationship lookup');
        self::assertSame($visitId, (int) ($lookup['id'] ?? 0), 'R2-pre: the lookup resolves exactly the partial visit');
        self::assertSame('checked_in', (string) ($lookup['status'] ?? ''), 'R2-pre: the durable partial state is checked_in');

        // RETRY — same authorized reception workflow, no sabotage.
        $res = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $patient,
            'appointment_id' => $appt,
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));

        self::assertSame(200, $res->get_status(), 'R2: the retry must recover — got ' . $res->get_status() . '/' . $this->errCode($res));
        $data    = $this->payload($res);
        $arrival = (array) ($data['arrival'] ?? []);
        self::assertSame('existing', (string) ($arrival['check_in'] ?? ''), 'R2: the retry runs NO second check-in — it recovers the existing visit');
        self::assertSame('ok', (string) ($arrival['enqueue'] ?? ''), 'R2: only the existing enqueue transition runs');
        self::assertSame('waiting', (string) ($arrival['outcome'] ?? ''), 'R2: the retry reaches the existing waiting state');
        self::assertTrue((bool) ($arrival['complete'] ?? false), 'R2: full success is claimed only now');

        // Durable truth: one visit, waiting, check-in ran exactly once.
        self::assertSame(1, $this->visitCountForAppointment($appt), 'R2: no duplicate visit is created');
        self::assertSame($visitId, (int) ($data['visit']['id'] ?? 0), 'R2: the same existing visit was transitioned');
        $row = $this->findVisit($visitId);
        self::assertSame('waiting', (string) $row['status'], 'R2: durable status is waiting');
        self::assertNotEmpty($row['waiting_since'], 'R2: waiting_since is stamped by the existing enqueue transition');

        $history = App::db()->fetchAll(
            'SELECT from_status, to_status FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d ORDER BY id ASC',
            [$visitId]
        );
        $seq = array_map(static fn (array $h): string => (string) ($h['from_status'] ?? 'null') . '>' . (string) $h['to_status'], is_array($history) ? $history : []);
        self::assertSame(
            ['null>checked_in', 'checked_in>waiting'],
            $seq,
            'R2: check-in ran once; enqueue is the only added transition — got=' . wp_json_encode($seq)
        );
    }

    // ============ R3 — Recovery authority is server-side and fail-closed ============

    /**
     * The recovery target is derived server-side from the authorized
     * appointment + trusted Clinic + selected Location. Cross-Location,
     * cross-Clinic and foreign-appointment attempts fail closed with no
     * durable side effect, and a client-supplied visit id is never authority.
     */
    public function testR3_RecoveryRejectsForeignSelectorsAndClientVisitIds(): void
    {
        $fx = $this->stageR('r3');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $this->setAutoEnqueue($fx['clinic'], false);

        // A second, unrelated waiting visit — the client will try to point the
        // recovery at it via a client-supplied visit id.
        $patientB = $this->insertPatient($fx['clinic'], 'r3b');
        $slotB    = $this->insertSlot($fx['clinic'], $fx['loc_tehran'], $fx['clinician'], self::TEHRAN_DATE, '21:00:00');
        $apptB    = $this->insertAppointment($fx['clinic'], $fx['loc_tehran'], 'r3-b', $patientB, $fx['clinician'], $slotB, self::TEHRAN_DATE, '21:00:00');
        $resB = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $patientB,
            'appointment_id' => $apptB,
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(200, $resB->get_status(), 'R3: the unrelated normal arrival succeeds');
        $visitB = (int) ($this->payload($resB)['visit']['id'] ?? 0);
        $historyBBefore = App::db()->fetchAll(
            'SELECT from_status, to_status FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d ORDER BY id ASC',
            [$visitB]
        );

        // Partial state on appointment A.
        $patientA = $this->insertPatient($fx['clinic'], 'r3a');
        $slotA    = $this->insertSlot($fx['clinic'], $fx['loc_tehran'], $fx['clinician'], self::TEHRAN_DATE, '22:00:00');
        $apptA    = $this->insertAppointment($fx['clinic'], $fx['loc_tehran'], 'r3-a', $patientA, $fx['clinician'], $slotA, self::TEHRAN_DATE, '22:00:00');
        $this->withSabotagedEnqueue(function () use ($patientA, $apptA, $fx) {
            return $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
                'patient_id'     => $patientA,
                'appointment_id' => $apptA,
            ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        });
        self::assertSame(1, $this->visitCountForAppointment($apptA), 'R3: partial state on A holds one visit');
        $visitA = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_visits') . ' WHERE appointment_id = %d LIMIT 1',
            [$apptA]
        );

        // Cross-Location attempt → fail closed, no side effect.
        $resLoc = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $patientA,
            'appointment_id' => $apptA,
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_tokyo']));
        self::assertGreaterThanOrEqual(400, $resLoc->get_status(), 'R3: cross-Location recovery attempt fails closed');
        self::assertSame('checked_in', (string) $this->findVisit($visitA)['status'], 'R3: cross-Location attempt left no durable trace');

        // Cross-Clinic attempt → fail closed, no side effect.
        $otherClinic = $this->insertClinicInOrg('Rcp Other ' . 'r3', $fx['org'], self::TZ_TEHRAN);
        $resClinic = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $patientA,
            'appointment_id' => $apptA,
        ], $this->scopeHeaders($otherClinic, $fx['loc_tehran']));
        self::assertGreaterThanOrEqual(400, $resClinic->get_status(), 'R3: cross-Clinic recovery attempt fails closed');
        self::assertSame('checked_in', (string) $this->findVisit($visitA)['status'], 'R3: cross-Clinic attempt left no durable trace');

        // Foreign appointment (another Clinic) → fail closed, no side effect.
        $foreignOrg    = $this->insertOrg('Rcp Foreign Org r3');
        $foreignClinic = $this->insertClinicInOrg('Rcp Foreign Clinic r3', $foreignOrg, self::TZ_TEHRAN);
        $foreignLoc    = $this->insertLocation($foreignClinic, 'Rcp Foreign Loc r3', self::TZ_TEHRAN, 1);
        $foreignClin   = $this->insertClinician('Dr Foreign r3', $foreignClinic, 1, 0);
        $foreignPat    = $this->insertPatient($foreignClinic, 'r3f');
        $foreignSlot   = $this->insertSlot($foreignClinic, $foreignLoc, $foreignClin, self::TEHRAN_DATE, '22:00:00');
        $foreignAppt   = $this->insertAppointment($foreignClinic, $foreignLoc, 'r3-f', $foreignPat, $foreignClin, $foreignSlot, self::TEHRAN_DATE, '22:00:00');
        $resForeign = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $foreignPat,
            'appointment_id' => $foreignAppt,
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertGreaterThanOrEqual(400, $resForeign->get_status(), 'R3: a foreign appointment cannot use the recovery path');

        // Client-supplied visit id is never authority: recovery still targets
        // the server-derived visit for THIS appointment; visit B untouched.
        $resRec = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $patientA,
            'appointment_id' => $apptA,
            'visit_id'       => $visitB,
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(200, $resRec->get_status(), 'R3: the legit recovery succeeds — got ' . $resRec->get_status() . '/' . $this->errCode($resRec));
        self::assertSame('waiting', (string) $this->findVisit($visitA)['status'], 'R3: the recovery targets the server-derived visit of THIS appointment');
        self::assertSame('waiting', (string) $this->findVisit($visitB)['status'], 'R3: the client-supplied visit id is ignored');
        $historyBAfter = App::db()->fetchAll(
            'SELECT from_status, to_status FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d ORDER BY id ASC',
            [$visitB]
        );
        self::assertSame($historyBBefore, $historyBAfter, 'R3: no transition was applied to the client-supplied visit');
    }

    // ============ R4 — Queued states expose no valid retry ============

    /**
     * An appointment already waiting must fail closed on a repeated arrival:
     * no second Visit, no state change, no recovery action.
     */
    public function testR4_WaitingAppointmentRejectsArrivalRetry(): void
    {
        $fx = $this->stageR('r4');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $this->setAutoEnqueue($fx['clinic'], false);

        $patient = $this->insertPatient($fx['clinic'], 'r4a');
        $slot    = $this->insertSlot($fx['clinic'], $fx['loc_tehran'], $fx['clinician'], self::TEHRAN_DATE, '22:00:00');
        $appt    = $this->insertAppointment($fx['clinic'], $fx['loc_tehran'], 'r4-arr', $patient, $fx['clinician'], $slot, self::TEHRAN_DATE, '22:00:00');

        $res = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $patient,
            'appointment_id' => $appt,
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertSame(200, $res->get_status(), 'R4: the normal arrival succeeds');

        $res2 = $this->dispatch('POST', '/' . self::REST_NS . '/staff/portal/reception/arrivals', [
            'patient_id'     => $patient,
            'appointment_id' => $appt,
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_tehran']));
        self::assertGreaterThanOrEqual(400, $res2->get_status(), 'R4: a waiting appointment rejects the arrival retry');
        self::assertSame(1, $this->visitCountForAppointment($appt), 'R4: no duplicate visit is created');
        $visitId = (int) ($this->payload($res)['visit']['id'] ?? 0);
        self::assertSame('waiting', (string) $this->findVisit($visitId)['status'], 'R4: the waiting state is untouched');
    }

    // ============ R5 — UI recovery control + sticky partial message ============

    /**
     * The reception UI contract pins: a checked_in row exposes a recovery
     * control, and the actionable partial-failure message is sticky against
     * silent board refreshes (behavior proven live in the browser journey;
     * these pins keep the mechanism from silently disappearing).
     */
    public function testR5_UiExposesRecoveryControlAndStickyPartialMessage(): void
    {
        $fx = $this->stageR('r5');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        $html = $this->renderStaffPortal($fx['secretary']);

        self::assertStringContainsString('sr-recover', $html, 'R5: the checked_in recovery control marker exists');
        self::assertStringContainsString('تکمیل ورود به صف', $html, 'R5: the recovery action is clearly labeled');
        self::assertStringContainsString('اما قرارگیری در صف انجام نشد', $html, 'R5: the partial-failure message is distinct and actionable');
        self::assertStringContainsString('statusSticky', $html, 'R5: the sticky status mechanism exists (silent refresh must not erase the partial message)');
    }

    // ================= helpers (TEST-ONLY) =================

    /**
     * TEST-ONLY failure injection: sabotage exactly the UPDATE that writes the
     * waiting transition (existing WordPress `query` filter — no production
     * test hook). Stage 1 (check-in → checked_in) is untouched.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withSabotagedEnqueue(callable $fn)
    {
        $sabotage = static function (string $q): string {
            if (preg_match('/^\s*UPDATE\b/i', $q) && false !== strpos($q, 'cpms_visits') && false !== strpos($q, "'waiting'")) {
                return 'UPDATE `cpms_sabotage_nonexistent` SET `x` = 1';
            }
            return $q;
        };
        add_filter('query', $sabotage);
        try {
            return $fn();
        } finally {
            remove_filter('query', $sabotage);
        }
    }

    private function setAutoEnqueue(int $clinicId, bool $on): void
    {
        $settings = new Settings(App::db(), $clinicId);
        $settings->set('queue.auto_enqueue', $on);
        Settings::flushCache();
    }

    private function stageR(string $tag): array
    {
        $org = $this->insertOrg('Rcv Org ' . $tag);
        $clinic = $this->insertClinicInOrg('Rcv Clinic ' . $tag, $org, 'America/New_York');
        $locTehran = $this->insertLocation($clinic, 'Rcv Tehran ' . $tag, self::TZ_TEHRAN, 1);
        $locTokyo  = $this->insertLocation($clinic, 'Rcv Tokyo ' . $tag, self::TZ_TOKYO, 0);
        $doctor    = $this->makeUser('qa_' . $tag . '_rcvdoc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr Rcv ' . $tag, $clinic, 1, $doctor);
        $secretary = $this->makeUser('qa_' . $tag . '_rcvsec', RolesAndCapabilities::ROLE_SECRETARY);
        return [
            'org'        => $org,
            'clinic'     => $clinic,
            'loc_tehran' => $locTehran,
            'loc_tokyo'  => $locTokyo,
            'doctor'     => $doctor,
            'clinician'  => $clinician,
            'secretary'  => $secretary,
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

    /**
     * Error envelope data (WP_Error → REST body 'data' block).
     *
     * @return array<string, mixed>
     */
    private function errorData(WP_REST_Response $res): array
    {
        $b = $res->get_data();
        if (is_array($b) && isset($b['data']) && is_array($b['data'])) {
            return $b['data'];
        }
        return [];
    }

    private function errCode(WP_REST_Response $res): string
    {
        $b = $res->get_data();
        if ($b instanceof WP_Error) {
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

    private function renderStaffPortal(int $userId): string
    {
        wp_set_current_user($userId);
        $url = StaffPortalShell::portal_url();
        self::assertNotEmpty($url, 'the canonical Staff Portal page exists');
        $path = (string) (wp_parse_url($url, \PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, \PHP_URL_QUERY) ?? '');
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
        $mrn = 'MR-RCV-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2));
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
