<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Visits\VisitService;
use PHPUnit\Framework\TestCase;

/**
 * VisitService::appointmentStatusLabel — منبعِ واحدِ برچسپ فارسیِ وضعیت (ITEM 5).
 * خالص (بدون دیتابیس/وردپرس) → PHPUnit\Framework\TestCase خالص.
 */
final class VisitStatusLabelTest extends TestCase
{
    public function testVisitStatusesHavePersianLabels(): void
    {
        self::assertSame('ثبت‌شده', VisitService::appointmentStatusLabel('checked_in'));
        self::assertSame('در صف', VisitService::appointmentStatusLabel('waiting'));
        self::assertSame('فراخوانده‌شده', VisitService::appointmentStatusLabel('called'));
        self::assertSame('در ویزیت', VisitService::appointmentStatusLabel('in_consultation'));
        self::assertSame('پایان ویزیت', VisitService::appointmentStatusLabel('consultation_completed'));
        self::assertSame('در انتظار پرداخت', VisitService::appointmentStatusLabel('awaiting_payment'));
        self::assertSame('پرداخت‌شده', VisitService::appointmentStatusLabel('paid'));
        self::assertSame('خارج‌شده', VisitService::appointmentStatusLabel('checked_out'));
        self::assertSame('لغوشده', VisitService::appointmentStatusLabel('cancelled'));
        self::assertSame('ردشده', VisitService::appointmentStatusLabel('skipped'));
    }

    public function testAppointmentStatusesKeepPersianLabels(): void
    {
        self::assertSame('توسط بیمار لغو', VisitService::appointmentStatusLabel('cancelled_by_patient'));
        self::assertSame('توسط کلینیک لغو', VisitService::appointmentStatusLabel('cancelled_by_staff'));
        self::assertSame('جابه‌جا شده', VisitService::appointmentStatusLabel('rescheduled'));
        self::assertSame('تکمیل شده', VisitService::appointmentStatusLabel('completed'));
    }

    public function testUnknownStatusNeverLeaksRawEnum(): void
    {
        self::assertSame('—', VisitService::appointmentStatusLabel('awaiting_payment_raw'));
        self::assertSame('—', VisitService::appointmentStatusLabel(''));
        self::assertSame('—', VisitService::appointmentStatusLabel('anything_else'));
    }
}
