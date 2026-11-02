<?php
/**
 * Integration bridge running `tests/js/pkg-p1-jalali-conversion.spec.js`
 * against the real `assets/js/cpms-jalali-date.js` script via `node`.
 *
 * @package ClinicCore\Tests\Integration
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use WP_UnitTestCase;

/**
 * Fails (does not skip) if `node` is unavailable in CI, matching
 * `PkgP1JalaliAssetsTest::test_dynamic_jalali_date_js_spec_passes`.
 */
final class PkgP1JalaliConversionIntegrationTest extends WP_UnitTestCase {

	public function test_jalali_conversion_js_spec_passes(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		self::assertNotSame( '', $node, 'node binary must be available in CI.' );

		$spec = dirname( __DIR__ ) . '/js/pkg-p1-jalali-conversion.spec.js';
		self::assertFileExists( $spec );

		$output    = [];
		$exit_code = 0;
		exec( $node . ' ' . escapeshellarg( $spec ) . ' 2>&1', $output, $exit_code );

		self::assertSame(
			0,
			$exit_code,
			"pkg-p1-jalali-conversion.spec.js failed:\n" . implode( "\n", $output )
		);
	}
}
