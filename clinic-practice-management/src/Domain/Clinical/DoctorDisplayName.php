<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Clinical;

/**
 * نرمال‌سازیِ «نام نمایشیِ پزشک» برای رابطِ کاربریِ فارسی — یک نقطهٔ واحد.
 *
 * قاعدهٔ مالک (D1): عنوانِ ابتدایی فقط به‌صورتِ «توکنِ کامل» شناخته می‌شود:
 *  - واژهٔ «دکتر» (کِ فارسی U+06A9؛ «ك» عربی U+0643 فقط برای *مقایسه* برابر
 *    گرفته می‌شود)، یا «Dr» / «Dr.» لاتین (بی‌توجه به بزرگی/کوچکی)،
 *  - و تنها وقتی پس از آن «پایانِ رشته» یا یک جداکننده بیاید: فاصلهٔ ASCII،
 *    NBSP، ZWNJ یا نقطه. واژه‌ای که فقط با همان حروف شروع شود (مثلِ «دکتری»
 *    یا «دکترزاده») **نام** است و دست‌نخورده می‌ماند.
 *
 * هیچ چیز **داخلِ** نام بازنویسی نمی‌شود: ZWNJ، نقطهٔ میانی و فاصله‌های
 * غیرِمعمولی دقیقاً همان‌طور که ذخیره شده‌اند می‌مانند؛ فقط فاصلهٔ ابتدا/انتها
 * حذف و رشته‌های فاصلهٔ معمولی به یک فاصله تبدیل می‌شوند. عنوان‌های تکراری در
 * ابتدا به یکی کاهش می‌یابند. نامِ ذخیره‌شده هرگز تغییر نمی‌کند.
 *
 * پیاده‌سازیِ UTF-8-امن: همهٔ برش‌ها با الگوهای `/u`؛ هیچ `trim()` بایتی.
 * معادلِ JS: `window.CpmsText.doctorDisplayName()` در `assets/js/cpms-ui-text.js`.
 * برابریِ PHP/JS از یک منبعِ واحد بررسی می‌شود:
 * `tests/fixtures/doctor-display-name-vectors.json`.
 */
final class DoctorDisplayName {

	/** عنوانِ پزشک با «ک»ِ فارسی — شکلِ خروجی. */
	public const TITLE = 'دکتر';

	/** جداکننده‌های مجاز پیرامونِ عنوان: فاصله‌ها، NBSP، ZWNJ، نقطه. */
	private const SEPARATORS = ' \t\n\r\f\v\x{00A0}\x{200C}.';

	/**
	 * کلاسِ صریحِ پیرایش — دقیقاً همان کلاسِ JS (`EDGE_WS_CLASS`): فقط فاصلهٔ ASCII
	 * (space، tab، LF، CR، FF، VT) به‌همراهِ NBSP U+00A0.
	 *
	 * عمداً از `\s` استفاده نمی‌شود: تعریفِ `\s` در PCRE، JS و ICU یکسان نیست
	 * (در JS شاملِ U+FEFF/U+2009/U+3000 هم می‌شود). U+2009، U+3000، U+FEFF و ZWNJ
	 * نه پیراسته می‌شوند و نه بازنویسی.
	 */
	private const EDGE_WS = ' \t\n\r\f\v\x{00A0}';

	/** واژهٔ عنوان، با هر دو شکلِ کاف (ک U+06A9 / ك U+0643). */
	private const TITLE_WORD = 'د[\x{06A9}\x{0643}]تر';

	/**
	 * نامِ پزشک با عنوانِ «دکتر» — دقیقاً یک‌بار، بدون بازنویسیِ داخلِ نام.
	 *
	 * @param string|null $name نامِ ذخیره‌شده (null/خالی → رشتهٔ خالی).
	 */
	public static function with_title( ?string $name ): string {
		$clean = self::normalize( $name );
		if ( '' === $clean ) {
			return '';
		}

		$pattern = '/^(?:' . self::TITLE_WORD . '|dr)(?=[' . self::SEPARATORS . ']|$)/iu';
		while ( 1 === preg_match( $pattern, $clean, $matches ) ) {
			$rest  = (string) substr( $clean, strlen( $matches[0] ) );
			$clean = self::trim_whitespace( self::strip_leading_separators( $rest ) );
			if ( '' === $clean ) {
				return self::TITLE;
			}
		}

		return self::TITLE . ' ' . $clean;
	}

	/**
	 * آیا نام از پیش با عنوانِ کاملِ «دکتر»/«Dr» شروع می‌شود؟
	 *
	 * @param string|null $name نامِ نمایشی.
	 */
	public static function starts_with_title( ?string $name ): bool {
		$clean = self::normalize( $name );
		if ( '' === $clean ) {
			return false;
		}

		$pattern = '/^(?:' . self::TITLE_WORD . '|dr)(?=[' . self::SEPARATORS . ']|$)/iu';

		return 1 === preg_match( $pattern, $clean );
	}

	/**
	 * حذفِ تکرارِ عنوان در متنی که از قبل ساخته شده است.
	 *
	 * تنها عنوانِ «کامل» (با جداکننده/پایان پس از خودش) جفت می‌شود؛ بنابراین
	 * «دکتر دکتری» دست‌نخورده می‌ماند و «دکتر دکتر رضایی» → «دکتر رضایی».
	 *
	 * @param string $text متنِ نهاییِ Template.
	 */
	public static function collapse_doubled_title( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		$pattern = '/' . self::TITLE_WORD
			. '[' . self::SEPARATORS . ']*'
			. self::TITLE_WORD
			. '(?=[' . self::SEPARATORS . ']|$)/u';

		do {
			$before = $text;
			$text   = (string) preg_replace( $pattern, self::TITLE, $text );
		} while ( $text !== $before );

		return $text;
	}

	/**
	 * فشرده‌سازیِ فاصله‌های معمولی + حذفِ فاصلهٔ ابتدا/انتها.
	 *
	 * @param string|null $value ورودیِ خام.
	 */
	private static function normalize( ?string $value ): string {
		$value = null === $value ? '' : $value;
		$value = (string) preg_replace( '/[ \t\n\r\f\v]+/u', ' ', $value );

		// NBSP هم «فاصله» است و در دو سر حذف می‌شود؛ ZWNJ و نقطه فاصله نیستند.
		$value = self::trim_whitespace( $value );

		// ورودیِ نامعتبر UTF-8: هر preg_* با الگوی /u مقدار null برمی‌گرداند و
		// (string) null یعنی رشتهٔ خالی — fail-closed، بدونِ بازنویسیِ ورودی.
		return $value;
	}

	/**
	 * @param string $value ورودی.
	 */
	private static function trim_whitespace( string $value ): string {
		$trimmed = preg_replace(
			'/^[' . self::EDGE_WS . ']+|[' . self::EDGE_WS . ']+$/u',
			'',
			$value
		);

		// preg_* با ورودیِ UTF-8 نامعتبر null می‌دهد → رشتهٔ خالی (fail-closed).
		return null === $trimmed ? '' : $trimmed;
	}

	/**
	 * @param string $value ورودی.
	 */
	private static function strip_leading_separators( string $value ): string {
		return (string) preg_replace( '/^[' . self::SEPARATORS . ']+/u', '', $value );
	}
}
