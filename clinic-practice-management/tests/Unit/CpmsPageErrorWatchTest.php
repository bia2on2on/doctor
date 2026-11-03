<?php
/**
 * Offline gate for the unfiltered browser-error watch (owner defect 12).
 *
 * Evidence that motivated this gate: the "JavaScript error related to
 * serialize" report was never reproduced with an UNFILTERED page-error
 * listener. The one production context that navigates to wp-admin/profile.php
 * as a shared role — the Accountant journey in bin/pilot-finance-board.py —
 * carried no pageerror/console listener at all, and every other gate decides by
 * page URL or by message, so a core TypeError there could stay invisible.
 *
 * Locks:
 *  1. bin/cpms_page_error_watch.py passes its offline python suite: no message
 *     is dropped, nothing is decided by the word "serialize", and only a source
 *     frame under the plugin path fails the step.
 *  2. The production harness really attaches that watch to the page that visits
 *     wp-admin/profile.php and really reports it (a stub could not fail).
 *
 * A missing python3 binary is a FAIL, never a skip (the mechanism of
 * Day2BookingBusyDiagnosticsTest / PkgDoctorStaffShellDialogTest).
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CpmsPageErrorWatchTest extends TestCase {

	private const HARNESS = 'bin/pilot-finance-board.py';
	private const WATCHER = 'bin/cpms_page_error_watch.py';

	private function plugin_file( string $rel ): string {
		$path = dirname( __DIR__, 2 ) . '/' . $rel;
		self::assertFileExists( $path, $rel . ' must exist' );
		$src = (string) file_get_contents( $path );
		self::assertNotSame( '', $src, $rel . ' must not be empty' );
		return $src;
	}

	/** python3 is a hard requirement: a missing interpreter FAILS instead of skipping. */
	public function test_unfiltered_error_watch_python_suite_passes(): void {
		$python = trim( (string) shell_exec( 'command -v python3 2>/dev/null' ) );
		self::assertNotSame( '', $python, 'python3 binary must be available in CI.' );

		$suite = dirname( __DIR__ ) . '/bin/test_cpms_page_error_watch.py';
		self::assertFileExists( $suite );

		$output    = [];
		$exit_code = 0;
		exec( $python . ' ' . escapeshellarg( $suite ) . ' 2>&1', $output, $exit_code );

		self::assertSame(
			0,
			$exit_code,
			"test_cpms_page_error_watch.py failed:\n" . implode( "\n", $output )
		);
	}

	/** The watch must be attached to the profile.php context itself. */
	public function test_production_harness_attaches_the_watch_to_the_profile_journey(): void {
		$harness = $this->plugin_file( self::HARNESS );

		self::assertStringContainsString(
			'page.goto(BASE + "/wp-admin/profile.php"',
			$harness,
			'the Accountant journey must keep visiting wp-admin/profile.php'
		);
		self::assertStringContainsString(
			'attach_error_watch(page, watched_errors)',
			$harness,
			'the unfiltered listener pair must be attached to that page'
		);
		self::assertStringContainsString(
			'report_error_watch(watched_errors, "accountant-journey")',
			$harness,
			'the captured errors must be reported, not just collected'
		);
		self::assertStringContainsString(
			'require(False, watched_failure)',
			$harness,
			'a plugin-owned error must fail the step'
		);
	}

	/** The plugin path is the only thing that may fail the step. */
	public function test_watcher_fails_only_for_plugin_owned_sources(): void {
		$watcher = $this->plugin_file( self::WATCHER );

		self::assertStringContainsString(
			'PLUGIN_ASSETS_PREFIX = "/wp-content/plugins/clinic-practice-management/"',
			$watcher
		);
		self::assertStringContainsString( 'FAIL {label} plugin-owned error:', $watcher );
		self::assertStringContainsString( 'INFO {label} non-plugin error (does not fail this step)', $watcher );
	}
}
