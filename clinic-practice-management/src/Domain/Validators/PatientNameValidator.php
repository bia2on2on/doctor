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
    private const SPACES = "\x{0020}\t\n\r\f\v\x{00A0}";

    /** نویسه‌های نادیده‌گرفته/Zero-Width که در نام نمایشی شمارش نمی‌شوند. */
    private const INVISIBLE = "\x{200B}\x{200C}\x{200D}\x{200E}\x{200F}\x{2060}\x{FEFF}";

    /**
     * آیا رشته محتوای دیدشدنی دارد؟
     *
     * @param string $value نام خام.
     * @return true اگر پس از حذف فاصله‌ها و نویسه‌های پنهان چیزی بماند.
     */
    public static function hasVisibleContent( string $value ): bool
    {
        $pattern = '/[' . self::SPACES . self::INVISIBLE . ']/u';

        $stripped = (string) preg_replace( $pattern, '', $value );

        return $stripped !== '';
    }
}
