<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * TEST-ONLY RED: My Prescriptions on existing C7 GET /prescriptions and the CPMS shell.
 * link_id is ONLY a selector, never authority. No new route, migration, or write API.
 * Patient eligibility joins current WP user, active Patient, and persisted Clinic.
 * UI hooks below define a bounded read-only presentation contract in the approved shell.
 */
final class Phase9Slice6PatientPrescriptionsRedTest extends WP_UnitTestCase
{
    private int $user;
    private int $foreign;
    private int $empty;
    private array $a;
    private array $b;
    private array $other;
    private string $tag;
    private string $today;
    private string $now;

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
        $this->now = App::db()->nowUtcSql();
        $this->user = $this->user('caller');
        $this->foreign = $this->user('foreign');
        $this->empty = $this->user('empty');

        $org = $this->insert('cpms_organizations', [
            'name' => 'RxOrg ' . $this->tag, 'slug' => 'rx-org-' . $this->tag,
            'status' => 'active', 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $clinics = [];
        foreach (['Alpha', 'Beta'] as $name) {
            $clinics[] = $this->insert('cpms_clinics', [
                'organization_id' => $org, 'name' => $name . $this->tag,
                'slug' => strtolower($name) . $this->tag, 'timezone' => 'Asia/Tehran',
                'created_at' => $this->now, 'updated_at' => $this->now,
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

    public function testMultipleRecordsRequireSelectionEvenWithForgedAuthority(): void
    {
        $response = $this->get('/prescriptions', [
            'clinic_id' => $this->a['clinic'], 'patient_id' => $this->a['patient'],
            'organization_id' => 1, 'role' => 'cpms_manager',
        ]);
        $this->error($response, 422, 'CLINIC_SELECTION_REQUIRED');
    }

    public function testSelectedNonPrimaryPrescriptionsNeverMixRecords(): void
    {
        $response = $this->get('/prescriptions', [
            'link_id' => $this->b['link'],
            'clinic_id' => $this->a['clinic'], 'patient_id' => $this->a['patient'],
        ]);
        self::assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        $rxNumbers = array_column($data['prescriptions'], 'prescription_number');
        self::assertContains(
            $this->b['rx']['prescription_number'],
            $rxNumbers,
            'link_id must select B prescriptions, never primary A or foreign B-Clinic patient.'
        );
        self::assertNotContains($this->a['rx']['prescription_number'], $rxNumbers);
        self::assertNotContains($this->other['rx']['prescription_number'], $rxNumbers);
    }

    public function testSelectedPrescriptionsUsesExistingPatientVisibleQueriesAndExcludesDrafts(): void
    {
        $queries = [];
        $capture = static function (string $sql) use (&$queries): string {
            $queries[] = $sql;
            return $sql;
        };
        add_filter('query', $capture);
        try {
            $response = $this->get('/prescriptions', ['link_id' => $this->b['link']]);
        } finally {
            remove_filter('query', $capture);
        }
        self::assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        $rxNumbers = array_column($data['prescriptions'], 'prescription_number');
        self::assertContains($this->b['rx']['prescription_number'], $rxNumbers);
        self::assertNotContains(
            $this->b['draft_rx']['prescription_number'],
            $rxNumbers,
            'Draft prescriptions must be excluded from patient responses.'
        );
        self::assertNotContains(
            $this->b['hidden_rx']['prescription_number'],
            $rxNumbers,
            'is_patient_visible = 0 prescriptions must be excluded from patient responses.'
        );
        self::assertStringNotContainsString('PRIVATE', (string) wp_json_encode($data));

        $rxQueries = array_filter(
            $queries,
            static fn (string $q): bool => stripos($q, 'SELECT') === 0 && str_contains($q, 'cpms_prescriptions')
        );
        self::assertNotEmpty($rxQueries, 'Must reach the real prescription repository, not a mock.');
        foreach ($rxQueries as $sql) {
            self::assertMatchesRegularExpression(
                '/is_patient_visible\s*=\s*(1|%d)/',
                $sql,
                'Visibility must remain a query-level predicate.'
            );
        }

        // Verify item detail fields in returned structure:
        $firstRx = $data['prescriptions'][0];
        self::assertNotEmpty($firstRx['items']);
        $item = $firstRx['items'][0];
        self::assertSame('Amoxicillin B', $item['generic_name']);
        self::assertSame('500mg', $item['strength']);
        self::assertSame('capsule', $item['form']);
        self::assertSame('1 cap', $item['dose']);
        self::assertSame('TDS', $item['frequency']);
        self::assertSame('oral', $item['route']);
        self::assertSame(7, $item['duration_days']);
        self::assertSame('Take with water B', $item['instructions']);
    }

    public function testInvalidSelectorsAreNonEnumeratingAndNeverFallBack(): void
    {
        $inactive = $this->record($this->b['clinic'], $this->user, 'Archived', 0, 'archived');
        $mismatch = $this->record($this->b['clinic'], $this->user, 'Mismatch', 0);
        global $wpdb;
        self::assertSame(1, $wpdb->update(
            $wpdb->prefix . 'cpms_patient_user_links',
            ['clinic_id' => $this->a['clinic']],
            ['id' => $mismatch['link']]
        ));

        $canonical = null;
        foreach ([$this->other['link'], $inactive['link'], $mismatch['link'], 2147483647] as $link) {
            $response = $this->get('/prescriptions', ['link_id' => $link]);
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
        $this->error($this->get('/prescriptions', [], $this->empty), 404, 'CLINIC_NOT_FOUND');
        $this->error($this->get('/prescriptions', [], $this->user, false), 403, 'CLINIC_INVALID_NONCE');
        $this->error($this->get('/prescriptions', [], 0, false), 403, 'CLINIC_INVALID_NONCE');
        self::assertSame($before, $this->counts(), 'Read/denial must not create Patients, links, or prescriptions.');

        // Foreign user owns exactly one B-Clinic record. Auto-resolution is allowed without link_id:
        $response = $this->get('/prescriptions', [], $this->foreign);
        self::assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        $rxNumbers = array_column($data['prescriptions'], 'prescription_number');
        self::assertSame([$this->other['rx']['prescription_number']], $rxNumbers);
    }

    public function testPrescriptionDatesPreserveGregorianApiAndPairJalaliForDisplay(): void
    {
        $utc = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $this->now, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $this->now, new \DateTimeZone('UTC'));
        self::assertNotFalse($utc);
        $local = $utc->setTimezone(new \DateTimeZone('Asia/Tehran'));
        $expectedJalali = Jalali::formatYmd($local->format('Y-m-d'));

        $response = $this->get('/prescriptions', [], $this->foreign);
        self::assertSame(200, $response->get_status());
        $rxList = $response->get_data()['data']['prescriptions'];
        self::assertCount(1, $rxList);
        $rx = $rxList[0];

        // Raw Gregorian datetime field stays unchanged in API:
        self::assertSame($this->now, $rx['created_at'], 'C7 raw Gregorian field stays unchanged.');
        self::assertArrayHasKey('created_at_jalali', $rx, 'C7 response pairs created_at with created_at_jalali.');
        self::assertSame($expectedJalali, $rx['created_at_jalali']);

        self::assertSame(
            $this->now,
            App::db()->fetchValue(
                'SELECT created_at FROM ' . App::db()->table('cpms_prescriptions') . ' WHERE id = %d',
                [$this->other['rx']['id']]
            ),
            'Presentation formatting must not mutate stored datetimes.'
        );

        // Dom render check: paired Jalali date displayed on portal surface, raw Gregorian absent.
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $this->render($this->foreign));
        $xpath = new \DOMXPath($dom);
        $lists = $xpath->query('//*[@data-role="prescriptions-list"]');
        self::assertSame(1, $lists->length, 'Sole record renders prescriptions list.');
        $text = $lists->item(0)->textContent;
        self::assertStringContainsString($expectedJalali, $text, 'Sole-record prescription list displays paired Jalali date.');
        self::assertStringNotContainsString($utc->format('Y-m-d'), $text);
        self::assertDoesNotMatchRegularExpression('/\b[0-9]{4}-[0-9]{2}-[0-9]{2}\b/', $text);
    }

    public static function cardinalities(): array
    {
        return ['zero records' => [0], 'one record' => [1], 'multiple records' => [2]];
    }

    /** @dataProvider cardinalities */
    public function testMyPrescriptionsLivesInApprovedShellWithSafeRecordState(int $count): void
    {
        $html = $this->render($count === 0 ? $this->empty : ($count === 1 ? $this->foreign : $this->user));
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);

        // Existing shell guards: previous slices remain intact.
        foreach (['patient-nav', 'appointments-section', 'notifications-section', 'profile-section', 'visits-section'] as $role) {
            self::assertSame(1, $xpath->query('//*[@data-role="' . $role . '"]')->length, 'Existing shell guard: ' . $role);
        }

        self::assertSame(1, $xpath->query('//nav//*[@data-role="nav-prescriptions"]')->length, 'RED UI: one My Prescriptions navigation item in existing shell.');
        $sections = $xpath->query('//section[@data-role="prescriptions-section"]');
        self::assertSame(1, $sections->length, 'One read-only Prescriptions section only.');
        $section = $sections->item(0);

        self::assertSame(0, $xpath->query('.//form|.//*[@contenteditable="true"]', $section)->length, 'No prescription mutations.');
        foreach (['nav-files', 'prescriptions-edit', 'prescriptions-delete', 'prescriptions-refill'] as $role) {
            self::assertSame(0, $xpath->query('//*[@data-role="' . $role . '"]')->length);
        }

        if ($count === 0) {
            self::assertSame(1, $xpath->query('.//*[@data-role="prescriptions-empty-state"]', $section)->length);
            self::assertSame(0, $xpath->query('.//*[@data-role="prescription-item"]', $section)->length);
        } elseif ($count === 1) {
            $context = $xpath->query('.//*[@data-role="prescriptions-context"]', $section);
            self::assertSame(1, $context->length);
            self::assertStringContainsString('Beta' . $this->tag, $context->item(0)->textContent);
            self::assertStringContainsString('Foreign', $context->item(0)->textContent);
            self::assertSame(1, $xpath->query('.//*[@data-role="prescriptions-list"]', $section)->length);
            $items = $xpath->query('.//*[@data-role="prescription-item"]', $section);
            self::assertSame(1, $items->length, 'Single record auto-resolves to prescription list with item details.');
            self::assertStringContainsString($this->other['rx']['prescription_number'], $items->item(0)->textContent);
            self::assertStringContainsString('Amoxicillin Foreign', $items->item(0)->textContent);
        } else {
            $select = $xpath->query('.//select[@data-role="prescriptions-record-select"]', $section);
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
            self::assertSame(0, $xpath->query('.//*[@data-role="prescription-item"]', $section)->length, 'No Clinic prescriptions before explicit choice.');
        }

        $sectionHtml = $dom->saveHTML($section);
        foreach (['clinic_id', 'patient_id', 'organization_id', 'created_by_wp_user_id', 'void_reason', 'correction_of_prescription_id', 'is_patient_visible', 'drug_ref_id'] as $key) {
            self::assertStringNotContainsString($key, $sectionHtml, 'No authority inputs or unresolved internal display metadata: ' . $key);
        }
        self::assertStringNotContainsString('PRIVATE', $sectionHtml);

        $configs = $xpath->query('//script[@class="cpms-patient-portal__config"]');
        self::assertSame(1, $configs->length);
        $config = json_decode($configs->item(0)->textContent, true);
        self::assertNotFalse(wp_verify_nonce($config['nonce'], 'wp_rest'));
        self::assertSame('/prescriptions', $config['prescriptions_path'] ?? null, 'Reuse C7, relative to existing rest_root.');
        foreach (['clinic_id', 'patient_id', 'organization_id', 'role'] as $key) {
            self::assertArrayNotHasKey($key, $config, 'Config must not contain authority key: ' . $key);
        }
    }

    private function record(int $clinic, int $user, string $name, int $primary, string $status = 'active'): array
    {
        $now = $this->now;
        $mobile = '09' . str_pad((string) (abs(crc32($this->tag . $name)) % 1000000000), 9, '0', STR_PAD_LEFT);
        $patient = $this->insert('cpms_patients', [
            'clinic_id' => $clinic, 'mrn' => $this->tag . $name, 'first_name' => $name,
            'last_name' => 'Prescriptions', 'mobile' => $mobile, 'status' => $status,
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
        $visit = $this->insert('cpms_visits', [
            'clinic_id' => $clinic, 'patient_id' => $patient,
            'clinician_id' => $clinician, 'location_id' => $location, 'visit_date' => $this->today,
            'source' => 'walk_in', 'status' => 'checked_out', 'active' => 0,
            'check_in_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $record = compact('clinic', 'patient', 'link', 'clinician', 'location', 'visit');

        // 1. Primary finalized, patient-visible prescription:
        $rxNumber = 'RX-' . strtoupper(bin2hex(random_bytes(3))) . '-' . $name;
        $rxId = $this->insert('cpms_prescriptions', [
            'clinic_id' => $clinic,
            'location_id' => $location,
            'prescription_number' => $rxNumber,
            'visit_id' => $visit,
            'patient_id' => $patient,
            'clinician_id' => $clinician,
            'status' => 'finalized',
            'is_patient_visible' => 1,
            'finalized_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insert('cpms_prescription_items', [
            'prescription_id' => $rxId,
            'generic_name' => 'Amoxicillin ' . $name,
            'brand_name' => 'Brand ' . $name,
            'strength' => '500mg',
            'form' => 'capsule',
            'dose' => '1 cap',
            'frequency' => 'TDS',
            'route' => 'oral',
            'duration_days' => 7,
            'instructions' => 'Take with water ' . $name,
            'source' => 'manual',
            'sort_order' => 0,
            'created_at' => $now,
        ]);
        $record['rx'] = [
            'id' => $rxId,
            'prescription_number' => $rxNumber,
            'generic_name' => 'Amoxicillin ' . $name,
            'brand_name' => 'Brand ' . $name,
        ];

        // 2. Draft prescription (must NOT leak to patient):
        $draftNumber = 'RX-DRAFT-' . strtoupper(bin2hex(random_bytes(2))) . '-' . $name;
        $draftRxId = $this->insert('cpms_prescriptions', [
            'clinic_id' => $clinic,
            'location_id' => $location,
            'prescription_number' => $draftNumber,
            'visit_id' => $visit,
            'patient_id' => $patient,
            'clinician_id' => $clinician,
            'status' => 'draft',
            'is_patient_visible' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insert('cpms_prescription_items', [
            'prescription_id' => $draftRxId,
            'generic_name' => 'PRIVATE-DRAFT-DRUG ' . $name,
            'dose' => '1 tab',
            'frequency' => 'daily',
            'form' => 'tablet',
            'route' => 'oral',
            'sort_order' => 0,
            'created_at' => $now,
        ]);
        $record['draft_rx'] = ['id' => $draftRxId, 'prescription_number' => $draftNumber];

        // 3. Hidden prescription (is_patient_visible = 0, must NOT leak):
        $hiddenNumber = 'RX-HIDDEN-' . strtoupper(bin2hex(random_bytes(2))) . '-' . $name;
        $hiddenRxId = $this->insert('cpms_prescriptions', [
            'clinic_id' => $clinic,
            'location_id' => $location,
            'prescription_number' => $hiddenNumber,
            'visit_id' => $visit,
            'patient_id' => $patient,
            'clinician_id' => $clinician,
            'status' => 'finalized',
            'is_patient_visible' => 0,
            'finalized_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insert('cpms_prescription_items', [
            'prescription_id' => $hiddenRxId,
            'generic_name' => 'PRIVATE-HIDDEN-DRUG ' . $name,
            'dose' => '1 tab',
            'frequency' => 'daily',
            'form' => 'tablet',
            'route' => 'oral',
            'sort_order' => 0,
            'created_at' => $now,
        ]);
        $record['hidden_rx'] = ['id' => $hiddenRxId, 'prescription_number' => $hiddenNumber];

        return $record;
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
        $id = wp_create_user('p9s6-' . $this->tag . $suffix, 'test-pass-only');
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
        return array_map(
            static fn (string $table): int => (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($table)),
            ['cpms_patients', 'cpms_patient_user_links', 'cpms_prescriptions']
        );
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
