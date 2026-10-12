<?php

declare(strict_types=1);

namespace ClinicCore\Application\Roles;

/**
 * Custom Role & Permission Management — Slice 3B: domain failure of a
 * Clinic-local staff custom-role ASSIGNMENT.
 *
 * Deliberately separate from AuthorizationException/ScopeRequiredException:
 * authority failures keep their existing typed meaning (authentication / trusted
 * scope / scoped permission), while this exception reports a bounded-input or
 * object-lookup failure of the assignment itself.
 *
 * No message contains patient, clinical or finance data — an assignment carries
 * only role keys, capability slugs and WordPress user / membership identifiers.
 */
final class CustomRoleAssignmentException extends \RuntimeException {

    public const CODE_INVALID_ROLE_KEY           = 'CUSTOM_ROLE_ASSIGNMENT_INVALID_ROLE_KEY';
    public const CODE_TARGET_MEMBERSHIP_REQUIRED = 'CUSTOM_ROLE_ASSIGNMENT_TARGET_MEMBERSHIP_REQUIRED';
    public const CODE_DEFINITION_NOT_FOUND       = 'CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_FOUND';
    public const CODE_DEFINITION_NOT_ACTIVE      = 'CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_ACTIVE';
    public const CODE_CAPABILITY_NOT_DELEGABLE   = 'CUSTOM_ROLE_ASSIGNMENT_CAPABILITY_NOT_DELEGABLE';
    public const CODE_MEMBERSHIP_UPDATE_FAILED   = 'CUSTOM_ROLE_ASSIGNMENT_MEMBERSHIP_UPDATE_FAILED';

    /**
     * Narrow, non-sensitive reason code (never a raw request value). Not the
     * inherited `$code` property, which stays an integer.
     */
    private string $reason_code = self::CODE_DEFINITION_NOT_FOUND;

    private function __construct( string $code, string $detail ) {
        parent::__construct( self::safe_message( $code ) . ( '' !== $detail ? ' (' . $detail . ')' : '' ) );
        $this->reason_code = $code;
    }

    public function reason_code(): string {
        return $this->reason_code;
    }

    public static function invalid_role_key(): self {
        return new self( self::CODE_INVALID_ROLE_KEY, '' );
    }

    public static function target_membership_required(): self {
        return new self( self::CODE_TARGET_MEMBERSHIP_REQUIRED, '' );
    }

    public static function definition_not_found( string $role_key ): self {
        return new self( self::CODE_DEFINITION_NOT_FOUND, $role_key );
    }

    public static function definition_not_active( string $role_key ): self {
        return new self( self::CODE_DEFINITION_NOT_ACTIVE, $role_key );
    }

    /**
     * @param array<array-key, mixed> $rejected
     */
    public static function capability_not_delegable( array $rejected ): self {
        return new self( self::CODE_CAPABILITY_NOT_DELEGABLE, self::describe( $rejected ) );
    }

    public static function membership_update_failed( int $membership_id ): self {
        return new self( self::CODE_MEMBERSHIP_UPDATE_FAILED, (string) $membership_id );
    }

    /**
     * Safe catalogue of operator-facing messages. A reason code outside the
     * catalogue can only come from an internal defect and therefore falls back
     * to the least informative (not-found) wording.
     */
    private static function safe_message( string $code ): string {
        return match ( $code ) {
            self::CODE_INVALID_ROLE_KEY           => 'The requested key is not a definable custom-role key (built-in keys are never assignable here).',
            self::CODE_TARGET_MEMBERSHIP_REQUIRED => 'The target user has no ACTIVE membership in this Clinic.',
            self::CODE_DEFINITION_NOT_ACTIVE      => 'That custom role is not active in this Clinic.',
            self::CODE_CAPABILITY_NOT_DELEGABLE   => 'The custom role carries a capability beyond the effective Clinic authority of the assigner.',
            self::CODE_MEMBERSHIP_UPDATE_FAILED   => 'The membership role update was not persisted.',
            default                               => 'No custom role definition with that key exists in this Clinic.',
        };
    }

    /**
     * Reports rejected capability names only (never values of any other kind),
     * bounded in length so an oversized payload cannot inflate a log line.
     *
     * @param array<array-key, mixed> $rejected
     */
    private static function describe( array $rejected ): string {
        $names = [];
        foreach ( $rejected as $candidate ) {
            if ( ! is_string( $candidate ) ) {
                $names[] = gettype( $candidate );

                continue;
            }
            $names[] = ( mb_strlen( $candidate ) > 64 ) ? mb_substr( $candidate, 0, 64 ) . '…' : $candidate;
            if ( count( $names ) >= 5 ) {
                break;
            }
        }

        return implode( ', ', $names );
    }
}
