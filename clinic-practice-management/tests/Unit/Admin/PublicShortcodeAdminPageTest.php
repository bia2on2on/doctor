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
		// B-1: this helper is no longer a global Tools entry; it is registered
		// beneath the single CPMS menu and keeps its own manage_options gate.
		self::assertStringNotContainsString( 'add_management_page(', $this->source );
		self::assertStringContainsString( 'add_submenu_page(', $this->source );
		self::assertStringContainsString( 'CpmsAdminMenu::parent_slug()', $this->source );
		self::assertStringContainsString( "'manage_options'", $this->source );
		self::assertStringContainsString( 'active_memberships_for_user', $this->source );
		self::assertStringContainsString( 'RolesAndCapabilities::CONFIG', $this->source );
		self::assertStringContainsString( 'array_intersect( $known_cpms_tags, $registered )', $this->source );
		self::assertStringContainsString( 'PublicBookingShortcode::TAG', $this->source );
		self::assertStringNotContainsString( 'register_rest_route(', $this->source );
	}

	/**
	 * رگرسیونِ واقعیِ Real WP Acceptance: فرم با GET ارسال می‌شود و اگر action به
	 * مسیرِ legacy (`tools.php`) اشاره کند، redirectِ سازگاری باید `clinic_id` را
	 * حفظ کند — وگرنه صفحه با انتخابِ خالی برمی‌گردد و کد کوتاه تولید نمی‌شود.
	 */
	public function testFormPostsBackToTheCanonicalPageAndKeepsTheClinicSelector(): void {
		self::assertStringContainsString(
			"admin_url( 'admin.php?page=' . self::PAGE_SLUG )",
			$this->source,
			'form action must be the canonical CPMS page URL'
		);
		self::assertStringNotContainsString( "admin_url( 'tools.php' )", $this->source );
		self::assertStringContainsString( 'type="hidden" name="page"', $this->source );
	}

	public function testGeneratedValueIsClinicScopedAndPageDoesNotCreateContent(): void {
		self::assertStringContainsString( 'sprintf( \'[%s clinic_id="%d"]\', $tag, $clinic_id )', $this->source );
		self::assertStringContainsString( 'if ( (int) $choice[\'clinic_id\'] === $requested_clinic_id )', $this->source );
		self::assertStringNotContainsString( 'wp_insert_post(', $this->source );
		self::assertStringNotContainsString( '$_POST', $this->source );
		self::assertStringContainsString( 'data-cpms-copy', $this->source );
		self::assertStringContainsString( 'document.execCommand(\'copy\')', $this->source );
	}

	/**
	 * The real-WordPress acceptance script treats `[data-cpms-shortcode]` and
	 * `[data-cpms-copy]` as SINGLE targets (strict locator + count == 1). The
	 * discovery example added for Phase 8 Slice 4 must therefore keep its own
	 * distinct attributes, and each copy button must resolve its own field.
	 */
	public function testDiscoveryExampleKeepsTheSingleShortcodeFieldContract(): void {
		self::assertStringContainsString( 'data-cpms-shortcode-discovery', $this->source );
		self::assertStringContainsString( 'data-cpms-copy-discovery', $this->source );
		self::assertStringContainsString( "hasAttribute('data-cpms-copy-discovery')", $this->source );
		self::assertStringContainsString( "'[data-cpms-shortcode' + suffix + ']'", $this->source );
		// Exactly one <label> may point at the single-Clinic textarea, otherwise the
		// control has two accessible names.
		self::assertSame(
			1,
			preg_match_all( '/for="cpms-shortcode-<\\?php echo esc_attr\\( \\$tag \\); \\?>"/', $this->source ),
			'one label must reference the single-Clinic shortcode field'
		);
	}

	public function testClinicMustBeSelectedExplicitlyEvenForSingleMembership(): void {
		self::assertStringContainsString( '<option value="">انتخاب Clinic</option>', $this->source );
		self::assertStringContainsString( 'Clinic را صریحاً انتخاب کنید؛ هیچ Clinic پیش‌فرضی حدس زده نمی‌شود.', $this->source );
		self::assertStringNotContainsString( '1 === count( $clinic_choices )', $this->source );
	}
}
