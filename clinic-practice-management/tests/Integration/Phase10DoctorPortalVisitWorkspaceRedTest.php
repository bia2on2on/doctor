<?php
/**
 * Phase 10 Doctor Portal Visit Workspace — BLOCKER FIX.
 *
 * Slice: Doctor Portal opens the selected current Visit using existing visit_id;
 * display bounded safe patient/Visit header; create/read notes using existing
 * backend contract (doctor_private, patient_visible); list current-Visit notes
 * in the Doctor Portal; Doctor Portal clinical access is portal-scoped:
 * raw visit_id is selector only (authenticated doctor identity + trusted Clinic
 * + trusted operational Location + own-doctor/Visit relationship).
 *
 * Blocker evidence (independent review):
 * - shared E7/E8 only enforced Clinic-scoped authorization; did NOT enforce
 *   doctor ownership nor visit.location_id matching trusted operational Location;
 * - G2.B incorrectly expected 200 for another same-Clinic doctor's Visit via
 *   the portal boundary;
 * - G2.D incorrectly expected 200 for another Location's Visit via the portal.
 *
 * Contract for DOCTOR PORTAL Visit Workspace only:
 *   authenticated WP user
 *   + server-derived clinician identity
 *   + active trusted Clinic
 *   + trusted operational Location (0=>fail closed, 1=>auto, N>1=>explicit required,
 *     foreign/inactive/unassigned=>fail closed)
 *   + Visit owned by that clinician and at that Location
 *   = authorized.
 * Raw visit_id/location_id remain selectors only; cross-doctor and foreign-
 * Location selectors fail non-enumerating (404 CLINIC_NOT_FOUND). Shared/admin/
 * staff E7/E8 behavior stays clinic-scoped only and is preserved here as
 * explicit regression proof.
 *
 * Live context:
 * - Latest migration: 2026_09_20_0022 (no 0023)
 * - Backend: E7 record, E8 notes, VisitMachine, ClinicalService
 * - Doctor Portal: shell + context/clinics/locations REST + workspace UI
 *   (queue row -> GET /doctor/portal/visits/{id}/record, POST .../notes)
 * - Portal workspace routes reuse E7/E8 after the portal guard; shared
 *   E7/E8 contract is untouched.
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

final class Phase10DoctorPortalVisitWorkspaceRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';
    private const PORTAL_RECORD = 'clinic/v1/doctor/portal/visits/%d/record';
    private const PORTAL_NOTES  = 'clinic/v1/doctor/portal/visits/%d/notes';
    private const SHARED_RECORD = 'clinic/v1/visits/%d/record';
    private const SHARED_NOTES  = 'clinic/v1/visits/%d/notes';
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

    // ============ Group 1 — PORTAL ENTRY ============

    public function testGroup1_PortalEntrySelectOpenOwnVisit(): void
    {
        $fx = $this->makePortalStage('g1');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g1_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        // Portal boundary: own doctor + trusted Clinic + trusted Location + own Visit => success.
        $rPortal = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visit), [], $headers);
        self::assertSame(200, $rPortal->get_status(),
            'G1: Doctor can access own visit via Doctor Portal workspace, got ' . $rPortal->get_status() . '/' . $this->errCode($rPortal));

        $payload = $this->payload($rPortal);
        self::assertArrayHasKey('visit', $payload, 'G1: Portal record payload contains visit data');
        self::assertSame($visit, (int) $payload['visit']['id'], 'G1: Correct visit returned via portal');
    }

    // ============ Group 2 — PORTAL AUTHORITY / ISOLATION ============

    public function testGroup2_PortalAuthorityIsolation(): void
    {
        $fx = $this->makePortalStage('g2');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        // Own visit
        $patientOwn = $this->insertPatient($fx['clinic'], 'g2_own');
        $visitOwn = $this->insertVisit($patientOwn, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        // Another doctor in same clinic
        $doctorB = $this->makeUser('g2_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G2 B', $fx['clinic'], 1, $doctorB);
        cpms_test_seed_membership($doctorB, $fx['clinic'], 'cpms_doctor');

        $patientOther = $this->insertPatient($fx['clinic'], 'g2_other');
        $visitOther = $this->insertVisit($patientOther, $clinicianB, $fx['clinic'], $fx['location'], 'in_consultation');

        // Foreign clinic
        $org = $this->insertOrg('G2 Foreign Org');
        $clinicForeign = $this->insertClinicInOrg('G2 Foreign Clinic', $org, self::TZ_TEHRAN);
        $locForeign = $this->insertLocation($clinicForeign, 'G2 Foreign Loc', self::TZ_TEHRAN, 1);

        $clinicianForeign = $this->insertClinician('Dr G2 Foreign', $clinicForeign, 1, $fx['doctor']);
        $patientForeign = $this->insertPatient($clinicForeign, 'g2_foreign');
        $visitForeign = $this->insertVisit($patientForeign, $clinicianForeign, $clinicForeign, $locForeign, 'in_consultation');

        // Other Location in same Clinic
        $locOther = $this->insertLocation($fx['clinic'], 'G2 Other Loc', self::TZ_TEHRAN, 0);
        $patientOtherLoc = $this->insertPatient($fx['clinic'], 'g2_otherloc');
        $visitOtherLoc = $this->insertVisit($patientOtherLoc, $fx['clinician'], $fx['clinic'], $locOther, 'in_consultation');

        // A. Own doctor + own clinic + own location + own visit => portal SUCCESS
        wp_set_current_user($fx['doctor']);
        $rOwn = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitOwn), [], $headers);
        self::assertSame(200, $rOwn->get_status(), 'G2.A: Portal own visit succeeds');

        // B. Same Clinic but another doctor => Doctor Portal record read non-enumerating denial (404).
        // The portal boundary must NOT return 200 for another doctor's Visit.
        wp_set_current_user($fx['doctor']);
        $rCrossDoctorPortal = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitOther), [], $headers);
        self::assertSame(404, $rCrossDoctorPortal->get_status(), 'G2.B-portal: Portal cross-doctor visit denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rCrossDoctorPortal), 'G2.B-portal: non-enumerating 404');

        // Preserve explicit regression: shared E7 remains clinic-scoped (200) and is NOT tightened globally.
        // This proves we did not make shared/admin/staff globally doctor-owned.
        wp_set_current_user($doctorB);
        $rCrossDoctorShared = $this->dispatch('GET', '/' . sprintf(self::SHARED_RECORD, $visitOwn), [], $headers);
        self::assertSame(200, $rCrossDoctorShared->get_status(), 'G2.B-shared: Shared E7 remains clinic-scoped (200) for same-clinic doctor');

        // C. Foreign clinic access => DENIED (non-enumerating 404) via both portal and shared.
        wp_set_current_user($fx['doctor']);
        $rForeignPortal = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitForeign), [], $headers);
        self::assertSame(404, $rForeignPortal->get_status(), 'G2.C-portal: Portal foreign clinic denied 404');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rForeignPortal), 'G2.C-portal: non-enumerating 404');
        $rForeignShared = $this->dispatch('GET', '/' . sprintf(self::SHARED_RECORD, $visitForeign), [], $headers);
        self::assertSame(404, $rForeignShared->get_status(), 'G2.C-shared: Shared foreign clinic denied 404');

        // D. Same Clinic but another Location => Portal non-enumerating denial (404).
        // Shared E7 has no operational-Location boundary and stays 200 — do NOT tighten globally.
        wp_set_current_user($fx['doctor']);
        $rCrossLocPortal = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitOtherLoc), [], $headers);
        self::assertSame(404, $rCrossLocPortal->get_status(), 'G2.D-portal: Portal cross-Location visit denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rCrossLocPortal), 'G2.D-portal: non-enumerating 404');

        $rCrossLocShared = $this->dispatch('GET', '/' . sprintf(self::SHARED_RECORD, $visitOtherLoc), [], $headers);
        self::assertSame(200, $rCrossLocShared->get_status(), 'G2.D-shared: Shared E7 is not globally Location-strict (200)');

        // E. Prove stricter behavior is Doctor-Portal-specific and does not regress shared/admin staff
        $secretary = $this->makeUser('g2_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], 'cpms_secretary');

        wp_set_current_user($secretary);
        $rSecretaryShared = $this->dispatch('GET', '/' . sprintf(self::SHARED_RECORD, $visitOwn), [], $headers);
        self::assertSame(403, $rSecretaryShared->get_status(), 'G2.E-shared: Shared E7 denial for secretaries preserved (403)');

        // Portal workspace for secretary must also be denied (doctor role required at permission check).
        $rSecretaryPortal = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitOwn), [], $headers);
        self::assertSame(403, $rSecretaryPortal->get_status(), 'G2.E-portal: Portal workspace denies secretary (403)');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rSecretaryPortal), 'G2.E-portal: portal secretary denial is permission boundary');
    }

    // ============ Group 2b — PORTAL NOTE CREATE AUTHORITY ============

    public function testGroup2b_PortalNoteCreateAuthority(): void
    {
        $fx = $this->makePortalStage('g2b');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        $patientOwn = $this->insertPatient($fx['clinic'], 'g2b_own');
        $visitOwn = $this->insertVisit($patientOwn, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        $doctorB = $this->makeUser('g2b_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G2b B', $fx['clinic'], 1, $doctorB);
        cpms_test_seed_membership($doctorB, $fx['clinic'], 'cpms_doctor');
        $patientOther = $this->insertPatient($fx['clinic'], 'g2b_other');
        $visitOther = $this->insertVisit($patientOther, $clinicianB, $fx['clinic'], $fx['location'], 'in_consultation');

        $locOther = $this->insertLocation($fx['clinic'], 'G2b Other Loc', self::TZ_TEHRAN, 0);
        $patientOtherLoc = $this->insertPatient($fx['clinic'], 'g2b_otherloc');
        $visitOtherLoc = $this->insertVisit($patientOtherLoc, $fx['clinician'], $fx['clinic'], $locOther, 'in_consultation');

        $noteBody = [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'Portal note create test',
        ];

        // D. Same B/C denial applies to Doctor Portal note create.

        // Cross-doctor note create via portal => 404 non-enumerating
        wp_set_current_user($fx['doctor']);
        $rCrossDoctorNotePortal = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visitOther), $noteBody, $headers);
        self::assertSame(404, $rCrossDoctorNotePortal->get_status(), 'G2b.D-portal: Portal note create cross-doctor denied 404');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rCrossDoctorNotePortal), 'G2b.D-portal: non-enumerating 404 for note create');

        // Cross-Location note create via portal => 404
        $rCrossLocNotePortal = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visitOtherLoc), $noteBody, $headers);
        self::assertSame(404, $rCrossLocNotePortal->get_status(), 'G2b.D-portal: Portal note create cross-Location denied 404');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rCrossLocNotePortal), 'G2b.D-portal: non-enumerating 404 for note create');

        // Own note create via portal must succeed (prove portal note creation works when authorized)
        $rOwnNotePortal = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visitOwn), $noteBody, $headers);
        self::assertSame(200, $rOwnNotePortal->get_status(), 'G2b.D-portal: Portal own note create succeeds');

        // Shared note create remains clinic-scoped (200) for same-clinic doctor on own visit — not globally tightened.
        // This uses the shared route with the same headers but the actor is the owning doctorB on his own visit.
        wp_set_current_user($doctorB);
        // doctorB's own visit is visitOther — give him headers for same clinic/location (he is member)
        $rSharedOwnNote = $this->dispatch('POST', '/' . sprintf(self::SHARED_NOTES, $visitOther), $noteBody, $headers);
        self::assertSame(200, $rSharedOwnNote->get_status(), 'G2b.D-shared: Shared E8 remains clinic-scoped for owning doctor');
    }

    // ============ Group 3 — SAFE HEADER ============

    public function testGroup3_SafeHeader(): void
    {
        $fx = $this->makePortalStage('g3');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g3_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        // Portal header is bounded safe patient/Visit header from already-authorized medical-view.
        $rRecord = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visit), [], $headers);
        self::assertSame(200, $rRecord->get_status(), 'G3: Portal record accessible');

        $payload = $this->payload($rRecord);
        self::assertArrayHasKey('patient', $payload, 'G3: Patient data present');
        self::assertArrayHasKey('visit', $payload, 'G3: Visit data present');

        $patientData = $payload['patient'];
        self::assertArrayHasKey('id', $patientData, 'G3: Patient ID present');
        self::assertArrayHasKey('full_name', $patientData, 'G3: Established medical view provides patient name');

        $visitData = $payload['visit'];
        self::assertArrayHasKey('id', $visitData, 'G3: Visit ID present');
        self::assertArrayHasKey('status', $visitData, 'G3: Visit status present');

        $sensitiveFields = ['mobile', 'national_id', 'address', 'emergency_contact', 'emergency_phone'];
        foreach ($sensitiveFields as $field) {
            self::assertArrayNotHasKey($field, $patientData,
                'G3: Sensitive field \"' . $field . '\" must not be exposed in portal workspace header');
        }
    }

    // ============ Group 4 — PRIVATE NOTE ============

    public function testGroup4_PrivateNote(): void
    {
        $fx = $this->makePortalStage('g4');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g4_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        // Portal doctor can create doctor_private note via portal boundary
        $noteBody = [
            'category' => 'clinical_note',
            'visibility' => 'doctor_private',
            'content_text' => 'Private clinical observation - not for patient',
        ];

        $rCreate = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visit), $noteBody, $headers);
        self::assertSame(200, $rCreate->get_status(), 'G4: Doctor can create private note via portal');
        $notePayload = $this->payload($rCreate);
        self::assertArrayHasKey('id', $notePayload, 'G4: Note ID returned');
        $noteId = (int) $notePayload['id'];

        // Portal doctor can read the private note via portal record
        $rRecord = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visit), [], $headers);
        self::assertSame(200, $rRecord->get_status(), 'G4: Portal record accessible');
        $recordPayload = $this->payload($rRecord);
        self::assertArrayHasKey('notes', $recordPayload, 'G4: Notes present in portal record');
        $notes = $recordPayload['notes'];
        $foundPrivate = false;
        foreach ($notes as $note) {
            if ((int) $note['id'] === $noteId) {
                $foundPrivate = true;
                self::assertSame('doctor_private', $note['visibility'], 'G4: Note is doctor_private');
                break;
            }
        }
        self::assertTrue($foundPrivate, 'G4: Private note found in doctor portal record');

        // Secretary denial preserved
        $secretary = $this->makeUser('g4_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], 'cpms_secretary');

        wp_set_current_user($secretary);
        $rSecretaryPortal = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visit), [], $headers);
        self::assertSame(403, $rSecretaryPortal->get_status(), 'G4: Secretary cannot access portal clinical record');
        $rSecretaryShared = $this->dispatch('GET', '/' . sprintf(self::SHARED_RECORD, $visit), [], $headers);
        self::assertSame(403, $rSecretaryShared->get_status(), 'G4: Secretary cannot access shared clinical record (regression)');
    }

    // ============ Group 5 — PATIENT-VISIBLE NOTE ============

    public function testGroup5_PatientVisibleNote(): void
    {
        $fx = $this->makePortalStage('g5');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g5_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        $noteBody = [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'Patient can see this note',
        ];

        $rCreate = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visit), $noteBody, $headers);
        self::assertSame(200, $rCreate->get_status(), 'G5: Doctor can create patient-visible note via portal');
        $notePayload = $this->payload($rCreate);
        $noteId = (int) $notePayload['id'];
        self::assertSame('patient_visible', $notePayload['visibility'], 'G5: Note is patient_visible');

        $privateBody = [
            'category' => 'clinical_note',
            'visibility' => 'doctor_private',
            'content_text' => 'Private note - should not leak to patient',
        ];

        $rPrivate = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visit), $privateBody, $headers);
        self::assertSame(200, $rPrivate->get_status(), 'G5: Private note created via portal');
        $privateNoteId = (int) $this->payload($rPrivate)['id'];

        // Patient Visit Detail still filters to patient_visible only (existing contract)
        $patientUser = $this->makeUser('g5_patient_user', RolesAndCapabilities::ROLE_PATIENT);
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
        $rPatientDetail = $this->dispatch('GET', '/' . self::REST_NS . '/visits/' . $visit);
        self::assertSame(200, $rPatientDetail->get_status(), 'G5: Established patient Visit Detail accessible to linked patient');
        $patientPayload = $this->payload($rPatientDetail);
        self::assertCount(1, $patientPayload['notes'], 'G5: Patient sees only one visible note');
        self::assertSame($noteId, (int) $patientPayload['notes'][0]['id']);
        self::assertSame('patient_visible', $patientPayload['notes'][0]['visibility']);
        self::assertNotSame($privateNoteId, (int) $patientPayload['notes'][0]['id'], 'G5: Private note not exposed to patient');
    }

    // ============ Group 6 — PORTAL UI WIRING CONTRACT ============

    public function testGroup6_PortalUiWiringContract(): void
    {
        $fx = $this->makePortalStage('g6');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g6_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        $invalidVisibilities = ['admin_only', 'staff_visible', 'public', ''];

        foreach ($invalidVisibilities as $invalidVis) {
            $noteBody = [
                'category' => 'clinical_note',
                'visibility' => $invalidVis,
                'content_text' => 'Test note',
            ];

            $rInvalid = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visit), $noteBody, $headers);
            self::assertSame(422, $rInvalid->get_status(),
                'G6: Portal invalid visibility \"' . $invalidVis . '\" rejected');
            self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rInvalid),
                'G6: Validation failure for invalid visibility');
        }

        $validVisibilities = ['doctor_private', 'patient_visible'];

        foreach ($validVisibilities as $validVis) {
            $noteBody = [
                'category' => 'clinical_note',
                'visibility' => $validVis,
                'content_text' => 'Test note with ' . $validVis,
            ];

            $rValid = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visit), $noteBody, $headers);
            self::assertSame(200, $rValid->get_status(),
                'G6: Portal valid visibility \"' . $validVis . '\" accepted');
        }

        $rNoNonce = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visit), [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'Test',
        ], $headers, false);

        self::assertNotSame(201, $rNoNonce->get_status(), 'G6: Portal request without nonce rejected');
        self::assertContains($rNoNonce->get_status(), [401, 403], 'G6: Portal nonce rejection is 401/403');

        $rMissingCategory = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visit), [
            'visibility' => 'patient_visible',
            'content_text' => 'Test',
        ], $headers);

        self::assertContains($rMissingCategory->get_status(), [400, 422], 'G6: Portal missing category rejected');

        $rMissingContent = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visit), [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
        ], $headers);

        self::assertContains($rMissingContent->get_status(), [400, 422], 'G6: Portal missing content rejected');
    }

    // ============ Group 7 — LOCATION POLICY (E + F) ============

    public function testGroup7_PortalLocationPolicy(): void
    {
        // E. >1 eligible Location requires explicit trusted Location before Visit access.
        $fxMulti = $this->makePortalStage('g7m');
        $loc2 = $this->insertLocation($fxMulti['clinic'], 'G7 Second Loc', self::TZ_TEHRAN, 0);
        // Now eligible = 2 (both active, scope_mode clinic). Portal without explicit Location must fail.
        $patientMulti = $this->insertPatient($fxMulti['clinic'], 'g7m_patient');
        $visitMulti = $this->insertVisit($patientMulti, $fxMulti['clinician'], $fxMulti['clinic'], $fxMulti['location'], 'in_consultation');

        wp_set_current_user($fxMulti['doctor']);
        // No Location header => portal must require explicit Location (400 with field=location_id)
        $headersNoLoc = $this->scopeHeaders($fxMulti['clinic'], null);
        $rNoLoc = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitMulti), [], $headersNoLoc);
        self::assertSame(400, $rNoLoc->get_status(), 'G7.E: Portal N>1 without explicit Location requires 400');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($rNoLoc), 'G7.E: Location required code');
        $dataNoLoc = $rNoLoc->get_data();
        // Envelope: error data is inside 'data' or directly? Inspect via errData helper
        // We check that the response carries field=location_id reason=location_required and does NOT leak eligible IDs.
        $rawNoLoc = $this->rawErrorData($rNoLoc);
        self::assertSame('location_id', $rawNoLoc['field'] ?? null, 'G7.E: field=location_id');
        self::assertSame('location_required', $rawNoLoc['reason'] ?? null, 'G7.E: reason=location_required');
        self::assertArrayNotHasKey('eligible_location_ids', $rawNoLoc, 'G7.E: must not leak eligible IDs');
        self::assertArrayNotHasKey('eligible', $rawNoLoc, 'G7.E: must not leak eligible IDs');

        // With explicit trusted Location, portal succeeds (and auto-resolution with 1 location also succeeds).
        $headersWithLoc = $this->scopeHeaders($fxMulti['clinic'], $fxMulti['location']);
        $rWithLoc = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitMulti), [], $headersWithLoc);
        self::assertSame(200, $rWithLoc->get_status(), 'G7.E: Portal with explicit trusted Location succeeds');

        // Note create also requires explicit Location when N>1
        $noteBody = ['category' => 'clinical_note', 'visibility' => 'patient_visible', 'content_text' => 'loc test'];
        $rNoteNoLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visitMulti), $noteBody, $headersNoLoc);
        self::assertSame(400, $rNoteNoLoc->get_status(), 'G7.E: Portal note create N>1 without Location requires 400');

        // Single-location clinic auto-resolution must still succeed without explicit header (prove 1=>auto).
        $fxSingle = $this->makePortalStage('g7s');
        $patientSingle = $this->insertPatient($fxSingle['clinic'], 'g7s_patient');
        $visitSingle = $this->insertVisit($patientSingle, $fxSingle['clinician'], $fxSingle['clinic'], $fxSingle['location'], 'in_consultation');
        wp_set_current_user($fxSingle['doctor']);
        $rSingleNoHeader = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitSingle), [], $this->scopeHeaders($fxSingle['clinic'], null));
        self::assertSame(200, $rSingleNoHeader->get_status(), 'G7.E: Single location auto-resolution succeeds without explicit header');

        // F. forged/foreign/inactive/unassigned Location cannot create authority.

        // Foreign Location (belongs to another clinic)
        $orgF = $this->insertOrg('G7F Org');
        $clinicF = $this->insertClinicInOrg('G7F Clinic', $orgF, self::TZ_TEHRAN);
        $locF = $this->insertLocation($clinicF, 'G7F Foreign', self::TZ_TEHRAN, 1);
        // Try to use foreign location header while clinic is fxSingle's clinic => must fail closed (403) and not create authority.
        wp_set_current_user($fxSingle['doctor']);
        $headersForeign = $this->scopeHeaders($fxSingle['clinic'], $locF);
        $rForeignLoc = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitSingle), [], $headersForeign);
        // RestClinicContext will fail the foreign location binding before our guard: 403 UNAVAILABLE reason location or 422.
        self::assertContains($rForeignLoc->get_status(), [403, 422], 'G7.F: Foreign Location header fails closed');
        if (403 === $rForeignLoc->get_status()) {
            self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rForeignLoc), 'G7.F: Foreign location denial is UNAVAILABLE');
        }

        // Inactive Location
        $locInactive = $this->insertLocation($fxSingle['clinic'], 'G7 Inactive', self::TZ_TEHRAN, 0);
        // Deactivate it
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE id = %d', $locInactive));
        $headersInactive = $this->scopeHeaders($fxSingle['clinic'], $locInactive);
        $rInactive = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitSingle), [], $headersInactive);
        self::assertContains($rInactive->get_status(), [403, 422], 'G7.F: Inactive Location fails closed');

        // Unassigned Location when scope_mode = location
        $fxLocScoped = $this->makePortalStage('g7u');
        $locA = $fxLocScoped['location'];
        $locB = $this->insertLocation($fxLocScoped['clinic'], 'G7 Unassigned B', self::TZ_TEHRAN, 0);
        $memRow = App::membership_service()->membership_for($fxLocScoped['clinic'], $fxLocScoped['doctor']);
        self::assertIsArray($memRow, 'membership must exist for location-scoped test');
        $memId = (int) ($memRow['id'] ?? 0);
        self::assertGreaterThan(0, $memId, 'membership id must be positive');
        App::membership_service()->set_scope_mode($memId, 'location', [$locA]);
        // Now eligible = [locA] only; locB is active but unassigned => using it must fail closed.
        $patientU = $this->insertPatient($fxLocScoped['clinic'], 'g7u_patient');
        $visitU = $this->insertVisit($patientU, $fxLocScoped['clinician'], $fxLocScoped['clinic'], $locA, 'in_consultation');
        wp_set_current_user($fxLocScoped['doctor']);
        $headersUnassigned = $this->scopeHeaders($fxLocScoped['clinic'], $locB);
        $rUnassigned = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitU), [], $headersUnassigned);
        self::assertContains($rUnassigned->get_status(), [403, 422], 'G7.F: Unassigned Location fails closed');
        // But using the assigned Location succeeds
        $headersAssigned = $this->scopeHeaders($fxLocScoped['clinic'], $locA);
        $rAssigned = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitU), [], $headersAssigned);
        self::assertSame(200, $rAssigned->get_status(), 'G7.F: Assigned Location succeeds');

        // Forged Location (non-existent ID)
        $headersForged = $this->scopeHeaders($fxSingle['clinic'], 999999);
        $rForged = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitSingle), [], $headersForged);
        self::assertContains($rForged->get_status(), [403, 422], 'G7.F: Forged Location fails closed');

        // 0 eligible => fail closed
        // Deactivate all locations for a clinic so eligible = []
        $fxZero = $this->makePortalStage('g7z');
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE clinic_id = %d', $fxZero['clinic']));
        $patientZ = $this->insertPatient($fxZero['clinic'], 'g7z_patient');
        $visitZ = $this->insertVisit($patientZ, $fxZero['clinician'], $fxZero['clinic'], $fxZero['location'], 'in_consultation');
        wp_set_current_user($fxZero['doctor']);
        $rZero = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitZ), [], $this->scopeHeaders($fxZero['clinic'], $fxZero['location']));
        // With 0 eligible, even explicit trusted Location must fail closed (no authority to create scope)
        self::assertContains($rZero->get_status(), [403, 404], 'G7.F: 0 eligible fails closed');
    }

    // ================= helpers (proven Slice 1/2 patterns) =================

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
     * Extract raw error data payload (status/details) regardless of WP_Error vs array envelope.
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
    private function findVisit(int $visitId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitId]);
        self::assertIsArray($row, 'visit row must exist');
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

}
