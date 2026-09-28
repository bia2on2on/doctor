<?php
/**
 * Phase 11 (bounded slice) — Staff Portal Reception: Reschedule a Booked
 * Appointment Within the CURRENT Trusted Location — TEST-ONLY RED.
 *
 * Slice: inside the EXISTING Staff Portal Reception module an authorized
 * secretary works in the already-trusted Clinic + operational Location, sees a
 * confirmed not-yet-received booked appointment, explicitly chooses
 * «جابه‌جایی نوبت», sees the current appointment context, selects an eligible
 * doctor AT THAT SAME trusted Location, a Location-local operational date and an
 * ALREADY-GENERATED available persisted slot at the same Location, and
 * explicitly submits. The reschedule itself is delegated to the EXISTING staff
 * contract (`BookingService::rescheduleByStaff()`, the same service behind
 * `POST /clinic/v1/appointments/{id}/reschedule` → `BookingController::reschedule`):
 * existing AppointmentMachine T7, existing replacement-appointment creation,
 * existing slot locking, existing old-slot release, existing new-slot claim,
 * existing capacity/horizon policy, existing audit, existing reminder
 * cancellation, existing internal notification + reschedule SMS, existing
 * idempotency storage. No Visit, no queue, no walk-in, no payment, no second
 * state machine, no second scheduler.
 *
 * PRODUCT-OWNER DECISION for this slice: cross-Location reschedule is OUT OF
 * SCOPE. Both the SOURCE appointment Location and the DESTINATION slot Location
 * must equal the CURRENT trusted Reception Location. The reception boundary is
 * therefore STRICTER than the shared service (which proves the trusted Clinic
 * only): a same-Clinic appointment/slot at ANOTHER Location fails closed with
 * the canonical non-enumerating fingerprint, no other-Location selector exists,
 * no other-Location slot is ever listed, and no Location auto-switch happens.
 *
 * Live reconstruction (verified, not assumed — 2026-09-28):
 * - authoritative main = origin/main = bea85b55a823d580fe6b53e924cba847420d3b92;
 *   open PRs = 0; latest migration = 2026_09_26_0023_handwriting_prescription_paper.php
 *   (this RED adds/reserves no migration).
 * - Phase 11 = STARTED / IN PROGRESS — NOT CLOSED; Slice 1 Arrival Board (#133),
 *   Slice 2 search (#135), Slice 3 patient create (#136), Slice 4 walk-in (#137),
 *   Slice 5 appointment create (#138), Slice 6 cancel (#139) verified in live code.
 * - established staff reschedule contract (reused, never duplicated):
 *   POST /clinic/v1/appointments/{id}/reschedule → BookingController::reschedule
 *   (staff branch: clinic-scoped requireClinicPermission cpms_appt_reschedule;
 *   header Idempotency-Key REQUIRED as a UUID — null ⇒ 400 CLINIC_VALIDATION_FAILED)
 *   → BookingService::rescheduleByStaff(actor, id, clinicianId, date, time, idemKey, slotId)
 *   → rescheduleAs(..., 'staff'): idempotency claim (scope = key+endpoint+actor+appointment+clinic)
 *   BEFORE the license gate; replay of a completed key returns the stored response;
 *   a PENDING key ⇒ 409 CLINIC_DUPLICATE_IN_FLIGHT; failure ⇒ release(key);
 *   row lock findForUpdate; Clinic ownership via assertAppointmentWithinExplicitScope;
 *   participating destination clinician via clinician_participates_in;
 *   destination slot re-resolution (exact slot_id preferred) + is_open;
 *   staff min-lead = 0 + Clinic booking horizon (CLINIC_POLICY_VIOLATION / 409);
 *   AppointmentMachine T7 confirmed→rescheduled for secretary/doctor
 *   (CLINIC_INVALID_TRANSITION / 409 otherwise); I-3 assertNoActiveVisit
 *   (HAS_ACTIVE_VISIT / 409); duplicate + capacity guards
 *   (CLINIC_DUPLICATE_APPOINTMENT / CLINIC_SLOT_TAKEN / 409); ordered slot locks;
 *   releaseBooking(old) once + atomicBook(new) once; new appointment `confirmed`
 *   with rescheduled_from = old id (Location snapshotted from the destination
 *   slot); old appointment `rescheduled` with rescheduled_to = new id;
 *   APPOINTMENT_RESCHEDULED audit on the new appointment; queued internal
 *   reminders of the old appointment cancelled; existing reschedule SMS +
 *   internal notification; bounded view + previous_appointment_id.
 *
 * The RED fails ONLY because the Reception reschedule boundary + UI are missing:
 * bootstrap, migrations and fixtures succeed, the intended Reception reschedule
 * route answers with the canonical missing-route fingerprint (`rest_no_route`)
 * and the rendered module has no reschedule surface. Everything this slice leans
 * on is proven GREEN on this very head by the C8 CONTROL: the shared established
 * staff reschedule route (`POST /clinic/v1/appointments/{id}/reschedule`)
 * reschedules a confirmed appointment for the same secretary in the same
 * fixture, and Slices 1–6 (board, search, patient create, eligibility + walk-in,
 * appointment create, cancel) stay intact.
 *
 * TEST GROUP MAP:
 *  C1  Surface + explicit same-Location reschedule of a booked row .... INTENDED RED
 *  C2  Access: role / capability / nonce / membership ................. INTENDED RED
 *  C3  Trusted Clinic + CURRENT Location; cross-Location fails closed .. INTENDED RED
 *  C4  Destination clinician + persisted slot authority ............... INTENDED RED
 *  C5  Idempotency contract (required key, replay, in-flight, release) . INTENDED RED
 *  C6  Source state + active Visit honesty; no Visit/queue side effect . INTENDED RED
 *  C7  Board outcome for same-day vs future destination ................ INTENDED RED
 *  C8  Slices 1–6 + shared staff reschedule intact .................... CONTROL (pass)
 *
 * Excluded by design: cross-Location product capability, appointment details
 * modal/history, bulk reschedule, schedule editor, cancellation inside this
 * form, no-show, finance/payment, clinical/notes/prescriptions, new role/
 * capability/status/machine, migrations, second reschedule implementation,
 * client-side-only security, client-supplied date/time authority.
 *
 * Determinism: every persisted slot this test creates is placed at a distinct
 * minute offset from "now" in the Location IANA frame (crossing midnight simply
 * lands on the next local day, which is still a valid, distinct, future
 * destination) — never a fixed wall-clock literal that a late CI hour could turn
 * into a past slot.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase11ReceptionAppointmentRescheduleRedTest extends WP_UnitTestCase
{
    private const RESCHEDULE = '/clinic/v1/staff/portal/reception/appointments/';
    private const SHARED     = '/clinic/v1/appointments/';
    private const BOARD      = '/clinic/v1/staff/portal/reception/board';
    private const ARRIVALS   = '/clinic/v1/staff/portal/reception/arrivals';
    private const CLINICIANS = '/clinic/v1/staff/portal/reception/clinicians';
    private const SEARCH     = '/clinic/v1/staff/portal/reception/patients/search';
    private const PCREATE    = '/clinic/v1/staff/portal/reception/patients';
    private const WALK_IN    = '/clinic/v1/staff/portal/reception/walk-ins';
    private const SLOTS      = '/clinic/v1/staff/portal/reception/slots';
    private const APPTS      = '/clinic/v1/staff/portal/reception/appointments';
    private const CANCEL     = '/clinic/v1/staff/portal/reception/appointments/';

    private const TZ = 'Asia/Tehran';

    /** The ONLY keys the Reception reschedule response may carry (no PHI, no internals). */
    private const RESCHEDULE_KEYS = ['appointment', 'reception'];

    /** The ONLY keys the bounded rescheduled-appointment object may carry. */
    private const RESCHEDULE_APPT_KEYS = [
        'appointment_id',
        'date',
        'jalali',
        'previous_appointment_id',
        'reference_code',
        'status',
        'time',
    ];

    /** The ONLY keys the bounded reception scope object may carry. */
    private const RESCHEDULE_SCOPE_KEYS = ['clinic_id', 'location_id', 'on_operational_day'];

    /** Static Reception reschedule-surface markers (this slice's UI). */
    private const RESCHEDULE_MARKERS = [
        'sr-reschedule-open',
        'sr-reschedule-form',
        'sr-reschedule-context',
        'sr-reschedule-clinician',
        'sr-reschedule-date',
        'sr-reschedule-slots',
        'sr-reschedule-slot',
        'sr-reschedule-selected',
        'sr-reschedule-confirm',
        'sr-reschedule-abort',
    ];

    /** Surfaces this slice must NOT introduce. */
    private const FORBIDDEN_MARKERS = [
        'sr-reschedule-bulk',
        'sr-reschedule-location',
        'sr-reschedule-history',
        'sr-reschedule-visit',
        'sr-reschedule-payment',
        'sr-reschedule-cancel',
        'sr-appt-details',
        'sr-no-show',
    ];

    /** Live statuses the existing reschedule service treats as an active Visit. */
    private const LIVE_VISIT_STATUSES = ['checked_in', 'waiting', 'called', 'in_consultation', 'consultation_completed', 'awaiting_payment', 'paid'];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
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
        parent::tearDown();
    }

    // ============ C1 — Surface + explicit same-Location reschedule ============

    public function testC1_ConfirmedBookedRowIsRescheduledWithinTheTrustedLocation(): void
    {
        $fx      = $this->stage('c1');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);
        $today   = $this->localDate(self::TZ);

        // UI — Reception carries the compact reschedule surface inside the booked board.
        $html = $this->renderReception($fx['secretary']);
        self::assertStringContainsString('data-role="reception-app"', $html, 'C1: reception module renders');
        self::assertStringContainsString('data-role="sr-appointments"', $html, 'C1: Slice 1 board remains');
        foreach (self::RESCHEDULE_MARKERS as $role) {
            self::assertStringContainsString('data-role="' . $role . '"', $html, 'C1: reschedule surface marker ' . $role);
        }
        self::assertStringContainsString('/staff/portal/reception/appointments/', $html, 'C1: UI posts to the Reception reschedule boundary');
        self::assertStringContainsString('/reschedule', $html, 'C1: UI uses the reschedule action path');
        self::assertStringContainsString('Idempotency-Key', $html, 'C1: the UI sends the required idempotency header');
        foreach (self::FORBIDDEN_MARKERS as $role) {
            self::assertStringNotContainsString('data-role="' . $role . '"', $html, 'C1: out-of-scope surface ' . $role . ' must not exist');
        }

        // The booked row is on the actionable board for the trusted Location.
        $patient                       = $this->insertPatient($fx['clinic'], 'c1');
        [$source, $srcDate, $sourceT]  = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 120, 1, ['booked' => 1]);
        $appt                          = $this->insertAppointment($fx['clinic'], $fx['locA'], $patient, $fx['c1'], $source, $srcDate, $sourceT, 'confirmed');
        [$dest, $destDate, $destT]     = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c2'], 160);
        self::assertContains($appt, $this->boardAppointmentIds($this->dispatch('GET', self::BOARD, [], $headers)), 'C1: the booked row is actionable on the board');

        // A queued internal reminder for the OLD appointment: the existing
        // reschedule service must cancel it (never re-implemented here).
        $reminder = $this->queueInternalReminder($fx['clinic'], $patient, $appt);

        // REST — one explicit reschedule to an eligible doctor + persisted slot
        // of the SAME trusted operational Location.
        $res = $this->reschedule($fx, $appt, $fx['locA'], $fx['c2'], $dest, $this->uuid());
        self::assertSame(200, $res->get_status(), 'C1: reception reschedule answers — ' . $this->errCode($res));
        $data = $this->payload($res);
        self::assertSame(self::RESCHEDULE_KEYS, $this->sortedKeys($data), 'C1: bounded reschedule payload');
        $view = (array) ($data['appointment'] ?? []);
        self::assertSame(self::RESCHEDULE_APPT_KEYS, $this->sortedKeys($view), 'C1: bounded rescheduled-appointment object');
        self::assertSame(self::RESCHEDULE_SCOPE_KEYS, $this->sortedKeys((array) ($data['reception'] ?? [])), 'C1: bounded reception scope object');
        $newId = (int) ($view['appointment_id'] ?? 0);
        self::assertGreaterThan(0, $newId, 'C1: the new appointment id');
        self::assertNotSame($appt, $newId, 'C1: a replacement appointment is created');
        self::assertSame($appt, (int) ($view['previous_appointment_id'] ?? 0), 'C1: explicit old→new relationship');
        self::assertSame('confirmed', (string) ($view['status'] ?? ''), 'C1: the replacement is confirmed');
        self::assertSame($destDate, (string) ($view['date'] ?? ''), 'C1: the destination operational date');
        self::assertSame(substr($destT, 0, 5), (string) ($view['time'] ?? ''), 'C1: the destination slot time');
        self::assertSame($fx['clinic'], (int) ($data['reception']['clinic_id'] ?? 0), 'C1: trusted Clinic echoed from server truth');
        self::assertSame($fx['locA'], (int) ($data['reception']['location_id'] ?? 0), 'C1: trusted operational Location echoed from server truth');
        self::assertSame($destDate === $today, (bool) ($data['reception']['on_operational_day'] ?? false), 'C1: the operational-day flag follows the persisted destination date');

        // Persisted truth — existing service semantics, nothing re-implemented here.
        $old = $this->appointmentRow($appt);
        $new = $this->appointmentRow($newId);
        self::assertSame('rescheduled', (string) $old['status'], 'C1: existing T7 source state');
        self::assertSame($newId, (int) $old['rescheduled_to'], 'C1: old → new pointer');
        self::assertSame('confirmed', (string) $new['status'], 'C1: replacement state');
        self::assertSame($appt, (int) $new['rescheduled_from'], 'C1: new → old pointer');
        self::assertSame($fx['c2'], (int) $new['clinician_id'], 'C1: the selected eligible doctor owns the replacement');
        self::assertSame($fx['locA'], (int) $new['location_id'], 'C1: the replacement stays at the CURRENT trusted Location');
        self::assertSame($dest, (int) $new['slot_id'], 'C1: the replacement holds the selected persisted slot');
        self::assertSame(1, $this->replacementCount($appt), 'C1: exactly ONE replacement appointment');
        self::assertSame(0, (int) $this->slotRow($source)['booked_count'], 'C1: the old slot is released once');
        self::assertSame(1, (int) $this->slotRow($dest)['booked_count'], 'C1: the new slot is claimed once');
        self::assertSame(1, $this->auditCount('APPOINTMENT_RESCHEDULED', $newId), 'C1: existing APPOINTMENT_RESCHEDULED audit');
        self::assertSame('cancelled', (string) $this->notificationRow($reminder)['status'], 'C1: existing queued-reminder cancellation for the old appointment');
        self::assertGreaterThanOrEqual(1, $this->rescheduleSmsCount($fx['clinic'], $newId), 'C1: existing reschedule SMS/change notification');
        self::assertSame(0, $this->visitCount($patient), 'C1: NO Visit created');
        self::assertSame(0, $this->visitCountForAppointment($appt), 'C1: NO Visit bound to the old appointment');
        self::assertSame(0, $this->visitCountForAppointment($newId), 'C1: NO Visit bound to the replacement');
        self::assertSame(0, $this->queueCount($fx['clinic']), 'C1: NO queue entry created');
        self::assertSame(0, $this->paymentCount($fx['clinic']), 'C1: NO payment created');

        // Privacy — nothing clinical / unrelated in the bounded response.
        $flat = strtolower((string) wp_json_encode($data));
        foreach (['mobile', 'national_id', 'mrn', 'note', 'prescription', 'invoice', 'payment', 'file', 'storage_path', 'clinician_id', 'patient_id', 'idempot', 'location_name'] as $needle) {
            self::assertStringNotContainsString($needle, $flat, 'C1: reschedule response must not expose ' . $needle);
        }
    }

    // ============ C2 — Access ============

    public function testC2_AccessRoleCapabilityNonceMembership(): void
    {
        $fx      = $this->stage('c2');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);

        $mk = function (string $tag) use ($fx): array {
            $patient                      = $this->insertPatient($fx['clinic'], 'c2' . $tag);
            [$slot, $date, $time]         = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 120, 1, ['booked' => 1]);
            $appt                         = $this->insertAppointment($fx['clinic'], $fx['locA'], $patient, $fx['c1'], $slot, $date, $time, 'confirmed');
            [$dest]                       = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c2'], 170);

            return [$patient, $slot, $appt, $dest];
        };

        // A. Doctor (not the Reception role) — capability alone is not access.
        [, $srcA, $apptA, $destA] = $mk('a');
        wp_set_current_user($fx['doc1']);
        $this->assertDenied($this->reschedule($fx, $apptA, $fx['locA'], $fx['c2'], $destA, $this->uuid()), 'C2 doctor reschedule');
        self::assertSame('confirmed', (string) $this->appointmentRow($apptA)['status'], 'C2: doctor denial mutates nothing');
        self::assertSame(1, (int) $this->slotRow($srcA)['booked_count'], 'C2: doctor denial releases no slot');

        // B. Accountant with ACTIVE membership — membership alone is not permission.
        $acct = $this->makeUser('qa_c2_acct', RolesAndCapabilities::ROLE_ACCOUNTANT);
        $this->seedMembership($acct, $fx['clinic'], 'cpms_accountant');
        wp_set_current_user($acct);
        $this->assertDenied($this->reschedule($fx, $apptA, $fx['locA'], $fx['c2'], $destA, $this->uuid()), 'C2 accountant reschedule');

        // C. Secretary with a clinic-scoped cpms_appt_reschedule DENY (explicit deny wins).
        $mid = $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        App::membership_service()->set_capability($mid, RolesAndCapabilities::APPT_RESCHEDULE, 'deny');
        wp_set_current_user($fx['secretary']);
        $this->assertDenied($this->reschedule($fx, $apptA, $fx['locA'], $fx['c2'], $destA, $this->uuid()), 'C2 appt-reschedule-deny');
        self::assertSame('confirmed', (string) $this->appointmentRow($apptA)['status'], 'C2: capability denial mutates nothing');
        self::assertSame(1, (int) $this->slotRow($srcA)['booked_count'], 'C2: capability denial releases no slot');
        self::assertSame(0, (int) $this->slotRow($destA)['booked_count'], 'C2: capability denial claims no slot');
        App::membership_service()->remove_capability($mid, RolesAndCapabilities::APPT_RESCHEDULE);

        // D. Missing nonce (CSRF).
        $noNonce = $this->dispatch(
            'POST',
            self::RESCHEDULE . $apptA . '/reschedule',
            ['clinician_id' => $fx['c2'], 'slot_id' => $destA],
            $headers,
            false,
            $this->uuid()
        );
        self::assertSame(403, $noNonce->get_status(), 'C2: reschedule without nonce rejected');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($noNonce), 'C2: nonce denial code');

        // E. Suspended membership.
        App::membership_service()->suspend_membership($mid);
        wp_set_current_user($fx['secretary']);
        $this->assertDenied($this->reschedule($fx, $apptA, $fx['locA'], $fx['c2'], $destA, $this->uuid()), 'C2 suspended membership');

        // F. Secretary without membership; anonymous.
        $loner = $this->makeUser('qa_c2_loner', RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($loner);
        $this->assertDenied($this->reschedule($fx, $apptA, $fx['locA'], $fx['c2'], $destA, $this->uuid()), 'C2 no-membership');
        wp_set_current_user(0);
        self::assertContains($this->reschedule($fx, $apptA, $fx['locA'], $fx['c2'], $destA, $this->uuid())->get_status(), [401, 403], 'C2: anonymous denied');

        self::assertSame('confirmed', (string) $this->appointmentRow($apptA)['status'], 'C2: no denied actor rescheduled the appointment');
        self::assertSame(1, (int) $this->slotRow($srcA)['booked_count'], 'C2: no denied actor released the slot');
        self::assertSame(0, (int) $this->slotRow($destA)['booked_count'], 'C2: no denied actor claimed the slot');
        self::assertSame(0, $this->replacementCount($apptA), 'C2: no denied actor created a replacement');
        self::assertSame(0, $this->auditCount('APPOINTMENT_RESCHEDULED', $apptA), 'C2: no denied actor produced a reschedule audit');

        // Positive control in the same stage: the authorized secretary succeeds.
        App::membership_service()->reactivate_membership($mid);
        wp_set_current_user($fx['secretary']);
        $ok = $this->reschedule($fx, $apptA, $fx['locA'], $fx['c2'], $destA, $this->uuid());
        self::assertSame(200, $ok->get_status(), 'C2: authorized secretary reschedules — ' . $this->errCode($ok));
        self::assertSame('rescheduled', (string) $this->appointmentRow($apptA)['status'], 'C2: authorized reschedule persisted');
    }

    // ============ C3 — Trusted Clinic + CURRENT trusted Location ============

    public function testC3_TrustedClinicAndCurrentLocationFailClosed(): void
    {
        // A. Same-Clinic SOURCE appointment at ANOTHER Location must fail closed
        //    (the shared service's Clinic-only check is NOT sufficient).
        $fx = $this->stage('c3a');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $pA                       = $this->insertPatient($fx['clinic'], 'c3a');
        [$srcB, $srcBDate, $srcBT] = $this->insertFutureSlot($fx['clinic'], $fx['locB'], $fx['c2'], 120, 1, ['booked' => 1]);
        $apptB                    = $this->insertAppointment($fx['clinic'], $fx['locB'], $pA, $fx['c2'], $srcB, $srcBDate, $srcBT, 'confirmed');
        [$destA]                  = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c2'], 180);

        $blocked = $this->reschedule($fx, $apptB, $fx['locA'], $fx['c2'], $destA, $this->uuid());
        self::assertSame(404, $blocked->get_status(), 'C3: same-Clinic cross-Location SOURCE rejected — ' . $this->errCode($blocked));
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($blocked), 'C3: canonical non-enumerating fingerprint');
        self::assertSame('confirmed', (string) $this->appointmentRow($apptB)['status'], 'C3: cross-Location rejection mutates nothing');
        self::assertSame(1, (int) $this->slotRow($srcB)['booked_count'], 'C3: cross-Location rejection releases no slot');
        self::assertSame(0, (int) $this->slotRow($destA)['booked_count'], 'C3: cross-Location rejection claims no slot');
        self::assertSame(0, $this->replacementCount($apptB), 'C3: cross-Location rejection creates no replacement');

        // The very same appointment IS reschedulable when Reception is scoped to
        // its own trusted operational Location (the rejection was Location, not
        // an impossibility).
        [$sameLocDest] = $this->insertFutureSlot($fx['clinic'], $fx['locB'], $fx['c2'], 240);
        $sameLoc       = $this->reschedule($fx, $apptB, $fx['locB'], $fx['c2'], $sameLocDest, $this->uuid());
        self::assertSame(200, $sameLoc->get_status(), 'C3: reschedulable at its own Location — ' . $this->errCode($sameLoc));
        self::assertSame('rescheduled', (string) $this->appointmentRow($apptB)['status'], 'C3: same-Location reschedule persisted');

        // B. Foreign-Clinic SOURCE appointment: identical non-enumerating fingerprint.
        $fxB = $this->stage('c3b');
        $this->seedMembership($fxB['secretary'], $fxB['clinic'], 'cpms_secretary');
        wp_set_current_user($fxB['secretary']);
        $foreignPatient                 = $this->insertPatient($fxB['clinicF'], 'c3f');
        $foreignLoc                     = $this->insertLocation($fxB['clinicF'], 'C3 Foreign Loc', 1);
        [$foreignSlot, $fDate, $fTime]  = $this->insertFutureSlot($fxB['clinicF'], $foreignLoc, $fxB['c5'], 120, 1, ['booked' => 1]);
        $foreignAppt                    = $this->insertAppointment($fxB['clinicF'], $foreignLoc, $foreignPatient, $fxB['c5'], $foreignSlot, $fDate, $fTime, 'confirmed');
        [$localDest]                    = $this->insertFutureSlot($fxB['clinic'], $fxB['locA'], $fxB['c1'], 180);

        $foreign = $this->reschedule($fxB, $foreignAppt, $fxB['locA'], $fxB['c1'], $localDest, $this->uuid());
        self::assertSame(404, $foreign->get_status(), 'C3: foreign-Clinic SOURCE rejected — ' . $this->errCode($foreign));
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($foreign), 'C3: identical fingerprint for foreign Clinic (no enumeration)');
        self::assertSame('confirmed', (string) $this->appointmentRow($foreignAppt)['status'], 'C3: foreign-Clinic rejection mutates nothing');
        self::assertSame(1, (int) $this->slotRow($foreignSlot)['booked_count'], 'C3: foreign-Clinic rejection releases no foreign slot');

        // C. DESTINATION slot from ANOTHER Location of the SAME Clinic: fail closed
        //    even though the shared service would accept it (cross-Location is a
        //    separate, NOT-authorized product capability).
        $pC                            = $this->insertPatient($fxB['clinic'], 'c3c');
        [$srcC, $srcCDate, $srcCT]     = $this->insertFutureSlot($fxB['clinic'], $fxB['locA'], $fxB['c1'], 240, 1, ['booked' => 1]);
        $apptC                         = $this->insertAppointment($fxB['clinic'], $fxB['locA'], $pC, $fxB['c1'], $srcC, $srcCDate, $srcCT, 'confirmed');
        [$destB]                       = $this->insertFutureSlot($fxB['clinic'], $fxB['locB'], $fxB['c1'], 300);
        $crossD                        = $this->reschedule($fxB, $apptC, $fxB['locA'], $fxB['c1'], $destB, $this->uuid());
        self::assertSame(404, $crossD->get_status(), 'C3: cross-Location DESTINATION slot rejected — ' . $this->errCode($crossD));
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($crossD), 'C3: canonical non-enumerating fingerprint for the destination');
        self::assertSame('confirmed', (string) $this->appointmentRow($apptC)['status'], 'C3: cross-Location destination mutates nothing');
        self::assertSame(1, (int) $this->slotRow($srcC)['booked_count'], 'C3: cross-Location destination releases no source slot');
        self::assertSame(0, (int) $this->slotRow($destB)['booked_count'], 'C3: cross-Location destination claims no other-Location slot');
        self::assertSame(0, $this->replacementCount($apptC), 'C3: cross-Location destination creates no replacement');

        // D. Raw selectors cannot manufacture authority: body clinic_id /
        //    location_id pointing at the other Location / foreign Clinic change
        //    nothing (the route owns its scope from trusted server context).
        $raw = $this->dispatch('POST', self::RESCHEDULE . $apptC . '/reschedule', [
            'clinician_id' => $fxB['c1'],
            'slot_id'      => $destB,
            'clinic_id'    => $fxB['clinicF'],
            'location_id'  => $fxB['locB'],
        ], $this->scopeHeaders($fxB['clinic'], $fxB['locA']), true, $this->uuid());
        self::assertSame(404, $raw->get_status(), 'C3: raw payload selectors are not authority — ' . $this->errCode($raw));
        self::assertSame('confirmed', (string) $this->appointmentRow($apptC)['status'], 'C3: raw selectors rescheduled nothing');
        self::assertSame(0, (int) $this->slotRow($destB)['booked_count'], 'C3: raw selectors claimed nothing');

        // E. Foreign / unassigned Location selector on the request scope fails closed.
        $fx2                        = $this->stage('c3e');
        $this->seedMembership($fx2['secretary'], $fx2['clinic'], 'cpms_secretary');
        wp_set_current_user($fx2['secretary']);
        $p4                         = $this->insertPatient($fx2['clinic'], 'c3e');
        [$src4, $src4Date, $src4T]  = $this->insertFutureSlot($fx2['clinic'], $fx2['locA'], $fx2['c1'], 120, 1, ['booked' => 1]);
        $appt4                      = $this->insertAppointment($fx2['clinic'], $fx2['locA'], $p4, $fx2['c1'], $src4, $src4Date, $src4T, 'confirmed');
        [$dest4]                    = $this->insertFutureSlot($fx2['clinic'], $fx2['locA'], $fx2['c2'], 180);
        $denied                     = $this->reschedule($fx2, $appt4, $fx2['locF'], $fx2['c2'], $dest4, $this->uuid());
        self::assertContains($denied->get_status(), [400, 403], 'C3: foreign Location scope fails closed — ' . $this->errCode($denied));
        self::assertSame('confirmed', (string) $this->appointmentRow($appt4)['status'], 'C3: foreign Location scope rescheduled nothing');
        self::assertSame(1, (int) $this->slotRow($src4)['booked_count'], 'C3: foreign Location scope released no slot');
        self::assertSame(0, (int) $this->slotRow($dest4)['booked_count'], 'C3: foreign Location scope claimed no slot');
    }

    // ============ C4 — Destination clinician + persisted slot authority ============

    public function testC4_DestinationClinicianAndPersistedSlotAuthority(): void
    {
        $fx = $this->stage('c4');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);

        $p1                        = $this->insertPatient($fx['clinic'], 'c4a');
        [$src1, $src1Date, $src1T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 120, 1, ['booked' => 1]);
        $appt1                     = $this->insertAppointment($fx['clinic'], $fx['locA'], $p1, $fx['c1'], $src1, $src1Date, $src1T, 'confirmed');
        [$sameD]                   = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 180);
        self::assertContains($fx['c1'], $this->clinicianIds($this->dispatch('GET', self::CLINICIANS, [], $headers)), 'C4: the current clinician is eligible at this Location');

        // A. Ineligible destination clinician (active membership in the Clinic but
        //    NOT assigned to this Location) is never a destination.
        $ineligible = $this->reschedule($fx, $appt1, $fx['locA'], $fx['c5'], $sameD, $this->uuid());
        self::assertSame(404, $ineligible->get_status(), 'C4: ineligible destination clinician rejected — ' . $this->errCode($ineligible));
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($ineligible), 'C4: canonical non-enumerating fingerprint for the clinician');
        self::assertSame('confirmed', (string) $this->appointmentRow($appt1)['status'], 'C4: ineligible clinician mutates nothing');

        // B. A clinician whose durable participation is missing entirely is never
        //    a destination either (foreign Clinic membership only).
        $foreignDoctor = $this->reschedule($fx, $appt1, $fx['locA'], $fx['c5'], $sameD, $this->uuid());
        self::assertSame(404, $foreignDoctor->get_status(), 'C4: foreign clinician rejected — ' . $this->errCode($foreignDoctor));

        // C. Missing / non-persisted slot selector: a persisted slot_id is REQUIRED.
        $noSlot = $this->dispatch('POST', self::RESCHEDULE . $appt1 . '/reschedule', ['clinician_id' => $fx['c1']], $headers, true, $this->uuid());
        self::assertSame(422, $noSlot->get_status(), 'C4: missing slot_id rejected — ' . $this->errCode($noSlot));
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($noSlot), 'C4: bounded validation envelope');
        $unknown = $this->reschedule($fx, $appt1, $fx['locA'], $fx['c1'], 987654321, $this->uuid());
        self::assertSame(404, $unknown->get_status(), 'C4: unknown slot rejected — ' . $this->errCode($unknown));
        self::assertSame('confirmed', (string) $this->appointmentRow($appt1)['status'], 'C4: missing/unknown slot mutates nothing');

        // D. Destination slot belonging to ANOTHER clinician is rejected.
        [$dest2] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c2'], 240);
        $wrong   = $this->reschedule($fx, $appt1, $fx['locA'], $fx['c1'], $dest2, $this->uuid());
        self::assertSame(404, $wrong->get_status(), 'C4: slot/clinician mismatch rejected — ' . $this->errCode($wrong));
        self::assertSame(0, (int) $this->slotRow($dest2)['booked_count'], 'C4: mismatch claims nothing');

        // E. A CLOSED (not open) persisted slot is never a destination.
        [$closed]  = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 300, 1, ['is_open' => 0]);
        $closedRes = $this->reschedule($fx, $appt1, $fx['locA'], $fx['c1'], $closed, $this->uuid());
        self::assertSame(404, $closedRes->get_status(), 'C4: closed slot rejected — ' . $this->errCode($closedRes));
        self::assertSame(0, (int) $this->slotRow($closed)['booked_count'], 'C4: closed slot claims nothing');

        // F. A FULL slot keeps the established CLINIC_SLOT_TAKEN behaviour from the
        //    delegated service (never re-implemented here) and mutates nothing.
        [$full]    = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 360, 1, ['booked' => 1]);
        $fullRes   = $this->reschedule($fx, $appt1, $fx['locA'], $fx['c1'], $full, $this->uuid());
        self::assertSame(409, $fullRes->get_status(), 'C4: full destination slot rejected — ' . $this->errCode($fullRes));
        self::assertSame('CLINIC_SLOT_TAKEN', $this->errCode($fullRes), 'C4: existing bounded capacity envelope');
        self::assertSame('confirmed', (string) $this->appointmentRow($appt1)['status'], 'C4: capacity rejection mutates nothing');
        self::assertSame(1, (int) $this->slotRow($src1)['booked_count'], 'C4: capacity rejection releases nothing');
        self::assertSame(1, (int) $this->slotRow($full)['booked_count'], 'C4: capacity rejection claims nothing');

        // G. Booking horizon stays authoritative (existing Clinic policy).
        $beyond  = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $this->localDate(self::TZ, '+90 days'), '09:00:00', 1);
        $horizon = $this->reschedule($fx, $appt1, $fx['locA'], $fx['c1'], $beyond, $this->uuid());
        self::assertSame(409, $horizon->get_status(), 'C4: beyond-horizon destination rejected — ' . $this->errCode($horizon));
        self::assertSame('CLINIC_POLICY_VIOLATION', $this->errCode($horizon), 'C4: existing Clinic horizon envelope');
        self::assertSame('confirmed', (string) $this->appointmentRow($appt1)['status'], 'C4: horizon rejection mutates nothing');
        self::assertSame(0, (int) $this->slotRow($beyond)['booked_count'], 'C4: horizon rejection claims nothing');

        // H. Destination date/time are NEVER client authority: the persisted slot
        //    supplies them, so a client date/time that disagrees with the selected
        //    persisted slot cannot hijack the destination.
        [$dest3, $dest3Date, $dest3T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 420);
        $forged = $this->dispatch('POST', self::RESCHEDULE . $appt1 . '/reschedule', [
            'clinician_id' => $fx['c1'],
            'slot_id'      => $dest3,
            'slot_date'    => $this->localDate(self::TZ, '+3 days'),
            'slot_time'    => '03:00:00',
        ], $headers, true, $this->uuid());
        self::assertSame(200, $forged->get_status(), 'C4: the persisted slot stays the only authority — ' . $this->errCode($forged));
        $forgedView = (array) ($this->payload($forged)['appointment'] ?? []);
        self::assertSame($dest3Date, (string) ($forgedView['date'] ?? ''), 'C4: client date is not authority');
        self::assertSame(substr($dest3T, 0, 5), (string) ($forgedView['time'] ?? ''), 'C4: client time is not authority');
        $forgedNew = $this->appointmentRow((int) ($forgedView['appointment_id'] ?? 0));
        self::assertSame($dest3, (int) $forgedNew['slot_id'], 'C4: the persisted destination slot is claimed');
        self::assertSame($fx['locA'], (int) $forgedNew['location_id'], 'C4: the replacement stays at the trusted Location');

        // I. Success at the SAME clinician (time-only change) stays inside the
        //    trusted Location and keeps every existing invariant.
        $p9                        = $this->insertPatient($fx['clinic'], 'c4i');
        [$src9, $src9Date, $src9T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 480, 1, ['booked' => 1]);
        $appt9                     = $this->insertAppointment($fx['clinic'], $fx['locA'], $p9, $fx['c1'], $src9, $src9Date, $src9T, 'confirmed');
        [$dest9, $dest9Date, $dest9T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 540);
        $same                      = $this->reschedule($fx, $appt9, $fx['locA'], $fx['c1'], $dest9, $this->uuid());
        self::assertSame(200, $same->get_status(), 'C4: same-clinician time change succeeds — ' . $this->errCode($same));
        $sameNew = $this->appointmentRow((int) ($this->payload($same)['appointment']['appointment_id'] ?? 0));
        self::assertSame($fx['c1'], (int) $sameNew['clinician_id'], 'C4: clinician preserved');
        self::assertSame($dest9Date, (string) $sameNew['slot_date'], 'C4: persisted destination date');
        self::assertSame($dest9T, (string) $sameNew['slot_time'], 'C4: persisted destination time');
        self::assertSame(0, (int) $this->slotRow($src9)['booked_count'], 'C4: old slot released');
        self::assertSame(1, (int) $this->slotRow($dest9)['booked_count'], 'C4: new slot claimed');
    }

    // ============ C5 — Idempotency ============

    public function testC5_IdempotencyContractIsTheExistingOne(): void
    {
        $fx = $this->stage('c5');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);

        $p1                        = $this->insertPatient($fx['clinic'], 'c5a');
        [$src1, $src1Date, $src1T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 120, 1, ['booked' => 1]);
        $appt1                     = $this->insertAppointment($fx['clinic'], $fx['locA'], $p1, $fx['c1'], $src1, $src1Date, $src1T, 'confirmed');
        [$dest1]                   = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 180);

        // A. Missing key: the existing contract requires a UUID Idempotency-Key.
        $missing = $this->dispatch('POST', self::RESCHEDULE . $appt1 . '/reschedule', ['clinician_id' => $fx['c1'], 'slot_id' => $dest1], $headers);
        self::assertSame(400, $missing->get_status(), 'C5: missing Idempotency-Key rejected — ' . $this->errCode($missing));
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($missing), 'C5: existing bounded envelope');
        self::assertSame('confirmed', (string) $this->appointmentRow($appt1)['status'], 'C5: missing key mutates nothing');

        // B. A non-UUID key is not a key (the established RestBase contract).
        $nonUuid = $this->dispatch('POST', self::RESCHEDULE . $appt1 . '/reschedule', ['clinician_id' => $fx['c1'], 'slot_id' => $dest1], $headers, true, 'not-a-uuid');
        self::assertSame(400, $nonUuid->get_status(), 'C5: non-UUID key rejected — ' . $this->errCode($nonUuid));

        // C. Same key replay: the stored response is returned and NO second
        //    replacement appointment is created (double submit is safe).
        $key       = $this->uuid();
        $first     = $this->reschedule($fx, $appt1, $fx['locA'], $fx['c1'], $dest1, $key);
        self::assertSame(200, $first->get_status(), 'C5: first attempt succeeds — ' . $this->errCode($first));
        $firstView = (array) ($this->payload($first)['appointment'] ?? []);
        $newId     = (int) ($firstView['appointment_id'] ?? 0);
        $replay    = $this->reschedule($fx, $appt1, $fx['locA'], $fx['c1'], $dest1, $key);
        self::assertSame(200, $replay->get_status(), 'C5: replay answers from the stored response — ' . $this->errCode($replay));
        self::assertSame($firstView, (array) ($this->payload($replay)['appointment'] ?? []), 'C5: replay returns the stored bounded view');
        self::assertSame(1, $this->replacementCount($appt1), 'C5: replay creates NO second replacement');
        self::assertSame(1, (int) $this->slotRow($dest1)['booked_count'], 'C5: replay claims the destination slot exactly once');
        self::assertSame(0, (int) $this->slotRow($src1)['booked_count'], 'C5: replay releases the old slot exactly once');
        self::assertSame(1, $this->auditCount('APPOINTMENT_RESCHEDULED', $newId), 'C5: replay audits exactly once');

        // D. Duplicate in-flight: an existing PENDING key keeps the established
        //    409 CLINIC_DUPLICATE_IN_FLIGHT and mutates nothing.
        $p2                        = $this->insertPatient($fx['clinic'], 'c5b');
        [$src2, $src2Date, $src2T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 240, 1, ['booked' => 1]);
        $appt2                     = $this->insertAppointment($fx['clinic'], $fx['locA'], $p2, $fx['c1'], $src2, $src2Date, $src2T, 'confirmed');
        [$dest2]                   = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 300);
        $inFlightKey               = $this->uuid();
        $this->seedPendingIdempotency($inFlightKey, $fx['clinic'], $fx['secretary'], $appt2);
        $inFlight = $this->reschedule($fx, $appt2, $fx['locA'], $fx['c1'], $dest2, $inFlightKey);
        self::assertSame(409, $inFlight->get_status(), 'C5: duplicate in-flight rejected — ' . $this->errCode($inFlight));
        self::assertSame('CLINIC_DUPLICATE_IN_FLIGHT', $this->errCode($inFlight), 'C5: existing bounded in-flight envelope');
        self::assertSame('confirmed', (string) $this->appointmentRow($appt2)['status'], 'C5: in-flight rejection mutates nothing');
        self::assertSame(0, $this->replacementCount($appt2), 'C5: in-flight rejection creates no replacement');

        // E. A failed attempt RELEASES the key (existing contract): the same key
        //    can then perform the real reschedule instead of replaying a failure.
        $p3                        = $this->insertPatient($fx['clinic'], 'c5c');
        [$src3, $src3Date, $src3T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 360, 1, ['booked' => 1]);
        $appt3                     = $this->insertAppointment($fx['clinic'], $fx['locA'], $p3, $fx['c1'], $src3, $src3Date, $src3T, 'confirmed');
        [$full3]                   = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 420, 1, ['booked' => 1]);
        [$dest3]                   = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 480);
        $retryKey                  = $this->uuid();
        $failed                    = $this->reschedule($fx, $appt3, $fx['locA'], $fx['c1'], $full3, $retryKey);
        self::assertSame(409, $failed->get_status(), 'C5: capacity failure keeps its bounded envelope — ' . $this->errCode($failed));
        self::assertSame('confirmed', (string) $this->appointmentRow($appt3)['status'], 'C5: failed attempt mutates nothing');
        $retry = $this->reschedule($fx, $appt3, $fx['locA'], $fx['c1'], $dest3, $retryKey);
        self::assertSame(200, $retry->get_status(), 'C5: the released key performs the real retry — ' . $this->errCode($retry));
        self::assertSame('rescheduled', (string) $this->appointmentRow($appt3)['status'], 'C5: retry persisted');
        self::assertSame(1, $this->replacementCount($appt3), 'C5: retry creates exactly one replacement');
        self::assertSame(1, (int) $this->slotRow($dest3)['booked_count'], 'C5: retry claims the destination exactly once');

        // F. No Visit / queue / payment anywhere in this stage.
        self::assertSame(0, $this->visitCount($p1) + $this->visitCount($p2) + $this->visitCount($p3), 'C5: NO Visit created');
        self::assertSame(0, $this->queueCount($fx['clinic']), 'C5: NO queue entry created');
        self::assertSame(0, $this->paymentCount($fx['clinic']), 'C5: NO payment created');
    }

    // ============ C6 — Source state + active Visit honesty ============

    public function testC6_SourceStateAndActiveVisitStayHonest(): void
    {
        $fx = $this->stage('c6');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);

        // A. A pending source follows the EXISTING machine behaviour (T7 is
        //    confirmed-only) — bounded, honest, and mutating nothing.
        $p1                        = $this->insertPatient($fx['clinic'], 'c6a');
        [$src1, $src1Date, $src1T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 120, 1, ['booked' => 1]);
        $appt1                     = $this->insertAppointment($fx['clinic'], $fx['locA'], $p1, $fx['c1'], $src1, $src1Date, $src1T, 'pending');
        [$dest1]                   = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 180);
        $pending                   = $this->reschedule($fx, $appt1, $fx['locA'], $fx['c1'], $dest1, $this->uuid());
        self::assertSame(409, $pending->get_status(), 'C6: pending source follows the existing machine — ' . $this->errCode($pending));
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($pending), 'C6: existing bounded state envelope');
        self::assertSame('pending', (string) $this->appointmentRow($appt1)['status'], 'C6: pending rejection mutates nothing');
        self::assertSame(1, (int) $this->slotRow($src1)['booked_count'], 'C6: pending rejection releases nothing');
        self::assertSame(0, (int) $this->slotRow($dest1)['booked_count'], 'C6: pending rejection claims nothing');

        // B. An already-terminal source (cancelled) keeps the existing machine's
        //    bounded failure; no replacement, no slot movement.
        $p2                        = $this->insertPatient($fx['clinic'], 'c6b');
        [$src2, $src2Date, $src2T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 240);
        $appt2                     = $this->insertAppointment($fx['clinic'], $fx['locA'], $p2, $fx['c1'], $src2, $src2Date, $src2T, 'cancelled_by_staff');
        [$dest2]                   = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 300);
        $terminal                  = $this->reschedule($fx, $appt2, $fx['locA'], $fx['c1'], $dest2, $this->uuid());
        self::assertSame(409, $terminal->get_status(), 'C6: terminal source rejected — ' . $this->errCode($terminal));
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($terminal), 'C6: existing bounded state envelope');
        self::assertSame(0, $this->replacementCount($appt2), 'C6: terminal source creates no replacement');
        self::assertSame(0, (int) $this->slotRow($dest2)['booked_count'], 'C6: terminal source claims no slot');

        // C. An ACTIVE Visit (I-3) blocks honestly: no slot release, no
        //    destination appointment, no false success.
        $p3                        = $this->insertPatient($fx['clinic'], 'c6c');
        [$src3, $src3Date, $src3T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 360, 1, ['booked' => 1]);
        $appt3                     = $this->insertAppointment($fx['clinic'], $fx['locA'], $p3, $fx['c1'], $src3, $src3Date, $src3T, 'confirmed');
        $visit                     = $this->insertLiveVisit($fx['clinic'], $fx['locA'], $fx['c1'], $p3, $appt3, $src3Date);
        [$dest3]                   = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 420);
        $blocked                   = $this->reschedule($fx, $appt3, $fx['locA'], $fx['c1'], $dest3, $this->uuid());
        self::assertSame(409, $blocked->get_status(), 'C6: active Visit blocks the reschedule — ' . $this->errCode($blocked));
        self::assertSame('HAS_ACTIVE_VISIT', $this->errCode($blocked), 'C6: existing bounded error code (not re-implemented here)');
        self::assertSame('confirmed', (string) $this->appointmentRow($appt3)['status'], 'C6: rejection mutates no appointment state');
        self::assertSame(1, (int) $this->slotRow($src3)['booked_count'], 'C6: the old slot stays claimed — released at most once, never on rejection');
        self::assertSame(0, (int) $this->slotRow($dest3)['booked_count'], 'C6: the destination slot is NOT claimed');
        self::assertSame(0, $this->replacementCount($appt3), 'C6: no destination appointment on rejection');
        self::assertSame('waiting', (string) $this->visitRow($visit)['status'], 'C6: the live Visit is untouched');
        self::assertSame(1, $this->visitCount($p3), 'C6: no additional Visit');
        self::assertSame(0, $this->auditCount('APPOINTMENT_RESCHEDULED', $appt3), 'C6: no reschedule audit on rejection');
        self::assertContains($appt3, $this->boardAppointmentIds($this->dispatch('GET', self::BOARD, [], $headers)), 'C6: the row remains on the board (no false success)');

        // D. No Visit / queue / payment side effects anywhere in this stage.
        self::assertSame(1, $this->visitCountForAppointment($appt3), 'C6: only the pre-existing live Visit exists');
        self::assertSame(0, $this->paymentCount($fx['clinic']), 'C6: NO payment created');
    }

    // ============ C7 — Board outcome for same-day vs future destination ============

    public function testC7_BoardReflectsOldDisappearanceAndSameDayVersusFutureDestination(): void
    {
        $fx = $this->stage('c7');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);
        $today   = $this->localDate(self::TZ);

        // A. Same-day destination: the old row leaves today's board and the new
        //    confirmed row appears through the EXISTING board read.
        $p1                            = $this->insertPatient($fx['clinic'], 'c7a');
        [$src1, $src1Date, $src1T]     = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 120, 1, ['booked' => 1]);
        $appt1                         = $this->insertAppointment($fx['clinic'], $fx['locA'], $p1, $fx['c1'], $src1, $src1Date, $src1T, 'confirmed');
        [$dest1, $dest1Date, $dest1T]  = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c2'], 180);
        self::assertContains($appt1, $this->boardAppointmentIds($this->dispatch('GET', self::BOARD, [], $headers)), 'C7: the old row starts on the board');

        $sameDay = $this->reschedule($fx, $appt1, $fx['locA'], $fx['c2'], $dest1, $this->uuid());
        self::assertSame(200, $sameDay->get_status(), 'C7: same-day reschedule succeeds — ' . $this->errCode($sameDay));
        $new1 = (int) ($this->payload($sameDay)['appointment']['appointment_id'] ?? 0);

        $this->dispatch('GET', self::BOARD, [], $headers); // existing board refresh
        $board = $this->dispatch('GET', self::BOARD, [], $headers);
        self::assertNotContains($appt1, $this->boardAppointmentIds($board), 'C7: the old row disappears after refresh');
        if ($dest1Date === $today) {
            self::assertContains($new1, $this->boardAppointmentIds($board), 'C7: the new same-day row appears through the existing board read');
            $row = $this->boardRow($board, $new1);
            self::assertSame(substr($dest1T, 0, 5), (string) ($row['time'] ?? ''), 'C7: the board shows the destination time');
            self::assertNull($row['visit_status'] ?? null, 'C7: the replacement is still NOT received (no Visit)');
            self::assertSame($p1, (int) ($row['patient_id'] ?? 0), 'C7: the same patient is carried to the new row');
        } else {
            // Near the end of the local day the only valid future destination is
            // the next local day: the same rule must then keep it OFF today's board.
            self::assertNotContains($new1, $this->boardAppointmentIds($board), 'C7: an off-operational-day destination stays off today\'s board');
        }

        // B. Future destination: the old row leaves today's board and the future
        //    appointment does NOT appear on today's board.
        $p2                        = $this->insertPatient($fx['clinic'], 'c7b');
        [$src2, $src2Date, $src2T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 240, 1, ['booked' => 1]);
        $appt2                     = $this->insertAppointment($fx['clinic'], $fx['locA'], $p2, $fx['c1'], $src2, $src2Date, $src2T, 'confirmed');
        $futureDate                = $this->localDate(self::TZ, '+1 day');
        $dest2                     = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $futureDate, '09:00:00', 1);

        $future = $this->reschedule($fx, $appt2, $fx['locA'], $fx['c1'], $dest2, $this->uuid());
        self::assertSame(200, $future->get_status(), 'C7: future-destination reschedule succeeds — ' . $this->errCode($future));
        $futureView = (array) ($this->payload($future)['appointment'] ?? []);
        $new2       = (int) ($futureView['appointment_id'] ?? 0);
        self::assertSame($futureDate, (string) ($futureView['date'] ?? ''), 'C7: the future operational date is returned');
        self::assertFalse((bool) ($this->payload($future)['reception']['on_operational_day'] ?? true), 'C7: the response marks the destination as off the operational day');

        $board2 = $this->dispatch('GET', self::BOARD, [], $headers);
        self::assertNotContains($appt2, $this->boardAppointmentIds($board2), 'C7: the old row disappears');
        self::assertNotContains($new2, $this->boardAppointmentIds($board2), 'C7: a future appointment stays OFF today\'s board');
        self::assertSame('confirmed', (string) $this->appointmentRow($new2)['status'], 'C7: the future appointment is durably confirmed');
        self::assertSame($futureDate, (string) $this->appointmentRow($new2)['slot_date'], 'C7: the future destination date is persisted');

        // C. Neither operation produced a Visit / queue / walk-in / payment row.
        self::assertSame(0, $this->visitCount($p1) + $this->visitCount($p2), 'C7: NO Visit created by any reschedule');
        self::assertSame(0, $this->queueCount($fx['clinic']), 'C7: NO queue entry created');
        self::assertSame(0, $this->walkInCount($fx['clinic']), 'C7: NO walk-in created');
        self::assertSame(0, $this->paymentCount($fx['clinic']), 'C7: NO payment created');
    }

    // ============ C8 — Slices 1–6 + shared staff reschedule intact (CONTROL) ============

    public function testC8_ControlSlicesOneToSixAndSharedStaffRescheduleRemainIntact(): void
    {
        $fx = $this->stage('c8');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);

        // Slice 1 — Arrival Board: a booked row is received into the queue.
        $p1                        = $this->insertPatient($fx['clinic'], 'c8board');
        [$s1, $s1Date, $s1T]       = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 120);
        $a1                        = $this->insertAppointment($fx['clinic'], $fx['locA'], $p1, $fx['c1'], $s1, $s1Date, $s1T, 'confirmed');
        $board                     = $this->dispatch('GET', self::BOARD, [], $headers);
        self::assertSame(200, $board->get_status(), 'C8: the Slice 1 board answers');
        self::assertContains($a1, $this->boardAppointmentIds($board), 'C8: the board lists the booked row');
        $arr = $this->dispatch('POST', self::ARRIVALS, ['patient_id' => $p1, 'appointment_id' => $a1], $headers);
        self::assertSame(200, $arr->get_status(), 'C8: the Slice 1 arrival answers — ' . $this->errCode($arr));
        self::assertSame('waiting', (string) ($this->payload($arr)['visit']['status'] ?? ''), 'C8: arrival reaches waiting');

        // Slice 2 — read-only Clinic patient search.
        $search = $this->dispatch('GET', self::SEARCH, ['q' => 'c8board'], $headers);
        self::assertSame(200, $search->get_status(), 'C8: the Slice 2 search answers');
        self::assertContains($p1, array_map(static fn($r): int => (int) ($r['id'] ?? 0), (array) $this->payload($search)), 'C8: Slice 2 finds the Clinic patient');

        // Slice 3 — bounded Clinic patient create.
        $create = $this->dispatch('POST', self::PCREATE, [
            'first_name' => 'Slice',
            'last_name'  => 'Reschedule',
            'mobile'     => '0913' . sprintf('%07d', random_int(1000000, 9999999)),
        ], $headers);
        self::assertSame(200, $create->get_status(), 'C8: the Slice 3 create answers — ' . $this->errCode($create));
        self::assertSame(0, $this->visitCount((int) ($this->payload($create)['id'] ?? 0)), 'C8: Slice 3 create starts no Visit');

        // Slice 4 — eligible doctors + walk-in.
        self::assertSame($this->sorted([$fx['c1'], $fx['c2']]), $this->clinicianIds($this->dispatch('GET', self::CLINICIANS, [], $headers)), 'C8: Slice 4 eligibility intact');
        $p4 = $this->insertPatient($fx['clinic'], 'c8wi');
        $wi = $this->dispatch('POST', self::WALK_IN, ['patient_id' => $p4, 'clinician_id' => $fx['c2']], $headers);
        self::assertSame(200, $wi->get_status(), 'C8: the Slice 4 walk-in answers — ' . $this->errCode($wi));
        self::assertSame('waiting', (string) ($this->payload($wi)['visit']['status'] ?? ''), 'C8: walk-in reaches waiting');
        self::assertSame(0, $this->appointmentCountForPatient($p4), 'C8: walk-in still creates no appointment');

        // Slice 5 — appointment create from an already-generated persisted slot.
        $tomorrow = $this->localDate(self::TZ, '+1 day');
        $slot5    = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '09:00:00', 2);
        $read5    = $this->dispatch('GET', self::SLOTS, ['clinician_id' => $fx['c1'], 'date' => $tomorrow], $headers);
        self::assertSame(200, $read5->get_status(), 'C8: the Slice 5 slot read answers — ' . $this->errCode($read5));
        self::assertContains($slot5, $this->slotIds($read5), 'C8: the persisted free slot is offered');
        $p5    = $this->insertPatient($fx['clinic'], 'c8book');
        $book5 = $this->dispatch('POST', self::APPTS, [
            'patient_id'   => $p5,
            'clinician_id' => $fx['c1'],
            'slot_id'      => $slot5,
        ], $headers);
        self::assertSame(200, $book5->get_status(), 'C8: the Slice 5 create answers — ' . $this->errCode($book5));
        self::assertSame('confirmed', (string) ($this->payload($book5)['appointment']['status'] ?? ''), 'C8: Slice 5 produces one confirmed appointment');

        // Slice 6 — cancel a booked appointment at the trusted Location.
        $p6                        = $this->insertPatient($fx['clinic'], 'c8cancel');
        [$slot6, $slot6Date, $slot6T] = $this->insertFutureSlot($fx['clinic'], $fx['locA'], $fx['c1'], 180, 1, ['booked' => 1]);
        $appt6                     = $this->insertAppointment($fx['clinic'], $fx['locA'], $p6, $fx['c1'], $slot6, $slot6Date, $slot6T, 'confirmed');
        $cancel                    = $this->dispatch('POST', self::CANCEL . $appt6 . '/cancel', ['reason' => 'کنترل برش ۶'], $headers);
        self::assertSame(200, $cancel->get_status(), 'C8: the Slice 6 cancel answers — ' . $this->errCode($cancel));
        self::assertSame('cancelled_by_staff', (string) ($this->payload($cancel)['appointment']['status'] ?? ''), 'C8: Slice 6 terminal state');
        self::assertSame(0, (int) $this->slotRow($slot6)['booked_count'], 'C8: Slice 6 releases the slot');

        // SHARED established staff reschedule route (the very service this slice
        // must delegate to): answers 200 for this secretary in this fixture, so
        // bootstrap, migrations, fixtures, the license gate, the Appointment
        // machine, the I-3 active-Visit guard, slot release/claim, audit,
        // reminder cancellation, notification/SMS and idempotency storage all
        // work on this head.
        $ctrlPatient = $this->insertPatient($fx['clinic'], 'c8ctl');
        $ctrlSrc     = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '10:30:00', 1, ['booked' => 1]);
        $ctrlAppt    = $this->insertAppointment($fx['clinic'], $fx['locA'], $ctrlPatient, $fx['c1'], $ctrlSrc, $tomorrow, '10:30:00', 'confirmed');
        $ctrlDest    = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '11:30:00', 1);
        // The established shared route declares slot_date + slot_time as required
        // args (exact slot_id is still the identity authority); the control calls
        // the SHARED contract exactly as its own route contract requires.
        $shared      = $this->dispatchSharedReschedule($fx['clinic'], $ctrlAppt, [
            'clinician_id' => $fx['c1'],
            'slot_date'    => $tomorrow,
            'slot_time'    => '11:30:00',
            'slot_id'      => $ctrlDest,
        ], $this->uuid());
        self::assertSame(200, $shared->get_status(), 'C8: shared established staff reschedule reachable — ' . $this->errCode($shared));
        $sharedView = $this->payload($shared);
        self::assertSame('confirmed', (string) ($sharedView['status'] ?? ''), 'C8: established reschedule produces a confirmed replacement');
        self::assertSame($ctrlAppt, (int) ($sharedView['previous_appointment_id'] ?? 0), 'C8: established old→new relationship');
        self::assertSame('rescheduled', (string) $this->appointmentRow($ctrlAppt)['status'], 'C8: established source state');
        self::assertSame(0, (int) $this->slotRow($ctrlSrc)['booked_count'], 'C8: the shared service releases the old slot');
        self::assertSame(1, (int) $this->slotRow($ctrlDest)['booked_count'], 'C8: the shared service claims the new slot');
        self::assertSame(0, $this->visitCount($ctrlPatient), 'C8: the shared reschedule creates no Visit');

        // UI — every delivered Reception surface remains in the module.
        $html = $this->renderReception($fx['secretary']);
        foreach (['sr-appointments', 'sr-queue', 'sr-search', 'sr-create', 'sr-walkin', 'sr-book', 'sr-location-select', 'sr-cancel-open', 'sr-cancel-form', 'sr-cancel-confirm'] as $role) {
            self::assertStringContainsString('data-role="' . $role . '"', $html, 'C8: delivered surface ' . $role . ' remains');
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
        $org     = $this->insertOrg('AR Org ' . $tag);
        $clinic  = $this->insertClinic('AR Clinic ' . $tag, $org);
        $clinicF = $this->insertClinic('AR Foreign ' . $tag, $org);
        $locA    = $this->insertLocation($clinic, 'AR A ' . $tag, 1);
        $locB    = $this->insertLocation($clinic, 'AR B ' . $tag, 0);
        $locC    = $this->insertLocation($clinic, 'AR C ' . $tag, 0);
        $locF    = $this->insertLocation($clinicF, 'AR F ' . $tag, 1);

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

        // Deterministic Clinic policy for this stage: an open booking horizon, the
        // established staff min-lead of 0, and a log SMS provider.
        $settings = App::settingsFactory()->forClinic($clinic);
        $settings->set('booking.max_future_days', 60);
        $settings->set('booking.min_lead_hours', 2);
        $settings->set('sms.provider', 'log');
        Settings::flushCache();

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
     * One explicit Reception reschedule of a selected source appointment to a
     * selected eligible doctor + persisted destination slot at the given scope.
     *
     * @param array<string, int> $fx
     */
    private function reschedule(array $fx, int $appointmentId, ?int $locationId, int $clinicianId, int $slotId, ?string $idemKey): WP_REST_Response
    {
        return $this->dispatch('POST', self::RESCHEDULE . $appointmentId . '/reschedule', [
            'clinician_id' => $clinicianId,
            'slot_id'      => $slotId,
        ], $this->scopeHeaders($fx['clinic'], $locationId), true, $idemKey);
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
     * The ESTABLISHED shared staff reschedule route is deliberately EXEMPT from
     * the trusted-Clinic binder (`RestClinicContext::requiresTrustedClinic()`
     * skips `/clinic/v1/appointments/{id}/reschedule` because the same route is
     * also the patient-self path). Its staff branch therefore operates on the
     * caller's explicit Clinic scope — exactly as the established Phase 7 staff
     * REST entry does — so this CONTROL installs that explicit scope for the
     * call and clears it again: the rest of the suite keeps proving that the
     * Reception boundary binds its OWN scope from the trusted request headers.
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    private function dispatchSharedReschedule(int $clinicId, int $appointmentId, array $params, string $idemKey): WP_REST_Response
    {
        ScopeContext::clear();
        App::resetScope();
        $request = new WP_REST_Request('POST', self::SHARED . $appointmentId . '/reschedule');
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);
        $request->set_header('Idempotency-Key', $idemKey);

        ScopeContext::set(ClinicScope::forClinic($clinicId));
        try {
            return rest_do_request($request);
        } finally {
            ScopeContext::clear();
            App::resetScope();
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = [], bool $withNonce = true, ?string $idemKey = null): WP_REST_Response
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
        if ($idemKey !== null) {
            $r->set_header('Idempotency-Key', $idemKey);
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

    /**
     * A persisted slot ALWAYS inside the future booking window of the Location
     * frame: the requested minute offset from "now" in that IANA zone (crossing
     * midnight simply lands on the next local day, which stays a valid, distinct
     * and future destination). Distinct offsets are always distinct slot times.
     *
     * @return array{0: string, 1: string}
     */
    private function futureSlot(string $tz, int $minutes): array
    {
        $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz));
        $plus = $now->add(new \DateInterval('PT' . max(1, $minutes) . 'M'));

        return [$plus->format('Y-m-d'), $plus->format('H:i:00')];
    }

    /**
     * Insert one persisted slot at the requested future offset and return
     * [slot_id, slot_date, slot_time] so a booked row can mirror the persisted
     * slot exactly.
     *
     * @param array{booked?: int, held?: int, is_open?: int, duration?: int} $opts
     * @return array{0: int, 1: string, 2: string}
     */
    private function insertFutureSlot(int $clinicId, int $locId, int $clinicianId, int $minutes, int $capacity = 1, array $opts = []): array
    {
        [$date, $time] = $this->futureSlot(self::TZ, $minutes);

        return [$this->insertSlot($clinicId, $locId, $clinicianId, $date, $time, $capacity, $opts), $date, $time];
    }

    private function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex      = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function replacementCount(int $appointmentId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE rescheduled_from = %d',
            [$appointmentId]
        );
    }

    private function rescheduleSmsCount(int $clinicId, int $newAppointmentId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE clinic_id = %d AND event = %s AND context_type = %s AND context_id = %d',
            [$clinicId, SmsEvents::APPT_RESCHEDULED, 'appointment', $newAppointmentId]
        );
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
        $statuses     = self::LIVE_VISIT_STATUSES;
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . " WHERE clinic_id = %d AND status IN ({$placeholders})",
            array_merge([$clinicId], $statuses)
        );
    }

    private function walkInCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . " WHERE clinic_id = %d AND source = 'walk_in'",
            [$clinicId]
        );
    }

    private function paymentCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE clinic_id = %d', [$clinicId]);
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

    /**
     * @return array<string, mixed>
     */
    private function notificationRow(int $notificationId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_notifications') . ' WHERE id = %d LIMIT 1', [$notificationId]);
        self::assertIsArray($row, 'the notification row must exist for id ' . $notificationId);

        return $row;
    }

    /** A queued internal reminder bound to the (old) appointment — existing dedupe shape. */
    private function queueInternalReminder(int $clinicId, int $patientId, int $appointmentId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok  = $wpdb->insert($wpdb->prefix . 'cpms_notifications', [
            'clinic_id'            => $clinicId,
            'recipient_wp_user_id' => 0,
            'recipient_patient_id' => $patientId,
            'channel'              => 'internal',
            'template'             => 'appointment_reminder',
            'payload_json'         => (string) wp_json_encode(['appointment_id' => $appointmentId]),
            'status'               => 'queued',
            'attempts'             => 0,
            'dedupe_key'           => 'apt:' . $appointmentId . ':reminder:' . bin2hex(random_bytes(3)),
            'created_at'           => $now,
        ]);
        self::assertNotFalse($ok, 'reminder fixture insert: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    /** An in-flight (PENDING) idempotency claim in the EXISTING scope shape. */
    private function seedPendingIdempotency(string $key, int $clinicId, int $actorId, int $appointmentId): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'cpms_idempotency_keys', [
            'key'           => $key,
            'clinic_id'     => $clinicId,
            'wp_user_id'    => $actorId,
            'endpoint'      => 'booking/reschedule',
            'context_id'    => $appointmentId,
            'status'        => 0,
            'response_code' => null,
            'response_json' => null,
            'created_at'    => App::db()->nowUtcSql(),
        ]);
        self::assertNotFalse($ok, 'idempotency fixture insert: ' . $wpdb->last_error);
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
            'mrn'        => 'MR-AR-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2)),
            'first_name' => 'Reschedule',
            'last_name'  => 'Patient ' . $tag,
            'mobile'     => '0916' . sprintf('%07d', random_int(1000000, 9999999)),
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
            'ar-' . bin2hex(random_bytes(3)),
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
