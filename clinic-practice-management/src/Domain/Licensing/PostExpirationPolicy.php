<?php

declare( strict_types=1 );

namespace ClinicCore\Domain\Licensing;

use DateTimeImmutable;
use DateTimeZone;

/** Owner decision: half-open UTC windows, Gregorian months, clamped month end. */
final class PostExpirationPolicy {
    public static function deadline( int $expires_at, int $months ): int {
        $start = ( new DateTimeImmutable( '@' . $expires_at ) )->setTimezone( new DateTimeZone( 'UTC' ) );
        $month = $start->modify( 'first day of this month' )->modify( '+' . $months . ' months' );
        $day   = min( (int) $start->format( 'j' ), (int) $month->format( 't' ) );

        return $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $day )->getTimestamp();
    }

    public static function allows_new_service( int $now, int $expires_at, bool $returning, bool $reception ): bool {
        if ( $returning ) {
            return $now < self::deadline( $expires_at, 6 );
        }

        return $reception && $now < self::deadline( $expires_at, 1 );
    }
}
