<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Validators;

/**
 * اعتبارسنجی/عادی‌سازی موبایل ایران — خالص.
 *
 * شکل عادی‌شده: 09xxxxxxxxx (11 رقم).
 * ورودی‌های مجاز: 09121234567 / 9121234567 / +989121234567 / 00989121234567 /
 * 0912 123 4567 و فرم‌های 98 با صفرِ میان‌شهری (9809121234567).
 *
 * ارقام یونیکد پشتیبانی می‌شوند و پیش از هر چیز به ASCII تا می‌شوند:
 * فارسی ۰۱۲۳۴۵۶۷۸۹ و عربی ٠١٢٣٤٥٦٧٨٩ (C5).
 */
final class MobileValidator
{
    /**
     * @return string|null شکل عادی‌شده، یا null اگر نامعتبر
     */
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace( '/[^\d]/', '', self::fold_digits( $input ) );
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
        if ( $len === 12 && str_starts_with( $digits, '989' ) ) {
            return '0' . substr( $digits, 2 );
        }
        // «98» + شکل محلی با صفرِ میان‌شهری: +98 (0912) 123-4567
        if ( $len === 13 && str_starts_with( $digits, '9809' ) ) {
            return substr( $digits, 2 );
        }
        if ($len === 14 && str_starts_with($digits, '0098')) {
            return '0' . substr($digits, 4);
        }
        // «0098» + شکل محلی با صفرِ میان‌شهری (00 98 0912...)
        if ( $len === 15 && str_starts_with( $digits, '009809' ) ) {
            return substr( $digits, 4 );
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

    /**
     * تا کردن ارقام یونیکد به ASCII — فارسی (U+06F0..) و عربی (U+0660..).
     *
     * فقط ارقام؛ حروف/علائم فارسی و عربی separator حساب نمی‌شوند و مثل بقیهٔ
     * نویسه‌های غیررقمی در normalize() حذف می‌شوند.
     */
    private static function fold_digits( string $input ): string {
        $map = [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ];

        return strtr( $input, $map );
    }
}
