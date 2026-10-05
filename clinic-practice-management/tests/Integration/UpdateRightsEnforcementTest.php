<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Application\Update\UpdateService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Domain\Update\ReleaseSignature;
use ClinicCore\Infrastructure\Licensing\VendorGateway;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use ClinicCore\Infrastructure\Update\UpdateMetadataGateway;
use ClinicCore\Infrastructure\Update\WpUpdateBridge;
use WP_UnitTestCase;

/**
 * Phase 16 Slice 6B — Version Rights enforcement in the UPDATE plane only, with a
 * signed security-release exception.
 *
 * Contract under test (signed integers only, never the local wall clock):
 *   - no `update_rights_until` claim in the stored verified document ⇒ unchanged behaviour;
 *   - bounded document + ordinary release published at/ before the boundary ⇒ normal decision;
 *   - bounded document + ordinary release published after the boundary ⇒ `update_rights_expired`;
 *   - bounded document + `release_kind=security` after the boundary ⇒ still eligible;
 *   - `security` never bypasses signature/shape/channel/applicability and never grants the
 *     base `updates` entitlement;
 *   - malformed/missing `signed_at` under a bounded document fails closed (`invalid_manifest`);
 *   - unknown/malformed `release_kind` invalidates the manifest; tampering after signing
 *     invalidates the signature; renewal of the boundary is immediately effective.
 *
 * Runs the real `LicenseService` (offline signed-document activation + refresh) and the real
 * `UpdateService::checkForUpdates()` decision path on WP/MySQL, plus the real `WpUpdateBridge`
 * WordPress-facing results. No network: release metadata is a signed fixture.
 */
final class UpdateRightsEnforcementTest extends WP_UnitTestCase
{
    private const SCHEMA_VERSION = 2;
    private const KEY_ID = 'update-rights-enforcement';
    private const LEGACY_KEY_FILTER = 'cpms_license_public_key';
    private const KEY_RING_FILTER = 'cpms_license_public_keys';
    private const RELEASE_KEY_FILTER = 'cpms_release_public_key';

