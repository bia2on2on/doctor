<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Admin\CpmsUi;
use ClinicCore\Application\Handwriting\PrescriptionPaperContext;
use ClinicCore\Domain\Clinical\DoctorDisplayName;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Server-side display projections without a WordPress or database dependency. */
final class PresentationIntegrityTest extends TestCase {

	public function test_doctor_fallback_only_applies_after_normalization(): void {
		foreach ( [ null, '', " \t", "\u{00A0}" ] as $blank ) {
			self::assertSame( 'پزشک', DoctorDisplayName::with_title_or_fallback( $blank ) );
			self::assertSame( '', DoctorDisplayName::with_title( $blank ) );
		}
		self::assertSame(
			'دکتر رضایی',
			DoctorDisplayName::with_title_or_fallback( 'رضایی' )
		);
		self::assertSame(
			'دکتر رضایی',
			DoctorDisplayName::with_title_or_fallback( 'Dr. رضایی' )
		);
		self::assertSame(
			'دکتر رضایی',
			DoctorDisplayName::with_title_or_fallback( 'دکتر دکتر رضایی' )
		);
		set_error_handler( static fn (): bool => true );
		try {
			self::assertSame( 'پزشک', DoctorDisplayName::with_title_or_fallback( "\xFF" ) );
			self::assertSame( '', DoctorDisplayName::with_title( "\xFF" ) );
		} finally {
			restore_error_handler();
		}
	}

	public function test_paper_display_preserves_real_names_and_machine_date(): void {
		$present = new ReflectionMethod( PrescriptionPaperContext::class, 'present_row' );
		$row = [
			'doctor'     => 'Dr HW',
			'location'   => 'مطب',
			'first_name' => 'سارا',
			'last_name'  => 'احمدی',
			'visit_date' => '2026-03-21',
		];
		self::assertSame(
			[
				'doctor'      => 'Dr HW',
				'location'    => 'مطب',
				'patient'     => 'سارا احمدی',
				'date'        => '2026-03-21',
				'jalali_date' => '۱۴۰۵/۰۱/۰۱',
			],
			$present->invoke( null, $row )
		);

		$row['doctor']     = "\u{00A0}";
		$row['first_name'] = "\u{00A0}";
		$row['last_name']  = ' ';
		$row['visit_date'] = '2026-02-30';
		$blank             = $present->invoke( null, $row );
		self::assertSame( 'نام پزشک ثبت نشده', $blank['doctor'] );
		self::assertSame( 'نام بیمار ثبت نشده', $blank['patient'] );
		self::assertSame( '2026-02-30', $blank['date'] );
		self::assertSame( '', $blank['jalali_date'] );

		$row['doctor']     = "\xFF";
		$row['first_name'] = "\xFF";
		set_error_handler( static fn (): bool => true );
		try {
			$malformed = $present->invoke( null, $row );
			self::assertSame( 'نام پزشک ثبت نشده', $malformed['doctor'] );
			self::assertSame( 'نام بیمار ثبت نشده', $malformed['patient'] );
		} finally {
			restore_error_handler();
		}
	}

	public function test_paper_lookup_still_requires_every_original_scope_anchor(): void {
		$path = dirname( __DIR__, 2 ) . '/src/Application/Handwriting/PrescriptionPaperContext.php';
		$src  = (string) file_get_contents( $path );
		self::assertStringContainsString( 'p.id = v.patient_id AND p.clinic_id = v.clinic_id', $src );
		self::assertStringContainsString( 'l.id = v.location_id AND l.clinic_id = v.clinic_id', $src );
		self::assertStringContainsString( 'c.id = v.clinician_id AND c.is_active = 1', $src );
		self::assertStringContainsString( 'v.id = %d AND v.clinic_id = %d', $src );
		self::assertStringContainsString( 'v.location_id = %d AND v.clinician_id = %d', $src );
	}

	public function test_sql_and_iso_timestamps_are_displayed_in_the_requested_timezone(): void {
		$expected = '۱۴۰۵/۰۱/۰۱ ۱۳:۳۰';
		foreach (
			[ '2026-03-21 10:00:00', '2026-03-21 10:00:00.000', '2026-03-21T10:00:00+00:00' ]
			as $raw
		) {
			self::assertSame( $expected, CpmsUi::jalali_date_time( $raw, 'Asia/Tehran' ) );
		}
		self::assertSame(
			'۱۴۰۵/۰۱/۰۱ ۰۶:۳۰',
			CpmsUi::jalali_date_time( '2026-03-21T10:00:00+03:30', 'UTC' )
		);
		self::assertSame( 'not-a-date', CpmsUi::jalali_date_time( 'not-a-date', 'UTC' ) );
		$invalid = '2026-02-30T10:00:00+00:00';
		self::assertSame( $invalid, CpmsUi::jalali_date_time( $invalid, 'UTC' ) );
	}
}
