<?php
/** Source and runtime gate for staff pricing dialogs and payment display. */
declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class StaffPricingDialogPaymentTest extends TestCase
{
    public function test_sources_use_shared_dialog_and_display_formatter(): void
    {
        $root = dirname(__DIR__, 3);
        $pricing = (string) file_get_contents($root . '/templates/staff-pricing.php');
        $finance = (string) file_get_contents($root . '/templates/staff-finance-board.php');
        self::assertDoesNotMatchRegularExpression('/window\.(?:confirm|prompt|alert)\s*\(/', $pricing);
        self::assertStringContainsString('window.CpmsDialog.confirm(', $pricing);
        self::assertSame(3, substr_count($pricing, 'window.CpmsDialog.prompt('));
        self::assertStringContainsString("'پرداخت ' + rial(amount) + ' ثبت شد.'", $finance);
        self::assertStringContainsString('var body = { amount: amount, method: method };', $finance);
    }

    public function test_node_vm_spec(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        self::assertNotSame('', $node, 'node is required');
        $spec = dirname(__DIR__, 2) . '/js/staff-pricing-dialog-payment.spec.js';
        self::assertFileExists($spec);
        $output = [];
        $exit = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg($spec) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
    }
}
