<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Machine;

/**
 * State Machine ویزیت/صف — مطابق docs/state-machines/visit-queue.md (V1..V15).
 *
 * Actors: secretary | doctor | system
 * 'recall' یک Transition است (called→waiting) نه حالت؛ 'no_show' روی Appointment است.
 */
final class VisitMachine
{
    public const CHECKED_IN = 'checked_in';
    public const WAITING = 'waiting';
    public const CALLED = 'called';
    public const IN_CONSULTATION = 'in_consultation';
    public const CONSULTATION_COMPLETED = 'consultation_completed';
    public const AWAITING_PAYMENT = 'awaiting_payment';
    public const PAID = 'paid';
    public const CHECKED_OUT = 'checked_out';
    public const CANCELLED = 'cancelled';
    public const SKIPPED = 'skipped';

    public const NEW = 'new';

    /**
     * وضعیت‌های «زندهٔ» ویزیت — مبنای یگانهٔ I-3 (docs/state-machines/appointment.md §4):
     * تا وقتی ویزیتِ متصل به نوبت در یکی از این وضعیت‌ها و `active = 1` است،
     * T5/T6/T7 روی آن نوبت ممنوع است (`HAS_ACTIVE_VISIT`). اشاره‌گر کهنهٔ
     * `appointments.active_visit_id` به‌تنهایی شاهدِ فعال بودن نیست.
     */
    public const ACTIVE_STATUSES = [
        self::CHECKED_IN, self::WAITING, self::CALLED, self::IN_CONSULTATION,
        self::CONSULTATION_COMPLETED, self::AWAITING_PAYMENT, self::PAID,
    ];

    public function __construct(private readonly StateMachine $machine)
    {
    }

    public static function create(): self
    {
        $m = new StateMachine('Visit');

        // V1/V2
        $m->addTransition(self::NEW, 'check_in', self::CHECKED_IN, ['secretary']);
        $m->addTransition(self::NEW, 'create_walk_in', self::CHECKED_IN, ['secretary']);
        // V3 (خودکار یا دستی)
        $m->addTransition(self::CHECKED_IN, 'enqueue', self::WAITING, ['secretary', 'system']);
        // V4/V6/V5/V7/V8
        $m->addTransition(self::WAITING, 'call', self::CALLED, ['doctor']);
        $m->addTransition(self::CALLED, 'recall', self::WAITING, ['doctor']);
        $m->addTransition(self::CALLED, 'start', self::IN_CONSULTATION, ['doctor']);
        $m->addTransition(self::WAITING, 'skip', self::SKIPPED, ['doctor']);
        $m->addTransition(self::CALLED, 'skip', self::SKIPPED, ['doctor']);
        // V9
        foreach ([self::CHECKED_IN, self::WAITING, self::CALLED] as $from) {
            $m->addTransition($from, 'cancel', self::CANCELLED, ['secretary']);
        }
        // V10 (یک‌بار — Lock Row در Application)
        $m->addTransition(self::IN_CONSULTATION, 'complete', self::CONSULTATION_COMPLETED, ['doctor']);
        // V15 (Correction)
        $m->addTransition(self::CONSULTATION_COMPLETED, 'reopen', self::IN_CONSULTATION, ['doctor']);
        // V11/V12/V13/V14
        $m->addTransition(self::CONSULTATION_COMPLETED, 'invoice_ready', self::AWAITING_PAYMENT, ['system', 'secretary']);
        $m->addTransition(self::AWAITING_PAYMENT, 'settled', self::PAID, ['system']);
        $m->addTransition(self::AWAITING_PAYMENT, 'waive', self::CHECKED_OUT, ['secretary', 'doctor']);
        $m->addTransition(self::PAID, 'check_out', self::CHECKED_OUT, ['secretary']);

        foreach ([self::SKIPPED, self::CANCELLED, self::CHECKED_OUT] as $terminal) {
            $m->addTerminal($terminal);
        }

        return new self($m);
    }

    public function machine(): StateMachine
    {
        return $this->machine;
    }
}
