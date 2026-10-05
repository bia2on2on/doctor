<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Application\Update\UpdateService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Domain\Update\ReleaseSignature;
use ClinicCore\Infrastructure\Licensing\LicenseGatewayException;
use ClinicCore\Infrastructure\Licensing\VendorGateway;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use ClinicCore\Infrastructure\Update\UpdateMetadataGateway;
use WP_UnitTestCase;

/**
 * Phase 16 Slice 6A — representation-only signed v2 update-rights boundary.
 *
 * Runs the actual LicenseService + LicenseSignature verifier + existing state
 * table on WP/MySQL. No availability enforcement belongs to this slice.
 */
final class LicenseUpdateRightsClaimTest extends WP_UnitTestCase
{
    private const LEGACY_KEY_FILTER = 'cpms_license_public_key';
    private const KEY_RING_FILTER = 'cpms_license_public_keys';
    private const RELEASE_KEY_FILTER = 'cpms_release_public_key';
    private const SCHEMA_VERSION = 2;
    private const KEY_ID = 'rights-test-a';

    private string $legacyKeypair;
    private string $ringKeypair;
    private string $releaseKeypair;
    private int $issuedAt;
    private int $expiresAt;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available — signed-license tests need real sodium');
        }

        $this->legacyKeypair = sodium_crypto_sign_keypair();
        $this->ringKeypair = sodium_crypto_sign_keypair();
        $this->releaseKeypair = sodium_crypto_sign_keypair();
        $this->issuedAt = time() - 60;
        $this->expiresAt = time() + 30 * 86400;

        $legacyPublic = base64_encode(sodium_crypto_sign_publickey($this->legacyKeypair));
        $ringPublic = base64_encode(sodium_crypto_sign_publickey($this->ringKeypair));
        $releasePublic = base64_encode(sodium_crypto_sign_publickey($this->releaseKeypair));
        add_filter(self::LEGACY_KEY_FILTER, static fn (): string => $legacyPublic);
        add_filter(self::KEY_RING_FILTER, static fn (): array => [self::KEY_ID => $ringPublic]);
        add_filter(self::RELEASE_KEY_FILTER, static fn (): string => $releasePublic);
    }

    protected function tearDown(): void
    {
        remove_all_filters(self::LEGACY_KEY_FILTER);
        remove_all_filters(self::KEY_RING_FILTER);
        remove_all_filters(self::RELEASE_KEY_FILTER);
        parent::tearDown();
    }

    public function testV2DocumentWithoutUpdateRightsUntilRemainsAccepted(): void
    {
        $service = $this->service();
        $this->installDocument($service, $this->v2Payload($service), $this->ringKeypair);

        $this->assertSame(LicenseStatus::ACTIVE, $service->currentState()['status']);
        $this->assertArrayNotHasKey('update_rights_until', $this->storedPayload());
        $this->assertArrayNotHasKey('update_rights_until', $service->statusMeta());
    }

    public function testValidV2UpdateRightsUntilIsPersistedVerbatimOnlyInExistingPayloadJson(): void
    {
        $service = $this->service();

        foreach ([1893463200, PHP_INT_MAX] as $boundary) {
            $this->installDocument(
                $service,
                $this->v2Payload($service, ['update_rights_until' => $boundary]),
                $this->ringKeypair
            );

            $this->assertSame($boundary, $this->storedPayload()['update_rights_until'] ?? null);
            $this->assertStringContainsString(
                '"update_rights_until":' . $boundary,
                (string) (new LicenseRepository(App::db()))->state()['payload_json']
            );
            $this->assertArrayNotHasKey('update_rights_until', $service->statusMeta());
        }

        global $wpdb;
        $table = App::db()->table('cpms_license_state');
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertContains('payload_json', $columns);
        $this->assertNotContains('update_rights_until', $columns, 'the claim must not have its own column');

        $tables = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s',
                '%update_rights%'
            )
        );
        $this->assertSame([], $tables, 'the claim must not have a dedicated table');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedUpdateRightsUntilValues(): array
    {
        return [
            'boolean true' => [true],
            'boolean false' => [false],
            'string' => ['1893463200'],
            'float' => [1893463200.5],
            'zero' => [0],
            'negative' => [-1],
            'null' => [null],
            'array' => [[]],
            'object-like array' => [['epoch' => 1893463200]],
        ];
    }

    /**
     * @dataProvider malformedUpdateRightsUntilValues
     *
     * @param mixed $boundary
     */
    public function testMalformedV2UpdateRightsUntilFailsClosedWithoutPersistence(mixed $boundary): void
    {
        $service = $this->service();
        $this->assertDocumentRejected(
            $service,
            $this->v2Payload($service, ['update_rights_until' => $boundary]),
            $this->ringKeypair,
            'a present malformed update_rights_until must fail closed'
        );
    }

    public function testLegacyDocumentCannotCarryUpdateRightsUntil(): void
    {
        $service = $this->service();
        $this->assertDocumentRejected(
            $service,
            $this->legacyPayload($service, ['update_rights_until' => 1893463200]),
            $this->legacyKeypair,
            'update_rights_until requires v2 key-ring provenance'
        );
    }

    public function testHealthyLegacyDocumentWithoutUpdateRightsUntilRemainsAccepted(): void
    {
        $service = $this->service();
        $this->installDocument($service, $this->legacyPayload($service), $this->legacyKeypair);

        $this->assertSame(LicenseStatus::ACTIVE, $service->currentState()['status']);
        $this->assertArrayNotHasKey('update_rights_until', $this->storedPayload());
    }

    public function testAddingChangingOrRemovingTheClaimAfterSigningInvalidatesTheDocument(): void
    {
        $service = $this->service();
        $signedWith = $this->v2Payload($service, ['update_rights_until' => 1893463200]);
        $signedWithout = $this->v2Payload($service);

        $changed = $signedWith;
        $changed['update_rights_until'] = 1893463201;
        $removed = $signedWith;
        unset($removed['update_rights_until']);
        $added = $signedWithout;
        $added['update_rights_until'] = 1893463200;

        foreach ([
            'changed after signing' => [$changed, $this->signatureFor($signedWith, $this->ringKeypair)],
            'removed after signing' => [$removed, $this->signatureFor($signedWith, $this->ringKeypair)],
            'added after signing' => [$added, $this->signatureFor($signedWithout, $this->ringKeypair)],
        ] as $label => [$payload, $signature]) {
            try {
                $service->activateWithDocument($this->payloadJson($payload), $signature);
                $this->fail("update_rights_until {$label} must invalidate the signature");
            } catch (LicenseGatewayException $exception) {
                $this->assertSame('CLINIC_LICENSE_INVALID', $exception->apiCode(), $label);
            }
            $this->assertNull((new LicenseRepository(App::db()))->state(), "{$label}: rejected document must not persist");
        }
    }

    public function testOnlineActivationRefreshAndOfflineActivationShareTheClaimContract(): void
    {
        $gateway = new class($this) implements VendorGateway {
            /** @var list<array{action: string, request: array<string, mixed>}> */
            public array $requests = [];

            /** @var mixed */
            public $activationBoundary = 'not-an-integer';

            /** @var mixed */
            public $refreshBoundary = null;

            public function __construct(private readonly LicenseUpdateRightsClaimTest $test)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function activate(array $request): array
            {
                $this->requests[] = ['action' => 'activate', 'request' => $request];

                return $this->test->documentForRequest($request, $this->activationBoundary);
            }

            public function refresh(array $request): array
            {
                $this->requests[] = ['action' => 'refresh', 'request' => $request];

                return $this->test->documentForRequest($request, $this->refreshBoundary);
            }
        };
        $service = $this->service($gateway);
        $repository = new LicenseRepository(App::db());

        try {
            $service->activateWithKey('fixture-key');
            $this->fail('malformed update_rights_until on online activation must be rejected');
        } catch (LicenseGatewayException $exception) {
            $this->assertSame('CLINIC_LICENSE_INVALID', $exception->apiCode());
        }
        $this->assertNull($repository->state(), 'rejected online activation must persist no document');

        $gateway->activationBoundary = 1893463200;
        $this->assertSame(LicenseStatus::ACTIVE, $service->activateWithKey('fixture-key')['status']);
        $this->assertSame(1893463200, $this->storedPayload()['update_rights_until'] ?? null);

        $gateway->refreshBoundary = 1893463201;
        $this->assertSame(LicenseStatus::ACTIVE, $service->refresh()['status']);
        $verifiedPayload = $this->storedPayload();
        $this->assertSame(1893463201, $verifiedPayload['update_rights_until'] ?? null);
        $verifiedState = $this->stateProjection($service);

        $gateway->refreshBoundary = true;
        try {
            $service->refresh();
            $this->fail('malformed update_rights_until on refresh must be rejected');
        } catch (LicenseGatewayException $exception) {
            $this->assertSame('CLINIC_LICENSE_INVALID', $exception->apiCode());
        }
        $this->assertSame($verifiedPayload, $this->storedPayload(), 'rejected refresh must retain the last verified payload');
        $this->assertSame($verifiedState, $this->stateProjection($service), 'rejected refresh must retain the prior local license state');

        $this->installDocument(
            $service,
            $this->v2Payload($service, ['update_rights_until' => 1893463202]),
            $this->ringKeypair
        );
        $this->assertSame(1893463202, $this->storedPayload()['update_rights_until'] ?? null);

        foreach ($gateway->requests as $sent) {
            $this->assertArrayNotHasKey('update_rights_until', $sent['request'], $sent['action'] . ' metadata must not contain the claim');
            $expected = $sent['action'] === 'activate'
                ? ['install_id', 'environment', 'license_key', 'version', 'wp_version', 'php_version', 'domain']
                : ['install_id', 'license_id', 'environment', 'version', 'domain'];
            $this->assertRequestKeys($expected, $sent['request']);
        }
    }

    public function testClaimDoesNotChangeStateEntitlementsGateActivationIdOrDomainBinding(): void
    {
        update_option('home', 'https://clinic-a.example');
        $service = $this->service();
        $payload = $this->v2Payload($service, [
            'domain' => 'clinic-a.example',
            'activation_id' => 'act-rights-claim-test',
            'entitlements' => [
                'features' => ['updates' => false, 'handwriting' => true],
                'limits' => ['doctors' => 3],
            ],
        ]);

        $this->installDocument($service, $payload, $this->ringKeypair);
        $withoutClaim = $this->decisionSnapshot($service);
        $this->assertSame(LicenseStatus::ACTIVE, $withoutClaim['status']);

        $payload['update_rights_until'] = 1893463200;
        $this->installDocument($service, $payload, $this->ringKeypair);
        $withClaim = $this->decisionSnapshot($service);
        $this->assertSame($withoutClaim, $withClaim);
        $this->assertSame('act-rights-claim-test', $this->storedPayload()['activation_id'] ?? null);

        update_option('home', 'https://clinic-b.example');
        unset($payload['update_rights_until']);
        $this->installDocument($service, $payload, $this->ringKeypair);
        $withoutClaimMismatch = $this->decisionSnapshot($service);
        $this->assertSame(LicenseStatus::RESTRICTED, $withoutClaimMismatch['status']);
        $this->assertSame('binding_mismatch', $withoutClaimMismatch['reason']);

        $payload['update_rights_until'] = 1893463200;
        $this->installDocument($service, $payload, $this->ringKeypair);
        $withClaimMismatch = $this->decisionSnapshot($service);
        $this->assertSame($withoutClaimMismatch, $withClaimMismatch);
    }

    public function testClaimDoesNotChangeOrdinaryExpirationSemantics(): void
    {
        $now = time();
        $service = $this->service();
        $payload = $this->v2Payload($service, [
            'issued_at' => $now - 365 * 86400,
            'expires_at' => $now - 10 * 86400,
            'entitlements' => ['features' => ['updates' => false]],
        ]);

        $this->installDocument($service, $payload, $this->ringKeypair);
        $withoutClaim = $this->decisionSnapshot($service);
        $this->assertSame(LicenseStatus::RESTRICTED, $withoutClaim['status']);
        $this->assertSame('expired', $withoutClaim['reason']);
        $this->assertTrue($withoutClaim['needs_renewal']);
        foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $operation) {
            $this->assertTrue($withoutClaim['gate'][$operation]['allowed'], 'ordinary expiration must not block new business');
        }

        $payload['update_rights_until'] = 1893463200;
        $this->installDocument($service, $payload, $this->ringKeypair);
        $withClaim = $this->decisionSnapshot($service);
        $this->assertSame($withoutClaim, $withClaim);
    }

    public function testCurrentUpdateAvailabilityIsUnchangedAndClaimNeverGrantsUpdatesEntitlement(): void
    {
        $service = $this->service();
        $releasePayload = [
            'product' => 'cpms',
            'version' => '9.9.9',
            'channel' => 'stable',
            'package_url' => 'https://updates.example.com/cpms-9.9.9.zip',
            'package_sha256' => str_repeat('d', 64),
            'signed_at' => time(),
        ];
        $releaseSignature = base64_encode(sodium_crypto_sign_detached(
            ReleaseSignature::canonicalJson($releasePayload),
            sodium_crypto_sign_secretkey($this->releaseKeypair)
        ));
        $gateway = new class(['payload' => $releasePayload, 'signature_b64' => $releaseSignature]) implements UpdateMetadataGateway {
            public int $fetchCount = 0;

            public function __construct(private readonly array $document)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function fetch(string $channel): array
            {
                ++$this->fetchCount;

                return $this->document;
            }
        };
        $updates = new UpdateService(App::settings(), $service, $gateway);
        $licensePayload = $this->v2Payload($service, [
            'entitlements' => ['features' => ['updates' => true]],
        ]);

        $this->installDocument($service, $licensePayload, $this->ringKeypair);
        $withoutClaim = $updates->checkForUpdates(force: true);
        $this->assertTrue($withoutClaim['available']);

        $licensePayload['update_rights_until'] = time() - 60;
        $this->installDocument($service, $licensePayload, $this->ringKeypair);
        $withExpiredBoundary = $updates->checkForUpdates(force: true);
        $this->assertTrue($withExpiredBoundary['available'], 'this slice must not enforce the publication-rights boundary');
        unset($withoutClaim['checked_at'], $withExpiredBoundary['checked_at']);
        $this->assertSame($withoutClaim, $withExpiredBoundary);
        $this->assertSame(2, $gateway->fetchCount);

        $licensePayload = $this->v2Payload($service, [
            'entitlements' => ['features' => ['handwriting' => true]],
        ]);
        $this->installDocument($service, $licensePayload, $this->ringKeypair);
        $withoutUpdatesFeature = $updates->checkForUpdates(force: true);
        $this->assertFalse($updates->isUpdateEntitled());
        $this->assertFalse($withoutUpdatesFeature['available']);
        $this->assertSame('not_entitled', $withoutUpdatesFeature['reason']);

        $licensePayload['update_rights_until'] = PHP_INT_MAX;
        $this->installDocument($service, $licensePayload, $this->ringKeypair);
        $withBoundaryWithoutFeature = $updates->checkForUpdates(force: true);
        $this->assertFalse($updates->isUpdateEntitled(), 'update_rights_until must never substitute for entitlements.features.updates');
        $this->assertFalse($withBoundaryWithoutFeature['available']);
        $this->assertSame('not_entitled', $withBoundaryWithoutFeature['reason']);
        unset($withoutUpdatesFeature['checked_at'], $withBoundaryWithoutFeature['checked_at']);
        $this->assertSame($withoutUpdatesFeature, $withBoundaryWithoutFeature);
        $this->assertSame(2, $gateway->fetchCount, 'a claim without the updates feature must not reach the release gateway');
    }

    /**
     * Build a signed fixture for online activation/refresh using the request's install binding.
     *
     * @param array<string, mixed> $request
     * @param mixed $boundary
     * @return array{payload: array<string, mixed>, signature_b64: string}
     */
    public function documentForRequest(array $request, mixed $boundary): array
    {
        $payload = $this->v2PayloadForInstallId((string) ($request['install_id'] ?? ''), [
            'update_rights_until' => $boundary,
        ]);

        return [
            'payload' => $payload,
            'signature_b64' => $this->signatureFor($payload, $this->ringKeypair),
        ];
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
                throw new \RuntimeException('offline fixture gateway must not activate');
            }

            public function refresh(array $request): array
            {
                throw new \RuntimeException('offline fixture gateway must not refresh');
            }
        };

        return new LicenseService(new LicenseRepository(App::db()), $gateway, App::db());
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function legacyPayload(LicenseService $service, array $extra = []): array
    {
        return array_merge([
            'product' => 'cpms',
            'license_id' => 'lic-update-rights-claim',
            'install_id' => $service->installId(),
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => ['updates' => false]],
        ], $extra);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function v2Payload(LicenseService $service, array $extra = []): array
    {
        return $this->v2PayloadForInstallId($service->installId(), $extra);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function v2PayloadForInstallId(string $installId, array $extra = []): array
    {
        return array_merge($this->legacyPayloadForInstallId($installId), [
            'schema_version' => self::SCHEMA_VERSION,
            'key_id' => self::KEY_ID,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyPayloadForInstallId(string $installId): array
    {
        return [
            'product' => 'cpms',
            'license_id' => 'lic-update-rights-claim',
            'install_id' => $installId,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => ['updates' => false]],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signatureFor(array $payload, string $keypair): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($keypair)
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadJson(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Exercise the real offline signed-document ingestion path.
     *
     * @param array<string, mixed> $payload
     */
    private function installDocument(LicenseService $service, array $payload, string $keypair): void
    {
        $service->activateWithDocument($this->payloadJson($payload), $this->signatureFor($payload, $keypair));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertDocumentRejected(LicenseService $service, array $payload, string $keypair, string $why): void
    {
        try {
            $this->installDocument($service, $payload, $keypair);
            $this->fail($why);
        } catch (LicenseGatewayException $exception) {
            $this->assertSame('CLINIC_LICENSE_INVALID', $exception->apiCode(), $why);
        }

        $this->assertNull((new LicenseRepository(App::db()))->state(), 'rejected documents must not be persisted');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storedPayload(): array
    {
        $row = (new LicenseRepository(App::db()))->state();
        $this->assertNotNull($row, 'a verified signed document must be persisted');
        $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function stateProjection(LicenseService $service): array
    {
        $state = $service->currentState();

        return [
            'status' => $state['status'],
            'reason' => $state['reason'],
            'expires_at' => $state['expires_at'],
            'needs_renewal' => $state['needs_renewal'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decisionSnapshot(LicenseService $service): array
    {
        $state = $service->currentState();
        $entitlements = $service->entitlements();
        $gate = new SignedLicenseGate($service);
        $operations = array_merge(
            SignedLicenseGate::BLOCKED_UNDER_RESTRICTION,
            [LicenseGate::OP_PATIENT_UPDATE, LicenseGate::OP_APPOINTMENT_CANCEL]
        );
        $gateDecisions = [];
        foreach ($operations as $operation) {
            $decision = $gate->assert($operation);
            $gateDecisions[$operation] = ['allowed' => $decision->allowed, 'reason' => $decision->reason];
        }

        return [
            'status' => $state['status'],
            'reason' => $state['reason'],
            'needs_renewal' => $state['needs_renewal'],
            'features' => $entitlements->allFeatures(),
            'limits' => $entitlements->allLimits(),
            'gate' => $gateDecisions,
            'read_only' => $gate->isReadOnly(),
            'install_id' => $service->installId(),
        ];
    }

    /**
     * @param list<string> $expected
     * @param array<string, mixed> $request
     */
    private function assertRequestKeys(array $expected, array $request): void
    {
        $actual = array_keys($request);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, 'vendor request metadata keys must remain unchanged');
    }
}
