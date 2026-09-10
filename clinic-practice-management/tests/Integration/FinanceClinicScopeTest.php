<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\InvoiceRepository;
use ClinicCore\Infrastructure\Repository\PaymentRepository;
use ClinicCore\Infrastructure\Repository\ServiceRepository;
use WP_UnitTestCase;

/**
 * C6 post-closure corrective — finance با Clinic صریح (نه پیش‌فرض Clinic 1).
 *
 * این کلاس هفت مسیر runtime را می‌پوشاند که پس از بستن رسمی C6 کشف شدند:
 * در هر هفت مورد، `clinic_id` یک placeholder درست (`%d`) بود ولی **literal 1**
 * به‌صورت جداگانه bind می‌شد؛ بنابراین Tenant Tripwire (خط‌محور) آن‌ها را
 * نمی‌دید و اسکن تولید «CLEAN» گزارش می‌کرد.
 *
 *  - ServiceRepository::all()
 *  - PaymentRepository::revenueSummary() / forRange() / nextPaymentNumber()
 *  - InvoiceRepository::openInvoices() / nextInvoiceNumber()
 *  - FinanceService::lockClinic()
 *
 * به‌علاوه نقص ثانویهٔ insert-failure (شناسهٔ والد stale/صفر) و سازگاری
 * Clinic 1. همه‌جا رفتار واقعی روی MySQL واقعی سنجیده می‌شود، نه mock.
 *
 * Clinic A = 1 (ردیف seed شدهٔ نصب) و Clinic B = 2 (ساختهٔ تست) — پس همزمان
 * «Clinic غیر ۱» و «سازگاری Clinic 1» پوشش داده می‌شود.
 */
final class FinanceClinicScopeTest extends WP_UnitTestCase
{
    private const CLINIC_A = 1;

    private const CLINIC_B = 2;

    private int $locA;

    private int $locB;

    private int $operatorUserId;

    private int $clinicianId;

    private int $patientA;

    private int $patientB;

    private int $visitA;

    private int $visitB;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        $this->today = gmdate('Y-m-d');
        $this->locA = $this->primaryLocation(self::CLINIC_A);
        $this->insertClinic(self::CLINIC_B, 'fin-scope-b');
        $this->locB = $this->insertLocation(self::CLINIC_B, 'fin-scope-b-loc');

        /*
         * یک اپراتور با همهٔ Capabilityهای مالی مورد نیاز این تست. نقش سراسری WP
         * رابطهٔ tenant را تعریف نمی‌کند، پس عضویت هر دو Clinic صریح ساخته می‌شود.
         */
        $this->operatorUserId = $this->makeUser('fin_scope_op', 'cpms_secretary');
        $operator = get_userdata($this->operatorUserId);
        $operator?->add_cap('cpms_config');
        $membership = App::membership_service();
        $membership->create_membership(self::CLINIC_A, $this->operatorUserId, 'cpms_secretary');
        $membership->create_membership(self::CLINIC_B, $this->operatorUserId, 'cpms_secretary');

        // u_clinician_user — یک WP User حداکثر یک Clinician Profile دارد؛
        // clinic_id روی آن ردیف «home clinic» است نه مرز مجوز.
        $this->clinicianId = $this->insertClinician(self::CLINIC_A, $this->operatorUserId, 'Dr Scope');

        $this->patientA = $this->insertPatient(self::CLINIC_A, 'ScopeA', 'MRN-A-SCOPE');
        $this->patientB = $this->insertPatient(self::CLINIC_B, 'ScopeB', 'MRN-B-SCOPE');

        $this->visitA = $this->insertVisit(self::CLINIC_A, $this->locA, $this->patientA);
        $this->visitB = $this->insertVisit(self::CLINIC_B, $this->locB, $this->patientB);

        $this->bindScope(self::CLINIC_A);
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    // ================= ۱ — Clinic با شناسهٔ != 1端到端 کار می‌کند =================

