<?php

declare(strict_types=1);

namespace ClinicCore\Application\Patients;

use ClinicCore\Domain\Licensing\FirstEnrollmentPolicy;
use ClinicCore\Domain\Licensing\LicenseStateProvider;
use ClinicCore\Infrastructure\Repository\PatientEnrollmentRepository;
use Closure;

/**
 * Server-side presentation model for patient-self first enrollment.
 *
 * Security boundaries:
 *  - The authenticated WP user id is the only input; no Clinic, identity,
 *    patient or Organization id can come from the browser.
 *  - Only Organizations with exactly one explicit user↔identity link and an
 *    active status contribute. Ambiguity, missing proof, a legacy-only clinical
 *    self-link or a suspended Organization never becomes a fallback grant.
 *  - Only destination Clinic id/name is returned. No identity id, Organization
 *    id, patient id, encounter, date, T0, treatment or other PHI leaves here.
 *  - Policy is re-evaluated by the enrollment endpoint; this preview cannot
 *    create a record. A stale preview can only lead to a safe server denial.
 *  - An ineligible subject is not told why: no cross-Clinic history, no T0 and
 *    no "you visited Clinic X" signal is exposed.
 */
final class PatientEnrollmentOptions {
    public const STATE_ELIGIBLE       = 'eligible';
    public const STATE_UNVERIFIED     = 'unverified';
    public const STATE_NO_DESTINATION = 'no_destination';
    public const STATE_UNAVAILABLE    = 'unavailable';

    /** @var Closure():int */
    private readonly Closure $clock;

    public function __construct(
        private readonly PatientEnrollmentRepository $repository,
        private readonly LicenseStateProvider $license,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array{state:string, clinics:list<array{id:int, name:string}>}
     */
    public function for_user( int $wp_user_id ): array {
        if ( $wp_user_id <= 0 ) {
            return self::view( self::STATE_UNVERIFIED );
        }

        $verified = $this->repository->verified_organization_identities( $wp_user_id );
        if ( $verified === [] ) {
            return self::view( self::STATE_UNVERIFIED );
        }

        $license    = $this->license->currentState();
        $status     = (string) ( $license['status'] ?? '' );
        $reason     = (string) ( $license['reason'] ?? '' );
        $expires_at = isset( $license['expires_at'] ) && is_numeric( $license['expires_at'] )
            ? (int) $license['expires_at']
            : null;
        $now        = ( $this->clock )();

        $clinics                 = [];
        $saw_active_organization = false;
        $saw_destination         = false;

        foreach ( $verified as $identity ) {
            if ( $identity['organization_status'] !== 'active' ) {
                continue; // Suspended Organization: never offer or name its Clinics.
            }
            $saw_active_organization = true;

            $destinations = $this->repository->destination_clinics(
                $identity['organization_id'],
                $identity['identity_id'],
                $wp_user_id
            );
            if ( $destinations === [] ) {
                continue; // No other Clinic, or this identity already has a local record.
            }
            $saw_destination = true;

            $returning = false;
            if ( FirstEnrollmentPolicy::is_post_expiration( $status, $reason, $expires_at, $now ) ) {
                $returning = $this->repository->identity_has_pre_t0_encounter(
                    $wp_user_id,
                    $identity['identity_id'],
                    $identity['organization_id'],
                    (int) $expires_at
                );
            }

            if ( ! FirstEnrollmentPolicy::allows_first_enrollment( $status, $reason, $expires_at, $now, $returning ) ) {
                continue; // Do not publish policy-blocked destinations.
            }

            foreach ( $destinations as $clinic ) {
                $clinics[] = $clinic;
            }
        }

        if ( $clinics !== [] ) {
            return [ 'state' => self::STATE_ELIGIBLE, 'clinics' => $clinics ];
        }
        if ( $saw_destination ) {
            return self::view( self::STATE_UNAVAILABLE );
        }
        if ( $saw_active_organization ) {
            return self::view( self::STATE_NO_DESTINATION );
        }

        return self::view( self::STATE_UNAVAILABLE );
    }

    /**
     * @return array{state:string, clinics:list<array{id:int, name:string}>}
     */
    private static function view( string $state ): array {
        return [ 'state' => $state, 'clinics' => [] ];
    }
}
