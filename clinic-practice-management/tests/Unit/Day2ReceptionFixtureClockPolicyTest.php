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
 * The regression under test: Journey B reads the reception board, and the real
 * `visits.no_show` job sweeps `confirmed` rows once
 * `slot + queue.no_show_grace_minutes` has passed. The retired fixed
 * 08:20/08:40/09:00 Asia/Tehran rows were already swept for any run after 08:50
 * local, and the reception board (which lists only `pending`/`confirmed`)
 * returned no row for the browser step.
 *
 * The policy is asserted TOTAL here: for every minute of a local day, and for
 * every listed boundary instant, it must return three usable rows — there is no
 * clock at which the fixture gives up. The required day boundaries
 * (00:00:00, 00:00:01, 00:00:03, 00:02:59, 08:50:00, 12:00:00, 23:39:00,
 * 23:42:00, 23:57:00, 23:59:59) are covered explicitly.
 */
final class Day2ReceptionFixtureClockPolicyTest extends TestCase
{
    private const TZ = 'Asia/Tehran';
    private const GRACE_MINUTES = 30;
    private const SLOT_DURATION_MIN = 20;

    #[DataProvider('boundaryClockCases')]
    public function testEveryBoundaryInstantYieldsUsableRows(string $nowText, string $expectedTimes): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $now = new DateTimeImmutable($nowText, $timezone);
        $date = $now->format('Y-m-d');

        $times = Day2ReceptionFixtureClockPolicy::slotTimes($now, $date, self::GRACE_MINUTES);

