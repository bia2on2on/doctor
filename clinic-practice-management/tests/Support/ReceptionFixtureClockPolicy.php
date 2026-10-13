<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

use ClinicCore\Domain\Booking\BookingWindow;
use DateInterval;
use DateTimeImmutable;

/**
 * Pure clock policy for the Reception fixture's required same-day proof.
 *
 * The caller supplies the Location-local clock explicitly so all day-boundary
 * decisions can be tested without changing the product clock or timezone policy.
 */
final class ReceptionFixtureClockPolicy
{
    public const MINIMUM_RUNWAY_SECONDS = 180;
    public const PREFERRED_LEAD_MINUTES = 90;

    /**
     * Same-day row times used once the PREFERRED lead would leave the local day.
     *
     * The Slice-8 same-day destination falls back to 23:59:00 in exactly that
     * window, and cpms_schedule_slots carries UNIQUE KEY u_slot
     * (clinician_id, slot_date, slot_time): a row pinned to 23:59:00 would make
     * the destination insert duplicate it. The latest pin therefore stops at
     * 23:58:00 so the destination minute stays free.
     */
    public const LATE_DAY_SLOT_TIMES = ['23:55:00', '23:56:00', '23:57:00', '23:58:00'];

    /**
     * Return a valid, sufficiently-future wall time on the same local day, or
     * null when TODAY cannot honestly satisfy the browser proof.
     */
    public static function upcomingSameDayDestination(DateTimeImmutable $nowLocal): ?DateTimeImmutable
    {
        $today = $nowLocal->format('Y-m-d');
        $timezone = $nowLocal->getTimezone();
        $candidate = $nowLocal->add(new DateInterval('PT' . self::PREFERRED_LEAD_MINUTES . 'M'));

        if ($candidate->format('Y-m-d') !== $today) {
            // Keep the explicit same-day case on its own date. Never roll this
            // proof to tomorrow when the last same-day destination is too close.
            $candidate = $nowLocal->setTime(23, 59, 0, 0);
        }

        if ($candidate->format('Y-m-d') !== $today) {
            return null;
        }

        if ($candidate <= $nowLocal->add(new DateInterval('PT' . self::MINIMUM_RUNWAY_SECONDS . 'S'))) {
            return null;
        }

        // A DATE+TIME slot has no UTC offset/fold column. Validate that this
        // exact local wall time maps to one instant; do not normalize or guess.
        if (BookingWindow::slotUtcInstant($candidate->format('Y-m-d'), $candidate->format('H:i:s'), $timezone) === null) {
            return null;
        }

        return $candidate;
    }

    /**
     * The four same-day row times the fixture seeds before the Slice-8 proof.
     *
     * Uses the same PREFERRED horizon as upcomingSameDayDestination(), so the
     * late-day pins apply exactly when that destination can fall back to
     * 23:59:00 and the two can never resolve to one (clinician, date, time).
     *
     * @return array{express: DateTimeImmutable, plain: DateTimeImmutable, third: DateTimeImmutable, partial: DateTimeImmutable}
     */
    public static function sameDaySlotTimes(DateTimeImmutable $nowLocal): array
    {
        $today = $nowLocal->format('Y-m-d');
        $preferred = $nowLocal->add(new DateInterval('PT' . self::PREFERRED_LEAD_MINUTES . 'M'));

        if ($preferred->format('Y-m-d') === $today) {
            return [
                'express' => $nowLocal->add(new DateInterval('PT10M')),
                'plain' => $nowLocal->add(new DateInterval('PT30M')),
                'third' => $nowLocal->add(new DateInterval('PT50M')),
                'partial' => $nowLocal->add(new DateInterval('PT70M')),
            ];
        }

        $pinned = [];
        $keys = ['express', 'plain', 'third', 'partial'];
        foreach (self::LATE_DAY_SLOT_TIMES as $index => $time) {
            [$hour, $minute, $second] = array_map('intval', explode(':', $time));
            $pinned[$keys[$index]] = $nowLocal->setTime($hour, $minute, $second);
        }

        return $pinned;
    }
}
