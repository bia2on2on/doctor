<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Domain\Licensing\LicenseStatus;
use PHPUnit\Framework\TestCase;

/**
 * F10 — حالت توسعه/تست صریح (تصمیم کارفرما):
 * فقط مکانیسم مستند (ثابت CPMS_DEV_MODE / فیلتر cpms_license_dev_mode)؛
 * در محیط خالصِ بدون هیچ‌کدام، هرگز خودکار روشن نمی‌شود (هیچ تشخیص
 * محیط/دامنه/localhost وجود ندارد و هیچ unlock مخفی در package نیست).
 */
final class LicenseServiceDevModeTest extends TestCase
{
    public function testDevModeIsOffByDefaultInPureEnvironment(): void
    {
        $this->assertFalse(LicenseService::devModeEnabled());
    }

    public function testPreActivationStatusesAreNotHiddenDevUnlock(): void
    {
        // ACTIVATION_* وضعیت‌های «پیش از فعال‌سازی» با مهلت‌اند (نه unlock).
        // DEVELOPMENT جدا از PRE_ACTIVATION است تا با فعال‌سازی اشتباه نشود.
        $this->assertNotContains(
            LicenseStatus::DEVELOPMENT,
            LicenseStatus::PRE_ACTIVATION
        );
    }
}
