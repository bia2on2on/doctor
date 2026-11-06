<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Jobs\HoldsExpireHandler;
use ClinicCore\Infrastructure\Db\CpmsDb;
use PHPUnit\Framework\TestCase;

/**
 * B2 — capacity release on Hold expiry (holds.expire job).
 *
 * ============================================================================
 * DEFECT UNDER TEST (B2)
 * ============================================================================
 *
 * `HoldsExpireHandler` decremented `cpms_schedule_slots.held_count` whenever the
 * status UPDATE of the Hold row "succeeded", with NO affected-rows check. The
 * guard it relied on cannot distinguish the two outcomes, because
 * `CpmsDb::query()` returns `bool`:
 *
 *     $ok = $this->wpdb->query( $prepared ) !== false;
 *
 * `$wpdb->query()` returns the affected-row count for an UPDATE, so a conditional
 * UPDATE that matched ZERO rows returns `0`, and `0 !== false` is `true`. The
 * handler therefore treated "matched nothing" as "I changed the row" and went on
 * to release one unit of capacity from the slot.
 *
 * Observable consequences:
 *   - a hold converted or released by a competing worker between the handler's
 *     SELECT and its UPDATE still had capacity released for it, double-counting
 *     the release against the same slot;
 *   - a re-run over a stale work list could release the same hold's capacity
 *     twice;
 *   - nothing at the handler level pinned the counter floor, so any such double
 *     release drove `held_count` down instead of stopping at zero.
 *
 * ============================================================================
 * WHY A UNIT TEST WITH A SQL MIRROR
 * ============================================================================
 *
 * The behaviour that decides this defect IS the SQL: `WHERE id = %d AND status =
 * %s AND expires_at <= %s`, `GREATEST(held_count - 1, 0)` and `WHERE held_count >
 * 0`. Those statements are therefore executed rather than mocked, against an
 * in-memory SQLite database holding the two tables involved, with the production
 * column definitions for the fields under test.
 *
 * A stub `wpdb` subclasses the WordPress base class (which the plain unit
 * bootstrap does not provide) and forwards statements to that mirror.
 * `query()` returns SQLite's changed-row count, which is the same contract
 * `$wpdb->query()` exposes for UPDATE (`$return_val = $this->rows_affected`).
 *
 * The stub is defined in the global namespace under the name `wpdb` because
 * `CpmsDb`'s constructor is typed against that class. That is a shared-process
 * side effect on the Unit suite; it is safe here because the only other unit
 * test that reaches for `$wpdb` (RequestProfilerTest) guards every read behind
 * `RequestProfiler::armed()`, which is false in this suite.
 *
 * LIMITS, stated plainly: this proves the handler's counter logic, idempotency
 * and floor. It does NOT prove InnoDB row-lock behaviour, transaction isolation
 * between concurrent workers, or that the real MySQL DDL matches this reduced
 * mirror. Those belong to the Integration suite, which needs the WP test library
 * and a live MySQL server.
 */
final class HoldsExpireHandlerCapacityTest extends TestCase
{
    private \wpdb $wpdb;

    private CpmsDb $db;

    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        if (!defined('OBJECT')) {
            define('OBJECT', 'OBJECT');
        }

        if (!class_exists('wpdb', false)) {
            eval($this->wpdbStubSource());
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if (method_exists($this->pdo, 'sqliteCreateFunction')) {
            // MySQL's GREATEST() has no SQLite equivalent; register the same
            // variadic maximum so the production statement runs unmodified.
            $this->pdo->sqliteCreateFunction(
                'GREATEST',
                static function (...$values) {
                    return max($values);
                },
                -1
            );
        }
        $this->pdo->exec(
            'CREATE TABLE wp_cpms_schedule_slots (
                id INTEGER PRIMARY KEY,
                capacity INTEGER NOT NULL DEFAULT 1,
                booked_count INTEGER NOT NULL DEFAULT 0,
                held_count INTEGER NOT NULL DEFAULT 0,
                is_open INTEGER NOT NULL DEFAULT 1,
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE wp_cpms_slot_holds (
                id INTEGER PRIMARY KEY,
                slot_id INTEGER NOT NULL,
                token TEXT NOT NULL,
                status TEXT NOT NULL,
                expires_at TEXT NOT NULL
            )'
        );

        $this->wpdb = new \wpdb($this->pdo);
        $this->db   = new CpmsDb($this->wpdb);
    }

