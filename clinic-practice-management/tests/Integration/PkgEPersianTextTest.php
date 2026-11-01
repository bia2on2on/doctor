<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use WP_UnitTestCase;

/**
 * Package E — runs the plain-Node regression specs that execute the SHIPPED
 * assets and grep the production templates/pages for the regressions this
 * package fixes:
 *
 *  - js/pkg-e-texts.spec.js            → `cpms-ui-text.js` (doctor title,
 *    Persian digits) driven by the SAME fixture the PHP unit test reads, plus
 *    template/page checks (raw enum fallbacks, MRN labels, SMS headers, aria).
 *  - js/pkg-e-dialog-behaviour.spec.js → `cpms-dialog.js` + the `cpms-admin.js`
 *    confirm binding against a hand-written DOM stub: Escape/Enter, focus
 *    return, Tab trap both ways, one-live-dialog (D3) and the fail-closed native
 *    fallback (D2).
 *
 * Neither spec needs WordPress or the network; they are shelled out to from here
 * so the same assertions gate the WP integration job. A missing node binary is
 * the only skip, and testBothSpecsAndTheSharedFixtureArePresent guarantees the
 * gate cannot pass while the specs are absent.
 */
final class PkgEPersianTextTest extends WP_UnitTestCase
{
    /**
     * Every plain-Node spec this package owns. They are executed as REAL child
     * processes so the assertions cannot be satisfied by a PHP reimplementation.
     *
     * @return array<string, string> label => relative path
     */
    private static function specs(): array
    {
        return [
            'pkg-e-texts.spec.js'             => 'js/pkg-e-texts.spec.js',
            'pkg-e-dialog-behaviour.spec.js'  => 'js/pkg-e-dialog-behaviour.spec.js',
        ];
    }

    public static function specProvider(): array
    {
        $cases = [];
        foreach (self::specs() as $label => $rel) {
            $cases[$label] = [$label, $rel];
        }

        return $cases;
    }

    /**
     * @dataProvider specProvider
     */
    public function testPkgESpecSuitePasses(string $label, string $relative): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not available in this environment.');
        }

        $script = \dirname(__DIR__) . '/' . $relative;
        self::assertFileExists($script, "{$label} is missing");

        $output   = [];
        $exitCode = 0;
        exec($node . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        $stdout = implode("\n", $output);
        self::assertSame(
            0,
            $exitCode,
            "{$label} failed:\n" . $stdout
        );
        self::assertStringContainsString('OK:', $stdout);
        self::assertStringNotContainsString('FAIL -', $stdout, "{$label} reported a failing assertion");
    }

    /**
     * Guard against a silent skip: both specs must be wired here, and the shared
     * PHP/JS fixture must exist, otherwise this test would pass while checking
     * nothing.
     */
    public function testBothSpecsAndTheSharedFixtureArePresent(): void
    {
        $base = \dirname(__DIR__);
        foreach (self::specs() as $label => $relative) {
            self::assertFileExists($base . '/' . $relative, "{$label} must be wired into this gate");
        }
        self::assertFileExists($base . '/fixtures/doctor-display-name-vectors.json');

        $fixture = json_decode((string) file_get_contents($base . '/fixtures/doctor-display-name-vectors.json'), true);
        self::assertIsArray($fixture);
        self::assertGreaterThanOrEqual(20, count($fixture['vectors'] ?? []));
    }
}
