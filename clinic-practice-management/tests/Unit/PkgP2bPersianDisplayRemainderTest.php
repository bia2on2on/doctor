<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Domain\Time\PersianDigits;
use PHPUnit\Framework\TestCase;

/**
 * Package P2B — lock for the remaining user-visible Latin digits.
 *
 * Two independent halves:
 *  1. Source lock: whitespace-tolerant regexes (never exact byte runs or line
 *     counts) prove that every changed DISPLAY producer calls the shared
 *     helpers from #309, and that the machine values of the same projection
 *     (REST `date`/`time`/`created_at`, stored columns, identifiers, contract
 *     keys) keep their Latin form.
 *  2. Behaviour lock: `tests/js/pkg-p2b-portal-digits.spec.js` executes the
 *     shipped portal JS in a Node `vm` and proves Persian digits out, Latin
 *     machine values untouched. Node is REQUIRED — this wrapper fails (never
 *     skips) when it is missing, the same contract as PersianDigitsTest's
 *     B4/B5 runner.
 *
 * Runtime proof of the PHP producers lives in the integration suites
 * (PkgP2a, NotificationFlow, Phase11, Phase12Slice5).
 */
final class PkgP2bPersianDisplayRemainderTest extends TestCase {

	private const SRC = __DIR__ . '/../../src/';
	private const TEMPLATES = __DIR__ . '/../../templates/';

