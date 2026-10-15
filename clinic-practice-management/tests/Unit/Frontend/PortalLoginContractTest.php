<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/** Source-level guardrails for WordPress-native Doctor/Staff portal login. */
final class PortalLoginContractTest extends TestCase {

	private string $source;

	protected function setUp(): void {
		$path = dirname( __DIR__, 3 ) . '/src/Frontend/PortalLogin.php';
		self::assertFileExists( $path );
		$this->source = (string) file_get_contents( $path );
		self::assertNotSame( '', $this->source );
	}

	public function testUsesWordPressAuthAndChecksPortalEligibilityBeforeIssuingCookie(): void {
		self::assertStringContainsString( 'wp_authenticate( $username, $password )', $this->source );
		self::assertStringContainsString( 'wp_signon( $credentials, is_ssl() )', $this->source );
		self::assertStringContainsString( 'self::is_authorized( (int) $preauthenticated->ID, $portal )', $this->source );
		self::assertStringContainsString( 'self::is_authorized( (int) $authenticated->ID, $portal )', $this->source );
		self::assertStringContainsString( 'wp_verify_nonce( $nonce, \'cpms_portal_login_\' . $portal )', $this->source );
		self::assertStringContainsString( 'StaffPortalShell::eligible_modules( $user_id )', $this->source );
		self::assertStringContainsString( 'StaffPortalShell::doctor_module_eligible( $user_id )', $this->source );
	}

	public function testDoesNotAcceptUserControlledRedirectOrIssueSessionOnDeniedRole(): void {
		self::assertStringNotContainsString( '$_POST[\'redirect_to\']', $this->source );
		self::assertStringNotContainsString( 'wp_login_url(', $this->source );
		self::assertStringContainsString( 'in_array( RolesAndCapabilities::ROLE_PATIENT, (array) $user->roles, true )', $this->source );
		self::assertStringContainsString( "wp_logout();", $this->source );
		self::assertStringContainsString( 'wp_safe_redirect( self::portal_url( $portal ) )', $this->source );
	}
}
