<?php
/**
 * Phase 14 Slice 2 — acceptance coverage for aggregate Visit Duration in the
 * existing Staff Portal Reports module. This test was first introduced as the
 * test-only RED preserved in the PR history; it now guards the completed GREEN.
 *
 * The existing GET route is the backend control: explicit Clinic/date requests
 * must return the current ReportService aggregate and no PHI. The product
 * contract is asserted on the real Reports template_include render path.
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

final class Phase14StaffPortalReportsVisitDurationRedTest extends WP_UnitTestCase
{
    private const MODULE = 'reports';
    private const ROUTE = '/clinic/v1/reports/visit_duration';
    private const DATE = '2026-03-14';

    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locationA = 0;
    private int $locationB = 0;
    private int $clinicianA = 0;
    private int $clinicianB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        App::migrations()->migrate();

        $db = App::db();
        $this->clinicA = (int) $db->fetchValue(
            'SELECT id FROM ' . $db->table('cpms_clinics') . ' ORDER BY id ASC LIMIT 1'
        );
        self::assertGreaterThan(0, $this->clinicA, 'fixture: seeded Clinic A');
        $organizationId = (int) $db->fetchValue(
            'SELECT organization_id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d',
            [$this->clinicA]
        );
        self::assertGreaterThan(0, $organizationId, 'fixture: Clinic A has an Organization');

        $this->locationA = $this->primaryLocation($this->clinicA);
        $this->clinicB = $this->insertClinic($organizationId, 'Visit Duration Clinic Beta', 'America/Los_Angeles');
        $this->locationB = $this->primaryLocation($this->clinicB);
        $this->clinicianA = $this->insertClinician($this->clinicA, null, 'Visit Duration Doctor Alpha');
        $this->clinicianB = $this->insertClinician($this->clinicB, null, 'Visit Duration Doctor Beta');
        SystemClinicResolver::flush();
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

    /** Existing backend control: Clinic selector + recorded date + aggregate-only response. */
    public function testControlExistingVisitDurationRouteUsesSelectedClinicAndOneRecordedDate(): void
    {
        $reporter = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($reporter, $this->clinicA, 'cpms_manager');
        $membershipB = cpms_test_seed_membership($reporter, $this->clinicB, 'cpms_manager');

        self::assertTrue(get_userdata($reporter)->has_cap(RolesAndCapabilities::REPORT_READ), 'precondition: existing global cpms_report_read');
        self::assertTrue(App::authorization_service()->can($reporter, $this->clinicA, RolesAndCapabilities::REPORT_READ));
        self::assertTrue(App::authorization_service()->can($reporter, $this->clinicB, RolesAndCapabilities::REPORT_READ));
        self::assertSame(
            [$this->clinicA, $this->clinicB],
            array_column(StaffPortalShell::reports_eligible_clinics($reporter), 'id'),
            'two eligible Clinics are available to the existing Reports selector'
        );

        // Consultation durations are 600s + 1200s in A (average 900s) and
        // 300s in B. Waiting fields deliberately differ; an unfinished consult
        // has no completed timestamp and must not enter the sample.
        $patientA1 = $this->seedVisit($this->clinicA, $this->locationA, $this->clinicianA, '10:00:00', '10:10:00');
        $patientA2 = $this->seedVisit($this->clinicA, $this->locationA, $this->clinicianA, '11:00:00', '11:20:00');
        $unfinished = $this->seedVisit($this->clinicA, $this->locationA, $this->clinicianA, '12:00:00', null);
        // UTC timestamps on March 15 are still March 14 in this Location; report
        // day is the already-recorded Visit::visit_date, not one Clinic timezone.
        $patientB = $this->seedVisit($this->clinicB, $this->locationB, $this->clinicianB, '06:30:00', '06:35:00', '2026-03-15');

        wp_set_current_user($reporter);
        $range = ['from' => self::DATE, 'to' => self::DATE];

        $noClinic = $this->dispatch($range, null);
        self::assertSame(400, $noClinic->get_status(), 'N eligible Clinics require an explicit selector; there is no first-Clinic fallback');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($noClinic));

        $clinicA = $this->dispatch($range, (string) $this->clinicA);
        $dataA = $this->assertDurationAggregate($clinicA, 'clinic', 2, 900, [$patientA1, $patientA2, $unfinished, $patientB]);
        self::assertSame(900, $dataA['rows'][0]['avg_duration_sec'], 'Clinic A averages consultation start → completion, not waiting time');

        $clinicB = $this->dispatch($range, (string) $this->clinicB);
        $dataB = $this->assertDurationAggregate($clinicB, 'clinic', 1, 300, [$patientA1, $patientA2, $unfinished, $patientB]);
        self::assertSame(300, $dataB['rows'][0]['avg_duration_sec'], 'Clinic B is distinct and not blended with Clinic A');

        // A previously valid numeric Clinic id remains only a selector: the
        // existing REST boundary rechecks membership and authority each time.
        App::membership_service()->suspend_membership($membershipB);
        $staleClinic = $this->dispatch($range, (string) $this->clinicB);
        self::assertSame(403, $staleClinic->get_status());
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($staleClinic));
        self::assertSame(200, $this->dispatch($range, (string) $this->clinicA)->get_status(), 'the still-authorized Clinic remains available');
    }

    /** Existing backend control: own-vs-Clinic scope is derived from the actor, not the selector. */
    public function testControlVisitDurationScopeRemainsServerDerived(): void
    {
        $doctor = $this->makeUser(RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($doctor, $this->clinicA, 'cpms_doctor');
        cpms_test_seed_membership($doctor, $this->clinicB, 'cpms_doctor');
        $ownClinician = $this->insertClinician($this->clinicA, $doctor, 'Visit Duration Own Doctor');
        $otherPatient = $this->seedVisit($this->clinicA, $this->locationA, $this->clinicianA, '10:00:00', '10:10:00');
        $ownPatient = $this->seedVisit($this->clinicA, $this->locationA, $ownClinician, '11:00:00', '11:02:00');
        $otherClinicPatient = $this->seedVisit($this->clinicB, $this->locationB, $this->clinicianB, '09:00:00', '09:05:00');

        self::assertTrue(get_userdata($doctor)->has_cap(RolesAndCapabilities::REPORT_READ));
        self::assertTrue(App::authorization_service()->can($doctor, $this->clinicA, RolesAndCapabilities::REPORT_READ));
        self::assertTrue(App::authorization_service()->can($doctor, $this->clinicB, RolesAndCapabilities::REPORT_READ));

        wp_set_current_user($doctor);
        $range = ['from' => self::DATE, 'to' => self::DATE];
        $ownA = $this->assertDurationAggregate(
            $this->dispatch($range, (string) $this->clinicA),
            'own',
            1,
            120,
            [$otherPatient, $ownPatient, $otherClinicPatient]
        );
        self::assertSame(120, $ownA['rows'][0]['avg_duration_sec']);

        $ownBResponse = $this->dispatch($range, (string) $this->clinicB);
        self::assertSame(200, $ownBResponse->get_status(), $this->errorCode($ownBResponse));
        $ownB = $this->payload($ownBResponse);
        self::assertSame('own', $ownB['scope'] ?? null, 'the Clinic selector cannot widen the linked doctor to Clinic scope');
        self::assertSame(self::DATE, $ownB['from'] ?? null);
        self::assertSame(self::DATE, $ownB['to'] ?? null);
        self::assertSame(0, $ownB['summary']['visits'] ?? null);
        self::assertSame(0, $ownB['summary']['avg_sec'] ?? null, 'empty backend aggregate is not itself a UI average');
        self::assertSame([], $ownB['rows'] ?? null);

        $manager = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($manager, $this->clinicA, 'cpms_manager');
        cpms_test_seed_membership($manager, $this->clinicB, 'cpms_manager');
        wp_set_current_user($manager);
        $clinicWide = $this->assertDurationAggregate(
            $this->dispatch($range, (string) $this->clinicA),
            'clinic',
            2,
            360,
            [$otherPatient, $ownPatient, $otherClinicPatient]
        );
        self::assertSame(360, $clinicWide['rows'][0]['avg_duration_sec'], 'the unlinked manager receives Clinic A aggregate scope');
    }

    /**
     * Slice 2 product assertion: the canonical Staff Reports module reuses its
     * existing Clinic/date selection for an aggregate-only Visit Duration action.
     * All Slice 1 selector/date preconditions remain enforced.
     */
    public function testExistingStaffReportsPathExposesAggregateOnlyVisitDurationActionAndResult(): void
    {
        $reporter = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($reporter, $this->clinicA, 'cpms_manager');
        cpms_test_seed_membership($reporter, $this->clinicB, 'cpms_manager');
        self::assertTrue(StaffPortalShell::module_eligible(self::MODULE, $reporter), 'precondition: existing cpms_report_read Staff Reports module is eligible');
        self::assertSame(
            [$this->clinicA, $this->clinicB],
            array_column(StaffPortalShell::reports_eligible_clinics($reporter), 'id')
        );

        $html = $this->renderReportsModule($reporter);
        self::assertStringContainsString('data-cpms-portal="staff"', $html, 'precondition: canonical Staff Portal document rendered');
        self::assertSame(
            1,
            preg_match('/<main\b(?=[^>]*data-role="reports-root")[^>]*>.*?<\/main>/s', $html, $rootMatch),
            'precondition: real Reports module root reached through template_include'
        );
        $root = $rootMatch[0];
        self::assertStringContainsString('data-cpms-staff-module="reports"', $root);
        self::assertSame(1, preg_match('/<form\b[^>]*data-role="reports-avg-waiting-form"[^>]*>/', $root), 'Slice 1 Reports surface remains mounted');

        // Reuse Slice 1 Clinic choice and the one required, non-defaulted date.
        self::assertSame(
            1,
            preg_match('/<select\b[^>]*data-role="reports-clinic-select"[^>]*>(.*?)<\/select>/s', $root, $clinicSelect),
            'the existing Clinic selector is reused for N eligible Clinics'
        );
        self::assertMatchesRegularExpression('/\brequired\b/', $clinicSelect[0]);
        self::assertDoesNotMatchRegularExpression('/<option\b[^>]*\bselected\b/', $clinicSelect[1], 'no first-Clinic fallback');
        self::assertSame(1, preg_match_all('/<select\b/', $root), 'no additional Location selector');
        self::assertSame(1, preg_match_all('/<input\b[^>]*data-role="reports-date-input"[^>]*>/', $root, $dateInputs));
        self::assertMatchesRegularExpression('/\btype="date"/', $dateInputs[0][0]);
        self::assertMatchesRegularExpression('/\brequired\b/', $dateInputs[0][0]);
        self::assertDoesNotMatchRegularExpression('/\bvalue="/', $dateInputs[0][0], 'one explicit date, never an implicit today');

        // The Visit Duration action is part of this same Reports form and does
        // not introduce another date or Clinic selector.
        self::assertSame(
            1,
            preg_match_all('/<(?:button|input)\b[^>]*data-role="reports-visit-duration-action"[^>]*>/i', $root),
            'Phase 14 Slice 2: the existing Staff Reports module exposes a Visit Duration action'
        );
        self::assertSame(1, preg_match('/<button\b(?=[^>]*data-role="reports-visit-duration-action")[^>]*>/i', $root, $durationAction));
        self::assertMatchesRegularExpression('/\btype="button"/', $durationAction[0], 'Visit Duration is an independent action, not the Average Waiting submit action');

        // The product contract reuses the existing GET route, displays only
        // aggregate values, and identifies the stored-date consultation metric.
        $normalizedHtml = str_replace('\\/', '/', $html);
        self::assertStringContainsString('/reports/visit_duration', $normalizedHtml, 'reuse GET /clinic/v1/reports/visit_duration');
        self::assertSame(
            1,
            preg_match('/<section\b(?=[^>]*data-role="reports-visit-duration-result")[^>]*>(.*?)<\/section>/s', $root, $durationResult),
            'Visit Duration has an aggregate result region in the same Reports module'
        );
        self::assertSame(1, preg_match('/<dd\b[^>]*data-role="reports-visit-duration-count"/', $durationResult[1]), 'show aggregate visit/sample count');
        self::assertSame(1, preg_match('/<dd\b[^>]*data-role="reports-visit-duration-average"/', $durationResult[1]), 'show average consultation duration');
        self::assertSame(2, preg_match_all('/<dd\b/', $durationResult[1]), 'count and average are the only displayed values');
        self::assertSame(1, preg_match('/data-role="reports-visit-duration-average">—<\/dd>/', $durationResult[1]), 'zero samples display a dash rather than a zero-second average');
        self::assertSame(1, preg_match('/<p\b(?=[^>]*data-role="reports-visit-duration-description")[^>]*>(.*?)<\/p>/s', $durationResult[1], $durationDescription));
        foreach (['consultation_started_at', 'consultation_completed_at', 'visit_date'] as $metricField) {
            self::assertStringContainsString($metricField, $durationDescription[1], 'describe the consultation metric and stored visit date explicitly');
        }
        self::assertDoesNotMatchRegularExpression('/<(?:table|tr|canvas)\b|patient_(?:name|id)|\bmrn\b|diagnosis|clinical_note|reports-location/i', $root, 'no PHI rows, drilldown, Location selector or chart');
        foreach (['/reports/visit_duration/export', '/reports/visit_duration/print', '/reports/visits'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $normalizedHtml, 'Slice 2 excludes ' . $forbidden);
        }
    }

    /** @param array<string, mixed> $params */
    private function dispatch(array $params, ?string $clinicHeader): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', self::ROUTE);
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
    private function assertDurationAggregate(
        WP_REST_Response $response,
        string $scope,
        int $samples,
        int $averageSeconds,
        array $syntheticPatientMarkers
    ): array {
        self::assertSame(200, $response->get_status(), 'existing visit_duration backend control — ' . $this->errorCode($response));
        $data = $this->payload($response);
        self::assertSame('visit_duration', $data['type'] ?? null);
        self::assertSame($scope, $data['scope'] ?? null, 'scope is derived by the existing server boundary');
        self::assertSame(self::DATE, $data['from'] ?? null);
        self::assertSame(self::DATE, $data['to'] ?? null, 'the same explicit YYYY-MM-DD is sent as from and to');
        self::assertSame(['visits', 'avg_sec', 'avg_min'], array_keys($data['summary'] ?? []));
        self::assertSame($samples, $data['summary']['visits'] ?? null);
        self::assertSame($averageSeconds, $data['summary']['avg_sec'] ?? null);
        self::assertSame((int) round($averageSeconds / 60), $data['summary']['avg_min'] ?? null);
        self::assertCount(1, $data['rows'] ?? [], 'one stored visit_date produces one aggregate row');
        $row = $data['rows'][0];
        self::assertSame(
            ['date', 'date_jalali', 'visits', 'avg_duration_sec', 'avg_duration_min'],
            array_keys($row),
            'aggregate projection only — no patient identifiers or clinical detail'
        );
        self::assertSame(self::DATE, $row['date']);
        self::assertSame($samples, $row['visits']);
        self::assertSame($averageSeconds, $row['avg_duration_sec']);
        self::assertSame((int) round($averageSeconds / 60), $row['avg_duration_min']);

        $json = (string) wp_json_encode($data);
        foreach ($syntheticPatientMarkers as $marker) {
            self::assertStringNotContainsString($marker['name'], $json, 'no synthetic patient name in the report projection');
            self::assertStringNotContainsString($marker['mrn'], $json, 'no synthetic MRN in the report projection');
        }

        return $data;
    }

    /** @return array{name:string,mrn:string} */
    private function seedVisit(int $clinicId, int $locationId, int $clinicianId, string $startedTime, ?string $completedTime, string $timestampDate = self::DATE): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $marker = 'P14VD-' . bin2hex(random_bytes(5));
        $mrn = 'MRN-' . $marker;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id' => $clinicId,
            'mrn' => $mrn,
            'first_name' => $marker,
            'last_name' => 'Synthetic',
            'mobile' => '0919' . random_int(1000000, 9999999),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'patient fixture');
        $patientId = (int) $wpdb->insert_id;
        $startedAt = $timestampDate . ' ' . $startedTime . '.000';
        $completedAt = $completedTime === null ? null : $timestampDate . ' ' . $completedTime . '.000';
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_visits', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'patient_id' => $patientId,
            'source' => 'walk_in',
            'status' => $completedTime === null ? 'in_consultation' : 'consultation_completed',
            'visit_date' => self::DATE,
            'check_in_at' => $timestampDate . ' 05:00:00.000',
            'waiting_since' => $timestampDate . ' 05:00:00.000',
            'called_at' => $timestampDate . ' 06:15:00.000',
            'consultation_started_at' => $startedAt,
            'consultation_completed_at' => $completedAt,
            'active' => $completedTime === null ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'visit fixture');

        return ['name' => $marker, 'mrn' => $mrn];
    }

    private function insertClinic(int $organizationId, string $name, string $locationTimezone = 'Asia/Tehran'): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $slug = 'p14vd-' . bin2hex(random_bytes(5));
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinics', [
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => $slug,
            'timezone' => 'Asia/Tehran',
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'Clinic B fixture');
        $clinicId = (int) $wpdb->insert_id;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_locations', [
            'clinic_id' => $clinicId,
            'name' => $name . ' Location',
            'slug' => $slug . '-location',
            'timezone' => $locationTimezone,
            'is_primary' => 1,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'Location fixture');

        return $clinicId;
    }

    private function primaryLocation(int $clinicId): int
    {
        $id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1 LIMIT 1',
            [$clinicId]
        );
        self::assertGreaterThan(0, $id, 'fixture: primary Location for Clinic ' . $clinicId);

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

    private function makeUser(string $role): int
    {
        $suffix = bin2hex(random_bytes(5));
        $id = (int) wp_create_user('p14vd_' . $suffix, 'test-password-123', 'p14vd_' . $suffix . '@test.local');
        self::assertGreaterThan(0, $id, 'user fixture');
        get_userdata($id)->set_role($role);

        return $id;
    }

    /** Render the real canonical Staff Portal Reports module via template_include. */
    private function renderReportsModule(int $userId): string
    {
        wp_set_current_user($userId);
        $url = StaffPortalShell::portal_url();
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?? '');
        $previousGet = $_GET;
        try {
            $target = $path . ($query !== '' ? '?' . $query : '');
            $target .= ($query !== '' ? '&' : '?') . StaffPortalShell::MODULE_PARAM . '=' . self::MODULE;
            $this->go_to($target);
            $_GET[StaffPortalShell::MODULE_PARAM] = self::MODULE;
            $baseline = get_stylesheet_directory() . '/page.php';
            if (!is_readable($baseline)) {
                $baseline = get_stylesheet_directory() . '/index.php';
            }
            $template = (string) apply_filters('template_include', $baseline);
            self::assertNotSame($baseline, $template, 'template_include must use the plugin-owned Staff Portal shell');
            ob_start();
            include $template;

            return (string) ob_get_clean();
        } finally {
            $_GET = $previousGet;
        }
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Response $response): array
    {
        $body = $response->get_data();
        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return is_array($body) ? $body : [];
    }

    private function errorCode(WP_REST_Response $response): string
    {
        $body = $response->get_data();

        return is_array($body) ? (string) ($body['code'] ?? '') : '';
    }
}