	private function source( string $relative ): string {
		$path = self::SRC . $relative;
		self::assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	private function template( string $relative ): string {
		$path = self::TEMPLATES . $relative;
		self::assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	public function test_staff_messaging_time_label_is_persian_and_never_latin_utc(): void {
		$src = $this->source( 'Application/Messaging/StaffMessagingService.php' );

		self::assertMatchesRegularExpression(
			"/private\s+const\s+UTC_LABEL_FA\s*=\s*'به وقت جهانی';/u",
			$src,
			'the UTC label is Persian text, never the Latin letters'
		);
		self::assertMatchesRegularExpression( '/self::UTC_LABEL_FA/u', $src );
		self::assertMatchesRegularExpression( '/Jalali::format_display_ymd\(\s*\$date\s*\)/', $src );
		self::assertMatchesRegularExpression( '/PersianDigits::digits\(\s*\$time\s*\)/', $src );
		self::assertStringNotContainsString( "' UTC'", $src );
		// Storage/repository column is still read raw for the machine field.
		self::assertMatchesRegularExpression(
			"/'created_at'\s*=>\s*\(string\)\s*\\\$row\['created_at'\],/",
			$src,
			'created_at stays the raw stored value'
		);
	}

	public function test_reminder_and_booking_variables_use_persian_display_values(): void {
		$appt = $this->source( 'Application/Jobs/ApptReminderHandler.php' );
		self::assertMatchesRegularExpression(
			"/'appointment_date'\s*=>\s*Jalali::format_display_ymd\(/",
			$appt,
			'reminder date is Persian'
		);
		self::assertMatchesRegularExpression(
			"/'appointment_time'\s*=>\s*PersianDigits::digits\(/",
			$appt,
			'reminder time is Persian digits'
		);
		self::assertMatchesRegularExpression(
			"/substr\(\s*\(string\)\s*\\\$row\['slot_time'\],\s*0,\s*5\s*\)/",
			$appt,
			'the machine slot_time is still cut to HH:MM'
		);
		self::assertStringNotContainsString( 'Jalali::formatYmd', $appt );

		$follow = $this->source( 'Application/Jobs/FollowUpReminderHandler.php' );
		self::assertMatchesRegularExpression(
			"/'appointment_date'\s*=>\s*Jalali::format_display_ymd\(/",
			$follow,
			'follow-up date is Persian'
		);
		self::assertMatchesRegularExpression(
			"/\\\$row\['suggested_date'\]\s*\)/",
			$follow,
			'the date still comes from the persisted suggested_date'
		);
		self::assertStringNotContainsString( 'Jalali::formatYmd', $follow );

		$booking = $this->source( 'Application/Booking/BookingService.php' );
		self::assertMatchesRegularExpression(
			"/'appointment_date'\s*=>\s*Jalali::format_display_ymd\(/",
			$booking,
			'booking SMS date is Persian'
		);
		self::assertMatchesRegularExpression(
			"/\\\$appt\['slot_date'\]\s*\)/",
			$booking,
			'the booking date still comes from the persisted slot_date'
		);
		self::assertMatchesRegularExpression(
			"/'appointment_time'\s*=>\s*PersianDigits::digits\(/",
			$booking
		);
		// The machine date/time of the same appointment view stays Gregorian/HH:MM.
		self::assertMatchesRegularExpression( "/'date'\s*=>\s*\\\$date,/", $booking );
		self::assertMatchesRegularExpression(
			"/'time'\s*=>\s*substr\(\(\s*string\s*\)\s*\\\$row\['slot_time'\],\s*0,\s*5\),/",
			$booking,
			'time stays HH:MM'
		);
		self::assertStringNotContainsString( 'Jalali::formatYmd', $booking );
	}

	public function test_portal_jalali_day_labels_are_persian_while_iso_dates_stay_latin(): void {
		$visits = $this->source( 'Application/Visits/VisitService.php' );
		self::assertSame(
			3,
			preg_match_all( "/'jalali'\s*=>\s*Jalali::format_display_ymd\(/", $visits ),
			'every operational-day label (queue, today, empty-scope) is Persian'
		);
		self::assertDoesNotMatchRegularExpression(
			"/'jalali'\s*=>\s*Jalali::formatYmd\(/",
			$visits,
			'no operational-day label stays Latin'
		);
		self::assertMatchesRegularExpression( "/'date'\s*=>\s*\\\$operational_date,/", $visits );

		$appointments = $this->source( 'Infrastructure/Repository/AppointmentRepository.php' );
		self::assertMatchesRegularExpression( "/'jalali'\s*=>/", $appointments );
		self::assertMatchesRegularExpression(
			'/ClinicCore\\\\Domain\\\\Time\\\\Jalali::format_display_ymd\(/',
			$appointments,
			'the upcoming-list label uses the display helper'
		);
		self::assertMatchesRegularExpression( "/'time'\s*=>\s*substr\(/", $appointments );

		$reception = $this->source( 'Rest/ReceptionPortalController.php' );
		self::assertStringNotContainsString( 'Jalali::formatYmd', $reception );
		self::assertMatchesRegularExpression(
			"/'jalali'\s*=>\s*Jalali::format_display_ymd\(\s*\\\$date\s*\)/",
			$reception,
			'the reception day label renders the same Location-local date in Persian'
		);
		self::assertMatchesRegularExpression( "/'time'\s*=>\s*substr\(/", $reception );
	}

	public function test_file_and_finance_display_dates_are_persian(): void {
		$files = $this->source( 'Application/Clinical/MedicalFileService.php' );
		self::assertMatchesRegularExpression(
			"/\\\$map\[\s*\(\s*int\s*\)\s*\\\$row\['id'\]\s*\]\s*=\s*Jalali::format_display_ymd\(/",
			$files,
			'the per-file display date map uses the display helper'
		);
		self::assertStringNotContainsString( 'Jalali::formatYmd', $files );

		$finance = $this->source( 'Application/Finance/FinanceService.php' );
		self::assertMatchesRegularExpression(
			"/'jalali_date'\s*=>\s*Jalali::format_display_ymd\(/",
			$finance,
			'receipt invoice date is Persian'
		);
		self::assertMatchesRegularExpression(
			"/'jalali_paid_at'\s*=>\s*Jalali::format_display_ymd\(/",
			$finance,
			'receipt payment date is Persian'
		);
		// Receipt contract keys are untouched: only the display digits changed.
		self::assertMatchesRegularExpression(
			"/'invoice_number'\s*=>\s*\(string\)\s*\\\$invoice\[\s*'invoice_number'\s*\],/",
			$finance
		);
		self::assertMatchesRegularExpression(
			"/'payment_number'\s*=>\s*\(string\)\s*\\\$p\[\s*'payment_number'\s*\],/",
			$finance
		);
	}

	public function test_portal_templates_call_the_display_helpers_in_the_changed_spots(): void {
		$doctor = $this->template( 'doctor-portal-shell.php' );
		self::assertMatchesRegularExpression(
			"/var\s+value\s*=\s*s\[f\[0\]\]\s*!=\s*null\s*\?\s*cpmsFaDigits\(/",
			$doctor,
			'today stat counters go through cpmsFaDigits'
		);
		self::assertMatchesRegularExpression(
			"/\+ ' بایت';/u",
			$doctor,
			'the byte label is appended after a converted number'
		);
		self::assertMatchesRegularExpression( "/fa\(\s*n\s*\)\s*\+ ' بایت'/u", $doctor );
		self::assertMatchesRegularExpression(
			"/fa\(\s*Math\.round\(\s*n\s*\/\s*1024\s*\)\s*\)/",
			$doctor,
			'kilobyte label converts the computed number'
		);
		self::assertMatchesRegularExpression(
			"/\/\s*10485\.76\s*\)\s*\/\s*100\s*\)/",
			$doctor,
			'megabyte label keeps its two-decimal computation'
		);
		self::assertMatchesRegularExpression( "/return\s+fa\(/", $doctor );

		$reception = $this->template( 'staff-reception.php' );
		self::assertMatchesRegularExpression(
			'/faDigits\(\s*\(\s*stats\s*&&\s*stats\[key\]\s*\)\s*\|\|\s*0\s*\)/',
			$reception,
			'queue counters go through faDigits'
		);
		// The day <option> keeps the machine ISO value; only its text is Persian.
		self::assertMatchesRegularExpression( "/'<option value=\"'/", $reception );
		self::assertMatchesRegularExpression( '/escapeHtml\(day\.date\)/', $reception );
		self::assertMatchesRegularExpression( '/escapeHtml\(day\.jalali\)/', $reception );
	}

	public function test_p2b_portal_digits_js_spec_passes_and_fails_without_node(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		self::assertNotSame(
			'',
			$node,
			'node binary must be available in CI: the portal digit spec is executed, never skipped.'
		);

		$spec = dirname( __DIR__ ) . '/js/pkg-p2b-portal-digits.spec.js';
		self::assertFileExists( $spec );

		$output    = [];
		$exit_code = 0;
		exec( $node . ' ' . escapeshellarg( $spec ) . ' 2>&1', $output, $exit_code );

		self::assertSame(
			0,
			$exit_code,
			"pkg-p2b-portal-digits.spec.js failed:\n" . implode( "\n", $output )
		);
		self::assertStringContainsString( 'OK: pkg-p2b-portal-digits spec', implode( "\n", $output ) );
	}

	public function test_display_helpers_convert_digits_only_and_keep_machine_forms(): void {
		// Display helpers: Persian digits, same Jalali/Gregorian meaning.
		self::assertSame( '۱۴۰۵/۰۱/۰۱', Jalali::format_display_ymd( '2026-03-21' ) );
		self::assertSame( '۰۹:۳۰', PersianDigits::digits( '09:30' ) );
		// Machine forms stay available and unchanged for REST/storage values.
		self::assertSame( '1405/01/01', Jalali::formatYmd( '2026-03-21' ) );
		self::assertSame( '۱۴۰۵-۰۱-۰۱', PersianDigits::digits( '1405-01-01' ) );
		$display = Jalali::format_display_ymd( '2026-03-21' );
		self::assertNotSame( $display, Jalali::formatYmd( '2026-03-21' ) );
		self::assertNotSame( PersianDigits::digits( '09:30' ), '09:30' );
	}
}
