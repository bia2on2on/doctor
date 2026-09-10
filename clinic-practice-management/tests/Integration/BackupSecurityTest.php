<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Backup\BackupService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Backup\BackupSqlDumper;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use WP_UnitTestCase;

/**
 * Phase 1A — Item 6 (Backup Security) روی همان پیاده‌سازی موجود.
 *
 * هیچ قابلیت جدیدی از Phase 15 اینجا اضافه نمی‌شود؛ فقط کنترل دسترسی،
 * افشای محل ذخیره و صحت‌سنجی بررسی می‌شود.
 *
 * نقص اصلاح‌شده: بررسی صحت مانیفست Fail-Open بود — نبودِ فایل
 * `manifest.json.sha256` به معنای «سالم» تفسیر می‌شد، پس برای پنهان کردن
 * دستکاری کافی بود مهاجم آن فایل را حذف کند.
 */
final class BackupSecurityTest extends WP_UnitTestCase
{
    private string $tmpBase = '';
    private ProtectedBackupStore $store;
    private BackupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();

        // ریشهٔ موقت — تستِ امنیت نباید به پوشهٔ بکاپِ نصب واقعی دست بزند.
        $this->tmpBase = sys_get_temp_dir() . '/cpms-backup-sec-' . bin2hex(random_bytes(5));
        $filesBase = $this->tmpBase . '/clinic-files';
        mkdir($filesBase . '/1/a3', 0750, true);
        file_put_contents($filesBase . '/1/a3/' . str_repeat('b', 32) . '.pdf', 'clinical-bytes');

        $this->store = ProtectedBackupStore::active($this->tmpBase . '/backups');
        $this->service = new BackupService(
            App::db(),
            $this->store,
            new BackupSqlDumper(App::db()),
            App::settings(),
            App::audit(),
            App::op(),
            $filesBase
        );
    }

    protected function tearDown(): void
    {
        if ($this->tmpBase !== '' && is_dir($this->tmpBase)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpBase, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $entry) {
                $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->tmpBase);
        }
        parent::tearDown();
    }

    private function store(): ProtectedBackupStore
    {
        return $this->store;
    }

    private function service(): BackupService
    {
        return $this->service;
    }

    public function testServerGuardsIncludeNonApacheWebServers(): void
    {
        $this->store()->ensureGuards();
        $base = $this->store()->basePath();

        self::assertFileExists($base . '/.htaccess');
        self::assertFileExists($base . '/index.php');
        self::assertFileExists($base . '/web.config', 'گارد IIS برای پوشهٔ بکاپ نوشته نشد.');
        self::assertFileExists($base . '/README-SECURITY.txt');
        self::assertStringContainsString(
            'nginx',
            (string) file_get_contents($base . '/README-SECURITY.txt'),
            'یادداشت باید صراحتاً بگوید nginx فایل .htaccess را نمی‌خواند.'
        );
    }

    /**
     * شناسهٔ بکاپ نباید قابل حدس باشد و نباید اجازهٔ پیمایش مسیر بدهد.
     */
    public function testBackupIdRejectsTraversalAndControlCharacters(): void
    {
        $payloads = ['../../etc', '..', '.', '', 'a/../../b', 'x' . "\0" . 'y', '/absolute', 'AB', 'ab'];

        foreach ($payloads as $payload) {
            $rejected = false;
            try {
                $this->store()->dirOf($payload);
            } catch (\Throwable) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'شناسهٔ بکاپ باید رد می‌شد: ' . var_export($payload, true));
        }
    }

    /**
     * حذف فایل هش نباید بکاپ را «سالم» جلوه دهد — ولی «دستکاری‌شده» هم
     * نیست: بکاپ‌های نسخه‌های قدیمی‌تر ممکن است این فایل را نداشته باشند.
     */
    public function testMissingManifestHashIsReportedAsLegacyNotOkAndNotCorrupt(): void
    {
        $created = $this->service()->createBackup();
        $backupId = (string) $created['backup_id'];
        $dir = $this->store()->dirOf($backupId);

        $meta = $this->service()->backupMeta($backupId);
        self::assertNotNull($meta);
        self::assertSame('ok_quick', $meta['integrity'], 'بکاپ تازه باید سالم باشد.');

        self::assertFileExists($dir . '/manifest.json.sha256');
        unlink($dir . '/manifest.json.sha256');

        $after = $this->service()->backupMeta($backupId);
        self::assertNotNull($after);
        self::assertSame(
            'legacy_unverified',
            $after['integrity'],
            'نبودِ فایل هش نباید «سالم» تفسیر شود و نباید با «دستکاری‌شده» یکی گرفته شود.'
        );

        $this->service()->deleteBackup($backupId);
    }

    /**
     * سازگاری: بکاپ legacy (بدون فایل هش) باید همچنان قابل بازیابی بماند —
     * ولی هشدارش صریح باشد.
     */
    public function testLegacyBackupWithoutManifestHashStaysRestorableWithExplicitWarning(): void
    {
        $created = $this->service()->createBackup();
        $backupId = (string) $created['backup_id'];
        $dir = $this->store()->dirOf($backupId);

        unlink($dir . '/manifest.json.sha256');

        $verify = $this->service()->verifyBackup($backupId);
        self::assertTrue($verify['ok'], 'بکاپ سالمِ قدیمی نباید بی‌صدا غیرقابل بازیابی شود.');
        self::assertNotEmpty($verify['warnings'], 'نبودِ فایل هش باید هشدار صریح تولید کند.');
        self::assertStringContainsString('legacy', implode(' ', $verify['warnings']));

        $pre = $this->service()->restorePreflight($backupId);
        self::assertTrue($pre['restore_safe'], 'restore_safe نباید برای بکاپ legacy سالم false شود.');

        $this->service()->deleteBackup($backupId);
    }

    /**
     * اما دستکاری واقعی مانیفست باید مسیر بازیابی را قطعاً ببندد.
     */
    public function testTamperedManifestBlocksRestorePath(): void
    {
        $created = $this->service()->createBackup();
        $backupId = (string) $created['backup_id'];
        $dir = $this->store()->dirOf($backupId);

        $raw = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        $raw['injected'] = 'evil';
        file_put_contents($dir . '/manifest.json', json_encode($raw));

        $verify = $this->service()->verifyBackup($backupId);
        self::assertFalse($verify['ok']);
        self::assertContains('manifest.json tampered', $verify['errors']);

        $pre = $this->service()->restorePreflight($backupId);
        self::assertFalse($pre['restore_safe'], 'بکاپ دستکاری‌شده نباید restore_safe باشد.');

        $this->service()->deleteBackup($backupId);
    }

    /**
     * فایل هش خالی هم نباید سالم شمرده شود.
     */
    public function testEmptyManifestHashFileIsCorrupt(): void
    {
        $created = $this->service()->createBackup();
        $backupId = (string) $created['backup_id'];
        $dir = $this->store()->dirOf($backupId);

        file_put_contents($dir . '/manifest.json.sha256', "   \n");

        $after = $this->service()->backupMeta($backupId);
        self::assertNotNull($after);
        self::assertSame('legacy_unverified', $after['integrity'], 'فایل هش خالی = تأییدناپذیر، نه سالم.');

        $this->service()->deleteBackup($backupId);
    }
}
