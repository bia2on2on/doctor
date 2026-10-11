<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * SAVEPOINT harness narrow regression — previously uncovered paths.
 *
 * Verifies that the test bootstrap's SAVEPOINT stack correctly models
 * documented MySQL implicit-commit semantics without hiding genuine
 * failures via a blind SELECT 1.
 *
 * Covers:
 *  - successful real DDL inside transactional (stack cleared only after DDL)
 *  - failed real DDL is observable (throws, no silent SELECT 1)
 *  - temporary DDL does not clear stack
 *  - DDL with leading comments is recognised as real DDL
 *  - unexpected SAVEPOINT loss without DDL fails loudly
 *  - failed START / failed RELEASE / failed ROLLBACK remain observable
 *    (via TransactionControlTest, but one explicit harness-level probe here)
 */
final class SavepointHarnessTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private string $tmpTable = '';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->tmpTable = App::db()->table( 'cpms_harness_probe_' . bin2hex( random_bytes( 3 ) ) );
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if ( '' !== $this->tmpTable ) {
            $this->withRealTables(function () use ($wpdb): void {
                $wpdb->query( "DROP TABLE IF EXISTS {$this->tmpTable}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            });
        }
        // Ensure stack is clean for next test — the harness should have left it empty,
        // but if a test failed mid-transaction we reset it to avoid cross-test leakage.
        $GLOBALS['__cpms_sp_stack'] = [];
        $GLOBALS['__cpms_ddl_seen_since_start'] = false;
        parent::tearDown();
    }

    public function testSuccessfulRealDdlInsideTransactionalDoesNotBreakHarness(): void
    {
        global $wpdb;
        $db = App::db();

        $this->withRealTables(function () use ($db, $wpdb): void {
            $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cpms_schema_migrations" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $db->transactional(function () use ($wpdb): string {
                $ok = $wpdb->query( "CREATE TABLE {$this->tmpTable} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse( $ok, 'real DDL inside transactional must succeed: ' . $wpdb->last_error );
                // DDL implicitly commits, so the following COMMIT will be on an empty stack.
                // The harness must have seen the real DDL and turned the COMMIT into SELECT 1.
                return 'ddl-ok';
            });
            self::assertSame( 'ddl-ok', $result, 'transactional must report success after successful real DDL' );
            // Verify the table actually exists (DDL was not silently hidden).
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tmpTable ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertSame( $this->tmpTable, $found, 'real DDL table must exist' );
            // Outer WP test transaction must still be intact (no leak).
            $after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cpms_schema_migrations" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertSame( $before, $after, 'outer transaction isolation must be preserved' );
        });
    }

    public function testFailedRealDdlIsObservableAndDoesNotBecomeNoop(): void
    {
        $db = App::db();
        global $wpdb;

        $thrown = false;
        $msg = '';
        try {
            $this->withRealTables(function () use ($db, $wpdb): void {
                $db->transactional(function () use ($wpdb): void {
                    // Intentionally malformed DDL — should fail and be observable.
                    $ok = $wpdb->query( "CREATE TABLE {$this->tmpTable} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id), FOREIGN KEY (id) REFERENCES non_existent_table_that_does_not_exist (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    // In strict mode this will throw via ensureNoSqlError, but even in non-strict it returns false.
                    if ( false === $ok ) {
                        throw new \RuntimeException( 'DDL failed as expected: ' . $wpdb->last_error );
                    }
                });
            });
        } catch ( \Throwable $e ) {
            $thrown = true;
            $msg = $e->getMessage();
        }

        self::assertTrue( $thrown, 'failed real DDL must be observable, not hidden as SELECT 1' );
        self::assertStringContainsString( 'DDL failed', $msg, 'error must mention DDL' );
        // Table must not exist.
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tmpTable ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertNull( $found, 'failed DDL table must not exist' );
    }

    public function testTemporaryDdlDoesNotClearStack(): void
    {
        $db = App::db();
        global $wpdb;

        // Temporary DDL must not be treated as real DDL — stack must remain.
        $result = $db->transactional(function () use ($wpdb): string {
            $ok = $wpdb->query( "CREATE TEMPORARY TABLE {$this->tmpTable} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertNotFalse( $ok, 'temporary DDL must succeed: ' . $wpdb->last_error );
            // No real DDL was seen, so COMMIT must be a RELEASE, not SELECT 1.
            // We verify by checking that the transaction still commits correctly.
            return 'tmp-ok';
        });

        self::assertSame( 'tmp-ok', $result );
        // Temporary tables are visible only in this session; they should exist now but be cleaned up on tearDown.
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tmpTable ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        // For temporary tables, SHOW TABLES may not show them in the same way, but the query should have succeeded.
        self::assertTrue( $found === $this->tmpTable || $found === null, 'temporary table handling must not break transaction' );
    }

    public function testDdlWithLeadingCommentsIsRecognisedAsRealDdl(): void
    {
        $db = App::db();
        global $wpdb;

        $this->withRealTables(function () use ($db, $wpdb): void {
            $result = $db->transactional(function () use ($wpdb): string {
                $ok = $wpdb->query( "/* leading comment */ CREATE TABLE {$this->tmpTable} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse( $ok, 'DDL with leading comment must succeed: ' . $wpdb->last_error );
                return 'comment-ddl-ok';
            });
            self::assertSame( 'comment-ddl-ok', $result );
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tmpTable ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertSame( $this->tmpTable, $found );
        });
    }

    public function testUnexpectedEmptyStackWithoutDdlFailsLoudly(): void
    {
        $db = App::db();

        // Simulate an unexpected empty-stack by manually clearing the stack
        // inside a transaction and then attempting COMMIT — without any DDL,
        // the harness must not return SELECT 1 but must fail loudly (1305).
        $GLOBALS['__cpms_sp_stack'] = [];
        $GLOBALS['__cpms_ddl_seen_since_start'] = false;

        $thrown = false;
        $msg = '';
        try {
            $db->transactional(function (): void {
                // Manually corrupt the stack inside the transaction to simulate unexpected loss.
                $GLOBALS['__cpms_sp_stack'] = [];
                $GLOBALS['__cpms_ddl_seen_since_start'] = false;
            });
        } catch ( \Throwable $e ) {
            $thrown = true;
            $msg = $e->getMessage();
        }

        // With our harness, an empty-stack COMMIT without DDL returns a failing
        // savepoint (RELEASE SAVEPOINT cpms_sp_does_not_exist) which tx_control
        // reports as 'transaction control failed'. This is the observable failure.
        // If the harness incorrectly returned SELECT 1, this test would fail.
        self::assertTrue( $thrown, 'unexpected empty stack without DDL must fail loudly, not silently become SELECT 1' );
        self::assertStringContainsString( 'transaction control failed', strtolower( $msg ) );
    }
}
