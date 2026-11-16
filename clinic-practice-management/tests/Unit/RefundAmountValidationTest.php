<?php
/** Node-backed client validation contract for finance amounts. */
declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RefundAmountValidationTest extends TestCase {

	public function test_refund_amount_validation_spec_passes_with_node(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		self::assertNotSame( '', $node, 'node binary must be available; this test must not skip.' );
		$spec = dirname( __DIR__ ) . '/js/refund-amount-validation.spec.js';
		self::assertFileExists( $spec );
		$output    = [];
		$exit_code = 0;
		exec( $node . ' ' . escapeshellarg( $spec ) . ' 2>&1', $output, $exit_code );
		self::assertSame( 0, $exit_code, "refund-amount-validation.spec.js failed:\n" . implode( "\n", $output ) );
	}
}
