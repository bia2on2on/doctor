<?php

declare(strict_types=1);

namespace ClinicCore\Application\Backup;

use ClinicCore\Domain\Backup\BackupManifest;
use ClinicCore\Domain\Backup\SqlStatementSplitter;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Backup\BackupSqlDumper;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Settings\Settings;

/**
 * سرویس بکاپ/بازیابی (F10 — spec §22–§25):
 *
 * CREATE : db.sql (cpms_* فقط) + mirror storage (فایل‌های پزشکی) + مانیفست
 *          (sha256 هر فایل + تعداد ردیف‌ها) در ProtectedBackupStore.
 * VERIFY : تمامیت کامل روی دیسک (همه‌ی هش‌ها + مانیفست).
 * PRUNE  : Retention (پیش‌فرض ۱۴ نسخه — تنظیم‌پذیر؛ Keep newest N).
 * RESTORE: Preflight (سند + هش‌ها + دیسک + DB) → Safety Backup خودکار →
 *          اعمال SQL (cpms_* فقط؛ FK off؛ به‌صورت تک‌Statement) + بازگردانی
 *          storage. فقط با تأیید صریح (restoreApply).
 *
 * هرگز WP Core یا داده‌ی افزونه‌های دیگر را لمس نمی‌کند؛ بدون PHI در Log/
 * Audit (فقط id و شمارنده‌ها).
 */
final class BackupService
{
    public const ENGINE_VERSION = '1.0.0';

    public function __construct(
        private readonly CpmsDb $db,
        private readonly ProtectedBackupStore $store,
        private readonly BackupSqlDumper $dumper,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op,
        private readonly string $filesBasePath
    ) {
    }

    public function store(): ProtectedBackupStore
    {
        return $this->store;
    }

    // ================= CREATE / LIST / VERIFY / DELETE / PRUNE =================

