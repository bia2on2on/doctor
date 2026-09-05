<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Booking;

use DateTimeImmutable;
use DateTimeZone;

/**
 * قوانین Window رزرو/لغو/جابه‌جایی (SRS FR-4.9/FR-4.10) — خالص و دترمینیستیک.
 *
 * همه مقادیر زمانی **UTC**. تابع‌ها در صورت اعتبار `null` برمی‌گردانند،
 * در غیر این‌صورت کد خطای `CLINIC_*` (ADR-0019).
 *
 * Policy (از Settings — قابل تنظیم):
 *  - `minLeadHours`   : حداقل فاصله زمانی تا شروع نوبت (پیش‌فرض 2h)
 *  - `maxFutureDays`  : بیشینه افق رزرو (پیش‌فرض 60 روز)
 *  - `cancelDeadlineHours` / `rescheduleDeadlineHours` : حداقل X ساعت قبل از شروع
 *    (پیش‌فرض 24h مطابق SRS FR-4.9 — V1 بدون جریمه مالی، فقط محدودیت زمانی)
 */
final class BookingWindow
{
    public const CODE_INVALID = 'CLINIC_VALIDATION_FAILED';
    public const CODE_POLICY = 'CLINIC_POLICY_VIOLATION';

    /**
     * اعتبارسنجی درخواست رزرو (quote/hold/staff-create).
     */
    public static function checkRequest(
        string $slotDate,
        string $slotTime,
        DateTimeImmutable $nowUtc,
        int $minLeadHours,
        int $maxFutureDays
    ): ?string {
        $dt = self::slotDateTime($slotDate, $slotTime);
        if ($dt === null) {
            return self::CODE_INVALID;
        }
        $minLead = $nowUtc->add(new \DateInterval('PT' . max(0, $minLeadHours) . 'H'));
        if ($dt < $minLead) {
            return self::CODE_POLICY;
        }
        $max = $nowUtc->add(new \DateInterval('P' . max(0, $maxFutureDays) . 'D'));
        if ($dt > $max) {
            return self::CODE_POLICY;
        }

        return null;
    }

    /**
     * اعتبارسنجی لغو/جابه‌جایی: حداقل `deadlineHours` ساعت قبل از شروع.
     * (Nobát گذشته = دیگر قابل لغو نیست؛ در V1 جریمه مالی ندارد — SRS FR-4.9.)
     */
    public static function checkCancel(
        string $slotDate,
        string $slotTime,
        DateTimeImmutable $nowUtc,
        int $deadlineHours
    ): ?string {
        $dt = self::slotDateTime($slotDate, $slotTime);
        if ($dt === null) {
            return self::CODE_INVALID;
        }
        $deadline = $dt->sub(new \DateInterval('PT' . max(0, $deadlineHours) . 'H'));
        if ($nowUtc > $deadline) {
            return self::CODE_POLICY;
        }

        return null;
    }

    /**
     * Parse سخت (strict) تاریخ+زمان UTC — بدون Roll-over.
     * فرمت‌ها: `Y-m-d` و `H:i` یا `H:i:s` (ساعت 00..23).
     *
     * @return DateTimeImmutable|null null = فرمت/تاریخ نامعتبر (مثلاً 2026-02-30)
     */
    public static function slotDateTime(string $slotDate, string $slotTime): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $slotDate) !== 1) {
            return null;
        }
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $slotTime) !== 1) {
            return null;
        }
        $hour = str_pad((string) (int) substr($slotTime, 0, strpos($slotTime, ':')), 2, '0', STR_PAD_LEFT);
        $minute = substr($slotTime, strcspn($slotTime, ':') + 1);
        $colon2 = strpos($minute, ':');
        if ($colon2 === false) {
            $minute = str_pad($minute, 2, '0', STR_PAD_LEFT);
            $second = '00';
        } else {
            $second = substr($minute, $colon2 + 1);
            $minute = substr($minute, 0, $colon2);
        }

        $normalized = sprintf('%s %s:%s:%s', $slotDate, $hour, $minute, $second);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, new DateTimeZone('UTC'));
        if ($dt === false) {
            return null;
        }
        // Round-trip: تاریخ ناموجود (مثل 30 فوریه) یا ساعت/دقیقه غلط رد می‌شوند
        if ($dt->format('Y-m-d H:i:s') !== $normalized) {
            return null;
        }

        return $dt;
    }
}
