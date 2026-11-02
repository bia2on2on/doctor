<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Time\Jalali;
use PHPUnit\Framework\TestCase;

/**
 * Shared-fixture conversion test for `ClinicCore\Domain\Time\Jalali`.
 *
 * Reads `tests/fixtures/jalali-intl-vectors.json` (generated from ICU `Intl`
 * `calendar=persian`, matching the JS spec `pkg-p1-jalali-conversion.spec.js`)
 * and verifies all vectors, leap years, month lengths, invalid-day rejections,
 * and full day-by-day round-trip across 1300..1499 without changing `Jalali`'s
 * public API.
 */
final class PkgP1JalaliConversionTest extends TestCase
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $fixture = null;

    /**
     * @return array<string, mixed>
     */
    private static function loadFixture(): array
    {
        if (self::$fixture !== null) {
            return self::$fixture;
        }

        $path = dirname(__DIR__) . '/fixtures/jalali-intl-vectors.json';
        self::assertFileExists($path);
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::$fixture = $decoded;

        return self::$fixture;
    }

    public function testFixtureHeaderAndAnchors(): void
    {
        $fixture = self::loadFixture();
        $meta = $fixture['meta'] ?? null;
        $this->assertIsArray($meta);
        $this->assertNotSame('', (string) ($meta['icu_version'] ?? ''));
        $this->assertCount(200, $fixture['nowruz']);
        $this->assertCount(192, $fixture['month_ends_1395_1410']);
        $this->assertGreaterThanOrEqual(1000, count($fixture['samples']));

        $this->assertVectorGroup($fixture['anchors'], 'anchors');
    }

    public function testAllNowruzVectors1300To1499(): void
    {
        $fixture = self::loadFixture();
        $this->assertVectorGroup($fixture['nowruz'], 'nowruz');
    }

    public function testAllMonthEnds1395To1410(): void
    {
        $fixture = self::loadFixture();
        $this->assertVectorGroup($fixture['month_ends_1395_1410'], 'month_ends');
    }

    public function testDeterministicSamplesAcross1300To1499(): void
    {
        $fixture = self::loadFixture();
        $this->assertVectorGroup($fixture['samples'], 'samples');
    }

    public function testLeapYearsMonthLengthsAndFullRoundTrip1300To1499(): void
    {
        $fixture = self::loadFixture();
        /** @var array<int, bool> $leapMap */
        $leapMap = [];
        foreach ($fixture['leap_years'] as $ly) {
            $leapMap[(int) $ly] = true;
        }

        $this->assertTrue(isset($leapMap[1399]));
        $this->assertTrue(isset($leapMap[1403]));
        $this->assertFalse(isset($leapMap[1401]));
        $this->assertFalse(isset($leapMap[1405]));

        $prevJdn = null;
        $daysChecked = 0;

        for ($jy = 1300; $jy <= 1499; $jy++) {
            $expectedLeap = isset($leapMap[$jy]);
            $this->assertSame($expectedLeap, Jalali::isLeapJalaaliYear($jy), "leap mismatch for {$jy}");

            for ($jm = 1; $jm <= 12; $jm++) {
                $expectedLen = $jm <= 6 ? 31 : ($jm <= 11 ? 30 : ($expectedLeap ? 30 : 29));
                $this->assertSame($expectedLen, Jalali::monthLength($jy, $jm), "monthLength mismatch for {$jy}/{$jm}");
                $this->assertFalse(Jalali::isValidJalaliDate($jy, $jm, $expectedLen + 1));

                for ($jd = 1; $jd <= $expectedLen; $jd++) {
                    $this->assertTrue(Jalali::isValidJalaliDate($jy, $jm, $jd));
                    [$gy, $gm, $gd] = Jalali::toGregorian($jy, $jm, $jd);
                    $jdn = Jalali::g2d($gy, $gm, $gd);
                    if ($prevJdn !== null) {
                        $this->assertSame(1, $jdn - $prevJdn, "non-consecutive day at {$jy}/{$jm}/{$jd}");
                    }
                    $prevJdn = $jdn;
                    $this->assertSame([$jy, $jm, $jd], Jalali::toJalali($gy, $gm, $gd), "round-trip failed at {$jy}/{$jm}/{$jd}");
                    $daysChecked++;
                }
            }
        }

        $this->assertSame((int) $fixture['meta']['total_days_in_range'], $daysChecked);
    }

    public function testRejectsDay31InMonth7(): void
    {
        $this->assertFalse(Jalali::isValidJalaliDate(1403, 7, 31));
        $this->expectException(\DomainException::class);
        Jalali::toGregorian(1403, 7, 31);
    }

    public function testRejectsDay30EsfandInNonLeapYear1401(): void
    {
        $this->assertFalse(Jalali::isValidJalaliDate(1401, 12, 30));
        $this->assertFalse(Jalali::isValidJalaliDate(1405, 12, 30));
        $this->expectException(\DomainException::class);
        Jalali::toGregorian(1401, 12, 30);
    }

    /**
     * @param list<array{jy: int, jm: int, jd: int, gy: int, gm: int, gd: int, iso: string}> $vectors
     */
    private function assertVectorGroup(array $vectors, string $group): void
    {
        foreach ($vectors as $v) {
            $jy = (int) $v['jy'];
            $jm = (int) $v['jm'];
            $jd = (int) $v['jd'];
            $gy = (int) $v['gy'];
            $gm = (int) $v['gm'];
            $gd = (int) $v['gd'];
            $iso = (string) $v['iso'];

            $this->assertSame(
                [$jy, $jm, $jd],
                Jalali::toJalali($gy, $gm, $gd),
                "{$group}: toJalali({$iso}) mismatch"
            );
            $this->assertSame(
                [$gy, $gm, $gd],
                Jalali::toGregorian($jy, $jm, $jd),
                "{$group}: toGregorian({$jy}/{$jm}/{$jd}) mismatch"
            );
            $this->assertSame(
                sprintf('%d/%02d/%02d', $jy, $jm, $jd),
                Jalali::formatYmd($iso),
                "{$group}: formatYmd({$iso}) mismatch"
            );
        }
    }
}
