<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

/**
 * ردِ Fail-Closed برای نوع Jobِ بدون طبقهٔ scope یا با طبقهٔ نامعتبر
 * (A-1.10 + RT-12 + §A-3).
 *
 * این خطا **هرگز** با حدس زدن «system» یا «اولین Clinic» جایگزین نمی‌شود؛
 * در `JobsDispatcher::tick()` مانند هر خطای handler دیگر ثبت و ایزوله می‌شود
 * تا کلِ tick متوقف نشود (RT-14).
 */
final class JobScopeUnknownException extends \RuntimeException
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
