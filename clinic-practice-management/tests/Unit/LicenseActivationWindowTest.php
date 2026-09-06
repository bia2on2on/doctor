<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\LicensePolicy;
use ClinicCore\Domain\Licensing\LicenseStateMachine;
use ClinicCore\Domain\Licensing\LicenseStatus;
use PHPUnit\Framework\TestCase;

/**
 * F10 — پنجرهٔ فعال‌سازی (تصمیم کارفرما): نصب تازه ۷ روز
 * (ACTIVATION_PENDING) و نصب pre-F10 با مهلت مهاجرت ۳۰ روز
 * (ACTIVATION_GRACE)؛ پایان پنجره بدون سند → RESTRICTED.
 * مرزها، reasonها و anti-extension ساعت (start در آینده) اینجا خالص
 * و بدون DB/شبکه تست می‌شوند.
 */
final class LicenseActivationWindowTest extends TestCase
{
    private const DAY = 86400;
    private LicensePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new LicensePolicy();
    }

    public function testFreshWindowDay0IsActivationPending(): void
    {
        $now = 1_800_000_000;
        $out = LicenseStateMachine::computeActivationWindow($now, 'fresh', $now, $this->policy);

        $this->assertSame(LicenseStatus::ACTIVATION_PENDING, $out['status']);
        $this->assertSame('activation_pending', $out['reason']);
        $this->assertSame($now + 7 * self::DAY, $out['expires_at']);
        $this->assertTrue($out['needs_renewal']);
    }

    public function testFreshWindowInsideSevenDaysIsPending(): void
    {
        $start = 1_800_000_000;
        $out = LicenseStateMachine::computeActivationWindow($start, 'fresh', $start + 6 * self::DAY, $this->policy);

        $this->assertSame(LicenseStatus::ACTIVATION_PENDING, $out['status']);
    }

    public function testFreshWindowAtBoundaryDay7StillPending(): void
    {
        $start = 1_800_000_000;
        $out = LicenseStateMachine::computeActivationWindow($start, 'fresh', $start + 7 * self::DAY, $this->policy);

        $this->assertSame(LicenseStatus::ACTIVATION_PENDING, $out['status']);
    }

    public function testFreshWindowAfterSevenDaysWithoutLicenseIsRestricted(): void
    {
        $start = 1_800_000_000;
        $out = LicenseStateMachine::computeActivationWindow($start, 'fresh', $start + 7 * self::DAY + 1, $this->policy);

        $this->assertSame(LicenseStatus::RESTRICTED, $out['status']);
        $this->assertSame('activation_window_expired', $out['reason']);
        $this->assertTrue($out['needs_renewal']);
    }

    public function testMigrationGraceBeforeDay30IsActivationGrace(): void
    {
        $start = 1_800_000_000;
        $out = LicenseStateMachine::computeActivationWindow($start, 'migration', $start + 29 * self::DAY, $this->policy);

        $this->assertSame(LicenseStatus::ACTIVATION_GRACE, $out['status']);
        $this->assertSame('migration_grace', $out['reason']);
        $this->assertSame($start + 30 * self::DAY, $out['expires_at']);
    }

    public function testMigrationGraceAfterDay30WithoutLicenseIsRestricted(): void
    {
        $start = 1_800_000_000;
        $out = LicenseStateMachine::computeActivationWindow($start, 'migration', $start + 30 * self::DAY + 1, $this->policy);

        $this->assertSame(LicenseStatus::RESTRICTED, $out['status']);
        $this->assertSame('migration_grace_expired', $out['reason']);
    }

    public function testClockRolledBackCannotExtendWindow(): void
    {
        // سناریو: start واقعاً در T ثبت شده، سپس ساعت سرور ۲ روز به عقب کشیده
        // شده (now = T - 2d). بدون مهار، پایان پنجره T+7d می‌شد و کاربر ۹ روز
        // مهلت می‌گرفت. مهار: شروع مؤثر = now → پایان = now+7d (فقط ۷ روز از
        // همین الان؛ هرگز طولانی‌تر از مقدار مقرر).
        $start = 1_800_000_000;
        $clockRolledBack = $start - 2 * self::DAY;
        $out = LicenseStateMachine::computeActivationWindow($start, 'fresh', $clockRolledBack, $this->policy);

        $this->assertSame(LicenseStatus::ACTIVATION_PENDING, $out['status']);
        $this->assertSame($clockRolledBack + 7 * self::DAY, $out['expires_at']);
        $this->assertLessThan($start + 7 * self::DAY, $out['expires_at']);
    }

    public function testNoWindowRowIsNotConfiguredDefensive(): void
    {
        $out = LicenseStateMachine::computeActivationWindow(null, 'fresh', 1_800_000_000, $this->policy);

        $this->assertSame(LicenseStatus::NOT_CONFIGURED, $out['status']);
        $this->assertFalse($out['needs_renewal']);
    }

    public function testPreActivationStatesAllowNewBusiness(): void
    {
        foreach ([
            LicenseStatus::NOT_CONFIGURED,
            LicenseStatus::ACTIVATION_PENDING,
            LicenseStatus::ACTIVATION_GRACE,
            LicenseStatus::DEVELOPMENT,
        ] as $status) {
            $this->assertTrue(LicenseStatus::allowsNewBusiness($status), "{$status} باید فعالیت راه‌اندازی را مجاز کند");
            $this->assertFalse(LicenseStatus::isRestricted($status));
        }

        foreach ([LicenseStatus::RESTRICTED, LicenseStatus::REVOKED, LicenseStatus::SUSPENDED] as $status) {
            $this->assertFalse(LicenseStatus::allowsNewBusiness($status));
            $this->assertTrue(LicenseStatus::isRestricted($status));
        }
    }

    public function testPreActivationConstantList(): void
    {
        $this->assertContains(LicenseStatus::NOT_CONFIGURED, LicenseStatus::PRE_ACTIVATION);
        $this->assertContains(LicenseStatus::ACTIVATION_PENDING, LicenseStatus::PRE_ACTIVATION);
        $this->assertContains(LicenseStatus::ACTIVATION_GRACE, LicenseStatus::PRE_ACTIVATION);
        $this->assertNotContains(LicenseStatus::DEVELOPMENT, LicenseStatus::PRE_ACTIVATION);
        $this->assertNotContains(LicenseStatus::RESTRICTED, LicenseStatus::PRE_ACTIVATION);
    }

    public function testAllNewStatusesAreValid(): void
    {
        foreach ([
            LicenseStatus::ACTIVATION_PENDING,
            LicenseStatus::ACTIVATION_GRACE,
            LicenseStatus::DEVELOPMENT,
        ] as $status) {
            $this->assertContains($status, LicenseStatus::VALID);
        }
    }
}
