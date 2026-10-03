<?php
/**
 * Phase 15 — NEXT SLICE: PROVIDER-INDEPENDENT SOURCE-BACKUP → REMOTE RECOVERY CHAIN.
 *
 * ============================================================================
 * WHAT THIS FILE PROVES (one bounded, provider-independent integration slice)
 * ============================================================================
 *
 * An artifact produced by the REAL `BackupService::createBackup()` traverses the
 * existing S3 mirror and the existing remote reconstruction/preflight path:
 *
 *   real BackupService::createBackup()
 *     → real BackupS3Mirror::mirrorBackup()
 *     → the existing fake/local AWS SDK HTTP handling (the documented Slice 2B
 *       `S3BackupClientFactory` `http_handler` seam) captures encrypted objects
 *     → the exact eight-field pointer produced by the mirror result
 *       (`BackupS3Mirror::pointerFromResult()` / `POINTER_FIELDS`)
 *     → real BackupS3MirrorRecovery::reconstructAndPreflight()
 *     → decrypt/reconstruct into owned private staging
 *     → existing LocalBackupVerifier succeeds
 *     → existing BackupService::restorePreflight() succeeds
 *     → NO restoreApply
 *     → NO SQL import/write caused by recovery
 *     → NO active clinical-storage mutation caused by recovery
 *     → owned recovery staging is cleaned.
 *
 * The source backup is deliberately NOT hand-written. It is created by the real
 * production engine over a real `cpms_*` MySQL dump plus real clinical files
 * written by the production `LocalFileStorage` under the authoritative
 * per-Clinic active roots registered in `cpms_settings`. That is the difference
 * from `Phase15S3MirrorReconstructionRedTest`, whose reconstruction fixture
 * writes db.sql/manifest.json/storage bytes by hand and pins a synthetic
 * `1/a3/…` path.
 *
 * ============================================================================
 * EXPLICITLY OUT OF SCOPE — not expressed, referenced, stubbed or pre-wired
 * ============================================================================
 *
 * retention/prune policy, scheduler or `backup.run` integration, settings/UI,
 * monitoring infrastructure, persistent recovery status, destructive remote
 * restore (`restoreApply`), external alerts, KMS/escrow, migrations, real
 * provider calls, and any new transport/provider abstraction created for
 * testing. No production code is introduced or changed by this file.
 *
 * ============================================================================
 * NO NETWORK / NO REAL PROVIDER
 * ============================================================================
 *
 * Every AWS request below is intercepted in-process by the pinned SDK's
 * documented `http_handler` seam. The endpoint uses the RFC 6761 reserved
 * `.invalid` TLD, so even an accidental bypass could not resolve to a real S3
 * endpoint. Bucket, prefix, region, credentials, encryption key, clinical
 * payloads and Clinic identifiers are synthetic and non-production.
 *
 * ============================================================================
 * LIVE STATE AT AUTHORING (independently verified BEFORE any write)
 * ============================================================================
 *
 *   repository root      : /home/user/doctor
 *   branch               : arena/01a103d2-doctor
 *   HEAD = origin/main   : 12432ad0b35a489a62cbe6f432853fc90431514c
 *                          (merge of PR #168 — Phase 15 Slice 2D)
 *   worktree             : clean (tracked + untracked = none)
 *   open PRs             : 0
 *   latest migration     : 2026_09_26_0023_handwriting_prescription_paper.php
 *                          (no 0024 exists; none is created or referenced)
 *
 * ============================================================================
 * TARGET-ISOLATION LIMITATION (evidence-honest; NOT a fresh installation)
 * ============================================================================
 *
 * This runs on the repository's EXISTING isolated integration mechanism
 * (`WP_UnitTestCase` + `tests/integration-bootstrap.php` + the real MySQL 8
 * service in CI). It is therefore a BOUNDED ISOLATED TARGET, not a fresh
 * WordPress installation:
 *
 *   isolated and owned by this test:
 *     - the source backup store root, the recovery operation store root, the
 *       mirror scratch root, the recovery staging root and both active clinical
 *       storage roots are unique private `0700` directories under a single
 *       random test root outside the document root, removed in tearDown;
 *     - the Organizations/Clinics/settings rows and the clinical files are
 *       created per test and removed per test (plus the WP per-test
 *       transaction rollback);
 *     - the recovery `BackupService` is bound to an operation store that
 *       provably does NOT contain the source backup before reconstruction, so
 *       the only valid preflight source is the newly created private stage.
 *
 *   NOT isolated (shared, pre-existing):
 *     - the WordPress install and the `cpms_*` schema are the shared CI test
 *       install migrated once by `tests/integration-bootstrap.php`. There is no
 *       per-test fresh installation, no fresh `wp-config.php` and no empty
 *       database. Claiming a "clean/fresh target bootstrap" here would be
 *       false, and no such claim is made.
 *
 * Representing a genuinely fresh target would require material new
 * infrastructure (a second WordPress test architecture), which this slice
 * deliberately does not invent.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Backup\BackupS3Mirror;
use ClinicCore\Application\Backup\BackupService;
use ClinicCore\Application\Backup\LocalBackupVerifier;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Backup\BackupEncryptionEnvelope;
use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Backup\BackupSqlDumper;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Settings\InstallationSettings;
use ClinicCore\Settings\Settings;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\StreamInterface;
use WP_UnitTestCase;

/**
 * Real source backup → real encrypted mirror → real remote reconstruction →
 * existing verifier/restore preflight, without a restore and without a provider.
 */
final class Phase15SourceBackupRemoteRecoveryChainTest extends WP_UnitTestCase
{
    private const RECOVERY_CLASS  = 'ClinicCore\\Application\\Backup\\BackupS3MirrorRecovery';
    private const RECOVERY_METHOD = 'reconstructAndPreflight';
    private const MIRROR_ACTION   = 'BACKUP_MIRROR_UPLOADED';

    /**
     * Fake, explicitly non-production provider configuration. `.invalid` is a
     * reserved TLD (RFC 6761) so no real endpoint can ever be reached.
     */
    private const FAKE_ENDPOINT   = 'https://cpms-source-chain.invalid';
    private const FAKE_REGION     = 'cpms-source-chain-1';
    private const FAKE_BUCKET     = 'cpms_source_chain_bucket';
    private const FAKE_PREFIX     = 'cpms/phase15/source-chain';
    private const FAKE_ACCESS_KEY = 'CPMSSOURCECHAINACCESSNOTPRODUCTION01';
    private const FAKE_SECRET     = 'cpms-source-chain-not-production-secret-01';

