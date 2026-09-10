<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Backup\BackupService;
use ClinicCore\Application\System\SystemHealthService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Backup\BackupSqlDumper;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Infrastructure\Storage\PrivateStorageLocation;
use ClinicCore\Infrastructure\Storage\PrivateStorageMigrator;
use ClinicCore\Infrastructure\Storage\StorageConfigurationException;
use ReflectionClass;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * OD-9 (تصمیم مالک) — مرز امنیتی ذخیره‌سازی بکاپ:
 *
 *   A) ریشهٔ بکاپِ فعال باید بیرون از DocumentRoot باشد — Fail-Closed،
 *      بدون fallback بی‌صدای ناامن.
 *   B) ریشهٔ داخل webroot فقط «مبدأ legacy فقط‌خواندنی» است — هرگز مقصد
 *      نوشتن نمی‌شود.
 *   C) Restore به‌خاطر سیاست جدید قفل نمی‌شود: Safety Backup فقط به
 *      «مقصد امن خصوصی» هدایت می‌شود؛ بکاپ دستکاری‌شده هرگز restore نمی‌شود.
 *   D) مهاجرت بکاپ‌های legacy داخل webroot به ریشهٔ خصوصی: idempotent،
 *      بدون overwrite کورکورانه، مبدأ فقط پس از تأیید مقصد حذف می‌شود.
 *
 * این تست‌ها به `ABSPATH` واقعی (Integration) نیاز دارند تا «داخل
 * DocumentRoot» معنا داشته باشد — الگوی PrivateStorageConfigurationTest.
 *
 * NOTE: مثل BackupEngineTest، `restoreApply` تا مرحلهٔ DDL اجرا نمی‌شود
 * (DDL داخل تراکنش تست ایزوله‌سازی WP را می‌شکند). برای اثبات رفتار
 * Safety Backup، آرتیفکتی ساخته می‌شود که preflight را پاس می‌کند ولی
 * `db.sql` آن خالی است ⇒ restore دقیقاً بعد از Safety Backup و قبل از
 * هر DDL متوقف می‌شود — مسیر مخرب کامل قبلاً توسط Restore Drill سطح
 * OS در Pilot/Staging Gate پوشش داده شده است.
 */
final class BackupStorageBoundaryTest extends WP_UnitTestCase
{
    private string $tmp;
    private string $legacyProbe;
    private string $filesBase;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();

        $this->tmp = sys_get_temp_dir() . '/cpms-od9-' . bin2hex(random_bytes(5));
        $this->filesBase = $this->tmp . '/clinic-files';
        mkdir($this->filesBase . '/1/a3', 0750, true);
        file_put_contents($this->filesBase . '/1/a3/' . str_repeat('c', 32) . '.pdf', 'od9-clinical-bytes');

