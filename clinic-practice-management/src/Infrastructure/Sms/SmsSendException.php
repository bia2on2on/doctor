<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Sms;

use RuntimeException;

/**
 * خطای ارسال SMS از Provider — با پرچم Retryable (ADR-0025، الزام §20).
 *
 * @see docs/sms/adding-provider.md §2
 */
final class SmsSendException extends RuntimeException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly string $errorCode = 'SMS_PROVIDER_FAILED',
        public readonly array $data = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function apiCode(): string
    {
        return $this->errorCode;
    }
}
