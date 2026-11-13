<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Infrastructure\Db\CpmsDb;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Integration coverage for the installation-wide CLI table count.
 */
final class CpmsDbTableCountTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    public function testCountUsesCurrentWordPressPrefixAndEscapesLikeWildcards(): void
    {
        global $wpdb;

        $original_prefix = $wpdb->prefix;
        $unique          = bin2hex( random_bytes( 6 ) );
        // Synthetic only: WordPress validates real prefixes more narrowly, but
        // this `wpdb->prefix` value exercises literal SQL LIKE `_` and `%` escapes.
        $synthetic_prefix = 'arena_' . $unique . '_%';
        $exact_table      = $synthetic_prefix . 'cpms_status_probe_' . $unique;
        // This would match `arena_<unique>_%cpms_%` if the literal `_` and `%`
        // in the synthetic current prefix were not escaped for SQL LIKE.
        $lookalike_table = 'arenaX' . $unique . 'YwildcardcpmsXstatus_probe_' . $unique;

        $this->withRealTables(
            static function () use ( $wpdb, $original_prefix, $synthetic_prefix, $exact_table, $lookalike_table ): void {
                $wpdb->prefix = $synthetic_prefix;

                try {
                    self::assertNotFalse(
                        $wpdb->query( 'CREATE TABLE `' . $exact_table . '` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB' )
                    );
                    self::assertNotFalse(
                        $wpdb->query( 'CREATE TABLE `' . $lookalike_table . '` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB' )
                    );

                    self::assertSame(
                        1,
                        ( new CpmsDb( $wpdb ) )->count_cpms_tables(),
                        'Only the table beginning with the literal current WordPress prefix and cpms_ stem should count.'
                    );
                } finally {
                    $wpdb->query( 'DROP TABLE IF EXISTS `' . $lookalike_table . '`' );
                    $wpdb->query( 'DROP TABLE IF EXISTS `' . $exact_table . '`' );
                    $wpdb->prefix = $original_prefix;
                }
            }
        );

        self::assertSame( $original_prefix, $wpdb->prefix, 'The synthetic WordPress prefix must not leak into other tests.' );
    }
}
