<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Infrastructure\Licensing\LicenseGatewayException;
use ClinicCore\Infrastructure\Licensing\VendorGateway;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use WP_UnitTestCase;

/**
 * F10 — چرخه‌ی حیات لایسنس روی مسیر واقعی تولید (App::licenseService ←
 * LicenseRepository ← MySQL) با Gateway جعلیِ امضاشده (سرویس مرکزی خارج از
 * repo است؛ Client در این‌جا تست می‌شود — ADR-0023/ADR-0028).
 *
 * فقط PHP رسمی با sodium در CI اجرا می‌شود؛ بدون sodium = skip (امضا ممکن نیست).
 */
final class LicenseLifecycleTest extends WP_UnitTestCase
{
    /** @var string|null کلید امضا برای سندهای جعلی */
    public ?string $keypair = null;

    /** رفتار Gateway جعلی برای سناریوهای مختلف */
    public string $mode = 'ok';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available — signature tests need real sodium');
        }
        $this->keypair = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($this->keypair));
        add_filter('cpms_license_public_key', static fn (): string => $pub);
    }

    protected function tearDown(): void
    {
        remove_all_filters('cpms_license_public_key');
        parent::tearDown();
    }

    private function gateway(): VendorGateway
    {
        return new class($this) implements VendorGateway {
            public function __construct(private readonly LicenseLifecycleTest $t)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function activate(array $request): array
            {
                return $this->docFor($request);
            }

            public function refresh(array $request): array
            {
                switch ($this->t->mode) {
                    case 'retryable':
                        throw new LicenseGatewayException('down', true, 'CLINIC_LICENSE_SERVER_ERROR');
                    case 'permanent':
                        throw new LicenseGatewayException('rejected', false, 'CLINIC_LICENSE_ACTIVATION_FAILED');
                    default:
                        return $this->docFor($request);
                }
            }

            /**
             * پاسخِ امضاشده با توجه به mode — برای activate و refresh مشترک است
             * تا هر دو مسیر دقیقاً همان سندهای دستکاری‌شده/ناسازگار را ببینند.
             *
             * @param array<string, mixed> $request
             *
             * @return array{payload: array<string, mixed>, signature_b64: string}
             */
            private function docFor(array $request): array
            {
                switch ($this->t->mode) {
                    case 'expired':
                        return $this->doc($request, -10 * 86400);
                    case 'revoked':
                        return $this->doc($request, 30 * 86400, revoked: true);
                    case 'suspended':
                        return $this->doc($request, 30 * 86400, suspended: true);
                    case 'wrong_install':
                        return $this->doc($request, 30 * 86400, overrideInstall: 'deadbeef' . str_repeat('0', 24));
                    case 'tampered':
                        // سندِ امضاشده، سپس تغییر بعد از امضا (تقلب در payload)
                        $out = $this->doc($request, 30 * 86400);
                        $out['payload']['expires_at'] = $out['payload']['expires_at'] + 86400 * 365;

                        return $out;
                    default:
                        return $this->doc($request, 30 * 86400);
                }
            }

            /**
             * @param array<string, mixed> $request
             *
             * @return array{payload: array<string, mixed>, signature_b64: string}
             */
            private function doc(array $request, int $offsetSec, bool $revoked = false, bool $suspended = false, string $overrideInstall = ''): array
            {
                $expires = time() + $offsetSec;
                $payload = [
                    'product' => 'cpms',
                    'license_id' => 'lic-test-001',
                    'install_id' => $overrideInstall !== '' ? $overrideInstall : (string) ($request['install_id'] ?? ''),
                    // issued_at همیشه قبل از expires_at (سندِ منقضی هم این اصل را دارد)
                    'issued_at' => min(time() - 60, $expires - 1),
                    'expires_at' => $expires,
                    'revoked' => $revoked,
                    'suspended' => $suspended,
                    'plan' => 'test',
                    'entitlements' => [
                        'features' => ['handwriting' => true, 'ocr' => false],
                        'limits' => ['doctors' => 5],
                    ],
                ];
                $msg = LicenseSignature::canonicalJson($payload);
                $sig = sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($this->t->keypair));

                return ['payload' => $payload, 'signature_b64' => base64_encode($sig)];
            }
        };
    }

    private function service(): LicenseService
    {
        return new LicenseService(
            new LicenseRepository(App::db()),
            $this->gateway(),
            App::db()
        );
    }

    public function testFreshInstallIsNotConfiguredAndOpen(): void
    {
        $svc = $this->service();
        $state = $svc->currentState();
        $this->assertSame(LicenseStatus::NOT_CONFIGURED, $state['status']);

        $gate = new SignedLicenseGate($svc);
        $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertFalse($gate->isReadOnly());

        // شناسه‌ی نصب: آنتروپی بالا، 32 hex
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $svc->installId());
    }

    public function testActivationStoresVerifiedDocAndGrantsActive(): void
    {
        $svc = $this->service();
        $meta = $svc->activateWithKey('key-123');
        $this->assertTrue($meta['configured']);
        $this->assertSame(LicenseStatus::ACTIVE, $meta['status']);

        $this->assertSame(LicenseStatus::ACTIVE, $svc->currentState()['status']);
        $this->assertTrue($svc->entitlements()->hasFeature('handwriting'));
        $this->assertFalse($svc->entitlements()->hasFeature('ocr'));

        // Gate واقعی روی Provider واقعی
        $gate = new SignedLicenseGate($svc);
        $this->assertTrue($gate->assert(LicenseGate::OP_INVOICE_CREATE)->allowed);
        $this->assertFalse($gate->isReadOnly());
    }

    public function testTamperedSignatureRejectedAndNothingStored(): void
    {
        $this->mode = 'tampered'; // سندِ امضاشده، سپس expires_at دستکاری شده بعد از امضا
        $svc = $this->service();
        try {
            $svc->activateWithKey('key-tamper');
            $this->fail('انتظار CLINIC_LICENSE_INVALID بود');
        } catch (LicenseGatewayException $e) {
            $this->assertSame('CLINIC_LICENSE_INVALID', $e->apiCode());
        }
        $this->assertSame(LicenseStatus::NOT_CONFIGURED, $svc->currentState()['status']);
    }

    public function testWrongInstallIdRejected(): void
    {
        $this->mode = 'wrong_install';
        $svc = $this->service();
        try {
            $svc->activateWithKey('key-x');
            $this->fail('انتظار رد نصب دیگر بود');
        } catch (LicenseGatewayException $e) {
            $this->assertSame('CLINIC_LICENSE_INVALID', $e->apiCode());
        }
        $this->assertSame(LicenseStatus::NOT_CONFIGURED, $svc->currentState()['status']);
    }

    public function testRevokedDocumentRestrictsNewBusiness(): void
    {
        $svc = $this->service();
        $svc->activateWithKey('k');
        $this->mode = 'revoked';
        $svc->refresh();

        $this->assertSame(LicenseStatus::REVOKED, $svc->currentState()['status']);
        $gate = new SignedLicenseGate($svc);
        $this->assertFalse($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_CANCEL)->allowed);
        $this->assertTrue($gate->assert(LicenseGate::OP_PATIENT_UPDATE)->allowed);
        $this->assertTrue($gate->isReadOnly());
    }

    public function testRetryableFailureKeepsCachedDocOperating(): void
    {
        // سند ۳۰ روزه معتبر؛ سرور قطع → refresh شکست می‌خورد ولی وضعیت از سند
        // کش‌شده ادامه می‌یابد (قطع شبکه ≠ نامعتبر — spec §15)
        $svc = $this->service();
        $svc->activateWithKey('k');
        $this->mode = 'retryable';

        try {
            $svc->refresh();
            $this->fail('انتظار خطای Retryable بود');
        } catch (LicenseGatewayException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame('CLINIC_LICENSE_SERVER_ERROR', $e->apiCode());
        }

        $this->assertSame(LicenseStatus::ACTIVE, $svc->currentState()['status']);
        $gate = new SignedLicenseGate($svc);
        $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);

        $meta = $svc->statusMeta();
        $this->assertGreaterThanOrEqual(1, $meta['refresh_fail_count']);
        $this->assertNotEmpty($meta['last_refresh_error']);
    }

    public function testExpiredBeyondGraceRestrictsButKeepsHistoryOpen(): void
    {
        $svc = $this->service();
        $svc->activateWithKey('k');
        $this->mode = 'expired'; // expires_at = now - 10d (خارج از GRACE ۷ روزه)
        $svc->refresh();

        $state = $svc->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);

        $gate = new SignedLicenseGate($svc);
        // فعالیت جدید مسدود
        $this->assertFalse($gate->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertFalse($gate->assert(LicenseGate::OP_PATIENT_CREATE)->allowed);
        $this->assertFalse($gate->assert(LicenseGate::OP_INVOICE_CREATE)->allowed);
        // بهداشت/تکمیل/به‌روزرسانی مجاز (spec §16)
        $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_CANCEL)->allowed);
        $this->assertTrue($gate->assert(LicenseGate::OP_PATIENT_UPDATE)->allowed);
        $this->assertTrue($gate->isReadOnly());
    }

    public function testManualOfflineDocumentActivationWorks(): void
    {
        // فعال‌سازی دستی/آفلاین (بدون سرور پیکربندی‌شده) — با سند امضاشده
        $svc = $this->service();
        $installId = $svc->installId();
        $payload = [
            'product' => 'cpms',
            'license_id' => 'lic-offline-1',
            'install_id' => $installId,
            'issued_at' => time() - 60,
            'expires_at' => time() + 86400 * 60,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => ['handwriting' => true]],
        ];
        $sig = base64_encode(sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->keypair)
        ));

        $svc->activateWithDocument(LicenseSignature::canonicalJson($payload), $sig);
        $this->assertSame(LicenseStatus::ACTIVE, $svc->currentState()['status']);
        $this->assertTrue($svc->statusMeta()['configured']);
    }

    public function testRefreshDueOnlyWhenNeeded(): void
    {
        $svc = $this->service();
        // بدون state → Due نیست
        $this->assertFalse($svc->refreshDue());

        // فعال با سند دور → تا ۲۴ ساعت Due نیست
        $svc->activateWithKey('k');
        $this->assertFalse($svc->refreshDue());

        // سند نزدیک انقضا (نیازمند تمدید) → Due
        // (از طریق activateWithDocument با سند ۲ روزه)
        $installId = $svc->installId();
        $payload = [
            'product' => 'cpms',
            'license_id' => 'lic-near',
            'install_id' => $installId,
            'issued_at' => time() - 60,
            'expires_at' => time() + 2 * 86400,
            'revoked' => false,
            'suspended' => false,
        ];
        $sig = base64_encode(sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->keypair)
        ));
        $svc->activateWithDocument(LicenseSignature::canonicalJson($payload), $sig);
        $this->assertTrue($svc->refreshDue());
    }

    public function testPermanentFailureDoesNotChangeCachedStatusUntilSignedVerdict(): void
    {
        // خطای غیرمرتبط با امضا (مثلاً کلید رد) — وضعیت کش‌شده حفظ می‌شود
        $svc = $this->service();
        $svc->activateWithKey('k');
        $this->mode = 'permanent';
        try {
            $svc->refresh();
            $this->fail('انتظار خطای Permanent بود');
        } catch (LicenseGatewayException $e) {
            $this->assertFalse($e->retryable);
        }
        $this->assertSame(LicenseStatus::ACTIVE, $svc->currentState()['status']);
    }
}
