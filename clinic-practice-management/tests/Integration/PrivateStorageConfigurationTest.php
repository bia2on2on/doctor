<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Infrastructure\Storage\PrivateStorageLocation;
use ClinicCore\Infrastructure\Storage\PrivateStorageMigrator;
use ClinicCore\Infrastructure\Storage\StorageConfigurationException;
use WP_UnitTestCase;

/**
 * OD-7 — «Private clinical storage must remain outside the effective
 * web/document root.»
 *
 * این تست‌ها به `ABSPATH` واقعی نیاز دارند تا «داخل DocumentRoot» معنا داشته
 * باشد، پس Integration هستند نه Unit.
 */
final class PrivateStorageConfigurationTest extends WP_UnitTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/cpms-cfg-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        App::settings()->set('files.storage_path', '');
        $this->rmrf($this->tmp);
        $inside = rtrim((string) ABSPATH, '/') . '/wp-content/cpms-unsafe-probe';
        $this->rmrf($inside);
        $link = rtrim((string) ABSPATH, '/') . '/wp-content/cpms-unsafe-link';
        if (is_link($link)) {
            unlink($link);
        }
        parent::tearDown();
    }

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

    // ================= A — پیش‌فرض =================

    public function testDefaultClinicalStoragePathIsOutsideTheWebRoot(): void
    {
        $default = LocalFileStorage::defaultBasePath();

        self::assertFalse(
            PrivateStorageLocation::isInsideWebRoot($default),
            "مسیر پیش‌فرض نباید داخل DocumentRoot باشد: {$default}"
        );
        self::assertStringNotContainsString('/wp-content/', $default);
    }

    public function testDefaultStorageCanBeConstructedWithoutThrowing(): void
    {
        $storage = new LocalFileStorage(LocalFileStorage::defaultBasePath());

        self::assertSame(LocalFileStorage::defaultBasePath(), $storage->basePath());
        self::assertFalse($storage->isInsideWebRoot());
    }

    // ================= B — مسیر فعال =================

    public function testConfiguredPathOutsideTheWebRootIsAccepted(): void
    {
        App::settings()->set('files.storage_path', $this->tmp);

        $storage = App::localFileStorage();

        self::assertSame($this->tmp, $storage->basePath());
        self::assertFalse($storage->isInsideWebRoot());
    }

    public function testEffectiveActiveStoragePathIsOutsideTheWebRoot(): void
    {
        App::settings()->set('files.storage_path', '');

        self::assertFalse(App::localFileStorage()->isInsideWebRoot());
    }

    // ================= C — رد کردن Fail-Closed =================

    public function testConfiguredPathInsideTheWebRootIsRejected(): void
    {
        $inside = rtrim((string) ABSPATH, '/') . '/wp-content/cpms-unsafe-probe';
        mkdir($inside, 0777, true);
        App::settings()->set('files.storage_path', $inside);

        $this->expectException(StorageConfigurationException::class);
        App::localFileStorage();
    }

    public function testRejectionCarriesAnExplicitConfigurationErrorCode(): void
    {
        $inside = rtrim((string) ABSPATH, '/') . '/wp-content/cpms-unsafe-probe';
        mkdir($inside, 0777, true);

        try {
            new LocalFileStorage($inside);
            self::fail('باید استثنای پیکربندی پرتاب می‌شد.');
        } catch (StorageConfigurationException $e) {
            self::assertSame('CLINIC_STORAGE_INSIDE_WEBROOT', $e->errorCode);
            self::assertSame($inside, $e->path);
        }
    }

    /**
     * Symlinkی که بیرون به‌نظر می‌رسد ولی به داخل webroot می‌رسد باید رد شود —
     * مقایسه روی `realpath` انجام می‌شود، نه روی رشتهٔ خام.
     */
    public function testSymlinkResolvingInsideTheWebRootIsRejected(): void
    {
        $inside = rtrim((string) ABSPATH, '/') . '/wp-content/cpms-unsafe-probe';
        mkdir($inside, 0777, true);
        $link = $this->tmp . '/looks-outside';
        if (!@symlink($inside, $link)) {
            self::markTestSkipped('symlink در این محیط ممکن نیست.');
        }

        self::assertTrue(
            PrivateStorageLocation::isInsideWebRoot($link),
            'Symlink باید تا مقصد واقعی دنبال شود.'
        );
        $this->expectException(StorageConfigurationException::class);
        new LocalFileStorage($link);
    }

    /**
     * مهم‌ترین قید: نباید بی‌سروصدا به مسیر امن fallback کند. یک پیکربندی
     * ناامن باید **خطا** بدهد، نه اینکه با پیش‌فرض جایگزین شود و اپراتور فکر
     * کند تنظیماتش اعمال شده است.
     */
    public function testThereIsNoSilentFallbackToASafePath(): void
    {
        $inside = rtrim((string) ABSPATH, '/') . '/wp-content/cpms-unsafe-probe';
        mkdir($inside, 0777, true);
        App::settings()->set('files.storage_path', $inside);

        $thrown = null;
        $basePath = null;
        try {
            $basePath = App::localFileStorage()->basePath();
        } catch (StorageConfigurationException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'پیکربندی ناامن باید خطا بدهد.');
        self::assertNull($basePath, 'نباید هیچ مسیری برگردانده شود.');
        self::assertNotSame(
            LocalFileStorage::defaultBasePath(),
            $basePath,
            'نباید بی‌سروصدا به پیش‌فرض برگردد.'
        );
    }

    // ================= استثنای مهاجرت =================

    /**
     * مهاجرت باید بتواند مسیر قدیمیِ **داخل** webroot را به‌عنوان مبدأ
     * فقط‌خواندنی بخواند. این استثنا نباید آن مسیر را به ذخیره‌سازی فعال
     * تبدیل کند: مقصد بیرون است و ساخت یک Storage روی مبدأ همچنان رد می‌شود.
     */
    public function testLegacyInsideWebRootStillWorksAsAMigrationSourceOnly(): void
    {
        $legacy = rtrim((string) ABSPATH, '/') . '/wp-content/cpms-unsafe-probe';
        mkdir($legacy . '/1/ab', 0777, true);
        file_put_contents($legacy . '/1/ab/legacy.pdf', 'LEGACY-PHI');
        $private = $this->tmp . '/private';

        $report = (new PrivateStorageMigrator())->migrate($legacy, $private);

        self::assertSame(1, $report['moved'], 'مبدأ داخل webroot باید خوانده شود.');
        self::assertSame(0, $report['failed']);
        self::assertSame('LEGACY-PHI', file_get_contents($private . '/1/ab/legacy.pdf'));
        self::assertFileDoesNotExist($legacy . '/1/ab/legacy.pdf', 'پس از تأیید، مبدأ پاک می‌شود.');

        // ...ولی همان مسیر همچنان به‌عنوان ذخیره‌سازی فعال رد می‌شود.
        $this->expectException(StorageConfigurationException::class);
        new LocalFileStorage($legacy);
    }
}
