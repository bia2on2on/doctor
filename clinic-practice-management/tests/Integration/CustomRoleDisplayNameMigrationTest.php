<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Custom Role & Permission Management — Slice 2 migration safety
 * (`2026_10_11_0025_custom_role_display_name`).
 *
 * Contract:
 *  - additive only: it adds a NULL-able, utf8mb4 `display_name` column and
 *    changes nothing else (no data write, no seed, no destructive rebuild);
 *  - idempotent: a repeat migrate() is a no-op;
 *  - fail closed: a malformed pre-existing column fails the migration loudly,
 *    the version is NOT recorded and nothing is repaired or dropped;
 *  - existing definitions, their capability assignments and their technical
 *    keys survive every migrate()/rollback cycle unchanged;
 *  - down() removes only the column it introduced and up() restores it.
 */
final class CustomRoleDisplayNameMigrationTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private const VERSION = '2026_10_11_0025';
    private const PREVIOUS_VERSION = '2026_10_10_0024';
    /** Slice 3A adds the first-setup marker column after this migration. */
    private const HEAD_VERSION = '2026_10_13_0027';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
    }

    protected function tearDown(): void
    {
        try {
            $this->withRealTables(static fn (): array => App::migrations()->migrate());
        } finally {
            parent::tearDown();
        }
    }

    public function testColumnIsAddedNullAbleUtf8mb4AndMigrationIsIdempotent(): void
    {
        global $wpdb;
        $defs = App::db()->table('cpms_custom_role_defs');

        self::assertSame([], $this->withRealTables(static fn (): array => App::migrations()->migrate()), 'repeat migrate() must be a no-op');
        self::assertSame(self::HEAD_VERSION, App::migrations()->currentVersion(), 'later forward-only migrations advance the head');

        $column = $wpdb->get_row("SHOW FULL COLUMNS FROM {$defs} LIKE 'display_name'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertIsArray($column, 'display_name column must exist');
        self::assertSame('varchar(190)', strtolower((string) $column['Type']));
        self::assertSame('YES', strtoupper((string) $column['Null']), 'the column must be NULL-able for pre-existing definitions');
        self::assertIsString($column['Collation'] ?? null, 'the column must have an explicit collation');
        self::assertTrue(
            str_starts_with(strtolower((string) $column['Collation']), 'utf8mb4_'),
            'the column must be utf8mb4 so any Unicode display name can be stored'
        );
    }

    public function testExistingDefinitionsCapabilitiesAndKeysSurviveReMigration(): void
    {
        global $wpdb;
        $defs = App::db()->table('cpms_custom_role_defs');
        $caps = App::db()->table('cpms_custom_role_capabilities');

        $this->withRealTables(function () use ($wpdb, $defs, $caps): void {
            $now = App::db()->nowUtcSql();
            $ok  = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$defs} (clinic_id, role_key, display_name, status, version, created_at, updated_at) VALUES (1, 'dname_survive', %s, 'active', 3, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'نام نمایشی — Unicode 受付',
                $now,
                $now
            ));
            self::assertNotFalse($ok, 'definition fixture must insert');
            $defId = (int) $wpdb->insert_id;
            self::assertGreaterThan(0, $defId);

            $ok = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$caps} (role_def_id, capability, created_at) VALUES (%d, 'cpms_patient_read', %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $defId,
                $now
            ));
            self::assertNotFalse($ok, 'capability fixture must insert');

            // Re-migrate twice: nothing may change.
            App::migrations()->migrate();
            App::migrations()->migrate();

            $row = $wpdb->get_row($wpdb->prepare("SELECT role_key, display_name, status, version FROM {$defs} WHERE id = %d", $defId), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertIsArray($row);
            self::assertSame('dname_survive', (string) $row['role_key'], 'the technical key must never be rewritten');
            self::assertSame('نام نمایشی — Unicode 受付', (string) $row['display_name'], 'the Unicode display name must round-trip');
            self::assertSame('active', (string) $row['status']);
            self::assertSame(3, (int) $row['version'], 'the durable version must be untouched');

            $stored = $wpdb->get_col($wpdb->prepare("SELECT capability FROM {$caps} WHERE role_def_id = %d ORDER BY capability", $defId)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertSame(['cpms_patient_read'], array_values(array_map('strval', (array) $stored)), 'capability assignments must be preserved');
        });
    }

    public function testDownDropsOnlyTheDisplayNameColumnAndUpRestoresIt(): void
    {
        global $wpdb;
        $defs = App::db()->table('cpms_custom_role_defs');

        $this->withRealTables(function () use ($wpdb, $defs): void {
            $rolledHead = App::migrations()->rollbackOne();
            self::assertSame(self::HEAD_VERSION, $rolledHead, 'later migrations must roll back before the display-name migration');
            self::assertSame('2026_10_11_0026', App::migrations()->rollbackOne());
            $rolled = App::migrations()->rollbackOne();
            self::assertSame(self::VERSION, $rolled, 'the display-name migration must roll back before 0024');
            self::assertSame(self::PREVIOUS_VERSION, App::migrations()->currentVersion());

            $gone = $wpdb->get_row("SHOW COLUMNS FROM {$defs} LIKE 'display_name'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertNull($gone, 'down() must drop the display_name column');

            $still = $wpdb->get_row("SHOW COLUMNS FROM {$defs} LIKE 'role_key'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertIsArray($still, 'down() must not touch the immutable technical key column');

            $applied = App::migrations()->migrate();
            self::assertContains(self::VERSION, $applied, 'up() must re-add the column');
            $again = $wpdb->get_row("SHOW COLUMNS FROM {$defs} LIKE 'display_name'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertIsArray($again, 'display_name must be present again after migrate()');
        });
    }

    public function testMalformedDisplayNameColumnFailsLoudlyWithoutRecordingVersion(): void
    {
        global $wpdb;
        $defs = App::db()->table('cpms_custom_role_defs');

        try {
            $this->withRealTables(function () use ($wpdb, $defs): void {
                self::assertSame(self::HEAD_VERSION, App::migrations()->rollbackOne());
                self::assertSame('2026_10_11_0026', App::migrations()->rollbackOne());
                self::assertSame(self::VERSION, App::migrations()->rollbackOne());
                self::assertSame(self::PREVIOUS_VERSION, App::migrations()->currentVersion());

                // Malformed pre-existing column: wrong length and NOT NULL.
                $ok = $wpdb->query("ALTER TABLE {$defs} ADD COLUMN display_name VARCHAR(50) NOT NULL DEFAULT ''"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse($ok, 'malformed column fixture must be created');

                $failed = false;
                $message = '';
                try {
                    App::migrations()->migrate();
                } catch (\RuntimeException $e) {
                    $failed  = true;
                    $message = $e->getMessage();
                }

                self::assertTrue($failed, 'a malformed display_name column must fail the migration loudly');
                self::assertStringContainsString('migration 0025', $message, 'the failure must name the violated migration contract');

                // The malformed column and its rows stay untouched (no repair).
                $column = $wpdb->get_row("SHOW COLUMNS FROM {$defs} LIKE 'display_name'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertIsArray($column);
                self::assertSame('varchar(50)', strtolower((string) $column['Type']), 'the malformed column must not be repaired');
            });

            self::assertSame(
                self::PREVIOUS_VERSION,
                App::migrations()->currentVersion(),
                'the failed migration must never be recorded'
            );
        } finally {
            // Deterministic cleanup: drop the malformed column and re-migrate cleanly.
            $this->withRealTables(function () use ($wpdb, $defs): void {
                $wpdb->query("ALTER TABLE {$defs} DROP COLUMN display_name"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                App::migrations()->migrate();
            });
        }

        self::assertSame(self::HEAD_VERSION, App::migrations()->currentVersion(), 'suite schema must be restored');
        $column = $wpdb->get_row("SHOW COLUMNS FROM {$defs} LIKE 'display_name'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertIsArray($column, 'the well-formed column must be restored after cleanup');
        self::assertSame('YES', strtoupper((string) $column['Null']));
    }
}
