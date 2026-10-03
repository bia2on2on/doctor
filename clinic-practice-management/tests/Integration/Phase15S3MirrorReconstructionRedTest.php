<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use Aws\S3\S3Client;
use ClinicCore\Application\Backup\BackupService;
use ClinicCore\Application\Backup\BackupS3Mirror;
use ClinicCore\Application\Backup\LocalBackupVerifier;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Backup\BackupManifest;
use ClinicCore\Infrastructure\Backup\BackupEncryptionEnvelope;
use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Backup\BackupSqlDumper;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Infrastructure\Backup\S3BackupClientFactory;
use ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\StreamInterface;
use WP_UnitTestCase;

/**
 * Phase 15 Slice 2D — TEST-ONLY RED for remote mirror reconstruction + the
 * existing restore preflight. No production operation exists on the RED head.
 *
 * All AWS traffic below is intercepted by the pinned SDK's documented
 * S3BackupClientFactory http_handler seam. The fixture source, bucket, endpoint,
 * credentials, encryption key and synthetic clinical markers are fake.
 */
final class Phase15S3MirrorReconstructionRedTest extends WP_UnitTestCase
{
    private const RECOVERY_CLASS = 'ClinicCore\\Application\\Backup\\BackupS3MirrorRecovery';
    private const RECOVERY_METHOD = 'reconstructAndPreflight';

    private const MISSING_CONTRACT_MESSAGE = 'Phase 15 Slice 2D INTENDED PRODUCT RED: the bounded '
        . 'remote-mirror reconstruction operation is missing (expected '
        . 'ClinicCore\\Application\\Backup\\BackupS3MirrorRecovery::reconstructAndPreflight(array $pointer): array). '
        . 'The Slice 2C catalog/pointer, Slice 2B official S3Client handler, Slice 1 envelope, LocalBackupVerifier '
        . 'and existing BackupService::restorePreflight controls are tested independently below; this is an explicit '
        . 'contract assertion, not a missing-class fatal or fixture/bootstrap failure.';

    private const FAKE_ENDPOINT = 'https://cpms-recovery-red.invalid';
    private const FAKE_REGION = 'cpms-recovery-red-1';
    private const FAKE_BUCKET = 'cpms_recovery_red_bucket';
    private const FAKE_PREFIX = 'cpms/phase15/recovery-red';
    private const FAKE_ACCESS_KEY = 'CPMSRECOVERYREDNOTPRODUCTIONACCESS001';
    private const FAKE_SECRET = 'cpms-recovery-red-not-production-secret-001';

    private const FIXTURE_KEY_BYTE = "\xa7";
    private const WRONG_KEY_BYTE = "\x5a";
    private const BACKUP_ID = 'cpms-backup-20260115-093000-7a3f9c21';
    private const OTHER_BACKUP_ID = 'cpms-backup-20260114-080000-abcdef01';
    private const STORAGE_RELATIVE = '1/a3/a1b2c3d4e5f60718293a4b5c6d7e8f90.pdf';
    private const NOTE_MARKER = 'SYNTHETIC-PHI-MARKER-NOT-REAL';

    /** Maximum remote objects, including the catalog object. */
    private const MAX_REMOTE_OBJECT_COUNT = 1024;
    /** Per-object ciphertext bytes; inherited from Slice 2C, binary 4 GiB. */
    private const MAX_ENCRYPTED_OBJECT_BYTES = 4294967296;
    /** Peak ciphertext-plus-plaintext staging bytes, binary 8 GiB. */
    private const MAX_AGGREGATE_STAGED_BYTES = 8589934592;
    /** Maximum decrypted catalog JSON bytes, binary 1 MiB. */
    private const MAX_CATALOG_JSON_BYTES = 1048576;

    private string $root;
    private string $testStorageRoot;
    private ProtectedBackupStore $sourceStore;
    private ProtectedBackupStore $operationStore;
    private BackupService $localBackupService;
    private BackupService $operationBackupService;
    /** @var array<string, mixed> */
    private array $manifest;
    private string $manifestJson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = rtrim(sys_get_temp_dir(), '/') . '/cpms-phase15-reconstruction-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700, true), 'fixture precondition: create isolated private test root');
        chmod($this->root, 0700);

        $this->sourceStore = ProtectedBackupStore::active($this->root . '/source-store');
        $this->operationStore = ProtectedBackupStore::active($this->root . '/operation-store');
        $this->testStorageRoot = $this->root . '/active-storage-probe';
        mkdir($this->testStorageRoot, 0700, true);
        file_put_contents($this->testStorageRoot . '/unchanged-sentinel.txt', 'test-owned storage sentinel');

        $this->writeLocalBackupFixture();
        $db = App::db();
        $dumper = new BackupSqlDumper($db);
        $settings = App::installationSettings();
        $audit = App::audit();
        $op = App::op();
        $this->localBackupService = new BackupService(
            $db,
            $this->sourceStore,
            $dumper,
            $settings,
            $audit,
            $op,
            $this->testStorageRoot
        );
        // Recovery receives a service whose configured active store deliberately
        // does NOT contain BACKUP_ID. The only valid preflight source is the new,
        // private reconstruction stage passed through the existing preflight API.
        $this->operationBackupService = new BackupService(
            $db,
            $this->operationStore,
            $dumper,
            $settings,
            $audit,
            $op,
            $this->testStorageRoot
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $this->removeTree($this->root);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Delivered foundations: these controls must remain GREEN on the RED head.
    // ---------------------------------------------------------------------

    public function testControlSlice2CCatalogFixtureAndEncryptedRemoteSetCanBeBuilt(): void
    {
        $remote = $this->buildRemoteFixture();
        $pointer = $remote['pointer'];
        $catalog = $remote['catalog'];

        self::assertSame(BackupS3Mirror::POINTER_FIELDS, array_keys($pointer), 'control: use the exact bounded Slice 2C durable pointer');
        self::assertSame(self::BACKUP_ID, $pointer['backup_id']);
        self::assertSame(self::BACKUP_ID, $catalog['local_backup_id']);
        self::assertSame($pointer['mirror_id'], $catalog['mirror_id']);
        self::assertSame($pointer['catalog_object_id'], $catalog['catalog_object_id']);
        self::assertSame('cpms-s3-mirror-catalog', $catalog['format']);
        self::assertSame(1, $catalog['format_version']);
        self::assertSame(BackupEncryptionEnvelope::MAGIC, $catalog['envelope_format']);
        self::assertSame(1, $catalog['envelope_format_version']);
        self::assertSame(4, count($remote['objects']), 'control: db.sql, manifest.json, one storage object and one remote-only catalog');
        self::assertCount(3, $catalog['entries']);
        self::assertSame(['db.sql', 'manifest.json', 'storage/' . self::STORAGE_RELATIVE], array_column($catalog['entries'], 'logical_path'));
        self::assertNotContains('manifest.json.sha256', array_column($catalog['entries'], 'logical_path'), 'control: the local-only manifest sidecar is not a remote object');
        self::assertSame(hash('sha256', $this->manifestJson), $catalog['local_manifest_sha256']);

        foreach ($remote['objects'] as $objectId => $ciphertext) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $objectId, 'control: Slice 2C object ids are opaque lowercase hex');
            self::assertStringStartsWith(BackupEncryptionEnvelope::MAGIC, $ciphertext, 'control: every remote object is encrypted with the Slice 1 envelope');
        }

