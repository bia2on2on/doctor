<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Machine\InvalidTransitionException;
use ClinicCore\Domain\Machine\StateMachine;
use PHPUnit\Framework\TestCase;

/**
 * تست‌های State Machine پایه — منطق Actor-based branching.
 */
final class StateMachineTest extends TestCase
{
    public function testActorFallbackToUnrestrictedCandidate(): void
    {
        // Fallback مستندشده در docblock (F1-10) — رفتار عمدی:
        $m = new StateMachine('T');
        $m->addTransition('a', 'go', 'b', ['x']); // محدود به نقش x
        $m->addTransition('a', 'go', 'c'); // بدون محدودیت = همه

        $this->assertSame('b', $m->assert('a', 'go', 'x'), 'actor منطقی → Candidate محدودِ خودش');
        $this->assertSame('c', $m->assert('a', 'go', 'z'), 'actor خارج از فهرست‌ها → fallback به Candidate عمومی');
        $this->assertSame('c', $m->assert('a', 'go'), 'بدون actor → اول Candidate بدون محدودیت');
        $this->assertTrue($m->can('a', 'go', 'z'));
    }

    public function testNoFallbackWhenOnlyRestrictedCandidatesExist(): void
    {
        $m = new StateMachine('T');
        $m->addTransition('a', 'go', 'b', ['x']);

        $this->expectException(InvalidTransitionException::class);
        $m->assert('a', 'go', 'z');
    }

    public function testTransitionAllowedForListedActor(): void
    {
        $m = new StateMachine('T');
        $m->addTransition('a', 'go', 'b', ['x', 'y']);

        $this->assertSame('b', $m->assert('a', 'go', 'x'));
        $this->assertSame('b', $m->assert('a', 'go', 'y'));
        $this->assertFalse($m->can('a', 'go', 'z'));
    }

    public function testActorBranchingSameEventDifferentTarget(): void
    {
        // سناریوی Appointment.cancel: بیمار → cancelled_by_patient / کارکن → cancelled_by_staff
        $m = new StateMachine('T');
        $m->addTransition('confirmed', 'cancel', 'cancelled_by_patient', ['patient']);
        $m->addTransition('confirmed', 'cancel', 'cancelled_by_staff', ['secretary', 'doctor']);

        $this->assertSame('cancelled_by_patient', $m->assert('confirmed', 'cancel', 'patient'));
        $this->assertSame('cancelled_by_staff', $m->assert('confirmed', 'cancel', 'secretary'));
        $this->assertFalse($m->can('confirmed', 'cancel', 'system'));
    }

    public function testUnknownEventThrows(): void
    {
        $m = new StateMachine('T');
        $m->addTransition('a', 'go', 'b');

        $this->expectException(InvalidTransitionException::class);
        $m->assert('a', 'nope');
    }

    public function testUnknownStateThrows(): void
    {
        $m = new StateMachine('T');
        $m->addTransition('a', 'go', 'b');

        $this->expectException(InvalidTransitionException::class);
        $m->assert('zzz', 'go');
    }

    public function testTerminalIsReported(): void
    {
        $m = new StateMachine('T');
        $m->addTransition('a', 'go', 'b');
        $m->addTerminal('b');

        $this->assertTrue($m->isTerminal('b'));
        $this->assertFalse($m->isTerminal('a'));
    }

    public function testAllowedEvents(): void
    {
        $m = new StateMachine('T');
        $m->addTransition('a', 'go', 'b');
        $m->addTransition('a', 'stop', 'c');
        $m->addTransition('b', 'go', 'c');

        $this->assertEqualsCanonicalizing(['go', 'stop'], $m->allowedEvents('a'));
        $this->assertSame(['go'], $m->allowedEvents('b'));
        $this->assertSame([], $m->allowedEvents('c'));
    }

    public function testNullActorMatchesFirstCandidate(): void
    {
        $m = new StateMachine('T');
        $m->addTransition('a', 'go', 'b');

        $this->assertSame('b', $m->assert('a', 'go', null));
    }
}
