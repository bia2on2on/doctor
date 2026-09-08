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
 * OD-9 (تصمیم مالک) — تفکیک صریح «مبدأ» و «مقصد»:
 *  - خواندن (verify/preflight/restore) ابتدا مخزن فعال را می‌پرسد و در صورت
 *    نبود، ریشهٔ خصوصی پیش‌فرض و ریشهٔ legacy داخل webroot را **فقط به‌عنوان
 *    مبدأ فقط‌خواندنی** جست‌وجو می‌کند (recovery).
 *  - نوشتن فقط در مقصد امن: مخزن فعال اگر قابل‌نوشتن باشد؛ وگرنه Safety
 *    Backup پیش از restore به ریشهٔ خصوصی بیرون از webroot هدایت می‌شود —
 *    مبدأ legacy هرگز مقصد نوشتن نیست ⇒ restore به‌خاطر سیاست جدید قفل
 *    نمی‌شود (دروازهٔ Fail-Closed به‌جای نوشتنِ بی‌صدای ناامن، مسیر امن می‌گیرد).
 *
 * هرگز WP Core یا داده‌ی افزونه‌های دیگر را لمس نمی‌کند؛ بدون PHI در Log/
 * Audit (فقط id و شمارنده‌ها و مسیرهای ذخیره‌سازی).
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
     * بکاپ جدید — فقط در مخزن فعال. اگر ریشهٔ فعال داخل DocumentRoot باشد
     * (حالت مبدأ legacy فقط‌خواندنی) این فراخوانی Fail-Closed خطا می‌دهد؛
     * هیچ fallback بی‌صدایی به مسیر دیگری وجود ندارد (OD-9).
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

    /**
     * وضعیت فایل هشِ مانیفست — تنها نقطهٔ تصمیم برای هر دو مسیر بررسی.
     *
     * @return self::MANIFEST_HASH_*
     */
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
     * @param ProtectedBackupStore $store مخزنی که متادیتا از آن خوانده می‌شود
     *
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
        // Phase 1A — سه وضعیت صریح به‌جای دو وضعیت مبهم.
        //
        // پیش از این، «نبودِ» فایل هش برابر «سالم» تفسیر می‌شد
        // (`=== false ||`)، پس برای پنهان کردن دستکاری مانیفست کافی بود
        // مهاجم فایل هش را پاک کند و بکاپ همچنان `ok_quick` بگیرد.
        //
        // اما «نبودِ هش» و «عدم تطابق هش» یک چیز نیستند: بکاپ‌های ساخته‌شده
        // با نسخه‌های قدیمی‌تر ممکن است این فایل را نداشته باشند و سالم
        // باشند. یکسان گرفتن این دو یا Fail-Open است یا اپراتور را از
        // بازیابی یک بکاپ سالم می‌ترساند. پس:
        //   ok_quick         → هش موجود و منطبق
        //   legacy_unverified→ هش موجود نیست (اصالت مانیفست تأییدناپذیر)
        //   corrupt          → هش موجود ولی نامنطبق، یا مانیفست نامعتبر
        $shaState = $this->manifestHashState($dir);
        // شناسهٔ داخل مانیفست باید با نام پوشه یکی باشد (جابه‌جایی/دستکاری مانیفست)
        $idMatches = (string) ($raw['backup_id'] ?? '') === $backupId;
        // Quick check (ارزان برای لیست) — تأیید کامل هش فایل‌ها = verifyBackup()
        // مانیفستِ خراب/دستکاری‌شده نباید لیست را بشکند: ردیف با integrity=corrupt
        // و فیلدهای پیش‌فرض برمی‌گردد تا اپراتور بکاپِ آلوده را ببیند و حذف کند.
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
     * تأیید تمامیت یک بکاپ — OD-9: اگر بکاپ در مخزن فعال نبود، ریشهٔ خصوصی
     * پیش‌فرض و ریشهٔ legacy نیز فقط به‌عنوان «مبدأ فقط‌خواندنی» جست‌وجو
     * می‌شوند (verification/recovery از بکاپ‌های قدیمی داخل webroot).
     *
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
        // Phase 1A — این مسیر همان چیزی است که restorePreflight() و در نتیجه
        // restoreApply() روی آن گیت می‌زنند، پس رفتارش باید صریح باشد.
        //
        // «عدم تطابق» = دستکاری ⇒ خطای قطعی (Fail-Closed).
        // «نبودِ فایل هش» = بکاپ legacy ⇒ بازیابی مسدود نمی‌شود (شکستن
        // بازیابی بکاپ‌های سالمِ قدیمی یک ریسک در دسترس‌پذیری است)، ولی
        // دیگر بی‌صدا هم نیست: به‌صورت هشدار صریح گزارش می‌شود. توجه: خود
        // مانیفست همچنان در برابر sha256 تک‌تک فایل‌ها اعتبارسنجی می‌شود؛
        // این فایل فقط اصالتِ خودِ مانیفست را پوشش می‌دهد.
        $warnings = [];
        $shaState = $this->manifestHashState($dir);
        if ($shaState === self::MANIFEST_HASH_MISMATCH) {
            return ['ok' => false, 'errors' => ['manifest.json tampered'], 'warnings' => []];
        }
        if ($shaState === self::MANIFEST_HASH_MISSING) {
            $warnings[] = 'manifest.json.sha256 missing — legacy backup; manifest authenticity cannot be verified';
        }
        // مانیفستِ داخل این پوشه باید متعلق به همین پوشه باشد
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
        // Audit فقط پس از موفقیت واقعی (زنجیر شواهد صادقانه)
        $this->audit->log('BACKUP_DELETED', null, 'backup', null, null, null, null, ['backup_id' => $backupId]);
        $this->op->info('BACKUP_DELETED', ['backup_id' => $backupId]);
    }

    /**
     * Retention روی مخزن فعال: نگهداری N نسخه‌ی آخر (پیش‌فرض ۱۴ — تنظیم `backup.keep_count`).
     *
     * @return list<string> بکاپ‌های حذف‌شده
     */
    public function prune(int $keep = 0): array
    {
        return $this->pruneStore($this->store, $keep);
    }

    /**
     * @return list<string> بکاپ‌های حذف‌شده
     */
    private function pruneStore(ProtectedBackupStore $store, int $keep = 0): array
    {
        $keep = $keep > 0 ? $keep : max(1, (int) $this->settings->get('backup.keep_count', 14));
        $metas = $this->listMetasIn($store); // مرتب created_at نزولی — جدیدترین اول
        $removed = [];
        foreach (array_slice($metas, $keep) as $old) {
            $id = (string) $old['backup_id'];
            try {
                $store->delete($id);
                // همان زنجیرهٔ Audit/Op حذفِ دستی — فقط پس از موفقیت واقعی
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
     * Preflight — هرگز چیزی را تغییر نمی‌دهد (spec §25).
     *
     * OD-9: مبدأ بکاپ resolve می‌شود (فعال → ریشهٔ خصوصی → ریشهٔ legacy) و
     * نتیجه صراحتاً شامل `source`، هشدارهای تمامیت و پرچم `legacy_unverified`
     * است تا رفتار بازیابی از مبدأ قدیمی، audit-شدنی و بی‌ابهام باشد.
     *
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
     * اعمال Restore — فقط با تأیید صریح؛ خودکار Safety Backup می‌سازد.
     * فقط از CLI/Admin با تأیید (هرگز از Job خودکار).
     *
     * OD-9: Safety Backup فقط در «مقصد امن خصوصی» نوشته می‌شود — مخزن فعال
     * اگر قابل‌نوشتن باشد، وگرنه ریشهٔ خصوصی پیش‌فرض (بیرون از webroot).
     * مبدأ legacy هرگز مقصد Safety Backup نیست ⇒ restore در نصبِ دارای
     * پیکربندی ناامن قفل نمی‌شود، ولی هیچ بایت PHI جدیدی هم داخل webroot
     * نوشته نمی‌شود. اگر هیچ مقصد امنی وجود نداشته باشد، restore قبل از
     * هر گام مخرب Fail-Closed متوقف می‌شود.
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

        // Safety Backup (همیشه قبل از تغییر مخرب) — فقط در مقصد امن خصوصی (OD-9)
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

    /**
     * مبدأ خواندنِ یک بکاپ: مخزن فعال، سپس ریشهٔ خصوصی پیش‌فرض (محل مهاجرت
     * بکاپ‌های legacy) و سپس ریشهٔ قدیمی داخل webroot — دو مورد آخر فقط
     * به‌عنوان مبدأ فقط‌خواندنی. اگر هیچ‌کدام نبود، خودِ مخزن فعال
     * برگردانده می‌شود تا معنای خطای پیشین (manifest missing) حفظ شود.
     */
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

    /**
     * مقصد امن نوشتن Safety Backup (OD-9 — تصمیم مالک، گزینهٔ C):
     * مخزن فعال اگر قابل‌نوشتن باشد؛ وگرنه ریشهٔ خصوصی پیش‌فرض. خودِ
     * `::active()` Fail-Closed است — اگر حتی ریشهٔ خصوصی هم داخل webroot
     * باشد (CPMS_PRIVATE_STORAGE_DIR ناامن)، استثنا پرتاب می‌شود و restore
     * پیش از هر گام مخرب متوقف می‌ماند: بدون مقصد امن، Safety Backup
     * ساخته نمی‌شود و بازیابی مخرب آغاز نمی‌شود.
     */
    private function safetyDestinationStore(): ProtectedBackupStore
    {
        if (!$this->store->isReadonly()) {
            return $this->store;
        }

        return ProtectedBackupStore::active(ProtectedBackupStore::defaultBasePath());
    }

    /** مقایسهٔ نرمال‌شدهٔ دو مسیر ریشه (بدون اثر اسلش انتهایی/ویندوزی). */
    private function sameBasePath(string $a, string $b): bool
    {
        $norm = static function (string $p): string {
            return rtrim(str_replace('\\', '/', $p), '/');
        };

        return $norm($a) === $norm($b);
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
