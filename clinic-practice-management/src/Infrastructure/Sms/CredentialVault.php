<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Sms;

/**
 * Vault Credentials پنل پیامک (ADR-0025، الزام §4 + Baseline §9).
 *
 * - AES-256-GCM (Authenticated Encryption)
 * - کلید: Env `CPMS_SECRET_KEY` (≥32 byte) یا مشتق از Salt‌های نصب وردپرس
 *   (منحصربه‌فرد per-installation؛ هیچ‌کدام در Repo نیست)
 * - خروجی: {v, nonce, tag, data} (base64) — ذخیره در cpms_settings به‌عنوان JSON
 * - مقدار plaintext هرگز در Settings/HTML/Log/REST/Audit نیست
 */
final class CredentialVault
{
    public function encrypt(string $plaintext): array
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('SMS credential encryption failed (openssl unavailable?)');
        }

        return [
            'v' => 1,
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ];
    }

    /**
     * @param array<string, mixed> $sealed
     */
    public function decrypt(array $sealed): ?string
    {
        try {
            if (!isset($sealed['nonce'], $sealed['tag'], $sealed['data'])) {
                return null;
            }
            $plaintext = openssl_decrypt(
                (string) base64_decode((string) $sealed['data']),
                'aes-256-gcm',
                $this->key(),
                OPENSSL_RAW_DATA,
                (string) base64_decode((string) $sealed['nonce']),
                (string) base64_decode((string) $sealed['tag'])
            );

            return $plaintext === false ? null : $plaintext;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 4 رقم آخر برای نمایش Placeholder (••••••••abcd) — الزام §4.
     */
    public static function last4(string $secret): string
    {
        return strlen($secret) >= 4 ? substr($secret, -4) : '';
    }

    private function key(): string
    {
        $env = getenv('CPMS_SECRET_KEY');
        if (is_string($env) && strlen($env) >= 32) {
            return hash('sha256', $env);
        }

        $salt = (defined('AUTH_KEY') ? (string) AUTH_KEY : '')
            . '|' . (defined('SECURE_AUTH_KEY') ? (string) SECURE_AUTH_KEY : '')
            . '|' . (defined('AUTH_SALT') ? (string) AUTH_SALT : '');

        return hash('sha256', 'cpms-sms-vault:v1|' . $salt);
    }
}
