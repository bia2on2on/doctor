<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\EntitlementRegistry;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseStateMachine;
use ClinicCore\Domain\Licensing\LicenseStateProvider;
use ClinicCore\Domain\Licensing\PostExpirationPolicy;
use ClinicCore\Domain\Licensing\ReturningPatientEvidence;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use PHPUnit\Framework\TestCase;

final class PostExpirationPolicyTest extends TestCase
{
    private function gate(int $expires, int $now, bool $returning = false): SignedLicenseGate
    {
        $provider = new class($expires, $now) implements LicenseStateProvider {
            public function __construct(public int $expires, public int $now) {}
            public function currentState(): array {
                return LicenseStateMachine::compute(['expires_at' => $this->expires], LicenseStateMachine::VERIFIED, $this->now);
            }
            public function entitlements(): EntitlementRegistry { return new EntitlementRegistry(); }
        };
        $evidence = new class($returning) implements ReturningPatientEvidence {
            public function __construct(private bool $returning) {}
            public function qualifies(int $patient_id, int $clinic_id, int $expires_at): bool { return $this->returning; }
        };
        return new SignedLicenseGate($provider, $evidence, static fn (): int => $now);
    }

    public function testBookingAndCommercialFeaturesStopExactlyAtSignedT0DespiteGraceAndSkew(): void
    {
        $t0 = 1793448000;
        foreach ([LicenseGate::OP_APPOINTMENT_BOOK, LicenseGate::OP_APPOINTMENT_RESCHEDULE, LicenseGate::OP_SMS_COMMERCIAL, LicenseGate::OP_HANDWRITING_NEW] as $op) {
            $this->assertTrue($this->gate($t0, $t0 - 1)->assert($op)->allowed);
            foreach ([$t0, $t0 + 1, $t0 + 300, $t0 + 7 * 86400, $t0 + 200 * 86400] as $now) {
                $this->assertFalse($this->gate($t0, $now, true)->assert($op, ['reception' => true])->allowed, $op . ' at ' . $now);
            }
        }
    }

    public function testGregorianMonthsClampAndKeepUtcTime(): void
    {
        foreach ([
            ['2028-01-31 12:34:56', 1, '2028-02-29 12:34:56'],
            ['2027-01-31 12:34:56', 1, '2027-02-28 12:34:56'],
            ['2026-08-31 23:59:59', 6, '2027-02-28 23:59:59'],
            ['2027-12-31 12:34:56', 1, '2028-01-31 12:34:56'],
        ] as [$start, $months, $expected]) {
            $this->assertSame(strtotime($expected . ' UTC'), PostExpirationPolicy::deadline(strtotime($start . ' UTC'), $months));
        }
    }

    public function testNewAndVerifiedReturningPatientExactHalfOpenWindows(): void
    {
        $t0 = strtotime('2028-01-31 12:34:56 UTC');
        $context = ['patient_id' => 42, 'clinic_id' => 7, 'reception' => true];
        foreach ([LicenseGate::OP_PATIENT_CREATE, LicenseGate::OP_VISIT_CHECKIN] as $op) {
            foreach ([false => 1, true => 6] as $returning => $months) {
                $end = PostExpirationPolicy::deadline($t0, $months);
                $this->assertTrue($this->gate($t0, $end - 1, (bool) $returning)->assert($op, $context)->allowed);
                $this->assertFalse($this->gate($t0, $end, (bool) $returning)->assert($op, $context)->allowed);
                $this->assertFalse($this->gate($t0, $end + 1, (bool) $returning)->assert($op, $context)->allowed);
            }
            $this->assertFalse($this->gate($t0, $t0)->assert($op)->allowed);
            $this->assertTrue($this->gate($t0, $t0, true)->assert($op)->allowed);
        }
    }

    public function testContinuityAndRenewal(): void
    {
        $t0 = strtotime('2027-01-31 UTC');
        $now = PostExpirationPolicy::deadline($t0, 6);
        foreach ([LicenseGate::OP_PATIENT_UPDATE, LicenseGate::OP_APPOINTMENT_CANCEL, LicenseGate::OP_INVOICE_CREATE, 'clinical.note', 'clinical.export', 'backup.restore', 'payment.refund'] as $op) {
            $this->assertTrue($this->gate($t0, $now)->assert($op)->allowed);
        }
        $this->assertFalse($this->gate($t0, $now)->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertTrue($this->gate($now + 86400, $now)->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
    }
}
