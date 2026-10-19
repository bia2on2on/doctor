<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Admin\SettingsAdmin;
use ClinicCore\Admin\SystemPage;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Application\System\SystemHealthService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Multi-Clinic wp-admin defects A (System / Settings) and B (Clinicians).
 *
 * Contract under test:
 *  A. With more than one Clinic installed, SystemPage and SettingsAdmin never
 *     raise an uncaught ScopeRequiredException. Installation-wide information
 *     renders without a Clinic; Clinic-specific settings render/write only for
 *     an explicitly selected Clinic in which the actor holds an ACTIVE
 *     membership AND scoped CONFIG. No sole/first Clinic is inferred, and a
 *     foreign/forged selector discloses nothing.
 *  B. A manager of several Clinics gets an explicit Clinic selector on the
 *     Clinicians page; data and writes are bound to the verified selected
 *     Clinic only; a foreign selector is rejected; single-membership behavior
 *     is preserved with the active Clinic shown and carried explicitly.
 *  B+. A professional shared between Clinics keeps ONE identity (home Clinic A,
 *     u_clinician_user) and participates in Clinic B through an ACTIVE durable
 *     membership: Clinic B can manage that professional's schedule at Clinic B
 *     Locations only, Clinic A rows stay untouched, the profile stays home-only,
 *     and a suspended participation fails closed.
 *  W. The Classic setup wizard on a multi-Clinic install offers an explicit
 *     authorized Clinic choice instead of a dead end, configures only the
 *     verified selected Clinic, never infers one on save, and rejects a
 *     foreign selector.
 *
 * Fixture rules: dynamic Organization/Clinics (ids asserted > 1), durable
 * memberships through the production primitive (cpms_test_seed_membership),
 * no fixed tenant ids, rows purged by Organization in tearDown.
 */
final class MultiClinicAdminPagesTest extends WP_UnitTestCase
{
    private const TZ = 'Asia/Tehran';

    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;

    /** Active manager membership in BOTH Clinics. */
    private int $multiManagerId = 0;

    /** Active manager membership in Clinic A only. */
    private int $managerAId = 0;

    /** WordPress administrator with cpms_config but no Clinic membership. */
    private int $wpAdminId = 0;

    private int $clinicianA = 0;
    private int $clinicianB = 0;
    private string $clinicianAName = '';
    private string $clinicianBName = '';

    private int $locA1 = 0;
    private int $locB1 = 0;
    private string $locA1Name = '';
    private string $locB1Name = '';

