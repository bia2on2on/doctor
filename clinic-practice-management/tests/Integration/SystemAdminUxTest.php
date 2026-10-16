<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\SystemPage;
use ClinicCore\Application\System\SystemHealthService;
use WP_UnitTestCase;

/**
 * Chunk E — License/Backup/Update/Health (صفحهٔ «سلامت سیستم»؛ قابل‌فهم و امن).
 *
 * پوشش:
 *  - راهنمای انسانی هر fault («چه / اثر / چه کنم») به‌ازای کلید/وضعیت — خالص و بدون WP/DB.
 *  - رندر Health به‌صورت کارت خطا (برای غیر-PASS) + جزئیات فنی جمع‌شونده؛ در عین حفظ
 *    سرصفحهٔ موجود («وضعیت Health / سازگاری میزبان») و عدم رگرسیون (SystemAdminPagesTest).
 *  - Restore با preflight + تأیید صریح (چک‌باکس + تایپ RESTORE + اشاره به Safety Backup)
 *    در صفحه رندر می‌شود (بک‌اند preflight/audit از BackupService موجود است).
 */
final class SystemAdminUxTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testGuideReturnsReadableSectionsForKnownFault(): void
    {
        $g = SystemPage::guide('php.version', SystemHealthService::FAIL);

        $this->assertArrayHasKey('what', $g);
        $this->assertArrayHasKey('impact', $g);
        $this->assertArrayHasKey('action', $g);
        $this->assertNotSame('', trim($g['what']), 'چه');
        $this->assertNotSame('', trim($g['impact']), 'اثر');
        $this->assertNotSame('', trim($g['action']), 'چه کنم');
    }

    public function testGuideReturnsActionableAdviceForDatabaseFailure(): void
    {
        $g = SystemPage::guide('db.reachable', SystemHealthService::FAIL);

        $this->assertStringContainsString('اتصال به دیتابیس', $g['what']);
        $this->assertStringContainsString('دسترس', $g['impact']); // «در دسترس نخواهد بود»
        $this->assertStringContainsString('wp-config', $g['action']);
    }

    /**
     * نصبِ وردپرسِ کهنه: صفحهٔ سیستم باید راهنمایِ فارسیِ ارتقا نشان دهد و
     * صریح بگوید داده‌ها در دسترس می‌مانند (بدون قفل/دسترسی‌زدایی).
     */
    public function testGuideGivesPersianUpgradePathForOutdatedWordPress(): void
    {
        $g = SystemPage::guide('wp.version', SystemHealthService::FAIL);

        $this->assertStringContainsString('وردپرس', $g['what']);
        $this->assertStringContainsString('به‌روزرسانی', $g['action']);
        $this->assertStringContainsString('بکاپ', $g['action']);
        $this->assertStringContainsString('wp core update', $g['action']);
        $this->assertNotSame('', trim($g['impact']));
    }

    public function testGuideReturnsHealthyForPass(): void
    {
        $g = SystemPage::guide('php.version', SystemHealthService::PASS);

        $this->assertStringContainsString('سالم است', $g['what']);
        $this->assertStringContainsString('بدون اقدام', $g['action']);
    }

    public function testGuideFallsBackForUnknownKey(): void
    {
        $g = SystemPage::guide('totally.unknown.key', SystemHealthService::WARNING);
        $this->assertNotSame('', trim($g['what']));
        $this->assertNotSame('', trim($g['impact']));
        $this->assertNotSame('', trim($g['action']));
    }

    public function testRenderHealthAsFaultCardsWithCollapsibleDetails(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('وضعیت Health / سازگاری میزبان', $html, 'سرصفحهٔ Health حفظ شود');
        $this->assertStringContainsString('چی:', $html, 'کارت خطا باید «چه» داشته باشد');
        $this->assertStringContainsString('اثر:', $html, 'کارت خطا باید «اثر» داشته باشد');
        $this->assertStringContainsString('چه کنم:', $html, 'کارت خطا باید «چه کنم» داشته باشد');
        $this->assertStringContainsString('جزئیات فنی', $html, 'جزئیات فنی به‌صورت جمع‌شونده باشد');
    }

    public function testRestoreFormShowsPreflightAndHardenedConfirmation(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('cpms_restore_preflight', $html, 'فرم Preflight بازیابی وجود داشته باشد');
        $this->assertStringContainsString('Safety Backup', $html, 'اشاره به Safety Backup شود');
        $this->assertStringContainsString('confirm_text', $html, 'تایپ عبارت تأیید الزامی باشد');
        $this->assertStringContainsString('cpms_restore_apply', $html, 'فرم Restore وجود داشته باشد');
    }

    public function testRenderedMariaDbUncertifiedRowShowsWarningInRowAndBanner(): void
    {
        $health = [
            'checks' => [
                ['key' => 'php.version', 'label' => 'PHP نسخه', 'status' => SystemHealthService::PASS, 'detail' => '8.3'],
                ['key' => 'db.mysql_version', 'label' => 'نسخهٔ سرور دیتابیس (MySQL)', 'status' => SystemHealthService::UNKNOWN, 'detail' => 'mariadb:detected | MariaDB شناسایی شد — کفِ اعلام‌شده فقط MySQL 8.0 است و سیاستِ MariaDB اعلام نشده است'],
            ],
            'host' => ['status' => SystemHealthService::HOST_SUPPORTED_WITH_WARNINGS, 'issues' => ['db.mysql_version']],
        ];

        $html = $this->renderWith($health);

        $this->assertStringContainsString('نیازمند تنظیم', $html, 'MariaDBِ تشخیص‌داده‌شده باید «نیازمند تنظیم» باشد.');
        $this->assertStringContainsString('MariaDB شناسایی شد', $html, 'راهنما باید MariaDB را گزارش کند وقتی مدرک موجود است.');
        $this->assertStringNotContainsString('مشکل ضروری', $html, 'MariaDBِ تشخیص‌داده‌شده نباید «مشکل ضروری» باشد.');
    }

    public function testRenderedGenuinelyUnknownDatabaseShowsCriticalAndNoFalseMariaDbClaim(): void
    {
        $health = [
            'checks' => [
                ['key' => 'php.version', 'label' => 'PHP نسخه', 'status' => SystemHealthService::PASS, 'detail' => '8.3'],
                ['key' => 'db.mysql_version', 'label' => 'نسخهٔ سرور دیتابیس (MySQL)', 'status' => SystemHealthService::UNKNOWN, 'detail' => 'نسخهٔ سرور دیتابیس در دسترس نیست یا قابل‌تشخیص نیست — برآورده‌شدنِ کفِ MySQL 8.0 تأییدپذیر نیست'],
            ],
            'host' => ['status' => SystemHealthService::HOST_UNSUPPORTED, 'issues' => ['db.mysql_version: نسخهٔ سرور دیتابیس در دسترس نیست یا قابل‌تشخیص نیست — برآورده‌شدنِ کفِ MySQL 8.0 تأییدپذیر نیست']],
        ];

        $html = $this->renderWith($health);

        $this->assertStringContainsString('مشکل ضروری', $html, 'موتورِ ناشناخته باید «مشکل ضروری» باشد.');
        $this->assertStringNotContainsString('MariaDB شناسایی شد', $html, 'بدون مدرکِ MariaDB نباید ادعای MariaDB شود.');
    }

    private function renderWith(array $health): string
    {
        ob_start();
        try {
            SystemPage::render($health);
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }

    private function render(): string
    {
        ob_start();
        try {
            SystemPage::render();
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }
}
