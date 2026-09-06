<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * تأمین‌کننده‌ی وضعیت جاری مجوز برای LicenseGate — جداسازی تا Gate خالص
 * بماند و Provider (DB) قابل تعویض/تست باشد (ADR-0023 §2).
 */
interface LicenseStateProvider
{
    /**
     * وضعیت محاسبه‌شده‌ی جاری.
     *
     * @return array{status: string, reason: string, expires_at: int|null, needs_renewal: bool}
     */
    public function currentState(): array;

    /**
     * انتitlement‌های سند جاری (fail-closed برای کلید ناشناخته).
     */
    public function entitlements(): EntitlementRegistry;
}
