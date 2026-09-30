<?php
/**
 * Phase 12 Slice 5 — Staff Portal Finance: read-only printed receipt for a
 * NORMAL fully settled invoice at the CURRENT trusted Location — TEST-ONLY RED.
 *
 * Owner-approved slice contract (bounded; docs/api/api-contract.md — Phase 12
 * Slice 5 section, written before implementation):
 *   Inside the EXISTING independent Staff Portal Finance module (Slice 1
 *   awaiting-payment board + Slice 2 first issuance + Slice 3 manual capture +
 *   Slice 4 paid checkout), an actor with the EXISTING `cpms_invoice_read`
 *   authority reads the receipt of the invoice of a Visit whose persisted
 *   invoice is NORMAL and FULLY SETTLED at the CURRENT trusted operational
 *   Location, and prints it through the browser (`window.print()`). No server
 *   PDF, no new receipt engine, no mutation, no audit side effect.
 *
 * Slice 5 delta on the existing surface (no new domain concept):
 *   - GET /clinic/v1/staff/portal/finance/visits/{id}/receipt — bounded,
 *     read-only Staff Portal projection/adapter over the EXISTING persisted
 *     finance data; the Visit id is a selector, never authority;
 *   - the module template gains a bounded receipt panel + a print action that
 *     isolates the receipt surface from the portal/theme chrome.
 *
 * Proven eligibility contract (durable tables only — no new “clean receipt”
 * state, no schema change, no migration). The receipt is returned only when:
 *   - the Visit exists and belongs to the trusted Clinic AND to the CURRENT
 *     trusted operational Location (raw ids are selectors only);
 *   - the selected invoice (and every payment row used for it) is durably
 *     OWNED by that Visit: `visit_id`/`clinic_id`/`patient_id` agree with the
 *     anchor, and `location_id` is either NULL (the established nullable
 *     finance Location) or the Visit Location exactly; any durable ownership
 *     mismatch fails closed with the same non-enumerating 404 parity as a
 *     foreign Visit and never renders another patient's data;
 *   - the Visit has exactly one non-voided invoice and no voided invoice;
 *   - that invoice is exactly `paid`, not voided, `total > 0`,
 *     `paid_amount = total`, `balance = 0`, and `paid_amount` equals the sum of
 *     its `cpms_payments` rows;
 *   - every payment row is a clean capture (`captured`, `refunded_amount = 0`,
 *     no void markers) and at least one exists;
 *   - no `cpms_payment_adjustments` row exists for the invoice;
 *   - at least one `cpms_invoice_items` row exists;
 *   - the append-only Visit history contains no `awaiting_payment →
 *     checked_out` waiver transition.
 * Anything else fails closed with 409 `CLINIC_RECEIPT_NOT_ELIGIBLE` + a
 * bounded `reason` (`invoice_missing`, `invoice_not_settled`,
 * `correction_evidence`, `settlement_integrity`, `items_missing`,
 * `waive_evidence`) and mutates nothing; a durable ownership/linkage mismatch
 * instead uses the 404 `CLINIC_NOT_FOUND` parity, because an inconsistent row
 * is never repaired, never partially rendered and never disclosed.
 *
 * Deliberately OUT of scope (asserted as absent, not implemented): refund UI,
 * void UI, adjustment UI, waive, new payment, checkout mutation, online
 * payment, any POS/card-terminal communication (`card_pos` may appear only as
 * the already-recorded manual payment method), server-side PDF generation, any
 * new dependency/framework, any second receipt engine, any new migration,
 * role, capability or finance state machine. The EXISTING D17 back-office
 * receipt route (and its wp-admin print flow) is a GREEN-today control: this
 * slice neither re-implements nor changes it.
 *
 * INTENDED RED (test-only; no product bytes in this commit):
 *   - the Staff Portal receipt route does not exist (404 `rest_no_route`) →
 *     Groups 1–8 (every dispatch to it);
 *   - the module template has no receipt panel/print wiring → Group 9.
 * GREEN-today controls that must stay GREEN after the product change: the
 * existing Slice 1 awaiting-payment board projection (9 keys), the Slice 4
 * paid board projection (8 keys), the existing D17 back-office receipt route
 * and the existing shared D16 checkout route.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Frontend\StaffPortalShell;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase12Slice5StaffFinanceReceiptTest extends WP_UnitTestCase
{
    private const RECEIPT = '/clinic/v1/staff/portal/finance/visits/%d/receipt';
    private const CONTEXT = '/clinic/v1/staff/portal/finance/context';
    private const BOARD = '/clinic/v1/staff/portal/finance/awaiting-payment';
    private const PAID = '/clinic/v1/staff/portal/finance/paid';
    private const D17_RECEIPT = '/clinic/v1/invoices/%d/receipt';

    /** The exact Slice 5 receipt projection: only what the printed receipt needs. */
    private const RECEIPT_KEYS = [
        'clinic',
        'patient',
        'invoice_number',
        'invoice_date',
        'jalali_invoice_date',
        'items',
        'totals',
        'payments',
    ];

    private const CLINIC_KEYS = ['name', 'address', 'phone'];
    private const PATIENT_KEYS = ['name'];
    private const ITEM_KEYS = ['description', 'quantity', 'unit_price', 'amount'];
    private const TOTALS_KEYS = ['subtotal', 'discount', 'tax', 'total', 'paid_amount', 'balance', 'currency'];
    private const PAYMENT_KEYS = ['payment_number', 'method', 'amount', 'paid_at', 'jalali_paid_at'];

    /** The Slice 1 board projection — a GREEN-today control that must not change. */
    private const BOARD_ROW_KEYS = [
        'visit_id', 'patient_name', 'clinician_name', 'operational_date',
        'jalali_date', 'operational_time', 'visit_status', 'invoice_id', 'invoice',
    ];

    /** The Slice 4 paid board projection — a GREEN-today control that must not change. */
    private const PAID_ROW_KEYS = [
        'visit_id', 'patient_name', 'clinician_name', 'operational_date',
        'jalali_date', 'operational_time', 'visit_status', 'invoice',
    ];

    /** Bounded, explicit, non-enumerating eligibility failure reasons. */
    private const ELIGIBILITY_REASONS = [
        'invoice_missing',
        'invoice_not_settled',
        'correction_evidence',
        'settlement_integrity',
        'items_missing',
        'waive_evidence',
    ];

    /** Fixed UTC instants: the Location-local date of the first payment differs from its UTC date. */
    private const INVOICE_CREATED_AT = '2026-06-15 17:00:00.000';
    private const PAYMENT_ONE_AT = '2026-06-15 21:30:00.000';
    private const PAYMENT_TWO_AT = '2026-06-16 05:00:00.000';

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
     * Group 1 — the NORMAL fully settled invoice: the bounded receipt
     * projection is served from persisted rows, the Visit→invoice truth is
     * verified server-side, Jalali/location-local presentation is derived from
     * the CURRENT trusted Location timezone, privacy stays minimal, the read
     * is bounded and nothing is written.
     */
    public function testNormalFullySettledInvoiceReturnsTheBoundedReceiptProjection(): void
    {
        $org = $this->insertOrg('Slice5 normal org');
        $clinic = $this->insertClinic($org, 'Slice5 Normal Clinic', 'Asia/Tehran');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice5_normal_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Slice Five');
        $patient = $this->insertPatient($clinic, 'Receipt Normal');

        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::PAYMENT_ONE_AT, 'paid');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'paid', '250000.00', '250000.00', '0.00', [
            'created_at' => self::INVOICE_CREATED_AT,
            'updated_at' => self::INVOICE_CREATED_AT,
            'invoice_number' => 'INV-SLICE5-NORMAL',
        ]);
        $this->insertItem($invoice, 'مشاورهٔ سرپایی', '1.00', '200000.00', '200000.00');
        $this->insertItem($invoice, 'ویزیت مجدد', '2.00', '25000.00', '50000.00');
        $firstPayment = $this->insertPayment($clinic, $invoice, $patient, '150000.00', 'cash', [
            'payment_number' => 'PAY-SLICE5-ONE',
            'paid_at' => self::PAYMENT_ONE_AT,
        ]);
        $this->insertPayment($clinic, $invoice, $patient, '100000.00', 'card_pos', [
            'payment_number' => 'PAY-SLICE5-TWO',
            'paid_at' => self::PAYMENT_TWO_AT,
        ]);
        $this->seedVisitHistory($visit, 'awaiting_payment', 'paid', 'پرداخت کامل شد');

        $patientRow = $this->patientRow($patient);
        $mobile = (string) $patientRow['mobile'];
        $mrn = (string) $patientRow['mrn'];

        wp_set_current_user($secretary);
        // FULL persisted rows (not just counts): a before/after comparison of
        // these values detects UPDATEs as well as INSERT/DELETE.
        $rowsBefore = $this->persistedReceiptRows($visit, $invoice);
        $before = $this->readOnlySnapshot();
        $queries = [];
        $capture = function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };
        add_filter('query', $capture, PHP_INT_MAX);
        $response = $this->dispatch('GET', sprintf(self::RECEIPT, $visit), [], $this->scopeHeaders($clinic, $location));
        remove_filter('query', $capture, PHP_INT_MAX);

        self::assertSame(200, $response->get_status(), 'a normal fully settled invoice must yield a receipt — ' . $this->errorCode($response));
        $receipt = $this->payload($response)['receipt'] ?? null;
        self::assertIsArray($receipt, 'the receipt lives under `receipt` (D17-shaped envelope)');
        self::assertSame(self::RECEIPT_KEYS, array_keys($receipt), 'the receipt projection is exactly the bounded Slice 5 shape');

        self::assertSame(self::CLINIC_KEYS, array_keys($receipt['clinic']), 'clinic identity is name/address/phone only');
        self::assertSame('Slice5 Normal Clinic', (string) $receipt['clinic']['name']);
        self::assertSame(self::PATIENT_KEYS, array_keys($receipt['patient']), 'patient display name only — no MRN, no identifiers');
        self::assertSame(trim((string) $patientRow['first_name'] . ' ' . (string) $patientRow['last_name']), (string) $receipt['patient']['name']);
        self::assertSame('INV-SLICE5-NORMAL', (string) $receipt['invoice_number']);

        // Location-local date FIRST, then Jalali — never the UTC date substring.
        self::assertSame('2026-06-15', (string) $receipt['invoice_date'], 'the invoice instant is presented in the CURRENT trusted Location timezone');
        self::assertSame(Jalali::formatYmd('2026-06-15'), (string) $receipt['jalali_invoice_date']);

        self::assertSame(self::TOTALS_KEYS, array_keys($receipt['totals']), 'totals are the durably stored invoice amounts');
        self::assertSame('250000.00', (string) $receipt['totals']['total']);
        self::assertSame('250000.00', (string) $receipt['totals']['paid_amount']);
        self::assertSame('0.00', (string) $receipt['totals']['balance']);
        self::assertSame('IRR', (string) $receipt['totals']['currency']);
        self::assertSame('0.00', (string) $receipt['totals']['discount']);
        self::assertSame('0.00', (string) $receipt['totals']['tax']);

        self::assertCount(2, $receipt['items'], 'every durably stored line item is projected');
        foreach ($receipt['items'] as $item) {
            self::assertSame(self::ITEM_KEYS, array_keys($item), 'item line shape is bounded');
        }
        self::assertSame('مشاورهٔ سرپایی', (string) $receipt['items'][0]['description']);
        self::assertSame('1.00', (string) $receipt['items'][0]['quantity']);
        self::assertSame('200000.00', (string) $receipt['items'][0]['unit_price']);
        self::assertSame('200000.00', (string) $receipt['items'][0]['amount']);
        self::assertSame('50000.00', (string) $receipt['items'][1]['amount']);

        self::assertCount(2, $receipt['payments'], 'every clean captured payment of this bounded path is projected');
        foreach ($receipt['payments'] as $payment) {
            self::assertSame(self::PAYMENT_KEYS, array_keys($payment), 'payment line shape is bounded');
        }
        self::assertSame('PAY-SLICE5-ONE', (string) $receipt['payments'][0]['payment_number']);
        self::assertSame('cash', (string) $receipt['payments'][0]['method']);
        self::assertSame('150000.00', (string) $receipt['payments'][0]['amount']);
        self::assertSame('card_pos', (string) $receipt['payments'][1]['method'], 'card_pos is the recorded manual method, nothing more');
        // 2026-06-15 21:30 UTC = 2026-06-16 01:00 Asia/Tehran — the local date crosses the UTC boundary.
        self::assertSame('2026-06-16', (string) $receipt['payments'][0]['paid_at'], 'payment instants are converted to the Location timezone first');
        self::assertSame(Jalali::formatYmd('2026-06-16'), (string) $receipt['payments'][0]['jalali_paid_at']);
        self::assertNotSame(Jalali::formatYmd(substr(self::PAYMENT_ONE_AT, 0, 10)), (string) $receipt['payments'][0]['jalali_paid_at'], 'the UTC date substring must never be the receipt date');
        self::assertSame('2026-06-16', (string) $receipt['payments'][1]['paid_at']);

        $encoded = (string) wp_json_encode($receipt);
        self::assertStringNotContainsString($mobile, $encoded, 'patient mobile never appears in the receipt');
        self::assertStringNotContainsString($mrn, $encoded, 'patient MRN never appears in the receipt');
        foreach ([
            'patient_id', 'visit_id', 'invoice_id', 'payment_id', 'location_id', 'clinic_id',
            'national_id', 'mobile', 'mrn', 'transaction_ref', 'refunded_amount', 'void_reason',
            'adjustment', 'refund', 'voided', 'idempotency',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, 'receipt projection keeps no ' . $forbidden . ' field');
        }

        $projectionQueries = array_values(array_filter(
            $queries,
            static fn (string $query): bool => str_contains($query, 'cpms_invoice_items') || str_contains($query, 'cpms_payments')
        ));
        self::assertLessThanOrEqual(2, count($projectionQueries), 'items and payments are read with one bounded query each (no N+1)');
        // The ceiling covers the established trusted-scope + permission path
        // (establisher + authorization) plus the bounded projection reads; the
        // N+1 guard above is the projection-specific assertion.
        self::assertLessThanOrEqual(24, count($queries), 'the whole receipt read stays bounded');

        self::assertSame(
            $rowsBefore,
            $this->persistedReceiptRows($visit, $invoice),
            'every persisted row the receipt reads (Visit, invoice, items, payments, adjustments, history, patient, clinic) is value-identical after the GET'
        );
        self::assertSame($before, $this->readOnlySnapshot(), 'the receipt GET inserts or deletes no row (count-level guard) and writes no audit row');

        // GET-only: the same path never accepts a mutation verb.
        $post = $this->dispatch('POST', sprintf(self::RECEIPT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(404, $post->get_status(), 'the receipt surface is GET-only');
        self::assertSame('rest_no_route', $this->errorCode($post));
        self::assertSame($before, $this->readOnlySnapshot(), 'a rejected POST inserts or deletes no row (count-level guard)');
        self::assertNotSame(0, $firstPayment, 'the seeded clean capture exists');
    }

    /**
     * Group 2 — the authoritative timezone is the persisted CURRENT Location
     * row: the same stored UTC instant renders a different Location-local
     * (and Jalali) date per Location timezone; no ambient/UTC/hardcoded
     * timezone is used.
     */
    public function testReceiptUsesThePersistedCurrentLocationTimezone(): void
    {
        $org = $this->insertOrg('Slice5 timezone org');

        // Tehran (+03:30): 2026-06-15 17:00 UTC → 20:30 local, same date.
        $tehranClinic = $this->insertClinic($org, 'Slice5 Tehran Clinic', 'Asia/Tehran');
        $tehranLocation = $this->insertLocation($tehranClinic, 'Asia/Tehran');
        $tehranSecretary = $this->makeUser('phase12_slice5_tz_tehran', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($tehranSecretary, $tehranClinic, 'cpms_secretary');
        $tehranClinician = $this->insertClinician($tehranClinic, 'Dr Tehran');
        $tehranPatient = $this->insertPatient($tehranClinic, 'ReceiptTehran');
        $tehranVisit = $this->insertVisit($tehranClinic, $tehranLocation, $tehranPatient, $tehranClinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $this->seedSettledInvoice($tehranClinic, $tehranLocation, $tehranPatient, $tehranVisit, $tehranSecretary, '100000.00', self::INVOICE_CREATED_AT);

        // Tokyo (+09:00): the SAME instant is already the next local date.
        $tokyoClinic = $this->insertClinic($org, 'Slice5 Tokyo Clinic', 'Asia/Tokyo');
        $tokyoLocation = $this->insertLocation($tokyoClinic, 'Asia/Tokyo');
        $tokyoSecretary = $this->makeUser('phase12_slice5_tz_tokyo', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($tokyoSecretary, $tokyoClinic, 'cpms_secretary');
        $tokyoClinician = $this->insertClinician($tokyoClinic, 'Dr Tokyo');
        $tokyoPatient = $this->insertPatient($tokyoClinic, 'ReceiptTokyo');
        $tokyoVisit = $this->insertVisit($tokyoClinic, $tokyoLocation, $tokyoPatient, $tokyoClinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $this->seedSettledInvoice($tokyoClinic, $tokyoLocation, $tokyoPatient, $tokyoVisit, $tokyoSecretary, '100000.00', self::INVOICE_CREATED_AT);

        wp_set_current_user($tehranSecretary);
        $tehranResponse = $this->dispatch('GET', sprintf(self::RECEIPT, $tehranVisit), [], $this->scopeHeaders($tehranClinic, $tehranLocation));
        self::assertSame(200, $tehranResponse->get_status(), 'Tehran receipt resolves — ' . $this->errorCode($tehranResponse));
        $tehranReceipt = $this->payload($tehranResponse)['receipt'];

        wp_set_current_user($tokyoSecretary);
        $tokyoResponse = $this->dispatch('GET', sprintf(self::RECEIPT, $tokyoVisit), [], $this->scopeHeaders($tokyoClinic, $tokyoLocation));
        self::assertSame(200, $tokyoResponse->get_status(), 'Tokyo receipt resolves — ' . $this->errorCode($tokyoResponse));
        $tokyoReceipt = $this->payload($tokyoResponse)['receipt'];

        self::assertSame('2026-06-15', (string) $tehranReceipt['invoice_date'], 'Tehran (+03:30) keeps the UTC date for this instant');
        self::assertSame('2026-06-16', (string) $tokyoReceipt['invoice_date'], 'Tokyo (+09:00) renders the next local date for the SAME instant');
        self::assertSame(Jalali::formatYmd('2026-06-15'), (string) $tehranReceipt['jalali_invoice_date']);
        self::assertSame(Jalali::formatYmd('2026-06-16'), (string) $tokyoReceipt['jalali_invoice_date']);
        self::assertSame($tehranReceipt['payments'][0]['amount'], $tokyoReceipt['payments'][0]['amount'], 'the money is identical; only the Location-local presentation differs');
        self::assertSame('1405/03/26', (string) $tehranReceipt['jalali_invoice_date'], 'the repository Jalali utility is the authority for presentation');
        self::assertSame('1405/03/27', (string) $tokyoReceipt['jalali_invoice_date']);
    }

    /**
     * Group 3 — authorization: nonce + the EXISTING `cpms_invoice_read`
     * capability + an active same-Clinic membership. Membership alone is never
     * authority and no role gains access.
     */
    public function testReceiptRequiresExistingInvoiceReadAuthorityAndActiveMembership(): void
    {
        $org = $this->insertOrg('Slice5 authority org');
        $clinic = $this->insertClinic($org, 'Slice5 Authority Clinic', 'Asia/Tehran');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice5_auth_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $doctor = $this->makeUser('phase12_slice5_auth_doctor', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($doctor, $clinic, 'cpms_doctor');
        $accountant = $this->makeUser('phase12_slice5_auth_accountant', RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountant, $clinic, 'cpms_accountant');
        $manager = $this->makeUser('phase12_slice5_auth_manager', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($manager, $clinic, 'cpms_manager');
        $noMembership = $this->makeUser('phase12_slice5_auth_nomember', RolesAndCapabilities::ROLE_SECRETARY);

        $clinician = $this->insertClinician($clinic, 'Dr Authority5');
        $patient = $this->insertPatient($clinic, 'Authority5');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $this->seedSettledInvoice($clinic, $location, $patient, $visit, $secretary, '100000.00', self::INVOICE_CREATED_AT);

        // 1) nonce is mandatory (established guard order: nonce → cap → scope).
        wp_set_current_user($secretary);
        $noNonce = $this->dispatch('GET', sprintf(self::RECEIPT, $visit), [], $this->scopeHeaders($clinic, $location), null, [], false);
        self::assertSame(403, $noNonce->get_status());
        self::assertSame('CLINIC_INVALID_NONCE', $this->errorCode($noNonce));

        $before = $this->readOnlySnapshot();

        // 2) A secretary with the existing invoice-read authority reads it.
        $ok = $this->dispatch('GET', sprintf(self::RECEIPT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $ok->get_status(), 'the existing cpms_invoice_read authority serves the receipt — ' . $this->errorCode($ok));

        // 3) A doctor keeps the same existing invoice-read authority (parity with D12b/D17).
        wp_set_current_user($doctor);
        $doctorRead = $this->dispatch('GET', sprintf(self::RECEIPT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $doctorRead->get_status(), 'the doctor keeps the existing invoice-read boundary — ' . $this->errorCode($doctorRead));

        // 4) An accountant has the existing invoice-read authority but is NOT a
        //    Finance module reader (no cpms_queue_read): this route is exactly
        //    the existing D12b/D17 read class, so parity holds and the module
        //    UI boundary stays unchanged.
        self::assertFalse(StaffPortalShell::finance_module_eligible($accountant), 'accountant stays outside the Finance module UI boundary');
        self::assertTrue(user_can($accountant, RolesAndCapabilities::INVOICE_READ), 'accountant holds the existing invoice-read capability');
        wp_set_current_user($accountant);
        $accountantRead = $this->dispatch('GET', sprintf(self::RECEIPT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $accountantRead->get_status(), 'the receipt route is the existing invoice-read class — ' . $this->errorCode($accountantRead));

        // 5) A manager has no finance/invoice read capability — denied.
        self::assertFalse(user_can($manager, RolesAndCapabilities::INVOICE_READ), 'manager holds no invoice-read capability');
        wp_set_current_user($manager);
        $managerRead = $this->dispatch('GET', sprintf(self::RECEIPT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(403, $managerRead->get_status(), 'the receipt never widens access beyond the existing invoice-read authority');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($managerRead));

        // 6) A capability holder WITHOUT an active membership cannot establish
        //    the trusted scope: fail closed, membership alone is never authority.
        wp_set_current_user($noMembership);
        $nomember = $this->dispatch('GET', sprintf(self::RECEIPT, $visit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(403, $nomember->get_status());
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($nomember));

        self::assertSame($before, $this->readOnlySnapshot(), 'no rejected receipt read writes anything');
    }

    /**
     * Group 4 — the Visit id is a selector, never authority: foreign Clinic,
     * same-Clinic foreign Location and unknown ids share the established
     * non-enumerating 404 parity and read nothing.
     */
    public function testReceiptSelectorCannotEscapeTrustedClinicOrCurrentLocation(): void
    {
        $org = $this->insertOrg('Slice5 isolation org');
        $clinic = $this->insertClinic($org, 'Slice5 Isolation Clinic', 'Asia/Tehran');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $otherLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $foreignClinic = $this->insertClinic($org, 'Slice5 Isolation Foreign', 'Asia/Tehran');
        $foreignLocation = $this->insertLocation($foreignClinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice5_iso_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Iso5');
        $foreignClinician = $this->insertClinician($foreignClinic, 'Dr Iso5Foreign');
        $patient = $this->insertPatient($clinic, 'Iso5');
        $foreignPatient = $this->insertPatient($foreignClinic, 'Iso5Foreign');

        $otherLocationVisit = $this->insertVisit($clinic, $otherLocation, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $this->seedSettledInvoice($clinic, $otherLocation, $patient, $otherLocationVisit, $secretary, '70000.00', self::INVOICE_CREATED_AT);
        $foreignVisit = $this->insertVisit($foreignClinic, $foreignLocation, $foreignPatient, $foreignClinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $this->seedSettledInvoice($foreignClinic, $foreignLocation, $foreignPatient, $foreignVisit, $secretary, '70000.00', self::INVOICE_CREATED_AT);

        wp_set_current_user($secretary);
        $before = $this->readOnlySnapshot();

        $cases = [
            'same-Clinic other-Location Visit' => $otherLocationVisit,
            'foreign-Clinic Visit' => $foreignVisit,
            'unknown Visit id' => 999999999,
        ];
        foreach ($cases as $label => $target) {
            $response = $this->dispatch('GET', sprintf(self::RECEIPT, $target), [], $this->scopeHeaders($clinic, $location));
            self::assertSame(404, $response->get_status(), $label . ' selector must fail closed with 404 parity');
            self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response), $label . ' selector must not disclose existence');
            self::assertStringNotContainsString('receipt', strtolower((string) wp_json_encode($response->get_data())), $label . ' never returns receipt data');
        }

        self::assertSame($before, $this->readOnlySnapshot(), 'no rejected selector read writes anything (count-level guard)');
    }

    /**
     * Group 5 — the established strict 0/1/N Location policy applies to the
     * receipt unchanged (0 eligible ⇒ fail closed; 1 ⇒ auto-resolution;
     * N>1 ⇒ explicit selector), including the invalid-timezone fail-closed
     * branch.
     */
    public function testReceiptAppliesTheEstablishedZeroOneNLocationPolicy(): void
    {
        $org = $this->insertOrg('Slice5 policy org');
        $clinic = $this->insertClinic($org, 'Slice5 Policy Clinic', 'Asia/Tehran');
        $first = $this->insertLocation($clinic, 'Asia/Tehran');
        $second = $this->insertLocation($clinic, 'Asia/Tehran');
        $inactive = $this->insertLocation($clinic, 'Asia/Tehran');
        $this->updateLocationActive($inactive, 0);
        $brokenZone = $this->insertLocation($clinic, 'Asia/Tehran');
        $foreignClinic = $this->insertClinic($org, 'Slice5 Policy Foreign', 'Asia/Tehran');
        $foreignLocation = $this->insertLocation($foreignClinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice5_policy_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Policy5');
        $patient = $this->insertPatient($clinic, 'Policy5');
        $firstVisit = $this->insertVisit($clinic, $first, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $this->seedSettledInvoice($clinic, $first, $patient, $firstVisit, $secretary, '100000.00', self::INVOICE_CREATED_AT);

        wp_set_current_user($secretary);

        // N>1 without a selector never auto-resolves.
        $ambiguous = $this->dispatch('GET', sprintf(self::RECEIPT, $firstVisit), [], $this->scopeHeaders($clinic));
        self::assertSame(400, $ambiguous->get_status(), 'two+ eligible Locations require an explicit trusted Location selector');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($ambiguous));

        // An explicit valid selector resolves.
        $selected = $this->dispatch('GET', sprintf(self::RECEIPT, $firstVisit), [], $this->scopeHeaders($clinic, $first));
        self::assertSame(200, $selected->get_status(), 'an explicit eligible selector resolves the CURRENT Location — ' . $this->errorCode($selected));

        // A foreign-Clinic Location and an inactive Location are never authority.
        $foreign = $this->dispatch('GET', sprintf(self::RECEIPT, $firstVisit), [], $this->scopeHeaders($clinic, $foreignLocation));
        self::assertSame(403, $foreign->get_status(), 'a foreign-Clinic Location selector is not acceptable authority');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($foreign));
        $inactiveRead = $this->dispatch('GET', sprintf(self::RECEIPT, $firstVisit), [], $this->scopeHeaders($clinic, $inactive));
        self::assertSame(403, $inactiveRead->get_status(), 'an inactive Location selector is not acceptable authority');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($inactiveRead));

        // An unusable persisted timezone fails closed instead of fabricating a date.
        $this->setLocationTimezone($brokenZone, 'Not/AZone');
        $brokenVisit = $this->insertVisit($clinic, $brokenZone, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $this->seedSettledInvoice($clinic, $brokenZone, $patient, $brokenVisit, $secretary, '100000.00', self::INVOICE_CREATED_AT);
        $broken = $this->dispatch('GET', sprintf(self::RECEIPT, $brokenVisit), [], $this->scopeHeaders($clinic, $brokenZone));
        self::assertSame(403, $broken->get_status(), 'an unusable Location timezone fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($broken));
        self::assertSame('timezone', (string) ($this->errorData($broken)['reason'] ?? ''), 'the timezone reason is explicit');

        // Zero eligible Locations: fail closed (never fabricate a receipt).
        $emptyClinic = $this->insertClinic($org, 'Slice5 Policy Empty', 'Asia/Tehran');
        $emptyLocation = $this->insertLocation($emptyClinic, 'Asia/Tehran');
        $this->updateLocationActive($emptyLocation, 0);
        cpms_test_seed_membership($secretary, $emptyClinic, 'cpms_secretary');
        $emptyClinician = $this->insertClinician($emptyClinic, 'Dr Policy5Empty');
        $emptyPatient = $this->insertPatient($emptyClinic, 'Policy5Empty');
        $emptyVisit = $this->insertVisit($emptyClinic, $emptyLocation, $emptyPatient, $emptyClinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $this->seedSettledInvoice($emptyClinic, $emptyLocation, $emptyPatient, $emptyVisit, $secretary, '100000.00', self::INVOICE_CREATED_AT);
        $empty = $this->dispatch('GET', sprintf(self::RECEIPT, $emptyVisit), [], $this->scopeHeaders($emptyClinic));
        self::assertSame(403, $empty->get_status(), 'zero eligible Locations ⇒ the receipt fails closed (object-route parity)');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($empty));
        self::assertSame('location', (string) ($this->errorData($empty)['reason'] ?? ''), 'the unavailable Location reason is explicit');

        self::assertNotSame(0, $second, 'the second eligible Location exists (N>1 policy)');
    }

    /**
     * Group 6 — correction evidence is detected deterministically from durable
     * rows and always fails closed with the bounded explicit error; historical
     * correction/refund data is never mutated or erased.
     */
    public function testReceiptExcludesDurableCorrectionRefundAndAdjustmentEvidence(): void
    {
        $org = $this->insertOrg('Slice5 correction org');
        $clinic = $this->insertClinic($org, 'Slice5 Correction Clinic', 'Asia/Tehran');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice5_corr_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Correction');
        $now = App::db()->nowUtcSql();

        // (a) a voided payment row on an otherwise settled invoice.
        $voidedPatient = $this->insertPatient($clinic, 'CorrVoidPayment');
        $voidedVisit = $this->insertVisit($clinic, $location, $voidedPatient, $clinician, '2026-06-16', $now, 'paid');
        $voidedInvoice = $this->seedSettledInvoice($clinic, $location, $voidedPatient, $voidedVisit, $secretary, '100000.00', $now);
        $this->insertPayment($clinic, $voidedInvoice, $voidedPatient, '40000.00', 'cash', [
            'status' => 'voided',
            'void_reason' => 'ثبت اشتباه',
            'voided_at' => $now,
            'voided_by_wp_user_id' => $secretary,
        ]);

        // (b) a partially refunded payment row (still `captured`).
        $refundPatient = $this->insertPatient($clinic, 'CorrRefund');
        $refundVisit = $this->insertVisit($clinic, $location, $refundPatient, $clinician, '2026-06-16', $now, 'paid');
        $refundInvoice = $this->seedSettledInvoice($clinic, $location, $refundPatient, $refundVisit, $secretary, '100000.00', $now);
        $this->insertPayment($clinic, $refundInvoice, $refundPatient, '100000.00', 'cash', [
            'refunded_amount' => '30000.00',
        ]);

        // (c) a durable credit adjustment row.
        $adjustPatient = $this->insertPatient($clinic, 'CorrAdjust');
        $adjustVisit = $this->insertVisit($clinic, $location, $adjustPatient, $clinician, '2026-06-16', $now, 'paid');
        $adjustInvoice = $this->seedSettledInvoice($clinic, $location, $adjustPatient, $adjustVisit, $secretary, '100000.00', $now);
        $this->insertAdjustment($adjustInvoice, 'credit', '10000.00', 'اصلاح تعرفه', $secretary);

        // (d) a voided invoice in the same Visit's history (correction/re-issue).
        $reissuePatient = $this->insertPatient($clinic, 'CorrReissue');
        $reissueVisit = $this->insertVisit($clinic, $location, $reissuePatient, $clinician, '2026-06-16', $now, 'paid');
        $this->seedSettledInvoice($clinic, $location, $reissuePatient, $reissueVisit, $secretary, '100000.00', $now);
        $this->insertInvoice($clinic, $location, $reissuePatient, $reissueVisit, $secretary, 'voided', '100000.00', '0.00', '100000.00', [
            'invoice_number' => 'INV-SLICE5-VOIDED-' . bin2hex(random_bytes(3)),
            'void_reason' => 'صدور مجدد',
            'voided_at' => $now,
        ]);

        // (e) two non-voided invoices on one Visit (ambiguous accounting history).
        $ambiguousPatient = $this->insertPatient($clinic, 'CorrAmbiguous');
        $ambiguousVisit = $this->insertVisit($clinic, $location, $ambiguousPatient, $clinician, '2026-06-16', $now, 'paid');
        $this->seedSettledInvoice($clinic, $location, $ambiguousPatient, $ambiguousVisit, $secretary, '100000.00', $now);
        $this->insertInvoice($clinic, $location, $ambiguousPatient, $ambiguousVisit, $secretary, 'paid', '50000.00', '50000.00', '0.00', [
            'invoice_number' => 'INV-SLICE5-SECOND-' . bin2hex(random_bytes(3)),
        ]);

        wp_set_current_user($secretary);
        $before = $this->readOnlySnapshot();

        $cases = [
            'voided payment row' => $voidedVisit,
            'refunded payment row' => $refundVisit,
            'adjustment row' => $adjustVisit,
            'voided invoice on the Visit' => $reissueVisit,
            'ambiguous non-voided invoices' => $ambiguousVisit,
        ];
        foreach ($cases as $label => $target) {
            $response = $this->dispatch('GET', sprintf(self::RECEIPT, $target), [], $this->scopeHeaders($clinic, $location));
            self::assertSame(409, $response->get_status(), $label . ' must fail closed rather than print misleading accounting history');
            self::assertSame('CLINIC_RECEIPT_NOT_ELIGIBLE', $this->errorCode($response), $label . ' uses the bounded explicit eligibility error');
            self::assertSame('correction_evidence', (string) ($this->errorData($response)['reason'] ?? ''), $label . ' reports the bounded correction reason');
            self::assertContains((string) ($this->errorData($response)['reason'] ?? ''), self::ELIGIBILITY_REASONS, $label . ' stays inside the enumerated reason vocabulary');
        }

        self::assertSame($before, $this->readOnlySnapshot(), 'no ineligible receipt read mutates or erases correction/refund/adjustment data');
        self::assertSame(
            1,
            (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_payment_adjustments') . ' WHERE invoice_id = %d', [$adjustInvoice]),
            'the existing adjustment row is preserved untouched'
        );
        self::assertSame(
            1,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . " WHERE id IN (SELECT id FROM " . App::db()->table('cpms_payments') . " WHERE invoice_id = %d AND status = 'voided')",
                [$voidedInvoice]
            ),
            'the existing void marker is preserved untouched'
        );
    }

    /**
     * Group 7 — the remaining bounded eligibility branches: unsettled,
     * non-reconciling, item-less, invoice-less and waived paths all fail closed
     * with the explicit bounded reason and no write.
     */
    public function testReceiptFailsClosedOnUnsettledInconsistentOrWaivedAccounting(): void
    {
        $org = $this->insertOrg('Slice5 eligibility org');
        $clinic = $this->insertClinic($org, 'Slice5 Eligibility Clinic', 'Asia/Tehran');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice5_elig_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Eligibility');
        $now = App::db()->nowUtcSql();

        // (a) no invoice at all (waive/no-invoice path).
        $noInvoicePatient = $this->insertPatient($clinic, 'EligNoInvoice');
        $noInvoiceVisit = $this->insertVisit($clinic, $location, $noInvoicePatient, $clinician, '2026-06-16', $now, 'checked_out');

        // (b) a genuinely open invoice with a remaining balance.
        $openPatient = $this->insertPatient($clinic, 'EligOpen');
        $openVisit = $this->insertVisit($clinic, $location, $openPatient, $clinician, '2026-06-16', $now, 'awaiting_payment');
        $this->insertInvoice($clinic, $location, $openPatient, $openVisit, $secretary, 'open', '100000.00', '0.00', '100000.00', [
            'invoice_number' => 'INV-SLICE5-OPEN-' . bin2hex(random_bytes(3)),
        ]);

        // (c) a partially settled invoice.
        $partialPatient = $this->insertPatient($clinic, 'EligPartial');
        $partialVisit = $this->insertVisit($clinic, $location, $partialPatient, $clinician, '2026-06-16', $now, 'awaiting_payment');
        $partialInvoice = $this->insertInvoice($clinic, $location, $partialPatient, $partialVisit, $secretary, 'partial', '100000.00', '30000.00', '70000.00', [
            'invoice_number' => 'INV-S5-PARTIAL-' . bin2hex(random_bytes(3)),
        ]);
        $this->insertItem($partialInvoice, 'مشاوره', '1.00', '100000.00', '100000.00');
        $this->insertPayment($clinic, $partialInvoice, $partialPatient, '30000.00', 'cash', []);

        // (d) `paid` status whose durable amounts do not reconcile (no payment rows).
        $mismatchPatient = $this->insertPatient($clinic, 'EligMismatch');
        $mismatchVisit = $this->insertVisit($clinic, $location, $mismatchPatient, $clinician, '2026-06-16', $now, 'paid');
        $this->insertInvoice($clinic, $location, $mismatchPatient, $mismatchVisit, $secretary, 'paid', '100000.00', '100000.00', '0.00', [
            'invoice_number' => 'INV-S5-MISMATCH-' . bin2hex(random_bytes(3)),
        ]);

        // (e) a settled invoice with no line items.
        $barePatient = $this->insertPatient($clinic, 'EligBare');
        $bareVisit = $this->insertVisit($clinic, $location, $barePatient, $clinician, '2026-06-16', $now, 'paid');
        $bareInvoice = $this->insertInvoice($clinic, $location, $barePatient, $bareVisit, $secretary, 'paid', '100000.00', '100000.00', '0.00', [
            'invoice_number' => 'INV-SLICE5-BARE-' . bin2hex(random_bytes(3)),
        ]);
        $this->insertPayment($clinic, $bareInvoice, $barePatient, '100000.00', 'cash', []);

        // (f) a clean, settled invoice whose Visit was checked out through the
        //     durable WAIVE transition (awaiting_payment → checked_out).
        $waivePatient = $this->insertPatient($clinic, 'EligWaive');
        $waiveVisit = $this->insertVisit($clinic, $location, $waivePatient, $clinician, '2026-06-16', $now, 'checked_out');
        $this->seedSettledInvoice($clinic, $location, $waivePatient, $waiveVisit, $secretary, '100000.00', $now);
        $this->seedVisitHistory($waiveVisit, 'awaiting_payment', 'checked_out', 'معافیت از پرداخت');

        wp_set_current_user($secretary);
        $before = $this->readOnlySnapshot();

        $expected = [
            'invoice_missing' => $noInvoiceVisit,
            'invoice_not_settled' => $openVisit,
            'invoice_not_settled (partial)' => $partialVisit,
            'settlement_integrity' => $mismatchVisit,
            'items_missing' => $bareVisit,
            'waive_evidence' => $waiveVisit,
        ];
        foreach ($expected as $label => $target) {
            $reason = str_contains($label, 'partial') ? 'invoice_not_settled' : $label;
            $response = $this->dispatch('GET', sprintf(self::RECEIPT, $target), [], $this->scopeHeaders($clinic, $location));
            self::assertSame(409, $response->get_status(), $label . ' must fail closed');
            self::assertSame('CLINIC_RECEIPT_NOT_ELIGIBLE', $this->errorCode($response), $label . ' uses the bounded explicit eligibility error');
            $data = $this->errorData($response);
            self::assertSame($reason, (string) ($data['reason'] ?? ''), $label . ' reports its bounded reason');
            self::assertContains((string) ($data['reason'] ?? ''), self::ELIGIBILITY_REASONS, $label . ' stays inside the enumerated reason vocabulary');
            // The bounded failure envelope carries only the reason (the 409
            // code itself intentionally contains the word "receipt", so the
            // leak check is on the payload keys, not on a substring scan).
            self::assertSame(
                [],
                array_intersect(['receipt', 'clinic', 'patient', 'items', 'totals', 'payments', 'invoice_number'], array_keys($data)),
                $label . ' never leaks receipt data'
            );
        }

        self::assertSame($before, $this->readOnlySnapshot(), 'no ineligible path writes anything (count-level guard)');
    }

    /**
     * Group 10 — durable ownership/linkage: the persisted Visit is the
     * tenant/Location anchor, so an invoice or payment row that disagrees with
     * that anchor on the durable fields it actually carries (visit_id /
     * clinic_id / patient_id / nullable location_id) can never produce a
     * receipt — not a partial projection and not another patient's display
     * name. Inconsistent rows are neither repaired nor disclosed: they stay
     * indistinguishable from a missing Visit (the established non-enumerating
     * 404 parity). `location_id` follows the established nullable
     * finance-Location rule of migration 0015 (`FinanceService` persists NULL
     * when the Visit carries no Location; the Slice 4 paid board joins
     * `location_id = %d OR location_id IS NULL`), so NULL stays printable while
     * any non-NULL value must match the Visit Location exactly.
     */
    public function testReceiptFailsClosedWhenDurableRowsDoNotBelongToTheAuthorizedVisit(): void
    {
        $org = $this->insertOrg('Slice5 ownership org');
        $clinic = $this->insertClinic($org, 'Slice5 Ownership Clinic', 'Asia/Tehran');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $otherLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $foreignClinic = $this->insertClinic($org, 'Slice5 Ownership Foreign', 'Asia/Tehran');
        $foreignLocation = $this->insertLocation($foreignClinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice5_owner_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Ownership5');
        $patient = $this->insertPatient($clinic, 'Owner5');
        $otherPatient = $this->insertPatient($clinic, 'Owner5Other');
        $foreignPatient = $this->insertPatient($foreignClinic, 'Owner5Foreign');

        $patientName = $this->displayName($patient);
        $otherName = $this->displayName($otherPatient);
        $foreignName = $this->displayName($foreignPatient);

        /**
         * Seeds a fully eligible-looking settlement (paid/zero balance, one
         * item, one clean captured payment, no adjustment, no waive) so that
         * ONLY the deliberately broken durable linkage can reject it.
         *
         * @param array<string, mixed> $invoiceOverrides
         * @param array<string, mixed> $paymentOverrides
         */
        $settle = function (int $visitId, array $invoiceOverrides = [], array $paymentOverrides = []) use ($clinic, $location, $patient, $secretary): int {
            $invoice = $this->insertInvoice($clinic, $location, $patient, $visitId, $secretary, 'paid', '150000.00', '150000.00', '0.00', array_merge([
                'invoice_number' => 'INV-S5-OWN-' . bin2hex(random_bytes(4)),
                'created_at' => self::INVOICE_CREATED_AT,
                'updated_at' => self::INVOICE_CREATED_AT,
            ], $invoiceOverrides));
            $this->insertItem($invoice, 'مشاوره و ویزیت', '1.00', '150000.00', '150000.00');
            $this->insertPayment($clinic, $invoice, $patient, '150000.00', 'cash', array_merge([
                'payment_number' => 'PAY-S5-OWN-' . bin2hex(random_bytes(4)),
                'paid_at' => self::INVOICE_CREATED_AT,
            ], $paymentOverrides));
            $this->seedVisitHistory($visitId, 'awaiting_payment', 'paid', 'پرداخت کامل شد');

            return $invoice;
        };

        wp_set_current_user($secretary);

        // Reference rejection: a selector that resolves to no Visit at all.
        // Every ownership mismatch must be indistinguishable from it.
        $reference = $this->dispatch('GET', sprintf(self::RECEIPT, 987654321), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(404, $reference->get_status(), 'the non-enumerating reference rejection is 404');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($reference));
        $referenceEnvelope = (array) $reference->get_data();

        $mismatches = [];

        // (a) invoice→Visit linkage: the anchor Visit carries no finance
        //     document of its own while a settled invoice exists on a sibling
        //     Visit of the same Clinic/Location (the durable selector mismatch
        //     the schema can express: `cpms_invoices.visit_id`).
        $linkageVisit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $siblingVisit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $siblingInvoice = $settle($siblingVisit);
        $mismatches['invoice visit linkage'] = [$linkageVisit, $siblingInvoice, 409, 'CLINIC_RECEIPT_NOT_ELIGIBLE'];

        // (b) invoice.clinic_id disagrees with the authorized Clinic.
        $clinicVisit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $clinicInvoice = $settle($clinicVisit, ['clinic_id' => $foreignClinic]);
        $mismatches['invoice clinic linkage'] = [$clinicVisit, $clinicInvoice, 404, 'CLINIC_NOT_FOUND'];

        // (c) invoice.patient_id points at another patient of the same Clinic —
        //     the receipt must never render that patient's name.
        $patientVisit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $patientInvoice = $settle($patientVisit, ['patient_id' => $otherPatient]);
        $mismatches['invoice patient linkage'] = [$patientVisit, $patientInvoice, 404, 'CLINIC_NOT_FOUND'];

        // (d) invoice.location_id points at another Location of the same Clinic.
        $invoiceLocationVisit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $invoiceLocationInvoice = $settle($invoiceLocationVisit, ['location_id' => $otherLocation]);
        $mismatches['invoice location linkage'] = [$invoiceLocationVisit, $invoiceLocationInvoice, 404, 'CLINIC_NOT_FOUND'];

        // (e) a captured payment of that invoice belongs to another Clinic.
        $paymentClinicVisit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $paymentClinicInvoice = $settle($paymentClinicVisit, [], ['clinic_id' => $foreignClinic]);
        $mismatches['payment clinic linkage'] = [$paymentClinicVisit, $paymentClinicInvoice, 404, 'CLINIC_NOT_FOUND'];

        // (f) a captured payment belongs to another patient of the same Clinic.
        $paymentPatientVisit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $paymentPatientInvoice = $settle($paymentPatientVisit, [], ['patient_id' => $otherPatient]);
        $mismatches['payment patient linkage'] = [$paymentPatientVisit, $paymentPatientInvoice, 404, 'CLINIC_NOT_FOUND'];

        // (g) a captured payment carries another Location of the same Clinic.
        $paymentLocationVisit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $paymentLocationInvoice = $settle($paymentLocationVisit, [], ['location_id' => $otherLocation]);
        $mismatches['payment location linkage'] = [$paymentLocationVisit, $paymentLocationInvoice, 404, 'CLINIC_NOT_FOUND'];

        foreach ($mismatches as $label => $case) {
            [$target, $invoiceId, $status, $code] = $case;
            $rowsBefore = $this->persistedReceiptRows($target, $invoiceId);
            $before = $this->readOnlySnapshot();
            $response = $this->dispatch('GET', sprintf(self::RECEIPT, $target), [], $this->scopeHeaders($clinic, $location));
            $data = (array) $response->get_data();

            self::assertSame($status, $response->get_status(), $label . ' must fail closed — got ' . $response->get_status());
            self::assertSame($code, $this->errorCode($response), $label . ' uses the established fail-closed code');
            self::assertSame(
                [],
                array_intersect(self::RECEIPT_KEYS, array_keys($this->payload($response))),
                $label . ' returns no receipt projection'
            );
            self::assertArrayNotHasKey('receipt', $this->payload($response), $label . ' never carries a receipt payload');

            $encoded = (string) wp_json_encode($data);
            self::assertStringNotContainsString($otherName, $encoded, $label . ' never leaks another patient of the Clinic');
            self::assertStringNotContainsString($foreignName, $encoded, $label . ' never leaks a foreign-Clinic patient');
            self::assertStringNotContainsString($patientName, $encoded, $label . ' never leaks even the anchor patient name (no partial projection)');

            if (404 === $status) {
                self::assertSame(
                    $referenceEnvelope,
                    $data,
                    $label . ' is indistinguishable from a missing Visit (non-enumerating parity)'
                );
            } else {
                self::assertSame(
                    'invoice_missing',
                    (string) ($this->errorData($response)['reason'] ?? ''),
                    'the unreachable-invoice case stays inside the bounded eligibility vocabulary'
                );
            }

            self::assertSame($rowsBefore, $this->persistedReceiptRows($target, $invoiceId), $label . ' repairs nothing: every persisted row is value-identical');
            self::assertSame($before, $this->readOnlySnapshot(), $label . ' writes nothing (count-level guard)');
        }

        // Positive side of the proven policy: the established nullable finance
        // Location (NULL — what the normal capture path persists) is legitimate
        // because the Visit carries the authoritative Location.
        $legacyVisit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $settle($legacyVisit, [
            'location_id' => null,
            'invoice_number' => 'INV-SLICE5-LEGACY-NULL',
        ]);
        $legacy = $this->dispatch('GET', sprintf(self::RECEIPT, $legacyVisit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $legacy->get_status(), 'a legacy NULL invoice.location_id stays printable — ' . $this->errorCode($legacy));
        $legacyReceipt = $this->payload($legacy)['receipt'] ?? null;
        self::assertIsArray($legacyReceipt, 'the NULL-Location receipt is the bounded projection');
        self::assertSame(self::RECEIPT_KEYS, array_keys($legacyReceipt));
        self::assertSame('INV-SLICE5-LEGACY-NULL', (string) $legacyReceipt['invoice_number']);
        self::assertSame($patientName, (string) $legacyReceipt['patient']['name'], 'the ANCHOR patient is displayed, never another patient');

        // A payment row that does carry a Location is consistent only when it
        // matches the Visit Location exactly.
        $locatedVisit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $settle($locatedVisit, ['invoice_number' => 'INV-SLICE5-LOCATED'], ['location_id' => $location]);
        $located = $this->dispatch('GET', sprintf(self::RECEIPT, $locatedVisit), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $located->get_status(), 'a payment Location equal to the Visit Location is consistent — ' . $this->errorCode($located));
        self::assertSame('INV-SLICE5-LOCATED', (string) ($this->payload($located)['receipt']['invoice_number'] ?? ''));

        self::assertNotSame(0, $foreignLocation, 'the foreign Location fixture exists (no invented authority)');
    }

    /**
     * Group 8 — GREEN-today controls: the existing D17 back-office receipt
     * keeps its unchanged behaviour (including its UTC-date presentation and
     * its MRN), the Slice 1 board and the Slice 4 paid board keep their exact
     * projections, and the shared D16 checkout route is untouched.
     */
    public function testExistingBackOfficeReceiptAndBoardProjectionsStayUnchanged(): void
    {
        $org = $this->insertOrg('Slice5 controls org');
        $clinic = $this->insertClinic($org, 'Slice5 Controls Clinic', 'Asia/Tehran');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice5_controls_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Controls');
        $patient = $this->insertPatient($clinic, 'Controls5');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $secretary, 'paid', '100000.00', '100000.00', '0.00', [
            'created_at' => self::INVOICE_CREATED_AT,
            'updated_at' => self::INVOICE_CREATED_AT,
            'invoice_number' => 'INV-SLICE5-CONTROL',
        ]);
        $this->insertItem($invoice, 'مشاوره', '1.00', '100000.00', '100000.00');
        $this->insertPayment($clinic, $invoice, $patient, '100000.00', 'cash', [
            'payment_number' => 'PAY-SLICE5-CONTROL',
            'paid_at' => self::PAYMENT_ONE_AT,
        ]);
        $awaitingPatient = $this->insertPatient($clinic, 'ControlsAwaiting');
        $paidBoardPatient = $this->insertPatient($clinic, 'ControlsPaidBoard');
        // The Slice 1/4 boards are day-scoped to the CURRENT Location-local
        // operational day, so the GREEN-today control rows carry today's date
        // while the D17 control above keeps its fixed instant.
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Asia/Tehran'))
            ->format('Y-m-d');
        $awaitingVisit = $this->insertVisit($clinic, $location, $awaitingPatient, $clinician, $today, App::db()->nowUtcSql(), 'awaiting_payment');
        $paidBoardVisit = $this->insertVisit($clinic, $location, $paidBoardPatient, $clinician, $today, App::db()->nowUtcSql(), 'paid');
        $this->seedSettledInvoice($clinic, $location, $paidBoardPatient, $paidBoardVisit, $secretary, '50000.00', App::db()->nowUtcSql());

        $patientRow = $this->patientRow($patient);
        wp_set_current_user($secretary);

        // D17 — the existing back-office receipt is neither re-implemented nor changed.
        $d17 = $this->dispatch('GET', sprintf(self::D17_RECEIPT, $invoice), [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $d17->get_status(), 'the existing D17 receipt route stays available — ' . $this->errorCode($d17));
        $legacy = $this->payload($d17)['receipt'] ?? null;
        self::assertIsArray($legacy, 'D17 keeps its `receipt` envelope');
        self::assertSame('INV-SLICE5-CONTROL', (string) $legacy['invoice_number']);
        self::assertSame((string) $patientRow['mrn'], (string) ($legacy['patient']['mrn'] ?? ''), 'D17 keeps printing the MRN (unchanged behaviour)');
        self::assertSame(Jalali::formatYmd(substr(self::INVOICE_CREATED_AT, 0, 10)), (string) $legacy['jalali_date'], 'D17 keeps its existing UTC-derived date presentation (unchanged)');
        self::assertSame(Jalali::formatYmd(substr(self::PAYMENT_ONE_AT, 0, 10)), (string) $legacy['payments'][0]['jalali_paid_at'], 'D17 keeps its existing UTC-derived payment date (unchanged)');
        self::assertNotSame((string) $legacy['payments'][0]['jalali_paid_at'], Jalali::formatYmd('2026-06-16'), 'D17 is NOT silently corrected by this slice');

        // Slice 1 board projection unchanged.
        $board = $this->dispatch('GET', self::BOARD, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $board->get_status(), 'the Slice 1 board stays available — ' . $this->errorCode($board));
        $boardRows = $this->payload($board)['visits'] ?? [];
        $awaitingRow = null;
        foreach ($boardRows as $row) {
            self::assertSame(self::BOARD_ROW_KEYS, array_keys($row), 'the Slice 1 projection is unchanged by this slice');
            if ((int) $row['visit_id'] === $awaitingVisit) {
                $awaitingRow = $row;
            }
        }
        self::assertNotNull($awaitingRow, 'the awaiting-payment Visit is on the Slice 1 board');

        // Slice 4 paid board projection unchanged.
        $paid = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $paid->get_status(), 'the Slice 4 paid board stays available — ' . $this->errorCode($paid));
        $paidRows = $this->payload($paid)['visits'] ?? [];
        self::assertNotEmpty($paidRows, 'the settled Visit is on the paid board');
        $paidBoardIds = [];
        foreach ($paidRows as $row) {
            self::assertSame(self::PAID_ROW_KEYS, array_keys($row), 'the Slice 4 projection is unchanged by this slice');
            $paidBoardIds[] = (int) $row['visit_id'];
        }
        self::assertContains($paidBoardVisit, $paidBoardIds, 'the settled paid Visit is the GREEN-today paid-board row');

        // Context keeps its existing flags (no new flag is required by this slice).
        $context = $this->dispatch('GET', self::CONTEXT, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $context->get_status(), 'the Slice 1 context stays available — ' . $this->errorCode($context));
        $contextData = $this->payload($context);
        self::assertTrue((bool) ($contextData['can_issue_invoice'] ?? false), 'can_issue_invoice stays');
        self::assertTrue((bool) ($contextData['can_capture_payment'] ?? false), 'can_capture_payment stays');
        self::assertTrue((bool) ($contextData['can_check_out'] ?? false), 'can_check_out stays');
    }

    /**
     * Group 9 — the module template offers a bounded receipt panel with a
     * browser print action and NO new mutation/device/refund/void/adjustment
     * affordance; the paid board keeps exactly its existing read/checkout
     * controls plus the receipt read control.
     */
    public function testFinanceModuleRendersTheBoundedReceiptPanelAndPrintAction(): void
    {
        $org = $this->insertOrg('Slice5 template org');
        $clinic = $this->insertClinic($org, 'Slice5 Template Clinic', 'Asia/Tehran');
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $secretary = $this->makeUser('phase12_slice5_template_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, 'cpms_secretary');
        $clinician = $this->insertClinician($clinic, 'Dr Template5');
        $patient = $this->insertPatient($clinic, 'Template5');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, '2026-06-16', self::INVOICE_CREATED_AT, 'paid');
        $this->seedSettledInvoice($clinic, $location, $patient, $visit, $secretary, '100000.00', self::INVOICE_CREATED_AT);

        $template = (string) file_get_contents((string) StaffPortalShell::finance_module_template_path());
        foreach ([
            'finance-receipt',
            'finance-receipt-body',
            'finance-receipt-open',
            'finance-receipt-print',
            'finance-receipt-close',
            'window.print',
            'cpms-finance-printing',
            '/staff/portal/finance/visits/',
            'state.busy',
        ] as $required) {
            self::assertStringContainsString($required, $template, 'the module must expose the Slice 5 receipt contract: ' . $required);
        }
        foreach (['waive', 'معافیت', 'void', 'refund', 'adjustment', 'reissue', 'online', 'terminal', 'device', 'sdk', 'serial', 'usb', 'nfc', 'bluetooth', 'websocket', 'merchant'] as $forbidden) {
            if ('device' === $forbidden) {
                continue;
            }
            self::assertStringNotContainsString($forbidden, strtolower($template), 'out-of-scope vocabulary must not appear: ' . $forbidden);
        }
        $scanned = str_replace('width=device-width', 'width=viewport', strtolower($template));
        self::assertSame(1, substr_count(strtolower($template), 'device'), 'the only "device" occurrence is the standard viewport declaration');
        self::assertStringNotContainsString('device', $scanned, 'device/provider vocabulary must not appear beyond the viewport declaration');

        $html = $this->renderFinancePortal($secretary);
        self::assertStringContainsString('data-role="finance-receipt"', $html, 'the receipt panel is mounted in the module');
        self::assertStringContainsString('data-role="finance-receipt-print"', $html, 'the print action is mounted in the module');
        self::assertStringNotContainsString('application/pdf', strtolower($html), 'no server/browser PDF generation is wired');

        wp_set_current_user($secretary);
        $paid = $this->dispatch('GET', self::PAID, [], $this->scopeHeaders($clinic, $location));
        self::assertSame(200, $paid->get_status(), 'the paid board stays available for the receipt affordance — ' . $this->errorCode($paid));
        foreach ($this->payload($paid)['visits'] ?? [] as $row) {
            self::assertSame(self::PAID_ROW_KEYS, array_keys($row), 'the receipt affordance does not add any identifier to the paid projection');
            self::assertArrayNotHasKey('invoice_id', $row, 'the paid projection deliberately carries no invoice identifier');
        }
    }

    // ================= helpers =================

    /** @return array<string, mixed> */
    private function patientRow(int $patientId): array
    {
        return (array) App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d LIMIT 1',
            [$patientId]
        );
    }

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

    /** @return array<string, mixed>|null */
    private function invoiceRow(int $invoiceId): ?array
    {
        return App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d LIMIT 1', [$invoiceId]);
    }

    private function paymentCount(int $invoiceId): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d', [$invoiceId]);
    }

    /**
     * Seeds a genuinely settled invoice: one clean captured payment, one line
     * item and a durable paid_state history entry (fixture only).
     */
    private function seedSettledInvoice(
        int $clinicId,
        int $locationId,
        int $patientId,
        int $visitId,
        int $actorId,
        string $amount,
        string $createdAt
    ): int {
        $invoice = $this->insertInvoice($clinicId, $locationId, $patientId, $visitId, $actorId, 'paid', $amount, $amount, '0.00', [
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'invoice_number' => 'INV-SLICE5-' . bin2hex(random_bytes(4)),
        ]);
        $this->insertItem($invoice, 'مشاوره و ویزیت', '1.00', $amount, $amount);
        $this->insertPayment($clinicId, $invoice, $patientId, $amount, 'cash', [
            'payment_number' => 'PAY-SLICE5-' . bin2hex(random_bytes(4)),
            'paid_at' => $createdAt,
        ]);
        $this->seedVisitHistory($visitId, 'awaiting_payment', 'paid', 'پرداخت کامل شد');

        return $invoice;
    }

    private function seedVisitHistory(int $visitId, string $fromStatus, string $toStatus, string $note): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_visit_status_history', [
            'visit_id' => $visitId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_at' => App::db()->nowUtcSql(),
            'actor_wp_user_id' => 0,
            'actor_role' => 'secretary',
            'note' => $note,
            'request_id' => null,
        ]), 'visit history fixture insert');
    }

    private function insertItem(int $invoiceId, string $description, string $quantity, string $unitPrice, string $amount): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoice_items', [
            'invoice_id' => $invoiceId,
            'service_id' => null,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'discount' => '0.00',
        ]), 'invoice item fixture insert');

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertPayment(int $clinicId, int $invoiceId, int $patientId, string $amount, string $method, array $overrides): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $row = [
            'payment_number' => 'PAY-SLICE5-' . bin2hex(random_bytes(4)),
            'clinic_id' => $clinicId,
            'invoice_id' => $invoiceId,
            'patient_id' => $patientId,
            'amount' => $amount,
            'method' => $method,
            'transaction_ref' => null,
            'idempotency_key' => $this->uuid(),
            'status' => 'captured',
            'refunded_amount' => '0.00',
            'paid_at' => $now,
            'received_by_wp_user_id' => 0,
            'void_reason' => null,
            'voided_at' => null,
            'voided_by_wp_user_id' => null,
            'created_at' => $now,
        ];
        foreach ($overrides as $key => $value) {
            $row[$key] = $value;
        }
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_payments', $row), 'payment fixture insert');

        return (int) $wpdb->insert_id;
    }

    private function insertAdjustment(int $invoiceId, string $type, string $amount, string $reason, int $actorId): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_payment_adjustments', [
            'invoice_id' => $invoiceId,
            'payment_id' => null,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'approved_by_wp_user_id' => $actorId,
            'created_at' => App::db()->nowUtcSql(),
        ]), 'adjustment fixture insert');

        return (int) $wpdb->insert_id;
    }

    private function updateLocationActive(int $locationId, int $active): void
    {
        self::assertNotFalse(
            App::db()->query('UPDATE ' . App::db()->table('cpms_locations') . ' SET is_active = %d WHERE id = %d', [$active, $locationId])
        );
    }

    private function setLocationTimezone(int $locationId, string $timezone): void
    {
        self::assertNotFalse(
            App::db()->query('UPDATE ' . App::db()->table('cpms_locations') . ' SET timezone = %s WHERE id = %d', [$timezone, $locationId])
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

    private function insertClinic(int $orgId, string $name, string $timezone = 'Asia/Tehran'): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinics', ['organization_id' => $orgId, 'name' => $name, 'slug' => 'clinic-' . bin2hex(random_bytes(3)), 'timezone' => $timezone, 'created_at' => $now, 'updated_at' => $now]), 'clinic fixture insert');

        return (int) $wpdb->insert_id;
    }

    private function insertLocation(int $clinicId, string $timezone): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_locations', ['clinic_id' => $clinicId, 'name' => 'Slice5 Location', 'slug' => 'location-' . bin2hex(random_bytes(3)), 'timezone' => $timezone, 'is_primary' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]), 'Location fixture insert');

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', ['clinic_id' => $clinicId, 'mrn' => 'M-' . bin2hex(random_bytes(5)), 'first_name' => $tag, 'last_name' => 'Slice5', 'mobile' => '09' . random_int(1000000000, 9999999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]), 'patient fixture insert');

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

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertInvoice(
        int $clinicId,
        ?int $locationId,
        int $patientId,
        int $visitId,
        int $actorId,
        string $status,
        string $total = '100000.00',
        string $paid = '0.00',
        string $balance = '100000.00',
        array $overrides = []
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $row = [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'invoice_number' => 'INV-SLICE5-' . bin2hex(random_bytes(4)),
            'patient_id' => $patientId,
            'visit_id' => $visitId,
            'status' => $status,
            'subtotal' => $total,
            'discount' => '0.00',
            'tax' => '0.00',
            'total' => $total,
            'currency' => 'IRR',
            'paid_amount' => $paid,
            'balance' => $balance,
            'issued_by_wp_user_id' => $actorId,
            'void_reason' => null,
            'voided_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        foreach ($overrides as $key => $value) {
            $row[$key] = $value;
        }
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoices', $row), 'invoice fixture insert');

        return (int) $wpdb->insert_id;
    }

    private function displayName(int $patientId): string
    {
        $row = $this->patientRow($patientId);

        return trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
    }

    /**
     * The full persisted rows the receipt path reads for one Visit/invoice.
     * Comparing the returned values before/after a GET detects UPDATEs, which
     * a count-level snapshot by construction cannot.
     *
     * @return array<string, mixed>
     */
    private function persistedReceiptRows(int $visitId, int $invoiceId): array
    {
        $all = static fn (string $sql, array $params): array => (array) App::db()->fetchAll($sql, $params);
        $one = static fn (string $sql, array $params): ?array => App::db()->fetchRow($sql, $params);

        return [
            'visit' => $one('SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d LIMIT 1', [$visitId]),
            'invoice' => $one('SELECT * FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d LIMIT 1', [$invoiceId]),
            'items' => $all('SELECT * FROM ' . App::db()->table('cpms_invoice_items') . ' WHERE invoice_id = %d ORDER BY id ASC', [$invoiceId]),
            'payments' => $all('SELECT * FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d ORDER BY id ASC', [$invoiceId]),
            'adjustments' => $all('SELECT * FROM ' . App::db()->table('cpms_payment_adjustments') . ' WHERE invoice_id = %d ORDER BY id ASC', [$invoiceId]),
            'history' => $all('SELECT * FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d ORDER BY id ASC', [$visitId]),
            'patient' => $one('SELECT * FROM ' . App::db()->table('cpms_patients') . ' WHERE id = (SELECT patient_id FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d) LIMIT 1', [$visitId]),
            'clinic' => $one('SELECT * FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = (SELECT clinic_id FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d) LIMIT 1', [$visitId]),
        ];
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
