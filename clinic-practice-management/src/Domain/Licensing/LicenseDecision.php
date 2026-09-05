<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * نتیجه بررسی LicenseGate — Value Object خالص.
 * `reason` فقط کد فنی/Log است (مثلاً `license:grace`) — متن کاربر از Call-site می‌آید.
 */
final class LicenseDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason = ''
    ) {
    }

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
