<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * Phase 16 Slice 4 — bounded grammar of the signed `activation_id` claim.
 *
 * `activation_id` identifies the central license service's activation RECORD
 * for this installation: the handle a later central-service/rebind contract
 * can reference unambiguously. It is NOT the installation identity
 * (`install_id` stays mandatory and independently verified), NOT a substitute
 * for the signed `domain` binding, NOT tenancy/authorization authority, and
 * NOT a secret or PHI — an opaque, vendor-issued, bounded ASCII identifier
 * that lives inside the signed canonical payload (tampering invalidates the
 * document) and is persisted only through the existing stored payload JSON.
 *
 * Only the external central service issues/supersedes activation records and
 * can know whether another production activation exists; CPMS never derives
 * such a verdict from local state and no local decision reads this value.
 *
 * Pure PHP (no WP/DB/network). Same bounded-identifier discipline as
 * `LicenseKeys::is_valid_key_id()`, but deliberately a separate validator:
 * `key_id` selects a verification key and is never an activation identity.
 */
final class LicenseActivationId {
    public const MAX_LENGTH = 64;

    /**
     * Opaque vendor record identifiers (UUID/ULID/prefixed ids) may start with
     * a digit, so the first byte is any ASCII alphanumeric; the rest also
     * allows `.`, `_` and `-`. No whitespace, slash, colon, `@`, control or
     * non-ASCII bytes; `\z` rejects a trailing newline.
     */
    private const PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/';

    /**
     * Strict, fail-closed shape check for a PRESENT claim: string only,
     * 1..64 bytes, conservative ASCII identifier grammar. Whether absence is
     * acceptable is the document-format rule in LicenseSignature, not here.
     */
    public static function is_valid( mixed $value ): bool {
        return is_string( $value )
            && $value !== ''
            && strlen( $value ) <= self::MAX_LENGTH
            && preg_match( self::PATTERN, $value ) === 1;
    }
}
