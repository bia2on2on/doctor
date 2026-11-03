<?php
/**
 * Runtime proof (real migrations, real MySQL, REAL template render) that the
 * Management Portal shell shows its facility-plan and table counters with
 * Persian digits while every machine value (data-* attributes, URL query ids,
 * hidden form inputs) stays Latin.
 *
 * Fixture discipline mirrors DelegatedClinicCompletionTest: a dynamic
 * Organization created per test and purged by Organization in tearDown.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Onboarding\FacilitySetupService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\ManagementPortalShell;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

final class ManagementShellPersianDigitsTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private const TZ = 'Asia/Tehran';

    /** @var list<int> */
    private array $orgs = [];

    private int $org_actor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        Settings::flushCache();
        App::resetScope();
        \ClinicCore\Application\Scope\ScopeContext::clear();
        $this->org_actor = $this->make_user('mspd_orgmgr', 'subscriber');
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
            $this->purge_organization($org);
        }
        $this->orgs = [];
        parent::tearDown();
    }

    public function test_org_overview_counters_render_persian_and_machine_values_latin(): void
    {
        $org = $this->fresh_org('MSPD counters');
        $portal = App::management_portal_service();

        $start = $portal->start_facility_setup(
            $this->org_actor,
            $org,
            'general_clinic',
            'multi_doctor',
            'مرکز شمارندهٔ فارسی'
        );
        self::assertTrue($start['ok'], (string) ($start['message'] ?? 'start failed'));

        $plan = $portal->facility_plan($this->org_actor, $org);
        if (empty($plan['sites'])) {
            $portal->add_facility_site($this->org_actor, $org, 'ساختمان مرکزی', 'تهران');
            $plan = $portal->facility_plan($this->org_actor, $org);
        }
        self::assertNotEmpty($plan['sites'], 'plan exposes its sites');
        $site = (string) $plan['sites'][0]['key'];

        $created = $portal->add_facility_clinic(
            $this->org_actor,
            $org,
            'درمانگاه شمارنده',
            $site,
            self::TZ
        );
        self::assertTrue($created['ok'], (string) ($created['message'] ?? 'clinic create failed'));

        $plan = $portal->facility_plan($this->org_actor, $org);
        self::assertSame(1, count((array) $plan['sites']), 'fixture: exactly one site');
        self::assertSame(1, count((array) $plan['clinics']), 'fixture: exactly one Clinic');

        $html = $this->render_portal($this->org_actor, ['cpms-org' => $org]);

        // Facility-plan counters: Persian digits, bounded so «۱» never
        // matches the head of «۱۰» (next char is no digit in any set).
        self::assertMatchesRegularExpression(
            '/— ساختمان‌ها: ۱(?![0-9۰-۹٠-٩])/u',
            $html
        );
        self::assertMatchesRegularExpression(
            '/— درمانگاه‌ها: ۱(?![0-9۰-۹٠-٩])/u',
            $html
        );
        self::assertStringNotContainsString('— ساختمان‌ها: 1', $html);
        self::assertStringNotContainsString('— درمانگاه‌ها: 1', $html);
        // Plan meta attributes stay machine-valued.
        self::assertStringContainsString('data-cpms-plan-steps="1"', $html);

        // The org table's active-Location count cell is a Persian-digit cell;
        // no pure-Latin numeric table cell remains on the page.
        self::assertMatchesRegularExpression('/<td>[۰-۹]+<\/td>/u', $html);
        self::assertDoesNotMatchRegularExpression('/<td>[0-9]+<\/td>/', $html);

        // Machine values stay Latin: data-* id, URL query id, hidden input id.
        self::assertStringContainsString('data-cpms-org="' . $org . '"', $html);
        self::assertStringContainsString('cpms-org=' . $org, $html);
        self::assertStringContainsString(
            'name="organization_id" value="' . $org . '"',
            $html
        );
    }

    private function render_portal(int $user_id, array $get): string
    {
        ManagementPortalShell::ensure_portal_page();
        $_GET = $get;
        wp_set_current_user($user_id);
        ob_start();
        include dirname(__DIR__, 2) . '/templates/management-portal-shell.php';
        $html = (string) ob_get_clean();
        wp_set_current_user(0);
        $_GET = [];

        return $html;
    }

    private function fresh_org(string $label): int
    {
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        App::db()->insert('cpms_organizations', [
            'name' => $label . ' ' . $unique,
            'slug' => 'mspd-org-' . $unique,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $org = App::db()->wpdb_last_insert_id();
        self::assertGreaterThan(0, $org, 'precondition: organization inserted');
        $this->orgs[] = $org;

        App::db()->insert('cpms_organization_memberships', [
            'organization_id' => $org,
            'wp_user_id' => $this->org_actor,
            'role_key' => 'organization_manager',
            'status' => 'active',
            'created_by_wp_user_id' => $this->org_actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::assertGreaterThan(
            0,
            App::db()->wpdb_last_insert_id(),
            'precondition: organization membership'
        );

        return $org;
    }

    private function make_user(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $user_id = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@mspd.test');
        self::assertGreaterThan(0, $user_id, 'precondition: wp user ' . $login);
        $user = get_userdata($user_id);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $user_id;
    }

    private function purge_organization(int $org): void
    {
        global $wpdb;
        if ($org <= 0) {
            return;
        }
        $p = $wpdb->prefix;
        $clinics = 'SELECT id FROM ' . $p . 'cpms_clinics WHERE organization_id = ' . $org;
        $clinic_tables = [
            'cpms_schedule_slots',
            'cpms_schedule_exceptions',
            'cpms_schedule',
        ];
        foreach ($clinic_tables as $table) {
            $sql = 'DELETE t FROM ' . $p . $table . ' t WHERE t.clinic_id IN (' . $clinics . ')';
            $wpdb->query($sql); // phpcs:ignore
        }
        $wpdb->query( // phpcs:ignore
            'DELETE t FROM ' . $p . 'cpms_clinician_locations t JOIN ' . $p
            . 'cpms_locations l ON l.id = t.location_id JOIN ' . $p
            . 'cpms_clinics c ON c.id = l.clinic_id WHERE c.organization_id = ' . $org
        );
        $wpdb->query( // phpcs:ignore
            'DELETE mc FROM ' . $p . 'cpms_membership_capabilities mc JOIN ' . $p
            . 'cpms_clinic_memberships m ON m.id = mc.membership_id JOIN ' . $p
            . 'cpms_clinics c ON c.id = m.clinic_id WHERE c.organization_id = ' . $org
        );
        $wpdb->query( // phpcs:ignore
            'DELETE ml FROM ' . $p . 'cpms_membership_locations ml JOIN ' . $p
            . 'cpms_clinic_memberships m ON m.id = ml.membership_id JOIN ' . $p
            . 'cpms_clinics c ON c.id = m.clinic_id WHERE c.organization_id = ' . $org
        );
        $wpdb->query( // phpcs:ignore
            'DELETE m FROM ' . $p . 'cpms_clinic_memberships m JOIN ' . $p
            . 'cpms_clinics c ON c.id = m.clinic_id WHERE c.organization_id = ' . $org
        );
        foreach (['cpms_clinicians', 'cpms_settings', 'cpms_locations'] as $table) {
            $sql = 'DELETE t FROM ' . $p . $table . ' t WHERE t.clinic_id IN (' . $clinics . ')';
            $wpdb->query($sql); // phpcs:ignore
        }
        $wpdb->query( // phpcs:ignore
            'DELETE FROM ' . $p . 'cpms_clinics WHERE organization_id = ' . $org
        );
        $wpdb->query( // phpcs:ignore
            'DELETE c FROM ' . $p . 'cpms_organization_membership_capabilities c JOIN ' . $p
            . 'cpms_organization_memberships m ON m.id = c.membership_id '
            . 'WHERE m.organization_id = ' . $org
        );
        $org_sql = 'DELETE FROM ' . $p . 'cpms_organization_memberships '
            . 'WHERE organization_id = ' . $org;
        $wpdb->query($org_sql); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $p . 'cpms_organizations WHERE id = ' . $org); // phpcs:ignore
        delete_option(FacilitySetupService::OPTION_PREFIX . $org);
    }
}
