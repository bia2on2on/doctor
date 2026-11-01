<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
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
    private const HEAD_VERSION = '2026_10_31_0031';

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
                'نام نمایشی — Unicode 受付 🧑‍⚕️',
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
            self::assertSame('نام نمایشی — Unicode 受付 🧑‍⚕️', (string) $row['display_name'], 'the Unicode display name must round-trip');
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
            self::assertSame('2026_10_27_0030', App::migrations()->rollbackOne(), 'the messaging/pricing migration must roll back before the display-name migration');
            self::assertSame('2026_10_18_0029', App::migrations()->rollbackOne(), 'the Clinic-lifecycle migration must roll back before the display-name migration');
            self::assertSame('2026_10_18_0028', App::migrations()->rollbackOne(), 'the Organization-membership migration must roll back before the display-name migration');
            self::assertSame('2026_10_13_0027', App::migrations()->rollbackOne(), 'the archival migration must roll back before the display-name migration');
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

        // Archived rows must survive this test and its cleanup untouched.
        $archivedBefore = $this->withRealTables(fn (): int => $this->archivedDefinitionCount());
        $bodyFailure    = null;

        try {
            $this->withRealTables(function () use ($wpdb, $defs): void {
                self::assertSame(self::HEAD_VERSION, App::migrations()->rollbackOne());
                self::assertSame('2026_10_27_0030', App::migrations()->rollbackOne());
                self::assertSame('2026_10_18_0029', App::migrations()->rollbackOne());
                self::assertSame('2026_10_18_0028', App::migrations()->rollbackOne());
                self::assertSame('2026_10_13_0027', App::migrations()->rollbackOne(), 'the archival migration must roll back before the display-name migration');
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
        } catch (\Throwable $failure) {
            // Keep the original failure: the verified restoration below must never mask it.
            $bodyFailure = $failure;
            throw $failure;
        } finally {
            try {
                $this->withRealTables(function () use ($defs, $archivedBefore): void {
                    $this->restoreReviewedDisplayNameColumn($defs, $archivedBefore);
                });
            } catch (\Throwable $cleanupFailure) {
                if ($bodyFailure !== null) {
                    throw new \RuntimeException(
                        'display_name restoration failed after the original test failure: ' . $cleanupFailure->getMessage(),
                        0,
                        $bodyFailure
                    );
                }

                throw $cleanupFailure;
            }
        }

        self::assertSame(self::HEAD_VERSION, App::migrations()->currentVersion(), 'suite schema must be restored');
        $column = $this->displayNameColumn();
        self::assertIsArray($column, 'the well-formed column must be restored after cleanup');
        self::assertTrue(
            $this->displayNameColumnMatchesContract($column),
            'the reviewed display_name contract must hold after cleanup'
        );
    }

    /**
     * Shared-schema restoration for the malformed-column fixture — verified, never assumed.
     *
     * Why migrate() alone cannot be trusted here: migration 0027's guarded downgrade may
     * refuse the very first rollback (committed archived/unknown rows), so the balanced
     * 0027 → 0026 → 0025 down cycle never runs and 0025 stays recorded as applied. The
     * runner skips recorded versions, so a dropped column is never re-created by a later
     * migrate(). MySQL DDL also implicitly commits, so restoration is deterministic DDL
     * against the real table instead of a rollback-dependent transaction.
     */
    private function restoreReviewedDisplayNameColumn(string $defs, int $archivedBefore): void
    {
        global $wpdb;

        if (App::migrations()->currentVersion() === self::PREVIOUS_VERSION) {
            // The down cycle completed: whatever display_name is present now (the malformed
            // fixture or older debris) is not owned by the recorded state — remove it and let
            // the official 0025 up() re-create the reviewed column and its version marker.
            if ($this->displayNameColumn() !== null) {
                $wpdb->query("ALTER TABLE {$defs} DROP COLUMN display_name"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
            App::migrations()->migrate();
        } else {
            // The downgrade was refused (or stopped early): migrate() realigns the version
            // markers, but the column itself must be repaired explicitly — 0025 is already
            // recorded, so its up() will not run again.
            App::migrations()->migrate();

            $column = $this->displayNameColumn();
            if ($column === null) {
                $wpdb->query(
                    "ALTER TABLE {$defs} ADD COLUMN `display_name` VARCHAR(190) NULL DEFAULT NULL AFTER `role_key`"
                ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            } elseif (! $this->displayNameColumnMatchesContract($column)) {
                // Fixture debris (VARCHAR(50) NOT NULL): relax and widen in place so stored
                // values survive; the reviewed contract is a NULL-able utf8mb4 VARCHAR(190).
                $wpdb->query(
                    "ALTER TABLE {$defs} MODIFY COLUMN `display_name` VARCHAR(190) NULL DEFAULT NULL"
                ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
        }

        // Restoration is asserted, not inferred: a still-broken shared schema fails loudly.
        self::assertSame(self::HEAD_VERSION, App::migrations()->currentVersion(), 'migration markers must be restored to the suite head');

        $column = $this->displayNameColumn();
        self::assertIsArray($column, 'display_name must exist after restoration');
        self::assertTrue($this->displayNameColumnMatchesContract($column), 'display_name must match the reviewed migration 0025 contract');

        // One real product read path that selects display_name must resolve again.
        $clinicId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_clinics') . ' ORDER BY id LIMIT 1'
        );
        if ($clinicId > 0) {
            self::assertIsArray(
                (new CustomRoleRepository(App::db()))->list_definitions($clinicId),
                'custom-role reads must resolve display_name after restoration'
            );
        }

        // Archived definitions are preserved: this cleanup issues no row DML at all.
        self::assertSame($archivedBefore, $this->archivedDefinitionCount(), 'archived rows must survive the fixture cleanup');
    }

    /**
     * @param array<string, mixed> $column information_schema.COLUMNS row
     */
    private function displayNameColumnMatchesContract(array $column): bool
    {
        return strtolower((string) ($column['DATA_TYPE'] ?? '')) === 'varchar'
            && (int) ($column['CHARACTER_MAXIMUM_LENGTH'] ?? 0) === 190
            && strtoupper((string) ($column['IS_NULLABLE'] ?? '')) === 'YES'
            && strtolower((string) ($column['CHARACTER_SET_NAME'] ?? '')) === 'utf8mb4';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function displayNameColumn(): ?array
    {
        return App::db()->fetchRow(
            'SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [App::db()->table('cpms_custom_role_defs'), 'display_name']
        );
    }

    private function archivedDefinitionCount(): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_custom_role_defs') . " WHERE status = 'archived'"
        );
    }
}
