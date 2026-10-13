<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Tests\Support\ArchivalRaceWorker;
use PHPUnit\Framework\AssertionFailedError;
use WP_UnitTestCase;

require_once dirname(__DIR__) . '/Support/ArchivalRaceWorker.php';

/**
 * Fixture ownership of the archival race suite.
 *
 * The race creates committed synthetic fixtures on purpose (an independent
 * MySQL session can only see committed rows) and removes them in tearDown().
 * This test pins that contract with real rows: an owned row is counted by the
 * exact key it was created with, a lookalike row with the same shape but a
 * different owner is never counted, and unrelated users and tables survive
 * untouched.
 *
 * Real tables versus the WordPress test framework:
 *   The suite hooks two `query` filters (`_create_temporary_tables` /
 *   `_drop_temporary_tables`, see TempTableIsolationTest) that rewrite
 *   `CREATE TABLE` into `CREATE TEMPORARY TABLE` and `DROP TABLE` into
 *   `DROP TEMPORARY TABLE`. A real table therefore CANNOT be dropped through
 *   $wpdb: the rewritten statement is a no-op for it and the table silently
 *   survives (this suite's earlier cleanup had exactly that defect). Tables
 *   created on an independent mysqli session are real tables that belong to that
 *   session, so they are also created and dropped there — no query filter is
 *   involved, and none is disabled. RealTableMigrations::withRealTables() is the
 *   repo's alternative mechanism for real DDL through $wpdb; it is deliberately
 *   not used here, because these fixtures are owned by an independent session,
 *   not by $wpdb.
 *
 * Every descriptor of state this test creates is tracked at creation time, so a
 * failure anywhere in the body still cleans up completely: synthetic users are
 * tracked before they are used, real tables and session tables are tracked
 * separately, and control objects (which must survive the fixture cleanup) are
 * tracked in their own list and removed last.
 */
final class ArchivalRaceFixtureOwnershipTest extends WP_UnitTestCase
{
    /**
     * Real tables created on independent sessions; dropped on that kind of
     * session in tearDown().
     *
     * @var list<string>
     */
    private array $realFixtureTables = [];

    /**
     * Tables created through $wpdb. The suite's filters turn these into
     * TEMPORARY tables on the $wpdb session, which is why they are dropped
     * through $wpdb as well.
     *
     * @var list<string>
     */
    private array $sessionTables = [];

    /**
     * Real tables that exist to prove unrelated objects survive the fixture
     * cleanup. Removed last, after that proof has been asserted.
     *
     * @var list<string>
     */
    private array $controlTables = [];

    /**
     * Every synthetic WordPress user this test created, tracked at creation so a
     * partially initialized fixture is cleaned up too.
     *
     * @var list<int>
     */
    private array $createdUsers = [];

