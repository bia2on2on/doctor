<?php
declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use RuntimeException;

final class ReminderPayloadInvalidException extends RuntimeException
{
    /** @param array<string,mixed> $context */
    public function __construct(string $code, string $message, array $context = [])
    {
        parent::__construct($code . ': ' . $message);
    }
}
