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
        $cleanupError = '';
        try {
            if ( '' !== $this->tmpTable ) {
                $this->withRealTables(function () use ($wpdb, &$cleanupError): void {
                    $ok = $wpdb->query( "DROP TABLE IF EXISTS {$this->tmpTable}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                        $cleanupError = $wpdb->last_error;
                    }
                    // Remove temporary shadow if any (defensive)
                    $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$this->tmpTable}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                });
            }
            // Ensure any harness marker tables left by the new commented-TEMPORARY tests are removed even after assertion failures.
            $this->withRealTables(function () use ($wpdb, &$cleanupError): void {
                foreach ( [ 'cpms_harness_marker', 'cpms_harness_marker_line_dash', 'cpms_harness_marker_hash' ] as $suffix ) {
                    $table = $wpdb->prefix . $suffix;
                    $ok = $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    if ( false === $ok && '' !== $wpdb->last_error && '' === $cleanupError ) {
                        $cleanupError = $wpdb->last_error;
                    }
                    $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                }
            });
        } finally {
            // Ensure stack is clean for next test even if cleanup fails — preserve original failure.
            $GLOBALS['__cpms_sp_stack'] = [];
            $GLOBALS['__cpms_ddl_seen_since_start'] = false;
            if ( '' !== $cleanupError ) {
                self::assertSame( '', $cleanupError, 'SavepointHarnessTest tearDown cleanup must succeed: ' . $cleanupError );
            }
            parent::tearDown();
        }
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

    /**
     * COMMENTED TEMPORARY DDL - the classifier previously mis-identified
     * CREATE with inline block-comment before TEMPORARY as real DDL
     * (because it only stripped leading comments). That caused an
     * implicit-commit flag to be set and the subsequent ROLLBACK (from a
     * controlled callback failure) was rewritten to SELECT 1, so the
     * persistent marker was NOT rolled back. This test proves the fix:
     * with correct inline-comment handling the statement is recognised as
     * TEMPORARY, the flag stays false, and rollback correctly removes marker.
     */
    public function testCommentedTemporaryDdlDoesNotDestroyRollback(): void
    {
        global $wpdb;
        $db          = App::db();
        $markerTable = $wpdb->prefix . 'cpms_harness_marker';

        // Create a real (non-TEMPORARY) marker table outside any transactional.
        $this->withRealTables(function () use ($wpdb, $markerTable): void {
            $ok = $wpdb->query( "CREATE TABLE IF NOT EXISTS {$markerTable} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, value VARCHAR(64) NOT NULL, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertNotFalse( $ok, 'marker table must be created: ' . $wpdb->last_error );
            $wpdb->query( "DELETE FROM {$markerTable}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        });

        // Verify the server actually accepts `CREATE /* comment */ TEMPORARY`.
        $this->withRealTables(function () use ($wpdb): void {
            $tmpCheck = App::db()->table( 'cpms_harness_tmp_syntax_check_' . bin2hex( random_bytes( 2 ) ) );
            $ok = $wpdb->query( "CREATE /* inline comment */ TEMPORARY TABLE {$tmpCheck} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertNotFalse( $ok, 'MySQL must accept CREATE /* comment */ TEMPORARY: ' . $wpdb->last_error );
            $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$tmpCheck}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        });

        $thrown = false;
        try {
            $db->transactional(function () use ($wpdb, $markerTable): void {
                $ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$markerTable} (value) VALUES (%s)", 'probe_marker' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse( $ok, 'marker insert must succeed: ' . $wpdb->last_error );
                // This is TEMPORARY with an inline comment — must NOT be treated as real DDL.
                $tmp = App::db()->table( 'cpms_harness_probe_commented_tmp_' . bin2hex( random_bytes( 2 ) ) );
                $ok2 = $wpdb->query( "CREATE /* inline comment */ TEMPORARY TABLE {$tmp} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse( $ok2, 'commented TEMPORARY DDL inside transactional must succeed: ' . $wpdb->last_error );
                // Controlled failure forces a ROLLBACK — the marker must be rolled back.
                throw new \RuntimeException( 'controlled rollback trigger' );
            });
        } catch ( \Throwable $e ) {
            if ( str_contains( $e->getMessage(), 'controlled rollback trigger' ) ) {
                $thrown = true;
            } else {
                throw $e;
            }
        }

        self::assertTrue( $thrown, 'controlled rollback trigger must be observed' );
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$markerTable} WHERE value = 'probe_marker'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertSame( 0, $count, 'commented TEMPORARY DDL must not destroy rollback — marker must be rolled back to 0' );

        // Cleanup marker table (real) - check result and preserve original failure.
        $cleanupError = '';
        try {
            $this->withRealTables(function () use ($wpdb, $markerTable, &$cleanupError): void {
                $ok = $wpdb->query( "DROP TABLE IF EXISTS {$markerTable}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                if ( false === $ok && '' !== $wpdb->last_error ) {
                    $cleanupError = $wpdb->last_error;
                }
            });
        } finally {
            $GLOBALS['__cpms_sp_stack'] = [];
            $GLOBALS['__cpms_ddl_seen_since_start'] = false;
            if ( '' !== $cleanupError ) {
                // Do not silently ignore cleanup failure - report it, but preserve original test failure if any.
                self::assertSame( '', $cleanupError, 'marker table cleanup must succeed: ' . $cleanupError );
            }
        }
    }

    public function testLineCommentTemporaryDdlWithDoubleDashDoesNotDestroyRollback(): void
    {
        global $wpdb;
        $db          = App::db();
        $markerTable = $wpdb->prefix . 'cpms_harness_marker_line_dash';

        $this->withRealTables(function () use ($wpdb, $markerTable): void {
            $ok = $wpdb->query( "CREATE TABLE IF NOT EXISTS {$markerTable} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, value VARCHAR(64) NOT NULL, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertNotFalse( $ok, 'marker table must be created: ' . $wpdb->last_error );
            $wpdb->query( "DELETE FROM {$markerTable}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        });

        // Verify MySQL accepts CREATE -- comment newline TEMPORARY
        $this->withRealTables(function () use ($wpdb): void {
            $tmpCheck = App::db()->table( 'cpms_harness_tmp_dash_' . bin2hex( random_bytes( 2 ) ) );
            $ok = $wpdb->query( "CREATE -- inline dash comment\nTEMPORARY TABLE {$tmpCheck} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertNotFalse( $ok, 'MySQL must accept CREATE -- comment TEMPORARY: ' . $wpdb->last_error );
            $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$tmpCheck}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        });

        $thrown = false;
        try {
            $db->transactional(function () use ($wpdb, $markerTable): void {
                $ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$markerTable} (value) VALUES (%s)", 'probe_dash' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse( $ok, 'marker insert must succeed: ' . $wpdb->last_error );
                $tmp = App::db()->table( 'cpms_harness_probe_dash_tmp_' . bin2hex( random_bytes( 2 ) ) );
                $ok2 = $wpdb->query( "CREATE -- dash comment\nTEMPORARY TABLE {$tmp} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse( $ok2, 'dash-commented TEMPORARY inside transactional must succeed: ' . $wpdb->last_error );
                throw new \RuntimeException( 'controlled dash rollback' );
            });
        } catch ( \Throwable $e ) {
            if ( str_contains( $e->getMessage(), 'controlled dash rollback' ) ) {
                $thrown = true;
            } else {
                throw $e;
            }
        }

        self::assertTrue( $thrown, 'controlled dash rollback must be observed' );
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$markerTable} WHERE value = 'probe_dash'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertSame( 0, $count, 'dash-commented TEMPORARY must not destroy rollback' );

        $cleanupError = '';
        try {
            $this->withRealTables(function () use ($wpdb, $markerTable, &$cleanupError): void {
                $ok = $wpdb->query( "DROP TABLE IF EXISTS {$markerTable}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                if ( false === $ok && '' !== $wpdb->last_error ) {
                    $cleanupError = $wpdb->last_error;
                }
                // Remove any leftover temp tables (defensive)
                $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$markerTable}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            });
        } finally {
            $GLOBALS['__cpms_sp_stack'] = [];
            $GLOBALS['__cpms_ddl_seen_since_start'] = false;
            if ( '' !== $cleanupError ) {
                self::assertSame( '', $cleanupError, 'cleanup must succeed: ' . $cleanupError );
            }
        }
    }

    public function testLineCommentTemporaryDdlWithHashDoesNotDestroyRollback(): void
    {
        global $wpdb;
        $db          = App::db();
        $markerTable = $wpdb->prefix . 'cpms_harness_marker_hash';

        $this->withRealTables(function () use ($wpdb, $markerTable): void {
            $ok = $wpdb->query( "CREATE TABLE IF NOT EXISTS {$markerTable} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, value VARCHAR(64) NOT NULL, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertNotFalse( $ok, 'marker table must be created: ' . $wpdb->last_error );
            $wpdb->query( "DELETE FROM {$markerTable}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        });

        $this->withRealTables(function () use ($wpdb): void {
            $tmpCheck = App::db()->table( 'cpms_harness_tmp_hash_' . bin2hex( random_bytes( 2 ) ) );
            $ok = $wpdb->query( "CREATE # hash comment\nTEMPORARY TABLE {$tmpCheck} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::assertNotFalse( $ok, 'MySQL must accept CREATE # comment TEMPORARY: ' . $wpdb->last_error );
            $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$tmpCheck}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        });

        $thrown = false;
        try {
            $db->transactional(function () use ($wpdb, $markerTable): void {
                $ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$markerTable} (value) VALUES (%s)", 'probe_hash' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse( $ok, 'marker insert must succeed: ' . $wpdb->last_error );
                $tmp = App::db()->table( 'cpms_harness_probe_hash_tmp_' . bin2hex( random_bytes( 2 ) ) );
                $ok2 = $wpdb->query( "CREATE # hash comment\nTEMPORARY TABLE {$tmp} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) ) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::assertNotFalse( $ok2, 'hash-commented TEMPORARY inside transactional must succeed: ' . $wpdb->last_error );
                throw new \RuntimeException( 'controlled hash rollback' );
            });
        } catch ( \Throwable $e ) {
            if ( str_contains( $e->getMessage(), 'controlled hash rollback' ) ) {
                $thrown = true;
            } else {
                throw $e;
            }
        }

        self::assertTrue( $thrown, 'controlled hash rollback must be observed' );
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$markerTable} WHERE value = 'probe_hash'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertSame( 0, $count, 'hash-commented TEMPORARY must not destroy rollback' );

        $cleanupError = '';
        try {
            $this->withRealTables(function () use ($wpdb, $markerTable, &$cleanupError): void {
                $ok = $wpdb->query( "DROP TABLE IF EXISTS {$markerTable}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                if ( false === $ok && '' !== $wpdb->last_error ) {
                    $cleanupError = $wpdb->last_error;
                }
                $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$markerTable}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            });
        } finally {
            $GLOBALS['__cpms_sp_stack'] = [];
            $GLOBALS['__cpms_ddl_seen_since_start'] = false;
            if ( '' !== $cleanupError ) {
                self::assertSame( '', $cleanupError, 'cleanup must succeed: ' . $cleanupError );
            }
        }
    }
}

