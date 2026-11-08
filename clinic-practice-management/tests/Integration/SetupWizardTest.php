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
        $diagnostic = $form === null ? $this->explicitSelectorFailureDiagnostics($user_id, $clinic_b, $html) : '';

        self::assertNotNull(
            $form,
            'An explicitly selected, authorized Clinic must render its ordinary wizard form.' . $diagnostic
        );
        self::assertStringContainsString('name="duration"', (string) $form);
        self::assertStringContainsString('value="47"', (string) $form, 'The form must render Clinic B settings, not Clinic A settings.');
        self::assertStringNotContainsString('value="31"', (string) $form);
        self::assertStringContainsString('data-cpms-wizard-clinic="' . $clinic_b . '"', $html);
        self::assertSame($clinic_b, (int) App::scope()->clinicId);
        self::assertSame($clinic_b, App::settings()->clinicId());
        self::assertTrue(App::authorization_service()->can($user_id, $clinic_b, RolesAndCapabilities::CONFIG));
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

    private function renderWizardStep(string $step, ?int $clinicId = null): string
    {
        $previousGet = $_GET;
        $bufferLevel = ob_get_level();
        $_GET['step'] = $step;
        unset($_GET['clinic_id']);
        if ($clinicId !== null) {
            $_GET['clinic_id'] = (string) $clinicId;
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

    /**
     * TEMPORARY PR #334 diagnostic: failure-only, read-only and test-local.
     * Emits only fixed branch codes, sanitized counts and booleans.
     */
    private function explicitSelectorFailureDiagnostics(int $actorId, int $clinicId, string $html): string
    {
        $boolean = static function (callable $probe): string {
            try {
                return $probe() ? '1' : '0';
            } catch (\Throwable) {
                return 'u';
            }
        };
        $requested = $boolean(static fn () => AdminClinicSelector::requested_clinic_id([
            'clinic_id' => (string) $clinicId,
        ]) === $clinicId);
        $selectorEstablished = $boolean(static fn () => AdminClinicSelector::establish(
            $actorId,
            $clinicId,
            RolesAndCapabilities::CONFIG
        ) !== null);
        $membershipActive = $boolean(static fn () => App::membership_service()->active_membership_for(
            $clinicId,
            $actorId
        ) !== null);
        $clinicConfig = $boolean(static fn () => App::authorization_service()->can(
            $actorId,
            $clinicId,
            RolesAndCapabilities::CONFIG
        ));
        $onboarding = $boolean(static fn () => CpmsSetupWizard::onboarding_panel_applies());
        $actorCurrent = $boolean(static fn () => (int) get_current_user_id() === $actorId);
        $wpConfig = $boolean(static fn () => current_user_can(RolesAndCapabilities::CONFIG));
        $manageOptions = $boolean(static fn () => user_can($actorId, 'manage_options'));
        $scopeMatches = $boolean(static fn () => (int) App::scope()->clinicId === $clinicId);
        $settingsMatches = $boolean(static fn () => App::settings()->clinicId() === $clinicId);
        $explicitBound = $boolean(static function () use ($clinicId): bool {
            $property = new \ReflectionProperty(CpmsSetupWizard::class, 'explicit_clinic_id');
            $property->setAccessible(true);

            return (int) $property->getValue() === $clinicId;
        });
        $selectorDenied = str_contains($html, 'data-cpms-clinic-scope="denied"') ? '1' : '0';
        $scopeRequired = str_contains($html, 'data-cpms-scope-required="1"') ? '1' : '0';
        $wizardDenied = str_contains($html, 'data-cpms-wizard-access="denied"') ? '1' : '0';
        $saveAction = str_contains($html, 'name="action" value="cpms_wizard_save"') ? '1' : '0';
        $formCount = preg_match_all('/<form\b/i', $html, $formMatches) === false
            ? 'u'
            : (string) count($formMatches[0]);

        if ($onboarding === '1') {
            $branch = 'onboarding';
        } elseif ($selectorDenied === '1') {
            $branch = 'selector_denied';
        } elseif ($scopeRequired === '1') {
            $branch = 'scope_required';
        } elseif ($wizardDenied === '1') {
            $branch = 'wizard_denied';
        } elseif ($formCount === '0') {
            $branch = 'no_form';
        } elseif ($saveAction === '0') {
            $branch = 'save_action_missing';
        } else {
            $branch = 'form_present_match_failed';
        }

        return sprintf(
            ' [diagnostic branch=%s requested=%s selector=%s membership=%s clinic_config=%s onboarding=%s actor_current=%s wp_config=%s manage_options=%s scope_clinic=%s settings_clinic=%s explicit_bound=%s selector_denied=%s scope_required=%s wizard_denied=%s save_action=%s forms=%s]',
            $branch,
            $requested,
            $selectorEstablished,
            $membershipActive,
            $clinicConfig,
            $onboarding,
            $actorCurrent,
            $wpConfig,
            $manageOptions,
            $scopeMatches,
            $settingsMatches,
            $explicitBound,
            $selectorDenied,
            $scopeRequired,
            $wizardDenied,
            $saveAction,
            $formCount
        );
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

    private function setClinicSetting(int $clinicId, string $key, int $value, int $updatedBy): void
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