        self::assertIsArray($times, 'The policy must be total for ' . $nowText);
        self::assertSame($expectedTimes, $this->render($times));
        $this->assertUsableRows($times, $now, $date, self::GRACE_MINUTES);
    }

    /** @return iterable<string, array{string, string}> */
    public static function boundaryClockCases(): iterable
    {
        // Every instant below is a required boundary from the corrective brief.
        yield 'local midnight itself' => ['2026-10-24 00:00:00', '00:00:00,00:00:00,00:00:00'];
        yield 'one second after midnight' => ['2026-10-24 00:00:01', '00:00:00,00:00:00,00:00:00'];
        // In the first minutes of a local day the clamp is the binding
        // constraint: the rows legitimately share the day-start second (they are
        // distinct appointments, and the Clinic A pair have distinct clinicians,
        // so cpms_schedule_slots u_slot stays satisfied).
        yield 'three seconds after midnight' => ['2026-10-24 00:00:03', '00:00:00,00:00:00,00:00:00'];
        yield 'the old day-elapsed floor' => ['2026-10-24 00:02:59', '00:00:00,00:00:00,00:00:00'];
        yield 'first second where the ages are not clamped' => ['2026-10-24 00:05:00', '00:00:00,00:01:00,00:02:00'];
        yield 'the retired 08:20 grace boundary' => ['2026-10-24 08:50:00', '08:45:00,08:46:00,08:47:00'];
        yield 'ordinary midday' => ['2026-10-24 12:00:00', '11:55:00,11:56:00,11:57:00'];
        yield 'the observed passing clock of run 40064349702' => ['2026-10-24 08:40:00', '08:35:00,08:36:00,08:37:00'];
        yield 'the observed failing clock of run 40071858376' => ['2026-10-24 10:52:33', '10:47:33,10:48:33,10:49:33'];
        yield 'before the old runway floor' => ['2026-10-24 23:39:00', '23:34:00,23:35:00,23:36:00'];
        yield 'the old runway boundary' => ['2026-10-24 23:42:00', '23:37:00,23:38:00,23:39:00'];
        yield 'inside the old failure window' => ['2026-10-24 23:57:00', '23:52:00,23:53:00,23:54:00'];
        yield 'the last second of the local day' => ['2026-10-24 23:59:59', '23:54:59,23:55:59,23:56:59'];
    }

    public function testEveryMinuteOfTheDayHasNoUnusableWindow(): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $start = new DateTimeImmutable('2026-10-24 00:00:00', $timezone);

        $covered = 0;
        for ($offset = 0; $offset < 86400; $offset += 60) {
            $now = $start->add(new DateInterval('PT' . $offset . 'S'));
            $date = $now->format('Y-m-d');
            $times = Day2ReceptionFixtureClockPolicy::slotTimes($now, $date, self::GRACE_MINUTES);

            self::assertIsArray($times, 'No wall-clock minute may be unusable: ' . $now->format('Y-m-d H:i:s'));
            $this->assertUsableRows($times, $now, $date, self::GRACE_MINUTES);
            ++$covered;
        }

        self::assertSame(1440, $covered, 'The sweep must cover every minute of the local day');
    }

    public function testMidnightRolloverKeepsTheNewDayUsableAndRejectsTheStaleDate(): void
    {
        $timezone = new DateTimeZone(self::TZ);

        $lastSecond = new DateTimeImmutable('2026-10-24 23:59:59', $timezone);
        $after = new DateTimeImmutable('2026-10-25 00:00:00', $timezone);

        $before = Day2ReceptionFixtureClockPolicy::slotTimes($lastSecond, '2026-10-24', self::GRACE_MINUTES);
        self::assertIsArray($before);
        $this->assertUsableRows($before, $lastSecond, '2026-10-24', self::GRACE_MINUTES);

        $afterTimes = Day2ReceptionFixtureClockPolicy::slotTimes($after, '2026-10-25', self::GRACE_MINUTES);
        self::assertIsArray($afterTimes);
        $this->assertUsableRows($afterTimes, $after, '2026-10-25', self::GRACE_MINUTES);

        // A clock that no longer belongs to the requested operational date is
        // inconsistent input: the harness re-anchors against the server date
        // instead of silently passing (this is the rollover race, not a skip).
        self::assertNull(Day2ReceptionFixtureClockPolicy::slotTimes($after, '2026-10-24', self::GRACE_MINUTES));
        self::assertNull(Day2ReceptionFixtureClockPolicy::slotTimes($lastSecond, '2026-10-25', self::GRACE_MINUTES));
    }

    #[DataProvider('timezoneCases')]
    public function testLocationAuthoritativeTimezonesResolveToExactlyOneInstant(string $timezoneName): void
    {
        $timezone = new DateTimeZone($timezoneName);
        $start = new DateTimeImmutable('2026-10-24 00:00:00', $timezone);

        for ($offset = 0; $offset < 86400; $offset += 600) {
            $now = $start->add(new DateInterval('PT' . $offset . 'S'));
            $date = $now->format('Y-m-d');
            $times = Day2ReceptionFixtureClockPolicy::slotTimes($now, $date, self::GRACE_MINUTES);

            self::assertIsArray($times, $timezoneName . ' must be total at ' . $now->format('Y-m-d H:i:s'));
            $this->assertUsableRows($times, $now, $date, self::GRACE_MINUTES, $timezone);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function timezoneCases(): iterable
    {
        yield 'Asia/Tehran (no DST since 2022)' => ['Asia/Tehran'];
        yield 'UTC' => ['UTC'];
        yield 'Europe/Berlin (DST both ways)' => ['Europe/Berlin'];
        yield 'America/New_York (spring-forward gap)' => ['America/New_York'];
        yield 'Australia/Lord_Howe (30 minute DST)' => ['Australia/Lord_Howe'];
    }

    #[DataProvider('dstCases')]
    public function testDstGapAndFoldNeverProduceAnAmbiguousOrNonexistentWallTime(
        string $timezoneName,
        string $nowText,
        string $expectedDate,
        bool $expectUsable
    ): void {
        $timezone = new DateTimeZone($timezoneName);
        $now = new DateTimeImmutable($nowText, $timezone);

        $times = Day2ReceptionFixtureClockPolicy::slotTimes($now, $expectedDate, self::GRACE_MINUTES);

        if (!$expectUsable) {
            // Second occurrence of a repeated local hour: every unambiguous
            // instant lies outside the grace window, so an "already due but
            // still inside grace" row cannot exist. The policy must fail closed
            // rather than invent an instant (production cannot book such a wall
            // time either — BookingWindow::slotUtcInstant() refuses it).
            self::assertNull($times, 'A fold second hour must fail closed, never guess an occurrence');

            return;
        }

        self::assertIsArray($times, 'A DST transition may not make the policy unusable');
        $this->assertUsableRows($times, $now, $expectedDate, self::GRACE_MINUTES, $timezone);
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function dstCases(): iterable
    {
        // 2026-03-08 02:00 America/New_York springs forward to 03:00: an hour
        // simply does not exist. The rows are placed at real instants.
        yield 'spring forward, hour after the gap' => ['America/New_York', '2026-03-08 03:00:00', '2026-03-08', true];
        yield 'spring forward, just past the gap' => ['America/New_York', '2026-03-08 03:00:30', '2026-03-08', true];
        // 2026-11-01 02:00 America/New_York falls back to 01:00 (repeated hour).
        // First occurrence still has unambiguous earlier instants; the second
        // occurrence cannot satisfy due + inside-grace without ambiguity.
        yield 'fall back, first occurrence of the repeated hour' => ['America/New_York', '2026-11-01 01:30:00', '2026-11-01', false];
        // 2026-03-29 02:00 Europe/Berlin springs forward to 03:00.
        yield 'Berlin spring forward' => ['Europe/Berlin', '2026-03-29 03:00:00', '2026-03-29', true];
        // 2026-10-25 03:00 Europe/Berlin falls back to 02:00 (repeated hour).
        yield 'Berlin fall back' => ['Europe/Berlin', '2026-10-25 02:30:00', '2026-10-25', false];
        // The same instants in the SHIPPED fixture timezone stay usable.
        yield 'Tehran has no DST transition' => ['Asia/Tehran', '2026-11-01 01:30:00', '2026-11-01', true];
        yield 'Tehran during the Berlin transition' => ['Asia/Tehran', '2026-10-25 02:30:00', '2026-10-25', true];
    }

    public function testAnyUsableDstResultUsesOnlyUnambiguousInstants(): void
    {
        foreach (self::timezoneCases() as [$timezoneName]) {
            $timezone = new DateTimeZone($timezoneName);
            $start = new DateTimeImmutable('2026-10-24 00:00:00', $timezone);
            for ($offset = 0; $offset < 86400; $offset += 3600) {
                $now = $start->add(new DateInterval('PT' . $offset . 'S'));
                $times = Day2ReceptionFixtureClockPolicy::slotTimes($now, $now->format('Y-m-d'), self::GRACE_MINUTES);
                if ($times === null) {
                    continue; // only a repeated local hour may be unusable
                }
                foreach ($times as $key => $slot) {
                    self::assertNotNull(
                        BookingWindow::slotUtcInstant($slot->format('Y-m-d'), $slot->format('H:i:s'), $timezone),
                        $timezoneName . ' ' . $key . ' must never be an ambiguous/non-existent wall time'
                    );
                }
            }
        }
    }

    #[DataProvider('graceCases')]
    public function testGraceWindowIsAlwaysRespected(int $graceMinutes, int $expectedRemainder): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $now = new DateTimeImmutable('2026-10-24 15:00:00', $timezone);

        $times = Day2ReceptionFixtureClockPolicy::slotTimes($now, '2026-10-24', $graceMinutes);
        self::assertIsArray($times, 'grace=' . $graceMinutes . ' must remain usable');

        self::assertSame(
            $expectedRemainder,
            Day2ReceptionFixtureClockPolicy::graceRemainderSeconds($graceMinutes * 60)
        );
        $this->assertUsableRows($times, $now, '2026-10-24', $graceMinutes, null, $expectedRemainder);
    }

    /** @return iterable<string, array{int, int}> */
    public static function graceCases(): iterable
    {
        yield 'shipped default 30 minutes' => [30, 300];
        yield 'wide 60 minutes' => [60, 300];
        yield 'narrow 10 minutes' => [10, 300];
        yield 'very narrow 2 minutes' => [2, 60];
        yield 'minimum usable 1 minute' => [1, 30];
    }

    public function testOnlyGenuinelyUnusableConfigurationFailsClosed(): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $now = new DateTimeImmutable('2026-10-24 15:00:00', $timezone);

        // Grace shorter than the documented survivable floor: a configuration
        // defect, not a wall-clock window.
        self::assertNull(Day2ReceptionFixtureClockPolicy::slotTimes($now, '2026-10-24', 0));
        self::assertNull(Day2ReceptionFixtureClockPolicy::slotTimes($now, '2026-10-24', -5));

        // Malformed operational dates never silently pass.
        self::assertNull(Day2ReceptionFixtureClockPolicy::slotTimes($now, '2026-13-01', 30));
        self::assertNull(Day2ReceptionFixtureClockPolicy::slotTimes($now, 'not-a-date', 30));
    }

    public function testRetiredFixedMorningRowsAreAlreadyPastGraceAtTheObservedFailingClock(): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $now = new DateTimeImmutable('2026-10-24 10:52:33', $timezone); // run 40071858376
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));
        $today = $now->format('Y-m-d');

        foreach (['08:20:00', '08:40:00', '09:00:00'] as $retired) {
            $instant = BookingWindow::slotUtcInstant($today, $retired, $timezone);
            self::assertNotNull($instant);
            self::assertLessThan(
                $nowUtc->getTimestamp(),
                $instant->add(new DateInterval('PT' . (self::GRACE_MINUTES * 60) . 'S'))->getTimestamp(),
                'The retired fixed row ' . $retired . ' is already past slot + grace at the observed '
                . 'failing clock, so the real visits.no_show job legitimately sweeps it before Journey B'
            );
        }

        $times = Day2ReceptionFixtureClockPolicy::slotTimes($now, $today, self::GRACE_MINUTES);
        self::assertIsArray($times);
        $this->assertUsableRows($times, $now, $today, self::GRACE_MINUTES);
    }

    /**
     * The anchor's success criterion is the persisted row, never the affected
     * row count: `CpmsDb::execute()` is soft and a legitimately idempotent
     * UPDATE reports 0 rows.
     */
    public function testReadbackAcceptsAnIdempotentUpdateThatChangedNothing(): void
    {
        $expected = [
            'id' => 40,
            'clinic_id' => 16,
            'location_id' => 17,
            'slot_date' => '2026-10-24',
            'slot_time' => '12:25:53',
            'slot_end_time' => '12:45:53',
        ];
        $stored = $expected + ['status' => 'confirmed'];

        self::assertNull(
            Day2ReceptionFixtureClockPolicy::readbackViolation($expected, $stored),
            'A 0-row idempotent UPDATE whose persisted row is already correct is a success'
        );
    }

    #[DataProvider('readbackViolationCases')]
    public function testReadbackRejectsEveryMismatch(?array $stored, string $expectedCode): void
    {
        $expected = [
            'id' => 40,
            'clinic_id' => 16,
            'location_id' => 17,
            'slot_date' => '2026-10-24',
            'slot_time' => '12:25:53',
            'slot_end_time' => '12:45:53',
        ];

        self::assertSame($expectedCode, Day2ReceptionFixtureClockPolicy::readbackViolation($expected, $stored));
    }

    /** @return iterable<string, array{array<string, mixed>|null, string}> */
    public static function readbackViolationCases(): iterable
    {
        $ok = [
            'id' => 40,
            'clinic_id' => 16,
            'location_id' => 17,
            'slot_date' => '2026-10-24',
            'slot_time' => '12:25:53',
            'slot_end_time' => '12:45:53',
            'status' => 'confirmed',
        ];

        yield 'row vanished' => [null, 'APPOINTMENT_MISSING_AFTER_WRITE'];
        yield 'different appointment id' => [array_merge($ok, ['id' => 41]), 'READBACK_ID_MISMATCH'];
        yield 'id missing' => [array_diff_key($ok, ['id' => true]), 'READBACK_ID_MISMATCH'];
        yield 'foreign clinic' => [array_merge($ok, ['clinic_id' => 17]), 'READBACK_SCOPE_MISMATCH'];
        yield 'foreign location' => [array_merge($ok, ['location_id' => 18]), 'READBACK_SCOPE_MISMATCH'];
        yield 'swept to no_show' => [array_merge($ok, ['status' => 'no_show']), 'READBACK_STATUS_MISMATCH'];
        yield 'cancelled' => [array_merge($ok, ['status' => 'cancelled']), 'READBACK_STATUS_MISMATCH'];
        yield 'status missing' => [array_diff_key($ok, ['status' => true]), 'READBACK_STATUS_MISMATCH'];
        yield 'stale slot date' => [array_merge($ok, ['slot_date' => '2026-10-23']), 'READBACK_SLOT_MISMATCH'];
        yield 'stale slot time' => [array_merge($ok, ['slot_time' => '12:20:53']), 'READBACK_SLOT_MISMATCH'];
        yield 'stale end time' => [array_merge($ok, ['slot_end_time' => '12:40:53']), 'READBACK_END_MISMATCH'];
    }

    /**
     * @param array{doc1: DateTimeImmutable, doc2: DateTimeImmutable, foreign: DateTimeImmutable} $times
     */
    private function render(array $times): string
    {
        return implode(',', array_map(
            static fn (DateTimeImmutable $at): string => $at->format('H:i:s'),
            $times
        ));
    }

    /**
     * @param array{doc1: DateTimeImmutable, doc2: DateTimeImmutable, foreign: DateTimeImmutable} $times
     */
    private function assertUsableRows(
        array $times,
        DateTimeImmutable $now,
        string $operationalDate,
        int $graceMinutes,
        ?DateTimeZone $timezone = null,
        ?int $expectedRemainder = null
    ): void {
        $timezone ??= $now->getTimezone();
        $remainingFloor = $expectedRemainder
            ?? Day2ReceptionFixtureClockPolicy::graceRemainderSeconds($graceMinutes * 60);

        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));
        $dayStart = new DateTimeImmutable($operationalDate . ' 00:00:00', $timezone);
        $dayEnd = $dayStart->add(new DateInterval('P1D'));

        foreach ($times as $key => $slot) {
            self::assertSame($operationalDate, $slot->format('Y-m-d'), $key . ' must stay on the operational date');
            self::assertGreaterThanOrEqual(
                $dayStart->getTimestamp(),
                $slot->getTimestamp(),
                $key . ' must never borrow the previous local day'
            );
            self::assertLessThan($dayEnd->getTimestamp(), $slot->getTimestamp(), $key . ' must stay inside the local day');

            $instant = BookingWindow::slotUtcInstant($slot->format('Y-m-d'), $slot->format('H:i:s'), $timezone);
            self::assertNotNull($instant, $key . ' must map to exactly one instant (no DST gap/fold)');
            self::assertLessThanOrEqual(
                $nowUtc->getTimestamp(),
                $instant->getTimestamp(),
                $key . ' must already be due — a no-show is never a future cancellation'
            );

            $remaining = $instant->add(new DateInterval('PT' . ($graceMinutes * 60) . 'S'))->getTimestamp()
                - $nowUtc->getTimestamp();
            self::assertGreaterThan(
                0,
                $remaining,
                $key . ' must still be inside queue.no_show_grace_minutes when the board is read'
            );
            self::assertGreaterThanOrEqual(
                $remainingFloor,
                $remaining,
                $key . ' must keep the documented grace remainder'
            );

            $end = DurationResolver::slotEndTime($slot->format('H:i:s'), self::SLOT_DURATION_MIN);
            self::assertMatchesRegularExpression('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $end, $key . ' slot_end_time must be a valid TIME');
            $startSeconds = ((int) substr($slot->format('H:i:s'), 0, 2)) * 3600
                + ((int) substr($slot->format('H:i:s'), 3, 2)) * 60
                + (int) substr($slot->format('H:i:s'), 6, 2);
            $expectedEnd = ($startSeconds + self::SLOT_DURATION_MIN * 60) % 86400;
            $endSeconds = ((int) substr($end, 0, 2)) * 3600 + ((int) substr($end, 3, 2)) * 60 + (int) substr($end, 6, 2);
            self::assertSame($expectedEnd, $endSeconds, $key . ' slot_end_time must match the duration snapshot');
            if (DurationResolver::crossesMidnight($slot->format('H:i:s'), self::SLOT_DURATION_MIN)) {
                // Documented convention: slot_end_time < slot_time means +1 day.
                self::assertLessThan($slot->format('H:i:s'), $end, $key . ' a crossing end must be encoded as < slot_time');
            }
        }
    }
}
