<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Validators;

/**
 * اعتبارسنجی کد ملی ۱۰ رقمی (algorism استاندارد + checksum) — خالص.
 */
final class NationalIdValidator
{
    public static function isValid(string $input): bool
    {
        $nid = preg_replace('/\D/', '', $input);
        if ($nid === null || strlen($nid) !== 10) {
            return false;
        }
        if (preg_match('/^(\d)\1{9}$/', $nid)) {
            return false; // مثل 1111111111
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $nid[$i] * (10 - $i);
        }
        $remainder = $sum % 11;

        if ($remainder === 0) {
            return (int) $nid[9] === 0;
        }
        if ($remainder === 1) {
            return (int) $nid[9] === 1;
        }

        return (int) $nid[9] === 11 - $remainder;
    }

    /**
     * Mask نمایشی (Audit): ***4567
     */
    public static function mask(string $nid): string
    {
        $clean = preg_replace('/\D/', '', $nid) ?: '';

        return strlen($clean) >= 4 ? '***' . substr($clean, -4) : '***';
    }
}
