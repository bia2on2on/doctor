<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use WP_UnitTestCase;

/**
 * Custom Role & Permission Management — Slice 1 hardening (BLOCKER 1
 * regression set): transactional integrity of the custom-role write paths.
 *
 * Contracts:
 *  - Every required database write of define()/replace_capabilities()/
 *    set_status() is checked; a failed write throws inside the transaction.
 *  - A failed definition leaves no partially created role row and no
 *    capability rows (rollback proven through persisted database state).
 *  - A failed capability replacement preserves the previous capability set
 *    AND the previous version; a successful replacement atomically persists
 *    the complete approved set and advances the version exactly once.
 *  - A failed delete, insert, or version update never reports success.
 *
 * Fault injection: deterministic, bounded query-filter sabotage that rewrites
 * exactly one statement class (verb + table) of the real write path to a
 * nonexistent table, producing a real MySQL error at execution time. This is
 * the established sabotage pattern of FinanceClinicIsolationTest; the filter
 * is armed only around the targeted call and disarmed in finally.
 */
final class CustomRoleRepositoryTransactionTest extends WP_UnitTestCase
{
    private CustomRoleRepository $repo;
    private int $clinicId;

    private ?string $sabotageTable = null;
    private string $sabotageVerb   = 'INSERT';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->repo = new CustomRoleRepository(App::db());

