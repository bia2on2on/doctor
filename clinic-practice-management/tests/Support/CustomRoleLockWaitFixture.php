<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

use ClinicCore\Bootstrap\App;
use ClinicCore\Application\Roles\CustomRoleAssignmentService;
use RuntimeException;

/**
 * Slice 4B reuses the independently proven Slice 4A connection/wait-edge
 * fixture queries. The original Slice 4A class/test is deliberately untouched.
 * Polling only observes MySQL; success requires the exact PRIMARY record edge.
 */
trait CustomRoleLockWaitFixture
{
    private function freshMysqli(): \mysqli
    {
        global $wpdb;
        try {
            $connection = new \mysqli($wpdb->dbhost, $wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname);
        } catch (\mysqli_sql_exception $exception) {
            throw new RuntimeException('Could not open the independent Performance Schema observer connection.', 0, $exception);
        }
        if ($connection->connect_errno !== 0) {
            throw new RuntimeException('Could not open the independent Performance Schema observer connection: ' . $connection->connect_error);
        }

        if (!$connection->set_charset('utf8mb4')) {
            throw new RuntimeException('Could not set the observer connection character set: ' . $connection->error);
        }

        return $connection;
    }

    /**
     * MariaDB has no performance_schema.data_locks/data_lock_waits (MDEV-31165
     * is still open); it exposes information_schema.INNODB_LOCKS/LOCK_WAITS
     * instead. Select the introspection dialect from the live server, never
     * from configuration, so a mislabelled server cannot pick the wrong path.
     */
    private function isMariaDbServer(\mysqli $observer): bool
    {
        $result = $observer->query('SELECT VERSION()');
        if ($result === false) {
            throw new RuntimeException('Could not read the database server version: ' . $observer->error);
        }
        $row = $result->fetch_row();
        $result->free();

        return stripos((string) ($row[0] ?? ''), 'mariadb') !== false;
    }

