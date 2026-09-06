<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\EntitlementRegistry;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseStateProvider;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use PHPUnit\Framework\TestCase;

/**
 * F10 — SignedLicenseGate: رفتار عملیات در وضعیت‌های مختلف (spec §16/§17).
 *
 * سیاست RESTRICTED: مسدود = فعالیت مستقل جدید؛ مجاز = لغو/به‌روزرسانی/
 * بهداشت (و همه‌ی مسیرهای در جریان که assert نمی‌کنند — الگوی F4).
 */
final class SignedLicenseGateTest extends TestCase
{
    /**
     * @return LicenseStateProvider
     */
    private function provider(string $status): LicenseStateProvider
    {
        return new class($status) implements LicenseStateProvider {
            public function __construct(private readonly string $status)
            {
            }

            public function currentState(): array
            {
                return [
                    'status' => $this->status,
                    'reason' => 'test',
                    'expires_at' => null,
                    'needs_renewal' => false,
                ];
            }

            public function entitlements(): EntitlementRegistry
            {
                return new EntitlementRegistry();
            }
        };
    }

    public function testActiveAllowsAllProtectedOps(): void
    {
        $gate = new SignedLicenseGate($this->provider(LicenseStatus::ACTIVE));
        foreach ([
            LicenseGate::OP_PATIENT_CREATE,
            LicenseGate::OP_PATIENT_UPDATE,
            LicenseGate::OP_APPOINTMENT_BOOK,
            LicenseGate::OP_APPOINTMENT_CANCEL,
            LicenseGate::OP_APPOINTMENT_RESCHEDULE,
            LicenseGate::OP_VISIT_CHECKIN,
            LicenseGate::OP_INVOICE_CREATE,
        ] as $op) {
            $this->assertTrue($gate->assert($op)->allowed, "{$op} باید در ACTIVE مجاز باشد");
        }
        $this->assertFalse($gate->isReadOnly());
        $this->assertSame(LicenseStatus::ACTIVE, $gate->state());
    }

    public function testRestrictedBlocksNewBusinessButAllowsHygieneOps(): void
    {
        $gate = new SignedLicenseGate($this->provider(LicenseStatus::RESTRICTED));

        foreach ([
            LicenseGate::OP_PATIENT_CREATE,
            LicenseGate::OP_APPOINTMENT_BOOK,
            LicenseGate::OP_APPOINTMENT_RESCHEDULE,
            LicenseGate::OP_VISIT_CHECKIN,
            LicenseGate::OP_INVOICE_CREATE,
        ] as $op) {
            $decision = $gate->assert($op);
            $this->assertFalse($decision->allowed, "{$op} باید در RESTRICTED مسدود شود");
            $this->assertStringStartsWith('license:', $decision->reason);
        }

        // بهداشت/تکمیل/لغو — مجاز (spec §16)
        foreach ([
            LicenseGate::OP_PATIENT_UPDATE,
            LicenseGate::OP_APPOINTMENT_CANCEL,
        ] as $op) {
            $this->assertTrue($gate->assert($op)->allowed, "{$op} باید در RESTRICTED مجاز بماند");
        }
        $this->assertTrue($gate->isReadOnly());
    }

    public function testRevokedAndSuspendedAlsoBlockNewBusiness(): void
    {
        foreach ([LicenseStatus::REVOKED, LicenseStatus::SUSPENDED] as $status) {
            $gate = new SignedLicenseGate($this->provider($status));
            $this->assertFalse($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
            $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_CANCEL)->allowed);
            $this->assertTrue($gate->assert(LicenseGate::OP_PATIENT_UPDATE)->allowed);
        }
    }

    public function testGraceStillAllowsNewBusiness(): void
    {
        // GRACE (میراث F3 read-only) در مدل F10 = هشدار برجسته ولی فعالیت
        // مجاز تا پایان مهلت — این رفتار توسط ADR-0023 جایگزین معنای قدیمی شد
        $gate = new SignedLicenseGate($this->provider(LicenseStatus::GRACE));
        $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
    }

    public function testUnreachableWithoutCacheBlocksNewBusiness(): void
    {
        $gate = new SignedLicenseGate($this->provider(LicenseStatus::UNREACHABLE));
        $this->assertFalse($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_CANCEL)->allowed);
        $this->assertTrue($gate->isReadOnly());
    }

    public function testUnknownStateFailsClosed(): void
    {
        $gate = new SignedLicenseGate($this->provider('bogus-state'));
        $this->assertFalse($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertTrue($gate->isReadOnly());
    }
}
