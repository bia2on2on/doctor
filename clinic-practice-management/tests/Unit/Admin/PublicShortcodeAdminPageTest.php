<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** Source-level contract tests for the read-only public-shortcode helper. */
final class PublicShortcodeAdminPageTest extends TestCase {

	private string $source;

	protected function setUp(): void {
		$path = dirname( __DIR__, 3 ) . '/src/Admin/PublicShortcodeAdminPage.php';
		self::assertFileExists( $path );
		$this->source = (string) file_get_contents( $path );
		self::assertNotSame( '', $this->source );
	}

	public function testPageIsManageOptionsOnlyAndListsOnlyAuthorizedClinicChoices(): void {
		self::assertStringContainsString( 'add_management_page(', $this->source );
		self::assertStringContainsString( "'manage_options'", $this->source );
		self::assertStringContainsString( 'active_memberships_for_user', $this->source );
		self::assertStringContainsString( 'RolesAndCapabilities::CONFIG', $this->source );
		self::assertStringContainsString( 'array_intersect( $knownCpmsTags, $registered )', $this->source );
		self::assertStringContainsString( 'PublicBookingShortcode::TAG', $this->source );
		self::assertStringNotContainsString( 'register_rest_route(', $this->source );
	}

	public function testGeneratedValueIsClinicScopedAndPageDoesNotCreateContent(): void {
		self::assertStringContainsString( 'sprintf( \'[%s clinic_id="%d"]\', $tag, $clinicId )', $this->source );
		self::assertStringContainsString( 'if ( (int) $choice[\'clinic_id\'] === $requestedClinicId )', $this->source );
		self::assertStringNotContainsString( 'wp_insert_post(', $this->source );
		self::assertStringNotContainsString( '$_POST', $this->source );
		self::assertStringContainsString( 'data-cpms-copy', $this->source );
		self::assertStringContainsString( 'document.execCommand(\'copy\')', $this->source );
	}
}
