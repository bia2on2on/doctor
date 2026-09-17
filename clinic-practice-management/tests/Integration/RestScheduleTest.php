<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * REST-Level Tests (F3) — ScheduleController (G1 + G1b):
 *
 *  - CRUD برنامه هفتگی + استثنائات از مسیر REST واقعی (Capability cpms_config)
 *  - TP-04 (Nonce) + TP-09 (Capability — منشی دسترسی ندارد)
 *  - FR-3.3: تولید Slot از برنامه + استثنا (holiday → روز بسته)
 *  - Regeneration (ADR-0004): تغییر برنامه → Slotهای آینده «خالی» حذف و بازتولید؛
 *    Slot دارای رزرو هرگز حذف نمی‌شود.
 */
final class RestScheduleTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $clinicianId;
    private int $adminUserId;
    private int $secretaryUserId;

    /** Primary Location of the seeded Clinic 1 — explicit create contract (Phase 6 Slice 3). */
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('booking.max_future_days', 14);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Schedule Test',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        // Phase 6 Slice 3: create برنامه حالا Location صریح می‌خواهد — fixture همان
        // Location اصلیِ Clinic 1 را صریح می‌فرستد (نه تکیه بر فالبک).
        $this->locationId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') .
            ' WHERE clinic_id = 1 AND is_primary = 1 ORDER BY id LIMIT 1'
        );
        $this->assertGreaterThan(0, $this->locationId, 'precondition: Clinic 1 primary Location exists');

        // C6 repair — عضویت فعال staff صریح است (نه fixture سراسری).
        // تست‌های patient/non-member عمداً عضویت نمی‌گیرند.
        $this->adminUserId = $this->makeUser('rsched_admin', 'administrator');
        cpms_test_seed_membership($this->adminUserId, 1, 'cpms_manager');
        $this->secretaryUserId = $this->makeUser('rsched_secretary', 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryUserId, 1, 'cpms_secretary');

        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ---------- Authorization ----------

    public function testCreateScheduleWithoutNonceRejected(): void
    {
        wp_set_current_user($this->adminUserId);

        $request = new WP_REST_Request('POST', self::NS . '/config/schedules');
        $request->set_param('clinician_id', $this->clinicianId);
        $request->set_param('day_of_week', 0);
        $request->set_param('start_time', '09:00');
        $request->set_param('end_time', '12:00');
        $response = rest_do_request($request);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_INVALID_NONCE');
    }

    public function testCreateScheduleBySecretaryRejected(): void
    {
        wp_set_current_user($this->secretaryUserId);

        $response = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 0,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED');
    }

    public function testListSchedulesUnauthenticatedRejected(): void
    {
        wp_set_current_user(0);

        $response = $this->dispatch('GET', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
        ]);

        $this->assertSame(401, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_UNAUTHORIZED');
    }

    // ---------- Validation ----------

    public function testCreateScheduleValidation(): void
    {
        wp_set_current_user($this->adminUserId);

        // پایان قبل از شروع
        // Phase 6 Slice 3: Location صریح همراه Clinic صریح (مرز REST: selectorِ
        // Location بدون selectorِ Clinic معتبر نمی‌شود).
        $bad = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 1,
            'start_time' => '12:00',
            'end_time' => '09:00',
            'location_id' => $this->locationId,
        ], ['X-CPMS-Clinic-Id' => '1']);
        $this->assertSame(400, $bad->get_status());
        $this->assertClinicError($bad, 'CLINIC_VALIDATION_FAILED');

        // روز هفته خارج از بازه
        $badDay = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 9,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);
        $this->assertSame(400, $badDay->get_status());

        // پزشک ناموجود
        $badClinician = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => 999999,
            'day_of_week' => 2,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);
        $this->assertSame(404, $badClinician->get_status());
        $this->assertClinicError($badClinician, 'CLINIC_NOT_FOUND');
    }

    // ---------- CRUD + Generation (FR-3.3) ----------

    public function testScheduleCrudAndSlotGeneration(): void
    {
        wp_set_current_user($this->adminUserId);

        // Phase 2 temporal: slots.generate uses Location-local calendar (Asia/Tehran for clinic 1).
        // Using UTC tomorrow is flaky when UTC time is after 20:30 (Tehran already next day).
        // Compute tomorrow in the primary Location's timezone to be deterministic.
        $locTz = App::db()->fetchValue('SELECT timezone FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1');
        $locTz = is_string($locTz) && $locTz !== '' ? $locTz : 'Asia/Tehran';
        try {
            $zone = new \DateTimeZone($locTz);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('Asia/Tehran');
        }
        $nowInLoc = new \DateTimeImmutable('now', $zone);
        $tomorrow = $nowInLoc->modify('+1 day')->format('Y-m-d');
        $dow = $this->iranianDowForZone($tomorrow, $zone);

        // Create
        $create = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => $dow,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'appointment_duration_min' => 60,
            'slot_capacity' => 2,
            'location_id' => $this->locationId,
        ], ['X-CPMS-Clinic-Id' => '1']);
        $this->assertSame(200, $create->get_status());
        $view = $create->get_data()['data'];
        $scheduleId = (int) $view['id'];
        $this->assertSame('09:00', $view['start_time']);
        $this->assertSame(60, $view['appointment_duration_min']);

        // Phase 6 Slice 4 (multi-shift): a NON-overlapping second shift is now
        // allowed; an OVERLAPPING shift at the same Location + weekday is
        // rejected with the distinct overlap reason — and writes no row, so the
        // slot assertions below still observe only the first shift.
        $overlap = $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => $dow,
            'start_time' => '10:00',
            'end_time' => '14:00',
            'location_id' => $this->locationId,
        ], ['X-CPMS-Clinic-Id' => '1']);
        $this->assertSame(400, $overlap->get_status());
        $this->assertClinicError($overlap, 'CLINIC_VALIDATION_FAILED');
        $overlapBody = $overlap->get_data();
        $this->assertIsArray($overlapBody, 'Overlap rejection envelope must be an array');
        $this->assertSame(
            'overlapping_shift',
            (is_array($overlapBody['data']['errors'] ?? null) ? ($overlapBody['data']['errors']['start_time,end_time'] ?? null) : null),
            'Overlap rejection must carry the distinct overlapping_shift reason'
        );

        // Regeneration Job (enqueue در Service) → اجرا
        $this->runJobs();

        // Slotهای فردا: 09:00, 10:00, 11:00 با ظرفیت 2
        $slots = App::db()->fetchAll(
            'SELECT slot_time, capacity FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND slot_date = %s ORDER BY slot_time',
            [$this->clinicianId, $tomorrow]
        );
        $this->assertSame(['09:00:00', '10:00:00', '11:00:00'], array_column($slots, 'slot_time'));
        $this->assertSame(2, (int) $slots[0]['capacity']);

        // Update: کوتاه‌شدن ساعت کاری → Slotهای خالی آینده حذف و بازتولید
        $update = $this->dispatch('PUT', self::NS . '/config/schedules/' . $scheduleId, [
            'end_time' => '10:30',
        ]);
        $this->assertSame(200, $update->get_status());
        $this->assertSame('10:30', $update->get_data()['data']['end_time']);
        $this->runJobs();

        $slots = App::db()->fetchAll(
            'SELECT slot_time FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND slot_date = %s ORDER BY slot_time',
            [$this->clinicianId, $tomorrow]
        );
        $this->assertSame(['09:00:00'], array_column($slots, 'slot_time'));

        // Delete
        $delete = $this->dispatch('DELETE', self::NS . '/config/schedules/' . $scheduleId);
        $this->assertSame(200, $delete->get_status());
        $this->runJobs();
        $count = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND slot_date = %s',
            [$this->clinicianId, $tomorrow]
        );
        $this->assertSame(0, $count);
    }

    public function testBookedSlotSurvivesRegeneration(): void
    {
        wp_set_current_user($this->adminUserId);

        $future = gmdate('Y-m-d', time() + 3 * 86400);
        $dow = $this->iranianDow($future);
        $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => $dow,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'location_id' => $this->locationId,
        ], ['X-CPMS-Clinic-Id' => '1']);
        $this->runJobs();

        // شبیه‌سازی رزرو روی یک Slot آینده
        App::db()->query(
            'UPDATE ' . App::db()->table('cpms_schedule_slots') .
            ' SET booked_count = 1 WHERE clinician_id = %d AND slot_date = %s AND slot_time = %s',
            [$this->clinicianId, $future, '09:00:00']
        );

        // تغییر برنامه → Regeneration
        $scheduleId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_schedule') .
            ' WHERE clinician_id = %d AND day_of_week = %d',
            [$this->clinicianId, $dow]
        );
        $this->dispatch('PUT', self::NS . '/config/schedules/' . $scheduleId, [
            'end_time' => '13:00',
        ]);
        $this->runJobs();

        // Slot رزروشده حذف نشده (امانت داده) + Slotهای جدید تولید شده‌اند
        $booked = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND slot_date = %s AND slot_time = %s AND booked_count = 1',
            [$this->clinicianId, $future, '09:00:00']
        );
        $this->assertSame(1, $booked);
        $count = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND slot_date = %s',
            [$this->clinicianId, $future]
        );
        $this->assertGreaterThanOrEqual(4, $count); // 09:00 (booked) + 10:00..12:00
    }

    // ---------- Exceptions (FR-3.2) ----------

    public function testHolidayExceptionClosesFutureDay(): void
    {
        wp_set_current_user($this->adminUserId);

        $future = gmdate('Y-m-d', time() + 4 * 86400);
        $dow = $this->iranianDow($future);
        $this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => $dow,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'location_id' => $this->locationId,
        ], ['X-CPMS-Clinic-Id' => '1']);
        $this->runJobs();
        $before = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND slot_date = %s',
            [$this->clinicianId, $future]
        );
        $this->assertGreaterThan(0, $before);

        // استثنا: تعطیلی کل آن روز
        $exception = $this->dispatch('POST', self::NS . '/config/schedule-exceptions', [
            'clinician_id' => $this->clinicianId,
            'date' => $future,
            'type' => 'holiday',
            'reason' => 'تعطیل رسمی تست',
        ]);
        $this->assertSame(200, $exception->get_status());
        $this->assertSame('holiday', $exception->get_data()['data']['type']);
        $exceptionId = (int) $exception->get_data()['data']['id'];
        $this->runJobs();

        $after = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND slot_date = %s',
            [$this->clinicianId, $future]
        );
        $this->assertSame(0, $after, 'Holiday must remove empty future slots for that day');

        // حذف استثنا → بازتولید
        $delete = $this->dispatch('DELETE', self::NS . '/config/schedule-exceptions/' . $exceptionId);
        $this->assertSame(200, $delete->get_status());
        $this->runJobs();
        $restored = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND slot_date = %s',
            [$this->clinicianId, $future]
        );
        $this->assertSame($before, $restored);
    }

    public function testRangeExceptionValidation(): void
    {
        wp_set_current_user($this->adminUserId);

        // blocked بدون بازه → 400
        $noRange = $this->dispatch('POST', self::NS . '/config/schedule-exceptions', [
            'clinician_id' => $this->clinicianId,
            'date' => gmdate('Y-m-d', time() + 5 * 86400),
            'type' => 'blocked',
        ]);
        $this->assertSame(400, $noRange->get_status());
        $this->assertClinicError($noRange, 'CLINIC_VALIDATION_FAILED');

        // تاریخ گذشته → 400
        $past = $this->dispatch('POST', self::NS . '/config/schedule-exceptions', [
            'clinician_id' => $this->clinicianId,
            'date' => '2020-01-01',
            'type' => 'holiday',
        ]);
        $this->assertSame(400, $past->get_status());

        // List
        $list = $this->dispatch('GET', self::NS . '/config/schedule-exceptions', [
            'clinician_id' => $this->clinicianId,
        ]);
        $this->assertSame(200, $list->get_status());
    }

    // ---------- Helpers ----------

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    /**
     * @param array<string, mixed> $body
     */
    /**
     * @param array<string, mixed>  $body
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

    private function runJobs(): void
    {
        App::dispatcher()->tick(10);
    }

    /**
     * تبدیل تاریخ گرگوری به روز هفته ایرانی (0=شنبه..6=جمعه) — همان نگاشت Handler.
     */
    private function iranianDow(string $ymd): int
    {
        $map = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 0];

        return $map[(int) gmdate('w', strtotime($ymd))];
    }

    private function iranianDowForZone(string $ymd, \DateTimeZone $zone): int
    {
        $map = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 0];
        $dt = new \DateTimeImmutable($ymd . ' 12:00:00', $zone);
        return $map[(int) $dt->format('w')];
    }

    private function assertClinicError(WP_REST_Response $response, string $code): void
    {
        $data = $response->get_data();
        $this->assertIsArray($data, 'Error envelope must be an array');
        $this->assertSame($code, $data['code'] ?? null, 'Top-level code must be the stable CLINIC_* token');
        $this->assertArrayHasKey('message', $data);
        $this->assertSame($response->get_status(), $data['data']['status'] ?? null);
    }
}