    /**
     * @return array{transaction_id:string,lock_id:string,lock_mode:string,lock_status:string,lock_data:string}|null
     */
    private function customRoleDefinitionLockForConnection(\mysqli $observer, int $connectionId, int $definitionId): ?array
    {
        if ($this->isMariaDbServer($observer)) {
            return $this->mariaDbGrantedDefinitionLock($observer, $connectionId, $definitionId);
        }
        $table = $observer->real_escape_string(App::db()->table('cpms_custom_role_defs'));
        $lockData = $observer->real_escape_string((string) $definitionId);
        $connectionId = (int) $connectionId;
        $sql =
            'SELECT locks.engine_transaction_id AS transaction_id, locks.engine_lock_id AS lock_id,' .
            ' locks.lock_mode AS lock_mode, locks.lock_status AS lock_status, locks.lock_data AS lock_data' .
            ' FROM performance_schema.data_locks AS locks' .
            ' INNER JOIN performance_schema.threads AS threads ON threads.thread_id = locks.thread_id' .
            ' WHERE threads.processlist_id = ' . $connectionId .
            ' AND locks.object_schema = DATABASE()' .
            " AND locks.object_name = '{$table}'" .
            " AND locks.index_name = 'PRIMARY'" .
            " AND locks.lock_type = 'RECORD'" .
            " AND locks.lock_mode LIKE 'X%'" .
            " AND locks.lock_status = 'GRANTED'" .
            " AND locks.lock_data = '{$lockData}' LIMIT 1";
        $result = $observer->query($sql);
        if ($result === false) {
            throw new RuntimeException('Could not inspect the parent definition lock: ' . $observer->error);
        }

        $row = $result->fetch_assoc();
        $result->free();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,string|null>|null
     */
    private function customRoleDefinitionWaitEdge(
        \mysqli $observer,
        int $requestingConnectionId,
        int $blockingConnectionId,
        int $definitionId
    ): ?array {
        if ($this->isMariaDbServer($observer)) {
            return $this->mariaDbDefinitionWaitEdge($observer, $requestingConnectionId, $blockingConnectionId, $definitionId);
        }
        $table = $observer->real_escape_string(App::db()->table('cpms_custom_role_defs'));
        $lockData = $observer->real_escape_string((string) $definitionId);
        $requestingConnectionId = (int) $requestingConnectionId;
        $blockingConnectionId = (int) $blockingConnectionId;
        $sql =
            'SELECT waits.requesting_engine_transaction_id AS requesting_transaction_id,' .
            ' waits.blocking_engine_transaction_id AS blocking_transaction_id,' .
            ' waits.requesting_engine_lock_id AS requesting_lock_id,' .
            ' waits.blocking_engine_lock_id AS blocking_lock_id,' .
            ' requesting_lock.lock_mode AS requesting_lock_mode,' .
            ' requesting_lock.lock_status AS requesting_lock_status,' .
            ' requesting_lock.lock_data AS requesting_lock_data,' .
            ' blocking_lock.lock_mode AS blocking_lock_mode,' .
            ' blocking_lock.lock_status AS blocking_lock_status,' .
            ' blocking_lock.lock_data AS blocking_lock_data,' .
            ' requesting_thread.processlist_id AS requesting_connection_id,' .
            ' blocking_thread.processlist_id AS blocking_connection_id' .
            ' FROM performance_schema.data_lock_waits AS waits' .
            ' INNER JOIN performance_schema.data_locks AS requesting_lock' .
            ' ON requesting_lock.engine_lock_id = waits.requesting_engine_lock_id' .
            ' INNER JOIN performance_schema.data_locks AS blocking_lock' .
            ' ON blocking_lock.engine_lock_id = waits.blocking_engine_lock_id' .
            ' INNER JOIN performance_schema.threads AS requesting_thread' .
            ' ON requesting_thread.thread_id = requesting_lock.thread_id' .
            ' INNER JOIN performance_schema.threads AS blocking_thread' .
            ' ON blocking_thread.thread_id = blocking_lock.thread_id' .
            ' WHERE requesting_thread.processlist_id = ' . $requestingConnectionId .
            ' AND blocking_thread.processlist_id = ' . $blockingConnectionId .
            ' AND requesting_lock.object_schema = DATABASE()' .
            " AND requesting_lock.object_name = '{$table}'" .
            " AND requesting_lock.index_name = 'PRIMARY'" .
            " AND requesting_lock.lock_type = 'RECORD'" .
            " AND requesting_lock.lock_mode LIKE 'X%'" .
            " AND requesting_lock.lock_status = 'WAITING'" .
            ' AND blocking_lock.object_schema = DATABASE()' .
            " AND blocking_lock.object_name = '{$table}'" .
            " AND blocking_lock.index_name = 'PRIMARY'" .
            " AND blocking_lock.lock_type = 'RECORD'" .
            " AND blocking_lock.lock_mode LIKE 'X%'" .
            " AND blocking_lock.lock_status = 'GRANTED'" .
            " AND blocking_lock.lock_data = '{$lockData}' LIMIT 1";
        $result = $observer->query($sql);
        if ($result === false) {
            throw new RuntimeException('Could not inspect the exact InnoDB lock-wait edge: ' . $observer->error);
        }

        $row = $result->fetch_assoc();
        $result->free();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,string>|null
     */
    private function innodbTransactionForConnection(\mysqli $observer, int $connectionId): ?array
    {
        $connectionId = (int) $connectionId;
        $sql =
            'SELECT trx_id AS transaction_id, trx_state, trx_operation_state,' .
            ' trx_requested_lock_id, trx_mysql_thread_id' .
            ' FROM information_schema.innodb_trx' .
            ' WHERE trx_mysql_thread_id = ' . $connectionId . ' LIMIT 1';
        $result = $observer->query($sql);
        if ($result === false) {
            throw new RuntimeException('Could not inspect the InnoDB transaction state: ' . $observer->error);
        }

        $row = $result->fetch_assoc();
        $result->free();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,string|null>>
     */
    private function customRoleDefinitionLocksForConnection(\mysqli $observer, int $connectionId): array
    {
        if ($this->isMariaDbServer($observer)) {
            return $this->mariaDbDefinitionLocks($observer, $connectionId);
        }
        $table = $observer->real_escape_string(App::db()->table('cpms_custom_role_defs'));
        $connectionId = (int) $connectionId;
        $sql =
            'SELECT locks.engine_transaction_id AS transaction_id, locks.engine_lock_id AS lock_id,' .
            ' locks.object_name, locks.index_name, locks.lock_type, locks.lock_mode,' .
            ' locks.lock_status, locks.lock_data' .
            ' FROM performance_schema.data_locks AS locks' .
            ' INNER JOIN performance_schema.threads AS threads ON threads.thread_id = locks.thread_id' .
            ' WHERE threads.processlist_id = ' . $connectionId .
            ' AND locks.object_schema = DATABASE()' .
            " AND locks.object_name = '{$table}' LIMIT 20";
        $result = $observer->query($sql);
        if ($result === false) {
            throw new RuntimeException('Could not inspect definition-row lock diagnostics: ' . $observer->error);
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    /**
     * MariaDB cannot expose a granted row lock before a waiter exists: its
     * INNODB_LOCKS only lists requested-but-not-granted locks and held locks
     * that currently block another transaction. Row-lock ownership is instead
     * proven behaviourally: an independent session must be unable to acquire
     * the exact primary-key row with FOR UPDATE NOWAIT while the winner holds
     * its transaction. The returned marker is not a real InnoDB lock id; callers
     * must compare the transaction identity on this engine.
     *
     * @return array{transaction_id:string,lock_id:string,lock_mode:string,lock_status:string,lock_data:string}|null
     */
    private function mariaDbGrantedDefinitionLock(\mysqli $observer, int $connectionId, int $definitionId): ?array
    {
        $connectionId = (int) $connectionId;
        $definitionId = (int) $definitionId;
        $trxResult = $observer->query(
            'SELECT trx_id FROM information_schema.innodb_trx'
            . ' WHERE trx_mysql_thread_id = ' . $connectionId . ' LIMIT 1'
        );
        if ($trxResult === false) {
            throw new RuntimeException('Could not read the MariaDB transaction identity: ' . $observer->error);
        }
        $trx = $trxResult->fetch_assoc();
        $trxResult->free();
        if (!is_array($trx)) {
            return null;
        }

        $table = $observer->real_escape_string(App::db()->table('cpms_custom_role_defs'));
        $probe = $this->freshMysqli();
        $locked = false;
        try {
            $probe->query('SET SESSION innodb_lock_wait_timeout = 2');
            $probe->query('START TRANSACTION');
            $probeErrno = 0;
            try {
                $result = $probe->query("SELECT id FROM `{$table}` WHERE id = {$definitionId} FOR UPDATE NOWAIT");
                if ($result instanceof \mysqli_result) {
                    $result->free();
                }
            } catch (\mysqli_sql_exception $exception) {
                $result = false;
                $probeErrno = (int) $exception->getCode();
            }
            if ($result === false) {
                $probeErrno = $probeErrno !== 0 ? $probeErrno : (int) $probe->errno;
                if ($probeErrno !== 3572) {
                    throw new RuntimeException(
                        'Could not execute the MariaDB primary-key lock probe: ' . $probe->error,
                        0
                    );
                }
                $locked = true;
            }
            $probe->query('ROLLBACK');
        } finally {
            $probe->close();
        }
        if (!$locked) {
            return null;
        }

        return [
            'transaction_id' => (string) $trx['trx_id'],
            'lock_id' => 'mariadb:behavioural:' . $trx['trx_id'] . ':' . $definitionId,
            'lock_mode' => 'X',
            'lock_status' => 'GRANTED',
            'lock_data' => (string) $definitionId,
        ];
    }

    /**
     * MariaDB equivalent of the performance_schema wait-edge query. The
     * requested and blocking locks appear in INNODB_LOCKS only while the wait
     * exists, which is exactly the window this helper observes.
     *
     * @return array<string,string|null>|null
     */
    private function mariaDbDefinitionWaitEdge(
        \mysqli $observer,
        int $requestingConnectionId,
        int $blockingConnectionId,
        int $definitionId
    ): ?array {
        $table = $observer->real_escape_string(App::db()->table('cpms_custom_role_defs'));
        $lockData = $observer->real_escape_string((string) $definitionId);
        $requestingConnectionId = (int) $requestingConnectionId;
        $blockingConnectionId = (int) $blockingConnectionId;
        $normalizedTable = "REPLACE(REPLACE(%s.lock_table, '`', ''), ' ', '') = CONCAT(DATABASE(), '.', '{$table}')";
        $sql =
            'SELECT waits.requesting_trx_id AS requesting_transaction_id,' .
            ' waits.blocking_trx_id AS blocking_transaction_id,' .
            ' waits.requested_lock_id AS requesting_lock_id,' .
            ' waits.blocking_lock_id AS blocking_lock_id,' .
            ' requesting_lock.lock_mode AS requesting_lock_mode,' .
            " 'WAITING' AS requesting_lock_status," .
            ' requesting_lock.lock_data AS requesting_lock_data,' .
            ' blocking_lock.lock_mode AS blocking_lock_mode,' .
            " 'GRANTED' AS blocking_lock_status," .
            ' blocking_lock.lock_data AS blocking_lock_data,' .
            ' requesting_trx.trx_mysql_thread_id AS requesting_connection_id,' .
            ' blocking_trx.trx_mysql_thread_id AS blocking_connection_id' .
            ' FROM information_schema.innodb_lock_waits AS waits' .
            ' INNER JOIN information_schema.innodb_locks AS requesting_lock' .
            ' ON requesting_lock.lock_id = waits.requested_lock_id' .
            ' INNER JOIN information_schema.innodb_locks AS blocking_lock' .
            ' ON blocking_lock.lock_id = waits.blocking_lock_id' .
            ' INNER JOIN information_schema.innodb_trx AS requesting_trx' .
            ' ON requesting_trx.trx_id = waits.requesting_trx_id' .
            ' INNER JOIN information_schema.innodb_trx AS blocking_trx' .
            ' ON blocking_trx.trx_id = waits.blocking_trx_id' .
            ' WHERE requesting_trx.trx_mysql_thread_id = ' . $requestingConnectionId .
            ' AND blocking_trx.trx_mysql_thread_id = ' . $blockingConnectionId .
            ' AND requesting_lock.lock_type = ' . "'RECORD'" .
            " AND requesting_lock.lock_mode LIKE 'X%'" .
            " AND requesting_lock.lock_index = 'PRIMARY'" .
            ' AND ' . sprintf($normalizedTable, 'requesting_lock') .
            ' AND blocking_lock.lock_type = ' . "'RECORD'" .
            " AND blocking_lock.lock_mode LIKE 'X%'" .
            " AND blocking_lock.lock_index = 'PRIMARY'" .
            " AND blocking_lock.lock_data = '{$lockData}'" .
            ' AND ' . sprintf($normalizedTable, 'blocking_lock') .
            ' LIMIT 1';
        $result = $observer->query($sql);
        if ($result === false) {
            throw new RuntimeException('Could not inspect the exact MariaDB lock-wait edge: ' . $observer->error);
        }

        $row = $result->fetch_assoc();
        $result->free();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,string|null>>
     */
    private function mariaDbDefinitionLocks(\mysqli $observer, int $connectionId): array
    {
        $table = $observer->real_escape_string(App::db()->table('cpms_custom_role_defs'));
        $connectionId = (int) $connectionId;
        $sql =
            'SELECT locks.lock_trx_id AS transaction_id, locks.lock_id AS lock_id,' .
            " SUBSTRING_INDEX(REPLACE(locks.lock_table, '`', ''), '.', -1) AS object_name," .
            ' locks.lock_index AS index_name, locks.lock_type AS lock_type,' .
            ' locks.lock_mode AS lock_mode,' .
            " CASE WHEN locks.lock_id = trx.trx_requested_lock_id THEN 'WAITING' ELSE 'GRANTED' END AS lock_status," .
            ' locks.lock_data AS lock_data' .
            ' FROM information_schema.innodb_locks AS locks' .
            ' INNER JOIN information_schema.innodb_trx AS trx ON trx.trx_id = locks.lock_trx_id' .
            ' WHERE trx.trx_mysql_thread_id = ' . $connectionId .
            " AND REPLACE(REPLACE(locks.lock_table, '`', ''), ' ', '') = CONCAT(DATABASE(), '.', '{$table}')" .
            ' LIMIT 20';
        $result = $observer->query($sql);
        if ($result === false) {
            throw new RuntimeException('Could not inspect MariaDB definition-row lock diagnostics: ' . $observer->error);
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    /**
     * @param resource $pipe
     */
    private function readRaceLine($pipe, int $timeoutSeconds): ?string
    {
        if (!is_resource($pipe)) {
            return null;
        }

        stream_set_timeout($pipe, $timeoutSeconds);
        $line = fgets($pipe);
        $metadata = stream_get_meta_data($pipe);
        if ($line === false || ($metadata['timed_out'] ?? false)) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    private function customRoleAssignmentAuditCount(int $clinicId, int $membershipId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE clinic_id = %d AND action = %s AND resource_type = %s AND resource_id = %d',
            [
                $clinicId,
                CustomRoleAssignmentService::AUDIT_ASSIGNED,
                CustomRoleAssignmentService::RESOURCE_TYPE,
                $membershipId,
            ]
        );
    }

    private function freshWpdb(): \wpdb
    {
        global $wpdb;
        $connection = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $connection->set_prefix($wpdb->prefix);
        $connection->show_errors(false);

        return $connection;
    }

    /**
     * Test-only teardown for the committed two-connection fixture. The test
     * asserts every lifecycle/assignment event before this cleanup; production lifecycle
     * paths never delete audit records, definitions, memberships or overrides.
     *
     * @param list<int> $clinicIds
     * @param list<int> $userIds
     */
    private function cleanupCommittedRaceFixtures(array $clinicIds, array $userIds, int $definitionId): void
    {
        $db = App::db();
        $clinicId = (int) ($clinicIds[0] ?? 0);
        if ($clinicId > 0) {
            // Only this committed, ephemeral Clinic's test audit rows are removed.
            $db->query('DELETE FROM ' . $db->table('cpms_audit_logs') . ' WHERE clinic_id = %d', [$clinicId]);
        }
        $db->query(
            'DELETE FROM ' . $db->table('cpms_custom_role_capabilities') . ' WHERE role_def_id = %d',
            [$definitionId]
        );
        $db->query(
            'DELETE FROM ' . $db->table('cpms_custom_role_defs') . ' WHERE id = %d',
            [$definitionId]
        );
        foreach ($clinicIds as $clinicId) {
            $memberships = $db->fetchAll(
                'SELECT id FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
                [$clinicId]
            );
            foreach ($memberships as $membership) {
                $membershipId = (int) ($membership['id'] ?? 0);
                if ($membershipId > 0) {
                    $db->query('DELETE FROM ' . $db->table('cpms_membership_capabilities') . ' WHERE membership_id = %d', [$membershipId]);
                    $db->query('DELETE FROM ' . $db->table('cpms_membership_locations') . ' WHERE membership_id = %d', [$membershipId]);
                    $db->query('DELETE FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE id = %d', [$membershipId]);
                }
            }
        }
        foreach ($userIds as $userId) {
            wp_delete_user($userId);
        }
        foreach ($clinicIds as $clinicId) {
            $db->query('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [$clinicId]);
        }
    }

    private function makeClinic(int $organizationId, string $prefix): int
    {
        global $wpdb;
        $slug = $prefix . '-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $organizationId,
            'Lifecycle test ' . $slug,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $login, string $role = 'subscriber'): int
    {
        $id = self::factory()->user->create(['user_login' => $login, 'role' => $role]);
        self::assertGreaterThan(0, $id);

        return (int) $id;
    }
}
