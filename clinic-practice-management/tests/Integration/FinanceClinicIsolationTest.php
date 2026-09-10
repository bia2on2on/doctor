<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\InvoiceRepository;
use ClinicCore\Infrastructure\Repository\PaymentRepository;
use WP_UnitTestCase;

/**
 * رگرسیون ایزولیشن مالی بر اساس Clinic (اصلاحیه پسابسته C6).
 *
 * پوشش:
 *  - سازگاری Clinic شماره 1 + کارکرد Clinic با شناسه غیر 1 + همزیستی دو Clinic
 *  - ایزولیشن تعرفه‌ها، خلاصه درآمد، بازه پرداخت‌ها، فاکتورهای باز
 *  - عدم نشت نام بیمار/MRN از مسیر مالی به Clinic دیگر
 *  - استقلال per-Clinic شماره‌گذاری فاکتور و پرداخت
 *  - رفتار قفل/عددگیری در غیاب ردیف Clinic شماره 1
 *  - fail-closed در نبود Scope معتبر
 *  - ایمنی شکست insert (بدون-cherry-pick از شاخه حذف‌شده؛ شرط شکست واقعی DB)
 *
 * همه سناریوها از قرارداد پایدار سطح سرویس استفاده می‌کنند تا هم روی رفتار
 * قدیمی (پیش از فیکس) اجرا شوند و شکست بخورند، هم پس از فیکس سبز شوند.
 */
final class FinanceClinicIsolationTest extends WP_UnitTestCase
{
    private const CLINIC_B = 702;

