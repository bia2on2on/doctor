<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Validators\NationalIdValidator;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Phase 9 Slice 4 — Patient Profile with trusted Clinic/Patient Record Selector.
 *
 * TEST-ONLY VALID RED. This suite specifies the future real REST contract:
 *
 * - GET /clinic/v1/patient/my-records lists only the caller's durable active
 *   Patient links and only the bounded selector/display fields below;
 * - link_id selects a record on the existing GET/PUT /patient/me paths;
 * - a link is a selector, never authority. Authority remains the authenticated
 *   WP user + a durable matching cpms_patient_user_links row + an active Patient
 *   with the persisted Patient/Clinic relationship;
 * - N active links requires explicit selection: no primary/first fallback.
 *
 * Do not add a migration, route implementation, UI, JS, CSS, ScopeContext,
 * mobile-auth, booking, or Staff Portal behavior here. Fixtures use real
 * WordPress users, migrated CPMS tables, permission callbacks, and REST paths.
 */
final class Phase9Slice4PatientProfileRecordSelectorRedTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';
    private const ME_PATH = self::NS . '/patient/me';
    private const MY_RECORDS_PATH = self::NS . '/patient/my-records';

    /** @var list<string> */
    private const RECORD_KEYS = [
        'link_id',
        'clinic_id',
        'clinic_name',
        'patient_id',
        'patient_display_name',
        'mrn',
        'is_primary',
    ];

    private string $fixtureTag = '';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        $this->fixtureTag = bin2hex(random_bytes(5));
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

    /**
     * RED-1: the record list is a bounded selector surface, not a profile dump.
     */
    public function testMyRecordsListsOnlyCallersActiveLinkedClinicPatientRecords(): void
    {
        $clinics = $this->createTwoClinicFixture();
        $callerUserId = $this->createUser('r1-caller', 'cpms_patient');
        $foreignUserId = $this->createUser('r1-foreign', 'cpms_patient');

        $patientA = $this->insertPatient(
            $clinics['a_id'],
            'MR-P9S4-R1-A-' . $this->fixtureTag,
            'Alpha',
            'Listed',
            $this->mobileFor('r1-a')
        );
        $patientB = $this->insertPatient(
            $clinics['b_id'],
            'MR-P9S4-R1-B-' . $this->fixtureTag,
            'Beta',
            'Listed',
            $this->mobileFor('r1-b')
        );
        $foreignPatient = $this->insertPatient(
            $clinics['a_id'],
            'MR-P9S4-R1-F-' . $this->fixtureTag,
            'Foreign',
            'Owner',
            $this->mobileFor('r1-f')
        );
        $inactivePatient = $this->insertPatient(
            $clinics['b_id'],
            'MR-P9S4-R1-I-' . $this->fixtureTag,
            'Inactive',
            'Record',
            $this->mobileFor('r1-i'),
            'archived'
        );

        $linkA = $this->insertLink($clinics['a_id'], $patientA, $callerUserId, $this->mobileFor('r1-a'), 1);
        $linkB = $this->insertLink($clinics['b_id'], $patientB, $callerUserId, $this->mobileFor('r1-b'), 0);
        $foreignLink = $this->insertLink($clinics['a_id'], $foreignPatient, $foreignUserId, $this->mobileFor('r1-f'), 1);
        $inactiveLink = $this->insertLink($clinics['b_id'], $inactivePatient, $callerUserId, $this->mobileFor('r1-i'), 0);

        // Material fixture controls: two real non-default Clinics, durable links,
        // one foreign user, and one archived Patient all exist before the request.
        $this->assertMaterializedLink($linkA, $patientA, $callerUserId, $clinics['a_id'], 'active', 1);
        $this->assertMaterializedLink($linkB, $patientB, $callerUserId, $clinics['b_id'], 'active', 0);
        $this->assertMaterializedLink($foreignLink, $foreignPatient, $foreignUserId, $clinics['a_id'], 'active', 1);
        $this->assertMaterializedLink($inactiveLink, $inactivePatient, $callerUserId, $clinics['b_id'], 'archived', 0);

        $response = $this->dispatch('GET', self::MY_RECORDS_PATH, [], $callerUserId);

        self::assertSame(200, $response->get_status(), 'GET /patient/my-records must be a real authenticated REST route.');
        $body = $response->get_data();
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertIsArray($body['data']);
        self::assertCount(2, $body['data'], 'Only the caller\'s two active durable links may be listed.');

        /** @var array<int, array<string, mixed>> $recordsByLink */
        $recordsByLink = [];
        foreach ($body['data'] as $record) {
            self::assertIsArray($record);
            $keys = array_keys($record);
            sort($keys);
            $expectedKeys = self::RECORD_KEYS;
            sort($expectedKeys);
            self::assertSame($expectedKeys, $keys, 'List records must contain only the bounded selector/display contract.');
            $recordsByLink[(int) $record['link_id']] = $record;
        }

        ksort($recordsByLink);
        $expectedLinkIds = [$linkA, $linkB];
        sort($expectedLinkIds);
        self::assertSame($expectedLinkIds, array_keys($recordsByLink), 'List membership must be exactly the caller\'s active links.');
        self::assertArrayNotHasKey($foreignLink, $recordsByLink, 'A different WP user\'s link must never be exposed.');
        self::assertArrayNotHasKey($inactiveLink, $recordsByLink, 'An archived Patient must never be selectable.');

        self::assertSame($clinics['a_id'], (int) $recordsByLink[$linkA]['clinic_id']);
        self::assertSame($clinics['a_name'], (string) $recordsByLink[$linkA]['clinic_name']);
        self::assertSame($patientA, (int) $recordsByLink[$linkA]['patient_id']);
        self::assertSame('Alpha Listed', (string) $recordsByLink[$linkA]['patient_display_name']);
        self::assertSame('MR-P9S4-R1-A-' . $this->fixtureTag, (string) $recordsByLink[$linkA]['mrn']);
        self::assertTrue((bool) $recordsByLink[$linkA]['is_primary']);

        self::assertSame($clinics['b_id'], (int) $recordsByLink[$linkB]['clinic_id']);
        self::assertSame($clinics['b_name'], (string) $recordsByLink[$linkB]['clinic_name']);
        self::assertSame($patientB, (int) $recordsByLink[$linkB]['patient_id']);
        self::assertSame('Beta Listed', (string) $recordsByLink[$linkB]['patient_display_name']);
        self::assertSame('MR-P9S4-R1-B-' . $this->fixtureTag, (string) $recordsByLink[$linkB]['mrn']);
        self::assertFalse((bool) $recordsByLink[$linkB]['is_primary']);
    }

    /**
     * RED-2: a selected non-primary record wins; omission with N active links
     * fails canonically; an edit targets only that selected record.
     */
    public function testMultiLinkedPatientRequiresExplicitSelectionAndReadEditTargetSelectedRecordOnly(): void
    {
        $clinics = $this->createTwoClinicFixture();
        $callerUserId = $this->createUser('r2-caller', 'cpms_patient');

        $patientA = $this->insertPatient(
            $clinics['a_id'],
            'MR-P9S4-R2-A-' . $this->fixtureTag,
            'Primary',
            'Alpha',
            $this->mobileFor('r2-a'),
            'active',
            null,
            'A clinical history must remain in Clinic A.'
        );
        $patientB = $this->insertPatient(
            $clinics['b_id'],
            'MR-P9S4-R2-B-' . $this->fixtureTag,
            'Selected',
            'Beta',
            $this->mobileFor('r2-b'),
            'active',
            null,
            'B clinical history is self-service read-only.'
        );
        $linkA = $this->insertLink($clinics['a_id'], $patientA, $callerUserId, $this->mobileFor('r2-a'), 1);
        $linkB = $this->insertLink($clinics['b_id'], $patientB, $callerUserId, $this->mobileFor('r2-b'), 0);

        $this->assertMaterializedLink($linkA, $patientA, $callerUserId, $clinics['a_id'], 'active', 1);
        $this->assertMaterializedLink($linkB, $patientB, $callerUserId, $clinics['b_id'], 'active', 0);
        self::assertSame([
            'first_name', 'last_name', 'birth_date', 'gender', 'address', 'phone',
            'national_id', 'emergency_contact_name', 'emergency_contact_phone',
        ], PatientService::ME_EDITABLE, 'Record selection must not widen the established self-service whitelist.');

        $selectedRead = $this->dispatch('GET', self::ME_PATH, ['link_id' => $linkB], $callerUserId);
        self::assertSame(200, $selectedRead->get_status());
        $selectedReadBody = $selectedRead->get_data();
        self::assertSame($patientB, (int) ($selectedReadBody['data']['id'] ?? 0), 'link_id must select linked non-primary B; it may not fall back to primary A.');
        self::assertSame('Selected', (string) ($selectedReadBody['data']['first_name'] ?? ''));

        $noSelection = $this->dispatch('GET', self::ME_PATH, [], $callerUserId);
        $this->assertClinicError(
            $noSelection,
            'CLINIC_SELECTION_REQUIRED',
            422,
            'N active linked Patient records require explicit selection; primary/first fallback is forbidden.'
        );

        $patientCountBefore = $this->countRows('cpms_patients');
        $linkCountBefore = $this->countRows('cpms_patient_user_links');
        $patientABefore = $this->patientRow($patientA);
        $patientBBefore = $this->patientRow($patientB);
        $auditABefore = $this->auditCountForPatient($patientA);
        $auditBBefore = $this->auditCountForPatient($patientB);

        // mobile and clinical fields are deliberately included as self-service
        // negative controls. ME_EDITABLE must remain unchanged by selector work.
        $selectedEdit = $this->dispatch('PUT', self::ME_PATH, [
            'link_id' => $linkB,
            'first_name' => 'Selected Edited',
            'mobile' => '09999999999',
            'medical_history' => 'Client supplied clinical history must be ignored.',
        ], $callerUserId);
        self::assertSame(200, $selectedEdit->get_status());
        $selectedEditBody = $selectedEdit->get_data();
        self::assertSame($patientB, (int) ($selectedEditBody['data']['id'] ?? 0), 'PUT must target selected B, never primary A.');

        $patientAAfter = $this->patientRow($patientA);
        $patientBAfter = $this->patientRow($patientB);
        self::assertSame($patientABefore['clinic_id'], $patientAAfter['clinic_id']);
        self::assertSame($patientABefore['first_name'], $patientAAfter['first_name'], 'The Clinic-A primary record must not be edited.');
        self::assertSame($patientABefore['mobile'], $patientAAfter['mobile']);
        self::assertSame($patientABefore['medical_history'], $patientAAfter['medical_history']);

        self::assertSame($clinics['b_id'], (int) $patientBAfter['clinic_id']);
        self::assertSame('Selected Edited', (string) $patientBAfter['first_name']);
        self::assertSame($patientBBefore['mobile'], $patientBAfter['mobile'], 'Login/authentication mobile remains read-only.');
        self::assertSame($patientBBefore['medical_history'], $patientBAfter['medical_history'], 'ME_EDITABLE must not gain clinical fields.');
        self::assertSame($patientCountBefore, $this->countRows('cpms_patients'), 'Editing must not create a Patient row.');
        self::assertSame($linkCountBefore, $this->countRows('cpms_patient_user_links'), 'Editing must not create or propagate a link.');
        self::assertSame($auditABefore, $this->auditCountForPatient($patientA), 'The unselected record has no successful-update audit.');
        self::assertSame($auditBBefore + 1, $this->auditCountForPatient($patientB), 'The selected record keeps the established PATIENT_PROFILE_UPDATED audit.');
    }

    /**
     * RED-3: selectors that the caller cannot use must be indistinguishable
     * from a nonexistent selector and must cause no mutation.
     */
    public function testForeignOrInactivePatientRecordSelectorFailsClosedWithoutExistenceLeak(): void
    {
        $clinics = $this->createTwoClinicFixture();
        $callerUserId = $this->createUser('r3-caller', 'cpms_patient');
        $foreignUserId = $this->createUser('r3-foreign', 'cpms_patient');
        $nonPatientUserId = $this->createUser('r3-non-patient', 'subscriber');
        $noLinkUserId = $this->createUser('r3-no-link', 'cpms_patient');

        $allowedPatient = $this->insertPatient(
            $clinics['a_id'],
            'MR-P9S4-R3-OK-' . $this->fixtureTag,
            'Allowed',
            'Record',
            $this->mobileFor('r3-ok')
        );
        $foreignPatient = $this->insertPatient(
            $clinics['b_id'],
            'MR-P9S4-R3-F-' . $this->fixtureTag,
            'Foreign',
            'Record',
            $this->mobileFor('r3-f')
        );
        $inactivePatient = $this->insertPatient(
            $clinics['b_id'],
            'MR-P9S4-R3-I-' . $this->fixtureTag,
            'Inactive',
            'Record',
            $this->mobileFor('r3-i'),
            'archived'
        );

        $allowedLink = $this->insertLink($clinics['a_id'], $allowedPatient, $callerUserId, $this->mobileFor('r3-ok'), 1);
        $foreignLink = $this->insertLink($clinics['b_id'], $foreignPatient, $foreignUserId, $this->mobileFor('r3-f'), 1);
        $inactiveLink = $this->insertLink($clinics['b_id'], $inactivePatient, $callerUserId, $this->mobileFor('r3-i'), 0);

        $this->assertMaterializedLink($allowedLink, $allowedPatient, $callerUserId, $clinics['a_id'], 'active', 1);
        $this->assertMaterializedLink($foreignLink, $foreignPatient, $foreignUserId, $clinics['b_id'], 'active', 1);
        $this->assertMaterializedLink($inactiveLink, $inactivePatient, $callerUserId, $clinics['b_id'], 'archived', 0);

        // Existing permission and no-link guards are deliberate positive controls,
        // before the selector privacy contract starts.
        $withoutNonce = $this->dispatch('GET', self::ME_PATH, [], $callerUserId, false);
        $this->assertClinicError($withoutNonce, 'CLINIC_INVALID_NONCE', 403, 'wp_rest nonce remains mandatory.');

        $wrongRole = $this->dispatch('GET', self::ME_PATH, [], $nonPatientUserId);
        $this->assertClinicError($wrongRole, 'CLINIC_PERMISSION_DENIED', 403, 'Only the cpms_patient role can use patient self-service.');

        $noLink = $this->dispatch('GET', self::ME_PATH, [], $noLinkUserId);
        $this->assertClinicError($noLink, 'CLINIC_NOT_FOUND', 404, 'A patient-role user without a durable link remains not-found.');

        $patientCountBefore = $this->countRows('cpms_patients');
        $linkCountBefore = $this->countRows('cpms_patient_user_links');
        $auditCountBefore = $this->auditCountForPatient($allowedPatient);
        $allowedBefore = $this->patientRow($allowedPatient);

        $foreign = $this->dispatch('GET', self::ME_PATH, ['link_id' => $foreignLink], $callerUserId);
        $this->assertClinicError($foreign, 'CLINIC_NOT_FOUND', 404, 'A foreign user\'s link_id must fail closed without revealing it exists.');

        $inactive = $this->dispatch('GET', self::ME_PATH, ['link_id' => $inactiveLink], $callerUserId);
        $this->assertClinicError($inactive, 'CLINIC_NOT_FOUND', 404, 'A link to an archived Patient must fail closed without revealing it exists.');

        $nonexistent = $this->dispatch('GET', self::ME_PATH, ['link_id' => $foreignLink + 999999], $callerUserId);
        $this->assertClinicError($nonexistent, 'CLINIC_NOT_FOUND', 404, 'A nonexistent link_id must receive the same canonical not-found response.');

        self::assertSame($foreign->get_data(), $inactive->get_data(), 'Foreign and inactive selectors must have the exact same non-enumerating envelope.');
        self::assertSame($foreign->get_data(), $nonexistent->get_data(), 'Foreign and nonexistent selectors must have the exact same non-enumerating envelope.');
        self::assertSame($patientCountBefore, $this->countRows('cpms_patients'), 'Rejected selectors may not create or change Patient records.');
        self::assertSame($linkCountBefore, $this->countRows('cpms_patient_user_links'), 'Rejected selectors may not create or change durable links.');
        self::assertSame($auditCountBefore, $this->auditCountForPatient($allowedPatient), 'Rejected selector reads may not log successful profile mutation.');
        self::assertSame($allowedBefore, $this->patientRow($allowedPatient), 'Rejected selectors may not mutate the caller\'s valid record.');
    }

    /**
     * RED-4: a database uniqueness collision is product validation, not a soft
     * wpdb success. The attempted duplicate is sent only through real REST.
     */
    public function testDuplicateNationalIdWithinSelectedClinicFailsCanonicallyWithoutFalseSuccess(): void
    {
        $clinics = $this->createTwoClinicFixture();
        $callerUserId = $this->createUser('r4-caller', 'cpms_patient');

        self::assertTrue(NationalIdValidator::isValid('0000000140'), 'Fixture control: valid Iranian national ID.');
        self::assertTrue(NationalIdValidator::isValid('0000000061'), 'Fixture control: valid Iranian national ID.');

        $selectedPatient = $this->insertPatient(
            $clinics['b_id'],
            'MR-P9S4-R4-S-' . $this->fixtureTag,
            'Selected',
            'NationalId',
            $this->mobileFor('r4-s')
        );
        $sameClinicDuplicate = $this->insertPatient(
            $clinics['b_id'],
            'MR-P9S4-R4-D-' . $this->fixtureTag,
            'Existing',
            'NationalId',
            $this->mobileFor('r4-d'),
            'active',
            '0000000061'
        );
        $crossClinicSameNationalId = $this->insertPatient(
            $clinics['a_id'],
            'MR-P9S4-R4-X-' . $this->fixtureTag,
            'CrossClinic',
            'NationalId',
            $this->mobileFor('r4-x'),
            'active',
            '0000000061'
        );
        $selectedLink = $this->insertLink($clinics['b_id'], $selectedPatient, $callerUserId, $this->mobileFor('r4-s'), 1);

        $this->assertMaterializedLink($selectedLink, $selectedPatient, $callerUserId, $clinics['b_id'], 'active', 1);
        self::assertSame(1, $this->countPatientsByClinicAndNationalId($clinics['b_id'], '0000000061'), 'Duplicate fixture exists in the selected Clinic.');
        self::assertSame(1, $this->countPatientsByClinicAndNationalId($clinics['a_id'], '0000000061'), 'The same national ID in another Clinic is not a cross-Clinic merge signal.');

        // Positive controls: the existing one-link /patient/me read remains
        // automatic, and national ID remains self-service editable when valid
        // and unique in the selected Clinic.
        $singleLinkRead = $this->dispatch('GET', self::ME_PATH, [], $callerUserId);
        self::assertSame(200, $singleLinkRead->get_status(), 'One active durable link keeps existing /patient/me behavior.');
        $singleLinkBody = $singleLinkRead->get_data();
        self::assertSame($selectedPatient, (int) ($singleLinkBody['data']['id'] ?? 0));

        $auditBeforeValidEdit = $this->auditCountForPatient($selectedPatient);
        $validEdit = $this->dispatch('PUT', self::ME_PATH, [
            'link_id' => $selectedLink,
            'national_id' => '0000000140',
        ], $callerUserId);
        self::assertSame(200, $validEdit->get_status(), 'A valid unique national ID edit remains supported.');
        $validBody = $validEdit->get_data();
        self::assertSame($selectedPatient, (int) ($validBody['data']['id'] ?? 0));
        self::assertSame('0000000140', (string) ($validBody['data']['national_id'] ?? ''));
        self::assertSame('0000000140', (string) $this->patientRow($selectedPatient)['national_id']);
        self::assertSame($auditBeforeValidEdit + 1, $this->auditCountForPatient($selectedPatient), 'Successful edit retains PATIENT_PROFILE_UPDATED audit behavior.');

        $selectedBeforeReject = $this->patientRow($selectedPatient);
        $patientCountBeforeReject = $this->countRows('cpms_patients');
        $linkCountBeforeReject = $this->countRows('cpms_patient_user_links');
        $auditBeforeReject = $this->auditCountForPatient($selectedPatient);

        // No raw SQL is used for the attempted duplicate: this is the actual
        // selected-record self-service API path under the future link_id contract.
        $duplicateEdit = $this->dispatch('PUT', self::ME_PATH, [
            'link_id' => $selectedLink,
            'national_id' => '0000000061',
        ], $callerUserId);
        $this->assertClinicError(
            $duplicateEdit,
            'CLINIC_VALIDATION_FAILED',
            400,
            'Duplicate valid national ID within the selected Clinic must be canonical validation failure, never false success.'
        );

        self::assertSame($selectedBeforeReject, $this->patientRow($selectedPatient), 'A rejected duplicate may not mutate the selected record.');
        self::assertSame($patientCountBeforeReject, $this->countRows('cpms_patients'), 'A rejected duplicate may not create Patient records.');
        self::assertSame($linkCountBeforeReject, $this->countRows('cpms_patient_user_links'), 'A rejected duplicate may not create links or associations.');
        self::assertSame($auditBeforeReject, $this->auditCountForPatient($selectedPatient), 'A rejected duplicate may not write a successful PATIENT_PROFILE_UPDATED audit.');
        self::assertSame('0000000061', (string) $this->patientRow($sameClinicDuplicate)['national_id']);
        self::assertSame('0000000061', (string) $this->patientRow($crossClinicSameNationalId)['national_id']);
    }

    /**
     * @return array{a_id: int, a_name: string, b_id: int, b_name: string}
     */
    private function createTwoClinicFixture(): array
    {
        $now = App::db()->nowUtcSql();
        $organizationId = $this->insertRow('cpms_organizations', [
            'name' => 'P9S4 Organization ' . $this->fixtureTag,
            'slug' => 'p9s4-org-' . $this->fixtureTag,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s'], 'organization');

        $clinicAName = 'P9S4 Clinic Alpha ' . $this->fixtureTag;
        $clinicBName = 'P9S4 Clinic Beta ' . $this->fixtureTag;
        $clinicA = $this->insertRow('cpms_clinics', [
            'organization_id' => $organizationId,
            'name' => $clinicAName,
            'slug' => 'p9s4-alpha-' . $this->fixtureTag,
            'timezone' => 'Asia/Tehran',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s'], 'clinic A');
        $clinicB = $this->insertRow('cpms_clinics', [
            'organization_id' => $organizationId,
            'name' => $clinicBName,
            'slug' => 'p9s4-beta-' . $this->fixtureTag,
            'timezone' => 'Asia/Tehran',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s'], 'clinic B');

        self::assertGreaterThan(1, $clinicA, 'Fixture Clinic IDs must be dynamic/non-default.');
        self::assertGreaterThan(1, $clinicB, 'Fixture Clinic IDs must be dynamic/non-default.');
        self::assertNotSame($clinicA, $clinicB, 'Fixture requires genuinely distinct Clinic IDs.');

        return [
            'a_id' => $clinicA,
            'a_name' => $clinicAName,
            'b_id' => $clinicB,
            'b_name' => $clinicBName,
        ];
    }

    private function createUser(string $suffix, string $role): int
    {
        $login = 'p9s4_' . $this->fixtureTag . '_' . $suffix;
        $userId = (int) wp_create_user($login, 'pass-not-used-123', $login . '@test.local');
        self::assertGreaterThan(0, $userId, 'Fixture WP user must be created.');
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $userId;
    }

    private function insertPatient(
        int $clinicId,
        string $mrn,
        string $firstName,
        string $lastName,
        string $mobile,
        string $status = 'active',
        ?string $nationalId = null,
        ?string $medicalHistory = null
    ): int {
        $data = [
            'clinic_id' => $clinicId,
            'mrn' => $mrn,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'mobile' => $mobile,
            'status' => $status,
            'created_at' => App::db()->nowUtcSql(),
            'updated_at' => App::db()->nowUtcSql(),
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];
        if ($nationalId !== null) {
            $data['national_id'] = $nationalId;
            $formats[] = '%s';
        }
        if ($medicalHistory !== null) {
            $data['medical_history'] = $medicalHistory;
            $formats[] = '%s';
        }

        return $this->insertRow('cpms_patients', $data, $formats, 'patient');
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
        ], ['%d', '%d', '%d', '%s', '%d', '%s'], 'patient-user link');
    }

    private function assertMaterializedLink(
        int $linkId,
        int $patientId,
        int $userId,
        int $clinicId,
        string $patientStatus,
        int $isPrimary
    ): void {
        $row = App::db()->fetchRow(
            'SELECT l.id AS link_id, l.clinic_id AS link_clinic_id, l.patient_id, l.wp_user_id, l.is_primary,
                    p.clinic_id AS patient_clinic_id, p.status AS patient_status
             FROM ' . App::db()->table('cpms_patient_user_links') . ' l
             JOIN ' . App::db()->table('cpms_patients') . ' p ON p.id = l.patient_id
             WHERE l.id = %d',
            [$linkId]
        );
        self::assertIsArray($row, 'Durable cpms_patient_user_links fixture row must exist.');
        self::assertSame($linkId, (int) $row['link_id']);
        self::assertSame($clinicId, (int) $row['link_clinic_id']);
        self::assertSame($patientId, (int) $row['patient_id']);
        self::assertSame($userId, (int) $row['wp_user_id']);
        self::assertSame($clinicId, (int) $row['patient_clinic_id'], 'Link and Patient Clinic relationship must be persisted and equal.');
        self::assertSame($patientStatus, (string) $row['patient_status']);
        self::assertSame($isPrimary, (int) $row['is_primary']);
    }

    /**
     * @return array<string, mixed>
     */
    private function patientRow(int $patientId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$patientId]
        );
        self::assertIsArray($row, 'Patient fixture row must exist.');

        return $row;
    }

    private function countPatientsByClinicAndNationalId(int $clinicId, string $nationalId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d AND national_id = %s',
            [$clinicId, $nationalId]
        );
    }

    private function auditCountForPatient(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . '
             WHERE action = %s AND resource_type = %s AND resource_id = %d',
            ['PATIENT_PROFILE_UPDATED', 'patient', $patientId]
        );
    }

    private function countRows(string $table): int
    {
        $allowed = ['cpms_patients', 'cpms_patient_user_links'];
        self::assertContains($table, $allowed, 'Only fixed CPMS fixture tables may be counted.');

        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($table));
    }

    private function mobileFor(string $suffix): string
    {
        $number = (int) sprintf('%u', crc32($this->fixtureTag . ':' . $suffix)) % 1_000_000_000;

        return '09' . str_pad((string) $number, 9, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dispatch(string $method, string $route, array $params, int $userId, bool $withNonce = true): WP_REST_Response
    {
        wp_set_current_user($userId);
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($withNonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }

        return rest_do_request($request);
    }

    private function assertClinicError(WP_REST_Response $response, string $code, int $status, string $what): void
    {
        self::assertSame($status, $response->get_status(), $what . ' Body: ' . wp_json_encode($response->get_data()));
        $data = $response->get_data();
        self::assertIsArray($data, 'REST error envelope must be an array.');
        self::assertSame($code, (string) ($data['code'] ?? ''), $what);
        self::assertArrayHasKey('message', $data, 'REST error envelope must carry a user message.');
        self::assertSame($status, (int) ($data['data']['status'] ?? 0), 'REST error data.status must match HTTP status.');
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $formats
     */
    private function insertRow(string $table, array $data, array $formats, string $label): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . $table, $data, $formats);
        self::assertTrue((bool) $ok, 'Fixture ' . $label . ' insert failed: ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'Fixture ' . $label . ' ID must be a durable positive database ID.');

        return $id;
    }
}
