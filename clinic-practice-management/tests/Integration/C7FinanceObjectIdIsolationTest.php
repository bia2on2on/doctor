<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * C7-0 / C7-C1 — تست Characterization اجرایی: ایزولیشن «شناسهٔ شیء» در مسیرهای مالیِ مبتنی بر ID.
 *
 * این فایل فقط شواهد است (evidence branch). هیچ رفتار تولیدی را تغییر نمی‌دهد.
 * مرجع یافتهٔ کاندیدا: docs/phase-reports/c7-0-census.md §۵ (ردیف‌های ۱۶ و ۱۷ ماتریس).
 *
 * خاصیت امنیتی مورد آزمایش:
 *   فراخوانی تحت Scope مورد اعتمادِ Clinic B (عضویت فعال + تأیید مرز REST C6)
 *   نباید صرفاً با ارسال شناسهٔ شیءِ Clinic A به فاکتور/پرداختِ Clinic A
 *   دسترسی خواندن یا تغییر به دست آورد.
 *
 * رفتار امنِ مورد انتظار (قرارداد محصول: fail-closed + 404 parity — همان
 * پاکت «یافت نشد»، الگوی MedicalFileService/c6-corrective):
 *   - پاسخ 404 با کد CLINIC_NOT_FOUND
 *   - بدون ردیف جهش بین‌کلینیکی در DB
 *   - وضعیت فاکتور/پرداخت قربانی بدون تغییر
 *
 * این فایل «مشخصهٔ ایزولیشن» است، نه آینهٔ رفتار فعلی. اگر تستی در main فعلی
 * قرمز شود، همان قرمزی، شاهدِ نقصِ ازپیش‌موجودِ محصول است (طبقه‌بندی خطای
 * پروژه: کلاس B). برای سبز کردن CI نباید این تست‌ها تضعیف/skip/تغییر داده
 * شوند؛ اصلاح در برش پیاده‌سازی جداگانهٔ C7 انجام می‌شود.
 *
 * استراتژی fixture:
 *   - Clinic قربانی A = 61301 و Clinic مهاجم B = 61302 — هر دو در محدودهٔ رزرو
 *     تست (≥ 61000)؛ هیچ‌کدام 1 نیستند و B فرض نمی‌کند «Clinic 2» آزاد است.
 *   - Scope مورد اعتماد B به‌صورت مستقل از اشیای قربانی برقرار می‌شود:
 *     منشیِ B فقط عضویت فعال روی B دارد و درخواست حمله هدر
 *     X-CPMS-Clinic-Id: 61302 را حمل می‌کند (سرور آن را با عضویت تأیید می‌کند).
 *     هویت مورد اعتماد هرگز از خودِ فاکتور/پرداخت قربانی گرفته نمی‌شود.
 *   - فاکتور/پرداخت قربانی به‌شکل مشروع توسط منشیِ خودِ Clinic A از مسیر
 *     واقعی سرویس (walk-in → مشاوره → فاکتور → پرداخت) ساخته می‌شود.
 */
final class C7FinanceObjectIdIsolationTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    /** Clinic قربانی — محدودهٔ رزرو تست (≥ 61000). */
    private const CLINIC_A = 61301;

    /** Clinic مهاجم (Scope مورد اعتمادِ فراخوان) — محدودهٔ رزرو تست. */
    private const CLINIC_B = 61302;

    private int $secretaryA = 0;

    private int $doctorA = 0;

    private int $secretaryB = 0;

    private int $clinicianA = 0;

    private int $patientA = 0;

    private string $patientAName = '';

    private string $patientAMrn = '';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * شاهد ایزوله‌سازی: ابتدای هر تست نباید هیچ Clinic رزرو (≥ 61000) از
         * تست قبلی باقی مانده باشد — الگوی ClinicTenantIsolationTest.
         */
        global $wpdb;
        $leftover = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id >= 61000' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(
            0,
            $leftover,
            'CPMS_ISOLATION_WITNESS: ' . $leftover . ' Clinic رزرو از تست قبلی باقی مانده — rollback/پاک‌سازی مختل شده'
        );

        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        /*
         * Warm خنثی پیش از ساخت fixtureها (الگوی ClinicTenantIsolationTest):
         * App::boot() یک‌بارمصرف است و rest_api_init سرویس‌ها را با Scope/
         * Settings همان لحظه در کلوزر routeها pin می‌کند. این کلاس ممکن است
         * نخستین لمس‌کنندهٔ REST در پروسه باشد؛ warm در Scope خنثی یعنی
         * pin == baseline (Clinic تنها/۱) — نه Clinic fixture.
         */
        $this->warmRoutes();

        // این دو تنظیم باید پیش از ساخت Clinicهای دوم اعمال شوند (تنها Clinic seed).
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('clinical.require_chief_complaint', true);
        App::resetScope();

        $orgId = $this->defaultOrganization();
        $this->insertClinic(self::CLINIC_A, $orgId, 'c7-fin-clinic-a');
        $this->insertClinic(self::CLINIC_B, $orgId, 'c7-fin-clinic-b');
        $this->insertLocation(self::CLINIC_A, 'c7-fin-loc-a');
        $this->insertLocation(self::CLINIC_B, 'c7-fin-loc-b');

        // Clinic A — کاربران مشروعِ مالکِ اشیای قربانی.
        $this->secretaryA = $this->makeUser('c7fin_sec_a', 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryA, self::CLINIC_A, 'cpms_secretary');
        $this->doctorA = $this->makeUser('c7fin_doc_a', 'cpms_doctor');
        cpms_test_seed_membership($this->doctorA, self::CLINIC_A, 'cpms_doctor');
        $this->clinicianA = $this->insertClinician(self::CLINIC_A, $this->doctorA, 'Dr C7 Finance Victim');

        // Clinic B — مهاجم: عضویت فعال فقط روی B (Scope مورد اعتماد مستقل از قربانی).
        $this->secretaryB = $this->makeUser('c7fin_sec_b', 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryB, self::CLINIC_B, 'cpms_secretary');

        $patient = $this->insertPatient(self::CLINIC_A);
        $this->patientA = $patient['id'];
        $this->patientAName = $patient['name'];
        $this->patientAMrn = $patient['mrn'];
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();

        // نشتی‌گیر دفاعی fixture (در صورت اختلال rollback) — هیچ ادعای محصولی را سست نمی‌کند.
        $this->purgeReserveRows();

        parent::tearDown();
    }

    // ================= C7-C1/A — ثبت پرداخت روی فاکتور Clinic دیگر =================

    /**
     * C7-C1/A — recordPayment بر پایهٔ invoice_id ورودی.
     *
     * خاصیت: منشیِ Clinic B (با PAYMENT_CREATE و Scope معتبر B) نباید بتواند
     * روی فاکتور Clinic A پرداخت ثبت کند. انتظار: 404 + CLINIC_NOT_FOUND؛
     * بدون ردیف پرداخت جدید برای فاکتور قربانی؛ فاکتور hand‌نخورده.
     * قرمزی این تست در main فعلی = شاهد کلاس B (census §۵).
     */
    public function testRecordPaymentAgainstForeignClinicInvoiceMustFailClosed(): void
    {
        $invoice = $this->issueVictimInvoice(100000);
        $invoiceId = (int) $invoice['id'];

        wp_set_current_user($this->secretaryB);
        $res = $this->dispatch('POST', self::NS . '/invoices/' . $invoiceId . '/payments', [
            'amount' => 40000,
            'method' => 'cash',
        ], [
            'Idempotency-Key' => $this->uuid(),
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $payment = $this->fetchPaymentForInvoice($invoiceId);
        $inv = $this->fetchInvoiceRow($invoiceId);

        $this->assertSame(
            404,
            $status,
            'C7-C1/A recordPayment: انتظار پاسخ fail-closed معادل 404 (CLINIC_NOT_FOUND). '
            . "واقعی: HTTP {$status} code={$code}; "
            . 'DB: payment_row=' . ($payment === null ? 'none' : $this->sig($payment))
            . "; victim_invoice(clinic_id={$inv['clinic_id']}, status={$inv['status']}, paid_amount={$inv['paid_amount']})"
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertNull($payment, 'نباید هیچ ردیف پرداختی برای فاکتور Clinic A ثبت شود');
        $this->assertSame(0.0, (float) $inv['paid_amount'], 'paid_amount فاکتور قربانی نباید تغییر کند');
        $this->assertSame('open', (string) $inv['status'], 'وضعیت فاکتور قربانی نباید تغییر کند');
    }

    // ================= C7-C1/B — اصلاح فاکتور Clinic دیگر =================

    /**
     * C7-C1/B — addAdjustment بر پایهٔ invoice_id ورودی.
     *
     * خاصیت: منشیِ Clinic B (با INVOICE_ADJUST) نباید بتواند فاکتور Clinic A
     * را اصلاح (credit/debit) کند. انتظار: 404 + CLINIC_NOT_FOUND؛ بدون ردیف
     * اصلاح؛ balance فاکتور قربانی بدون تغییر.
     */
    public function testAdjustmentAgainstForeignClinicInvoiceMustFailClosed(): void
    {
        $invoice = $this->issueVictimInvoice(100000);
        $invoiceId = (int) $invoice['id'];

        wp_set_current_user($this->secretaryB);
        $res = $this->dispatch('POST', self::NS . '/invoices/' . $invoiceId . '/adjustments', [
            'type' => 'credit',
            'amount' => 25000,
            'reason' => 'c7 cross-clinic adjustment attempt',
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $adjustments = App::db()->fetchRow(
            'SELECT id, invoice_id, type, amount, approved_by_wp_user_id FROM ' . App::db()->table('cpms_payment_adjustments')
            . ' WHERE invoice_id = %d',
            [$invoiceId]
        );
        $inv = $this->fetchInvoiceRow($invoiceId);

        $this->assertSame(
            404,
            $status,
            'C7-C1/B addAdjustment: انتظار پاسخ fail-closed معادل 404 (CLINIC_NOT_FOUND). '
            . "واقعی: HTTP {$status} code={$code}; "
            . 'DB: adjustment_row=' . ($adjustments === null ? 'none' : $this->sig($adjustments))
            . "; victim_invoice(balance={$inv['balance']}, status={$inv['status']})"
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertNull($adjustments, 'نباید هیچ ردیف اصلاحی برای فاکتور Clinic A ثبت شود');
        $this->assertSame(100000.0, (float) $inv['balance'], 'balance فاکتور قربانی نباید تغییر کند');
        $this->assertSame('open', (string) $inv['status'], 'وضعیت فاکتور قربانی نباید تغییر کند');
    }

    // ================= C7-C1/C — ابطال پرداخت Clinic دیگر =================

    /**
     * C7-C1/C — voidPayment بر پایهٔ payment_id ورودی.
     *
     * خاصیت: منشیِ Clinic B (با PAYMENT_VOID) نباید بتواند پرداخت Clinic A
     * را ابطال کند. انتظار: 404 + CLINIC_NOT_FOUND؛ وضعیت پرداخت قربانی
     * هنوز captured و بدون voided_by؛ فاکتور قربانی بدون تغییر.
     */
    public function testVoidOfForeignClinicPaymentMustFailClosed(): void
    {
        $invoice = $this->issueVictimInvoice(100000);
        $invoiceId = (int) $invoice['id'];
        $paymentId = $this->recordVictimPayment($invoiceId, 100000);

        // پیش‌شرط: پرداختِ امروزِ Clinic A در وضعیت captured و فاکتور تسویه‌شده.
        $pre = $this->fetchPaymentRow($paymentId);
        self::assertSame('captured', (string) $pre['status'], 'پیش‌شرط: پرداخت قربانی captured است');
        self::assertSame(100000.0, (float) $this->fetchInvoiceRow($invoiceId)['paid_amount'], 'پیش‌شرط: فاکتور تسویه است');

        wp_set_current_user($this->secretaryB);
        $res = $this->dispatch('POST', self::NS . '/payments/' . $paymentId . '/void', [
            'reason' => 'c7 cross-clinic void attempt',
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $pay = $this->fetchPaymentRow($paymentId);
        $inv = $this->fetchInvoiceRow($invoiceId);

        $this->assertSame(
            404,
            $status,
            'C7-C1/C voidPayment: انتظار پاسخ fail-closed معادل 404 (CLINIC_NOT_FOUND). '
            . "واقعی: HTTP {$status} code={$code}; "
            . "DB: victim_payment(status={$pay['status']}, voided_by_wp_user_id={$pay['voided_by_wp_user_id']}, clinic_id={$pay['clinic_id']}); "
            . "victim_invoice(paid_amount={$inv['paid_amount']}, status={$inv['status']})"
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertSame('captured', (string) $pay['status'], 'وضعیت پرداخت قربانی نباید تغییر کند');
        $this->assertEmpty((int) $pay['voided_by_wp_user_id'], 'ابطال‌کننده نباید کاربر Clinic B باشد');
        $this->assertSame(100000.0, (float) $inv['paid_amount'], 'paid_amount فاکتور قربانی نباید برگردد');
    }

    // ================= C7-C1/D — بازپرداخت پرداخت Clinic دیگر =================

    /**
     * C7-C1/D — refundPayment بر پایهٔ payment_id ورودی.
     *
     * خاصیت: منشیِ Clinic B (با PAYMENT_REFUND) نباید بتواند پرداخت Clinic A
     * را مسترد کند. انتظار: 404 + CLINIC_NOT_FOUND؛ refunded_amount صفر و
     * وضعیت captured باقی بماند؛ فاکتور قربانی بدون تغییر.
     */
    public function testRefundOfForeignClinicPaymentMustFailClosed(): void
    {
        $invoice = $this->issueVictimInvoice(100000);
        $invoiceId = (int) $invoice['id'];
        $paymentId = $this->recordVictimPayment($invoiceId, 40000);

        // پیش‌شرط: پرداخت جزئیِ امروزِ Clinic A؛ فاکتور partial.
        $pre = $this->fetchPaymentRow($paymentId);
        self::assertSame('captured', (string) $pre['status'], 'پیش‌شرط: پرداخت قربانی captured است');
        self::assertSame('partial', (string) $this->fetchInvoiceRow($invoiceId)['status'], 'پیش‌شرط: فاکتور قربانی partial است');

        wp_set_current_user($this->secretaryB);
        $res = $this->dispatch('POST', self::NS . '/payments/' . $paymentId . '/refund', [
            'reason' => 'c7 cross-clinic refund attempt',
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $pay = $this->fetchPaymentRow($paymentId);
        $inv = $this->fetchInvoiceRow($invoiceId);

        $this->assertSame(
            404,
            $status,
            'C7-C1/D refundPayment: انتظار پاسخ fail-closed معادل 404 (CLINIC_NOT_FOUND). '
            . "واقعی: HTTP {$status} code={$code}; "
            . "DB: victim_payment(status={$pay['status']}, refunded_amount={$pay['refunded_amount']}, clinic_id={$pay['clinic_id']}); "
            . "victim_invoice(paid_amount={$inv['paid_amount']}, status={$inv['status']})"
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertSame(0.0, (float) $pay['refunded_amount'], 'refunded_amount پرداخت قربانی نباید تغییر کند');
        $this->assertSame('captured', (string) $pay['status'], 'وضعیت پرداخت قربانی نباید تغییر کند');
        $this->assertSame(40000.0, (float) $inv['paid_amount'], 'paid_amount فاکتور قربانی نباید برگردد');
    }

    // ================= C7-C1/E — خواندن فاکتور Clinic دیگر (مسیرهای تولیدی) =================

    /**
     * C7-C1/E.1 — findInvoiceForActor بر پایهٔ invoice_id (GET /invoices/{id}).
     *
     * خاصیت: منشیِ Clinic B (با INVOICE_READ) نباید بتواند فاکتور Clinic A
     * را بخواند. انتظار: 404 + CLINIC_NOT_FOUND؛ بدنهٔ پاسخ نباید داده‌های
     * مالی/بیماریِ فاکتور قربانی (شماره، مبالغ، patient_id، اقلام) را حمل کند.
     */
    public function testInvoiceReadOfForeignClinicInvoiceMustFailClosed(): void
    {
        $invoice = $this->issueVictimInvoice(100000);
        $invoiceId = (int) $invoice['id'];

        wp_set_current_user($this->secretaryB);
        $res = $this->dispatch('GET', self::NS . '/invoices/' . $invoiceId, [], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $body = (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE);

        $this->assertSame(
            404,
            $status,
            'C7-C1/E.1 findInvoiceForActor: انتظار پاسخ fail-closed معادل 404 (CLINIC_NOT_FOUND). '
            . "واقعی: HTTP {$status} code={$code}; leaked_body=" . substr($body, 0, 300)
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertStringNotContainsString((string) $invoice['invoice_number'], $body, 'شمارهٔ فاکتور قربانی نباید فاش شود');
        $this->assertStringNotContainsString($this->patientAName, $body, 'هویت بیمار قربانی نباید فاش شود');
    }

    /**
     * C7-C1/E.2 — receipt بر پایهٔ invoice_id (GET /invoices/{id}/receipt).
     *
     * خاصیت: رسیدِ فاکتور Clinic A شامل نام بیمار و MRN است؛ منشیِ Clinic B
     * نباید به آن دسترسی داشته باشد. انتظار: 404 + CLINIC_NOT_FOUND؛
     * نام/MRN بیمار قربانی نباید در پاسخ ظاهر شود (PII).
     */
    public function testReceiptOfForeignClinicInvoiceMustNotLeakPatientIdentity(): void
    {
        $invoice = $this->issueVictimInvoice(100000);
        $invoiceId = (int) $invoice['id'];

        wp_set_current_user($this->secretaryB);
        $res = $this->dispatch('GET', self::NS . '/invoices/' . $invoiceId . '/receipt', [], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $body = (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE);

        $this->assertSame(
            404,
            $status,
            'C7-C1/E.2 receipt: انتظار پاسخ fail-closed معادل 404 (CLINIC_NOT_FOUND). '
            . "واقعی: HTTP {$status} code={$code}; leaked_body=" . substr($body, 0, 300)
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertStringNotContainsString($this->patientAName, $body, 'نام بیمار Clinic A نباید فاش شود');
        $this->assertStringNotContainsString($this->patientAMrn, $body, 'MRN بیمار Clinic A نباید فاش شود');
    }

    /**
     * C7-C1/E.3 — invoiceForVisit بر پایهٔ visit_id (GET /visits/{id}/invoice).
     *
     * خاصیت: منشیِ Clinic B نباید بتواند از راه شناسهٔ ویزیتِ Clinic A به
     * فاکتور فعالِ آن کلینیک برسد. انتظار: 404 + CLINIC_NOT_FOUND؛
     * شمارهٔ فاکتور قربانی نباید در پاسخ ظاهر شود.
     */
    public function testInvoiceForVisitLookupOfForeignClinicVisitMustFailClosed(): void
    {
        $visitId = $this->makeCompletedVictimVisit();
        $invoice = $this->issueInvoiceForVisit($visitId, 100000);
        $invoiceId = (int) $invoice['id'];
        self::assertGreaterThan(0, $invoiceId, 'پیش‌شرط: فاکتور قربانی وجود دارد');

        wp_set_current_user($this->secretaryB);
        $res = $this->dispatch('GET', self::NS . '/visits/' . $visitId . '/invoice', [], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $body = (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE);

        $this->assertSame(
            404,
            $status,
            'C7-C1/E.3 invoiceForVisit: انتظار پاسخ fail-closed معادل 404 (CLINIC_NOT_FOUND). '
            . "واقعی: HTTP {$status} code={$code}; leaked_body=" . substr($body, 0, 300)
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertStringNotContainsString((string) $invoice['invoice_number'], $body, 'شمارهٔ فاکتور قربانی نباید فاش شود');
    }

    // ================= C7-NO-SCOPE — قاعدهٔ معماری: بدون زمینهٔ معتبر، fail-closed =================

    /**
     * C7-NO-SCOPE — قاعدهٔ موردنظر معماری (تصمیم معمار پس از پذیرش C7-S1):
     *
     * «عملیات حساسِ مالیِ مبتنی بر شناسهٔ شیء، در نبودِ زمینهٔ کلینیکِ معتبرِ
     * درخواست باید fail-closed باشد — نه اینکه هویت tenant را از ردیفِ هدف
     * وام بگیرد.»
     *
     * ردیابی فراخوان‌ها (پیش از نوشتن این تست): هر هفت عملیات مبتنی بر ID
     * مالی دقیقاً یک فراخوان تولیدی دارند — FinanceController (REST) — و مرز
     * REST برای staff همیشه Scope صریح برقرار می‌کند؛ یعنی هیچ فراخوانِ
     * تولیدیِ بدون Scope برای این متدها وجود ندارد. فراخوانی‌های بدون Scope
     * در تست‌های موجود (FinanceFlowTest/FinanceClinicIsolationTest) صرفاً
     * سادگی harness تست تک‌کلینیکی‌اند، نه آینهٔ هیچ مسیر تولیدی.
     *
     * C7-S3: این قاعده اکنون در خود سرویس پیاده شده است (relief اختیاریِ
     * C7-S1 حذف شد) — این تست از این پس رگرسیونِ دائمیِ سبزِ همان قرارداد
     * است: کد استاندارد CLINIC_SCOPE_REQUIRED + HTTP 400 + DB دست‌نخورده.
     *
     * نمایندهٔ انتخابی: voidPayment (جهش مالی حساس با وضعیت DB قابل assert).
     * fixture: فاکتور/پرداخت مشروع Clinic A؛ سپس حذف کامل هر Scope صریح و
     * فراخوانی مستقیم سرویس. انتظار: استثنای fail-closed + DB دست‌نخورده.
     */
    public function testVoidPaymentWithoutTrustedClinicContextFailsClosedInsteadOfAdoptingRowClinic(): void
    {
        $invoice = $this->issueVictimInvoice(100000);
        $invoiceId = (int) $invoice['id'];
        $paymentId = $this->recordVictimPayment($invoiceId, 100000);

        // پیش‌شرط: پرداختِ امروزِ Clinic A در وضعیت captured و فاکتور تسویه‌شده.
        $pre = $this->fetchPaymentRow($paymentId);
        self::assertSame('captured', (string) $pre['status'], 'پیش‌شرط: پرداخت قربانی captured است');
        self::assertSame(100000.0, (float) $this->fetchInvoiceRow($invoiceId)['paid_amount'], 'پیش‌شرط: فاکتور تسویه است');

        // حذف کامل زمینهٔ مورد اعتماد — نه Scope صریح، نه Clinicِ برگرفته از ردیف.
        wp_set_current_user($this->secretaryA);
        App::resetScope();
        self::assertNull(ScopeContext::tryGet(), 'پیش‌شرط: هیچ Scope صریحی برقرار نیست');

        $failedClosed = false;
        $thrown = null;
        try {
            $this->finance()->voidPayment($this->secretaryA, $paymentId, 'c7 no-scope characterization');
        } catch (FinanceException $e) {
            $failedClosed = true;
            $thrown = $e;
        }

        $pay = $this->fetchPaymentRow($paymentId);
        $inv = $this->fetchInvoiceRow($invoiceId);

        $this->assertTrue(
            $failedClosed,
            'C7-NO-SCOPE voidPayment: عملیات حساس مالی بدون هیچ زمینهٔ کلینیک معتبر باید '
            . 'fail-closed باشد، نه اینکه Clinic را از خودِ ردیفِ هدف وام گیرد. '
            . 'رفتار واقعی: عملیات بدون استثنا اجرا شد. '
            . "DB: victim_payment(status={$pay['status']}, voided_by_wp_user_id={$pay['voided_by_wp_user_id']}, clinic_id={$pay['clinic_id']}); "
            . "victim_invoice(paid_amount={$inv['paid_amount']}, status={$inv['status']})"
        );

        // کد ماشین‌خوان استانداردِ نبودِ Scope (قرارداد SystemClinicResolver/
        // ScopeRequiredException — C7-S3) و وضعیت HTTP مرسوم آن.
        $this->assertSame(
            'CLINIC_SCOPE_REQUIRED',
            $thrown->errorCode,
            'کد استثنا باید قرارداد کانونی CLINIC_SCOPE_REQUIRED باشد، نه کد دیگری.'
        );
        $this->assertSame(400, $thrown->httpStatus, 'وضعیت HTTP استانداردِ CLINIC_SCOPE_REQUIRED');

        // حتی در حالت fail-closed هم DB باید دست‌نخورده بماند (استثنای فریبنده ننویسد).
        $this->assertSame('captured', (string) $pay['status'], 'وضعیت پرداخت قربانی نباید تغییر کند');
        $this->assertEmpty((int) $pay['voided_by_wp_user_id'], 'ابطال‌کننده‌ای نباید ثبت شده باشد');
        $this->assertSame(100000.0, (float) $inv['paid_amount'], 'paid_amount فاکتور قربانی نباید برگردد');
    }

    // ================= Helpers — fixture قربانی (مسیر واقعی سرویس) =================

    private function finance(): \ClinicCore\Application\Finance\FinanceService
    {
        return App::financeService();
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private function withScope(int $clinicId, callable $fn)
    {
        $previous = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic($clinicId));
        try {
            return $fn();
        } finally {
            App::replaceExplicitScope($previous);
        }
    }

    private function warmRoutes(): void
    {
        \ClinicCore\Settings\Settings::flushCache();
        rest_do_request(new WP_REST_Request('GET', self::NS . '/health'));
        \ClinicCore\Settings\Settings::flushCache();
    }

    private function makeCompletedVictimVisit(): int
    {
        return $this->withScope(self::CLINIC_A, function (): int {
            $visit = App::visitService()->walkIn($this->secretaryA, $this->patientA, $this->clinicianA);
            $id = (int) $visit['id'];
            App::visitService()->transition($this->doctorA, $id, 'call');
            App::visitService()->transition($this->doctorA, $id, 'start');
            App::clinicalService()->addNote($this->doctorA, $id, [
                'category' => 'chief_complaint',
                'visibility' => 'patient_visible',
                'content_text' => 'درد و تهوع (fixture قربانی C7)',
            ]);
            App::clinicalService()->completeConsultation($this->doctorA, $id);

            return $id;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function issueInvoiceForVisit(int $visitId, int $total): array
    {
        return $this->withScope(self::CLINIC_A, fn (): array => $this->finance()->issueInvoice($this->secretaryA, [
            'visit_id' => $visitId,
            'items' => [['description' => 'ویزیت قربانی C7', 'unit_price' => $total]],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function issueVictimInvoice(int $total): array
    {
        $visitId = $this->makeCompletedVictimVisit();

        return $this->issueInvoiceForVisit($visitId, $total);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchInvoiceRow(int $invoiceId): array
    {
        $row = App::db()->fetchRow(
            'SELECT id, clinic_id, status, total, paid_amount, balance FROM ' . App::db()->table('cpms_invoices')
            . ' WHERE id = %d',
            [$invoiceId]
        );
        self::assertNotNull($row, 'پیش‌شرط: فاکتور قربانی در DB وجود دارد');

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPaymentRow(int $paymentId): array
    {
        $row = App::db()->fetchRow(
            'SELECT id, clinic_id, invoice_id, status, amount, refunded_amount, received_by_wp_user_id,'
            . ' voided_by_wp_user_id FROM ' . App::db()->table('cpms_payments') . ' WHERE id = %d',
            [$paymentId]
        );
        self::assertNotNull($row, 'پیش‌شرط: پرداخت قربانی در DB وجود دارد');

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPaymentForInvoice(int $invoiceId): ?array
    {
        return App::db()->fetchRow(
            'SELECT id, clinic_id, invoice_id, status, amount, received_by_wp_user_id FROM ' . App::db()->table('cpms_payments')
            . ' WHERE invoice_id = %d',
            [$invoiceId]
        );
    }

    private function recordVictimPayment(int $invoiceId, int $amount): int
    {
        $result = $this->withScope(
            self::CLINIC_A,
            fn (): array => $this->finance()->recordPayment(
                $this->secretaryA,
                $invoiceId,
                ['amount' => $amount, 'method' => 'cash'],
                $this->uuid()
            )
        );

        return (int) $result['payment_id'];
    }

    // ================= Helpers — عمومی تست =================

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

    private function errorCode(WP_REST_Response $res): string
    {
        $body = $res->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code();
        }

        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
    }

    /**
     * امضای فشردهٔ ردیف DB برای پیام‌های assertion (شواهد CI).
     *
     * @param array<string, mixed> $row
     */
    private function sig(array $row): string
    {
        $parts = [];
        foreach ($row as $key => $value) {
            $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : json_encode($value));
        }

        return '{' . implode(',', $parts) . '}';
    }

    private function uuid(): string
    {
        $d = static fn (int $len): string => bin2hex(random_bytes((int) ceil($len / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $d(8), $d(4), substr($d(3), 0, 3), substr($d(4), 0, 4), $d(12));
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . bin2hex(random_bytes(2)) . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function defaultOrganization(): int
    {
        global $wpdb;
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $orgId, 'پیش‌شرط: Organization از ردیف seed شدهٔ نصب خوانده شود');

        return $orgId;
    }

    private function insertClinic(int $id, int $orgId, string $slug): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
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
        self::assertNotEquals(1, $id, '⚑ این فایل نباید به Clinic id=1 تکیه کند');
        App::resetScope();
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
                'Loc ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertClinician(int $clinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $name,
                $wpUserId,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array{id: int, name: string, mrn: string}
     */
    private function insertPatient(int $clinicId): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-C7FIN-' . bin2hex(random_bytes(3));
        $first = 'C7FinVictim';
        $last = 'PiiCanary' . bin2hex(random_bytes(2));
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $mrn,
                $first,
                $last,
                '0912' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $patientId);

        return ['id' => $patientId, 'name' => trim($first . ' ' . $last), 'mrn' => $mrn];
    }

    /**
     * پاک‌سازی دفاعی fixture در محدودهٔ رزرو — الگوی ClinicTenantIsolationTest.
     * هیچ ادعای محصولی را سست نمی‌کند؛ فقط نشتی احتمالی بین کلاس‌ها را می‌بندد.
     */
    private function purgeReserveRows(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $pure = 'WHERE clinic_id >= 61000';
        $inv = 'WHERE invoice_id IN (SELECT id FROM ' . $p . 'cpms_invoices WHERE clinic_id >= 61000)';
        $vis = 'WHERE visit_id IN (SELECT id FROM ' . $p . 'cpms_visits WHERE clinic_id >= 61000)';
        $mem = 'WHERE membership_id IN (SELECT id FROM ' . $p . 'cpms_clinic_memberships WHERE clinic_id >= 61000)';
        $steps = [
            'cpms_payments' => $inv,
            'cpms_payment_adjustments' => $inv,
            'cpms_invoices' => $pure,
            'cpms_visit_status_history' => $vis,
            'cpms_visits' => $pure,
            'cpms_patients' => $pure,
            'cpms_clinicians' => $pure,
            'cpms_locations' => $pure,
            'cpms_membership_capabilities' => $mem,
            'cpms_membership_locations' => $mem,
            'cpms_clinic_memberships' => $pure,
            'cpms_clinics' => 'WHERE id >= 61000',
        ];
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ($steps as $table => $clause) {
            $wpdb->query('DELETE FROM ' . $p . $table . ' ' . $clause); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}
