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
 * Phase 6 Slice 1 — RED characterization: ایزولیشن Clinic در بازتولید برنامه
 * و شمارنده‌های impact.
 *
 * نقصِ مورد آزمایش (طبقه‌بندی B = نقص محصول ازپیش‌موجود):
 *   وقتی یک پزشک در دو Clinic مشارکت فعال پایدار دارد (چندعضویتی مشروع)،
 *   جهش برنامه در Clinic A (مثلاً به‌روزرسانی ساعت کاری یا حذف/ایجاد/استثنا)
 *   از طریق ScheduleService::regenerate() بدون Clinic scope، Slotهای
 *   «خالیِ آینده» را صرفاً بر اساس clinician_id حذف می‌کند — در نتیجه Slotهای
 *   خالیِ Clinic B هم اشتباهاً حذف می‌شوند. همچنین ScheduleService::impact()
 *   بدون Clinic scope شمارش می‌کند و Slotهای Clinic B را در تأثیرِ Clinic A
 *   می‌گنجاند.
 *
 * سناریوی fixture:
 *   - Clinic A = 61411، Clinic B = 61412 (محدوده رزرو ≥ 61000).
 *   - یک هویت پزشک/پروفایلِ یکتا (clinician_id واحد) که Clinic خانه‌اش A است
 *     ولی عضو فعال پایدار B نیز هست (مشارکت مشروع در هر دو).
 *   - برای هر Clinic، یک Location + یک برنامه هفتگی فعال + یک Slot خالی آینده
 *     در Clinic خودشان.
 *   - در Clinic B یک Slot «محافظت‌شده» (booked_count=1) هم کاشته می‌شود تا
 *     ثابت شود رزروهای B دست‌نخورده می‌مانند (قرارداد book/held protection).
 *
 * مسیر آزمون: ScheduleService::update() واقعی از مسیر REST با Scope معتبر A
 * (یعنی trusted Clinic context همان A است)، تا ثابت شود نقص در عملیات واقعی
 * محصول رخ می‌دهد نه فقط در فراخوانی مستقیم Repository.
 */
final class ScheduleRegenerationClinicScopeRedTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    /** Clinic هدف/مجازِ عملیات. */
    private const CLINIC_A = 61411;

    /** Clinic خواهر/قربانی — پزشک در آن مشارکت مشروع دارد. */
    private const CLINIC_B = 61412;

    private int $managerA = 0;
    private int $managerB = 0;
    private int $doctorUserId = 0;

    /** یک پروفایل پزشکِ یکتا (هویت سراسری) — خانه = A، اما عضو فعال B نیز. */
    private int $sharedClinicianId = 0;

    private int $locA = 0;
    private int $locB = 0;

    /** برنامه هفتگیِ Clinic A برای پزشک مشترک. */
    private int $scheduleAId = 0;

    /** Slot خالی آینده در Clinic A. */
    private int $emptySlotAId = 0;

    /** Slot خالی آینده در Clinic B — قربانیِ نقص. */
    private int $emptySlotBId = 0;

    /** Slot رزروشده در Clinic B — باید دست‌نخورده بماند. */
    private int $bookedSlotBId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // شاهد ایزوله‌سازی: ابتدای هر تست نباید هیچ Clinic رزرو (≥ 61000) باقی مانده باشد.
        global $wpdb;
        $leftover = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id >= 61000' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(
            0,
            $leftover,
            'CPMS_ISOLATION_WITNESS: ' . $leftover . ' Clinic رزرو از تست قبلی باقی مانده'
        );

        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        $this->warmRoutes();

        $orgId = $this->defaultOrganization();
        $this->insertClinic(self::CLINIC_A, $orgId, 'p6s1-clinic-a');
        $this->insertClinic(self::CLINIC_B, $orgId, 'p6s1-clinic-b');
        $this->locA = $this->insertLocation(self::CLINIC_A, 'p6s1-loc-a');
        $this->locB = $this->insertLocation(self::CLINIC_B, 'p6s1-loc-b');

        // مدیر Clinic A (با عضویت فعال + capability cpms_config).
        $this->managerA = $this->makeUser('p6s1_mgr_a', 'cpms_manager');
        cpms_test_seed_membership($this->managerA, self::CLINIC_A, 'cpms_manager');

        // مدیر Clinic B (برای sanity؛ در آزمون اصلی استفاده نمی‌شود اما fixture تأیید می‌کند).
        $this->managerB = $this->makeUser('p6s1_mgr_b', 'cpms_manager');
        cpms_test_seed_membership($this->managerB, self::CLINIC_B, 'cpms_manager');

        // یک پزشکِ یکتا: خانه = Clinic A؛ سپس عضویت فعال در B اضافه می‌شود.
        $this->doctorUserId = $this->makeUser('p6s1_doc_shared', 'cpms_doctor');
        // عضویت مشروع در هر دو Clinic.
        cpms_test_seed_membership($this->doctorUserId, self::CLINIC_A, 'cpms_doctor');
        cpms_test_seed_membership($this->doctorUserId, self::CLINIC_B, 'cpms_doctor');
        $this->sharedClinicianId = $this->insertClinician(self::CLINIC_A, $this->doctorUserId, 'Dr Phase6 Slice1 Shared');
        self::assertGreaterThan(0, $this->sharedClinicianId, 'پیش‌شرط: پروفایل پزشک مشترک ساخته شود');

        // برنامه هفتگی در Clinic A — از مسیر واقعی سرویس.
        $schedA = $this->withScope(self::CLINIC_A, fn (): array => App::scheduleService()->create(
            $this->managerA,
            [
                'clinician_id' => $this->sharedClinicianId,
                'day_of_week' => $this->dowForDate(gmdate('Y-m-d', time() + 7 * 86400)),
                'start_time' => '09:00',
                'end_time' => '13:00',
                'appointment_duration_min' => 30,
                'slot_capacity' => 2,
            ]
        ));
        $this->scheduleAId = (int) $schedA['id'];
        self::assertGreaterThan(0, $this->scheduleAId, 'پیش‌شرط: برنامه Clinic A ساخته شود');

        // برنامه هفتگی در Clinic B برای همان پزشک — از مسیر واقعی سرویس با Scope B.
        $schedB = $this->withScope(self::CLINIC_B, fn (): array => App::scheduleService()->create(
            $this->managerB,
            [
                'clinician_id' => $this->sharedClinicianId,
                'day_of_week' => $this->dowForDate(gmdate('Y-m-d', time() + 7 * 86400)),
                'start_time' => '09:00',
                'end_time' => '12:00',
                'appointment_duration_min' => 30,
                'slot_capacity' => 1,
            ]
        ));
        self::assertGreaterThan(0, (int) $schedB['id'], 'پیش‌شرط: برنامه Clinic B ساخته شود');

        // پس از ساخت هر برنامه، regenerate به‌صورت خودکار اجرا می‌شود و کاشته‌شده‌های
        // بعدی توسط آن حذف می‌شوند. بنابراین Slotهای شاهد را *پس از* هر دو create می‌کاریم.
        $this->emptySlotAId = $this->seedFutureEmptySlot(self::CLINIC_A, $this->locA, $this->sharedClinicianId, gmdate('Y-m-d', time() + 7 * 86400), '10:00');
        self::assertGreaterThan(0, $this->emptySlotAId, 'پیش‌شرط: Slot خالی Clinic A کاشته شود');

        $this->emptySlotBId = $this->seedFutureEmptySlot(self::CLINIC_B, $this->locB, $this->sharedClinicianId, gmdate('Y-m-d', time() + 7 * 86400), '10:00');
        self::assertGreaterThan(0, $this->emptySlotBId, 'پیش‌شرط: Slot خالی Clinic B کاشته شود');

        $this->bookedSlotBId = $this->seedFutureBookedSlot(self::CLINIC_B, $this->locB, $this->sharedClinicianId, gmdate('Y-m-d', time() + 7 * 86400), '10:30');
        self::assertGreaterThan(0, $this->bookedSlotBId, 'پیش‌شرط: Slot رزرو‌شده Clinic B کاشته شود');

        // اطمینان پیش‌شرط: هر سه Slot واقعاً وجود دارند.
        self::assertTrue($this->slotExists($this->emptySlotAId), 'پیش‌شرط: emptySlotA پیش از آزمون موجود باشد');
        self::assertTrue($this->slotExists($this->emptySlotBId), 'پیش‌شرط: emptySlotB پیش از آزمون موجود باشد');
        self::assertTrue($this->slotExists($this->bookedSlotBId), 'پیش‌شرط: bookedSlotB پیش از آزمون موجود باشد');

        // اجرای jobها را در این تست متوقف می‌کنیم: تولید مجدد slotها توسط
        // SlotsGenerateHandler در این برش scope خودش را دارد (Phase 4 Slice 1)
        // و ربطی به نقص delete ندارد؛ ما در اینجا فقط اثر deleteFutureEmptySlots
        // را (بازتولید) می‌سنجیم، نه صحت handler.
        App::resetScope();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();

        $this->purgeReserveRows();

        parent::tearDown();
    }

    /**
     * Phase 6 Slice 1 — نقص A: به‌روزرسانی برنامه در Clinic A (Scope معتبر A)
     * نباید Slot خالی آیندهٔ Clinic B را حذف کند.
     *
     * قبل از اصلاح، regenerate() فقط با clinician_id صدا زده می‌شود و
     * deleteFutureEmptySlots() صرفاً با clinician_id حذف می‌کند → emptySlotB
     * اشتباهاً حذف می‌شود (و bookedSlotB هم بواسطه booked_count>0 حفظ می‌شود).
     */
    public function testRegenerationUnderClinicAScopeMustNotDeleteClinicBEmptySlots(): void
    {
        wp_set_current_user($this->managerA);

        // جهش واقعی محصول از مسیر REST با Scope معتبر Clinic A.
        $res = $this->dispatch('PUT', self::NS . '/config/schedules/' . $this->scheduleAId, [
            'end_time' => '12:30',
        ], [
            'X-CPMS-Clinic-Id' => (string) self::CLINIC_A,
        ]);

        $status = $res->get_status();
        $body = $res->get_data();
        self::assertSame(
            200,
            $status,
            'جهش در Clinic A باید با 200 موفق شود (بدون خطا)؛ واقعی: HTTP ' . $status
                . ' body=' . json_encode($body, JSON_UNESCAPED_UNICODE)
        );

        // قرارداد ۱: Slot خالی Clinic A در جریان regeneration بازتولید می‌شود و
        // بسته به زمان‌بندی جدید ممکن است حذف شده باشد — طبیعی است؛ این تست
        // روی آن شرط نمی‌گذارد (و focus روی cross-clinic leakage است).

        // قرارداد ۲ (ضدنقص): Slot خالی Clinic B نباید حذف شود.
        self::assertTrue(
            $this->slotExists($this->emptySlotBId),
            'Phase6-S1/regenerate: بازتولید برنامه در Clinic A نباید Slot خالیِ آیندهٔ Clinic B را حذف کند (تعدادclinician-only)'
        );

        // قرارداد ۳ (حفاظت تاریخی): Slot رزروشده Clinic B همیشه دست‌نخورده.
        self::assertTrue(
            $this->slotExists($this->bookedSlotBId),
            'Phase6-S1/regenerate: Slot رزروشدهٔ Clinic B باید دست‌نخورده بماند'
        );
        $booked = App::db()->fetchRow(
            'SELECT id, clinic_id, booked_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$this->bookedSlotBId]
        );
        self::assertNotNull($booked, 'ردیف bookedSlotB باید قابل بارگذاری باشد');
        self::assertSame(self::CLINIC_B, (int) $booked['clinic_id'], 'bookedSlotB باید در Clinic B باقی بماند');
        self::assertGreaterThanOrEqual(1, (int) $booked['booked_count']);

        // قرارداد ۴: ردیف برنامه در Clinic A به‌روزرسانی شده است (اثبات که
        // مسیر محصول واقعاً اجرا شد و ما صرفاً no-op/خطا ندیدیم).
        $updated = App::db()->fetchRow(
            'SELECT end_time FROM ' . App::db()->table('cpms_schedule') . ' WHERE id = %d',
            [$this->scheduleAId]
        );
        self::assertNotNull($updated, 'برنامه Clinic A باید موجود باشد');
        self::assertSame('12:30:00', (string) $updated['end_time'], 'ساعت پایان برنامه A باید به 12:30 تغییر کند');
    }

    /**
     * Phase 6 Slice 1 — نقص B: شمارندهٔ impact تحت Scope Clinic A نباید
     * Slotهای Clinic B را بشمارد.
     *
     * توجه: REST endpoint برای impact در این مرحله وجود ندارد — ScheduleController
     * آن را Register نمی‌کند. بنابراین از مسیر سرویس با Scope معتبر Clinic A
     * استفاده می‌کنیم (همان کد که Admin UI و handlerهای داخلی صدا می‌زنند).
     */
    public function testImpactUnderClinicAScopeMustCountOnlyClinicARows(): void
    {
        // انتظار: impact در Scope A فقط Slotهای A را بشمارد.
        $impact = $this->withScope(self::CLINIC_A, fn (): array => App::scheduleService()->impact($this->sharedClinicianId));

        // قبل از اصلاح: impact در Scope A هر دو Slot خالی (A + B) را می‌شمارد
        // چون countFutureEmptySlots فقط روی clinician_id فیلتر می‌شود.
        // پس عدد برگشتی 2 خواهد بود (empty A + empty B)؛ این باید 1 باشد (فقط A).
        self::assertSame(
            1,
            (int) $impact['future_empty_slots'],
            'Phase6-S1/impact: فاصلهٔ Clinic A باید فقط Slotهای خالیِ خودش را بشمارد (B را نشمارد)'
        );

        // مشابه برای reserved: Slot رزروشدهٔ Clinic B نباید در شمارش A بیاید.
        self::assertSame(
            0,
            (int) $impact['future_reserved_slots'],
            'Phase6-S1/impact: فاصلهٔ Clinic A نباید Slot محافظت‌شدهٔ Clinic B را بشمارد'
        );
    }

    // ================= Helpers =================

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

    private function warmRoutes(): void
    {
        \ClinicCore\Settings\Settings::flushCache();
        rest_do_request(new WP_REST_Request('GET', self::NS . '/health'));
        \ClinicCore\Settings\Settings::flushCache();
    }

    private function slotExists(int $slotId): bool
    {
        return App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        ) !== null;
    }

    private function seedFutureEmptySlot(int $clinicId, int $locationId, int $clinicianId, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, location_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
                 VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, "manual", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $clinicianId,
                $locationId,
                $date,
                $time,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function seedFutureBookedSlot(int $clinicId, int $locationId, int $clinicianId, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, location_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
                 VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, "manual", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $clinicianId,
                $locationId,
                $date,
                $time,
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

    private function dowForDate(string $ymd): int
    {
        $map = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 0];

        return $map[(int) gmdate('w', strtotime($ymd))];
    }

    private function makeUser(string $login, string $role): int
    {
        $suffix = bin2hex(random_bytes(2));
        $userId = (int) wp_create_user($login . $suffix, 'pass-12345', $login . $suffix . '@test.local');
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
        self::assertGreaterThan(0, $orgId, 'پیش‌شرط: Organization از Clinic id=1 خوانده شود');

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
        self::assertNotEquals(1, $id, '⚑ Clinic id نباید 1 باشد (محدوده رزرو)');
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
