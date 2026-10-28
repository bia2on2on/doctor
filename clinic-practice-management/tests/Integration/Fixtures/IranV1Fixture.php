<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration\Fixtures;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use DateTimeImmutable;
use DateTimeZone;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test-only fixture helpers for the Iran V1 messaging + pricing suites.
 *
 * These write DURABLE rows with the same shapes as the delivered Phase 12
 * finance tests. Nothing here is authority: each REST/service boundary still
 * establishes and re-checks its own trusted Clinic/Organization/Location.
 */
trait IranV1Fixture
{
    protected function iv1_boot(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        App::migrations()->migrate();
    }

    protected function iv1_reset(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
    }

    protected function iv1_user(string $role, string $tag = 'user'): int
    {
        $id = (int) wp_create_user('iv1_' . $tag . '_' . bin2hex(random_bytes(4)), 'test-password-123', $tag . '@test.local');
        self::assertGreaterThan(0, $id);
        $user = get_userdata($id);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $id;
    }

    protected function iv1_org(string $tag = 'org'): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert(
            $wpdb->prefix . 'cpms_organizations',
            ['name' => 'IranV1 ' . $tag, 'slug' => 'iv1-org-' . bin2hex(random_bytes(4)), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]
        ));

        return (int) $wpdb->insert_id;
    }

    protected function iv1_clinic(int $org_id): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert(
            $wpdb->prefix . 'cpms_clinics',
            ['organization_id' => $org_id, 'name' => 'IranV1 Clinic', 'slug' => 'iv1-clinic-' . bin2hex(random_bytes(4)), 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]
        ));

        return (int) $wpdb->insert_id;
    }

    protected function iv1_location(int $clinic_id, string $name = 'محل اصلی'): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert(
            $wpdb->prefix . 'cpms_locations',
            ['clinic_id' => $clinic_id, 'name' => $name, 'slug' => 'iv1-loc-' . bin2hex(random_bytes(4)), 'timezone' => 'Asia/Tehran', 'is_primary' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]
        ));

        return (int) $wpdb->insert_id;
    }

    protected function iv1_patient(int $clinic_id, string $tag = 'بیمار'): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert(
            $wpdb->prefix . 'cpms_patients',
            [
                'clinic_id' => $clinic_id, 'mrn' => 'IV1-' . bin2hex(random_bytes(5)),
                'first_name' => $tag, 'last_name' => 'آزمایشی', 'mobile' => '09' . random_int(1000000000, 9999999999),
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]
        ));

        return (int) $wpdb->insert_id;
    }

    protected function iv1_clinician(int $clinic_id, ?int $wp_user_id = null, string $name = 'پزشک'): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert(
            $wpdb->prefix . 'cpms_clinicians',
            [
                'clinic_id' => $clinic_id, 'wp_user_id' => $wp_user_id, 'full_name' => $name,
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]
        ));

        return (int) $wpdb->insert_id;
    }

    protected function iv1_visit(
        int $clinic_id,
        int $location_id,
        int $patient_id,
        int $clinician_id,
        string $status = 'consultation_completed'
    ): int {
        global $wpdb;
        $now  = App::db()->nowUtcSql();
        $date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        self::assertNotFalse($wpdb->insert(
            $wpdb->prefix . 'cpms_visits',
            [
                'clinic_id' => $clinic_id, 'location_id' => $location_id, 'clinician_id' => $clinician_id,
                'patient_id' => $patient_id, 'appointment_id' => null, 'source' => 'walk_in', 'status' => $status,
                'visit_date' => $date, 'check_in_at' => $now, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]
        ));

        return (int) $wpdb->insert_id;
    }

    /**
     * Mark a real walk-in Visit as a completed consultation for billing tests.
     *
     * This is a fixture-only status projection: invoice issuance legitimately
     * requires `consultation_completed`, while the check-in fee snapshot must
     * already have been captured by VisitService::createVisit() before it.
     */
    protected function iv1_complete_consultation(int $visit_id): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cpms_visits',
            ['status' => 'consultation_completed', 'active' => 0],
            ['id' => $visit_id]
        );
    }

    /**
     * Create a checked-in Visit through the real VisitService so the Iran V1
     * fee-snapshot hook runs inside the production atomic transaction.
     */
    protected function iv1_walk_in_visit(
        int $clinic_id,
        int $location_id,
        int $receptionist_user_id,
        int $patient_id,
        int $clinician_id
    ): int {
        $previous = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic($clinic_id, $location_id));
        wp_set_current_user($receptionist_user_id);
        try {
            $visit = App::visitService()->walkIn($receptionist_user_id, $patient_id, $clinician_id);

            return (int) $visit['id'];
        } finally {
            App::replaceExplicitScope($previous);
            wp_set_current_user(0);
        }
    }

    protected function iv1_membership(int $user_id, int $clinic_id, string $role = RolesAndCapabilities::ROLE_SECRETARY): int
    {
        return cpms_test_seed_membership($user_id, $clinic_id, $role);
    }

    protected function iv1_restrict_to_location(int $membership_id, int $location_id): void
    {
        App::membership_service()->set_scope_mode($membership_id, 'location', [$location_id]);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    protected function iv1_scope(int $clinic_id, callable $fn)
    {
        $previous = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic($clinic_id));
        try {
            return $fn();
        } finally {
            App::replaceExplicitScope($previous);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function iv1_headers(int $clinic_id, ?int $location_id = null): array
    {
        $headers = ['X-CPMS-Clinic-Id' => (string) $clinic_id];
        if ($location_id !== null) {
            $headers['X-CPMS-Location-Id'] = (string) $location_id;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    protected function iv1_dispatch(string $method, string $route, array $params = [], array $headers = [], ?array $body = null): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $key => $value) {
            $request->set_header($key, $value);
        }

        return rest_do_request($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function iv1_data(WP_REST_Response $response): array
    {
        $data = $response->get_data();

        return is_array($data) && is_array($data['data'] ?? null) ? $data['data'] : (is_array($data) ? $data : []);
    }

    protected function iv1_code(WP_REST_Response $response): string
    {
        $data = $response->get_data();

        return is_array($data) ? (string) ($data['code'] ?? '') : '';
    }

    protected function iv1_uuid(): string
    {
        $hex = static fn (int $len): string => bin2hex(random_bytes((int) ceil($len / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $hex(8), $hex(4), substr($hex(3), 0, 3), substr($hex(4), 0, 4), $hex(12));
    }
}
