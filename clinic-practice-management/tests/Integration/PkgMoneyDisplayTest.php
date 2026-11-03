<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Finance\VisitPricingService;
use WP_UnitTestCase;

/**
 * Money display (Defect 11) — gates the plain-Node money spec and the PHP-side
 * contract of the surfaces it covers:
 *
 *  - `js/pkg-money-format.spec.js` executes the SHIPPED `assets/js/cpms-money.js`
 *    as a real child process (a PHP re-implementation cannot satisfy it) and
 *    checks the four surfaces are wired to it.
 *  - the pricing fee-snapshot policy is a Persian, display-ready sentence and
 *    the English one is gone (no test locked the old text beyond non-emptiness).
 *  - `pricing_context` exposes a `locations` projection built from the actor's
 *    permitted Locations, with clinic-wide 0 left to the caller.
 *
 * Nothing here asserts a REST money string: `currency`/`currency_label` and the
 * `*.00` amounts stay byte-identical (VisitPricingBillingTest owns those).
 */
final class PkgMoneyDisplayTest extends WP_UnitTestCase
{
    private const SPEC = 'tests/js/pkg-money-format.spec.js';

    private static function pluginFile(string $relative): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $relative);
    }

    /**
     * The spec must run as a real child process, so a missing node binary is the
     * only skip — and testMoneySpecAndHelperAreWired() keeps the gate honest by
     * failing when the spec/asset disappear.
     */
    public function testMoneySpecSuitePasses(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not available in this environment.');
        }

        $script = \dirname(__DIR__) . '/js/pkg-money-format.spec.js';
        self::assertFileExists($script, 'the money spec must be present');

        $output   = [];
        $exitCode = 0;
        exec($node . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        $stdout = implode("\n", $output);
        self::assertSame(0, $exitCode, "pkg-money-format.spec.js failed:\n" . $stdout);
        self::assertStringContainsString('OK:', $stdout);
        self::assertStringNotContainsString('FAIL -', $stdout, 'the money spec reported a failing assertion');
    }

    /**
     * Guard against a silent skip: the asset, the spec and the four surfaces must
     * all be wired, otherwise the gate above would pass while checking nothing.
     */
    public function testMoneySpecAndHelperAreWired(): void
    {
        $base = \dirname(__DIR__, 2);
        self::assertFileExists($base . '/assets/js/cpms-money.js');
        self::assertFileExists($base . '/' . self::SPEC);

        $helper = self::pluginFile('assets/js/cpms-money.js');
        foreach (['format', 'rial', 'number', 'digits', 'THOUSANDS_SEPARATOR', 'DECIMAL_SEPARATOR'] as $api) {
            self::assertStringContainsString($api, $helper, "cpms-money.js must expose {$api}");
        }

        $admin = self::pluginFile('src/Admin/CpmsAssets.php');
        self::assertMatchesRegularExpression('/cpms-money\.js/', $admin, 'wp-admin must load the helper');

        $portal = self::pluginFile('src/Frontend/StaffPortalShell.php');
        self::assertMatchesRegularExpression('/MONEY_HANDLE\s*=\s*\'cpms-money\'/', $portal);

        foreach (['templates/staff-pricing.php', 'templates/staff-finance-board.php'] as $template) {
            self::assertStringContainsString(
                'window.CpmsMoney',
                self::pluginFile($template),
                $template . ' must format money through the shared helper'
            );
        }

        self::assertStringContainsString('window.CpmsMoney', self::pluginFile('src/Admin/SecretaryFinancePage.php'));
        self::assertStringContainsString('window.CpmsMoney', self::pluginFile('src/Admin/SmsSettingsPage.php'));
    }

    /**
     * The displayed policy is Persian and must not drift back to the English
     * sentence shown through `fee_policy` before this change.
     */
    public function testFeeSnapshotPolicyIsPersianAndNotEmpty(): void
    {
        $policy = VisitPricingService::FEE_SNAPSHOT_POLICY;

        self::assertNotSame('', trim($policy));
        self::assertSame(
            0,
            (int) preg_match('/[A-Za-z]/', $policy),
            'the displayed fee-snapshot policy must not contain Latin letters'
        );
        self::assertStringContainsString('ویزیت', $policy);
        self::assertStringContainsString('فاکتور', $policy);
        self::assertStringNotContainsString('Captured at check-in', $policy);
        self::assertStringNotContainsString('Finalized invoices are immutable', $policy);
    }

    /**
     * `locations` is a display projection of permitted Locations: clinic-scoped,
     * active only, and intersected with the actor's Location grant when the
     * membership is Location-scoped. Clinic-wide 0 is not a Location row and is
     * therefore never part of the list.
     */
    public function testPricingContextExposesPermittedLocationsOnly(): void
    {
        $service = self::pluginFile('src/Application/Finance/VisitPricingService.php');

        self::assertMatchesRegularExpression(
            '/\'locations\'\s*=>\s*\$this->pricing_locations\(/',
            $service,
            'pricing context must expose the locations projection'
        );

        $offset = strpos($service, 'private function pricing_locations(');
        self::assertNotFalse($offset, 'pricing_locations() must exist');
        $body = substr($service, (int) $offset, 1400);

        self::assertStringContainsString('is_active = 1', $body);
        self::assertStringContainsString('clinic_id = %d', $body);
        self::assertStringContainsString('in_array( $id, $allowed, true )', $body);
        self::assertStringContainsString('\'id\' => $id', $body);
        self::assertStringContainsString('\'name\' =>', $body);
        self::assertStringNotContainsString('INSERT', $body);
        self::assertStringNotContainsString('UPDATE', $body);
    }
}
