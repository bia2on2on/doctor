<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Sms;

use RuntimeException;

final class SmsTemplateException extends RuntimeException
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

    public function apiCode(): string
    {
        return $this->errorCode;
    }
}