    protected function tearDown(): void
    {
        $failures = $this->removeOwnedFixtures();

        if ($failures !== []) {
            // Evidence channel: CI mirrors STDERR, so a cleanup defect is visible
            // even when the test body already failed for another reason.
            fwrite(STDERR, PHP_EOL . 'CPMS_FIXTURE_CLEANUP_FAILED ' . json_encode($failures, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }

        parent::tearDown();

        if ($failures !== []) {
            $this->reportCleanupFailures($failures);
        }
    }

    public function testOwnedRowsAreCountedByKeyAndLookalikesArePreserved(): void
    {
        global $wpdb;
        $prefix = (string) $wpdb->prefix;
        $markerKey = 'cpms_fixture_owner';

        // An unrelated, pre-existing object that must survive everything below.
        // The framework itself preserves user 1 (tests/phpunit/includes/functions.php).
        $unrelatedUserId = 1;
        self::assertNotFalse(get_userdata($unrelatedUserId), 'the suite must have its pre-existing administrator');

        $ownedTable = $this->createFixtureTable($prefix . 'unit_race_fixture_owned_' . bin2hex(random_bytes(4)));
        $ownerKey = 'cpms_owned_' . bin2hex(random_bytes(4));

        // The owned fixtures, committed like the race's real fixtures are.
        self::assertNotFalse($wpdb->query('COMMIT'), 'independent sessions require committed fixtures');
        $ownedUserId = $this->trackUser((int) self::factory()->user->create(['user_login' => 'cpms_owner_' . bin2hex(random_bytes(3))]));
        add_user_meta($ownedUserId, $markerKey, $ownerKey, true);
        add_user_meta($ownedUserId, 'session_tokens', ['unit' => 'fixture'], true); // system-shaped row: must go with the user, not be counted
        self::assertNotFalse($wpdb->query($wpdb->prepare('INSERT INTO `' . $ownedTable . '` (owner_key) VALUES (%s)', $ownerKey)));
        self::assertNotFalse($wpdb->query($wpdb->prepare('INSERT INTO `' . $ownedTable . '` (owner_key) VALUES (%s)', $ownerKey)));

        // Lookalikes: same shapes, different owners, committed from a parallel
        // session so they are genuinely unrelated to this test's transaction.
        $lookalike = $this->freshMysqli();
        $lookalikeTable = $this->createRealTable(
            $lookalike,
            $prefix . 'unit_race_fixture_lookalike_' . bin2hex(random_bytes(4)),
            $this->realFixtureTables
        );
        self::assertNotFalse($lookalike->query('COMMIT'), 'parallel session: ' . (string) $lookalike->error);
        self::assertNotFalse($lookalike->query('INSERT INTO `' . $lookalikeTable . '` (owner_key) VALUES (\'cpms_lookalike_a\')'), (string) $lookalike->error);
        self::assertNotFalse($lookalike->query('INSERT INTO `' . $lookalikeTable . '` (owner_key) VALUES (\'cpms_lookalike_b\')'), (string) $lookalike->error);
        self::assertNotFalse($lookalike->query('INSERT INTO `' . $lookalikeTable . '` (owner_key) VALUES (\'cpms_lookalike_c\')'), (string) $lookalike->error);

        // An unrelated control object: a real table, on a session of its own.
        $control = $this->freshMysqli();
        $controlTable = $this->createRealTable(
            $control,
            $prefix . 'unit_race_fixture_control_' . bin2hex(random_bytes(4)),
            $this->controlTables
        );

        $markerUsers = [];
        foreach (['cpms_marker_a', 'cpms_marker_b'] as $login) {
            $userId = $this->trackUser((int) self::factory()->user->create(['user_login' => $login . '_' . bin2hex(random_bytes(3))]));
            add_user_meta($userId, $markerKey, 'cpms_lookalike_owner');
            $markerUsers[] = $userId;
        }
        $unmarkedUserId = $this->trackUser((int) self::factory()->user->create(['user_login' => 'cpms_unmarked_' . bin2hex(random_bytes(3))]));
        self::assertNotFalse($wpdb->query('COMMIT'));

        // Ownership probe: exactly the owned user, counted by the marker the
        // fixture writes, with its id and login.
        $keys = ArchivalRaceWorker::ownershipKeys($lookalike, $prefix, (string) get_userdata($ownedUserId)->user_login);
        self::assertIsArray($keys);
        self::assertCount(1, $keys);
        self::assertSame($ownedUserId, $keys[0]['id']);
        self::assertSame(1, $keys[0]['user_rows']);
        self::assertSame(1, $keys[0]['usermeta_rows'], 'only the marker row identifies ownership');

        $ownedRowsFor = static function (string $table, string $key): int {
            global $wpdb;

            return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE owner_key = %s', $key));
        };
        self::assertSame(2, $ownedRowsFor($ownedTable, $ownerKey));
        self::assertSame(3, $ownedRowsFor($lookalikeTable, 'cpms_lookalike_a')
            + $ownedRowsFor($lookalikeTable, 'cpms_lookalike_b')
            + $ownedRowsFor($lookalikeTable, 'cpms_lookalike_c'));

        // Cleanup, exactly as the race performs it: by key, scoped, and verified.
        self::assertNotFalse($wpdb->query($wpdb->prepare(
            'DELETE FROM `' . $prefix . 'usermeta` WHERE user_id = %d AND meta_key = %s',
            $ownedUserId,
            $markerKey
        )));
        self::assertNotFalse($wpdb->query($wpdb->prepare('DELETE FROM `' . $ownedTable . '` WHERE owner_key = %s', $ownerKey)));
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        wp_delete_user($ownedUserId);
        self::assertNotFalse($wpdb->query('COMMIT'), 'fixture removal must be durable');

        self::assertSame(0, $ownedRowsFor($ownedTable, $ownerKey), 'owned rows are gone');
        self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $prefix . 'usermeta` WHERE user_id = %d', $ownedUserId)));
        self::assertFalse(get_userdata($ownedUserId) !== false, 'the owned user must be gone, not merely renamed');