        $byPath = [];
        foreach ($catalog['entries'] as $entry) {
            $byPath[$entry['logical_path']] = $entry;
        }
        $expected = [
            'db.sql' => (string) file_get_contents($this->sourceStore->dirOf(self::BACKUP_ID) . '/db.sql'),
            'manifest.json' => $this->manifestJson,
            'storage/' . self::STORAGE_RELATIVE => (string) file_get_contents(
                $this->sourceStore->dirOf(self::BACKUP_ID) . '/storage/' . self::STORAGE_RELATIVE
            ),
        ];
        foreach ($expected as $path => $bytes) {
            $entry = $byPath[$path];
            self::assertSame(strlen($remote['objects'][$entry['object_id']]), (int) $entry['ciphertext_bytes']);
            self::assertSame(hash('sha256', $remote['objects'][$entry['object_id']]), $entry['ciphertext_sha256']);
            self::assertSame($bytes, $this->decryptFixture($remote['objects'][$entry['object_id']], $this->fixtureKey(), 'slice2c-' . $entry['object_id']));
        }
    }

    public function testControlSlice2BOfficialClientMockReturnsARealisticGetObjectStream(): void
    {
        self::assertTrue(class_exists(S3Client::class), 'control: the pinned official S3 SDK must be available in CI');
        self::assertSame('3.399.0', \Aws\Sdk::VERSION, 'control: use the repository-pinned official AWS SDK model');

        $objectId = str_repeat('ab', 16);
        $body = "fixture-ciphertext\x00\xff";
        $objects = [$objectId => $body];
        $requests = [];
        $client = S3BackupClientFactory::create(
            $this->deploymentConfig($this->fixtureKey())->transportSettings(),
            $this->getObjectHandler($objects, $requests, str_repeat('cd', 16))
        );
        $result = $client->getObject([
            'Bucket' => self::FAKE_BUCKET,
            'Key' => self::FAKE_PREFIX . '/' . str_repeat('cd', 16) . '/' . $objectId,
        ]);

        self::assertCount(1, $requests, 'control: a GetObject command is intercepted locally exactly once');
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame(strlen($body), (int) $result['ContentLength']);
        self::assertInstanceOf(StreamInterface::class, $result['Body'], 'control: the pinned SDK returns GetObject Body as a readable stream');
        self::assertSame($body, (string) $result['Body']);
        self::assertSame(self::FAKE_ENDPOINT, (string) $client->getEndpoint());
        self::assertSame('v4', $client->getConfig('signature_version'));
    }

    public function testControlSlice1EnvelopeRoundTripsTheLocalBackupPayload(): void
    {
        self::assertTrue(extension_loaded('sodium'), 'control: CI must exercise the real supported sodium secretstream runtime');
        $source = $this->root . '/envelope-source.bin';
        $cipher = $this->root . '/envelope-source.enc';
        $plain = $this->root . '/envelope-roundtrip.bin';
        $bytes = "db.sql\x00" . str_repeat('synthetic-', 37);
        file_put_contents($source, $bytes);

        BackupEncryptionEnvelope::encryptFile($source, $cipher, $this->fixtureKey());
        BackupEncryptionEnvelope::decryptFile($cipher, $plain, $this->fixtureKey());

        self::assertSame($bytes, (string) file_get_contents($plain));
        self::assertSame($bytes, (string) file_get_contents($source), 'control: the envelope leaves the local source byte-identical');
        self::assertStringStartsWith(BackupEncryptionEnvelope::MAGIC, (string) file_get_contents($cipher));
    }

    public function testControlExistingLocalVerifierAndRestorePreflightPassANormalFixture(): void
    {
        $dir = $this->sourceStore->dirOf(self::BACKUP_ID);
        $verified = LocalBackupVerifier::verify($dir, self::BACKUP_ID, true);
        self::assertTrue($verified['ok'], 'control: the ordinary local fixture passes the existing verifier');
        self::assertSame([], $verified['errors']);
        self::assertSame(hash('sha256', $this->manifestJson), hash_file('sha256', $dir . '/manifest.json'));

        $preflight = $this->localBackupService->restorePreflight(self::BACKUP_ID);
        self::assertTrue($preflight['integrity_ok'], 'control: the existing BackupService preflight accepts a normal local backup');
        self::assertTrue($preflight['db_reachable']);
        self::assertTrue($preflight['restore_safe']);
        self::assertFalse($preflight['legacy_unverified']);
        self::assertSame('active', $preflight['source']);
    }

    public function testControlTestStorageIsPrivateAndOutsideTheWordPressDocumentRoot(): void
    {
        self::assertFalse($this->sourceStore->isInsideWebRoot(), 'control: fixtures use the established protected/private storage boundary');
        self::assertSame(0700, fileperms($this->root) & 0777, 'control: test staging parent is owner-only');
        self::assertFileExists($this->sourceStore->dirOf(self::BACKUP_ID) . '/manifest.json.sha256');
    }

    // ---------------------------------------------------------------------
    // Implemented behavior contracts. Missing APIs fail as stable assertions;
    // the public reconstruction operation is an instance method, not static.
    // ---------------------------------------------------------------------

    public function test_reconstruction_operation_contract_is_explicit_and_instance_scoped(): void
    {
        $class = $this->recoveryClass();
        $method = new \ReflectionMethod( $class, self::RECOVERY_METHOD );
        self::assertTrue( $method->isPublic(), 'contract: the new reconstruction operation is public' );
        self::assertFalse( $method->isStatic(), 'contract: the new reconstruction operation uses its configured instance' );

        // Reuse the one established restore-preflight path by adding only an
        // optional read-only source boundary; no second verifier/preflight stack.
        $preflight = new \ReflectionMethod(BackupService::class, 'restorePreflight');
        self::assertSame(2, $preflight->getNumberOfParameters(), 'contract: restorePreflight accepts the backup id plus an optional staged ProtectedBackupStore source');
        self::assertTrue($preflight->getParameters()[1]->isOptional());
        $sourceType = $preflight->getParameters()[1]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $sourceType);
        self::assertSame(ProtectedBackupStore::class, $sourceType->getName());
        self::assertTrue($sourceType->allowsNull());
    }

    public function testRecoveryTechnicalBoundsAreNamedAndUseExplicitUnits(): void
    {
        $class = $this->recoveryClass();
        $reflection = new \ReflectionClass($class);
        $expected = [
            'MAX_REMOTE_OBJECT_COUNT' => self::MAX_REMOTE_OBJECT_COUNT,
            'MAX_ENCRYPTED_OBJECT_BYTES' => BackupS3Mirror::MAX_ENCRYPTED_OBJECT_BYTES,
            'MAX_AGGREGATE_STAGED_BYTES' => self::MAX_AGGREGATE_STAGED_BYTES,
            'MAX_CATALOG_JSON_BYTES' => self::MAX_CATALOG_JSON_BYTES,
        ];
        foreach ($expected as $name => $value) {
            $constant = $reflection->getReflectionConstant($name);
            self::assertNotFalse($constant, 'contract: named recovery bound exists: ' . $name);
            self::assertSame($value, $constant->getValue(), 'contract: explicit conservative technical bound in the documented unit: ' . $name);
        }
    }

    public function testValidEncryptedMirrorReconstructsTheExistingLayoutAndReturnsABoundedPass(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $requests = [];
        $stage = $this->stagePath();
        $watermarks = $this->logWatermarks();
        $result = $this->invokeRecovery($remote, $remote['pointer'], $this->fixtureKey(), $stage, $requests);

        $this->assertSuccessfulBoundedResult($result, $remote['pointer']);
        $this->assertNoNewLogLeaks($watermarks, $remote);
        self::assertGreaterThan(1, count($requests), 'contract: the catalog and every encrypted data object are fetched through the official handler');
        self::assertSame(array_fill(0, count($requests), 'GET'), array_column($requests, 'method'), 'contract: reconstruction only reads remote objects');
        $this->assertOnlyOpaqueKeysWereRequested($requests, $remote['pointer']);
        $this->assertPathAbsent($stage, 'contract: private download and reconstruction staging is removed after success');
    }

    public function testReconstructedBackupPassesTheExistingVerifierAndRestorePreflightAgainstTheStage(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $requests = [];
        $stage = $this->stagePath();
        $preflightCalls = 0;
        $verifiedAtPreflight = false;
        $observer = function ($query) use (&$preflightCalls, &$verifiedAtPreflight, $stage, $remote): string {
            if (trim((string) $query) === 'SELECT 1') {
                ++$preflightCalls;
                $stagedBackup = $stage . '/' . self::BACKUP_ID;
                self::assertDirectoryExists($stagedBackup, 'contract: restorePreflight is reached only after reconstruction into a distinct stage');
                self::assertSame($this->expectedFilesFromFixture(), $this->fileMap($stagedBackup), 'contract: reconstructed db.sql, manifest, storage bytes and only required sidecar match the local layout');
                self::assertSame(
                    $remote['catalog']['local_manifest_sha256'],
                    hash_file('sha256', $stagedBackup . '/manifest.json'),
                    'contract: authenticated local_manifest_sha256 is compared to reconstructed manifest bytes before verification'
                );
                $localVerify = LocalBackupVerifier::verify($stagedBackup, self::BACKUP_ID, true);
                self::assertTrue($localVerify['ok'], 'contract: existing LocalBackupVerifier passes against the reconstructed staged backup');
                self::assertSame([], $localVerify['errors']);
                $verifiedAtPreflight = true;
            }

            return (string) $query;
        };
        add_filter('query', $observer, 999, 1);
        try {
            $result = $this->invokeRecovery($remote, $remote['pointer'], $this->fixtureKey(), $stage, $requests);
        } finally {
            remove_filter('query', $observer, 999);
        }

        self::assertSame(1, $preflightCalls, 'contract: BackupService::restorePreflight() reaches its existing database check once for the staged backup');
        self::assertTrue($verifiedAtPreflight);
        $this->assertSuccessfulBoundedResult($result, $remote['pointer']);
        $this->assertPathAbsent($stage, 'contract: the stage is removed after restore preflight returns');
    }

    public function testAuthenticatedCatalogIsFullyValidatedBeforeTheFirstPlaintextFileIsWritten(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $stage = $this->stagePath();
        $requests = [];
        $observed = false;
        $onFirstDataRequest = function (string $objectId, string $path) use (&$observed, $stage): void {
            if ($observed) {
                return;
            }
            $observed = true;
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $objectId);
            self::assertStringNotContainsString(self::STORAGE_RELATIVE, $path);
            self::assertDirectoryExists($stage, 'contract: reconstruction has an operation-owned stage before requesting payload objects');
            self::assertSame(0, fileperms($stage) & 0077, 'contract: operation-owned stage denies group/other access');
            self::assertSame([], $this->fileMap($stage . '/' . self::BACKUP_ID), 'contract: no plaintext backup file exists until every authenticated catalog entry has been validated');
        };
        $result = $this->invokeRecovery(
            $remote,
            $remote['pointer'],
            $this->fixtureKey(),
            $stage,
            $requests,
            ['on_data_request' => $onFirstDataRequest]
        );

        self::assertTrue($observed);
        $this->assertSuccessfulBoundedResult($result, $remote['pointer']);
        $this->assertPathAbsent($stage, 'contract: the private stage is removed after successful verification');
    }

    public function testWrongEncryptionKeyFailsClosed(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], str_repeat(self::WRONG_KEY_BYTE, 32), $stage);
        self::assertNotSame('', $outcome['error']->getErrorCode());
        self::assertSame(1, count($outcome['requests']), 'contract: a wrong key is rejected while authenticating the catalog, before data-object downloads');
    }

    public function testCorruptedCatalogFailsAuthentication(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $catalogId = $remote['pointer']['catalog_object_id'];
        $cipher = $remote['objects'][$catalogId];
        $cipher[strlen($cipher) - 1] = chr(ord($cipher[strlen($cipher) - 1]) ^ 1);
        $remote['objects'][$catalogId] = $cipher;
        $stage = $this->stagePath();
        $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
    }

    public function testCorruptedObjectFailsAuthenticatedCiphertextHashValidation(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $entry = $remote['catalog']['entries'][0];
        $cipher = $remote['objects'][$entry['object_id']];
        $cipher[40] = chr(ord($cipher[40]) ^ 1);
        $remote['objects'][$entry['object_id']] = $cipher;
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        self::assertCount(2, $outcome['requests'], 'contract: catalog and first data object are fetched; the altered bytes fail before plaintext reconstruction');
    }

    public function testCorruptedObjectWithMatchingCatalogEvidenceStillFailsEnvelopeFinalTag(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $entry = $remote['catalog']['entries'][0];
        $cipher = $remote['objects'][$entry['object_id']];
        $cipher[strlen($cipher) - 1] = chr(ord($cipher[strlen($cipher) - 1]) ^ 1);
        $remote['objects'][$entry['object_id']] = $cipher;
        $remote['catalog']['entries'][0]['ciphertext_bytes'] = strlen($cipher);
        $remote['catalog']['entries'][0]['ciphertext_sha256'] = hash('sha256', $cipher);
        $remote = $this->withCatalog($remote, $remote['catalog']);
        $stage = $this->stagePath();
        $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
    }

    public function testTruncatedObjectWithMatchingCatalogEvidenceFailsEnvelopeAuthentication(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $entry = $remote['catalog']['entries'][0];
        $truncated = substr($remote['objects'][$entry['object_id']], 0, -1);
        $remote['objects'][$entry['object_id']] = $truncated;
        $remote['catalog']['entries'][0]['ciphertext_bytes'] = strlen($truncated);
        $remote['catalog']['entries'][0]['ciphertext_sha256'] = hash('sha256', $truncated);
        $remote = $this->withCatalog($remote, $remote['catalog']);
        $stage = $this->stagePath();
        $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
    }

    public function testMissingRemoteObjectFailsClosed(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $missingId = $remote['catalog']['entries'][0]['object_id'];
        unset($remote['objects'][$missingId]);
        $stage = $this->stagePath();
        $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
    }

    public function testCiphertextSizeAndHashMismatchFailWithoutTrustingETag(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $entry = $remote['catalog']['entries'][0];
        $cipher = substr($remote['objects'][$entry['object_id']], 0, -1);
        $remote['objects'][$entry['object_id']] = $cipher;
        $stage = $this->stagePath();
        $etag = $entry['ciphertext_sha256'];
        $outcome = $this->expectRemoteFailure(
            $remote,
            $remote['pointer'],
            $this->fixtureKey(),
            $stage,
            ['etag_overrides' => [$entry['object_id'] => '"' . $etag . '"']]
        );
        self::assertCount(2, $outcome['requests']);

        $remote = $this->buildRemoteFixture();
        $entry = $remote['catalog']['entries'][0];
        $cipher = $remote['objects'][$entry['object_id']];
        $cipher[40] = chr(ord($cipher[40]) ^ 1);
        $remote['objects'][$entry['object_id']] = $cipher;
        $stage = $this->stagePath();
        $this->expectRemoteFailure(
            $remote,
            $remote['pointer'],
            $this->fixtureKey(),
            $stage,
            ['etag_overrides' => [$entry['object_id'] => '"' . $entry['ciphertext_sha256'] . '"']]
        );
    }

    public function testPointerAndCatalogIdentityMismatchesFailClosed(): void
    {
        $this->recoveryClass();
        $base = $this->buildRemoteFixture();
        $stage = $this->stagePath();
        $pointer = $base['pointer'];
        $pointer['backup_id'] = self::OTHER_BACKUP_ID;
        $this->expectRemoteFailure($base, $pointer, $this->fixtureKey(), $stage);

        $base = $this->buildRemoteFixture();
        $pointer = $base['pointer'];
        $pointer['mirror_id'] = str_repeat('f', 32);
        $stage = $this->stagePath();
        $this->expectRemoteFailure($base, $pointer, $this->fixtureKey(), $stage);

        $base = $this->buildRemoteFixture();
        $wrongCatalogId = str_repeat('e', 32);
        $pointer = $base['pointer'];
        $pointer['catalog_object_id'] = $wrongCatalogId;
        $base['objects'][$wrongCatalogId] = $base['objects'][$base['pointer']['catalog_object_id']];
        $stage = $this->stagePath();
        $this->expectRemoteFailure($base, $pointer, $this->fixtureKey(), $stage);

        foreach (['local_backup_id' => self::OTHER_BACKUP_ID, 'mirror_id' => str_repeat('d', 32), 'catalog_object_id' => str_repeat('c', 32)] as $field => $value) {
            $remote = $this->buildRemoteFixture();
            $remote['catalog'][$field] = $value;
            $remote = $this->withCatalog($remote, $remote['catalog']);
            $stage = $this->stagePath();
            $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        }
        foreach (['object_count', 'ciphertext_bytes'] as $field) {
            $remote = $this->buildRemoteFixture();
            $pointer = $remote['pointer'];
            ++$pointer[$field];
            $stage = $this->stagePath();
            $outcome = $this->expectRemoteFailure($remote, $pointer, $this->fixtureKey(), $stage);
            self::assertLessThanOrEqual(1, count($outcome['requests']), 'contract: Slice 2C pointer aggregate evidence must match the authenticated catalog before payload downloads');
        }

        $remote = $this->buildRemoteFixture();
        $remote['catalog']['entries'][0]['remote_verification'] = BackupS3Mirror::STRENGTH_ACKNOWLEDGED;
        $remote = $this->withCatalog( $remote, $remote['catalog'] );
        $pointer = $remote['pointer'];
        $pointer['verification'] = BackupS3Mirror::STRENGTH_VERIFIED;
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure(
            $remote,
            $pointer,
            $this->fixtureKey(),
            $stage
        );
        self::assertCount(
            1,
            $outcome['requests'],
            'contract: VERIFIED aggregate evidence cannot contain an ACKNOWLEDGED catalog entry'
        );
    }

    public function testAuthenticatedLocalManifestDigestMustMatchReconstructedManifestBytes(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $remote['catalog']['local_manifest_sha256'] = str_repeat('a', 64);
        $remote = $this->withCatalog($remote, $remote['catalog']);
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        self::assertCount(4, $outcome['requests'], 'contract: authenticated catalog plus all three payload objects are available before manifest-hash comparison fails');
    }

    public function testUnknownCatalogFormatAndVersionFailClosed(): void
    {
        $this->recoveryClass();
        foreach ([['format' => 'cpms-s3-mirror-catalog-unrecognized'], ['format_version' => 99]] as $change) {
            $remote = $this->buildRemoteFixture();
            $catalog = array_merge($remote['catalog'], $change);
            $remote = $this->withCatalog($remote, $catalog);
            $stage = $this->stagePath();
            $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        }
    }

    public function testPointerAndCatalogOpaqueIdsMustUseDeliveredLowercaseHexSyntax(): void
    {
        $this->recoveryClass();
        $base = $this->buildRemoteFixture();
        foreach (['mirror_id', 'catalog_object_id'] as $field) {
            $pointer = $base['pointer'];
            $pointer[$field] = '../not-an-opaque-id';
            $requests = [];
            $stage = $this->stagePath();
            $outcome = $this->expectRemoteFailure($base, $pointer, $this->fixtureKey(), $stage);
            $requests = $outcome['requests'];
            self::assertCount(0, $requests, 'contract: invalid pointer ids are rejected before any GetObject request');
        }
        foreach (['mirror_id' => 'UPPERCASE', 'catalog_object_id' => 'short'] as $field => $value) {
            $remote = $this->buildRemoteFixture();
            $remote['catalog'][$field] = $value;
            $remote = $this->withCatalog($remote, $remote['catalog']);
            $stage = $this->stagePath();
            $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        }
        foreach (['../not-an-id', 'UPPERCASE', 'short'] as $objectId) {
            $remote = $this->buildRemoteFixture();
            $remote['catalog']['entries'][2]['object_id'] = $objectId;
            $remote = $this->withCatalog($remote, $remote['catalog']);
            $stage = $this->stagePath();
            $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
            self::assertCount(1, $outcome['requests'], 'contract: invalid delivered object ids are rejected before any data-object GetObject');
        }
    }

    public function testDuplicateObjectIdsFailBeforeFetchingDataObjects(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $remote['catalog']['entries'][2]['object_id'] = $remote['catalog']['entries'][0]['object_id'];
        $remote = $this->withCatalog($remote, $remote['catalog']);
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        self::assertCount(1, $outcome['requests'], 'contract: duplicate object ids are rejected after catalog authentication but before data downloads');
    }

    public function testDuplicateNormalizedLogicalPathsFailBeforeFetchingDataObjects(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $duplicate = $remote['catalog']['entries'][2];
        $duplicate['object_id'] = str_repeat('b', 32);
        $remote['catalog']['entries'][] = $duplicate;
        $remote = $this->withCatalog($remote, $remote['catalog']);
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        self::assertCount(1, $outcome['requests']);
    }

    public function testDatabaseAndManifestRolesAndPathsAreExactAndUnique(): void
    {
        $this->recoveryClass();
        $mutations = [
            static function (array $catalog): array {
                $catalog['entries'] = array_values(array_filter($catalog['entries'], static fn (array $entry): bool => $entry['role'] !== 'database'));
                return $catalog;
            },
            static function (array $catalog): array {
                $catalog['entries'][0]['logical_path'] = 'other.sql';
                return $catalog;
            },
            static function (array $catalog): array {
                $catalog['entries'][1]['logical_path'] = 'other-manifest.json';
                return $catalog;
            },
            static function (array $catalog): array {
                $duplicate = $catalog['entries'][0];
                $duplicate['object_id'] = str_repeat('9', 32);
                $catalog['entries'][] = $duplicate;
                return $catalog;
            },
            static function (array $catalog): array {
                $catalog['entries'] = array_values(array_filter($catalog['entries'], static fn (array $entry): bool => $entry['role'] !== 'manifest'));
                return $catalog;
            },
        ];
        foreach ($mutations as $mutate) {
            $remote = $this->buildRemoteFixture();
            $remote = $this->withCatalog($remote, $mutate($remote['catalog']));
            $stage = $this->stagePath();
            $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        }
    }

    public function testUnknownCatalogRoleIsRejectedRatherThanSilentlyAccepted(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $entry = $remote['catalog']['entries'][2];
        $entry['object_id'] = str_repeat('8', 32);
        $entry['logical_path'] = 'storage/1/a3/unknown-role.bin';
        $entry['role'] = 'future-unknown-role';
        $remote['catalog']['entries'][] = $entry;
        $remote = $this->withCatalog($remote, $remote['catalog']);
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        self::assertCount(1, $outcome['requests']);
    }

    public function testStorageEntriesAreStorageRelativeAndManifestSidecarIsNeverRemote(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $remote['catalog']['entries'][2]['logical_path'] = 'db.sql';
        $remote = $this->withCatalog($remote, $remote['catalog']);
        $stage = $this->stagePath();
        $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);

        $remote = $this->buildRemoteFixture();
        $entry = $remote['catalog']['entries'][1];
        $entry['object_id'] = str_repeat('7', 32);
        $entry['logical_path'] = 'manifest.json.sha256';
        $remote['catalog']['entries'][] = $entry;
        $remote = $this->withCatalog($remote, $remote['catalog']);
        $stage = $this->stagePath();
        $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
    }

    public function testAbsoluteTraversalBackslashEmptyDotAndControlPathsFailClosed(): void
    {
        $this->recoveryClass();
        $invalidPaths = [
            '/storage/1/a3/file.pdf',
            'C:/storage/1/a3/file.pdf',
            '../escape.sql',
            'storage/../escape.pdf',
            'storage\\1\\a3\\file.pdf',
            'storage//1/a3/file.pdf',
            'storage/./1/a3/file.pdf',
            "storage/1/a3/bad\0name.pdf",
            "storage/1/a3/bad\x1fname.pdf",
        ];
        foreach ($invalidPaths as $path) {
            $remote = $this->buildRemoteFixture();
            $remote['catalog']['entries'][2]['logical_path'] = $path;
            $remote = $this->withCatalog($remote, $remote['catalog']);
            $stage = $this->stagePath();
            $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
            self::assertCount(1, $outcome['requests'], 'contract: all catalog paths are validated before any data-object download or plaintext write');
        }
    }

    public function testSymlinkStageEscapeFailsWithoutTouchingTheOutsideTarget(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $outside = $this->root . '/outside-target';
        mkdir($outside, 0700, true);
        $sentinel = $outside . '/must-remain.txt';
        file_put_contents($sentinel, 'outside-stage-sentinel');
        $stage = $this->root . '/stage-symlink';
        self::assertTrue(symlink($outside, $stage), 'fixture precondition: Linux CI permits a deterministic staging-root symlink');

        try {
            $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage, [], false);
            self::assertCount(0, $outcome['requests'], 'contract: canonical/symlink stage safety is checked before remote reads');
            self::assertTrue(is_link($stage), 'contract: a foreign symlink is not unlinked as if it were owned staging');
            self::assertSame('outside-stage-sentinel', file_get_contents($sentinel));
        } finally {
            if (is_link($stage)) {
                unlink($stage);
            }
        }
    }

    public function testRemoteObjectCountBoundRejectsAboveLimitBeforeDataDownloads(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $remote = $this->withEntryCount($remote, self::MAX_REMOTE_OBJECT_COUNT);
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        self::assertLessThanOrEqual(1, count($outcome['requests']), 'contract: an over-limit set is rejected before any data-object GetObject (pointer evidence may allow rejection before fetching the catalog)');
    }

    public function testRemoteObjectCountBoundaryIsInclusiveWithoutLargeByteFixtures(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $remote = $this->withEntryCount($remote, self::MAX_REMOTE_OBJECT_COUNT - 1);
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure(
            $remote,
            $remote['pointer'],
            $this->fixtureKey(),
            $stage,
            ['fail_first_data' => true]
        );
        self::assertSame(self::MAX_REMOTE_OBJECT_COUNT, (int) $remote['pointer']['object_count'], 'contract: the catalog object is included in the count boundary');
        self::assertCount(2, $outcome['requests'], 'contract: the inclusive count boundary reaches the first data-object request; the fixture then returns a deterministic missing-object response');
    }

    public function testPerObjectEncryptedByteBoundMatchesSlice2CAndFailsAboveIt(): void
    {
        $this->recoveryClass();
        self::assertSame(self::MAX_ENCRYPTED_OBJECT_BYTES, BackupS3Mirror::MAX_ENCRYPTED_OBJECT_BYTES, 'contract: recovery uses the existing 4 GiB Slice 2C ciphertext cap, not a new per-object limit');
        $remote = $this->buildRemoteFixture();
        $remote['catalog']['entries'][0]['ciphertext_bytes'] = self::MAX_ENCRYPTED_OBJECT_BYTES + 1;
        $remote = $this->withCatalog($remote, $remote['catalog']);
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        self::assertCount(
            0,
            $outcome['requests'],
            'contract: the accepted aggregate pointer ceiling is stricter than the per-object cap, so oversized metadata is rejected before any remote read; no giant object bytes are allocated'
        );
    }

    public function testAggregateStagingBoundFailsBeforeFetchingOversizedObjectBytes(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $perObject = intdiv(self::MAX_AGGREGATE_STAGED_BYTES, 4) + 1;
        $remote['catalog']['entries'][0]['ciphertext_bytes'] = $perObject;
        $remote['catalog']['entries'][1]['ciphertext_bytes'] = $perObject;
        $remote = $this->withCatalog($remote, $remote['catalog']);
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        self::assertCount(
            0,
            $outcome['requests'],
            'contract: the pointer aggregate ciphertext ceiling rejects this encrypted-plus-plaintext overage before any remote read; no oversized fixture is allocated'
        );
    }

    public function testCatalogJsonInputIsBoundedAndMalformedJsonFailsClosed(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $remote = $this->withCatalogPlaintext($remote, '{"format":');
        $stage = $this->stagePath();
        $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);

        $remote = $this->buildRemoteFixture();
        $oversized = str_repeat(' ', self::MAX_CATALOG_JSON_BYTES + 1);
        $remote = $this->withCatalogPlaintext($remote, $oversized);
        $stage = $this->stagePath();
        $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $stage);
        self::assertCount(1, $outcome['requests'], 'contract: oversized authenticated catalog input fails before data-object work');
    }

    public function testInsufficientPrivateFilesystemSpaceFailsBeforeDataDownloads(): void
    {
        $this->recoveryClass();
        self::assertDirectoryExists(
            '/dev/shm',
            'control: the Linux CI runner provides a bounded tmpfs for a no-allocation free-space test'
        );
        $free = disk_free_space( '/dev/shm' );
        self::assertNotFalse( $free );
        self::assertGreaterThan( 0, (int) $free );

        $remote = $this->buildRemoteFixture();
        $storage_entry = $remote['catalog']['entries'][2];
        $fixed_ciphertext_bytes = (int) $remote['pointer']['ciphertext_bytes'] - (int) $storage_entry['ciphertext_bytes'];
        $maximum_pointer_ciphertext_bytes = intdiv( self::MAX_AGGREGATE_STAGED_BYTES - 64, 2 );
        $candidate_ciphertext_bytes = intdiv( (int) $free, 2 ) + 1;
        self::assertLessThanOrEqual(
            self::MAX_ENCRYPTED_OBJECT_BYTES,
            $candidate_ciphertext_bytes,
            'control: metadata-only test input remains under the existing per-object cap; no giant fixture is allocated'
        );
        self::assertLessThanOrEqual(
            $maximum_pointer_ciphertext_bytes - $fixed_ciphertext_bytes,
            $candidate_ciphertext_bytes,
            'control: metadata-only test input remains under the accepted pointer aggregate ceiling'
        );
        $remote['catalog']['entries'][2]['ciphertext_bytes'] = $candidate_ciphertext_bytes;
        $remote = $this->withCatalog( $remote, $remote['catalog'] );
        self::assertLessThanOrEqual(
            $maximum_pointer_ciphertext_bytes,
            $remote['pointer']['ciphertext_bytes'],
            'control: recomputed pointer metadata remains within the accepted aggregate ceiling'
        );
        $stage = '/dev/shm/cpms-phase15-stage-' . bin2hex( random_bytes( 8 ) );
        try {
            $outcome = $this->expectRemoteFailure(
                $remote,
                $remote['pointer'],
                $this->fixtureKey(),
                $stage
            );
            self::assertCount(
                1,
                $outcome['requests'],
                'contract: authenticated metadata exceeds available space before any data-object download; no giant fixture is allocated'
            );
        } finally {
            if ( is_dir( $stage ) ) {
                $this->removeTree( $stage );
            }
        }
    }

    public function testExistingRealBackupDirectoryCannotBeUsedAsReconstructionDestination(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $existing = $this->sourceStore->dirOf(self::BACKUP_ID);
        $before = $this->fileMap($existing);
        $outcome = $this->expectRemoteFailure($remote, $remote['pointer'], $this->fixtureKey(), $existing, [], false);
        self::assertCount(0, $outcome['requests'], 'contract: a real active-store backup collision is rejected before any network read');
        self::assertSame($before, $this->fileMap($existing), 'contract: an existing real local backup directory is never overwritten or cleaned');
    }

    public function testAllOwnedStagingIsRemovedAfterSuccessAndFailure(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $successStage = $this->stagePath();
        $requests = [];
        $result = $this->invokeRecovery($remote, $remote['pointer'], $this->fixtureKey(), $successStage, $requests);
        $this->assertSuccessfulBoundedResult($result, $remote['pointer']);
        $this->assertPathAbsent($successStage, 'contract: successful reconstruction leaves no downloaded ciphertext or plaintext staging');

        $failureStage = $this->stagePath();
        $this->expectRemoteFailure($remote, $remote['pointer'], str_repeat(self::WRONG_KEY_BYTE, 32), $failureStage);
        $this->assertPathAbsent($failureStage, 'contract: failed authentication leaves no private staging');
    }

    public function testBoundedFailureOutputsAndNewLogsNeverExposeSecretsPHIPathsOrObjectKeys(): void
    {
        $this->recoveryClass();
        $remote = $this->buildRemoteFixture();
        $watermarks = $this->logWatermarks();
        $stage = $this->stagePath();
        $this->expectRemoteFailure($remote, $remote['pointer'], str_repeat(self::WRONG_KEY_BYTE, 32), $stage);
        $this->assertNoNewLogLeaks($watermarks, $remote);
    }

    public function testRecoveryIsNotWiredIntoBackupRunOrRecurringScheduler(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $jobsRoot = $pluginRoot . '/src/Application/Jobs';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($jobsRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                self::assertStringNotContainsString(self::RECOVERY_CLASS, (string) file_get_contents($file->getPathname()), 'contract: no recovery call is wired into a job or backup.run handler');
            }
        }
        self::assertStringNotContainsString(self::RECOVERY_CLASS, (string) file_get_contents($pluginRoot . '/src/Bootstrap/App.php'), 'contract: no scheduler/Bootstrap registration is added for recovery');
    }

    // ---------------------------------------------------------------------
    // Contract helpers — no missing-class instantiation or production seam.
    // ---------------------------------------------------------------------

    private function recoveryClass(): string
    {
        self::assertTrue(class_exists(self::RECOVERY_CLASS), self::MISSING_CONTRACT_MESSAGE);
        self::assertTrue(
            method_exists( self::RECOVERY_CLASS, self::RECOVERY_METHOD ),
            'contract: public reconstructAndPreflight(array $pointer): array exists'
        );

        $reflection = new \ReflectionClass(self::RECOVERY_CLASS);
        self::assertTrue($reflection->isFinal(), 'contract: the operation is a concrete application service, not a new production provider interface');
        $method = $reflection->getMethod(self::RECOVERY_METHOD);
        self::assertTrue($method->isPublic());
        self::assertFalse( $method->isStatic() );
        self::assertSame(1, $method->getNumberOfParameters());
        self::assertSame('pointer', $method->getParameters()[0]->getName());
        $parameterType = $method->getParameters()[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $parameterType);
        self::assertSame('array', $parameterType->getName());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());

        return self::RECOVERY_CLASS;
    }

    /** @param array<string, mixed> $remote @param array<string, mixed> $pointer @param array<string, mixed> $handlerOptions */
    private function invokeRecovery(array $remote, array $pointer, string $key, string $stage, array &$requests, array $handlerOptions = []): array
    {
        $class = $this->recoveryClass();
        $handler = $this->getObjectHandler($remote['objects'], $requests, (string) $remote['pointer']['catalog_object_id'], $handlerOptions);
        $recovery = new $class(
            $this->operationBackupService,
            $this->deploymentConfig($key),
            $handler,
            $stage
        );

        return $recovery->reconstructAndPreflight($pointer);
    }

    /**
     * @param array<string, mixed>      $remote
     * @param array<string, mixed>|null $pointer
     * @param array<string, mixed>      $handlerOptions
     *
     * @return array{error: BackupException, requests: list<array<string, mixed>>}
     */
    private function expectRemoteFailure(
        array $remote,
        ?array $pointer = null,
        ?string $key = null,
        ?string $stage = null,
        array $handlerOptions = [],
        bool $assertStageClean = true
    ): array {
        $requests = [];
        $stage = $stage ?? $this->stagePath();
        try {
            $this->invokeRecovery(
                $remote,
                $pointer ?? $remote['pointer'],
                $key ?? $this->fixtureKey(),
                $stage,
                $requests,
                $handlerOptions
            );
            self::fail('contract: invalid or unsafe remote mirror input must fail closed');
        } catch (AssertionFailedError $error) {
            throw $error;
        } catch (BackupException $error) {
            $this->assertBoundedFailure($error, $remote);
            return ['error' => $error, 'requests' => $requests];
        } catch (\Throwable $error) {
            self::fail('contract: failures surface only as a bounded BackupException, not raw ' . get_class($error));
        } finally {
            if ($assertStageClean) {
                $this->assertPathAbsent($stage, 'contract: operation-owned staging is removed after every failure');
            }
        }

        self::fail('unreachable');
    }

    /** @param array<string, mixed> $pointer */
    private function assertSuccessfulBoundedResult(array $result, array $pointer): void
    {
        $keys = array_keys($result);
        sort($keys);
        $expectedKeys = ['ok', 'result', 'backup_id', 'mirror_id', 'catalog_object_id', 'local_verifier_ok', 'restore_preflight_ok'];
        sort($expectedKeys);
        self::assertSame(
            $expectedKeys,
            $keys,
            'contract: the success projection is bounded and contains no path, manifest, PHI or provider configuration'
        );
        self::assertTrue($result['ok']);
        self::assertIsString($result['result']);
        self::assertLessThanOrEqual(80, strlen($result['result']));
        self::assertSame(self::BACKUP_ID, $result['backup_id']);
        self::assertSame($pointer['mirror_id'], $result['mirror_id']);
        self::assertSame($pointer['catalog_object_id'], $result['catalog_object_id']);
        self::assertTrue($result['local_verifier_ok']);
        self::assertTrue($result['restore_preflight_ok']);
    }

    /** @param array<string, mixed> $remote */
    private function assertBoundedFailure(BackupException $error, array $remote): void
    {
        self::assertMatchesRegularExpression('/^CLINIC_BACKUP_[A-Z0-9_]+$/', $error->getErrorCode());
        self::assertLessThanOrEqual(200, strlen($error->getMessage()), 'contract: failure messages are short and bounded');
        $encodedData = json_encode($error->data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        self::assertIsString($encodedData);
        self::assertLessThanOrEqual(512, strlen($encodedData), 'contract: failure evidence is bounded');

        $surfaces = $error->getErrorCode() . "\n" . $error->getMessage() . "\n" . $encodedData . "\n" . (string) $error;
        foreach ($this->forbiddenLeakMarkers($remote) as $marker) {
            if ($marker !== '') {
                self::assertStringNotContainsString($marker, $surfaces, 'contract: bounded errors never reveal credentials, PHI, paths, endpoint/bucket/prefix or remote keys');
            }
        }
    }

    /** @param array<string, mixed> $remote @return list<string> */
    private function forbiddenLeakMarkers(array $remote): array
    {
        $markers = [
            self::FAKE_ENDPOINT,
            self::FAKE_BUCKET,
            self::FAKE_PREFIX,
            self::FAKE_ACCESS_KEY,
            base64_encode(self::FAKE_ACCESS_KEY),
            self::FAKE_SECRET,
            base64_encode(self::FAKE_SECRET),
            rawurlencode(self::FAKE_ENDPOINT),
            rawurlencode(self::FAKE_BUCKET),
            rawurlencode(self::FAKE_PREFIX),
            base64_encode($this->fixtureKey()),
            bin2hex($this->fixtureKey()),
            base64_encode(str_repeat(self::WRONG_KEY_BYTE, 32)),
            bin2hex(str_repeat(self::WRONG_KEY_BYTE, 32)),
            self::NOTE_MARKER,
            self::STORAGE_RELATIVE,
            $this->root,
            $this->sourceStore->basePath(),
        ];
        $mirror = (string) ($remote['pointer']['mirror_id'] ?? '');
        foreach (array_keys((array) ($remote['objects'] ?? [])) as $objectId) {
            $objectKey = self::FAKE_BUCKET . '/' . self::FAKE_PREFIX . '/' . $mirror . '/' . (string) $objectId;
            $markers[] = $objectKey;
            $markers[] = rawurlencode($objectKey);
        }
        foreach ((array) ($remote['catalog']['entries'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['logical_path'] ?? null)) {
                $markers[] = $entry['logical_path'];
            }
        }

        return $markers;
    }

    /** @param array<string, mixed> $pointer */
    private function assertOnlyOpaqueKeysWereRequested(array $requests, array $pointer): void
    {
        foreach ($requests as $request) {
            self::assertSame('GET', $request['method']);
            $path = (string) $request['path'];
            $prefix = '/' . self::FAKE_BUCKET . '/' . self::FAKE_PREFIX . '/' . $pointer['mirror_id'] . '/';
            self::assertStringStartsWith($prefix, $path, 'contract: remote key is the configured prefix + validated mirror id + validated object id only');
            $objectId = substr($path, strlen($prefix));
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $objectId, 'contract: no backup id, logical path or filename enters an object key');
        }
    }

    // ---------------------------------------------------------------------
    // Fixture construction uses the real Slice 2C uploader, official SDK and
    // Slice 1 encryption. It allocates only small local fixtures.
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function buildRemoteFixture(): array
    {
        $requests = [];
        $mirror = new BackupS3Mirror(
            $this->sourceStore,
            $this->deploymentConfig($this->fixtureKey()),
            $this->putObjectHandler($requests),
            $this->root . '/mirror-upload-scratch'
        );
        $result = $mirror->mirrorBackup(self::BACKUP_ID);
        self::assertTrue((bool) ($result['ok'] ?? false), 'fixture precondition: the existing Slice 2C mirror succeeds using only the in-process test handler');
        self::assertSame(BackupS3Mirror::RESULT_VERIFIED, $result['result']);

        $pointer = BackupS3Mirror::pointerFromResult($result);
        $objects = [];
        foreach ($requests as $request) {
            $segments = explode('/', trim((string) $request['path'], '/'));
            $objectId = (string) end($segments);
            $objects[$objectId] = (string) $request['body'];
        }
        $catalogId = (string) $pointer['catalog_object_id'];
        self::assertArrayHasKey($catalogId, $objects, 'fixture precondition: the final Slice 2C PutObject is the encrypted remote catalog');
        $catalogJson = $this->decryptFixture($objects[$catalogId], $this->fixtureKey(), 'catalog-' . $catalogId);
        $catalog = json_decode($catalogJson, true);
        self::assertIsArray($catalog, 'fixture precondition: decrypt the real Slice 2C catalog JSON');

        return [
            'pointer' => $pointer,
            'catalog' => $catalog,
            'catalog_json' => $catalogJson,
            'objects' => $objects,
            'put_requests' => $requests,
        ];
    }

    /** @param list<array<string, mixed>> $requests */
    private function putObjectHandler(array &$requests): callable
    {
        return static function ($request, array $options) use (&$requests) {
            $body = (string) $request->getBody();
            $checksum = $request->getHeaderLine('x-amz-checksum-sha256');
            $requests[] = [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'query' => $request->getUri()->getQuery(),
                'body' => $body,
            ];

            return new FulfilledPromise(new Response(200, [
                'ETag' => '"' . md5($body) . '"',
                'x-amz-checksum-sha256' => $checksum,
            ], ''));
        };
    }

    /** @param list<array<string, mixed>> $requests @param array<string, string> $objects @param array<string, mixed> $options */
    private function getObjectHandler(array $objects, array &$requests, string $catalogId, array $options = []): callable
    {
        $firstDataFailureSent = false;
        return static function ($request, array $handlerOptions) use (&$requests, $objects, $catalogId, $options, &$firstDataFailureSent) {
            $method = $request->getMethod();
            $path = $request->getUri()->getPath();
            $requests[] = [
                'method' => $method,
                'path' => $path,
                'query' => $request->getUri()->getQuery(),
            ];
            if ($method !== 'GET') {
                return new RejectedPromise(new \RuntimeException('fixture handler accepts GetObject only'));
            }

            $parts = explode('/', trim((string) $path, '/'));
            $objectId = (string) end($parts);
            if ($objectId !== $catalogId && isset($options['on_data_request']) && is_callable($options['on_data_request'])) {
                $options['on_data_request']($objectId, (string) $path);
            }
            if (!empty($options['fail_first_data']) && $objectId !== $catalogId && !$firstDataFailureSent) {
                $firstDataFailureSent = true;
                return new FulfilledPromise(new Response(404, ['Content-Type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>'));
            }
            if (!array_key_exists($objectId, $objects)) {
                return new FulfilledPromise(new Response(404, ['Content-Type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>'));
            }

            $body = $objects[$objectId];
            $etag = $options['etag_overrides'][$objectId] ?? ('"' . md5($body) . '"');
            return new FulfilledPromise(new Response(200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) strlen($body),
                'ETag' => $etag,
                'Last-Modified' => 'Wed, 15 Jan 2026 09:30:00 GMT',
            ], $body));
        };
    }

    private function deploymentConfig(string $key): S3BackupDeploymentConfig
    {
        $values = [
            S3BackupDeploymentConfig::NAME_ENDPOINT => self::FAKE_ENDPOINT,
            S3BackupDeploymentConfig::NAME_REGION => self::FAKE_REGION,
            S3BackupDeploymentConfig::NAME_BUCKET => self::FAKE_BUCKET,
            S3BackupDeploymentConfig::NAME_PREFIX => self::FAKE_PREFIX,
            S3BackupDeploymentConfig::NAME_PATH_STYLE => true,
            S3BackupDeploymentConfig::NAME_ACCESS_KEY_ID => self::FAKE_ACCESS_KEY,
            S3BackupDeploymentConfig::NAME_SECRET_ACCESS_KEY => self::FAKE_SECRET,
            S3BackupDeploymentConfig::NAME_ENCRYPTION_KEY_B64 => base64_encode($key),
        ];
        $config = S3BackupDeploymentConfig::fromReader(static function (string $name) use ($values) {
            return $values[$name] ?? null;
        });
        self::assertInstanceOf(S3BackupDeploymentConfig::class, $config, 'fixture precondition: fake non-production configuration resolves through the existing Slice 2B reader');

        return $config;
    }

    private function fixtureKey(): string
    {
        return str_repeat(self::FIXTURE_KEY_BYTE, 32);
    }

    private function writeLocalBackupFixture(): void
    {
        $dir = $this->sourceStore->createDir(self::BACKUP_ID);
        $storagePath = $dir . '/storage/' . self::STORAGE_RELATIVE;
        mkdir(dirname($storagePath), 0700, true);
        $dbBytes = "-- synthetic backup fixture\nINSERT INTO cpms_patients VALUES ('" . self::NOTE_MARKER . "');\n";
        $storageBytes = '%PDF-1.4 synthetic file marker ' . self::NOTE_MARKER;
        file_put_contents($dir . '/db.sql', $dbBytes);
        file_put_contents($storagePath, $storageBytes);

        $this->manifest = [
            'schema_version' => BackupManifest::SCHEMA_VERSION,
            'engine' => BackupManifest::ENGINE,
            'engine_version' => BackupService::ENGINE_VERSION,
            'backup_id' => self::BACKUP_ID,
            'created_at' => '2026-01-15T09:30:00+00:00',
            'note' => 'synthetic fixture only',
            'db' => [
                'file' => 'db.sql',
                'sha256' => hash('sha256', $dbBytes),
                'tables' => [['name' => 'cpms_patients', 'rows' => 1]],
            ],
            'storage' => [
                'root' => 'storage',
                'files' => [[
                    'path' => self::STORAGE_RELATIVE,
                    'size' => strlen($storageBytes),
                    'sha256' => hash('sha256', $storageBytes),
                ]],
                'count' => 1,
                'bytes' => strlen($storageBytes),
            ],
            'meta' => ['wp_version' => '6.7.2', 'php_version' => PHP_VERSION, 'cpms_version' => 'test'],
        ];
        self::assertSame([], BackupManifest::validate($this->manifest), 'fixture precondition: use the delivered BackupManifest format without alteration');
        $json = json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        self::assertIsString($json);
        $this->manifestJson = $json;
        file_put_contents($dir . '/manifest.json', $json);
        file_put_contents($dir . '/manifest.json.sha256', hash('sha256', $json));
    }

    /** @param array<string, mixed> $remote @param array<string, mixed> $catalog @return array<string, mixed> */
    private function withCatalog(array $remote, array $catalog): array
    {
        $json = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($json, 'fixture precondition: authenticated catalog mutation remains JSON-encodable');

        return $this->withCatalogPlaintext($remote, $json, $catalog);
    }

    /** @param array<string, mixed> $remote @param array<string, mixed>|null $catalog @return array<string, mixed> */
    private function withCatalogPlaintext(array $remote, string $plaintext, ?array $catalog = null): array
    {
        $catalogId = (string) $remote['pointer']['catalog_object_id'];
        $cipher = $this->encryptFixture($plaintext, $this->fixtureKey(), 'mutated-catalog-' . bin2hex(random_bytes(3)));
        $remote['objects'][$catalogId] = $cipher;
        $remote['catalog_json'] = $plaintext;
        if ($catalog !== null) {
            $remote['catalog'] = $catalog;
            $remote['pointer']['object_count'] = count((array) ($catalog['entries'] ?? [])) + 1;
            $remote['pointer']['ciphertext_bytes'] = strlen($cipher);
            foreach ((array) ($catalog['entries'] ?? []) as $entry) {
                $remote['pointer']['ciphertext_bytes'] += (int) ($entry['ciphertext_bytes'] ?? 0);
            }
        } else {
            $remote['pointer']['ciphertext_bytes'] = array_sum(array_map('strlen', $remote['objects']));
        }

        return $remote;
    }

    /** @param array<string, mixed> $remote @return array<string, mixed> */
    private function withEntryCount(array $remote, int $dataEntryCount): array
    {
        $catalog = $remote['catalog'];
        $entries = (array) $catalog['entries'];
        $usedIds = array_fill_keys(array_column($entries, 'object_id'), true);
        for ($index = count($entries); $index < $dataEntryCount; ++$index) {
            $id = str_pad(dechex($index + 1), 32, '0', STR_PAD_LEFT);
            while (isset($usedIds[$id])) {
                $id = str_pad(dechex(hexdec($id) + 1), 32, '0', STR_PAD_LEFT);
            }
            $usedIds[$id] = true;
            $entries[] = [
                'role' => 'storage',
                'logical_path' => 'storage/1/boundary/' . sprintf('%04d', $index) . '.bin',
                'object_id' => $id,
                'ciphertext_bytes' => 64,
                'ciphertext_sha256' => str_repeat('0', 64),
                'remote_verification' => 'VERIFIED',
            ];
        }
        $catalog['entries'] = $entries;

        return $this->withCatalog($remote, $catalog);
    }

    private function encryptFixture(string $plaintext, string $key, string $label): string
    {
        $dir = $this->root . '/fixture-crypto';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $safe = preg_replace('/[^a-z0-9_-]/i', '-', $label);
        $source = $dir . '/' . $safe . '.plain';
        $cipher = $dir . '/' . $safe . '.enc';
        file_put_contents($source, $plaintext);
        try {
            BackupEncryptionEnvelope::encryptFile($source, $cipher, $key);
            return (string) file_get_contents($cipher);
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
            if (is_file($cipher)) {
                unlink($cipher);
            }
        }
    }

    private function decryptFixture(string $ciphertext, string $key, string $label): string
    {
        $dir = $this->root . '/fixture-crypto';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $safe = preg_replace('/[^a-z0-9_-]/i', '-', $label);
        $cipher = $dir . '/' . $safe . '.enc';
        $plain = $dir . '/' . $safe . '.plain';
        file_put_contents($cipher, $ciphertext);
        try {
            BackupEncryptionEnvelope::decryptFile($cipher, $plain, $key);
            return (string) file_get_contents($plain);
        } finally {
            if (is_file($cipher)) {
                unlink($cipher);
            }
            if (is_file($plain)) {
                unlink($plain);
            }
        }
    }

    private function stagePath(): string
    {
        return $this->root . '/reconstruction-stage-' . bin2hex(random_bytes(6));
    }

    /** @return array<string, string> */
    private function expectedFilesFromFixture(): array
    {
        $dir = $this->sourceStore->dirOf(self::BACKUP_ID);
        return [
            'db.sql' => (string) file_get_contents($dir . '/db.sql'),
            'manifest.json' => (string) file_get_contents($dir . '/manifest.json'),
            'manifest.json.sha256' => (string) file_get_contents($dir . '/manifest.json.sha256'),
            'storage/' . self::STORAGE_RELATIVE => (string) file_get_contents($dir . '/storage/' . self::STORAGE_RELATIVE),
        ];
    }

    /** @return array<string, string> */
    private function fileMap(string $root): array
    {
        $files = [];
        if (!is_dir($root)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $relative = substr($file->getPathname(), strlen(rtrim($root, '/')) + 1);
                $files[str_replace('\\', '/', $relative)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    private function assertPathAbsent(string $path, string $message): void
    {
        self::assertFalse(file_exists($path) || is_link($path), $message);
    }

    /** @return array<string, int> */
    private function logWatermarks(): array
    {
        $db = App::db();
        return [
            'audit' => (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM ' . $db->table('cpms_audit_logs')),
            'operational' => (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM ' . $db->table('cpms_operational_logs')),
        ];
    }

    /** @param array<string, int> $watermarks @param array<string, mixed> $remote */
    private function assertNoNewLogLeaks(array $watermarks, array $remote): void
    {
        $db = App::db();
        $rows = array_merge(
            $db->fetchAll('SELECT * FROM ' . $db->table('cpms_audit_logs') . ' WHERE id > %d', [$watermarks['audit']]),
            $db->fetchAll('SELECT * FROM ' . $db->table('cpms_operational_logs') . ' WHERE id > %d', [$watermarks['operational']])
        );
        foreach ($rows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            self::assertIsString($encoded);
            foreach ($this->forbiddenLeakMarkers($remote) as $marker) {
                if ($marker !== '') {
                    self::assertStringNotContainsString($marker, $encoded, 'contract: new audit/operational rows contain no secrets, PHI, logical paths or remote object keys');
                }
            }
        }
    }

    /** @return array<string, string> */
    private function databaseSnapshot(): array
    {
        global $wpdb;
        $snapshot = [];
        foreach ((array) $wpdb->get_col('SHOW TABLES') as $table) {
            $table = (string) $table;
            $isCpms = str_starts_with($table, $wpdb->prefix . 'cpms_');
            $isOptions = $table === $wpdb->options;
            if (!$isCpms && !$isOptions) {
                continue;
            }
            if (str_ends_with($table, 'cpms_audit_logs') || str_ends_with($table, 'cpms_operational_logs')) {
                continue;
            }
            $safeTable = str_replace('`', '``', $table);
            $rows = (array) $wpdb->get_results('SELECT * FROM `' . $safeTable . '`', ARRAY_A);
            usort($rows, static fn (array $left, array $right): int => strcmp(serialize($left), serialize($right)));
            $snapshot[$table] = hash('sha256', serialize($rows));
        }
        ksort($snapshot);

        return $snapshot;
    }

    private function assertNoRestoreApplyCall(string $class): void
    {
        $file = (new \ReflectionClass($class))->getFileName();
        self::assertIsString($file);
        $tokens = token_get_all((string) file_get_contents($file));
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'restoreApply') {
                continue;
            }
            for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                $candidate = $tokens[$next];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                self::assertNotSame('(', is_array($candidate) ? $candidate[1] : $candidate, 'contract: recovery never calls destructive BackupService::restoreApply()');
                break;
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $child = $entry->getPathname();
            if (is_link($child) || is_file($child)) {
                unlink($child);
            } elseif (is_dir($child)) {
                $this->removeTree($child);
            }
        }
        rmdir($path);
    }
}
