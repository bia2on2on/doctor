<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
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
}
