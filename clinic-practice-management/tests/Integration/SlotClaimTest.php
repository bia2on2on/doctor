<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * TP-03 (لایه DB) — تضمین ضد Double-Booking در سطح دیتابیس (ADR-0004).
 *
 * منطق کامل Hold/Claim در F3 (Booking Service)؛ اینجا تضمین DB را تست می‌کنیم:
 * 1) Claim اتمیک با ظرفیت 1: دعوای دوم باید 0 اثر بگذارد.
 * 2) Convert Hold→Booked: شمارنده‌ها دقیق می‌مانند.
 */
final class SlotClaimTest extends WP_UnitTestCase
{
    private int $slotId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        global $wpdb;

        // Clinician + Slot تست
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Test',
                $now,
                $now
            )
        );
        $clinicianId = (int) $wpdb->insert_id;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicianId,
                '2026-10-01',
                '09:00:00',
                $now,
                $now
            )
        );
        $this->slotId = (int) $wpdb->insert_id;
    }

    /**
     * Claim اتمیک — همان SQL که Booking Service استفاده می‌کند.
     */
    private function atomicClaim(): int
    {
        global $wpdb;

        return (int) $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_schedule_slots
                 SET held_count = held_count + 1
                 WHERE id = %d AND capacity - booked_count - held_count > 0', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->slotId
            )
        );
    }

    public function testSecondClaimOnCapacityOneFails(): void
    {
        $this->assertSame(1, $this->atomicClaim(), 'دعوای اول باید موفق باشد');
        $this->assertSame(0, $this->atomicClaim(), 'دعوای دوم باید CLINIC_SLOT_TAKEN باشد (0 row)');

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT capacity, booked_count, held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->slotId
            ),
            ARRAY_A
        );
        $this->assertSame(1, (int) $row['capacity']);
        $this->assertSame(0, (int) $row['booked_count']);
        $this->assertSame(1, (int) $row['held_count'], 'ظرفیت باید دقیقاً 1 باشد — نه 2');
    }

    public function testConvertHoldToBookedKeepsCapacity(): void
    {
        $this->assertSame(1, $this->atomicClaim());

        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_schedule_slots
                 SET held_count = GREATEST(held_count - 1, 0), booked_count = booked_count + 1
                 WHERE id = %d AND held_count > 0', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->slotId
            )
        );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT booked_count, held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->slotId
            ),
            ARRAY_A
        );
        $this->assertSame(1, (int) $row['booked_count']);
        $this->assertSame(0, (int) $row['held_count']);
    }

    public function testSlotUniqueConstraintPreventsDuplicates(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $clinicianId = (int) $wpdb->get_var(
            'SELECT clinician_id FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = ' . (int) $this->slotId // phpcs:ignore
        );

        $duplicate = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, %s, 20, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicianId,
                '2026-10-01',
                '09:00:00',
                $now,
                $now
            )
        );
        $this->assertSame(0, (int) $duplicate, 'K-2: Slot تکراری ساخته نشود (Idempotent generation)');

        $count = $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = ' . (int) $this->slotId // phpcs:ignore
        );
        $this->assertSame(1, (int) $count);
    }
}
