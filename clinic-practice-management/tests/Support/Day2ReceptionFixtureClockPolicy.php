<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

use ClinicCore\Domain\Booking\BookingWindow;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure clock policy for the Day-2 reception acceptance fixture's ALREADY-DUE rows.
 *
 * Journey B proves a real receptionist no-show on the reception board. Each
 * reception row therefore has to be, AT THE INSTANT THE BOARD IS READ:
 *   - already due (`slot_start <= now` — a no-show is a reception-desk
 *     judgement, never a future cancellation), and
 *   - still inside `queue.no_show_grace_minutes`, so the real `visits.no_show`
 *     job has not already flipped it to `no_show` and dropped it from
 *     `AppointmentRepository::list_for_reception_operational_day()`
 *     (`status IN ('pending','confirmed')`), and
 *   - on the exact operational date the server resolves for the Location.
 *
 * The retired `08:20:00` / `08:40:00` / `09:00:00` Asia/Tehran rows satisfied
 * none of those properties for arbitrary clocks: after local 08:50 they were
 * past slot + grace, the job legitimately marked them `no_show`, and the board
 * returned nothing (observed `journey-B-reception … Locator.wait_for` timeout).
 *
 * This policy is TOTAL for every instant of the local day: instead of failing
 * when "now minus the preferred age" would fall before local midnight, it clamps
 * the slot to the start of the operational day. Because the clamp only ever
 * moves a slot LATER (never earlier than midnight, never later than now), the
 * resulting row is still due and keeps at least the documented grace remainder.
 * The first seconds of a local day are the only place where the three rows can
 * coincide — they legitimately share the day-start second, which is harmless
 * because they belong to distinct appointments (and, for the Clinic A pair, to
 * distinct clinicians, so `cpms_schedule_slots` `u_slot` stays satisfied).
 *
 * The caller supplies the clock, the operational date and the Location's IANA
 * timezone explicitly, so every day-boundary decision is testable without
 * changing the product clock, the timezone policy or the background job.
 */
final class Day2ReceptionFixtureClockPolicy
{
    /** Grace remaining after the anchor when the window is wide enough. */
    public const PREFERRED_REMAINDER_SECONDS = 300;

    /** Floor for the grace remainder on a narrow configured window. */
    public const MINIMUM_REMAINDER_SECONDS = 30;

    /** Below this the row cannot be guaranteed to survive the browser step. */
    public const MINIMUM_GRACE_SECONDS = 60;

    /** Preferred ages of the three already-due rows (distinct, seconds before now). */
    public const PREFERRED_AGES = [
        'doc1' => 300,
        'doc2' => 240,
        'foreign' => 180,
    ];

    /**
     * Grace remainder this policy guarantees for a configured grace window.
     *
     * At the shipped `queue.no_show_grace_minutes = 30` this is the documented
     * 300 s; a narrower configured window degrades gracefully instead of
     * creating a clock-dependent failure window.
     */
    public static function graceRemainderSeconds(int $graceSeconds): int
    {
        return min(
            self::PREFERRED_REMAINDER_SECONDS,
            max(self::MINIMUM_REMAINDER_SECONDS, intdiv($graceSeconds, 2))
        );
    }

    /**
     * Already-due, still-inside-grace slot instants on one operational day.
     *
     * Returns null only for genuinely unusable inputs, never because of the
     * wall clock: a configured grace below `MINIMUM_GRACE_SECONDS`, a clock that
     * does not belong to `$operationalDate` (a rollover race the caller resolves
     * by re-reading the server date), or a day whose every candidate wall time
     * is a DST gap/fold with no unambiguous instant.
     *
     * @return array{doc1: DateTimeImmutable, doc2: DateTimeImmutable, foreign: DateTimeImmutable}|null
     */
    public static function slotTimes(
        DateTimeImmutable $nowLocal,
        string $operationalDate,
        int $graceMinutes
    ): ?array {
        $timezone = $nowLocal->getTimezone();
        if (!self::isValidDate($operationalDate)) {
            return null;
        }

        $graceSeconds = $graceMinutes * 60;
        if ($graceSeconds < self::MINIMUM_GRACE_SECONDS) {
            return null;
        }

        $now = $nowLocal->setTime(
            (int) $nowLocal->format('H'),
            (int) $nowLocal->format('i'),
            (int) $nowLocal->format('s')
        );

        // The caller anchors to the date the SERVER resolves. If this clock is
        // not on that date the pair is inconsistent (midnight crossed between
        // the two reads) and the caller must re-anchor — never silently pass.
        if ($now->format('Y-m-d') !== $operationalDate) {
            return null;
        }

        $dayStart = new DateTimeImmutable($operationalDate . ' 00:00:00', $timezone);
        $dayEnd = $dayStart->add(new DateInterval('P1D'));
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));

        $remainder = self::graceRemainderSeconds($graceSeconds);
        $maximumAge = $graceSeconds - $remainder;
        if ($maximumAge <= 0) {
            return null;
        }

        $times = [];
        $index = 0;
        foreach (self::PREFERRED_AGES as $key => $preferredAge) {
            // The per-row decrement keeps the three rows distinct even when a
            // narrow configured grace window, rather than the preferred age,
            // is the binding constraint.
            $age = min($preferredAge, $maximumAge - $index);
            if ($age <= 0) {
                return null;
            }
            $candidate = $now->sub(new DateInterval('PT' . $age . 'S'));
            if ($candidate < $dayStart) {
                // Clamp to the operational day start instead of failing or
                // borrowing yesterday's date.
                $candidate = $dayStart;
            }

            $resolved = self::nearestUnambiguousInstant(
                $candidate,
                $dayStart,
                $dayEnd,
                $now,
                $maximumAge,
                $timezone
            );
            if ($resolved === null) {
                return null;
            }

            [$slot, $slotInstant] = $resolved;

            if ($slotInstant > $nowUtc) {
                return null; // must be already due
            }
            if ($slotInstant->add(new DateInterval('PT' . $graceSeconds . 'S'))->getTimestamp()
                - $nowUtc->getTimestamp() < $remainder) {
                return null; // must keep the documented grace remainder
            }

            $times[$key] = $slot;
            ++$index;
        }

        return $times;
    }

    /**
     * Walk earlier by whole minutes until the Location-local wall time maps to
     * exactly one instant. A DATE+TIME column has no offset/fold field, so a
     * repeated or non-existent wall time is rejected rather than guessed.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null [local wall time, UTC instant]
     */
    private static function nearestUnambiguousInstant(
        DateTimeImmutable $candidate,
        DateTimeImmutable $dayStart,
        DateTimeImmutable $dayEnd,
        DateTimeImmutable $now,
        int $maximumAge,
        DateTimeZone $timezone
    ): ?array {
        $attempt = $candidate;
        // Bounded search: at most the whole allowed age, one minute per step.
        for ($step = 0; $step <= intdiv($maximumAge, 60) + 1; ++$step) {
            if ($attempt < $dayStart) {
                $attempt = $dayStart;
            }
            if ($attempt >= $dayEnd || $attempt > $now) {
                return null;
            }

            $instant = BookingWindow::slotUtcInstant(
                $attempt->format('Y-m-d'),
                $attempt->format('H:i:s'),
                $timezone
            );
            if ($instant !== null) {
                return [$attempt, $instant];
            }

            $attempt = $attempt->sub(new DateInterval('PT60S'));
        }

        return null;
    }

    private static function isValidDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
