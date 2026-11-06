<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\HoldsExpireHandler;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * F3/B2 (لایه DB) — آزادسازی ظرفیت Holdهای منقضی روی دیتابیس واقعی.
 *
 * تست واحد این منطق را روی یک mirror SQLite می‌سنجد؛ اینجا همان سناریو روی
 * MySQL/MariaDB واقعی اجرا می‌شود تا خودِ عبارت‌ها (`GREATEST`, `held_count > 0`)
 * و تراکنش واقعی هم پوشش داشته باشند.
 *
 * سناریو: Hold منقضی در میانهٔ اجرا توسط نویسندهٔ رقیب convert می‌شود. آن‌گاه
 * UPDATE وضعیت صفر ردیف را تغییر می‌دهد و ظرفیت نباید آزاد شود؛ اجرای دوم هم
 * باید no-op باشد.
 */
final class HoldsExpireCapacityTest extends WP_UnitTestCase
{
    private int $clinicianId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Holds',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;
    }

    public function testExpiredHoldConvertedMidRunDoesNotReleaseCapacityAndSecondRunIsNoOp(): void
    {
        global $wpdb;

        $locationId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d AND is_primary = 1 LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                1
            )
        );
        self::assertGreaterThan(0, $locationId, 'a persisted primary Location is required');

        $now   = App::db()->nowUtcSql();
        $date  = gmdate('Y-m-d', time() + 86400);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %d, %s, %s, 20, 1, 0, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $locationId,
                $this->clinicianId,
                $date,
                '09:00:00',
                $now,
                $now
            )
        );
        $slotId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $slotId, 'slot fixture must persist');

        $token = wp_generate_uuid4();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_slot_holds
                     (clinic_id, slot_id, holder_wp_user_id, token, expires_at, status, created_at)
                 VALUES (1, %d, NULL, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slotId,
                $token,
                gmdate('Y-m-d H:i:s', time() - 60) . '.000',
                'active',
                $now
            )
        );
        $holdId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $holdId, 'hold fixture must persist');

        // Competing writer between the handler's SELECT and its UPDATE: the hold
        // is converted, so the guarded status UPDATE matches zero rows.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_slot_holds SET status = %s WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'converted',
                $holdId
            )
        );

        $handler = new HoldsExpireHandler(App::db());
        self::assertSame(0, $handler([]), 'a converted hold must not be reported as freed');

        $slot = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT held_count, booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slotId
            ),
            ARRAY_A
        );
        self::assertSame(1, (int) $slot['held_count'], 'capacity stays with the converted hold');
        self::assertSame(0, (int) $slot['booked_count'], 'booked counter is untouched');
        self::assertSame(
            'converted',
            (string) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT status FROM ' . $wpdb->prefix . 'cpms_slot_holds WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $holdId
                )
            ),
            'the hold keeps the competing writer state'
        );

        // Second run: nothing left to expire, counters still identical.
        self::assertSame(0, $handler([]), 'a second run is a no-op');
        $slotAfter = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT held_count, booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slotId
            ),
            ARRAY_A
        );
        self::assertSame(1, (int) $slotAfter['held_count'], 'second run does not decrement');
        self::assertSame(0, (int) $slotAfter['booked_count'], 'second run leaves booked_count alone');
    }
}
