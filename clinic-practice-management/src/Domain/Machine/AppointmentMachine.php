<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Machine;

/**
 * State Machine نوبت — مطابق docs/state-machines/appointment.md (T1..T9).
 *
 * Actors: patient | secretary | doctor | system
 * شرط‌های کسب‌وکاری (Policy لغو، CLINIC_SLOT_TAKEN، HAS_ACTIVE_VISIT) در لایه Application
 * بررسی می‌شوند و در صورت شکست با کد خطای مربوطه رد می‌شوند؛ اینجا فقط Transition و Actor.
 */
final class AppointmentMachine
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const CANCELLED_BY_PATIENT = 'cancelled_by_patient';
    public const CANCELLED_BY_STAFF = 'cancelled_by_staff';
    public const RESCHEDULED = 'rescheduled';
    public const COMPLETED = 'completed';
    public const NO_SHOW = 'no_show';

    public const NEW = 'new';

    public function __construct(private readonly StateMachine $machine)
    {
    }

    public static function create(): self
    {
        $m = new StateMachine('Appointment');

        // T1/T2: ساخت
        $m->addTransition(self::NEW, 'create', self::PENDING, ['secretary', 'doctor']);
        $m->addTransition(self::NEW, 'book_final', self::CONFIRMED, ['patient']);
        // T3/T4
        $m->addTransition(self::PENDING, 'confirm', self::CONFIRMED, ['secretary', 'doctor', 'system']);
        $m->addTransition(self::PENDING, 'cancel', self::CANCELLED_BY_STAFF, ['secretary', 'doctor', 'system']);
        // T5/T6/T7/T8/T9
        $m->addTransition(self::CONFIRMED, 'cancel', self::CANCELLED_BY_PATIENT, ['patient']);
        $m->addTransition(self::CONFIRMED, 'cancel', self::CANCELLED_BY_STAFF, ['secretary', 'doctor']);
        $m->addTransition(self::CONFIRMED, 'reschedule', self::RESCHEDULED, ['patient', 'secretary', 'doctor']);
        $m->addTransition(self::CONFIRMED, 'no_show', self::NO_SHOW, ['secretary', 'doctor', 'system']);
        $m->addTransition(self::CONFIRMED, 'visit_checked_out', self::COMPLETED, ['system']);

        foreach ([
            self::CANCELLED_BY_PATIENT, self::CANCELLED_BY_STAFF,
            self::RESCHEDULED, self::NO_SHOW, self::COMPLETED,
        ] as $terminal) {
            $m->addTerminal($terminal);
        }

        return new self($m);
    }

    public function machine(): StateMachine
    {
        return $this->machine;
    }
}
