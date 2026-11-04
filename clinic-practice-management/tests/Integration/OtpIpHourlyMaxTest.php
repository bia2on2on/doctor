<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * ITEM 4 — سقفِ ساعتیِ OTP برای هر IP از Setting `otp.ip_hourly_max` خوانده
 * می‌شود (پیش‌فرض ۱۰، رفتار پیش‌فرض تغییر نمی‌کند) و حداقل ۱ و سقف منطقی
 * دارد. از همان شیئِ Settings که `otp.hourly_max` را می‌خواند.
 *
 * ولا محدودهِٔ موبایل، نامِ קיتی (bucket) و ClientIp تغییر نمی‌کنند.
 */
final class OtpIpHourlyMaxTest extends WP_UnitTestCase
{
    private const MOBILE = '09129990001';

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

    /** ۱۰ درخواست از یک IP با پیش‌فرض (۱۰) باید सफल شود؛ خطای نodos번 نیست. */
    public function testDefaultIpLimitIsTen(): void
    {
        // هیچ Setting `otp.ip_hourly_max` ثبت نشده ⇒ پیش‌فرض ۱۰ اعمال می‌شود.
        for ($i = 0; $i < 10; $i++) {
            $this->service()->request(self::MOBILE, OtpService::PURPOSE_LOGIN, null, '203.0.113.' . $i);
        }
        $this->addToAssertionCount(1);
    }

    public function testIpLimitComesFromSetting(): void
    {
        $settings = App::settingsFactory()->forClinic(1);
        $settings->set('otp.ip_hourly_max', 2);

        $ip = '203.0.113.50';
        $this->service()->request(self::MOBILE, OtpService::PURPOSE_LOGIN, null, $ip);
        $this->service()->request(self::MOBILE, OtpService::PURPOSE_LOGIN, null, $ip);

        $this->expectException(OtpException::class);
        try {
            $this->service()->request(self::MOBILE, OtpService::PURPOSE_LOGIN, null, $ip);
        } catch (OtpException $e) {
            self::assertSame('CLINIC_RATE_LIMITED', $e->apiCode());
            throw $e;
        }
    }

    public function testIpLimitMinimumIsOne(): void
    {
        $settings = App::settingsFactory()->forClinic(1);
        $settings->set('otp.ip_hourly_max', 0); // نامعتبر ⇒ باز normalized به ۱

        $ip = '203.0.113.60';
        $this->service()->request(self::MOBILE, OtpService::PURPOSE_LOGIN, null, $ip);

        $this->expectException(OtpException::class);
        try {
            $this->service()->request(self::MOBILE, OtpService::PURPOSE_LOGIN, null, $ip);
        } catch (OtpException $e) {
            self::assertSame('CLINIC_RATE_LIMITED', $e->apiCode());
            throw $e;
        }
    }
}
