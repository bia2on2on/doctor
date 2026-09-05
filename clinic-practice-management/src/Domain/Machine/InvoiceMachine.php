<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Machine;

/**
 * State Machine فاکتور — مطابق docs/state-machines/payment.md (I1..I4).
 *
 * یادداشت: رویداد پرداخت به دو Event صریح تبدیل می‌شود (pay_partial/pay_full)
 * چون مقدار «ناقص/کامل» یک شرط کسب‌وکاری است که لایه Application با InvoiceCalc محاسبه می‌کند.
 * Void فقط از 'open' و فقط بدون پرداخت (M-6).
 */
final class InvoiceMachine
{
    public const OPEN = 'open';
    public const PARTIAL = 'partial';
    public const PAID = 'paid';
    public const VOIDED = 'voided';

    public const NEW = 'new';

    public function __construct(private readonly StateMachine $machine)
    {
    }

    public static function create(): self
    {
        $m = new StateMachine('Invoice');

        $m->addTransition(self::NEW, 'issue', self::OPEN, ['secretary', 'doctor']);
        $m->addTransition(self::OPEN, 'pay_partial', self::PARTIAL, ['system']);
        $m->addTransition(self::OPEN, 'pay_full', self::PAID, ['system']);
        $m->addTransition(self::PARTIAL, 'pay_full', self::PAID, ['system']);
        $m->addTransition(self::OPEN, 'void', self::VOIDED, ['secretary']);

        foreach ([self::PAID, self::VOIDED] as $terminal) {
            $m->addTerminal($terminal);
        }

        return new self($m);
    }

    public function machine(): StateMachine
    {
        return $this->machine;
    }
}
