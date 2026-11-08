<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Domain\Booking\BookingException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * M4 follow-up (independent review, round 29): the new-patient booking
 * entrance — BookingService::requireNewPatientNames — requires BOTH names
 * when no active patient exists for the hold's clinic. It shares the same
 * Persian/ZWNJ preservation and the same empty/invisible/bidi rejection
 * guarantees as PatientService::cleanName.
 *
 * This is the boundary that protects the booking flow from creating a
 * nameless or spoofed new patient record. BookingService is pure PHP on
 * this path (no WP dependencies), so reflection without the constructor is
 * a faithful unit boundary. No authorization decision is exercised here by
 * design — that boundary lives upstream (hold/session authorization) and is
 * covered by the booking integration suites.
 */
final class BookingNewPatientBoundaryTest extends TestCase
{
	private static function newService(): BookingService
	{
		return ( new ReflectionClass( BookingService::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * @return array{first_name: string, last_name: string}
	 */
	private static function invokeNames( ?string $first, ?string $last ): array
	{
		$method = new ReflectionMethod( BookingService::class, 'requireNewPatientNames' );

		/** @var array{first_name: string, last_name: string} $result */
		$result = $method->invoke( self::newService(), $first, $last );

		return $result;
	}

	public function testAcceptsPersianNamesContainingZwnjExactly(): void
	{
		$out = self::invokeNames( "علی\u{200C}رضا", "طاهری\u{200C}زاده" );

		self::assertSame( "علی\u{200C}رضا", $out['first_name'] );
		self::assertSame( "طاهری\u{200C}زاده", $out['last_name'] );
	}

	public function testAcceptsTrimmedNamesButNeverRewritesInteriorContent(): void
	{
		$out = self::invokeNames( "  علی\u{200C}رضا  ", "  طاهری  " );

		self::assertSame( "علی\u{200C}رضا", $out['first_name'] );
		self::assertSame( 'طاهری', $out['last_name'] );
	}

	public function testRejectsBidiControlInFirstName(): void
	{
		try {
			self::invokeNames( "علی\u{2066}رضا", 'تست' ); // LRI
			self::fail( 'a bidi control character in the first name must be rejected' );
		} catch ( BookingException $e ) {
			self::assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
			self::assertSame( 400, $e->httpStatus );
			self::assertSame( 'نام حاوی نویسه‌های کنترلِ جهت نوشتار است', $e->getMessage() );
		}
	}

	public function testRejectsBidiControlInLastName(): void
	{
		try {
			self::invokeNames( 'علی', "تست\u{202E}" ); // RLO
			self::fail( 'a bidi control character in the last name must be rejected' );
		} catch ( BookingException $e ) {
			self::assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
			self::assertSame( 400, $e->httpStatus );
			self::assertSame( 'نام حاوی نویسه‌های کنترلِ جهت نوشتار است', $e->getMessage() );
		}
	}

	public function testRejectsInvisibleOnlyLastName(): void
	{
		try {
			self::invokeNames( 'علی', "\u{200C}\u{200D}\u{2060}" ); // ZWNJ + ZWJ + WORD JOINER — no visible content
			self::fail( 'an invisible-only last name must be rejected' );
		} catch ( BookingException $e ) {
			self::assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
			self::assertSame( 400, $e->httpStatus );
			self::assertSame(
				'نام نمی‌تواند فقط از نویسه‌های نامرئی یا فاصله تشکیل شده باشد',
				$e->getMessage()
			);
		}
	}

	public function testRejectsWhitespaceOnlyLastName(): void
	{
		try {
			self::invokeNames( 'علی', " \t " );
			self::fail( 'a whitespace-only last name must be rejected' );
		} catch ( BookingException $e ) {
			self::assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
			self::assertSame( 400, $e->httpStatus );
			self::assertSame( 'برای ثبت بیمار جدید، نام و نام خانوادگی الزامی است', $e->getMessage() );
		}
	}

	public function testRejectsNullFirstName(): void
	{
		try {
			self::invokeNames( null, 'طاهری' );
			self::fail( 'a missing first name must be rejected at the new-patient gate' );
		} catch ( BookingException $e ) {
			self::assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
			self::assertSame( 400, $e->httpStatus );
			self::assertSame( 'برای ثبت بیمار جدید، نام و نام خانوادگی الزامی است', $e->getMessage() );
		}
	}
}
