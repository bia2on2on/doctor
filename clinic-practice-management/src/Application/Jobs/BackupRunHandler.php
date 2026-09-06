<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Backup\BackupService;
use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Settings\Settings;

/**
 * Job دوره‌ای بکاپ (spec §22–§24): هر Tick چک می‌شود ولی فقط وقتی
 * enabled + سررسید (backup.interval_hours) باشد اجرا می‌شود.
 *
 * اجرا در صف = تک‌کاره (بدون دو بکاپ هم‌زمان — ADR-0016 J-3). شکست → Job
 * fail با Backoff (بدون حمله‌ی دیسک). بدون PHI در Log.
 */
final class BackupRunHandler
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly Settings $settings,
        private readonly OpLogger $op
    ) {
    }

    public function __invoke(array $payload = []): void
    {
        if (!(bool) $this->settings->get('backup.enabled', false)) {
            return; // پیش‌فرض خاموش — فعال‌سازی آگاهانه (Admin/CLI)
        }
        $now = time();
        $intervalH = max(1, (int) $this->settings->get('backup.interval_hours', 24));
        $lastRun = (int) $this->settings->get('backup.last_run_at', 0);
        if ($lastRun > 0 && ($now - $lastRun) < $intervalH * 3600) {
            return; // هنوز سررسید نشده
        }

        try {
            $this->backups->createBackup('scheduled');
        } catch (BackupException $e) {
            $this->op->error('BACKUP_JOB_FAILED', ['code' => $e->getErrorCode()]);
            throw $e; // Job fail → retry با Backoff (دیده‌شونده در Health)
        }
    }
}
