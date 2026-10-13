<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/** Real MySQL enum evolution and fail-closed downgrade, including non-strict SQL. */
final class CustomRoleArchivalMigrationTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private const VERSION = '2026_10_13_0027';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withRealTables(static fn(): array => App::migrations()->migrate());
    }

    protected function tearDown(): void
    {
        try {
            $this->withRealTables(static fn(): array => App::migrations()->migrate());
        } finally {
            parent::tearDown();
        }
    }

    public function testFreshInstallHasTerminalEnumAndNoArchivedAtColumn(): void
    {
        self::assertSame(self::VERSION, App::migrations()->currentVersion());
        $column = $this->statusColumn();
        self::assertSame("enum('active','inactive','archived')", strtolower($column['Type']));
        self::assertSame('NO', $column['Null']);
        self::assertSame('active', $column['Default']);
        self::assertNull(App::db()->fetchRow('SHOW COLUMNS FROM ' . App::db()->table('cpms_custom_role_defs') . " LIKE 'archived_at'"));
        self::assertSame([], App::migrations()->migrate());
    }

    public function testUpgradeFrom0026PreservesRowsColumnAttributesAndEveryIndex(): void
    {
        /** @var list<int> $ids definitions created by this fixture, tracked from creation */
        $ids = [];

        try {
            $this->withRealTables(function () use (&$ids): void {
                $repository = new CustomRoleRepository(App::db());
                $clinic = (int) App::db()->fetchValue('SELECT id FROM ' . App::db()->table('cpms_clinics') . ' ORDER BY id LIMIT 1');
                // Reclaim durable rows of an earlier run of this fixture (the job runs
                // this file in the focused schema step and again in the full suite).
                $this->purgeOwnedRows($ids);
                $ids[] = $repository->define($clinic, 'arc_mig_active', ['cpms_search'], '受付 — نام');
                $ids[] = $repository->define($clinic, 'arc_mig_inactive', [], 'Inactive');
                $repository->set_status($clinic, 'arc_mig_inactive', 'inactive');

                try {
                    try {
                        self::assertSame(self::VERSION, App::migrations()->rollbackOne());
                    } catch (\Throwable $failure) {
                        $causes = [];
                        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) { $causes[] = $cause->getMessage(); }
                        fwrite(STDERR, 'ARCHIVAL_DOWNGRADE_FAILURE_EVIDENCE ' . json_encode([
                            'causes' => $causes, 'status_column' => $this->statusColumn(),
                            'constraints' => App::db()->fetchAll('SELECT tc.CONSTRAINT_NAME, tc.ENFORCED, cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc LEFT JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = %s', [App::db()->table('cpms_custom_role_defs')]),
                        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
                        throw $failure;
                    }
                    self::assertSame('2026_10_11_0026', App::migrations()->currentVersion());
                    $beforeColumn = $this->statusColumn();
                    $beforeIndexes = $this->indexes();
                    $beforeRows = $this->rows($ids);
                    $beforeCaps = App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_custom_role_capabilities') . ' WHERE role_def_id IN (%d, %d) ORDER BY role_def_id, capability', $ids);
                    self::assertSame([self::VERSION], App::migrations()->migrate());
                    $afterColumn = $this->statusColumn();
                    self::assertSame("enum('active','inactive','archived')", strtolower($afterColumn['Type']));
                    unset($beforeColumn['Type'], $afterColumn['Type']);
                    self::assertSame($beforeColumn, $afterColumn, 'all other status attributes must survive MODIFY');
                    self::assertSame($beforeIndexes, $this->indexes(), 'indexes and uniqueness must be untouched');
                    self::assertSame($beforeRows, $this->rows($ids));
                    self::assertSame($beforeCaps, App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_custom_role_capabilities') . ' WHERE role_def_id IN (%d, %d) ORDER BY role_def_id, capability', $ids));
                    self::assertSame([], App::migrations()->migrate());
                } finally {
                    App::migrations()->migrate();
                }
            });
        } finally {
            // Runs even when a definition above threw: no owned row is left behind.
            $this->purgeOwnedRows($ids);
        }
    }

    public function testArchivedRowsRefuseRollbackAndRetainVersionSchemaAndCapabilities(): void
    {
        $this->assertUnsafeRollback(false);
    }

    public function testNonStrictSqlCannotSilentlyConvertArchivedDefinitionsDuringRollback(): void
    {
        $this->assertUnsafeRollback(true);
    }

    private function assertUnsafeRollback(bool $nonStrict): void
    {
        /** @var list<int> $ids definitions created by this fixture, tracked from creation */
        $ids = [];

        try {
            $this->withRealTables(function () use ($nonStrict, &$ids): void {
                $db = App::db();
                $clinic = (int) $db->fetchValue('SELECT id FROM ' . $db->table('cpms_clinics') . ' ORDER BY id LIMIT 1');
                $key = 'arc_mig_reserved_' . bin2hex(random_bytes(3));
                $ids[] = (new CustomRoleRepository($db))->define($clinic, $key, ['cpms_search'], 'Archived definition');
                $id = $ids[0];
                self::assertSame(1, $db->execute('UPDATE ' . $db->table('cpms_custom_role_defs') . ' SET status = %s WHERE id = %d', ['archived', $id]));
                $before = $this->rows([$id]);
                $schema = $this->statusColumn();
                $indexes = $this->indexes();
                $caps = $db->fetchAll('SELECT * FROM ' . $db->table('cpms_custom_role_capabilities') . ' WHERE role_def_id = %d', [$id]);
                $sqlMode = (string) $db->fetchValue('SELECT @@SESSION.sql_mode');

                try {
                    if ($nonStrict) {
                        self::assertTrue($db->query('SET SESSION sql_mode = %s', ['']));
                    }
                    try {
                        App::migrations()->rollbackOne();
                        self::fail('an archived definition must block unsafe downgrade');
                    } catch (\RuntimeException $exception) {
                        self::assertStringContainsString('0027', $exception->getMessage());
                    }
                    self::assertSame(self::VERSION, App::migrations()->currentVersion(), 'failed down must not delete its migration marker');
                    self::assertSame($before, $this->rows([$id]));
                    self::assertSame($schema, $this->statusColumn());
                    self::assertSame($indexes, $this->indexes());
                    self::assertSame($caps, $db->fetchAll('SELECT * FROM ' . $db->table('cpms_custom_role_capabilities') . ' WHERE role_def_id = %d', [$id]));
                } finally {
                    $db->query('SET SESSION sql_mode = %s', [$sqlMode]);
                }
            });
        } finally {
            // Runs even when define() threw: the archived probe row never survives.
            $this->purgeOwnedRows($ids);
        }
    }

    /**
     * Removes only the synthetic definitions this fixture owns: the ids it created
     * plus its two deterministic keys (`arc_mig_active` / `arc_mig_inactive`, proven
     * unique to this file). Archived production rows and every unrelated Clinic row
     * are untouched.
     *
     * Why the explicit COMMITs: the fixture runs real DDL through withRealTables(),
     * and MySQL commits implicitly around DDL, so its rows are durable even though
     * WP_UnitTestCase::tearDown() rolls the test transaction back. A plain DELETE
     * would be rolled back with it and leave exactly the residue that made this
     * file's own `arc_mig_active` pre-check fail and that triggered migration 0027's
     * fail-closed downgrade guard in other suites. Committing the removal is the
     * same explicit idiom other committed-fixture suites already use.
     *
     * @param list<int> $ids
     */
    private function purgeOwnedRows(array $ids): void
    {
        $db       = App::db();
        $defs     = $db->table('cpms_custom_role_defs');
        $caps     = $db->table('cpms_custom_role_capabilities');
        $ownedKey = "role_key IN ('arc_mig_active', 'arc_mig_inactive')";

        $db->query('COMMIT'); // close the suite transaction so the DELETEs below are durable

        foreach ($ids as $id) {
            $db->delete('cpms_custom_role_capabilities', ['role_def_id' => $id]);
            $db->delete('cpms_custom_role_defs', ['id' => $id]);
        }
        $db->query('DELETE FROM ' . $caps . ' WHERE role_def_id IN (SELECT id FROM ' . $defs . ' WHERE ' . $ownedKey . ')');
        $db->query('DELETE FROM ' . $defs . ' WHERE ' . $ownedKey);

        $db->query('COMMIT'); // persist the removal: tearDown() only rolls back
    }

    private function statusColumn(): array
    {
        $column = App::db()->fetchRow('SHOW FULL COLUMNS FROM ' . App::db()->table('cpms_custom_role_defs') . " LIKE 'status'");
        self::assertNotNull($column);
        return $column;
    }

    private function indexes(): array
    {
        return App::db()->fetchAll('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX', [App::db()->table('cpms_custom_role_defs')]);
    }

    private function rows(array $ids): array
    {
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        return App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_custom_role_defs') . ' WHERE id IN (' . $placeholders . ') ORDER BY id', $ids);
    }
}
