<?php

declare(strict_types=1);

namespace ClinicCore\Application\Authorization;

/**
 * Phase 3 Slice 1 — Authorization failure (typed, fail-closed).
 */
final class AuthorizationException extends \RuntimeException
{
    /** @var string */
    private string $errorCode;

    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $errorCode, string $message = '', array $data = [], int $httpStatus = 403, ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $errorCode, $httpStatus, $previous);
        $this->errorCode = $errorCode;
        $this->data = $data;
    }

    public function getErrorCode(): string
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

    public function getHttpStatus(): int
    {
        return (int) $this->getCode();
    }
}
