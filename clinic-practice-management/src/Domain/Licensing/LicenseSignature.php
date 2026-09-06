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
