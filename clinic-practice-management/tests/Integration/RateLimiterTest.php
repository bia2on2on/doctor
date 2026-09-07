<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * TP-05 (لایه Rate Limit) — OTP و Endpointهای حساس.
 */
final class RateLimiterTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
    }

    public function testAllowsUpToMaxThenBlocks(): void
    {
        $rl = App::rate();
        $results = [];
        for ($i = 1; $i <= 4; $i++) {
            $results[] = $rl->hit('otp:09121112233', 3, 3600);
        }

        $this->assertTrue($results[0]['allowed']);
        $this->assertSame(2, $results[0]['remaining']);
        $this->assertTrue($results[2]['allowed']);
        $this->assertFalse($results[3]['allowed'], 'دعوای چهارم باید Block شود');
        $this->assertSame(0, $results[3]['remaining']);
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $rl = App::rate();
        for ($i = 0; $i < 3; $i++) {
            $rl->hit('otp:a', 3, 3600);
        }
        $this->assertTrue($rl->hit('otp:b', 3, 3600)['allowed']);
        $this->assertFalse($rl->hit('otp:a', 3, 3600)['allowed']);
    }

    public function testAtomicIncrementUnderSequentialHits(): void
    {
        // شبیه‌سازی hitهای پشت‌سرهم (Concurrency واقعی با 2 Process در تست بار)
        $rl = App::rate();
        for ($i = 0; $i < 10; $i++) {
            $rl->hit('booking:user-7', 5, 3600);
        }
        $r = $rl->hit('booking:user-7', 5, 3600);
        $this->assertFalse($r['allowed']);
    }

    public function testCleanupRemovesOldWindows(): void
    {
        // ردیف legacy (بدون window_sec — پیش‌فرض 3600 از Migration 0009):
        // حذف می‌شود؛ رفتار قدیمی برای ردیف‌های ساعتی حفظ است.
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_rate_limits';
        $oldWindow = intdiv(time() - 200000, 3600);
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (window_key, window_id, hits) VALUES ('old-key', %d, 99)", $oldWindow)); // phpcs:ignore WordPress.DB.PreparedSQL

        App::rate()->cleanup(86400);

        $left = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE window_key = %s", 'old-key')); // phpcs:ignore
        $this->assertSame(0, (int) $left);
    }

    public function testDailyOtpLimitSurvivesCleanup(): void
    {
        // Regression F1-1: کد قدیم cutoff را در واحد «ساعت» حساب می‌کرد در حالی
        // که window_id پنجرهٔ روزانه در واحد 86400 است — ردیفِ زندهٔ
        // otp-day در هر اجرا حذف می‌شد و سقف 3 تلاش در روز بی‌اثر بود.
        $rl = App::rate();
        $key = 'otp-day:09121112233';

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($rl->hit($key, 3, 86400)['allowed']);
        }

        // همان فراخوانی Job روزانه (RateLimitCleanupHandler)
        $rl->cleanup(2 * 86400);

        $windowId = intdiv(time(), 86400);
        $row = App::db()->fetchRow(
            'SELECT hits, window_sec FROM ' . App::db()->table('cpms_rate_limits')
            . ' WHERE window_key = %s AND window_id = %d',
            [$key, $windowId]
        );
        $this->assertNotNull($row, 'پنجرهٔ روزانهٔ زنده باید بعد از cleanup باقی بماند');
        $this->assertSame(3, (int) $row['hits']);
        $this->assertSame(86400, (int) $row['window_sec']);

        $this->assertFalse(
            $rl->hit($key, 3, 86400)['allowed'],
            'تلاش چهارمِ همان روز بعد از cleanup باید Block شود'
        );
    }

    public function testCleanupKeepsLiveWindowsOfEveryUnit(): void
    {
        // Regression F1-1 (بخش دوم): پنجره‌های زنده با هر windowSec
        // (روز / ساعت / دقیقه) نباید پاک شوند.
        $rl = App::rate();
        $rl->hit('live:daily', 100, 86400);
        $rl->hit('live:hourly', 100, 3600);
        $rl->hit('live:minute', 100, 60);

        $rl->cleanup(2 * 86400);

        $this->assertNotNull($this->rowFor('live:daily', 86400));
        $this->assertNotNull($this->rowFor('live:hourly', 3600));
        $this->assertNotNull($this->rowFor('live:minute', 60));
    }

    public function testCleanupRemovesExpiredWindowsOfEveryUnit(): void
    {
        // پنجره‌های منقضی (4 روز پیش) با هر واحد windowSec حذف می‌شوند —
        // پیش از F1-1 ردیف‌های دقیقه‌ای هرگز حذف نمی‌شدند (رشد بی‌پایان جدول).
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_rate_limits';
        $now = time();

        $expired = [
            ['exp:daily', intdiv($now - 4 * 86400, 86400), 86400],
            ['exp:hourly', intdiv($now - 4 * 3600, 3600), 3600],
            ['exp:minute', intdiv($now - 4 * 3600, 60), 60],
        ];
        foreach ($expired as [$key, $windowId, $windowSec]) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (window_key, window_id, window_sec, hits) VALUES (%s, %d, %d, 7)",
                $key, $windowId, $windowSec
            )); // phpcs:ignore WordPress.DB.PreparedSQL
        }

        $deleted = App::rate()->cleanup(86400);

        $this->assertGreaterThanOrEqual(3, $deleted);
        foreach ($expired as [$key, $windowId, $windowSec]) {
            $left = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE window_key = %s AND window_id = %d",
                $key, $windowId
            )); // phpcs:ignore
            $this->assertSame(0, (int) $left, "پنجرهٔ منقضی {$key} باید حذف شده باشد");
        }
    }

    /**
     * کمک‌کنندهٔ تست: آیا پنجرهٔ زندهٔ (key, windowSec) در جدول است؟
     *
     * @return array<string, mixed>|null
     */
    private function rowFor(string $key, int $windowSec): ?array
    {
        return App::db()->fetchRow(
            'SELECT hits FROM ' . App::db()->table('cpms_rate_limits')
            . ' WHERE window_key = %s AND window_id = %d',
            [$key, intdiv(time(), $windowSec)]
        );
    }
}
