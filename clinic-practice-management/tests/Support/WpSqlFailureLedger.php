<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

/**
 * دفترِ تشخیصیِ خطاهای SQL برای اجرای Integration — **فقط تست، هرگز production**.
 *
 * چرا لازم است (شاهدِ کد، نه حدس):
 *  ۱) `CpmsDb::$strict` پیش‌فرض `false` است و `setStrict(true)` تنها داخل
 *     `MigrationRunner` روشن/خاموش می‌شود؛ پس `ensureNoSqlError()` بیرون از
 *     migration عملاً هیچ‌چیز را منتشر نمی‌کند و `insert()` فقط `false`
 *     برمی‌گرداند.
 *  ۲) `CustomRoleRepository::define()` در این حالت هر INSERT ناموفق را با پیام
 *     عمومی «custom role key already defined…» گزارش می‌کند — یعنی متن/کدِ خطای
 *     واقعیِ MySQL دور ریخته می‌شود.
 *  ۳) WordPress خودش منبعِ کاملِ این خطاها را نگه می‌دارد:
 *     `wpdb::query()` هنگام شکست `$this->last_error` را از `mysqli_error()`
 *     پر می‌کند و `print_error()` **پیش از** بررسی `suppress_errors` رکورد
 *     `{query, error_str}` را به `$GLOBALS['EZSQL_ERROR']` اضافه می‌کند
 *     (سورس 6.7.2 — verify شد). پس حتی با suppression، متنِ خطا موجود است و
 *     فقط چاپ نمی‌شود.
 *
 * این کلاس همان داده را بدون تغییر هیچ معنایی از محصول/تست، در پایانِ فرآیند
 * به STDERR می‌ریزد تا در لاگِ CI و mirror کامنت PR خواندنی شود. هیچ انتظار
 * تست، هیچ مجوزدهی، هیچ اسکیمایی و هیچ رفتار محصولی تغییر نمی‌کند:
 *  • فیلتر `query` فقط *تماشا* می‌کند و کوئری را دست‌نخورده برمی‌گرداند؛
 *  • هستهٔ دفتر (dump در shutdown) مستقل از فیلترهاست، چون `EZSQL_ERROR` را
 *    می‌خواند؛ بعضی تست‌های هم‌روندی `remove_all_filters('query')` می‌زنند و
 *    نباید شاهدِ خطا را از دست بدهیم.
 *
 * داده‌های منتشرشده عمداً محدود و sanitize شده‌اند: عملیات + نام جدول (بدون
 * VALUES)، متنِ خطای درایور (فقط شناسه‌های مصنوعیِ fixture)، و برای INSERTِ
 * نقش‌ها فقط دو فیلدِ درخواستیِ `clinic_id`/`role_key`. هیچ اعتبارنامه، توکن،
 * دادهٔ بیمار یا SQL کاملِ دارای مقدار چاپ نمی‌شود.
 */
final class WpSqlFailureLedger
{
    /** سقفِ امضاهای نگه‌داشته‌شده (بالاتر از سقفِ چاپ، برای انتخابِ اولویت‌دار). */
    private const MAX_SIGNATURES = 60;

    /** سقفِ خطوطِ چاپ‌شده در بلوکِ پایانی (mirror کامنت PR فقط tail را می‌خواند). */
    private const MAX_PRINTED = 14;

    /** سقفِ طولِ متنِ خطای درایور. */
    private const MESSAGE_LIMIT = 200;

