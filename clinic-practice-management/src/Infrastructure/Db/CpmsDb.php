<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Db;

use ClinicCore\Infrastructure\Profiling\RequestProfiler;
use RuntimeException;
use wpdb;

/**
 * wrapper نازک روی $wpdb — فقط نقطه‌ی واحد DB Access (NFR-MAINT-2).
 *
 * - همه Queryها با prepare (تثبیت NFR-SEC-3 — بدون Interpolation مقدار).
 * - transactional() برای عملیات حیاتی (رزرو، پرداخت، complete/checkout).
 *
 * Strict mode (F1-2): به‌طور پیش‌فرض خاموش (رفتار soft — خطای SQL →
 * false/0/NULL، مناسب مسیرهای عملیاتی که شکستشان نباید request را
 * بکِشد). با setStrict(true) هر خطای SQL بلافاصله RuntimeException
 * می‌دهد — MigrationRunner دورِ هر Migration آن را روشن می‌کند تا
 * Migration شکست‌خورده هرگز «موفق + version ثبت‌شده» به نظر نرسد.
 */
final class CpmsDb
{
    private bool $strict = false;

    /**
     * عمق تراکنش‌های بازِ همین اتصال (فقط برای transactional()). صفر = هیچ
     * تراکنش CpmsDb فعال نیست. مقدار همیشه در finally کاهش می‌یابد.
     */
    private int $tx_depth = 0;

    /**
     * Rollback generation of this connection: incremented whenever a
     * transaction (or nested savepoint) is rolled back, including the
     * rollback attempted after a failed COMMIT. Consumers caching
     * snapshots read inside a transaction (Settings) compare against
     * this value: a snapshot taken before the latest rollback may hold
     * values that were never committed and must not be trusted.
     */
    private int $rollback_epoch = 0;

    public function rollback_epoch(): int {
        return $this->rollback_epoch;
    }

    public function __construct(private readonly wpdb $wpdb, private string $prefix = 'cpms_')
    {
    }

    public function setStrict(bool $strict): void
    {
        $this->strict = $strict;
    }

    public function is_strict(): bool {
        return $this->strict;
    }

    /**
     * فقط در Strict mode: هر خطای SQLِ فراخوانیِ آخر باید Exception شود
     * (wpdb خطا را در last_error نگه می‌دارد و در موفقیت پاک می‌کند).
     */
    private function ensureNoSqlError(): void
    {
        if ($this->strict && $this->wpdb->last_error !== '') {
            throw new RuntimeException('SQL error: ' . $this->wpdb->last_error);
        }
    }

    /**
     * نام فیزیکی جدول: `{wp_prefix}cpms_{name}`.
     *
     * پیشوند `cpms_` بخشی از قرارداد مستند (ERD/SRS/دیتادیکشنری) است و همیشه
     * در نام نهایی حفظ می‌شود؛ str_replace فقط تحمل فراخوانی با/بدون پیشوند
     * را می‌دهد (table('cpms_patients') === table('patients')).
     */
    public function table(string $short): string
    {
        $name = preg_replace('/^' . preg_quote($this->prefix, '/') . '/', '', $short);

        return $this->wpdb->prefix . $this->prefix . $name;
    }

    public function dbPrefix(): string
    {
        return $this->wpdb->prefix;
    }

