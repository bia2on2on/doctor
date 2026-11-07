<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Validators\VisibleText;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * M4 — Bidirectional control characters are never legitimate in a stored
 * patient name. They render nothing (so a ZWNJ-invisible check cannot see
 * them when visible letters exist) yet let an attacker reorder/mirror the
 * displayed text (first-strong isolation, RTL embedding …).
 *
 * The shared decision point is VisibleText::has_bidi_control(); the two
 * name cleaners (PatientService::cleanName for create/update/updateMe/enroll
 * and BookingService::cleanBookingName for the OTP/booking new-patient
 * entrance — same entrance shape) must reject, never silently strip.
 */
final class VisibleTextBidiControlTest extends TestCase
{
	private const BIDI_CONTROLS = [
		"\u{061C}", // ARABIC LETTER MARK
		"\u{200E}", // LRM
		"\u{200F}", // RLM
		"\u{202A}", // LRE
		"\u{202B}", // RLE
		"\u{202C}", // PDF
		"\u{202D}", // LRO
		"\u{202E}", // RLO
		"\u{2066}", // LRI
		"\u{2067}", // RLI
		"\u{2068}", // FSI
		"\u{2069}", // PDI
	];

	public function testEveryBidiControlIsDetected(): void
	{
		foreach ( self::BIDI_CONTROLS as $control ) {
			self::assertTrue(
				VisibleText::has_bidi_control( 'علی' . $control . 'تست' ),
				'hex ' . bin2hex( $control ) . ' must be detected in an otherwise-valid name'
			);
		}
	}

	public function testBidiControlAsSuffixOrPrefixIsDetected(): void
	{
		self::assertTrue( VisibleText::has_bidi_control( "علی\u{202E}" ) );
		self::assertTrue( VisibleText::has_bidi_control( "\u{2066}Ali" ) );
		self::assertTrue( VisibleText::has_bidi_control( "\u{200F}" ) );
	}

	public function testNormalNamesHaveNoBidiControl(): void
	{
		// Persian with an inner ZWNJ, Latin, mixed — none of these are bidi controls.
		foreach ( [ 'علی‌رضا', 'می‌روم', 'Zahra Ahmadi', 'آ', 'سارا محمدی', "نیما\u{200C}طاهری" ] as $name ) {
			self::assertFalse( VisibleText::has_bidi_control( $name ), $name );
		}
	}

	public function testInvalidUtf8FailsClosedAsBidiControlPresent(): void
	{
		self::assertTrue( VisibleText::has_bidi_control( "\xC3\x28" ) );
		self::assertTrue( VisibleText::has_bidi_control( "\xFF" ) );
	}

	public function testPatientServiceRejectsBidiControlNames(): void
	{
		$service = ( new ReflectionClass( PatientService::class ) )->newInstanceWithoutConstructor();
		$method  = new ReflectionMethod( PatientService::class, 'cleanName' );

		foreach ( self::BIDI_CONTROLS as $control ) {
			try {
				$method->invoke( $service, 'علی' . $control . 'تست' );
				self::fail( 'hex ' . bin2hex( $control ) . ' must be rejected' );
			} catch ( BookingException $e ) {
				self::assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
				self::assertSame( 400, $e->httpStatus );
				self::assertSame( 'نام حاوی نویسه‌های کنترلِ جهت نوشتار است', $e->getMessage() );
			}
		}

		// Valid Persian names keep passing unchanged — never rewritten.
		self::assertSame( 'علی‌رضا', $method->invoke( $service, 'علی‌رضا' ) );
		self::assertSame( 'سارا محمدی', $method->invoke( $service, 'سارا محمدی' ) );
	}

	public function testBookingIdentityStepRejectsBidiControlNames(): void
	{
		$service = ( new ReflectionClass( BookingService::class ) )->newInstanceWithoutConstructor();
		$method  = new ReflectionMethod( BookingService::class, 'cleanBookingName' );

		try {
			$method->invoke( $service, "سارا\u{2066}محمدی" );
			self::fail( 'a bidi-control name must be rejected at the booking entrance' );
		} catch ( BookingException $e ) {
			self::assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
			self::assertSame( 400, $e->httpStatus );
		}

		self::assertSame( 'سارا محمدی', $method->invoke( $service, 'سارا محمدی' ) );
	}
}
