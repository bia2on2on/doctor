<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

/**
 * Bounded, read-only connection diagnostics for exactly two Integration tests:
 *   - Phase7Fr55NoShowRedTest::testConcurrentCheckInAndNoShowNeverLeaveLiveVisitOnNoShowAppointment
 *   - VisitConcurrencyTest::testParallelCompleteExactlyOneSucceeds
 *
 * Purpose: capture decisive runtime facts about the parent wpdb session across a
 * pcntl_fork() race, the statement that actually executed, and the authoritative
 * persisted state read through a genuinely independent MySQL connection.
 *
 * Honesty/safety constraints (test-only infrastructure):
 *  - It never changes a query, an assertion, a schema, a gate or product behaviour.
 *  - It issues NO query on the parent wpdb session: every wpdb fact below is a
 *    property read or a client-side mysqli accessor (thread id, errno, insert id),
 *    so the diagnostic cannot mask the very failure it observes.
 *  - Only sanitized scalars are emitted: statement verb + plugin table name,
 *    booleans, counts and connection identities. No SQL text, no row values,
 *    no credentials, no patient/user data.
 *  - Output is capped (MAX_LINES) so the evidence stays bounded.
 */
final class ConnectionDiagnostics
{
    private const PREFIX    = 'CPMS_CONN_DIAG ';
    private const FILE      = '/tmp/cpms-conn-diag.jsonl';
    private const MAX_LINES = 60;

    private static int $emitted = 0;

