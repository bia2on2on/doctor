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
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_rate_limits';
        $oldWindow = intdiv(time() - 200000, 3600);
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (window_key, window_id, hits) VALUES ('old-key', %d, 99)", $oldWindow)); // phpcs:ignore WordPress.DB.PreparedSQL

        App::rate()->cleanup(86400);

        $left = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE window_key = %s", 'old-key')); // phpcs:ignore
        $this->assertSame(0, (int) $left);
    }
}
