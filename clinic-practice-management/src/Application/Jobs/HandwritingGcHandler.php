<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Handwriting\HandwritingService;

/**
 * پاک‌سازی نسخه‌های قدیمی دست‌خط (Job: handwriting.gc — background-jobs.md).
 *
 * سیاست نگهداری ADR-0009: حذف نسخه‌های قدیمی‌تر از `hw.version_max_age_days`
 * که خارج از `hw.version_keep` نسخه آخر هر صفحه هستند — نسخه‌های تازه و
 * آخرین نسخه هر صفحه هرگز حذف نمی‌شوند (K-6: صفحه زنده در _pages می‌ماند).
 */
final class HandwritingGcHandler
{
    public function __construct(private readonly HandwritingService $handwriting)
    {
    }

    public function __invoke(array $payload): int
    {
        return $this->handwriting->purgeVersions();
    }
}
