<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Application\Reports\ExportService;

/**
 * Job: notif.dispatch (هر دقیقه — background-jobs.md §2).
 *
 * N-2/N-3: ارسال اعلان‌های queued کانال Internal (queued → sent)
 * + Retention §5 (حذف فرستاده‌شده‌های > notif.archive_days روز)
 * + پاک‌سازی فایل‌های Export منقضی (reports.export_retention_days).
 *
 * Idempotency (J-2): UPDATE شرطی روی status — اجرای تکراری بی‌اثر.
 * کانال SMS پایپ‌لاین مستقل خودش را دارد (cpms_sms_messages + sms.send).
 */
final class NotifDispatchHandler
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ExportService $exports
    ) {
    }

    public function __invoke(array $payload): int
    {
        $result = $this->notifications->dispatchQueued();
        $this->exports->purgeExpired();

        return (int) ($result['sent'] ?? 0);
    }
}
