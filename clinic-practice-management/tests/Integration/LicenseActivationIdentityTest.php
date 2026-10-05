<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Infrastructure\Licensing\LicenseGatewayException;
use ClinicCore\Infrastructure\Licensing\VendorGateway;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use WP_UnitTestCase;

/**
 * Phase 16 Slice 4 — signed activation identity (`activation_id`) for the
 * commercial rule "one standard License has at most one active production
 * activation at a time".
 *
 * Client-boundary contract only. The central license service is NOT
 * implemented here: it alone issues/supersedes activation records and knows
 * whether another production activation exists. CPMS verifies and stores the
 * signed claim and never counts activations from local state.
 *
 *  - A v2 signed document (schema_version 2 + key_id) MAY carry
 *    `activation_id` = the central activation RECORD identifier. It is not the
 *    installation identity: `install_id` stays mandatory and independently
 *    verified, and the signed `domain` binding stays independently enforced.
 *  - Present ⇒ string, 1..64 bytes, conservative ASCII identifier grammar
 *    (`[A-Za-z0-9][A-Za-z0-9._-]*`). Anything else fails closed as
 *    `CLINIC_LICENSE_INVALID` and persists nothing.
 *  - Absent on v2 ⇒ accepted exactly as before (bounded compatibility rule:
 *    already-accepted v2 documents and fixtures carry no activation record
 *    because the central service does not issue them yet).
 *  - Legacy (unversioned) documents predate the claim ⇒ presence is rejected.
 *  - The value is inside the signed canonical payload (tamper ⇒ invalid) and
 *    is stored only through the existing payload_json (no new table/column).
 *  - Online activation, refresh and offline activation share the contract.
 *  - No status, entitlement or gate decision depends on it, and it is never
 *    echoed to the vendor (request allowlist unchanged).
 *
 * Runs only in CI (WP + MySQL + sodium). Test keys are generated here; the
 * product receives only their public keys through the existing verifier hooks.
 */
final class LicenseActivationIdentityTest extends WP_UnitTestCase
{
    private const LEGACY_KEY_FILTER = 'cpms_license_public_key';
    private const KEY_RING_FILTER = 'cpms_license_public_keys';
    private const SCHEMA_VERSION = 2;
    private const KEY_ID = 'release-a';
    private const VALID_ACTIVATION_ID = 'act_01J9Z4K4M0Q8X3V7P2N6B5C1D9';

    /** Refresh request keys already allowed by HttpVendorGateway (ADR-0028 §2). */
    private const REFRESH_REQUEST_KEYS = ['install_id', 'license_id', 'environment', 'version', 'domain'];

