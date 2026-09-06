<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Reports\ExportService;

/**
 * Job: report.export (رویدادی — background-jobs.md §2).
 *
 * Exportهای سنگین (CSV) خارج از مسیر REST (performance-baseline §18):
 * فایل + اعلان «آماده شد» به درخواست‌دهنده.
 *
 * Idempotency (J-2): ردیف Job با Row Lock (JobQueue claim)؛ اگر Job تکراری
 * اجرا شود اعلان جدیدی می‌سازد ولی فایل قبلی تا Retention قابل دانلود است.
 */
final class ReportExportHandler
{
    public function __construct(private readonly ExportService $exports)
    {
    }

    public function __invoke(array $payload): void
    {
        $this->exports->generate($payload);
    }
}
