<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * Trusted Ed25519 public keys for signed license documents (ADR-0023 §2).
 *
 * - Private signing keys never ship in CPMS; only public verification material is accepted here.
 * - PRODUCTION_PUBLIC_B64 is the existing legacy-key placeholder. It remains invalid until
 *   the official release key is supplied as a commercialization/release prerequisite.
 * - TRUSTED_PUBLIC_KEYS_B64 is the v2 key ring. It is intentionally empty until real public
 *   release keys are available; tests provide generated public keys via the trusted filter.
 * - The old cpms_license_public_key filter is retained only for explicitly legacy documents.
 *   New documents use the separate cpms_license_public_keys ring and never fall back to it.
 */
final class LicenseKeys
{
    /**
     * Placeholder — base64-invalid on purpose until the official legacy release key is supplied.
     */
    public const PRODUCTION_PUBLIC_B64 = 'REPLACE_AT_RELEASE_WITH_ED25519_PUBLIC_B64';

    /**
     * Locally trusted v2 verification ring: key_id => base64-encoded Ed25519 public key.
     * No generated/test key is included in the production source.
     *
     * @var array<string, string>
     */
    public const TRUSTED_PUBLIC_KEYS_B64 = [];

    private const MAX_KEY_ID_LENGTH       = 64;
    private const MAX_TRUSTED_PUBLIC_KEYS = 32;

    public static function publicKey(): string
    {
        $key = self::PRODUCTION_PUBLIC_B64;
        if (function_exists('apply_filters')) {
            $filtered = (string) apply_filters('cpms_license_public_key', $key);
            if ($filtered !== '') {
                return $filtered;
            }
        }

        return $key;
    }

    /**
     * The existing one-key path, selected only for legacy document shape.
     */
    public static function legacy_public_key(): string {
        return self::publicKey();
    }

    /**
     * Current CPMS-trusted v2 key ring. No document/request values are inputs.
     * A malformed or oversized configured ring is rejected as a whole.
     *
     * @return array<string, string>
     */
    public static function trusted_public_keys(): array {
        $keys = self::TRUSTED_PUBLIC_KEYS_B64;
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'cpms_license_public_keys', $keys );
            if ( ! is_array( $filtered ) ) {
                return [];
            }
            $keys = $filtered;
        }

        if ( count( $keys ) > self::MAX_TRUSTED_PUBLIC_KEYS ) {
            return [];
        }

        $trusted = [];
        foreach ( $keys as $key_id => $public_key_b64 ) {
            if ( ! is_string( $key_id )
                || ! self::is_valid_key_id( $key_id )
                || ! is_string( $public_key_b64 )
                || trim( $public_key_b64 ) === ''
            ) {
                return [];
            }
            $trusted[ $key_id ] = $public_key_b64;
        }

        return $trusted;
    }

    /**
     * Resolve an exact, valid identifier against the locally trusted ring.
     */
    public static function public_key_for( mixed $key_id ): ?string {
        if ( ! self::is_valid_key_id( $key_id ) ) {
            return null;
        }

        $keys = self::trusted_public_keys();

        return $keys[ $key_id ] ?? null;
    }

    /**
     * key_id is a bounded, case-sensitive ASCII identifier, not key material.
     */
    public static function is_valid_key_id( mixed $key_id ): bool {
        return is_string( $key_id )
            && strlen( $key_id ) <= self::MAX_KEY_ID_LENGTH
            && preg_match( '/\A[A-Za-z][A-Za-z0-9._-]*\z/', $key_id ) === 1;
    }
}
