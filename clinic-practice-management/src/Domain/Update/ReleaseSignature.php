<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Update;

use ClinicCore\Domain\Licensing\LicenseSignature;

/**
 * تأیید امضای مانیفست انتشار (ADR-0029) — Ed25519، canonicalization مشترک
 * با ADR-0023؛ کلید جدا (ReleaseKeys). بدون WP/DB/شبکه.
 */
final class ReleaseSignature
{
    public static function available(): bool
    {
        return LicenseSignature::available();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function canonicalJson(array $payload): string
    {
        return LicenseSignature::canonicalJson($payload);
    }

    public static function verify(array $payload, string $signatureB64, ?string $publicKeyB64 = null): bool
    {
        $key = $publicKeyB64 ?? ReleaseKeys::publicKey();

        return LicenseSignature::verify(self::canonicalJson($payload), $signatureB64, $key);
    }
}
