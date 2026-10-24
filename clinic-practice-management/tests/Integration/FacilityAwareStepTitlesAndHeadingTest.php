<?php
declare(strict_types=1);
namespace ClinicCore\Tests\Integration;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Application\Onboarding\FacilityTypeCatalog;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Corrective slice: the wizard's PAGE HEADING, STEP-NAV LABELS and
 * "missing required steps" wording must themselves be facility-aware,
 * not only the per-step card content.
 *
 * Defect fixed here (admin walkthrough finding): before this slice, a
 * hospital/dental/specialized-clinic administrator still saw the generic
 * nav pill "2. اطلاعات کلینیک (الزامی)" and page heading
 * "راه‌اندازی کلینیک — قدم N از M" even though every OTHER string on the
 * same screen (step CARD heading, "پزشکان"/"دندانپزشکان" field labels,
 * guidance) was already facility-aware via FacilityTypeCatalog. The fix
 * is a single source of truth — FacilityTypeCatalog::step_title() /
 * ::wizard_heading() — not scattered string replacement, and it is a
 * pure additive change: with no facility selected, every assertion below
 * about the legacy generic wording still holds (see
 * testUnselectedFacilityKeepsLegacyGenericWording).
 */
final class FacilityAwareStepTitlesAndHeadingTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
    }

    protected function tearDown(): void {
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function authorize(): int {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);
        $clinicId = 1;
        try {
            $clinicId = App::scope()->clinicId;
        } catch (\Throwable) {
        }
        if ($clinicId > 0) {
            cpms_test_seed_membership($id, $clinicId, 'cpms_manager');
        }
        try {
            App::replaceExplicitScope(App::scope());
        } catch (\Throwable) {
        }

        return $id;
    }

    private function selectFacility(int $uid, string $facility, string $structure = ''): void {
        self::assertSame('', CpmsSetupWizard::save_facility(App::settings(), [
            'facility_type' => $facility,
            'structure'     => $structure,
        ], $uid));
    }

    private function renderStep(string $step): string {
        $prev = $_GET;
        $_GET['step'] = $step;
        ob_start();
        try {
            CpmsSetupWizard::render();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $_GET = $prev;
        }
    }

    public function testHospitalNavAndHeadingAreFacilityAwareNotGenericClinic(): void {
        $uid = $this->authorize();
        $this->selectFacility($uid, 'hospital', 'hospital_network');

        $html = $this->renderStep('clinic');

        // The exact reported defect must be gone: no generic "اطلاعات کلینیک" pill/heading.
        self::assertStringNotContainsString('اطلاعات کلینیک', $html, 'hospital must not show the generic clinic nav label');
        self::assertStringNotContainsString('راه‌اندازی کلینیک —', $html, 'hospital must not show the generic clinic page heading');
        // Hospital is presented as outpatient-only "مرکز درمانی", not the
        // bare "بیمارستان" label used only in the type picker, and never the
        // generic "کلینیک" wording.
        self::assertStringContainsString('راه‌اندازی مرکز درمانی —', $html, 'the page heading must name the selected facility unit');
        self::assertStringContainsString('اطلاعات مرکز درمانی', $html, 'the clinic-step nav label must use the hospital unit');

        $steps = CpmsSetupWizard::active_steps();
        $byId  = [];
        foreach ($steps as $s) {
            $byId[$s['id']] = $s;
        }
        self::assertSame('اطلاعات مرکز درمانی', $byId['clinic']['title']);
        self::assertTrue($byId['clinic']['required']);
    }

    public function testDentalClinicDoctorsStepUsesDentistWording(): void {
        $uid = $this->authorize();
        $this->selectFacility($uid, 'dental_clinic', 'multi_doctor');

        $byId = [];
        foreach (CpmsSetupWizard::active_steps() as $s) {
            $byId[$s['id']] = $s;
        }
        self::assertSame('دندانپزشکان', $byId['doctors']['title']);
        self::assertSame('اطلاعات کلینیک دندانپزشکی', $byId['clinic']['title']);

        $html = $this->renderStep('doctors');
        self::assertStringContainsString('دندانپزشکان', $html);
        self::assertStringNotContainsString('. پزشکان (', $html, 'the generic "پزشکان" nav pill wording must not leak for a dental clinic');
    }

    public function testMissingRequiredStepsReportFacilityAwareTitles(): void {
        $uid = $this->authorize();
        $this->selectFacility($uid, 'specialized_clinic', 'multi_doctor');

        $missing = CpmsSetupWizard::missing_required_steps(App::settings());
        self::assertContains('پزشکان متخصص', $missing, 'the doctors step must be reported with the specialized-clinic clinician term');
    }

    /** @dataProvider facilityHeadingCases */
    public function testActiveStepHeadingNamesTheSelectedFacilityAndStep(string $facility, string $structure): void {
        $uid = $this->authorize();
        $this->selectFacility($uid, $facility, $structure);
        $html = $this->renderStep('clinic');
        $expected = FacilityTypeCatalog::wizard_heading($facility) . ' — '
            . FacilityTypeCatalog::step_title($facility, 'clinic', 'اطلاعات کلینیک');
        self::assertStringContainsString($expected, $html);
    }

    public static function facilityHeadingCases(): array {
        return [
            'practice' => ['doctor_practice', 'single'],
            'hospital' => ['hospital', 'hospital_network'],
            'medical building' => ['other', 'medical_building'],
            'dental' => ['dental_clinic', 'multi_doctor'],
        ];
    }

    public function testUnselectedFacilityKeepsLegacyGenericWording(): void {
        // No facility selection at all: legacy pre-existing behaviour must be byte-identical.
        $this->authorize();
        self::assertSame('کلینیک', FacilityTypeCatalog::type_profile('')['unit']);
        self::assertSame('اطلاعات کلینیک', FacilityTypeCatalog::step_title('', 'clinic', 'اطلاعات کلینیک'));
        self::assertSame('پزشکان', FacilityTypeCatalog::step_title('', 'doctors', 'پزشکان'));
        self::assertSame('راه‌اندازی کلینیک', FacilityTypeCatalog::wizard_heading(''));
        self::assertSame('مکان‌ها', FacilityTypeCatalog::step_title('hospital', 'locations', 'مکان‌ها'), 'step ids other than clinic/doctors are never overridden');
    }

    public function testInvalidFacilityIdFallsBackSafely(): void {
        self::assertSame('fallback', FacilityTypeCatalog::step_title('not-a-real-type', 'clinic', 'fallback'));
        self::assertSame('راه‌اندازی کلینیک', FacilityTypeCatalog::wizard_heading('not-a-real-type'));
    }
}
