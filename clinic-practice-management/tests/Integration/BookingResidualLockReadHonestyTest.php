<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Eight residual FOR UPDATE reads in BookingService used to mask a driver
 * error as a legacy envelope. Each is now guarded by assert_read_succeeded(),
 * which rolls the transaction back, emits the booking.lock_read_failed
 * diagnostic and answers 503 CLINIC_BOOKING_BUSY. A genuinely absent row keeps
 * the exact legacy 404/409/422 code and Persian text.
 *
 * NEW file only. Fault injection follows BookingLocationLockFailureHonestyTest
 * (PR #311): add_filter('query') + suppress_errors() rewrites ONE matched read
 * class; setup and direct service calls follow BookingFlowTest. No existing
 * test is edited or weakened.
 *
 * Reads covered: confirm (hold row, slot), rescheduleAs (appointment, first
 * slot, second slot), createByStaff (slot), markNoShowByStaff (appointment),
 * cancel (appointment).
 *
 * Honest limitation (already documented by PR #311): cancel() and
 * markNoShowByStaff() use a raw CpmsDb::transactional() boundary with no
 * booking_transaction() wrapper, so the diagnostic those two paths write is
 * rolled back with the transaction. Their tests therefore assert the 503 only;
 * the failure is never masked and no state is changed. Transaction boundaries
 * are deliberately NOT changed by this PR.
 */
final class BookingResidualLockReadHonestyTest extends WP_UnitTestCase
{
    private const LOG_MESSAGE = 'booking.lock_read_failed';
    private const BUSY_CODE = 'CLINIC_BOOKING_BUSY';
    private const NOT_FOUND_TEXT = 'نوبت یافت نشد';
    private const SLOT_GONE_TEXT = 'اسلات مبدأ یا مقصد '
        . 'دیگر در دسترس نیست';
    private const STAFF_SLOT_TEXT = 'اسلات یافت نشد — '
        . 'ممکن است Schedule پزشک '
        . 'برای این روز تعریف نشده باشد';
    private const HOLD_GONE_TEXT = 'جلسه رزرو منقضی/بسته شده — '
        . 'دوباره Slot انتخاب کنید';

    private int $clinicianId = 0;
    private int $patientUserId = 0;
    private int $patientId = 0;
    private int $locationId = 0;
    private int $logBaseline = 0;
    private ?\Closure $fault = null;
    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('booking.hold_ttl_sec', 600);
        App::settings()->set('booking.cancel_deadline_hours', 24);
        App::settings()->set('booking.reschedule_deadline_hours', 24);
        wp_set_current_user(0);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                 (clinic_id, full_name, is_active, created_at, updated_at)
             VALUES (1, %s, 1, %s, %s)',
            'Dr Residual ' . bin2hex(random_bytes(5)),
            $now,
            $now
        ));
        $this->clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicianId, 'precondition: clinician');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
             VALUES (1, %s, %s, %s, %s, %s, %s, %s)',
            'MR-RESIDUAL-' . bin2hex(random_bytes(5)),
            'A',
            'Patient',
            '09121110001',
            'active',
            $now,
            $now
        ));
        $this->patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->patientId, 'precondition: patient');

        $this->patientUserId = (int) wp_create_user(
            'residual_' . bin2hex(random_bytes(5)),
            'pass-12345',
            bin2hex(random_bytes(5)) . '@otp.cpms.local'
        );
        $user = get_userdata($this->patientUserId);
        self::assertNotFalse($user, 'precondition: patient WP user');
        $user->set_role('cpms_patient');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                 (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
             VALUES (1, %d, %d, %s, 1, %s)',
            $this->patientId,
            $this->patientUserId,
            '09121110001',
            $now
        ));
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'precondition: link');

        $this->locationId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations')
            . ' WHERE clinic_id = 1 AND is_active = 1 LIMIT 1'
        );
        self::assertGreaterThan(0, $this->locationId, 'precondition: Location');

        $this->logBaseline = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_operational_logs')
            . ' WHERE message = %s',
            [self::LOG_MESSAGE]
        );
    }

    protected function tearDown(): void
    {
        $this->removeFault();
        App::resetScope();
        parent::tearDown();
    }

    private function booking()
    {
        return App::bookingService();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @return array{id: int, date: string, time: string} */
    private function makeSlot(string $time, int $dayOffset = 3): array
    {
        global $wpdb;
        $date = gmdate('Y-m-d', time() + $dayOffset * 86400);
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                 (clinic_id, location_id, clinician_id, slot_date, slot_time,
                  duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
             VALUES (1, %d, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)',
            $this->locationId,
            $this->clinicianId,
            $date,
            $time,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: slot');

        return ['id' => $id, 'date' => $date, 'time' => $time];
    }

    /** @return array{token: string, slot_id: int} */
    private function makeHold(): array
    {
        $slot = $this->makeSlot('10:00');
        $hold = $this->booking()->hold(
            $this->patientUserId,
            $this->clinicianId,
            $slot['date'],
            $slot['time']
        );

        return [
            'token'   => (string) $hold['hold_token'],
            'slot_id' => $slot['id'],
        ];
    }

    /** @return array{id: int, slot_id: int} */
    private function makeAppointment(): array
    {
        $hold = $this->makeHold();
        $appt = $this->booking()->confirm(
            $hold['token'],
            $this->patientUserId,
            null,
            $this->uuid()
        );

        return [
            'id'      => (int) $appt['appointment_id'],
            'slot_id' => $hold['slot_id'],
        ];
    }

    /** @return array<string, mixed> */
    private function diagnosticLogs(): array
    {
        return App::db()->fetchAll(
            'SELECT id, level, message, context_json FROM '
            . App::db()->table('cpms_operational_logs')
            . ' WHERE message = %s ORDER BY id',
            [self::LOG_MESSAGE]
        );
    }

    /** @return array<string, mixed> */
    private function lastDiagnostic(): array
    {
        $rows = $this->diagnosticLogs();
        self::assertCount($this->logBaseline + 1, $rows, 'exactly one new diagnostic');
        $context = json_decode((string) $rows[count($rows) - 1]['context_json'], true);
        self::assertIsArray($context);

        return $context;
    }

    /**
     * Rewrite the Nth matched read class. 'error' makes the driver fail;
     * 'absent' returns no row with no SQL error (a genuinely absent row).
     */
    private function installFault(string $mode, string $target, int $onMatch = 1): void
    {
        global $wpdb;
        self::assertNull($this->fault, 'fault injection must be installed once per test');

        $seen = 0;
        $fault = static function (string $query) use ($mode, $target, $onMatch, &$seen): string {
            if (!self::matchesTarget($query, $target)) {
                return $query;
            }
            ++$seen;
            if ($seen !== $onMatch) {
                return $query;
            }
            $table = self::tableFrom($query);
            if ($table === '') {
                return $query;
            }
            if ($mode === 'absent') {
                return 'SELECT 1 FROM ' . $table . ' WHERE 1 = 0 LIMIT 1';
            }

            return 'SELECT 1 FROM ' . $table
                . ' WHERE cpms_fault_injection_no_such_column = 1 LIMIT 1';
        };

        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        $this->fault = $fault;
        add_filter('query', $fault, 5);
    }

    private function removeFault(): void
    {
        if ($this->fault === null) {
            return;
        }
        global $wpdb;
        remove_filter('query', $this->fault, 5);
        $this->fault = null;
        $wpdb->suppress_errors($this->previousSuppression);
    }

    /** Match one read class, never a whole table, so other reads stay real. */
    private static function matchesTarget(string $query, string $target): bool
    {
        if (stripos($query, 'SELECT * FROM') === false
            || stripos($query, 'FOR UPDATE') === false) {
            return false;
        }

        switch ($target) {
            case 'hold_lock':
                return stripos($query, 'cpms_slot_holds') !== false;
            case 'slot_lock':
                return stripos($query, 'cpms_schedule_slots') !== false;
            case 'appointment_lock':
                return stripos($query, 'cpms_appointments') !== false;
        }

        return false;
    }

    private static function tableFrom(string $query): string
    {
        if (preg_match('/FROM\s+(`?[A-Za-z0-9_]+`?)/i', $query, $parts) === 1) {
            return $parts[1];
        }

        return '';
    }

    /**
     * @param callable(): void $action
     */
    private function assertBusy(
        string $target,
        callable $action,
        string $read,
        int $onMatch = 1,
        bool $diagnostic = true
    ): void {
        $this->installFault('error', $target, $onMatch);
        try {
            $action();
            self::fail('a driver-level read failure must not be masked as a legacy envelope');
        } catch (BookingException $e) {
            self::assertSame(self::BUSY_CODE, $e->errorCode);
            self::assertSame(503, $e->httpStatus);
            self::assertStringContainsString('دوباره تلاش', $e->getMessage());
        }
        if ($diagnostic) {
            self::assertSame($read, (string) ($this->lastDiagnostic()['read'] ?? ''));
        }
        // With $diagnostic = false the raw transactional() boundary rolls the
        // in-transaction diagnostic back (PR #311 limitation); only the 503 is
        // asserted, and no transaction boundary is changed by this PR.
    }

    /**
     * @param callable(): void $action
     */
    private function assertAbsent(
        string $target,
        callable $action,
        string $code,
        int $http,
        string $text,
        int $onMatch = 1
    ): void {
        $this->installFault('absent', $target, $onMatch);
        try {
            $action();
            self::fail('a genuinely absent row must keep its legacy envelope');
        } catch (BookingException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($http, $e->httpStatus);
            self::assertSame($text, $e->getMessage());
        }
        self::assertCount($this->logBaseline, $this->diagnosticLogs());
    }

    // ---------- 503 on a simulated driver error (one per newly guarded read) ----------

    public function testConfirmHoldLockFailureIsRetryable(): void
    {
        $hold = $this->makeHold();
        $this->assertBusy('hold_lock', function () use ($hold): void {
            $this->booking()->confirm($hold['token'], $this->patientUserId, null, $this->uuid());
        }, 'confirm_hold');
    }

    public function testConfirmSlotLockFailureIsRetryable(): void
    {
        $hold = $this->makeHold();
        $this->assertBusy('slot_lock', function () use ($hold): void {
            $this->booking()->confirm($hold['token'], $this->patientUserId, null, $this->uuid());
        }, 'confirm_slot');
    }

    public function testRescheduleAppointmentLockFailureIsRetryable(): void
    {
        $appt = $this->makeAppointment();
        $newSlot = $this->makeSlot('14:00', 5);
        $this->assertBusy('appointment_lock', function () use ($appt, $newSlot): void {
            $this->booking()->reschedule(
                $this->patientUserId,
                $appt['id'],
                $this->clinicianId,
                $newSlot['date'],
                '14:00',
                $this->uuid()
            );
        }, 'reschedule_appointment');
    }

    public function testRescheduleFirstSlotLockFailureIsRetryable(): void
    {
        $appt = $this->makeAppointment();
        $newSlot = $this->makeSlot('14:00', 5);
        $this->assertBusy('slot_lock', function () use ($appt, $newSlot): void {
            $this->booking()->reschedule(
                $this->patientUserId,
                $appt['id'],
                $this->clinicianId,
                $newSlot['date'],
                '14:00',
                $this->uuid()
            );
        }, 'reschedule_first_slot', 1);
    }

    public function testRescheduleSecondSlotLockFailureIsRetryable(): void
    {
        $appt = $this->makeAppointment();
        $newSlot = $this->makeSlot('14:00', 5);
        $this->assertBusy('slot_lock', function () use ($appt, $newSlot): void {
            $this->booking()->reschedule(
                $this->patientUserId,
                $appt['id'],
                $this->clinicianId,
                $newSlot['date'],
                '14:00',
                $this->uuid()
            );
        }, 'reschedule_second_slot', 2);
    }

    public function testStaffCreateSlotLockFailureIsRetryable(): void
    {
        $slot = $this->makeSlot('10:00', 1);
        $this->assertBusy('slot_lock', function () use ($slot): void {
            $this->booking()->createByStaff(
                $this->patientUserId,
                $this->patientId,
                $this->clinicianId,
                $slot['date'],
                '10:00',
                'فوری'
            );
        }, 'staff_create_slot');
    }

    public function testNoShowAppointmentLockFailureIsRetryable(): void
    {
        $appt = $this->makeAppointment();
        $this->assertBusy('appointment_lock', function () use ($appt): void {
            $this->booking()->markNoShowByStaff(
                $this->patientUserId,
                $appt['id'],
                'عدم حضور بیمار'
            );
        }, 'no_show_appointment', 1, false);
    }

    public function testCancelAppointmentLockFailureIsRetryable(): void
    {
        $appt = $this->makeAppointment();
        $this->assertBusy('appointment_lock', function () use ($appt): void {
            $this->booking()->cancelByStaff(
                $this->patientUserId,
                $appt['id'],
                'درخواست بیمار'
            );
        }, 'cancel_appointment', 1, false);
    }

    // ---------- genuinely absent row keeps the exact legacy envelope ----------

    public function testConfirmAbsentHoldKeepsLegacy422(): void
    {
        $hold = $this->makeHold();
        $this->assertAbsent('hold_lock', function () use ($hold): void {
            $this->booking()->confirm($hold['token'], $this->patientUserId, null, $this->uuid());
        }, 'CLINIC_HOLD_EXPIRED', 422, self::HOLD_GONE_TEXT);
    }

    public function testConfirmAbsentSlotKeepsLegacy409(): void
    {
        $hold = $this->makeHold();
        $this->assertAbsent('slot_lock', function () use ($hold): void {
            $this->booking()->confirm($hold['token'], $this->patientUserId, null, $this->uuid());
        }, 'CLINIC_SLOT_TAKEN', 409, 'اسلات دیگر در دسترس نیست');
    }

    public function testRescheduleAbsentAppointmentKeepsLegacy404(): void
    {
        $appt = $this->makeAppointment();
        $newSlot = $this->makeSlot('14:00', 5);
        $this->assertAbsent('appointment_lock', function () use ($appt, $newSlot): void {
            $this->booking()->reschedule(
                $this->patientUserId,
                $appt['id'],
                $this->clinicianId,
                $newSlot['date'],
                '14:00',
                $this->uuid()
            );
        }, 'CLINIC_NOT_FOUND', 404, self::NOT_FOUND_TEXT);
    }

    public function testRescheduleAbsentFirstSlotKeepsLegacy409(): void
    {
        $appt = $this->makeAppointment();
        $newSlot = $this->makeSlot('14:00', 5);
        $this->assertAbsent('slot_lock', function () use ($appt, $newSlot): void {
            $this->booking()->reschedule(
                $this->patientUserId,
                $appt['id'],
                $this->clinicianId,
                $newSlot['date'],
                '14:00',
                $this->uuid()
            );
        }, 'CLINIC_SLOT_TAKEN', 409, self::SLOT_GONE_TEXT);
    }

    public function testRescheduleAbsentSecondSlotKeepsLegacy409(): void
    {
        $appt = $this->makeAppointment();
        $newSlot = $this->makeSlot('14:00', 5);
        $this->assertAbsent('slot_lock', function () use ($appt, $newSlot): void {
            $this->booking()->reschedule(
                $this->patientUserId,
                $appt['id'],
                $this->clinicianId,
                $newSlot['date'],
                '14:00',
                $this->uuid()
            );
        }, 'CLINIC_SLOT_TAKEN', 409, self::SLOT_GONE_TEXT, 2);
    }

    public function testStaffCreateAbsentSlotKeepsLegacy404(): void
    {
        $slot = $this->makeSlot('10:00', 1);
        $this->assertAbsent('slot_lock', function () use ($slot): void {
            $this->booking()->createByStaff(
                $this->patientUserId,
                $this->patientId,
                $this->clinicianId,
                $slot['date'],
                '10:00',
                'فوری'
            );
        }, 'CLINIC_NOT_FOUND', 404, self::STAFF_SLOT_TEXT);
    }

    public function testNoShowAbsentAppointmentKeepsLegacy404(): void
    {
        $appt = $this->makeAppointment();
        $this->assertAbsent('appointment_lock', function () use ($appt): void {
            $this->booking()->markNoShowByStaff(
                $this->patientUserId,
                $appt['id'],
                'عدم حضور بیمار'
            );
        }, 'CLINIC_NOT_FOUND', 404, self::NOT_FOUND_TEXT);
    }

    public function testCancelAbsentAppointmentKeepsLegacy404(): void
    {
        $appt = $this->makeAppointment();
        $this->assertAbsent('appointment_lock', function () use ($appt): void {
            $this->booking()->cancelByStaff(
                $this->patientUserId,
                $appt['id'],
                'درخواست بیمار'
            );
        }, 'CLINIC_NOT_FOUND', 404, self::NOT_FOUND_TEXT);
    }

    // ---------- normal (clean) behaviour is unchanged ----------

    public function testConfirmCleanPathSucceedsWithNoDiagnostic(): void
    {
        $hold = $this->makeHold();
        $appt = $this->booking()->confirm(
            $hold['token'],
            $this->patientUserId,
            null,
            $this->uuid()
        );
        self::assertSame('confirmed', (string) $appt['status']);
        self::assertCount($this->logBaseline, $this->diagnosticLogs());
    }

    public function testRescheduleCleanPathSucceedsWithNoDiagnostic(): void
    {
        $appt = $this->makeAppointment();
        $newSlot = $this->makeSlot('14:00', 5);
        $result = $this->booking()->reschedule(
            $this->patientUserId,
            $appt['id'],
            $this->clinicianId,
            $newSlot['date'],
            '14:00',
            $this->uuid()
        );
        self::assertNotSame($appt['id'], (int) $result['appointment_id']);
        self::assertCount($this->logBaseline, $this->diagnosticLogs());
    }

    public function testStaffCreateCleanPathSucceedsWithNoDiagnostic(): void
    {
        $slot = $this->makeSlot('10:00', 1);
        $created = $this->booking()->createByStaff(
            $this->patientUserId,
            $this->patientId,
            $this->clinicianId,
            $slot['date'],
            '10:00',
            'فوری'
        );
        self::assertSame('confirmed', (string) $created['status']);
        self::assertCount($this->logBaseline, $this->diagnosticLogs());
    }

    public function testNoShowCleanPathSucceedsWithNoDiagnostic(): void
    {
        $appt = $this->makeAppointment();
        $result = $this->booking()->markNoShowByStaff(
            $this->patientUserId,
            $appt['id'],
            'عدم حضور بیمار'
        );
        self::assertSame('no_show', (string) $result['status']);
        self::assertCount($this->logBaseline, $this->diagnosticLogs());
    }

    public function testCancelCleanPathSucceedsWithNoDiagnostic(): void
    {
        $appt = $this->makeAppointment();
        $result = $this->booking()->cancelByStaff(
            $this->patientUserId,
            $appt['id'],
            'درخواست بیمار'
        );
        self::assertSame('cancelled_by_staff', (string) $result['status']);
        self::assertCount($this->logBaseline, $this->diagnosticLogs());
    }
}
