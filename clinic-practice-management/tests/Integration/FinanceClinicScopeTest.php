<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Finance\FinanceService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * C6 post-closure corrective — جداسازی Clinic در هفت مسیر مالی.
 *
 * پیش از این corrective، هفت نقطه در کد تولید `clinic_id` را با literal `1`
 * bind می‌کردند (ServiceRepository::all، PaymentRepository::revenueSummary /
 * forRange / nextPaymentNumber، InvoiceRepository::openInvoices /
 * nextInvoiceNumber و FinanceService::lockClinic). نتیجه در نصب چند‌کلینیکی:
 * خلاصهٔ مالی، فاکتورهای باز، نام و MRN بیمار، تعرفه‌ها، شمارهٔ سریال
 * فاکتور/پرداخت و حتی قفل ردیف — همه به Clinic 1 گره خورده بودند.
 *
 * این فایل «مشخصۀ جداسازی» است نه آینهٔ رفتار: انتظارها از معماری
 * (Organization → Clinic → Location) می‌آیند.
 *
 * ⚑ Clinicهای عملیاتی این تست **عمداً ۱ نیستند** (B=62001، C=62002) تا هیچ
 *   تستی با «Clinic 1» سبز نشود. سازگاری Clinic 1 در تست جداگانه و صریح
 *   سنجیده می‌شود (نه به‌شکل پیش‌فرض پنهان).
 *
 * همهٔ داده‌ها ردیف‌های واقعی MySQL هستند و همهٔ ادعاها از مسیر واقعی
 * Service/Repository خوانده می‌شوند — بدون mock.
 */
final class FinanceClinicScopeTest extends WP_UnitTestCase
{
    private const CLINIC_B = 62001;

    private const CLINIC_C = 62002;

    private const DEFAULT_CLINIC = 1;

    /** @var list<string> کوئری‌های FOR UPDATE گرفته‌شده در تستِ قفل */
    private array $lockQueries = [];

    private int $orgA = 0;

    private int $locB = 0;

    private int $locC = 0;

    private int $adminUserId = 0;

    private int $secretaryB = 0;

    private int $secretaryC = 0;

    private int $patientB = 0;

    private int $patientC = 0;

    private int $visitB = 0;

