<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\FacilitySetupPage;
use ClinicCore\Application\Onboarding\FacilitySetupException;
use ClinicCore\Application\Onboarding\FacilitySetupService;
use ClinicCore\Application\Onboarding\OrganizationClinicProvisioningService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Top-down Organization facility setup (hospital outpatient network, medical
 * building) — real entity creation through the existing services.
 *
 * Contract under test:
 *  - Organization authority is explicit (active organization_manager membership,
 *    DENY > GRANT > preset per capability); a WordPress Administrator without that
 *    membership gets nothing, and a forged Organization is refused.
 *  - Every created Clinic belongs to the chosen Organization, every Location to
 *    its Clinic; Clinics are held suspended until explicit activation.
 *  - Clinic managers are designated explicitly; existing memberships are never
 *    overwritten; Organization authority alone never configures a Clinic.
 *  - Retries with the same server-issued request key never duplicate Clinics or
 *    rooms, including after a lost plan write.
 *  - A professional keeps ONE profile and gets separate schedules per Clinic.
 *
 * Fixture rules: dynamic Organizations, purged by Organization in tearDown.
 */
final class FacilitySetupJourneyTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private const TZ = 'Asia/Tehran';

    private int $org = 0;
    private int $otherOrg = 0;
    private int $actor = 0;
    private int $actorMembership = 0;

    /** @var list<int> */
    private array $orgMemberships = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        $this->org = $this->createOrganization('FS Org');
        $this->otherOrg = $this->createOrganization('FS Other Org');
        $this->actor = $this->makeUser('fs_orgmgr', 'subscriber');
        $this->actorMembership = $this->orgManager($this->actor, $this->org);
    }

    protected function tearDown(): void
    {
        ScopeContext::clear();
        App::resetScope();
        Settings::flushCache();
        App::settingsFactory()->reset();
        wp_set_current_user(0);
        $_GET = [];
        foreach ([$this->org, $this->otherOrg] as $org) {
            $this->purgeOrganization($org);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Hospital outpatient network — full journey
    // ------------------------------------------------------------------

    public function testHospitalOutpatientJourneyCreatesTwoClinicsRoomsSharedClinicianSchedulesAndActivates(): void
    {
        $service = FacilitySetupPage::service();
        $plan = $service->start($this->actor, $this->org, 'hospital', '', 'بیمارستان آزمون');
        self::assertSame('hospital_network', $plan['flow']);

        // Outpatient-only acknowledgment gates Clinic creation.
        $service->add_site($this->actor, $this->org, 'ساختمان شرقی', 'تهران، خیابان یک');
        $sites = $service->plan($this->actor, $this->org)['sites'];
        $this->assertRefused('ack_required', fn () => $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه قلب', $sites[0]['key'], self::TZ));
        $service->acknowledge_outpatient($this->actor, $this->org, true);
        $service->add_site($this->actor, $this->org, 'ساختمان غربی', 'تهران، خیابان دو');
        $sites = $service->plan($this->actor, $this->org)['sites'];
        self::assertCount(2, $sites);

        $clinicA = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه قلب', $sites[0]['key'], self::TZ);
        $clinicB = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه پوست', $sites[1]['key'], self::TZ);
        foreach ([$clinicA, $clinicB] as $entry) {
            self::assertSame($this->org, $this->clinicOrg((int) $entry['clinic_id']), 'Clinic belongs to the chosen Organization');
            self::assertSame((int) $entry['clinic_id'], $this->locationClinic((int) $entry['location_id']), 'primary Location belongs to its Clinic');
            self::assertSame('suspended', $this->clinicStatus((int) $entry['clinic_id']), 'held without new demand until activation');
            self::assertTrue((bool) $entry['held']);
        }
        self::assertSame('تهران، خیابان دو', (string) App::db()->fetchValue(
            'SELECT address FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d',
            [(int) $clinicB['location_id']]
        ), 'the chosen physical site address is copied to the Clinic Location (descriptive, no site entity)');

        // Organization authority alone cannot configure a Clinic.
        $this->assertRefused('clinic_denied', fn () => $service->add_room($this->actor, $this->org, $clinicA['key'], FacilitySetupService::new_request_key(), 'اتاق ۱', $sites[0]['key']));

        // Explicit manager designation (self, audited) grants Clinic authority.
        $designation = $service->designate_manager($this->actor, $this->org, $clinicA['key'], $this->actor);
        self::assertFalse($designation['noop']);
        self::assertTrue($service->designate_manager($this->actor, $this->org, $clinicA['key'], $this->actor)['noop'], 'idempotent designation');
        $service->designate_manager($this->actor, $this->org, $clinicB['key'], $this->actor);

        $roomA1 = $service->add_room($this->actor, $this->org, $clinicA['key'], FacilitySetupService::new_request_key(), 'اتاق ویزیت ۱', $sites[0]['key']);
        $roomA2 = $service->add_room($this->actor, $this->org, $clinicA['key'], FacilitySetupService::new_request_key(), 'اتاق ویزیت ۲', $sites[0]['key']);
        $roomB1 = $service->add_room($this->actor, $this->org, $clinicB['key'], FacilitySetupService::new_request_key(), 'اتاق پوست', $sites[1]['key']);
        self::assertSame((int) $clinicA['clinic_id'], $this->locationClinic((int) $roomA1['location_id']));
        self::assertSame((int) $clinicA['clinic_id'], $this->locationClinic((int) $roomA2['location_id']));
        self::assertSame((int) $clinicB['clinic_id'], $this->locationClinic((int) $roomB1['location_id']));
        self::assertSame('تهران، خیابان یک', $this->locationAddress((int) $roomA1['location_id']), 'a room Location persists the address of its chosen site');
        self::assertSame('تهران، خیابان دو', $this->locationAddress((int) $roomB1['location_id']), 'a room at another site persists that site address');

        // One professional, two Clinics: one profile, explicit membership in B.
        $doctor = $this->makeUser('fs_doctor', 'subscriber');
        $first = $service->assign_clinician($this->actor, $this->org, $clinicA['key'], $doctor, 'دکتر مشترک', [$roomA1['key']]);
        self::assertTrue($first['profile_created']);
        $second = $service->assign_clinician($this->actor, $this->org, $clinicB['key'], $doctor, '', [$roomB1['key']]);
        self::assertFalse($second['profile_created'], 'the shared professional keeps ONE profile');
        self::assertTrue($second['membership_created']);
        self::assertSame($first['clinician_id'], $second['clinician_id']);
        self::assertSame(1, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d',
            [$doctor]
        ));
        $assigned = App::membership_service()->clinician_location_ids($first['clinician_id']);
        sort($assigned);
        $expected = [(int) $roomA1['location_id'], (int) $roomB1['location_id']];
        sort($expected);
        self::assertSame($expected, $assigned, 'Location assignment is additive across Clinics');

        // Room keys of another Clinic are refused for this Clinic.
        $this->assertRefused('invalid_room', fn () => $service->assign_clinician($this->actor, $this->org, $clinicA['key'], $doctor, '', [$roomB1['key']]));

        // Separate per-Clinic, per-Location weekly schedules.
        $sA = $service->add_weekly_schedule($this->actor, $this->org, $clinicA['key'], $first['clinician_id'], $roomA1['key'], ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'appointment_duration_min' => 15, 'slot_capacity' => 1]);
        $sB = $service->add_weekly_schedule($this->actor, $this->org, $clinicB['key'], $first['clinician_id'], $roomB1['key'], ['day_of_week' => 3, 'start_time' => '14:00', 'end_time' => '18:00', 'appointment_duration_min' => 30, 'slot_capacity' => 2]);
        self::assertFalse($sA['noop']);
        self::assertTrue($service->add_weekly_schedule($this->actor, $this->org, $clinicA['key'], $first['clinician_id'], $roomA1['key'], ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'appointment_duration_min' => 15, 'slot_capacity' => 1])['noop'], 'identical retry creates no second row');
        $rows = App::db()->fetchAll(
            'SELECT clinic_id, location_id, day_of_week, appointment_duration_min, slot_capacity FROM ' . App::db()->table('cpms_schedule') . ' WHERE clinician_id = %d ORDER BY clinic_id',
            [$first['clinician_id']]
        );
        self::assertCount(2, $rows);
        $byClinic = [];
        foreach ($rows as $row) {
            $byClinic[(int) $row['clinic_id']] = $row;
        }
        self::assertSame((int) $roomA1['location_id'], (int) $byClinic[(int) $clinicA['clinic_id']]['location_id']);
        self::assertSame(15, (int) $byClinic[(int) $clinicA['clinic_id']]['appointment_duration_min']);
        self::assertSame((int) $roomB1['location_id'], (int) $byClinic[(int) $clinicB['clinic_id']]['location_id']);
        self::assertSame(2, (int) $byClinic[(int) $clinicB['clinic_id']]['slot_capacity']);
        self::assertGreaterThan(0, $sB['schedule_id']);

        // Review blocks until the booking policy exists for every Clinic.
        $review = $service->review($this->actor, $this->org);
        self::assertFalse($review['ready']);
        $this->assertRefused('not_ready', fn () => $service->activate($this->actor, $this->org, true));
        $policy = ['duration' => '15', 'capacity' => '1', 'lead' => '2', 'future' => '30', 'cancel' => '12'];
        $service->save_booking_policy($this->actor, $this->org, $clinicA['key'], $policy);
        $service->save_booking_policy($this->actor, $this->org, $clinicB['key'], ['capacity' => '2'] + $policy);
        Settings::flushCache();
        App::settingsFactory()->reset();
        self::assertSame(2, (int) (new Settings(App::db(), (int) $clinicB['clinic_id'], App::audit()))->get('booking.slot_capacity_default'));
        self::assertSame(1, (int) (new Settings(App::db(), (int) $clinicA['clinic_id'], App::audit()))->get('booking.slot_capacity_default'));

        $review = $service->review($this->actor, $this->org);
        self::assertTrue($review['ready'], implode(' | ', $review['blocking']));
        self::assertCount(2, $review['clinics']);
        $this->assertRefused('confirm_required', fn () => $service->activate($this->actor, $this->org, false));
        $activated = $service->activate($this->actor, $this->org, true);
        sort($activated['activated']);
        $ids = [(int) $clinicA['clinic_id'], (int) $clinicB['clinic_id']];
        sort($ids);
        self::assertSame($ids, $activated['activated']);
        self::assertSame('active', $this->clinicStatus((int) $clinicA['clinic_id']));
        self::assertSame('active', $this->clinicStatus((int) $clinicB['clinic_id']));
        self::assertNotSame('', (string) $service->plan($this->actor, $this->org)['activated_at']);
        self::assertTrue(App::audit()->verifyChain(5000)['ok']);
    }

    // ------------------------------------------------------------------
    // Medical building — independent Clinics, explicit managers
    // ------------------------------------------------------------------

    public function testMedicalBuildingProvisionsIndependentClinicsWithDistinctManagersAndNoSilentAccess(): void
    {
        RolesAndCapabilities::register();
        $service = FacilitySetupPage::service();
        $plan = $service->start($this->actor, $this->org, 'other', 'medical_building', 'مجتمع پزشکی آزمون');
        self::assertSame('medical_building', $plan['flow']);
        $this->assertRefused('not_hospital', fn () => $service->acknowledge_outpatient($this->actor, $this->org, true));
        $service->add_site($this->actor, $this->org, 'ساختمان اصلی', 'اصفهان');
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $a = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'مطب دندانپزشکی', $site, self::TZ);
        $b = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'مطب چشم‌پزشکی', $site, self::TZ);

        $managerA = $this->makeUser('fs_mgr_a', 'subscriber');
        $managerB = $this->makeUser('fs_mgr_b', 'subscriber');
        $service->designate_manager($this->actor, $this->org, $a['key'], $managerA);
        $service->designate_manager($this->actor, $this->org, $b['key'], $managerB);

        $authz = App::authorization_service();
        self::assertTrue($authz->can($managerA, (int) $a['clinic_id'], RolesAndCapabilities::CONFIG));
        self::assertFalse($authz->can($managerA, (int) $b['clinic_id'], RolesAndCapabilities::CONFIG), 'manager A has no authority in Clinic B');
        self::assertFalse($authz->can($managerB, (int) $a['clinic_id'], RolesAndCapabilities::PATIENT_READ), 'manager B never reads Clinic A patients');
        self::assertFalse($authz->can($this->actor, (int) $a['clinic_id'], RolesAndCapabilities::PATIENT_READ), 'Organization authority is not Clinic/PHI authority');
        self::assertNull(App::membership_service()->membership_for((int) $a['clinic_id'], $this->actor), 'no silent membership for the Organization Manager');

        $this->assertRefused('clinic_denied', fn () => $service->add_room($this->actor, $this->org, $a['key'], FacilitySetupService::new_request_key(), 'اتاق', $site));

        $review = $service->review($this->actor, $this->org);
        self::assertTrue($review['ready'], implode(' | ', $review['blocking']));
        foreach ($review['clinics'] as $clinic) {
            self::assertSame(1, $clinic['managers']);
            self::assertFalse($clinic['can_configure'], 'the Organization Manager is not a member of independent Clinics');
            self::assertSame(1, $clinic['entry_missing'], 'a subscriber manager is reported without CPMS admin entry');
            self::assertSame(1, $clinic['managers_no_entry']);
        }
        foreach ([$managerA, $managerB] as $manager) {
            self::assertSame(['subscriber'], array_values(get_userdata($manager)->roles), 'designation never changes a WordPress role');
        }
        wp_set_current_user($this->actor);
        $_GET = ['page' => FacilitySetupPage::PAGE, 'organization_id' => (string) $this->org, 'step' => 'review'];
        ob_start();
        FacilitySetupPage::render();
        $html = (string) ob_get_clean();
        self::assertSame(2, substr_count($html, 'data-cpms-facility-entry-missing="1"'), 'each Clinic shows its admin-entry gap');
        self::assertStringContainsString('data-cpms-facility-entry-guidance="2"', $html);

        // Technical WordPress step outside CPMS: the matching WordPress role opens the
        // screens for manager A only, and grants no Clinic authority anywhere.
        get_userdata($managerA)->add_role(RolesAndCapabilities::ROLE_MANAGER);
        $byClinic = [];
        foreach ($service->review($this->actor, $this->org)['clinics'] as $clinic) {
            $byClinic[(int) $clinic['clinic_id']] = $clinic;
        }
        self::assertSame(0, $byClinic[(int) $a['clinic_id']]['entry_missing'], 'manager A can now open the CPMS screens');
        self::assertSame(1, $byClinic[(int) $b['clinic_id']]['entry_missing'], 'the independent Clinic B is unaffected');
        self::assertFalse($authz->can($managerA, (int) $b['clinic_id'], RolesAndCapabilities::CONFIG), 'a WordPress role grants no Clinic authority');
        self::assertFalse($authz->can($managerA, (int) $b['clinic_id'], RolesAndCapabilities::PATIENT_READ));
        $service->activate($this->actor, $this->org, true);
        self::assertSame('active', $this->clinicStatus((int) $a['clinic_id']));
        self::assertSame('active', $this->clinicStatus((int) $b['clinic_id']));
    }

    // ------------------------------------------------------------------
    // Idempotency, retry and partial failure
    // ------------------------------------------------------------------

    public function testRetriesWithTheSameRequestKeyNeverDuplicateClinicsOrRoomsEvenAfterALostPlanWrite(): void
    {
        $service = FacilitySetupPage::service();
        $this->hospitalReady($service);
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $key = FacilitySetupService::new_request_key();
        $first = $service->add_clinic($this->actor, $this->org, $key, 'درمانگاه تکرار', $site, self::TZ);
        $again = $service->add_clinic($this->actor, $this->org, $key, 'درمانگاه تکرار', $site, self::TZ);
        self::assertSame($first['clinic_id'], $again['clinic_id']);
        self::assertSame(1, $this->clinicCount($this->org));

        // Simulate a lost plan write after the Clinic row was committed.
        $option = FacilitySetupService::OPTION_PREFIX . $this->org;
        $stored = get_option($option);
        $stored['clinics'] = [];
        update_option($option, $stored, false);
        $adopted = $service->add_clinic($this->actor, $this->org, $key, 'درمانگاه تکرار', $site, self::TZ);
        self::assertSame((int) $first['clinic_id'], (int) $adopted['clinic_id'], 'the deterministic slug is adopted, not duplicated');
        self::assertSame((int) $first['location_id'], (int) $adopted['location_id']);
        self::assertSame(1, $this->clinicCount($this->org));

        // A new submission with the same name is refused (no duplicate Clinic).
        $this->assertRefused('duplicate_clinic', fn () => $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه تکرار', $site, self::TZ));
        self::assertSame(1, $this->clinicCount($this->org));

        // Rooms: same request key → same Location; lost plan write → adopted.
        $service->designate_manager($this->actor, $this->org, $adopted['key'], $this->actor);
        $roomKey = FacilitySetupService::new_request_key();
        $room = $service->add_room($this->actor, $this->org, $adopted['key'], $roomKey, 'اتاق تکرار', $site);
        self::assertSame($room['location_id'], $service->add_room($this->actor, $this->org, $adopted['key'], $roomKey, 'اتاق تکرار', $site)['location_id']);
        $stored = get_option($option);
        $stored['clinics'][0]['rooms'] = [];
        update_option($option, $stored, false);
        $roomAgain = $service->add_room($this->actor, $this->org, $adopted['key'], $roomKey, 'اتاق تکرار', $site);
        self::assertSame((int) $room['location_id'], (int) $roomAgain['location_id']);
        self::assertSame(2, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d',
            [(int) $adopted['clinic_id']]
        ), 'primary + one room, no duplicate Location');
        $this->assertRefused('duplicate_room', fn () => $service->add_room($this->actor, $this->org, $adopted['key'], FacilitySetupService::new_request_key(), 'اتاق تکرار', $site));
    }

    public function testClinicAndRoomCreationIsAllOrNothingWithThePlanWriteSoAFailedWriteLeavesNothingBehind(): void
    {
        $service = FacilitySetupPage::service();
        $this->hospitalReady($service);
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $option = FacilitySetupService::OPTION_PREFIX . $this->org;
        $failWrite = static function (): void {
            throw new \RuntimeException('injected plan write failure');
        };
        $provisioned = $this->auditCount(OrganizationClinicProvisioningService::AUDIT_ACTION);

        // The Clinic, its primary Location, the hold and the plan entry are written in
        // ONE transaction under the Organization row lock: a failing plan write rolls
        // everything back, so no Clinic (or provisioning audit row) exists outside the plan.
        $key = FacilitySetupService::new_request_key();
        add_filter('pre_update_option_' . $option, $failWrite);
        try {
            $service->add_clinic($this->actor, $this->org, $key, 'درمانگاه همه یا هیچ', $site, self::TZ);
            self::fail('the injected plan write failure must surface');
        } catch (\RuntimeException $e) {
            self::assertSame('injected plan write failure', $e->getMessage());
        } finally {
            remove_filter('pre_update_option_' . $option, $failWrite);
        }
        self::assertSame(0, $this->clinicCount($this->org), 'no Clinic outside the plan after a failed plan write');
        self::assertSame([], $service->plan($this->actor, $this->org)['clinics']);
        self::assertSame($provisioned, $this->auditCount(OrganizationClinicProvisioningService::AUDIT_ACTION), 'no orphan provisioning audit row');

        // The page path turns an internal failure into an error notice (never a fatal) and also leaves nothing behind.
        $fields = ['request_key' => FacilitySetupService::new_request_key(), 'clinic_name' => 'درمانگاه مسیر صفحه', 'site_key' => $site, 'timezone' => self::TZ];
        $field = static fn (string $name): string => (string) ($fields[$name] ?? '');
        add_filter('pre_update_option_' . $option, $failWrite);
        try {
            self::assertStringStartsWith('خطا', FacilitySetupPage::dispatch($this->actor, $this->org, 'clinic', $field, []));
        } finally {
            remove_filter('pre_update_option_' . $option, $failWrite);
        }
        self::assertSame(0, $this->clinicCount($this->org));

        // The same request then creates exactly one held Clinic; a retry adopts it.
        $clinic = $service->add_clinic($this->actor, $this->org, $key, 'درمانگاه همه یا هیچ', $site, self::TZ);
        self::assertSame(1, $this->clinicCount($this->org));
        self::assertSame('suspended', $this->clinicStatus((int) $clinic['clinic_id']), 'held until activation');
        self::assertSame($provisioned + 1, $this->auditCount(OrganizationClinicProvisioningService::AUDIT_ACTION));
        self::assertSame((int) $clinic['clinic_id'], (int) $service->add_clinic($this->actor, $this->org, $key, 'درمانگاه همه یا هیچ', $site, self::TZ)['clinic_id']);
        self::assertSame(1, $this->clinicCount($this->org));

        // Rooms: the Location and its plan entry are written together as well.
        $service->designate_manager($this->actor, $this->org, $clinic['key'], $this->actor);
        $locations = static fn (): int => (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d',
            [(int) $clinic['clinic_id']]
        );
        $roomKey = FacilitySetupService::new_request_key();
        add_filter('pre_update_option_' . $option, $failWrite);
        try {
            $service->add_room($this->actor, $this->org, $clinic['key'], $roomKey, 'اتاق همه یا هیچ', $site);
            self::fail('the injected plan write failure must surface');
        } catch (\RuntimeException $e) {
            self::assertSame('injected plan write failure', $e->getMessage());
        } finally {
            remove_filter('pre_update_option_' . $option, $failWrite);
        }
        self::assertSame(1, $locations(), 'only the primary Location remains after a failed plan write');
        self::assertSame([], $service->plan($this->actor, $this->org)['clinics'][0]['rooms']);
        $room = $service->add_room($this->actor, $this->org, $clinic['key'], $roomKey, 'اتاق همه یا هیچ', $site);
        self::assertSame(2, $locations());
        self::assertSame((int) $room['location_id'], (int) $service->add_room($this->actor, $this->org, $clinic['key'], $roomKey, 'اتاق همه یا هیچ', $site)['location_id'], 'a retry adopts the same Location');
        self::assertSame(2, $locations());
        $this->assertRefused('duplicate_room', fn () => $service->add_room($this->actor, $this->org, $clinic['key'], FacilitySetupService::new_request_key(), 'اتاق همه یا هیچ', $site));
        self::assertSame(2, $locations(), 'a refused duplicate writes nothing');
    }

    public function testPartialProgressResumesAndTheFlowIsLockedAfterTheFirstClinic(): void
    {
        $service = FacilitySetupPage::service();
        $this->hospitalReady($service);
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه نخست', $site, self::TZ);

        // Resume: a fresh service instance sees the same durable plan.
        $resumed = FacilitySetupPage::service()->plan($this->actor, $this->org);
        self::assertCount(1, $resumed['clinics']);
        self::assertSame((int) $this->actor, (int) $resumed['outpatient_ack_by']);

        $this->assertRefused('flow_locked', fn () => $service->start($this->actor, $this->org, 'other', 'medical_building', 'تغییر جریان'));
        self::assertSame('hospital_network', $service->plan($this->actor, $this->org)['flow']);
        $this->assertRefused('incompatible_structure', fn () => $service->start($this->actor, $this->org, 'hospital', 'single', 'x'));
        $this->assertRefused('not_multi_clinic', fn () => $service->start($this->actor, $this->otherOrgManagedByActor(), 'doctor_practice', '', 'مطب'));
    }

    // ------------------------------------------------------------------
    // Authorization: forged ids, WordPress Administrator, DENY precedence
    // ------------------------------------------------------------------

    public function testForgedOrganizationAndForeignPlanKeysAreRefusedWithoutMutation(): void
    {
        $service = FacilitySetupPage::service();
        $this->assertRefused('denied', fn () => $service->start($this->actor, $this->otherOrg, 'hospital', '', 'جعلی'));
        $this->assertRefused('denied', fn () => $service->start($this->actor, PHP_INT_MAX, 'hospital', '', 'جعلی'));
        $this->assertRefused('denied', fn () => $service->plan($this->actor, 0));
        self::assertFalse(get_option(FacilitySetupService::OPTION_PREFIX . $this->otherOrg));

        // A second Organization with its own manager and plan.
        $otherManager = $this->makeUser('fs_other_mgr', 'subscriber');
        $this->orgManager($otherManager, $this->otherOrg);
        $other = FacilitySetupPage::service();
        $other->start($otherManager, $this->otherOrg, 'other', 'medical_building', 'سازمان دیگر');
        $other->add_site($otherManager, $this->otherOrg, 'ساختمان دیگر', '');
        $foreignSite = $other->plan($otherManager, $this->otherOrg)['sites'][0]['key'];
        $foreign = $other->add_clinic($otherManager, $this->otherOrg, FacilitySetupService::new_request_key(), 'درمانگاه دیگر', $foreignSite, self::TZ);

        $this->hospitalReady($service);
        $this->assertRefused('not_found', fn () => $service->designate_manager($this->actor, $this->org, $foreign['key'], $this->actor));
        $this->assertRefused('invalid_site', fn () => $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه', $foreignSite, self::TZ));
        $this->assertRefused('denied', fn () => $service->designate_manager($this->actor, $this->otherOrg, $foreign['key'], $this->actor));
        $this->assertRefused('invalid_request', fn () => $service->add_clinic($this->actor, $this->org, 'not-a-key', 'درمانگاه', 'x', self::TZ));
        self::assertNull(App::membership_service()->membership_for((int) $foreign['clinic_id'], $this->actor));
        self::assertSame(0, $this->clinicCount($this->org));

        // The Organization service itself refuses a Clinic of another Organization.
        try {
            App::organization_clinic_provisioning_service()->designate_clinic_manager($this->actor, $this->org, (int) $foreign['clinic_id'], $this->actor);
            self::fail('foreign Clinic accepted');
        } catch (\RuntimeException $e) {
            self::assertSame('clinic not found in organization', $e->getMessage());
        }
    }

    public function testWordPressAdministratorWithoutOrganizationMembershipGetsNothing(): void
    {
        $admin = $this->makeUser('fs_wpadmin', 'administrator');
        $service = FacilitySetupPage::service();
        self::assertSame([], $service->organizations($admin));
        $this->assertRefused('denied', fn () => $service->start($admin, $this->org, 'hospital', '', 'ادمین'));

        wp_set_current_user($admin);
        $_GET = ['page' => FacilitySetupPage::PAGE, 'organization_id' => (string) $this->org];
        ob_start();
        FacilitySetupPage::render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('data-cpms-facility-setup="denied"', $html);
        self::assertStringNotContainsString('data-cpms-facility-form', $html);
        self::assertStringContainsString('dir="rtl"', $html);
    }

    public function testExplicitDenyWinsForEachOrganizationCapability(): void
    {
        $service = FacilitySetupPage::service();
        $this->hospitalReady($service);
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $clinic = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه منع', $site, self::TZ);

        $this->orgCapability(OrganizationClinicProvisioningService::CAPABILITY_CLINIC_MANAGER, 'deny');
        $target = $this->makeUser('fs_target', 'subscriber');
        $this->assertRefused('denied', fn () => $service->designate_manager($this->actor, $this->org, $clinic['key'], $target));
        self::assertNull(App::membership_service()->membership_for((int) $clinic['clinic_id'], $target));

        $this->orgCapability(OrganizationClinicProvisioningService::CAPABILITY_LIFECYCLE, 'deny');
        $this->assertRefused('denied', fn () => $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه دوم', $site, self::TZ));
        $this->assertRefused('denied', fn () => $service->activate($this->actor, $this->org, true));
        self::assertSame(1, $this->clinicCount($this->org));
    }

    public function testExistingMembershipIsNeverOverwrittenAndInactiveLocationsAndSuspendedMembershipsFailClosed(): void
    {
        $service = FacilitySetupPage::service();
        $this->hospitalReady($service);
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $a = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه الف', $site, self::TZ);
        $b = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه ب', $site, self::TZ);
        $service->designate_manager($this->actor, $this->org, $a['key'], $this->actor);
        $service->designate_manager($this->actor, $this->org, $b['key'], $this->actor);

        // A suspended secretary membership is not re-roled into a manager.
        $secretary = $this->makeUser('fs_secretary', 'subscriber');
        $membershipId = App::membership_service()->create_membership((int) $a['clinic_id'], $secretary, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->suspend_membership($membershipId);
        $this->assertRefused('membership_exists', fn () => $service->designate_manager($this->actor, $this->org, $a['key'], $secretary));
        $row = App::membership_service()->membership_for((int) $a['clinic_id'], $secretary);
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $row['role_key']);
        self::assertSame('suspended', (string) $row['status']);

        // Inactive room is refused for assignment and scheduling.
        $room = $service->add_room($this->actor, $this->org, $a['key'], FacilitySetupService::new_request_key(), 'اتاق غیرفعال', $site);
        App::locationService()->set_active($this->actor, (int) $a['clinic_id'], (int) $room['location_id'], false);
        $doctor = $this->makeUser('fs_doc2', 'subscriber');
        $this->assertRefused('inactive_location', fn () => $service->assign_clinician($this->actor, $this->org, $a['key'], $doctor, 'دکتر آزمون', [$room['key']]));
        $assigned = $service->assign_clinician($this->actor, $this->org, $a['key'], $doctor, 'دکتر آزمون', ['primary']);
        $this->assertRefused('inactive_location', fn () => $service->add_weekly_schedule($this->actor, $this->org, $a['key'], $assigned['clinician_id'], $room['key'], ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00']));

        // A suspended participation in B fails closed (no silent reactivation).
        $membershipB = App::membership_service()->create_membership((int) $b['clinic_id'], $doctor, RolesAndCapabilities::ROLE_DOCTOR);
        App::membership_service()->suspend_membership($membershipB);
        $this->assertRefused('membership_suspended', fn () => $service->assign_clinician($this->actor, $this->org, $b['key'], $doctor, '', ['primary']));
        $this->assertRefused('invalid_schedule', fn () => $service->add_weekly_schedule($this->actor, $this->org, $b['key'], $assigned['clinician_id'], 'primary', ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00']));
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule') . ' WHERE clinician_id = %d',
            [$assigned['clinician_id']]
        ));
    }

    public function testDispatcherAndReviewRenderAreFacilityAwareRtlAndNeverPreselect(): void
    {
        wp_set_current_user($this->actor);
        $_GET = ['page' => FacilitySetupPage::PAGE];
        ob_start();
        FacilitySetupPage::render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('id="cpms_facility_org"', $html);
        self::assertStringNotContainsString('selected', $html, 'no Organization is pre-selected');

        $fields = ['facility_type' => 'hospital', 'structure' => '', 'display_name' => 'بیمارستان نمایشی'];
        $field = static fn (string $key): string => (string) ($fields[$key] ?? '');
        self::assertStringStartsNotWith('خطا', FacilitySetupPage::dispatch($this->actor, $this->org, 'start', $field, []));
        self::assertStringStartsWith('خطا', FacilitySetupPage::dispatch($this->actor, $this->org, 'bogus', $field, []));
        self::assertStringStartsWith('خطا', FacilitySetupPage::dispatch($this->actor, $this->otherOrg, 'start', $field, []));

        $_GET = ['page' => FacilitySetupPage::PAGE, 'organization_id' => (string) $this->org, 'step' => 'review'];
        ob_start();
        FacilitySetupPage::render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('data-cpms-facility-flow="hospital_network"', $html);
        self::assertStringContainsString('data-cpms-inpatient-unsupported="1"', $html);
        self::assertStringContainsString('data-cpms-facility-review="blocked"', $html);
        self::assertStringContainsString('درمانگاه‌های سرپایی', $html);

        $_GET = ['page' => FacilitySetupPage::PAGE, 'organization_id' => (string) $this->otherOrg];
        ob_start();
        FacilitySetupPage::render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('data-cpms-facility-setup="org-denied"', $html);
        self::assertStringNotContainsString('data-cpms-facility-flow', $html);
    }

    // ------------------------------------------------------------------
    // Step 6 — staff roles with explicit Clinic/Location permissions
    // ------------------------------------------------------------------

    public function testStaffRolesCarryExplicitClinicOrLocationScopeDenyOnlyOverridesAndNeverOverwrite(): void
    {
        $service = FacilitySetupPage::service();
        $this->hospitalReady($service);
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $a = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه کارکنان الف', $site, self::TZ);
        $b = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه کارکنان ب', $site, self::TZ);
        $secretary = $this->makeUser('fs_staff_sec', 'subscriber');
        $staffAuditBefore = $this->auditCount(FacilitySetupService::STAFF_AUDIT_ACTION);

        // Organization authority alone configures no Clinic staff.
        $this->assertRefused('clinic_denied', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_SECRETARY, 'clinic', [], []));
        $service->designate_manager($this->actor, $this->org, $a['key'], $this->actor);
        $service->designate_manager($this->actor, $this->org, $b['key'], $this->actor);
        $roomA = $service->add_room($this->actor, $this->org, $a['key'], FacilitySetupService::new_request_key(), 'اتاق پذیرش الف', $site);
        $roomB = $service->add_room($this->actor, $this->org, $b['key'], FacilitySetupService::new_request_key(), 'اتاق پذیرش ب', $site);

        // Every choice is explicit; anything outside the role preset or this Clinic writes nothing.
        foreach ([RolesAndCapabilities::ROLE_MANAGER, RolesAndCapabilities::ROLE_DOCTOR, RolesAndCapabilities::ROLE_PATIENT, 'administrator', ''] as $role) {
            $this->assertRefused('invalid_role', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, $role, 'clinic', [], []));
        }
        $this->assertRefused('invalid_access', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_SECRETARY, '', [], []));
        $this->assertRefused('invalid_access', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_SECRETARY, 'clinic', [$roomA['key']], []));
        $this->assertRefused('invalid_room', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_SECRETARY, 'locations', [], []));
        $this->assertRefused('invalid_room', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_SECRETARY, 'locations', [$roomB['key']], []));
        $this->assertRefused('invalid_capability', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_SECRETARY, 'locations', [$roomA['key']], [RolesAndCapabilities::CONFIG]));
        $this->assertRefused('invalid_capability', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_SECRETARY, 'locations', [$roomA['key']], [RolesAndCapabilities::PAYMENT_VOID]));
        $wpAdmin = $this->makeUser('fs_staff_wpadmin', 'administrator');
        $this->assertRefused('user_not_eligible', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $wpAdmin, RolesAndCapabilities::ROLE_SECRETARY, 'clinic', [], []));
        $this->assertRefused('user_not_eligible', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], 0, RolesAndCapabilities::ROLE_SECRETARY, 'clinic', [], []));
        $this->assertRefused('not_found', fn () => $service->assign_staff($this->actor, $this->org, 'c-forged', $secretary, RolesAndCapabilities::ROLE_SECRETARY, 'clinic', [], []));
        self::assertNull(App::membership_service()->membership_for((int) $a['clinic_id'], $secretary), 'refusals wrote nothing');
        self::assertNull(App::membership_service()->membership_for((int) $a['clinic_id'], $wpAdmin), 'a WordPress Administrator is not a staff account');

        // Location-scoped reception with one explicit DENY, written atomically and audited.
        $result = $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_SECRETARY, 'locations', [$roomA['key']], [RolesAndCapabilities::INVOICE_VOID]);
        self::assertFalse($result['noop']);
        self::assertSame('location', $result['scope_mode']);
        self::assertSame([(int) $roomA['location_id']], $result['location_ids']);
        self::assertSame([RolesAndCapabilities::INVOICE_VOID], $result['denied']);
        $membership = App::membership_service()->membership_for((int) $a['clinic_id'], $secretary);
        self::assertNotNull($membership);
        self::assertSame((int) $membership['id'], $result['membership_id']);
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $membership['role_key']);
        self::assertSame('location', (string) $membership['scope_mode']);
        self::assertSame('active', (string) $membership['status']);
        self::assertSame([(int) $roomA['location_id']], App::membership_service()->membership_location_ids((int) $membership['id']));
        self::assertSame(
            [['capability' => RolesAndCapabilities::INVOICE_VOID, 'effect' => 'deny']],
            array_map(static fn (array $row): array => ['capability' => (string) $row['capability'], 'effect' => (string) $row['effect']], App::membership_service()->capabilities_for((int) $membership['id']))
        );
        self::assertSame($staffAuditBefore + 1, $this->auditCount(FacilitySetupService::STAFF_AUDIT_ACTION));

        $authz = App::authorization_service();
        self::assertFalse($authz->can($secretary, (int) $a['clinic_id'], RolesAndCapabilities::INVOICE_VOID), 'explicit DENY beats the role preset');
        self::assertTrue($authz->can($secretary, (int) $a['clinic_id'], RolesAndCapabilities::APPT_CREATE), 'the rest of the preset applies');
        self::assertFalse($authz->can($secretary, (int) $a['clinic_id'], RolesAndCapabilities::CONFIG), 'no widening beyond the preset');
        self::assertFalse($authz->can($secretary, (int) $b['clinic_id'], RolesAndCapabilities::APPT_READ), 'no access in the other Clinic');
        $establisher = new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db()));
        self::assertSame((int) $a['clinic_id'], $establisher->establish($secretary, (int) $a['clinic_id'], (int) $roomA['location_id'])->clinicId);
        try {
            $establisher->establish($secretary, (int) $a['clinic_id'], (int) $a['location_id']);
            self::fail('a location-scoped staff member must not bind to an unassigned Location');
        } catch (ScopeRequiredException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        // Identical retry is a no-op; any different request is refused and nothing is overwritten.
        $retry = $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_SECRETARY, 'locations', [$roomA['key']], [RolesAndCapabilities::INVOICE_VOID]);
        self::assertTrue($retry['noop']);
        self::assertSame((int) $membership['id'], $retry['membership_id']);
        self::assertSame($staffAuditBefore + 1, $this->auditCount(FacilitySetupService::STAFF_AUDIT_ACTION), 'a no-op retry is not re-audited');
        $this->assertRefused('membership_exists', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_SECRETARY, 'clinic', [], [RolesAndCapabilities::INVOICE_VOID]));
        $this->assertRefused('membership_exists', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_ACCOUNTANT, 'locations', [$roomA['key']], [RolesAndCapabilities::INVOICE_VOID]));
        $this->assertRefused('membership_exists', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $secretary, RolesAndCapabilities::ROLE_SECRETARY, 'locations', [$roomA['key']], []));
        $this->assertRefused('membership_exists', fn () => $service->assign_staff($this->actor, $this->org, $a['key'], $this->actor, RolesAndCapabilities::ROLE_SECRETARY, 'clinic', [], []));
        $after = App::membership_service()->membership_for((int) $a['clinic_id'], $secretary);
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $after['role_key']);
        self::assertSame('location', (string) $after['scope_mode']);
        self::assertSame(OrganizationClinicProvisioningService::CLINIC_MANAGER_ROLE_KEY, (string) App::membership_service()->membership_for((int) $a['clinic_id'], $this->actor)['role_key'], 'the manager membership is never re-roled');
        self::assertSame(1, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d AND wp_user_id = %d',
            [(int) $a['clinic_id'], $secretary]
        ));

        // Clinic-wide finance role in the other Clinic: no clinical access, no Location rows.
        $accountant = $this->makeUser('fs_staff_acc', 'subscriber');
        $wide = $service->assign_staff($this->actor, $this->org, $b['key'], $accountant, RolesAndCapabilities::ROLE_ACCOUNTANT, 'clinic', [], []);
        self::assertSame('clinic', $wide['scope_mode']);
        self::assertSame([], App::membership_service()->membership_location_ids($wide['membership_id']));
        self::assertTrue($authz->can($accountant, (int) $b['clinic_id'], RolesAndCapabilities::FINANCE_READ));
        self::assertFalse($authz->can($accountant, (int) $b['clinic_id'], RolesAndCapabilities::PATIENT_READ), 'the finance preset carries no clinical access');
        self::assertFalse($authz->can($accountant, (int) $a['clinic_id'], RolesAndCapabilities::FINANCE_READ), 'no access in the first Clinic');

        // A suspended staff membership is never silently re-activated by a retry.
        App::membership_service()->suspend_membership($wide['membership_id']);
        $this->assertRefused('membership_exists', fn () => $service->assign_staff($this->actor, $this->org, $b['key'], $accountant, RolesAndCapabilities::ROLE_ACCOUNTANT, 'clinic', [], []));
        self::assertSame('suspended', (string) App::membership_service()->membership_for((int) $b['clinic_id'], $accountant)['status']);

        // An inactive Location is refused for a location-scoped role.
        App::locationService()->set_active($this->actor, (int) $b['clinic_id'], (int) $roomB['location_id'], false);
        $reception = $this->makeUser('fs_staff_rec', 'subscriber');
        $this->assertRefused('inactive_location', fn () => $service->assign_staff($this->actor, $this->org, $b['key'], $reception, RolesAndCapabilities::ROLE_SECRETARY, 'locations', [$roomB['key']], []));
        self::assertNull(App::membership_service()->membership_for((int) $b['clinic_id'], $reception));

        // Live review and the actor's own roster reflect the real rows.
        $review = $service->review($this->actor, $this->org);
        $byClinic = [];
        foreach ($review['clinics'] as $clinic) {
            $byClinic[(int) $clinic['clinic_id']] = $clinic;
        }
        self::assertSame(1, $byClinic[(int) $a['clinic_id']]['staff']);
        self::assertSame(1, $byClinic[(int) $a['clinic_id']]['staff_location_scope']);
        self::assertSame(0, $byClinic[(int) $b['clinic_id']]['staff'], 'a suspended membership is not counted as active staff');
        $roster = $service->clinic_staff($this->actor, $this->org, $a['key']);
        self::assertCount(1, $roster);
        self::assertSame('location', $roster[0]['scope_mode']);
        self::assertSame(1, $roster[0]['locations']);
        self::assertSame(1, $roster[0]['denied']);
        self::assertFalse($roster[0]['entry'], 'a subscriber staff account is reported without CPMS admin entry; its WordPress role is untouched');
        self::assertSame(['subscriber'], array_values(get_userdata($secretary)->roles));

        // The admin-post dispatcher path and the rendered step are explicit (no preselection).
        $front = $this->makeUser('fs_staff_front', 'subscriber');
        $frontLogin = (string) get_userdata($front)->user_login;
        $fields = ['clinic_key' => $a['key'], 'user_login' => $frontLogin, 'role_key' => RolesAndCapabilities::ROLE_SECRETARY, 'access' => 'locations'];
        $field = static fn (string $key): string => (string) ($fields[$key] ?? '');
        self::assertStringStartsWith('خطا', FacilitySetupPage::dispatch($this->actor, $this->org, 'staff', $field, [$roomB['key']], []));
        self::assertNull(App::membership_service()->membership_for((int) $a['clinic_id'], $front));
        self::assertStringStartsNotWith('خطا', FacilitySetupPage::dispatch($this->actor, $this->org, 'staff', $field, ['primary'], [RolesAndCapabilities::FILE_READ]));
        self::assertSame('location', (string) App::membership_service()->membership_for((int) $a['clinic_id'], $front)['scope_mode']);

        wp_set_current_user($this->actor);
        $_GET = ['page' => FacilitySetupPage::PAGE, 'organization_id' => (string) $this->org, 'step' => 'staff'];
        ob_start();
        FacilitySetupPage::render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('data-cpms-facility-form="staff"', $html);
        self::assertStringContainsString('<option value="">انتخاب صریح نقش</option>', $html);
        self::assertStringContainsString('name="access" value="clinic" required>', $html);
        self::assertStringNotContainsString('checked', $html, 'no access scope, room or DENY is pre-selected');
        self::assertStringNotContainsString('selected', $html, 'no Clinic or role is pre-selected');
        self::assertStringContainsString('data-cpms-facility-staff-roster="' . $a['key'] . '"', $html);
        self::assertStringNotContainsString('value="' . RolesAndCapabilities::ROLE_MANAGER . '"', $html, 'the manager role is not offered by the staff step');
    }

    public function testSuspendedOrganizationRefusesEveryFacilityStepWithoutMutationUntilReactivated(): void
    {
        $service = FacilitySetupPage::service();
        $this->hospitalReady($service);
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $clinic = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه تعلیق', $site, self::TZ);
        $service->designate_manager($this->actor, $this->org, $clinic['key'], $this->actor);
        $option = FacilitySetupService::OPTION_PREFIX . $this->org;
        $planBefore = get_option($option);
        $staff = $this->makeUser('fs_susp_staff', 'subscriber');
        $doctor = $this->makeUser('fs_susp_doc', 'subscriber');
        $planAudits = $this->auditCount(FacilitySetupService::PLAN_AUDIT_ACTION);
        self::assertGreaterThan(0, $planAudits, 'Organization-level plan changes (identity, scope, sites, Clinics) are audited');

        App::db()->update('cpms_organizations', ['status' => 'suspended'], ['id' => $this->org]);
        $listed = array_map(static fn (array $org): int => (int) $org['id'], $service->organizations($this->actor));
        self::assertNotContains($this->org, $listed, 'a suspended Organization is not offered');
        $this->assertRefused('denied', fn () => $service->plan($this->actor, $this->org));
        $this->assertRefused('denied', fn () => $service->add_site($this->actor, $this->org, 'ساختمان تازه', ''));
        $this->assertRefused('denied', fn () => $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه تازه', $site, self::TZ));
        $this->assertRefused('denied', fn () => $service->designate_manager($this->actor, $this->org, $clinic['key'], $staff));
        $this->assertRefused('denied', fn () => $service->add_room($this->actor, $this->org, $clinic['key'], FacilitySetupService::new_request_key(), 'اتاق تازه', $site));
        $this->assertRefused('denied', fn () => $service->assign_clinician($this->actor, $this->org, $clinic['key'], $doctor, 'دکتر تعلیق', ['primary']));
        $this->assertRefused('denied', fn () => $service->assign_staff($this->actor, $this->org, $clinic['key'], $staff, RolesAndCapabilities::ROLE_SECRETARY, 'clinic', [], []));
        $this->assertRefused('denied', fn () => $service->save_booking_policy($this->actor, $this->org, $clinic['key'], ['duration' => '20', 'capacity' => '1', 'lead' => '2', 'future' => '60', 'cancel' => '24']));
        $this->assertRefused('denied', fn () => $service->review($this->actor, $this->org));
        $this->assertRefused('denied', fn () => $service->activate($this->actor, $this->org, true));
        $field = static fn (string $key): string => 'site_label' === $key ? 'ساختمان دیگر' : '';
        self::assertStringStartsWith('خطا', FacilitySetupPage::dispatch($this->actor, $this->org, 'site', $field, []));

        self::assertSame($planBefore, get_option($option), 'the plan is unchanged');
        self::assertSame(1, $this->clinicCount($this->org));
        self::assertSame(1, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d', [(int) $clinic['clinic_id']]));
        self::assertNull(App::membership_service()->membership_for((int) $clinic['clinic_id'], $staff));
        self::assertNull(App::membership_service()->membership_for((int) $clinic['clinic_id'], $doctor));
        self::assertSame('suspended', $this->clinicStatus((int) $clinic['clinic_id']), 'held Clinic stays held');
        self::assertSame($planAudits, $this->auditCount(FacilitySetupService::PLAN_AUDIT_ACTION), 'refused steps are not audited as changes');

        App::db()->update('cpms_organizations', ['status' => 'active'], ['id' => $this->org]);
        $service->add_site($this->actor, $this->org, 'ساختمان پس از رفع تعلیق', '');
        self::assertCount(2, $service->plan($this->actor, $this->org)['sites'], 'setup resumes after explicit reactivation');
        self::assertSame($planAudits + 1, $this->auditCount(FacilitySetupService::PLAN_AUDIT_ACTION), 'the site change is audited once');
        $service->add_site($this->actor, $this->org, 'ساختمان پس از رفع تعلیق', '');
        self::assertCount(2, $service->plan($this->actor, $this->org)['sites'], 'an identical retry adds no site');
        self::assertSame($planAudits + 1, $this->auditCount(FacilitySetupService::PLAN_AUDIT_ACTION), 'an identical retry is not re-audited');
    }

    // ------------------------------------------------------------------
    // Pre-merge hardening: fail-closed hold, atomic clinician step,
    // authorize-before-identify manager designation
    // ------------------------------------------------------------------

    public function testAFailedClinicHoldFailsClosedSoNoActiveBookableClinicEscapes(): void
    {
        $service = FacilitySetupPage::service();
        $this->hospitalReady($service);
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $clinics = App::db()->table('cpms_clinics');
        $isHold = static fn (string $sql): bool => 1 === preg_match('/^\s*UPDATE\s+`?' . preg_quote($clinics, '/') . '`?\s+SET\s+`?status`?\s*=\s*\'suspended\'/i', $sql);
        $injections = [
            // The lifecycle UPDATE raises (a database failure surfaced as an exception).
            'exception' => static function (string $sql) use ($isHold): string {
                if ($isHold($sql)) {
                    throw new \RuntimeException('injected hold failure');
                }

                return $sql;
            },
            // The lifecycle UPDATE silently matches no row (no exception anywhere).
            'no_row' => static fn (string $sql): string => $isHold($sql) ? (string) preg_replace('/\bWHERE\b/i', 'WHERE 1 = 0 AND', $sql, 1) : $sql,
        ];
        $provisioned = $this->auditCount(OrganizationClinicProvisioningService::AUDIT_ACTION);
        $deactivated = $this->auditCount('CLINIC_DEACTIVATED');
        $planChanges = $this->auditCount(FacilitySetupService::PLAN_AUDIT_ACTION);
        $nothingEscaped = function (string $label) use ($service, $provisioned, $deactivated, $planChanges): void {
            self::assertSame(0, $this->clinicCount($this->org), $label . ': no Clinic survives a failed hold');
            self::assertSame(0, (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics') . ' WHERE organization_id = %d AND COALESCE(status, %s) = %s',
                [$this->org, 'active', 'active']
            ), $label . ': no active (bookable) Clinic escapes');
            self::assertSame([], $service->plan($this->actor, $this->org)['clinics'], $label . ': the setup never records an unheld Clinic');
            self::assertSame($provisioned, $this->auditCount(OrganizationClinicProvisioningService::AUDIT_ACTION), $label . ': provisioning audit rolled back');
            self::assertSame($deactivated, $this->auditCount('CLINIC_DEACTIVATED'), $label);
            self::assertSame($planChanges, $this->auditCount(FacilitySetupService::PLAN_AUDIT_ACTION), $label);
            self::assertTrue(App::audit()->verifyChain(5000)['ok'], $label . ': audit hash chain intact after the rollback');
        };

        $key = FacilitySetupService::new_request_key();
        foreach ($injections as $label => $inject) {
            add_filter('query', $inject);
            try {
                $this->assertRefused('hold_failed', fn () => $service->add_clinic($this->actor, $this->org, $key, 'درمانگاه نگه‌داشته', $site, self::TZ));
            } finally {
                remove_filter('query', $inject);
            }
            $nothingEscaped($label);
        }

        // The page path reports an error notice and leaves nothing behind either.
        $fields = ['request_key' => $key, 'clinic_name' => 'درمانگاه نگه‌داشته', 'site_key' => $site, 'timezone' => self::TZ];
        $field = static fn (string $name): string => (string) ($fields[$name] ?? '');
        add_filter('query', $injections['exception']);
        try {
            self::assertStringStartsWith('خطا', FacilitySetupPage::dispatch($this->actor, $this->org, 'clinic', $field, []));
        } finally {
            remove_filter('query', $injections['exception']);
        }
        $nothingEscaped('page');

        // Once holding works, the same request creates exactly one held Clinic.
        $clinic = $service->add_clinic($this->actor, $this->org, $key, 'درمانگاه نگه‌داشته', $site, self::TZ);
        self::assertSame(1, $this->clinicCount($this->org));
        self::assertSame('suspended', $this->clinicStatus((int) $clinic['clinic_id']), 'held: no new demand before explicit activation');
        self::assertTrue((bool) $clinic['held']);
        self::assertSame($provisioned + 1, $this->auditCount(OrganizationClinicProvisioningService::AUDIT_ACTION));
        self::assertSame($deactivated + 1, $this->auditCount('CLINIC_DEACTIVATED'), 'the hold is audited through the lifecycle service');
        self::assertSame((int) $clinic['clinic_id'], (int) $service->add_clinic($this->actor, $this->org, $key, 'درمانگاه نگه‌داشته', $site, self::TZ)['clinic_id'], 'a retry adopts it');
        self::assertSame(1, $this->clinicCount($this->org));
        self::assertTrue(App::audit()->verifyChain(5000)['ok']);
    }

    public function testAFailedClinicianAssignmentLeavesNoPartialStateAndTheRetryIsAuditedExactlyOnce(): void
    {
        $service = FacilitySetupPage::service();
        $this->hospitalReady($service);
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $clinic = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه اتمی', $site, self::TZ);
        $service->designate_manager($this->actor, $this->org, $clinic['key'], $this->actor);
        $room = $service->add_room($this->actor, $this->org, $clinic['key'], FacilitySetupService::new_request_key(), 'اتاق اتمی', $site);
        $doctor = $this->makeUser('fs_atomic_doc', 'subscriber');
        $login = (string) get_userdata($doctor)->user_login;
        $links = App::db()->table('cpms_clinician_locations');
        // The LAST write of the step fails: profile and membership were already written.
        $failLink = static function (string $sql) use ($links): string {
            if (1 === preg_match('/^\s*INSERT\s+INTO\s+`?' . preg_quote($links, '/') . '`?\s/i', $sql)) {
                throw new \RuntimeException('injected clinician location failure');
            }

            return $sql;
        };
        $created = $this->auditCount('CLINICIAN_CREATED');
        $assigned = $this->auditCount(FacilitySetupService::CLINICIAN_AUDIT_ACTION);
        $nothingLeft = function (string $label) use ($clinic, $doctor, $created, $assigned): void {
            self::assertSame(0, $this->profileCount($doctor), $label . ': no partial clinician profile');
            self::assertNull(App::membership_service()->membership_for((int) $clinic['clinic_id'], $doctor), $label . ': no partial membership');
            self::assertSame(0, $this->clinicianLinkCount((int) $clinic['clinic_id']), $label . ': no partial Location association');
            self::assertSame($created, $this->auditCount('CLINICIAN_CREATED'), $label . ': no audit row for a rolled-back profile');
            self::assertSame($assigned, $this->auditCount(FacilitySetupService::CLINICIAN_AUDIT_ACTION), $label);
            self::assertTrue(App::audit()->verifyChain(5000)['ok'], $label . ': audit hash chain intact after the rollback');
        };

        add_filter('query', $failLink);
        try {
            $service->assign_clinician($this->actor, $this->org, $clinic['key'], $doctor, 'دکتر اتمی', [$room['key']]);
            self::fail('the injected failure must surface');
        } catch (\RuntimeException $e) {
            self::assertSame('injected clinician location failure', $e->getMessage());
        } finally {
            remove_filter('query', $failLink);
        }
        $nothingLeft('service');

        $fields = ['clinic_key' => $clinic['key'], 'user_login' => $login, 'full_name' => 'دکتر اتمی'];
        $field = static fn (string $name): string => (string) ($fields[$name] ?? '');
        add_filter('query', $failLink);
        try {
            self::assertStringStartsWith('خطا', FacilitySetupPage::dispatch($this->actor, $this->org, 'clinician', $field, [$room['key']]));
        } finally {
            remove_filter('query', $failLink);
        }
        $nothingLeft('page');

        // The retry succeeds exactly once and is audited; an identical retry is a no-op.
        $first = $service->assign_clinician($this->actor, $this->org, $clinic['key'], $doctor, 'دکتر اتمی', [$room['key']]);
        self::assertTrue($first['profile_created']);
        self::assertTrue($first['membership_created']);
        self::assertFalse($first['noop']);
        self::assertSame(1, $this->profileCount($doctor));
        $membership = App::membership_service()->membership_for((int) $clinic['clinic_id'], $doctor);
        self::assertNotNull($membership);
        self::assertSame('active', (string) $membership['status']);
        self::assertSame(RolesAndCapabilities::ROLE_DOCTOR, (string) $membership['role_key']);
        self::assertSame([(int) $room['location_id']], App::membership_service()->clinician_location_ids($first['clinician_id']));
        self::assertSame($created + 1, $this->auditCount('CLINICIAN_CREATED'));
        self::assertSame($assigned + 1, $this->auditCount(FacilitySetupService::CLINICIAN_AUDIT_ACTION));

        $again = $service->assign_clinician($this->actor, $this->org, $clinic['key'], $doctor, 'دکتر اتمی', [$room['key']]);
        self::assertTrue($again['noop'], 'an identical retry writes nothing');
        self::assertSame($first['clinician_id'], $again['clinician_id']);
        self::assertSame(1, $this->profileCount($doctor));
        self::assertSame(1, $this->clinicianLinkCount((int) $clinic['clinic_id']));
        self::assertSame($created + 1, $this->auditCount('CLINICIAN_CREATED'), 'no second audit row for a no-op');
        self::assertSame($assigned + 1, $this->auditCount(FacilitySetupService::CLINICIAN_AUDIT_ACTION));

        $row = App::db()->fetchRow(
            'SELECT resource_type, resource_id, clinic_id, after_json FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            [FacilitySetupService::CLINICIAN_AUDIT_ACTION]
        );
        self::assertNotNull($row);
        self::assertSame('clinic_membership', (string) $row['resource_type']);
        self::assertSame((int) $membership['id'], (int) $row['resource_id']);
        self::assertSame((int) $clinic['clinic_id'], (int) $row['clinic_id']);
        $after = json_decode((string) $row['after_json'], true);
        self::assertSame([(int) $room['location_id']], $after['location_ids'], 'the audit row lists this Clinic\'s Locations');
        self::assertTrue($after['membership_created']);
        self::assertTrue(App::audit()->verifyChain(5000)['ok']);
    }

    public function testASuspendedMembershipIsRefusedBeforeAnyProfileIsWritten(): void
    {
        $service = FacilitySetupPage::service();
        $this->hospitalReady($service);
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $clinic = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه تعلیق', $site, self::TZ);
        $service->designate_manager($this->actor, $this->org, $clinic['key'], $this->actor);
        $doctor = $this->makeUser('fs_susp_first', 'subscriber');
        $membershipId = App::membership_service()->create_membership((int) $clinic['clinic_id'], $doctor, RolesAndCapabilities::ROLE_DOCTOR);
        App::membership_service()->suspend_membership($membershipId);
        $created = $this->auditCount('CLINICIAN_CREATED');
        $assigned = $this->auditCount(FacilitySetupService::CLINICIAN_AUDIT_ACTION);

        $this->assertRefused('membership_suspended', fn () => $service->assign_clinician($this->actor, $this->org, $clinic['key'], $doctor, 'دکتر معلق', ['primary']));
        self::assertSame(0, $this->profileCount($doctor), 'the suspended membership is detected before any profile is created');
        self::assertSame(0, $this->clinicianLinkCount((int) $clinic['clinic_id']));
        $row = App::membership_service()->membership_for((int) $clinic['clinic_id'], $doctor);
        self::assertNotNull($row);
        self::assertSame('suspended', (string) $row['status'], 'never re-activated implicitly');
        self::assertSame(RolesAndCapabilities::ROLE_DOCTOR, (string) $row['role_key']);
        self::assertSame($created, $this->auditCount('CLINICIAN_CREATED'));
        self::assertSame($assigned, $this->auditCount(FacilitySetupService::CLINICIAN_AUDIT_ACTION));
    }

    public function testUnauthorizedManagerDesignationNeverRevealsWhetherTheTargetUserExists(): void
    {
        $service = FacilitySetupPage::service();
        $provisioning = App::organization_clinic_provisioning_service();
        $this->hospitalReady($service);
        $site = $service->plan($this->actor, $this->org)['sites'][0]['key'];
        $clinic = $service->add_clinic($this->actor, $this->org, FacilitySetupService::new_request_key(), 'درمانگاه کاوش', $site, self::TZ);
        $existing = $this->makeUser('fs_probe_target', 'subscriber');
        $existingLogin = (string) get_userdata($existing)->user_login;
        $missing = 2147483000;
        self::assertFalse(get_userdata($missing), 'precondition: no such user');
        $designated = $this->auditCount(OrganizationClinicProvisioningService::MANAGER_AUDIT_ACTION);
        $notice = fn (string $clinicKey, string $login): string => FacilitySetupPage::dispatch(
            $this->actor,
            $this->org,
            'manager',
            static fn (string $name): string => (string) (['clinic_key' => $clinicKey, 'user_login' => $login][$name] ?? ''),
            []
        );

        // 1) Explicit DENY of cpms_org_clinic_manager_assign: one refusal for an existing
        //    user, a nonexistent user and invalid ids — service, Organization service, page.
        $this->orgCapability(OrganizationClinicProvisioningService::CAPABILITY_CLINIC_MANAGER, 'deny');
        $messages = [];
        foreach ([$existing, $missing, 0, -7] as $target) {
            $messages[] = $this->refusalMessage('denied', fn () => $service->designate_manager($this->actor, $this->org, $clinic['key'], $target));
            self::assertSame(
                \RuntimeException::class . ': organization clinic manager designation denied',
                $this->provisioningRefusal(fn () => $provisioning->designate_clinic_manager($this->actor, $this->org, (int) $clinic['clinic_id'], $target)),
                'DENY is decided before the target user is evaluated'
            );
        }
        self::assertCount(1, array_unique($messages), 'identical refusal text for every target');
        $deniedNotice = $notice($clinic['key'], $existingLogin);
        self::assertStringStartsWith('خطا', $deniedNotice);
        self::assertSame($deniedNotice, $notice($clinic['key'], 'fs_no_such_login_' . bin2hex(random_bytes(3))));
        self::assertSame($deniedNotice, $notice($clinic['key'], 'nobody_' . bin2hex(random_bytes(3)) . '@fs.test'));

        // 2) Foreign Organization (the actor is not its manager): one refusal for every target.
        $otherManager = $this->makeUser('fs_probe_other', 'subscriber');
        $this->orgManager($otherManager, $this->otherOrg);
        $other = FacilitySetupPage::service();
        $other->start($otherManager, $this->otherOrg, 'other', 'medical_building', 'سازمان دیگر');
        $other->add_site($otherManager, $this->otherOrg, 'ساختمان دیگر', '');
        $foreignSite = $other->plan($otherManager, $this->otherOrg)['sites'][0]['key'];
        $foreign = $other->add_clinic($otherManager, $this->otherOrg, FacilitySetupService::new_request_key(), 'درمانگاه بیگانه', $foreignSite, self::TZ);
        foreach ([$existing, $missing, 0] as $target) {
            $this->assertRefused('denied', fn () => $service->designate_manager($this->actor, $this->otherOrg, $foreign['key'], $target));
            self::assertSame(
                \RuntimeException::class . ': organization clinic manager designation denied',
                $this->provisioningRefusal(fn () => $provisioning->designate_clinic_manager($this->actor, $this->otherOrg, (int) $foreign['clinic_id'], $target))
            );
        }

        // 3) Authorized in its own Organization but naming a foreign Clinic: refused as
        //    not found before the target user is evaluated.
        $this->orgCapability(OrganizationClinicProvisioningService::CAPABILITY_CLINIC_MANAGER, 'grant');
        foreach ([$existing, $missing, 0] as $target) {
            $this->assertRefused('not_found', fn () => $service->designate_manager($this->actor, $this->org, $foreign['key'], $target));
            self::assertSame(
                \RuntimeException::class . ': clinic not found in organization',
                $this->provisioningRefusal(fn () => $provisioning->designate_clinic_manager($this->actor, $this->org, (int) $foreign['clinic_id'], $target))
            );
        }
        self::assertNull(App::membership_service()->membership_for((int) $clinic['clinic_id'], $existing));
        self::assertNull(App::membership_service()->membership_for((int) $foreign['clinic_id'], $existing));
        self::assertSame($designated, $this->auditCount(OrganizationClinicProvisioningService::MANAGER_AUDIT_ACTION), 'no refused attempt wrote anything');

        // 4) Authorized behavior is preserved: unknown or invalid users are reported as
        //    such only to an authorized caller, and a real user is designated (audited).
        foreach ([$missing, 0, -7] as $target) {
            $this->assertRefused('user_not_found', fn () => $service->designate_manager($this->actor, $this->org, $clinic['key'], $target));
        }
        self::assertStringStartsWith('خطا', $notice($clinic['key'], 'fs_no_such_login_' . bin2hex(random_bytes(3))));
        self::assertNotSame($deniedNotice, $notice($clinic['key'], 'fs_no_such_login_' . bin2hex(random_bytes(3))), 'an authorized caller gets the specific reason');
        $result = $service->designate_manager($this->actor, $this->org, $clinic['key'], $existing);
        self::assertFalse($result['noop']);
        self::assertSame($designated + 1, $this->auditCount(OrganizationClinicProvisioningService::MANAGER_AUDIT_ACTION));
        self::assertTrue(App::audit()->verifyChain(5000)['ok']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function hospitalReady(FacilitySetupService $service): void
    {
        $service->start($this->actor, $this->org, 'hospital', '', 'بیمارستان آزمون');
        $service->acknowledge_outpatient($this->actor, $this->org, true);
        $service->add_site($this->actor, $this->org, 'ساختمان مرکزی', 'تهران');
    }

    private function otherOrgManagedByActor(): int
    {
        $this->orgManager($this->actor, $this->otherOrg);

        return $this->otherOrg;
    }

    private function assertRefused(string $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('expected refusal: ' . $reason);
        } catch (FacilitySetupException $e) {
            self::assertSame($reason, $e->reason(), $e->getMessage());
        }
    }

    private function orgCapability(string $capability, string $effect): void
    {
        App::db()->query(
            'DELETE FROM ' . App::db()->table('cpms_organization_membership_capabilities') . ' WHERE membership_id = %d AND capability = %s',
            [$this->actorMembership, $capability]
        );
        App::db()->insert('cpms_organization_membership_capabilities', [
            'membership_id' => $this->actorMembership,
            'capability' => $capability,
            'effect' => $effect,
            'created_by_wp_user_id' => $this->actor,
            'created_at' => App::db()->nowUtcSql(),
        ]);
    }

    private function createOrganization(string $label): int
    {
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        App::db()->insert('cpms_organizations', [
            'name' => $label . ' ' . $unique,
            'slug' => 'fs-org-' . $unique,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = App::db()->wpdb_last_insert_id();
        self::assertGreaterThan(0, $id, 'precondition: organization inserted');

        return $id;
    }

    private function orgManager(int $userId, int $organizationId): int
    {
        $now = App::db()->nowUtcSql();
        App::db()->insert('cpms_organization_memberships', [
            'organization_id' => $organizationId,
            'wp_user_id' => $userId,
            'role_key' => 'organization_manager',
            'status' => 'active',
            'created_by_wp_user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = App::db()->wpdb_last_insert_id();
        self::assertGreaterThan(0, $id, 'precondition: organization membership');
        $this->orgMemberships[] = $id;

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@fs.test');
        self::assertGreaterThan(0, $userId, 'precondition: wp user ' . $login);
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $userId;
    }

    private function refusalMessage(string $reason, callable $operation): string
    {
        try {
            $operation();
            self::fail('expected refusal: ' . $reason);
        } catch (FacilitySetupException $e) {
            self::assertSame($reason, $e->reason(), $e->getMessage());

            return $e->getMessage();
        }
    }

    private function provisioningRefusal(callable $operation): string
    {
        try {
            $operation();
            self::fail('expected the Organization service to refuse');
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    }

    private function profileCount(int $userId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d', [$userId]);
    }

    private function clinicianLinkCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinician_locations') . ' cl JOIN ' . App::db()->table('cpms_locations') . ' l ON l.id = cl.location_id WHERE l.clinic_id = %d',
            [$clinicId]
        );
    }

    private function locationAddress(int $locationId): string
    {
        return (string) App::db()->fetchValue('SELECT address FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d', [$locationId]);
    }

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s', [$action]);
    }

    private function clinicOrg(int $clinicId): int
    {
        return (int) App::db()->fetchValue('SELECT organization_id FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d', [$clinicId]);
    }

    private function clinicStatus(int $clinicId): string
    {
        return (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d', [$clinicId]);
    }

    private function locationClinic(int $locationId): int
    {
        return (int) App::db()->fetchValue('SELECT clinic_id FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d', [$locationId]);
    }

    private function clinicCount(int $organizationId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics') . ' WHERE organization_id = %d', [$organizationId]);
    }

    private function purgeOrganization(int $org): void
    {
        global $wpdb;
        if ($org <= 0) {
            return;
        }
        $p = $wpdb->prefix;
        $clinics = 'SELECT id FROM ' . $p . 'cpms_clinics WHERE organization_id = ' . $org;
        $wpdb->query('DELETE t FROM ' . $p . 'cpms_schedule_slots t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $p . 'cpms_schedule_exceptions t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $p . 'cpms_schedule t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $p . 'cpms_clinician_locations t JOIN ' . $p . 'cpms_locations l ON l.id = t.location_id JOIN ' . $p . 'cpms_clinics c ON c.id = l.clinic_id WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE mc FROM ' . $p . 'cpms_membership_capabilities mc JOIN ' . $p . 'cpms_clinic_memberships m ON m.id = mc.membership_id JOIN ' . $p . 'cpms_clinics c ON c.id = m.clinic_id WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE ml FROM ' . $p . 'cpms_membership_locations ml JOIN ' . $p . 'cpms_clinic_memberships m ON m.id = ml.membership_id JOIN ' . $p . 'cpms_clinics c ON c.id = m.clinic_id WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE m FROM ' . $p . 'cpms_clinic_memberships m JOIN ' . $p . 'cpms_clinics c ON c.id = m.clinic_id WHERE c.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $p . 'cpms_clinicians t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $p . 'cpms_settings t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE t FROM ' . $p . 'cpms_locations t WHERE t.clinic_id IN (' . $clinics . ')'); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $p . 'cpms_clinics WHERE organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE c FROM ' . $p . 'cpms_organization_membership_capabilities c JOIN ' . $p . 'cpms_organization_memberships m ON m.id = c.membership_id WHERE m.organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $p . 'cpms_organization_memberships WHERE organization_id = ' . $org); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $p . 'cpms_organizations WHERE id = ' . $org); // phpcs:ignore
        delete_option(FacilitySetupService::OPTION_PREFIX . $org);
    }
}
