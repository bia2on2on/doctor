<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

/**
 * Fail-closed SQL failure ledger for Integration runs — TEST-ONLY, never production.
 *
 * Why it exists (code evidence, not speculation):
 *  1) `CpmsDb::$strict` defaults to `false` and `setStrict(true)` is toggled only
 *     inside `MigrationRunner`; outside migrations `ensureNoSqlError()` publishes
 *     nothing and `insert()` merely returns `false`.
 *  2) `CustomRoleRepository::define()` then reports every failed INSERT with a
 *     generic "custom role key already defined…" message — the real MySQL error
 *     text/code is discarded.
 *  3) WordPress keeps a complete copy of these errors anyway: `wpdb::query()`
 *     fills `$this->last_error` from `mysqli_error()` on failure, and
 *     `print_error()` records `{query, error_str}` into
 *     `$GLOBALS['EZSQL_ERROR']` *before* checking `suppress_errors`.
 *
 * PUBLICATION CONTRACT (fail-closed — the only thing this class may publish):
 *  Every byte this class writes to STDERR (or any other capture) is built from
 *  FIXED allow-listed categories and STRICTLY VALIDATED, BOUNDED fields:
 *    • `category`  — one of CATEGORIES (fixed internal vocabulary).
 *    • `table`     — one of KNOWN_TABLES, otherwise `unknown`. Never any other
 *                    identifier, never a prefixed table name, never a value.
 *    • `op`        — one of OPERATIONS, otherwise `OTHER`.
 *    • `mysql_errno` — a positive int or the literal `unavailable`.
 *    • `sqlstate`  — exactly 5 ASCII base-32 chars, otherwise null/omitted.
 *    • `count`/`seq`/`dropped` — ints derived from counters only.
 *    • `test`/`product` — `Class::method` identifiers matching a strict ASCII
 *                    charset and length caps, otherwise `(unknown)`.
 *    • `focus`     — bool.
 *    • table facts — the allow-listed short table name, `real_exists` and
 *                    `name_visible` (both bools). Row counts are NOT published.
 *
 * NEVER published: raw driver text, exception messages, SQL text or fragments,
 * VALUES, fixture values (ids, keys, names, addresses), engine dumps,
 * performance_schema `lock_data`, `SHOW ENGINE INNODB STATUS` output, stack
 * traces or any free-text fallback. There is deliberately no "message" field,
 * no `clean()`/pattern-redaction step and no free-text path: a field that does
 * not pass its validator is DROPPED, never rewritten into "safe-ish" text.
 *
 * The signature used to de-duplicate repeated failures is an internal SHA-256
 * fingerprint of the driver message. It is used ONLY as an in-process map key
 * and is never printed, so it reveals nothing and is not a redaction boundary.
 *
 * Test expectations, permissions, schema and product behaviour are unchanged:
 *  • the `query` filter only *observes* and returns the query untouched;
 *  • the shutdown dump is fork-guarded (children must not run it);
 *  • phpunit's own output is untouched; this class only reads and appends.
 */
final class WpSqlFailureLedger
{
    /** سقفِ امضاهای نگه‌داشته‌شده (بالاتر از سقفِ چاپ، برای انتخابِ اولویت‌دار). */
    private const MAX_SIGNATURES = 60;

    /** سقفِ خطوطِ چاپ‌شده در بلوکِ پایانی. */
    private const MAX_PRINTED = 14;

    /** دسته‌بندیِ ثابت — تنها واژه‌نامهٔ داخلی که منتشر می‌شود. */
    private const CATEGORIES = ['duplicate_entry', 'foreign_key', 'missing_table', 'lock', 'check_constraint', 'other'];

