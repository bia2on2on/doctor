<?php

declare(strict_types=1);

namespace ClinicCore\Application\Clinic;

use RuntimeException;

/**
 * Domain exception for clinic profile update.
 */
final class ClinicProfileException extends RuntimeException
{
    public const VALIDATION = 'CLINIC_VALIDATION_FAILED';
    public const NOT_FOUND = 'CLINIC_NOT_FOUND';
    public const PERMISSION_DENIED = 'CLINIC_PERMISSION_DENIED';
    public const QUERY_FAILED = 'CLINIC_QUERY_FAILED';
    public const AUTH_REQUIRED = 'CLINIC_AUTH_REQUIRED';
    public const SCOPE_REQUIRED = 'CLINIC_SCOPE_REQUIRED';

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus,
        private readonly array $data = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string,mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function of(string $code, string $message, int $http, array $data = []): self
    {
        return new self($code, $message, $http, $data);
    }
}