    /** Distinct fixture key bytes keep this test's leak markers unambiguous. */
    private const FIXTURE_KEY_BYTE = "\xb5";
    private const WRONG_KEY_BYTE   = "\x3c";

    /**
     * Synthetic, clearly non-real markers. Each one is placed in exactly one
     * plaintext surface of the real backup artifact so that "no plaintext
     * backup payload is ever sent as an S3 object" is falsifiable.
     */
    private const SQL_MARKER      = 'CPMS-SYNTHETIC-SQL-SOURCE-MARKER-NOT-REAL';
    private const CLINICAL_A      = 'CPMS-SYNTHETIC-CLINICAL-A-MARKER-NOT-REAL';
    private const CLINICAL_B      = 'CPMS-SYNTHETIC-CLINICAL-B-MARKER-NOT-REAL';
    private const NOTE_MARKER     = 'CPMS-SYNTHETIC-BACKUP-NOTE-MARKER-NOT-REAL';
    private const FILE_EXTENSION  = 'pdf';

    /** One shared random private root; every owned path lives below it. */
    private string $root;
    private string $clinicalRootA;
    private string $clinicalRootB;
    private string $mirrorScratch;
    private ProtectedBackupStore $sourceStore;
    private ProtectedBackupStore $operationStore;
    private BackupService $sourceBackupService;
    private BackupService $operationBackupService;

    private int $organizationId    = 0;
    private int $clinicIdA         = 0;
    private int $clinicIdB         = 0;
    private string $clinicalRelA   = '';
    private string $clinicalRelB   = '';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();

        $this->root = rtrim(sys_get_temp_dir(), '/') . '/cpms-phase15-source-chain-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700, true), 'fixture precondition: create the isolated private test root');
        chmod($this->root, 0700);

        $this->sourceStore    = ProtectedBackupStore::active($this->root . '/source-backup-store');
        // Recovery is bound to a DIFFERENT store that never contains the source
        // backup, so the reconstructed private stage is the only preflight source.
        $this->operationStore = ProtectedBackupStore::active($this->root . '/recovery-operation-store');
        $this->mirrorScratch  = $this->root . '/mirror-scratch';
        $this->clinicalRootA  = $this->root . '/clinical-a/active-storage';
        $this->clinicalRootB  = $this->root . '/clinical-b/active-storage';
        mkdir($this->clinicalRootA, 0700, true);
        mkdir($this->clinicalRootB, 0700, true);

        $this->registerActiveClinicalStorageRoots();

        $db       = App::db();
        $dumper   = new BackupSqlDumper($db);
        $settings = App::installationSettings();
        $audit    = App::audit();
        $op       = App::op();
        $this->sourceBackupService = new BackupService(
            $db,
            $this->sourceStore,
            $dumper,
            $settings,
            $audit,
            $op,
            $this->clinicalRootA
        );
        $this->operationBackupService = new BackupService(
            $db,
            $this->operationStore,
            $dumper,
            $settings,
            $audit,
            $op,
            $this->clinicalRootA
        );

