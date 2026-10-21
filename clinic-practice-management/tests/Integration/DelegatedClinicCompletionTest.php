<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Onboarding\FacilitySetupService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\ManagementPortalController;
use ClinicCore\Frontend\ManagementPortalShell;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Delegated (non-hospital) Clinic completion through the frontend portal
 * (independent-audit blockers B2/B3/B4, 2026-10-21).
 *
 * Contract under test:
 *  - a SEPARATE Subscriber Clinic Manager (no Organization membership) can
 *    complete a doctor_practice (simple_practice) or general_clinic
 *    (multi_doctor_clinic) plan Clinic — location, clinicians, schedules and
 *    the booking policy — from the Clinic surface, and the readiness panel is
 *    rendered for these flows (previously hospital-only);
 *  - the Organization Manager activates atomically after the delegated
 *    completion, and cannot silently perform Clinic Manager operations
 *    without the explicit designation;
 *  - cross-Clinic submissions are uniformly refused without disclosure;
 *  - stale form values restore ONLY into the form whose action failed (B3);
 *  - notices distinguish invalid input / missing prerequisites /
 *    authorization denial (B4).
 *
 * Fixture rules: dynamic Organizations, purged by Organization in tearDown.
 */
final class DelegatedClinicCompletionTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private const TZ = 'Asia/Tehran';

    /** @var list<int> */
    private array $orgs = [];

    /** @var list<int> */
    private array $orgMemberships = [];

    private int $orgActor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        Settings::flushCache();
        App::resetScope();
        \ClinicCore\Application\Scope\ScopeContext::clear();
        $this->orgActor = $this->makeUser('dcc_orgmgr', 'subscriber');
    }

    protected function tearDown(): void
    {
        \ClinicCore\Application\Scope\ScopeContext::clear();
        App::resetScope();
        Settings::flushCache();
        App::settingsFactory()->reset();
        wp_set_current_user(0);
        $_GET = [];
        foreach ($this->orgs as $org) {
            $this->purgeOrganization($org);
        }
        $this->orgs = [];
        parent::tearDown();
    }

    public function testDelegatedGeneralClinicManagerCompletesAndOrgManagerActivatesAtomically(): void
    {
        $portal = App::management_portal_service();
        [$org, $clinicKey, $clinicId, $mgr, $login] = $this->seedDelegatedClinic(
            'general_clinic',
            'multi_doctor',
            'درمانگاه عمومی نمونه',
            'dcc_mgr_general'
        );

        // The Organization Manager has org authority but is NOT the designated
        // Clinic manager: Clinic-surface operations are refused (uniformly).
        self::assertFalse(
            $portal->create_location($this->orgActor, $clinicId, ['name' => 'اتاق', 'slug' => 'dcc-x', 'timezone' => self::TZ])['ok'],
            'Organization authority alone is not Clinic authority'
        );
        self::assertFalse(
            $portal->save_clinic_booking_policy($this->orgActor, $clinicId, $this->bookingInput())['ok'],
            'Organization authority alone cannot write the Clinic booking policy'
        );
        self::assertNull($portal->clinic_facility_readiness($this->orgActor, $clinicId));

        // The delegated Subscriber Manager completes the Clinic from the
        // Clinic surface (the exact UI path of ?cpms-clinic=<id>).
        $locationId = $this->mgrCreatesLocation($mgr, $clinicId, 'dcc-gen-room');
        $this->mgrRegistersClinicianWithSchedule($mgr, $clinicId, $locationId, 'دکتر یک', 0);
        $this->mgrRegistersClinicianWithSchedule($mgr, $clinicId, $locationId, 'دکتر دو', 1);
        $booking = $portal->save_clinic_booking_policy($mgr, $clinicId, $this->bookingInput());
        self::assertTrue($booking['ok'], (string) ($booking['message'] ?? ''));

        $ready = $portal->clinic_facility_readiness($mgr, $clinicId);
        self::assertIsArray($ready);
        self::assertTrue($ready['ready'], implode(' | ', (array) $ready['blocking']));
        self::assertFalse($ready['hospital'], 'general_clinic is not the hospital flow');
        self::assertTrue($ready['requires_booking'], 'this flow requires a booking policy');

        // B2: the delegated manager's portal must render the readiness card and
        // the booking form for a NON-hospital flow (previously hospital-gated).
        $html = $this->renderPortal($mgr, ['cpms-clinic' => $clinicId]);
        self::assertStringContainsString('data-cpms-clinic-readiness', $html, 'B2: readiness card must render for multi_doctor_clinic');
        self::assertStringContainsString('value="clinic_facility_booking"', $html, 'B2: delegated booking form must render for multi_doctor_clinic');
        self::assertStringContainsString('تکمیل راه‌اندازی مرکز', $html, 'B2: completion heading is flow-general');

        // The Organization Manager activates the plan atomically.
        $activation = $portal->activate_facility_setup($this->orgActor, $org);
        self::assertTrue($activation['ok'], (string) ($activation['message'] ?? ''));
        self::assertSame('active', $this->clinicStatus($clinicId));
        self::assertNotSame('', (string) ($portal->facility_plan($this->orgActor, $org)['activated_at'] ?? ''), 'activation recorded on the plan');
    }

    public function testDelegatedDoctorPracticeManagerCompletesAndOrgManagerActivatesAtomically(): void
    {
        $portal = App::management_portal_service();
        [$org, $clinicKey, $clinicId, $mgr, $login] = $this->seedDelegatedClinic(
            'doctor_practice',
            'single',
            'مطب دکتر نمونه',
            'dcc_mgr_solo'
        );

        $locationId = $this->mgrCreatesLocation($mgr, $clinicId, 'dcc-solo-room');
        $this->mgrRegistersClinicianWithSchedule($mgr, $clinicId, $locationId, 'دکتر مطب', 2);
        $booking = $portal->save_clinic_booking_policy($mgr, $clinicId, $this->bookingInput());
        self::assertTrue($booking['ok'], (string) ($booking['message'] ?? ''));

        $ready = $portal->clinic_facility_readiness($mgr, $clinicId);
        self::assertIsArray($ready);
        self::assertTrue($ready['ready'], implode(' | ', (array) $ready['blocking']));
        self::assertTrue($ready['requires_booking']);

        // B2: the readiness card and booking form render for the compact flow.
        $html = $this->renderPortal($mgr, ['cpms-clinic' => $clinicId]);
        self::assertStringContainsString('data-cpms-clinic-readiness', $html, 'B2: readiness card must render for simple_practice');
        self::assertStringContainsString('value="clinic_facility_booking"', $html, 'B2: delegated booking form must render for simple_practice');

        $activation = $portal->activate_facility_setup($this->orgActor, $org);
        self::assertTrue($activation['ok'], (string) ($activation['message'] ?? ''));
        self::assertSame('active', $this->clinicStatus($clinicId));
    }

    public function testDesignationFormIsNotOfferedBeforeAPlanClinicExists(): void
    {
        $portal = App::management_portal_service();
        $org    = $this->freshOrg('DCC designation prerequisite');

        $portal->start_facility_setup($this->orgActor, $org, 'general_clinic', 'multi_doctor', 'درمانگاه آغاز');

        // A designation form whose Clinic select has no options is a dead end:
        // it can only be refused after submit. The portal must state the real
        // prerequisite instead of rendering an unsubmittable form.
        $html = $this->renderPortal($this->orgActor, ['cpms-org' => $org]);
        self::assertStringNotContainsString(
            'value="org_facility_designate"',
            $html,
            'no designation form may be rendered while the plan has no Clinic'
        );
        self::assertStringContainsString(
            'data-cpms-designation-prerequisite="1"',
            $html,
            'the real prerequisite is disclosed in Persian'
        );

        // As soon as a plan Clinic exists, the explicit designation form returns.
        $portal->add_facility_site($this->orgActor, $org, 'ساختمان اصلی', 'تهران');
        $plan = $portal->facility_plan($this->orgActor, $org);
        $site = (string) $plan['sites'][0]['key'];
        $portal->add_facility_clinic($this->orgActor, $org, 'درمانگاه اصلی', $site, self::TZ);

        $html = $this->renderPortal($this->orgActor, ['cpms-org' => $org]);
        self::assertStringContainsString('value="org_facility_designate"', $html, 'designation form returns with a real Clinic');
        self::assertStringContainsString(
            'درمانگاه اصلی',
            $this->formBlock($html, 'org_facility_designate'),
            'the explicit Clinic selector lists the real plan Clinic'
        );
    }

    public function testCrossClinicSubmissionsAreUniformlyRefusedWithoutDisclosure(): void
    {
        $portal = App::management_portal_service();
        $org   = $this->freshOrg('DCC cross');
        $mgr   = $this->makeUser('dcc_mgr_cross', 'subscriber');
        $login = (string) get_userdata($mgr)->user_login;

        $portal->start_facility_setup($this->orgActor, $org, 'general_clinic', 'multi_doctor', 'درمانگاه الف');
        $portal->add_facility_site($this->orgActor, $org, 'ساختمان', 'تهران');
        $plan = $portal->facility_plan($this->orgActor, $org);
        $site = (string) $plan['sites'][0]['key'];
        $portal->add_facility_clinic($this->orgActor, $org, 'درمانگاه الف', $site, self::TZ);
        $portal->add_facility_clinic($this->orgActor, $org, 'درمانگاه ب', $site, self::TZ);
        $plan     = $portal->facility_plan($this->orgActor, $org);
        $clinicA  = (int) $plan['clinics'][0]['clinic_id'];
        $keyA     = (string) $plan['clinics'][0]['key'];
        $clinicB  = (int) $plan['clinics'][1]['clinic_id'];

        // Explicit designation for Clinic A ONLY.
        $designated = $portal->designate_facility_manager_by_identifier($this->orgActor, $org, $keyA, $login);
        self::assertTrue($designated['ok'], (string) ($designated['message'] ?? ''));

        // Every Clinic-surface mutation on Clinic B is refused uniformly.
        $denied = $portal->create_location($mgr, $clinicB, ['name' => 'اتاق', 'slug' => 'dcc-cross', 'timezone' => self::TZ]);
        self::assertFalse($denied['ok']);
        self::assertSame('denied', $denied['notice'] ?? '');
        $denied = $portal->save_clinic_booking_policy($mgr, $clinicB, $this->bookingInput());
        self::assertFalse($denied['ok']);
        self::assertSame('denied', $denied['notice'] ?? '');
        self::assertNull($portal->clinic_facility_readiness($mgr, $clinicB), 'no readiness disclosure for a foreign Clinic');

        // Clinic A remains usable by its own manager (the refusal is scoped).
        self::assertIsArray($portal->clinic_facility_readiness($mgr, $clinicA));
    }

    public function testStaleFieldValuesRestoreOnlyIntoTheFormThatFailed(): void
    {
        [$org, $clinicKey, $clinicId, $mgr, $login] = $this->seedDelegatedClinic(
            'general_clinic',
            'multi_doctor',
            'درمانگاه فرم‌ها',
            'dcc_mgr_forms'
        );

        // A failed clinic_location_create submission carries its own values.
        set_transient(
            ManagementPortalController::form_state_key($mgr),
            [
                'action'  => 'clinic_location_create',
                'notice'  => 'invalid',
                'message' => 'ورودی نامعتبر است.',
                'fields'  => [
                    'cpms_entity_name' => 'اتاق-ناموفق',
                    'address'          => 'نشانی-ناموفق',
                ],
            ],
            5 * MINUTE_IN_SECONDS
        );
        $html        = $this->renderPortal($mgr, ['cpms-clinic' => $clinicId]);
        $location    = $this->formBlock($html, 'clinic_location_create');
        $profile     = $this->formBlock($html, 'clinic_update_profile');
        self::assertNotSame('', $location, 'location form rendered');
        self::assertNotSame('', $profile, 'profile form rendered');
        self::assertStringContainsString('اتاق-ناموفق', $location, 'B3: failed form keeps its own value');
        self::assertStringContainsString('نشانی-ناموفق', $location, 'B3: failed form keeps its own address');
        self::assertStringNotContainsString('اتاق-ناموفق', $profile, 'B3: other forms sharing cpms_entity_name are untouched');
        self::assertStringNotContainsString('نشانی-ناموفق', $profile, 'B3: other forms sharing address are untouched');

        // The inverse: a profile failure never leaks into the location form.
        set_transient(
            ManagementPortalController::form_state_key($mgr),
            [
                'action'  => 'clinic_update_profile',
                'notice'  => 'invalid',
                'message' => 'ورودی نامعتبر است.',
                'fields'  => [ 'cpms_entity_name' => 'نام-ناموفق' ],
            ],
            5 * MINUTE_IN_SECONDS
        );
        $html     = $this->renderPortal($mgr, ['cpms-clinic' => $clinicId]);
        $location = $this->formBlock($html, 'clinic_location_create');
        $profile  = $this->formBlock($html, 'clinic_update_profile');
        self::assertStringContainsString('نام-ناموفق', $profile, 'B3: the failing form restores its value');
        self::assertStringNotContainsString('نام-ناموفق', $location, 'B3: the sibling form is untouched');
    }

    public function testNoticeCategoriesDistinguishInvalidPrerequisiteAndDenied(): void
    {
        $portal = App::management_portal_service();
        [$org, $clinicKey, $clinicId, $mgr, $login] = $this->seedDelegatedClinic(
            'doctor_practice',
            'single',
            'مطب دسته‌بندی خطاها',
            'dcc_mgr_notices'
        );

        // Invalid INPUT (missing required booking fields).
        $invalid = $portal->save_clinic_booking_policy($mgr, $clinicId, [ 'duration' => '15', 'capacity' => '1' ]);
        self::assertFalse($invalid['ok']);
        self::assertSame('invalid', $invalid['notice'] ?? '', 'invalid input is not a permission denial');

        // Missing PREREQUISITE for an AUTHORIZED manager: the Clinic is not
        // ready, so activation is refused as 'prerequisite'.
        $activation = $portal->activate_facility_setup($this->orgActor, $org);
        self::assertFalse($activation['ok']);
        self::assertSame('prerequisite', $activation['notice'] ?? '', 'not-ready activation is a missing prerequisite');

        // Invalid INPUT on the designation path (empty identifier).
        $designation = $portal->designate_facility_manager_by_identifier($this->orgActor, $org, $clinicKey, '   ');
        self::assertFalse($designation['ok']);
        self::assertSame('invalid', $designation['notice'] ?? '');

        // Missing PREREQUISITE on the designation path (account must exist).
        $designation = $portal->designate_facility_manager_by_identifier($this->orgActor, $org, $clinicKey, 'dcc_no_such_account_xyz');
        self::assertFalse($designation['ok']);
        self::assertSame('prerequisite', $designation['notice'] ?? '');

        // Authorization DENIED (org authority without Clinic designation).
        $denied = $portal->save_clinic_booking_policy($this->orgActor, $clinicId, $this->bookingInput());
        self::assertFalse($denied['ok']);
        self::assertSame('denied', $denied['notice'] ?? '');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{int,string,int,int,string} */
    private function seedDelegatedClinic(string $type, string $structure, string $name, string $loginPrefix): array
    {
        $portal = App::management_portal_service();
        $org    = $this->freshOrg('DCC ' . $type);
        $mgr    = $this->makeUser($loginPrefix, 'subscriber');
        $login  = (string) get_userdata($mgr)->user_login;

        $start = $portal->start_facility_setup($this->orgActor, $org, $type, $structure, $name);
        self::assertTrue($start['ok'], (string) ($start['message'] ?? 'start failed'));
        $plan = $portal->facility_plan($this->orgActor, $org);
        if (empty($plan['sites'])) {
            $portal->add_facility_site($this->orgActor, $org, 'ساختمان مرکزی', 'تهران');
            $plan = $portal->facility_plan($this->orgActor, $org);
        }
        self::assertNotEmpty($plan['sites'], 'plan exposes its sites');
        $site = (string) $plan['sites'][0]['key'];

        $created = $portal->add_facility_clinic($this->orgActor, $org, $name, $site, self::TZ);
        self::assertTrue($created['ok'], (string) ($created['message'] ?? 'clinic create failed'));
        $plan     = $portal->facility_plan($this->orgActor, $org);
        $clinicKey = (string) $plan['clinics'][0]['key'];
        $clinicId  = (int) $plan['clinics'][0]['clinic_id'];

        $designated = $portal->designate_facility_manager_by_identifier($this->orgActor, $org, $clinicKey, $login);
        self::assertTrue($designated['ok'], (string) ($designated['message'] ?? 'designation failed'));

        return [ $org, $clinicKey, $clinicId, $mgr, $login ];
    }

    /** @return array<string,string> */
    private function bookingInput(): array
    {
        return [
            'duration' => '15',
            'capacity' => '1',
            'lead'     => '2',
            'future'   => '30',
            'cancel'   => '6',
        ];
    }

    private function mgrCreatesLocation(int $mgr, int $clinicId, string $slug): int
    {
        $portal = App::management_portal_service();
        $created = $portal->create_location(
            $mgr,
            $clinicId,
            [
                'name'     => 'اتاق ویزیت',
                'slug'     => $slug . '-' . bin2hex(random_bytes(3)),
                'timezone' => self::TZ,
            ]
        );
        self::assertTrue($created['ok'], (string) ($created['message'] ?? 'location create failed'));
        $locationId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d ORDER BY id DESC LIMIT 1',
            [ $clinicId ]
        );
        self::assertGreaterThan(0, $locationId, 'location persisted');

        return $locationId;
    }

    private function mgrRegistersClinicianWithSchedule(int $mgr, int $clinicId, int $locationId, string $name, int $day): int
    {
        $portal  = App::management_portal_service();
        $created = $portal->register_clinician($mgr, $clinicId, $name, 'عمومی');
        self::assertTrue($created['ok'], (string) ($created['message'] ?? 'clinician create failed'));
        $clinicianId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_clinicians') . ' WHERE clinic_id = %d ORDER BY id DESC LIMIT 1',
            [ $clinicId ]
        );
        self::assertGreaterThan(0, $clinicianId, 'clinician persisted');

        $schedule = $portal->create_schedule($mgr, $clinicId, $clinicianId, $locationId, $day, '09:00', '12:00');
        self::assertTrue($schedule['ok'], (string) ($schedule['message'] ?? 'schedule create failed'));

        return $clinicianId;
    }

    private function renderPortal(int $userId, array $get): string
    {
        ManagementPortalShell::ensure_portal_page();
        $_GET = $get;
        wp_set_current_user($userId);
        ob_start();
        include dirname(__DIR__, 2) . '/templates/management-portal-shell.php';
        $html = (string) ob_get_clean();
        wp_set_current_user(0);
        $_GET = [];

        return $html;
    }

    private function formBlock(string $html, string $action): string
    {
        $needle = 'value="' . $action . '"';
        $pos    = strpos($html, $needle);
        if (false === $pos) {
            return '';
        }
        $start = (int) strrpos(substr($html, 0, $pos), '<form');
        $end   = strpos($html, '</form>', $pos);
        if (false === $end) {
            return substr($html, $start);
        }

        return substr($html, $start, $end - $start);
    }

    private function clinicStatus(int $clinicId): string
    {
        return (string) App::db()->fetchValue(
            'SELECT COALESCE(status, %s) FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            ['active', $clinicId]
        );
    }

    private function freshOrg(string $label): int
    {
        $org = $this->createOrganization($label);
        $this->orgManager($this->orgActor, $org);

        return $org;
    }

    private function createOrganization(string $label): int
    {
        $now    = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        App::db()->insert('cpms_organizations', [
            'name'       => $label . ' ' . $unique,
            'slug'       => 'dcc-org-' . $unique,
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
        $unique  = $login . '_' . bin2hex(random_bytes(3));
        $userId  = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@dcc.test');
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
