<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * Read-only preview of the existing post-expiration first-enrollment policy.
 *
 * This class deliberately mirrors the ordinary-expiration branch of
 * {@see SignedLicenseGate::assert()} so the Patient Portal can decide whether to
 * offer the enrollment form at all. It does not grant anything: the enrollment
 * endpoint remains the only authority and re-evaluates the same policy inside
 * its transaction. No state, history, T0 or Clinic record is returned here.
 */
final class FirstEnrollmentPolicy
{
    /**
     * True exactly when SignedLicenseGate treats the license as an ordinary
     * expiration at $now (signed T0 reached). Grace/skew never moves T0.
     */
    public static function is_post_expiration(
        string $status,
        string $reason,
        ?int $expires_at,
        int $now
    ): bool {
        if ( $expires_at === null || $expires_at <= 0 || $now < $expires_at ) {
            return false;
        }

        return match ( $status ) {
            LicenseStatus::ACTIVE => $reason === '',
            LicenseStatus::EXPIRING => $reason === 'near_expiry',
            LicenseStatus::GRACE => in_array( $reason, [ 'grace', 'unreachable_grace' ], true ),
            LicenseStatus::RESTRICTED => $reason === 'expired',
            default => false,
        };
    }

    /**
     * The same decision the gate will make for `patient.create` on this subject:
     * ordinary expiration allows only a returning (strict pre-T0 encounter)
     * subject inside the half-open six-Gregorian-month window; no reception
     * exception is available on the patient-self path.
     */
    public static function allows_first_enrollment(
        string $status,
        string $reason,
        ?int $expires_at,
        int $now,
        bool $returning
    ): bool {
        if ( self::is_post_expiration( $status, $reason, $expires_at, $now ) ) {
            return PostExpirationPolicy::allows_new_service(
                $now,
                (int) $expires_at,
                $returning,
                false
            );
        }

        return LicenseStatus::allowsNewBusiness( $status );
    }
}
