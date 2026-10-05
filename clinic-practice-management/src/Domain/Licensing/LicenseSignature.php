<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * تأیید امضای سند مجوز — Ed25519 (sodium_crypto_sign_verify_detached).
 *
 * خالص (فقط PHP ext)؛ بدون WP/DB/شبکه. کلید عمومی فروشنده در افزونه شipped
 * می‌شود (LicenseKeys)؛ کلید خصوصی هرگز در افزونه نیست (ADR-0023 §2/§19).
 *
 * Canonicalization: هر دو طرف (سرور و کلاینت) payload را با کلیدهای مرتب
 * شده (k-sort بازگشتی) JSON می‌کنند و روی همان رشته امضا/تأیید می‌کنند —
 * ترتیب کلیدها هرگز امضا را نمی‌شکند.
 *
 * اگر sodium در دسترس نباشد → امضا «تأییدشدنی نیست» (false) = fail-closed
 * (فعال‌سازی/refresh رد می‌شود و Health کمبود capability را هشدار می‌دهد).
 */
final class LicenseSignature
{
    public const DOCUMENT_SCHEMA_VERSION = 2;

    public static function available(): bool
    {
        return extension_loaded('sodium') && function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * رشته‌ی متعارف برای امضا/تأیید.
     *
     * @param array<string, mixed> $payload
     */
    public static function canonicalJson(array $payload): string
    {
        self::ksortRecursive($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Verify one signed-license document using its explicit compatibility format.
     *
     * No version fields selects only the bounded legacy format. Presence of either
     * field opts into the new format; partial metadata never falls back to legacy.
     *
     * Phase 16 Slice 4 — signed activation identity: a v2 document MAY carry
     * `activation_id` (central activation RECORD id, see LicenseActivationId).
     * Absent ⇒ accepted (bounded compatibility rule); present ⇒ must satisfy the
     * bounded grammar or the whole document fails closed. The legacy format
     * predates the claim, so a legacy document carrying it fails closed too.
     * The value stays inside the canonical signed payload like every other key.
     *
     * @param array<string, mixed> $payload
     */
    public static function verify_license_document( array $payload, string $signature_b64 ): bool {
        $has_schema_version = array_key_exists( 'schema_version', $payload );
        $has_key_id         = array_key_exists( 'key_id', $payload );

        if ( ! $has_schema_version && ! $has_key_id ) {
            return self::verify_legacy_document( $payload, $signature_b64 );
        }
        if ( ! $has_schema_version || ! $has_key_id ) {
            return false;
        }

        return self::verify_versioned_document( $payload, $signature_b64 );
    }

    /**
     * Explicit, format-bounded compatibility path for old documents only.
     *
     * @param array<string, mixed> $payload
     */
    private static function verify_legacy_document( array $payload, string $signature_b64 ): bool {
        if ( array_key_exists( 'schema_version', $payload ) || array_key_exists( 'key_id', $payload ) ) {
            return false;
        }
        // Old documents predate the v2 activation identity claim (Phase 16 Slice 4).
        if ( array_key_exists( 'activation_id', $payload ) ) {
            return false;
        }

        return self::verify(
            self::canonicalJson( $payload ),
            $signature_b64,
            LicenseKeys::legacy_public_key()
        );
    }

    /**
     * v2 selects exactly one trusted public key before checking the detached signature.
     * The key_id and schema_version remain part of the canonicalized signed payload.
     *
     * @param array<string, mixed> $payload
     */
    private static function verify_versioned_document( array $payload, string $signature_b64 ): bool {
        $schema_version = $payload['schema_version'] ?? null;
        if ( ! is_int( $schema_version ) || $schema_version !== self::DOCUMENT_SCHEMA_VERSION ) {
            return false;
        }

        $public_key_b64 = LicenseKeys::public_key_for( $payload['key_id'] ?? null );
        if ( $public_key_b64 === null ) {
            return false;
        }
        // Phase 16 Slice 4 — optional signed activation identity: absent is accepted
        // (the central service issues no activation records yet); a present claim
        // must be a bounded ASCII identifier or the document fails closed.
        if ( array_key_exists( 'activation_id', $payload ) && ! LicenseActivationId::is_valid( $payload['activation_id'] ) ) {
            return false;
        }

        return self::verify(
            self::canonicalJson( $payload ),
            $signature_b64,
            $public_key_b64
        );
    }

    /**
     * تأیید detached signature روی پیام خام.
     */
    public static function verify(string $message, string $signatureB64, string $publicKeyB64): bool
    {
        if (!self::available()) {
            return false;
        }
        $sig = base64_decode($signatureB64, true);
        $key = base64_decode($publicKeyB64, true);
        if ($sig === false || $key === false) {
            return false;
        }
        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $message, $key);
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function ksortRecursive(array &$arr): void
    {
        ksort($arr, SORT_STRING);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                self::ksortRecursive($v);
            }
        }
        unset($v);
    }
}
