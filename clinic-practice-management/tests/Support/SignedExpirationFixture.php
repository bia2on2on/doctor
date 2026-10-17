<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseSignature;

/** Real verification/storage path shared by post-expiry continuity regressions. */
trait SignedExpirationFixture
{
    private function installSignedExpiration(int $expiresAt): void
    {
        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium required');
        }
        $pair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        remove_all_filters('cpms_license_public_key');
        add_filter('cpms_license_public_key', static fn (): string => $public);
        $payload = [
            'product' => 'cpms',
            'license_id' => 'owner-expiry-regression',
            'install_id' => App::licenseService()->installId(),
            'issued_at' => $expiresAt - 365 * 86400,
            'expires_at' => $expiresAt,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => ['handwriting' => true]],
        ];
        $signature = base64_encode(sodium_crypto_sign_detached(LicenseSignature::canonicalJson($payload), sodium_crypto_sign_secretkey($pair)));
        App::licenseService()->activateWithDocument(LicenseSignature::canonicalJson($payload), $signature);
    }
}