    /**
     * @return array<string, mixed>
     */
    public function createBackup(string $note = '', int $now = 0): array
    {
        $now = $now > 0 ? $now : time();
        $backupId = 'cpms-backup-' . gmdate('Ymd-His', $now) . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $dir = $this->store->createDir($backupId);
        $sqlFile = $dir . '/db.sql';
        $storageDir = $dir . '/storage';

        // ۱) DB dump (cpms_*)
        $tableStats = $this->dumper->dumpToFile($sqlFile);
        $sqlSha = $this->hashFile($sqlFile);

        // ۲) Mirror فایل‌های پزشکی (فقط فایل‌های واقعی؛ بدون گارد)
        $files = $this->mirrorStorage($this->filesBasePath, $storageDir);

        // ۳) مانیفست
        $manifest = [
            'schema_version' => BackupManifest::SCHEMA_VERSION,
            'engine' => BackupManifest::ENGINE,
            'engine_version' => self::ENGINE_VERSION,
            'backup_id' => $backupId,
            'created_at' => gmdate('c', $now),
            'note' => mb_substr($note, 0, 200),
            'db' => ['file' => 'db.sql', 'sha256' => $sqlSha, 'tables' => $tableStats],
            'storage' => [
                'root' => 'storage',
                'files' => $files['list'],
                'count' => $files['count'],
                'bytes' => $files['bytes'],
            ],
            'meta' => [
                'wp_version' => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '',
                'php_version' => PHP_VERSION,
                'cpms_version' => defined('CPMS_VERSION') ? CPMS_VERSION : 'dev',
            ],
        ];
        $errors = BackupManifest::validate($manifest);
        if ($errors !== []) {
            throw BackupException::of('CLINIC_BACKUP_MANIFEST', 'manifest invalid: ' . implode('; ', $errors));
        }
        $manifestJson = (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($dir . '/manifest.json', $manifestJson);
        file_put_contents($dir . '/manifest.json.sha256', hash('sha256', $manifestJson));

        $this->settings->set('backup.last_run_at', $now);
        $this->op->info('BACKUP_CREATED', [
            'backup_id' => $backupId,
            'tables' => count($tableStats),
            'rows' => array_sum(array_map(static fn (array $t): int => (int) $t['rows'], $tableStats)),
            'storage_files' => $files['count'],
            'storage_bytes' => $files['bytes'],
        ]);
        $this->audit->log('BACKUP_CREATED', null, 'backup', null, null, null, null, [
            'backup_id' => $backupId,
            'tables' => count($tableStats),
            'storage_files' => $files['count'],
        ]);

        $this->prune();

        return $this->backupMeta($backupId) ?? ['backup_id' => $backupId];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listBackups(): array
    {
        $out = [];
        foreach ($this->store->listIds() as $id) {
            $meta = $this->backupMeta($id);
            if ($meta !== null) {
                $out[] = $meta;
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function backupMeta(string $backupId): ?array
    {
        if (!$this->store->exists($backupId)) {
            return null;
        }
        $dir = $this->store->dirOf($backupId);
        $raw = $this->readManifest($backupId);
        if ($raw === null) {
            return null;
        }
        $manifestShaOk = @file_get_contents($dir . '/manifest.json.sha256') === false
            || trim((string) @file_get_contents($dir . '/manifest.json.sha256')) === hash_file('sha256', $dir . '/manifest.json');
        // Quick check (ارزان برای لیست) — تأیید کامل هش فایل‌ها = verifyBackup()
        $integrity = BackupManifest::isValid($raw) && $manifestShaOk ? 'ok_quick' : 'corrupt';

        return [
            'backup_id' => $backupId,
            'created_at' => (string) $raw['created_at'],
            'note' => (string) ($raw['note'] ?? ''),
            'tables' => count((array) ($raw['db']['tables'] ?? [])),
            'rows' => array_sum(array_map(static fn (array $t): int => (int) $t['rows'], (array) ($raw['db']['tables'] ?? []))),
            'storage_files' => (int) ($raw['storage']['count'] ?? 0),
            'storage_bytes' => (int) ($raw['storage']['bytes'] ?? 0),
            'integrity' => $integrity,
            'engine_version' => (string) ($raw['engine_version'] ?? ''),
            'manifest_valid' => BackupManifest::isValid($raw),
        ];
    }

    /**
     * @return array{ok: bool, errors: list<string>, warnings: list<string>}
     */
    public function verifyBackup(string $backupId): array
    {
        $dir = $this->store->dirOf($backupId);
        $raw = $this->readManifest($backupId);
        if ($raw === null) {
            return ['ok' => false, 'errors' => ['manifest missing/corrupt'], 'warnings' => []];
        }
        $expectedManifestSha = @file_get_contents($dir . '/manifest.json.sha256');
        $actualManifestSha = hash_file('sha256', $dir . '/manifest.json');
        if ($expectedManifestSha !== false && trim((string) $expectedManifestSha) !== $actualManifestSha) {
            return ['ok' => false, 'errors' => ['manifest.json tampered'], 'warnings' => []];
        }

        $result = BackupManifest::verifyFiles($raw, function (string $rel) use ($dir): ?array {
            $abs = $dir . '/' . $rel;
            if (!is_file($abs)) {
                return null;
            }

            return ['size' => (int) filesize($abs), 'sha256' => hash_file('sha256', $abs) ?: ''];
        });

        return $result;
    }

    public function deleteBackup(string $backupId): void
    {
        $this->audit->log('BACKUP_DELETED', null, 'backup', null, null, null, null, ['backup_id' => $backupId]);
        $this->store->delete($backupId);
        $this->op->info('BACKUP_DELETED', ['backup_id' => $backupId]);
    }

    /**
     * Retention: نگهداری N نسخه‌ی آخر (پیش‌فرض ۱۴ — تنظیم `backup.keep_count`).
     *
     * @return list<string> بکاپ‌های حذف‌شده
     */
    public function prune(int $keep = 0): array
    {
        $keep = $keep > 0 ? $keep : max(1, (int) $this->settings->get('backup.keep_count', 14));
        $metas = $this->listBackups(); // مرتب created_at نزولی — جدیدترین اول
        $removed = [];
        foreach (array_slice($metas, $keep) as $old) {
            $id = (string) $old['backup_id'];
            try {
                $this->deleteBackup($id);
                $removed[] = $id;
            } catch (BackupException $e) {
                $this->op->warning('BACKUP_PRUNE_FAILED', ['backup_id' => $id, 'error' => $e->getErrorCode()]);
            }
        }
        if ($removed !== []) {
            $this->op->info('BACKUP_PRUNE', ['removed' => $removed]);
        }

        return $removed;
    }

    // ================= RESTORE =================

    /**
     * Preflight — هرگز چیزی را تغییر نمی‌دهد (spec §25).
     *
     * @return array<string, mixed>
     */
    public function restorePreflight(string $backupId): array
    {
        $dir = $this->store->dirOf($backupId);
        $raw = $this->readManifest($backupId);
        if ($raw === null) {
            throw BackupException::of('CLINIC_BACKUP_MANIFEST', 'backup manifest missing: ' . $backupId);
        }

        $verify = $this->verifyBackup($backupId);
        $rows = array_sum(array_map(static fn (array $t): int => (int) $t['rows'], (array) ($raw['db']['tables'] ?? [])));
        $dbOk = $this->dbOk();

        return [
            'backup_id' => $backupId,
            'created_at' => (string) $raw['created_at'],
            'integrity_ok' => $verify['ok'],
            'integrity_errors' => $verify['errors'],
            'tables' => count((array) ($raw['db']['tables'] ?? [])),
            'rows' => $rows,
            'storage_files' => (int) ($raw['storage']['count'] ?? 0),
            'db_reachable' => $dbOk,
            'disk_free_bytes' => function_exists('disk_free_space') ? @disk_free_space($this->store->basePath()) : null,
            'engine_version' => (string) ($raw['engine_version'] ?? ''),
            'restore_safe' => $verify['ok'] && $dbOk,
        ];
    }

    /**
     * اعمال Restore — فقط با تأیید صریح؛ خودکار Safety Backup می‌سازد.
     * فقط از CLI/Admin با تأیید (هرگز از Job خودکار).
     *
     * @return array<string, mixed>
     */
    public function restoreApply(string $backupId, bool $confirmed, bool $includeFiles = true): array
    {
        if (!$confirmed) {
            throw BackupException::of('CLINIC_BACKUP_CONFIRM_REQUIRED', 'restore requires explicit confirmation');
        }
        $pre = $this->restorePreflight($backupId);
        if (!$pre['restore_safe']) {
            throw BackupException::of('CLINIC_BACKUP_PREFLIGHT_FAILED', 'restore preflight failed');
        }

        // Safety Backup (همیشه قبل از تغییر مخرب)
        $safety = $this->createBackup('pre-restore-safety-' . $backupId);

        $dir = $this->store->dirOf($backupId);
        $raw = $this->readManifest($backupId);
        $sql = (string) @file_get_contents($dir . '/db.sql');
        if ($sql === '') {
            throw BackupException::of('CLINIC_BACKUP_IO', 'db.sql empty/missing');
        }

        $applied = 0;
        $dropped = 0;
        $this->db->transactional(function () use ($raw, $sql, &$applied, &$dropped): void {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            // حذف فقط جدول‌های cpms_* (بر اساس لیست خود مانیفست — نه LIKE روی سرور)
            foreach ((array) ($raw['db']['tables'] ?? []) as $t) {
                $name = (string) $t['name'];
                if (!str_starts_with($name, 'cpms_')) {
                    continue; // محافظ — هرگز غیر از cpms_
                }
                $this->db->query('DROP TABLE IF EXISTS `' . $name . '`');
                $dropped++;
            }
            foreach (SqlStatementSplitter::split($sql) as $stmt) {
                $this->db->query($stmt);
                $applied++;
            }
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        });

        if ($includeFiles && is_dir($dir . '/storage')) {
            $this->restoreFiles($dir . '/storage', (array) ($raw['storage']['files'] ?? []));
        }

        $this->audit->log('RESTORE_APPLIED', null, 'backup', null, null, null, null, [
            'backup_id' => $backupId,
            'safety_backup' => (string) ($safety['backup_id'] ?? ''),
            'statements' => $applied,
            'dropped_tables' => $dropped,
            'files' => $includeFiles ? (int) ($raw['storage']['count'] ?? 0) : 0,
        ]);
        $this->op->info('RESTORE_APPLIED', [
            'backup_id' => $backupId,
            'safety_backup' => (string) ($safety['backup_id'] ?? ''),
        ]);

        return $pre;
    }

    // ================= helpers =================

    private function dbOk(): bool
    {
        try {
            return (int) $this->db->fetchValue('SELECT 1') === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(string $backupId): ?array
    {
        $dir = $this->store->dirOf($backupId);
        $json = @file_get_contents($dir . '/manifest.json');
        if ($json === false) {
            return null;
        }
        $raw = json_decode($json, true);

        return is_array($raw) ? $raw : null;
    }

    /**
     * کپی بازگشتی storage (فایل‌های پزشکی) به پوشه‌ی بکاپ + جمع‌آوری هش‌ها.
     *
     * @return array{list: list<array{path: string, size: int, sha256: string}>, count: int, bytes: int}
     */
    private function mirrorStorage(string $srcBase, string $dstDir): array
    {
        $list = [];
        $count = 0;
        $bytes = 0;
        if (!is_dir($srcBase)) {
            return ['list' => [], 'count' => 0, 'bytes' => 0];
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcBase, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $rel = ltrim(substr($f->getPathname(), strlen($srcBase)), '/');
            if (!$this->relSafePath($rel)) {
                $this->op->warning('BACKUP_SKIPPED_PATH', ['path' => $rel]);
                continue;
            }
            $dst = $dstDir . '/' . $rel;
            if (!is_dir(dirname($dst)) && !mkdir(dirname($dst), 0750, true) && !is_dir(dirname($dst))) {
                throw BackupException::of('CLINIC_BACKUP_IO', 'storage mkdir failed');
            }
            if (!copy($f->getPathname(), $dst)) {
                throw BackupException::of('CLINIC_BACKUP_IO', 'storage copy failed: ' . $rel);
            }
            $size = (int) filesize($dst);
            $sha = hash_file('sha256', $dst) ?: '';
            $list[] = ['path' => $rel, 'size' => $size, 'sha256' => $sha];
            $count++;
            $bytes += $size;
        }

        return ['list' => $list, 'count' => $count, 'bytes' => $bytes];
    }

    /**
     * @param list<array{path: string, size: int, sha256: string}> $files
     */
    private function restoreFiles(string $srcDir, array $files): void
    {
        foreach ($files as $f) {
            $rel = (string) $f['path'];
            if (!$this->relSafePath($rel)) {
                throw BackupException::of('CLINIC_BACKUP_INVALID_PATH', 'unsafe storage path in manifest: ' . $rel);
            }
            $src = $srcDir . '/' . $rel;
            $dst = $this->filesBasePath . '/' . $rel;
            if (!is_file($src)) {
                throw BackupException::of('CLINIC_BACKUP_IO', 'restore source missing: ' . $rel);
            }
            if (!is_dir(dirname($dst)) && !mkdir(dirname($dst), 0750, true) && !is_dir(dirname($dst))) {
                throw BackupException::of('CLINIC_BACKUP_IO', 'restore mkdir failed');
            }
            if (!copy($src, $dst)) {
                throw BackupException::of('CLINIC_BACKUP_IO', 'restore copy failed: ' . $rel);
            }
        }
    }

    /**
     * مسیر نسبیِ امن برای فایل‌های ذخیره‌سازی: زیرپوشه‌های عادی، بدون
     * `..`/شروع با نقطه/مطلق (دقیقاً الگوی LocalFileStorage + زیرپوشه‌ها).
     */
    private function relSafePath(string $rel): bool
    {
        return $rel !== ''
            && !str_starts_with($rel, '.')
            && !str_contains($rel, '..')
            && strpos($rel, '/') !== 0
            && !str_contains($rel, '\\')
            && strpos($rel, "\0") === false;
    }

    private function hashFile(string $path): string
    {
        return hash_file('sha256', $path) ?: '';
    }
}
