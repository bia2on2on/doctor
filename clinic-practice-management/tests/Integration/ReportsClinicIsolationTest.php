<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * C6-E3 — گزارش‌ها داخل دقیقاً یک Clinic معتبر اجرا می‌شوند.
 *
 * یک Clinician Professional Profile برای WP User (u_clinician_user).
 * کار در چند کلینیک از Membership است، نه ردیف پزشک تکراری.
 */
final class ReportsClinicIsolationTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $clinicA = 1;

    private int $clinicB = 2;

    private int $locA;

    private int $locB;

    private int $doctorUserId;

    private int $accountantUserId;

    private int $clinicianId;

    private int $patientAId;

    private int $patientBId;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        $this->today = gmdate('Y-m-d');
        $this->locA = $this->primaryLocation($this->clinicA);
        $this->insertClinic($this->clinicB, 'reports-iso-b');
        $this->locB = $this->insertLocation($this->clinicB, 'reports-iso-b-loc');

        $this->doctorUserId = $this->makeUser('rp_iso_doc', 'cpms_doctor');
        $this->accountantUserId = $this->makeUser('rp_iso_acc', 'subscriber');
        $acc = get_userdata($this->accountantUserId);
        $acc?->add_cap('cpms_report_read');
        $acc?->add_cap('cpms_patient_read');
        $acc?->add_cap('cpms_finance_read');
        $acc?->add_cap('cpms_medical_read');

        $membership = App::membership_service();
        $membership->create_membership($this->clinicA, $this->doctorUserId, 'cpms_doctor');
        $membership->create_membership($this->clinicB, $this->doctorUserId, 'cpms_doctor');
        $membership->create_membership($this->clinicA, $this->accountantUserId, 'cpms_accountant');
        $membership->create_membership($this->clinicB, $this->accountantUserId, 'cpms_accountant');

        // یک پروفایل پزشک — home clinic ستون است نه مرز مجوز.
        $this->clinicianId = $this->insertClinician($this->clinicA, $this->doctorUserId, 'Dr Dual');
        $this->patientAId = $this->insertPatient($this->clinicA, 'IsoA');
        $this->patientBId = $this->insertPatient($this->clinicB, 'IsoB');

        $visitA = $this->insertVisit($this->clinicA, $this->locA, $this->clinicianId, $this->patientAId);
        $visitB = $this->insertVisit($this->clinicB, $this->locB, $this->clinicianId, $this->patientBId);
        $this->insertPayment($this->clinicA, $this->patientAId, $visitA, 111000);
        $this->insertPayment($this->clinicB, $this->patientBId, $visitB, 222000);
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    public function testAccountantSeesOnlyActiveClinicNotUnionOfMemberships(): void
    {
        $this->activateClinic($this->clinicA);
        wp_set_current_user($this->accountantUserId);

        $visitsA = $this->payload($this->dispatch('GET', self::NS . '/reports/visits', [
            'from' => $this->today,
            'to' => $this->today,
        ]));
        $this->assertSame(200, $this->lastStatus);
        $this->assertSame('clinic', $visitsA['scope']);
        $this->assertSame(1, $visitsA['summary']['count']);
        $this->assertStringContainsString('IsoA', (string) $visitsA['rows'][0]['patient_name']);
        $this->assertStringNotContainsString('IsoB', (string) json_encode($visitsA));

        $revA = $this->payload($this->dispatch('GET', self::NS . '/reports/revenue', [
            'from' => $this->today,
            'to' => $this->today,
        ]));
        $this->assertSame(111000, $revA['summary']['net']);

        $this->activateClinic($this->clinicB);
        $visitsB = $this->payload($this->dispatch('GET', self::NS . '/reports/visits', [
            'from' => $this->today,
            'to' => $this->today,
        ]));
        $this->assertSame('clinic', $visitsB['scope']);
        $this->assertSame(1, $visitsB['summary']['count']);
        $this->assertStringContainsString('IsoB', (string) $visitsB['rows'][0]['patient_name']);
        $this->assertStringNotContainsString('IsoA', (string) json_encode($visitsB));

        $revB = $this->payload($this->dispatch('GET', self::NS . '/reports/revenue', [
            'from' => $this->today,
            'to' => $this->today,
        ]));
        $this->assertSame(222000, $revB['summary']['net']);
    }

    public function testOwnDoctorUsesSameProfileInsideEachTrustedClinic(): void
    {
        $this->activateClinic($this->clinicA);
        wp_set_current_user($this->doctorUserId);

        $visitsA = $this->payload($this->dispatch('GET', self::NS . '/reports/visits', [
            'from' => $this->today,
            'to' => $this->today,
        ]));
        $this->assertSame('own', $visitsA['scope']);
        $this->assertSame(1, $visitsA['summary']['count']);
        $this->assertStringContainsString('IsoA', (string) $visitsA['rows'][0]['patient_name']);
        $this->assertStringNotContainsString('IsoB', (string) json_encode($visitsA));

        $this->activateClinic($this->clinicB);
        $visitsB = $this->payload($this->dispatch('GET', self::NS . '/reports/visits', [
            'from' => $this->today,
            'to' => $this->today,
        ]));
        $this->assertSame('own', $visitsB['scope']);
        $this->assertSame(1, $visitsB['summary']['count']);
        $this->assertStringContainsString('IsoB', (string) $visitsB['rows'][0]['patient_name']);
        $this->assertStringNotContainsString('IsoA', (string) json_encode($visitsB));
    }

    public function testMissingScopeFailsClosedWhenMultipleClinicsExist(): void
    {
        App::resetScope();
        wp_set_current_user($this->accountantUserId);

        $res = $this->dispatch('GET', self::NS . '/reports/visits', [
            'from' => $this->today,
            'to' => $this->today,
        ]);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($res));
    }

    public function testServiceThrowsWhenScopeAmbiguous(): void
    {
        App::resetScope();
        $this->expectException(ScopeRequiredException::class);
        App::reportService()->run($this->accountantUserId, 'avg_waiting', $this->today, $this->today);
    }

    public function testSequentialContextSwitchDoesNotLeak(): void
    {
        wp_set_current_user($this->accountantUserId);
        $this->activateClinic($this->clinicA);
        $first = $this->payload($this->dispatch('GET', self::NS . '/reports/visits', [
            'from' => $this->today,
            'to' => $this->today,
        ]));
        $this->activateClinic($this->clinicB);
        $second = $this->payload($this->dispatch('GET', self::NS . '/reports/visits', [
            'from' => $this->today,
            'to' => $this->today,
        ]));
        $this->activateClinic($this->clinicA);
        $third = $this->payload($this->dispatch('GET', self::NS . '/reports/visits', [
            'from' => $this->today,
            'to' => $this->today,
        ]));

        $this->assertStringContainsString('IsoA', (string) json_encode($first));
        $this->assertStringContainsString('IsoB', (string) json_encode($second));
        $this->assertStringContainsString('IsoA', (string) json_encode($third));
        $this->assertStringNotContainsString('IsoB', (string) json_encode($third));
    }

    // ================= Fixture =================

    private int $lastStatus = 0;

    private function activateClinic(int $clinicId): void
    {
        App::resetScope();
        ScopeContext::set(ClinicScope::forClinic($clinicId));
    }

    private function insertClinic(int $id, string $slug): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id,
                $orgId,
                'Clinic ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        self::assertNotFalse($ok);
    }

    private function primaryLocation(int $clinicId): int
    {
        global $wpdb;
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d AND is_primary = 1 LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function insertLocation(int $clinicId, string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertClinician(int $homeClinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $homeClinicId,
                $name,
                $wpUserId,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $lastName): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'MR-ISO-' . $seq . '-' . $clinicId,
                'Patient',
                $lastName,
                '0917' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertVisit(int $clinicId, int $locationId, int $clinicianId, int $patientId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                     (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, called_at, active, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, "walk_in", "waiting", %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $locationId,
                $clinicianId,
                $patientId,
                $this->today,
                $this->today . ' 10:00:00.000',
                $this->today . ' 10:00:00.000',
                $this->today . ' 10:05:00.000',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertPayment(int $clinicId, int $patientId, int $visitId, int $amount): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_invoices
                     (clinic_id, invoice_number, patient_id, visit_id, status, subtotal, total, paid_amount, balance, issued_by_wp_user_id, created_at, updated_at)
                 VALUES (%d, %s, %d, %d, "paid", %d, %d, %d, 0, %d, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'INV-ISO-' . $seq,
                $patientId,
                $visitId,
                $amount,
                $amount,
                $amount,
                $this->accountantUserId,
                $now,
                $now
            )
        );
        $invoiceId = (int) $wpdb->insert_id;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_payments
                     (clinic_id, payment_number, invoice_id, patient_id, amount, method, idempotency_key, status, paid_at, received_by_wp_user_id, created_at)
                 VALUES (%d, %s, %d, %d, %d, "cash", %s, "captured", %s, %d, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'PAY-ISO-' . $seq,
                $invoiceId,
                $patientId,
                $amount,
                'idem-iso-' . $seq,
                $this->today . ' 12:00:00.000',
                $this->accountantUserId,
                $now
            )
        );
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@test.local');
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

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Response $res): array
    {
        $body = $res->get_data();
        if (is_array($body) && array_key_exists('data', $body)) {
            return is_array($body['data']) ? $body['data'] : [];
        }

        return is_array($body) ? $body : [];
    }

    private function errorCode(WP_REST_Response $res): string
    {
        $body = $res->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code();
        }

        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
    }
}
