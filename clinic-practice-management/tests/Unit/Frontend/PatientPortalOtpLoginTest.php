<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/** Source-level contract tests for the existing A2/A3 OTP-based Patient login. */
final class PatientPortalOtpLoginTest extends TestCase {

	private string $source;

	protected function setUp(): void {
		$path = dirname( __DIR__, 3 ) . '/assets/js/cpms-patient-portal.js';
		self::assertFileExists( $path );
		$this->source = (string) file_get_contents( $path );
		self::assertNotSame( '', $this->source );
	}

	public function testPatientLoginUsesOnlyTheExistingOtpRoutesAndLoginPurpose(): void {
		self::assertStringContainsString( "parsed.otp_request_path !== '/otp/request'", $this->source );
		self::assertStringContainsString( "parsed.otp_verify_path !== '/otp/verify'", $this->source );
		self::assertStringContainsString( "body: JSON.stringify({ mobile: mobile, purpose: 'login' })", $this->source );
		self::assertStringContainsString( 'body: JSON.stringify({ mobile: mobile, code: code })', $this->source );
		self::assertStringNotContainsString( 'clinic_id:', substr( $this->source, strpos( $this->source, 'function bindPatientOtpLogin()' ), 6500 ) );
	}

	public function testSessionIsReusedOnlyAfterSuccessfulOtpVerifyAndPersianDigitsAreNormalized(): void {
		self::assertStringContainsString( "credentials: 'same-origin'", substr( $this->source, strpos( $this->source, 'function bindPatientOtpLogin()' ), 6500 ) );
		self::assertStringContainsString( "payload.session_issued === true", $this->source );
		self::assertStringContainsString( 'window.location.reload();', $this->source );
		self::assertStringContainsString( '/[\\u06F0-\\u06F9]/g', $this->source );
		self::assertStringContainsString( '/[\\u0660-\\u0669]/g', $this->source );
	}
}
