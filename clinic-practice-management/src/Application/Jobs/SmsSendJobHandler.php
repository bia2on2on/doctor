<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Notifications\SmsService;

/**
 * Job: sms.send — ارسال یک Message از Queue (ADR-0025، الزام §19).
 *
 * Idempotency: dispatchMessage فقط روی رکوردهای غیر-Terminal عمل می‌کند؛
 * اجرای تکراری Job روی پیام SENT/FAILED بی‌اثر است.
 * Retry: JobQueue (backoff + max از Settings) + Policy در SmsService (بدون Blind Retry).
 */
final class SmsSendJobHandler
{
    public function __construct(private readonly SmsService $sms)
    {
    }

    public function __invoke(array $payload): void
    {
        $messageId = (int) ($payload['message_id'] ?? 0);
        if ($messageId <= 0) {
            return;
        }

        $this->sms->dispatchMessage($messageId);
    }
}
