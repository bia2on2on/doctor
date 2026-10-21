<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\ManagementPortalController;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Regression for the two-Subscriber hospital onboarding manager-designation.
 *
 * Proves that the `org_facility_designate` portal path with `identifier`
 * (login or e-mail) persists a durable `cpms_manager` membership per Clinic,
 * that the organization-level review then sees `managers = 1` per Clinic,
 * and that the designated Subscriber gains only its own Clinic surface
 * (no Organization authority, no wp-admin, cross-Clinic denied).
 */
final class ManagerDesignationRegressionTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private int $org = 0;
    private int $orgManager = 0;
    private int $managerA = 0;
    private int $managerB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        App::resetScope();

        $this->org        = $this->create_org('Regression Org');
        $this->orgManager = self::factory()->user->create(['role' => 'subscriber']);
        $this->managerA   = self::factory()->user->create(['role' => 'subscriber']);
        $this->managerB   = self::factory()->user->create(['role' => 'subscriber']);

        $this->grant_org_manager($this->org, $this->orgManager);
    }

    protected function tearDown(): void
    {
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testIdentifierLoginAndEmailBothDesignateDistinctManagers(): void
    {
        $service = App::management_portal_service();

        // Hospital plan + sites + 2 held clinics.
        self::assertTrue($this->act($this->orgManager, 'org_facility_start', [
            'organization_id' => (string) $this->org,
            'facility_type'   => 'hospital',
            'structure'       => '',
            'display_name'    => 'بیمارستان رگرسیون',
        ]));
        self::assertTrue($this->act($this->orgManager, 'org_facility_ack', [
            'organization_id' => (string) $this->org,
        ]));
        self::assertTrue($this->act($this->orgManager, 'org_facility_site', [
            'organization_id' => (string) $this->org,
            'label'           => 'ساختمان رگرسیون',
            'address'         => 'تهران، خیابان رگرسیون',
        ]));
        $plan = $service->facility_plan($this->orgManager, $this->org);
        self::assertIsArray($plan);
        $siteKey = (string) $plan['sites'][0]['key'];
        foreach (['درمانگاه رگرسیون الف', 'درمانگاه رگرسیون ب'] as $name) {
            self::assertTrue($this->act($this->orgManager, 'org_facility_clinic', [
                'organization_id' => (string) $this->org,
                'name'            => $name,
                'site_key'        => $siteKey,
                'timezone'        => 'Asia/Tehran',
            ]));
        }
        $plan = $service->facility_plan($this->orgManager, $this->org);
        self::assertIsArray($plan);
        self::assertCount(2, $plan['clinics']);
        $keyA = (string) $plan['clinics'][0]['key'];
        $keyB = (string) $plan['clinics'][1]['key'];
        $clinicA = (int) $plan['clinics'][0]['clinic_id'];
        $clinicB = (int) $plan['clinics'][1]['clinic_id'];

        // Designate via login (A) and via e-mail (B) — both through the same portal action.
        $loginA = (string) get_userdata($this->managerA)->user_login;
        $emailB = (string) get_userdata($this->managerB)->user_email;
        self::assertTrue($this->act($this->orgManager, 'org_facility_designate', [
            'organization_id' => (string) $this->org,
            'clinic_key'      => $keyA,
            'identifier'      => $loginA,
        ]), 'manager A via login');
        self::assertTrue($this->act($this->orgManager, 'org_facility_designate', [
            'organization_id' => (string) $this->org,
            'clinic_key'      => $keyB,
            'identifier'      => $emailB,
        ]), 'manager B via e-mail');

        // DB evidence: 2 distinct memberships persisted, correct role and scope.
        $db = App::db();
        $countA = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d AND wp_user_id = %d AND status = %s AND role_key = %s',
            [$clinicA, $this->managerA, 'active', 'cpms_manager']
        );
        $countB = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d AND wp_user_id = %d AND status = %s AND role_key = %s',
            [$clinicB, $this->managerB, 'active', 'cpms_manager']
        );
        self::assertSame(1, $countA, 'clinic A has exactly one active cpms_manager membership for manager A');
        self::assertSame(1, $countB, 'clinic B has exactly one active cpms_manager membership for manager B');

        // Cross-clinic isolation: each manager sees only its own Clinic.
        self::assertCount(0, $service->organizations($this->managerA), 'manager A has no Organization surface');
        self::assertCount(0, $service->organizations($this->managerB), 'manager B has no Organization surface');
        $clinicsA = $service->clinics($this->managerA);
        $clinicsB = $service->clinics($this->managerB);
        self::assertCount(1, $clinicsA);
        self::assertCount(1, $clinicsB);
        self::assertSame($clinicA, $clinicsA[0]['id']);
        self::assertSame($clinicB, $clinicsB[0]['id']);

        // WordPress role is untouched; no global CPMS cap.
        foreach ([$this->managerA, $this->managerB] as $mgr) {
            self::assertSame(['subscriber'], array_values((array) get_userdata($mgr)->roles));
        }

        // Direct controller dispatch with a non-existent identifier must not create anything.
        $before = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d', [$clinicA]);
        self::assertFalse($this->act($this->orgManager, 'org_facility_designate', [
            'organization_id' => (string) $this->org,
            'clinic_key'      => $keyA,
            'identifier'      => 'no-such-user-' . bin2hex(random_bytes(4)),
        ]));
        $after = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d', [$clinicA]);
        self::assertSame($before, $after, 'unknown identifier creates no membership');
    }

    private function act(int $actor, string $action, array $input): bool
    {
        wp_set_current_user($actor);
        $result = ManagementPortalController::dispatch($actor, $action, $input);
        return is_array($result) && true === ($result['ok'] ?? false);
    }

    private function create_org(string $name): int
    {
        $db = App::db();
        $now = $db->nowUtcSql();
        $db->insert('cpms_organizations', [
            'name'       => $name . ' ' . bin2hex(random_bytes(3)),
            'slug'       => 'regression-' . bin2hex(random_bytes(4)),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $db->wpdb_last_insert_id();
    }

    private function grant_org_manager(int $orgId, int $userId): void
    {
        $db = App::db();
        $now = $db->nowUtcSql();
        $db->insert('cpms_organization_memberships', [
            'organization_id'       => $orgId,
            'wp_user_id'            => $userId,
            'role_key'              => 'organization_manager',
            'status'                => 'active',
            'created_by_wp_user_id' => $userId,
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);
    }
}
