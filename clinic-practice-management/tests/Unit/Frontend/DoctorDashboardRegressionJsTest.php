<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/** The dashboard regression spec requires Node; an absent binary is a failure. */
final class DoctorDashboardRegressionJsTest extends TestCase {

	public function test_node_dashboard_regressions(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		self::assertNotSame( '', $node, 'Node is required, not an optional skip.' );

		$spec = dirname( __DIR__, 2 ) . '/js/doctor-dashboard-regressions.spec.js';
		self::assertFileExists( $spec );
		$output = [];
		$exit   = 0;
		exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $spec ) . ' 2>&1', $output, $exit );
		self::assertSame( 0, $exit, implode( "\n", $output ) );
	}
}
