<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Infrastructure\Backup\BackupException;
use PHPUnit\Framework\TestCase;

final class BackupStoreReadOnlyListingTest extends TestCase
{
    public function testMissingPrivateDirectoryIsNotCreatedByListing(): void
    {
        $root = sys_get_temp_dir() . '/cpms-backup-list-' . bin2hex(random_bytes(8));
        self::assertFalse(file_exists($root));
        $store = ProtectedBackupStore::active($root);
        self::assertSame([], $store->listIds());
        self::assertFalse(file_exists($root));
    }

    public function testUncreatablePrivateRootFailsClosedWithoutEmittingWarnings(): void
    {
        $parent = tempnam(sys_get_temp_dir(), 'cpms-backup-file-');
        self::assertIsString($parent);
        try {
            $store = ProtectedBackupStore::active($parent . '/private-backups');
            $warnings = [];
            set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;
                return true;
            });
            try {
                $store->createDir('backup-20261024');
                self::fail('private storage must fail closed');
            } catch (BackupException $e) {
                self::assertSame('CLINIC_BACKUP_IO', $e->errorCode);
            } finally {
                restore_error_handler();
            }
            self::assertSame([], $warnings, 'filesystem warnings must not leak into HTTP output');
            self::assertFalse(is_dir($parent . '/private-backups'));
        } finally {
            unlink($parent);
        }
    }
}
