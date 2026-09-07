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
        $this->assertStringContainsString('رسان', $g['impact']); // e.g. «برقرار نیست/در دسترس نیست»
        $this->assertStringContainsString('wp-config', $g['action']);
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
