<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Validators;

/**
 * اعتبارسنجی/عادی‌سازی موبایل ایران — خالص.
 *
 * شکل عادی‌شده: 09xxxxxxxxx (11 رقم).
 * ورودی‌های مجاز: 09121234567 / 9121234567 / +989121234567 / 00989121234567 / 0912 123 4567
 */
final class MobileValidator
{
    /**
     * @return string|null شکل عادی‌شده، یا null اگر نامعتبر
     */
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/[^\d]/', '', $input);
        if ($digits === null || $digits === '') {
            return null;
        }

        $len = strlen($digits);
        if ($len === 11 && str_starts_with($digits, '09')) {
            return $digits;
        }
        if ($len === 10 && str_starts_with($digits, '9')) {
            return '0' . $digits;
        }
        if ($len === 12 && (str_starts_with($digits, '9809') || str_starts_with($digits, '989'))) {
            return '0' . substr($digits, 2);
        }
        if ($len === 14 && str_starts_with($digits, '0098')) {
            return '0' . substr($digits, 4);
        }

        return null;
    }

    public static function isValid(string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /**
     * Mask نمایشی (Audit/Log): 0912***5678
     */
    public static function mask(string $normalized): string
    {
        if (strlen($normalized) < 8) {
            return '***';
        }

        return substr($normalized, 0, 4) . '***' . substr($normalized, -4);
    }
}
