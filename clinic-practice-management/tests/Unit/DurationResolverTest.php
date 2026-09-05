<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Slots\DurationResolver;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TP-21 (بخش Resolution) — ADR-0017: سلسله‌مراتب مدت + Snapshot semantics.
 */
final class DurationResolverTest extends TestCase
{
    /**
     * @return array<string, array{0: ?int, 1: ?int, 2: ?int, 3: int, 4: int}>
     */
    public static function resolutionProvider(): array
    {
        return [
            'appointment override wins' => [30, 25, 20, 15, 30],
            'service override (no appt)' => [null, 25, 20, 15, 25],
            'doctor override (no appt/service)' => [null, null, 20, 15, 20],
            'clinic default (all null)' => [null, null, null, 15, 15],
            'invalid zero falls through' => [0, 25, 20, 15, 25],
            'invalid negative falls through' => [-5, null, 20, 15, 20],
        ];
    }

    #[DataProvider('resolutionProvider')]
    public function testResolutionHierarchy(?int $appt, ?int $service, ?int $doctor, int $default, int $expected): void
    {
        $this->assertSame($expected, DurationResolver::resolve($appt, $service, $doctor, $default));
    }

    public function testInvalidClinicDefaultRejected(): void
    {
        $this->expectException(DomainException::class);
        DurationResolver::resolve(null, null, null, 0);
    }

    public function testSlotEndTime(): void
    {
        $this->assertSame('09:20:00', DurationResolver::slotEndTime('09:00:00', 20));
        $this->assertSame('10:00:00', DurationResolver::slotEndTime('09:30:00', 30));
        $this->assertSame('00:10:00', DurationResolver::slotEndTime('23:50:00', 20));
    }

    public function testCrossesMidnightDetection(): void
    {
        $this->assertFalse(DurationResolver::crossesMidnight('09:00:00', 20));
        $this->assertTrue(DurationResolver::crossesMidnight('23:50:00', 20));
        $this->assertFalse(DurationResolver::crossesMidnight('23:40:00', 20));
    }

    /**
     * معنای Snapshot (TP-21): تغییر Default بعد از Booking نباید روی مقدار ذخیره‌شده اثر بگذارد.
     * (پیاده‌سازی Snapshot در Booking Service F3؛ اینجا ثابت می‌کنیم که Resolver
     * مقدار «لحظه‌ای» برمی‌گرداند و مقادیر بعدی به آن برنمی‌گردند.)
     */
    public function testResolvedValueIsFrozenAtBookingTime(): void
    {
        $atBooking = DurationResolver::resolve(null, null, null, 20);

        // "تغییر" پیش‌فرض کلینیک بعد از Booking:
        $newDefault = 30;
        $unchanged = DurationResolver::resolve(20, null, null, $newDefault); // override = مقدار booking

        $this->assertSame(20, $atBooking);
        $this->assertSame(20, $unchanged, 'Snapshot Booking با تغییر Default بعدی دست نمی‌خورد');
    }
}
