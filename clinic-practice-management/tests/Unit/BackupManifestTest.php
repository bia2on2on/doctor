<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Backup\BackupManifest;
use PHPUnit\Framework\TestCase;

/**
 * F10 — مانیفست بکاپ (spec §22–§24): اعتبارسنجی ساختار + تمامیت فایل‌ها.
 */
final class BackupManifestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validManifest(): array
    {
        return [
            'schema_version' => 1,
            'engine' => 'cpms-backup',
            'engine_version' => '1.0.0',
            'backup_id' => str_repeat('ab', 16),
            'created_at' => '2026-09-06T10:00:00+00:00',
            'note' => '',
            'db' => [
                'file' => 'db.sql',
                'sha256' => str_repeat('0', 64),
                'tables' => [
                    ['name' => 'cpms_patients', 'rows' => 10],
                ],
            ],
            'storage' => [
                'root' => 'storage',
                'files' => [
                    ['path' => '1/a3/' . str_repeat('a', 32) . '.pdf', 'size' => 100, 'sha256' => str_repeat('1', 64)],
                ],
                'count' => 1,
                'bytes' => 100,
            ],
            'meta' => ['wp_version' => '6.7', 'php_version' => '8.2', 'cpms_version' => '1.0.0'],
        ];
    }

    public function testValidManifestPasses(): void
    {
        $this->assertTrue(BackupManifest::isValid($this->validManifest()));
        $this->assertSame([], BackupManifest::validate($this->validManifest()));
    }

    public function testRejectsUnsupportedSchema(): void
    {
        $m = $this->validManifest();
        $m['schema_version'] = 2;
        $this->assertFalse(BackupManifest::isValid($m));
    }

    public function testRejectsWrongEngine(): void
    {
        $m = $this->validManifest();
        $m['engine'] = 'other-backup';
        $this->assertFalse(BackupManifest::isValid($m));
    }

    public function testRejectsBadBackupId(): void
    {
        $m = $this->validManifest();
        $m['backup_id'] = '../../etc/passwd';
        $this->assertFalse(BackupManifest::isValid($m));
    }

    public function testRejectsBadDbSha(): void
    {
        $m = $this->validManifest();
        $m['db']['sha256'] = 'xyz';
        $this->assertFalse(BackupManifest::isValid($m));
    }

    public function testRejectsStorageCountMismatch(): void
    {
        $m = $this->validManifest();
        $m['storage']['count'] = 5; // دروغ — لیست فقط ۱ فایل دارد
        $this->assertFalse(BackupManifest::isValid($m));
    }

    public function testVerifyFilesOk(): void
    {
        $m = $this->validManifest();
        $result = BackupManifest::verifyFiles($m, function (string $rel): ?array {
            if ($rel === 'db.sql') {
                return ['size' => 50, 'sha256' => str_repeat('0', 64)];
            }
            if ($rel === 'storage/1/a3/' . str_repeat('a', 32) . '.pdf') {
                return ['size' => 100, 'sha256' => str_repeat('1', 64)];
            }

            return null;
        });
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
    }

    public function testVerifyFilesDetectsMissingFile(): void
    {
        $m = $this->validManifest();
        $result = BackupManifest::verifyFiles($m, static fn (string $rel): ?array => null);
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testVerifyFilesDetectsTamperedHash(): void
    {
        $m = $this->validManifest();
        $result = BackupManifest::verifyFiles($m, function (string $rel): ?array {
            if ($rel === 'db.sql') {
                return ['size' => 50, 'sha256' => str_repeat('f', 64)]; // دستکاری
            }

            return null;
        });
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('hash mismatch db.sql', implode('; ', $result['errors']));
    }
}
