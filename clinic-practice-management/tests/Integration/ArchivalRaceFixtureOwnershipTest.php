<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Tests\Support\ArchivalRaceWorker;
use WP_UnitTestCase;

require_once dirname(__DIR__) . '/Support/ArchivalRaceWorker.php';

/**
 * Fixture ownership of the archival race suite.
 *
 * The race creates committed synthetic fixtures on purpose (an independent
 * MySQL session can only see committed rows) and removes them in tearDown().
 * This test pins that contract: an owned row is counted by the exact key it was
 * created with, a lookalike row with the same shape but a different owner is
 * never counted, and unrelated users and tables survive untouched.
 *
 * It proves the *counting and scoping* layer of the cleanup with real rows. The
 * deletion order and its durability are exercised where the fixtures actually
 * are created: CustomRoleArchivalConcurrencyTest, which asserts after each case
 * that every tracked row is gone.
 */
final class ArchivalRaceFixtureOwnershipTest extends WP_UnitTestCase
{
    /** @var list<string> */
    private array $createdTables = [];

    /** @var list<int> */
    private array $createdUsers = [];

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->createdTables as $table) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->createdTables = [];

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        foreach ($this->createdUsers as $userId) {
            if (get_userdata($userId) !== false) {
                wp_delete_user($userId);
            }
        }
        $this->createdUsers = [];

        parent::tearDown();
    }

    public function testOwnedRowsAreCountedByKeyAndLookalikesArePreserved(): void
    {
        global $wpdb;
        $prefix = (string) $wpdb->prefix;
        $markerKey = 'cpms_fixture_owner';

        $ownedTable = $this->createFixtureTable($prefix . 'unit_race_fixture_owned_' . bin2hex(random_bytes(4)));
        $ownerKey = 'cpms_owned_' . bin2hex(random_bytes(4));

        // The owned fixtures, committed like the race's real fixtures are.
        self::assertNotFalse($wpdb->query('COMMIT'), 'independent sessions require committed fixtures');
        $ownedUserId = (int) self::factory()->user->create(['user_login' => 'cpms_owner_' . bin2hex(random_bytes(3))]);
        self::assertGreaterThan(0, $ownedUserId);
        $this->createdUsers[] = $ownedUserId;
        add_user_meta($ownedUserId, $markerKey, $ownerKey, true);
        add_user_meta($ownedUserId, 'session_tokens', ['unit' => 'fixture'], true); // system-shaped row: must go with the user, not be counted
        self::assertNotFalse($wpdb->query($wpdb->prepare('INSERT INTO `' . $ownedTable . '` (owner_key) VALUES (%s)', $ownerKey)));
        self::assertNotFalse($wpdb->query($wpdb->prepare('INSERT INTO `' . $ownedTable . '` (owner_key) VALUES (%s)', $ownerKey)));

        // Lookalikes: same shapes, different owners, committed from a parallel
        // session so they are genuinely unrelated to this test's transaction.
        // The table itself is created on that session: the suite's query filter
        // rewrites CREATE TABLE into a session-scoped temporary table, so one
        // made through wpdb would be invisible to another connection.
        $lookalike = $this->freshMysqli();
        $lookalikeTable = $prefix . 'unit_race_fixture_lookalike_' . bin2hex(random_bytes(4));
        self::assertFalse($this->tableExists($lookalike, $lookalikeTable), 'the lookalike table must not exist yet');
        self::assertNotFalse(
            $lookalike->query(
                'CREATE TABLE `' . $lookalikeTable . '` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, owner_key VARCHAR(64) NOT NULL) ENGINE=InnoDB'
            ),
            'lookalike table creation: ' . (string) $lookalike->error
        );
        $this->createdTables[] = $lookalikeTable;
        self::assertNotFalse($lookalike->query('COMMIT'), 'parallel session: ' . (string) $lookalike->error);
        self::assertNotFalse($lookalike->query('INSERT INTO `' . $lookalikeTable . '` (owner_key) VALUES (\'cpms_lookalike_a\')'), (string) $lookalike->error);
        self::assertNotFalse($lookalike->query('INSERT INTO `' . $lookalikeTable . '` (owner_key) VALUES (\'cpms_lookalike_b\')'), (string) $lookalike->error);
        self::assertNotFalse($lookalike->query('INSERT INTO `' . $lookalikeTable . '` (owner_key) VALUES (\'cpms_lookalike_c\')'), (string) $lookalike->error);
        $markerUsers = [];
        foreach (['cpms_marker_a', 'cpms_marker_b'] as $login) {
            $userId = (int) self::factory()->user->create(['user_login' => $login . '_' . bin2hex(random_bytes(3))]);
            self::assertGreaterThan(0, $userId);
            add_user_meta($userId, $markerKey, 'cpms_lookalike_owner');
            $markerUsers[] = $userId;
        }
        $unmarkedUserId = (int) self::factory()->user->create(['user_login' => 'cpms_unmarked_' . bin2hex(random_bytes(3))]);
        self::assertGreaterThan(0, $unmarkedUserId);
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

        wp_delete_user($unmarkedUserId);
        foreach ($markerUsers as $userId) {
            wp_delete_user($userId);
        }
        $lookalike->close();
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

    private function tableExists(\mysqli $conn, string $table): bool
    {
        $result = $conn->query('SHOW TABLES LIKE \'' . $conn->real_escape_string($table) . '\'');

        return $result instanceof \mysqli_result && $result->num_rows > 0;
    }

    private function createFixtureTable(string $table): string
    {
        global $wpdb;
        self::assertNull($wpdb->get_var('SHOW TABLES LIKE \'' . $table . '\''), 'fixture table must not already exist');
        self::assertNotFalse($wpdb->query(
            'CREATE TABLE `' . $table . '` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, owner_key VARCHAR(64) NOT NULL) ENGINE=InnoDB'
        ));
        $this->createdTables[] = $table;

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
