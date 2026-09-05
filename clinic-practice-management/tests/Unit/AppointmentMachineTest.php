<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Machine\AppointmentMachine as A;
use ClinicCore\Domain\Machine\InvalidTransitionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TP-14 (بخش Appointment): Exhaustive روی State Machine نوبت — docs/state-machines/appointment.md
 */
final class AppointmentMachineTest extends TestCase
{
    private A $machine;

    protected function setUp(): void
    {
        $this->machine = A::create();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function validProvider(): array
    {
        return [
            // T1
            'T1 create by secretary' => [A::NEW, 'create', 'secretary', A::PENDING],
            'T1 create by doctor' => [A::NEW, 'create', 'doctor', A::PENDING],
            // T2
            'T2 book_final by patient' => [A::NEW, 'book_final', 'patient', A::CONFIRMED],
            // T3
            'T3 confirm by secretary' => [A::PENDING, 'confirm', 'secretary', A::CONFIRMED],
            'T3 confirm by system' => [A::PENDING, 'confirm', 'system', A::CONFIRMED],
            // T4
            'T4 pending cancel by system (expire)' => [A::PENDING, 'cancel', 'system', A::CANCELLED_BY_STAFF],
            // T5
            'T5 confirmed cancel by patient' => [A::CONFIRMED, 'cancel', 'patient', A::CANCELLED_BY_PATIENT],
            // T6
            'T6 confirmed cancel by secretary' => [A::CONFIRMED, 'cancel', 'secretary', A::CANCELLED_BY_STAFF],
            'T6 confirmed cancel by doctor' => [A::CONFIRMED, 'cancel', 'doctor', A::CANCELLED_BY_STAFF],
            // T7
            'T7 reschedule by patient' => [A::CONFIRMED, 'reschedule', 'patient', A::RESCHEDULED],
            'T7 reschedule by secretary' => [A::CONFIRMED, 'reschedule', 'secretary', A::RESCHEDULED],
            // T8
            'T8 no_show by system' => [A::CONFIRMED, 'no_show', 'system', A::NO_SHOW],
            'T8 no_show by secretary' => [A::CONFIRMED, 'no_show', 'secretary', A::NO_SHOW],
            // T9
            'T9 completed by system on checkout' => [A::CONFIRMED, 'visit_checked_out', 'system', A::COMPLETED],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'patient cannot confirm pending' => [A::PENDING, 'confirm', 'patient'],
            'system cannot cancel confirmed' => [A::CONFIRMED, 'cancel', 'system'],
            'doctor cannot book_final' => [A::NEW, 'book_final', 'doctor'],
            'patient cannot create (secretary flow)' => [A::NEW, 'create', 'patient'],
            'no_show from pending forbidden' => [A::PENDING, 'no_show', 'secretary'],
            'completed only from confirmed' => [A::PENDING, 'visit_checked_out', 'system'],
            'reschedule from pending forbidden' => [A::PENDING, 'reschedule', 'patient'],
            'no exit from completed' => [A::COMPLETED, 'cancel', 'patient'],
            'no exit from no_show' => [A::NO_SHOW, 'visit_checked_out', 'system'],
            'no exit from rescheduled' => [A::RESCHEDULED, 'confirm', 'secretary'],
            'unknown event' => [A::CONFIRMED, 'teleport', 'patient'],
        ];
    }

    #[DataProvider('validProvider')]
    public function testValidTransitions(string $from, string $event, string $actor, string $to): void
    {
        $this->assertSame($to, $this->machine->machine()->assert($from, $event, $actor));
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidTransitions(string $from, string $event, string $actor): void
    {
        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionCode(0);
        $this->machine->machine()->assert($from, $event, $actor);
    }

    public function testTerminalStatesHaveNoOutgoingTransitions(): void
    {
        $m = $this->machine->machine();
        foreach ([
            A::CANCELLED_BY_PATIENT, A::CANCELLED_BY_STAFF,
            A::RESCHEDULED, A::NO_SHOW, A::COMPLETED,
        ] as $terminal) {
            $this->assertTrue($m->isTerminal($terminal), $terminal);
            $this->assertSame([], $m->allowedEvents($terminal), 'terminal outgoing: ' . $terminal);
        }
    }

    public function testDoubleCompleteRejected(): void
    {
        $m = $this->machine->machine();
        $this->assertSame(A::COMPLETED, $m->assert(A::CONFIRMED, 'visit_checked_out', 'system'));

        $this->expectException(InvalidTransitionException::class);
        $m->assert(A::COMPLETED, 'visit_checked_out', 'system');
    }
}
