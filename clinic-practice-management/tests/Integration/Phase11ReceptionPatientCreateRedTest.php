<?php
/**
 * Phase 11 Slice 3 (bounded) — Staff Portal Reception: Create Clinic Patient,
 * Then Read-Only Select — TEST-ONLY RED.
 *
 * Slice: an authorized secretary working in the EXISTING Staff Portal
 * Reception module searches the trusted Clinic; if no correct patient exists,
 * they open a SMALL create-patient form, create via the EXISTING patient-create
 * contract, and the new patient appears as the SAME read-only selected-patient
 * presentation used by Slice 2. Nothing else happens automatically.
 *
 * Live reconstruction (verified, not assumed — 2026-09-27):
 * - authoritative main = origin/main = 2c9055847d7b6120c2c52e07cd16a6c3aadb4948;
 *   open PRs = 0; latest migration = 2026_09_26_0023_handwriting_prescription_paper.php
 *   (this RED adds/reserves no migration).
 * - Phase 11 = STARTED / IN PROGRESS — NOT CLOSED; Slice 1 Arrival Board merged
 *   via PR #133; Slice 2 read-only Clinic patient search merged via PR #135.
 * - established create contract (reused, never duplicated):
 *   POST /clinic/v1/patients → PatientController::create → PatientService::create
 *   (license, first/last required, MobileValidator::normalize, same-Clinic mobile
 *   duplicate ⇒ 400 CLINIC_VALIDATION_FAILED, MRN MR-YYMMDD-XXXXX, clinic_id from
 *   App::scope() not from the body) → PatientRepository::create. The shared route
 *   gates on cpms_patient_create only — it does NOT carry the Reception
 *   secretary-role boundary. create() returns staffView (clinical fields) which
 *   must NOT be the Reception UI payload.
 *
 * The RED fails ONLY because the Reception patient-create boundary/UI is
 * missing: bootstrap, migrations and fixtures succeed; the trusted-Clinic
 * secretary path is reached (the shared established create answers 200 for
 * the same secretary in the same fixture); the intended Reception create path
 * answers with the canonical missing-route fingerprint and the rendered
 * Reception module carries no create form.
 *
 * TEST GROUP MAP:
 *  S1  Secretary creates inside Reception (UI surface + REST) ..... INTENDED RED
 *  S2  Insufficient role / capability / missing nonce denied ...... INTENDED RED
 *  S3  Suspended / missing membership denied ...................... INTENDED RED
 *  S4  Foreign/raw Clinic selector cannot create elsewhere ........ INTENDED RED
 *  S5  Selected Location is NOT patient ownership ................. INTENDED RED
 *  S6  Required first/last/mobile + mobile normalization .......... INTENDED RED
 *  S7  National-ID validation preserved ........................... INTENDED RED
 *  S8  Same-Clinic mobile duplicate (established behavior) ........ INTENDED RED
 *  S9  Same-Clinic national-ID uniqueness = bounded product error . INTENDED RED
 *  S10 Similar name alone does NOT block .......................... INTENDED RED
 *  S11 Privacy / dormant Org identity / no auto workflow .......... INTENDED RED
 *  S12 Slice 1 Arrival Board + Slice 2 search remain intact ....... CONTROL (pass)
 *
 * Excluded by design: walk-in, appointment, check-in, queue, Visit, file,
 * prescription, finance, custom MRN, address/emergency/clinical form fields,
 * new role/capability, migrations, second patient service/repository,
 * Organization identity activation, patient merge.
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

final class Phase11ReceptionPatientCreateRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';
    private const CREATE = '/clinic/v1/staff/portal/reception/patients';
    private const SHARED_CREATE = '/clinic/v1/patients';
    private const SEARCH = '/clinic/v1/staff/portal/reception/patients/search';

    /** Established searchView presentation — the ONLY keys a Reception create result may carry. */
    private const SEARCH_KEYS = ['birth_date', 'first_name', 'gender', 'id', 'last_name', 'mobile', 'mrn', 'national_id', 'status'];

    /** Checksum-valid national IDs (not sequential fakes). */
    private const NID_A = '0499370899';
    private const NID_B = '0123456789';
    private const NID_C = '0013542419';
    private const NID_INVALID = '1234567890';

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
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        parent::tearDown();
    }

    // ============ S1 — Secretary creates inside Reception ============

    public function testS1_SecretaryCanCreateInsideReception(): void
    {
        $fx = $this->makeStage('s1');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        // Control: the trusted-Clinic secretary path is reached — the SHARED
        // established create answers for this very secretary and fixture.
        $shared = $this->dispatch('POST', self::SHARED_CREATE, [
            'first_name' => 'Control',
            'last_name'  => 'Shared',
            'mobile'     => '09121110001',
        ], $this->scopeHeaders($fx['clinic']));
        self::assertSame(200, $shared->get_status(), 'S1 control: shared established create reachable for the secretary');
        $sharedRow = $this->createPayload($shared);
        self::assertGreaterThan(0, (int) ($sharedRow['id'] ?? 0), 'S1 control: shared create persisted a patient');
        self::assertMatchesRegularExpression('/^MR-\d{6}-[A-Z0-9]{5}$/', (string) ($sharedRow['mrn'] ?? ''), 'S1 control: established MRN pattern');
        self::assertSame($fx['clinic'], $this->patientClinic((int) $sharedRow['id']), 'S1 control: shared create uses trusted Clinic');

        // UI: the Reception module carries the small create form.
        $html = $this->renderReception($fx['secretary']);
        self::assertStringContainsString('data-role="reception-app"', $html, 'S1: reception module renders');
        self::assertStringContainsString('data-role="sr-search"', $html, 'S1: search panel remains');
        self::assertStringContainsString('data-role="sr-create-open"', $html, 'S1: reception exposes the create action');
        self::assertStringContainsString('data-role="sr-create"', $html, 'S1: reception exposes the create form');
        self::assertStringContainsString('data-role="sr-create-first-name"', $html, 'S1: first name field');
        self::assertStringContainsString('data-role="sr-create-last-name"', $html, 'S1: last name field');
        self::assertStringContainsString('data-role="sr-create-mobile"', $html, 'S1: mobile field');
        self::assertStringContainsString('data-role="sr-search-selected"', $html, 'S1: existing read-only selected surface remains');

        // REST: the Reception boundary creates via the established contract.
        $res = $this->dispatch('POST', self::CREATE, [
            'first_name' => 'Shirin',
            'last_name'  => 'Kavian',
            'mobile'     => '+989121110002',
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_a']));
        self::assertSame(200, $res->get_status(), 'S1: reception create answers — got ' . $res->get_status() . '/' . $this->errCode($res));
        $row = $this->createPayload($res);
        self::assertGreaterThan(0, (int) ($row['id'] ?? 0), 'S1: reception create returns a patient id');
        self::assertSame('Shirin', (string) $row['first_name']);
        self::assertSame('Kavian', (string) $row['last_name']);
        self::assertSame('09121110002', (string) $row['mobile'], 'S1: mobile normalized by the established contract');
        self::assertMatchesRegularExpression('/^MR-\d{6}-[A-Z0-9]{5}$/', (string) ($row['mrn'] ?? ''), 'S1: MRN generated by the established contract');
        self::assertSame($fx['clinic'], $this->patientClinic((int) $row['id']), 'S1: patient belongs to the trusted Clinic');
        self::assertNotSame((int) $sharedRow['id'], (int) $row['id'], 'S1: reception create is a distinct row from the shared-create control');
    }

    // ============ S2 — Insufficient role / capability / nonce ============

    public function testS2_InsufficientRoleOrCapabilityDenied(): void
    {
        $fx = $this->makeStage('s2');
        $body = ['first_name' => 'Arman', 'last_name' => 'Deny', 'mobile' => '09121110011'];

        // A. Doctor is NOT the Reception role (and has no patient-create cap).
        $this->seedMembership($fx['doctor'], $fx['clinic'], 'cpms_doctor');
        wp_set_current_user($fx['doctor']);
        $doc = $this->dispatch('POST', self::CREATE, $body, $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $doc->get_status(), 'S2: doctor denied on the reception create boundary');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($doc), 'S2: doctor denial envelope');
        self::assertSame(0, $this->countPatients($fx['clinic']), 'S2: doctor creates no row');

        // B. Accountant with ACTIVE membership — membership alone is not permission.
        $acct = $this->makeUser('qa_s2_acct', RolesAndCapabilities::ROLE_ACCOUNTANT);
        $this->seedMembership($acct, $fx['clinic'], 'cpms_accountant');
        wp_set_current_user($acct);
        $acc = $this->dispatch('POST', self::CREATE, $body, $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $acc->get_status(), 'S2: accountant denied');
        self::assertSame(0, $this->countPatients($fx['clinic']), 'S2: accountant creates no row');

        // C. Secretary + ACTIVE membership but clinic-scoped patient-create DENY.
        $mid = $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        App::membership_service()->set_capability($mid, RolesAndCapabilities::PATIENT_CREATE, 'deny');
        wp_set_current_user($fx['secretary']);
        $deny = $this->dispatch('POST', self::CREATE, $body, $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $deny->get_status(), 'S2: scoped cpms_patient_create deny blocks reception create');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($deny), 'S2: capability denial envelope');
        self::assertSame(0, $this->countPatients($fx['clinic']), 'S2: capability deny creates no row');

        // D. Missing nonce — CSRF layer preserved.
        App::membership_service()->remove_capability($mid, RolesAndCapabilities::PATIENT_CREATE);
        $noNonce = $this->dispatch('POST', self::CREATE, $body, $this->scopeHeaders($fx['clinic']), false);
        self::assertSame(403, $noNonce->get_status(), 'S2: missing nonce rejected');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($noNonce), 'S2: nonce denial code');
        self::assertSame(0, $this->countPatients($fx['clinic']), 'S2: missing nonce creates no row');

        // E. Anonymous.
        wp_set_current_user(0);
        $anon = $this->dispatch('POST', self::CREATE, $body, $this->scopeHeaders($fx['clinic']));
        self::assertContains($anon->get_status(), [401, 403], 'S2: anonymous denied');
        self::assertSame(0, $this->countPatients($fx['clinic']), 'S2: anonymous creates no row');
    }

    // ============ S3 — Suspended / missing membership ============

    public function testS3_SuspendedOrMissingMembershipDenied(): void
    {
        $fx = $this->makeStage('s3');
        $body = ['first_name' => 'Nima', 'last_name' => 'Member', 'mobile' => '09121110021'];

        $mid = $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        App::membership_service()->suspend_membership($mid);
        wp_set_current_user($fx['secretary']);
        $susp = $this->dispatch('POST', self::CREATE, $body, $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $susp->get_status(), 'S3: suspended membership denied');
        self::assertSame(0, $this->countPatients($fx['clinic']), 'S3: suspended membership creates no row');

        $loner = $this->makeUser('qa_s3_loner', RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($loner);
        $none = $this->dispatch('POST', self::CREATE, $body, $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $none->get_status(), 'S3: secretary without membership denied');
        self::assertSame(0, $this->countPatients($fx['clinic']), 'S3: no membership creates no row');
    }

    // ============ S4 — Clinic isolation ============

    public function testS4_ForeignOrRawClinicSelectorCannotCreateElsewhere(): void
    {
        $a = $this->makeStage('s4a');
        $b = $this->makeStage('s4b');
        $this->seedMembership($a['secretary'], $a['clinic'], 'cpms_secretary');
        wp_set_current_user($a['secretary']);

        $beforeA = $this->countPatients($a['clinic']);
        $beforeB = $this->countPatients($b['clinic']);

        // Raw Clinic B selector from a Clinic A secretary fails closed.
        $cross = $this->dispatch('POST', self::CREATE, [
            'first_name' => 'Twin',
            'last_name'  => 'Foreign',
            'mobile'     => '09121110031',
        ], $this->scopeHeaders($b['clinic']));
        self::assertNotSame(200, $cross->get_status(), 'S4: foreign Clinic selector fails closed');
        self::assertSame($beforeA, $this->countPatients($a['clinic']), 'S4: foreign selector creates no Clinic A row');
        self::assertSame($beforeB, $this->countPatients($b['clinic']), 'S4: foreign selector creates no Clinic B row');

        // Body clinic_id of B is never authority — create lands in trusted A.
        $res = $this->dispatch('POST', self::CREATE, [
            'first_name' => 'Trusted',
            'last_name'  => 'Bodyid',
            'mobile'     => '09121110032',
            'clinic_id'  => $b['clinic'],
        ], $this->scopeHeaders($a['clinic'], $a['loc_a']));
        self::assertSame(200, $res->get_status(), 'S4: trusted-Clinic create answers — ' . $this->errCode($res));
        $row = $this->createPayload($res);
        $pid = (int) ($row['id'] ?? 0);
        self::assertGreaterThan(0, $pid, 'S4: patient id returned');
        self::assertSame($a['clinic'], $this->patientClinic($pid), 'S4: patient is created in the trusted Clinic, not the body clinic_id');
        self::assertSame($beforeB, $this->countPatients($b['clinic']), 'S4: Clinic B row count unchanged');
    }

    // ============ S5 — Location is not patient ownership ============

    public function testS5_SelectedLocationIsNotPatientOwnership(): void
    {
        $fx = $this->makeStage('s5');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        $res = $this->dispatch('POST', self::CREATE, [
            'first_name'  => 'Loc',
            'last_name'   => 'Neutral',
            'mobile'      => '09121110041',
            'location_id' => $fx['loc_b'],
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_a']));
        self::assertSame(200, $res->get_status(), 'S5: create with a selected Location answers — ' . $this->errCode($res));
        $pid = (int) ($this->createPayload($res)['id'] ?? 0);
        self::assertGreaterThan(0, $pid, 'S5: patient id');

        $row = $this->patientRow($pid);
        self::assertSame($fx['clinic'], (int) $row['clinic_id'], 'S5: clinic_id is the trusted Clinic');
        self::assertArrayNotHasKey('location_id', $row, 'S5: patients table does not persist Location as ownership');
        self::assertTrue(!isset($row['identity_id']) || $row['identity_id'] === null || $row['identity_id'] === '', 'S5: identity_id stays empty');
    }

    // ============ S6 — Required fields + mobile normalization ============

    public function testS6_RequiredFieldsAndMobileNormalization(): void
    {
        $fx = $this->makeStage('s6');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic']);

        foreach (
            [
                ['first_name' => '', 'last_name' => 'Y', 'mobile' => '09121110051'],
                ['first_name' => 'X', 'last_name' => '', 'mobile' => '09121110052'],
                ['first_name' => 'X', 'last_name' => 'Y', 'mobile' => ''],
                ['first_name' => 'X', 'last_name' => 'Y', 'mobile' => '12345'],
            ] as $i => $body
        ) {
            $res = $this->dispatch('POST', self::CREATE, $body, $headers);
            self::assertContains($res->get_status(), [400, 422], 'S6: invalid payload #' . $i . ' rejected');
            self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($res), 'S6: established validation code for #' . $i);
        }
        self::assertSame(0, $this->countPatients($fx['clinic']), 'S6: invalid payloads create no row');

        $ok = $this->dispatch('POST', self::CREATE, [
            'first_name' => 'Norm',
            'last_name'  => 'Mobile',
            'mobile'     => '989121110055',
        ], $headers);
        self::assertSame(200, $ok->get_status(), 'S6: normalized mobile accepted — ' . $this->errCode($ok));
        self::assertSame('09121110055', (string) ($this->createPayload($ok)['mobile'] ?? ''), 'S6: +98 mobile normalized to 09xxxxxxxxx');
    }

    // ============ S7 — National-ID validation ============

    public function testS7_NationalIdValidationPreserved(): void
    {
        $fx = $this->makeStage('s7');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);

        $bad = $this->dispatch('POST', self::CREATE, [
            'first_name'  => 'Bad',
            'last_name'   => 'Nid',
            'mobile'      => '09121110061',
            'national_id' => self::NID_INVALID,
        ], $this->scopeHeaders($fx['clinic']));
        self::assertSame(400, $bad->get_status(), 'S7: invalid national ID rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($bad), 'S7: established validation code');
        self::assertSame(0, $this->countPatients($fx['clinic']), 'S7: invalid national ID creates no row');
        $json = (string) wp_json_encode($bad->get_data());
        self::assertStringNotContainsString('SQL', $json, 'S7: no SQL/internal leak');

        $ok = $this->dispatch('POST', self::CREATE, [
            'first_name'  => 'Good',
            'last_name'   => 'Nid',
            'mobile'      => '09121110062',
            'national_id' => self::NID_A,
            'birth_date'  => '1985-04-01',
            'gender'      => 'female',
        ], $this->scopeHeaders($fx['clinic']));
        self::assertSame(200, $ok->get_status(), 'S7: valid national ID accepted — ' . $this->errCode($ok));
        $row = $this->createPayload($ok);
        self::assertSame('***0899', (string) $row['national_id'], 'S7: national ID masked in the Reception presentation');
        self::assertSame('1985-04-01', (string) $row['birth_date']);
        self::assertSame('female', (string) $row['gender']);
    }

    // ============ S8 — Same-Clinic mobile duplicate ============

    public function testS8_SameClinicMobileDuplicatePreserved(): void
    {
        $fx = $this->makeStage('s8');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic']);

        $first = $this->dispatch('POST', self::CREATE, [
            'first_name' => 'First',
            'last_name'  => 'Dup',
            'mobile'     => '09121110071',
        ], $headers);
        self::assertSame(200, $first->get_status(), 'S8: first create answers — ' . $this->errCode($first));
        $firstId = (int) ($this->createPayload($first)['id'] ?? 0);

        $dup = $this->dispatch('POST', self::CREATE, [
            'first_name' => 'Second',
            'last_name'  => 'Dup',
            'mobile'     => '09121110071',
        ], $headers);
        self::assertSame(400, $dup->get_status(), 'S8: same-Clinic mobile duplicate rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($dup), 'S8: established duplicate code');
        self::assertSame(1, $this->countPatients($fx['clinic']), 'S8: no duplicate row');
        self::assertSame($firstId, $this->latestPatientId($fx['clinic']), 'S8: original row remains');
        $json = (string) wp_json_encode($dup->get_data());
        self::assertStringNotContainsString('SQL', $json, 'S8: no SQL/index leak');
        self::assertStringNotContainsString('u_pat_mobile', $json, 'S8: no index name leak');
    }

    // ============ S9 — Same-Clinic national-ID uniqueness ============

    public function testS9_SameClinicNationalIdConflictIsBoundedProductError(): void
    {
        $fx = $this->makeStage('s9');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic']);

        $first = $this->dispatch('POST', self::CREATE, [
            'first_name'  => 'First',
            'last_name'   => 'Nidunq',
            'mobile'      => '09121110081',
            'national_id' => self::NID_B,
        ], $headers);
        self::assertSame(200, $first->get_status(), 'S9: first create answers — ' . $this->errCode($first));

        $dup = $this->dispatch('POST', self::CREATE, [
            'first_name'  => 'Second',
            'last_name'   => 'Nidunq',
            'mobile'      => '09121110082',
            'national_id' => self::NID_B,
        ], $headers);
        self::assertSame(400, $dup->get_status(), 'S9: same-Clinic national-ID conflict is a product error, not a raw DB failure');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($dup), 'S9: bounded validation code');
        self::assertSame(1, $this->countPatients($fx['clinic']), 'S9: no duplicate row');
        $json = (string) wp_json_encode($dup->get_data());
        self::assertStringNotContainsString('SQL', $json, 'S9: no SQL leak');
        self::assertStringNotContainsString('Duplicate', $json, 'S9: no engine duplicate wording');
        self::assertStringNotContainsString('u_pat_nid', $json, 'S9: no index name leak');
        self::assertStringNotContainsString('cpms_patients', $json, 'S9: no table name leak');
    }

    // ============ S10 — Similar name alone does not block ============

    public function testS10_SimilarNameAloneDoesNotBlock(): void
    {
        $fx = $this->makeStage('s10');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $headers = $this->scopeHeaders($fx['clinic']);

        $a = $this->dispatch('POST', self::CREATE, [
            'first_name' => 'Same',
            'last_name'  => 'Namex',
            'mobile'     => '09121110091',
        ], $headers);
        $b = $this->dispatch('POST', self::CREATE, [
            'first_name' => 'Same',
            'last_name'  => 'Namex',
            'mobile'     => '09121110092',
        ], $headers);
        self::assertSame(200, $a->get_status(), 'S10: first same-name create answers');
        self::assertSame(200, $b->get_status(), 'S10: second same-name different-mobile is allowed — ' . $this->errCode($b));
        self::assertSame(2, $this->countPatients($fx['clinic']), 'S10: two rows exist');
        self::assertNotSame(
            (int) ($this->createPayload($a)['id'] ?? 0),
            (int) ($this->createPayload($b)['id'] ?? 0),
            'S10: two distinct patients'
        );
    }

    // ============ S11 — Privacy / dormant identity / no auto workflow ============

    public function testS11_BoundedResponseNoClinicalNoAutoWorkflow(): void
    {
        $fx = $this->makeStage('s11');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($fx['secretary']);
        $before = $this->workflowFingerprint($fx['clinic']);

        $res = $this->dispatch('POST', self::CREATE, [
            'first_name'            => 'Private',
            'last_name'             => 'Clinicalx',
            'mobile'                => '09121110101',
            'national_id'           => self::NID_C,
            'medical_history'       => 'HISTORY-SECRET',
            'medication_allergies'  => '["penicillin-SECRET"]',
            'address'               => 'ADDRESS-SECRET',
            'emergency_contact_phone' => '09990000000',
            'blood_group'           => 'A+',
        ], $this->scopeHeaders($fx['clinic'], $fx['loc_a']));
        self::assertSame(200, $res->get_status(), 'S11: create answers — ' . $this->errCode($res));
        $row = $this->createPayload($res);
        $keys = array_keys($row);
        sort($keys);
        self::assertSame(self::SEARCH_KEYS, $keys, 'S11: Reception create returns only the established search presentation');
        self::assertSame('***2419', (string) $row['national_id'], 'S11: national ID masked');

        $json = (string) wp_json_encode($res->get_data());
        foreach (['0013542419', 'SECRET', '09990000000', 'medication_allergies', 'medical_history', 'address', 'emergency_contact', 'blood_group', 'staffView'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'S11: payload must not expose "' . $needle . '"');
        }

        $pid = (int) $row['id'];
        $stored = $this->patientRow($pid);
        self::assertTrue($stored['medical_history'] === null || $stored['medical_history'] === '', 'S11: Reception must not write clinical history');
        self::assertTrue($stored['address'] === null || $stored['address'] === '', 'S11: Reception must not write address');
        self::assertTrue(!isset($stored['identity_id']) || $stored['identity_id'] === null || $stored['identity_id'] === '', 'S11: identity_id stays dormant');
        self::assertSame(0, $this->countIdentities(), 'S11: Organization patient-identity table stays empty for this create');

        $after = $this->workflowFingerprint($fx['clinic']);
        self::assertSame($before['cpms_visits'], $after['cpms_visits'], 'S11: no Visit created');
        self::assertSame($before['cpms_appointments'], $after['cpms_appointments'], 'S11: no appointment created');
        self::assertSame($before['cpms_visit_status_history'], $after['cpms_visit_status_history'], 'S11: no queue/visit transition');
        self::assertSame($before['walkins'], $after['walkins'], 'S11: no automatic walk-in');
    }

    // ============ S12 — Slice 1 + Slice 2 remain intact (CONTROL) ============

    public function testS12_Slice1AndSlice2RemainIntact(): void
    {
        $fx = $this->makeStage('s12');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        $pid = $this->insertPatient($fx['clinic'], 'Slice', 'Twox', '09121110111', null, 'MR-S12-0001');
        wp_set_current_user($fx['secretary']);

        $search = $this->dispatch('GET', self::SEARCH, ['q' => 'Twox'], $this->scopeHeaders($fx['clinic'], $fx['loc_a']));
        self::assertSame(200, $search->get_status(), 'S12: Slice 2 reception search still answers');
        self::assertSame([$pid], $this->searchIds($search), 'S12: Slice 2 search still finds the Clinic patient');

        $html = $this->renderReception($fx['secretary']);
        self::assertStringContainsString('data-role="sr-board"', $html, 'S12: Slice 1 Arrival Board surface remains');
        self::assertStringContainsString('data-role="sr-appointments"', $html, 'S12: Slice 1 appointments table remains');
        self::assertStringContainsString('data-role="sr-search"', $html, 'S12: Slice 2 search panel remains');
        self::assertStringContainsString('data-role="sr-search-input"', $html, 'S12: Slice 2 search input remains');
        self::assertStringContainsString('/staff/portal/reception/patients/search', $html, 'S12: Slice 2 search route remains');
        self::assertStringContainsString('/staff/portal/reception/arrivals', $html, 'S12: Slice 1 arrival route remains');
    }

    // ============ Helpers ============

    /**
     * @return array<string, int>
     */
    private function makeStage(string $tag): array
    {
        $org = $this->insertOrg('Crt Org ' . $tag);
        $clinic = $this->insertClinic('Crt Clinic ' . $tag, $org);
        $locA = $this->insertLocation($clinic, 'Crt A ' . $tag, 1);
        $locB = $this->insertLocation($clinic, 'Crt B ' . $tag, 0);
        $doctor = $this->makeUser('qa_' . $tag . '_crtdoc', RolesAndCapabilities::ROLE_DOCTOR);
        $secretary = $this->makeUser('qa_' . $tag . '_crtsec', RolesAndCapabilities::ROLE_SECRETARY);
        return [
            'clinic'    => $clinic,
            'loc_a'     => $locA,
            'loc_b'     => $locB,
            'doctor'    => $doctor,
            'secretary' => $secretary,
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
    private function createPayload(WP_REST_Response $res): array
    {
        if ($res->get_status() !== 200) {
            return [];
        }
        $b = $res->get_data();
        if (is_array($b) && isset($b['data']) && is_array($b['data'])) {
            return $b['data'];
        }
        return [];
    }

    /**
     * @return list<int>
     */
    private function searchIds(WP_REST_Response $res): array
    {
        $ids = [];
        if ($res->get_status() !== 200) {
            return $ids;
        }
        $b = $res->get_data();
        $rows = (is_array($b) && isset($b['data']) && is_array($b['data'])) ? $b['data'] : [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    private function errCode(WP_REST_Response $res): string
    {
        $b = $res->get_data();
        if ($b instanceof \WP_Error) {
            return (string) $b->get_error_code();
        }
        return (string) (is_array($b) ? ($b['code'] ?? '') : '');
    }

    private function countPatients(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d',
            [$clinicId]
        );
    }

    private function latestPatientId(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d ORDER BY id DESC LIMIT 1',
            [$clinicId]
        );
    }

    private function patientClinic(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$patientId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function patientRow(int $patientId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d LIMIT 1',
            [$patientId]
        );
        return is_array($row) ? $row : [];
    }

    private function countIdentities(): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_patient_identities'));
    }

    /**
     * @return array<string, string>
     */
    private function workflowFingerprint(int $clinicId): array
    {
        $db = App::db();
        return [
            'cpms_visits'               => (string) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id = %d', [$clinicId]),
            'cpms_appointments'         => (string) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d', [$clinicId]),
            'cpms_visit_status_history' => (string) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_visit_status_history')),
            'walkins'                   => (string) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d AND is_walkin_express = 1', [$clinicId]),
        ];
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
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', $orgId, $name, 'cl-' . bin2hex(random_bytes(3)), 'Asia/Tehran', $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        App::resetScope();
        return $id;
    }

    private function insertLocation(int $clinicId, string $name, int $primary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', $clinicId, $name, 'loc-' . bin2hex(random_bytes(3)), 'Asia/Tehran', $primary, $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $first, string $last, string $mobile, ?string $nationalId, string $mrn): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok = $wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id'   => $clinicId,
            'mrn'         => $mrn,
            'first_name'  => $first,
            'last_name'   => $last,
            'mobile'      => $mobile,
            'national_id' => $nationalId,
            'status'      => 'active',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        self::assertNotFalse($ok, 'patient fixture insert: ' . $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }
}
