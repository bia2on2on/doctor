<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\ActiveLicenseGate;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseState;
use PHPUnit\Framework\TestCase;

/**
 * LicenseGate Seam (F3) — ADR-0023/C3:
 * - پیش‌فرض F3: همیشه ACTIVE، بدون I/O.
 * - Business Services فقط با Interface کار می‌کنند (Test روی Contract).
 */
final class ActiveLicenseGateTest extends TestCase
{
    private ActiveLicenseGate $gate;

    protected function setUp(): void
    {
        $this->gate = new ActiveLicenseGate();
    }

    public function testImplementsLicenseGateInterface(): void
    {
        $this->assertInstanceOf(LicenseGate::class, $this->gate);
    }

    public function testAllProtectedOperationsAllowedByDefault(): void
    {
        foreach ([
            LicenseGate::OP_PATIENT_CREATE,
            LicenseGate::OP_APPOINTMENT_BOOK,
            LicenseGate::OP_APPOINTMENT_CANCEL,
            LicenseGate::OP_APPOINTMENT_RESCHEDULE,
            LicenseGate::OP_VISIT_CHECKIN,
            LicenseGate::OP_INVOICE_CREATE,
        ] as $op) {
            $decision = $this->gate->assert($op, ['foo' => 'bar']);
            $this->assertTrue($decision->allowed, "operation {$op} باید در F3 آزاد باشد");
            $this->assertSame('', $decision->reason);
        }
    }

    public function testStateIsActiveAndNotReadOnly(): void
    {
        $this->assertSame(LicenseState::ACTIVE, $this->gate->state());
        $this->assertFalse($this->gate->isReadOnly());
    }

    public function testReadOnlySemanticsForAllNonActiveStates(): void
    {
        $this->assertFalse(LicenseState::isReadOnly(LicenseState::ACTIVE));
        foreach ([LicenseState::GRACE, LicenseState::EXPIRED, LicenseState::INVALID, LicenseState::UNKNOWN] as $state) {
            $this->assertTrue(LicenseState::isReadOnly($state), "state {$state} باید Read-Only باشد");
        }
    }
}
