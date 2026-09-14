<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Reports\ExportService;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
use ClinicCore\Settings\InstallationSettings;

/**
 * Job: notif.dispatch (هر دقیقه — background-jobs.md §2).
 *
 * N-2/N-3: ارسال اعلان‌های queued کانال Internal (queued → sent)
 * + Retention §5 (حذف فرستاده‌شده‌های > notif.archive_days روز)
 * + پاک‌سازی فایل‌های Export منقضی (reports.export_retention_days).
 *
 * Idempotency (J-2): UPDATE شرطی روی status — اجرای تکراری بی‌اثر.
 * کانال SMS پایپ‌لاین مستقل خودش را دارد (cpms_sms_messages + sms.send).
 *
 * Phase 2 M-2 (scope-neutral worker):
 *   ساختِ این handler به هیچ Clinic/Scope/کاربر/Context درخواست وابسته نیست.
 *   dispatch (جاروی queued→sent) و purge (بایگانی) مستقیماً از
 *   `NotificationRepository` انجام می‌شوند؛ روزهای نگهداری از
 *   `InstallationSettings::getNotifArchiveDays()` (سطح نصب، wp_options) می‌آید —
 *   نه از Settingsِ Clinic-scoped و نه از `App::scope()`.
 */
final class NotifDispatchHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly InstallationSettings $installationSettings,
        private readonly ExportService $exports
    ) {
    }

    public function __invoke(array $payload): int
    {
        $sent = $this->notifications->dispatchQueued(500);
        $this->notifications->purgeArchived($this->installationSettings->getNotifArchiveDays());
        $this->exports->purgeExpired();

        return $sent;
    }
}
