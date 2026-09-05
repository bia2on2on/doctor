<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Db;

use wpdb;

/**
 * wrapper نازک روی $wpdb — فقط نقطه‌ی واحد DB Access (NFR-MAINT-2).
 *
 * - همه Queryها با prepare (تثبیت NFR-SEC-3 — بدون Interpolation مقدار).
 * - transactional() برای عملیات حیاتی (رزرو، پرداخت، complete/checkout).
 */
final class CpmsDb
{
    public function __construct(private readonly wpdb $wpdb, private string $prefix = 'cpms_')
    {
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

    public function prepare(string $sql, array $params): string
    {
        if ($params === []) {
            return $sql;
        }

        return $this->wpdb->prepare($sql, ...array_values($params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function query(string $sql, array $params = []): bool
    {
        return $this->wpdb->query($this->prepare($sql, $params)) !== false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchRow(string $sql, array $params = []): ?array
    {
        $row = $this->wpdb->get_row($this->prepare($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return $row === null ? null : (array) $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $rows = $this->wpdb->get_results($this->prepare($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_array($rows) ? array_map(static fn ($r) => (array) $r, $rows) : [];
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        return $this->wpdb->get_var($this->prepare($sql, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): bool
    {
        return $this->wpdb->insert($this->table($table), $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $result = $this->wpdb->update(
            $this->table($table),
            $data,
            $where,
            array_fill(0, count($data), '%s'),
            array_fill(0, count($where), '%s')
        );

        return (int) $result;
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $result = $this->wpdb->delete(
            $this->table($table),
            $where,
            array_fill(0, count($where), '%s')
        );

        return (int) $result;
    }

    /**
     * Transaction + Row Lock انتخابی.
     *
     * @template T
     *
     * @param callable $fn
     *
     * @return T
     */
    public function transactional(callable $fn)
    {
        $this->wpdb->query('START TRANSACTION');
        try {
            $result = $fn();
            $this->wpdb->query('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }
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