    /**
     * @param array<string, mixed> $payload
     */
    public static function emit(array $payload): void
    {
        if (self::$emitted >= self::MAX_LINES) {
            return;
        }
        ++self::$emitted;

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (! is_string($json)) {
            return;
        }

        $line = self::PREFIX . $json;
        fwrite(STDOUT, $line . PHP_EOL);
        @file_put_contents(self::FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Connection identity of a mysqli handle WITHOUT issuing a query.
     */
    public static function threadId(mixed $dbh): int
    {
        if (! $dbh instanceof \mysqli) {
            return -1;
        }

        try {
            return (int) $dbh->thread_id;
        } catch (\Throwable) {
            return -2;
        }
    }

    /**
     * Verb + plugin table of a statement, with every value stripped.
     *
     * @return array{verb: string, table: string}
     */
    public static function classify(?string $sql): array
    {
        $sql = trim((string) $sql);
        if ($sql === '') {
            return ['verb' => 'none', 'table' => 'none'];
        }

        $verb = preg_match('/^([a-z]+)/i', $sql, $m) === 1 ? strtolower($m[1]) : 'other';

        $table = 'none';
        if (preg_match('/(?:into|from|update|table)\s+`?([a-z0-9_]+)`?/i', $sql, $t) === 1) {
            $name  = strtolower($t[1]);
            $table = str_contains($name, 'cpms_') ? (string) preg_replace('/^[a-z0-9]*cpms_/', 'cpms_', $name) : 'other';
        }

        return ['verb' => $verb, 'table' => $table];
    }

    /**
     * Census of the live 'query' filter chain (identities only, never bodies).
     *
     * @return array{count: int, chain: list<string>}
     */
    public static function queryFilters(): array
    {
        global $wp_filter;

        $hook = $wp_filter['query'] ?? null;
        if (! is_object($hook) || ! isset($hook->callbacks) || ! is_array($hook->callbacks)) {
            return ['count' => 0, 'chain' => []];
        }

        $shapes = [];
        foreach ($hook->callbacks as $priority => $callbacks) {
            if (! is_array($callbacks)) {
                continue;
            }
            foreach ($callbacks as $entry) {
                $fn = is_array($entry) ? ($entry['function'] ?? null) : null;
                if (is_string($fn)) {
                    $shape = $fn;
                } elseif (is_array($fn)) {
                    $owner = is_object($fn[0] ?? null) ? get_class($fn[0]) : (string) ($fn[0] ?? '?');
                    $shape = $owner . '::' . (string) ($fn[1] ?? '?');
                } elseif ($fn instanceof \Closure) {
                    $shape = 'closure#' . substr((string) spl_object_id($fn), -3);
                } else {
                    $shape = get_debug_type($fn);
                }
                $shapes[] = (string) $priority . ':' . $shape;
            }
        }

        $shapes = array_values(array_unique($shapes));
        sort($shapes);

        return ['count' => count($shapes), 'chain' => array_slice($shapes, 0, 12)];
    }

    /**
     * Snapshot of the parent wpdb session without issuing any query.
     *
     * @param object $wpdb
     *
     * @return array<string, mixed>
     */
    public static function wpdbState(object $wpdb): array
    {
        $dbh = $wpdb->dbh ?? null;

        return [
            'ready'                            => (bool) ($wpdb->ready ?? false),
            'dbh_is_mysqli'                    => $dbh instanceof \mysqli,
            'thread_id'                        => self::threadId($dbh),
            'last_query'                       => self::classify(isset($wpdb->last_query) ? (string) $wpdb->last_query : ''),
            'last_error_present'               => '' !== (string) ($wpdb->last_error ?? ''),
            'mysql_errno'                      => $dbh instanceof \mysqli ? (int) mysqli_errno($dbh) : -1,
            'rows_affected'                    => (int) ($wpdb->rows_affected ?? 0),
            'num_rows'                         => (int) ($wpdb->num_rows ?? 0),
            'insert_id'                        => (int) ($wpdb->insert_id ?? 0),
            'mysqli_insert_id'                 => $dbh instanceof \mysqli ? (int) mysqli_insert_id($dbh) : -1,
            'cached_result_rows'               => is_array($wpdb->last_result ?? null) ? count($wpdb->last_result) : 0,
            'cached_first_scalar_is_literal_1' => self::cachedFirstScalarIsLiteralOne($wpdb),
        ];
    }

    /**
     * True when wpdb's cached last_result row (what get_var() would return when the
     * new statement never executes) carries the literal scalar '1'.
     *
     * @param object $wpdb
     */
    private static function cachedFirstScalarIsLiteralOne(object $wpdb): bool
    {
        $rows = $wpdb->last_result ?? null;
        if (! is_array($rows) || $rows === [] || ! isset($rows[0])) {
            return false;
        }

        $values = array_values(get_object_vars($rows[0]));

        return isset($values[0]) && (string) $values[0] === '1';
    }

    /**
     * A genuinely independent MySQL connection (never reuses the inherited socket).
     */
    public static function independent(): ?\mysqli
    {
        global $wpdb;

        if (! class_exists('\mysqli')) {
            return null;
        }

        try {
            $conn = @new \mysqli(
                (string) $wpdb->dbhost,
                (string) $wpdb->dbuser,
                (string) $wpdb->dbpassword,
                (string) $wpdb->dbname
            );
        } catch (\Throwable) {
            return null;
        }

        if ($conn->connect_errno !== 0) {
            @$conn->close();

            return null;
        }

        $conn->set_charset('utf8mb4');

        return $conn;
    }

    /**
     * Sanitized schema/persistence facts for one plugin table, read through an
     * independent connection. Never emits row values.
     *
     * @return array<string, mixed>
     */
    public static function tableFacts(\mysqli $conn, string $shortTable, string $keyColumn, ?int $keyValue = null): array
    {
        global $wpdb;

        $full  = (string) $wpdb->prefix . $shortTable;
        $facts = [
            'table'                   => $shortTable,
            'has_auto_increment'      => -1,
            'next_auto_increment'     => -1,
            'row_exists_for_key'      => -1,
            'temp_shadow_rows'        => -1,
        ];

        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                AND EXTRA LIKE ?'
        );
        if ($stmt instanceof \mysqli_stmt) {
            $like = '%auto_increment%';
            $stmt->bind_param('sss', $full, $keyColumn, $like);
            $stmt->execute();
            $val = null;
            $stmt->bind_result($val);
            if ($stmt->fetch()) {
                $facts['has_auto_increment'] = (int) $val;
            }
            $stmt->close();
        }

        $stmt = $conn->prepare(
            'SELECT AUTO_INCREMENT FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if ($stmt instanceof \mysqli_stmt) {
            $stmt->bind_param('s', $full);
            $stmt->execute();
            $val = null;
            $stmt->bind_result($val);
            if ($stmt->fetch() && $val !== null) {
                $facts['next_auto_increment'] = (int) $val;
            }
            $stmt->close();
        }

        if ($keyValue !== null) {
            $stmt = $conn->prepare('SELECT COUNT(*) FROM `' . $full . '` WHERE `' . $keyColumn . '` = ?');
            if ($stmt instanceof \mysqli_stmt) {
                $stmt->bind_param('i', $keyValue);
                $stmt->execute();
                $val = null;
                $stmt->bind_result($val);
                if ($stmt->fetch()) {
                    $facts['row_exists_for_key'] = (int) $val;
                }
                $stmt->close();
            }
        }

        $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.INNODB_TEMP_TABLE_INFO WHERE NAME LIKE ?');
        if ($stmt instanceof \mysqli_stmt) {
            $like = '%' . $shortTable . '%';
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $val = null;
            $stmt->bind_result($val);
            if ($stmt->fetch()) {
                $facts['temp_shadow_rows'] = (int) $val;
            }
            $stmt->close();
        }

        return $facts;
    }

    /**
     * Counts-only view of one visit row's persisted status through an independent
     * connection. Never emits the stored value itself.
     *
     * @return array<string, mixed>
     */
    public static function visitStatusFacts(\mysqli $conn, string $fullTable, int $visitId): array
    {
        $facts = ['row_exists' => -1, 'status_completed' => -1, 'status_in_consultation' => -1];

        $stmt = $conn->prepare('SELECT COUNT(*) FROM `' . $fullTable . '` WHERE `id` = ?');
        if ($stmt instanceof \mysqli_stmt) {
            $stmt->bind_param('i', $visitId);
            $stmt->execute();
            $val = null;
            $stmt->bind_result($val);
            if ($stmt->fetch()) {
                $facts['row_exists'] = (int) $val;
            }
            $stmt->close();
        }

        foreach (['status_completed' => 'consultation_completed', 'status_in_consultation' => 'in_consultation'] as $key => $status) {
            $stmt = $conn->prepare('SELECT COUNT(*) FROM `' . $fullTable . '` WHERE `id` = ? AND `status` = ?');
            if (! $stmt instanceof \mysqli_stmt) {
                continue;
            }
            $stmt->bind_param('is', $visitId, $status);
            $stmt->execute();
            $val = null;
            $stmt->bind_result($val);
            if ($stmt->fetch()) {
                $facts[$key] = (int) $val;
            }
            $stmt->close();
        }

        return $facts;
    }
}
