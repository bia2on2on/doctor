<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Visits\VisitService;

/**
 * No-show خودکار (FR-5.5 — Job: visits.no_show — هر دقیقه).
 *
 * نوبت‌های Confirmed گذشته از Grace (پیش‌فرض 30 دقیقه، تنظیم
 * `queue.no_show_grace_minutes`) بدون ویزیت فعال → no_show.
 * Idempotent: sweep داخل Row Lock دوباره وضعیت را چک می‌کند.
 */
final class VisitsNoShowHandler
{
    public function __construct(private readonly VisitService $visits)
    {
    }

    public function __invoke(array $payload): int
    {
        return $this->visits->processNoShows();
    }
}
