<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use RuntimeException;
use WP_UnitTestCase;

/** Database contract regression tests for failed writes. */
final class CpmsDbUpdateFailureTest extends WP_UnitTestCase
{
    public function test_failed_wpdb_update_throws_instead_of_becoming_zero_rows(): void
    {
        try {
            App::db()->update(
                'cpms_missing_update_contract_table',
                ['status' => 'active'],
                ['id' => 1],
                true
            );
            self::fail('A failed wpdb update must not be reported as a zero-row success.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Database update failed', $exception->getMessage());
        }
    }
}
