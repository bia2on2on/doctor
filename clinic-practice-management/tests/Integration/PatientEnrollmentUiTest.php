<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientEnrollmentSection;
use ClinicCore\Application\Patients\PatientEnrollmentOptions;
use WP_UnitTestCase;

/**
 * Classic Patient Portal enrollment UI contract.
 *
 * Presentation-only assertions: server-authorized options are rendered with an
 * accessible RTL form; unverified/unavailable subjects never see a Clinic list;
 * no booking control exists; server strings are escaped.
 */
final class PatientEnrollmentUiTest extends WP_UnitTestCase
{
    /** @return array{state:string, clinics:list<array{id:int, name:string}>} */
    private static function options( string $state, array $clinics = [] ): array
    {
        return [ 'state' => $state, 'clinics' => $clinics ];
    }

    public function testEligibleStateRendersAccessibleRtlFormWithOnlyAuthorizedClinics(): void
    {
        $html = PatientEnrollmentSection::render( self::options( PatientEnrollmentOptions::STATE_ELIGIBLE, [
            [ 'id' => 7, 'name' => 'مرکز هفت' ],
            [ 'id' => 9, 'name' => 'مرکز نه' ],
        ] ) );

        $this->assertStringContainsString( 'dir="rtl"', $html );
        $this->assertStringContainsString( 'data-role="patient-enrollment-form"', $html );
        $this->assertStringContainsString( 'aria-labelledby="cpms-pp-enrollment-heading"', $html );
        $this->assertStringContainsString( '<label for="cpms-pp-enrollment-clinic">مرکز مقصد</label>', $html );
        $this->assertStringContainsString( 'data-role="enrollment-clinic" required', $html );
        $this->assertStringContainsString( 'aria-required="true"', $html );
        $this->assertStringContainsString( 'value="7"', $html );
        $this->assertStringContainsString( 'value="9"', $html );
        $this->assertStringContainsString( 'مرکز هفت', $html );
        $this->assertStringContainsString( 'مرکز نه', $html );
        $this->assertSame( 3, substr_count( $html, '<option value="' ), 'Only the two server-authorized Clinics plus the explicit placeholder may be selectable.' );
        $this->assertStringContainsString( 'انتخاب مرکز…', $html );

        $this->assertStringContainsString( 'name="first_name"', $html );
        $this->assertStringContainsString( 'autocomplete="given-name"', $html );
        $this->assertStringContainsString( 'name="last_name"', $html );
        $this->assertStringContainsString( 'autocomplete="family-name"', $html );
        $this->assertStringContainsString( 'name="mobile"', $html );
        $this->assertStringContainsString( 'inputmode="tel"', $html );
        $this->assertStringContainsString( 'dir="ltr"', $html );

        $this->assertStringContainsString( 'role="alert"', $html );
        $this->assertStringContainsString( 'role="status"', $html );
        $this->assertStringContainsString( 'aria-live="polite"', $html );
        $this->assertStringContainsString( 'data-role="enrollment-submit"', $html );
        $this->assertStringContainsString( 'این اقدام نوبت ایجاد نمی‌کند', $html );
        $this->assertStringNotContainsString( 'رزرو نوبت', $html );
        $this->assertStringNotContainsString( 'href=', $html, 'Enrollment must not link to any booking flow.' );
        $this->assertStringNotContainsString( 'type="hidden"', $html );
        $this->assertStringNotContainsString( 'name="clinic_id"', $html, 'The Clinic id is a select value, not a browser-supplied authority field.' );
    }

    public function testUnverifiedStateNeverPublishesClinicNamesAndExplainsReceptionRoute(): void
    {
        $html = PatientEnrollmentSection::render( self::options( PatientEnrollmentOptions::STATE_UNVERIFIED ) );

        $this->assertStringContainsString( 'data-state="unverified"', $html );
        $this->assertStringContainsString( 'بیماران جدید یا تأییدنشده', $html );
        $this->assertStringContainsString( 'پذیرش مرکز', $html );
        $this->assertStringNotContainsString( 'patient-enrollment-form', $html );
        $this->assertStringNotContainsString( '<select', $html );
        $this->assertStringNotContainsString( '<option', $html );
        $this->assertStringNotContainsString( 'مرکز هفت', $html );
    }

    public function testUnavailableStateIsGenericAndLeaksNoHistoryOrDeadline(): void
    {
        $html = PatientEnrollmentSection::render( self::options( PatientEnrollmentOptions::STATE_UNAVAILABLE ) );

        $this->assertStringContainsString( 'data-state="unavailable"', $html );
        $this->assertStringContainsString( 'در حال حاضر', $html );
        $this->assertStringContainsString( 'پذیرش مطب', $html );
        $this->assertStringNotContainsString( 'patient-enrollment-form', $html );
        $this->assertStringNotContainsString( '<select', $html );
        $this->assertStringNotContainsString( 'انقضا', $html );
        $this->assertStringNotContainsString( 'T0', $html );
        $this->assertStringNotContainsString( 'ویزیت', $html );
    }

    public function testNoDestinationStateIsNotPresentedAsSuccessOrFailure(): void
    {
        $html = PatientEnrollmentSection::render( self::options( PatientEnrollmentOptions::STATE_NO_DESTINATION ) );

        $this->assertStringContainsString( 'data-state="no_destination"', $html );
        $this->assertStringContainsString( 'مرکز دیگری برای ثبت در دسترس نیست', $html );
        $this->assertStringNotContainsString( 'patient-enrollment-form', $html );
        $this->assertStringNotContainsString( '<select', $html );
    }

    public function testEligibleWithoutServerClinicsDegradesToUnavailable(): void
    {
        $html = PatientEnrollmentSection::render( self::options( PatientEnrollmentOptions::STATE_ELIGIBLE, [] ) );

        $this->assertStringContainsString( 'data-state="unavailable"', $html );
        $this->assertStringNotContainsString( 'patient-enrollment-form', $html );
    }

    public function testClinicNamesAreEscapedAndInvalidSelectorsAreDropped(): void
    {
        $html = PatientEnrollmentSection::render( self::options( PatientEnrollmentOptions::STATE_ELIGIBLE, [
            [ 'id' => 0, 'name' => 'Ignored zero id' ],
            [ 'id' => 5, 'name' => '<script>alert(1)</script>' ],
            [ 'id' => 5, 'name' => 'Duplicate must be dropped' ],
            [ 'id' => 6, 'name' => '   ' ],
        ] ) );

        $this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
        $this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
        $this->assertStringNotContainsString( 'Ignored zero id', $html );
        $this->assertStringNotContainsString( 'Duplicate must be dropped', $html );
        $this->assertSame( 2, substr_count( $html, '<option value="' ), 'One escaped Clinic plus the placeholder only.' );
    }
}
