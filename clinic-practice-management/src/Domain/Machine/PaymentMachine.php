<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Machine;

/**
 * State Machine پرداخت — مطابق docs/state-machines/payment.md (P1..P3).
 *
 * Paymentها Immutable هستند: Void/Refund تغییر «وضعیت» رکورد است نه ویرایش مبلغ.
 * Correction مبلغ = Adjustment (جدول جدا) — اینجا نیست.
 */
final class PaymentMachine
{
    public const CAPTURED = 'captured';
    public const VOIDED = 'voided';
    public const REFUNDED = 'refunded';

    public const NEW = 'new';

    public function __construct(private readonly StateMachine $machine)
    {
    }

    public static function create(): self
    {
        $m = new StateMachine('Payment');

        $m->addTransition(self::NEW, 'capture', self::CAPTURED, ['secretary']);
        $m->addTransition(self::CAPTURED, 'void', self::VOIDED, ['secretary']);
        $m->addTransition(self::CAPTURED, 'refund', self::REFUNDED, ['secretary']);

        foreach ([self::VOIDED, self::REFUNDED] as $terminal) {
            $m->addTerminal($terminal);
        }

        return new self($m);
    }

    public function machine(): StateMachine
    {
        return $this->machine;
    }
}
