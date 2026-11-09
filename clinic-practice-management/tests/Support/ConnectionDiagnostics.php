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
 *  - Query-filter chain records are SOURCE-VERIFIED identifiers: a bare
 *    function must be declared in this repository's src/ or tests/ tree, and
 *    a Class::method must resolve through the PSR-4 mapping to a real file
 *    with a declared method. Everything else (including WP-core and other
 *    plugins' callbacks and any valid-shaped canary) is dropped and counted
 *    by cause in chain_dropped_shape / chain_dropped_unverified.
 *  - Output is capped (MAX_LINES) so the evidence stays bounded.
 */
final class ConnectionDiagnostics
{
    private const PREFIX    = 'CPMS_CONN_DIAG ';
    private const FILE      = '/tmp/cpms-conn-diag.jsonl';
    private const MAX_LINES = 40;

    /**
     * Tables created by the plugin migrations (short, unprefixed names).
     * Independent copy of the boundary list (BoundedEvidence / projector keep
     * their own); tableFacts() echoes a table name only from this set.
     */
    private const KNOWN_TABLES = [
        'cpms_appointments', 'cpms_audit_logs', 'cpms_audit_seq',
        'cpms_clinic_memberships', 'cpms_clinical_note_versions',
        'cpms_clinical_notes', 'cpms_clinician_locations', 'cpms_clinicians',
        'cpms_clinics', 'cpms_custom_role_capabilities', 'cpms_custom_role_defs',
        'cpms_doctor_consultation_fees', 'cpms_drug_reference', 'cpms_follow_ups',
        'cpms_handwriting_documents', 'cpms_handwriting_page_versions',
        'cpms_handwriting_pages', 'cpms_idempotency_keys', 'cpms_invoice_items',
        'cpms_invoices', 'cpms_jobs', 'cpms_license_install', 'cpms_license_state',
        'cpms_locations', 'cpms_medical_attachments', 'cpms_membership_capabilities',
        'cpms_membership_locations', 'cpms_message_participants',
        'cpms_message_threads', 'cpms_messages', 'cpms_notifications',
        'cpms_ocr_jobs', 'cpms_operational_logs',
        'cpms_organization_membership_capabilities', 'cpms_organization_memberships',
        'cpms_organizations', 'cpms_otp_tokens', 'cpms_patient_identities',
        'cpms_patient_identity_links', 'cpms_patient_merges',
        'cpms_patient_user_links', 'cpms_patients',
        'cpms_payment_adjustments', 'cpms_payments', 'cpms_prescription_items',
        'cpms_prescriptions', 'cpms_rate_limits', 'cpms_recommendations',
        'cpms_schedule', 'cpms_schedule_exceptions', 'cpms_schedule_slots',
        'cpms_schema_migrations', 'cpms_services', 'cpms_settings',
        'cpms_slot_holds', 'cpms_sms_messages',
        'cpms_visit_charge_items', 'cpms_visit_consultation_fees',
        'cpms_visit_fee_snapshot_failures', 'cpms_visit_status_history',
        'cpms_visits',
    ];

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
     * Sorted set of every function name declared in this repository's src/ or
     * tests/ tree. Cached per process; built once (bounded reads).
     *
     * @return array<string, true>
     */
    private static function declaredFunctionNames(): array
    {
        static $names = null;
        if ($names !== null) {
            return $names;
        }
        $names = [];
        $root = dirname(__DIR__, 2);
        $candidates = [$root . '/src', $root . '/tests'];
        foreach ($candidates as $base) {
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php' || $file->getSize() > 8 * 1024 * 1024) {
                    continue;
                }
                self::collectDeclaredFunctions($file->getPathname(), $names);
            }
        }
        // Plugin entry points at the root (e.g. clinic-practice-management.php).
        foreach (glob($root . '/*.php') ?: [] as $entry) {
            if (is_file($entry) && filesize($entry) <= 8 * 1024 * 1024) {
                self::collectDeclaredFunctions($entry, $names);
            }
        }

        return $names;
    }

    /**
     * @param array<string, true> $names
     */
    private static function collectDeclaredFunctions(string $path, array &$names): void
    {
        $text = @file_get_contents($path);
        if (!is_string($text)) {
            return;
        }
        $count = preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]{0,127})\s*\(/', $text, $m);
        if (is_int($count)) {
            foreach ($m[1] as $name) {
                $names[$name] = true;
            }
        }
    }

    /**
     * Source-verified Class::method for this repository (PSR-4 mapping +
     * declared method in the resolved file). Null when unverifiable.
     */
    private static function sourceVerifiedMethod(string $fqcn, string $method): ?string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/D', $fqcn) !== 1) {
            return null;
        }
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/D', $method) !== 1) {
            return null;
        }
        $root = dirname(__DIR__, 2);
        $prefixes = ['ClinicCore\\Tests\\' => 'tests/', 'ClinicCore\\' => 'src/'];
        foreach ($prefixes as $prefix => $sub) {
            if (strncmp($fqcn, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($fqcn, strlen($prefix))) . '.php';
            $candidate = $root . '/' . $sub . $rel;
            $real = realpath($candidate);
            if ($real === false || !is_file($real) || !str_starts_with($real, $root)) {
                return null;
            }
            static $methodCache = [];
            $key = $real . '#' . $method;
            if (!array_key_exists($key, $methodCache)) {
                $text = @file_get_contents($real);
                $methodCache[$key] = is_string($text)
                    && preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $text) === 1;
            }

            return $methodCache[$key] ? $fqcn . '::' . $method : null;
        }

        return null;
    }

    /**
     * Source-verified bare function name declared in this repository.
     * Null when unverifiable.
     */
    private static function sourceVerifiedFunction(string $name): ?string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/D', $name) !== 1) {
            return null;
        }

        return isset(self::declaredFunctionNames()[$name]) ? $name : null;
    }

    /**
     * Resolve a callback to a source-verified shape, or null (drop).
     *
     * @param string $owner   class part (possibly an object class) for methods
     * @param string $method  method part for methods
     */
    private static function sourceVerifiedCallback(string $kind, string $owner, string $method): ?string
    {
        if ($kind === 'method') {
            return self::sourceVerifiedMethod($owner, $method);
        }

        return self::sourceVerifiedFunction($owner);
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
     * Map a captured table identifier to a known short table name.
     * Anything outside KNOWN_TABLES (exact, or the tail of a prefixed name
     * such as wptests_cpms_visits) collapses to 'other' — never echoed raw.
     */
    private static function knownTableFromIdentifier(string $name): string
    {
        foreach (self::KNOWN_TABLES as $short) {
            if ($name === $short) {
                return $short;
            }
            $suffix = '_' . $short;
            $length = strlen($suffix);
            if (strlen($name) > $length && substr($name, -$length) === $suffix) {
                return $short;
            }
        }

        return 'other';
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
        if (preg_match('/(?:into|from|update|table)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $t) === 1) {
            $table = self::knownTableFromIdentifier(strtolower($t[1]));
        }

        return ['verb' => $verb, 'table' => $table];
    }

    /**
     * Census of the live 'query' filter chain (source-verified identities only,
     * never bodies). Dropped records are counted by cause with fixed tokens:
     * chain_dropped_shape (unparseable priority/callback shape) and
     * chain_dropped_unverified (parseable, but not declared/resolvable in this
     * repository — WP-core/other-plugin callbacks and canary identifiers).
     *
     * @return array{count: int, chain: list<string>, chain_dropped_shape: int, chain_dropped_unverified: int}
     */
    public static function queryFilters(): array
    {
        global $wp_filter;

        $hook = $wp_filter['query'] ?? null;
        if (! is_object($hook) || ! isset($hook->callbacks) || ! is_array($hook->callbacks)) {
            return ['count' => 0, 'chain' => []];
        }

        $shapes = [];
        $droppedShape = 0;
        $droppedUnverified = 0;
        $segment = '[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*';
        foreach ($hook->callbacks as $priority => $callbacks) {
            // Only integer priorities in [0, 9999] reach the publication
            // boundary; anything else is skipped at the source (and counted).
            if (! is_int($priority) || $priority < 0 || $priority > 9999) {
                $droppedShape++;
                continue;
            }
            if (! is_array($callbacks)) {
                $droppedShape++;
                continue;
            }
            foreach ($callbacks as $entry) {
                $fn = is_array($entry) ? ($entry['function'] ?? null) : null;
                if ($fn instanceof \Closure) {
                    $shapes[] = $priority . ':closure#' . substr((string) spl_object_id($fn), -3);
                    continue;
                }
                $kind = null;
                $owner = null;
                $method = null;
                if (is_string($fn)) {
                    $kind = 'bare';
                    $owner = $fn;
                    $method = '';
                } elseif (is_array($fn)) {
                    $class = $fn[0] ?? null;
                    $owner = is_object($class) ? get_class($class) : (is_string($class) ? $class : null);
                    $method = (string) ($fn[1] ?? '');
                    if ($owner === null) {
                        $droppedShape++;
                        continue;
                    }
                    $kind = 'method';
                } elseif (is_callable($fn)) {
                    $verified = null;
                    try {
                        $reflection = new \ReflectionFunction(\Closure::fromCallable($fn));
                        // First-class callable syntax: publish only a source-verified name.
                        if ($reflection->isClosure() === false) {
                            $verified = self::sourceVerifiedFunction($reflection->getName());
                        }
                    } catch (\Throwable) {
                        $verified = null;
                    }
                    if ($verified === null) {
                        $droppedUnverified++;
                    } else {
                        $shapes[] = $priority . ':' . $verified;
                    }
                    continue;
                } else {
                    $droppedShape++;
                    continue; // unsupported callback shapes carry no diagnostic value.
                }
                // Shape gate (defence-in-depth): priority:(bare_fn|Fqcn::method).
                $shape = $kind === 'method' ? $owner . '::' . $method : $owner;
                $shapeOk = $kind === 'method'
                    ? (bool) preg_match('/\A' . $segment . '::[A-Za-z_][A-Za-z0-9_]{0,127}\z/', $shape)
                    : (bool) preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/', $shape);
                if (! $shapeOk) {
                    $droppedShape++;
                    continue;
                }
                // Source gate: declared in this repository, or dropped by cause.
                $verified = self::sourceVerifiedCallback($kind, $owner, (string) $method);
                if ($verified === null) {
                    $droppedUnverified++;
                    continue;
                }
                $shapes[] = $priority . ':' . $verified;
            }
        }

        $shapes = array_values(array_unique($shapes));
        sort($shapes);

        return [
            'count' => count($shapes),
            'chain' => array_slice($shapes, 0, 12),
            'chain_dropped_shape' => $droppedShape,
            'chain_dropped_unverified' => $droppedUnverified,
        ];
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

        if (! in_array($shortTable, self::KNOWN_TABLES, true)) {
            // Never echo an unlisted table identifier; the boundary vocabulary
            // is the fixed KNOWN_TABLES set, not a pattern. All-facts-unknown.
            return [
                'table'              => 'none',
                'has_auto_increment' => -1,
                'next_auto_increment' => -1,
                'row_exists_for_key' => -1,
                'temp_shadow_rows'   => -1,
            ];
        }

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

    /**
     * Counts-only proof of persisted identity for one synthetic patient row,
     * scoped by Clinic + the caller's own test MRN. The MRN value and every row
     * value are never emitted — only counts and a positive-key flag.
     *
     * @return array<string, mixed>
     */
    public static function identityFacts(\mysqli $conn, string $fullTable, string $scopeColumn, int $scopeId, string $naturalKey, string $keyColumn): array
    {
        $facts = ['rows_for_scope_and_key' => -1, 'positive_key_rows' => -1, 'key_is_positive' => -1];

        $stmt = $conn->prepare('SELECT COUNT(*) FROM `' . $fullTable . '` WHERE `' . $scopeColumn . '` = ? AND `' . $naturalKey . '` = ?');
        if ($stmt instanceof \mysqli_stmt) {
            $stmt->bind_param('is', $scopeId, $naturalKey);
            $stmt->execute();
            $val = null;
            $stmt->bind_result($val);
            if ($stmt->fetch()) {
                $facts['rows_for_scope_and_key'] = (int) $val;
            }
            $stmt->close();
        }

        $stmt = $conn->prepare('SELECT COUNT(*) FROM `' . $fullTable . '` WHERE `' . $scopeColumn . '` = ? AND `' . $naturalKey . '` = ? AND `' . $keyColumn . '` > 0');
        if ($stmt instanceof \mysqli_stmt) {
            $stmt->bind_param('is', $scopeId, $naturalKey);
            $stmt->execute();
            $val = null;
            $stmt->bind_result($val);
            if ($stmt->fetch()) {
                $facts['positive_key_rows'] = (int) $val;
            }
            $stmt->close();
        }

        $stmt = $conn->prepare('SELECT MAX(`' . $keyColumn . '`) > 0 FROM `' . $fullTable . '` WHERE `' . $scopeColumn . '` = ? AND `' . $naturalKey . '` = ?');
        if ($stmt instanceof \mysqli_stmt) {
            $stmt->bind_param('is', $scopeId, $naturalKey);
            $stmt->execute();
            $val = null;
            $stmt->bind_result($val);
            if ($stmt->fetch()) {
                $facts['key_is_positive'] = (int) $val;
            }
            $stmt->close();
        }

        return $facts;
    }
}
