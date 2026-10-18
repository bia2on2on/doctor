<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Custom Role & Permission Management — Slice 1 (TEST-ONLY RED): migration and
 * schema-safety contracts for the Clinic-local custom-role tables.
 *
 * Contracts (items 11 + 12 of the focused security matrix):
 *  - Migration 2026_10_10_0024 is forward-only, idempotent and creates the
 *    versioned custom-role persistence tables.
 *  - It preserves every existing membership row (no membership migration or
 *    rewrite of role_key values).
 *  - It creates no professional identity, no patient identity and no duplicate
 *    identity of any kind (migration is schema-only, without seeds).
 */
final class CustomRoleMigrationSafetyTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    /** The custom-role persistence migration (Slice 1). */
    private const NEW_VERSION = '2026_10_10_0024';

    /**
     * Slice 2 adds a forward-only follow-up migration (display_name column) and
     * Slice 3A adds the first-setup marker column, so the 0024 tables are no
     * longer the head of the migration chain. The custom-role schema safety
     * assertions below still target 0024 explicitly; "head" assertions target
     * this version.
     */
    private const HEAD_VERSION = '2026_10_18_0029';

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

    public function testCustomRoleTablesExistAfterIdempotentMigrate(): void
    {
        global $wpdb;

        $appliedFirst  = $this->withRealTables(static fn (): array => App::migrations()->migrate());
        $appliedSecond = $this->withRealTables(static fn (): array => App::migrations()->migrate());

        self::assertSame([], $appliedSecond, 'second migrate() must be a no-op (idempotent)');
        $applied_versions = implode(',', App::migrations()->applied());
        self::assertStringContainsString(self::NEW_VERSION, $applied_versions, 'migration 2026_10_10_0024 must be applied');
        self::assertStringContainsString(self::HEAD_VERSION, $applied_versions, 'migration 2026_10_18_0029 must be applied');
        self::assertSame(self::HEAD_VERSION, App::migrations()->currentVersion(), 'the custom-role migrations must be the current head');

        foreach (['cpms_custom_role_defs', 'cpms_custom_role_capabilities'] as $short) {
            $table = App::db()->table($short);
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertSame($table, $found, "missing table: {$short}");
        }

        // The unique constraints the authorization contract relies on must exist.
        $defs = App::db()->table('cpms_custom_role_defs');
        $idx  = $wpdb->get_results("SHOW INDEX FROM {$defs} WHERE Key_name = 'u_custom_role'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertCount(2, (array) $idx, 'u_custom_role must span (clinic_id, role_key)');

        self::assertIsArray($appliedFirst);
    }

    public function testMigrationPreservesExistingMemberships(): void
    {
        global $wpdb;
        $memberships = App::db()->table('cpms_clinic_memberships');

        $countBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$memberships}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rowsBefore  = $wpdb->get_results("SELECT id, clinic_id, wp_user_id, role_key, status FROM {$memberships} ORDER BY id"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        $this->withRealTables(static fn (): array => App::migrations()->migrate());

        $countAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$memberships}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rowsAfter  = $wpdb->get_results("SELECT id, clinic_id, wp_user_id, role_key, status FROM {$memberships} ORDER BY id"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        self::assertSame($countBefore, $countAfter, 'membership count must be preserved');
        self::assertEquals($rowsBefore, $rowsAfter, 'membership rows must be preserved unchanged (no role_key migration)');
    }

    public function testMigrationCreatesNoProfessionalOrPatientIdentity(): void
    {
        global $wpdb;

        $count = static function (): array {
            global $wpdb;
            return [
                'clinicians'  => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinicians'), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'identities'  => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_patient_identities'), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'ident_links' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_patient_identity_links'), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'wp_users'    => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'users'), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ];
        };

        $before = $count();
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        $after = $count();

        self::assertSame($before, $after, 'migration must create no clinician/patient identity and no WordPress user');
    }

    // ------------------------------------------------------------------
    // BLOCKER 3 — schema safety of migration 2026_10_10_0024: a malformed
    // pre-existing same-name table must fail loudly and must never be
    // marked migrated; existing rows and malformed tables stay untouched.
    // ------------------------------------------------------------------

    private const PREVIOUS_VERSION = '2026_09_26_0023';

    /**
     * Normal fresh migration (create-after-rollback) succeeds and BOTH
     * unique constraints and BOTH foreign keys exist and enforce their
     * contracts at the engine level (real MySQL INSERT probes).
     */
    public function testFreshMigrationEnforcesUniqueConstraintsAndForeignKeys(): void
    {
        global $wpdb;
        $defs = App::db()->table('cpms_custom_role_defs');
        $caps = App::db()->table('cpms_custom_role_capabilities');

        $this->withRealTables(function () use ($wpdb, $defs, $caps): void {
            try {
                ( function (): void {
                    App::migrations()->migrate();
                    while ( App::migrations()->currentVersion() !== self::PREVIOUS_VERSION ) {
                        self::assertNotNull( App::migrations()->rollbackOne(), 'the schema must be rollable back to 0023' );
                    }
                } )();
                self::assertSame(self::PREVIOUS_VERSION, App::migrations()->currentVersion(), 'version must sit at 0023 before the fresh migration');

                $applied = App::migrations()->migrate();
                self::assertContains(self::NEW_VERSION, $applied, 'normal fresh migration must apply 0024');
                self::assertContains(self::HEAD_VERSION, $applied, 'normal fresh migration must apply the display-name migration too');
                self::assertSame(self::HEAD_VERSION, App::migrations()->currentVersion());

                // Repeat migration is safe (idempotent no-op).
                self::assertSame([], App::migrations()->migrate(), 'repeat migration must be a no-op');

                $now = App::db()->nowUtcSql();
                $ok  = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$defs} (clinic_id, role_key, status, version, created_at, updated_at) VALUES (1, 'enf_probe', 'active', 1, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $now,
                    $now
                ));
                self::assertNotFalse($ok, 'seed definition insert must succeed');
                $def_id = (int) $wpdb->insert_id;

                // UNIQUE (clinic_id, role_key) — enforced.
                $dup = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$defs} (clinic_id, role_key, status, version, created_at, updated_at) VALUES (1, 'enf_probe', 'active', 1, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $now,
                    $now
                ));
                self::assertFalse((bool) $dup, 'UNIQUE (clinic_id, role_key) must reject duplicates');

                // UNIQUE (role_def_id, capability) — enforced.
                $ok = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$caps} (role_def_id, capability, created_at) VALUES (%d, 'cpms_patient_read', %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $def_id,
                    $now
                ));
                self::assertNotFalse($ok, 'seed capability insert must succeed');
                $dup_cap = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$caps} (role_def_id, capability, created_at) VALUES (%d, 'cpms_patient_read', %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $def_id,
                    $now
                ));
                self::assertFalse((bool) $dup_cap, 'UNIQUE (role_def_id, capability) must reject duplicates');

                // FK integrity — enforced.
                $orphan_def = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$defs} (clinic_id, role_key, status, version, created_at, updated_at) VALUES (999999, 'enf_orphan', 'active', 1, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $now,
                    $now
                ));
                self::assertFalse((bool) $orphan_def, 'fk_customrole_clinic must reject a definition for a non-existent Clinic');
                $orphan_cap = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$caps} (role_def_id, capability, created_at) VALUES (999999, 'cpms_patient_read', %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $now
                ));
                self::assertFalse((bool) $orphan_cap, 'fk_customrolecap_def must reject a capability of a non-existent definition');

                // Persisted state after enforcement probes.
                self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$defs}"), 'exactly the valid definition must remain'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$caps}"), 'exactly the valid capability must remain'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            } finally {
                // Deterministic cleanup: fresh empty tables at 0024 again.
                $wpdb->query("DELETE FROM {$wpdb->prefix}cpms_schema_migrations WHERE version IN ('" . self::NEW_VERSION . "', '" . self::HEAD_VERSION . "')"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query("DROP TABLE IF EXISTS {$caps}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query("DROP TABLE IF EXISTS {$defs}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                App::migrations()->migrate();
            }
        });

        self::assertSame(self::HEAD_VERSION, App::migrations()->currentVersion());
    }

    /**
     * A malformed pre-existing cpms_custom_role_defs table must fail the
     * migration loudly: 0024 is NOT recorded (version stays at 0023), and
     * the malformed table and its rows remain untouched.
     */
    public function testMalformedExistingDefsTableFailsMigrationWithoutRecordingVersion(): void
    {
        global $wpdb;
        $defs = App::db()->table('cpms_custom_role_defs');

        try {
            $this->withRealTables(function () use ($wpdb, $defs): void {
                // Back to 0023: real custom-role tables gone, 0024 unrecorded.
                ( function (): void {
                    App::migrations()->migrate();
                    while ( App::migrations()->currentVersion() !== self::PREVIOUS_VERSION ) {
                        self::assertNotNull( App::migrations()->rollbackOne(), 'the schema must be rollable back to 0023' );
                    }
                } )();
                self::assertSame(self::PREVIOUS_VERSION, App::migrations()->currentVersion());

                // Malformed same-name table with a marker row that must survive.
                $ok = $wpdb->query("CREATE TABLE {$defs} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=InnoDB"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse($ok, 'malformed table fixture must be created');
                $ok = $wpdb->query("INSERT INTO {$defs} (id) VALUES (1)"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse($ok, 'marker row must be inserted');

                $migrate_failed = false;
                $error_message  = '';
                try {
                    App::migrations()->migrate();
                } catch (\RuntimeException $e) {
                    $migrate_failed = true;
                    $error_message  = $e->getMessage();
                }
                self::assertTrue($migrate_failed, 'migrate() must fail loudly on a malformed pre-existing defs table');
                self::assertStringContainsString('migration 0024', $error_message, 'the failure must clearly name the schema contract violation');
            });

            // Assertions against persisted state (outside the DDL window):
            self::assertSame(
                self::PREVIOUS_VERSION,
                App::migrations()->currentVersion(),
                'migration version must stay at 0023 — 0024 must never be recorded after the failure'
            );
            $cols = array_map('strval', (array) $wpdb->get_col("SHOW COLUMNS FROM {$defs}")); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertSame(['id'], $cols, 'the malformed table must remain untouched');
            self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$defs}"), 'existing rows must remain unchanged'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } finally {
            $this->restoreFreshSchema();
        }

        self::assertSame(self::HEAD_VERSION, App::migrations()->currentVersion(), 'suite schema must be restored after the test');
    }

    /**
     * A malformed pre-existing cpms_custom_role_capabilities table (missing
     * its unique constraint and foreign key) must fail the migration loudly:
     * 0024 is NOT recorded and the malformed table stays untouched.
     */
    public function testMalformedExistingCapsTableFailsMigrationWithoutRecordingVersion(): void
    {
        global $wpdb;
        $defs = App::db()->table('cpms_custom_role_defs');
        $caps = App::db()->table('cpms_custom_role_capabilities');

        try {
            $this->withRealTables(function () use ($wpdb, $defs, $caps): void {
                ( function (): void {
                    App::migrations()->migrate();
                    while ( App::migrations()->currentVersion() !== self::PREVIOUS_VERSION ) {
                        self::assertNotNull( App::migrations()->rollbackOne(), 'the schema must be rollable back to 0023' );
                    }
                } )();
                self::assertSame(self::PREVIOUS_VERSION, App::migrations()->currentVersion());

                // Malformed caps: columns present, but NO unique constraint
                // and NO foreign key.
                $ok = $wpdb->query("CREATE TABLE {$caps} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, role_def_id BIGINT UNSIGNED NOT NULL, capability VARCHAR(64) NOT NULL, created_at DATETIME(3) NOT NULL, PRIMARY KEY (id) ) ENGINE=InnoDB"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse($ok, 'malformed caps fixture must be created');
                $ok = $wpdb->query("INSERT INTO {$caps} (role_def_id, capability, created_at) VALUES (1, 'cpms_patient_read', UTC_TIMESTAMP(3))"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse($ok, 'marker row must be inserted');

                $migrate_failed = false;
                $error_message  = '';
                try {
                    App::migrations()->migrate();
                } catch (\RuntimeException $e) {
                    $migrate_failed = true;
                    $error_message  = $e->getMessage();
                }
                self::assertTrue($migrate_failed, 'migrate() must fail loudly on a malformed pre-existing caps table');
                self::assertStringContainsString('migration 0024', $error_message);
            });

            self::assertSame(
                self::PREVIOUS_VERSION,
                App::migrations()->currentVersion(),
                'migration version must stay at 0023 — 0024 must never be recorded after the failure'
            );
            self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$caps}"), 'existing rows must remain unchanged'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } finally {
            $this->restoreFreshSchema();
        }

        self::assertSame(self::HEAD_VERSION, App::migrations()->currentVersion(), 'suite schema must be restored after the test');
    }

    // ------------------------------------------------------------------
    // BLOCKER 2 (second review round) — the schema-contract validation of
    // migration 0024 must not FALSELY ACCEPT a materially incompatible
    // pre-existing table. Previously accepted defects, each now proven to be
    // rejected before anything is created or recorded:
    //   1. idx_custom_role_status must be NON-UNIQUE (a UNIQUE index was accepted);
    //   2. id must be AUTO_INCREMENT (a plain BIGINT was accepted);
    //   3. the PRIMARY KEY must be exactly (id) (wrong/absent PK was accepted);
    //   4. a FOREIGN KEY must match the COMPLETE expected mapping (a composite
    //      FK whose first column matched was accepted);
    //   5. the capabilities FK is validated independently of the defs table.
    // ------------------------------------------------------------------

    /**
     * Shared malformed-schema proof: back to 0023, create the malformed
     * same-name table(s) + one marker row, then require that migrate() fails
     * loudly, that 0024 is NOT recorded, and that the malformed table and its
     * row survive untouched (no drop/recreate, no silent repair).
     *
     * @param callable(): void $createMalformed creates the malformed fixture + marker row
     * @param list<string>     $expectedColumns expected SHOW COLUMNS order of $probeTable
     */
    private function assertMalformedSchemaRejected(string $label, callable $createMalformed, string $probeTable, array $expectedColumns): void
    {
        global $wpdb;

        $beforeRows = null;
        $beforeCols = null;

        try {
            $this->withRealTables(function () use ($label, $createMalformed, $probeTable, $expectedColumns, &$beforeRows, &$beforeCols): void {
                global $wpdb;
                ( function () use ( $label ): void {
                    App::migrations()->migrate();
                    while ( App::migrations()->currentVersion() !== self::PREVIOUS_VERSION ) {
                        self::assertNotNull( App::migrations()->rollbackOne(), $label . ': the schema must be rollable back to 0023' );
                    }
                } )();
                self::assertSame(self::PREVIOUS_VERSION, App::migrations()->currentVersion(), $label . ': version must sit at 0023');

                $createMalformed();

                // Capture exact before snapshot (structure + data) before the failing migration.
                $beforeCols = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$probeTable}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $beforeRows = $wpdb->get_results( "SELECT * FROM {$probeTable} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertSame( $expectedColumns, $beforeCols, $label . ': malformed table must have expected columns before migration' );
                self::assertCount( 1, (array) $beforeRows, $label . ': exactly one marker row must exist before migration' );

                $migrate_failed = false;
                $error_message  = '';
                try {
                    App::migrations()->migrate();
                } catch (\RuntimeException $e) {
                    $migrate_failed = true;
                    $error_message  = $e->getMessage();
                }

                self::assertTrue($migrate_failed, $label . ': migrate() must fail loudly on the malformed schema');
                self::assertStringContainsString('migration 0024', $error_message, $label . ': the failure must name the violated schema contract');
            });

            self::assertSame(
                self::PREVIOUS_VERSION,
                App::migrations()->currentVersion(),
                $label . ': migration 0024 must never be recorded after the failure'
            );
            // Capture after snapshot with real-table mode to ensure we see the same real table.
            $afterCols = null;
            $afterRows = null;
            $this->withRealTables(function () use ($probeTable, &$afterCols, &$afterRows): void {
                global $wpdb;
                $afterCols = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$probeTable}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $afterRows = $wpdb->get_results( "SELECT * FROM {$probeTable} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            });
            self::assertSame($beforeCols, $afterCols, $label . ': table structure must remain exactly unchanged (before vs after)');
            self::assertEquals($beforeRows, $afterRows, $label . ': marker row must remain exactly unchanged (before vs after)');
            $marker = $afterRows[0] ?? $beforeRows[0] ?? [];
            // For defs probe the marker role_key must still be the probe value; for caps probe the capability must remain.
            if ( str_contains( $probeTable, 'cpms_custom_role_defs' ) ) {
                $found_key = (string) ( $marker['role_key'] ?? '' );
                self::assertTrue(
                    str_contains( $found_key, 'probe_' ),
                    $label . ': marker role_key must remain a probe value, got ' . $found_key
                );
            } else {
                $found_cap = (string) ( $marker['capability'] ?? '' );
                // Caps marker is either 'cpms_patient_read' or 'probe_pair' depending on phase.
                self::assertTrue(
                    $found_cap === 'cpms_patient_read' || $found_cap === 'probe_pair',
                    $label . ': marker capability must remain probe value, got ' . $found_cap
                );
            }
        } finally {
            $this->restoreFreshSchema();
        }

        self::assertSame(self::HEAD_VERSION, App::migrations()->currentVersion(), $label . ': suite schema must be restored');
    }

    /**
     * Contract-valid defs DDL, optionally with one extra (harmless) index that
     * a composite caps FK can reference.
     */
    private function defsDdl(bool $withPairIndex): string
    {
        $defs    = App::db()->table('cpms_custom_role_defs');
        $clinics = App::db()->table('cpms_clinics');
        $pair    = $withPairIndex ? ', UNIQUE KEY idx_probe_pair (id, role_key)' : '';

        return "CREATE TABLE {$defs} ("
            . ' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' clinic_id BIGINT UNSIGNED NOT NULL,'
            . ' role_key VARCHAR(64) NOT NULL,'
            . " status ENUM('active','inactive') NOT NULL DEFAULT 'active',"
            . ' version INT UNSIGNED NOT NULL DEFAULT 1,'
            . ' created_at DATETIME(3) NOT NULL,'
            . ' updated_at DATETIME(3) NOT NULL,'
            . ' PRIMARY KEY (id),'
            . ' UNIQUE KEY u_custom_role (clinic_id, role_key),'
            . ' KEY idx_custom_role_status (clinic_id, status)'
            . $pair
            . ", CONSTRAINT fk_customrole_clinic FOREIGN KEY (clinic_id) REFERENCES {$clinics} (id) ON DELETE CASCADE"
            . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /**
     * caps DDL with a caller-supplied FOREIGN KEY clause.
     */
    private function capsDdl(string $foreignKeyClause): string
    {
        $caps = App::db()->table('cpms_custom_role_capabilities');

        return "CREATE TABLE {$caps} ("
            . ' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' role_def_id BIGINT UNSIGNED NOT NULL,'
            . ' capability VARCHAR(64) NOT NULL,'
            . ' created_at DATETIME(3) NOT NULL,'
            . ' PRIMARY KEY (id),'
            . ' UNIQUE KEY u_custom_role_cap (role_def_id, capability),'
            . ' ' . $foreignKeyClause
            . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private function assertSqlSucceeds(string $sql): void
    {
        global $wpdb;
        $ok = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertNotFalse($ok, 'fixture SQL must succeed: ' . $wpdb->last_error);
    }

    /**
     * Malformed case 1 — a UNIQUE index where the repository read path requires
     * the NON-UNIQUE lookup index idx_custom_role_status.
     */
    public function testUniqueIndexWhereNonUniqueIsRequiredFailsMigration(): void
    {
        $defs    = App::db()->table('cpms_custom_role_defs');
        $clinics = App::db()->table('cpms_clinics');

        $this->assertMalformedSchemaRejected(
            'UNIQUE idx_custom_role_status',
            function () use ($defs, $clinics): void {
                $this->assertSqlSucceeds(
                    "CREATE TABLE {$defs} ("
                    . ' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' clinic_id BIGINT UNSIGNED NOT NULL,'
                    . ' role_key VARCHAR(64) NOT NULL,'
                    . " status ENUM('active','inactive') NOT NULL DEFAULT 'active',"
                    . ' version INT UNSIGNED NOT NULL DEFAULT 1,'
                    . ' created_at DATETIME(3) NOT NULL,'
                    . ' updated_at DATETIME(3) NOT NULL,'
                    . ' PRIMARY KEY (id),'
                    . ' UNIQUE KEY u_custom_role (clinic_id, role_key),'
                    . ' UNIQUE KEY idx_custom_role_status (clinic_id, status),'
                    . " CONSTRAINT fk_customrole_clinic FOREIGN KEY (clinic_id) REFERENCES {$clinics} (id) ON DELETE CASCADE"
                    . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
                $this->assertSqlSucceeds(
                    "INSERT INTO {$defs} (clinic_id, role_key, status, version, created_at, updated_at)"
                    . " VALUES (1, 'probe_unique_index', 'active', 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))"
                );
            },
            $defs,
            ['id', 'clinic_id', 'role_key', 'status', 'version', 'created_at', 'updated_at']
        );
    }

    /**
     * Malformed case 2 — id without AUTO_INCREMENT: every other contract point
     * is satisfied, so only the auto-increment requirement can catch it.
     */
    public function testIdWithoutAutoIncrementFailsMigration(): void
    {
        $defs    = App::db()->table('cpms_custom_role_defs');
        $clinics = App::db()->table('cpms_clinics');

        $this->assertMalformedSchemaRejected(
            'id without AUTO_INCREMENT',
            function () use ($defs, $clinics): void {
                $this->assertSqlSucceeds(
                    "CREATE TABLE {$defs} ("
                    . ' id BIGINT UNSIGNED NOT NULL,'
                    . ' clinic_id BIGINT UNSIGNED NOT NULL,'
                    . ' role_key VARCHAR(64) NOT NULL,'
                    . " status ENUM('active','inactive') NOT NULL DEFAULT 'active',"
                    . ' version INT UNSIGNED NOT NULL DEFAULT 1,'
                    . ' created_at DATETIME(3) NOT NULL,'
                    . ' updated_at DATETIME(3) NOT NULL,'
                    . ' PRIMARY KEY (id),'
                    . ' UNIQUE KEY u_custom_role (clinic_id, role_key),'
                    . ' KEY idx_custom_role_status (clinic_id, status),'
                    . " CONSTRAINT fk_customrole_clinic FOREIGN KEY (clinic_id) REFERENCES {$clinics} (id) ON DELETE CASCADE"
                    . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
                $this->assertSqlSucceeds(
                    "INSERT INTO {$defs} (id, clinic_id, role_key, status, version, created_at, updated_at)"
                    . " VALUES (1, 1, 'probe_no_autoinc', 'active', 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))"
                );
            },
            $defs,
            ['id', 'clinic_id', 'role_key', 'status', 'version', 'created_at', 'updated_at']
        );
    }

    /**
     * Malformed case 3 — wrong PRIMARY KEY, and (second phase) no PRIMARY KEY
     * at all. Both must be rejected on primary-key metadata alone.
     */
    public function testIncorrectOrMissingPrimaryKeyFailsMigration(): void
    {
        $defs    = App::db()->table('cpms_custom_role_defs');
        $clinics = App::db()->table('cpms_clinics');
        $columns = ['id', 'clinic_id', 'role_key', 'status', 'version', 'created_at', 'updated_at'];

        // Phase 1 — PRIMARY KEY on the wrong column (id is AUTO_INCREMENT so the error must reach the PK validator, not the AUTO_INCREMENT check).
        $this->assertMalformedSchemaRejected(
            'PRIMARY KEY (clinic_id)',
            function () use ($defs, $clinics): void {
                $this->assertSqlSucceeds(
                    "CREATE TABLE {$defs} ("
                    . ' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' clinic_id BIGINT UNSIGNED NOT NULL,'
                    . ' role_key VARCHAR(64) NOT NULL,'
                    . " status ENUM('active','inactive') NOT NULL DEFAULT 'active',"
                    . ' version INT UNSIGNED NOT NULL DEFAULT 1,'
                    . ' created_at DATETIME(3) NOT NULL,'
                    . ' updated_at DATETIME(3) NOT NULL,'
                    . ' PRIMARY KEY (clinic_id),'
                    . ' KEY idx_defs_id (id),'
                    . ' UNIQUE KEY u_custom_role (clinic_id, role_key),'
                    . ' KEY idx_custom_role_status (clinic_id, status),'
                    . " CONSTRAINT fk_customrole_clinic FOREIGN KEY (clinic_id) REFERENCES {$clinics} (id) ON DELETE CASCADE"
                    . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
                $this->assertSqlSucceeds(
                    "INSERT INTO {$defs} (id, clinic_id, role_key, status, version, created_at, updated_at)"
                    . " VALUES (7, 1, 'probe_wrong_pk', 'active', 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))"
                );
            },
            $defs,
            $columns
        );

        // Phase 2 — no PRIMARY KEY at all (id is AUTO_INCREMENT with a plain KEY so the error must reach the PK validator).
        $this->assertMalformedSchemaRejected(
            'missing PRIMARY KEY',
            function () use ($defs, $clinics): void {
                $this->assertSqlSucceeds(
                    "CREATE TABLE {$defs} ("
                    . ' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' clinic_id BIGINT UNSIGNED NOT NULL,'
                    . ' role_key VARCHAR(64) NOT NULL,'
                    . " status ENUM('active','inactive') NOT NULL DEFAULT 'active',"
                    . ' version INT UNSIGNED NOT NULL DEFAULT 1,'
                    . ' created_at DATETIME(3) NOT NULL,'
                    . ' updated_at DATETIME(3) NOT NULL,'
                    . ' KEY idx_id (id),'
                    . ' UNIQUE KEY u_custom_role (clinic_id, role_key),'
                    . ' KEY idx_custom_role_status (clinic_id, status),'
                    . " CONSTRAINT fk_customrole_clinic FOREIGN KEY (clinic_id) REFERENCES {$clinics} (id) ON DELETE CASCADE"
                    . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
                $this->assertSqlSucceeds(
                    "INSERT INTO {$defs} (id, clinic_id, role_key, status, version, created_at, updated_at)"
                    . " VALUES (9, 1, 'probe_no_pk', 'active', 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))"
                );
            },
            $defs,
            $columns
        );
    }

    /**
     * Malformed case 4 — a COMPOSITE capabilities FK whose first mapping
     * (role_def_id → defs.id) matches the contract but whose complete mapping
     * does not. The defs table is contract-valid (plus one extra harmless
     * index the composite FK needs), so only full-mapping FK validation can
     * catch this.
     */
    public function testCompositeForeignKeyWithMatchingFirstColumnOnlyFailsMigration(): void
    {
        global $wpdb;
        $defs = App::db()->table('cpms_custom_role_defs');
        $caps = App::db()->table('cpms_custom_role_capabilities');

        $this->assertMalformedSchemaRejected(
            'composite fk_customrolecap_def',
            function () use ($wpdb, $defs, $caps): void {
                $this->assertSqlSucceeds($this->defsDdl(true));
                $this->assertSqlSucceeds(
                    "INSERT INTO {$defs} (clinic_id, role_key, status, version, created_at, updated_at)"
                    . " VALUES (1, 'probe_pair', 'active', 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))"
                );
                $defId = (int) $wpdb->insert_id;
                self::assertGreaterThan(0, $defId, 'probe definition must exist for the composite FK');

                // First mapping matches the contract (role_def_id → defs.id);
                // the second one (capability → defs.role_key) does not exist in
                // the contract at all — hence the marker capability value must
                // equal the referenced role_key for this FK to be insertable.
                $this->assertSqlSucceeds(
                    $this->capsDdl(
                        "CONSTRAINT fk_customrolecap_def FOREIGN KEY (role_def_id, capability) REFERENCES {$defs} (id, role_key) ON DELETE CASCADE"
                    )
                );
                $this->assertSqlSucceeds(
                    "INSERT INTO {$caps} (role_def_id, capability, created_at) VALUES ({$defId}, 'probe_pair', UTC_TIMESTAMP(3))"
                );
            },
            $caps,
            ['id', 'role_def_id', 'capability', 'created_at']
        );
    }

    /**
     * Malformed case 5 — the capabilities FK is validated independently of the
     * defs table (defs stays contract-valid): wrong referenced table, then
     * wrong ON DELETE behaviour.
     */
    public function testMalformedCapabilitiesForeignKeyFailsMigrationIndependently(): void
    {
        global $wpdb;
        $defs    = App::db()->table('cpms_custom_role_defs');
        $caps    = App::db()->table('cpms_custom_role_capabilities');
        $clinics = App::db()->table('cpms_clinics');
        $columns = ['id', 'role_def_id', 'capability', 'created_at'];

        $seedDef = function () use ($wpdb, $defs): int {
            $this->assertSqlSucceeds($this->defsDdl(false));
            $this->assertSqlSucceeds(
                "INSERT INTO {$defs} (clinic_id, role_key, status, version, created_at, updated_at)"
                . " VALUES (1, 'probe_fk', 'active', 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))"
            );

            return (int) $wpdb->insert_id;
        };

        // Phase 1 — FK points at the wrong table.
        $this->assertMalformedSchemaRejected(
            'fk_customrolecap_def wrong referenced table',
            function () use ($seedDef, $caps, $clinics): void {
                $seedDef();
                $this->assertSqlSucceeds(
                    $this->capsDdl(
                        "CONSTRAINT fk_customrolecap_def FOREIGN KEY (role_def_id) REFERENCES {$clinics} (id) ON DELETE CASCADE"
                    )
                );
                $this->assertSqlSucceeds(
                    "INSERT INTO {$caps} (role_def_id, capability, created_at) VALUES (1, 'cpms_patient_read', UTC_TIMESTAMP(3))"
                );
            },
            $caps,
            $columns
        );

        // Phase 2 — FK points at the right table but does not cascade.
        $this->assertMalformedSchemaRejected(
            'fk_customrolecap_def wrong delete rule',
            function () use ($seedDef, $caps, $defs): void {
                $defId = $seedDef();
                $this->assertSqlSucceeds(
                    $this->capsDdl(
                        "CONSTRAINT fk_customrolecap_def FOREIGN KEY (role_def_id) REFERENCES {$defs} (id) ON DELETE RESTRICT"
                    )
                );
                $this->assertSqlSucceeds(
                    "INSERT INTO {$caps} (role_def_id, capability, created_at) VALUES ({$defId}, 'cpms_patient_read', UTC_TIMESTAMP(3))"
                );
            },
            $caps,
            $columns
        );
    }

    /**
     * Cross-schema FK regression — the validator must reject a FK that points
     * to a same-named parent table in another schema (REFERENCED_TABLE_SCHEMA
     * must match DATABASE()). 12-step runtime proof with exact preservation.
     */
    public function testCrossSchemaForeignKeyFailsMigration(): void
    {
        global $wpdb;
        $defs          = App::db()->table( 'cpms_custom_role_defs' );
        $caps          = App::db()->table( 'cpms_custom_role_capabilities' );
        $current_db    = (string) $wpdb->get_var( 'SELECT DATABASE()' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $cross_db      = $current_db . '_cross';
        $cross_clinics = $wpdb->prefix . 'cpms_clinics';

        // 1. Establish actual migration state after setUp().
        self::assertSame( self::HEAD_VERSION, App::migrations()->currentVersion(), 'setUp must leave the custom-role migrations at head' );

        $beforeRows = null;
        $beforeCols = null;
        $crossParentId = null;

        try {
            $this->withRealTables(function () use ($wpdb, $defs, $caps, $cross_db, $cross_clinics, &$beforeRows, &$beforeCols, &$crossParentId): void {
                // 2. Roll the custom-role migrations back to 0023 BEFORE constructing malformed schema.
                App::migrations()->migrate();
                while ( App::migrations()->currentVersion() !== self::PREVIOUS_VERSION ) {
                    self::assertNotNull( App::migrations()->rollbackOne(), 'the schema must be rollable back to 0023' );
                }
                self::assertSame( self::PREVIOUS_VERSION, App::migrations()->currentVersion(), 'version must sit at 0023 before fixture' );

                // 3. Create cross-schema referenced parent table (check every result).
                // Ensure clean slate.
                $wpdb->query( "DROP DATABASE IF EXISTS `{$cross_db}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $created = $wpdb->query( "CREATE DATABASE `{$cross_db}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                if ( false === $created ) {
                    self::markTestSkipped( 'cross-schema FK fixture not constructible: CREATE DATABASE failed: ' . $wpdb->last_error );
                    return;
                }
                self::assertNotFalse( $created, 'CREATE DATABASE must succeed: ' . $wpdb->last_error );
                $ok = $wpdb->query( "CREATE TABLE `{$cross_db}`.`{$cross_clinics}` ( `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse( $ok, 'cross-schema parent table must be created: ' . $wpdb->last_error );
                $ok = $wpdb->query( "INSERT INTO `{$cross_db}`.`{$cross_clinics}` (id) VALUES (1)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse( $ok, 'cross-schema parent row must be inserted: ' . $wpdb->last_error );
                $crossParentId = (int) $wpdb->get_var( "SELECT id FROM `{$cross_db}`.`{$cross_clinics}` WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertSame( 1, $crossParentId, 'cross parent id must be 1' );

                // 4. Construct malformed local table with actual FK referencing external schema.
                $ok = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    "CREATE TABLE {$defs} ("
                    . ' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' clinic_id BIGINT UNSIGNED NOT NULL,'
                    . ' role_key VARCHAR(64) NOT NULL,'
                    . " status ENUM('active','inactive') NOT NULL DEFAULT 'active',"
                    . ' version INT UNSIGNED NOT NULL DEFAULT 1,'
                    . ' created_at DATETIME(3) NOT NULL,'
                    . ' updated_at DATETIME(3) NOT NULL,'
                    . ' PRIMARY KEY (id),'
                    . ' UNIQUE KEY u_custom_role (clinic_id, role_key),'
                    . ' KEY idx_custom_role_status (clinic_id, status),'
                    . " CONSTRAINT fk_customrole_clinic FOREIGN KEY (clinic_id) REFERENCES `{$cross_db}`.`{$cross_clinics}` (id) ON DELETE CASCADE"
                    . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
                if ( false === $ok ) {
                    // Do not treat unrelated CREATE TABLE errors as unsupported cross-schema FK — but if FK creation fails due to server restriction, report it.
                    $wpdb->query( "DROP DATABASE IF EXISTS `{$cross_db}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    self::markTestSkipped( 'cross-schema FK not supported: CREATE TABLE with cross-schema FK failed: ' . $wpdb->last_error );
                    return;
                }
                self::assertNotFalse( $ok, 'malformed local defs with cross-schema FK must be created: ' . $wpdb->last_error );

                // 5. Insert and verify required parent and local marker records.
                $ok = $wpdb->query( "INSERT INTO {$defs} (clinic_id, role_key, status, version, created_at, updated_at) VALUES (1, 'probe_cross', 'active', 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse( $ok, 'local marker row must be inserted: ' . $wpdb->last_error );
                $localCount = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$defs} WHERE role_key = 'probe_cross'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertSame( 1, $localCount, 'local marker must be exactly one' );

                // 6 is covered by every assertNotFalse above; 7 — do not rollback again before validation.

                // Capture exact before snapshot for preservation check.
                $beforeCols = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$defs}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $beforeRows = $wpdb->get_results( "SELECT * FROM {$defs} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertCount( 1, (array) $beforeRows, 'exactly one marker row before migration' );

                // 8. Execute real migration validator against malformed schema.
                $migrate_failed = false;
                $error_message  = '';
                try {
                    App::migrations()->migrate();
                } catch (\RuntimeException $e) {
                    $migrate_failed = true;
                    $error_message  = $e->getMessage();
                }

                // 9. Assert precise expected schema-contract rejection.
                self::assertTrue( $migrate_failed, 'migrate must fail loudly on cross-schema FK' );
                self::assertStringContainsString( 'migration 0024', $error_message, 'failure must name migration 0024' );
                // The validator mentions the violated FK contract.
                self::assertStringContainsString( 'fk_customrole_clinic', strtolower( $error_message ), 'failure must mention fk_customrole_clinic' );
            });

            // If the test was skipped due to permissions, the withRealTables closure returned early and beforeRows remains null — do not assert further.
            if ( null === $beforeRows ) {
                // Check if the test was marked skipped (PHPUnit will have recorded it). We still need to clean up.
                return;
            }

            // 10. Assert migration version remains 0023.
            self::assertSame( self::PREVIOUS_VERSION, App::migrations()->currentVersion(), 'migration 0024 must never be recorded after cross-schema failure' );

            // 11. Assert exact malformed table structure and marker-row values remain unchanged (with real-table mode).
            $afterCols = null;
            $afterRows = null;
            $this->withRealTables(function () use ($wpdb, $defs, &$afterCols, &$afterRows): void {
                $afterCols = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$defs}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $afterRows = $wpdb->get_results( "SELECT * FROM {$defs} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            });
            self::assertSame( $beforeCols, $afterCols, 'cross-schema malformed table columns must remain exactly unchanged' );
            self::assertEquals( $beforeRows, $afterRows, 'cross-schema marker row must remain exactly unchanged (before vs after)' );
            $marker = $afterRows[0];
            self::assertSame( 'probe_cross', (string) ( $marker['role_key'] ?? '' ), 'marker role_key must remain probe_cross' );
            self::assertSame( 1, (int) ( $marker['clinic_id'] ?? 0 ), 'marker clinic_id must remain 1' );
        } finally {
            // 12. Restore normal schema safely for subsequent tests - drop child before parent, check failures, preserve original.
            $cleanupError = '';
            try {
                $this->withRealTables(function () use ($wpdb, $cross_db, $defs, $caps, &$cleanupError): void {
                    // Drop local child (defs has FK to cross_db) before external parent.
                    $ok = $wpdb->query( "DROP TABLE IF EXISTS {$defs}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    if ( false === $ok && '' !== $wpdb->last_error ) {
                        $cleanupError = $wpdb->last_error;
                    }
                    $ok = $wpdb->query( "DROP TABLE IF EXISTS {$caps}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                        $cleanupError = $wpdb->last_error;
                    }
                    $ok = $wpdb->query( "DROP DATABASE IF EXISTS `{$cross_db}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                        $cleanupError = $wpdb->last_error;
                    }
                    $ok = $wpdb->query( "DELETE FROM {$wpdb->prefix}cpms_schema_migrations WHERE version IN ('" . self::NEW_VERSION . "', '" . self::HEAD_VERSION . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                        $cleanupError = $wpdb->last_error;
                    }
                    $ok = $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$defs}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                        $cleanupError = $wpdb->last_error;
                    }
                    $ok = $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$caps}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                        $cleanupError = $wpdb->last_error;
                    }
                    App::migrations()->migrate();
                });
            } finally {
                if ( '' !== $cleanupError ) {
                    // Preserve original test failure if any, but report cleanup failure.
                    // Use assert to make it visible without silently ignoring.
                    self::assertSame( '', $cleanupError, 'cross-schema cleanup must succeed: ' . $cleanupError );
                }
            }
        }

        self::assertSame( self::HEAD_VERSION, App::migrations()->currentVersion(), 'suite schema must be restored after cross-schema test' );
    }

    /**
     * Deterministic suite-state restore: drop both custom-role tables, unrecord
     * 0024 and re-run the migration so later tests always see a complete,
     * freshly-created schema (independent of what a failing test left behind).
     */
    private function restoreFreshSchema(): void
    {
        global $wpdb;
        $defs = App::db()->table('cpms_custom_role_defs');
        $caps = App::db()->table('cpms_custom_role_capabilities');

        $cleanupError = '';
        try {
            $this->withRealTables(function () use ($wpdb, $defs, $caps, &$cleanupError): void {
                $ok = $wpdb->query("DELETE FROM {$wpdb->prefix}cpms_schema_migrations WHERE version IN ('" . self::NEW_VERSION . "', '" . self::HEAD_VERSION . "')"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                    $cleanupError = $wpdb->last_error;
                }
                // Drop child before parent (caps has FK to defs)
                $ok = $wpdb->query("DROP TABLE IF EXISTS {$caps}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                    $cleanupError = $wpdb->last_error;
                }
                $ok = $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$caps}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                    $cleanupError = $wpdb->last_error;
                }
                $ok = $wpdb->query("DROP TABLE IF EXISTS {$defs}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                    $cleanupError = $wpdb->last_error;
                }
                $ok = $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$defs}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                    $cleanupError = $wpdb->last_error;
                }
                App::migrations()->migrate();
            });
        } finally {
            if ( '' !== $cleanupError ) {
                self::assertSame( '', $cleanupError, 'restoreFreshSchema cleanup must succeed: ' . $cleanupError );
            }
        }
    }
}
