<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Reports\ExportService;
use ClinicCore\Infrastructure\Repository\NotificationRepository;

/**
 * Job: notif.dispatch (هر دقیقه — background-jobs.md §2).
 *
 * N-2/N-3: ارسال اعلان‌های queued کانال Internal (queued → sent)
 * + پاک‌سازی فایل‌های Export منقضی (reports.export_retention_days).
 *
 * Idempotency (J-2): UPDATE شرطی روی status — اجرای تکراری بی‌اثر.
 * کانال SMS پایپ‌لاین مستقل خودش را دارد (cpms_sms_messages + sms.send).
 *
 * Phase 2 M-2 (scope-neutral worker): ساختِ این handler دیگر به
 * `NotificationService` (و در نتیجه `App::scope()` / Settingsِ Clinic-scoped)
 * وابسته نیست؛ وابستگیِ dispatch مستقیماً `NotificationRepository` است که
 * جاروی queued→sent را بدون هیچ Clinic/Settings اجرا می‌کند.
 *
 * PURGE / RETENTION (notif.archive_days):
 *   پاک‌سازیِ بایگانیِ اعلان‌های Internal (Retention §5 — 90 روز) عمداً از
 *   مسیرِ اجرای `notif.dispatch` جدا شد، چون `notif.archive_days` امروز از
 *   Settingsِ Clinic-scoped خوانده می‌شود و معنای «نصب‌گسترده در برابر
 *   per-Clinic» آن همچنان OPEN است (phase2-tenant-context-remediation-design
 *   §۸-۲). قابلیتِ زیرین (`NotificationRepository::purgeArchived` و
 *   `NotificationService::dispatchQueued`) دست‌نخورده باقی مانده است؛ فقط
 *   اجرای retention از مسیرِ این job خارج شده و به‌عنوان آیتمِ بازِ فاز ۲
 *   ثبت می‌شود (بدون job جدید و بدون تصمیمِ سیاستی در این برش).
 */
final class NotifDispatchHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly ExportService $exports
    ) {
    }

    public function __invoke(array $payload): int
    {
        $sent = $this->notifications->dispatchQueued(500);
        $this->exports->purgeExpired();

        return $sent;
    }
}
