<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Slots;

use DomainException;

/**
 * Resolution مدت نوبت — ADR-0017 (خالص و قابل تست).
 *
 * سلسله‌مراتب (اولین مقدار معتبر برنده):
 *   1. Override نوبت تکی (appointment)
 *   2. Override Service
 *   3. Override پزشک (schedule.clinician)
 *   4. پیش‌فرض کلینیک (settings)
 *
 * قانون سفت: مقدار Resolved در زمان Booking Snapshot می‌شود
 * (appointments.duration_min / slot_end_time) — تغییر تنظیمات بعدی
 * روی نوبت‌های موجود اثر ندارد (TP-21).
 */
final class DurationResolver
{
    public static function resolve(
        ?int $appointmentOverride,
        ?int $serviceDuration,
        ?int $doctorDuration,
        int $clinicDefault
    ): int {
        foreach ([$appointmentOverride, $serviceDuration, $doctorDuration] as $candidate) {
            if ($candidate !== null && $candidate > 0) {
                return $candidate;
            }
        }
        if ($clinicDefault <= 0) {
            throw new DomainException('CLINIC_DURATION_INVALID: clinic default must be > 0');
        }

        return $clinicDefault;
    }

    /**
     * زمان پایان Slot — "H:i:s" (بدون carry-over تاریخ؛ عباز از نیمه‌شب در Booking Service
     * مدیریت می‌شود: slot_end_time < slot_time یعنی +1 روز).
     */
    public static function slotEndTime(string $startTime, int $durationMin): string
    {
        $parts = explode(':', $startTime);
        $seconds = ((int) ($parts[0] ?? 0)) * 3600
            + ((int) ($parts[1] ?? 0)) * 60
            + (int) ($parts[2] ?? 0);
        $seconds = ($seconds + $durationMin * 60) % 86400;

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * آیا پایان در روز بعد از شروع است؟ (مرز نیمه‌شب)
     */
    public static function crossesMidnight(string $startTime, int $durationMin): bool
    {
        $start = self::toSeconds($startTime);

        return $start + $durationMin * 60 > 86400;
    }

    public static function toSeconds(string $hms): int
    {
        $parts = explode(':', $hms);

        return ((int) ($parts[0] ?? 0)) * 3600
            + ((int) ($parts[1] ?? 0)) * 60
            + (int) ($parts[2] ?? 0);
    }
}
