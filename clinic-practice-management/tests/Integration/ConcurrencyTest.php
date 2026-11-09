<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Concurrency Tests (F3) — TP-03: N Request هم‌زمان روی یک Slot.
 *
 * تضمین ADR-0004 در سطح DB با Conditional UPDATE اتمیک است (بدون Read-then-Write).
 * این تست با **پردازهای واقعی موازی** (pcntl_fork — اتصال mysqli مستقل هر پرداز)
 * و **اتصال‌های موازی** همان تضمین را می‌سنجد:
 *
 *  - ظرفیت ۱: دقیقاً یک Claim/Hold موفق؛ بقیه fail
 *  - ظرفیت N: دقیقاً N موفق
 *  - شمارنده‌های hold/claim در تبدیل، دقیق و بدون نشت ظرفیت
 *
 * نکته محیط: WP Test Suite هر تست را داخل Transaction والد اجرا می‌کند؛ اتصال‌های
 * مستقل نمی‌توانند ردیف‌های uncommitted را ببینند/قفل کنند. Fixtureهای worker روی
 * اتصال مستقل commit و با همان مرز مستقل حذف می‌شوند. Sentinel نامرتبط اثبات می‌کند
 * که این کارها تراکنش والد را commit نمی‌کنند و rollback والد sentinel را حذف می‌کند.
 *
 * لایه‌بندی پوشش: نتیجه در سطح Service/REST (تلاش دوم → CLINIC_SLOT_TAKEN/409)
 * در BookingFlowTest/RestBookingTest پوشش داده شده است.
 */
final class ConcurrencyTest extends WP_UnitTestCase
{
    private int $clinicId = 1;
    private int $clinicianId = 0;
    private string $sentinelKey = '';

    /** @var list<int> */
    private array $slotIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();

