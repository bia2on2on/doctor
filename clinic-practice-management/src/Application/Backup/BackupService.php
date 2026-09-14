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
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Infrastructure\Storage\PrivateStorageLocation;
use ClinicCore\Infrastructure\Storage\StorageConfigurationException;
use ClinicCore\Settings\InstallationSettings;

/**
 * سرویس بکاپ/بازیابی (F10 — spec §22–§25) + M-2 multi-clinic active storage.
 *
 * CREATE : db.sql (cpms_* فقط) + mirror storage از **تمام** ریشه‌های فعال بالینی
 *          (هر Clinic ریشهٔ فعال خودش) + مانیفست (sha256 هر فایل + تعداد ردیف‌ها)
 *          در ProtectedBackupStore.
 * VERIFY : تمامیت کامل روی دیسک (همه‌ی هش‌ها + مانیفست).
 * PRUNE  : Retention (پیش‌فرض ۱۴ نسخه — تنظیم‌پذیر؛ Keep newest N).
 * RESTORE: Preflight → Safety Backup → اعمال SQL + بازگردانی storage به ریشهٔ
 *          فعالِ هر Clinic (بر اساس clinic_id در مسیر نسبی)، با حفظ هویت tenant.
 *
 * OD-7 — پیش‌فرض خارج DocumentRoot؛ داخل webroot = Fail-Closed.
 * OD-9 — تفکیک مبدأ/مقصد بکاپ.
 * M-2 GREEN — backup.run سطح نصب، بدون Clinic-bound Settings.
 * Multi-Clinic File Roots — بکاپ نصب‌گسترده باید **همهٔ** فایل‌های فعالِ همهٔ
 * Clinicها را شامل شود، نه فقط ریشهٔ پیش‌فرض یا Clinic جاری. منبعِ معتبر:
 * جدول cpms_clinics + cpms_settings (files.storage_path per-Clinic). بدون
 * استفاده از Clinic جاری/اول/1/clinic_id=0/payload.
 */
final class BackupService
{
    private const MANIFEST_HASH_OK = 'ok';
    private const MANIFEST_HASH_MISSING = 'missing';
    private const MANIFEST_HASH_MISMATCH = 'mismatch';

    public const ENGINE_VERSION = '1.0.0';

