<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * تست‌های مالی F6 — D12–D18 + P3 + G2 + Invariants (M-1..M-7) + TP-02/TP-18.
 *
 * پوشش:
 *  - D12 صدور فاکتور: V11 سیستمی + Totals (InvoiceCalc) + Audit INVOICE_CREATE + یکتایی (I1/I4)
 *  - D13 پرداخت: Partial→Full (I2/I3) + V12 + PAY Number + Idempotency (M-1/TP-02)
 *  - M-3 بیش‌پرداخت؛ D14 ابطال (همان‌روز/روز بعد)؛ P3 بازپرداخت (جزئی/کامل/بیش از حد)
 *  - D15 اصلاح credit/debit + M-6 (فکتور paid) + V12 با credit کامل
 *  - V14/NOT_SETTLED گارد Checkout + مسیر معافیت (waive)
 *  - D17 رسید Deterministic (M-5) + جلالی؛ D18 خلاصه
 *  - G2 تعرفه‌ها (فقط cpms_config) + ماتریس مجوزها (P-3: admin فقط فنی)
 */
final class FinanceFlowTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $clinicianId;
    private int $patientId;
    private int $secretaryUserId;
    private int $doctorUserId;
    private int $adminUserId;
    private int $patientUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('clinical.require_chief_complaint', true);

        global $wpdb;
        $now = App::db()->nowUtcSql();

        $this->secretaryUserId = $this->makeUser('ff_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('ff_doctor', 'cpms_doctor');
        $this->adminUserId = $this->makeUser('ff_admin', 'administrator');
        $this->patientUserId = $this->makeUser('ff_patient', 'cpms_patient');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Finance',
                $this->doctorUserId,
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-FF-0001',
                'Finance',
                'Patient',
                '09125551001',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;
    }

    // ================= D12 — صدور فاکتور =================

    public function testIssueInvoiceMovesVisitAndAudits(): void
    {
        $visitId = $this->makeCompletedVisit();
        $svc = $this->makeService('VISIT', 'ویزیت', 500000);

        $invoice = $this->finance()->issueInvoice($this->secretaryUserId, [
            'visit_id' => $visitId,
            'items' => [
                ['service_id' => $svc, 'quantity' => 1],
                ['description' => 'دستکاری', 'quantity' => 2, 'unit_price' => 150000, 'discount' => 20000],
            ],
            'discount' => 50000,
            'tax' => 10000,
        ]);

        // Totals (InvoiceCalc): اقلام 500k + (2×150k) = 800k gross؛ تخفیف قلم 20k
        // → subtotal 780k؛ تخفیف فاکتور 50k → base 730k؛ مالیات 10k → total 740k
        $this->assertSame(780000.0, $invoice['subtotal']);
        $this->assertSame(70000.0, $invoice['discount']);
        $this->assertSame(10000.0, $invoice['tax']);
        $this->assertSame(740000.0, $invoice['total']);
        $this->assertSame(740000.0, $invoice['balance']);
        $this->assertSame('open', $invoice['status']);
        $this->assertMatchesRegularExpression('/^INV-\d{6}-\d{3,}$/', $invoice['invoice_number']);
        $this->assertCount(2, $invoice['items']);

        // V11 سیستمی — فاکتور، ویزیت را به awaiting_payment برد
        $visit = App::db()->fetchRow(
            'SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visitId]
        );
        $this->assertSame('awaiting_payment', (string) $visit['status']);

        // Audit (M-4) — INVOICE_CREATE با مبالغ
        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
            ['INVOICE_CREATE', 'invoice', (int) $invoice['id']]
        );
        $this->assertNotNull($audit, 'INVOICE_CREATE audit row exists');
        $after = json_decode((string) $audit['after_json'], true);
        $this->assertSame(740000, $after['invoice.total']);
        $this->assertSame(740000, $after['invoice.balance']);
        $this->assertSame($visitId, $after['visit_id']);

        // I1/I4 — تکرار صدور → Policy Violation
        try {
            $this->finance()->issueInvoice($this->secretaryUserId, ['visit_id' => $visitId, 'items' => [['description' => 'x', 'unit_price' => 1]]]);
            $this->fail('Expected CLINIC_POLICY_VIOLATION for duplicate invoice');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_POLICY_VIOLATION', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
        }
    }

    public function testIssueInvoiceRejectsInvalidInputs(): void
    {
        $visitId = $this->makeCompletedVisit();

        // وضعیت ویزیت نادرست (in_consultation)
        $inProgress = $this->makeConsultation($this->patientId);
        try {
            $this->finance()->issueInvoice($this->doctorUserId, ['visit_id' => $inProgress, 'items' => [['description' => 'x', 'unit_price' => 1]]]);
            $this->fail('Expected CLINIC_INVALID_TRANSITION');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_INVALID_TRANSITION', $e->errorCode);
        }

        // بدون قلم → 422
        try {
            $this->finance()->issueInvoice($this->secretaryUserId, ['visit_id' => $visitId, 'items' => []]);
            $this->fail('Expected CLINIC_VALIDATION_FAILED (empty items)');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }

        // مبلغ کسری → 422 (TP-18 — مبالغ ریال صحیح)
        try {
            $this->finance()->issueInvoice($this->secretaryUserId, ['visit_id' => $visitId, 'items' => [['description' => 'x', 'unit_price' => 100.5]]]);
            $this->fail('Expected CLINIC_VALIDATION_FAILED (fractional rial)');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }
    }

    // ================= D13 — پرداخت + Idempotency =================

    public function testPartialThenFullPaymentSettlesVisit(): void
    {
        $visitId = $this->makeCompletedVisit();
        $invoice = $this->finance()->issueInvoice($this->secretaryUserId, [
            'visit_id' => $visitId,
            'items' => [['description' => 'ویزیت', 'unit_price' => 500000]],
        ]);

        $p1 = $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], [
            'amount' => 200000, 'method' => 'cash',
        ], $this->uuid());
        $this->assertMatchesRegularExpression('/^PAY-\d{6}-\d{4,}$/', $p1['payment_number']);
        $this->assertSame('partial', $p1['invoice']['status']);
        $this->assertSame(300000.0, $p1['invoice']['balance']);

        $p2 = $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], [
            'amount' => 300000, 'method' => 'card_pos', 'transaction_ref' => 'POS-991',
        ], $this->uuid());

        // I3 + V12 — فاکتور paid و ویزیت paid (نقش system) در همان Transaction
        $this->assertSame('paid', $p2['invoice']['status']);
        $this->assertSame(0.0, $p2['invoice']['balance']);
        $visit = App::db()->fetchRow('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitId]);
        $this->assertSame('paid', (string) $visit['status']);

        // Audit PAYMENT_CAPTURE
        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s ORDER BY id DESC',
            ['PAYMENT_CAPTURE', 'payment']
        );
        $this->assertNotNull($audit);

        // M-3 — بیش‌پرداخت روی فاکتور تسویه‌شده
        try {
            $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 1, 'method' => 'cash'], $this->uuid());
            $this->fail('Expected CLINIC_OVERPAYMENT or NOT_MODIFIABLE');
        } catch (FinanceException $e) {
            $this->assertContains($e->errorCode, ['CLINIC_OVERPAYMENT', 'CLINIC_INVOICE_NOT_MODIFIABLE']);
        }
    }

    public function testOverpaymentRejectedWithBalance(): void
    {
        $invoice = $this->makeInvoice(500000);

        try {
            $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 500001, 'method' => 'cash'], $this->uuid());
            $this->fail('Expected CLINIC_OVERPAYMENT');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_OVERPAYMENT', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame(500000, $e->data['balance']);
        }
    }

    public function testPaymentIdempotencyReplayReturnsSamePayment(): void
    {
        $invoice = $this->makeInvoice(500000);
        $key = $this->uuid();

        $first = $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 500000, 'method' => 'cash'], $key);
        $this->assertArrayNotHasKey('idempotent_replay', $first);

        // TP-02 — تکرار همان کلید = همان پرداخت، نه تراکنش دوم
        $replay = $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 500000, 'method' => 'cash'], $key);
        $this->assertTrue($replay['idempotent_replay']);
        $this->assertSame('CLINIC_IDEMPOTENCY_REPLAY', $replay['code']);
        $this->assertSame($first['payment_id'], $replay['payment_id']);

        // M-1 — فقط یک ردیف پرداخت برای این فاکتور
        $count = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d',
            [(int) $invoice['id']]
        );
        $this->assertSame(1, $count);
    }

    public function testRestPaymentWithoutIdempotencyKeyIsRejected(): void
    {
        $invoice = $this->makeInvoice(500000);

        wp_set_current_user($this->secretaryUserId);
        $response = $this->dispatch('POST', self::NS . '/invoices/' . $invoice['id'] . '/payments', [
            'amount' => 100000, 'method' => 'cash',
        ]);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('CLINIC_VALIDATION_FAILED', $response->get_data()['code'] ?? null);
    }

    public function testRestPaymentFirst201Replay200(): void
    {
        $invoice = $this->makeInvoice(500000);
        $key = $this->uuid();

        wp_set_current_user($this->secretaryUserId);
        $first = $this->dispatch('POST', self::NS . '/invoices/' . $invoice['id'] . '/payments', [
            'amount' => 100000, 'method' => 'cash',
        ], ['Idempotency-Key' => $key]);
        $this->assertSame(201, $first->get_status());
        $this->assertMatchesRegularExpression('/^PAY-\d{6}-\d{4,}$/', $first->get_data()['data']['payment_number'] ?? '');

        $replay = $this->dispatch('POST', self::NS . '/invoices/' . $invoice['id'] . '/payments', [
            'amount' => 100000, 'method' => 'cash',
        ], ['Idempotency-Key' => $key]);
        $this->assertSame(200, $replay->get_status());
        $this->assertSame('CLINIC_IDEMPOTENCY_REPLAY', $replay->get_data()['data']['code'] ?? null);
        $this->assertSame(
            $first->get_data()['data']['payment_id'],
            $replay->get_data()['data']['payment_id']
        );
    }

    // ================= D14 — ابطال پرداخت =================

    public function testVoidSameDayRestoresInvoiceButNotVisit(): void
    {
        $visitId = $this->makeCompletedVisit();
        $invoice = $this->makeInvoice(500000, $visitId);
        $pay = $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 500000, 'method' => 'cash'], $this->uuid());
        $this->assertSame('paid', $pay['invoice']['status']);

        $voided = $this->finance()->voidPayment($this->secretaryUserId, (int) $pay['payment_id'], 'ثبت اشتباه مبلغ');
        $this->assertSame('voided', $voided['payment']['status']);
        $this->assertSame('open', $voided['invoice']['status']);
        $this->assertSame(500000.0, $voided['invoice']['balance']);
        $this->assertSame(0.0, $voided['invoice']['paid_amount']);

        // Deviation مستند: V12 یک‌طرفه است — ویزیت paid ماند
        $visit = App::db()->fetchRow('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitId]);
        $this->assertSame('paid', (string) $visit['status']);

        // Audit PAYMENT_VOID
        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s ORDER BY id DESC',
            ['PAYMENT_VOID', 'payment']
        );
        $this->assertNotNull($audit);

        // V14 — خروج با فاکتور باز → NOT_SETTLED
        try {
            App::visitService()->checkout($this->secretaryUserId, $visitId, null);
            $this->fail('Expected CLINIC_NOT_SETTLED');
        } catch (\ClinicCore\Domain\Visits\VisitException $e) {
            $this->assertSame('CLINIC_NOT_SETTLED', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
            $this->assertSame(1, $e->data['open_invoices']);
        }

        // تسویه مجدد → خروج OK
        $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 500000, 'method' => 'cash'], $this->uuid());
        $out = App::visitService()->checkout($this->secretaryUserId, $visitId, null);
        $this->assertSame('checked_out', $out['status']);
    }

    public function testVoidNextDayIsRejected(): void
    {
        $invoice = $this->makeInvoice(500000);
        $pay = $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 100000, 'method' => 'cash'], $this->uuid());

        // شبیه‌سازی ثبت دیروز (UTC)
        App::db()->query(
            'UPDATE ' . App::db()->table('cpms_payments') . ' SET paid_at = %s WHERE id = %d',
            [gmdate('Y-m-d H:i:s', time() - 86400) . '.000', (int) $pay['payment_id']]
        );

        try {
            $this->finance()->voidPayment($this->secretaryUserId, (int) $pay['payment_id'], 'دیر شد');
            $this->fail('Expected CLINIC_VOID_WINDOW_EXPIRED');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_VOID_WINDOW_EXPIRED', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
        }
    }

    public function testVoidRequiresReason(): void
    {
        $invoice = $this->makeInvoice(500000);
        $pay = $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 100000, 'method' => 'cash'], $this->uuid());

        try {
            $this->finance()->voidPayment($this->secretaryUserId, (int) $pay['payment_id'], '  ');
            $this->fail('Expected CLINIC_VALIDATION_FAILED (reason required)');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }
    }

    // ================= P3 — بازپرداخت =================

    public function testRefundPartialThenFull(): void
    {
        $invoice = $this->makeInvoice(500000);
        $pay = $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 500000, 'method' => 'online'], $this->uuid());

        // جزئی — تراکنش captured می‌ماند
        $r1 = $this->finance()->refundPayment($this->secretaryUserId, (int) $pay['payment_id'], 'بازگشت بخشی', ['amount' => 150000]);
        $this->assertSame('captured', $r1['payment']['status']);
        $this->assertSame(150000.0, $r1['payment']['refunded_amount']);
        $this->assertSame('partial', $r1['invoice']['status']);
        $this->assertSame(150000.0, $r1['invoice']['balance']);

        // بیش از سقف باقیمانده → 422
        try {
            $this->finance()->refundPayment($this->secretaryUserId, (int) $pay['payment_id'], 'x', ['amount' => 350001]);
            $this->fail('Expected CLINIC_VALIDATION_FAILED (over-refund)');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame(350000, $e->data['max_refundable']);
        }

        // کامل (پیش‌فرض بدون amount) — تراکنش refunded + بازگردانی فاکتور
        $r2 = $this->finance()->refundPayment($this->secretaryUserId, (int) $pay['payment_id'], 'لغو کل');
        $this->assertSame('refunded', $r2['payment']['status']);
        $this->assertSame(500000.0, $r2['payment']['refunded_amount']);
        $this->assertSame('open', $r2['invoice']['status']);
        $this->assertSame(500000.0, $r2['invoice']['balance']);
    }

    // ================= D15 — اصلاح =================

    public function testAdjustmentCreditAndDebit(): void
    {
        $invoice = $this->makeInvoice(500000);

        // credit 100k → balance 400k
        $c = $this->finance()->addAdjustment($this->secretaryUserId, (int) $invoice['id'], 'credit', ['amount' => 100000, 'reason' => 'تخفیف توافقی']);
        $this->assertSame(400000.0, $c['invoice']['balance']);
        $this->assertSame('open', $c['invoice']['status']);

        // debit 50k → balance 450k
        $d = $this->finance()->addAdjustment($this->secretaryUserId, (int) $invoice['id'], 'debit', ['amount' => 50000, 'reason' => 'خدمت اضافه']);
        $this->assertSame(450000.0, $d['invoice']['balance']);

        // پرداخت با «کلید تسویه مؤثر» (total−credit+debit = 450k)
        $pay = $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 450000, 'method' => 'cash'], $this->uuid());
        $this->assertSame('paid', $pay['invoice']['status']);

        // M-6 — اصلاح روی فاکتور paid → NOT_MODIFIABLE
        try {
            $this->finance()->addAdjustment($this->secretaryUserId, (int) $invoice['id'], 'debit', ['amount' => 1000, 'reason' => 'دیر']);
            $this->fail('Expected CLINIC_INVOICE_NOT_MODIFIABLE');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_INVOICE_NOT_MODIFIABLE', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
        }
    }

    public function testAdjustmentCreditSettlesVisitAndValidates(): void
    {
        $visitId = $this->makeCompletedVisit();
        $invoice = $this->makeInvoice(300000, $visitId);

        // بیش از بدهی → 422
        try {
            $this->finance()->addAdjustment($this->secretaryUserId, (int) $invoice['id'], 'credit', ['amount' => 300001, 'reason' => 'خیلیم زیاد']);
            $this->fail('Expected CLINIC_VALIDATION_FAILED (credit > balance)');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }

        // دلیل الزامی
        try {
            $this->finance()->addAdjustment($this->secretaryUserId, (int) $invoice['id'], 'credit', ['amount' => 1000, 'reason' => '']);
            $this->fail('Expected CLINIC_VALIDATION_FAILED (reason required)');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }

        // credit کامل → فاکتور paid + V12
        $c = $this->finance()->addAdjustment($this->secretaryUserId, (int) $invoice['id'], 'credit', ['amount' => 300000, 'reason' => 'معافیت پزشک']);
        $this->assertSame('paid', $c['invoice']['status']);
        $this->assertSame(0.0, $c['invoice']['balance']);
        $visit = App::db()->fetchRow('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitId]);
        $this->assertSame('paid', (string) $visit['status']);

        // Audit PAYMENT_ADJUST
        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s ORDER BY id DESC',
            ['PAYMENT_ADJUST', 'invoice']
        );
        $this->assertNotNull($audit);
    }

    // ================= D17 — رسید (M-5) =================

    public function testReceiptIsDeterministicAndJalali(): void
    {
        $visitId = $this->makeCompletedVisit();
        $invoice = $this->makeInvoice(500000, $visitId);
        $pay = $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 500000, 'method' => 'cash'], $this->uuid());
        $this->finance()->voidPayment($this->secretaryUserId, (int) $pay['payment_id'], 'اشتباه');
        $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 500000, 'method' => 'card_pos'], $this->uuid());

        $r1 = $this->finance()->receipt($this->secretaryUserId, (int) $invoice['id']);
        $r2 = $this->finance()->receipt($this->secretaryUserId, (int) $invoice['id']);

        // M-5 — تولید مجدد = همان محتوا
        $this->assertSame($r1, $r2);

        $rc = $r1['receipt'];
        $this->assertSame($invoice['invoice_number'], $rc['invoice_number']);
        $this->assertMatchesRegularExpression('#^14\d{2}/\d{2}/\d{2}$#', $rc['jalali_date']);
        $this->assertNotSame('', $rc['clinic']['name'], 'clinic name resolved');
        $this->assertStringStartsWith('Finance ', $rc['patient']['name']);
        $this->assertMatchesRegularExpression('/^MR-FF-\d+$/', $rc['patient']['mrn']);

        // فقط پرداخت‌های captured در رسید (اکشن voided حذف)
        $this->assertCount(1, $rc['payments']);
        $this->assertSame('card_pos', $rc['payments'][0]['method']);
        $this->assertSame(500000.0, $rc['totals']['paid_amount']);
    }

    // ================= D18 — خلاصه =================

    public function testSummaryRevenueAndOpenBalances(): void
    {
        $visitId = $this->makeCompletedVisit();
        $invoice = $this->makeInvoice(500000, $visitId);
        $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 300000, 'method' => 'cash'], $this->uuid());
        $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 100000, 'method' => 'card_pos'], $this->uuid());

        $today = gmdate('Y-m-d');
        $s = $this->finance()->summary($this->secretaryUserId, $today, $today);

        $this->assertSame(400000, $s['revenue']['total']);
        $this->assertSame(300000, $s['revenue']['by_method']['cash']);
        $this->assertSame(100000, $s['revenue']['by_method']['card_pos']);
        $this->assertSame(2, $s['revenue']['payment_count']);
        $this->assertSame(100000, $s['open_balances']['total']);
        $this->assertSame(1, $s['open_balances']['invoice_count']);
        $this->assertSame('partial', $s['open_balances']['invoices'][0]['status']);

        // تاریخ نامعتبر → 422
        try {
            $this->finance()->summary($this->secretaryUserId, '2026-13-45', $today);
            $this->fail('Expected CLINIC_VALIDATION_FAILED (bad date)');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }
    }

    // ================= G2 — تعرفه‌ها =================

    public function testServicesCrudAdminOnly(): void
    {
        // Secretary — خواندن OK (برای فاکتورسازی) ولی CRUD فنی → 403
        $list = $this->finance()->listServices($this->secretaryUserId, false);
        $this->assertSame([], $list);
        try {
            $this->finance()->createService($this->secretaryUserId, ['code' => 'X', 'name' => 'x', 'price' => 1]);
            $this->fail('Expected CLINIC_PERMISSION_DENIED (secretary config)');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
        }

        // Admin — CRUD کامل
        $svc = $this->finance()->createService($this->adminUserId, ['code' => 'VISIT', 'name' => 'ویزیت تخصصی', 'price' => 500000]);
        $this->assertGreaterThan(0, $svc['id']);

        try {
            $this->finance()->createService($this->adminUserId, ['code' => 'VISIT', 'name' => 'تکراری', 'price' => 1]);
            $this->fail('Expected CLINIC_POLICY_VIOLATION (duplicate code)');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_POLICY_VIOLATION', $e->errorCode);
        }

        $upd = $this->finance()->updateService($this->adminUserId, (int) $svc['id'], ['price' => 550000]);
        $this->assertSame(550000.0, $upd['price']);

        $off = $this->finance()->deactivateService($this->adminUserId, (int) $svc['id']);
        $this->assertFalse($off['is_active']);

        // حذف منطقی: ردیف می‌ماند + فیلتر active
        $active = $this->finance()->listServices($this->adminUserId, true);
        $this->assertSame([], $active);
        $all = $this->finance()->listServices($this->adminUserId, false);
        $this->assertCount(1, $all);
    }

    // ================= ماتریس مجوزها =================

    public function testPermissionMatrixOnFinanceOperations(): void
    {
        $visitId = $this->makeCompletedVisit();
        $invoice = $this->makeInvoice(500000, $visitId);
        $pay = $this->finance()->recordPayment($this->secretaryUserId, (int) $invoice['id'], ['amount' => 100000, 'method' => 'cash'], $this->uuid());

        // بیمار — همه عمل‌های مالی → 403
        foreach ([
            ['issueInvoice', [$this->patientUserId, ['visit_id' => $visitId, 'items' => [['description' => 'x', 'unit_price' => 1]]]]],
            ['recordPayment', [$this->patientUserId, (int) $invoice['id'], ['amount' => 1, 'method' => 'cash'], $this->uuid()]],
            ['voidPayment', [$this->patientUserId, (int) $pay['payment_id'], 'دلیل']],
            ['refundPayment', [$this->patientUserId, (int) $pay['payment_id'], 'دلیل']],
            ['addAdjustment', [$this->patientUserId, (int) $invoice['id'], 'credit', ['amount' => 1, 'reason' => 'r']]],
            ['summary', [$this->patientUserId]],
            ['receipt', [$this->patientUserId, (int) $invoice['id']]],
        ] as [$method, $args]) {
            try {
                $this->finance()->{$method}(...$args);
                $this->fail("Expected 403 for patient on {$method}");
            } catch (FinanceException $e) {
                $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode, "{$method} for patient");
            }
        }

        // پزشک — payment_create و invoice_adjust ندارد؛ issue/void/refund/summary دارد
        try {
            $this->finance()->recordPayment($this->doctorUserId, (int) $invoice['id'], ['amount' => 1, 'method' => 'cash'], $this->uuid());
            $this->fail('Expected 403 for doctor on recordPayment');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
        }
        try {
            $this->finance()->addAdjustment($this->doctorUserId, (int) $invoice['id'], 'credit', ['amount' => 1, 'reason' => 'r']);
            $this->fail('Expected 403 for doctor on addAdjustment');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
        }
        $docInvoice = $this->makeInvoice(200000);
        $docPay = $this->finance()->recordPayment($this->secretaryUserId, (int) $docInvoice['id'], ['amount' => 200000, 'method' => 'cash'], $this->uuid());
        // پزشک مجاز به void/refund است (ماتریس §3) — تست روی فاکتور مستقل
        $this->finance()->voidPayment($this->doctorUserId, (int) $docPay['payment_id'], 'ابطال توسط پزشک');
        try {
            $this->finance()->refundPayment($this->doctorUserId, (int) $docPay['payment_id'], 'روی voided');
            $this->fail('Expected CLINIC_INVALID_TRANSITION (refund on voided payment)');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_INVALID_TRANSITION', $e->errorCode);
        }

        // پزشک مجاز به خلاصه مالی و صدور فاکتور (تست جدا) — و summary OK
        $this->assertIsArray($this->finance()->summary($this->doctorUserId, gmdate('Y-m-d'), gmdate('Y-m-d')));
    }

    public function testDoctorIssueInvoiceAllowedAndAdminIsTechnicalOnly(): void
    {
        $visitId = $this->makeCompletedVisit();

        // پزشک مجاز به صدور (ماتریس §7)
        $inv = $this->finance()->issueInvoice($this->doctorUserId, [
            'visit_id' => $visitId, 'items' => [['description' => 'ویزیت', 'unit_price' => 100000]],
        ]);
        $this->assertSame('open', $inv['status']);

        // P-3 — admin وردپرس فقط فنی است: صدور فاکتور ندارد
        $visitId2 = $this->makeCompletedVisit();
        try {
            $this->finance()->issueInvoice($this->adminUserId, [
                'visit_id' => $visitId2, 'items' => [['description' => 'ویزیت', 'unit_price' => 100000]],
            ]);
            $this->fail('Expected 403 for admin (P-3 technical only)');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
        }
    }

    // ================= V14 — Checkout regression =================

    public function testCheckoutWithoutInvoiceStillWorks(): void
    {
        // بدون فاکتور: awaiting_payment → waive = خروج مستقیم (V13 — checked_out)
        $visitId = $this->makeCompletedVisit();
        App::visitService()->transition($this->secretaryUserId, $visitId, 'invoice_ready');
        $waived = App::visitService()->checkout($this->secretaryUserId, $visitId, 'معافیت بیمار خاص');
        $this->assertSame('checked_out', $waived['status']);

        // paid (تسویه سیستمی بدون فاکتور — فقره باز V14 بدون فاکتور) → check_out OK
        $visitId2 = $this->makeCompletedVisit();
        App::visitService()->transition($this->secretaryUserId, $visitId2, 'invoice_ready');
        $visit = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitId2]);
        App::visitService()->applyTransition($this->secretaryUserId, $visit, 'settled', [], 'system');
        $out = App::visitService()->checkout($this->secretaryUserId, $visitId2, null);
        $this->assertSame('checked_out', $out['status']);
    }

    // ================= Helpers =================

    private function finance(): \ClinicCore\Application\Finance\FinanceService
    {
        return App::financeService();
    }

    private function uuid(): string
    {
        $d = static fn (int $len): string => bin2hex(random_bytes((int) ceil($len / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $d(8), $d(4), substr($d(3), 0, 3), substr($d(4), 0, 4), $d(12));
    }

    /** ویزیت واقعی تا in_consultation — هر فراخوانی بیمار تازه (J-5: ویزیت فعال واحد در روز). */
    private function makeConsultation(?int $patientId = null): int
    {
        $patientId ??= $this->makePatient();
        $visit = App::visitService()->walkIn($this->secretaryUserId, $patientId, $this->clinicianId);
        $id = (int) $visit['id'];
        App::visitService()->transition($this->doctorUserId, $id, 'call');
        App::visitService()->transition($this->doctorUserId, $id, 'start');

        return $id;
    }

    private function makePatient(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-FF-' . $seq,
                'Finance',
                'P' . $seq,
                '0912' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    /** ویزیت تا consultation_completed (با Chief Complaint — FR-8.7)؛ بیمار تازه در هر فراخوانی. */
    private function makeCompletedVisit(): int
    {
        $visitId = $this->makeConsultation();
        App::clinicalService()->addNote($this->doctorUserId, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'درد و تهوع',
        ]);
        App::clinicalService()->completeConsultation($this->doctorUserId, $visitId);

        return $visitId;
    }

    private function makeService(string $code, string $name, int $price): int
    {
        return (int) $this->finance()->createService($this->adminUserId, ['code' => $code, 'name' => $name, 'price' => $price])['id'];
    }

    /** فاکتور باز با یک قلم — ویزیت completed تازه (بیمار تازه) مگر این‌که ویزیت پاس شود. */
    private function makeInvoice(int $total, ?int $visitId = null): array
    {
        $visitId ??= $this->makeCompletedVisit();

        return $this->finance()->issueInvoice($this->secretaryUserId, [
            'visit_id' => $visitId,
            'items' => [['description' => 'ویزیت', 'unit_price' => $total]],
        ]);
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
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $body = [], array $headers = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        return rest_do_request($request);
    }
}
