<?php
/**
 * Phase 14 Slice 1 — multi-Clinic scope repair for the Staff Portal Reports
 * module (class A blocker: a reporter with several ACTIVE Clinic memberships
 * saw Reports, but the page sent no Clinic context, so the REST boundary
 * failed closed with CLINIC_SCOPE_REQUIRED and the user was stuck).
 *
 * Contract under test (no new protocol, no new route/capability/migration):
 *   - eligible Clinics = global `cpms_report_read` AND an ACTIVE membership whose
 *     Clinic-scoped authorization grants it AND an ACTIVE Organization (the same
 *     conditions the REST trusted-scope establisher enforces);
 *   - 0 eligible -> module not eligible / fail closed; exactly 1 -> that Clinic
 *     is rendered and sent via the EXISTING `X-CPMS-Clinic-Id` mechanism; N>1 ->
 *     the page renders an explicit Clinic select with NO default (no first-Clinic
 *     fallback);
 *   - a Clinic id is a SELECTOR only: the existing REST boundary
 *     (RestClinicContext -> TrustedClinicEstablisher -> scoped permission) is the
 *     authority, so foreign / suspended / unauthorized / malformed / stale
 *     selectors fail closed and raw ids create no authority;
 *   - own-vs-Clinic scope semantics and the explicit-date rule are unchanged.
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

final class Phase14StaffPortalReportsClinicScopeTest extends WP_UnitTestCase
{
    private const MODULE = 'reports';
    private const ROUTE = '/clinic/v1/reports/avg_waiting';
    private const DATE = '2026-03-14';

    private int $clinicA = 0;
    private int $clinicB = 0;
    /** Clinic nobody in this suite is a member of. */
    private int $clinicForeign = 0;
    /** Clinic in a SUSPENDED Organization (members hold an ACTIVE membership). */
    private int $clinicSuspendedOrg = 0;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        App::migrations()->migrate();

        $db = App::db();
        $this->clinicA = (int) $db->fetchValue('SELECT id FROM ' . $db->table('cpms_clinics') . ' ORDER BY id ASC LIMIT 1');
        self::assertGreaterThan(0, $this->clinicA, 'fixture: seeded Clinic A');
        $orgId = (int) $db->fetchValue('SELECT organization_id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [$this->clinicA]);

        $this->clinicB = $this->insertClinic($orgId, 'Clinic Scope Beta');
        $this->clinicForeign = $this->insertClinic($orgId, 'Clinic Scope Foreign');
        $suspendedOrg = $this->insertOrganization('suspended');
        $this->clinicSuspendedOrg = $this->insertClinic($suspendedOrg, 'Clinic Scope Suspended Org');
        SystemClinicResolver::flush();

        // A: 600s + 300s (Dr A, no user) + 60s (the linked doctor's own visit).
        // B: 120s (Dr B). Foreign: 900s (must never appear for a non-member).
        $drA = $this->insertClinician($this->clinicA, null, 'Dr A');
        $drB = $this->insertClinician($this->clinicB, null, 'Dr B');
        $drForeign = $this->insertClinician($this->clinicForeign, null, 'Dr Foreign');
        $this->seedVisit($this->clinicA, $drA, '10:00:00', '10:10:00');
        $this->seedVisit($this->clinicA, $drA, '11:00:00', '11:05:00');
        $this->seedVisit($this->clinicB, $drB, '09:00:00', '09:02:00');
        $this->seedVisit($this->clinicForeign, $drForeign, '08:00:00', '08:15:00');
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

    // ============ 1 eligible Clinic: resolves without guessing another Clinic ============

    public function testExactlyOneEligibleClinicResolvesAutomaticallyAndSendsThatClinicOnly(): void
    {
        // Two ACTIVE memberships, but only Clinic A grants cpms_report_read.
        $reporter = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($reporter, $this->clinicA, 'cpms_manager');
        cpms_test_seed_membership($reporter, $this->clinicB, 'cpms_secretary');

        self::assertTrue(App::authorization_service()->can($reporter, $this->clinicA, RolesAndCapabilities::REPORT_READ), 'precondition: A grants report read');
        self::assertFalse(App::authorization_service()->can($reporter, $this->clinicB, RolesAndCapabilities::REPORT_READ), 'precondition: B (secretary membership) does not');

        self::assertSame([$this->clinicA], array_column(StaffPortalShell::reports_eligible_clinics($reporter), 'id'));
        self::assertTrue(StaffPortalShell::module_eligible(self::MODULE, $reporter));

        $html = $this->renderPortal($reporter, self::MODULE);
        self::assertSame(1, preg_match('/<p\b[^>]*data-role="reports-clinic-fixed"[^>]*data-clinic-id="(\d+)"/', $html, $m), 'the single eligible Clinic is shown');
        self::assertSame((string) $this->clinicA, $m[1]);
        self::assertStringNotContainsString('<select', $html, 'a single eligible Clinic needs no selector');
        self::assertStringNotContainsString('Clinic Scope Beta', $html, 'an ineligible membership Clinic is never offered');
        self::assertStringContainsString('X-CPMS-Clinic-Id', $html, 'the page uses the existing REST Clinic-context header');

        // Why the page must send the selector: with 2 ACTIVE memberships the REST
        // boundary will not auto-resolve (no first-Clinic fallback).
        wp_set_current_user($reporter);
        $noContext = $this->dispatch(self::ROUTE, ['from' => self::DATE, 'to' => self::DATE], null);
        self::assertSame(400, $noContext->get_status());
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($noContext));

        $ok = $this->dispatch(self::ROUTE, ['from' => self::DATE, 'to' => self::DATE], (string) $this->clinicA);
        self::assertSame(200, $ok->get_status(), $this->errorCode($ok));
        $data = $this->payload($ok);
        self::assertSame('clinic', $data['scope'] ?? null);
        self::assertSame(2, $data['summary']['visits'] ?? null);
        self::assertSame(450, $data['summary']['avg_sec'] ?? null);
    }

    // ============ N eligible Clinics: explicit selection, no default ============

    public function testMultipleEligibleClinicsRequireExplicitSelectionWithNoDefault(): void
    {
        $reporter = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($reporter, $this->clinicB, 'cpms_manager');
        cpms_test_seed_membership($reporter, $this->clinicA, 'cpms_manager');
        // Active membership in a Clinic of a SUSPENDED Organization: never offered.
        cpms_test_seed_membership($reporter, $this->clinicSuspendedOrg, 'cpms_manager');

        self::assertSame(
            [$this->clinicA, $this->clinicB],
            array_column(StaffPortalShell::reports_eligible_clinics($reporter), 'id'),
            'stable id order; suspended-Organization Clinic excluded; foreign Clinic absent'
        );

        $html = $this->renderPortal($reporter, self::MODULE);
        self::assertSame(1, preg_match('/<select\b[^>]*data-role="reports-clinic-select"[^>]*>(.*?)<\/select>/s', $html, $m), 'explicit Clinic select is rendered');
        self::assertStringContainsString('required', $m[0]);
        self::assertSame(3, preg_match_all('/<option\b[^>]*value="(\d*)"[^>]*>/', $m[1], $options), 'placeholder + two Clinics');
        self::assertSame(['', (string) $this->clinicA, (string) $this->clinicB], $options[1], 'placeholder first, then each eligible Clinic exactly once');
        self::assertDoesNotMatchRegularExpression('/<option\b[^>]*\bselected\b/', $m[1], 'no Clinic is pre-selected (no first-Clinic fallback)');
        self::assertStringNotContainsString('data-role="reports-clinic-fixed"', $html);
        self::assertStringNotContainsString('Clinic Scope Foreign', $html);
        self::assertStringNotContainsString('Clinic Scope Suspended Org', $html);
        self::assertStringNotContainsString('reports-location', $html, 'no Location selector');

        // The explicit-date rule is untouched by the repair.
        self::assertSame(1, preg_match_all('/<input\b[^>]*data-role="reports-date-input"[^>]*>/', $html));
        self::assertDoesNotMatchRegularExpression('/<input\b[^>]*data-role="reports-date-input"[^>]*\bvalue=/', $html);

        // Without any Clinic context the REST boundary still fails closed.
        wp_set_current_user($reporter);
        $none = $this->dispatch(self::ROUTE, ['from' => self::DATE, 'to' => self::DATE], null);
        self::assertSame(400, $none->get_status());
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($none));
    }

    // ============ valid selection reaches avg_waiting under THAT trusted Clinic only ============

    public function testValidSelectedClinicReturnsDataOnlyUnderThatTrustedClinic(): void
    {
        $reporter = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($reporter, $this->clinicA, 'cpms_manager');
        cpms_test_seed_membership($reporter, $this->clinicB, 'cpms_manager');
        wp_set_current_user($reporter);

        $a = $this->dispatch(self::ROUTE, ['from' => self::DATE, 'to' => self::DATE], (string) $this->clinicA);
        self::assertSame(200, $a->get_status(), $this->errorCode($a));
        $dataA = $this->payload($a);
        self::assertSame('clinic', $dataA['scope'] ?? null);
        self::assertSame(self::DATE, $dataA['from'] ?? null);
        self::assertSame(self::DATE, $dataA['to'] ?? null, 'from = to = the explicit date');
        self::assertSame(2, $dataA['summary']['visits'] ?? null);
        self::assertSame(450, $dataA['summary']['avg_sec'] ?? null, 'Clinic A only: (600s + 300s) / 2');

        $b = $this->dispatch(self::ROUTE, ['from' => self::DATE, 'to' => self::DATE], (string) $this->clinicB);
        self::assertSame(200, $b->get_status(), $this->errorCode($b));
        $dataB = $this->payload($b);
        self::assertSame(1, $dataB['summary']['visits'] ?? null);
        self::assertSame(120, $dataB['summary']['avg_sec'] ?? null, 'Clinic B only: 120s — not blended with Clinic A or the foreign Clinic');

        // Sequential switch back: no state leaks between selections.
        $again = $this->payload($this->dispatch(self::ROUTE, ['from' => self::DATE, 'to' => self::DATE], (string) $this->clinicA));
        self::assertSame(450, $again['summary']['avg_sec'] ?? null);
    }

    // ============ foreign / suspended / unauthorized / malformed / stale selectors fail closed ============

    public function testForeignSuspendedUnauthorizedMalformedAndStaleSelectorsFailClosed(): void
    {
        $reporter = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        $membershipA = cpms_test_seed_membership($reporter, $this->clinicA, 'cpms_manager');
        $membershipB = cpms_test_seed_membership($reporter, $this->clinicB, 'cpms_manager');
        cpms_test_seed_membership($reporter, $this->clinicSuspendedOrg, 'cpms_manager');
        wp_set_current_user($reporter);
        $range = ['from' => self::DATE, 'to' => self::DATE];

        // Foreign Clinic (no membership): selector grants nothing.
        $foreign = $this->dispatch(self::ROUTE, $range, (string) $this->clinicForeign);
        self::assertSame(403, $foreign->get_status());
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($foreign));
        self::assertStringNotContainsString('"avg_sec"', (string) wp_json_encode($foreign->get_data()), 'no foreign data');

        // Membership exists but the Clinic's Organization is suspended.
        $suspended = $this->dispatch(self::ROUTE, $range, (string) $this->clinicSuspendedOrg);
        self::assertSame(403, $suspended->get_status());
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($suspended));

        // Non-existent Clinic id.
        $missing = $this->dispatch(self::ROUTE, $range, '99999999');
        self::assertSame(403, $missing->get_status());
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($missing));

        // Malformed selectors.
        foreach (['abc', '0', '-1', '1abc', '1.5', ' 1', '01'] as $raw) {
            $bad = $this->dispatch(self::ROUTE, $range, $raw);
            self::assertSame(422, $bad->get_status(), 'malformed selector rejected: ' . $raw);
            self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($bad), $raw);
        }

        // Raw/forced Clinic id in the query string is a selector too: a foreign id
        // fails closed, and a header/param disagreement is rejected.
        $forcedParam = $this->dispatch(self::ROUTE, $range + ['clinic_id' => (string) $this->clinicForeign], null);
        self::assertSame(403, $forcedParam->get_status());
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($forcedParam));
        $disagree = $this->dispatch(self::ROUTE, $range + ['clinic_id' => (string) $this->clinicForeign], (string) $this->clinicA);
        self::assertSame(422, $disagree->get_status());
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($disagree));
        // A Location id cannot establish (or widen) a Clinic.
        $locOnly = $this->dispatch(self::ROUTE, $range + ['location_id' => '1'], null);
        self::assertContains($locOnly->get_status(), [400, 403, 422]);
        self::assertNotSame(200, $locOnly->get_status());

        // Stale selector: a Clinic that WAS valid when the page rendered, then the
        // membership is suspended. The rendered list never grants anything.
        self::assertSame(200, $this->dispatch(self::ROUTE, $range, (string) $this->clinicB)->get_status(), 'precondition: B valid before');
        App::membership_service()->suspend_membership($membershipB);
        $stale = $this->dispatch(self::ROUTE, $range, (string) $this->clinicB);
        self::assertSame(403, $stale->get_status());
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($stale));
        self::assertSame(200, $this->dispatch(self::ROUTE, $range, (string) $this->clinicA)->get_status(), 'the remaining valid Clinic still works');
        self::assertSame([$this->clinicA], array_column(StaffPortalShell::reports_eligible_clinics($reporter), 'id'), 'the suspended membership is no longer offered');
        self::assertGreaterThan(0, $membershipA);
    }

    public function testUnauthorizedClinicAndNoEligibleClinicFailClosed(): void
    {
        // Global cap + manager in A; secretary membership (no report read) in B.
        $mixed = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($mixed, $this->clinicA, 'cpms_manager');
        cpms_test_seed_membership($mixed, $this->clinicB, 'cpms_secretary');
        self::assertFalse(App::authorization_service()->can($mixed, $this->clinicB, RolesAndCapabilities::REPORT_READ), 'precondition');
        wp_set_current_user($mixed);
        $denied = $this->dispatch(self::ROUTE, ['from' => self::DATE, 'to' => self::DATE], (string) $this->clinicB);
        self::assertSame(403, $denied->get_status(), 'selecting a Clinic where the actor lacks the report capability is denied');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($denied));

        // Global capability but ALL memberships suspended / none eligible.
        $none = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        $membership = cpms_test_seed_membership($none, $this->clinicA, 'cpms_manager');
        App::membership_service()->suspend_membership($membership);
        self::assertSame([], StaffPortalShell::reports_eligible_clinics($none));
        self::assertFalse(StaffPortalShell::module_eligible(self::MODULE, $none));
        $html = $this->renderPortal($none, self::MODULE);
        self::assertStringNotContainsString('data-role="reports-avg-waiting-form"', $html, 'zero eligible Clinics: no form, no report surface');
        wp_set_current_user($none);
        $res = $this->dispatch(self::ROUTE, ['from' => self::DATE, 'to' => self::DATE], (string) $this->clinicA);
        self::assertSame(403, $res->get_status(), 'a raw Clinic id never creates authority');

        // Membership-only (no global capability) and a user with no membership at all.
        $memberOnly = $this->makeUser('subscriber');
        cpms_test_seed_membership($memberOnly, $this->clinicA, 'cpms_manager');
        $stranger = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        foreach ([$memberOnly, $stranger] as $actor) {
            self::assertSame([], StaffPortalShell::reports_eligible_clinics($actor));
            wp_set_current_user($actor);
            $r = $this->dispatch(self::ROUTE, ['from' => self::DATE, 'to' => self::DATE], (string) $this->clinicA);
            self::assertSame(403, $r->get_status());
        }
        self::assertSame([], StaffPortalShell::reports_eligible_clinics(0));
    }

    // ============ own-vs-Clinic semantics unchanged ============

    public function testOwnVersusClinicScopeSemanticsAreUnchangedPerSelectedClinic(): void
    {
        $doctor = $this->makeUser('cpms_doctor');
        cpms_test_seed_membership($doctor, $this->clinicA, 'cpms_doctor');
        cpms_test_seed_membership($doctor, $this->clinicB, 'cpms_doctor');
        // One Clinician profile (home Clinic A) with a single 60s visit in A.
        $profile = $this->insertClinician($this->clinicA, $doctor, 'Dr Own');
        $this->seedVisit($this->clinicA, $profile, '12:00:00', '12:01:00');
        $manager = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($manager, $this->clinicA, 'cpms_manager');
        cpms_test_seed_membership($manager, $this->clinicB, 'cpms_manager');

        self::assertSame([$this->clinicA, $this->clinicB], array_column(StaffPortalShell::reports_eligible_clinics($doctor), 'id'));
        $range = ['from' => self::DATE, 'to' => self::DATE];

        wp_set_current_user($doctor);
        $ownA = $this->payload($this->dispatch(self::ROUTE, $range, (string) $this->clinicA));
        self::assertSame('own', $ownA['scope'] ?? null, 'a linked Clinician keeps own scope');
        self::assertSame(1, $ownA['summary']['visits'] ?? null);
        self::assertSame(60, $ownA['summary']['avg_sec'] ?? null);
        $ownB = $this->payload($this->dispatch(self::ROUTE, $range, (string) $this->clinicB));
        self::assertSame('own', $ownB['scope'] ?? null);
        self::assertSame(0, $ownB['summary']['visits'] ?? null, 'own scope never widens to the other Clinic');

        wp_set_current_user($manager);
        $clinicA = $this->payload($this->dispatch(self::ROUTE, $range, (string) $this->clinicA));
        self::assertSame('clinic', $clinicA['scope'] ?? null, 'no Clinician link keeps Clinic scope');
        self::assertSame(3, $clinicA['summary']['visits'] ?? null);
        self::assertSame(320, $clinicA['summary']['avg_sec'] ?? null, '(600 + 300 + 60) / 3');
    }

    // ============ fixtures / helpers ============

    private function makeUser(string $role): int
    {
        $id = (int) wp_create_user('p14s_' . bin2hex(random_bytes(4)), 'test-password-123', 'p14s_' . bin2hex(random_bytes(4)) . '@test.local');
        self::assertGreaterThan(0, $id, 'user fixture');
        get_userdata($id)->set_role($role);

        return $id;
    }

    private function insertOrganization(string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $slug = 'p14s-org-' . bin2hex(random_bytes(4));
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_organizations', [
            'name' => 'Org ' . $slug, 'slug' => $slug, 'status' => $status, 'created_at' => $now, 'updated_at' => $now,
        ]), 'organization fixture');

        return (int) $wpdb->insert_id;
    }

    private function insertClinic(int $organizationId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinics', [
            'organization_id' => $organizationId, 'name' => $name, 'slug' => 'p14s-' . bin2hex(random_bytes(4)),
            'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now,
        ]), 'clinic fixture');
        $clinicId = (int) $wpdb->insert_id;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_locations', [
            'clinic_id' => $clinicId, 'name' => $name . ' Loc', 'slug' => 'p14s-loc-' . bin2hex(random_bytes(4)),
            'timezone' => 'Asia/Tehran', 'is_primary' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]), 'location fixture');

        return $clinicId;
    }

    private function primaryLocation(int $clinicId): int
    {
        $id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1 LIMIT 1',
            [$clinicId]
        );
        self::assertGreaterThan(0, $id, 'primary Location for Clinic ' . $clinicId);

        return $id;
    }

    private function insertClinician(int $clinicId, ?int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $row = ['clinic_id' => $clinicId, 'full_name' => $name, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now];
        if ($wpUserId !== null) {
            $row['wp_user_id'] = $wpUserId;
        }
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinicians', $row), 'clinician fixture');

        return (int) $wpdb->insert_id;
    }

    private function seedVisit(int $clinicId, int $clinicianId, string $waitingSince, string $calledAt): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id' => $clinicId, 'mrn' => 'P14S-' . bin2hex(random_bytes(4)), 'first_name' => 'Patient', 'last_name' => 'Wait',
            'mobile' => '0919' . random_int(1000000, 9999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]), 'patient fixture');
        $patientId = (int) $wpdb->insert_id;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_visits', [
            'clinic_id' => $clinicId, 'location_id' => $this->primaryLocation($clinicId), 'clinician_id' => $clinicianId,
            'patient_id' => $patientId, 'source' => 'walk_in', 'status' => 'waiting', 'visit_date' => self::DATE,
            'check_in_at' => self::DATE . ' ' . $waitingSince . '.000',
            'waiting_since' => self::DATE . ' ' . $waitingSince . '.000',
            'called_at' => self::DATE . ' ' . $calledAt . '.000',
            'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]), 'visit fixture');
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
    private function dispatch(string $route, array $params, ?string $clinicHeader): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        if ($clinicHeader !== null) {
            $request->set_header('X-CPMS-Clinic-Id', $clinicHeader);
        }

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
