<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Booking\ScheduleService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\LocationRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\ScheduleRepository;
use WP_UnitTestCase;

/**
 * Phase 6 Slice 7 — «concurrence safety of multi-shift conflict enforcement».
 *
 * DEFECT UNDER TEST (pre-fix, service-level check-then-act WITHOUT any
 * transaction/lock on the mutation path):
 *
 *   request A: read "no overlapping shift for (Clinic, Location, clinician, weekday)"
 *   request B: read "no overlapping shift for the same cell"
 *   A inserts 08:00-12:00
 *   B inserts 10:00-14:00
 *   => two ACTIVE overlapping cpms_schedule rows (the unique key u_sched_slot
 *      only covers identical start_time, NOT overlapping windows).
 *
 * CONTRACT UNDER TEST (the commercial contract must hold regardless of
 * request ORDERING and must be enforced in the DB layer, not the UI):
 *
 *   A. create vs create (overlapping windows): exactly ONE winner; the losers
 *      receive the stable `overlapping_shift` / `duplicate_schedule_day`
 *      product failures; the cell ends with EXACTLY ONE of the conflicting
 *      active rows.
 *   B. create vs create (exact duplicate): exactly ONE row; losers get the
 *      stable `duplicate_schedule_day`; no raw DB uniqueness error, no ghost
 *      row id.
 *   C. create vs update (an existing row is moved INTO the overlap while a
 *      conflicting create fires): the cell NEVER ends with two active
 *      overlapping rows; exactly one of the conflicting mutations wins.
 *   D. update vs update (two rows moved into overlapping ranges): the cell
 *      NEVER ends with two active overlapping rows.
 *   E. POSITIVE CONTROL: two genuinely concurrent NON-overlapping creates
 *      both succeed (the fix must not over-serialize distinct windows).
 *
 * HOW CONCURRENCY IS PRODUCED (real, not simulated):
 *   - pcntl_fork: each contender is a REAL independent process with its own
 *     fresh wpdb connection (the established ConcurrencyTest /
 *     SlotCapacityOneHundredWayTest / VisitConcurrencyTest pattern).
 *   - Each child runs the REAL production entry point (ScheduleService::
 *     create / ScheduleService::update) with the same dependency wiring as
 *     App::scheduleService(), on its own connection — not a raw SQL mirror.
 *   - Synchronization barrier: all children open their connection, then block
 *     until a shared absolute time (same host clock, microsecond-scale
 *     alignment) before firing the product call — a deterministic race
 *     start, not "hope they overlap".
 *   - Children write their outcome (ok / stable error / fatal) to a temp
 *     file and exit; the parent aggregates outcomes and then reads the
 *     FINAL committed DB state with a FRESH connection (the WP Test Suite
 *     parent connection holds a stale REPEATABLE-READ snapshot).
 *
 * CHILD TRANSACTION BOUNDARY (critical):
 *   The test bootstrap rewrites the cpms-marked transaction verbs to
 *   SAVEPOINT/RELEASE (in-process, for the WP Test Suite's outer
 *   transaction). In a forked child there is NO outer transaction, so the
 *   child opens a REAL (unmarked) `START TRANSACTION` before the product
 *   call and a real `COMMIT` after it — mirroring production, where the
 *   service's own COMMIT is real. Without that explicit boundary the child's
 *   connection would close with an open transaction and ROLL BACK the
 *   winner's row.
 *
 * FIXTURE RULES (same discipline as the Phase 6 suites):
 *   - dynamic Organization/Clinic/Location (ids > 1, never the seeded
 *     legacy Clinic 1 — no first-row tenant, no hardcoded ids);
 *   - one WP user <-> exactly one cpms_clinicians row;
 *   - durable membership through the production primitive
 *     (cpms_test_seed_membership);
 *   - the trusted Clinic arrives via ScopeContext::set (the server-side
 *     explicit scope the service consumes) — never as payload.
 *   - Fixtures are COMMITTED (independent connections must see them);
 *     cleanup is MANUAL (tearDown ROLLBACK is a no-op after COMMIT).
 */
final class Phase6ScheduleShiftConcurrencyRedTest extends WP_UnitTestCase
{
    /** Location timezone — production-realistic (matches the Phase 6 suites). */
    private const TZ = 'Asia/Tehran';

    /** Barrier lead time before the product fire (seconds). */
    private const BARRIER_SEC = 2.0;

    /** Barrier-synchronized attempts for the binary (2-child) races. */
    private const BINARY_ATTEMPTS = 5;

