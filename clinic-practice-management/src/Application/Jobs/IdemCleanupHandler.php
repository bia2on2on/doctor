<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Infrastructure\Security\Idempotency;

/**
 * پاک‌سازی کلیدهای Idempotency قدیمی (Job: cleanup.idem — F9 Hardening).
 *
 * ریشه: Idempotency::cleanup() از ابتدا «Job» مستند شده بود اما هیچ Jobی آن
 * را زمان‌بندی نمی‌کرد — رشد بی‌کران جدول hot-path با UNIQUE ایندکس
 * (DoS/حجم). هم‌راه cleanup.otp/cleanup.rate_limits در RECURRING_JOBS.
 */
final class IdemCleanupHandler
{
    public function __construct(private readonly Idempotency $idem)
    {
    }

    public function __invoke(array $payload): int
    {
        return $this->idem->cleanup(90);
    }
}
