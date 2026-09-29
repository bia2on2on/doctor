<?php
/**
 * Phase 12 Slice 4 — Staff Portal Finance: checkout for paid Visits at the
 * CURRENT trusted Location — TEST-ONLY RED.
 *
 * Owner-approved slice contract (bounded; docs/api/api-contract.md — Phase 12
 * Slice 4 section, written before implementation):
 *   Inside the EXISTING independent Staff Portal Finance module (Phase 12
 *   Slice 1 read-only awaiting-payment board + Slice 2 first issuance +
 *   Slice 3 manual capture), an actor with the EXISTING `cpms_queue_checkout`
 *   authority checks out a Visit whose persisted status is exactly `paid` at
 *   the CURRENT trusted operational Location of the trusted Clinic.
 *
 * Slice 4 delta on the existing surface (no new domain concept):
 *   - GET /clinic/v1/staff/portal/finance/paid — bounded paid/checkout-ready
 *     projection (CURRENT Location, Location-local day, exact `paid`,
 *     deterministic order, max 100 rows + truthful `has_more`, one joined
 *     query, minimal privacy-safe projection + settlement summary only);
 *   - POST /clinic/v1/staff/portal/finance/visits/{id}/checkout — the Visit id
 *     is a selector, never authority; before delegation the PERSISTED Visit
 *     must belong to the trusted Clinic AND the CURRENT trusted Location AND
 *     be exactly `paid`; the mutation delegates to the EXISTING
 *     VisitService::checkout(actor, visitId, null) — no second checkout
 *     engine, no waive reason, no new state/side effect;
 *   - the Slice 1 context gains the server-derived `can_check_out` UI hint;
 *   - the module template gains a paid/checkout-ready panel with a clear
 *     confirmation step, busy/double-submit guard and server-truth refresh.
 *
 * Deliberately OUT of scope (asserted as absent, not implemented): the waive/
 * free checkout path on this surface (the existing D16 backend route keeps its
 * unchanged behaviour, incl. its documented waiver path), refund, void,
 * adjustment, receipt, online payment, any real POS or card-reader
 * integration (no provider/device settings, SDK, discovery, polling or
 * abstraction), any second checkout engine, any new migration, role,
 * capability, dependency, framework or state machine.
 *
 * Authorization: the read route keeps the Slice 1 read contract
 * (`cpms_finance_read` + `cpms_invoice_read` + `cpms_queue_read`, each via the
 * existing global and same-Clinic layers). The mutation route requires the
 * nonce and the EXISTING `cpms_queue_checkout` capability (global +
 * Clinic-scoped), and the delegated service re-authorizes identically.
 * Membership alone is never authority; no accountant/manager access is
 * widened by this slice.
 *
 * CRITICAL Location guard (before any delegation): raw Clinic/Location/Visit
 * values are selectors only. A valid Location selector may choose among
 * eligible assigned Locations but never creates authority. Foreign Clinic,
 * same-Clinic foreign/unassigned/inactive Location and unknown Visit ids fail
 * closed with the established non-enumerating 404 `CLINIC_NOT_FOUND` parity
 * (or the established 403 `CLINIC_SCOPE_UNAVAILABLE` for an unavailable
 * scope). Nothing is written on any rejected path.
 *
 * Settlement truth: the V14 guard stays owned by the delegated service — a
 * server-side open/partial invoice balance fails checkout with 409
 * `CLINIC_NOT_SETTLED` (server-derived `open_invoices`/`balance`); the client
 * cannot override or submit settlement truth.
 *
 * INTENDED RED (test-only; no product bytes in this commit):
 *   - the paid board route does not exist (404 `rest_no_route`) → Groups 1–3,
 *     the re-read in Group 6;
 *   - the checkout route does not exist (404 `rest_no_route`) → Groups 4–9;
 *   - the context lacks `can_check_out` → Group 4/10;
 *   - the module template has no paid/checkout panel wiring → Group 10.
 * GREEN-today controls that must stay GREEN after the product change: the
 * Slice 1 awaiting-payment board projection (unchanged 9 keys), the existing
 * D16 shared checkout route (unchanged, incl. its waiver path) and the
 * existing `can_issue_invoice`/`can_capture_payment` context flags.
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

final class Phase12Slice4StaffFinanceCheckoutTest extends WP_UnitTestCase
{
    private const CONTEXT = '/clinic/v1/staff/portal/finance/context';
    private const PAID = '/clinic/v1/staff/portal/finance/paid';
    private const CHECKOUT = '/clinic/v1/staff/portal/finance/visits/%d/checkout';
    private const BOARD = '/clinic/v1/staff/portal/finance/awaiting-payment';
    private const SHARED_CHECKOUT = '/clinic/v1/visits/%d/checkout';

    /** The exact Slice 4 paid-board projection: minimal, no identifier or number. */
    private const ROW_KEYS = [
        'visit_id',
        'patient_name',
        'clinician_name',
        'operational_date',
        'jalali_date',
        'operational_time',
        'visit_status',
        'invoice',
    ];

    /** The Slice 1/3 awaiting-payment board projection — must stay unchanged. */
    private const BOARD_ROW_KEYS = [
        'visit_id',
        'patient_name',
        'clinician_name',
        'operational_date',
        'jalali_date',
        'operational_time',
        'visit_status',
        'invoice_id',
        'invoice',
    ];

    /** Finance/device vocabulary this slice must NOT grow into the module. */
    private const FORBIDDEN_MODULE_VOCABULARY = [
        'waive', 'معافیت', 'void', 'refund', 'adjustment', 'reissue', 'online',
        'terminal', 'device', 'sdk', 'serial', 'usb', 'nfc', 'bluetooth', 'websocket', 'merchant',
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
        SystemClinicResolver::flush();
        parent::tearDown();
    }

    /**
     * Group 1 — the paid board read: CURRENT trusted Location isolation,
     * Location-local day, exact `paid`, deterministic order, one joined
     * query, minimal privacy-safe projection + settlement summary only.
     */
    public function testPaidBoardReadsExactlyTheCurrentLocationPaidProjection(): void
    {
        $org = $this->insertOrg('Slice4 board org');
        $clinic = $this->insertClinic($org, 'Slice4 Clinic');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $otherLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $foreignClinic = $this->insertClinic($org, 'Slice4 Foreign Clinic');
        $secretary = $this->makeUser('phase12_slice4_board_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Slice Four');
        $timezone = new \DateTimeZone('Asia/Tehran');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($timezone)->modify('-1 day')->format('Y-m-d');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $settledPatient = $this->insertPatient($clinic, 'BoardSettled');
        $barePatient = $this->insertPatient($clinic, 'BoardBare');
        $otherLocationPatient = $this->insertPatient($clinic, 'BoardOtherLocation');
        $foreignPatient = $this->insertPatient($foreignClinic, 'BoardForeignClinic');
        $yesterdayPatient = $this->insertPatient($clinic, 'BoardYesterday');
        $awaitingPatient = $this->insertPatient($clinic, 'BoardAwaiting');
        $consultingPatient = $this->insertPatient($clinic, 'BoardConsulting');

        $firstCheckIn = $now->modify('-4 hours')->format('Y-m-d H:i:s');
        $secondCheckIn = $now->modify('-2 hours')->format('Y-m-d H:i:s');
        $settledVisit = $this->insertVisit($clinic, $location, $settledPatient, $clinician, $date, $firstCheckIn, 'paid');
        $bareVisit = $this->insertVisit($clinic, $location, $barePatient, $clinician, $date, $secondCheckIn, 'paid');
        $otherLocationVisit = $this->insertVisit($clinic, $otherLocation, $otherLocationPatient, $clinician, $date, $firstCheckIn, 'paid');
        $this->insertVisit($foreignClinic, $this->insertLocation($foreignClinic, 'Asia/Tehran'), $foreignPatient, $clinician, $date, $firstCheckIn, 'paid');
        $this->insertVisit($clinic, $location, $yesterdayPatient, $clinician, $yesterday, $firstCheckIn, 'paid');
        $this->insertVisit($clinic, $location, $awaitingPatient, $clinician, $date, $firstCheckIn, 'awaiting_payment');
        $this->insertVisit($clinic, $location, $consultingPatient, $clinician, $date, $firstCheckIn, 'in_consultation');

        // The settled invoice the row summarizes; an open invoice that belongs
        // to another Location of the same Clinic must not be claimed as the
        // CURRENT-Location row's invoice.
        $this->insertInvoice($clinic, $location, $settledPatient, $settledVisit, $secretary, 'paid', '100000.00', '100000.00', '0.00');
        $this->insertInvoice($clinic, $otherLocation, $otherLocationPatient, $otherLocationVisit, $secretary, 'open', '50000.00', '0.00', '50000.00');

        $mobile = (string) App::db()->fetchValue('SELECT mobile FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d', [$settledPatient]);
        $mrn = (string) App::db()->fetchValue('SELECT mrn FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d', [$settledPatient]);

        wp_set_current_user($secretary);
        $context = $this->dispatch('GET', self::CONTEXT, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $context->get_status(), 'Slice 1 context stays available — ' . $this->errorCode($context));
        // GREEN-today control: the existing context flags are unchanged.
        self::assertTrue((bool) ($this->payload($context)['can_issue_invoice'] ?? false), 'existing can_issue_invoice flag stays');
        self::assertTrue((bool) ($this->payload($context)['can_capture_payment'] ?? false), 'existing can_capture_payment flag stays');
        self::assertTrue((bool) ($this->payload($context)['can_check_out'] ?? false), 'context must expose the server-derived can_check_out flag for a secretary holding cpms_queue_checkout in this Clinic');

        $readOnlyBefore = $this->readOnlySnapshot();
        $projectionQueries = 0;
        $capture = function (string $query) use (&$projectionQueries): string {
            if (str_contains($query, 'AS visit_id') && str_contains($query, 'cpms_visits')) {
                $projectionQueries++;
            }
            return $query;
        };
        add_filter('query', $capture, PHP_INT_MAX);
        $board = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $location));
        remove_filter('query', $capture, PHP_INT_MAX);

        self::assertSame(200, $board->get_status(), 'authorized paid board read must resolve — ' . $this->errorCode($board));
        $data = $this->payload($board);
        self::assertSame($date, $data['date'] ?? null, 'operational day is the Location-local date');
        self::assertSame($location, (int) ($data['location_id'] ?? 0));
        self::assertFalse((bool) ($data['has_more'] ?? true));
        self::assertSame(1, $projectionQueries, 'one joined query serves the complete paid projection (no N+1)');
        self::assertCount(2, $data['visits'] ?? [], 'only the CURRENT Location paid Visits of the Location-local day are listed');

        $expectedOrder = substr($firstCheckIn, 11) <= substr($secondCheckIn, 11) ? [$settledVisit, $bareVisit] : [$bareVisit, $settledVisit];
        self::assertSame(
            $expectedOrder,
            array_map(static fn (array $row): int => (int) $row['visit_id'], $data['visits']),
            'deterministic order follows the established slot-time/check-in asc then id asc policy'
        );

        $rows = [];
        foreach ($data['visits'] as $row) {
            self::assertSame(self::ROW_KEYS, array_keys($row), 'paid projection is minimal and carries no identifier, number or extra finance field');
            self::assertSame('paid', $row['visit_status'], 'the board lists exactly the persisted paid state');
            self::assertSame('Dr Slice Four', $row['clinician_name']);
            self::assertSame($date, $row['operational_date']);
            self::assertNotSame('', (string) $row['jalali_date'], 'Jalali date is presentation-only');
            self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', (string) $row['operational_time']);
            $rows[(string) $row['patient_name']] = $row;
        }
        self::assertArrayHasKey('BoardSettled Slice4', $rows);
        self::assertArrayHasKey('BoardBare Slice4', $rows);

        $summary = $rows['BoardSettled Slice4']['invoice'];
        self::assertIsArray($summary, 'a legitimately linked settled invoice is summarized for checkout confirmation');
        self::assertSame(['status', 'total', 'paid', 'remaining', 'currency'], array_keys($summary), 'settlement summary is minimal');
        self::assertSame('paid', (string) $summary['status']);
        self::assertSame('100000.00', (string) $summary['total']);
        self::assertSame('100000.00', (string) $summary['paid']);
        self::assertSame('0.00', (string) $summary['remaining']);
        self::assertSame('IRR', (string) $summary['currency']);
        self::assertNull($rows['BoardBare Slice4']['invoice'], 'no legitimately linked invoice ⇒ null, no financial value inferred');

        $encoded = (string) wp_json_encode($data);
        self::assertStringNotContainsString($mobile, $encoded, 'patient mobile never appears in the paid projection');
        self::assertStringNotContainsString($mrn, $encoded, 'patient mrn never appears in the paid projection');
        self::assertStringNotContainsString('INV-SLICE4-', $encoded, 'invoice number never appears in the paid projection');
        foreach (['patient_id', 'national_id', 'mobile', 'mrn', 'invoice_id', 'payment_number'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, 'projection keeps no ' . $forbidden . ' field');
        }
        foreach (['BoardOtherLocation', 'BoardForeignClinic', 'BoardYesterday', 'BoardAwaiting', 'BoardConsulting'] as $excluded) {
            self::assertStringNotContainsString($excluded, $encoded, $excluded . ' must not appear anywhere in the payload');
        }

        self::assertSame($readOnlyBefore, $this->readOnlySnapshot(), 'the paid board read performs no mutation');
    }

    /**
     * Group 2 — bounded read: max 100 returned rows + truthful `has_more`.
     */
    public function testPaidBoardStopsAtOneHundredRowsAndSignalsHasMore(): void
    {
        $org = $this->insertOrg('Slice4 bound org');
        $clinic = $this->insertClinic($org, 'Slice4 Bound Clinic');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice4_bound_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Bound');
        $patient = $this->insertPatient($clinic, 'Bound');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');

        for ($i = 0; $i < 101; $i++) {
            $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'paid');
        }

        wp_set_current_user($secretary);
        $board = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $board->get_status(), 'authorized bounded paid read must resolve — ' . $this->errorCode($board));
        $data = $this->payload($board);
        self::assertCount(100, $data['visits'] ?? [], 'at most 100 rows are returned');
        self::assertTrue((bool) ($data['has_more'] ?? false), 'has_more truthfully signals truncation');
        foreach ($data['visits'] as $row) {
            self::assertSame('paid', $row['visit_status']);
        }
    }

    /**
     * Group 3 — the established strict 0/1/N Location policy on both routes.
     */
    public function testPaidRoutesApplyTheEstablishedZeroOneNLocationPolicy(): void
    {
        $org = $this->insertOrg('Slice4 policy org');
        $clinic = $this->insertClinic($org, 'Slice4 Policy Clinic');
        $first = $this->insertLocation($clinic, 'Asia/Tehran');
        $this->insertLocation($clinic, 'Asia/Tehran');
        $this->insertLocation($clinic, 'Asia/Tehran');
        $inactive = $this->insertLocation($clinic, 'Asia/Tehran');
        $this->updateLocationActive($inactive, 0);
        $foreignClinic = $this->insertClinic($org, 'Slice4 Policy Foreign');
        $foreignLocation = $this->insertLocation($foreignClinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice4_policy_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Policy');
        $patient = $this->insertPatient($clinic, 'Policy');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $visit = $this->insertVisit($clinic, $first, $patient, $clinician, $date, App::db()->nowUtcSql(), 'paid');

        wp_set_current_user($secretary);

        // N>1 without a selector never auto-resolves: 400 location_required.
        $ambiguousRead = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic));
        self::assertSame(400, $ambiguousRead->get_status(), 'two+ eligible Locations require an explicit trusted Location selector (read)');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($ambiguousRead));
        $ambiguousCheckout = $this->dispatch('POST', sprintf(self::CHECKOUT, $visit), [], $this->scopeHeaders($clinic));
        self::assertSame(400, $ambiguousCheckout->get_status(), 'checkout never auto-picks among N>1 Locations');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($ambiguousCheckout));

        // An explicit valid selector resolves; the row is visible.
        $selected = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $first));
        self::assertSame(200, $selected->get_status(), 'an explicit eligible selector resolves the CURRENT Location (read) — ' . $this->errorCode($selected));
        self::assertCount(1, $this->payload($selected)['visits'] ?? []);

        // A Location of another Clinic is never an acceptable selector.
        $foreignRead = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $foreignLocation));
        self::assertSame(403, $foreignRead->get_status(), 'a foreign-Clinic Location selector is not acceptable authority (read)');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($foreignRead));
        $foreignCheckout = $this->dispatch('POST', sprintf(self::CHECKOUT, $visit), [], $this->scopeHeaders($clinic, $foreignLocation));
        self::assertSame(403, $foreignCheckout->get_status(), 'a foreign-Clinic Location selector is not acceptable authority (checkout)');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($foreignCheckout));

        // An inactive Location is never an acceptable selector.
        $inactiveRead = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $inactive));
        self::assertSame(403, $inactiveRead->get_status(), 'an inactive Location selector is not acceptable authority (read)');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($inactiveRead));

        // Zero eligible Locations: the read returns an empty board, the
        // mutation fails closed.
        $emptyClinic = $this->insertClinic($org, 'Slice4 Policy Empty');
        $emptyLocation = $this->insertLocation($emptyClinic, 'Asia/Tehran');
        $this->updateLocationActive($emptyLocation, 0);
        cpms_test_seed_membership($secretary, $emptyClinic, 'cpms_secretary');
        $emptyRead = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($emptyClinic));
        self::assertSame(200, $emptyRead->get_status(), 'zero eligible Locations ⇒ empty board (read) — ' . $this->errorCode($emptyRead));
        $emptyData = $this->payload($emptyRead);
        self::assertSame([], $emptyData['visits'] ?? null);
        self::assertFalse((bool) ($emptyData['has_more'] ?? true));
        $emptyCheckout = $this->dispatch('POST', sprintf(self::CHECKOUT, $visit), [], $this->scopeHeaders($emptyClinic));
        self::assertSame(403, $emptyCheckout->get_status(), 'zero eligible Locations ⇒ checkout fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($emptyCheckout));

        self::assertSame(
            'paid',
            (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visit]),
            'failing checkout leaves the Visit untouched'
        );
    }

    /**
     * Group 4 — nonce + EXISTING capability + active membership; membership
     * alone is never enough and no role is widened by this slice.
     */
    public function testCheckoutRequiresExistingQueueCheckoutAuthorityAndActiveMembership(): void
    {
        $org = $this->insertOrg('Slice4 authority org');
        $clinic = $this->insertClinic($org, 'Slice4 Authority Clinic');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $clinician = $this->insertClinician($clinic, 'Dr Authority4');
        $patient = $this->insertPatient($clinic, 'Authority4');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'paid');

        $secretary = $this->makeUser('phase12_slice4_auth_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $doctor = $this->makeUser('phase12_slice4_auth_doctor', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($doctor, $clinic, 'cpms_doctor');
        $accountant = $this->makeUser('phase12_slice4_auth_accountant', RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountant, $clinic, 'cpms_accountant');
        $manager = $this->makeUser('phase12_slice4_auth_manager', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($manager, $clinic, 'cpms_manager');
        $noMembership = $this->makeUser('phase12_slice4_auth_nomember', RolesAndCapabilities::ROLE_SECRETARY);

        // 1) nonce is mandatory.
        wp_set_current_user($secretary);
        $noNonce = $this->dispatch('POST', sprintf(self::CHECKOUT, $visit), [], $this->scopeHeaders($clinic, $location), null, [], false);
        self::assertSame(403, $noNonce->get_status());
        self::assertSame('CLINIC_INVALID_NONCE', $this->errorCode($noNonce));

        // 2) Doctor: module reader (existing three read caps) but NO
        //    cpms_queue_checkout — read stays available, checkout denied,
        //    hint false. Membership alone is not authority.
        wp_set_current_user($doctor);
        $doctorRead = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $doctorRead->get_status(), 'the doctor keeps the Slice 1 module read boundary — ' . $this->errorCode($doctorRead));
        $doctorContext = $this->dispatch('GET', self::CONTEXT, [], $this->scopeHeaders($clinic, $location));
        self::assertFalse((bool) ($this->payload($doctorContext)['can_check_out'] ?? true), 'can_check_out is false without cpms_queue_checkout');
        $doctorCheckout = $this->dispatch('POST', sprintf(self::CHECKOUT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(403, $doctorCheckout->get_status(), 'checkout requires the existing cpms_queue_checkout capability');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($doctorCheckout));

        // 3) Accountant: outside the module read boundary (no cpms_queue_read)
        //    and without checkout authority — unchanged by this slice.
        self::assertFalse(StaffPortalShell::finance_module_eligible($accountant), 'accountant stays outside the Finance module read boundary');
        wp_set_current_user($accountant);
        $accountantRead = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(403, $accountantRead->get_status());
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($accountantRead));
        $accountantCheckout = $this->dispatch('POST', sprintf(self::CHECKOUT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(403, $accountantCheckout->get_status());
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($accountantCheckout));

        // 4) Manager: not a module reader (no finance read caps) — unchanged.
        self::assertFalse(StaffPortalShell::finance_module_eligible($manager), 'membership alone never enables the module');
        wp_set_current_user($manager);
        $managerRead = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(403, $managerRead->get_status());
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($managerRead));

        // 5) A secretary WITH the global capability but WITHOUT an active
        //    membership for this Clinic is not authority: the trusted scope
        //    cannot be established (fail closed, non-enumerating).
        wp_set_current_user($noMembership);
        $nomemberCheckout = $this->dispatch('POST', sprintf(self::CHECKOUT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(403, $nomemberCheckout->get_status(), 'membership is required to establish the trusted Clinic scope');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($nomemberCheckout));
        $nomemberRead = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(403, $nomemberRead->get_status());
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($nomemberRead));

        self::assertTrue(StaffPortalShell::finance_module_eligible($secretary), 'existing Finance module eligibility is unchanged');
        self::assertSame(
            'paid',
            (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visit]),
            'denied checkout attempts leave the Visit untouched'
        );
    }

    /**
     * Group 5 — the Visit id is a selector, never authority: foreign Clinic,
     * same-Clinic foreign Location and unknown ids share the established
     * non-enumerating 404 parity and write nothing.
     */
    public function testCheckoutSelectorCannotEscapeTrustedClinicOrCurrentLocation(): void
    {
        $org = $this->insertOrg('Slice4 isolation org');
        $clinic = $this->insertClinic($org, 'Slice4 Isolation Clinic');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $otherLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $foreignClinic = $this->insertClinic($org, 'Slice4 Isolation Foreign');
        $foreignLocation = $this->insertLocation($foreignClinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice4_iso_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Isolation');
        $clinician2 = $this->insertClinician($foreignClinic, 'Dr Isolation Foreign');
        $patient = $this->insertPatient($clinic, 'Isolation');
        $foreignPatient = $this->insertPatient($foreignClinic, 'IsolationForeign');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');

        $ownVisit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'paid');
        $otherLocationVisit = $this->insertVisit($clinic, $otherLocation, $patient, $clinician, $date, App::db()->nowUtcSql(), 'paid');
        $foreignVisit = $this->insertVisit($foreignClinic, $foreignLocation, $foreignPatient, $clinician2, $date, App::db()->nowUtcSql(), 'paid');

        wp_set_current_user($secretary);
        $before = $this->readOnlySnapshot();
        $historyBefore = $this->historyCount();

        $cases = [
            'same-Clinic other-Location Visit' => $otherLocationVisit,
            'foreign-Clinic Visit' => $foreignVisit,
            'unknown Visit id' => 999999999,
        ];
        foreach ($cases as $label => $target) {
            $response = $this->dispatch('POST', sprintf(self::CHECKOUT, $target), [], $this->scopeHeaders($clinic, $location));
            self::assertSame(404, $response->get_status(), $label . ' selector must fail closed with 404 parity');
            self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response), $label . ' selector must not disclose existence');
        }

        self::assertSame($before, $this->readOnlySnapshot(), 'no rejected checkout path performs any mutation');
        self::assertSame($historyBefore, $this->historyCount(), 'no rejected checkout path appends history');
        foreach ([$ownVisit, $otherLocationVisit, $foreignVisit] as $visitId) {
            self::assertSame(
                'paid',
                (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitId]),
                'rejected selectors leave every Visit untouched'
            );
        }
    }

    /**
     * Group 6 — the valid journey: delegation to the EXISTING service, the
     * established V14 side effects, the terminal state, server-truth refresh
     * (the row leaves the paid board) and zero finance mutation.
     */
    public function testValidPaidCheckoutDelegatesToTheExistingServiceAndPersistsTheTerminalState(): void
    {
        $org = $this->insertOrg('Slice4 journey org');
        $clinic = $this->insertClinic($org, 'Slice4 Journey Clinic');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice4_journey_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Journey');
        $patient = $this->insertPatient($clinic, 'Journey');
        $otherPatient = $this->insertPatient($clinic, 'JourneyOther');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');

        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'paid');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'paid', '100000.00', '100000.00', '0.00');
        $this->insertPayment($clinic, $invoice, $patient, '100000.00', 'cash');
        $otherVisit = $this->insertVisit($clinic, $location, $otherPatient, $clinician, $date, App::db()->nowUtcSql(), 'paid');
        $this->insertInvoice($clinic, $location, $otherPatient, $otherVisit, $secretary, 'paid', '50000.00', '50000.00', '0.00');

        wp_set_current_user($secretary);
        $invoiceBefore = $this->invoiceRow($invoice);
        $paymentsBefore = $this->paymentCount($invoice);
        $historyBefore = $this->historyCount();
        $auditBefore = $this->auditCount('VISIT_CHECK_OUT');

        $response = $this->dispatch('POST', sprintf(self::CHECKOUT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $response->get_status(), 'valid paid checkout must succeed — ' . $this->errorCode($response));
        $data = $this->payload($response);
        self::assertSame($visit, (int) ($data['id'] ?? 0), 'the delegated visit view identifies the checked-out Visit');
        self::assertSame('checked_out', (string) ($data['status'] ?? ''), 'success uses the existing paid → checked_out transition');
        self::assertNotSame(null, $data['checked_out_at'] ?? null, 'checked_out_at is stamped by the reused service');
        self::assertFalse((bool) ($data['active'] ?? true), 'the Visit becomes inactive');

        $row = $this->visitRow($visit);
        self::assertSame('checked_out', (string) $row['status'], 'persisted terminal state');
        self::assertSame(0, (int) $row['active'], 'persisted inactive flag');
        self::assertNotSame('', (string) $row['checked_out_at'], 'persisted checked_out_at stamp');

        $history = $this->visitHistory($visit);
        self::assertCount(1, $history, 'exactly one history row is appended by the checkout');
        self::assertSame('paid', (string) $history[0]['from_status']);
        self::assertSame('checked_out', (string) $history[0]['to_status']);
        self::assertSame('secretary', (string) $history[0]['actor_role']);
        self::assertSame($secretary, (int) $history[0]['actor_wp_user_id']);
        self::assertSame($historyBefore + 1, $this->historyCount(), 'no other history row is written');
        self::assertSame($auditBefore + 1, $this->auditCount('VISIT_CHECK_OUT'), 'exactly one VISIT_CHECK_OUT audit row');

        self::assertSame($invoiceBefore, $this->invoiceRow($invoice), 'checkout does NOT mutate the invoice');
        self::assertSame($paymentsBefore, $this->paymentCount($invoice), 'checkout does NOT create or touch payments');

        // Server-truth refresh: the row leaves the paid board, the unrelated
        // paid row stays.
        $board = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $board->get_status());
        $ids = array_map(static fn (array $r): int => (int) $r['visit_id'], $this->payload($board)['visits'] ?? []);
        self::assertNotContains($visit, $ids, 'the checked-out Visit is removed from the paid board on server-truth refresh');
        self::assertContains($otherVisit, $ids, 'the unrelated paid Visit is untouched');
    }

    /**
     * Group 7 — settlement truth: the V14 guard stays owned by the delegated
     * service; client-supplied money/status can never override it.
     */
    public function testCheckoutFailsClosedWhileASettlementBalanceRemainsAndIgnoresClientMoney(): void
    {
        $org = $this->insertOrg('Slice4 settlement org');
        $clinic = $this->insertClinic($org, 'Slice4 Settlement Clinic');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice4_settle_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Settlement');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');

        $openPatient = $this->insertPatient($clinic, 'SettleOpen');
        $openVisit = $this->insertVisit($clinic, $location, $openPatient, $clinician, $date, App::db()->nowUtcSql(), 'paid');
        $openInvoice = $this->insertInvoice($clinic, $location, $openPatient, $openVisit, $secretary, 'open', '100000.00', '0.00', '100000.00');

        $partialPatient = $this->insertPatient($clinic, 'SettlePartial');
        $partialVisit = $this->insertVisit($clinic, $location, $partialPatient, $clinician, $date, App::db()->nowUtcSql(), 'paid');
        $partialInvoice = $this->insertInvoice($clinic, $location, $partialPatient, $partialVisit, $secretary, 'partial', '100000.00', '30000.00', '70000.00');
        $this->insertPayment($clinic, $partialInvoice, $partialPatient, '30000.00', 'cash');

        wp_set_current_user($secretary);

        $openBefore = $this->readOnlySnapshot();
        $openResponse = $this->dispatch('POST', sprintf(self::CHECKOUT, $openVisit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(409, $openResponse->get_status(), 'an open invoice balance blocks checkout (V14)');
        self::assertSame('CLINIC_NOT_SETTLED', $this->errorCode($openResponse));
        $openData = $this->errorData($openResponse);
        self::assertSame(1, (int) ($openData['open_invoices'] ?? 0), 'the server-derived open invoice count is reported');
        self::assertGreaterThan(0, (float) ($openData['balance'] ?? 0), 'the server-derived balance is reported');

        // Client-supplied settlement truth is never forwarded.
        $hostile = $this->dispatch(
            'POST',
            sprintf(self::CHECKOUT, $openVisit),
            [],
            $this->scopeHeaders($clinic, $location),
            [
                'status' => 'paid',
                'balance' => '0.00',
                'settled' => true,
                'amount' => 100000,
                'invoice_id' => $openInvoice,
            ]
        );
        self::assertSame(409, $hostile->get_status(), 'client-supplied settlement fields never become authority');
        self::assertSame('CLINIC_NOT_SETTLED', $this->errorCode($hostile));

        $partialResponse = $this->dispatch('POST', sprintf(self::CHECKOUT, $partialVisit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(409, $partialResponse->get_status(), 'a partial invoice balance blocks checkout (V14)');
        self::assertSame('CLINIC_NOT_SETTLED', $this->errorCode($partialResponse));
        self::assertSame(70000.0, (float) $this->errorData($partialResponse)['balance'] ?? 0.0, 'the server-derived remaining balance is reported');

        self::assertSame($openBefore, $this->readOnlySnapshot(), 'no blocked checkout path performs any mutation');
        foreach ([$openVisit, $partialVisit] as $visitId) {
            self::assertSame(
                'paid',
                (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitId]),
                'a blocked checkout leaves the Visit in paid'
            );
        }

        // A matching client selector in the body is accepted but never
        // authority; a DISAGREEING selector fails closed before the route.
        $cleanPatient = $this->insertPatient($clinic, 'SettleClean');
        $cleanVisit = $this->insertVisit($clinic, $location, $cleanPatient, $clinician, $date, App::db()->nowUtcSql(), 'paid');
        $cleanInvoice = $this->insertInvoice($clinic, $location, $cleanPatient, $cleanVisit, $secretary, 'paid', '1000.00', '1000.00', '0.00');
        $matching = $this->dispatch(
            'POST',
            sprintf(self::CHECKOUT, $cleanVisit),
            [],
            $this->scopeHeaders($clinic, $location),
            ['clinic_id' => $clinic, 'location_id' => $location]
        );
        self::assertSame(200, $matching->get_status(), 'matching body selectors are ignored, headers establish the trusted scope — ' . $this->errorCode($matching));
        self::assertSame('checked_out', (string) $this->visitRow($cleanVisit)['status']);
        self::assertSame($cleanInvoice, (int) $this->invoiceRow($cleanInvoice)['id'], 'the body never touched the invoice');

        $foreignClinic = $this->insertClinic($org, 'Slice4 Settle Foreign');
        $disagreeing = $this->dispatch(
            'POST',
            sprintf(self::CHECKOUT, $cleanVisit),
            [],
            $this->scopeHeaders($clinic, $location),
            ['clinic_id' => $foreignClinic]
        );
        self::assertSame(422, $disagreeing->get_status(), 'a body selector disagreeing with the trusted header fails closed before the route');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($disagreeing));
    }

    /**
     * Group 8 — exact state: only persisted `paid` is acceptable; wrong states
     * keep the established semantics; the portal never accepts a waive reason
     * while the EXISTING shared D16 route keeps its unchanged behaviour.
     */
    public function testOnlyPersistedPaidStateIsAcceptedAndWrongStatesKeepEstablishedSemantics(): void
    {
        $org = $this->insertOrg('Slice4 state org');
        $clinic = $this->insertClinic($org, 'Slice4 State Clinic');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice4_state_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr State');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $now = App::db()->nowUtcSql();

        $states = ['consultation_completed', 'awaiting_payment', 'in_consultation', 'waiting', 'called', 'checked_out', 'cancelled', 'skipped'];
        $visits = [];
        foreach ($states as $state) {
            $patient = $this->insertPatient($clinic, 'State' . ucfirst($state));
            $visits[$state] = $this->insertVisit($clinic, $location, $patient, $clinician, $date, $now, $state);
        }
        $waivePatient = $this->insertPatient($clinic, 'StateWaive');
        $waiveVisit = $this->insertVisit($clinic, $location, $waivePatient, $clinician, $date, $now, 'awaiting_payment');

        wp_set_current_user($secretary);
        $historyBefore = $this->historyCount();
        $auditBefore = $this->auditCount('VISIT_CHECK_OUT');

        foreach ($states as $state) {
            $response = $this->dispatch('POST', sprintf(self::CHECKOUT, $visits[$state]), [], $this->scopeHeaders($clinic, $location));
            self::assertSame(409, $response->get_status(), $state . ' must not be accepted as paid checkout');
            self::assertSame('CLINIC_INVALID_TRANSITION', $this->errorCode($response), $state . ' keeps the established invalid-transition semantics');
            self::assertSame($state, (string) ($this->errorData($response)['visit_status'] ?? ''), $state . ' is reported server-side');
            self::assertSame(
                $state,
                (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visits[$state]]),
                $state . ' Visit stays untouched'
            );
        }

        // The portal NEVER accepts a waive reason: an awaiting_payment Visit
        // with a waiver body is rejected exactly like any wrong state.
        $waive = $this->dispatch(
            'POST',
            sprintf(self::CHECKOUT, $waiveVisit),
            [],
            $this->scopeHeaders($clinic, $location),
            ['waive_invoice' => ['reason' => 'معافیت از پرداخت']]
        );
        self::assertSame(409, $waive->get_status(), 'the portal surface never exposes the waive path');
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errorCode($waive));
        self::assertSame('awaiting_payment', (string) $this->errorData($waive)['visit_status'] ?? '');
        self::assertSame(
            'awaiting_payment',
            (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$waiveVisit]),
            'the waiver body is not honoured on this surface'
        );

        self::assertSame($historyBefore, $this->historyCount(), 'no wrong-state attempt appends history');
        self::assertSame($auditBefore, $this->auditCount('VISIT_CHECK_OUT'), 'no wrong-state attempt appends checkout audit');

        // GREEN-today control: the EXISTING shared D16 route keeps its
        // unchanged behaviour, including the documented waiver path.
        $sharedResponse = $this->dispatch(
            'POST',
            sprintf(self::SHARED_CHECKOUT, $waiveVisit),
            [],
            $this->scopeHeaders($clinic, $location),
            ['waive_invoice' => ['reason' => 'معافیت از پرداخت']]
        );
        self::assertSame(200, $sharedResponse->get_status(), 'the existing D16 route is unchanged — ' . $this->errorCode($sharedResponse));
        self::assertSame('checked_out', (string) ($this->payload($sharedResponse)['status'] ?? ''), 'the existing D16 waiver path still works on the shared route only');
    }

    /**
     * Group 9 — duplicate checkout fails through the existing terminal-state
     * behaviour with no duplicate history/audit mutation.
     */
    public function testDuplicateCheckoutFailsOnceAndWritesNoSecondHistoryOrAudit(): void
    {
        $org = $this->insertOrg('Slice4 duplicate org');
        $clinic = $this->insertClinic($org, 'Slice4 Duplicate Clinic');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice4_dup_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Duplicate');
        $patient = $this->insertPatient($clinic, 'Duplicate');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');

        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'paid');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'paid', '200000.00', '200000.00', '0.00');
        $invoiceBefore = $this->invoiceRow($invoice);

        wp_set_current_user($secretary);
        $first = $this->dispatch('POST', sprintf(self::CHECKOUT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $first->get_status(), 'the first checkout succeeds — ' . $this->errorCode($first));

        $history = $this->visitHistory($visit);
        self::assertCount(1, $history, 'the first checkout appends exactly one history row');

        $second = $this->dispatch('POST', sprintf(self::CHECKOUT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(409, $second->get_status(), 'the duplicate checkout fails through the existing terminal-state behaviour');
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errorCode($second));
        self::assertSame('checked_out', (string) ($this->errorData($second)['from'] ?? $this->errorData($second)['visit_status'] ?? ''), 'the duplicate reports the terminal state');

        self::assertCount(1, $this->visitHistory($visit), 'no duplicate history row is appended');
        self::assertSame(1, $this->auditCount('VISIT_CHECK_OUT'), 'no duplicate audit row is appended');
        self::assertSame('checked_out', (string) $this->visitRow($visit)['status']);
        self::assertSame($invoiceBefore, $this->invoiceRow($invoice), 'the duplicate attempt does not touch finance data');
    }

    /**
     * Group 10 — the module template offers the paid/checkout-ready panel with
     * the existing server-truth refresh wiring and NO waive/refund/void/
     * adjustment/online/device vocabulary; the Slice 1 board projection stays
     * byte-for-byte the Slice 3 shape (GREEN-today control).
     */
    public function testFinanceModuleTemplateOffersPaidCheckoutWithoutWaiveOrFinanceInternals(): void
    {
        $org = $this->insertOrg('Slice4 template org');
        $clinic = $this->insertClinic($org, 'Slice4 Template Clinic');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice4_template_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Template');
        $patient = $this->insertPatient($clinic, 'Template');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'paid');
        $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'paid', '100000.00', '100000.00', '0.00');

        $template = (string) file_get_contents((string) StaffPortalShell::finance_module_template_path());
        foreach (['finance-paid', 'finance-paid-rows', 'finance-checkout-open', 'finance-checkout-form', 'finance-checkout-submit', '/staff/portal/finance/paid', 'can_check_out'] as $required) {
            self::assertStringContainsString($required, $template, 'the module must expose the paid/checkout contract: ' . $required);
        }
        self::assertStringContainsString('state.busy', $template, 'the UI must guard against accidental double submit');
        foreach (self::FORBIDDEN_MODULE_VOCABULARY as $forbidden) {
            if ('device' === $forbidden) {
                continue;
            }
            self::assertStringNotContainsString($forbidden, strtolower($template), 'out-of-scope vocabulary must not appear: ' . $forbidden);
        }
        // The single standard HTML5 viewport token (`width=device-width`) is
        // core responsive markup, not integration vocabulary: neutralize
        // exactly that token; any other `device` occurrence still fails, and
        // the occurrence count is pinned so a new one cannot hide behind it.
        $scanned = str_replace('width=device-width', 'width=viewport', strtolower($template));
        self::assertSame(1, substr_count(strtolower($template), 'device'), 'the only "device" occurrence is the standard viewport declaration');
        self::assertStringNotContainsString('device', $scanned, 'device/provider vocabulary must not appear beyond the viewport declaration');

        $html = $this->renderFinancePortal($secretary);
        self::assertStringContainsString('data-role="finance-paid"', $html, 'the paid/checkout-ready panel is mounted in the module');

        // GREEN-today control: the Slice 1/3 awaiting-payment board keeps its
        // exact projection shape — nothing in this slice changes it.
        wp_set_current_user($secretary);
        $awaitingPatient = $this->insertPatient($clinic, 'TemplateAwaiting');
        $awaitingVisit = $this->insertVisit($clinic, $location, $awaitingPatient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $board = $this->dispatch('GET', self::BOARD, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $board->get_status(), 'the Slice 1 board stays available — ' . $this->errorCode($board));
        $visits = $this->payload($board)['visits'] ?? [];
        self::assertCount(1, $visits, 'only the awaiting_payment row is on the Slice 1 board');
        self::assertSame(self::BOARD_ROW_KEYS, array_keys($visits[0]), 'the Slice 1/3 board projection is unchanged by Slice 4');
        self::assertNotContains($visit, array_map(static fn (array $r): int => (int) $r['visit_id'], $visits), 'the paid Visit is not on the awaiting board');
    }

    // ================= helpers =================

    /** @return array<string, mixed> */
    private function visitRow(int $visitId): array
    {
        return (array) App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d LIMIT 1', [$visitId]);
    }

    private function historyCount(): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visit_status_history'));
    }

    /** @return list<array<string, mixed>> */
    private function visitHistory(int $visitId): array
    {
        $rows = App::db()->fetchAll(
            'SELECT * FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d ORDER BY id ASC',
            [$visitId]
        );

        return is_array($rows) ? $rows : [];
    }

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s', [$action]);
    }

    /** @return array<string, mixed>|null */
    private function invoiceRow(int $invoiceId): ?array
    {
        return App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d LIMIT 1', [$invoiceId]);
    }

    private function paymentCount(int $invoiceId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d', [$invoiceId]);
    }

    private function insertPayment(int $clinicId, int $invoiceId, int $patientId, string $amount, string $method): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse(
            $wpdb->insert($wpdb->prefix . 'cpms_payments', [
                'payment_number' => 'PAY-SLICE4-' . bin2hex(random_bytes(4)),
                'clinic_id' => $clinicId,
                'invoice_id' => $invoiceId,
                'patient_id' => $patientId,
                'amount' => $amount,
                'method' => $method,
                'transaction_ref' => null,
                'idempotency_key' => $this->uuid(),
                'status' => 'captured',
                'refunded_amount' => 0,
                'paid_at' => $now,
                'received_by_wp_user_id' => 0,
                'created_at' => $now,
            ]),
            'payment fixture insert'
        );

        return (int) $wpdb->insert_id;
    }

    private function updateLocationActive(int $locationId, int $active): void
    {
        self::assertNotFalse(
            App::db()->query('UPDATE ' . App::db()->table('cpms_locations') . ' SET is_active = %d WHERE id = %d', [$active, $locationId])
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
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

    private function insertClinic(int $orgId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinics', ['organization_id' => $orgId, 'name' => $name, 'slug' => 'clinic-' . bin2hex(random_bytes(3)), 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now]), 'clinic fixture insert');

        return (int) $wpdb->insert_id;
    }

    private function insertLocation(int $clinicId, string $timezone): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_locations', ['clinic_id' => $clinicId, 'name' => 'Slice4 Location', 'slug' => 'location-' . bin2hex(random_bytes(3)), 'timezone' => $timezone, 'is_primary' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]), 'Location fixture insert');

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', ['clinic_id' => $clinicId, 'mrn' => 'M-' . bin2hex(random_bytes(5)), 'first_name' => $tag, 'last_name' => 'Slice4', 'mobile' => '09' . random_int(1000000000, 9999999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]), 'patient fixture insert');

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

    private function insertInvoice(int $clinicId, int $locationId, int $patientId, int $visitId, int $actorId, string $status, string $total = '100000.00', string $paid = '0.00', string $balance = '100000.00'): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoices', [
            'clinic_id' => $clinicId, 'location_id' => $locationId, 'invoice_number' => 'INV-SLICE4-' . bin2hex(random_bytes(4)),
            'patient_id' => $patientId, 'visit_id' => $visitId, 'status' => $status, 'subtotal' => $total,
            'total' => $total, 'currency' => 'IRR', 'paid_amount' => $paid, 'balance' => $balance,
            'issued_by_wp_user_id' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]), 'invoice fixture insert');

        return (int) $wpdb->insert_id;
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

    /** @return array<string, mixed> */
    private function errorData(WP_REST_Response $response): array
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
