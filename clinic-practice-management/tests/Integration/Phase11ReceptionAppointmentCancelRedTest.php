<?php
/**
 * Phase 11 (bounded slice) — Staff Portal Reception: Cancel a Booked
 * Appointment at the Trusted operational Location — TEST-ONLY RED.
 *
 * Slice: inside the EXISTING Staff Portal Reception module an authorized
 * secretary works in the already-trusted Clinic + operational Location, sees a
 * currently actionable booked appointment, explicitly chooses cancel, may
 * supply an OPTIONAL bounded reason, and the cancellation is delegated to the
 * EXISTING staff cancellation contract (`BookingService::cancelByStaff()`, the
 * same service behind `POST /clinic/v1/appointments/{id}/cancel` →
 * `BookingController::cancel`): existing transition, existing slot release,
 * existing audit, existing notification/reminder-cancel behaviour, existing
 * active-Visit guard. No Visit, no reschedule, no no-show, no finance, no
 * second state machine.
 *
 * The ONE Reception-specific addition is the fail-closed Location boundary:
 * `BookingService::assertAppointmentWithinExplicitScope()` proves the trusted
 * CLINIC only. Reception must also prove the appointment's Location equals the
 * current trusted operational Location, otherwise a same-Clinic cross-Location
 * appointment would be mutable from the wrong Location.
 *
 * Live reconstruction (verified, not assumed — 2026-09-28):
 * - authoritative main = origin/main = bbfc9f946f598bf649bfe8b77797a5d0943e65b0;
 *   open PRs = 0; latest migration = 2026_09_26_0023_handwriting_prescription_paper.php
 *   (this RED adds/reserves no migration).
 * - Phase 11 = STARTED / IN PROGRESS — NOT CLOSED; Slice 1 Arrival Board (#133),
 *   Slice 2 search (#135), Slice 3 patient create (#136), Slice 4 walk-in (#137),
 *   Slice 5 appointment create (#138) verified in live code.
 * - established staff cancel contract (reused, never duplicated):
 *   POST /clinic/v1/appointments/{id}/cancel → BookingController::cancel
 *   (cancelPermission: nonce + patient role OR global cpms_appt_cancel; staff
 *   branch adds clinic-scoped requireClinicPermission cpms_appt_cancel) →
 *   BookingService::cancelByStaff(actor, appointmentId, reason) → cancel(...,'staff'):
 *   license OP_APPOINTMENT_CANCEL; row lock findForUpdate; Clinic ownership via
 *   assertAppointmentWithinExplicitScope; AppointmentMachine cancel transition
 *   (pending|confirmed → cancelled_by_staff for secretary/doctor); I-3
 *   assertNoActiveVisit (HAS_ACTIVE_VISIT / 409); optional reason stored as
 *   mb_substr(reason, 0, 255) (empty ⇒ NULL); APPOINTMENT_CANCELLED audit;
 *   SlotRepository::releaseBooking(slot_id) exactly once (guarded by
 *   booked_count > 0); Notifications::cancelQueuedForAppointment(apt:{id});
 *   existing patient notification for a confirmed appointment; bounded
 *   {appointment_id, status} result. Slot release is NOT idempotent as a second
 *   decrement because the transition itself is refused after the first success
 *   (CLINIC_INVALID_TRANSITION / 409).
 *
 * The RED fails ONLY because the Reception cancel boundary + UI are missing:
 * bootstrap, migrations and fixtures succeed, the intended Reception cancel
 * route answers with the canonical missing-route fingerprint (`rest_no_route`)
 * and the rendered module has no cancel surface. Everything this slice leans on
 * is proven GREEN on this very head by the C7 CONTROL: the shared established
 * staff cancel route (`POST /clinic/v1/appointments/{id}/cancel`) cancels a
 * confirmed appointment for the same secretary in the same fixture, and
 * Slices 1–5 (board, search, patient create, eligibility + walk-in, appointment
 * create) stay intact.
 *
 * TEST GROUP MAP:
 *  C1  Surface + explicit cancel of a confirmed booked row .......... INTENDED RED
 *  C2  Access: role / capability / nonce / membership .............. INTENDED RED
 *  C3  Trusted Clinic + trusted Location; cross-Location fails closed INTENDED RED
 *  C4  Pending, optional/empty reason, truncation, repeat, slots .... INTENDED RED
 *  C5  Active Visit honesty (existing HAS_ACTIVE_VISIT) .............. INTENDED RED
 *  C6  Board disappearance + arrival flow intact .................... INTENDED RED
 *  C7  Slices 1–5 + shared staff cancel intact ..................... CONTROL (pass)
 *
 * Excluded by design: reschedule, no-show, cancelled tab/history, appointment
 * details modal, bulk cancel, finance/payment, clinical/notes/prescriptions,
 * new role/capability/status/machine, migrations, second cancellation
 * implementation, client-side-only security.
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

final class Phase11ReceptionAppointmentCancelRedTest extends WP_UnitTestCase
{
    private const CANCEL      = '/clinic/v1/staff/portal/reception/appointments/';
    private const SHARED      = '/clinic/v1/appointments/';
    private const BOARD       = '/clinic/v1/staff/portal/reception/board';
    private const ARRIVALS    = '/clinic/v1/staff/portal/reception/arrivals';
    private const CLINICIANS  = '/clinic/v1/staff/portal/reception/clinicians';
    private const SEARCH      = '/clinic/v1/staff/portal/reception/patients/search';
    private const PCREATE     = '/clinic/v1/staff/portal/reception/patients';
    private const WALK_IN     = '/clinic/v1/staff/portal/reception/walk-ins';
    private const SLOTS       = '/clinic/v1/staff/portal/reception/slots';
    private const APPTS       = '/clinic/v1/staff/portal/reception/appointments';

    private const TZ = 'Asia/Tehran';

    /** The ONLY keys the Reception cancel response may carry (no PHI, no internals). */
    private const CANCEL_KEYS = ['appointment', 'reception'];

    /** The ONLY keys the bounded cancelled-appointment object may carry. */
    private const CANCEL_APPT_KEYS = ['appointment_id', 'status'];

    /** Static Reception cancel-surface markers (this slice's UI). */
    private const CANCEL_MARKERS = [
        'sr-cancel-open',
        'sr-cancel-form',
        'sr-cancel-reason',
        'sr-cancel-confirm',
        'sr-cancel-abort',
    ];

    /** Surfaces this slice must NOT introduce. */
    private const FORBIDDEN_MARKERS = [
        'sr-reschedule',
        'sr-no-show',
        'sr-cancel-history',
        'sr-cancel-bulk',
        'sr-appt-details',
    ];

    /** Live statuses the existing cancellation service treats as an active Visit. */
    private const LIVE_VISIT_STATUSES = ['checked_in', 'waiting', 'called', 'in_consultation', 'consultation_completed', 'awaiting_payment', 'paid'];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        VisitService::setTestNowUtc(null);
        App::migrations()->migrate();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        $_GET     = [];
        $_POST    = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        VisitService::setTestNowUtc(null);
        parent::tearDown();
    }

    // ============ C1 — Surface + explicit cancel of a confirmed booked row ============

    public function testC1_ConfirmedBookedRowIsCancelledThroughReception(): void
    {
        $fx      = $this->stage('c1');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);
        $today   = $this->localDate(self::TZ);
        $time    = $this->futureTime(self::TZ);

        // UI — Reception carries the compact cancel surface inside the booked board.
        $html = $this->renderReception($fx['secretary']);
        self::assertStringContainsString('data-role="reception-app"', $html, 'C1: reception module renders');
        self::assertStringContainsString('data-role="sr-appointments"', $html, 'C1: Slice 1 board remains');
        foreach (self::CANCEL_MARKERS as $role) {
            self::assertStringContainsString('data-role="' . $role . '"', $html, 'C1: cancel surface marker ' . $role);
        }
        self::assertStringContainsString('/staff/portal/reception/appointments/', $html, 'C1: UI posts to the Reception cancel boundary');
        self::assertStringContainsString('/cancel', $html, 'C1: UI uses the cancel action path');
        foreach (self::FORBIDDEN_MARKERS as $role) {
            self::assertStringNotContainsString('data-role="' . $role . '"', $html, 'C1: out-of-scope surface ' . $role . ' must not exist');
        }

        // The booked row is on today's actionable board for the trusted Location.
        $patient = $this->insertPatient($fx['clinic'], 'c1');
        $slot    = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, $time, 1, ['booked' => 1]);
        $appt    = $this->insertAppointment($fx['clinic'], $fx['locA'], $patient, $fx['c1'], $slot, $today, $time, 'confirmed');
        self::assertContains($appt, $this->boardAppointmentIds($this->dispatch('GET', self::BOARD, [], $headers)), 'C1: the booked row is actionable on the board');

        // REST — explicit cancel with an optional reason.
        $res = $this->cancel($fx, $appt, $fx['locA'], 'لغو توسط منشی پذیرش');
        self::assertSame(200, $res->get_status(), 'C1: reception cancel answers — ' . $this->errCode($res));
        $data = $this->payload($res);
        self::assertSame(self::CANCEL_KEYS, $this->sortedKeys($data), 'C1: bounded cancel payload');
        self::assertSame(self::CANCEL_APPT_KEYS, $this->sortedKeys((array) ($data['appointment'] ?? [])), 'C1: bounded cancelled-appointment object');
        self::assertSame($appt, (int) ($data['appointment']['appointment_id'] ?? 0), 'C1: the cancelled appointment id');
        self::assertSame('cancelled_by_staff', (string) ($data['appointment']['status'] ?? ''), 'C1: existing staff-cancel terminal state');
        self::assertSame($fx['clinic'], (int) ($data['reception']['clinic_id'] ?? 0), 'C1: trusted Clinic echoed from server truth');
        self::assertSame($fx['locA'], (int) ($data['reception']['location_id'] ?? 0), 'C1: trusted operational Location echoed from server truth');

        // Persisted truth — existing service semantics, nothing re-implemented here.
        $row = $this->appointmentRow($appt);
        self::assertSame('cancelled_by_staff', (string) $row['status'], 'C1: appointment transitioned by the existing machine');
        self::assertSame('لغو توسط منشی پذیرش', (string) $row['cancel_reason'], 'C1: bounded reason persisted by the existing service');
        self::assertSame($fx['secretary'], (int) $row['cancelled_by_wp_user_id'], 'C1: actor recorded by the existing service');
        self::assertSame(0, (int) $this->slotRow($slot)['booked_count'], 'C1: existing slot release');
        self::assertSame(1, $this->auditCount('APPOINTMENT_CANCELLED', $appt), 'C1: existing APPOINTMENT_CANCELLED audit');
        self::assertSame(0, $this->visitCount($patient), 'C1: NO Visit created');
        self::assertSame(0, $this->visitCountForAppointment($appt), 'C1: NO Visit bound to the cancelled appointment');
        self::assertSame(0, $this->queueCount($fx['clinic']), 'C1: NO queue entry created');

        // Privacy — nothing clinical / unrelated in the bounded response.
        $flat = strtolower((string) wp_json_encode($data));
        foreach (['mobile', 'national_id', 'mrn', 'note', 'prescription', 'invoice', 'payment', 'file', 'storage_path', 'clinician_id', 'patient_id'] as $needle) {
            self::assertStringNotContainsString($needle, $flat, 'C1: cancel response must not expose ' . $needle);
        }

        // Board — the cancelled row leaves the actionable board naturally.
        self::assertNotContains($appt, $this->boardAppointmentIds($this->dispatch('GET', self::BOARD, [], $headers)), 'C1: cancelled row leaves the actionable board');
    }

    // ============ C2 — Access ============

    public function testC2_AccessRoleCapabilityNonceMembership(): void
    {
        $fx      = $this->stage('c2');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);
        $today   = $this->localDate(self::TZ);

        $mk = function (string $tag) use ($fx, $today): array {
            $patient = $this->insertPatient($fx['clinic'], 'c2' . $tag);
            $slot    = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, $this->uniqueTime($fx['clinic']), 1, ['booked' => 1]);
            $appt    = $this->insertAppointment($fx['clinic'], $fx['locA'], $patient, $fx['c1'], $slot, $today, $this->slotTime($slot), 'confirmed');

            return [$patient, $slot, $appt];
        };

        // A. Doctor (not the Reception role) — capability alone is not access.
        [, $slotA, $apptA] = $mk('a');
        wp_set_current_user($fx['doc1']);
        $this->assertDenied($this->cancel($fx, $apptA, $fx['locA']), 'C2 doctor cancel');
        self::assertSame('confirmed', (string) $this->appointmentRow($apptA)['status'], 'C2: doctor denial mutates nothing');

        // B. Accountant with ACTIVE membership — membership alone is not permission.
        $acct = $this->makeUser('qa_c2_acct', RolesAndCapabilities::ROLE_ACCOUNTANT);
        $this->seedMembership($acct, $fx['clinic'], 'cpms_accountant');
        wp_set_current_user($acct);
        $this->assertDenied($this->cancel($fx, $apptA, $fx['locA']), 'C2 accountant cancel');
        self::assertSame('confirmed', (string) $this->appointmentRow($apptA)['status'], 'C2: accountant denial mutates nothing');

        // C. Secretary with a clinic-scoped cpms_appt_cancel DENY (explicit deny wins).
        $mid = $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        App::membership_service()->set_capability($mid, RolesAndCapabilities::APPT_CANCEL, 'deny');
        wp_set_current_user($fx['secretary']);
        $this->assertDenied($this->cancel($fx, $apptA, $fx['locA']), 'C2 appt-cancel-deny');
        self::assertSame('confirmed', (string) $this->appointmentRow($apptA)['status'], 'C2: capability denial mutates nothing');
        self::assertSame(1, (int) $this->slotRow($slotA)['booked_count'], 'C2: capability denial releases no slot');
        App::membership_service()->remove_capability($mid, RolesAndCapabilities::APPT_CANCEL);

        // D. Missing nonce (CSRF).
        $noNonce = $this->dispatch('POST', self::CANCEL . $apptA . '/cancel', ['reason' => 'بدون nonce'], $headers, false);
        self::assertSame(403, $noNonce->get_status(), 'C2: cancel without nonce rejected');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($noNonce), 'C2: nonce denial code');

        // E. Suspended membership.
        App::membership_service()->suspend_membership($mid);
        $this->assertDenied($this->cancel($fx, $apptA, $fx['locA']), 'C2 suspended membership');

        // F. Secretary without membership; anonymous.
        $loner = $this->makeUser('qa_c2_loner', RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($loner);
        $this->assertDenied($this->cancel($fx, $apptA, $fx['locA']), 'C2 no-membership');
        wp_set_current_user(0);
        self::assertContains($this->cancel($fx, $apptA, $fx['locA'])->get_status(), [401, 403], 'C2: anonymous denied');

        self::assertSame('confirmed', (string) $this->appointmentRow($apptA)['status'], 'C2: no denied actor cancelled the appointment');
        self::assertSame(1, (int) $this->slotRow($slotA)['booked_count'], 'C2: no denied actor released the slot');
        self::assertSame(0, $this->auditCount('APPOINTMENT_CANCELLED', $apptA), 'C2: no denied actor produced a cancellation audit');

        // Positive control in the same stage: the authorized secretary succeeds.
        App::membership_service()->reactivate_membership($mid);
        wp_set_current_user($fx['secretary']);
        $ok = $this->cancel($fx, $apptA, $fx['locA']);
        self::assertSame(200, $ok->get_status(), 'C2: authorized secretary cancels — ' . $this->errCode($ok));
        self::assertSame('cancelled_by_staff', (string) $this->appointmentRow($apptA)['status'], 'C2: authorized cancellation persisted');
    }

    // ============ C3 — Trusted Clinic + trusted Location ============

    public function testC3_TrustedClinicAndLocationFailClosed(): void
    {
        $today = $this->localDate(self::TZ);

        // A. Same-Clinic appointment at ANOTHER Location must fail closed
        //    (the shared service's Clinic-only check is NOT sufficient).
        $fx = $this->stage('c3a');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $pA    = $this->insertPatient($fx['clinic'], 'c3a');
        $slotB = $this->insertSlot($fx['clinic'], $fx['locB'], $fx['c1'], $today, '09:15:00', 1, ['booked' => 1]);
        $apptB = $this->insertAppointment($fx['clinic'], $fx['locB'], $pA, $fx['c1'], $slotB, $today, '09:15:00', 'confirmed');

        $blocked = $this->cancel($fx, $apptB, $fx['locA'], 'محل اشتباه');
        self::assertSame(404, $blocked->get_status(), 'C3: same-Clinic cross-Location cancel rejected — ' . $this->errCode($blocked));
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($blocked), 'C3: canonical non-enumerating fingerprint');
        self::assertSame('confirmed', (string) $this->appointmentRow($apptB)['status'], 'C3: cross-Location rejection mutates nothing');
        self::assertSame(1, (int) $this->slotRow($slotB)['booked_count'], 'C3: cross-Location rejection releases no slot');
        self::assertSame(0, $this->auditCount('APPOINTMENT_CANCELLED', $apptB), 'C3: cross-Location rejection audits no cancellation');

        // The very same appointment IS cancellable when Reception is scoped to
        // its own trusted operational Location (the rejection was Location, not
        // an impossibility).
        $sameLoc = $this->cancel($fx, $apptB, $fx['locB']);
        self::assertSame(200, $sameLoc->get_status(), 'C3: the appointment is cancellable at its own Location — ' . $this->errCode($sameLoc));
        self::assertSame('cancelled_by_staff', (string) $this->appointmentRow($apptB)['status'], 'C3: same-Location cancellation persisted');

        // B. Foreign-Clinic appointment: identical non-enumerating fingerprint.
        $foreignPatient = $this->insertPatient($fx['clinicF'], 'c3f');
        $foreignLoc     = $this->insertLocation($fx['clinicF'], 'C3 Foreign Loc', 1);
        $foreignSlot    = $this->insertSlot($fx['clinicF'], $foreignLoc, $fx['c5'], $today, '10:15:00', 1, ['booked' => 1]);
        $foreignAppt    = $this->insertAppointment($fx['clinicF'], $foreignLoc, $foreignPatient, $fx['c5'], $foreignSlot, $today, '10:15:00', 'confirmed');

        $foreign = $this->cancel($fx, $foreignAppt, $fx['locA'], 'کلینیک دیگر');
        self::assertSame(404, $foreign->get_status(), 'C3: foreign-Clinic cancel rejected — ' . $this->errCode($foreign));
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($foreign), 'C3: identical fingerprint for foreign Clinic (no enumeration)');
        self::assertSame('confirmed', (string) $this->appointmentRow($foreignAppt)['status'], 'C3: foreign-Clinic rejection mutates nothing');
        self::assertSame(1, (int) $this->slotRow($foreignSlot)['booked_count'], 'C3: foreign-Clinic rejection releases no foreign slot');

        // C. Raw selectors cannot manufacture authority: body clinic_id /
        //    location_id pointing at the foreign / other Location change nothing.
        $pC    = $this->insertPatient($fx['clinic'], 'c3c');
        $slotC = $this->insertSlot($fx['clinic'], $fx['locC'], $fx['c1'], $today, '11:15:00', 1, ['booked' => 1]);
        $apptC = $this->insertAppointment($fx['clinic'], $fx['locC'], $pC, $fx['c1'], $slotC, $today, '11:15:00', 'confirmed');
        $raw   = $this->dispatch('POST', self::CANCEL . $apptC . '/cancel', [
            'reason'      => 'جعل محدوده',
            'clinic_id'   => $fx['clinic'],
            'location_id' => $fx['locC'],
        ], $this->scopeHeaders($fx['clinic'], $fx['locA']));
        self::assertSame(404, $raw->get_status(), 'C3: raw payload selectors are not authority — ' . $this->errCode($raw));
        self::assertSame('confirmed', (string) $this->appointmentRow($apptC)['status'], 'C3: raw selectors cancelled nothing');

        // D. Foreign / unassigned Location selector on the request scope fails closed.
        $fx2 = $this->stage('c3d');
        $this->seedMembership($fx2['secretary'], $fx2['clinic'], 'cpms_secretary');
        wp_set_current_user($fx2['secretary']);
        $p4     = $this->insertPatient($fx2['clinic'], 'c3d');
        $slot4  = $this->insertSlot($fx2['clinic'], $fx2['locA'], $fx2['c1'], $today, '12:15:00', 1, ['booked' => 1]);
        $appt4  = $this->insertAppointment($fx2['clinic'], $fx2['locA'], $p4, $fx2['c1'], $slot4, $today, '12:15:00', 'confirmed');
        $denied = $this->cancel($fx2, $appt4, $fx2['locF'], 'موقعیت بیگانه');
        self::assertContains($denied->get_status(), [400, 403], 'C3: foreign Location scope fails closed — ' . $this->errCode($denied));
        self::assertSame('confirmed', (string) $this->appointmentRow($appt4)['status'], 'C3: foreign Location scope cancelled nothing');
        self::assertSame(1, (int) $this->slotRow($slot4)['booked_count'], 'C3: foreign Location scope released no slot');
    }

    // ============ C4 — Cancel semantics of the existing service ============

    public function testC4_PendingEmptyReasonTruncationRepeatAndSlotRelease(): void
    {
        $fx = $this->stage('c4');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $today = $this->localDate(self::TZ);

        // A. pending is cancellable by the established machine (T3) and an
        //    EMPTY reason is valid (the reason stays optional).
        $pA    = $this->insertPatient($fx['clinic'], 'c4a');
        $slotA = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, '13:15:00', 1, ['booked' => 1]);
        $apptA = $this->insertAppointment($fx['clinic'], $fx['locA'], $pA, $fx['c1'], $slotA, $today, '13:15:00', 'pending');
        $empty = $this->cancel($fx, $apptA, $fx['locA'], '');
        self::assertSame(200, $empty->get_status(), 'C4: pending + empty reason cancels — ' . $this->errCode($empty));
        self::assertSame('cancelled_by_staff', (string) $this->appointmentRow($apptA)['status'], 'C4: pending cancellation persisted');
        self::assertNull($this->appointmentRow($apptA)['cancel_reason'], 'C4: empty reason stays NULL (optional, not mandatory)');
        self::assertSame(0, (int) $this->slotRow($slotA)['booked_count'], 'C4: slot released once');

        // B. Supplied reason follows the EXISTING contract (mb_substr 255).
        $long  = str_repeat('ا', 300);
        $pB    = $this->insertPatient($fx['clinic'], 'c4b');
        $slotB = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, '14:15:00', 1, ['booked' => 1]);
        $apptB = $this->insertAppointment($fx['clinic'], $fx['locA'], $pB, $fx['c1'], $slotB, $today, '14:15:00', 'confirmed');
        $resB  = $this->cancel($fx, $apptB, $fx['locA'], $long);
        self::assertSame(200, $resB->get_status(), 'C4: long supplied reason cancels — ' . $this->errCode($resB));
        self::assertSame(255, mb_strlen((string) $this->appointmentRow($apptB)['cancel_reason']), 'C4: existing truncation contract preserved');

        // C. Repeat after success follows the existing bounded state behaviour
        //    and releases the slot only once.
        $repeat = $this->cancel($fx, $apptB, $fx['locA'], 'تکرار');
        self::assertSame(409, $repeat->get_status(), 'C4: repeat cancel rejected — ' . $this->errCode($repeat));
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($repeat), 'C4: existing bounded conflict/state envelope');
        self::assertSame('cancelled_by_staff', (string) $this->appointmentRow($apptB)['status'], 'C4: repeat mutates nothing');
        self::assertSame(0, (int) $this->slotRow($slotB)['booked_count'], 'C4: slot released exactly once (never twice)');
        self::assertSame(1, $this->auditCount('APPOINTMENT_CANCELLED', $apptB), 'C4: exactly one cancellation audit');

        // D. Unknown appointment id: canonical non-enumerating not-found.
        $missing = $this->cancel($fx, 987654321, $fx['locA'], 'ناموجود');
        self::assertSame(404, $missing->get_status(), 'C4: unknown id rejected — ' . $this->errCode($missing));
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($missing), 'C4: canonical not-found fingerprint');

        // E. Notification / audit behaviour stays the EXISTING service's:
        //    the Reception route and the shared staff route produce the same
        //    observable notification and audit outcome for the same operation.
        $pR    = $this->insertPatient($fx['clinic'], 'c4r');
        $slotR = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, '15:15:00', 1, ['booked' => 1]);
        $apptR = $this->insertAppointment($fx['clinic'], $fx['locA'], $pR, $fx['c1'], $slotR, $today, '15:15:00', 'confirmed');
        $pS    = $this->insertPatient($fx['clinic'], 'c4s');
        $slotS = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, '16:15:00', 1, ['booked' => 1]);
        $apptS = $this->insertAppointment($fx['clinic'], $fx['locA'], $pS, $fx['c1'], $slotS, $today, '16:15:00', 'confirmed');

        $notifBefore = $this->notificationCount($fx['clinic']);
        $viaReception = $this->cancel($fx, $apptR, $fx['locA'], 'از پذیرش');
        self::assertSame(200, $viaReception->get_status(), 'C4: reception path cancels — ' . $this->errCode($viaReception));
        $receptionDelta = $this->notificationCount($fx['clinic']) - $notifBefore;

        $viaShared = $this->dispatch('POST', self::SHARED . $apptS . '/cancel', ['reason' => 'از مسیر مشترک'], $this->scopeHeaders($fx['clinic'], $fx['locA']));
        self::assertSame(200, $viaShared->get_status(), 'C4: shared staff cancel control — ' . $this->errCode($viaShared));
        $sharedDelta = $this->notificationCount($fx['clinic']) - $notifBefore - $receptionDelta;

        self::assertSame($sharedDelta, $receptionDelta, 'C4: notification behaviour is exactly the existing service');
        self::assertSame(1, $this->auditCount('APPOINTMENT_CANCELLED', $apptR), 'C4: reception path audits through the existing service');
        self::assertSame(1, $this->auditCount('APPOINTMENT_CANCELLED', $apptS), 'C4: shared path audits through the existing service');
        self::assertSame('cancelled_by_staff', (string) $this->appointmentRow($apptS)['status'], 'C4: shared control terminal state');

        // F. No Visit is created anywhere in this stage.
        self::assertSame(0, $this->visitCount($pA) + $this->visitCount($pB) + $this->visitCount($pR) + $this->visitCount($pS), 'C4: NO Visit created by any cancellation');
        self::assertSame(0, $this->queueCount($fx['clinic']), 'C4: NO queue entry created by any cancellation');
    }

    // ============ C5 — Active Visit honesty ============

    public function testC5_ActiveVisitRejectionStaysHonestAndReleasesNothing(): void
    {
        $fx = $this->stage('c5');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);
        $today   = $this->localDate(self::TZ);
        $time    = $this->futureTime(self::TZ);

        $patient = $this->insertPatient($fx['clinic'], 'c5');
        $slot    = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, $time, 1, ['booked' => 1]);
        $appt    = $this->insertAppointment($fx['clinic'], $fx['locA'], $patient, $fx['c1'], $slot, $today, $time, 'confirmed');
        $visit   = $this->insertLiveVisit($fx['clinic'], $fx['locA'], $fx['c1'], $patient, $appt, $today);

        $res = $this->cancel($fx, $appt, $fx['locA'], 'در حین ویزیت');
        self::assertSame(409, $res->get_status(), 'C5: active Visit blocks cancellation — ' . $this->errCode($res));
        self::assertSame('HAS_ACTIVE_VISIT', $this->errCode($res), 'C5: existing bounded error code (not re-implemented here)');
        self::assertSame('confirmed', (string) $this->appointmentRow($appt)['status'], 'C5: rejection mutates no appointment state');
        self::assertSame(1, (int) $this->slotRow($slot)['booked_count'], 'C5: slot stays claimed — released at most once, never on rejection');
        self::assertSame(0, $this->auditCount('APPOINTMENT_CANCELLED', $appt), 'C5: no cancellation audit on rejection');
        self::assertSame('waiting', (string) $this->visitRow($visit)['status'], 'C5: the live Visit is untouched');
        self::assertContains($appt, $this->boardAppointmentIds($this->dispatch('GET', self::BOARD, [], $headers)), 'C5: the row remains on the actionable board (no false success)');
    }

    // ============ C6 — Board disappearance + arrival flow intact ============

    public function testC6_CancelledRowLeavesBoardAndArrivalStillWorks(): void
    {
        $fx = $this->stage('c6');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);
        $nowUtc  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        VisitService::setTestNowUtc($nowUtc);
        $localNow = $nowUtc->setTimezone(new \DateTimeZone(self::TZ));
        $today    = $localNow->format('Y-m-d');
        $arrival  = $localNow->modify('+2 minutes');
        if ($arrival->format('Y-m-d') !== $today) {
            // Keep the appointment on this operational day and within the
            // existing grace window when the test starts near local midnight.
            $arrival = $localNow->modify('-2 minutes');
        }
        $arrivalTime = $arrival->format('H:i:00');
        $cancelTime  = $arrival->modify('-1 minute')->format('H:i:00');

        $pCancel = $this->insertPatient($fx['clinic'], 'c6cancel');
        $slotC   = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, $cancelTime, 1, ['booked' => 1]);
        $apptC   = $this->insertAppointment($fx['clinic'], $fx['locA'], $pCancel, $fx['c1'], $slotC, $today, $cancelTime, 'confirmed');

        $pArrive = $this->insertPatient($fx['clinic'], 'c6arrive');
        $slotK   = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, $arrivalTime, 1, ['booked' => 1]);
        $apptK   = $this->insertAppointment($fx['clinic'], $fx['locA'], $pArrive, $fx['c1'], $slotK, $today, $arrivalTime, 'confirmed');

        $before = $this->boardAppointmentIds($this->dispatch('GET', self::BOARD, [], $headers));
        self::assertContains($apptC, $before, 'C6: both rows start on the board');
        self::assertContains($apptK, $before, 'C6: both rows start on the board');

        self::assertSame(200, $this->cancel($fx, $apptC, $fx['locA'])->get_status(), 'C6: cancel succeeds');

        $this->dispatch('GET', self::BOARD, [], $headers); // existing board refresh
        $after = $this->boardAppointmentIds($this->dispatch('GET', self::BOARD, [], $headers));
        self::assertNotContains($apptC, $after, 'C6: cancelled row disappears from the actionable board');
        self::assertContains($apptK, $after, 'C6: the untouched row remains actionable');

        // Existing arrival flow on another row stays functional.
        $arr = $this->dispatch('POST', self::ARRIVALS, ['patient_id' => $pArrive, 'appointment_id' => $apptK], $headers);
        self::assertSame(200, $arr->get_status(), 'C6: arrival still works on another row — ' . $this->errCode($arr));
        self::assertSame('waiting', (string) ($this->payload($arr)['visit']['status'] ?? ''), 'C6: arrival reaches waiting');
        self::assertSame(0, $this->visitCount($pCancel), 'C6: the cancelled patient still has NO Visit');

        $final = $this->dispatch('GET', self::BOARD, [], $headers);
        self::assertNotContains($apptC, $this->boardAppointmentIds($final), 'C6: cancelled row stays absent');
        // The received row stays visible with its EXISTING read-only queue state
        // and is no longer a booked-not-received row (no arrival/cancel action).
        self::assertContains($apptK, $this->boardAppointmentIds($final), 'C6: the received row keeps its existing board visibility');
        self::assertSame('waiting', (string) ($this->boardRow($final, $apptK)['visit_status'] ?? ''), 'C6: existing read-only queue state on the board');
    }

    // ============ C7 — Slices 1–5 + shared staff cancel intact (CONTROL) ============

    public function testC7_Slices12345AndSharedStaffCancelRemainIntact(): void
    {
        $fx = $this->stage('c7');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);
        $today   = $this->localDate(self::TZ);
        $time    = $this->futureTime(self::TZ);

        // Slice 1 — Arrival Board: a booked row is received into the queue.
        $p1 = $this->insertPatient($fx['clinic'], 'c7board');
        $s1 = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, $time);
        $a1 = $this->insertAppointment($fx['clinic'], $fx['locA'], $p1, $fx['c1'], $s1, $today, $time, 'confirmed');
        $board = $this->dispatch('GET', self::BOARD, [], $headers);
        self::assertSame(200, $board->get_status(), 'C7: the Slice 1 board answers');
        self::assertContains($a1, $this->boardAppointmentIds($board), 'C7: the board lists the booked row');
        $arr = $this->dispatch('POST', self::ARRIVALS, ['patient_id' => $p1, 'appointment_id' => $a1], $headers);
        self::assertSame(200, $arr->get_status(), 'C7: the Slice 1 arrival answers — ' . $this->errCode($arr));
        self::assertSame('waiting', (string) ($this->payload($arr)['visit']['status'] ?? ''), 'C7: arrival reaches waiting');

        // Slice 2 — read-only Clinic patient search.
        $search = $this->dispatch('GET', self::SEARCH, ['q' => 'c7board'], $headers);
        self::assertSame(200, $search->get_status(), 'C7: the Slice 2 search answers');
        self::assertContains($p1, array_map(static fn($r): int => (int) ($r['id'] ?? 0), (array) $this->payload($search)), 'C7: Slice 2 finds the Clinic patient');

        // Slice 3 — bounded Clinic patient create.
        $create = $this->dispatch('POST', self::PCREATE, [
            'first_name' => 'Slice',
            'last_name'  => 'Cancel',
            'mobile'     => '0914' . sprintf('%07d', random_int(1000000, 9999999)),
        ], $headers);
        self::assertSame(200, $create->get_status(), 'C7: the Slice 3 create answers — ' . $this->errCode($create));
        self::assertSame(0, $this->visitCount((int) ($this->payload($create)['id'] ?? 0)), 'C7: Slice 3 create starts no Visit');

        // Slice 4 — eligible doctors + walk-in.
        self::assertSame($this->sorted([$fx['c1'], $fx['c2']]), $this->clinicianIds($this->dispatch('GET', self::CLINICIANS, [], $headers)), 'C7: Slice 4 eligibility intact');
        $p4 = $this->insertPatient($fx['clinic'], 'c7wi');
        $wi = $this->dispatch('POST', self::WALK_IN, ['patient_id' => $p4, 'clinician_id' => $fx['c2']], $headers);
        self::assertSame(200, $wi->get_status(), 'C7: the Slice 4 walk-in answers — ' . $this->errCode($wi));
        self::assertSame('waiting', (string) ($this->payload($wi)['visit']['status'] ?? ''), 'C7: walk-in reaches waiting');
        self::assertSame(0, $this->appointmentCountForPatient($p4), 'C7: walk-in still creates no appointment');

        // Slice 5 — appointment create from an already-generated persisted slot.
        $tomorrow = $this->localDate(self::TZ, '+1 day');
        $slot5    = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '09:00:00', 2);
        $read5    = $this->dispatch('GET', self::SLOTS, ['clinician_id' => $fx['c1'], 'date' => $tomorrow], $headers);
        self::assertSame(200, $read5->get_status(), 'C7: the Slice 5 slot read answers — ' . $this->errCode($read5));
        self::assertContains($slot5, $this->slotIds($read5), 'C7: the persisted free slot is offered');
        $p5    = $this->insertPatient($fx['clinic'], 'c7book');
        $book5 = $this->dispatch('POST', self::APPTS, [
            'patient_id'   => $p5,
            'clinician_id' => $fx['c1'],
            'slot_id'      => $slot5,
        ], $headers);
        self::assertSame(200, $book5->get_status(), 'C7: the Slice 5 create answers — ' . $this->errCode($book5));
        self::assertSame('confirmed', (string) ($this->payload($book5)['appointment']['status'] ?? ''), 'C7: Slice 5 produces one confirmed appointment');

        // SHARED established staff cancel route (the very service this slice
        // must delegate to): answers 200 for this secretary in this fixture, so
        // bootstrap, migrations, fixtures, the license gate, the Appointment
        // machine, the active-Visit guard, slot release and
        // `BookingService::cancelByStaff` itself all work on this head.
        $ctrlPatient = $this->insertPatient($fx['clinic'], 'c7ctl');
        $ctrlSlot    = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '10:30:00', 1, ['booked' => 1]);
        $ctrlAppt    = $this->insertAppointment($fx['clinic'], $fx['locA'], $ctrlPatient, $fx['c1'], $ctrlSlot, $tomorrow, '10:30:00', 'confirmed');
        $shared      = $this->dispatch('POST', self::SHARED . $ctrlAppt . '/cancel', ['reason' => 'کنترل مسیر مشترک'], $headers);
        self::assertSame(200, $shared->get_status(), 'C7: shared established staff cancel reachable — ' . $this->errCode($shared));
        $sharedView = $this->payload($shared);
        self::assertSame(['appointment_id', 'status'], $this->sortedKeys($sharedView), 'C7: established bounded cancel representation');
        self::assertSame('cancelled_by_staff', (string) $sharedView['status'], 'C7: established staff cancel terminal state');
        self::assertSame(0, (int) $this->slotRow($ctrlSlot)['booked_count'], 'C7: the shared service releases the slot');
        self::assertSame(0, $this->visitCount($ctrlPatient), 'C7: the shared cancel creates no Visit');

        // UI — every delivered Reception surface remains in the module.
        $html = $this->renderReception($fx['secretary']);
        foreach (['sr-appointments', 'sr-queue', 'sr-search', 'sr-create', 'sr-walkin', 'sr-book', 'sr-location-select'] as $role) {
            self::assertStringContainsString('data-role="' . $role . '"', $html, 'C7: delivered surface ' . $role . ' remains');
        }
    }

    // ================= helpers (TEST-ONLY) =================

    /**
     * Stage: Clinic A (Locations A, B, C — all Asia/Tehran) + foreign Clinic F.
     *   c1: home A, ACTIVE membership A, assigned {A}            ⇒ eligible at A
     *   c2: home F (!), ACTIVE membership A, assigned {A, B}     ⇒ eligible at A, B
     *   c5: home F, membership F only, assigned {F}              ⇒ never in A
     *
     * @return array<string, int>
     */
    private function stage(string $tag): array
    {
        $org     = $this->insertOrg('AC Org ' . $tag);
        $clinic  = $this->insertClinic('AC Clinic ' . $tag, $org);
        $clinicF = $this->insertClinic('AC Foreign ' . $tag, $org);
        $locA    = $this->insertLocation($clinic, 'AC A ' . $tag, 1);
        $locB    = $this->insertLocation($clinic, 'AC B ' . $tag, 0);
        $locC    = $this->insertLocation($clinic, 'AC C ' . $tag, 0);
        $locF    = $this->insertLocation($clinicF, 'AC F ' . $tag, 1);

        $secretary = $this->makeUser('qa_' . $tag . '_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $ms        = App::membership_service();

        $doc1 = $this->makeUser('qa_' . $tag . '_d1', RolesAndCapabilities::ROLE_DOCTOR);
        $c1   = $this->insertClinician('Dr One ' . $tag, $clinic, 1, $doc1);
        $this->seedMembership($doc1, $clinic, 'cpms_doctor');
        $ms->assign_clinician_locations($c1, [$locA], $locA);

        $doc2 = $this->makeUser('qa_' . $tag . '_d2', RolesAndCapabilities::ROLE_DOCTOR);
        $c2   = $this->insertClinician('Dr Two ' . $tag, $clinicF, 1, $doc2);
        $this->seedMembership($doc2, $clinic, 'cpms_doctor');
        $ms->assign_clinician_locations($c2, [$locA, $locB], $locA);

        $doc5 = $this->makeUser('qa_' . $tag . '_d5', RolesAndCapabilities::ROLE_DOCTOR);
        $c5   = $this->insertClinician('Dr Foreign ' . $tag, $clinicF, 1, $doc5);
        $this->seedMembership($doc5, $clinicF, 'cpms_doctor');
        $ms->assign_clinician_locations($c5, [$locF], $locF);

        App::resetScope();

        return [
            'clinic'    => $clinic,
            'clinicF'   => $clinicF,
            'locA'      => $locA,
            'locB'      => $locB,
            'locC'      => $locC,
            'locF'      => $locF,
            'secretary' => $secretary,
            'doc1'      => $doc1,
            'c1'        => $c1,
            'c2'        => $c2,
            'c5'        => $c5,
        ];
    }

    /**
     * @param array<string, int> $fx
     */
    private function cancel(array $fx, int $appointmentId, ?int $locationId, string $reason = ''): WP_REST_Response
    {
        $params = ['reason' => $reason];

        return $this->dispatch('POST', self::CANCEL . $appointmentId . '/cancel', $params, $this->scopeHeaders($fx['clinic'], $locationId));
    }

    private function assertDenied(WP_REST_Response $res, string $label): void
    {
        self::assertContains($res->get_status(), [401, 403], $label . ': denied — got ' . $res->get_status() . '/' . $this->errCode($res));
        self::assertNotSame('rest_no_route', $this->errCode($res), $label . ': the Reception route must exist (denial, not missing route)');
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

    private function errCode(WP_REST_Response $res): string
    {
        $b = $res->get_data();
        if ($b instanceof \WP_Error) {
            return (string) $b->get_error_code();
        }

        return (string) (is_array($b) ? ($b['code'] ?? '') : '');
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

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
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
     * @return list<int>
     */
    private function boardAppointmentIds(WP_REST_Response $res): array
    {
        if ($res->get_status() !== 200) {
            return [-1];
        }
        $ids = [];
        foreach ((array) ($this->payload($res)['appointments'] ?? []) as $row) {
            $ids[] = (int) ((array) $row)['id'];
        }
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function boardRow(WP_REST_Response $res, int $appointmentId): array
    {
        foreach ((array) ($this->payload($res)['appointments'] ?? []) as $row) {
            if ((int) ((array) $row)['id'] === $appointmentId) {
                return (array) $row;
            }
        }

        return [];
    }

    /**
     * @return list<int>
     */
    private function slotIds(WP_REST_Response $res): array
    {
        if ($res->get_status() !== 200) {
            return [-1];
        }
        $ids = [];
        foreach ((array) ($this->payload($res)['slots'] ?? []) as $row) {
            $ids[] = (int) ((array) $row)['slot_id'];
        }
        sort($ids);

        return $ids;
    }

    /** Location-local date in the given IANA frame (never UTC/WP/PHP ambient). */
    private function localDate(string $tz, string $modify = ''): string
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz));
        if ($modify !== '') {
            $now = $now->modify($modify);
        }

        return $now->format('Y-m-d');
    }

    /** A wall-clock time still in the future in the Location and on its local day. */
    private function futureTime(string $tz): string
    {
        $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz));
        $plus = $now->add(new \DateInterval('PT2H'));
        if ($plus->format('Y-m-d') !== $now->format('Y-m-d')) {
            return '23:59:59';
        }

        return $plus->format('H:i:00');
    }

    /** Deterministic unique slot time inside one second-of-day budget. */
    private function uniqueTime(int $clinicId): string
    {
        $offset = ($clinicId * 7) % 120;

        return sprintf('%02d:%02d:00', 8 + intdiv($offset, 60), $offset % 60);
    }

    private function slotTime(int $slotId): string
    {
        return (string) $this->slotRow($slotId)['slot_time'];
    }

    private function notificationCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_notifications') . ' WHERE clinic_id = %d', [$clinicId]);
    }

    private function visitCount(int $patientId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d', [$patientId]);
    }

    private function visitCountForAppointment(int $appointmentId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . ' WHERE appointment_id = %d', [$appointmentId]);
    }

    private function queueCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . " WHERE clinic_id = %d AND status IN ('checked_in','waiting','called','in_consultation')",
            [$clinicId]
        );
    }

    private function appointmentCountForPatient(int $patientId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE patient_id = %d', [$patientId]);
    }

    private function auditCount(string $action, int $resourceId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_id = %d', [$action, $resourceId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentRow(int $appointmentId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d LIMIT 1', [$appointmentId]);
        self::assertIsArray($row, 'the appointment row must exist for id ' . $appointmentId);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function slotRow(int $slotId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d LIMIT 1', [$slotId]);
        self::assertIsArray($row, 'the slot row must exist for id ' . $slotId);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function visitRow(int $visitId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d LIMIT 1', [$visitId]);
        self::assertIsArray($row, 'the visit row must exist for id ' . $visitId);

        return $row;
    }

    private function renderReception(int $userId): string
    {
        wp_set_current_user($userId);
        $url = StaffPortalShell::portal_url();
        self::assertNotEmpty($url, 'the canonical Staff Portal page exists');
        $path  = (string) (wp_parse_url($url, \PHP_URL_PATH) ?? '/');
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
        $u  = $login . '_' . bin2hex(random_bytes(3));
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

    private function insertLocation(int $clinicId, string $name, int $primary, string $timezone = self::TZ): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', $clinicId, $name, 'loc-' . bin2hex(random_bytes(3)), $timezone, $primary, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'location fixture insert: ' . $wpdb->last_error);

        return $id;
    }

    private function insertClinician(string $name, int $clinicId, int $active, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s)', $clinicId, $name, $wpUserId, $active, $now, $now));

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag, string $status = 'active'): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok  = $wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id'  => $clinicId,
            'mrn'        => 'MR-AC-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2)),
            'first_name' => 'Cancel',
            'last_name'  => 'Patient ' . $tag,
            'mobile'     => '0915' . sprintf('%07d', random_int(1000000, 9999999)),
            'status'     => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::assertNotFalse($ok, 'patient fixture insert: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array{booked?: int, held?: int, is_open?: int, duration?: int} $opts
     */
    private function insertSlot(int $clinicId, int $locId, int $clinicianId, string $date, string $time, int $capacity = 1, array $opts = []): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok  = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)',
            $clinicId,
            $locId,
            $clinicianId,
            $date,
            $time,
            (int) ($opts['duration'] ?? 20),
            $capacity,
            (int) ($opts['booked'] ?? 0),
            (int) ($opts['held'] ?? 0),
            (int) ($opts['is_open'] ?? 1),
            'manual',
            $now,
            $now
        ));
        self::assertNotFalse($ok, 'slot fixture insert: ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'slot fixture insert id');

        return $id;
    }

    private function insertAppointment(
        int $clinicId,
        int $locId,
        int $patientId,
        int $clinicianId,
        int $slotId,
        string $date,
        string $time,
        string $status = 'confirmed'
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok  = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s, %s)',
            $clinicId,
            $locId,
            'ac-' . bin2hex(random_bytes(3)),
            $patientId,
            $clinicianId,
            $slotId,
            0,
            $date,
            $time,
            20,
            $time,
            $status,
            0,
            $now,
            $now,
            $now
        ));
        self::assertNotFalse($ok, 'appointment fixture insert: ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'appointment fixture insert id');

        return $id;
    }

    /** A genuinely active Visit bound to the appointment (I-3 witness). */
    private function insertLiveVisit(int $clinicId, int $locId, int $clinicianId, int $patientId, int $appointmentId, string $date): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok  = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, appointment_id, source, status, visit_date, check_in_at, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %d, %s, %s, %s, %s, 1, %s, %s)',
            $clinicId,
            $locId,
            $clinicianId,
            $patientId,
            $appointmentId,
            'scheduled',
            'waiting',
            $date,
            $now,
            $now,
            $now
        ));
        self::assertNotFalse($ok, 'visit fixture insert: ' . $wpdb->last_error);
        $visitId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $visitId, 'visit fixture insert id');
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_appointments SET active_visit_id = %d WHERE id = %d', $visitId, $appointmentId));

        return $visitId;
    }
}
