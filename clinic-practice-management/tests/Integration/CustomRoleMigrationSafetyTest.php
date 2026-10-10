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

    private const NEW_VERSION = '2026_10_10_0024';

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
        self::assertSame(self::NEW_VERSION, App::migrations()->currentVersion(), 'migration 2026_10_10_0024 must be the current version');

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
                $rolled = App::migrations()->rollbackOne();
                self::assertSame(self::NEW_VERSION, $rolled, 'rollbackOne() must roll back 0024 first');
                self::assertSame(self::PREVIOUS_VERSION, App::migrations()->currentVersion(), 'version must sit at 0023 before the fresh migration');

                $applied = App::migrations()->migrate();
                self::assertContains(self::NEW_VERSION, $applied, 'normal fresh migration must apply 0024');
                self::assertSame(self::NEW_VERSION, App::migrations()->currentVersion());

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
                $wpdb->query("DELETE FROM {$wpdb->prefix}cpms_schema_migrations WHERE version = '" . self::NEW_VERSION . "'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query("DROP TABLE IF EXISTS {$caps}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query("DROP TABLE IF EXISTS {$defs}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                App::migrations()->migrate();
            }
        });

        self::assertSame(self::NEW_VERSION, App::migrations()->currentVersion());
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
                self::assertSame(self::NEW_VERSION, App::migrations()->rollbackOne());
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

        self::assertSame(self::NEW_VERSION, App::migrations()->currentVersion(), 'suite schema must be restored after the test');
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
                self::assertSame(self::NEW_VERSION, App::migrations()->rollbackOne());
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

        self::assertSame(self::NEW_VERSION, App::migrations()->currentVersion(), 'suite schema must be restored after the test');
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

        $this->withRealTables(static function () use ($wpdb, $defs, $caps): void {
            $wpdb->query("DELETE FROM {$wpdb->prefix}cpms_schema_migrations WHERE version = '" . self::NEW_VERSION . "'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("DROP TABLE IF EXISTS {$caps}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("DROP TABLE IF EXISTS {$defs}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            App::migrations()->migrate();
        });
    }
}
