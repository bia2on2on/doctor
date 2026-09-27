<?php
/**
 * Phase 11 (bounded slice) — Staff Portal Reception: Walk-In for Selected
 * Clinic Patient — TEST-ONLY RED.
 *
 * Slice: inside the EXISTING Staff Portal Reception module an authorized
 * secretary who already selected a Clinic patient (Slices 2/3) sees the
 * doctors eligible for the trusted Clinic + operational Location and
 * explicitly submits ONE walk-in. The EXISTING walk-in/queue machine is used
 * (VisitService::walkIn → checked_in → existing enqueue → waiting). No
 * appointment is created; no second walk-in/queue model exists.
 *
 * Live reconstruction (verified, not assumed — 2026-09-27):
 * - authoritative main = origin/main = b1862e9a67a27bd5b9c51db810be920b8925f914;
 *   open PRs = 0; latest migration = 2026_09_26_0023_handwriting_prescription_paper.php
 *   (this RED adds/reserves no migration).
 * - Phase 11 = STARTED / IN PROGRESS — NOT CLOSED; Slice 1 Arrival Board (#133),
 *   Slice 2 search (#135), Slice 3 create (#136) verified in live code.
 * - established walk-in contract (reused, never duplicated):
 *   POST /clinic/v1/visits/walk-in → QueueController → VisitService::walkIn
 *   (secretary role; license; trusted Clinic from scope; clinician
 *   participation via MembershipRepository::clinician_participates_in — which
 *   still honours the legacy HOME-Clinic compatibility path; patient must be
 *   in the trusted Clinic; guardDuplicateActiveVisit = same patient +
 *   clinician + operational day, ANY Location; createVisit source=walk_in,
 *   status=checked_in, history, VISIT_WALK_IN audit; queue.auto_enqueue
 *   (default true) applies the existing enqueue inside the same transaction).
 *   The shared route gates only on cpms_queue_checkin — no Reception
 *   secretary-role/membership/Location boundary and no Location-assignment
 *   clinician eligibility.
 *
 * The RED fails ONLY because the Reception walk-in boundary/UI is missing:
 * bootstrap, migrations and fixtures succeed; the shared walk-in route answers
 * 200 for the same secretary in the same fixture (W1 control); Slices 1–3 stay
 * GREEN (W11 control); the intended Reception routes answer with the canonical
 * missing-route fingerprint and the rendered module has no walk-in section.
 *
 * TEST GROUP MAP:
 *  W1  Surface + explicit walk-in reaches waiting (shared control) . INTENDED RED
 *  W2  Access: role / capability / nonce / membership .............. INTENDED RED
 *  W3  Location 0 / 1 / N / foreign / inactive / unassigned ........ INTENDED RED
 *  W4  Clinician eligibility (participation + Location assignment) . INTENDED RED
 *  W5  Patient belongs to trusted Clinic; Location ≠ ownership ..... INTENDED RED
 *  W6  Existing machine: auto-enqueue on/off, history, audit ....... INTENDED RED
 *  W7  Partial checked_in + server-derived retry (no hijack) ....... INTENDED RED
 *  W8  Non-recoverable existing states fail closed ................. INTENDED RED
 *  W9  Duplicate active Visit preserved; no appointment conversion . INTENDED RED
 *  W10 Privacy: bounded clinician + walk-in payloads ............... INTENDED RED
 *  W11 Slice 1 board + Slice 2 search + Slice 3 create intact ...... CONTROL (pass)
 *
 * Excluded by design: appointment create/convert, patient create/edit here,
 * finance, new role/capability/status/machine, migrations, Organization
 * identity activation, shared-route tightening.
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

final class Phase11ReceptionWalkInRedTest extends WP_UnitTestCase
{
    private const CLINICIANS    = '/clinic/v1/staff/portal/reception/clinicians';
    private const WALK_IN       = '/clinic/v1/staff/portal/reception/walk-ins';
    private const SHARED_WALKIN = '/clinic/v1/visits/walk-in';
    private const BOARD         = '/clinic/v1/staff/portal/reception/board';
    private const ARRIVALS      = '/clinic/v1/staff/portal/reception/arrivals';
    private const SEARCH        = '/clinic/v1/staff/portal/reception/patients/search';
    private const CREATE        = '/clinic/v1/staff/portal/reception/patients';

    /** 08:00 UTC ⇒ Tehran 11:30 on 2026-03-14 (every stage Location is Asia/Tehran). */
    private const FIXED_UTC = '2026-03-14 08:00:00';
    private const TODAY     = '2026-03-14';
    private const TZ        = 'Asia/Tehran';

    /** The ONLY keys a Reception clinician option may carry. */
    private const CLINICIAN_KEYS = ['id', 'name'];

    /** Bounded queue presentation keys a Reception walk-in visit may carry. */
    private const VISIT_KEYS = ['active', 'appointment_id', 'check_in_at', 'clinician_id', 'clinician_name', 'id', 'patient_id', 'patient_name', 'source', 'status', 'waiting_since'];

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

    // ============ W1 — Surface + explicit walk-in reaches waiting ============

    public function testW1_SecretaryWalkInReachesWaitingInsideReception(): void
    {
        $fx = $this->stage('w1');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        // Control: the SHARED established walk-in route answers for this very
        // secretary and fixture (the trusted-Clinic secretary path is reached).
        $p0 = $this->insertPatient($fx['clinic'], 'w1ctl');
        $shared = $this->dispatch('POST', self::SHARED_WALKIN, [
            'patient_id'   => $p0,
            'clinician_id' => $fx['c1'],
        ], $this->scopeHeaders($fx['clinic'], $fx['locA']));
        self::assertSame(200, $shared->get_status(), 'W1 control: shared established walk-in reachable — ' . $this->errCode($shared));
        self::assertSame(1, $this->visitCount($p0), 'W1 control: shared walk-in persisted one Visit');

        // UI: Reception carries the compact walk-in section in the selected state.
        $html = $this->renderReception($fx['secretary']);
        self::assertStringContainsString('data-role="reception-app"', $html, 'W1: reception module renders');
        self::assertStringContainsString('data-role="sr-search-selected"', $html, 'W1: existing selected-patient surface remains');
        foreach (['sr-walkin', 'sr-walkin-location', 'sr-walkin-clinician', 'sr-walkin-submit', 'sr-walkin-state', 'sr-walkin-recover'] as $role) {
            self::assertStringContainsString('data-role="' . $role . '"', $html, 'W1: walk-in surface marker ' . $role);
        }
        self::assertStringContainsString('/staff/portal/reception/clinicians', $html, 'W1: UI reads the Reception clinician boundary');
        self::assertStringContainsString('/staff/portal/reception/walk-ins', $html, 'W1: UI posts to the Reception walk-in boundary');

        // REST: eligible doctors for the trusted Location (N=2 at A).
        $list = $this->dispatch('GET', self::CLINICIANS, [], $this->scopeHeaders($fx['clinic'], $fx['locA']));
        self::assertSame(200, $list->get_status(), 'W1: reception clinicians answers — ' . $this->errCode($list));
        self::assertSame($this->sorted([$fx['c1'], $fx['c2']]), $this->clinicianIds($list), 'W1: exactly the eligible doctors at Location A');

        // REST: explicit walk-in for the selected patient + selected doctor.
        $p1 = $this->insertPatient($fx['clinic'], 'w1');
        $apptBefore = $this->appointmentCount($fx['clinic']);
        $res = $this->walkIn($fx, $p1, $fx['c2'], $fx['locA']);
        self::assertSame(200, $res->get_status(), 'W1: reception walk-in answers — ' . $this->errCode($res));
        $data = $this->payload($res);
        self::assertSame('waiting', (string) ($data['visit']['status'] ?? ''), 'W1: the intended reception operation reaches waiting');
        self::assertTrue((bool) ($data['walk_in']['complete'] ?? false), 'W1: complete=true');
        self::assertSame(1, $this->visitCount($p1), 'W1: exactly one walk-in Visit');
        $visit = $this->latestVisit($p1);
        self::assertSame('walk_in', (string) $visit['source'], 'W1: source=walk_in');
        self::assertNull($visit['appointment_id'], 'W1: no appointment bound');
        self::assertSame($fx['clinic'], (int) $visit['clinic_id'], 'W1: trusted Clinic');
        self::assertSame($fx['locA'], (int) $visit['location_id'], 'W1: trusted Location');
        self::assertSame($fx['c2'], (int) $visit['clinician_id'], 'W1: selected doctor');
        self::assertSame(self::TODAY, (string) $visit['visit_date'], 'W1: Location-local operational day');
        self::assertSame($apptBefore, $this->appointmentCount($fx['clinic']), 'W1: no appointment created');

        // The patient appears in the existing Reception queue read.
        $board = $this->dispatch('GET', self::BOARD, [], $this->scopeHeaders($fx['clinic'], $fx['locA']));
        self::assertSame(200, $board->get_status(), 'W1: board answers');
        $queueIds = array_map(static fn($r): int => (int) ($r['id'] ?? 0), (array) ($this->payload($board)['queue'] ?? []));
        self::assertContains((int) $visit['id'], $queueIds, 'W1: walk-in Visit appears in the existing waiting queue');
    }

    // ============ W2 — Access ============

    public function testW2_AccessRoleCapabilityNonceMembership(): void
    {
        $fx = $this->stage('w2');
        $p = $this->insertPatient($fx['clinic'], 'w2');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);

        // A. Doctor (not Reception role; no check-in capability).
        wp_set_current_user($fx['doc1']);
        $this->assertDenied($this->dispatch('GET', self::CLINICIANS, [], $headers), 'W2 doctor clinicians');
        $this->assertDenied($this->walkIn($fx, $p, $fx['c1'], $fx['locA']), 'W2 doctor walk-in');

        // B. Accountant with ACTIVE membership — membership alone is not permission.
        $acct = $this->makeUser('qa_w2_acct', RolesAndCapabilities::ROLE_ACCOUNTANT);
        $this->seedMembership($acct, $fx['clinic'], 'cpms_accountant');
        wp_set_current_user($acct);
        $this->assertDenied($this->dispatch('GET', self::CLINICIANS, [], $headers), 'W2 accountant clinicians');
        $this->assertDenied($this->walkIn($fx, $p, $fx['c1'], $fx['locA']), 'W2 accountant walk-in');

        // C. Secretary with clinic-scoped check-in DENY.
        $mid = $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        App::membership_service()->set_capability($mid, RolesAndCapabilities::QUEUE_CHECKIN, 'deny');
        wp_set_current_user($fx['secretary']);
        $this->assertDenied($this->dispatch('GET', self::CLINICIANS, [], $headers), 'W2 checkin-deny clinicians');
        $this->assertDenied($this->walkIn($fx, $p, $fx['c1'], $fx['locA']), 'W2 checkin-deny walk-in');
        App::membership_service()->remove_capability($mid, RolesAndCapabilities::QUEUE_CHECKIN);

        // D. Secretary with clinic-scoped queue-advance DENY (enqueue required).
        App::membership_service()->set_capability($mid, RolesAndCapabilities::QUEUE_ADVANCE, 'deny');
        $this->assertDenied($this->walkIn($fx, $p, $fx['c1'], $fx['locA']), 'W2 advance-deny walk-in');
        App::membership_service()->remove_capability($mid, RolesAndCapabilities::QUEUE_ADVANCE);

        // E. Missing nonce.
        $noNonce = $this->dispatch('POST', self::WALK_IN, ['patient_id' => $p, 'clinician_id' => $fx['c1']], $headers, false);
        self::assertSame(403, $noNonce->get_status(), 'W2: missing nonce rejected');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($noNonce), 'W2: nonce denial code');

        // F. Suspended membership.
        App::membership_service()->suspend_membership($mid);
        $this->assertDenied($this->dispatch('GET', self::CLINICIANS, [], $headers), 'W2 suspended clinicians');
        $this->assertDenied($this->walkIn($fx, $p, $fx['c1'], $fx['locA']), 'W2 suspended walk-in');

        // G. Secretary without membership; anonymous.
        $loner = $this->makeUser('qa_w2_loner', RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($loner);
        $this->assertDenied($this->walkIn($fx, $p, $fx['c1'], $fx['locA']), 'W2 no-membership walk-in');
        wp_set_current_user(0);
        $anon = $this->walkIn($fx, $p, $fx['c1'], $fx['locA']);
        self::assertContains($anon->get_status(), [401, 403], 'W2: anonymous denied');

        self::assertSame(0, $this->visitCount($p), 'W2: no denied actor created a Visit');

        // Positive control within the same stage: the authorized secretary succeeds.
        App::membership_service()->reactivate_membership($mid);
        wp_set_current_user($fx['secretary']);
        $ok = $this->walkIn($fx, $p, $fx['c1'], $fx['locA']);
        self::assertSame(200, $ok->get_status(), 'W2: authorized secretary succeeds — ' . $this->errCode($ok));
        self::assertSame(1, $this->visitCount($p), 'W2: exactly one Visit for the authorized actor');
    }

    // ============ W3 — Location policy ============

    public function testW3_LocationPolicyZeroOneManyForeignInactiveUnassigned(): void
    {
        // A. 0 eligible Locations ⇒ fail closed.
        $zero = $this->stage('w3z');
        $this->seedMembership($zero['secretary'], $zero['clinic'], 'cpms_secretary');
        foreach (['locA', 'locB', 'locC'] as $k) {
            $this->deactivateLocation($zero[$k]);
        }
        wp_set_current_user($zero['secretary']);
        $pz = $this->insertPatient($zero['clinic'], 'w3z');
        $zl = $this->dispatch('GET', self::CLINICIANS, [], $this->scopeHeaders($zero['clinic']));
        self::assertSame(200, $zl->get_status(), 'W3: zero-eligible clinicians read answers fail-closed — ' . $this->errCode($zl));
        self::assertNull($this->payload($zl)['location_id'] ?? null, 'W3: zero eligible ⇒ no Location resolved');
        self::assertSame([], $this->clinicianIds($zl), 'W3: zero eligible ⇒ no doctors');
        $zw = $this->dispatch('POST', self::WALK_IN, ['patient_id' => $pz, 'clinician_id' => $zero['c1']], $this->scopeHeaders($zero['clinic']));
        self::assertSame(403, $zw->get_status(), 'W3: zero eligible ⇒ walk-in fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($zw), 'W3: zero-eligible envelope');
        self::assertSame(0, $this->visitCount($pz), 'W3: zero eligible ⇒ no Visit');

        // B. N>1 without explicit Location ⇒ REQUIRED (no first-Location fallback).
        $many = $this->stage('w3n');
        $mid = $this->seedMembership($many['secretary'], $many['clinic'], 'cpms_secretary');
        wp_set_current_user($many['secretary']);
        $pn = $this->insertPatient($many['clinic'], 'w3n');
        $nl = $this->dispatch('GET', self::CLINICIANS, [], $this->scopeHeaders($many['clinic']));
        self::assertSame(400, $nl->get_status(), 'W3: N>1 clinicians without Location rejected');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($nl), 'W3: N>1 envelope');
        self::assertSame('location_required', (string) ($this->errorData($nl)['reason'] ?? ''), 'W3: location_required reason');
        $nw = $this->dispatch('POST', self::WALK_IN, ['patient_id' => $pn, 'clinician_id' => $many['c1']], $this->scopeHeaders($many['clinic']));
        self::assertSame(400, $nw->get_status(), 'W3: N>1 walk-in without Location rejected');
        self::assertSame(0, $this->visitCount($pn), 'W3: N>1 without Location ⇒ no Visit');

        // C. Foreign Location (other Clinic) and inactive Location.
        $fw = $this->walkIn($many, $pn, $many['c1'], $many['locF']);
        self::assertSame(403, $fw->get_status(), 'W3: foreign Location rejected');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($fw), 'W3: foreign Location envelope');
        $this->deactivateLocation($many['locC']);
        $iw = $this->walkIn($many, $pn, $many['c1'], $many['locC']);
        self::assertSame(403, $iw->get_status(), 'W3: inactive Location rejected');
        self::assertSame(0, $this->visitCount($pn), 'W3: foreign/inactive Location ⇒ no Visit');

        // D. Location-scoped secretary: unassigned Location rejected; the ONE
        // eligible Location auto-resolves without a selector.
        App::membership_service()->set_scope_mode($mid, 'location', [$many['locB']]);
        $uw = $this->walkIn($many, $pn, $many['c2'], $many['locA']);
        self::assertSame(403, $uw->get_status(), 'W3: unassigned Location rejected');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($uw), 'W3: unassigned Location envelope');
        $ol = $this->dispatch('GET', self::CLINICIANS, [], $this->scopeHeaders($many['clinic']));
        self::assertSame(200, $ol->get_status(), 'W3: single eligible Location auto-resolves for clinicians — ' . $this->errCode($ol));
        self::assertSame($many['locB'], (int) ($this->payload($ol)['location_id'] ?? 0), 'W3: auto-resolved Location = B');
        self::assertSame([$many['c2']], $this->clinicianIds($ol), 'W3: Location B doctors only');
        $ow = $this->dispatch('POST', self::WALK_IN, ['patient_id' => $pn, 'clinician_id' => $many['c2']], $this->scopeHeaders($many['clinic']));
        self::assertSame(200, $ow->get_status(), 'W3: single eligible Location walk-in succeeds — ' . $this->errCode($ow));
        self::assertSame($many['locB'], (int) $this->latestVisit($pn)['location_id'], 'W3: Visit lands in the auto-resolved Location');
    }

    // ============ W4 — Clinician eligibility ============

    public function testW4_ClinicianEligibilityParticipationAndLocationAssignment(): void
    {
        $fx = $this->stage('w4');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        // N>1 at A: only active participants durably assigned to A.
        $a = $this->dispatch('GET', self::CLINICIANS, [], $this->scopeHeaders($fx['clinic'], $fx['locA']));
        self::assertSame(200, $a->get_status(), 'W4: clinicians A — ' . $this->errCode($a));
        $ids = $this->clinicianIds($a);
        self::assertSame($this->sorted([$fx['c1'], $fx['c2']]), $ids, 'W4: Location A = {c1, c2}');
        self::assertNotContains($fx['c3'], $ids, 'W4: home-Clinic metadata without membership is not authorization');
        self::assertNotContains($fx['c4'], $ids, 'W4: inactive professional identity excluded');
        self::assertNotContains($fx['c5'], $ids, 'W4: foreign-Clinic doctor excluded');
        self::assertNotContains($fx['c6'], $ids, 'W4: suspended participation excluded');
        self::assertNotContains($fx['c7'], $ids, 'W4: participation scoped to another Location excluded');

        // 1 at B: c2 (home Clinic is FOREIGN — participation comes from membership).
        $b = $this->dispatch('GET', self::CLINICIANS, [], $this->scopeHeaders($fx['clinic'], $fx['locB']));
        self::assertSame([$fx['c2']], $this->clinicianIds($b), 'W4: Location B = {c2}; c1 (assigned to A only) unavailable at B');

        // 0 at C.
        $c = $this->dispatch('GET', self::CLINICIANS, [], $this->scopeHeaders($fx['clinic'], $fx['locC']));
        self::assertSame(200, $c->get_status(), 'W4: clinicians C answers');
        self::assertSame([], $this->clinicianIds($c), 'W4: Location C = 0 eligible doctors');

        // Raw/foreign clinician selectors are rejected before any Visit.
        $p = $this->insertPatient($fx['clinic'], 'w4');
        foreach (
            [
                'home-decoy'      => [$fx['c3'], $fx['locA']],
                'inactive'        => [$fx['c4'], $fx['locA']],
                'foreign'         => [$fx['c5'], $fx['locA']],
                'suspended'       => [$fx['c6'], $fx['locA']],
                'other-location'  => [$fx['c7'], $fx['locA']],
                'A-doctor-at-B'   => [$fx['c1'], $fx['locB']],
                'nonexistent'     => [999999, $fx['locA']],
            ] as $label => [$cid, $loc]
        ) {
            $res = $this->walkIn($fx, $p, $cid, $loc);
            self::assertSame(404, $res->get_status(), 'W4: ' . $label . ' clinician selector rejected — ' . $this->errCode($res));
            self::assertSame('CLINIC_NOT_FOUND', $this->errCode($res), 'W4: ' . $label . ' non-enumerating envelope');
        }
        self::assertSame(0, $this->visitCount($p), 'W4: no rejected selector created a Visit');
    }

    // ============ W5 — Patient ============

    public function testW5_PatientBelongsToTrustedClinicLocationIsNotOwnership(): void
    {
        $fx = $this->stage('w5');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        $foreign = $this->insertPatient($fx['clinicF'], 'w5f');
        $archived = $this->insertPatient($fx['clinic'], 'w5a', 'archived');
        foreach (['foreign' => $foreign, 'archived' => $archived, 'nonexistent' => 999999] as $label => $pid) {
            $res = $this->walkIn($fx, $pid, $fx['c1'], $fx['locA']);
            self::assertSame(404, $res->get_status(), 'W5: ' . $label . ' patient rejected — ' . $this->errCode($res));
            self::assertSame('CLINIC_NOT_FOUND', $this->errCode($res), 'W5: ' . $label . ' non-enumerating envelope');
        }
        $zero = $this->walkIn($fx, 0, $fx['c1'], $fx['locA']);
        self::assertContains($zero->get_status(), [400, 422], 'W5: invalid patient selector rejected');
        self::assertSame(0, $this->visitCount($foreign) + $this->visitCount($archived), 'W5: no Visit for rejected patients');

        $p = $this->insertPatient($fx['clinic'], 'w5ok');
        $before = $this->patientRow($p);
        $ok = $this->walkIn($fx, $p, $fx['c1'], $fx['locA']);
        self::assertSame(200, $ok->get_status(), 'W5: trusted-Clinic patient walk-in — ' . $this->errCode($ok));
        $after = $this->patientRow($p);
        self::assertArrayNotHasKey('location_id', $after, 'W5: patients table carries no Location ownership');
        self::assertSame($before, $after, 'W5: walk-in does not modify the patient identity row');
        self::assertSame($fx['clinic'], (int) $after['clinic_id'], 'W5: patient remains Clinic-level');
    }

    // ============ W6 — Existing machine, history, audit ============

    public function testW6_ExistingMachineAutoEnqueueOnAndOff(): void
    {
        $fx = $this->stage('w6');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        // Auto-enqueue ON (default): the established walkIn transaction produces waiting.
        $p1 = $this->insertPatient($fx['clinic'], 'w6on');
        $on = $this->walkIn($fx, $p1, $fx['c1'], $fx['locA']);
        self::assertSame(200, $on->get_status(), 'W6: auto-enqueue ON walk-in — ' . $this->errCode($on));
        $v1 = $this->latestVisit($p1);
        self::assertSame('waiting', (string) $v1['status'], 'W6: ON ⇒ waiting');
        self::assertSame([['', 'checked_in'], ['checked_in', 'waiting']], $this->historyPairs((int) $v1['id']), 'W6: ON history = create_walk_in then enqueue');
        self::assertGreaterThanOrEqual(1, $this->auditCount('VISIT_WALK_IN', (int) $v1['id']), 'W6: established VISIT_WALK_IN audit');

        // Auto-enqueue OFF: walkIn legitimately returns checked_in; the
        // Reception operation runs the EXISTING enqueue transition.
        $this->setAutoEnqueue($fx['clinic'], false);
        $p2 = $this->insertPatient($fx['clinic'], 'w6off');
        $off = $this->walkIn($fx, $p2, $fx['c2'], $fx['locA']);
        self::assertSame(200, $off->get_status(), 'W6: auto-enqueue OFF walk-in — ' . $this->errCode($off));
        $v2 = $this->latestVisit($p2);
        self::assertSame('waiting', (string) $v2['status'], 'W6: OFF ⇒ existing enqueue reaches waiting');
        self::assertSame([['', 'checked_in'], ['checked_in', 'waiting']], $this->historyPairs((int) $v2['id']), 'W6: OFF history = create_walk_in then enqueue (no new status)');
        self::assertSame(1, $this->visitCount($p2), 'W6: one Visit');
        self::assertGreaterThanOrEqual(1, $this->auditCount('VISIT_WALK_IN', (int) $v2['id']), 'W6: audit on OFF path');
    }

    // ============ W7 — Partial checked_in + server-derived retry ============

    public function testW7_PartialCheckedInIsTruthfulAndRetryRecoversWithoutDuplicate(): void
    {
        $fx = $this->stage('w7');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $this->setAutoEnqueue($fx['clinic'], false);

        // A decoy checked_in walk-in of ANOTHER patient (same doctor/Location).
        $decoyPatient = $this->insertPatient($fx['clinic'], 'w7decoy');
        $this->withSabotagedEnqueue(fn() => $this->walkIn($fx, $decoyPatient, $fx['c1'], $fx['locA']));
        $decoy = $this->latestVisit($decoyPatient);
        self::assertSame('checked_in', (string) $decoy['status'], 'W7-pre: decoy is a real checked_in walk-in');

        $p = $this->insertPatient($fx['clinic'], 'w7');
        $res = $this->withSabotagedEnqueue(fn() => $this->walkIn($fx, $p, $fx['c1'], $fx['locA']));
        self::assertGreaterThanOrEqual(400, $res->get_status(), 'W7: partial must not claim success — got ' . $res->get_status());
        self::assertSame('CLINIC_WALK_IN_INCOMPLETE', $this->errCode($res), 'W7: partial has its own non-generic code');
        $err = $this->errorData($res);
        self::assertFalse((bool) ($err['walk_in']['complete'] ?? true), 'W7: complete=false');
        self::assertSame('failed', (string) ($err['walk_in']['enqueue'] ?? ''), 'W7: enqueue stage failed');
        self::assertSame('checked_in', (string) ($err['visit_status'] ?? ''), 'W7: durable truth = checked_in');
        self::assertSame(1, $this->visitCount($p), 'W7: exactly one Visit after the partial');
        $visit = $this->latestVisit($p);
        self::assertSame('checked_in', (string) $visit['status'], 'W7: durable checked_in');

        // Retry with a HOSTILE client visit_id (the decoy): never authority.
        $retry = $this->walkIn($fx, $p, $fx['c1'], $fx['locA'], ['visit_id' => (int) $decoy['id']]);
        self::assertSame(200, $retry->get_status(), 'W7: retry recovers — ' . $this->errCode($retry));
        self::assertSame((int) $visit['id'], (int) ($this->payload($retry)['visit']['id'] ?? 0), 'W7: retry derived the existing Visit server-side');
        self::assertSame('existing', (string) ($this->payload($retry)['walk_in']['created'] ?? ''), 'W7: retry reports the existing Visit (no new create)');
        self::assertSame(1, $this->visitCount($p), 'W7: retry created no duplicate Visit');
        self::assertSame('waiting', (string) $this->latestVisit($p)['status'], 'W7: retry reached waiting');
        self::assertSame([['', 'checked_in'], ['checked_in', 'waiting']], $this->historyPairs((int) $visit['id']), 'W7: retry ran ONLY the existing enqueue transition');
        self::assertSame('checked_in', (string) $this->latestVisit($decoyPatient)['status'], 'W7: the client visit_id did not hijack the decoy');
    }

    // ============ W8 — Non-recoverable states fail closed ============

    public function testW8_NonRecoverableExistingStatesFailClosed(): void
    {
        $fx = $this->stage('w8');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $this->setAutoEnqueue($fx['clinic'], false);

        // A. checked_in walk-in at Location B; retry at Location A must NOT
        // enqueue it (trusted Location mismatch) and must NOT create another.
        $p = $this->insertPatient($fx['clinic'], 'w8loc');
        $this->withSabotagedEnqueue(fn() => $this->walkIn($fx, $p, $fx['c2'], $fx['locB']));
        $atB = $this->latestVisit($p);
        self::assertSame('checked_in', (string) $atB['status'], 'W8-pre: checked_in at B');
        $cross = $this->walkIn($fx, $p, $fx['c2'], $fx['locA']);
        self::assertSame(409, $cross->get_status(), 'W8: cross-Location recovery refused — ' . $this->errCode($cross));
        self::assertSame('CLINIC_DUPLICATE_ACTIVE_VISIT', $this->errCode($cross), 'W8: established duplicate envelope');
        self::assertSame(1, $this->visitCount($p), 'W8: no second Visit');
        self::assertSame('checked_in', (string) $this->latestVisit($p)['status'], 'W8: B Visit untouched');

        // B. checked_in SCHEDULED Visit (partial arrival) for the same doctor:
        // a walk-in never enqueues or converts it.
        $ps = $this->insertPatient($fx['clinic'], 'w8sched');
        $slot = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], self::TODAY, '12:00:00');
        $appt = $this->insertAppointment($fx['clinic'], $fx['locA'], $ps, $fx['c1'], $slot, self::TODAY, '12:00:00');
        $this->withSabotagedEnqueue(fn() => $this->dispatch('POST', self::ARRIVALS, ['patient_id' => $ps, 'appointment_id' => $appt], $this->scopeHeaders($fx['clinic'], $fx['locA'])));
        $sched = $this->latestVisit($ps);
        self::assertSame('checked_in', (string) $sched['status'], 'W8-pre: scheduled Visit checked_in');
        $sw = $this->walkIn($fx, $ps, $fx['c1'], $fx['locA']);
        self::assertSame(409, $sw->get_status(), 'W8: scheduled checked_in Visit is not a walk-in recovery target');
        self::assertSame('checked_in', (string) $this->latestVisit($ps)['status'], 'W8: scheduled Visit untouched');
        self::assertSame(1, $this->visitCount($ps), 'W8: no walk-in Visit beside the scheduled one');

        // C. Already waiting ⇒ bounded conflict, no re-enqueue, no new Visit.
        $this->setAutoEnqueue($fx['clinic'], true);
        $pw = $this->insertPatient($fx['clinic'], 'w8wait');
        self::assertSame(200, $this->walkIn($fx, $pw, $fx['c1'], $fx['locA'])->get_status(), 'W8-pre: waiting walk-in');
        $again = $this->walkIn($fx, $pw, $fx['c1'], $fx['locA']);
        self::assertSame(409, $again->get_status(), 'W8: waiting Visit ⇒ conflict');
        self::assertSame('waiting', (string) ($this->errorData($again)['visit_status'] ?? ''), 'W8: bounded existing-state');
        self::assertSame(1, $this->visitCount($pw), 'W8: no new Visit');
        self::assertCount(2, $this->historyPairs((int) $this->latestVisit($pw)['id']), 'W8: no re-enqueue history row');
    }

    // ============ W9 — Duplicate preserved; no appointment conversion ============

    public function testW9_DuplicateActiveVisitPreservedAndNoAppointmentConversion(): void
    {
        $fx = $this->stage('w9');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        // Same patient + doctor + operational day active Visit, even across Location.
        $p = $this->insertPatient($fx['clinic'], 'w9');
        self::assertSame(200, $this->walkIn($fx, $p, $fx['c2'], $fx['locB'])->get_status(), 'W9-pre: walk-in at B');
        $dup = $this->walkIn($fx, $p, $fx['c2'], $fx['locA']);
        self::assertSame(409, $dup->get_status(), 'W9: established duplicate across Location preserved');
        self::assertSame('CLINIC_DUPLICATE_ACTIVE_VISIT', $this->errCode($dup), 'W9: duplicate code');
        self::assertSame(1, $this->visitCount($p), 'W9: no second active Visit');

        // A booked appointment without a Visit is NOT silently converted.
        $pa = $this->insertPatient($fx['clinic'], 'w9appt');
        $slot = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], self::TODAY, '13:00:00');
        $appt = $this->insertAppointment($fx['clinic'], $fx['locA'], $pa, $fx['c1'], $slot, self::TODAY, '13:00:00');
        $apptBefore = $this->appointmentCount($fx['clinic']);
        $res = $this->walkIn($fx, $pa, $fx['c1'], $fx['locA']);
        self::assertSame(200, $res->get_status(), 'W9: walk-in with an unrelated booked appointment — ' . $this->errCode($res));
        $v = $this->latestVisit($pa);
        self::assertNull($v['appointment_id'], 'W9: walk-in is not bound to the appointment');
        $row = App::db()->fetchRow('SELECT status, active_visit_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d', [$appt]);
        self::assertSame('confirmed', (string) $row['status'], 'W9: appointment state untouched');
        self::assertEmpty($row['active_visit_id'], 'W9: appointment not linked to the walk-in');
        self::assertSame($apptBefore, $this->appointmentCount($fx['clinic']), 'W9: no appointment created');
    }

    // ============ W10 — Privacy ============

    public function testW10_BoundedClinicianAndWalkInPayloads(): void
    {
        $fx = $this->stage('w10');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        $list = $this->dispatch('GET', self::CLINICIANS, [], $this->scopeHeaders($fx['clinic'], $fx['locA']));
        self::assertSame(200, $list->get_status(), 'W10: clinicians answers — ' . $this->errCode($list));
        $rows = (array) ($this->payload($list)['clinicians'] ?? []);
        self::assertNotEmpty($rows, 'W10: rows present');
        foreach ($rows as $row) {
            $keys = array_keys((array) $row);
            sort($keys);
            self::assertSame(self::CLINICIAN_KEYS, $keys, 'W10: clinician option carries only id/name');
        }

        $p = $this->insertPatient($fx['clinic'], 'w10', 'active', '0499370899');
        $res = $this->walkIn($fx, $p, $fx['c1'], $fx['locA']);
        self::assertSame(200, $res->get_status(), 'W10: walk-in answers — ' . $this->errCode($res));
        $data = $this->payload($res);
        $top = array_keys($data);
        sort($top);
        self::assertSame(['visit', 'walk_in'], $top, 'W10: bounded top-level envelope');
        $vk = array_keys((array) $data['visit']);
        sort($vk);
        self::assertSame(self::VISIT_KEYS, $vk, 'W10: bounded queue presentation of the walk-in Visit');
        $json = (string) wp_json_encode([$this->payload($list), $data]);
        foreach (['national_id', '0499370899', 'mobile', 'wp_user_id', 'email', 'note', 'diagnosis', 'prescription', 'file', 'private', 'room', 'specialty', 'birth_date', 'address'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'W10: payload must not expose "' . $needle . '"');
        }
    }

    // ============ W11 — Slices 1–3 intact (CONTROL) ============

    public function testW11_Slices123RemainIntact(): void
    {
        $fx = $this->stage('w11');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);

        $board = $this->dispatch('GET', self::BOARD, [], $headers);
        self::assertSame(200, $board->get_status(), 'W11: Slice 1 board answers');
        $pid = $this->insertPatient($fx['clinic'], 'w11');
        $search = $this->dispatch('GET', self::SEARCH, ['q' => 'Walkin'], $headers);
        self::assertSame(200, $search->get_status(), 'W11: Slice 2 search answers');
        $found = array_map(static fn($r): int => (int) ($r['id'] ?? 0), (array) $this->payload($search));
        self::assertContains($pid, $found, 'W11: Slice 2 search finds the Clinic patient');
        $create = $this->dispatch('POST', self::CREATE, ['first_name' => 'Slice', 'last_name' => 'Three', 'mobile' => '09121119911'], $headers);
        self::assertSame(200, $create->get_status(), 'W11: Slice 3 create answers — ' . $this->errCode($create));
        self::assertSame(0, $this->visitCount((int) ($this->payload($create)['id'] ?? 0)), 'W11: create starts no Visit');
        $html = $this->renderReception($fx['secretary']);
        self::assertStringContainsString('data-role="sr-search"', $html, 'W11: search panel remains');
        self::assertStringContainsString('data-role="sr-create"', $html, 'W11: create form remains');
        self::assertStringContainsString('data-role="sr-appointments"', $html, 'W11: arrival board remains');
    }

    // ================= helpers (TEST-ONLY) =================

    /**
     * Stage: Clinic A (Locations A, B, C — all Asia/Tehran) + foreign Clinic F.
     *   c1: home A, ACTIVE membership A, assigned {A}            ⇒ eligible at A
     *   c2: home F (!), ACTIVE membership A, assigned {A, B}     ⇒ eligible at A, B
     *   c3: home A, NO membership, raw assignment {A}             ⇒ never (home ≠ auth)
     *   c4: home A, INACTIVE identity, membership A, {A}          ⇒ never
     *   c5: home F, membership F only, assigned {F}               ⇒ never in A
     *   c6: home A, SUSPENDED membership A, {A}                   ⇒ never
     *   c7: home A, membership A scoped to Location B, {A, C}     ⇒ never
     *
     * @return array<string, int>
     */
    private function stage(string $tag): array
    {
        $org = $this->insertOrg('WI Org ' . $tag);
        $clinic = $this->insertClinic('WI Clinic ' . $tag, $org);
        $clinicF = $this->insertClinic('WI Foreign ' . $tag, $org);
        $locA = $this->insertLocation($clinic, 'WI A ' . $tag, 1);
        $locB = $this->insertLocation($clinic, 'WI B ' . $tag, 0);
        $locC = $this->insertLocation($clinic, 'WI C ' . $tag, 0);
        $locF = $this->insertLocation($clinicF, 'WI F ' . $tag, 1);
        $secretary = $this->makeUser('qa_' . $tag . '_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $ms = App::membership_service();

        $doc1 = $this->makeUser('qa_' . $tag . '_d1', RolesAndCapabilities::ROLE_DOCTOR);
        $c1 = $this->insertClinician('Dr One ' . $tag, $clinic, 1, $doc1);
        $this->seedMembership($doc1, $clinic, 'cpms_doctor');
        $ms->assign_clinician_locations($c1, [$locA], $locA);

        $doc2 = $this->makeUser('qa_' . $tag . '_d2', RolesAndCapabilities::ROLE_DOCTOR);
        $c2 = $this->insertClinician('Dr Two ' . $tag, $clinicF, 1, $doc2);
        $this->seedMembership($doc2, $clinic, 'cpms_doctor');
        $ms->assign_clinician_locations($c2, [$locA, $locB], $locA);

        $doc3 = $this->makeUser('qa_' . $tag . '_d3', RolesAndCapabilities::ROLE_DOCTOR);
        $c3 = $this->insertClinician('Dr Home ' . $tag, $clinic, 1, $doc3);
        $this->rawClinicianLocation($c3, $locA);

        $doc4 = $this->makeUser('qa_' . $tag . '_d4', RolesAndCapabilities::ROLE_DOCTOR);
        $c4 = $this->insertClinician('Dr Inactive ' . $tag, $clinic, 0, $doc4);
        $this->seedMembership($doc4, $clinic, 'cpms_doctor');
        $this->rawClinicianLocation($c4, $locA);

        $doc5 = $this->makeUser('qa_' . $tag . '_d5', RolesAndCapabilities::ROLE_DOCTOR);
        $c5 = $this->insertClinician('Dr Foreign ' . $tag, $clinicF, 1, $doc5);
        $this->seedMembership($doc5, $clinicF, 'cpms_doctor');
        $ms->assign_clinician_locations($c5, [$locF], $locF);

        $doc6 = $this->makeUser('qa_' . $tag . '_d6', RolesAndCapabilities::ROLE_DOCTOR);
        $c6 = $this->insertClinician('Dr Suspended ' . $tag, $clinic, 1, $doc6);
        $m6 = $this->seedMembership($doc6, $clinic, 'cpms_doctor');
        $this->rawClinicianLocation($c6, $locA);
        $ms->suspend_membership($m6);

        $doc7 = $this->makeUser('qa_' . $tag . '_d7', RolesAndCapabilities::ROLE_DOCTOR);
        $c7 = $this->insertClinician('Dr Scoped ' . $tag, $clinic, 1, $doc7);
        $m7 = $this->seedMembership($doc7, $clinic, 'cpms_doctor');
        $ms->set_scope_mode($m7, 'location', [$locB]);
        $this->rawClinicianLocation($c7, $locA);
        $this->rawClinicianLocation($c7, $locC);

        return [
            'clinic' => $clinic, 'clinicF' => $clinicF,
            'locA' => $locA, 'locB' => $locB, 'locC' => $locC, 'locF' => $locF,
            'secretary' => $secretary, 'doc1' => $doc1,
            'c1' => $c1, 'c2' => $c2, 'c3' => $c3, 'c4' => $c4, 'c5' => $c5, 'c6' => $c6, 'c7' => $c7,
        ];
    }

    /**
     * @param array<string, int> $fx
     * @param array<string, mixed> $extra
     */
    private function walkIn(array $fx, int $patientId, int $clinicianId, int $locationId, array $extra = []): WP_REST_Response
    {
        return $this->dispatch('POST', self::WALK_IN, array_merge([
            'patient_id'   => $patientId,
            'clinician_id' => $clinicianId,
        ], $extra), $this->scopeHeaders($fx['clinic'], $locationId));
    }

    private function assertDenied(WP_REST_Response $res, string $label): void
    {
        self::assertContains($res->get_status(), [401, 403], $label . ': denied — got ' . $res->get_status() . '/' . $this->errCode($res));
        self::assertNotSame('rest_no_route', $this->errCode($res), $label . ': the Reception route must exist (denial, not missing route)');
    }

    /**
     * TEST-ONLY failure injection (same technique as the Slice 1 partial-arrival
     * regression): make EXACTLY the enqueue UPDATE (cpms_visits → 'waiting')
     * throw via the existing WordPress `query` filter. No production hook.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withSabotagedEnqueue(callable $fn)
    {
        $sabotage = static function (string $q): string {
            if (preg_match('/^\s*UPDATE\b/i', $q) && false !== strpos($q, 'cpms_visits') && false !== strpos($q, "'waiting'")) {
                throw new \RuntimeException('test-only enqueue sabotage');
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
        (new Settings(App::db(), $clinicId))->set('queue.auto_enqueue', $on);
        Settings::flushCache();
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

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = [], bool $withNonce = true): WP_REST_Response
    {
        ScopeContext::clear();
        App::resetScope();
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
        if ($b instanceof \WP_Error) {
            return (string) $b->get_error_code();
        }
        return (string) (is_array($b) ? ($b['code'] ?? '') : '');
    }

    /**
     * @return list<int>
     */
    private function clinicianIds(WP_REST_Response $res): array
    {
        if ($res->get_status() !== 200) {
            return [-1];
        }
        $ids = [];
        foreach ((array) ($this->payload($res)['clinicians'] ?? []) as $row) {
            $ids[] = (int) ((array) $row)['id'];
        }
        sort($ids);
        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }

    private function visitCount(int $patientId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d', [$patientId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function latestVisit(int $patientId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d ORDER BY id DESC LIMIT 1', [$patientId]);
        self::assertIsArray($row, 'visit row must exist for patient ' . $patientId);
        return $row;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function historyPairs(int $visitId): array
    {
        $rows = App::db()->fetchAll('SELECT from_status, to_status FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d ORDER BY id ASC', [$visitId]);
        $out = [];
        foreach ((is_array($rows) ? $rows : []) as $r) {
            $out[] = [(string) ($r['from_status'] ?? ''), (string) $r['to_status']];
        }
        return $out;
    }

    private function auditCount(string $action, int $resourceId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_id = %d', [$action, $resourceId]);
    }

    private function appointmentCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE clinic_id = %d', [$clinicId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function patientRow(int $patientId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d LIMIT 1', [$patientId]);
        return is_array($row) ? $row : [];
    }

    private function deactivateLocation(int $locationId): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'cpms_locations', ['is_active' => 0], ['id' => $locationId]);
    }

    private function rawClinicianLocation(int $clinicianId, int $locationId): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'cpms_clinician_locations', [
            'clinician_id' => $clinicianId,
            'location_id'  => $locationId,
            'is_primary'   => 0,
            'created_at'   => App::db()->nowUtcSql(),
        ]);
        self::assertNotFalse($ok, 'clinician_location fixture insert: ' . $wpdb->last_error);
    }

    private function renderReception(int $userId): string
    {
        wp_set_current_user($userId);
        $url = StaffPortalShell::portal_url();
        self::assertNotEmpty($url, 'the canonical Staff Portal page exists');
        $path = (string) (wp_parse_url($url, \PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, \PHP_URL_QUERY) ?? '');
        $this->go_to($path . ($query !== '' ? '?' . $query . '&' : '?') . 'cpms-module=reception');
        $baseline = get_stylesheet_directory() . '/page.php';
        if (!is_readable($baseline)) {
            $baseline = get_stylesheet_directory() . '/index.php';
        }
        $tpl = (string) apply_filters('template_include', $baseline);
        self::assertNotSame($baseline, $tpl, 'template_include must intercept with the plugin-owned Staff Portal template');
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

    private function insertClinic(string $name, int $orgId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', $orgId, $name, 'cl-' . bin2hex(random_bytes(3)), self::TZ, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        App::resetScope();
        return $id;
    }

    private function insertLocation(int $clinicId, string $name, int $primary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', $clinicId, $name, 'loc-' . bin2hex(random_bytes(3)), self::TZ, $primary, $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertClinician(string $name, int $clinicId, int $active, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s)', $clinicId, $name, $wpUserId, $active, $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag, string $status = 'active', ?string $nationalId = null): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-WI-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2));
        $ok = $wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id'   => $clinicId,
            'mrn'         => $mrn,
            'first_name'  => 'Walkin',
            'last_name'   => 'Patient ' . $tag,
            'mobile'      => '0912' . sprintf('%07d', random_int(1000000, 9999999)),
            'national_id' => $nationalId,
            'status'      => $status,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        self::assertNotFalse($ok, 'patient fixture insert: ' . $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function insertSlot(int $clinicId, int $locId, int $clinicianId, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)', $clinicId, $locId, $clinicianId, $date, $time, 20, 1, 1, 0, 1, 'manual', $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertAppointment(int $clinicId, int $locId, int $patientId, int $clinicianId, int $slotId, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s, %s)', $clinicId, $locId, 'wi-' . bin2hex(random_bytes(3)), $patientId, $clinicianId, $slotId, 0, $date, $time, 20, $time, 'confirmed', 0, $now, $now, $now));
        return (int) $wpdb->insert_id;
    }
}
