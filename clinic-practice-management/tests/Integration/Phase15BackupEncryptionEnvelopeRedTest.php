<?php
/**
 * Phase 15 — Backup & Recovery, Slice 1: FILE ENCRYPTION ENVELOPE (TEST-ONLY RED).
 *
 * ============================================================================
 * AUTHORIZED SCOPE (Product Owner direction, deliberately narrow)
 * ============================================================================
 *
 * Phase 15 direction approved by the Product Owner (future, NOT this slice):
 * a remote S3-compatible destination, backup data encrypted before it leaves
 * the server, and S3 credentials / encryption-key material kept outside the
 * CPMS database and outside this repository.
 *
 * THIS SLICE builds ONLY the reusable file-encryption envelope primitive:
 * a private plaintext file is streamed into a versioned, authenticated
 * encrypted file, and that encrypted file can be authenticated and decrypted
 * back into a private destination.
 *
 * EXPLICITLY OUT OF SCOPE — and therefore NOT expressed, referenced, stubbed
 * or pre-wired anywhere in this file:
 *   S3/object-storage transport, any fake or real remote-store abstraction,
 *   provider interfaces, S3/AWS SDKs, credentials, settings, InstallationSettings,
 *   environment-variable readers, admin UI, new capabilities/roles, migrations,
 *   remote backup job changes, restore changes, password/KDF material, Phase 20.
 *
 * ============================================================================
 * LIVE STATE AT RED AUTHORING (independently verified on 2026-10-02, BEFORE writes)
 * ============================================================================
 *   - authoritative main = 3f5c05fe1eedab8b6be0d2131b21cc45b4a0f7e0
 *     (branch arena/01a0fbc8-doctor branched from it; working tree clean,
 *      tracked + untracked = none)
 *   - open PRs = 0 (no active Phase 15 PR exists)
 *   - Phase 15 roadmap marker = `| **Phase 15** | **Backup & Recovery** | ⏳ |`
 *     (Phase 15 work not started on main)
 *   - latest migration on disk = 2026_09_26_0023_handwriting_prescription_paper.php
 *     (no 0024 exists; none is created, reserved or referenced by this RED)
 *   - PHP support policy = PHP >= 8.1 (composer.json) with CI matrix 8.1/8.2/8.3/8.4
 *   - sodium secretstream availability in the supported runtime: ext/sodium is a
 *     shipped extension of PHP >= 8.1 and is proven PRESENT in this repository's
 *     CI runtime by the gating closure probes `ok('sodium-available', ...)` +
 *     `sodium_crypto_sign_keypair()` in `.github/workflows/closure-gate.yml`
 *     (those probes `exit(1)` on failure and are terminal-success on main for
 *     PHP 8.1/8.2/8.3/8.4 with the same shivammathur/setup-php action used by
 *     this suite). The runtime control test below makes that precondition
 *     explicit instead of implicit.
 *
 * ============================================================================
 * INTENDED PRODUCT CONTRACT ESTABLISHED BY THIS FILE
 * ============================================================================
 *
 *   Class: ClinicCore\Infrastructure\Backup\BackupEncryptionEnvelope
 *          final, static; same namespace and `CLINIC_BACKUP_*` error convention
 *          as the already-delivered BackupException / ProtectedBackupStore /
 *          BackupSqlDumper. No interface, no provider/transport abstraction.
 *
 *   public const MAGIC = 'CPMSBK01';
 *       Exactly 8 ASCII bytes at offset 0 of every encrypted file:
 *       'CPMS' + 'BK' (backup-keyed envelope) + '01' (format version 1).
 *       The WHOLE marker is authoritative: a wrong magic AND a recognized magic
 *       carrying an unsupported version are both rejected.
 *
 *   public const CHUNK_BYTES = 1048576;
 *       Format v1 processes exactly 1 MiB of plaintext per authenticated chunk.
 *       It is a format constant (not tuning metadata): the decryptor must use it
 *       to parse the same version, so it is part of the versioned contract.
 *
 *   public static function encryptFile(string $sourcePath, string $destinationPath, string $key): void;
 *   public static function decryptFile(string $sourcePath, string $destinationPath, string $key): void;
 *       $key is caller-supplied, ALREADY-VALID 32-byte binary key material.
 *       This slice has no password, no KDF, no key id, no key persistence and no
 *       key-identifier metadata: those are future decisions and are not pre-wired.
 *
 *   ENVELOPE FORMAT v1
 *     offset 0      8 bytes  magic "CPMSBK01"
 *     offset 8     24 bytes  sodium secretstream XChaCha20-Poly1305 header
 *                            (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES)
 *     offset 32        ...   authenticated chunk stream; each chunk is
 *                            (up to CHUNK_BYTES plaintext) + 17-byte Poly1305 tag
 *                            (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES).
 *                            Every chunk except the last is pushed with TAG_MESSAGE;
 *                            the LAST chunk is pushed with TAG_FINAL, so the final
 *                            authenticated tag is always present and the file ends
 *                            immediately after it (trailing bytes are rejected).
 *                            An empty plaintext still yields ONE authenticated final
 *                            chunk of 17 bytes — the format makes the empty file
 *                            round-trip safely rather than special-casing it.
 *     total size = 32 + plaintext_size + 17 * max(1, ceil(plaintext_size / CHUNK_BYTES))
 *
 *     Only what is required to parse/decrypt safely is stored: the magic/version
 *     marker and the sodium header. No filename, timestamp, checksum, key id,
 *     client metadata, compression or S3/remote fields are introduced.
 *
 *   FAILURE MODEL (fail closed, with no distinguishing oracle)
 *     - every encrypt-side failure  -> BackupException code CLINIC_BACKUP_ENCRYPTION_FAILED
 *     - every decrypt-side failure  -> BackupException code CLINIC_BACKUP_DECRYPTION_FAILED
 *       Wrong key, header tamper, ciphertext tamper, truncation, missing final tag,
 *       trailing bytes, unsupported magic/version and invalid key length are all
 *       intentionally INDISTINGUISHABLE to the caller.
 *     - a failed call never leaves a partial destination behind: if the destination
 *       did not exist it still does not exist; if it pre-existed it stays
 *       byte-identical. No temporary/partial artifact is left in the directory.
 *     - a failed call never deletes or mutates its SOURCE file (the same holds for
 *       every successful call).
 *     - no caller-visible error (message, code, data) may contain key material or
 *       plaintext content.
 *
 * ============================================================================
 * WHY THIS IS A VALID RED (intended failing assertions)
 * ============================================================================
 *
 * The runtime control test is GREEN today (ext/sodium + secretstream exist in the
 * supported runtime). Every other test first resolves the intended primitive
 * through `envelopeClass()`, which asserts existence of the class, of both static
 * entry points, and of the two format constants. On this head the class does not
 * exist, so each of those tests fails with an explicit INTENDED PRODUCT RED
 * assertion message — an assertion failure that names the missing contract, not a
 * missing-class fatal, and not a harness/bootstrap defect. No production code,
 * dependency, migration, setting or workflow change accompanies this file.
 *
 * The tests below are written as the eventual GREEN acceptance suite for this
 * primitive: test bootstrap succeeds, the existing backup suites stay reachable
 * and untouched, and no assertion depends on files outside the disposable private
 * directory created per test under the system temp directory.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Infrastructure\Backup\BackupException;
use WP_UnitTestCase;

final class Phase15BackupEncryptionEnvelopeRedTest extends WP_UnitTestCase
{
    /** Intended production primitive — absent on this head (INTENDED PRODUCT RED). */
    private const ENVELOPE = 'ClinicCore\\Infrastructure\\Backup\\BackupEncryptionEnvelope';

    /** Format v1 marker: 'CPMS' + 'BK' + format version '01'. */
    private const MAGIC = 'CPMSBK01';

    /** Format v1 plaintext bytes per authenticated chunk (1 MiB). */
    private const CHUNK_BYTES = 1048576;

    /** Sodium secretstream XChaCha20-Poly1305 header bytes. */
    private const HEADER_BYTES = 24;

    /** Sodium secretstream XChaCha20-Poly1305 per-chunk tag bytes. */
    private const TAG_BYTES = 17;

    /** Fixed per-file framing: 8-byte magic + 24-byte sodium header. */
    private const ENVELOPE_OVERHEAD = 8 + self::HEADER_BYTES;

    private const ENCRYPT_FAILED = 'CLINIC_BACKUP_ENCRYPTION_FAILED';
    private const DECRYPT_FAILED = 'CLINIC_BACKUP_DECRYPTION_FAILED';

    private const RED_MESSAGE = 'INTENDED PRODUCT RED — Phase 15 Slice 1: the CPMS backup file-encryption envelope '
        . 'primitive ClinicCore\Infrastructure\Backup\BackupEncryptionEnvelope does not exist on this head, so the '
        . 'approved streaming authenticated-encryption contract (versioned magic + sodium secretstream XChaCha20-'
        . 'Poly1305 header + bounded 1 MiB chunks + authenticated final tag) is missing.';

    /** Disposable private directory for this test only. */
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cpms-envelope-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir, 0700, true), 'fixture: disposable private test directory is creatable');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    // ========================================================================
    // CONTROL — the supported runtime must provide the primitive this slice is
    // built on (GREEN today; it never fails for the intended RED reason).
    // ========================================================================

    public function testSupportedRuntimeProvidesSodiumSecretstreamXChaCha20Poly1305(): void
    {
        self::assertTrue(extension_loaded('sodium'), 'the supported CPMS runtime must ship ext/sodium (bundled and enabled by default since PHP 7.2; policy here is PHP >= 8.1)');

        foreach (
            [
                'sodium_crypto_secretstream_xchacha20poly1305_keygen',
                'sodium_crypto_secretstream_xchacha20poly1305_init_push',
                'sodium_crypto_secretstream_xchacha20poly1305_push',
                'sodium_crypto_secretstream_xchacha20poly1305_init_pull',
                'sodium_crypto_secretstream_xchacha20poly1305_pull',
            ] as $function
        ) {
            self::assertTrue(function_exists($function), 'missing sodium secretstream primitive: ' . $function);
        }

        self::assertSame(24, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES, 'XChaCha20-Poly1305 secretstream header size');
        self::assertSame(17, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES, 'XChaCha20-Poly1305 secretstream per-chunk tag size');
        self::assertSame(32, strlen(sodium_crypto_secretstream_xchacha20poly1305_keygen()), 'secretstream keys are 32 bytes of binary key material');
    }

    // ========================================================================
    // ROUND TRIPS — small binary, multi-chunk, empty
    // ========================================================================

    public function testSmallBinaryFileRoundTripsAndItsSourceIsNeverMutated(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $plaintext = $this->binaryString(4096);
        $source = $this->writeBytes('small.bin', $plaintext);
        $sourceHash = $this->fileSha256($source);
        $encrypted = $this->path('small.bin.cpmsenc');
        $decrypted = $this->path('small.bin.out');

        $class::encryptFile($source, $encrypted, $key);

        self::assertFileExists($encrypted, 'encryptFile must produce the encrypted destination');
        self::assertSame(self::MAGIC, substr((string) file_get_contents($encrypted), 0, 8), 'the encrypted file must start with the explicit CPMS magic/version marker');
        self::assertSame(4096 + self::ENVELOPE_OVERHEAD + self::TAG_BYTES, (int) filesize($encrypted), 'single-chunk framing: 8-byte magic + 24-byte header + plaintext + 17-byte authenticated tag');
        self::assertSame($sourceHash, $this->fileSha256($source), 'encryptFile must not mutate (or delete) its plaintext source');

        $encryptedHash = $this->fileSha256($encrypted);

        $class::decryptFile($encrypted, $decrypted, $key);

        self::assertFileExists($decrypted, 'decryptFile must produce the plaintext destination');
        self::assertSame($plaintext, (string) file_get_contents($decrypted), 'the round trip reproduces every byte of the binary fixture');
        self::assertSame($sourceHash, $this->fileSha256($source), 'decryptFile must not mutate (or delete) the original plaintext source');
        self::assertSame($encryptedHash, $this->fileSha256($encrypted), 'decryptFile must not mutate (or delete) its encrypted source');
        self::assertSame(
            ['small.bin', 'small.bin.cpmsenc', 'small.bin.out'],
            $this->dirEntries(),
            'no partial or temporary artifact may remain in the destination directory'
        );
    }

    public function testMultipleChunkFileRoundTripsWithExactAuthenticatedChunkFraming(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $size = 2 * self::CHUNK_BYTES + 12345;
        $source = $this->writeStreamedFixture('multi.bin', $size);
        $sourceHash = $this->fileSha256($source);
        $encrypted = $this->path('multi.bin.cpmsenc');
        $decrypted = $this->path('multi.bin.out');

        $class::encryptFile($source, $encrypted, $key);

        self::assertSame(
            $size + self::ENVELOPE_OVERHEAD + 3 * self::TAG_BYTES,
            (int) filesize($encrypted),
            'bounded chunking: a 2 MiB+ fixture must be framed as 3 authenticated chunks (2 full + 1 tail), not one whole-file blob'
        );

        $class::decryptFile($encrypted, $decrypted, $key);

        self::assertSame($sourceHash, $this->fileSha256($decrypted), 'the multi-chunk round trip is byte-exact');
        self::assertSame($sourceHash, $this->fileSha256($source), 'the multi-chunk source stays byte-identical');
    }

    public function testEmptyPlaintextRoundTripsAsMagicHeaderPlusAuthenticatedFinalTag(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $source = $this->writeBytes('empty.bin', '');
        $encrypted = $this->path('empty.bin.cpmsenc');
        $decrypted = $this->path('empty.bin.out');

        $class::encryptFile($source, $encrypted, $key);

        self::assertSame(
            self::ENVELOPE_OVERHEAD + self::TAG_BYTES,
            (int) filesize($encrypted),
            'an empty plaintext still produces exactly one authenticated final chunk (format permits the empty file safely)'
        );

        $class::decryptFile($encrypted, $decrypted, $key);

        self::assertFileExists($decrypted, 'the empty plaintext destination must exist');
        self::assertSame('', (string) file_get_contents($decrypted), 'the empty plaintext round-trips exactly');
    }

    // ========================================================================
    // FAIL-CLOSED AUTHENTICATION — wrong key, tampering, truncation, framing
    // ========================================================================

    public function testWrongKeyFailsClosedAndNeverLeavesASuccessfulLookingDestination(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $wrongKey = $this->otherKey($key);
        $source = $this->writeBinaryFixture('wrong-key.bin', 2048);
        $encrypted = $this->path('wrong-key.bin.cpmsenc');
        $decrypted = $this->path('wrong-key.bin.out');

        $class::encryptFile($source, $encrypted, $key);

        // A stale file left over from an earlier attempt must never be mistaken
        // for a successful plaintext result of this call.
        $stale = 'stale-previous-destination-content';
        self::assertNotFalse(file_put_contents($decrypted, $stale), 'fixture: stale destination is written');

        $error = $this->assertFailsClosed(
            fn () => $class::decryptFile($encrypted, $decrypted, $wrongKey),
            self::DECRYPT_FAILED,
            $decrypted,
            $stale
        );

        $this->assertMessageRevealsNoSecrets($error, $wrongKey, $stale);
    }

    public function testSingleByteCiphertextTamperFailsClosed(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $marker = 'PHASE15-TAMPER-BODY-MARKER';
        $source = $this->writeBytes('tamper-body.bin', $marker . $this->binaryString(1024));
        $encrypted = $this->path('tamper-body.bin.cpmsenc');
        $tampered = $this->path('tamper-body.bin.flipped');
        $decrypted = $this->path('tamper-body.bin.out');

        $class::encryptFile($source, $encrypted, $key);

        $bytes = (string) file_get_contents($encrypted);
        $offset = self::ENVELOPE_OVERHEAD + 1000; // inside the authenticated ciphertext payload
        $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);
        self::assertNotFalse(file_put_contents($tampered, $bytes), 'fixture: tampered copy is written');

        $error = $this->assertFailsClosed(
            fn () => $class::decryptFile($tampered, $decrypted, $key),
            self::DECRYPT_FAILED,
            $decrypted
        );

        $this->assertMessageRevealsNoSecrets($error, $key, $marker);
    }

    public function testHeaderTamperFailsClosed(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $source = $this->writeBinaryFixture('tamper-header.bin', 4096);
        $encrypted = $this->path('tamper-header.bin.cpmsenc');
        $tampered = $this->path('tamper-header.bin.flipped');
        $decrypted = $this->path('tamper-header.bin.out');

        $class::encryptFile($source, $encrypted, $key);

        $bytes = (string) file_get_contents($encrypted);
        $offset = 8 + 3; // inside the 24-byte sodium secretstream header, after a still-valid magic
        $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x80);
        self::assertNotFalse(file_put_contents($tampered, $bytes), 'fixture: tampered copy is written');

        $this->assertFailsClosed(
            fn () => $class::decryptFile($tampered, $decrypted, $key),
            self::DECRYPT_FAILED,
            $decrypted
        );
    }

    public function testTruncatedStreamAndMissingFinalTagFailClosed(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $tail = 5000;
        $source = $this->writeStreamedFixture('truncate.bin', self::CHUNK_BYTES + $tail);
        $encrypted = $this->path('truncate.bin.cpmsenc');

        $class::encryptFile($source, $encrypted, $key);
        $bytes = (string) file_get_contents($encrypted);
        self::assertSame((self::CHUNK_BYTES + $tail) + self::ENVELOPE_OVERHEAD + 2 * self::TAG_BYTES, strlen($bytes), 'fixture: two authenticated chunks were produced');

        // (a) the whole final authenticated chunk is gone -> no TAG_FINAL was ever seen
        $missingFinalChunk = substr($bytes, 0, strlen($bytes) - ($tail + self::TAG_BYTES));
        $caseA = $this->writeBytes('truncate.bin.no-final-chunk', $missingFinalChunk);

        // (b) the final authenticated tag is cut in half -> truncated tag
        $oneByteShort = substr($bytes, 0, -1);
        $caseB = $this->writeBytes('truncate.bin.one-byte-short', $oneByteShort);

        // (c) nothing but magic + header -> no authenticated content at all
        $headerOnly = substr($bytes, 0, self::ENVELOPE_OVERHEAD);
        $caseC = $this->writeBytes('truncate.bin.header-only', $headerOnly);

        $destinationA = $this->path('truncate.bin.a.out');
        $destinationB = $this->path('truncate.bin.b.out');
        $destinationC = $this->path('truncate.bin.c.out');

        $this->assertFailsClosed(
            fn () => $class::decryptFile($caseA, $destinationA, $key),
            self::DECRYPT_FAILED,
            $destinationA
        );
        $this->assertFailsClosed(
            fn () => $class::decryptFile($caseB, $destinationB, $key),
            self::DECRYPT_FAILED,
            $destinationB
        );
        $this->assertFailsClosed(
            fn () => $class::decryptFile($caseC, $destinationC, $key),
            self::DECRYPT_FAILED,
            $destinationC
        );
    }

    public function testTrailingBytesAfterTheAuthenticatedFinalChunkFailClosed(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $source = $this->writeBinaryFixture('trailing.bin', 4096);
        $encrypted = $this->path('trailing.bin.cpmsenc');
        $extended = $this->path('trailing.bin.extended');
        $decrypted = $this->path('trailing.bin.out');

        $class::encryptFile($source, $encrypted, $key);
        $extendedBytes = (string) file_get_contents($encrypted) . "\x00APPENDED";
        self::assertNotFalse(file_put_contents($extended, $extendedBytes), 'fixture: appended-bytes copy is written');

        $this->assertFailsClosed(
            fn () => $class::decryptFile($extended, $decrypted, $key),
            self::DECRYPT_FAILED,
            $decrypted
        );
    }

    public function testUnsupportedMagicFailsClosed(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $bytes = $this->encryptedFixture($class, $key, 'magic.bin', 4096, 'magic.bin.cpmsenc');
        $bytes[0] = 'X'; // 'XPMSBK01' — wrong magic
        $tampered = $this->writeBytes('magic.bin.wrong-magic', $bytes);
        $decrypted = $this->path('magic.bin.out');

        $this->assertFailsClosed(
            fn () => $class::decryptFile($tampered, $decrypted, $key),
            self::DECRYPT_FAILED,
            $decrypted
        );
    }

    public function testUnsupportedVersionFailsClosed(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $bytes = $this->encryptedFixture($class, $key, 'version.bin', 4096, 'version.bin.cpmsenc');
        $bytes[7] = '2'; // 'CPMSBK02' — recognized magic, unsupported format version
        $tampered = $this->writeBytes('version.bin.unsupported', $bytes);
        $decrypted = $this->path('version.bin.out');

        $this->assertFailsClosed(
            fn () => $class::decryptFile($tampered, $decrypted, $key),
            self::DECRYPT_FAILED,
            $decrypted
        );
    }

    // ========================================================================
    // KEY MATERIAL HYGIENE + ERROR TEXT
    // ========================================================================

    public function testInvalidKeyMaterialLengthFailsClosedWithoutWritingADestination(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $source = $this->writeBinaryFixture('bad-key.bin', 1024);
        $encrypted = $this->path('bad-key.bin.cpmsenc');
        $decrypted = $this->path('bad-key.bin.out');

        foreach ([substr($key, 0, 31), $key . 'X', ''] as $invalidKey) {
            $this->assertFailsClosed(
                fn () => $class::encryptFile($source, $encrypted, $invalidKey),
                self::ENCRYPT_FAILED,
                $encrypted
            );
        }

        $class::encryptFile($source, $encrypted, $key);

        $this->assertFailsClosed(
            fn () => $class::decryptFile($encrypted, $decrypted, substr($key, 0, 31)),
            self::DECRYPT_FAILED,
            $decrypted
        );
    }

    public function testCallerVisibleErrorsRevealNoKeyMaterialAndNoPlaintext(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $wrongKey = $this->otherKey($key);
        $plaintextMarker = 'PHASE15-PLAINTEXT-MARKER-DO-NOT-LEAK';
        $source = $this->writeBytes('secret.bin', $plaintextMarker . str_repeat('x', 512));
        $encrypted = $this->path('secret.bin.cpmsenc');
        $tampered = $this->path('secret.bin.flipped');
        $decrypted = $this->path('secret.bin.out');

        $class::encryptFile($source, $encrypted, $key);

        $wrongKeyError = $this->assertFailsClosed(
            fn () => $class::decryptFile($encrypted, $decrypted, $wrongKey),
            self::DECRYPT_FAILED,
            $decrypted
        );
        $this->assertMessageRevealsNoSecrets($wrongKeyError, $wrongKey, $plaintextMarker);

        $bytes = (string) file_get_contents($encrypted);
        $bytes[self::ENVELOPE_OVERHEAD + 16] = chr(ord($bytes[self::ENVELOPE_OVERHEAD + 16]) ^ 0x40);
        self::assertNotFalse(file_put_contents($tampered, $bytes), 'fixture: tampered copy is written');

        $tamperError = $this->assertFailsClosed(
            fn () => $class::decryptFile($tampered, $decrypted, $key),
            self::DECRYPT_FAILED,
            $decrypted
        );
        $this->assertMessageRevealsNoSecrets($tamperError, $key, $plaintextMarker);

        self::assertSame(
            $plaintextMarker . str_repeat('x', 512),
            (string) file_get_contents($source),
            'the source plaintext file is never mutated, including across failures'
        );
    }

    // ========================================================================
    // BOUNDED-MEMORY / STREAMING BEHAVIOUR
    // ========================================================================

    public function testLargeMultiChunkFixtureIsProcessedWithBoundedMemory(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $chunks = 12;
        $size = $chunks * self::CHUNK_BYTES + 4321;
        $source = $this->writeStreamedFixture('large.bin', $size);
        $encrypted = $this->path('large.bin.cpmsenc');
        $decrypted = $this->path('large.bin.out');

        gc_collect_cycles();
        $peakBefore = memory_get_peak_usage(true);
        $class::encryptFile($source, $encrypted, $key);
        $class::decryptFile($encrypted, $decrypted, $key);
        $peakDelta = memory_get_peak_usage(true) - $peakBefore;

        self::assertSame(
            $size + self::ENVELOPE_OVERHEAD + ($chunks + 1) * self::TAG_BYTES,
            (int) filesize($encrypted),
            'a 12 MiB+ fixture must be framed as 13 authenticated chunks (chunked processing, not a single whole-file record)'
        );
        self::assertSame($this->fileSha256($source), $this->fileSha256($decrypted), 'the large fixture round-trips byte-exact');
        self::assertLessThanOrEqual(
            8 * 1024 * 1024,
            $peakDelta,
            sprintf(
                'streaming must stay bounded: a whole-file implementation would add at least %d bytes to the process peak for this fixture (observed peak delta: %d bytes)',
                $size,
                $peakDelta
            )
        );
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Resolve the intended primitive through the contract itself, so that the
     * RED failure is an assertion naming the missing API — never a fatal error.
     */
    private function envelopeClass(): string
    {
        self::assertTrue(class_exists(self::ENVELOPE), self::RED_MESSAGE);
        self::assertTrue(
            is_callable([self::ENVELOPE, 'encryptFile']),
            'contract: public static function encryptFile(string $sourcePath, string $destinationPath, string $key): void'
        );
        self::assertTrue(
            is_callable([self::ENVELOPE, 'decryptFile']),
            'contract: public static function decryptFile(string $sourcePath, string $destinationPath, string $key): void'
        );
        self::assertTrue(defined(self::ENVELOPE . '::MAGIC'), 'contract: the envelope format marker is exposed as ' . self::ENVELOPE . '::MAGIC');
        self::assertSame(self::MAGIC, constant(self::ENVELOPE . '::MAGIC'), 'contract: format v1 marker is exactly "CPMSBK01"');
        self::assertTrue(defined(self::ENVELOPE . '::CHUNK_BYTES'), 'contract: the fixed v1 plaintext chunk size is exposed as ' . self::ENVELOPE . '::CHUNK_BYTES');
        self::assertSame(self::CHUNK_BYTES, constant(self::ENVELOPE . '::CHUNK_BYTES'), 'contract: format v1 authenticates 1 MiB of plaintext per chunk');

        return self::ENVELOPE;
    }

    private function key(): string
    {
        return random_bytes(32);
    }

    private function otherKey(string $key): string
    {
        $other = $this->key();
        while (hash_equals($key, $other)) {
            $other = $this->key();
        }

        return $other;
    }

    private function path(string $name): string
    {
        return $this->dir . '/' . $name;
    }

    private function writeBytes(string $name, string $bytes): string
    {
        $path = $this->path($name);
        self::assertNotFalse(file_put_contents($path, $bytes), 'fixture: bytes are written to ' . $name);

        return $path;
    }

    private function writeBinaryFixture(string $name, int $size): string
    {
        return $this->writeBytes($name, $this->binaryString($size));
    }

    /** Deterministic binary fixture containing every byte value in sequence. */
    private function binaryString(int $size): string
    {
        $block = '';
        for ($i = 0; $i < 256; $i++) {
            $block .= chr($i);
        }

        return substr(str_repeat($block, intdiv($size, 256) + 1), 0, $size);
    }

    /** Writes a large fixture without building it in memory (bounded writes). */
    private function writeStreamedFixture(string $name, int $size): string
    {
        $path = $this->path($name);
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle, 'fixture: large disposable fixture is writable');

        $block = str_pad('', self::CHUNK_BYTES, 'CPMS15-ENVELOPE-');
        $written = 0;
        while ($written < $size) {
            $take = min(strlen($block), $size - $written);
            $chunk = $take === strlen($block) ? $block : substr($block, 0, $take);
            $result = fwrite($handle, $chunk);
            self::assertSame($take, $result, 'fixture: streamed fixture write is complete');
            $written += $take;
        }
        fclose($handle);

        return $path;
    }

    /** Encrypts a fixture and returns the raw encrypted bytes. */
    private function encryptedFixture(string $class, string $key, string $fixtureName, int $size, string $encryptedName): string
    {
        $source = $this->writeBinaryFixture($fixtureName, $size);
        $encrypted = $this->path($encryptedName);
        $class::encryptFile($source, $encrypted, $key);

        return (string) file_get_contents($encrypted);
    }

    private function fileSha256(string $path): string
    {
        $hash = hash_file('sha256', $path);
        self::assertIsString($hash, 'fixture: file hash is computable for ' . $path);

        return $hash;
    }

    /**
     * Asserts the complete fail-closed contract for one failing operation:
     * typed BackupException, operation-specific code, no leftover destination or
     * partial artifact, and (when applicable) an untouched pre-existing file.
     */
    private function assertFailsClosed(
        callable $operation,
        string $expectedCode,
        string $destination,
        ?string $destinationMustRemain = null
    ): BackupException {
        $entriesBefore = $this->dirEntries();
        $caught = null;
        try {
            $operation();
        } catch (BackupException $e) {
            $caught = $e;
        } catch (\Throwable $e) {
            self::fail('fail-closed contract: expected ' . BackupException::class . ', got ' . get_class($e) . ' — ' . $e->getMessage());
        }

        self::assertInstanceOf(BackupException::class, $caught, 'the operation must fail closed with a typed backup error');
        self::assertSame($expectedCode, $caught->getErrorCode(), 'the error code identifies the failed operation, never the failure cause');
        self::assertSame($entriesBefore, $this->dirEntries(), 'a failed call must not leave partial or temporary artifacts behind');

        if ($destinationMustRemain === null) {
            self::assertFileDoesNotExist($destination, 'a failed call must never leave a destination file behind');
        } else {
            self::assertFileExists($destination, 'a failed call must leave a pre-existing destination in place');
            self::assertSame($destinationMustRemain, (string) file_get_contents($destination), 'a failed call must leave a pre-existing destination byte-identical');
        }

        return $caught;
    }

    private function assertMessageRevealsNoSecrets(BackupException $error, string $key, string $plaintextMarker): void
    {
        $visible = $error->getMessage() . ' | ' . $error->getErrorCode() . ' | ' . (string) json_encode($error->data);

        self::assertStringNotContainsString($key, $visible, 'raw key material must never appear in a caller-visible error');
        self::assertStringNotContainsString(bin2hex($key), $visible, 'hex-encoded key material must never appear in a caller-visible error');
        self::assertStringNotContainsString(base64_encode($key), $visible, 'base64-encoded key material must never appear in a caller-visible error');
        self::assertStringNotContainsString($plaintextMarker, $visible, 'plaintext content must never appear in a caller-visible error');
    }

    /** Sorted names of the files currently present in the disposable directory. */
    private function dirEntries(): array
    {
        $entries = scandir($this->dir);
        self::assertIsArray($entries, 'fixture: the disposable directory is listable');
        $entries = array_values(array_diff($entries, ['.', '..']));
        sort($entries);

        return $entries;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
