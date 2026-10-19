<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use WP_UnitTestCase;

/**
 * Executes the REAL production `assets/js/cpms-jalali-date.js` logic (not a
 * PHP reimplementation) via a plain Node subprocess and asserts the exact
 * regression the independent review reported:
 *
 *   "Typing invalid 1405/12/30 must NEVER submit the intermediate valid
 *    1405/12/03 Gregorian equivalent."
 *
 * ...plus the supporting positive/negative cases (supported year window
 * 1300-1499, non-leap/leap Esfand-30 handling, malformed/partial shapes).
 * The actual DOM-widget wiring (never writing to the native input on every
 * keystroke, setCustomValidity() blocking submission while unconfirmed) is
 * additionally covered by the real-browser Playwright acceptance suite —
 * this test is the fast, deterministic, exact-input/output layer underneath
 * it, run against the shipped script byte-for-byte.
 */
final class CpmsJalaliDateLogicTest extends WP_UnitTestCase
{
    public function testJalaliDateScriptLogicPassesItsOwnRegressionSuite(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not available in this environment; the real-browser acceptance suite still covers the widget behaviour end to end.');
        }

        $script = \dirname(__DIR__) . '/js/cpms-jalali-date.spec.js';
        self::assertFileExists($script);

        $output     = [];
        $exitCode   = 0;
        $escaped    = escapeshellarg($script);
        exec($node . ' ' . $escaped . ' 2>&1', $output, $exitCode);

        self::assertSame(
            0,
            $exitCode,
            "cpms-jalali-date.js logic regression suite failed:\n" . implode("\n", $output)
        );
        self::assertStringContainsString('OK:', implode("\n", $output));
    }
}
