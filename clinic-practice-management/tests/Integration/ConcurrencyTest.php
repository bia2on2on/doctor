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
 * مستقل نمی‌توانند ردیف‌های uncommitted را ببینند/قفل کنند. به همین دلیل این تست
 * Fixture را قبل از fork/اتصال commit می‌کند و Cleanup دستی انجام می‌دهد
 * (tearDown ROLLBACK بعد از commit بی‌اثر است).
 *
 * لایه‌بندی پوشش: نتیجه در سطح Service/REST (تلاش دوم → CLINIC_SLOT_TAKEN/409)
 * در BookingFlowTest/RestBookingTest پوشش داده شده است.
 */
final class ConcurrencyTest extends WP_UnitTestCase
{
    private bool $createdClinician = false;
    private int $clinicianId = 0;

    /** @var list<int> */
    private array $slotIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
    }

    protected function tearDown(): void
    {
        // Cleanup دستی — این Fixtureها commit شده‌اند و ROLLBACK tearDown اثری ندارد
        foreach ($this->slotIds as $slotId) {
            App::db()->query('DELETE FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d', [$slotId]);
        }
        if ($this->createdClinician && $this->clinicianId > 0) {
            App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d', [$this->clinicianId]);
        }
        $this->slotIds = [];
        $this->createdClinician = false;

        parent::tearDown();
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

    // ---------- Fixture (committed) ----------

    /**
     * ساخت Slot و Commit تراکنش والد تا اتصال‌های مستقل آن را ببینند.
     */
    private function committedSlot(int $capacity): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        $existing = App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_clinicians') . ' ORDER BY id LIMIT 1'
        );
        if ($existing !== null && (int) $existing > 0) {
            $this->clinicianId = (int) $existing;
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                         (clinic_id, full_name, is_active, created_at, updated_at)
                     VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    'Dr Concurrency Test',
                    $now,
                    $now
                )
            );
            $this->clinicianId = (int) $wpdb->insert_id;
            $this->createdClinician = true;
        }

        // تاریخ/ساعت تصادفی در افق دور — برخورد UNIQUE با داده تست‌های دیگر بعید
        $date = gmdate('Y-m-d', time() + (330 + random_int(0, 60)) * 86400);
        $time = sprintf('%02d:%02d:00', random_int(8, 15), random_int(0, 59));
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, %s, 20, %d, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianId,
                $date,
                $time,
                $capacity,
                $now,
                $now
            )
        );
        $slotId = (int) $wpdb->insert_id;

        // داده Fixture را برای پردازها/اتصال‌های مستقل commit می‌کنیم
        $wpdb->query('COMMIT');

        $this->slotIds[] = $slotId;

        return $slotId;
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
     * اتصال مستقل (شبیه پرداز/Request جدا) — همان Credentialهای Test Suite.
     */
    private function freshMysqli(): \mysqli
    {
        $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
        $user = defined('DB_USER') ? DB_USER : 'root';
        $pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
        $name = defined('DB_NAME') ? DB_NAME : 'wordpress_test';

        $mysqli = @new \mysqli($host, $user, $pass, $name);
        if ($mysqli->connect_errno !== 0) {
            $this->markTestSkipped('Cannot open independent DB connection: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    private function table(string $short): string
    {
        global $table_prefix;

        return $table_prefix . $short;
    }

    private function assertSlotState(int $slotId, int $booked, int $held): void
    {
        $row = App::db()->fetchRow(
            'SELECT booked_count, held_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        );
        $this->assertNotNull($row, 'Slot row must exist');
        $this->assertSame($booked, (int) $row['booked_count'], 'booked_count must be exact');
        $this->assertSame($held, (int) $row['held_count'], 'held_count must be exact');
    }
}
