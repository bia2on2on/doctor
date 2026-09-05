<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Slots;

use DomainException;

/**
 * تولید Slotهای یک روز از برنامه هفتگی + استثنائات — خالص (ADR-0004).
 *
 * خروجی: لیست ساعت‌های شروع "H:i" (UTC-aware: ساعت‌ها محلی کلینیک — ذخیره با DATE+TIME).
 *
 * استثنائات (cpms_schedule_exceptions.type):
 *  - holiday / leave      → کل روز بسته
 *  - blocked              → بازه [start, end] بسته (اگر start/end نبود کل روز)
 *  - open_override        → بازه اضافی باز (خارج از برنامه اصلی)
 */
final class SlotGenerator
{
    /**
     * @param array{start: string, end: string, break_start?: string|null, break_end?: string|null} $schedule
     *        format "H:i"
     * @param list<array{type: string, start?: string|null, end?: string|null}> $exceptions
     *
     * @return list<string>
     *
     * @throws DomainException
     */
    public static function generateDay(array $schedule, int $durationMin, array $exceptions = []): array
    {
        if ($durationMin <= 0) {
            throw new DomainException('CLINIC_DURATION_INVALID');
        }

        // 1) کل روز بسته؟
        foreach ($exceptions as $e) {
            if (in_array($e['type'] ?? '', ['holiday', 'leave'], true)) {
                return [];
            }
        }

        $baseStart = self::toSeconds($schedule['start']);
        $baseEnd = self::toSeconds($schedule['end']);
        if ($baseEnd <= $baseStart) {
            return [];
        }

        $slots = self::grid($baseStart, $baseEnd, $durationMin);

        // 2) استراحت و blocked
        $breakStart = $schedule['break_start'] ?? null;
        $breakEnd = $schedule['break_end'] ?? null;
        $intervals = [];
        if ($breakStart !== null && $breakEnd !== null) {
            $intervals[] = [self::toSeconds($breakStart), self::toSeconds($breakEnd)];
        }
        foreach ($exceptions as $e) {
            if (($e['type'] ?? '') === 'blocked') {
                $s = $e['start'] ?? null;
                $en = $e['end'] ?? null;
                $intervals[] = [
                    $s !== null ? self::toSeconds($s) : 0,
                    $en !== null ? self::toSeconds($en) : 86399,
                ];
            }
        }
        $durationSec = $durationMin * 60;
        if ($intervals !== []) {
            $slots = array_values(array_filter(
                $slots,
                static fn (string $t): bool => !self::overlapsAny(self::toSeconds($t), $durationSec, $intervals)
            ));
        }

        // 3) open_override (ساعت‌های اضافی خارج از برنامه اصلی)
        foreach ($exceptions as $e) {
            if (($e['type'] ?? '') !== 'open_override') {
                continue;
            }
            $s = $e['start'] ?? null;
            $en = $e['end'] ?? null;
            if ($s === null || $en === null) {
                continue;
            }
            $extra = self::grid(self::toSeconds($s), self::toSeconds($en), $durationMin);
            foreach ($extra as $t) {
                $ts = self::toSeconds($t);
                $slotEnd = $ts + $durationMin * 60;
                // فقط اگر با برنامه اصلی و intervalهای بسته همپوشانی نداشته باشد
                if (($ts >= $baseStart && $slotEnd <= $baseEnd)
                    || self::overlapsAny($ts, $durationSec, $intervals)) {
                    continue;
                }
                $slots[] = $t;
            }
        }

        $slots = array_values(array_unique($slots));
        sort($slots);

        return $slots;
    }

    /**
     * @param list<array{0: int, 1: int}> $intervals seconds [start, end)
     */
    public static function overlapsAny(int $start, int $durationSec, array $intervals): bool
    {
        $end = $start + $durationSec;
        foreach ($intervals as [$is, $ie]) {
            if ($start < $ie && $end > $is) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function grid(int $startSec, int $endSec, int $durationMin): array
    {
        $slots = [];
        $step = $durationMin * 60;
        for ($t = $startSec; $t + $step <= $endSec; $t += $step) {
            $slots[] = sprintf('%02d:%02d', intdiv($t, 3600), intdiv($t % 3600, 60));
        }

        return $slots;
    }

    private static function toSeconds(string $hms): int
    {
        $parts = explode(':', $hms);
        if (count($parts) < 2) {
            throw new DomainException('TIME_FORMAT_INVALID: ' . $hms);
        }

        return ((int) $parts[0]) * 3600 + ((int) $parts[1]) * 60 + (isset($parts[2]) ? (int) $parts[2] : 0);
    }
}
