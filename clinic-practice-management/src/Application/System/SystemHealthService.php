<?php

declare(strict_types=1);

namespace ClinicCore\Application\System;

use ClinicCore\Application\Backup\BackupService;
use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Application\Update\UpdateService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Settings\Settings;

/**
 * Health/سازگاری سیستم (F10 — spec §10–§13/§40): چک‌های بدون PHI.
 *
 * هر چک: {key, label, status: pass|warning|fail|not_configured|unknown, detail}
 * Host Capability Detection (spec §10–§13):
 *   SUPPORTED / SUPPORTED_WITH_WARNINGS / UNSUPPORTED
 * با دسته‌بندی خالص (قابل واحدتست): failهای حیاتی → UNSUPPORTED؛ فقط
 * warning → SUPPORTED_WITH_WARNINGS.
 *
 * Live Network تست (سرور لایسنس/خروجی) عمداً اینجا اجرا نمی‌شود — فقط
 * پیکربندی بررسی می‌شود (BLOCKED_BY_ENVIRONMENT برای تأیید زنده).
 */
final class SystemHealthService
{
    public const PASS = 'pass';
    public const WARNING = 'warning';
    public const FAIL = 'fail';
    public const NOT_CONFIGURED = 'not_configured';
    public const UNKNOWN = 'unknown';

    public const HOST_SUPPORTED = 'SUPPORTED';
    public const HOST_SUPPORTED_WITH_WARNINGS = 'SUPPORTED_WITH_WARNINGS';
    public const HOST_UNSUPPORTED = 'UNSUPPORTED';

    /** چک‌های حیاتی برای پشتیبانی میزبان */
    private const CRITICAL_KEYS = [
        'php.version',
        'db.reachable',
        'db.migrated',
        'storage.files',
        'storage.backups',
        'cron.jobs',
    ];

    /** @var callable|null (برای تست/تزریق) */
    private $now = null;

    public function __construct(
        private readonly CpmsDb $db,
        private readonly Settings $settings,
        private readonly LicenseService $licenses,
        private readonly BackupService $backups,
        private readonly UpdateService $updates,
        private readonly OpLogger $op,
        ?callable $now = null
    ) {
        $this->now = $now;
    }

    private function ts(): int
    {
        return $this->now !== null ? (int) ($this->now)() : time();
    }

    /**
     * @return array{checks: list<array{key:string,label:string,status:string,detail:string}>, host: array{status:string, issues:list<string>}}
     */
    public function run(): array
    {
        $checks = [];
        $add = function (string $key, string $label, string $status, string $detail = '') use (&$checks): void {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
        };

        // ---------- PHP ----------
        $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
        $add('php.version', 'PHP نسخه', $phpOk ? self::PASS : self::FAIL, PHP_VERSION);
        $add(
            'php.sodium',
            'PHP ext/sodium',
            extension_loaded('sodium') ? self::PASS : self::WARNING,
            extension_loaded('sodium') ? 'امضای مجوز/انتشار در دسترس است' : 'غایب — تأیید امضای اسناد مجوز/انتشار ممکن نیست (fail-closed)'
        );
        $mem = (int) ini_get('memory_limit');
        $add('php.memory', 'PHP memory_limit', $mem <= 0 || $mem >= 128 ? self::PASS : self::WARNING, ini_get('memory_limit') ?: '?');

        // ---------- دیتابیس ----------
        $dbOk = false;
        try {
            $dbOk = (int) $this->db->fetchValue('SELECT 1') === 1;
        } catch (\Throwable) {
            $dbOk = false;
        }
        $add('db.reachable', 'دیتابیس', $dbOk ? self::PASS : self::FAIL, $dbOk ? 'اتصال برقرار است' : 'عدم دسترسی به دیتابیس');

        $migrated = false;
        $schemaVersion = '';
        try {
            $migrated = $this->isMigrated();
            $schemaVersion = (string) (App::migrations()->currentVersion() ?? '');
        } catch (\Throwable) {
            $migrated = false;
        }
        $add('db.migrated', 'Migration Schema', $migrated ? self::PASS : self::FAIL, $schemaVersion);

        $tables = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s",
            [$this->db->dbPrefix() . 'cpms_%']
        );
        $add('db.tables', 'جداول cpms_', $tables > 0 ? self::PASS : self::FAIL, (string) $tables);

        // ---------- Cron / Jobs (spec §10–§13) ----------
        $queue = App::queueHealth(300);
        $cronStatus = self::PASS;
        $cronDetail = 'System Cron فعال است';
        if ($queue['stale']) {
            $cronStatus = self::FAIL;
            $cronDetail = 'آخرین tick ' . ($queue['last_tick_at'] ? gmdate('Y-m-d H:i', (int) $queue['last_tick_at']) . ' UTC' : 'هرگز') . ' — Queue متوقف است';
        } elseif (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
            $cronStatus = self::WARNING;
            $cronDetail = 'WP-Cron به‌عنوان Fallback (توصیه: System Cron + DISABLE_WP_CRON — ADR-0016)';
        } else {
            $cronDetail = 'System Cron (DISABLE_WP_CRON)';
        }
        $add('cron.jobs', 'Cron/Queue', $cronStatus, $cronDetail . ' | failed=' . $queue['failed']);

