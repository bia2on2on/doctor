<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Validators;

/**
 * نامِ بیمار/پزشک — helper مشترک برای تشخیص «محتوای دیدشدنی» بودن نام.
 *
 * قاعدهٔ مالک: نامی که تنها از فاصله و نویسه‌های Zero-Width/پنهان
 * (U+200B تا U+200F، U+2060، U+FEFF) ساخته شده باشد، نام نیست → رد شود.
 * اما ZWNJ (U+200C) «داخلِ» یک کلمه فارسی (مثلاً «س‌ام») نام را نامعتبر
 * نمی‌کند: پس از حذف نویسه‌های پنهان، حروف دیدشدنی می‌مانند. فقط وقتی هیچ
 * دیدهشدنی باقی نماند، نام نامعتبر است.
 *
 * خالص (بدون وردپرس/دیتابیس)، UTF-8-امن: همه برش‌ها با الگوهای `/u`.
 */
final class PatientNameValidator
{
    /** نویسه‌های فاصله (ASCII + NBSP). */
    private const SPACES = "\x20\t\n\r\f\v\u{00A0}";

    /** نویسه‌های کنترل ASCII که نمایشی نیستند (U+0000 تا U+001F). */
    private const CONTROL = '\x00-\x1F';

    /** نویسه‌های نادیده‌گرفته/Zero-Width که در نام نمایشی شمارش نمی‌شوند. */
    private const INVISIBLE = "\u{200B}\u{200C}\u{200D}\u{200E}\u{200F}\u{2060}\u{FEFF}";

    /**
     * آیا رشته محتوای دیدشدنی دارد؟
     *
     * @param string $value نام خام.
     * @return bool اگر پس از حذف فاصله‌ها و نویسه‌های پنهان چیزی بماند.
     */
    public static function hasVisibleContent( string $value ): bool
    {
        $pattern = '/[' . self::CONTROL . self::SPACES . self::INVISIBLE . ']/u';

        $stripped = (string) preg_replace( $pattern, '', $value );

        return $stripped !== '';
    }
}
