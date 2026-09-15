<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

/**
 * Fail-closed برای payload نامعتبر job ادامه‌دار no-show.
 *
 * یک payload خراب نباید بی‌صدا به عنوان root sweep از ابتدا شروع کند.
 *
 * M-4: این خطا **قطعی** است — اجرای مجددِ همان payload همان شکست را می‌دهد.
 * بنابراین با قرارداد `NonRetryableJobFailure` اعلام می‌شود و در همان اولین
 * تلاش نهایی (`failed`) می‌شود، بدونِ Requeue/Backoffِ عمومی.
 */
final class JobPayloadInvalidException extends \RuntimeException implements NonRetryableJobFailure
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $data = []
    ) {
        parent::__construct($message);
    }
}
