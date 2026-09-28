<?php
/** Phase 12 Slice 1 — Staff Portal Finance board read-only acceptance. */
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

final class Phase12StaffPortalFinanceBoardTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        App::migrations()->migrate();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        parent::tearDown();
    }

    public function testBoardIsLocationScopedBoundedProjectionAndReadOnly(): void
    {
        $org = $this->insertOrg('Finance board org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tokyo');
        $otherLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $actor = $this->makeUser('phase12_finance_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($actor, $clinic, 'cpms_secretary');
        $patientWithInvoice = $this->insertPatient($clinic, 'Patient');
        $patientWithoutInvoice = $this->insertPatient($clinic, 'NoInvoice');
        $patientOtherLocation = $this->insertPatient($clinic, 'OtherLocation');
        $clinician = $this->insertClinician($clinic, 'Dr Board');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
        $checkIn = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-2 hours')->format('Y-m-d H:i:s');
        $visitWithInvoice = $this->insertVisit($clinic, $location, $patientWithInvoice, $clinician, $date, $checkIn, 'awaiting_payment');
        $visitWithoutInvoice = $this->insertVisit($clinic, $location, $patientWithoutInvoice, $clinician, $date, $checkIn, 'awaiting_payment');
        $this->insertVisit($clinic, $otherLocation, $patientOtherLocation, $clinician, $date, $checkIn, 'awaiting_payment');
        $this->insertVisit($clinic, $location, $patientOtherLocation, $clinician, $date, $checkIn, 'waiting');
        $this->insertInvoice($clinic, $location, $patientWithInvoice, $visitWithInvoice, $actor);

        $readOnlyBefore = $this->readOnlySnapshot();
        $visitBefore = App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitWithInvoice]);

        self::assertTrue(StaffPortalShell::finance_module_eligible($actor), 'secretary with existing same-Clinic finance/invoice/queue read authority is eligible');
        self::assertContains(StaffPortalShell::MODULE_FINANCE, array_column(StaffPortalShell::registered_modules(), 'id'));
        self::assertFileExists(StaffPortalShell::finance_module_template_path());
        $html = $this->renderFinancePortal($actor);
        self::assertStringContainsString('data-cpms-portal="staff"', $html);
        self::assertStringContainsString('data-cpms-staff-module="finance"', $html);
        self::assertStringContainsString('data-role="finance-board"', $html);
        self::assertStringNotContainsString('wp-admin', $html, 'finance board is not rendered in wp-admin chrome');

        wp_set_current_user($actor);
        $context = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/finance/context', [], $this->scopeHeaders($clinic));
        self::assertSame(200, $context->get_status(), 'authorized finance context must resolve');
        $contextData = $this->payload($context);
        self::assertTrue((bool) ($contextData['selection_required'] ?? false), 'N eligible Locations require explicit choice');

        $missingLocation = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/finance/awaiting-payment', [], $this->scopeHeaders($clinic));
        self::assertSame(400, $missingLocation->get_status());
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($missingLocation));

        // Observe the actual product query: all display joins and invoice data
        // must arrive in one bounded database query, not an N+1 loop.
        $projectionQueries = 0;
        $captureProjection = static function (string $query) use (&$projectionQueries): string {
            if (str_contains($query, 'AS visit_id') && str_contains($query, 'cpms_visits')) {
                $projectionQueries++;
            }
            return $query;
        };
        add_filter('query', $captureProjection, PHP_INT_MAX);
        $board = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/finance/awaiting-payment', [], $this->scopeHeaders($clinic, $location));
        remove_filter('query', $captureProjection, PHP_INT_MAX);
        self::assertSame(200, $board->get_status(), 'authorized board read must succeed — ' . $this->errorCode($board));
        $data = $this->payload($board);
        self::assertSame($date, $data['date'] ?? null, 'operational day is the Location-local date');
        self::assertSame($location, (int) ($data['location_id'] ?? 0));
        self::assertFalse((bool) ($data['has_more'] ?? true));
        self::assertCount(2, $data['visits'] ?? []);
        self::assertSame(1, $projectionQueries, 'one joined read query serves the complete board projection');

        $byPatient = [];
        foreach ($data['visits'] as $row) {
            $byPatient[$row['patient_name']] = $row;
            self::assertSame('Dr Board', $row['clinician_name']);
            self::assertSame($date, $row['operational_date']);
            self::assertSame('awaiting_payment', $row['visit_status']);
            self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $row['operational_time']);
            self::assertSame(['patient_name', 'clinician_name', 'operational_date', 'jalali_date', 'operational_time', 'visit_status', 'invoice'], array_keys($row));
        }
        self::assertArrayHasKey('Patient Board', $byPatient);
        self::assertSame('Asia/Tokyo', $this->locationTimezone($location));
        self::assertSame([
            'status' => 'partial', 'total' => '1234.00',
            'paid' => '300.00', 'remaining' => '934.00', 'currency' => 'IRR',
        ], $byPatient['Patient Board']['invoice']);
        self::assertArrayHasKey('NoInvoice Board', $byPatient);
        self::assertNull($byPatient['NoInvoice Board']['invoice'], 'missing invoice must remain null, not a fabricated zero balance');

        self::assertSame($visitBefore, App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitWithInvoice]));
        self::assertSame($readOnlyBefore, $this->readOnlySnapshot(), 'read routes do not mutate invoice/payment, visit history/state, audit, idempotency, job, or notification data');
    }

    public function testMembershipAloneAndFinanceWithoutQueueReadDoNotExposeBoard(): void
    {
        $org = $this->insertOrg('Finance denied org');
        $clinic = $this->insertClinic($org);
        $this->insertLocation($clinic, 'UTC');
        $accountant = $this->makeUser('phase12_finance_accountant', RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountant, $clinic, 'cpms_accountant');
        self::assertFalse(StaffPortalShell::finance_module_eligible($accountant), 'accountant role lacks queue-read authority and is not widened by this board');
        wp_set_current_user($accountant);
        $response = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/finance/awaiting-payment', [], $this->scopeHeaders($clinic));
        self::assertSame(403, $response->get_status());
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($response));

        $member = $this->makeUser('phase12_finance_member_only', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($member, $clinic, 'cpms_manager');
        self::assertFalse(StaffPortalShell::finance_module_eligible($member), 'active membership without the existing finance read permissions is not access');
    }

    private function renderFinancePortal(int $userId): string
    {
        wp_set_current_user($userId);
        $url = StaffPortalShell::portal_url();
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?? '');
        $previousGet = $_GET;
        $_GET[StaffPortalShell::MODULE_PARAM] = StaffPortalShell::MODULE_FINANCE;
        try {
            $this->go_to($path . ($query !== '' ? '?' . $query . '&' : '?') . 'cpms-module=finance');
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

    private function makeUser(string $login, string $role): int
    {
        $id = (int) wp_create_user($login . '_' . bin2hex(random_bytes(3)), 'test-password-123', $login . '@test.local');
        self::assertGreaterThan(0, $id);
        get_userdata($id)->set_role($role);
        return $id;
    }

    private function insertOrg(string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_organizations', ['name' => $name, 'slug' => 'org-' . bin2hex(random_bytes(3)), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]), 'organization fixture insert');
        return (int) $wpdb->insert_id;
    }

    private function insertClinic(int $orgId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinics', ['organization_id' => $orgId, 'name' => 'Finance Clinic', 'slug' => 'clinic-' . bin2hex(random_bytes(3)), 'timezone' => 'America/New_York', 'created_at' => $now, 'updated_at' => $now]), 'clinic fixture insert');
        return (int) $wpdb->insert_id;
    }

    private function insertLocation(int $clinicId, string $timezone): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_locations', ['clinic_id' => $clinicId, 'name' => 'Finance Location', 'slug' => 'location-' . bin2hex(random_bytes(3)), 'timezone' => $timezone, 'is_primary' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]), 'Location fixture insert');
        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', ['clinic_id' => $clinicId, 'mrn' => 'M-' . bin2hex(random_bytes(5)), 'first_name' => $tag, 'last_name' => 'Board', 'mobile' => '09' . random_int(1000000000, 9999999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]), 'patient fixture insert');
        return (int) $wpdb->insert_id;
    }

    private function insertClinician(int $clinicId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinicians', ['clinic_id' => $clinicId, 'full_name' => $name, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]), 'clinician fixture insert');
        return (int) $wpdb->insert_id;
    }

    private function insertVisit(int $clinicId, int $locationId, int $patientId, int $clinicianId, string $date, string $checkIn, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_visits', [
            'clinic_id' => $clinicId, 'location_id' => $locationId, 'clinician_id' => $clinicianId,
            'patient_id' => $patientId, 'appointment_id' => null, 'source' => 'walk_in', 'status' => $status,
            'visit_date' => $date, 'check_in_at' => $checkIn, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]), 'visit fixture insert');
        return (int) $wpdb->insert_id;
    }

    private function insertInvoice(int $clinicId, int $locationId, int $patientId, int $visitId, int $actorId): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoices', [
            'clinic_id' => $clinicId, 'location_id' => $locationId, 'invoice_number' => 'INV-BOARD-1',
            'patient_id' => $patientId, 'visit_id' => $visitId, 'status' => 'partial', 'subtotal' => '1234.00',
            'total' => '1234.00', 'currency' => 'IRR', 'paid_amount' => '300.00', 'balance' => '934.00',
            'issued_by_wp_user_id' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]), 'invoice fixture insert');
    }

    /** @return array<string, int> */
    private function readOnlySnapshot(): array
    {
        $tables = [
            'cpms_visits', 'cpms_visit_status_history', 'cpms_invoices', 'cpms_invoice_items',
            'cpms_payments', 'cpms_payment_adjustments', 'cpms_audit_logs', 'cpms_idempotency_keys',
            'cpms_jobs', 'cpms_notifications',
        ];
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($table));
        }
        return $snapshot;
    }

    private function locationTimezone(int $locationId): string
    {
        return (string) App::db()->fetchValue('SELECT timezone FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d', [$locationId]);
    }

    /** @return array<string, string> */
    private function scopeHeaders(int $clinicId, ?int $locationId = null): array
    {
        $headers = ['X-CPMS-Clinic-Id' => (string) $clinicId];
        if ($locationId !== null) $headers['X-CPMS-Location-Id'] = (string) $locationId;
        return $headers;
    }

    private function dispatch(string $method, string $route, array $params = [], array $headers = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) $request->set_param($key, $value);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $key => $value) $request->set_header($key, $value);
        return rest_do_request($request);
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Response $response): array
    {
        $data = $response->get_data();
        return is_array($data) && is_array($data['data'] ?? null) ? $data['data'] : (is_array($data) ? $data : []);
    }

    private function errorCode(WP_REST_Response $response): string
    {
        $data = $response->get_data();
        return is_array($data) ? (string) ($data['code'] ?? '') : '';
    }
}
