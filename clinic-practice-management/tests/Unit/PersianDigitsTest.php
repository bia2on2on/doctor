<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Domain\Time\PersianDigits;
use PHPUnit\Framework\TestCase;

/**
 * Package P2A — pure Persian digits helper, Jalali display variant, and B4/B5 JS spec runner.
 */
final class PersianDigitsTest extends TestCase {

	public function test_digits_converts_latin_digits_and_leaves_non_digits_unchanged(): void {
		self::assertSame( '۰۱۲۳۴۵۶۷۸۹', PersianDigits::digits( '0123456789' ) );
		self::assertSame( '۴۲', PersianDigits::digits( 42 ) );
		self::assertSame( '۰', PersianDigits::digits( 0 ) );
		self::assertSame( '', PersianDigits::digits( '' ) );
		self::assertSame(
			'۱۴۰۵/۰۸/۱۱ ۱۴:۳۰ UTC',
			PersianDigits::digits( '1405/08/11 14:30 UTC' )
		);
		self::assertSame(
			'-۱۲.۳۴ +۵۶:۷۸ (نوبت‌دهی)',
			PersianDigits::digits( '-12.34 +56:78 (نوبت‌دهی)' )
		);
		self::assertSame(
			'١٢٣٤٥٦٧٨٩٠',
			PersianDigits::digits( '١٢٣٤٥٦٧٨٩٠' ),
			'Non-Latin characters (including Arabic-Indic digits) stay untouched.'
		);
	}

	public function test_format_int_uses_persian_thousands_separator_and_handles_negatives(): void {
		self::assertSame( '۰', PersianDigits::format_int( 0 ) );
		self::assertSame( '۷', PersianDigits::format_int( 7 ) );
		self::assertSame( '۹۹۹', PersianDigits::format_int( 999 ) );
		self::assertSame( '۱٬۰۰۰', PersianDigits::format_int( 1000 ) );
		self::assertSame( '۳۵۰٬۰۰۰', PersianDigits::format_int( 350000 ) );
		self::assertSame( '۱٬۲۳۴٬۵۶۷٬۸۹۰', PersianDigits::format_int( 1234567890 ) );
		self::assertSame( '-۱', PersianDigits::format_int( -1 ) );
		self::assertSame( '-۳۵۰٬۰۰۰', PersianDigits::format_int( -350000 ) );
		self::assertSame(
			'۹٬۲۲۳٬۳۷۲٬۰۳۶٬۸۵۴٬۷۷۵٬۸۰۷',
			PersianDigits::format_int( 9223372036854775807 )
		);
	}

	public function test_format_number_formats_integers_and_decimals_with_persian_separators(): void {
		self::assertSame( '۱٬۵۰۰٬۰۰۰', PersianDigits::format_number( 1500000 ) );
		self::assertSame( '۱٬۲۳۴٫۵۰', PersianDigits::format_number( 1234.5, 2 ) );
		self::assertSame( '-۱٬۲۳۴٫۵۶', PersianDigits::format_number( -1234.56, 2 ) );
		self::assertSame( '۰٫۵', PersianDigits::format_number( 0.5, 1 ) );
		self::assertSame( '۰٫۰۰', PersianDigits::format_number( -0.0001, 2 ) );
		self::assertSame( '۲۵۰٬۰۰۰٫۷۵', PersianDigits::format_number( '250000.75', 2 ) );
		self::assertSame( '-۴۲٬۰۰۰', PersianDigits::format_number( '-42000', 0 ) );
	}

	public function test_jalali_display_uses_persian_digits_and_keeps_format_ymd_latin(): void {
		self::assertSame( '1405/01/01', Jalali::formatYmd( '2026-03-21' ) );
		self::assertSame( '۱۴۰۵/۰۱/۰۱', Jalali::format_display_ymd( '2026-03-21' ) );
		self::assertSame( '1403/12/30', Jalali::formatYmd( '2025-03-20' ) );
		self::assertSame( '۱۴۰۳/۱۲/۳۰', Jalali::format_display_ymd( '2025-03-20' ) );

		// B5: historical birth dates outside 1300..1499 never throw on PHP display paths.
		self::assertSame( '۱۲۹۴/۰۲/۱۹', Jalali::format_display_ymd( '1915-05-10' ) );
		self::assertSame( '۱۲۷۸/۱۰/۱۱', Jalali::format_display_ymd( '1900-01-01' ) );

		$today_latin   = Jalali::todayJalali();
		$today_persian = Jalali::today_display_jalali();
		self::assertMatchesRegularExpression( '/^\d{4}\/\d{2}\/\d{2}$/', $today_latin );
		self::assertMatchesRegularExpression( '/^[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}$/u', $today_persian );
		self::assertSame( PersianDigits::digits( $today_latin ), $today_persian );
	}

	public function test_p2a_jalali_js_spec_passes(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		self::assertNotSame( '', $node, 'node binary must be available in CI.' );

		$spec = dirname( __DIR__ ) . '/js/pkg-p2a-persian-digits.spec.js';
		self::assertFileExists( $spec );

		$output    = [];
		$exit_code = 0;
		exec( $node . ' ' . escapeshellarg( $spec ) . ' 2>&1', $output, $exit_code );

		self::assertSame(
			0,
			$exit_code,
			"pkg-p2a-persian-digits.spec.js failed:\n" . implode( "\n", $output )
		);
	}
}
