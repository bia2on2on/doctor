<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Visits\VisitException;
use WP_UnitTestCase;

/**
 * D7 — VisitService mandatory-audit propagation (real-MySQL fault injection).
 *
 * The second auditor block required that a failed mandatory audit can no
 * longer be swallowed inside VisitService: every paired audit runs inside the
 * same CpmsDb boundary as the visit/appointment mutation, so a real MySQL 1054
 * on cpms_audit_logs INSERT must:
 *
 *  - propagate out of checkIn() and roll the visit + appointment link back
 *    (no un-audited mutation, no false success), with SAVEPOINT depth restored;
 *  - leave an authorized retry fully functional after the fault is removed;
 *  - in processNoShows() roll only the single failing item back while the
 *    cron tick continues (no fatal abort; the item is retried next tick);
 *  - NOT change denial paths: a refused operation still throws its domain
 *    exception because denial audits are deliberately best-effort.
 */
final class VisitAuditAtomicityTest extends WP_UnitTestCase
{
    private int $clinicianId;
    private int $patientId;
    private int $secretaryUserId;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('queue.no_show_grace_minutes', 30);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Visit Atomicity',
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
                'MR-VAT-0001',
                '09124440001',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $this->secretaryUserId = $this->makeUser('vat_secretary', 'cpms_secretary');
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc(null);
        parent::tearDown();
    }

    public function testCheckInAuditFailureRollsBackVisitAndAppointmentLink(): void
    {
        $tz = new \DateTimeZone('Asia/Tehran');
        $local_day = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $dt = new \DateTimeImmutable($local_day . ' 14:00:00', $tz);
        $arrival = new \DateTimeImmutable($local_day . ' 09:00:00', $tz);
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($arrival->setTimezone(new \DateTimeZone('UTC')));
        $apptId = $this->makeAppointment($this->patientId, $this->clinicianId, $dt->format('Y-m-d'), $dt->format('H:i:s'));
        $depthBefore = $this->savepointDepth();

        $this->failAuditInserts();
        $threw = false;
        try {
            App::visitService()->checkIn($this->secretaryUserId, $this->patientId, $apptId);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory check-in audit must propagate, never return false success');
        self::assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits')), 'no visit row may survive the rolled-back check-in');
        self::assertNull(App::db()->fetchValue('SELECT active_visit_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d', [$apptId]), 'the appointment link must roll back with the visit');
        self::assertSame('confirmed', (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d', [$apptId]));
        self::assertSame(0, $this->auditCount('VISIT_CHECKED_IN'), 'no un-audited check-in may survive');
        self::assertSame($depthBefore, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        // Authorized retry after the fault is removed commits visit + audit together.
        $visit = App::visitService()->checkIn($this->secretaryUserId, $this->patientId, $apptId);
        self::assertSame('waiting', (string) $visit['status']);
        self::assertSame(1, $this->auditCount('VISIT_CHECKED_IN'));
        self::assertSame(1, $this->latestAuditClinicId('VISIT_CHECKED_IN'), 'the visit audit must name the trusted Clinic explicitly');
        self::assertSame($depthBefore, $this->savepointDepth());
    }

    public function testProcessNoShowsContinuesAfterPerItemAuditFailureAndRetriesAfterwards(): void
    {
        $t = time() - 7200;
        $dt = (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('Asia/Tehran'));
        $late = $this->makeAppointment($this->patientId, $this->clinicianId, $dt->format('Y-m-d'), $dt->format('H:i:s'));
        $depthBefore = $this->savepointDepth();

        $this->failAuditInserts();
        // The tick must NOT abort: the failing item rolls back inside its own
        // boundary and the cron call returns its normal result shape.
        $res = App::visitService()->processNoShows();
        $this->restoreAuditInserts();

        self::assertIsArray($res);
        self::assertSame(0, (int) ($res['processed'] ?? -1), 'the faulted no-show item must not be counted as processed');
        self::assertSame('confirmed', (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d', [$late]), 'the item must stay confirmed after its rolled-back boundary');
        self::assertSame(0, $this->auditCount('APPOINTMENT_NO_SHOW'));
        self::assertSame($depthBefore, $this->savepointDepth(), 'SAVEPOINT depth must be restored after the item rollback');

        // Next tick (fault removed) durably marks the unchanged item.
        $retry = App::visitService()->processNoShows();
        self::assertSame(1, (int) ($retry['processed'] ?? 0));
        self::assertSame('no_show', (string) App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d', [$late]));
        self::assertSame(1, $this->auditCount('APPOINTMENT_NO_SHOW'));
        self::assertSame(1, $this->latestAuditClinicId('APPOINTMENT_NO_SHOW'), 'the no-show audit must name the trusted Clinic explicitly');
    }

    public function testDenialAuditFailureDoesNotReplaceTheDomainRefusal(): void
    {
        $t = time() + 3600;
        $dt = (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('Asia/Tehran'));
        $apptId = $this->makeAppointment($this->patientId, $this->clinicianId, $dt->format('Y-m-d'), $dt->format('H:i:s'));

        // A second patient that does not own the appointment.
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Other", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-VAT-0002',
                '09124440002',
                $now,
                $now
            )
        );
        $otherPatientId = (int) $wpdb->insert_id;

        $this->failAuditInserts();
        $threw = false;
        try {
            App::visitService()->checkIn($this->secretaryUserId, $otherPatientId, $apptId);
        } catch (VisitException $e) {
            $threw = true;
            self::assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'the domain denial must remain authoritative even when its best-effort audit cannot be persisted');
        self::assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits')), 'a denial never mutates state');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function makeAppointment(int $patientId, int $clinicianId, string $slotDate, string $slotTime): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, (SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1), %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicianId,
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
                     (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time,
                      duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)
                 VALUES (1, (SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1), %s, %d, %d, %d, %s, %s, 20, %s, "confirmed", 0, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'VAT-' . bin2hex(random_bytes(6)),
                $patientId,
                $clinicianId,
                $slotId,
                $slotDate,
                $slotTime,
                (new \DateTimeImmutable($slotDate . ' ' . $slotTime, new \DateTimeZone('Asia/Tehran')))->add(new \DateInterval('PT20M'))->format('H:i:s'),
                $now,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
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
}
