<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Machine\InvalidTransitionException;
use ClinicCore\Domain\Machine\VisitMachine as V;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TP-14 (بخش Visit/Queue) + TP-03b (Double Complete) — docs/state-machines/visit-queue.md
 */
final class VisitMachineTest extends TestCase
{
    private V $machine;

    protected function setUp(): void
    {
        $this->machine = V::create();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function validProvider(): array
    {
        return [
            // V1/V2
            'V1 check_in' => [V::NEW, 'check_in', 'secretary', V::CHECKED_IN],
            'V2 walk_in' => [V::NEW, 'create_walk_in', 'secretary', V::CHECKED_IN],
            // V3
            'V3 enqueue by secretary' => [V::CHECKED_IN, 'enqueue', 'secretary', V::WAITING],
            'V3 enqueue auto by system' => [V::CHECKED_IN, 'enqueue', 'system', V::WAITING],
            // V4
            'V4 call by doctor' => [V::WAITING, 'call', 'doctor', V::CALLED],
            // V6
            'V6 recall' => [V::CALLED, 'recall', 'doctor', V::WAITING],
            // V5
            'V5 start consultation' => [V::CALLED, 'start', 'doctor', V::IN_CONSULTATION],
            // V7/V8
            'V7 skip from waiting' => [V::WAITING, 'skip', 'doctor', V::SKIPPED],
            'V8 skip from called' => [V::CALLED, 'skip', 'doctor', V::SKIPPED],
            // V9
            'V9 cancel from checked_in' => [V::CHECKED_IN, 'cancel', 'secretary', V::CANCELLED],
            'V9 cancel from waiting' => [V::WAITING, 'cancel', 'secretary', V::CANCELLED],
            'V9 cancel from called' => [V::CALLED, 'cancel', 'secretary', V::CANCELLED],
            // V10
            'V10 complete' => [V::IN_CONSULTATION, 'complete', 'doctor', V::CONSULTATION_COMPLETED],
            // V15
            'V15 reopen (correction)' => [V::CONSULTATION_COMPLETED, 'reopen', 'doctor', V::IN_CONSULTATION],
            // V11/V12/V13/V14
            'V11 invoice ready by system' => [V::CONSULTATION_COMPLETED, 'invoice_ready', 'system', V::AWAITING_PAYMENT],
            'V12 settled' => [V::AWAITING_PAYMENT, 'settled', 'system', V::PAID],
            'V13 waive' => [V::AWAITING_PAYMENT, 'waive', 'secretary', V::CHECKED_OUT],
            'V14 checkout after paid' => [V::PAID, 'check_out', 'secretary', V::CHECKED_OUT],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'secretary cannot call' => [V::WAITING, 'call', 'secretary'],
            'patient cannot check_in' => [V::NEW, 'check_in', 'patient'],
            'waiting cannot start directly (must be called first)' => [V::WAITING, 'start', 'doctor'],
            'no recall from waiting' => [V::WAITING, 'recall', 'doctor'],
            'complete from waiting forbidden' => [V::WAITING, 'complete', 'doctor'],
            'complete from in_consultation by secretary' => [V::IN_CONSULTATION, 'complete', 'secretary'],
            'checkout before paid (use waive)' => [V::AWAITING_PAYMENT, 'check_out', 'secretary'],
            'settled only by system' => [V::AWAITING_PAYMENT, 'settled', 'secretary'],
            'no cancel from in_consultation' => [V::IN_CONSULTATION, 'cancel', 'secretary'],
            'no exit from checked_out' => [V::CHECKED_OUT, 'call', 'doctor'],
            'no exit from cancelled' => [V::CANCELLED, 'enqueue', 'secretary'],
            'no exit from skipped' => [V::SKIPPED, 'call', 'doctor'],
            'reopen from in_consultation forbidden' => [V::IN_CONSULTATION, 'reopen', 'doctor'],
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
        $this->machine->machine()->assert($from, $event, $actor);
    }

    public function testFullHappyPath(): void
    {
        $m = $this->machine->machine();
        $state = $m->assert(V::NEW, 'check_in', 'secretary');
        $state = $m->assert($state, 'enqueue', 'system');
        $state = $m->assert($state, 'call', 'doctor');
        $state = $m->assert($state, 'start', 'doctor');
        $state = $m->assert($state, 'complete', 'doctor');
        $state = $m->assert($state, 'invoice_ready', 'system');
        $state = $m->assert($state, 'settled', 'system');
        $state = $m->assert($state, 'check_out', 'secretary');

        $this->assertSame(V::CHECKED_OUT, $state);
        $this->assertTrue($m->isTerminal($state));
    }

    public function testRecallLoopThenSkip(): void
    {
        $m = $this->machine->machine();
        $state = $m->assert(V::WAITING, 'call', 'doctor');
        $state = $m->assert($state, 'recall', 'doctor');
        $this->assertSame(V::WAITING, $state);
        $state = $m->assert($state, 'call', 'doctor');
        $state = $m->assert($state, 'skip', 'doctor');
        $this->assertSame(V::SKIPPED, $state);
    }

    public function testDoubleCompleteRejected(): void
    {
        // TP-03b: دو Complete هم‌زمان — Machine فقط یکی را می‌پذیرد (Lock در Application)
        $m = $this->machine->machine();
        $state = $m->assert(V::IN_CONSULTATION, 'complete', 'doctor');

        $this->expectException(InvalidTransitionException::class);
        $m->assert($state, 'complete', 'doctor');
    }
}
