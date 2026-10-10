<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Booking\BookingWindow;
use ClinicCore\Domain\Time\Jalali;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * BookingWindow (SRS FR-4.9/FR-4.10) — قوانین رزرو/لغو، خالص و دترمینیستیک (UTC).
 */
final class BookingWindowTest extends TestCase
{
    /** A real DST-observing IANA Location timezone, deliberately not Tehran. */
    private const DST_LOCATION_TIMEZONE = 'America/New_York';

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

    public function testLocationToUtcRejectsSpringForwardNonexistentWallClock(): void
    {
        $timezone = $this->dstLocationTimezone();

        // America/New_York advances from 01:59:59 EST to 03:00:00 EDT on 2026-03-08.
        // A persisted DATE+TIME slot cannot represent the missing 02:30 wall clock.
        $this->assertNull(BookingWindow::slotUtcInstant('2026-03-08', '02:30:00', $timezone));
    }

    public function testLocationToUtcAndUtcToLocationStayCorrectAcrossSpringForward(): void
    {
        $timezone = $this->dstLocationTimezone();
        $utc = new DateTimeZone('UTC');

        $before = BookingWindow::slotUtcInstant('2026-03-08', '01:59:00', $timezone);
        $after = BookingWindow::slotUtcInstant('2026-03-08', '03:00:00', $timezone);

        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertSame('2026-03-08T06:59:00Z', $before->format('Y-m-d\\TH:i:s\\Z'));
        $this->assertSame('2026-03-08T07:00:00Z', $after->format('Y-m-d\\TH:i:s\\Z'));
        $this->assertSame('2026-03-08 01:59:00 EST -05:00', $before->setTimezone($timezone)->format('Y-m-d H:i:s T P'));
        $this->assertSame('2026-03-08 03:00:00 EDT -04:00', $after->setTimezone($timezone)->format('Y-m-d H:i:s T P'));
        $this->assertSame('UTC', $before->setTimezone($utc)->getTimezone()->getName());
    }

    public function testUtcToLocationDistinguishesBothFallBackInstants(): void
    {
        $timezone = $this->dstLocationTimezone();
        $utc = new DateTimeZone('UTC');
        $first = new DateTimeImmutable('2026-11-01 05:30:00', $utc);
        $second = new DateTimeImmutable('2026-11-01 06:30:00', $utc);

        // 01:30 occurs once in EDT and once in EST; UTC remains the unique instant.
        $this->assertSame('2026-11-01 01:30:00 EDT -04:00', $first->setTimezone($timezone)->format('Y-m-d H:i:s T P'));
        $this->assertSame('2026-11-01 01:30:00 EST -05:00', $second->setTimezone($timezone)->format('Y-m-d H:i:s T P'));
    }

    public function testLocationToUtcDoesNotSilentlyChooseAFallBackOccurrence(): void
    {
        $timezone = $this->dstLocationTimezone();

        // DATE+TIME has no offset/fold field, so this repeated local value has no
        // unique appointment instant. It must not inherit PHP's arbitrary choice.
        $this->assertNull(BookingWindow::slotUtcInstant('2026-11-01', '01:30:00', $timezone));
        $this->assertSame(
            BookingWindow::CODE_INVALID,
            BookingWindow::checkRequestWithTimezone(
                '2026-11-01',
                '01:30:00',
                $timezone,
                new DateTimeImmutable('2026-10-01 00:00:00', new DateTimeZone('UTC')),
                0,
                60
            )
        );

        $before = BookingWindow::slotUtcInstant('2026-11-01', '00:59:00', $timezone);
        $after = BookingWindow::slotUtcInstant('2026-11-01', '02:00:00', $timezone);
        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertSame('2026-11-01T04:59:00Z', $before->format('Y-m-d\\TH:i:s\\Z'));
        $this->assertSame('2026-11-01T07:00:00Z', $after->format('Y-m-d\\TH:i:s\\Z'));
    }

    public function testDistinctLocationsAndAmbientTimezoneDoNotChangeOrdinaryConversion(): void
    {
        $newYork = $this->dstLocationTimezone();
        $tokyo = new DateTimeZone('Asia/Tokyo');
        $originalAmbient = date_default_timezone_get();

        try {
            date_default_timezone_set('Pacific/Kiritimati');
            $newYorkUtc = BookingWindow::slotUtcInstant('2026-01-15', '09:00:00', $newYork);
            $tokyoUtc = BookingWindow::slotUtcInstant('2026-01-15', '09:00:00', $tokyo);

            date_default_timezone_set('Asia/Tehran');
            $newYorkUnderDifferentAmbient = BookingWindow::slotUtcInstant('2026-01-15', '09:00:00', $newYork);
            $tokyoUnderDifferentAmbient = BookingWindow::slotUtcInstant('2026-01-15', '09:00:00', $tokyo);
        } finally {
            date_default_timezone_set($originalAmbient);
        }

        $this->assertNotNull($newYorkUtc);
        $this->assertNotNull($tokyoUtc);
        $this->assertNotNull($newYorkUnderDifferentAmbient);
        $this->assertNotNull($tokyoUnderDifferentAmbient);
        $this->assertSame('2026-01-15T14:00:00Z', $newYorkUtc->format('Y-m-d\\TH:i:s\\Z'));
        $this->assertSame('2026-01-15T00:00:00Z', $tokyoUtc->format('Y-m-d\\TH:i:s\\Z'));
        $this->assertNotSame($newYorkUtc->getTimestamp(), $tokyoUtc->getTimestamp());
        $this->assertSame($newYorkUtc->getTimestamp(), $newYorkUnderDifferentAmbient->getTimestamp());
        $this->assertSame($tokyoUtc->getTimestamp(), $tokyoUnderDifferentAmbient->getTimestamp());
    }

    public function testJalaliPresentationDoesNotMutateLocationInstantOrAuthority(): void
    {
        $timezone = $this->dstLocationTimezone();
        $instant = new DateTimeImmutable('2026-03-08 07:00:00', new DateTimeZone('UTC'));
        $local = $instant->setTimezone($timezone);

        $this->assertSame('1404/12/18', Jalali::formatYmd($local->format('Y-m-d')));
        $this->assertSame('America/New_York', $local->getTimezone()->getName());
        $this->assertSame('2026-03-08T07:00:00Z', $instant->format('Y-m-d\\TH:i:s\\Z'));
    }

    private function dstLocationTimezone(): DateTimeZone
    {
        $this->assertContains(self::DST_LOCATION_TIMEZONE, timezone_identifiers_list());

        return new DateTimeZone(self::DST_LOCATION_TIMEZONE);
    }
}
