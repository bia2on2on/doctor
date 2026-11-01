<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use WP_UnitTestCase;

/**
 * Package E — runs the plain-Node regression spec that executes the SHIPPED
 * assets (`assets/js/cpms-ui-text.js`, `assets/js/cpms-dialog.js`) and greps
 * the production templates/pages for the Persian-UI regressions this package
 * fixes (raw enum fallbacks, MRN labels, English SMS headers, stale finance
 * hint, glued Persian words).
 *
 * The spec itself needs no WordPress and no network; it is shelled out to from
 * here so the same assertions gate the WP integration job.
 */
final class PkgEPersianTextTest extends WP_UnitTestCase
{
    public function testPkgEPersianTextSpecSuitePasses(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not available in this environment.');
        }

        $script = \dirname(__DIR__) . '/js/pkg-e-texts.spec.js';
        self::assertFileExists($script);

        $output   = [];
        $exitCode = 0;
        exec($node . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        self::assertSame(
            0,
            $exitCode,
            "pkg-e-texts.spec.js failed:\n" . implode("\n", $output)
        );
        self::assertStringContainsString('OK:', implode("\n", $output));
    }
}
