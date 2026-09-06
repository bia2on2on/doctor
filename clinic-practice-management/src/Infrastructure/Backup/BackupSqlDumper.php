<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Backup;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Dump دیتابیس CPMS به SQL خالص — بدون وابستگی به mysqldump (Shared Hosting).
 *
 * اصول:
 *  - فقط جدول‌های `cpms_*` (افزونه) — WP Core و جدول‌های افزونه‌های دیگر هرگز
 *    در dump نیستند (بازیابی ایمن و بدون دست‌زدن به Core — spec §23/§38).
 *  - Snapshot سازگار: BEGIN با نشانگر cpms → خواندن همه‌ی جدول‌ها با
 *    REPEATABLE READ (معادل --single-transaction) → COMMIT. بدون قفل نوشتن.
 *  - Batch + OFFSET با ORDER BY PK (یا ORDER BY NULL) — حافظه‌ی محدود.
 *  - Escape امن (wpdb->_real_escape)؛ مقادیر همیشه به‌صورت رشته‌ی literal.
 *  - خروجی برای اجرای تک‌Statement با SqlStatementSplitter سازگار است.
 */
final class BackupSqlDumper
{
    private const BATCH = 500;

    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return list<array{name: string, rows: int}>
     */
    public function dumpToFile(string $path, ?string $tableLike = null): array
    {
        $wpdb = $this->db->wpdb();
        $tables = $this->tables($tableLike);
        if ($tables === []) {
            throw BackupException::of('CLINIC_BACKUP_NO_TABLES', 'no cpms tables found to dump');
        }

        $fh = @fopen($path, 'wb');
        if ($fh === false) {
            throw BackupException::of('CLINIC_BACKUP_IO', 'cannot write dump file');
        }

        $stats = [];
        $this->line($fh, '-- CPMS consistent backup dump');
        $this->line($fh, '-- Created: ' . gmdate('c'));
        $this->line($fh, '-- Tables: ' . count($tables));
        $this->line($fh, 'SET NAMES utf8mb4;');
        $this->line($fh, 'SET FOREIGN_KEY_CHECKS = 0;');
        $this->line($fh, '');

        // Snapshot سازگار (REPEATABLE READ) — بدون قفل
        $this->db->query('/*cpms*/ START TRANSACTION'); // phpcs:ignore WordPress.DB.RestrictedClasses -- marker برای تست‌ها
        try {
            foreach ($tables as $table) {
                $fq = '`' . $table . '`';
                $create = $this->createStatement($table);
                $pk = $this->primaryKeyColumns($table);
                $this->line($fh, '-- ---- table: ' . $table . ' ----');
                $this->line($fh, 'DROP TABLE IF EXISTS ' . $fq . ';');
                foreach ($create as $chunk) {
                    $this->line($fh, $chunk);
                }
                $this->line($fh, ';');

                $cols = $this->columns($table);
                if ($cols === []) {
                    $stats[] = ['name' => $table, 'rows' => 0];
                    continue;
                }
                $colSql = implode(',', array_map(static fn (string $c): string => '`' . $c . '`', $cols));
                $orderBy = $pk !== [] ? 'ORDER BY ' . implode(',', array_map(static fn (string $c): string => '`' . $c . '`', $pk)) : 'ORDER BY NULL';

                $rows = 0;
                $offset = 0;
                while (true) {
                    $batch = $wpdb->get_results(
                        $wpdb->prepare(
                            'SELECT * FROM ' . $fq . ' ' . $orderBy . ' LIMIT %d OFFSET %d',
                            [self::BATCH, $offset]
                        ),
                        ARRAY_A
                    );
                    $n = is_array($batch) ? count($batch) : 0;
                    if ($n === 0) {
                        break;
                    }
                    $rows += $n;
                    $this->writeInsertBatch($fh, $fq, $cols, $batch, $wpdb);
                    if ($n < self::BATCH) {
                        break;
                    }
                    $offset += $n;
                }
                $this->line($fh, '');
                $stats[] = ['name' => $table, 'rows' => $rows];
            }
        } finally {
            $this->db->query('/*cpms*/ COMMIT');
        }

        $this->line($fh, 'SET FOREIGN_KEY_CHECKS = 1;');
        $this->line($fh, '-- end of cpms dump');
        fclose($fh);

        return $stats;
    }

    /**
     * @return list<string>
     */
    private function tables(?string $tableLike): array
    {
        $wpdb = $this->db->wpdb();
        $prefix = $this->db->dbPrefix();
        $pattern = ($tableLike ?? $prefix . 'cpms_%');
        $rows = $wpdb->get_results($wpdb->prepare('SHOW TABLES LIKE %s', [$pattern]), ARRAY_N);
        $tables = [];
        foreach ((array) $rows as $r) {
            $tables[] = (string) $r[0];
        }
        sort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function createStatement(string $table): array
    {
        $wpdb = $this->db->wpdb();
        $rows = $wpdb->get_results('SHOW CREATE TABLE `' . $table . '`', ARRAY_N);
        if (!is_array($rows) || !isset($rows[0][1])) {
            throw BackupException::of('CLINIC_BACKUP_IO', 'SHOW CREATE TABLE failed: ' . $table);
        }
        $stmt = (string) $rows[0][1];

        return explode("\n", $stmt);
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        $wpdb = $this->db->wpdb();
        $rows = $wpdb->get_results('SHOW COLUMNS FROM `' . $table . '`', ARRAY_A);
        $cols = [];
        foreach ((array) $rows as $r) {
            $cols[] = (string) $r['Field'];
        }

        return $cols;
    }

    /**
     * @return list<string> ستون‌های PK (ترتیب Key) — خالی اگر PK نیست/مرکب خاص
     */
    private function primaryKeyColumns(string $table): array
    {
        $wpdb = $this->db->wpdb();
        $rows = $wpdb->get_results('SHOW KEYS FROM `' . $table . '` WHERE Key_name = \'PRIMARY\'', ARRAY_A);
        $cols = [];
        foreach ((array) $rows as $r) {
            $cols[(int) $r['Seq_in_index']] = (string) $r['Column_name'];
        }
        ksort($cols);

        return array_values($cols);
    }

    /**
     * @param list<string> $cols
     * @param list<array<string, mixed>> $batch
     */
    private function writeInsertBatch($fh, string $fq, array $cols, array $batch, \wpdb $wpdb): void
    {
        $values = [];
        foreach ($batch as $row) {
            $parts = [];
            foreach ($cols as $c) {
                $v = $row[$c] ?? null;
                if ($v === null) {
                    $parts[] = 'NULL';
                } elseif (is_int($v) || is_float($v)) {
                    $parts[] = (string) $v;
                } else {
                    $parts[] = "'" . $wpdb->_real_escape((string) $v) . "'";
                }
            }
            $values[] = '(' . implode(',', $parts) . ')';
        }
        $sql = 'INSERT INTO ' . $fq . ' (' . implode(',', array_map(static fn (string $c): string => '`' . $c . '`', $cols)) . ') VALUES' . "\n" . implode(",\n", $values) . ';';
        fwrite($fh, $sql . "\n");
    }

    private function line($fh, string $s): void
    {
        fwrite($fh, $s . "\n");
    }
}
