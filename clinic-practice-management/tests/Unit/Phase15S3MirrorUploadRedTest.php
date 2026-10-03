<?php
/**
 * Phase 15 — Backup & Recovery, Slice 2C: EXPLICIT VERIFIED/ACKNOWLEDGED ENCRYPTED S3
 * MIRROR UPLOAD OF ONE EXISTING LOCAL BACKUP — TEST-ONLY RED.
 *
 * ============================================================================
 * AUTHORIZED SCOPE (Product Owner direction, deliberately narrow)
 * ============================================================================
 *
 * This slice pins ONE future callable operation, conceptually
 * `mirrorBackup(existingLocalBackupId)`: take an ALREADY-EXISTING local backup,
 * verify it through the established local backup/manifest verification, encrypt
 * every logical object with the delivered Slice 1 envelope, and upload the
 * ciphertext set to the configured S3-compatible bucket using opaque keys,
 * finishing with exactly one encrypted REMOTE-ONLY catalog object. Each object
 * is either service-checksum VERIFIED or merely ACKNOWLEDGED, and the SET-level
 * claim is capped by the weakest object. Nothing in this file claims download,
 * recovery, restore, protection, recoverability, restore-readiness or RPO/RTO.
 *
 * EXPLICITLY OUT OF SCOPE — therefore NOT expressed, referenced, stubbed or
 * pre-wired anywhere in this file:
 *   download / decrypt / restore flows, `backup.run` or cron/schedule
 *   integration, remote retention, remote delete (except bounded cleanup of this
 *   operation's own temporary state after a failed attempt), admin UI/settings,
 *   database persistence of any kind (no table, no migration), provider or
 *   transport abstraction, multipart upload, remote-protection claims, Phase 20.
 *
 * ============================================================================
 * LIVE STATE AT RED AUTHORING (independently verified on 2026-10-03, BEFORE writes)
 * ============================================================================
 *   - repository root / branch / HEAD: /home/user/doctor,
 *     arena/01a10162-doctor, HEAD = bc6df5c2dfa5d0bf57a100885eac4076d3b13468
 *     = authoritative main (merge of PR #166); complete worktree clean (tracked
 *     + untracked = none); open PRs = 0
 *   - foundations already on main:
 *       Slice 1  = ClinicCore\Infrastructure\Backup\BackupEncryptionEnvelope —
 *                  envelope format v1 (magic `CPMSBK01`, 24-byte secretstream
 *                  header, 1 MiB authenticated chunks, 17-byte tags, fail-closed,
 *                  exclusive sibling temp file, atomic rename) — PR #162
 *       Slice 2A = locked `aws/aws-sdk-php ~3.399.0` with committed composer.lock
 *                  (SHA256 41e908effeb0496e3b289c9a18c535a72ceb2c3f36bbb2d48c8074b158d48e4d),
 *                  guarded vendor/autoload.php require in the plugin entry point,
 *                  release probes tests/bin/release-runtime-contract.py and
 *                  tests/bin/release-s3-client-smoke.php — PR #164
 *       Slice 2B = S3BackupDeploymentConfig (deployment-constants only, strict
 *                  fixed-order validation, disabled-when-absent, partial-fail-closed,
 *                  keyless transport view, immediate __unserialize() rejection)
 *                  + S3BackupTransportSettings + S3BackupClientFactory (zero network
 *                  at construction; documented test-only `http_handler` seam) — PR #166
 *   - local backup contracts on main: BackupService (create/verify/prune/restore;
 *     manifest + manifest.json.sha256 sidecar; verifyIn() = sidecar state +
 *     manifest backup_id equality + BackupManifest::verifyFiles), BackupManifest
 *     (schema_version 1), ProtectedBackupStore ({base}/{backup_id}/, id grammar
 *     [0-9a-z][0-9a-z._-]{3,120}, legacy read-only source); audit/operational
 *     mechanism = AuditLogger::log(...) and OpLogger::info/warning(...) with an
 *     explicit "no PHI in message/context" policy
 *   - latest migration on disk = 2026_09_26_0023_handwriting_prescription_paper.php
 *     (no 0024 exists; none is created, reserved or referenced here)
 *   - PHP policy >= 8.1 with CI Unit matrix 8.1/8.2/8.3/8.4; tests/Unit runs
 *     without WordPress; tests/ is out of the WPCS and PHPStan scope by policy,
 *     and bin/tenant-tripwire.py skips tests/ by design
 *
 * ============================================================================
 * FUTURE PRODUCT CONTRACT PINNED BY THIS FILE (absent on this head => RED)
 * ============================================================================
 *
 *   Class: ClinicCore\Application\Backup\BackupS3Mirror  (final; no interface,
 *   no provider/transport abstraction, no DI container)
 *
 *     public function __construct(
 *         ProtectedBackupStore $store,
 *         ?S3BackupDeploymentConfig $config = null,  // null = S3 not configured
 *         ?callable $http_handler = null,            // Slice 2B test seam, forwarded
 *                                                    // to S3BackupClientFactory::create();
 *                                                    // MUST stay null in production
 *         ?string $scratch_dir = null,               // private workspace for temporary
 *                                                    // ciphertext and the temporary
 *                                                    // plaintext catalog
 *         ?AuditLogger $audit = null,
 *         ?OpLogger $op = null
 *     )
 *     public function mirrorBackup(string $local_backup_id): array
 *     public static function pointerFromResult(array $result): array
 *     one private size gate with the SHAPE (int $ciphertextBytes): void — the name is
 *     deliberately NOT pinned (a RED-guessed private name is not product contract);
 *     exactly one such seam may exist and it must fail closed before any request
 *
 *   PUBLIC CONSTANTS:
 *     CATALOG_FORMAT           = 'cpms-s3-mirror-catalog'
 *     CATALOG_FORMAT_VERSION   = 1
 *     MAX_ENCRYPTED_OBJECT_BYTES = 4294967296
 *         = 4 GiB = 4 * 1024^3 bytes. UNITS: bytes, binary multiples. It caps the
 *         ENCRYPTED object (the thing a single PUT must carry), fail-closed before
 *         any S3 request. Official single-request evidence (verified 2026-10-03):
 *           - Amazon S3 User Guide "Uploading objects": "With a single PUT
 *             operation, you can upload a single object up to 5 GB in size"; larger
 *             objects must use the multipart upload API.
 *           - Amazon S3 FAQs state that same ceiling in bytes: "PUT of 5 GB
 *             (5,368,709,120 bytes)" — the ceiling is binary 5 GiB.
 *           - The pinned SDK carries the identical number as a hard constant:
 *             Aws\S3\MultipartUploader::PART_MAX_SIZE === 5368709120 (3.399.0),
 *             asserted as a control below.
 *         4,294,967,296 is therefore demonstrably 1,073,741,824 bytes (exactly
 *         1 GiB) BELOW the official ceiling, leaving headroom for S3-compatible
 *         providers that quote 5*10^9 bytes. The SDK's
 *         ObjectUploader::DEFAULT_MULTIPART_THRESHOLD (16,777,216) is an
 *         auto-switch heuristic, NOT a service limit, and is deliberately NOT
 *         adopted: Slice 2C is single-part PutObject only and never uploads parts.
 *     STRENGTH_VERIFIED     = 'VERIFIED'      (per object)
 *     STRENGTH_ACKNOWLEDGED = 'ACKNOWLEDGED'  (per object)
 *     RESULT_VERIFIED       = 'encrypted upload verified'
 *     RESULT_ACKNOWLEDGED   = 'encrypted upload acknowledged; checksum verification unavailable'
 *     POINTER_FIELDS = ['backup_id', 'mirror_id', 'catalog_object_id', 'object_count',
 *                       'ciphertext_bytes', 'verification', 'timestamp', 'result_code']
 *         The bounded evidence allowed to reach the existing audit/operational
 *         mechanism and nothing else: no logical paths, no PHI, no secrets, no
 *         configuration values.
 *
 *   RESULT ARRAY of mirrorBackup() (pinned minimum keys):
 *     ok(bool), result(string: one of the two RESULT_* constants on success),
 *     backup_id, mirror_id, catalog_object_id,
 *     object_count(int: ALL remote objects, the catalog included),
 *     ciphertext_bytes(int: total ciphertext bytes of all remote objects),
 *     verification(string: set-level strength — 'VERIFIED' only when every object
 *     including the catalog is VERIFIED),
 *     objects(list of {role, logical_path, object_id, ciphertext_bytes,
 *     ciphertext_sha256, strength}), error_code/error (?string, bounded).
 *
 *   REMOTE REPRESENTATION (exactly one PutObject per logical object, nothing else):
 *     encrypted db.sql, encrypted manifest.json, one encrypted object per
 *     manifest storage file, and LAST one encrypted REMOTE-ONLY catalog.
 *     manifest.json.sha256 is deliberately NOT mirrored as its own object.
 *     Every key: configured prefix + '/' + opaque mirror id + '/' + opaque
 *     object id, both ids 32 lowercase hex characters (16 random bytes), so no
 *     local backup id, original filename, relative path, Clinic/patient/MRN or
 *     clinical identifier, note, or backup-id-derived timestamp can appear.
 *     The local format is unchanged, the local source stays authoritative and
 *     byte-identical on every success and failure, and no local pruning is ever
 *     caused by a mirror outcome.
 *
 *   PLAINTEXT CATALOG SCHEMA v1 (temporary private local state, encrypted with the
 *   same Slice 1 envelope before upload, cleaned after success and failure, never
 *   persisted in the database):
 *     format, format_version, mirror_id, catalog_object_id, local_backup_id,
 *     local_manifest_sha256, envelope_format, envelope_format_version,
 *     entries[] = {role: database|manifest|storage, logical_path, object_id,
 *                  ciphertext_bytes, ciphertext_sha256, remote_verification}
 *     Logical paths are allowed here ONLY because this object is encrypted. No
 *     credentials and no key material, ever.
 *
 *   CHECKSUM / VERIFICATION POLICY, bounded by what the pinned SDK actually
 *   supports (verified against aws-sdk-php 3.399.0's own S3 model):
 *     - every PutObject carries the precomputed ciphertext digest as the
 *       ChecksumSHA256 request member (PutObjectRequest.ChecksumSHA256 ->
 *       header `x-amz-checksum-sha256`) plus the exact ContentLength; the SDK then
 *       skips its own checksum computation (Aws\S3\ApplyChecksumMiddleware::
 *       hasAlgorithmHeader()) and forwards the body byte-for-byte — no
 *       aws-chunked framing and no trailer rewriting.
 *     - the service proof is the echoed checksum: PutObject OUTPUT member ChecksumSHA256
 *       (header `x-amz-checksum-sha256`) of the same model. An object is VERIFIED when
 *       a 2xx acknowledgement and an echoed checksum equal to the local digest hold
 *       (the request itself carried the exact ContentLength and that same digest).
 *       Nothing more is required: AWS documents PutObject's `x-amz-object-size` as
 *       "only present if you append to an object" (S3 Express One Zone directory
 *       buckets), so an ordinary PUT response does NOT generally carry an object size
 *       and VERIFIED must never depend on it. `Size` is read for exactly one purpose —
 *       rejecting an endpoint that explicitly reports a CONTRADICTORY length.
 *     - a 2xx acknowledgement without a comparable echoed checksum is ACKNOWLEDGED and
 *       is never reported or recorded as verified.
 *     - an explicit checksum disagreement — or an explicitly contradictory length —
 *       fails closed; a request/service checksum rejection fails closed through the
 *       normal SDK error path (no 2xx, so no upload was acknowledged at all).
 *     - an ETag is never a SHA-256 proof and echoed application metadata is never
 *       body-hash proof (the ACKNOWLEDGED test supplies both as decoys).
 *     - catalog-last is an ordering/recovery aid for a later reader, NOT an atomic
 *       transaction claim; no statement is made about what a concurrent observer
 *       sees, and no "protected"/"recoverable"/"restore-ready"/RPO/RTO claim.
 *
 * ============================================================================
 * WHY THIS IS A VALID RED
 * ============================================================================
 *
 * The control tests are GREEN on this head: the runtime Sodium control, the
 * Slice 2A locked SDK foundation, the delivered Slice 1 envelope round trip, the
 * Slice 2B configuration and client factory producing zero network before any
 * command, the AWS SDK mock handler fixture itself, and a disposable local backup
 * that creates and verifies through the established BackupManifest verification.
 * Every mirror test first resolves the intended class through `mirrorClass()`,
 * which asserts the class, the entry points and the pinned constants with this
 * explicit message naming the missing Slice 2C contract — so the intended failure
 * is a named assertion about missing product functionality, never a missing-class
 * fatal, and never a harness, fixture or bootstrap defect.
 *
 * All remote interaction here is an in-process handler installed through the
 * Slice 2B test seam, driven by commands against `S3BackupClientFactory`. There
 * is no real endpoint (a reserved `.invalid` host that can never resolve), no
 * bucket, no credentials and no network; production code is untouched.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Backup\BackupManifest;
use ClinicCore\Infrastructure\Backup\BackupEncryptionEnvelope;
use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Infrastructure\Backup\S3BackupClientFactory;
use ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class Phase15S3MirrorUploadRedTest extends TestCase
{
    /** Intended Slice 2C production class — absent on this head (INTENDED PRODUCT RED). */
    private const MIRROR = 'ClinicCore\\Application\\Backup\\BackupS3Mirror';

    /** Delivered prerequisites this slice must reuse and never re-implement. */
    private const ENVELOPE_CLASS = 'ClinicCore\\Infrastructure\\Backup\\BackupEncryptionEnvelope';
    private const CONFIG_CLASS   = 'ClinicCore\\Infrastructure\\Backup\\S3BackupDeploymentConfig';
    private const FACTORY_CLASS  = 'ClinicCore\\Infrastructure\\Backup\\S3BackupClientFactory';
    private const SERVICE_CLASS  = 'ClinicCore\\Application\\Backup\\BackupService';

    /** Pinned remote representation + catalog contract. */
    private const CATALOG_FORMAT = 'cpms-s3-mirror-catalog';
    private const CATALOG_FORMAT_VERSION = 1;
    private const ROLE_DATABASE = 'database';
    private const ROLE_MANIFEST = 'manifest';
    private const ROLE_STORAGE  = 'storage';

    /** Pinned verification strengths and the only two permitted set-level claims. */
    private const STRENGTH_VERIFIED     = 'VERIFIED';
    private const STRENGTH_ACKNOWLEDGED = 'ACKNOWLEDGED';
    private const RESULT_VERIFIED       = 'encrypted upload verified';
    private const RESULT_ACKNOWLEDGED   = 'encrypted upload acknowledged; checksum verification unavailable';

    /**
     * Conservative single-PutObject ceiling for the ENCRYPTED object, in bytes
     * (4 * 1024^3 = 4 GiB). See the file header for the official evidence
     * (single PUT = 5 GB = 5,368,709,120 bytes) and for why the SDK's 16 MiB
     * auto-multipart heuristic is deliberately not adopted.
     */
    private const MAX_ENCRYPTED_OBJECT_BYTES = 4294967296;

    /** Official single-request ceiling, byte-exact, per the pinned SDK constant. */
    private const OFFICIAL_SINGLE_PUT_LIMIT_BYTES = 5368709120;

    /** Bounded audit/operational pointer: the ONLY keys allowed, in this order. */
    private const POINTER_FIELDS = ['backup_id', 'mirror_id', 'catalog_object_id', 'object_count', 'ciphertext_bytes', 'verification', 'timestamp', 'result_code'];

    /** Stable bounded BackupException codes (repository CLINIC_BACKUP_* convention). */
    private const E_NOT_CONFIGURED    = 'CLINIC_BACKUP_MIRROR_NOT_CONFIGURED';
    private const E_LOCAL_INVALID     = 'CLINIC_BACKUP_MIRROR_LOCAL_INVALID';
    private const E_ENCRYPTION_FAILED = 'CLINIC_BACKUP_MIRROR_ENCRYPTION_FAILED';
    private const E_OBJECT_TOO_LARGE  = 'CLINIC_BACKUP_MIRROR_OBJECT_TOO_LARGE';
    private const E_UPLOAD_FAILED     = 'CLINIC_BACKUP_MIRROR_UPLOAD_FAILED';
    private const E_CHECKSUM_MISMATCH = 'CLINIC_BACKUP_MIRROR_CHECKSUM_MISMATCH';
    private const E_SIZE_MISMATCH     = 'CLINIC_BACKUP_MIRROR_SIZE_MISMATCH';

    /** Clearly fake, non-production fixtures. The host can never resolve. */
    private const FAKE_ENDPOINT   = 'https://cpms-s3-red.invalid';
    private const FAKE_REGION     = 'cpms-red-region-1';
    private const FAKE_BUCKET     = 'cpms_red_bucket';
    private const FAKE_PREFIX     = 'cpms/red/mirror';
    private const FAKE_ACCESS_KEY = 'CPMSREDNOTPRODUCTIONACCESSKEY0001';
    private const FAKE_SECRET     = 'cpms-red-not-production-secret-access-key-0001';

    /** Fixed 32-byte fixture key (never real key material) + marker for leak scans. */
    private const FIXTURE_KEY_BYTE = "\xa7";

    /** Local backup fixture identity (id grammar comes from ProtectedBackupStore). */
    private const BACKUP_ID   = 'cpms-backup-20260115-093000-7a3f9c21';
    private const BACKUP_DATE = '20260115';
    private const BACKUP_TIME = '093000';
    private const SIBLING_ID  = 'cpms-backup-20260114-080000-abcdef01';
    private const UNKNOWN_ID  = 'cpms-backup-19990101-000000-ffffff00';
    private const STORAGE_HEX = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const NOTE_MARKER    = 'NOTE-MARKER-mirror-red-must-never-travel-unencrypted';
    private const PATIENT_MARKER = 'MRN-42AB-9911 patient-marker-mirror-red';

    /** Expected relative listing of an untouched local backup directory. */
    private const LOCAL_LAYOUT = ['db.sql', 'manifest.json', 'manifest.json.sha256', 'storage', 'storage/1', 'storage/1/a3', 'storage/1/a3/a1b2c3d4e5f60718293a4b5c6d7e8f90.pdf'];

    private const MISSING_CONTRACT_MESSAGE = 'Phase 15 Slice 2C INTENDED PRODUCT RED: the explicit '
        . 'verified/acknowledged encrypted S3 mirror upload of ONE existing local backup is missing on this head '
        . '(ClinicCore\\Application\\Backup\\BackupS3Mirror with mirrorBackup(string $local_backup_id): array, the '
        . 'pinned cpms-s3-mirror-catalog v1 contract, opaque prefix+mirror-id+object-id keys, ciphertext-only '
        . 'single-part PutObject uploads with the encrypted catalog uploaded last, per-object VERIFIED/ACKNOWLEDGED '
        . 'strength, the two bounded set-level claims, fail-closed checksum/size/transport/encryption/oversize '
        . 'handling, a byte-identical and never-pruned local source, temporary-state cleanup and the bounded audit '
        . 'pointer). This is the missing Slice 2C functionality this RED exists to prove — not a harness, fixture '
        . 'or bootstrap defect, and not a missing-class fatal.';

    /** @var list<string> */
    private array $roots = [];

    /** Memoised envelope-capability probe (see requireSecretstream()). */
    private static ?bool $secretstream = null;

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            if (is_dir($root)) {
                @chmod($root, 0700);
                $this->removeTree($root);
            }
            if (is_dir($root . '-scratch')) {
                @chmod($root . '-scratch', 0700);
                $this->removeTree($root . '-scratch');
            }
        }
        $this->roots = [];

        parent::tearDown();
    }

    // ========================================================================
    // CONTROLS — prerequisites that must be GREEN today on this head
    // ========================================================================

    public function testControlSupportedRuntimeProvidesSodiumSecretstream(): void
    {
        if (!extension_loaded('sodium')) {
            $polyfilled = function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')
                && defined('SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES');
            if ($polyfilled) {
                self::markTestSkipped(
                    'real ext/sodium is absent in this local sandbox harness (pure-PHP sodium_compat polyfill is '
                    . 'loaded); CI runs this control against the real extension on PHP 8.1-8.4'
                );
            }
        }

        self::assertTrue(function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push'), 'control: missing sodium secretstream primitive');
        self::assertTrue(function_exists('sodium_crypto_secretstream_xchacha20poly1305_pull'), 'control: missing sodium secretstream primitive');
        self::assertTrue(defined('SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE'), 'control: the delivered envelope distinguishes message/final frames, so a runtime must define both tag constants');
        self::assertSame(32, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 'control: the Slice 2B deployment key decodes to exactly the secretstream KEYBYTES length');
    }

    public function testControlSlice2aAwsSdkFoundationIsLoadableFromTheLockedRuntime(): void
    {
        self::assertTrue(class_exists(\Aws\S3\S3Client::class), 'control: the merged Slice 2A foundation must make the official Aws\\S3\\S3Client loadable (CI installs the locked composer vendor tree)');
        self::assertSame('3.399.0', \Aws\Sdk::VERSION, 'control: the locked SDK version must remain the approved 3.399.0 runtime');
        self::assertSame(
            self::OFFICIAL_SINGLE_PUT_LIMIT_BYTES,
            \Aws\S3\MultipartUploader::PART_MAX_SIZE,
            'control: the official single-request byte ceiling quoted by the pinned SDK is exactly 5 GiB = 5,368,709,120 bytes'
        );
        self::assertLessThan(
            self::OFFICIAL_SINGLE_PUT_LIMIT_BYTES,
            self::MAX_ENCRYPTED_OBJECT_BYTES,
            'control: the pinned conservative product cap must stay strictly below the official single-PUT ceiling'
        );
        self::assertTrue(class_exists(self::ENVELOPE_CLASS), 'control: the Slice 1 envelope this slice must reuse is present');
        self::assertTrue(class_exists(self::CONFIG_CLASS), 'control: the Slice 2B configuration this slice must reuse is present');
        self::assertTrue(class_exists(self::FACTORY_CLASS), 'control: the Slice 2B client factory this slice must reuse is present');
    }

    public function testControlDeploymentConstantsAreAbsentFromThisProcess(): void
    {
        foreach (array_keys($this->deploymentMap()) as $name) {
            self::assertFalse(defined($name), 'control: ' . $name . ' must not be defined in the test process (tests never define real deployment constants)');
        }
    }

    public function testControlSlice1EnvelopeRoundTripsALocalBackupFileWithoutPlaintextResidue(): void
    {
        $this->requireSecretstream();

        $root   = $this->workspace();
        $source = $root . '/plain.sql';
        $cipher = $root . '/cipher.enc';
        $plain  = $root . '/plain.out';
        file_put_contents($source, $this->databaseSql());

        BackupEncryptionEnvelope::encryptFile($source, $cipher, $this->fixtureKey());
        self::assertFileExists($cipher, 'control: the delivered Slice 1 envelope must encrypt a local backup file');
        $bytes = (string) file_get_contents($cipher);
        self::assertSame(BackupEncryptionEnvelope::MAGIC, substr($bytes, 0, 8), 'control: ciphertext starts with the envelope v1 magic/version marker');
        self::assertStringNotContainsString($this->databaseSql(), $bytes, 'control: the ciphertext never carries the plaintext body');

        BackupEncryptionEnvelope::decryptFile($cipher, $plain, $this->fixtureKey());
        self::assertSame($this->databaseSql(), (string) file_get_contents($plain), 'control: the envelope decrypts back byte-identically (this is the only encryption primitive Slice 2C may use)');
        self::assertSame($this->databaseSql(), (string) file_get_contents($source), 'control: encryption never touches or re-formats the source file');
    }

    public function testControlSlice2bConfigAndFactoryMakeZeroNetworkBeforeAnyCommand(): void
    {
        $config = S3BackupDeploymentConfig::fromReader($this->readerFor($this->deploymentMap()));
        self::assertInstanceOf(S3BackupDeploymentConfig::class, $config, 'control: a fully populated fake map must resolve to a configured object');

        $calls  = 0;
        $client = S3BackupClientFactory::create($config->transportSettings(), static function () use (&$calls) {
            ++$calls;
            throw new \RuntimeException('network is forbidden in this control');
        });

        self::assertSame(0, $calls, 'control: Slice 2B client construction performs zero HTTP calls before any command');
        self::assertSame(self::FAKE_ENDPOINT, (string) $client->getEndpoint(), 'control: configured HTTPS endpoint');
        self::assertSame(self::FAKE_REGION, $client->getRegion(), 'control: configured region');
        self::assertTrue((bool) $client->getConfig('use_path_style_endpoint'), 'control: explicit path style');
        self::assertSame('v4', $client->getConfig('signature_version'), 'control: SigV4');
        $http = (new \ReflectionProperty(\Aws\AwsClient::class, 'defaultRequestOptions'))->getValue($client);
        self::assertTrue(is_array($http) && true === ($http['verify'] ?? null), 'control: TLS verification is never disabled');
        self::assertStringNotContainsString(base64_encode($this->fixtureKey()), var_export($client->getConfig(), true), 'control: the backup encryption key never enters the SDK configuration');
        self::assertNull(
            S3BackupDeploymentConfig::fromReader($this->readerFor(array())),
            'control: the all-absent deployment state remains "disabled / not configured" — Slice 2C adds no ENABLED flag'
        );
    }

    public function testControlAwsSdkMockHandlerFixtureItselfIsValidForPutObject(): void
    {
        $config   = S3BackupDeploymentConfig::fromReader($this->readerFor($this->deploymentMap()));
        $requests = array();
        $client   = S3BackupClientFactory::create($config->transportSettings(), $this->recordingHandler($requests, 'raw'));

        $ciphertext = "cipher\x00bytes\xff";
        $result     = $client->putObject(array(
            'Bucket'         => self::FAKE_BUCKET,
            'Key'            => self::FAKE_PREFIX . '/m0/o0',
            'Body'           => $ciphertext,
            'ContentLength'  => strlen($ciphertext),
            'ChecksumSHA256' => base64_encode(hash('sha256', $ciphertext, true)),
        ));

        self::assertCount(1, $requests, 'control: the mock endpoint observed exactly one request for one PutObject command');
        self::assertSame('PUT', $requests[0]['method'], 'control: PutObject is a single-part PUT');
        self::assertSame('/' . self::FAKE_BUCKET . '/' . self::FAKE_PREFIX . '/m0/o0', $requests[0]['path'], 'control: path-style target is /{bucket}/{key}');
        self::assertSame('', $requests[0]['query'], 'control: a plain PutObject has no query string');
        self::assertSame($ciphertext, $requests[0]['body'], 'control: the handler receives the body bytes unchanged (no chunked framing, no trailer rewriting)');
        self::assertSame((string) strlen($ciphertext), (string) ($requests[0]['headers']['content-length'] ?? ''), 'control: the exact Content-Length reaches the endpoint');
        self::assertSame(base64_encode(hash('sha256', $ciphertext, true)), (string) ($requests[0]['headers']['x-amz-checksum-sha256'] ?? ''), 'control: the pinned SDK forwards an explicit ChecksumSHA256 as x-amz-checksum-sha256');
        self::assertSame('ZZZ=', (string) $result['ChecksumSHA256'], 'control: a mocked service checksum is readable from the PutObject result — the only checksum proof the future operation may use');
        self::assertSame(strlen($ciphertext), (int) $result['Size'], 'control: the modeled Size member is readable WHEN an endpoint returns it — the mirror uses it only to reject an explicit contradiction and never requires it as evidence');
        self::assertSame('"deadbeef"', (string) $result['ETag'], 'control: the ETag is observable but is not a checksum proof');

        $rejects  = array();
        $rejecter = S3BackupClientFactory::create($config->transportSettings(), $this->recordingHandler($rejects, 'reject-first'));
        $caught   = null;
        try {
            $rejecter->putObject(array('Bucket' => self::FAKE_BUCKET, 'Key' => self::FAKE_PREFIX . '/m0/o0', 'Body' => 'x'));
        } catch (\Throwable $error) {
            $caught = $error;
        }
        self::assertNotNull($caught, 'control: a rejected transport promise must propagate (a mirror can never rely on a silent failure)');
        self::assertCount(1, $rejects, 'control: the rejection path performs exactly one HTTP attempt per PutObject (no hidden retry storm in the fixture)');
    }

    public function testControlExistingLocalBackupFixtureCreatesAndVerifiesLocally(): void
    {
        $root   = $this->workspace();
        $backup = $this->writeLocalBackup($root, self::BACKUP_ID);
        $store  = ProtectedBackupStore::active($root);

        self::assertTrue($store->exists(self::BACKUP_ID), 'control: the disposable local backup must live in the established ProtectedBackupStore layout');
        self::assertSame(array(self::BACKUP_ID), $store->listIds(), 'control: the fixture backup is the only backup in the disposable root');
        self::assertSame(self::LOCAL_LAYOUT, $this->relativeListing($root . '/' . self::BACKUP_ID), 'control: the local layout is exactly the delivered product layout (db.sql + storage mirror + manifest + sidecar)');
        self::assertSame(array(), BackupManifest::validate($backup['manifest']), 'control: the fixture manifest must satisfy the delivered BackupManifest schema v1');

        $verified = $this->verifyLocalBackup($root, self::BACKUP_ID);
        self::assertTrue($verified['ok'], 'control: the fixture must verify cleanly through the established verification — errors: ' . json_encode($verified['errors']));
        self::assertFalse($verified['hash_missing'], 'control: the manifest hash sidecar must be present and matching for the fixture');

        // Tampering the authoritative local source must be detectable by the same
        // established verification — this is exactly what the mirror runs first.
        file_put_contents($root . '/' . self::BACKUP_ID . '/db.sql', "tampered\n");
        $tampered = $this->verifyLocalBackup($root, self::BACKUP_ID);
        self::assertFalse($tampered['ok'], 'control: a tampered db.sql must fail the established verification');
        self::assertNotEmpty($tampered['errors']);

        // The legacy state (sidecar missing) is representable and stays a warning,
        // never silent trust — Slice 2C must not lean on it.
        $this->writeLocalBackup($root, self::BACKUP_ID);
        unlink($root . '/' . self::BACKUP_ID . '/manifest.json.sha256');
        $legacy = $this->verifyLocalBackup($root, self::BACKUP_ID);
        self::assertTrue($legacy['ok'], 'control: the established verification keeps its documented legacy warning semantics');
        self::assertTrue($legacy['hash_missing'], 'control: the fixture must be able to express the "manifest.json.sha256 missing" legacy state');
    }

    // ========================================================================
    // T0 — the pinned contract surface itself
    // ========================================================================

    public function testMirrorOperationContractSurfaceIsPinnedAndClaimsNothingMore(): void
    {
        $class = $this->mirrorClass();

        self::assertSame(self::CATALOG_FORMAT, (string) constant($class . '::CATALOG_FORMAT'), 'contract: pinned plaintext catalog format name');
        self::assertSame(self::CATALOG_FORMAT_VERSION, (int) constant($class . '::CATALOG_FORMAT_VERSION'), 'contract: pinned catalog format version');
        self::assertSame(self::MAX_ENCRYPTED_OBJECT_BYTES, (int) constant($class . '::MAX_ENCRYPTED_OBJECT_BYTES'), 'contract: 4 GiB = 4,294,967,296 bytes of ENCRYPTED object per single PutObject, strictly below the official 5,368,709,120-byte ceiling; oversized fails closed before any request');
        self::assertSame(4 * 1024 * 1024 * 1024, (int) constant($class . '::MAX_ENCRYPTED_OBJECT_BYTES'), 'contract: the cap is expressed in binary bytes (units are documented, not assumed)');
        self::assertSame(self::STRENGTH_VERIFIED, (string) constant($class . '::STRENGTH_VERIFIED'));
        self::assertSame(self::STRENGTH_ACKNOWLEDGED, (string) constant($class . '::STRENGTH_ACKNOWLEDGED'));
        self::assertSame(self::RESULT_VERIFIED, (string) constant($class . '::RESULT_VERIFIED'), 'contract: the only set-level claim when every required object including the catalog is VERIFIED');
        self::assertSame(self::RESULT_ACKNOWLEDGED, (string) constant($class . '::RESULT_ACKNOWLEDGED'), 'contract: the mandatory weaker set-level claim whenever any required object is only ACKNOWLEDGED');
        self::assertSame(self::POINTER_FIELDS, array_values((array) constant($class . '::POINTER_FIELDS')), 'contract: the bounded audit/operational pointer field set — nothing else may reach the audit mechanism');

        $reflection = new \ReflectionClass($class);
        $names      = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $reflection->getConstructor()->getParameters());
        self::assertSame(array('store', 'config', 'http_handler', 'scratch_dir', 'audit', 'op'), $names, 'contract: the pinned narrow constructor — no provider/transport interface, no DI container; http_handler is the Slice 2B test-only seam and stays null in production');

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $lower = strtolower($method->getName());
            foreach (array('download', 'restore', 'delete', 'prune', 'retention', 'schedule') as $forbidden) {
                self::assertStringNotContainsString($forbidden, $lower, 'out of scope in Slice 2C: ' . $forbidden);
            }
        }
        foreach (array('PROTECTED', 'RECOVERABLE', 'RESTORE_READY', 'RPO', 'RTO', 'MULTIPART_THRESHOLD', 'PART_SIZE') as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $reflection->getConstants(), 'contract: no remote-protection/recoverability/RPO/RTO claim and no multipart knob may exist in this slice');
        }
        self::assertFalse(method_exists($class, 'mirrorAll'), 'contract: this slice mirrors ONE existing local backup, never a sweep');
        self::assertFalse(method_exists($class, 'uploadPart'), 'contract: single-part only — no multipart surface');
    }

    // ========================================================================
    // T1 — local verification precedes every remote request (fail closed)
    // ========================================================================

    public function testExistingLocalBackupIsVerifiedBeforeAnyRemoteRequest(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $this->writeLocalBackup($root, self::SIBLING_ID);

        $requests = array();
        $result   = $this->mirror($root, 'verified', $requests)->mirrorBackup(self::BACKUP_ID);
        self::assertTrue((bool) ($result['ok'] ?? false), 'contract: a verified local backup mirrors successfully — failure: ' . json_encode(array('code' => $result['error_code'] ?? null)));
        self::assertNotEmpty($requests, 'contract: the mirror must actually have issued requests for a valid local backup');

        // Tampered content => the established verification fails and NOTHING is sent.
        file_put_contents($root . '/' . self::BACKUP_ID . '/db.sql', "tampered\n");
        $after   = array();
        $failure = $this->captureMirrorFailure($this->mirror($root, 'verified', $after), self::BACKUP_ID);
        self::assertCount(0, $after, 'contract: a local backup that does not verify through the established verification must never reach S3 — not even one request');
        self::assertSame(self::E_LOCAL_INVALID, $failure['code'], 'contract: tampered local content fails closed with the bounded local-invalid code');

        // A missing manifest hash sidecar (unverifiable manifest authenticity) is not
        // trusted by an operation that must record local_manifest_sha256.
        $this->writeLocalBackup($root, self::BACKUP_ID);
        unlink($root . '/' . self::BACKUP_ID . '/manifest.json.sha256');
        $legacyRequests = array();
        $legacyFailure  = $this->captureMirrorFailure($this->mirror($root, 'verified', $legacyRequests), self::BACKUP_ID);
        self::assertCount(0, $legacyRequests, 'contract: an unverifiable manifest hash state must fail closed before any remote request');
        self::assertSame(self::E_LOCAL_INVALID, $legacyFailure['code']);

        // A non-existent backup id fails closed with no remote request either.
        $missingRequests = array();
        $missingFailure  = $this->captureMirrorFailure($this->mirror($root, 'verified', $missingRequests), self::UNKNOWN_ID);
        self::assertCount(0, $missingRequests, 'contract: a non-existent local backup id must never trigger a remote request');
        self::assertSame(self::E_LOCAL_INVALID, $missingFailure['code']);
    }

    public function testManifestDisagreeingWithItsDirectoryIdentityIsRefused(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);

        // Rewrite the manifest so its backup_id no longer matches the directory while
        // keeping its own hash sidecar consistent (a forged or relocated backup).
        $dir      = $root . '/' . self::BACKUP_ID;
        $manifest = $this->manifestFor($root, self::SIBLING_ID, self::BACKUP_ID);
        $json     = (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($dir . '/manifest.json', $json);
        file_put_contents($dir . '/manifest.json.sha256', hash('sha256', $json));
        self::assertFalse($this->verifyLocalBackup($root, self::BACKUP_ID)['ok'], 'fixture precondition: the id-mismatched manifest must be refused by the established verification');

        $requests = array();
        $failure  = $this->captureMirrorFailure($this->mirror($root, 'verified', $requests), self::BACKUP_ID);
        self::assertCount(0, $requests, 'contract: identity disagreement between the manifest and the requested backup id must fail closed with zero requests');
        self::assertSame(self::E_LOCAL_INVALID, $failure['code']);
    }

    // ========================================================================
    // T2/T3 — only ciphertext leaves the process, one object per logical part
    // ========================================================================

    public function testEveryRemoteObjectIsEncryptedAndNoPlaintextEverLeaves(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $requests = array();
        $result   = $this->mirror($root, 'verified', $requests)->mirrorBackup(self::BACKUP_ID);
        self::assertTrue((bool) ($result['ok'] ?? false), 'contract precondition — failure: ' . json_encode(array('code' => $result['error_code'] ?? null)));

        // 1 db + 1 manifest + 1 storage file + 1 remote-only catalog = 4 objects.
        self::assertCount(4, $requests, 'contract: encrypted db.sql + encrypted manifest.json + one encrypted object per manifest storage file + exactly one encrypted catalog');
        self::assertSame(4, (int) ($result['object_count'] ?? 0), 'contract: object_count covers every remote object including the catalog');

        $plaintexts = array(
            'db.sql body'     => $this->databaseSql(),
            'manifest json'   => (string) file_get_contents($root . '/' . self::BACKUP_ID . '/manifest.json'),
            'storage payload' => $this->storagePayload(),
            'note marker'     => self::NOTE_MARKER,
            'patient marker'  => self::PATIENT_MARKER,
            'sidecar hash'    => (string) file_get_contents($root . '/' . self::BACKUP_ID . '/manifest.json.sha256'),
            'local path'      => 'storage/1/a3',
            'local file name' => 'db.sql',
        );

        $observedSizes = array();
        foreach ($requests as $index => $request) {
            self::assertSame('PUT', $request['method'], 'request #' . $index . ': single-part PutObject only');
            self::assertSame('', $request['query'], 'request #' . $index . ': no ?uploads / ?partNumber / ?uploadId query');
            self::assertSame(
                BackupEncryptionEnvelope::MAGIC,
                substr($request['body'], 0, 8),
                'request #' . $index . ': every remote body starts with the envelope v1 magic/version marker — the delivered Slice 1 envelope, before any S3 request'
            );
            self::assertGreaterThan(8, strlen($request['body']), 'request #' . $index . ': a remote body is ciphertext, not an empty payload');
            foreach ($plaintexts as $label => $bytes) {
                if ('' === $bytes) {
                    continue;
                }
                self::assertStringNotContainsString($bytes, $request['body'], 'contract: plaintext "' . $label . '" must be absent from every request body');
            }
            $observedSizes[] = strlen($request['body']);
        }

        // The three data objects must be exactly the envelope output for their three
        // local sources (sizes only: the secretstream header is random per run).
        $expectedSizes = array();
        foreach (array('db.sql', 'manifest.json', 'storage/1/a3/' . self::STORAGE_HEX . '.pdf') as $relative) {
            $expectedSizes[]  = strlen($this->encryptLocally($root, $root . '/' . self::BACKUP_ID . '/' . $relative));
        }
        sort($expectedSizes);
        $dataSizes = array_slice($observedSizes, 0, 3);
        sort($dataSizes);
        self::assertSame($expectedSizes, $dataSizes, 'contract: the uploaded ciphertext sizes equal the envelope output for the local sources — no compression, padding or re-framing is invented');

        self::assertSame(array_sum($observedSizes), (int) ($result['ciphertext_bytes'] ?? -1), 'contract: ciphertext_bytes is the total of all uploaded ciphertext sizes (including the catalog)');
    }

    // ========================================================================
    // T4/T5 — encrypted catalog, uploaded last, reconstructing the mapping
    // ========================================================================

    public function testEncryptedCatalogIsRemoteOnlyAndUploadedLast(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $requests = array();
        $result   = $this->mirror($root, 'verified', $requests)->mirrorBackup(self::BACKUP_ID);
        self::assertTrue((bool) ($result['ok'] ?? false), 'contract precondition — failure: ' . json_encode(array('code' => $result['error_code'] ?? null)));

        $catalog = $requests[count($requests) - 1];
        $decoded = json_decode($this->decrypt($root, $catalog['body'], 'catalog'), true);
        self::assertIsArray($decoded, 'contract: the LAST uploaded object must decrypt to the JSON catalog');
        self::assertSame(self::CATALOG_FORMAT, (string) ($decoded['format'] ?? ''), 'contract: pinned catalog format marker');
        self::assertSame(self::CATALOG_FORMAT_VERSION, (int) ($decoded['format_version'] ?? 0), 'contract: pinned catalog format version');

        foreach (array_slice($requests, 0, -1) as $index => $request) {
            $earlier = json_decode($this->decrypt($root, $request['body'], 'early' . $index), true);
            self::assertTrue(
                ! is_array($earlier) || self::CATALOG_FORMAT !== ($earlier['format'] ?? null),
                'contract: object #' . $index . ' must not be the catalog — the encrypted catalog is uploaded last (an ordering/recovery aid, not an atomicity claim)'
            );
        }

        // Remote-only: no plaintext catalog and no extra artifact inside the local backup.
        self::assertSame(self::LOCAL_LAYOUT, $this->relativeListing($root . '/' . self::BACKUP_ID), 'contract: local format unchanged — no local catalog file, no staging leftovers');

        // The hash sidecar is deliberately NOT mirrored as its own remote object.
        $sidecar = (string) file_get_contents($root . '/' . self::BACKUP_ID . '/manifest.json.sha256');
        foreach ($requests as $index => $request) {
            self::assertNotSame($sidecar, $this->decrypt($root, $request['body'], 'scan' . $index), 'contract: manifest.json.sha256 must never become a separate remote object (#' . $index . ')');
        }
        foreach ($requests as $request) {
            self::assertStringNotContainsString(self::CATALOG_FORMAT, $request['body'], 'contract: the catalog format marker never appears in cleartext on the wire');
            self::assertStringNotContainsString('.sha256', $request['path'], 'contract: no sidecar-derived key naming');
        }
    }

    public function testCatalogEntriesReconstructLocalRolesPathsAndVerification(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $requests = array();
        $result   = $this->mirror($root, 'verified', $requests)->mirrorBackup(self::BACKUP_ID);
        self::assertTrue((bool) ($result['ok'] ?? false), 'contract precondition — failure: ' . json_encode(array('code' => $result['error_code'] ?? null)));

        $catalog = json_decode($this->decrypt($root, end($requests)['body'], 'catalog'), true);
        self::assertIsArray($catalog);
        self::assertSame(self::BACKUP_ID, (string) ($catalog['local_backup_id'] ?? ''), 'contract: the encrypted catalog carries the LOCAL backup id (allowed and needed because this object is encrypted)');
        self::assertSame(hash_file('sha256', $root . '/' . self::BACKUP_ID . '/manifest.json'), (string) ($catalog['local_manifest_sha256'] ?? ''), 'contract: the catalog pins the local manifest sha256');
        self::assertSame(BackupEncryptionEnvelope::MAGIC, (string) ($catalog['envelope_format'] ?? ''), 'contract: the catalog records the envelope format marker');
        self::assertSame(1, (int) ($catalog['envelope_format_version'] ?? 0), 'contract: the catalog records the envelope format version');
        self::assertSame(32, strlen((string) ($catalog['mirror_id'] ?? '')), 'contract: opaque mirror id (32 hex chars) is carried in the catalog');
        self::assertSame(32, strlen((string) ($catalog['catalog_object_id'] ?? '')), 'contract: the catalog names its own opaque object id');
        foreach (array('access_key', 'secret_key', 'secret', 'credential', 'key', 'encryption_key') as $forbiddenKey) {
            self::assertArrayNotHasKey($forbiddenKey, (array) $catalog, 'contract: the catalog carries no credential or key material');
        }

        $entries = array_values((array) ($catalog['entries'] ?? array()));
        self::assertCount(3, $entries, 'contract: one entry per logical DATA object (db.sql, manifest.json, every storage file); the catalog names itself only through catalog_object_id');
        self::assertSame(
            array(self::ROLE_DATABASE, self::ROLE_MANIFEST, self::ROLE_STORAGE),
            array_map(static fn (array $entry): string => (string) ($entry['role'] ?? ''), $entries),
            'contract: role vocabulary is exactly database|manifest|storage'
        );
        self::assertSame(
            array('db.sql', 'manifest.json', 'storage/1/a3/' . self::STORAGE_HEX . '.pdf'),
            array_map(static fn (array $entry): string => (string) ($entry['logical_path'] ?? ''), $entries),
            'contract: entries preserve the logical local paths so a later reader can reconstruct the mapping'
        );

        $byObject = array();
        foreach ($entries as $entry) {
            $byObject[(string) ($entry['object_id'] ?? '')] = $entry;
        }
        self::assertCount(3, array_unique(array_keys($byObject)), 'contract: every entry has a distinct opaque object id');

        $matched = 0;
        foreach ($requests as $request) {
            foreach ($byObject as $objectId => $entry) {
                if (!str_contains($request['path'], '/' . $objectId)) {
                    continue;
                }
                ++$matched;
                $logical = (string) $entry['logical_path'];
                $local   = (string) file_get_contents($root . '/' . self::BACKUP_ID . '/' . $logical);
                $remote  = $this->decrypt($root, $request['body'], 'entry-' . $matched);
                self::assertSame($local, $remote, 'contract: the ciphertext under object id ' . $objectId . ' must decrypt to exactly the local bytes of ' . $logical);
                self::assertSame(strlen($request['body']), (int) ($entry['ciphertext_bytes'] ?? -1), 'contract: the entry records the ciphertext byte size');
                self::assertSame(hash('sha256', $request['body']), (string) ($entry['ciphertext_sha256'] ?? ''), 'contract: the entry records the ciphertext SHA-256 (lowercase hex)');
                self::assertSame(self::STRENGTH_VERIFIED, (string) ($entry['remote_verification'] ?? ''), 'contract: the entry records the remote verification strength achieved, for later interpretation');
                self::assertNotSame($local, $request['body'], 'contract: the remote representation is not the local file');
            }
        }
        self::assertSame(3, $matched, 'contract: every catalog entry maps to exactly one uploaded object');
    }

    // ========================================================================
    // T6 — opaque keys, no local/tenant/patient data, no metadata leakage
    // ========================================================================

    public function testObjectKeysAreOpaqueAndFreeOfLocalTenantAndPatientData(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $requests = array();
        $result   = $this->mirror($root, 'verified', $requests)->mirrorBackup(self::BACKUP_ID);
        self::assertTrue((bool) ($result['ok'] ?? false), 'contract precondition — failure: ' . json_encode(array('code' => $result['error_code'] ?? null)));

        $prefix    = '/' . self::FAKE_BUCKET . '/' . self::FAKE_PREFIX . '/';
        $mirrorIds = array();
        $objectIds = array();
        foreach ($requests as $index => $request) {
            $path = (string) $request['path'];
            self::assertStringStartsWith($prefix, $path, 'contract: every key starts with the configured prefix');
            $segments = explode('/', substr($path, strlen($prefix)));
            self::assertCount(2, $segments, 'contract: key = configured prefix + opaque mirror id + opaque object id, nothing else (request #' . $index . ')');
            self::assertSame(1, preg_match('/^[0-9a-f]{32}$/', $segments[0]), 'contract: opaque mirror id is 32 lowercase hex characters with no structure');
            self::assertSame(1, preg_match('/^[0-9a-f]{32}$/', $segments[1]), 'contract: opaque object id is 32 lowercase hex characters (no dot, no extension, no path)');
            $mirrorIds[] = $segments[0];
            $objectIds[] = $segments[1];

            foreach ($this->forbiddenKeyFragments() as $label => $fragment) {
                self::assertStringNotContainsString($fragment, $path, 'contract: the remote key must not contain ' . $label);
            }

            foreach ($request['headers'] as $name => $value) {
                self::assertStringNotContainsString('x-amz-meta-', (string) $name, 'contract: no application metadata that could echo local names or identities');
                self::assertStringNotContainsString('content-disposition', (string) $name, 'contract: no Content-Disposition filename hint');
                self::assertStringNotContainsString($this->fixtureKey(), (string) $value, 'contract: the encryption key never reaches the request in raw form');
                self::assertStringNotContainsString(base64_encode($this->fixtureKey()), (string) $value, 'contract: the encryption key never reaches the request in encoded form');
                self::assertStringNotContainsString(hash('sha256', $this->fixtureKey()), (string) $value, 'contract: the encryption key never reaches the request in hashed form');
                foreach (array('note' => self::NOTE_MARKER, 'patient' => self::PATIENT_MARKER, 'backup id' => self::BACKUP_ID, 'secret' => self::FAKE_SECRET) as $label => $fragment) {
                    if ('authorization' === (string) $name) {
                        continue; // SigV4 Authorization is the SDK's own credential proof, never a CPMS payload
                    }
                    self::assertStringNotContainsString($fragment, (string) $value, 'contract: header ' . $name . ' must not carry ' . $label);
                }
            }
        }

        self::assertCount(1, array_unique($mirrorIds), 'contract: all objects of one mirror share exactly one opaque mirror id');
        self::assertCount(4, array_unique($objectIds), 'contract: every object has its own opaque object id');
        self::assertSame((string) reset($mirrorIds), (string) ($result['mirror_id'] ?? ''), 'contract: the operation reports the opaque mirror id it used');
    }

    // ========================================================================
    // T7/T8 — checksum-capable versus checksum-incapable endpoints
    // ========================================================================

    public function testControlVerifiedFixtureIsARealisticPutObjectResponse(): void
    {
        $config   = S3BackupDeploymentConfig::fromReader($this->readerFor($this->deploymentMap()));
        $requests = array();
        $client   = S3BackupClientFactory::create($config->transportSettings(), $this->recordingHandler($requests, 'verified'));

        $ciphertext = "cipher\x00bytes\xff";
        $result     = $client->putObject(array(
            'Bucket'         => self::FAKE_BUCKET,
            'Key'            => self::FAKE_PREFIX . '/m0/o0',
            'Body'           => $ciphertext,
            'ContentLength'  => strlen($ciphertext),
            'ChecksumSHA256' => base64_encode(hash('sha256', $ciphertext, true)),
        ));

        self::assertSame(
            base64_encode(hash('sha256', $ciphertext, true)),
            (string) $result['ChecksumSHA256'],
            'control: the VERIFIED fixture does expose the echoed service checksum the product relies on'
        );
        self::assertFalse(
            isset($result['Size']),
            'control: the VERIFIED fixture is a REALISTIC ordinary PutObject response — it reports no object size at all, so VERIFIED cannot be reaching for one'
        );
        self::assertSame('"' . hash('md5', $ciphertext) . '"', (string) $result['ETag'], 'control: the fixture still returns an ETag, which is not proof of anything');
    }

    public function testChecksumCapableEndpointYieldsVerifiedObjectsAndVerifiedSet(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $requests = array();
        $result   = $this->mirror($root, 'verified', $requests)->mirrorBackup(self::BACKUP_ID);

        self::assertTrue((bool) ($result['ok'] ?? false));
        self::assertSame(self::RESULT_VERIFIED, (string) ($result['result'] ?? ''), 'contract: every required object VERIFIED, catalog included => "encrypted upload verified"');
        self::assertSame(self::STRENGTH_VERIFIED, (string) ($result['verification'] ?? ''), 'contract: set-level verification strength');
        $objects = array_values((array) ($result['objects'] ?? array()));
        self::assertCount(4, $objects, 'contract: every object including the encrypted catalog is accounted for');
        foreach ($objects as $object) {
            self::assertSame(self::STRENGTH_VERIFIED, (string) ($object['strength'] ?? ''), 'contract: an object is VERIFIED because the service echoed the same SHA-256 the request carried — no object-size response is involved (this fixture emits none)');
        }
        self::assertStringNotContainsString('protected', strtolower((string) ($result['result'] ?? '')), 'contract: no remote-protection claim');
        self::assertStringNotContainsString('recoverab', strtolower((string) ($result['result'] ?? '')), 'contract: no recoverability claim');
    }

    public function testChecksumIncapableEndpointYieldsAcknowledgedSetAndNeverVerified(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $requests = array();
        $result   = $this->mirror($root, 'acknowledged', $requests)->mirrorBackup(self::BACKUP_ID);

        self::assertTrue((bool) ($result['ok'] ?? false), 'contract: an accepted upload without the required proof is a successful ACKNOWLEDGED mirror, not a failure');
        self::assertSame(self::RESULT_ACKNOWLEDGED, (string) ($result['result'] ?? ''), 'contract: ANY object only ACKNOWLEDGED forces the weaker set-level claim verbatim');
        self::assertNotSame(self::RESULT_VERIFIED, (string) ($result['result'] ?? ''), 'contract: an acknowledged set is never reported as verified');
        self::assertSame(self::STRENGTH_ACKNOWLEDGED, (string) ($result['verification'] ?? ''), 'contract: the aggregate strength is the weakest object strength');
        foreach (array_values((array) ($result['objects'] ?? array())) as $object) {
            self::assertSame(self::STRENGTH_ACKNOWLEDGED, (string) ($object['strength'] ?? ''), 'contract: an object whose endpoint cannot expose the required proof is never called verified — even though the ETag equals the local digest and application metadata echoes it (both are decoys, neither is body-hash proof)');
        }
        self::assertCount(4, $requests, 'contract: the ACKNOWLEDGED path still uploads the whole set with the catalog last');
        self::assertSame(4, (int) ($result['object_count'] ?? 0));
    }

    // ========================================================================
    // T9/T10/T11 — disagreement and transport failure fail closed
    // ========================================================================

    /**
     * Test correction 4: an ETag alone must never upgrade an object — even when it
     * is literally the SHA-256 of the uploaded body (a plain-PUT ETag is MD5-like,
     * and either way it is not the service checksum member the policy is built on).
     */
    public function testEtagDecoyWithoutChecksumIsAcknowledgedNeverVerified(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $requests = array();
        $result   = $this->mirror($root, 'etag-decoy', $requests)->mirrorBackup(self::BACKUP_ID);

        self::assertTrue((bool) ($result['ok'] ?? false));
        self::assertSame(self::RESULT_ACKNOWLEDGED, (string) ($result['result'] ?? ''), 'contract: an ETag is not the service checksum — the set stays ACKNOWLEDGED');
        self::assertNotSame(self::STRENGTH_VERIFIED, (string) ($result['verification'] ?? ''));
        foreach (array_values((array) ($result['objects'] ?? array())) as $object) {
            self::assertSame(self::STRENGTH_ACKNOWLEDGED, (string) ($object['strength'] ?? ''), 'contract: no object is VERIFIED on an ETag alone, even a SHA-256-valued one');
        }
        self::assertCount(4, $this->putRequests($requests), 'contract: no verification request is invented to rescue the weaker claim');
    }

    /**
     * Test correction 5: an application-metadata echo of the exact digest must never
     * upgrade an object — the value came from our own request, so it is not a
     * service-side measurement (the product never reads x-amz-meta-* at all).
     */
    public function testMetadataEchoWithoutChecksumIsAcknowledgedNeverVerified(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $requests = array();
        $result   = $this->mirror($root, 'metadata-decoy', $requests)->mirrorBackup(self::BACKUP_ID);

        self::assertTrue((bool) ($result['ok'] ?? false));
        self::assertSame(self::RESULT_ACKNOWLEDGED, (string) ($result['result'] ?? ''), 'contract: an echoed metadata value is not the service checksum — the set stays ACKNOWLEDGED');
        self::assertNotSame(self::STRENGTH_VERIFIED, (string) ($result['verification'] ?? ''));
        foreach (array_values((array) ($result['objects'] ?? array())) as $object) {
            self::assertSame(self::STRENGTH_ACKNOWLEDGED, (string) ($object['strength'] ?? ''), 'contract: no object is VERIFIED on a metadata round-trip alone');
        }
        self::assertCount(4, $this->putRequests($requests), 'contract: no verification request is invented to rescue the weaker claim');
    }

    public function testExplicitChecksumMismatchFailsClosed(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $before   = $this->snapshot($root);
        $requests = array();

        $failure = $this->captureMirrorFailure($this->mirror($root, 'checksum-mismatch', $requests), self::BACKUP_ID);
        self::assertSame(self::E_CHECKSUM_MISMATCH, $failure['code'], 'contract: a service checksum that disagrees with the local ciphertext digest fails closed (no silent downgrade to ACKNOWLEDGED)');
        self::assertCount(1, $this->putRequests($requests), 'contract: nothing further is uploaded after an explicit checksum disagreement');
        // The PUT was accepted before the proof disagreed, so the stored-but-unverified object
        // is exactly the one object this attempt may clean up — nothing else.
        $this->assertOnlyBoundedSelfCleanup($requests, $this->putPaths($requests), 1);
        self::assertSame($before, $this->snapshot($root), 'contract: the local backup stays byte-identical on a failed mirror');
    }

    public function testIncompleteFailedAttemptCleanupIsRecordedAsBoundedEvidence(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $before   = $this->snapshot($root);
        $scratch  = $this->scratch($root);
        $requests = array();

        // PUTs 1-2 accepted, PUT 3 rejected, every DELETE of the resulting cleanup rejected.
        $failure = $this->captureMirrorFailure($this->mirror($root, 'cleanup-fails', $requests), self::BACKUP_ID);
        self::assertSame(self::E_UPLOAD_FAILED, $failure['code'], 'contract: an incomplete cleanup never replaces the original bounded failure code');
        $cleanup = $failure['data']['cleanup'] ?? null;
        self::assertIsArray($cleanup, 'contract: a cleanup that could not complete is not hidden — bounded evidence travels with the failure');
        self::assertSame(array('attempted', 'failed', 'object_ids'), array_keys($cleanup), 'contract: cleanup evidence is exactly the bounded shape');
        self::assertSame(2, $cleanup['attempted'], 'contract: at most the objects this attempt stored are touched');
        self::assertSame(2, $cleanup['failed'], 'contract: failed cleanups are counted, not swallowed');
        self::assertCount(2, $cleanup['object_ids'], 'contract: the evidence names only opaque object ids of this attempt');
        foreach ($cleanup['object_ids'] as $object_id) {
            self::assertSame(1, preg_match('/^[0-9a-f]{32}$/', (string) $object_id), 'contract: cleanup evidence carries opaque ids only');
        }
        self::assertSame(array('cleanup'), array_keys($failure['data']), 'contract: no other failure payload exists');
        $this->assertOnlyBoundedSelfCleanup($requests, $this->putPaths($requests), 2);
        $blob = json_encode($failure['data'], JSON_UNESCAPED_SLASHES);
        foreach (array(self::BACKUP_ID, 'storage/', 'manifest.json', 'db.sql', self::NOTE_MARKER, self::PATIENT_MARKER, self::FAKE_SECRET, self::FAKE_ACCESS_KEY, self::FAKE_ENDPOINT) as $forbidden) {
            self::assertSame(0, substr_count((string) $blob, $forbidden), 'contract: cleanup evidence never leaks identifiers, paths, markers or configuration values');
        }
        self::assertSame($before, $this->snapshot($root), 'contract: an incomplete remote cleanup never touches or prunes the local source');
        self::assertSame(array(), $this->relativeListing($scratch), 'contract: local temporary state is cleaned even when the remote cleanup failed');
    }

    /**
     * Pinning the ONLY role left to a reported length: an endpoint that explicitly
     * contradicts the request's Content-Length fails closed. (Length is never
     * required to reach VERIFIED — see the VERIFIED fixture, which reports none.)
     */
    public function testContradictoryReportedLengthFailsClosed(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $before   = $this->snapshot($root);
        $requests = array();

        $failure = $this->captureMirrorFailure($this->mirror($root, 'size-mismatch', $requests), self::BACKUP_ID);
        self::assertSame(self::E_SIZE_MISMATCH, $failure['code'], 'contract: an endpoint that explicitly reports a length contradicting the local ciphertext size fails closed (the echoed checksum alone did NOT upgrade it)');
        self::assertCount(1, $this->putRequests($requests), 'contract: the remaining objects and the catalog are not uploaded after a length disagreement');
        $this->assertOnlyBoundedSelfCleanup($requests, $this->putPaths($requests), 1);
        self::assertSame($before, $this->snapshot($root));
    }

    public function testUploadAndTransientFailuresFailClosedWithBoundedErrors(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $before = $this->snapshot($root);

        $first = array();
        $early = $this->captureMirrorFailure($this->mirror($root, 'reject-first', $first), self::BACKUP_ID);
        self::assertSame(self::E_UPLOAD_FAILED, $early['code'], 'contract: a transport/auth failure maps to the bounded upload-failed code');
        self::assertCount(1, $first, 'contract: nothing further is uploaded and nothing is cleaned (this attempt created no object), and the remaining objects, catalog included, are never sent');

        $later = array();
        $mid   = $this->captureMirrorFailure($this->mirror($root, 'reject-at-3', $later), self::BACKUP_ID);
        self::assertSame(self::E_UPLOAD_FAILED, $mid['code']);
        $puts = $this->putRequests($later);
        self::assertCount(3, $puts, 'contract: exactly the objects attempted up to the failure are uploaded; the catalog is not uploaded when the data set never completed');
        $this->assertOnlyBoundedSelfCleanup($later, $this->putPaths($later), 2);
        $tail = json_decode($this->decrypt($root, $puts[count($puts) - 1]['body'], 'tail'), true);
        self::assertTrue(! is_array($tail) || self::CATALOG_FORMAT !== ($tail['format'] ?? null), 'contract: the last uploaded object of an incomplete set is not a catalog');
        self::assertSame($before, $this->snapshot($root), 'contract: no local change and no local pruning on failure');

        foreach (array($early, $mid) as $failure) {
            foreach (array('message' => (string) $failure['message'], 'code' => (string) $failure['code'], 'json' => (string) json_encode($failure)) as $surface) {
                foreach ($this->forbiddenKeyFragments() + array('access key' => self::FAKE_ACCESS_KEY, 'secret key' => self::FAKE_SECRET, 'endpoint' => self::FAKE_ENDPOINT, 'bucket' => self::FAKE_BUCKET, 'region' => self::FAKE_REGION, 'local root' => $root, 'key material' => $this->fixtureKey(), 'key b64' => base64_encode($this->fixtureKey())) as $label => $fragment) {
                    if ('' === $fragment) {
                        continue;
                    }
                    self::assertStringNotContainsString($fragment, $surface, 'contract: bounded errors must never contain ' . $label);
                }
            }
        }
    }

    // ========================================================================
    // T12/T13/T14 — configuration, encryption and size gates before any request
    // ========================================================================

    public function testMissingOrInvalidConfigurationSendsNothing(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $class    = $this->mirrorClass();
        $store    = ProtectedBackupStore::active($root);
        $scratch  = $this->scratch($root);
        $requests = array();

        // The disabled deployment state (no constants) => the operation is unconfigured.
        $unconfigured = new $class($store, null, $this->recordingHandler($requests, 'verified'), $scratch);
        $failure      = $this->captureMirrorFailure($unconfigured, self::BACKUP_ID);
        self::assertSame(self::E_NOT_CONFIGURED, $failure['code'], 'contract: with no deployment configuration the operation fails closed with the bounded not-configured code');
        self::assertCount(0, $requests, 'contract: no remote request may be attempted without configuration');
        self::assertSame(array(), $this->relativeListing($scratch), 'contract: the unconfigured path leaves no temporary state behind');

        // A partially defined deployment is already a hard configuration error upstream
        // (Slice 2B), so no client and no request can exist at all.
        $partial = $this->deploymentMap();
        unset($partial['CPMS_S3_BUCKET']);
        $caught = null;
        try {
            S3BackupDeploymentConfig::fromReader($this->readerFor($partial));
        } catch (BackupException $error) {
            $caught = $error;
        }
        self::assertNotNull($caught, 'control precondition: partial configuration fails closed in the delivered Slice 2B contract');
        self::assertSame('CLINIC_BACKUP_S3_PARTIAL_CONFIG', (string) $caught->getErrorCode());
        self::assertCount(0, $requests, 'contract: a partial configuration never produces a remote request');
    }

    public function testEncryptionFailureNeverSendsPlaintext(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $scratch  = $this->scratch($root);
        $before   = $this->snapshot($root . '/' . self::BACKUP_ID);

        // Make the private scratch workspace unwritable: no ciphertext can be
        // produced, so nothing at all may be sent — not even ciphertext.
        chmod($scratch, 0500);
        $requests = array();
        try {
            $failure = $this->captureMirrorFailure($this->mirror($root, 'verified', $requests, $scratch), self::BACKUP_ID);
        } finally {
            chmod($scratch, 0700);
        }

        self::assertSame(self::E_ENCRYPTION_FAILED, $failure['code'], 'contract: an encryption failure maps to the bounded encryption-failed code (the same no-oracle policy as the delivered envelope)');
        self::assertCount(0, $requests, 'contract: when encryption fails, no plaintext — and no request at all — may reach the transport');
        self::assertSame($before, $this->snapshot($root . '/' . self::BACKUP_ID), 'contract: an encryption failure leaves the local source untouched');
        foreach (array('message' => (string) $failure['message']) as $surface) {
            foreach (array($this->fixtureKey(), base64_encode($this->fixtureKey()), $this->databaseSql(), self::PATIENT_MARKER) as $fragment) {
                self::assertStringNotContainsString($fragment, $surface, 'contract: the encryption failure surfaces never carry key material, plaintext or PHI');
            }
        }
    }

    public function testOversizedCiphertextIsRejectedBeforeAnyRequest(): void
    {
        $class = $this->mirrorClass();

        self::assertSame(self::MAX_ENCRYPTED_OBJECT_BYTES, (int) constant($class . '::MAX_ENCRYPTED_OBJECT_BYTES'), 'contract: the pinned cap is 4 GiB expressed in binary bytes (4 * 1024^3 = 4,294,967,296)');
        self::assertLessThan(self::OFFICIAL_SINGLE_PUT_LIMIT_BYTES, (int) constant($class . '::MAX_ENCRYPTED_OBJECT_BYTES'), 'contract: the cap stays below the official single-PUT ceiling of 5,368,709,120 bytes');
        self::assertGreaterThan(0, self::OFFICIAL_SINGLE_PUT_LIMIT_BYTES - (int) constant($class . '::MAX_ENCRYPTED_OBJECT_BYTES'), 'contract: the margin is real, not an invented threshold');

        // A real 4 GiB fixture is absurd in a unit suite, so the gate itself is
        // exercised directly (and it must be the ONLY size story: no multipart
        // fallback may absorb an oversized object in this slice).
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $requests = array();
        $mirror   = $this->mirror($root, 'verified', $requests);
        // The gate is pinned by SHAPE, not by name: one and only one method taking a
        // single int (the ciphertext byte size) and returning void. A RED-guessed
        // private name is not product contract, so GREEN may name it freely — but it
        // may not grow a second size story or a multipart fallback.
        $guard = null;
        foreach ((new \ReflectionClass($class))->getMethods() as $method) {
            $parameters = $method->getParameters();
            if (1 !== count($parameters) || 'void' !== (string) $method->getReturnType()) {
                continue;
            }
            $type = $parameters[0]->getType();
            if ($type instanceof \ReflectionNamedType && 'int' === $type->getName() && ! $type->allowsNull()) {
                self::assertNull($guard, 'contract: exactly ONE single-PUT size gate may exist — no second size story, no multipart fallback in this slice');
                $guard = $method;
            }
        }
        self::assertNotNull($guard, 'contract: the operation must own one bounded fail-closed single-PUT size gate — shape: (int $ciphertextBytes): void, fail-closed BEFORE any S3 request');
        $guard->setAccessible(true);

        $guard->invoke($mirror, self::MAX_ENCRYPTED_OBJECT_BYTES);
        self::assertCount(0, $requests, 'control: the gate is a pure pre-flight check');

        $tooBig = null;
        try {
            $guard->invoke($mirror, self::MAX_ENCRYPTED_OBJECT_BYTES + 1);
        } catch (BackupException $error) {
            $tooBig = $error;
        }
        self::assertNotNull($tooBig, 'contract: one byte over the pinned cap must fail closed');
        self::assertSame(self::E_OBJECT_TOO_LARGE, $tooBig->getErrorCode(), 'contract: oversized encrypted object => CLINIC_BACKUP_MIRROR_OBJECT_TOO_LARGE before any S3 request');
        self::assertSame(array(), $tooBig->data, 'contract: the bounded error carries no sizes, paths or configuration values');
        self::assertCount(0, $requests, 'contract: no request was attempted for the oversized object (single-part only, no multipart fallback in this slice)');
    }

    // ========================================================================
    // T15/T16/T17 — authoritative local source, no pruning, temporary-state hygiene
    // ========================================================================

    public function testLocalBackupRemainsByteIdenticalAndNeverPrunedOnSuccessAndFailure(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $this->writeLocalBackup($root, self::SIBLING_ID);
        $this->writeLocalBackup($root, self::UNKNOWN_ID);
        $before   = $this->snapshot($root);

        $successRequests = array();
        $ok              = $this->mirror($root, 'verified', $successRequests)->mirrorBackup(self::BACKUP_ID);
        self::assertTrue((bool) ($ok['ok'] ?? false));
        self::assertSame($before, $this->snapshot($root), 'contract: a successful mirror never prunes, deletes, rewrites or re-formats ANY local backup in the root — the local source stays authoritative and byte-identical');

        $failureRequests = array();
        $failure         = $this->captureMirrorFailure($this->mirror($root, 'reject-first', $failureRequests), self::BACKUP_ID);
        self::assertSame(self::E_UPLOAD_FAILED, $failure['code']);
        self::assertSame($before, $this->snapshot($root), 'contract: a failed mirror never prunes or mutates local state either — the mirror outcome has zero local retention effect');

        self::assertSame(array(self::BACKUP_ID, self::SIBLING_ID, self::UNKNOWN_ID), ProtectedBackupStore::active($root)->listIds(), 'contract: every local backup id survives both outcomes (the store\'s descending listing is unchanged — the mirror never prunes)');
    }

    public function testTemporaryCiphertextAndCatalogStateAreCleanedUpOnSuccessAndFailure(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $scratch  = $this->scratch($root);

        $requests = array();
        $ok       = $this->mirror($root, 'verified', $requests, $scratch)->mirrorBackup(self::BACKUP_ID);
        self::assertTrue((bool) ($ok['ok'] ?? false));
        self::assertSame(array(), $this->relativeListing($scratch), 'contract: temporary ciphertext and the temporary plaintext catalog are removed after success — private local state only, nothing left behind');
        self::assertSame(self::LOCAL_LAYOUT, $this->relativeListing($root . '/' . self::BACKUP_ID), 'contract: nothing is staged inside the local backup directory itself');

        $failedRequests = array();
        $failure        = $this->captureMirrorFailure($this->mirror($root, 'reject-at-2', $failedRequests, $scratch), self::BACKUP_ID);
        self::assertSame(self::E_UPLOAD_FAILED, $failure['code']);
        self::assertSame(array(), $this->relativeListing($scratch), "contract: the operation's own temporary ciphertext/catalog state is also cleaned after failure (bounded failed-attempt cleanup may issue this attempt's own DELETEs only)");
        self::assertSame(self::LOCAL_LAYOUT, $this->relativeListing($root . '/' . self::BACKUP_ID), 'contract: a failed mirror leaves no local residue in the backup directory');
    }

    // ========================================================================
    // T18 — bounded audit/operational pointer
    // ========================================================================

    public function testAuditPointerCarriesOnlyBoundedEvidence(): void
    {
        $class = $this->mirrorClass();

        $verified = array(
            'ok'                => true,
            'result'            => self::RESULT_VERIFIED,
            'backup_id'         => self::BACKUP_ID,
            'mirror_id'         => str_repeat('ab', 16),
            'catalog_object_id' => str_repeat('cd', 16),
            'object_count'      => 4,
            'ciphertext_bytes'  => 4096,
            'verification'      => self::STRENGTH_VERIFIED,
            'timestamp'         => 1767572400,
            'result_code'       => 'ok',
            'objects'           => array(array('role' => self::ROLE_STORAGE, 'logical_path' => 'storage/1/a3/' . self::STORAGE_HEX . '.pdf', 'object_id' => str_repeat('ef', 16), 'ciphertext_bytes' => 100, 'ciphertext_sha256' => str_repeat('0', 64), 'strength' => self::STRENGTH_VERIFIED)),
        );

        $pointer = $class::pointerFromResult($verified);
        self::assertSame(self::POINTER_FIELDS, array_keys($pointer), 'contract: the pointer is exactly the bounded evidence needed to locate the mirror later: local backup id, opaque mirror id, opaque catalog object id, object count, ciphertext byte total, aggregate upload verification strength, timestamp/result code — nothing more');
        self::assertSame(self::BACKUP_ID, (string) $pointer['backup_id'], 'contract: the pointer keeps the LOCAL backup id (the locator a later phase needs)');
        self::assertSame(str_repeat('ab', 16), (string) $pointer['mirror_id'], 'contract: the pointer keeps the opaque mirror id');
        self::assertSame(str_repeat('cd', 16), (string) $pointer['catalog_object_id'], 'contract: the pointer keeps the opaque catalog object id');
        self::assertSame(4, (int) $pointer['object_count']);
        self::assertSame(4096, (int) $pointer['ciphertext_bytes']);
        self::assertSame(self::STRENGTH_VERIFIED, (string) $pointer['verification'], 'contract: the aggregate upload verification strength is retained for later interpretation');
        self::assertSame('ok', (string) $pointer['result_code']);

        foreach ($this->deepStrings($pointer) as $value) {
            foreach (array('db.sql', 'manifest.json', 'storage/1/a3', self::STORAGE_HEX . '.pdf', self::NOTE_MARKER, self::PATIENT_MARKER, self::FAKE_SECRET, self::FAKE_ACCESS_KEY, self::FAKE_ENDPOINT, self::FAKE_BUCKET, self::FAKE_PREFIX, $this->fixtureKey()) as $forbidden) {
                self::assertStringNotContainsString($forbidden, $value, 'contract: no logical path, PHI, note, secret or configuration value may enter the audit/operational pointer');
            }
        }

        // A failed operation still yields a bounded pointer, and it must not smuggle
        // the error message or the object list into the audit mechanism.
        $failed = array(
            'ok'                => false,
            'result'            => '',
            'backup_id'         => self::BACKUP_ID,
            'mirror_id'         => str_repeat('ab', 16),
            'catalog_object_id' => '',
            'object_count'      => 1,
            'ciphertext_bytes'  => 100,
            'verification'      => self::STRENGTH_ACKNOWLEDGED,
            'timestamp'         => 1767572400,
            'result_code'       => self::E_UPLOAD_FAILED,
            'error'             => 'mock transport rejection mentioning ' . self::PATIENT_MARKER,
            'error_code'        => self::E_UPLOAD_FAILED,
            'objects'           => array(array('role' => self::ROLE_STORAGE, 'logical_path' => 'storage/1/a3/' . self::STORAGE_HEX . '.pdf')),
        );
        $failedPointer = $class::pointerFromResult($failed);
        self::assertSame(self::POINTER_FIELDS, array_keys($failedPointer));
        self::assertSame(self::E_UPLOAD_FAILED, (string) $failedPointer['result_code'], 'contract: the failure is recorded as a bounded result code only');
        foreach ($this->deepStrings($failedPointer) as $value) {
            self::assertStringNotContainsString(self::PATIENT_MARKER, $value, 'contract: the failure message never reaches the pointer');
            self::assertStringNotContainsString('storage/1/a3', $value, 'contract: logical paths never reach the pointer');
        }
    }

    // ========================================================================
    // T19/T20 — boundary guards (GREEN on this head and must stay GREEN)
    // ========================================================================

    public function testMirrorIsNotWiredIntoBackupRunOrPersistenceInThisSlice(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $needles    = array('BackupS3Mirror', 'mirrorBackup', self::CATALOG_FORMAT, 'S3Mirror', 'MAX_ENCRYPTED_OBJECT_BYTES');
        $files      = array(
            'src/Application/Backup/BackupService.php',
            'src/Application/Jobs/BackupRunHandler.php',
            'src/Bootstrap/App.php',
            'src/Infrastructure/Backup/S3BackupClientFactory.php',
            'src/Infrastructure/Backup/S3BackupDeploymentConfig.php',
            'src/Infrastructure/Backup/S3BackupTransportSettings.php',
            'src/Infrastructure/Backup/ProtectedBackupStore.php',
            'src/Infrastructure/Backup/BackupEncryptionEnvelope.php',
            'src/Domain/Backup/BackupManifest.php',
        );

        foreach ($files as $relative) {
            $file = $pluginRoot . '/' . $relative;
            self::assertFileExists($file, 'control precondition: ' . $relative . ' must exist for the boundary check');
            $source = (string) file_get_contents($file);
            foreach ($needles as $needle) {
                self::assertStringNotContainsString($needle, $source, 'contract: Slice 2C must not be integrated into ' . $relative . ' — no backup.run/job wiring, no automatic invocation, no coupling of the delivered primitives to a mirror');
            }
        }

        self::assertTrue(class_exists(self::SERVICE_CLASS), 'control precondition: the delivered BackupService must be loadable');
        foreach (get_class_methods(self::SERVICE_CLASS) as $method) {
            self::assertStringNotContainsString('mirror', strtolower((string) $method), 'contract: BackupService gains no mirror entry point in this slice');
        }

        // No persistence of any kind: no migration file, no new table name.
        $migrations = (array) glob($pluginRoot . '/src/Migrations/*.php');
        self::assertNotEmpty($migrations, 'control precondition: the delivered migration set must be discoverable');
        foreach ($migrations as $migration) {
            $name = strtolower(basename((string) $migration));
            self::assertStringNotContainsString('mirror', $name, 'out of scope: no migration for mirror state');
            self::assertStringNotContainsString('_s3', $name, 'out of scope: no migration for S3 state');
        }
        self::assertFileExists($pluginRoot . '/src/Migrations/2026_09_26_0023_handwriting_prescription_paper.php', 'control: the latest migration on this head remains 0023 (untouched)');
    }

    public function testSinglePutOnlyNeverTouchesTheMultipartApi(): void
    {
        $this->mirrorClass();
        $this->requireSecretstream();
        $root     = $this->workspace();
        $this->writeLocalBackup($root, self::BACKUP_ID);
        $requests = array();
        $ok       = $this->mirror($root, 'verified', $requests)->mirrorBackup(self::BACKUP_ID);
        self::assertTrue((bool) ($ok['ok'] ?? false), 'contract precondition — failure: ' . json_encode(array('code' => $ok['error_code'] ?? null)));

        foreach ($requests as $index => $request) {
            self::assertSame('PUT', $request['method'], 'contract: every remote call is one single PUT (request #' . $index . ')');
            self::assertSame('', $request['query'], 'contract: no ?uploads, no ?partNumber, no ?uploadId');
            self::assertStringNotContainsString('uploads', (string) $request['path'], 'contract: no multipart resource in any key');
            self::assertStringNotContainsString('partNumber', implode(',', array_keys($request['headers'])), 'contract: no part-aware request header');
        }
        self::assertCount(4, $requests, 'contract: exactly one PutObject per remote object and nothing else (4 objects here, none of them a multipart round trip)');
    }

    // ========================================================================
    // helpers
    // ========================================================================

    /**
     * Resolver for the intended Slice 2C class. On this head the class does not
     * exist, so every contract test fails here with a named assertion — the intended
     * product RED — instead of a missing-class fatal error.
     */
    private function mirrorClass(): string
    {
        self::assertTrue(class_exists(self::MIRROR), self::MISSING_CONTRACT_MESSAGE);
        self::assertTrue(method_exists(self::MIRROR, 'mirrorBackup'), 'contract: public function BackupS3Mirror::mirrorBackup(string $local_backup_id): array');
        self::assertTrue(method_exists(self::MIRROR, 'pointerFromResult'), 'contract: public static function BackupS3Mirror::pointerFromResult(array $result): array — the single bounded pointer derivation');
        foreach (array('CATALOG_FORMAT', 'CATALOG_FORMAT_VERSION', 'MAX_ENCRYPTED_OBJECT_BYTES', 'STRENGTH_VERIFIED', 'STRENGTH_ACKNOWLEDGED', 'RESULT_VERIFIED', 'RESULT_ACKNOWLEDGED', 'POINTER_FIELDS') as $constant) {
            self::assertTrue(defined(self::MIRROR . '::' . $constant), 'contract: public const BackupS3Mirror::' . $constant . ' — ' . self::MISSING_CONTRACT_MESSAGE);
        }

        return self::MIRROR;
    }

    /**
     * The mirror tests need the secretstream API the delivered Slice 1 envelope uses.
     * A pure-PHP sodium_compat polyfill is byte-compatible for the framing these tests
     * depend on (magic + 24-byte header + payload + 17-byte tag), so they run either
     * way; only a runtime with neither source skips. The real-extension guarantee
     * itself is pinned by the Sodium control above, with CI as the authority.
     */
    private function requireSecretstream(): void
    {
        if (self::$secretstream === null) {
            self::$secretstream = $this->probeSecretstream();
        }
        if (self::$secretstream) {
            return;
        }

        self::markTestSkipped(
            'this test depends on the delivered Slice 1 envelope actually round-tripping, which needs the complete '
            . 'sodium secretstream API (including ..._TAG_MESSAGE). The local sandbox harness only has a partial '
            . 'pure-PHP sodium_compat polyfill (that constant is undefined, so the envelope correctly fails closed), '
            . 'so these assertions are CI-authoritative: the Unit suite runs PHP 8.1-8.4 with real ext/sodium. '
            . 'This is a harness capability limit, not a product finding, and never the intended RED.'
        );
    }

    private function probeSecretstream(): bool
    {
        $api = function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_push')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_pull')
            && defined('SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL')
            && defined('SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE');
        if (! $api) {
            return false;
        }

        $dir = rtrim(sys_get_temp_dir(), '/') . '/cpms-slice2c-capability-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            file_put_contents($dir . '/plain', "probe-payload