        global $wpdb;
        $now   = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $orgId, 'seeded organization must exist');

        $slug = 'cxrt-' . bin2hex(random_bytes(4));
        $ok   = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $orgId,
                'CustomRoleTxn Clinic ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        self::assertNotFalse($ok, 'clinic fixture must be created');
        $this->clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicId);
    }

    protected function tearDown(): void
    {
        $this->disarmSabotage();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Deterministic bounded fault injection (real MySQL failure on the
    // actual write path; only the armed verb + table is affected).
    // ------------------------------------------------------------------

    private function armSabotage(string $table, string $verb): void
    {
        $this->sabotageTable = $table;
        $this->sabotageVerb  = $verb;
        add_filter('query', [$this, 'sabotageQuery']);
    }

    private function disarmSabotage(): void
    {
        if ($this->sabotageTable !== null) {
            remove_filter('query', [$this, 'sabotageQuery']);
            $this->sabotageTable = null;
        }
    }

    /**
     * @param mixed $query
     * @return mixed
     */
    public function sabotageQuery($query)
    {
        if ($this->sabotageTable !== null && is_string($query)) {
            global $wpdb;
            // wpdb quotes identifiers with backticks — cover both forms.
            $table = preg_quote($wpdb->prefix . $this->sabotageTable, '/');
            $q     = trim($query);
            if ($this->sabotageVerb === 'INSERT' && preg_match('/INSERT\s+INTO\s+`?' . $table . '`?\s*\(/i', $q) === 1) {
                return 'INSERT INTO `' . $wpdb->prefix . 'cpms_table_that_does_not_exist` (id) VALUES (1)';
            }
            if ($this->sabotageVerb === 'DELETE' && preg_match('/^DELETE\s+FROM\s+`?' . $table . '`?/i', $q) === 1) {
                return 'DELETE FROM `' . $wpdb->prefix . 'cpms_table_that_does_not_exist` WHERE 1 = 1';
            }
            if ($this->sabotageVerb === 'UPDATE' && preg_match('/^UPDATE\s+`?' . $table . '`?\s+SET/i', $q) === 1) {
                return 'UPDATE `' . $wpdb->prefix . 'cpms_table_that_does_not_exist` SET id = 1 WHERE 1 = 1';
            }
        }

        return $query;
    }

    // ------------------------------------------------------------------
    // Persisted-state helpers (rollback must be visible in the database,
    // not merely asserted as an exception).
    // ------------------------------------------------------------------

    /**
     * @return array{def: ?array{status: string, version: int}, caps: ?list<string>}
     */
    private function fetchState(string $role_key): array
    {
        global $wpdb;
        $defs = $wpdb->prefix . 'cpms_custom_role_defs';
        $caps = $wpdb->prefix . 'cpms_custom_role_capabilities';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status, version FROM {$defs} WHERE clinic_id = %d AND role_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $role_key
            ),
            ARRAY_A
        );
        if ($row === null) {
            return ['def' => null, 'caps' => null];
        }
        $cap_rows = (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT capability FROM {$caps} WHERE role_def_id = %d ORDER BY capability", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                (int) $row['id']
            )
        );

        return [
            'def'  => ['status' => (string) $row['status'], 'version' => (int) $row['version']],
            'caps' => array_map('strval', $cap_rows),
        ];
    }

    private function countDefs(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_custom_role_defs'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function countCaps(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_custom_role_capabilities'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    // ------------------------------------------------------------------
    // define(): a failed capability write must leave no partial role.
    // ------------------------------------------------------------------

    public function testFailedDefinitionLeavesNoPartialRoleOrCapabilities(): void
    {
        // The definition INSERT succeeds; only the capability INSERT fails.
        $this->armSabotage('cpms_custom_role_capabilities', 'INSERT');
        $thrown = false;
        try {
            $this->repo->define($this->clinicId, 'team_txn_partial', ['cpms_patient_read']);
        } catch (\RuntimeException $e) {
            $thrown = true;
        } finally {
            $this->disarmSabotage();
        }

        self::assertSame(['def' => null, 'caps' => null], $this->fetchState('team_txn_partial'), 'a failed definition must leave no partially created role or capabilities');
        self::assertSame(0, $this->countDefs(), 'no definition row may survive the rolled-back define()');
        self::assertSame(0, $this->countCaps(), 'no capability row may survive the rolled-back define()');
        self::assertTrue($thrown, 'a failed capability insert must throw (never report success)');
    }

    // ------------------------------------------------------------------
    // replace_capabilities(): each write failure preserves set + version.
    // ------------------------------------------------------------------

    public function testFailedCapabilityDeletePreservesPreviousSetAndVersion(): void
    {
        $this->repo->define($this->clinicId, 'team_txn_del', ['cpms_patient_read']);
        $before = $this->fetchState('team_txn_del');

        $this->armSabotage('cpms_custom_role_capabilities', 'DELETE');
        $thrown = false;
        try {
            $this->repo->replace_capabilities($this->clinicId, 'team_txn_del', ['cpms_appt_read']);
        } catch (\RuntimeException $e) {
            $thrown = true;
        } finally {
            $this->disarmSabotage();
        }

        self::assertSame($before, $this->fetchState('team_txn_del'), 'a failed capability replacement must preserve the previous capability set and version');
        self::assertTrue($thrown, 'a failed delete must throw and never report success');
    }

    public function testFailedCapabilityInsertDuringReplacePreservesPreviousSetAndVersion(): void
    {
        $this->repo->define($this->clinicId, 'team_txn_ins', ['cpms_patient_read']);
        $before = $this->fetchState('team_txn_ins');

        $this->armSabotage('cpms_custom_role_capabilities', 'INSERT');
        $thrown = false;
        try {
            $this->repo->replace_capabilities($this->clinicId, 'team_txn_ins', ['cpms_appt_read']);
        } catch (\RuntimeException $e) {
            $thrown = true;
        } finally {
            $this->disarmSabotage();
        }

        self::assertSame($before, $this->fetchState('team_txn_ins'), 'a failed capability insert during replace must not persist a partial set');
        self::assertTrue($thrown, 'a failed capability insert must throw and never report success');
    }

    public function testFailedVersionAdvancePreservesPreviousSetAndVersion(): void
    {
        $this->repo->define($this->clinicId, 'team_txn_ver', ['cpms_patient_read']);
        $before = $this->fetchState('team_txn_ver');

        $this->armSabotage('cpms_custom_role_defs', 'UPDATE');
        $thrown = false;
        try {
            $this->repo->replace_capabilities($this->clinicId, 'team_txn_ver', ['cpms_appt_read']);
        } catch (\RuntimeException $e) {
            $thrown = true;
        } finally {
            $this->disarmSabotage();
        }

        self::assertSame($before, $this->fetchState('team_txn_ver'), 'a failed version update must roll the whole replacement back');
        self::assertTrue($thrown, 'a failed version update must throw and never report success');
    }

    // ------------------------------------------------------------------
    // set_status(): a failed update must not report success.
    // ------------------------------------------------------------------

    public function testFailedStatusUpdateNeverReportsSuccess(): void
    {
        $this->repo->define($this->clinicId, 'team_txn_status', ['cpms_patient_read']);
        $before = $this->fetchState('team_txn_status');

        $this->armSabotage('cpms_custom_role_defs', 'UPDATE');
        $thrown = false;
        try {
            $this->repo->set_status($this->clinicId, 'team_txn_status', 'inactive');
        } catch (\RuntimeException $e) {
            $thrown = true;
        } finally {
            $this->disarmSabotage();
        }

        self::assertSame($before, $this->fetchState('team_txn_status'), 'a failed status write must leave the definition unchanged');
        self::assertTrue($thrown, 'a failed status update must throw and never report success');
    }

    // ------------------------------------------------------------------
    // Success path: atomic persistence of the complete approved set with
    // exactly one version advance per successful replacement.
    // ------------------------------------------------------------------

    public function testSuccessfulReplacementIsAtomicAndAdvancesVersionExactlyOnce(): void
    {
        $this->repo->define($this->clinicId, 'team_txn_ok', ['cpms_patient_read', 'cpms_appt_read']);
        self::assertSame(
            [
                'def'  => ['status' => 'active', 'version' => 1],
                'caps' => ['cpms_appt_read', 'cpms_patient_read'],
            ],
            $this->fetchState('team_txn_ok'),
            'define() must persist exactly the validated set at version 1'
        );

        $this->repo->replace_capabilities($this->clinicId, 'team_txn_ok', ['cpms_visit_read']);
        self::assertSame(
            [
                'def'  => ['status' => 'active', 'version' => 2],
                'caps' => ['cpms_visit_read'],
            ],
            $this->fetchState('team_txn_ok'),
            'a successful replacement must atomically persist the complete approved set and advance the version exactly once'
        );

        $this->repo->replace_capabilities($this->clinicId, 'team_txn_ok', []);
        self::assertSame(
            [
                'def'  => ['status' => 'active', 'version' => 3],
                'caps' => [],
            ],
            $this->fetchState('team_txn_ok'),
            'replacing with the empty approved set must clear capabilities and still advance the version exactly once'
        );
    }
}
