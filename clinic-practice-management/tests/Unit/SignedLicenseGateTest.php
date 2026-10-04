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

    /**
     * Provider با status + reason صریح — Phase 16 Slice 1: «علتِ» RESTRICTED
     * (نه خودِ status) تعیین‌کننده است. `$reason = null` یعنی کلید `reason`
     * اصلاً در state حضور ندارد (shape ناقص → باید fail-closed بماند).
     *
     * @return LicenseStateProvider
     */
    private function providerWithReason(string $status, ?string $reason): LicenseStateProvider
    {
        return new class($status, $reason) implements LicenseStateProvider {
            public function __construct(private readonly string $status, private readonly ?string $reason)
            {
            }

            public function currentState(): array
            {
                $state = [
                    'status' => $this->status,
                    'expires_at' => null,
                    'needs_renewal' => false,
                ];
                if ($this->reason !== null) {
                    $state['reason'] = $this->reason;
                }

                return $state;
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

    public function testNotConfiguredAllowsOperationButFlagsSetup(): void
    {
        // فعال‌سازی‌نشده: مجاز (ایمنی بیمار §1) — Health/Admin Setup را نشان می‌دهند
        $gate = new SignedLicenseGate($this->provider(LicenseStatus::NOT_CONFIGURED));
        $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertTrue($gate->assert(LicenseGate::OP_PATIENT_CREATE)->allowed);
        $this->assertFalse($gate->isReadOnly());
        $this->assertSame(LicenseStatus::NOT_CONFIGURED, $gate->state());
    }

    public function testUnknownStateFailsClosed(): void
    {
        $gate = new SignedLicenseGate($this->provider('bogus-state'));
        $this->assertFalse($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertTrue($gate->isReadOnly());
    }

    // ============ Phase 16 Slice 1 — علتِ RESTRICTED تعیین‌کننده است ============
    //
    // جهت تجاری: واحد تجاری = Organization؛ جهت پایه = Annual License + Version
    // Rights. «انقضای عادی تجاری» (پایان سالانه) نباید فعالیت بالینیِ جدید را
    // قفل کند — درحالی‌که نمایش وضعیت/تمدید دست‌نخورده می‌ماند. تنها استثنای
    // به‌رسمیت‌شناخته‌شده: status=RESTRICTED + reason=expired. پایان پنجرهٔ
    // فعال‌سازی، vendor-unreachable/stale، suspension، revocation، invalid و
    // علتِ ناشناخته همگی همان رفتار قبلی را دارند (fail-closed).

    public function testOrdinaryCommercialExpirationAllowsEveryGatedNewBusinessOperation(): void
    {
        $gate = new SignedLicenseGate($this->providerWithReason(LicenseStatus::RESTRICTED, 'expired'));

        foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
            $this->assertTrue(
                $gate->assert($op)->allowed,
                "{$op} نباید صرفاً به‌خاطر انقضای عادی تجاری مسدود شود"
            );
        }

        // بهداشت/به‌روزرسانی/لغو — همچنان مجاز
        foreach ([LicenseGate::OP_PATIENT_UPDATE, LicenseGate::OP_APPOINTMENT_CANCEL] as $op) {
            $this->assertTrue($gate->assert($op)->allowed, "{$op} باید مجاز بماند");
        }

        // نمایش وضعیت/تمدید بدون تغییر باقی می‌ماند — فقط سیاستِ «فعالیت جدید»
        // برای همین یک علت آزاد می‌شود.
        $this->assertSame(LicenseStatus::RESTRICTED, $gate->state());
        $this->assertTrue($gate->isReadOnly());
    }

    public function testActivationWindowExhaustionStillBlocksNewBusiness(): void
    {
        foreach (['activation_window_expired', 'migration_grace_expired'] as $reason) {
            $gate = new SignedLicenseGate($this->providerWithReason(LicenseStatus::RESTRICTED, $reason));

            foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
                $decision = $gate->assert($op);
                $this->assertFalse($decision->allowed, "{$op} با reason={$reason} باید مسدود بماند");
                $this->assertStringStartsWith('license:', $decision->reason);
            }
            $this->assertTrue($gate->isReadOnly());
        }
    }

    public function testVendorUnreachableStaleExpirationStillBlocksNewBusiness(): void
    {
        // RESTRICTED + expired_unreachable = «انقضا با وضعیت vendor-unreachable/
        // stale» — نزدیک‌ترین همسایهٔ معنایی به انقضای عادی و صریحاً بیرون از
        // استثنای آن (تطبیق باید دقیق باشد، نه prefix/contains).
        $gate = new SignedLicenseGate($this->providerWithReason(LicenseStatus::RESTRICTED, 'expired_unreachable'));

        foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
            $this->assertFalse($gate->assert($op)->allowed, "{$op} با reason=expired_unreachable باید مسدود بماند");
        }
        $this->assertTrue($gate->isReadOnly());
    }

    public function testAbsentUnknownOrMalformedRestrictedReasonFailsClosed(): void
    {
        // کلید غایب، رشتهٔ خالی، علت ناشناخته و تطبیق‌های تقریبی — همه fail-closed.
        $reasons = [null, '', 'test', 'EXPIRED', ' expired', 'expired ', 'expiry', 'renewal_required'];
        foreach ($reasons as $reason) {
            $gate = new SignedLicenseGate($this->providerWithReason(LicenseStatus::RESTRICTED, $reason));

            foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
                $this->assertFalse(
                    $gate->assert($op)->allowed,
                    "{$op} با reason=" . var_export($reason, true) . ' باید fail-closed مسدود بماند'
                );
            }
            $this->assertTrue($gate->isReadOnly());
        }
    }

    public function testExpiredReasonDoesNotRelaxAnyOtherStatus(): void
    {
        // استثنا فقط برای status=RESTRICTED است؛ reason=expired به‌تنهایی کافی نیست.
        $statuses = [
            LicenseStatus::UNREACHABLE,
            LicenseStatus::INVALID,
            LicenseStatus::SUSPENDED,
            LicenseStatus::REVOKED,
            'bogus-state',
        ];
        foreach ($statuses as $status) {
            $gate = new SignedLicenseGate($this->providerWithReason((string) $status, 'expired'));
            $this->assertFalse(
                $gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed,
                "status={$status} با reason=expired باید مسدود بماند"
            );
        }
    }
}
