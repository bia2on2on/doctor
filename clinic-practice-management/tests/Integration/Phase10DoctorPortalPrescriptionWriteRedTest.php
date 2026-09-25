<?php
/**
 * Phase 10 — Doctor Portal Prescription Write (Visit Workspace slice) — TEST-ONLY RED.
 *
 * Approved slice contract (owner-approved):
 *   The existing Doctor Portal Visit Workspace displays prescriptions for the
 *   current authorized Visit; the doctor creates a structured prescription
 *   DRAFT with the established structured item fields, then FINALIZES the
 *   draft through the established lifecycle. Finalized Rx is read-only in
 *   this slice. Prescription void / print / handwriting-stylus are OUT.
 *
 * Intended portal boundary (same adapter pattern as the merged Visit
 * Workspace slice — narrow, portal-scoped; shared E10/E11 stay untouched):
 *   POST /clinic/v1/doctor/portal/visits/{id}/prescriptions      (draft create)
 *   POST /clinic/v1/doctor/portal/prescriptions/{id}/finalize    (draft -> finalized)
 *
 * Authority for BOTH portal writes (selector headers are validated against
 * persisted authority, never trusted by themselves):
 *   authenticated WP user + doctor role + cpms_rx_create
 *   + server-derived active clinician identity
 *   + active trusted Clinic
 *   + trusted operational Location (0 => fail closed, 1 => auto-resolution,
 *     N>1 => explicit trusted Location required, foreign/inactive/unassigned
 *     => fail closed, never guess first/primary when N>1)
 *   + Visit owned by that clinician and located at that Clinic/Location
 *     (create) resp. Prescription belonging to that authorized Visit
 *     (finalize)
 *   Cross-doctor / foreign-Clinic / foreign-Location / foreign-Visit /
 *   foreign-prescription selectors fail non-enumerating (404 CLINIC_NOT_FOUND).
 *
 * Established structured item contract (verified live — ClinicalService::
 * validateRxItem / initial schema): generic_name (required, <=190), dose
 * (required, <=64), frequency (required, <=64), form enum RX_FORMS, route
 * enum RX_ROUTES, duration_days 1..3650 (nullable), brand_name/strength/
 * instructions optional, drug_ref_id optional and must exist. Persisted
 * status = draft; prescription_number keeps RX-NNNNNN backend behavior;
 * PRESCRIPTION_CREATED / PRESCRIPTION_FINALIZED audit stays; finalize sets
 * finalized_at; repeated/invalid finalize keeps CLINIC_INVALID_TRANSITION 409.
 *
 * Established patient contract (verified live — C7 patientPrescriptions):
 *   draft is NEVER visible to the patient (independent of is_patient_visible);
 *   finalized + is_patient_visible=1 is visible; is_patient_visible=0 hidden.
 *   doctor_private note semantics are unrelated and not asserted here.
 *
 * INTENDED PRODUCT RED (missing portal behavior, verified pre-write):
 *   - the two portal write routes above do NOT exist on live main (REST
 *     dispatch returns 404 rest_no_route) — Groups 2..5 anchor here;
 *   - the portal shell/JS has NO prescription composer/list/finalize wiring —
 *     Group 7 anchors here.
 * Groups 1 and 6 are MATERIAL CONTROLS that are already GREEN and MUST stay
 * GREEN (existing portal architecture reached; shared/patient contracts
 * preserved). Groups 2/5 regression guards prove the shared E10/E11 backend
 * remains green (regression evidence, not the RED). Shared/admin/staff
 * E10/E11 behavior is NOT tightened globally by this slice.
 *
 * Live context (verified): main @ 972df97 (merge of PR #120 Visit Workspace);
 * latest migration 2026_09_20_0022; Phase 10 = IN PROGRESS.
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

final class Phase10DoctorPortalPrescriptionWriteRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';
    private const PORTAL_RECORD = 'clinic/v1/doctor/portal/visits/%d/record';
    private const PORTAL_RX_CREATE = 'clinic/v1/doctor/portal/visits/%d/prescriptions';
    private const PORTAL_RX_FINALIZE = 'clinic/v1/doctor/portal/prescriptions/%d/finalize';
    private const SHARED_RX_CREATE = 'clinic/v1/visits/%d/prescriptions';
    private const SHARED_RX_FINALIZE = 'clinic/v1/prescriptions/%d/finalize';
    private const PATIENT_RX_LIST = 'clinic/v1/prescriptions';
    private const FIXED_UTC = '2026-03-14 10:00:00';
    private const FIXED_UTC_DATE = '2026-03-14';
    private const TZ_TEHRAN = 'Asia/Tehran';

    /** Current established item enums (ClinicalService::RX_FORMS/RX_ROUTES). */
    private const RX_FORMS = ['tablet', 'capsule', 'syrup', 'injection', 'ointment', 'drops', 'inhaler', 'other'];
    private const RX_ROUTES = ['oral', 'iv', 'im', 'sc', 'topical', 'inhaled', 'other'];

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

    // ============ Group 1 — PORTAL RX ENTRY / READ (material control, GREEN) ============

    public function testGroup1_PortalWorkspaceExposesCurrentVisitPrescriptions(): void
    {
        $fx = $this->makePortalStage('g1');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g1_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        // REAL fixtures (established schema): one draft + one finalized Rx on this Visit.
        // D1 fix: distinct deterministic ordinal per prescription ROW on the same
        // Visit — the real u_rx_number uniqueness constraint must be honoured and
        // each fixture insert must be proven to have persisted (>0, helper guard).
        $rxDraft = $this->insertPrescription($visit, $patient, $fx['clinician'], $fx['clinic'], 'draft', 0, 0);
        $this->insertRxItem($rxDraft, [
            'generic_name' => 'Ibuprofen', 'brand_name' => null, 'strength' => '400mg',
            'form' => 'tablet', 'dose' => '1 قرص', 'frequency' => 'هر 8 ساعت',
            'route' => 'oral', 'duration_days' => 5, 'instructions' => 'بعد از غذا',
        ]);
        $rxFinal = $this->insertPrescription($visit, $patient, $fx['clinician'], $fx['clinic'], 'finalized', 1, 1);
        $this->insertRxItem($rxFinal, [
            'generic_name' => 'Amoxicillin', 'brand_name' => null, 'strength' => '500mg',
            'form' => 'capsule', 'dose' => '1 کپسول', 'frequency' => 'هر 12 ساعت',
            'route' => 'oral', 'duration_days' => 7, 'instructions' => null,
        ]);

        // D1 guard: BOTH fixture prescriptions on the same Visit really persisted
        // (per-row unique prescription_number — would have collided before the fix).
        self::assertGreaterThan(0, $rxDraft, 'G1.fixture: draft Rx row persisted');
        self::assertGreaterThan(0, $rxFinal, 'G1.fixture: finalized Rx row persisted');
        self::assertNotSame($rxDraft, $rxFinal, 'G1.fixture: two distinct prescription rows');
        $fixtureRows = App::db()->fetchAll(
            'SELECT id, prescription_number FROM ' . App::db()->table('cpms_prescriptions') .
            ' WHERE id IN (%d, %d) ORDER BY id ASC',
            [$rxDraft, $rxFinal]
        ) ?: [];
        self::assertCount(2, $fixtureRows, 'G1.fixture: both same-Visit prescription rows persisted in DB');
        self::assertNotSame(
            (string) $fixtureRows[0]['prescription_number'],
            (string) $fixtureRows[1]['prescription_number'],
            'G1.fixture: per-row unique prescription_number (real u_rx_number honoured)'
        );

        // The existing/portal architecture is REACHED: own authorized Visit
        // Workspace record must expose BOTH current-Visit prescriptions
        // (doctor view shows drafts too — established record() contract).
        $rPortal = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visit), [], $headers);
        $this->gate(200, $rPortal,
            'G1: authorized portal record reaches existing workspace architecture, got '
            . $rPortal->get_status() . '/' . $this->errCode($rPortal));

        $payload = $this->payload($rPortal);
        if (!isset($payload['prescriptions']) || !is_array($payload['prescriptions'])) {
            $this->annotate('control-G1', 'G1: portal record must carry prescriptions list', json_encode(array_keys($payload), JSON_UNESCAPED_UNICODE) ?: 'payload-keys-unavailable');
        }
        self::assertArrayHasKey('visit', $payload, 'G1: portal record carries visit');
        self::assertSame($visit, (int) $payload['visit']['id'], 'G1: correct visit');
        self::assertArrayHasKey('prescriptions', $payload, 'G1: portal workspace exposes current-Visit prescriptions');

        $byId = [];
        foreach ($payload['prescriptions'] as $rx) {
            $byId[(int) $rx['id']] = $rx;
        }
        self::assertArrayHasKey($rxDraft, $byId, 'G1: draft Rx of current Visit visible to doctor portal');
        self::assertArrayHasKey($rxFinal, $byId, 'G1: finalized Rx of current Visit visible to doctor portal');
        self::assertSame('draft', $byId[$rxDraft]['status'], 'G1: draft status round-trip');
        self::assertSame('finalized', $byId[$rxFinal]['status'], 'G1: finalized status round-trip');
        self::assertNotEmpty($byId[$rxDraft]['prescription_number'], 'G1: established prescription number present');
        self::assertCount(1, $byId[$rxDraft]['items'], 'G1: structured items round-trip');
        self::assertSame('Ibuprofen', $byId[$rxDraft]['items'][0]['generic_name'], 'G1: item generic_name');
        self::assertSame('1 قرص', $byId[$rxDraft]['items'][0]['dose'], 'G1: item dose');
        self::assertSame('هر 8 ساعت', $byId[$rxDraft]['items'][0]['frequency'], 'G1: item frequency');
        self::assertSame('tablet', $byId[$rxDraft]['items'][0]['form'], 'G1: item form enum');
        self::assertSame('oral', $byId[$rxDraft]['items'][0]['route'], 'G1: item route enum');
        self::assertSame(5, $byId[$rxDraft]['items'][0]['duration_days'], 'G1: item duration_days');
    }

    // ============ Group 2 — PORTAL AUTHORITY (intended RED anchor: boundary missing) ============

    public function testGroup2_PortalRxWriteAuthorityAndIsolation(): void
    {
        $fx = $this->makePortalStage('g2');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        $patientOwn = $this->insertPatient($fx['clinic'], 'g2_own');
        $visitOwn = $this->insertVisit($patientOwn, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        $doctorB = $this->makeUser('g2_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G2 B', $fx['clinic'], 1, $doctorB);
        cpms_test_seed_membership($doctorB, $fx['clinic'], 'cpms_doctor');
        $patientOther = $this->insertPatient($fx['clinic'], 'g2_other');
        $visitOther = $this->insertVisit($patientOther, $clinicianB, $fx['clinic'], $fx['location'], 'in_consultation');

        // D2 fix: a genuinely SEPARATE WP user for the foreign-clinic clinician —
        // reusing the stage doctor's user collides with u_clinician_user and used to
        // silently zero the clinician + foreign-Visit FK insert. Helpers now fail
        // loudly (>0) if any fixture ever breaks again.
        $foreignUser = $this->makeUser('g2_foreign_user', RolesAndCapabilities::ROLE_DOCTOR);
        $org = $this->insertOrg('G2 Foreign Org');
        $clinicForeign = $this->insertClinicInOrg('G2 Foreign Clinic', $org, self::TZ_TEHRAN);
        $locForeign = $this->insertLocation($clinicForeign, 'G2 Foreign Loc', self::TZ_TEHRAN, 1);
        $clinicianForeign = $this->insertClinician('Dr G2 Foreign', $clinicForeign, 1, $foreignUser);
        self::assertGreaterThan(0, $clinicianForeign, 'G2.fixture: foreign clinician persisted with a distinct WP user');
        $patientForeign = $this->insertPatient($clinicForeign, 'g2_foreign');
        $visitForeign = $this->insertVisit($patientForeign, $clinicianForeign, $clinicForeign, $locForeign, 'in_consultation');
        self::assertGreaterThan(0, $visitForeign, 'G2.fixture: foreign Visit really persisted');
        $visitForeignRow = $this->findVisitRow($visitForeign);
        self::assertSame($clinicForeign, (int) $visitForeignRow['clinic_id'], 'G2.fixture: foreign Visit bound to foreign Clinic');
        self::assertSame($clinicianForeign, (int) $visitForeignRow['clinician_id'], 'G2.fixture: foreign Visit bound to foreign clinician');

        $locOther = $this->insertLocation($fx['clinic'], 'G2 Other Loc', self::TZ_TEHRAN, 0);
        $patientOtherLoc = $this->insertPatient($fx['clinic'], 'g2_otherloc');
        $visitOtherLoc = $this->insertVisit($patientOtherLoc, $fx['clinician'], $fx['clinic'], $locOther, 'in_consultation');

        $body = ['items' => [$this->validItem()], 'is_patient_visible' => false];

        // A. Own doctor + trusted Clinic + trusted Location + own Visit => SUCCESS
        // (INTENDED PRODUCT RED: portal write boundary does not exist yet on main).
        wp_set_current_user($fx['doctor']);
        $rOwn = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_CREATE, $visitOwn), $body, $headers);
        $this->gate(200, $rOwn,
            'G2.A: portal draft create must succeed for authorized doctor at the intended portal boundary, got '
            . $rOwn->get_status() . '/' . $this->errCode($rOwn));
        $ownRx = $this->payload($rOwn);
        self::assertSame('draft', $ownRx['status'] ?? null, 'G2.A: persisted status must be draft');

        // B. Cross-doctor Visit selector => non-enumerating 404 at the portal boundary.
        wp_set_current_user($fx['doctor']);
        $rCross = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_CREATE, $visitOther), $body, $headers);
        $this->gate(404, $rCross, 'G2.B: cross-doctor portal create denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rCross), 'G2.B: non-enumerating 404 contract');

        // C. Foreign-Clinic Visit selector => non-enumerating 404.
        $rForeignClinic = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_CREATE, $visitForeign), $body, $headers);
        $this->gate(404, $rForeignClinic, 'G2.C: foreign-clinic portal create denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rForeignClinic), 'G2.C: non-enumerating 404 contract');

        // D. Foreign-Location Visit selector => non-enumerating 404.
        $rForeignLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_CREATE, $visitOtherLoc), $body, $headers);
        $this->gate(404, $rForeignLoc, 'G2.D: foreign-location portal create denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rForeignLoc), 'G2.D: non-enumerating 404 contract');

        // E. Secretary must be denied at the portal boundary (doctor-only slice).
        $secretary = $this->makeUser('g2_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($secretary);
        $rSecretary = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_CREATE, $visitOwn), $body, $headers);
        $this->gate(403, $rSecretary, 'G2.E: secretary denied at portal boundary');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rSecretary),
            'G2.E: secretary denial is the permission boundary');

        // ---- Regression guards: shared/admin E10 behavior stays untouched ----
        // Secretary on shared E10 was already denied (403) and MUST stay so.
        $rSharedSecretary = $this->dispatch('POST', '/' . sprintf(self::SHARED_RX_CREATE, $visitOwn), $body, $headers);
        $this->gate(403, $rSharedSecretary,
            'G2.reg-shared: shared E10 secretary denial preserved (global tightening prohibited in both directions)');

        // Owning doctor via SHARED E10 still succeeds (established backend — green);
        // proves the RED above is the MISSING PORTAL boundary, not a backend defect.
        wp_set_current_user($fx['doctor']);
        $rSharedDoctor = $this->dispatch('POST', '/' . sprintf(self::SHARED_RX_CREATE, $visitOwn), $body, $headers);
        $this->gate(200, $rSharedDoctor,
            'G2.reg-shared: shared E10 create still green for owning doctor (backend already implemented, got '
            . $rSharedDoctor->get_status() . ')');
    }

    // ============ Group 3 — DRAFT CREATE through portal (intended RED) ============

    public function testGroup3_PortalDraftCreatePersistsDraftWithEstablishedBehavior(): void
    {
        $fx = $this->makePortalStage('g3');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g3_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        $body = [
            'items' => [
                [
                    'generic_name' => 'استامینوفن',
                    'brand_name' => 'Tylenol',
                    'strength' => '500mg',
                    'form' => 'tablet',
                    'dose' => '1 قرص',
                    'frequency' => 'هر 8 ساعت',
                    'route' => 'oral',
                    'duration_days' => 5,
                    'instructions' => 'بعد از غذا',
                ],
                ['generic_name' => 'دیفن‌هیدرامین', 'dose' => '1 آمپول', 'frequency' => 'شب‌ها'],
            ],
            'is_patient_visible' => true,
        ];

        $rCreate = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_CREATE, $visit), $body, $headers);
        $this->gate(200, $rCreate,
            'G3: authorized portal draft create persists (intended portal boundary), got '
            . $rCreate->get_status() . '/' . $this->errCode($rCreate));

        $rx = $this->payload($rCreate);
        // persisted status must be draft (established lifecycle starts Draft).
        self::assertSame('draft', $rx['status'] ?? null, 'G3: persisted status must be draft');
        // prescription number uses established backend behavior (RX-NNNNNN).
        self::assertMatchesRegularExpression('/^RX-\d{6}$/', (string) ($rx['prescription_number'] ?? ''),
            'G3: prescription number uses established RX-NNNNNN backend behavior');
        // at least one valid structured item persists (both items round-trip).
        self::assertCount(2, $rx['items'] ?? [], 'G3: both structured items persisted');
        self::assertSame('استامینوفن', $rx['items'][0]['generic_name'] ?? null, 'G3: item generic_name');
        self::assertSame('Tylenol', $rx['items'][0]['brand_name'] ?? null, 'G3: item brand_name');
        self::assertSame('500mg', $rx['items'][0]['strength'] ?? null, 'G3: item strength');
        self::assertSame('tablet', $rx['items'][0]['form'] ?? null, 'G3: item form');
        self::assertSame('1 قرص', $rx['items'][0]['dose'] ?? null, 'G3: item dose');
        self::assertSame('هر 8 ساعت', $rx['items'][0]['frequency'] ?? null, 'G3: item frequency');
        self::assertSame('oral', $rx['items'][0]['route'] ?? null, 'G3: item route');
        self::assertSame(5, $rx['items'][0]['duration_days'] ?? null, 'G3: item duration_days');
        self::assertSame('بعد از غذا', $rx['items'][0]['instructions'] ?? null, 'G3: item instructions');
        self::assertSame(true, $rx['is_patient_visible'] ?? null, 'G3: prescription-level patient visibility contract round-trip');

        $rxId = (int) ($rx['id'] ?? 0);
        self::assertGreaterThan(0, $rxId, 'G3: persisted prescription id');

        // Row persists against the authorized Visit (no second Visit concept).
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_prescriptions') . ' WHERE id = %d',
            [$rxId]
        );
        self::assertIsArray($row, 'G3: prescription row persisted');
        self::assertSame('draft', (string) $row['status'], 'G3: persisted row status=draft');
        self::assertSame($visit, (int) $row['visit_id'], 'G3: row bound to the current authorized Visit');
        self::assertSame($patient, (int) $row['patient_id'], 'G3: row bound to visit patient');
        self::assertSame($fx['clinic'], (int) $row['clinic_id'], 'G3: row bound to trusted Clinic');
        self::assertSame($fx['clinician'], (int) $row['clinician_id'], 'G3: row bound to server-derived clinician');
        self::assertNull($row['finalized_at'], 'G3: draft has no finalized_at yet');

        // Established audit behavior intact (PRESCRIPTION_CREATED).
        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_type = %s AND resource_id = %d ORDER BY id DESC LIMIT 1',
            ['PRESCRIPTION_CREATED', 'prescription', $rxId]
        );
        self::assertIsArray($audit, 'G3: PRESCRIPTION_CREATED audit row exists (established audit intact)');
        self::assertSame($fx['doctor'], (int) $audit['actor_wp_user_id'], 'G3: audit actor is the authorized doctor');
        self::assertSame($patient, (int) $audit['patient_id'], 'G3: audit patient binding');
    }

    // ============ Group 4 — ITEM VALIDATION via portal (intended RED) ============

    public function testGroup4_PortalRxItemValidationUsesExistingDomainContract(): void
    {
        $fx = $this->makePortalStage('g4');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g4_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $route = '/' . sprintf(self::PORTAL_RX_CREATE, $visit);

        // Existing domain contract enforced at the portal boundary too:
        // empty items ...
        $rEmpty = $this->dispatch('POST', $route, ['items' => []], $headers);
        $this->gate(422, $rEmpty, 'G4: empty items rejected at portal boundary');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rEmpty), 'G4: empty items => existing validation code');

        // required fields (existing domain rules) ...
        $rNoDose = $this->dispatch('POST', $route, [
            'items' => [['generic_name' => 'دارو', 'frequency' => '1', 'form' => 'tablet', 'route' => 'oral']],
        ], $headers);
        $this->gate(422, $rNoDose, 'G4: missing required dose rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rNoDose), 'G4: missing dose code');

        $rNoGeneric = $this->dispatch('POST', $route, [
            'items' => [['dose' => '1', 'frequency' => '1']],
        ], $headers);
        $this->gate(422, $rNoGeneric, 'G4: missing required generic_name rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rNoGeneric), 'G4: missing generic_name code');

        $rNoFreq = $this->dispatch('POST', $route, [
            'items' => [['generic_name' => 'دارو', 'dose' => '1']],
        ], $headers);
        $this->gate(422, $rNoFreq, 'G4: missing required frequency rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rNoFreq), 'G4: missing frequency code');

        // current enums (no invented catalog/schema) ...
        $rBadForm = $this->dispatch('POST', $route, [
            'items' => [['generic_name' => 'دارو', 'dose' => '1', 'frequency' => '1', 'form' => 'lollipop']],
        ], $headers);
        $this->gate(422, $rBadForm, 'G4: form outside current enum RX_FORMS rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rBadForm), 'G4: form enum code');

        $rBadRoute = $this->dispatch('POST', $route, [
            'items' => [['generic_name' => 'دارو', 'dose' => '1', 'frequency' => '1', 'route' => 'venous']],
        ], $headers);
        $this->gate(422, $rBadRoute, 'G4: route outside current enum RX_ROUTES rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rBadRoute), 'G4: route enum code');

        // current scalar limits (duration_days 1..3650) ...
        $rDurZero = $this->dispatch('POST', $route, [
            'items' => [['generic_name' => 'دارو', 'dose' => '1', 'frequency' => '1', 'duration_days' => 0]],
        ], $headers);
        $this->gate(422, $rDurZero, 'G4: duration_days=0 rejected (existing 1..3650 limit)');
        $rDurHuge = $this->dispatch('POST', $route, [
            'items' => [['generic_name' => 'دارو', 'dose' => '1', 'frequency' => '1', 'duration_days' => 10000]],
        ], $headers);
        $this->gate(422, $rDurHuge, 'G4: duration_days>3650 rejected (existing limit)');

        // optional drug_ref_id must reference an existing drug_reference row
        // (established rule; NO catalog/schema invention in the slice).
        $rBadDrugRef = $this->dispatch('POST', $route, [
            'items' => [['generic_name' => 'دارو', 'dose' => '1', 'frequency' => '1', 'drug_ref_id' => 999999]],
        ], $headers);
        $this->gate(404, $rBadDrugRef, 'G4: unknown drug_ref_id keeps established 404');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rBadDrugRef), 'G4: unknown drug_ref_id code');

        // Sanity control: all established forms/routes are accepted by the
        // existing contract at the portal boundary (desk validation).
        $formsJoined = implode(',', self::RX_FORMS);
        $routesJoined = implode(',', self::RX_ROUTES);
        self::assertSame('tablet,capsule,syrup,injection,ointment,drops,inhaler,other', $formsJoined,
            'G4: enums mirror the current domain contract');
        self::assertSame('oral,iv,im,sc,topical,inhaled,other', $routesJoined,
            'G4: enums mirror the current domain contract');
    }

    // ============ Group 5 — FINALIZE authority + transition (mixed RED/GREEN) ============

    public function testGroup5_PortalFinalizeAuthorityAndEstablishedTransition(): void
    {
        $fx = $this->makePortalStage('g5');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        $patient = $this->insertPatient($fx['clinic'], 'g5_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        // REAL fixture via the established SHARED backend (green, pre-existing).
        wp_set_current_user($fx['doctor']);
        $rShared = $this->dispatch('POST', '/' . sprintf(self::SHARED_RX_CREATE, $visit), [
            'items' => [$this->validItem()], 'is_patient_visible' => true,
        ], $headers);
        $this->gate(200, $rShared,
            'G5.fixture: shared E10 backend create is green (regression evidence, got ' . $rShared->get_status() . ')');
        $rxDraft = $this->payload($rShared);
        $rxId = (int) ($rxDraft['id'] ?? 0);
        self::assertGreaterThan(0, $rxId, 'G5.fixture: draft persists via shared backend');
        self::assertSame('draft', $rxDraft['status'] ?? null, 'G5.fixture: persisted status is draft');

        // A. Own authorized draft CAN be finalized through the portal boundary
        // (INTENDED PRODUCT RED: portal finalize boundary missing on main).
        $rFin = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_FINALIZE, $rxId), [], $headers);
        $this->gate(200, $rFin,
            'G5.A: authorized portal finalize succeeds at the intended boundary, got '
            . $rFin->get_status() . '/' . $this->errCode($rFin));
        $fin = $this->payload($rFin);
        self::assertSame('finalized', $fin['status'] ?? null, 'G5.A: draft -> finalized');
        self::assertNotEmpty($fin['finalized_at'] ?? null, 'G5.A: finalized_at established');
        $finRow = App::db()->fetchRow(
            'SELECT status, finalized_at FROM ' . App::db()->table('cpms_prescriptions') . ' WHERE id = %d',
            [$rxId]
        );
        self::assertSame('finalized', (string) ($finRow['status'] ?? ''), 'G5.A: persisted status finalized');
        self::assertNotEmpty($finRow['finalized_at'] ?? null, 'G5.A: persisted finalized_at');
        $auditFin = App::db()->fetchRow(
            'SELECT id FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_type = %s AND resource_id = %d ORDER BY id DESC LIMIT 1',
            ['PRESCRIPTION_FINALIZED', 'prescription', $rxId]
        );
        self::assertIsArray($auditFin, 'G5.A: PRESCRIPTION_FINALIZED audit row exists (established audit intact)');

        // B. Foreign prescription cannot be finalized through the portal
        // boundary (non-enumerating 404) — foreign-clinic prescription.
        // D2 fix: foreign clinician gets its OWN WP user (u_clinician_user 1:1).
        $foreignUser = $this->makeUser('g5_foreign_user', RolesAndCapabilities::ROLE_DOCTOR);
        $orgF = $this->insertOrg('G5 Foreign Org');
        $clinicF = $this->insertClinicInOrg('G5 Foreign Clinic', $orgF, self::TZ_TEHRAN);
        $locF = $this->insertLocation($clinicF, 'G5 Foreign Loc', self::TZ_TEHRAN, 1);
        $clinicianF = $this->insertClinician('Dr G5 Foreign', $clinicF, 1, $foreignUser);
        self::assertGreaterThan(0, $clinicianF, 'G5.B.fixture: foreign clinician persisted with a distinct WP user');
        $patientF = $this->insertPatient($clinicF, 'g5_foreign');
        $visitF = $this->insertVisit($patientF, $clinicianF, $clinicF, $locF, 'in_consultation');
        self::assertGreaterThan(0, $visitF, 'G5.B.fixture: foreign Visit really persisted');
        $rxForeign = $this->insertPrescription($visitF, $patientF, $clinicianF, $clinicF, 'draft', 1);

        wp_set_current_user($fx['doctor']);
        $rFinForeign = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_FINALIZE, $rxForeign), [], $headers);
        $this->gate(404, $rFinForeign,
            'G5.B: foreign prescription finalize denied non-enumerating at the portal boundary');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rFinForeign), 'G5.B: non-enumerating 404 contract');
        $rowForeign = App::db()->fetchRow(
            'SELECT status FROM ' . App::db()->table('cpms_prescriptions') . ' WHERE id = %d',
            [$rxForeign]
        );
        self::assertSame('draft', (string) ($rowForeign['status'] ?? ''), 'G5.B: foreign prescription untouched');

        // C. Cross-doctor prescription (same Clinic, another doctor) cannot be
        // finalized through the portal boundary either (non-enumerating 404).
        $doctorB = $this->makeUser('g5_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G5 B', $fx['clinic'], 1, $doctorB);
        cpms_test_seed_membership($doctorB, $fx['clinic'], 'cpms_doctor');
        $patientB = $this->insertPatient($fx['clinic'], 'g5_b');
        $visitB = $this->insertVisit($patientB, $clinicianB, $fx['clinic'], $fx['location'], 'in_consultation');
        wp_set_current_user($doctorB);
        $rSharedB = $this->dispatch('POST', '/' . sprintf(self::SHARED_RX_CREATE, $visitB), [
            'items' => [$this->validItem()], 'is_patient_visible' => true,
        ], $headers);
        $this->gate(200, $rSharedB, 'G5.C.fixture: doctorB draft via shared backend green');
        $rxB = (int) $this->payload($rSharedB)['id'];

        wp_set_current_user($fx['doctor']);
        $rFinB = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_FINALIZE, $rxB), [], $headers);
        $this->gate(404, $rFinB,
            'G5.C: cross-doctor prescription finalize denied non-enumerating at the portal boundary');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rFinB), 'G5.C: non-enumerating 404 contract');

        // D. Established invalid-transition semantics preserved (GREEN — shared
        // backend already implemented and MUST stay unchanged): repeated
        // finalize via the SHARED route is a 409 CLINIC_INVALID_TRANSITION and
        // the row remains finalized (no new state invented).
        wp_set_current_user($doctorB);
        $rFin1 = $this->dispatch('POST', '/' . sprintf(self::SHARED_RX_FINALIZE, $rxB), [], $headers);
        $this->gate(200, $rFin1, 'G5.D: shared finalize succeeds for owning doctor (green)');
        $rFin2 = $this->dispatch('POST', '/' . sprintf(self::SHARED_RX_FINALIZE, $rxB), [], $headers);
        $this->gate(409, $rFin2,
            'G5.D: repeated/invalid finalize keeps established 409 (no new state, got ' . $rFin2->get_status() . ')');
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($rFin2),
            'G5.D: established invalid-transition code unchanged');
        $rowB = App::db()->fetchRow(
            'SELECT status FROM ' . App::db()->table('cpms_prescriptions') . ' WHERE id = %d',
            [$rxB]
        );
        self::assertSame('finalized', (string) ($rowB['status'] ?? ''), 'G5.D: row stays finalized after invalid retry');
    }

    // ============ Group 6 — PATIENT VISIBILITY contract preserved (GREEN) ============

    public function testGroup6_PatientVisibilityContractPreservedWithPortalSlice(): void
    {
        $fx = $this->makePortalStage('g6');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g6_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        // Fixtures through the ESTABLISHED backend (all green — regression evidence):
        // 1) draft that is "patient visible" flagged => STILL hidden (draft rule).
        $rDraft = $this->dispatch('POST', '/' . sprintf(self::SHARED_RX_CREATE, $visit), [
            'items' => [['generic_name' => 'داروی پیش‌نویس', 'dose' => '1', 'frequency' => '1']],
            'is_patient_visible' => true,
        ], $headers);
        $this->gate(200, $rDraft, 'G6.fixture: draft create green');
        $draft = $this->payload($rDraft);

        // 2) finalized + is_patient_visible=1 => established patient-visible.
        $rVisible = $this->dispatch('POST', '/' . sprintf(self::SHARED_RX_CREATE, $visit), [
            'items' => [['generic_name' => 'استامینوفن', 'dose' => '1', 'frequency' => 'روزانه']],
            'is_patient_visible' => true,
        ], $headers);
        $this->gate(200, $rVisible, 'G6.fixture: visible create green');
        $visible = $this->payload($rVisible);

        // 3) finalized + is_patient_visible=0 => hidden.
        $rHidden = $this->dispatch('POST', '/' . sprintf(self::SHARED_RX_CREATE, $visit), [
            'items' => [['generic_name' => 'داروی پنهان', 'dose' => '1', 'frequency' => '1']],
            'is_patient_visible' => false,
        ], $headers);
        $this->gate(200, $rHidden, 'G6.fixture: hidden create green');
        $hidden = $this->payload($rHidden);

        $rFinV = $this->dispatch('POST', '/' . sprintf(self::SHARED_RX_FINALIZE, (int) $visible['id']), [], $headers);
        $this->gate(200, $rFinV, 'G6.fixture: visible finalize green');
        $rFinH = $this->dispatch('POST', '/' . sprintf(self::SHARED_RX_FINALIZE, (int) $hidden['id']), [], $headers);
        $this->gate(200, $rFinH, 'G6.fixture: hidden finalize green');

        // Linked patient reads the established C7 endpoint (single link => auto-resolution).
        $patientUser = $this->makeUser('g6_patient_user', RolesAndCapabilities::ROLE_PATIENT);
        global $wpdb;
        $mobile = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT mobile FROM ' . $wpdb->prefix . 'cpms_patients WHERE id = %d',
            $patient
        ));
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_patient_user_links', [
            'clinic_id' => $fx['clinic'],
            'patient_id' => $patient,
            'wp_user_id' => $patientUser,
            'mobile_at_link' => $mobile,
            'is_primary' => 1,
            'linked_at' => $now,
        ]);
        wp_set_current_user($patientUser);
        $rPatient = $this->dispatch('GET', '/' . self::PATIENT_RX_LIST);
        $this->gate(200, $rPatient, 'G6: established patient prescriptions endpoint accessible');

        $numbers = array_column($this->payload($rPatient)['prescriptions'] ?? [], 'prescription_number');
        if (
            !in_array($visible['prescription_number'], $numbers, true)
            || in_array($hidden['prescription_number'], $numbers, true)
            || in_array($draft['prescription_number'], $numbers, true)
        ) {
            $this->annotate('control-G6', 'G6: established patient visibility contract drifted', json_encode($numbers, JSON_UNESCAPED_UNICODE) ?: 'unknown');
        }
        self::assertContains($visible['prescription_number'], $numbers,
            'G6: finalized + patient-visible Rx remains visible (established patient contract)');
        self::assertNotContains($hidden['prescription_number'], $numbers,
            'G6: is_patient_visible=0 stays hidden (no leakage)');
        self::assertNotContains($draft['prescription_number'], $numbers,
            'G6: draft NEVER visible to patient even with is_patient_visible=1 (no visibility state invented)');
    }

    // ============ Group 7 — PORTAL UI WIRING CONTRACT (intended RED: composer missing) ============

    public function testGroup7_PortalUiPrescriptionWriteWiringContract(): void
    {
        $fx = $this->makePortalStage('g7');

        // The real merged portal still renders (proves the harness reached the
        // product path — GREEN material control).
        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'G7: independent Doctor Portal frontend entry must exist');
        $html = $this->renderPortal($fx['doctor'], $url);
        self::assertStringContainsString('cpms-doctor-portal-shell', $html, 'G7: real portal shell rendered');

        $root = dirname(__DIR__, 2);
        $templatePath = $root . '/templates/doctor-portal-shell.php';
        $jsPath = $root . '/assets/js/cpms-doctor-portal.js';
        self::assertFileExists($templatePath, 'G7: portal template exists');
        self::assertFileExists($jsPath, 'G7: portal JS exists');
        $ui = (string) file_get_contents($templatePath) . "\n" . (string) file_get_contents($jsPath);
        unset($fx);

        // --- GREEN guards (pass now; must keep passing after GREEN) ---
        // (a) Visit Workspace markers (queue row -> record/note wiring) untouched.
        foreach (['workspace-note-form', 'workspace-visibility', 'workspace-content', 'workspace-note-submit'] as $keep) {
            self::assertStringContainsString($keep, $ui, 'G7 guard: Visit Workspace note wiring preserved (' . $keep . ')');
        }
        // (b) no exclusion-scope controls anywhere near the portal UI:
        // no void (این برش void را ندارد), no print, no handwriting/stylus.
        foreach (['rx-void', 'rx_void', 'data-action="void"', 'voidPrescription', 'rx-print', 'stylus', 'handwriting-canvas'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $ui, 'G7 guard: out-of-scope control must not exist (' . $forbidden . ')');
        }

        // --- INTENDED PRODUCT RED: prescription composer/wiring missing ---
        $missing = [];
        // composer uses the current structured item fields only.
        foreach (['workspace-rx-section', 'workspace-rx-list', 'workspace-rx-form'] as $marker) {
            if (!str_contains($ui, 'data-role="' . $marker . '"')) {
                $missing[] = 'composer area marker (' . $marker . ')';
            }
        }
        foreach (['workspace-rx-generic-name', 'workspace-rx-dose', 'workspace-rx-frequency', 'workspace-rx-route', 'workspace-rx-duration-days', 'workspace-rx-instructions'] as $marker) {
            if (!str_contains($ui, 'data-role="' . $marker . '"')) {
                $missing[] = 'composer field marker (' . $marker . ')';
            }
        }
        if (!str_contains($ui, 'data-role="workspace-rx-form-select"')) {
            $missing[] = 'composer field marker (workspace-rx-form-select)';
        }
        // composer enumerates the CURRENT domain enums only (no new catalog/schema).
        foreach (['tablet', 'capsule', 'syrup', 'injection', 'ointment', 'drops', 'inhaler', 'other'] as $form) {
            if (!str_contains($ui, 'value="' . $form . '"')) {
                $missing[] = 'form enum option (' . $form . ')';
                break;
            }
        }
        foreach (['oral', 'iv', 'im', 'sc', 'topical', 'inhaled', 'other'] as $routeEnum) {
            if (!str_contains($ui, 'value="' . $routeEnum . '"')) {
                $missing[] = 'route enum option (' . $routeEnum . ')';
                break;
            }
        }
        // working states: busy/success/error wiring so failed persistence can
        // never turn into visual success (submit/busy/success/error markers).
        foreach (['workspace-rx-submit', 'workspace-rx-busy', 'workspace-rx-error', 'workspace-rx-success'] as $marker) {
            if (!str_contains($ui, 'data-role="' . $marker . '"')) {
                $missing[] = 'busy/success/error state marker (' . $marker . ')';
            }
        }
        // finalize control + finalized read-only rendering in this slice.
        if (!str_contains($ui, 'data-role="workspace-rx-finalize"')) {
            $missing[] = 'finalize control (workspace-rx-finalize)';
        }
        if (!str_contains($ui, 'data-role="workspace-rx-readonly"')) {
            $missing[] = 'finalized read-only rendering (workspace-rx-readonly)';
        }
        // portal-boundary POST wiring (create + finalize) with the selector headers.
        if (!str_contains($ui, '/doctor/portal/visits/') || !str_contains($ui, '/prescriptions')) {
            $missing[] = 'POST wiring to /doctor/portal/visits/{id}/prescriptions';
        }
        if (!str_contains($ui, '/doctor/portal/prescriptions/') || !str_contains($ui, '/finalize')) {
            $missing[] = 'POST wiring to /doctor/portal/prescriptions/{id}/finalize';
        }
        foreach (['X-WP-Nonce', 'X-CPMS-Clinic-Id', 'X-CPMS-Location-Id'] as $header) {
            if (!str_contains($ui, $header)) {
                $missing[] = 'selector header on rx wiring (' . $header . ')';
            }
        }
        if ($missing !== []) {
            $this->annotate('red-G7', 'G7: Doctor Portal prescription composer/list/finalize/read-only wiring missing', implode('; ', $missing));
        }
        self::assertSame([], $missing,
            'G7: Doctor Portal prescription composer/list/finalize/read-only wiring missing: ' . implode('; ', $missing));
    }

    // ============ Location policy for writes (intended RED; same 0/1/N rule) ============

    public function testGroup2b_PortalRxWriteLocationPolicy(): void
    {
        // N>1 eligible Location => explicit trusted Location required on write.
        $fxMulti = $this->makePortalStage('g2bm');
        $loc2 = $this->insertLocation($fxMulti['clinic'], 'G2b Second Loc', self::TZ_TEHRAN, 0);
        unset($loc2);
        $patientMulti = $this->insertPatient($fxMulti['clinic'], 'g2bm_patient');
        $visitMulti = $this->insertVisit($patientMulti, $fxMulti['clinician'], $fxMulti['clinic'], $fxMulti['location'], 'in_consultation');

        wp_set_current_user($fxMulti['doctor']);
        $headersNoLoc = $this->scopeHeaders($fxMulti['clinic'], null);
        $rNoLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_CREATE, $visitMulti), [
            'items' => [$this->validItem()],
        ], $headersNoLoc);
        $this->gate(400, $rNoLoc,
            'G2b.E: portal Rx write with N>1 eligible and no explicit Location requires 400');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($rNoLoc), 'G2b.E: Location required code');
        $rawNoLoc = $this->rawErrorData($rNoLoc);
        self::assertSame('location_id', $rawNoLoc['field'] ?? null, 'G2b.E: field=location_id');
        self::assertSame('location_required', $rawNoLoc['reason'] ?? null, 'G2b.E: reason=location_required');
        self::assertArrayNotHasKey('eligible_location_ids', $rawNoLoc, 'G2b.E: must not leak eligible IDs');

        // With explicit trusted Location the same write path succeeds.
        $headersWithLoc = $this->scopeHeaders($fxMulti['clinic'], $fxMulti['location']);
        $rWithLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_CREATE, $visitMulti), [
            'items' => [$this->validItem()],
        ], $headersWithLoc);
        $this->gate(200, $rWithLoc, 'G2b.E: explicit trusted Location write succeeds');

        // 1 eligible => auto-resolution allowed on write (no Location header).
        $fxSingle = $this->makePortalStage('g2bs');
        $patientSingle = $this->insertPatient($fxSingle['clinic'], 'g2bs_patient');
        $visitSingle = $this->insertVisit($patientSingle, $fxSingle['clinician'], $fxSingle['clinic'], $fxSingle['location'], 'in_consultation');
        wp_set_current_user($fxSingle['doctor']);
        $rSingle = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_CREATE, $visitSingle), [
            'items' => [$this->validItem()],
        ], $this->scopeHeaders($fxSingle['clinic'], null));
        $this->gate(200, $rSingle, 'G2b.E: single-location auto-resolution write succeeds');

        // 0 eligible => fail closed on write.
        $fxZero = $this->makePortalStage('g2bz');
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE clinic_id = %d', $fxZero['clinic']));
        $patientZero = $this->insertPatient($fxZero['clinic'], 'g2bz_patient');
        $visitZero = $this->insertVisit($patientZero, $fxZero['clinician'], $fxZero['clinic'], $fxZero['location'], 'in_consultation');
        wp_set_current_user($fxZero['doctor']);
        $rZero = $this->dispatch('POST', '/' . sprintf(self::PORTAL_RX_CREATE, $visitZero), [
            'items' => [$this->validItem()],
        ], $this->scopeHeaders($fxZero['clinic'], $fxZero['location']));
        $this->gateIn([403, 404], $rZero, 'G2b.F: zero eligible Locations fails closed on write');
    }

    // ================= helpers (proven Slice 3/Visit Workspace patterns) =================

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

    /**
     * One valid structured item matching the CURRENT established contract.
     *
     * @return array<string, mixed>
     */
    private function validItem(array $overrides = []): array
    {
        return $overrides + [
            'generic_name' => 'Amoxicillin',
            'brand_name' => 'Amoxil',
            'strength' => '500mg',
            'form' => 'capsule',
            'dose' => '1 کپسول',
            'frequency' => 'هر 12 ساعت',
            'route' => 'oral',
            'duration_days' => 7,
            'instructions' => 'با غذا',
        ];
    }

    /**
     * Annotating status gate: identical semantics to assertSame($wanted, $r->get_status(), $message)
     * plus a standard CI runner annotation (::error) emitted ONLY when the status
     * is unexpected, so the exact failing assertion text is retrievable from the
     * check-run annotations (job log blobs are not reachable from the sandbox).
     */
    private function gate(int $wanted, WP_REST_Response $r, string $message): void
    {
        $got = $r->get_status();
        if ($got !== $wanted) {
            $this->annotate('status', $message, 'expected=' . $wanted . ' got=' . $got . ' code=' . $this->errCode($r));
        }
        self::assertSame($wanted, $got, $message);
    }

    /**
     * @param list<int> $wantedSet
     */
    private function gateIn(array $wantedSet, WP_REST_Response $r, string $message): void
    {
        $got = $r->get_status();
        if (!in_array($got, $wantedSet, true)) {
            $this->annotate('status-set', $message, 'expected=' . implode('|', $wantedSet) . ' got=' . $got . ' code=' . $this->errCode($r));
        }
        self::assertContains($got, $wantedSet, $message);
    }

    private function annotate(string $kind, string $message, string $detail): void
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $message . ' :: ' . $detail));
        // Runner command escaping (workflow command v2 parameter values).
        $esc = str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', ' -', ';'], $text);
        // Unique title per assertion site (G-label) — runner dedupes identical titles.
        $tokens = trim(preg_replace('/[^A-Za-z0-9._-]/', '', strstr($message, ':', true) ?: substr($message, 0, 12)));
        // STDERR bypasses PHPUnit output buffering (echo would be swallowed).
        fwrite(STDERR, '::error title=CPMS-Phase10RxWrite-' . $kind . '-' . $tokens . '::' . $esc . PHP_EOL);
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
    private function rawErrorData(WP_REST_Response $res): array
    {
        $b = $res->get_data();
        if ($b instanceof \WP_Error) {
            $d = $b->get_error_data();
            return is_array($d) ? $d : [];
        }
        if (is_array($b) && isset($b['data']) && is_array($b['data'])) {
            return is_array($b['data']) ? $b['data'] : [];
        }
        return is_array($b) ? $b : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function findVisitRow(int $visitId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visitId]
        );
        self::assertIsArray($row, 'visit row must exist in DB');
        return $row;
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
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'organization fixture row must persist');
        return $id;
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
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'location fixture row must persist');
        return $id;
    }

    private function insertClinician(string $name, int $clinicId, int $active, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s)', $clinicId, $name, $wpUserId, $active, $now, $now));
        $id = (int) $wpdb->insert_id;
        // Loud fixture guard (D2): duplicate wp_user_id (u_clinician_user) must fail LOUDLY.
        self::assertGreaterThan(0, $id, 'clinician fixture row must persist (wp_user_id must be distinct)');
        return $id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-QA-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2));
        $mobile = '0912' . sprintf('%07d', random_int(1000000, 9999999));
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', $clinicId, $mrn, 'Test', 'Patient ' . substr($mrn, -4), $mobile, 'active', $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient fixture row must persist');
        return $id;
    }

    private function insertVisit(int $patientId, int $clinicianId, int $clinicId, int $locId, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $active = 'skipped' === $status ? 0 : 1;
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %d, %s, %s)', $clinicId, $locId, $clinicianId, $patientId, 'walk_in', $status, self::FIXED_UTC_DATE, self::FIXED_UTC_DATE . ' 10:00:00', self::FIXED_UTC_DATE . ' 10:00:00', $active, $now, $now));
        $id = (int) $wpdb->insert_id;
        // Loud fixture guard (D2): FK/duplicate failures must fail LOUDLY, never become
        // a fake "404 proof" via insert_id = 0.
        self::assertGreaterThan(0, $id, 'visit fixture row must persist (clinician/location patient FKs must be valid)');
        return $id;
    }

    /**
     * REAL fixture row — established schema (status draft/finalized; no void in this slice).
     *
     * D1 fix: prescription_number is deterministically unique PER ROW. Service-generated
     * numbers live in the RX-000xxx band; fixture space uses 'RX-9' + padded visit id
     * (4 digits) + an explicit per-call ordinal, so two fixture rows on the SAME Visit
     * can never collide on the real u_rx_number UNIQUE key. No randomness — fully
     * reproducible; the product constraint is honoured, never relaxed/bypassed.
     */
    private function insertPrescription(int $visitId, int $patientId, int $clinicianId, int $clinicId, string $status, int $visible, int $ordinal = 0): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $number = 'RX-9' . sprintf('%04d', $visitId % 10000) . $ordinal;
        $finalizedAt = 'finalized' === $status ? $wpdb->prepare('%s', $now) : 'NULL';
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_prescriptions (clinic_id, prescription_number, visit_id, patient_id, clinician_id, status, is_patient_visible, void_reason, correction_of_prescription_id, finalized_at, created_at, updated_at) VALUES (%d, %s, %d, %d, %d, %s, %d, NULL, NULL, '
            . $finalizedAt . ', %s, %s)',
            $clinicId, $number, $visitId, $patientId, $clinicianId, $status, $visible, $now, $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'prescription fixture row must persist');
        return $id;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function insertRxItem(int $rxId, array $item): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        // Nullable columns must be real NULL tokens (strict-mode safe).
        $tok = static function ($v, string $type = '%s') use ($wpdb): string {
            return $v === null ? 'NULL' : (string) $wpdb->prepare($type, $v);
        };
        $sql = 'INSERT INTO ' . $wpdb->prefix . 'cpms_prescription_items '
            . '(prescription_id, drug_ref_id, generic_name, brand_name, strength, form, dose, frequency, route, duration_days, instructions, source, ocr_job_id, sort_order, created_at) VALUES ('
            . (int) $rxId . ', NULL, '
            . $tok($item['generic_name']) . ', '
            . $tok($item['brand_name'] ?? null) . ', '
            . $tok($item['strength'] ?? null) . ', '
            . $tok($item['form'] ?? 'tablet') . ', '
            . $tok($item['dose']) . ', '
            . $tok($item['frequency']) . ', '
            . $tok($item['route'] ?? 'oral') . ', '
            . $tok($item['duration_days'] ?? null, '%d') . ', '
            . $tok($item['instructions'] ?? null) . ', '
            . $tok('manual') . ', NULL, 0, '
            . $tok($now) . ')';
        $wpdb->query($sql);
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'rx item fixture row must persist');
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
        self::assertNotSame($baseline, $tpl, 'G7: template_include must intercept with plugin-owned template');
        self::assertFileExists($tpl);
        ob_start();
        include $tpl;
        return (string) ob_get_clean();
    }
}