    private string $legacyKeypair = '';
    private string $ringKeypair = '';
    private int $issuedAt = 0;
    private int $expiresAt = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available — signature tests need real sodium');
        }

        $this->legacyKeypair = sodium_crypto_sign_keypair();
        $this->ringKeypair = sodium_crypto_sign_keypair();
        $this->issuedAt = time() - 60;
        $this->expiresAt = time() + 30 * 86400;

        $legacyPublic = base64_encode(sodium_crypto_sign_publickey($this->legacyKeypair));
        $ringPublic = base64_encode(sodium_crypto_sign_publickey($this->ringKeypair));

        // Existing single-key hook = explicit legacy verifier source.
        add_filter(self::LEGACY_KEY_FILTER, static fn (): string => $legacyPublic);
        // The v2 ring is trusted CPMS configuration, independent of document/request data.
        add_filter(self::KEY_RING_FILTER, static fn (): array => [self::KEY_ID => $ringPublic]);
    }

    protected function tearDown(): void
    {
        remove_all_filters(self::LEGACY_KEY_FILTER);
        remove_all_filters(self::KEY_RING_FILTER);
        parent::tearDown();
    }

    // =========================================================================
    // A) Valid claim: accepted, stored through the existing signed payload only
    // =========================================================================

    public function testValidActivationIdIsAcceptedAndStoredInsideTheExistingSignedPayload(): void
    {
        $service = $this->service();

        foreach ([
            self::VALID_ACTIVATION_ID,
            '7f9c2e1a-4b3d-4c5e-9f0a-1b2c3d4e5f60', // digit-leading UUID: an opaque vendor record id
            'A.b_c-9',
            '1',
            str_repeat('a', 64), // exact upper bound
        ] as $activationId) {
            $this->installDocument($service, $this->v2Payload($service, ['activation_id' => $activationId]), $this->ringKeypair);

            $this->assertSame(LicenseStatus::ACTIVE, $service->currentState()['status'], "valid activation_id {$activationId} must be accepted");
            $this->assertSame(
                $activationId,
                $this->storedPayload()['activation_id'] ?? null,
                'the signed claim must be persisted through the existing payload_json'
            );
        }

        // No migration: the claim lives only inside payload_json — no dedicated column (and no second table).
        global $wpdb;
        $table = App::db()->table('cpms_license_state');
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertContains('payload_json', $columns);
        $this->assertNotContains('activation_id', $columns, 'activation_id must not get its own column/table');
    }

    public function testAbsentActivationIdOnV2KeepsAcceptanceAndIdenticalLocalDecisions(): void
    {
        $service = $this->service();
        $gate = new SignedLicenseGate($service);

        // Bounded compatibility rule: v2 without an activation record stays accepted.
        $this->installDocument($service, $this->v2Payload($service), $this->ringKeypair);
        $without = $this->localDecisions($service, $gate);
        $this->assertSame(LicenseStatus::ACTIVE, $without['status']);
        $this->assertArrayNotHasKey('activation_id', $this->storedPayload());

        // Presence of the handle is NOT local authority: nothing in status/entitlements/gate changes.
        $this->installDocument($service, $this->v2Payload($service, ['activation_id' => self::VALID_ACTIVATION_ID]), $this->ringKeypair);
        $with = $this->localDecisions($service, $gate);

        $this->assertSame($without, $with, 'activation_id must not change status, reason, entitlements or any gate decision');
        foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
            $this->assertTrue($with['gate'][$op], "{$op} must stay allowed — no new clinical lock may hinge on activation_id");
        }
    }

    // =========================================================================
    // B) Malformed claim fails closed (string only, bounded, ASCII grammar)
    // =========================================================================

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedActivationIds(): array
    {
        return [
            'empty string' => [''],
            'integer' => [7],
            'float' => [7.5],
            'boolean' => [true],
            'null' => [null],
            'empty array' => [[]],
            'object-like array' => [['id' => 'act-1']],
            'oversized (65 bytes)' => [str_repeat('a', 65)],
            'leading space' => [' act-1'],
            'trailing space' => ['act-1 '],
            'inner space' => ['act 1'],
            'slash' => ['act/1'],
            'colon' => ['act:1'],
            'at sign' => ['act_1@vendor'],
            'leading dot' => ['.act-1'],
            'leading hyphen' => ['-act-1'],
            'leading underscore' => ['_act-1'],
            'non-ascii' => ['açt-1'],
            'trailing newline' => ["act-1\n"],
        ];
    }

    /**
     * @dataProvider malformedActivationIds
     *
     * @param mixed $activationId
     */
    public function testMalformedActivationIdOnV2FailsClosedAndPersistsNothing($activationId): void
    {
        $service = $this->service();

        $this->assertDocumentRejected(
            $service,
            $this->v2Payload($service, ['activation_id' => $activationId]),
            $this->ringKeypair,
            'a present but malformed activation_id must fail closed'
        );
    }

    public function testLegacyDocumentCarryingActivationIdIsRejected(): void
    {
        $service = $this->service();

        // The claim belongs to the v2 format; the bounded legacy path predates it.
        $this->assertDocumentRejected(
            $service,
            $this->legacyPayload($service, ['activation_id' => self::VALID_ACTIVATION_ID]),
            $this->legacyKeypair,
            'legacy (unversioned) documents must not carry activation_id'
        );

        // Control: the legacy path itself is healthy for the pre-slice shape.
        $this->installDocument($service, $this->legacyPayload($service), $this->legacyKeypair);
        $this->assertSame(LicenseStatus::ACTIVE, $service->currentState()['status']);
        $this->assertArrayNotHasKey('activation_id', $this->storedPayload());
    }

    // =========================================================================
    // C) Signed canonical payload: tampering after signature invalidates
    // =========================================================================

    public function testActivationIdTamperedAfterSigningInvalidatesTheDocument(): void
    {
        $service = $this->service();
        $repo = new LicenseRepository(App::db());

        $signedWith = $this->v2Payload($service, ['activation_id' => 'act-signed-1']);
        $signatureWith = $this->signatureFor($signedWith, $this->ringKeypair);

        $signedWithout = $this->v2Payload($service);
        $signatureWithout = $this->signatureFor($signedWithout, $this->ringKeypair);

        $changed = $signedWith;
        $changed['activation_id'] = 'act-signed-2';

        $removed = $signedWith;
        unset($removed['activation_id']);

        $added = $signedWithout;
        $added['activation_id'] = 'act-signed-1';

        foreach ([
            'changed after signing' => [$changed, $signatureWith],
            'removed after signing' => [$removed, $signatureWith],
            'added after signing' => [$added, $signatureWithout],
        ] as $label => [$payload, $signature]) {
            try {
                $service->activateWithDocument((string) json_encode($payload, JSON_UNESCAPED_UNICODE), $signature);
                $this->fail("activation_id {$label} must invalidate the signed document");
            } catch (LicenseGatewayException $exception) {
                $this->assertSame('CLINIC_LICENSE_INVALID', $exception->apiCode(), $label);
            }
            $this->assertNull($repo->state(), "{$label}: nothing may be persisted");
        }
    }

    // =========================================================================
    // D) Independent bindings stay independent
    // =========================================================================

    public function testInstallIdMismatchIsStillRejectedWithAValidActivationId(): void
    {
        $service = $this->service();

        $this->assertDocumentRejected(
            $service,
            $this->v2Payload($service, [
                'install_id' => str_repeat('b', 32),
                'activation_id' => self::VALID_ACTIVATION_ID,
            ]),
            $this->ringKeypair,
            'activation_id must never substitute for install_id verification'
        );
    }

    public function testDomainMismatchIsStillEnforcedWithAValidActivationId(): void
    {
        update_option('home', 'https://clinic-a.example');
        $service = $this->service();
        $gate = new SignedLicenseGate($service);

        $this->installDocument($service, $this->v2Payload($service, [
            'domain' => 'clinic-b.example',
            'activation_id' => self::VALID_ACTIVATION_ID,
        ]), $this->ringKeypair);

        $state = $service->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
        $this->assertSame('binding_mismatch', $state['reason']);
        $this->assertFalse($gate->assert(LicenseGate::OP_PATIENT_CREATE)->allowed, 'a valid activation_id must not relax the domain binding');
        $this->assertTrue($gate->assert(LicenseGate::OP_PATIENT_UPDATE)->allowed, 'existing-data hygiene stays open');

        // The same document on its bound domain is normal again (document untouched).
        update_option('home', 'https://www.Clinic-B.example');
        $this->assertSame(LicenseStatus::ACTIVE, $service->currentState()['status']);
        $this->assertSame(self::VALID_ACTIVATION_ID, $this->storedPayload()['activation_id'] ?? null);
    }

    // =========================================================================
    // E) Entry-point parity: online activation, refresh, offline document
    // =========================================================================

    public function testOnlineActivationRefreshAndOfflineActivationShareTheContract(): void
    {
        $gateway = new class($this) implements VendorGateway {
            /** @var list<array{action: string, request: array<string, mixed>}> */
            public array $requests = [];

            /** @var mixed */
            public $refreshActivationId = 'act-online-2';

            public function __construct(private readonly LicenseActivationIdentityTest $test)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function activate(array $request): array
            {
                $this->requests[] = ['action' => 'activate', 'request' => $request];

                return $this->test->documentForRequest($request, 'act-online-1');
            }

            public function refresh(array $request): array
            {
                $this->requests[] = ['action' => 'refresh', 'request' => $request];

                return $this->test->documentForRequest($request, $this->refreshActivationId);
            }
        };
        $service = $this->service($gateway);

        // 1) Online activation: the central service issues activation record act-online-1.
        $this->assertSame(LicenseStatus::ACTIVE, $service->activateWithKey('fixture-key')['status']);
        $this->assertSame('act-online-1', $this->storedPayload()['activation_id'] ?? null);

        // 2) Refresh: the central service supersedes the record for this installation;
        //    the newer signed handle is stored through the same path.
        $this->assertSame(LicenseStatus::ACTIVE, $service->refresh()['status']);
        $this->assertSame('act-online-2', $this->storedPayload()['activation_id'] ?? null);

        // 3) A malformed handle on refresh fails closed and never overwrites the verified document.
        $gateway->refreshActivationId = 7;
        try {
            $service->refresh();
            $this->fail('a malformed activation_id from the vendor must be rejected on refresh');
        } catch (LicenseGatewayException $exception) {
            $this->assertSame('CLINIC_LICENSE_INVALID', $exception->apiCode());
        }
        $this->assertSame('act-online-2', $this->storedPayload()['activation_id'] ?? null, 'the previously verified claim must survive a rejected refresh');
        $this->assertSame(LicenseStatus::ACTIVE, $service->currentState()['status'], 'a rejected refresh must not degrade the cached verified state');

        // 4) Offline signed document: same verification path, same storage.
        $this->installDocument($service, $this->v2Payload($service, ['activation_id' => 'act-offline-3']), $this->ringKeypair);
        $this->assertSame('act-offline-3', $this->storedPayload()['activation_id'] ?? null);

        // 5) The handle is never echoed to the vendor: request metadata stays within the existing allowlist.
        $this->assertCount(3, $gateway->requests);
        foreach ($gateway->requests as $sent) {
            $this->assertArrayNotHasKey('activation_id', $sent['request'], $sent['action'] . ' must not send activation_id');
        }
        $refreshKeys = array_keys($gateway->requests[1]['request']);
        sort($refreshKeys);
        $expectedKeys = self::REFRESH_REQUEST_KEYS;
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $refreshKeys, 'refresh must send exactly the existing allowlisted metadata');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Vendor fixture: a v2 document for the request's install, carrying the given activation handle.
     *
     * @param array<string, mixed> $request
     * @param mixed $activationId
     * @return array{payload: array<string, mixed>, signature_b64: string}
     */
    public function documentForRequest(array $request, $activationId): array
    {
        $payload = [
            'product' => 'cpms',
            'license_id' => 'lic-activation-identity',
            'install_id' => (string) ($request['install_id'] ?? ''),
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => ['updates' => false]],
            'schema_version' => self::SCHEMA_VERSION,
            'key_id' => self::KEY_ID,
            'activation_id' => $activationId,
        ];

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
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function legacyPayload(LicenseService $service, array $extra = []): array
    {
        return array_merge([
            'product' => 'cpms',
            'license_id' => 'lic-activation-identity',
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
        return $this->legacyPayload($service, array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'key_id' => self::KEY_ID,
        ], $extra));
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
     * Real install path (signature verification + storage) through production code.
     *
     * @param array<string, mixed> $payload
     */
    private function installDocument(LicenseService $service, array $payload, string $keypair): void
    {
        $service->activateWithDocument(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            $this->signatureFor($payload, $keypair)
        );
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
     * @return array<string, mixed>
     */
    private function storedPayload(): array
    {
        $row = (new LicenseRepository(App::db()))->state();
        $this->assertNotNull($row, 'a verified document must be persisted');
        $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Everything a local decision could depend on: state, entitlements and gate verdicts.
     *
     * @return array<string, mixed>
     */
    private function localDecisions(LicenseService $service, SignedLicenseGate $gate): array
    {
        $state = $service->currentState();
        $entitlements = $service->entitlements();
        $decisions = [];
        foreach ([
            LicenseGate::OP_PATIENT_CREATE,
            LicenseGate::OP_PATIENT_UPDATE,
            LicenseGate::OP_APPOINTMENT_BOOK,
            LicenseGate::OP_APPOINTMENT_CANCEL,
            LicenseGate::OP_APPOINTMENT_RESCHEDULE,
            LicenseGate::OP_VISIT_CHECKIN,
            LicenseGate::OP_INVOICE_CREATE,
        ] as $op) {
            $decisions[$op] = $gate->assert($op)->allowed;
        }

        return [
            'status' => $state['status'],
            'reason' => $state['reason'],
            'needs_renewal' => $state['needs_renewal'],
            'features' => $entitlements->allFeatures(),
            'limits' => $entitlements->allLimits(),
            'read_only' => $gate->isReadOnly(),
            'gate' => $decisions,
        ];
    }
}
