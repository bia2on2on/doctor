<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Domain\Time\PersianDigits;
use PHPUnit\Framework\TestCase;

/**
 * Package P2B — source-level lock for the remaining user-visible Latin digits.
 *
 * Every assertion below is about DISPLAY producers only: the Persian/Jalali
 * label shown to a human. Machine values (REST `date`/`time`/`created_at`,
 * stored columns, identifiers) must keep their Latin form, so the same test
 * pins them as unchanged. Runtime proof of the same outputs lives in the
 * integration suites (PkgP2a, NotificationFlow, Phase11, Phase12Slice5).
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

		self::assertStringContainsString( "private const UTC_LABEL_FA = 'به وقت جهانی';", $src );
		self::assertStringContainsString( 'self::UTC_LABEL_FA', $src );
		self::assertStringContainsString( 'Jalali::format_display_ymd( $date )', $src );
		self::assertStringContainsString( 'PersianDigits::digits( $time )', $src );
		self::assertStringNotContainsString( ". ' UTC'", $src );
		// Storage/repository column is still read raw for the machine field.
		self::assertStringContainsString(
			"'created_at'        => (string) \$row['created_at'],",
			$src
		);
	}

	public function test_reminder_and_booking_sms_variables_use_persian_display_values(): void {
		$appt = $this->source( 'Application/Jobs/ApptReminderHandler.php' );
		self::assertStringContainsString(
			"'appointment_date' => Jalali::format_display_ymd( (string) \$row['slot_date'] ),",
			$appt
		);
		self::assertStringContainsString(
			"'appointment_time' => PersianDigits::digits(",
			$appt
		);
		self::assertStringContainsString(
			"substr( (string) \$row['slot_time'], 0, 5 )",
			$appt
		);
		self::assertStringNotContainsString( 'Jalali::formatYmd', $appt );

		$follow = $this->source( 'Application/Jobs/FollowUpReminderHandler.php' );
		self::assertStringContainsString(
			"'appointment_date' => Jalali::format_display_ymd( (string) \$row['suggested_date'] ),",
			$follow
		);
		self::assertStringNotContainsString( 'Jalali::formatYmd', $follow );

		$booking = $this->source( 'Application/Booking/BookingService.php' );
		self::assertStringContainsString(
			"'appointment_date' => Jalali::format_display_ymd( (string) \$appt['slot_date'] ),",
			$booking
		);
		self::assertStringContainsString(
			"'appointment_time' => PersianDigits::digits(",
			$booking
		);
		// The machine date/time of the same appointment view stays Gregorian/HH:MM.
		self::assertStringContainsString( "'date' => \$date,", $booking );
		self::assertStringContainsString(
			"'time' => substr((string) \$row['slot_time'], 0, 5),",
			$booking
		);
		self::assertStringNotContainsString( 'Jalali::formatYmd', $booking );
	}

	public function test_export_ready_notification_uses_persian_expiry_date(): void {
		$src = $this->source( 'Application/Reports/ExportService.php' );

		self::assertStringContainsString(
			"'expires_at' => Jalali::format_display_ymd( substr( \$expiresAt, 0, 10 ) ),",
			$src
		);
		// The machine expiry (comparison target) is still the raw UTC SQL value.
		self::assertStringContainsString( "'expires_at' => \$expiresAt,", $src );
	}

	public function test_portal_jalali_day_labels_are_persian_while_iso_dates_stay_latin(): void {
		$visits = $this->source( 'Application/Visits/VisitService.php' );
		self::assertSame( 3, substr_count( $visits, "'jalali'        => Jalali::format_display_ymd(" ) );
		self::assertStringNotContainsString( "'jalali'        => Jalali::formatYmd(", $visits );
		self::assertStringContainsString( "'date'          => \$operational_date,", $visits );

		$appointments = $this->source( 'Infrastructure/Repository/AppointmentRepository.php' );
		self::assertStringContainsString(
			'Jalali::format_display_ymd(',
			$appointments
		);
		self::assertStringContainsString( "(string) \$row['slot_date']", $appointments );
		self::assertStringContainsString(
			"'time'           => substr( (string) \$row['slot_time'], 0, 5 ),",
			$appointments
		);

		$reception = $this->source( 'Rest/ReceptionPortalController.php' );
		self::assertStringNotContainsString( 'Jalali::formatYmd', $reception );
		self::assertStringContainsString( "'jalali'        => Jalali::format_display_ymd( \$date ),", $reception );
		self::assertStringContainsString( "'time'          => substr( \$time, 0, 5 ),", $reception );
	}

	public function test_file_and_finance_display_dates_are_persian(): void {
		$files = $this->source( 'Application/Clinical/MedicalFileService.php' );
		self::assertStringContainsString(
			'$map[ (int) $row[\'id\'] ] = Jalali::format_display_ymd(',
			$files
		);
		self::assertStringNotContainsString( 'Jalali::formatYmd', $files );

		$finance = $this->source( 'Application/Finance/FinanceService.php' );
		self::assertStringContainsString(
			"'jalali_date' => Jalali::format_display_ymd(",
			$finance
		);
		self::assertStringContainsString(
			"substr((string) \$invoice['created_at'], 0, 10)",
			$finance
		);
		self::assertStringContainsString(
			"'jalali_paid_at' => Jalali::format_display_ymd(",
			$finance
		);
		self::assertStringContainsString(
			"substr((string) \$p['paid_at'], 0, 10)",
			$finance
		);
		// Receipt contract keys are untouched: only the display digits changed.
		self::assertStringContainsString( "'invoice_number' => (string) \$invoice['invoice_number'],", $finance );
		self::assertStringContainsString( "'payment_number' => (string) \$p['payment_number'],", $finance );
	}

	public function test_management_portal_template_renders_numbers_in_persian_digits(): void {
		$src = $this->template( 'management-portal-shell.php' );

		self::assertStringContainsString( 'use ClinicCore\Domain\Time\PersianDigits;', $src );
		self::assertStringContainsString(
			"PersianDigits::digits( (string) \$cpms_mp_row['location_count'] )",
			$src
		);
		self::assertStringContainsString(
			"PersianDigits::digits( (string) count( (array) \$cpms_mp_facility['sites'] ) )",
			$src
		);
		self::assertStringContainsString(
			"PersianDigits::digits( (string) count( (array) \$cpms_mp_facility['clinics'] ) )",
			$src
		);
		self::assertStringContainsString(
			"'#' . PersianDigits::digits( (int) \$cpms_mp_mid )",
			$src
		);
		self::assertStringContainsString(
			"'#' . PersianDigits::digits( (string) \$cpms_mp_manager['wp_user_id'] )",
			$src
		);
		self::assertStringContainsString(
			"'#' . PersianDigits::digits( (string) \$cpms_mp_member['wp_user_id'] )",
			$src
		);
		self::assertStringContainsString(
			"PersianDigits::digits( (string) \$cpms_mp_clinician['id'] )",
			$src
		);
		// Identifiers are never thousands-grouped (format_int would corrupt them).
		self::assertStringNotContainsString( 'PersianDigits::format_int', $src );
		// Machine attributes of the same template stay Latin.
		self::assertStringContainsString(
			'data-cpms-org="<?php echo esc_attr( (string) $cpms_mp_org_view[\'organization\'][\'id\'] ); ?>',
			$src
		);
	}

	public function test_portal_js_counters_and_file_sizes_use_persian_digits(): void {
		$doctor = $this->template( 'doctor-portal-shell.php' );

		self::assertStringContainsString(
			"var value = s[f[0]] != null ? cpmsFaDigits(s[f[0]]) : '—';",
			$doctor
		);
		self::assertStringContainsString( "return fa(n) + ' بایت';", $doctor );
		self::assertStringContainsString( "return fa(Math.round(n / 1024)) + ' کیلوبایت';", $doctor );
		self::assertStringContainsString(
			"return fa(Math.round(n / 10485.76) / 100) + ' مگابایت';",
			$doctor
		);

		$reception = $this->template( 'staff-reception.php' );
		self::assertStringContainsString(
			'node.firstElementChild.textContent = faDigits((stats && stats[key]) || 0);',
			$reception
		);
	}

	public function test_display_helpers_convert_digits_only_and_keep_machine_forms(): void {
		// Display helpers: Persian digits, same Jalali/Gregorian meaning.
		self::assertSame( '۱۴۰۵/۰۱/۰۱', Jalali::format_display_ymd( '2026-03-21' ) );
		self::assertSame( '۰۹:۳۰', PersianDigits::digits( '09:30' ) );
		// Machine forms stay available and unchanged for REST/storage values.
		self::assertSame( '1405/01/01', Jalali::formatYmd( '2026-03-21' ) );
		self::assertSame( '۱۴۰۵-۰۱-۰۱', PersianDigits::digits( '1405-01-01' ) );
		self::assertNotSame( Jalali::format_display_ymd( '2026-03-21' ), Jalali::formatYmd( '2026-03-21' ) );
		self::assertNotSame( PersianDigits::digits( '09:30' ), '09:30' );
	}
}
