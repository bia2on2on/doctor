<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\LicenseActivationId;
use ClinicCore\Domain\Licensing\LicenseKeys;
use ClinicCore\Domain\Licensing\LicenseSignature;
use PHPUnit\Framework\TestCase;

/**
 * Phase 16 Slice 4 — bounded grammar of the signed `activation_id` claim
 * (central activation RECORD id; not the installation identity, not key
 * material, not authority): string only, 1..64 bytes, ASCII
 * `[A-Za-z0-9][A-Za-z0-9._-]*`. Pure — no WP/DB/network.
 */
final class LicenseActivationIdTest extends TestCase
{
    public function testBoundedAsciiIdentifiersAreAccepted(): void
    {
        foreach ([
            'a',
            'Z',
            '1',
            'act_01J9Z4K4M0Q8X3V7P2N6B5C1D9',
            '7f9c2e1a-4b3d-4c5e-9f0a-1b2c3d4e5f60', // digit-leading UUID
            'A.b_c-9',
            str_repeat('a', 64),
        ] as $value) {
            $this->assertTrue(LicenseActivationId::is_valid($value), "{$value} must be a valid activation_id");
        }
    }

    public function testNonStringsEmptyOversizedAndMalformedValuesAreRejected(): void
    {
        foreach ([
            '',
            7,
            7.5,
            true,
            false,
            null,
            [],
            ['id' => 'act-1'],
            str_repeat('a', 65),
            ' act-1',
            'act-1 ',
            'act 1',
            'act/1',
            'act:1',
            'act_1@vendor',
            '.act-1',
            '-act-1',
            '_act-1',
            'açt-1',
            "act-1\n",
            "act-1\0",
        ] as $value) {
            $this->assertFalse(LicenseActivationId::is_valid($value), var_export($value, true) . ' must be rejected');
        }
    }

    public function testActivationIdGrammarIsSeparateFromKeyIdGrammar(): void
    {
        // Same bounded discipline, deliberately not the same validator: a vendor record id
        // may start with a digit (valid activation_id) while key_id must start with a letter.
        $this->assertTrue(LicenseActivationId::is_valid('7f9c2e1a-4b3d-4c5e-9f0a-1b2c3d4e5f60'));
        $this->assertFalse(LicenseKeys::is_valid_key_id('7f9c2e1a-4b3d-4c5e-9f0a-1b2c3d4e5f60'));

        // Both share the 64-byte bound.
        $this->assertSame(64, LicenseActivationId::MAX_LENGTH);
        $this->assertTrue(LicenseActivationId::is_valid(str_repeat('k', 64)));
        $this->assertFalse(LicenseActivationId::is_valid(str_repeat('k', 65)));
        $this->assertTrue(LicenseKeys::is_valid_key_id(str_repeat('k', 64)));
        $this->assertFalse(LicenseKeys::is_valid_key_id(str_repeat('k', 65)));
    }

    public function testCanonicalJsonCoversActivationIdSoTamperingBreaksTheSignature(): void
    {
        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available in this environment');
        }
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $payload = ['license_id' => 'lic-1', 'expires_at' => 1893463200, 'activation_id' => 'act-signed-1'];
        $signature = base64_encode(sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($keypair)
        ));

        $this->assertTrue(LicenseSignature::verify(LicenseSignature::canonicalJson($payload), $signature, $publicKey));

        $changed = $payload;
        $changed['activation_id'] = 'act-signed-2';
        $this->assertFalse(LicenseSignature::verify(LicenseSignature::canonicalJson($changed), $signature, $publicKey));

        $removed = $payload;
        unset($removed['activation_id']);
        $this->assertFalse(LicenseSignature::verify(LicenseSignature::canonicalJson($removed), $signature, $publicKey));
    }
}
