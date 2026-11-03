<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Offline python suite for the Day-2 booking-busy diagnostic sanitizer
 * (bin/day2_booking_busy.py, used by bin/pilot-day2-workflows.py).
 *
 * python3 is a hard requirement here — mirroring the node spec wrapper in
 * PersianDigitsTest — so a missing interpreter FAILS instead of skipping
 * and the allow-list sanitizer can never rot silently.
 */
final class Day2BookingBusyDiagnosticsTest extends TestCase {

	public function test_day2_booking_busy_python_suite_passes(): void {
		$python = trim( (string) shell_exec( 'command -v python3 2>/dev/null' ) );
		self::assertNotSame( '', $python, 'python3 binary must be available in CI.' );

		$suite = dirname( __DIR__ ) . '/bin/test_day2_booking_busy.py';
		self::assertFileExists( $suite );

		$output    = [];
		$exit_code = 0;
		exec( $python . ' ' . escapeshellarg( $suite ) . ' 2>&1', $output, $exit_code );

		self::assertSame(
			0,
			$exit_code,
			"test_day2_booking_busy.py failed:\n" . implode( "\n", $output )
		);
	}
}
