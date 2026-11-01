<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * C11 — سهمیهٔ روزانه/ساعتیِ هر شماره یک «سهمیهٔ صدور» است، نه «سهمیهٔ تلاش».
 *
 * قرارداد:
 *   - درخواستی که با Cooldown یا Lockout رد می‌شود هیچ‌کدام از شمارنده‌های
 *     `otp-day:` / `otp-hour:` را مصرف نمی‌کند.
 *   - درخواستی که واقعاً Challenge صادر می‌کند دقیقاً یکی به هر دو اضافه می‌کند.
 *   - سقف دقیق است: بعد از max صدورِ موفق، درخواست بعدی رد می‌شود و شمارنده
 *     از max بالاتر نمی‌رود (نه max+1).
 *   - شمارندهٔ `otp-ip:` دست‌نخورده و مستقل از این تغییر است.
 *
 * هیچ‌کدام از تست‌ها `otp.cooldown_sec` را صفر نمی‌کنند: Cooldown واقعی (۶۰
 * ثانیه) فعال می‌ماند و برای عبور از آن، `created_at` آخرین Challenge به عقب
 * برده می‌شود (همان روشی که probeهای مرزیِ production-race-proof استفاده
 * می‌کنند).
 */
final class OtpQuotaConsumptionTest extends WP_UnitTestCase
{
    private const DAY = 86400;
    private const HOUR = 3600;

