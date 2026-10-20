<?php
/**
 * Clinic-local custom roles × Staff Portal FINANCE MODULE access — focused
 * regression coverage for the confirmed StaffPortalShell boundary defect.
 *
 * Confirmed defect (traced at the PR head before this change): the module was
 * only ever selected from WordPress-GLOBAL truth — the shell template mounts a
 * module through `StaffPortalShell::select_module()` →
 * `eligible_modules()` → `module_eligible()` →
 * `finance_module_eligible()`, which FIRST required
 * `WP_User::has_cap()` for FINANCE_READ + INVOICE_READ + QUEUE_READ. A
 * Clinic-local custom role is durable membership data and can never satisfy a
 * WP-global capability, so a real staff member (WP-global `subscriber`, ACTIVE
 * membership, ACTIVE custom role, all three read capabilities effective
 * *inside the Clinic*) had no finance module at all: the portal rendered
 * without the module, the board never mounted, and the Slice 1/3 REST work was
 * unreachable from the product surface. The existing direct REST tests could
 * not see this because they never cross the shell boundary.
 *
 * Contract asserted here, on the real portal boundary (real template rendering)
 * and the real REST routes (real MySQL, no mocks):
 *   1. the custom-role actor reaches and can select the existing Finance module;
 *   2. the finance context loads under the trusted Clinic scope;
 *   3. the existing payment controls are mounted for that actor;
 *   4. an ordinary payment POST still succeeds and is audited;
 *   5. nothing changes the actor's WordPress-global role or capabilities;
 *   6-7. the three required READ capabilities stay mandatory (PAYMENT_CREATE
 *        alone never unlocks the board) and an explicit membership DENY wins;
 *   8. a foreign Clinic stays inaccessible while the actor's own Clinic works;
 *   9. a patient without authorized staff membership gains nothing by supplying
 *        identifiers;
 *  10. the built-in Secretary stays eligible and the built-in Accountant/Manager
 *        stay outside the Finance module read boundary (unchanged pins).
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class CustomRoleStaffPortalFinanceModuleTest extends WP_UnitTestCase
{
    private const CONTEXT = '/clinic/v1/staff/portal/finance/context';
    private const PAY = '/clinic/v1/staff/portal/finance/invoices/%d/payments';
    private const ROLE_KEY = 'sr_board_cashier';

    /** The three read capabilities the Finance board genuinely requires. */
    /**
     * Owner delivery 2026-10-20: the finance board's read boundary is finance +
     * invoice read in ONE Clinic. Queue read is no longer part of it (the
     * accountant preset owns the board), so each of these two stays mandatory.
     */
    private const READ_CAPS = [
        RolesAndCapabilities::FINANCE_READ,
        RolesAndCapabilities::INVOICE_READ,
    ];

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
    // 1 + 3 + 5 — module reachability, controls mounted, no global mutation.
    // ------------------------------------------------------------------

    public function testClinicLocalCustomRoleReachesTheFinanceModuleThroughTheShellBoundary(): void
    {
        [$clinic, $location, ] = $this->clinicFixture();
        $cashier = $this->customRoleActor($clinic, self::ROLE_KEY, [
            ...self::READ_CAPS,
            RolesAndCapabilities::PAYMENT_CREATE,
        ]);

        $before = $this->globalAuthoritySnapshot($cashier);

        self::assertTrue(
            StaffPortalShell::finance_module_eligible($cashier),
            'an ACTIVE Clinic-local custom role carrying the read capabilities must reach the Finance module'
        );
        self::assertContains(
            StaffPortalShell::MODULE_FINANCE,
            array_column(StaffPortalShell::eligible_modules($cashier), 'id'),
            'the Finance module must be offered to the actor'
        );
        self::assertSame(
            StaffPortalShell::MODULE_FINANCE,
            StaffPortalShell::select_module($cashier),
            'the Finance module must be selectable (no doctor/reception eligibility for this actor)'
        );

        $html = $this->renderPortal($cashier, StaffPortalShell::MODULE_FINANCE);
        self::assertStringContainsString('data-cpms-staff-module="finance"', $html, 'the finance module is mounted');
        self::assertStringContainsString('data-role="finance-board"', $html, 'the existing finance board is mounted');
        self::assertStringContainsString(
            'data-role="finance-payment"',
            $html,
            'the existing manual capture panel is mounted for a PAYMENT_CREATE holder'
        );

        self::assertSame($before, $this->globalAuthoritySnapshot($cashier), 'no WordPress-global role or capability may change');
    }

    // ------------------------------------------------------------------
    // 2 + 4 — real REST journey: context under trusted scope, capture works.
    // ------------------------------------------------------------------

    public function testFinanceContextLoadsAndOrdinaryPaymentStillSucceedsForThatActor(): void
    {
        [$clinic, $location, $invoice] = $this->clinicFixture();
        $cashier = $this->customRoleActor($clinic, self::ROLE_KEY, [
            ...self::READ_CAPS,
            RolesAndCapabilities::PAYMENT_CREATE,
        ]);

        wp_set_current_user($cashier);
        $context = $this->dispatch('GET', self::CONTEXT, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $context->get_status(), 'the finance context must load under the trusted Clinic scope — ' . $this->errorCode($context));

        $payment = $this->dispatch(
            'POST',
            sprintf(self::PAY, $invoice),
            [],
            $this->scopeHeaders($clinic, $location),
            ['amount' => 10000, 'method' => 'cash'],
            ['Idempotency-Key' => $this->uuid()]
        );
        self::assertSame(201, $payment->get_status(), 'an ordinary capture must still succeed — ' . $this->errorCode($payment));
        self::assertSame(1, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d',
            [$invoice]
        ), 'the capture is persisted exactly once');
        self::assertSame(1, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . " WHERE action = 'PAYMENT_CAPTURE'",
            []
        ), 'the capture is audited exactly once');
    }

    // ------------------------------------------------------------------
    // 6 — the read capabilities stay mandatory (no weakening to fit PAYMENT_CREATE).
    // ------------------------------------------------------------------

    public function testEachRequiredFinanceReadCapabilityStaysMandatory(): void
    {
        [$clinic, ] = $this->clinicFixture();
        $full = [
            ...self::READ_CAPS,
            RolesAndCapabilities::PAYMENT_CREATE,
        ];

        foreach (self::READ_CAPS as $index => $missing) {
            $caps = array_values(array_diff($full, [$missing]));
            $actor = $this->customRoleActor($clinic, 'sr_board_missing_' . $index, $caps);
            self::assertFalse(
                StaffPortalShell::finance_module_eligible($actor),
                'the Finance board must stay unavailable without ' . $missing
            );
        }

        // PAYMENT_CREATE alone never unlocks a read board.
        $captureOnly = $this->customRoleActor($clinic, 'sr_board_capture_only', [RolesAndCapabilities::PAYMENT_CREATE]);
        self::assertFalse(
            StaffPortalShell::finance_module_eligible($captureOnly),
            'payment capture without the read capabilities must not unlock the Finance board'
        );
        self::assertTrue(
            App::authorization_service()->can($captureOnly, $clinic, RolesAndCapabilities::PAYMENT_CREATE),
            'the capture-only actor is still a legitimate capture holder — the board is what stays denied'
        );
    }

    // ------------------------------------------------------------------
    // 7 — explicit membership DENY precedence is preserved.
    // ------------------------------------------------------------------

    public function testExplicitMembershipDenyTakesPrecedenceOverTheCustomRole(): void
    {
        [$clinic, ] = $this->clinicFixture();
        $actor = $this->customRoleActor($clinic, 'sr_board_deny', [
            ...self::READ_CAPS,
            RolesAndCapabilities::PAYMENT_CREATE,
        ]);
        self::assertTrue(StaffPortalShell::finance_module_eligible($actor), 'fixture baseline: eligible before the DENY');

        $membership = App::membership_service()->membership_for($clinic, $actor);
        self::assertNotNull($membership);
        (new MembershipRepository(App::db()))->set_capability(
            (int) $membership['id'],
            RolesAndCapabilities::FINANCE_READ,
            'deny'
        );

        self::assertFalse(
            StaffPortalShell::finance_module_eligible($actor),
            'an explicit membership DENY must win over the custom-role definition'
        );
    }

    // ------------------------------------------------------------------
    // 8 — foreign-Clinic scope stays inaccessible (and the own Clinic works).
    // ------------------------------------------------------------------

    public function testForeignClinicScopeStaysInaccessibleForThatActor(): void
    {
        [$clinicA, $locationA, ] = $this->clinicFixture();
        [$clinicB, $locationB, ] = $this->clinicFixture();
        self::assertNotSame($clinicA, $clinicB);
        $actor = $this->customRoleActor($clinicB, self::ROLE_KEY, [
            ...self::READ_CAPS,
            RolesAndCapabilities::PAYMENT_CREATE,
        ]);

        wp_set_current_user($actor);
        $foreign = $this->dispatch('GET', self::CONTEXT, [], $this->scopeHeaders($clinicA, $locationA));
        self::assertContains(
            $foreign->get_status(),
            [400, 403, 404],
            'a Clinic the actor is not a member of must stay inaccessible — ' . $this->errorCode($foreign)
        );

        $own = $this->dispatch('GET', self::CONTEXT, [], $this->scopeHeaders($clinicB, $locationB));
        self::assertSame(200, $own->get_status(), 'the actor\'s own Clinic stays usable — ' . $this->errorCode($own));
    }

    // ------------------------------------------------------------------
    // 9 — a patient without authorized staff membership gains nothing.
    // ------------------------------------------------------------------

    public function testPatientWithoutStaffMembershipCannotGainAccessBySupplyingIdentifiers(): void
    {
        [$clinic, $location, ] = $this->clinicFixture();
        $patient = $this->makeUser('sub_portal_patient', RolesAndCapabilities::ROLE_PATIENT);

        self::assertFalse(
            StaffPortalShell::finance_module_eligible($patient),
            'a patient role must never reach the Finance module'
        );

        wp_set_current_user($patient);
        $context = $this->dispatch('GET', self::CONTEXT, [], $this->scopeHeaders($clinic, $location));
        self::assertContains(
            $context->get_status(),
            [400, 401, 403, 404],
            'supplying Clinic/Location identifiers must not create authority — ' . $this->errorCode($context)
        );
    }

    // ------------------------------------------------------------------
    // 10 — built-in role behaviour is unchanged.
    // ------------------------------------------------------------------

    public function testBuiltInRoleFinanceModuleBehaviourIsPreserved(): void
    {
        [$clinic, ] = $this->clinicFixture();

        $secretary = $this->makeUser('sub_board_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, RolesAndCapabilities::ROLE_SECRETARY);
        self::assertTrue(StaffPortalShell::finance_module_eligible($secretary), 'the built-in Secretary stays eligible');

        $accountant = $this->makeUser('sub_board_accountant', RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountant, $clinic, RolesAndCapabilities::ROLE_ACCOUNTANT);
        self::assertTrue(
            StaffPortalShell::finance_module_eligible($accountant),
            'the built-in Accountant reads the Finance board (finance + invoice read; queue read is not part of the read boundary)'
        );

        $manager = $this->makeUser('sub_board_manager', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($manager, $clinic, RolesAndCapabilities::ROLE_MANAGER);
        self::assertFalse(StaffPortalShell::finance_module_eligible($manager), 'membership alone never enables the module');
    }

    // ================= fixtures / helpers =================

    /**
     * Clinic + Location + an `open` invoice at that Location.
     *
     * @return array{0:int,1:int,2:int}
     */
    private function clinicFixture(): array
    {
        $org = $this->insertOrg('Custom-role portal org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic);
        $clinician = $this->insertClinician($clinic);
        $patient = $this->insertPatient($clinic);
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date);
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $clinician);

        return [$clinic, $location, $invoice];
    }

    /**
     * WP-global `subscriber` + ACTIVE Clinic membership whose durable role_key is
     * an ACTIVE Clinic-local custom definition with exactly the given caps.
     *
     * @param list<string> $caps
     */
    private function customRoleActor(int $clinicId, string $roleKey, array $caps): int
    {
        (new CustomRoleRepository(App::db()))->define($clinicId, $roleKey, $caps, 'Portal board role');
        $userId = $this->makeUser($roleKey, 'subscriber');
        $membershipId = cpms_test_seed_membership($userId, $clinicId, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->set_role_key($membershipId, $roleKey);
        self::assertSame(['subscriber'], (array) get_userdata($userId)->roles, 'fixture: the WP-global role stays subscriber');

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

    /** Render the real plugin-owned Staff Portal template for the given module. */
    private function renderPortal(int $userId, string $moduleId): string
    {
        wp_set_current_user($userId);
        $url = StaffPortalShell::portal_url();
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?? '');
        $previousGet = $_GET;
        try {
            $this->go_to($path . ($query !== '' ? '?' . $query . '&' : '?') . StaffPortalShell::MODULE_PARAM . '=' . $moduleId);
            $_GET[StaffPortalShell::MODULE_PARAM] = $moduleId;
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
     * @param array<string, mixed>      $params
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extraHeaders
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = [], ?array $body = null, array $extraHeaders = []): WP_REST_Response
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
        foreach ($extraHeaders as $key => $value) {
            $request->set_header($key, $value);
        }

        return rest_do_request($request);
    }

    private function errorCode(WP_REST_Response $response): string
    {
        $data = $response->get_data();

        return is_array($data) ? (string) ($data['code'] ?? '') : '';
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
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
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinics', ['organization_id' => $orgId, 'name' => 'Custom-role portal Clinic', 'slug' => 'clinic-' . bin2hex(random_bytes(3)), 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertLocation(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_locations', ['clinic_id' => $clinicId, 'name' => 'Portal Location', 'slug' => 'location-' . bin2hex(random_bytes(3)), 'timezone' => 'Asia/Tehran', 'is_primary' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', ['clinic_id' => $clinicId, 'mrn' => 'M-' . bin2hex(random_bytes(5)), 'first_name' => 'Portal', 'last_name' => 'Finance', 'mobile' => '09' . random_int(1000000000, 9999999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertClinician(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinicians', ['clinic_id' => $clinicId, 'full_name' => 'Dr Portal', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertVisit(int $clinicId, int $locationId, int $patientId, int $clinicianId, string $date): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_visits', [
            'clinic_id' => $clinicId, 'location_id' => $locationId, 'clinician_id' => $clinicianId,
            'patient_id' => $patientId, 'appointment_id' => null, 'source' => 'walk_in', 'status' => 'awaiting_payment',
            'visit_date' => $date, 'check_in_at' => $now, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]));

        return (int) $wpdb->insert_id;
    }

    private function insertInvoice(int $clinicId, int $locationId, int $patientId, int $visitId, int $actorId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $total = '100000.00';
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoices', [
            'clinic_id' => $clinicId, 'location_id' => $locationId, 'invoice_number' => 'INV-CRP-' . bin2hex(random_bytes(4)),
            'patient_id' => $patientId, 'visit_id' => $visitId, 'status' => 'open', 'subtotal' => $total,
            'total' => $total, 'currency' => 'IRR', 'paid_amount' => '0.00', 'balance' => $total,
            'issued_by_wp_user_id' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]));

        return (int) $wpdb->insert_id;
    }
}
