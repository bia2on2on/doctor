<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Domain\Otp\OtpState;
use PHPUnit\Framework\TestCase;

/**
 * TP-05 (منطق) — سیاست OTP: TTL, Attempts, Cooldown, Daily Max, Lockout (ADR-0006).
 */
final class OtpPolicyTest extends TestCase
{
    private OtpPolicy $policy;

    private const T0 = '2026-09-05T10:00:00+00:00';

    protected function setUp(): void
    {
        $this->policy = new OtpPolicy(300, 5, 60, 3, 900);
    }

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }

    public function testFreshStateAllowsSend(): void
    {
        $result = $this->policy->canSend(new OtpState(), $this->at(self::T0));
        $this->assertTrue($result['ok']);
        $this->assertSame(OtpPolicy::REASON_OK, $result['reason']);
    }

    public function testCooldownBlocksResend(): void
    {
        $state = new OtpState();
        $state = $this->policy->registerSend($state, $this->at(self::T0));

        $result = $this->policy->canSend($state, $this->at('2026-09-05T10:00:30+00:00'));
        $this->assertFalse($result['ok']);
        $this->assertSame(OtpPolicy::REASON_COOLDOWN, $result['reason']);

        $result = $this->policy->canSend($state, $this->at('2026-09-05T10:01:01+00:00'));
        $this->assertTrue($result['ok']);
    }

    public function testDailyMaxEnforced(): void
    {
        $state = new OtpState();
        $state = $this->policy->registerSend($state, $this->at('2026-09-05T08:00:00+00:00'));
        $state = $this->policy->registerSend($state, $this->at('2026-09-05T09:00:00+00:00'));
        $state = $this->policy->registerSend($state, $this->at('2026-09-05T09:30:00+00:00'));

        $result = $this->policy->canSend($state, $this->at(self::T0));
        $this->assertFalse($result['ok']);
        $this->assertSame(OtpPolicy::REASON_DAILY_LIMIT, $result['reason']);
    }

    public function testLockAfterMaxAttempts(): void
    {
        $state = new OtpState();
        for ($i = 0; $i < 5; $i++) {
            $state = $this->policy->registerFailedAttempt($state, $this->at(self::T0));
        }

        $this->assertTrue($state->isLocked($this->at('2026-09-05T10:01:00+00:00')));
        $result = $this->policy->canSend($state, $this->at('2026-09-05T10:01:00+00:00'));
        $this->assertSame(OtpPolicy::REASON_LOCKED, $result['reason']);
        $this->assertSame(0, $this->policy->remainingAttempts($state));
    }

    public function testLockExpiresAndAttemptsReset(): void
    {
        $state = new OtpState();
        for ($i = 0; $i < 5; $i++) {
            $state = $this->policy->registerFailedAttempt($state, $this->at(self::T0));
        }

        // بعد از 15 دقیقه قفل
        $after = $this->at('2026-09-05T10:16:00+00:00');
        $state->resetIfUnlocked($after);
        $this->assertFalse($state->isLocked($after));
        $this->assertSame(0, $state->attempts);

        $result = $this->policy->canSend($state, $after);
        $this->assertTrue($result['ok']);
    }

    public function testCodeExpiration(): void
    {
        $issued = $this->at(self::T0);
        $this->assertFalse($this->policy->isCodeExpired($issued, $this->at('2026-09-05T10:04:59+00:00')));
        $this->assertTrue($this->policy->isCodeExpired($issued, $this->at('2026-09-05T10:05:00+00:00')));
    }

    public function testCodeGeneration(): void
    {
        $code = OtpPolicy::generateCode(6);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame(OtpPolicy::generateCode(6), $code, 'کدها باید تصادفی باشند');
    }

    public function testHashIsDeterministicAndSecretBound(): void
    {
        $a = OtpPolicy::hashCode('123456', 'pepper-1');
        $b = OtpPolicy::hashCode('123456', 'pepper-1');
        $c = OtpPolicy::hashCode('123456', 'pepper-2');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertFalse(str_contains($a, '123456'), 'هش نباید کد را لو بدهد');
    }

    public function testRemainingAttemptsCountdown(): void
    {
        $state = new OtpState();
        $this->assertSame(5, $this->policy->remainingAttempts($state));
        $state = $this->policy->registerFailedAttempt($state, $this->at(self::T0));
        $state = $this->policy->registerFailedAttempt($state, $this->at(self::T0));
        $this->assertSame(3, $this->policy->remainingAttempts($state));
    }
}
