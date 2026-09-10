<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Storage\PrivateStorageLocation;
use ClinicCore\Infrastructure\Storage\PrivateStorageMigrator;
use PHPUnit\Framework\TestCase;

/**
 * OD-7 — انتقال ذخیره‌سازی بالینی به بیرون از DocumentRoot.
 *
 * تمرکز این تست‌ها روی قرارداد ایمنی است، نه روی «کار می‌کند یا نه»:
 * مبدأ نباید پیش از تأیید مقصد حذف شود، اجرای دوباره نباید کاری بکند، و
 * برخورد محتوا نباید داده را بازنویسی کند.
 */
final class PrivateStorageMigrationTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/cpms-od7-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmp);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        @rmdir($path);
    }

    private function put(string $absolute, string $content): void
    {
        $dir = dirname($absolute);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($absolute, $content);
    }

    // ================= مسیر پیش‌فرض =================

    public function testPrivateRootHonoursTheConstantFirst(): void
    {
        // ثابت در این فرایند تعریف نشده است، پس باید به مشتق‌گیری از مسیر برسیم.
        self::assertFalse(
            defined(PrivateStorageLocation::CONSTANT),
            'پیش‌شرط: ثابت نباید در تست تعریف شده باشد.'
        );
        self::assertStringEndsWith('/cpms-private', PrivateStorageLocation::root());
    }

    public function testNamedPathsSitUnderThePrivateRoot(): void
    {
        self::assertSame(
            PrivateStorageLocation::root() . '/clinic-files',
            PrivateStorageLocation::path('clinic-files')
        );
        self::assertSame(
            PrivateStorageLocation::root() . '/cpms-backups',
            PrivateStorageLocation::path('cpms-backups')
        );
    }

    // ================= مهاجرت =================

    public function testMigrationMovesFilesAndPreservesNestedStructure(): void
    {
        $legacy = $this->tmp . '/legacy';
        $private = $this->tmp . '/private';
        $this->put($legacy . '/1/ab/deadbeef.pdf', 'PDF-A');
        $this->put($legacy . '/2/cd/cafebabe.jpg', 'JPG-B');

        $report = (new PrivateStorageMigrator())->migrate($legacy, $private);

        self::assertSame(2, $report['moved']);
        self::assertSame(0, $report['failed']);
        self::assertSame(0, $report['conflict']);
        self::assertSame('PDF-A', file_get_contents($private . '/1/ab/deadbeef.pdf'));
        self::assertSame('JPG-B', file_get_contents($private . '/2/cd/cafebabe.jpg'));
        self::assertFileDoesNotExist($legacy . '/1/ab/deadbeef.pdf', 'مبدأ باید پس از تأیید حذف شود.');
    }

    public function testMigrationIsIdempotent(): void
    {
        $legacy = $this->tmp . '/legacy';
        $private = $this->tmp . '/private';
        $this->put($legacy . '/1/ab/file.pdf', 'X');

        $migrator = new PrivateStorageMigrator();
        $first = $migrator->migrate($legacy, $private);
        $second = $migrator->migrate($legacy, $private);

        self::assertSame(1, $first['moved']);
        self::assertSame(0, $second['moved'], 'اجرای دوم نباید چیزی جابه‌جا کند.');
        self::assertSame(0, $second['failed']);
        self::assertSame('X', file_get_contents($private . '/1/ab/file.pdf'));
    }

    public function testAlreadyPresentDestinationWithSameContentRemovesTheSource(): void
    {
        $legacy = $this->tmp . '/legacy';
        $private = $this->tmp . '/private';
        $this->put($legacy . '/1/ab/file.pdf', 'SAME');
        $this->put($private . '/1/ab/file.pdf', 'SAME');

        $report = (new PrivateStorageMigrator())->migrate($legacy, $private);

        self::assertSame(1, $report['already']);
        self::assertSame(0, $report['moved']);
        self::assertFileDoesNotExist($legacy . '/1/ab/file.pdf');
        self::assertSame('SAME', file_get_contents($private . '/1/ab/file.pdf'));
    }

    /**
     * مهم‌ترین قید: هرگز داده‌ی ناهمخوان بازنویسی نشود و مبدأ حذف نشود.
     */
    public function testConflictingDestinationIsNeverOverwrittenAndSourceSurvives(): void
    {
        $legacy = $this->tmp . '/legacy';
        $private = $this->tmp . '/private';
        $this->put($legacy . '/1/ab/file.pdf', 'SOURCE');
        $this->put($private . '/1/ab/file.pdf', 'DIFFERENT');

        $report = (new PrivateStorageMigrator())->migrate($legacy, $private);

        self::assertSame(1, $report['conflict']);
        self::assertSame(0, $report['moved']);
        self::assertSame(0, $report['already']);
        self::assertSame('SOURCE', file_get_contents($legacy . '/1/ab/file.pdf'), 'مبدأ باید دست‌نخورده بماند.');
        self::assertSame('DIFFERENT', file_get_contents($private . '/1/ab/file.pdf'), 'مقصد نباید بازنویسی شود.');
        self::assertNotEmpty($report['errors']);
    }

    public function testGuardFilesAreNotMigrated(): void
    {
        $legacy = $this->tmp . '/legacy';
        $private = $this->tmp . '/private';
        $this->put($legacy . '/.htaccess', 'deny');
        $this->put($legacy . '/index.php', '<?php');
        $this->put($legacy . '/web.config', '<xml/>');
        $this->put($legacy . '/README-SECURITY.txt', 'note');
        $this->put($legacy . '/1/ab/real.pdf', 'REAL');

        $report = (new PrivateStorageMigrator())->migrate($legacy, $private);

        self::assertSame(1, $report['moved'], 'فقط فایل واقعی باید منتقل شود.');
        self::assertFileExists($legacy . '/.htaccess', 'گاردهای مبدأ باید سرجایشان بمانند.');
        self::assertFileDoesNotExist($private . '/.htaccess');
    }

    public function testMissingLegacyDirectoryIsANoOpNotAnError(): void
    {
        $report = (new PrivateStorageMigrator())->migrate($this->tmp . '/nope', $this->tmp . '/private');

        self::assertSame('no_legacy_dir', $report['skipped_reason']);
        self::assertSame(0, $report['failed']);
        self::assertSame(0, $report['moved']);
    }

    public function testIdenticalSourceAndDestinationIsRefused(): void
    {
        $legacy = $this->tmp . '/same';
        mkdir($legacy, 0777, true);

        $report = (new PrivateStorageMigrator())->migrate($legacy, $legacy);

        self::assertSame('same_or_empty_path', $report['skipped_reason']);
        self::assertSame(0, $report['moved']);
    }

    public function testNoPartialFilesAreLeftBehindOnSuccess(): void
    {
        $legacy = $this->tmp . '/legacy';
        $private = $this->tmp . '/private';
        $this->put($legacy . '/1/ab/file.pdf', str_repeat('A', 4096));

        (new PrivateStorageMigrator())->migrate($legacy, $private);

        self::assertFileDoesNotExist($private . '/1/ab/file.pdf.part', 'فایل موقت نباید باقی بماند.');
        self::assertSame(4096, filesize($private . '/1/ab/file.pdf'));
    }
}
