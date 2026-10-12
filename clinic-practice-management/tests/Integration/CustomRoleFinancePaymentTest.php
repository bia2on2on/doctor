<?php
/**
 * Slice 3B follow-up — Clinic-local custom roles and ORDINARY finance/payment
 * operations: real REST contract tests (truthful RED first, product bytes in a
 * separate commit).
 *
 * Independent compatibility blocker being resolved: a staff member whose only
 * authority is an ACTIVE Clinic-local custom role (WP-global role `subscriber`,
 * durable ACTIVE membership, effective `cpms_payment_create`) could not record
 * an ordinary payment. The staff-portal route and the delegated service both
 * consulted the WordPress-GLOBAL capability as a gate, which no Clinic-local
 * custom role can ever satisfy.
 *
 * Contract asserted here (real endpoint `/clinic/v1/staff/portal/finance/
 * invoices/{id}/payments`, real MySQL, no mocks):
 *   1. ordinary payment recording SUCCEEDS for that actor through the REAL
 *      endpoint — nonce, trusted Clinic, Location ownership, invoice state,
 *      effective Clinic capability and payment validation unchanged;
 *   2. the payment is persisted and audited (`PAYMENT_CAPTURE`, exactly one);
 *   3. sensitive operations stay denied: payment void, payment refund, invoice
 *      adjustment, and `cpms_invoice_void` can never even enter the definition;
 *   4. an explicit membership DENY on `cpms_payment_create` blocks recording;
 *   5. a foreign-Clinic target is denied;
 *   6. a WordPress administrator with a global capability but NO trusted Clinic
 *      authority stays denied;
 *   7. the existing built-in Secretary and Accountant flows keep working;
 *   8. nothing changes the actor's WordPress-global role or capabilities.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class CustomRoleFinancePaymentTest extends WP_UnitTestCase
{
    private const PAY = '/clinic/v1/staff/portal/finance/invoices/%d/payments';
    private const PAY_VOID = '/clinic/v1/payments/%d/void';
    private const PAY_REFUND = '/clinic/v1/payments/%d/refund';
    private const INVOICE_ADJUST = '/clinic/v1/invoices/%d/adjustments';

    /** The narrow Clinic-local role under test: payments + invoice read only. */
    private const ROLE_KEY = 'sr_cashier';

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
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1 + 2 — ordinary payment recording succeeds and is persisted + audited.
    // ------------------------------------------------------------------

    public function testClinicLocalCustomRoleCanRecordOrdinaryPaymentThroughTheRealEndpoint(): void
    {
        [$clinic, $location, , $invoice, $visit] = $this->financeFixture();
        $cashier = $this->customRoleCashier($clinic);

        wp_set_current_user($cashier);
        $response = $this->pay($invoice, $clinic, $location, ['amount' => 20000, 'method' => 'cash']);

        self::assertSame(
            201,
            $response->get_status(),
            'an ACTIVE Clinic-local custom role carrying cpms_payment_create must record an ordinary payment — '
                . $this->errorCode($response)
        );
        $data = $this->payload($response);
        self::assertSame(20000.0, (float) ($data['payment']['amount'] ?? -1));
        self::assertSame('captured', (string) ($data['payment']['status'] ?? ''));
        self::assertSame('partial', (string) ($data['invoice']['status'] ?? ''));
        self::assertSame(1, $this->paymentCount($invoice), 'exactly one payment row is persisted');
        self::assertSame(1, $this->auditCount('PAYMENT_CAPTURE'), 'the payment is audited exactly once');
        self::assertSame('awaiting_payment', (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visit]
        ), 'a partial capture keeps the Visit awaiting payment');
    }

    // ------------------------------------------------------------------
    // 3 — sensitive operations stay denied for that actor.
    // ------------------------------------------------------------------

    public function testCustomRoleWithPaymentCreateCannotVoidRefundOrAdjust(): void
    {
        [$clinic, $location, , $invoice, ] = $this->financeFixture();
        $cashier = $this->customRoleCashier($clinic);

        wp_set_current_user($cashier);
        $capture = $this->pay($invoice, $clinic, $location, ['amount' => 50000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'fixture capture — ' . $this->errorCode($capture));
        $paymentId = (int) ($this->payload($capture)['payment']['id'] ?? 0);
        self::assertGreaterThan(0, $paymentId);

        $void = $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'denial probe']);
        self::assertSame(403, $void->get_status(), 'payment void must stay denied — ' . $this->errorCode($void));

        $refund = $this->dispatch('POST', sprintf(self::PAY_REFUND, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'denial probe']);
        self::assertSame(403, $refund->get_status(), 'payment refund must stay denied — ' . $this->errorCode($refund));

        $adjust = $this->dispatch('POST', sprintf(self::INVOICE_ADJUST, $invoice), [], $this->scopeHeaders($clinic), ['type' => 'credit', 'amount' => 1000, 'reason' => 'denial probe']);
        self::assertSame(403, $adjust->get_status(), 'invoice adjustment must stay denied — ' . $this->errorCode($adjust));

        // The sensitive attempt left the money untouched.
        self::assertSame('captured', (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_payments') . ' WHERE id = %d',
            [$paymentId]
        ), 'no void/refund may reach the persisted payment');
        self::assertSame('partial', (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d',
            [$invoice]
        ), 'no adjustment may reach the persisted invoice');

        // Inventory of the forbidden vocabulary: neither sensitive capability is
        // even definable for a custom role, so it can never be reached this way.
        foreach (['cpms_payment_void', 'cpms_payment_refund', 'cpms_invoice_void', 'cpms_invoice_adjust', 'cpms_export'] as $sensitive) {
            self::assertFalse(CustomRolePolicy::is_editable_capability($sensitive), $sensitive . ' must stay non-delegable');
        }
        self::assertTrue(CustomRolePolicy::is_editable_capability('cpms_payment_create'), 'ordinary payment capture stays delegable');
    }

    // ------------------------------------------------------------------
    // 4 — explicit membership DENY on PAYMENT_CREATE blocks recording.
    // ------------------------------------------------------------------

    public function testMembershipDenyOnPaymentCreateBlocksRecording(): void
    {
        [$clinic, $location, , $invoice, ] = $this->financeFixture();
        $cashier = $this->customRoleCashier($clinic);

        $membership = App::membership_service()->membership_for($clinic, $cashier);
        self::assertNotNull($membership);
        (new MembershipRepository(App::db()))->set_capability((int) $membership['id'], RolesAndCapabilities::PAYMENT_CREATE, 'deny');

        wp_set_current_user($cashier);
        $response = $this->pay($invoice, $clinic, $location, ['amount' => 10000, 'method' => 'cash']);
        self::assertSame(403, $response->get_status(), 'an explicit membership DENY must win over the role definition');
        self::assertSame(0, $this->paymentCount($invoice), 'nothing may be written on a denied path');
        self::assertSame(0, $this->auditCount('PAYMENT_CAPTURE'));
    }

    // ------------------------------------------------------------------
    // 5 — foreign-Clinic target denied.
    // ------------------------------------------------------------------

    public function testForeignClinicPaymentRecordingIsDenied(): void
    {
        [$clinicA, $locationA, , , ] = $this->financeFixture();
        [$clinicB, $locationB, , $invoiceB, ] = $this->financeFixture();
        self::assertNotSame($clinicA, $clinicB);
        $cashier = $this->customRoleCashier($clinicA);

        wp_set_current_user($cashier);
        $response = $this->pay($invoiceB, $clinicB, $locationB, ['amount' => 10000, 'method' => 'cash']);
        self::assertContains(
            $response->get_status(),
            [400, 403, 404],
            'a foreign Clinic must never be payable — ' . $this->errorCode($response)
        );
        self::assertSame(0, $this->paymentCount($invoiceB));
        self::assertSame(0, $this->auditCount('PAYMENT_CAPTURE'));
    }

    // ------------------------------------------------------------------
    // 6 — WordPress administrator without trusted Clinic authority denied.
    // ------------------------------------------------------------------

    public function testWordPressAdministratorWithoutClinicAuthorityCannotRecordPayment(): void
    {
        [$clinic, $location, , $invoice, ] = $this->financeFixture();
        $admin = $this->makeUser('sub_admin_finance', 'administrator');
        $adminUser = get_userdata($admin);
        self::assertNotFalse($adminUser);
        $adminUser->add_cap('manage_options');
        $adminUser->add_cap(RolesAndCapabilities::PAYMENT_CREATE);

        wp_set_current_user($admin);
        $response = $this->pay($invoice, $clinic, $location, ['amount' => 10000, 'method' => 'cash']);
        self::assertContains(
            $response->get_status(),
            [401, 403, 404],
            'a global WordPress capability must never create Clinic authority: ' . $this->errorCode($response)
        );
        self::assertSame(0, $this->paymentCount($invoice));
    }

    // ------------------------------------------------------------------
    // 7 + 8 — built-in Secretary and Accountant flows stay operational, and
    // nothing mutates the actor's WordPress-global authority.
    // ------------------------------------------------------------------

    public function testBuiltInSecretaryAndAccountantFlowsStayOperationalAndNothingChangesGlobally(): void
    {
        [$clinic, $location, , $invoice, ] = $this->financeFixture();
        $secretary = $this->makeUser('sub_fin_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, RolesAndCapabilities::ROLE_SECRETARY);

        wp_set_current_user($secretary);
        $before = $this->globalAuthoritySnapshot($secretary);
        $response = $this->pay($invoice, $clinic, $location, ['amount' => 25000, 'method' => 'cash']);
        self::assertSame(201, $response->get_status(), 'the built-in Secretary capture stays operational — ' . $this->errorCode($response));
        self::assertSame($before, $this->globalAuthoritySnapshot($secretary), 'no WordPress-global role or capability may change');

        [$clinicB, $locationB, , $invoiceB, ] = $this->financeFixture();
        $accountant = $this->makeUser('sub_fin_accountant', RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountant, $clinicB, RolesAndCapabilities::ROLE_ACCOUNTANT);

        wp_set_current_user($accountant);
        $accountantBefore = $this->globalAuthoritySnapshot($accountant);
        $accountantResponse = $this->pay($invoiceB, $clinicB, $locationB, ['amount' => 15000, 'method' => 'card_pos']);
        self::assertSame(201, $accountantResponse->get_status(), 'the built-in Accountant capture stays operational — ' . $this->errorCode($accountantResponse));
        self::assertSame($accountantBefore, $this->globalAuthoritySnapshot($accountant), 'no WordPress-global role or capability may change');
    }

    // ================= fixtures / helpers =================

    /**
     * Clinic + two Locations + a patient/clinician/Visit/invoice at the FIRST
     * Location. The invoice is `open` with its full balance outstanding.
     *
     * @return array{0:int,1:int,2:int,3:int,4:int}
     */
    private function financeFixture(): array
    {
        $org = $this->insertOrg('Custom-role finance org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $otherLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $clinician = $this->insertClinician($clinic, 'Dr Custom Role');
        $patient = $this->insertPatient($clinic, 'CustomRole');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $clinician, 'open');

        return [$clinic, $location, $otherLocation, $invoice, $visit];
    }

    /**
     * The realistic actor: WP-global `subscriber`, ACTIVE Clinic membership and
     * an ACTIVE Clinic-local custom role whose capability set is exactly
     * `cpms_payment_create` (+ invoice read) — no clinical, no sensitive
     * finance, no unrelated authority.
     */
    private function customRoleCashier(int $clinicId): int
    {
        (new CustomRoleRepository(App::db()))->define(
            $clinicId,
            self::ROLE_KEY,
            [RolesAndCapabilities::PAYMENT_CREATE, RolesAndCapabilities::INVOICE_READ],
            'Cashier (payments only)'
        );

        $userId = $this->makeUser('sub_fin_cashier', 'subscriber');
        $membershipId = cpms_test_seed_membership($userId, $clinicId, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->set_role_key($membershipId, self::ROLE_KEY);

        self::assertTrue(
            App::authorization_service()->can($userId, $clinicId, RolesAndCapabilities::PAYMENT_CREATE),
            'fixture: the custom role must be effective in the Clinic'
        );
        self::assertFalse(App::authorization_service()->can($userId, $clinicId, RolesAndCapabilities::PAYMENT_REFUND));
        self::assertFalse(App::authorization_service()->can($userId, $clinicId, 'cpms_medical_read'));

        return $userId;
    }

    /** @return array{0:string,1:list<string>,2:array<string,bool>} */
    private function globalAuthoritySnapshot(int $userId): array
    {
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $caps = (array) $user->allcaps;
        ksort($caps);

        return [(string) ($user->roles[0] ?? ''), array_values((array) $user->roles), $caps];
    }

    /** @param array<string, mixed> $body */
    private function pay(int $invoiceId, int $clinicId, ?int $locationId, array $body): WP_REST_Response
    {
        return $this->dispatch(
            'POST',
            sprintf(self::PAY, $invoiceId),
            [],
            $this->scopeHeaders($clinicId, $locationId),
            $body,
            ['Idempotency-Key' => $this->uuid()]
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function paymentCount(int $invoiceId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d',
            [$invoiceId]
        );
    }

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            [$action]
        );
    }

    /** @return array<string, string> */
    private function scopeHeaders(int $clinicId, ?int $locationId = null): array
    {
        $headers = ['X-CPMS-Clinic-Id' => (string) $clinicId];
        if ($locationId !== null) {
            $headers['X-CPMS-Location-Id'] = (string) $locationId;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     * @param array<string, string> $extraHeaders
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = [], ?array $body = null, array $extraHeaders = [], bool $withNonce = true): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }
        if ($withNonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        foreach ($headers as $key => $value) {
            $request->set_header($key, $value);
        }
        foreach ($extraHeaders as $key => $value) {
            $request->set_header($key, $value);
        }

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
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_organizations', ['name' => $name, 'slug' => 'org-' . bin2hex(random_bytes(3)), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertClinic(int $orgId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinics', ['organization_id' => $orgId, 'name' => 'Custom-role Clinic', 'slug' => 'clinic-' . bin2hex(random_bytes(3)), 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertLocation(int $clinicId, string $timezone): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_locations', ['clinic_id' => $clinicId, 'name' => 'Custom-role Location', 'slug' => 'location-' . bin2hex(random_bytes(3)), 'timezone' => $timezone, 'is_primary' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', ['clinic_id' => $clinicId, 'mrn' => 'M-' . bin2hex(random_bytes(5)), 'first_name' => $tag, 'last_name' => 'Finance', 'mobile' => '09' . random_int(1000000000, 9999999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertClinician(int $clinicId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinicians', ['clinic_id' => $clinicId, 'full_name' => $name, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]));

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
        ]));

        return (int) $wpdb->insert_id;
    }

    private function insertInvoice(int $clinicId, int $locationId, int $patientId, int $visitId, int $actorId, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $total = '100000.00';
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoices', [
            'clinic_id' => $clinicId, 'location_id' => $locationId, 'invoice_number' => 'INV-CR-' . bin2hex(random_bytes(4)),
            'patient_id' => $patientId, 'visit_id' => $visitId, 'status' => $status, 'subtotal' => $total,
            'total' => $total, 'currency' => 'IRR', 'paid_amount' => '0.00', 'balance' => $total,
            'issued_by_wp_user_id' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]));

        return (int) $wpdb->insert_id;
    }
}
