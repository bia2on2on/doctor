<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Chunk B — راه‌اندازی گام‌به‌گام (Setup Wizard).
 *
 * Updated for Phase4 Clinic Profile Canonicalization:
 * - cpms_clinics canonical, not setup.clinic.*
 * - wizard save uses ClinicProfileService with trusted clinic + CONFIG auth
 * - timezone no longer handled here (Location timezone operational truth)
 */
final class SetupWizardTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
    }

    protected function tearDown(): void
    {
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testWizardSubmenuRegisteredUnderCpmsMenu(): void
    {
        $this->authorizeConfigUser();

        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];

        CpmsAdminMenu::menu();
        CpmsSetupWizard::menu();

        $sub = $GLOBALS['submenu']['cpms-dashboard'] ?? [];
        $slugs = array_map(static fn ($r) => $r[2] ?? '', $sub);

        $this->assertContains(CpmsSetupWizard::PAGE_SLUG, $slugs, 'زیرمنوی «راه‌اندازی» باید تحت «مدیریت مطب» ثبت شود');
    }

    public function testSaveClinicPersists(): void
    {
        $adminId = $this->authorizeConfigUser();
        $err = CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_name' => 'کلینیک آزمایشی',
            'clinic_address' => 'خیابان آزادی',
            'clinic_phone' => '02112345678',
            'clinic_timezone' => 'Asia/Tehran',
        ], $adminId);

        $this->assertSame('', $err, 'گام معتبر کلینیک نباید خطا بدهد');
        // Canonical source: cpms_clinics
        $clinic = App::clinicRepository()->find(App::scope()->clinicId);
        $this->assertNotNull($clinic);
        $this->assertSame('کلینیک آزمایشی', $clinic['name']);
        $this->assertSame('خیابان آزادی', $clinic['address']);
        $this->assertSame('02112345678', $clinic['phone']);
        // Timezone preserved (no sync, not edited via wizard)
        $this->assertNotEmpty($clinic['timezone']);
    }

    public function testSaveClinicRejectsEmptyName(): void
    {
        $adminId = $this->authorizeConfigUser();
        $before = App::clinicRepository()->find(App::scope()->clinicId);
        $beforeName = $before['name'] ?? '';

        $err = CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_name' => '   ',
        ], $adminId);

        $this->assertStringContainsString('نام کلینیک الزامی است.', $err);
        $after = App::clinicRepository()->find(App::scope()->clinicId);
        $this->assertSame($beforeName, $after['name'] ?? '', 'نباید مقدار تهی ذخیره شود');
    }

    public function testSaveClinicRejectsOverlongName(): void
    {
        $adminId = $this->authorizeConfigUser();
        $err = CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_name' => str_repeat('ت', 200),
        ], $adminId);

        $this->assertStringContainsString('حداکثر ۱۹۰ کاراکتر', $err);
    }

    public function testSaveClinicFallsBackTimezone(): void
    {
        // Phase4: timezone is NOT handled by wizard anymore — it should be preserved, not fallback to setup.clinic.timezone
        $adminId = $this->authorizeConfigUser();
        $before = App::clinicRepository()->find(App::scope()->clinicId);
        $beforeTz = $before['timezone'] ?? 'Asia/Tehran';

        CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_name' => 'کلینیک',
            'clinic_timezone' => 'invalid/tz',
        ], $adminId);

        $after = App::clinicRepository()->find(App::scope()->clinicId);
        $this->assertSame($beforeTz, $after['timezone'] ?? '', 'timezone should be preserved, not changed via wizard');
        // Historical setup.clinic.timezone should NOT be written as canonical anymore
        // We allow it to be absent or old, but not as second canonical
    }

    public function testSaveBookingBoundsValues(): void
    {
        $adminId = $this->authorizeConfigUser();
        $err = CpmsSetupWizard::saveBooking(App::settings(), [
            'duration' => 9999,
            'future' => -5,
        ], $adminId);

        $this->assertSame('', $err);
        $s = App::settings();
        $this->assertSame(240, (int) $s->get('booking.duration_default_min'));
        $this->assertSame(1, (int) $s->get('booking.max_future_days'));
    }

    public function testSaveSmsPersistsOnlyWhenProvided(): void
    {
        $adminId = $this->authorizeConfigUser();
        CpmsSetupWizard::saveSms(App::settings(), [
            'sms_provider' => 'generic_api',
            'sms_sender' => 'CLINIC',
        ], $adminId);

        $this->assertSame('generic_api', App::settings()->get('sms.provider'));
        $this->assertSame('CLINIC', App::settings()->get('sms.sender'));
    }

    public function testWizardCompletesWhenPrerequisitesMet(): void
    {
        $adminId = $this->authorizeConfigUser();
        $this->resetClinicians();
        $this->createActiveClinician();

        CpmsSetupWizard::saveClinic(App::settings(), ['clinic_name' => 'کلینیک آماده'], $adminId);
        $err = $this->invokeSaveFinish($adminId);

        $this->assertSame('', $err, 'وقتی پیش‌نیازها برقرار است نباید خطا بدهد');
        $this->assertTrue((bool) App::settings()->get(CpmsSetupWizard::COMPLETE_KEY, false), 'باید «آمادهٔ بهره‌برداری» ثبت شود.');
    }

    public function testWizardDoesNotCompleteWithoutClinician(): void
    {
        $adminId = $this->authorizeConfigUser();
        $this->resetClinicians();
        CpmsSetupWizard::saveClinic(App::settings(), ['clinic_name' => 'کلینیک بدون پزشک'], $adminId);

        $err = $this->invokeSaveFinish($adminId);

        $this->assertStringContainsString('پیش‌نیازهای الزامی', $err);
        $this->assertFalse((bool) App::settings()->get(CpmsSetupWizard::COMPLETE_KEY, false));
    }

    public function testWizardDoesNotCompleteWithoutClinicName(): void
    {
        $adminId = $this->authorizeConfigUser();
        $this->resetClinicians();
        $this->createActiveClinician();

        // Make canonical clinic name empty to simulate missing clinic name
        global $wpdb;
        $clinicId = App::scope()->clinicId;
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_clinics SET name = "" WHERE id = %d', $clinicId));

        $err = $this->invokeSaveFinish($adminId);

        $this->assertStringContainsString('پیش‌نیازهای الزامی', $err);
        $this->assertFalse((bool) App::settings()->get(CpmsSetupWizard::COMPLETE_KEY, false));

        // Restore name for other tests
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_clinics SET name = %s WHERE id = %d', 'کلینیک تست', $clinicId));
    }

    // ================= handlers (capability / CSRF) =================

    public function testSaveRejectsInvalidNonce(): void
    {
        $this->authorizeConfigUser();
        $_POST = [
            '_wpnonce' => 'invalid-nonce-value',
            'action'   => 'cpms_wizard_save',
            'step'     => 'clinic',
            'clinic_name' => 'نام',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('اعتبارسنجی ناموفق');
        $this->captureWpDie(function (): void {
            CpmsSetupWizard::save();
        });
    }

    public function testSaveRejectsCapabilitylessUser(): void
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);

        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_save'),
            'action'   => 'cpms_wizard_save',
            'step'     => 'clinic',
            'clinic_name' => 'نام',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('اعتبارسنجی ناموفق');
        $this->captureWpDie(function (): void {
            CpmsSetupWizard::save();
        });
    }

    public function testSaveRejectsInvalidStep(): void
    {
        $this->authorizeConfigUser();
        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_save'),
            'action'   => 'cpms_wizard_save',
            'step'     => 'not-a-step',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('گام نامعتبر');
        $this->captureWpDie(function (): void {
            CpmsSetupWizard::save();
        });
    }

    public function testSaveAdvancesToNextStepOnSuccess(): void
    {
        $this->authorizeConfigUser();
        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_save'),
            'action'   => 'cpms_wizard_save',
            'step'     => 'clinic',
            'clinic_name' => 'کلینیک گام‌به‌گام',
            'clinic_timezone' => 'Asia/Tehran',
        ];

        $location = $this->captureRedirect(function (): void {
            CpmsSetupWizard::save();
        });

        $this->assertStringContainsString('cpms-wizard', $location, 'پس از گام غیرپایانی باید به ویزارد برگردد');
        $this->assertSame('booking', App::settings()->get('setup.current_step'), 'پس از «کلینیک» باید «رزرو» باشد');
    }

    public function testSaveFinishRedirectsToDashboardOnSuccess(): void
    {
        $adminId = $this->authorizeConfigUser();
        $this->createActiveClinician();
        CpmsSetupWizard::saveClinic(App::settings(), ['clinic_name' => 'کلینیک نهایی'], $adminId);

        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_save'),
            'action'   => 'cpms_wizard_save',
            'step'     => 'finish',
        ];

        $location = $this->captureRedirect(function (): void {
            CpmsSetupWizard::save();
        });

        $this->assertStringContainsString('cpms-dashboard', $location, 'پس از تکمیل باید به داشبورد برود');
        $this->assertTrue((bool) App::settings()->get(CpmsSetupWizard::COMPLETE_KEY, false));
    }

    // ================= helpers =================

    private function authorizeConfigUser(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);
        // Phase4: need durable active membership with CONFIG for clinic 1
        $clinicId = 1;
        try {
            $clinicId = App::scope()->clinicId;
        } catch (\Throwable) {
            $clinicId = 1;
        }
        if ($clinicId > 0) {
            cpms_test_seed_membership($id, $clinicId, 'cpms_manager');
        }
        // Ensure scope is set for settings
        try {
            $scope = App::scope();
            App::replaceExplicitScope($scope);
        } catch (\Throwable) {
            // ignore
        }
        return $id;
    }

    private function createActiveClinician(): void
    {
        $clinicId = 1;
        try {
            $clinicId = App::scope()->clinicId;
        } catch (\Throwable) {
            $clinicId = 1;
        }
        App::clinicianRepository()->create($clinicId, ['full_name' => 'دکتر آزمایشی']);
    }

    private function resetClinicians(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_clinicians';
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
        $wpdb->query('DELETE FROM ' . $table);
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
    }

    private function invokeSaveFinish(int $updatedBy): string
    {
        $method = new \ReflectionMethod(CpmsSetupWizard::class, 'saveFinish');
        $method->setAccessible(true);

        return (string) $method->invoke(null, App::settings(), $updatedBy);
    }

    private function captureWpDie(callable $fn): void
    {
        add_filter('wp_die_handler', static function (): callable {
            return static function (string $message): void {
                throw new \RuntimeException($message);
            };
        }, PHP_INT_MAX);
        $fn();
    }

    private function captureRedirect(callable $fn): string
    {
        $location = '';
        add_filter('wp_redirect', static function (string $loc) use (&$location): string {
            $location = $loc;
            throw new \RuntimeException('REDIRECT::' . $loc);
        }, 1);

        try {
            $fn();
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'REDIRECT::')) {
                return $location;
            }
            throw $e;
        }

        return $location;
    }
}
