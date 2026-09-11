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
 * C7-0 / C7-C2 — تست Characterization اجرایی: ایزولیشن «شناسهٔ شیء» در جهش‌های برنامهٔ هفتگی.
 *
 * این فایل فقط شواهد است (evidence branch). هیچ رفتار تولیدی را تغییر نمی‌دهد.
 * مرجع یافتهٔ کاندیدا: docs/phase-reports/c7-0-census.md §۲ (ردیف ۱۳ ماتریس).
 *
 * مسیرهای تولیدیِ مورد آزمایش (هر سه از ScheduleController واقعی):
 *   POST   /clinic/v1/config/schedules/{id}          → ScheduleService::update()
 *   DELETE /clinic/v1/config/schedules/{id}          → ScheduleService::delete()
 *   DELETE /clinic/v1/config/schedule-exceptions/{id} → ScheduleService::deleteException()
 *
 * خاصیت امنیتی مورد آزمایش:
 *   فراخوانی تحت Scope مورد اعتمادِ Clinic B (مدیر B با capability ‌cpms_config
 *   و عضویت فعال، تأییدشده توسط مرز REST C6) نباید صرفاً با ارسال شناسهٔ شیء،
 *   برنامه/استثنای Clinic A را ویرایش یا حذف کند — و نباید دادهٔ وابستهٔ
 *   Clinic A (Slotهای آیندهٔ خالی پزشک A) به‌دلیل عملیات B حذف/بازتولید شود.
 *
 * رفتار امنِ مورد انتظار (قرارداد محصول: fail-closed + 404 parity):
 *   - پاسخ 404 با کد CLINIC_NOT_FOUND
 *   - ردیف برنامه/استثنای قربانی در DB بدون تغییر (یا موجود)
 *   - Slot آیندهٔ خالیِ پزشک قربانی همچنان موجود (بدون regenerate قربانی)
 *
 * این فایل «مشخصهٔ ایزولیشن» است، نه آینهٔ رفتار فعلی. قرمزی تست‌ها در main
 * فعلی = شاهد نقص ازپیش‌موجود (طبقه‌بندی خطای پروژه: کلاس B). برای سبز کردن
 * CI نباید این تست‌ها تضعیف/skip شوند؛ اصلاح در برش C7 انجام می‌شود.
 *
 * استراتژی fixture:
 *   - Clinic قربانی A = 61311 و Clinic مهاجم B = 61312 (محدودهٔ رزرو ≥ 61000؛
 *     نه 1، نه 2؛ فرض نمی‌کند «Clinic 2» آزاد است).
 *   - پزشکِ دارای برنامه، متعلق به Clinic A (کلینیک از ردیف پزشک، نه از Scope).
 *   - Scope مورد اعتماد B مستقل از اشیای قربانی: مدیر B فقط عضویت فعال روی B
 *     دارد و درخواست حمله X-CPMS-Clinic-Id: 61312 را حمل می‌کند.
 *   - برنامه/استثنای قربانی از مسیر واقعی سرویس توسط مدیرِ خودِ A ساخته می‌شود
 *     (create/createException دامنه را از requireClinician می‌گیرند).
 */
final class C7ScheduleObjectIdIsolationTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    /** Clinic قربانی — محدودهٔ رزرو تست (≥ 61000). */
    private const CLINIC_A = 61311;

    /** Clinic مهاجم (Scope مورد اعتمادِ فراخوان) — محدودهٔ رزرو تست. */
    private const CLINIC_B = 61312;

    private int $managerA = 0;

    private int $managerB = 0;

    private int $doctorA = 0;

    private int $clinicianA = 0;

    private int $scheduleAId = 0;

    private int $exceptionAId = 0;

    private int $futureEmptySlotAId = 0;

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

        // Warm خنثی پیش از ساخت fixtureها — الگوی ClinicTenantIsolationTest (boot یک‌بارمصرف).
        $this->warmRoutes();

        $orgId = $this->defaultOrganization();
        $this->insertClinic(self::CLINIC_A, $orgId, 'c7-sch-clinic-a');
        $this->insertClinic(self::CLINIC_B, $orgId, 'c7-sch-clinic-b');
        $locA = $this->insertLocation(self::CLINIC_A, 'c7-sch-loc-a');
        $this->insertLocation(self::CLINIC_B, 'c7-sch-loc-b');

        // Clinic A — مالکان مشروع اشیای قربانی.
        $this->managerA = $this->makeUser('c7sch_mgr_a', 'cpms_manager');
        cpms_test_seed_membership($this->managerA, self::CLINIC_A, 'cpms_manager');
        $this->doctorA = $this->makeUser('c7sch_doc_a', 'cpms_doctor');
        cpms_test_seed_membership($this->doctorA, self::CLINIC_A, 'cpms_doctor');
        $this->clinicianA = $this->insertClinician(self::CLINIC_A, $this->doctorA, 'Dr C7 Schedule Victim');

        // Clinic B — مهاجم: مدیر با cpms_config و عضویت فعال فقط روی B.
        $this->managerB = $this->makeUser('c7sch_mgr_b', 'cpms_manager');
        cpms_test_seed_membership($this->managerB, self::CLINIC_B, 'cpms_manager');

        // اشیای قربانی از مسیر تولیدی سرویس (create/createException) توسط مدیر A.
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
         * Slot خالیِ آیندهٔ پزشک قربانی — بعد از همهٔ عملیاتِ regenerate‌دارِ
         * fixture ساخته می‌شود تا شاهدِ اثر جانبی (حذف Slot توسط regenerateِ
         * جهشِ متقاطع) باشد.
         */
        $this->futureEmptySlotAId = $this->seedFutureEmptySlot(self::CLINIC_A, $locA, $this->clinicianA);
        self::assertGreaterThan(0, $this->futureEmptySlotAId, 'پیش‌شرط: Slot خالی آیندهٔ قربانی ساخته شود');
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

    // ================= C7-C2 — ویرایش برنامهٔ Clinic دیگر =================

    /**
     * C7-C2/1 — ScheduleService::update از مسیر REST با شناسهٔ ورودی.
     *
     * خاصیت: مدیر Clinic B (با cpms_config و Scope معتبر B) نباید بتواند
     * برنامهٔ Clinic A را ویرایش کند. انتظار: 404 + CLINIC_NOT_FOUND؛
     * ساعات برنامهٔ قربانی بدون تغییر؛ Slot آیندهٔ خالی پزشک قربانی همچنان
     * موجود (بدون regenerate ناشی از عملیات B).
     */
    public function testUpdateOfForeignClinicScheduleMustFailClosed(): void
    {
        wp_set_current_user($this->managerB);
        $res = $this->dispatch('POST', self::NS . '/config/schedules/' . $this->scheduleAId, [
            'start_time' => '10:00',
            'end_time' => '16:00',
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $row = $this->fetchScheduleRow($this->scheduleAId);
        $slotExists = $this->slotExists($this->futureEmptySlotAId);

        $this->assertSame(
            404,
            $status,
            'C7-C2/1 schedule update: انتظار پاسخ fail-closed معادل 404 (CLINIC_NOT_FOUND). '
            . "واقعی: HTTP {$status} code={$code}; "
            . 'DB: victim_schedule=' . ($row === null ? 'GONE' : $this->sig($row))
            . '; victim_future_empty_slot_exists=' . ($slotExists ? 'yes' : 'NO (regenerate قربانی اجرا شد)')
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertNotNull($row, 'ردیف برنامهٔ Clinic A نباید حذف شود');
        $this->assertSame('09:00:00', (string) $row['start_time'], 'زمان شروع برنامهٔ قربانی نباید تغییر کند');
        $this->assertSame('13:00:00', (string) $row['end_time'], 'زمان پایان برنامهٔ قربانی نباید تغییر کند');
        $this->assertTrue($slotExists, 'Slot خالی آیندهٔ پزشک Clinic A به‌دلیل عملیات Clinic B نباید حذف شود');
    }

    // ================= C7-C2 — حذف برنامهٔ Clinic دیگر =================

    /**
     * C7-C2/2 — ScheduleService::delete از مسیر REST با شناسهٔ ورودی.
     *
     * خاصیت: مدیر Clinic B نباید بتواند برنامهٔ Clinic A را حذف کند.
     * انتظار: 404 + CLINIC_NOT_FOUND؛ ردیف برنامهٔ قربانی همچنان موجود؛
     * Slot آیندهٔ خالی پزشک قربانی همچنان موجود.
     */
    public function testDeleteOfForeignClinicScheduleMustFailClosed(): void
    {
        wp_set_current_user($this->managerB);
        $res = $this->dispatch('DELETE', self::NS . '/config/schedules/' . $this->scheduleAId, [], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $row = $this->fetchScheduleRow($this->scheduleAId);
        $slotExists = $this->slotExists($this->futureEmptySlotAId);

        $this->assertSame(
            404,
            $status,
            'C7-C2/2 schedule delete: انتظار پاسخ fail-closed معادل 404 (CLINIC_NOT_FOUND). '
            . "واقعی: HTTP {$status} code={$code}; "
            . 'DB: victim_schedule=' . ($row === null ? 'DELETED (جهش بین‌کلینیکی رخ داد)' : $this->sig($row))
            . '; victim_future_empty_slot_exists=' . ($slotExists ? 'yes' : 'NO (regenerate قربانی اجرا شد)')
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertNotNull($row, 'ردیف برنامهٔ Clinic A نباید حذف شود');
        $this->assertSame('09:00:00', (string) $row['start_time'], 'برنامهٔ قربانی نباید تغییر کند');
        $this->assertTrue($slotExists, 'Slot خالی آیندهٔ پزشک Clinic A به‌دلیل عملیات Clinic B نباید حذف شود');
    }

    // ================= C7-C2 — حذف استثنای برنامهٔ Clinic دیگر =================

    /**
     * C7-C2/3 — ScheduleService::deleteException از مسیر REST با شناسهٔ ورودی.
     *
     * خاصیت: مدیر Clinic B نباید بتواند استثنای برنامهٔ Clinic A را حذف کند.
     * انتظار: 404 + CLINIC_NOT_FOUND؛ ردیف استثنای قربانی همچنان موجود؛
     * Slot آیندهٔ خالی پزشک قربانی همچنان موجود.
     */
    public function testDeleteOfForeignClinicScheduleExceptionMustFailClosed(): void
    {
        wp_set_current_user($this->managerB);
        $res = $this->dispatch('DELETE', self::NS . '/config/schedule-exceptions/' . $this->exceptionAId, [], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_B,
        ]);

        $status = $res->get_status();
        $code = $this->errorCode($res);
        $row = $this->fetchExceptionRow($this->exceptionAId);
        $slotExists = $this->slotExists($this->futureEmptySlotAId);

        $this->assertSame(
            404,
            $status,
            'C7-C2/3 schedule-exception delete: انتظار پاسخ fail-closed معادل 404 (CLINIC_NOT_FOUND). '
            . "واقعی: HTTP {$status} code={$code}; "
            . 'DB: victim_exception=' . ($row === null ? 'DELETED (جهش بین‌کلینیکی رخ داد)' : $this->sig($row))
            . '; victim_future_empty_slot_exists=' . ($slotExists ? 'yes' : 'NO (regenerate قربانی اجرا شد)')
        );
        $this->assertSame('CLINIC_NOT_FOUND', $code, 'کد خطای مورد انتظار مطابق قرارداد 404-parity');
        $this->assertNotNull($row, 'ردیف استثنای Clinic A نباید حذف شود');
        $this->assertTrue($slotExists, 'Slot خالی آیندهٔ پزشک Clinic A به‌دلیل عملیات Clinic B نباید حذف شود');
    }

    // ================= Helpers =================

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
     * @return array<string, mixed>|null
     */
    private function fetchScheduleRow(int $id): ?array
    {
        return App::db()->fetchRow(
            'SELECT id, clinic_id, clinician_id, day_of_week, start_time, end_time, slot_capacity FROM '
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
        return App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        ) !== null;
    }

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
