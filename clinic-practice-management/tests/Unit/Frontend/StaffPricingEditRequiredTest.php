<?php
/** Source + runtime gate for REQUIRED staff-pricing edit prompts. */
declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * The three edit-flow CpmsDialog.prompt calls added in PR #316 must require a
 * non-empty answer (required:true + a Persian requiredMessage) so an empty
 * answer cannot be confirmed into a PUT. Cancel must still send nothing.
 *
 * This is a NEW test file: StaffPricingDialogPaymentTest.php and
 * tests/js/staff-pricing-dialog-payment.spec.js are intentionally untouched.
 * The runtime behaviour (real shared dialog + real editCharge) is proven by
 * tests/js/staff-pricing-edit-required.spec.js, shelled out below so that a
 * missing node FAILS instead of skipping.
 */
final class StaffPricingEditRequiredTest extends TestCase
{
    public function test_edit_prompts_are_required_with_a_persian_message(): void
    {
        $root = dirname(__DIR__, 3);
        $pricing = (string) file_get_contents($root . '/templates/staff-pricing.php');
        self::assertNotSame('', $pricing);
        self::assertSame(3, substr_count($pricing, 'window.CpmsDialog.prompt('));
        self::assertSame(3, substr_count($pricing, 'required: true'));
        self::assertSame(3, substr_count($pricing, "requiredMessage: '"));
        self::assertStringContainsString(
            "requiredMessage: 'شرح خدمت الزامی است.'",
            $pricing
        );
        self::assertStringContainsString(
            "requiredMessage: 'مبلغ واحد الزامی است.'",
            $pricing
        );
        self::assertStringContainsString(
            "requiredMessage: 'تعداد الزامی است.'",
            $pricing
        );
        self::assertDoesNotMatchRegularExpression(
            '/window\.(?:confirm|prompt|alert)\s*\(/',
            $pricing
        );
        self::assertStringContainsString(
            'if (value === null || state.visitId !== visitId) return null;',
            $pricing,
            'cancel still short-circuits before any mutation'
        );
    }

    public function test_node_vm_required_spec(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        self::assertNotSame('', $node, 'node is required');
        $spec = dirname(__DIR__, 2) . '/js/staff-pricing-edit-required.spec.js';
        self::assertFileExists($spec);
        $output = [];
        $exit = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg($spec) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
    }
}
