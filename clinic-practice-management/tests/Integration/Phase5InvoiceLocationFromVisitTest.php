<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Phase 5 — Slice 1: ثبت Location فاکتور از Visit و تثبیت رفتارهای مالی موجود.
 *
 * قرارداد:
 *  - invoice.location_id از visits.location_id همان Visit گرفته می‌شود.
 *  - Location هرگز از request payload گرفته نمی‌شود.
 *  - Visit باید در trusted Clinic فعلی معتبر باشد.
 *  - invoiceView() باید location_id ذخیره‌شده را برگرداند.
 *  - رفتار manual unit_price override دست‌نخورده.
 *  - fallback به service.price دست‌نخورده.
 *  - invoice item snapshot دست‌نخورده.
 *  - فاکتورهای تاریخی با location_id=NULL بدون تغییر باقی بمانند.
 *
 * Invariants:
 *  1. Clinic فقط از trusted server context می‌آید.
 *  2. payload منبع clinic_id یا location_id معتبر نیست.
 *  3. invoice مربوط به Visit باید location_id را فقط از همان Visit معتبر بگیرد.
 *  4. هیچ first-row/default/fake Location مجاز نیست.
 *  5. cross-Clinic service_id باید همچنان fail closed و دارای not-found parity باشد.
 *  6. manual unit_price override فعلی باید حفظ شود.
 *  7. در نبود unit_price، رفتار fallback فعلی به service.price حفظ شود.
 *  8. invoice_items snapshotهای تاریخی نباید به تغییر بعدی service وابسته شوند.
 *  9. هیچ فاکتور تاریخی backfill یا بازنویسی نشود.
 * 10. هیچ سیاست جدیدی برای service.is_active یا services.location_id غیرNULL ایجاد نشود.
 */
final class Phase5InvoiceLocationFromVisitTest extends WP_UnitTestCase
{
    private ?ScopeContext $previousScope = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('clinical.require_chief_complaint', true);

