<?php

/**
 * Phase 9 Slice 4 — Patient Profile UI inside the EXISTING CPMS Patient Portal shell.
 *
 * TEST-ONLY VALID RED. This suite specifies the smallest complete Profile UI
 * surface that must appear inside the already-approved, owner-signed
 * independent CPMS Patient Portal shell (Phase 9 Slice 3 GREEN). It does NOT
 * redesign the shell/header/footer, does NOT add any migration, REST route,
 * auth, router, SPA framework, or backend selector rewrite, and does NOT
 * touch Staff Portal / Visits / Prescriptions / Files / mobile-change flow.
 *
 * Hybrid contract (owner policy):
 *   Server-rendered: shell, navigation, record selector, initial selected Profile state.
 *   Small vanilla JS: record selection (when N>1), profile save, loading/success/error.
 *
 * Authority contract:
 *   link_id = selector only. Client MUST NOT publish/use raw clinic_id,
 *   patient_id, organization_id, role as authority. Backend resolves
 *   authenticated WP user + durable link + active Patient + persisted Clinic.
 *
 * Mobile contract:
 *   Login/authentication mobile is read-only. No mobile-edit input/OTP flow.
 *
 * National ID contract:
 *   Visible and self-editable as part of Profile. NOT a booking requirement.
 *   Server validation/error remains authoritative; no client-side duplicate check.
 *
 * INTENDED RED — exactly four tests fail on the live Slice-3-green main,
 * attributable ONLY to the missing Profile UI (product-level assertion
 * failures — never bootstrap/fixture/SQL/FK/harness/nonce/permission failures).
 *
 *   R1 testPatientPortalExposesProfileNavigationAndProfileSection
 *   R2 testProfileRecordSelectorUsesOnlyServerLinkedRecordsAndRequiresExplicitChoiceForMultiple
 *   R3 testProfileFormExposesAllowedFieldsAndKeepsLoginMobileReadOnly
 *   R4 testProfileSaveUsesSelectedLinkAndCanonicalBackendErrors
 *
 * Expected RED: exactly the four tests above fail on main; all positive guards green.
 *
 * Positive guards (MUST stay GREEN on current main and after GREEN):
 *   - real shell renders (Slice 3 contract preserved);
 *   - authenticated patient session works;
 *   - backend GET /patient/my-records is green;
 *   - selected GET /patient/me?link_id= is green;
 *   - selected EDIT /patient/me is green;
 *   - N>1 missing link_id returns CLINIC_SELECTION_REQUIRED (422);
 *   - foreign link_id fails closed (404);
 *   - login mobile is non-editable via REST (rejected silently per service whitelist);
 *   - valid National ID update works;
 *   - duplicate National ID canonical failure (CLINIC_VALIDATION_FAILED) works.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Validators\NationalIdValidator;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase9Slice4PatientProfileUiRedTest extends WP_UnitTestCase
{
    /** Existing REST namespace already used by the patient portal. */
    private const NS = '/clinic/v1';
    private const ME_PATH = self::NS . '/patient/me';
    private const MY_RECORDS_PATH = self::NS . '/patient/my-records';

    /**
     * Paths published in portal config. rest_root already ends in /clinic/v1;
     * these stay relative so apiUrl() does not produce /clinic/v1/clinic/v1/….
     */
    private const PUBLISHED_ME_PATH = '/patient/me';
    private const PUBLISHED_MY_RECORDS_PATH = '/patient/my-records';

    /** Existing portal runtime config class (Slice 1–3). */
    private const CONFIG_CLASS = 'cpms-patient-portal__config';

    // ------------------------------------------------------------------
    // Structural UI markers — chosen to be stable, greppable, RTL/accessibility
    // friendly, and NOT to encode pixel/color/aesthetic details. GREEN chooses
    // the concrete element/tag; RED only requires these semantic hooks.
    // ------------------------------------------------------------------

    /** Navigation item that points to the Profile surface. */
    private const NAV_PROFILE_ROLE = 'nav-profile';

    /** Profile section/landmark root inside <main>. */
    private const PROFILE_SECTION_ROLE = 'profile-section';

    /** Clinic-context label showing which Clinic the displayed profile belongs to. */
    private const PROFILE_CLINIC_LABEL_ROLE = 'profile-clinic-label';

    /** Record selector root (present only when server reports N links). */
    private const PROFILE_SELECTOR_ROLE = 'profile-record-selector';

    /** Selector option data attribute carrying server-provided link_id. */
    private const SELECTOR_OPTION_ROLE = 'profile-record-option';

    /** Empty state for zero links. */
    private const PROFILE_EMPTY_ROLE = 'profile-empty-state';

    /** Profile form root (only present when a record is selected, or auto-select N=1). */
    private const PROFILE_FORM_ROLE = 'profile-form';

    /** Save button. */
    private const PROFILE_SAVE_ROLE = 'profile-save';

    /** Read-only login mobile display (NOT an input). */
    private const PROFILE_LOGIN_MOBILE_ROLE = 'profile-login-mobile';

    /** Success/error alerts (role="alert" regions, Persian content). */
    private const PROFILE_SUCCESS_ROLE = 'profile-success';
    private const PROFILE_ERROR_ROLE = 'profile-error';

    /** ME_EDITABLE field input marker — data-field="<name>" on editable inputs. */
    private const FIELD_ATTR = 'data-field';

    /** Forbidden authority keys anywhere in published portal markup/config/JS. */
    private const FORBIDDEN_AUTHORITY_KEYS = [
        'clinic_id',
        'patient_id',
        'organization_id',
        'role',
        'roles',
    ];

    /** Canonical editable fields per existing PatientService::ME_EDITABLE. */
    private const EXPECTED_EDITABLE_FIELDS = [
        'first_name',
        'last_name',
        'birth_date',
        'gender',
        'address',
        'phone',
        'national_id',
        'emergency_contact_name',
        'emergency_contact_phone',
    ];

    /** Clinical/private fields that must NOT appear in the self-edit form. */
    private const FORBIDDEN_SELF_EDIT_FIELDS = [
        'mobile',
        'medical_history',
        'surgery_history',
        'blood_group',
        'medication_allergies',
        'other_allergies',
        'chronic_conditions',
        'current_medications',
        'mrn',
        'status',
    ];

    private string $fixtureTag = '';
    private ?string $permalinkStructureToRestore = null;
    private string $originalTheme = '';

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
        $this->permalinkStructureToRestore = null;
        $this->originalTheme = (string) get_stylesheet();
    }

    protected function tearDown(): void
    {
        if ($this->permalinkStructureToRestore !== null) {
            $this->set_permalink_structure($this->permalinkStructureToRestore);
            $this->permalinkStructureToRestore = null;
        }
        if ($this->originalTheme !== '' && (string) get_stylesheet() !== $this->originalTheme) {
            switch_theme($this->originalTheme);
        }
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

    // =====================================================================
    // R1 — RED-UI-1: Profile navigation + Profile section on real shell
    // =====================================================================

    public function testPatientPortalExposesProfileNavigationAndProfileSection(): void
    {
        // ---- POSITIVE GUARDS FIRST (prove shell/session/backend are green before RED) ----
        $fx = $this->buildSingleLinkFixture('r1');
        $this->assertShellGuard($fx);
        $this->assertBackendGuardsForSelectedLink($fx, $fx['link_id']);

        $html = $this->renderProductionFrontendPortalAs((int) $fx['user_id']);

        // Existing shell content must remain (Slice 1–3 appointments + notifications).
        $this->assertShellStructurePreserved($html, $fx);

        // ---- INTENDED RED (UI-1): Profile navigation + Profile section do not exist on live main. ----
        $this->assertNavItem($html, self::NAV_PROFILE_ROLE,
            'RED-UI-1: patient navigation must expose a Profile nav item (' . self::NAV_PROFILE_ROLE . ').'
        );

        $sections = $this->markedElements($html, self::PROFILE_SECTION_ROLE);
        self::assertCount(
            1,
            $sections,
            'RED-UI-1: exactly one Profile section/landmark must exist inside the real Patient Portal shell (' . self::PROFILE_SECTION_ROLE . ').'
        );

        // Clinic context label must identify which Clinic's profile is displayed.
        $labels = $this->markedElements($html, self::PROFILE_CLINIC_LABEL_ROLE);
        self::assertCount(
            1,
            $labels,
            'RED-UI-1: Profile section must contain a Clinic-context label (' . self::PROFILE_CLINIC_LABEL_ROLE . ').'
        );
        self::assertStringContainsString(
            (string) $fx['clinic_name'],
            $labels[0]['html'],
            'RED-UI-1: Clinic context label must show the selected Clinic name from server data.'
        );

        // Current profile data must be visibly present (first_name / last_name at minimum).
        $sectionHtml = $sections[0]['html'];
        self::assertStringContainsString(
            (string) $fx['first_name'],
            $sectionHtml,
            'RED-UI-1: Profile section must visibly render current profile first_name.'
        );
        self::assertStringContainsString(
            (string) $fx['last_name'],
            $sectionHtml,
            'RED-UI-1: Profile section must visibly render current profile last_name.'
        );
    }

    // =====================================================================
    // R2 — RED-UI-2: Zero / One / Many record selector
    // =====================================================================

    public function testProfileRecordSelectorUsesOnlyServerLinkedRecordsAndRequiresExplicitChoiceForMultiple(): void
    {
        // ---- POSITIVE GUARDS ----
        // Backend selector contract (already GREEN on this main) is asserted first,
        // so this RED test can only fail for missing UI.
        $twoFx = $this->buildTwoLinkFixture('r2');
        $this->assertShellGuard($twoFx['fx']);

        // Backend: N=2 without link_id ⇒ CLINIC_SELECTION_REQUIRED (422).
        $noSel = $this->dispatchRest('GET', self::ME_PATH, [], (int) $twoFx['fx']['user_id']);
        $this->assertClinicError($noSel, 'CLINIC_SELECTION_REQUIRED', 422,
            'positive control: N>1 requires explicit link_id on backend.'
        );
        // Backend: foreign link_id ⇒ 404 closed.
        $foreignLinkResp = $this->dispatchRest('GET', self::ME_PATH, ['link_id' => $twoFx['foreign_link_id']], (int) $twoFx['fx']['user_id']);
        $this->assertClinicError($foreignLinkResp, 'CLINIC_NOT_FOUND', 404,
            'positive control: foreign link_id fails closed on backend.'
        );
        // Backend: explicit link_id selection works for both A and B.
        $selA = $this->dispatchRest('GET', self::ME_PATH, ['link_id' => $twoFx['link_a']], (int) $twoFx['fx']['user_id']);
        self::assertSame(200, $selA->get_status(), 'positive control: GET /patient/me?link_id=A returns 200.');
        self::assertSame($twoFx['patient_a'], (int) ($selA->get_data()['data']['id'] ?? 0));
        $selB = $this->dispatchRest('GET', self::ME_PATH, ['link_id' => $twoFx['link_b']], (int) $twoFx['fx']['user_id']);
        self::assertSame(200, $selB->get_status(), 'positive control: GET /patient/me?link_id=B returns 200.');
        self::assertSame($twoFx['patient_b'], (int) ($selB->get_data()['data']['id'] ?? 0));

        // Backend my-records returns exactly 2 active links (foreign/inactive absent).
        $recordsResp = $this->dispatchRest('GET', self::MY_RECORDS_PATH, [], (int) $twoFx['fx']['user_id']);
        self::assertSame(200, $recordsResp->get_status(), 'positive control: GET /patient/my-records returns 200.');
        $recordsBody = $recordsResp->get_data();
        self::assertIsArray($recordsBody);
        self::assertCount(2, (array) $recordsBody['data'], 'positive control: my-records lists exactly 2 active linked records.');

        // Backend: zero-link patient is 404 on /patient/me, my-records empty list.
        $zeroFx = $this->buildZeroLinkFixture('r2-zero');
        $this->assertShellGuard($zeroFx);
        $zeroMe = $this->dispatchRest('GET', self::ME_PATH, [], (int) $zeroFx['user_id']);
        $this->assertClinicError($zeroMe, 'CLINIC_NOT_FOUND', 404,
            'positive control: zero-link patient is CLINIC_NOT_FOUND on /patient/me.'
        );
        $zeroRecords = $this->dispatchRest('GET', self::MY_RECORDS_PATH, [], (int) $zeroFx['user_id']);
        self::assertSame(200, $zeroRecords->get_status());
        self::assertSame([], (array) $zeroRecords->get_data()['data'],
            'positive control: my-records is an empty list for zero-link patients.'
        );

        // Backend: single active link auto-selects (no CLINIC_SELECTION_REQUIRED).
        $singleFx = $this->buildSingleLinkFixture('r2-single');
        $this->assertShellGuard($singleFx);
        $singleMe = $this->dispatchRest('GET', self::ME_PATH, [], (int) $singleFx['user_id']);
        self::assertSame(200, $singleMe->get_status(),
            'positive control: single active link auto-selects on backend.'
        );

        // ---- INTENDED RED (UI-2): profile record selector UI is absent on live main. ----

        // Case A: 0 links ⇒ safe empty state, NO edit form, NO association-creation action.
        $html0 = $this->renderProductionFrontendPortalAs((int) $zeroFx['user_id']);
        $empty0 = $this->markedElements($html0, self::PROFILE_EMPTY_ROLE);
        self::assertCount(
            1,
            $empty0,
            'RED-UI-2 (0 links): a safe empty state must be rendered (' . self::PROFILE_EMPTY_ROLE . ').'
        );
        self::assertCount(
            0,
            $this->markedElements($html0, self::PROFILE_FORM_ROLE),
            'RED-UI-2 (0 links): no edit form may be rendered when there are no links.'
        );
        self::assertCount(
            0,
            $this->markedElements($html0, 'profile-create-link'),
            'RED-UI-2 (0 links): no association-creation action may be exposed; users contact the clinic.'
        );
        // Empty state must not leak PHI of another patient and must not reference raw IDs.
        $this->assertNoAuthorityInHtml($html0, 'RED-UI-2 (0 links) empty-state markup');

        // Case B: 1 active linked record ⇒ automatically selected, Profile shown, no selector options exposed.
        $html1 = $this->renderProductionFrontendPortalAs((int) $singleFx['user_id']);
        // With exactly one link, profile should render without forcing an explicit pick.
        $sections1 = $this->markedElements($html1, self::PROFILE_SECTION_ROLE);
        self::assertCount(1, $sections1,
            'RED-UI-2 (1 link): Profile section must be shown for the auto-selected record.'
        );
        // Selector MAY render in read-only/disabled form showing the single clinic,
        // but must not allow arbitrary clinic_id/patient_id entry.
        $selector1 = $this->markedElements($html1, self::PROFILE_SELECTOR_ROLE);
        foreach ($selector1 as $sel) {
            self::assertStringNotContainsString('name="clinic_id"', $sel['html'],
                'RED-UI-2 (1 link): selector must not expose raw clinic_id authority.'
            );
            self::assertStringNotContainsString('name="patient_id"', $sel['html'],
                'RED-UI-2 (1 link): selector must not expose raw patient_id authority.'
            );
        }

        // Case C: N>1 linked records ⇒ selector lists ONLY server my-records, clinic labels visible,
        // NO automatic primary/first selection; explicit selection required before editable profile.
        $htmlN = $this->renderProductionFrontendPortalAs((int) $twoFx['fx']['user_id']);
        $selectors = $this->markedElements($htmlN, self::PROFILE_SELECTOR_ROLE);
        self::assertCount(
            1,
            $selectors,
            'RED-UI-2 (N>1): a record selector must be rendered (' . self::PROFILE_SELECTOR_ROLE . ').'
        );
        $options = $this->markedElements($htmlN, self::SELECTOR_OPTION_ROLE);
        self::assertCount(
            2,
            $options,
            'RED-UI-2 (N>1): selector must list exactly the two records returned by my-records '
            . '(foreign/inactive records must NOT appear).'
        );
        $linkIdsInOptions = [];
        $clinicNamesSeen = [];
        foreach ($options as $opt) {
            $lid = $this->attributeValue($opt['tag'], 'data-link-id');
            self::assertNotNull($lid, 'RED-UI-2 (N>1): every selector option must carry data-link-id from server my-records.');
            $linkIdsInOptions[] = (int) $lid;
            self::assertStringNotContainsString('data-clinic-id=', $opt['tag'],
                'RED-UI-2: selector must NOT publish raw clinic_id as client authority.'
            );
            self::assertStringNotContainsString('data-patient-id=', $opt['tag'],
                'RED-UI-2: selector must NOT publish raw patient_id as client authority.'
            );
            // Clinic label must be visible on each option (clinic_name from server).
            $optText = wp_strip_all_tags($opt['html']);
            $seenClinic = false;
            foreach ([$twoFx['clinic_a_name'], $twoFx['clinic_b_name']] as $cName) {
                if (str_contains($optText, (string) $cName)) {
                    $seenClinic = true;
                    $clinicNamesSeen[] = $cName;
                }
            }
            self::assertTrue($seenClinic,
                'RED-UI-2 (N>1): every selector option must visibly show its Clinic name from server my-records.'
            );
        }
        sort($linkIdsInOptions);
        self::assertSame(
            [ (int) $twoFx['link_a'], (int) $twoFx['link_b'] ],
            $linkIdsInOptions,
            'RED-UI-2 (N>1): selector options must be exactly the server-returned link_ids (not a client-invented set).'
        );
        self::assertTrue(
            in_array($twoFx['clinic_a_name'], $clinicNamesSeen, true)
            && in_array($twoFx['clinic_b_name'], $clinicNamesSeen, true),
            'RED-UI-2 (N>1): both Clinic labels must be visible in the selector.'
        );

        // N>1 WITHOUT explicit selection ⇒ no editable profile form rendered.
        // (Server-side CLINIC_SELECTION_REQUIRED semantics must be reflected in UI.)
        self::assertCount(
            0,
            $this->markedElements($htmlN, self::PROFILE_FORM_ROLE),
            'RED-UI-2 (N>1): editable Profile form must NOT render before explicit record selection '
            . '(no automatic primary/first fallback).'
        );

        // is_primary must NOT be treated as authority: primary marker (link A) must not
        // pre-select or auto-submit; selector must not have a 'data-selected-primary' signal.
        $selectorHtml = $selectors[0]['html'];
        self::assertStringNotContainsString('data-auto-selected="true"', $selectorHtml,
            'RED-UI-2 (N>1): must not auto-select the primary record.'
        );

        // Foreign / inactive link_ids must NOT appear as options.
        foreach ($options as $opt) {
            $lid = (int) $this->attributeValue($opt['tag'], 'data-link-id');
            self::assertNotSame($twoFx['foreign_link_id'], $lid,
                'RED-UI-2: foreign-user links must not appear in selector.'
            );
            self::assertNotSame($twoFx['inactive_link_id'], $lid,
                'RED-UI-2: archived/inactive patient links must not appear in selector.'
            );
        }
    }

    // =====================================================================
    // R3 — RED-UI-3: Field / Mobile read-only / National-ID contract
    // =====================================================================

    public function testProfileFormExposesAllowedFieldsAndKeepsLoginMobileReadOnly(): void
    {
        // ---- POSITIVE GUARDS ----
        $fx = $this->buildSingleLinkFixture('r3');
        $this->assertShellGuard($fx);
        $this->assertBackendGuardsForSelectedLink($fx, $fx['link_id']);

        // Backend: login mobile is non-editable (rejected by ME_EDITABLE whitelist) even
        // when sent alongside an otherwise-valid self-edit. Send a real ME_EDITABLE field
        // (first_name) so the request is not an empty update, plus a bogus mobile. Expect
        // 200 for the legitimate field and NO mobile mutation.
        $r3EditName = 'GuardMobileReject_' . $this->fixtureTag;
        $mobileReject = $this->dispatchRest('PUT', self::ME_PATH, [
            'link_id' => $fx['link_id'],
            'first_name' => $r3EditName,
            'mobile' => '09999999999',
        ], (int) $fx['user_id']);
        self::assertSame(200, $mobileReject->get_status(),
            'positive control: a valid self-edit that also sends mobile must still return 200 (mobile is not in ME_EDITABLE and is silently ignored).'
        );
        $afterMobileReject = $this->patientRow((int) $fx['patient_id']);
        self::assertSame($r3EditName, (string) $afterMobileReject['first_name'],
            'positive control: legitimate ME_EDITABLE field is applied while mobile is ignored.'
        );
        self::assertSame($fx['mobile'], (string) $afterMobileReject['mobile'],
            'positive control: login/authentication mobile remains EXACTLY unchanged when sent in the same payload as a valid edit.'
        );

        self::assertSame(
            self::EXPECTED_EDITABLE_FIELDS,
            PatientService::ME_EDITABLE,
            'positive control: PatientService::ME_EDITABLE whitelist is exactly the approved list.'
        );

        // ---- INTENDED RED (UI-3): profile form is absent on live main. ----
        $html = $this->renderProductionFrontendPortalAs((int) $fx['user_id']);

        $forms = $this->markedElements($html, self::PROFILE_FORM_ROLE);
        self::assertCount(1, $forms,
            'RED-UI-3: exactly one Profile edit form must exist (' . self::PROFILE_FORM_ROLE . ').'
        );
        $formHtml = $forms[0]['html'];

        // All ME_EDITABLE fields must be rendered as editable inputs with data-field="<name>".
        foreach (self::EXPECTED_EDITABLE_FIELDS as $field) {
            $inputs = $this->markedElements($formHtml, 'profile-field-' . $field);
            // Accept either dedicated role markers OR generic data-field="<field>" inputs.
            $generic = $this->fieldInputs($formHtml, $field);
            self::assertTrue(
                count($inputs) + count($generic) >= 1,
                'RED-UI-3: editable field "' . $field . '" must be present in the Profile form '
                . '(either data-role="profile-field-' . $field . '" or an input/select/textarea with '
                . self::FIELD_ATTR . '="' . $field . '").'
            );
        }

        // Login/auth mobile must be visibly presented but NOT as an editable input.
        $mobileDisplay = $this->markedElements($formHtml, self::PROFILE_LOGIN_MOBILE_ROLE);
        self::assertCount(1, $mobileDisplay,
            'RED-UI-3: login/authentication mobile must be visibly presented in read-only form ('
            . self::PROFILE_LOGIN_MOBILE_ROLE . ').'
        );
        self::assertStringContainsString(
            $fx['mobile'],
            $mobileDisplay[0]['html'],
            'RED-UI-3: login mobile display must show the actual linked mobile number.'
        );
        // Must NOT be an <input>/<select>/<textarea> carrying name=mobile or data-field=mobile.
        $mobileInputRe = "/<(input|select|textarea)\\b[^>]*\\b(name|" . preg_quote(self::FIELD_ATTR, '/') . ")=[\"']mobile[\"']/i";
        self::assertDoesNotMatchRegularExpression(
            $mobileInputRe,
            $mobileDisplay[0]['html'],
            'RED-UI-3: login mobile must NOT be rendered as an editable input (read-only presentation only).'
        );
        $mobileInputsInForm = $this->fieldInputs($formHtml, 'mobile');
        self::assertCount(0, $mobileInputsInForm,
            'RED-UI-3: Profile form must not contain any editable mobile input.'
        );

        // National ID must be visible, editable, and clearly part of Profile (data-field=national_id),
        // but not marked as booking-required.
        $nidInputs = $this->fieldInputs($formHtml, 'national_id');
        self::assertTrue(count($nidInputs) >= 1,
            'RED-UI-3: national_id must be an editable field in the Profile form.'
        );
        foreach ($nidInputs as $inp) {
            self::assertStringNotContainsString('required="required"', strtolower($inp['tag']),
                'RED-UI-3: National ID must NOT be marked required at a booking-required level.'
            );
            self::assertStringNotContainsString('aria-required="true"', strtolower($inp['tag']),
                'RED-UI-3: National ID must NOT be marked aria-required.'
            );
            self::assertStringNotContainsString('data-booking-required', $inp['tag'],
                'RED-UI-3: National ID must not be tagged as a booking requirement.'
            );
        }

        // No clinical/private fields may appear in the self-edit form.
        foreach (self::FORBIDDEN_SELF_EDIT_FIELDS as $forbidden) {
            // mobile is checked above separately (display-OK, input-NOT). For other forbidden fields,
            // neither display nor input of those names may appear in the self-edit form.
            if ($forbidden === 'mobile') {
                continue;
            }
            $forbiddenInputs = $this->fieldInputs($formHtml, $forbidden);
            self::assertCount(0, $forbiddenInputs,
                'RED-UI-3: clinical/private field "' . $forbidden . '" must NOT appear in the self-edit form.'
            );
        }

        // Form must not expose raw authority anywhere.
        $this->assertNoAuthorityInHtml($formHtml, 'RED-UI-3 profile form markup');
    }

    // =====================================================================
    // R4 — RED-UI-4: Real save wiring (selected link, nonce, success/error)
    // =====================================================================

    public function testProfileSaveUsesSelectedLinkAndCanonicalBackendErrors(): void
    {
        // ---- POSITIVE GUARDS ----
        $fx = $this->buildSingleLinkFixture('r4');
        $this->assertShellGuard($fx);
        $this->assertBackendGuardsForSelectedLink($fx, $fx['link_id']);

        // Backend: valid national-id update; duplicate national-id canonical failure.
        self::assertTrue(NationalIdValidator::isValid('0000000140'), 'positive control: valid national ID.');
        self::assertTrue(NationalIdValidator::isValid('0000000061'), 'positive control: valid national ID (duplicate).');
        $dupClinicId = (int) $fx['clinic_id'];
        // Insert another Patient in the same Clinic with national_id 0000000061.
        $dupPatient = $this->insertPatient(
            $dupClinicId,
            'MR-P9S4-R4-DUP-' . $this->fixtureTag,
            'Duplicate',
            'Bearer',
            $this->mobileFor('r4-dup'),
            'active',
            '0000000061'
        );
        // Duplicate National ID must be canonically rejected (selected patient starts with NULL).
        $dupEdit = $this->dispatchRest('PUT', self::ME_PATH, [
            'link_id' => $fx['link_id'],
            'national_id' => '0000000061',
        ], (int) $fx['user_id']);
        $this->assertClinicError($dupEdit, 'CLINIC_VALIDATION_FAILED', 400,
            'positive control: duplicate National ID returns canonical CLINIC_VALIDATION_FAILED on backend.'
        );
        $dupMsg = (string) ($dupEdit->get_data()['message'] ?? '');
        self::assertNotSame('', $dupMsg, 'positive control: canonical error carries a Persian message.');

        // A valid, unique National ID update succeeds.
        $validEdit = $this->dispatchRest('PUT', self::ME_PATH, [
            'link_id' => $fx['link_id'],
            'national_id' => '0000000140',
        ], (int) $fx['user_id']);
        self::assertSame(200, $validEdit->get_status(),
            'positive control: valid unique National ID update succeeds via PUT /patient/me.'
        );

        // ---- INTENDED RED (UI-4): save wiring is absent on live main. ----
        $html = $this->renderProductionFrontendPortalAs((int) $fx['user_id']);

        // Save button must exist.
        $saveButtons = $this->markedElements($html, self::PROFILE_SAVE_ROLE);
        self::assertCount(1, $saveButtons,
            'RED-UI-4: exactly one Profile save button must exist (' . self::PROFILE_SAVE_ROLE . ').'
        );
        $saveTag = strtolower($saveButtons[0]['tag']);
        self::assertTrue(
            str_contains($saveTag, '<button') || str_contains($saveTag, '<input'),
            'RED-UI-4: save control must be a real submit/button element.'
        );

        // Save button must be bound to the form (type=submit or form= attribute) but NOT issue a
        // destructive full-page navigation; we require a marker that JS intercepts submission
        // (e.g., data-role profile-form already wraps inputs, and the JS save handler reads
        // the runtime config including nonce). The form must declare the REST save path in
        // config/published data, not hardcode clinic_id/patient_id.

        // Success / error alert regions must exist (role="alert") for Persian feedback.
        $successRegions = $this->markedElements($html, self::PROFILE_SUCCESS_ROLE);
        self::assertCount(1, $successRegions,
            'RED-UI-4: an accessible success alert region must exist (' . self::PROFILE_SUCCESS_ROLE . ', role="alert").'
        );
        self::assertTrue(
            str_contains($successRegions[0]['tag'], 'role="alert"') || str_contains($successRegions[0]['tag'], "role='alert'"),
            'RED-UI-4: success region must carry role="alert" for accessibility.'
        );
        $errorRegions = $this->markedElements($html, self::PROFILE_ERROR_ROLE);
        self::assertCount(1, $errorRegions,
            'RED-UI-4: an accessible error alert region must exist (' . self::PROFILE_ERROR_ROLE . ', role="alert").'
        );
        self::assertTrue(
            str_contains($errorRegions[0]['tag'], 'role="alert"') || str_contains($errorRegions[0]['tag'], "role='alert'"),
            'RED-UI-4: error region must carry role="alert" for accessibility.'
        );

        // Runtime config must publish profile save endpoint + record list endpoint + nonce
        // (wp_rest), so the small vanilla JS can save without hardcoding URLs.
        $configs = $this->configScriptPayloads($html);
        self::assertCount(1, $configs,
            'RED-UI-4: portal runtime config must be published (single JSON script block).'
        );
        $config = json_decode(trim($configs[0]), true);
        self::assertIsArray($config, 'RED-UI-4: portal config must be JSON.');
        self::assertArrayHasKey('nonce', $config, 'RED-UI-4: wp_rest nonce must remain published.');
        self::assertNotFalse(wp_verify_nonce((string) $config['nonce'], 'wp_rest'),
            'RED-UI-4: published nonce must verify as wp_rest.'
        );
        self::assertArrayHasKey('profile_me_path', $config,
            'RED-UI-4: portal config must publish profile_me_path for PUT /patient/me save.'
        );
        self::assertArrayHasKey('profile_my_records_path', $config,
            'RED-UI-4: portal config must publish profile_my_records_path for GET /patient/my-records selector.'
        );
        self::assertSame(
            self::PUBLISHED_ME_PATH,
            (string) $config['profile_me_path'],
            'RED-UI-4: profile_me_path must be the relative /patient/me path; rest_root already ends in /clinic/v1.'
        );
        self::assertSame(
            self::PUBLISHED_MY_RECORDS_PATH,
            (string) $config['profile_my_records_path'],
            'RED-UI-4: profile_my_records_path must be the relative /patient/my-records path.'
        );
        self::assertStringStartsNotWith(
            self::NS,
            (string) $config['profile_me_path'],
            'RED-UI-4: profile_me_path must not repeat the /clinic/v1 namespace prefix.'
        );
        self::assertStringStartsNotWith(
            self::NS,
            (string) $config['profile_my_records_path'],
            'RED-UI-4: profile_my_records_path must not repeat the /clinic/v1 namespace prefix.'
        );
        $restRoot = (string) ($config['rest_root'] ?? '');
        self::assertNotSame('', $restRoot, 'RED-UI-4: rest_root must be published.');
        self::assertStringEndsWith(
            '/clinic/v1',
            $restRoot,
            'RED-UI-4: rest_root must end in /clinic/v1.'
        );
        self::assertSame(
            1,
            substr_count($restRoot, '/clinic/v1'),
            'RED-UI-4: rest_root must contain /clinic/v1 exactly once.'
        );
        $joinedMe = $this->portalApiUrl($restRoot, (string) $config['profile_me_path']);
        $joinedRecords = $this->portalApiUrl($restRoot, (string) $config['profile_my_records_path']);
        self::assertStringEndsWith(
            '/clinic/v1/patient/me',
            $joinedMe,
            'RED-UI-4: rest_root + profile_me_path must be the canonical /clinic/v1/patient/me endpoint exactly once.'
        );
        self::assertStringEndsWith(
            '/clinic/v1/patient/my-records',
            $joinedRecords,
            'RED-UI-4: rest_root + profile_my_records_path must be the canonical /clinic/v1/patient/my-records endpoint exactly once.'
        );
        self::assertSame(1, substr_count($joinedMe, '/clinic/v1'),
            'RED-UI-4: joined profile_me_path must not duplicate /clinic/v1.');
        self::assertSame(1, substr_count($joinedRecords, '/clinic/v1'),
            'RED-UI-4: joined profile_my_records_path must not duplicate /clinic/v1.');
        self::assertStringNotContainsString('/clinic/v1/clinic/v1', $joinedMe);
        self::assertStringNotContainsString('/clinic/v1/clinic/v1', $joinedRecords);
        // Same combiner as assets/js apiUrl(): Plain (?rest_route=) rewrites the
        // path query to &, Pretty (no ?) concatenates. Absolute input is stripped
        // so a stale /clinic/v1 prefix cannot double the namespace.
        $plainRoot = 'http://example.org/index.php?rest_route=/clinic/v1';
        $prettyRoot = 'https://example.org/rest/clinic/v1';
        self::assertSame(
            'http://example.org/index.php?rest_route=/clinic/v1/patient/me',
            $this->portalApiUrl($plainRoot, self::PUBLISHED_ME_PATH)
        );
        self::assertSame(
            'http://example.org/index.php?rest_route=/clinic/v1/patient/me&link_id=1',
            $this->portalApiUrl($plainRoot, self::PUBLISHED_ME_PATH . '?link_id=1')
        );
        self::assertSame(
            'https://example.org/rest/clinic/v1/patient/my-records',
            $this->portalApiUrl($prettyRoot, self::PUBLISHED_MY_RECORDS_PATH)
        );
        self::assertSame(
            'https://example.org/rest/clinic/v1/patient/me?link_id=1',
            $this->portalApiUrl($prettyRoot, self::PUBLISHED_ME_PATH . '?link_id=1')
        );
        self::assertSame(
            'https://example.org/rest/clinic/v1/patient/me',
            $this->portalApiUrl($prettyRoot, self::ME_PATH),
            'RED-UI-4: the established combiner must strip a duplicated /clinic/v1 prefix.'
        );
        self::assertSame(1, substr_count($this->portalApiUrl($plainRoot, self::ME_PATH), '/clinic/v1'));
        $this->assertNoAuthorityInHtml(wp_json_encode($config), 'RED-UI-4 runtime config');

        // The save payload contract MUST be described in the JS/markup such that the save:
        //  - uses POST/PUT (EDITABLE method) to profile_me_path;
        //  - sends selected link_id (read from the selector state);
        //  - sends only the ME_EDITABLE fields (never mobile/clinical fields);
        //  - includes X-WP-Nonce header from config.nonce (wp_rest);
        //  - does NOT send raw clinic_id/patient_id/organization_id/role;
        //  - on success, renders Persian success into the success region WITHOUT page-wide
        //    destructive navigation (no location.replace to /wp-admin/, no outer <form action>
        //    that does a full-page POST to admin-post.php etc).
        // We assert structural/declarative markers rather than simulating clicks, because a
        // JS-interpreter DOM isn't available at unit-test level — but the contract must be
        // discoverable by the small vanilla JS module shipped on the portal page.

        // The form/button must not hardcode a traditional full-page POST action (no native
        // <form action="...admin-post.php"> / method=POST without JS intercept) — we assert
        // the button carries a data-action marker that JS binds to, and not an action URL.
        self::assertStringNotContainsString(
            'admin-post.php',
            $html,
            'RED-UI-4: Profile save must NOT go through admin-post.php full-page POST; use REST + wp_rest nonce.'
        );
        // Save button must declare a JS hook (data-action or type=button inside form) rather than
        // a raw submit to a non-REST URL.
        $btnTag = $saveButtons[0]['tag'];
        $hasDataAction = str_contains($btnTag, 'data-action=') || str_contains($btnTag, 'data-save=');
        $formAround = $this->nearestFormAncestor($html, self::PROFILE_SAVE_ROLE);
        if ($formAround !== null) {
            // If inside a <form>, it must not have a non-REST action that causes full-nav submit.
            $action = $this->attributeValue($formAround, 'action') ?? '';
            if ($action !== '') {
                self::assertStringNotContainsString('/wp-admin/', $action,
                    'RED-UI-4: Profile form action must not POST to wp-admin.'
                );
                self::assertStringNotContainsString('admin-post.php', $action,
                    'RED-UI-4: Profile form must not POST to admin-post.php.'
                );
            }
            // Prefer declarative no-op form (action="" or no action) with JS-intercepted submit.
        } else {
            self::assertTrue($hasDataAction,
                'RED-UI-4: save button must publish a data-action/data-save hook for the vanilla-JS save handler.'
            );
        }

        // No page-wide destructive behaviour marker — absence of inline onunload/window.location.
        self::assertStringNotContainsString('window.location.replace', $html,
            'RED-UI-4: save wiring must not perform page-wide replacement navigation.'
        );
    }

    // =====================================================================
    // POSITIVE GUARDS — must stay green on current main and after GREEN
    // =====================================================================

    /**
     * G1 — existing Slice 3 shell still renders independently (no regression from adding Profile).
     */
    public function testGuardExistingShellStillRendersWithAppointmentsAndNotifications(): void
    {
        $fx = $this->buildSingleLinkFixture('g1');
        $html = $this->renderProductionFrontendPortalAs((int) $fx['user_id']);
        $this->assertShellStructurePreserved($html, $fx);
    }

    /**
     * G2 — backend my-records is green for one-link / two-link / zero-link.
     */
    public function testGuardBackendMyRecordsContractIsGreen(): void
    {
        $single = $this->buildSingleLinkFixture('g2a');
        $resp1 = $this->dispatchRest('GET', self::MY_RECORDS_PATH, [], (int) $single['user_id']);
        self::assertSame(200, $resp1->get_status());
        $data1 = (array) $resp1->get_data()['data'];
        self::assertCount(1, $data1);
        self::assertSame((int) $single['link_id'], (int) $data1[0]['link_id']);

        $two = $this->buildTwoLinkFixture('g2b');
        $resp2 = $this->dispatchRest('GET', self::MY_RECORDS_PATH, [], (int) $two['fx']['user_id']);
        self::assertSame(200, $resp2->get_status());
        $data2 = (array) $resp2->get_data()['data'];
        self::assertCount(2, $data2);
        $ids = array_map(static fn (array $r): int => (int) $r['link_id'], $data2);
        sort($ids);
        self::assertSame([(int) $two['link_a'], (int) $two['link_b']], $ids);

        $zero = $this->buildZeroLinkFixture('g2c');
        $resp0 = $this->dispatchRest('GET', self::MY_RECORDS_PATH, [], (int) $zero['user_id']);
        self::assertSame(200, $resp0->get_status());
        self::assertSame([], (array) $resp0->get_data()['data']);
    }

    /**
     * G3 — selected GET/PUT /patient/me is green (single + explicit link_id).
     */
    public function testGuardBackendMeReadEditIsGreen(): void
    {
        $fx = $this->buildSingleLinkFixture('g3');
        $resp = $this->dispatchRest('GET', self::ME_PATH, [], (int) $fx['user_id']);
        self::assertSame(200, $resp->get_status());
        self::assertSame((int) $fx['patient_id'], (int) ($resp->get_data()['data']['id'] ?? 0));

        $put = $this->dispatchRest('PUT', self::ME_PATH, [
            'link_id' => $fx['link_id'],
            'first_name' => 'GuardEdited',
        ], (int) $fx['user_id']);
        self::assertSame(200, $put->get_status());
        self::assertSame('GuardEdited', (string) ($put->get_data()['data']['first_name'] ?? ''));
        $row = $this->patientRow((int) $fx['patient_id']);
        self::assertSame('GuardEdited', (string) $row['first_name']);
    }

    /**
     * G4 — N>1 requires selection; foreign selector fails closed; login mobile immutable;
     * valid national ID works; duplicate national ID fails canonically.
     */
    public function testGuardBackendSecurityAndValidationIsGreen(): void
    {
        $two = $this->buildTwoLinkFixture('g4');
        $noSel = $this->dispatchRest('GET', self::ME_PATH, [], (int) $two['fx']['user_id']);
        $this->assertClinicError($noSel, 'CLINIC_SELECTION_REQUIRED', 422,
            'N>1 must return CLINIC_SELECTION_REQUIRED 422.'
        );

        $foreign = $this->dispatchRest('GET', self::ME_PATH, ['link_id' => $two['foreign_link_id']], (int) $two['fx']['user_id']);
        $this->assertClinicError($foreign, 'CLINIC_NOT_FOUND', 404,
            'Foreign link_id must fail closed (404).'
        );

        $fx = $this->buildSingleLinkFixture('g4s');
        $mobileBefore = (string) $this->patientRow((int) $fx['patient_id'])['mobile'];
        self::assertNotSame('', $mobileBefore, 'fixture guard: patient mobile row is present before guard dispatch.');
        $g4EditName = 'GuardG4Mob_' . $this->fixtureTag;
        $mob = $this->dispatchRest('PUT', self::ME_PATH, [
            'link_id' => $fx['link_id'],
            'first_name' => $g4EditName,
            'mobile' => '09999999999',
        ], (int) $fx['user_id']);
        self::assertSame(200, $mob->get_status(),
            'positive control: valid self-edit with extraneous mobile returns 200.'
        );
        $after = $this->patientRow((int) $fx['patient_id']);
        self::assertSame($g4EditName, (string) $after['first_name'],
            'positive control: legitimate ME_EDITABLE field applied.'
        );
        self::assertSame($mobileBefore, (string) $after['mobile'],
            'Login mobile must remain immutable via self-service even when sent alongside a valid edit.'
        );

        self::assertTrue(NationalIdValidator::isValid('0000000140'));

        // Insert another patient holding national_id 0000000061 first, then duplicate must fail.
        $dupPatient = $this->insertPatient(
            (int) $fx['clinic_id'],
            'MR-P9S4-G4DUP-' . $this->fixtureTag,
            'Dup',
            'Patient',
            $this->mobileFor('g4-dup'),
            'active',
            '0000000061'
        );
        self::assertGreaterThan(0, $dupPatient);
        $dup = $this->dispatchRest('PUT', self::ME_PATH, [
            'link_id' => $fx['link_id'],
            'national_id' => '0000000061',
        ], (int) $fx['user_id']);
        $this->assertClinicError($dup, 'CLINIC_VALIDATION_FAILED', 400,
            'Duplicate National ID must be canonical CLINIC_VALIDATION_FAILED.'
        );

        // A unique valid national ID (0000000140) must succeed.
        $ok = $this->dispatchRest('PUT', self::ME_PATH, [
            'link_id' => $fx['link_id'],
            'national_id' => '0000000140',
        ], (int) $fx['user_id']);
        self::assertSame(200, $ok->get_status());
        self::assertSame('0000000140', (string) $this->patientRow((int) $fx['patient_id'])['national_id']);
    }

    /**
     * G5 — ME_EDITABLE whitelist contract is unchanged (no widening via UI work).
     */
    public function testGuardMeEditableWhitelistIsUnchanged(): void
    {
        self::assertSame(self::EXPECTED_EDITABLE_FIELDS, PatientService::ME_EDITABLE,
            'ME_EDITABLE must remain exactly the approved whitelist; UI work must not widen it.'
        );
    }

    // =====================================================================
    // Shell / backend guard helpers (prove the existing portal is healthy
    // before any RED-UI failure is allowed to be attributed to missing UI).
    // =====================================================================

    /**
     * @param array<string, mixed> $fx
     */
    private function assertShellGuard(array $fx): void
    {
        $user = get_userdata((int) $fx['user_id']);
        self::assertNotFalse($user, 'shell guard: WP user exists.');
        self::assertTrue(
            PatientPortalPage::isPatientOnly((int) $fx['user_id']),
            'shell guard: user is pure patient.'
        );
        // is_portal_request() is only meaningful during a frontend request (go_to() in render).
        $url = PatientPortalShell::portal_url();
        self::assertStringNotContainsString('/wp-admin/', $url, 'shell guard: portal URL is frontend (Slice 3 GREEN).');

        // Backend reachability is proven by the positive guard REST dispatches that run
        // before any RED-UI assertion; the route list itself is not re-queried here to
        // avoid coupling to rest_get_server() init order.
    }

    /**
     * @param array<string, mixed> $fx
     */
    private function assertBackendGuardsForSelectedLink(array $fx, int $linkId): void
    {
        $uid = (int) $fx['user_id'];
        $me = $this->dispatchRest('GET', self::ME_PATH, ['link_id' => $linkId], $uid);
        self::assertSame(200, $me->get_status(),
            'backend guard: GET /patient/me?link_id= returns 200.'
        );
        $body = $me->get_data();
        self::assertIsArray($body);
        self::assertSame((int) $fx['patient_id'], (int) ($body['data']['id'] ?? 0),
            'backend guard: selected patient resolves to the linked patient.'
        );

        $edit = $this->dispatchRest('PUT', self::ME_PATH, [
            'link_id' => $linkId,
            'first_name' => (string) $fx['first_name'],
        ], $uid);
        self::assertSame(200, $edit->get_status(),
            'backend guard: PUT /patient/me?link_id= returns 200.'
        );
    }

    /**
     * @param array<string, mixed> $fx
     */
    private function assertShellStructurePreserved(string $html, array $fx): void
    {
        // Independent CPMS shell still owns the document.
        self::assertTrue(
            str_starts_with(ltrim($html), '<!DOCTYPE html>') || str_starts_with(strtolower(ltrim($html)), '<!doctype html>'),
            'shell guard: full CPMS document (Slice 3).'
        );
        self::assertTrue(
            str_contains($html, 'data-cpms-patient-portal="shell"')
            || str_contains($html, 'id="cpms-patient-portal-shell"'),
            'shell guard: shell root marker present.'
        );
        // RTL.
        self::assertTrue(
            (bool) preg_match("/\\bdir=[\"']rtl[\"']/i", $html),
            'shell guard: document is RTL.'
        );
        // Existing landmarks still present.
        self::assertTrue((bool) preg_match('/<header\b/i', $html) || str_contains($html, 'role="banner"'),
            'shell guard: header landmark preserved.'
        );
        self::assertTrue(
            (bool) preg_match('/<nav\b/i', $html)
            || str_contains($html, 'role="navigation"')
            || str_contains($html, 'data-role="patient-nav"'),
            'shell guard: patient nav landmark preserved.'
        );
        self::assertTrue((bool) preg_match('/<main\b/i', $html) || str_contains($html, 'role="main"'),
            'shell guard: main landmark preserved.'
        );
        // Existing Slice 1/2 content still present (appointments + notifications).
        self::assertStringContainsString('نوبت‌های من', $html,
            'shell guard: existing appointments content preserved.'
        );
        self::assertTrue(
            str_contains($html, 'data-role="notifications-section"')
            || str_contains($html, 'اعلان‌ها'),
            'shell guard: existing notifications section preserved.'
        );
        // Existing wp_rest nonce still published.
        $cfgs = $this->configScriptPayloads($html);
        self::assertCount(1, $cfgs, 'shell guard: portal runtime config still published.');
        $config = json_decode(trim($cfgs[0]), true);
        self::assertIsArray($config);
        self::assertArrayHasKey('nonce', $config, 'shell guard: nonce still published.');
        self::assertNotFalse(wp_verify_nonce((string) $config['nonce'], 'wp_rest'),
            'shell guard: nonce verifies as wp_rest.'
        );
        $this->assertNoAuthorityInHtml(wp_json_encode($config), 'shell guard: config');
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * @return array{clinic_id:int, clinic_name:string, location_id:int, clinician_id:int,
     *              user_id:int, patient_id:int, link_id:int, mobile:string,
     *              first_name:string, last_name:string}
     */
    private function buildSingleLinkFixture(string $tag): array
    {
        // Use the first/seeded Clinic (id lowest) as the fixture Clinic. In the
        // WP Test Suite the seed Clinic is id=1; additional clinics added by
        // multi-link tests are strictly higher and filtered out here.
        $clinic_id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_clinics') . ' ORDER BY id ASC LIMIT 1',
            []
        );
        self::assertGreaterThan(0, $clinic_id, 'fixture precondition: at least one Clinic is seeded.');

        $clinic_name = (string) App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [ $clinic_id ]
        );

        $location_id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1 ORDER BY id ASC LIMIT 1',
            [ $clinic_id ]
        );
        self::assertGreaterThan(0, $location_id, 'fixture precondition: seeded Clinic must have a primary Location.');

        $now = App::db()->nowUtcSql();
        $clinician_id = $this->insertRow('cpms_clinicians', [
            'clinic_id' => $clinic_id,
            'full_name' => 'Dr P9S4UI ' . $tag,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%d', '%s', '%s'], 'clinician');

        $mobile = $this->mobileFor($tag);
        $first_name = 'FirstName_' . $tag;
        $last_name = 'LastName_' . $tag;
        $patient_id = $this->insertPatient($clinic_id,
            'MR-P9S4UI-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
            $first_name,
            $last_name,
            $mobile
        );

        $user_id = (int) wp_create_user(
            'p9s4ui_' . $tag . '_' . uniqid('', false),
            'pass-not-used-123',
            uniqid('p9s4ui_' . $tag . '_', true) . '@test.local'
        );
        self::assertGreaterThan(0, $user_id);
        $u = get_userdata($user_id);
        self::assertNotFalse($u);
        $u->set_role(RolesAndCapabilities::ROLE_PATIENT);

        $link_id = $this->insertRow('cpms_patient_user_links', [
            'clinic_id' => $clinic_id,
            'patient_id' => $patient_id,
            'wp_user_id' => $user_id,
            'mobile_at_link' => $mobile,
            'is_primary' => 1,
            'linked_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%d', '%s'], 'patient_user_link');

        // One future confirmed appointment so Slice 1/2 existing content renders inside the shell.
        $slot_date = $this->ymdDaysOffset(5);
        $slot_time = '10:00:00';
        $slot_id = $this->insertSlot($clinic_id, $location_id, $clinician_id, $slot_date, $slot_time, 1);
        $this->insertAppointment(
            $clinic_id,
            $location_id,
            $clinician_id,
            $patient_id,
            $user_id,
            $slot_id,
            $slot_date,
            $slot_time,
            'confirmed',
            'P9S4UI' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $tag) ?? '') . bin2hex(random_bytes(3)),
            $now
        );

        return [
            'clinic_id' => $clinic_id,
            'clinic_name' => $clinic_name,
            'location_id' => $location_id,
            'clinician_id' => $clinician_id,
            'user_id' => $user_id,
            'patient_id' => $patient_id,
            'link_id' => $link_id,
            'mobile' => $mobile,
            'first_name' => $first_name,
            'last_name' => $last_name,
        ];
    }

    /**
     * Build a fixture where the caller has TWO active linked Patient records within
     * the single seeded Clinic, plus a foreign-user link (to another patient
     * within the same Clinic) and an inactive/archived link.
     *
     * We deliberately stay inside the single seeded Clinic to keep the independent
     * Patient Portal shell render path healthy (App::settings()/App::bookingService()/
     * NotificationService::inbox() all resolve cleanly against the single seeded
     * Clinic when there is exactly one). Adding additional Clinics during a UI
     * render would surface CLINIC_SCOPE_REQUIRED from settings()/phone — which is
     * caught in PatientPortalPage::render(), but which also means the shell
     * content degrades for this user; keeping both links in the same Clinic avoids
     * that and still fully exercises the N>1 record-selector UI contract. The
     * cross-Clinic backend selector contract is independently covered by the
     * existing Slice-4 backend RED/GREEN suite
     * (Phase9Slice4PatientProfileRecordSelectorRedTest).
     *
     * @return array{fx:array{clinic_id:int,clinic_name:string,user_id:int,patient_id:int,link_id:int,mobile:string,first_name:string,last_name:string},
     *              clinic_a:int,clinic_b:int,clinic_a_name:string,clinic_b_name:string,
     *              patient_a:int,patient_b:int,link_a:int,link_b:int,
     *              foreign_user_id:int,foreign_patient_id:int,foreign_link_id:int,
     *              inactive_patient_id:int,inactive_link_id:int}
     */
    private function buildTwoLinkFixture(string $tag): array
    {
        $now = App::db()->nowUtcSql();

        // Use the seeded Clinic (lowest id) for both linked records.
        $clinicA = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_clinics') . ' ORDER BY id ASC LIMIT 1',
            []
        );
        self::assertGreaterThan(0, $clinicA, 'fixture precondition: seeded Clinic exists.');
        $clinicAName = (string) App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [ $clinicA ]
        );

        // For the UI-level "Clinic context label" assertion, both records belong to the
        // same seeded Clinic so clinic_b mirrors clinic_a. The RED contract only requires
        // that a visible Clinic label accompany each option; two patients in the same
        // Clinic carrying that Clinic's name satisfies that.
        $clinicB = $clinicA;
        $clinicBName = $clinicAName;

        $locA = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1 ORDER BY id ASC LIMIT 1',
            [ $clinicA ]
        );
        self::assertGreaterThan(0, $locA, 'fixture precondition: seeded Clinic must have a primary Location.');

        $mobile = $this->mobileFor($tag);
        $user_id = (int) wp_create_user(
            'p9s4ui2_' . $tag . '_' . uniqid('', false),
            'pass-not-used-123',
            uniqid('p9s4ui2_' . $tag . '_', true) . '@test.local'
        );
        self::assertGreaterThan(0, $user_id);
        $u = get_userdata($user_id);
        self::assertNotFalse($u);
        $u->set_role(RolesAndCapabilities::ROLE_PATIENT);

        $patientA = $this->insertPatient($clinicA,
            'MR-P9S4UI-A-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)) . $tag,
            'PatientA_' . $tag, 'A_' . $tag,
            $this->mobileFor($tag . 'a')
        );
        $patientB = $this->insertPatient($clinicB,
            'MR-P9S4UI-B-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)) . $tag,
            'PatientB_' . $tag, 'B_' . $tag,
            $this->mobileFor($tag . 'b')
        );
        $linkA = $this->insertRow('cpms_patient_user_links', [
            'clinic_id' => $clinicA,
            'patient_id' => $patientA,
            'wp_user_id' => $user_id,
            'mobile_at_link' => $this->mobileFor($tag . 'a'),
            'is_primary' => 1,
            'linked_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%d', '%s'], 'linkA');
        $linkB = $this->insertRow('cpms_patient_user_links', [
            'clinic_id' => $clinicB,
            'patient_id' => $patientB,
            'wp_user_id' => $user_id,
            'mobile_at_link' => $this->mobileFor($tag . 'b'),
            'is_primary' => 0,
            'linked_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%d', '%s'], 'linkB');

        // Foreign user + foreign link (must not appear in caller's selector).
        $foreignUser = (int) wp_create_user(
            'p9s4ui_f_' . $tag . '_' . uniqid('', false),
            'pass-not-used-123',
            uniqid('p9s4ui_f_', true) . '@test.local'
        );
        self::assertGreaterThan(0, $foreignUser);
        $fu = get_userdata($foreignUser);
        self::assertNotFalse($fu);
        $fu->set_role(RolesAndCapabilities::ROLE_PATIENT);
        $foreignPatient = $this->insertPatient($clinicA,
            'MR-P9S4UI-F-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)) . $tag,
            'Foreign', 'Patient',
            $this->mobileFor($tag . 'f')
        );
        $foreignLink = $this->insertRow('cpms_patient_user_links', [
            'clinic_id' => $clinicA,
            'patient_id' => $foreignPatient,
            'wp_user_id' => $foreignUser,
            'mobile_at_link' => $this->mobileFor($tag . 'f'),
            'is_primary' => 1,
            'linked_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%d', '%s'], 'foreignLink');

        // Inactive (archived) patient link for caller — must not be selectable.
        $inactivePatient = $this->insertPatient($clinicB,
            'MR-P9S4UI-I-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)) . $tag,
            'Inactive', 'Record',
            $this->mobileFor($tag . 'i'),
            'archived'
        );
        $inactiveLink = $this->insertRow('cpms_patient_user_links', [
            'clinic_id' => $clinicB,
            'patient_id' => $inactivePatient,
            'wp_user_id' => $user_id,
            'mobile_at_link' => $this->mobileFor($tag . 'i'),
            'is_primary' => 0,
            'linked_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%d', '%s'], 'inactiveLink');

        // A minimal appointment for patient A so the existing Slice 1 appointments
        // section renders (this keeps shell render healthy at one Clinic).
        $clinA = $this->insertRow('cpms_clinicians', [
            'clinic_id' => $clinicA,
            'full_name' => 'Dr P9S4UI-2A ' . $tag,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%d', '%s', '%s'], 'clinA');
        $slot = $this->insertSlot($clinicA, $locA, $clinA, $this->ymdDaysOffset(5), '11:00:00', 1);
        $this->insertAppointment($clinicA, $locA, $clinA, $patientA, $user_id, $slot,
            $this->ymdDaysOffset(5), '11:00:00', 'confirmed',
            'P9S4U2' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $tag) ?? '') . bin2hex(random_bytes(2)), $now);

        return [
            'fx' => [
                'clinic_id' => $clinicA,
                'clinic_name' => $clinicAName,
                'user_id' => $user_id,
                'patient_id' => $patientA,
                'link_id' => $linkA,
                'mobile' => $this->mobileFor($tag . 'a'),
                'first_name' => 'PatientA_' . $tag,
                'last_name' => 'A_' . $tag,
            ],
            'clinic_a' => $clinicA,
            'clinic_b' => $clinicB,
            'clinic_a_name' => $clinicAName,
            'clinic_b_name' => $clinicBName,
            'patient_a' => $patientA,
            'patient_b' => $patientB,
            'link_a' => $linkA,
            'link_b' => $linkB,
            'foreign_user_id' => $foreignUser,
            'foreign_patient_id' => $foreignPatient,
            'foreign_link_id' => $foreignLink,
            'inactive_patient_id' => $inactivePatient,
            'inactive_link_id' => $inactiveLink,
        ];
    }

    /**
     * @return array{user_id:int}
     */
    private function buildZeroLinkFixture(string $tag): array
    {
        $user_id = (int) wp_create_user(
            'p9s4ui0_' . $tag . '_' . uniqid('', false),
            'pass-not-used-123',
            uniqid('p9s4ui0_' . $tag . '_', true) . '@test.local'
        );
        self::assertGreaterThan(0, $user_id);
        $u = get_userdata($user_id);
        self::assertNotFalse($u);
        $u->set_role(RolesAndCapabilities::ROLE_PATIENT);
        // No cpms_patient_user_links row inserted.

        return [ 'user_id' => $user_id ];
    }

    private function insertPatient(
        int $clinicId,
        string $mrn,
        string $firstName,
        string $lastName,
        string $mobile,
        string $status = 'active',
        ?string $nationalId = null
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

        return $this->insertRow('cpms_patients', $data, $formats, 'patient');
    }

    private function insertSlot(int $clinic_id, int $location_id, int $clinician_id, string $date, string $time, int $booked): int
    {
        $now = App::db()->nowUtcSql();
        return $this->insertRow('cpms_schedule_slots', [
            'clinic_id' => $clinic_id,
            'location_id' => $location_id,
            'clinician_id' => $clinician_id,
            'slot_date' => $date,
            'slot_time' => $time,
            'duration_min' => 20,
            'capacity' => 1,
            'booked_count' => $booked,
            'held_count' => 0,
            'is_open' => 1,
            'generated_from' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s'], 'slot');
    }

    private function insertAppointment(
        int $clinic_id,
        int $location_id,
        int $clinician_id,
        int $patient_id,
        int $user_id,
        int $slot_id,
        string $date,
        string $time,
        string $status,
        string $reference_code,
        string $now
    ): int {
        $end_time = (new \DateTimeImmutable($date . ' ' . $time, new \DateTimeZone('Asia/Tehran')))
            ->add(new \DateInterval('PT20M'))->format('H:i:s');
        $is_cancelled = str_starts_with($status, 'cancelled_');
        return $this->insertRow('cpms_appointments', [
            'clinic_id' => $clinic_id,
            'location_id' => $location_id,
            'reference_code' => $reference_code,
            'patient_id' => $patient_id,
            'clinician_id' => $clinician_id,
            'slot_id' => $slot_id,
            'wp_user_id' => $user_id,
            'slot_date' => $date,
            'slot_time' => $time,
            'duration_min' => 20,
            'slot_end_time' => $end_time,
            'status' => $status,
            'is_walkin_express' => 0,
            'confirmed_at' => $now,
            'cancelled_at' => $is_cancelled ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%d','%s','%d','%d','%d','%d','%s','%s','%d','%s','%s','%d','%s','%s','%s','%s'], 'appointment(' . $status . ')');
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
        self::assertGreaterThan(0, $id, 'fixture ' . $label . ' id must be positive.');
        return $id;
    }

    private function mobileFor(string $suffix): string
    {
        $number = (int) sprintf('%u', crc32($this->fixtureTag . ':' . $suffix)) % 1_000_000_000;
        return '09' . str_pad((string) $number, 9, '0', STR_PAD_LEFT);
    }

    private function ymdDaysOffset(int $days): string
    {
        return gmdate('Y-m-d', time() + $days * 86400);
    }

    /**
     * @return array<string, mixed>
     */
    private function patientRow(int $patientId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [ $patientId ]
        );
        self::assertIsArray($row);
        return $row;
    }

    // =====================================================================
    // REST dispatch
    // =====================================================================

    /**
     * @param array<string, mixed> $params
     */
    private function dispatchRest(string $method, string $route, array $params, int $userId, bool $withNonce = true): WP_REST_Response
    {
        wp_set_current_user($userId);
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
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
        self::assertSame($code, (string) ($data['code'] ?? ''), $what . ' (expected code ' . $code . ').');
        self::assertArrayHasKey('message', $data, 'REST error envelope must carry a user message.');
        self::assertSame($status, (int) ($data['data']['status'] ?? 0), 'REST error data.status must match HTTP status.');
    }

    // =====================================================================
    // Production-frontend rendering (same mechanism as Slice 3 render).
    // =====================================================================

    private function renderProductionFrontendPortalAs(int $user_id): string
    {
        wp_set_current_user($user_id);
        $frontend_url = PatientPortalShell::portal_url();
        self::assertStringNotContainsString('/wp-admin/', $frontend_url,
            'render guard: portal URL must not be under /wp-admin/.'
        );

        $path = (string) (wp_parse_url($frontend_url, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($frontend_url, PHP_URL_QUERY) ?? '');
        $request = $path . ($query !== '' ? '?' . $query : '');
        if ($request === '') {
            $request = '/';
        }
        $this->go_to($request);

        // Ensure the production template interception happens.
        $baseline = get_stylesheet_directory() . '/page.php';
        if (!is_readable($baseline)) {
            $baseline = get_stylesheet_directory() . '/index.php';
        }
        if (!is_readable($baseline)) {
            $baseline = '/active-theme/page.php';
        }
        $template = (string) apply_filters('template_include', $baseline);
        self::assertFileExists($template, 'render guard: production template must exist.');
        self::assertStringNotContainsString('/themes/', str_replace('\\', '/', $template),
            'render guard: production template must be plugin-owned (Slice 3 GREEN).'
        );

        ob_start();
        include $template;
        return (string) ob_get_clean();
    }

    // =====================================================================
    // Markup helpers
    // =====================================================================

    /**
     * @return list<array{tag:string, html:string}>
     */
    private function markedElements(string $html, string $role): array
    {
        $matches = [];
        // Match opening tags with data-role="<role>".
        $roleQ = preg_quote($role, '/');
        $re = "/<([a-zA-Z][a-zA-Z0-9]*)\\b([^>]*)data-role=[\"']{$roleQ}[\"']([^>]*)>/su";
        if (preg_match_all($re, $html, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $out = [];
        foreach ($matches[0] as $idx => $full) {
            $tagWithAttrs = $full[0];
            $tagName = strtolower((string) $matches[1][$idx][0]);
            // Void elements: treat as self-contained.
            $void = ['br','hr','img','input','meta','link','area','base','col','embed','source','track','wbr'];
            $inner = '';
            if (in_array($tagName, $void, true)) {
                $out[] = [ 'tag' => $tagWithAttrs, 'html' => $tagWithAttrs ];
                continue;
            }
            // Find matching close tag — naive but sufficient for well-formed test fixtures.
            $closeTag = '</' . $tagName;
            $startPos = (int) $full[1] + strlen($tagWithAttrs);
            $closePos = stripos($html, $closeTag, $startPos);
            if ($closePos === false) {
                $inner = substr($html, $startPos, 2000);
            } else {
                $inner = substr($html, $startPos, $closePos - $startPos);
            }
            $out[] = [
                'tag'  => $tagWithAttrs,
                'html' => $tagWithAttrs . $inner . '</' . $tagName . '>',
            ];
        }
        return $out;
    }

    private function assertNavItem(string $html, string $role, string $message): void
    {
        $items = $this->markedElements($html, $role);
        self::assertCount(1, $items, $message);
        // Must be inside the existing patient navigation landmark (Slice 3: <nav data-role="patient-nav">).
        $navPos = strpos($html, 'data-role="patient-nav"');
        if ($navPos === false) {
            $navPos = strpos($html, "data-role='patient-nav'");
        }
        self::assertNotFalse($navPos, $message . ' — prerequisite: the existing patient nav landmark must be present.');
        $itemPos = strpos($html, 'data-role="' . $role . '"');
        if ($itemPos === false) {
            $itemPos = strpos($html, "data-role='" . $role . "'");
        }
        // The item marker must appear after the nav opens and before </nav> closes.
        self::assertNotFalse($itemPos, $message);
        $navClose = stripos($html, '</nav>', $navPos);
        self::assertNotFalse($navClose, $message . ' — patient nav must have a closing tag.');
        self::assertLessThan($navClose, $itemPos,
            $message . ' — the Profile nav item must live inside the existing patient navigation landmark.'
        );
    }

    private function attributeValue(string $tag, string $attribute): ?string
    {
        if (preg_match('/\s' . preg_quote($attribute, '/') . '="([^"]*)"/su', $tag, $m) === 1) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match("/\s" . preg_quote($attribute, '/') . "='([^']*)'/su", $tag, $m) === 1) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }

    /**
     * Find editable inputs carrying data-field="<name>" (input/select/textarea).
     *
     * @return list<array{tag:string, html:string}>
     */
    private function fieldInputs(string $html, string $field): array
    {
        $matches = [];
        $attrQ = preg_quote(self::FIELD_ATTR, '/');
        $fldQ = preg_quote($field, '/');
        // Use a double-quoted PHP string so that the single quote in the character class
        // does not prematurely terminate the PHP string literal.
        $re = "/<(input|select|textarea)\\b([^>]*)\\s{$attrQ}=[\"']{$fldQ}[\"']([^>]*)>/su";
        if (preg_match_all($re, $html, $matches) === false) {
            return [];
        }
        $out = [];
        foreach ($matches[0] as $tag) {
            $out[] = [ 'tag' => $tag, 'html' => $tag ];
        }
        return $out;
    }

    private function nearestFormAncestor(string $html, string $role): ?string
    {
        // Find the element with data-role=$role and then walk backwards to its nearest <form> open tag.
        $pos = strpos($html, 'data-role="' . $role . '"');
        if ($pos === false) {
            $pos = strpos($html, "data-role='" . $role . "'");
        }
        if ($pos === false) {
            return null;
        }
        $before = substr($html, 0, $pos);
        $lastFormOpen = strripos($before, '<form');
        $lastFormClose = strripos($before, '</form>');
        if ($lastFormOpen === false) {
            return null;
        }
        if ($lastFormClose !== false && $lastFormClose > $lastFormOpen) {
            return null; // a form closed before this element but none open
        }
        // Extract the <form ...> tag.
        $end = strpos($html, '>', $lastFormOpen);
        if ($end === false) {
            return null;
        }
        return substr($html, $lastFormOpen, $end - $lastFormOpen + 1);
    }

    /**
     * @return list<string>
     */
    private function configScriptPayloads(string $html): array
    {
        $payloads = [];
        if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/su', $html, $matches, PREG_SET_ORDER) < 1) {
            return $payloads;
        }
        foreach ($matches as $script) {
            $attributes = ' ' . $script[1];
            if (preg_match('/\stype="([^"]*)"/i', $attributes, $type) !== 1 || strtolower(trim($type[1])) !== 'application/json') {
                continue;
            }
            if (preg_match('/\sclass="([^"]*)"/i', $attributes, $class) !== 1) {
                continue;
            }
            $classes = preg_split('/\s+/', trim($class[1]));
            if ($classes === false || !in_array(self::CONFIG_CLASS, $classes, true)) {
                continue;
            }
            $payloads[] = (string) $script[2];
        }
        return $payloads;
    }

    /**
     * Test-side mirror of assets/js/cpms-patient-portal.js apiUrl().
     * Relative paths concatenate onto rest_root. A duplicated /clinic/v1 prefix
     * is stripped. Plain permalink roots (already containing ?) rewrite the
     * first path "?" to "&".
     */
    private function portalApiUrl(string $restRoot, string $path): string
    {
        $nsPrefix = self::NS;
        if (str_starts_with($path, $nsPrefix . '/')) {
            $path = substr($path, strlen($nsPrefix));
        }
        if (str_contains($restRoot, '?') && str_contains($path, '?')) {
            $queryAt = strpos($path, '?');
            $path = substr($path, 0, $queryAt) . '&' . substr($path, $queryAt + 1);
        }

        return $restRoot . $path;
    }

    private function assertNoAuthorityInHtml(string $html, string $label): void
    {
        // Reject structured authority bindings (attribute name or config key).
        // Literal words like "clinic" or "patient" in Persian/English copy are fine.
        // `data-role` is the established semantic UI/test hook (profile-form,
        // profile-empty-state, notifications-section, …) and is NOT a role-authority
        // value. The authority field `role` is still rejected as a config key and
        // as a real field binding (name/data-field/data-user-role/…).
        foreach (self::FORBIDDEN_AUTHORITY_KEYS as $key) {
            if ($key === 'role') {
                self::assertDoesNotMatchRegularExpression(
                    '/(?:^|[\s<"\'])data-(?:user-role|wp-role|authority-role|role-id)\s*=/i',
                    $html,
                    $label . ': must not expose a role authority attribute.'
                );
                self::assertDoesNotMatchRegularExpression(
                    '/(?:^|[\s<"\'])(?:name|data-field|data-authority)\s*=\s*["\']role["\']/i',
                    $html,
                    $label . ': must not bind role as a client authority field.'
                );
            } else {
                $attr = 'data-' . str_replace('_', '-', $key);
                self::assertStringNotContainsString($attr . '=', strtolower($html),
                    $label . ': must not expose ' . $attr . ' authority attribute.'
                );
            }
            // Reject the raw snake_case key as a JS/JSON config key binding
            // (e.g. {"clinic_id":123} or {"role":"patient"}). Prose without a
            // following colon is not a data key. This still rejects "role".
            // Quoted JSON keys ("role":) and the older unquoted binding form.
            // Does not match the semantic attribute data-role= or role="alert".
            self::assertDoesNotMatchRegularExpression(
                '/(?:["{,]' . preg_quote($key, '/') . '\s*:|"' . preg_quote($key, '/') . '"\s*:)/i',
                $html,
                $label . ': must not publish "' . $key . '" as a client-side config key.'
            );
        }
    }
}
