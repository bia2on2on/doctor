<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * C7-PRE — مشخصه‌نگاری پایانی پیش از ادغام: سه مرز باقی‌ماندهٔ نزدیک به C7.
 *
 * این فایل فقط شواهد است (evidence branch). هیچ رفتار تولیدی را تغییر نمی‌دهد
 * و هیچ کد تولیدی در همین برش اصلاح نمی‌شود (طبق دستور: مشخصه‌نگاری و اصلاح
 * در یک گام غیرقابل‌مشاهده ممنوع).
 *
 * کاندیداها (سرشماری مخزن‌گستردهٔ C7-PRE: src/ + tests/ + bin/ + workflows):
 *   A) مرز wp-admin — ClinicianAdminPage::saveSchedules/deleteSchedule/
 *      deleteException → ScheduleService::update/delete/deleteException
 *      (فراخوان تولیدیِ بدون Scope صریح — از C7-S2 شناخته‌شده).
 *   B) FinanceService::issueInvoice بر پایهٔ visit_id ورودی
 *      (تولیدی: FinanceController ‏POST /invoices؛ ابزار: pilot/closure probes).
 *   C) FinanceService::updateService/deactivateService بر پایهٔ id ورودی
 *      (تولیدی: FinanceController ‏PUT/DELETE /config/services/{id}).
 *
 * خاصیت امنیتی مشترک:
 *   کاربر معتبر Clinic B (عضویت فعال + Scope مورد اعتماد مرز، یا در مسیر
 *   wp-admin: capability ‏cpms_config + nonce معتبر) نباید صرفاً با ارسال
 *   شناسهٔ شیءِ Clinic A بتواند آن را جهش دهد یا دادهٔ وابستهٔ A فاش/متحول شود.
 *   «مرز wp-admin استثنای مجوز نیست» — باید زمینهٔ کلینیک معتبر را برقرار/راستی
 *   کند و سپس عملیات حساس اجرا شود.
 *
 * رفتار امنِ مورد انتظار (قرارداد محصول: fail-closed + 404 parity):
 *   - مسیر REST: پاسخ 404 با کد CLINIC_NOT_FOUND و پاکت یکسان با «یافت نشد»
 *   - مسیر wp-admin: انکار معادل (بدون جهش) و بدون اثر جانبی
 *   - ردیف/اسلات/فاکتور قربانی در DB بدون تغییر
 *
 * این فایل «مشخصهٔ ایزولیشن» است، نه آینهٔ رفتار فعلی. قرمزی هر تست در
 * main فعلی = شاهد نقص ازپیش‌موجود (طبقه‌بندی خطای پروژه: کلاس B). برای سبز
 * کردن CI نباید این تست‌ها تضعیف/skip شوند.
 *
 * استراتژی fixture:
 *   - Clinic قربانی A = 61321 و Clinic مهاجم B = 61322 (محدودهٔ رزرو ≥ 61000).
 *   - Scope/هویت مهاجم به‌صورت مستقل از اشیای قربانی برقرار می‌شود (عضویت
 *     فعال فقط روی B؛ هدر X-CPMS-Clinic-Id: 61322 یا نقش wp-admin).
 *   - اشیای قربانی به‌شکل مشروع توسط کاربران خودِ Clinic A از مسیر واقعی
 *     سرویس ساخته می‌شوند.
 *   - مسیر wp-admin با handler واقعی و nonce/capability معتبر dispatch می‌شود؛
 *     چون redirect() در پایان handler فراخوانی exit می‌کند، با فیلتر استاندارد
 *     wp_redirect که استثنا پرتاب می‌کند، اجرای واقعی handler (گارد → nonce →
 *     فراخوانی سرویس → ثبت notice → redirect) کامل می‌شود و فقط exit شبیه‌سازی
 *     می‌گردد — همهٔ جهش‌های DB پیش از redirect رخ می‌دهند.
 */
final class C7PreIntegrationBoundaryTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    /** Clinic قربانی — محدودهٔ رزرو تست (≥ 61000). */
    private const CLINIC_A = 61321;

    /** Clinic مهاجم (Scope/هویت مورد اعتمادِ فراخوان) — محدودهٔ رزرو تست. */
    private const CLINIC_B = 61322;

    private int $managerA = 0;

    private int $managerB = 0;

    private int $doctorA = 0;

    private int $secretaryA = 0;

    private int $secretaryB = 0;

    private int $clinicianA = 0;

    private int $scheduleAId = 0;

    private int $exceptionAId = 0;

    private int $futureEmptySlotAId = 0;

    private int $serviceAId = 0;

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

        // Warm خنثی پیش از ساخت fixtureها (الگوی ClinicTenantIsolationTest).
        $this->warmRoutes();

        // این دو تنظیم باید پیش از ساخت Clinicهای دوم اعمال شوند (تنها Clinic seed).
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('clinical.require_chief_complaint', true);
        App::resetScope();

        $orgId = $this->defaultOrganization();
        $this->insertClinic(self::CLINIC_A, $orgId, 'c7-pre-clinic-a');
        $this->insertClinic(self::CLINIC_B, $orgId, 'c7-pre-clinic-b');
        $locA = $this->insertLocation(self::CLINIC_A, 'c7-pre-loc-a');
        $this->insertLocation(self::CLINIC_B, 'c7-pre-loc-b');

        // Clinic A — کاربران مشروعِ مالکِ اشیای قربانی.
        $this->managerA = $this->makeUser('c7pre_mgr_a', 'cpms_manager');
        cpms_test_seed_membership($this->managerA, self::CLINIC_A, 'cpms_manager');
        $this->doctorA = $this->makeUser('c7pre_doc_a', 'cpms_doctor');
        cpms_test_seed_membership($this->doctorA, self::CLINIC_A, 'cpms_doctor');
        $this->secretaryA = $this->makeUser('c7pre_sec_a', 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryA, self::CLINIC_A, 'cpms_secretary');
        $this->clinicianA = $this->insertClinician(self::CLINIC_A, $this->doctorA, 'Dr C7 Pre Boundary Victim');

        // Clinic B — مهاجم: عضویت فعال فقط روی B (هویت مستقل از قربانی).
        $this->managerB = $this->makeUser('c7pre_mgr_b', 'cpms_manager');
        cpms_test_seed_membership($this->managerB, self::CLINIC_B, 'cpms_manager');
        $this->secretaryB = $this->makeUser('c7pre_sec_b', 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryB, self::CLINIC_B, 'cpms_secretary');

        // اشیای قربانی Schedule/Exception — از مسیر واقعی سرویس توسط مدیر A.
        $schedule = $this->withScope(self::CLINIC_A, fn (): array => App::scheduleService()->create(
            $this->managerA,
            [
                'clinician_id' => $this->clinicianA,
                'day_of_week' => 3,
                'start_time' => '09:00',
                'end_time' => '13:00',
                'appointment_duration_min' => 30,
                'slot_capacity' => 2,
            ]
        ));
        $this->scheduleAId = (int) $schedule['id'];
        self::assertGreaterThan(0, $this->scheduleAId, 'پیش‌شرط: برنامهٔ قربانی ساخته شود');

        $exception = $this->withScope(self::CLINIC_A, fn (): array => App::scheduleService()->createException(
            $this->managerA,
            [
                'clinician_id' => $this->clinicianA,
                'date' => gmdate('Y-m-d', (time() + 10 * 86400)),
                'type' => 'holiday',
            ]
        ));
        $this->exceptionAId = (int) $exception['id'];
        self::assertGreaterThan(0, $this->exceptionAId, 'پیش‌شرط: استثنای قربانی ساخته شود');

        /*
         * Slot خالیِ آیندهٔ پزشک قربانی — شاهد اثر جانبی (regenerate پس از
         * جهش متقاطع، همهٔ Slotهای خالی آیندهٔ پزشک را پاک می‌کند).
         */
        $this->futureEmptySlotAId = $this->seedFutureEmptySlot(self::CLINIC_A, $locA, $this->clinicianA);
        self::assertGreaterThan(0, $this->futureEmptySlotAId, 'پیش‌شرط: Slot خالی آیندهٔ قربانی ساخته شود');

        // قربانی مالی — تعرفهٔ Clinic A از مسیر واقعی سرویس توسط مدیر A.
        $service = $this->withScope(self::CLINIC_A, fn (): array => App::financeService()->createService(
            $this->managerA,
            ['code' => 'C7PRE-VICTIM', 'name' => 'تعرفه قربانی C7-PRE', 'price' => 250000]
        ));
        $this->serviceAId = (int) $service['id'];
        self::assertGreaterThan(0, $this->serviceAId, 'پیش‌شرط: تعرفهٔ قربانی ساخته شود');

        // بیمار قربانی Clinic A (برای کاندیدای فاکتورِ ویزیت خارجی).
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
        $_POST = [];
        unset($_REQUEST['_wpnonce']);

        // نشتی‌گیر دفاعی fixture (در صورت اختلال rollback) — هیچ ادعای محصولی را سست نمی‌کند.
        $this->purgeReserveRows();

        parent::tearDown();
    }

    // ================= کاندیدای A — مرز wp-admin برنامهٔ هفتگی =================

    /**
     * C7-PRE/A1 — ClinicianAdminPage::deleteSchedule با شناسهٔ برنامهٔ Clinic A.
     *
     * خاصیت: مدیرِ wp-admin Clinic B (capability ‏cpms_config + nonce معتبر —
     * از مسیر REAL اکشن admin) نباید بتواند برنامهٔ Clinic A را حذف کند.
     * انتظار: انکار معادل fail-closed (بدون حذف)؛ ردیف برنامهٔ قربانی موجود؛
     * Slot آیندهٔ خالی پزشک قربانی موجود (بدون regenerate قربانی).
     */
    public function testWpAdminDeleteOfForeignClinicScheduleMustFailClosed(): void
    {
        $notice = $this->dispatchAdminAction(
            'cpms_schedule_delete',
            [
                'clinician_id' => (string) $this->clinicianA,
                'schedule_id' => (string) $this->scheduleAId,
            ],
            fn () => ClinicianAdminPage::deleteSchedule()
        );

        $row = $this->fetchScheduleRow($this->scheduleAId);
        $slotExists = $this->slotExists($this->futureEmptySlotAId);

        $this->assertNotNull(
            $row,
            'C7-PRE/A1 wp-admin schedule delete: مرز wp-admin استثنای مجوز نیست — حذف برنامهٔ Clinic A '
            . 'باید fail-closed شود. واقعی: ردیف برنامه DELETED (جهش بین‌کلینیکی از مسیر admin رخ داد)؛ '
            . 'admin_notice="' . $notice . '"; victim_future_empty_slot_exists=' . ($slotExists ? 'yes' : 'NO')
        );
        $this->assertSame('09:00:00', (string) $row['start_time'], 'زمان شروع برنامهٔ قربانی نباید تغییر کند');
        $this->assertSame('13:00:00', (string) $row['end_time'], 'زمان پایان برنامهٔ قربانی نباید تغییر کند');
        $this->assertTrue(
            $slotExists,
            'Slot خالی آیندهٔ پزشک Clinic A به‌دلیل عملیات wp-admin مدیر Clinic B نباید حذف شود '
            . '(admin_notice="' . $notice . '")'
        );
    }

    /**
     * C7-PRE/A2 — ClinicianAdminPage::deleteException با شناسهٔ استثنای Clinic A.
     *
     * خاصیت: همان انکار fail-closed برای حذف استثنا از مسیر REAL اکشن admin.
     */
    public function testWpAdminDeleteOfForeignClinicScheduleExceptionMustFailClosed(): void
    {
        $notice = $this->dispatchAdminAction(
            'cpms_exception_delete',
            [
                'clinician_id' => (string) $this->clinicianA,
                'exception_id' => (string) $this->exceptionAId,
            ],
            fn () => ClinicianAdminPage::deleteException()
        );

        $row = $this->fetchExceptionRow($this->exceptionAId);
        $slotExists = $this->slotExists($this->futureEmptySlotAId);

        $this->assertNotNull(
            $row,
            'C7-PRE/A2 wp-admin exception delete: حذف استثنای Clinic A از مسیر admin باید fail-closed شود. '
            . 'واقعی: ردیف استثنا DELETED (جهش بین‌کلینیکی از مسیر admin رخ داد)؛ '
            . 'admin_notice="' . $notice . '"; victim_future_empty_slot_exists=' . ($slotExists ? 'yes' : 'NO')
        );
        $this->assertSame('holiday', (string) $row['type'], 'نوع استثنای قربانی نباید تغییر کند');
        $this->assertTrue(
            $slotExists,
            'Slot خالی آیندهٔ پزشک Clinic A به‌دلیل عملیات wp-admin مدیر Clinic B نباید حذف شود '
            . '(admin_notice="' . $notice . '")'
        );
    }

    /**
     * C7-PRE/A3 — ClinicianAdminPage::saveSchedules با clinician_id پزشکِ Clinic A.
     *
     * خاصیت: مدیر wp-admin Clinic B با ارسال شناسهٔ پزشک Clinic A (و روزِ برنامهٔ
     * او) از مسیر REAL اکشن admin نباید بتواند برنامهٔ آن پزشک را ویرایش کند.
     * فرم admin برنامه را با کلید (clinician_id, day) انتخاب می‌کند — شناسهٔ
     * ورودیِ کلاینت همین clinician_id است و ردیفِ یافت‌شده متعلق به Clinic A است.
     * انتظار: ردیف بدون تغییر؛ Slot آیندهٔ خالی قربانی موجود.
     */
    public function testWpAdminUpdateOfForeignClinicScheduleMustFailClosed(): void
    {
        $notice = $this->dispatchAdminAction(
            'cpms_schedule_save',
            [
                'clinician_id' => (string) $this->clinicianA,
                'sched_submit' => ['3' => '1'],
                'sched' => [
                    3 => [
                        'start_time' => '22:00',
                        'end_time' => '23:30',
                        'appointment_duration_min' => '20',
                        'slot_capacity' => '1',
                        'is_active' => '1',
                    ],
                ],
            ],
            fn () => ClinicianAdminPage::saveSchedules()
        );

        $row = $this->fetchScheduleRow($this->scheduleAId);
        $slotExists = $this->slotExists($this->futureEmptySlotAId);

        $this->assertNotNull($row, 'ردیف برنامهٔ Clinic A نباید حذف شود');
        $this->assertSame(
            '09:00:00',
            (string) $row['start_time'],
            'C7-PRE/A3 wp-admin schedule update: ویرایش برنامهٔ Clinic A از مسیر admin باید fail-closed شود. '
            . 'واقعی: start_time="' . (string) $row['start_time'] . '" (انتظار 09:00:00 دست‌نخورده)؛ '
            . 'admin_notice="' . $notice . '"; victim_future_empty_slot_exists=' . ($slotExists ? 'yes' : 'NO')
        );
        $this->assertSame('13:00:00', (string) $row['end_time'], 'زمان پایان برنامهٔ قربانی نباید تغییر کند');
        $this->assertTrue(
            $slotExists,
            'Slot خالی آیندهٔ پزشک Clinic A به‌دلیل عملیات wp-admin مدیر Clinic B نباید حذف شود '
            . '(admin_notice="' . $notice . '")'
        );
    }

    // ================= کاندیدای B — فاکتور بر پایهٔ ویزیت خارجی =================

    /**
     * C7-PRE/B — FinanceService::issueInvoice از مسیر REST با visit_id ورودی.
     *
     * خاصیت: منشی Clinic B (با INVOICE_CREATE و Scope معتبر B) نباید بتواند
     * برای ویزیتِ پایان‌یافتهٔ Clinic A فاکتور صادر کند. انتظار: 404 +
     * CLINIC_NOT_FOUND با پاکت یکسان با ویزیتِ ناموجود؛ هیچ ردیف فاکتوری برای
     * ویزیت قربانی؛ وضعیت ویزیت قربانی همچنان consultation_completed؛ بدون
     * افشای نام/MRN بیمار Clinic A در پاسخ.
     */
    public function testIssueInvoiceForForeignClinicVisitMustFailClosed(): void
    {
        $visitId = $this->makeCompletedVictimVisit();

        wp_set_current_user($this->secretaryB);
        $res = $this->dispatch('POST', self::NS . '/invoices', [
            'visit_id' => $visitId,
            'items' => [['description' => 'c7-pre attack item', 'unit_price' => 50000]],
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $invoice = $this->fetchInvoiceForVisit($visitId);
        $visit = $this->fetchVisitRow($visitId);

        $this->assertSame(
            404,
            $status,
            'C7-PRE/B issueInvoice: صدور فاکتور برای ویزیت Clinic A از Scope B باید fail-closed شود. '
            . "واقعی: HTTP {$status} code={$code}; "
            . 'victim_invoice=' . ($invoice === null ? 'NONE' : $this->sig($invoice)) . '; '
            . 'victim_visit_status=' . (string) $visit['status']
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertNull($invoice, 'هیچ ردیف فاکتوری برای ویزیت قربانی نباید ثبت شود');
        $this->assertSame(
            'consultation_completed',
            (string) $visit['status'],
            'وضعیت ویزیت قربانی نباید به‌دلیل عملیات Clinic B به awaiting_payment تغییر کند'
        );

        $body = (string) json_encode($res->get_data());
        $this->assertStringNotContainsString($this->patientAMrn, $body, 'MRN بیمار Clinic A نباید فاش شود');
        $this->assertStringNotContainsString($this->patientAName, $body, 'نام بیمار Clinic A نباید فاش شود');

        // پاکت 404 باید با «ویزیت ناموجود» بایت‌به‌بایت هم‌پاکت باشد (عدم شمارش).
        $missing = $this->dispatch('POST', self::NS . '/invoices', [
            'visit_id' => 424242424,
            'items' => [['description' => 'x', 'unit_price' => 1]],
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);
        $this->assertSame($missing->get_status(), $status, 'پاسخ ویزیت خارجی و ویزیت ناموجود باید هم‌وضعیت باشد');
        $this->assertSame($this->errorCode($missing), $code, 'پاسخ ویزیت خارجی و ویزیت ناموجود باید هم‌کد باشد');
    }

    // ================= کاندیدای C — تعرفهٔ خارجی: ویرایش/غیرفعال‌سازی =================

    /**
     * C7-PRE/C1 — FinanceService::updateService از مسیر REST با id ورودی.
     *
     * خاصیت: مدیر Clinic B (با cpms_config و Scope معتبر B) نباید بتواند
     * تعرفهٔ Clinic A را ویرایش کند. انتظار: 404 + CLINIC_NOT_FOUND هم‌پاکت با
     * تعرفهٔ ناموجود؛ ردیف تعرفهٔ قربانی بدون تغییر (code/name/price)؛ بدون
     * ردیف Audit جدید برای این تعرفه توسط مهاجم.
     *
     * نکتهٔ قرارداد: «no-op بی‌صدا با پاسخ موفق» هم نقض همین خاصیت است — پاسخ
     * موفق برای شیء خارجی، وجود شیء را افشا می‌کند (oracle شمارش) و قرارداد
     * fail-closed ‏404-parity محصول را نقض می‌کند.
     */
    public function testUpdateOfForeignClinicServiceMustFailClosed(): void
    {
        $auditBefore = $this->countServiceSettingAudits($this->serviceAId);

        wp_set_current_user($this->managerB);
        $res = $this->dispatch('PUT', self::NS . '/config/services/' . $this->serviceAId, [
            'code' => 'C7PRE-HIJACK',
            'name' => 'تعرفه هک‌شده C7-PRE',
            'price' => 1,
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $row = $this->fetchServiceRow($this->serviceAId);
        $auditAfter = $this->countServiceSettingAudits($this->serviceAId);

        $this->assertSame(
            404,
            $status,
            'C7-PRE/C1 service update: ویرایش تعرفهٔ Clinic A از Scope B باید fail-closed شود. '
            . "واقعی: HTTP {$status} code={$code} (پاسخ موفق برای شیء خارجی = oracle وجود + نقض 404-parity)؛ "
            . 'victim_service=' . ($row === null ? 'GONE' : $this->sig($row))
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertNotNull($row, 'ردیف تعرفهٔ قربانی نباید حذف شود');
        $this->assertSame('C7PRE-VICTIM', (string) $row['code'], 'کد تعرفهٔ قربانی نباید تغییر کند');
        $this->assertSame('تعرفه قربانی C7-PRE', (string) $row['name'], 'نام تعرفهٔ قربانی نباید تغییر کند');
        $this->assertSame(250000.0, (float) $row['price'], 'قیمت تعرفهٔ قربانی نباید تغییر کند');
        $this->assertSame(
            $auditBefore,
            $auditAfter,
            'هیچ ردیف Audit جدیدی برای تعرفهٔ Clinic A توسط عملیات Clinic B نباید ثبت شود'
        );

        // پاکت 404 باید با «تعرفهٔ ناموجود» هم‌پاکت باشد (عدم شمارش).
        $missing = $this->dispatch('PUT', self::NS . '/config/services/999999999', [
            'code' => 'C7PRE-HIJACK',
            'name' => 'x',
            'price' => 1,
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);
        $this->assertSame($missing->get_status(), $status, 'پاسخ تعرفهٔ خارجی و تعرفهٔ ناموجود باید هم‌وضعیت باشد');
        $this->assertSame($this->errorCode($missing), $code, 'پاسخ تعرفهٔ خارجی و تعرفهٔ ناموجود باید هم‌کد باشد');
    }

    /**
     * C7-PRE/C2 — FinanceService::deactivateService از مسیر REST با id ورودی.
     *
     * خاصیت: مدیر Clinic B نباید بتواند تعرفهٔ Clinic A را غیرفعال کند.
     * انتظار: 404 + CLINIC_NOT_FOUND؛ is_active قربانی همچنان 1؛ بدون Audit جدید.
     */
    public function testDeactivateOfForeignClinicServiceMustFailClosed(): void
    {
        $auditBefore = $this->countServiceSettingAudits($this->serviceAId);

        wp_set_current_user($this->managerB);
        $res = $this->dispatch('DELETE', self::NS . '/config/services/' . $this->serviceAId, [], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $row = $this->fetchServiceRow($this->serviceAId);
        $auditAfter = $this->countServiceSettingAudits($this->serviceAId);

        $this->assertSame(
            404,
            $status,
            'C7-PRE/C2 service deactivate: غیرفعال‌سازی تعرفهٔ Clinic A از Scope B باید fail-closed شود. '
            . "واقعی: HTTP {$status} code={$code} (پاسخ موفق برای شیء خارجی = oracle وجود + نقض 404-parity)؛ "
            . 'victim_service=' . ($row === null ? 'GONE' : $this->sig($row))
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertNotNull($row, 'ردیف تعرفهٔ قربانی نباید حذف شود');
        $this->assertSame(
            1,
            (int) $row['is_active'],
            'تعرفهٔ Clinic A نباید به‌دلیل عملیات Clinic B غیرفعال شود'
        );
        $this->assertSame(
            $auditBefore,
            $auditAfter,
            'هیچ ردیف Audit جدیدی برای تعرفهٔ Clinic A توسط عملیات Clinic B نباید ثبت شود'
        );
    }

    // ================= C7-S4 — پوشش مثبت/ابهام (حداقلی، طبق برش) =================

    /**
     * C7-S4/P1 — عملیات مشروع wp-admin با تک‌عضویت فعال.
     *
     * مدیر Clinic B با تنها عضویت فعالش روی B، برنامهٔ «خودیِ» Clinic B را از
     * مسیر واقعی اکشن admin حذف می‌کند ⇒ موفق (ردیف حذف + notice موفق) و
     * بدون نشت به اشیای Clinic A.
     */
    public function testWpAdminDeleteOfOwnClinicScheduleSucceeds(): void
    {
        $doctorB = $this->makeUser('c7pre_doc_b', 'cpms_doctor');
        $clinicianB = $this->insertClinician(self::CLINIC_B, $doctorB, 'Dr C7 Pre Boundary B');
        $scheduleB = $this->withScope(self::CLINIC_B, fn (): array => App::scheduleService()->create(
            $this->managerB,
            [
                'clinician_id' => $clinicianB,
                'day_of_week' => 4,
                'start_time' => '10:00',
                'end_time' => '14:00',
                'appointment_duration_min' => 20,
                'slot_capacity' => 1,
            ]
        ));
        $scheduleBId = (int) $scheduleB['id'];
        self::assertGreaterThan(0, $scheduleBId, 'پیش‌شرط: برنامهٔ خودی B ساخته شود');

        $notice = $this->dispatchAdminAction(
            'cpms_schedule_delete',
            [
                'clinician_id' => (string) $clinicianB,
                'schedule_id' => (string) $scheduleBId,
            ],
            fn () => ClinicianAdminPage::deleteSchedule()
        );

        $this->assertNull(
            $this->fetchScheduleRow($scheduleBId),
            'C7-S4/P1: حذف برنامهٔ خودیِ Clinic B با تک‌عضویت فعال باید موفق باشد. notice="' . $notice . '"'
        );
        $this->assertStringContainsString('حذف شد', $notice, 'notice موفق انتظار می‌رود');
        $this->assertNotNull(
            $this->fetchScheduleRow($this->scheduleAId),
            'اشیای Clinic A نباید در عملیات خودی B دست بخورند'
        );
    }

    /**
     * C7-S4/P2 — بدون عضویت فعال ⇒ fail-closed (capability/nonce کافی نیست).
     */
    public function testWpAdminScheduleActionWithoutActiveMembershipFailsClosed(): void
    {
        $noMember = $this->makeUser('c7pre_nomgr', 'cpms_manager');

        $notice = $this->dispatchAdminAction(
            'cpms_schedule_delete',
            [
                'clinician_id' => (string) $this->clinicianA,
                'schedule_id' => (string) $this->scheduleAId,
            ],
            fn () => ClinicianAdminPage::deleteSchedule(),
            $noMember
        );

        $row = $this->fetchScheduleRow($this->scheduleAId);
        $slotExists = $this->slotExists($this->futureEmptySlotAId);
        $this->assertNotNull(
            $row,
            'C7-S4/P2: کاربر cpms_config بدون هیچ عضویت فعال باید fail-closed شود. '
            . 'واقعی: ردیف DELETED؛ admin_notice="' . $notice . '"'
        );
        $this->assertSame('09:00:00', (string) $row['start_time'], 'برنامهٔ قربانی نباید تغییر کند');
        $this->assertTrue($slotExists, 'Slot قربانی نباید حذف شود');
        $this->assertStringContainsString('خطا', $notice, 'notice خطا (نه موفق) انتظار می‌رود');
    }

    /**
     * C7-S4/P3 — عضویت فعال چندگانه بدون انتخاب صریح ⇒ fail-closed (بدون
     * انتخاب بی‌صدای اولین/پیش‌فرض؛ UX سوییچر خارج از این برش است).
     */
    public function testWpAdminScheduleActionWithAmbiguousMembershipsFailsClosed(): void
    {
        $multiMember = $this->makeUser('c7pre_multimgr', 'cpms_manager');
        cpms_test_seed_membership($multiMember, self::CLINIC_A, 'cpms_manager');
        cpms_test_seed_membership($multiMember, self::CLINIC_B, 'cpms_manager');

        $notice = $this->dispatchAdminAction(
            'cpms_schedule_delete',
            [
                'clinician_id' => (string) $this->clinicianA,
                'schedule_id' => (string) $this->scheduleAId,
            ],
            fn () => ClinicianAdminPage::deleteSchedule(),
            $multiMember
        );

        $row = $this->fetchScheduleRow($this->scheduleAId);
        $slotExists = $this->slotExists($this->futureEmptySlotAId);
        $this->assertNotNull(
            $row,
            'C7-S4/P3: عضویت فعال چندگانه بدون انتخاب صریح باید fail-closed شود (حتی برای شیء کلینیک خودِ کاربر). '
            . 'واقعی: ردیف DELETED؛ admin_notice="' . $notice . '"'
        );
        $this->assertSame('09:00:00', (string) $row['start_time'], 'برنامهٔ قربانی نباید تغییر کند');
        $this->assertTrue($slotExists, 'Slot قربانی نباید حذف شود');
        $this->assertStringContainsString('خطا', $notice, 'notice خطا (نه موفق) انتظار می‌رود');
    }

    /**
     * C7-S4/P4 — issueInvoice بدون هیچ Scope معتبر ⇒ CLINIC_SCOPE_REQUIRED
     * و بدون هیچ اثر مالی (همان قرارداد C7-S3 برای عملیات حساس مالی).
     */
    public function testIssueInvoiceWithoutTrustedClinicContextFailsClosed(): void
    {
        $visitId = $this->makeCompletedVictimVisit();

        wp_set_current_user($this->secretaryA);
        App::resetScope();
        self::assertNull(ScopeContext::tryGet(), 'پیش‌شرط: هیچ Scope صریحی برقرار نیست');

        $thrown = null;
        try {
            App::financeService()->issueInvoice($this->secretaryA, [
                'visit_id' => $visitId,
                'items' => [['description' => 'c7-s4 no-scope', 'unit_price' => 1000]],
            ]);
        } catch (FinanceException $e) {
            $thrown = $e;
        }

        $this->assertNotNull(
            $thrown,
            'C7-S4/P4: issueInvoice بدون زمینهٔ کلینیک معتبر باید fail-closed شود'
        );
        $this->assertSame('CLINIC_SCOPE_REQUIRED', $thrown->errorCode, 'کد کانونی قرارداد نبودِ Scope');
        $this->assertSame(400, $thrown->httpStatus, 'وضعیت HTTP استانداردِ CLINIC_SCOPE_REQUIRED');
        $this->assertNull($this->fetchInvoiceForVisit($visitId), 'هیچ فاکتوری نباید ثبت شود');
        $this->assertSame(
            'consultation_completed',
            (string) $this->fetchVisitRow($visitId)['status'],
            'وضعیت ویزیت قربانی نباید تغییر کند'
        );
    }

    /**
     * C7-S4/P5 — صدور فاکتور هم-کلینیک از مسیر واقعی REST همچنان کار می‌کند.
     */
    public function testIssueInvoiceForOwnClinicVisitSucceeds(): void
    {
        $visitId = $this->makeCompletedVictimVisit();

        wp_set_current_user($this->secretaryA);
        $res = $this->dispatch('POST', self::NS . '/invoices', [
            'visit_id' => $visitId,
            'items' => [['description' => 'ویزیت خودی C7-S4', 'unit_price' => 80000]],
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_A,
        ]);

        $invoice = $this->fetchInvoiceForVisit($visitId);
        $this->assertSame(
            201,
            $res->get_status(),
            'C7-S4/P5: صدور فاکتور هم-کلینیک باید موفق بماند. واقعی: HTTP ' . $res->get_status()
        );
        $this->assertNotNull($invoice, 'فاکتور هم-کلینیک باید ثبت شود');
        $this->assertSame(self::CLINIC_A, (int) $invoice['clinic_id'], 'فاکتور باید به Clinic A تعلق داشته باشد');
        $this->assertSame(80000.0, (float) $invoice['total'], 'مبلغ فاکتور صحیح باشد');
        $this->assertSame(
            'awaiting_payment',
            (string) $this->fetchVisitRow($visitId)['status'],
            'گذار V11 مشروع (consultation_completed → awaiting_payment) باید انجام شود'
        );
    }

    /**
     * C7-S4/P6 — ویرایش تعرفهٔ خودی از مسیر واقعی REST همچنان کار می‌کند.
     */
    public function testUpdateOfOwnClinicServiceSucceeds(): void
    {
        $service = $this->withScope(self::CLINIC_B, fn (): array => App::financeService()->createService(
            $this->managerB,
            ['code' => 'C7PRE-OWN-B', 'name' => 'تعرفهٔ خودی B', 'price' => 100000]
        ));
        $serviceId = (int) $service['id'];
        self::assertGreaterThan(0, $serviceId, 'پیش‌شرط: تعرفهٔ خودی B ساخته شود');

        wp_set_current_user($this->managerB);
        $res = $this->dispatch('PUT', self::NS . '/config/services/' . $serviceId, [
            'price' => 120000,
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $row = $this->fetchServiceRow($serviceId);
        $this->assertSame(
            200,
            $res->get_status(),
            'C7-S4/P6: ویرایش تعرفهٔ خودی باید موفق بماند. واقعی: HTTP ' . $res->get_status()
        );
        $this->assertSame(120000.0, (float) $row['price'], 'قیمت تعرفهٔ خودی باید به‌روز شود');
    }

    /**
     * C7-S4/P7 — غیرفعال‌سازی تعرفهٔ خودی از مسیر واقعی REST همچنان کار می‌کند.
     */
    public function testDeactivateOfOwnClinicServiceSucceeds(): void
    {
        $service = $this->withScope(self::CLINIC_B, fn (): array => App::financeService()->createService(
            $this->managerB,
            ['code' => 'C7PRE-OWN-B2', 'name' => 'تعرفهٔ خودی B (۲)', 'price' => 90000]
        ));
        $serviceId = (int) $service['id'];
        self::assertGreaterThan(0, $serviceId, 'پیش‌شرط: تعرفهٔ خودی B ساخته شود');

        wp_set_current_user($this->managerB);
        $res = $this->dispatch('DELETE', self::NS . '/config/services/' . $serviceId, [], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $row = $this->fetchServiceRow($serviceId);
        $this->assertSame(
            200,
            $res->get_status(),
            'C7-S4/P7: غیرفعال‌سازی تعرفهٔ خودی باید موفق بماند. واقعی: HTTP ' . $res->get_status()
        );
        $this->assertSame(0, (int) $row['is_active'], 'تعرفهٔ خودی باید غیرفعال شود');
    }

    // ================= C7-CLOSEOUT — دو کاندیدای مجاور نهایی (فقط شواهد) =================

    /**
     * C7-CLOSEOUT/1a — wp-admin: ساخت برنامه برای پزشکِ Clinic A با clinician_id خارجی.
     *
     * خاصیت: مدیر wp-admin Clinic B (تک‌عضویت فعال B، nonce/capability معتبر —
     * مسیر REAL اکشن admin، زمینهٔ معتبر پس از C7-S4 به B حل می‌شود) نباید
     * بتواند برای پزشکِ Clinic A برنامه بسازد. clinician_id ورودیِ فرم «انتخاب
     * شیء» است نه tenant context — کلینیک هرگز از ردیف پزشک گرفته نمی‌شود.
     * انتظار: انکار غیرافشا (معادل پزشک ناموجود)؛ بدون ردیف برنامه برای
     * پزشک A؛ Slot خالی آیندهٔ پزشک A موجود (بدون regenerate قربانی).
     */
    public function testWpAdminScheduleCreateWithForeignClinicianMustFailClosed(): void
    {
        // روز ۵ — برای پزشک A رکوردی ندارد ⇒ مسیر create واقعی handler.
        $notice = $this->dispatchAdminAction(
            'cpms_schedule_save',
            [
                'clinician_id' => (string) $this->clinicianA,
                'sched_submit' => ['5' => '1'],
                'sched' => [
                    5 => [
                        'start_time' => '22:00',
                        'end_time' => '23:30',
                        'appointment_duration_min' => '20',
                        'slot_capacity' => '1',
                        'is_active' => '1',
                    ],
                ],
            ],
            fn () => ClinicianAdminPage::saveSchedules()
        );

        $row = $this->fetchScheduleRowForClinicianDay($this->clinicianA, 5);
        $slotExists = $this->slotExists($this->futureEmptySlotAId);

        $this->assertNull(
            $row,
            'C7-CLOSEOUT/1a wp-admin schedule create: ساخت برنامه برای پزشک Clinic A از مرز admin باید '
            . 'fail-closed شود — clinician_id فرم هرگز tenant context نیست. واقعی: ردیف با clinic_id='
            . ($row === null ? 'NONE' : (string) $row['clinic_id']) . ' درج شد؛ admin_notice="' . $notice . '"; '
            . 'victim_future_empty_slot_exists=' . ($slotExists ? 'yes' : 'NO (regenerate قربانی اجرا شد)')
        );
        $this->assertTrue(
            $slotExists,
            'Slot خالی آیندهٔ پزشک Clinic A به‌دلیل عملیات wp-admin مدیر Clinic B نباید حذف/بازتولید شود '
            . '(admin_notice="' . $notice . '")'
        );
    }

    /**
     * C7-CLOSEOUT/1b — REST: ساخت برنامه برای پزشکِ Clinic A از Scope معتبر B.
     *
     * خاصیت: مدیر Clinic B (cpms_config + Scope معتبر B از مرز REST) با ارسال
     * clinician_id پزشک A در POST /config/schedules نباید برنامه‌ای برای
     * کلینیک پزشک (A) بسازد. انتظار: 404 + CLINIC_NOT_FOUND هم‌پاکت با
     * «پزشک ناموجود»؛ بدون ردیف؛ بدون regenerate قربانی.
     */
    public function testRestScheduleCreateWithForeignClinicianMustFailClosed(): void
    {
        wp_set_current_user($this->managerB);
        $res = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianA,
            'day_of_week' => 5,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'appointment_duration_min' => 20,
            'slot_capacity' => 1,
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $row = $this->fetchScheduleRowForClinicianDay($this->clinicianA, 5);
        $slotExists = $this->slotExists($this->futureEmptySlotAId);

        $this->assertSame(
            404,
            $status,
            'C7-CLOSEOUT/1b REST schedule create: ساخت برنامه برای پزشک Clinic A از Scope B باید fail-closed '
            . 'شود. واقعی: HTTP ' . $status . ' code=' . $code . '؛ ردیف clinic_id='
            . ($row === null ? 'NONE' : (string) $row['clinic_id']) . '؛ victim_future_empty_slot_exists='
            . ($slotExists ? 'yes' : 'NO (regenerate قربانی اجرا شد)')
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertNull($row, 'هیچ ردیف برنامه‌ای برای پزشک Clinic A نباید درج شود');
        $this->assertTrue($slotExists, 'Slot خالی آیندهٔ پزشک Clinic A نباید حذف/بازتولید شود');

        // پاکت 404 باید با «پزشک ناموجود» هم‌پاکت باشد (عدم شمارش).
        $missing = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => 424242424,
            'day_of_week' => 5,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);
        $this->assertSame($missing->get_status(), $status, 'پاسخ پزشک خارجی و پزشک ناموجود باید هم‌وضعیت باشد');
        $this->assertSame($this->errorCode($missing), $code, 'پاسخ پزشک خارجی و پزشک ناموجود باید هم‌کد باشد');
    }

    /**
     * C7-CLOSEOUT/2 — صدور فاکتور با service_id تعرفهٔ Clinic A در اقلام.
     *
     * خاصیت: منشی Clinic B (Scope معتبر B) برای ویزیت/بیمارِ «خودیِ» B فاکتور
     * صادر می‌کند اما یکی از اقلام به service_id تعرفهٔ Clinic A ارجاع می‌دهد.
     * B نباید بتواند هویت/نام/کد/قیمت تعرفهٔ A (پیکربندی دامنه‌بندی‌شدهٔ A) را
     * به دادهٔ مالی خود وارد کند. انتظار: 404 + CLINIC_NOT_FOUND هم‌پاکت با
     * «خدمت انتخاب‌شده یافت نشد»؛ هیچ ردیف فاکتور/قلمی نمی‌ماند (تراکنشی)؛
     * ویزیت B دست‌نخورده؛ تعرفهٔ A دست‌نخورده؛ بدون نشت نام/قیمت A در پاسخ.
     */
    public function testIssueInvoiceWithForeignClinicServiceIdMustFailClosed(): void
    {
        // fixture مشروع سمت B — ویزیت/بیمار/پزشک همگی Clinic B.
        $doctorB = $this->makeUser('c7clo_doc_b', 'cpms_doctor');
        cpms_test_seed_membership($doctorB, self::CLINIC_B, 'cpms_doctor');
        $clinicianB = $this->insertClinician(self::CLINIC_B, $doctorB, 'Dr C7 Closeout B');
        $patientB = $this->insertPatient(self::CLINIC_B);
        $visitBId = $this->withScope(self::CLINIC_B, function () use ($doctorB, $clinicianB, $patientB): int {
            $visit = App::visitService()->walkIn($this->secretaryB, $patientB['id'], $clinicianB);
            $id = (int) $visit['id'];
            App::visitService()->transition($doctorB, $id, 'call');
            App::visitService()->transition($doctorB, $id, 'start');
            App::clinicalService()->addNote($doctorB, $id, [
                'category' => 'chief_complaint',
                'visibility' => 'patient_visible',
                'content_text' => 'ویزیت خودی B (fixture closeout)',
            ]);
            App::clinicalService()->completeConsultation($doctorB, $id);

            return $id;
        });

        wp_set_current_user($this->secretaryB);
        $res = $this->dispatch('POST', self::NS . '/invoices', [
            'visit_id' => $visitBId,
            'items' => [['service_id' => $this->serviceAId, 'quantity' => 1]],
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $invoice = $this->fetchInvoiceForVisit($visitBId);
        $items = $invoice === null ? [] : $this->fetchInvoiceItems((int) $invoice['id']);
        $serviceRow = $this->fetchServiceRow($this->serviceAId);

        $this->assertSame(
            404,
            $status,
            'C7-CLOSEOUT/2 issueInvoice با service_id خارجی: قلمِ ارجاع‌دهنده به تعرفهٔ Clinic A باید '
            . 'fail-closed شود (تعرفهٔ خارجی = یافت‌نشد برای B). واقعی: HTTP ' . $status . ' code=' . $code
            . '؛ invoice=' . ($invoice === null ? 'NONE' : $this->sig($invoice))
            . '؛ items=' . ($items === [] ? 'NONE' : $this->sig($items[0]))
            . '؛ victim_service=' . $this->sig($serviceRow)
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertNull($invoice, 'هیچ ردیف فاکتوری نباید برای ویزیت B باقی بماند');
        $this->assertSame(
            'consultation_completed',
            (string) $this->fetchVisitRow($visitBId)['status'],
            'وضعیت ویزیت B (معتبر) نباید به‌دلیل قلم خارجی تغییر کند'
        );
        $this->assertSame('C7PRE-VICTIM', (string) $serviceRow['code'], 'کد تعرفهٔ قربانی A نباید تغییر کند');
        $this->assertSame(250000.0, (float) $serviceRow['price'], 'قیمت تعرفهٔ قربانی A نباید تغییر کند');
        $this->assertSame(1, (int) $serviceRow['is_active'], 'وضعیت تعرفهٔ قربانی A نباید تغییر کند');

        $body = (string) json_encode($res->get_data());
        $this->assertStringNotContainsString('تعرفه قربانی C7-PRE', $body, 'نام تعرفهٔ Clinic A نباید فاش شود');
        $this->assertStringNotContainsString('C7PRE-VICTIM', $body, 'کد تعرفهٔ Clinic A نباید فاش شود');

        // پاکت 404 باید با «service_id ناموجود» هم‌پاکت باشد (عدم شمارش).
        $missing = $this->dispatch('POST', self::NS . '/invoices', [
            'visit_id' => $visitBId,
            'items' => [['service_id' => 424242424, 'quantity' => 1]],
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);
        $this->assertSame($missing->get_status(), $status, 'پاسخ تعرفهٔ خارجی و تعرفهٔ ناموجود باید هم‌وضعیت باشد');
        $this->assertSame($this->errorCode($missing), $code, 'پاسخ تعرفهٔ خارجی و تعرفهٔ ناموجود باید هم‌کد باشد');
    }

    // ================= Helpers — dispatch مسیر واقعی wp-admin =================

    /**
     * اجرای REAL اکشن admin_post با کاربر مدیر Clinic B: گارد (capability +
     * nonce معتبر) → بدنهٔ handler (فراخوانی واقعی سرویس) → ثبت notice →
     * redirect. چون redirect() در پایان exit می‌کند، با فیلتر wp_redirect که
     * استثنا پرتاب می‌کند، exit شبیه‌سازی می‌شود — تمام جهش‌های DB پیش از آن
     * انجام شده‌اند. خروجی: متن notice ثبت‌شده (شاهد مسیر موفق/خطا).
     *
     * @param array<string, mixed> $post
     */
    private function dispatchAdminAction(string $nonceAction, array $post, callable $handler, ?int $userId = null): string
    {
        wp_set_current_user($userId ?? $this->managerB);
        $_POST = $post;
        $_REQUEST['_wpnonce'] = wp_create_nonce($nonceAction);
        delete_transient('cpms_clinic_notice');

        $redirect = static function ($location) {
            throw new \RuntimeException('C7_ADMIN_REDIRECT_EXIT_SIMULATED:' . (string) $location);
        };
        add_filter('wp_redirect', $redirect);
        try {
            $handler();
            self::fail('اکشن admin بدون redirect پایان یافت — قرارداد handler نقض شده است');
        } catch (\RuntimeException $e) {
            // مسیر کامل handler (گارد → سرویس → notice → redirect) اجرا شد؛ exit شبیه‌سازی گردید.
        } finally {
            remove_filter('wp_redirect', $redirect);
            $_POST = [];
            unset($_REQUEST['_wpnonce']);
        }

        return (string) get_transient('cpms_clinic_notice');
    }

    // ================= Helpers — fixture قربانی (مسیر واقعی سرویس) =================

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

    /**
     * ویزیت پایان‌یافتهٔ مشروع Clinic A — از مسیر واقعی سرویس توسط کاربران A.
     */
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
                'content_text' => 'درد و تهوع (fixture قربانی C7-PRE)',
            ]);
            App::clinicalService()->completeConsultation($this->doctorA, $id);

            return $id;
        });
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

    // ================= Helpers — شاهدهای DB =================

    /**
     * @return array<string, mixed>|null
     */
    private function fetchScheduleRow(int $id): ?array
    {
        return App::db()->fetchRow(
            'SELECT id, clinic_id, day_of_week, start_time, end_time, is_active FROM '
            . App::db()->table('cpms_schedule') . ' WHERE id = %d',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchExceptionRow(int $id): ?array
    {
        return App::db()->fetchRow(
            'SELECT id, clinic_id, clinician_id, date, type FROM '
            . App::db()->table('cpms_schedule_exceptions') . ' WHERE id = %d',
            [$id]
        );
    }

    private function slotExists(int $slotId): bool
    {
        return App::db()->fetchRow(
            'SELECT id FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        ) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchScheduleRowForClinicianDay(int $clinicianId, int $day): ?array
    {
        return App::db()->fetchRow(
            'SELECT id, clinic_id, clinician_id, day_of_week, start_time, end_time FROM '
            . App::db()->table('cpms_schedule') . ' WHERE clinician_id = %d AND day_of_week = %d',
            [$clinicianId, $day]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchServiceRow(int $id): ?array
    {
        return App::db()->fetchRow(
            'SELECT id, clinic_id, code, name, price, is_active FROM '
            . App::db()->table('cpms_services') . ' WHERE id = %d',
            [$id]
        );
    }

    private function countServiceSettingAudits(int $serviceId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
            . " WHERE action = 'SETTING_UPDATE' AND resource_type = 'service' AND resource_id = %d",
            [$serviceId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchInvoiceForVisit(int $visitId): ?array
    {
        return App::db()->fetchRow(
            'SELECT id, clinic_id, patient_id, visit_id, status, total FROM '
            . App::db()->table('cpms_invoices') . ' WHERE visit_id = %d',
            [$visitId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchVisitRow(int $visitId): array
    {
        $row = App::db()->fetchRow(
            'SELECT id, clinic_id, patient_id, status FROM ' . App::db()->table('cpms_visits')
            . ' WHERE id = %d',
            [$visitId]
        );
        self::assertNotNull($row, 'پیش‌شرط: ویزیت قربانی در DB وجود دارد');

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchInvoiceItems(int $invoiceId): array
    {
        return App::db()->fetchAll(
            'SELECT id, service_id, description, quantity, unit_price, amount FROM '
            . App::db()->table('cpms_invoice_items') . ' WHERE invoice_id = %d',
            [$invoiceId]
        ) ?: [];
    }

    // ================= Helpers — fixture پایه (الگوی C7) =================

    private function seedFutureEmptySlot(int $clinicId, int $locationId, int $clinicianId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $date = gmdate('Y-m-d', (time() + 7 * 86400));
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, location_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
                 VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, "lazy", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $clinicianId,
                $locationId,
                $date,
                '10:00',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
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
        $mrn = 'MR-C7PRE-' . bin2hex(random_bytes(3));
        $first = 'C7PreVictim';
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
     * پاک‌سازی دفاعی fixture در محدودهٔ رزرو — الگوی C7 (ادغام جداول مالی + برنامه).
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
            'cpms_slot_holds' => 'WHERE slot_id IN (SELECT id FROM ' . $p . 'cpms_schedule_slots WHERE clinic_id >= 61000)',
            'cpms_schedule_slots' => $pure,
            'cpms_schedule_exceptions' => $pure,
            'cpms_schedule' => $pure,
            'cpms_services' => $pure,
            'cpms_payments' => $inv,
            'cpms_payment_adjustments' => $inv,
            'cpms_invoice_items' => $inv,
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
