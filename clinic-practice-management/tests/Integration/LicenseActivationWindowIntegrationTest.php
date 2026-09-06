<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use WP_UnitTestCase;

/**
 * F10 — سیاست پنجرهٔ فعال‌سازی (تصمیم کارفرما) روی مسیر واقعی:
 *  - نصب تازه: ACTIVATION_PENDING تا ۷ روز → بدون سند = RESTRICTED
 *  - نصب pre-F10: ACTIVATION_GRACE تا ۳۰ روز → بدون سند = RESTRICTED
 *  - فعال‌سازی موفق در پنجره/آفلاین امضاشده
 *  - anti-reset: deactivate/reactivate/reinstall پنجره را از نو شروع نمی‌کند
 *  - dev/test فقط صریح (فیلتر/ثابت مستند)؛ بدون bypass خودکار
 * فقط در CI (MySQL + WP + sodium) اجرا می‌شود.
 */
final class LicenseActivationWindowIntegrationTest extends WP_UnitTestCase
{
    private ?string $keypair = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available');
        }
        $this->keypair = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($this->keypair));
        add_filter('cpms_license_public_key', static fn (): string => $pub);
    }

    protected function tearDown(): void
    {
        remove_all_filters('cpms_license_public_key');
        remove_all_filters('cpms_license_dev_mode');
        parent::tearDown();
    }

    private function service(): LicenseService
    {
        return App::licenseService();
    }

    private function setWindow(int $startTs, string $type): void
    {
        App::db()->update(
            'cpms_license_install',
            [
                'activation_window_started_at' => gmdate('Y-m-d H:i:s', $startTs),
                'activation_window_type' => $type,
                'updated_at' => App::db()->nowUtcSql(),
            ],
            ['id' => 1]
        );
    }

    private function gate(): SignedLicenseGate
    {
        return new SignedLicenseGate($this->service());
    }

    public function testFreshInstallDayZeroIsPendingAndOpen(): void
    {
        $this->setWindow(time(), 'fresh');
        $this->assertSame(LicenseStatus::ACTIVATION_PENDING, $this->service()->currentState()['status']);
        $this->assertTrue($this->gate()->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertFalse($this->gate()->isReadOnly());
    }

    public function testFreshInstallInsideSevenDaysPermitted(): void
    {
        $this->setWindow(time() - 6 * 86400, 'fresh');
        $this->assertSame(LicenseStatus::ACTIVATION_PENDING, $this->service()->currentState()['status']);
        $this->assertTrue($this->gate()->assert(LicenseGate::OP_PATIENT_CREATE)->allowed);
    }

    public function testFreshInstallAfterSevenDaysWithoutLicenseIsRestricted(): void
    {
        $this->setWindow(time() - 8 * 86400, 'fresh');
        $state = $this->service()->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
        $this->assertSame('activation_window_expired', $state['reason']);

        $g = $this->gate();
        $this->assertFalse($g->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertFalse($g->assert(LicenseGate::OP_INVOICE_CREATE)->allowed);
        // ایمنی/تاریخچه/بهداشت هرگز مسدود نمی‌شود
        $this->assertTrue($g->assert(LicenseGate::OP_PATIENT_UPDATE)->allowed);
        $this->assertTrue($g->assert(LicenseGate::OP_APPOINTMENT_CANCEL)->allowed);
        $this->assertTrue($g->isReadOnly());
    }

    public function testPreF10MigrationBeforeDay30IsActivationGrace(): void
    {
        $this->setWindow(time() - 29 * 86400, 'migration');
        $state = $this->service()->currentState();
        $this->assertSame(LicenseStatus::ACTIVATION_GRACE, $state['status']);
        $this->assertSame('migration_grace', $state['reason']);
        $this->assertTrue($this->gate()->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
    }

    public function testPreF10MigrationAfterDay30WithoutLicenseIsRestricted(): void
    {
        $this->setWindow(time() - 31 * 86400, 'migration');
        $state = $this->service()->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
        $this->assertSame('migration_grace_expired', $state['reason']);
        $this->assertFalse($this->gate()->assert(LicenseGate::OP_VISIT_CHECKIN)->allowed);
    }

    public function testSuccessfulActivationDuringWindowBecomesActive(): void
    {
        $this->setWindow(time() - 3 * 86400, 'fresh');
        $installId = $this->service()->installId();
        $payload = [
            'product' => 'cpms',
            'license_id' => 'lic-window-1',
            'install_id' => $installId,
            'issued_at' => time() - 60,
            'expires_at' => time() + 90 * 86400,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => ['updates' => true]],
        ];
        $sig = base64_encode(sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->keypair)
        ));
        $this->service()->activateWithDocument(LicenseSignature::canonicalJson($payload), $sig);

        $state = $this->service()->currentState();
        $this->assertSame(LicenseStatus::ACTIVE, $state['status']);
        $this->assertTrue($this->gate()->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
    }

    public function testSignedOfflineActivationWorks(): void
    {
        // offline activation بدون سرور پیکربندی‌شده — با سند امضاشدهٔ معتبر
        $installId = $this->service()->installId();
        $payload = [
            'product' => 'cpms',
            'license_id' => 'lic-offline-win',
            'install_id' => $installId,
            'issued_at' => time() - 60,
            'expires_at' => time() + 60 * 86400,
            'revoked' => false,
            'suspended' => false,
        ];
        $sig = base64_encode(sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->keypair)
        ));
        $this->service()->activateWithDocument(LicenseSignature::canonicalJson($payload), $sig);

        $this->assertSame(LicenseStatus::ACTIVE, $this->service()->currentState()['status']);
    }

    public function testDeactivateReactivateDoesNotResetWindow(): void
    {
        $this->setWindow(time() - 5 * 86400, 'fresh');
        $before = App::db()->fetchValue(
            'SELECT activation_window_started_at FROM ' . App::db()->table('cpms_license_install') . ' WHERE id = 1'
        );

        App::deactivate();
        App::activate();
        App::migrations()->migrate(); // reinstall-path: data باقی است → migrate دوباره

        $after = App::db()->fetchValue(
            'SELECT activation_window_started_at FROM ' . App::db()->table('cpms_license_install') . ' WHERE id = 1'
        );
        $this->assertSame($before, $after);
        $this->assertSame(LicenseStatus::ACTIVATION_PENDING, $this->service()->currentState()['status']);
    }

    public function testReinstallKeepsWindowWhenCpmsDataRemains(): void
    {
        $this->setWindow(time() - 6 * 86400, 'fresh');
        $before = (string) App::db()->fetchValue(
            'SELECT activation_window_started_at FROM ' . App::db()->table('cpms_license_install') . ' WHERE id = 1'
        );
        // شبیه‌سازی اجرای دوبارهٔ migration (داده‌ی CPMS باقی است)
        App::migrations()->migrate();
        $after = (string) App::db()->fetchValue(
            'SELECT activation_window_started_at FROM ' . App::db()->table('cpms_license_install') . ' WHERE id = 1'
        );
        $this->assertSame($before, $after);
    }

    public function testDevModeIsExplicitOnlyAndOverridesExpiredWindow(): void
    {
        // بدون filter/constant: پنجرهٔ منقضی → RESTRICTED (هیچ bypass خودکار)
        $this->setWindow(time() - 100 * 86400, 'fresh');
        $this->assertSame(LicenseStatus::RESTRICTED, $this->service()->currentState()['status']);

        // با مکانیسم صریح (فیلتر مستند) → DEVELOPMENT و فعالیت مجاز
        add_filter('cpms_license_dev_mode', '__return_true');
        $state = $this->service()->currentState();
        $this->assertSame(LicenseStatus::DEVELOPMENT, $state['status']);
        $this->assertSame('dev_mode', $state['reason']);
        $this->assertTrue($this->gate()->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
    }

    public function testNoHiddenProductionDevBypassByDefault(): void
    {
        // در محیط تستِ WP، مگر فیلتر/ثابت صریح نباشد DEVELOPMENT هرگز نمی‌آید
        $this->assertNotSame(LicenseStatus::DEVELOPMENT, $this->service()->currentState()['status']);
    }
}
