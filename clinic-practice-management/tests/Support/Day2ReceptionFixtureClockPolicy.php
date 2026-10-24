<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

use ClinicCore\Domain\Booking\BookingWindow;
use DateInterval;
use DateTimeImmutable;

/**
 * Pure clock policy for the Day-2 reception acceptance fixture's ALREADY-DUE rows.
 *
 * Journey B proves a real receptionist no-show on TODAY's reception board. The
 * three reception rows therefore have to be BOTH:
 *   - already due (the appointment time has passed — a no-show is a real
 *     reception-desk judgement, not a future cancellation), and
 *   - still inside `queue.no_show_grace_minutes`, so the real `visits.no_show`
 *     background job has NOT already flipped them to `no_show`.
 *
 * A fixed wall time cannot satisfy that pair for every clock. The retired
 * `08:20:00` / `08:40:00` / `09:00:00` Asia/Tehran rows were past
 * slot + grace for any run after 08:50 local (08:30 for the second row), so
 * `VisitService::processNoShows()` legitimately marked them `no_show` and
 * `AppointmentRepository::list_for_reception_operational_day()`
 * (`status IN ('pending','confirmed')`) dropped them before the browser
 * journey started. That is a fixture defect, not a reschedule and not a flake.
 *
 * The caller supplies the Location-local clock explicitly, so every
 * day-boundary decision is testable without changing the product clock, the
 * timezone policy, or the background job. This mirrors
 * `ReceptionFixtureClockPolicy`.
 */
final class Day2ReceptionFixtureClockPolicy
{
    /**
     * Remaining grace guaranteed at seed time. The grace window is what keeps
     * the rows `confirmed` while the real background job sweeps; five minutes
     * is ~7x the observed 24–40 s Day-2 browser step but still a normal
     * reception-desk interval.
     */
    public const MINIMUM_GRACE_REMAINDER_SECONDS = 300;

    /**
     * Local-day runway required after seeding.
     *
     * The board date is resolved server-side from the Location-local calendar
     * day, so the browser step must finish before local midnight. 18 minutes is
     * also > 20 min slot duration − 3 min oldest age, which keeps every seeded
     * `slot_end_time` inside the same operational day (no midnight wrap).
     */
    public const MINIMUM_RUNWAY_SECONDS = 1080;

    /** Age of the three already-due rows (distinct, seconds before `now`). */
    public const PRIMARY_AGE_SECONDS = 300;
    public const SECONDARY_AGE_SECONDS = 240;
    public const FOREIGN_AGE_SECONDS = 180;

    /**
     * A local day younger than this cannot host three distinct already-due rows
     * without borrowing yesterday's date, which the board would not show.
     */
    public const MINIMUM_DAY_ELAPSED_SECONDS = 180;

    /** Below this grace the proof cannot be made without weakening the rule. */
    public const MINIMUM_GRACE_MINUTES = 10;

    /**
     * Already-due, still-inside-grace slot times on the SAME local day, or null
     * when today cannot honestly satisfy the Journey B proof.
     *
     * Null is a fail-closed result: the fixture must never silently borrow
     * yesterday's date, push the rows into the future, disable the background
     * job or shorten the grace window to make the browser step pass.
     *
     * @param int $graceMinutes the Location's effective `queue.no_show_grace_minutes`
     * @return array{doc1: DateTimeImmutable, doc2: DateTimeImmutable, foreign: DateTimeImmutable}|null
     */
    public static function alreadyDueSameDaySlotTimes(DateTimeImmutable $nowLocal, int $graceMinutes): ?array
    {
        if ($graceMinutes < self::MINIMUM_GRACE_MINUTES) {
            return null;
        }

        $timezone = $nowLocal->getTimezone();
        $today    = $nowLocal->format('Y-m-d');

        // Second precision only: the persisted slot is H:i:s, and the
        // eligibility comparison below must not be moved by sub-second drift.
        $now = $nowLocal->setTime(
            (int) $nowLocal->format('H'),
            (int) $nowLocal->format('i'),
            (int) $nowLocal->format('s')
        );

        $dayStart = $now->setTime(0, 0, 0);
        $elapsed  = $now->getTimestamp() - $dayStart->getTimestamp();
        $runway   = $dayStart->add(new DateInterval('P1D'))->getTimestamp() - $now->getTimestamp();

        if ($elapsed < self::MINIMUM_DAY_ELAPSED_SECONDS || $runway < self::MINIMUM_RUNWAY_SECONDS) {
            return null;
        }

        $maximumAge = $graceMinutes * 60 - self::MINIMUM_GRACE_REMAINDER_SECONDS;
        if ($maximumAge <= 0) {
            return null;
        }

        $preferredAges = [
            'doc1' => self::PRIMARY_AGE_SECONDS,
            'doc2' => self::SECONDARY_AGE_SECONDS,
            'foreign' => self::FOREIGN_AGE_SECONDS,
        ];

        $times = [];
        $index = 0;
        foreach ($preferredAges as $key => $preferredAge) {
            // Stay inside the grace window (maximumAge) and inside today
            // (elapsed). The per-index term keeps the three rows distinct even
            // in the first minutes of a local day, where the day itself is the
            // binding constraint.
            $age = min($preferredAge, $maximumAge, $elapsed - $index);
            if ($age <= 0) {
                return null;
            }

            $slot = $now->sub(new DateInterval('PT' . $age . 'S'));

            if ($slot->format('Y-m-d') !== $today) {
                return null;
            }

            // A DATE+TIME slot has no offset/fold column. Reject an ambiguous
            // or non-existent local wall time instead of guessing an occurrence.
            if (BookingWindow::slotUtcInstant($slot->format('Y-m-d'), $slot->format('H:i:s'), $timezone) === null) {
                return null;
            }

            $times[$key] = $slot;
            ++$index;
        }

        return $times;
    }
}
