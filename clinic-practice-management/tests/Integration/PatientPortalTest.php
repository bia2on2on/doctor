<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_UnitTestCase;

/**
 * ADR-0030 / Part 1 — مقصد بیمار بعد از ورود OTP (ممیزی P5).
 *
 *  - بیمارِ خالص (cpms_patient بدون نقش ستادی) → «نوبت‌های من» + Admin Bar مخفی.
 *  - کاربران ستادی (پزشک/منشی) unaffected.
 *  - Login Redirect فقط برای بیمارِ خالص.
 */
final class PatientPortalTest extends WP_UnitTestCase
{
    private int $patientUserId;
    private int $doctorUserId;
    private int $secretaryUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patientUserId = $this->makeUser('pp_patient', RolesAndCapabilities::ROLE_PATIENT);
        $this->doctorUserId = $this->makeUser('pp_doctor', RolesAndCapabilities::ROLE_DOCTOR);
        $this->secretaryUserId = $this->makeUser('pp_secretary', RolesAndCapabilities::ROLE_SECRETARY);
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    public function testPatientOnlyDetection(): void
    {
        $this->assertTrue(PatientPortalPage::isPatientOnly($this->patientUserId));
        $this->assertFalse(PatientPortalPage::isPatientOnly($this->doctorUserId));
        $this->assertFalse(PatientPortalPage::isPatientOnly($this->secretaryUserId));
        $this->assertFalse(PatientPortalPage::isPatientOnly(99999999));

        // Multi-role: بیمار + منشی = ستادی (نه بیمار خالص)
        $multi = get_userdata($this->patientUserId);
        $this->assertNotFalse($multi);
        $multi->add_role(RolesAndCapabilities::ROLE_SECRETARY);
        $this->assertFalse(PatientPortalPage::isPatientOnly($this->patientUserId));
    }

    public function testLoginRedirectSendsPatientToOwnConsole(): void
    {
        $patientUser = get_userdata($this->patientUserId);
        $this->assertNotFalse($patientUser);

        $redirected = apply_filters('login_redirect', 'https://example.org/wp-admin/', '', $patientUser);
        $this->assertStringContainsString('page=cpms-patient', $redirected);

        // پزشک/منشی: بدون تغییر
        $doctorUser = get_userdata($this->doctorUserId);
        $kept = apply_filters('login_redirect', 'https://example.org/wp-admin/', '', $doctorUser);
        $this->assertSame('https://example.org/wp-admin/', $kept);
    }

    public function testAdminBarHiddenOnlyForPurePatients(): void
    {
        wp_set_current_user($this->patientUserId);
        $this->assertFalse(apply_filters('show_admin_bar', true));

        wp_set_current_user($this->secretaryUserId);
        $this->assertTrue(apply_filters('show_admin_bar', true));

        wp_set_current_user(0);
        $this->assertTrue(apply_filters('show_admin_bar', true));
    }
}
