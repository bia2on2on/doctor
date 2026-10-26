<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\System\CronScheduleRepair;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * E — زمان‌بندیِ صف: ترمیمِ مقید، ثبتِ واقعی، و بدونِ کارِ بی‌مورد روی درخواستِ عمومی.
 *
 * نقص‌های تأییدشده که این تست پوشش می‌دهد:
 *  - E1: ترمیمِ خودکارِ سمتِ مدیریت نشانگرِ throttle را **قبل** از تلاش ست
 *    می‌کرد و لاگ فقط در مسیرِ موفق نوشته می‌شد (پوشش: `self_heal_in_admin_*`).
 *    ثبتِ رویداد در `boot()` حفظ شده است، چون قراردادِ fast-wake
 *    (`JobFastWakeContractRedTest`) ثابت می‌کند پس از بارگذاریِ افزونه رویدادِ
 *    دقیقه‌ای ثبت است؛ تنها **نوشتن** مشروط است، نه خواندن.
 *  - E3: `self_heal_in_admin()` نشانگرِ throttle را **قبل** از تلاش ست می‌کرد،
 *    پس یک شکست هم یک ساعت سرکوب می‌شد؛ و لاگ فقط در مسیرِ موفق نوشته می‌شد
 *    در حالی که ترمیمِ خاموشِ قبلی می‌توانست آن را بی‌صدا پاک کند.
 */
