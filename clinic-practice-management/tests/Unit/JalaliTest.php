<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Time\Jalali;
use PHPUnit\Framework\TestCase;

/**
 * Jalali (جدول رسمی Nowruz 1390–1435 — V2 table-based).
 * صحت با:
 *  1) Nowruzهای رسمی/منتشرشده (2011–2026؛ شامل 1402/1/1 = 2023-03-21 که الگوریتم
 *     اریتمتیکی Borkowski آن را اشتباه می‌گرفت)
 *  2) Round-trip کامل 2011–2056 (کل بازه جدول)
 *  3) سال‌های کبیسه از جدول
 */
final class JalaliTest extends TestCase
{
    public function testKnownNowruzAnchors(): void
    {
        // 1 فروردین = روز رسمی Nowruz (تقویم رسمی ایران)
        $this->assertSame([1390, 1, 1], Jalali::toJalali(2011, 3, 21));
        $this->assertSame([1394, 1, 1], Jalali::toJalali(2015, 3, 21));
        $this->assertSame([1398, 1, 1], Jalali::toJalali(2019, 3, 21));
        $this->assertSame([1399, 1, 1], Jalali::toJalali(2020, 3, 20));
        $this->assertSame([1402, 1, 1], Jalali::toJalali(2023, 3, 21), '1402/1/1 رسمی = 2023-03-21 (اعتدال 21:24 UTC)');
        $this->assertSame([1403, 1, 1], Jalali::toJalali(2024, 3, 20));
        $this->assertSame([1404, 1, 1], Jalali::toJalali(2025, 3, 20));
        $this->assertSame([1405, 1, 1], Jalali::toJalali(2026, 3, 20));
        // روز قبل از Nowruz = آخر اسفند سال قبل (1402 و 1403 هر دو غیرکبیسه)
        $this->assertSame([1402, 12, 29], Jalali::toJalali(2024, 3, 19));
        $this->assertSame([1403, 12, 29], Jalali::toJalali(2025, 3, 19));
        // مرجع مستند (هم‌راستا با الگوریتم Borkowski هم)
        $this->assertSame([1395, 1, 23], Jalali::toJalali(2016, 4, 11));
        $this->assertSame(2457490, Jalali::g2d(2016, 4, 11));
        $this->assertSame(2457490, Jalali::j2d(1395, 1, 23));
    }

    public function testKnownLeapYearsFromTable(): void
    {
        // سال کبیسه = سالی که بین Nowruz آن و Nowruz بعدی، 29 فوریه میلادی قرار دارد
        $this->assertFalse(Jalali::isLeapJalaaliYear(1402));
        $this->assertFalse(Jalali::isLeapJalaaliYear(1403));
        $this->assertFalse(Jalali::isLeapJalaaliYear(1404));
        $this->assertFalse(Jalali::isLeapJalaaliYear(1405));
        $this->assertTrue(Jalali::isLeapJalaaliYear(1406), '1406: شامل 29 فوریه 2028 — 366 روز');
        $this->assertTrue(Jalali::isLeapJalaaliYear(1410), '1410: شامل 29 فوریه 2032');
        $this->assertFalse(Jalali::isLeapJalaaliYear(1407));
        $this->assertSame(30, Jalali::monthLength(1406, 12));
        $this->assertSame(29, Jalali::monthLength(1405, 12));
        $this->assertSame(29, Jalali::monthLength(1402, 12));
    }

    public function testTodayIsCorrectJalali(): void
    {
        // 2026-09-05 → ۱۵ شهریور ۱۴۰۵ (شهریور = ماه ۶)
        $this->assertSame([1405, 6, 15], Jalali::toJalali(2026, 9, 5));
        $this->assertSame('1405/06/15', Jalali::formatYmd('2026-09-05'));
        // بازگشت مستقیم
        $this->assertSame([2026, 9, 5], Jalali::toGregorian(1405, 6, 15));
    }

    public function testRoundTripWholeTableRange(): void
    {
        $start = new \DateTimeImmutable('2011-03-21', new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable('2056-03-19', new \DateTimeZone('UTC'));
        $checked = 0;
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            [$gy, $gm, $gd] = [(int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j')];
            [$jy, $jm, $jd] = Jalali::toJalali($gy, $gm, $gd);
            $this->assertTrue(Jalali::isValidJalaliDate($jy, $jm, $jd), "invalid jalali {$jy}-{$jm}-{$jd} for {$gy}-{$gm}-{$gd}");
            [$ry, $rm, $rd] = Jalali::toGregorian($jy, $jm, $jd);
            $this->assertSame([$gy, $gm, $gd], [$ry, $rm, $rd], 'round-trip failed for ' . $d->format('Y-m-d'));
            $checked++;
        }
        $this->assertGreaterThan(16000, $checked);
    }

    public function testMonthLengths(): void
    {
        $this->assertSame(31, Jalali::monthLength(1405, 1));
        $this->assertSame(31, Jalali::monthLength(1405, 6));
        $this->assertSame(30, Jalali::monthLength(1405, 7));
        $this->assertSame(30, Jalali::monthLength(1405, 11));
    }

    public function testInvalidDatesRejected(): void
    {
        $this->expectException(\DomainException::class);
        Jalali::toGregorian(1405, 13, 1);
    }
}
