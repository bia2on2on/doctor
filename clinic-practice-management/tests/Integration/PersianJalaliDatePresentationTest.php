<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAssets;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\StaffPortalShell;
use WP_UnitTestCase;

/**
 * Owner B — Persian/Jalali date presentation: wiring + markup regression.
 *
 * These changes make user-facing date SELECTION and DISPLAY Persian/Jalali
 * while preserving Gregorian storage, the trusted Location IANA timezone,
 * server-side validation, booking cutoff/cancellation rules, UTC/internal
 * comparisons and DST behaviour:
 *
 *   1. cpms-jalali-date.js enhances real calendar-date inputs on BOTH the
 *      CPMS admin `.wrap[dir="rtl"]` scope AND the plugin-owned Staff Portal
 *      shell (`<html dir="rtl">`), so the reception birth date, the finance
 *      reconciliation range and the reports date get the Shamsi entry field
 *      and popup calendar too.
 *   2. The Jalali widget CSS is scoped under the shared
 *      `body.cpms-jalali-scope` class (added by CpmsAssets on CPMS admin
 *      screens and by the Staff Portal shell template).
 *   3. StaffPortalShell registers/enqueues the SAME local, network-free
 *      Jalali bundle for every mounted portal module.
 *   4. The doctor-portal follow-up date, the patient birth-date fields and
 *      the clinician exception date are real `input[type="date"]` controls
 *      (so the Shamsi entry field applies) with no Gregorian-only
 *      placeholder/label left behind.
 *
 * The pure date-conversion logic is covered by tests/js/cpms-jalali-date.spec.js
 * (executed by CpmsJalaliDateLogicTest); the real-browser widget behaviour is
 * the Playwright acceptance suite. This file is the deterministic
 * wiring/markup layer underneath both.
 */
final class PersianJalaliDatePresentationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        unset($_GET['page']);
        wp_set_current_user(0);
        App::resetScope();
        parent::tearDown();
    }

    private static function pluginRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function testCpmsAssetsBodyClassCarriesTheSharedJalaliScope(): void
    {
        $_GET['page'] = 'cpms-clinicians';
        self::assertStringContainsString(
            'cpms-jalali-scope',
            CpmsAssets::bodyClass('base-class'),
            'CPMS admin screens must carry body.cpms-jalali-scope so the Jalali widget CSS applies'
        );

        $_GET['page'] = 'cpms-not-a-page';
        self::assertStringNotContainsString(
            'cpms-jalali-scope',
            CpmsAssets::bodyClass('base-class'),
            'an unrelated admin page must never carry the Jalali scope class'
        );
    }

    public function testStaffPortalRegistersTheSharedJalaliHandles(): void
    {
        StaffPortalShell::register_jalali_handles();

        self::assertTrue(
            wp_style_is('cpms-jalali-date', 'registered'),
            'the Staff Portal must register the shared cpms-jalali-date.css'
        );
        self::assertTrue(
            wp_script_is('cpms-jalali-date', 'registered'),
            'the Staff Portal must register the shared cpms-jalali-date.js'
        );

        // The SAME local bundle the CPMS admin screens use — no CDN/network.
        $registered = wp_scripts()->registered['cpms-jalali-date'] ?? null;
        self::assertNotNull($registered, 'the Jalali handle must be registered');
        self::assertStringContainsString('assets/js/cpms-jalali-date.js', (string) $registered->src);
    }

    public function testJalaliScriptSelectorCoversTheStandalonePortalScope(): void
    {
        $js = (string) file_get_contents(self::pluginRoot() . '/assets/js/cpms-jalali-date.js');
        self::assertStringContainsString(
            ".wrap[dir=\"rtl\"] input[type=\"date\"], html[dir=\"rtl\"] input[type=\"date\"]",
            $js,
            'enhanceAll must select real calendar-date inputs on both the admin .wrap[dir=rtl] scope and the standalone portal html[dir=rtl] scope'
        );
    }

    public function testJalaliWidgetCssIsScopedUnderTheSharedBodyClass(): void
    {
        $css = (string) file_get_contents(self::pluginRoot() . '/assets/css/cpms-jalali-date.css');
        self::assertStringContainsString(
            'body.cpms-jalali-scope .cpms-jalali-field',
            $css,
            'the widget CSS must be scoped under the shared body.cpms-jalali-scope class'
        );
        self::assertStringNotContainsString(
            'body.cpms-admin .cpms-jalali-',
            $css,
            'the widget CSS must no longer be CPMS-admin-only scoped'
        );
    }

    public function testStaffPortalShellTemplateCarriesTheJalaliScopeClass(): void
    {
        $shell = (string) file_get_contents(self::pluginRoot() . '/templates/staff-portal-shell.php');
        self::assertStringContainsString(
            'cpms-jalali-scope',
            $shell,
            'the Staff Portal shell body class must carry cpms-jalali-scope so the widget CSS applies on every module'
        );
    }

    public function testDoctorPortalFollowUpDateIsARealCalendarDateInput(): void
    {
        $shell = (string) file_get_contents(self::pluginRoot() . '/templates/doctor-portal-shell.php');

        $matched = preg_match('/<input[^>]*id="cpms-doc-ws-fu-date"[^>]*>/', $shell, $m);
        self::assertSame(1, $matched, 'precondition: the doctor-portal follow-up date input exists');
        self::assertStringContainsString(
            'type="date"',
            $m[0],
            'the follow-up date must be a real input[type=date] so the Shamsi entry field applies'
        );
        self::assertStringNotContainsString(
            'placeholder="2026-04-10"',
            $m[0],
            'no Gregorian-only placeholder remains on the follow-up date'
        );
        self::assertStringNotContainsString(
            'تاریخ پیشنهادی (YYYY-MM-DD)',
            $shell,
            'the follow-up date label must not advertise a Gregorian-only format'
        );
    }

    public function testPatientBirthDateFieldsAreRealCalendarDateInputs(): void
    {
        $admin = (string) file_get_contents(self::pluginRoot() . '/src/Admin/PatientAdminPage.php');
        $matched = preg_match('/<input[^>]*id="cp_pat_birth"[^>]*>/', $admin, $m);
        self::assertSame(1, $matched, 'precondition: the patient admin birth date input exists');
        self::assertStringContainsString('type="date"', $m[0], 'the patient admin birth date must be a real input[type=date]');
        self::assertStringNotContainsString('placeholder="YYYY-MM-DD"', $m[0], 'no Gregorian-only placeholder remains');

        $portal = (string) file_get_contents(self::pluginRoot() . '/src/Admin/PatientPortalPage.php');
        self::assertMatchesRegularExpression(
            "/'birth_date'\s*=>\s*\[[\s\S]*?'type'\s*=>\s*'date'/",
            $portal,
            'the patient portal birth date field must be type=date'
        );
        self::assertStringNotContainsString(
            "'placeholder' => 'YYYY-MM-DD'",
            $portal,
            'no Gregorian-only placeholder remains on the patient portal birth date'
        );
    }

    public function testClinicianExceptionDateLabelIsPersianOnly(): void
    {
        $page = (string) file_get_contents(self::pluginRoot() . '/src/Admin/ClinicianAdminPage.php');
        self::assertStringNotContainsString(
            'تاریخ (میلادی)',
            $page,
            'the clinician exception date label must not advertise a Gregorian-only format (the field is Jalali-enhanced)'
        );
    }
}