");
            BackupEncryptionEnvelope::encryptFile($dir . '/plain', $dir . '/cipher', $this->fixtureKey());
            BackupEncryptionEnvelope::decryptFile($dir . '/cipher', $dir . '/again', $this->fixtureKey());

            return is_file($dir . '/again') && 'probe-payload' . "\n" === (string) file_get_contents($dir . '/again');
        } catch (\Throwable $error) {
            return false;
        } finally {
            foreach (array('plain', 'cipher', 'again') as $name) {
                if (is_file($dir . '/' . $name)) {
                    @unlink($dir . '/' . $name);
                }
            }
            @rmdir($dir);
        }
    }

    /**
     * The ONLY non-upload request Slice 2C may issue is bounded best-effort cleanup of the
     * objects THIS attempt already PUT (its own opaque keys). Pinning it this way keeps
     * "nothing further is uploaded after a failure" exact while still allowing — and
     * constraining — the cleanup that GREEN added for failed attempts.
     *
     * @param array<int, array<string, mixed>> $requests
     * @param list<string>                     $put_paths
     */
    private function assertOnlyBoundedSelfCleanup(array $requests, array $put_paths, int $max_deletes): void
    {
        $deletes = 0;
        foreach ($requests as $request) {
            if ('PUT' === $request['method']) {
                continue;
            }
            ++$deletes;
            self::assertLessThanOrEqual($max_deletes, $deletes, 'contract: cleanup touches at most the objects this attempt uploaded — no bucket sweep');
            self::assertSame('DELETE', $request['method'], 'contract: no operation kind other than PUT and this-attempt-only DELETE exists in Slice 2C');
            self::assertContains((string) $request['path'], $put_paths, 'contract: cleanup may only target objects THIS attempt uploaded');
            self::assertSame(
                1,
                preg_match('#^/' . preg_quote(self::FAKE_BUCKET, '#') . '/' . preg_quote(self::FAKE_PREFIX, '#') . '/[0-9a-f]{32}/[0-9a-f]{32}$#', (string) $request['path']),
                'contract: cleanup keys keep the opaque bucket/prefix/mirror/object shape'
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     *
     * @return list<string>
     */
    private function putPaths(array $requests): array
    {
        return array_values(array_map(static fn (array $request): string => (string) $request['path'], $this->putRequests($requests)));
    }

    /**
     * Upload requests only: Slice 2C may additionally issue the bounded best-effort
     * cleanup of its own objects after a partial failure, so "what was uploaded" is
     * always counted over PUTs, never over total requests.
     *
     * @param array<int, array<string, mixed>> $requests
     *
     * @return list<array<string, mixed>>
     */
    private function putRequests(array $requests): array
    {
        return array_values(array_filter($requests, static fn (array $request): bool => 'PUT' === $request['method']));
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     */
    private function mirror(string $root, string $policy, array &$requests, ?string $scratch = null): object
    {
        $class  = $this->mirrorClass();
        $store  = ProtectedBackupStore::active($root);
        $config = S3BackupDeploymentConfig::fromReader($this->readerFor($this->deploymentMap()));

        return new $class($store, $config, $this->recordingHandler($requests, $policy), $scratch ?? $this->scratch($root));
    }

    /**
     * Normalises BOTH failure signals (a thrown BackupException and an ok=false
     * result array) into one bounded shape, so the contract states the guarantee
     * without prescribing which signal style GREEN uses.
     *
     * @return array{code:?string,message:?string}
     */
    private function captureMirrorFailure(object $mirror, string $backupId): array
    {
        try {
            $result = $mirror->mirrorBackup($backupId);
        } catch (BackupException $error) {
            return array(
                'code'    => $error->getErrorCode(),
                'message' => $error->getMessage(),
                // Bounded failure payload (Slice 2C: cleanup evidence only, never secrets/paths).
                'data'    => $error->data,
            );
        } catch (\Throwable $error) {
            self::fail('contract: a failed mirror must surface a bounded BackupException, never a raw ' . get_class($error) . ' (' . substr($error->getMessage(), 0, 160) . ')');
        }

        self::assertIsArray($result, 'contract: mirrorBackup() returns a result array');
        self::assertFalse((bool) ($result['ok'] ?? true), 'contract: a failed mirror reports ok=false');

        return array(
            'code'    => isset($result['error_code']) ? (string) $result['error_code'] : null,
            'message' => isset($result['error']) ? (string) $result['error'] : null,
        );
    }

    /**
     * In-process mock endpoint: it only records what the SDK asked to send and answers
     * by a fixed policy. Nothing leaves the process; no credentials are consulted.
     *
     * @param array<int, array<string, mixed>> $requests
     */
    private function recordingHandler(array &$requests, string $policy): callable
    {
        return function ($request, array $options) use (&$requests, $policy) {
            $body    = (string) $request->getBody();
            $headers = array();
            foreach ($request->getHeaders() as $name => $values) {
                $headers[strtolower((string) $name)] = implode(',', array_map(static fn ($value): string => (string) $value, (array) $values));
            }
            $requests[] = array(
                'method'  => $request->getMethod(),
                'path'    => $request->getUri()->getPath(),
                'query'   => $request->getUri()->getQuery(),
                'headers' => $headers,
                'body'    => $body,
            );

            $index = count($requests);
            if ('reject-first' === $policy && 1 === $index) {
                return new RejectedPromise(new \RuntimeException('mock endpoint: Access Denied (fixture)'));
            }
            if ('reject-at-2' === $policy && 2 === $index) {
                return new RejectedPromise(new \RuntimeException('mock endpoint: connection reset (fixture)'));
            }
            if ('reject-at-3' === $policy && 3 === $index) {
                return new RejectedPromise(new \RuntimeException('mock endpoint: transient failure (fixture)'));
            }
            if ('cleanup-fails' === $policy) {
                if ('DELETE' === $request->getMethod()) {
                    return new RejectedPromise(new \RuntimeException('mock endpoint: delete denied (fixture)'));
                }
                if (3 === $index) {
                    return new RejectedPromise(new \RuntimeException('mock endpoint: transient failure (fixture)'));
                }
            }
            if ('raw' === $policy) {
                return new FulfilledPromise(new Response(200, array('ETag' => '"deadbeef"', 'x-amz-checksum-sha256' => 'ZZZ=', 'x-amz-object-size' => (string) strlen($body)), ''));
            }

            $responseHeaders = array('ETag' => '"' . hash('md5', $body) . '"');
            if ('verified' === $policy) {
                // A checksum-capable endpoint: it accepts the supplied digest and echoes
                // the service checksum. An ordinary PutObject response carries no object
                // size (AWS documents x-amz-object-size as append/S3-Express-only), so
                // none is fabricated here — VERIFIED must follow from the checksum alone.
                $responseHeaders['x-amz-checksum-sha256'] = (string) ($headers['x-amz-checksum-sha256'] ?? '');
            } elseif ('acknowledged' === $policy) {
                // A checksum-incapable endpoint, with decoys: an ETag equal to the
                // local digest and echoed application metadata. Neither is body-hash
                // proof, so no object may be upgraded to VERIFIED.
                $responseHeaders['ETag']              = '"' . hash('sha256', $body) . '"';
                $responseHeaders['x-amz-meta-sha256'] = base64_encode(hash('sha256', $body, true));
            } elseif ('etag-decoy' === $policy) {
                // Isolated decoy: the ONLY thing that looks like proof is an ETag
                // equal to the SHA-256 of the body. Never body-hash proof.
                $responseHeaders['ETag'] = '"' . hash('sha256', $body) . '"';
            } elseif ('metadata-decoy' === $policy) {
                // Isolated decoy: application metadata echoing the exact digest.
                // The endpoint stores it verbatim and returns it; it proves nothing.
                $responseHeaders['x-amz-meta-sha256'] = base64_encode(hash('sha256', $body, true));
            } elseif ('checksum-mismatch' === $policy) {
                $responseHeaders['x-amz-checksum-sha256'] = base64_encode(hash('sha256', $body . 'tampered', true));
            } elseif ('size-mismatch' === $policy) {
                // A deliberately contradictory endpoint: the checksum matches yet it
                // reports a length that cannot be true. Only such an EXPLICIT
                // contradiction may veto VERIFIED — absence stays harmless.
                $responseHeaders['x-amz-checksum-sha256'] = (string) ($headers['x-amz-checksum-sha256'] ?? '');
                $responseHeaders['x-amz-object-size']    = (string) (strlen($body) + 1);
            }

            return new FulfilledPromise(new Response(200, $responseHeaders, ''));
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function deploymentMap(): array
    {
        return array(
            'CPMS_S3_ENDPOINT'               => self::FAKE_ENDPOINT,
            'CPMS_S3_REGION'                 => self::FAKE_REGION,
            'CPMS_S3_BUCKET'                 => self::FAKE_BUCKET,
            'CPMS_S3_PREFIX'                 => self::FAKE_PREFIX,
            'CPMS_S3_PATH_STYLE'             => true,
            'CPMS_S3_ACCESS_KEY_ID'          => self::FAKE_ACCESS_KEY,
            'CPMS_S3_SECRET_ACCESS_KEY'      => self::FAKE_SECRET,
            'CPMS_BACKUP_ENCRYPTION_KEY_B64' => base64_encode($this->fixtureKey()),
        );
    }

    /**
     * @param array<string, mixed> $map
     */
    private function readerFor(array $map): \Closure
    {
        return static function (string $name) use ($map) {
            return array_key_exists($name, $map) ? $map[$name] : null;
        };
    }

    private function fixtureKey(): string
    {
        return str_repeat(self::FIXTURE_KEY_BYTE, 32);
    }

    /**
     * @return array<string, string>
     */
    private function forbiddenKeyFragments(): array
    {
        return array(
            'local backup id'         => self::BACKUP_ID,
            'backup id date'          => self::BACKUP_DATE,
            'backup id time'          => self::BACKUP_TIME,
            'database file name'      => 'db.sql',
            'manifest file name'      => 'manifest.json',
            'local relative path'     => 'storage/',
            'storage file name'       => self::STORAGE_HEX . '.pdf',
            'patient/clinical marker' => self::PATIENT_MARKER,
            'note marker'             => self::NOTE_MARKER,
        );
    }

    private function databaseSql(): string
    {
        return "-- cpms backup dump (fixture)\n"
            . "INSERT INTO cpms_patients VALUES ('" . self::PATIENT_MARKER . "');\n"
            . '-- ' . str_repeat('x', 96) . "\n";
    }

    private function storagePayload(): string
    {
        return '%PDF-1.4 fixture payload ' . self::NOTE_MARKER . ' ' . str_repeat('y', 128);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestFor(string $root, string $backupId, ?string $sizeSource = null): array
    {
        $dir = $root . '/' . ($sizeSource ?? $backupId);
        $sql = (string) file_get_contents($dir . '/db.sql');
        $pdf = (string) file_get_contents($dir . '/storage/1/a3/' . self::STORAGE_HEX . '.pdf');

        return array(
            'schema_version' => BackupManifest::SCHEMA_VERSION,
            'engine'         => BackupManifest::ENGINE,
            'engine_version' => '1.0.0',
            'backup_id'      => $backupId,
            'created_at'     => '2026-01-15T09:30:00+00:00',
            'note'           => 'mirror slice red ' . self::NOTE_MARKER,
            'db'             => array('file' => 'db.sql', 'sha256' => hash('sha256', $sql), 'tables' => array(array('name' => 'cpms_patients', 'rows' => 3))),
            'storage'        => array(
                'root'  => 'storage',
                'files' => array(array('path' => '1/a3/' . self::STORAGE_HEX . '.pdf', 'size' => strlen($pdf), 'sha256' => hash('sha256', $pdf))),
                'count' => 1,
                'bytes' => strlen($pdf),
            ),
            'meta'           => array('wp_version' => '6.7.2', 'php_version' => PHP_VERSION, 'cpms_version' => 'test'),
        );
    }

    /**
     * Writes a disposable local backup in exactly the delivered product layout
     * (db.sql + storage mirror + manifest.json + manifest.json.sha256), built with
     * the same manifest shape the delivered BackupService writes.
     *
     * @return array{manifest:array<string,mixed>, dir:string}
     */
    private function writeLocalBackup(string $root, string $backupId): array
    {
        $dir = $root . '/' . $backupId;
        if (! is_dir($dir . '/storage/1/a3')) {
            mkdir($dir . '/storage/1/a3', 0700, true);
        }
        file_put_contents($dir . '/db.sql', $this->databaseSql());
        file_put_contents($dir . '/storage/1/a3/' . self::STORAGE_HEX . '.pdf', $this->storagePayload());

        $manifest = $this->manifestFor($root, $backupId);
        $errors   = BackupManifest::validate($manifest);
        self::assertSame(array(), $errors, 'fixture precondition: the manifest must satisfy the delivered schema (errors: ' . json_encode($errors) . ')');
        $json = (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($dir . '/manifest.json', $json);
        file_put_contents($dir . '/manifest.json.sha256', hash('sha256', $json));

        return array('manifest' => $manifest, 'dir' => $dir);
    }

    /**
     * The ESTABLISHED local verification, expressed with the delivered primitives:
     * manifest.json.sha256 sidecar state, manifest backup_id identity and
     * BackupManifest::verifyFiles() over every listed file.
     *
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,hash_missing:bool}
     */
    private function verifyLocalBackup(string $root, string $backupId): array
    {
        $dir  = $root . '/' . $backupId;
        $json = @file_get_contents($dir . '/manifest.json');
        if (! is_string($json)) {
            return array('ok' => false, 'errors' => array('manifest missing/corrupt'), 'warnings' => array(), 'hash_missing' => false);
        }
        $raw = json_decode($json, true);
        if (! is_array($raw)) {
            return array('ok' => false, 'errors' => array('manifest missing/corrupt'), 'warnings' => array(), 'hash_missing' => false);
        }

        $sidecar     = @file_get_contents($dir . '/manifest.json.sha256');
        $hash_missing = ! is_string($sidecar) || trim($sidecar) === '';
        if (! $hash_missing && ! hash_equals(trim($sidecar), (string) hash_file('sha256', $dir . '/manifest.json'))) {
            return array('ok' => false, 'errors' => array('manifest.json tampered'), 'warnings' => array(), 'hash_missing' => false);
        }
        if ((string) ($raw['backup_id'] ?? '') !== $backupId) {
            return array('ok' => false, 'errors' => array('manifest backup_id mismatch'), 'warnings' => array(), 'hash_missing' => $hash_missing);
        }

        $result = BackupManifest::verifyFiles($raw, static function (string $relative) use ($dir): ?array {
            $abs = $dir . '/' . $relative;
            if (! is_file($abs)) {
                return null;
            }

            return array('size' => (int) filesize($abs), 'sha256' => (string) hash_file('sha256', $abs));
        });

        return array(
            'ok'           => (bool) $result['ok'],
            'errors'       => array_values((array) $result['errors']),
            'warnings'     => array_values((array) ($result['warnings'] ?? array())),
            'hash_missing' => $hash_missing,
        );
    }

    /**
     * Encrypts one local source with the delivered envelope so a test can compare
     * the remote ciphertext framing without hard-coding any invented size.
     */
    private function encryptLocally(string $root, string $source): string
    {
        $scratch = $this->scratch($root);
        $cipher  = $scratch . '/probe-' . bin2hex(random_bytes(4)) . '.enc';
        BackupEncryptionEnvelope::encryptFile($source, $cipher, $this->fixtureKey());
        $bytes = (string) file_get_contents($cipher);
        @unlink($cipher);

        return $bytes;
    }

    /**
     * Decrypts one recorded body with the fixture key through the delivered envelope.
     */
    private function decrypt(string $root, string $body, string $tag): string
    {
        $scratch = $this->scratch($root);
        $cipher  = $scratch . '/dec-' . preg_replace('/[^a-z0-9_-]/i', '', $tag) . '.enc';
        $plain   = $cipher . '.plain';
        file_put_contents($cipher, $body);
        BackupEncryptionEnvelope::decryptFile($cipher, $plain, $this->fixtureKey());
        $bytes = (string) file_get_contents($plain);
        @unlink($cipher);
        @unlink($plain);

        return $bytes;
    }

    private function workspace(): string
    {
        $root = rtrim(sys_get_temp_dir(), '/') . '/cpms-slice2c-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $this->roots[] = $root;

        return $root;
    }

    /**
     * The private scratch workspace lives OUTSIDE the backup root: the local store
     * directory may never be used for staging (the local format is unchanged).
     */
    private function scratch(string $root): string
    {
        $scratch = $root . '-scratch';
        if (! is_dir($scratch)) {
            mkdir($scratch, 0700, true);
        }

        return $scratch;
    }

    /**
     * @return list<string>
     */
    private function relativeListing(string $dir): array
    {
        if (! is_dir($dir)) {
            return array();
        }
        $out = array();
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $entry) {
            $out[] = ltrim(str_replace($dir, '', $entry->getPathname()), '/');
        }
        sort($out);

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(string $dir): array
    {
        $state = array();
        foreach ($this->relativeListing($dir) as $relative) {
            $path           = $dir . '/' . $relative;
            $state[$relative] = is_file($path) ? ((string) filesize($path)) . ':' . hash_file('sha256', $path) : 'dir';
        }

        return $state;
    }

    /**
     * @return list<string>
     */
    private function deepStrings(mixed $value): array
    {
        $out = array();
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $out[] = (string) $key;
                $out   = array_merge($out, $this->deepStrings($item));
            }

            return $out;
        }
        if (is_scalar($value) && ! is_bool($value)) {
            $out[] = (string) $value;
        }

        return $out;
    }

    private function removeTree(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
