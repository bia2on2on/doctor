<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Time;

/**
 * تبدیل تقویم میلادی ↔ جلالی (شمسی) — خالص، بدون وابستگی (Unit-Testable).
 *
 * روش: الگوریتم ۲۰ نقطه‌شکست Borkowski (همگام با `assets/js/cpms-jalali-date.js`).
 * مرجع صحت‌سنجی: تقویم `persian` در ICU (`Intl`) بر پایهٔ مدل نجومی ظهر شرعی تهران
 * (`52.5°E`) و لنگرهای منتشرشدهٔ ۱۴۰۰ تا ۱۴۰۵ (۱۳۹۹ و ۱۴۰۳ کبیسه؛ ۱۴۰۱ و ۱۴۰۵
 * غیرکبیسه). این مرجع جدول قانونی آینده نیست و توافق روزبه‌روز آن با الگوریتم
 * نقطه‌شکست تا پایان سال ۱۵۰۲ جلالی (اولین واگرایی در ۱۵۰۳ / ۲۱۲۴ میلادی) برقرار است؛
 * بازهٔ عملیاتی پشتیبانی‌شده در رابط کاربری ۱۳۰۰..۱۴۹۹ است.
 *
 * کاربرد: فقط Presentation (فیلدهای `_jalali` در Responses — Contract §0).
 * ذخیره‌سازی همیشه UTC/میلادی (ADR-0013).
 */
final class Jalali
{
    /**
     * @return array{0: int, 1: int, 2: int} [jy, jm, jd]
     */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $ts = self::gregorianTs($gy, $gm, $gd);
        if ($ts === null) {
            throw new \DomainException("CLINIC_VALIDATION_FAILED: invalid Gregorian date {$gy}-{$gm}-{$gd}");
        }

