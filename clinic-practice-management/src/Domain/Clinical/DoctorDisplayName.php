<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Clinical;

/**
 * نرمال‌سازیِ «نام نمایشیِ پزشک» برای رابطِ کاربریِ فارسی — یک نقطهٔ واحد.
 *
 * قاعده: عنوان «دکتر» فقط یک‌بار و فقط اگر نام از پیش با آن شروع نشده باشد
 * اضافه می‌شود. نامِ ذخیره‌شده در پایگاه‌داده هرگز تغییر نمی‌کند؛ این کلاس
 * فقط خروجیِ نمایشی/SMS را می‌سازد.
 *
 * موارد پوشش‌داده‌شده:
 *  - «رضایی»                      → «دکتر رضایی»
 *  - «دکتر رضایی» / «دکتر  رضایی»  → «دکتر رضایی» (بدون تکرار)
 *  - «دکتر. رضایی» / «دکتر .رضایی» → «دکتر رضایی»
 *  - «دکتر‌رضایی» (ZWNJ)           → «دکتر رضایی»
 *  - نامِ خالی/null                → رشتهٔ خالی (فراخوان باید برچسبِ خودش را بگذارد)
 *
 * نکتهٔ پیاده‌سازی: تمام برش‌ها با الگوهای UTF-8 (`/u`) انجام می‌شود. هرگز
 * از `trim($name, $byteCharlist)` استفاده نکنید — فهرست بایتی می‌تواند بایتِ
 * پایانیِ حروفِ فارسیِ چندبایتی را جدا و متن را خراب کند.
 *
 * معادلِ JSِ همین رفتار: `window.CpmsText.doctorDisplayName()` در
 * `assets/js/cpms-ui-text.js` — هر دو باید دقیقاً یک خروجی بدهند
 * (تست: DoctorDisplayNameTest + tests/js/pkg-e-texts.spec.js).
 */
final class DoctorDisplayName
{
    /** عنوانِ پزشک با «ک»ِ فارسی. */
    public const TITLE = 'دکتر';

    /** جداکننده‌های مجازِ میان عنوان و نام: فاصله‌ها، فاصلهٔ سخت، ZWNJ، نقطه. */
    private const SEPARATOR_CLASS = '[\s\x{00A0}\x{200C}.]';

    /**
     * نامِ پزشک با عنوانِ «دکتر» — دقیقاً یک‌بار.
     */
    public static function withTitle(string $name): string
    {
        $clean = self::squeeze($name);
        if ($clean === '') {
            return '';
        }

        if (self::startsWithTitle($clean)) {
            $rest = self::stripLeadingTitle($clean);

            return self::TITLE . ($rest === '' ? '' : ' ' . $rest);
        }

        return self::TITLE . ' ' . $clean;
    }

    /**
     * آیا نام از پیش عنوانِ «دکتر» را دارد؟ (با نقطه/ZWNJ/فاصلهٔ اضافه)
     */
    public static function startsWithTitle(string $name): bool
    {
        $trimmed = self::trimSeparators($name);

        return $trimmed !== '' && self::stripLeadingTitle($trimmed) !== $trimmed;
    }

    /**
     * حذفِ تکرارِ عنوان در متنی که از قبل ساخته شده است.
     *
     * برای Templateهای SMSِ ذخیره‌شده‌ای که خودشان «دکتر {{doctor_name}}» را
     * در متن دارند (و نام هم اکنون عنوان‌دار است) — خروجی هرگز «دکتر دکتر»
     * ندارد. نقطه/فاصلهٔ اضافه/ZWNJ میان دو عنوان هم پوشش داده می‌شود.
     */
    public static function collapseDoubledTitle(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $pattern = '/' . self::TITLE . self::SEPARATOR_CLASS . '*' . self::TITLE . '/u';
        do {
            $before = $text;
            $text   = (string) preg_replace($pattern, self::TITLE, $text);
        } while ($text !== $before);

        return $text;
    }

    /** فشرده‌سازیِ فاصله‌های تکراری و حذفِ فاصله/نقطهٔ لبه‌ای. */
    private static function squeeze(string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{200C}"], ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);
        $value = (string) preg_replace('/\s*\.\s*/u', ' ', $value);

        return self::trimSeparators($value);
    }

    /** حذفِ فاصله/نقطه/ZWNJ از دو سرِ رشته — کاملاً امن برای UTF-8. */
    private static function trimSeparators(string $value): string
    {
        $pattern = '/^' . self::SEPARATOR_CLASS . '+|' . self::SEPARATOR_CLASS . '+$/u';

        return (string) preg_replace($pattern, '', $value);
    }

    /** بخشِ نام پس از عنوان (اگر عنوان در ابتدا نباشد، همان ورودیِ پیراسته). */
    private static function stripLeadingTitle(string $name): string
    {
        $trimmed = self::trimSeparators($name);
        if (!str_starts_with($trimmed, self::TITLE)) {
            return $trimmed;
        }

        // TITLE فقط حروفِ ASCII-سازگارِ UTF-8 است؛ برشِ بایتی مرزِ کاراکتر را
        // دقیقاً روی طولِ TITLE نگه می‌دارد (نه وسطِ یک حرفِ دیگر).
        $rest = substr($trimmed, strlen(self::TITLE));

        return self::trimSeparators((string) $rest);
    }
}
