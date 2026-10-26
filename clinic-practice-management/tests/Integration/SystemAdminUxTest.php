<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\SystemPage;
use ClinicCore\Application\System\SystemHealthService;
use ClinicCore\Bootstrap\App;
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

    /**
     * D — ردیفِ MariaDBِ آزموده‌نشده حالا **مسدودکننده** است (نه «نیازمند تنظیم»).
     *
     * دلیل: دروازهٔ واقعیِ مهاجرت (migration 0027) همان نسخه را fail-closed رد
     * می‌کند، پس نمایشِ «هشدار/نیازمند تنظیم» به اپراتور می‌گفت مشکل کوچکی است
     * در حالی که فعال‌سازی روی آن سرور شکست می‌خورد.
     */
    public function testRenderedUntestedMariaDbRowIsABlockerWithMigrationGateGuidance(): void
    {
        $health = [
            'checks' => [
                ['key' => 'php.version', 'label' => 'PHP نسخه', 'status' => SystemHealthService::PASS, 'detail' => '8.3'],
                ['key' => 'db.mysql_version', 'label' => 'نسخهٔ سرور دیتابیس (MariaDB)', 'status' => SystemHealthService::FAIL, 'detail' => 'mariadb:unverified | 11.5.2-MariaDB — آزموده‌نشده'],
            ],
            'host' => ['status' => SystemHealthService::HOST_UNSUPPORTED, 'issues' => ['db.mysql_version']],
        ];

        $html = $this->renderWith($health);

        $this->assertStringContainsString('مسدودکننده', $html, 'ردیف باید در گروهِ مسدودکننده‌ها باشد.');
        $this->assertStringContainsString('مشکل ضروری', $html, 'وضعیت ردیف باید «مشکل ضروری» باشد.');
        $this->assertStringContainsString('migration 0027', $html, 'راهنما باید به دروازهٔ واقعیِ مهاجرت اشاره کند.');
        $this->assertStringContainsString('11.4.13', $html, 'نسخهٔ آزموده‌شده باید در راهنما بیاید.');
    }

    /**
     * C — ریشهٔ ذخیره‌سازیِ ناموجود «اختیاری» نیست و کنترلِ راه‌اندازی وجود دارد.
     */
    public function testMissingStorageIsABlockerAndTheInitializationControlExists(): void
    {
        $health = [
            'checks' => [
                ['key' => 'php.version', 'label' => 'PHP نسخه', 'status' => SystemHealthService::PASS, 'detail' => '8.3'],
                ['key' => 'storage.files', 'label' => 'Storage فایل‌های پزشکی', 'status' => SystemHealthService::FAIL, 'detail' => 'ریشهٔ ذخیره‌سازی وجود ندارد: /srv/cpms-private/clinic-files'],
            ],
            'host' => ['status' => SystemHealthService::HOST_UNSUPPORTED, 'issues' => ['storage.files']],
        ];

        $html = $this->renderWith($health);

        $this->assertStringContainsString('مسدودکننده', $html);
        $this->assertStringNotContainsString('پیکربندی اختیاری', $html, 'ریشهٔ الزامیِ ذخیره‌سازی نباید در گروهِ اختیاری بیاید.');
        // کنترلی که راهنما به آن ارجاع می‌دهد باید واقعاً رندر شود.
        $this->assertStringContainsString('value="cpms_storage_init"', $html, 'فرم راه‌اندازی ذخیره‌سازی باید در صفحه باشد.');
        $this->assertStringContainsString('cpms_storage_init', $html);
    }

    /**
     * C — راهنمای ریشهٔ ذخیره‌سازی باید الزامی‌بودن را بگوید و به کنترلِ واقعیِ
     * همین صفحه ارجاع دهد؛ «wp-content عمومی» هرگز پیشنهاد نمی‌شود.
     */
    public function testGuideForStorageRootsIsMandatoryAndPointsAtTheRealControl(): void
    {
        foreach (['storage.files', 'storage.backups'] as $key) {
            $g = SystemPage::guide($key, SystemHealthService::FAIL);

            $this->assertStringContainsString('الزامی', $g['what'], 'ریشهٔ ذخیره‌سازی الزامی است: ' . $key);
            $this->assertStringContainsString('راه‌اندازی ریشهٔ ذخیره‌سازی', $g['action'], 'راهنما باید به دکمهٔ همین صفحه ارجاع دهد: ' . $key);
            $this->assertStringContainsString('بیرون از DocumentRoot', $g['action'], 'راهنما باید شرطِ مسیر را بگوید: ' . $key);
            $this->assertStringNotContainsString('chmod', $g['action'], 'راهنما نباید بازکردنِ مجوز را پیشنهاد کند: ' . $key);
            $this->assertStringContainsString('داده', $g['impact']);
        }

        $files = SystemPage::guide('storage.files', SystemHealthService::FAIL);
        $this->assertStringContainsString('CPMS_PRIVATE_STORAGE_DIR', $files['action'], 'مسیرِ جایگزینِ صریح باید ذکر شود.');
        $this->assertStringContainsString('wp-content', $files['action'], 'راهنما باید هشدارِ استفاده از پوشهٔ عمومی را بدهد.');
        $this->assertStringContainsString('هرگز', $files['action']);
    }

    public function testStorageInitializationHandlerIsRegisteredOnlyAsAnAdminPostAction(): void
    {
        SystemPage::register();

        $this->assertNotFalse(has_action('admin_post_cpms_storage_init'), 'هندلر باید ثبت شده باشد.');
        $this->assertFalse(
            has_action('admin_post_nopriv_cpms_storage_init'),
            'کاربرِ مهمان هرگز نباید بتواند ریشهٔ ذخیره‌سازی را راه‌اندازی کند.'
        );
    }

    public function testStorageInitializationWithoutNonceIsRejectedAndCreatesNothing(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $before = $this->storedFileCount();

        $_POST = ['action' => 'cpms_storage_init'];

        $this->assertWpDiedWith('اعتبارسنجی ناموفق', static function (): void {
            SystemPage::storage_init();
        });

        $_POST = [];
        $this->assertSame($before, $this->storedFileCount(), 'درخواستِ بی‌Nonce نباید هیچ ردیفی بسازد.');
    }

    public function testStorageInitializationWithoutCapabilityIsRejected(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $_POST = [
            'action'   => 'cpms_storage_init',
            '_wpnonce' => wp_create_nonce('cpms_storage_init'),
        ];

        $this->assertWpDiedWith('اعتبارسنجی ناموفق', static function (): void {
            SystemPage::storage_init();
        });

        $_POST = [];
    }

    /**
     * فراخوانیِ handler باید با wp_die متوقف شود؛ چون در محیطِ تست مسیرِ موفق
     * به `exit` می‌رسد، فقط مسیرهای ردشده بررسی می‌شوند.
     */
    private function assertWpDiedWith(string $needle, callable $run): void
    {
        $message = '';
        $handler = static function ($msg) use (&$message): void {
            $message = is_string($msg) ? $msg : '';
            throw new \RuntimeException('wp_die: ' . $message);
        };
        $filter = static fn () => $handler;

        add_filter('wp_die_handler', $filter);
        try {
            $run();
            $this->fail('انتظارِ wp_die می‌رفت.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wp_die', $e->getMessage());
            $this->assertStringContainsString($needle, $message);
        } finally {
            remove_filter('wp_die_handler', $filter);
        }
    }

    private function storedFileCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . App::db()->table('cpms_medical_attachments') . '`');
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
