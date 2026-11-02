<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Time;

/**
 * تبدیل ارقام لاتین به فارسی و قالب‌بندی نمایشی اعداد — خالص و بدون وابستگی به وردپرس.
 *
 * تنها برای لایهٔ نمایش به کاربر استفاده می‌شود؛ مقادیر ماشینی (تاریخ‌های ISO،
 * شناسه‌ها، ویژگی‌های `value` و `data-*` و مقادیر ذخیره‌شده) هرگز از این کلاس
 * عبور داده نمی‌شوند.
 */
final class PersianDigits {

	/** جداکنندهٔ هزارگان فارسی (U+066C). */
	public const THOUSANDS_SEPARATOR = '٬';

	/** جداکنندهٔ اعشار فارسی (U+066B). */
	public const DECIMAL_SEPARATOR = '٫';

	/** نگاشت ارقام ASCII به ارقام فارسی (U+06F0..U+06F9). */
	private const LATIN_TO_PERSIAN = [
		'0' => '۰',
		'1' => '۱',
		'2' => '۲',
		'3' => '۳',
		'4' => '۴',
		'5' => '۵',
		'6' => '۶',
		'7' => '۷',
		'8' => '۸',
		'9' => '۹',
	];

	/**
	 * تبدیل همهٔ ارقام لاتین (`0`..`9`) به فارسی بدون تغییر هیچ نویسهٔ غیررقمی.
	 */
	public static function digits( string|int $value ): string {
		return strtr( (string) $value, self::LATIN_TO_PERSIAN );
	}

	/**
	 * قالب‌بندی عدد صحیح با جداکنندهٔ هزارگان فارسی و ارقام فارسی.
	 */
	public static function format_int( int $value ): string {
		$raw      = (string) $value;
		$negative = str_starts_with( $raw, '-' );
		$digits   = $negative ? substr( $raw, 1 ) : $raw;
		$grouped  = self::group_thousands( $digits );

		if ( '0' === $grouped ) {
			return self::digits( '0' );
		}

		return ( $negative ? '-' : '' ) . self::digits( $grouped );
	}

	/**
	 * قالب‌بندی عدد صحیح یا اعشاری با جداکنندهٔ هزارگان و اعشار فارسی.
	 */
	public static function format_number( int|float|string $value, int $decimals = 0 ): string {
		$precision = max( 0, $decimals );

		if ( is_int( $value ) && 0 === $precision ) {
			return self::format_int( $value );
		}

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			$is_int_str = 1 === preg_match( '/^([+-]?)(\d+)$/', $trimmed, $int_match );
			if ( 0 === $precision && $is_int_str ) {
				$grouped = self::group_thousands( $int_match[2] );
				if ( '0' === $grouped ) {
					return self::digits( '0' );
				}

				return ( '-' === $int_match[1] ? '-' : '' ) . self::digits( $grouped );
			}
			if ( 1 !== preg_match( '/^[+-]?(?:\d+\.?\d*|\.\d+)$/', $trimmed ) ) {
				return self::digits( $value );
			}
			$numeric = (float) $trimmed;
		} else {
			if ( is_float( $value ) && ! is_finite( $value ) ) {
				return self::digits( (string) $value );
			}
			$numeric = (float) $value;
		}

		$formatted = number_format( abs( $numeric ), $precision, '.', '' );
		$segments  = explode( '.', $formatted );
		$int_part  = self::group_thousands( $segments[0] );
		$frac_part = $segments[1] ?? '';
		$is_zero   = '0' === $int_part && '' === trim( $frac_part, '0' );
		$sign      = ( $numeric < 0 && ! $is_zero ) ? '-' : '';
		$out       = $int_part . ( '' !== $frac_part ? self::DECIMAL_SEPARATOR . $frac_part : '' );

		return $sign . self::digits( $out );
	}

	/**
	 * گروه‌بندی سه‌رقمی رشتهٔ ارقام نامنفی ASCII از راست به چپ.
	 */
	private static function group_thousands( string $digits ): string {
		$clean = ltrim( $digits, '0' );
		if ( '' === $clean ) {
			return '0';
		}

		$len   = strlen( $clean );
		$head  = $len % 3;
		$parts = [];
		if ( $head > 0 ) {
			$parts[] = substr( $clean, 0, $head );
		}
		for ( $offset = $head; $offset < $len; $offset += 3 ) {
			$parts[] = substr( $clean, $offset, 3 );
		}

		return implode( self::THOUSANDS_SEPARATOR, $parts );
	}
}
