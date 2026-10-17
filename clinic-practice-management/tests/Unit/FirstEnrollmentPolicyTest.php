<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\FirstEnrollmentPolicy;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\PostExpirationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The Patient Portal must not offer a form the server will reject. This pins
 * the preview to the existing ordinary-expiration policy: same half-open
 * six-Gregorian-month window, same strict pre-T0 returning evidence, no
 * reception exception, and no new business after T0 for other operations.
 */
final class FirstEnrollmentPolicyTest extends TestCase
{
    private const T0 = 1769862896; // 2026-01-31 12:34:56 UTC

    public function testOrdinaryExpirationBoundaryIsHalfOpenForReturningSubject(): void
    {
        $deadline = PostExpirationPolicy::deadline( self::T0, 6 );

        self::assertTrue( FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, $deadline - 1, true ) );
        self::assertFalse( FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, $deadline, true ) );
        self::assertFalse( FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, $deadline + 1, true ) );
    }

    public function testPostExpirationNeverOffersTheReceptionOnlyNewPatientWindow(): void
    {
        $oneMonth = PostExpirationPolicy::deadline( self::T0, 1 );

        self::assertFalse(
            FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, $oneMonth - 1, false ),
            'The one-month reception window is staff/reception-only; patient-self enrollment must not inherit it.'
        );
        self::assertFalse(
            FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', self::T0, self::T0, false )
        );
    }

    public function testPostExpirationDetectionMatchesTheGateOrdinaryCases(): void
    {
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::ACTIVE, '', self::T0, self::T0 ) );
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::EXPIRING, 'near_expiry', self::T0, self::T0 ) );
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::GRACE, 'grace', self::T0, self::T0 ) );
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::GRACE, 'unreachable_grace', self::T0, self::T0 ) );
        self::assertTrue( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::RESTRICTED, 'expired', self::T0, self::T0 ) );

        self::assertFalse( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::ACTIVE, '', self::T0, self::T0 - 1 ) );
        self::assertFalse( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::SUSPENDED, 'suspended', self::T0, self::T0 + 1 ) );
        self::assertFalse( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::INVALID, 'expired', self::T0, self::T0 + 1 ) );
        self::assertFalse( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::RESTRICTED, 'binding_mismatch', self::T0, self::T0 + 1 ) );
        self::assertFalse( FirstEnrollmentPolicy::is_post_expiration( LicenseStatus::ACTIVE, '', null, self::T0 + 1 ) );
    }

    public function testNewBusinessStatesRemainAllowedBeforeT0(): void
    {
        foreach ( [ LicenseStatus::ACTIVE, LicenseStatus::EXPIRING, LicenseStatus::DEVELOPMENT, LicenseStatus::ACTIVATION_PENDING ] as $status ) {
            self::assertTrue(
                FirstEnrollmentPolicy::allows_first_enrollment( $status, '', self::T0, self::T0 - 1, false ),
                $status . ' before T0 allows ordinary new business.'
            );
        }
        self::assertTrue(
            FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::ACTIVE, '', self::T0, self::T0 - 1, false )
        );
    }

    public function testBlockingStatesNeverOfferEnrollmentEvenForReturningSubject(): void
    {
        foreach ( [ LicenseStatus::SUSPENDED, LicenseStatus::REVOKED, LicenseStatus::INVALID, LicenseStatus::UNREACHABLE ] as $status ) {
            self::assertFalse(
                FirstEnrollmentPolicy::allows_first_enrollment( $status, 'blocked', self::T0, self::T0 - 1, true ),
                $status . ' must not be softened by returning evidence or a pre-T0 clock.'
            );
        }
    }

    public function testActualSixGregorianMonthDeadlineUsesClampedGregorianMonths(): void
    {
        $endOfMonth = strtotime( '2026-08-31 10:00:00 UTC' );
        $deadline = PostExpirationPolicy::deadline( $endOfMonth, 6 );

        self::assertSame( '2027-02-28 10:00:00', gmdate( 'Y-m-d H:i:s', $deadline ) );
        self::assertTrue( FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', $endOfMonth, $deadline - 1, true ) );
        self::assertFalse( FirstEnrollmentPolicy::allows_first_enrollment( LicenseStatus::RESTRICTED, 'expired', $endOfMonth, $deadline, true ) );
    }
}
