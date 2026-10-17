<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/** Local signed-license enforcement. Owner post-expiration policy supersedes
 * the historical ordinary-expiration exemption and grace-based permissions.
 */
final class SignedLicenseGate implements LicenseGate
{
    /**
     * عملیات‌هایی که در حالت محدود (RESTRICTED و بدتر) مسدود می‌شوند.
     */
    public const BLOCKED_UNDER_RESTRICTION = [
        LicenseGate::OP_PATIENT_CREATE,
        LicenseGate::OP_APPOINTMENT_BOOK,
        LicenseGate::OP_APPOINTMENT_RESCHEDULE,
        LicenseGate::OP_VISIT_CHECKIN,
        LicenseGate::OP_INVOICE_CREATE,
        LicenseGate::OP_SMS_COMMERCIAL,
        LicenseGate::OP_HANDWRITING_NEW,
    ];

    public function __construct(
        private readonly LicenseStateProvider $provider,
        private readonly ?ReturningPatientEvidence $returning = null,
        private readonly ?\Closure $clock = null
    ) {
    }

    public function assert(string $operation, array $context = []): LicenseDecision
    {
        $state = $this->provider->currentState();
        $status = $state['status'];

        $now        = $this->clock !== null ? ( $this->clock )() : time();
        $expires_at = $state['expires_at'] ?? null;
        $ordinary   = match ( $status ) {
            LicenseStatus::ACTIVE => ( $state['reason'] ?? null ) === '',
            LicenseStatus::EXPIRING => ( $state['reason'] ?? null ) === 'near_expiry',
            LicenseStatus::GRACE => in_array( $state['reason'] ?? null, [ 'grace', 'unreachable_grace' ], true ),
            LicenseStatus::RESTRICTED => ( $state['reason'] ?? null ) === 'expired',
            default => false,
        };

        // Check signed T0 BEFORE allowsNewBusiness: neither grace nor skew moves T0.
        if ( $ordinary && is_int( $expires_at ) && $expires_at > 0 && $now >= $expires_at ) {
            if ( in_array( $operation, [ self::OP_APPOINTMENT_BOOK, self::OP_APPOINTMENT_RESCHEDULE, self::OP_SMS_COMMERCIAL, self::OP_HANDWRITING_NEW ], true ) ) {
                return LicenseDecision::deny( 'license:expired' );
            }
            if ( in_array( $operation, [ self::OP_PATIENT_CREATE, self::OP_VISIT_CHECKIN ], true ) ) {
                $returning = $this->returning !== null && $this->returning->qualifies(
                    (int) ( $context['patient_id'] ?? 0 ),
                    (int) ( $context['clinic_id'] ?? 0 ),
                    $expires_at
                );
                return PostExpirationPolicy::allows_new_service( $now, $expires_at, $returning, ( $context['reception'] ?? false ) === true )
                    ? LicenseDecision::allow()
                    : LicenseDecision::deny( 'license:expired' );
            }
            // Existing authorized care, including its legitimate finance, is not new business.
            return LicenseDecision::allow();
        }
        if ( LicenseStatus::allowsNewBusiness( $status ) ) {
            return LicenseDecision::allow();
        }
        if ( ! in_array( $operation, self::BLOCKED_UNDER_RESTRICTION, true ) ) {
            return LicenseDecision::allow();
        }

        $reason = match ($status) {
            LicenseStatus::GRACE => 'license:' . LicenseStatus::GRACE,
            LicenseStatus::RESTRICTED => 'license:' . LicenseStatus::RESTRICTED,
            LicenseStatus::SUSPENDED => 'license:' . LicenseStatus::SUSPENDED,
            LicenseStatus::REVOKED => 'license:' . LicenseStatus::REVOKED,
            LicenseStatus::INVALID => 'license:' . LicenseStatus::INVALID,
            default => 'license:unreachable',
        };

        return LicenseDecision::deny($reason);
    }

    public function state(): string
    {
        return $this->provider->currentState()['status'];
    }

    public function isReadOnly(): bool
    {
        return LicenseStatus::isRestricted($this->state());
    }
}
