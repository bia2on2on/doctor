<?php

declare(strict_types=1);

namespace ClinicCore\Application\Reports;

use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Settings\Settings;

/**
 * بستهٔ وابستگی‌های **وابسته به Clinic** برای Export.
 *
 * Phase 2 (Slice 1B.1 — رفعِ یافتهٔ بازبینیِ امنیتیِ PR #27):
 * `ExportService` دیگر وابستگی‌های Clinic-دار را در سازنده تزریق نمی‌گیرد.
 * پیش‌تر ساختِ `ExportService` مستلزمِ نمونهٔ `Settings`/`ReportService`/
 * `NotificationService`/`LocalFileStorage` بود و چون `Settings` از Scope حل
 * می‌شد، `App::dispatcher()` مجبور بود `clinic_id` **خامِ** payload را به
 * `ScopeContext` موردِ اعتماد bind کند — یعنی «context» پیش از هر مجوزی
 * برقرار می‌شد. این همان الگوی confused-deputy است.
 *
 * اکنون این بسته فقط **پس از** تعیینِ Clinic توسط یک مرزِ قابلِ اعتماد ساخته
 * می‌شود:
 *  - مسیر Job: پس از `clinicIdFromJobPayload()` **و** `requireClinicMembership()`؛
 *  - مسیر REST: پس از `trustedClinicId()` (که خودش از `TrustedClinicEstablisher`
 *    و عضویتِ تأییدشده می‌آید).
 *
 * خودِ این کلاس هیچ منطق و هیچ مجوزی ندارد — فقط یک حاملِ تغییرناپذیر است.
 */
final class ExportClinicDeps
{
    public function __construct(
        public readonly ReportService $reports,
        public readonly NotificationService $notifications,
        public readonly LocalFileStorage $storage,
        public readonly Settings $settings
    ) {
    }
}
