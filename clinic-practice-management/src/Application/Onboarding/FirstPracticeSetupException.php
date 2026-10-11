<?php

declare(strict_types=1);

namespace ClinicCore\Application\Onboarding;

/**
 * Domain failure of the first-practice setup operation (Slice 3A).
 *
 * Deliberately separate from AuthorizationException/ScopeRequiredException:
 * those keep their existing typed meaning for membership-based Clinic
 * authorization, while this exception reports the one-shot bootstrap window
 * itself (not eligible / already consumed / invalid selector / invalid input).
 *
 * No message ever contains patient, clinical or user-secret data.
 */
final class FirstPracticeSetupException extends \RuntimeException {

    public const CODE_INVALID_CLINIC    = 'FIRST_SETUP_INVALID_CLINIC';
    public const CODE_ALREADY_CLAIMED   = 'FIRST_SETUP_ALREADY_CLAIMED';
    public const CODE_NOT_AVAILABLE     = 'FIRST_SETUP_NOT_AVAILABLE';
    public const CODE_NOT_ADMINISTRATOR = 'FIRST_SETUP_NOT_ADMINISTRATOR';
    public const CODE_INVALID_INPUT     = 'FIRST_SETUP_INVALID_INPUT';

    /** Narrow, non-sensitive reason code (never a raw request value). */
    private string $reason_code = self::CODE_NOT_AVAILABLE;

    private function __construct( string $code, string $detail ) {
        parent::__construct( $code . ( '' !== $detail ? ' (' . $detail . ')' : '' ) );
        $this->reason_code = $code;
    }

    public function reason_code(): string {
        return $this->reason_code;
    }

    public static function invalid_clinic(): self {
        return new self( self::CODE_INVALID_CLINIC, '' );
    }

    public static function already_claimed(): self {
        return new self( self::CODE_ALREADY_CLAIMED, '' );
    }

    public static function not_available(): self {
        return new self( self::CODE_NOT_AVAILABLE, '' );
    }

    public static function not_administrator(): self {
        return new self( self::CODE_NOT_ADMINISTRATOR, '' );
    }

    public static function invalid_input( string $field ): self {
        return new self( self::CODE_INVALID_INPUT, $field );
    }
}
