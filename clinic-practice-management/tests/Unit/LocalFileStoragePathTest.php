<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1A — Item 5 (File Security).
 *
 * محدودسازی مسیر: هیچ ورودی‌ای نباید بتواند خواندن/حذف را از ریشهٔ
 * ذخیره‌سازی بیرون ببرد. امروز `storage_path` را خود store() می‌سازد و از
 * کاربر نمی‌آید، پس این یک بهره‌برداری اثبات‌شده نبود؛ اما تنها مانعِ
 * تبدیل «یک ستون دیتابیس» به «خواندن هر فایل روی سرور» همان فرض بود.
 */
final class LocalFileStoragePathTest extends TestCase
{
    private string $root;
    private LocalFileStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cpms-fs-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/1/ab', 0750, true);
        file_put_contents($this->root . '/1/ab/abcd.pdf', 'inside');
        file_put_contents(dirname($this->root) . '/cpms-outside-' . basename($this->root) . '.txt', 'outside');
        $this->storage = new LocalFileStorage($this->root);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $entry) {
                $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->root);
        }
        @unlink(dirname($this->root) . '/cpms-outside-' . basename($this->root) . '.txt');
        parent::tearDown();
    }

    public function testLegitimateRelativePathResolves(): void
    {
        self::assertSame('inside', $this->storage->read('1/ab/abcd.pdf'));
    }

    /**
     * حمله: پیمایش مسیر در همهٔ شکل‌های رایج.
     */
    public function testTraversalAttemptsAreRejected(): void
    {
        $outside = 'cpms-outside-' . basename($this->root) . '.txt';
        $payloads = [
            '../' . $outside,
            '../../etc/passwd',
            '1/ab/../../../' . $outside,
            './../' . $outside,
            '1/../../' . $outside,
            '....//....//' . $outside,
            '/etc/passwd',
            '..\\..\\' . $outside,
        ];

        foreach ($payloads as $payload) {
            self::assertNull(
                $this->storage->absolutePath($payload),
                'مسیر باید رد می‌شد: ' . $payload
            );
            self::assertNull($this->storage->read($payload), 'خواندن باید رد می‌شد: ' . $payload);
            self::assertFalse($this->storage->delete($payload), 'حذف باید رد می‌شد: ' . $payload);
        }

        self::assertFileExists(
            dirname($this->root) . '/' . $outside,
            'فایل بیرون از ریشه نباید حذف شده باشد.'
        );
    }

    public function testEmptyAndNullByteAreRejected(): void
    {
        self::assertNull($this->storage->absolutePath(''));
        self::assertNull($this->storage->absolutePath('/'));
        self::assertNull($this->storage->absolutePath("1/ab/abcd.pdf\0.png"));
    }

    /**
     * Symlink ای که به بیرون از ریشه اشاره کند نباید دنبال شود.
     */
    public function testSymlinkEscapingRootIsRejected(): void
    {
        $target = dirname($this->root) . '/cpms-outside-' . basename($this->root) . '.txt';
        $link = $this->root . '/1/ab/escape.pdf';
        if (!@symlink($target, $link)) {
            self::markTestSkipped('این محیط اجازهٔ ساخت symlink نمی‌دهد.');
        }

        try {
            self::assertNull($this->storage->absolutePath('1/ab/escape.pdf'));
            self::assertNull($this->storage->read('1/ab/escape.pdf'));
        } finally {
            @unlink($link);
        }
    }

    /**
     * فایل تولیدشده توسط store() باید همیشه داخل ریشه و با نام تصادفی باشد.
     */
    public function testStoredFileStaysInsideRootWithRandomName(): void
    {
        $relative = $this->storage->store('payload', 1, 'pdf');

        self::assertMatchesRegularExpression('#^1/[0-9a-f]{2}/[0-9a-f]{32}\.pdf$#', $relative);
        $absolute = $this->storage->absolutePath($relative);
        self::assertNotNull($absolute);
        self::assertStringStartsWith($this->root, (string) $absolute);
        self::assertSame('payload', $this->storage->read($relative));

        $this->storage->delete($relative);
    }

    /**
     * گاردهای وب‌سرور باید نوشته شوند — و صرفِ .htaccess کافی دانسته نشود.
     */
    public function testServerGuardsAreWrittenIncludingNonApache(): void
    {
        $this->storage->store('x', 2, 'pdf');

        self::assertFileExists($this->root . '/.htaccess');
        self::assertFileExists($this->root . '/index.php');
        self::assertFileExists($this->root . '/web.config', 'گارد IIS نوشته نشد.');
        self::assertFileExists($this->root . '/README-SECURITY.txt', 'یادداشت nginx نوشته نشد.');
        self::assertStringContainsString(
            'nginx',
            (string) file_get_contents($this->root . '/README-SECURITY.txt')
        );
    }
}
