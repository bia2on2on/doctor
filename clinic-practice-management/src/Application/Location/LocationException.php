<?php

declare(strict_types=1);

namespace ClinicCore\Application\Location;

use RuntimeException;

/**
 * Domain exception for Location master-data writes (create + name/timezone update).
 *
 * Semantics mirror ClinicProfileException: errorCode + httpStatus + data برای
 * نگاشت مرز transport (admin/REST) به پیام‌های ساختاریافته و parity-safe.
 */
final class LocationException extends RuntimeException
{
    public const VALIDATION = 'LOCATION_VALIDATION_FAILED';
    public const NOT_FOUND = 'LOCATION_NOT_FOUND';
    public const CONFLICT = 'LOCATION_CONFLICT';
    public const PERMISSION_DENIED = 'LOCATION_PERMISSION_DENIED';
    public const QUERY_FAILED = 'LOCATION_QUERY_FAILED';
    public const AUTH_REQUIRED = 'LOCATION_AUTH_REQUIRED';
    public const SCOPE_REQUIRED = 'LOCATION_SCOPE_REQUIRED';

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