    /** Shared professional: home Clinic A, ACTIVE membership in A and B. */
    private int $sharedUserId = 0;
    private int $sharedClinician = 0;
    private string $sharedName = '';
    private int $sharedMembershipB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];

        $this->orgId = $this->createOrganization();
        $this->clinicA = $this->createClinic('A');
        $this->clinicB = $this->createClinic('B');
        $locA1 = $this->createLocation($this->clinicA, 'A1');
        $locB1 = $this->createLocation($this->clinicB, 'B1');
        $this->locA1 = $locA1['id'];
        $this->locA1Name = $locA1['name'];
        $this->locB1 = $locB1['id'];
        $this->locB1Name = $locB1['name'];

        $unique = bin2hex(random_bytes(3));
        $this->clinicianAName = 'Dr MCA Alpha ' . $unique;
        $this->clinicianBName = 'Dr MCA Beta ' . $unique;
        $this->clinicianA = $this->insertClinician($this->clinicA, $this->clinicianAName);
        $this->clinicianB = $this->insertClinician($this->clinicB, $this->clinicianBName);

        $this->sharedName = 'Dr MCA Shared ' . $unique;
        $this->sharedUserId = $this->makeUser('mca_shared', RolesAndCapabilities::ROLE_DOCTOR);
        $this->sharedClinician = $this->insertClinician($this->clinicA, $this->sharedName, $this->sharedUserId);
        self::assertGreaterThan(0, cpms_test_seed_membership($this->sharedUserId, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR));
        $this->sharedMembershipB = cpms_test_seed_membership($this->sharedUserId, $this->clinicB, RolesAndCapabilities::ROLE_DOCTOR);
        self::assertGreaterThan(0, $this->sharedMembershipB);

        $this->multiManagerId = $this->makeUser('mca_multi', RolesAndCapabilities::ROLE_MANAGER);
        self::assertGreaterThan(0, cpms_test_seed_membership($this->multiManagerId, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER));
        self::assertGreaterThan(0, cpms_test_seed_membership($this->multiManagerId, $this->clinicB, RolesAndCapabilities::ROLE_MANAGER));

        $this->managerAId = $this->makeUser('mca_mgr_a', RolesAndCapabilities::ROLE_MANAGER);
        self::assertGreaterThan(0, cpms_test_seed_membership($this->managerAId, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER));

        $this->wpAdminId = $this->makeUser('mca_wpadmin', 'administrator');
        $admin = get_userdata($this->wpAdminId);
        self::assertNotFalse($admin);
        $admin->add_cap(RolesAndCapabilities::CONFIG);

        // Distinct per-Clinic values make any cross-Clinic disclosure observable.
        $this->setClinicSetting($this->clinicA, 'otp.ttl_sec', 171);
        $this->setClinicSetting($this->clinicB, 'otp.ttl_sec', 282);
        $this->setClinicSetting($this->clinicA, 'update.channel', 'stable');
        $this->setClinicSetting($this->clinicB, 'update.channel', 'stable');
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];
        unset($_REQUEST['_wpnonce']);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        App::settingsFactory()->reset();
        delete_transient('cpms_clinic_notice');
        delete_transient('cpms_sys_notice');
        $this->purgeRows();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Preconditions: the installation really is multi-Clinic.
    // ------------------------------------------------------------------

    public function testFixtureIsMultiClinicSoImplicitSettingsResolutionThrows(): void
    {
        self::assertGreaterThan(1, $this->clinicA);
        self::assertGreaterThan(1, $this->clinicB);
        App::resetScope();
        $thrown = null;
        try {
            App::settings();
        } catch (ScopeRequiredException $e) {
            $thrown = $e;
        }
        self::assertNotNull($thrown, 'precondition: implicit Clinic resolution must be ambiguous (CLINIC_SCOPE_REQUIRED) in this fixture');
    }

    // ------------------------------------------------------------------
    // Defect A — SettingsAdmin
    // ------------------------------------------------------------------

    public function testSettingsPageRendersInstallationInfoWithoutGuessingAClinic(): void
    {
        $html = $this->renderAs($this->multiManagerId, [SettingsAdmin::class, 'render']);

        self::assertStringContainsString('تعداد جداول cpms_', $html, 'installation-wide info renders without a Clinic');
        self::assertStringContainsString('Retention اعلان‌ها', $html, 'installation-level notification retention stays visible');
        self::assertStringContainsString('data-cpms-clinic-scope="required"', $html, 'explicit Clinic selection is requested');
        self::assertStringNotContainsString('OTP TTL', $html, 'no Clinic-specific settings without explicit selection');
        self::assertStringNotContainsString('data-cpms-clinic-settings', $html);
        self::assertSelectorOffersExactly($html, 'cpms_settings_clinic', [$this->clinicA, $this->clinicB]);
        self::assertNoPreselectedClinic($html, 'cpms_settings_clinic');
    }

    public function testSettingsPageShowsOnlyTheExplicitlySelectedAuthorizedClinic(): void
    {
        $html = $this->renderAs($this->multiManagerId, [SettingsAdmin::class, 'render'], ['clinic_id' => (string) $this->clinicA]);
        self::assertStringContainsString('data-cpms-clinic-settings="' . $this->clinicA . '"', $html);
        self::assertStringContainsString('>171<', $html, 'Clinic A value is shown for Clinic A');
        self::assertStringNotContainsString('>282<', $html, 'Clinic B value never leaks into Clinic A view');

        $html = $this->renderAs($this->multiManagerId, [SettingsAdmin::class, 'render'], ['clinic_id' => (string) $this->clinicB]);
        self::assertStringContainsString('data-cpms-clinic-settings="' . $this->clinicB . '"', $html);
        self::assertStringContainsString('>282<', $html);
        self::assertStringNotContainsString('>171<', $html);
    }

    public function testSettingsPageRejectsForeignForgedAndMalformedSelectorsWithoutDisclosure(): void
    {
        foreach ([(string) $this->clinicB, '2147483647', 'abc', '-3'] as $selector) {
            $html = $this->renderAs($this->managerAId, [SettingsAdmin::class, 'render'], ['clinic_id' => $selector]);
            self::assertStringContainsString('data-cpms-clinic-scope="denied"', $html, 'selector ' . $selector . ' must be rejected');
            self::assertStringNotContainsString('data-cpms-clinic-settings', $html);
            self::assertStringNotContainsString('>282<', $html, 'no Clinic B data for selector ' . $selector);
            self::assertStringContainsString('تعداد جداول cpms_', $html, 'installation info still renders');
            self::assertSelectorOffersExactly($html, 'cpms_settings_clinic', [$this->clinicA]);
        }
    }

    public function testSettingsPageForAdministratorWithoutMembershipOffersNoClinic(): void
    {
        $html = $this->renderAs($this->wpAdminId, [SettingsAdmin::class, 'render']);
        self::assertStringContainsString('تعداد جداول cpms_', $html);
        self::assertStringContainsString('data-cpms-clinic-scope="unavailable"', $html, 'WordPress administrator is not Clinic authority');
        self::assertStringNotContainsString('id="cpms_settings_clinic"', $html);

        $html = $this->renderAs($this->wpAdminId, [SettingsAdmin::class, 'render'], ['clinic_id' => (string) $this->clinicA]);
        self::assertStringContainsString('data-cpms-clinic-scope="denied"', $html);
        self::assertStringNotContainsString('>171<', $html);
    }

    // ------------------------------------------------------------------
    // Defect A — SystemPage
    // ------------------------------------------------------------------

    public function testSystemPageRendersOnMultiClinicInstallWithoutClinicSettings(): void
    {
        $html = $this->renderAs($this->multiManagerId, static function (): void {
            SystemPage::render(self::healthyOverride());
        });

        self::assertStringContainsString('وضعیت Health', $html, 'global health section renders');
        self::assertStringContainsString('به‌روزرسانی امن', $html);
        self::assertStringContainsString('data-cpms-clinic-scope="required"', $html);
        self::assertStringNotContainsString('name="channel"', $html, 'Clinic-persisted update settings need an explicit Clinic');
        self::assertSelectorOffersExactly($html, 'cpms_system_clinic', [$this->clinicA, $this->clinicB]);
        self::assertNoPreselectedClinic($html, 'cpms_system_clinic');
    }

    public function testSystemPageWithoutHealthOverrideNeverFatalsOnMultiClinicInstall(): void
    {
        $html = $this->renderAs($this->multiManagerId, [SystemPage::class, 'render']);
        self::assertStringContainsString('وضعیت Health', $html, 'the page renders even when Clinic-dependent probes cannot run');
        self::assertStringContainsString('به‌روزرسانی امن', $html);
    }

    public function testSystemPageExplicitClinicShowsUpdateSettingsForThatClinicOnly(): void
    {
        $html = $this->renderAs($this->multiManagerId, static function (): void {
            SystemPage::render(self::healthyOverride());
        }, ['clinic_id' => (string) $this->clinicB]);

        self::assertStringContainsString('name="channel"', $html);
        self::assertStringContainsString('name="clinic_id" value="' . $this->clinicB . '"', $html, 'update forms carry the explicit Clinic');
        self::assertStringNotContainsString('name="clinic_id" value="' . $this->clinicA . '"', $html);

        $html = $this->renderAs($this->managerAId, static function (): void {
            SystemPage::render(self::healthyOverride());
        }, ['clinic_id' => (string) $this->clinicB]);
        self::assertStringContainsString('data-cpms-clinic-scope="denied"', $html);
        self::assertStringNotContainsString('name="channel"', $html);
    }

    public function testSystemUpdateSettingsWriteRequiresExplicitAuthorizedClinic(): void
    {
        // Foreign selector: rejected, nothing written anywhere.
        $this->dispatchSystemAction($this->managerAId, 'cpms_update_settings', ['channel' => 'beta', 'interval_hours' => '12', 'clinic_id' => (string) $this->clinicB], [SystemPage::class, 'updateSettings']);
        self::assertSame('stable', $this->clinicSetting($this->clinicA, 'update.channel'));
        self::assertSame('stable', $this->clinicSetting($this->clinicB, 'update.channel'));

        // Missing selector on a multi-Clinic install: rejected, never inferred.
        $this->dispatchSystemAction($this->multiManagerId, 'cpms_update_settings', ['channel' => 'beta', 'interval_hours' => '12'], [SystemPage::class, 'updateSettings']);
        self::assertSame('stable', $this->clinicSetting($this->clinicA, 'update.channel'));
        self::assertSame('stable', $this->clinicSetting($this->clinicB, 'update.channel'));

        // Explicit authorized selector: only that Clinic changes.
        $this->dispatchSystemAction($this->multiManagerId, 'cpms_update_settings', ['channel' => 'beta', 'interval_hours' => '12', 'clinic_id' => (string) $this->clinicB], [SystemPage::class, 'updateSettings']);
        self::assertSame('beta', $this->clinicSetting($this->clinicB, 'update.channel'));
        self::assertSame('stable', $this->clinicSetting($this->clinicA, 'update.channel'));
    }

    // ------------------------------------------------------------------
    // Defect B — ClinicianAdminPage
    // ------------------------------------------------------------------

    public function testClinicianPageRequiresExplicitSelectionForMultiClinicManager(): void
    {
        $html = $this->renderAs($this->multiManagerId, [ClinicianAdminPage::class, 'render']);

        self::assertStringContainsString('data-cpms-clinic-scope="required"', $html);
        self::assertSelectorOffersExactly($html, 'cpms_clinicians_clinic', [$this->clinicA, $this->clinicB]);
        self::assertNoPreselectedClinic($html, 'cpms_clinicians_clinic');
        self::assertStringNotContainsString($this->clinicianAName, $html, 'no Clinic is guessed');
        self::assertStringNotContainsString($this->clinicianBName, $html, 'no Clinic is guessed');
        self::assertStringNotContainsString('cpms_clinician_save', $html, 'no mutation form before explicit selection');
    }

    public function testClinicianPageShowsOnlyTheSelectedClinicAndCarriesItExplicitly(): void
    {
        $html = $this->renderAs($this->multiManagerId, [ClinicianAdminPage::class, 'render'], ['clinic_id' => (string) $this->clinicB]);
        self::assertStringContainsString('data-cpms-active-clinic="' . $this->clinicB . '"', $html);
        self::assertStringContainsString($this->clinicianBName, $html);
        self::assertStringNotContainsString($this->clinicianAName, $html, 'Clinic A clinicians never appear in Clinic B');
        self::assertStringContainsString('name="clinic_id" value="' . $this->clinicB . '"', $html, 'create form carries the explicit Clinic');
        self::assertStringContainsString('clinician_id=' . $this->clinicianB . '&#038;clinic_id=' . $this->clinicB, $html, 'manage link carries the explicit Clinic');
        self::assertSelectorOffersExactly($html, 'cpms_clinicians_clinic', [$this->clinicA, $this->clinicB]);

        // Detail view of a clinician from the other Clinic is not loadable.
        $html = $this->renderAs($this->multiManagerId, [ClinicianAdminPage::class, 'render'], ['clinic_id' => (string) $this->clinicB, 'clinician_id' => (string) $this->clinicianA]);
        self::assertStringNotContainsString($this->clinicianAName, $html, 'cross-Clinic clinician detail is not disclosed');
    }

    public function testClinicianPageRejectsForeignSelectorWithoutDisclosure(): void
    {
        foreach ([(string) $this->clinicB, '2147483647', 'x1'] as $selector) {
            $html = $this->renderAs($this->managerAId, [ClinicianAdminPage::class, 'render'], ['clinic_id' => $selector]);
            self::assertStringContainsString('data-cpms-clinic-scope="denied"', $html, 'selector ' . $selector . ' must be rejected');
            self::assertStringNotContainsString($this->clinicianBName, $html);
            self::assertStringNotContainsString($this->clinicianAName, $html);
            self::assertStringNotContainsString('cpms_clinician_save', $html);
        }
    }

    public function testSingleMembershipManagerKeepsExistingBehaviorWithVisibleExplicitContext(): void
    {
        $html = $this->renderAs($this->managerAId, [ClinicianAdminPage::class, 'render']);
        self::assertStringContainsString('data-cpms-active-clinic="' . $this->clinicA . '"', $html, 'the active Clinic is shown, not silently applied');
        self::assertStringContainsString($this->clinicianAName, $html, 'existing single-Clinic listing is preserved');
        self::assertStringNotContainsString($this->clinicianBName, $html);
        self::assertStringContainsString('name="clinic_id" value="' . $this->clinicA . '"', $html, 'forms carry the Clinic explicitly');
        self::assertStringNotContainsString('id="cpms_clinicians_clinic"', $html, 'no switcher for a single authorized Clinic');
    }

    public function testClinicianCreateIsBoundToTheVerifiedSelectedClinic(): void
    {
        $name = 'Dr MCA Created ' . bin2hex(random_bytes(3));
        $result = $this->dispatchClinicianAction($this->multiManagerId, 'cpms_clinician_save', ['full_name' => $name, 'clinic_id' => (string) $this->clinicB], [ClinicianAdminPage::class, 'saveClinician']);
        self::assertSame([$this->clinicB], $this->clinicIdsOfClinicianNamed($name), 'created only in the explicitly selected Clinic');
        self::assertStringContainsString('clinic_id=' . $this->clinicB, $result['location'], 'redirect keeps the explicit Clinic context');
    }

    public function testClinicianCreateRejectsForeignOrMissingSelectorAndWritesNothing(): void
    {
        $foreign = 'Dr MCA Foreign ' . bin2hex(random_bytes(3));
        $result = $this->dispatchClinicianAction($this->managerAId, 'cpms_clinician_save', ['full_name' => $foreign, 'clinic_id' => (string) $this->clinicB], [ClinicianAdminPage::class, 'saveClinician']);
        self::assertSame([], $this->clinicIdsOfClinicianNamed($foreign), 'foreign selector writes nothing');
        self::assertStringStartsWith('خطا', $result['notice']);

        $missing = 'Dr MCA Missing ' . bin2hex(random_bytes(3));
        $result = $this->dispatchClinicianAction($this->multiManagerId, 'cpms_clinician_save', ['full_name' => $missing], [ClinicianAdminPage::class, 'saveClinician']);
        self::assertSame([], $this->clinicIdsOfClinicianNamed($missing), 'multi-membership without selector never infers a Clinic');
        self::assertStringStartsWith('خطا', $result['notice']);
    }

    public function testClinicianToggleCannotReachAnotherClinicThroughTheSelector(): void
    {
        $_GET = ['clinician_id' => (string) $this->clinicianB, 'clinic_id' => (string) $this->clinicA];
        $result = $this->dispatchClinicianAction($this->multiManagerId, 'cpms_clinician_toggle_' . $this->clinicianB, [], [ClinicianAdminPage::class, 'toggleClinician']);
        self::assertStringStartsWith('خطا', $result['notice'], 'Clinic B clinician is not found under Clinic A');
        self::assertSame(1, $this->clinicianActive($this->clinicianB), 'Clinic B clinician unchanged');

        $_GET = ['clinician_id' => (string) $this->clinicianB, 'clinic_id' => (string) $this->clinicB];
        $this->dispatchClinicianAction($this->multiManagerId, 'cpms_clinician_toggle_' . $this->clinicianB, [], [ClinicianAdminPage::class, 'toggleClinician']);
        self::assertSame(0, $this->clinicianActive($this->clinicianB), 'explicit authorized Clinic B toggle applies');
    }

    // ------------------------------------------------------------------
    // Defect B+ — professional shared between two authorized Clinics
    // ------------------------------------------------------------------

    public function testSharedProfessionalIsListedAsParticipantOnlyInTheParticipatingClinic(): void
    {
        $html = $this->renderAs($this->multiManagerId, [ClinicianAdminPage::class, 'render'], ['clinic_id' => (string) $this->clinicB]);
        self::assertStringContainsString('data-cpms-participants="' . $this->clinicB . '"', $html, 'Clinic B lists participating professionals');
        self::assertStringContainsString($this->sharedName, $html);
        self::assertStringContainsString('clinician_id=' . $this->sharedClinician . '&#038;clinic_id=' . $this->clinicB, $html);

        $html = $this->renderAs($this->multiManagerId, [ClinicianAdminPage::class, 'render'], ['clinic_id' => (string) $this->clinicA]);
        self::assertStringContainsString($this->sharedName, $html, 'home Clinic keeps listing its own professional');
        self::assertStringNotContainsString('data-cpms-participants=', $html, 'no participant section when nobody participates from elsewhere');
    }

    public function testSharedProfessionalDetailInParticipatingClinicShowsOnlyThatClinicLocationsAndNoProfileForm(): void
    {
        $html = $this->renderAs($this->multiManagerId, [ClinicianAdminPage::class, 'render'], ['clinic_id' => (string) $this->clinicB, 'clinician_id' => (string) $this->sharedClinician]);
        self::assertStringContainsString('data-cpms-participant-clinician="' . $this->sharedClinician . '"', $html);
        self::assertStringContainsString('value="cpms_schedule_save"', $html, 'schedule management is available in the participating Clinic');
        self::assertStringNotContainsString('value="cpms_clinician_save"', $html, 'profile stays home-Clinic only');
        self::assertStringNotContainsString('cpms_clinician_toggle', $html, 'activation stays home-Clinic only');
        self::assertStringContainsString($this->locB1Name, $html, 'Clinic B Locations are offered');
        self::assertStringNotContainsString($this->locA1Name, $html, 'Clinic A Locations never appear in Clinic B');

        $html = $this->renderAs($this->managerAId, [ClinicianAdminPage::class, 'render'], ['clinic_id' => (string) $this->clinicA, 'clinician_id' => (string) $this->sharedClinician]);
        self::assertStringContainsString('value="cpms_clinician_save"', $html, 'home Clinic keeps the profile form');
        self::assertStringNotContainsString('data-cpms-participant-clinician=', $html);
    }

    public function testPerLocationSchedulesOfSharedProfessionalStayInTheirOwnClinic(): void
    {
        $saveA = $this->dispatchClinicianAction($this->managerAId, 'cpms_schedule_save', $this->schedulePost($this->clinicA, $this->locA1, 0, '09:00', '12:00'), [ClinicianAdminPage::class, 'saveSchedules']);
        self::assertStringStartsNotWith('خطا', $saveA['notice'], 'Clinic A schedule saved: ' . $saveA['notice']);
        $saveB = $this->dispatchClinicianAction($this->multiManagerId, 'cpms_schedule_save', $this->schedulePost($this->clinicB, $this->locB1, 1, '14:00', '18:00'), [ClinicianAdminPage::class, 'saveSchedules']);
        self::assertStringStartsNotWith('خطا', $saveB['notice'], 'Clinic B schedule saved for the participant: ' . $saveB['notice']);
        self::assertStringContainsString('clinic_id=' . $this->clinicB, $saveB['location']);

        $rows = $this->scheduleRowsOf($this->sharedClinician);
        self::assertCount(2, $rows, 'one row per Clinic/Location');
        self::assertSame([$this->clinicA, $this->locA1, 0, '09:00'], [(int) $rows[0]['clinic_id'], (int) $rows[0]['location_id'], (int) $rows[0]['day_of_week'], substr((string) $rows[0]['start_time'], 0, 5)]);
        self::assertSame([$this->clinicB, $this->locB1, 1, '14:00'], [(int) $rows[1]['clinic_id'], (int) $rows[1]['location_id'], (int) $rows[1]['day_of_week'], substr((string) $rows[1]['start_time'], 0, 5)]);

        // Clinic B's view never exposes Clinic A's schedule cell.
        $html = $this->renderAs($this->multiManagerId, [ClinicianAdminPage::class, 'render'], ['clinic_id' => (string) $this->clinicB, 'clinician_id' => (string) $this->sharedClinician]);
        self::assertStringNotContainsString('sched[0][' . $this->locA1 . ']', $html);
        self::assertStringContainsString('sched[1][' . $this->locB1 . ']', $html);

        // Clinic B cannot delete Clinic A's row of the same professional.
        $delete = $this->dispatchClinicianAction($this->multiManagerId, 'cpms_schedule_delete', ['clinician_id' => (string) $this->sharedClinician, 'schedule_id' => (string) $rows[0]['id'], 'clinic_id' => (string) $this->clinicB], [ClinicianAdminPage::class, 'deleteSchedule']);
        self::assertStringStartsWith('خطا', $delete['notice']);
        self::assertCount(2, $this->scheduleRowsOf($this->sharedClinician), 'Clinic A row untouched');
    }

    public function testParticipantProfileIsHomeOnlyAndSuspendedParticipationFailsClosed(): void
    {
        $profile = $this->dispatchClinicianAction($this->multiManagerId, 'cpms_clinician_save', ['clinician_id' => (string) $this->sharedClinician, 'full_name' => 'Renamed From B', 'clinic_id' => (string) $this->clinicB], [ClinicianAdminPage::class, 'saveClinician']);
        self::assertStringStartsWith('خطا', $profile['notice'], 'participating Clinic cannot edit the home profile');
        self::assertSame([$this->clinicA], $this->clinicIdsOfClinicianNamed($this->sharedName), 'profile unchanged');

        App::membership_service()->suspend_membership($this->sharedMembershipB);

        $html = $this->renderAs($this->multiManagerId, [ClinicianAdminPage::class, 'render'], ['clinic_id' => (string) $this->clinicB]);
        self::assertStringNotContainsString($this->sharedName, $html, 'suspended participation is not listed');
        $html = $this->renderAs($this->multiManagerId, [ClinicianAdminPage::class, 'render'], ['clinic_id' => (string) $this->clinicB, 'clinician_id' => (string) $this->sharedClinician]);
        self::assertStringNotContainsString('data-cpms-participant-clinician=', $html, 'suspended participant detail is not loadable');
        self::assertStringNotContainsString($this->sharedName, $html);

        $save = $this->dispatchClinicianAction($this->multiManagerId, 'cpms_schedule_save', $this->schedulePost($this->clinicB, $this->locB1, 2, '10:00', '11:00'), [ClinicianAdminPage::class, 'saveSchedules']);
        self::assertStringStartsWith('خطا', $save['notice']);
        self::assertSame([], $this->scheduleRowsOf($this->sharedClinician), 'no schedule row written for a suspended participation');
    }

    // ------------------------------------------------------------------
    // Wizard — explicit per-Clinic configuration on a multi-Clinic install
    // ------------------------------------------------------------------

    public function testWizardOnMultiClinicInstallOffersExplicitClinicChoiceWithoutGuessing(): void
    {
        $html = $this->renderAs($this->multiManagerId, [CpmsSetupWizard::class, 'render']);
        self::assertStringContainsString('data-cpms-scope-required="1"', $html, 'ambiguous scope is still reported');
        self::assertStringContainsString('data-cpms-clinic-scope="required"', $html);
        self::assertSelectorOffersExactly($html, 'cpms_wizard_clinic', [$this->clinicA, $this->clinicB]);
        self::assertNoPreselectedClinic($html, 'cpms_wizard_clinic');
        self::assertStringNotContainsString('data-cpms-wizard-clinic=', $html, 'no Clinic is configured before explicit selection');
    }

    public function testWizardWithExplicitAuthorizedClinicConfiguresThatClinicOnly(): void
    {
        $html = $this->renderAs($this->multiManagerId, [CpmsSetupWizard::class, 'render'], ['clinic_id' => (string) $this->clinicB]);
        self::assertStringContainsString('data-cpms-wizard-clinic="' . $this->clinicB . '"', $html, 'the Clinic being configured is shown');
        self::assertStringNotContainsString('data-cpms-scope-required="1"', $html);
        self::assertStringContainsString('clinic_id=' . $this->clinicB, $html, 'wizard navigation and forms carry the explicit Clinic');
        self::assertStringNotContainsString('clinic_id=' . $this->clinicA . '"', $html);
    }

    public function testWizardRejectsForeignClinicSelectorWithoutDisclosure(): void
    {
        $html = $this->renderAs($this->managerAId, [CpmsSetupWizard::class, 'render'], ['clinic_id' => (string) $this->clinicB]);
        self::assertStringContainsString('data-cpms-clinic-scope="denied"', $html);
        self::assertStringNotContainsString('data-cpms-wizard-clinic=', $html);
        self::assertSelectorOffersExactly($html, 'cpms_wizard_clinic', [$this->clinicA]);
    }

    public function testWizardSaveWritesOnlyTheExplicitlySelectedClinicAndNeverInfersOne(): void
    {
        $before_a = $this->clinicSetting($this->clinicA, 'booking.duration_default_min');
        $booking = ['step' => 'booking', 'duration' => '35', 'capacity' => '2', 'lead' => '3', 'future' => '40', 'cancel' => '12'];

        // Multi-Clinic without selector: no fatal, no inference, no write.
        $missing = $this->dispatchWizardSave($this->multiManagerId, $booking);
        self::assertFalse($missing['died']);
        self::assertStringStartsWith('خطا', $missing['notice']);
        self::assertNotEquals(35, (int) $this->clinicSetting($this->clinicB, 'booking.duration_default_min'));
        self::assertEquals($before_a, $this->clinicSetting($this->clinicA, 'booking.duration_default_min'));

        // Foreign selector: rejected before any write.
        $foreign = $this->dispatchWizardSave($this->managerAId, $booking + ['clinic_id' => (string) $this->clinicB]);
        self::assertTrue($foreign['died'], 'foreign Clinic selector is refused');
        self::assertNotEquals(35, (int) $this->clinicSetting($this->clinicB, 'booking.duration_default_min'));

        // Explicit authorized selector: only Clinic B changes.
        $saved = $this->dispatchWizardSave($this->multiManagerId, $booking + ['clinic_id' => (string) $this->clinicB]);
        self::assertFalse($saved['died']);
        self::assertStringStartsNotWith('خطا', $saved['notice'], 'wizard save for Clinic B: ' . $saved['notice']);
        self::assertSame(35, (int) $this->clinicSetting($this->clinicB, 'booking.duration_default_min'));
        self::assertEquals($before_a, $this->clinicSetting($this->clinicA, 'booking.duration_default_min'), 'Clinic A untouched');
        self::assertStringContainsString('clinic_id=' . $this->clinicB, $saved['location'], 'redirect keeps the explicit Clinic');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $get
     */
    private function renderAs(int $userId, callable $render, array $get = []): string
    {
        wp_set_current_user($userId);
        ScopeContext::clear();
        App::resetScope();
        $_GET = $get;
        ob_start();
        try {
            $render();
        } finally {
            $html = (string) ob_get_clean();
            $_GET = [];
            ScopeContext::clear();
            App::resetScope();
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{notice: string, location: string}
     */
    private function dispatchClinicianAction(int $userId, string $nonceAction, array $post, callable $handler): array
    {
        wp_set_current_user($userId);
        ScopeContext::clear();
        App::resetScope();
        $_POST = $post;
        $_REQUEST['_wpnonce'] = wp_create_nonce($nonceAction);
        delete_transient('cpms_clinic_notice');
        $location = $this->runRedirectingHandler($handler);
        $_POST = [];
        $_GET = [];
        unset($_REQUEST['_wpnonce']);

        return ['notice' => (string) get_transient('cpms_clinic_notice'), 'location' => $location];
    }

    /**
     * @param array<string, string> $post
     */
    private function dispatchSystemAction(int $userId, string $nonceAction, array $post, callable $handler): string
    {
        wp_set_current_user($userId);
        ScopeContext::clear();
        App::resetScope();
        $_POST = $post + ['_wpnonce' => wp_create_nonce($nonceAction)];
        delete_transient('cpms_sys_notice');
        $this->runRedirectingHandler($handler);
        $_POST = [];
        ScopeContext::clear();
        App::resetScope();
        Settings::flushCache();
        App::settingsFactory()->reset();

        return (string) get_transient('cpms_sys_notice');
    }

    /**
     * @param array<string, string> $post
     * @return array{died: bool, notice: string, location: string}
     */
    private function dispatchWizardSave(int $userId, array $post): array
    {
        wp_set_current_user($userId);
        ScopeContext::clear();
        App::resetScope();
        $_POST = $post + ['_wpnonce' => wp_create_nonce('cpms_wizard_save'), 'action' => 'cpms_wizard_save'];
        delete_transient('cpms_wizard_notice');
        $died = false;
        $location = '';
        $redirect = static function ($location) {
            throw new \RuntimeException('MCA_ADMIN_REDIRECT_EXIT_SIMULATED:' . (string) $location);
        };
        add_filter('wp_redirect', $redirect);
        try {
            CpmsSetupWizard::save();
            self::fail('wizard save ended without a redirect or wp_die');
        } catch (\WPDieException $e) {
            $died = true;
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            self::assertStringStartsWith('MCA_ADMIN_REDIRECT_EXIT_SIMULATED:', $message, 'unexpected wizard failure: ' . $message);
            $location = substr($message, strlen('MCA_ADMIN_REDIRECT_EXIT_SIMULATED:'));
        } finally {
            remove_filter('wp_redirect', $redirect);
            $_POST = [];
            ScopeContext::clear();
            App::resetScope();
            Settings::flushCache();
            App::settingsFactory()->reset();
        }
        $notice = get_transient('cpms_wizard_notice');

        return ['died' => $died, 'notice' => is_string($notice) ? $notice : '', 'location' => $location];
    }

    private function runRedirectingHandler(callable $handler): string
    {
        $redirect = static function ($location) {
            throw new \RuntimeException('MCA_ADMIN_REDIRECT_EXIT_SIMULATED:' . (string) $location);
        };
        add_filter('wp_redirect', $redirect);
        $location = '';
        try {
            $handler();
            self::fail('admin action ended without a redirect');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            self::assertStringStartsWith('MCA_ADMIN_REDIRECT_EXIT_SIMULATED:', $message, 'unexpected handler failure: ' . $message);
            $location = substr($message, strlen('MCA_ADMIN_REDIRECT_EXIT_SIMULATED:'));
        } finally {
            remove_filter('wp_redirect', $redirect);
            ScopeContext::clear();
            App::resetScope();
        }

        return $location;
    }

    /**
     * @return array{checks: list<array{key:string,label:string,status:string,detail:string}>, host: array{status:string, issues:list<string>}}
     */
    private static function healthyOverride(): array
    {
        return ['checks' => [], 'host' => ['status' => SystemHealthService::HOST_SUPPORTED, 'issues' => []]];
    }

    /**
     * @param list<int> $expected
     */
    private static function assertSelectorOffersExactly(string $html, string $selectId, array $expected): void
    {
        self::assertMatchesRegularExpression('/<select[^>]*id="' . preg_quote($selectId, '/') . '"/', $html, 'selector ' . $selectId . ' is rendered');
        $start = (int) strpos($html, 'id="' . $selectId . '"');
        $end = strpos($html, '</select>', $start);
        self::assertNotFalse($end);
        $select = substr($html, $start, (int) $end - $start);
        preg_match_all('/<option value="(\d+)"/', $select, $m);
        $offered = array_map('intval', $m[1]);
        sort($offered);
        sort($expected);
        self::assertSame($expected, $offered, 'only Clinics with active membership + CONFIG are offered');
    }

    private static function assertNoPreselectedClinic(string $html, string $selectId): void
    {
        $start = (int) strpos($html, 'id="' . $selectId . '"');
        $end = strpos($html, '</select>', $start);
        self::assertNotFalse($end);
        $select = substr($html, $start, (int) $end - $start);
        self::assertStringNotContainsString('selected', $select, 'no Clinic is pre-selected by default');
    }

    private function setClinicSetting(int $clinicId, string $key, mixed $value): void
    {
        Settings::flushCache();
        (new Settings(App::db(), $clinicId, App::audit()))->set($key, $value);
        Settings::flushCache();
        App::settingsFactory()->reset();
    }

    private function clinicSetting(int $clinicId, string $key): mixed
    {
        Settings::flushCache();
        App::settingsFactory()->reset();

        return (new Settings(App::db(), $clinicId, App::audit()))->get($key);
    }

    /**
     * @return list<int>
     */
    private function clinicIdsOfClinicianNamed(string $name): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT clinic_id FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE full_name = %s ORDER BY clinic_id', $name)); // phpcs:ignore

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePost(int $clinicId, int $locationId, int $day, string $start, string $end): array
    {
        return [
            'clinician_id' => (string) $this->sharedClinician,
            'clinic_id' => (string) $clinicId,
            'sched_submit' => $day . ':' . $locationId . ':new',
            'sched' => [
                $day => [
                    $locationId => [
                        'new' => [
                            'schedule_id' => '',
                            'start_time' => $start,
                            'end_time' => $end,
                            'appointment_duration_min' => '20',
                            'slot_capacity' => '1',
                            'is_active' => '1',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scheduleRowsOf(int $clinicianId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, clinic_id, location_id, day_of_week, start_time FROM ' . $wpdb->prefix . 'cpms_schedule WHERE clinician_id = %d ORDER BY clinic_id ASC, day_of_week ASC', $clinicianId), ARRAY_A); // phpcs:ignore

        return is_array($rows) ? array_values($rows) : [];
    }

    private function clinicianActive(int $clinicianId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT is_active FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE id = %d', $clinicianId)); // phpcs:ignore
    }

    private function createOrganization(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, "active", %s, %s)', // phpcs:ignore
            'MCA Org ' . $unique,
            'mca-org-' . $unique,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: organization inserted (' . $wpdb->last_error . ')');

        return $id;
    }

    private function createClinic(string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics
                 (organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore
            $this->orgId,
            'MCA Clinic ' . $tag . ' ' . $unique,
            'mca-clinic-' . strtolower($tag) . '-' . $unique,
            self::TZ,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'precondition: clinic id is dynamic (never the seeded legacy clinic 1)');

        return $id;
    }

    /**
     * @return array{id: int, name: string}
     */
    private function createLocation(int $clinicId, string $tag): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $name = 'MCA Loc ' . $tag . ' ' . $unique;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                 (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
             VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore
            $clinicId,
            $name,
            'mca-loc-' . strtolower($tag) . '-' . $unique,
            self::TZ,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: location inserted (' . $wpdb->last_error . ')');

        return ['id' => $id, 'name' => $name];
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@mca.test');
        self::assertGreaterThan(0, $userId, 'precondition: wp user ' . $login);
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $userId;
    }

    private function insertClinician(int $clinicId, string $name, ?int $wpUserId = null): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        if ($wpUserId === null) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, NULL, 1, %s, %s)', // phpcs:ignore
                $clinicId,
                $name,
                $now,
                $now
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore
                $clinicId,
                $name,
                $wpUserId,
                $now,
                $now
            ));
        }
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: clinician row (' . $wpdb->last_error . ')');

        return $id;
    }

    private function purgeRows(): void
    {
        global $wpdb;
        $org = (int) $this->orgId;
        if ($org <= 0) {
            return;
        }
        $clinics = 'SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org;
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_schedule_slots t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_schedule_exceptions t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_schedule t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_clinician_locations t
                      JOIN ' . $wpdb->prefix . 'cpms_locations l ON l.id = t.location_id
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = l.clinic_id
                      WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE mc FROM ' . $wpdb->prefix . 'cpms_membership_capabilities mc
                      JOIN ' . $wpdb->prefix . 'cpms_clinic_memberships m ON m.id = mc.membership_id
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = m.clinic_id
                      WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE m FROM ' . $wpdb->prefix . 'cpms_clinic_memberships m
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = m.clinic_id
                      WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_clinicians t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_settings t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_locations t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = ' . $org); // phpcs:ignore
    }
}
