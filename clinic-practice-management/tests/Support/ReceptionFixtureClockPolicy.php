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
    private const PREFERRED_LEAD_MINUTES = 90;

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
}