    private int $orgId = 0;
    private int $clinicId = 0;
    private int $locationId = 0;
    private int $professionalUserId = 0;
    private int $clinicianId = 0;

    /** Per-run temp-file tag (crash-resistant outcome collection). */
    private string $fileTag = '';

    /** Committed-row cleanup window (children commit outside the test tx). */
    private string $sideEffectFloor = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available (CI Linux has it)');
        }
        App::migrations()->migrate();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $this->sideEffectFloor = gmdate('Y-m-d H:i:s', time() - 5) . '.000';
        $this->fileTag = 'p6schc-' . bin2hex(random_bytes(5));

        // ---------- Committed dynamic fixture (asserted: a failed insertion
        // must be readable as a FIXTURE failure, not a product RED) ----------
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'P6S7 Org ' . $unique,
            'p6s7-org-' . $unique,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'precondition: organization inserted (' . $wpdb->last_error . ')');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics
                 (organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->orgId,
            'P6S7 Clinic ' . $unique,
            'p6s7-clinic-' . $unique,
            self::TZ,
            $now,
            $now
        ));
        $this->clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicId, 'precondition: clinic id is dynamic (never the seeded legacy clinic 1)');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                 (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
             VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->clinicId,
            'P6S7 Loc ' . $unique,
            'p6s7-loc-' . $unique,
            self::TZ,
            $now,
            $now
        ));
        $this->locationId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->locationId, 'precondition: location inserted (' . $wpdb->last_error . ')');

        $this->professionalUserId = $this->makeUser('p6s7_prof');
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                 (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
             VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->clinicId,
            'Dr P6S7 Concurrency',
            $this->professionalUserId,
            $now,
            $now
        ));
        $this->clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicianId, 'precondition: clinician row (' . $wpdb->last_error . ')');

        self::assertGreaterThan(
            0,
            cpms_test_seed_membership($this->professionalUserId, $this->clinicId, RolesAndCapabilities::ROLE_DOCTOR),
            'precondition: durable ACTIVE membership in the fixture Clinic'
        );

        // Independent child connections must SEE the fixture.
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    protected function tearDown(): void
    {
        // Manual cleanup — everything above is COMMITTED (tearDown ROLLBACK
        // is a no-op for committed rows). Children + their side effects
        // (audit/op/job rows) are also committed -> windowed cleanup.
        $this->purgeSideEffectLogs();
        $this->purgeTopology();
        ScopeContext::clear();
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ==================================================================
    // CASE A — create vs create, overlapping windows (08:00-12:00 x
    //          10:00-14:00, 20 concurrent contenders per window).
    //          Exactly one active row may remain in the cell.
    // ==================================================================

    public function testConcurrentOverlappingCreatesLeaveExactlyOneActiveRow(): void
    {
        $workers = [];
        for ($i = 0; $i < 20; $i++) {
            $workers[] = ['kind' => 'create', 'day' => 1, 'start' => '08:00', 'end' => '12:00'];
        }
        for ($i = 0; $i < 20; $i++) {
            $workers[] = ['kind' => 'create', 'day' => 1, 'start' => '10:00', 'end' => '14:00'];
        }

        $outcomes = $this->runWorkers($workers);
        $this->assertNoFatalOutcomes($outcomes, 'A');
        $rows = $this->cellRows(1);

        $oks = array_values(array_filter($outcomes, static fn (array $o): bool => ($o['result'] ?? '') === 'ok'));
        self::assertCount(
            1,
            $oks,
            'A: of 40 concurrent overlapping creates exactly ONE may win. '
            . 'oks=' . json_encode($oks) . ' cell_rows=' . json_encode($rows)
        );
        self::assertCount(
            1,
            $rows,
            'A: the cell must contain exactly ONE of the two conflicting active rows. '
            . 'rows=' . json_encode($rows)
        );
        $this->assertNoActiveOverlap($rows, 'A');
        $this->assertLosersHaveStableConflictErrors(
            $outcomes,
            ['overlapping_shift', 'duplicate_schedule_day'],
            'A'
        );
    }

    // ==================================================================
    // CASE B — create vs create, EXACT duplicate window (20 concurrent
    //          contenders, same start_time). One row; stable
    //          duplicate_schedule_day for the losers; no ghost ids.
    // ==================================================================

    public function testConcurrentExactDuplicateCreatesKeepStableContract(): void
    {
        $workers = [];
        for ($i = 0; $i < 20; $i++) {
            $workers[] = ['kind' => 'create', 'day' => 2, 'start' => '09:00', 'end' => '12:00'];
        }

        $outcomes = $this->runWorkers($workers);
        $this->assertNoFatalOutcomes($outcomes, 'B');
        $rows = $this->cellRows(2);

        $oks = array_values(array_filter($outcomes, static fn (array $o): bool => ($o['result'] ?? '') === 'ok'));
        self::assertCount(
            1,
            $oks,
            'B: of 20 concurrent exact-duplicate creates exactly ONE may win (no ghost row ids). '
            . 'oks=' . json_encode($oks) . ' cell_rows=' . json_encode($rows)
        );
        foreach ($oks as $o) {
            self::assertGreaterThan(0, (int) ($o['id'] ?? 0), 'B: the winner must hold a real row id');
        }
        self::assertCount(1, $rows, 'B: exactly one row may persist. rows=' . json_encode($rows));
        $this->assertLosersHaveStableConflictErrors($outcomes, ['duplicate_schedule_day'], 'B');
    }

    // ==================================================================
    // CASE C — create vs update: committed row 08:00-09:00 is moved to
    //          10:00-14:00 while a concurrent create inserts 11:00-13:00
    //          (non-overlapping with the OLD window, overlapping with the
    //          NEW one). Neither order may end with an overlap; exactly
    //          one of the conflicting mutations wins.
    //          (5 barrier-synchronized attempts, fresh cell each time.)
    // ==================================================================

    public function testConcurrentCreateVsUpdateNeverLeavesOverlap(): void
    {
        $day = 3;
        for ($attempt = 1; $attempt <= self::BINARY_ATTEMPTS; $attempt++) {
            $rowId = $this->seedCellRow($day, '08:00:00', '09:00:00');

            $outcomes = $this->runWorkers([
                ['kind' => 'update', 'id' => $rowId, 'start' => '10:00', 'end' => '14:00'],
                ['kind' => 'create', 'day' => $day, 'start' => '11:00', 'end' => '13:00'],
            ]);
            $this->assertNoFatalOutcomes($outcomes, 'C attempt ' . $attempt);
            $rows = $this->cellRows($day);
            $this->assertNoActiveOverlap($rows, 'C attempt ' . $attempt);

            $moved     = $this->countStart($rows, '10:00:00');
            $original  = $this->countStart($rows, '08:00:00');
            $created   = $this->countStart($rows, '11:00:00');
            self::assertTrue(
                (($moved === 1) xor ($original === 1 && $created === 1)),
                'C attempt ' . $attempt . ': exactly one of the conflicting mutations may win. '
                . 'rows=' . json_encode($rows) . ' outcomes=' . json_encode($outcomes)
            );
            $this->assertLosersHaveStableConflictErrors($outcomes, ['overlapping_shift'], 'C attempt ' . $attempt);

            $this->resetCell($day);
        }
    }

    // ==================================================================
    // CASE D — update vs update: committed rows 08:00-08:30 and
    //          20:00-20:30 are concurrently moved to 10:00-14:00 and
    //          11:00-12:00 (non-overlapping with the OTHER's OLD window,
    //          overlapping with each other's NEW one).
    // ==================================================================

    public function testConcurrentUpdateVsUpdateNeverLeavesOverlap(): void
    {
        $day = 4;
        for ($attempt = 1; $attempt <= self::BINARY_ATTEMPTS; $attempt++) {
            $idX = $this->seedCellRow($day, '08:00:00', '08:30:00');
            $idY = $this->seedCellRow($day, '20:00:00', '20:30:00');

            $outcomes = $this->runWorkers([
                ['kind' => 'update', 'id' => $idX, 'start' => '10:00', 'end' => '14:00'],
                ['kind' => 'update', 'id' => $idY, 'start' => '11:00', 'end' => '12:00'],
            ]);
            $this->assertNoFatalOutcomes($outcomes, 'D attempt ' . $attempt);
            $rows = $this->cellRows($day);
            $this->assertNoActiveOverlap($rows, 'D attempt ' . $attempt);

            self::assertTrue(
                ($this->countStart($rows, '10:00:00') === 1) xor ($this->countStart($rows, '11:00:00') === 1),
                'D attempt ' . $attempt . ': exactly one of the two conflicting updates may win. '
                . 'rows=' . json_encode($rows) . ' outcomes=' . json_encode($outcomes)
            );
            $this->assertLosersHaveStableConflictErrors($outcomes, ['overlapping_shift'], 'D attempt ' . $attempt);

            $this->resetCell($day);
        }
    }

    // ==================================================================
    // CASE E — POSITIVE CONTROL: two genuinely concurrent NON-overlapping
    //          creates (08:00-12:00 and 16:00-20:00) both succeed.
    // ==================================================================

    public function testConcurrentNonOverlappingCreatesBothSucceed(): void
    {
        $outcomes = $this->runWorkers([
            ['kind' => 'create', 'day' => 5, 'start' => '08:00', 'end' => '12:00'],
            ['kind' => 'create', 'day' => 5, 'start' => '16:00', 'end' => '20:00'],
        ]);
        $this->assertNoFatalOutcomes($outcomes, 'E');
        $rows = $this->cellRows(5);

        $oks = array_values(array_filter($outcomes, static fn (array $o): bool => ($o['result'] ?? '') === 'ok'));
        self::assertCount(
            2,
            $oks,
            'E: two concurrent NON-overlapping creates must BOTH succeed. '
            . 'outcomes=' . json_encode($outcomes) . ' cell_rows=' . json_encode($rows)
        );
        self::assertCount(2, $rows, 'E: both shifts persist. rows=' . json_encode($rows));
        $this->assertNoActiveOverlap($rows, 'E');
    }

    // ==================================================================
    // Worker orchestration (pcntl_fork + independent wpdb per child +
    // absolute-time barrier — the established repo concurrency pattern).
    // ==================================================================

    /**
     * Fork one child per worker spec; children align on a shared absolute
     * time and then fire their REAL product call on their own connection.
     *
     * @param list<array{kind: string, day?: int, start: string, end: string, id?: int}> $workers
     *
     * @return list<array<string, mixed>> one outcome per worker
     */
    private function runWorkers(array $workers): array
    {
        $fireAt  = microtime(true) + self::BARRIER_SEC;
        $pids    = [];
        $files   = [];
        $crashed = [];

        foreach ($workers as $i => $worker) {
            $files[$i] = sys_get_temp_dir() . '/cpms-sched-conc-' . $this->fileTag . '-' . $i . '.json';
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                /*
                 * Child: an escaped exception would make the copied PHPUnit
                 * CONTINUE and re-run the whole suite inside the child.
                 * childWorker() therefore ALWAYS ends with exit().
                 */
                $this->childWorker($fireAt, $files[$i], $worker);
            }
            $pids[$i] = $pid;
        }

        foreach ($pids as $i => $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $crashed[] = $i;
            }
        }

        $outcomes = [];
        foreach ($workers as $i => $worker) {
            if (in_array($i, $crashed, true)) {
                $outcomes[] = ['result' => 'fatal', 'detail' => 'child crashed (exit != 0, no outcome file)', 'worker' => $worker];
                continue;
            }
            $raw = @file_get_contents($files[$i]);
            @unlink($files[$i]);
            $decoded = $raw === false ? null : (array) json_decode((string) $raw, true);
            $outcomes[] = is_array($decoded)
                ? $decoded
                : ['result' => 'fatal', 'detail' => 'no readable outcome file', 'worker' => $worker];
        }

        return $outcomes;
    }

    /**
     * Child body: fresh connection -> scope -> real service -> barrier ->
     * one production mutation inside a REAL transaction boundary ->
     * outcome file -> exit(). NEVER returns.
     *
     * @param array{kind: string, day?: int, start: string, end: string, id?: int} $worker
     */
    private function childWorker(float $fireAt, string $file, array $worker): void
    {
        $wdb = null;
        try {
            global $wpdb;
            $wdb = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            if (!method_exists($wdb, 'set_prefix')) {
                exit(9);
            }
            $wdb->set_prefix($wpdb->prefix);

            // The trusted Clinic is the server-side EXPLICIT scope (the same
            // primitive the REST boundary sets) — never a payload value.
            ScopeContext::set(ClinicScope::forClinic($this->clinicId));

            // Production wiring, on the child's own connection.
            $cpms    = new CpmsDb($wdb);
            $op      = new OpLogger($cpms);
            $service = new ScheduleService(
                $cpms,
                new ScheduleRepository($cpms),
                new MembershipRepository($cpms),
                new LocationRepository($cpms),
                new JobQueue($cpms, $op),
                new AuditLogger($cpms, $op),
                $op
            );

            // Barrier: all children open their connection first, then fire
            // the product call at the same absolute instant.
            while (microtime(true) < $fireAt) {
                usleep(250);
            }

            // REAL transaction boundary for the request (see class docblock:
            // the in-process cpms->SAVEPOINT rewrite must not own the child's
            // commit, or the winner's row rolls back when the child exits).
            $wdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            $outcome = ['result' => 'unknown'];
            try {
                if (($worker['kind'] ?? '') === 'create') {
                    $view = $service->create($this->professionalUserId, [
                        'clinician_id' => $this->clinicianId,
                        'location_id'  => $this->locationId,
                        'day_of_week'  => $worker['day'],
                        'start_time'   => $worker['start'],
                        'end_time'     => $worker['end'],
                    ]);
                    $outcome = ['result' => 'ok', 'id' => (int) ($view['id'] ?? 0), 'kind' => 'create'];
                } else {
                    $view = $service->update($this->professionalUserId, $worker['id'], [
                        'start_time' => $worker['start'],
                        'end_time'   => $worker['end'],
                    ]);
                    $outcome = ['result' => 'ok', 'id' => (int) ($view['id'] ?? 0), 'kind' => 'update'];
                }
            } catch (BookingException $e) {
                $outcome = [
                    'result' => 'error',
                    'code'   => $e->errorCode,
                    'http'   => $e->httpStatus,
                    'errors' => (array) ($e->data['errors'] ?? []),
                ];
            } catch (\Throwable $e) {
                // A raw PHP/SQL failure here is itself a contract violation
                // (no stable product error) — recorded, not swallowed.
                $outcome = ['result' => 'fatal', 'detail' => get_class($e) . ': ' . $e->getMessage()];
            }

            $wdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            file_put_contents($file, json_encode($outcome, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // Connection/setup/bootstrap failure in the child: still leave a
            // readable outcome (infrastructure evidence, not a silent pass).
            @file_put_contents(
                $file,
                json_encode(['result' => 'fatal', 'detail' => get_class($e) . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE)
            );
        }
        if ($wdb instanceof \wpdb) {
            @$wdb->close();
        }
        exit(0);
    }

    // ==================================================================
    // Final-state readers (fresh connection — the parent's WP Test Suite
    // connection is inside a stale REPEATABLE-READ transaction).
    // ==================================================================

    /**
     * All schedule rows of the test cell (Clinic, Location, clinician, day).
     *
     * @return list<array<string, mixed>>
     */
    private function cellRows(int $day): array
    {
        $conn = $this->freshMysqli();
        $sql  = 'SELECT id, start_time, end_time, is_active FROM ' . App::db()->table('cpms_schedule') .
            ' WHERE clinician_id = ' . (int) $this->clinicianId .
            ' AND clinic_id = ' . (int) $this->clinicId .
            ' AND location_id = ' . (int) $this->locationId .
            ' AND day_of_week = ' . (int) $day .
            ' ORDER BY start_time, id';
        $res = $conn->query($sql);
        $rows = [];
        while ($res !== false && ($row = $res->fetch_assoc()) !== null) {
            $rows[] = (array) $row;
        }
        $conn->close();

        return $rows;
    }

    private function freshMysqli(): \mysqli
    {
        $mysqli = @new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if ($mysqli->connect_errno !== 0) {
            $this->markTestSkipped('Cannot open independent DB connection: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    // ==================================================================
    // Fixture seeding / reset (committed — children must see it)
    // ==================================================================

    private function seedCellRow(int $day, string $start, string $end): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule
                 (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time,
                  appointment_duration_min, slot_capacity, is_active, created_at, updated_at)
             VALUES (%d, %d, %d, %d, %s, %s, 20, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->clinicId,
            $this->locationId,
            $this->clinicianId,
            $day,
            $start,
            $end,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: seed row inserted (' . $wpdb->last_error . ')');
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return $id;
    }

    private function resetCell(int $day): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'cpms_schedule
             WHERE clinic_id = %d AND clinician_id = %d AND location_id = %d AND day_of_week = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->clinicId,
            $this->clinicianId,
            $this->locationId,
            $day
        ));
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    // ==================================================================
    // Cleanup (committed rows — manual purge, FK-safe order)
    // ==================================================================

    private function purgeSideEffectLogs(): void
    {
        global $wpdb;
        if ($this->sideEffectFloor === '') {
            return;
        }
        // Side effects COMMITTED by child connections (winner's audit/op/job):
        // windowed by this test's start.
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE clinic_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->clinicId
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'cpms_operational_logs WHERE created_at >= %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->sideEffectFloor
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'cpms_jobs WHERE type = %s AND created_at >= %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'slots.generate',
            $this->sideEffectFloor
        ));
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function purgeTopology(): void
    {
        global $wpdb;
        $org = (int) $this->orgId;
        if ($org <= 0) {
            return;
        }
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_schedule_slots t
                      WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_schedule t
                      WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE m FROM ' . $wpdb->prefix . 'cpms_clinic_memberships m
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = m.clinic_id
                      WHERE c.organization_id = ' . $org); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_clinicians t
                      WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE t FROM ' . $wpdb->prefix . 'cpms_locations t
                      WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = ' . $org); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // users committed outside the test transaction
        if ($this->professionalUserId > 0) {
            $wpdb->delete($wpdb->usermeta, ['user_id' => $this->professionalUserId], ['%d']);
            $wpdb->delete($wpdb->users, ['ID' => $this->professionalUserId], ['%d']);
        }
    }

    private function makeUser(string $login): int
    {
        $unique   = $login . '_' . bin2hex(random_bytes(3));
        $userId   = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@p6s7.test');
        self::assertGreaterThan(0, $userId, 'precondition: wp user ' . $login);
        $user     = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role(RolesAndCapabilities::ROLE_DOCTOR);

        return $userId;
    }

    // ==================================================================
    // Assertion helpers
    // ==================================================================

    /**
     * No child may crash or leak a raw (non-product) failure: a fatal
     * outcome here is either infrastructure (C) or a product defect (raw
     * error leak) — never a valid RED/GREEN signal.
     *
     * @param list<array<string, mixed>> $outcomes
     */
    private function assertNoFatalOutcomes(array $outcomes, string $case): void
    {
        $fals = array_values(array_filter($outcomes, static fn (array $o): bool => ($o['result'] ?? '') === 'fatal'));
        self::assertSame(
            [],
            $fals,
            $case . ': no child may crash or surface a raw (non-product) failure: ' . json_encode($fals)
        );
    }

    /**
     * Every non-ok, non-fatal outcome must be the STABLE product envelope:
     * CLINIC_VALIDATION_FAILED / 400 with one of the expected machine
     * reasons — never a raw SQL/uniqueness/deadlock message.
     *
     * @param list<array<string, mixed>> $outcomes
     * @param list<string>               $expectedReasons
     */
    private function assertLosersHaveStableConflictErrors(array $outcomes, array $expectedReasons, string $case): void
    {
        $losers = array_values(array_filter($outcomes, static fn (array $o): bool => ($o['result'] ?? '') === 'error'));
        foreach ($losers as $loser) {
            self::assertSame(
                'CLINIC_VALIDATION_FAILED',
                $loser['code'] ?? null,
                $case . ': loser must carry the stable envelope code. outcome=' . json_encode($loser)
            );
            self::assertSame(
                400,
                (int) ($loser['http'] ?? 0),
                $case . ': loser must carry the stable HTTP 400. outcome=' . json_encode($loser)
            );
            $reasons = array_values((array) ($loser['errors'] ?? []));
            $known   = array_values(array_intersect($reasons, $expectedReasons));
            self::assertNotSame(
                [],
                $known,
                $case . ': loser must carry one of ' . json_encode($expectedReasons) . '. outcome=' . json_encode($loser)
            );
        }
    }

    /**
     * THE invariant: no two ACTIVE rows of the cell may overlap.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function assertNoActiveOverlap(array $rows, string $case): void
    {
        $active = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['is_active'] === 1));
        for ($i = 0, $n = count($active); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $active[$i];
                $b = $active[$j];
                $overlap = $this->toSeconds((string) $a['start_time']) < $this->toSeconds((string) $b['end_time'])
                    && $this->toSeconds((string) $b['start_time']) < $this->toSeconds((string) $a['end_time']);
                self::assertFalse(
                    $overlap,
                    $case . ': two ACTIVE overlapping rows survived the race: '
                    . $a['start_time'] . '-' . $a['end_time'] . ' x ' . $b['start_time'] . '-' . $b['end_time']
                );
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function countStart(array $rows, string $start): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (substr((string) $row['start_time'], 0, 8) === $start) {
                $count++;
            }
        }

        return $count;
    }

    private function toSeconds(string $hms): int
    {
        $parts = array_pad(explode(':', $hms), 3, '0');

        return ((int) $parts[0]) * 3600 + ((int) $parts[1]) * 60 + ((int) $parts[2]);
    }
}
