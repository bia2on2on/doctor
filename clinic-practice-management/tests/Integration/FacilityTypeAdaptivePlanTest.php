<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\FacilitySetupPage;
use ClinicCore\Application\Onboarding\FacilitySetupException;
use ClinicCore\Application\Onboarding\FacilitySetupService;
use ClinicCore\Application\Onboarding\FacilityTypeCatalog;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Type-adaptive Organization facility-plan wizard (2026-10-21): every one of
 * the 12 approved facility types can START and COMPLETE a real frontend plan
 * with flow-dependent required steps (FacilityTypeCatalog::plan_rules).
 *
 * Contract under test:
 *  - all 12 catalogue types start a plan whose flow is the catalogue flow and
 *    is plannable end-to-end (no type is refused as "not multi-clinic");
 *  - invalid types are refused with zero partial state;
 *  - the solo practice gets the COMPACT flow: automatic central site, one held
 *    Clinic, explicit manager, one scheduled clinician, booking policy,
 *    readiness review, atomic activation (held → active in one transaction);
 *  - a multi-doctor clinic requires TWO scheduled clinicians per Clinic;
 *  - the hospital keeps its PROVEN outpatient rules (explicit ack, scheduled
 *    clinician, booking policy) and the medical building keeps independent
 *    Clinic management (manager + Location only);
 *  - the structure override adapts the required steps (single → compact).
 *
 * Fixture rules: dynamic Organizations, purged by Organization in tearDown.
 */
final class FacilityTypeAdaptivePlanTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private const TZ = 'Asia/Tehran';

    /** @var list<int> */
    private array $orgs = [];

    /** @var list<int> */
    private array $orgMemberships = [];

    private int $actor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        Settings::flushCache();
        App::resetScope();
        \ClinicCore\Application\Scope\ScopeContext::clear();
        $this->actor = $this->makeUser('fta_orgmgr', 'subscriber');
    }

    protected function tearDown(): void
    {
        try {
            \ClinicCore\Application\Scope\ScopeContext::clear();
            App::resetScope();
            Settings::flushCache();
            App::settingsFactory()->reset();
            wp_set_current_user(0);
            foreach ($this->orgs as $org) {
                $this->purgeOrganization($org);
            }
            $this->orgs = [];
            parent::tearDown();
        } catch (\Throwable $e) {
            file_put_contents('php://stdout', '::error::FTA-DEBUG tearDown ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 250) . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
            throw $e;
        }
    }

    public function testAllTwelveFacilityTypesStartARealPlanWithTheCatalogueFlow(): void
    {
        $service = FacilitySetupPage::service();
        $types   = FacilityTypeCatalog::all();
        self::assertCount(12, $types, 'the approved catalogue has exactly 12 facility types');

        foreach ($types as $index => $type) {
            $org  = $this->freshOrg('FTA type ' . $index);
            $plan = $service->start($this->actor, $org, (string) $type['id'], '', 'مرکز ' . $index);
            $expected = FacilityTypeCatalog::resolve_flow((string) $type['id'], '');
            self::assertSame($expected, $plan['flow'], 'flow for ' . $type['id']);
            self::assertContains($expected, FacilitySetupService::FLOWS, 'every catalogue flow is plannable end-to-end: ' . $type['id']);
            if (FacilityTypeCatalog::plan_rules($expected)['auto_site']) {
                self::assertCount(1, $plan['sites'], 'the compact flow seeds its central site: ' . $type['id']);
            }
        }
    }

    public function testInvalidFacilityTypeIsRefusedWithoutAnyPlanRow(): void
    {
        $service = FacilitySetupPage::service();
        $org     = $this->freshOrg('FTA invalid');
        $this->assertRefused('invalid_facility', fn () => $service->start($this->actor, $org, 'not_a_facility', '', 'x'));
        self::assertNull($service->plan($this->actor, $org), 'a refused start writes no plan');
        $this->assertRefused('incompatible_structure', fn () => $service->start($this->actor, $org, 'hospital', 'single', 'x'));
        self::assertNull($service->plan($this->actor, $org), 'an incompatible structure writes no plan');
    }

    public function testSoloPracticeCompactFlowReachesReadinessAndActivatesHeldClinicsAtomically(): void
    {
        $service = FacilitySetupPage::service();
        $org     = $this->freshOrg('FTA solo');
        $plan    = $service->start($this->actor, $org, 'doctor_practice', '', 'مطب دکتر نمونه');
        self::assertSame('simple_practice', $plan['flow']);
        self::assertCount(1, $plan['sites'], 'compact: no manual site step');
        $site = (string) $plan['sites'][0]['key'];

        $clinic = $service->add_clinic($this->actor, $org, FacilitySetupService::new_request_key(), 'مطب دکتر نمونه', $site, self::TZ);
        self::assertSame('suspended', $this->clinicStatus((int) $clinic['clinic_id']), 'held (no new demand) until activation');

        // Review blocks before the operational steps (type-adaptive: the solo
        // flow requires ONE scheduled clinician and a booking policy).
        $review = $service->review($this->actor, $org);
        self::assertFalse($review['ready']);

        $service->designate_manager($this->actor, $org, $clinic['key'], $this->actor);
        $doctor = $this->makeUser('fta_solo_doc', 'subscriber');
        $assign = $service->assign_clinician($this->actor, $org, $clinic['key'], $doctor, 'دکتر نمونه', ['primary']);
        $service->add_weekly_schedule($this->actor, $org, $clinic['key'], $assign['clinician_id'], 'primary', [
            'day_of_week'             => 0,
            'start_time'              => '09:00',
            'end_time'                => '12:00',
            'appointment_duration_min' => 20,
            'slot_capacity'           => 1,
        ]);
        $service->save_booking_policy($this->actor, $org, $clinic['key'], [
            'duration' => '20', 'capacity' => '1', 'lead' => '2', 'future' => '30', 'cancel' => '6',
        ]);

        $review = $service->review($this->actor, $org);
        self::assertTrue($review['ready'], implode(' | ', $review['blocking']));

        $activated = $service->activate($this->actor, $org, true);
        self::assertSame([(int) $clinic['clinic_id']], $activated['activated']);
        self::assertSame('active', $this->clinicStatus((int) $clinic['clinic_id']));
        self::assertNotSame('', (string) $service->plan($this->actor, $org)['activated_at']);
        self::assertTrue(App::audit()->verifyChain(5000)['ok']);
    }

    public function testMultiDoctorClinicRequiresTwoScheduledCliniciansPerClinic(): void
    {
        $service = FacilitySetupPage::service();
        $org     = $this->freshOrg('FTA multi');
        $plan    = $service->start($this->actor, $org, 'general_clinic', '', 'درمانگاه عمومی نمونه');
        self::assertSame('multi_doctor_clinic', $plan['flow']);
        // Multi-doctor clinics do not get an automatic central site (auto_site=0).
        self::assertCount(0, $plan['sites']);
        $service->add_site($this->actor, $org, 'ساختمان مرکزی', 'تهران');
        $site = (string) $service->plan($this->actor, $org)['sites'][0]['key'];

        $clinic = $service->add_clinic($this->actor, $org, FacilitySetupService::new_request_key(), 'درمانگاه عمومی نمونه', $site, self::TZ);
        $service->designate_manager($this->actor, $org, $clinic['key'], $this->actor);
        $room = $service->add_room($this->actor, $org, $clinic['key'], FacilitySetupService::new_request_key(), 'اتاق ویزیت', $site);

        $doctor1 = $this->makeUser('fta_md_doc1', 'subscriber');
        $a1      = $service->assign_clinician($this->actor, $org, $clinic['key'], $doctor1, 'دکتر یک', [$room['key']]);
        $service->add_weekly_schedule($this->actor, $org, $clinic['key'], $a1['clinician_id'], $room['key'], [
            'day_of_week' => 0, 'start_time' => '09:00', 'end_time' => '12:00',
        ]);
        $service->save_booking_policy($this->actor, $org, $clinic['key'], [
            'duration' => '15', 'capacity' => '1', 'lead' => '2', 'future' => '30', 'cancel' => '6',
        ]);

        $review = $service->review($this->actor, $org);
        self::assertFalse($review['ready'], 'one doctor is not enough for a multi-doctor clinic');
        self::assertNotEmpty($review['blocking']);

        $doctor2 = $this->makeUser('fta_md_doc2', 'subscriber');
        $a2      = $service->assign_clinician($this->actor, $org, $clinic['key'], $doctor2, 'دکتر دو', [$room['key']]);
        $service->add_weekly_schedule($this->actor, $org, $clinic['key'], $a2['clinician_id'], $room['key'], [
            'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00',
        ]);

        $review = $service->review($this->actor, $org);
        self::assertTrue($review['ready'], implode(' | ', $review['blocking']));
    }

    public function testHospitalAckGateIsHospitalOnlyAndExplicit(): void
    {
        try {
        $service = FacilitySetupPage::service();
        $org     = $this->freshOrg('FTA hospital ack');
        $plan    = $service->start($this->actor, $org, 'hospital', '', 'بیمارستان نمونه');
        self::assertSame('hospital_network', $plan['flow']);

        $service->add_site($this->actor, $org, 'ساختمان مرکزی', 'تهران');
        $site   = (string) $service->plan($this->actor, $org)['sites'][0]['key'];
        $clinic = $service->add_clinic($this->actor, $org, FacilitySetupService::new_request_key(), 'درمانگاه قلب', $site, self::TZ);
        $service->designate_manager($this->actor, $org, $clinic['key'], $this->actor);
        $room = $service->add_room($this->actor, $org, $clinic['key'], FacilitySetupService::new_request_key(), 'اتاق ویزیت', $site);

        // The outpatient acknowledgment is a hospital-ONLY required step.
        $review = $service->review($this->actor, $org);
        self::assertFalse($review['ready']);
        $otherOrg  = $this->freshOrg('FTA other ack');
        $otherPlan = $service->start($this->actor, $otherOrg, 'general_clinic', '', 'درمانگاه دیگر');
        self::assertSame('multi_doctor_clinic', $otherPlan['flow']);
        $this->assertRefused('not_hospital', fn () => $service->acknowledge_outpatient($this->actor, $otherOrg, true));
        $service->acknowledge_outpatient($this->actor, $org, true);
        } catch (\Throwable $e) {
            file_put_contents('php://stdout', '::error::FTA-DEBUG ack-gate ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 250) . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
            throw $e;
        }
    }

    public function testHospitalKeepsTheProvenOutpatientRules(): void
    {
        try {
        $service = FacilitySetupPage::service();
        $org     = $this->freshOrg('FTA hospital');
        $plan    = $service->start($this->actor, $org, 'hospital', '', 'بیمارستان نمونه');
        self::assertSame('hospital_network', $plan['flow']);

        $service->add_site($this->actor, $org, 'ساختمان مرکزی', 'تهران');
        $site   = (string) $service->plan($this->actor, $org)['sites'][0]['key'];
        $clinic = $service->add_clinic($this->actor, $org, FacilitySetupService::new_request_key(), 'درمانگاه قلب', $site, self::TZ);
        $service->designate_manager($this->actor, $org, $clinic['key'], $this->actor);
        $room = $service->add_room($this->actor, $org, $clinic['key'], FacilitySetupService::new_request_key(), 'اتاق ویزیت', $site);
        $service->acknowledge_outpatient($this->actor, $org, true);

        $doctor = $this->makeUser('fta_h_doc', 'subscriber');
        $a      = $service->assign_clinician($this->actor, $org, $clinic['key'], $doctor, 'دکتر قلب', [$room['key']]);
        $service->add_weekly_schedule($this->actor, $org, $clinic['key'], $a['clinician_id'], $room['key'], [
            'day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);
        $this->assertRefused('not_ready', fn () => $service->activate($this->actor, $org, true), 'hospital still requires the booking policy');

        $service->save_booking_policy($this->actor, $org, $clinic['key'], [
            'duration' => '15', 'capacity' => '1', 'lead' => '2', 'future' => '30', 'cancel' => '6',
        ]);
        $review = $service->review($this->actor, $org);
        self::assertTrue($review['ready'], implode(' | ', $review['blocking']));
        } catch (\Throwable $e) {
            file_put_contents('php://stdout', '::error::FTA-DEBUG hospital-test ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 250) . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
            throw $e;
        }
    }

    public function testMedicalBuildingKeepsIndependentClinicManagementWithoutClinicalPrerequisites(): void
    {
        $service = FacilitySetupPage::service();
        $org     = $this->freshOrg('FTA building');
        $plan    = $service->start($this->actor, $org, 'other', 'medical_building', 'مجتمع پزشکی نمونه');
        self::assertSame('medical_building', $plan['flow']);

        $service->add_site($this->actor, $org, 'ساختمان الف', 'تهران');
        $site   = (string) $service->plan($this->actor, $org)['sites'][0]['key'];
        $clinic = $service->add_clinic($this->actor, $org, FacilitySetupService::new_request_key(), 'مطب مستقل یک', $site, self::TZ);
        $service->designate_manager($this->actor, $org, $clinic['key'], $this->actor);

        // Manager + Location only: no clinician, schedule or booking policy may
        // block a medical building's readiness (each Clinic configures itself).
        $review = $service->review($this->actor, $org);
        self::assertTrue($review['ready'], implode(' | ', $review['blocking']));
    }

    public function testStructureOverrideAdaptsRequiredSteps(): void
    {
        $service = FacilitySetupPage::service();
        $org     = $this->freshOrg('FTA structure');
        // A specialized clinic forced to a single unit gets the compact flow.
        $plan = $service->start($this->actor, $org, 'specialized_clinic', 'single', 'مطب تخصصی تک‌واحدی');
        self::assertSame('simple_practice', $plan['flow']);
        self::assertSame(1, FacilityTypeCatalog::plan_rules($plan['flow'])['min_scheduled_clinicians']);
        self::assertFalse(FacilityTypeCatalog::plan_rules($plan['flow'])['outpatient_ack']);

        // The same type with the multi_doctor override needs two scheduled
        // clinicians: required steps depend on type AND structure.
        $org2 = $this->freshOrg('FTA structure 2');
        $plan2 = $service->start($this->actor, $org2, 'specialized_clinic', 'multi_doctor', 'درمانگاه تخصصی چندپزشکه');
        self::assertSame('multi_doctor_clinic', $plan2['flow']);
        self::assertSame(2, FacilityTypeCatalog::plan_rules($plan2['flow'])['min_scheduled_clinicians']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function assertRefused(string $reason, callable $operation, string $context = ''): void
    {
        try {
            $operation();
            self::fail('expected refusal: ' . $reason . ( '' !== $context ? ' — ' . $context : '' ));
        } catch (FacilitySetupException $e) {
            self::assertSame($reason, $e->reason(), $e->getMessage());
        } catch (\Throwable $e) {
            file_put_contents('php://stdout', '::error::FTA-DEBUG unexpected ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 250) . "
");
            throw $e;
        }
    }

    private function freshOrg(string $label): int
    {
        $org = $this->createOrganization($label);
        $this->orgManager($this->actor, $org);

        return $org;
    }

    private function clinicStatus(int $clinicId): string
    {
        return (string) App::db()->fetchValue(
            'SELECT COALESCE(status, %s) FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            ['active', $clinicId]
        );
    }

    private function createOrganization(string $label): int
    {
        $now    = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        App::db()->insert('cpms_organizations', [
            'name'       => $label . ' ' . $unique,
            'slug'       => 'fta-org-' . $unique,
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = App::db()->wpdb_last_insert_id();
        self::assertGreaterThan(0, $id, 'precondition: organization inserted');
        $this->orgs[] = $id;

        return $id;
    }

    private function orgManager(int $userId, int $organizationId): int
    {
        $now = App::db()->nowUtcSql();
        App::db()->insert('cpms_organization_memberships', [
            'organization_id'        => $organizationId,
            'wp_user_id'             => $userId,
            'role_key'               => 'organization_manager',
            'status'                 => 'active',
            'created_by_wp_user_id'  => $userId,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
        $id = App::db()->wpdb_last_insert_id();
        self::assertGreaterThan(0, $id, 'precondition: organization membership');
        $this->orgMemberships[] = $id;

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@fta.test');
        self::assertGreaterThan(0, $userId, 'precondition: wp user ' . $login);
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $userId;
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