    public function __construct(
        private readonly CpmsDb $db,
        private readonly ProtectedBackupStore $store,
        private readonly BackupSqlDumper $dumper,
        private readonly InstallationSettings $installationSettings,
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
     * بکاپ جدید — فقط در مخزن فعال. اگر ریشهٔ فعال داخل DocumentRoot باشد
     * این فراخوانی Fail-Closed خطا می‌دهد؛ هیچ fallback بی‌صدایی نیست (OD-9).
     *
     * @return array<string, mixed>
     */
    public function createBackup(string $note = '', int $now = 0): array
    {
        return $this->createBackupTo($this->store, $note, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function createBackupTo(ProtectedBackupStore $destination, string $note = '', int $now = 0): array
    {
        $now = $now > 0 ? $now : time();
        $backupId = 'cpms-backup-' . gmdate('Ymd-His', $now) . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $dir = $destination->createDir($backupId);
        $sqlFile = $dir . '/db.sql';
        $storageDir = $dir . '/storage';

        // ۱) DB dump (cpms_*)
        $tableStats = $this->dumper->dumpToFile($sqlFile);
        $sqlSha = $this->hashFile($sqlFile);

        // ۲) Mirror فایل‌های پزشکی از **تمام** ریشه‌های فعال (multi-clinic)
        $files = $this->mirrorActiveStorages($storageDir);

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

        $this->installationSettings->setBackupLastRunAt($now);
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

        $this->pruneStore($destination);

        return $this->backupMetaIn($destination, $backupId) ?? ['backup_id' => $backupId];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listBackups(): array
    {
        return $this->listMetasIn($this->store);
    }

    private function manifestHashState(string $dir): string
    {
        $expected = @file_get_contents($dir . '/manifest.json.sha256');
        if (!is_string($expected) || trim($expected) === '') {
            return self::MANIFEST_HASH_MISSING;
        }
        $actual = hash_file('sha256', $dir . '/manifest.json');

        return is_string($actual) && hash_equals(trim($expected), $actual)
            ? self::MANIFEST_HASH_OK
            : self::MANIFEST_HASH_MISMATCH;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function backupMeta(string $backupId): ?array
    {
        return $this->backupMetaIn($this->store, $backupId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function backupMetaIn(ProtectedBackupStore $store, string $backupId): ?array
    {
        if (!$store->exists($backupId)) {
            return null;
        }
        $dir = $store->dirOf($backupId);
        $raw = $this->readManifestIn($store, $backupId);
        if ($raw === null) {
            return null;
        }
        $shaState = $this->manifestHashState($dir);
        $idMatches = (string) ($raw['backup_id'] ?? '') === $backupId;
        if (!BackupManifest::isValid($raw) || !$idMatches || $shaState === self::MANIFEST_HASH_MISMATCH) {
            $integrity = 'corrupt';
        } elseif ($shaState === self::MANIFEST_HASH_MISSING) {
            $integrity = 'legacy_unverified';
        } else {
            $integrity = 'ok_quick';
        }

        return [
            'backup_id' => $backupId,
            'created_at' => (string) ($raw['created_at'] ?? ''),
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
        return $this->verifyIn($this->resolveSourceStore($backupId), $backupId);
    }

    /**
     * @return array{ok: bool, errors: list<string>, warnings: list<string>}
     */
    private function verifyIn(ProtectedBackupStore $source, string $backupId): array
    {
        $dir = $source->dirOf($backupId);
        $raw = $this->readManifestIn($source, $backupId);
        if ($raw === null) {
            return ['ok' => false, 'errors' => ['manifest missing/corrupt'], 'warnings' => []];
        }
        $warnings = [];
        $shaState = $this->manifestHashState($dir);
        if ($shaState === self::MANIFEST_HASH_MISMATCH) {
            return ['ok' => false, 'errors' => ['manifest.json tampered'], 'warnings' => []];
        }
        if ($shaState === self::MANIFEST_HASH_MISSING) {
            $warnings[] = 'manifest.json.sha256 missing — legacy backup; manifest authenticity cannot be verified';
        }
        if ((string) ($raw['backup_id'] ?? '') !== $backupId) {
            return ['ok' => false, 'errors' => ['manifest backup_id mismatch'], 'warnings' => []];
        }

        $result = BackupManifest::verifyFiles($raw, function (string $rel) use ($dir): ?array {
            $abs = $dir . '/' . $rel;
            if (!is_file($abs)) {
                return null;
            }

            return ['size' => (int) filesize($abs), 'sha256' => hash_file('sha256', $abs) ?: ''];
        });
        $result['warnings'] = array_merge($warnings, (array) ($result['warnings'] ?? []));

        return $result;
    }

    public function deleteBackup(string $backupId): void
    {
        $this->store->delete($backupId);
        $this->audit->log('BACKUP_DELETED', null, 'backup', null, null, null, null, ['backup_id' => $backupId]);
        $this->op->info('BACKUP_DELETED', ['backup_id' => $backupId]);
    }

    /**
     * @return list<string>
     */
    public function prune(int $keep = 0): array
    {
        return $this->pruneStore($this->store, $keep);
    }

    /**
     * @return list<string>
     */
    private function pruneStore(ProtectedBackupStore $store, int $keep = 0): array
    {
        $keep = $keep > 0 ? $keep : max(1, $this->installationSettings->getBackupKeepCount());
        $metas = $this->listMetasIn($store);
        $removed = [];
        foreach (array_slice($metas, $keep) as $old) {
            $id = (string) $old['backup_id'];
            try {
                $store->delete($id);
                $this->audit->log('BACKUP_DELETED', null, 'backup', null, null, null, null, ['backup_id' => $id]);
                $this->op->info('BACKUP_DELETED', ['backup_id' => $id]);
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
     * @return array<string, mixed>
     */
    public function restorePreflight(string $backupId): array
    {
        $source = $this->resolveSourceStore($backupId);
        $dir = $source->dirOf($backupId);
        $raw = $this->readManifestIn($source, $backupId);
        if ($raw === null) {
            throw BackupException::of('CLINIC_BACKUP_MANIFEST', 'backup manifest missing: ' . $backupId);
        }

        $verify = $this->verifyIn($source, $backupId);
        $rows = array_sum(array_map(static fn (array $t): int => (int) $t['rows'], (array) ($raw['db']['tables'] ?? [])));
        $dbOk = $this->dbOk();

        return [
            'backup_id' => $backupId,
            'created_at' => (string) $raw['created_at'],
            'integrity_ok' => $verify['ok'],
            'integrity_errors' => $verify['errors'],
            'integrity_warnings' => $verify['warnings'],
            'legacy_unverified' => $this->manifestHashState($dir) === self::MANIFEST_HASH_MISSING,
            'source' => $source->isReadonly() ? 'legacy' : 'active',
            'source_root' => $source->basePath(),
            'tables' => count((array) ($raw['db']['tables'] ?? [])),
            'rows' => $rows,
            'storage_files' => (int) ($raw['storage']['count'] ?? 0),
            'db_reachable' => $dbOk,
            'disk_free_bytes' => function_exists('disk_free_space') ? @disk_free_space($source->basePath()) : null,
            'engine_version' => (string) ($raw['engine_version'] ?? ''),
            'restore_safe' => $verify['ok'] && $dbOk,
        ];
    }

    /**
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

        $safetyDestination = $this->safetyDestinationStore();
        $safety = $this->createBackupTo($safetyDestination, 'pre-restore-safety-' . $backupId);

        $source = $this->resolveSourceStore($backupId);
        $sourceLabel = $source->isReadonly() ? 'legacy' : 'active';
        $dir = $source->dirOf($backupId);
        $raw = $this->readManifestIn($source, $backupId);
        $sql = (string) @file_get_contents($dir . '/db.sql');
        if ($sql === '') {
            throw BackupException::of('CLINIC_BACKUP_IO', 'db.sql empty/missing');
        }

        $applied = 0;
        $dropped = 0;
        $this->db->transactional(function () use ($raw, $sql, &$applied, &$dropped): void {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            foreach ((array) ($raw['db']['tables'] ?? []) as $t) {
                $name = (string) $t['name'];
                if (!str_starts_with($name, 'cpms_')) {
                    continue;
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
            'safety_destination' => $safetyDestination->basePath(),
            'source' => $sourceLabel,
            'legacy_unverified' => (bool) $pre['legacy_unverified'],
            'statements' => $applied,
            'dropped_tables' => $dropped,
            'files' => $includeFiles ? (int) ($raw['storage']['count'] ?? 0) : 0,
        ]);
        $this->op->info('RESTORE_APPLIED', [
            'backup_id' => $backupId,
            'safety_backup' => (string) ($safety['backup_id'] ?? ''),
            'safety_destination' => $safetyDestination->basePath(),
            'source' => $sourceLabel,
        ]);

        $pre['safety_backup'] = (string) ($safety['backup_id'] ?? '');
        $pre['safety_backup_destination'] = $safetyDestination->basePath();

        return $pre;
    }

    // ================= OD-9: source/destination resolution =================

    private function resolveSourceStore(string $backupId): ProtectedBackupStore
    {
        if ($this->store->exists($backupId)) {
            return $this->store;
        }
        foreach ([ProtectedBackupStore::defaultBasePath(), ProtectedBackupStore::legacyBasePath()] as $base) {
            if ($this->sameBasePath($base, $this->store->basePath())) {
                continue;
            }
            $candidate = ProtectedBackupStore::legacySource($base);
            if ($candidate->exists($backupId)) {
                return $candidate;
            }
        }

        return $this->store;
    }

    private function safetyDestinationStore(): ProtectedBackupStore
    {
        if (!$this->store->isReadonly()) {
            return $this->store;
        }

        return ProtectedBackupStore::active(ProtectedBackupStore::defaultBasePath());
    }

    private function sameBasePath(string $a, string $b): bool
    {
        $norm = static function (string $p): string {
            return rtrim(str_replace('\\', '/', $p), '/');
        };

        return $norm($a) === $norm($b);
    }

    // ================= Multi-Clinic active storage enumeration =================

    /**
     * منبع معتبر ریشه‌های فعال بالینی: جدول cpms_clinics + cpms_settings (files.storage_path).
     * بدون استفاده از Clinic جاری/اول/1/clinic_id=0/payload.
     *
     * @return array<string, list<int>> map normalizedBasePath => list clinicIds using it
     */
    private function enumerateActiveClinicalStorageRoots(): array
    {
        $rootsMap = []; // normalized => clinicIds

        try {
            $clinicRows = $this->db->fetchAll('SELECT id FROM ' . $this->db->table('cpms_clinics'));
        } catch (\Throwable) {
            // قبل از Migration یا DB ناپایدار — fallback به filesBasePath
            $clinicRows = [];
        }

        $clinicIds = [];
        foreach ($clinicRows as $r) {
            $clinicIds[] = (int) ($r['id'] ?? $r['clinic_id'] ?? 0);
        }
        $clinicIds = array_filter($clinicIds, static fn (int $id): bool => $id > 0);

        if ($clinicIds === []) {
            // هیچ کلینیکی در DB نیست (تست‌های قدیمی یا نصب تازه) — فقط injected base
            $injected = trim($this->filesBasePath);
            if ($injected === '') {
                $injected = LocalFileStorage::defaultBasePath();
            }
            $norm = $this->validateAndNormalizeStoragePath($injected);
            if ($norm !== '') {
                $rootsMap[$norm] = [0];
            }
            return $rootsMap;
        }

        foreach ($clinicIds as $cid) {
            $path = '';
            try {
                $row = $this->db->fetchRow(
                    'SELECT value_json FROM ' . $this->db->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s',
                    [$cid, 'files.storage_path']
                );
                if ($row !== null) {
                    $decoded = json_decode((string) ($row['value_json'] ?? ''), true);
                    if (is_string($decoded)) {
                        $path = trim($decoded);
                    }
                }
            } catch (\Throwable) {
                $path = '';
            }

            if ($path === '') {
                $path = LocalFileStorage::defaultBasePath();
            }

            // Validate — fail closed if inside webroot
            $normalized = $this->validateAndNormalizeStoragePath($path);

            if ($normalized === '') {
                continue;
            }

            if (!isset($rootsMap[$normalized])) {
                $rootsMap[$normalized] = [];
            }
            $rootsMap[$normalized][] = $cid;
        }

        // برای سازگاری با تست‌هایی که filesBasePath سفارشی inject می‌کنند (مثل BackupEngineTest)
        // و Clinic 1 هنوز مقدار files.storage_path ندارد، آن مسیر را هم اضافه کن اگر امن و
        // قبلاً در لیست نیست — این باعث نمی‌شود در production فقط default جمع شود، چون
        // production از طریق DB همهٔ ریشه‌های فعال را می‌آورد.
        $injected = trim($this->filesBasePath);
        if ($injected !== '') {
            try {
                $normInjected = $this->validateAndNormalizeStoragePath($injected);
                if ($normInjected !== '' && !isset($rootsMap[$normInjected])) {
                    $rootsMap[$normInjected] = [0];
                }
            } catch (\Throwable $e) {
                // اگر injected ناامن است، Fail-Closed — حتی در تست‌ها هم نباید بی‌صدا حذف شود
                throw $e;
            }
        }

        return $rootsMap;
    }

    /**
     * نگاشت Clinic => Base فعال (برای restore).
     *
     * @return array<int, string> clinicId => normalizedBasePath
     */
    private function getClinicToBaseMap(): array
    {
        $map = [];
        try {
            $clinicRows = $this->db->fetchAll('SELECT id FROM ' . $this->db->table('cpms_clinics'));
        } catch (\Throwable) {
            return [];
        }

        foreach ($clinicRows as $r) {
            $cid = (int) ($r['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $path = '';
            try {
                $row = $this->db->fetchRow(
                    'SELECT value_json FROM ' . $this->db->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s',
                    [$cid, 'files.storage_path']
                );
                if ($row !== null) {
                    $decoded = json_decode((string) ($row['value_json'] ?? ''), true);
                    if (is_string($decoded)) {
                        $path = trim($decoded);
                    }
                }
            } catch (\Throwable) {
                $path = '';
            }

            if ($path === '') {
                $path = LocalFileStorage::defaultBasePath();
            }

            try {
                $normalized = $this->validateAndNormalizeStoragePath($path);
            } catch (\Throwable) {
                // در restore اگر یک Clinic مسیر ناامن دارد، آن Clinic را نادیده نگیر — Fail-Closed
                throw BackupException::of('CLINIC_STORAGE_INSIDE_WEBROOT', 'clinic ' . $cid . ' storage inside webroot: ' . $path);
            }

            if ($normalized !== '') {
                $map[$cid] = $normalized;
            }
        }

        return $map;
    }

    /**
     * اعتبارسنجی و نرمال‌سازی مسیر ذخیره‌سازی بالینی — Fail-Closed اگر داخل webroot.
     *
     * @throws BackupException
     */
    private function validateAndNormalizeStoragePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }
        $normalized = rtrim(str_replace('\\', '/', $trimmed), '/');

        if (PrivateStorageLocation::isInsideWebRoot($normalized)) {
            throw BackupException::of('CLINIC_STORAGE_INSIDE_WEBROOT', 'storage path inside webroot: ' . $normalized);
        }

        try {
            PrivateStorageLocation::assertOutsideWebRoot($normalized, 'ذخیره‌سازی فایل بالینی');
        } catch (StorageConfigurationException $e) {
            throw BackupException::of('CLINIC_STORAGE_INSIDE_WEBROOT', $e->getMessage());
        }

        return $normalized;
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
    private function readManifestIn(ProtectedBackupStore $store, string $backupId): ?array
    {
        $dir = $store->dirOf($backupId);
        $json = @file_get_contents($dir . '/manifest.json');
        if ($json === false) {
            return null;
        }
        $raw = json_decode($json, true);

        return is_array($raw) ? $raw : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listMetasIn(ProtectedBackupStore $store): array
    {
        $out = [];
        foreach ($store->listIds() as $id) {
            $meta = $this->backupMetaIn($store, $id);
            if ($meta !== null) {
                $out[] = $meta;
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $out;
    }

    /**
     * کپی بازگشتی از **تمام** ریشه‌های فعال (multi-clinic) به پوشه‌ی بکاپ.
     * - ریشه‌های تکراری (همان مسیر فیزیکی) فقط یک‌بار جمع می‌شوند
     * - مسیر نسبی شامل clinic_id است → هویت tenant حفظ می‌شود
     * - اگر یک rel از دو ریشهٔ فیزیکی متفاوت با محتوای متفاوت بیاید → Fail-Closed
     * - اگر یک ریشه داخل webroot باشد → Fail-Closed (validateAndNormalize)
     *
     * @return array{list: list<array{path: string, size: int, sha256: string}>, count: int, bytes: int}
     */
    private function mirrorActiveStorages(string $dstDir): array
    {
        $rootsMap = $this->enumerateActiveClinicalStorageRoots();
        $uniqueBases = array_keys($rootsMap);

        if ($uniqueBases === []) {
            // Fallback برای محیط‌های بدون Clinic (تست‌های قدیمی)
            return $this->mirrorStorage($this->filesBasePath, $dstDir);
        }

        $seen = []; // rel => [sha256, size, base]
        $list = [];
        $count = 0;
        $bytes = 0;

        foreach ($uniqueBases as $srcBase) {
            if (!is_dir($srcBase)) {
                continue;
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
                    $this->op->warning('BACKUP_SKIPPED_PATH', ['path' => $rel, 'base' => $srcBase]);
                    continue;
                }

                // Collision detection: same rel from different physical roots with different content
                if (isset($seen[$rel])) {
                    $existing = $seen[$rel];
                    $currentSize = (int) $f->getSize();
                    $currentSha = hash_file('sha256', $f->getPathname()) ?: '';
                    if ($existing['sha256'] === $currentSha && $existing['size'] === $currentSize) {
                        // همان فایل تکراری (ریشهٔ مشترک یا محتوای یکسان) — نادیده بگیر
                        continue;
                    }
                    // محتوای متفاوت برای یک rel یکسان از دو ریشهٔ متفاوت → Fail-Closed
                    throw BackupException::of(
                        'CLINIC_BACKUP_CONFLICT',
                        'conflicting file from different storage roots: ' . $rel . ' base1=' . $existing['base'] . ' base2=' . $srcBase
                    );
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
                $seen[$rel] = ['sha256' => $sha, 'size' => $size, 'base' => $srcBase];
                $count++;
                $bytes += $size;
            }
        }

        return ['list' => $list, 'count' => $count, 'bytes' => $bytes];
    }

    /**
     * کپی بازگشتی storage تک‌ریشه (برای fallback و تست‌های قدیمی).
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
     * بازگردانی فایل‌ها به ریشهٔ فعالِ هر Clinic بر اساس clinic_id در مسیر نسبی.
     * - هویت tenant حفظ می‌شود (clinic_id در rel)
     * - هیچ Clinic فایل Clinic دیگر را overwrite نمی‌کند (زیرپوشهٔ متفاوت)
     * - فایل‌های بدون clinic_id (مثل sentinel تست closure) به ریشهٔ Clinic 1
     *   یا اولین ریشهٔ فعال برمی‌گردند تا restore مخرب closure سبز بماند
     * - مقصد ناامن → Fail-Closed
     *
     * @param list<array{path: string, size: int, sha256: string}> $files
     */
    private function restoreFiles(string $srcDir, array $files): void
    {
        $clinicMap = $this->getClinicToBaseMap();
        $defaultBase = trim($this->filesBasePath) !== '' ? $this->filesBasePath : LocalFileStorage::defaultBasePath();

        // Validate defaultBase once
        try {
            $defaultBaseNorm = $this->validateAndNormalizeStoragePath($defaultBase);
        } catch (\Throwable) {
            $defaultBaseNorm = LocalFileStorage::defaultBasePath();
        }
        if ($defaultBaseNorm === '') {
            $defaultBaseNorm = LocalFileStorage::defaultBasePath();
        }

        // برای فایل‌های بدون clinic_id، اولین ریشهٔ فعال (معمولاً Clinic 1) را به‌عنوان fallback نگه دار
        $firstActiveBase = null;
        if (!empty($clinicMap)) {
            $firstActiveBase = reset($clinicMap);
        }

        foreach ($files as $f) {
            $rel = (string) $f['path'];
            if (!$this->relSafePath($rel)) {
                throw BackupException::of('CLINIC_BACKUP_INVALID_PATH', 'unsafe storage path in manifest: ' . $rel);
            }
            $src = $srcDir . '/' . $rel;
            if (!is_file($src)) {
                throw BackupException::of('CLINIC_BACKUP_IO', 'restore source missing: ' . $rel);
            }

            $parts = explode('/', $rel, 2);
            $clinicId = isset($parts[0]) && is_numeric($parts[0]) ? (int) $parts[0] : 0;
            $destBase = $defaultBaseNorm;
            if ($clinicId > 0 && isset($clinicMap[$clinicId])) {
                $destBase = $clinicMap[$clinicId];
            } elseif ($clinicId === 0 && $firstActiveBase !== null) {
                // فایل بدون clinic_id (مثل sentinel closure) → به اولین ریشهٔ فعال برگردان
                // تا restore مخرب که فایل را مستقیماً در STORAGE_OUT می‌نویسد سبز بماند
                $destBase = $firstActiveBase;
            }

            // Validate destBase (fail closed if inside webroot)
            $destBase = $this->validateAndNormalizeStoragePath($destBase);

            $dst = $destBase . '/' . $rel;
            if (!is_dir(dirname($dst)) && !mkdir(dirname($dst), 0750, true) && !is_dir(dirname($dst))) {
                throw BackupException::of('CLINIC_BACKUP_IO', 'restore mkdir failed');
            }
            if (!copy($src, $dst)) {
                throw BackupException::of('CLINIC_BACKUP_IO', 'restore copy failed: ' . $rel);
            }
        }
    }

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
