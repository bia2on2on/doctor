<?php
/**
 * Phase 15 — Slice 1: REPAIR REGRESSION — exclusive temporary-file creation.
 *
 * ACCEPTED BLOCKER (class A): the envelope's temporary sibling output used to be
 * opened with non-exclusive `fopen(..., 'wb')`, so a collision or race on that
 * path could truncate/overwrite a pre-existing file. The repair creates the
 * temporary output EXCLUSIVELY (`fopen(..., 'xb')` ⇒ `O_CREAT|O_EXCL`) with a
 * small bounded retry, so a colliding path is never truncated, never replaced
 * and never unlinked by this process; when no candidate can be created the
 * operation fails closed and the destination/source stay untouched.
 *
 * These tests are deterministic, not probabilistic probability games:
 *  - the temporary-naming contract is pinned reflectively (first candidate name
 *    and the bounded attempt constant are private constants), then the exact
 *    first-candidate path is CREATED as a foreign file. The first exclusive open
 *    must therefore fail on that exact path — no randomness is involved;
 *  - the retry-space-unavailable case is reproduced deterministically by making
 *    the destination directory non-writable while the foreign candidate exists,
 *    so every further exclusive create fails at the filesystem level and the
 *    operation must fail closed. If exclusivity were removed, the non-exclusive
 *    open would instead truncate the foreign file and these assertions fail;
 *  - directory non-writability is impossible when the test process runs as root,
 *    so the runtime precondition is pinned in its own test and the
 *    permission-dependent case skips with an explicit reason instead of
 *    pretending to be deterministic.
 *
 * No production seam, interface, or dependency-injection framework is
 * introduced: repository convention for deterministic internals checks is
 * reflection (21 existing test files), so the private constants are read that
 * way. The accepted RED suite (Phase15BackupEncryptionEnvelopeRedTest) and its
 * security contract are not modified or weakened by this file.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Infrastructure\Backup\BackupException;
use WP_UnitTestCase;

final class Phase15BackupEncryptionEnvelopeCollisionTest extends WP_UnitTestCase
{
    /** The repaired production primitive. */
    private const ENVELOPE = 'ClinicCore\\Infrastructure\\Backup\\BackupEncryptionEnvelope';

    private const ENCRYPT_FAILED = 'CLINIC_BACKUP_ENCRYPTION_FAILED';
    private const DECRYPT_FAILED = 'CLINIC_BACKUP_DECRYPTION_FAILED';

    /** Fixed framing of format v1 for the size assertions below. */
    private const ENVELOPE_OVERHEAD = 8 + 24;
    private const TAG_BYTES = 17;

    /** Disposable private directory for this test only. */
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cpms-envelope-collision-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir, 0700, true), 'fixture: disposable private test directory is creatable');
    }

    protected function tearDown(): void
    {
        // Restore traversal even if a contract test failed while the directory
        // was deliberately made non-writable.
        @chmod($this->dir, 0700);
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    // ========================================================================
    // DETERMINISTIC COLLISION — the exact first candidate is occupied.
    // ========================================================================

    public function testEncryptCollisionRetriesSafelyWithoutTouchingTheForeignFile(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $plaintext = str_repeat('CPMS15-COLLISION-ENCRYPT-', 16); // 384 bytes
        $source = $this->writeBytes('collision-encrypt.bin', $plaintext);
        $sourceHash = $this->fileSha256($source);
        $destination = $this->path('collision-encrypt.bin.cpmsenc');

        // Occupy the EXACT first temporary candidate with a foreign file.
        $foreignPath = $this->firstCandidatePath($destination);
        $foreignBytes = 'FOREIGN-COLLIDING-TEMP-BYTES-DO-NOT-TOUCH';
        $foreign = $this->writeBytes(basename($foreignPath), $foreignBytes);
        $foreignHash = $this->fileSha256($foreign);

        $class::encryptFile($source, $destination, $key);

        self::assertSame(
            $foreignHash,
            $this->fileSha256($foreign),
            'a colliding existing candidate path must never be truncated, rewritten, or removed'
        );
        self::assertSame($foreignBytes, (string) file_get_contents($foreign), 'the foreign file keeps every original byte');
        self::assertFileExists($destination, 'the collision must be resolved by a safe retry, publishing the destination');
        self::assertSame(
            strlen($plaintext) + self::ENVELOPE_OVERHEAD + self::TAG_BYTES,
            (int) filesize($destination),
            'the published destination is a complete single-chunk format v1 envelope'
        );
        self::assertSame($sourceHash, $this->fileSha256($source), 'the source stays byte-identical');

        // Proven by a real decrypt, not by a size guess.
        $decrypted = $this->path('collision-encrypt.bin.out');
        $class::decryptFile($destination, $decrypted, $key);
        self::assertSame($plaintext, (string) file_get_contents($decrypted), 'the retried rename published the full authenticated output');

        $expectedEntries = ['collision-encrypt.bin', 'collision-encrypt.bin.cpmsenc', 'collision-encrypt.bin.out', basename($foreignPath)];
        sort($expectedEntries);
        self::assertSame(
            $expectedEntries,
            $this->dirEntries(),
            'no process-owned temporary or partial artifact may remain after success'
        );
    }

    public function testDecryptCollisionRetriesSafelyWithoutTouchingTheForeignFile(): void
    {
        $class = $this->envelopeClass();
        $key = $this->key();
        $plaintext = str_repeat('CPMS15-COLLISION-DECRYPT-', 16); // 384 bytes
        $source = $this->writeBytes('collision-decrypt.bin', $plaintext);
        $sourceHash = $this->fileSha256($source);
        $encrypted = $this->path('collision-decrypt.bin.cpmsenc');
        $class::encryptFile($source, $encrypted, $key);

        $destination = $this->path('collision-decrypt.bin.out');

        // Occupy the EXACT first temporary candidate of the DECRYPT destination.
        $foreignPath = $this->firstCandidatePath($destination);
        $foreignBytes = 'FOREIGN-DECRYPT-COLLIDING-TEMP-BYTES';
        $foreign = $this->writeBytes(basename($foreignPath), $foreignBytes);
        $foreignHash = $this->fileSha256($foreign);
        $encryptedHash = $this->fileSha256($encrypted);

        $class::decryptFile($encrypted, $destination, $key);

        self::assertSame($foreignHash, $this->fileSha256($foreign), 'the occupied decrypt candidate is never truncated or removed');
        self::assertSame($foreignBytes, (string) file_get_contents($foreign), 'the foreign file keeps every original byte');
        self::assertFileExists($destination, 'the collision must be resolved by a safe retry, publishing the destination');
        self::assertSame($plaintext, (string) file_get_contents($destination), 'the retried rename published the full plaintext');
        self::assertSame($encryptedHash, $this->fileSha256($encrypted), 'the encrypted source is never mutated');
        self::assertSame($sourceHash, $this->fileSha256($source), 'the original plaintext source is never mutated');

        $expectedEntries = ['collision-decrypt.bin', 'collision-decrypt.bin.cpmsenc', 'collision-decrypt.bin.out', basename($foreignPath)];
        sort($expectedEntries);
        self::assertSame(
            $expectedEntries,
            $this->dirEntries(),
            'no process-owned temporary or partial artifact may remain after success'
        );
    }

    // ========================================================================
    // RETRY SPACE UNAVAILABLE — must fail closed, never truncate, never unlink.
    // ========================================================================

    public function testUnavailableRetrySpaceFailsClosedWithDestinationAndForeignFileUntouched(): void
    {
        if (!$this->canProduceUnwritableDirectory()) {
            self::markTestSkipped(
                'this runtime does not honour POSIX directory permissions for the test process (e.g. root); '
                . 'the retry-space-unavailable case cannot be reproduced deterministically here'
            );
        }

        $class = $this->envelopeClass();
        $key = $this->key();
        $plaintextMarker = 'CPMS15-COLLISION-FAIL-CLOSED-MARKER';
        $source = $this->writeBytes('collision-fail.bin', $plaintextMarker . str_repeat('x', 256));
        $sourceHash = $this->fileSha256($source);

        $destination = $this->path('collision-fail.bin.cpmsenc');
        $stale = 'PRE-EXISTING-DESTINATION-CONTENT';
        self::assertNotFalse(file_put_contents($destination, $stale), 'fixture: stale destination is written');

        // Occupy the exact first candidate, then remove the retry space itself.
        $foreignPath = $this->firstCandidatePath($destination);
        $foreignBytes = 'FOREIGN-FAIL-CLOSED-COLLIDING-TEMP';
        $foreign = $this->writeBytes(basename($foreignPath), $foreignBytes);
        $foreignHash = $this->fileSha256($foreign);

        $entriesBefore = $this->dirEntries();

        self::assertTrue(chmod($this->dir, 0500), 'fixture: destination directory permissions are applied');
        clearstatcache(true, $this->dir);
        if (is_writable($this->dir)) {
            chmod($this->dir, 0700);
            clearstatcache(true, $this->dir);
            self::markTestSkipped('directory permissions are not enforced for this process; cannot reproduce retry-space exhaustion deterministically');
        }

        $caught = null;
        try {
            $class::encryptFile($source, $destination, $key);
        } catch (BackupException $e) {
            $caught = $e;
        } catch (\Throwable $e) {
            self::fail('fail-closed contract: expected ' . BackupException::class . ', got ' . get_class($e) . ' — ' . $e->getMessage());
        } finally {
            @chmod($this->dir, 0700);
            clearstatcache(true, $this->dir);
        }

        self::assertInstanceOf(BackupException::class, $caught, 'exhausted exclusive-create attempts must fail closed');
        self::assertSame(self::ENCRYPT_FAILED, $caught->getErrorCode(), 'the error code identifies the failed operation, never the failure cause');
        self::assertSame($foreignHash, $this->fileSha256($foreign), 'the foreign colliding file is unchanged after a failed operation');
        self::assertSame($foreignBytes, (string) file_get_contents($foreign), 'the foreign file keeps every original byte');
        self::assertSame($stale, (string) file_get_contents($destination), 'the pre-existing destination stays byte-identical');
        self::assertSame($sourceHash, $this->fileSha256($source), 'the source stays byte-identical');
        self::assertSame($entriesBefore, $this->dirEntries(), 'no process-owned temporary or partial artifact may remain after failure');

        $this->assertMessageRevealsNoSecrets($caught, $key, $plaintextMarker);
    }

    // ========================================================================
    // CONTRACT PIN — deterministic seam + runtime precondition.
    // ========================================================================

    public function testTemporaryCandidateContractIsASiblingWithBoundedAttempts(): void
    {
        $destination = $this->path('whatever.bin.cpmsenc');
        $candidate = $this->firstCandidatePath($destination);

        self::assertStringStartsWith(
            $destination,
            $candidate,
            'the first temporary candidate must be derived from the destination path itself'
        );
        self::assertSame(
            dirname($destination),
            dirname($candidate),
            'the temporary candidate must be a sibling in the destination directory (same filesystem ⇒ atomic rename)'
        );
        self::assertNotSame($destination, $candidate, 'the destination file itself is never used as the temporary path');
        self::assertGreaterThanOrEqual(2, $this->attemptBound(), 'at least the deterministic candidate plus one retry must be attempted');
        self::assertLessThanOrEqual(32, $this->attemptBound(), 'the retry loop must stay small and bounded');

        // Runtime precondition for the deterministic retry-space test above.
        if (function_exists('posix_geteuid')) {
            self::assertNotSame(0, posix_geteuid(), 'the CI runtime must not run the suite as root (directory permissions are the deterministic collision mechanism)');
        }
        self::assertTrue($this->canProduceUnwritableDirectory(), 'the CI runtime must honour POSIX directory permissions so the collision tests are deterministic');
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function envelopeClass(): string
    {
        self::assertTrue(class_exists(self::ENVELOPE), 'the repaired envelope primitive must exist');
        self::assertTrue(is_callable([self::ENVELOPE, 'encryptFile']), 'contract: public static function encryptFile(string $sourcePath, string $destinationPath, string $key): void');
        self::assertTrue(is_callable([self::ENVELOPE, 'decryptFile']), 'contract: public static function decryptFile(string $sourcePath, string $destinationPath, string $key): void');

        return self::ENVELOPE;
    }

    /** The EXACT first temporary candidate path the repair tries (private constant, pinned reflectively). */
    private function firstCandidatePath(string $destination): string
    {
        $suffix = (new \ReflectionClass(self::ENVELOPE))->getConstant('TEMP_SUFFIX');
        self::assertIsString($suffix, 'contract: the private temporary-name suffix constant must exist');
        self::assertNotSame('', $suffix, 'contract: the temporary-name suffix must not be empty');

        return $destination . $suffix;
    }

    /** The bounded number of exclusive-create attempts (private constant, pinned reflectively). */
    private function attemptBound(): int
    {
        $bound = (new \ReflectionClass(self::ENVELOPE))->getConstant('TEMP_CREATE_ATTEMPTS');
        self::assertIsInt($bound, 'contract: the private bounded-attempt constant must exist');

        return $bound;
    }

    /**
     * True only when directory permissions actually deny this process — false
     * under root or permission-oblivious filesystems. Probe is self-cleaning.
     */
    private function canProduceUnwritableDirectory(): bool
    {
        $probe = $this->dir . '/probe-' . bin2hex(random_bytes(4));
        if (!@mkdir($probe, 0700)) {
            return false;
        }

        @chmod($probe, 0500);
        clearstatcache(true, $probe);
        $unwritable = !is_writable($probe);
        $refused = false === @fopen($probe . '/candidate', 'xb');
        @chmod($probe, 0700);
        clearstatcache(true, $probe);
        @rmdir($probe);

        return $unwritable && $refused;
    }

    private function key(): string
    {
        return random_bytes(32);
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

    private function fileSha256(string $path): string
    {
        $hash = hash_file('sha256', $path);
        self::assertIsString($hash, 'fixture: file hash is computable for ' . $path);

        return $hash;
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
