<?php
/**
 * Phase 11 (bounded slice) — Staff Portal Reception: Create Appointment for
 * Selected Clinic Patient — TEST-ONLY RED.
 *
 * Slice: inside the EXISTING Staff Portal Reception module an authorized
 * secretary who already selected a Clinic patient (Slices 2/3) works in the
 * already-trusted operational Location, lists the doctors eligible for the
 * trusted Clinic + that Location through the DELIVERED Slice 4 eligibility
 * contract, reads the ALREADY-GENERATED available schedule slots of the
 * selected doctor at that Location for one operational date, explicitly
 * selects ONE real persisted slot and explicitly submits. Creation delegates
 * to the EXISTING `BookingService::createByStaff()` (the same service behind
 * `POST /clinic/v1/appointments` → `BookingController::staffCreate`): ONE
 * confirmed appointment, existing numbering/reference, existing atomic
 * capacity, existing duplicate rule, existing audit. No Visit, no queue
 * entry, no check-in, no walk-in, no invoice/payment, no second scheduler.
 *
 * Live reconstruction (verified, not assumed — 2026-09-27):
 * - authoritative main = origin/main = cbf6e975eecc1009497eef861678904d49cbc304;
 *   open PRs = 0; latest migration = 2026_09_26_0023_handwriting_prescription_paper.php
 *   (this RED adds/reserves no migration).
 * - Phase 11 = STARTED / IN PROGRESS — NOT CLOSED; Slice 1 Arrival Board (#133),
 *   Slice 2 search (#135), Slice 3 patient create (#136), Slice 4 walk-in (#137)
 *   verified in live code.
 * - established staff appointment-create contract (reused, never duplicated):
 *   POST /clinic/v1/appointments → BookingController::staffCreate
 *   (permCap cpms_appt_create + requireClinicPermission cpms_appt_create) →
 *   BookingService::createByStaff(actor, patient, clinician, slot_date,
 *   slot_time, reason, slot_id): license OP_APPOINTMENT_BOOK; trusted Clinic
 *   from ScopeContext/App::scope() (NEVER clinicians.clinic_id); participation
 *   via MembershipRepository::clinician_participates_in (which still honours
 *   the legacy HOME-Clinic compatibility path); active patient of the trusted
 *   Clinic; slot resolution by exact slot_id (SlotRepository::findByIdAndClinic)
 *   with clinician + date/time agreement, else ambiguity-safe tuple
 *   resolution; Location timezone of the SLOT via resolveLocationTimezone;
 *   staff minimum lead 0 + Clinic booking horizon (booking.max_future_days)
 *   via BookingWindow::checkRequestWithTimezone; transaction with
 *   findForUpdate + is_open + findActiveForPatientSlot duplicate (409
 *   CLINIC_DUPLICATE_APPOINTMENT) + capacity (409 CLINIC_SLOT_TAKEN) +
 *   atomicBook; appointment.location_id snapshotted from the slot
 *   (AppointmentRepository::locationForNewAppointment); status confirmed with
 *   the existing machine checks; reference_code AP-YYYYMMDD-NN;
 *   APPOINTMENT_CREATED audit; booking.staff_created op-log; NO hold, NO
 *   notification/SMS, NO Visit, NO invoice.
 * - the PUBLIC availability route (GET /clinic/v1/availability →
 *   BookingService::availability → requireClinician) derives its Clinic from
 *   `clinicians.clinic_id` and is NOT Location-bounded ⇒ deliberately NOT
 *   reused as Reception authority.
 *
 * The RED fails ONLY because the Reception slot-read/appointment-create
 * boundary + UI are missing: bootstrap, migrations and fixtures succeed, the
 * intended Reception routes answer with the canonical missing-route
 * fingerprint (`rest_no_route`) and the rendered module has no appointment
 * surface. Everything this slice leans on is proven GREEN on this very head by
 * the A12 CONTROL: the shared established staff-create route
 * (`POST /clinic/v1/appointments` → `BookingController::staffCreate` →
 * `BookingService::createByStaff`) answers 200 with the established
 * appointmentView for the same secretary in the same fixture, and Slices 1–4
 * (board, search, patient create, eligibility + walk-in) stay intact.
 *
 * TEST GROUP MAP:
 *  A1  Surface + explicit slot create ............................. INTENDED RED
 *  A2  Access: role / capability / nonce / membership .............. INTENDED RED
 *  A3  Location 0/1/N/foreign/inactive/unassigned + switch ......... INTENDED RED
 *  A4  Patient belongs to trusted Clinic; Location ≠ ownership ..... INTENDED RED
 *  A5  Clinician eligibility reuses the delivered contract ......... INTENDED RED
 *  A6  Slot read: persisted only, bounded, no second scheduler ..... INTENDED RED
 *  A7  Date/time authority = Location IANA timezone ................ INTENDED RED
 *  A8  slot_id REQUIRED; free-form date/time never authority ....... INTENDED RED
 *  A9  Delegates to createByStaff; established representation ...... INTENDED RED
 *  A10 Same-day board visibility; future appointment not queued .... INTENDED RED
 *  A11 Duplicate / full / closed / stale / double-submit ........... INTENDED RED
 *  A12 Slices 1–4 + shared staff-create intact .................... CONTROL (pass)
 *
 * Excluded by design: reschedule/cancel, schedule management/generation UI,
 * Visit/check-in/queue/walk-in creation, finance, patient create/edit here,
 * new role/capability/status/machine, migrations, public-availability reuse,
 * free-form time booking, Organization identity activation, shared-route
 * tightening.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase11ReceptionAppointmentCreateRedTest extends WP_UnitTestCase
{
    private const SLOTS      = '/clinic/v1/staff/portal/reception/slots';
    private const APPTS      = '/clinic/v1/staff/portal/reception/appointments';
    private const SHARED     = '/clinic/v1/appointments';
    private const BOARD      = '/clinic/v1/staff/portal/reception/board';
    private const CLINICIANS = '/clinic/v1/staff/portal/reception/clinicians';
    private const WALK_IN    = '/clinic/v1/staff/portal/reception/walk-ins';
    private const ARRIVALS   = '/clinic/v1/staff/portal/reception/arrivals';
    private const SEARCH     = '/clinic/v1/staff/portal/reception/patients/search';
    private const PCREATE    = '/clinic/v1/staff/portal/reception/patients';

    private const TZ = 'Asia/Tehran';

    /** The ONLY keys a Reception slot option may carry (selection, not internals). */
    private const SLOT_KEYS = ['capacity_left', 'duration_min', 'slot_id', 'time'];

    /** The ONLY keys a Reception selectable-day option may carry. */
    private const DAY_KEYS = ['date', 'jalali'];

    /** The established staff-create representation (BookingService::appointmentView). */
    private const APPT_VIEW_KEYS = ['appointment_id', 'date', 'duration_min', 'id', 'is_walkin_express', 'jalali', 'jalali_time', 'reason', 'reference_code', 'status', 'time'];

    /** Static Reception appointment-surface markers (this slice's UI). */
    private const BOOK_MARKERS = [
        'sr-book', 'sr-book-location', 'sr-book-clinician', 'sr-book-date',
        'sr-book-slots', 'sr-book-state', 'sr-book-reason', 'sr-book-submit',
    ];

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

    // ============ A1 — Surface + explicit slot create ============

    public function testA1_SelectedSlotCreatesOneConfirmedAppointmentInsideReception(): void
    {
        $fx = $this->stage('a1');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers  = $this->scopeHeaders($fx['clinic'], $fx['locA']);
        $tomorrow = $this->localDate(self::TZ, '+1 day');

        // UI — Reception carries the compact appointment surface.
        $html = $this->renderReception($fx['secretary']);
        self::assertStringContainsString('data-role="reception-app"', $html, 'A1: reception module renders');
        self::assertStringContainsString('data-role="sr-search-selected"', $html, 'A1: existing selected-patient surface remains');
        foreach (self::BOOK_MARKERS as $role) {
            self::assertStringContainsString('data-role="' . $role . '"', $html, 'A1: appointment surface marker ' . $role);
        }
        self::assertStringContainsString('data-role="sr-book-slot"', $html, 'A1: slot option marker');
        self::assertStringContainsString('/staff/portal/reception/slots', $html, 'A1: UI reads the Reception slot boundary');
        self::assertStringContainsString('/staff/portal/reception/appointments', $html, 'A1: UI posts to the Reception appointment boundary');
        self::assertStringNotContainsString('type="time"', $html, 'A1: no free-form time field as booking authority');

        // REST — the eligible-doctor list is the DELIVERED Slice 4 contract.
        $docs = $this->dispatch('GET', self::CLINICIANS, [], $headers);
        self::assertSame(200, $docs->get_status(), 'A1: Slice 4 clinician eligibility answers — ' . $this->errCode($docs));
        self::assertSame($this->sorted([$fx['c1'], $fx['c2']]), $this->clinicianIds($docs), 'A1: N>1 eligible doctors at Location A');

        // REST — bounded slot read for the selected eligible doctor + date.
        $today    = $this->localDate(self::TZ);
        $openSlot = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, $this->futureTime(self::TZ), 2);
        $read     = $this->slots($fx, $fx['c1'], $fx['locA'], $today);
        self::assertSame(200, $read->get_status(), 'A1: reception slot read answers — ' . $this->errCode($read));
        self::assertContains($openSlot, $this->slotIds($read), 'A1: the real generated slot is offered');

        // REST — explicit slot selection + explicit submit.
        $patient = $this->insertPatient($fx['clinic'], 'a1');
        $res     = $this->create($fx, $patient, $fx['c1'], $openSlot, $fx['locA']);
        self::assertSame(200, $res->get_status(), 'A1: reception appointment create answers — ' . $this->errCode($res));
        $data = $this->payload($res);
        self::assertSame('confirmed', (string) ($data['appointment']['status'] ?? ''), 'A1: ONE confirmed appointment');
        self::assertSame(1, $this->appointmentCountForPatient($patient), 'A1: exactly one appointment row');

        $row = $this->appointmentRow((int) ($data['appointment']['id'] ?? 0));
        self::assertSame($fx['clinic'], (int) $row['clinic_id'], 'A1: trusted Clinic');
        self::assertSame($fx['locA'], (int) $row['location_id'], 'A1: appointment Location comes from the trusted selected slot');
        self::assertSame($fx['c1'], (int) $row['clinician_id'], 'A1: selected eligible doctor');
        self::assertSame($patient, (int) $row['patient_id'], 'A1: selected Clinic patient');
        self::assertSame($openSlot, (int) $row['slot_id'], 'A1: the explicitly selected persisted slot');
        self::assertSame($today, (string) $row['slot_date'], 'A1: persisted slot date');
        self::assertSame(0, $this->visitCount($patient), 'A1: NO Visit created');
        self::assertSame(0, $this->queueCount($fx['clinic']), 'A1: NO queue entry created');
    }

    // ============ A2 — Access ============

    public function testA2_AccessRoleCapabilityNonceMembership(): void
    {
        $fx      = $this->stage('a2');
        $p       = $this->insertPatient($fx['clinic'], 'a2');
        $today   = $this->localDate(self::TZ);
        $slot    = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, $this->futureTime(self::TZ));
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);

        // A. Doctor (not the Reception role) — capability alone is not access.
        wp_set_current_user($fx['doc1']);
        $this->assertDenied($this->slots($fx, $fx['c1'], $fx['locA'], $today), 'A2 doctor slots');
        $this->assertDenied($this->create($fx, $p, $fx['c1'], $slot, $fx['locA']), 'A2 doctor create');

        // B. Accountant with ACTIVE membership — membership alone is not permission.
        $acct = $this->makeUser('qa_a2_acct', RolesAndCapabilities::ROLE_ACCOUNTANT);
        $this->seedMembership($acct, $fx['clinic'], 'cpms_accountant');
        wp_set_current_user($acct);
        $this->assertDenied($this->slots($fx, $fx['c1'], $fx['locA'], $today), 'A2 accountant slots');
        $this->assertDenied($this->create($fx, $p, $fx['c1'], $slot, $fx['locA']), 'A2 accountant create');

        // C. Secretary with a clinic-scoped cpms_appt_create DENY (explicit deny wins).
        $mid = $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        App::membership_service()->set_capability($mid, RolesAndCapabilities::APPT_CREATE, 'deny');
        wp_set_current_user($fx['secretary']);
        $this->assertDenied($this->slots($fx, $fx['c1'], $fx['locA'], $today), 'A2 appt-create-deny slots');
        $this->assertDenied($this->create($fx, $p, $fx['c1'], $slot, $fx['locA']), 'A2 appt-create-deny create');
        App::membership_service()->remove_capability($mid, RolesAndCapabilities::APPT_CREATE);

        // D. Missing nonce (CSRF) on both routes.
        $noNonceRead = $this->dispatch('GET', self::SLOTS, ['clinician_id' => $fx['c1'], 'date' => $today], $headers, false);
        self::assertSame(403, $noNonceRead->get_status(), 'A2: slot read without nonce rejected');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($noNonceRead), 'A2: nonce denial code on read');
        $noNonce = $this->dispatch('POST', self::APPTS, [
            'patient_id' => $p, 'clinician_id' => $fx['c1'], 'slot_id' => $slot,
        ], $headers, false);
        self::assertSame(403, $noNonce->get_status(), 'A2: create without nonce rejected');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($noNonce), 'A2: nonce denial code on create');

        // E. Suspended membership.
        App::membership_service()->suspend_membership($mid);
        $this->assertDenied($this->slots($fx, $fx['c1'], $fx['locA'], $today), 'A2 suspended slots');
        $this->assertDenied($this->create($fx, $p, $fx['c1'], $slot, $fx['locA']), 'A2 suspended create');

        // F. Secretary without membership; anonymous.
        $loner = $this->makeUser('qa_a2_loner', RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($loner);
        $this->assertDenied($this->create($fx, $p, $fx['c1'], $slot, $fx['locA']), 'A2 no-membership create');
        wp_set_current_user(0);
        self::assertContains($this->create($fx, $p, $fx['c1'], $slot, $fx['locA'])->get_status(), [401, 403], 'A2: anonymous denied');

        self::assertSame(0, $this->appointmentCountForPatient($p), 'A2: no denied actor created an appointment');
        self::assertSame(0, $this->visitCount($p), 'A2: no denied actor created a Visit');

        // Positive control in the same stage: the authorized secretary succeeds.
        App::membership_service()->reactivate_membership($mid);
        wp_set_current_user($fx['secretary']);
        self::assertSame(200, $this->slots($fx, $fx['c1'], $fx['locA'], $today)->get_status(), 'A2: authorized secretary reads slots');
        $ok = $this->create($fx, $p, $fx['c1'], $slot, $fx['locA']);
        self::assertSame(200, $ok->get_status(), 'A2: authorized secretary creates — ' . $this->errCode($ok));
        self::assertSame(1, $this->appointmentCountForPatient($p), 'A2: exactly one appointment for the authorized actor');
    }

    // ============ A3 — Location policy ============

    public function testA3_LocationPolicyZeroOneManyForeignInactiveUnassignedAndSwitch(): void
    {
        $tomorrow = $this->localDate(self::TZ, '+1 day');

        // A. 0 eligible Locations ⇒ fail closed (read empty, create denied).
        $zero = $this->stage('a3z');
        $this->seedMembership($zero['secretary'], $zero['clinic'], 'cpms_secretary');
        foreach (['locA', 'locB', 'locC'] as $k) {
            $this->deactivateLocation($zero[$k]);
        }
        wp_set_current_user($zero['secretary']);
        $pz    = $this->insertPatient($zero['clinic'], 'a3z');
        $zslot = $this->insertSlot($zero['clinic'], $zero['locA'], $zero['c1'], $tomorrow, '09:00:00');
        $zr    = $this->dispatch('GET', self::SLOTS, ['clinician_id' => $zero['c1']], $this->scopeHeaders($zero['clinic']));
        self::assertSame(200, $zr->get_status(), 'A3: zero-eligible slot read answers fail-closed — ' . $this->errCode($zr));
        self::assertNull($this->payload($zr)['location_id'] ?? null, 'A3: zero eligible ⇒ no Location resolved');
        self::assertSame([], $this->slotIds($zr), 'A3: zero eligible ⇒ no slots');
        $zw = $this->dispatch('POST', self::APPTS, [
            'patient_id' => $pz, 'clinician_id' => $zero['c1'], 'slot_id' => $zslot,
        ], $this->scopeHeaders($zero['clinic']));
        self::assertSame(403, $zw->get_status(), 'A3: zero eligible ⇒ create fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($zw), 'A3: zero-eligible envelope');
        self::assertSame(0, $this->appointmentCountForPatient($pz), 'A3: zero eligible ⇒ no appointment');

        // B. N>1 without an explicit Location ⇒ REQUIRED (no first-Location fallback).
        $many = $this->stage('a3n');
        $mid  = $this->seedMembership($many['secretary'], $many['clinic'], 'cpms_secretary');
        wp_set_current_user($many['secretary']);
        $pn    = $this->insertPatient($many['clinic'], 'a3n');
        $nslot = $this->insertSlot($many['clinic'], $many['locA'], $many['c1'], $tomorrow, '09:00:00');
        $nr    = $this->dispatch('GET', self::SLOTS, ['clinician_id' => $many['c1']], $this->scopeHeaders($many['clinic']));
        self::assertSame(400, $nr->get_status(), 'A3: N>1 slot read without Location rejected');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($nr), 'A3: N>1 envelope');
        self::assertSame('location_required', (string) ($this->errorData($nr)['reason'] ?? ''), 'A3: location_required reason');
        self::assertSame('location_id', (string) ($this->errorData($nr)['field'] ?? ''), 'A3: the required field is the Location selector');
        self::assertArrayNotHasKey('eligible_location_ids', $this->errorData($nr), 'A3: the denial never leaks eligible Location ids');
        $nw = $this->dispatch('POST', self::APPTS, [
            'patient_id' => $pn, 'clinician_id' => $many['c1'], 'slot_id' => $nslot,
        ], $this->scopeHeaders($many['clinic']));
        self::assertSame(400, $nw->get_status(), 'A3: N>1 create without Location rejected');
        self::assertSame(0, $this->appointmentCountForPatient($pn), 'A3: N>1 without Location ⇒ no appointment');

        // C. Foreign and inactive Location selectors.
        $fr = $this->slots($many, $many['c1'], $many['locF'], null);
        self::assertSame(403, $fr->get_status(), 'A3: foreign Location slot read rejected');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($fr), 'A3: foreign Location envelope');
        self::assertSame(403, $this->create($many, $pn, $many['c1'], $nslot, $many['locF'])->get_status(), 'A3: foreign Location create rejected');
        $this->deactivateLocation($many['locC']);
        self::assertSame(403, $this->create($many, $pn, $many['c1'], $nslot, $many['locC'])->get_status(), 'A3: inactive Location create rejected');
        self::assertSame(0, $this->appointmentCountForPatient($pn), 'A3: foreign/inactive Location ⇒ no appointment');

        // D. Location-scoped secretary: unassigned Location rejected; the ONE
        // eligible Location auto-resolves without any selector.
        App::membership_service()->set_scope_mode($mid, 'location', [$many['locB']]);
        self::assertSame(403, $this->create($many, $pn, $many['c2'], $nslot, $many['locA'])->get_status(), 'A3: unassigned Location rejected');
        $autoSlot = $this->insertSlot($many['clinic'], $many['locB'], $many['c2'], $tomorrow, '10:00:00');
        $auto     = $this->dispatch('GET', self::SLOTS, ['clinician_id' => $many['c2'], 'date' => $tomorrow], $this->scopeHeaders($many['clinic']));
        self::assertSame(200, $auto->get_status(), 'A3: single eligible Location auto-resolves for the slot read — ' . $this->errCode($auto));
        self::assertSame($many['locB'], (int) ($this->payload($auto)['location_id'] ?? 0), 'A3: auto-resolved Location = B');
        self::assertContains($autoSlot, $this->slotIds($auto), 'A3: the auto-resolved Location offers its own slots');
        $aw = $this->dispatch('POST', self::APPTS, [
            'patient_id' => $pn, 'clinician_id' => $many['c2'], 'slot_id' => $autoSlot,
        ], $this->scopeHeaders($many['clinic']));
        self::assertSame(200, $aw->get_status(), 'A3: single eligible Location create succeeds — ' . $this->errCode($aw));
        self::assertSame($many['locB'], (int) $this->appointmentRow((int) ($this->payload($aw)['appointment']['id'] ?? 0))['location_id'], 'A3: the appointment lands in the auto-resolved Location');

        // E. A Location switch invalidates the previous slot context: a slot of
        // Location A is never offered nor bookable while Location B is trusted.
        App::membership_service()->set_scope_mode($mid, 'clinic', []);
        $slotAtA = $this->insertSlot($many['clinic'], $many['locA'], $many['c2'], $tomorrow, '09:30:00');
        $cross   = $this->create($many, $pn, $many['c2'], $slotAtA, $many['locB']);
        self::assertSame(404, $cross->get_status(), 'A3: stale cross-Location slot rejected after a Location switch');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($cross), 'A3: non-enumerating envelope for a cross-Location slot');
        $crossRead = $this->slots($many, $many['c2'], $many['locB'], $tomorrow);
        self::assertSame(200, $crossRead->get_status(), 'A3: slot read at B answers — ' . $this->errCode($crossRead));
        self::assertNotContains($slotAtA, $this->slotIds($crossRead), 'A3: a Location-A slot is never offered at Location B');
        self::assertSame(1, $this->appointmentCountForPatient($pn), 'A3: the stale cross-Location selector created nothing');
    }

    // ============ A4 — Patient ============

    public function testA4_PatientBelongsToTrustedClinicAndIsNotLocationOwned(): void
    {
        $fx       = $this->stage('a4');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $tomorrow = $this->localDate(self::TZ, '+1 day');
        $slot     = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '09:00:00');

        $foreign  = $this->insertPatient($fx['clinicF'], 'a4f');
        $archived = $this->insertPatient($fx['clinic'], 'a4a', 'archived');
        foreach (['foreign' => $foreign, 'archived' => $archived, 'nonexistent' => 999999] as $label => $pid) {
            $res = $this->create($fx, $pid, $fx['c1'], $slot, $fx['locA']);
            self::assertSame(404, $res->get_status(), 'A4: ' . $label . ' patient rejected — ' . $this->errCode($res));
            self::assertSame('CLINIC_NOT_FOUND', $this->errCode($res), 'A4: ' . $label . ' non-enumerating envelope');
            self::assertSame(0, $this->appointmentCountForPatient($pid), 'A4: ' . $label . ' patient got no appointment');
        }
        self::assertContains($this->create($fx, 0, $fx['c1'], $slot, $fx['locA'])->get_status(), [400, 422], 'A4: invalid patient selector rejected');

        // The same patient is bookable at EITHER Location: identity stays
        // Clinic-level and never becomes Location-owned.
        $p      = $this->insertPatient($fx['clinic'], 'a4ok');
        $before = $this->patientRow($p);
        $slotB  = $this->insertSlot($fx['clinic'], $fx['locB'], $fx['c2'], $tomorrow, '11:00:00');
        $atB    = $this->create($fx, $p, $fx['c2'], $slotB, $fx['locB']);
        self::assertSame(200, $atB->get_status(), 'A4: Clinic patient bookable at Location B — ' . $this->errCode($atB));
        $atA = $this->create($fx, $p, $fx['c1'], $slot, $fx['locA']);
        self::assertSame(200, $atA->get_status(), 'A4: the same Clinic patient bookable at Location A — ' . $this->errCode($atA));
        $after = $this->patientRow($p);
        self::assertArrayNotHasKey('location_id', $after, 'A4: the patients table carries no Location ownership');
        self::assertSame($before, $after, 'A4: booking does not modify the patient identity row');
        self::assertSame($fx['clinic'], (int) $after['clinic_id'], 'A4: patient remains Clinic-level');
        self::assertSame(2, $this->appointmentCountForPatient($p), 'A4: two appointments, one per Location');
    }

    // ============ A5 — Clinician eligibility ============

    public function testA5_ClinicianEligibilityReusesTheDeliveredReceptionContract(): void
    {
        $fx       = $this->stage('a5');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $tomorrow = $this->localDate(self::TZ, '+1 day');
        $p        = $this->insertPatient($fx['clinic'], 'a5');

        // Every non-eligible doctor is rejected with the SAME non-enumerating
        // fingerprint, even when a raw slot row for that doctor exists.
        foreach (
            [
                'home-decoy'     => [$fx['c3'], $fx['locA']],
                'inactive'       => [$fx['c4'], $fx['locA']],
                'foreign'        => [$fx['c5'], $fx['locA']],
                'suspended'      => [$fx['c6'], $fx['locA']],
                'other-location' => [$fx['c7'], $fx['locA']],
                'A-doctor-at-B'  => [$fx['c1'], $fx['locB']],
                'nonexistent'    => [999999, $fx['locA']],
            ] as $label => [$cid, $loc]
        ) {
            // A raw slot row for the selector (only insertable for a real
            // clinician — the FK is part of the existing schema).
            $rawSlot = $cid === 999999 ? 999999 : $this->insertSlot($fx['clinic'], $loc, $cid, $tomorrow, '08:00:00');
            $read    = $this->slots($fx, $cid, $loc, $tomorrow);
            self::assertSame(404, $read->get_status(), 'A5: ' . $label . ' slot read rejected — ' . $this->errCode($read));
            self::assertSame('CLINIC_NOT_FOUND', $this->errCode($read), 'A5: ' . $label . ' non-enumerating read envelope');
            $res = $this->create($fx, $p, $cid, $rawSlot, $loc);
            self::assertSame(404, $res->get_status(), 'A5: ' . $label . ' create rejected — ' . $this->errCode($res));
            self::assertSame('CLINIC_NOT_FOUND', $this->errCode($res), 'A5: ' . $label . ' non-enumerating create envelope');
        }
        self::assertSame(0, $this->appointmentCountForPatient($p), 'A5: no rejected doctor created an appointment');

        // The 0/1/N behaviour of the DELIVERED eligibility contract is
        // preserved and the slot read agrees with it.
        self::assertSame($this->sorted([$fx['c1'], $fx['c2']]), $this->clinicianIds($this->dispatch('GET', self::CLINICIANS, [], $this->scopeHeaders($fx['clinic'], $fx['locA']))), 'A5: N=2 at Location A');
        self::assertSame([$fx['c2']], $this->clinicianIds($this->dispatch('GET', self::CLINICIANS, [], $this->scopeHeaders($fx['clinic'], $fx['locB']))), 'A5: N=1 at Location B');
        self::assertSame([], $this->clinicianIds($this->dispatch('GET', self::CLINICIANS, [], $this->scopeHeaders($fx['clinic'], $fx['locC']))), 'A5: N=0 at Location C');
        self::assertSame(404, $this->slots($fx, $fx['c1'], $fx['locC'], $tomorrow)->get_status(), 'A5: a doctor not eligible at Location C has no Reception slots there');

        // c2 (home Clinic is FOREIGN) is eligible through durable participation.
        $slotC2 = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c2'], $tomorrow, '12:00:00');
        $readC2 = $this->slots($fx, $fx['c2'], $fx['locA'], $tomorrow);
        self::assertSame(200, $readC2->get_status(), 'A5: shared professional slot read — ' . $this->errCode($readC2));
        self::assertContains($slotC2, $this->slotIds($readC2), 'A5: shared professional slot offered');
        self::assertSame($fx['clinicF'], $this->clinicianHomeClinic($fx['c2']), 'A5: home-Clinic metadata is untouched and is not authority');
    }

    // ============ A6 — Slot read ============

    public function testA6_SlotReadIsPersistedOnlyBoundedAndNotAScheduler(): void
    {
        $fx       = $this->stage('a6');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $today    = $this->localDate(self::TZ);
        $tomorrow = $this->localDate(self::TZ, '+1 day');

        // A weekly schedule template WITHOUT generated slots must not produce
        // slots: Reception reads already-generated rows only (no second
        // scheduler, no lazy generation, no fabrication).
        $dow = (int) (new \DateTimeImmutable($tomorrow . ' 00:00:00', new \DateTimeZone('UTC')))->format('N');
        $this->insertScheduleTemplate($fx['clinic'], $fx['locA'], $fx['c1'], $dow);
        $slotsBefore = $this->slotCount($fx['clinic']);
        self::assertSame(0, $slotsBefore, 'A6 precondition: no slot exists yet');
        $empty = $this->slots($fx, $fx['c1'], $fx['locA'], $tomorrow);
        self::assertSame(200, $empty->get_status(), 'A6: an empty day answers — ' . $this->errCode($empty));
        self::assertSame([], $this->slotIds($empty), 'A6: a template without generated slots offers nothing');
        self::assertSame(0, $this->slotCount($fx['clinic']), 'A6: the read generated no slot row');

        // Bounded by trusted Clinic + Location + clinician + date, and only
        // open slots with real free capacity.
        $mine     = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '10:00:00', 3);
        $otherDoc = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c2'], $tomorrow, '10:00:00');
        $otherLoc = $this->insertSlot($fx['clinic'], $fx['locB'], $fx['c1'], $tomorrow, '10:00:00');
        $otherDay = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $this->localDate(self::TZ, '+2 day'), '10:00:00');
        $foreign  = $this->insertSlot($fx['clinicF'], $fx['locF'], $fx['c5'], $tomorrow, '10:00:00');
        $closed   = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '11:00:00', 1, ['is_open' => 0]);
        $full     = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '12:00:00', 1, ['booked' => 1]);
        $held     = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '13:00:00', 1, ['held' => 1]);
        $read     = $this->slots($fx, $fx['c1'], $fx['locA'], $tomorrow);
        self::assertSame(200, $read->get_status(), 'A6: bounded read answers — ' . $this->errCode($read));
        $ids = $this->slotIds($read);
        self::assertSame([$mine], $ids, 'A6: only the trusted Clinic + Location + clinician + date slot is offered');
        self::assertNotContains($otherDoc, $ids, 'A6: another doctor slot excluded');
        self::assertNotContains($otherLoc, $ids, 'A6: another Location slot excluded');
        self::assertNotContains($otherDay, $ids, 'A6: another date slot excluded');
        self::assertNotContains($foreign, $ids, 'A6: foreign-Clinic slot excluded');
        self::assertNotContains($closed, $ids, 'A6: closed slot excluded');
        self::assertNotContains($full, $ids, 'A6: full slot excluded');
        self::assertNotContains($held, $ids, 'A6: a fully held slot is excluded (staff booking needs real free capacity)');
        self::assertSame(7, $this->slotCount($fx['clinic']), 'A6: the read fabricated no slot');
        self::assertSame(1, $this->slotCount($fx['clinicF']), 'A6: the foreign slot stayed in the foreign Clinic');

        // Bounded payload: selection fields only, no scheduling internals.
        $data = $this->payload($read);
        self::assertSame($tomorrow, (string) ($data['date'] ?? ''), 'A6: the queried Location-local date is echoed');
        self::assertSame($fx['locA'], (int) ($data['location_id'] ?? 0), 'A6: trusted Location echoed');
        self::assertSame($fx['c1'], (int) ($data['clinician_id'] ?? 0), 'A6: selected eligible doctor echoed');
        $rows = (array) ($data['slots'] ?? []);
        self::assertCount(1, $rows, 'A6: one bounded slot row');
        foreach ($rows as $row) {
            $keys = array_keys((array) $row);
            sort($keys);
            self::assertSame(self::SLOT_KEYS, $keys, 'A6: a slot option carries only slot_id/time/duration_min/capacity_left');
        }
        self::assertSame(3, (int) ($rows[0]['capacity_left'] ?? 0), 'A6: remaining-capacity indicator');
        self::assertSame('10:00', (string) ($rows[0]['time'] ?? ''), 'A6: Location-local start time HH:MM');
        self::assertSame(20, (int) ($rows[0]['duration_min'] ?? 0), 'A6: duration');
        self::assertSame($mine, (int) ($rows[0]['slot_id'] ?? 0), 'A6: the persisted slot_id selector');

        $days = (array) ($data['days'] ?? []);
        self::assertNotEmpty($days, 'A6: bounded selectable-day options for the Persian date selector');
        self::assertLessThanOrEqual(61, count($days), 'A6: day options are bounded by the Clinic booking horizon');
        foreach ($days as $day) {
            $keys = array_keys((array) $day);
            sort($keys);
            self::assertSame(self::DAY_KEYS, $keys, 'A6: a day option carries only date/jalali');
        }
        self::assertSame($today, (string) ($days[0]['date'] ?? ''), 'A6: day options start at the Location-local operational day');
        self::assertMatchesRegularExpression('/^\d{4}\/\d{2}\/\d{2}$/', (string) ($days[0]['jalali'] ?? ''), 'A6: existing Jalali presentation utility');
        $json = (string) wp_json_encode($data);
        foreach (['booked_count', 'held_count', 'generated_from', 'is_open', '"capacity"', 'wp_user_id', 'national_id', 'mobile'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'A6: the payload must not expose ' . $needle);
        }

        // A malformed date selector is rejected, never fabricated.
        foreach (['not-a-date', '2026-13-45', '20260101'] as $bad) {
            $res = $this->dispatch('GET', self::SLOTS, ['clinician_id' => $fx['c1'], 'date' => $bad], $this->scopeHeaders($fx['clinic'], $fx['locA']));
            self::assertSame(422, $res->get_status(), 'A6: malformed date "' . $bad . '" rejected');
            self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($res), 'A6: malformed date envelope');
        }
        self::assertSame(7, $this->slotCount($fx['clinic']), 'A6: rejected selectors fabricated nothing');
    }

    // ============ A7 — Date / timezone authority ============

    public function testA7_OperationalDateComesFromTheLocationTimezoneOnly(): void
    {
        $fx = $this->stage('a7');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        // At ANY instant at least one of these two frames differs from the UTC
        // frame, so the assertion below is deterministic and discriminating
        // (no time freeze, no hardcoded Tehran).
        $utcToday = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $plus     = (new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Kiritimati')))->format('Y-m-d');
        $minus    = (new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Midway')))->format('Y-m-d');
        $tz       = $plus !== $utcToday ? 'Pacific/Kiritimati' : 'Pacific/Midway';
        $local    = $plus !== $utcToday ? $plus : $minus;
        self::assertNotSame($utcToday, $local, 'A7 precondition: the Location frame differs from the UTC/server frame');

        $locTz = $this->insertLocation($fx['clinic'], 'A7 TZ Loc', 0, $tz);
        App::membership_service()->assign_clinician_locations($fx['c1'], [$fx['locA'], $locTz], $fx['locA']);

        // A slot persisted on the UTC-frame date must NOT be offered when the
        // Location-local operational day is a different calendar date.
        $wrongFrame = $this->insertSlot($fx['clinic'], $locTz, $fx['c1'], $utcToday, '23:30:00');
        $rightFrame = $this->insertSlot($fx['clinic'], $locTz, $fx['c1'], $local, '23:30:00');
        self::assertNotSame($wrongFrame, $rightFrame, 'A7 precondition: two distinct persisted slots');
        $read = $this->slots($fx, $fx['c1'], $locTz, null);
        self::assertSame(200, $read->get_status(), 'A7: slot read answers — ' . $this->errCode($read));
        $data = $this->payload($read);
        self::assertSame($local, (string) ($data['date'] ?? ''), 'A7: the default operational date is the Location-local day');
        self::assertSame($local, (string) ($data['operational_date'] ?? ''), 'A7: operational_date is the Location-local day');
        self::assertNotSame($utcToday, (string) ($data['date'] ?? ''), 'A7: never the UTC/server frame');
        self::assertSame([$rightFrame], $this->slotIds($read), 'A7: only the Location-local day slot is offered');

        // The Clinic timezone is not authority either (this Clinic is
        // Asia/Tehran while this Location is not).
        self::assertSame(self::TZ, $this->clinicTimezone($fx['clinic']), 'A7 precondition: the Clinic timezone differs from the Location timezone');
        $clinicToday = $this->localDate(self::TZ);
        if ($clinicToday !== $local) {
            self::assertNotSame($clinicToday, (string) ($data['date'] ?? ''), 'A7: never the Clinic timezone frame');
        }

        // An explicit date is a SELECTOR for querying valid slots, never
        // authority to fabricate one: a date with no persisted slot is empty.
        $far = $this->localDate($tz, '+3 day');
        $sel = $this->slots($fx, $fx['c1'], $locTz, $far);
        self::assertSame(200, $sel->get_status(), 'A7: an explicit date answers');
        self::assertSame($far, (string) ($this->payload($sel)['date'] ?? ''), 'A7: the explicit date is echoed');
        self::assertSame([], $this->slotIds($sel), 'A7: a date without persisted slots offers nothing');

        // A locally-past slot on the operational day is never offered.
        $past  = $this->insertSlot($fx['clinic'], $locTz, $fx['c1'], $local, '00:00:00');
        $again = $this->slots($fx, $fx['c1'], $locTz, $local);
        self::assertNotContains($past, $this->slotIds($again), 'A7: an already-started slot on the operational day is not offered');
        self::assertContains($rightFrame, $this->slotIds($again), 'A7: the still-future slot on the same day remains offered');
    }

    // ============ A8 — slot_id REQUIRED ============

    public function testA8_SlotIdRequiredAndFreeFormDateTimeIsNeverAuthority(): void
    {
        $fx       = $this->stage('a8');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $tomorrow = $this->localDate(self::TZ, '+1 day');
        $p        = $this->insertPatient($fx['clinic'], 'a8');
        $slot     = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '09:00:00');

        // A. No slot_id at all ⇒ rejected (free-form date/time cannot book).
        $noSlot = $this->dispatch('POST', self::APPTS, [
            'patient_id'   => $p,
            'clinician_id' => $fx['c1'],
            'slot_date'    => $tomorrow,
            'slot_time'    => '09:00:00',
        ], $this->scopeHeaders($fx['clinic'], $fx['locA']));
        self::assertContains($noSlot->get_status(), [400, 422], 'A8: create without slot_id rejected — ' . $this->errCode($noSlot));
        self::assertSame(0, $this->appointmentCountForPatient($p), 'A8: no appointment from a free-form date/time');

        // B. Zero / negative / unknown slot_id.
        foreach ([0, 999999] as $bad) {
            $res = $this->dispatch('POST', self::APPTS, [
                'patient_id' => $p, 'clinician_id' => $fx['c1'], 'slot_id' => $bad,
            ], $this->scopeHeaders($fx['clinic'], $fx['locA']));
            self::assertContains($res->get_status(), [404, 422], 'A8: slot_id=' . $bad . ' rejected — ' . $this->errCode($res));
        }
        self::assertSame(0, $this->appointmentCountForPatient($p), 'A8: no appointment from an invalid slot selector');

        // C. A valid slot_id wins over contradicting free-form date/time: the
        // persisted slot is the ONLY authority for the created appointment.
        $res = $this->dispatch('POST', self::APPTS, [
            'patient_id'   => $p,
            'clinician_id' => $fx['c1'],
            'slot_id'      => $slot,
            'slot_date'    => $this->localDate(self::TZ, '+9 day'),
            'slot_time'    => '23:45:00',
            'location_id'  => $fx['locA'],
        ], $this->scopeHeaders($fx['clinic'], $fx['locA']));
        self::assertSame(200, $res->get_status(), 'A8: create answers — ' . $this->errCode($res));
        $row = $this->appointmentRow((int) ($this->payload($res)['appointment']['id'] ?? 0));
        self::assertSame($tomorrow, (string) $row['slot_date'], 'A8: the persisted slot date, never the client date');
        self::assertSame('09:00:00', substr((string) $row['slot_time'], 0, 8), 'A8: the persisted slot time, never the client time');
        self::assertSame($slot, (int) $row['slot_id'], 'A8: the persisted slot');
        self::assertSame(1, $this->appointmentCountForPatient($p), 'A8: exactly one appointment');
    }

    // ============ A9 — Delegation to the existing contract ============

    public function testA9_DelegatesToExistingCreateByStaffWithoutSideEffects(): void
    {
        $fx       = $this->stage('a9');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $tomorrow = $this->localDate(self::TZ, '+1 day');
        $p        = $this->insertPatient($fx['clinic'], 'a9');
        $slot     = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '09:30:00', 1, ['duration' => 30]);

        $res = $this->create($fx, $p, $fx['c1'], $slot, $fx['locA'], ['reason' => 'دلیل مراجعه']);
        self::assertSame(200, $res->get_status(), 'A9: create answers — ' . $this->errCode($res));
        $data = $this->payload($res);

        // Established representation, unchanged.
        self::assertSame(['appointment', 'reception'], $this->sortedKeys($data), 'A9: a bounded Reception envelope around the established view');
        $view = (array) ($data['appointment'] ?? []);
        self::assertSame(self::APPT_VIEW_KEYS, $this->sortedKeys($view), 'A9: the established appointmentView representation');
        self::assertSame('confirmed', (string) ($view['status'] ?? ''), 'A9: confirmed status');
        self::assertSame($tomorrow, (string) ($view['date'] ?? ''), 'A9: established date key');
        self::assertSame('09:30', (string) ($view['time'] ?? ''), 'A9: established HH:MM time key');
        self::assertMatchesRegularExpression('/^\d{4}\/\d{2}\/\d{2}$/', (string) ($view['jalali'] ?? ''), 'A9: established Jalali presentation');
        self::assertSame(30, (int) ($view['duration_min'] ?? 0), 'A9: duration snapshot from the slot');
        self::assertSame('دلیل مراجعه', (string) ($view['reason'] ?? ''), 'A9: the bounded optional reason is supported by the existing contract');

        $id  = (int) ($view['id'] ?? 0);
        $row = $this->appointmentRow($id);
        self::assertSame($id, (int) ($view['appointment_id'] ?? -1), 'A9: established appointment_id key');
        self::assertMatchesRegularExpression('/^AP-\d{8}-[0-9A-Z]{2,5}$/', (string) ($view['reference_code'] ?? ''), 'A9: existing appointment numbering/reference');
        self::assertSame((string) $row['reference_code'], (string) ($view['reference_code'] ?? ''), 'A9: the reference is persisted');
        self::assertSame('confirmed', (string) $row['status'], 'A9: persisted confirmed');
        self::assertNotEmpty($row['confirmed_at'], 'A9: confirmed_at set by the existing contract');
        self::assertNotEmpty($row['booked_at'], 'A9: booked_at set by the existing contract');
        self::assertSame(30, (int) $row['duration_min'], 'A9: the duration snapshot is persisted');
        self::assertSame('10:00:00', substr((string) $row['slot_end_time'], 0, 8), 'A9: existing slot_end_time derivation');
        self::assertSame($fx['clinic'], (int) $row['clinic_id'], 'A9: Clinic from the trusted slot');
        self::assertSame($fx['locA'], (int) $row['location_id'], 'A9: Location snapshot from the trusted slot');

        // Existing capacity + audit; NO hold, NO notification.
        self::assertSame(1, (int) $this->slotRow($slot)['booked_count'], 'A9: the existing atomic capacity incremented once');
        self::assertGreaterThanOrEqual(1, $this->auditCount('APPOINTMENT_CREATED', $id), 'A9: the existing APPOINTMENT_CREATED audit');
        self::assertSame(0, $this->holdCount($fx['clinic']), 'A9: a staff create needs no public hold');
        self::assertSame(0, $this->notificationCount($fx['clinic']), 'A9: no notification outside the existing create contract');
        self::assertSame(0, $this->smsCount($fx['clinic']), 'A9: no SMS outside the existing create contract');

        // NO Visit / queue / check-in / walk-in / finance.
        self::assertSame(0, $this->visitCount($p), 'A9: no Visit');
        self::assertSame(0, $this->visitCountForAppointment($id), 'A9: no Visit bound to the appointment');
        self::assertSame(0, $this->walkInVisitCount($p), 'A9: no walk-in');
        self::assertSame(0, $this->queueCount($fx['clinic']), 'A9: no queue entry');
        self::assertSame(0, $this->visitHistoryCount($fx['clinic']), 'A9: no visit status history');
        self::assertEmpty($row['active_visit_id'], 'A9: the appointment is not linked to any Visit');
        self::assertSame(0, $this->invoiceCount($fx['clinic']), 'A9: no invoice');
        self::assertSame(0, $this->paymentCount($fx['clinic']), 'A9: no payment');
    }

    // ============ A10 — Board visibility ============

    public function testA10_SameDayAppearsOnTheExistingBoardAndFutureStaysOutOfTheQueue(): void
    {
        $fx      = $this->stage('a10');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);
        $today   = $this->localDate(self::TZ);

        // Same operational day ⇒ the EXISTING board read shows it naturally.
        $pToday   = $this->insertPatient($fx['clinic'], 'a10t', 'active', null, 'Board');
        $slotNow  = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, $this->futureTime(self::TZ));
        $resToday = $this->create($fx, $pToday, $fx['c1'], $slotNow, $fx['locA']);
        self::assertSame(200, $resToday->get_status(), 'A10: same-day create answers — ' . $this->errCode($resToday));
        self::assertTrue((bool) ($this->payload($resToday)['reception']['on_operational_day'] ?? false), 'A10: flagged as belonging to the operational day');
        $todayId = (int) ($this->payload($resToday)['appointment']['id'] ?? 0);

        $board = $this->dispatch('GET', self::BOARD, [], $headers);
        self::assertSame(200, $board->get_status(), 'A10: the Slice 1 board answers');
        self::assertSame($today, (string) ($this->payload($board)['date'] ?? ''), 'A10: board operational day = Location-local today');
        $boardIds = array_map(static fn($r): int => (int) ($r['id'] ?? 0), (array) ($this->payload($board)['appointments'] ?? []));
        self::assertContains($todayId, $boardIds, 'A10: the same-day appointment naturally appears on the existing board');
        self::assertSame(0, $this->queueCount($fx['clinic']), 'A10: a booked appointment is NOT a queue entry');
        self::assertSame(0, $this->visitCount($pToday), 'A10: no Visit / check-in / walk-in');

        // Future day ⇒ a future confirmed appointment, still out of the queue
        // and out of today's board.
        $future  = $this->localDate(self::TZ, '+5 day');
        $pFuture = $this->insertPatient($fx['clinic'], 'a10f', 'active', null, 'Future');
        $slotF   = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $future, '10:00:00');
        $resF    = $this->create($fx, $pFuture, $fx['c1'], $slotF, $fx['locA']);
        self::assertSame(200, $resF->get_status(), 'A10: future create answers — ' . $this->errCode($resF));
        self::assertFalse((bool) ($this->payload($resF)['reception']['on_operational_day'] ?? true), 'A10: a future appointment is not on the operational day');
        $futureId = (int) ($this->payload($resF)['appointment']['id'] ?? 0);
        $fRow     = $this->appointmentRow($futureId);
        self::assertSame('confirmed', (string) $fRow['status'], 'A10: the future appointment stays confirmed');
        self::assertSame($future, (string) $fRow['slot_date'], 'A10: the future date is persisted');
        self::assertNotContains($futureId, $boardIds, 'A10: the future appointment is not on today board');
        self::assertSame(0, $this->queueCount($fx['clinic']), 'A10: a future appointment never enters the queue');
        self::assertSame(0, $this->visitCount($pFuture), 'A10: a future appointment creates no Visit');
        self::assertSame(0, $this->visitCountForAppointment($futureId), 'A10: no Visit bound to the future appointment');
    }

    // ============ A11 — Duplicates / capacity / stale ============

    public function testA11_DuplicateFullClosedStaleAndDoubleSubmit(): void
    {
        $fx       = $this->stage('a11');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $tomorrow = $this->localDate(self::TZ, '+1 day');

        // A. Patient duplicate on the same active slot + accidental double submit.
        $p    = $this->insertPatient($fx['clinic'], 'a11d');
        $slot = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '09:00:00', 3);
        $one  = $this->create($fx, $p, $fx['c1'], $slot, $fx['locA']);
        self::assertSame(200, $one->get_status(), 'A11: the first create answers — ' . $this->errCode($one));
        $two = $this->create($fx, $p, $fx['c1'], $slot, $fx['locA']);
        self::assertSame(409, $two->get_status(), 'A11: the established duplicate rule is preserved');
        self::assertSame('CLINIC_DUPLICATE_APPOINTMENT', $this->errCode($two), 'A11: duplicate envelope');
        self::assertSame(1, $this->appointmentCountForPatient($p), 'A11: a double submit creates no second booking row');
        self::assertSame(1, (int) $this->slotRow($slot)['booked_count'], 'A11: capacity consumed exactly once');

        // B. Full slot for another patient.
        $p2   = $this->insertPatient($fx['clinic'], 'a11f');
        $full = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '10:00:00', 1, ['booked' => 1]);
        $res  = $this->create($fx, $p2, $fx['c1'], $full, $fx['locA']);
        self::assertSame(409, $res->get_status(), 'A11: a full slot is rejected');
        self::assertSame('CLINIC_SLOT_TAKEN', $this->errCode($res), 'A11: established full-slot envelope');
        self::assertSame(0, $this->appointmentCountForPatient($p2), 'A11: no appointment on a full slot');

        // C. Closed slot.
        $p3     = $this->insertPatient($fx['clinic'], 'a11c');
        $closed = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '11:00:00', 1, ['is_open' => 0]);
        $res3   = $this->create($fx, $p3, $fx['c1'], $closed, $fx['locA']);
        self::assertSame(404, $res3->get_status(), 'A11: a closed slot is rejected');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($res3), 'A11: established closed-slot envelope');
        self::assertSame(0, $this->appointmentCountForPatient($p3), 'A11: no appointment on a closed slot');

        // D. Stale client availability: the slot filled between read and submit.
        $p4    = $this->insertPatient($fx['clinic'], 'a11s');
        $stale = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $tomorrow, '12:00:00', 1);
        self::assertContains($stale, $this->slotIds($this->slots($fx, $fx['c1'], $fx['locA'], $tomorrow)), 'A11 precondition: the slot was offered while free');
        $this->fillSlot($stale);
        $res4 = $this->create($fx, $p4, $fx['c1'], $stale, $fx['locA']);
        self::assertSame(409, $res4->get_status(), 'A11: stale availability fails honestly');
        self::assertSame('CLINIC_SLOT_TAKEN', $this->errCode($res4), 'A11: stale-slot envelope');
        self::assertSame(0, $this->appointmentCountForPatient($p4), 'A11: no appointment from stale availability');
        self::assertNotContains($stale, $this->slotIds($this->slots($fx, $fx['c1'], $fx['locA'], $tomorrow)), 'A11: the filled slot is no longer offered');

        // E. Outside the Clinic booking horizon.
        $p5   = $this->insertPatient($fx['clinic'], 'a11h');
        $far  = $this->localDate(self::TZ, '+120 day');
        $fs   = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $far, '09:00:00');
        $res5 = $this->create($fx, $p5, $fx['c1'], $fs, $fx['locA']);
        self::assertSame(409, $res5->get_status(), 'A11: outside the booking horizon rejected');
        self::assertSame('CLINIC_POLICY_VIOLATION', $this->errCode($res5), 'A11: established horizon envelope');
        self::assertSame(0, $this->appointmentCountForPatient($p5), 'A11: no appointment outside the horizon');
        self::assertSame([], $this->slotIds($this->slots($fx, $fx['c1'], $fx['locA'], $far)), 'A11: an out-of-horizon day offers nothing');

        // F. Unrelated failures are NOT converted into booking conflicts: an
        // unknown patient keeps the non-enumerating selector fingerprint.
        $res6 = $this->create($fx, 987654, $fx['c1'], $slot, $fx['locA']);
        self::assertSame(404, $res6->get_status(), 'A11: an unknown patient is not a conflict');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($res6), 'A11: unknown-patient envelope');
        self::assertSame(1, $this->appointmentCountForPatient($p), 'A11: the rejected selectors changed nothing');
    }

    // ============ A12 — Slices 1–4 + shared create intact (CONTROL) ============

    public function testA12_Slices1234AndSharedStaffCreateRemainIntact(): void
    {
        $fx      = $this->stage('a12');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['locA']);
        $today   = $this->localDate(self::TZ);
        $time    = $this->futureTime(self::TZ);

        // Slice 1 — Arrival Board: a booked row is received into the queue.
        $p1    = $this->insertPatient($fx['clinic'], 'a12board');
        $s1    = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $today, $time);
        $a1    = $this->insertAppointment($fx['clinic'], $fx['locA'], $p1, $fx['c1'], $s1, $today, $time);
        $board = $this->dispatch('GET', self::BOARD, [], $headers);
        self::assertSame(200, $board->get_status(), 'A12: the Slice 1 board answers');
        self::assertContains($a1, array_map(static fn($r): int => (int) ($r['id'] ?? 0), (array) ($this->payload($board)['appointments'] ?? [])), 'A12: the board lists the booked row');
        $arr = $this->dispatch('POST', self::ARRIVALS, ['patient_id' => $p1, 'appointment_id' => $a1], $headers);
        self::assertSame(200, $arr->get_status(), 'A12: the Slice 1 arrival answers — ' . $this->errCode($arr));
        self::assertSame('waiting', (string) ($this->payload($arr)['visit']['status'] ?? ''), 'A12: arrival reaches waiting');

        // Slice 2 — read-only Clinic patient search.
        $search = $this->dispatch('GET', self::SEARCH, ['q' => 'a12board'], $headers);
        self::assertSame(200, $search->get_status(), 'A12: the Slice 2 search answers');
        self::assertContains($p1, array_map(static fn($r): int => (int) ($r['id'] ?? 0), (array) $this->payload($search)), 'A12: Slice 2 finds the Clinic patient');

        // Slice 3 — bounded Clinic patient create.
        $create = $this->dispatch('POST', self::PCREATE, [
            'first_name' => 'Slice',
            'last_name'  => 'Five',
            'mobile'     => '0914' . sprintf('%07d', random_int(1000000, 9999999)),
        ], $headers);
        self::assertSame(200, $create->get_status(), 'A12: the Slice 3 create answers — ' . $this->errCode($create));
        self::assertSame(0, $this->visitCount((int) ($this->payload($create)['id'] ?? 0)), 'A12: Slice 3 create starts no Visit');

        // Slice 4 — eligible doctors + walk-in.
        self::assertSame($this->sorted([$fx['c1'], $fx['c2']]), $this->clinicianIds($this->dispatch('GET', self::CLINICIANS, [], $headers)), 'A12: Slice 4 eligibility intact');
        $p4 = $this->insertPatient($fx['clinic'], 'a12wi');
        $wi = $this->dispatch('POST', self::WALK_IN, ['patient_id' => $p4, 'clinician_id' => $fx['c2']], $headers);
        self::assertSame(200, $wi->get_status(), 'A12: the Slice 4 walk-in answers — ' . $this->errCode($wi));
        self::assertSame('waiting', (string) ($this->payload($wi)['visit']['status'] ?? ''), 'A12: walk-in reaches waiting');
        self::assertSame(0, $this->appointmentCountForPatient($p4), 'A12: walk-in still creates no appointment');

        // SHARED established staff-create route (the very service this slice
        // must delegate to): answers 200 for this secretary in this fixture, so
        // bootstrap, migrations, fixtures, the license gate and
        // `BookingService::createByStaff` itself all work on this head.
        $ctlTomorrow = $this->localDate(self::TZ, '+1 day');
        $ctlSlot     = $this->insertSlot($fx['clinic'], $fx['locA'], $fx['c1'], $ctlTomorrow, '09:00:00');
        $ctlPatient  = $this->insertPatient($fx['clinic'], 'a12ctl');
        $shared      = $this->dispatch('POST', self::SHARED, [
            'patient_id'   => $ctlPatient,
            'clinician_id' => $fx['c1'],
            'slot_date'    => $ctlTomorrow,
            'slot_time'    => '09:00:00',
            'slot_id'      => $ctlSlot,
            'reason'       => 'کنترل مسیر مشترک',
        ], $headers);
        self::assertSame(200, $shared->get_status(), 'A12: shared established staff-create reachable — ' . $this->errCode($shared));
        $sharedView = $this->payload($shared);
        self::assertSame('confirmed', (string) ($sharedView['status'] ?? ''), 'A12: established confirmed status');
        self::assertSame(self::APPT_VIEW_KEYS, $this->sortedKeys($sharedView), 'A12: established appointmentView representation');
        self::assertSame(1, $this->appointmentCountForPatient($ctlPatient), 'A12: shared create persisted one appointment');
        self::assertSame(0, $this->visitCount($ctlPatient), 'A12: the shared create starts no Visit');

        // UI — every delivered Reception surface remains in the module.
        $html = $this->renderReception($fx['secretary']);
        foreach (['sr-appointments', 'sr-queue', 'sr-search', 'sr-create', 'sr-walkin', 'sr-location-select'] as $role) {
            self::assertStringContainsString('data-role="' . $role . '"', $html, 'A12: delivered surface ' . $role . ' remains');
        }
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
        $org       = $this->insertOrg('AP Org ' . $tag);
        $clinic    = $this->insertClinic('AP Clinic ' . $tag, $org);
        $clinicF   = $this->insertClinic('AP Foreign ' . $tag, $org);
        $locA      = $this->insertLocation($clinic, 'AP A ' . $tag, 1);
        $locB      = $this->insertLocation($clinic, 'AP B ' . $tag, 0);
        $locC      = $this->insertLocation($clinic, 'AP C ' . $tag, 0);
        $locF      = $this->insertLocation($clinicF, 'AP F ' . $tag, 1);
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

        $doc3 = $this->makeUser('qa_' . $tag . '_d3', RolesAndCapabilities::ROLE_DOCTOR);
        $c3   = $this->insertClinician('Dr Home ' . $tag, $clinic, 1, $doc3);
        $this->rawClinicianLocation($c3, $locA);

        $doc4 = $this->makeUser('qa_' . $tag . '_d4', RolesAndCapabilities::ROLE_DOCTOR);
        $c4   = $this->insertClinician('Dr Inactive ' . $tag, $clinic, 0, $doc4);
        $this->seedMembership($doc4, $clinic, 'cpms_doctor');
        $this->rawClinicianLocation($c4, $locA);

        $doc5 = $this->makeUser('qa_' . $tag . '_d5', RolesAndCapabilities::ROLE_DOCTOR);
        $c5   = $this->insertClinician('Dr Foreign ' . $tag, $clinicF, 1, $doc5);
        $this->seedMembership($doc5, $clinicF, 'cpms_doctor');
        $ms->assign_clinician_locations($c5, [$locF], $locF);

        $doc6 = $this->makeUser('qa_' . $tag . '_d6', RolesAndCapabilities::ROLE_DOCTOR);
        $c6   = $this->insertClinician('Dr Suspended ' . $tag, $clinic, 1, $doc6);
        $m6   = $this->seedMembership($doc6, $clinic, 'cpms_doctor');
        $this->rawClinicianLocation($c6, $locA);
        $ms->suspend_membership($m6);

        $doc7 = $this->makeUser('qa_' . $tag . '_d7', RolesAndCapabilities::ROLE_DOCTOR);
        $c7   = $this->insertClinician('Dr Scoped ' . $tag, $clinic, 1, $doc7);
        $m7   = $this->seedMembership($doc7, $clinic, 'cpms_doctor');
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
    private function create(array $fx, int $patientId, int $clinicianId, int $slotId, ?int $locationId, array $extra = []): WP_REST_Response
    {
        return $this->dispatch('POST', self::APPTS, array_merge([
            'patient_id'   => $patientId,
            'clinician_id' => $clinicianId,
            'slot_id'      => $slotId,
        ], $extra), $this->scopeHeaders($fx['clinic'], $locationId));
    }

    /**
     * @param array<string, int> $fx
     */
    private function slots(array $fx, int $clinicianId, ?int $locationId, ?string $date): WP_REST_Response
    {
        $params = ['clinician_id' => $clinicianId];
        if ($date !== null) {
            $params['date'] = $date;
        }

        return $this->dispatch('GET', self::SLOTS, $params, $this->scopeHeaders($fx['clinic'], $locationId));
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
     * A wall-clock time that is still in the future in the given Location and
     * stays on the same Location-local day (deterministic, no time freeze).
     */
    private function futureTime(string $tz): string
    {
        $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz));
        $plus = $now->add(new \DateInterval('PT2H'));
        if ($plus->format('Y-m-d') !== $now->format('Y-m-d')) {
            // Late local evening: the last second of the same local day.
            return '23:59:59';
        }

        return $plus->format('H:i:00');
    }

    private function visitCount(int $patientId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d', [$patientId]);
    }

    private function walkInVisitCount(int $patientId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . " WHERE patient_id = %d AND source = 'walk_in'", [$patientId]);
    }

    private function visitCountForAppointment(int $appointmentId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits') . ' WHERE appointment_id = %d', [$appointmentId]);
    }

    private function visitHistoryCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visit_status_history') . ' h JOIN ' . App::db()->table('cpms_visits') . ' v ON v.id = h.visit_id WHERE v.clinic_id = %d',
            [$clinicId]
        );
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

    private function holdCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_slot_holds') . ' WHERE clinic_id = %d', [$clinicId]);
    }

    private function notificationCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_notifications') . ' WHERE clinic_id = %d', [$clinicId]);
    }

    private function smsCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE clinic_id = %d', [$clinicId]);
    }

    private function invoiceCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_invoices') . ' WHERE clinic_id = %d', [$clinicId]);
    }

    private function paymentCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE clinic_id = %d', [$clinicId]);
    }

    private function slotCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE clinic_id = %d', [$clinicId]);
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
    private function patientRow(int $patientId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d LIMIT 1', [$patientId]);

        return is_array($row) ? $row : [];
    }

    private function clinicianHomeClinic(int $clinicianId): int
    {
        return (int) App::db()->fetchValue('SELECT clinic_id FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d LIMIT 1', [$clinicianId]);
    }

    private function clinicTimezone(int $clinicId): string
    {
        return (string) App::db()->fetchValue('SELECT timezone FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d LIMIT 1', [$clinicId]);
    }

    private function fillSlot(int $slotId): void
    {
        global $wpdb;
        $ok = $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_schedule_slots SET booked_count = capacity WHERE id = %d', $slotId));
        self::assertNotFalse($ok, 'fill slot: ' . $wpdb->last_error);
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

    private function insertPatient(int $clinicId, string $tag, string $status = 'active', ?string $nationalId = null, string $first = 'Appt'): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok  = $wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id'   => $clinicId,
            'mrn'         => 'MR-AP-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2)),
            'first_name'  => $first,
            'last_name'   => 'Patient ' . $tag,
            'mobile'      => '0914' . sprintf('%07d', random_int(1000000, 9999999)),
            'national_id' => $nationalId,
            'status'      => $status,
            'created_at'  => $now,
            'updated_at'  => $now,
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

    private function insertScheduleTemplate(int $clinicId, int $locId, int $clinicianId, int $dayOfWeek): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok  = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, appointment_duration_min, slot_capacity, is_active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %d, %d, 1, %s, %s)',
            $clinicId,
            $locId,
            $clinicianId,
            $dayOfWeek,
            '08:00:00',
            '14:00:00',
            20,
            1,
            $now,
            $now
        ));
        self::assertNotFalse($ok, 'schedule template fixture insert: ' . $wpdb->last_error);
    }

    private function insertAppointment(int $clinicId, int $locId, int $patientId, int $clinicianId, int $slotId, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s, %s)', $clinicId, $locId, 'ap-' . bin2hex(random_bytes(3)), $patientId, $clinicianId, $slotId, 0, $date, $time, 20, $time, 'confirmed', 0, $now, $now, $now));

        return (int) $wpdb->insert_id;
    }
}
