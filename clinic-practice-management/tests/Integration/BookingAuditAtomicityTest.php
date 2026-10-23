<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Booking\BookingException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * D6 — BookingService mandatory-audit atomicity (real-MySQL fault injection).
 *
 * Every mutating booking operation (hold / staff create / reschedule /
 * no-show / staff cancel) must share ONE CpmsDb boundary with its mandatory
 * audit event. A real MySQL 1054 injected only into cpms_audit_logs INSERTs
 * proves, per operation:
 *  1. the exception propagates (no false success);
 *  2. every business row rolls back (appointment / hold / slot counters);
 *  3. no audit row survives (the failed audit is never silently skipped);
 *  4. the SAVEPOINT stack returns to its baseline depth;
 *  5. once the fault is removed, the same authorized request succeeds and its
 *     audit row is attributed to the trusted Clinic (clinic_id = 1);
 *  6. authorization is still enforced before any mutation (ownership 403).
 */
final class BookingAuditAtomicityTest extends WP_UnitTestCase
{
    private int $clinicianId;

    private int $patientId;

    private int $patientUserId;

    private int $otherUserId;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        App::replaceExplicitScope(ClinicScope::forClinic(1));
        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('booking.hold_ttl_sec', 600);
        App::settings()->set('booking.cancel_deadline_hours', 24);
        App::settings()->set('booking.reschedule_deadline_hours', 24);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Atomic',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Atom", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-ATOM-0001',
                '09121110001',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $this->patientUserId = (int) wp_create_user('ba_patient_' . bin2hex(random_bytes(3)), 'pass-12345', 'ba1@test.local', ['role' => 'cpms_patient']);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (1, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->patientId,
                $this->patientUserId,
                '09121110001',
                $now
            )
        );
        $this->otherUserId = (int) wp_create_user('ba_other_' . bin2hex(random_bytes(3)), 'pass-12345', 'ba2@test.local', ['role' => 'cpms_patient']);
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        App::resetScope();
        parent::tearDown();
    }

    public function testHoldAuditFailureRollsBackHoldRowAndSlotCounters(): void
    {
        $slot = $this->makeSlot(3, '10:00');

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::bookingService()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory HOLD_CREATED audit must propagate, never report false success');
        self::assertSame(0, $this->slotHolds(), 'no hold row may survive the rolled-back request');
        self::assertSame(0, $this->slotHeldCount($slot['id']), 'the held slot counter must roll back');
        self::assertSame(0, $this->auditCount('HOLD_CREATED'), 'the failed audit must never be partially written');
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        // Authorized retry after the fault is removed succeeds and is attributed.
        $hold = App::bookingService()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        self::assertNotEmpty($hold['hold_token']);
        self::assertSame(1, $this->auditCount('HOLD_CREATED'));
        self::assertSame(1, $this->latestAuditClinicId('HOLD_CREATED'), 'tenant attribution must name the trusted Clinic');
    }

    public function testStaffCreateAuditFailureLeavesNoAppointmentAndNoCapacityMutation(): void
    {
        $slot = $this->makeSlot(3, '11:00');

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::bookingService()->createByStaff($this->patientUserId, $this->patientId, $this->clinicianId, $slot['date'], '11:00', 'فوری');
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory APPOINTMENT_CREATED audit must propagate');
        self::assertSame(0, $this->appointmentsForSlot($slot['id']), 'no appointment may survive the rolled-back staff create');
        self::assertSame(0, $this->slotBookedCount($slot['id']), 'booked capacity must roll back');
        self::assertSame(0, $this->auditCount('APPOINTMENT_CREATED'), 'no audit evidence may survive the failed transaction');
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $created = App::bookingService()->createByStaff($this->patientUserId, $this->patientId, $this->clinicianId, $slot['date'], '11:00', 'فوری');
        self::assertSame('confirmed', $created['status']);
        self::assertSame(1, $this->auditCount('APPOINTMENT_CREATED'));
        self::assertSame(1, $this->latestAuditClinicId('APPOINTMENT_CREATED'));
    }

    public function testRescheduleAuditFailureRestoresOriginalAppointmentAndBothSlots(): void
    {
        $old_slot = $this->makeSlot(3, '12:00');
        $new_slot = $this->makeSlot(5, '14:00');
        $appt = $this->confirmOn($old_slot);

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::bookingService()->reschedule(
                $this->patientUserId,
                (int) $appt['appointment_id'],
                $this->clinicianId,
                $new_slot['date'],
                '14:00',
                $this->uuid()
            );
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory APPOINTMENT_RESCHEDULED audit must propagate');
        $row = $this->appointmentRow((int) $appt['appointment_id']);
        self::assertSame('confirmed', (string) $row['status'], 'the original appointment must remain confirmed');
        self::assertNull($row['rescheduled_to'], 'no reschedule link may survive the rollback');
        self::assertSame(1, $this->slotBookedCount($old_slot['id']), 'the old slot keeps its booking');
        self::assertSame(0, $this->slotBookedCount($new_slot['id']), 'the target slot must not stay consumed');
        self::assertSame(0, $this->auditCount('APPOINTMENT_RESCHEDULED'));
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $retry = App::bookingService()->reschedule(
            $this->patientUserId,
            (int) $appt['appointment_id'],
            $this->clinicianId,
            $new_slot['date'],
            '14:00',
            $this->uuid()
        );
        self::assertSame('confirmed', $retry['status']);
        self::assertSame(1, $this->auditCount('APPOINTMENT_RESCHEDULED'));
        self::assertSame(1, $this->latestAuditClinicId('APPOINTMENT_RESCHEDULED'));
    }

    public function testNoShowAuditFailureKeepsConfirmedStatus(): void
    {
        $slot = $this->makeSlot(3, '15:00');
        $appt = $this->confirmOn($slot);

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::bookingService()->markNoShowByStaff($this->patientUserId, (int) $appt['appointment_id'], 'عدم حضور بیمار');
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory APPOINTMENT_NO_SHOW audit must propagate');
        $row = $this->appointmentRow((int) $appt['appointment_id']);
        self::assertSame('confirmed', (string) $row['status'], 'the appointment must keep its confirmed status');
        self::assertNull($row['no_show_at'], 'no no-show timestamp may survive the rollback');
        self::assertSame(0, $this->auditCount('APPOINTMENT_NO_SHOW'));
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $retry = App::bookingService()->markNoShowByStaff($this->patientUserId, (int) $appt['appointment_id'], 'عدم حضور بیمار');
        self::assertSame('no_show', $retry['status']);
        self::assertSame(1, $this->auditCount('APPOINTMENT_NO_SHOW'));
        self::assertSame(1, $this->latestAuditClinicId('APPOINTMENT_NO_SHOW'));
    }

    public function testStaffCancelAuditFailureKeepsAppointmentAndCapacity(): void
    {
        $slot = $this->makeSlot(3, '16:00');
        $appt = $this->confirmOn($slot);

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::bookingService()->cancelByStaff($this->patientUserId, (int) $appt['appointment_id'], 'درخواست بیمار');
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory APPOINTMENT_CANCELLED audit must propagate');
        $row = $this->appointmentRow((int) $appt['appointment_id']);
        self::assertSame('confirmed', (string) $row['status'], 'the appointment must remain confirmed');
        self::assertSame(1, $this->slotBookedCount($slot['id']), 'cancelled capacity cannot be released by a rolled-back cancel');
        self::assertSame(0, $this->auditCount('APPOINTMENT_CANCELLED'));
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $retry = App::bookingService()->cancelByStaff($this->patientUserId, (int) $appt['appointment_id'], 'درخواست بیمار');
        self::assertSame('cancelled_by_staff', $retry['status']);
        self::assertSame(1, $this->auditCount('APPOINTMENT_CANCELLED'));
        self::assertSame(1, $this->latestAuditClinicId('APPOINTMENT_CANCELLED'));
    }

    public function testUnauthorizedConfirmIsRejectedBeforeAnyMutation(): void
    {
        $slot = $this->makeSlot(3, '17:00');
        $hold = App::bookingService()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        try {
            App::bookingService()->confirm($hold['hold_token'], $this->otherUserId, null, $this->uuid());
            self::fail('a user without hold ownership must not confirm the appointment');
        } catch (BookingException $e) {
            self::assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }

        self::assertSame(0, $this->appointmentsForSlot($slot['id']), 'a denied request must never mutate appointments');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{id: int, date: string, time: string, location_id: int}
     */
    private function makeSlot(int $day_offset, string $time, int $capacity = 1): array
    {
        global $wpdb;
        $location_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d AND is_primary = 1 LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                1
            )
        );
        self::assertGreaterThan(0, $location_id, 'A persisted primary Location is required.');
        $date = gmdate('Y-m-d', time() + $day_offset * 86400);
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %d, %s, %s, 20, %d, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $location_id,
                $this->clinicianId,
                $date,
                $time,
                $capacity,
                $now,
                $now
            )
        );

        return ['id' => (int) $wpdb->insert_id, 'date' => $date, 'time' => $time, 'location_id' => $location_id];
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmOn(array $slot): array
    {
        $hold = App::bookingService()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        return App::bookingService()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());
    }

    private function uuid(): string
    {
        $d = static fn (int $len): string => bin2hex(random_bytes((int) ceil($len / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $d(8), $d(4), substr($d(3), 0, 3), substr($d(4), 0, 4), $d(12));
    }

    private function slotBookedCount(int $slot_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slot_id
            )
        );
    }

    private function slotHeldCount(int $slot_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slot_id
            )
        );
    }

    private function slotHolds(): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_slot_holds'));
    }

    private function appointmentsForSlot(int $slot_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_appointments WHERE slot_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slot_id
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentRow(int $appointment_id): array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, rescheduled_to, no_show_at FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $appointment_id
            ),
            ARRAY_A
        );
        self::assertIsArray($row);

        return $row;
    }

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            [$action]
        );
    }

    private function latestAuditClinicId(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            [$action]
        );
    }

    private function savepointDepth(): int
    {
        return is_array($GLOBALS['__cpms_sp_stack'] ?? null) ? count($GLOBALS['__cpms_sp_stack']) : 0;
    }

    private function failAuditInserts(): void
    {
        global $wpdb;

        self::assertNull($this->auditFault, 'the audit fault must not be installed twice');

        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\A\s*INSERT\s+INTO\s+(\S+)/i', $sql, $m) === 1
                && str_contains($m[1], 'cpms_audit_logs')) {
                return 'INSERT INTO ' . $m[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }

            return $query;
        };

        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        add_filter('query', $fault, 5);
        $this->auditFault = $fault;
    }

    private function restoreAuditInserts(): void
    {
        if ($this->auditFault === null) {
            return;
        }

        remove_filter('query', $this->auditFault, 5);
        $this->auditFault = null;

        global $wpdb;
        $wpdb->suppress_errors($this->previousSuppression);
    }
}
