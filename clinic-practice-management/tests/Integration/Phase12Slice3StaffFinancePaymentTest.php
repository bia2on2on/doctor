<?php
/**
 * Phase 12 Slice 3 — Staff Portal Finance: manual partial/full payment capture
 * for an EXISTING invoice at the CURRENT trusted Location — TEST-ONLY RED.
 *
 * Owner-approved slice contract (bounded):
 *   Inside the EXISTING independent Staff Portal Finance module (Phase 12
 *   Slice 1 read-only awaiting-payment board + Slice 2 first issuance), a
 *   Finance actor records ONE manual payment against an existing invoice whose
 *   persisted Visit belongs to the CURRENT trusted operational Location of the
 *   trusted Clinic — a partial amount or the exact remaining balance.
 *
 * Intended product boundary (one route in the established `clinic/v1`
 * namespace, nonce-authenticated, mounted only in the plugin-owned Staff
 * Portal module):
 *   POST /clinic/v1/staff/portal/finance/invoices/{id}/payments
 *
 * The board itself is extended ONLY by the two selector-only ids the action
 * needs (`visit_id`, `invoice_id`; `invoice_id` is null when the row has no
 * legitimately linked active invoice). Existing privacy-minimal projection,
 * CURRENT trusted Location/date filtering, deterministic ordering, the 100-row
 * bound with truthful `has_more`, and the single joined query (no N+1) are all
 * preserved — asserted here.
 *
 * The mutation delegates to the EXISTING `FinanceService::recordPayment()`
 * contract — there is no second payment/totals/state engine, no new finance
 * architecture, no schema/capability/role/state-machine addition. Before
 * delegation the boundary loads the PERSISTED invoice and its PERSISTED Visit
 * server-side and requires trusted Clinic + CURRENT trusted operational
 * Location ownership; raw client ids (clinic/location/visit/patient) are
 * selectors, never authority. Foreign Clinic, same-Clinic foreign Location and
 * unknown invoice ids fail closed with the established non-enumerating 404
 * `CLINIC_NOT_FOUND` parity; a foreign/unavailable Location selector keeps its
 * established 403 `CLINIC_SCOPE_UNAVAILABLE`.
 *
 * Amount: positive integer Rial only, bounded by the server-authoritative
 * invoice balance (`CLINIC_OVERPAYMENT` 422 for anything larger); zero,
 * negative and non-integer amounts stay rejected with 422; client-supplied
 * totals/balances/paid/status can never become authority.
 *
 * Idempotency: a canonical `Idempotency-Key` header is mandatory (400
 * `CLINIC_VALIDATION_FAILED` otherwise) and the existing backend replay
 * behaviour is preserved (first capture 201; replay of the same key 200 +
 * `CLINIC_IDEMPOTENCY_REPLAY` + the same payment; never a second payment).
 *
 * Methods exposed by THIS portal slice: `cash`, `card_pos`, `other`. `online`
 * is NOT exposed in the Staff Portal UI and is rejected by this route.
 * `card_pos` means only “the payment was taken outside CPMS (by a card
 * terminal or otherwise) and is being recorded manually here” — no device
 * communication, discovery, polling, provider/device settings or SDK, and the
 * payments schema/ENUM stays exactly as established.
 *
 * Optional `transaction_ref` keeps the existing service bounds
 * (`mb_substr(…, 0, 128)`) and is never an authority field.
 *
 * Partial capture leaves the Visit in `awaiting_payment` (server-derived
 * reduced balance) and the row on the board; an EXACT full settlement marks the
 * invoice `paid` and applies the existing `settled` Visit transition
 * (`awaiting_payment` → `paid`), so the row leaves the board. No checkout is
 * triggered by this slice, and no side effect beyond the delegated contract is
 * added (asserted by comparing the portal path against the existing shared D13
 * path over the same fixtures).
 *
 * INTENDED PRODUCT RED (to be verified pre-write against current main):
 *   - the portal payment route does not exist (REST dispatch returns 404
 *     rest_no_route) — Groups 2..9 anchor here;
 *   - the board projection does not expose `visit_id`/`invoice_id` — Group 1;
 *   - the module template has no manual capture panel — Group 10.
 * GREEN-today controls that MUST stay GREEN: the Slice 1 board read (minus the
 * two new selector ids), the Slice 2 issuance surface, the shared D13 route and
 * the existing payments schema.
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

final class Phase12Slice3StaffFinancePaymentTest extends WP_UnitTestCase
{
    private const CONTEXT = '/clinic/v1/staff/portal/finance/context';
    private const BOARD = '/clinic/v1/staff/portal/finance/awaiting-payment';
    private const PAY = '/clinic/v1/staff/portal/finance/invoices/%d/payments';
    private const SHARED_PAY = '/clinic/v1/invoices/%d/payments';

    /** Exact Slice 3 row contract: the two new ids are selectors only. */
    private const ROW_KEYS = [
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

    /** Methods this portal slice exposes (online is deliberately absent). */
    private const PORTAL_METHODS = ['cash', 'card_pos', 'other'];

    /** Finance actions this slice must NOT grow into the module. */
    private const FORBIDDEN_ACTION_VOCABULARY = [
        'void_', 'voidReason', 'waive', 'refund', 'adjustment', 'reissue', 'checkout', 'settle',
    ];

    /** Device/provider integration vocabulary that must not appear anywhere. */
    private const FORBIDDEN_DEVICE_VOCABULARY = [
        'terminal', 'device', 'sdk', 'serial', 'usb', 'nfc', 'bluetooth', 'websocket', 'polling', 'merchant',
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
     * Group 1 — the board exposes exactly the two selector ids the action needs
     * and nothing else new (one joined query, read-only, no fabrication).
     */
    public function testAwaitingPaymentBoardExposesSelectorIdsOnlyAndKeepsBoundedRead(): void
    {
        $org = $this->insertOrg('Slice3 board org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $otherLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice3_board_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Slice Three');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');

        $withInvoice = $this->insertPatient($clinic, 'BoardInvoice');
        $withoutInvoice = $this->insertPatient($clinic, 'BoardNoInvoice');
        $crossLocation = $this->insertPatient($clinic, 'BoardCrossLocation');
        $foreignLocation = $this->insertPatient($clinic, 'BoardOtherLocation');

        $invoiceVisit = $this->insertVisit($clinic, $location, $withInvoice, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $plainVisit = $this->insertVisit($clinic, $location, $withoutInvoice, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $crossVisit = $this->insertVisit($clinic, $location, $crossLocation, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $otherVisit = $this->insertVisit($clinic, $otherLocation, $foreignLocation, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');

        $invoice = $this->insertInvoice($clinic, $location, $withInvoice, $invoiceVisit, $secretary, 'open');
        // Same Clinic, foreign Location invoice for a CURRENT-Location Visit:
        // the board must not claim it as this row's invoice.
        $this->insertInvoice($clinic, $otherLocation, $crossLocation, $crossVisit, $secretary, 'open');
        $this->insertInvoice($clinic, $otherLocation, $foreignLocation, $otherVisit, $secretary, 'open');

        $mobile = (string) App::db()->fetchValue(
            'SELECT mobile FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$withInvoice]
        );
        $mrn = (string) App::db()->fetchValue(
            'SELECT mrn FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$withInvoice]
        );

        wp_set_current_user($secretary);
        $context = $this->dispatch('GET', self::CONTEXT, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $context->get_status(), 'Slice 1 context stays available — ' . $this->errorCode($context));

        $readOnlyBefore = $this->readOnlySnapshot();
        $projectionQueries = 0;
        $capture = function (string $query) use (&$projectionQueries): string {
            if (str_contains($query, 'AS visit_id') && str_contains($query, 'cpms_visits')) {
                $projectionQueries++;
            }
            return $query;
        };
        add_filter('query', $capture, PHP_INT_MAX);
        $board = $this->dispatch('GET', self::BOARD, [], $this->scopeHeaders($clinic, $location));
        remove_filter('query', $capture, PHP_INT_MAX);

        self::assertSame(200, $board->get_status(), 'authorized board read must resolve — ' . $this->errorCode($board));
        $data = $this->payload($board);
        self::assertSame($date, $data['date'] ?? null);
        self::assertSame($location, (int) ($data['location_id'] ?? 0));
        self::assertFalse((bool) ($data['has_more'] ?? true));
        self::assertSame(1, $projectionQueries, 'the board still reads the whole projection in one joined query (no N+1)');
        self::assertCount(3, $data['visits'] ?? [], 'only the CURRENT Location awaiting-payment Visits of the operational day are listed');

        $rows = [];
        foreach ($data['visits'] as $row) {
            self::assertSame(self::ROW_KEYS, array_keys($row), 'Slice 3 delta is exactly visit_id + invoice_id; nothing else changes');
            $rows[(string) $row['patient_name']] = $row;
        }
        self::assertArrayHasKey('BoardInvoice Slice3', $rows);
        self::assertArrayHasKey('BoardNoInvoice Slice3', $rows);
        self::assertArrayHasKey('BoardCrossLocation Slice3', $rows);
        self::assertArrayNotHasKey('BoardOtherLocation Slice3', $rows, 'another Location of the same Clinic never leaks into this board');

        self::assertSame($invoiceVisit, (int) $rows['BoardInvoice Slice3']['visit_id'], 'visit_id is the persisted Visit selector');
        self::assertSame($invoice, (int) $rows['BoardInvoice Slice3']['invoice_id'], 'invoice_id is the legitimately linked invoice selector');
        self::assertSame('open', (string) $rows['BoardInvoice Slice3']['invoice']['status']);
        self::assertNull($rows['BoardNoInvoice Slice3']['invoice_id'], 'no invoice ⇒ no selector and no fabricated amount');
        self::assertNull($rows['BoardNoInvoice Slice3']['invoice']);
        self::assertNull($rows['BoardCrossLocation Slice3']['invoice_id'], 'an invoice of another Location is not this row\'s invoice');
        self::assertNull($rows['BoardCrossLocation Slice3']['invoice']);

        $encoded = (string) wp_json_encode($data);
        self::assertStringNotContainsString($mobile, $encoded, 'patient mobile never appears in the board projection');
        self::assertStringNotContainsString($mrn, $encoded, 'patient mrn never appears in the board projection');
        foreach (['patient_id', 'national_id', 'mobile', 'mrn'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, 'projection keeps no ' . $forbidden . ' field');
        }

        self::assertSame($readOnlyBefore, $this->readOnlySnapshot(), 'the board read performs no mutation');
    }

    /**
     * Group 2 — nonce + EXISTING capability + active membership; membership
     * alone is never enough and the delegated service re-authorizes.
     */
    public function testCaptureRequiresNoncePaymentCapabilityAndActiveMembership(): void
    {
        $org = $this->insertOrg('Slice3 authority org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $clinician = $this->insertClinician($clinic, 'Dr Authority3');
        $patient = $this->insertPatient($clinic, 'Authority3');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, 0, 'open', '100000.00');

        $secretary = $this->makeUser('phase12_slice3_auth_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $manager = $this->makeUser('phase12_slice3_auth_manager', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($manager, $clinic, 'cpms_manager');
        $doctor = $this->makeUser('phase12_slice3_auth_doctor', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($doctor, $clinic, 'cpms_doctor');
        $accountant = $this->makeUser('phase12_slice3_auth_accountant', RolesAndCapabilities::ROLE_ACCOUNTANT);

        // 1) nonce is mandatory.
        wp_set_current_user($secretary);
        $noNonce = $this->dispatch(
            'POST',
            sprintf(self::PAY, $invoice),
            [],
            $this->scopeHeaders($clinic, $location),
            ['amount' => 1000, 'method' => 'cash'],
            ['Idempotency-Key' => $this->uuid()],
            false
        );
        self::assertSame(403, $noNonce->get_status(), 'a nonce is required for payment capture');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errorCode($noNonce));

        // 2) membership alone is not authority — manager and doctor hold no
        //    cpms_payment_create.
        wp_set_current_user($manager);
        $managerAttempt = $this->pay($invoice, $clinic, $location, ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        self::assertSame(403, $managerAttempt->get_status(), 'membership alone must never authorize a payment');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($managerAttempt));
        wp_set_current_user($doctor);
        $doctorAttempt = $this->pay($invoice, $clinic, $location, ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        self::assertSame(403, $doctorAttempt->get_status());
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($doctorAttempt));

        // 3) the existing payment capability without an active membership in
        //    the trusted Clinic still fails closed at the scope boundary.
        wp_set_current_user($accountant);
        $accountantAttempt = $this->pay($invoice, $clinic, $location, ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        self::assertSame(403, $accountantAttempt->get_status(), 'no active membership ⇒ no trusted Clinic scope');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($accountantAttempt));

        self::assertSame(0, $this->paymentCount($invoice), 'rejected attempts write nothing');
        self::assertSame(
            '100000.00',
            (string) App::db()->fetchValue('SELECT balance FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoice])
        );

        // 4) Slice 1/2 module access contract is unchanged (no broadening).
        self::assertFalse(StaffPortalShell::finance_module_eligible($manager), 'manager stays outside the Finance module');
        self::assertFalse(StaffPortalShell::finance_module_eligible($accountant), 'accountant stays outside the Finance module');
        self::assertTrue(StaffPortalShell::finance_module_eligible($secretary));

        // 5) the existing authority succeeds (GREEN anchor for the slice).
        wp_set_current_user($secretary);
        $allowed = $this->pay($invoice, $clinic, $location, ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        self::assertSame(201, $allowed->get_status(), 'existing cpms_payment_create + same-Clinic authority must capture — ' . $this->errorCode($allowed));
        self::assertSame(1, $this->paymentCount($invoice));
    }

    /**
     * Group 3 — CRITICAL Location guard: the invoice selector can never escape
     * the trusted Clinic or the CURRENT trusted operational Location.
     */
    public function testInvoiceSelectorCannotEscapeTheTrustedClinicOrCurrentLocation(): void
    {
        $org = $this->insertOrg('Slice3 isolation org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $otherLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $foreignClinic = $this->insertClinic($org);
        $foreignLocation = $this->insertLocation($foreignClinic, 'Asia/Tehran');

        $secretary = $this->makeUser('phase12_slice3_iso_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');

        $clinician = $this->insertClinician($clinic, 'Dr Iso3');
        $foreignClinician = $this->insertClinician($foreignClinic, 'Dr Foreign3');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $now = App::db()->nowUtcSql();

        $patient = $this->insertPatient($clinic, 'IsoCurrent');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, $now, 'awaiting_payment');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'open');

        $otherLocationPatient = $this->insertPatient($clinic, 'IsoOtherLocation');
        $otherLocationVisit = $this->insertVisit($clinic, $otherLocation, $otherLocationPatient, $clinician, $date, $now, 'awaiting_payment');
        $otherLocationInvoice = $this->insertInvoice($clinic, $otherLocation, $otherLocationPatient, $otherLocationVisit, $secretary, 'open');

        $foreignPatient = $this->insertPatient($foreignClinic, 'IsoForeign');
        $foreignVisit = $this->insertVisit($foreignClinic, $foreignLocation, $foreignPatient, $foreignClinician, $date, $now, 'awaiting_payment');
        $foreignInvoice = $this->insertInvoice($foreignClinic, $foreignLocation, $foreignPatient, $foreignVisit, 0, 'open');

        wp_set_current_user($secretary);

        // Foreign Clinic invoice, current trusted Clinic/Location headers.
        $foreignAttempt = $this->pay($foreignInvoice, $clinic, $location, ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        self::assertSame(404, $foreignAttempt->get_status(), 'an invoice of another Clinic is not disclosed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($foreignAttempt));

        // Same-Clinic foreign Location invoice.
        $otherLocationAttempt = $this->pay($otherLocationInvoice, $clinic, $location, ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        self::assertSame(404, $otherLocationAttempt->get_status(), 'an invoice of another Location of the same Clinic is not disclosed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($otherLocationAttempt));

        // Unknown / foreign invoice id parity.
        $unknownAttempt = $this->pay(2147483000, $clinic, $location, ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        self::assertSame(404, $unknownAttempt->get_status(), 'unknown invoice ids are indistinguishable from foreign ones');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($unknownAttempt));

        // The selector cannot follow the client into another Location either:
        // the trusted CURRENT Location is the same-Clinic other Location while
        // the target invoice belongs to this Location.
        $movedSelector = $this->pay($invoice, $clinic, $otherLocation, ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        self::assertSame(404, $movedSelector->get_status(), 'an invoice outside the CURRENT trusted Location is not payable');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($movedSelector));

        // Client-supplied Clinic header is never authority: a Clinic without
        // membership fails closed at the scope boundary.
        $foreignHeader = $this->pay($foreignInvoice, $foreignClinic, $foreignLocation, ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        self::assertSame(403, $foreignHeader->get_status(), 'client Clinic/Location selection never grants authority');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($foreignHeader));

        // Nothing was written anywhere.
        foreach ([$invoice, $otherLocationInvoice, $foreignInvoice] as $untouched) {
            self::assertSame(0, $this->paymentCount($untouched));
            self::assertSame(
                '0.00',
                (string) App::db()->fetchValue('SELECT paid_amount FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$untouched])
            );
            self::assertSame(
                'open',
                (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$untouched])
            );
        }
        self::assertSame('awaiting_payment', (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$foreignVisit]));
        self::assertSame(0, $this->auditCount('PAYMENT_CAPTURE'), 'rejected attempts never audit a capture');
    }

    /**
     * Group 4 — amount contract: positive integer Rial, server balance is the
     * maximum, and client-supplied money/status fields are ignored.
     */
    public function testAmountIsServerBoundedAndClientSuppliedMoneyNeverBecomesAuthority(): void
    {
        $org = $this->insertOrg('Slice3 amount org');
        $clinic = $this->insertClinic($org);
        $foreignClinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice3_amount_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Amount3');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');

        $patient = $this->insertPatient($clinic, 'Amount3');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'open', '500000.00');

        $otherPatient = $this->insertPatient($clinic, 'AmountOther3');
        $otherVisit = $this->insertVisit($clinic, $location, $otherPatient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');

        wp_set_current_user($secretary);

        $invalid = [
            'missing amount' => [],
            'zero' => ['amount' => 0],
            'negative' => ['amount' => -1000],
            'fractional' => ['amount' => '100.5'],
            'non numeric' => ['amount' => 'abc'],
        ];
        foreach ($invalid as $label => $body) {
            $attempt = $this->pay($invoice, $clinic, $location, $body + ['method' => 'cash'], $this->uuid());
            self::assertSame(422, $attempt->get_status(), $label . ' must stay rejected');
            self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($attempt), $label);
        }

        foreach ([500001, 600000] as $over) {
            $overAttempt = $this->pay($invoice, $clinic, $location, ['amount' => $over, 'method' => 'cash'], $this->uuid());
            self::assertSame(422, $overAttempt->get_status(), 'overpayment must remain 422');
            self::assertSame('CLINIC_OVERPAYMENT', $this->errorCode($overAttempt));
            self::assertSame(500000, (int) ($this->errorData($overAttempt)['balance'] ?? -1), 'the server balance is reported, never the client figure');
        }

        self::assertSame(0, $this->paymentCount($invoice), 'rejected amounts write no payment');
        self::assertSame(
            '0.00',
            (string) App::db()->fetchValue('SELECT paid_amount FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoice])
        );

        // Hostile client money/status/identity fields must never become
        // authority: only amount + method (+ optional ref) are forwarded.
        $hostile = $this->pay($invoice, $clinic, $location, [
            'amount' => 100000,
            'method' => 'cash',
            'total' => 1,
            'balance' => 1,
            'paid_amount' => 999999,
            'status' => 'paid',
            'currency' => 'USD',
            'clinic_id' => $foreignClinic,
            'location_id' => 999999,
            'visit_id' => $otherVisit,
            'patient_id' => $otherPatient,
            'discount' => 999999,
            'tax' => 999999,
        ], $this->uuid());
        self::assertSame(201, $hostile->get_status(), 'the legitimate capture must still succeed — ' . $this->errorCode($hostile));
        $captured = $this->payload($hostile);
        self::assertSame('partial', (string) ($captured['invoice']['status'] ?? ''), 'invoice state comes from the existing service, not the payload');
        self::assertSame(400000.0, (float) ($captured['invoice']['balance'] ?? -1));
        $stored = $this->paymentRow($invoice);
        self::assertNotNull($stored);
        self::assertSame('100000.00', (string) $stored['amount']);
        self::assertSame($patient, (int) $stored['patient_id'], 'the persisted invoice patient is authoritative');
        self::assertSame($clinic, (int) $stored['clinic_id']);
        self::assertSame(1, $this->paymentCount($invoice));
    }

    /**
     * Group 5 — this portal route exposes cash / card_pos / other only, and
     * `card_pos` stays pure manual entry (no device/provider modelling at all).
     */
    public function testPortalRouteExposesOnlyManualMethodsAndRejectsOnline(): void
    {
        $org = $this->insertOrg('Slice3 methods org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice3_methods_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Methods3');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $patient = $this->insertPatient($clinic, 'Methods3');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'open', '900000.00');

        wp_set_current_user($secretary);

        foreach (['online', 'card_terminal', 'device', 'cash_pos', ''] as $rejected) {
            $attempt = $this->pay($invoice, $clinic, $location, ['amount' => 1000, 'method' => $rejected], $this->uuid());
            self::assertSame(422, $attempt->get_status(), 'method "' . $rejected . '" must not be accepted by this portal route');
            self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($attempt), 'method "' . $rejected . '"');
        }
        self::assertSame(0, $this->paymentCount($invoice));

        $amounts = ['cash' => 1000, 'card_pos' => 2000, 'other' => 3000];
        foreach (self::PORTAL_METHODS as $method) {
            $response = $this->pay($invoice, $clinic, $location, ['amount' => $amounts[$method], 'method' => $method], $this->uuid());
            self::assertSame(201, $response->get_status(), $method . ' must be recordable — ' . $this->errorCode($response));
            self::assertSame('captured', (string) ($this->payload($response)['payment']['status'] ?? ''));
            self::assertSame($method, (string) ($this->payload($response)['payment']['method'] ?? ''));
        }
        $methods = App::db()->fetchAll(
            'SELECT method FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d ORDER BY id ASC',
            [$invoice]
        );
        self::assertSame(self::PORTAL_METHODS, array_map(static fn (array $row): string => (string) $row['method'], $methods), 'methods are persisted exactly as established, with no device mapping');

        // No speculative device/provider architecture: the payments schema is
        // exactly the established one (four methods, no device columns).
        $columns = App::db()->fetchAll('SHOW COLUMNS FROM ' . App::db()->table('cpms_payments'));
        $names = array_map(static fn (array $row): string => strtolower((string) $row['Field']), $columns);
        foreach ($names as $name) {
            self::assertDoesNotMatchRegularExpression(
                '/(device|terminal|provider|sdk|serial|nfc|bluetooth)/',
                $name,
                'no device/provider column may be introduced for this slice'
            );
        }
        $methodColumn = null;
        foreach ($columns as $column) {
            if ('method' === strtolower((string) $column['Field'])) {
                $methodColumn = (string) $column['Type'];
            }
        }
        self::assertNotNull($methodColumn, 'the established payments.method column must still exist');
        preg_match_all("/'([^']*)'/", $methodColumn, $matches);
        self::assertSame(
            ['cash', 'card_pos', 'online', 'other'],
            $matches[1] ?? [],
            'the established method ENUM is unchanged (online stays a backend method, it is simply not exposed by this portal route)'
        );
    }

    /**
     * Group 6 — partial capture: one captured payment, invoice stays payable
     * with a reduced server-derived balance, Visit stays awaiting_payment and
     * the row stays on the board with the new remaining amount.
     */
    public function testPartialCaptureKeepsTheVisitAwaitingPaymentAndTheBoardRowWithReducedBalance(): void
    {
        $org = $this->insertOrg('Slice3 partial org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice3_partial_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Partial3');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $patient = $this->insertPatient($clinic, 'Partial3');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'open', '500000.00');

        wp_set_current_user($secretary);
        $response = $this->pay($invoice, $clinic, $location, ['amount' => 200000, 'method' => 'cash'], $this->uuid());
        self::assertSame(201, $response->get_status(), 'a partial capture must delegate to the existing service — ' . $this->errorCode($response));
        $data = $this->payload($response);
        self::assertArrayNotHasKey('idempotent_replay', $data, 'a first capture is not a replay');
        self::assertSame(200000.0, (float) ($data['payment']['amount'] ?? -1));
        self::assertSame('captured', (string) ($data['payment']['status'] ?? ''));
        self::assertNotSame('', (string) ($data['payment']['payment_number'] ?? ''));
        self::assertSame('partial', (string) ($data['invoice']['status'] ?? ''));
        self::assertSame(200000.0, (float) ($data['invoice']['paid_amount'] ?? -1));
        self::assertSame(300000.0, (float) ($data['invoice']['balance'] ?? -1));

        self::assertSame(1, $this->paymentCount($invoice));
        self::assertSame(
            ['partial', '200000.00', '300000.00'],
            [
                (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoice]),
                (string) App::db()->fetchValue('SELECT paid_amount FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoice]),
                (string) App::db()->fetchValue('SELECT balance FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoice]),
            ]
        );
        self::assertSame(
            'awaiting_payment',
            (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visit]),
            'a partial capture never settles the Visit (J-2)'
        );
        self::assertSame(
            0,
            (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d', [$visit]),
            'a partial capture writes no settled Visit transition'
        );
        self::assertSame(1, $this->auditCount('PAYMENT_CAPTURE'), 'the delegated path still audits exactly one capture');

        $board = $this->dispatch('GET', self::BOARD, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $board->get_status());
        $rows = $this->payload($board)['visits'] ?? [];
        self::assertCount(1, $rows, 'the partially paid Visit stays on the awaiting-payment board');
        self::assertSame($visit, (int) $rows[0]['visit_id']);
        self::assertSame($invoice, (int) $rows[0]['invoice_id']);
        self::assertSame('partial', (string) $rows[0]['invoice']['status']);
        self::assertSame('300000.00', (string) $rows[0]['invoice']['remaining'], 'the row shows the server-truth reduced remaining amount');
    }

    /**
     * Group 7 — exact full settlement uses the existing paid + settled
     * transition, the row leaves the board, and nothing checks the Visit out.
     */
    public function testExactFullSettlementUsesExistingTransitionsAndNeverChecksOut(): void
    {
        $org = $this->insertOrg('Slice3 settle org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice3_settle_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Settle3');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $patient = $this->insertPatient($clinic, 'Settle3');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'open', '500000.00');

        wp_set_current_user($secretary);
        $partial = $this->pay($invoice, $clinic, $location, ['amount' => 200000, 'method' => 'cash'], $this->uuid());
        self::assertSame(201, $partial->get_status(), $this->errorCode($partial));

        $settlement = $this->pay($invoice, $clinic, $location, ['amount' => 300000, 'method' => 'card_pos'], $this->uuid());
        self::assertSame(201, $settlement->get_status(), 'the exact remaining amount must settle the invoice — ' . $this->errorCode($settlement));
        $data = $this->payload($settlement);
        self::assertSame('paid', (string) ($data['invoice']['status'] ?? ''));
        self::assertSame(0.0, (float) ($data['invoice']['balance'] ?? -1));
        self::assertSame(500000.0, (float) ($data['invoice']['paid_amount'] ?? -1));

        self::assertSame(2, $this->paymentCount($invoice));
        self::assertSame('paid', (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoice]));
        self::assertSame(
            'paid',
            (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visit]),
            'the existing settled transition (V12) moves awaiting_payment → paid'
        );
        $history = App::db()->fetchAll(
            'SELECT from_status, to_status, actor_role FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d ORDER BY id ASC',
            [$visit]
        );
        self::assertCount(1, $history, 'exactly one existing transition row is written by the settlement');
        self::assertSame('awaiting_payment', (string) $history[0]['from_status']);
        self::assertSame('paid', (string) $history[0]['to_status']);
        self::assertSame('system', (string) $history[0]['actor_role']);

        $board = $this->dispatch('GET', self::BOARD, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $board->get_status());
        self::assertSame([], $this->payload($board)['visits'] ?? null, 'the settled Visit leaves this board after server-truth refresh');

        self::assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . " WHERE action IN ('VISIT_CHECK_OUT','VISIT_WAIVE','VISIT_CHECKOUT')"
            ),
            'no automatic checkout/waive is triggered by payment capture'
        );
        self::assertSame(2, $this->auditCount('PAYMENT_CAPTURE'));

        // The existing invoice state machine still wins: a further attempt on
        // the now-paid invoice is refused by the delegated service.
        $afterSettlement = $this->pay($invoice, $clinic, $location, ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        self::assertSame(409, $afterSettlement->get_status(), 'a settled invoice is not modifiable');
        self::assertSame('CLINIC_INVOICE_NOT_MODIFIABLE', $this->errorCode($afterSettlement));
        self::assertSame(2, $this->paymentCount($invoice));
    }

    /**
     * Group 8 — mandatory Idempotency-Key and preserved replay behaviour: a
     * double submit / retry never creates a second payment.
     */
    public function testIdempotencyKeyIsMandatoryAndReplayNeverCreatesASecondPayment(): void
    {
        $org = $this->insertOrg('Slice3 idempotency org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice3_idem_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Idem3');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $patient = $this->insertPatient($clinic, 'Idem3');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'open', '300000.00');

        wp_set_current_user($secretary);

        $missing = $this->dispatch('POST', sprintf(self::PAY, $invoice), [], $this->scopeHeaders($clinic, $location), ['amount' => 1000, 'method' => 'cash']);
        self::assertSame(400, $missing->get_status(), 'a payment attempt without an Idempotency-Key must be refused');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($missing));

        foreach (['not-a-uuid', '0000', '12345678-1234-1234-1234-12345678901'] as $malformed) {
            $attempt = $this->pay($invoice, $clinic, $location, ['amount' => 1000, 'method' => 'cash'], $malformed);
            self::assertSame(400, $attempt->get_status(), 'malformed key "' . $malformed . '" must be refused');
            self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($attempt));
        }
        self::assertSame(0, $this->paymentCount($invoice), 'no payment is written without a valid key');

        $key = strtoupper($this->uuid());
        $first = $this->pay($invoice, $clinic, $location, ['amount' => 100000, 'method' => 'cash'], $key);
        self::assertSame(201, $first->get_status(), $this->errorCode($first));
        $firstPaymentId = (int) ($this->payload($first)['payment_id'] ?? 0);
        self::assertGreaterThan(0, $firstPaymentId);
        self::assertSame(
            strtolower($key),
            (string) App::db()->fetchValue('SELECT idempotency_key FROM ' . App::db()->table('cpms_payments') . ' WHERE id = %d', [$firstPaymentId]),
            'the existing canonical (lowercased UUID) key contract is reused'
        );

        $replay = $this->pay($invoice, $clinic, $location, ['amount' => 100000, 'method' => 'cash'], $key);
        self::assertSame(200, $replay->get_status(), 'a replay of the same key answers 200');
        $replayData = $this->payload($replay);
        self::assertTrue((bool) ($replayData['idempotent_replay'] ?? false), 'the existing replay flag is preserved');
        self::assertSame('CLINIC_IDEMPOTENCY_REPLAY', (string) ($replayData['code'] ?? ''));
        self::assertSame($firstPaymentId, (int) ($replayData['payment_id'] ?? 0), 'the replay returns the same payment');

        // A retry that carries a different amount must still replay the
        // original payment (idempotency is not an amount re-supplier).
        $changed = $this->pay($invoice, $clinic, $location, ['amount' => 250000, 'method' => 'cash'], $key);
        self::assertSame(200, $changed->get_status());
        self::assertSame(100000.0, (float) ($this->payload($changed)['payment']['amount'] ?? -1));

        self::assertSame(1, $this->paymentCount($invoice), 'M-1 — exactly one payment row for this invoice/key');
        self::assertSame(1, $this->auditCount('PAYMENT_CAPTURE'), 'the replay never re-audits a capture');
        self::assertSame(
            ['partial', '100000.00', '200000.00'],
            [
                (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoice]),
                (string) App::db()->fetchValue('SELECT paid_amount FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoice]),
                (string) App::db()->fetchValue('SELECT balance FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoice]),
            ],
            'the balance is reduced exactly once'
        );
    }

    /**
     * Group 9 — transaction reference bounds are unchanged, and the portal path
     * adds no side effect beyond the existing shared D13 contract.
     */
    public function testTransactionReferenceKeepsExistingBoundsAndDelegationAddsNoExtraSideEffects(): void
    {
        $org = $this->insertOrg('Slice3 parity org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice3_parity_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Parity3');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $now = App::db()->nowUtcSql();

        $portalPatient = $this->insertPatient($clinic, 'ParityPortal');
        $portalVisit = $this->insertVisit($clinic, $location, $portalPatient, $clinician, $date, $now, 'awaiting_payment');
        $portalInvoice = $this->insertInvoice($clinic, $location, $portalPatient, $portalVisit, $secretary, 'open', '400000.00');

        $sharedPatient = $this->insertPatient($clinic, 'ParityShared');
        $sharedVisit = $this->insertVisit($clinic, $location, $sharedPatient, $clinician, $date, $now, 'awaiting_payment');
        $sharedInvoice = $this->insertInvoice($clinic, $location, $sharedPatient, $sharedVisit, $secretary, 'open', '400000.00');

        $longRef = str_repeat('A', 200);

        wp_set_current_user($secretary);
        $beforePortal = $this->paritySnapshot();
        $portal = $this->pay($portalInvoice, $clinic, $location, ['amount' => 400000, 'method' => 'cash', 'transaction_ref' => $longRef], $this->uuid());
        self::assertSame(201, $portal->get_status(), 'portal capture with a reference must delegate — ' . $this->errorCode($portal));
        $portalDelta = $this->delta($beforePortal, $this->paritySnapshot());
        $portalPayment = $this->payload($portal)['payment'] ?? [];
        self::assertSame(128, mb_strlen((string) ($portalPayment['transaction_ref'] ?? '')), 'the existing 128-character bound is preserved');
        self::assertSame(substr($longRef, 0, 128), (string) ($portalPayment['transaction_ref'] ?? ''));

        $beforeShared = $this->paritySnapshot();
        $shared = $this->dispatch(
            'POST',
            sprintf(self::SHARED_PAY, $sharedInvoice),
            [],
            $this->scopeHeaders($clinic, $location),
            ['amount' => 400000, 'method' => 'cash', 'transaction_ref' => 'D13-REF'],
            ['Idempotency-Key' => $this->uuid()]
        );
        self::assertSame(201, $shared->get_status(), 'the existing shared D13 route stays available — ' . $this->errorCode($shared));
        $sharedDelta = $this->delta($beforeShared, $this->paritySnapshot());

        self::assertSame(
            $sharedDelta,
            $portalDelta,
            'the portal path must add no side effect beyond the existing finance contract (payments/audit/history/jobs/notifications)'
        );
        self::assertSame('D13-REF', (string) ($this->payload($shared)['payment']['transaction_ref'] ?? ''));
        self::assertSame(
            'paid',
            (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$portalVisit])
        );
        self::assertSame(
            'paid',
            (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$sharedVisit])
        );

        // No reference is also valid and stays null (never an authority field).
        $secondPatient = $this->insertPatient($clinic, 'ParityNoRef');
        $secondVisit = $this->insertVisit($clinic, $location, $secondPatient, $clinician, $date, $now, 'awaiting_payment');
        $secondInvoice = $this->insertInvoice($clinic, $location, $secondPatient, $secondVisit, $secretary, 'open', '100000.00');
        $withoutRef = $this->pay($secondInvoice, $clinic, $location, ['amount' => 100000, 'method' => 'other'], $this->uuid());
        self::assertSame(201, $withoutRef->get_status());
        self::assertNull($this->payload($withoutRef)['payment']['transaction_ref'] ?? null);
    }

    /**
     * Group 10 — module template/UI contract: manual capture only, no `online`
     * option, no device/POS affordance and no other finance action.
     */
    public function testFinanceModuleTemplateExposesManualCaptureWithoutOnlineOrDeviceIntegration(): void
    {
        $org = $this->insertOrg('Slice3 ui org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice3_ui_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Ui3');
        $patient = $this->insertPatient($clinic, 'Ui3');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'open', '100000.00');

        $template = (string) file_get_contents((string) StaffPortalShell::finance_module_template_path());
        foreach (['finance-pay-open', 'finance-pay-form', 'finance-pay-submit', 'finance-pay-method', 'finance-pay-amount', 'Idempotency-Key'] as $required) {
            self::assertStringContainsString($required, $template, 'the module must expose the manual capture contract: ' . $required);
        }
        foreach (self::PORTAL_METHODS as $method) {
            self::assertStringContainsString('value="' . $method . '"', $template, 'the capture form must offer ' . $method);
        }
        self::assertStringNotContainsString('online', $template, 'online payment must not be exposed anywhere in this module');
        foreach (self::FORBIDDEN_DEVICE_VOCABULARY as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($template), 'device/provider vocabulary must not appear: ' . $forbidden);
        }
        foreach (self::FORBIDDEN_ACTION_VOCABULARY as $forbidden) {
            self::assertStringNotContainsString($forbidden, $template, 'out-of-scope finance action vocabulary must not appear: ' . $forbidden);
        }
        self::assertStringContainsString('state.busy', $template, 'the UI must guard against accidental double submit');

        wp_set_current_user($secretary);
        $html = $this->renderFinancePortal($secretary);
        self::assertStringContainsString('data-cpms-portal="staff"', $html);
        self::assertStringContainsString('data-cpms-staff-module="finance"', $html);
        self::assertStringContainsString('data-role="finance-board"', $html, 'the Slice 1 board stays mounted');
        self::assertStringContainsString('data-role="finance-payment"', $html, 'the manual capture panel is mounted');
    }

    /** @param array<string, mixed> $body */
    private function pay(int $invoiceId, int $clinicId, ?int $locationId, array $body, ?string $key): WP_REST_Response
    {
        $headers = [];
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $this->dispatch(
            'POST',
            sprintf(self::PAY, $invoiceId),
            [],
            $this->scopeHeaders($clinicId, $locationId),
            $body,
            $headers
        );
    }

    private function paymentCount(int $invoiceId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d',
            [$invoiceId]
        );
    }

    /** @return array<string, mixed>|null */
    private function paymentRow(int $invoiceId): ?array
    {
        return App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d ORDER BY id ASC LIMIT 1',
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

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
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
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinics', ['organization_id' => $orgId, 'name' => 'Slice3 Clinic', 'slug' => 'clinic-' . bin2hex(random_bytes(3)), 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now]), 'clinic fixture insert');
        return (int) $wpdb->insert_id;
    }

    private function insertLocation(int $clinicId, string $timezone): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_locations', ['clinic_id' => $clinicId, 'name' => 'Slice3 Location', 'slug' => 'location-' . bin2hex(random_bytes(3)), 'timezone' => $timezone, 'is_primary' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]), 'Location fixture insert');
        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', ['clinic_id' => $clinicId, 'mrn' => 'M-' . bin2hex(random_bytes(5)), 'first_name' => $tag, 'last_name' => 'Slice3', 'mobile' => '09' . random_int(1000000000, 9999999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]), 'patient fixture insert');
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

    private function insertInvoice(int $clinicId, int $locationId, int $patientId, int $visitId, int $actorId, string $status, string $amount = '100000.00'): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoices', [
            'clinic_id' => $clinicId, 'location_id' => $locationId, 'invoice_number' => 'INV-SLICE3-' . bin2hex(random_bytes(4)),
            'patient_id' => $patientId, 'visit_id' => $visitId, 'status' => $status, 'subtotal' => $amount,
            'total' => $amount, 'currency' => 'IRR', 'paid_amount' => '0.00', 'balance' => $amount,
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
        return $this->tableCounts($tables);
    }

    /** @return array<string, int> */
    private function paritySnapshot(): array
    {
        return $this->tableCounts([
            'cpms_visits', 'cpms_visit_status_history', 'cpms_invoices', 'cpms_payments',
            'cpms_audit_logs', 'cpms_idempotency_keys', 'cpms_jobs', 'cpms_notifications',
        ]);
    }

    /**
     * @param list<string> $tables
     * @return array<string, int>
     */
    private function tableCounts(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($table));
        }
        return $snapshot;
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     * @return array<string, int>
     */
    private function delta(array $before, array $after): array
    {
        $delta = [];
        foreach ($after as $table => $count) {
            $delta[$table] = $count - ($before[$table] ?? 0);
        }
        return $delta;
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
    private function dispatch(
        string $method,
        string $route,
        array $params = [],
        array $headers = [],
        ?array $body = null,
        array $extraHeaders = [],
        bool $withNonce = true
    ): WP_REST_Response {
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
        return is_array($data) && is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    private function errorCode(WP_REST_Response $response): string
    {
        $data = $response->get_data();
        return is_array($data) ? (string) ($data['code'] ?? '') : '';
    }
}
