<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Security\LoginRateLimiter;
use WP_Error;
use WP_UnitTestCase;

/**
 * Phase 1A — Item 3: Bruteforce ورود.
 *
 * وضعیت پیش از Phase 1: کلید `login:{ip}` فقط در کامنت RateLimiter وجود
 * داشت؛ هیچ فراخوانی و هیچ Hook ای روی `authenticate` نبود، یعنی
 * `wp-login.php` و احراز هویت REST بدون هیچ سقفی قابل Bruteforce بودند.
 */
final class LoginRateLimitTest extends WP_UnitTestCase
{
    private LoginRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->limiter = App::loginRateLimiter();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(2, 250);
    }

    public function testHookIsActuallyRegisteredOnBoot(): void
    {
        self::assertNotFalse(
            has_filter('authenticate'),
            'فیلتر authenticate باید هنگام boot بسته شود.'
        );
        self::assertNotFalse(
            has_action('wp_login_failed'),
            'اکشن wp_login_failed باید هنگام boot بسته شود.'
        );
    }

    public function testCleanRequestIsNotBlocked(): void
    {
        $user = self::factory()->user->create_and_get(['user_login' => 'lrl_clean']);
        $result = $this->limiter->blockWhenThrottled($user, 'lrl_clean', 'secret');

        self::assertSame($user, $result, 'کاربر بدون سابقهٔ شکست نباید بلاک شود.');
    }

    /**
     * درخواست‌های بدون اعتبارنامه (بررسی کوکی) نباید لمس شوند.
     */
    public function testRequestWithoutCredentialsPassesThrough(): void
    {
        self::assertNull($this->limiter->blockWhenThrottled(null, '', ''));
    }

    /**
     * مرز دقیق سقف مبتنی بر نام کاربری.
     */
    public function testUsernameThresholdBoundary(): void
    {
        $username = 'lrl_target_' . uniqid();

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_USERNAME - 1; $i++) {
            $this->limiter->recordFailure($username);
        }
        self::assertNull(
            $this->limiter->blockWhenThrottled(null, $username, 'x'),
            'یک تلاش پیش از سقف نباید بلاک شود.'
        );

        $this->limiter->recordFailure($username);
        $blocked = $this->limiter->blockWhenThrottled(null, $username, 'x');

        self::assertInstanceOf(WP_Error::class, $blocked);
        self::assertSame('CLINIC_RATE_LIMITED', $blocked->get_error_code());
        self::assertSame(429, $blocked->get_error_data()['status'] ?? 0);
    }

    /**
     * سقف مبتنی بر IP مستقل از نام کاربری عمل می‌کند (پاشش روی چند حساب).
     */
    public function testIpThresholdBlocksSprayAcrossManyUsernames(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_IP; $i++) {
            $this->limiter->recordFailure('spray_user_' . $i);
        }

        $blocked = $this->limiter->blockWhenThrottled(null, 'spray_user_999', 'x');
        self::assertInstanceOf(WP_Error::class, $blocked);
        self::assertSame('CLINIC_RATE_LIMITED', $blocked->get_error_code());
    }

    /**
     * پیام بلاک نباید بگوید حساب وجود دارد یا نه (Enumeration).
     */
    public function testBlockMessageIsIdenticalForExistingAndUnknownAccounts(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.78';
        $existing = self::factory()->user->create_and_get(['user_login' => 'lrl_exists']);

        for ($i = 0; $i < LoginRateLimiter::MAX_PER_IP; $i++) {
            $this->limiter->recordFailure('whatever_' . $i);
        }

        $a = $this->limiter->blockWhenThrottled($existing, 'lrl_exists', 'x');
        $b = $this->limiter->blockWhenThrottled(null, 'definitely_not_a_user', 'x');

        self::assertInstanceOf(WP_Error::class, $a);
        self::assertInstanceOf(WP_Error::class, $b);
        self::assertSame($a->get_error_message(), $b->get_error_message());
    }

    /**
     * فقط شکست‌ها شمرده می‌شوند: بررسی نباید خودش شمارنده را بالا ببرد.
     */
    public function testCheckingDoesNotConsumeQuota(): void
    {
        $username = 'lrl_peek_' . uniqid();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.79';

        for ($i = 0; $i < 200; $i++) {
            $this->limiter->blockWhenThrottled(null, $username, 'x');
        }

        self::assertNull(
            $this->limiter->blockWhenThrottled(null, $username, 'x'),
            'صرفِ بررسی نباید سهمیه را مصرف کند.'
        );
    }

    /**
     * نام کاربری خام نباید در جدول Rate Limit ذخیره شود.
     */
    public function testUsernameIsNotStoredInClearText(): void
    {
        $username = 'lrl_secret_person';
        $this->limiter->recordFailure($username);

        $db = App::db();
        $rows = $db->fetchAll('SELECT window_key FROM ' . $db->table('cpms_rate_limits'), []);
        foreach ($rows as $row) {
            self::assertStringNotContainsString($username, (string) $row['window_key']);
        }
    }
}
