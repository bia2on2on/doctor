<?php

/**
 * Zero-row guard on the cancel path — DISPOSABLE schema, fault injected at
 * the SQL layer, exercised through the production stack AND the controller's
 * HTTP mapping.
 *
 * Review-driven addition (2026-11-07, item 4). The previous envelope-level
 * unit test (CancelZeroRowGuardErrorMappingTest) drives BookingController::wrap()
 * with a fabricated RuntimeException; it proves the mapping exists but NOT
 * that the production zero-row guard inside BookingService::cancel() fires,
 * rolls the transaction back, leaves capacity untouched, and reaches that
 * mapping when the database REALLY refuses the UPDATE (soft-mode driver
 * error => updateStatus() returns 0, no exception).
 *
 * This test closes that gap end to end:
 *
 *  - Every fixture (org/clinic/location/clinician/slot/appointment) lives in
 *    a per-test DISPOSABLE schema (`cpms_zg_<hex>`), migrated for real and
 *    dropped in tearDown's finally — never the shared WP test DB. See
 *    IsolatedSchema.php.
 *  - The appointment is produced by the PRODUCTION booking flow on this
 *    disposable schema (hold -> confirm, both committed), so the guard is
 *    tested against a real confirmed appointment, not a hand-built row.
 *  - The failure is injected by a test-only wpdb subclass that rewrites the
 *    appointment status UPDATE's table to a missing one; the REAL driver
 *    reports the real failure and the guard sees 0 affected rows. No error
 *    message is fabricated; fault delivery is asserted (exactly once).
 *  - Assertions: transaction rolled back (appointment still confirmed, no
 *    cancelled_at), slot booked_count unchanged (release_booking never
 *    executed), APPOINTMENT_CANCELLED never audited, fault fired exactly
 *    once, and the controller maps Throwable -> CLINIC_INTERNAL_ERROR / 500
 *    with the generic Persian message and NO internal details (no table
 *    name, no exception class/message, no path).
 *  - A fault-free retry afterwards must succeed, decrement booked_count and
 *    write APPOINTMENT_CANCELLED — proving the failure does not wedge the
 *    hold/appointment.
 *
 * NOT EXECUTED in the authoring sandbox: no MySQL 8.x / MariaDB 11.4.13
 * server can be provisioned from the allowed sources, and the WP test
 * bootstrap aborts at migration 0027 on older engines. This file must not be
 * described as passing until CI (or an equivalent supported InnoDB) runs it.
 *
 * PR #330 — review items 3/4. All fixtures are disposable.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Rest\BookingController;
use WP_Error;
use WP_UnitTestCase;

require_once __DIR__ . '/IsolatedSchema.php';
require_once __DIR__ . '/RealTableMigrations.php';

final class CancelZeroRowGuardTest extends WP_UnitTestCase
{
    use RealTableMigrations;
    use IsolatedSchema;

    private const GO_DEADLINE_SEC = 20.0; // unused, kept for symmetry with sibling suites

    private int $appointmentId = 0;
    private int $slotId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDisposableSchema('zg');
        $this->migrateDisposableSchema();
        $this->seedWpUsers();
        $this->seedOrganizationGraph();
        // A production booking needs a resolvable mobile: the seeded userA ->
        // link -> active patient provides it (no OTP-email fallback needed).
        $this->seedPatientsAndLinks();
        $this->seedSessions([$this->userAId]);

        $date = gmdate('Y-m-d', time() + 7 * 86400);
        $this->slotId = $this->insertSlot(2, 0, $date);

        // Production booking flow, committed durably into the disposable schema.
        $stack = $this->stackOn($this->fixtureWpdb, false);
        $hold = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->hold($this->userAId, $this->clinicianId, $date, '09:00:00', $this->slotId)
        );
        $appt = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->confirm((string) $hold['hold_token'], $this->userAId, null, $this->uuid())
        );

        $this->appointmentId = (int) $appt['appointment_id'];
        self::assertGreaterThan(0, $this->appointmentId, 'precondition: production confirm created the appointment');
        // Precondition: the booking consumed one capacity unit.
        self::assertSame(
            '1',
            $this->rawScalar('SELECT booked_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $this->slotId)
        );
    }

    protected function tearDown(): void
    {
        try {
            $this->dropDisposableSchema();
        } finally {
            parent::tearDown();
        }
    }

    public function testZeroRowGuardRollsBackAndMapsInternalErrorWithoutLeaking(): void
    {
        // Inject the driver failure: rewrite ONLY the appointment status
        // UPDATE (the only statement mentioning cancelled_at) to a missing
        // table. The driver reports it; the soft-mode updateStatus() returns
        // 0; the guard must fire.
        $fault = static function (string $sql): ?string {
            if (false !== stripos($sql, 'cpms_appointments') && false !== stripos($sql, 'cancelled_at')) {
                return str_ireplace('cpms_appointments', 'cpms_appointments__faultmissing', $sql);
            }
            return null;
        };
        $faultConn = new FaultWpdb(DB_USER, DB_PASSWORD, $this->disposableDb, DB_HOST);
        $faultConn->set_prefix($this->fixtureWpdb->prefix);
        $faultConn->show_errors(false);
        $faultConn->faultCallback = $fault;

        $faultStack = $this->stackOn($faultConn, false);
        $controller = new BookingController($faultStack['service']);
        $wrap = new \ReflectionMethod($controller, 'wrap');
        $wrap->setAccessible(true);

        // Drive the production zero-row guard through the controller's mapping.
        // The outer START TRANSACTION is required: the WP test bootstrap
        // rewrites CpmsDb's marked tx-control statements to savepoints, and
        // MariaDB/MySQL silently ignore SAVEPOINTs when no real transaction
        // is open on the session — without it the rollback-to-savepoint in
        // cancel()'s failure path reports "SAVEPOINT ... does not exist".
        $result = $this->transactionalCommit(
            $faultConn,
            fn () => $wrap->invoke(
                $controller,
                fn (): array => $faultStack['service']->cancelByPatient($this->userAId, $this->appointmentId, 'علت صفر-ردیف')
            )
        );

        try {
            // Defensive: close any implicit transaction left on the fault connection.
            $faultConn->query('ROLLBACK');
        } catch (\Throwable $ignored) {
            unset($ignored);
        }

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('CLINIC_INTERNAL_ERROR', $result->get_error_code());
        self::assertSame(500, (int) ($result->get_error_data()['status'] ?? 0));

        $message = $result->get_error_message();
        self::assertSame('خطای داخلی سرور؛ لطفاً دوباره تلاش کنید.', $message, 'the envelope carries exactly the generic Persian message');
        foreach (['cpms_appointments', 'cancelled_at', 'RuntimeException', 'zero rows', 'affected', '.php'] as $leak) {
            self::assertStringNotContainsString($leak, $message, 'the envelope must not leak internals');
        }

        // The guard fired exactly once ...
        self::assertSame(1, $faultConn->faultFired, 'the injected fault fired exactly once');

        // ... and the transaction was rolled back: appointment untouched.
        self::assertSame('confirmed', $this->rawScalar('SELECT status FROM ' . $this->rawTable('cpms_appointments') . ' WHERE id = ' . $this->appointmentId));
        self::assertSame('1', $this->rawScalar('SELECT cancelled_at IS NULL FROM ' . $this->rawTable('cpms_appointments') . ' WHERE id = ' . $this->appointmentId));

        // Capacity untouched: release_booking never ran.
        self::assertSame('1', $this->rawScalar('SELECT booked_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $this->slotId));

        // No cancellation was audited.
        self::assertSame('0', $this->rawScalar(
            'SELECT COUNT(*) FROM ' . $this->rawTable('cpms_audit_logs') . " WHERE action = 'APPOINTMENT_CANCELLED'"
        ));
    }

    public function testCancelZeroRowGuardRetryWithoutFaultSucceeds(): void
    {
        $stack = $this->stackOn($this->fixtureWpdb, false);
        $result = $this->transactionalCommit(
            $this->fixtureWpdb,
            fn (): array => $stack['service']->cancelByPatient($this->userAId, $this->appointmentId, 'لغو تستی')
        );
        self::assertSame('cancelled_by_patient', $result['status'] ?? '');

        self::assertSame('cancelled_by_patient', $this->rawScalar('SELECT status FROM ' . $this->rawTable('cpms_appointments') . ' WHERE id = ' . $this->appointmentId));
        self::assertSame('0', $this->rawScalar('SELECT booked_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $this->slotId));
        self::assertSame('1', $this->rawScalar(
            'SELECT COUNT(*) FROM ' . $this->rawTable('cpms_audit_logs') . " WHERE action = 'APPOINTMENT_CANCELLED'"
        ));
    }
}
