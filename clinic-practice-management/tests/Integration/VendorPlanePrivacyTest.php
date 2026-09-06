<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Infrastructure\Licensing\HttpVendorGateway;
use ClinicCore\Infrastructure\Sms\SmsSendException;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use ClinicCore\Infrastructure\Update\HttpUpdateMetadataGateway;
use WP_UnitTestCase;

/**
 * F10 — GO-LIVE §28: «هیچ PHI به سرور فروشنده» (ADR-0028).
 *
 * مسیر واقعی خروجی (HttpVendorGateway/HttpUpdateMetadataGateway) با رهگیری
 * pre_http_request آزمایش می‌شود: دادهٔ sentinel پزشکی در DB هست و هرگز نباید
 * در URL/Header/Body خروجی به Control-Plane ظاهر شود. Endpointهای داخلی/
 * خصوصی (SSRF) باید پیش از هر درخواست HTTP مسدود شوند.
 *
 * فقط در CI (WP + MySQL + sodium) اجرا می‌شود.
 */
final class VendorPlanePrivacyTest extends WP_UnitTestCase
{
    private const SENTINEL = 'SENTINEL-PHI-9f2c7b';

    /** @var list<array{url: string, headers: array<string, mixed>, body: string}> */
    private array $captured = [];
    private bool $httpFired = false;
    private ?string $keypair = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        $this->captured = [];
        $this->httpFired = false;
        add_filter('pre_http_request', function ($pre, array $args, string $url) {
            $this->httpFired = true;
            $headers = (array) ($args['headers'] ?? []);
            $this->captured[] = [
                'url' => $url,
                'headers' => $headers,
                'body' => (string) ($args['body'] ?? ''),
            ];
            if (str_contains($url, '/updates/manifest')) {
                $body = $this->fixtureUpdateBody();
            } else {
                $installId = '';
                foreach (['X-CPMS-Install', 'x-cpms-install'] as $h) {
                    if (isset($headers[$h]) && is_string($headers[$h])) { $installId = $headers[$h]; break; }
                }
                $body = $this->fixtureLicenseBody($installId);
            }

            return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => $body];
        }, 10, 3);

        if (LicenseSignature::available()) {
            $this->keypair = sodium_crypto_sign_keypair();
            $pub = base64_encode(sodium_crypto_sign_publickey($this->keypair));
            add_filter('cpms_license_public_key', static fn (): string => $pub);
        }
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        remove_all_filters('cpms_license_public_key');
        parent::tearDown();
    }

    private function seedSentinelPhi(): void
    {
        $db = App::db();
        $now = $db->nowUtcSql();
        $db->query(
            'INSERT INTO ' . $db->table('cpms_patients') .
            ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)' .
            ' VALUES (1, %s, %s, %s, %s, %s, %s, %s)',
            ['SYN-SENTINEL-001', self::SENTINEL, self::SENTINEL, '0912' . substr(self::SENTINEL, 0, 7), 'active', $now, $now]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function signedDoc(array $payload): array
    {
        $sig = base64_encode(sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->keypair)
        ));

        return ['payload' => $payload, 'signature_b64' => $sig];
    }

    private function fixtureLicenseBody(string $installId = ''): string
    {
        $payload = [
            'product' => 'cpms',
            'license_id' => 'lic-priv-1',
            'install_id' => $installId !== '' && preg_match('/^[0-9a-f]{32}$/', $installId) === 1
                ? $installId
                : str_repeat('a', 32),
            'issued_at' => time() - 60,
            'expires_at' => time() + 30 * 86400,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => []],
        ];
        if ($this->keypair === null) {
            return (string) json_encode($payload); // غیرقابل‌تأیید — برای capture کافی است
        }

        return (string) json_encode($this->signedDoc($payload));
    }

    private function fixtureUpdateBody(): string
    {
        return (string) json_encode(['payload' => ['product' => 'cpms'], 'signature_b64' => 'x']);
    }

    private function assertNoSentinelInCaptured(string $label): void
    {
        $this->assertNotSame([], $this->captured, "{$label}: هیچ درخواستی رهگیری نشد");
        foreach ($this->captured as $c) {
            $blob = $c['url'] . "\n" . (string) json_encode($c['headers']) . "\n" . $c['body'];
            $this->assertStringNotContainsString(self::SENTINEL, $blob, "{$label}: sentinel PHI در ترافیک خروجی دیده شد");
        }
    }

    // ================= License outbound =================

    public function testLicenseActivateBodyContainsOnlyAllowlistedMetadataEvenWithPhiInDb(): void
    {
        $this->seedSentinelPhi();
        $gateway = new HttpVendorGateway(['server_url' => 'https://example.com/vendor']);

        // تلاش عمدی برای عبور دادن کلیدهای PHIگونه به‌همراه ابردادهٔ مجاز
        $gateway->activate([
            'install_id' => str_repeat('a', 32),
            'environment' => 'production',
            'license_key' => 'key-x',
            'version' => '1.0.0',
            'wp_version' => '6.7',
            'php_version' => '8.2',
            'domain' => 'clinic.example',
            'patient_name' => self::SENTINEL,
            'notes' => self::SENTINEL,
            'full_row' => self::SENTINEL,
        ]);

        $this->assertNotSame([], $this->captured);
        $body = json_decode($this->captured[0]['body'], true);
        $this->assertIsArray($body);
        $allowed = ['install_id', 'license_id', 'environment', 'license_key', 'version', 'wp_version', 'php_version', 'domain'];
        foreach (array_keys($body) as $k) {
            $this->assertContains($k, $allowed, "کلید خارج از Allowlist در Body: {$k}");
        }
        $this->assertArrayNotHasKey('patient_name', $body);
        $this->assertNoSentinelInCaptured('license activate');
        $this->assertSame(0, preg_match('/SENTINEL/', (string) json_encode($this->captured[0]['headers'])));
    }

    public function testFullActivationThroughLicenseServiceSendsNoPhi(): void
    {
        if ($this->keypair === null) {
            $this->markTestSkipped('sodium not available');
        }
        $this->seedSentinelPhi();
        $db = App::db();
        $service = new LicenseService(
            new LicenseRepository($db),
            new HttpVendorGateway(['server_url' => 'https://example.com/vendor']),
            $db
        );
        $installId = $service->installId();

        $service->activateWithKey('key-sentinel');

        $this->assertNotSame([], $this->captured);
        $body = json_decode($this->captured[0]['body'], true);
        $this->assertIsArray($body);
        $this->assertSame($installId, $body['install_id'] ?? null);
        $this->assertSame([], array_diff(array_keys($body), ['install_id', 'environment', 'license_key', 'version', 'wp_version', 'php_version', 'domain']));
        $this->assertNoSentinelInCaptured('license full activation');
    }

    public function testLicenseRefreshOutboundContainsNoPhi(): void
    {
        $this->seedSentinelPhi();
        $db = App::db();
        $service = new LicenseService(
            new LicenseRepository($db),
            new HttpVendorGateway(['server_url' => 'https://example.com/vendor']),
            $db
        );
        $service->installId();
        // فعال‌سازی اول (capture اول را پاک می‌کنیم)
        $service->activateWithKey('k1');
        $this->captured = [];
        $this->httpFired = false;

        $service->refresh();

        $this->assertNotSame([], $this->captured, 'refresh درخواستی نفرستاد');
        $body = json_decode($this->captured[0]['body'], true);
        $this->assertIsArray($body);
        $this->assertNoSentinelInCaptured('license refresh');
        $allowed = ['install_id', 'license_id', 'environment', 'version'];
        foreach (array_keys($body) as $k) {
            $this->assertContains($k, $allowed);
        }
    }

    // ================= Update outbound =================

    public function testUpdateCheckRequestContainsNoPhi(): void
    {
        $this->seedSentinelPhi();
        $gw = new HttpUpdateMetadataGateway(['server_url' => 'https://example.com/vendor']);

        try {
            $gw->fetch('stable');
        } catch (\Throwable) {
            // پاسخ fixture ناقص است — فقط capture مهم است
        }
        $this->assertNotSame([], $this->captured);
        $this->assertStringContainsString('/updates/manifest?channel=stable', $this->captured[0]['url']);
        $this->assertNoSentinelInCaptured('update check');
    }

    // ================= SSRF (هرگز به HTTP واقعی نرسد) =================

    /**
     * هر Endpoint داخلی/خصوصی/متادیتا باید پیش از هر درخواست HTTP مسدود شود.
     */
    public function testPrivateLoopbackMetadataEndpointsAreBlockedBeforeAnyHttp(): void
    {
        $endpoints = [
            'http://127.0.0.1:8080/activate',
            'https://127.0.0.1/activate',
            'https://169.254.169.254/latest/meta-data/',
            'https://192.168.1.50/activate',
            'https://10.0.0.8/activate',
            'https://[::1]/activate',
        ];
        foreach ($endpoints as $endpoint) {
            $this->captured = [];
            $this->httpFired = false;
            $gateway = new HttpVendorGateway(['server_url' => $endpoint]);

            $blocked = false;
            try {
                $gateway->activate(['install_id' => str_repeat('a', 32)]);
            } catch (\Throwable $e) {
                $blocked = true;
                $code = $e instanceof SmsSendException || $e instanceof \ClinicCore\Infrastructure\Licensing\LicenseGatewayException
                    ? $e->apiCode()
                    : get_class($e);
                $this->assertNotSame('', (string) $code);
            }
            $this->assertTrue($blocked, "{$endpoint} باید مسدود می‌شد");
            $this->assertFalse($this->httpFired, "{$endpoint}: درخواست HTTP واقعی شلیک شد (SSRF)");
            $this->assertSame([], $this->captured);
        }
    }

    public function testUpdateEndpointPrivateIpIsBlocked(): void
    {
        $this->httpFired = false;
        $gw = new HttpUpdateMetadataGateway(['server_url' => 'https://10.1.2.3']);
        $blocked = false;
        try {
            $gw->fetch('stable');
        } catch (\Throwable) {
            $blocked = true;
        }
        $this->assertTrue($blocked);
        $this->assertFalse($this->httpFired);
    }
}
