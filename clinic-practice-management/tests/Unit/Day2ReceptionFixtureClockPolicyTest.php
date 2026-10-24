<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Booking\BookingWindow;
use ClinicCore\Domain\Slots\DurationResolver;
use ClinicCore\Tests\Support\Day2ReceptionFixtureClockPolicy;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Clock-safety proof for the Day-2 reception acceptance rows.
 *
 * The regression under test: Journey B seeds TODAY's reception rows, and the
 * real `visits.no_show` job sweeps `confirmed` rows once
 * `slot + queue.no_show_grace_minutes` has passed. The retired fixed
 * 08:20/08:40/09:00 Asia/Tehran rows were therefore already swept for any run
 * after 08:50 local, and the reception board (which lists only
 * `pending`/`confirmed`) returned no row for the browser step.
 */
final class Day2ReceptionFixtureClockPolicyTest extends TestCase
{
    private const TZ = 'Asia/Tehran';
    private const SLOT_DURATION_MIN = 20;
    private const GRACE_MINUTES = 30;

    #[DataProvider('hostedClockCases')]
    public function testChoosesAlreadyDueRowsThatStayInsideTheGraceWindow(
        string $nowText,
        int $graceMinutes,
        string $expectedTimes
    ): void {
        $timezone = new DateTimeZone(self::TZ);
        $now = new DateTimeImmutable($nowText, $timezone);

        $times = Day2ReceptionFixtureClockPolicy::alreadyDueSameDaySlotTimes($now, $graceMinutes);

        self::assertIsArray($times);
        self::assertSame($expectedTimes, implode(',', array_map(
            static fn (DateTimeImmutable $at): string => $at->format('H:i:s'),
            $times
        )));
        self::assertSame(
            3,
            count(array_unique(array_map(
                static fn (DateTimeImmutable $at): string => $at->format('H:i:s'),
                $times
            ))),
            'The three reception rows must stay distinct so the clinician filter proof is unambiguous'
        );

        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));
        $graceSeconds = $graceMinutes * 60;

        foreach ($times as $key => $at) {
            self::assertSame($now->format('Y-m-d'), $at->format('Y-m-d'), $key . ' must stay on the operational day the board resolves');
            self::assertLessThan($now->getTimestamp(), $at->getTimestamp(), $key . ' must already be due — a no-show is not a future cancellation');

            $instant = BookingWindow::slotUtcInstant($at->format('Y-m-d'), $at->format('H:i:s'), $timezone);
            self::assertNotNull($instant, $key . ' must map to exactly one real instant');

            $eligible = $instant->add(new DateInterval('PT' . $graceSeconds . 'S'));
            self::assertLessThan(
                $eligible->getTimestamp(),
                $nowUtc->getTimestamp(),
                $key . ' must still be inside queue.no_show_grace_minutes when it is seeded'
            );
            self::assertGreaterThanOrEqual(
                Day2ReceptionFixtureClockPolicy::MINIMUM_GRACE_REMAINDER_SECONDS,
                $eligible->getTimestamp() - $nowUtc->getTimestamp(),
                $key . ' must keep a documented grace remainder for the browser step'
            );
            self::assertFalse(
                DurationResolver::crossesMidnight($at->format('H:i:s'), self::SLOT_DURATION_MIN),
                $key . ' slot_end_time must stay inside the same operational day'
            );
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function hostedClockCases(): iterable
    {
        yield 'before the retired fixed morning rows' => ['2026-10-24 07:10:00', self::GRACE_MINUTES, '07:05:00,07:06:00,07:07:00'];
        yield 'the observed passing clock of run 40064349702' => ['2026-10-24 08:40:00', self::GRACE_MINUTES, '08:35:00,08:36:00,08:37:00'];
        yield 'one second after the retired first-row grace boundary' => ['2026-10-24 08:50:01', self::GRACE_MINUTES, '08:45:01,08:46:01,08:47:01'];
        yield 'the observed failing clock of run 40071858376' => ['2026-10-24 10:52:33', self::GRACE_MINUTES, '10:47:33,10:48:33,10:49:33'];
        yield 'the first second that satisfies the day-elapsed floor' => ['2026-10-24 00:03:00', self::GRACE_MINUTES, '00:00:00,00:00:01,00:00:02'];
        yield 'morning after a local date rollover' => ['2026-10-24 00:10:00', self::GRACE_MINUTES, '00:05:00,00:06:00,00:07:00'];
        yield 'exact runway boundary before local midnight' => ['2026-10-24 23:42:00', self::GRACE_MINUTES, '23:37:00,23:38:00,23:39:00'];
        yield 'a minimum grace of ten minutes' => ['2026-10-24 14:00:00', 10, '13:55:00,13:56:00,13:57:00'];
        yield 'a long sixty-minute grace' => ['2026-10-24 14:00:00', 60, '13:55:00,13:56:00,13:57:00'];
    }

    #[DataProvider('failClosedClockCases')]
    public function testFailsClosedWhenTodayCannotHostTheProof(string $nowText, int $graceMinutes): void
    {
        $now = new DateTimeImmutable($nowText, new DateTimeZone(self::TZ));

        self::assertNull(
            Day2ReceptionFixtureClockPolicy::alreadyDueSameDaySlotTimes($now, $graceMinutes),
            'The fixture must not borrow yesterday, seed future rows, shorten the grace window or disable the job to pass'
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function failClosedClockCases(): iterable
    {
        yield 'local midnight itself' => ['2026-10-24 00:00:00', self::GRACE_MINUTES];
        yield 'one second before the day-elapsed floor' => ['2026-10-24 00:02:59', self::GRACE_MINUTES];
        yield 'one second past the runway boundary' => ['2026-10-24 23:42:01', self::GRACE_MINUTES];
        yield 'late evening below the runway floor' => ['2026-10-24 23:50:00', self::GRACE_MINUTES];
        yield 'the last second of the local day' => ['2026-10-24 23:59:59', self::GRACE_MINUTES];
        yield 'grace one minute below the documented floor' => ['2026-10-24 14:00:00', Day2ReceptionFixtureClockPolicy::MINIMUM_GRACE_MINUTES - 1];
        yield 'grace disabled entirely' => ['2026-10-24 14:00:00', 0];
    }

    public function testFullLocalDaySweepKeepsEverySeededRowDueAndInsideGrace(): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $start = new DateTimeImmutable('2026-10-24 00:00:00', $timezone);
        $covered = 0;

        for ($offset = 0; $offset < 86400; $offset += 60) {
            $now = $start->add(new DateInterval('PT' . $offset . 'S'));
            $times = Day2ReceptionFixtureClockPolicy::alreadyDueSameDaySlotTimes($now, self::GRACE_MINUTES);

            if ($times === null) {
                continue;
            }

            $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));
            $runway = $start->add(new DateInterval('P1D'))->getTimestamp() - $now->getTimestamp();

            self::assertGreaterThanOrEqual(
                Day2ReceptionFixtureClockPolicy::MINIMUM_RUNWAY_SECONDS,
                $runway,
                'A hosted clock keeps the documented runway before local midnight at ' . $now->format('Y-m-d H:i:s')
            );

            foreach ($times as $key => $at) {
                self::assertSame($now->format('Y-m-d'), $at->format('Y-m-d'), $key . ' at ' . $now->format('H:i:s'));
                self::assertLessThan($now->getTimestamp(), $at->getTimestamp(), $key . ' must be already due at ' . $now->format('H:i:s'));

                $instant = BookingWindow::slotUtcInstant($at->format('Y-m-d'), $at->format('H:i:s'), $timezone);
                self::assertNotNull($instant, $key . ' at ' . $now->format('H:i:s'));

                $remaining = $instant->add(new DateInterval('PT' . (self::GRACE_MINUTES * 60) . 'S'))->getTimestamp()
                    - $nowUtc->getTimestamp();
                self::assertGreaterThanOrEqual(
                    Day2ReceptionFixtureClockPolicy::MINIMUM_GRACE_REMAINDER_SECONDS,
                    $remaining,
                    $key . ' keeps the documented grace remainder at ' . $now->format('H:i:s')
                );
            }

            ++$covered;
        }

        self::assertGreaterThan(
            1380,
            $covered,
            'The sweep must host the proof for almost the whole local day (only the first 3 and the last 18 minutes fail closed)'
        );
    }

    public function testRetiredFixedMorningRowsAreAlreadyPastGraceAtTheObservedFailingClock(): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $now = new DateTimeImmutable('2026-10-24 10:52:33', $timezone); // run 40071858376, Day-2 browser step
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));
        $today = $now->format('Y-m-d');

        $retired = ['08:20:00', '08:40:00', '09:00:00'];
        foreach ($retired as $time) {
            $instant = BookingWindow::slotUtcInstant($today, $time, $timezone);
            self::assertNotNull($instant);
            $eligible = $instant->add(new DateInterval('PT' . (self::GRACE_MINUTES * 60) . 'S'));

            self::assertLessThan(
                $nowUtc->getTimestamp(),
                $eligible->getTimestamp(),
                'The retired fixed row ' . $time . ' is already past slot + grace at the observed failing clock, '
                . 'so the real visits.no_show job legitimately sweeps it before Journey B'
            );
        }

        $times = Day2ReceptionFixtureClockPolicy::alreadyDueSameDaySlotTimes($now, self::GRACE_MINUTES);
        self::assertIsArray($times);
        foreach ($times as $at) {
            $instant = BookingWindow::slotUtcInstant($today, $at->format('H:i:s'), $timezone);
            self::assertNotNull($instant);
            self::assertGreaterThan(
                $nowUtc->getTimestamp(),
                $instant->add(new DateInterval('PT' . (self::GRACE_MINUTES * 60) . 'S'))->getTimestamp(),
                'The corrected row must still be inside the grace window at the same clock'
            );
        }
    }
}