    public function testNonOneClinicFinanceWorksEndToEnd(): void
    {
        $this->bindScope(self::CLINIC_B);

        $serviceId = $this->makeService('B-VISIT', 'ویزیت B', 400000);
        $invoice = $this->finance()->issueInvoice($this->operatorUserId, [
            'visit_id' => $this->visitB,
            'items' => [['service_id' => $serviceId, 'quantity' => 1]],
        ]);

        $this->assertSame(400000.0, $invoice['total']);
        $this->assertSame('open', $invoice['status']);
        $this->assertMatchesRegularExpression('/^INV-\d{6}-\d{3,}$/', $invoice['invoice_number']);

        $invoiceRow = $this->invoiceRow((int) $invoice['id']);
        $this->assertSame(self::CLINIC_B, (int) $invoiceRow['clinic_id'], 'فاکتور باید روی Clinic B بنشیند');

        $payment = $this->finance()->recordPayment(
            $this->operatorUserId,
            (int) $invoice['id'],
            ['amount' => 400000, 'method' => 'cash'],
            $this->uuid()
        );
        $this->assertGreaterThan(0, (int) $payment['payment_id']);
        $this->assertSame(
            self::CLINIC_B,
            (int) $this->paymentRow((int) $payment['payment_id'])['clinic_id'],
            'پرداخت باید روی Clinic B بنشیند'
        );

        $summary = $this->finance()->summary($this->operatorUserId, $this->today, $this->today);
        $this->assertSame(400000, $summary['revenue']['total']);
        $this->assertSame(1, $summary['revenue']['payment_count']);
    }

    // ================= ۲ — فهرست تعرفه Clinic-scoped =================

    public function testServiceListIsClinicScoped(): void
    {
        $this->bindScope(self::CLINIC_A);
        $this->makeService('A-TARIFF', 'تعرفه A', 100000);
        $this->bindScope(self::CLINIC_B);
        $this->makeService('B-TARIFF', 'تعرفه B', 200000);

        $this->bindScope(self::CLINIC_A);
        $codesA = $this->serviceCodes();
        $this->assertContains('A-TARIFF', $codesA);
        $this->assertNotContains('B-TARIFF', $codesA, 'تعرفهٔ Clinic B نباید به Clinic A نشت کند');

        $this->bindScope(self::CLINIC_B);
        $codesB = $this->serviceCodes();
        $this->assertContains('B-TARIFF', $codesB);
        $this->assertNotContains('A-TARIFF', $codesB, 'تعرفهٔ Clinic A نباید به Clinic B نشت کند');
    }

    // ================= ۳ — فاکتور باز + هویت بیمار هرگز cross-Clinic نمی‌شود =================

    public function testOpenInvoicesAndPatientIdentityNeverCrossClinic(): void
    {
        $this->bindScope(self::CLINIC_A);
        $this->makeInvoiceForVisit(self::CLINIC_A, $this->visitA, 111000);
        $this->bindScope(self::CLINIC_B);
        $this->makeInvoiceForVisit(self::CLINIC_B, $this->visitB, 222000);

        $this->bindScope(self::CLINIC_B);
        $summary = $this->finance()->summary($this->operatorUserId, $this->today, $this->today);

        $this->assertSame(222000, $summary['open_balances']['total']);
        $this->assertSame(1, $summary['open_balances']['invoice_count']);

        $encoded = (string) json_encode($summary);
        $this->assertStringContainsString('ScopeB', $encoded);
        $this->assertStringContainsString('MRN-B-SCOPE', $encoded);
        $this->assertStringNotContainsString('ScopeA', $encoded, 'نام بیمار Clinic A نشت کرد');
        $this->assertStringNotContainsString('MRN-A-SCOPE', $encoded, 'MRN بیمار Clinic A نشت کرد');
    }

    // ================= ۴ — Clinic B ردیف فاکتور/پرداخت Clinic A را نمی‌گیرد =================