    /** Signed publication timestamps around the boundary (all far from the test clock). */
    private const BOUNDARY = 1893463200;
    private const BOUNDARY_EARLY = 1893463199;
    private const BOUNDARY_LATE = 1893463201;
    private const BOUNDARY_RENEWED = 1952000000;
    private const ANCIENT_BOUNDARY = 1000000000;
    private const ANCIENT_PUBLICATION = 1500000000;
    /** Dedicated boundary for the blocked bridge test (own decision-cache key). */
    private const BOUNDARY_BRIDGE = 1893463300;

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
            $this->markTestSkipped('sodium not available — update-rights enforcement tests need real sodium');
        }

        $this->ringKeypair = sodium_crypto_sign_keypair();
        $this->releaseKeypair = sodium_crypto_sign_keypair();
        $this->issuedAt = time() - 60;
        $this->expiresAt = time() + 30 * 86400;

        add_filter(self::LEGACY_KEY_FILTER, static fn (): string => base64_encode(
            sodium_crypto_sign_publickey($this->ringKeypair)
        ));
        add_filter(self::KEY_RING_FILTER, static fn (): array => [
            self::KEY_ID => base64_encode(sodium_crypto_sign_publickey($this->ringKeypair)),
        ]);
        add_filter(self::RELEASE_KEY_FILTER, static fn (): string => base64_encode(
            sodium_crypto_sign_publickey($this->releaseKeypair)
        ));
    }

    protected function tearDown(): void
    {
        remove_all_filters(self::LEGACY_KEY_FILTER);
        remove_all_filters(self::KEY_RING_FILTER);
        remove_all_filters(self::RELEASE_KEY_FILTER);
        parent::tearDown();
    }

    // ==================== 1. no claim ⇒ unchanged ====================

    public function testDocumentWithoutTheClaimKeepsTheExistingUpdateBehaviour(): void
    {
        $service = $this->service();
        $this->install($service, $this->licensePayload($service));
        $this->assertArrayNotHasKey('update_rights_until', $this->storedPayload());

        $updates = $this->updates($service, $this->document($this->releasePayload()));

        $result = $this->check($updates);
        $this->assertTrue($result['available'], 'an unbounded document must keep the current decision');
        $this->assertSame('9.9.9', $result['version']);
        $this->assertSame('', $result['reason']);

        // A publication far after any conceivable boundary is still offered: no claim ⇒ no boundary.
        $late = $this->updates($service, $this->document($this->releasePayload([
            'signed_at' => self::BOUNDARY_RENEWED + 86400,
        ])));
        $this->assertTrue($this->check($late)['available']);
    }

    public function testUnboundedDocumentPreservesLegacyManifestCompatibility(): void
    {
        $service = $this->service();
        $this->install($service, $this->licensePayload($service));

        // No `signed_at` at all — valid today, and remains valid without a rights claim.
        $withoutSignedAt = $this->releasePayload();
        unset($withoutSignedAt['signed_at']);
        $this->assertTrue($this->check($this->updates($service, $this->document($withoutSignedAt)))['available']);

        // Numeric string `signed_at` — accepted today, and still accepted without a rights claim.
        $numericString = $this->releasePayload(['signed_at' => (string) self::BOUNDARY_LATE]);
        $result = $this->check($this->updates($service, $this->document($numericString)));
        $this->assertTrue($result['available']);
    }

    // ==================== 2. bounded + ordinary ⇒ compare signed integers ====================

    public function testManifestWithoutReleaseKindIsTreatedAsNormalAfterTheBoundary(): void
    {
        $service = $this->service();
        $this->installBoundary($service, self::BOUNDARY);

        $payload = $this->releasePayload(['signed_at' => self::BOUNDARY_LATE]);
        $this->assertArrayNotHasKey('release_kind', $payload);

        $result = $this->check($this->updates($service, $this->document($payload)));
        $this->assertFalse($result['available']);
        $this->assertSame('update_rights_expired', $result['reason']);
    }

    public function testOrdinaryReleaseAtOrBeforeTheBoundaryIsAvailable(): void
    {
        $service = $this->service();
        $this->installBoundary($service, self::BOUNDARY);

        foreach ([self::BOUNDARY_EARLY, self::BOUNDARY] as $signedAt) {
            $result = $this->check($this->updates($service, $this->document($this->releasePayload([
                'release_kind' => 'normal',
                'signed_at' => $signedAt,
            ]))));
            $this->assertTrue($result['available'], 'signed_at=' . $signedAt . ' must stay within the boundary');
            $this->assertSame('9.9.9', $result['version']);
        }
    }

    public function testOrdinaryReleaseAfterTheBoundaryIsNotOffered(): void
    {
        $service = $this->service();
        $this->installBoundary($service, self::BOUNDARY);

        $result = $this->check($this->updates($service, $this->document($this->releasePayload([
            'release_kind' => 'normal',
            'signed_at' => self::BOUNDARY_LATE,
        ]))));

        $this->assertFalse($result['available']);
        $this->assertSame('update_rights_expired', $result['reason']);
        $this->assertArrayNotHasKey('version', $result, 'a rights-blocked release must expose no offer');
        $this->assertArrayNotHasKey('package_url', $result);
        $this->assertArrayNotHasKey('package_sha256', $result);
    }

    // ==================== 3. bounded + security ⇒ exception only at the boundary ====================

    public function testSecurityReleaseAfterTheBoundaryRemainsEligible(): void
    {
        $service = $this->service();
        $this->installBoundary($service, self::BOUNDARY);

        $result = $this->check($this->updates($service, $this->document($this->releasePayload([
            'release_kind' => 'security',
            'signed_at' => self::BOUNDARY_LATE,
        ]))));

        $this->assertTrue($result['available']);
        $this->assertSame('9.9.9', $result['version']);
        $this->assertSame(str_repeat('d', 64), $result['package_sha256']);
    }

    public function testSecurityReleaseAtOrBeforeTheBoundaryIsEligibleNormally(): void
    {
        $service = $this->service();
        $this->installBoundary($service, self::BOUNDARY);

        $result = $this->check($this->updates($service, $this->document($this->releasePayload([
            'release_kind' => 'security',
            'signed_at' => self::BOUNDARY_EARLY,
        ]))));

        $this->assertTrue($result['available']);
    }

    public function testSecurityReleaseWithoutAnyClaimChangesNothing(): void
    {
        $service = $this->service();
        $this->install($service, $this->licensePayload($service));

        $result = $this->check($this->updates($service, $this->document($this->releasePayload([
            'release_kind' => 'security',
            'signed_at' => self::BOUNDARY_RENEWED,
        ]))));

        $this->assertTrue($result['available'], 'security is exceptional only for the rights boundary');
    }

    // ==================== 4. closed enum + fail-closed structure ====================

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedReleaseKindValues(): array
    {
        return [
            'uppercase security keyword' => ['SECURITY'],
            'trailing whitespace' => ['security '],
            'leading whitespace' => [' security'],
            'unknown kind' => ['critical'],
            'misspelled kind' => ['securty'],
            'empty string' => [''],
            'boolean true' => [true],
            'integer' => [1],
            'float' => [1.0],
            'null' => [null],
            'list' => [['security']],
            'map' => [['kind' => 'security']],
        ];
    }

    /**
     * @dataProvider malformedReleaseKindValues
     *
     * @param mixed $releaseKind
     */
    public function testUnknownOrMalformedReleaseKindInvalidatesTheManifest(mixed $releaseKind): void
    {
        $updates = $this->updates($this->service(), null, false);

        // Context-free: the structural rule must fail closed with or without a bounded document.
        foreach ([null, self::BOUNDARY] as $boundary) {
            $payload = $this->releasePayload(['release_kind' => $releaseKind]);
            $result = $updates->evaluateManifest(
                $payload,
                $this->document($payload)['signature_b64'],
                'stable',
                '1.0.0',
                '6.7',
                '8.2'
            );
            $label = $boundary === null ? 'unbounded' : 'bounded';
            $this->assertFalse($result['available'], $label . ' manifest must fail closed');
            $this->assertSame('invalid_manifest', $result['reason'], $label);
        }
    }

    public function testAbsentReleaseKindIsAcceptedAsNormal(): void
    {
        $payload = $this->releasePayload();
        $this->assertArrayNotHasKey('release_kind', $payload);

        $service = $this->updates($this->service(), null, false);
        $result = $service->evaluateManifest(
            $payload,
            $this->document($payload)['signature_b64'],
            'stable',
            '1.0.0',
            '6.7',
            '8.2'
        );

        $this->assertTrue($result['available'], 'absent release_kind must behave as normal');
    }

    public function testTamperedReleaseKindAfterSigningInvalidatesTheSignature(): void
    {
        $service = $this->updates($this->service(), null, false);

        $normal = $this->releasePayload(['release_kind' => 'normal']);
        $security = $this->releasePayload(['release_kind' => 'security']);

        foreach ([
            'normal → security' => [$security, $this->document($normal)['signature_b64']],
            'security → normal' => [$normal, $this->document($security)['signature_b64']],
        ] as $label => [$tampered, $signature]) {
            $result = $service->evaluateManifest($tampered, $signature, 'stable', '1.0.0', '6.7', '8.2');
            $this->assertFalse($result['available'], $label . ' must not verify');
            $this->assertSame('invalid_signature', $result['reason'], $label);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableSignedAtShapes(): array
    {
        return [
            'absent' => ['absent'],
            'numeric string' => ['1893463200'],
            'float' => ['1893463200.5'],
            'null' => ['null'],
            'boolean' => ['true'],
            'list' => ['list'],
        ];
    }

    /**
     * @dataProvider unusableSignedAtShapes
     *
     * @param string $shape
     */
    public function testUnusableSignedAtFailsClosedUnderABoundedDocument(string $shape): void
    {
        $service = $this->service();
        $this->installBoundary($service, self::BOUNDARY);
        $payload = $this->releasePayload();

        unset($payload['signed_at']);
        if ($shape === 'numeric string') {
            $payload['signed_at'] = (string) self::BOUNDARY_LATE;
        } elseif ($shape === 'float') {
            $payload['signed_at'] = (float) self::BOUNDARY_LATE + 0.5;
        } elseif ($shape === 'null') {
            $payload['signed_at'] = null;
        } elseif ($shape === 'boolean') {
            $payload['signed_at'] = true;
        } elseif ($shape === 'list') {
            $payload['signed_at'] = [];
        }

        $result = $this->check($this->updates($service, $this->document($payload)));
        $this->assertFalse($result['available'], 'a bounded document must not decide without a usable signed_at');
        $this->assertSame('invalid_manifest', $result['reason']);
    }

    // ==================== 5. security never overrides an earlier failure ====================

    public function testSecurityReleaseCannotBypassSignatureStructureChannelOrApplicability(): void
    {
        $service = $this->service();
        $this->installBoundary($service, self::BOUNDARY);
        $security = $this->releasePayload(['release_kind' => 'security', 'signed_at' => self::BOUNDARY_LATE]);
        $otherKeypair = sodium_crypto_sign_keypair();

        $cases = [
            'wrong release key' => [
                $security,
                $this->document($security, $otherKeypair)['signature_b64'],
                'invalid_signature',
            ],
            'wrong product' => [
                array_merge($security, ['product' => 'other-product']),
                null,
                'invalid_manifest',
            ],
            'bad package hash' => [
                array_merge($security, ['package_sha256' => 'short']),
                null,
                'invalid_manifest',
            ],
            'channel mismatch' => [
                array_merge($security, ['channel' => 'beta']),
                null,
                'channel_mismatch',
            ],
            'already installed version' => [
                array_merge($security, ['version' => '1.0.0']),
                null,
                'not_applicable',
            ],
            'unsupported PHP' => [
                array_merge($security, ['min_php_version' => '99.0']),
                null,
                'not_applicable',
            ],
        ];

        foreach ($cases as $label => [$payload, $signature, $expectedReason]) {
            $signature ??= $this->document($payload)['signature_b64'];
            $result = $this->check($this->updates($service, ['payload' => $payload, 'signature_b64' => $signature]));
            $this->assertFalse($result['available'], $label . ' must stay unavailable');
            $this->assertSame($expectedReason, $result['reason'], $label);
        }
    }

    // ==================== 6. base entitlement stays the gate ====================

    public function testMissingUpdatesFeatureStaysNotEntitledForARenewedSecurityRelease(): void
    {
        $service = $this->service();
        $this->install($service, $this->licensePayload($service, [
            'entitlements' => ['features' => ['handwriting' => true]],
            'update_rights_until' => PHP_INT_MAX,
        ]));

        $gateway = new RecordingUpdateGateway($this->document($this->releasePayload([
            'release_kind' => 'security',
            'signed_at' => self::BOUNDARY_RENEWED,
        ])));
        $updates = new UpdateService(App::settings(), $service, $gateway);

        $this->assertFalse($updates->isUpdateEntitled(), 'the rights claim must never grant the updates feature');
        $result = $updates->checkForUpdates(true, 'stable');

        $this->assertFalse($result['available']);
        $this->assertSame('not_entitled', $result['reason']);
        $this->assertSame(0, $gateway->fetchCount, 'an unentitled install must not reach the release gateway');
    }

    // ==================== 7. runtime non-encroachment ====================

    public function testOrdinaryExpirationFollowsTheRightsDecisionWhileTheClinicalGateStaysUnfrozen(): void
    {
        $now = time();
        $service = $this->service();
        $this->install($service, $this->licensePayload($service, [
            'issued_at' => $now - 365 * 86400,
            'expires_at' => $now - 10 * 86400,
            'update_rights_until' => self::BOUNDARY_LATE,
        ]));

        $state = $service->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
        $this->assertSame('expired', $state['reason']);
        $this->assertTrue($state['needs_renewal']);

        $gate = new SignedLicenseGate($service);
        foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $operation) {
            $this->assertTrue(
                $gate->assert($operation)->allowed,
                'ordinary expiration must not freeze new clinical business'
            );
        }

        $result = $this->check($this->updates($service, $this->document($this->releasePayload([
            'release_kind' => 'normal',
            'signed_at' => self::BOUNDARY,
        ]))));
        $this->assertTrue($result['available'], 'an expired commercial term must not block an entitled publication');

        // The boundary still applies to the update plane after ordinary expiration.
        $blocked = $this->check($this->updates($service, $this->document($this->releasePayload([
            'release_kind' => 'normal',
            'signed_at' => self::BOUNDARY_LATE,
        ]))));
        $this->assertFalse($blocked['available']);
        $this->assertSame('update_rights_expired', $blocked['reason']);
        $this->assertTrue($gate->assert(SignedLicenseGate::BLOCKED_UNDER_RESTRICTION[0])->allowed);
    }

    public function testTheRightsDecisionUsesSignedIntegersOnlyAndNoLocalClock(): void
    {
        $service = $this->service();

        // Publication far in the local future but inside the boundary ⇒ eligible.
        $this->installBoundary($service, self::BOUNDARY);
        $inTheFuture = $this->check($this->updates($service, $this->document($this->releasePayload([
            'release_kind' => 'normal',
            'signed_at' => self::BOUNDARY_EARLY,
        ]))));
        $this->assertTrue($inTheFuture['available'], 'a local clock must not veto a signed publication inside the boundary');

        // Publication long before the local clock but after the boundary ⇒ blocked.
        $this->installBoundary($service, self::ANCIENT_BOUNDARY);
        $longAgo = $this->check($this->updates($service, $this->document($this->releasePayload([
            'release_kind' => 'normal',
            'signed_at' => self::ANCIENT_PUBLICATION,
        ]))));
        $this->assertFalse($longAgo['available'], 'a local clock must not rescue a publication after the boundary');
        $this->assertSame('update_rights_expired', $longAgo['reason']);
    }

    // ==================== 8. renewal ====================

    public function testRenewedLaterBoundaryMakesTheSameOrdinaryReleaseEligibleAgain(): void
    {
        $service = $this->service();
        $this->installBoundary($service, self::BOUNDARY);

        $release = $this->releasePayload(['release_kind' => 'normal', 'signed_at' => self::BOUNDARY_LATE]);
        $gateway = new RecordingUpdateGateway($this->document($release));
        $updates = new UpdateService(App::settings(), $service, $gateway);

        $blocked = $updates->checkForUpdates(true, 'stable');
        $this->assertFalse($blocked['available']);
        $this->assertSame('update_rights_expired', $blocked['reason']);

        // Renewal = a newly signed local document with a later boundary, through the real
        // refresh path; no migration, no local reconciliation, no schema change.
        $refreshed = new class($this, self::BOUNDARY_RENEWED) implements VendorGateway {
            public function __construct(
                private readonly UpdateRightsEnforcementTest $test,
                private readonly int $boundary
            ) {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function activate(array $request): array
            {
                throw new \RuntimeException('renewal fixture must not activate');
            }

            public function refresh(array $request): array
            {
                return $this->test->documentForRequest($request, $this->boundary);
            }
        };
        $renewedService = new LicenseService(new LicenseRepository(App::db()), $refreshed, App::db());
        $this->assertSame(LicenseStatus::ACTIVE, $renewedService->refresh()['status']);
        $this->assertSame(self::BOUNDARY_RENEWED, $this->storedPayload()['update_rights_until'] ?? null);

        // Same release, same cached-check code path (non-forced), newly eligible.
        $eligible = $updates->checkForUpdates(false, 'stable');
        $this->assertTrue($eligible['available'], 'the later signed boundary must permit the previously blocked release');
        $this->assertSame('9.9.9', $eligible['version']);
    }

    // ==================== 9. WordPress-facing results ====================

    public function testWpUpdateBridgeDoesNotOfferOrDownloadARightsBlockedRelease(): void
    {
        $service = $this->service();
        $this->installBoundary($service, self::BOUNDARY_BRIDGE);

        $release = $this->releasePayload([
            'release_kind' => 'normal',
            'signed_at' => self::BOUNDARY_BRIDGE + 1,
        ]);
        $gateway = new RecordingUpdateGateway($this->document($release));
        $updates = new UpdateService(App::settings(), $service, $gateway);
        $bridge = new WpUpdateBridge(static fn (): UpdateService => $updates);

        $basename = (string) plugin_basename(CPMS_PLUGIN_FILE);
        $this->assertNotSame('', $basename);

        $transient = new \stdClass();
        $transient->response = [];
        $this->assertSame($transient, $bridge->injectUpdatePlugins($transient));
        $this->assertSame([], $transient->response, 'a rights-blocked release must not enter the WP update list');

        $this->assertNull(
            $bridge->injectPluginInfo(null, 'plugin_information', (object) ['slug' => WpUpdateBridge::PLUGIN_SLUG]),
            'a rights-blocked release must not expose plugin details'
        );

        $reply = $bridge->verifyPackageBeforeInstall(
            null,
            (string) $release['package_url'],
            null,
            ['plugin' => $basename]
        );
        $this->assertInstanceOf(\WP_Error::class, $reply);
        $this->assertSame('CLINIC_UPDATE_UNAVAILABLE', $reply->get_error_code());
        $this->assertSame(2, $gateway->fetchCount, 'each WordPress-facing read must re-confirm the blocked decision');
    }

    public function testWpUpdateBridgeStillOffersAnEligibleRelease(): void
    {
        $service = $this->service();
        $this->installBoundary($service, self::BOUNDARY_EARLY);

        $release = $this->releasePayload(['release_kind' => 'security', 'signed_at' => self::BOUNDARY_LATE]);
        $gateway = new RecordingUpdateGateway($this->document($release));
        $updates = new UpdateService(App::settings(), $service, $gateway);
        $bridge = new WpUpdateBridge(static fn (): UpdateService => $updates);

        $basename = (string) plugin_basename(CPMS_PLUGIN_FILE);
        $transient = new \stdClass();
        $transient->response = [];
        $bridge->injectUpdatePlugins($transient);

        $this->assertArrayHasKey($basename, $transient->response);
        $this->assertSame('9.9.9', $transient->response[$basename]->new_version);
        $this->assertSame((string) $release['package_url'], $transient->response[$basename]->package);
    }

    // ==================== helpers ====================

    /**
     * Exercise the real offline signed-document ingestion path.
     *
     * @param array<string, mixed> $payload
     */
    private function install(LicenseService $service, array $payload): void
    {
        $service->activateWithDocument(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            $this->signatureFor($payload)
        );
    }

    private function installBoundary(LicenseService $service, int $boundary): void
    {
        $this->install($service, $this->licensePayload($service, ['update_rights_until' => $boundary]));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function licensePayload(LicenseService $service, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'key_id' => self::KEY_ID,
            'product' => 'cpms',
            'license_id' => 'lic-update-rights-enforcement',
            'install_id' => $service->installId(),
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => ['updates' => true]],
        ], $extra);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function releasePayload(array $overrides = []): array
    {
        return array_merge([
            'product' => 'cpms',
            'version' => '9.9.9',
            'channel' => 'stable',
            'package_url' => 'https://updates.example.com/cpms-9.9.9.zip',
            'package_sha256' => str_repeat('d', 64),
            'signed_at' => self::BOUNDARY_EARLY,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{payload: array<string, mixed>, signature_b64: string}
     */
    private function document(array $payload, ?string $keypair = null): array
    {
        return [
            'payload' => $payload,
            'signature_b64' => base64_encode(sodium_crypto_sign_detached(
                ReleaseSignature::canonicalJson($payload),
                sodium_crypto_sign_secretkey($keypair ?? $this->releaseKeypair)
            )),
        ];
    }

    /**
     * Fixture gateway used by the renewal test (real VendorGateway contract).
     *
     * @param array<string, mixed> $request
     * @return array{payload: array<string, mixed>, signature_b64: string}
     */
    public function documentForRequest(array $request, int $boundary): array
    {
        $service = $this->service();
        $payload = $this->licensePayload($service, [
            'license_id' => (string) ($request['license_id'] ?? 'lic-update-rights-enforcement'),
            'install_id' => (string) ($request['install_id'] ?? ''),
            'update_rights_until' => $boundary,
        ]);

        return ['payload' => $payload, 'signature_b64' => $this->signatureFor($payload)];
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
     * @param array{payload: array<string, mixed>, signature_b64: string}|null $document
     */
    private function updates(LicenseService $service, ?array $document, bool $configured = true): UpdateService
    {
        return new UpdateService(App::settings(), $service, new RecordingUpdateGateway($document, $configured));
    }

    /**
     * @return array<string, mixed>
     */
    private function check(UpdateService $updates): array
    {
        return $updates->checkForUpdates(true, 'stable');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signatureFor(array $payload): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->ringKeypair)
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function storedPayload(): array
    {
        $row = (new LicenseRepository(App::db()))->state();
        $this->assertNotNull($row, 'a verified signed document must be persisted');
        $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}

/**
 * Release-metadata gateway fixture with a bounded fetch counter (no network).
 */
final class RecordingUpdateGateway implements UpdateMetadataGateway
{
    public int $fetchCount = 0;

    /**
     * @param array{payload: array<string, mixed>, signature_b64: string}|null $document
     */
    public function __construct(private readonly ?array $document, private readonly bool $configured = true)
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * @return array{payload: array<string, mixed>, signature_b64: string}
     */
    public function fetch(string $channel): array
    {
        ++$this->fetchCount;
        if ($this->document === null) {
            throw new \RuntimeException('no release document fixture');
        }

        return $this->document;
    }
}
