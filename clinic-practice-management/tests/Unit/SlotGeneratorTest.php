<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Slots\SlotGenerator;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * تست SlotGenerator — برنامه هفتگی + استثنائات (FR-3.x).
 */
final class SlotGeneratorTest extends TestCase
{
    private const SCHED = ['start' => '09:00', 'end' => '10:00']; // 3 slot با 20 دقیقه

    public function testBasicGrid(): void
    {
        $slots = SlotGenerator::generateDay(self::SCHED, 20);
        $this->assertSame(['09:00', '09:20', '09:40'], $slots);
    }

    public function testBreakExcludesOverlappingSlots(): void
    {
        $sched = ['start' => '09:00', 'end' => '11:00', 'break_start' => '10:00', 'break_end' => '10:30'];
        $slots = SlotGenerator::generateDay($sched, 30);
        // slots: 09:00, 09:30, 10:00(❌ overlap break), 10:30 ✓ (با break تمام می‌شود؟ slot 10:30-11:00 → yes)
        $this->assertSame(['09:00', '09:30', '10:30'], $slots);
    }

    public function testHolidayClosesWholeDay(): void
    {
        $slots = SlotGenerator::generateDay(self::SCHED, 20, [
            ['type' => 'holiday', 'start' => null, 'end' => null],
        ]);
        $this->assertSame([], $slots);
    }

    public function testLeaveClosesWholeDay(): void
    {
        $slots = SlotGenerator::generateDay(self::SCHED, 20, [
            ['type' => 'leave', 'start' => null, 'end' => null],
        ]);
        $this->assertSame([], $slots);
    }

    public function testBlockedInterval(): void
    {
        $slots = SlotGenerator::generateDay(self::SCHED, 20, [
            ['type' => 'blocked', 'start' => '09:15', 'end' => '09:45'],
        ]);
        // 09:00-09:20 overlap ❌ ; 09:20-09:40 overlap ❌ ; 09:40-10:00 بعد از Block (پایان Block 09:45 < شروع Slot 09:40؟ نه — 09:40 < 09:45!)
        // بررسی: Slot 09:40 شروع 09:40، Block پایان 09:45 → همپوشانی دارد (09:40 < 09:45) → ❌
        $this->assertSame([], $slots);
    }

    public function testBlockedPartial(): void
    {
        $slots = SlotGenerator::generateDay(self::SCHED, 20, [
            ['type' => 'blocked', 'start' => '09:00', 'end' => '09:20'],
        ]);
        $this->assertSame(['09:20', '09:40'], $slots);
    }

    public function testOpenOverrideAddsOutsideSlots(): void
    {
        $slots = SlotGenerator::generateDay(self::SCHED, 20, [
            ['type' => 'open_override', 'start' => '14:00', 'end' => '14:40'],
        ]);
        $this->assertSame(['09:00', '09:20', '09:40', '14:00', '14:20'], $slots);
    }

    public function testOpenOverrideInsideBaseIgnored(): void
    {
        $slots = SlotGenerator::generateDay(self::SCHED, 20, [
            ['type' => 'open_override', 'start' => '09:00', 'end' => '09:40'],
        ]);
        $this->assertSame(['09:00', '09:20', '09:40'], $slots);
    }

    public function testEndExclusiveNoPartialSlot(): void
    {
        $slots = SlotGenerator::generateDay(['start' => '09:00', 'end' => '09:30'], 20);
        // فقط 09:00-09:20 (09:20-09:40 خارج از end است)
        $this->assertSame(['09:00'], $slots);
    }

    public function testInvertedScheduleEmpty(): void
    {
        $this->assertSame([], SlotGenerator::generateDay(['start' => '10:00', 'end' => '09:00'], 20));
    }

    public function testInvalidDurationRejected(): void
    {
        $this->expectException(DomainException::class);
        SlotGenerator::generateDay(self::SCHED, 0);
    }

    public function testInvalidTimeFormatRejected(): void
    {
        $this->expectException(DomainException::class);
        SlotGenerator::generateDay(['start' => '9am', 'end' => '10:00'], 20);
    }
}