    public function testClinicBCannotReceiveClinicAInvoiceOrPaymentRows(): void
    {
        $this->bindScope(self::CLINIC_A);
        $invoiceA = $this->makeInvoiceForVisit(self::CLINIC_A, $this->visitA, 111000);
        $this->finance()->recordPayment(
            $this->operatorUserId,
            (int) $invoiceA['id'],
            ['amount' => 111000, 'method' => 'cash'],
            $this->uuid()
        );
        $this->bindScope(self::CLINIC_B);
        $this->makeInvoiceForVisit(self::CLINIC_B, $this->visitB, 222000);

        /** @var InvoiceRepository $invoices */
        $invoices = new InvoiceRepository(App::db());
        $openB = $invoices->openInvoices(self::CLINIC_B, 100);
        $this->assertCount(1, $openB);
        $this->assertSame(self::CLINIC_B, (int) $openB[0]['clinic_id']);
        // insertPatient یک پسوند یکتاساز به MRN می‌چسباند (MRN-B-SCOPE-<seq>).
        $this->assertStringStartsWith('MRN-B-SCOPE-', (string) $openB[0]['patient_mrn']);

        /** @var PaymentRepository $payments */
        $payments = new PaymentRepository(App::db());
        $rangeB = $payments->forRange(self::CLINIC_B, $this->today, $this->today, 100);
        $this->assertCount(0, $rangeB, 'پرداخت Clinic A نباید در بازهٔ Clinic B ظاهر شود');

        $rangeA = $payments->forRange(self::CLINIC_A, $this->today, $this->today, 100);
        $this->assertCount(1, $rangeA);
        $this->assertSame(self::CLINIC_A, (int) $rangeA[0]['clinic_id']);
    }

    // ================= ۵ — خلاصه درآمد و بازه پرداخت Clinic-scoped =================

    public function testRevenueAndPaymentRangeAreClinicScoped(): void
    {
        $this->bindScope(self::CLINIC_A);
        $invoiceA = $this->makeInvoiceForVisit(self::CLINIC_A, $this->visitA, 111000);
        $this->finance()->recordPayment(
            $this->operatorUserId,
            (int) $invoiceA['id'],
            ['amount' => 111000, 'method' => 'cash'],
            $this->uuid()
        );
        $this->bindScope(self::CLINIC_B);
        $invoiceB = $this->makeInvoiceForVisit(self::CLINIC_B, $this->visitB, 222000);
        $this->finance()->recordPayment(
            $this->operatorUserId,
            (int) $invoiceB['id'],
            ['amount' => 50000, 'method' => 'card_pos'],
            $this->uuid()
        );

        $this->bindScope(self::CLINIC_A);
        $sumA = $this->finance()->summary($this->operatorUserId, $this->today, $this->today);
        $this->assertSame(111000, $sumA['revenue']['total']);
        $this->assertSame(1, $sumA['revenue']['payment_count']);
        $this->assertSame(111000, $sumA['revenue']['by_method']['cash']);
        $this->assertSame(0, $sumA['revenue']['by_method']['card_pos']);

        $this->bindScope(self::CLINIC_B);
        $sumB = $this->finance()->summary($this->operatorUserId, $this->today, $this->today);
        $this->assertSame(50000, $sumB['revenue']['total']);
        $this->assertSame(1, $sumB['revenue']['payment_count']);
        $this->assertSame(0, $sumB['revenue']['by_method']['cash']);
        $this->assertSame(50000, $sumB['revenue']['by_method']['card_pos']);
        $this->assertSame(172000, $sumB['open_balances']['total']);
    }

    // ================= ۶/۷ — شماره‌گذاری INV و PAY مستقل per Clinic =================

    public function testInvoiceNumberingIsIndependentPerClinic(): void
    {
        $this->bindScope(self::CLINIC_A);
        $a1 = $this->makeInvoiceForVisit(self::CLINIC_A, $this->visitA, 1000);
        $a2 = $this->makeInvoiceForVisit(
            self::CLINIC_A,
            $this->insertVisit(self::CLINIC_A, $this->locA, $this->patientA),
            2000
        );

        // Clinic B باید از شمارندهٔ خودش شروع کند، نه از ادامهٔ Clinic A.
        $this->bindScope(self::CLINIC_B);
        $b1 = $this->makeInvoiceForVisit(self::CLINIC_B, $this->visitB, 3000);

        $seqA1 = $this->invoiceSeq((string) $a1['invoice_number']);
        $seqA2 = $this->invoiceSeq((string) $a2['invoice_number']);
        $seqB1 = $this->invoiceSeq((string) $b1['invoice_number']);

        $this->assertSame('INV-' . gmdate('ymd') . '-', substr((string) $b1['invoice_number'], 0, 11));
        $this->assertSame($seqA1 + 1, $seqA2, 'شماره‌گذاری Clinic A باید پیاپی پیش برود');
        $this->assertSame(
            $seqA1,
            $seqB1,
            'Clinic B باید از شمارندهٔ خودش شروع کند — اگر از Clinic 1 ادامه می‌داد این‌جا seq بزرگ‌تر بود'
        );
        $this->assertSame(
            (string) $b1['invoice_number'],
            $this->maxInvoiceNumber(self::CLINIC_B),
            'بیشینهٔ شمارهٔ Clinic B فقط از ردیف‌های خودش می‌آید'
        );
    }

