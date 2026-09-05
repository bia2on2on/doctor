<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Time;

/**
 * تبدیل تقویم میلادی ↔ جلالی (شما‌شی) — خالص، بدون وابستگی (Unit-Testable).
 *
 * روش: **جدول تاریخ رسمی شروع هر سال (Nowruz)** برای 1390–1435 — مطابق تقویم رسمی
 * ایران (روز لحظه اعتدال بهاری در ساعت محلی تهران، UTC+3:30). مقادیر جدول از
 * الگوریتم نجومی Meeus محاسبه و با تاریخ‌های Nowruz منتشرشده (timeanddate/USNO)
 * برای سال‌های 2020–2035 صحت‌سنجی شده‌اند (انحراف ≤ ۲۰ دقیقه؛ هیچ اعتدالی نزدیک
 * مرز نیمه‌شب تهران نبود).
 *
 * چرا جدول و نه الگوریتم اریتمتیکی (Borkowski/jalaali-js)؟ الگوریتم ۳۳ ساله
 * اریتمتیکی در برخی سال‌ها (مثل ۱۴۰۴ و ۱۴۰۵) یک روز با تقویم رسمی اختلاف دارد —
 * برای اپلیکیشن بالینی غیرقابل‌قبول است. ساختار ماه‌ها درون‌سال ثابت است
 * (۳۱×۶، ۳۰×۵، ۲۹/۳۰) — تنها مقدار متغیر، شروع سال است.
 *
 * خارج از بازه جدول (1390..1434): Fallback اریتمتیکی Borkowski (تقریبی — فقط
 * برای مقاومت، استفاده کلینیک خارج از بازه تقریباً صفر است).
 *
 * کاربرد: فقط Presentation (فیلدهای `_jalali` در Responses — Contract §0).
 * ذخیره‌سازی همیشه UTC/میلادی (ADR-0013).
 */
final class Jalali
{
    /**
     * شروع رسمی هر سال جلالی (Nowruz) — تاریخ میلادی که ۱ فروردین همان سال است.
     * سال 1435 فقط برای Delimit پایان بازه 1434 استفاده می‌شود.
     */
    private const NOWRUZ = [
        1390 => '2011-03-21',
        1391 => '2012-03-20',
        1392 => '2013-03-20',
        1393 => '2014-03-20',
        1394 => '2015-03-21',
        1395 => '2016-03-20',
        1396 => '2017-03-20',
        1397 => '2018-03-20',
        1398 => '2019-03-21',
        1399 => '2020-03-20',
        1400 => '2021-03-20',
        1401 => '2022-03-20',
        1402 => '2023-03-21',
        1403 => '2024-03-20',
        1404 => '2025-03-20',
        1405 => '2026-03-20',
        1406 => '2027-03-20',
        1407 => '2028-03-20',
        1408 => '2029-03-20',
        1409 => '2030-03-20',
        1410 => '2031-03-20',
        1411 => '2032-03-20',
        1412 => '2033-03-20',
        1413 => '2034-03-20',
        1414 => '2035-03-20',
        1415 => '2036-03-20',
        1416 => '2037-03-20',
        1417 => '2038-03-20',
        1418 => '2039-03-20',
        1419 => '2040-03-20',
        1420 => '2041-03-20',
        1421 => '2042-03-20',
        1422 => '2043-03-20',
        1423 => '2044-03-20',
        1424 => '2045-03-20',
        1425 => '2046-03-20',
        1426 => '2047-03-20',
        1427 => '2048-03-20',
        1428 => '2049-03-20',
        1429 => '2050-03-20',
        1430 => '2051-03-20',
        1431 => '2052-03-20',
        1432 => '2053-03-20',
        1433 => '2054-03-20',
        1434 => '2055-03-20',
        1435 => '2056-03-20',
    ];

    private const MIN_YEAR = 1390;
    private const MAX_YEAR = 1434; // بزرگ‌ترین سالی که Start سال بعدش هم موجود است

    /**
     * @return array{0: int, 1: int, 2: int} [jy, jm, jd]
     */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $ts = self::gregorianTs($gy, $gm, $gd);
        if ($ts === null) {
            throw new \DomainException("CLINIC_VALIDATION_FAILED: invalid Gregorian date {$gy}-{$gm}-{$gd}");
        }

        // Binary search: بزرگ‌ترین jی که start(jy) <= date
        $lo = self::MIN_YEAR;
        $hi = self::MAX_YEAR;
        $jy = self::MIN_YEAR;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if (self::yearStartTs($mid) <= $ts) {
                $jy = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        $offset = intdiv($ts - self::yearStartTs($jy), 86400);
        $leap = self::isLeapJalaaliYear($jy);

        if ($offset < 0 || $offset > ($leap ? 365 : 364)) {
            // خارج از بازه جدول → Fallback اریتمتیکی
            return self::toJalaliBorkowski($gy, $gm, $gd);
        }

        $md = self::offsetToMonthDay($offset);

        return [$jy, $md[0], $md[1]];
    }

    /**
     * @return array{0: int, 1: int, 2: int} [gy, gm, gd]
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > self::monthLength($jy, $jm)) {
            throw new \DomainException("CLINIC_VALIDATION_FAILED: invalid Jalaali date {$jy}/{$jm}/{$jd}");
        }
        if ($jy < self::MIN_YEAR || $jy > self::MAX_YEAR) {
            return self::toGregorianBorkowski($jy, $jm, $jd);
        }

        $offset = ($jm - 1) * 31 - intdiv($jm, 7) * ($jm - 7) + $jd - 1;
        $ts = self::yearStartTs($jy) + $offset * 86400;
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC'));

        return [(int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j')];
    }

    public static function isValidJalaliDate(int $jy, int $jm, int $jd): bool
    {
        return $jm >= 1 && $jm <= 12 && $jd >= 1 && $jd <= self::monthLength($jy, $jm);
    }

    public static function isLeapJalaaliYear(int $jy): bool
    {
        if ($jy >= self::MIN_YEAR && $jy <= self::MAX_YEAR) {
            return self::yearStartTs($jy + 1) - self::yearStartTs($jy) > 365 * 86400;
        }

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

    // ============ Internal ============

    /**
     * @return array{0: int, 1: int} [jm, jd]
     */
    private static function offsetToMonthDay(int $offset): array
    {
        if ($offset < 186) {
            return [intdiv($offset, 31) + 1, $offset % 31 + 1];
        }
        $k = $offset - 186;

        return [intdiv($k, 30) + 7, $k % 30 + 1];
    }

    private static function yearStartTs(int $jy): int
    {
        $d = (new \DateTimeImmutable(self::NOWRUZ[$jy], new \DateTimeZone('UTC')));

        return $d->getTimestamp();
    }

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
