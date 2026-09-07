<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\SystemPage;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use WP_UnitTestCase;

/**
 * رگرسیون نقص‌های گزارش‌شدهٔ نصب واقعی (Hotfix 2026-09-07):
 *
 *  D1) Tools → «CPMS (سیستم)» روی WordPress واقعی → Critical Error.
 *      ریشهٔ بازتولیدشده: شکست IO در مخزن بکاپ (پوشهٔ قابل‌نوشتن نیست →
 *      BackupException) کل صفحهٔ وضعیت را می‌کشاند؛ چون SystemPage::render()
 *      فراخوانی سرویس‌ها را بدون guard انجام می‌داد. این تست همان شکست را با
 *      ناممکن‌کردن ساخت پوشه (فایل به‌جای دایرکتوری) بازتولید می‌کند و انتظار
 *      دارد صفحه همچنان با هشدار درون‌صفحه‌ای رندر شود — نه Fatal.
 *
 *  نکتهٔ D2 (شمارش جداول): در WP Test Suite جدول‌ها TEMPORARY می‌شوند و در
 *  information_schema دیده نمی‌شوند — پس معیار قطعی «UI == DB» را گیت
 *  Real-WordPress-Acceptance روی MySQL واقعی با دو prefix قفل می‌کند.
 */
final class SystemAdminPagesTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function backupBasePath(): string
    {
        return ProtectedBackupStore::defaultBasePath();
    }

    protected function tearDown(): void
    {
        $base = $this->backupBasePath();
        if (is_file($base)) {
            unlink($base);
        }
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testSystemPageRendersHealthSectionWithoutException(): void
    {
        $base = $this->backupBasePath();
        $this->assertTrue(!is_file($base), 'پیش‌شرط تست: مسیر بکاپ دست‌کاری‌شده نباشد');

        $html = $this->renderPage();

        $this->assertStringContainsString('وضعیت Health / سازگاری میزبان', $html);
        $this->assertStringNotContainsString('wp-die-message', $html);
        $this->assertStringNotContainsString('بخش Health با خطا مواجه شد', $html);
    }

    public function testSystemPageSurvivesUnwritableBackupStore(): void
    {
        $base = $this->backupBasePath();
        if (is_dir($base)) {
            // پاک‌سازی محتوای احتمالی تولیدشده توسط تست‌های دیگر در همین process
            $this->removeDir($base);
        }
        // شبیه‌سازی میزبان واقعی: مسیر بکاپ «فایل» است → mkdir همیشه شکست می‌خورد
        $this->assertTrue(file_put_contents($base, 'block') !== false);
        $this->assertTrue(is_file($base));

        $html = null;
        $thrown = null;
        try {
            $html = $this->renderPage();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNull($thrown, 'D1: شکست مخزن بکاپ نباید صفحه را Fatal کند: ' . ($thrown ? $thrown->getMessage() : ''));
        $this->assertIsString($html);
        $this->assertStringContainsString('وضعیت Health / سازگاری میزبان', $html, 'بخش‌های سالم باید رندر شوند');
        $this->assertStringContainsString('فهرست بکاپ‌ها قابل خواندن نیست', $html, 'خطای بخش بکاپ باید درون‌صفحه‌ای نمایش داده شود');
    }

    private function renderPage(): string
    {
        ob_start();
        try {
            SystemPage::render();
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }

    private function removeDir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