    public function testPaymentNumberingIsIndependentPerClinic(): void
    {
        $this->bindScope(self::CLINIC_A);
        $invoiceA = $this->makeInvoiceForVisit(self::CLINIC_A, $this->visitA, 100000);
        $payA = $this->finance()->recordPayment(
            $this->operatorUserId,
            (int) $invoiceA['id'],
            ['amount' => 100000, 'method' => 'cash'],
            $this->uuid()
        );

        $this->bindScope(self::CLINIC_B);
        $invoiceB = $this->makeInvoiceForVisit(self::CLINIC_B, $this->visitB, 200000);
        $payB = $this->finance()->recordPayment(
            $this->operatorUserId,
            (int) $invoiceB['id'],
            ['amount' => 200000, 'method' => 'cash'],
            $this->uuid()
        );

        $seqA = $this->paymentSeq((string) $payA['payment_number']);
        $seqB = $this->paymentSeq((string) $payB['payment_number']);

        $this->assertSame('PAY-' . gmdate('ymd') . '-', substr((string) $payB['payment_number'], 0, 11));
        $this->assertSame(
            $seqA,
            $seqB,
            'شماره‌گذاری پرداخت Clinic B باید مستقل از Clinic A باشد'
        );
    }

    // ================= ۸ — قفل، همان Clinicِ در حال number-taking را هدف می‌گیرد =================