    protected function tearDown(): void
    {
        $this->db->setStrict(false);
        parent::tearDown();
    }

    // ================= fixtures =================

    private function seedSlot(int $id, int $capacity, int $booked, int $held): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wp_cpms_schedule_slots (id, capacity, booked_count, held_count, is_open, updated_at)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([$id, $capacity, $booked, $held, $this->past()]);
    }

    private function seedHold(int $id, int $slotId, string $status, string $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wp_cpms_slot_holds (id, slot_id, token, status, expires_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $slotId, 'token-' . $id, $status, $expiresAt]);
    }

    private function heldCount(int $slotId): int
    {
        $stmt = $this->pdo->prepare('SELECT held_count FROM wp_cpms_schedule_slots WHERE id = ?');
        $stmt->execute([$slotId]);

        return (int) $stmt->fetchColumn();
    }

    private function holdStatus(int $holdId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM wp_cpms_slot_holds WHERE id = ?');
        $stmt->execute([$holdId]);

        return (string) $stmt->fetchColumn();
    }

    /** A timestamp safely in the past, for `expires_at`. */
    private function past(): string
    {
        return '2020-01-01 00:00:00.000';
    }

    /** A timestamp safely in the future: an unexpired hold must never be touched. */
    private function future(): string
    {
        return '2099-01-01 00:00:00.000';
    }

    // ================= tests =================

    /**
     * Positive control: one expired hold on a one-unit slot releases exactly one
     * unit and reports exactly one freed hold. If this fails, no other test here
     * carries meaning.
     */
    public function testPositiveControlExpiredHoldReleasesExactlyOneUnit(): void
    {
        $this->seedSlot(1, 1, 0, 1);
        $this->seedHold(10, 1, 'active', $this->past());

        $freed = (new HoldsExpireHandler($this->db))([]);

        self::assertSame(1, $freed, 'exactly one hold was freed');
        self::assertSame(0, $this->heldCount(1), 'held_count went 1 -> 0');
        self::assertSame('expired', $this->holdStatus(10), 'hold row is marked expired');
    }

    /**
     * Contract: a second run has nothing to expire and must not decrement again.
     *
     * Note honestly: this alone would also pass before the fix, because the
     * second run's SELECT no longer returns the hold. It is kept as the
     * documented contract; the three tests below drive the real stale-work-list
     * path that the fix addresses.
     */
    public function testSecondRunOnSameHoldDoesNotDecrementAgain(): void
    {
        $this->seedSlot(1, 1, 0, 1);
        $this->seedHold(10, 1, 'active', $this->past());

        $handler = new HoldsExpireHandler($this->db);
        self::assertSame(1, $handler([]), 'first run frees the hold');
        $afterFirst = $this->heldCount(1);

        self::assertSame(0, $handler([]), 'second run has nothing to expire');
        self::assertSame($afterFirst, $this->heldCount(1), 'counter unchanged on the second run');
        self::assertSame(0, $this->heldCount(1));
    }

    /**
     * RED — the hold is converted by a competing worker after the handler built
     * its work list. The guarded UPDATE matches zero rows, so capacity must stay
     * with the converted hold.
     *
     * Deterministic: the stub runs a callback between the SELECT that builds the
     * work list and the UPDATE that follows it.
     */
    public function testHoldConvertedMidRunDoesNotReleaseCapacity(): void
    {
        $this->seedSlot(1, 2, 0, 1);
        $this->seedHold(10, 1, 'active', $this->past());

        $this->wpdb->afterNextSelect = function (): void {
            $this->pdo->exec("UPDATE wp_cpms_slot_holds SET status = 'converted' WHERE id = 10");
        };

        $freed = (new HoldsExpireHandler($this->db))([]);

        self::assertSame(0, $freed, 'no hold was freed: the guarded UPDATE matched no row');
        self::assertSame(1, $this->heldCount(1), 'capacity stayed with the converted hold');
        self::assertSame('converted', $this->holdStatus(10), 'the competing claim is preserved');
    }

    /**
     * RED — the hold is already `released` when the handler reaches it. Capacity
     * must not be released a second time.
     */
    public function testAlreadyReleasedHoldDoesNotReleaseCapacity(): void
    {
        $this->seedSlot(1, 2, 0, 1);
        $this->seedHold(10, 1, 'active', $this->past());

        $this->wpdb->afterNextSelect = function (): void {
            $this->pdo->exec("UPDATE wp_cpms_slot_holds SET status = 'released' WHERE id = 10");
        };

        $freed = (new HoldsExpireHandler($this->db))([]);

        self::assertSame(0, $freed, 'already-released hold is not freed twice');
        self::assertSame(1, $this->heldCount(1), 'held_count is untouched');
    }

    /**
     * RED — a zero-row status UPDATE is a *successful* statement. Treating it as
     * "changed" is exactly the defect, so it must not consume capacity.
     */
    public function testZeroRowUpdateIsTreatedAsNoChange(): void
    {
        $this->seedSlot(1, 1, 0, 1);
        $this->seedHold(10, 1, 'active', $this->past());

        $this->wpdb->afterNextSelect = function (): void {
            $this->pdo->exec("UPDATE wp_cpms_slot_holds SET status = 'expired' WHERE id = 10");
        };

        $freed = (new HoldsExpireHandler($this->db))([]);

        self::assertSame(0, $freed);
        self::assertSame(
            1,
            $this->heldCount(1),
            'a zero-row status UPDATE must not consume capacity'
        );
    }

    /**
     * Two holds expire against the same slot in one run: two units, one each.
     * Guards the per-hold accounting against an off-by-one.
     */
    public function testTwoExpiredHoldsReleaseTwoUnits(): void
    {
        $this->seedSlot(1, 3, 0, 2);
        $this->seedHold(10, 1, 'active', $this->past());
        $this->seedHold(11, 1, 'active', $this->past());

        $freed = (new HoldsExpireHandler($this->db))([]);

        self::assertSame(2, $freed);
        self::assertSame(0, $this->heldCount(1));
    }

    /**
     * An unexpired hold is never released and the counter is never touched.
     */
    public function testUnexpiredHoldIsNotReleased(): void
    {
        $this->seedSlot(1, 1, 0, 1);
        $this->seedHold(10, 1, 'active', $this->future());

        $freed = (new HoldsExpireHandler($this->db))([]);

        self::assertSame(0, $freed, 'future holds are out of scope for this tick');
        self::assertSame(1, $this->heldCount(1));
        self::assertSame('active', $this->holdStatus(10));
    }

    /**
     * The counter can never go below zero, even when the slot row already says
     * held_count = 0 while an expired hold still exists. `GREATEST(..., 0)` and
     * `AND held_count > 0` must both hold.
     */
    public function testCounterNeverGoesNegative(): void
    {
        $this->seedSlot(1, 1, 0, 0);
        $this->seedHold(10, 1, 'active', $this->past());

        $freed = (new HoldsExpireHandler($this->db))([]);

        self::assertSame(1, $freed, 'the hold itself was expired');
        self::assertSame(0, $this->heldCount(1), 'held_count stayed at the floor of zero, not -1');
    }

    /**
     * RED — a real DB error in the hold-status UPDATE must not be read as
     * "zero rows changed". The hold stays active, capacity is untouched and
     * the handler reports an error instead of a silent no-op.
     */
    public function testHoldStatusUpdateErrorAbortsWithoutReleasingCapacity(): void
    {
        $this->seedSlot(1, 1, 0, 1);
        $this->seedHold(10, 1, 'active', $this->past());
        $this->wpdb->writeFault = static function (string $sql): ?string {
            return strpos($sql, 'cpms_slot_holds') !== false ? 'simulated deadlock on holds' : null;
        };

        $freed = $this->runExpectingFailure();

        self::assertSame(0, $freed, 'a failed run must not report a freed hold');
        self::assertSame(1, $this->heldCount(1), 'capacity must not be released on a DB error');
        self::assertSame('active', $this->holdStatus(10), 'the hold stays active so a later run retries it');
    }

    /**
     * RED — a real DB error in the slot-release UPDATE must roll the whole hold
     * back, including the status change that already succeeded in this
     * transaction. Otherwise the hold would be marked expired while the slot
     * still counts it: capacity silently lost, never retried.
     */
    public function testSlotReleaseUpdateErrorRollsBackTheHoldStatusChange(): void
    {
        $this->seedSlot(1, 1, 0, 1);
        $this->seedHold(10, 1, 'active', $this->past());
        $this->wpdb->writeFault = static function (string $sql): ?string {
            return strpos($sql, 'cpms_schedule_slots') !== false ? 'simulated deadlock on slots' : null;
        };

        $freed = $this->runExpectingFailure();

        self::assertSame(0, $freed, 'a failed run must not report a freed hold');
        self::assertSame(1, $this->heldCount(1), 'the slot counter must be exactly what it was');
        self::assertSame('active', $this->holdStatus(10), 'the status change must have been rolled back');
    }

    /**
     * Positive control for the error path: the same injected fault is removed
     * and the identical fixture must now expire the hold and free one unit.
     * Without this, the two tests above could pass for the wrong reason.
     */
    public function testRecoveryAfterDbErrorSucceedsOnTheNextRun(): void
    {
        $this->seedSlot(1, 1, 0, 1);
        $this->seedHold(10, 1, 'active', $this->past());
        $this->wpdb->writeFault = static function (string $sql): ?string {
            return strpos($sql, 'cpms_slot_holds') !== false ? 'simulated deadlock on holds' : null;
        };
        self::assertSame(0, $this->runExpectingFailure(), 'the faulty run frees nothing');

        $this->wpdb->writeFault = null;
        $freed = (new HoldsExpireHandler($this->db))([]);

        self::assertSame(1, $freed, 'the retry frees the hold that stayed active');
        self::assertSame(0, $this->heldCount(1), 'the retry releases exactly one unit');
        self::assertSame('expired', $this->holdStatus(10), 'the hold is expired on the retry');
    }

    /**
     * Runs the handler and returns the number of holds it reported as freed,
     * asserting that the run surfaced the injected DB error instead of
     * swallowing it.
     */
    private function runExpectingFailure(): int
    {
        try {
            (new HoldsExpireHandler($this->db))([]);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('holds.expire write failed', $e->getMessage());

            return 0;
        }

        self::fail('a real DB error must abort the run, not be treated as zero affected rows');
    }

    // ================= stub =================

    /**
     * Global-namespace `wpdb` stub. Mirrors only what `CpmsDb` calls; every
     * statement goes to the SQLite mirror. `query()` returns the changed-row
     * count, matching `$wpdb->query()` for writes.
     */
    private function wpdbStubSource(): string
    {
        return <<<'PHP'
namespace {
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $base_prefix = 'wp_';
        public string $last_error = '';
        public int $num_queries = 0;
        public int $rows_affected = 0;
        public int $insert_id = 0;
        public $dbh = null;
        public bool $ready = true;
        public $last_result = [];
        /** @var callable|null runs once, right after the next SELECT returns rows */
        public $afterNextSelect = null;
        /**
         * @var callable|null failure injection: receives the SQL about to run and
         * returns an error message to fail it with, or null to run it normally.
         */
        public $writeFault = null;
        /** @var list<mixed> */
        private array $pending = [];
        private \PDO $pdo;

        public function __construct(\PDO $pdo)
        {
            $this->pdo = $pdo;
            $this->dbh = $pdo;
        }

        public function prepare($query, ...$args)
        {
            $flat = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;
            $this->pending = array_values($flat);
            if ($this->pending === []) {
                return (string) $query;
            }
            $out = '';
            $len = strlen((string) $query);
            for ($p = 0; $p < $len; $p++) {
                $ch = $query[$p];
                if ($ch === '%' && $p + 1 < $len) {
                    $spec = $query[$p + 1];
                    if ($spec === '%') { $out .= '%'; $p++; continue; }
                    if ($spec === 's' || $spec === 'd' || $spec === 'f') { $out .= '?'; $p++; continue; }
                }
                $out .= $ch;
            }
            return $out;
        }

        private function run(string $sql)
        {
            if ($this->pending === []) {
                return $this->pdo->query($sql);
            }
            $stmt = $this->pdo->prepare($sql);
            $n = 1;
            foreach ($this->pending as $value) {
                $stmt->bindValue($n++, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $this->pending = [];
            $stmt->execute();
            return $stmt;
        }

        private function fire(): void
        {
            if ($this->afterNextSelect !== null) {
                $cb = $this->afterNextSelect;
                $this->afterNextSelect = null;
                $cb();
            }
        }

        public function query($query)
        {
            $this->num_queries++;
            $this->last_error = '';
            $sql = (string) $query;
            try {
                if (preg_match('/^\s*\/\*cpms\*\/\s*START TRANSACTION/i', $sql) === 1) {
                    if (!$this->pdo->inTransaction()) {
                        $this->pdo->beginTransaction();
                    }
                    $this->pending = [];
                    return 1;
                }
                if (preg_match('/^\s*\/\*cpms\*\/\s*COMMIT/i', $sql) === 1) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->commit();
                    }
                    $this->pending = [];
                    return 1;
                }
                if (preg_match('/^\s*\/\*cpms\*\/\s*(SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)/i', $sql) === 1) {
                    $this->pdo->exec(preg_replace('/^\s*\/\*cpms\*\/\s*/', '', $sql));
                    $this->pending = [];
                    return 1;
                }
                if (preg_match('/^\s*\/\*cpms\*\/\s*ROLLBACK/i', $sql) === 1) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    $this->pending = [];
                    return 1;
                }

                if ($this->writeFault !== null
                    && preg_match('/^\s*(UPDATE|DELETE|INSERT|REPLACE)\b/i', $sql) === 1) {
                    $fault = ($this->writeFault)($sql);
                    if (is_string($fault) && $fault !== '') {
                        // wpdb contract: the statement fails, last_error is set,
                        // query() returns false and the row is left untouched.
                        $this->last_error = $fault;
                        $this->pending = [];
                        return false;
                    }
                }

                $stmt = $this->run($sql);
                if ($stmt === false) {
                    return false;
                }
                if (preg_match('/^\s*(UPDATE|DELETE|INSERT|REPLACE)\b/i', $sql) === 1) {
                    $this->rows_affected = $stmt->rowCount();
                    return $this->rows_affected;
                }
                if (preg_match('/^\s*SELECT\b/i', $sql) === 1) {
                    $this->last_result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    $this->fire();
                    return count($this->last_result);
                }
                return 0;
            } catch (\Throwable $e) {
                $this->last_error = $e->getMessage();
                return false;
            }
        }

        public function get_results($query = null, $output = OBJECT)
        {
            $this->num_queries++;
            $this->last_error = '';
            try {
                $stmt = $this->run((string) $query);
                $rows = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $this->last_error = $e->getMessage();
                return [];
            }
            $this->fire();
            return array_map(static function (array $r) { return (object) $r; }, $rows);
        }

        public function get_row($query = null, $output = OBJECT, $y = 0)
        {
            $rows = $this->get_results($query, $output);
            return $rows[0] ?? null;
        }

        public function get_var($query = null, $x = 0, $y = 0)
        {
            $rows = $this->get_results($query);
            if ($rows === []) {
                return null;
            }
            $first = (array) $rows[0];
            return array_values($first)[0] ?? null;
        }

        public function insert($table, $data, $format = null)
        {
            return 1;
        }

        public function update($table, $data, $where, $format = null, $where_format = null)
        {
            return 1;
        }

        public function delete($table, $where, $where_format = null)
        {
            return 1;
        }
    }
}
PHP;
    }
}
