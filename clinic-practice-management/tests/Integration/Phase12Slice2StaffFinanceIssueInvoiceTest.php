<?php
/**
 * Phase 12 Slice 2 — Staff Portal Finance: issue invoice for CURRENT trusted
 * Location consultation-completed Visits — TEST-ONLY RED.
 *
 * Owner-approved slice contract (bounded):
 *   Inside the EXISTING independent Staff Portal Finance module (Phase 12
 *   Slice 1 read-only board), a Finance actor issues the FIRST invoice for a
 *   Visit that is currently `consultation_completed` at the CURRENT trusted
 *   operational Location of the trusted Clinic.
 *
 * Intended product boundary (two routes, both in the established `clinic/v1`
 * namespace, both nonce-authenticated, both mounted only in the plugin-owned
 * Staff Portal module):
 *   GET  /clinic/v1/staff/portal/finance/invoice-eligible
 *   POST /clinic/v1/staff/portal/finance/visits/{id}/invoice
 *
 * The read surface is bounded exactly like the Slice 1 board:
 *   trusted Clinic (App::scope(), never payload) + CURRENT trusted operational
 *   Location (0 eligible => fail closed, 1 => auto-resolution, N>1 => explicit
 *   selector REQUIRED, foreign/inactive => fail closed) + Location-local
 *   operational date + exact status `consultation_completed` + deterministic
 *   order + at most 100 returned rows with `has_more` truncation signal
 *   (LIMIT 101) + NO cross-Location/global fallback. Projection is minimal:
 *   visit_id (selector only), patient display name, clinician display name,
 *   operational date/Jalali/time, and the status literal. No mobile, national
 *   id, clinical, prescription, file, invoice-item or payment data.
 *
 * The mutation delegates to the EXISTING `FinanceService::issueInvoice()`
 * contract — there is no second invoice implementation, no new state, no new
 * capability, no migration. Before delegation the boundary enforces trusted
 * Clinic + CURRENT trusted Location ownership of the selected Visit; raw
 * visit/location/clinic ids are selectors, never authority. A Visit outside the
 * trusted Clinic or outside the CURRENT trusted Location fails without
 * disclosing it (404 CLINIC_NOT_FOUND, non-enumerating), and a same-Location
 * Visit in another state returns 409 CLINIC_INVALID_TRANSITION. The existing
 * duplicate-active-invoice guard (409 CLINIC_POLICY_VIOLATION) and the existing
 * 404 parity are preserved unchanged.
 *
 * This slice exposes FIRST issuance only. The backend recovery/re-issue path
 * for `awaiting_payment` Visits (including a Visit whose invoice was voided) is
 * deliberately NOT exposed through the portal route — Group 5 proves the
 * portal refuses it while the existing shared D12 route keeps its current
 * behaviour untouched.
 *
 * Invoice items: the smallest commercially useful composition using the
 * existing contract {description, quantity, unit_price}. No tax UI, no
 * invoice-level or item-level discount UI; the portal route neither accepts nor
 * forwards those fields, and totals stay server-authoritative through the
 * existing InvoiceCalc/FinanceService path (Group 4 proves a hostile client
 * cannot influence them).
 *
 * After a successful first issuance the EXISTING backend transition runs
 * (VisitMachine V11: event `invoice_ready` from `consultation_completed` to
 * `awaiting_payment`), so the Visit leaves this issuance list and appears on
 * the existing Slice 1 awaiting-payment board (Group 4).
 *
 * INTENDED PRODUCT RED (verified pre-write against main 2849efd3):
 *   - neither portal route exists (REST dispatch returns 404 rest_no_route) —
 *     Groups 1..5 anchor here;
 *   - the module template/JS has no issuance panel, no eligible row contract
 *     and no `can_issue_invoice` context flag — Group 6 anchors here.
 * GREEN-today controls that MUST stay GREEN: the Slice 1 board read, module
 * registration/eligibility, and the shared D12 duplicate/404 semantics.
 *
 * Test-only: no product PHP/template/CSS/JS, no migration, no workflow change.
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

final class Phase12Slice2StaffFinanceIssueInvoiceTest extends WP_UnitTestCase
{
    private const ELIGIBLE = '/clinic/v1/staff/portal/finance/invoice-eligible';
    private const ISSUE = '/clinic/v1/staff/portal/finance/visits/%d/invoice';
    private const BOARD = '/clinic/v1/staff/portal/finance/awaiting-payment';
    private const CONTEXT = '/clinic/v1/staff/portal/finance/context';
    private const SHARED_ISSUE = '/clinic/v1/invoices';

    private array $capturedProjectionQueries = [];

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

    /**
     * Group 1 — bounded read surface.
     */
    public function testInvoiceEligibleReadIsBoundedCurrentLocationConsultationCompletedProjection(): void
    {
        $org = $this->insertOrg('Slice2 eligible org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tokyo');
        $otherLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice2_read_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Slice Two');
        $timezone = new \DateTimeZone('Asia/Tokyo');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($timezone)->modify('-1 day')->format('Y-m-d');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $firstPatient = $this->insertPatient($clinic, 'EligibleFirst');
        $secondPatient = $this->insertPatient($clinic, 'EligibleSecond');
        $waitingPatient = $this->insertPatient($clinic, 'StillWaiting');
        $otherLocationPatient = $this->insertPatient($clinic, 'OtherLocation');
        $yesterdayPatient = $this->insertPatient($clinic, 'Yesterday');
        $awaitingPatient = $this->insertPatient($clinic, 'AlreadyAwaiting');

        $firstCheckIn = $now->modify('-4 hours')->format('Y-m-d H:i:s');
        $secondCheckIn = $now->modify('-2 hours')->format('Y-m-d H:i:s');
        $first = $this->insertVisit($clinic, $location, $firstPatient, $clinician, $date, $firstCheckIn, 'consultation_completed');
        $second = $this->insertVisit($clinic, $location, $secondPatient, $clinician, $date, $secondCheckIn, 'consultation_completed');
        $this->insertVisit($clinic, $location, $waitingPatient, $clinician, $date, $firstCheckIn, 'waiting');
        $this->insertVisit($clinic, $otherLocation, $otherLocationPatient, $clinician, $date, $firstCheckIn, 'consultation_completed');
        $this->insertVisit($clinic, $location, $yesterdayPatient, $clinician, $yesterday, $firstCheckIn, 'consultation_completed');
        $this->insertVisit($clinic, $location, $awaitingPatient, $clinician, $date, $firstCheckIn, 'awaiting_payment');

        wp_set_current_user($secretary);
        $context = $this->dispatch('GET', self::CONTEXT, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $context->get_status(), 'Slice 1 context stays available — ' . $this->errorCode($context));
        self::assertTrue(
            (bool) ($this->payload($context)['can_issue_invoice'] ?? false),
            'context must expose the server-derived can_issue_invoice flag for an actor holding cpms_invoice_create in this Clinic'
        );

        $readOnlyBefore = $this->readOnlySnapshot();
        $this->capturedProjectionQueries = [];
        $capture = function (string $query): string {
            if (str_contains($query, 'AS visit_id') && str_contains($query, 'cpms_visits')) {
                $this->capturedProjectionQueries[] = $query;
            }
            return $query;
        };
        add_filter('query', $capture, PHP_INT_MAX);
        $eligible = $this->dispatch('GET', self::ELIGIBLE, [], $this->scopeHeaders($clinic, $location));
        remove_filter('query', $capture, PHP_INT_MAX);

        self::assertSame(200, $eligible->get_status(), 'authorized invoice-eligible read must resolve — ' . $this->errorCode($eligible));
        $data = $this->payload($eligible);
        self::assertSame($date, $data['date'] ?? null, 'operational day is the Location-local date');
        self::assertSame($location, (int) ($data['location_id'] ?? 0));
        self::assertFalse((bool) ($data['has_more'] ?? true));
        self::assertCount(1, $this->capturedProjectionQueries, 'one joined read query serves the complete eligible projection');
        self::assertCount(2, $data['visits'] ?? [], 'only CURRENT Location consultation_completed Visits of the Location-local day are listed');

        $expectedOrder = substr($firstCheckIn, 11) <= substr($secondCheckIn, 11) ? [$first, $second] : [$second, $first];
        self::assertSame(
            $expectedOrder,
            array_map(static fn (array $row): int => (int) $row['visit_id'], $data['visits']),
            'deterministic order follows the established slot-time/check-in asc then id asc policy'
        );

        $names = [];
        foreach ($data['visits'] as $row) {
            self::assertSame(
                ['visit_id', 'patient_name', 'clinician_name', 'operational_date', 'jalali_date', 'operational_time', 'visit_status'],
                array_keys($row),
                'projection is minimal and carries no identifier or financial field'
            );
            self::assertSame('consultation_completed', $row['visit_status']);
            self::assertSame('Dr Slice Two', $row['clinician_name']);
            self::assertSame($date, $row['operational_date']);
            self::assertNotSame('', (string) $row['jalali_date']);
            self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', (string) $row['operational_time']);
            $names[] = $row['patient_name'];
        }
        self::assertSame(['EligibleFirst Slice2', 'EligibleSecond Slice2'], $names, 'patient display names only');
        foreach (['StillWaiting', 'OtherLocation', 'Yesterday', 'AlreadyAwaiting'] as $excluded) {
            self::assertStringNotContainsString($excluded, (string) wp_json_encode($data), 'excluded visits must not appear anywhere in the payload');
        }

        self::assertSame($readOnlyBefore, $this->readOnlySnapshot(), 'the invoicing read performs no mutation of finance/visit/audit/job/notification data');
    }

    /**
     * Group 2 — authority and Location policy of both routes.
     */
    public function testBothRoutesFailClosedWithoutExistingAuthorityAndRequireExplicitLocation(): void
    {
        $org = $this->insertOrg('Slice2 authority org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secondLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $clinician = $this->insertClinician($clinic, 'Dr Authority');
        $patient = $this->insertPatient($clinic, 'Authority');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'consultation_completed');

        $manager = $this->makeUser('phase12_slice2_manager', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($manager, $clinic, 'cpms_manager');
        wp_set_current_user($manager);
        $managerRead = $this->dispatch('GET', self::ELIGIBLE, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(403, $managerRead->get_status());
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($managerRead));
        $managerIssue = $this->issue($visit, $clinic, $location);
        self::assertSame(403, $managerIssue->get_status(), 'issuance requires the existing cpms_invoice_create authority');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($managerIssue));

        // Accountant: existing finance role — keeps its existing invoice-create
        // authority on the delegated surface but is not module-eligible, and the
        // read surface keeps the Slice 1 read boundary.
        $accountant = $this->makeUser('phase12_slice2_accountant', RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountant, $clinic, 'cpms_accountant');
        self::assertFalse(StaffPortalShell::finance_module_eligible($accountant), 'accountant stays outside the Finance module read boundary');
        wp_set_current_user($accountant);
        $accountantRead = $this->dispatch('GET', self::ELIGIBLE, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(403, $accountantRead->get_status());
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($accountantRead));

        $secretary = $this->makeUser('phase12_slice2_ambiguous_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        wp_set_current_user($secretary);
        $ambiguousRead = $this->dispatch('GET', self::ELIGIBLE, [], $this->scopeHeaders($clinic));
        self::assertSame(400, $ambiguousRead->get_status(), 'two eligible Locations require an explicit trusted Location selector');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($ambiguousRead));
        $ambiguousIssue = $this->issue($visit, $clinic, null);
        self::assertSame(400, $ambiguousIssue->get_status(), 'issuance never auto-picks among N>1 Locations');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($ambiguousIssue));
        self::assertSame(
            'consultation_completed',
            (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visit]),
            'failing issuance leaves the Visit untouched'
        );

        $foreign = $this->insertLocation($this->insertClinic($org), 'Asia/Tehran');
        $foreignIssue = $this->dispatch(
            'POST',
            sprintf(self::ISSUE, $visit),
            [],
            $this->scopeHeaders($clinic, $foreign),
            ['items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1000]]]
        );
        self::assertSame(403, $foreignIssue->get_status(), 'a Location outside the eligible set is not acceptable authority');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($foreignIssue));

        self::assertFalse(StaffPortalShell::finance_module_eligible($manager), 'membership alone never enables the module');
        self::assertTrue(StaffPortalShell::finance_module_eligible($secretary), 'existing Finance module eligibility is unchanged');
    }

    /**
     * Group 3 — delegation, transition, refresh and server-authoritative totals.
     */
    public function testFirstIssuanceDelegatesTransitionsAndSurfacesTheVisitOnTheAwaitingPaymentBoard(): void
    {
        $org = $this->insertOrg('Slice2 issuance org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice2_issue_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Issuance');
        $patient = $this->insertPatient($clinic, 'Issuance');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'consultation_completed');

        wp_set_current_user($secretary);
        $response = $this->dispatch(
            'POST',
            sprintf(self::ISSUE, $visit),
            [],
            $this->scopeHeaders($clinic, $location),
            [
                'items' => [[
                    'description' => 'ویزیت و مشاوره',
                    'quantity' => 1,
                    'unit_price' => 500000,
                    'discount' => 999999,
                ]],
                'discount' => 999999,
                'tax' => 999999,
                'total' => 1,
            ]
        );
        self::assertSame(201, $response->get_status(), 'first issuance must delegate and return the existing invoice view — ' . $this->errorCode($response));
        $invoice = $this->payload($response);
        self::assertSame('open', $invoice['status'] ?? null);
        self::assertSame(500000.0, (float) ($invoice['subtotal'] ?? -1), 'subtotal is computed by the existing server-side calculation');
        self::assertSame(500000.0, (float) ($invoice['total'] ?? -1), 'client-supplied totals can never influence the invoice');
        self::assertSame(500000.0, (float) ($invoice['balance'] ?? -1));
        self::assertSame(0.0, (float) ($invoice['discount'] ?? -1), 'no invoice-level discount is accepted by this slice');
        self::assertSame(0.0, (float) ($invoice['tax'] ?? -1), 'no tax is accepted by this slice');
        self::assertSame('IRR', $invoice['currency'] ?? null);
        self::assertSame($location, (int) ($invoice['location_id'] ?? 0), 'invoice Location comes from the Visit, never from payload');
        self::assertSame($visit, (int) ($invoice['visit_id'] ?? 0));
        self::assertCount(1, $invoice['items'] ?? []);
        self::assertSame('ویزیت و مشاوره', $invoice['items'][0]['description'] ?? null);
        self::assertSame(500000.0, (float) ($invoice['items'][0]['unit_price'] ?? -1));
        self::assertSame(0.0, (float) ($invoice['items'][0]['discount'] ?? -1));

        self::assertSame(
            'awaiting_payment',
            (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visit]),
            'the existing transition must move the Visit out of consultation_completed'
        );
        $history = App::db()->fetchAll(
            'SELECT from_status, to_status, actor_role FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d ORDER BY id ASC',
            [$visit]
        );
        self::assertCount(1, $history, 'a first issuance writes exactly one existing status-history row');
        self::assertSame('consultation_completed', (string) $history[0]['from_status']);
        self::assertSame('awaiting_payment', (string) $history[0]['to_status'], 'VisitMachine V11 maps event invoice_ready directly to awaiting_payment');
        self::assertSame('system', (string) $history[0]['actor_role']);
        self::assertSame(
            1,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . " WHERE action = 'INVOICE_CREATE' AND resource_type = 'invoice' AND resource_id = %d",
                [(int) $invoice['id']]
            ),
            'the existing delegated path still audits INVOICE_CREATE exactly once'
        );

        $eligibleAfter = $this->dispatch('GET', self::ELIGIBLE, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $eligibleAfter->get_status());
        self::assertSame([], $this->payload($eligibleAfter)['visits'] ?? null, 'the issued Visit leaves the first-issuance list');

        $board = $this->dispatch('GET', self::BOARD, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $board->get_status());
        $rows = $this->payload($board)['visits'] ?? [];
        self::assertCount(1, $rows, 'the issued Visit appears on the existing Slice 1 awaiting-payment board');
        self::assertSame('awaiting_payment', $rows[0]['visit_status']);
        self::assertSame('500000.00', (string) ($rows[0]['invoice']['total'] ?? ''), 'the board reads the invoice issued through the existing service');

        $repeat = $this->dispatch(
            'POST',
            sprintf(self::ISSUE, $visit),
            [],
            $this->scopeHeaders($clinic, $location),
            ['items' => [['description' => 'ویزیت و مشاوره', 'quantity' => 1, 'unit_price' => 500000]]]
        );
        self::assertSame(409, $repeat->get_status(), 'no second issuance/re-issue path is exposed for an awaiting_payment Visit');
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errorCode($repeat));
        self::assertSame(
            1,
            (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_invoices') . ' WHERE visit_id = %d', [$visit]),
            'exactly one invoice exists for the Visit'
        );
    }

    /**
     * Group 4 — Clinic/Location ownership and first-issuance state guard.
     */
    public function testIssuanceFailsClosedForForeignClinicForeignLocationAndNonFirstIssuanceStates(): void
    {
        $org = $this->insertOrg('Slice2 guard org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $otherLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $foreignClinic = $this->insertClinic($org);
        $foreignLocation = $this->insertLocation($foreignClinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice2_guard_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $foreignClinician = $this->insertClinician($foreignClinic, 'Dr Foreign');
        $clinician = $this->insertClinician($clinic, 'Dr Guard');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $now = App::db()->nowUtcSql();

        $foreignPatient = $this->insertPatient($foreignClinic, 'Foreign');
        $foreignVisit = $this->insertVisit($foreignClinic, $foreignLocation, $foreignPatient, $foreignClinician, $date, $now, 'consultation_completed');
        $sameClinicOtherLocationPatient = $this->insertPatient($clinic, 'OtherLoc');
        $otherLocationVisit = $this->insertVisit($clinic, $otherLocation, $sameClinicOtherLocationPatient, $clinician, $date, $now, 'consultation_completed');
        $awaitingPatient = $this->insertPatient($clinic, 'Awaiting');
        $awaitingVisit = $this->insertVisit($clinic, $location, $awaitingPatient, $clinician, $date, $now, 'awaiting_payment');
        $waitingPatient = $this->insertPatient($clinic, 'Waiting');
        $waitingVisit = $this->insertVisit($clinic, $location, $waitingPatient, $clinician, $date, $now, 'waiting');
        $voidedPatient = $this->insertPatient($clinic, 'VoidedRecovery');
        $voidedVisit = $this->insertVisit($clinic, $location, $voidedPatient, $clinician, $date, $now, 'awaiting_payment');
        $this->insertInvoice($clinic, $location, $voidedPatient, $voidedVisit, $secretary, 'voided');

        wp_set_current_user($secretary);
        $foreignAttempt = $this->issue($foreignVisit, $clinic, $location);
        self::assertSame(404, $foreignAttempt->get_status(), 'a Visit of another Clinic is not disclosed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($foreignAttempt));
        $otherLocationAttempt = $this->issue($otherLocationVisit, $clinic, $location);
        self::assertSame(404, $otherLocationAttempt->get_status(), 'a Visit of another Location of the same Clinic is not disclosed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($otherLocationAttempt));
        $awaitingAttempt = $this->issue($awaitingVisit, $clinic, $location);
        self::assertSame(409, $awaitingAttempt->get_status());
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errorCode($awaitingAttempt));
        $waitingAttempt = $this->issue($waitingVisit, $clinic, $location);
        self::assertSame(409, $waitingAttempt->get_status());
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errorCode($waitingAttempt));

        // Recovery/re-issue for an awaiting_payment Visit with a voided invoice
        // is NOT exposed through the portal route …
        $voidedAttempt = $this->issue($voidedVisit, $clinic, $location);
        self::assertSame(409, $voidedAttempt->get_status());
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errorCode($voidedAttempt));
        self::assertSame(
            1,
            (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_invoices') . ' WHERE visit_id = %d', [$voidedVisit]),
            'the portal must not have created a recovery invoice'
        );

        // … while the existing shared D12 route keeps its current behaviour
        // (GREEN-today control): the backend recovery path itself is untouched.
        $shared = $this->dispatch(
            'POST',
            self::SHARED_ISSUE,
            [],
            $this->scopeHeaders($clinic, $location),
            ['items' => [['description' => 'بازیابی', 'quantity' => 1, 'unit_price' => 100000]]]
        );
        self::assertSame(201, $shared->get_status(), 'the existing shared issuance semantics for awaiting_payment remain available through the existing route — ' . $this->errorCode($shared));

        // Duplicate-active-invoice guard is preserved (GREEN-today control).
        $duplicate = $this->dispatch(
            'POST',
            self::SHARED_ISSUE,
            [],
            $this->scopeHeaders($clinic, $location),
            ['items' => [['description' => 'تکرار', 'quantity' => 1, 'unit_price' => 100000]]]
        );
        self::assertSame(409, $duplicate->get_status());
        self::assertSame('CLINIC_POLICY_VIOLATION', $this->errorCode($duplicate));

        self::assertSame('consultation_completed', (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$foreignVisit]));
        self::assertNull(App::db()->fetchRow('SELECT id FROM ' . App::db()->table('cpms_invoices') . ' WHERE visit_id = %d', [$otherLocationVisit]));
        self::assertNull(App::db()->fetchRow('SELECT id FROM ' . App::db()->table('cpms_invoices') . ' WHERE visit_id = %d', [$waitingVisit]));
    }

    /**
     * Group 6 — module template/UI contract (RED anchor).
     */
    public function testFinanceModuleTemplateExposesFirstIssuanceOnlyForCurrentLocationVisits(): void
    {
        $org = $this->insertOrg('Slice2 ui org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice2_ui_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Ui');
        $patient = $this->insertPatient($clinic, 'Ui');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'consultation_completed');

        $template = (string) file_get_contents((string) StaffPortalShell::finance_module_template_path());
        self::assertStringContainsString('invoice-eligible', $template, 'the module reads the bounded invoice-eligible surface');
        self::assertStringContainsString('finance-eligible', $template, 'the module exposes an eligible-row contract');
        self::assertStringContainsString('data-role="finance-issue-submit"', $template, 'the module exposes the first-issuance control');
        self::assertStringNotContainsString('card_pos', $template, 'no card/POS device integration may appear in this slice');
        foreach (['void_', 'voidReason', 'settle', 'waive', 'refund', 'adjustment', 'reissue'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $template, 'forbidden finance action vocabulary must not appear: ' . $forbidden);
        }

        wp_set_current_user($secretary);
        $html = $this->renderFinancePortal($secretary);
        self::assertStringContainsString('data-cpms-portal="staff"', $html);
        self::assertStringContainsString('data-cpms-staff-module="finance"', $html);
        self::assertStringContainsString('data-role="finance-board"', $html, 'the Slice 1 board stays mounted');
        self::assertStringContainsString('data-role="finance-eligible"', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(int $visitId, int $clinicId, ?int $locationId): WP_REST_Response
    {
        return $this->dispatch(
            'POST',
            sprintf(self::ISSUE, $visitId),
            [],
            $this->scopeHeaders($clinicId, $locationId),
            ['items' => [['description' => 'ویزیت', 'quantity' => 1, 'unit_price' => 100000]]]
        );
    }

    private function renderFinancePortal(int $userId): string
    {
        wp_set_current_user($userId);
        $url = StaffPortalShell::portal_url();
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?? '');
        $previousGet = $_GET;
        try {
            $this->go_to($path . ($query !== '' ? '?' . $query . '&' : '?') . 'cpms-module=finance');
            $_GET[StaffPortalShell::MODULE_PARAM] = StaffPortalShell::MODULE_FINANCE;
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
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinics', ['organization_id' => $orgId, 'name' => 'Slice2 Clinic', 'slug' => 'clinic-' . bin2hex(random_bytes(3)), 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now]), 'clinic fixture insert');
        return (int) $wpdb->insert_id;
    }

    private function insertLocation(int $clinicId, string $timezone): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_locations', ['clinic_id' => $clinicId, 'name' => 'Slice2 Location', 'slug' => 'location-' . bin2hex(random_bytes(3)), 'timezone' => $timezone, 'is_primary' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]), 'Location fixture insert');
        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', ['clinic_id' => $clinicId, 'mrn' => 'M-' . bin2hex(random_bytes(5)), 'first_name' => $tag, 'last_name' => 'Slice2', 'mobile' => '09' . random_int(1000000000, 9999999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]), 'patient fixture insert');
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

    private function insertInvoice(int $clinicId, int $locationId, int $patientId, int $visitId, int $actorId, string $status): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoices', [
            'clinic_id' => $clinicId, 'location_id' => $locationId, 'invoice_number' => 'INV-SLICE2-' . bin2hex(random_bytes(4)),
            'patient_id' => $patientId, 'visit_id' => $visitId, 'status' => $status, 'subtotal' => '100000.00',
            'total' => '100000.00', 'currency' => 'IRR', 'paid_amount' => '0.00', 'balance' => '100000.00',
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
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = [], ?array $body = null): WP_REST_Response
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