    public function testNumberingLockTargetsTheOperatingClinic(): void
    {
        $captured = [];
        $capture = static function ($query) use (&$captured) {
            if (is_string($query)
                && stripos($query, 'cpms_clinics') !== false
                && stripos($query, 'FOR UPDATE') !== false) {
                $captured[] = $query;
            }

            return $query;
        };

        $this->bindScope(self::CLINIC_B);
        add_filter('query', $capture);
        try {
            $this->makeInvoiceForVisit(self::CLINIC_B, $this->visitB, 5000);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertNotEmpty($captured, 'هیچ قفل ردیف Clinic ثبت نشد');
        foreach ($captured as $sql) {
            $this->assertStringContainsString(
                'WHERE id = ' . self::CLINIC_B,
                $sql,
                'قفل باید روی ردیف همان Clinic باشد، نه Clinic 1'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/WHERE id = 1\b/',
                $sql,
                'قفل هرگز نباید روی ردیف Clinic 1 بنشیند وقتی Clinic فعال B است'
            );
        }
    }

    // ================= ۹ — سازگاری Clinic 1 =================

    public function testDefaultClinicOneRemainsFullyCompatible(): void
    {
        $this->bindScope(self::CLINIC_A);

        $serviceId = $this->makeService('A-VISIT', 'ویزیت A', 300000);
        $invoice = $this->finance()->issueInvoice($this->operatorUserId, [
            'visit_id' => $this->visitA,
            'items' => [['service_id' => $serviceId, 'quantity' => 1]],
        ]);
        $this->assertMatchesRegularExpression('/^INV-\d{6}-\d{3,}$/', $invoice['invoice_number']);
        $this->assertSame(self::CLINIC_A, (int) $this->invoiceRow((int) $invoice['id'])['clinic_id']);

        $payment = $this->finance()->recordPayment(
            $this->operatorUserId,
            (int) $invoice['id'],
            ['amount' => 300000, 'method' => 'cash'],
            $this->uuid()
        );
        $this->assertMatchesRegularExpression('/^PAY-\d{6}-\d{4,}$/', (string) $payment['payment_number']);

        $summary = $this->finance()->summary($this->operatorUserId, $this->today, $this->today);
        $this->assertSame(300000, $summary['revenue']['total']);
        $this->assertSame(0, $summary['open_balances']['total']);
        $this->assertSame(1, count($summary['payments']));
    }

    // ================= ۱۰ — insert-failure: شناسهٔ والد stale/صفر =================

    /**
     * نقص ثانویه (بازتولید با MySQL واقعی، بدون mock):
     * `CpmsDb::$strict` در runtime خاموش است، پس `wpdb::insert` در خطا `false`
     * می‌دهد ولی `wpdb::$insert_id` مقدارِ insert موفقِ قبلی سرِ جایش می‌ماند.
     * پیش از corrective، `InvoiceRepository::insert` آن شناسهٔ stale را
     * برمی‌گرداند، اقلام فاکتور به فاکتور دیگری چسبانده می‌شد و تراکنش commit
     * می‌شد.
     *
     * تزریق خطا (بدون mock): `MAX(invoice_number)` با شماره‌ای مسموم می‌شود که
     * **الگوی LIKE را match می‌کند ولی الگوی عددگیری را نه** و از نظر مقایسهٔ
     * رشته‌ای بزرگ‌تر از ارقام است (`…-zzz` > `…-001`). در نتیجه seq=0 و
     * شمارهٔ بعدی `INV-…-001` ساخته می‌شود که از قبل اشغال است ⇒ نقض
     * `u_inv_number (clinic_id, invoice_number)` ⇒ شکست واقعی درج.
     */
    public function testInvoiceInsertFailureStopsAndNeverAttachesItemsToStaleParent(): void
    {
        $this->bindScope(self::CLINIC_B);
        $ymd = gmdate('ymd');

        // مسموم‌سازی MAX — داخل LIKE می‌افتد، داخل regex عددگیری نه.
        $this->insertRawInvoice(self::CLINIC_B, $this->patientB, $this->insertVisit(self::CLINIC_B, $this->locB, $this->patientB), 'INV-' . $ymd . '-zzz');
        // و همان شماره‌ای که عددگیر خواهد ساخت از قبل اشغال می‌شود.
        $occupiedId = $this->insertRawInvoice(self::CLINIC_B, $this->patientB, $this->insertVisit(self::CLINIC_B, $this->locB, $this->patientB), 'INV-' . $ymd . '-001');
        $this->assertSame('INV-' . $ymd . '-001', $this->nextInvoiceNumberFor(self::CLINIC_B), 'پیش‌شرط تزریق خطا');

        $itemsBefore = $this->countInvoiceItems(self::CLINIC_B);
        $visitForAttempt = $this->insertVisit(self::CLINIC_B, $this->locB, $this->patientB);

        $thrown = null;
        global $wpdb;
        $wpdb->suppress_errors();
        try {
            $this->finance()->issueInvoice($this->operatorUserId, [
                'visit_id' => $visitForAttempt,
                'items' => [['description' => 'قلمی که هرگز نباید بنشیند', 'unit_price' => 5000]],
            ]);
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            $wpdb->show_errors();
        }

        $this->assertInstanceOf(
            \RuntimeException::class,
            $thrown,
            'شکست درج فاکتور باید عملیات را متوقف کند (نه ادامه با شناسهٔ stale)'
        );
        $this->assertStringContainsString('cpms_invoices insert failed', (string) $thrown?->getMessage());

        $this->assertSame(
            $itemsBefore,
            $this->countInvoiceItems(self::CLINIC_B),
            'هیچ قلمی نباید به فاکتورِ والدِ stale/بیگانه چسبانده شود'
        );
        $this->assertSame(
            0,
            $this->countInvoiceItemsOf($occupiedId),
            'فاکتور از پیش موجود نباید قلمِ فاکتور شکست‌خورده را بگیرد'
        );
        $this->assertNull(
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
        $ymd = gmdate('ymd');

        $invoice = $this->makeInvoiceForVisit(self::CLINIC_B, $this->visitB, 500000);
        $invoiceId = (int) $invoice['id'];

        // MAX خارج‌الگو ⇒ عددگیر PAY-…-0001 می‌سازد؛ همان شماره اشغال می‌شود.
        $otherVisit = $this->insertVisit(self::CLINIC_B, $this->locB, $this->patientB);
        $otherInvoice = $this->insertRawInvoice(self::CLINIC_B, $this->patientB, $otherVisit, 'INV-' . $ymd . '-090');
        $this->insertRawPayment(self::CLINIC_B, $otherInvoice, $this->patientB, 'PAY-' . $ymd . '-zzz');
        $this->insertRawPayment(self::CLINIC_B, $otherInvoice, $this->patientB, 'PAY-' . $ymd . '-0001');
        $this->assertSame('PAY-' . $ymd . '-0001', $this->nextPaymentNumberFor(self::CLINIC_B), 'پیش‌شرط تزریق خطا');

        $paymentsBefore = $this->countPayments(self::CLINIC_B);

        $thrown = null;
        global $wpdb;
        $wpdb->suppress_errors();
        try {
            $this->finance()->recordPayment(
                $this->operatorUserId,
                $invoiceId,
                ['amount' => 100000, 'method' => 'cash'],
                $this->uuid()
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            $wpdb->show_errors();
        }

        $this->assertInstanceOf(FinanceException::class, $thrown, 'شکست درج پرداخت باید خطا بدهد');
        $this->assertSame(500, $thrown instanceof FinanceException ? $thrown->httpStatus : 0);
        $this->assertSame(
            $paymentsBefore,
            $this->countPayments(self::CLINIC_B),
            'پرداخت شکست‌خورده نباید ثبت شود'
        );
        $this->assertSame(
            0,
            (int) round((float) ($this->invoiceRow($invoiceId)['paid_amount'] ?? -1)),
            'اثر پرداخت نباید روی فاکتور commit شود (ROLLBACK)'
        );
    }

    // ================= ۱۱ — قرارداد صریح Clinic در Repository/Service =================

    /**
     * قرارداد باید «Clinic الزامی» را بیان کند: پارامتر اول، int، بدون مقدار
     * پیش‌فرض. این تست جلوی بازگشت امضای قدیمی را می‌گیرد.
     */
    public function testRepositoryContractsRequireExplicitClinicScope(): void
    {
        $cases = [
            [ServiceRepository::class, 'all', 'clinic_id'],
            [PaymentRepository::class, 'revenueSummary', 'clinic_id'],
            [PaymentRepository::class, 'forRange', 'clinic_id'],
            [PaymentRepository::class, 'nextPaymentNumber', 'clinic_id'],
            [InvoiceRepository::class, 'nextInvoiceNumber', 'clinic_id'],
            [InvoiceRepository::class, 'openInvoices', 'clinic_id'],
            [\ClinicCore\Application\Finance\FinanceService::class, 'lockClinic', 'clinicId'],
        ];

        foreach ($cases as [$class, $method, $expectedParam]) {
            $rm = new \ReflectionMethod($class, $method);
            $params = $rm->getParameters();
            $label = $class . '::' . $method;
            $this->assertGreaterThan(0, count($params), $label . ' باید پارامتر Clinic داشته باشد');
            $this->assertSame($expectedParam, $params[0]->getName(), $label . ' — پارامتر اول باید Clinic باشد');
            $this->assertFalse($params[0]->isOptional(), $label . ' — Clinic نباید optional باشد');
            $this->assertFalse($params[0]->isDefaultValueAvailable(), $label . ' — Clinic نباید مقدار پیش‌فرض داشته باشد');
            $this->assertSame('int', (string) $params[0]->getType(), $label . ' — Clinic باید int باشد');
        }
    }

    // ================= Fixture / helpers =================

    private function bindScope(int $clinicId): void
    {
        App::resetScope();
        ScopeContext::set(ClinicScope::forClinic($clinicId));
    }

    private function finance(): \ClinicCore\Application\Finance\FinanceService
    {
        return App::financeService();
    }

    private function uuid(): string
    {
        $d = static fn (int $len): string => bin2hex(random_bytes((int) ceil($len / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $d(8), $d(4), substr($d(3), 0, 3), substr($d(4), 0, 4), $d(12));
    }

    /** @return list<string> */
    private function serviceCodes(): array
    {
        return array_map(
            static fn (array $s): string => (string) $s['code'],
            $this->finance()->listServices($this->operatorUserId, false)
        );
    }

    private function makeService(string $code, string $name, int $price): int
    {
        return (int) $this->finance()->createService($this->operatorUserId, [
            'code' => $code,
            'name' => $name,
            'price' => $price,
        ])['id'];
    }

    /** @return array<string, mixed> */
    private function makeInvoiceForVisit(int $clinicId, int $visitId, int $total): array
    {
        $this->bindScope($clinicId);

        return $this->finance()->issueInvoice($this->operatorUserId, [
            'visit_id' => $visitId,
            'items' => [['description' => 'ویزیت', 'unit_price' => $total]],
        ]);
    }

    /** @return array<string, mixed> */
    private function invoiceRow(int $invoiceId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d',
            [$invoiceId]
        );
        $this->assertNotNull($row, 'invoice row must exist');

        return (array) $row;
    }

    /** @return array<string, mixed> */
    private function paymentRow(int $paymentId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_payments') . ' WHERE id = %d',
            [$paymentId]
        );
        $this->assertNotNull($row, 'payment row must exist');

        return (array) $row;
    }

    /** @return array<string, mixed>|null */
    private function invoiceForVisit(int $visitId): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_invoices') . ' WHERE visit_id = %d',
            [$visitId]
        );

        return $row === null ? null : (array) $row;
    }

    private function maxInvoiceNumber(int $clinicId): string
    {
        return (string) App::db()->fetchValue(
            'SELECT MAX(invoice_number) FROM ' . App::db()->table('cpms_invoices') . ' WHERE clinic_id = %d',
            [$clinicId]
        );
    }

    private function invoiceSeq(string $invoiceNumber): int
    {
        $this->assertMatchesRegularExpression('/^INV-\d{6}-(\d{3,})$/', $invoiceNumber);

        return (int) substr($invoiceNumber, 11);
    }

    private function paymentSeq(string $paymentNumber): int
    {
        $this->assertMatchesRegularExpression('/^PAY-\d{6}-(\d{4,})$/', $paymentNumber);

        return (int) substr($paymentNumber, 11);
    }

    private function nextInvoiceNumberFor(int $clinicId): string
    {
        return (new InvoiceRepository(App::db()))->nextInvoiceNumber($clinicId);
    }

    private function nextPaymentNumberFor(int $clinicId): string
    {
        return (new PaymentRepository(App::db()))->nextPaymentNumber($clinicId);
    }

    private function countInvoiceItems(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_invoice_items') . ' it' .
            ' JOIN ' . App::db()->table('cpms_invoices') . ' i ON i.id = it.invoice_id' .
            ' WHERE i.clinic_id = %d',
            [$clinicId]
        );
    }

