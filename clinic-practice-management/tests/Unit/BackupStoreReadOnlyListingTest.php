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
            ob_start();
            try {
                $store->createDir('backup-20261024');
                self::fail('private storage must fail closed');
            } catch (BackupException $e) {
                self::assertSame('CLINIC_BACKUP_IO', $e->errorCode);
            } finally {
                $output = (string) ob_get_clean();
            }
            self::assertSame('', $output, 'filesystem warnings must not appear in HTTP output');
            self::assertFalse(is_dir($parent . '/private-backups'));
        } finally {
            unlink($parent);
        }
    }
}
