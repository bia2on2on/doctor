<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/** Run the patient visit display spec; Node is required, never skipped. */
final class PatientVisitsDisplayJsTest extends TestCase {

	public function test_patient_visit_date_display(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		self::assertNotSame( '', $node, 'Node is required, not an optional skip.' );

		$spec = dirname( __DIR__, 2 ) . '/js/patient-visits-display.spec.js';
		self::assertFileExists( $spec );
		$output = [];
		$exit   = 0;
		exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $spec ) . ' 2>&1', $output, $exit );
		self::assertSame( 0, $exit, implode( "\n", $output ) );
		self::assertStringContainsString( 'OK: patient visit dates display Persian digits', implode( "\n", $output ) );
	}
}
