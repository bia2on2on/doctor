<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Sms\SmsSendException;
use ClinicCore\Infrastructure\Sms\SsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0025 §17 — محافظت SSRF برای Endpointهای دلخواه (Generic API).
 */
final class SsrfGuardTest extends TestCase
{
    public function testPublicHttpAllowed(): void
    {
        SsrfGuard::assertSafe('http://8.8.8.8:8080/api/send');
        SsrfGuard::assertSafe('https://1.1.1.1/v1');
        $this->assertTrue(true, 'بدون استثنا = مجاز');
    }

    public function testPublicNonPrivateRangeAllowed(): void
    {
        // 172.32.x خارج از بلوک خصوصی 172.16-31 است
        SsrfGuard::assertSafe('https://172.32.0.1:443/');
        SsrfGuard::assertSafe('https://100.64.0.1/'); // CGNAT — در Filter PHP خصوصی محسوب نمی‌شود
        $this->assertTrue(true, 'بدون استثنا = مجاز');
    }

    public function testLoopbackBlocked(): void
    {
        $this->expectException(SmsSendException::class);
        $this->expectExceptionMessage('SSRF');
        SsrfGuard::assertSafe('http://127.0.0.1/admin');
    }

    public function testPrivateRangesBlocked(): void
    {
        $targets = ['http://10.0.0.5/', 'http://192.168.1.1/', 'http://172.16.0.1/', 'http://169.254.1.1/'];
        foreach ($targets as $url) {
            $caught = false;
            try {
                SsrfGuard::assertSafe($url);
            } catch (SmsSendException $e) {
                $caught = $e->apiCode() === 'CLINIC_SSRF_BLOCKED';
            }
            $this->assertTrue($caught, "مسدود نبود: {$url}");
        }
    }

    public function testLocalhostNameBlocked(): void
    {
        $this->expectException(SmsSendException::class);
        SsrfGuard::assertSafe('http://localhost:8080/');
    }

    public function testBadSchemeBlocked(): void
    {
        $this->expectException(SmsSendException::class);
        SsrfGuard::assertSafe('ftp://8.8.8.8/file');
    }

    public function testMalformedUrlBlocked(): void
    {
        $this->expectException(SmsSendException::class);
        SsrfGuard::assertSafe('not-a-url');
    }

    public function testSchemeCaseInsensitive(): void
    {
        SsrfGuard::assertSafe('HTTPS://8.8.8.8/');
        $this->assertTrue(true, 'بدون استثنا = مجاز');
    }
}
