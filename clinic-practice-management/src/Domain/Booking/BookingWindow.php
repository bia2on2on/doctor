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
     * اعتبارسنجی درخواست رزرو (quote/hold/staff-create) — LEGACY UTC path.
     * نگه داشته شده برای سازگاری تست‌های قدیمی؛ مسیر جدید باید از
     * checkRequestWithTimezone استفاده کند (Two-Clock rule).
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
     * اعتبارسنجی درخواست رزرو با timezone معتبر Location (Two-Clock rule).
     * nowUtc = لحظه سیستمی UTC، slotDate/slotTime = wall-clock محلی Location.
     */
    public static function checkRequestWithTimezone(
        string $slotDate,
        string $slotTime,
        DateTimeZone $locationTz,
        DateTimeImmutable $nowUtc,
        int $minLeadHours,
        int $maxFutureDays
    ): ?string {
        $dtUtc = self::slotUtcInstant($slotDate, $slotTime, $locationTz);
        if ($dtUtc === null) {
            return self::CODE_INVALID;
        }
        $minLead = $nowUtc->add(new \DateInterval('PT' . max(0, $minLeadHours) . 'H'));
        if ($dtUtc < $minLead) {
            return self::CODE_POLICY;
        }
        $max = $nowUtc->add(new \DateInterval('P' . max(0, $maxFutureDays) . 'D'));
        if ($dtUtc > $max) {
            return self::CODE_POLICY;
        }

        return null;
    }

    /**
     * اعتبارسنجی لغو/جابه‌جایی: حداقل `deadlineHours` ساعت قبل از شروع.
     * (Nobát گذشته = دیگر قابل لغو نیست؛ در V1 جریمه مالی ندارد — SRS FR-4.9.)
     * LEGACY UTC path.
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
     * اعتبارسنجی لغو/جابه‌جایی با timezone معتبر Location (Two-Clock rule).
     */
    public static function checkCancelWithTimezone(
        string $slotDate,
        string $slotTime,
        DateTimeZone $locationTz,
        DateTimeImmutable $nowUtc,
        int $deadlineHours
    ): ?string {
        $dtUtc = self::slotUtcInstant($slotDate, $slotTime, $locationTz);
        if ($dtUtc === null) {
            return self::CODE_INVALID;
        }
        $deadline = $dtUtc->sub(new \DateInterval('PT' . max(0, $deadlineHours) . 'H'));
        if ($nowUtc > $deadline) {
            return self::CODE_POLICY;
        }

        return null;
    }

    /**
     * Parse سخت (strict) تاریخ+زمان UTC — بدون Roll-over.
     * LEGACY path — wall-clock را UTC تفسیر می‌کند.
     *
     * @return DateTimeImmutable|null null = فرمت/تاریخ نامعتبر (مثلاً 2026-02-30)
     */
    public static function slotDateTime(string $slotDate, string $slotTime): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $slotDate) !== 1) {
            return null;
        }
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $slotTime, $m)) {
            return null;
        }
        $h = str_pad((string) (int) $m[1], 2, '0', STR_PAD_LEFT);
        $min = str_pad((string) $m[2], 2, '0', STR_PAD_LEFT);
        $sec = isset($m[3]) ? str_pad((string) $m[3], 2, '0', STR_PAD_LEFT) : '00';

        $normalized = sprintf('%s %s:%s:%s', $slotDate, $h, $min, $sec);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, new DateTimeZone('UTC'));
        if ($dt === false) {
            return null;
        }
        if ($dt->format('Y-m-d H:i:s') !== $normalized) {
            return null;
        }

        return $dt;
    }

    /**
     * تبدیل wall-clock محلی Location به لحظه UTC (Two-Clock rule).
     *
     * @return DateTimeImmutable|null null = فرمت نامعتبر
     */
    public static function slotUtcInstant(string $slotDate, string $slotTime, DateTimeZone $locationTz): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $slotDate) !== 1) {
            return null;
        }
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $slotTime, $m)) {
            return null;
        }
        $h = str_pad((string) (int) $m[1], 2, '0', STR_PAD_LEFT);
        $min = str_pad((string) $m[2], 2, '0', STR_PAD_LEFT);
        $sec = isset($m[3]) ? str_pad((string) $m[3], 2, '0', STR_PAD_LEFT) : '00';

        $normalized = sprintf('%s %s:%s:%s', $slotDate, $h, $min, $sec);
        $local = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, $locationTz);
        if ($local === false) {
            return null;
        }
        if ($local->format('Y-m-d H:i:s') !== $normalized) {
            return null;
        }

        return $local->setTimezone(new DateTimeZone('UTC'));
    }
}