    /** Count CPMS tables using the active WordPress database prefix. */
    public function count_cpms_tables(): int {
        $prefix_pattern = $this->wpdb->esc_like( $this->wpdb->prefix . $this->prefix ) . '%';

        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s',
            [ $prefix_pattern ]
        );
    }

    public function prepare(string $sql, array $params): string
    {
        if ($params === []) {
            return $sql;
        }

        return $this->wpdb->prepare($sql, ...array_values($params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function query(string $sql, array $params = []): bool
    {
        // Phase 17 profiling: observed only when the server-side profiler is armed
        // (one memoized check otherwise); counted even on SQL error, matching
        // `$wpdb->num_queries` semantics.
        $profile_start = RequestProfiler::query_start();
        $ok = $this->wpdb->query($this->prepare($sql, $params)) !== false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return $ok;
    }

    /**
     * اجرای نوشتاری با برگرداندن تعداد سطرهای اثرگرفته (برخلاف query که bool است).
     * خطای SQL → 0 (رفتار soft؛ برای لاگ/پاک‌سازی‌های دوره‌ای کافی است) —
     * مگر در Strict mode که Exception می‌دهد.
     */
    public function execute(string $sql, array $params = []): int
    {
        $profile_start = RequestProfiler::query_start();
        $result = $this->wpdb->query($this->prepare($sql, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return is_int($result) ? $result : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchRow(string $sql, array $params = []): ?array
    {
        $profile_start = RequestProfiler::query_start();
        $row = $this->wpdb->get_row($this->prepare($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return $row === null ? null : (array) $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $profile_start = RequestProfiler::query_start();
        $rows = $this->wpdb->get_results($this->prepare($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return is_array($rows) ? array_map(static fn ($r) => (array) $r, $rows) : [];
    }

    /**
     * Fetch rows while distinguishing an empty result from a failed query.
     * Unlike the ordinary soft-mode reader, this method always throws on a
     * database error so callers can fail closed without treating it as absence.
     *
     * @return list<array<string, mixed>>
     */
    public function fetch_all_strict( string $sql, array $params = [] ): array {
        $profile_start = RequestProfiler::query_start();
        $rows          = $this->wpdb->get_results( $this->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );

        if ( $this->wpdb->last_error !== '' || ! is_array( $rows ) ) {
            throw new RuntimeException( 'Database read failed.' );
        }

        return array_map( static fn ( $row ) => (array) $row, $rows );
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $profile_start = RequestProfiler::query_start();
        $value = $this->wpdb->get_var($this->prepare($sql, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): bool
    {
        // wpdb::insert در موفقیت int (تعداد ردیف) و در خطا false برمی‌گرداند —
        // نه bool؛ بدون این نرمال‌سازی هر insert موفق TypeError می‌داد.
        $profile_start = RequestProfiler::query_start();
        $ok = $this->wpdb->insert($this->table($table), $data) !== false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return $ok;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update( string $table, array $data, array $where, bool $throw_on_error = false ): int
    {
        $profile_start = RequestProfiler::query_start();
        $result = $this->wpdb->update(
            $this->table($table),
            $data,
            $where,
            array_fill(0, count($data), '%s'),
            array_fill(0, count($where), '%s')
        );
        RequestProfiler::query_end( $profile_start );
        if ( false === $result && $throw_on_error ) {
            $error = '' !== $this->wpdb->last_error ? $this->wpdb->last_error : 'unknown error';
            throw new RuntimeException( 'Database update failed: ' . $error );
        }
        $this->ensureNoSqlError();

        return (int) $result;
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $profile_start = RequestProfiler::query_start();
        $result = $this->wpdb->delete(
            $this->table($table),
            $where,
            array_fill(0, count($where), '%s')
        );
        RequestProfiler::query_end( $profile_start );
        $this->ensureNoSqlError();

        return (int) $result;
    }

    private function tx_control( string $sql ): void {
        $profile_start = RequestProfiler::query_start();
        $result        = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        RequestProfiler::query_end( $profile_start );
        if ( false === $result || '' !== $this->wpdb->last_error ) {
            $error = '' !== $this->wpdb->last_error ? $this->wpdb->last_error : 'unknown error';
            throw new RuntimeException( 'transaction control failed (' . $sql . '): ' . $error );
        }
    }

    /**
     * Transaction + Row Lock انتخابی.
     *
     * در Test-Bootstrap، افعال تراکنش (با نشانگر cpms در ابتدای SQL) به
     * SAVEPOINT/RELEASE/ROLLBACK-TO بازنویسی می‌شوند تا داخل تراکنش بازِ
     * WP Test Suite بدون COMMIT ضمنی اجرا شوند (نشت Fixture بین تست‌ها).
     * رفتار Production بدون تغییر است — MySQL comment را نادیده می‌گیرد.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transactional( callable $callback ) {
        // F2-1 (atomicidade): فراخوانی تودرتو نباید COMMIT ضمنی روی تراکنش
        // بیرونی بزند. MySQL با START TRANSACTION تراکنش باز را COMMIT
        // می‌کند، پس هر فراخوانیِ تودرتو به SAVEPOINT تبدیل می‌شود: مرزِ
        // تراکنشِ سطحِ صفر همان بیرونی می‌ماند و شکستِ داخلی فقط تا همان
        // Savepoint عقب می‌رود. نامِ Savepoint با «nested_» از نام‌های
        // bootstrap تست جدا نگه داشته می‌شود.
        $savepoint = $this->tx_depth > 0 ? 'cpms_sp_nested_' . $this->tx_depth : null;

        if ( $savepoint === null ) {
            $this->tx_control( '/*cpms*/ START TRANSACTION' );
        } else {
            $this->tx_control( '/*cpms*/ SAVEPOINT ' . $savepoint );
        }
        ++$this->tx_depth;

        try {
            try {
                $result = $callback();
            } catch ( \Throwable $e ) {
                try {
                    $this->undo( $savepoint );
                } catch ( \Throwable $rollback_ex ) {
                    throw new RuntimeException( 'transaction rollback failed: ' . $rollback_ex->getMessage(), 0, $e );
                }
                throw $e;
            }
            try {
                if ( $savepoint === null ) {
                    $this->tx_control( '/*cpms*/ COMMIT' );
                } else {
                    $this->tx_control( '/*cpms*/ RELEASE SAVEPOINT ' . $savepoint );
                }
            } catch ( \Throwable $commit_ex ) {
                try {
                    $this->undo( $savepoint );
                } catch ( \Throwable $rollback_ex ) {
                    throw new RuntimeException( 'transaction commit failed and rollback also failed: ' . $rollback_ex->getMessage(), 0, $commit_ex );
                }
                throw $commit_ex;
            }

            return $result;
        } finally {
            --$this->tx_depth;
        }
    }

    /**
     * بازگردانی دقیق: در تراکنش سطح صفر ROLLBACK کامل، در تودرتو صرفاً
     * ROLLBACK TO SAVEPOINT + آزادسازی آن (تراکنش بیرونی دست‌نخورده می‌ماند).
     */
    private function undo( ?string $savepoint ): void {
        // Bump FIRST: even if the rollback statement itself fails, cached
        // snapshots from this transaction are untrustworthy from now on.
        ++$this->rollback_epoch;
        if ( $savepoint === null ) {
            $this->tx_control( '/*cpms*/ ROLLBACK' );

            return;
        }

        $this->tx_control( '/*cpms*/ ROLLBACK TO SAVEPOINT ' . $savepoint );
        $this->tx_control( '/*cpms*/ RELEASE SAVEPOINT ' . $savepoint );
    }

    /**
     * SELECT ... FOR UPDATE — برای Claim (Slot)، Invoice، Visit (Anti Double-Execute).
     *
     * @return array<string, mixed>|null
     */
    public function fetchRowForUpdate(string $sql, array $params = []): ?array
    {
        $sql = rtrim($sql, ';') . ' FOR UPDATE';

        return $this->fetchRow($sql, $params);
    }

    /**
     * اجرای یک SELECT به همراه FOR UPDATE برای قفل‌گذاری روی چندین ردیف.
     *
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAllForUpdate(string $sql, array $params = []): array
    {
        $sql = rtrim($sql, ';') . ' FOR UPDATE';

        return $this->fetchAll($sql, $params);
    }

    public function wpdb_last_insert_id(): int
    {
        return (int) $this->wpdb->insert_id;
    }

    public function wpdb(): wpdb
    {
        return $this->wpdb;
    }

    /**
     * NOW(3) UTC — مقدار را در PHP می‌سازیم تا مستقل از TZ سرور MySQL باشد (ADR-0013).
     */
    public function nowUtc(int $microSeconds = 0): string
    {
        $ts = microtime(true) + $microSeconds / 1_000_000;
        $dt = (new \DateTimeImmutable('@' . (string) (int) $ts))->setTimezone(new \DateTimeZone('UTC'));

        return $dt->format('Y-m-d H:i:s') . sprintf('.%03d', (int) round(microtime(true) * 1000) % 1000);
    }

    public function nowUtcSql(): string
    {
        return gmdate('Y-m-d H:i:s', time()) . '.000';
    }
}
