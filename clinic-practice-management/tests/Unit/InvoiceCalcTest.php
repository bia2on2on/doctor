<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Finance\InvoiceCalc;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TP-18 (بخش محاسبات) — totals/partial/overpayment/adjustment.
 */
final class InvoiceCalcTest extends TestCase
{
    public function testIssueTotalsSimple(): void
    {
        $totals = InvoiceCalc::issueTotals([
            ['quantity' => 1, 'unit_price' => 500_000],
            ['quantity' => 2, 'unit_price' => 150_000],
        ], 50_000, 0);

        $this->assertSame(800_000, $totals['subtotal']);
        $this->assertSame(50_000, $totals['discount']);
        $this->assertSame(0, $totals['tax']);
        $this->assertSame(750_000, $totals['total']);
    }

    public function testIssueTotalsWithItemDiscountAndTax(): void
    {
        $totals = InvoiceCalc::issueTotals([
            ['quantity' => 1, 'unit_price' => 100_000, 'discount' => 20_000],
        ], 0, 10_000);

        // subtotal = 100k - 20k(item) = 80k; total = 80k + 10k(tax) = 90k
        $this->assertSame(80_000, $totals['subtotal']);
        $this->assertSame(20_000, $totals['discount']);
        $this->assertSame(90_000, $totals['total']);
    }

    public function testEmptyInvoiceRejected(): void
    {
        $this->expectException(DomainException::class);
        InvoiceCalc::issueTotals([], 0, 0);
    }

    public function testInvalidItemRejected(): void
    {
        $this->expectException(DomainException::class);
        InvoiceCalc::issueTotals([['quantity' => 0, 'unit_price' => 100]], 0, 0);
    }

    public function testFractionalQuantityRounds(): void
    {
        $totals = InvoiceCalc::issueTotals([['quantity' => 1.5, 'unit_price' => 100_001]], 0, 0);
        $this->assertSame(150_002, $totals['subtotal']); // 1.5 * 100001 = 150001.5 → 150002
    }

    public function testPartialPaymentKeepsBalance(): void
    {
        $r = InvoiceCalc::applyPayment(750_000, 0, 300_000);
        $this->assertSame(300_000, $r['paid']);
        $this->assertSame(450_000, $r['balance']);
        $this->assertSame('pay_partial', $r['event']);
    }

    public function testFullPaymentCloses(): void
    {
        $r = InvoiceCalc::applyPayment(750_000, 300_000, 450_000);
        $this->assertSame(750_000, $r['paid']);
        $this->assertSame(0, $r['balance']);
        $this->assertSame('pay_full', $r['event']);
    }

    public function testOverpaymentRejected(): void
    {
        $this->expectException(DomainException::class);
        InvoiceCalc::applyPayment(750_000, 700_000, 60_000);
    }

    public function testZeroPaymentRejected(): void
    {
        $this->expectException(DomainException::class);
        InvoiceCalc::applyPayment(750_000, 0, 0);
    }

    public function testCreditAdjustmentReducesBalance(): void
    {
        $r = InvoiceCalc::applyAdjustment(750_000, 300_000, 450_000, 'credit', 50_000);
        $this->assertSame(400_000, $r['balance']);
    }

    public function testDebitAdjustmentIncreasesBalance(): void
    {
        $r = InvoiceCalc::applyAdjustment(750_000, 0, 750_000, 'debit', 25_000);
        $this->assertSame(775_000, $r['balance']);
    }

    public function testAdjustmentBelowZeroRejected(): void
    {
        $this->expectException(DomainException::class);
        InvoiceCalc::applyAdjustment(100_000, 0, 50_000, 'credit', 60_000);
    }

    public function testInvalidAdjustmentTypeRejected(): void
    {
        $this->expectException(DomainException::class);
        InvoiceCalc::applyAdjustment(100_000, 0, 50_000, 'void', 10_000);
    }
}