    /** @var array<string, array<string, mixed>> امضا → نخستین رخداد + شمارش */
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
        // (wpdb::query() مقدار last_error را برای هر دستور، بعد از اجرا، بازنویسی می‌کند).
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
     * ثبتِ یک شکست، در لحظه‌ای که متنِ خطا و (در صورت امکان) کد/SQLSTATE و بافتِ تست در دسترس‌اند.
     */
    private static function record(object $wpdb, string $lastError): void
    {
        $ledgers = isset($GLOBALS['EZSQL_ERROR']) && is_array($GLOBALS['EZSQL_ERROR']) ? $GLOBALS['EZSQL_ERROR'] : [];
        $entry = $ledgers === [] ? null : end($ledgers);

        $statement = '';
        $message = $lastError;
        if (is_array($entry)) {
            $statement = isset($entry['query']) && is_string($entry['query']) ? $entry['query'] : '';
            $message = isset($entry['error_str']) && is_string($entry['error_str']) && $entry['error_str'] !== ''
                ? $entry['error_str']
                : $lastError;
        }
        if ($statement === '' && isset($wpdb->last_query) && is_string($wpdb->last_query)) {
            $statement = $wpdb->last_query;
        }

        $table = self::table_of($statement);
        $category = self::categorize($message);
        $shaped = self::shape($statement, $message);
        $signature = $category . '|' . $table . '|' . $shaped;

        self::$failures++;

        if (isset(self::$signatures[$signature])) {
            self::$signatures[$signature]['count']++;

            return;
        }

        if (count(self::$signatures) >= self::MAX_SIGNATURES) {
            self::$dropped++;

            return;
        }

        $driver = self::driver_facts($wpdb);
        $focus = self::is_focus($table, $message, $statement);

        self::$signatures[$signature] = [
            'seq' => ++self::$sequence,
            'count' => 1,
            'category' => $category,
            'table' => $table,
            'op' => self::operation_of($statement),
            'mysql_errno' => $driver['errno'],
            'sqlstate' => $driver['sqlstate'],
            'message' => self::clean($message, self::MESSAGE_LIMIT),
            'clinic_id' => self::insert_field($statement, 'clinic_id'),
            'role_key' => self::insert_field($statement, 'role_key'),
            'test' => self::context(),
            'focus' => $focus,
        ];

        // رخدادهای مرتبط با نقش‌های سفارشی فوراً چاپ می‌شوند تا حتی اگر لاگ بریده شد موجود باشند.
        if ($focus) {
            fwrite(STDERR, PHP_EOL . 'CPMS_SQL_FAILURE_EVIDENCE ' . json_encode(
                self::$signatures[$signature],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . PHP_EOL);
        }
    }

    /**
     * بلوکِ پایانی: خواندنِ کلِ `EZSQL_ERROR` (مستقل از فیلترها) + واقعیتِ جداول.
     *
     * این بلوک آخرین خطوطِ لاگ است و توسط mirror کامنت PR (بخش tail) خوانده می‌شود.
     */
    public static function flush(): void
    {
        /*
         * Parent-process-only. Every pcntl_fork() child inherits this shutdown
         * callback; running it there would execute table_facts() queries on the
         * inherited WordPress connection, i.e. a child process writing statements
         * to and reading responses from a session owned by the parent. Two
         * independently observed consequences of that shape are exactly the two
         * long-standing failures: the parent turning an OK packet of a child
         * statement into its own `insert_id = 0` (patient identity lost) and the
         * parent reading a child's COUNT(*) result as its own scalar value ("1").
         * The owning process needs no database failure ledger of a child's making.
         */
        if (null !== self::$ownerPid && getmypid() !== self::$ownerPid) {
            return;
        }

        try {
            global $wpdb;

            // تلاش برای اینکه این بلوک آخرین خطوطِ لاگ باشد (mirror فقط tail را می‌خواند).
            if (defined('STDOUT')) {
                @fflush(STDOUT);
            }

            $lines = [];
            $lines[] = 'CPMS_SQL_FAILURE_LEDGER_BEGIN failures=' . self::$failures
                . ' distinct=' . count(self::$signatures)
                . ' dropped=' . self::$dropped
                . ' ezsql=' . (isset($GLOBALS['EZSQL_ERROR']) && is_array($GLOBALS['EZSQL_ERROR']) ? count($GLOBALS['EZSQL_ERROR']) : 0);

            $ordered = self::$signatures;
            uasort($ordered, static function (array $a, array $b): int {
                if ($a['focus'] !== $b['focus']) {
                    return $a['focus'] ? -1 : 1;
                }

                return $a['seq'] <=> $b['seq'];
            });

            $printed = 0;
            $byCategory = [];
            foreach ($ordered as $row) {
                $byCategory[$row['category']] = ($byCategory[$row['category']] ?? 0) + $row['count'];
                if ($printed >= self::MAX_PRINTED) {
                    continue;
                }
                $printed++;
                $lines[] = 'CPMS_SQL_FAILURE_LEDGER ' . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $lines[] = 'CPMS_SQL_FAILURE_LEDGER_SUMMARY ' . json_encode([
                'by_category' => $byCategory,
                'printed' => $printed,
                'kept' => count(self::$signatures),
                'dropped' => self::$dropped,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (is_object($wpdb)) {
                foreach (self::table_facts($wpdb) as $fact) {
                    $lines[] = 'CPMS_SQL_FAILURE_LEDGER_TABLE ' . json_encode($fact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            $lines[] = 'CPMS_SQL_FAILURE_LEDGER_END';

            fwrite(STDERR, PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL);
        } catch (\Throwable $ignored) {
            // در shutdown هرگز نباید استثنا بالا برود.
        }
    }

    /**
     * errno / SQLSTATE درایور در همان لحظه (best-effort).
     *
     * @return array{errno: int|string, sqlstate: string|null}
     */
    private static function driver_facts(object $wpdb): array
    {
        $dbh = $wpdb->dbh ?? null;
        if (!($dbh instanceof \mysqli)) {
            return ['errno' => 'unavailable', 'sqlstate' => null];
        }

        $errno = mysqli_errno($dbh);
        $sqlstate = mysqli_sqlstate($dbh);

        return [
            'errno' => $errno > 0 ? $errno : 'unavailable',
            'sqlstate' => is_string($sqlstate) && $sqlstate !== '00000' ? $sqlstate : null,
        ];
    }

    /**
     * دستهٔ خطا — فقط برای خوانایی گزارش؛ ملاک، متنِ خودِ درایور است.
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

    private static function operation_of(string $statement): string
    {
        $op = strtoupper((string) preg_replace('/^\s*([a-z]+)\b.*/is', '$1', trim($statement)));

        return $op === '' ? 'unknown' : substr($op, 0, 12);
    }

    /**
     * نام جدولِ هدف — تنها بخشی از SQL که منتشر می‌شود (بدون هیچ مقداری).
     */
    private static function table_of(string $statement): string
    {
        if (preg_match('/^\s*(?:insert\s+into|replace\s+into|update|delete\s+from|select\s+.+?\s+from)\s+`?([a-z0-9_]+)`?/is', $statement, $m)) {
            $table = strtolower($m[1]);
            if (str_contains($table, 'custom_role') || str_contains($table, 'cpms_')) {
                return $table;
            }

            return preg_replace('/^[a-z0-9]*_/', '', $table) ?: $table;
        }

        return 'unknown';
    }

    /**
     * امضای شکلِ خطا (بدون مقدار): برای ادغامِ تکرارها.
     */
    private static function shape(string $statement, string $message): string
    {
        $op = self::operation_of($statement);
        $shape = (string) preg_replace("/'[^']*'/", "'?'", $message);
        $shape = (string) preg_replace('/\d+/', 'N', $shape);

        return $op . '::' . substr($shape, 0, 120);
    }

    /**
     * آیا این رخداد به موضوعِ پروندهٔ جاری مربوط است؟ (اولویتِ چاپ)
     */
    private static function is_focus(string $table, string $message, string $statement): bool
    {
        $haystack = strtolower($table . ' ' . $message . ' ' . $statement);

        return str_contains($haystack, 'custom_role') || str_contains($haystack, 'cpms_clinics');
    }

    /**
     * مقدارِ یک ستونِ INSERT — فقط برای دو فیلدِ مصنوعیِ خواسته‌شده (clinic_id/role_key).
     * هیچ ستونِ دیگری استخراج نمی‌شود و SQL کامل هرگز چاپ نمی‌شود.
     */
    private static function insert_field(string $statement, string $field): ?string
    {
        if (!preg_match('/^\s*insert\s+into\s+`?([a-z0-9_]+)`?\s*\(([^)]*)\)\s*values\s*\(([^)]*)\)/is', $statement, $m)) {
            return null;
        }
        if (!str_contains(strtolower($m[1]), 'custom_role')) {
            return null;
        }

        $columns = array_map(static fn(string $c): string => trim($c, " \t\n`'"), explode(',', $m[2]));
        $values = self::split_values($m[3]);
        $index = array_search($field, $columns, true);
        if ($index === false || !isset($values[$index])) {
            return null;
        }

        $value = trim($values[$index]);
        $value = trim($value, "'");

        return $value === '' && $field === 'role_key' ? null : substr($value, 0, 64);
    }

    /**
     * جداکردنِ مقادیرِ VALUES با احترام به رشته‌های نقل‌قولی.
     *
     * @return list<string>
     */
    private static function split_values(string $values): array
    {
        $out = [];
        $buffer = '';
        $quoted = false;
        $length = strlen($values);
        for ($i = 0; $i < $length; $i++) {
            $char = $values[$i];
            if ($char === "'") {
                $quoted = !$quoted;
            }
            if ($char === ',' && !$quoted) {
                $out[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $out[] = $buffer;

        return $out;
    }

    /**
     * بافت: نامِ تستِ در حال اجرا + نخستین قابِ محصول (کمک به attribution، نه حکم).
     */
    private static function context(): array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96);
        $test = '(unknown)';
        $product = null;

        foreach ($frames as $frame) {
            $class = isset($frame['class']) ? (string) $frame['class'] : '';
            $function = isset($frame['function']) ? (string) $frame['function'] : '';
            if ($class === '' ) {
                continue;
            }
            $isTestClass = str_starts_with($class, 'ClinicCore\\Tests\\');
            if ($test === '(unknown)' && $isTestClass && str_starts_with($function, 'test')) {
                $test = $class . '::' . $function;
            }
            if ($product === null && str_starts_with($class, 'ClinicCore\\') && !$isTestClass) {
                $product = $class . '::' . $function;
            }
        }

        return ['test' => $test, 'product' => $product];
    }

    private static function clean(string $message, int $limit): string
    {
        $message = (string) preg_replace('/\s+/', ' ', $message);

        return substr($message, 0, $limit);
    }

    /**
     * واقعیتِ جداولِ کلیدی در پایانِ اجرا: جدولِ واقعی موجود است؟ سایهٔ موقت هست؟
     * (SHOW TABLES جدول موقت را هم فهرست می‌کند؛ information_schema فقط واقعی را.)
     *
     * @return list<array<string, mixed>>
     */
    private static function table_facts(object $wpdb): array
    {
        $dbh = $wpdb->dbh ?? null;
        if (!($dbh instanceof \mysqli) || !isset($wpdb->prefix)) {
            return [];
        }

        $facts = [];
        foreach (['cpms_custom_role_defs', 'cpms_custom_role_capabilities', 'cpms_clinics'] as $short) {
            $table = (string) $wpdb->prefix . $short;
            // واقعیتِ جدول از information_schema (که جدول موقت را نشان نمی‌دهد) و
            // دیده‌شدنِ نام در SHOW TABLES (که جدول موقت را هم فهرست می‌کند) — دو
            // مشاهدهٔ مستقل؛ نتیجه‌گیریِ «سایه» به خواننده واگذار می‌شود.
            $real = self::scalar($dbh, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'");
            $visible = 0;
            $rows = 'unavailable';
            if ($result = mysqli_query($dbh, 'SHOW TABLES LIKE ' . "'" . $table . "'")) {
                $visible = (int) mysqli_num_rows($result);
                mysqli_free_result($result);
            }
            if ($result = mysqli_query($dbh, 'SELECT COUNT(*) FROM `' . $table . '`')) {
                $row = mysqli_fetch_row($result);
                $rows = isset($row[0]) ? (string) $row[0] : 'unavailable';
                mysqli_free_result($result);
            }
            $facts[] = [
                'table' => $table,
                'real_exists' => ((int) $real) > 0,
                'name_visible' => $visible > 0,
                'rows_resolved_on_connection' => $rows,
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
