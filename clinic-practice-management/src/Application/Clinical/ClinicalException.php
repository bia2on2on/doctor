<?php

declare(strict_types=1);

namespace ClinicCore\Application\Clinical;

use DomainException;

/**
 * خطای دامنه بالینی (F5) — کد `CLINIC_*` (ADR-0019) + HTTP + Data.
 * Controller این Exception را مستقیم به WP_Error نگاشت می‌کند.
 */
final class ClinicalException extends DomainException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $data = []
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function of(string $code, string $message, int $http = 400, array $data = []): self
    {
        return new self($code, $message, $http, $data);
    }
}