    private function countInvoiceItemsOf(int $invoiceId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_invoice_items') . ' WHERE invoice_id = %d',
            [$invoiceId]
        );
    }

    private function countPayments(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE clinic_id = %d',
            [$clinicId]
        );
    }

    private function insertClinic(int $id, string $slug): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
        $this->assertNotFalse($ok, 'clinic fixture must insert');
    }

    private function primaryLocation(int $clinicId): int
    {
        global $wpdb;
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d AND is_primary = 1 LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
        $this->assertGreaterThan(0, $id, 'seeded clinic must have a primary location');

        return $id;
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

        return (int) $wpdb->insert_id;
    }

    private function insertClinician(int $homeClinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $homeClinicId,
                $name,
                $wpUserId,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $lastName, string $mrn): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $mrn . '-' . $seq,
                'Patient',
                $lastName,
                '0918' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    /** ویزیت آمادهٔ فاکتورسازی (awaiting_payment) — مستقیم، بدون state machine. */
    private function insertVisit(int $clinicId, int $locationId, int $patientId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                     (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, called_at, active, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, "walk_in", "awaiting_payment", %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $locationId,
                $this->clinicianId,
                $patientId,
                $this->today,
                $this->today . ' 10:00:00.000',
                $this->today . ' 10:00:00.000',
                $this->today . ' 10:05:00.000',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertRawInvoice(int $clinicId, int $patientId, int $visitId, string $invoiceNumber): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_invoices
                     (clinic_id, invoice_number, patient_id, visit_id, status, subtotal, total, paid_amount, balance, issued_by_wp_user_id, created_at, updated_at)
                 VALUES (%d, %s, %d, %d, "open", 1000, 1000, 0, 1000, %d, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $invoiceNumber,
                $patientId,
                $visitId,
                $this->operatorUserId,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertRawPayment(int $clinicId, int $invoiceId, int $patientId, string $paymentNumber): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_payments
                     (clinic_id, payment_number, invoice_id, patient_id, amount, method, idempotency_key, status, paid_at, received_by_wp_user_id, created_at)
                 VALUES (%d, %s, %d, %d, 1000, "cash", %s, "captured", %s, %d, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $paymentNumber,
                $invoiceId,
                $patientId,
                'idem-' . $paymentNumber,
                $this->today . ' 12:00:00.000',
                $this->operatorUserId,
                $now
            )
        );

        return (int) $wpdb->insert_id;
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
}
