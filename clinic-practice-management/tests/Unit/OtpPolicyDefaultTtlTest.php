<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * C11 — TTL پیش‌فرضِ سیاست OTP باید با پیش‌فرضِ عملیاتی یکی باشد.
 *
 * پیش‌تر `OtpPolicy::__construct` مقدار `300` داشت، در حالی که مقدارِ واقعیِ
 * Runtime از `Settings::DEFAULTS['otp.ttl_sec']` (=120) می‌آمد و متنِ پیامک هم
 * «۲ دقیقه» می‌گفت. یک Default غیرِعملیاتیِ ناسازگار = تلهٔ نگهداری.
 */
final class OtpPolicyDefaultTtlTest extends TestCase
{
    public function testDefaultTtlIs120AndMatchesSettingsDefault(): void
    {
        // قراردادِ ثابت: هر دو منبع باید ۱۲۰ ثانیه باشند.
        $this->assertSame(120, Settings::DEFAULTS['otp.ttl_sec'], 'Settings default TTL must be 120s');

        $policy = new OtpPolicy(); // مسیرِ بدون آرگومان = همان Default مورد بحث
        $issuedAt = new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('UTC'));

        $settingsTtl = (int) Settings::DEFAULTS['otp.ttl_sec'];

        // رفتارِ Default: نه یک ثانیه کمتر، نه یک ثانیه بیشتر.
        $this->assertFalse(
            $policy->isCodeExpired($issuedAt, $issuedAt->modify('+' . ($settingsTtl - 1) . ' seconds')),
            'a code must still be valid one second before the default TTL'
        );
        $this->assertTrue(
            $policy->isCodeExpired($issuedAt, $issuedAt->modify('+' . $settingsTtl . ' seconds')),
            'a code must be expired exactly at the default TTL'
        );

        // اگر کسی Default را دوباره به 300 برگرداند، این تست می‌شکند: با
        // TTL=300 کد در ثانیهٔ ۲۹۹ هنوز معتبر می‌بود.
        $this->assertTrue(
            $policy->isCodeExpired($issuedAt, $issuedAt->modify('+299 seconds')),
            'default TTL must not be 300s again'
        );
        $this->assertFalse(
            $policy->isCodeExpired($issuedAt, $issuedAt->modify('+60 seconds')),
            'the code must still be valid well inside the 120s window'
        );
    }
}
