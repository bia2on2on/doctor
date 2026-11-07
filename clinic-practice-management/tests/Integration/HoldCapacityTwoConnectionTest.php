<?php

/**
 * Two-connection capacity tests against InnoDB — genuinely overlapping
 * transactions on an ISOLATED, DISPOSABLE database (CI-only).
 *
 * Review-driven redesign (2026-11-07). The previous revision of this file
 * (a) ran against the shared WP test database, (b) committed fixtures into
 * it, (c) deleted jobs/logs/holds by a high-water mark (blast radius: any
 * rows written concurrently, including other tests' rows), and (d) sequenced
 * all phases inside one process, which cannot demonstrate lock waiting. This
 * revision fixes all four:
 *
 *  - Isolation: every test creates a unique schema `cpms_t2c_<hex>` (CREATE
 *    DATABASE), runs the real plugin migrations inside it, points two
 *    independent connections at it, and DROPs the schema in tearDown's
 *    finally. Unrelated data (the shared WP test DB, other tests) can never
 *    be read or modified here, and cleanup is total even on failure: the
 *    whole schema is dropped — there is no scanning and no per-table delete.
 *    See DisposableSchema.php for the shared machinery.
 *  - Fixture visibility: fixture rows are INSERTed on a connection with
 *    autocommit, so they are durable and visible from the moment they exist.
 *    No fixture is ever written to the harness's global connection.
 *  - Transaction boundaries: production code opens transactions through
 *    CpmsDb, whose marked control statements the WP test bootstrap rewrites
 *    to SAVEPOINTs nested in the framework's outer transaction. The
 *    "confirm must be denied" assertion runs in-process inside a real outer
 *    transaction (transactionalCommit rolls it back on the expected throw);
 *    the mutating workers run production code on a bare connection whose
 *    implicit transaction the worker COMMITs before exiting — the same
 *    top-level commit semantics production has. Verified against MySQL 5.7
 *    semantics: SAVEPOINT on an idle connection is accepted; real DDL
 *    implicitly commits and destroys prior savepoints (which is why
 *    migrations run before any savepoint exists).
 *  - Deterministic overlap, no sleep-race (pcntl backend): a pcntl_fork()ed
 *    worker signals readiness through a file barrier; the test then proves
 *    from InnoDB's own lock tables (performance_schema on MySQL,
 *    information_schema innodb_lock_waits on MariaDB) that the worker is in
 *    LOCK WAIT behind the coordinator's connection on the expected table
 *    before releasing it. No sleep picks the winner.
 *  - Same-file fallback (no pcntl backend): runtimes without pcntl_fork()
 *    (e.g. php-wasm) execute a deterministic fallback instead: an
 *    independent connection is parked FOR UPDATE on the target row by a
 *    coordinator, an independent production worker with
 *    innodb_lock_wait_timeout=1 then blocks and is rejected with the
 *    production code for that case (CLINIC_BOOKING_BUSY), the coordinator
 *    rolls back, a fresh production worker succeeds, and a third is rejected
 *    with CLINIC_SLOT_TAKEN. It cannot observe the lock-wait edge itself and
 *    cannot construct a true simultaneous-entry race; those properties
 *    belong to the pcntl backend. Final-state assertions are identical.
 *  - Failure injection without fabricated errors: a test-only wpdb subclass
 *    rewrites the target table to a missing one, so the REAL driver reports
 *    a real failure; counters assert exactly-once fault delivery. There are
 *    no retries to mask deadlocks or errors anywhere in this file.
 *
 * SCOPE — what this file does and does NOT show:
 *  - It exercises production code paths (BookingService::hold/confirm,
 *    HoldsExpireHandler) with genuinely overlapping connections.
 *  - It does NOT prove deadlock-freedom, fairness, crash recovery, or
 *    anything about deployments outside a single test process.
 *  - The forked workers cannot read the parent's uncommitted WP session
 *    rows. Each worker builds its stack with a PatientSessionScope seeded
 *    from the session arrays captured in the parent before forking (the
 *    class's documented session_reader/clinics_reader seams; the empty
 *    live-link snapshot is consistent with the disposable schema having no
 *    global link rows), a pure always-allow ActiveLicenseGate (the global
 *    SignedLicenseGate singleton is bound to the parent connection and must
 *    not be touched from a fork), and its own SmsService/JobQueue bound to
 *    the disposable connection. In-process assertions use the production
 *    wiring (App::licenseGate()) instead.
 *  - Execution status is tracked in the commit/PR that lands this file.
 *    The no-pcntl fallback backend was executed on MariaDB 11.4.13 (see the
 *    run logs of the PR #330 continuation); the pcntl backend itself only
 *    runs where pcntl_fork() exists (CI). Do not claim the pcntl backend
 *    passed anywhere it did not run.
 *
 * PR #330 — two-connection capacity proof (red-first). All fixtures are
 * disposable; nothing here may run on a shared database.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\HoldsExpireHandler;
use ClinicCore\Domain\Booking\BookingException;
use WP_UnitTestCase;

require_once __DIR__ . '/DisposableSchema.php';
require_once __DIR__ . '/RealTableMigrations.php';

final class HoldCapacityTwoConnectionTest extends WP_UnitTestCase
{
    use RealTableMigrations;
    use DisposableSchema;

    private const READY_TIMEOUT_SEC = 20.0;
    private const LOCK_WAIT_TIMEOUT_SEC = 10.0;
    private const GO_DEADLINE_SEC = 20.0;

    protected function setUp(): void
    {
        parent::setUp();
        // pcntl_fork() decides the backend at test level (pcntl backend when
        // available, deterministic connection fallback otherwise).

        $this->createDisposableSchema('t2c');
        $this->migrateDisposableSchema();
        $this->seedWpUsers();
        $this->seedOrganizationGraph();
        $this->seedPatientsAndLinks();
        $this->seedSessions([$this->userAId, $this->userBId]);
    }

    protected function tearDown(): void
    {
        try {
            $this->dropDisposableSchema();
        } finally {
            parent::tearDown();
        }
    }

    // ================= scenario 1: competing holds, last capacity unit =================

    public function testCompetingHoldOnLastCapacityUnitIsRejectedFromIndependentConnection(): void
    {
        $date   = gmdate('Y-m-d', time() + 3 * 86400);
        $slotId = $this->insertSlot(1, 0, $date);

        if ( ! $this->pcntlAvailable() ) {
            $this->runScenarioOneConnectionFallback( $slotId, $date );
            return;
        }

        $tag       = sys_get_temp_dir() . '/' . $this->fileTag . '-race1';
        $readyA    = $tag . '-ready-a';
        $goA       = $tag . '-go-a';
        $holdDoneA = $tag . '-hold-done-a';
        $commitA   = $tag . '-commit-a';
        $outcomeA  = $tag . '-outcome-a.json';
        $readyB    = $tag . '-ready-b';
        $outcomeB  = $tag . '-outcome-b.json';

        // Competitor A (fork): takes the Slot row lock, waits for GO, runs the
        // production hold(), signals completion, waits for COMMIT, then commits
        // for real. Its connection keeps the lock until COMMIT.
        $pidA = pcntl_fork();
        if (0 === $pidA) {
            $this->childCompetitorHold($readyA, $goA, $holdDoneA, $commitA, $outcomeA, $slotId, $date, $this->userAId, $this->capturedSessions[$this->userAId]);
        }
        self::assertNotFalse($pidA);
        self::assertGreaterThan(0, $pidA, 'competitor A fork');
        self::assertTrue($this->waitForFile($readyA, self::READY_TIMEOUT_SEC), 'competitor A never signalled readiness');
        $coordinatorConnection = $this->readConnectionIdFromFile($readyA);
        self::assertGreaterThan(0, $coordinatorConnection);

        // Competitor B (fork): runs the same production hold() on its own
        // connection; it must block behind A.
        $pidB = pcntl_fork();
        if (0 === $pidB) {
            $this->childCompetingHoldLoser($readyB, $outcomeB, $slotId, $date, $this->userBId, $this->capturedSessions[$this->userBId]);
        }
        self::assertNotFalse($pidB);
        self::assertGreaterThan(0, $pidB, 'competitor B fork');
        self::assertTrue($this->waitForFile($readyB, self::READY_TIMEOUT_SEC), 'competitor B never signalled readiness');
        $workerConnection = $this->readConnectionIdFromFile($readyB);
        self::assertGreaterThan(0, $workerConnection);

        // Deterministic overlap proof: B is in InnoDB LOCK WAIT on the Slot row
        // behind A's connection BEFORE A proceeds.
        $edge = $this->waitForLockWait($workerConnection, $coordinatorConnection, 'cpms_schedule_slots', self::LOCK_WAIT_TIMEOUT_SEC);
        self::assertNotNull($edge, 'competitor B must be observed in InnoDB LOCK WAIT on the Slot row');
        self::assertStringContainsString('cpms_schedule_slots', strtolower((string) ($edge['lock_table'] ?? '')));

        // A completes its hold while B is blocked.
        file_put_contents($goA, 'go');
        self::assertTrue($this->waitForFile($holdDoneA, self::GO_DEADLINE_SEC), 'competitor A did not complete its hold');

        // B must STILL be blocked: A's connection holds the row until COMMIT.
        $stillWaiting = $this->waitForLockWait($workerConnection, $coordinatorConnection, 'cpms_schedule_slots', 1.0);
        self::assertNotNull($stillWaiting, 'competitor B must remain blocked until A commits');

        // Release A; B unblocks, sees the capacity consumed, and must lose.
        file_put_contents($commitA, 'go');
        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $outcomeBJson = json_decode((string) @file_get_contents($outcomeB), true);
        self::assertIsArray($outcomeBJson, 'B must write its outcome');
        self::assertSame('refused', $outcomeBJson['result'] ?? '');
        self::assertSame('CLINIC_SLOT_TAKEN', $outcomeBJson['code'] ?? '');

        // Fresh raw reads: exactly one unit of capacity consumed, exactly one hold.
        self::assertSame('1', $this->rawScalar('SELECT held_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('1', $this->rawScalar("SELECT COUNT(*) FROM " . $this->rawTable('cpms_slot_holds') . " WHERE slot_id = {$slotId} AND status = 'active'"));
        self::assertSame('0', $this->rawScalar('SELECT COUNT(*) FROM ' . $this->rawTable('cpms_appointments') . ' WHERE slot_id = ' . $slotId));

        $this->cleanupRaceFiles([$readyA, $goA, $holdDoneA, $commitA, $outcomeA, $readyB, $outcomeB]);
    }

    // ================= scenario 2: expiry worker vs confirm =================

    public function testExpiryWorkerOnIndependentConnectionThenConfirmIsDeniedWithoutDoubleRelease(): void
    {
        $date   = gmdate('Y-m-d', time() + 3 * 86400);
        $slotId = $this->insertSlot(2, 1, $date);
        $holdId = $this->insertHold($slotId, $this->userAId, gmdate('Y-m-d H:i:s', time() - 3600) . '.000', 'active');

        if ( ! $this->pcntlAvailable() ) {
            $this->runScenarioTwoConnectionFallback( $slotId, $holdId );
            return;
        }

        $tag       = sys_get_temp_dir() . '/' . $this->fileTag . '-race2';
        $readyA    = $tag . '-ready-a';
        $goA       = $tag . '-go-a';
        $readyB    = $tag . '-ready-b';
        $outcomeB  = $tag . '-outcome-b.json';

        // Coordinator A (fork): holds the Hold row's record lock exactly where
        // confirm() takes it (HOLD before SLOT), signals readiness, waits for GO.
        $pidA = pcntl_fork();
        if (0 === $pidA) {
            $this->childHoldLockHolder($readyA, $goA, $holdId);
        }
        self::assertNotFalse($pidA);
        self::assertGreaterThan(0, $pidA);
        self::assertTrue($this->waitForFile($readyA, self::READY_TIMEOUT_SEC), 'coordinator A never signalled readiness');
        $coordinatorConnection = $this->readConnectionIdFromFile($readyA);
        self::assertGreaterThan(0, $coordinatorConnection);

        // Worker B (fork): the production HoldsExpireHandler on its own
        // connection; it must block on the Hold row until A releases it.
        $pidB = pcntl_fork();
        if (0 === $pidB) {
            $this->childExpiryWorker($readyB, $outcomeB);
        }
        self::assertNotFalse($pidB);
        self::assertGreaterThan(0, $pidB);
        self::assertTrue($this->waitForFile($readyB, self::READY_TIMEOUT_SEC), 'expiry worker never signalled readiness');
        $workerConnection = $this->readConnectionIdFromFile($readyB);

        $edge = $this->waitForLockWait($workerConnection, $coordinatorConnection, 'cpms_slot_holds', self::LOCK_WAIT_TIMEOUT_SEC);
        self::assertNotNull($edge, 'the expiry worker must be observed in InnoDB LOCK WAIT on the Hold row');
        self::assertStringContainsString('cpms_slot_holds', strtolower((string) ($edge['lock_table'] ?? '')));

        // Release A's hold lock; B expires the hold and frees capacity for real.
        file_put_contents($goA, 'go');
        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);
        $outcomeBJson = json_decode((string) @file_get_contents($outcomeB), true);
        self::assertIsArray($outcomeBJson, 'worker B must write its outcome');
        self::assertSame('ok', $outcomeBJson['result'] ?? '');
        self::assertGreaterThanOrEqual(1, (int) ($outcomeBJson['freed'] ?? 0));

        // Confirm must now be denied in the gate WITHOUT releasing capacity again.
        $confirmError = null;
        try {
            $this->transactionalCommit($this->fixtureWpdb, function () use ($holdId): void {
                $stack = $this->stackOn($this->fixtureWpdb, false);
                $stack['service']->confirm($this->holdToken($holdId, $this->fixtureWpdb), $this->userAId, null, $this->uuid());
            });
        } catch (BookingException $e) {
            $confirmError = $e;
        }
        self::assertNotNull($confirmError, 'confirm must be denied after the worker expired the hold');
        self::assertSame('CLINIC_HOLD_EXPIRED', $confirmError->errorCode);
        self::assertSame(422, $confirmError->httpStatus);

        // Capacity released exactly once (by the worker), never by confirm.
        self::assertSame('expired', $this->rawScalar('SELECT status FROM ' . $this->rawTable('cpms_slot_holds') . ' WHERE id = ' . $holdId));
        self::assertSame('0', $this->rawScalar('SELECT held_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT booked_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT COUNT(*) FROM ' . $this->rawTable('cpms_appointments') . ' WHERE slot_id = ' . $slotId));
        $compensationFailures = (int) $this->rawScalar(
            'SELECT COUNT(*) FROM ' . $this->rawTable('cpms_operational_logs')
            . " WHERE message IN ('booking.hold_release_compensation_failed', 'booking.hold_capacity_release_failed')"
        );
        self::assertSame(0, $compensationFailures, 'the gate denial must not release capacity or log a release failure');

        $this->cleanupRaceFiles([$readyA, $goA, $readyB, $outcomeB]);
    }

    // ================= scenario 3a: injected conversion failure =================

    public function testConversionFailureRollsBackBookingAndCompensatesHoldExactlyOnce(): void
    {
        $date   = gmdate('Y-m-d', time() + 3 * 86400);
        $slotId = $this->insertSlot(2, 1, $date);
        $holdId = $this->insertHold($slotId, $this->userAId, gmdate('Y-m-d H:i:s', time() + 3600) . '.000', 'active');

        // Failure injection: rewrite ONLY the conversion UPDATE's table to a
        // missing one. The real driver then reports the real failure; no error
        // message is fabricated anywhere.
        $conversionFault = static function (string $sql): ?string {
            if (false !== stripos($sql, 'cpms_slot_holds') && false !== stripos($sql, "status = 'converted'")) {
                return str_ireplace('cpms_slot_holds', 'cpms_slot_holds__missing_two_connection', $sql);
            }
            return null;
        };

        $decoded = $this->confirmWithFaultInProcess($conversionFault, $holdId, $this->userAId);
        self::assertSame('error', $decoded['result'] ?? '');
        self::assertSame('CLINIC_INTERNAL_ERROR', $decoded['code'] ?? '');
        self::assertSame(1, (int) ($decoded['fault_fired'] ?? -1), 'the injected conversion fault fired exactly once');

        // Booking rolled back; compensation released exactly once (hold -> released, counter 0).
        self::assertSame('released', $this->rawScalar('SELECT status FROM ' . $this->rawTable('cpms_slot_holds') . ' WHERE id = ' . $holdId));
        self::assertSame('0', $this->rawScalar('SELECT held_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT booked_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT COUNT(*) FROM ' . $this->rawTable('cpms_appointments') . ' WHERE slot_id = ' . $slotId));
        $compensationFailures = (int) $this->rawScalar(
            'SELECT COUNT(*) FROM ' . $this->rawTable('cpms_operational_logs')
            . " WHERE message IN ('booking.hold_release_compensation_failed', 'booking.hold_capacity_release_failed')"
        );
        self::assertSame(0, $compensationFailures, 'a successful compensation must not log a release failure');

    }

    // ============ scenario 3b: injected compensation-release failure + retry ============

    public function testCompensationReleaseFailureRollsBackAndHoldStaysUsableForRetry(): void
    {
        $date   = gmdate('Y-m-d', time() + 3 * 86400);
        $slotId = $this->insertSlot(2, 1, $date);
        $holdId = $this->insertHold($slotId, $this->userAId, gmdate('Y-m-d H:i:s', time() + 3600) . '.000', 'active');

        // The conversion fault fires first; additionally the compensation's
        // counter release (release_hold: GREATEST(held_count - 1, 0), no
        // booked_count reference) is rewritten to a missing table.
        $releaseFault = static function (string $sql): ?string {
            if (false !== stripos($sql, 'cpms_slot_holds') && false !== stripos($sql, "status = 'converted'")) {
                return str_ireplace('cpms_slot_holds', 'cpms_slot_holds__missing_two_connection', $sql);
            }
            if (
                false !== stripos($sql, 'cpms_schedule_slots')
                && false !== stripos($sql, 'GREATEST(held_count - 1, 0)')
                && false === stripos($sql, 'booked_count')
            ) {
                return str_ireplace('cpms_schedule_slots', 'cpms_schedule_slots__missing_two_connection', $sql);
            }
            return null;
        };

        $decoded = $this->confirmWithFaultInProcess($releaseFault, $holdId, $this->userAId);
        self::assertSame('error', $decoded['result'] ?? '');
        self::assertSame('CLINIC_INTERNAL_ERROR', $decoded['code'] ?? '');

        // Nothing consumed, built, or released: the hold is untouched and active.
        self::assertSame('active', $this->rawScalar('SELECT status FROM ' . $this->rawTable('cpms_slot_holds') . ' WHERE id = ' . $holdId));
        self::assertSame('1', $this->rawScalar('SELECT held_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT booked_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT COUNT(*) FROM ' . $this->rawTable('cpms_appointments') . ' WHERE slot_id = ' . $slotId));

        // The compensation failure was logged with the slot_release stage.
        $releaseFailures = (int) $this->rawScalar(
            'SELECT COUNT(*) FROM ' . $this->rawTable('cpms_operational_logs')
            . " WHERE message = 'booking.hold_capacity_release_failed'"
        );
        self::assertSame(1, $releaseFailures, 'the compensation failure must be logged once with stage slot_release');

        // Retry on a fresh connection with faults disabled: must succeed.
        $retry = $this->confirmWithFaultInProcess(null, $holdId, $this->userAId);
        self::assertSame('ok', $retry['result'] ?? '');

        self::assertSame('converted', $this->rawScalar('SELECT status FROM ' . $this->rawTable('cpms_slot_holds') . ' WHERE id = ' . $holdId));
        self::assertSame('1', $this->rawScalar('SELECT COUNT(*) FROM ' . $this->rawTable('cpms_appointments') . ' WHERE slot_id = ' . $slotId));
        self::assertSame('0', $this->rawScalar('SELECT held_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));
        self::assertSame('1', $this->rawScalar('SELECT booked_count FROM ' . $this->rawTable('cpms_schedule_slots') . ' WHERE id = ' . $slotId));

    }

    // ================= no-pcntl backend + in-process fault helper =================

    private function pcntlAvailable(): bool
    {
        return function_exists('pcntl_fork');
    }

    /**
     * Deterministic single-process fallback for scenario 1 (no pcntl_fork).
     * It proves a weaker but honest property: an independent production
     * connection really WAITS for the Slot row's InnoDB lock held by the
     * coordinator (bounded by its own innodb_lock_wait_timeout=1) and is then
     * rejected with the production code for that case (CLINIC_BOOKING_BUSY);
     * after the coordinator releases the lock, a fresh production hold
     * succeeds and consumes the last unit; a third is rejected with
     * CLINIC_SLOT_TAKEN. It does NOT demonstrate two competitors entering
     * hold() simultaneously nor observe the lock-wait edge — those belong to
     * the pcntl backend. Final-state assertions are identical to it.
     */
    private function runScenarioOneConnectionFallback( int $slotId, string $date ): void
    {
        $coordinator = $this->newDisposableConnection();
        $coordinator->query( 'START TRANSACTION' );
        $locked = $coordinator->get_var(
            $coordinator->prepare( 'SELECT id FROM ' . $coordinator->prefix . 'cpms_schedule_slots WHERE id = %d FOR UPDATE', $slotId )
        );
        self::assertSame( $slotId, (int) $locked, 'coordinator must hold the Slot row lock' );

        // Production hold() on an independent connection behind the held lock.
        $worker = $this->newDisposableConnection();
        $worker->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
        $worker->query( 'START TRANSACTION' );
        $busyError = null;
        try {
            $stack = $this->stackOn( $worker, false );
            $stack['service']->hold( $this->userAId, $this->clinicianId, $date, '09:00:00', $slotId );
            $worker->query( 'ROLLBACK' );
        } catch ( BookingException $e ) {
            $busyError = $e;
            $worker->query( 'ROLLBACK' );
        }
        self::assertNotNull( $busyError, 'the blocked production hold must be rejected while the Slot row is locked' );
        self::assertSame( 'CLINIC_BOOKING_BUSY', $busyError->errorCode );
        self::assertSame( 503, $busyError->httpStatus );
        $worker->close();

        $coordinator->query( 'ROLLBACK' );
        $coordinator->close();

        // A fresh production hold now succeeds and consumes the last unit.
        $winner = $this->newDisposableConnection();
        $winner->query( 'START TRANSACTION' );
        $stack = $this->stackOn( $winner, false );
        $winnerResult = $stack['service']->hold( $this->userAId, $this->clinicianId, $date, '09:00:00', $slotId );
        $winner->query( 'COMMIT' );
        self::assertNotSame( '', (string) ( $winnerResult['hold_token'] ?? '' ) );
        $winner->close();

        // A third connection must now be rejected: capacity is consumed.
        $loser = $this->newDisposableConnection();
        $loser->query( 'START TRANSACTION' );
        $loserError = null;
        try {
            $stack = $this->stackOn( $loser, false );
            $stack['service']->hold( $this->userBId, $this->clinicianId, $date, '09:00:00', $slotId );
            $loser->query( 'COMMIT' );
        } catch ( BookingException $e ) {
            $loserError = $e;
            $loser->query( 'ROLLBACK' );
        }
        self::assertNotNull( $loserError, 'capacity is exhausted; the third hold must be rejected' );
        self::assertSame( 'CLINIC_SLOT_TAKEN', $loserError->errorCode );
        self::assertSame( 409, $loserError->httpStatus );
        $loser->close();

        self::assertSame( '1', $this->rawScalar( 'SELECT held_count FROM ' . $this->rawTable( 'cpms_schedule_slots' ) . ' WHERE id = ' . $slotId ) );
        self::assertSame( '1', $this->rawScalar( "SELECT COUNT(*) FROM " . $this->rawTable( 'cpms_slot_holds' ) . " WHERE slot_id = {$slotId} AND status = 'active'" ) );
        self::assertSame( '0', $this->rawScalar( 'SELECT COUNT(*) FROM ' . $this->rawTable( 'cpms_appointments' ) . ' WHERE slot_id = ' . $slotId ) );
    }

    /**
     * Deterministic single-process fallback for scenario 2 (no pcntl_fork).
     * The coordinator holds the Hold row's X lock; the production
     * HoldsExpireHandler on an independent connection with
     * innodb_lock_wait_timeout=1 blocks on its first write, fails, and rolls
     * back — the hold stays active and capacity stays claimed. After the
     * coordinator releases, the handler expires the hold and frees capacity;
     * confirm is then denied with CLINIC_HOLD_EXPIRED (422) and capacity is
     * never released twice.
     */
    private function runScenarioTwoConnectionFallback( int $slotId, int $holdId ): void
    {
        $coordinator = $this->newDisposableConnection();
        $coordinator->query( 'START TRANSACTION' );
        $locked = $coordinator->get_var(
            $coordinator->prepare( 'SELECT id FROM ' . $coordinator->prefix . 'cpms_slot_holds WHERE id = %d FOR UPDATE', $holdId )
        );
        self::assertSame( $holdId, (int) $locked, 'coordinator must hold the Hold row lock' );

        $worker = $this->newDisposableConnection();
        $worker->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
        $worker->query( 'START TRANSACTION' );
        $expireError = null;
        try {
            $stack = $this->stackOn( $worker, false );
            $handler = new HoldsExpireHandler( $stack['db'] );
            $handler->__invoke( [] );
            $worker->query( 'COMMIT' );
        } catch ( \RuntimeException $e ) {
            $expireError = $e;
            $worker->query( 'ROLLBACK' );
        }
        self::assertNotNull( $expireError, 'the expiry worker must fail while the Hold row is locked' );
        self::assertSame( 'active', $this->rawScalar( 'SELECT status FROM ' . $this->rawTable( 'cpms_slot_holds' ) . ' WHERE id = ' . $holdId ) );
        self::assertSame( '1', $this->rawScalar( 'SELECT held_count FROM ' . $this->rawTable( 'cpms_schedule_slots' ) . ' WHERE id = ' . $slotId ) );
        $worker->close();

        $coordinator->query( 'ROLLBACK' );
        $coordinator->close();

        // Now the handler runs clean: it expires the hold and frees capacity.
        $cleanup = $this->newDisposableConnection();
        $cleanup->query( 'START TRANSACTION' );
        $stack = $this->stackOn( $cleanup, false );
        $handler = new HoldsExpireHandler( $stack['db'] );
        $freed = $handler->__invoke( [] );
        $cleanup->query( 'COMMIT' );
        self::assertGreaterThanOrEqual( 1, $freed );
        $cleanup->close();

        // Confirm must now be denied in the gate WITHOUT releasing capacity again.
        $confirmError = null;
        try {
            $this->transactionalCommit( $this->fixtureWpdb, function () use ( $holdId ): void {
                $stack = $this->stackOn( $this->fixtureWpdb, false );
                $stack['service']->confirm( $this->holdToken( $holdId, $this->fixtureWpdb ), $this->userAId, null, $this->uuid() );
            } );
        } catch ( BookingException $e ) {
            $confirmError = $e;
        }
        self::assertNotNull( $confirmError, 'confirm must be denied after the worker expired the hold' );
        self::assertSame( 'CLINIC_HOLD_EXPIRED', $confirmError->errorCode );
        self::assertSame( 422, $confirmError->httpStatus );

        // Capacity released exactly once (by the handler), never by confirm.
        self::assertSame( 'expired', $this->rawScalar( 'SELECT status FROM ' . $this->rawTable( 'cpms_slot_holds' ) . ' WHERE id = ' . $holdId ) );
        self::assertSame( '0', $this->rawScalar( 'SELECT held_count FROM ' . $this->rawTable( 'cpms_schedule_slots' ) . ' WHERE id = ' . $slotId ) );
        self::assertSame( '0', $this->rawScalar( 'SELECT booked_count FROM ' . $this->rawTable( 'cpms_schedule_slots' ) . ' WHERE id = ' . $slotId ) );
        self::assertSame( '0', $this->rawScalar( 'SELECT COUNT(*) FROM ' . $this->rawTable( 'cpms_appointments' ) . ' WHERE slot_id = ' . $slotId ) );
        $compensationFailures = (int) $this->rawScalar(
            'SELECT COUNT(*) FROM ' . $this->rawTable( 'cpms_operational_logs' )
            . " WHERE message IN ('booking.hold_release_compensation_failed', 'booking.hold_capacity_release_failed')"
        );
        self::assertSame( 0, $compensationFailures, 'the gate denial must not release capacity or log a release failure' );
    }

    /**
     * In-process twin of the former forked confirm worker: drives the
     * production confirm() with (or without) a fault injected at the SQL
     * layer. After production rolls its nested transactions back to
     * savepoints, COMMIT only ends the outer transaction — the same
     * top-level durability the fork committed. Returns the result envelope
     * used by the assertions.
     *
     * @return array<string, mixed>
     */
    private function confirmWithFaultInProcess( ?callable $fault, int $holdId, int $userId ): array
    {
        $conn = null;
        try {
            if ( null !== $fault ) {
                $conn = new FaultWpdb( DB_USER, DB_PASSWORD, $this->disposableDb, DB_HOST );
                $conn->set_prefix( $this->fixtureWpdb->prefix );
                $conn->show_errors( false );
                $conn->faultCallback = $fault;
            } else {
                $conn = $this->newDisposableConnection();
            }
            $token = $this->holdToken( $holdId, $conn );
            $stack = $this->stackOn( $conn, false );
            $conn->query( 'START TRANSACTION' );
            try {
                $stack['service']->confirm( $token, $userId, null, $this->uuid() );
            } catch ( \Throwable $e ) {
                $conn->query( 'COMMIT' );
                return [
                    'result' => 'error',
                    'code' => $e instanceof BookingException ? $e->errorCode : get_class( $e ),
                    'fault_fired' => isset( $conn->faultFired ) ? $conn->faultFired : null,
                ];
            }
            $conn->query( 'COMMIT' );
            return [ 'result' => 'ok' ];
        } catch ( \Throwable $e ) {
            try {
                if ( null !== $conn ) {
                    $conn->query( 'ROLLBACK' );
                }
            } catch ( \Throwable $ignored ) {
                unset( $ignored );
            }
            return [ 'result' => 'fatal', 'code' => get_class( $e ) ];
        }
    }

    // ================= forked workers =================

    /** Coordinator competitor: holds the Slot row lock, then confirms a hold for user A. */
    private function childCompetitorHold(string $readyFile, string $goFile, string $holdDoneFile, string $commitFile, string $outcomeFile, int $slotId, string $date, int $userId, array $session): void
    {
        $conn = null;
        try {
            $conn = $this->newDisposableConnection();
            $connectionId = (int) $conn->get_var('SELECT CONNECTION_ID()');
            $conn->query('START TRANSACTION');
            $locked = $conn->get_var($conn->prepare('SELECT id FROM ' . $conn->prefix . 'cpms_schedule_slots WHERE id = %d FOR UPDATE', $slotId));
            if ((int) $locked !== $slotId) {
                throw new \RuntimeException('coordinator could not lock the Slot row');
            }
            file_put_contents($readyFile, (string) $connectionId);

            $this->waitForGo($goFile);

            $stack = $this->stackOn($conn, true, $userId, $session);
            $stack['service']->hold($userId, $this->clinicianId, $date, '09:00:00', $slotId);
            file_put_contents($holdDoneFile, 'done');

            $this->waitForGo($commitFile);
            $conn->query('COMMIT');
            file_put_contents($outcomeFile, (string) json_encode(['result' => 'ok']));
        } catch (\Throwable $e) {
            try {
                if (null !== $conn) {
                    $conn->query('ROLLBACK');
                }
            } catch (\Throwable $ignored) {
                // connection already gone
            }
            file_put_contents($outcomeFile, (string) json_encode(['result' => 'error', 'code' => $e instanceof BookingException ? $e->errorCode : get_class($e)]));
        } finally {
            $this->childExit();
        }
    }

    /** Losing competitor: attempts the same production hold; expected to be rejected. */
    private function childCompetingHoldLoser(string $readyFile, string $outcomeFile, int $slotId, string $date, int $userId, array $session): void
    {
        $conn = null;
        try {
            $conn = $this->newDisposableConnection();
            file_put_contents($readyFile, (string) (int) $conn->get_var('SELECT CONNECTION_ID()'));

            $stack = $this->stackOn($conn, true, $userId, $session);
            $conn->query('START TRANSACTION');
            try {
                $stack['service']->hold($userId, $this->clinicianId, $date, '09:00:00', $slotId);
                $conn->query('COMMIT');
                file_put_contents($outcomeFile, (string) json_encode(['result' => 'ok']));
            } catch (BookingException $e) {
                $conn->query('ROLLBACK');
                file_put_contents($outcomeFile, (string) json_encode(['result' => 'refused', 'code' => $e->errorCode]));
            }
        } catch (\Throwable $e) {
            try {
                if (null !== $conn) {
                    $conn->query('ROLLBACK');
                }
            } catch (\Throwable $ignored) {
                // connection already gone
            }
            file_put_contents($outcomeFile, (string) json_encode(['result' => 'error', 'code' => get_class($e)]));
        } finally {
            $this->childExit();
        }
    }

    /** Coordinator for scenario 2: holds the Hold-row record lock where confirm() takes it. */
    private function childHoldLockHolder(string $readyFile, string $goFile, int $holdId): void
    {
        $conn = null;
        try {
            $conn = $this->newDisposableConnection();
            $connectionId = (int) $conn->get_var('SELECT CONNECTION_ID()');
            $conn->query('START TRANSACTION');
            $locked = $conn->get_var($conn->prepare('SELECT id FROM ' . $conn->prefix . 'cpms_slot_holds WHERE id = %d FOR UPDATE', $holdId));
            if ((int) $locked !== $holdId) {
                throw new \RuntimeException('coordinator could not lock the Hold row');
            }
            file_put_contents($readyFile, (string) $connectionId);
            $this->waitForGo($goFile);
            $conn->query('COMMIT');
        } catch (\Throwable $e) {
            try {
                if (null !== $conn) {
                    $conn->query('ROLLBACK');
                }
            } catch (\Throwable $ignored) {
                // connection already gone
            }
        } finally {
            $this->childExit();
        }
    }

    /** Expiry worker: the production HoldsExpireHandler on its own connection. */
    private function childExpiryWorker(string $readyFile, string $outcomeFile): void
    {
        $conn = null;
        try {
            $conn = $this->newDisposableConnection();
            $connectionId = (int) $conn->get_var('SELECT CONNECTION_ID()');
            $conn->query('START TRANSACTION');
            $db = new \ClinicCore\Infrastructure\Db\CpmsDb($conn);
            file_put_contents($readyFile, (string) $connectionId);
            $freed = (new HoldsExpireHandler($db))([]);
            $conn->query('COMMIT');
            file_put_contents($outcomeFile, (string) json_encode(['result' => 'ok', 'freed' => (int) $freed]));
        } catch (\Throwable $e) {
            try {
                if (null !== $conn) {
                    $conn->query('ROLLBACK');
                }
            } catch (\Throwable $ignored) {
                // connection already gone
            }
            file_put_contents($outcomeFile, (string) json_encode(['result' => 'error', 'code' => get_class($e)]));
        } finally {
            $this->childExit();
        }
    }

    /** Confirm with optional fault injection on its own connection (failure scenarios). */
    private function childExit(): void
    {
        // Forked children must not run PHP shutdown: destructors could close
        // the inherited sockets of the parent's WP sessions.
        if (function_exists('posix_kill')) {
            posix_kill(getmypid(), SIGKILL);
        }
        exit(0);
    }

    /** InnoDB lock-wait edge with a known worker connection id. */
    private function waitForLockWait(int $workerConnection, int $blockingThread, string $table, float $timeout): ?array
    {
        if ($workerConnection <= 0) {
            return null;
        }
        $mysqli = $this->freshMysqli();
        try {
            $isMariaDb = false !== stripos($this->versionOf($mysqli), 'mariadb');
            if ($isMariaDb) {
                $sql = 'SELECT requesting_trx.trx_mysql_thread_id AS requesting_thread, blocking_trx.trx_mysql_thread_id AS blocking_thread,
                               requested_lock.lock_table AS lock_table, requested_lock.lock_index AS lock_index,
                               requested_lock.lock_type AS lock_type, requested_lock.lock_mode AS lock_mode
                        FROM information_schema.innodb_lock_waits waits
                        JOIN information_schema.innodb_trx requesting_trx ON requesting_trx.trx_id = waits.requesting_trx_id
                        JOIN information_schema.innodb_trx blocking_trx ON blocking_trx.trx_id = waits.blocking_trx_id
                        JOIN information_schema.innodb_locks requested_lock ON requested_lock.lock_id = waits.requested_lock_id
                       WHERE requesting_trx.trx_mysql_thread_id = ?';
            } else {
                $sql = 'SELECT requesting_thread.PROCESSLIST_ID AS requesting_thread, blocking_thread.PROCESSLIST_ID AS blocking_thread,
                               requested_lock.OBJECT_NAME AS lock_table, requested_lock.INDEX_NAME AS lock_index,
                               requested_lock.LOCK_TYPE AS lock_type, requested_lock.LOCK_MODE AS lock_mode
                        FROM performance_schema.data_lock_waits waits
                        JOIN performance_schema.data_locks requested_lock
                          ON requested_lock.ENGINE = waits.ENGINE AND requested_lock.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
                        JOIN performance_schema.data_locks blocking_lock
                          ON blocking_lock.ENGINE = waits.ENGINE AND blocking_lock.ENGINE_LOCK_ID = waits.BLOCKING_ENGINE_LOCK_ID
                        JOIN performance_schema.threads requesting_thread ON requesting_thread.THREAD_ID = requested_lock.THREAD_ID
                        JOIN performance_schema.threads blocking_thread ON blocking_thread.THREAD_ID = blocking_lock.THREAD_ID
                       WHERE requesting_thread.PROCESSLIST_ID = ?';
            }
            $deadline = microtime(true) + $timeout;
            do {
                $stmt = $mysqli->prepare($sql);
                if (false === $stmt) {
                    return null;
                }
                $stmt->bind_param('i', $workerConnection);
                $stmt->execute();
                $result = $stmt->get_result();
                $found = null;
                while ($result && $row = $result->fetch_assoc()) {
                    if (
                        (int) ($row['blocking_thread'] ?? 0) === $blockingThread
                        && str_contains(strtolower((string) ($row['lock_table'] ?? '')), strtolower($table))
                    ) {
                        $found = $row;
                        break;
                    }
                }
                if ($result) {
                    $result->free();
                }
                $stmt->close();
                if (null !== $found) {
                    return $found;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            return null;
        } finally {
            $mysqli->close();
        }
    }
}
