<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Frontend\LicenseStatusNotice;
use PHPUnit\Framework\TestCase;

final class LicenseStatusNoticeTest extends TestCase
{
    public function testOnlyRelevantStatesProduceScopedCopy(): void
    {
        self::assertNull(LicenseStatusNotice::text(['status' => 'active']));
        $grace = LicenseStatusNotice::text(['status' => 'grace']);
        self::assertStringContainsString('نوبت', $grace);
        $expired = LicenseStatusNotice::text(['status' => 'restricted', 'reason' => 'expired']);
        self::assertStringContainsString('بازیابی', $expired);
        $invalid = LicenseStatusNotice::text(['status' => 'restricted', 'reason' => 'signature_invalid']);
        self::assertStringNotContainsString('مهلت', $invalid);
        self::assertStringNotContainsString('secret', $invalid);
    }
}
