<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Infrastructure\Licensing\LicenseGatewayException;
use ClinicCore\Infrastructure\Logging\OpLogger;

/**
 * Job دوره‌ای refresh لایسنس (ADR-0023 / ADR-0016 / spec §26):
 *  - هر Tick صدا زده می‌شود ولی شبکه را فقط وقتی refreshDue() صادق باشد لمس
 *    می‌کند (Backoff بر اساس تعداد شکست‌های پیاپی — رشد فاصله، بدون حمله به
 *    سرور فروشنده).
 *  - شکست در cpms_license_state ثبت و در Health دیده می‌شود؛ Job را fail
 *    نمی‌کنیم (زمان‌بندی مجدد دوره‌ای خودش Retry است) و هرگز مسیر درخواست
 *    را لمس نمی‌کند.
 *  - هیچ PHI در درخواست/خطاها (فقط شناسه‌های نصب/مجوز + کد خطا) — ADR-0028.
 */
final class LicenseRefreshHandler
{
    public function __construct(
        private readonly LicenseService $licenses,
        private readonly OpLogger $op
    ) {
    }

    public function __invoke(array $payload = []): void
    {
        if (!$this->licenses->refreshDue()) {
            return; // حالت عادی — هزینه‌ی ~صفر
        }

        try {
            $this->licenses->refresh();
            $this->op->info('LICENSE_REFRESH_OK', [
                'status' => $this->licenses->currentState()['status'],
            ]);
        } catch (LicenseGatewayException $e) {
            // ثبت خطا در state داخل refresh() انجام شده؛ اینجا فقط Log عملیاتی
            // (بدون جزئیات PHI/توکن — ADR-0028).
            $this->op->warning('LICENSE_REFRESH_FAILED', [
                'code' => $e->apiCode(),
                'retryable' => $e->retryable ? 1 : 0,
            ]);
        }
    }
}
