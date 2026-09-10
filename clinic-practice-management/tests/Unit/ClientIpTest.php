<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Security\ClientIp;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1A — Item 3: رفتار صریح نسبت به Reverse Proxy.
 *
 * قرارداد امنیتی که این تست قفل می‌کند: تا وقتی نصب صراحتاً Proxy معتمد
 * اعلام نکرده، هیچ هدر Forwarded ای نباید بر تشخیص IP اثر بگذارد — در غیر
 * این صورت هر محدودیت مبتنی بر IP با یک هدر جعلی دور می‌خورد.
 *
 * چون CPMS_TRUSTED_PROXIES یک ثابت است و در PHP قابل بازتعریف نیست،
 * سناریوهای «با Proxy معتمد» فقط وقتی اجرا می‌شوند که همان ثابت در محیط
 * تعریف شده باشد؛ در غیر این صورت skip می‌شوند و مسیر پیش‌فرض (که مسیر
 * امن است) کامل تست می‌شود.
 */
final class ClientIpTest extends TestCase
{
    public function testReturnsRemoteAddrWhenNoTrustedProxyConfigured(): void
    {
        if (defined(ClientIp::TRUSTED_PROXIES_CONST)) {
            self::markTestSkipped('CPMS_TRUSTED_PROXIES در این محیط تعریف شده است.');
        }

        $ip = ClientIp::resolve([
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        self::assertSame('198.51.100.7', $ip);
    }

    /**
     * حمله: جعل X-Forwarded-For برای گرفتن یک «IP تازه» در هر درخواست.
     */
    public function testSpoofedForwardedHeaderIsIgnoredByDefault(): void
    {
        if (defined(ClientIp::TRUSTED_PROXIES_CONST)) {
            self::markTestSkipped('CPMS_TRUSTED_PROXIES در این محیط تعریف شده است.');
        }

        foreach (['9.9.9.9', '10.0.0.1, 9.9.9.9', 'not-an-ip', '127.0.0.1'] as $spoof) {
            self::assertSame(
                '198.51.100.7',
                ClientIp::resolve([
                    'REMOTE_ADDR' => '198.51.100.7',
                    'HTTP_X_FORWARDED_FOR' => $spoof,
                    'HTTP_X_REAL_IP' => $spoof,
                ]),
                'هدر جعلی نباید IP را تغییر دهد: ' . $spoof
            );
        }
    }

    public function testTrustsProxiesIsFalseByDefault(): void
    {
        if (defined(ClientIp::TRUSTED_PROXIES_CONST)) {
            self::markTestSkipped('CPMS_TRUSTED_PROXIES در این محیط تعریف شده است.');
        }

        self::assertFalse(ClientIp::trustsProxies());
    }

    public function testMalformedRemoteAddrYieldsNull(): void
    {
        foreach ([null, '', '   ', 'localhost', '999.999.999.999', '<script>'] as $bad) {
            self::assertNull(
                ClientIp::resolve(['REMOTE_ADDR' => $bad]),
                'REMOTE_ADDR بدشکل باید null بدهد.'
            );
        }
    }

    public function testIpv4WithPortIsNormalized(): void
    {
        self::assertSame('198.51.100.7', ClientIp::resolve(['REMOTE_ADDR' => '198.51.100.7:54321']));
    }

    public function testBracketedIpv6IsNormalized(): void
    {
        self::assertSame('2001:db8::1', ClientIp::resolve(['REMOTE_ADDR' => '[2001:db8::1]:443']));
    }

    public function testPlainIpv6IsAccepted(): void
    {
        self::assertSame('2001:db8::1', ClientIp::resolve(['REMOTE_ADDR' => '2001:db8::1']));
    }

    public function testMissingRemoteAddrYieldsNull(): void
    {
        self::assertNull(ClientIp::resolve([]));
    }
}
