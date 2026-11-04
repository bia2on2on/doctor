<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Item 4 — the per-IP OTP hourly limit is a Setting, not a literal.
 *
 * New key otp.ip_hourly_max (default 10, so default behaviour is identical to
 * the former literal) is read from the same Settings object as otp.hourly_max.
 * The per-mobile limits, the 'otp-ip:' bucket name and ClientIp are unchanged.
 */
final class OtpIpHourlyLimitTest extends TestCase
{
    public function testDefaultMatchesTheFormerLiteral(): void
    {
        self::assertArrayHasKey('otp.ip_hourly_max', Settings::DEFAULTS);
        self::assertSame(10, Settings::DEFAULTS['otp.ip_hourly_max']);
    }

    public function testClampKeepsTheLimitInsideSanityBounds(): void
    {
        // Default and ordinary values pass through unchanged.
        self::assertSame(10, OtpService::clamp_ip_hourly_max(10));
        self::assertSame(1, OtpService::clamp_ip_hourly_max(1));
        self::assertSame(1000, OtpService::clamp_ip_hourly_max(1000));

        // min 1: zero/negative/nonsense can never disarm the per-IP guard.
        self::assertSame(1, OtpService::clamp_ip_hourly_max(0));
        self::assertSame(1, OtpService::clamp_ip_hourly_max(-25));
        self::assertSame(1, OtpService::clamp_ip_hourly_max(null));

        // sane max: an absurdly large value cannot make the guard unbounded.
        self::assertSame(1000, OtpService::clamp_ip_hourly_max(1001));
        self::assertSame(1000, OtpService::clamp_ip_hourly_max(9_999_999));
    }

    public function testRequestUsesTheSettingInsteadOfALiteral(): void
    {
        // Wiring guard: the per-IP hit must read the Settings key and must not
        // regress to a bare numeric cap on that line.
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Application/Auth/OtpService.php'
        );

        self::assertStringContainsString("get( 'otp.ip_hourly_max' )", $src);
        self::assertStringNotContainsString("hit('otp-ip:' . \$ip, 10, 3600)", $src);
    }
}
