<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use WP_UnitTestCase;

/**
 * C7 hardening (bounded slice) — RED: ScheduleService no-scope reads fail closed.
 *
 * دامنهٔ قرارداد (تست‌تنها — هیچ رفتار تولیدی در این کامیت تغییر نمی‌کند):
 *
 *   مسیرهای تولیدیِ مورد آزمایش:
 *     ScheduleService::list($clinicianId)
 *     ScheduleService::listExceptions($clinicianId, $from, $to)
 *
 *   قرارداد هدف:
 *     بدون Scope صریحِ معتبر (ScopeContext::tryGet() === null) هر دو خواندن
 *     باید fail-closed باشند با پاکتِ پایدارِ «پزشک یافت نشد»:
 *       BookingException با errorCode = CLINIC_NOT_FOUND، httpStatus = 404،
 *       پیام فارسیِ موجودِ not-found = «پزشک یافت نشد».
 *     هیچ ردیف برنامه/استثنا نباید برگردد و هیچ fallback به
 *     `clinicians.clinic_id` به‌عنوان مرجع tenant مجاز نیست.
 *     رفتار scoped (Scope صریح + عضویت فعال) نباید تغییر کند (positive control).
 *
 *   رفتار فعلیِ main (مشخصه‌نگاری‌شده در بازبینی C6/Phase6 closure، قلم hardening
 *   «ج» — deferrable, نه بلوکر امنیتی فعلی production چون مرز REST همیشه Scope
 *   صریح می‌سازد):
 *     requireClinicianWithinTrustedClinic() وقتی explicitTrustedClinicId() تهی
 *     است فقط requireClinician() را اجرا می‌کند (وجود پروفایل فعال کافی است) و
 *     null برمی‌گرداند ⇒ list()/listExceptions() مسیر بدون دامنهٔ Clinic را
 *     می‌خوانند و ردیف‌ها افشا می‌شوند.
 *
 *   RED مورد انتظار روی main فعلی: هر دو تست no-scope شکست می‌خورند چون
 *   خواندنِ بدون Scope به‌جای fail-closed، ردیف‌های برنامه/استثنا را برمی‌گرداند.
 *   تستِ positive control باید روی main فعلی سبز بماند (اثبات می‌کند شکستِ
 *   تست‌های RED دقیقاً منتسب به قرارداد no-scope است، نه bootstrap/fixture).
 *
 * استراتژی fixture (الگوی C7ScheduleObjectIdIsolationTest):
 *   - Clinic رزرو 61331 (محدودهٔ ≥ 61000؛ نه 1، نه 2) — با شاهد ایزوله‌سازی.
 *   - پزشکِ فعال با wp_user واقعی + عضویت فعالِ صریح (SoT) در همان Clinic.
 *   - ردیف برنامهٔ هفتگی + ردیف استثنای آینده مستقیم در DB (fixture مادی،
 *     همین‌جا assert می‌شود) تا خواندنِ بدون Scope «چیزی برای افشا» داشته باشد.
 */
final class C7ScheduleNoScopeReadRedTest extends WP_UnitTestCase
{
    /** Clinic قربانی — محدودهٔ رزرو تست (≥ 61000). */
    private const CLINIC_A = 61331;

    private int $doctorA = 0;

    private int $clinicianA = 0;

    private int $locationA = 0;

    private int $scheduleId = 0;

    private int $exceptionId = 0;

    private string $exceptionDate = '';

    private string $rangeFrom = '';

    private string $rangeTo = '';

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
        wp_set_current_user(0);

        $orgId = $this->defaultOrganization();
        $this->insertClinic(self::CLINIC_A, $orgId, 'c7-noscope-clinic-a');
        $this->locationA = $this->insertLocation(self::CLINIC_A, 'c7-noscope-loc-a');

        // پزشکِ دارای پروفایل فعال + عضویت فعالِ صریح در Clinic A.
        $this->doctorA = $this->makeUser('c7ns_doc_a', 'cpms_doctor');
        cpms_test_seed_membership($this->doctorA, self::CLINIC_A, 'cpms_doctor');
        $this->clinicianA = $this->insertClinician(self::CLINIC_A, $this->doctorA, 'Dr C7 NoScope Read');

