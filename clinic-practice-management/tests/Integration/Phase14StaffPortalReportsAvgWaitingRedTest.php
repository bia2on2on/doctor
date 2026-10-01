<?php
/**
 * Phase 14 Slice 1 — Staff Portal read-only Average Waiting reporting surface
 * for ONE explicitly selected date — TEST-ONLY RED.
 *
 * Authorized Slice 1 contract (Product Owner, 2026-10-01):
 *   - the surface is a read-only module of the canonical Staff Portal
 *     (`StaffPortalShell`, module id `reports`, selected with the existing
 *     `cpms-module` view selector); no wp-admin page, role or capability;
 *   - authority is the EXISTING `cpms_report_read` capability (global WP
 *     capability AND an ACTIVE Clinic membership that grants it), exactly the
 *     established Finance-module visibility model; the REST layer
 *     (`/clinic/v1/reports/avg_waiting`) stays authoritative and unchanged;
 *   - the page requires ONE explicit Gregorian Y-m-d date input, which the
 *     client sends as BOTH `from` and `to`. It never defaults to "today" and
 *     the page render itself never executes a report;
 *   - it reuses the existing report route only (no new report calculation),
 *     and shows aggregate metrics only — no Location selector, chart, export,
 *     print or visit rows.
 *
 * INTENDED PRODUCT RED: the Staff Portal has no `reports` module, no menu
 * entry and no date form. Everything these tests build on (bootstrap,
 * migrations, Clinic/Location/Visit fixtures, Staff Portal render path, REST
 * `avg_waiting` contract and its authorization) is already-delivered behavior;
 * those parts are asserted FIRST in each test (and in the pure control test)
 * and must stay GREEN. The RED assertions are the ones that name the missing
 * Phase 14 module. The negative authorization test (user lacking
 * cpms_report_read) is a fail-closed regression guard that is independent of
 * module registration, so it is GREEN both before and after the module exists. The string `reports` is used instead of a new class
 * constant on purpose, so the failure is an assertion, not an undefined-constant
 * fatal.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\StaffPortalShell;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase14StaffPortalReportsAvgWaitingRedTest extends WP_UnitTestCase
{
    private const MODULE = 'reports';
    private const ROUTE = '/clinic/v1/reports/avg_waiting';
    /** Fixed business date for the REST control (never "today"). */
    private const DATE = '2026-03-14';

    private int $clinicId = 0;
    private int $locationId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        App::migrations()->migrate();

        $this->clinicId = (int) App::db()->fetchValue('SELECT id FROM ' . App::db()->table('cpms_clinics') . ' ORDER BY id ASC LIMIT 1');
        $this->locationId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1 LIMIT 1',
            [$this->clinicId]
        );
        self::assertGreaterThan(0, $this->clinicId, 'fixture: seeded Clinic exists after migrations');
        self::assertGreaterThan(0, $this->locationId, 'fixture: seeded primary Location exists after migrations');
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        $_GET = [];
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        parent::tearDown();
    }

    // ============ CONTROL — already-delivered contract this slice must reuse (GREEN now) ============

    public function testControlExistingAvgWaitingRouteServesOneExplicitDateAggregateOnly(): void
    {
        $manager = $this->makeMember(RolesAndCapabilities::ROLE_MANAGER, 'cpms_manager');
        $this->seedWaitingVisits();

        wp_set_current_user($manager);
        $response = $this->dispatch('GET', self::ROUTE, ['from' => self::DATE, 'to' => self::DATE]);
        self::assertSame(200, $response->get_status(), 'existing avg_waiting route authorizes a cpms_report_read member — ' . $this->errorCode($response));
        $data = $this->payload($response);
        self::assertSame('clinic', $data['scope'] ?? null, 'a member with no Clinician link reports Clinic scope');
        self::assertSame(self::DATE, $data['from'] ?? null);
        self::assertSame(self::DATE, $data['to'] ?? null);
        self::assertSame(2, $data['summary']['visits'] ?? null, 'sample/visit count is part of the existing contract');
        self::assertSame(450, $data['summary']['avg_sec'] ?? null, '(600s + 300s) / 2');
        self::assertCount(1, $data['rows'] ?? [], 'one explicit date yields one aggregate row');
        self::assertSame(
            ['date', 'date_jalali', 'visits', 'avg_wait_sec', 'avg_wait_min'],
            array_keys($data['rows'][0] ?? []),
            'aggregate row only — no patient or visit identifiers'
        );
    }

    // ============ RED 1 — cpms_report_read holder is intended to have the reporting menu/page surface ============

    public function testReportReaderIsEligibleForReportsModuleAndSeesItInStaffNavigation(): void
    {
        $manager = $this->makeMember(RolesAndCapabilities::ROLE_MANAGER, 'cpms_manager');

        // Delivered preconditions (must be GREEN before the product assertions).
        self::assertTrue(get_userdata($manager)->has_cap(RolesAndCapabilities::REPORT_READ), 'precondition: global cpms_report_read');
        self::assertTrue(App::authorization_service()->can($manager, $this->clinicId, RolesAndCapabilities::REPORT_READ), 'precondition: Clinic-scoped cpms_report_read');
        self::assertFalse(StaffPortalShell::module_eligible('does-not-exist', $manager), 'precondition: unknown modules fail closed');

        // Missing Phase 14 product surface.
        self::assertContains(self::MODULE, array_column(StaffPortalShell::registered_modules(), 'id'), 'Staff Portal registers a reports module');
        self::assertTrue(StaffPortalShell::module_eligible(self::MODULE, $manager), 'cpms_report_read holder is eligible for the reports module');
        self::assertContains(self::MODULE, array_column(StaffPortalShell::eligible_modules($manager), 'id'));

        $html = $this->renderPortal($manager, null);
        self::assertStringContainsString('data-cpms-portal="staff"', $html, 'precondition: canonical Staff Portal template rendered');
        self::assertMatchesRegularExpression(
            '/<a\b[^>]*data-role="staff-module-link"[^>]*data-cpms-staff-module="reports"[^>]*>/',
            $html,
            'reports entry appears in the Staff Portal module navigation (menu)'
        );
        self::assertStringNotContainsString('wp-admin', $html, 'reporting is not rendered in wp-admin chrome');
    }

    // ============ RED 2 — explicit date input, no implicit today/default, no silent report execution ============

    public function testReportsModuleRequiresExplicitGregorianDateInputAndNeverDefaultsToToday(): void
    {
        $manager = $this->makeMember(RolesAndCapabilities::ROLE_MANAGER, 'cpms_manager');

        $auditBefore = $this->reportReadAuditCount();
        self::assertSame(0, $auditBefore, 'precondition: no REPORT_READ audit rows yet');

        $html = $this->renderPortal($manager, self::MODULE);
        self::assertStringContainsString('data-cpms-portal="staff"', $html, 'precondition: canonical Staff Portal template rendered');

        // Missing Phase 14 product surface.
        self::assertStringContainsString('data-cpms-staff-module="reports"', $html, 'reports module is mounted for an eligible user');
        self::assertMatchesRegularExpression('/<form\b[^>]*data-role="reports-avg-waiting-form"[^>]*>/', $html, 'average-waiting form exists');

        self::assertSame(1, preg_match('/<input\b[^>]*data-role="reports-date-input"[^>]*>/', $html, $m), 'exactly one explicit date input');
        $input = $m[0];
        self::assertMatchesRegularExpression('/\bname="date"/', $input, 'single date field (not from/to)');
        self::assertMatchesRegularExpression('/\brequired\b/', $input, 'the date is required — the user must choose it');
        self::assertDoesNotMatchRegularExpression('/\bvalue="[^"]+"/', $input, 'no server-side default value (no implicit today)');
        self::assertMatchesRegularExpression(
            '/\btype="date"|\bpattern="[^"]*\\\\d\{4\}-\\\\d\{2\}-\\\\d\{2\}[^"]*"/',
            $input,
            'Gregorian Y-m-d input (native date value or an explicit YYYY-MM-DD pattern)'
        );
        self::assertStringNotContainsString(gmdate('Y-m-d'), $input, 'today is never silently injected');
        self::assertDoesNotMatchRegularExpression('/<input\b[^>]*\bname="(from|to)"/', $html, 'one date drives both from and to; no range inputs');
        self::assertMatchesRegularExpression('/<(button|input)\b[^>]*\btype="submit"/', $html, 'the user explicitly submits the chosen date');

        // Reuses the existing report route only; surfaces excluded from Slice 1 are absent.
        $normalized = str_replace('\\/', '/', $html);
        self::assertStringContainsString('reports/avg_waiting', $normalized, 'existing avg_waiting contract is reused');
        foreach (['reports/revenue', 'reports/visits', '/export', '/print'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $normalized, 'Slice 1 excludes: ' . $forbidden);
        }
        self::assertStringNotContainsString('<canvas', $html, 'no chart');
        self::assertStringNotContainsString('<select', $html, 'no Location (or other) selector');

        self::assertSame($auditBefore, $this->reportReadAuditCount(), 'rendering the page never silently runs a report for a default date');
    }

    // ============ NEGATIVE — lacking cpms_report_read is not offered/authorized; raw selector creates no authority ============
    // (Corrected: an earlier revision wrongly asserted the reports module is registered. Registration is
    // global and says nothing about a user lacking the capability; that was a test-contract defect.)

    public function testUserLackingReportReadIsNotAuthorizedForReportsSurface(): void
    {
        $secretary = $this->makeMember(RolesAndCapabilities::ROLE_SECRETARY, 'cpms_secretary');
        // Active Clinic membership whose preset WOULD grant report read, but the
        // WordPress user holds no global cpms_report_read: membership alone is not access.
        $memberOnly = $this->makeMember('subscriber', 'cpms_manager');

        // Delivered authorization truth (must be GREEN before the product assertions).
        self::assertFalse(get_userdata($secretary)->has_cap(RolesAndCapabilities::REPORT_READ), 'precondition: secretary lacks cpms_report_read');
        self::assertFalse(get_userdata($memberOnly)->has_cap(RolesAndCapabilities::REPORT_READ), 'precondition: member-only user lacks global cpms_report_read');
        foreach ([$secretary, $memberOnly] as $actor) {
            wp_set_current_user($actor);
            $denied = $this->dispatch('GET', self::ROUTE, ['from' => self::DATE, 'to' => self::DATE]);
            self::assertSame(403, $denied->get_status(), 'REST remains authoritative and denies the report to a user lacking cpms_report_read');
        }
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($denied));

        // Fail-closed contract. It must hold whether or not a reports module is
        // registered (it must NOT depend on module registration/visibility for a
        // user who lacks the capability), so nothing here asserts registration.
        foreach ([$secretary, $memberOnly] as $actor) {
            self::assertFalse(StaffPortalShell::module_eligible(self::MODULE, $actor), 'user lacking cpms_report_read is not eligible for the reports surface');
            self::assertNotContains(self::MODULE, array_column(StaffPortalShell::eligible_modules($actor), 'id'), 'the reports surface is not offered');
            self::assertNotSame(self::MODULE, StaffPortalShell::select_module($actor), 'a raw cpms-module selector never selects an ineligible module');
        }

        // The forced selector renders no reporting markup for an actor with no eligible module at all.
        $html = $this->renderPortal($memberOnly, self::MODULE);
        self::assertStringContainsString('data-role="portal-access-denied"', $html, 'ineligible user receives the staff access notice');
        self::assertStringNotContainsString('data-cpms-staff-module="reports"', $html);
        self::assertStringNotContainsString('data-role="reports-avg-waiting-form"', $html);
        self::assertStringNotContainsString('reports/avg_waiting', str_replace('\\/', '/', $html), 'no report route/nonce payload for an unauthorized visitor');
    }

    // ============ fixtures / helpers ============

    private function makeMember(string $role, string $membershipRoleKey): int
    {
        $id = (int) wp_create_user('p14_' . bin2hex(random_bytes(4)), 'test-password-123', 'p14_' . bin2hex(random_bytes(4)) . '@test.local');
        self::assertGreaterThan(0, $id, 'user fixture');
        get_userdata($id)->set_role($role);
        cpms_test_seed_membership($id, $this->clinicId, $membershipRoleKey);

        return $id;
    }

    private function seedWaitingVisits(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinicians', [
            'clinic_id' => $this->clinicId, 'full_name' => 'Dr Report', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]), 'clinician fixture');
        $clinicianId = (int) $wpdb->insert_id;

        foreach ([['10:00:00', '10:10:00'], ['11:00:00', '11:05:00']] as $i => [$waitingSince, $calledAt]) {
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', [
                'clinic_id' => $this->clinicId, 'mrn' => 'P14-' . bin2hex(random_bytes(4)), 'first_name' => 'Patient' . $i, 'last_name' => 'Wait',
                'mobile' => '0919' . random_int(1000000, 9999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]), 'patient fixture');
            $patientId = (int) $wpdb->insert_id;
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_visits', [
                'clinic_id' => $this->clinicId, 'location_id' => $this->locationId, 'clinician_id' => $clinicianId,
                'patient_id' => $patientId, 'source' => 'walk_in', 'status' => 'waiting', 'visit_date' => self::DATE,
                'check_in_at' => self::DATE . ' ' . $waitingSince . '.000',
                'waiting_since' => self::DATE . ' ' . $waitingSince . '.000',
                'called_at' => self::DATE . ' ' . $calledAt . '.000',
                'active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]), 'visit fixture');
        }
    }

    private function reportReadAuditCount(): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s', ['REPORT_READ']);
    }

    /** Render the canonical Staff Portal document through the real template_include path. */
    private function renderPortal(int $userId, ?string $module): string
    {
        wp_set_current_user($userId);
        $url = StaffPortalShell::portal_url();
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?? '');
        $previousGet = $_GET;
        try {
            $target = $path . ($query !== '' ? '?' . $query : '');
            if ($module !== null) {
                $target .= ($query !== '' ? '&' : '?') . StaffPortalShell::MODULE_PARAM . '=' . $module;
            }
            $this->go_to($target);
            if ($module !== null) {
                $_GET[StaffPortalShell::MODULE_PARAM] = $module;
            }
            $baseline = get_stylesheet_directory() . '/page.php';
            if (!is_readable($baseline)) {
                $baseline = get_stylesheet_directory() . '/index.php';
            }
            $template = (string) apply_filters('template_include', $baseline);
            self::assertNotSame($baseline, $template, 'template_include must use the plugin-owned Staff Portal template');
            ob_start();
            include $template;

            return (string) ob_get_clean();
        } finally {
            $_GET = $previousGet;
        }
    }

    /** @param array<string, mixed> $params */
    private function dispatch(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $this->clinicId);

        return rest_do_request($request);
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Response $response): array
    {
        $data = $response->get_data();
        if (is_array($data) && array_key_exists('data', $data)) {
            return is_array($data['data']) ? $data['data'] : [];
        }

        return is_array($data) ? $data : [];
    }

    private function errorCode(WP_REST_Response $response): string
    {
        $data = $response->get_data();

        return is_array($data) ? (string) ($data['code'] ?? '') : '';
    }
}
