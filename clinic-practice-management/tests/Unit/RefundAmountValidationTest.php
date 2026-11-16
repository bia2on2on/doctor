<?php
/**
 * Refund dialog amount validation — PHPUnit wrapper for the Node spec.
 *
 * The spec (tests/js/refund-amount-validation.spec.js) exercises the
 * `validateRefundAmount` function extracted from the real
 * `SecretaryFinancePage.php` inline script.
 *
 * A missing `node` binary is a FAIL, never a skip (mirrors the pattern in
 * AdminBusyLockTest / PkgDoctorStaffShellDialogTest / StaffPricingDialogPaymentTest).
 *
 * @package ClinicCore\Tests\Unit
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RefundAmountValidationTest extends TestCase
{
    private const SPEC_JS = 'js/refund-amount-validation.spec.js';

    private function pluginFile(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path, $rel . ' must exist');
        $src = (string) file_get_contents($path);
        self::assertNotSame('', $src, $rel . ' must not be empty');
        return $src;
    }

    /** Node gate for the refund amount validation spec — missing node = FAIL, not skip. */
    public function test_refund_amount_validation_spec_passes_with_node(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        self::assertNotSame('', $node, 'node binary must be available in CI.');

        // Spec is at tests/js/refund-amount-validation.spec.js relative to plugin root
        // PHPUnit runs from plugin root (clinic-practice-management/) due to CI working-directory
        $spec = 'tests/js/refund-amount-validation.spec.js';
        self::assertFileExists($spec);

        $output    = [];
        $exit_code = 0;
        exec($node . ' ' . escapeshellarg($spec) . ' 2>&1', $output, $exit_code);

        self::assertSame(
            0,
            $exit_code,
            "refund-amount-validation.spec.js failed:\n" . implode("\n", $output)
        );
    }

    /** Guard against a silent pass: the spec must load the real shipped page. */
    public function test_spec_is_wired_to_secretary_finance_page(): void
    {
        $spec = (string) file_get_contents(dirname(__DIR__) . '/' . self::SPEC_JS);

        self::assertStringContainsString(
            'src/Admin/SecretaryFinancePage.php',
            $spec,
            'SecretaryFinancePage.php must be exercised by the spec'
        );
    }

    /** The page must keep its validateMoneyAmount function and use it in all money flows. */
    public function test_page_keeps_validate_money_amount_and_uses_it(): void
    {
        $finance = $this->pluginFile('src/Admin/SecretaryFinancePage.php');

        // The core validation function must exist
        self::assertStringContainsString(
            'function validateMoneyAmount(',
            $finance,
            'SecretaryFinancePage must define validateMoneyAmount function'
        );

        // The refund dialog handler must call validateRefundAmount (wrapper)
        self::assertStringContainsString(
            'validateRefundAmount(',
            $finance,
            'refund handler must call validateRefundAmount'
        );

        // Other money handlers must call validateMoneyAmount
        self::assertStringContainsString(
            'validateMoneyAmount(',
            $finance,
            'money handlers must call validateMoneyAmount'
        );

        // The handler must check the result and show error if invalid
        self::assertStringContainsString(
            'validation.ok',
            $finance,
            'handlers must check validation .ok'
        );
        self::assertStringContainsString(
            'validation.error',
            $finance,
            'handlers must use validation error for invalid input'
        );
        self::assertStringContainsString(
            'validation.blank',
            $finance,
            'handlers must check validation blank for omitted amount'
        );
        self::assertStringContainsString(
            'validation.value',
            $finance,
            'handlers must use validation value for valid input'
        );
    }
}