    /** هر تست شمارهٔ خودش را دارد تا وضعیت بین تست‌ها نشت نکند. */
    private const MOBILE_COOLDOWN = '09127770001';
    private const MOBILE_LOCKOUT = '09127770002';
    private const MOBILE_DAILY = '09127770003';
    private const MOBILE_HOURLY = '09127770004';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
    }

    private function service(): OtpService
    {
        return App::otpService();
    }

    /** شمارندهٔ پنجرهٔ جاری (فقط خواندن). */
    private function counter(string $key, int $windowSec): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_rate_limits';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT hits FROM {$table} WHERE window_key = %s AND window_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
                $key,
                intdiv(time(), $windowSec)
            )
        );
    }

    private function dayHits(string $mobile): int
    {
        return $this->counter('otp-day:' . $mobile, self::DAY);
    }

    private function hourHits(string $mobile): int
    {
        return $this->counter('otp-hour:' . $mobile, self::HOUR);
    }

    private function latestChallengeId(string $mobile): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_otp_tokens';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE mobile = %s AND purpose = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
                $mobile,
                OtpService::PURPOSE_LOGIN
            )
        );
    }

    private function challengeCount(string $mobile): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_otp_tokens';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE mobile = %s AND purpose = %s", // phpcs:ignore WordPress.DB.PreparedSQL
                $mobile,
                OtpService::PURPOSE_LOGIN
            )
        );
    }

    /**
     * عبور از Cooldownِ واقعی بدون تغییرِ تنظیمات: مبدأِ Cooldown همان
     * `created_at` آخرین Challenge است، پس فقط همان ردیف را عقب می‌بریم.
     */
    private function movePastCooldown(string $mobile): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_otp_tokens';
        $id = $this->latestChallengeId($mobile);
        $this->assertGreaterThan(0, $id, 'a challenge must exist before its cooldown can elapse');
        $updated = $wpdb->update(
            $table,
            ['created_at' => gmdate('Y-m-d H:i:s', time() - 61) . '.000'],
            ['id' => $id]
        );
        $this->assertSame(1, (int) $updated, 'cooldown fixture must age exactly one challenge');
    }

    private function issue(string $mobile): array
    {
        return $this->service()->request($mobile, OtpService::PURPOSE_LOGIN);
    }

    private function expectApiCode(string $expected, callable $action): OtpException
    {
        try {
            $action();
        } catch (OtpException $error) {
            $this->assertSame($expected, $error->apiCode());

            return $error;
        }

        $this->fail('expected ' . $expected);
    }

    /**
     * (a) درخواستِ ردشده با Cooldown سهمیهٔ روز/ساعت را مصرف نمی‌کند.
     *
     * علیه رفتارِ قدیمی شکست می‌خورد: آنجا hit()ها پیش از بررسیِ Cooldown اجرا
     * می‌شدند و شمارنده‌ها 2 می‌شدند.
     */
    public function testCooldownRejectedRequestDoesNotConsumeQuota(): void
    {
        $mobile = self::MOBILE_COOLDOWN;

        $this->issue($mobile);
        $this->assertSame(1, $this->dayHits($mobile), 'the issued challenge charges the daily quota exactly once');
        $this->assertSame(1, $this->hourHits($mobile), 'the issued challenge charges the hourly quota exactly once');

        $this->expectApiCode('CLINIC_OTP_COOLDOWN', fn () => $this->issue($mobile));

        $this->assertSame(1, $this->dayHits($mobile), 'a cooldown-rejected request must not consume the daily quota');
        $this->assertSame(1, $this->hourHits($mobile), 'a cooldown-rejected request must not consume the hourly quota');
        $this->assertSame(1, $this->challengeCount($mobile), 'no challenge may be created by a rejected request');
    }

    /**
     * (b) درخواستِ ردشده با Lockout سهمیهٔ روز/ساعت را مصرف نمی‌کند.
     */
    public function testLockoutRejectedRequestDoesNotConsumeQuota(): void
    {
        $mobile = self::MOBILE_LOCKOUT;

        $issued = $this->issue($mobile);
        $this->assertSame(1, $this->dayHits($mobile));
        $this->assertSame(1, $this->hourHits($mobile));

        // قفل با ۵ تلاش نادرست (پیش‌فرض otp.max_attempts=5) — کد واقعی لازم نیست.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $expected = $attempt === 5 ? 'CLINIC_OTP_LOCKED' : 'CLINIC_OTP_INVALID';
            $error = $this->expectApiCode($expected, fn () => $this->service()->verify($mobile, '000000'));
            $this->assertSame(5 - $attempt, (int) ($error->getData()['remaining'] ?? -1));
        }
        // تلاش‌های نادرست سهمیهٔ صدور را لمس نمی‌کنند.
        $this->assertSame(1, $this->dayHits($mobile), 'failed verifications must not touch the issuance quota');
        $this->assertSame(1, $this->hourHits($mobile), 'failed verifications must not touch the issuance quota');

        $locked = $this->expectApiCode('CLINIC_OTP_LOCKED', fn () => $this->issue($mobile));
        $this->assertGreaterThanOrEqual(1, (int) $locked->getData()['retry_after']);

        $this->assertSame(1, $this->dayHits($mobile), 'a lockout-rejected request must not consume the daily quota');
        $this->assertSame(1, $this->hourHits($mobile), 'a lockout-rejected request must not consume the hourly quota');
        $this->assertSame(1, $this->challengeCount($mobile), 'no challenge may be created while locked out');
        $this->assertSame(120, (int) $issued['expires_in'], 'TTL stays at the operational default');
    }

    /**
     * (c) سقفِ روزانه دقیقاً بعد از max صدورِ موفق مسدود می‌کند.
     */
    public function testDailyCapBlocksExactlyAfterMaxIssuedChallenges(): void
    {
        $mobile = self::MOBILE_DAILY;
        $dailyMax = (int) Settings::DEFAULTS['otp.daily_max'];
        $this->assertSame(3, $dailyMax, 'guard: this test documents the default daily cap');

        for ($issued = 1; $issued <= $dailyMax; $issued++) {
            $this->issue($mobile);
            $this->assertSame($issued, $this->dayHits($mobile), 'each issued challenge charges the daily quota exactly once');
            $this->assertSame($issued, $this->hourHits($mobile), 'each issued challenge charges the hourly quota exactly once');
            if ($issued < $dailyMax) {
                $this->movePastCooldown($mobile);
            }
        }

        $this->assertSame($dailyMax, $this->challengeCount($mobile));

        $denied = $this->expectApiCode('CLINIC_OTP_DAILY_LIMIT', fn () => $this->issue($mobile));
        $data = $denied->getData();
        $this->assertGreaterThanOrEqual(1, (int) $data['retry_after']);
        $this->assertLessThanOrEqual(self::DAY, (int) $data['retry_after']);
        $this->assertLessThanOrEqual(1, abs((int) $data['reset_at'] - time() - (int) $data['retry_after']));
        $this->assertFalse((bool) $data['allowed']);
        $this->assertSame(0, (int) $data['remaining']);

        $this->assertSame($dailyMax, $this->dayHits($mobile), 'the cap must never be exceeded (never max+1)');
        $this->assertSame($dailyMax, $this->hourHits($mobile), 'a cap-rejected request must not consume either quota');
        $this->assertSame($dailyMax, $this->challengeCount($mobile), 'a cap-rejected request must not create a challenge');
    }

    /**
     * (d) سقفِ ساعتی هم دقیقاً همین‌طور مسدود می‌کند (با سقفِ روزانهٔ بالاتر تا
     * سقفِ ساعتی زودتر برسد).
     */
    public function testHourlyCapBlocksExactlyAfterMaxIssuedChallenges(): void
    {
        $mobile = self::MOBILE_HOURLY;
        $hourlyMax = 2;

        Settings::flushCache();
        App::settings()->set('otp.daily_max', 10);
        App::settings()->set('otp.hourly_max', $hourlyMax);
        Settings::flushCache();

        for ($issued = 1; $issued <= $hourlyMax; $issued++) {
            $this->issue($mobile);
            $this->assertSame($issued, $this->hourHits($mobile), 'each issued challenge charges the hourly quota exactly once');
            $this->assertSame($issued, $this->dayHits($mobile));
            if ($issued < $hourlyMax) {
                $this->movePastCooldown($mobile);
            }
        }

        $denied = $this->expectApiCode('CLINIC_RATE_LIMITED', fn () => $this->issue($mobile));
        $data = $denied->getData();
        $this->assertFalse((bool) $data['allowed']);
        $this->assertSame(0, (int) $data['remaining']);
        $this->assertGreaterThanOrEqual(1, (int) $data['retry_after']);
        $this->assertLessThanOrEqual(self::HOUR, (int) $data['retry_after']);

        $this->assertSame($hourlyMax, $this->hourHits($mobile), 'the hourly cap must never be exceeded');
        $this->assertSame($hourlyMax, $this->dayHits($mobile), 'a cap-rejected request must not consume the daily quota either');
        $this->assertSame($hourlyMax, $this->challengeCount($mobile));
    }

    /**
     * (f-مکمل) `RateLimiter::peek()` که تصمیمِ سهمیه بر آن استوار است، فقط
     * می‌خوانَد و وضعیت را تغییر نمی‌دهد. (RateLimiterTest دست‌نخورده می‌ماند،
     * پس پوششِ peek اینجا اضافه شده است.)
     */
    public function testPeekDoesNotMutateTheCounter(): void
    {
        $key = 'otp-day:' . '09127770099';

        $this->assertSame(0, App::rate()->peek($key, self::DAY), 'peek on a missing window reads zero');

        $first = App::rate()->hit($key, 3, self::DAY);
        $this->assertTrue($first['allowed']);

        $this->assertSame(1, App::rate()->peek($key, self::DAY));
        $this->assertSame(1, App::rate()->peek($key, self::DAY), 'peek is idempotent');
        $this->assertSame(1, App::rate()->peek($key, self::DAY));

        // هنوز دقیقاً یک مصرف؛ اگر peek می‌نوشت، اینجا 4 می‌شد و سقف زودتر می‌بست.
        $second = App::rate()->hit($key, 3, self::DAY);
        $this->assertTrue($second['allowed']);
        $this->assertSame(1, (int) $second['remaining']);

        $third = App::rate()->hit($key, 3, self::DAY);
        $this->assertTrue($third['allowed'], 'exactly max hits are allowed');
        $this->assertSame(0, (int) $third['remaining']);

        $fourth = App::rate()->hit($key, 3, self::DAY);
        $this->assertFalse($fourth['allowed'], 'max+1 is rejected — exact-max behaviour is unchanged');
    }
}
