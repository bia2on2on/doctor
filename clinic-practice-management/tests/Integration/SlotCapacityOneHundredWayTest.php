<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use WP_UnitTestCase;

/**
 * F10 §27/§28 — آزمون پذیرش اجباری: ۱۰۰ راهِ هم‌زمان روی «همان Slot با
 * ظرفیت ۱» روی **مسیر واقعی Production** (SlotRepository::atomicBook —
 * همان Conditional UPDATE + قفل ردیف InnoDB که Booking در Production اجرا
 * می‌کند) با **اتصال‌های مستقل MySQL** (هر child یک اتصال تازه).
 *
 * موفقیت = دقیقاً ۱ برنده (و برای ظرفیت ۳ = دقیقاً ۳) — بدون Overbooking.
 *
 * نکات فنی:
 *  - pcntl_fork + mysqli مستقل در هر child (الگوی VisitConcurrencyTest F4).
 *  - Fixture COMMIT می‌شود تا children ببینند؛ Cleanup دستی (ROLLBACK تست
 *    بعد از COMMIT بی‌اثر است).
 *  - در این sandbox (بدون MySQL/pcntl) اجرا نمی‌شود — CI اجرا می‌کند.
 */
final class SlotCapacityOneHundredWayTest extends WP_UnitTestCase
{
    private int $clinicianId = 0;
    private int $slotId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }
        App::migrations()->migrate();
    }

    public function testOneHundredWaySameSlotCapacityOneAllowsExactlyOneWinner(): void
    {
        $this->makeSlotFixture(capacity: 1);
        $winners = $this->runOneHundredContenders();

        $this->assertSame(1, $winners, 'فقط یک درخواست باید روی ظرفیت ۱ برنده شود');
        $this->assertSame(1, $this->readBookedCount(), 'booked_count نهایی باید ۱ باشد');
        $this->cleanupFixture();
    }

    public function testOneHundredWayCapacityThreeAllowsExactlyThreeWinners(): void
    {
        $this->makeSlotFixture(capacity: 3);
        $winners = $this->runOneHundredContenders();

        $this->assertSame(3, $winners, 'با ظرفیت ۳ فقط ۳ درخواست باید برنده شوند');
        $this->assertSame(3, $this->readBookedCount(), 'booked_count نهایی باید ۳ باشد');
        $this->cleanupFixture();
    }

    // ================= fixture / workers =================

    private function makeSlotFixture(int $capacity): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)',
                'Dr 100-Way F10',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
                 VALUES (1, %d, %s, %s, 15, %d, 0, 0, 1, %s, %s, %s)',
                $this->clinicianId,
                '2099-01-01',
                '09:00:00',
                $capacity,
                'manual',
                $now,
                $now
            )
        );
        $this->slotId = (int) $wpdb->insert_id;

        // COMMIT تا children (اتصال‌های مستقل) ردیف را ببینند
        $wpdb->query('COMMIT');
    }

    private function runOneHundredContenders(): int
    {
        $children = [];
        for ($i = 0; $i < 100; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                // ===== child: اتصال مستقل + مسیر واقعی تولید =====
                usleep(random_int(0, 3000)); // پخش تلاش اتصال (محدودیت max_connections)
                $won = $this->childAtomicBook();
                exit($won ? 1 : 0);
            }
            $children[] = $pid;
        }

        $winners = 0;
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 1) {
                $winners++;
            }
        }

        return $winners;
    }

    private function childAtomicBook(): bool
    {
        try {
            $w = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            global $wpdb;
            $w->set_prefix($wpdb->prefix);
            $cpms = new CpmsDb($w);

            return (new SlotRepository($cpms))->atomicBook($this->slotId);
        } catch (\Throwable) {
            exit(3);
        }
    }

    private function readBookedCount(): int
    {
        global $wpdb;
        $v = $wpdb->get_var(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $this->slotId)
        );

        return (int) $v;
    }

    private function cleanupFixture(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $this->slotId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE id = %d', $this->clinicianId));
        $wpdb->query('COMMIT');
    }
}
