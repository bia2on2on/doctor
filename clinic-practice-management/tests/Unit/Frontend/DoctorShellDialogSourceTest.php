<?php
/**
 * Source gate for the two converted Doctor Portal dialog sites.
 *
 * The skip-reason prompt (`doQueueAction`) and the finish-visit confirmation
 * (`submitWorkspaceComplete`) must go through the shared in-page dialog with
 * fail-closed behaviour. A bare native `confirm(`/`prompt(` (or a
 * `window.*` native call) in either site would silently reintroduce the browser
 * dialog the shells no longer answer natively, and would let an unavailable
 * dialog mean "confirmed" instead of cancel — so this test fails on it.
 *
 * Pure source inspection: no WP, no DB, no browser (mirrors
 * PublicBookingJsSendGateTest). The rendered-head contract is proven by
 * ClinicCore\Tests\Integration\PkgDoctorStaffShellDialogTest, and the runtime
 * behaviour by tests/js/doctor-shell-dialog.spec.js.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class DoctorShellDialogSourceTest extends TestCase
{
    private const DOCTOR_SHELL = 'templates/doctor-portal-shell.php';
    private const STAFF_SHELL  = 'templates/staff-portal-shell.php';
    private const DOCTOR_JS    = 'assets/js/cpms-doctor-portal.js';

    /** Regex for a BARE native call; a `CpmsDialog.confirm(` is NOT a match. */
    private const BARE_NATIVE = '/(?<![A-Za-z0-9_$.])(?:confirm|prompt|alert)\s*\(/';

    private function pluginFile(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        self::assertFileExists($path, $rel . ' must exist');
        $src = (string) file_get_contents($path);
        self::assertNotSame('', $src, $rel . ' must not be empty');
        return $src;
    }

    /** Slice out one top-level (column-0) function, up to the next one. */
    private function functionBody(string $src, string $name): string
    {
        $start = strpos($src, "\nfunction " . $name . '(');
        self::assertNotFalse($start, 'function ' . $name . ' must exist');
        $end = strpos($src, "\nfunction ", $start + 1);
        self::assertNotFalse($end, 'function ' . $name . ' must be followed by another');
        return substr($src, $start, $end - $start);
    }

    public function testNoNativeDialogCallRemainsInTheConvertedSites(): void
    {
        $src = $this->pluginFile(self::DOCTOR_SHELL);

        // Whole-file guard: the two native sites were the only ones in this shell.
        self::assertStringNotContainsString('window.confirm(', $src);
        self::assertStringNotContainsString('window.prompt(', $src);
        self::assertStringNotContainsString('window.alert(', $src);

        $skip     = $this->functionBody($src, 'doQueueAction');
        $complete = $this->functionBody($src, 'submitWorkspaceComplete');

        $sites = ['doQueueAction' => $skip, 'submitWorkspaceComplete' => $complete];
        foreach ($sites as $label => $body) {
            $bare = [];
            preg_match_all(self::BARE_NATIVE, $body, $bare);
            self::assertSame(
                [],
                $bare[0],
                $label . ' must not contain a bare native confirm/prompt/alert call'
            );
        }

        // Each converted site carries its OWN CpmsDialog path and its OWN
        // fail-closed default (null for a prompt, false for a confirm).
        self::assertStringContainsString(
            'window.CpmsDialog.prompt(', $skip, 'skip must use the shared prompt'
        );
        self::assertStringContainsString(
            'Promise.resolve(null)', $skip, 'skip must fail closed with null'
        );
        self::assertStringContainsString(
            'window.CpmsDialog.confirm(', $complete,
            'complete must use the shared confirm'
        );
        self::assertStringContainsString(
            'Promise.resolve(false)', $complete,
            'complete must fail closed with false'
        );
    }

    /**
     * The established Persian wording is unchanged, and the locked Phase 10
     * "explicit confirmation before Complete" window still contains `confirm(`.
     */
    public function testPersianWordingAndLockedCompleteWindowArePreserved(): void
    {
        $src      = $this->pluginFile(self::DOCTOR_SHELL);
        $skip     = $this->functionBody($src, 'doQueueAction');
        $complete = $this->functionBody($src, 'submitWorkspaceComplete');

        self::assertStringContainsString('علت رد کردن را بنویسید:', $skip);
        self::assertStringContainsString('علت رد کردن الزامی است.', $skip);
        self::assertStringContainsString(
            'پایان این ویزیت ثبت شود؟ ویزیت از صف زنده خارج می‌شود.',
            $complete
        );

        $ui  = $src . "\n" . $this->pluginFile(self::DOCTOR_JS);
        $pos = strpos($ui, '/complete');
        self::assertNotFalse($pos, 'the Complete REST wiring must remain');
        $window = substr($ui, max(0, $pos - 1500), 4500);
        self::assertStringContainsString(
            'confirm(', $window, 'explicit confirmation must remain near /complete'
        );
        self::assertStringContainsString('submittedVisitId', $window);
        self::assertStringContainsString('loadTodayAndQueue', $window);
    }

    /** Both shells print the shared handles inside <head>, before inline scripts. */
    public function testBothShellsRegisterAndPrintTheDialogHandlesInHead(): void
    {
        $load = 'wp_print_scripts( [ StaffPortalShell::UI_TEXT_HANDLE, '
            . 'StaffPortalShell::DIALOG_HANDLE ] )';
        foreach ([self::DOCTOR_SHELL, self::STAFF_SHELL] as $rel) {
            $shell = $this->pluginFile($rel);
            $head  = strpos($shell, '</head>');
            self::assertNotFalse($head, $rel . ' must render a </head>');
            $print = strpos($shell, $load);
            self::assertNotFalse($print, $rel . ' must print the dialog handles');
            self::assertLessThan($head, $print, $rel . ' must print them in <head>');
            self::assertStringContainsString(
                'StaffPortalShell::register_ui_handles();',
                $shell,
                $rel . ' must register the handles first'
            );
        }
    }
}
