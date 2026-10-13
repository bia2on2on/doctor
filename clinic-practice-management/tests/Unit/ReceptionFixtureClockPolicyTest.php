<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Booking\BookingWindow;
use ClinicCore\Tests\Support\ReceptionFixtureClockPolicy;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceptionFixtureClockPolicyTest extends TestCase
{
    #[DataProvider('bookableSameDayCases')]
    public function testChoosesOnlyAValidSameDayDestination(string $nowText, string $expectedText): void
    {
        $timezone = new DateTimeZone('Asia/Tehran');
        $now = new DateTimeImmutable($nowText, $timezone);
        $destination = ReceptionFixtureClockPolicy::upcomingSameDayDestination($now);

        self::assertInstanceOf(DateTimeImmutable::class, $destination);
        self::assertSame($expectedText, $destination->format('Y-m-d H:i:s'));
        self::assertSame($now->format('Y-m-d'), $destination->format('Y-m-d'), 'The TODAY proof must not roll into tomorrow');
        self::assertGreaterThan(
            $now->getTimestamp() + ReceptionFixtureClockPolicy::MINIMUM_RUNWAY_SECONDS,
            $destination->getTimestamp(),
            'A destination must have strictly more than the required browser runway'
        );
        self::assertNotNull(
            BookingWindow::slotUtcInstant($destination->format('Y-m-d'), $destination->format('H:i:s'), $timezone),
            'The persisted local wall time must map to exactly one real instant'
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function bookableSameDayCases(): iterable
    {
        yield 'ordinary daytime' => ['2026-10-13 10:00:00', '2026-10-13 11:30:00'];
        yield 'late evening before fallback' => ['2026-10-13 22:45:00', '2026-10-13 23:59:00'];
        yield 'approximately 23:55' => ['2026-10-13 23:55:00', '2026-10-13 23:59:00'];
        yield 'last second with more than three minutes runway' => ['2026-10-13 23:55:59', '2026-10-13 23:59:00'];
        yield 'midnight begins the new local day' => ['2026-10-14 00:00:00', '2026-10-14 01:30:00'];
    }

    #[DataProvider('noSameDayRunwayCases')]
    public function testFailsClosedWhenNoSameDayDestinationHasEnoughRunway(string $nowText): void
    {
        $now = new DateTimeImmutable($nowText, new DateTimeZone('Asia/Tehran'));

        self::assertNull(
            ReceptionFixtureClockPolicy::upcomingSameDayDestination($now),
            'The fixture must not claim success by selecting a past slot or silently changing the TODAY date'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function noSameDayRunwayCases(): iterable
    {
        yield 'approximately 23:56 exact runway boundary' => ['2026-10-13 23:56:00'];
        yield 'approximately 23:57 insufficient runway' => ['2026-10-13 23:57:00'];
        yield 'approximately 23:59 destination already reached' => ['2026-10-13 23:59:00'];
    }

    #[DataProvider('sameDaySlotTimeCases')]
    public function testSeedsSameDayRowsThatLeaveTheDestinationMinuteFree(string $nowText, string $expectedPins): void
    {
        $now = new DateTimeImmutable($nowText, new DateTimeZone('Asia/Tehran'));
        $pins = ReceptionFixtureClockPolicy::sameDaySlotTimes($now);

        self::assertSame($expectedPins, implode(',', array_map(
            static fn (DateTimeImmutable $at): string => $at->format('H:i:s'),
            $pins
        )));
        foreach ($pins as $at) {
            self::assertSame(
                $now->format('Y-m-d'),
                $at->format('Y-m-d'),
                'A seeded same-day row must not cross the Tehran operational day'
            );
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function sameDaySlotTimeCases(): iterable
    {
        yield 'ordinary rows keep their planned offsets' => ['2026-10-13 10:00:00', '10:10:00,10:30:00,10:50:00,11:10:00'];
        yield 'the observed failing clock' => ['2026-10-13 22:53:39', '23:55:00,23:56:00,23:57:00,23:58:00'];
        yield 'the observed passing clock' => ['2026-10-13 21:56:47', '22:06:47,22:26:47,22:46:47,23:06:47'];
        yield 'the preferred lead starts the late-day rows' => ['2026-10-13 22:30:00', '23:55:00,23:56:00,23:57:00,23:58:00'];
        yield 'one second before the preferred lead stays inside today' => ['2026-10-13 22:29:59', '22:39:59,22:59:59,23:19:59,23:39:59'];
    }

    public function testSeededSameDayRowsNeverDuplicateTheSameDayDestinationSlot(): void
    {
        $timezone = new DateTimeZone('Asia/Tehran');
        $start = new DateTimeImmutable('2026-10-13 00:00:00', $timezone);
        $covered = 0;

        for ($offset = 0; $offset < 86400; $offset += 60) {
            $now = $start->add(new DateInterval('PT' . $offset . 'S'));
            $destination = ReceptionFixtureClockPolicy::upcomingSameDayDestination($now);
            if ($destination === null) {
                continue;
            }

            $pins = array_map(
                static fn (DateTimeImmutable $at): string => $at->format('H:i:s'),
                ReceptionFixtureClockPolicy::sameDaySlotTimes($now)
            );

            self::assertNotContains(
                $destination->format('H:i:s'),
                $pins,
                'A seeded row and the same-day destination must not collide on cpms_schedule_slots '
                . 'u_slot (clinician_id, slot_date, slot_time) at ' . $now->format('Y-m-d H:i:s')
            );
            ++$covered;
        }

        self::assertGreaterThan(1400, $covered, 'The sweep must cover the bookable part of the day');
    }

    public function testRejectsAnAmbiguousLocationWallTimeInsteadOfChoosingAnOccurrence(): void
    {
        $timezone = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2026-11-01 00:30:00', $timezone);
        $candidate = $now->add(new DateInterval('PT90M'));

        self::assertSame('2026-11-01 01:00:00', $candidate->format('Y-m-d H:i:s'));
        self::assertNull(BookingWindow::slotUtcInstant('2026-11-01', '01:00:00', $timezone));
        self::assertNull(ReceptionFixtureClockPolicy::upcomingSameDayDestination($now));
    }
}
