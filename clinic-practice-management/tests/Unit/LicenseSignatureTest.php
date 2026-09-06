<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\LicenseSignature;
use PHPUnit\Framework\TestCase;

/**
 * F10 / ADR-0023 — تأیید امضای Ed25519 سند مجوز (spec §19/§43):
 *  - امضای معتبر پذیرفته می‌شود
 *  - دستکاری payload / امضا / کلید → رد قطعی
 *  - canonicalization مستقل از ترتیب کلیدها
 *
 * نیازمند ext/sodium — در محیط‌های بدون sodium (WASM 32-bit) skip می‌شود؛
 * CI (PHP رسمی) آن را اجرا می‌کند.
 */
final class LicenseSignatureTest extends TestCase
{
    public function testValidSignatureVerifies(): void
    {
        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available in this environment');
        }
        $kp = sodium_crypto_sign_keypair();
        $pub = sodium_crypto_sign_publickey($kp);
        $payload = ['license_id' => 'lic-1', 'expires_at' => 1893463200, 'entitlements' => ['features' => ['handwriting' => true]]];
        $msg = LicenseSignature::canonicalJson($payload);
        $sig = sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($kp));

        $this->assertTrue(LicenseSignature::verify($msg, base64_encode($sig), base64_encode($pub)));
    }

    public function testTamperedPayloadRejected(): void
    {
        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available in this environment');
        }
        $kp = sodium_crypto_sign_keypair();
        $pub = sodium_crypto_sign_publickey($kp);
        $payload = ['license_id' => 'lic-1', 'expires_at' => 1893463200];
        $sig = sodium_crypto_sign_detached(LicenseSignature::canonicalJson($payload), sodium_crypto_sign_secretkey($kp));

        $tampered = $payload;
        $tampered['expires_at'] = 1893463200 + 86400 * 365; // تلاش تمدید جعلی
        $this->assertFalse(LicenseSignature::verify(LicenseSignature::canonicalJson($tampered), base64_encode($sig), base64_encode($pub)));
    }

    public function testWrongKeyRejected(): void
    {
        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available in this environment');
        }
        $kp = sodium_crypto_sign_keypair();
        $other = sodium_crypto_sign_keypair();
        $msg = LicenseSignature::canonicalJson(['license_id' => 'lic-1']);
        $sig = sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($kp));

        $this->assertFalse(LicenseSignature::verify($msg, base64_encode($sig), base64_encode(sodium_crypto_sign_publickey($other))));
    }

    public function testCanonicalizationIgnoresKeyOrder(): void
    {
        $a = LicenseSignature::canonicalJson(['b' => 2, 'a' => ['x' => 1, 'y' => 2]]);
        $b = LicenseSignature::canonicalJson(['a' => ['y' => 2, 'x' => 1], 'b' => 2]);
        $this->assertSame($a, $b);
    }

    public function testMalformedBase64Rejected(): void
    {
        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available in this environment');
        }
        $this->assertFalse(LicenseSignature::verify('msg', '!!!not-base64!!!', base64_encode(str_repeat("\x01", 32))));
        $this->assertFalse(LicenseSignature::verify('msg', base64_encode(str_repeat("\x01", 64)), 'not-base64'));
    }

    public function testPlaceholderProductionKeyIsNotVerifiable(): void
    {
        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available in this environment');
        }
        // Placeholder عمداً نامعتبر است — تا جایگزینی کلید رسمی، fail-closed
        $this->assertFalse(LicenseSignature::verify('x', base64_encode(str_repeat("\x01", 64)), \ClinicCore\Domain\Licensing\LicenseKeys::PRODUCTION_PUBLIC_B64));
    }
}
