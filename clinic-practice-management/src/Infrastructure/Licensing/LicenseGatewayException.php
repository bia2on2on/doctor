<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Licensing;

use RuntimeException;

/**
 * خطای Gateway سرویس مجوز — کد `CLINIC_*` (ADR-0019) + پرچم Retryable.
 * فقط Job refresh (با Backoff) retry می‌کند؛ مسیر درخواست هرگز شبکه نمی‌رود.
 */
final class LicenseGatewayException extends RuntimeException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly string $errorCode = 'CLINIC_LICENSE_SERVER_ERROR',
        public readonly array $data = []
    ) {
        parent::__construct($message);
    }

    public function apiCode(): string
    {
        return $this->errorCode;
    }
}
