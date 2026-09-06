<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Application\Update\UpdateService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use ClinicCore\Infrastructure\Update\UpdateMetadataGateway;
use WP_UnitTestCase;

/**
 * F10 — سرویس به‌روزرسانی امن (ADR-0029): entitlement (feature `updates`)
 * + ارزیابی مانیفست امضاشده روی مسیر واقعی (MySQL). بدون شبکه — Gateway
 * جعلی/Fixture. در CI اجرا می‌شود.
 */
final class UpdateServiceTest extends WP_UnitTestCase
{
    /** @var string|null */
    private ?string $licenseKp = null;

    /** @var string|null */
    private ?string $releaseKp = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available');
        }
        $this->licenseKp = sodium_crypto_sign_keypair();
        $this->releaseKp = sodium_crypto_sign_keypair();
        add_filter('cpms_license_public_key', fn (): string => base64_encode(sodium_crypto_sign_publickey($this->licenseKp)));
        add_filter('cpms_release_public_key', fn (): string => base64_encode(sodium_crypto_sign_publickey($this->releaseKp)));
    }

    protected function tearDown(): void
    {
        remove_all_filters('cpms_license_public_key');
        remove_all_filters('cpms_release_public_key');
        parent::tearDown();
    }

    private function licenseService(): LicenseService
    {
        // Gateway هرگز صدا زده نمی‌شود (فعال‌سازی دستی) — Stub امن
        $stub = new class implements \ClinicCore\Infrastructure\Licensing\VendorGateway {
            public function isConfigured(): bool
            {
                return false;
            }

            public function activate(array $request): array
            {
                throw new \RuntimeException('stub gateway must not be called');
            }

            public function refresh(array $request): array
            {
                throw new \RuntimeException('stub gateway must not be called');
            }
        };

        return new LicenseService(new LicenseRepository(App::db()), $stub, App::db());
    }

    /**
     * @param array<string, bool> $features
     */
    private function activateWithFeatures(array $features): void
    {
        $svc = $this->licenseService();
        $installId = $svc->installId();
        $payload = [
            'product' => 'cpms',
            'license_id' => 'lic-upd-1',
            'install_id' => $installId,
            'issued_at' => time() - 60,
            'expires_at' => time() + 86400 * 30,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => $features],
        ];
        $sig = base64_encode(sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->licenseKp)
        ));
        $svc->activateWithDocument(LicenseSignature::canonicalJson($payload), $sig);
    }

    /**
     * @return array<string, mixed>
     */
    private function releasePayload(string $version = '9.9.9'): array
    {
        return [
            'product' => 'cpms',
            'version' => $version,
            'channel' => 'stable',
            'package_url' => 'https://updates.example.com/cpms-' . $version . '.zip',
            'package_sha256' => str_repeat('d', 64),
            'signed_at' => time(),
        ];
    }

    private function serviceWithGateway(?array $fixture = null, bool $configured = true): UpdateService
    {
        $gateway = new class($fixture, $configured) implements UpdateMetadataGateway {
            public function __construct(private readonly ?array $fixture, private readonly bool $configured)
            {
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function fetch(string $channel): array
            {
                if ($this->fixture === null) {
                    throw new \RuntimeException('no fixture');
                }

                return $this->fixture;
            }
        };

        return new UpdateService(App::settings(), $this->licenseService(), $gateway);
    }

    public function testUnactivatedInstallIsUpdateEntitled(): void
    {
        // قبل از فعال‌سازی (محیط توسعه/CI) به‌روزرسانی آزاد است
        $this->assertTrue($this->serviceWithGateway()->isUpdateEntitled());
    }

    public function testActivatedWithoutUpdatesFeatureIsNotEntitled(): void
    {
        $this->activateWithFeatures(['handwriting' => true]); // بدون updates
        $this->assertFalse($this->serviceWithGateway()->isUpdateEntitled());
    }

    public function testActivatedWithUpdatesFeatureIsEntitled(): void
    {
        $this->activateWithFeatures(['updates' => true]);
        $this->assertTrue($this->serviceWithGateway()->isUpdateEntitled());
    }

    public function testEvaluateValidSignedManifestAvailable(): void
    {
        $this->activateWithFeatures(['updates' => true]);
        $payload = $this->releasePayload();
        $sig = base64_encode(sodium_crypto_sign_detached(
            \ClinicCore\Domain\Update\ReleaseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->releaseKp)
        ));

        $svc = $this->serviceWithGateway();
        $result = $svc->evaluateManifest($payload, $sig, 'stable', '1.0.0', '6.7', '8.2');
        $this->assertTrue($result['available']);
        $this->assertSame('9.9.9', $result['version']);
        $this->assertSame(str_repeat('d', 64), $result['package_sha256']);
    }

    public function testEvaluateRejectsWrongReleaseKeySignature(): void
    {
        $this->activateWithFeatures(['updates' => true]);
        $payload = $this->releasePayload();
        // امضاشده با کلید مجوز (کلید اشتباه برای انتشار)
        $sig = base64_encode(sodium_crypto_sign_detached(
            \ClinicCore\Domain\Update\ReleaseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->licenseKp)
        ));

        $svc = $this->serviceWithGateway();
        $result = $svc->evaluateManifest($payload, $sig, 'stable', '1.0.0', '6.7', '8.2');
        $this->assertFalse($result['available']);
        $this->assertSame('invalid_signature', $result['reason']);
    }

    public function testCheckForUpdatesNotConfiguredWithoutNetwork(): void
    {
        $this->activateWithFeatures(['updates' => true]);
        $svc = $this->serviceWithGateway(configured: false);
        $result = $svc->checkForUpdates(force: true);
        $this->assertFalse($result['available']);
        $this->assertSame('not_configured', $result['reason']);
    }

    public function testCheckForUpdatesWithFixtureGateway(): void
    {
        $this->activateWithFeatures(['updates' => true]);
        $payload = $this->releasePayload();
        $sig = base64_encode(sodium_crypto_sign_detached(
            \ClinicCore\Domain\Update\ReleaseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->releaseKp)
        ));
        $svc = $this->serviceWithGateway(['payload' => $payload, 'signature_b64' => $sig]);
        $result = $svc->checkForUpdates(force: true);
        $this->assertTrue($result['available']);
        $this->assertSame('9.9.9', $result['version']);
    }
}
