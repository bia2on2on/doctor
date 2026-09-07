<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use WP_UnitTestCase;

/**
 * ADR-0030 / Part 1 — Override مدیریتی Capabilities نقش‌ها (ممیزی P2).
 *
 * مشکل: Self-healing (TP-10) هر cpms_* خارج از Template کلاس را بی‌صدا حذف
 * می‌کرد — یعنی اعطای عمدی ادمین (مثلاً cpms_export به منشی) در همان رفرش
 * بعدی پاک می‌شد و هیچ راه مدیریت‌شده‌ای برای تغییر دسترسی نقش وجود نداشت.
 *
 * راه‌حل: Override ثبت‌شده (OPTION_OVERRIDE) مبنای Self-healing می‌شود؛
 * خارج از آن همچنان Least Privilege برقرار است.
 */
final class RoleCapabilitiesOverrideTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        RolesAndCapabilities::register();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        delete_option(RolesAndCapabilities::OPTION_OVERRIDE);
        RolesAndCapabilities::register();
    }

    public function testDeliberateOverrideSurvivesSelfHealing(): void
    {
        $caps = RolesAndCapabilities::SECRETARY_CAPS;
        $caps[] = RolesAndCapabilities::EXPORT; // اعطای عمدی توسط ادمین
        $this->assertTrue(RolesAndCapabilities::setRoleCaps(RolesAndCapabilities::ROLE_SECRETARY, $caps));

        $secretary = get_role(RolesAndCapabilities::ROLE_SECRETARY);
        $this->assertNotFalse($secretary);
        $this->assertTrue($secretary->has_cap(RolesAndCapabilities::EXPORT));

        // Drift دستی همچنان پاک می‌شود، ولی Override نمی‌سوزد:
        $secretary->add_cap('cpms_drift_probe');
        RolesAndCapabilities::register();

        $this->assertTrue($secretary->has_cap(RolesAndCapabilities::EXPORT), 'Override عمدی ادمین نباید حذف شود');
        $this->assertFalse($secretary->has_cap('cpms_drift_probe'), 'Least Privilege برای خارج از فهرست برقرار است');
    }

    public function testSetRoleCapsFiltersUnknownCapsAndPersistsOverride(): void
    {
        $caps = array_merge(RolesAndCapabilities::SECRETARY_CAPS, [
            RolesAndCapabilities::REPORT_READ,
            'totally_bogus_cap', // باید فیلتر شود
        ]);
        $this->assertTrue(RolesAndCapabilities::setRoleCaps(RolesAndCapabilities::ROLE_SECRETARY, $caps));

        $stored = get_option(RolesAndCapabilities::OPTION_OVERRIDE);
        $this->assertIsArray($stored);
        $this->assertArrayHasKey(RolesAndCapabilities::ROLE_SECRETARY, $stored);
        $this->assertContains(RolesAndCapabilities::REPORT_READ, $stored[RolesAndCapabilities::ROLE_SECRETARY]);
        $this->assertNotContains('totally_bogus_cap', $stored[RolesAndCapabilities::ROLE_SECRETARY]);

        $secretary = get_role(RolesAndCapabilities::ROLE_SECRETARY);
        $this->assertTrue($secretary->has_cap(RolesAndCapabilities::REPORT_READ));
    }

    public function testSettingDefaultsRemovesOverrideAndRevokedCapIsEnforced(): void
    {
        $revoked = array_values(array_diff(RolesAndCapabilities::SECRETARY_CAPS, [RolesAndCapabilities::PAYMENT_REFUND]));
        $this->assertTrue(RolesAndCapabilities::setRoleCaps(RolesAndCapabilities::ROLE_SECRETARY, $revoked));

        $secretary = get_role(RolesAndCapabilities::ROLE_SECRETARY);
        $this->assertFalse($secretary->has_cap(RolesAndCapabilities::PAYMENT_REFUND), 'حذف عمدی باید اعمال شود');

        // بازگرداندن کامل به پیش‌فرض → Override حذف می‌شود (Semantics «پیش‌فرض»)
        $this->assertTrue(RolesAndCapabilities::setRoleCaps(
            RolesAndCapabilities::ROLE_SECRETARY,
            RolesAndCapabilities::SECRETARY_CAPS
        ));
        $stored = get_option(RolesAndCapabilities::OPTION_OVERRIDE);
        $this->assertIsArray($stored);
        $this->assertArrayNotHasKey(RolesAndCapabilities::ROLE_SECRETARY, $stored);
        $this->assertTrue($secretary->has_cap(RolesAndCapabilities::PAYMENT_REFUND));
    }

    public function testPatientRoleAndUnknownRoleAreRejected(): void
    {
        // P-5: بیمار فقط Ownership — بدون Cap و بدون Override
        $this->assertFalse(RolesAndCapabilities::setRoleCaps(RolesAndCapabilities::ROLE_PATIENT, [
            RolesAndCapabilities::EXPORT,
        ]));

        $this->assertFalse(RolesAndCapabilities::setRoleCaps('editor', [
            RolesAndCapabilities::EXPORT,
        ]));

        $patient = get_role(RolesAndCapabilities::ROLE_PATIENT);
        $this->assertNotFalse($patient);
        $this->assertFalse($patient->has_cap(RolesAndCapabilities::EXPORT));
    }

    public function testCapsMapFallsBackToTemplateWhenNoOverride(): void
    {
        delete_option(RolesAndCapabilities::OPTION_OVERRIDE);
        $map = RolesAndCapabilities::capsMap(RolesAndCapabilities::ROLE_DOCTOR);
        foreach (RolesAndCapabilities::DOCTOR_CAPS as $cap) {
            $this->assertArrayHasKey($cap, $map);
        }
        $this->assertCount(count(RolesAndCapabilities::DOCTOR_CAPS), $map);
    }
}
