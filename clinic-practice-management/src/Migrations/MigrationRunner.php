<?php

declare(strict_types=1);

namespace ClinicCore\Migrations;

use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use RuntimeException;

/**
 * Migration System (SRS §47, NFR-MAINT-4):
 *
 * - فایل‌های src/Migrations/YYYY_MM_DD_NNNN_*.php — ترتیب نام‌گذاری = ترتیب اجرا.
 * - هر فایل آرایه برمی‌گرداند: ['version'=>..., 'description'=>..., 'up'=>fn(CpmsDb):void, 'down'=>fn(CpmsDb):void]
 * - اجرا در Transaction + ثبت در cpms_schema_migrations (idempotent).
 * - Rollback: down() — فقط برای Migrationهای امن؛ Migrationهای حساس (مالی/بالینی)
 *   باید قبل از اجرا Backup داشته باشند (فرایند F9/DR).
 */
final class MigrationRunner
{
    private const SCHEMA_TABLE = 'cpms_schema_migrations';

    public function __construct(
        private readonly CpmsDb $db,
        private readonly OpLogger $op,
        private readonly string $migrationsDir
    ) {
    }

    public function ensureSchemaTable(): void
    {
        $t = $this->db->table(self::SCHEMA_TABLE);

        /*
         * جدول واقعی موجود است → CREATE نزن.
         * زیر WP Test Suite فیلتری روی query فعال است که CREATE TABLE را به
         * CREATE TEMPORARY TABLE بازنویسی می‌کند؛ روی جدولِ موجود، جدول
         * «سایه» خالی می‌سازد که نسخه‌های اعمال‌شده را مخفی می‌کند و باعث
         * اجرای دوباره کل Migrationها (و خطای FK جدول‌های موقت) می‌شود.
         */
        $exists = $this->db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
            [$t]
        );
        if ((int) $exists > 0) {
            return;
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS {$t} (
                `version` VARCHAR(64) NOT NULL,
                `description` VARCHAR(255) NOT NULL DEFAULT '',
                `applied_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * اجرای Migrationهای اعمال‌نشده.
     *
     * @return list<string> نسخه‌های اعمال‌شده در این فراخوانی
     */
    public function migrate(): array
    {
        $this->ensureSchemaTable();

        $appliedNow = [];
        foreach ($this->migrationFiles() as $file) {
            $version = $this->versionOf($file['name']);
            if ($this->isApplied($version)) {
                continue;
            }

            $migration = require_once $file['path'];
            if (!is_array($migration) || !isset($migration['up'])) {
                throw new RuntimeException("Invalid migration file: {$file['name']}");
            }

            $this->op->info('MIGRATION_START', ['version' => $version]);
            $this->db->transactional(function () use ($migration, $version) {
                ($migration['up'])($this->db);
                $this->db->insert(self::SCHEMA_TABLE, [
                    'version' => $version,
                    'description' => (string) ($migration['description'] ?? ''),
                    'applied_at' => $this->db->nowUtcSql(),
                ]);
            });

            $appliedNow[] = $version;
            $this->op->info('MIGRATION_DONE', ['version' => $version]);
        }

        return $appliedNow;
    }

    /**
     * Rollback آخرین Migration (فقط Migrationهایی که down تعریف کرده‌اند).
     */
    public function rollbackOne(): ?string
    {
        $this->ensureSchemaTable();
        $last = $this->db->fetchRow(
            'SELECT version, description FROM ' . $this->db->table(self::SCHEMA_TABLE) . ' ORDER BY version DESC LIMIT 1'
        );
        if ($last === null) {
            return null;
        }

        foreach ($this->migrationFiles() as $file) {
            if ($this->versionOf($file['name']) !== $last['version']) {
                continue;
            }
            $migration = require_once $file['path'];
            if (!isset($migration['down'])) {
                throw new RuntimeException("Migration {$last['version']} has no down() — manual restore from backup required");
            }

            $this->db->transactional(function () use ($migration, $last) {
                ($migration['down'])($this->db);
                $this->db->query(
                    'DELETE FROM ' . $this->db->table(self::SCHEMA_TABLE) . ' WHERE version = %s',
                    [$last['version']]
                );
            });

            return $last['version'];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function applied(): array
    {
        $this->ensureSchemaTable();

        return (array) $this->db->fetchValue(
            'SELECT GROUP_CONCAT(version ORDER BY version) FROM ' . $this->db->table(self::SCHEMA_TABLE)
        ) ?: [];
    }

    public function currentVersion(): ?string
    {
        $this->ensureSchemaTable();

        $v = $this->db->fetchValue(
            'SELECT version FROM ' . $this->db->table(self::SCHEMA_TABLE) . ' ORDER BY version DESC LIMIT 1'
        );

        return is_string($v) ? $v : null;
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    private function migrationFiles(): array
    {
        // توجه: glob() از quantifier مثل {4} پشتیبانی نمی‌کند (literal می‌گیرد) —
        // الگوی صحیح با «?» تک‌کاراکتری است: YYYY_MM_DD_NNNN_*.php
        $files = glob(rtrim($this->migrationsDir, '/') . '/????_??_??_????_*.php') ?: [];
        sort($files);

        return array_map(
            static fn (string $f): array => ['name' => basename($f), 'path' => $f],
            $files
        );
    }

    private function versionOf(string $name): string
    {
        // 2026_09_05_0001_initial_schema.php → 2026_09_05_0001
        return substr($name, 0, 15);
    }

    private function isApplied(string $version): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->db->table(self::SCHEMA_TABLE) . ' WHERE version = %s',
            [$version]
        );
    }
}
