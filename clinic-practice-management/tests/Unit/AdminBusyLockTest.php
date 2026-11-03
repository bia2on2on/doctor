<?php
/**
 * The four cpms admin write pages must lock their action button until the
 * request settles (owner defect 9 leftovers — double-submit window).
 *
 * Evidence that motivated this gate: DoctorDashboardPage, SecretaryFinancePage,
 * SecretaryQueuePage and SmsSettingsPage each issue a write request from a
 * persistent button without disabling it, so a second click could start a
 * second in-flight POST.
 *
 * Locks:
 *  1. tests/js/admin-busy-lock.spec.js passes in Node against the REAL shipped
 *     inline code extracted from those pages (disable before settle, release on
 *     fulfillment AND rejection, unchanged request path/body/message).
 *  2. Every page still carries its own lock statements — a stub spec could not
 *     fail, and a silently removed guard must not stay green.
 *
 * A missing node binary is a FAIL, never a skip (the mechanism of
 * PkgDoctorStaffShellDialogTest / StaffPricingDialogPaymentTest).
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminBusyLockTest extends TestCase {

	private const SPEC_JS = 'js/admin-busy-lock.spec.js';

	private function plugin_file( string $rel ): string {
		$path = dirname( __DIR__, 2 ) . '/' . $rel;
		self::assertFileExists( $path, $rel . ' must exist' );
		$src = (string) file_get_contents( $path );
		self::assertNotSame( '', $src, $rel . ' must not be empty' );
		return $src;
	}

	/** Node gate for the busy-lock spec — missing node = FAIL, not skip. */
	public function test_admin_busy_lock_spec_passes_with_node(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		self::assertNotSame( '', $node, 'node binary must be available in CI.' );

		$spec = dirname( __DIR__ ) . '/' . self::SPEC_JS;
		self::assertFileExists( $spec );

		$output    = [];
		$exit_code = 0;
		exec( $node . ' ' . escapeshellarg( $spec ) . ' 2>&1', $output, $exit_code );

		self::assertSame(
			0,
			$exit_code,
			"admin-busy-lock.spec.js failed:\n" . implode( "\n", $output )
		);
	}

	/** Guard against a silent pass: the spec must load the real shipped pages. */
	public function test_spec_is_wired_to_the_shipped_pages(): void {
		$spec = (string) file_get_contents( dirname( __DIR__ ) . '/' . self::SPEC_JS );

		foreach (
			[
				'src/Admin/DoctorDashboardPage.php',
				'src/Admin/SecretaryFinancePage.php',
				'src/Admin/SecretaryQueuePage.php',
				'src/Admin/SmsSettingsPage.php',
			] as $page
		) {
			self::assertStringContainsString( $page, $spec, $page . ' must be exercised by the spec' );
		}
	}

	/** Each page keeps its own guard: no payload or message was swapped. */
	public function test_each_page_keeps_its_busy_lock_statements(): void {
		$dashboard = $this->plugin_file( 'src/Admin/DoctorDashboardPage.php' );
		self::assertStringContainsString( 'lockTrigger(btn);', $dashboard );
		self::assertStringContainsString( 'request.then(releaseTrigger, releaseTrigger);', $dashboard );

		$finance = $this->plugin_file( 'src/Admin/SecretaryFinancePage.php' );
		self::assertStringContainsString(
			"var submitBtn = document.getElementById('cpms-fin-issue-submit');",
			$finance
		);
		self::assertStringContainsString( 'issueRequest.then(releaseIssue, releaseIssue);', $finance );
		self::assertStringContainsString( "api('POST', 'invoices', body)", $finance );

		$queue = $this->plugin_file( 'src/Admin/SecretaryQueuePage.php' );
		self::assertStringContainsString( 'function doPost(path, body, okMsg, btn) {', $queue );
		self::assertStringContainsString(
			".then(release, release);",
			$queue,
			'the checkout trigger must be released on both settle paths'
		);

		$sms = $this->plugin_file( 'src/Admin/SmsSettingsPage.php' );
		self::assertStringContainsString( "var saveBtn = el('cpms-sms-save');", $sms );
		self::assertStringContainsString( 'chainDone.then(releaseSave, releaseSave);', $sms );
		self::assertStringContainsString( "api('POST', 'settings', body)", $sms );
	}
}