    private ?string $sabotageTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('clinical.require_chief_complaint', true);
        App::resetScope();
    }

    protected function tearDown(): void
    {
        $this->disarmSabotage();
        App::resetScope();
        parent::tearDown();
    }

    // ================= سازگاری و همزیستی =================

    public function testClinicOneCompatibility(): void
    {
        $fx = $this->makeClinicFixtures(1, 'A');

        $svc = $this->withScope(1, fn (): array => $this->finance()->createService(
            $fx['admin'],
            ['code' => 'C1-COMPAT', 'name' => 'سازگاری ۱', 'price' => 100000]
        ));
        $this->assertSame('C1-COMPAT', $svc['code']);

        $list = $this->withScope(1, fn (): array => $this->finance()->listServices($fx['secretary'], false));
        $this->assertContains('C1-COMPAT', array_column($list, 'code'));

        $visitId = $this->makeCompletedVisit($fx);
        $invoice = $this->finance()->issueInvoice($fx['secretary'], [
            'visit_id' => $visitId,
            'items' => [['description' => 'ویزیت', 'unit_price' => 120000]],
        ]);
        $this->assertSame('INV-' . gmdate('ymd') . '-001', $invoice['invoice_number']);

        $pay = $this->finance()->recordPayment(
            $fx['secretary'],
            (int) $invoice['id'],
            ['amount' => 120000, 'method' => 'cash'],
            $this->uuid()
        );
        $this->assertSame('paid', $pay['invoice']['status']);

        $summary = $this->withScope(
            1,
            fn (): array => $this->finance()->summary($fx['secretary'], gmdate('Y-m-d'), gmdate('Y-m-d'))
        );
        $this->assertSame(120000, $summary['revenue']['total']);
    }

    public function testNonOneClinicWorks(): void
    {
        $this->ensureClinicB();
        $fx = $this->makeClinicFixtures(self::CLINIC_B, 'B');

        $svc = $this->withScope(self::CLINIC_B, fn (): array => $this->finance()->createService(
            $fx['admin'],
            ['code' => 'CB-WORKS', 'name' => 'کارکرد B', 'price' => 200000]
        ));
        $this->assertSame('CB-WORKS', $svc['code']);

        // Clinic شماره 1 در این تست هیچ تعرفه‌ای ندارد؛ اگر لیست خالی برگردد
        // یعنی خواندن هنوز به Clinic ۱ چسبیده است.
        $list = $this->withScope(
            self::CLINIC_B,
            fn (): array => $this->finance()->listServices($fx['secretary'], false)
        );
        $this->assertContains('CB-WORKS', array_column($list, 'code'));

        $visitId = $this->makeCompletedVisit($fx);
        $invoice = $this->finance()->issueInvoice($fx['secretary'], [
            'visit_id' => $visitId,
            'items' => [['description' => 'ویزیت B', 'unit_price' => 200000]],
        ]);
        $this->assertSame('INV-' . gmdate('ymd') . '-001', $invoice['invoice_number']);
    }

    public function testTwoClinicsCoexist(): void
    {
        $this->ensureClinicB();
        $fxA = $this->makeClinicFixtures(1, 'A');
        $fxB = $this->makeClinicFixtures(self::CLINIC_B, 'B');

        $this->withScope(1, fn (): array => $this->finance()->createService(
            $fxA['admin'],
            ['code' => 'SVC-A', 'name' => 'خدمت A', 'price' => 1000]
        ));
        $this->withScope(self::CLINIC_B, fn (): array => $this->finance()->createService(
            $fxB['admin'],
            ['code' => 'SVC-B', 'name' => 'خدمت B', 'price' => 2000]
        ));

        $listA = $this->withScope(1, fn (): array => $this->finance()->listServices($fxA['secretary'], false));
        $listB = $this->withScope(
            self::CLINIC_B,
            fn (): array => $this->finance()->listServices($fxB['secretary'], false)
        );

        $this->assertSame(['SVC-A'], array_column($listA, 'code'));
        $this->assertSame(['SVC-B'], array_column($listB, 'code'));
    }

    // ================= ایزولیشن خواندن‌ها =================

    public function testServiceListingIsolatedByClinic(): void
    {
        $this->ensureClinicB();
        $fxA = $this->makeClinicFixtures(1, 'A');
        $fxB = $this->makeClinicFixtures(self::CLINIC_B, 'B');

        $this->withScope(1, fn (): array => $this->finance()->createService(
            $fxA['admin'],
            ['code' => 'ONLY-A', 'name' => 'فقط A', 'price' => 1000]
        ));
        $this->withScope(self::CLINIC_B, fn (): array => $this->finance()->createService(
            $fxB['admin'],
            ['code' => 'ONLY-B', 'name' => 'فقط B', 'price' => 2000]
        ));

        $codesB = array_column(
            $this->withScope(
                self::CLINIC_B,
                fn (): array => $this->finance()->listServices($fxB['secretary'], false)
            ),
            'code'
        );
        $this->assertContains('ONLY-B', $codesB);
        $this->assertNotContains('ONLY-A', $codesB);
    }

    public function testRevenueSummaryIsolatedByClinic(): void
    {
        $this->ensureClinicB();
        $fxA = $this->makeClinicFixtures(1, 'A');
        $fxB = $this->makeClinicFixtures(self::CLINIC_B, 'B');

        $this->issueAndPayInFull($fxA, 100000);
        $this->issueAndPayInFull($fxB, 250000);

        $today = gmdate('Y-m-d');
        $sumA = $this->withScope(1, fn (): array => $this->finance()->summary($fxA['secretary'], $today, $today));
        $sumB = $this->withScope(
            self::CLINIC_B,
            fn (): array => $this->finance()->summary($fxB['secretary'], $today, $today)
        );

        $this->assertSame(100000, $sumA['revenue']['total']);
        $this->assertSame(250000, $sumB['revenue']['total']);
        $this->assertSame(1, $sumA['revenue']['payment_count']);
        $this->assertSame(1, $sumB['revenue']['payment_count']);
    }

    public function testPaymentRangeIsolatedByClinic(): void
    {
        $this->ensureClinicB();
        $fxA = $this->makeClinicFixtures(1, 'A');
        $fxB = $this->makeClinicFixtures(self::CLINIC_B, 'B');

        $this->issueAndPayInFull($fxA, 100000);
        $payB = $this->issueAndPayInFull($fxB, 250000);
        $expectedInvoiceId = (int) $payB['invoice_id'];

        $today = gmdate('Y-m-d');
        $sumB = $this->withScope(
            self::CLINIC_B,
            fn (): array => $this->finance()->summary($fxB['secretary'], $today, $today)
        );

        $invoiceIds = array_column($sumB['payments'], 'invoice_id');
        $this->assertNotSame([], $invoiceIds);
        foreach ($invoiceIds as $id) {
            $this->assertSame($expectedInvoiceId, (int) $id, 'بازه پرداخت B فقط پرداخت‌های B را دارد');
        }
    }

    public function testOpenInvoicesIsolatedByClinic(): void
    {
        $this->ensureClinicB();
        $fxA = $this->makeClinicFixtures(1, 'A');
        $fxB = $this->makeClinicFixtures(self::CLINIC_B, 'B');

        $invA = $this->issueOpenInvoice($fxA, 110000);
        $invB = $this->issueOpenInvoice($fxB, 220000);

        $today = gmdate('Y-m-d');
        $sumB = $this->withScope(
            self::CLINIC_B,
            fn (): array => $this->finance()->summary($fxB['secretary'], $today, $today)
        );

        $ids = array_column($sumB['open_balances']['invoices'], 'id');
        $this->assertContains((int) $invB['id'], array_map('intval', $ids));
        $this->assertNotContains((int) $invA['id'], array_map('intval', $ids));
        $this->assertSame(220000, $sumB['open_balances']['total']);
    }

    public function testPatientNameMrnCannotCrossClinicThroughFinance(): void
    {
        $this->ensureClinicB();
        $fxA = $this->makeClinicFixtures(1, 'A');
        $fxB = $this->makeClinicFixtures(self::CLINIC_B, 'B');

        // هر فاکتور روی بیمار شناخته‌شده همان Clinic تا نام/MRN قابل assert باشد.
        $this->issueOpenInvoice($fxA, 110000, $fxA['patient']);
        $this->issueOpenInvoice($fxB, 220000, $fxB['patient']);

        $today = gmdate('Y-m-d');
        $sumB = $this->withScope(
            self::CLINIC_B,
            fn (): array => $this->finance()->summary($fxB['secretary'], $today, $today)
        );

        $names = array_column($sumB['open_balances']['invoices'], 'patient_name');
        $mrns = array_column($sumB['open_balances']['invoices'], 'mrn');
        $this->assertContains($fxB['patient_name'], $names);
        $this->assertContains($fxB['mrn'], $mrns);
        $this->assertNotContains($fxA['patient_name'], $names);
        $this->assertNotContains($fxA['mrn'], $mrns);
    }

    // ================= شماره‌گذاری مستقل =================

    public function testInvoiceNumberingIndependentPerClinic(): void
    {
        $this->ensureClinicB();
        $fxA = $this->makeClinicFixtures(1, 'A');
        $fxB = $this->makeClinicFixtures(self::CLINIC_B, 'B');

        $prefix = 'INV-' . gmdate('ymd') . '-';
        $invA1 = $this->issueOpenInvoice($fxA, 100000);
        $this->assertSame($prefix . '001', $invA1['invoice_number']);

        // Clinic ۲ دنباله خودش را از ۰۰۱ شروع می‌کند، نه ادامه Clinic ۱.
        $invB1 = $this->issueOpenInvoice($fxB, 200000);
        $this->assertSame($prefix . '001', $invB1['invoice_number']);

        $invB2 = $this->issueOpenInvoice($fxB, 300000);
        $this->assertSame($prefix . '002', $invB2['invoice_number']);

        $this->assertNotSame((int) $invA1['id'], (int) $invB1['id']);
    }

    public function testPaymentNumberingIndependentPerClinic(): void
    {
        $this->ensureClinicB();
        $fxA = $this->makeClinicFixtures(1, 'A');
        $fxB = $this->makeClinicFixtures(self::CLINIC_B, 'B');

        $prefix = 'PAY-' . gmdate('ymd') . '-';
        $payA = $this->issueAndPayInFull($fxA, 100000);
        $this->assertSame($prefix . '0001', $payA['payment_number']);

        $payB = $this->issueAndPayInFull($fxB, 200000);
        $this->assertSame($prefix . '0001', $payB['payment_number']);
    }

    // ================= قفل/عددگیری بدون ردیف Clinic ۱ =================

    public function testOperationsWorkWhenClinicOneRowAbsent(): void
    {
        global $wpdb;
        // پیش‌شرط: تنها Clinic نصب، شناسه غیر ۱ دارد. ردیف Clinic ۱ (و
        // فرزندان مستقیمش) با FOREIGN_KEY_CHECKS=0 حذف می‌شود چون ممکن است
        // ردیف‌های seed/چسبیده از مسیرهای دیگر به آن ارجاع بدهند؛ همه‌چیز
        // داخل تراکنش همین تست است و در پایان رول‌بک می‌شود.
        $this->ensureClinicB();
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        try {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE clinic_id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $deleted = $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } finally {
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $this->assertSame(1, (int) $deleted, 'پیش‌شرط: حذف ردیف Clinic ۱');
        App::resetScope();
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertSame(1, $count);

        $fx = $this->makeClinicFixtures(self::CLINIC_B, 'B');
        $this->withScope(self::CLINIC_B, fn (): array => $this->finance()->createService(
            $fx['admin'],
            ['code' => 'NO-ONE', 'name' => 'بدون ردیف یک', 'price' => 5000]
        ));
        $list = $this->withScope(
            self::CLINIC_B,
            fn (): array => $this->finance()->listServices($fx['secretary'], false)
        );
        $this->assertContains('NO-ONE', array_column($list, 'code'));

        // دو صدور پیاپی باید ۰۰۱ و ۰۰۲ بگیرد، نه خطای Duplicate روی ۰۰۱.
        $prefix = 'INV-' . gmdate('ymd') . '-';
        $inv1 = $this->issueOpenInvoice($fx, 100000);
        $inv2 = $this->issueOpenInvoice($fx, 200000);
        $this->assertSame($prefix . '001', $inv1['invoice_number']);
        $this->assertSame($prefix . '002', $inv2['invoice_number']);
    }

    // ================= fail-closed =================

    public function testListServicesWithoutScopeFailsClosed(): void
    {
        $this->ensureClinicB();
        $fxA = $this->makeClinicFixtures(1, 'A');
        App::resetScope(); // چند Clinic + بدون Scope صریح

        $this->expectException(FinanceException::class);
        $this->finance()->listServices($fxA['secretary'], false);
    }

    public function testSummaryWithoutScopeFailsClosed(): void
    {
        $this->ensureClinicB();
        $fxA = $this->makeClinicFixtures(1, 'A');
        App::resetScope(); // چند Clinic + بدون Scope صریح

        $this->expectException(FinanceException::class);
        $this->finance()->summary($fxA['secretary'], gmdate('Y-m-d'), gmdate('Y-m-d'));
    }

    public function testFailClosedCarriesScopeRequiredCode(): void
    {
        $this->ensureClinicB();
        $fxA = $this->makeClinicFixtures(1, 'A');
        App::resetScope();

        try {
            $this->finance()->summary($fxA['secretary'], gmdate('Y-m-d'), gmdate('Y-m-d'));
            $this->fail('انتظار استثنا بدون Scope معتبر');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode);
            $this->assertSame(400, $e->httpStatus);
        }
    }

    // ================= ایمنی شکست insert (شرط واقعی DB) =================

    public function testFailedInvoiceInsertAttachesNothingAndRollsBack(): void
    {
        $fx = $this->makeClinicFixtures(1, 'A');

        // فاکتور نامرتبطِ ازپیش‌موجود با یک قلم — شمار اقلامش نباید تغییر کند.
        $unrelated = $this->issueOpenInvoice($fx, 50000);
        $itemsBefore = $this->countInvoiceItems((int) $unrelated['id']);
        $this->assertSame(1, $itemsBefore);

        $visitId = $this->makeCompletedVisit($fx);
        $this->armSabotage('cpms_invoices');
        try {
            $this->finance()->issueInvoice($fx['secretary'], [
                'visit_id' => $visitId,
                'items' => [['description' => 'ویزیت', 'unit_price' => 90000]],
            ]);
            $this->fail('شکست درج فاکتور باید استثنا بدهد');
        } catch (FinanceException $e) {
            $this->assertNotSame('', $e->getMessage());
        } finally {
            $this->disarmSabotage();
        }

        // هیچ قلمی به فاکتور نامرتبط نچسبیده است.
        $this->assertSame(1, $this->countInvoiceItems((int) $unrelated['id']));
        // گذار V11 رول‌بک شده و ویزیت هنوز consultation_completed است.
        $visit = App::db()->fetchRow(
            'SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visitId]
        );
        $this->assertSame('consultation_completed', (string) $visit['status']);
        // هیچ فاکتوری برای این ویزیت ثبت نشده است.
        try {
            $this->finance()->invoiceForVisit($fx['secretary'], $visitId);
            $this->fail('نباید فاکتوری برای ویزیت ناموفق وجود داشته باشد');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }
    }

    public function testFailedInvoiceInsertReturnsZeroId(): void
    {
        $this->makeClinicFixtures(1, 'A');
        $repo = new InvoiceRepository(App::db());

        $this->armSabotage('cpms_invoices');
        try {
            $id = $repo->insert(1, [
                'invoice_number' => 'INV-000000-000',
                'patient_id' => 1,
                'visit_id' => 1,
            ]);
        } finally {
            $this->disarmSabotage();
        }

        // معنای wpdb استاندارد: insert ناموفق insert_id صفر می‌گذارد، نه stale.
        $this->assertSame(0, $id);
    }

    public function testFailedPaymentInsertAppliesNoEffectsAndRollsBack(): void
    {
        $fx = $this->makeClinicFixtures(1, 'A');
        $invoice = $this->issueOpenInvoice($fx, 140000);
        $invoiceId = (int) $invoice['id'];

        $this->armSabotage('cpms_payments');
        try {
            $this->finance()->recordPayment(
                $fx['secretary'],
                $invoiceId,
                ['amount' => 140000, 'method' => 'cash'],
                $this->uuid()
            );
            $this->fail('شکست درج پرداخت باید استثنا بدهد');
        } catch (FinanceException $e) {
            $this->assertNotSame('', $e->getMessage());
        } finally {
            $this->disarmSabotage();
        }

        // اثری روی فاکتور اعمال نشده و هیچ سطر پرداختی ثبت نشده است.
        $view = $this->finance()->invoiceView($invoiceId);
        $this->assertSame('open', $view['status']);
        $this->assertSame(0.0, $view['paid_amount']);
        $this->assertSame(140000.0, $view['balance']);
        $this->assertSame([], $view['payments']);

        global $wpdb;
        $n = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_payments WHERE invoice_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $invoiceId
            )
        );
        $this->assertSame(0, $n);
    }

    public function testFailedPaymentInsertReturnsZeroId(): void
    {
        $this->makeClinicFixtures(1, 'A');
        $repo = new PaymentRepository(App::db());

        $this->armSabotage('cpms_payments');
        try {
            $id = $repo->insert(1, [
                'payment_number' => 'PAY-000000-0000',
                'invoice_id' => 1,
                'patient_id' => 1,
                'amount' => 10,
                'method' => 'cash',
                'idempotency_key' => $this->uuid(),
                'paid_at' => App::db()->nowUtcSql(),
                'received_by_wp_user_id' => 1,
            ]);
        } finally {
            $this->disarmSabotage();
        }

        $this->assertSame(0, $id);
    }

    // ================= Helpers =================

    private function finance(): \ClinicCore\Application\Finance\FinanceService
    {
        return App::financeService();
    }

    /**
     * @template T
     * @param callable(): T $fn
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

    private function uuid(): string
    {
        $d = static fn (int $len): string => bin2hex(random_bytes((int) ceil($len / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $d(8), $d(4), substr($d(3), 0, 3), substr($d(4), 0, 4), $d(12));
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

    private function ensureClinicB(): void
    {
        global $wpdb;
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::CLINIC_B
            )
        );
        if ($exists > 0) {
            return;
        }
        $orgId = (int) $wpdb->get_var(
            'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $this->assertGreaterThan(0, $orgId, 'پیش‌شرط: Organization نصب از Clinic ۱ خوانده شود');
        $now = App::db()->nowUtcSql();
        $slug = 'clinic-b-' . bin2hex(random_bytes(3));
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::CLINIC_B,
                $orgId,
                'Clinic B',
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->assertSame(self::CLINIC_B, (int) $wpdb->insert_id);
        // AD-15: هر Clinic حداقل یک Location اصلی دارد (ویزیت به آن نیاز دارد).
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::CLINIC_B,
                'Loc B',
                'loc-b-' . bin2hex(random_bytes(3)),
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->assertGreaterThan(0, (int) $wpdb->insert_id);
        App::resetScope();
    }

    /**
     * فیكسچر کامل یک Clinic: کاربران + عضویت‌ها + Clinician + بیمار.
     *
     * @return array{clinic: int, secretary: int, doctor: int, admin: int, clinician: int, patient: int, patient_name: string, mrn: string}
     */
    private function makeClinicFixtures(int $clinicId, string $tag): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        $secretary = $this->makeUser('fci_sec_' . $tag, 'cpms_secretary');
        cpms_test_seed_membership($secretary, $clinicId, 'cpms_secretary');
        $doctor = $this->makeUser('fci_doc_' . $tag, 'cpms_doctor');
        cpms_test_seed_membership($doctor, $clinicId, 'cpms_doctor');
        $admin = $this->makeUser('fci_adm_' . $tag, 'administrator');
        cpms_test_seed_membership($admin, $clinicId, 'cpms_manager');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'Dr Iso ' . $tag,
                $doctor,
                $now,
                $now
            )
        );
        $clinicianId = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $clinicianId);

        $patient = $this->makePatient($clinicId, $tag);

        return [
            'clinic' => $clinicId,
            'secretary' => $secretary,
            'doctor' => $doctor,
            'admin' => $admin,
            'clinician' => $clinicianId,
            'patient' => $patient['id'],
            'patient_name' => $patient['name'],
            'mrn' => $patient['mrn'],
        ];
    }

    /**
     * بیمار تازه — هر ویزیتِ یک تست، بیمار خودش را می‌گیرد (J-5: یک ویزیت
     * فعال در روز برای هر بیمار).
     *
     * @return array{id: int, name: string, mrn: string}
     */
    private function makePatient(int $clinicId, string $tag): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-FCI-' . $tag . '-' . bin2hex(random_bytes(2));
        $first = $tag === 'A' ? 'AliAhmadi' : 'SaraMoradi';
        $last = 'Iso' . $tag . bin2hex(random_bytes(2));
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
        $this->assertGreaterThan(0, $patientId);

        return ['id' => $patientId, 'name' => trim($first . ' ' . $last), 'mrn' => $mrn];
    }

    /**
     * @param array{clinic: int, secretary: int, doctor: int, clinician: int, patient: int} $fx
     */
    private function makeCompletedVisit(array $fx, ?int $patientId = null): int
    {
        $tag = ((int) $fx['clinic'] === 1) ? 'A' : 'B';
        $patientId ??= $this->makePatient((int) $fx['clinic'], $tag)['id'];
        $visit = App::visitService()->walkIn($fx['secretary'], $patientId, $fx['clinician']);
        $id = (int) $visit['id'];
        App::visitService()->transition($fx['doctor'], $id, 'call');
        App::visitService()->transition($fx['doctor'], $id, 'start');
        App::clinicalService()->addNote($fx['doctor'], $id, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'درد و تهوع',
        ]);
        App::clinicalService()->completeConsultation($fx['doctor'], $id);

        return $id;
    }

    /**
     * @param array{clinic: int, secretary: int, doctor: int, clinician: int, patient: int} $fx
     * @return array<string, mixed>
     */
    private function issueOpenInvoice(array $fx, int $total, ?int $patientId = null): array
    {
        $visitId = $this->makeCompletedVisit($fx, $patientId);

        return $this->finance()->issueInvoice($fx['secretary'], [
            'visit_id' => $visitId,
            'items' => [['description' => 'ویزیت', 'unit_price' => $total]],
        ]);
    }

    /**
     * @param array{secretary: int, doctor: int, clinician: int, patient: int} $fx
     * @return array<string, mixed>
     */
    private function issueAndPayInFull(array $fx, int $total): array
    {
        $invoice = $this->issueOpenInvoice($fx, $total);
        $result = $this->finance()->recordPayment(
            $fx['secretary'],
            (int) $invoice['id'],
            ['amount' => $total, 'method' => 'cash'],
            $this->uuid()
        );
        $this->assertSame('paid', $result['invoice']['status']);

        return $result['payment'];
    }

    private function countInvoiceItems(int $invoiceId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_invoice_items WHERE invoice_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $invoiceId
            )
        );
    }

    /**
     * خرابکاری قطعی insert: بازنویسی INSERT جدول هدف به جدول ناموجود.
     * شرط شکست واقعی DB است، نه فرض MAX/پیشوند.
     */
    private function armSabotage(string $table): void
    {
        $this->sabotageTable = $table;
        add_filter('query', [$this, 'sabotageQuery']);
    }

    private function disarmSabotage(): void
    {
        if ($this->sabotageTable !== null) {
            remove_filter('query', [$this, 'sabotageQuery']);
            $this->sabotageTable = null;
        }
    }

    /**
     * @param mixed $query
     * @return mixed
     */
    public function sabotageQuery($query)
    {
        if ($this->sabotageTable !== null && is_string($query)) {
            global $wpdb;
            // wpdb شناسه‌ها را با بک‌تیک نقل‌قول می‌کند — هر دو شکل پوشش داده می‌شود.
            $table = preg_quote($wpdb->prefix . $this->sabotageTable, '/');
            if (preg_match('/INSERT\s+INTO\s+`?' . $table . '`?\s*\(/i', $query) === 1) {
                return 'INSERT INTO `' . $wpdb->prefix . 'cpms_table_that_does_not_exist` (id) VALUES (1)';
            }
        }

        return $query;
    }
}