    /** افعالِ ثابت — تنها عملیاتِ منتشرشده. */
    private const OPERATIONS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'SELECT', 'CREATE', 'ALTER', 'DROP', 'START', 'COMMIT', 'ROLLBACK', 'OTHER'];

    /** تنها نام‌های کوتاهِ جدول که منتشر می‌شوند. */
    private const KNOWN_TABLES = ['cpms_custom_role_defs', 'cpms_custom_role_capabilities', 'cpms_clinics'];

    /** @var array<string, array<string, mixed>> امضای داخلی → نخستین رخداد + شمارش */
    private static array $signatures = [];

    /** @var int شمارندهٔ کلِ شکست‌های مشاهده‌شده */
    private static int $failures = 0;

    /** @var int امضاهایی که به سقف نخوردند */
    private static int $dropped = 0;

    /** @var int شمارندهٔ ترتیبی برای پایدار ماندن نظمِ چاپ */
    private static int $sequence = 0;

    private static bool $registered = false;

    /**
     * PID that owns this diagnostic state — the process that called register()
     * (the PHPUnit parent). Forked children inherit the shutdown callback; they
     * must never run the flush, because its table_facts() queries would be issued
     * on the inherited WordPress mysqli handle (the parent's session) and corrupt
     * the parent's in-flight responses / connection state.
     */
    private static ?int $ownerPid = null;

    private static bool $inside = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        self::$ownerPid   = (int) getmypid();

        // اولویت بسیار بالا: پیش از اجرای کوئریِ بعدی، نتیجهٔ کوئریِ قبلی را می‌بیند
        add_filter('query', [self::class, 'observe'], PHP_INT_MAX);
        register_shutdown_function([self::class, 'flush']);
    }

    /**
     * تماشاگرِ فقط-خواندنی: کوئری را هرگز تغییر نمی‌دهد.
     */
    public static function observe(string $query): string
    {
        if (self::$inside) {
            return $query;
        }
        self::$inside = true;

        try {
            global $wpdb;

            if (is_object($wpdb) && isset($wpdb->last_error) && is_string($wpdb->last_error) && $wpdb->last_error !== '') {
                self::record($wpdb, $wpdb->last_error);
            }
        } catch (\Throwable $ignored) {
            // تشخیص هرگز نباید suite را بشکند.
        } finally {
            self::$inside = false;
        }

        return $query;
    }

    /**
     * ثبتِ یک شکست با فیدهای ثابت و اعتبارسنجی‌شده.
     */
    private static function record(object $wpdb, string $lastError): void
    {
        $ledgers = isset($GLOBALS['EZSQL_ERROR']) && is_array($GLOBALS['EZSQL_ERROR']) ? $GLOBALS['EZSQL_ERROR'] : [];
        $entry = $ledgers === [] ? null : end($ledgers);

        $statement = '';
        if (is_array($entry) && isset($entry['query']) && is_string($entry['query'])) {
            $statement = $entry['query'];
        }
        if ($statement === '' && isset($wpdb->last_query) && is_string($wpdb->last_query)) {
            $statement = $wpdb->last_query;
        }

        $message = $lastError;
        if (is_array($entry)
            && isset($entry['error_str'])
            && is_string($entry['error_str'])
            && $entry['error_str'] !== '') {
            $message = $entry['error_str'];
        }

        $driver = self::driver_facts($wpdb);
        $category = self::categorize($message);
        $table = self::table_of($statement);
        $op = self::operation_of($statement);

        // امضای داخلی برای ادغامِ تکرارها؛ هرگز چاپ نمی‌شود.
        $fingerprint = substr(hash('sha256', $message), 0, 16);
        $signature = $category . '|' . $table . '|' . $op . '|'
            . (is_int($driver['errno']) ? (string) $driver['errno'] : 'unavailable') . '|'
            . (is_string($driver['sqlstate']) ? $driver['sqlstate'] : 'none') . '|'
            . $fingerprint;

        self::$failures++;

        if (isset(self::$signatures[$signature])) {
            self::$signatures[$signature]['count']++;

            return;
        }

        if (count(self::$signatures) >= self::MAX_SIGNATURES) {
            self::$dropped++;

            return;
        }

        $context = self::context();
        self::$signatures[$signature] = [
            'seq' => ++self::$sequence,
            'count' => 1,
            'category' => $category,
            'table' => $table,
            'op' => $op,
            'mysql_errno' => $driver['errno'],
            'sqlstate' => $driver['sqlstate'],
            'test' => $context['test'],
            'product' => $context['product'],
            'focus' => self::is_focus($table, $message, $statement),
        ];

        // رخدادهای مرتبط با نقش‌های سفارشی فوراً چاپ می‌شوند (همیشه از همان مرزِ اعتبارسنجی عبور می‌کنند).
        $row = self::publishableRow(self::$signatures[$signature]);
        if ($row['focus']) {
            fwrite(STDERR, PHP_EOL . 'CPMS_SQL_FAILURE_EVIDENCE ' . self::encodeJson($row) . PHP_EOL);
        }
    }

    /**
     * بلوکِ پایانی: خواندنِ `EZSQL_ERROR` برای شمارش + واقعیتِ جداول (فقط بولین).
     */
    public static function flush(): void
    {
        /*
         * Parent-process-only. Every pcntl_fork() child inherits this shutdown
         * callback; running it there would execute table_facts() queries on the
         * inherited WordPress connection, i.e. a child process writing statements
         * to and reading responses from a session owned by the parent.
         */
        if (null !== self::$ownerPid && getmypid() !== self::$ownerPid) {
            return;
        }

        try {
            global $wpdb;

            if (defined('STDOUT')) {
                @fflush(STDOUT);
            }

            fwrite(STDERR, PHP_EOL . implode(PHP_EOL, self::buildLines(is_object($wpdb) ? $wpdb : null)) . PHP_EOL);
        } catch (\Throwable $ignored) {
            // در shutdown هرگز نباید استثنا بالا برود.
        }
    }

    /**
     * ساختِ همهٔ خطوطِ بلوکِ پایانی — خالص و بدونِ نوشتن (برای تست‌های مرزِ انتشار).
     *
     * @return list<string>
     */
    private static function buildLines($wpdb): array
    {
        $lines = [];
        $lines[] = 'CPMS_SQL_FAILURE_LEDGER_BEGIN failures=' . (int) self::$failures
            . ' distinct=' . (int) count(self::$signatures)
            . ' dropped=' . (int) self::$dropped
            . ' ezsql=' . (isset($GLOBALS['EZSQL_ERROR']) && is_array($GLOBALS['EZSQL_ERROR']) ? (int) count($GLOBALS['EZSQL_ERROR']) : 0);

        $ordered = self::$signatures;
        uasort($ordered, static function (array $a, array $b): int {
            $aFocus = !empty($a['focus']);
            $bFocus = !empty($b['focus']);
            if ($aFocus !== $bFocus) {
                return $aFocus ? -1 : 1;
            }

            return (int) ($a['seq'] ?? 0) <=> (int) ($b['seq'] ?? 0);
        });

        $printed = 0;
        $byCategory = [];
        foreach ($ordered as $row) {
            $safe = self::publishableRow($row);
            $byCategory[$safe['category']] = ($byCategory[$safe['category']] ?? 0) + $safe['count'];
            if ($printed >= self::MAX_PRINTED) {
                continue;
            }
            $printed++;
            $lines[] = 'CPMS_SQL_FAILURE_LEDGER ' . self::encodeJson($safe);
        }

        $lines[] = 'CPMS_SQL_FAILURE_LEDGER_SUMMARY ' . self::encodeJson([
            'by_category' => $byCategory,
            'printed' => (int) $printed,
            'kept' => (int) count(self::$signatures),
            'dropped' => (int) self::$dropped,
        ]);

        if (is_object($wpdb)) {
            foreach (self::table_facts($wpdb) as $fact) {
                $lines[] = 'CPMS_SQL_FAILURE_LEDGER_TABLE ' . self::encodeJson($fact);
            }
        }

        $lines[] = 'CPMS_SQL_FAILURE_LEDGER_END';

        return $lines;
    }

    /**
     * تنها مرزِ انتشار فیدها: هر فیلد اعتبارسنجی می‌شود؛ هر نامعتبر حذف می‌شود.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function publishableRow(array $row): array
    {
        $errno = $row['mysql_errno'] ?? 'unavailable';
        if (!is_int($errno) || $errno <= 0 || $errno > 4294967295) {
            $errno = 'unavailable';
        }

        $sqlstate = $row['sqlstate'] ?? null;
        if (!is_string($sqlstate) || !preg_match('/\A[0-9A-Z]{5}\z/D', $sqlstate)) {
            $sqlstate = null;
        }

        $category = $row['category'] ?? 'other';
        if (!is_string($category) || !in_array($category, self::CATEGORIES, true)) {
            $category = 'other';
        }

        $op = $row['op'] ?? 'OTHER';
        if (!is_string($op) || !in_array($op, self::OPERATIONS, true)) {
            $op = 'OTHER';
        }

        $table = $row['table'] ?? 'unknown';
        $table = is_string($table) ? self::tableName($table) : 'unknown';

        $seq = $row['seq'] ?? 0;
        $count = $row['count'] ?? 1;

        return [
            'seq' => is_numeric($seq) ? max(0, (int) $seq) : 0,
            'count' => is_numeric($count) ? max(1, (int) $count) : 1,
            'category' => $category,
            'table' => $table,
            'op' => $op,
            'mysql_errno' => $errno,
            'sqlstate' => $sqlstate,
            'test' => self::identifier($row['test'] ?? null),
            'product' => self::identifier($row['product'] ?? null),
            'focus' => !empty($row['focus']),
        ];
    }

    /**
     * شناسهٔ `Class::method` — charset و طول کاملاً محدود؛ هر چیز دیگر `(unknown)`.
     *
     * @param mixed $value
     */
    private static function identifier($value): string
    {
        if (!is_string($value)) {
            return '(unknown)';
        }
        $value = trim($value);
        if (!preg_match('/\A[A-Za-z0-9_\\\\]{1,200}::[A-Za-z0-9_]{1,128}\z/D', $value)) {
            return '(unknown)';
        }

        return $value;
    }

    /**
     * JSON با تنها فیدهای اعتبارسنجی‌شده — همیشه تک‌خط و ASCII-امن.
     *
     * @param mixed $value
     */
    private static function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '{}';
    }

    /**
     * errno / SQLSTATE درایور در همان لحظه (best-effort، اعتبارسنجی‌شده).
     *
     * @return array{errno: int|string, sqlstate: string|null}
     */
    private static function driver_facts(object $wpdb): array
    {
        $dbh = $wpdb->dbh ?? null;
        if (!($dbh instanceof \mysqli)) {
            return ['errno' => 'unavailable', 'sqlstate' => null];
        }

        $errno = (int) mysqli_errno($dbh);
        $sqlstate = (string) mysqli_sqlstate($dbh);

        return [
            'errno' => $errno > 0 ? $errno : 'unavailable',
            'sqlstate' => preg_match('/\A[0-9A-Z]{5}\z/D', $sqlstate) === 1 && $sqlstate !== '00000' ? $sqlstate : null,
        ];
    }

    /**
     * دستهٔ خطا — از واژه‌نامهٔ ثابت CATEGORIES (ملاک، متنِ خودِ درایور است).
     */
    private static function categorize(string $message): string
    {
        $m = strtolower($message);
        if (str_contains($m, 'duplicate entry')) {
            return 'duplicate_entry';
        }
        if (str_contains($m, 'foreign key') || str_contains($m, 'child row')) {
            return 'foreign_key';
        }
        if (str_contains($m, "doesn't exist") || str_contains($m, 'does not exist')) {
            return 'missing_table';
        }
        if (str_contains($m, 'lock wait timeout') || str_contains($m, 'deadlock')) {
            return 'lock';
        }
        if (str_contains($m, 'check constraint')) {
            return 'check_constraint';
        }

        return 'other';
    }

    /**
     * فعلِ کوئری — تنها از OPERATIONS؛ هر چیز دیگر `OTHER`.
     */
    private static function operation_of(string $statement): string
    {
        $first = '';
        if (preg_match('/^\s*([a-z]{2,12})\b/i', trim($statement), $m) === 1) {
            $first = strtoupper($m[1]);
        }

        return in_array($first, self::OPERATIONS, true) ? $first : 'OTHER';
    }

    /**
     * نامِ جدول — تنها از KNOWN_TABLES؛ هر چیز دیگر `unknown`.
     */
    private static function table_of(string $statement): string
    {
        if (preg_match('/^\s*(?:insert\s+into|replace\s+into|update|delete\s+from|select\s+.+?\s+from)\s+`?([A-Za-z0-9_]+)`?/is', $statement, $m) === 1) {
            return self::tableName($m[1]);
        }

        return 'unknown';
    }

    /**
     * نرمال‌سازی به یک نامِ کوتاهِ ثابت؛ هر چیز دیگر `unknown`.
     */
    private static function tableName(string $raw): string
    {
        $t = strtolower(trim($raw));
        if ($t === '') {
            return 'unknown';
        }
        foreach (self::KNOWN_TABLES as $short) {
            if ($t === $short) {
                return $short;
            }
            $suffix = '_' . $short;
            $length = strlen($suffix);
            if (strlen($t) > $length && substr($t, -$length) === $suffix) {
                return $short;
            }
        }

        return 'unknown';
    }

    /**
     * آیا این رخداد به موضوعِ پروندهٔ جاری مربوط است؟ (فقط بولین — فید منتشر نمی‌شود)
     */
    private static function is_focus(string $table, string $message, string $statement): bool
    {
        $haystack = strtolower($table . ' ' . $message . ' ' . $statement);

        return str_contains($haystack, 'custom_role') || str_contains($haystack, 'cpms_clinics');
    }

    /**
     * بافت: نامِ تستِ در حال اجرا + نخستین قابِ محصول — هر دو اعتبارسنجی می‌شوند.
     *
     * @return array{test: string, product: string}
     */
    private static function context(): array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96);
        $test = '(unknown)';
        $product = '(unknown)';

        foreach ($frames as $frame) {
            $class = isset($frame['class']) ? (string) $frame['class'] : '';
            $function = isset($frame['function']) ? (string) $frame['function'] : '';
            if ($class === '') {
                continue;
            }
            $isTestClass = str_starts_with($class, 'ClinicCore\\Tests\\');
            if ($test === '(unknown)' && $isTestClass && str_starts_with($function, 'test')) {
                $test = self::identifier($class . '::' . $function);
            }
            if ($product === '(unknown)' && str_starts_with($class, 'ClinicCore\\') && !$isTestClass) {
                $product = self::identifier($class . '::' . $function);
            }
        }

        return ['test' => $test, 'product' => $product];
    }

    /**
     * واقعیتِ جداولِ کلیدی در پایانِ اجرا — فقط بولین وجود/دیده‌شدن (بدون شمارشِ ردیف).
     *
     * @return list<array{table: string, real_exists: bool, name_visible: bool}>
     */
    private static function table_facts(object $wpdb): array
    {
        $dbh = $wpdb->dbh ?? null;
        if (!($dbh instanceof \mysqli) || !isset($wpdb->prefix)) {
            return [];
        }

        $facts = [];
        foreach (self::KNOWN_TABLES as $short) {
            $table = (string) $wpdb->prefix . $short;
            $table = str_replace(["'", "\\", "\n", "\r"], '', $table);
            $real = self::scalar($dbh, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'");
            $visible = 0;
            if ($result = mysqli_query($dbh, 'SHOW TABLES LIKE ' . "'" . $table . "'")) {
                $visible = (int) mysqli_num_rows($result);
                mysqli_free_result($result);
            }
            $facts[] = [
                'table' => $short,
                'real_exists' => ((int) $real) > 0,
                'name_visible' => $visible > 0,
            ];
        }

        return $facts;
    }

    private static function scalar(\mysqli $link, string $sql): string
    {
        $result = mysqli_query($link, $sql);
        if (!$result) {
            return '0';
        }
        $row = mysqli_fetch_row($result);
        mysqli_free_result($result);

        return isset($row[0]) ? (string) $row[0] : '0';
    }
}
