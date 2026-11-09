<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\AdminClinicSelector;
use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Application\Scope\ClinicScope;
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
        $this->resetWizardRequestScope();
    }

    protected function tearDown(): void
    {
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        $this->resetWizardRequestScope();
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
            'capacity' => 99,
            'lead' => -5,
            'future' => -5,
            'cancel' => 500,
        ], $adminId);

        $this->assertSame('', $err);
        $s = App::settings();
        $this->assertSame(240, (int) $s->get('booking.duration_default_min'));
        $this->assertSame(20, (int) $s->get('booking.slot_capacity_default'));
        $this->assertSame(1, (int) $s->get('booking.min_lead_hours'));
        $this->assertSame(1, (int) $s->get('booking.max_future_days'));
        $this->assertSame(168, (int) $s->get('booking.cancel_deadline_hours'));
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
        $clinicId = App::scope()->clinicId;
        $timezoneBefore = (string) (App::clinicRepository()->find($clinicId)['timezone'] ?? '');
        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_save'),
            'action'   => 'cpms_wizard_save',
            'step'     => 'clinic',
            'clinic_name' => 'کلینیک گام‌به‌گام',
            'clinic_address' => 'تهران، خیابان آزادی، کوچهٔ سرو',
            'clinic_phone' => '۰۲۱-۱۲۳۴۵۶۷۸',
            'clinic_timezone' => 'Asia/Tehran',
        ];

        $location = $this->captureRedirect(function (): void {
            CpmsSetupWizard::save();
        });

        $this->assertStringContainsString('cpms-wizard', $location, 'پس از گام غیرپایانی باید به ویزارد برگردد');
        $this->assertSame('booking', App::settings()->get('setup.current_step'), 'پس از «کلینیک» باید «رزرو» باشد');
        $clinic = App::clinicRepository()->find(App::scope()->clinicId);
        $this->assertSame('کلینیک گام‌به‌گام', $clinic['name'] ?? '');
        $this->assertSame('تهران، خیابان آزادی، کوچهٔ سرو', $clinic['address'] ?? '');
        $this->assertSame('۰۲۱-۱۲۳۴۵۶۷۸', $clinic['phone'] ?? '');
        $this->assertSame($timezoneBefore, (string) ($clinic['timezone'] ?? ''), 'POST گام کلینیک نباید timezone عملیاتی Location/Clinic را تغییر دهد');
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

    public function testClinicFieldsBelongToSubmittingForm(): void
    {
        $this->authorizeConfigUser();
        App::settings()->set('setup.current_step', 'welcome');

        $html = $this->renderWizardStep('clinic');
        $form = $this->findFormWithHiddenValue($html, 'action', 'cpms_wizard_save');

        $this->assertNotNull($form, 'فرم ذخیرهٔ راه‌اندازی باید رندر شود');
        $this->assertTrue(str_contains((string) $form, 'method="post"'), 'فرم کلینیک باید POST واقعی باشد');
        $this->assertTrue(str_contains((string) $form, 'admin-post.php'), 'فرم کلینیک باید به admin-post ارسال شود');
        $this->assertTrue(str_contains((string) $form, 'name="_wpnonce"'), 'Nonce باید داخل فرم ارسال باشد');
        $this->assertTrue(str_contains((string) $form, 'name="step" value="clinic"'), 'گام باید داخل فرم ارسال باشد');
        foreach (['clinic_name', 'clinic_address', 'clinic_phone'] as $field) {
            $this->assertTrue(str_contains((string) $form, 'name="' . $field . '"'), 'فیلد کلینیک باید مالکیت فرمی داشته باشد');
        }
        $this->assertTrue(str_contains((string) $form, '<button type="submit"'), 'دکمهٔ ارسال باید داخل همان فرم باشد');
    }

    public function testBookingFieldsBelongToSubmittingForm(): void
    {
        $this->authorizeConfigUser();
        App::settings()->set('setup.current_step', 'welcome');

        $html = $this->renderWizardStep('booking');
        $form = $this->findFormWithHiddenValue($html, 'action', 'cpms_wizard_save');

        $this->assertNotNull($form, 'فرم ذخیرهٔ راه‌اندازی باید رندر شود');
        $this->assertTrue(str_contains((string) $form, 'method="post"'), 'فرم رزرو باید POST واقعی باشد');
        $this->assertTrue(str_contains((string) $form, 'admin-post.php'), 'فرم رزرو باید به admin-post ارسال شود');
        $this->assertTrue(str_contains((string) $form, 'name="_wpnonce"'), 'Nonce باید داخل فرم ارسال باشد');
        $this->assertTrue(str_contains((string) $form, 'name="step" value="booking"'), 'گام باید داخل فرم ارسال باشد');
        foreach (['duration', 'capacity', 'lead', 'future', 'cancel'] as $field) {
            $this->assertTrue(str_contains((string) $form, 'name="' . $field . '"'), 'فیلد رزرو باید مالکیت فرمی داشته باشد');
        }
        $this->assertTrue(str_contains((string) $form, '<button type="submit"'), 'دکمهٔ ارسال باید داخل همان فرم باشد');
    }

    public function testAuthorizedClinicConfigUserSeesWizardStepFormForMatchingSettingsScope(): void
    {
        $this->authorizeConfigUser();
        $clinic_id = (int) App::scope()->clinicId;

        $html = $this->renderWizardStep('locations');
        $form = $this->findFormWithHiddenValue($html, 'action', 'cpms_wizard_save');

        self::assertNotNull($form, 'کاربرِ مجازِ Clinic باید فرم گام را ببیند.');
        self::assertStringContainsString('name="step" value="locations"', (string) $form);
        self::assertStringNotContainsString('data-cpms-wizard-access="denied"', $html);
        self::assertSame($clinic_id, (int) App::scope()->clinicId, 'The active scope must remain the Clinic authorized for the request.');
        self::assertSame($clinic_id, App::settings()->clinicId(), 'The rendered Settings instance must use the authorized Clinic scope.');
    }

    public function testExplicitAuthorizedClinicSelectorBindsTheRenderedSettingsClinic(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        $clinic_b = $this->createAdditionalClinic('authorized-selector');
        cpms_test_seed_membership($user_id, $clinic_b, RolesAndCapabilities::ROLE_MANAGER);
        $this->setClinicSetting($clinic_a, 'booking.duration_default_min', 31, $user_id);
        $this->setClinicSetting($clinic_b, 'booking.duration_default_min', 47, $user_id);
        Settings::flushCache();

        $html = $this->renderWizardStep('booking', $clinic_b);
        $form = $this->findFormWithHiddenValue($html, 'action', 'cpms_wizard_save');

        self::assertNotNull(
            $form,
            'An explicitly selected, authorized Clinic must render its ordinary wizard form.'
        );
        self::assertStringContainsString('name="duration"', (string) $form);
        self::assertStringContainsString('value="47"', (string) $form, 'The form must render Clinic B settings, not Clinic A settings.');
        self::assertStringNotContainsString('value="31"', (string) $form);
        self::assertStringContainsString('data-cpms-wizard-clinic="' . $clinic_b . '"', $html);
        self::assertSame($clinic_b, (int) App::scope()->clinicId);
        self::assertSame($clinic_b, App::settings()->clinicId());
        self::assertTrue(App::authorization_service()->can($user_id, $clinic_b, RolesAndCapabilities::CONFIG));
    }

    public function testClinicBFlowAndCompletionUsePersistedSettingsBeforeClinicAMetadata(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;

        // Configure Clinic A through the ordinary wizard first. This leaves
        // A's type/structure in the actor's user-meta, as happens in production.
        self::assertSame('', CpmsSetupWizard::save_facility(App::settings(), [
            'facility_type' => 'doctor_practice',
            'structure'     => 'single',
        ], $user_id));
        self::assertSame('doctor_practice', (string) get_user_meta($user_id, 'cpms_onboarding_facility_type', true));
        self::assertSame('single', (string) get_user_meta($user_id, 'cpms_onboarding_structure', true));

        $clinic_b = $this->createAdditionalClinic('facility-flow-b');
        cpms_test_seed_membership($user_id, $clinic_b, RolesAndCapabilities::ROLE_MANAGER);

        // An explicitly selected Clinic's empty structure means "use its type's
        // default"; it must not inherit Clinic A's user-meta override.
        $this->setClinicSetting($clinic_b, 'setup.facility_type', 'general_clinic', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.facility_structure', '', $user_id);
        $auto_structure_html = $this->renderWizardStep('facility', $clinic_b);
        self::assertStringContainsString('data-cpms-onboarding-flow="multi_doctor_clinic"', $auto_structure_html);
        self::assertSame(1, preg_match('/value="general_clinic"[^>]*checked/', $auto_structure_html));
        self::assertSame(0, preg_match('/value="doctor_practice"[^>]*checked/', $auto_structure_html));

        $keys = [
            'setup.facility_type',
            'setup.facility_structure',
            'setup.outpatient_only_ack',
            'setup.completed_steps',
            CpmsSetupWizard::COMPLETE_KEY,
            'booking.duration_default_min',
            'booking.slot_capacity_default',
            'booking.min_lead_hours',
            'booking.max_future_days',
            'booking.cancel_deadline_hours',
        ];
        $this->setClinicSetting($clinic_b, 'setup.facility_type', 'hospital', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.facility_structure', 'hospital_network', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.outpatient_only_ack', false, $user_id);
        $this->setClinicSetting($clinic_b, 'setup.completed_steps', '', $user_id);
        $this->setClinicSetting($clinic_b, CpmsSetupWizard::COMPLETE_KEY, false, $user_id);

        // Build every hospital prerequisite except its explicit outpatient-only
        // acknowledgment, so completion readiness changes only when B is acked.
        $suffix      = bin2hex(random_bytes(4));
        $created_at  = App::db()->nowUtcSql();
        $location_id = App::locationRepository()->create(
            $clinic_b,
            'A-to-B location ' . $suffix,
            'a2b-' . $suffix,
            'Asia/Tehran',
            $created_at
        );
        self::assertGreaterThan(0, $location_id);
        $clinician_one = App::clinicianRepository()->create($clinic_b, ['full_name' => 'A-to-B clinician one ' . $suffix]);
        $clinician_two = App::clinicianRepository()->create($clinic_b, ['full_name' => 'A-to-B clinician two ' . $suffix]);
        self::assertGreaterThan(0, $clinician_one);
        self::assertGreaterThan(0, $clinician_two);
        global $wpdb;
        $schedule_insert = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule
                (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, is_active, created_at, updated_at)
             VALUES (%d, %d, %d, %d, %s, %s, %d, %s, %s)',
            $clinic_b,
            $location_id,
            $clinician_one,
            1,
            '09:00:00',
            '12:00:00',
            1,
            $created_at,
            $created_at
        ));
        self::assertSame(1, $schedule_insert);
        Settings::flushCache();

        $before_a = $this->clinicSettingValues($clinic_a, $keys);
        $before_b = $this->clinicSettingValues($clinic_b, $keys);
        $clinic_a_before = App::clinicRepository()->find($clinic_a);
        $audit_a_before = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_a]
        );

        $facility_html = $this->renderWizardStep('facility', $clinic_b);
        self::assertSame(
            1,
            preg_match('/value="hospital"[^>]*checked/', $facility_html),
            'Clinic B’s persisted facility type must determine the checked choice despite Clinic A user-meta.'
        );
        self::assertSame(0, preg_match('/value="doctor_practice"[^>]*checked/', $facility_html));
        self::assertStringContainsString('data-cpms-onboarding-flow="hospital_network"', $facility_html);

        $settings_b = App::settings();
        self::assertSame($clinic_b, $settings_b->clinicId());
        self::assertSame('', CpmsSetupWizard::saveClinic($settings_b, ['clinic_name' => 'A-to-B hospital outpatient ' . $suffix], $user_id));
        self::assertSame('', CpmsSetupWizard::saveBooking($settings_b, [
            'duration' => '20',
            'capacity' => '1',
            'lead'     => '2',
            'future'   => '60',
            'cancel'   => '24',
        ], $user_id));
        CpmsSetupWizard::mark_step_completed($settings_b, 'booking', $user_id);
        self::assertSame(['سلامت سیستم'], CpmsSetupWizard::missing_required_steps($settings_b));

        $health_html = $this->renderWizardStep('health', $clinic_b);
        self::assertStringContainsString('data-cpms-inpatient-unsupported', $health_html);
        self::assertStringContainsString('data-cpms-hospital-outpatient-scope="1"', $health_html);
        $health_form = $this->findFormWithHiddenValue($health_html, 'step', 'health');
        self::assertNotNull($health_form, 'The authorized Clinic B hospital health step must render.');
        self::assertStringContainsString('name="outpatient_only_ack"', (string) $health_form);
        self::assertSame(0, preg_match('/name="outpatient_only_ack"[^>]*checked/', (string) $health_form));
        self::assertFalse(CpmsSetupWizard::step_is_complete('health', $settings_b));

        $finish_before_ack = $this->renderWizardStep('finish', $clinic_b);
        self::assertStringContainsString('data-cpms-finish-ready="0"', $finish_before_ack);
        self::assertStringNotContainsString('✅ راه‌اندازی تکمیل شده است', $finish_before_ack);

        // Fail the first full Settings read after cache flush. This used to
        // initialize an empty cache, making Clinic B look like a legacy setup
        // and allowing the Clinic-name/clinician fallback to bypass its ack.
        Settings::flushCache();
        $render_read_failed = false;
        $render_fault = $this->failNextSettingsReadForClinic($clinic_b, $render_read_failed);
        $previous_suppression = $wpdb->suppress_errors(true);
        add_filter('query', $render_fault, 5, 1);
        try {
            $finish_after_read_failure = $this->renderWizardStep('finish', $clinic_b);
        } finally {
            remove_filter('query', $render_fault, 5);
            $wpdb->suppress_errors($previous_suppression);
        }
        self::assertTrue($render_read_failed, 'the fixture must fail the first Clinic B settings read');
        self::assertStringContainsString('data-cpms-finish-ready="0"', $finish_after_read_failure);
        self::assertStringNotContainsString('data-cpms-finish-ready="1"', $finish_after_read_failure);
        self::assertStringNotContainsString('✅ راه‌اندازی تکمیل شده است', $finish_after_read_failure);

        // Exercise the same one-shot read failure on the write path as well;
        // every hospital prerequisite except the fresh acknowledgment is met.
        $before_a_failed_finish = $this->clinicSettingValues($clinic_a, $keys);
        $before_b_failed_finish = $this->clinicSettingValues($clinic_b, $keys);
        $audit_a_before_failed_finish = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_a]
        );
        $audit_b_before_failed_finish = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_b]
        );
        Settings::flushCache();
        $save_read_failed = false;
        $save_fault = $this->failNextSettingsReadForClinic($clinic_b, $save_read_failed);
        $previous_suppression = $wpdb->suppress_errors(true);
        add_filter('query', $save_fault, 5, 1);
        try {
            $finish_error_after_read_failure = $this->invokeSaveFinish($user_id);
        } finally {
            remove_filter('query', $save_fault, 5);
            $wpdb->suppress_errors($previous_suppression);
        }
        self::assertTrue($save_read_failed, 'saveFinish must encounter the injected Clinic B settings read failure');
        self::assertStringContainsString('قابل تأیید نیست', $finish_error_after_read_failure, 'an unverified facility configuration must fail closed instead of completing through any fallback');
        Settings::flushCache();
        self::assertSame($before_a_failed_finish, $this->clinicSettingValues($clinic_a, $keys), 'a failed Clinic B read must not write Clinic A settings');
        self::assertSame($before_b_failed_finish, $this->clinicSettingValues($clinic_b, $keys), 'a failed read must not set Clinic B completion');
        self::assertSame($audit_a_before_failed_finish, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_a]
        ));
        self::assertSame($audit_b_before_failed_finish, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_b]
        ));
        self::assertSame('', CpmsSetupWizard::save_health($settings_b, ['outpatient_only_ack' => '1'], $user_id));
        self::assertTrue(CpmsSetupWizard::step_is_complete('health', $settings_b));
        self::assertSame([], CpmsSetupWizard::missing_required_steps($settings_b));

        $finish_ready = $this->renderWizardStep('finish', $clinic_b);
        self::assertStringContainsString('data-cpms-finish-ready="1"', $finish_ready);
        self::assertSame('', $this->invokeSaveFinish($user_id));
        $finish_complete = $this->renderWizardStep('finish', $clinic_b);
        self::assertStringContainsString('data-cpms-finish-ready="1"', $finish_complete);
        self::assertStringContainsString('✅ راه‌اندازی تکمیل شده است', $finish_complete);
        self::assertSame('doctor_practice', (string) get_user_meta($user_id, 'cpms_onboarding_facility_type', true));
        self::assertSame('single', (string) get_user_meta($user_id, 'cpms_onboarding_structure', true));

        Settings::flushCache();
        self::assertSame($before_a, $this->clinicSettingValues($clinic_a, $keys), 'Clinic B saves must not alter Clinic A settings.');
        self::assertSame($clinic_a_before, App::clinicRepository()->find($clinic_a), 'Clinic B setup must not write Clinic A profile data.');
        $after_b = $this->clinicSettingValues($clinic_b, $keys);
        self::assertSame('hospital', $after_b['setup.facility_type']);
        self::assertSame('hospital_network', $after_b['setup.facility_structure']);
        self::assertTrue($after_b['setup.outpatient_only_ack']);
        self::assertSame('booking', $after_b['setup.completed_steps']);
        self::assertTrue($after_b[CpmsSetupWizard::COMPLETE_KEY]);
        self::assertFalse($before_b['setup.outpatient_only_ack']);
        self::assertFalse($before_b[CpmsSetupWizard::COMPLETE_KEY]);
        self::assertSame($audit_a_before, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_a]
        ), 'Clinic B settings writes must not create an audit event for Clinic A.');
    }

    public function testExplicitClinicWithMissingFacilitySettingKeepsLegacyFinishSemantics(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        self::assertSame('', CpmsSetupWizard::save_facility(App::settings(), [
            'facility_type' => 'doctor_practice',
            'structure'     => 'single',
        ], $user_id));

        $clinic_b = $this->createAdditionalClinic('legacy-no-facility');
        cpms_test_seed_membership($user_id, $clinic_b, RolesAndCapabilities::ROLE_MANAGER);
        $clinician_id = App::clinicianRepository()->create($clinic_b, ['full_name' => 'Legacy Clinic B clinician']);
        self::assertGreaterThan(0, $clinician_id);

        $keys = ['setup.facility_type', 'setup.facility_structure', CpmsSetupWizard::COMPLETE_KEY];
        $before_a = $this->clinicSettingValues($clinic_a, $keys);
        $clinic_a_meta = [
            'facility_type' => (string) get_user_meta($user_id, 'cpms_onboarding_facility_type', true),
            'structure'     => (string) get_user_meta($user_id, 'cpms_onboarding_structure', true),
        ];
        $clinic_b_before = $this->clinicSettingValues($clinic_b, $keys);

        $html = $this->renderWizardStep('finish', $clinic_b);
        $settings_b = App::settings();
        self::assertSame($clinic_b, $settings_b->clinicId());
        self::assertFalse($settings_b->has_stored('setup.facility_type'), 'a genuinely absent B row is the legacy case');
        self::assertStringContainsString('data-cpms-finish-ready="1"', $html, 'legacy Clinic completion remains based on its canonical name and real clinician');
        self::assertSame('', $this->invokeSaveFinish($user_id));

        Settings::flushCache();
        self::assertSame($before_a, $this->clinicSettingValues($clinic_a, $keys), 'legacy completion in B must not write Clinic A settings');
        self::assertSame($clinic_b_before['setup.facility_type'], $this->clinicSettingValues($clinic_b, $keys)['setup.facility_type']);
        self::assertTrue((bool) App::settings()->get(CpmsSetupWizard::COMPLETE_KEY, false), 'the legitimate legacy path still records completion in B');
        self::assertSame($clinic_a_meta['facility_type'], (string) get_user_meta($user_id, 'cpms_onboarding_facility_type', true));
        self::assertSame($clinic_a_meta['structure'], (string) get_user_meta($user_id, 'cpms_onboarding_structure', true));
    }

    public function testExplicitClinicWithInvalidFacilitySettingCannotUseLegacyFinishFallback(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        self::assertSame('', CpmsSetupWizard::save_facility(App::settings(), [
            'facility_type' => 'doctor_practice',
            'structure'     => 'single',
        ], $user_id));

        $clinic_b = $this->createAdditionalClinic('invalid-facility');
        cpms_test_seed_membership($user_id, $clinic_b, RolesAndCapabilities::ROLE_MANAGER);
        $this->setClinicSetting($clinic_b, 'setup.facility_type', 'forged_facility_type', $user_id);
        $this->setClinicSetting($clinic_b, CpmsSetupWizard::COMPLETE_KEY, false, $user_id);
        $clinician_id = App::clinicianRepository()->create($clinic_b, ['full_name' => 'Invalid Clinic B clinician']);
        self::assertGreaterThan(0, $clinician_id);

        $keys = ['setup.facility_type', CpmsSetupWizard::COMPLETE_KEY];
        $before_a = $this->clinicSettingValues($clinic_a, $keys);
        $before_b = $this->clinicSettingValues($clinic_b, $keys);
        $clinic_b_profile = App::clinicRepository()->find($clinic_b);
        $clinic_a_meta = [
            'facility_type' => (string) get_user_meta($user_id, 'cpms_onboarding_facility_type', true),
            'structure'     => (string) get_user_meta($user_id, 'cpms_onboarding_structure', true),
        ];

        $html = $this->renderWizardStep('finish', $clinic_b);
        self::assertStringContainsString('data-cpms-finish-ready="0"', $html);
        self::assertStringContainsString('data-cpms-facility-config-unverified="1"', $html);
        self::assertStringNotContainsString('✅ راه‌اندازی تکمیل شده است', $html);
        $error = $this->invokeSaveFinish($user_id);
        self::assertStringContainsString('قابل تأیید نیست', $error, 'invalid persisted data must not enter the legacy save fallback');

        Settings::flushCache();
        self::assertSame($before_a, $this->clinicSettingValues($clinic_a, $keys), 'invalid Clinic B data must not write Clinic A settings');
        self::assertSame($before_b, $this->clinicSettingValues($clinic_b, $keys), 'invalid facility configuration must not set completion');
        self::assertSame($clinic_b_profile, App::clinicRepository()->find($clinic_b), 'the denied finish must not alter either Clinic profile');
        self::assertSame($clinic_a_meta['facility_type'], (string) get_user_meta($user_id, 'cpms_onboarding_facility_type', true));
        self::assertSame($clinic_a_meta['structure'], (string) get_user_meta($user_id, 'cpms_onboarding_structure', true));
    }

    public function testFacilityStepCarriesTheExplicitClinicAndPostChangesOnlyThatClinic(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        $clinic_b = $this->createAdditionalClinic('facility-form-selected');
        cpms_test_seed_membership($user_id, $clinic_b, RolesAndCapabilities::ROLE_MANAGER);

        $keys = [
            'setup.facility_type',
            'setup.facility_structure',
            'setup.current_step',
            'setup.outpatient_only_ack',
            'setup.completed_steps',
        ];
        $this->setClinicSetting($clinic_a, 'setup.facility_type', 'doctor_practice', $user_id);
        $this->setClinicSetting($clinic_a, 'setup.facility_structure', 'single', $user_id);
        $this->setClinicSetting($clinic_a, 'setup.current_step', 'welcome', $user_id);
        $this->setClinicSetting($clinic_a, 'setup.outpatient_only_ack', true, $user_id);
        $this->setClinicSetting($clinic_a, 'setup.completed_steps', 'clinic', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.facility_type', 'general_clinic', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.facility_structure', 'multi_doctor', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.current_step', 'facility', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.outpatient_only_ack', true, $user_id);
        $this->setClinicSetting($clinic_b, 'setup.completed_steps', 'booking', $user_id);
        Settings::flushCache();
        $before_a = $this->clinicSettingValues($clinic_a, $keys);

        $html = $this->renderWizardStep('facility', $clinic_b);
        $form = $this->findFormWithHiddenValue($html, 'action', 'cpms_wizard_save');
        self::assertNotNull($form, 'The ordinary facility step must render its real POST form.');
        self::assertStringContainsString(
            'name="clinic_id" value="' . $clinic_b . '"',
            (string) $form,
            'The selected Clinic must remain attached to the facility form POST.'
        );
        self::assertStringNotContainsString(
            'name="clinic_id" value="' . $clinic_a . '"',
            (string) $form,
            'The form must carry only its selected Clinic.'
        );

        $previous_post = $_POST;
        $_POST = [
            '_wpnonce'      => wp_create_nonce('cpms_wizard_save'),
            'action'        => 'cpms_wizard_save',
            'step'          => 'facility',
            'clinic_id'     => (string) $clinic_b,
            'facility_type' => 'hospital',
            'structure'     => 'hospital_network',
        ];
        try {
            $location = $this->captureRedirect(static function (): void {
                CpmsSetupWizard::save();
            });
        } finally {
            $_POST = $previous_post;
        }

        self::assertStringContainsString('clinic_id=' . $clinic_b, $location, 'The redirect must preserve the selected Clinic too.');
        Settings::flushCache();
        self::assertSame($before_a, $this->clinicSettingValues($clinic_a, $keys), 'Clinic A must remain unchanged by Clinic B’s POST.');
        $after_b = $this->clinicSettingValues($clinic_b, $keys);
        self::assertSame('hospital', $after_b['setup.facility_type']);
        self::assertSame('hospital_network', $after_b['setup.facility_structure']);
        self::assertSame('clinic', $after_b['setup.current_step']);
        self::assertFalse($after_b['setup.outpatient_only_ack'], 'Changing the facility selection must reset its Clinic-local acknowledgment.');
        self::assertSame('booking,facility', $after_b['setup.completed_steps']);
        self::assertSame($clinic_b, (int) App::scope()->clinicId);
    }

    public function testUnauthorizedFacilityPostForAnotherClinicWritesNothing(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        $clinic_b = $this->createAdditionalClinic('facility-form-denied');
        $keys     = ['setup.facility_type', 'setup.facility_structure', 'setup.current_step'];
        $this->setClinicSetting($clinic_a, 'setup.facility_type', 'doctor_practice', $user_id);
        $this->setClinicSetting($clinic_a, 'setup.facility_structure', 'single', $user_id);
        $this->setClinicSetting($clinic_a, 'setup.current_step', 'welcome', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.facility_type', 'general_clinic', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.facility_structure', 'multi_doctor', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.current_step', 'facility', $user_id);
        Settings::flushCache();
        $before_a = $this->clinicSettingValues($clinic_a, $keys);
        $before_b = $this->clinicSettingValues($clinic_b, $keys);
        $audit_a  = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_a]
        );
        $audit_b  = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_b]
        );

        $previous_post = $_POST;
        $_POST = [
            '_wpnonce'      => wp_create_nonce('cpms_wizard_save'),
            'action'        => 'cpms_wizard_save',
            'step'          => 'facility',
            'clinic_id'     => (string) $clinic_b,
            'facility_type' => 'hospital',
            'structure'     => 'hospital_network',
        ];
        $denied = false;
        try {
            $this->captureWpDie(static function (): void {
                CpmsSetupWizard::save();
            });
        } catch (\RuntimeException $error) {
            $denied = true;
            self::assertStringContainsString('Clinic انتخاب‌شده معتبر نیست', $error->getMessage());
        } finally {
            $_POST = $previous_post;
        }

        self::assertTrue($denied, 'An unauthorized explicit Clinic POST must be rejected.');
        Settings::flushCache();
        self::assertSame($before_a, $this->clinicSettingValues($clinic_a, $keys), 'Denial must not fall back to writing the actor’s authorized Clinic.');
        self::assertSame($before_b, $this->clinicSettingValues($clinic_b, $keys), 'Denial must not write the unauthorized Clinic either.');
        self::assertSame($audit_a, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_a]
        ));
        self::assertSame($audit_b, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_b]
        ));
        self::assertSame($clinic_a, (int) App::scope()->clinicId, 'Rejecting the selector must not replace the prior scope.');
    }

    public function testMalformedWizardClinicSelectorsAreRejectedOnGetAndPostWithoutFallback(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        $this->createAdditionalClinic('malformed-selector');
        $keys = ['setup.facility_type', 'setup.facility_structure', 'setup.current_step', 'setup.completed_steps'];
        $this->setClinicSetting($clinic_a, 'setup.facility_type', 'doctor_practice', $user_id);
        $this->setClinicSetting($clinic_a, 'setup.facility_structure', 'single', $user_id);
        $this->setClinicSetting($clinic_a, 'setup.current_step', 'welcome', $user_id);
        $this->setClinicSetting($clinic_a, 'setup.completed_steps', 'clinic', $user_id);
        Settings::flushCache();
        $before = $this->clinicSettingValues($clinic_a, $keys);

        $malformed_selectors = ['', '  ', [], ['1'], null, true, 1.5, 0, 'not-a-clinic'];
        foreach ($malformed_selectors as $malformed) {
            $html = $this->renderWizardStep('facility', $malformed);
            self::assertStringContainsString('data-cpms-clinic-scope="denied"', $html, 'A present malformed selector on GET must be rejected.');
            self::assertStringNotContainsString('name="action" value="cpms_wizard_save"', $html);
            self::assertStringNotContainsString('name="clinic_name"', $html);
            self::assertSame($clinic_a, (int) App::scope()->clinicId, 'Malformed GET must not fall back to the actor’s unique Clinic.');
            Settings::flushCache();
            self::assertSame($before, $this->clinicSettingValues($clinic_a, $keys), 'Malformed GET must be read-only.');
        }

        foreach ($malformed_selectors as $malformed) {
            $previous_post = $_POST;
            $_POST = [
                '_wpnonce'      => wp_create_nonce('cpms_wizard_save'),
                'action'        => 'cpms_wizard_save',
                'step'          => 'facility',
                'clinic_id'     => $malformed,
                'facility_type' => 'hospital',
                'structure'     => 'hospital_network',
            ];
            $denied = false;
            try {
                $this->captureWpDie(static function (): void {
                    CpmsSetupWizard::save();
                });
            } catch (\RuntimeException $error) {
                $denied = true;
                self::assertStringContainsString('Clinic انتخاب‌شده معتبر نیست', $error->getMessage());
            } finally {
                $_POST = $previous_post;
            }

            self::assertTrue($denied, 'A present malformed selector on POST must fail closed.');
            self::assertSame($clinic_a, (int) App::scope()->clinicId, 'Malformed POST must not replace or infer scope.');
            Settings::flushCache();
            self::assertSame($before, $this->clinicSettingValues($clinic_a, $keys), 'Malformed POST must not write through the implicit single-membership scope.');
        }
    }

    public function testMalformedSelectorDoesNotReadAmbientUnauthorizedClinicSettings(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        $clinic_b = $this->createAdditionalClinic('malformed-ambient-scope');
        $keys     = ['setup.facility_type', 'setup.facility_structure'];
        $this->setClinicSetting($clinic_b, 'setup.facility_type', 'hospital', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.facility_structure', 'hospital_network', $user_id);
        Settings::flushCache();

        // Seed stale request-local selection state, then model an ambient scope
        // the actor is not authorized to read. A malformed GET must clear that
        // state and show only the generic rejection/authorized-choice surface.
        $this->renderWizardStep('booking', $clinic_a);
        $property = new \ReflectionProperty(CpmsSetupWizard::class, 'explicit_clinic_id');
        self::assertSame($clinic_a, $property->getValue());
        $clinic = App::clinicRepository()->find($clinic_a);
        self::assertIsArray($clinic);
        App::replaceExplicitScope(ClinicScope::forClinic($clinic_b)->withOrganization((int) $clinic['organization_id']));

        $before_b = $this->clinicSettingValues($clinic_b, $keys);
        $html     = $this->renderWizardStep('facility', ' ');

        self::assertStringContainsString('data-cpms-clinic-scope="denied"', $html);
        self::assertStringNotContainsString('data-cpms-facility-guidance=', $html);
        self::assertStringNotContainsString('data-cpms-inpatient-unsupported=', $html);
        self::assertStringNotContainsString('name="facility_type"', $html);
        self::assertSame($clinic_b, (int) App::scope()->clinicId, 'The rejected selector must not replace the ambient scope.');
        self::assertSame(0, $property->getValue(), 'Rejected GET must clear stale request-local Clinic binding.');
        self::assertSame($before_b, $this->clinicSettingValues($clinic_b, $keys), 'Rejection must not read/write Clinic B settings through the wizard step.');
        self::assertFalse(App::authorization_service()->can($user_id, $clinic_b, RolesAndCapabilities::CONFIG));
    }

    public function testUnauthorizedExplicitClinicSelectorShowsOnlyAuthorizedChoices(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        $clinic_b = $this->createAdditionalClinic('unauthorized-selector');
        $this->setClinicSetting($clinic_b, 'booking.duration_default_min', 193, $user_id);
        Settings::flushCache();

        $html = $this->renderWizardStep('booking', $clinic_b);

        self::assertStringContainsString('data-cpms-clinic-scope="denied"', $html);
        self::assertStringContainsString('class="cpms-clinic-selector"', $html, 'The selector may offer only Clinics the actor can configure.');
        self::assertStringContainsString('value="' . $clinic_a . '"', $html);
        self::assertStringNotContainsString('value="' . $clinic_b . '"', $html);
        self::assertStringNotContainsString('value="193"', $html, 'The unauthorized Clinic setting must not be displayed.');
        self::assertStringNotContainsString('name="duration"', $html);
        self::assertStringNotContainsString('name="action" value="cpms_wizard_save"', $html);
        $scope = ScopeContext::tryGet();
        self::assertNotNull($scope, 'A rejected selector must not replace the pre-existing authorized scope.');
        self::assertSame($clinic_a, (int) $scope->clinicId);
        self::assertSame($clinic_a, App::settings()->clinicId());
        self::assertFalse(App::authorization_service()->can($user_id, $clinic_b, RolesAndCapabilities::CONFIG));
    }

    public function testAuthorizedClinicAWithAmbientClinicBScopeCannotReadOrWriteClinicB(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        $clinic_b = $this->createAdditionalClinic('ambient-mismatch');
        $this->setClinicSetting($clinic_a, 'booking.duration_default_min', 31, $user_id);
        $this->setClinicSetting($clinic_b, 'booking.duration_default_min', 137, $user_id);
        Settings::flushCache();

        $organization_id = (int) App::clinicRepository()->find($clinic_a)['organization_id'];
        App::replaceExplicitScope(ClinicScope::forClinic($clinic_b)->withOrganization($organization_id));
        self::assertSame($clinic_b, (int) App::scope()->clinicId, 'Fixture must model an ambient B scope without granting B membership.');
        self::assertSame($clinic_b, App::settings()->clinicId());
        self::assertTrue(App::authorization_service()->can($user_id, $clinic_a, RolesAndCapabilities::CONFIG));
        self::assertFalse(App::authorization_service()->can($user_id, $clinic_b, RolesAndCapabilities::CONFIG));

        $html = $this->renderWizardStep('booking');
        self::assertStringContainsString('data-cpms-wizard-access="denied"', $html);
        self::assertStringNotContainsString('name="duration"', $html);
        self::assertStringNotContainsString('name="action" value="cpms_wizard_save"', $html);
        self::assertStringNotContainsString('value="137"', $html, 'Clinic B settings must not be displayed under Clinic A authority.');

        $clinic_b_before = App::clinicRepository()->find($clinic_b);
        self::assertIsArray($clinic_b_before);
        $clinic_b_name_before = (string) $clinic_b_before['name'];
        $clinic_profile_error = CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_name' => 'Unauthorized Clinic B write',
        ], $user_id);
        self::assertSame(
            'دسترسی لازم را ندارید',
            $clinic_profile_error,
            'Direct profile-save denials stay generic when the actor lacks authorization for the Settings Clinic.'
        );
        self::assertSame($clinic_b_name_before, (string) App::clinicRepository()->find($clinic_b)['name']);

        $settings_b = App::settings();
        $settings_b->set( 'setup.current_step', 'finish', $user_id );
        $settings_b->set( CpmsSetupWizard::COMPLETE_KEY, true, $user_id );
        $settings_b->set( 'setup.outpatient_only_ack', true, $user_id );
        $settings_b->set( 'setup.completed_steps', 'clinic', $user_id );
        $progress_keys = [
            'setup.current_step',
            CpmsSetupWizard::COMPLETE_KEY,
            'setup.outpatient_only_ack',
            'setup.completed_steps',
        ];
        $before_b_progress = [];
        foreach ( $progress_keys as $key ) {
            $before_b_progress[ $key ] = $settings_b->get( $key );
        }
        CpmsSetupWizard::mark_step_completed( $settings_b, 'booking', $user_id );
        CpmsSetupWizard::reset_progress( $settings_b, $user_id );
        foreach ( $progress_keys as $key ) {
            self::assertSame(
                $before_b_progress[ $key ],
                $settings_b->get( $key ),
                'Direct progress helpers must reject Clinic B Settings under Clinic A authority.'
            );
        }

        $before_b = $this->clinicSettingValues($clinic_b, [
            'booking.duration_default_min',
            'booking.slot_capacity_default',
            'booking.min_lead_hours',
            'booking.max_future_days',
            'booking.cancel_deadline_hours',
        ]);
        $before_a = $this->clinicSettingValues($clinic_a, [
            'booking.duration_default_min',
            'booking.slot_capacity_default',
            'booking.min_lead_hours',
            'booking.max_future_days',
            'booking.cancel_deadline_hours',
        ]);
        $before_audit_count = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_b]
        );
        $previous_post = $_POST;
        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_save'),
            'action'   => 'cpms_wizard_save',
            'step'     => 'booking',
            'duration' => '60',
            'capacity' => '2',
            'lead'     => '3',
            'future'   => '30',
            'cancel'   => '24',
        ];
        try {
            try {
                $this->captureWpDie(static function (): void {
                    CpmsSetupWizard::save();
                });
                self::fail('The ordinary save handler must reject the ambient Clinic B scope.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('عضویت فعال', $error->getMessage());
            }
        } finally {
            $_POST = $previous_post;
        }

        self::assertSame($clinic_b, App::settings()->clinicId());
        self::assertSame($before_b, $this->clinicSettingValues($clinic_b, [
            'booking.duration_default_min',
            'booking.slot_capacity_default',
            'booking.min_lead_hours',
            'booking.max_future_days',
            'booking.cancel_deadline_hours',
        ]), 'A denied request must not write any Clinic B booking settings.');
        self::assertSame($before_a, $this->clinicSettingValues($clinic_a, [
            'booking.duration_default_min',
            'booking.slot_capacity_default',
            'booking.min_lead_hours',
            'booking.max_future_days',
            'booking.cancel_deadline_hours',
        ]), 'A denied Clinic B request must not redirect writes to Clinic A either.');
        self::assertSame($before_audit_count, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_b]
        ));
    }

    public function testMultipleClinicsWithoutSelectorRenderChoiceInsteadOfWizardSettings(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        $clinic_b = $this->createAdditionalClinic('ambiguous-scope');
        App::resetScope();
        Settings::flushCache();

        $html = $this->renderWizardStep('booking');

        self::assertStringContainsString('data-cpms-scope-required="1"', $html);
        self::assertStringContainsString('class="cpms-clinic-selector"', $html);
        self::assertStringContainsString('value="' . $clinic_a . '"', $html);
        self::assertStringNotContainsString('value="' . $clinic_b . '"', $html);
        self::assertStringNotContainsString('name="duration"', $html);
        self::assertStringNotContainsString('name="action" value="cpms_wizard_save"', $html);
        self::assertTrue(ScopeContext::tryGet() === null, 'An ambiguous no-selector request must not choose a Clinic.');
        self::assertSame(2, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics')));
        self::assertTrue(App::membership_service()->membership_for($clinic_a, $user_id) !== null);
        self::assertNull(App::membership_service()->membership_for($clinic_b, $user_id));
    }

    public function testZeroClinicsFailClosedWithoutRenderingWizardSettings(): void
    {
        global $wpdb;
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);
        $table = $wpdb->prefix . 'cpms_clinics';
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            $wpdb->query('DELETE FROM ' . $table);
        } finally {
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
        }
        App::resetScope();

        $html = $this->renderWizardStep('booking');

        self::assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics')));
        self::assertStringContainsString('data-cpms-scope-required="1"', $html);
        self::assertStringNotContainsString('name="duration"', $html);
        self::assertStringNotContainsString('name="action" value="cpms_wizard_save"', $html);
        self::assertStringNotContainsString('cpms_first_practice_setup', $html, 'Zero-Clinic installations do not get a guessed first-practice Clinic form.');
    }

    public function testOrdinaryWordPressAdministratorWithoutClinicMembershipIsDeniedAfterBootstrapEligibilityEnds(): void
    {
        $clinic_id = (int) App::scope()->clinicId;
        $established_manager = self::factory()->user->create(['role' => 'subscriber']);
        cpms_test_seed_membership($established_manager, $clinic_id, RolesAndCapabilities::ROLE_MANAGER);
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        App::replaceExplicitScope(App::scope());

        self::assertTrue(current_user_can(RolesAndCapabilities::CONFIG));
        self::assertNull(App::membership_service()->membership_for($clinic_id, $admin_id));
        self::assertNull(App::first_practice_setup_service()->first_setup_candidate(), 'Another membership makes the fresh first-practice path ineligible.');
        $settings = App::settings();
        $before_duration = $settings->get('booking.duration_default_min');
        $borrowed_actor_error = CpmsSetupWizard::saveBooking($settings, [
            'duration' => 60,
            'capacity' => 2,
            'lead'     => 3,
            'future'   => 30,
            'cancel'   => 24,
        ], $established_manager);
        self::assertNotSame('', $borrowed_actor_error, 'A global administrator may not borrow another user’s Clinic membership.');
        self::assertSame($before_duration, $settings->get('booking.duration_default_min'));

        $html = $this->renderWizardStep('booking');

        self::assertStringContainsString('data-cpms-wizard-access="denied"', $html);
        self::assertStringNotContainsString('name="duration"', $html);
        self::assertStringNotContainsString('name="action" value="cpms_wizard_save"', $html);
        self::assertStringNotContainsString('cpms_first_practice_setup', $html);
    }

    public function testSaveClinicKeepsDenialGenericWhenSuppliedActorIsNotCurrentUser(): void
    {
        $current_user_id = $this->authorizeConfigUser();
        $clinic_id       = (int) App::scope()->clinicId;
        $other_user_id   = self::factory()->user->create(['role' => 'administrator']);

        self::assertSame($current_user_id, (int) get_current_user_id());
        self::assertTrue(App::authorization_service()->can($current_user_id, $clinic_id, RolesAndCapabilities::CONFIG));
        self::assertNotNull(App::membership_service()->active_membership_for($clinic_id, $current_user_id));
        self::assertNull(App::membership_service()->active_membership_for($clinic_id, $other_user_id));
        $before = App::clinicRepository()->find($clinic_id);
        self::assertIsArray($before);

        $error = CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_name' => 'Mismatched actor must not write',
        ], $other_user_id);

        self::assertSame('دسترسی لازم را ندارید', $error, 'A caller/current-user mismatch is not a membership-state diagnosis.');
        $after = App::clinicRepository()->find($clinic_id);
        self::assertIsArray($after);
        self::assertSame($before['name'], $after['name'], 'The mismatched actor must not mutate the Clinic profile.');
    }

    public function testGlobalConfigWithoutClinicLocalConfigSeesDenialAndNoWizardForm(): void
    {
        $user_id    = $this->authorizeConfigUser();
        $clinic_id  = (int) App::scope()->clinicId;
        $membership = App::membership_service()->membership_for($clinic_id, $user_id);

        self::assertNotNull($membership, 'Fixture must have a real Clinic membership.');
        self::assertTrue(current_user_can(RolesAndCapabilities::CONFIG), 'The WordPress Administrator keeps its technical CONFIG capability.');
        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::CONFIG, 'deny');
        self::assertTrue(current_user_can(RolesAndCapabilities::CONFIG), 'A Clinic-local DENY must not remove the global WordPress capability.');

        $html = $this->renderWizardStep('locations');

        self::assertStringContainsString('data-cpms-wizard-access="denied"', $html);
        self::assertStringContainsString('دسترسی ندارید', $html);
        self::assertSame(0, preg_match('/<form\\b/i', $html), 'A denied user must not receive an active wizard form.');
        self::assertStringNotContainsString('name="action" value="cpms_wizard_save"', $html);
    }

    public function testSaveBookingRejectsGlobalConfigWithoutClinicLocalConfigAndDoesNotWrite(): void
    {
        $user_id    = $this->authorizeConfigUser();
        $clinic_id  = (int) App::scope()->clinicId;
        $membership = App::membership_service()->membership_for($clinic_id, $user_id);

        self::assertNotNull($membership);
        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::CONFIG, 'deny');
        $settings = App::settings();
        $before   = $settings->get('booking.duration_default_min');

        $error = CpmsSetupWizard::saveBooking($settings, [
            'duration' => 60,
            'capacity' => 2,
            'lead'     => 3,
            'future'   => 30,
            'cancel'   => 24,
        ], $user_id);

        self::assertStringContainsString('عضویت فعال', $error);
        self::assertSame($before, $settings->get('booking.duration_default_min'), 'A Clinic-local denial must leave settings unchanged.');

        $clinic_before = App::clinicRepository()->find($clinic_id);
        self::assertIsArray($clinic_before);
        $profile_error = CpmsSetupWizard::saveClinic($settings, [
            'clinic_name' => 'Denied Clinic-local CONFIG profile write',
        ], $user_id);
        self::assertSame('دسترسی لازم را ندارید', $profile_error, 'A Clinic-local CONFIG denial uses the existing generic profile-save contract.');
        self::assertSame($clinic_before['name'], App::clinicRepository()->find($clinic_id)['name']);
    }

    public function testValidStepSelectorRendersRequestedStepWithoutChangingProgress(): void
    {
        $this->authorizeConfigUser();
        App::settings()->set('setup.current_step', 'booking');

        $html = $this->renderWizardStep('clinic');

        $this->assertTrue(str_contains($html, 'name="clinic_name"'), 'گام مجاز clinic باید مستقیماً قابل مشاهده باشد');
        $this->assertFalse(str_contains($html, 'name="duration"'), 'گام درخواستی باید جایگزین نمایش گام ذخیره‌شده شود');
        $this->assertSame('booking', App::settings()->get('setup.current_step'), 'ناوبری GET نباید پیشرفت ذخیره‌شده را تغییر دهد');
    }

    public function testInvalidStepSelectorFallsBackToStoredStep(): void
    {
        $this->authorizeConfigUser();
        App::settings()->set('setup.current_step', 'booking');

        $html = $this->renderWizardStep('not-a-step');

        $this->assertTrue(str_contains($html, 'name="duration"'), 'گزینشگر نامعتبر باید به گام ذخیره‌شده برگردد');
        $this->assertFalse(str_contains($html, 'name="clinic_name"'), 'گزینشگر نامعتبر نباید گام دلخواهی را تحمیل کند');
        $this->assertSame('booking', App::settings()->get('setup.current_step'), 'گزینشگر نامعتبر نباید پیشرفت را تغییر دهد');
    }

    public function testStepLinksUseRegistryAndReviewAffordanceIsLive(): void
    {
        $this->authorizeConfigUser();
        $html = $this->renderWizardStep('welcome');

        foreach (CpmsSetupWizard::steps() as $step) {
            $this->assertStringContainsString(
                'data-cpms-wizard-step="' . $step['id'] . '"',
                $html,
                'هر گام ثبت‌شده باید پیوند مستقیم داشته باشد'
            );
        }
        $this->assertStringContainsString('step=review', $html, 'پرش به بازبینی باید گام واقعی را انتخاب کند');
        $this->assertStringNotContainsString('jump=review', $html, 'پارامتر قدیمی jump نباید باقی بماند');
    }

    public function testStepSelectorDoesNotBypassConfigCapability(): void
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);
        $previousGet = $_GET;
        $_GET['step'] = 'finish';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('دسترسی ندارید');
        try {
            $this->captureWpDie(function (): void {
                CpmsSetupWizard::render();
            });
        } finally {
            $_GET = $previousGet;
        }
    }

    public function testFinishDoesNotExposeClinicianLinkToGlobalAdminWithoutMembership(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $clinicId = App::scope()->clinicId;
        global $wpdb;
        $clinicTable = $wpdb->prefix . 'cpms_clinics';
        $clinicianTable = $wpdb->prefix . 'cpms_clinicians';
        $clinic = App::clinicRepository()->find($clinicId);
        $clinicians = $wpdb->get_results(
            $wpdb->prepare('SELECT id, is_active FROM ' . $clinicianTable . ' WHERE clinic_id = %d', $clinicId),
            ARRAY_A
        );
        $previousGet = $_GET;

        try {
            $wpdb->update($clinicTable, ['name' => ''], ['id' => $clinicId]);
            $wpdb->query($wpdb->prepare('UPDATE ' . $clinicianTable . ' SET is_active = 0 WHERE clinic_id = %d', $clinicId));
            // An ambient Clinic selector must not establish clinician-page access.
            $_GET['clinic_id'] = (string) $clinicId;
            $html = $this->renderWizardStep('finish');

            $this->assertStringNotContainsString('data-cpms-wizard-action="clinic"', $html, 'شناسهٔ Clinic در URL جایگزین عضویت و مجوز ویرایش Clinic نیست');
            $this->assertStringNotContainsString('data-cpms-wizard-action="clinicians"', $html, 'شناسهٔ Clinic در URL جایگزین عضویت و مجوز scoped نیست');
        } finally {
            $_GET = $previousGet;
            $wpdb->update($clinicTable, ['name' => (string) ($clinic['name'] ?? '')], ['id' => $clinicId]);
            foreach (is_array($clinicians) ? $clinicians : [] as $clinician) {
                $wpdb->update(
                    $clinicianTable,
                    ['is_active' => (int) $clinician['is_active']],
                    ['id' => (int) $clinician['id'], 'clinic_id' => $clinicId]
                );
            }
        }
    }

    public function testClinicianPageAccessCheckDoesNotMutateScopeContext(): void
    {
        $this->authorizeConfigUser();
        $scopeBefore = ScopeContext::tryGet();
        $this->assertNotNull($scopeBefore, 'پیش‌شرط: helper باید Scope صریح موجود را برقرار کرده باشد');

        $this->assertTrue(ClinicianAdminPage::can_current_user_access_page());
        $this->assertSame($scopeBefore, ScopeContext::tryGet(), 'بررسی لینک پزشکان نباید Scope موجود را جایگزین کند');

        ScopeContext::clear();
        $this->assertNull(ScopeContext::tryGet(), 'پیش‌شرط: مسیر بدون Scope صریح');
        $this->assertTrue(ClinicianAdminPage::can_current_user_access_page());
        $this->assertNull(ScopeContext::tryGet(), 'بررسی لینک پزشکان نباید Scope جدیدی در context بنویسد');
    }

    public function testSaveClinicRejectsMissingNameExplicitly(): void
    {
        $adminId = $this->authorizeConfigUser();
        $clinicId = App::scope()->clinicId;
        $before = App::clinicRepository()->find($clinicId);

        $error = CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_address' => 'نشانی آزمایشی',
            'clinic_phone' => '02100000000',
        ], $adminId);

        $this->assertStringContainsString('نام کلینیک الزامی است.', $error);
        $after = App::clinicRepository()->find($clinicId);
        $this->assertSame($before['name'] ?? '', $after['name'] ?? '', 'نام کلینیک نباید با POST ناقص تغییر کند');
        $this->assertSame($before['address'] ?? '', $after['address'] ?? '', 'آدرس کلینیک نباید با POST ناقص تغییر کند');
        $this->assertSame($before['phone'] ?? '', $after['phone'] ?? '', 'تلفن کلینیک نباید با POST ناقص تغییر کند');
    }

    public function testSaveBookingRejectsMissingKeyWithoutOverwritingAnySetting(): void
    {
        $adminId = $this->authorizeConfigUser();
        $settings = App::settings();
        $existing = [
            'booking.duration_default_min' => 47,
            'booking.slot_capacity_default' => 3,
            'booking.min_lead_hours' => 9,
            'booking.max_future_days' => 211,
            'booking.cancel_deadline_hours' => 31,
        ];
        foreach ($existing as $key => $value) {
            $settings->set($key, $value, $adminId);
        }

        $error = CpmsSetupWizard::saveBooking($settings, [
            'duration' => 35,
            'capacity' => 2,
            'lead' => 4,
            'future' => 120,
            // Required `cancel` intentionally missing.
        ], $adminId);

        $this->assertNotSame('', $error, 'کلید عددیِ مفقود باید خطای صریح بدهد');
        foreach ($existing as $key => $value) {
            $this->assertSame($value, (int) $settings->get($key), 'POST ناقص نباید هیچ تنظیم رزروی را بازنویسی کند');
        }
    }

    public function testSaveBookingPersistsValidCustomValues(): void
    {
        $adminId = $this->authorizeConfigUser();
        $settings = App::settings();

        $error = CpmsSetupWizard::saveBooking($settings, [
            'duration' => 35,
            'capacity' => 2,
            'lead' => 4,
            'future' => 120,
            'cancel' => 18,
        ], $adminId);

        $this->assertSame('', $error);
        $this->assertSame(35, (int) $settings->get('booking.duration_default_min'));
        $this->assertSame(2, (int) $settings->get('booking.slot_capacity_default'));
        $this->assertSame(4, (int) $settings->get('booking.min_lead_hours'));
        $this->assertSame(120, (int) $settings->get('booking.max_future_days'));
        $this->assertSame(18, (int) $settings->get('booking.cancel_deadline_hours'));
    }

    public function testFinishShowsRepairLinksForMissingClinicAndClinician(): void
    {
        $this->authorizeConfigUser();
        $this->resetClinicians();
        global $wpdb;
        $clinicId = App::scope()->clinicId;
        $clinic = App::clinicRepository()->find($clinicId);
        $table = $wpdb->prefix . 'cpms_clinics';

        try {
            $wpdb->update($table, ['name' => ''], ['id' => $clinicId]);
            $html = $this->renderWizardStep('finish');

            $this->assertStringContainsString('page=cpms-wizard', $html, 'کمبود نام کلینیک باید پیوند ویرایش داخل ویزارد داشته باشد');
            $this->assertStringContainsString('step=clinic', $html, 'پیوند کلینیک باید مستقیماً به گام مجاز برسد');
            $this->assertStringContainsString('page=cpms-clinicians', $html, 'کمبود پزشک باید پیوند به صفحهٔ پزشکان داشته باشد');
        } finally {
            $wpdb->update($table, ['name' => (string) ($clinic['name'] ?? '')], ['id' => $clinicId]);
        }
    }

    public function testHealthStepOffersAuthorizedSystemActionLink(): void
    {
        $this->authorizeConfigUser();

        $html = $this->renderWizardStep('health');

        $this->assertStringContainsString('page=cpms-system', $html, 'جزئیات سلامت سیستم باید از گام سلامت قابل دسترسی باشد');
    }

    public function testRestartFormHasProtectedNonDestructiveTrigger(): void
    {
        $this->authorizeConfigUser();
        App::settings()->set('setup.current_step', 'finish');

        $html = $this->renderWizardStep('finish');
        $form = $this->findFormWithHiddenValue($html, 'action', 'cpms_wizard_restart');

        $this->assertNotNull($form, 'گام پایانی باید trigger واقعی restart داشته باشد');
        $this->assertTrue(str_contains((string) $form, 'method="post"'), 'فرم restart باید POST واقعی باشد');
        $this->assertTrue(str_contains((string) $form, 'admin-post.php'), 'فرم restart باید به admin-post ارسال شود');
        $this->assertTrue(str_contains((string) $form, 'name="_wpnonce"'), 'فرم restart باید nonce داشته باشد');
        $this->assertTrue(str_contains((string) $form, 'name="action" value="cpms_wizard_restart"'), 'فرم restart باید action ثبت‌شده را بفرستد');
        $this->assertTrue(str_contains((string) $form, 'فقط بازنشانی پیشرفت'), 'برچسب restart باید دامنهٔ محدود خود را روشن کند');
        $this->assertTrue(str_contains($html, 'دادهٔ کلینیک'), 'متن باید صریحاً حفظ داده‌های کلینیک را تضمین کند');
    }

    public function testRestartRejectsInvalidNonce(): void
    {
        $this->authorizeConfigUser();
        $_POST = [
            '_wpnonce' => 'invalid-restart-nonce',
            'action' => 'cpms_wizard_restart',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('اعتبارسنجی ناموفق');
        $this->captureWpDie(function (): void {
            CpmsSetupWizard::restart();
        });
    }

    public function testRestartRejectsCapabilitylessUser(): void
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);
        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_restart'),
            'action' => 'cpms_wizard_restart',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('اعتبارسنجی ناموفق');
        $this->captureWpDie(function (): void {
            CpmsSetupWizard::restart();
        });
    }

    public function testRestartChangesOnlySetupProgress(): void
    {
        $adminId = $this->authorizeConfigUser();
        $settings = App::settings();
        $clinicId = App::scope()->clinicId;
        $clinicBefore = App::clinicRepository()->find($clinicId);
        $cliniciansBefore = count(App::clinicianRepository()->listAll($clinicId, false));
        $timezoneBefore = (string) ($clinicBefore['timezone'] ?? '');
        $booking = [
            'booking.duration_default_min' => 36,
            'booking.slot_capacity_default' => 2,
            'booking.min_lead_hours' => 5,
            'booking.max_future_days' => 140,
            'booking.cancel_deadline_hours' => 16,
        ];
        foreach ($booking as $key => $value) {
            $settings->set($key, $value, $adminId);
        }
        $settings->set('setup.started_at', '2026-10-05T00:00:00+00:00', $adminId);
        $settings->set('setup.current_step', 'finish', $adminId);
        $settings->set(CpmsSetupWizard::COMPLETE_KEY, true, $adminId);
        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_restart'),
            'action' => 'cpms_wizard_restart',
        ];

        $location = $this->captureRedirect(function (): void {
            CpmsSetupWizard::restart();
        });

        $this->assertStringContainsString('cpms-wizard', $location);
        $this->assertSame('welcome', $settings->get('setup.current_step'));
        $this->assertFalse((bool) $settings->get(CpmsSetupWizard::COMPLETE_KEY));
        $this->assertSame('2026-10-05T00:00:00+00:00', $settings->get('setup.started_at'));
        foreach ($booking as $key => $value) {
            $this->assertSame($value, (int) $settings->get($key), 'Restart نباید تنظیمات رزرو را تغییر دهد');
        }
        $clinicAfter = App::clinicRepository()->find($clinicId);
        $this->assertSame($clinicBefore['name'] ?? '', $clinicAfter['name'] ?? '', 'Restart نباید نام کلینیک را تغییر دهد');
        $this->assertSame($clinicBefore['address'] ?? '', $clinicAfter['address'] ?? '', 'Restart نباید آدرس کلینیک را تغییر دهد');
        $this->assertSame($clinicBefore['phone'] ?? '', $clinicAfter['phone'] ?? '', 'Restart نباید تلفن کلینیک را تغییر دهد');
        $this->assertSame($timezoneBefore, (string) ($clinicAfter['timezone'] ?? ''), 'Restart نباید timezone عملیاتی Clinic را تغییر دهد');
        $this->assertSame($cliniciansBefore, count(App::clinicianRepository()->listAll($clinicId, false)), 'Restart نباید پزشکان یا داده‌های کسب‌وکار را حذف کند');
    }

    // ================= helpers =================


    public function testFacilityChangeRollsBackAtomicallyWhenAcknowledgmentResetFails(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        $clinic_b = $this->createAdditionalClinic('facility-atomic-b');
        cpms_test_seed_membership($user_id, $clinic_b, RolesAndCapabilities::ROLE_MANAGER);

        // Clinic B starts as a hospital whose outpatient-only acknowledgment
        // is affirmed for the hospital selection.
        $this->setClinicSetting($clinic_b, 'setup.facility_type', 'hospital', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.facility_structure', 'hospital_network', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.outpatient_only_ack', true, $user_id);
        update_user_meta($user_id, 'cpms_onboarding_facility_type', 'hospital');
        update_user_meta($user_id, 'cpms_onboarding_structure', 'hospital_network');

        $watched        = ['setup.facility_type', 'setup.facility_structure', 'setup.outpatient_only_ack'];
        $before_a       = $this->clinicSettingValues($clinic_a, $watched);
        $audit_a_before = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_a]
        );
        $audit_b_before = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_b]
        );

        $this->renderWizardStep('facility', $clinic_b);
        $settings_b = App::settings();
        self::assertSame($clinic_b, $settings_b->clinicId());

        // Fail the acknowledgment-reset write in the middle of the change.
        $fault = $this->failSettingsWritesForKey('setup.outpatient_only_ack');
        add_filter('query', $fault, 5, 1);
        $threw = false;
        try {
            CpmsSetupWizard::save_facility($settings_b, [
                'facility_type' => 'doctor_practice',
                'structure'     => 'single',
            ], $user_id);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            remove_filter('query', $fault, 5);
        }
        Settings::flushCache();

        self::assertTrue($threw, 'a failed acknowledgment reset must abort the facility transition');
        $after_b = $this->clinicSettingValues($clinic_b, $watched);
        self::assertSame('hospital', $after_b['setup.facility_type'], 'the facility type must roll back with the failed transition');
        self::assertSame('hospital_network', $after_b['setup.facility_structure'], 'the facility structure must roll back');
        self::assertSame(true, $after_b['setup.outpatient_only_ack'], 'the acknowledgment must stay bound to the unchanged hospital selection');
        self::assertSame($audit_b_before, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_b]
        ), 'the audits of the earlier writes in the transition must roll back too');
        self::assertSame($before_a, $this->clinicSettingValues($clinic_a, $watched), 'Clinic A settings must be untouched');
        self::assertSame($audit_a_before, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_a]
        ), 'Clinic A audit rows must be untouched');
        self::assertSame('hospital', (string) get_user_meta($user_id, 'cpms_onboarding_facility_type', true), 'the actor facility user-meta must not change on a rolled-back transition');

        // After the fault clears, the same change commits atomically.
        self::assertSame('', CpmsSetupWizard::save_facility($settings_b, [
            'facility_type' => 'doctor_practice',
            'structure'     => 'single',
        ], $user_id));
        $committed_b = $this->clinicSettingValues($clinic_b, $watched);
        self::assertSame('doctor_practice', $committed_b['setup.facility_type']);
        self::assertSame('single', $committed_b['setup.facility_structure']);
        self::assertSame(false, $committed_b['setup.outpatient_only_ack'], 'the acknowledgment reset must commit together with the new selection');
    }

    public function testFacilityChangeRollbackLeavesNoPhantomCacheAndRetryCommits(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        $clinic_b = $this->createAdditionalClinic('facility-cache-b');
        cpms_test_seed_membership($user_id, $clinic_b, RolesAndCapabilities::ROLE_MANAGER);

        $this->setClinicSetting($clinic_b, 'setup.facility_type', 'hospital', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.facility_structure', 'hospital_network', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.outpatient_only_ack', true, $user_id);

        $watched        = ['setup.facility_type', 'setup.facility_structure', 'setup.outpatient_only_ack'];
        $before_a       = $this->clinicSettingValues($clinic_a, $watched);
        $audit_b_before = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_b]
        );

        $this->renderWizardStep('facility', $clinic_b);
        $settings_b = App::settings();
        self::assertSame($clinic_b, $settings_b->clinicId());

        // Fail the acknowledgment-reset write in the middle of the change.
        $fault = $this->failSettingsWritesForKey('setup.outpatient_only_ack');
        add_filter('query', $fault, 5, 1);
        $threw = false;
        try {
            CpmsSetupWizard::save_facility($settings_b, [
                'facility_type' => 'doctor_practice',
                'structure'     => 'single',
            ], $user_id);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            remove_filter('query', $fault, 5);
        }

        self::assertTrue($threw, 'a failed acknowledgment reset must abort the facility transition');
        // Same process, deliberately NO flushCache(): the inner writes
        // repopulated the snapshot cache with uncommitted values before
        // the failure; after the rollback, reads must show the durable
        // (old) selection, never the phantom new one.
        self::assertSame('hospital', (string) $settings_b->get('setup.facility_type', ''), 'the rolled-back facility type must not survive in the snapshot cache');
        self::assertSame('hospital_network', (string) $settings_b->get('setup.facility_structure', ''), 'the rolled-back facility structure must not survive in the snapshot cache');
        self::assertSame(true, $settings_b->get('setup.outpatient_only_ack', false), 'the old acknowledgment must still govern the unchanged selection');
        $after_b = $this->clinicSettingValues($clinic_b, $watched);
        self::assertSame('hospital', $after_b['setup.facility_type'], 'the durable facility type must be unchanged');
        self::assertSame(true, $after_b['setup.outpatient_only_ack'], 'the durable acknowledgment must be unchanged');
        self::assertSame($audit_b_before, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_b]
        ), 'the audits of the rolled-back transition must roll back too');
        self::assertSame($before_a, $this->clinicSettingValues($clinic_a, $watched), 'Clinic A settings must be untouched');

        // Retry in the same process (still no flushCache): the change now
        // commits atomically, and the old acknowledgment must not survive
        // the committed selection change.
        self::assertSame('', CpmsSetupWizard::save_facility($settings_b, [
            'facility_type' => 'doctor_practice',
            'structure'     => 'single',
        ], $user_id));
        self::assertSame('doctor_practice', (string) $settings_b->get('setup.facility_type', ''), 'the committed selection must be readable in the same process');
        self::assertSame(false, $settings_b->get('setup.outpatient_only_ack', true), 'the old acknowledgment must not survive a committed selection change');
        $committed_b = $this->clinicSettingValues($clinic_b, $watched);
        self::assertSame('doctor_practice', $committed_b['setup.facility_type']);
        self::assertSame('single', $committed_b['setup.facility_structure']);
        self::assertSame(false, $committed_b['setup.outpatient_only_ack'], 'the acknowledgment reset must commit together with the new selection');
        self::assertSame($audit_b_before + 3, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_b]
        ), 'the committed retry must audit exactly the three changed keys');
    }

    public function testBookingStepFailureRollsBackWholePolicyAndRetryCommits(): void
    {
        $user_id   = $this->authorizeConfigUser();
        $clinic_id = (int) App::scope()->clinicId;
        $keys      = [
            'booking.duration_default_min'  => 20,
            'booking.slot_capacity_default' => 1,
            'booking.min_lead_hours'        => 2,
            'booking.max_future_days'       => 60,
            'booking.cancel_deadline_hours' => 24,
        ];
        foreach ($keys as $key => $value) {
            $this->setClinicSetting($clinic_id, $key, $value, $user_id);
        }
        $settings = App::settings();

        // Fail the third policy write: the whole booking policy (wizard
        // entry point) must roll back, in the database and in the
        // same-process snapshot cache (no flushCache below).
        $fault = $this->failSettingsWritesForKey('booking.min_lead_hours');
        add_filter('query', $fault, 5, 1);
        $threw = false;
        try {
            CpmsSetupWizard::saveBooking($settings, ['duration' => '35', 'capacity' => '2', 'lead' => '3', 'future' => '40', 'cancel' => '12'], $user_id);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            remove_filter('query', $fault, 5);
        }

        self::assertTrue($threw, 'a mid-policy failure must abort the booking step');
        self::assertSame(20, (int) $settings->get('booking.duration_default_min'), 'the rolled-back duration must not survive in the snapshot cache');
        self::assertSame(1, (int) $settings->get('booking.slot_capacity_default'), 'the rolled-back capacity must not survive in the snapshot cache');
        $durable = $this->clinicSettingValues($clinic_id, array_keys($keys));
        foreach ($keys as $key => $value) {
            self::assertSame($value, $durable[$key], 'the durable policy must be unchanged after rollback: ' . $key);
        }

        self::assertSame('', CpmsSetupWizard::saveBooking($settings, ['duration' => '35', 'capacity' => '2', 'lead' => '3', 'future' => '40', 'cancel' => '12'], $user_id), 'the retry must commit after the fault clears');
        self::assertSame(35, (int) $settings->get('booking.duration_default_min'));
        self::assertSame(3, (int) $settings->get('booking.min_lead_hours'));
    }

    public function testBookingSaveThroughDispatcherReportsProgressFailureTruthfully(): void
    {
        $admin_id   = $this->authorizeConfigUser();
        $clinic_id  = (int) App::scope()->clinicId;
        $audit_before = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_id]
        );

        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_save'),
            'action'   => 'cpms_wizard_save',
            'step'     => 'booking',
            'duration' => '35',
            'capacity' => '2',
            'lead'     => '3',
            'future'   => '40',
            'cancel'   => '12',
        ];

        // The booking step itself commits; only the subsequent progress
        // write (completed-steps) fails.
        $fault = $this->failSettingsWritesForKey('setup.completed_steps');
        add_filter('query', $fault, 5, 1);
        try {
            $location = $this->captureRedirect(static function (): void {
                CpmsSetupWizard::save();
            });
        } finally {
            remove_filter('query', $fault, 5);
        }

        self::assertStringContainsString('cpms-wizard', $location);
        $notice = (string) get_transient('cpms_wizard_notice');
        self::assertStringContainsString('ذخیره شد، اما ثبت پیشرفت', $notice, 'the message must admit the step was saved when only progress recording failed: ' . $notice);
        self::assertStringNotContainsString('ذخیره انجام نشد', $notice, 'the message must not falsely claim that nothing was saved');

        // Durable truth: the booking policy committed and was audited; the
        // progress marker was not recorded.
        $durable = $this->clinicSettingValues($clinic_id, ['booking.duration_default_min', 'setup.completed_steps']);
        self::assertSame(35, $durable['booking.duration_default_min'], 'the committed booking step must be durable');
        self::assertStringNotContainsString('booking', (string) ($durable['setup.completed_steps'] ?? ''), 'the failed progress write must not be recorded');
        self::assertGreaterThan($audit_before, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinic_id]
        ), 'the committed booking step must be audited');
    }

    public function testRestartRollsBackAtomicallyWhenALaterWriteFails(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_b = $this->createAdditionalClinic('restart-atomic-b');
        cpms_test_seed_membership($user_id, $clinic_b, RolesAndCapabilities::ROLE_MANAGER);

        $this->setClinicSetting($clinic_b, 'setup.current_step', 'booking', $user_id);
        $this->setClinicSetting($clinic_b, CpmsSetupWizard::COMPLETE_KEY, true, $user_id);
        $this->setClinicSetting($clinic_b, 'setup.outpatient_only_ack', true, $user_id);

        $this->renderWizardStep('facility', $clinic_b);
        $settings_b = App::settings();
        self::assertSame($clinic_b, $settings_b->clinicId());

        // Fail the completion-flag write after the current-step write.
        $fault = $this->failSettingsWritesForKey(CpmsSetupWizard::COMPLETE_KEY);
        add_filter('query', $fault, 5, 1);
        $threw = false;
        try {
            CpmsSetupWizard::reset_progress($settings_b, $user_id);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            remove_filter('query', $fault, 5);
        }
        Settings::flushCache();

        self::assertTrue($threw, 'a failed restart write must abort the whole restart');
        $after_b = $this->clinicSettingValues($clinic_b, [
            'setup.current_step',
            CpmsSetupWizard::COMPLETE_KEY,
            'setup.outpatient_only_ack',
        ]);
        self::assertSame('booking', $after_b['setup.current_step'], 'the current-step write must roll back');
        self::assertSame(true, $after_b[CpmsSetupWizard::COMPLETE_KEY], 'the completion flag must be unchanged');
        self::assertSame(true, $after_b['setup.outpatient_only_ack'], 'the acknowledgment must be unchanged');
    }

    public function testMarkStepCompletedPreservesStoredStepsAfterTransientReadFailure(): void
    {
        $user_id  = $this->authorizeConfigUser();
        $clinic_a = (int) App::scope()->clinicId;
        $clinic_b = $this->createAdditionalClinic('completed-steps-rmw-b');
        cpms_test_seed_membership($user_id, $clinic_b, RolesAndCapabilities::ROLE_MANAGER);

        $this->setClinicSetting($clinic_a, 'setup.completed_steps', 'clinic', $user_id);
        $this->setClinicSetting($clinic_b, 'setup.completed_steps', 'booking', $user_id);
        $before_a = $this->clinicSettingValues($clinic_a, ['setup.completed_steps']);

        $this->renderWizardStep('facility', $clinic_b);
        // A fresh Settings instance with an empty snapshot cache: its first
        // (soft) read below is the one that fails, exactly like a request
        // whose settings load failed before any successful read.
        Settings::flushCache();
        $settings_b = new Settings(App::db(), $clinic_b, App::audit());
        self::assertSame($clinic_b, $settings_b->clinicId());

        // Production sequence: an earlier soft read fails and yields the
        // unverified empty default; the read-modify-write runs only after
        // the database recovers, and must merge with the stored list.
        $injected = false;
        $fault    = $this->failNextSettingsReadForClinic($clinic_b, $injected);
        add_filter('query', $fault, 5, 1);
        self::assertSame('', (string) $settings_b->get('setup.completed_steps', ''));
        self::assertTrue($injected, 'the transient read failure must be injected');
        remove_filter('query', $fault, 5);

        CpmsSetupWizard::mark_step_completed($settings_b, 'doctors', $user_id);

        $after_b = $this->clinicSettingValues($clinic_b, ['setup.completed_steps']);
        self::assertSame('booking,doctors', $after_b['setup.completed_steps'], 'previously completed steps must survive a merge computed after a transient read failure');
        self::assertSame($before_a, $this->clinicSettingValues($clinic_a, ['setup.completed_steps']), 'Clinic A completed steps must be untouched');
    }

    private function renderWizardStep(string $step, mixed $clinicId = null): string
    {
        $previousGet = $_GET;
        $bufferLevel = ob_get_level();
        $_GET['step'] = $step;
        unset($_GET['clinic_id']);
        if (func_num_args() > 1) {
            $_GET['clinic_id'] = is_int($clinicId) ? (string) $clinicId : $clinicId;
        }
        ob_start();
        try {
            CpmsSetupWizard::render();

            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $_GET = $previousGet;
        }
    }

    private function findFormWithHiddenValue(string $html, string $name, string $value): ?string
    {
        preg_match_all('/<form\b[^>]*>.*?<\/form>/is', $html, $matches);
        foreach ($matches[0] as $form) {
            $pattern = '/<input\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*\bvalue="' . preg_quote($value, '/') . '"[^>]*>/is';
            if (preg_match($pattern, $form) === 1) {
                return $form;
            }
        }

        return null;
    }

    private function createAdditionalClinic(string $label): int
    {
        global $wpdb;
        $current_clinic = App::clinicRepository()->find((int) App::scope()->clinicId);
        self::assertIsArray($current_clinic);
        $organization_id = (int) ($current_clinic['organization_id'] ?? 0);
        self::assertGreaterThan(0, $organization_id);

        $suffix = bin2hex(random_bytes(4));
        $now    = App::db()->nowUtcSql();
        $result = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics
                (organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)',
            $organization_id,
            'Wizard ' . $label . ' ' . $suffix,
            'wizard-' . sanitize_key($label) . '-' . $suffix,
            'Asia/Tehran',
            $now,
            $now
        ));
        self::assertNotFalse($result);
        $clinic_id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $clinic_id);

        return $clinic_id;
    }

    private function setClinicSetting(int $clinicId, string $key, mixed $value, int $updatedBy): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $json = (string) wp_json_encode($value);
        $result = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_settings
                (clinic_id, `key`, value_json, updated_by_wp_user_id, updated_at)
             VALUES (%d, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json),
                updated_by_wp_user_id = VALUES(updated_by_wp_user_id), updated_at = VALUES(updated_at)',
            $clinicId,
            $key,
            $json,
            $updatedBy,
            $now
        ));
        self::assertNotFalse($result);
        Settings::flushCache();
    }

    /** @param list<string> $keys
     *  @return array<string, mixed>
     */
    private function clinicSettingValues(int $clinicId, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $json = App::db()->fetchValue(
                'SELECT value_json FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s',
                [$clinicId, $key]
            );
            $values[$key] = $json === null ? null : json_decode((string) $json, true);
        }

        return $values;
    }

    private function resetWizardRequestScope(): void
    {
        $property = new \ReflectionProperty(CpmsSetupWizard::class, 'explicit_clinic_id');
        $property->setValue(null, 0);
    }

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


    private function failSettingsWritesForKey(string $key): \Closure
    {
        global $wpdb;
        $settings_table = $wpdb->prefix . 'cpms_settings';

        return static function ($query) use ($key, $settings_table) {
            $query = (string) $query;
            if (
                (str_contains($query, 'INSERT INTO `' . $settings_table . '`') || str_starts_with($query, 'UPDATE ' . $settings_table . ' '))
                && str_contains($query, "'" . $key . "'")
            ) {
                return str_replace($settings_table, $settings_table . '_write_failure', $query);
            }

            return $query;
        };
    }

    private function failNextSettingsReadForClinic(int $clinicId, bool &$injected): \Closure
    {
        global $wpdb;
        $settings_table = $wpdb->prefix . 'cpms_settings';

        return static function ($query) use ($clinicId, $settings_table, &$injected) {
            $query = (string) $query;
            if (
                ! $injected
                && str_contains( $query, 'SELECT `key`, value_json FROM ' . $settings_table )
                && preg_match( '/WHERE clinic_id = ' . $clinicId . '\s*$/', $query ) === 1
            ) {
                $injected = true;

                return str_replace( 'FROM ' . $settings_table, 'FROM ' . $settings_table . '_read_failure', $query );
            }

            return $query;
        };
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
