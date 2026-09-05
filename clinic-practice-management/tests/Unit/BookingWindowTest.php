<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Booking\BookingWindow;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * BookingWindow (SRS FR-4.9/FR-4.10) — قوانین رزرو/لغو، خالص و دترمینیستیک (UTC).
 */
final class BookingWindowTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-05 12:00:00', new DateTimeZone('UTC'));
    }

    // ---------- checkRequest ----------

    public function testRequestWithinWindowIsValid(): void
    {
        // 2 ساعت بعد (دقیقاً روی مرز Lead) و چند روز بعد
        $this->assertNull(BookingWindow::checkRequest('2026-09-05', '14:00', $this->now, 2, 60));
        $this->assertNull(BookingWindow::checkRequest('2026-09-06', '09:30', $this->now, 2, 60));
        $this->assertNull(BookingWindow::checkRequest('2026-11-04', '11:59', $this->now, 2, 60));
    }

    public function testRequestBeforeMinLeadIsPolicyViolation(): void
    {
        $this->assertSame(
            BookingWindow::CODE_POLICY,
            BookingWindow::checkRequest('2026-09-05', '13:59', $this->now, 2, 60)
        );
        $this->assertSame(
            BookingWindow::CODE_POLICY,
            BookingWindow::checkRequest('2026-09-04', '09:00', $this->now, 2, 60),
            'گذشته = خارج Window'
        );
    }

    public function testRequestBeyondMaxFutureDaysIsPolicyViolation(): void
    {
        $this->assertSame(
            BookingWindow::CODE_POLICY,
            BookingWindow::checkRequest('2026-11-05', '09:00', $this->now, 2, 60)
        );
    }

    public function testInvalidDateOrTimeIsValidationError(): void
    {
        $this->assertSame(BookingWindow::CODE_INVALID, BookingWindow::checkRequest('2026-02-30', '09:00', $this->now, 2, 60));
        $this->assertSame(BookingWindow::CODE_INVALID, BookingWindow::checkRequest('2026-13-01', '09:00', $this->now, 2, 60));
        $this->assertSame(BookingWindow::CODE_INVALID, BookingWindow::checkRequest('2026-09-06', '24:00', $this->now, 2, 60));
        $this->assertSame(BookingWindow::CODE_INVALID, BookingWindow::checkRequest('2026-09-06', '09:60', $this->now, 2, 60));
        $this->assertSame(BookingWindow::CODE_INVALID, BookingWindow::checkRequest('05/09/2026', '09:00', $this->now, 2, 60));
        $this->assertSame(BookingWindow::CODE_INVALID, BookingWindow::checkRequest('2026-09-06', 'lunch', $this->now, 2, 60));
    }

    // ---------- checkCancel ----------

    public function testCancelBeforeDeadlineIsAllowed(): void
    {
        // نوبت 2 روز بعد (46 ساعت) — مجاز
        $this->assertNull(BookingWindow::checkCancel('2026-09-07', '10:00', $this->now, 24));
        // نوبت فردا ساعت 12 — دقیقاً 24 ساعت بعد → مرز (>=) مجاز
        $this->assertNull(BookingWindow::checkCancel('2026-09-06', '12:00', $this->now, 24));
    }

    public function testCancelInsideDeadlineIsPolicyViolation(): void
    {
        // نوبت فردا 09:00 → deadline 24h = دیروز 09:00 → الان دیر است
        $this->assertSame(
            BookingWindow::CODE_POLICY,
            BookingWindow::checkCancel('2026-09-06', '09:00', $this->now, 24)
        );
        // نوبت دیروز — کاملاً دیر
        $this->assertSame(
            BookingWindow::CODE_POLICY,
            BookingWindow::checkCancel('2026-09-04', '09:00', $this->now, 24)
        );
    }

    public function testCancelExactlyAtDeadlineIsAllowed(): void
    {
        // deadline = شروع - 24h = الان دقیقاً → > نیست → مجاز
        $this->assertNull(BookingWindow::checkCancel('2026-09-06', '12:00', $this->now, 24));
    }

    // ---------- slotDateTime (strict parse) ----------

    public function testStrictParsingAcceptsValidFormats(): void
    {
        $dt = BookingWindow::slotDateTime('2026-09-06', '9:30');
        $this->assertNotNull($dt);
        $this->assertSame('2026-09-06 09:30:00', $dt->format('Y-m-d H:i:s'));

        $dt2 = BookingWindow::slotDateTime('2026-12-31', '23:59:59');
        $this->assertNotNull($dt2);
        $this->assertSame('2026-12-31 23:59:59', $dt2->format('Y-m-d H:i:s'));
    }

    public function testStrictParsingRejectsRolloverDates(): void
    {
        // 30 فوریه وجود ندارد — createFromFormat باید 2026-03-02 بسازد؛ Round-trip رد می‌کند
        $this->assertNull(BookingWindow::slotDateTime('2026-02-30', '10:00'));
        $this->assertNull(BookingWindow::slotDateTime('2026-04-31', '10:00'));
        $this->assertNull(BookingWindow::slotDateTime('2026-09-06', '99:00'));
        $this->assertNull(BookingWindow::slotDateTime('2026-9-6', '10:00'));
        $this->assertNull(BookingWindow::slotDateTime('2026-09-06 ', '10:00'));
        $this->assertNull(BookingWindow::slotDateTime('2026-09-06', ' 10:00'));
    }
}
