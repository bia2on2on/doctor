<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * TEST-ONLY RED: My Visits on existing C5/C6 and the independent CPMS shell.
 * link_id is ONLY a selector. No new route, migration, capability or write API.
 * The link table has no active flag: eligibility is the existing Profile join
 * (current user, active Patient, matching persisted link/Patient Clinic).
 * UI hooks below are a bounded presentation contract, not a shell redesign.
 * Browser interaction/visual acceptance is not claimed by PHP rendering tests.
 */
final class Phase9Slice5PatientVisitsRedTest extends WP_UnitTestCase
{
    private int $user;
    private int $foreign;
    private int $empty;
    private array $a;
    private array $b;
    private array $other;
    private string $tag;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        $this->tag = bin2hex(random_bytes(5));
        $this->today = gmdate('Y-m-d');
        $this->user = $this->user('caller');
        $this->foreign = $this->user('foreign');
        $this->empty = $this->user('empty');
        $now = App::db()->nowUtcSql();
        $org = $this->insert('cpms_organizations', [
            'name' => 'Visits ' . $this->tag, 'slug' => 'visits-' . $this->tag,
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $clinics = [];
        foreach (['Alpha', 'Beta'] as $name) {
            $clinics[] = $this->insert('cpms_clinics', [
                'organization_id' => $org, 'name' => $name . $this->tag,
                'slug' => strtolower($name) . $this->tag, 'timezone' => 'Asia/Tehran',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        self::assertNotSame($clinics[0], $clinics[1]);
        self::assertGreaterThan(1, $clinics[0]);
        $this->a = $this->record($clinics[0], $this->user, 'A', 1);
        $this->b = $this->record($clinics[1], $this->user, 'B', 0);
        $this->other = $this->record($clinics[1], $this->foreign, 'Foreign', 1);

        // Material controls before ANY intended RED: persisted topology and the
        // already-GREEN Profile contract prove the two eligible links really exist.
        $records = $this->get('/patient/my-records')->get_data()['data'];
        self::assertSame([$this->a['link'], $this->b['link']], array_column($records, 'link_id'));
        $selected = $this->get('/patient/me', ['link_id' => $this->b['link']]);
        self::assertSame(200, $selected->get_status());
        self::assertSame($this->b['patient'], $selected->get_data()['data']['id']);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        parent::tearDown();
    }

    public static function endpoints(): array
    {
        return ['C5 list' => [false], 'C6 detail' => [true]];
    }

    /** @dataProvider endpoints */
    public function testMultipleRecordsRequireSelectionEvenWithForgedAuthority(bool $detail): void
    {
        $path = $detail ? '/visits/' . $this->a['visit'] : '/visits';
        $response = $this->get($path, [
            'clinic_id' => $this->a['clinic'], 'patient_id' => $this->a['patient'],
            'organization_id' => 1, 'role' => 'cpms_manager',
        ]);
        $this->error($response, 422, 'CLINIC_SELECTION_REQUIRED');
    }

    public function testSelectedNonPrimaryListRetainsDateWindowAndNeverMixesRecords(): void
    {
        $this->visit($this->b, '2000-01-01');
        $response = $this->get('/visits', ['link_id' => $this->b['link'],
            'from' => $this->today, 'to' => $this->today,
            'clinic_id' => $this->a['clinic'], 'patient_id' => $this->a['patient'],
        ]);
        self::assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        self::assertSame($this->today, $data['from']);
        self::assertSame($this->today, $data['to']);
        self::assertSame([$this->b['visit']], array_column($data['visits'], 'id'), 'link_id must select B, never primary A or foreign B-Clinic patient.');
    }

    public function testSelectedNonPrimaryDetailUsesExistingPatientVisibleQueries(): void
    {
        $queries = [];
        $capture = static function (string $sql) use (&$queries): string {
            $queries[] = $sql;
            return $sql;
        };
        add_filter('query', $capture);
        try {
            $response = $this->get('/visits/' . $this->b['visit'], ['link_id' => $this->b['link']]);
        } finally {
            remove_filter('query', $capture);
        }
        self::assertSame(200, $response->get_status(), 'Selected B detail must not authorize against primary A.');
        $data = $response->get_data()['data'];
        self::assertSame($this->b['visit'], $data['visit']['id']);
        self::assertSame(['Visible B'], array_column($data['notes'], 'content_text'));
        self::assertSame(['Advice B'], array_column($data['recommendations'], 'text'));
        self::assertStringNotContainsString('PRIVATE', (string) wp_json_encode($data));
        $noteQueries = array_filter($queries, static fn (string $q): bool => stripos($q, 'SELECT') === 0 && str_contains($q, 'cpms_clinical_notes'));
        self::assertNotEmpty($noteQueries, 'Must reach the real repository, not a test-side projection.');
        foreach ($noteQueries as $sql) {
            self::assertStringContainsString('patient_visible', $sql, 'Visibility must remain a query-level predicate.');
            self::assertStringContainsString('is_archived', $sql);
        }
        $recQueries = array_filter($queries, static fn (string $q): bool => stripos($q, 'SELECT') === 0 && str_contains($q, 'cpms_recommendations'));
        self::assertNotEmpty($recQueries);
        foreach ($recQueries as $sql) {
            self::assertMatchesRegularExpression('/is_patient_visible\s*=\s*1/', $sql);
        }
    }

    public function testSelectedRecordCannotReadOtherOwnedOrForeignVisitAndAuditsDenial(): void
    {
        foreach ([$this->a['visit'], $this->other['visit']] as $visit) {
            $before = $this->auditCount($visit);
            $response = $this->get('/visits/' . $visit, ['link_id' => $this->b['link']]);
            $this->error($response, 404, 'CLINIC_NOT_FOUND');
            self::assertGreaterThan($before, $this->auditCount($visit), 'Keep existing forbidden-visit audit behavior.');
        }
    }

    /** @dataProvider endpoints */
    public function testInvalidSelectorsAreNonEnumeratingAndNeverFallBack(bool $detail): void
    {
        $inactive = $this->record($this->b['clinic'], $this->user, 'Archived', 0, 'archived');
        $mismatch = $this->record($this->b['clinic'], $this->user, 'Mismatch', 0);
        global $wpdb;
        self::assertSame(1, $wpdb->update($wpdb->prefix . 'cpms_patient_user_links',
            ['clinic_id' => $this->a['clinic']], ['id' => $mismatch['link']]));
        $path = $detail ? '/visits/' . $this->a['visit'] : '/visits';
        $canonical = null;
        foreach ([$this->other['link'], $inactive['link'], $mismatch['link'], 2147483647] as $link) {
            $response = $this->get($path, ['link_id' => $link]);
            $this->error($response, 404, 'CLINIC_NOT_FOUND');
            $error = $response->get_data();
            $fingerprint = [$error['code'], $error['message'], $error['data']['status']];
            $canonical ??= $fingerprint;
            self::assertSame($canonical, $fingerprint, 'Foreign/inactive/mismatched/missing selectors must be indistinguishable.');
        }
    }

    public function testZeroAndSingleRecordControlsKeepExistingBoundsAndAuthentication(): void
    {
        $before = $this->counts();
        foreach (['/visits', '/visits/' . $this->a['visit']] as $path) {
            $this->error($this->get($path, [], $this->empty), 404, 'CLINIC_NOT_FOUND');
            $this->error($this->get($path, [], $this->user, false), 403, 'CLINIC_INVALID_NONCE');
            $this->error($this->get($path, [], 0, false), 403, 'CLINIC_INVALID_NONCE');
        }
        self::assertSame($before, $this->counts(), 'Read/denial must not create Patients or links.');
        // Foreign user owns exactly one B-Clinic record. Auto-resolution is legal.
        for ($i = 0; $i < 101; $i++) {
            $this->visit($this->other, $this->today);
        }
        $this->visit($this->other, '2000-01-01');
        $list = $this->get('/visits', [], $this->foreign);
        self::assertSame(200, $list->get_status());
        $data = $list->get_data()['data'];
        self::assertCount(100, $data['visits']);
        self::assertSame(gmdate('Y-m-d', strtotime('-1 year')), $data['from']);
        self::assertSame(gmdate('Y-m-d', strtotime('+1 day')), $data['to']);
        self::assertSame([$this->today], array_values(array_unique(array_column($data['visits'], 'visit_date'))));
        $detail = $this->get('/visits/' . $this->other['visit'], [], $this->foreign);
        self::assertSame(200, $detail->get_status());
        self::assertSame(['Visible Foreign'], array_column($detail->get_data()['data']['notes'], 'content_text'));
    }

    public static function cardinalities(): array
    {
        return ['zero records' => [0], 'one record' => [1], 'multiple records' => [2]];
    }

    /** @dataProvider cardinalities */
    public function testMyVisitsLivesInApprovedShellWithSafeRecordState(int $count): void
    {
        $html = $this->render($count === 0 ? $this->empty : ($count === 1 ? $this->foreign : $this->user));
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);
        foreach (['patient-nav', 'appointments-section', 'notifications-section', 'profile-section'] as $role) {
            self::assertSame(1, $xpath->query('//*[@data-role="' . $role . '"]')->length, 'Existing shell guard: ' . $role);
        }
        self::assertSame(1, $xpath->query('//nav//*[@data-role="nav-visits"]')->length, 'RED UI: one My Visits navigation item in existing shell.');
        $sections = $xpath->query('//section[@data-role="visits-section"]');
        self::assertSame(1, $sections->length, 'One read-only Visits section only.');
        $section = $sections->item(0);
        self::assertSame(0, $xpath->query('.//form|.//*[@contenteditable="true"]', $section)->length, 'No visit mutations.');
        foreach (['nav-prescriptions', 'nav-files', 'visits-edit', 'visits-delete'] as $role) {
            self::assertSame(0, $xpath->query('//*[@data-role="' . $role . '"]')->length);
        }
        if ($count === 0) {
            self::assertSame(1, $xpath->query('.//*[@data-role="visits-empty-state"]', $section)->length);
            self::assertSame(0, $xpath->query('.//*[@data-role="visit-open"]', $section)->length);
        } elseif ($count === 1) {
            $context = $xpath->query('.//*[@data-role="visits-context"]', $section);
            self::assertSame(1, $context->length);
            self::assertStringContainsString('Beta' . $this->tag, $context->item(0)->textContent);
            self::assertStringContainsString('Foreign', $context->item(0)->textContent);
            self::assertSame(1, $xpath->query('.//*[@data-role="visit-open"][@data-visit-id="' . $this->other['visit'] . '"]', $section)->length, 'Single record auto-resolves to a visit list with a detail affordance.');
        } else {
            $select = $xpath->query('.//select[@data-role="visits-record-select"]', $section);
            self::assertSame(1, $select->length);
            $values = [];
            foreach ($xpath->query('./option', $select->item(0)) as $option) {
                $value = $option->getAttribute('value');
                if ($value !== '') {
                    $values[] = (int) $value;
                    self::assertFalse($option->hasAttribute('selected'), 'Never preselect primary/first.');
                }
            }
            self::assertSame([$this->a['link'], $this->b['link']], $values);
            self::assertSame('', $xpath->query('./option', $select->item(0))->item(0)->getAttribute('value'), 'First option must be an empty explicit-choice prompt.');
            self::assertSame(0, $xpath->query('.//*[@data-role="visit-open"]', $section)->length, 'No Clinic history before explicit choice.');
        }
        $sectionHtml = $dom->saveHTML($section);
        foreach (['clinic_id', 'patient_id', 'organization_id', 'created_by_wp_user_id', 'change_reason', 'cancel_reason', 'skip_reason'] as $key) {
            self::assertStringNotContainsString($key, $sectionHtml, 'No authority inputs or unresolved internal display metadata.');
        }
        self::assertStringNotContainsString('PRIVATE', $sectionHtml);
        $configs = $xpath->query('//script[@class="cpms-patient-portal__config"]');
        self::assertSame(1, $configs->length);
        $config = json_decode($configs->item(0)->textContent, true);
        self::assertNotFalse(wp_verify_nonce($config['nonce'], 'wp_rest'));
        self::assertSame('/visits', $config['visits_path'] ?? null, 'Reuse C5, relative to existing rest_root.');
        self::assertSame('/visits/{id}', $config['visit_detail_path'] ?? null, 'Reuse C6, not a parallel endpoint.');
        self::assertSame(1, $xpath->query('.//*[@data-role="visits-detail"]', $section)->length);
        foreach (['clinic_id', 'patient_id', 'organization_id', 'role'] as $key) {
            self::assertArrayNotHasKey($key, $config);
        }
    }

    private function record(int $clinic, int $user, string $name, int $primary, string $status = 'active'): array
    {
        $now = App::db()->nowUtcSql();
        $mobile = '09' . str_pad((string) (abs(crc32($this->tag . $name)) % 1000000000), 9, '0', STR_PAD_LEFT);
        $patient = $this->insert('cpms_patients', [
            'clinic_id' => $clinic, 'mrn' => $this->tag . $name, 'first_name' => $name,
            'last_name' => 'Visits', 'mobile' => $mobile, 'status' => $status,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $link = $this->insert('cpms_patient_user_links', [
            'clinic_id' => $clinic, 'patient_id' => $patient, 'wp_user_id' => $user,
            'mobile_at_link' => $mobile, 'is_primary' => $primary, 'linked_at' => $now,
        ]);
        $clinician = $this->insert('cpms_clinicians', [
            'clinic_id' => $clinic, 'full_name' => 'Doctor ' . $name,
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $location = $this->insert('cpms_locations', [
            'clinic_id' => $clinic, 'name' => 'Location ' . $name, 'slug' => strtolower($name),
            'timezone' => 'Asia/Tehran', 'is_primary' => 1, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $record = compact('clinic', 'patient', 'link', 'clinician', 'location');
        $record['visit'] = $this->visit($record, $this->today);
        foreach ([['patient_visible', 'Visible '], ['doctor_private', 'PRIVATE ']] as [$visibility, $prefix]) {
            $this->insert('cpms_clinical_notes', [
                'clinic_id' => $clinic, 'patient_id' => $patient, 'clinician_id' => $clinician,
                'visit_id' => $record['visit'], 'visibility' => $visibility, 'category' => 'clinical_note',
                'content_text' => $prefix . $name, 'created_by_wp_user_id' => $user,
                'change_reason' => 'INTERNAL-CORRECTION', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([1, 0] as $visible) {
            $this->insert('cpms_recommendations', [
                'clinic_id' => $clinic, 'patient_id' => $patient, 'clinician_id' => $clinician,
                'visit_id' => $record['visit'], 'is_patient_visible' => $visible,
                'text' => ($visible ? 'Advice ' : 'PRIVATE ') . $name, 'created_at' => $now,
            ]);
        }
        return $record;
    }

    private function visit(array $record, string $date): int
    {
        $now = App::db()->nowUtcSql();
        return $this->insert('cpms_visits', [
            'clinic_id' => $record['clinic'], 'patient_id' => $record['patient'],
            'clinician_id' => $record['clinician'], 'location_id' => $record['location'], 'visit_date' => $date,
            'source' => 'walk_in', 'status' => 'checked_out', 'active' => 0,
            'check_in_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function insert(string $table, array $data): int
    {
        global $wpdb;
        self::assertSame(1, $wpdb->insert($wpdb->prefix . $table, $data), 'Material fixture: ' . $table . ' ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        self::assertSame($id, (int) App::db()->fetchValue('SELECT id FROM ' . App::db()->table($table) . ' WHERE id = %d', [$id]));
        return $id;
    }

    private function user(string $suffix): int
    {
        $id = wp_create_user('p9s5-' . $this->tag . $suffix, 'test-pass-only');
        self::assertIsInt($id);
        get_userdata($id)->set_role('cpms_patient');
        return $id;
    }

    private function get(string $path, array $params = [], ?int $user = null, bool $nonce = true): WP_REST_Response
    {
        wp_set_current_user($user ?? $this->user);
        $request = new WP_REST_Request('GET', '/clinic/v1' . $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($nonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        return rest_do_request($request);
    }

    private function error(WP_REST_Response $response, int $status, string $code): void
    {
        self::assertSame($status, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $data = $response->get_data();
        self::assertSame($code, $data['code']);
        self::assertSame($status, $data['data']['status']);
        self::assertNotEmpty($data['message']);
    }

    private function counts(): array
    {
        return array_map(static fn (string $table): int => (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($table)), ['cpms_patients', 'cpms_patient_user_links']);
    }

    private function auditCount(int $visit): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s AND resource_id = %d', ['FORBIDDEN_ACCESS_ATTEMPT', 'visit', $visit]);
    }

    private function render(int $user): string
    {
        wp_set_current_user($user);
        $url = PatientPortalShell::portal_url();
        self::assertStringNotContainsString('/wp-admin/', $url);
        $this->go_to($url);
        $template = apply_filters('template_include', get_stylesheet_directory() . '/page.php');
        self::assertSame(realpath(dirname(__DIR__, 2) . '/templates/patient-portal-shell.php'), realpath($template));
        ob_start();
        include $template;
        return (string) ob_get_clean();
    }
}
