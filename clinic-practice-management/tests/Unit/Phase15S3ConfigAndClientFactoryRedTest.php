<?php
/**
 * Phase 15 — Backup & Recovery, Slice 2B: DEPLOYMENT-CONSTANTS S3 CONFIGURATION +
 * NO-NETWORK S3Client FACTORY — accepted unit behavioral/security contract.
 *
 * ============================================================================
 * RECONSTRUCTION TRACEABILITY (recovery PR, NOT a new TDD cycle)
 * ============================================================================
 *
 * This file is the accepted Slice 2B unit behavioral/security contract,
 * reconstructed as NEW forward work by the replacement PR that supersedes the
 * closed, unmerged PR #165 (Arena branch-lock recovery — no healthy new Arena
 * session could write to PR #165's source branch). The original valid
 * TEST-ONLY RED belongs to superseded PR #165 at
 * a03df1cb8b312ee742fd8920c2d0df53e6d58743; this replacement does NOT
 * reproduce that RED and does not claim to. On this head the contract is
 * GREEN: every test below must pass, including the focused regression
 * assertions for the previously accepted blocker repair (immediate
 * __unserialize() rejection for BOTH S3BackupDeploymentConfig and
 * S3BackupTransportSettings).
 *
 * ============================================================================
 * AUTHORIZED SCOPE (Product Owner direction, deliberately narrow)
 * ============================================================================
 *
 * Slice 2B establishes ONE bounded contract: a validated deployment
 * configuration whose ONLY production source is WordPress/server deployment
 * constants (define()'d in wp-config.php / server config — never the database,
 * never getenv, never settings/options), plus a factory that can construct the
 * official Aws\S3\S3Client from that configuration WITHOUT any network access.
 * No CPMS_S3_ENABLED flag exists: the all-fields-absent state IS "disabled".
 *
 * EXPLICITLY OUT OF SCOPE — and therefore NOT expressed, referenced, stubbed or
 * pre-wired anywhere in this file:
 *   upload/download, BackupService or backup.run changes, persistence,
 *   settings/options/UI, migration, remote status/protection claims, any
 *   generic provider/transport abstraction/framework, real AWS endpoints,
 *   buckets or credentials, and Phase 20.
 *
 * ============================================================================
 * BASELINE STATE AT ORIGINAL RED AUTHORING (superseded PR #165 cycle,
 * independently verified on 2026-10-02, BEFORE its writes)
 * ============================================================================
 *   - authoritative main = 4c9fb5c3adecac0c7aef0140dd30ab91b8e8b6d6 (merge of
 *     PR #164); branch arena/01a0fd3a-doctor branched from it; complete
 *     worktree clean (tracked + untracked = none)
 *   - open PRs before writing = 0
 *   - Slice 1 encryption envelope on main =
 *     ClinicCore\Infrastructure\Backup\BackupEncryptionEnvelope (delivered)
 *   - Slice 2A AWS SDK / build foundation on main: composer.json requires
 *     aws/aws-sdk-php ~3.399.0 with committed composer.lock
 *     (SHA256 41e908effeb0496e3b289c9a18c535a72ceb2c3f36bbb2d48c8074b158d48e4d),
 *     guarded vendor/autoload.php require in the plugin entry point, official
 *     builder bin/build-release.sh and release probes
 *     tests/bin/release-runtime-contract.py + tests/bin/release-s3-client-smoke.php
 *   - latest migration on disk = 2026_09_26_0023_handwriting_prescription_paper.php
 *     (no 0024 exists; none is created, reserved or referenced by this contract)
 *   - PHP support policy = PHP >= 8.1 (composer.json) with CI Unit matrix
 *     8.1/8.2/8.3/8.4 (tests/Unit runs without WordPress; this contract uses no
 *     WordPress API — only defined()/constant(), which are PHP built-ins)
 *   - sodium secretstream availability: ext/sodium is a shipped extension of
 *     PHP >= 8.1 (control test below keeps Slice 1's explicit runtime proof)
 *
 * ============================================================================
 * PRODUCT CONTRACT ESTABLISHED BY THIS FILE (implemented on this head)
 * ============================================================================
 *
 *   Classes (all final, namespace ClinicCore\Infrastructure\Backup, no
 *   interface, no provider/transport abstraction, no DI container):
 *
 *     S3BackupDeploymentConfig   — validated, immutable deployment configuration
 *     S3BackupTransportSettings  — deliberately KEYLESS transport view
 *     S3BackupClientFactory      — constructs the official S3 client, no network
 *
 *   DEPLOYMENT CONSTANT NAMES (the only production config source; no getenv):
 *     CPMS_S3_ENDPOINT, CPMS_S3_REGION, CPMS_S3_BUCKET, CPMS_S3_PREFIX (the
 *     ONLY optional field), CPMS_S3_PATH_STYLE, CPMS_S3_ACCESS_KEY_ID,
 *     CPMS_S3_SECRET_ACCESS_KEY, CPMS_BACKUP_ENCRYPTION_KEY_B64.
 *     Deliberately NO CPMS_S3_ENABLED (the all-absent state is "disabled").
 *
 *   STATE CONTRACT (fail-closed):
 *     - all 8 constants absent                     => fromReader/fromDeploymentConstants
 *                                                     return null ("disabled/not configured");
 *     - all 7 required present and valid           => configured instance
 *                                                     (prefix may be absent);
 *     - partially present                          => BackupException
 *                                                     CLINIC_BACKUP_S3_PARTIAL_CONFIG
 *                                                     (presence is checked BEFORE any
 *                                                     value validation);
 *     - malformed value                            => bounded per-field error:
 *                                                     CLINIC_BACKUP_S3_ENDPOINT_INVALID,
 *                                                     CLINIC_BACKUP_S3_REGION_INVALID,
 *                                                     CLINIC_BACKUP_S3_BUCKET_INVALID,
 *                                                     CLINIC_BACKUP_S3_PREFIX_INVALID,
 *                                                     CLINIC_BACKUP_S3_PATH_STYLE_INVALID,
 *                                                     CLINIC_BACKUP_S3_CREDENTIALS_INVALID,
 *                                                     CLINIC_BACKUP_ENCRYPTION_KEY_INVALID.
 *     Presence = the reader returns a value for the name (null = absent — a
 *     callable reader cannot distinguish "defined as null" from "undefined",
 *     so present-null is the absent state by construction). A present-but-
 *     empty or present-but-non-string value is PRESENT and INVALID (it can
 *     never silently degrade into "disabled").
 *
 *   VALIDATION RULES (fixed order: endpoint, region, bucket, prefix, path
 *   style, access key id, secret access key, encryption key; first failure wins):
 *     - endpoint: non-empty bounded (<= 2048 bytes) string, absolute HTTPS URL,
 *       host required, no userinfo, no query, no fragment, no control
 *       characters, no whitespace; TLS verification is never disabled;
 *     - region: non-empty bounded (<= 128 bytes) string, no control characters,
 *       no whitespace, no slashes; NO AWS-only allowlist (any provider region
 *       string is valid);
 *     - bucket: non-empty bounded (<= 255 bytes) string, no control characters,
 *       no whitespace, no slashes; NO provider-specific DNS naming rule;
 *     - prefix: optional, bounded (<= 512 bytes); leading/trailing and repeated
 *       slashes are normalized away; backslashes and control characters are
 *       rejected; "." / ".." traversal SEGMENTS are rejected (segment equality,
 *       not substring matching);
 *     - path style: STRICT boolean — exactly true or exactly false; strings,
 *       ints and null are rejected (no truthy/falsy coercion);
 *     - access key id / secret access key: non-empty bounded (<= 256 bytes)
 *       strings, no control characters, no whitespace; both are SENSITIVE;
 *     - encryption key: strict Base64 text, decoded length MUST equal
 *       SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES (32); the
 *       decoded key must never be persisted, logged or serialized; when
 *       Sodium is unavailable the key validation fails closed.
 *
 *   ERROR MODEL (BackupException, repository CLINIC_BACKUP_* convention):
 *     - messages are fixed and bounded; they NEVER contain config values,
 *       credentials, key material, authorization headers or signed URLs;
 *     - the exception data array is always EMPTY for these errors;
 *     - the config object and the transport settings mask every sensitive
 *       value in every debug/serialization path (var_dump, var_export,
 *       print_r, serialize, json_encode).
 *
 *   IMPORTANT SEPARATION (encryption is NOT transport):
 *     - the decoded encryption key lives in the validated deployment
 *       configuration and is reachable ONLY through the deliberately narrow
 *       in-memory accessor S3BackupDeploymentConfig::encryptionKeyForBackupEnvelope()
 *       for the future backup-encryption layer;
 *     - the transport factory consumes ONLY S3BackupTransportSettings — a
 *       keyless view carrying endpoint, region, bucket, prefix, path style and
 *       the S3 credentials — and MUST NOT pass the encryption key (or any key
 *       material) into the AWS SDK configuration;
 *     - the factory constructs Aws\S3\S3Client with: the configured HTTPS
 *       endpoint, the configured region, explicit path-style handling, SigV4
 *       signing, explicit non-production credentials and TLS verification
 *       enabled; construction performs ZERO commands/requests/network calls
 *       (proved with a throwing HTTP handler seam, exactly like the already
 *       merged Slice 2A release smoke).
 *
 *   OBJECT KEY PRODUCT CONTRACT (explicitly NOT a detection claim):
 *     CPMS itself must never append patient/Clinic/clinical identifiers to
 *     remote object keys; remote object keys are built only from the
 *     configured deployment prefix and CPMS-owned backup file names. The
 *     software cannot and does not semantically detect PHI inside an
 *     arbitrary customer-configured prefix — no such claim is made.
 *     S3BackupDeploymentConfig::OBJECT_KEY_PRODUCT_CONTRACT pins this sentence
 *     as product documentation.
 *
 * ============================================================================
 * TEST STATE ON THIS HEAD (GREEN contract test; no new RED)
 * ============================================================================
 *
 * This replacement does NOT manufacture a new RED. The original valid
 * TEST-ONLY RED belongs to superseded PR #165 (see RECONSTRUCTION
 * TRACEABILITY above). On this head the control tests (runtime Sodium,
 * loadable Slice 2A SDK foundation, and the absence of the deployment
 * constants in this process) are GREEN and every contract test passes.
 * Each contract test still resolves the accepted classes through
 * configClass() / transportClass() / factoryClass(), which assert
 * class/entry-point/constant existence with an explicit message naming the
 * accepted Slice 2B contract — so a regression that removes the product
 * code fails as a named assertion, never as a missing-class fatal, never as
 * a harness/bootstrap/fixture defect, and never with a network effect. The
 * tests use only clearly fake, non-production values and never define real
 * deployment constants in the process.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Backup\BackupException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Phase15S3ConfigAndClientFactoryRedTest extends TestCase
{
    /** Accepted Slice 2B production classes (present on this head). */
    private const CONFIG   = 'ClinicCore\\Infrastructure\\Backup\\S3BackupDeploymentConfig';
    private const TRANSPORT = 'ClinicCore\\Infrastructure\\Backup\\S3BackupTransportSettings';
    private const FACTORY  = 'ClinicCore\\Infrastructure\\Backup\\S3BackupClientFactory';

    /** The exact deployment constant names (registry order is the validation order). */
    private const NAME_ENDPOINT          = 'CPMS_S3_ENDPOINT';
    private const NAME_REGION            = 'CPMS_S3_REGION';
    private const NAME_BUCKET            = 'CPMS_S3_BUCKET';
    private const NAME_PREFIX            = 'CPMS_S3_PREFIX';
    private const NAME_PATH_STYLE        = 'CPMS_S3_PATH_STYLE';
    private const NAME_ACCESS_KEY_ID     = 'CPMS_S3_ACCESS_KEY_ID';
    private const NAME_SECRET_ACCESS_KEY = 'CPMS_S3_SECRET_ACCESS_KEY';
    private const NAME_ENCRYPTION_KEY_B64 = 'CPMS_BACKUP_ENCRYPTION_KEY_B64';

    /** Stable bounded BackupException codes (repository CLINIC_BACKUP_* convention). */
    private const E_PARTIAL     = 'CLINIC_BACKUP_S3_PARTIAL_CONFIG';
    private const E_ENDPOINT    = 'CLINIC_BACKUP_S3_ENDPOINT_INVALID';
    private const E_REGION      = 'CLINIC_BACKUP_S3_REGION_INVALID';
    private const E_BUCKET      = 'CLINIC_BACKUP_S3_BUCKET_INVALID';
    private const E_PREFIX      = 'CLINIC_BACKUP_S3_PREFIX_INVALID';
    private const E_PATH_STYLE  = 'CLINIC_BACKUP_S3_PATH_STYLE_INVALID';
    private const E_CREDENTIALS = 'CLINIC_BACKUP_S3_CREDENTIALS_INVALID';
    private const E_KEY         = 'CLINIC_BACKUP_ENCRYPTION_KEY_INVALID';
    private const E_RESTORE_CONFIG   = 'CLINIC_BACKUP_S3_CONFIG_RESTORE_REJECTED';
    private const E_RESTORE_TRANSPORT = 'CLINIC_BACKUP_S3_TRANSPORT_RESTORE_REJECTED';

    /**
     * Clearly fake, clearly non-production fixtures. Nothing here approaches a
     * real AWS endpoint, bucket or credential; the endpoint is a reserved
     * .invalid host that can never resolve.
     */
    private const FAKE_ENDPOINT     = 'https://cpms-s3-red.invalid';
    private const FAKE_REGION       = 'cpms-red-region-1';
    private const FAKE_BUCKET       = 'cpms_red_bucket_underscores_are_valid_here';
    private const FAKE_PREFIX_RAW   = '//red//fixture/prefix//';
    private const FAKE_PREFIX_NORM  = 'red/fixture/prefix';
    private const FAKE_ACCESS_KEY   = 'CPMSREDNOTPRODUCTIONACCESSKEY0001';
    private const FAKE_SECRET       = 'cpms-red-not-production-secret-access-key-0001';

    /**
     * Resolver assertion message. On this head the accepted Slice 2B contract
     * is present, so a failure here is a REGRESSION (the product code was
     * removed), never an intended RED. The original valid TEST-ONLY RED belongs
     * to superseded PR #165 (see the file header) and is NOT reproduced here.
     */
    private const MISSING_CONTRACT_MESSAGE = 'Phase 15 Slice 2B: the CPMS deployment-constants S3 '
        . 'configuration / no-network S3Client factory contract (ClinicCore\\Infrastructure\\Backup\\'
        . 'S3BackupDeploymentConfig + S3BackupTransportSettings + S3BackupClientFactory) is missing on this '
        . 'head. This is a regression, not an intended RED: the approved fail-closed deployment configuration '
        . 'contract (constants-only source without an ENABLED flag, disabled-when-absent / partial-fail-closed / '
        . 'per-field bounded validation, strict boolean path style, strict Base64 sodium-secretstream encryption '
        . 'key, keyless transport view, SigV4 HTTPS client construction with zero network calls, and immediate '
        . '__unserialize() rejection for S3BackupDeploymentConfig and S3BackupTransportSettings) must be present.';

    // ========================================================================
    // CONTROLS — prerequisites that must be GREEN today (never fail for the
    // intended RED reason): runtime Sodium, the merged Slice 2A SDK
    // foundation, and a deployment-constants-free test process.
    // ========================================================================

    public function testSupportedRuntimeProvidesSodiumSecretstreamXChaCha20Poly1305(): void
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

        self::assertTrue(extension_loaded('sodium'), 'the supported CPMS runtime must ship ext/sodium (bundled and enabled by default since PHP 7.2; policy here is PHP >= 8.1)');
        self::assertTrue(function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push'), 'missing sodium secretstream primitive');
        self::assertTrue(function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull'), 'missing sodium secretstream primitive');
        self::assertSame(32, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 'sodium secretstream keys are 32 bytes of binary key material');
    }

    public function testSlice2aAwsSdkFoundationIsLoadableFromTheLockedRuntime(): void
    {
        self::assertTrue(class_exists(\Aws\S3\S3Client::class), 'the merged Slice 2A foundation must make the official Aws\\S3\\S3Client loadable (CI installs the locked composer vendor tree)');
        self::assertTrue(class_exists(\Aws\Credentials\Credentials::class), 'the official SDK credentials value must be loadable');
        self::assertSame('3.399.0', \Aws\Sdk::VERSION, 'the locked SDK major must remain the approved 3.399.0 runtime');
    }

    public function testDeploymentConstantsAreAbsentFromThisProcess(): void
    {
        foreach ($this->deploymentNames() as $name) {
            self::assertFalse(defined($name), 'control: ' . $name . ' must not be defined in the test process (tests never define real deployment constants)');
        }
    }

    // ========================================================================
    // T1 — all-absent => disabled/not configured (no ENABLED flag exists)
    // ========================================================================

    public function testAllConstantsAbsentMeansDisabledNotConfigured(): void
    {
        $class = $this->configClass();

        self::assertNull(
            $class::fromReader(self::readerFor([])),
            'an all-absent configuration is the explicit "disabled / not configured" state: fromReader() returns null (there is deliberately no CPMS_S3_ENABLED flag)'
        );

        // The REAL product path: production reads deployment constants through
        // the same contract; with none defined (this process) it is disabled.
        self::assertNull(
            $class::fromDeploymentConstants(),
            'fromDeploymentConstants() must report the same disabled state when no deployment constant is defined'
        );
    }

    // ========================================================================
    // T2 — partially present => bounded fail-closed partial-config error
    // ========================================================================

    #[DataProvider('providerPartialConfigurationMaps')]
    public function testPartiallyPresentConfigurationFailsClosedWithBoundedPartialConfigError(array $map): void
    {
        $class = $this->configClass();

        $reader = self::readerFor($map);
        $error = $this->captureError(static function () use ($class, $reader): void {
            $class::fromReader($reader);
        });

        self::assertNotNull($error, 'a partially present configuration must fail closed, never silently disable or configure');
        self::assertSame(self::E_PARTIAL, $error->getErrorCode(), 'the partial state must use the bounded partial-config error code');
        self::assertSame([], $error->data, 'config errors must not carry values in their data array');
    }

    public static function providerPartialConfigurationMaps(): iterable
    {
        $full = self::fullValidMap();

        // Only a single field present.
        foreach (self::requiredDeploymentNames() as $name) {
            $only = [$name => $full[$name]];
            yield 'only ' . $name => [$only];
        }

        // Only the optional prefix present (still partial: required fields missing).
        yield 'only optional prefix' => [[self::NAME_PREFIX => self::FAKE_PREFIX_RAW]];

        // Substantial but incomplete subsets.
        $noPathStyle = $full;
        unset($noPathStyle[self::NAME_PATH_STYLE]);
        yield 'everything except path style' => [$noPathStyle];

        $noSecret = $full;
        unset($noSecret[self::NAME_SECRET_ACCESS_KEY]);
        yield 'everything except secret access key' => [$noSecret];

        $noKey = $full;
        unset($noKey[self::NAME_ENCRYPTION_KEY_B64]);
        yield 'everything except encryption key' => [$noKey];

        // Presence is checked BEFORE value validation: even with an invalid
        // value among them, the missing-required-field partial error wins.
        $invalidButPartial = $full;
        $invalidButPartial[self::NAME_REGION] = '';
        unset($invalidButPartial[self::NAME_BUCKET]);
        yield 'missing bucket outranks invalid region' => [$invalidButPartial];
    }

    // ========================================================================
    // T3 + T4 — endpoint validation (HTTPS only, no userinfo/query/fragment,
    // no controls/whitespace, bounded, host required)
    // ========================================================================

    #[DataProvider('providerInvalidFieldValues')]
    public function testInvalidSingleFieldValueIsRejectedWithItsBoundedErrorCode(string $name, mixed $badValue, string $expectedCode): void
    {
        $class = $this->configClass();

        $map = self::fullValidMap();
        $map[$name] = $badValue;

        $reader = self::readerFor($map);
        $error = $this->captureError(static function () use ($class, $reader): void {
            $class::fromReader($reader);
        });

        self::assertNotNull($error, 'the malformed ' . $name . ' value must be rejected');
        self::assertSame($expectedCode, $error->getErrorCode(), 'the malformed ' . $name . ' value must fail with its bounded error code');
        self::assertSame([], $error->data, 'config errors must not carry values in their data array');
    }

    public static function providerInvalidFieldValues(): iterable
    {
        // -- endpoint (bounded absolute HTTPS URL; host required; no
        //    userinfo/query/fragment; no controls/whitespace) --
        yield 'endpoint: plain http is not HTTPS'          => [self::NAME_ENDPOINT, 'http://cpms-s3-red.invalid', self::E_ENDPOINT];
        yield 'endpoint: scheme-less junk'                 => [self::NAME_ENDPOINT, 'cpms-s3-red.invalid', self::E_ENDPOINT];
        yield 'endpoint: userinfo with password'           => [self::NAME_ENDPOINT, 'https://user:pass@cpms-s3-red.invalid', self::E_ENDPOINT];
        yield 'endpoint: userinfo without password'        => [self::NAME_ENDPOINT, 'https://user@cpms-s3-red.invalid', self::E_ENDPOINT];
        yield 'endpoint: query string'                     => [self::NAME_ENDPOINT, 'https://cpms-s3-red.invalid/?x=1', self::E_ENDPOINT];
        yield 'endpoint: fragment'                         => [self::NAME_ENDPOINT, 'https://cpms-s3-red.invalid/#frag', self::E_ENDPOINT];
        yield 'endpoint: empty host'                       => [self::NAME_ENDPOINT, 'https:///path', self::E_ENDPOINT];
        yield 'endpoint: scheme only'                      => [self::NAME_ENDPOINT, 'https://', self::E_ENDPOINT];
        yield 'endpoint: leading whitespace'               => [self::NAME_ENDPOINT, ' https://cpms-s3-red.invalid', self::E_ENDPOINT];
        yield 'endpoint: trailing whitespace'              => [self::NAME_ENDPOINT, 'https://cpms-s3-red.invalid ', self::E_ENDPOINT];
        yield 'endpoint: embedded space'                   => [self::NAME_ENDPOINT, 'https://cpms s3-red.invalid', self::E_ENDPOINT];
        yield 'endpoint: embedded tab'                     => [self::NAME_ENDPOINT, "https://cpms\ts3-red.invalid", self::E_ENDPOINT];
        yield 'endpoint: embedded newline'                 => [self::NAME_ENDPOINT, "https://cpms-s3-red\n.invalid", self::E_ENDPOINT];
        yield 'endpoint: embedded control character'       => [self::NAME_ENDPOINT, "https://cpms\x07s3-red.invalid", self::E_ENDPOINT];
        yield 'endpoint: empty string is present-invalid'  => [self::NAME_ENDPOINT, '', self::E_ENDPOINT];
        yield 'endpoint: non-string int'                   => [self::NAME_ENDPOINT, 12345, self::E_ENDPOINT];
        yield 'endpoint: non-string bool'                  => [self::NAME_ENDPOINT, true, self::E_ENDPOINT];
        yield 'endpoint: over bound'                       => [self::NAME_ENDPOINT, 'https://' . str_repeat('a', 2100) . '.invalid', self::E_ENDPOINT];

        // -- region (bounded non-empty; no controls/whitespace/slashes; NO AWS
        //    allowlist — any provider string is valid, see T8) --
        yield 'region: empty string'                       => [self::NAME_REGION, '', self::E_REGION];
        yield 'region: whitespace'                         => [self::NAME_REGION, 'cpms red region', self::E_REGION];
        yield 'region: leading tab'                        => [self::NAME_REGION, "\tcpms-red-region-1", self::E_REGION];
        yield 'region: slash'                              => [self::NAME_REGION, 'cpms/red', self::E_REGION];
        yield 'region: backslash'                          => [self::NAME_REGION, 'cpms\\red', self::E_REGION];
        yield 'region: control character'                  => [self::NAME_REGION, "cpms\x01red", self::E_REGION];
        yield 'region: non-string'                         => [self::NAME_REGION, 42, self::E_REGION];
        yield 'region: over bound'                         => [self::NAME_REGION, str_repeat('r', 129), self::E_REGION];

        // -- bucket (bounded non-empty; no controls/whitespace/slashes; NO
        //    provider-specific DNS rule — underscores are valid, see T8/T10) --
        yield 'bucket: empty string'                       => [self::NAME_BUCKET, '', self::E_BUCKET];
        yield 'bucket: whitespace'                         => [self::NAME_BUCKET, 'cpms red bucket', self::E_BUCKET];
        yield 'bucket: slash'                              => [self::NAME_BUCKET, 'cpms/red', self::E_BUCKET];
        yield 'bucket: backslash'                          => [self::NAME_BUCKET, 'cpms\\red', self::E_BUCKET];
        yield 'bucket: control character'                  => [self::NAME_BUCKET, "cpms\x07red", self::E_BUCKET];
        yield 'bucket: non-string'                         => [self::NAME_BUCKET, false, self::E_BUCKET];
        yield 'bucket: over bound'                         => [self::NAME_BUCKET, str_repeat('b', 256), self::E_BUCKET];

        // -- prefix (optional; bounded; no backslashes/control characters;
        //    no "." / ".." traversal SEGMENTS) --
        yield 'prefix: backslash is rejected'              => [self::NAME_PREFIX, 'red\\fixture', self::E_PREFIX];
        yield 'prefix: control character'                  => [self::NAME_PREFIX, "red\x01fixture", self::E_PREFIX];
        yield 'prefix: dot segment'                        => [self::NAME_PREFIX, 'red/./fixture', self::E_PREFIX];
        yield 'prefix: dot segment only'                   => [self::NAME_PREFIX, '.', self::E_PREFIX];
        yield 'prefix: dot-dot segment'                    => [self::NAME_PREFIX, 'red/../fixture', self::E_PREFIX];
        yield 'prefix: dot-dot segment only'               => [self::NAME_PREFIX, '..', self::E_PREFIX];
        yield 'prefix: traversal at start'                 => [self::NAME_PREFIX, '../red/fixture', self::E_PREFIX];
        yield 'prefix: traversal at end'                   => [self::NAME_PREFIX, 'red/fixture/..', self::E_PREFIX];
        yield 'prefix: control character inside traversal' => [self::NAME_PREFIX, "red/../\x00", self::E_PREFIX];
        yield 'prefix: non-string'                         => [self::NAME_PREFIX, ['red'], self::E_PREFIX];
        yield 'prefix: over bound'                         => [self::NAME_PREFIX, str_repeat('p', 513), self::E_PREFIX];

        // -- path style (STRICT boolean contract; no truthy/falsy coercion) --
        yield 'path style: string true'                    => [self::NAME_PATH_STYLE, 'true', self::E_PATH_STYLE];
        yield 'path style: string false'                   => [self::NAME_PATH_STYLE, 'false', self::E_PATH_STYLE];
        yield 'path style: string one'                     => [self::NAME_PATH_STYLE, '1', self::E_PATH_STYLE];
        yield 'path style: string zero'                    => [self::NAME_PATH_STYLE, '0', self::E_PATH_STYLE];
        yield 'path style: int one'                        => [self::NAME_PATH_STYLE, 1, self::E_PATH_STYLE];
        yield 'path style: int zero'                        => [self::NAME_PATH_STYLE, 0, self::E_PATH_STYLE];
        yield 'path style: yes'                            => [self::NAME_PATH_STYLE, 'yes', self::E_PATH_STYLE];

        // -- S3 credentials (bounded non-empty strings; sensitive) --
        yield 'access key: empty string'                   => [self::NAME_ACCESS_KEY_ID, '', self::E_CREDENTIALS];
        yield 'access key: whitespace'                     => [self::NAME_ACCESS_KEY_ID, ' cpms-red-access-key', self::E_CREDENTIALS];
        yield 'access key: embedded newline'               => [self::NAME_ACCESS_KEY_ID, "cpms-red\naccess-key", self::E_CREDENTIALS];
        yield 'access key: control character'              => [self::NAME_ACCESS_KEY_ID, "cpms\x00red", self::E_CREDENTIALS];
        yield 'access key: non-string'                     => [self::NAME_ACCESS_KEY_ID, 1234, self::E_CREDENTIALS];
        yield 'access key: over bound'                     => [self::NAME_ACCESS_KEY_ID, str_repeat('A', 257), self::E_CREDENTIALS];
        yield 'secret: empty string'                       => [self::NAME_SECRET_ACCESS_KEY, '', self::E_CREDENTIALS];
        yield 'secret: whitespace'                         => [self::NAME_SECRET_ACCESS_KEY, 'cpms-red-secret ', self::E_CREDENTIALS];
        yield 'secret: control character'                  => [self::NAME_SECRET_ACCESS_KEY, "cpms\x1fsecret", self::E_CREDENTIALS];
        yield 'secret: non-string'                         => [self::NAME_SECRET_ACCESS_KEY, 99.5, self::E_CREDENTIALS];
        yield 'secret: over bound'                         => [self::NAME_SECRET_ACCESS_KEY, str_repeat('s', 257), self::E_CREDENTIALS];

        // -- backup encryption key (strict Base64; decoded length MUST equal
        //    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) --
        yield 'encryption key: not base64'                 => [self::NAME_ENCRYPTION_KEY_B64, 'this-is-not-base64!!!', self::E_KEY];
        yield 'encryption key: base64 with whitespace'     => [self::NAME_ENCRYPTION_KEY_B64, base64_encode(random_bytes(32)) . ' ', self::E_KEY];
        yield 'encryption key: empty string'               => [self::NAME_ENCRYPTION_KEY_B64, '', self::E_KEY];
        yield 'encryption key: decoded too short (31)'     => [self::NAME_ENCRYPTION_KEY_B64, base64_encode(random_bytes(31)), self::E_KEY];
        yield 'encryption key: decoded too long (33)'      => [self::NAME_ENCRYPTION_KEY_B64, base64_encode(random_bytes(33)), self::E_KEY];
        yield 'encryption key: decoded empty'              => [self::NAME_ENCRYPTION_KEY_B64, base64_encode(''), self::E_KEY];
        yield 'encryption key: non-string'                 => [self::NAME_ENCRYPTION_KEY_B64, 321, self::E_KEY];
    }

    // ========================================================================
    // T5c-normalization + T8 — a valid (clearly fake) configuration resolves
    // into a configured object with exact accessors and the narrow key API
    // ========================================================================

    public function testValidFakeConfigurationProducesConfiguredObjectWithExactValues(): void
    {
        $class = $this->configClass();
        $map = self::fullValidMap(); // no prefix, path style true

        $config = $class::fromReader(self::readerFor($map));

        self::assertNotNull($config, 'a complete valid (clearly fake) configuration must resolve to a configured object');
        self::assertSame(self::FAKE_ENDPOINT, $config->endpoint(), 'endpoint() returns the configured HTTPS endpoint');
        self::assertSame(self::FAKE_REGION, $config->region(), 'region() returns the configured region (non-AWS strings are valid — no allowlist)');
        self::assertSame(self::FAKE_BUCKET, $config->bucket(), 'bucket() returns the configured bucket (underscores are valid — no DNS rule)');
        self::assertSame('', $config->keyPrefix(), 'an absent prefix is the empty normalized prefix');
        self::assertTrue($config->usePathStyle(), 'usePathStyle() returns the strict configured boolean');
        self::assertSame(32, strlen($config->encryptionKeyForBackupEnvelope()), 'the decoded encryption key is exactly SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES binary bytes');
        self::assertSame(base64_decode($map[self::NAME_ENCRYPTION_KEY_B64], true), $config->encryptionKeyForBackupEnvelope(), 'the narrow accessor returns the decoded key for the future backup-encryption layer only');

        $withPrefix = $map;
        $withPrefix[self::NAME_PREFIX] = self::FAKE_PREFIX_RAW;
        $withPrefix[self::NAME_PATH_STYLE] = false;
        $prefixed = $class::fromReader(self::readerFor($withPrefix));

        self::assertNotNull($prefixed, 'prefix is optional and may be present');
        self::assertSame(self::FAKE_PREFIX_NORM, $prefixed->keyPrefix(), 'leading/trailing and repeated slashes are normalized away');
        self::assertFalse($prefixed->usePathStyle(), 'the strict boolean false must survive validation');

        // Prefix normalization is segment-aware: ".." inside a larger segment
        // is NOT a traversal segment.
        $dotdotInsideSegment = $map;
        $dotdotInsideSegment[self::NAME_PREFIX] = 'red..fixture/prefix';
        $dotdotConfig = $class::fromReader(self::readerFor($dotdotInsideSegment));
        self::assertNotNull($dotdotConfig, 'a prefix containing ".." inside a larger segment is not traversal');
        self::assertSame('red..fixture/prefix', $dotdotConfig->keyPrefix(), 'only exact "." / ".." segments are traversal');
    }

    public function testPrefixNormalizationContractIsConsistent(): void
    {
        $class = $this->configClass();

        foreach (
            [
                ''             => '',
                '/'            => '',
                '///'          => '',
                'single'       => 'single',
                '/lead'        => 'lead',
                'trail/'       => 'trail',
                '//a////b//'   => 'a/b',
            ] as $raw => $expected
        ) {
            $map = self::fullValidMap();
            $map[self::NAME_PREFIX] = $raw;
            $config = $class::fromReader(self::readerFor($map));

            self::assertNotNull($config, 'a configuration with prefix ' . var_export($raw, true) . ' must stay valid (prefix is optional and normalized)');
            self::assertSame($expected, $config->keyPrefix(), 'prefix ' . var_export($raw, true) . ' must normalize consistently');
        }
    }

    // ========================================================================
    // T9 — error secrecy: bounded errors never echo sensitive values
    // ========================================================================

    public function testBoundedErrorsNeverContainSensitiveFakeValues(): void
    {
        $class = $this->configClass();

        $map = self::fullValidMap(); // ONE map; its key material is the scan target
        $decoded = base64_decode($map[self::NAME_ENCRYPTION_KEY_B64], true);
        self::assertSame(32, strlen((string) $decoded), 'fixture: the fake key decodes to 32 bytes');
        $forbidden = [
            self::FAKE_ENDPOINT,
            self::FAKE_REGION,
            self::FAKE_BUCKET,
            self::FAKE_ACCESS_KEY,
            self::FAKE_SECRET,
            $map[self::NAME_ENCRYPTION_KEY_B64],
            (string) $decoded,
            bin2hex((string) $decoded),
        ];

        $cases = [
            self::E_PARTIAL => [self::NAME_BUCKET => null],
            self::E_ENDPOINT => [self::NAME_ENDPOINT => 'https://user:pass@cpms-s3-red.invalid'],
            self::E_REGION => [self::NAME_REGION => self::FAKE_REGION . "\x07"],
            self::E_BUCKET => [self::NAME_BUCKET => self::FAKE_BUCKET . '/bad'],
            self::E_PREFIX => [self::NAME_PREFIX => 'red/../fixture'],
            self::E_PATH_STYLE => [self::NAME_PATH_STYLE => 'true'],
            self::E_CREDENTIALS => [self::NAME_ACCESS_KEY_ID => ''],
            self::E_KEY => [self::NAME_ENCRYPTION_KEY_B64 => 'not-base64!!!'],
        ];

        foreach ($cases as $expectedCode => $override) {
            $map = self::fullValidMap();
            foreach ($override as $name => $value) {
                $map[$name] = $value;
            }

            $reader = self::readerFor($map);
            $error = $this->captureError(static function () use ($class, $reader): void {
                $class::fromReader($reader);
            });

            self::assertNotNull($error, 'expected a bounded error for ' . $expectedCode);
            self::assertSame($expectedCode, $error->getErrorCode(), 'the bounded error code is stable');

            foreach ($this->errorSurfaces($error) as $surface => $text) {
                foreach ($forbidden as $secretValue) {
                    self::assertStringNotContainsString(
                        $secretValue,
                        $text,
                        'error surface "' . $surface . '" for ' . $expectedCode . ' must not contain sensitive fixture values'
                    );
                }
            }

            self::assertSame([], $error->data, 'the exception data array must stay empty for ' . $expectedCode);
        }
    }

    // ========================================================================
    // T11a — safe debug/serialization of configuration and transport view
    // ========================================================================

    public function testConfigurationAndTransportDebugRepresentationsNeverExposeSensitiveValues(): void
    {
        $class = $this->configClass();
        $transportClass = $this->transportClass();

        $map = self::fullValidMap();
        $map[self::NAME_PREFIX] = self::FAKE_PREFIX_RAW;
        $config = $class::fromReader(self::readerFor($map));
        self::assertNotNull($config, 'fixture: the valid fake configuration resolves');

        $decoded = base64_decode($map[self::NAME_ENCRYPTION_KEY_B64], true);
        $forbidden = [
            self::FAKE_ACCESS_KEY,
            self::FAKE_SECRET,
            $map[self::NAME_ENCRYPTION_KEY_B64],
            (string) $decoded,
            bin2hex((string) $decoded),
        ];

        $settings = $config->transportSettings();
        self::assertInstanceOf($transportClass, $settings, 'transportSettings() returns the keyless transport view');

        foreach (['config' => $config, 'transport settings' => $settings] as $subject => $object) {
            foreach ($this->representationSurfaces($object) as $surface => $text) {
                foreach ($forbidden as $secretValue) {
                    self::assertStringNotContainsString(
                        $secretValue,
                        $text,
                        $subject . ' debug/serialization surface "' . $surface . '" must never expose credentials or key material'
                    );
                }
            }
        }

        // Structural separation: the transport view carries NO encryption-key
        // member at all (reflection over the whole class).
        $reflection = new \ReflectionClass($transportClass);
        foreach ($reflection->getProperties() as $property) {
            self::assertStringNotContainsString('encrypt', strtolower($property->getName()), 'the transport view must not hold encryption-key state');
        }
        foreach ($reflection->getMethods() as $method) {
            self::assertStringNotContainsString('encrypt', strtolower($method->getName()), 'the transport view must not expose encryption-key accessors');
        }
    }

    // ========================================================================
    // T13 — MANDATORY UNSERIALIZE REPAIR (previously accepted blocker):
    // both value objects reject restoration from serialized data IMMEDIATELY,
    // with a bounded non-sensitive failure, so no serialized copy can ever
    // become a usable configuration / transport object.
    // ========================================================================

    public function testConfigurationRejectsRestorationFromSerializedData(): void
    {
        $class = $this->configClass();

        // (1) The normal pre-serialization object works.
        $map = self::fullValidMap();
        $map[self::NAME_PREFIX] = self::FAKE_PREFIX_RAW;
        $config = $class::fromReader(self::readerFor($map));
        self::assertNotNull($config, 'fixture: the valid fake configuration resolves');
        self::assertSame(self::FAKE_ENDPOINT, $config->endpoint(), 'pre-serialization accessors still work (endpoint)');
        self::assertSame(self::FAKE_PREFIX_NORM, $config->keyPrefix(), 'pre-serialization accessors still work (normalized prefix)');
        self::assertSame(32, strlen($config->encryptionKeyForBackupEnvelope()), 'pre-serialization narrow in-memory key accessor still works');

        // (2) The serialize output exposes no sensitive values (secret-safe shell).
        $forbidden = [
            self::FAKE_ENDPOINT,
            self::FAKE_REGION,
            self::FAKE_BUCKET,
            self::FAKE_PREFIX_NORM,
            self::FAKE_ACCESS_KEY,
            self::FAKE_SECRET,
            $map[self::NAME_ENCRYPTION_KEY_B64],
        ];
        $serialized = serialize($config);
        self::assertIsString($serialized, 'serialize() must succeed and produce a state-free shell');
        foreach ($forbidden as $secretValue) {
            self::assertStringNotContainsString($secretValue, $serialized, 'serialize() output must not expose endpoint/bucket/prefix/credential/key material');
        }

        // (3) unserialize(serialize(object)) throws DURING restoration;
        // (4) no usable object is returned (the throw preempts any return value).
        $error = $this->captureError(static function () use ($serialized): void {
            unserialize($serialized);
        });
        self::assertNotNull($error, 'unserialize() must throw DURING restoration — a serialized copy must never become a usable configuration object');
        self::assertSame(self::E_RESTORE_CONFIG, $error->getErrorCode(), 'the restore rejection must use the bounded config restore-rejected error code');
        self::assertSame([], $error->data, 'the restore-rejected error must carry no data');

        // (5) The thrown error contains no fake sensitive values.
        foreach ($this->errorSurfaces($error) as $surface => $text) {
            foreach ($forbidden as $secretValue) {
                self::assertStringNotContainsString($secretValue, $text, 'restore-rejected error surface "' . $surface . '" must not contain sensitive fixture values');
            }
        }

        // Immediate rejection, independent of payload content: even a crafted
        // serialized payload that CARRIES data is rejected before any of it is
        // inspected, so no property state is ever restored.
        $crafted = sprintf('O:%d:"%s":1:{s:1:"x";s:5:"dummy";}', strlen($class), $class);
        $errorCrafted = $this->captureError(static function () use ($crafted): void {
            unserialize($crafted);
        });
        self::assertNotNull($errorCrafted, 'a crafted serialized payload with data must also be rejected during restoration');
        self::assertSame(self::E_RESTORE_CONFIG, $errorCrafted->getErrorCode(), 'the crafted-payload rejection must use the same bounded code');
        self::assertSame([], $errorCrafted->data, 'the crafted-payload rejection must carry no data');
    }

    public function testTransportSettingsRejectsRestorationFromSerializedData(): void
    {
        $configClass = $this->configClass();
        $transportClass = $this->transportClass();

        // (1) The normal pre-serialization object works.
        $map = self::fullValidMap();
        $map[self::NAME_PREFIX] = self::FAKE_PREFIX_RAW;
        $config = $configClass::fromReader(self::readerFor($map));
        self::assertNotNull($config, 'fixture: the valid fake configuration resolves');
        $settings = $config->transportSettings();
        self::assertInstanceOf($transportClass, $settings, 'transportSettings() returns the keyless transport view');
        self::assertSame(self::FAKE_ENDPOINT, $settings->endpoint(), 'pre-serialization accessors still work (endpoint)');
        self::assertSame(self::FAKE_BUCKET, $settings->bucket(), 'pre-serialization accessors still work (bucket)');
        self::assertSame(self::FAKE_ACCESS_KEY, $settings->accessKeyId(), 'pre-serialization accessors still work (access key id)');

        // (2) The serialize output exposes no sensitive values (secret-safe shell).
        $forbidden = [
            self::FAKE_ENDPOINT,
            self::FAKE_REGION,
            self::FAKE_BUCKET,
            self::FAKE_PREFIX_NORM,
            self::FAKE_ACCESS_KEY,
            self::FAKE_SECRET,
        ];
        $serialized = serialize($settings);
        self::assertIsString($serialized, 'serialize() must succeed and produce a state-free shell');
        foreach ($forbidden as $secretValue) {
            self::assertStringNotContainsString($secretValue, $serialized, 'serialize() output must not expose endpoint/bucket/prefix/credential material');
        }

        // (3) unserialize(serialize(object)) throws DURING restoration;
        // (4) no usable object is returned (the throw preempts any return value).
        $error = $this->captureError(static function () use ($serialized): void {
            unserialize($serialized);
        });
        self::assertNotNull($error, 'unserialize() must throw DURING restoration — a serialized copy must never become a usable transport settings object');
        self::assertSame(self::E_RESTORE_TRANSPORT, $error->getErrorCode(), 'the restore rejection must use the bounded transport restore-rejected error code');
        self::assertSame([], $error->data, 'the restore-rejected error must carry no data');

        // (5) The thrown error contains no fake sensitive values.
        foreach ($this->errorSurfaces($error) as $surface => $text) {
            foreach ($forbidden as $secretValue) {
                self::assertStringNotContainsString($secretValue, $text, 'restore-rejected error surface "' . $surface . '" must not contain sensitive fixture values');
            }
        }

        // Immediate rejection, independent of payload content: even a crafted
        // serialized payload that CARRIES data is rejected before any of it is
        // inspected, so no property state is ever restored.
        $crafted = sprintf('O:%d:"%s":1:{s:1:"x";s:5:"dummy";}', strlen($transportClass), $transportClass);
        $errorCrafted = $this->captureError(static function () use ($crafted): void {
            unserialize($crafted);
        });
        self::assertNotNull($errorCrafted, 'a crafted serialized payload with data must also be rejected during restoration');
        self::assertSame(self::E_RESTORE_TRANSPORT, $errorCrafted->getErrorCode(), 'the crafted-payload rejection must use the same bounded code');
        self::assertSame([], $errorCrafted->data, 'the crafted-payload rejection must carry no data');
    }

    // ========================================================================
    // T10 + T11b — the factory constructs the official S3 client with ZERO
    // network activity, and the encryption key never enters the SDK config path
    // ========================================================================

    public function testFactoryConstructsOfficialS3ClientWithZeroHttpCalls(): void
    {
        $class = $this->configClass();
        $factory = $this->factoryClass();

        $map = self::fullValidMap();
        $map[self::NAME_PREFIX] = self::FAKE_PREFIX_RAW;
        $config = $class::fromReader(self::readerFor($map));
        self::assertNotNull($config, 'fixture: the valid fake configuration resolves');

        $calls = 0;
        $handler = static function () use (&$calls): never {
            ++$calls;
            throw new \RuntimeException('Network is forbidden in the Phase 15 Slice 2B contract tests');
        };

        $settings = $config->transportSettings();
        $client = $factory::create($settings, $handler);

        self::assertInstanceOf(\Aws\S3\S3Client::class, $client, 'the factory constructs the OFFICIAL Aws\\S3\\S3Client');
        self::assertSame(0, $calls, 'construction must perform zero HTTP calls (throwing handler never invoked)');
        self::assertSame(self::FAKE_ENDPOINT, (string) $client->getEndpoint(), 'the client uses the configured HTTPS endpoint');
        self::assertSame(self::FAKE_REGION, $client->getRegion(), 'the client uses the configured region');
        self::assertTrue($client->getConfig('use_path_style_endpoint'), 'path-style must be explicit (strict boolean true)');
        self::assertSame('v4', $client->getConfig('signature_version'), 'SigV4 signing must be configured');

        $http = (new \ReflectionProperty(\Aws\AwsClient::class, 'defaultRequestOptions'))->getValue($client);
        self::assertIsArray($http);
        self::assertTrue($http['verify'] ?? false, 'TLS verification must stay enabled (never disabled)');

        $credentials = $client->getCredentials()->wait();
        self::assertSame(self::FAKE_ACCESS_KEY, $credentials->getAccessKeyId(), 'credentials must be the explicit non-production access key id');
        self::assertSame(self::FAKE_SECRET, $credentials->getSecretKey(), 'credentials must be the explicit non-production secret access key');

        // No command/bucket operation exists anywhere in this slice: the only
        // interactions above are configuration introspection.
    }

    public function testEncryptionKeyNeverEntersTheSdkClientConfigurationPath(): void
    {
        $class = $this->configClass();
        $factory = $this->factoryClass();

        $map = self::fullValidMap();
        $config = $class::fromReader(self::readerFor($map));
        self::assertNotNull($config, 'fixture: the valid fake configuration resolves');

        $decoded = base64_decode($map[self::NAME_ENCRYPTION_KEY_B64], true);
        $forbidden = [
            $map[self::NAME_ENCRYPTION_KEY_B64],
            (string) $decoded,
            bin2hex((string) $decoded),
        ];

        $handler = static function (): never {
            throw new \RuntimeException('Network is forbidden in the Phase 15 Slice 2B contract tests');
        };

        $client = $factory::create($config->transportSettings(), $handler);

        $surfaces = [
            'resolved SDK configuration' => $this->stringifyDeep($client->getConfig()),
            'default request options'    => $this->stringifyDeep((new \ReflectionProperty(\Aws\AwsClient::class, 'defaultRequestOptions'))->getValue($client)),
        ];

        foreach ($surfaces as $surface => $text) {
            foreach ($forbidden as $keyMaterial) {
                self::assertStringNotContainsString(
                    $keyMaterial,
                    $text,
                    'the ' . $surface . ' of the constructed client must never contain backup encryption key material'
                );
            }
        }
    }

    // ========================================================================
    // T12 — the deployment constant registry is exact: 8 names, no ENABLED
    // flag, and the object-key product contract is pinned as documentation
    // ========================================================================

    public function testDeploymentConstantRegistryIsExactAndPinsTheObjectKeyProductContract(): void
    {
        $class = $this->configClass();

        self::assertSame(
            [
                'NAME_ENDPOINT'          => self::NAME_ENDPOINT,
                'NAME_REGION'            => self::NAME_REGION,
                'NAME_BUCKET'            => self::NAME_BUCKET,
                'NAME_PREFIX'            => self::NAME_PREFIX,
                'NAME_PATH_STYLE'        => self::NAME_PATH_STYLE,
                'NAME_ACCESS_KEY_ID'     => self::NAME_ACCESS_KEY_ID,
                'NAME_SECRET_ACCESS_KEY' => self::NAME_SECRET_ACCESS_KEY,
                'NAME_ENCRYPTION_KEY_B64' => self::NAME_ENCRYPTION_KEY_B64,
            ],
            self::nameConstantsOf($class),
            'the class must expose exactly the eight deployment constant names (NAME_*)'
        );

        foreach ((new \ReflectionClass($class))->getConstants() as $constantName => $value) {
            self::assertStringNotContainsString('ENABLED', strtoupper((string) $constantName), 'no ENABLED flag may exist in the deployment contract');
            if (is_string($value)) {
                self::assertStringNotContainsString('ENABLED', strtoupper($value), 'no ENABLED-style deployment flag may be referenced by the deployment contract');
            }
        }

        self::assertTrue(defined($class . '::OBJECT_KEY_PRODUCT_CONTRACT'), 'contract: the object-key product contract is pinned as ' . $class . '::OBJECT_KEY_PRODUCT_CONTRACT');
        $contract = (string) constant($class . '::OBJECT_KEY_PRODUCT_CONTRACT');
        self::assertStringContainsString('must never append patient', $contract, 'the product contract pins that CPMS itself never appends patient identifiers to remote object keys');
        self::assertStringContainsString('clinic', strtolower($contract), 'the product contract covers clinic/clinical identifiers');
        self::assertStringContainsString('remote object keys', $contract, 'the product contract is about remote object keys');
        self::assertStringContainsString('detect', strtolower($contract), 'the product contract explicitly states that PHI inside a configured prefix cannot be semantically detected (no detection claim)');
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Resolve the intended configuration class through the contract itself, so
     * the RED failure is an assertion naming the missing API — never a fatal.
     */
    private function configClass(): string
    {
        self::assertTrue(class_exists(self::CONFIG), self::MISSING_CONTRACT_MESSAGE);
        self::assertTrue(is_callable([self::CONFIG, 'fromReader']), 'contract: public static function S3BackupDeploymentConfig::fromReader(callable $reader): ?self');
        self::assertTrue(is_callable([self::CONFIG, 'fromDeploymentConstants']), 'contract: public static function S3BackupDeploymentConfig::fromDeploymentConstants(): ?self (production reader over defined()/constant())');
        self::assertTrue(method_exists(self::CONFIG, 'transportSettings'), 'contract: public function S3BackupDeploymentConfig::transportSettings(): S3BackupTransportSettings');
        self::assertTrue(method_exists(self::CONFIG, 'encryptionKeyForBackupEnvelope'), 'contract: the deliberately narrow in-memory accessor encryptionKeyForBackupEnvelope() for the future backup-encryption layer');

        foreach (self::nameConstantsOf(self::CONFIG) as $constantName => $deploymentName) {
            self::assertSame(
                constant(self::CONFIG . '::' . $constantName),
                $deploymentName,
                'contract: ' . self::CONFIG . '::' . $constantName . ' pins the deployment constant name ' . $deploymentName
            );
        }

        return self::CONFIG;
    }

    private function transportClass(): string
    {
        self::assertTrue(class_exists(self::TRANSPORT), self::MISSING_CONTRACT_MESSAGE);

        return self::TRANSPORT;
    }

    private function factoryClass(): string
    {
        self::assertTrue(class_exists(self::FACTORY), self::MISSING_CONTRACT_MESSAGE);
        self::assertTrue(is_callable([self::FACTORY, 'create']), 'contract: public static function S3BackupClientFactory::create(S3BackupTransportSettings $settings, ?callable $httpHandler = null): Aws\\S3\\S3Client');

        return self::FACTORY;
    }

    /** The eight deployment constant names in registry (validation) order. @return array<string, string> */
    private static function deploymentNames(): array
    {
        return [
            self::NAME_ENDPOINT,
            self::NAME_REGION,
            self::NAME_BUCKET,
            self::NAME_PREFIX,
            self::NAME_PATH_STYLE,
            self::NAME_ACCESS_KEY_ID,
            self::NAME_SECRET_ACCESS_KEY,
            self::NAME_ENCRYPTION_KEY_B64,
        ];
    }

    /** The REQUIRED subset (the optional prefix is not part of the presence check). @return array<string, string> */
    private static function requiredDeploymentNames(): array
    {
        return [
            self::NAME_ENDPOINT,
            self::NAME_REGION,
            self::NAME_BUCKET,
            self::NAME_PATH_STYLE,
            self::NAME_ACCESS_KEY_ID,
            self::NAME_SECRET_ACCESS_KEY,
            self::NAME_ENCRYPTION_KEY_B64,
        ];
    }

    /**
     * The smallest repository-consistent reader seam: a callable/map reader.
     * Tests never mutate the global environment or define process constants.
     *
     * The map is held in a per-test-class registry and the returned closure
     * captures ONLY its string key: exception traces carry the reader as a
     * live frame argument, and print_r/var_dump render a Closure's captured
     * state — a map-capturing closure would leak fixture values into the very
     * secrecy surfaces these tests scan.
     */
    private static array $reader_maps = [];

    private static int $reader_seq = 0;

    /**
     * @param array<string, mixed> $map
     */
    private static function readerFor(array $map): \Closure
    {
        $key = 'm' . ++self::$reader_seq;
        self::$reader_maps[$key] = $map;

        return static function (string $name) use ($key) {
            return array_key_exists($name, self::$reader_maps[$key]) ? self::$reader_maps[$key][$name] : null;
        };
    }

    /**
     * A complete, clearly fake, valid deployment map (prefix absent on
     * purpose: it is the ONLY optional field). Fresh non-production key
     * material per call; nothing here is a real credential.
     *
     * @return array<string, mixed>
     */
    private static function fullValidMap(): array
    {
        return [
            self::NAME_ENDPOINT          => self::FAKE_ENDPOINT,
            self::NAME_REGION            => self::FAKE_REGION,
            self::NAME_BUCKET            => self::FAKE_BUCKET,
            self::NAME_PATH_STYLE        => true,
            self::NAME_ACCESS_KEY_ID     => self::FAKE_ACCESS_KEY,
            self::NAME_SECRET_ACCESS_KEY => self::FAKE_SECRET,
            self::NAME_ENCRYPTION_KEY_B64 => base64_encode(random_bytes(32)),
        ];
    }

    /** @return array<string, string> NAME_* constant => deployment name, in registry order. */
    private static function nameConstantsOf(string $class): array
    {
        $expected = [
            'NAME_ENDPOINT'          => self::NAME_ENDPOINT,
            'NAME_REGION'            => self::NAME_REGION,
            'NAME_BUCKET'            => self::NAME_BUCKET,
            'NAME_PREFIX'            => self::NAME_PREFIX,
            'NAME_PATH_STYLE'        => self::NAME_PATH_STYLE,
            'NAME_ACCESS_KEY_ID'     => self::NAME_ACCESS_KEY_ID,
            'NAME_SECRET_ACCESS_KEY' => self::NAME_SECRET_ACCESS_KEY,
            'NAME_ENCRYPTION_KEY_B64' => self::NAME_ENCRYPTION_KEY_B64,
        ];

        $actual = [];
        $constants = defined($class . '::NAME_ENDPOINT') ? [
            'NAME_ENDPOINT'          => constant($class . '::NAME_ENDPOINT'),
            'NAME_REGION'            => defined($class . '::NAME_REGION') ? constant($class . '::NAME_REGION') : null,
            'NAME_BUCKET'            => defined($class . '::NAME_BUCKET') ? constant($class . '::NAME_BUCKET') : null,
            'NAME_PREFIX'            => defined($class . '::NAME_PREFIX') ? constant($class . '::NAME_PREFIX') : null,
            'NAME_PATH_STYLE'        => defined($class . '::NAME_PATH_STYLE') ? constant($class . '::NAME_PATH_STYLE') : null,
            'NAME_ACCESS_KEY_ID'     => defined($class . '::NAME_ACCESS_KEY_ID') ? constant($class . '::NAME_ACCESS_KEY_ID') : null,
            'NAME_SECRET_ACCESS_KEY' => defined($class . '::NAME_SECRET_ACCESS_KEY') ? constant($class . '::NAME_SECRET_ACCESS_KEY') : null,
            'NAME_ENCRYPTION_KEY_B64' => defined($class . '::NAME_ENCRYPTION_KEY_B64') ? constant($class . '::NAME_ENCRYPTION_KEY_B64') : null,
        ] : [];

        foreach ($expected as $constantName => $deploymentName) {
            $actual[$constantName] = is_string($constants[$constantName] ?? null) ? $constants[$constantName] : '';
        }

        return $actual;
    }

    /** Capture a bounded BackupException without letting others escape as fatals. */
    private function captureError(callable $attempt): ?BackupException
    {
        try {
            $attempt();
        } catch (BackupException $error) {
            return $error;
        }

        return null;
    }

    /**
     * Every caller-visible error surface that could leak a value.
     *
     * @return array<string, string>
     */
    private function errorSurfaces(BackupException $error): array
    {
        return [
            'getMessage'   => $error->getMessage(),
            'getErrorCode' => $error->getErrorCode(),
            'toString'     => (string) $error,
            'var_export'   => $this->stringify($error, 'var_export'),
            'print_r'      => print_r($error, true),
            'json_encode'  => $this->stringify($error, 'json_encode'),
            'serialize'    => $this->stringify($error, 'serialize'),
            'var_dump'     => $this->stringify($error, 'var_dump'),
        ];
    }

    /**
     * Safe debug/serialization representations of a value object.
     *
     * @return array<string, string>
     */
    private function representationSurfaces(object $object): array
    {
        $surfaces = [
            'var_export'  => $this->stringify($object, 'var_export'),
            'print_r'     => print_r($object, true),
            'json_encode' => $this->stringify($object, 'json_encode'),
            'serialize'   => $this->stringify($object, 'serialize'),
            'var_dump'    => $this->stringify($object, 'var_dump'),
        ];
        if (method_exists($object, '__toString')) {
            $surfaces['toString'] = (string) $object;
        }

        return $surfaces;
    }

    /** Render a value with the requested dumper, never throwing on failure. */
    private function stringify(mixed $value, string $mode): string
    {
        try {
            switch ($mode) {
                case 'var_export':
                    return var_export($value, true);
                case 'json_encode':
                    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    return is_string($json) ? $json : '';
                case 'serialize':
                    return serialize($value);
                case 'var_dump':
                    ob_start();
                    var_dump($value);
                    return (string) ob_get_clean();
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    /** Deep-flatten any structure into one scannable string (bounded by design to small fixtures). */
    private function stringifyDeep(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }
        if (is_iterable($value)) {
            $out = '';
            foreach ($value as $item) {
                $out .= "\n" . $this->stringifyDeep($item);
            }

            return $out;
        }
        if (is_object($value)) {
            return "\n" . $this->stringify($value, 'var_export') . "\n" . $this->stringify($value, 'print_r');
        }

        return '';
    }
}
