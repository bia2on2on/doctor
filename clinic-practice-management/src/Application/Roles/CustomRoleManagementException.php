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
final class CustomRoleManagementException extends \RuntimeException {

	public const CODE_INVALID_ROLE_KEY         = 'CUSTOM_ROLE_INVALID_ROLE_KEY';
	public const CODE_INVALID_DISPLAY_NAME     = 'CUSTOM_ROLE_INVALID_DISPLAY_NAME';
	public const CODE_CAPABILITY_NOT_ALLOWED   = 'CUSTOM_ROLE_CAPABILITY_NOT_ALLOWED';
	public const CODE_CAPABILITY_NOT_DELEGABLE = 'CUSTOM_ROLE_CAPABILITY_NOT_DELEGABLE';
	public const CODE_NOT_FOUND                = 'CUSTOM_ROLE_NOT_FOUND';
	public const CODE_KEY_CONFLICT             = 'CUSTOM_ROLE_KEY_CONFLICT';
	public const CODE_STALE_VERSION            = 'CUSTOM_ROLE_STALE_VERSION';
	public const CODE_TERMINAL_STATE           = 'CUSTOM_ROLE_TERMINAL_STATE';
	public const CODE_INVALID_STATE            = 'CUSTOM_ROLE_INVALID_STATE';
	public const CODE_MEMBERSHIP_REFERENCES    = 'CUSTOM_ROLE_MEMBERSHIP_REFERENCES';

	/**
	 * Narrow, non-sensitive reason code (never a raw request value). Not the
	 * inherited `$code` property, which stays an integer.
	 */
	private string $reason_code = self::CODE_NOT_FOUND;

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

	public static function stale_version(): self {
		return new self( self::CODE_STALE_VERSION, '' );
	}

	public static function terminal_state(): self {
		return new self( self::CODE_TERMINAL_STATE, '' );
	}

	public static function invalid_state(): self {
		return new self( self::CODE_INVALID_STATE, '' );
	}

	public static function membership_references(): self {
		return new self( self::CODE_MEMBERSHIP_REFERENCES, '' );
	}

	/**
	 * Safe, translated-free catalogue of operator-facing messages. A reason code
	 * that is not in the catalogue can only be produced by an internal defect and
	 * therefore falls back to the least informative (not-found) wording.
	 */
	private static function safe_message( string $code ): string {
		return match ( $code ) {
			self::CODE_INVALID_ROLE_KEY         => 'The role key is not a valid definable custom-role key.',
			self::CODE_INVALID_DISPLAY_NAME     => 'The display name is empty, too long, or not usable text.',
			self::CODE_CAPABILITY_NOT_ALLOWED   => 'A requested capability is not an editable CPMS capability.',
			self::CODE_CAPABILITY_NOT_DELEGABLE => 'A requested capability exceeds the effective Clinic authority of the editor.',
			self::CODE_KEY_CONFLICT             => 'That custom role key is already defined in this Clinic.',
			self::CODE_STALE_VERSION            => 'The custom role definition changed; reload it before retrying.',
			self::CODE_TERMINAL_STATE           => 'The archived custom role is terminal and read-only.',
			self::CODE_INVALID_STATE            => 'The custom role has an unrecognized status; no mutation is allowed.',
			self::CODE_MEMBERSHIP_REFERENCES    => 'Reassign every same-Clinic membership, including suspended memberships, before archival.',
			default                             => 'No custom role definition with that key exists in this Clinic.',
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