        return self::toJalaliBorkowski( $gy, $gm, $gd );
    }

    /**
     * @return array{0: int, 1: int, 2: int} [gy, gm, gd]
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > self::monthLength($jy, $jm)) {
            throw new \DomainException("CLINIC_VALIDATION_FAILED: invalid Jalaali date {$jy}/{$jm}/{$jd}");
        }

        return self::toGregorianBorkowski( $jy, $jm, $jd );
    }

    public static function isValidJalaliDate(int $jy, int $jm, int $jd): bool
    {
        if ( $jy < self::BREAKS[0] || $jy >= self::BREAKS[ count( self::BREAKS ) - 1 ] ) {
            return false;
        }

        return $jm >= 1 && $jm <= 12 && $jd >= 1 && $jd <= self::monthLength($jy, $jm);
    }

    public static function isLeapJalaaliYear(int $jy): bool
    {
        return self::isLeapBorkowski($jy);
    }

    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }
        return self::isLeapJalaaliYear($jy) ? 30 : 29;
    }

    // ============ Presentation Helpers ============

    /**
     * نمایش Jalaali برای تاریخ میلادی (Y-m-d) — مثال: `1405/07/14`.
     */
    public static function formatYmd(string $gregorianYmd): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $gregorianYmd, $m) !== 1) {
            throw new \DomainException('CLINIC_VALIDATION_FAILED: invalid Gregorian date ' . $gregorianYmd);
        }
        [$jy, $jm, $jd] = self::toJalali((int) $m[1], (int) $m[2], (int) $m[3]);

        return sprintf('%d/%02d/%02d', $jy, $jm, $jd);
    }

    /**
     * Jalaali امروز (UTC) — برای نمایش.
     */
    public static function todayJalali(): string
    {
        return self::formatYmd(gmdate('Y-m-d'));
    }

	/**
	 * نمایش شمسی با ارقام فارسی برای رابط کاربری — مثال: `۱۴۰۵/۰۷/۱۴`.
	 */
	public static function format_display_ymd( string $gregorian_ymd ): string {
		return PersianDigits::digits( self::formatYmd( $gregorian_ymd ) );
	}

	/**
	 * تاریخ شمسی امروز (UTC) با ارقام فارسی برای نمایش.
	 */
	public static function today_display_jalali(): string {
		return PersianDigits::digits( self::todayJalali() );
	}

    // ============ Internal ============

    private static function gregorianTs(int $gy, int $gm, int $gd): ?int
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $gy, $gm, $gd), new \DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m-d') !== sprintf('%04d-%02d-%02d', $gy, $gm, $gd)) {
            return null;
        }

        return $d->getTimestamp();
    }

    // ============ Fallback خارج از بازه (Borkowski — jalaali-js 1.2.6, MIT) ============

    private const BREAKS = [
        -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210,
        1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178,
    ];

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function toJalaliBorkowski(int $gy, int $gm, int $gd): array
    {
        return self::d2j(self::g2d($gy, $gm, $gd));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function toGregorianBorkowski(int $jy, int $jm, int $jd): array
    {
        return self::d2g(self::j2d($jy, $jm, $jd));
    }

    /**
     * تقسیم صحیح این فرمول‌ها باید گردشدن به سمت صفر (`intdiv`) باشد، نه `floor`.
     * نمونهٔ اثبات: برای مارس (`$gm = 3`)، `intdiv(3 - 8, 6) === 0` است و
     * `g2d(2016, 4, 11) === 2457490` و `1370/01/01 === 1991-03-21` به دست می‌آید؛
     * اما با `floor(-5 / 6) === -1.0` مقدار JDN به اندازهٔ ۳۶۶ روز جابه‌جا می‌شود.
     */
    public static function g2d(int $gy, int $gm, int $gd): int
    {
        $d = intdiv(($gy + intdiv($gm - 8, 6) + 100100) * 1461, 4)
            + intdiv(153 * (($gm + 9) % 12) + 2, 5)
            + $gd - 34840408;
        $d = $d - intdiv(intdiv($gy + 100100 + intdiv($gm - 8, 6), 100) * 3, 4) + 752;

        return $d;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function d2g(int $jdn): array
    {
        $j = 4 * $jdn + 139361631;
        $j = $j + intdiv(intdiv(4 * $jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
        $i = intdiv($j % 1461, 4) * 5 + 308;
        $gd = intdiv($i % 153, 5) + 1;
        $gm = intdiv($i, 153) % 12 + 1;
        $gy = intdiv($j, 1461) - 100100 + intdiv(8 - $gm, 6);

        return [$gy, $gm, $gd];
    }

    public static function j2d(int $jy, int $jm, int $jd): int
    {
        $r = self::jalCal($jy);

        return self::g2d($r['gy'], 3, $r['march']) + ($jm - 1) * 31 - intdiv($jm, 7) * ($jm - 7) + $jd - 1;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function d2j(int $jdn): array
    {
        $gy = self::d2g($jdn)[0];
        $jy = $gy - 621;
        $r = self::jalCal($jy);
        $jdn1f = self::g2d($gy, 3, $r['march']);
        $k = $jdn - $jdn1f;

        if ($k >= 0) {
            if ($k <= 185) {
                return [$jy, 1 + intdiv($k, 31), $k % 31 + 1];
            }
            $k -= 186;
        } else {
            $jy -= 1;
            $k += 179;
            if ($r['leap'] === 1) {
                $k += 1;
            }
        }

        return [$jy, 7 + intdiv($k, 30), $k % 30 + 1];
    }

    public static function isLeapBorkowski(int $jy): bool
    {
        return self::jalCal($jy)['leap'] === 0;
    }

    /**
     * @return array{leap: int, gy: int, march: int}
     */
    private static function jalCal(int $jy): array
    {
        $breaks = self::BREAKS;
        $count = count($breaks);
        $gy = $jy + 621;
        $leapJ = -14;
        $jp = $breaks[0];
        $jump = 0;

        if ($jy < $breaks[0] || $jy >= $breaks[$count - 1]) {
            throw new \DomainException('CLINIC_VALIDATION_FAILED: Jalaali year out of range');
        }

        for ($i = 1; $i < $count; $i++) {
            $jm = $breaks[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) {
                break;
            }
            $leapJ = $leapJ + intdiv($jump, 33) * 8 + intdiv($jump % 33, 4);
            $jp = $jm;
        }
        $n = $jy - $jp;

        $leapJ = $leapJ + intdiv($n, 33) * 8 + intdiv(($n % 33) + 3, 4);
        if (($jump % 33) === 4 && $jump - $n === 4) {
            $leapJ += 1;
        }

        $leapG = intdiv($gy, 4) - intdiv((intdiv($gy, 100) + 1) * 3, 4) - 150;
        $march = 20 + $leapJ - $leapG;

        if ($jump - $n < 6) {
            $n = $n - $jump + intdiv($jump + 4, 33) * 33;
        }
        $leap = ((($n + 1) % 33) - 1) % 4;
        if ($leap === -1) {
            $leap = 4;
        }

        return ['leap' => $leap, 'gy' => $gy, 'march' => $march];
    }
}
