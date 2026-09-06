<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Queue\JobQueue;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * تست‌های گزارش/Export F8 — FR-19.2/19.3 + G5 + ADR-0026 (D-8/D-15) + ماتریس §6.
 *
 * پوشش:
 *  - cpms_report_read فقط پزشک (پیش‌فرض)؛ منشی 403
 *  - Scope سرور-side: پزشک متصل = OWN — داده پزشک دیگر هرگز (cross-doctor isolation)
 *  - Aggregate مطب فقط با اعطای صریح (کاربر بدون Clinician-Link + Caps)
 *  - تفکیک Aggregate⊥Detail (D-8): مالی نیاز finance_read و بدون نام بیمار؛
 *    عملیاتیِ دارای نام بیمار نیاز patient_read؛ follow_ups نیاز medical_read
 *  - Export: cpms_export هیچ‌کس پیش‌فرض؛ جریان async (Job → فایل + اعلان)؛
 *    دانلود فقط مالک + Audit EXPORT (request/download)
 *  - CSV: BOM + محافظت Formula-Injection (FR-21.x)
 *  - Private Doctor Notes هرگز در گزارش‌ها نشت نمی‌کند (FR-16.5 analog)
 *  - Print View با Watermark (FR-19.3)
 */
final class ReportsAuthzTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private const PRIVATE_MARKER = 'SECRET-PRIVATE-NOTE-MARKER-98765';

    private int $clinicianAId = 0;
    private int $clinicianBId = 0;
    private int $doctorAUserId;
    private int $doctorBUserId;
    private int $secretaryUserId;
    private int $accountantUserId; // report_read + finance_read (اعطای صریح، بدون Clinician-Link)
    private int $opsUserId; // فقط report_read (بدون patient_read/finance_read)
    private int $patientAId = 0;
    private int $patientBId = 0;
    private int $visitAId;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        // از یک timestamp واحد برای تاریخ‌ها (درس flaky نیمه‌شب F7)
        $this->today = gmdate('Y-m-d');

        $this->secretaryUserId = $this->makeUser('rp_sec', 'cpms_secretary');
        $this->doctorAUserId = $this->makeUser('rp_docA', 'cpms_doctor');
        $this->doctorBUserId = $this->makeUser('rp_docB', 'cpms_doctor');
        $this->accountantUserId = $this->makeUser('rp_acc', 'subscriber');
        $this->opsUserId = $this->makeUser('rp_ops', 'subscriber');

        // اعطای صریح Capability به کاربر غیر-نقشی (ADR-0026 — الگوی حسابدار ماتریس §6)
        $acc = get_userdata($this->accountantUserId);
        $acc?->add_cap('cpms_report_read');
        $acc?->add_cap('cpms_finance_read');
        $ops = get_userdata($this->opsUserId);
        $ops?->add_cap('cpms_report_read');

        global $wpdb;
        $now = App::db()->nowUtcSql();

        // پزشک A (متصل به doctorA) و پزشک B (متصل به doctorB)
        foreach ([['Dr Own', $this->doctorAUserId, &$this->clinicianAId], ['Dr Other', $this->doctorBUserId, &$this->clinicianBId]] as $c) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                         (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                     VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $c[0],
                    $c[1],
                    $now,
                    $now
                )
            );
            $c[2] = (int) $wpdb->insert_id;
        }

        // بیمار A با نام مخصوص تست Formula-Injection
        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-RP-' . $seq,
                '=HYPERLINK("http://evil.example","click")',
                'RP-A',
                '0915' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );
        $this->patientAId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-RPB-' . $seq,
                'PatientB',
                'RP-B',
                '0916' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );
        $this->patientBId = (int) $wpdb->insert_id;

        $this->seedOperationalAndFinancialData();
    }

    // ================= Catalog + Scope =================

    public function testCatalogListsTwelveTypesForDoctorWithOwnScope(): void
    {
        wp_set_current_user($this->doctorAUserId);
        $res = $this->dispatch('GET', self::NS . '/reports');
        $this->assertSame(200, $res->get_status());
        $data = $this->payload($res);
        $this->assertCount(12, $data['reports']);
        $this->assertSame('own', $data['reports'][0]['scope']);
        $ids = array_column($data['reports'], 'type');
        $this->assertContains('revenue', $ids);
        $this->assertContains('follow_ups_due', $ids);
        foreach ($data['reports'] as $r) {
            $this->assertTrue($r['available'], 'پزشک همه Capهای نوع را دارد: ' . $r['type']);
        }
    }

    public function testSecretaryDeniedByDefault(): void
    {
        wp_set_current_user($this->secretaryUserId);
        $res = $this->dispatch('GET', self::NS . '/reports');
        $this->assertSame(403, $res->get_status());
        $this->assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($res));
    }

    public function testDoctorScopeIsOwnServerSide(): void
    {
        wp_set_current_user($this->doctorAUserId);

        // ویزیت‌ها — فقط پزشک A (۲ ویزیت seed شده)؛ ویزیت B هرگز
        $res = $this->dispatch('GET', self::NS . '/reports/visits', ['from' => $this->today, 'to' => $this->today]);
        $this->assertSame(200, $res->get_status());
        $data = $this->payload($res);
        $this->assertSame('own', $data['scope']);
        $this->assertCount(2, $data['rows']);
        foreach ($data['rows'] as $row) {
            $this->assertStringNotContainsString('PatientB', (string) $row['patient_name'], 'cross-doctor leak');
        }

        // درآمد — فقط پرداخت A (100,000)؛ پرداخت B (250,000) هرگز
        $rev = $this->payload($this->dispatch('GET', self::NS . '/reports/revenue', ['from' => $this->today, 'to' => $this->today]));
        $this->assertSame(100000, $rev['summary']['net']);
        $this->assertSame(1, $rev['summary']['payment_count']);

        // نوبت‌ها — فقط A
        $appts = $this->payload($this->dispatch('GET', self::NS . '/reports/appointments_today'));
        $this->assertSame(2, $appts['summary']['count']);
    }

    public function testAggregateClinicScopeOnlyByExplicitGrant(): void
    {
        // حسابدار (report_read + finance_read، بدون Clinician-Link) → Aggregate مطب
        wp_set_current_user($this->accountantUserId);
        $rev = $this->dispatch('GET', self::NS . '/reports/revenue', ['from' => $this->today, 'to' => $this->today]);
        $this->assertSame(200, $rev->get_status());
        $data = $this->payload($rev);
        $this->assertSame('clinic', $data['scope']);
        $this->assertSame(350000, $data['summary']['net'], '100k (A) + 250k (B)');
        $this->assertSame(2, $data['summary']['payment_count']);

        // Aggregate بدون نام بیمار (D-8) — هیچ ردیفی patient_name ندارد
        $this->assertArrayNotHasKey('patient_name', $data['rows'][0]);

        // کاربر فقط-report_read: مالی 403 (بدون finance_read)
        wp_set_current_user($this->opsUserId);
        $denied = $this->dispatch('GET', self::NS . '/reports/revenue', ['from' => $this->today, 'to' => $this->today]);
        $this->assertSame(403, $denied->get_status());
        $this->assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($denied));

        // عملیاتیِ دارای نام بیمار: بدون patient_read → 403 (فقط داده اعطاشده صریح)
        $visits = $this->dispatch('GET', self::NS . '/reports/visits', ['from' => $this->today, 'to' => $this->today]);
        $this->assertSame(403, $visits->get_status());

        // Aggregate عملیاتیِ بدون PHI (میانگین انتظار) → مجاز
        $wait = $this->dispatch('GET', self::NS . '/reports/avg_waiting', ['from' => $this->today, 'to' => $this->today]);
        $this->assertSame(200, $wait->get_status());
        $waitData = $this->payload($wait);
        $this->assertSame('clinic', $waitData['scope']);
        $this->assertSame(3, $waitData['summary']['visits'], 'ویزیت‌های A (۲) + B (۱) — Aggregate مطب');
    }

    public function testDoctorFinancialReportDoesNotGrantClinicalAccess(): void
    {
        // D-8: دسترسی مالی → هیچ داده بالینی؛ revenue فقط عدد است (تست منفی:
        // محتوای پاسخ revenue نباید فیلد بالینی داشته باشد)
        wp_set_current_user($this->doctorAUserId);
        $rev = $this->payload($this->dispatch('GET', self::NS . '/reports/revenue', ['from' => $this->today, 'to' => $this->today]));
        $flat = json_encode($rev, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('chief_complaint', (string) $flat);
        $this->assertStringNotContainsString('diagnosis', (string) $flat);
    }

    // ================= Report correctness (Aggregate) =================

    public function testAggregateReportsComputeCorrectly(): void
    {
        wp_set_current_user($this->doctorAUserId);

        $wait = $this->payload($this->dispatch('GET', self::NS . '/reports/avg_waiting', ['from' => $this->today, 'to' => $this->today]));
        // ویزیت‌های A: 600s + 300s → میانگین 450s (ویزیت B خارج scope پزشک A)
        $this->assertSame(450, $wait['summary']['avg_sec']);
        $this->assertSame(2, $wait['summary']['visits']);

        $dur = $this->payload($this->dispatch('GET', self::NS . '/reports/visit_duration', ['from' => $this->today, 'to' => $this->today]));
        $this->assertSame(900, $dur['summary']['avg_sec'], '15m مشاوره ویزیت A');

        $methods = $this->payload($this->dispatch('GET', self::NS . '/reports/payment_methods', ['from' => $this->today, 'to' => $this->today]));
        $cash = null;
        foreach ($methods['rows'] as $r) {
            if ($r['method'] === 'cash') {
                $cash = $r;
            }
        }
        $this->assertNotNull($cash);
        $this->assertSame(100000, (int) $cash['amount']);
        $this->assertSame(1, (int) $cash['payments']);

        $open = $this->payload($this->dispatch('GET', self::NS . '/reports/open_balances', ['from' => $this->today, 'to' => $this->today]));
        $this->assertSame(50000, $open['summary']['total_balance']);
        $this->assertSame(1, $open['summary']['invoice_count']);
        $this->assertArrayNotHasKey('patient_name', $open['rows'][0], 'D-8: بدون نام بیمار');

        $noshow = $this->payload($this->dispatch('GET', self::NS . '/reports/no_shows', ['from' => $this->today, 'to' => $this->today]));
        $this->assertSame(1, $noshow['summary']['count']);

        $walkins = $this->payload($this->dispatch('GET', self::NS . '/reports/walk_ins', ['from' => $this->today, 'to' => $this->today]));
        $this->assertSame(1, $walkins['summary']['count']);

        $cancels = $this->payload($this->dispatch('GET', self::NS . '/reports/cancellations', ['from' => $this->today, 'to' => $this->today]));
        $this->assertSame(1, $cancels['summary']['count']);

        $fus = $this->payload($this->dispatch('GET', self::NS . '/reports/follow_ups_due', ['from' => $this->today, 'to' => $this->today]));
        $this->assertSame(1, $fus['summary']['count']);
    }

    public function testRangeValidationIsBounded(): void
    {
        wp_set_current_user($this->doctorAUserId);
        $res = $this->dispatch('GET', self::NS . '/reports/visits', ['from' => '2020-01-01', 'to' => $this->today]);
        $this->assertSame(422, $res->get_status());
        $this->assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($res));

        $bad = $this->dispatch('GET', self::NS . '/reports/visits', ['from' => 'notadate', 'to' => $this->today]);
        $this->assertSame(422, $bad->get_status());

        $unknown = $this->dispatch('GET', self::NS . '/reports/does_not_exist');
        $this->assertSame(404, $unknown->get_status());
    }

    // ================= Private Note leak (قاعده کارفرما) =================

    public function testPrivateDoctorNotesNeverLeakIntoReports(): void
    {
        wp_set_current_user($this->doctorAUserId);

        foreach ([
            'appointments_today', 'appointments_week', 'visits', 'cancellations', 'no_shows',
            'walk_ins', 'avg_waiting', 'visit_duration', 'revenue', 'payment_methods',
            'open_balances', 'follow_ups_due',
        ] as $type) {
            $res = $this->dispatch('GET', self::NS . '/reports/' . $type);
            $this->assertSame(200, $res->get_status(), "report {$type} باید 200 باشد");
            $flat = json_encode($this->payload($res), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString(self::PRIVATE_MARKER, (string) $flat, "Private Note در {$type} نشت کرد!");
        }
    }

    // ================= Export (FR-19.3) =================

    public function testExportFlowAuthorizationAuditAndFormulaInjection(): void
    {
        // cpms_export = هیچ‌کس پیش‌فرض → پزشک هم 403
        wp_set_current_user($this->doctorAUserId);
        $denied = $this->dispatch('POST', self::NS . '/reports/visits/export', [
            'from' => $this->today,
            'to' => $this->today,
        ]);
        $this->assertSame(403, $denied->get_status());
        $this->assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($denied));

        // اعطای موردی cpms_export → 202 (فقط enqueue — async طبق baseline §18)
        $doc = get_userdata($this->doctorAUserId);
        $doc?->add_cap('cpms_export');

        $req = $this->dispatch('POST', self::NS . '/reports/visits/export', [
            'from' => $this->today,
            'to' => $this->today,
        ]);
        $this->assertSame(202, $req->get_status());
        $reqData = $this->payload($req);
        $this->assertSame('queued', $reqData['status']);
        $jobId = (int) $reqData['job_id'];
        $this->assertGreaterThan(0, $jobId);

        // Audit اکشن EXPORT (فاز request)
        $this->assertAuditExportCount(1);

        // اجرای Job از مسیر Dispatcher واقعی
        App::dispatcher()->tick(5);
        $this->assertSame('success', (string) $this->scalar(
            "SELECT status FROM {cpms_jobs} WHERE id = {$jobId}"
        ));

        // «فایل + اعلان» — اعلان آماده‌شدن به درخواست‌دهنده
        $notifId = (int) $this->scalar(
            "SELECT id FROM {cpms_notifications} WHERE template = 'report_export_ready' AND recipient_wp_user_id = {$this->doctorAUserId}"
        );
        $this->assertGreaterThan(0, $notifId);

        $list = $this->payload($this->dispatch('GET', self::NS . '/reports/exports'));
        $this->assertCount(1, $list['exports']);
        $this->assertSame('visits', $list['exports'][0]['type']);

        // دانلود محافظت‌شده — مالک
        $download = $this->dispatch('GET', self::NS . '/reports/exports/' . $notifId . '/download');
        $this->assertSame(200, $download->get_status());
        $csv = (string) $download->get_data();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM برای Excel فارسی');
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'محافظت Formula-Injection (پیشوند آپاستروف)');
        $this->assertStringContainsString('visits', $csv);
        $this->assertStringContainsString('summary_key', $csv);

        // Audit اکشن EXPORT (فاز download)
        $this->assertAuditExportCount(2);

        // دانلود توسط دیگری → 404 (مالکیت سرور-side)
        $docB = get_userdata($this->doctorBUserId);
        $docB?->add_cap('cpms_report_read');
        $docB?->add_cap('cpms_export');
        wp_set_current_user($this->doctorBUserId);
        $notYours = $this->dispatch('GET', self::NS . '/reports/exports/' . $notifId . '/download');
        $this->assertSame(404, $notYours->get_status());
    }

    public function testExportWithoutReportReadCapDenied(): void
    {
        // cpms_export بدون report_read کافی نیست
        $subscriber = $this->makeUser('rp_plain', 'subscriber');
        $u = get_userdata($subscriber);
        $u?->add_cap('cpms_export');
        wp_set_current_user($subscriber);

        $res = $this->dispatch('POST', self::NS . '/reports/visits/export', [
            'from' => $this->today,
            'to' => $this->today,
        ]);
        $this->assertSame(403, $res->get_status());
    }

    // ================= Print View (Watermark) =================

    public function testPrintViewContainsWatermarkAndRows(): void
    {
        wp_set_current_user($this->doctorAUserId);
        $res = $this->dispatch('GET', self::NS . '/reports/visits/print', ['from' => $this->today, 'to' => $this->today]);
        $this->assertSame(200, $res->get_status());
        $html = (string) $res->get_data();
        $this->assertStringContainsString('watermark', $html);
        $this->assertStringContainsString('rp_docA', $html, 'Watermark = کاربر چاپ‌کننده');
        $this->assertStringContainsString('patient_name', $html);
        $this->assertStringNotContainsString('PatientB', $html, 'own-scope در چاپ هم اعمال می‌شود');

        // منشی → 403
        wp_set_current_user($this->secretaryUserId);
        $denied = $this->dispatch('GET', self::NS . '/reports/visits/print');
        $this->assertSame(403, $denied->get_status());
    }

    // ================= Seed =================

    private function seedOperationalAndFinancialData(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $today = $this->today;
        $seq = random_int(1000, 999999);

        // یک Slot برای FK نوبت‌ها
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, "10:00:00", 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianAId,
                $today,
                $now,
                $now
            )
        );
        $slotId = (int) $wpdb->insert_id;

        // نوبت‌های A: confirmed + no_show | نوبت B: confirmed | لغو A (دیروز)
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                 (clinic_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, status, confirmed_at, no_show_at, cancelled_at, cancel_reason, booked_at, created_at, updated_at)
             VALUES (1, %s, %d, %d, %d, %s, "10:00:00", "confirmed", %s, NULL, NULL, NULL, %s, %s, %s)',
            ['AP-RP-' . $seq . '-1', $this->clinicianAId, $this->patientAId, $slotId, $today, $now, $now, $now, $now]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $apptA1 = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                 (clinic_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, status, no_show_at, booked_at, created_at, updated_at)
             VALUES (1, %s, %d, %d, %d, %s, "11:00:00", "no_show", %s, %s, %s, %s)',
            ['AP-RP-' . $seq . '-2', $this->clinicianAId, $this->patientAId, $slotId, $today, $now, $now, $now, $now]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                 (clinic_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, status, booked_at, created_at, updated_at)
             VALUES (1, %s, %d, %d, %d, %s, "10:00:00", "confirmed", %s, %s, %s)',
            ['AP-RP-' . $seq . '-3', $this->clinicianBId, $this->patientBId, $slotId, $today, $now, $now, $now]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                 (clinic_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, status, cancelled_at, cancel_reason, cancelled_by_wp_user_id, booked_at, created_at, updated_at)
             VALUES (1, %s, %d, %d, %d, %s, "09:00:00", "cancelled_by_staff", %s, "عدم حضور", %d, %s, %s, %s)',
            ['AP-RP-' . $seq . '-4', $this->clinicianAId, $this->patientAId, $slotId, $today, $now, $this->secretaryUserId, $now, $now, $now]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // ویزیت A: walk-in منتظر (10:00 → called 10:10؛ در ویزیت 10:15→10:30)
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                 (clinic_id, clinician_id, patient_id, appointment_id, source, status, visit_date, check_in_at, waiting_since, called_at, consultation_started_at, consultation_completed_at, active, created_at, updated_at)
             VALUES (1, %d, %d, NULL, "walk_in", "in_consultation", %s, %s, %s, %s, %s, %s, 1, %s, %s)',
            [
                $this->clinicianAId, $this->patientAId,
                $today,
                $today . ' 10:00:00.000', $today . ' 10:00:00.000', $today . ' 10:10:00.000',
                $today . ' 10:15:00.000', $today . ' 10:30:00.000',
                $now, $now,
            ]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->visitAId = (int) $wpdb->insert_id;

        // ویزیت A دوم: scheduled checked_out (برای شمارش ۲تایی own-scope)
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                 (clinic_id, clinician_id, patient_id, appointment_id, source, status, visit_date, check_in_at, waiting_since, called_at, checked_out_at, active, created_at, updated_at)
             VALUES (1, %d, %d, %d, "scheduled", "checked_out", %s, %s, %s, %s, %s, 0, %s, %s)',
            [
                $this->clinicianAId, $this->patientAId, $apptA1,
                $today,
                $today . ' 09:00:00.000', $today . ' 09:00:00.000', $today . ' 09:05:00.000', $today . ' 09:40:00.000',
                $now, $now,
            ]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // ویزیت B: walk_in (10:05 → 10:06 = 60s انتظار)
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                 (clinic_id, clinician_id, patient_id, appointment_id, source, status, visit_date, check_in_at, waiting_since, called_at, active, created_at, updated_at)
             VALUES (1, %d, %d, NULL, "walk_in", "waiting", %s, %s, %s, %s, 1, %s, %s)',
            [
                $this->clinicianBId, $this->patientBId,
                $today,
                $today . ' 10:05:00.000', $today . ' 10:05:00.000', $today . ' 10:06:00.000',
                $now, $now,
            ]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $visitBId = (int) $wpdb->insert_id;

        // فاکتور + پرداخت A: 100,000 نقدی (paid)
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_invoices
                 (clinic_id, invoice_number, patient_id, visit_id, status, subtotal, total, paid_amount, balance, issued_by_wp_user_id, created_at, updated_at)
             VALUES (1, %s, %d, %d, "paid", 100000, 100000, 100000, 0, %d, %s, %s)',
            ['INV-RP-' . $seq . '-1', $this->patientAId, $this->visitAId, $this->secretaryUserId, $now, $now]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $invA = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_payments
                 (clinic_id, payment_number, invoice_id, patient_id, amount, method, idempotency_key, status, paid_at, received_by_wp_user_id, created_at)
             VALUES (1, %s, %d, %d, 100000, "cash", %s, "captured", %s, %d, %s)',
            ['PAY-RP-' . $seq . '-1', $invA, $this->patientAId, 'idem-rp-' . $seq . '-1', $today . ' 12:00:00.000', $this->secretaryUserId, $now]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // فاکتور + پرداخت B: 250,000 کارت‌خوان (paid)
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_invoices
                 (clinic_id, invoice_number, patient_id, visit_id, status, subtotal, total, paid_amount, balance, issued_by_wp_user_id, created_at, updated_at)
             VALUES (1, %s, %d, %d, "paid", 250000, 250000, 250000, 0, %d, %s, %s)',
            ['INV-RP-' . $seq . '-2', $this->patientBId, $visitBId, $this->secretaryUserId, $now, $now]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $invB = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_payments
                 (clinic_id, payment_number, invoice_id, patient_id, amount, method, idempotency_key, status, paid_at, received_by_wp_user_id, created_at)
             VALUES (1, %s, %d, %d, 250000, "card_pos", %s, "captured", %s, %d, %s)',
            ['PAY-RP-' . $seq . '-2', $invB, $this->patientBId, 'idem-rp-' . $seq . '-2', $today . ' 12:30:00.000', $this->secretaryUserId, $now]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // فاکتور بازِ A: 50,000 (open balance)
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_invoices
                 (clinic_id, invoice_number, patient_id, visit_id, status, subtotal, total, paid_amount, balance, issued_by_wp_user_id, created_at, updated_at)
             VALUES (1, %s, %d, %d, "open", 50000, 50000, 0, 50000, %d, %s, %s)',
            ['INV-RP-' . $seq . '-3', $this->patientAId, $this->visitAId, $this->secretaryUserId, $now, $now]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // Follow-Up سررسید A
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups
                 (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at)
             VALUES (1, %d, %d, %d, 1, %s, 30, %s, "pending", %s)',
            [$this->visitAId, $this->patientAId, $this->clinicianAId, $today, 'کنترل یک‌ماهه', $now]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // Private Doctor Note روی ویزیت A — هرگز نباید در گزارش‌ها بیاید
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinical_notes
                 (clinic_id, visit_id, patient_id, clinician_id, category, visibility, content_text, version, created_by_wp_user_id, created_at, updated_at)
             VALUES (1, %d, %d, %d, "private_note", "doctor_private", %s, 1, %d, %s, %s)',
            [$this->visitAId, $this->patientAId, $this->clinicianAId, self::PRIVATE_MARKER . ' — نکته خصوصی پزشک', $this->doctorAUserId, $now, $now]
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    // ================= Helpers =================

    private function assertAuditExportCount(int $min): void
    {
        $count = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            ['EXPORT']
        );
        $this->assertGreaterThanOrEqual($min, $count, 'Audit EXPORT');
    }

    private function scalar(string $sqlWithBraceTable): mixed
    {
        global $wpdb;
        $sql = preg_replace('/\{([a-z_]+)\}/', $wpdb->prefix . '$1', $sqlWithBraceTable);

        return $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Response $res): array
    {
        $body = $res->get_data();
        if (is_array($body) && array_key_exists('data', $body)) {
            return is_array($body['data']) ? $body['data'] : [];
        }

        return is_array($body) ? $body : [];
    }

    private function errorCode(WP_REST_Response $res): string
    {
        $body = $res->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code();
        }

        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
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
     * @param array<string, mixed> $body
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
