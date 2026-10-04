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
     * @param array<string, mixed> $payload
     */
    public static function verifyLicenseDocument(array $payload, string $signatureB64): bool
    {
        $hasSchemaVersion = array_key_exists('schema_version', $payload);
        $hasKeyId = array_key_exists('key_id', $payload);

        if (!$hasSchemaVersion && !$hasKeyId) {
            return self::verifyLegacyDocument($payload, $signatureB64);
        }
        if (!$hasSchemaVersion || !$hasKeyId) {
            return false;
        }

        return self::verifyVersionedDocument($payload, $signatureB64);
    }

    /**
     * Explicit, format-bounded compatibility path for old documents only.
     *
     * @param array<string, mixed> $payload
     */
    private static function verifyLegacyDocument(array $payload, string $signatureB64): bool
    {
        if (array_key_exists('schema_version', $payload) || array_key_exists('key_id', $payload)) {
            return false;
        }

        return self::verify(
            self::canonicalJson($payload),
            $signatureB64,
            LicenseKeys::legacyPublicKey()
        );
    }

    /**
     * v2 selects exactly one trusted public key before checking the detached signature.
     * The key_id and schema_version remain part of the canonicalized signed payload.
     *
     * @param array<string, mixed> $payload
     */
    private static function verifyVersionedDocument(array $payload, string $signatureB64): bool
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        if (!is_int($schemaVersion) || $schemaVersion !== self::DOCUMENT_SCHEMA_VERSION) {
            return false;
        }

        $publicKeyB64 = LicenseKeys::publicKeyFor($payload['key_id'] ?? null);
        if ($publicKeyB64 === null) {
            return false;
        }

        return self::verify(self::canonicalJson($payload), $signatureB64, $publicKeyB64);
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
