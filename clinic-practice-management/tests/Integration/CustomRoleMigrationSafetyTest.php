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
}