        // Any transaction-level COMMIT on $wpdb would make this unrelated
        // write durable. Fixture commits must use only independent connections.
        global $wpdb;
        $this->sentinelKey = 'test.concurrency.sentinel.' . bin2hex(random_bytes(8));
        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_settings
                (clinic_id, `key`, value_json, updated_at)
             VALUES (%d, %s, %s, %s)',
            $this->clinicId,
            $this->sentinelKey,
            (string) wp_json_encode(['pending' => true]),
            App::db()->nowUtcSql()
        ));
        self::assertSame(1, $inserted, 'The unrelated rollback sentinel must be pending before fixture setup.');
    }

    protected function tearDown(): void
    {
        $cleanupFailures = $this->cleanupCommittedFixtures();

        // The only rollback of the parent transaction occurs in the base test.
        parent::tearDown();

        $sentinelFailures = $this->assertPendingSentinelRolledBack();
        self::assertSame([], $cleanupFailures, 'Concurrency fixtures must be deleted exactly once and durably absent.');
        self::assertSame([], $sentinelFailures, 'Fixture setup/cleanup must not commit unrelated pending writes.');
    }

    /**
     * TP-03 — ۱۰ پرداز موازی، ظرفیت ۱: دقیقاً یک موفق.
     */
    public function testParallelWorkersClaimCapacityOneExactlyOnceSucceeds(): void
    {
        $slotId = $this->committedSlot(capacity: 1);

        $successes = $this->forkWorkers(10, $this->atomicBookSql(), $slotId);
        $this->assertSame(1, $successes, 'Exactly one parallel worker must succeed (TP-03)');
        $this->assertSlotState($slotId, booked: 1, held: 0);
    }

    /**
     * TP-03 (Hold) — ۸ پرداز موازی روی Hold با ظرفیت ۱: دقیقاً یک موفق.
     */
    public function testParallelWorkersHoldCapacityOneExactlyOnceSucceeds(): void
    {
        $slotId = $this->committedSlot(capacity: 1);

        $successes = $this->forkWorkers(8, $this->atomicHoldSql(), $slotId);
        $this->assertSame(1, $successes);
        $this->assertSlotState($slotId, booked: 0, held: 1);
    }

    /**
     * ظرفیت N=3 با ۹ پرداز موازی: دقیقاً ۳ موفق — شمارنده دقیق.
     */
    public function testParallelWorkersCapacityNExactCountSucceeds(): void
    {
        $slotId = $this->committedSlot(capacity: 3);

        $successes = $this->forkWorkers(9, $this->atomicBookSql(), $slotId);
        $this->assertSame(3, $successes, 'Exactly capacity(3) workers must succeed');
        $this->assertSlotState($slotId, booked: 3, held: 0);
    }

    /**
     * Hold→Claim روی دو اتصال موازی — شمارنده‌ها دقیق، بدون نشت ظرفیت.
     */
    public function testHoldThenClaimAcrossSeparateConnectionsKeepsCountersExact(): void
    {
        $slotId = $this->committedSlot(capacity: 1);

        $connA = $this->freshMysqli();
        $connB = $this->freshMysqli();

        // A: Hold (اتمیک)
        $this->assertTrue($this->conditionalUpdate($connA, $this->atomicHoldSql(), $slotId));
        $this->assertSlotState($slotId, booked: 0, held: 1);

        // B: Book مستقیم وقتی کل ظرفیت Hold شده → fail
        $this->assertFalse($this->conditionalUpdate($connB, $this->atomicBookSql(), $slotId));

        // B: Claim (hold→booked)
        $this->assertTrue($this->conditionalUpdate($connB, $this->atomicClaimSql(), $slotId));
        $this->assertSlotState($slotId, booked: 1, held: 0);

        // A: Claim دوباره → fail (holdی نیست)
        $this->assertFalse($this->conditionalUpdate($connA, $this->atomicClaimSql(), $slotId));

        // A: Release Hold روی slot بدون hold → fail (شرط held>0)
        $this->assertFalse($this->conditionalUpdate($connA, $this->releaseHoldSql(), $slotId));
        $this->assertSlotState($slotId, booked: 1, held: 0);

        $connA->close();
        $connB->close();
    }

    // ---------- Fixture (committed on an independent connection) ----------

    /**
     * Commit only the clinician/slot rows needed by independent workers.
     * The WordPress test connection and its transaction are never used here.
     */
    private function committedSlot(int $capacity): int
    {
        $conn = $this->connectMysqli();
        if (!$conn->begin_transaction()) {
            $conn->close();
            throw new \RuntimeException('Concurrency fixture transaction could not start.');
        }

        try {
            $locationId = $this->primaryLocationId($conn);
            $now        = gmdate('Y-m-d H:i:s') . '.000';
            $clinician  = 'Dr Concurrency ' . bin2hex(random_bytes(6));
            $clinicId   = $this->clinicId;
            $clinicianStatement = $conn->prepare(
                'INSERT INTO ' . $this->table('cpms_clinicians') . '
                    (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (?, ?, 1, ?, ?)'
            );
            if ($clinicianStatement === false) {
                throw new \RuntimeException('Concurrency clinician fixture could not be prepared.');
            }
            $clinicianStatement->bind_param('isss', $clinicId, $clinician, $now, $now);
            $clinicianStatement->execute();
            if ($clinicianStatement->affected_rows !== 1) {
                $clinicianStatement->close();
                throw new \RuntimeException('Concurrency clinician fixture was not inserted.');
            }
            $clinicianId = (int) $conn->insert_id;
            $clinicianStatement->close();
            if ($clinicianId <= 0) {
                throw new \RuntimeException('Concurrency clinician fixture has no id.');
            }

            // A far-future date and an isolated clinician avoid conflicts with
            // fixtures created by other tests or workers.
            $date = gmdate('Y-m-d', time() + (330 + random_int(0, 60)) * 86400);
            $time = sprintf('%02d:%02d:00', random_int(8, 15), random_int(0, 59));
            $slotStatement = $conn->prepare(
                'INSERT INTO ' . $this->table('cpms_schedule_slots') . '
                    (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min,
                     capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 20, ?, 0, 0, 1, ?, ?)'
            );
            if ($slotStatement === false) {
                throw new \RuntimeException('Concurrency slot fixture could not be prepared.');
            }
            $slotStatement->bind_param(
                'iiississ',
                $clinicId,
                $locationId,
                $clinicianId,
                $date,
                $time,
                $capacity,
                $now,
                $now
            );
            $slotStatement->execute();
            if ($slotStatement->affected_rows !== 1) {
                $slotStatement->close();
                throw new \RuntimeException('Concurrency slot fixture was not inserted.');
            }
            $slotId = (int) $conn->insert_id;
            $slotStatement->close();
            if ($slotId <= 0) {
                throw new \RuntimeException('Concurrency slot fixture has no id.');
            }

            // Track ids before commit so teardown can still remove them if the
            // server committed but the client did not receive the acknowledgment.
            $this->clinicianId = $clinicianId;
            $this->slotIds[]   = $slotId;
            if (!$conn->commit()) {
                throw new \RuntimeException('Concurrency fixture transaction could not be committed.');
            }
        } catch (\Throwable $error) {
            try {
                $conn->rollback();
            } catch (\Throwable) {
                // Preserve the fixture error; teardown still verifies durable absence.
            }
            $conn->close();
            throw $error;
        }

        $conn->close();

        // Verify committed visibility from a fresh connection, then prove the
        // unrelated wpdb sentinel is still uncommitted on the parent connection.
        $visibility = $this->connectMysqli();
        try {
            self::assertSame(1, $this->countById($visibility, 'cpms_clinicians', $this->clinicianId));
            self::assertSame(1, $this->countById($visibility, 'cpms_schedule_slots', $slotId));
        } finally {
            $visibility->close();
        }
        $this->assertPendingSentinelIsIsolated();

        return $slotId;
    }

    private function primaryLocationId(\mysqli $conn): int
    {
        $statement = $conn->prepare(
            'SELECT id FROM ' . $this->table('cpms_locations') .
            ' WHERE clinic_id = ? AND is_primary = 1 ORDER BY id LIMIT 1'
        );
        if ($statement === false) {
            throw new \RuntimeException('Primary Location fixture query could not be prepared.');
        }
        $clinicId = $this->clinicId;
        $statement->bind_param('i', $clinicId);
        $statement->execute();
        $locationId = 0;
        $statement->bind_result($locationId);
        $found = $statement->fetch();
        $statement->close();
        if (!$found || (int) $locationId <= 0) {
            throw new \RuntimeException('The committed primary Location fixture is unavailable.');
        }

        return (int) $locationId;
    }

    // ---------- Fork orchestration ----------

    /**
     * @param string $sql همان SQL اتمیک SlotRepository (متد atomicXxx)
     * @return int تعداد پردازهای موفق (exit code 0)
     */
    private function forkWorkers(int $count, string $sql, int $slotId): int
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available in this environment (CI Linux has it)');
        }

        $pids = [];
        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                /*
                 * ---- Child: اتصال مستقل + یک UPDATE اتمیک ----
                 * هر خطا باید به exit() منجر شود: اگر استثنایی (حتی
                 * markTestSkipped) از فرزند فرار کند، PHPUnitِ کپی‌شده در فرزند
                 * «ادامه» می‌دهد و کل Suite را دوباره اجرا می‌کند (zombie) —
                 * خروجی‌ها interleave شده و اتصال مشترک والد خراب می‌شود.
                 */
                $exitCode = 1;
                $mysqli = null;
                try {
                    $mysqli = $this->freshMysqli();
                    $stmt = $mysqli->prepare($sql);
                    if ($stmt !== false) {
                        $now = gmdate('Y-m-d H:i:s') . '.000';
                        if ($stmt->bind_param('si', $now, $slotId)) {
                            $stmt->execute();
                            $exitCode = $stmt->affected_rows === 1 ? 0 : 1;
                            $stmt->close();
                        }
                    }
                } catch (\Throwable $e) {
                    $exitCode = 1;
                }
                if ($mysqli instanceof \mysqli) {
                    @$mysqli->close();
                }
                exit($exitCode);
            }
            $pids[] = $pid;
        }

        $successes = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                $successes++;
            }
        }

        return $successes;
    }

    // ---------- SQL اتمیک (آینه SlotRepository) ----------

    private function atomicHoldSql(): string
    {
        return 'UPDATE ' . $this->table('cpms_schedule_slots') .
            ' SET held_count = held_count + 1, updated_at = ?' .
            ' WHERE id = ? AND is_open = 1 AND capacity - booked_count - held_count > 0';
    }

    private function atomicClaimSql(): string
    {
        return 'UPDATE ' . $this->table('cpms_schedule_slots') .
            ' SET held_count = GREATEST(held_count - 1, 0), booked_count = booked_count + 1, updated_at = ?' .
            ' WHERE id = ? AND held_count > 0';
    }

    private function atomicBookSql(): string
    {
        // آینه SlotRepository::atomicBook — ظرفیت آزاد واقعی (منهای Holdهای فعال)
        return 'UPDATE ' . $this->table('cpms_schedule_slots') .
            ' SET booked_count = booked_count + 1, updated_at = ?' .
            ' WHERE id = ? AND is_open = 1 AND capacity - booked_count - held_count > 0';
    }

    private function releaseHoldSql(): string
    {
        return 'UPDATE ' . $this->table('cpms_schedule_slots') .
            ' SET held_count = GREATEST(held_count - 1, 0), updated_at = ?' .
            ' WHERE id = ? AND held_count > 0';
    }

    // ---------- Helpers ----------

    private function conditionalUpdate(\mysqli $conn, string $sql, int $slotId): bool
    {
        $stmt = $conn->prepare($sql);
        $now = gmdate('Y-m-d H:i:s') . '.000';
        $stmt->bind_param('si', $now, $slotId);
        $stmt->execute();
        $ok = $stmt->affected_rows === 1;
        $stmt->close();

        return $ok;
    }

    /**
     * Open a separate connection using the WordPress Test Suite credentials.
     */
    private function freshMysqli(): \mysqli
    {
        try {
            return $this->connectMysqli();
        } catch (\Throwable) {
            $this->markTestSkipped('Cannot open an independent DB connection.');
            throw new \RuntimeException('Independent DB connection is unavailable.');
        }
    }

    private function connectMysqli(): \mysqli
    {
        $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
        $user = defined('DB_USER') ? DB_USER : 'root';
        $pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
        $name = defined('DB_NAME') ? DB_NAME : 'wordpress_test';

        $mysqli = @new \mysqli($host, $user, $pass, $name);
        if ($mysqli->connect_errno !== 0) {
            throw new \RuntimeException('Cannot open an independent DB connection.');
        }
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    private function assertPendingSentinelIsIsolated(): void
    {
        global $wpdb;
        $parentCount = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_settings WHERE clinic_id = %d AND `key` = %s',
            $this->clinicId,
            $this->sentinelKey
        ));
        self::assertSame(1, $parentCount, 'The pending sentinel must remain visible on the parent transaction.');

        $conn = $this->connectMysqli();
        try {
            self::assertSame(0, $this->countSentinel($conn), 'Independent fixture commits must not persist the parent sentinel.');
        } finally {
            $conn->close();
        }
    }

    /** @return list<string> */
    private function cleanupCommittedFixtures(): array
    {
        if ($this->slotIds === [] && $this->clinicianId <= 0) {
            return [];
        }

        $failures = [];
        $conn     = null;
        try {
            $conn = $this->connectMysqli();
            if (!$conn->begin_transaction()) {
                throw new \RuntimeException('Isolated fixture-cleanup transaction could not start.');
            }
            foreach ($this->slotIds as $slotId) {
                $present = $this->countById($conn, 'cpms_schedule_slots', $slotId);
                if ($present > 0 && ($present !== 1 || $this->deleteById($conn, 'cpms_schedule_slots', $slotId) !== 1)) {
                    $failures[] = 'a tracked slot was not deleted exactly once';
                }
            }
            if ($this->clinicianId > 0) {
                $present = $this->countById($conn, 'cpms_clinicians', $this->clinicianId);
                if ($present > 0 && ($present !== 1 || $this->deleteById($conn, 'cpms_clinicians', $this->clinicianId) !== 1)) {
                    $failures[] = 'the tracked clinician was not deleted exactly once';
                }
            }
            if (!$conn->commit()) {
                $failures[] = 'the isolated fixture-cleanup transaction did not commit';
                $conn->rollback();
            }
        } catch (\Throwable) {
            if ($conn instanceof \mysqli) {
                try {
                    $conn->rollback();
                } catch (\Throwable) {
                    // Continue to durable-absence verification.
                }
            }
            $failures[] = 'isolated fixture cleanup could not complete';
        } finally {
            if ($conn instanceof \mysqli) {
                $conn->close();
            }
        }

        $check = null;
        try {
            $check = $this->connectMysqli();
            foreach ($this->slotIds as $slotId) {
                if ($this->countById($check, 'cpms_schedule_slots', $slotId) !== 0) {
                    $failures[] = 'a tracked slot remains durably present after cleanup';
                }
            }
            if ($this->clinicianId > 0 && $this->countById($check, 'cpms_clinicians', $this->clinicianId) !== 0) {
                $failures[] = 'the tracked clinician remains durably present after cleanup';
            }
        } catch (\Throwable) {
            $failures[] = 'durable fixture-absence verification could not complete';
        } finally {
            if ($check instanceof \mysqli) {
                $check->close();
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function assertPendingSentinelRolledBack(): array
    {
        if ($this->sentinelKey === '') {
            return [];
        }

        $failures = [];
        $conn     = null;
        try {
            $conn  = $this->connectMysqli();
            $count = $this->countSentinel($conn);
            if ($count > 0) {
                if (!$conn->begin_transaction()) {
                    throw new \RuntimeException('Sentinel cleanup transaction could not start.');
                }
                $statement = $conn->prepare(
                    'DELETE FROM ' . $this->table('cpms_settings') . ' WHERE clinic_id = ? AND `key` = ?'
                );
                if ($statement === false) {
                    throw new \RuntimeException('Sentinel cleanup could not be prepared.');
                }
                $clinicId    = $this->clinicId;
                $sentinelKey = $this->sentinelKey;
                $statement->bind_param('is', $clinicId, $sentinelKey);
                $statement->execute();
                $deleted = $statement->affected_rows;
                $statement->close();
                $committed = $conn->commit();

                if (!$committed || $deleted !== $count) {
                    $failures[] = 'an unrelated sentinel survived the parent rollback';
                } else {
                    $failures[] = 'fixture setup committed an unrelated pending write';
                }
                if ($this->countSentinel($conn) !== 0) {
                    $failures[] = 'the unexpected sentinel could not be removed durably';
                }
            }
        } catch (\Throwable) {
            $failures[] = 'the unrelated-write rollback sentinel could not be verified';
        } finally {
            if ($conn instanceof \mysqli) {
                $conn->close();
            }
        }

        return $failures;
    }

    private function countById(\mysqli $conn, string $shortTable, int $id): int
    {
        $statement = $conn->prepare('SELECT COUNT(*) FROM ' . $this->table($shortTable) . ' WHERE id = ?');
        if ($statement === false) {
            throw new \RuntimeException('Fixture row-count query could not be prepared.');
        }
        $statement->bind_param('i', $id);
        $statement->execute();
        $count = 0;
        $statement->bind_result($count);
        $statement->fetch();
        $statement->close();

        return (int) $count;
    }

    private function countSentinel(\mysqli $conn): int
    {
        $statement = $conn->prepare(
            'SELECT COUNT(*) FROM ' . $this->table('cpms_settings') . ' WHERE clinic_id = ? AND `key` = ?'
        );
        if ($statement === false) {
            throw new \RuntimeException('Sentinel row-count query could not be prepared.');
        }
        $clinicId    = $this->clinicId;
        $sentinelKey = $this->sentinelKey;
        $statement->bind_param('is', $clinicId, $sentinelKey);
        $statement->execute();
        $count = 0;
        $statement->bind_result($count);
        $statement->fetch();
        $statement->close();

        return (int) $count;
    }

    private function deleteById(\mysqli $conn, string $shortTable, int $id): int
    {
        $statement = $conn->prepare('DELETE FROM ' . $this->table($shortTable) . ' WHERE id = ?');
        if ($statement === false) {
            throw new \RuntimeException('Fixture deletion could not be prepared.');
        }
        $statement->bind_param('i', $id);
        $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        return $affected;
    }

    private function table(string $short): string
    {
        global $table_prefix;

        return $table_prefix . $short;
    }

    private function assertSlotState(int $slotId, int $booked, int $held): void
    {
        // خواندن با اتصال تازه: تراکنشِ بازِ WP Test Suite روی اتصال wpdb با
        // REPEATABLE READ اسنپ‌شات قدیمی می‌دهد و تغییرات commit‌شده‌ی اتصال‌های
        // مستقل را نمی‌بیند؛ برای شمارنده‌های دقیق باید خارج از آن خواند.
        $conn = $this->freshMysqli();
        $res = $conn->query('SELECT booked_count, held_count FROM ' . $this->table('cpms_schedule_slots') . ' WHERE id = ' . (int) $slotId);
        $row = $res !== false ? $res->fetch_assoc() : null;
        $conn->close();
        $this->assertNotNull($row, 'Slot row must exist');
        $this->assertSame($booked, (int) $row['booked_count'], 'booked_count must be exact');
        $this->assertSame($held, (int) $row['held_count'], 'held_count must be exact');
    }
}
