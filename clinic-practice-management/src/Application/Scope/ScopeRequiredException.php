<?php

declare(strict_types=1);

namespace ClinicCore\Application\Scope;

use RuntimeException;

/**
 * خطای نبودِ Scope صریح — Phase 2 (ADR-0031، fail-closed).
 *
 * کد API: `CLINIC_SCOPE_REQUIRED` (HTTP 400). وقتی Clinic فعال قابل تعیین
 * نیست (چند Clinic بدون انتخاب صریح، یا هیچ Clinicی)، عملیات **بسته** می‌شود؛
 * هیچ fallback implicit (کلینیک پیش‌فرض/اولین) وجود ندارد.
 */
final class ScopeRequiredException extends RuntimeException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $data = [],
        private readonly int $status = 400
    ) {
        parent::__construct($message);
    }

    public function apiCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}