        $this->previousScope = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic(1));
    }

    protected function tearDown(): void
    {
        App::replaceExplicitScope($this->previousScope);
        $this->previousScope = null;
        parent::tearDown();
    }

    // ================= سناریوی اصلی: invoice.location_id === visit.location_id =================

    /**
     * سناریوی اصلی Phase 5 Slice 1:
     * Clinic A دارای حداقل دو Location است. Visit در Location دوم ایجاد می‌شود
     * تا انتخاب اتفاقی اولین Location نتواند تست را پاس کند.
     * پس از صدور فاکتور:
     *  - persisted invoice.location_id === visit.location_id
     *  - invoiceView().location_id === visit.location_id
     */
    public function testInvoiceLocationIdPersistedFromVisitNotPrimary(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        // ── Fixture: کاربران و عضویت‌ها ──
        $secretaryUserId = $this->makeUser('p5_sec', 'cpms_secretary');
        cpms_test_seed_membership($secretaryUserId, 1, 'cpms_secretary');
        $doctorUserId = $this->makeUser('p5_doc', 'cpms_doctor');
        cpms_test_seed_membership($doctorUserId, 1, 'cpms_doctor');
        $adminUserId = $this->makeUser('p5_adm', 'administrator');
        cpms_test_seed_membership($adminUserId, 1, 'cpms_manager');

        // ── Fixture: Clinician ──
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr P5',
                $doctorUserId,
                $now,
                $now
            )
        );
        $clinicianId = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $clinicianId, 'clinician fixture inserted');

        // ── Fixture: Location دوم (غیر اصلی) ──
        // Location اصلی از قبل توسط migration seed شده است. یک Location دوم می‌سازیم.
        $primaryLocId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1 AND is_primary = 1 ORDER BY id LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $this->assertGreaterThan(0, $primaryLocId, 'primary location exists');

        $slug = 'p5-loc2-' . bin2hex(random_bytes(3));
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                     (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (1, %s, %s, %s, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Location Secondary',
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $secondLocId = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $secondLocId, 'second location inserted');
        $this->assertNotSame($primaryLocId, $secondLocId, 'second location differs from primary');

        // ── Fixture: بیمار ──
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-P5-' . bin2hex(random_bytes(2)),
                'Phase5',
                'Patient',
                '0912' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $patientId, 'patient fixture inserted');

        // ── Fixture: Visit (WalkIn → primary location) سپس update به Location دوم ──
        $visit = App::visitService()->walkIn($secretaryUserId, $patientId, $clinicianId);
        $visitId = (int) $visit['id'];
        $this->assertGreaterThan(0, $visitId, 'visit created');

        // ویزیت را به Location دوم منتقل می‌کنیم تا تست، انتخاب تصادفی primary
        // location را تشخیص دهد.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_visits SET location_id = %d WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $secondLocId,
                $visitId
            )
        );

        // تأیید پیش‌شرط: visit.location_id اکنون Location دوم است
        $visitLocAfter = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT location_id FROM ' . $wpdb->prefix . 'cpms_visits WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $visitId
            )
        );
        $this->assertSame($secondLocId, $visitLocAfter, 'visit.location_id is second location');

        // ── Fixture: ویزیت را تا consultation_completed پیش می‌بریم ──
        App::visitService()->transition($doctorUserId, $visitId, 'call');
        App::visitService()->transition($doctorUserId, $visitId, 'start');
        App::clinicalService()->addNote($doctorUserId, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'درد شکم',
        ]);
        App::clinicalService()->completeConsultation($doctorUserId, $visitId);

        // ── عمل: صدور فاکتور ──
        $invoice = App::financeService()->issueInvoice($secretaryUserId, [
            'visit_id' => $visitId,
            'items' => [['description' => 'ویزیت', 'unit_price' => 300000]],
        ]);

        $invoiceId = (int) $invoice['id'];
        $this->assertGreaterThan(0, $invoiceId, 'invoice created');

        // ── انتظار: persisted invoice.location_id === visit.location_id ──
        $persistedLocId = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT location_id FROM ' . $wpdb->prefix . 'cpms_invoices WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $invoiceId
            )
        );
        $this->assertNotNull($persistedLocId, 'invoice.location_id is NOT NULL');
        $this->assertSame($secondLocId, (int) $persistedLocId, 'persisted invoice.location_id === visit.location_id (second location)');

        // ── انتظار: invoiceView().location_id === visit.location_id ──
        $this->assertArrayHasKey('location_id', $invoice, 'invoiceView returns location_id');
        $this->assertSame($secondLocId, (int) $invoice['location_id'], 'invoiceView().location_id === visit.location_id');

        // ── تأیید: invoiceView از DB هم همین را برمی‌گرداند ──
        $view = App::financeService()->invoiceView($invoiceId);
        $this->assertArrayHasKey('location_id', $view, 'invoiceView has location_id key');
        $this->assertSame($secondLocId, (int) $view['location_id'], 'invoiceView().location_id from DB === visit.location_id');
    }

    // ================= تثبیت رفتار manual unit_price override =================

    /**
     * Invariant 6: manual unit_price override باید حفظ شود.
     * اگر unit_price در payload قلم داده شود، همان مقدار snapshot می‌شود
     * (نه service.price).
     */
    public function testManualUnitPriceOverrideSnapshotPreserved(): void
    {
        $fx = $this->makeBasicFixtures();

        // service.price = 500000، اما override = 350000
        $invoice = App::financeService()->issueInvoice($fx['secretary'], [
            'visit_id' => $fx['visit_id'],
            'items' => [
                ['service_id' => $fx['service_id'], 'quantity' => 1, 'unit_price' => 350000],
            ],
        ]);

        $this->assertCount(1, $invoice['items']);
        $this->assertSame(350000.0, $invoice['items'][0]['unit_price'], 'manual override unit_price is snapshot');
        $this->assertSame(350000.0, $invoice['subtotal'], 'subtotal reflects manual override');
    }

    // ================= تثبیت رفتار fallback به service.price =================

    /**
     * Invariant 7: در نبود unit_price، service.price استفاده شود.
     */
    public function testFallbackToServicePriceWhenNoUnitPriceProvided(): void
    {
        $fx = $this->makeBasicFixtures();

        // بدون unit_price در payload — باید از service.price = 500000 استفاده شود
        $invoice = App::financeService()->issueInvoice($fx['secretary'], [
            'visit_id' => $fx['visit_id'],
            'items' => [
                ['service_id' => $fx['service_id'], 'quantity' => 1],
            ],
        ]);

        $this->assertCount(1, $invoice['items']);
        $this->assertSame(500000.0, $invoice['items'][0]['unit_price'], 'fallback to service.price');
        $this->assertSame(500000.0, $invoice['subtotal'], 'subtotal reflects service.price');
    }

    // ================= Invariant 8: snapshot immutability =================

    /**
     * Invariant 8: پس از تغییر service.price، invoice_items فاکتور قبلی تغییر نکند.
     */
    public function testInvoiceItemSnapshotImmutableAfterServicePriceChange(): void
    {
        $fx = $this->makeBasicFixtures();

        // صدور فاکتور با service.price فعلی (500000)
        $invoice = App::financeService()->issueInvoice($fx['secretary'], [
            'visit_id' => $fx['visit_id'],
            'items' => [
                ['service_id' => $fx['service_id'], 'quantity' => 1],
            ],
        ]);
        $invoiceId = (int) $invoice['id'];
        $this->assertSame(500000.0, $invoice['items'][0]['unit_price']);

        // تغییر service.price به 700000
        App::financeService()->updateService($fx['admin'], $fx['service_id'], ['price' => 700000]);

        // فاکتور قبلی نباید تغییر کند
        $view = App::financeService()->invoiceView($invoiceId);
        $this->assertSame(500000.0, $view['items'][0]['unit_price'], 'snapshot immutable after service.price change');
        $this->assertSame(500000.0, $view['subtotal'], 'subtotal unchanged after service.price change');
    }

    // ================= Invariant 5: cross-Clinic service_id fail-closed =================

    /**
     * Invariant 5: cross-Clinic service_id باید fail closed باشد (404 parity).
     */
    public function testCrossClinicServiceIdFailClosed(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        // ── Clinic B ──
        $clinicB = 802;
        $orgId = (int) $wpdb->get_var(
            'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $this->assertGreaterThan(0, $orgId);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicB,
                $orgId,
                'Clinic B P5',
                'clinic-b-p5-' . bin2hex(random_bytes(3)),
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->assertSame($clinicB, (int) $wpdb->insert_id);

        // Location for Clinic B
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicB,
                'Loc B P5',
                'loc-b-p5-' . bin2hex(random_bytes(3)),
                'Asia/Tehran',
                $now,
                $now
            )
        );

        // Service in Clinic B
        $secB = $this->makeUser('p5_sec_b', 'cpms_secretary');
        cpms_test_seed_membership($secB, $clinicB, 'cpms_secretary');
        $admB = $this->makeUser('p5_adm_b', 'administrator');
        cpms_test_seed_membership($admB, $clinicB, 'cpms_manager');

        $prevScope = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic($clinicB));
        $svcB = App::financeService()->createService($admB, ['code' => 'P5-SVC-B', 'name' => 'خدمت B', 'price' => 200000]);
        $svcBId = (int) $svcB['id'];
        App::replaceExplicitScope($prevScope);

        // ── Clinic A (clinic_id=1): Visit + attempt to use Clinic B's service ──
        $fx = $this->makeBasicFixtures();

        App::replaceExplicitScope(ClinicScope::forClinic(1));
        try {
            App::financeService()->issueInvoice($fx['secretary'], [
                'visit_id' => $fx['visit_id'],
                'items' => [
                    ['service_id' => $svcBId, 'quantity' => 1],
                ],
            ]);
            $this->fail('Expected CLINIC_NOT_FOUND for cross-Clinic service_id');
        } catch (FinanceException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode, 'cross-Clinic service_id fail-closed');
            $this->assertSame(404, $e->httpStatus);
        }
    }

    // ================= Invariant 9: فاکتورهای تاریخی backfill نشوند =================

    /**
     * Invariant 9: فاکتورهای تاریخی با location_id=NULL بدون تغییر باقی بمانند.
     * این تست تأیید می‌کند که کد محصول، فاکتورهای قدیمی را backfill نمی‌کند.
     */
    public function testHistoricalInvoicesWithNullLocationNotBackfilled(): void
    {
        global $wpdb;

        $fx = $this->makeBasicFixtures();

        // صدور فاکتور (که حالا location_id دارد)
        $invoice = App::financeService()->issueInvoice($fx['secretary'], [
            'visit_id' => $fx['visit_id'],
            'items' => [['description' => 'ویزیت', 'unit_price' => 100000]],
        ]);
        $invoiceId = (int) $invoice['id'];

        // شبیه‌سازی فاکتور تاریخی: location_id را NULL می‌کنیم
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_invoices SET location_id = NULL WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $invoiceId
            )
        );

        // خواندن فاکتور: location_id باید null بماند (هیچ backfill خودکاری رخ نداده)
        $view = App::financeService()->invoiceView($invoiceId);
        $this->assertNull($view['location_id'], 'historical NULL location_id not backfilled');

        // تأیید مستقیم DB
        $dbLoc = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT location_id FROM ' . $wpdb->prefix . 'cpms_invoices WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $invoiceId
            )
        );
        $this->assertNull($dbLoc, 'DB location_id remains NULL for historical invoice');
    }

    // ================= Invariant 2: payload منبع location_id نیست =================

    /**
     * Invariant 2: حتی اگر payload شامل location_id باشد، نادیده گرفته شود
     * و location_id فقط از Visit گرفته شود.
     */
    public function testLocationIdNeverTakenFromPayload(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        // ── Fixture ──
        $secretaryUserId = $this->makeUser('p5p_sec', 'cpms_secretary');
        cpms_test_seed_membership($secretaryUserId, 1, 'cpms_secretary');
        $doctorUserId = $this->makeUser('p5p_doc', 'cpms_doctor');
        cpms_test_seed_membership($doctorUserId, 1, 'cpms_doctor');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr P5P',
                $doctorUserId,
                $now,
                $now
            )
        );
        $clinicianId = (int) $wpdb->insert_id;

        // Location دوم (غیر اصلی)
        $primaryLocId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1 AND is_primary = 1 ORDER BY id LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        $slug = 'p5p-loc2-' . bin2hex(random_bytes(3));
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                     (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (1, %s, %s, %s, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Loc Secondary P5P',
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $secondLocId = (int) $wpdb->insert_id;
        $this->assertNotSame($primaryLocId, $secondLocId);

        // بیمار
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-P5P-' . bin2hex(random_bytes(2)),
                'Phase5P',
                'Payload',
                '0912' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;

        // Visit در Location دوم
        $visit = App::visitService()->walkIn($secretaryUserId, $patientId, $clinicianId);
        $visitId = (int) $visit['id'];
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_visits SET location_id = %d WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $secondLocId,
                $visitId
            )
        );

        App::visitService()->transition($doctorUserId, $visitId, 'call');
        App::visitService()->transition($doctorUserId, $visitId, 'start');
        App::clinicalService()->addNote($doctorUserId, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'سردرد',
        ]);
        App::clinicalService()->completeConsultation($doctorUserId, $visitId);

        // صدور فاکتور با location_id جعلی در payload (primary location)
        $invoice = App::financeService()->issueInvoice($secretaryUserId, [
            'visit_id' => $visitId,
            'location_id' => $primaryLocId, // ← باید نادیده گرفته شود
            'items' => [['description' => 'ویزیت', 'unit_price' => 200000]],
        ]);

        // location_id فاکتور باید از Visit باشد (secondLocId)، نه از payload
        $this->assertSame($secondLocId, (int) $invoice['location_id'], 'location_id from visit, not payload');
    }

    // ================= Helpers =================

    /**
     * @return array{secretary: int, doctor: int, admin: int, visit_id: int, service_id: int, clinician: int}
     */
    private function makeBasicFixtures(): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        $secretary = $this->makeUser('p5b_sec', 'cpms_secretary');
        cpms_test_seed_membership($secretary, 1, 'cpms_secretary');
        $doctor = $this->makeUser('p5b_doc', 'cpms_doctor');
        cpms_test_seed_membership($doctor, 1, 'cpms_doctor');
        $admin = $this->makeUser('p5b_adm', 'administrator');
        cpms_test_seed_membership($admin, 1, 'cpms_manager');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr P5B',
                $doctor,
                $now,
                $now
            )
        );
        $clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-P5B-' . bin2hex(random_bytes(2)),
                'Phase5B',
                'Patient',
                '0912' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;

        // Service
        $svc = App::financeService()->createService($admin, ['code' => 'P5-VISIT', 'name' => 'ویزیت P5', 'price' => 500000]);
        $serviceId = (int) $svc['id'];

        // Visit completed
        $visit = App::visitService()->walkIn($secretary, $patientId, $clinicianId);
        $visitId = (int) $visit['id'];
        App::visitService()->transition($doctor, $visitId, 'call');
        App::visitService()->transition($doctor, $visitId, 'start');
        App::clinicalService()->addNote($doctor, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'درد',
        ]);
        App::clinicalService()->completeConsultation($doctor, $visitId);

        return [
            'secretary' => $secretary,
            'doctor' => $doctor,
            'admin' => $admin,
            'visit_id' => $visitId,
            'service_id' => $serviceId,
            'clinician' => $clinicianId,
        ];
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