        // مبدأ legacy شبیه‌سازی‌شده — واقعاً داخل DocumentRoot
        $this->legacyProbe = rtrim((string) ABSPATH, '/') . '/wp-content/cpms-backups-od9-probe';
    }

    protected function tearDown(): void
    {
        App::settings()->set('backup.storage_path', '');
        App::settings()->set('files.storage_path', '');
        $this->rmrf($this->tmp);
        $this->rmrf($this->legacyProbe);
        $this->rmrf(ProtectedBackupStore::legacyBasePath() . '/cpms-backup-od9.fallback1111');
        if (is_dir(ProtectedBackupStore::legacyBasePath())) {
            @rmdir(ProtectedBackupStore::legacyBasePath()); // فقط اگر خالی باشد
        }
        // بکاپ‌هایی که این کلاس در ریشهٔ خصوصی واقعی ممکن است ساخته باشد
        // (Safety Backup) — هیچ تست دیگری بکاپ در آن نمی‌سازد.
        foreach ($this->privateRootBackupDirs() as $dir) {
            $this->rmrf($dir);
        }
        parent::tearDown();
    }

    // ================= helpers =================

    private function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->rmrf($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    private function service(ProtectedBackupStore $store): BackupService
    {
        return new BackupService(
            App::db(),
            $store,
            new BackupSqlDumper(App::db()),
            App::settings(),
            App::audit(),
            App::op(),
            $this->filesBase
        );
    }

    /**
     * @return list<string> مسیر بکاپ‌های موجود در ریشهٔ خصوصی واقعی
     */
    private function privateRootBackupDirs(): array
    {
        $dirs = [];
        foreach ((array) glob(ProtectedBackupStore::defaultBasePath() . '/cpms-backup-*') as $dir) {
            if (is_dir($dir)) {
                $dirs[] = $dir;
            }
        }
        sort($dirs);

        return $dirs;
    }

    /** جابه‌جایی بازگشتی یک دایرکتوری (rename، با fallback کپی برای CrOSS-Device). */
    private function moveDir(string $src, string $dst): void
    {
        if (@rename($src, $dst)) {
            return;
        }
        $this->rmrf($dst);
        $this->copyDir($src, $dst);
        $this->rmrf($src);
    }

    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst) && !@mkdir($dst, 0750, true) && !is_dir($dst)) {
            throw new \RuntimeException('copy mkdir failed: ' . $dst);
        }
        foreach (scandir($src) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $from = $src . '/' . $e;
            $to = $dst . '/' . $e;
            if (is_dir($from)) {
                $this->copyDir($from, $to);
            } elseif (!@copy($from, $to)) {
                throw new \RuntimeException('copy failed: ' . $from);
            }
        }
    }

    /**
     * یک بکاپ واقعی در مخزن امن (بیرون webroot) می‌سازد و سپس آن را به مبدأ
     * legacy داخل webroot منتقل می‌کند — یعنی دقیقاً وضعیت یک نصب قدیمی.
     */
    private function seedBackupOutside(string $destinationRoot): string
    {
        $staging = ProtectedBackupStore::active($this->tmp . '/staging');
        $created = $this->service($staging)->createBackup('od9-seed');
        $id = (string) $created['backup_id'];

        if (!is_dir($destinationRoot)) {
            mkdir($destinationRoot, 0777, true);
        }
        $this->moveDir($staging->dirOf($id), $destinationRoot . '/' . $id);

        return $id;
    }

    /**
     * db.sql را خالی و هش مانیفست را هم‌ساز می‌کند تا restoreApply دقیقاً
     * بعد از Safety Backup و قبل از هر DDL متوقف شود.
     */
    private function makeArtifactAbortBeforeDdl(string $backupDir): void
    {
        file_put_contents($backupDir . '/db.sql', '');
        $raw = json_decode((string) file_get_contents($backupDir . '/manifest.json'), true);
        self::assertIsArray($raw);
        $raw['db']['sha256'] = (string) hash_file('sha256', $backupDir . '/db.sql');
        $json = (string) json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($backupDir . '/manifest.json', $json);
        file_put_contents($backupDir . '/manifest.json.sha256', hash('sha256', $json));
    }

    // ================= A — پیش‌فرض و مسیر امن =================

    public function testDefaultBackupPathIsOutsideTheWebRoot(): void
    {
        $default = ProtectedBackupStore::defaultBasePath();

        self::assertFalse(
            PrivateStorageLocation::isInsideWebRoot($default),
            "مسیر پیش‌فرض بکاپ نباید داخل DocumentRoot باشد: {$default}"
        );
        self::assertStringNotContainsString('/wp-content/', $default);

        $store = ProtectedBackupStore::active($default);
        self::assertFalse($store->isReadonly());
        self::assertFalse($store->isInsideWebRoot());
    }

    public function testSafeConfiguredBackupPathIsAcceptedAndWritable(): void
    {
        $safe = $this->tmp . '/backups-safe';
        App::settings()->set('backup.storage_path', $safe);

        $service = App::backupService();
        self::assertFalse($service->store()->isReadonly());
        self::assertSame($safe, $service->store()->basePath());

        $meta = $service->createBackup('od9-safe-config');
        self::assertNotSame('', (string) $meta['backup_id']);
        self::assertFileExists($safe . '/' . $meta['backup_id'] . '/manifest.json');
    }

    // ================= B — رد Fail-Closed =================

    public function testInsideWebRootActivePathIsRejected(): void
    {
        mkdir($this->legacyProbe, 0777, true);

        try {
            ProtectedBackupStore::active($this->legacyProbe);
            self::fail('باید استثنای پیکربندی پرتاب می‌شد.');
        } catch (StorageConfigurationException $e) {
            self::assertSame('CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT', $e->errorCode);
            self::assertSame($this->legacyProbe, $e->path);
        }
    }

    /**
     * Symlinkی که بیرون به‌نظر می‌رسد ولی به داخل webroot می‌رسد باید رد شود —
     * مقایسه روی `realpath` انجام می‌شود، نه روی رشتهٔ خام.
     */
    public function testSymlinkResolvingInsideTheWebRootIsRejected(): void
    {
        mkdir($this->legacyProbe, 0777, true);
        $link = $this->tmp . '/looks-outside-backups';
        if (!@symlink($this->legacyProbe, $link)) {
            self::markTestSkipped('symlink در این محیط ممکن نیست.');
        }

        self::assertTrue(PrivateStorageLocation::isInsideWebRoot($link));
        $this->expectException(StorageConfigurationException::class);
        ProtectedBackupStore::active($link);
    }

    /**
     * مهم‌ترین قید A: مسیر ناامنِ پیکربندی‌شده نباید بی‌سروصدا به مسیر امن
     * fallback کند. App آن را به مبدأ legacy فقط‌خواندنی تنزل می‌دهد — همان
     * مسیر می‌ماند، خواندن ادامه دارد، نوشتن Fail-Closed خطا می‌دهد.
     */
    public function testUnsafeConfiguredPathIsDowngradedExplicitlyAndWritesFailClosed(): void
    {
        mkdir($this->legacyProbe, 0777, true);
        App::settings()->set('backup.storage_path', $this->legacyProbe);

        $service = App::backupService();

        self::assertTrue($service->store()->isReadonly(), 'پیکربندی ناامن باید فقط‌خواندنی شود.');
        self::assertSame(
            $this->legacyProbe,
            $service->store()->basePath(),
            'مسیر نباید بی‌سروصدا عوض شود (fallback ناامن/ناخواسته ممنوع).'
        );

        try {
            $service->createBackup('must-fail-closed');
            self::fail('نوشتن در مسیر داخل webroot باید Fail-Closed می‌شد.');
        } catch (StorageConfigurationException $e) {
            self::assertSame('CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT', $e->errorCode);
        }

        // هیچ بایت PHI جدیدی داخل webroot ننوشته شد — حتی گاردها هم نه
        self::assertSame([], (array) glob($this->legacyProbe . '/cpms-backup-*'));
        self::assertFileDoesNotExist($this->legacyProbe . '/index.php');
    }

    // ================= B — مبدأ legacy فقط خواندنی =================

    public function testLegacySourceIsReadableAsSourceOnly(): void
    {
        $id = 'cpms-backup-od9.readonly1';
        mkdir($this->legacyProbe . '/' . $id . '/storage', 0777, true);
        file_put_contents($this->legacyProbe . '/' . $id . '/db.sql', '-- dump');
        file_put_contents($this->legacyProbe . '/.htaccess', 'deny');

        $source = ProtectedBackupStore::legacySource($this->legacyProbe);

        // خواندن مجاز است
        self::assertTrue($source->exists($id));
        self::assertSame($this->legacyProbe . '/' . $id, $source->dirOf($id));
        self::assertSame([$id], $source->listIds());
        self::assertFileDoesNotExist($this->legacyProbe . '/index.php', 'listIds نباید گارد بنویسد.');

        // نوشتن/حذف ممنوع است — مبدأ legacy هرگز ذخیره‌سازی فعال نمی‌شود
        try {
            $source->ensureGuards();
            self::fail('ensureGuards روی مبدأ legacy باید رد می‌شد.');
        } catch (StorageConfigurationException $e) {
            self::assertSame('CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT', $e->errorCode);
        }
        try {
            $source->createDir('cpms-backup-od9.newwrite1');
            self::fail('createDir روی مبدأ legacy باید رد می‌شد.');
        } catch (StorageConfigurationException $e) {
            self::assertSame('CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT', $e->errorCode);
        }
        try {
            $source->delete($id);
            self::fail('delete روی مبدأ legacy باید رد می‌شد.');
        } catch (StorageConfigurationException $e) {
            self::assertSame('CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT', $e->errorCode);
        }

        // و همان مسیر همچنان به‌عنوان مخزن فعال رد می‌شود
        $this->expectException(StorageConfigurationException::class);
        ProtectedBackupStore::active($this->legacyProbe);
    }

    /**
     * رزولوشن مبدأ در سطح سرویس: بکاپی که فقط در ریشهٔ قدیمی داخل webroot
     * است، برای verification/recovery پیدا می‌شود و preflight صراحتاً
     * مبدأ legacy را گزارش می‌دهد.
     */
    public function testServiceResolvesBackupThatExistsOnlyInLegacyDefaultLocation(): void
    {
        $legacyDefault = ProtectedBackupStore::legacyBasePath();
        $id = $this->seedBackupOutside($legacyDefault);

        // مخزن فعالِ امن (خارج webroot) — بکاپ در آن نیست
        $activeStore = ProtectedBackupStore::active($this->tmp . '/active-empty');
        $service = $this->service($activeStore);

        $verify = $service->verifyBackup($id);
        self::assertTrue($verify['ok'], 'بکاپ باید از مبدأ legacy پیدا و تأیید شود.');

        $pre = $service->restorePreflight($id);
        self::assertTrue($pre['restore_safe']);
        self::assertSame('legacy', $pre['source']);
        self::assertSame($legacyDefault, $pre['source_root']);
    }

    // ================= C — Restore بدون deadlock + مقصد Safety =================

    /**
     * هستهٔ OD-9: در نصبی با ریشهٔ بکاپِ ناامن (داخل webroot)، restore از
     * مبدأ legacy کار می‌کند (قفل نمی‌شود) و Safety Backup فقط در مقصد امن
     * خصوصی نوشته می‌شود — هرگز در مبدأ legacy.
     */
    public function testRestoreFromLegacySourceRedirectsSafetyBackupToPrivateDestination(): void
    {
        $id = $this->seedBackupOutside($this->legacyProbe);
        $this->makeArtifactAbortBeforeDdl($this->legacyProbe . '/' . $id);

        $unsafe = $this->service(ProtectedBackupStore::legacySource($this->legacyProbe));
        $pre = $unsafe->restorePreflight($id);
        self::assertTrue($pre['restore_safe'], 'preflight باید از مبدأ legacy پاس شود.');
        self::assertSame('legacy', $pre['source']);

        $before = $this->privateRootBackupDirs();

        try {
            $unsafe->restoreApply($id, true);
            self::fail('باید قبل از DDL متوقف می‌شد (db.sql خالی).');
        } catch (BackupException $e) {
            self::assertSame('CLINIC_BACKUP_IO', $e->getErrorCode());
        }

        // Safety Backup فقط در ریشهٔ خصوصی ساخته شد
        $after = $this->privateRootBackupDirs();
        $new = array_values(array_diff($after, $before));
        self::assertCount(1, $new, 'دقیقاً یک Safety Backup باید ساخته شود.');
        $safetyDir = $new[0];
        self::assertFileExists($safetyDir . '/manifest.json');

        $safetyManifest = json_decode((string) file_get_contents($safetyDir . '/manifest.json'), true);
        self::assertIsArray($safetyManifest);
        self::assertStringStartsWith(
            'pre-restore-safety-',
            (string) $safetyManifest['note'],
            'بکاپ ساخته‌شده باید Safety Backupِ restore باشد.'
        );

        // مقصد Safety هرگز مبدأ legacy نیست و در webroot چیزی نوشته نشد
        self::assertSame(ProtectedBackupStore::defaultBasePath(), dirname($safetyDir));
        self::assertSame(
            [$id],
            array_map('basename', (array) glob($this->legacyProbe . '/cpms-backup-*')),
            'هیچ بکاپ جدیدی داخل webroot نباید ساخته شود — فقط بکاپ مبدأ آنجاست.'
        );

        // DB دست‌نخورده ماند (متوقف‌شدن قبل از تراکنش مخرب)
        self::assertSame(1, (int) App::db()->fetchValue('SELECT 1'));
    }

    /**
     * مسیر عادی (پیکربندی امن): Safety Backup در همان مخزن فعال نوشته
     * می‌شود — رفتار پیشین restore حفظ شده است.
     */
    public function testRestoreWithSafeConfigKeepsSafetyBackupInActiveStore(): void
    {
        $active = ProtectedBackupStore::active($this->tmp . '/active-restore');
        $service = $this->service($active);
        $id = $this->seedBackupOutside($this->tmp . '/staging');
        // بکاپ را از staging به مخزن فعالِ همین سرویس منتقل کن
        $this->moveDir($this->tmp . '/staging/' . $id, $active->basePath() . '/' . $id);
        $this->makeArtifactAbortBeforeDdl($active->dirOf($id));

        $before = count((array) glob($active->basePath() . '/cpms-backup-*'));

        try {
            $service->restoreApply($id, true);
            self::fail('باید قبل از DDL متوقف می‌شد (db.sql خالی).');
        } catch (BackupException $e) {
            self::assertSame('CLINIC_BACKUP_IO', $e->getErrorCode());
        }

        $after = count((array) glob($active->basePath() . '/cpms-backup-*'));
        self::assertSame($before + 1, $after, 'Safety Backup باید در مخزن فعال نوشته شود.');
        self::assertSame([], $this->privateRootBackupDirs(), 'وقتی مخزن فعال امن است، مقصد خصوصی جدا لازم نیست.');
    }

    /**
     * بکاپ دستکاری‌شده هرگز restore نمی‌شود — حتی از مبدأ legacy — و پیش از
     * آن، هیچ Safety Backupی هم ساخته نمی‌شود (preflight گیت می‌زند).
     */
    public function testTamperedLegacyBackupIsRejectedBeforeSafetyBackup(): void
    {
        $id = $this->seedBackupOutside($this->legacyProbe);

        // دستکاری مانیفست بدون به‌روزرسانی فایل هش ⇒ mismatch
        $dir = $this->legacyProbe . '/' . $id;
        $raw = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        self::assertIsArray($raw);
        $raw['note'] = 'TAMPERED-BY-ATTACKER';
        file_put_contents($dir . '/manifest.json', (string) json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $unsafe = $this->service(ProtectedBackupStore::legacySource($this->legacyProbe));

        $verify = $unsafe->verifyBackup($id);
        self::assertFalse($verify['ok']);
        self::assertContains('manifest.json tampered', $verify['errors']);

        $pre = $unsafe->restorePreflight($id);
        self::assertFalse($pre['restore_safe']);

        try {
            $unsafe->restoreApply($id, true);
            self::fail('بکاپ دستکاری‌شده نباید restore شود.');
        } catch (BackupException $e) {
            self::assertSame('CLINIC_BACKUP_PREFLIGHT_FAILED', $e->getErrorCode());
        }

        self::assertSame([], $this->privateRootBackupDirs(), 'پیش از preflight موفق هیچ Safety Backupی نباید ساخته شود.');
        self::assertSame(1, (int) App::db()->fetchValue('SELECT 1'));
    }

    /**
     * رفتار legacy_unverified صریح است: بکاپ بدون فایل هش مانیفست،
     * بازیابی‌پذیر می‌ماند ولی با هشدار صریح گزارش می‌شود (نه بی‌صدا).
     */
    public function testLegacyUnverifiedBackupIsExplicitlyReportedAndRestorable(): void
    {
        $id = $this->seedBackupOutside($this->legacyProbe);
        unlink($this->legacyProbe . '/' . $id . '/manifest.json.sha256');

        $unsafe = $this->service(ProtectedBackupStore::legacySource($this->legacyProbe));

        $verify = $unsafe->verifyBackup($id);
        self::assertTrue($verify['ok'], 'بکاپ legacy بدون فایل هش باید قابل بازیابی بماند.');
        self::assertNotEmpty($verify['warnings']);

        $pre = $unsafe->restorePreflight($id);
        self::assertTrue($pre['restore_safe']);
        self::assertTrue($pre['legacy_unverified']);
        self::assertNotEmpty($pre['integrity_warnings']);
        self::assertSame('legacy', $pre['source']);
    }

    // ================= D — مهاجرت ریشهٔ ناامن/legacy =================

    /**
     * ریشهٔ بکاپِ پیکربندی‌شده داخل webroot: محتوایش (بکاپ‌های legacy) توسط
     * ensurePrivateStorage به ریشهٔ خصوصی منتقل می‌شود — idempotent، مبدأ
     * فقط پس از تأیید بایت‌به‌بایت حذف می‌شود و Setting دست نمی‌خورد.
     */
    public function testUnsafeConfiguredBackupRootIsMigratedToPrivateRootIdempotently(): void
    {
        $id = 'cpms-backup-od9.migrate01';
        $src = $this->legacyProbe . '/' . $id;
        mkdir($src . '/storage', 0777, true);
        file_put_contents($src . '/db.sql', 'DUMP-BYTES');
        file_put_contents($src . '/manifest.json', '{"backup_id":"' . $id . '"');
        file_put_contents($this->legacyProbe . '/.htaccess', 'deny');

        App::settings()->set('files.storage_path', $this->tmp . '/files-base'); // جفت clinic-files غیرفعال
        App::settings()->set('backup.storage_path', $this->legacyProbe);

        $option = (string) (new ReflectionClass(App::class))->getConstant('PRIVATE_STORAGE_OPTION');
        $dest = ProtectedBackupStore::defaultBasePath() . '/' . $id;

        $run = static function () use ($option): void {
            delete_option($option); // هر بار مثل request تازهٔ مهاجرت‌نشده
            $m = new ReflectionMethod(App::class, 'ensurePrivateStorage');
            $m->setAccessible(true);
            $m->invoke(null);
        };
        $run();

        // مقصد سالم و بایت‌به‌بایت — مبدأ فقط پس از تأیید حذف شد
        self::assertFileExists($dest . '/db.sql');
        self::assertSame('DUMP-BYTES', file_get_contents($dest . '/db.sql'));
        self::assertFileDoesNotExist($src . '/db.sql', 'مبدأ باید پس از تأیید حذف شود.');
        self::assertFileExists($this->legacyProbe . '/.htaccess', 'گاردهای مبدأ سرجایشان می‌مانند.');

        // idempotent — اجرای دوم روی مبدأِ تخلیه‌شده هیچ چیز جدید جابه‌جا نمی‌کند
        $run();
        self::assertSame('DUMP-BYTES', file_get_contents($dest . '/db.sql'));
        self::assertFileDoesNotExist($src . '/db.sql');

        // Setting عمداً دست‌نخورده ماند (تصمیم اپراتور، نه سیستم) و همچنان
        // فقط‌خواندنی/Fail-Closed است
        self::assertSame($this->legacyProbe, App::backupService()->store()->basePath());
        self::assertTrue(App::backupService()->store()->isReadonly());
    }

    /**
     * تعارض: اگر مقصد از قبل فایلی با محتوای متفاوت داشته باشد، هیچ‌کدام از
     * دو طرف بازنویسی نمی‌شوند (قرارداد PrivateStorageMigrator) — اینجا روی
     * محتوای بکاپ‌شکل در مسیر مهاجرت OD-9.
     */
    public function testMigrationConflictNeverOverwritesAndSourceSurvives(): void
    {
        $id = 'cpms-backup-od9.conflict1';
        $src = $this->legacyProbe . '/' . $id;
        mkdir($src, 0777, true);
        file_put_contents($src . '/db.sql', 'SOURCE-TRUTH');

        $dst = $this->tmp . '/private-dst/' . $id;
        mkdir($dst, 0777, true);
        file_put_contents($dst . '/db.sql', 'DESTINATION-TRUTH');

        $report = (new PrivateStorageMigrator())->migrate($this->legacyProbe, $this->tmp . '/private-dst');

        self::assertSame(1, $report['conflict']);
        self::assertSame(0, $report['moved']);
        self::assertSame('SOURCE-TRUTH', file_get_contents($src . '/db.sql'), 'مبدأ دست‌نخورده.');
        self::assertSame('DESTINATION-TRUTH', file_get_contents($dst . '/db.sql'), 'مقصد بازنویسی نشد.');
        self::assertNotEmpty($report['errors']);
    }

    // ================= گزارش سلامت =================

    /**
     * ریشهٔ بکاپ داخل webroot از این پس FAIL گزارش می‌شود (پیکربندی
     * رد‌شده)، نه WARNING — و چون storage.backups جزو چک‌های حیاتی است،
     * میزبان UNSUPPORTED طبقه‌بندی می‌شود.
     */
    public function testHealthReportsInsideWebRootBackupRootAsFail(): void
    {
        mkdir($this->legacyProbe, 0777, true);

        $health = new SystemHealthService(
            App::db(),
            App::settings(),
            App::licenseService(),
            $this->service(ProtectedBackupStore::legacySource($this->legacyProbe)),
            App::updateService(),
            App::op()
        );

        $report = $health->run();
        $row = null;
        foreach ($report['checks'] as $check) {
            if ($check['key'] === 'storage.backups') {
                $row = $check;
            }
        }
        self::assertNotNull($row);
        self::assertSame(SystemHealthService::FAIL, $row['status']);
        self::assertSame(SystemHealthService::HOST_UNSUPPORTED, $report['host']['status']);
    }
}