        $activeRoots = $this->operationBackupService->activeClinicalStorageRoots();
        self::assertContains(
            $this->clinicalRootA,
            $activeRoots,
            'fixture precondition: the first active clinical root is read through the existing all-Clinic backup configuration model'
        );
        self::assertContains(
            $this->clinicalRootB,
            $activeRoots,
            'fixture precondition: the second active clinical root is read through the existing all-Clinic backup configuration model'
        );
        self::assertFalse(
            $this->operationStore->isReadonly(),
            'fixture precondition: the recovery operation store is an active writable store, not a legacy read-only source'
        );
    }

    protected function tearDown(): void
    {
        $this->removeActiveClinicalStorageRoots();
        if (isset($this->root) && is_dir($this->root)) {
            $this->removeTree($this->root);
        }
        if (function_exists('delete_option')) {
            delete_option(InstallationSettings::OPTION_BACKUP_ENABLED);
            delete_option(InstallationSettings::OPTION_BACKUP_INTERVAL_HOURS);
            delete_option(InstallationSettings::OPTION_BACKUP_KEEP_COUNT);
            delete_option(InstallationSettings::OPTION_BACKUP_STORAGE_PATH);
            delete_option(InstallationSettings::OPTION_BACKUP_LAST_RUN_AT);
        }
        Settings::flushCache();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // LEG 1 — real createBackup() → real mirrorBackup() → encrypted objects
    //         + the exact bounded eight-field pointer.
    // ---------------------------------------------------------------------

    public function testRealServiceBackupMirrorsToEncryptedObjectsOnlyAndYieldsTheExactBoundedPointer(): void
    {
        $source = $this->createRealSourceBackup();
        $sourceBefore = $this->fileMap($source['dir']);
        $watermarks   = $this->logWatermarks();

        $putRequests = [];
        $mirror      = $this->mirrorRealBackup($source['backup_id'], $putRequests);

        // ── the exact bounded pointer produced by the mirror result ──
        $pointer = BackupS3Mirror::pointerFromResult($mirror);
        self::assertSame(
            BackupS3Mirror::POINTER_FIELDS,
            array_keys($pointer),
            'contract: the pointer is exactly the eight bounded Slice 2C fields, in order, and nothing else'
        );
        self::assertSame($source['backup_id'], $pointer['backup_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $pointer['mirror_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $pointer['catalog_object_id']);
        self::assertNotSame($pointer['mirror_id'], $pointer['catalog_object_id']);
        self::assertSame(BackupS3Mirror::STRENGTH_VERIFIED, $pointer['verification']);
        self::assertSame('ok', $pointer['result_code']);
        self::assertGreaterThan(0, $pointer['timestamp']);

        $storageCount    = count($source['manifest']['storage']['files']);
        $expectedObjects = $storageCount + 3; // db.sql + manifest.json + catalog
        self::assertSame($expectedObjects, $pointer['object_count'], 'contract: one remote object per logical file plus exactly one remote-only catalog');
        self::assertCount($expectedObjects, $putRequests, 'contract: the mirror issues exactly one single-part PutObject per remote object');
        self::assertSame(
            array_sum(array_map('strlen', array_column($putRequests, 'body'))),
            $pointer['ciphertext_bytes'],
            'contract: the pointer byte total is the real captured ciphertext total'
        );

        // ── captured remote objects: opaque keys, encrypted bodies only ──
        $objects = [];
        foreach ($putRequests as $request) {
            self::assertSame('PUT', $request['method'], 'contract: the mirror leg only writes objects (single-part PutObject)');
            $prefix = '/' . self::FAKE_BUCKET . '/' . self::FAKE_PREFIX . '/' . $pointer['mirror_id'] . '/';
            self::assertStringStartsWith($prefix, $request['path'], 'contract: the object key is the configured prefix plus the opaque mirror id only');
            $objectId = substr($request['path'], strlen($prefix));
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $objectId, 'contract: no backup id, logical path, filename or tenant identity enters an object key');
            self::assertSame(
                0,
                strpos($request['body'], BackupEncryptionEnvelope::MAGIC),
                'contract: every remote object body is an authenticated Slice 1 envelope, never a plaintext payload'
            );
            self::assertArrayNotHasKey($objectId, $objects, 'contract: object ids are unique per attempt');
            $objects[$objectId] = (string) $request['body'];
        }
        $lastPath     = (string) $putRequests[count($putRequests) - 1]['path'];
        $lastObjectId = substr($lastPath, strrpos($lastPath, '/') + 1);
        self::assertSame(
            $pointer['catalog_object_id'],
            $lastObjectId,
            'contract: the remote-only catalog is the final PutObject of the real mirror operation'
        );

        // ── no plaintext backup payload is ever an S3 object body ──
        $plaintexts = $this->sourcePlaintexts($source);
        self::assertCount(
            $storageCount + 2,
            $plaintexts,
            'fixture precondition: the real logical set is db.sql + manifest.json + one entry per real clinical file'
        );
        foreach ($objects as $objectId => $body) {
            foreach ($this->plaintextMarkers() as $marker) {
                self::assertStringNotContainsString(
                    $marker,
                    $body,
                    'contract: no plaintext marker of the real backup appears in any captured remote object body'
                );
            }
        }

        // ── decryption is a bijection onto the real source files ──
        $catalogId = $pointer['catalog_object_id'];
        $remaining = $plaintexts;
        foreach ($objects as $objectId => $body) {
            if ($objectId === $catalogId) {
                continue;
            }
            $plain = $this->decryptFixture($body, $this->fixtureKey(), $objectId);
            $match = array_search($plain, $remaining, true);
            self::assertIsString(
                $match,
                'contract: every decrypted remote data object is byte-identical to exactly one real source file'
            );
            unset($remaining[$match]);
        }
        self::assertSame(
            [],
            $remaining,
            'contract: the real db.sql, the real manifest.json and every real clinical file were mirrored, each exactly once'
        );
        // ── the authenticated catalog binds the real artifact identity ──
        $catalogJson = $this->decryptFixture($objects[$catalogId], $this->fixtureKey(), 'catalog-' . $catalogId);
        $catalog     = json_decode($catalogJson, true);
        self::assertIsArray($catalog, 'fixture precondition: the real catalog decrypts to JSON');
        self::assertSame(BackupS3Mirror::CATALOG_FORMAT, $catalog['format']);
        self::assertSame(BackupS3Mirror::CATALOG_FORMAT_VERSION, $catalog['format_version']);
        self::assertSame($source['backup_id'], $catalog['local_backup_id'], 'contract: the catalog binds the real service-produced backup id');
        self::assertSame($pointer['mirror_id'], $catalog['mirror_id']);
        self::assertSame($catalogId, $catalog['catalog_object_id']);
        self::assertSame(BackupEncryptionEnvelope::MAGIC, $catalog['envelope_format']);
        self::assertSame(
            (string) hash_file('sha256', $source['dir'] . '/manifest.json'),
            $catalog['local_manifest_sha256'],
            'contract: the catalog authenticates the real manifest digest produced by createBackup()'
        );
        $logicalPaths = array_column((array) $catalog['entries'], 'logical_path');
        self::assertContains('db.sql', $logicalPaths, 'contract: the real dump is a remote logical object');
        self::assertContains('manifest.json', $logicalPaths, 'contract: the real manifest is a remote logical object');
        self::assertContains('storage/' . $this->clinicalRelA, $logicalPaths, 'contract: the first real per-Clinic clinical file keeps its authoritative relative path');
        self::assertContains('storage/' . $this->clinicalRelB, $logicalPaths, 'contract: the second real per-Clinic clinical file keeps its authoritative relative path');
        self::assertNotContains(
            'manifest.json.sha256',
            $logicalPaths,
            'contract: the local authenticity sidecar is deliberately never a remote object'
        );
        sort($logicalPaths);
        $expectedPaths = array_keys($plaintexts);
        sort($expectedPaths);
        self::assertSame(
            $expectedPaths,
            $logicalPaths,
            'contract: catalog logical paths are exactly the real backup layout and nothing more'
        );
        $roles = array_count_values(array_column((array) $catalog['entries'], 'role'));
        self::assertSame(1, $roles['database'] ?? 0);
        self::assertSame(1, $roles['manifest'] ?? 0);
        self::assertSame($storageCount, $roles['storage'] ?? 0);

        // ── the real source backup is unchanged and still valid ──
        self::assertSame($sourceBefore, $this->fileMap($source['dir']), 'safety: mirroring leaves the real source backup byte-identical');
        $verify = $this->sourceBackupService->verifyBackup($source['backup_id']);
        self::assertTrue($verify['ok'], 'safety: the real source backup still verifies after mirroring');
        self::assertSame([], $verify['errors']);
        self::assertPathAbsent($this->mirrorScratch, 'contract: the mirror removes its own temporary ciphertext and plaintext catalog');

        // ── the durable footprint of the real mirror is bounded and leak-free ──
        $rows = $this->newLogRows($watermarks);
        self::assertSame(
            [self::MIRROR_ACTION],
            array_values(array_unique(array_column($rows, 'label'))),
            'contract: the only durable footprint is the existing audit/operational mirror pointer record'
        );
        $this->assertNoLeaksInRows($rows, $objects, $pointer, $plaintexts);
    }

    // ---------------------------------------------------------------------
    // LEG 2 — the exact pointer → real reconstructAndPreflight() → verifier +
    //         existing restorePreflight, with no restore and no writes.
    // ---------------------------------------------------------------------

    public function testRealMirroredPointerReconstructsAndPassesTheExistingVerifierAndRestorePreflight(): void
    {
        $class  = $this->recoveryClass();
        $source = $this->createRealSourceBackup();

        self::assertFalse(
            $this->operationStore->exists($source['backup_id']),
            'target isolation: the recovery operation store provably lacks the artifact before reconstruction'
        );

        $sourceBefore = $this->fileMap($source['dir']);
        $watermarks   = $this->logWatermarks();
        $putRequests  = [];
        $mirror       = $this->mirrorRealBackup($source['backup_id'], $putRequests);
        $pointer      = BackupS3Mirror::pointerFromResult($mirror);
        $objects      = $this->objectsFromPutRequests($putRequests);

        $databaseBefore = $this->databaseSnapshot();
        $storageBefore  = $this->activeClinicalStorageSnapshot();
        $stage          = $this->stagePath();
        $getRequests    = [];

        $preflightCalls      = 0;
        $verifiedAtPreflight = false;
        $nonReadQueries      = [];
        $observer            = function ($query) use (&$preflightCalls, &$verifiedAtPreflight, &$nonReadQueries, $stage, $source, $sourceBefore): string {
            if (preg_match('/^\s*(?:SELECT|SHOW|EXPLAIN|DESCRIBE)\b/i', ltrim((string) $query)) !== 1) {
                $nonReadQueries[] = 'non-read SQL was issued during recovery';
            }
            if (trim((string) $query) === 'SELECT 1') {
                ++$preflightCalls;
                $staged = $stage . '/' . $source['backup_id'];
                self::assertDirectoryExists($staged, 'contract: restorePreflight is reached only after reconstruction into the distinct private stage');
                self::assertSame(
                    $sourceBefore,
                    $this->fileMap($staged),
                    'contract: the reconstructed stage reproduces the real backup layout byte-for-byte, including the real db.sql and real per-Clinic clinical files'
                );
                self::assertSame(
                    (string) hash_file('sha256', $source['dir'] . '/manifest.json'),
                    (string) hash_file('sha256', $staged . '/manifest.json'),
                    'contract: the reconstructed manifest is the real manifest'
                );
                $localVerify = LocalBackupVerifier::verify($staged, $source['backup_id'], true);
                self::assertTrue($localVerify['ok'], 'contract: the existing LocalBackupVerifier passes against the reconstructed real backup');
                self::assertSame([], $localVerify['errors']);
                $verifiedAtPreflight = true;
            }

            return (string) $query;
        };

        add_filter('query', $observer, 999, 1);
        try {
            $result = $this->recover($objects, $pointer, $this->fixtureKey(), $stage, $getRequests);
        } finally {
            remove_filter('query', $observer, 999);
        }

        // ── bounded success projection, echoing the exact consumed pointer ──
        $this->assertSuccessfulBoundedResult($result, $pointer);
        self::assertSame($source['backup_id'], $result['backup_id'], 'contract: the reconstructed backup id is the real service-produced backup id');
        self::assertSame(1, $preflightCalls, 'contract: the existing BackupService::restorePreflight() reaches its database probe exactly once');
        self::assertTrue($verifiedAtPreflight, 'contract: the existing local verifier passed at preflight time');

        // ── only reads, only opaque keys, every object fetched ──
        self::assertSame([], $nonReadQueries, 'safety: recovery issues no SQL write, DDL or import statement — the staged dump is never executed');
        self::assertGreaterThan(1, count($getRequests), 'contract: the catalog and every encrypted data object are fetched through the official handler');
        self::assertSame(
            array_fill(0, count($getRequests), 'GET'),
            array_column($getRequests, 'method'),
            'contract: reconstruction only reads remote objects'
        );
        $this->assertOnlyOpaqueKeysWereRequested($getRequests, $pointer);
        $fetched = [];
        foreach ($getRequests as $request) {
            $fetched[] = substr((string) $request['path'], strrpos((string) $request['path'], '/') + 1);
        }
        sort($fetched);
        $expectedIds = array_keys($objects);
        sort($expectedIds);
        self::assertSame($expectedIds, $fetched, 'contract: exactly the objects named by the real mirror pointer are requested, each once');

        // ── no destructive restore anywhere in the recovery operation ──
        $this->assertNoRestoreApplyCall($class);

        // ── owned staging is cleaned; no active-DB or clinical mutation ──
        $this->assertPathAbsent($stage, 'contract: owned recovery staging is removed after success');
        self::assertSame($databaseBefore, $this->databaseSnapshot(), 'safety: recovery imports no SQL and mutates no database state');
        self::assertSame(
            $storageBefore,
            $this->activeClinicalStorageSnapshot(),
            'safety: recovery mutates no authoritative active clinical storage root'
        );
        self::assertFalse(
            $this->operationStore->exists($source['backup_id']),
            'safety: recovery never writes the reconstructed backup into the operation store'
        );

        // ── the real source backup is still valid and unchanged ──
        self::assertSame($sourceBefore, $this->fileMap($source['dir']), 'safety: the real source backup is byte-identical after the whole chain');
        $verify = $this->sourceBackupService->verifyBackup($source['backup_id']);
        self::assertTrue($verify['ok'], 'safety: the real source backup still verifies after the whole chain');
        self::assertSame('ok_quick', $this->sourceBackupService->backupMeta($source['backup_id'])['integrity']);
        self::assertPathAbsent($this->mirrorScratch, 'contract: no mirror scratch survives the chain');

        // ── success projection and durable footprint are leak-free ──
        $plaintexts    = $this->sourcePlaintexts($source);
        $encodedResult = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        self::assertIsString($encodedResult);
        $this->assertNoLeaksInText($encodedResult, $objects, $pointer, $plaintexts);
        $rows = $this->newLogRows($watermarks);
        $this->assertNoLeaksInRows($rows, $objects, $pointer, $plaintexts);
        self::assertSame(
            [self::MIRROR_ACTION],
            array_values(array_unique(array_column($rows, 'label'))),
            'contract: the reconstruction/preflight leg adds no durable footprint of its own'
        );
    }

    // ---------------------------------------------------------------------
    // LEG 3 — the same real artifact under a wrong key: bounded, leak-free,
    //         clean, and still non-destructive.
    // ---------------------------------------------------------------------

    public function testRealMirroredRecoveryFailureStaysBoundedLeakFreeAndNonDestructive(): void
    {
        $class  = $this->recoveryClass();
        $source = $this->createRealSourceBackup();

        $sourceBefore = $this->fileMap($source['dir']);
        $plaintexts   = $this->sourcePlaintexts($source);
        $watermarks   = $this->logWatermarks();
        $putRequests  = [];
        $mirror       = $this->mirrorRealBackup($source['backup_id'], $putRequests);
        $pointer      = BackupS3Mirror::pointerFromResult($mirror);
        $objects      = $this->objectsFromPutRequests($putRequests);

        $databaseBefore = $this->databaseSnapshot();
        $storageBefore  = $this->activeClinicalStorageSnapshot();
        $stage          = $this->stagePath();
        $getRequests    = [];
        $nonReadQueries = [];
        $observer       = static function (string $query) use (&$nonReadQueries): string {
            if (preg_match('/^\s*(?:SELECT|SHOW|EXPLAIN|DESCRIBE)\b/i', ltrim($query)) !== 1) {
                $nonReadQueries[] = 'non-read SQL was issued during a failed recovery';
            }

            return $query;
        };

        $failure = null;
        add_filter('query', $observer, 999, 1);
        try {
            $this->recover($objects, $pointer, str_repeat(self::WRONG_KEY_BYTE, 32), $stage, $getRequests);
            self::fail('contract: reconstructing the real mirror with a wrong key must fail closed');
        } catch (AssertionFailedError $error) {
            throw $error;
        } catch (BackupException $error) {
            $failure = $error;
        } catch (\Throwable $error) {
            self::fail('contract: failures surface only as a bounded BackupException, not raw ' . get_class($error));
        } finally {
            remove_filter('query', $observer, 999);
        }

        self::assertInstanceOf(BackupException::class, $failure);
        $this->assertBoundedFailure($failure, $objects, $pointer, $plaintexts);
        self::assertSame([], $nonReadQueries, 'safety: a failed recovery issues no SQL write, DDL or import statement');
        $this->assertPathAbsent($stage, 'contract: owned recovery staging is removed after failure');
        self::assertSame($databaseBefore, $this->databaseSnapshot(), 'safety: a failed recovery mutates no database state');
        self::assertSame(
            $storageBefore,
            $this->activeClinicalStorageSnapshot(),
            'safety: a failed recovery mutates no authoritative active clinical storage root'
        );
        self::assertFalse($this->operationStore->exists($source['backup_id']), 'safety: a failed recovery writes nothing into the operation store');
        self::assertSame($sourceBefore, $this->fileMap($source['dir']), 'safety: the real source backup is byte-identical after a failed recovery');
        self::assertTrue($this->sourceBackupService->verifyBackup($source['backup_id'])['ok'], 'safety: the real source backup still verifies after a failed recovery');
        $this->assertNoRestoreApplyCall($class);
        $this->assertNoLeaksInRows($this->newLogRows($watermarks), $objects, $pointer, $plaintexts);
    }

    // ---------------------------------------------------------------------
    // Real source artifact — produced only by the production backup engine.
    // ---------------------------------------------------------------------

    /**
     * @return array{backup_id: string, dir: string, manifest: array<string, mixed>, meta: array<string, mixed>}
     */
    private function createRealSourceBackup(): array
    {
        $meta = $this->sourceBackupService->createBackup(self::NOTE_MARKER);
        $backupId = (string) ($meta['backup_id'] ?? '');
        self::assertNotSame('', $backupId, 'fixture precondition: the real engine returns a backup id');
        self::assertSame('ok_quick', $meta['integrity'], 'fixture precondition: the real engine reports a fully verified artifact');
        self::assertTrue((bool) ($meta['manifest_valid'] ?? false), 'fixture precondition: the real manifest is valid');

        $dir = $this->sourceStore->dirOf($backupId);
        $raw = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        self::assertIsArray($raw, 'fixture precondition: read the real manifest');
        self::assertSame($backupId, (string) $raw['backup_id']);
        self::assertSame(self::NOTE_MARKER, (string) $raw['note'], 'fixture precondition: the note marker is inside the real manifest only');

        $files = (array) $raw['storage']['files'];
        self::assertCount(2, $files, 'fixture precondition: the real backup contains exactly the two real clinical files');
        $paths = array_column($files, 'path');
        sort($paths);
        $expected = [$this->clinicalRelA, $this->clinicalRelB];
        sort($expected);
        self::assertSame($expected, $paths, 'fixture precondition: the real backup carries the real per-Clinic relative paths, not a synthetic layout');

        $verify = $this->sourceBackupService->verifyBackup($backupId);
        self::assertTrue($verify['ok'], 'fixture precondition: the real artifact passes the existing local verification');
        self::assertSame([], $verify['errors']);

        return ['backup_id' => $backupId, 'dir' => $dir, 'manifest' => $raw, 'meta' => $meta];
    }

    /**
     * Real logical files of the real artifact, keyed by backup-relative path.
     *
     * @param array{backup_id: string, dir: string, manifest: array<string, mixed>, meta: array<string, mixed>} $source
     *
     * @return array<string, string>
     */
    private function sourcePlaintexts(array $source): array
    {
        $dir = $source['dir'];
        $plaintexts = [
            'db.sql'        => (string) file_get_contents($dir . '/db.sql'),
            'manifest.json' => (string) file_get_contents($dir . '/manifest.json'),
        ];
        foreach ((array) $source['manifest']['storage']['files'] as $file) {
            $rel = (string) (is_array($file) ? $file['path'] : '');
            self::assertNotSame('', $rel, 'fixture precondition: every real storage entry has a relative path');
            $plaintexts['storage/' . $rel] = (string) file_get_contents($dir . '/storage/' . $rel);
        }

        return $plaintexts;
    }

    /** @return list<string> */
    private function plaintextMarkers(): array
    {
        return [
            self::SQL_MARKER,
            self::CLINICAL_A,
            self::CLINICAL_B,
            self::NOTE_MARKER,
            $this->clinicalRelA,
            $this->clinicalRelB,
        ];
    }

    // ---------------------------------------------------------------------
    // Existing production services + the existing documented SDK test seam.
    // ---------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $putRequests
     *
     * @return array<string, mixed>
     */
    private function mirrorRealBackup(string $backupId, array &$putRequests): array
    {
        $mirror = new BackupS3Mirror(
            $this->sourceStore,
            $this->deploymentConfig($this->fixtureKey()),
            $this->putObjectHandler($putRequests),
            $this->mirrorScratch,
            App::audit(),
            App::op()
        );

        $result = $mirror->mirrorBackup($backupId);
        self::assertTrue(
            (bool) ($result['ok'] ?? false),
            'fixture precondition: the real mirror succeeds over the real service-produced backup using only the in-process handler'
        );
        self::assertSame(
            BackupS3Mirror::RESULT_VERIFIED,
            $result['result'],
            'fixture precondition: the checksum-capable fake endpoint yields the verified set-level claim'
        );

        return $result;
    }

    /**
     * @param array<string, string>      $objects
     * @param array<string, mixed>       $pointer
     * @param list<array<string, mixed>> $requests
     *
     * @return array<string, mixed>
     */
    private function recover(array $objects, array $pointer, string $key, string $stage, array &$requests): array
    {
        $class    = $this->recoveryClass();
        $recovery = new $class(
            $this->operationBackupService,
            $this->deploymentConfig($key),
            $this->getObjectHandler($objects, $requests),
            $stage
        );

        return $recovery->reconstructAndPreflight($pointer);
    }

    /**
     * @param list<array<string, mixed>> $putRequests
     *
     * @return array<string, string>
     */
    private function objectsFromPutRequests(array $putRequests): array
    {
        $objects = [];
        foreach ($putRequests as $request) {
            $path             = (string) $request['path'];
            $objectId         = substr($path, strrpos($path, '/') + 1);
            $objects[$objectId] = (string) $request['body'];
        }

        return $objects;
    }

    /** @param list<array<string, mixed>> $requests */
    private function putObjectHandler(array &$requests): callable
    {
        return static function ($request, array $options) use (&$requests) {
            $body     = (string) $request->getBody();
            $checksum = $request->getHeaderLine('x-amz-checksum-sha256');
            $requests[] = [
                'method' => $request->getMethod(),
                'path'   => $request->getUri()->getPath(),
                'query'  => $request->getUri()->getQuery(),
                'body'   => $body,
            ];

            // A checksum-capable endpoint echoes the client digest back.
            return new FulfilledPromise(new Response(200, [
                'ETag' => '"' . md5($body) . '"',
                'x-amz-checksum-sha256' => $checksum,
            ], ''));
        };
    }

    /**
     * Local-only GetObject handling that honours the SDK transfer options the
     * real recovery operation relies on (`sink` + `on_headers`).
     *
     * @param array<string, string>      $objects
     * @param list<array<string, mixed>> $requests
     */
    private function getObjectHandler(array $objects, array &$requests): callable
    {
        return static function ($request, array $handlerOptions) use (&$requests, $objects) {
            $method = $request->getMethod();
            $path   = (string) $request->getUri()->getPath();
            $requests[] = [
                'method' => $method,
                'path'   => $path,
                'query'  => $request->getUri()->getQuery(),
            ];
            if ($method !== 'GET') {
                return new RejectedPromise(new \RuntimeException('chain fixture handler accepts GetObject only'));
            }

            $parts    = explode('/', trim($path, '/'));
            $objectId = (string) end($parts);
            if (!array_key_exists($objectId, $objects)) {
                return new FulfilledPromise(new Response(404, ['Content-Type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>'));
            }

            $body    = $objects[$objectId];
            $headers = [
                'Content-Type'   => 'application/octet-stream',
                'ETag'           => '"' . md5($body) . '"',
                'Last-Modified'  => 'Thu, 15 Jan 2026 09:30:00 GMT',
                'Content-Length' => (string) strlen($body),
            ];

            $response  = new Response(200, $headers, '');
            $onHeaders = $handlerOptions['on_headers'] ?? null;
            if (is_callable($onHeaders)) {
                try {
                    $onHeaders($response);
                } catch (\Throwable $error) {
                    return new RejectedPromise($error);
                }
            }

            $sink = $handlerOptions['sink'] ?? null;
            if ($sink instanceof StreamInterface) {
                $chunkSize = 8192;
                $length    = strlen($body);
                for ($offset = 0; $offset < $length; $offset += $chunkSize) {
                    try {
                        $sink->write(substr($body, $offset, $chunkSize));
                    } catch (\Throwable $error) {
                        return new RejectedPromise($error);
                    }
                }
                if ($sink->isSeekable()) {
                    $sink->rewind();
                }

                return new FulfilledPromise(new Response(200, $headers, $sink));
            }

            return new FulfilledPromise(new Response(200, $headers, $body));
        };
    }

    private function deploymentConfig(string $key): S3BackupDeploymentConfig
    {
        $values = [
            S3BackupDeploymentConfig::NAME_ENDPOINT            => self::FAKE_ENDPOINT,
            S3BackupDeploymentConfig::NAME_REGION              => self::FAKE_REGION,
            S3BackupDeploymentConfig::NAME_BUCKET              => self::FAKE_BUCKET,
            S3BackupDeploymentConfig::NAME_PREFIX              => self::FAKE_PREFIX,
            S3BackupDeploymentConfig::NAME_PATH_STYLE          => true,
            S3BackupDeploymentConfig::NAME_ACCESS_KEY_ID       => self::FAKE_ACCESS_KEY,
            S3BackupDeploymentConfig::NAME_SECRET_ACCESS_KEY   => self::FAKE_SECRET,
            S3BackupDeploymentConfig::NAME_ENCRYPTION_KEY_B64  => base64_encode($key),
        ];
        $config = S3BackupDeploymentConfig::fromReader(static function (string $name) use ($values) {
            return $values[$name] ?? null;
        });
        self::assertInstanceOf(
            S3BackupDeploymentConfig::class,
            $config,
            'fixture precondition: the fake non-production configuration resolves through the existing Slice 2B reader'
        );

        return $config;
    }

    private function fixtureKey(): string
    {
        return str_repeat(self::FIXTURE_KEY_BYTE, 32);
    }

    // ---------------------------------------------------------------------
    // Fixture: two real Clinics with real active roots and real clinical files.
    // ---------------------------------------------------------------------

    private function registerActiveClinicalStorageRoots(): void
    {
        global $wpdb;
        $db  = App::db();
        $now = $db->nowUtcSql();

        $organizationSlug = 'phase15-source-chain-org-' . bin2hex(random_bytes(5));
        $organizationResult = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
            self::SQL_MARKER,
            $organizationSlug,
            'active',
            $now,
            $now
        ));
        self::assertNotFalse($organizationResult, 'fixture precondition: create an isolated Organization for the real backup source');
        $this->organizationId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->organizationId);

        $clinicA = $this->createClinicWithActiveRoot($now, 'a', $this->clinicalRootA, self::CLINICAL_A);
        $clinicB = $this->createClinicWithActiveRoot($now, 'b', $this->clinicalRootB, self::CLINICAL_B);
        $this->clinicIdA     = $clinicA['clinic_id'];
        $this->clinicIdB     = $clinicB['clinic_id'];
        $this->clinicalRelA  = $clinicA['relative'];
        $this->clinicalRelB  = $clinicB['relative'];
        Settings::flushCache();
    }

    /**
     * One real Clinic, its authoritative active root and one real clinical file
     * written by the production storage service.
     *
     * @return array{clinic_id: int, relative: string}
     */
    private function createClinicWithActiveRoot(string $now, string $label, string $clinicalRoot, string $fileMarker): array
    {
        global $wpdb;
        $clinicSlug = 'phase15-source-chain-clinic-' . $label . '-' . bin2hex(random_bytes(5));
        $clinicResult = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->organizationId,
            self::SQL_MARKER . ' ' . $label,
            $clinicSlug,
            'Europe/Berlin',
            $now,
            $now
        ));
        self::assertNotFalse($clinicResult, 'fixture precondition: create an isolated Clinic for the real backup source');
        $clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $clinicId);

        App::settingsFactory()->forClinic($clinicId)->set('files.storage_path', $clinicalRoot);

        // Real production storage writer, real per-Clinic active root.
        $storage  = new LocalFileStorage($clinicalRoot);
        $relative = $storage->store($fileMarker . "\n", $clinicId, self::FILE_EXTENSION);
        self::assertMatchesRegularExpression(
            '/^' . $clinicId . '\/[0-9a-f]{2}\/[0-9a-f]{32}\.' . self::FILE_EXTENSION . '$/',
            $relative,
            'fixture precondition: the clinical file is written by the production storage service under its own Clinic root'
        );

        return ['clinic_id' => $clinicId, 'relative' => $relative];
    }

    private function removeActiveClinicalStorageRoots(): void
    {
        global $wpdb;
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ([$this->clinicIdA, $this->clinicIdB] as $clinicId) {
                if ($clinicId <= 0) {
                    continue;
                }
                $wpdb->delete($wpdb->prefix . 'cpms_settings', ['clinic_id' => $clinicId], ['%d']);
                $wpdb->delete($wpdb->prefix . 'cpms_clinics', ['id' => $clinicId], ['%d']);
            }
            if ($this->organizationId > 0) {
                $wpdb->delete($wpdb->prefix . 'cpms_organizations', ['id' => $this->organizationId], ['%d']);
            }
        } finally {
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        App::settingsFactory()->reset();
        Settings::flushCache();
    }

    // ---------------------------------------------------------------------
    // Bounded contract assertions.
    // ---------------------------------------------------------------------

    private function recoveryClass(): string
    {
        self::assertTrue(class_exists(self::RECOVERY_CLASS), 'contract: the existing recovery operation is present');
        self::assertTrue(
            method_exists(self::RECOVERY_CLASS, self::RECOVERY_METHOD),
            'contract: public reconstructAndPreflight(array $pointer): array exists'
        );
        $reflection = new \ReflectionClass(self::RECOVERY_CLASS);
        self::assertTrue($reflection->isFinal(), 'contract: the operation stays a concrete application service, not a new provider abstraction');
        $method = $reflection->getMethod(self::RECOVERY_METHOD);
        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());
        self::assertSame(1, $method->getNumberOfParameters());
        self::assertSame('pointer', $method->getParameters()[0]->getName());

        return self::RECOVERY_CLASS;
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $pointer */
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
        self::assertSame($pointer['backup_id'], $result['backup_id']);
        self::assertSame($pointer['mirror_id'], $result['mirror_id']);
        self::assertSame($pointer['catalog_object_id'], $result['catalog_object_id']);
        self::assertTrue($result['local_verifier_ok']);
        self::assertTrue($result['restore_preflight_ok']);
    }

    /** @param list<array<string, mixed>> $requests @param array<string, mixed> $pointer */
    private function assertOnlyOpaqueKeysWereRequested(array $requests, array $pointer): void
    {
        $prefix = '/' . self::FAKE_BUCKET . '/' . self::FAKE_PREFIX . '/' . $pointer['mirror_id'] . '/';
        foreach ($requests as $request) {
            $path = (string) $request['path'];
            self::assertStringStartsWith($prefix, $path, 'contract: the remote key is the configured prefix plus the validated mirror id plus the validated object id only');
            $objectId = substr($path, strlen($prefix));
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $objectId, 'contract: no backup id, logical path or filename enters a requested object key');
        }
    }

    /**
     * @param array<string, string> $objects
     * @param array<string, mixed>  $pointer
     * @param array<string, string> $plaintexts
     */
    private function assertBoundedFailure(BackupException $error, array $objects, array $pointer, array $plaintexts): void
    {
        self::assertMatchesRegularExpression('/^CLINIC_BACKUP_[A-Z0-9_]+$/', $error->getErrorCode());
        self::assertLessThanOrEqual(200, strlen($error->getMessage()), 'contract: failure messages stay short and bounded');
        $encodedData = json_encode($error->data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        self::assertIsString($encodedData);
        self::assertLessThanOrEqual(512, strlen($encodedData), 'contract: failure evidence stays bounded');

        $surfaces = $error->getErrorCode() . "\n" . $error->getMessage() . "\n" . $encodedData . "\n" . (string) $error;
        $this->assertNoLeaksInText($surfaces, $objects, $pointer, $plaintexts);
        foreach ($plaintexts as $relative => $bytes) {
            self::assertStringNotContainsString(
                $relative,
                $surfaces,
                'contract: a bounded failure never reveals a real backup-relative logical path'
            );
        }
    }

    /**
     * @param array<string, string> $objects
     * @param array<string, mixed>  $pointer
     * @param array<string, string> $plaintexts
     */
    private function assertNoLeaksInText(string $text, array $objects, array $pointer, array $plaintexts): void
    {
        foreach ($this->forbiddenLeakMarkers($objects, $pointer, $plaintexts) as $marker) {
            if ($marker !== '') {
                self::assertStringNotContainsString(
                    $marker,
                    $text,
                    'contract: bounded outputs never reveal credentials, secrets, key material, PHI, filesystem paths, endpoints or remote object keys'
                );
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string>      $objects
     * @param array<string, mixed>       $pointer
     * @param array<string, string>      $plaintexts
     */
    private function assertNoLeaksInRows(array $rows, array $objects, array $pointer, array $plaintexts): void
    {
        foreach ($rows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            self::assertIsString($encoded);
            $this->assertNoLeaksInText($encoded, $objects, $pointer, $plaintexts);
        }
    }

    /**
     * @param array<string, string> $objects
     * @param array<string, mixed>  $pointer
     * @param array<string, string> $plaintexts
     *
     * @return list<string>
     */
    private function forbiddenLeakMarkers(array $objects, array $pointer, array $plaintexts): array
    {
        $wrongKey = str_repeat(self::WRONG_KEY_BYTE, 32);
        $markers  = [
            self::FAKE_ENDPOINT,
            self::FAKE_BUCKET,
            self::FAKE_PREFIX,
            self::FAKE_ACCESS_KEY,
            self::FAKE_SECRET,
            base64_encode(self::FAKE_ACCESS_KEY),
            base64_encode(self::FAKE_SECRET),
            rawurlencode(self::FAKE_ENDPOINT),
            rawurlencode(self::FAKE_BUCKET),
            rawurlencode(self::FAKE_PREFIX),
            base64_encode($this->fixtureKey()),
            bin2hex($this->fixtureKey()),
            base64_encode($wrongKey),
            bin2hex($wrongKey),
            self::SQL_MARKER,
            self::CLINICAL_A,
            self::CLINICAL_B,
            self::NOTE_MARKER,
            $this->root,
            $this->clinicalRootA,
            $this->clinicalRootB,
            $this->sourceStore->basePath(),
            $this->operationStore->basePath(),
            $this->mirrorScratch,
        ];
        foreach (array_keys($plaintexts) as $relative) {
            $markers[] = (string) $relative;
        }
        $mirrorId = (string) ($pointer['mirror_id'] ?? '');
        foreach (array_keys($objects) as $objectId) {
            $objectKey = self::FAKE_BUCKET . '/' . self::FAKE_PREFIX . '/' . $mirrorId . '/' . (string) $objectId;
            $markers[] = $objectKey;
            $markers[] = rawurlencode($objectKey);
        }

        return $markers;
    }

    /**
     * Structural proof that the recovery operation cannot apply a restore: the
     * delivered class source contains no `restoreApply(` call site at all.
     */
    private function assertNoRestoreApplyCall(string $class): void
    {
        $file = (new \ReflectionClass($class))->getFileName();
        self::assertIsString($file);
        $tokens = token_get_all((string) file_get_contents($file));
        $calls  = 0;
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'restoreApply') {
                continue;
            }
            for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                $candidate = $tokens[$next];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ((is_array($candidate) ? $candidate[1] : $candidate) === '(') {
                    ++$calls;
                }
                break;
            }
        }

        self::assertSame(0, $calls, 'contract: the recovery operation has no destructive BackupService::restoreApply() call');
    }

    // ---------------------------------------------------------------------
    // Isolation / snapshot helpers (filesystem + read-only SQL only).
    // ---------------------------------------------------------------------

    private function stagePath(): string
    {
        return $this->root . '/recovery-stage-' . bin2hex(random_bytes(6));
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

    /** @return list<string> Opaque hashes for every authoritative active Clinic root. */
    private function activeClinicalStorageSnapshot(): array
    {
        $roots = $this->operationBackupService->activeClinicalStorageRoots();
        sort($roots, SORT_STRING);
        $snapshots = [];
        foreach ($roots as $root) {
            $snapshots[] = hash('sha256', serialize($this->storageTreeSnapshot($root)));
        }

        return $snapshots;
    }

    /** @return array<string, mixed> */
    private function storageTreeSnapshot(string $root): array
    {
        $rootReal  = realpath($root);
        $scanRoot  = is_string($rootReal) && is_dir($rootReal) ? $rootReal : null;
        $snapshot  = [
            'root_exists' => $scanRoot !== null,
            'root_mode'   => $scanRoot !== null ? (fileperms($scanRoot) & 0777) : null,
            'root_link'   => is_link($root) ? @readlink($root) : null,
            'directories' => [],
            'files'       => [],
            'links'       => [],
        ];
        if ($scanRoot === null) {
            return $snapshot;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scanRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            $path     = $entry->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen(rtrim($scanRoot, '/')) + 1));
            if ($entry->isLink()) {
                $target = @readlink($path);
                $snapshot['links'][$relative] = is_string($target) ? $target : null;
            } elseif ($entry->isDir()) {
                $snapshot['directories'][$relative] = [
                    'mode'  => fileperms($path) & 0777,
                    'mtime' => filemtime($path),
                ];
            } elseif ($entry->isFile()) {
                $snapshot['files'][$relative] = [
                    'mode'   => fileperms($path) & 0777,
                    'mtime'  => filemtime($path),
                    'size'   => filesize($path),
                    'sha256' => hash_file('sha256', $path),
                ];
            }
        }
        foreach (['directories', 'files', 'links'] as $key) {
            ksort($snapshot[$key]);
        }

        return $snapshot;
    }

    /** @return array<string, string> */
    private function databaseSnapshot(): array
    {
        global $wpdb;
        $snapshot = [];
        foreach ((array) $wpdb->get_col('SHOW TABLES') as $table) {
            $table     = (string) $table;
            $isCpms    = str_starts_with($table, $wpdb->prefix . 'cpms_');
            $isOptions = $table === $wpdb->options;
            if (!$isCpms && !$isOptions) {
                continue;
            }
            $safeTable = str_replace('`', '``', $table);
            $rows      = (array) $wpdb->get_results('SELECT * FROM `' . $safeTable . '`', ARRAY_A);
            usort($rows, static fn (array $left, array $right): int => strcmp(serialize($left), serialize($right)));
            $snapshot[$table] = hash('sha256', serialize($rows));
        }
        ksort($snapshot);

        return $snapshot;
    }

    /** @return array<string, int> */
    private function logWatermarks(): array
    {
        $db = App::db();

        return [
            'audit'       => (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM ' . $db->table('cpms_audit_logs')),
            'operational' => (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM ' . $db->table('cpms_operational_logs')),
        ];
    }

    /**
     * New audit/operational rows since the watermark, projected to the fields
     * this slice is allowed to reason about.
     *
     * @param array<string, int> $watermarks
     *
     * @return list<array<string, mixed>>
     */
    private function newLogRows(array $watermarks): array
    {
        $db   = App::db();
        $rows = [];
        foreach ($db->fetchAll('SELECT * FROM ' . $db->table('cpms_audit_logs') . ' WHERE id > %d', [$watermarks['audit']]) as $row) {
            $rows[] = [
                'label'   => (string) ($row['action'] ?? ''),
                'payload' => (string) ($row['meta_json'] ?? ''),
            ];
        }
        foreach ($db->fetchAll('SELECT * FROM ' . $db->table('cpms_operational_logs') . ' WHERE id > %d', [$watermarks['operational']]) as $row) {
            $rows[] = [
                'label'   => (string) ($row['message'] ?? ''),
                'level'   => (string) ($row['level'] ?? ''),
                'payload' => (string) ($row['context_json'] ?? ''),
            ];
        }

        return $rows;
    }

    private function decryptFixture(string $ciphertext, string $key, string $label): string
    {
        $dir = $this->root . '/fixture-crypto';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $safe   = preg_replace('/[^a-z0-9_-]/i', '-', $label);
        $cipher = $dir . '/' . $safe . '-' . bin2hex(random_bytes(4)) . '.enc';
        $plain  = $dir . '/' . $safe . '-' . bin2hex(random_bytes(4)) . '.plain';
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

    private function assertPathAbsent(string $path, string $message): void
    {
        self::assertFalse(file_exists($path) || is_link($path), $message);
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
