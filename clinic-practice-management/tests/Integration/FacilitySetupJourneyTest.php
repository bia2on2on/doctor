<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\FacilitySetupPage;
use ClinicCore\Application\Onboarding\FacilitySetupException;
use ClinicCore\Application\Onboarding\FacilitySetupService;
use ClinicCore\Application\Onboarding\OrganizationClinicProvisioningService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
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
        }
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
