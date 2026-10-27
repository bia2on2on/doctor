<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Finance\VisitPricingService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\IranV1Fixture;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/IranV1Fixture.php';

/**
 * Iran V1 — doctor default/override consultation fees, draft treatment
 * charges, receptionist itemized billing, payment idempotency, settled-invoice
 * immutability and multi-doctor/multi-location isolation.
 *
 * All invoice/payment writes delegate to the EXISTING FinanceService; this
 * suite asserts the adapter never mutates a settled invoice or a payment.
 */
final class VisitPricingBillingTest extends WP_UnitTestCase
{
    use IranV1Fixture;

    private const BILLING = '/clinic/v1/staff/portal/finance/visits/%d/billing';
    private const AUTO = '/clinic/v1/staff/portal/finance/visits/%d/invoice/auto';
    private const PAY = '/clinic/v1/staff/portal/finance/invoices/%d/payments';

    protected function setUp(): void
    {
        parent::setUp();
        $this->iv1_boot();
    }

    protected function tearDown(): void
    {
        $this->iv1_reset();
        parent::tearDown();
    }

    /**
     * @return array<string, int>
     */
    private function world(): array
    {
        $org      = $this->iv1_org('pricing');
        $clinic   = $this->iv1_clinic($org);
        $location = $this->iv1_location($clinic);
        $doctor   = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'price_doc');
        $secretary = $this->iv1_user(RolesAndCapabilities::ROLE_SECRETARY, 'price_sec');
        $this->iv1_membership($doctor, $clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $this->iv1_membership($secretary, $clinic, RolesAndCapabilities::ROLE_SECRETARY);
        $clinician = $this->iv1_clinician($clinic, $doctor, 'پزشک تعرفه');

        return compact('org', 'clinic', 'location', 'doctor', 'secretary', 'clinician');
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing_default(array $world, int $amount): array
    {
        return $this->iv1_scope((int) $world['clinic'], fn (): array => App::visit_pricing_service()->set_default_fee((int) $world['doctor'], 0, $amount));
    }

    /**
     * @return array<string, mixed>
     */
    private function billing(array $world, int $visit_id, int $actor): array
    {
        return $this->iv1_scope((int) $world['clinic'], fn (): array => App::visit_pricing_service()->billing_for_visit($actor, $visit_id));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function add_charge(array $world, int $visit_id, array $payload): array
    {
        return $this->iv1_scope((int) $world['clinic'], fn (): array => App::visit_pricing_service()->add_charge((int) $world['doctor'], $visit_id, $payload));
    }

    private function assertFinanceDenied(callable $fn, string $code, int $status): void
    {
        try {
            $fn();
            self::fail('Expected FinanceException ' . $code);
        } catch (FinanceException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($status, $exception->httpStatus);
        }
    }

    public function testDefaultFeeAppliesToNewInvoiceAndPerVisitOverrideIsIndependent(): void
    {
        $world = $this->world();
        $patient1 = $this->iv1_patient((int) $world['clinic'], 'بیمار یک');
        $patient2 = $this->iv1_patient((int) $world['clinic'], 'بیمار دو');
        $visit1 = $this->iv1_visit((int) $world['clinic'], (int) $world['location'], $patient1, (int) $world['clinician']);
        $visit2 = $this->iv1_visit((int) $world['clinic'], (int) $world['location'], $patient2, (int) $world['clinician']);

        wp_set_current_user((int) $world['doctor']);
        $set = $this->pricing_default($world, 500000);
        self::assertSame(500000, (int) $set['default_fee']);
        self::assertSame('IRR', $set['currency']);
        self::assertSame('ریال', $set['currency_label']);

        $this->add_charge($world, $visit1, ['description' => 'پانسمان', 'amount' => 20000, 'quantity' => 2, 'note' => 'دو بار']);
        $this->add_charge($world, $visit1, ['description' => 'تزریق', 'amount' => 30000, 'quantity' => 1]);

        $bill1 = $this->billing($world, $visit1, (int) $world['secretary']);
        self::assertSame(500000, (int) $bill1['default_fee']);
        self::assertNull($bill1['override_fee']);
        self::assertSame(500000, (int) $bill1['effective_fee']);
        self::assertCount(2, $bill1['charges']);
        self::assertSame(70000, (int) $bill1['charges_total']);
        self::assertSame(570000, (int) $bill1['expected_total']);
        self::assertSame(VisitPricingService::CURRENCY_LABEL, $bill1['currency_label']);

        $override = $this->iv1_scope((int) $world['clinic'], fn (): array => App::visit_pricing_service()->set_visit_consultation_fee((int) $world['doctor'], $visit2, 750000));
        self::assertSame(750000, (int) $override['effective_fee']);
        $bill1_after = $this->billing($world, $visit1, (int) $world['secretary']);
        self::assertSame(500000, (int) $bill1_after['effective_fee'], 'another patient override never changes this visit');
        $bill2 = $this->billing($world, $visit2, (int) $world['secretary']);
        self::assertSame(750000, (int) $bill2['effective_fee']);
        self::assertSame(500000, (int) $bill2['default_fee']);

        wp_set_current_user((int) $world['secretary']);
        $headers = $this->iv1_headers((int) $world['clinic'], (int) $world['location']);
        $issued = $this->iv1_dispatch('POST', sprintf(self::AUTO, $visit1), [], $headers, []);
        self::assertSame(201, $issued->get_status(), 'auto issuance delegates to the existing service — ' . $this->iv1_code($issued));
        $invoice = $this->iv1_data($issued);
        self::assertSame(570000.0, (float) ($invoice['total'] ?? -1));
        self::assertSame('IRR', $invoice['currency'] ?? null);
        self::assertCount(3, $invoice['items'] ?? []);
        self::assertSame(VisitPricingService::CONSULTATION_DESCRIPTION, $invoice['items'][0]['description'] ?? null);

        // A later default-fee change must never rewrite an existing invoice.
        wp_set_current_user((int) $world['doctor']);
        $this->pricing_default($world, 900000);
        $row = App::db()->fetchRow('SELECT total, subtotal, balance, paid_amount, status FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [(int) $invoice['id']]);
        self::assertSame(570000.0, (float) $row['total']);
        self::assertSame(0.0, (float) $row['paid_amount']);
        self::assertSame('open', (string) $row['status']);
        $stamped = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visit_charge_items')
            . ' WHERE visit_id = %d AND invoiced_invoice_id = %d AND removed_at IS NULL',
            [$visit1, (int) $invoice['id']]
        );
        self::assertSame(2, $stamped);
    }

    public function testDraftChargesCanBeCorrectedOrRemovedWithAuditableHistory(): void
    {
        $world = $this->world();
        $patient = $this->iv1_patient((int) $world['clinic'], 'بیمار خدمات');
        $visit = $this->iv1_visit((int) $world['clinic'], (int) $world['location'], $patient, (int) $world['clinician']);
        wp_set_current_user((int) $world['doctor']);
        $this->pricing_default($world, 300000);

        $added = $this->add_charge($world, $visit, ['description' => 'آزمایش', 'amount' => 10000, 'quantity' => 1]);
        $charge_id = (int) $added['charge_id'];
        self::assertSame(310000, (int) $added['expected_total']);

        $updated = $this->iv1_scope((int) $world['clinic'], fn (): array => App::visit_pricing_service()->update_charge(
            (int) $world['doctor'], $visit, $charge_id, ['description' => 'آزمایش کامل', 'amount' => 25000, 'quantity' => 2]
        ));
        self::assertSame(350000, (int) $updated['expected_total']);
        self::assertSame('آزمایش کامل', $updated['charges'][0]['description']);

        $removed = $this->iv1_scope((int) $world['clinic'], fn (): array => App::visit_pricing_service()->remove_charge((int) $world['doctor'], $visit, $charge_id));
        self::assertSame([], $removed['charges']);
        self::assertSame(300000, (int) $removed['expected_total']);

        $history = App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
            . " WHERE resource_type = 'visit_charge_item' AND action IN ('INVOICE_ITEM_CREATE','INVOICE_ITEM_UPDATE','INVOICE_ITEM_REMOVE')"
        );
        self::assertSame(3, (int) $history);
        $removed_rows = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visit_charge_items') . ' WHERE id = %d AND removed_at IS NOT NULL',
            [$charge_id]
        );
        self::assertSame(1, $removed_rows, 'soft removal keeps the auditable row');
    }

