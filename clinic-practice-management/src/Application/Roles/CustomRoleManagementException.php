<?php

declare(strict_types=1);

namespace ClinicCore\Application\Roles;

/**
 * Domain failure of a custom-role management write. Deliberately separate from
 * AuthorizationException/ScopeRequiredException: authority failures keep their
 * existing typed meaning (authentication / trusted scope / scoped permission),
 * while this exception reports a bounded-input or object-lookup failure.
 *
 * No message ever contains patient or clinical data — custom-role definitions
 * hold only role keys, display names and capability slugs.
 */
final class CustomRoleManagementException extends \RuntimeException
{
    public const CODE_INVALID_ROLE_KEY      = 'CUSTOM_ROLE_INVALID_ROLE_KEY';
    public const CODE_INVALID_DISPLAY_NAME  = 'CUSTOM_ROLE_INVALID_DISPLAY_NAME';
    public const CODE_CAPABILITY_NOT_ALLOWED = 'CUSTOM_ROLE_CAPABILITY_NOT_ALLOWED';
    public const CODE_CAPABILITY_NOT_DELEGABLE = 'CUSTOM_ROLE_CAPABILITY_NOT_DELEGABLE';
    public const CODE_NOT_FOUND             = 'CUSTOM_ROLE_NOT_FOUND';
    public const CODE_KEY_CONFLICT          = 'CUSTOM_ROLE_KEY_CONFLICT';

    private const SAFE_MESSAGES = [
        self::CODE_INVALID_ROLE_KEY            => 'The role key is not a valid definable custom-role key.',
        self::CODE_INVALID_DISPLAY_NAME        => 'The display name is empty, too long, or not usable text.',
        self::CODE_CAPABILITY_NOT_ALLOWED      => 'A requested capability is not an editable CPMS capability.',
        self::CODE_CAPABILITY_NOT_DELEGABLE    => 'A requested capability exceeds the editor\'s effective Clinic authority.',
        self::CODE_NOT_FOUND                   => 'No custom role definition with that key exists in this Clinic.',
        self::CODE_KEY_CONFLICT                => 'That custom role key is already defined in this Clinic.',
    ];

    private function __construct( string $code, string $detail ) {
        parent::__construct( self::SAFE_MESSAGES[ $code ] . ( $detail !== '' ? ' (' . $detail . ')' : '' ) );
        $this->reason_code = $code; // narrow, non-sensitive reason code
    }

    /** Narrow, non-sensitive reason code (never a raw request value). */
    private string $reason_code = self::CODE_NOT_FOUND;

    public function reason_code(): string {
        return $this->reason_code;
    }

    public static function invalid_role_key(): self {
        return new self( self::CODE_INVALID_ROLE_KEY, '' );
    }

    public static function invalid_display_name(): self {
        return new self( self::CODE_INVALID_DISPLAY_NAME, '' );
    }

    /**
     * @param array<array-key, mixed> $rejected
     */
    public static function capability_not_allowed( array $rejected ): self {
        return new self( self::CODE_CAPABILITY_NOT_ALLOWED, self::describe( $rejected ) );
    }

    /**
     * @param array<array-key, mixed> $rejected
     */
    public static function capability_not_delegable( array $rejected ): self {
        return new self( self::CODE_CAPABILITY_NOT_DELEGABLE, self::describe( $rejected ) );
    }

    public static function not_found( string $role_key ): self {
        return new self( self::CODE_NOT_FOUND, $role_key );
    }

    public static function key_conflict( string $role_key ): self {
        return new self( self::CODE_KEY_CONFLICT, $role_key );
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
