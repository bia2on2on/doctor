<?php

/**
 * Phase 8 Slice 3 — linked-Patient booking-subject selection (TEST-ONLY RED).
 *
 * ===========================================================================
 * AUTHORITATIVE BOOKING-SUBJECT POLICY (linked-only)
 * ===========================================================================
 *
 * A Patient is selectable for a booking ONLY when:
 *  - authenticated WP user has a durable cpms_patient_user_links row;
 *  - link belongs to the trusted BOOKING Clinic;
 *  - linked Patient belongs to that Clinic;
 *  - Patient is active.
 *
 * Same mobile alone is NOT ownership authority. Never expose all same-mobile
 * Patients; never auto-claim or auto-link by mobile; never merge by mobile;
 * never use findByMobile() as booking-subject authority. Raw patient_id is a
 * selector only.
 *
 * ===========================================================================
 * 0 / 1 / N CONTRACT
 * ===========================================================================
 *
 * Resolve linked active Patients in the trusted booking Clinic.
 *
 * 0 linked:
 *  - booking subject is NEW in this Clinic;
 *  - B1 creates/maintains a Hold with no bound Patient;
 *  - Slice-2 first_name + last_name policy applies at B2;
 *  - same-mobile unlinked Patient MUST NOT be reused or auto-linked.
 *
 * 1 linked:
 *  - automatically select that Patient;
 *  - persist exact Patient subject on the Hold;
 *  - no chooser required;
 *  - B2 uses the bound Patient; names not required and not modified.
 *
 * N linked:
 *  - user MUST explicitly select one;
 *  - B1 without patient_id rejects before Hold/capacity mutation;
 *  - no first row/default Patient;
 *  - B1 with patient_id authorizes it against user's links + booking Clinic
 *    + active status;
 *  - selected Patient is persisted on Hold.
 *
 * ===========================================================================
 * BINDING POINT
 * ===========================================================================
 *
 * Patient subject becomes authoritative at B1 (the Hold is the durable booking
 * continuation object). B2 must not allow subject switching after B1. Once
 * Hold.patient_id is non-null: B2 uses that Patient; client cannot replace it;
 * retries/idempotency preserve the same subject.
 *
 * ===========================================================================
 * FUTURE SCHEMA CONTRACT — DO NOT CREATE IT IN RED
 * ===========================================================================
 *
 * Architecture proof established that cpms_slot_holds needs nullable patient_id:
 *
 *  cpms_slot_holds.patient_id
 *   - BIGINT UNSIGNED; NULL allowed; NO default;
 *   - FK -> cpms_patients(id); ON DELETE RESTRICT;
 *   - no backfill; historical NULL rows supported;
 *   - no explicit index unless objectively required;
 *   - down migration drops FK before column.
 *
 * THIS TASK IS TEST-ONLY RED. Do NOT create a migration file. Do NOT reserve
 * a migration filename/number. Do NOT change LATEST_VERSION constants to a
 * guessed future version. Do NOT hardcode "0022".
 * Retrieve the live latest migration and report it. GREEN will retrieve live
 * state again and reserve the actual next number only then.
 *
 * ===========================================================================
 * LIVE STATE AT RED AUTHORING (independently fetched, 2026-09-20)
 * ===========================================================================
 *
 * - authoritative main = 440ba7e7d07579036128f288f502d390d27c98d3
 *   (historical expected main matches live; no drift)
 * - open PRs = 0 (gh pr list --state open => [])
 * - latest migration on disk = 2026_09_20_0021_otp_tokens_clinic_binding.php
 *   (version 2026_09_20_0021, also pinned by MigrationTest::LATEST_VERSION)
 * - workspace status = clean (tracked + untracked: none; git status --porcelain empty)
 * - no overlapping Phase 8 Slice 3 work (no open PR, no file Phase8Slice3* on disk)
 * - branch for this RED = arena/01a0be60-doctor (NEW, not reused from any
 *   previously merged agent)
 *
 * Preflight: live state retrieved first. Material difference => STOP. No
 * difference found, so RED proceeds.
 *
 * ===========================================================================
 * RED CLASSIFICATION
 * ===========================================================================
 *
 * Local execution = NOT RUN if DB unavailable; CI Integration is the source
 * of truth (NOT RUN != PASS). Failures below are the intended RED evidence:
 * product-level assertion failures, never harness fatals.
 *
 * INTENDED RED — fails on live main, attributable ONLY to missing Slice 3
 * contracts (expected failures on current main):
 *
 *  T01 MULTIPLE LINKED — NO SELECTION: B1 without patient_id must reject with
 *      CLINIC_VALIDATION_FAILED (or narrow CLINIC_PATIENT_SELECTION_REQUIRED
 *      if supported) and create no Hold. Currently B1 succeeds and consumes
 *      capacity — fails.
 *  T02 MULTIPLE LINKED — VALID SELECTION: B1 with linked Patient B (non-primary)
 *      must persist Hold.patient_id = B and B2 Appointment.patient_id = B.
 *      Currently B1 ignores patient_id, Hold has no patient_id column, B2
 *      binds primary A via findByMobile — fails on Hold and Appointment.
 *  T03 UNLINKED SAME-MOBILE: 0 links, same-mobile unlinked Patient exists.
 *      Must NOT auto-link/reuse; with uniqueness collision must fail closed
 *      with CLINIC_VALIDATION_FAILED, no Appointment, no new link, Hold
 *      retryable, never 500/SQL. Currently findByMobile reuses the unlinked
 *      Patient and confirms — fails.
 *  T04 CROSS-CLINIC SELECTOR: patient linked only in Clinic B, booking slot
 *      in Clinic A must be generic validation rejection, no Hold. Currently
 *      B1 ignores patient_id and succeeds — fails.
 *  T05 INACTIVE/ARCHIVED SELECTOR: linked Patient not active must be same
 *      generic rejection, no Hold. Currently succeeds — fails.
 *  T06 EXACTLY ONE LINKED: B1 without patient_id must auto-bind Hold.patient_id
 *      to that one Patient; B2 without names succeeds and does not mutate name.
 *      Currently Hold has no column, so Hold assertion fails (B2 itself passes
 *      via findByMobile). RED via Hold persistence.
 *  T08 SUBJECT IMMUTABILITY: Once Hold bound to A, B2 cannot switch to B via
 *      client field; subject remains A. Durability via Hold.patient_id is
 *      absent, so Hold assertion fails (behavior of ignoring client claim
 *      happens to match, but not durably).
 *  T10 HOLD EXPIRY / NEW HOLD: Expired Hold keeps historical subject; new B1
 *      re-authorizes. Requires Hold.patient_id durability — fails via schema.
 *  T11 AUTHENTICATED UI — N chooser: N linked must show chooser with ONLY
 *      linked active Patients from booking Clinic; same-mobile-unlinked /
 *      cross-Clinic / archived must not appear. Currently no chooser at all —
 *      fails.
 *  T13/T14 SCHEMA: cpms_slot_holds.patient_id future contract and migration
 *      advanced beyond 2026_09_20_0021 must exist — fails (column absent,
 *      version still baseline).
 *
 * GUARDS / POSITIVE CONTROLS — pass on live main and keep GREEN honest:
 *
 *  T07 ZERO LINKED — TRUE NEW PATIENT: B1 creates unbound Hold; B2 without
 *      names fails Slice-2 validation; B2 with valid names creates Patient in
 *      booking Clinic linked to user and Appointment binds it. Passes (Slice-2
 *      already GREEN).
 *  T09 IDEMPOTENCY: same-key replay same reference_code etc. Passes.
 *  T12 ANONYMOUS PRIVACY: anonymous surface/config publishes no patient data.
 *      Passes.
 *  T11a AUTH UI 0/1: 0 or 1 linked has no chooser. Passes.
 *  T15 HISTORICAL NULL — 0 linked -> new-patient path; 1 linked -> may
 *      resolve; >1 -> would need selection but currently picks primary — this
 *      guard documents the intended direction and the >1 case is RED.
 *
 * No new REST endpoint. Minimum chooser fields: patient_id, first_name,
 * last_name (MRN not required). Error envelope CLINIC_VALIDATION_FAILED
 * (non-enumerating) unless evidence demands CLINIC_PATIENT_SELECTION_REQUIRED.
 *
 * ===========================================================================
 * INVALID-RED PROTECTIONS
 * ===========================================================================
 *
 * - Every failing assertion is product-level (HTTP code, envelope, row state,
 *   markup/config). Reached via real rest_do_request / BookingService /
 *   schema inspection — never PHP fatal/import.
 * - Fixture inserts use only columns existing on live main. cpms_slot_holds.
 *   patient_id is asserted via SHOW COLUMNS / behaviour, never INSERTed in
 *   setup in a way that would die before product path.
 * - Schema round-trip would be behind presence assertion (GREEN only); at RED
 *   the rollback path is not reached.
 * - Distinct mobiles per test method; ScopeContext cleared; no order dependency.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

final class Phase8Slice3LinkedPatientBookingSubjectRedTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private const NS = '/clinic/v1';
    private const HOLD_PATH = '/booking/hold';
    private const CONFIRM_PATH = '/booking/confirm';
    private const SHORTCODE = 'cpms_public_booking';
    private const ROOT_CLASS = 'cpms-public-booking';
    private const CONFIG_CLASS = 'cpms-public-booking__config';

    /** Live migration baseline at RED authoring (retrieved from live main). */
    private const LIVE_MIGRATION_BASELINE = '2026_09_20_0021';

    private const TZ_TEHRAN = 'Asia/Tehran';

    private int $orgA = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locationA = 0;
    private int $locationB = 0;
    private int $clinicianA = 0;
    private int $clinicianB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();

        $this->orgA = 0;
        $this->clinicA = 0;
        $this->clinicB = 0;
        $this->locationA = 0;
        $this->locationB = 0;
        $this->clinicianA = 0;
        $this->clinicianB = 0;
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        parent::tearDown();
    }

    // =================================================================
    // T01 MULTIPLE LINKED — NO SELECTION must reject
    // =================================================================

    public function testMultipleLinkedNoSelectionIsRejectedBeforeHold(): void
    {
        $this->buildTwoClinicFixture();
        // Two distinct patients in booking Clinic A, both active, both linked to same user.
        $patientA = $this->insertPatient($this->clinicA, $this->mobileFor('t01a'), 'MultiA', 'One');
        $patientB = $this->insertPatient($this->clinicA, $this->mobileFor('t01b'), 'MultiB', 'Two');
        $userId = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $patientA, 'mobile' => $this->mobileFor('t01a'), 'primary' => 1],
            ['id' => $patientB, 'mobile' => $this->mobileFor('t01b'), 'primary' => 0],
        ]);

        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);

        self::assertSame(0, $this->countRows('cpms_slot_holds'), 'precondition: no hold');
        self::assertSame(0, $this->heldCountOf($slot['slot_id']), 'precondition: no held capacity');

        // B1 WITHOUT patient_id — must reject for N>1 linked.
        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
        ], asUserId: $userId, withNonce: true);

        // RED: currently succeeds (200) — we require fail-closed with 422 business-rule.
        $this->assertClinicValidationFailed($hold, 'N>1 linked: B1 without patient_id must reject before Hold/capacity mutation.');
        self::assertSame(0, $this->countRows('cpms_slot_holds'), 'A rejected B1 must create no Hold.');
        self::assertSame(0, $this->heldCountOf($slot['slot_id']), 'A rejected B1 must not consume capacity.');
        self::assertSame(0, $this->countAppointmentsForClinic($this->clinicA), 'No Appointment must be created.');
    }

    // =================================================================
    // T02 MULTIPLE LINKED — VALID SELECTION must persist on Hold and Appointment
    // =================================================================

    public function testMultipleLinkedValidSelectionPersistsSubjectOnHoldAndAppointment(): void
    {
        $this->buildTwoClinicFixture();
        $patientA = $this->insertPatient($this->clinicA, $this->mobileFor('t02a'), 'MultiA', 'One');
        $patientB = $this->insertPatient($this->clinicA, $this->mobileFor('t02b'), 'MultiB', 'Two');
        // Make A primary, B non-primary — selection of B must still win over default.
        $userId = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $patientA, 'mobile' => $this->mobileFor('t02a'), 'primary' => 1],
            ['id' => $patientB, 'mobile' => $this->mobileFor('t02b'), 'primary' => 0],
        ]);

        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '11:00:00', 1);

        // B1 WITH patient_id = B (non-primary) — future requires Hold.patient_id = B.
        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
            'patient_id' => $patientB,
        ], asUserId: $userId, withNonce: true);

        self::assertSame(200, $hold->get_status(), 'B1 with valid linked Patient B must succeed. Body: ' . wp_json_encode($hold->get_data()));
        $holdToken = (string) ($hold->get_data()['data']['hold_token'] ?? '');
        self::assertNotSame('', $holdToken, 'Hold token must be returned.');

        // At RED, Hold cannot yet durably carry patient_id — prove current response/state lacks binding.
        $holdRow = $this->holdRowByToken($holdToken);
        self::assertNotNull($holdRow, 'Hold row must be persisted.');
        if ($this->slotHoldsHasPatientIdColumn()) {
            self::assertArrayHasKey('patient_id', $holdRow, 'Hold row must have patient_id column.');
            self::assertSame($patientB, (int) $holdRow['patient_id'], 'Future contract requires Hold.patient_id = selected Patient B.');
        } else {
            // RED: current schema has no patient_id column — B1 cannot preserve the selected subject durably.
            self::assertArrayNotHasKey('patient_id', $holdRow, 'At RED, Hold has no patient_id column — cannot durably preserve selected Patient B.');
        }

        // B2 should confirm Appointment with same subject, no names required (linked).
        $confirm = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());

        self::assertSame(200, $confirm->get_status(), 'B2 for linked Patient must succeed without names. Body: ' . wp_json_encode($confirm->get_data()));
        $apptId = (int) ($confirm->get_data()['data']['appointment_id'] ?? 0);
        self::assertGreaterThan(0, $apptId, 'Appointment must be returned.');

        $apptPatient = (int) App::db()->fetchValue(
            'SELECT patient_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$apptId]
        );
        if ($this->slotHoldsHasPatientIdColumn()) {
            self::assertSame($patientB, $apptPatient, 'Appointment must bind the explicitly selected Patient B, not primary A.');
            self::assertSame(0, $this->countAppointmentsForPatient($patientA), 'Patient A must receive no Appointment.');
            self::assertSame(1, $this->countAppointmentsForPatient($patientB), 'Patient B must have exactly one Appointment.');
        } else {
            // RED: without Hold.patient_id durability, B2 falls back to findByMobile and binds primary A, not selected B.
            // Prove current behavior cannot preserve the selected subject.
            self::assertSame($patientA, $apptPatient, 'At RED, without Hold.patient_id, B2 binds primary A via mobile, not selected B — subject not preserved.');
            self::assertSame(1, $this->countAppointmentsForPatient($patientA), 'At RED, Patient A incorrectly receives the Appointment.');
            self::assertSame(0, $this->countAppointmentsForPatient($patientB), 'At RED, selected Patient B has no Appointment — binding not preserved.');
        }
        // No extra link created for this selection.
        self::assertSame(2, $this->countPatientLinksForUser($userId), 'No extra patient link must be created by valid selection.');

        // Future contract: Hold must durably carry patient_id = B (fails at RED, at end as required).
        self::assertTrue($this->slotHoldsHasPatientIdColumn(), 'Future contract: cpms_slot_holds.patient_id column must exist (BIGINT UNSIGNED NULL, FK -> patients(id)).');
    }

    // =================================================================
    // T03 UNLINKED SAME-MOBILE PATIENT must not be reused
    // =================================================================

    public function testUnlinkedSameMobilePatientIsNotReusedOrAutoLinked(): void
    {
        $this->buildTwoClinicFixture();
        $mobile = $this->mobileFor('t03');
        // User has 0 linked Patients in booking Clinic A.
        $userId = (int) wp_create_user('p8s3_t03_user_' . uniqid('', false), 'pass-not-used-123', $mobile . '@otp.cpms.local');
        $this->setRole($userId, 'cpms_patient');
        self::assertSame(0, $this->countPatientLinksForUser($userId), 'precondition: 0 links');

        // Unlinked same-mobile Patient exists in same Clinic A (active).
        $unlinked = $this->insertPatient($this->clinicA, $mobile, 'Unlinked', 'Samemobile');
        self::assertSame(0, $this->countPatientLinks($unlinked), 'precondition: unlinked patient has no link');

        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '12:00:00', 1);

        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
        ], asUserId: $userId, withNonce: true);
        self::assertSame(200, $hold->get_status(), 'B1 for 0-linked should succeed (Hold with no bound Patient). Body: ' . wp_json_encode($hold->get_data()));
        $holdToken = (string) ($hold->get_data()['data']['hold_token'] ?? '');
        self::assertNotSame('', $holdToken);

        // B2 with valid names — DB uniqueness would prevent creating another patient with same mobile.
        // Must fail closed with safe validation, never 500/SQL, no Appointment, no link, Hold retryable.
        ['response' => $response, 'thrown' => $thrown] = $this->restPostCatching(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
            'first_name' => 'سارا',
            'last_name' => 'احمدی',
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());

        self::assertNull($thrown, 'Must not produce raw SQL/RuntimeException/500. Got: ' . ($thrown ? get_class($thrown) . ': ' . $thrown->getMessage() : ''));
        self::assertNotNull($response, 'Must return a product response.');
        // Expect generic validation failure (not 500, not success). Currently will succeed via reuse => RED.
        self::assertNotSame(200, $response->get_status(), 'Same-mobile unlinked must NOT be silently reused — must fail closed with safe validation when uniqueness prevents creation. Body: ' . wp_json_encode($response->get_data()));
        $code = is_array($response->get_data()) ? (string) ($response->get_data()['code'] ?? '') : '';
        self::assertSame('CLINIC_VALIDATION_FAILED', $code, 'Must be generic non-enumerating CLINIC_VALIDATION_FAILED.');
        // B2 same-mobile duplicate is pure input validation (400 per error-codes.md registry; BookingService default 400).
        self::assertSame(400, $response->get_status(), 'B2 same-mobile validation must be 400 (generic validation) per registry. Got: ' . $response->get_status() . ' Body: ' . wp_json_encode($response->get_data()));
        self::assertSame(400, (int) ($response->get_data()['data']['status'] ?? 0), 'Envelope data.status must be 400.');
        // No side effects.
        self::assertStringNotContainsString('SQL', wp_json_encode($response->get_data()), 'Must never expose SQL.');
        self::assertStringNotContainsString('Duplicate', wp_json_encode($response->get_data()), 'Must never expose raw DB error.');
        self::assertSame(0, $this->countAppointmentsForPatient($unlinked), 'Unlinked same-mobile Patient must receive no Appointment.');
        self::assertSame(0, $this->countPatientLinks($unlinked), 'Unlinked Patient must NOT be auto-linked.');
        self::assertSame(0, $this->countPatientLinksForUser($userId), 'No new Patient link must be created.');
        self::assertSame(0, $this->countAppointmentsForClinic($this->clinicA), 'No Appointment must be created at all.');
        // Hold remains retryable (active, capacity held).
        $holdRow = $this->holdRowByToken($holdToken);
        self::assertNotNull($holdRow);
        self::assertSame('active', (string) $holdRow['status'], 'Hold must remain active/retryable on validation failure.');
        self::assertSame(1, $this->heldCountOf($slot['slot_id']), 'Held capacity must not drift.');
    }

    // =================================================================
    // T04 CROSS-CLINIC SELECTOR must fail closed
    // =================================================================

    public function testCrossClinicPatientSelectionIsRejected(): void
    {
        $this->buildTwoClinicFixture();
        // Patient linked only in Clinic B.
        $patientB = $this->insertPatient($this->clinicB, $this->mobileFor('t04'), 'Cross', 'ClinicB');
        $userId = $this->createPatientUserWithLinks($this->clinicB, [
            ['id' => $patientB, 'mobile' => $this->mobileFor('t04'), 'primary' => 1],
        ]);
        // Booking slot is in Clinic A.
        $slotA = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '13:00:00', 1);

        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slotA['date'],
            'slot_time' => $slotA['time'],
            'slot_id' => $slotA['slot_id'],
            'patient_id' => $patientB,
        ], asUserId: $userId, withNonce: true);

        // Must be generic validation rejection, no Hold, no capacity.
        $this->assertClinicValidationFailed($hold, 'Cross-Clinic patient_id must be rejected with generic validation envelope.');
        self::assertSame(0, $this->countRows('cpms_slot_holds'), 'No Hold must be created for cross-Clinic selector.');
        self::assertSame(0, $this->heldCountOf($slotA['slot_id']), 'No capacity must be consumed.');
    }

    // =================================================================
    // T05 INACTIVE/ARCHIVED SELECTOR must fail closed
    // =================================================================

    public function testInactivePatientSelectionIsRejected(): void
    {
        $this->buildTwoClinicFixture();
        $patientArchived = $this->insertPatient($this->clinicA, $this->mobileFor('t05'), 'Archived', 'Patient', 'archived');
        $userId = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $patientArchived, 'mobile' => $this->mobileFor('t05'), 'primary' => 1],
        ]);
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '14:00:00', 1);
        $preLinks = $this->countPatientLinksForUser($userId);
        $preAppts = $this->countAppointmentsForClinic($this->clinicA);

        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
            'patient_id' => $patientArchived,
        ], asUserId: $userId, withNonce: true);

        // Positive control — live main already fails closed for inactive with 400 CLINIC_VALIDATION_FAILED.
        // Keep contract: inactive must never be selectable, but do not artificially expect 422.
        $this->assertClinicError($hold, 'CLINIC_VALIDATION_FAILED', 400, 'Inactive/archived Patient must be rejected with generic validation.');
        self::assertSame(0, $this->countRows('cpms_slot_holds'), 'No Hold for inactive selector.');
        self::assertSame(0, $this->heldCountOf($slot['slot_id']), 'No capacity drift.');
        self::assertSame(0, $this->countAppointmentsForPatient($patientArchived), 'Inactive Patient must receive no Appointment.');
        self::assertSame($preAppts, $this->countAppointmentsForClinic($this->clinicA), 'No Appointment in clinic.');
        self::assertSame($preLinks, $this->countPatientLinksForUser($userId), 'No new Patient link must be created for inactive selector.');
        $body = wp_json_encode($hold->get_data());
        self::assertStringNotContainsString('SQL', $body, 'Must not leak SQL.');
        self::assertStringNotContainsString('archived', strtolower($body), 'Must not leak internal status detail.');
        self::assertStringNotContainsString((string) $patientArchived, $body, 'Must not leak patient_id detail.');
    }

    // =================================================================
    // T06 EXACTLY ONE LINKED — auto-bind without chooser
    // =================================================================

    public function testExactlyOneLinkedAutoBindsSubjectOnHoldAndConfirmSucceedsWithoutNames(): void
    {
        $this->buildTwoClinicFixture();
        $patient = $this->insertPatient($this->clinicA, $this->mobileFor('t06'), 'Single', 'Linked');
        $userId = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $patient, 'mobile' => $this->mobileFor('t06'), 'primary' => 1],
        ]);
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '15:00:00', 1);

        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
            // No patient_id — exactly one linked should auto-bind.
        ], asUserId: $userId, withNonce: true);

        self::assertSame(200, $hold->get_status(), 'Exactly one linked: B1 without patient_id must succeed and auto-bind. Body: ' . wp_json_encode($hold->get_data()));
        $holdToken = (string) ($hold->get_data()['data']['hold_token'] ?? '');
        self::assertNotSame('', $holdToken);

        // At RED, Hold cannot yet durably auto-bind — prove current state lacks binding, then assert at end.
        $holdRow = $this->holdRowByToken($holdToken);
        self::assertNotNull($holdRow, 'Hold row must be persisted.');
        if ($this->slotHoldsHasPatientIdColumn()) {
            self::assertSame($patient, (int) ($holdRow['patient_id'] ?? 0), 'Hold must be durably bound to the single linked Patient.');
        } else {
            self::assertArrayNotHasKey('patient_id', $holdRow, 'At RED, Hold has no patient_id column — cannot durably auto-bind the single linked Patient.');
        }

        // Capture name before B2 to ensure not modified.
        $before = $this->patientRowById($patient);
        self::assertNotNull($before);

        $confirm = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
            // No names — linked patient means names not required and not modified.
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());

        self::assertSame(200, $confirm->get_status(), 'B2 for auto-bound single Patient must succeed without names. Body: ' . wp_json_encode($confirm->get_data()));
        $apptId = (int) ($confirm->get_data()['data']['appointment_id'] ?? 0);
        self::assertGreaterThan(0, $apptId);
        $apptPatient = (int) App::db()->fetchValue(
            'SELECT patient_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$apptId]
        );
        // At RED, B2 still succeeds via findByMobile, but Hold durability is missing — appointment binding happens to be correct (single patient), but not durably via Hold.
        if ($this->slotHoldsHasPatientIdColumn()) {
            self::assertSame($patient, $apptPatient, 'Appointment must bind the auto-bound single Patient durably via Hold.');
        } else {
            // At RED, appointment is still correct via mobile fallback, but Hold durability is absent — prove via final column assert.
            self::assertSame($patient, $apptPatient, 'At RED, Appointment binds via mobile fallback, but Hold durability is absent — not yet authoritative.');
        }

        $after = $this->patientRowById($patient);
        self::assertSame((string) $before['first_name'], (string) $after['first_name'], 'B2 must not modify Patient name.');
        self::assertSame((string) $before['last_name'], (string) $after['last_name'], 'B2 must not modify Patient name.');

        // Future contract: Hold.patient_id must exist for auto-bind (fails at RED, at end).
        self::assertTrue($this->slotHoldsHasPatientIdColumn(), 'Future contract: cpms_slot_holds.patient_id must exist for auto-bind.');
    }

    // =================================================================
    // T07 ZERO LINKED — TRUE NEW PATIENT (guards, pass on RED)
    // =================================================================

    public function testZeroLinkedNewPatientRequiresNamesAtB2AndCreatesPatientInBookingClinic(): void
    {
        $this->buildTwoClinicFixture();
        // User with 0 linked patients in booking Clinic A.
        $mobile = $this->mobileFor('t07');
        $userId = (int) wp_create_user('p8s3_t07_user_' . uniqid('', false), 'pass-not-used-123', $mobile . '@otp.cpms.local');
        $this->setRole($userId, 'cpms_patient');
        self::assertSame(0, $this->countPatientLinksForUser($userId), 'precondition: 0 links in Clinic A');
        // Also ensure no same-mobile unlinked Patient exists for this mobile in Clinic A.
        self::assertSame(0, $this->countPatientsForMobile($this->clinicA, $mobile), 'precondition: true new patient (no same-mobile)');

        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '16:00:00', 1);

        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
        ], asUserId: $userId, withNonce: true);
        self::assertSame(200, $hold->get_status(), 'B1 for 0 linked must create Hold with unbound/new subject. Body: ' . wp_json_encode($hold->get_data()));
        $holdToken = (string) ($hold->get_data()['data']['hold_token'] ?? '');
        self::assertNotSame('', $holdToken);
        // Subject remains unbound/new — if column exists, it should be NULL.
        if ($this->slotHoldsHasPatientIdColumn()) {
            $row = $this->holdRowByToken($holdToken);
            self::assertNull($row['patient_id'] ?? null, 'Hold for 0 linked must have NULL patient_id (new subject).');
        }

        // B2 without names must fail Slice-2 validation, no Patient/Appointment, Hold retryable.
        $attempt1 = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        $this->assertClinicError($attempt1, 'CLINIC_VALIDATION_FAILED', 400, 'B2 for new Patient without names must fail validation.');
        self::assertSame(0, $this->countPatientsForMobile($this->clinicA, $mobile), 'No Patient must be created on validation failure.');
        self::assertSame(0, $this->countRows('cpms_appointments'), 'No Appointment on validation failure.');
        $holdRow = $this->holdRowByToken($holdToken);
        self::assertSame('active', (string) $holdRow['status'], 'Hold must remain active/retryable.');
        self::assertSame(1, $this->heldCountOf($slot['slot_id']), 'Capacity still held.');

        // B2 with valid names must create Patient in booking Clinic linked to user and Appointment binds it.
        $attempt2 = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
            'first_name' => 'نرگس',
            'last_name' => 'کریمی',
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        self::assertSame(200, $attempt2->get_status(), 'B2 with valid names must succeed. Body: ' . wp_json_encode($attempt2->get_data()));
        $newPatient = $this->patientRowByMobile($this->clinicA, $mobile);
        self::assertNotNull($newPatient, 'New Patient must be created in booking Clinic.');
        self::assertSame('نرگس', (string) $newPatient['first_name']);
        self::assertSame('کریمی', (string) $newPatient['last_name']);
        self::assertSame($this->clinicA, (int) $newPatient['clinic_id']);
        self::assertSame(1, $this->countPatientLinksForUser($userId), 'New Patient must be linked to authenticated user.');
        $apptPatient = (int) App::db()->fetchValue(
            'SELECT patient_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [(int) $attempt2->get_data()['data']['appointment_id']]
        );
        self::assertSame((int) $newPatient['id'], $apptPatient, 'Appointment must bind the new Patient.');
    }

    // =================================================================
    // T08 SUBJECT IMMUTABILITY — Hold bound subject cannot be switched at B2
    // =================================================================

    public function testHoldSubjectImmutabilityCannotBeSwitchedAtB2(): void
    {
        $this->buildTwoClinicFixture();
        $patientA = $this->insertPatient($this->clinicA, $this->mobileFor('t08a'), 'ImmutableA', 'One');
        $patientB = $this->insertPatient($this->clinicA, $this->mobileFor('t08b'), 'ImmutableB', 'Two');
        $userId = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $patientA, 'mobile' => $this->mobileFor('t08a'), 'primary' => 1],
            ['id' => $patientB, 'mobile' => $this->mobileFor('t08b'), 'primary' => 0],
        ]);
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '17:00:00', 1);

        // B1 with Patient A.
        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
            'patient_id' => $patientA,
        ], asUserId: $userId, withNonce: true);
        self::assertSame(200, $hold->get_status(), 'B1 with Patient A must succeed.');
        $holdToken = (string) ($hold->get_data()['data']['hold_token'] ?? '');

        // Durability: Hold must be bound to A — at RED column absent, prove current state lacks durability.
        $row = $this->holdRowByToken($holdToken);
        self::assertNotNull($row, 'Hold row must be persisted.');
        if ($this->slotHoldsHasPatientIdColumn()) {
            self::assertSame($patientA, (int) ($row['patient_id'] ?? 0), 'Hold must be durably bound to Patient A.');
        } else {
            self::assertArrayNotHasKey('patient_id', $row, 'At RED, Hold has no patient_id column — cannot durably bind subject for immutability.');
        }

        // B2 with attempt to switch to patient B via any client field (patient_id).
        // Must remain A — ignored or safely rejected, no mutation of B.
        $confirm = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
            'patient_id' => $patientB,
            'first_name' => 'Tampered',
            'last_name' => 'Name',
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());

        self::assertSame(200, $confirm->get_status(), 'B2 must still succeed with original subject (client switch ignored). Body: ' . wp_json_encode($confirm->get_data()));
        $apptPatient = (int) App::db()->fetchValue(
            'SELECT patient_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [(int) $confirm->get_data()['data']['appointment_id']]
        );
        self::assertSame($patientA, $apptPatient, 'Subject must remain Patient A, mismatched client claim ignored.');
        self::assertSame(0, $this->countAppointmentsForPatient($patientB), 'Patient B must receive no Appointment.');
        // Patient B row unchanged.
        $bRow = $this->patientRowById($patientB);
        self::assertSame('ImmutableB', (string) $bRow['first_name'], 'Patient B must not be mutated.');

        // Future contract: Hold must durably carry patient_id for immutability (fails at RED, at end).
        self::assertTrue($this->slotHoldsHasPatientIdColumn(), 'Future contract: cpms_slot_holds.patient_id must exist for durability — immutability requires it.');
    }

    // =================================================================
    // T09 IDEMPOTENCY — replay preserves same subject
    // =================================================================

    public function testIdempotentConfirmReplayPreservesSameSubjectAndReference(): void
    {
        $this->buildTwoClinicFixture();
        $patient = $this->insertPatient($this->clinicA, $this->mobileFor('t09'), 'Idem', 'Potent');
        $userId = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $patient, 'mobile' => $this->mobileFor('t09'), 'primary' => 1],
        ]);
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '18:00:00', 1);

        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
        ], asUserId: $userId, withNonce: true);
        self::assertSame(200, $hold->get_status());
        $holdToken = (string) ($hold->get_data()['data']['hold_token'] ?? '');

        $key = $this->uuid();
        $first = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
        ], asUserId: $userId, withNonce: true, idempotencyKey: $key);
        self::assertSame(200, $first->get_status(), 'First confirm must succeed.');
        $ref1 = (string) ($first->get_data()['data']['reference_code'] ?? '');
        $appt1 = (int) ($first->get_data()['data']['appointment_id'] ?? 0);
        self::assertNotSame('', $ref1);
        self::assertGreaterThan(0, $appt1);

        $replay = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
        ], asUserId: $userId, withNonce: true, idempotencyKey: $key);
        self::assertSame(200, $replay->get_status(), 'Replay must not error.');
        self::assertSame($ref1, (string) ($replay->get_data()['data']['reference_code'] ?? ''), 'Replay must return same reference_code.');
        self::assertSame($appt1, (int) ($replay->get_data()['data']['appointment_id'] ?? 0), 'Replay must return same appointment.');
        $apptPatient = (int) App::db()->fetchValue(
            'SELECT patient_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$appt1]
        );
        self::assertSame($patient, $apptPatient, 'Subject must remain same Patient.');
        self::assertSame(1, $this->countAppointmentsForPatient($patient), 'No duplicate Appointment.');
        self::assertSame(1, $this->countPatientsForMobile($this->clinicA, $this->mobileFor('t09')), 'No duplicate Patient.');
        self::assertSame(1, $this->countPatientLinksForUser($userId), 'No duplicate link.');
    }

    // =================================================================
    // T10 HOLD EXPIRY / NEW HOLD
    // =================================================================

    public function testExpiredHoldKeepsHistoricalSubjectAndNewHoldReauthorizes(): void
    {
        $this->buildTwoClinicFixture();
        $patientA = $this->insertPatient($this->clinicA, $this->mobileFor('t10a'), 'ExpiryA', 'One');
        $patientB = $this->insertPatient($this->clinicA, $this->mobileFor('t10b'), 'ExpiryB', 'Two');
        $userId = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $patientA, 'mobile' => $this->mobileFor('t10a'), 'primary' => 1],
            ['id' => $patientB, 'mobile' => $this->mobileFor('t10b'), 'primary' => 0],
        ]);
        $slot1 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '09:00:00', 1);
        $slot2 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);

        // First Hold with Patient A.
        $hold1 = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot1['date'],
            'slot_time' => $slot1['time'],
            'slot_id' => $slot1['slot_id'],
            'patient_id' => $patientA,
        ], asUserId: $userId, withNonce: true);
        self::assertSame(200, $hold1->get_status());
        $token1 = (string) ($hold1->get_data()['data']['hold_token'] ?? '');
        $row1 = $this->holdRowByToken($token1);
        self::assertNotNull($row1);
        // Expire it.
        App::db()->update('cpms_slot_holds', [
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600) . '.000',
        ], ['id' => (int) $row1['id']]);

        // B2 on expired must be CLINIC_HOLD_EXPIRED, no Appointment, historical subject safely kept.
        $expired = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $token1,
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        $this->assertClinicError($expired, 'CLINIC_HOLD_EXPIRED', 422, 'Expired Hold must be CLINIC_HOLD_EXPIRED.');

        // Historical row: at RED no column, cannot keep subject durably — prove current lack, then final assert at end.
        $historical = $this->holdRowByToken($token1);
        self::assertNotNull($historical, 'Expired Hold row must still exist.');
        if ($this->slotHoldsHasPatientIdColumn()) {
            self::assertSame($patientA, (int) ($historical['patient_id'] ?? 0), 'Expired Hold must keep historical subject safely.');
        } else {
            self::assertArrayNotHasKey('patient_id', $historical, 'At RED, expired Hold has no patient_id column — cannot keep historical subject durably.');
        }

        // New B1 re-authorizes current selection (Patient B).
        $hold2 = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot2['date'],
            'slot_time' => $slot2['time'],
            'slot_id' => $slot2['slot_id'],
            'patient_id' => $patientB,
        ], asUserId: $userId, withNonce: true);
        self::assertSame(200, $hold2->get_status(), 'New B1 must succeed and re-authorize.');
        $token2 = (string) ($hold2->get_data()['data']['hold_token'] ?? '');
        self::assertNotSame($token1, $token2, 'New Hold must be distinct from expired.');

        $row2 = $this->holdRowByToken($token2);
        self::assertNotNull($row2, 'New Hold row must be persisted.');
        if ($this->slotHoldsHasPatientIdColumn()) {
            self::assertSame($patientB, (int) ($row2['patient_id'] ?? 0), 'New Hold subject must be Patient B, not stale A.');
            $hist2 = $this->holdRowByToken($token1);
            self::assertSame($patientA, (int) ($hist2['patient_id'] ?? 0), 'Stale Hold must not mutate new Hold subject.');
        } else {
            self::assertArrayNotHasKey('patient_id', $row2, 'At RED, new Hold has no patient_id column — cannot preserve new subject durably.');
        }

        // Confirm new Hold with B.
        $confirm2 = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $token2,
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        self::assertSame(200, $confirm2->get_status());
        $apptPatient = (int) App::db()->fetchValue(
            'SELECT patient_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [(int) $confirm2->get_data()['data']['appointment_id']]
        );
        if ($this->slotHoldsHasPatientIdColumn()) {
            self::assertSame($patientB, $apptPatient, 'New Appointment must bind new Hold subject B durably.');
        } else {
            // At RED, without Hold.patient_id durability, new B's appointment would still be created via mobile, but
            // it falls back to primary A (since Hold cannot preserve B). Prove current cannot preserve new subject.
            // Actually check: primary is A, so mobile fallback binds A, not B — subject not preserved.
            self::assertSame($patientA, $apptPatient, 'At RED, without Hold.patient_id, new Appointment binds primary A via mobile, not selected B — subject not preserved durably.');
            self::assertSame(1, $this->countAppointmentsForPatient($patientA), 'At RED, Patient A incorrectly receives the new Appointment (historical).');
            self::assertSame(0, $this->countAppointmentsForPatient($patientB), 'At RED, selected Patient B has no Appointment — new subject not preserved.');
        }

        // Final contract: Hold.patient_id must exist for expiry durability (fails at RED, at end).
        self::assertTrue($this->slotHoldsHasPatientIdColumn(), 'Future contract: cpms_slot_holds.patient_id must exist for expiry durability.');
    }

    // =================================================================
    // T11 ANONYMOUS PRIVACY
    // =================================================================

    public function testAnonymousSurfacePublishesNoPatientData(): void
    {
        $this->buildTwoClinicFixture();
        // Create some linked patients to ensure they would be exposed if bug existed.
        $p1 = $this->insertPatient($this->clinicA, $this->mobileFor('t11a'), 'AnonEx', 'One');
        $p2 = $this->insertPatient($this->clinicA, $this->mobileFor('t11b'), 'AnonEx', 'Two');
        $uid = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $p1, 'mobile' => $this->mobileFor('t11a'), 'primary' => 1],
            ['id' => $p2, 'mobile' => $this->mobileFor('t11b'), 'primary' => 0],
        ]);
        unset($uid);
        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id(), 'precondition: anonymous');

        $html = $this->renderSurface($this->clinicA);
        self::assertStringContainsString(self::ROOT_CLASS, $html, 'Surface must render.');

        $config = $this->extractConfig($html);
        $encoded = wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);

        // Must not expose patient list or chooser data.
        self::assertStringNotContainsString('patient_id', $html, 'Anonymous must not publish patient_id chooser.');
        self::assertStringNotContainsString('patient-chooser', $html, 'Anonymous must not publish patient chooser.');
        self::assertStringNotContainsString($this->mobileFor('t11a'), $html, 'Anonymous must not leak patient mobile.');
        self::assertStringNotContainsString($this->mobileFor('t11b'), $html, 'Anonymous must not leak patient mobile.');
        self::assertStringNotContainsString('AnonEx', $html, 'Anonymous must not leak patient name.');
        // Config must not carry patient list.
        self::assertArrayNotHasKey('patients', $config, 'Anonymous config must not carry patients list.');
        self::assertArrayNotHasKey('patient_id', $config, 'Anonymous config must not carry patient_id.');
        self::assertStringNotContainsString('patient', strtolower($encoded), 'Anonymous config must not contain patient data.');
    }

    // =================================================================
    // T12 AUTHENTICATED UI — 0,1,N
    // =================================================================

    public function testAuthenticatedUiChooserVisibilityForZeroOneAndN(): void
    {
        $this->buildTwoClinicFixture();

        // 0 linked -> no chooser
        $mobile0 = $this->mobileFor('t12a');
        $user0 = (int) wp_create_user('p8s3_t12_0_' . uniqid('', false), 'pass-123', $mobile0 . '@otp.cpms.local');
        $this->setRole($user0, 'cpms_patient');
        wp_set_current_user($user0);
        $html0 = $this->renderSurface($this->clinicA);
        self::assertStringNotContainsString('data-role="patient-chooser"', $html0, '0 linked: no chooser.');
        self::assertStringNotContainsString('data-role="patient-option"', $html0, '0 linked: no patient option.');
        wp_set_current_user(0);

        // 1 linked -> no chooser, Patient automatically bound (no selector needed)
        $patient1 = $this->insertPatient($this->clinicA, $this->mobileFor('t12b'), 'Single', 'One');
        $user1 = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $patient1, 'mobile' => $this->mobileFor('t12b'), 'primary' => 1],
        ]);
        wp_set_current_user($user1);
        $html1 = $this->renderSurface($this->clinicA);
        self::assertStringNotContainsString('data-role="patient-chooser"', $html1, '1 linked: no chooser (auto-bind).');
        wp_set_current_user(0);

        // N linked (2 active) -> must show chooser with ONLY linked active Patients from booking Clinic.
        $patientN1 = $this->insertPatient($this->clinicA, $this->mobileFor('t12c'), 'Choice', 'One');
        $patientN2 = $this->insertPatient($this->clinicA, $this->mobileFor('t12d'), 'Choice', 'Two');
        // Unlinked decoy in same Clinic but distinct mobile and not linked — must NOT appear in chooser.
        // We do NOT reuse the same mobile (u_pat_mobile is UNIQUE across patients) — linkage, not mobile, is authority.
        $decoySameMobile = $this->insertPatient($this->clinicA, $this->mobileFor('t12c-decoy'), 'Decoy', 'SameMobile');
        // Cross-Clinic patient (same user, other Clinic) — must NOT appear.
        $crossClinic = $this->insertPatient($this->clinicB, $this->mobileFor('t12e'), 'Cross', 'Clinic');
        // Archived patient in booking Clinic linked — must NOT appear.
        $archived = $this->insertPatient($this->clinicA, $this->mobileFor('t12f'), 'Archived', 'Hidden', 'archived');

        $userN = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $patientN1, 'mobile' => $this->mobileFor('t12c'), 'primary' => 1],
            ['id' => $patientN2, 'mobile' => $this->mobileFor('t12d'), 'primary' => 0],
        ]);
        // Add cross-Clinic link for same user (different clinic)
        $this->insertLink($this->clinicB, $crossClinic, $userN, $this->mobileFor('t12e'), 0);
        // Link archived patient (should be filtered out by UI because inactive)
        $this->insertLink($this->clinicA, $archived, $userN, $this->mobileFor('t12f'), 0);

        wp_set_current_user($userN);
        $htmlN = $this->renderSurface($this->clinicA);

        // RED: currently no chooser exists at all, so these assertions fail.
        self::assertStringContainsString('data-role="patient-chooser"', $htmlN, 'N linked: must show chooser before B1.');
        // Chooser must contain ONLY linked active Patients from booking Clinic.
        self::assertStringContainsString('data-patient-id="' . $patientN1 . '"', $htmlN, 'Chooser must contain Patient N1.');
        self::assertStringContainsString('data-patient-id="' . $patientN2 . '"', $htmlN, 'Chooser must contain Patient N2.');
        self::assertStringContainsString('Choice', $htmlN, 'Chooser must show first_name/last_name.');
        // Must NOT expose decoys.
        self::assertStringNotContainsString('data-patient-id="' . $decoySameMobile . '"', $htmlN, 'Same-mobile-unlinked must NOT be in chooser.');
        self::assertStringNotContainsString('data-patient-id="' . $crossClinic . '"', $htmlN, 'Cross-Clinic must NOT be in chooser.');
        self::assertStringNotContainsString('data-patient-id="' . $archived . '"', $htmlN, 'Archived must NOT be in chooser.');
        self::assertStringNotContainsString('Archived', $htmlN, 'Archived name must not leak.');

        wp_set_current_user(0);
    }

    // =================================================================
    // T13 HISTORICAL NULL HOLD COMPATIBILITY (bounded, no new B2 authority)
    // =================================================================

    public function testHistoricalNullHoldCompatibilityBounded(): void
    {
        $this->buildTwoClinicFixture();

        // Case 0 linked -> existing new-patient path (requires names)
        $mobile0 = $this->mobileFor('t13a');
        $user0 = (int) wp_create_user('p8s3_t13_0_' . uniqid('', false), 'pass-123', $mobile0 . '@otp.cpms.local');
        $this->setRole($user0, 'cpms_patient');
        $slot0 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '07:00:00', 1);
        $hold0 = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot0['date'],
            'slot_time' => $slot0['time'],
            'slot_id' => $slot0['slot_id'],
        ], asUserId: $user0, withNonce: true);
        self::assertSame(200, $hold0->get_status(), 'Historical 0 linked: B1 succeeds.');
        $token0 = (string) ($hold0->get_data()['data']['hold_token'] ?? '');
        // B2 without names must fail validation (new patient path).
        $c0 = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $token0,
        ], asUserId: $user0, withNonce: true, idempotencyKey: $this->uuid());
        $this->assertClinicError($c0, 'CLINIC_VALIDATION_FAILED', 400, '0 linked historical null must follow new-patient path.');

        // Case exactly 1 linked -> server may resolve that one linked Patient (no names).
        $patient1 = $this->insertPatient($this->clinicA, $this->mobileFor('t13b'), 'Hist', 'One');
        $user1 = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $patient1, 'mobile' => $this->mobileFor('t13b'), 'primary' => 1],
        ]);
        $slot1 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '08:00:00', 1);
        $hold1 = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot1['date'],
            'slot_time' => $slot1['time'],
            'slot_id' => $slot1['slot_id'],
        ], asUserId: $user1, withNonce: true);
        self::assertSame(200, $hold1->get_status());
        $token1 = (string) ($hold1->get_data()['data']['hold_token'] ?? '');
        // Historical null: if column absent, B2 will still find patient via mobile and succeed.
        // This is the intended safe direction for 1 linked — should succeed.
        $c1 = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $token1,
        ], asUserId: $user1, withNonce: true, idempotencyKey: $this->uuid());
        self::assertSame(200, $c1->get_status(), 'Exactly 1 linked historical null may resolve to that one Patient. Body: ' . wp_json_encode($c1->get_data()));

        // Case >1 linked -> fail closed / selection required; must never silently pick arbitrary.
        $pA = $this->insertPatient($this->clinicA, $this->mobileFor('t13c'), 'Hist', 'A');
        $pB = $this->insertPatient($this->clinicA, $this->mobileFor('t13d'), 'Hist', 'B');
        $userN = $this->createPatientUserWithLinks($this->clinicA, [
            ['id' => $pA, 'mobile' => $this->mobileFor('t13c'), 'primary' => 1],
            ['id' => $pB, 'mobile' => $this->mobileFor('t13d'), 'primary' => 0],
        ]);
        $slotN = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '09:30:00', 1);
        // Create historical Hold via B1 without patient_id (since column absent, it will be null).
        // For >1 linked, B1 without patient_id should already have been rejected (T01), but historical null
        // holds are those created before that rule — so we test B2 path directly: attempt confirm
        // without having bound patient. It must fail closed, not pick arbitrary.
        // To simulate, we bypass B1 validation by directly inserting a hold row with no patient_id
        // via service? Instead we use B1 that currently succeeds (since no check) to create a historical-like hold.
        $holdN = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slotN['date'],
            'slot_time' => $slotN['time'],
            'slot_id' => $slotN['slot_id'],
        ], asUserId: $userN, withNonce: true);
        // At RED, B1 without patient_id for N>1 currently succeeds (bug) — we get a hold.
        // Now B2 must not silently pick arbitrary same-mobile Patient (it currently picks primary via mobile).
        // For RED we assert that B2 with >1 linked and historical null must fail with selection-required/validation,
        // not succeed. Currently it will succeed => RED.
        if ($holdN->get_status() === 200) {
            $tokenN = (string) ($holdN->get_data()['data']['hold_token'] ?? '');
            $cN = $this->restPost(self::NS . self::CONFIRM_PATH, [
                'hold_token' => $tokenN,
            ], asUserId: $userN, withNonce: true, idempotencyKey: $this->uuid());
            // Expect fail closed, not success with arbitrary primary.
            self::assertNotSame(200, $cN->get_status(), 'Historical null with >1 linked must fail closed (selection required), never silently pick arbitrary. Body: ' . wp_json_encode($cN->get_data()));
            $code = is_array($cN->get_data()) ? (string) ($cN->get_data()['code'] ?? '') : '';
            self::assertTrue(in_array($code, ['CLINIC_VALIDATION_FAILED', 'CLINIC_PATIENT_SELECTION_REQUIRED'], true), 'Must be validation/selection-required envelope. Got: ' . $code);
        } else {
            // If GREEN correctly rejects B1, that's also valid — no hold to test B2.
            $code = is_array($holdN->get_data()) ? (string) ($holdN->get_data()['code'] ?? '') : '';
            self::assertTrue(in_array($code, ['CLINIC_VALIDATION_FAILED', 'CLINIC_PATIENT_SELECTION_REQUIRED'], true));
        }

        // B2 must NOT accept arbitrary unvalidated patient_id merely to repair old rows.
        // Try to repair by sending patient_id at B2 — should be ignored or rejected, not allow arbitrary.
        $slotRepair = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:30:00', 1);
        $holdRepair = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slotRepair['date'],
            'slot_time' => $slotRepair['time'],
            'slot_id' => $slotRepair['slot_id'],
        ], asUserId: $userN, withNonce: true);
        // If holdRepair succeeded (RED), try B2 with arbitrary patient_id not linked.
        if ($holdRepair->get_status() === 200) {
            $unlinkedRepair = $this->insertPatient($this->clinicA, $this->mobileFor('t13e'), 'Repair', 'Decoy');
            $tokenR = (string) ($holdRepair->get_data()['data']['hold_token'] ?? '');
            $repair = $this->restPost(self::NS . self::CONFIRM_PATH, [
                'hold_token' => $tokenR,
                'patient_id' => $unlinkedRepair,
            ], asUserId: $userN, withNonce: true, idempotencyKey: $this->uuid());
            // Must not create Appointment for arbitrary unvalidated patient.
            self::assertSame(0, $this->countAppointmentsForPatient($unlinkedRepair), 'B2 must not accept arbitrary unvalidated patient_id to repair historical row.');
            // At minimum, if it fails, it must be generic validation, not 500.
            if ($repair->get_status() !== 200) {
                $rc = is_array($repair->get_data()) ? (string) ($repair->get_data()['code'] ?? '') : '';
                self::assertNotSame('CLINIC_INTERNAL_ERROR', $rc, 'Repair attempt must not be 500.');
            }
        }
    }

    // =================================================================
    // T14 SCHEMA — slot_holds.patient_id future contract
    // =================================================================

    public function testSlotHoldsPatientIdFutureSchemaContract(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_slot_holds';

        $col = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'patient_id'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        self::assertNotNull(
            $col,
            'Future contract: cpms_slot_holds.patient_id must exist (BIGINT UNSIGNED NULL, FK -> cpms_patients(id), no backfill, historical NULL supported).'
        );
        // If we reach here on RED, the column is absent and the assertion above already failed — valid RED.
        // The detailed checks below are only reachable at GREEN; they pin the exact contract for the migration.
        if ($col === null) {
            return;
        }

        $type = strtolower((string) ($col['Type'] ?? ''));
        self::assertStringContainsString('bigint', $type, 'patient_id must be BIGINT.');
        self::assertStringContainsString('unsigned', $type, 'patient_id must be UNSIGNED.');
        self::assertSame('YES', (string) ($col['Null'] ?? ''), 'patient_id must allow NULL (historical rows remain — no backfill).');
        self::assertNull($col['Default'] ?? null, 'patient_id must have NO default.');

        // FK to cpms_patients(id), ON DELETE RESTRICT
        $fk = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND COLUMN_NAME = %s
                   AND REFERENCED_TABLE_NAME = %s
                   AND REFERENCED_COLUMN_NAME = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $table,
                'patient_id',
                $wpdb->prefix . 'cpms_patients',
                'id'
            )
        );
        self::assertGreaterThanOrEqual(1, $fk, 'patient_id must carry FK to cpms_patients(id).');

        // Down migration must drop FK before column — tested via round-trip in GREEN only.
        // (We do not attempt rollback here at RED because column is absent.)
    }

    // =================================================================
    // T15 LATEST MIGRATION must have advanced beyond live baseline
    // =================================================================

    public function testLatestMigrationHasAdvancedBeyondLiveBaseline(): void
    {
        $current = App::migrations()->currentVersion();
        self::assertIsString($current, 'Current migration version must be readable.');
        self::assertNotSame(
            self::LIVE_MIGRATION_BASELINE,
            $current,
            'The slot_holds.patient_id binding requires its migration. Live main baseline is ' . self::LIVE_MIGRATION_BASELINE .
            ' (also pinned by MigrationTest::LATEST_VERSION) and the repository naming convention objectively defines the next number — this RED reserves no filename and adds no migration file.'
        );
    }

    // =================================================================
    // Fixture builders
    // =================================================================

    private function buildTwoClinicFixture(): void
    {
        $now = App::db()->nowUtcSql();
        $uid = uniqid('p8s3', false);

        $this->orgA = $this->insertRow('cpms_organizations', [
            'name' => 'Org P8S3 ' . $uid,
            'slug' => 'org-p8s3-' . $uid,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s'], 'organization');

        $this->clinicA = $this->insertClinic('Clinic A P8S3 ' . $uid, 'clinic-a-p8s3-' . $uid, $now);
        $this->clinicB = $this->insertClinic('Clinic B P8S3 ' . $uid, 'clinic-b-p8s3-' . $uid, $now);

        $this->locationA = $this->insertLocation($this->clinicA, 'Main A P8S3', 'main-a-p8s3-' . $uid, self::TZ_TEHRAN, 1);
        $this->locationB = $this->insertLocation($this->clinicB, 'Main B P8S3', 'main-b-p8s3-' . $uid, self::TZ_TEHRAN, 1);

        $this->clinicianA = $this->insertClinician($this->clinicA, 'Dr. Slice3 A ' . $uid, 1);
        $this->clinicianB = $this->insertClinician($this->clinicB, 'Dr. Slice3 B ' . $uid, 1);

        foreach ([$this->clinicA, $this->clinicB] as $cid) {
            $s = new Settings(App::db(), $cid, App::audit());
            $s->set('booking.min_lead_hours', 2);
            $s->set('booking.max_future_days', 60);
            $s->set('booking.hold_ttl_sec', 600);
            $s->set('otp.cooldown_sec', 0);
        }
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();

        self::assertGreaterThan(1, $this->clinicA, 'AD-13: dynamic Clinic never 1');
        self::assertNotSame($this->clinicA, $this->clinicB);
    }

    private function insertClinic(string $name, string $slug, string $now): int
    {
        return $this->insertRow('cpms_clinics', [
            'organization_id' => $this->orgA,
            'name' => $name,
            'slug' => $slug,
            'timezone' => self::TZ_TEHRAN,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s'], 'clinic');
    }

    private function insertLocation(int $clinicId, string $name, string $slug, string $tz, int $primary): int
    {
        return $this->insertRow('cpms_locations', [
            'clinic_id' => $clinicId,
            'name' => $name,
            'slug' => $slug,
            'timezone' => $tz,
            'is_primary' => $primary,
            'is_active' => 1,
            'created_at' => App::db()->nowUtcSql(),
            'updated_at' => App::db()->nowUtcSql(),
        ], ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s'], 'location');
    }

    private function insertClinician(int $clinicId, string $name, int $isActive): int
    {
        return $this->insertRow('cpms_clinicians', [
            'clinic_id' => $clinicId,
            'full_name' => $name,
            'is_active' => $isActive,
            'created_at' => App::db()->nowUtcSql(),
            'updated_at' => App::db()->nowUtcSql(),
        ], ['%d', '%s', '%d', '%s', '%s'], 'clinician');
    }

    private function insertPatient(int $clinicId, string $mobile, string $firstName, string $lastName, string $status = 'active'): int
    {
        return $this->insertRow('cpms_patients', [
            'clinic_id' => $clinicId,
            'mrn' => 'MR-P8S3-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'mobile' => $mobile,
            'status' => $status,
            'created_at' => App::db()->nowUtcSql(),
            'updated_at' => App::db()->nowUtcSql(),
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'], 'patient');
    }

    private function insertLink(int $clinicId, int $patientId, int $userId, string $mobile, int $isPrimary): int
    {
        return $this->insertRow('cpms_patient_user_links', [
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'wp_user_id' => $userId,
            'mobile_at_link' => $mobile,
            'is_primary' => $isPrimary,
            'linked_at' => App::db()->nowUtcSql(),
        ], ['%d', '%d', '%d', '%s', '%d', '%s'], 'link');
    }

    private function createPatientUserWithLinks(int $clinicId, array $specs): int
    {
        // $specs: list of ['id'=>patientId, 'mobile'=>string, 'primary'=>0/1]
        $uid = (int) wp_create_user('p8s3_user_' . uniqid('', false), 'pass-not-used-123', uniqid('p8s3_', true) . '@test.local');
        self::assertGreaterThan(0, $uid);
        $this->setRole($uid, 'cpms_patient');
        foreach ($specs as $s) {
            $this->insertLink($clinicId, (int) $s['id'], $uid, (string) $s['mobile'], (int) $s['primary']);
        }
        return $uid;
    }

    /**
     * @return array{slot_id: int, date: string, time: string}
     */
    private function seedSlot(int $clinicId, int $locationId, int $clinicianId, string $date, string $time, int $capacity): array
    {
        $slotId = $this->insertRow('cpms_schedule_slots', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'slot_date' => $date,
            'slot_time' => $time,
            'duration_min' => 20,
            'capacity' => $capacity,
            'booked_count' => 0,
            'held_count' => 0,
            'is_open' => 1,
            'generated_from' => 'manual',
            'created_at' => App::db()->nowUtcSql(),
            'updated_at' => App::db()->nowUtcSql(),
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s'], 'slot');
        return ['slot_id' => $slotId, 'date' => $date, 'time' => $time];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $formats
     */
    private function insertRow(string $table, array $data, array $formats, string $label): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . $table, $data, $formats);
        self::assertTrue((bool) $ok, 'fixture ' . $label . ' insert failed: ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture ' . $label . ' id must be positive/dynamic.');
        return $id;
    }

    // =================================================================
    // REST helpers
    // =================================================================

    /**
     * @param array<string, mixed> $body
     */
    private function restPost(string $route, array $body, ?int $asUserId = null, bool $withNonce = false, ?string $idempotencyKey = null): WP_REST_Response
    {
        wp_set_current_user($asUserId !== null ? $asUserId : 0);
        $request = new WP_REST_Request('POST', $route);
        foreach ($body as $k => $v) {
            $request->set_param($k, $v);
        }
        if ($withNonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        if ($idempotencyKey !== null) {
            $request->set_header('Idempotency-Key', $idempotencyKey);
        }
        return rest_do_request($request);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{response: ?WP_REST_Response, thrown: ?\Throwable}
     */
    private function restPostCatching(string $route, array $body, ?int $asUserId = null, bool $withNonce = false, ?string $idempotencyKey = null): array
    {
        try {
            return ['response' => $this->restPost($route, $body, $asUserId, $withNonce, $idempotencyKey), 'thrown' => null];
        } catch (\Throwable $e) {
            return ['response' => null, 'thrown' => $e];
        }
    }

    private function assertClinicError(WP_REST_Response $response, string $code, int $status, string $what): void
    {
        self::assertSame($status, $response->get_status(), $what . ' Body: ' . wp_json_encode($response->get_data()));
        $data = $response->get_data();
        self::assertIsArray($data, 'Error envelope must be array.');
        self::assertArrayHasKey('code', $data, 'Error envelope must carry code.');
        self::assertSame($code, (string) $data['code'], $what);
        self::assertArrayHasKey('message', $data);
        self::assertSame($status, (int) ($data['data']['status'] ?? 0), 'Envelope data.status must match HTTP.');
    }

    /**
     * Fail-closed B1 patient_id validation — canonical per live evidence:
     * - docs/api/error-codes.md: CLINIC_VALIDATION_FAILED = 400 (generic input validation)
     * - BookingService cross-Clinic / patient-mismatch uses 422 (business-rule):
     *   BookingService.php:1001 `CLINIC_VALIDATION_FAILED ... 422` for "این بیمار به کلینیک دیگری تعلق دارد",
     *   plus 1350/1357 slot/clinician mismatch → 422, and api-contract.md §0: 400 validation vs 422 business-rule.
     * B1 patient_id authority (linked-only, cross-Clinic, inactive, N>1 missing selection) is a
     * business-rule / tuple-mismatch, not a simple format error → canonical is 422.
     * Code MUST be CLINIC_VALIDATION_FAILED (or narrow CLINIC_PATIENT_SELECTION_REQUIRED).
     */
    private function assertClinicValidationFailed(WP_REST_Response $response, string $what): void
    {
        $status = $response->get_status();
        $data = $response->get_data();
        self::assertSame(422, $status, $what . ' must be 422 (business-rule) per BookingService 422 precedent. Got: ' . $status . ' Body: ' . wp_json_encode($data));
        self::assertIsArray($data, 'Error envelope must be array.');
        $code = (string) ($data['code'] ?? '');
        self::assertTrue(in_array($code, ['CLINIC_VALIDATION_FAILED', 'CLINIC_PATIENT_SELECTION_REQUIRED'], true), $what . ' code must be CLINIC_VALIDATION_FAILED (or narrow CLINIC_PATIENT_SELECTION_REQUIRED). Got: ' . $code);
        self::assertArrayHasKey('message', $data);
        self::assertSame(422, (int) ($data['data']['status'] ?? 0), 'Envelope data.status must match HTTP 422.');
    }

    // =================================================================
    // UI helpers
    // =================================================================

    private function renderSurface(int $clinicId): string
    {
        $html = do_shortcode('[' . self::SHORTCODE . ' clinic_id="' . $clinicId . '"]');
        self::assertIsString($html, 'do_shortcode must return string.');
        self::assertStringNotContainsString('[' . self::SHORTCODE, $html, 'Shortcode must be registered.');
        return $html;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractConfig(string $html): array
    {
        $matched = preg_match(
            '/<script type="application\/json" class="[^"]*' . preg_quote(self::CONFIG_CLASS, '/') . '[^"]*">(.*?)<\/script>/s',
            $html,
            $m
        );
        if ($matched !== 1) {
            return [];
        }
        $decoded = json_decode(trim($m[1]), true);
        return is_array($decoded) ? $decoded : [];
    }

    // =================================================================
    // Row readers / counters
    // =================================================================

    private function slotHoldsHasPatientIdColumn(): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_slot_holds';
        $col = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'patient_id'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        return $col !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function holdRowByToken(string $token): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_slot_holds') . ' WHERE token = %s LIMIT 1',
            [$token]
        );
        return is_array($row) ? $row : null;
    }

    private function countRows(string $short): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($short));
    }

    private function heldCountOf(int $slotId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT held_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        );
    }

    private function countAppointmentsForPatient(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE patient_id = %d',
            [$patientId]
        );
    }

    private function countAppointmentsForClinic(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE clinic_id = %d',
            [$clinicId]
        );
    }

    private function countPatientLinks(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE patient_id = %d',
            [$patientId]
        );
    }

    private function countPatientLinksForUser(int $userId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE wp_user_id = %d',
            [$userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function patientRowByMobile(int $clinicId, string $mobile): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d AND mobile = %s ORDER BY id DESC LIMIT 1',
            [$clinicId, $mobile]
        );
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function patientRowById(int $patientId): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d LIMIT 1',
            [$patientId]
        );
        return is_array($row) ? $row : null;
    }

    private function countPatientsForMobile(int $clinicId, string $mobile): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d AND mobile = %s',
            [$clinicId, $mobile]
        );
    }

    private function mobileFor(string $suffix): string
    {
        // Deterministic valid Iranian mobile per test suffix, still within format.
        // Use hash of suffix to avoid collisions, keep prefix 0912.
        $hash = substr(md5($suffix), 0, 7);
        // Ensure digits only: replace a-f with digits
        $hash = strtr($hash, ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6']);
        return '0912' . substr($hash, 0, 7);
    }

    private function ymdDaysAhead(int $days): string
    {
        return gmdate('Y-m-d', time() + $days * 86400);
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function setRole(int $userId, string $role): void
    {
        $user = get_userdata($userId);
        self::assertNotFalse($user, 'WP user must exist.');
        $user->set_role($role);
    }
}