    public function testSettledInvoiceAndPaymentAreNeverSilentlyMutated(): void
    {
        $world = $this->world();
        $patient = $this->iv1_patient((int) $world['clinic'], 'بیمار تسویه');
        $visit = $this->iv1_visit((int) $world['clinic'], (int) $world['location'], $patient, (int) $world['clinician']);
        wp_set_current_user((int) $world['doctor']);
        $this->pricing_default($world, 400000);
        $this->add_charge($world, $visit, ['description' => 'خدمت', 'amount' => 50000, 'quantity' => 1]);

        wp_set_current_user((int) $world['secretary']);
        $headers = $this->iv1_headers((int) $world['clinic'], (int) $world['location']);
        $issued = $this->iv1_dispatch('POST', sprintf(self::AUTO, $visit), [], $headers, []);
        self::assertSame(201, $issued->get_status(), 'issuance — ' . $this->iv1_code($issued));
        $invoice_id = (int) $this->iv1_data($issued)['id'];

        $pay_headers = $headers + ['Idempotency-Key' => $this->iv1_uuid()];
        $paid = $this->iv1_dispatch('POST', sprintf(self::PAY, $invoice_id), [], $pay_headers, ['amount' => 450000, 'method' => 'cash']);
        self::assertSame(201, $paid->get_status(), 'full capture — ' . $this->iv1_code($paid));

        $invoice_before = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoice_id]);
        $payments_before = App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d', [$invoice_id]);

        wp_set_current_user((int) $world['doctor']);
        $service = App::visit_pricing_service();
        $this->assertFinanceDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => $service->add_charge((int) $world['doctor'], $visit, ['description' => 'غیرمجاز', 'amount' => 1000])),
            'CLINIC_INVOICE_NOT_MODIFIABLE',
            409
        );
        $this->assertFinanceDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => $service->set_visit_consultation_fee((int) $world['doctor'], $visit, 999999)),
            'CLINIC_INVOICE_NOT_MODIFIABLE',
            409
        );
        $this->assertFinanceDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => $service->remove_charge((int) $world['doctor'], $visit, 1)),
            'CLINIC_INVOICE_NOT_MODIFIABLE',
            409
        );
        $this->assertFinanceDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => App::financeService()->issue_invoice_from_pricing((int) $world['secretary'], $visit)),
            'CLINIC_POLICY_VIOLATION',
            409
        );

        $invoice_after = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoice_id]);
        $payments_after = App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d', [$invoice_id]);
        self::assertSame($invoice_before, $invoice_after, 'paid invoice is byte-identical');
        self::assertSame($payments_before, $payments_after, 'recorded payments are byte-identical');
    }

    public function testReceptionistSeesItemizedChargesAndDuplicatePaymentIsPrevented(): void
    {
        $world = $this->world();
        $patient = $this->iv1_patient((int) $world['clinic'], 'بیمار پذیرش');
        $visit = $this->iv1_visit((int) $world['clinic'], (int) $world['location'], $patient, (int) $world['clinician']);
        wp_set_current_user((int) $world['doctor']);
        $this->pricing_default($world, 250000);
        $this->add_charge($world, $visit, ['description' => 'پانسمان', 'amount' => 15000, 'quantity' => 2]);

        wp_set_current_user((int) $world['secretary']);
        $headers = $this->iv1_headers((int) $world['clinic'], (int) $world['location']);
        $issued = $this->iv1_dispatch('POST', sprintf(self::AUTO, $visit), [], $headers, []);
        self::assertSame(201, $issued->get_status(), 'issuance — ' . $this->iv1_code($issued));
        $invoice_id = (int) $this->iv1_data($issued)['id'];

        $read = $this->iv1_dispatch('GET', sprintf(self::BILLING, $visit), [], $headers);
        self::assertSame(200, $read->get_status(), 'itemized billing read — ' . $this->iv1_code($read));
        $bill = $this->iv1_data($read);
        self::assertSame(250000, (int) $bill['effective_fee']);
        self::assertSame(30000, (int) $bill['charges_total']);
        self::assertSame(280000, (int) $bill['expected_total']);
        self::assertSame(0, (int) $bill['paid_amount']);
        self::assertSame(280000, (int) $bill['remaining_balance']);
        self::assertSame('پرداخت نشده', $bill['payment_status_label']);
        self::assertCount(2, $bill['invoice']['items']);
        self::assertSame('ریال', $bill['currency_label']);

        $key = $this->iv1_uuid();
        $pay_headers = $headers + ['Idempotency-Key' => $key];
        $first = $this->iv1_dispatch('POST', sprintf(self::PAY, $invoice_id), [], $pay_headers, ['amount' => 100000, 'method' => 'cash']);
        self::assertSame(201, $first->get_status(), 'first capture — ' . $this->iv1_code($first));
        $replay = $this->iv1_dispatch('POST', sprintf(self::PAY, $invoice_id), [], $pay_headers, ['amount' => 100000, 'method' => 'cash']);
        self::assertSame(200, $replay->get_status(), 'replay — ' . $this->iv1_code($replay));
        self::assertTrue((bool) ($this->iv1_data($replay)['idempotent_replay'] ?? false));
        self::assertSame(1, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d', [$invoice_id]));

        $after = $this->iv1_dispatch('GET', sprintf(self::BILLING, $visit), [], $headers);
        $bill_after = $this->iv1_data($after);
        self::assertSame(100000, (int) $bill_after['paid_amount']);
        self::assertSame(180000, (int) $bill_after['remaining_balance']);
        self::assertSame('پرداخت جزئی', $bill_after['payment_status_label']);

        // A doctor has no cpms_payment_create in the built-in preset: the
        // existing capture and void routes stay closed to the doctor.
        wp_set_current_user((int) $world['doctor']);
        $doctor_headers = $headers + ['Idempotency-Key' => $this->iv1_uuid()];
        $doctor_capture = $this->iv1_dispatch('POST', sprintf(self::PAY, $invoice_id), [], $doctor_headers, ['amount' => 1000, 'method' => 'cash']);
        self::assertSame(403, $doctor_capture->get_status(), 'doctor cannot capture a payment');
        // Void/refund keep the EXISTING ownership-aware rules: a doctor may
        // only ever act on a payment of their own service, never arbitrarily.
        $doctor_void = $this->iv1_dispatch('POST', '/clinic/v1/payments/1/void', [], $this->iv1_headers((int) $world['clinic']), ['reason' => 'test']);
        self::assertGreaterThanOrEqual(400, $doctor_void->get_status(), 'no unrestricted doctor void authority');
        self::assertSame(1, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d', [$invoice_id]));
    }

    public function testMultiDoctorAndMultiLocationPricingIsolation(): void
    {
        $world = $this->world();
        $location2 = $this->iv1_location((int) $world['clinic'], 'محل دوم');
        $doctor2 = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'second_doc');
        $this->iv1_membership($doctor2, (int) $world['clinic'], RolesAndCapabilities::ROLE_DOCTOR);
        $clinician2 = $this->iv1_clinician((int) $world['clinic'], $doctor2, 'پزشک دوم');

        wp_set_current_user((int) $world['doctor']);
        $this->pricing_default($world, 400000);
        wp_set_current_user($doctor2);
        $this->iv1_scope((int) $world['clinic'], fn (): array => App::visit_pricing_service()->set_default_fee($doctor2, $location2, 600000));

        $patient1 = $this->iv1_patient((int) $world['clinic'], 'بیمار پزشک یک');
        $patient2 = $this->iv1_patient((int) $world['clinic'], 'بیمار پزشک دو');
        $visit1 = $this->iv1_visit((int) $world['clinic'], (int) $world['location'], $patient1, (int) $world['clinician']);
        $visit2 = $this->iv1_visit((int) $world['clinic'], $location2, $patient2, $clinician2);

        // Doctor 1 set a clinic-wide default; doctor 2 set only a
        // location-specific default. Neither doctor's fee bleeds into the
        // other's visits.
        $bill1 = $this->billing($world, $visit1, (int) $world['secretary']);
        self::assertSame(400000, (int) $bill1['effective_fee']);
        // Doctor 2's location-specific default applies to a Location-2 visit,
        // and a Location-1 visit of doctor 2 has no configured fee.
        $bill2 = $this->billing($world, $visit2, (int) $world['secretary']);
        self::assertSame(600000, (int) $bill2['effective_fee']);
        $visit2_other_location = $this->iv1_visit((int) $world['clinic'], (int) $world['location'], $this->iv1_patient((int) $world['clinic'], 'بیمار سوم'), $clinician2);
        $bill3 = $this->billing($world, $visit2_other_location, (int) $world['secretary']);
        self::assertNull($bill3['effective_fee']);

        // Doctor 2 cannot override doctor 1's visit fee.
        $this->assertFinanceDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => App::visit_pricing_service()->set_visit_consultation_fee($doctor2, $visit1, 1)),
            'CLINIC_PERMISSION_DENIED',
            403
        );

        // A location-scoped receptionist cannot read Location-1 billing from
        // the finance portal while only authorized for Location 2.
        $membership = App::membership_service()->membership_for((int) $world['clinic'], (int) $world['secretary']);
        self::assertNotNull($membership);
        $this->iv1_restrict_to_location((int) $membership['id'], $location2);
        wp_set_current_user((int) $world['secretary']);
        $denied = $this->iv1_dispatch('GET', sprintf(self::BILLING, $visit1), [], $this->iv1_headers((int) $world['clinic'], (int) $world['location']));
        self::assertGreaterThanOrEqual(400, $denied->get_status());
        self::assertArrayNotHasKey('effective_fee', $this->iv1_data($denied));

        // Cross-Clinic pricing write is refused (404 parity for foreign Visit).
        $foreign_org = $this->iv1_org('foreign');
        $foreign_clinic = $this->iv1_clinic($foreign_org);
        $foreign_doctor = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'foreign_doc');
        $this->iv1_membership($foreign_doctor, $foreign_clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $this->iv1_clinician($foreign_clinic, $foreign_doctor, 'پزشک خارجی');
        wp_set_current_user($foreign_doctor);
        $this->assertFinanceDenied(
            fn () => $this->iv1_scope($foreign_clinic, fn (): array => App::visit_pricing_service()->set_visit_consultation_fee($foreign_doctor, $visit1, 1)),
            'CLINIC_NOT_FOUND',
            404
        );
    }

    public function testAutoIssuanceRequiresAConfiguredFeeAndNeverTouchesAnExistingInvoice(): void
    {
        $world = $this->world();
        $patient = $this->iv1_patient((int) $world['clinic'], 'بیمار بدون تعرفه');
        $visit = $this->iv1_visit((int) $world['clinic'], (int) $world['location'], $patient, (int) $world['clinician']);
        wp_set_current_user((int) $world['secretary']);
        $headers = $this->iv1_headers((int) $world['clinic'], (int) $world['location']);

        $unset = $this->iv1_dispatch('POST', sprintf(self::AUTO, $visit), [], $headers, []);
        self::assertSame(422, $unset->get_status());
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->iv1_code($unset));

        // Legacy invoice created before any pricing configuration.
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoices', [
            'clinic_id' => (int) $world['clinic'], 'location_id' => (int) $world['location'],
            'invoice_number' => 'INV-LEGACY-' . bin2hex(random_bytes(4)), 'patient_id' => $patient, 'visit_id' => $visit,
            'status' => 'paid', 'subtotal' => '123456.00', 'total' => '123456.00', 'currency' => 'IRR',
            'paid_amount' => '123456.00', 'balance' => '0.00', 'issued_by_wp_user_id' => (int) $world['secretary'],
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $legacy_id = (int) $wpdb->insert_id;
        $before = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$legacy_id]);

        wp_set_current_user((int) $world['doctor']);
        $this->pricing_default($world, 900000);
        wp_set_current_user((int) $world['secretary']);
        $repeat_migrate = App::migrations()->migrate();
        self::assertSame([], $repeat_migrate, 'migration 0030 is idempotent');
        $again = $this->iv1_dispatch('POST', sprintf(self::AUTO, $visit), [], $headers, []);
        self::assertContains($again->get_status(), [409, 422]);
        $after = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$legacy_id]);
        self::assertSame($before, $after, 'existing finalized invoice survives pricing + migration unchanged');
    }
}
