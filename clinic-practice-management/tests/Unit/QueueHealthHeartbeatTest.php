<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\System\CronScheduleRepair;
use ClinicCore\Application\System\QueueDiagnosis;
use ClinicCore\Application\System\SystemHealthService;
use ClinicCore\Settings\InstallationSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * CPMS Classic Admin UX (E) — heartbeat سطح نصب، تشخیصِ صف و ترمیمِ زمان‌بندی.
 *
 * ریشهٔ نقص‌هایی که این تست‌ها تثبیت می‌کنند:
 *  ۱. heartbeat صف فقط Settingِ per-Clinic بود ⇒ در نصبِ چند-Clinic کارگرِ
 *     سیستم بدونِ ClinicScope شکست می‌خورد و صفِ سالم «متوقف» گزارش می‌شد.
 *  ۲. «نصبِ تازه»، «رویدادِ زمان‌بندیِ غایب»، «اجرای کهنه» و «Job شکست‌خورده»
 *     از هم تفکیک نمی‌شدند.
 *  ۳. WP-Cronِ سالمِ متکی به ترافیک به‌عنوانِ خطای بحرانی برچسب می‌خورد.
 *  ۴. ترمیمی برای رویدادِ زمان‌بندیِ حذف‌شده وجود نداشت (و اگر اضافه می‌شد
 *     نباید هیچ Jobی را فوراً اجرا می‌کرد).
 */
final class QueueHealthHeartbeatTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store = [];

    private function installation_settings(): InstallationSettings
    {
        return new InstallationSettings(
            function (string $key, mixed $default): mixed {
                return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
            },
            function (string $key, mixed $value): void {
                $this->store[$key] = $value;
            }
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function evidence( array $overrides = [] ): array
    {
        return array_merge(
            [
                'last_tick_at'       => 1_000_000,
                'heartbeat_source'   => 'installation',
                'now'                => 1_000_000 + 30,
                'stale_after_sec'    => 300,
                'failed'             => 0,
                'queued'             => 0,
                'has_activity'       => false,
                'schedule_scheduled' => true,
                'next_scheduled_at'  => 1_000_060,
                'wp_cron_disabled'   => false,
            ],
            $overrides
        );
    }

    // ============ ۱. heartbeat سطح نصب ============

    public function testInstallationHeartbeatIsScopeNeutralAndDefaultsToNever(): void
    {
        $settings = new InstallationSettings();

        self::assertSame( 0, $settings->get_jobs_last_tick_at(), 'بدونِ Option، heartbeat «هرگز» (0) است.' );
    }

    public function testInstallationHeartbeatRoundTripsInANonAutoloadedOption(): void
    {
        $settings = $this->installation_settings();
        $settings->set_jobs_last_tick_at( 1_700_000_000 );

        self::assertArrayHasKey( InstallationSettings::OPTION_JOBS_LAST_TICK_AT, $this->store );
        self::assertSame( 1_700_000_000, $this->store[ InstallationSettings::OPTION_JOBS_LAST_TICK_AT ] );
        self::assertSame( 1_700_000_000, $settings->get_jobs_last_tick_at() );
    }

    public function testCorruptOrNegativeHeartbeatFallsBackToNever(): void
    {
        $settings = $this->installation_settings();

        $this->store[ InstallationSettings::OPTION_JOBS_LAST_TICK_AT ] = 'not-a-number';
        self::assertSame( 0, $settings->get_jobs_last_tick_at() );

        $this->store[ InstallationSettings::OPTION_JOBS_LAST_TICK_AT ] = -5;
        self::assertSame( 0, $settings->get_jobs_last_tick_at(), 'مقدارِ منفی نباید heartbeat معتبر تلقی شود.' );

        $this->expectException( InvalidArgumentException::class );
        $settings->set_jobs_last_tick_at( -1 );
    }

    // ============ ۲. تشخیصِ صف ============

    public function testHealthyTrafficDrivenWpCronIsNotACriticalFailure(): void
    {
        $result = QueueDiagnosis::classify( self::evidence() );

        self::assertSame( QueueDiagnosis::STATE_HEALTHY, $result['state'] );
        self::assertSame( QueueDiagnosis::SEVERITY_OK, $result['severity'] );
        self::assertSame( SystemHealthService::PASS, $result['status'], 'WP-Cronِ سالم نباید FAIL شود.' );
        self::assertFalse( QueueDiagnosis::severity_is_blocking( $result['severity'] ) );
        self::assertStringContainsString( 'ترافیک', $result['detail'], 'وابستگی به ترافیک باید صادقانه توضیح داده شود.' );
    }

    public function testHealthySystemCronExplainsTheExternalScheduler(): void
    {
        $result = QueueDiagnosis::classify( self::evidence( [ 'wp_cron_disabled' => true ] ) );

        self::assertSame( QueueDiagnosis::STATE_HEALTHY, $result['state'] );
        self::assertStringContainsString( 'System Cron', $result['detail'] );
        self::assertStringContainsString( 'System Cron', $result['guidance'] );
    }

    public function testFreshInstallationIsNotReportedAsAStoppedQueue(): void
    {
        $result = QueueDiagnosis::classify(
            self::evidence( [
                'last_tick_at'     => 0,
                'heartbeat_source' => 'none',
                'has_activity'     => false,
            ] )
        );

        self::assertSame( QueueDiagnosis::STATE_NEVER_RUN, $result['state'] );
        self::assertSame( QueueDiagnosis::SEVERITY_INFO, $result['severity'], 'نصبِ تازه هشدار/بحرانی نیست.' );
        self::assertSame( SystemHealthService::NOT_CONFIGURED, $result['status'] );
        self::assertNotSame( SystemHealthService::FAIL, $result['status'] );
    }

    public function testMissingScheduleIsFingerprintedAsRepairable(): void
    {
        $result = QueueDiagnosis::classify( self::evidence( [ 'schedule_scheduled' => false ] ) );

        self::assertSame( QueueDiagnosis::STATE_MISSING_SCHEDULE, $result['state'] );
        self::assertSame( QueueDiagnosis::SEVERITY_PROBLEM, $result['severity'] );
        self::assertSame( SystemHealthService::FAIL, $result['status'] );
        self::assertTrue( $result['repairable'], 'رویدادِ غایب باید با اقدامِ ایمن قابلِ ترمیم باشد.' );
        self::assertTrue( QueueDiagnosis::severity_is_blocking( $result['severity'] ) );
    }

    public function testStaleExecutionIsDistinguishedFromMissingSchedule(): void
    {
        $result = QueueDiagnosis::classify(
            self::evidence( [
                'now'          => 1_000_000 + 3_600,
                'has_activity' => true,
                'queued'       => 4,
            ] )
        );

        self::assertSame( QueueDiagnosis::STATE_STALE, $result['state'] );
        self::assertSame( QueueDiagnosis::SEVERITY_PROBLEM, $result['severity'] );
        self::assertSame( SystemHealthService::FAIL, $result['status'] );
        self::assertFalse( $result['repairable'], 'اجرای کهنه با ترمیمِ زمان‌بندی حل نمی‌شود.' );
        self::assertStringContainsString( 'queued=4', $result['detail'] );
    }

    public function testFailedJobsAreTheirOwnStateNotAStoppedQueue(): void
    {
        $result = QueueDiagnosis::classify( self::evidence( [ 'failed' => 2 ] ) );

        self::assertSame( QueueDiagnosis::STATE_FAILED, $result['state'] );
        self::assertSame( QueueDiagnosis::SEVERITY_WARNING, $result['severity'] );
        self::assertSame( SystemHealthService::WARNING, $result['status'] );
        self::assertFalse( QueueDiagnosis::severity_is_blocking( $result['severity'] ) );
    }

    public function testLegacyClinicHeartbeatIsStillHonoured(): void
    {
        $result = QueueDiagnosis::classify( self::evidence( [ 'heartbeat_source' => 'legacy_clinic' ] ) );

        self::assertSame( QueueDiagnosis::STATE_HEALTHY, $result['state'] );
        self::assertStringContainsString( 'سازگاری', $result['detail'], 'منبعِ سازگاری باید صادقانه ذکر شود.' );
    }

    public function testMissingHeartbeatWithPendingWorkIsUnknownNotHealthy(): void
    {
        $result = QueueDiagnosis::classify(
            self::evidence( [
                'last_tick_at'     => 0,
                'heartbeat_source' => 'none',
                'has_activity'     => true,
                'queued'           => 3,
            ] )
        );

        self::assertSame( QueueDiagnosis::STATE_UNKNOWN, $result['state'] );
        self::assertSame( SystemHealthService::WARNING, $result['status'] );
        self::assertNotSame( SystemHealthService::PASS, $result['status'] );
    }

    public function testOnlyBlockingSeveritiesMapToHostLevelProblems(): void
    {
        self::assertTrue( QueueDiagnosis::severity_is_blocking( QueueDiagnosis::SEVERITY_PROBLEM ) );
        self::assertFalse( QueueDiagnosis::severity_is_blocking( QueueDiagnosis::SEVERITY_WARNING ) );
        self::assertFalse( QueueDiagnosis::severity_is_blocking( QueueDiagnosis::SEVERITY_INFO ) );
        self::assertFalse( QueueDiagnosis::severity_is_blocking( QueueDiagnosis::SEVERITY_OK ) );
    }

    // ============ ۳. ترمیمِ زمان‌بندی (بدون WP) ============

    public function testSchedulerProbeIsHonestOutsideWordPress(): void
    {
        self::assertNull( CronScheduleRepair::is_scheduled(), 'بدون WP نباید «زمان‌بندی‌شده» ادعا شود.' );
        self::assertNull( CronScheduleRepair::next_scheduled_at() );
        self::assertFalse( CronScheduleRepair::ensure_scheduled(), 'بدون WP هیچ زمان‌بندی‌ای ساخته نمی‌شود.' );
        self::assertFalse( CronScheduleRepair::self_heal_in_admin(), 'خارج از wp-admin ترمیمِ خودکار اجرا نمی‌شود.' );
    }

    public function testRepairNeverExecutesQueueWork(): void
    {
        // قراردادِ ایمنی: کلاسِ ترمیم فقط APIِ زمان‌بندی را صدا می‌زند.
        // (این تست عمداً روی منبعِ واقعی است، نه mock — یک بازگشتِ تصادفی به
        // اجرای صف باید همین‌جا شکست بدهد.)
        $source = (string) file_get_contents( __DIR__ . '/../../src/Application/System/CronScheduleRepair.php' );

        self::assertStringNotContainsString( 'runTick', $source, 'ترمیم نباید صف را اجرا کند.' );
        self::assertStringNotContainsString( 'jobs tick', $source, 'ترمیم نباید Job اجرا کند.' );
        self::assertStringNotContainsString( 'do_action(', $source, 'ترمیم نباید هیچ اکشنی را اجرا کند.' );
        self::assertStringNotContainsString( 'Dispatcher', $source, 'ترمیم نباید هیچ Jobی را dispatch کند.' );
        // تنها نوشتنِ مجاز، APIِ رسمیِ زمان‌بندیِ وردپرس است.
        self::assertStringContainsString( 'wp_schedule_event', $source );
        self::assertStringNotContainsString( 'define(', $source, 'ترمیم نباید هیچ ثابتی (مثل DISABLE_WP_CRON) تعریف کند.' );
        self::assertStringNotContainsString( 'file_put_contents', $source, 'ترمیم نباید هیچ فایلی بنویسد.' );
    }

    public function testSchedulerExplanationNeverClaimsHostChanges(): void
    {
        $text = CronScheduleRepair::scheduler_explanation();

        self::assertStringContainsString( 'ترافیک', $text );
        self::assertStringContainsString( 'ADR-0016', $text );
        self::assertStringContainsString( 'تغییر نمی‌دهد', $text );
    }
}
