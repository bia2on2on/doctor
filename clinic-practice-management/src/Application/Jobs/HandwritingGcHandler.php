<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Handwriting\HandwritingService;

/**
 * پاک‌سازی نسخه‌های قدیمی دست‌خط (Job: handwriting.gc — background-jobs.md).
 *
 * Phase 2 M-2 — طبقهٔ **W** (installation-wide sweep با semantics پر-ردیفِ
 * Clinic): خودِ Job به هیچ Scope محیطی نیاز ندارد (payload خالی، همانند
 * `scheduleRecurringJobs()`). مالکیتِ دائمیِ هر ردیف از رابطهٔ
 * `versions.page_id → pages.document_id → documents.clinic_id` مشتق می‌شود و
 * ردیف‌های هر Clinic فقط با `hw.version_keep` + `hw.version_max_age_days`
 * **خودِ همان** Clinic (Clinic-owned در `cpms_settings`) پاک‌سازی می‌شوند.
 * کرانِ کارِ هر فراخوانی = `HandwritingService::GC_PAGE_BATCH_SIZE` صفحهٔ
 * کاندیدا (LIMIT واقعی در انتخابِ کاندیدا)؛ فراخوانیِ بعدیِ Job کارِ
 * باقی‌مانده را ادامه می‌دهد (بدونِ OFFSET، بدونِ cursor دائمی).
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