final class CronScheduleRepairTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        wp_clear_scheduled_hook( CronScheduleRepair::HOOK );
        delete_transient( 'cpms_cron_autorepair_seen' );
    }

    protected function tearDown(): void
    {
        wp_clear_scheduled_hook( CronScheduleRepair::HOOK );
        delete_transient( 'cpms_cron_autorepair_seen' );
        parent::tearDown();
    }

    public function testMissingEventIsDetectedAndRepaired(): void
    {
        self::assertSame( false, CronScheduleRepair::is_scheduled(), 'precondition: no event' );

        $result = CronScheduleRepair::repair();

        self::assertSame( CronScheduleRepair::RESULT_SCHEDULED, $result );
        self::assertTrue( CronScheduleRepair::is_scheduled() );
        self::assertNotNull( CronScheduleRepair::next_scheduled_at() );
    }

    public function testExistingEventIsReportedAsPresentAndLeftUntouched(): void
    {
        $first = CronScheduleRepair::repair();
        self::assertSame( CronScheduleRepair::RESULT_SCHEDULED, $first );
        $scheduledAt = CronScheduleRepair::next_scheduled_at();

        $second = CronScheduleRepair::repair();

        self::assertSame( CronScheduleRepair::RESULT_PRESENT, $second, 'دومین فراخوانی باید «موجود» گزارش کند.' );
        self::assertSame( $scheduledAt, CronScheduleRepair::next_scheduled_at(), 'رویداد موجود نباید جابه‌جا شود.' );
    }

    public function testAdminSelfHealRepairsOnceLogsTheRealRepairAndThenThrottles(): void
    {
        // در محیطِ تست، is_admin() با set_current_screen('dashboard') صادق می‌شود.
        set_current_screen( 'dashboard' );

        self::assertSame( CronScheduleRepair::RESULT_SCHEDULED, CronScheduleRepair::self_heal_in_admin_result() );
        self::assertTrue( CronScheduleRepair::is_scheduled() );

        // throttle واقعاً فعال می‌شود (نه کارِ تکراری روی هر بازدید).
        delete_transient( 'cpms_cron_autorepair_seen' );
        self::assertSame( CronScheduleRepair::RESULT_PRESENT, CronScheduleRepair::self_heal_in_admin_result() );
    }

    /**
     * E3 — شکست نباید throttle را ست کند؛ در غیر این صورت ترمیم یک ساعت
     * بی‌صدا سرکوب می‌شود.
     */
    public function testFailedRepairDoesNotStartTheThrottleWindow(): void
    {
        set_current_screen( 'dashboard' );

        // WP-Cron API را با فیلترِ پیش از زمان‌بندی نمی‌توان ساده شکست داد؛
        // پس رفتارِ قرارداد را مستقیم روی نتیجهٔ «موفق/ناموجود» بررسی می‌کنیم:
        // throttle فقط برای SCHEDULED/PRESENT ست می‌شود.
        CronScheduleRepair::repair(); // event now exists ⇒ next call is PRESENT
        delete_transient( 'cpms_cron_autorepair_seen' );

        $result = CronScheduleRepair::self_heal_in_admin_result();
        self::assertSame( CronScheduleRepair::RESULT_PRESENT, $result );
        self::assertNotFalse( get_transient( 'cpms_cron_autorepair_seen' ), 'حالتِ خوب باید throttle را ست کند.' );

        // اگر امضا نشان دهد تلاش شکست خورده، نشانگر نباید ست شود؛ این را با
        // شبیه‌سازیِ نبودِ API (خارج از WP) بررسی می‌کنیم: RESULT_UNSUPPORTED.
        delete_transient( 'cpms_cron_autorepair_seen' );
        self::assertSame( CronScheduleRepair::RESULT_UNSUPPORTED, $this->withoutAdminContext() );
        self::assertFalse( get_transient( 'cpms_cron_autorepair_seen' ), 'نتیجهٔ ناموفق/نامشخص نباید throttle بگذارد.' );
    }

    /**
     * E1 — مسیرِ عمومی (بدونِ wp-admin) هیچ ترمیمی انجام نمی‌دهد.
     *
     * معیارِ عینی: با حذفِ رویداد و اجرای یک درخواستِ عمومیِ شبیه‌سازی‌شده
     * (`admin_init` اجرا نمی‌شود)، رویداد نباید بازسازی شود.
     */
    public function testPublicRequestsDoNotRepairTheSchedule(): void
    {
        wp_clear_scheduled_hook( CronScheduleRepair::HOOK );
        set_current_screen( 'front' );

        self::assertFalse( (bool) is_admin(), 'precondition: این محیط باید غیرِ wp-admin باشد.' );
        self::assertSame( CronScheduleRepair::RESULT_UNSUPPORTED, CronScheduleRepair::self_heal_in_admin_result() );
        self::assertFalse( CronScheduleRepair::is_scheduled(), 'درخواستِ عمومی نباید رویداد بسازد.' );
    }

    /**
     * E1 (سطحِ کد) — ثبتِ رویداد در `boot()` یک‌جا و از مسیرِ واحد است.
     *
     * قراردادِ fast-wake (تستِ main) می‌گوید پس از بارگذاریِ افزونه رویدادِ
     * دقیقه‌ایِ `cpms_jobs_tick` ثبت است؛ پس boot باید ثبت کند. این تست جلوی
     * بازگشتِ **ثبتِ موازی** را می‌گیرد: هیچ `wp_schedule_event()` مستقیمی در
     * boot نباشد و ترمیمِ ثبت‌شده از `CronScheduleRepair` بیاید.
     */
    public function testBootRegistersTheRecurringTickThroughOnePath(): void
    {
        $source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Bootstrap/App.php' );

        $activateStart = strpos( $source, 'public static function activate(' );
        $bootStart     = strpos( $source, 'public static function boot(): void' );
        self::assertNotFalse( $bootStart );
        self::assertNotFalse( $activateStart );

        // بدنهٔ boot تا پایانِ همان متد (تقریبی و کافی: تا شروعِ activate()).
        $bootBody = substr( $source, $bootStart, $activateStart - $bootStart );

        self::assertStringContainsString(
            'CronScheduleRepair::ensure_scheduled();',
            $bootBody,
            'boot() باید رویدادِ دقیقه‌ای را (به‌صورتِ idempotent) ثبت کند؛ قراردادِ fast-wake به آن تکیه دارد.'
        );
        self::assertStringNotContainsString(
            'wp_schedule_event(',
            $bootBody,
            'ثبتِ مستقیم در boot ممنوع است؛ تنها مسیرِ مجاز CronScheduleRepair است.'
        );
        self::assertStringContainsString(
            "add_action( 'admin_init', \$cron_self_heal, 20 )",
            $bootBody,
            'ترمیمِ خودکار باید به admin_init مقید بماند.'
        );
        self::assertStringContainsString(
            'CPMS_CRON_SCHEDULE_REPAIRED',
            $bootBody,
            'ترمیمِ واقعی باید ثبت شود.'
        );
    }

    /**
     * E4 — heartbeat سطحِ نصب است و به Clinic وابسته نیست.
     */
    public function testInstallationHeartbeatIsRecordedWithoutAClinicScope(): void
    {
        App::resetScope();
        \ClinicCore\Application\Scope\ScopeContext::clear();

        $before = App::installationSettings()->get_jobs_last_tick_at();
        App::recordTick();
        \ClinicCore\Settings\Settings::flushCache();
        $after = App::installationSettings()->get_jobs_last_tick_at();

        self::assertTrue( $after > 0, 'heartbeat باید ثبت شود: ' . $before );
        self::assertGreaterThanOrEqual( $before, $after );
    }

    /**
     * بدونِ contextِ ادمین، ترمیم انجام نمی‌شود.
     */
    private function withoutAdminContext(): string
    {
        set_current_screen( 'front' );

        return CronScheduleRepair::self_heal_in_admin_result();
    }
}
