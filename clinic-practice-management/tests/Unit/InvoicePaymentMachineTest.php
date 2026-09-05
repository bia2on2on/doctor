<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Machine\InvalidTransitionException;
use ClinicCore\Domain\Machine\InvoiceMachine as I;
use ClinicCore\Domain\Machine\PaymentMachine as P;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TP-14 (بخش مالی) — docs/state-machines/payment.md
 */
final class InvoicePaymentMachineTest extends TestCase
{
    public function testInvoiceHappyPathFull(): void
    {
        $m = I::create()->machine();
        $state = $m->assert(I::NEW, 'issue', 'secretary');
        $this->assertSame(I::OPEN, $state);
        $state = $m->assert($state, 'pay_full', 'system');
        $this->assertSame(I::PAID, $state);
        $this->assertTrue($m->isTerminal($state));
    }

    public function testInvoicePartialThenFull(): void
    {
        $m = I::create()->machine();
        $state = $m->assert(I::NEW, 'issue', 'secretary');
        $state = $m->assert($state, 'pay_partial', 'system');
        $this->assertSame(I::PARTIAL, $state);
        $state = $m->assert($state, 'pay_full', 'system');
        $this->assertSame(I::PAID, $state);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invoiceInvalidProvider(): array
    {
        return [
            'void from partial forbidden' => [I::PARTIAL, 'void', 'secretary'],
            'void from paid forbidden' => [I::PAID, 'void', 'secretary'],
            'pay after void' => [I::VOIDED, 'pay_full', 'system'],
            'issue from open (no re-issue)' => [I::OPEN, 'issue', 'secretary'],
            'patient cannot issue' => [I::NEW, 'issue', 'patient'],
            'pay_partial by non-system' => [I::OPEN, 'pay_partial', 'secretary'],
        ];
    }

    #[DataProvider('invoiceInvalidProvider')]
    public function testInvoiceInvalidTransitions(string $from, string $event, string $actor): void
    {
        $this->expectException(InvalidTransitionException::class);
        I::create()->machine()->assert($from, $event, $actor);
    }

    public function testPaymentLifecycle(): void
    {
        $m = P::create()->machine();
        $state = $m->assert(P::NEW, 'capture', 'secretary');
        $this->assertSame(P::CAPTURED, $state);
        $state = $m->assert($state, 'void', 'secretary');
        $this->assertSame(P::VOIDED, $state);
        $this->assertTrue($m->isTerminal($state));
    }

    public function testPaymentRefundPath(): void
    {
        $m = P::create()->machine();
        $state = $m->assert(P::NEW, 'capture', 'secretary');
        $state = $m->assert($state, 'refund', 'secretary');
        $this->assertSame(P::REFUNDED, $state);
    }

    public function testPaymentDoubleVoidRejected(): void
    {
        $m = P::create()->machine();
        $state = $m->assert(P::NEW, 'capture', 'secretary');
        $m->assert($state, 'void', 'secretary');

        $this->expectException(InvalidTransitionException::class);
        $m->assert(P::VOIDED, 'void', 'secretary');
    }

    public function testPaymentRefundAfterVoidRejected(): void
    {
        $m = P::create()->machine();
        $state = $m->assert(P::NEW, 'capture', 'secretary');
        $m->assert($state, 'void', 'secretary');

        $this->expectException(InvalidTransitionException::class);
        $m->assert(P::VOIDED, 'refund', 'secretary');
    }
}
