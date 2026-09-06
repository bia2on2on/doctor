<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\LicensePolicy;
use ClinicCore\Domain\Licensing\LicenseStateMachine;
use ClinicCore\Domain\Licensing\LicenseStatus;
use PHPUnit\Framework\TestCase;

/**
 * F10 / ADR-0023 §3 — ماشین وضعیت محلی مجوز (spec §15/§16).
 *
 * قواعد حیاتی تحت آزمون:
 *  - شبکه قطع ≠ invalid (UNREACHABLE مجزا؛ ادامه از کش معتبر تا پنجره).
 *  - REVOKED/SUSPENDED فقط از سند معتبر (هیچ‌وقت از قطع شبکه).
 *  - شکست امضا = INVALID (جدا از UNREACHABLE).
 *  - پیشروی ACTIVE→EXPIRING→GRACE→RESTRICTED با مهلت پیش‌فرض ۷ روز.
 */
final class LicenseStateMachineTest extends TestCase
{
    private const DAY = 86400;
    private int $now;

    protected function setUp(): void
    {
        // 2030-01-15 12:00 UTC — ثابت برای قطعیت
        $this->now = 1893463200;
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function compute(?array $doc, string $verdict, LicensePolicy $policy): array
    {
        return LicenseStateMachine::compute($doc, $verdict, $this->now, $policy);
    }

    private function doc(int $expiresInSec, bool $revoked = false, bool $suspended = false, int $issuedAgoSec = 1000): array
    {
        return [
            'issued_at' => $this->now - $issuedAgoSec,
            'expires_at' => $this->now + $expiresInSec,
            'revoked' => $revoked,
            'suspended' => $suspended,
        ];
    }

    public function testActiveWhenFarFromExpiry(): void
    {
        $out = $this->compute($this->doc(30 * self::DAY), LicenseStateMachine::VERIFIED, new LicensePolicy());
        $this->assertSame(LicenseStatus::ACTIVE, $out['status']);
        $this->assertFalse(LicenseStatus::isRestricted($out['status']));
    }

    public function testExpiringWithinGraceWindow(): void
    {
        // ۳ روز تا انقضا (< ۷ روز) → EXPIRING ولی کسب‌وکار جدید مجاز
        $out = $this->compute($this->doc(3 * self::DAY), LicenseStateMachine::VERIFIED, new LicensePolicy());
        $this->assertSame(LicenseStatus::EXPIRING, $out['status']);
        $this->assertTrue(LicenseStatus::allowsNewBusiness($out['status']));
        $this->assertTrue($out['needs_renewal']);
    }

    public function testGraceAfterExpiryWithinDefaultSevenDays(): void
    {
        // ۲ روز پس از انقضا → GRACE (داخل مهلت ۷ روزه) — هشدار، تمدید لازم
        $out = $this->compute($this->doc(-2 * self::DAY), LicenseStateMachine::VERIFIED, new LicensePolicy());
        $this->assertSame(LicenseStatus::GRACE, $out['status']);
        $this->assertSame($this->now - 2 * self::DAY, $out['expires_at']);
        $this->assertTrue(LicenseStatus::allowsNewBusiness($out['status']));
    }

    public function testRestrictedAfterGraceExpiry(): void
    {
        // ۱۰ روز پس از انقضا → RESTRICTED (خارج از مهلت ۷ روزه)
        $out = $this->compute($this->doc(-10 * self::DAY), LicenseStateMachine::VERIFIED, new LicensePolicy());
        $this->assertSame(LicenseStatus::RESTRICTED, $out['status']);
        $this->assertTrue(LicenseStatus::isRestricted($out['status']));
        $this->assertFalse(LicenseStatus::allowsNewBusiness($out['status']));
    }

    public function testRevokedOnlyFromVerifiedSignedDocument(): void
    {
        $out = $this->compute($this->doc(30 * self::DAY, revoked: true), LicenseStateMachine::VERIFIED, new LicensePolicy());
        $this->assertSame(LicenseStatus::REVOKED, $out['status']);
        // مهم: revoked با وجود expires در آینده
        $this->assertTrue(LicenseStatus::isRestricted($out['status']));
    }

    public function testSuspendedOnlyFromVerifiedDocument(): void
    {
        $out = $this->compute($this->doc(30 * self::DAY, suspended: true), LicenseStateMachine::VERIFIED, new LicensePolicy());
        $this->assertSame(LicenseStatus::SUSPENDED, $out['status']);
    }

    public function testSignatureInvalidIsInvalidNotUnreachable(): void
    {
        $out = $this->compute($this->doc(30 * self::DAY), LicenseStateMachine::SIGNATURE_INVALID, new LicensePolicy());
        $this->assertSame(LicenseStatus::INVALID, $out['status']);
        $this->assertNotSame(LicenseStatus::UNREACHABLE, $out['status']);
    }

    public function testUnreachableWithinGraceKeepsOperating(): void
    {
        // سند معتبر تا ۲۰ روز دیگر؛ سرور قطع → داخل پنجره = ادامه ACTIVE.
        // needs_renewal=false چون ۲۰ روز ≫ فاصله‌ی renew (24h) — کادنس refresh
        // توسط refreshDue (Backoff) کنترل می‌شود نه needs_renewal.
        $out = $this->compute($this->doc(20 * self::DAY), LicenseStateMachine::UNREACHABLE, new LicensePolicy());
        $this->assertSame(LicenseStatus::ACTIVE, $out['status']);
        $this->assertFalse($out['needs_renewal']);
    }

    public function testUnreachableWithExpiredDocAndStaleCache(): void
    {
        // سند ۱۰ روز قبل منقضی + شبکه قطع → خارج از پنجره‌ی unreachable
        // (3 روز پس از انقضا) → UNREACHABLE صریح (نه INVALID؛ داده هرگز
        // قفل/حذف نمی‌شود — فقط فعالیت جدید محدود است)
        $policy = new LicensePolicy(unreachableGraceDays: 3);
        $out = $this->compute($this->doc(-10 * self::DAY), LicenseStateMachine::UNREACHABLE, $policy);
        $this->assertSame(LicenseStatus::UNREACHABLE, $out['status']);
        $this->assertTrue(LicenseStatus::isRestricted($out['status']));
    }

    public function testUnreachableBoundaryExactWindowEdge(): void
    {
        $policy = new LicensePolicy(unreachableGraceDays: 3);
        // دقیقاً expires_at + 3 روز + 1 ثانیه → خارج از پنجره → UNREACHABLE
        $out = $this->compute(
            $this->doc(-3 * self::DAY - 1),
            LicenseStateMachine::UNREACHABLE,
            $policy
        );
        $this->assertSame(LicenseStatus::UNREACHABLE, $out['status']);
    }

    public function testUnreachableInsideWindowWithUnExpiredDocStaysActive(): void
    {
        // شبکه قطع ولی سند معتبر (۲۰ روز دیگر) → پنجره از expires محاسبه
        // می‌شود؛ داخل پنجره → همان وضعیت سند (ACTIVE) — مطب کار می‌کند
        $policy = new LicensePolicy(unreachableGraceDays: 3);
        $out = $this->compute($this->doc(20 * self::DAY), LicenseStateMachine::UNREACHABLE, $policy);
        $this->assertSame(LicenseStatus::ACTIVE, $out['status']);
    }

    public function testNoCachedStateUnreachable(): void
    {
        $out = $this->compute(null, LicenseStateMachine::UNREACHABLE, new LicensePolicy());
        $this->assertSame(LicenseStatus::UNREACHABLE, $out['status']);
        $this->assertSame('no_cached_state', $out['reason']);
    }

    public function testCustomShortGraceHonored(): void
    {
        $policy = new LicensePolicy(expiryGraceDays: 1);
        $out = $this->compute($this->doc(-2 * self::DAY), LicenseStateMachine::VERIFIED, $policy);
        // با مهلت ۱ روزه، ۲ روز پس از انقضا = RESTRICTED
        $this->assertSame(LicenseStatus::RESTRICTED, $out['status']);
    }

    public function testGraceWindowBoundaryUsesPolicy(): void
    {
        $policy = new LicensePolicy(expiryGraceDays: 7);
        // دقیقاً ۷ روز پس از انقضا: با تلورانس skew کم، هنوز GRACE؟ چون شرط
        // now > expires+grace+skew → 7 روز = GRACE
        $out = $this->compute($this->doc(-7 * self::DAY), LicenseStateMachine::VERIFIED, $policy);
        $this->assertSame(LicenseStatus::GRACE, $out['status']);
    }
}