        // Nothing else was touched: the lookalike table keeps its rows, the
        // marker-bearing users keep their rows, and the unmarked user survives.
        self::assertSame(3, (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $lookalikeTable . '`'));
        foreach ($markerUsers as $userId) {
            self::assertNotFalse(get_userdata($userId), 'an unrelated marker-bearing user must survive');
            self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM `' . $prefix . 'usermeta` WHERE user_id = %d AND meta_key = %s',
                $userId,
                $markerKey
            )));
        }
        self::assertNotFalse(get_userdata($unmarkedUserId), 'an unrelated unmarked user must survive');
        self::assertNotFalse(get_userdata($unrelatedUserId), 'a pre-existing user must survive');
        self::assertTrue($this->realTableExists($control, $controlTable), 'an unrelated real table must survive');

        $lookalike->close();
        $control->close();
    }

    /**
     * The lookalike must be a real table, not a session-local temporary one:
     * another connection sees it, and the data dictionary lists it. A temporary
     * table would satisfy neither check.
     */
    public function testRealLookalikeTableIsVisibleToAnotherSessionAndInTheDataDictionary(): void
    {
        global $wpdb;
        $owner = $this->freshMysqli();
        $observer = $this->freshMysqli();

        try {
            $table = $this->createRealTable(
                $owner,
                (string) $wpdb->prefix . 'unit_race_fixture_realness_' . bin2hex(random_bytes(4)),
                $this->realFixtureTables
            );

            self::assertTrue($this->realTableExists($observer, $table), 'a real table is visible to another connection');
            self::assertTrue($this->inDataDictionary($observer, $table), 'a real table is listed in information_schema');
            self::assertStringStartsWith(
                'CREATE TABLE',
                $this->createStatement($observer, $table),
                'the data dictionary must describe a base table, not a temporary one'
            );

            // The contrast the defect depended on: a table created through $wpdb
            // in this suite is temporary — it exists only on the $wpdb session and
            // never appears in the data dictionary (the idiom TempTableIsolationTest
            // establishes for this distinction).
            $sessionTable = $this->createFixtureTable((string) $wpdb->prefix . 'unit_race_fixture_temp_' . bin2hex(random_bytes(4)));
            $row = $wpdb->get_row('SHOW CREATE TABLE `' . $sessionTable . '`', ARRAY_A);
            self::assertStringContainsString(
                'TEMPORARY',
                (string) ($row['Create Table'] ?? ''),
                'the suite rewrites CREATE TABLE through wpdb into a temporary table'
            );
            self::assertFalse($this->inDataDictionary($owner, $sessionTable), 'a temporary table is not in the data dictionary');
            self::assertFalse($this->inDataDictionary($observer, $sessionTable), 'a temporary table is invisible to another connection');
        } finally {
            $owner->close();
            $observer->close();
        }
    }

    /**
     * A surviving real table must be detected, never assumed gone: this is the
     * check whose absence let the defective cleanup report success.
     */
    public function testSurvivingRealTableIsDetectedNotAssumedGone(): void
    {
        global $wpdb;
        $conn = $this->freshMysqli();

        try {
            $table = $this->createRealTable(
                $conn,
                (string) $wpdb->prefix . 'unit_race_fixture_survivor_' . bin2hex(random_bytes(4)),
                $this->realFixtureTables
            );

            // The structured report must name the surviving table ...
            $failures = $this->assertRealTableAbsentFailures($conn, $table);
            self::assertNotSame([], $failures, 'a surviving real table must be reported, never assumed gone');
            self::assertStringContainsString($table, implode(' ', $failures));

            // ... and the verification built on it must actually fail, so a
            // surviving table can never leave the test green.
            $detected = false;
            try {
                $this->assertRealTableAbsent($conn, $table);
            } catch (AssertionFailedError $detectedFailure) {
                $detected = true;
            }
            self::assertTrue($detected, 'a real table that still exists must fail verification');

            // The same check must pass after a real removal.
            self::assertNotFalse($conn->query('DROP TABLE `' . $table . '`'), 'drop: ' . (string) $conn->error);
            $this->assertRealTableAbsent($conn, $table);
        } finally {
            $conn->close();
        }
    }

    /**
     * Cleanup failures are reported through an assertion that names the cause and
     * states that the test body's own result is unaffected, so a body failure and
     * a cleanup failure are both visible instead of one hiding the other.
     */
    public function testCleanupFailureIsReportedWithTheBodyResultKeptVisible(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/body result is unaffected/');
        $this->reportCleanupFailures(['real fixture table still present: unit_race_fixture_example']);
    }

    /**
     * Synthetic users are tracked when they are created and verified gone after
     * removal, from this session and from an independent one.
     */
    public function testEverySyntheticUserIsRemovedAndVerifiedFromAnIndependentSession(): void
    {
        global $wpdb;
        $userId = $this->trackUser((int) self::factory()->user->create(['user_login' => 'cpms_partial_' . bin2hex(random_bytes(3))]));
        self::assertContains($userId, $this->createdUsers, 'a created synthetic user must be tracked immediately');

        $failures = $this->removeOwnedFixtures();
        self::assertSame([], $failures, 'cleanup of a tracked synthetic user must not fail: ' . implode(', ', $failures));

        self::assertFalse(get_userdata($userId) !== false, 'the synthetic user must be gone');
        self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $wpdb->users . '` WHERE ID = %d', $userId)));
        self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $wpdb->usermeta . '` WHERE user_id = %d', $userId)));

        $independent = $this->freshMysqli();
        try {
            self::assertSame(0, $this->countOn($independent, 'SELECT COUNT(*) AS n FROM `' . $wpdb->users . '` WHERE ID = ' . $userId), 'an independent session must not see the user either');
            self::assertNotFalse(get_userdata(1), 'the pre-existing administrator must survive');
        } finally {
            $independent->close();
        }
    }

    public function testOwnershipProbeReturnsNullForAnUnknownLogin(): void
    {
        global $wpdb;
        $conn = $this->freshMysqli();
        try {
            self::assertNull(ArchivalRaceWorker::ownershipKeys($conn, (string) $wpdb->prefix, 'cpms_no_such_login'));
        } finally {
            $conn->close();
        }
    }

    /**
     * Removes every fixture object this test owns, in dependency order, and
     * verifies the removal.
     *
     * Real fixture tables are dropped on an independent mysqli session — the same
     * kind of session that created them — so the WordPress temporary-table
     * rewrite cannot turn the DROP into a no-op. The statement is plain
     * `DROP TABLE` (not `IF EXISTS`), its result and the MySQL error are checked,
     * and absence is then proven three ways: no row in information_schema, not
     * visible to a fresh independent session, and no longer present on the
     * dropping session. Note that DDL performs an implicit commit: that is why
     * nothing here runs inside a transaction.
     *
     * @return list<string> failure descriptions; empty means fully cleaned up
     */
    private function removeOwnedFixtures(): array
    {
        global $wpdb;
        $failures = [];

        // 1) Synthetic users, before anything that might reference them.
        if ($this->createdUsers !== []) {
            if (!function_exists('wp_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            foreach ($this->createdUsers as $userId) {
                if (get_userdata($userId) !== false && !wp_delete_user($userId)) {
                    $failures[] = 'user_delete_failed:' . $userId;
                }
            }
            if ($wpdb->query('COMMIT') === false) {
                $failures[] = 'commit_failed:users';
            }
            foreach ($this->createdUsers as $userId) {
                if (get_userdata($userId) !== false) {
                    $failures[] = 'user_survives:' . $userId;
                }
                if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $wpdb->usermeta . '` WHERE user_id = %d', $userId)) !== 0) {
                    $failures[] = 'usermeta_survives:' . $userId;
                }
            }
            $this->createdUsers = [];
        }

        $independent = null;
        try {
            $independent = $this->freshMysqli();
        } catch (\Throwable $noSession) {
            if ($this->realFixtureTables !== [] || $this->controlTables !== []) {
                $failures[] = 'independent_session_unavailable';
            }
        }

        if ($independent instanceof \mysqli) {
            // 2) Real fixture tables, then 3) the control tables (removed last).
            foreach (array_merge($this->realFixtureTables, $this->controlTables) as $table) {
                foreach ($this->dropRealTable($independent, $table) as $failure) {
                    $failures[] = $failure;
                }
            }
            foreach ($this->realFixtureTables as $table) {
                foreach ($this->assertRealTableAbsentFailures($independent, $table) as $failure) {
                    $failures[] = $failure;
                }
            }
            $this->realFixtureTables = [];
            $this->controlTables = [];
            $independent->close();
        }

        // 4) Session tables created through $wpdb: temporary, so they are dropped
        // through $wpdb (the rewrite is correct for them) and verified there.
        foreach ($this->sessionTables as $table) {
            if ($wpdb->get_var('SHOW TABLES LIKE \'' . $table . '\'') === null) {
                continue; // never created, or already dropped: nothing to remove
            }
            if ($wpdb->query('DROP TABLE `' . $table . '`') === false) {
                $failures[] = 'session_table_drop_failed:' . $table . ':' . (string) $wpdb->last_error;
            }
            if ($wpdb->get_var('SHOW TABLES LIKE \'' . $table . '\'') !== null) {
                $failures[] = 'session_table_survives:' . $table;
            }
        }
        $this->sessionTables = [];

        return $failures;
    }

    /**
     * Drops one real table on an independent session, checking the statement
     * result and the MySQL error, then verifying absence.
     *
     * @return list<string>
     */
    private function dropRealTable(\mysqli $conn, string $table): array
    {
        $failures = [];

        if (!$this->realTableExists($conn, $table)) {
            return $failures; // partially initialized fixture: nothing was created
        }
        if (!$conn->query('DROP TABLE `' . $table . '`')) {
            $failures[] = 'real_table_drop_failed:' . $table . ':errno=' . $conn->errno . ':error=' . $conn->error;
        }

        return array_merge($failures, $this->assertRealTableAbsentFailures($conn, $table));
    }

    /**
     * Absence proof for a real table: absent from the data dictionary, invisible
     * to a fresh independent session, and gone from the session that dropped it.
     *
     * @return list<string>
     */
    private function assertRealTableAbsentFailures(\mysqli $conn, string $table): array
    {
        $failures = [];
        if ($this->inDataDictionary($conn, $table)) {
            $failures[] = 'real_table_in_data_dictionary:' . $table;
        }
        if ($this->realTableExists($conn, $table)) {
            $failures[] = 'real_table_visible_on_dropping_session:' . $table;
        }
        $observer = $this->freshMysqli();
        try {
            if ($this->tableExists($observer, $table) || $this->inDataDictionary($observer, $table)) {
                $failures[] = 'real_table_visible_to_independent_session:' . $table;
            }
        } finally {
            $observer->close();
        }

        return $failures;
    }

    private function assertRealTableAbsent(\mysqli $conn, string $table): void
    {
        self::assertSame(
            [],
            $this->assertRealTableAbsentFailures($conn, $table),
            'the real table must be gone, verified from an independent session too'
        );
    }

    /**
     * Reports cleanup failures without hiding the test body's own result.
     *
     * @param list<string> $failures
     */
    private function reportCleanupFailures(array $failures): void
    {
        self::fail(
            'archival race fixture cleanup failed ('
            . implode('; ', $failures)
            . '); the test body result is unaffected and is reported separately'
        );
    }

    private function createRealTable(\mysqli $conn, string $table, array &$tracked): string
    {
        self::assertFalse($this->realTableExists($conn, $table), 'the real fixture table must not exist yet');
        self::assertFalse($this->inDataDictionary($conn, $table), 'the real fixture table must not exist yet (data dictionary)');
        self::assertNotFalse(
            $conn->query(
                'CREATE TABLE `' . $table . '` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, owner_key VARCHAR(64) NOT NULL) ENGINE=InnoDB'
            ),
            'real table creation: ' . (string) $conn->error
        );
        $tracked[] = $table;

        return $table;
    }

    private function trackUser(int $userId): int
    {
        self::assertGreaterThan(0, $userId);
        $this->createdUsers[] = $userId;

        return $userId;
    }

    private function tableExists(\mysqli $conn, string $table): bool
    {
        $result = $conn->query('SHOW TABLES LIKE \'' . $conn->real_escape_string($table) . '\'');

        return $result instanceof \mysqli_result && $result->num_rows > 0;
    }

    /**
     * A temporary table is session-local and never appears in the data
     * dictionary, so this is the decisive real/non-real check.
     */
    private function realTableExists(\mysqli $conn, string $table): bool
    {
        return $this->inDataDictionary($conn, $table);
    }

    private function inDataDictionary(\mysqli $conn, string $table): bool
    {
        return $this->countOn(
            $conn,
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \''
            . $conn->real_escape_string($table) . '\''
        ) > 0;
    }

    private function createStatement(\mysqli $conn, string $table): string
    {
        $result = $conn->query('SHOW CREATE TABLE `' . $table . '`');
        if (!$result instanceof \mysqli_result) {
            return '';
        }
        $row = $result->fetch_row();

        return is_array($row) && isset($row[1]) ? (string) $row[1] : '';
    }

    private function countOn(\mysqli $conn, string $sql): int
    {
        $result = $conn->query($sql);
        if (!$result instanceof \mysqli_result) {
            return 0;
        }
        $row = $result->fetch_row();

        return is_array($row) ? (int) $row[0] : 0;
    }

    private function createFixtureTable(string $table): string
    {
        global $wpdb;
        self::assertNull($wpdb->get_var('SHOW TABLES LIKE \'' . $table . '\''), 'fixture table must not already exist');
        self::assertNotFalse($wpdb->query(
            'CREATE TABLE `' . $table . '` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, owner_key VARCHAR(64) NOT NULL) ENGINE=InnoDB'
        ));
        $this->sessionTables[] = $table;

        return $table;
    }

    private function freshMysqli(): \mysqli
    {
        $host = (string) DB_HOST;
        $port = null;
        if (str_contains($host, ':')) {
            [$host, $portPart] = explode(':', $host, 2);
            if (ctype_digit($portPart)) {
                $port = (int) $portPart;
            }
        }
        $conn = new \mysqli($host, (string) DB_USER, (string) DB_PASSWORD, (string) DB_NAME, $port);
        self::assertSame(0, $conn->connect_errno, 'the ownership probe needs its own connection: ' . (string) $conn->connect_error);

        return $conn;
    }
}