        // ردیف برنامهٔ قربانی — مستقیم در DB (fixture مادی با assert).
        $now = App::db()->nowUtcSql();
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule
                     (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, appointment_duration_min, slot_capacity, is_active, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, %s, %s, %d, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::CLINIC_A,
                $this->locationA,
                $this->clinicianA,
                3,
                '09:00:00',
                '13:00:00',
                30,
                2,
                $now,
                $now
            )
        );
        self::assertNotFalse($ok, 'پیش‌شرط: درج برنامهٔ قربانی موفق باشد');
        $this->scheduleId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->scheduleId, 'پیش‌شرط: برنامهٔ قربانی ساخته شود');

        // ردیف استثنای قربانی — تاریخ آینده تا شکست fail-closed دقیقاً در گارد
        // Scope رخ بدهد نه در اعتبارسنجی تاریخ.
        $this->exceptionDate = gmdate('Y-m-d', (time() + 3 * 86400));
        $this->rangeFrom = gmdate('Y-m-d');
        $this->rangeTo = gmdate('Y-m-d', (time() + 10 * 86400));
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_exceptions
                     (clinic_id, location_id, clinician_id, date, type, start_time, end_time, reason, created_by_wp_user_id, created_at)
                 VALUES (%d, NULL, %d, %s, %s, NULL, NULL, %s, %d, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::CLINIC_A,
                $this->clinicianA,
                $this->exceptionDate,
                'holiday',
                'c7 noscope read fixture',
                $this->doctorA,
                $now
            )
        );
        self::assertNotFalse($ok, 'پیش‌شرط: درج استثنای قربانی موفق باشد');
        $this->exceptionId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->exceptionId, 'پیش‌شرط: استثنای قربانی ساخته شود');

        // اثبات مادی بودن fixture: خواندنِ بدون دامنه «چیزی برای افشا» دارد.
        $clinicianActive = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE id = %d AND is_active = 1',
                $this->clinicianA
            )
        );
        self::assertSame(1, $clinicianActive, 'پیش‌شرط: پزشکِ فعالِ معتبر وجود دارد');

        $scheduleCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_schedule WHERE clinician_id = %d',
                $this->clinicianA
            )
        );
        self::assertGreaterThanOrEqual(1, $scheduleCount, 'پیش‌شرط: ردیف برنامه برای افشا بالقوه وجود دارد');

        $exceptionCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_schedule_exceptions WHERE clinician_id = %d AND date BETWEEN %s AND %s',
                $this->clinicianA,
                $this->rangeFrom,
                $this->rangeTo
            )
        );
        self::assertGreaterThanOrEqual(1, $exceptionCount, 'پیش‌شرط: ردیف استثنا در بازهٔ خواندن وجود دارد');
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

    // ================= C7-A — خواندن بدون Scope: list() =================

    /**
     * C7-A/1 — ScheduleService::list بدون Scope صریح باید fail-closed باشد.
     *
     * انتظار: BookingException(CLINIC_NOT_FOUND, 404, «پزشک یافت نشد») و هیچ
     * ردیفی برنمی‌گردد. روی main فعلی این تست RED است چون شاخهٔ سازگاریِ
     * بدون Scope با وجود پزشکِ فعال عبور می‌کند و ردیف‌ها را برمی‌گرداند.
     */
    public function testListWithoutTrustedClinicScopeMustFailClosed(): void
    {
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);
        self::assertNull(ScopeContext::tryGet(), 'پیش‌شرط: هیچ Scope صریحی برقرار نیست (ScopeContext خالی)');

        $blocked = null;
        $rows = null;
        try {
            $rows = App::scheduleService()->list($this->clinicianA);
        } catch (BookingException $e) {
            $blocked = $e;
        }

        $observed = is_array($rows)
            ? 'میزان ردیف‌های برگشتیِ بدون دامنه: ' . count($rows) . ' (افشا شد)'
            : 'بدون نتیجه';

        self::assertInstanceOf(
            BookingException::class,
            $blocked,
            'C7-A/1 list: بدون Scope صریحِ معتبر، خواندن برنامه باید fail-closed شود '
            . '(CLINIC_NOT_FOUND/404)؛ هرگز مسیر fallback به clinicians.clinic_id نباید داده برگرداند. '
            . 'رفتار واقعی: استثنایی پرتاب نشد — ' . $observed
        );
        self::assertSame('CLINIC_NOT_FOUND', $blocked->errorCode, 'پاکتِ پایدار: کد CLINIC_NOT_FOUND');
        self::assertSame(404, $blocked->httpStatus, 'پاکتِ پایدار: HTTP 404');
        self::assertSame('پزشک یافت نشد', $blocked->getMessage(), 'پاکتِ پایدار: پیام فارسیِ موجودِ not-found');
    }

    // ================= C7-A — خواندن بدون Scope: listExceptions() =================

    /**
     * C7-A/2 — ScheduleService::listExceptions بدون Scope صریح باید با همان
     * پاکتِ list fail-closed باشد (CLINIC_NOT_FOUND / 404 / «پزشک یافت نشد»).
     * روی main فعلی RED است چون شاخهٔ بدون Scope استثناها را برمی‌گرداند.
     */
    public function testListExceptionsWithoutTrustedClinicScopeMustFailClosed(): void
    {
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);
        self::assertNull(ScopeContext::tryGet(), 'پیش‌شرط: هیچ Scope صریحی برقرار نیست (ScopeContext خالی)');

        $blocked = null;
        $rows = null;
        try {
            $rows = App::scheduleService()->listExceptions($this->clinicianA, $this->rangeFrom, $this->rangeTo);
        } catch (BookingException $e) {
            $blocked = $e;
        }

        $observed = is_array($rows)
            ? 'میزان ردیف‌های استثنای برگشتیِ بدون دامنه: ' . count($rows) . ' (افشا شد)'
            : 'بدون نتیجه';

        self::assertInstanceOf(
            BookingException::class,
            $blocked,
            'C7-A/2 listExceptions: بدون Scope صریحِ معتبر، خواندن استثناها باید با همان پاکتِ '
            . 'fail-closed پاسخ بدهد (CLINIC_NOT_FOUND/404) و هیچ ردیفی برنگردد. '
            . 'رفتار واقعی: استثنایی پرتاب نشد — ' . $observed
        );
        self::assertSame('CLINIC_NOT_FOUND', $blocked->errorCode, 'پاکتِ پایدار: کد CLINIC_NOT_FOUND');
        self::assertSame(404, $blocked->httpStatus, 'پاکتِ پایدار: HTTP 404');
        self::assertSame('پزشک یافت نشد', $blocked->getMessage(), 'پاکتِ پایدار: پیام فارسیِ موجودِ not-found');
    }

    // ================= C7-A — کنترل مثبت: رفتار scoped دست‌نخورده =================

    /**
     * C7-A/3 — Positive control: با Scope صریح + عضویت فعال، رفتار موجودِ
     * دامنه‌بندی‌شده باید دقیقاً مانند main فعلی کار کند (باید سبز بماند —
     * هم روی main فعلی و هم پس از اصلاح fail-closed مسیر بدون Scope).
     */
    public function testScopedReadsWithExplicitScopeAndActiveMembershipStillSucceed(): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A));
        self::assertNotNull(ScopeContext::tryGet(), 'پیش‌شرط: Scope صریح برقرار است');

        $schedules = App::scheduleService()->list($this->clinicianA);
        self::assertGreaterThanOrEqual(1, count($schedules), 'با Scope معتبر، برنامه باید برگردد');

        $foundSchedule = false;
        foreach ($schedules as $row) {
            if ((int) ($row['id'] ?? 0) === $this->scheduleId) {
                $foundSchedule = true;
                self::assertSame('09:00', (string) ($row['start_time'] ?? ''), 'view ساعت شروع دامنه‌بندی‌شده');
                self::assertSame($this->clinicianA, (int) ($row['clinician_id'] ?? 0), 'view پزشک');
            }
        }
        self::assertTrue($foundSchedule, 'ردیف برنامهٔ fixture باید در خواندن دامنه‌بندی‌شده دیده شود');

        $exceptions = App::scheduleService()->listExceptions($this->clinicianA, $this->rangeFrom, $this->rangeTo);
        self::assertGreaterThanOrEqual(1, count($exceptions), 'با Scope معتبر، استثناها باید برگردند');

        $foundException = false;
        foreach ($exceptions as $row) {
            if ((int) ($row['id'] ?? 0) === $this->exceptionId) {
                $foundException = true;
                self::assertSame($this->exceptionDate, (string) ($row['date'] ?? ''), 'view تاریخ استثنا');
                self::assertSame('holiday', (string) ($row['type'] ?? ''), 'view نوع استثنا');
            }
        }
        self::assertTrue($foundException, 'ردیف استثنای fixture باید در خواندن دامنه‌بندی‌شده دیده شود');

        App::resetScope();
        self::assertNull(ScopeContext::tryGet(), 'پاک‌سازی Scope پس از کنترل مثبت');
    }

    // ================= Helpers =================

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
     * پاک‌سازی دفاعی fixture در محدودهٔ رزرو — الگوی ClinicTenantIsolationTest.
     */
    private function purgeReserveRows(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $pure = 'WHERE clinic_id >= 61000';
        $mem = 'WHERE membership_id IN (SELECT id FROM ' . $p . 'cpms_clinic_memberships WHERE clinic_id >= 61000)';
        $steps = [
            'cpms_slot_holds' => 'WHERE slot_id IN (SELECT id FROM ' . $p . 'cpms_schedule_slots WHERE clinic_id >= 61000)',
            'cpms_schedule_slots' => $pure,
            'cpms_schedule_exceptions' => $pure,
            'cpms_schedule' => $pure,
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
