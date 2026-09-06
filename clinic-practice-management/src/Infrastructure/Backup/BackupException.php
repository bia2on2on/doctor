<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Backup;

use RuntimeException;

/**
 * خطای بکاپ/بازیابی — کد `CLINIC_BACKUP_*` (ADR-0019).
 */
final class BackupException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $data = []
    ) {
        parent::__construct($message);
    }

    public static function of(string $code, string $message, array $data = []): self
    {
        return new self($code, $message, $data);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