    private int $visitC = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * شاهد ایزوله‌سازی: اگر پاک‌سازی تست قبلی این کلاس مختل شده باشد،
         * صریح گزارش می‌شود — نه به‌شکل assertion بی‌ربط در کلاس دیگر.
         */
        global $wpdb;
        $leftover = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id >= 62000' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(
            0,
            $leftover,
            'CPMS_FINANCE_ISOLATION_WITNESS: ' . $leftover . ' Clinic رزرو از تست قبلی باقی مانده است'
        );

        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        // نصب این تست چند‌کلینیکی است ⇒ Resolution سیستمی عمداً Fail-Closed است؛
        // پس fixtureها هم همیشه زیر Scope صریح اجرا می‌شوند.
        $this->orgA = $this->defaultOrganization();

        $this->insertClinic(self::CLINIC_B, $this->orgA, 'fin-clinic-b');
        $this->insertClinic(self::CLINIC_C, $this->orgA, 'fin-clinic-c');
        // insertClinic عمداً Scope را reset می‌کند (تا Resolution کش‌شده کهنه
        // نماند)؛ از این نقطه به بعد هر fixture باید زیر Scope صریح باشد وگرنه
        // MembershipService به Resolution سیستمی می‌افتد و Fail-Closed می‌شود.
        $this->bindScope(self::CLINIC_B);

        $this->locB = $this->insertLocation(self::CLINIC_B, 'fin-loc-b');
        $this->locC = $this->insertLocation(self::CLINIC_C, 'fin-loc-c');

        $this->adminUserId = $this->makeUser('fcs_admin', 'administrator');
        $this->secretaryB = $this->makeUser('fcs_secretary_b', 'cpms_secretary');
        $this->secretaryC = $this->makeUser('fcs_secretary_c', 'cpms_secretary');
        cpms_test_seed_membership($this->adminUserId, self::CLINIC_B, 'cpms_manager');
        cpms_test_seed_membership($this->adminUserId, self::CLINIC_C, 'cpms_manager');
        cpms_test_seed_membership($this->secretaryB, self::CLINIC_B, 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryC, self::CLINIC_C, 'cpms_secretary');

        // نام و MRN عمداً متمایز و قابل تشخیص هستند تا نشتِ PHI قابل اثبات باشد.
        $this->patientB = $this->seedPatient(self::CLINIC_B, 'Alpha', 'BetaClinic', 'MRN-B-SECRET-771');
        $this->patientC = $this->seedPatient(self::CLINIC_C, 'Gamma', 'DeltaClinic', 'MRN-C-SECRET-882');

        $clinicianB = $this->insertClinician(self::CLINIC_B, 'Dr B');
        $clinicianC = $this->insertClinician(self::CLINIC_C, 'Dr C');
        $this->visitB = $this->seedAwaitingPaymentVisit(self::CLINIC_B, $this->locB, $clinicianB, $this->patientB);
        $this->visitC = $this->seedAwaitingPaymentVisit(self::CLINIC_C, $this->locC, $clinicianC, $this->patientC);

        $this->bindScope(self::CLINIC_B);
    }

    protected function tearDown(): void
    {
        $this->stopLockCapture();
        ScopeContext::clear();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();
        $this->purgeReserveRows();

        parent::tearDown();
    }

    // ================= ۱ — Clinic عملیاتی با شناسهٔ غیر از ۱ =================

    /**
     * کل مسیر مالی (تعرفه → فاکتور → پرداخت → خلاصه) روی Clinic که شناسه‌اش
     * ۱ نیست کار می‌کند. پیش از corrective این مسیر به Clinic 1 گره خورده بود.
     */
    public function testNonOneClinicFinanceWorksEndToEnd(): void
    {
        $this->bindScope(self::CLINIC_B);
        $serviceId = $this->makeService('B-VISIT', 'ویزیت B', 400000);

        $invoice = $this->finance()->issueInvoice($this->secretaryB, [
            'visit_id' => $this->visitB,
            'items' => [['service_id' => $serviceId, 'quantity' => 1]],
        ]);
        self::assertGreaterThan(0, (int) $invoice['id']);

        $row = $this->invoiceRow((int) $invoice['id']);
        self::assertSame(self::CLINIC_B, (int) $row['clinic_id'], 'فاکتور باید در همان Clinic ثبت شود');
        self::assertSame($this->patientB, (int) $row['patient_id']);

        $payment = $this->finance()->recordPayment(
            $this->secretaryB,
            (int) $invoice['id'],
            ['amount' => 150000, 'method' => 'cash'],
            $this->uuid()
        );
        self::assertGreaterThan(0, (int) $payment['payment_id']);

        $summary = $this->finance()->summary($this->secretaryB, gmdate('Y-m-d'), gmdate('Y-m-d'));
        self::assertSame(150000, $summary['revenue']['total']);
        self::assertSame(1, $summary['revenue']['payment_count']);
        self::assertSame(250000, $summary['open_balances']['total']);
    }

    // ================= ۲ — جداسازی تعرفه/خدمات =================

    public function testServiceListIsClinicScoped(): void
    {
        $this->bindScope(self::CLINIC_B);
        $bId = $this->makeService('SVC-B-1', 'خدمت B', 100000);

        $this->bindScope(self::CLINIC_C);
        $cId = $this->makeService('SVC-C-1', 'خدمت C', 200000);

        $this->bindScope(self::CLINIC_B);
        $bCodes = $this->serviceCodes($this->finance()->listServices($this->secretaryB, true));
        self::assertContains('SVC-B-1', $bCodes);
        self::assertNotContains('SVC-C-1', $bCodes, 'Clinic B نباید تعرفهٔ Clinic C را ببیند');

        $this->bindScope(self::CLINIC_C);
        $cCodes = $this->serviceCodes($this->finance()->listServices($this->secretaryC, true));
        self::assertContains('SVC-C-1', $cCodes);
        self::assertNotContains('SVC-B-1', $cCodes, 'Clinic C نباید تعرفهٔ Clinic B را ببیند');
        self::assertNotContains($bId, $this->serviceIds($this->finance()->listServices($this->secretaryC, true)));
        self::assertGreaterThan(0, $cId);
    }

    // ================= ۳ — جداسازی فاکتورهای باز + نام/MRN بیمار =================

    public function testOpenInvoicesAndPatientIdentityNeverCrossClinic(): void
    {
        $this->issueInBothClinics();

        $this->bindScope(self::CLINIC_B);
        $b = $this->finance()->summary($this->secretaryB, gmdate('Y-m-d'), gmdate('Y-m-d'));
        $bInvoices = $b['open_balances']['invoices'];
        self::assertCount(1, $bInvoices);
        self::assertSame('MRN-B-SECRET-771', $bInvoices[0]['mrn']);
        self::assertSame('Alpha BetaClinic', $bInvoices[0]['patient_name']);
        self::assertStringNotContainsString('MRN-C-SECRET-882', json_encode($b, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertStringNotContainsString('DeltaClinic', json_encode($b, JSON_UNESCAPED_UNICODE) ?: '');

        $this->bindScope(self::CLINIC_C);
        $c = $this->finance()->summary($this->secretaryC, gmdate('Y-m-d'), gmdate('Y-m-d'));
        $cInvoices = $c['open_balances']['invoices'];
        self::assertCount(1, $cInvoices);
        self::assertSame('MRN-C-SECRET-882', $cInvoices[0]['mrn']);
        self::assertSame('Gamma DeltaClinic', $cInvoices[0]['patient_name']);
        self::assertStringNotContainsString('MRN-B-SECRET-771', json_encode($c, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertStringNotContainsString('BetaClinic', json_encode($c, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** هیچ شناسهٔ فاکتور Clinic B در پاسخ Clinic C ظاهر نمی‌شود. */
    public function testClinicBCannotReceiveClinicAInvoiceOrPaymentRows(): void
    {
        $invoiceB = $this->issueInBothClinics()['B'];

        $this->bindScope(self::CLINIC_C);
        $c = $this->finance()->summary($this->secretaryC, gmdate('Y-m-d'), gmdate('Y-m-d'));
        $cIds = array_map(static fn (array $i): int => (int) $i['id'], $c['open_balances']['invoices']);
        self::assertNotContains((int) $invoiceB['id'], $cIds);
        self::assertSame([], $c['payments'], 'Clinic C نباید پرداختی از Clinic B دریافت کند');
    }

    // ================= ۴ — جداسازی خلاصهٔ درآمد/پرداخت =================

    public function testRevenueAndPaymentRangeAreClinicScoped(): void
    {
        $invoiceB = $this->issueInBothClinics()['B'];
        $this->bindScope(self::CLINIC_B);
        $this->finance()->recordPayment($this->secretaryB, (int) $invoiceB['id'], ['amount' => 120000, 'method' => 'card_pos'], $this->uuid());

        $this->bindScope(self::CLINIC_C);
        $c = $this->finance()->summary($this->secretaryC, gmdate('Y-m-d'), gmdate('Y-m-d'));
        self::assertSame(0, $c['revenue']['total'], 'درآمد Clinic B نباید در خلاصهٔ Clinic C بنشیند');
        self::assertSame(0, $c['revenue']['payment_count']);
        self::assertSame(0.0, (float) $c['revenue']['by_method']['card_pos']);

        $this->bindScope(self::CLINIC_B);
        $b = $this->finance()->summary($this->secretaryB, gmdate('Y-m-d'), gmdate('Y-m-d'));
        self::assertSame(120000, $b['revenue']['total']);
        self::assertSame(120000, $b['revenue']['by_method']['card_pos']);
        self::assertCount(1, $b['payments']);
    }

    // ================= ۵ — عددگیری مستقل فاکتور و پرداخت =================

    public function testInvoiceNumberingIsIndependentPerClinic(): void
    {
        $this->issueInBothClinics();

        $this->bindScope(self::CLINIC_B);
        $secondB = $this->finance()->issueInvoice($this->secretaryB, [
            'visit_id' => $this->seedAwaitingPaymentVisit(self::CLINIC_B, $this->locB, $this->clinicianOf(self::CLINIC_B), $this->patientB),
            'items' => [['description' => 'قلم دوم B', 'unit_price' => 1000]],
        ]);

        $prefix = 'INV-' . gmdate('ymd') . '-';
        self::assertSame($prefix . '002', $secondB['invoice_number'], 'دومین فاکتور Clinic B');

        $this->bindScope(self::CLINIC_C);
        $secondC = $this->finance()->issueInvoice($this->secretaryC, [
            'visit_id' => $this->seedAwaitingPaymentVisit(self::CLINIC_C, $this->locC, $this->clinicianOf(self::CLINIC_C), $this->patientC),
            'items' => [['description' => 'قلم دوم C', 'unit_price' => 1000]],
        ]);
        self::assertSame($prefix . '002', $secondC['invoice_number'], 'شمارهٔ Clinic C از Clinic B اثر نمی‌گیرد');
    }

    public function testPaymentNumberingIsIndependentPerClinic(): void
    {
        $issued = $this->issueInBothClinics();
        $prefix = 'PAY-' . gmdate('ymd') . '-';

        $this->bindScope(self::CLINIC_B);
        $payB = $this->finance()->recordPayment($this->secretaryB, (int) $issued['B']['id'], ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        $this->bindScope(self::CLINIC_C);
        $payC = $this->finance()->recordPayment($this->secretaryC, (int) $issued['C']['id'], ['amount' => 1000, 'method' => 'cash'], $this->uuid());

        self::assertSame($prefix . '0001', $payB['payment_number']);
        self::assertSame($prefix . '0001', $payC['payment_number'], 'هر Clinic شمارندهٔ خودش را دارد');

        $this->bindScope(self::CLINIC_B);
        $secondB = $this->finance()->recordPayment($this->secretaryB, (int) $issued['B']['id'], ['amount' => 1000, 'method' => 'cash'], $this->uuid());
        self::assertSame($prefix . '0002', $secondB['payment_number']);
    }

    // ================= ۶ — قفل ردیف روی Clinic درست =================

    /**
     * قفل سریال‌سازی عددگیری باید روی ردیفِ همان Clinic گرفته شود. پیش از
     * corrective ردیف Clinic 1 قفل می‌شد: در نصب چند‌کلینیکی Clinicهای دیگر
     * هیچ سریال‌سازی‌ای نداشتند (و همه روی یک ردیف بی‌ربط صف می‌کشیدند).
     */
    public function testNumberingLockTargetsTheOperatingClinic(): void
    {
        $this->startLockCapture();
        try {
            $this->bindScope(self::CLINIC_B);
            $this->finance()->issueInvoice($this->secretaryB, [
                'visit_id' => $this->visitB,
                'items' => [['description' => 'ویزیت', 'unit_price' => 1000]],
            ]);
            $this->bindScope(self::CLINIC_C);
            $this->finance()->issueInvoice($this->secretaryC, [
                'visit_id' => $this->visitC,
                'items' => [['description' => 'ویزیت', 'unit_price' => 1000]],
            ]);
        } finally {
            $this->stopLockCapture();
        }

        self::assertContains((string) self::CLINIC_B, $this->lockQueries, 'قفل باید روی ردیف Clinic B گرفته شود');
        self::assertContains((string) self::CLINIC_C, $this->lockQueries, 'قفل باید روی ردیف Clinic C گرفته شود');
        self::assertNotContains('1', $this->lockQueries, 'هیچ قفل عددگیری نباید روی Clinic 1 گرفته شود');
    }

    // ================= ۷ — سازگاری Clinic 1 =================

    /**
     * Clinic 1 هیچ رفتار ویژه‌ای ندارد و نباید هم داشته باشد — اما چون نصب
     * پیش‌فرض تک‌کلینیکی روی همان شناسه کار می‌کند، سازگاریش صریح سنجیده
     * می‌شود (نه به‌شکل fallback پنهان).
     */
    public function testDefaultClinicOneRemainsFullyCompatible(): void
    {
        $locationId = (int) $this->primaryLocationOf(self::DEFAULT_CLINIC);
        self::assertGreaterThan(0, $locationId, 'پیش‌شرط: نصب پیش‌فرض یک Location اولیه برای Clinic 1 دارد');

        $secretary = $this->makeUser('fcs_secretary_default', 'cpms_secretary');
        cpms_test_seed_membership($secretary, self::DEFAULT_CLINIC, 'cpms_secretary');

        $this->bindScope(self::DEFAULT_CLINIC);
        $patientId = $this->seedPatient(self::DEFAULT_CLINIC, 'Default', 'Patient', 'MRN-DEF-' . random_int(10000, 99999));
        $clinicianId = $this->insertClinician(self::DEFAULT_CLINIC, 'Dr Default');
        $visitId = $this->seedAwaitingPaymentVisit(self::DEFAULT_CLINIC, $locationId, $clinicianId, $patientId);

        $invoice = $this->finance()->issueInvoice($secretary, [
            'visit_id' => $visitId,
            'items' => [['description' => 'ویزیت پیش‌فرض', 'unit_price' => 300000]],
        ]);
        self::assertMatchesRegularExpression('/^INV-\d{6}-\d{3,}$/', $invoice['invoice_number']);

        // جزئی — تا مسیر تست روی مالی بماند (Transition تسویهٔ ویزیت در
        // FinanceFlowTest پوشش دارد).
        $payment = $this->finance()->recordPayment($secretary, (int) $invoice['id'], ['amount' => 100000, 'method' => 'cash'], $this->uuid());
        self::assertMatchesRegularExpression('/^PAY-\d{6}-\d{4,}$/', $payment['payment_number']);

        $summary = $this->finance()->summary($secretary, gmdate('Y-m-d'), gmdate('Y-m-d'));
        self::assertSame(100000, $summary['revenue']['total']);
        self::assertSame(200000, $summary['open_balances']['total']);
        self::assertSame($this->mrnOf($patientId), $summary['open_balances']['invoices'][0]['mrn']);
    }

    // ================= ۸ — شکست درج: بدون شناسهٔ stale/صفر =================

    /**
     * نقص ثانویهٔ گزارش‌شده (بازتولید با MySQL واقعی، بدون mock):
     * `wpdb::insert` در خطا `false` می‌دهد ولی `insert_id` را پاک نمی‌کند، پس
     * مقدارِ insert موفقِ قبلی سرِ جایش می‌ماند. پیش از corrective،
     * `InvoiceRepository::insert` آن شناسهٔ stale را برمی‌گرداند، اقلام فاکتور
     * به فاکتور دیگری چسبانده می‌شد و تراکنش commit می‌شد.
     *
     * تزریق خطا: `MAX(invoice_number)` با یک شمارهٔ خارج‌الگو مسموم می‌شود تا
     * عددگیر `001` بسازد، و همان `001` از قبل اشغال است ⇒ نقض
     * `u_inv_number (clinic_id, invoice_number)`.
     */
    public function testInvoiceInsertFailureStopsAndNeverAttachesItemsToStaleParent(): void
    {
        $this->bindScope(self::CLINIC_B);
        $today = gmdate('ymd');

        // شمارهٔ خارج‌الگو ⇒ MAX الگوی INV نمی‌دهد ⇒ seq=0 ⇒ بعدی = INV-…-001
        $this->insertRawInvoice(self::CLINIC_B, $this->patientB, $this->visitB, 'ZZZ-POISON');
        // و همان شمارهٔ بعدی از قبل اشغال می‌شود ⇒ درج فاکتور تازه شکست می‌خورد.
        // این همان ردیفی است که کد قدیمی با شناسهٔ stale به آن قلم می‌چسباند.
        $occupiedId = $this->insertRawInvoice(self::CLINIC_B, $this->patientB, $this->visitB, 'INV-' . $today . '-001');

        $itemsBefore = $this->countInvoiceItems(self::CLINIC_B);
        $visitForAttempt = $this->seedAwaitingPaymentVisit(
            self::CLINIC_B,
            $this->locB,
            $this->clinicianOf(self::CLINIC_B),
            $this->patientB
        );

        $thrown = null;
        try {
            $this->finance()->issueInvoice($this->secretaryB, [
                'visit_id' => $visitForAttempt,
                'items' => [['description' => 'قلمی که هرگز نباید بنشیند', 'unit_price' => 5000]],
            ]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(
            \RuntimeException::class,
            $thrown,
            'شکست درج فاکتور باید عملیات را متوقف کند (نه ادامه با شناسهٔ stale)'
        );
        self::assertStringContainsString('cpms_invoices insert failed', (string) $thrown?->getMessage());

        self::assertSame(
            $itemsBefore,
            $this->countInvoiceItems(self::CLINIC_B),
            'هیچ قلمی نباید به فاکتورِ والدِ stale/بیگانه چسبانده شود'
        );
        self::assertSame(
            0,
            $this->countInvoiceItemsOf($occupiedId),
            'فاکتور از پیش موجود نباید قلمِ فاکتور شکست‌خورده را بگیرد'
        );
        self::assertNull(
            $this->invoiceForVisit($visitForAttempt),
            'تراکنش نباید فاکتور نامعتبر را commit کند'
        );
    }

    /**
     * همان نقص در مسیر پرداخت. پیش از corrective گارد `!$ok || $paymentId <= 0`
     * عملاً فقط «صفر» را می‌گرفت (چون `$ok` همان insert_id بود) و یک شناسهٔ
     * stale غیرصفر می‌توانست به‌عنوان پرداخت تازه commit شود.
     */
    public function testPaymentInsertFailureRollsBackInsteadOfCommittingStaleId(): void
    {
        $this->bindScope(self::CLINIC_B);
        $invoice = $this->finance()->issueInvoice($this->secretaryB, [
            'visit_id' => $this->visitB,
            'items' => [['description' => 'ویزیت', 'unit_price' => 500000]],
        ]);
        $invoiceId = (int) $invoice['id'];
        $today = gmdate('ymd');

        // MAX خارج‌الگو ⇒ عددگیر PAY-…-0001 می‌سازد؛ همان شماره اشغال می‌شود.
        $other = $this->seedAwaitingPaymentVisit(self::CLINIC_B, $this->locB, $this->clinicianOf(self::CLINIC_B), $this->patientB);
        $otherInvoice = $this->insertRawInvoice(self::CLINIC_B, $this->patientB, $other, 'INV-' . $today . '-090');
        $this->insertRawPayment(self::CLINIC_B, $otherInvoice, $this->patientB, 'ZZZ-POISON');
        $this->insertRawPayment(self::CLINIC_B, $otherInvoice, $this->patientB, 'PAY-' . $today . '-0001');

        $paymentsBefore = $this->countPayments(self::CLINIC_B);

        $thrown = null;
        try {
            $this->finance()->recordPayment(
                $this->secretaryB,
                $invoiceId,
                ['amount' => 100000, 'method' => 'cash'],
                $this->uuid()
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(FinanceException::class, $thrown, 'شکست درج پرداخت باید خطا بدهد');
        self::assertSame(500, $thrown instanceof FinanceException ? $thrown->httpStatus : 0);
        self::assertSame(
            $paymentsBefore,
            $this->countPayments(self::CLINIC_B),
            'پرداخت شکست‌خورده نباید ثبت شود'
        );
        self::assertSame(
            0,
            (int) ($this->invoiceRow($invoiceId)['paid_amount'] ?? -1),
            'اثر پرداخت نباید روی فاکتور commit شود (ROLLBACK)'
        );
    }

    // ================= ۹ — قرارداد صریح Clinic در Repository/Service =================

    /**
     * قرارداد باید «Clinic الزامی» را بیان کند: بدون آرگومان Clinic و بدون
     * مقدار پیش‌فرض. این تست جلوی بازگشت امضای قدیمی را می‌گیرد.
     */
    public function testRepositoryContractsRequireExplicitClinicScope(): void
    {
        $cases = [
            [\ClinicCore\Infrastructure\Repository\ServiceRepository::class, 'all'],
            [\ClinicCore\Infrastructure\Repository\InvoiceRepository::class, 'openInvoices'],
            [\ClinicCore\Infrastructure\Repository\InvoiceRepository::class, 'nextInvoiceNumber'],
            [\ClinicCore\Infrastructure\Repository\PaymentRepository::class, 'revenueSummary'],
            [\ClinicCore\Infrastructure\Repository\PaymentRepository::class, 'forRange'],
            [\ClinicCore\Infrastructure\Repository\PaymentRepository::class, 'nextPaymentNumber'],
        ];

        foreach ($cases as [$class, $method]) {
            $first = (new \ReflectionMethod($class, $method))->getParameters()[0] ?? null;
            self::assertNotNull($first, "{$class}::{$method} باید پارامتر Clinic داشته باشد");
            self::assertSame(
                'clinic_id',
                (string) $first?->getName(),
                "{$class}::{$method} — اولین پارامتر باید clinic_id باشد"
            );
            self::assertFalse(
                (bool) $first?->isDefaultValueAvailable(),
                "{$class}::{$method} — Clinic نباید مقدار پیش‌فرض داشته باشد"
            );
        }

        // lockClinic خصوصی است ولی همان قرارداد را دارد.
        $lock = new \ReflectionMethod(FinanceService::class, 'lockClinic');
        $params = $lock->getParameters();
        self::assertCount(1, $params, 'lockClinic باید Clinic را صریح بگیرد');
        self::assertFalse($params[0]->isDefaultValueAvailable(), 'lockClinic نباید مقدار پیش‌فرض داشته باشد');
    }

    // ================= Helpers =================

    private function finance(): FinanceService
    {
        return App::financeService();
    }

    private function bindScope(int $clinicId): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic($clinicId));
    }

    private function uuid(): string
    {
        $d = static fn (int $len): string => bin2hex(random_bytes((int) ceil($len / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $d(8), $d(4), substr($d(3), 0, 3), substr($d(4), 0, 4), $d(12));
    }

    /** یک فاکتور باز در هر دو Clinic. @return array{B: array<string, mixed>, C: array<string, mixed>} */
    private function issueInBothClinics(): array
    {
        $this->bindScope(self::CLINIC_B);
        $b = $this->finance()->issueInvoice($this->secretaryB, [
            'visit_id' => $this->visitB,
            'items' => [['description' => 'ویزیت B', 'unit_price' => 300000]],
        ]);

        $this->bindScope(self::CLINIC_C);
        $c = $this->finance()->issueInvoice($this->secretaryC, [
            'visit_id' => $this->visitC,
            'items' => [['description' => 'ویزیت C', 'unit_price' => 400000]],
        ]);

        return ['B' => $b, 'C' => $c];
    }

    private function makeService(string $code, string $name, int $price): int
    {
        return (int) $this->finance()->createService($this->adminUserId, [
            'code' => $code,
            'name' => $name,
            'price' => $price,
        ])['id'];
    }

    /** @param list<array<string, mixed>> $services @return list<string> */
    private function serviceCodes(array $services): array
    {
        return array_map(static fn (array $s): string => (string) $s['code'], $services);
    }

    /** @param list<array<string, mixed>> $services @return list<int> */
    private function serviceIds(array $services): array
    {
        return array_map(static fn (array $s): int => (int) $s['id'], $services);
    }

    /** @return array<string, mixed> */
    private function invoiceRow(int $invoiceId): array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'cpms_invoices WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $invoiceId
            ),
            ARRAY_A
        );
        self::assertIsArray($row, 'پیش‌شرط: ردیف فاکتور وجود دارد');

        return (array) $row;
    }

    /** @return array<string, mixed>|null */
    private function invoiceForVisit(int $visitId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . "cpms_invoices WHERE visit_id = %d AND status != 'voided' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $visitId
            ),
            ARRAY_A
        );

        return $row === null ? null : (array) $row;
    }

    private function countInvoiceItems(int $clinicId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_invoice_items it
                   JOIN ' . $wpdb->prefix . 'cpms_invoices i ON i.id = it.invoice_id
                  WHERE i.clinic_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
    }

    private function countInvoiceItemsOf(int $invoiceId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_invoice_items WHERE invoice_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $invoiceId
            )
        );
    }

    private function countPayments(int $clinicId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_payments WHERE clinic_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
    }

    private function mrnOf(int $patientId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT mrn FROM ' . $wpdb->prefix . 'cpms_patients WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $patientId
            )
        );
    }

    private function insertRawInvoice(int $clinicId, int $patientId, int $visitId, string $number): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_invoices
                     (clinic_id, invoice_number, patient_id, visit_id, status, subtotal, discount, tax, total,
                      currency, paid_amount, balance, issued_by_wp_user_id, created_at, updated_at)
                 VALUES (%d, %s, %d, %d, "open", 0, 0, 0, 0, "IRR", 0, 0, %d, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $number,
                $patientId,
                $visitId,
                $this->secretaryB,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج فاکتور خام ' . $number);

        return $id;
    }

    private function insertRawPayment(int $clinicId, int $invoiceId, int $patientId, string $number): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_payments
                     (clinic_id, payment_number, invoice_id, patient_id, amount, method, idempotency_key,
                      status, refunded_amount, paid_at, received_by_wp_user_id, created_at)
                 VALUES (%d, %s, %d, %d, 0, "cash", %s, "captured", 0, %s, %d, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $number,
                $invoiceId,
                $patientId,
                'raw-' . $number . '-' . bin2hex(random_bytes(4)),
                $now,
                $this->secretaryB,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج پرداخت خام ' . $number);

        return $id;
    }

    private function clinicianOf(int $clinicId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE clinic_id = %d ORDER BY id ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
    }

    private function primaryLocationOf(int $clinicId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d ORDER BY is_primary DESC, id ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
    }

    /** گرفتن کوئری‌های `SELECT ... FOR UPDATE` روی ردیف Clinic (بدون تغییر رفتار). */
    private function startLockCapture(): void
    {
        $this->lockQueries = [];
        add_filter('query', [$this, 'captureClinicLock'], 5, 1);
    }

    private function stopLockCapture(): void
    {
        remove_filter('query', [$this, 'captureClinicLock'], 5);
    }

    /**
     * @param mixed $query
     * @return mixed
     */
    public function captureClinicLock($query)
    {
        $sql = (string) $query;
        if (preg_match('/cpms_clinics\s+WHERE\s+id\s*=\s*(\d+)\s+LIMIT\s+1\s+FOR UPDATE/i', $sql, $m) === 1) {
            $this->lockQueries[] = $m[1];
        }

        return $query;
    }

    private function defaultOrganization(): int
    {
        global $wpdb;
        $orgId = (int) $wpdb->get_var(
            'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertGreaterThan(0, $orgId, 'پیش‌شرط: Organization از ردیف seed شدهٔ نصب خوانده شود');

        return $orgId;
    }

    private function insertClinic(int $id, int $orgId, string $slug): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
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
        self::assertNotEquals(1, $id, '⚑ Clinicهای عملیاتی این تست نباید ۱ باشند');
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
                $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج Location');

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $seed = bin2hex(random_bytes(4));
        $created = wp_create_user($login . $seed, 'pass-12345', $login . $seed . '@test.local');
        if ($created instanceof \WP_Error) {
            self::fail('پیش‌شرط: ساخت کاربر ناموفق: ' . $created->get_error_message());
        }
        $userId = (int) $created;
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }
        self::assertGreaterThan(0, $userId, 'پیش‌شرط: ساخت کاربر');

        return $userId;
    }

    private function seedPatient(int $clinicId, string $firstName, string $lastName, string $mrn): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(100000, 9999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $mrn,
                $firstName,
                $lastName,
                '0990' . sprintf('%07d', $seq % 10000000),
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج بیمار');

        return $id;
    }

    private function insertClinician(int $clinicId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        // u_clinician_user: UNIQUE روی wp_user_id ⇒ هر ردیف کاربر factory خودش را می‌گیرد.
        $linkedUserId = (int) self::factory()->user->create(['role' => 'subscriber']);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $name,
                $linkedUserId,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج Clinician');

        return $id;
    }

    /**
     * ویزیت در وضعیت `awaiting_payment` — یعنی فاکتورپذیر (I1) بدون نیاز به
     * اجرای کامل ماشین وضعیت. Transition سیستمیِ V11 عمداً دور زده می‌شود تا
     * تست روی مسیر مالی بماند، نه روی ماشین وضعیت ویزیت.
     */
    private function seedAwaitingPaymentVisit(int $clinicId, int $locationId, int $clinicianId, int $patientId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $date = gmdate('Y-m-d');
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                     (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date,
                      check_in_at, waiting_since, called_at, active, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, "walk_in", "awaiting_payment", %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $locationId,
                $clinicianId,
                $patientId,
                $date,
                $date . ' 10:00:00.000',
                $date . ' 10:00:00.000',
                $date . ' 10:05:00.000',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج Visit');

        return $id;
    }

    /**
     * پاک‌سازی ردیف‌های fixture با شناسهٔ رزرو‌شده ≥ 62000. فقط hygiene تست است
     * (نه سست‌کردن ادعا): اگر rollback تراکنش suite مختل شود، «تعداد Clinic ≠ ۱»
     * به کلاس‌های بعدی سرایت نمی‌کند.
     */
    private function purgeReserveRows(): void
    {
        global $wpdb;
        $inv = 'SELECT id FROM ' . $wpdb->prefix . 'cpms_invoices WHERE clinic_id >= 62000';
        $steps = [
            'cpms_payment_adjustments' => 'WHERE invoice_id IN (' . $inv . ')',
            'cpms_payments' => 'WHERE clinic_id >= 62000',
            'cpms_invoice_items' => 'WHERE invoice_id IN (' . $inv . ')',
            'cpms_invoices' => 'WHERE clinic_id >= 62000',
            'cpms_services' => 'WHERE clinic_id >= 62000',
            'cpms_visit_status_history' => 'WHERE visit_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_visits WHERE clinic_id >= 62000)',
            'cpms_visits' => 'WHERE clinic_id >= 62000',
            'cpms_patients' => 'WHERE clinic_id >= 62000',
            'cpms_clinicians' => 'WHERE clinic_id >= 62000',
            'cpms_locations' => 'WHERE clinic_id >= 62000',
            'cpms_membership_capabilities' => 'WHERE membership_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE clinic_id >= 62000)',
            'cpms_membership_locations' => 'WHERE membership_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE clinic_id >= 62000)',
            'cpms_clinic_memberships' => 'WHERE clinic_id >= 62000',
            'cpms_settings' => 'WHERE clinic_id >= 62000',
            'cpms_notifications' => 'WHERE clinic_id >= 62000',
            'cpms_idempotency_keys' => 'WHERE clinic_id >= 62000',
            'cpms_clinics' => 'WHERE id >= 62000',
        ];
        $wpdb->query(
            'DELETE FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE clinic_id >= 62000' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ($steps as $table => $clause) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $table . ' ' . $clause); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}
