<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * REST-Level Tests (F3) — PatientController (C1/C2 + D2–D5):
 *
 *  - C2: Whitelist خودخدمتی — فیلد بالینی در updateMe رد می‌شود (Field-Access)
 *  - D*: Capability — بیمار به Endpointهای منشی دسترسی ندارد (TP-09)
 *  - Error Envelope CLINIC_* + Nonce (TP-04)
 */
final class RestPatientTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $patientUserId;
    private int $secretaryUserId;
    private int $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Rest", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-RPAT-0001',
                '09121220001',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $this->patientUserId = $this->makeUser('rpat_patient', 'cpms_patient');
        $this->secretaryUserId = $this->makeUser('rpat_secretary', 'cpms_secretary');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (1, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->patientId,
                $this->patientUserId,
                '09121220001',
                $now
            )
        );

        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testMeWithoutNonceRejected(): void
    {
        wp_set_current_user($this->patientUserId);

        $request = new WP_REST_Request('GET', self::NS . '/patient/me');
        $response = rest_do_request($request);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_INVALID_NONCE');
    }

    public function testMeReturnsOwnProfile(): void
    {
        wp_set_current_user($this->patientUserId);

        $response = $this->dispatch('GET', self::NS . '/patient/me');

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        $this->assertSame($this->patientId, (int) $data['id']);
        $this->assertSame('Rest', $data['first_name']);
    }

    public function testUpdateMeWhitelistBlocksClinicalFields(): void
    {
        wp_set_current_user($this->patientUserId);

        $response = $this->dispatch('PUT', self::NS . '/patient/me', [
            'first_name' => 'Renamed',
            'chronic_conditions' => 'DIABETES', // فیلد بالینی — خودخدمتی ممنوع
        ]);

        // فیلد مجاز اعمال، فیلد بالینی رد/نادیده (Service Policy — PatientFlowTest هم پوشش داده)
        $this->assertSame(200, $response->get_status());
        $row = App::db()->fetchRow(
            'SELECT first_name, chronic_conditions FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$this->patientId]
        );
        $this->assertSame('Renamed', $row['first_name']);
        $this->assertNotSame('DIABETES', $row['chronic_conditions']);
    }

    public function testPatientCannotSearchPatients(): void
    {
        wp_set_current_user($this->patientUserId);

        $response = $this->dispatch('GET', self::NS . '/patients/search', ['q' => 'Rest']);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED');
    }

    public function testPatientCannotCreatePatient(): void
    {
        wp_set_current_user($this->patientUserId);

        $response = $this->dispatch('POST', self::NS . '/patients', [
            'first_name' => 'X',
            'last_name' => 'Y',
            'mobile' => '09120009999',
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED');
    }

    public function testSecretaryCreatesPatientViaRest(): void
    {
        wp_set_current_user($this->secretaryUserId);

        $response = $this->dispatch('POST', self::NS . '/patients', [
            'first_name' => 'New',
            'last_name' => 'Patient',
            'mobile' => '09120008888',
        ]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        $this->assertMatchesRegularExpression('/^MR-\d{6}-[A-Z0-9]{5}$/', (string) $data['mrn']);
        $this->assertSame('09120008888', $data['mobile']);

        // موبایل تکراری → رد
        $dup = $this->dispatch('POST', self::NS . '/patients', [
            'first_name' => 'Dup',
            'last_name' => 'Patient',
            'mobile' => '09120008888',
        ]);
        $this->assertSame(400, $dup->get_status());
        $this->assertClinicError($dup, 'CLINIC_VALIDATION_FAILED');
    }

    public function testUnauthenticatedMeRejected(): void
    {
        wp_set_current_user(0);

        $response = $this->dispatch('GET', self::NS . '/patient/me');

        $this->assertSame(401, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_UNAUTHORIZED');
    }

    // ---------- Helpers ----------

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatch(string $method, string $route, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
    }

    private function assertClinicError(WP_REST_Response $response, string $code): void
    {
        $data = $response->get_data();
        $this->assertIsArray($data, 'Error envelope must be an array');
        $this->assertSame($code, $data['code'] ?? null, 'Top-level code must be the stable CLINIC_* token');
        $this->assertArrayHasKey('message', $data);
        $this->assertSame($response->get_status(), $data['data']['status'] ?? null);
    }
}
