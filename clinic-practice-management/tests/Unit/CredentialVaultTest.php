<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Sms\CredentialVault;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0025 §4 — Vault Credentials (AES-256-GCM) + Mask.
 */
final class CredentialVaultTest extends TestCase
{
    private function vault(): CredentialVault
    {
        return new CredentialVault();
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $vault = $this->vault();
        $sealed = $vault->encrypt('my-super-secret-api-key-12345');

        $this->assertArrayNotHasKey('plaintext', $sealed);
        $this->assertNotSame('my-super-secret-api-key-12345', $sealed['data']);

        $this->assertSame('my-super-secret-api-key-12345', $vault->decrypt($sealed));
    }

    public function testDifferentNoncePerEncryption(): void
    {
        $vault = $this->vault();
        $a = $vault->encrypt('same-secret');
        $b = $vault->encrypt('same-secret');

        $this->assertNotSame($a['nonce'], $b['nonce']);
        $this->assertNotSame($a['data'], $b['data']);
    }

    public function testTamperedCiphertextRejected(): void
    {
        $vault = $this->vault();
        $sealed = $vault->encrypt('secret-value');
        $sealed['data'] = base64_encode(strrev(base64_decode($sealed['data'])));

        $this->assertNull($vault->decrypt($sealed));
    }

    public function testWrongKeyCannotDecrypt(): void
    {
        $sealed = $this->vault()->encrypt('secret-value');

        // Vault با کلید متفاوت (Env متفاوت) باید نتواند decrypt کند
        $key = getenv('CPMS_SECRET_KEY');
        putenv('CPMS_SECRET_KEY=' . str_repeat('a', 32));
        try {
            $other = new CredentialVault();
            $this->assertNull($other->decrypt($sealed));
        } finally {
            if ($key === false) {
                putenv('CPMS_SECRET_KEY');
            } else {
                putenv('CPMS_SECRET_KEY=' . $key);
            }
        }
    }

    public function testMalformedSealedReturnsNull(): void
    {
        $this->assertNull($this->vault()->decrypt([]));
        $this->assertNull($this->vault()->decrypt(['nonce' => 'xx']));
    }

    public function testLast4(): void
    {
        $this->assertSame('abcd', CredentialVault::last4('x.y.z.abcd'));
        $this->assertSame('', CredentialVault::last4('ab'));
    }
}
