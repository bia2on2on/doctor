<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Machine;

use RuntimeException;

/**
 * تغییر وضعیت نامعتبر (State Machine) — کد خطای عمومی: CLINIC_INVALID_TRANSITION.
 */
final class InvalidTransitionException extends RuntimeException
{
    public const CODE = 'CLINIC_INVALID_TRANSITION';

    public static function code(): string
    {
        return self::CODE;
    }
}
