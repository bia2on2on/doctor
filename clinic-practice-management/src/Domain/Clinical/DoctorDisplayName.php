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
 * از `trim( $name, $byteCharlist )` استفاده نکنید — فهرست بایتی می‌تواند بایتِ
 * پایانیِ حروفِ فارسیِ چندبایتی را جدا و متن را خراب کند.
 *
 * معادلِ JSِ همین رفتار: `window.CpmsText.doctorDisplayName()` در
 * `assets/js/cpms-ui-text.js` — هر دو باید دقیقاً یک خروجی بدهند
 * (تست: DoctorDisplayNameTest + tests/js/pkg-e-texts.spec.js).
 */
final class DoctorDisplayName {

	/** عنوانِ پزشک با «ک»ِ فارسی. */
	public const TITLE = 'دکتر';

	/** جداکننده‌های مجازِ میان عنوان و نام: فاصله‌ها، فاصلهٔ سخت، ZWNJ، نقطه. */
	private const SEPARATOR_CLASS = '[\s\x{00A0}\x{200C}.]';

	/**
	 * نامِ پزشک با عنوانِ «دکتر» — دقیقاً یک‌بار.
	 *
	 * @param string $name نامِ ذخیره‌شده (هرگز تغییر نمی‌کند).
	 */
	public static function with_title( string $name ): string {
		$clean = self::squeeze( $name );
		if ( '' === $clean ) {
			return '';
		}

		if ( self::starts_with_title( $clean ) ) {
			$rest = self::strip_leading_title( $clean );

			return self::TITLE . ( '' === $rest ? '' : ' ' . $rest );
		}

		return self::TITLE . ' ' . $clean;
	}

	/**
	 * آیا نام از پیش عنوانِ «دکتر» را دارد؟ (با نقطه/ZWNJ/فاصلهٔ اضافه)
	 *
	 * @param string $name نامِ نمایشی.
	 */
	public static function starts_with_title( string $name ): bool {
		$trimmed = self::trim_separators( $name );

		return '' !== $trimmed && self::strip_leading_title( $trimmed ) !== $trimmed;
	}

	/**
	 * حذفِ تکرارِ عنوان در متنی که از قبل ساخته شده است.
	 *
	 * برای Templateهای SMSِ ذخیره‌شده‌ای که خودشان «دکتر {{doctor_name}}» را
	 * در متن دارند (و نام هم اکنون عنوان‌دار است) — خروجی هرگز «دکتر دکتر»
	 * ندارد. نقطه/فاصلهٔ اضافه/ZWNJ میان دو عنوان هم پوشش داده می‌شود.
	 *
	 * @param string $text متنِ نهاییِ Template.
	 */
	public static function collapse_doubled_title( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		$pattern = '/' . self::TITLE . self::SEPARATOR_CLASS . '*' . self::TITLE . '/u';
		do {
			$before = $text;
			$text   = (string) preg_replace( $pattern, self::TITLE, $text );
		} while ( $text !== $before );

		return $text;
	}

	/**
	 * فشرده‌سازیِ فاصله‌های تکراری و حذفِ فاصله/نقطهٔ لبه‌ای.
	 *
	 * @param string $value ورودیِ خام.
	 */
	private static function squeeze( string $value ): string {
		$value = str_replace( array( "\u{00A0}", "\u{200C}" ), ' ', $value );
		$value = (string) preg_replace( '/\s+/u', ' ', $value );
		$value = (string) preg_replace( '/\s*\.\s*/u', ' ', $value );

		return self::trim_separators( $value );
	}

	/**
	 * حذفِ فاصله/نقطه/ZWNJ از دو سرِ رشته — کاملاً امن برای UTF-8.
	 *
	 * @param string $value ورودی.
	 */
	private static function trim_separators( string $value ): string {
		$pattern = '/^' . self::SEPARATOR_CLASS . '+|' . self::SEPARATOR_CLASS . '+$/u';

		return (string) preg_replace( $pattern, '', $value );
	}

	/**
	 * بخشِ نام پس از عنوان (اگر عنوان در ابتدا نباشد، همان ورودیِ پیراسته).
	 *
	 * @param string $name نامِ نمایشی.
	 */
	private static function strip_leading_title( string $name ): string {
		$trimmed = self::trim_separators( $name );
		if ( ! str_starts_with( $trimmed, self::TITLE ) ) {
			return $trimmed;
		}

		// TITLE فقط حروفِ UTF-8 است؛ برشِ بایتی مرزِ کاراکتر را دقیقاً روی
		// طولِ TITLE نگه می‌دارد (نه وسطِ یک حرفِ دیگر).
		$rest = substr( $trimmed, strlen( self::TITLE ) );

		return self::trim_separators( (string) $rest );
	}
}