        // ---------- Storage (spec §22/§23) ----------
        $add('storage.files', 'Storage فایل‌های پزشکی', $this->storageStatus($this->filesBase(), 'storage.files'));
        $add('storage.backups', 'Storage بکاپ', $this->storageStatus($this->backups->store()->basePath(), 'storage.backups'));

        // ---------- License (ADR-0023) ----------
        $state = $this->licenses->currentState();
        $licenseStatus = match ($state['status']) {
            LicenseStatus::ACTIVE => self::PASS,
            LicenseStatus::EXPIRING, LicenseStatus::GRACE => self::WARNING,
            LicenseStatus::NOT_CONFIGURED => self::NOT_CONFIGURED,
            default => self::FAIL, // RESTRICTED/SUSPENDED/REVOKED/INVALID/UNREACHABLE
        };
        $licenseDetail = $state['status'] . ($state['reason'] !== '' ? ' (' . $state['reason'] . ')' : '');
        if ($state['expires_at'] !== null && $state['status'] !== LicenseStatus::NOT_CONFIGURED) {
            $licenseDetail .= ' | expires ' . gmdate('Y-m-d', (int) $state['expires_at']);
        }
        $add('license.state', 'مجوز', $licenseStatus, $licenseDetail);
        $add(
            'license.gateway',
            'سرور مجوز',
            $this->settings->get('license.server_url', '') !== '' ? self::PASS : self::NOT_CONFIGURED,
            (string) $this->settings->get('license.server_url', '') !== '' ? 'پیکربندی‌شده' : 'فعال‌سازی دستی/آفلاین ممکن است'
        );

        // ---------- Backup ----------
        $bkEnabled = (bool) $this->settings->get('backup.enabled', false);
        $lastRun = (int) $this->settings->get('backup.last_run_at', 0);
        if (!$bkEnabled) {
            $add('backup.enabled', 'بکاپ دوره‌ای', self::NOT_CONFIGURED, 'غیرفعال — در «CPMS (سیستم)» فعال کنید (spec §22)');
        } else {
            $ageH = $lastRun > 0 ? (int) (($this->ts() - $lastRun) / 3600) : -1;
            $status = $lastRun > 0 && $ageH <= (int) $this->settings->get('backup.interval_hours', 24) ? self::PASS : self::WARNING;
            $add('backup.enabled', 'بکاپ دوره‌ای', $status, $lastRun > 0 ? 'آخرین: ' . $ageH . ' ساعت پیش' : 'هنوز اجرا نشده');
        }

        // ---------- Update ----------
        if (!$this->updates->isUpdateEntitled()) {
            $add('update.entitlement', 'به‌روزرسانی', self::FAIL, 'سند مجوز feature `updates` را نمی‌دهد');
        } else {
            $add('update.entitlement', 'به‌روزرسانی', self::PASS, 'مجاز');
        }

        // ---------- HTTPS ----------
        $add('https.active', 'HTTPS', function_exists('is_ssl') && is_ssl() ? self::PASS : self::WARNING, function_exists('is_ssl') && is_ssl() ? 'فعال' : 'روی HTTP — برای تولید HTTPS الزامی است');

        // ---------- Host Capability ----------
        $host = self::classifyHost($checks);

        return ['checks' => $checks, 'host' => $host];
    }

    /**
     * دسته‌بندی خالص وضعیت میزبان — واحدتست.
     *
     * @param list<array{key:string,label:string,status:string,detail:string}> $checks
     *
     * @return array{status:string, issues:list<string>}
     */
    public static function classifyHost(array $checks): array
    {
        $issues = [];
        $warnings = [];
        foreach ($checks as $c) {
            if (!in_array($c['key'], self::CRITICAL_KEYS, true)) {
                continue;
            }
            if ($c['status'] === self::FAIL || $c['status'] === self::UNKNOWN) {
                $issues[] = $c['key'] . ': ' . $c['detail'];
            } elseif ($c['status'] === self::WARNING) {
                $warnings[] = $c['key'];
            }
        }
        if ($issues !== []) {
            return ['status' => self::HOST_UNSUPPORTED, 'issues' => $issues];
        }

        return [
            'status' => $warnings !== [] ? self::HOST_SUPPORTED_WITH_WARNINGS : self::HOST_SUPPORTED,
            'issues' => $warnings !== [] ? $warnings : [],
        ];
    }

    // ================= private =================

    private function isMigrated(): bool
    {
        $latest = '';
        foreach ((array) glob(CPMS_PLUGIN_DIR . 'src/Migrations/????_??_??_????_*.php') as $f) {
            $latest = max($latest, substr(basename($f), 0, 15));
        }
        $applied = App::migrations()->currentVersion();

        return $latest !== '' && $applied !== null && $applied >= $latest;
    }

    private function filesBase(): string
    {
        $configured = trim((string) $this->settings->get('files.storage_path', ''));
        if ($configured !== '') {
            return $configured;
        }

        return defined('WP_CONTENT_DIR') ? (string) WP_CONTENT_DIR . '/clinic-files' : dirname(__DIR__, 3) . '/clinic-files';
    }

    private function storageStatus(string $path, string $key): string
    {
        if (!is_dir($path)) {
            return self::NOT_CONFIGURED;
        }
        if (!is_writable($path)) {
            return self::FAIL;
        }
        if (is_file($path . '/.htaccess')) {
            return self::PASS;
        }

        return self::WARNING; // پوشه موجود ولی بدون گارد سرور (در اولین استفاده ساخته می‌شود)
    }
}
