<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\LicenseDomain;
use PHPUnit\Framework\TestCase;

/**
 * Phase 16 Slice 2 — قاعدهٔ canonicalizationِ دامنه (یک تابعِ مشترک برای
 * دامنهٔ محلی و ادعای دامنهٔ سند):
 *   lower-case + حذفِ `www.` پیشرو — بدون IDN/punycode/path/port.
 * هر مقدارِ غیررشته‌ای/غیرقابل‌استفاده = '' (fail-closed در مقایسه).
 */
final class LicenseDomainTest extends TestCase
{
    public function testLowercasesAndStripsLeadingWwwOnBothSides(): void
    {
        $this->assertSame('clinic.example', LicenseDomain::canonicalize('clinic.example'));
        $this->assertSame('clinic.example', LicenseDomain::canonicalize('CLINIC.Example'));
        $this->assertSame('clinic.example', LicenseDomain::canonicalize('www.clinic.example'));
        $this->assertSame('clinic.example', LicenseDomain::canonicalize('WWW.Clinic.Example'));
        // فقط یک `www.` پیشرو حذف می‌شود (بدون policy اضافه).
        $this->assertSame('www.clinic.example', LicenseDomain::canonicalize('www.www.clinic.example'));
    }

    public function testUnusableValuesCanonicalizeToEmptyString(): void
    {
        $this->assertSame('', LicenseDomain::canonicalize(''));
        $this->assertSame('', LicenseDomain::canonicalize(123));
        $this->assertSame('', LicenseDomain::canonicalize(null));
        $this->assertSame('', LicenseDomain::canonicalize([]));
        $this->assertSame('', LicenseDomain::canonicalize(true));
    }

    public function testNoPunycodePathOrPortPolicyIsApplied(): void
    {
        // این اسلایس عمداً سیاستِ IDN/punycode/path/port اضافه نمی‌کند؛
        // مقادیرِ غیرِ host ساده بی‌تحول می‌مانند و در مقایسه fail-closed می‌شوند.
        $this->assertSame('clinic.example:8443', LicenseDomain::canonicalize('clinic.example:8443'));
        $this->assertSame('clinic.example/admin', LicenseDomain::canonicalize('clinic.example/admin'));
        $this->assertSame('xn--mgba.example', LicenseDomain::canonicalize('XN--MGBA.example'));
    }
}
