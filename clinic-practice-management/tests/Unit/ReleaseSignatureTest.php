<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Update\ReleaseSignature;
use PHPUnit\Framework\TestCase;

/**
 * F10 — امضای مانیفست انتشار (ADR-0029): کلید جدا از مجوز؛ جعل رد می‌شود.
 * نیازمند sodium — بدون آن skip (CI اجرا می‌کند).
 */
final class ReleaseSignatureTest extends TestCase
{
    public function testSignedByReleaseKeyVerifiesWithThatKey(): void
    {
        if (!ReleaseSignature::available()) {
            $this->markTestSkipped('sodium not available');
        }
        $kp = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($kp));
        $payload = ['product' => 'cpms', 'version' => '1.1.0', 'package_sha256' => str_repeat('b', 64)];
        $sig = base64_encode(sodium_crypto_sign_detached(
            ReleaseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($kp)
        ));

        $this->assertTrue(ReleaseSignature::verify($payload, $sig, $pub));
    }

    public function testLicenseKeyCannotSignReleases(): void
    {
        if (!ReleaseSignature::available()) {
            $this->markTestSkipped('sodium not available');
        }
        // امضاشده با کلید «مجوز» (کلید دیگر) → با کلید انتشار رد می‌شود
        $licenseKp = sodium_crypto_sign_keypair();
        $releaseKp = sodium_crypto_sign_keypair();
        $payload = ['product' => 'cpms', 'version' => '9.9.9'];
        $sig = base64_encode(sodium_crypto_sign_detached(
            ReleaseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($licenseKp)
        ));

        $this->assertFalse(ReleaseSignature::verify($payload, $sig, base64_encode(sodium_crypto_sign_publickey($releaseKp))));
    }

    public function testTamperedManifestRejected(): void
    {
        if (!ReleaseSignature::available()) {
            $this->markTestSkipped('sodium not available');
        }
        $kp = sodium_crypto_sign_keypair();
        $payload = ['product' => 'cpms', 'version' => '1.1.0', 'package_sha256' => str_repeat('b', 64)];
        $sig = base64_encode(sodium_crypto_sign_detached(
            ReleaseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($kp)
        ));

        $tampered = $payload;
        $tampered['package_sha256'] = str_repeat('c', 64);
        $this->assertFalse(ReleaseSignature::verify($tampered, $sig, base64_encode(sodium_crypto_sign_publickey($kp))));
    }
}
