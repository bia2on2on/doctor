<?php

declare(strict_types=1);

namespace ClinicCore\Application\Auth;

use RuntimeException;

/**
 * خطای جریان OTP — کدهای API: CLINIC_MOBILE_INVALID, CLINIC_OTP_COOLDOWN, CLINIC_OTP_DAILY_LIMIT,
 * CLINIC_OTP_LOCKED, CLINIC_OTP_INVALID, CLINIC_OTP_EXPIRED, CLINIC_RATE_LIMITED.
 */
final class OtpException extends RuntimeException
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

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            'CLINIC_MOBILE_INVALID', 'CLINIC_OTP_INVALID', 'CLINIC_OTP_EXPIRED' => 400,
            'CLINIC_OTP_COOLDOWN', 'CLINIC_OTP_DAILY_LIMIT', 'CLINIC_OTP_LOCKED', 'CLINIC_RATE_LIMITED' => 429,
            default => 400,
        };
    }
}
