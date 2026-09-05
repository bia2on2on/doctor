<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseDecision;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use WP_UnitTestCase;

/**
 * سیاست لایسنس صف/مراجعه (§18 دستور F4 — ADR-0023 Seam):
 *
 *  - Read-Only (Expired/Restricted): «ویزیت مستقل جدید» = Walk-in ممنوع
 *    → CLINIC_LICENSE_BLOCKED / 503 (بدون Network Call داخل request).
 *  - Check-in نوبت از پیش موجود (pre-existing Appointment workflow) = مجاز.
 *  - Transitionهای ویزیت در جریان (in-progress Visit) = مجاز تا اتمام ایمن.
 *
 * Gate از طریق Fake تزریق می‌شود (ActiveLicenseGate فعلاً همیشه allow است تا F10)؛
 * تست‌ها VisitService را مستقیم می‌سازند تا سیم‌کشی App تحت تأثیر نباشد.
 */
final class VisitLicenseGateTest extends WP_UnitTestCase
{
    private int $clinicianId;
    private int $patientId;
    private int $secretaryUserId;
    private int $doctorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('queue.max_recalls', 2);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr License Test',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "License", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-LIC-0001',
                '09123330999',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $this->secretaryUserId = $this->makeUser('lic_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('lic_doctor', 'cpms_doctor');
    }

    // ================= §18 — Read-Only Blocks New Independent Visit =================

    public function testWalkInIsBlockedInReadOnlyMode(): void
    {
        try {
            $this->service(new FakeLicenseGate(false))->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
            $this->fail('Expected CLINIC_LICENSE_BLOCKED');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
            $this->assertSame(503, $e->httpStatus);
        }

        // هیچ Visitای نباید ساخته شده باشد (رد قبل از هر نوشتن)
        $count = App::db()->fetchRow(
            'SELECT COUNT(*) AS n FROM ' . App::db()->table('cpms_visits') . ' WHERE patient_id = %d',
            [$this->patientId]
        );
        $this->assertSame(0, (int) $count['n']);
    }

    public function testWalkInSucceedsWithActiveLicense(): void
    {
        // کنترل — همان فراخوانی با Gate فعال موفق است (تفاوت فقط تصمیم Gate است)
        $visit = $this->service(new FakeLicenseGate(true))->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);

        $this->assertSame('waiting', $visit['status']);
        $this->assertSame('walk_in', $visit['source']);
    }

    // ================= §18 — Pre-existing Appointment Workflow Allowed =================

    public function testCheckInOfPreExistingAppointmentIsAllowedInReadOnlyMode(): void
    {
        $apptId = $this->makeAppointment(gmdate('Y-m-d'), gmdate('H:i:s', time() + 3600));

        $visit = $this->service(new FakeLicenseGate(false))->checkIn($this->secretaryUserId, $this->patientId, $apptId);

        $this->assertSame('waiting', $visit['status']);
        $this->assertSame('scheduled', $visit['source']);
        $this->assertSame($apptId, (int) $visit['appointment_id']);
    }

    // ================= §18 — In-progress Visit Completes Safely =================

    public function testInProgressVisitTransitionsAreAllowedInReadOnlyMode(): void
    {
        $apptId = $this->makeAppointment(gmdate('Y-m-d'), gmdate('H:i:s', time() + 3600));
        $svc = $this->service(new FakeLicenseGate(false));

        $visit = $svc->checkIn($this->secretaryUserId, $this->patientId, $apptId);

        // ویزیت در جریان — Call/Start پزشک نباید توسط Gate متوقف شود
        $called = $svc->transition($this->doctorUserId, (int) $visit['id'], 'call');
        $this->assertSame('called', $called['status']);

        $started = $svc->transition($this->doctorUserId, (int) $visit['id'], 'start');
        $this->assertSame('in_consultation', $started['status']);

        $completed = $svc->transition($this->doctorUserId, (int) $visit['id'], 'complete');
        $this->assertSame('consultation_completed', $completed['status']);
    }

    // ================= Helpers =================

    private function service(LicenseGate $gate): VisitService
    {
        $db = App::db();

        return new VisitService(
            $db,
            new VisitRepository($db),
            new AppointmentRepository($db),
            App::settings(),
            App::audit(),
            $gate
        );
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function makeAppointment(string $slotDate, string $slotTime): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianId,
                $slotDate,
                $slotTime,
                $now,
                $now
            )
        );
        $slotId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                     (clinic_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time,
                      duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)
                 VALUES (1, %s, %d, %d, %d, %s, %s, 20, %s, "confirmed", 0, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'LG-' . bin2hex(random_bytes(6)),
                $this->patientId,
                $this->clinicianId,
                $slotId,
                $slotDate,
                $slotTime,
                gmdate('H:i:s', strtotime($slotTime) + 1200),
                $now,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }
}

/**
 * Gate تزریقی تست — تصمیم ثابت، بدون I/O (قرارداد ADR-0023).
 */
final class FakeLicenseGate implements LicenseGate
{
    public function __construct(private readonly bool $allowed)
    {
    }

    public function assert(string $operation, array $context = []): LicenseDecision
    {
        return $this->allowed
            ? LicenseDecision::allow()
            : LicenseDecision::deny('license:expired');
    }

    public function state(): string
    {
        return $this->allowed ? 'active' : 'expired';
    }

    public function isReadOnly(): bool
    {
        return !$this->allowed;
    }
}
