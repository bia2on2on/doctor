<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Infrastructure\Licensing\LicenseGatewayException;
use ClinicCore\Infrastructure\Licensing\VendorGateway;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use WP_UnitTestCase;

/**
 * Phase 16 Slice 3 — versioned signed-license documents and trusted-key rotation.
 *
 * Test-only signing keys are generated here. Production receives only their
 * public keys through the configured verifier hooks.
 */
final class LicenseKeyRotationTest extends WP_UnitTestCase
{
    private const LEGACY_KEY_FILTER = 'cpms_license_public_key';
    private const KEY_RING_FILTER = 'cpms_license_public_keys';
    private const SCHEMA_VERSION = 2;

    private string $legacyKeypair;

    /** @var array<string, string> */
    private array $keypairs = [];

    /** @var array<string, string> */
    private array $trustedPublicKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available — signature tests need real sodium');
        }

        $this->legacyKeypair = sodium_crypto_sign_keypair();
        $this->keypairs = [
            'release-a' => sodium_crypto_sign_keypair(),
            'release-b' => sodium_crypto_sign_keypair(),
        ];
        $this->trustedPublicKeys = $this->publicKeyRing();

        // Existing single-key hook remains the explicit legacy verifier source.
        add_filter(self::LEGACY_KEY_FILTER, function (): string {
            return $this->publicKeyB64($this->legacyKeypair);
        });

        // The ring is trusted CPMS configuration, independent of document/request data.
        add_filter(self::KEY_RING_FILTER, function (): array {
            return $this->trustedPublicKeys;
        });
    }

    protected function tearDown(): void
    {
        remove_all_filters(self::LEGACY_KEY_FILTER);
        remove_all_filters(self::KEY_RING_FILTER);
        parent::tearDown();
    }

    public function testLegacyDocumentWithoutEitherMetadataFieldStillVerifies(): void
    {
        $service = $this->service();
        $payload = $this->payload($service);

        $this->installDocument($service, $payload, $this->legacyKeypair);

        $this->assertSame(LicenseStatus::ACTIVE, $service->currentState()['status']);
        $this->assertNotNull((new LicenseRepository(App::db()))->state());
    }

    public function testPartialVersionMetadataCannotFallBackToLegacyVerification(): void
    {
        $service = $this->service();

        foreach ([
            ['schema_version' => self::SCHEMA_VERSION],
            ['key_id' => 'release-a'],
        ] as $partialMetadata) {
            // Signed by the configured legacy key so an old verifier that ignores
            // partial metadata would incorrectly accept this document.
            $this->assertDocumentRejected(
                $service,
                $this->payload($service, $partialMetadata),
                $this->legacyKeypair
            );
        }
    }

    public function testNewSchemaAcceptsDocumentsSignedByEitherMatchingRingKey(): void
    {
        $service = $this->service();

        foreach ($this->keypairs as $keyId => $keypair) {
            $payload = $this->payload($service, [
                'schema_version' => self::SCHEMA_VERSION,
                'key_id' => $keyId,
            ]);

            $this->installDocument($service, $payload, $keypair);
            $this->assertSame(LicenseStatus::ACTIVE, $service->currentState()['status']);
        }
    }

    public function testKeyIdSelectsTheSignerAndNeverFallsBackToLegacyKey(): void
    {
        $service = $this->service();
        // Make the old single-key verifier accept this exact mismatched document;
        // the v2 verifier must instead select release-b and reject signer release-a.
        $this->legacyKeypair = $this->keypairs['release-a'];
        $payload = $this->payload($service, [
            'schema_version' => self::SCHEMA_VERSION,
            'key_id' => 'release-b',
        ]);

        $this->assertDocumentRejected($service, $payload, $this->keypairs['release-a']);
    }

    public function testUnknownKeyIdFailsClosedInsteadOfUsingTheLegacyKey(): void
    {
        $service = $this->service();
        $payload = $this->payload($service, [
            'schema_version' => self::SCHEMA_VERSION,
            'key_id' => 'release-c',
        ]);

        $this->assertDocumentRejected($service, $payload, $this->legacyKeypair);
    }

    public function testRemovingARequiredKeyFromTheRingFailsClosed(): void
    {
        $service = $this->service();
        $this->legacyKeypair = $this->keypairs['release-a'];
        $this->trustedPublicKeys = [
            'release-b' => $this->publicKeyB64($this->keypairs['release-b']),
        ];
        $payload = $this->payload($service, [
            'schema_version' => self::SCHEMA_VERSION,
            'key_id' => 'release-a',
        ]);

        // A legacy fallback would accept this; an absent ring entry must not.
        $this->assertDocumentRejected($service, $payload, $this->keypairs['release-a']);
    }

    public function testUnknownAndMalformedSchemaVersionsFailClosed(): void
    {
        $service = $this->service();
        $versions = [
            1,
            3,
            '2',
            2.5,
            true,
            null,
            [],
        ];

        foreach ($versions as $version) {
            $payload = $this->payload($service, [
                'schema_version' => $version,
                'key_id' => 'release-a',
            ]);
            $this->assertDocumentRejected($service, $payload, $this->legacyKeypair);
        }
    }

    public function testMalformedAndUnboundedKeyIdsFailClosed(): void
    {
        $service = $this->service();
        $keyIds = [
            '',
            7,
            true,
            null,
            [],
            str_repeat('a', 65),
            ' release-a',
            'release/a',
            'rélease-a',
        ];

        foreach ($keyIds as $keyId) {
            $payload = $this->payload($service, [
                'schema_version' => self::SCHEMA_VERSION,
                'key_id' => $keyId,
            ]);
            $this->assertDocumentRejected($service, $payload, $this->legacyKeypair);
        }
    }

    public function testChangingEitherSignedMetadataFieldInvalidatesTheDetachedSignature(): void
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'key_id' => 'release-a',
            'license_id' => 'lic-metadata-coverage',
        ];
        $signature = base64_encode(sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->keypairs['release-a'])
        ));
        $publicKey = $this->publicKeyB64($this->keypairs['release-a']);

        foreach ([
            ['schema_version' => self::SCHEMA_VERSION + 1],
            ['key_id' => 'release-b'],
        ] as $change) {
            $tampered = array_merge($payload, $change);
            $this->assertFalse(LicenseSignature::verify(
                LicenseSignature::canonicalJson($tampered),
                $signature,
                $publicKey
            ));
        }
    }

    public function testVersionedDocumentsUseTheSameOnlineRefreshAndOfflineEntryPoints(): void
    {
        $gateway = new class($this) implements VendorGateway {
            public function __construct(private readonly LicenseKeyRotationTest $test)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function activate(array $request): array
            {
                return $this->test->documentForRequest($request, 'release-a');
            }

            public function refresh(array $request): array
            {
                return $this->test->documentForRequest($request, 'release-b');
            }
        };

        $service = $this->service($gateway);
        $this->assertSame(LicenseStatus::ACTIVE, $service->activateWithKey('fixture-key')['status']);
        $this->assertSame(LicenseStatus::ACTIVE, $service->refresh()['status']);

        $offlinePayload = $this->payload($service, [
            'schema_version' => self::SCHEMA_VERSION,
            'key_id' => 'release-a',
        ]);
        $offlineDocument = $this->signedDocument($offlinePayload, $this->keypairs['release-a']);
        $service->activateWithDocument($offlineDocument['payload_json'], $offlineDocument['signature_b64']);
        $this->assertSame(LicenseStatus::ACTIVE, $service->currentState()['status']);
    }

    /** @return array<string, string> */
    private function publicKeyRing(): array
    {
        $keys = [];
        foreach ($this->keypairs as $keyId => $keypair) {
            $keys[$keyId] = $this->publicKeyB64($keypair);
        }

        return $keys;
    }

    private function publicKeyB64(string $keypair): string
    {
        return base64_encode(sodium_crypto_sign_publickey($keypair));
    }

    private function service(?VendorGateway $gateway = null): LicenseService
    {
        $gateway ??= new class implements VendorGateway {
            public function isConfigured(): bool
            {
                return false;
            }

            public function activate(array $request): array
            {
                throw new \RuntimeException('Offline test gateway must not activate');
            }

            public function refresh(array $request): array
            {
                throw new \RuntimeException('Offline test gateway must not refresh');
            }
        };

        return new LicenseService(
            new LicenseRepository(App::db()),
            $gateway,
            App::db()
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function payload(LicenseService $service, array $metadata = []): array
    {
        return array_merge([
            'product' => 'cpms',
            'license_id' => 'lic-key-rotation-test',
            'install_id' => $service->installId(),
            'issued_at' => time() - 60,
            'expires_at' => time() + 30 * 86400,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => ['updates' => false]],
        ], $metadata);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{payload_json: string, signature_b64: string}
     */
    private function signedDocument(array $payload, string $keypair): array
    {
        return [
            'payload_json' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'signature_b64' => base64_encode(sodium_crypto_sign_detached(
                LicenseSignature::canonicalJson($payload),
                sodium_crypto_sign_secretkey($keypair)
            )),
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array{payload: array<string, mixed>, signature_b64: string}
     */
    public function documentForRequest(array $request, string $keyId): array
    {
        $payload = [
            'product' => 'cpms',
            'license_id' => 'lic-key-rotation-test',
            'install_id' => (string) ($request['install_id'] ?? ''),
            'issued_at' => time() - 60,
            'expires_at' => time() + 30 * 86400,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => ['updates' => false]],
            'schema_version' => self::SCHEMA_VERSION,
            'key_id' => $keyId,
        ];
        $document = $this->signedDocument($payload, $this->keypairs[$keyId]);

        return [
            'payload' => $payload,
            'signature_b64' => $document['signature_b64'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function installDocument(LicenseService $service, array $payload, string $keypair): void
    {
        $document = $this->signedDocument($payload, $keypair);
        $service->activateWithDocument($document['payload_json'], $document['signature_b64']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertDocumentRejected(LicenseService $service, array $payload, string $keypair): void
    {
        $document = $this->signedDocument($payload, $keypair);
        try {
            $service->activateWithDocument($document['payload_json'], $document['signature_b64']);
            $this->fail('Malformed, unknown, partial, or mismatched signature metadata must fail closed');
        } catch (LicenseGatewayException $exception) {
            $this->assertSame('CLINIC_LICENSE_INVALID', $exception->apiCode());
        }

        $this->assertNull((new LicenseRepository(App::db()))->state(), 'Rejected documents must not be persisted');
    }
}
