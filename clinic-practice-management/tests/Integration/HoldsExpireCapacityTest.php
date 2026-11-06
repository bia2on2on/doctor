<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\HoldsExpireHandler;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * F3/B2 (لایه DB) — «پس از convert، ظرفیت آزاد نمی‌شود» روی MySQL/MariaDB واقعی.
 *
 * دامنهٔ دقیق این تست (بدون ادعای بیشتر):
 *  - Hold *پیش از* اجرای handler به `converted` رفته است. این یک واقعیتِ
 *    محتمل در عمل است (Hold در فاصلهٔ بین دو اجرای cron تبدیل شده) و همان
 *    چیزی است که تست می‌سنجد: نبودِ آزادسازی برای Holdی که دیگر active نیست،
 *    و no-op بودن اجرای دوم.
 *  - این تست **مسابقهٔ واقعی** را نمی‌سنجد: نوشتنِ رقیب بین SELECT و UPDATEِ
 *    همان اجرا انجام نمی‌شود. پوشش آن حالت در تست‌های واحد است
 *    (`HoldsExpireHandlerCapacityTest`, هوک `afterNextSelect`).
 *  - این تست روی handler قدیمی (بدون بررسی affected-rows) هم سبز می‌شد، چون
 *    SELECT در همان اجرا ردیفِ converted را برنمی‌گرداند. بنابراین شاهدِ رفعِ
 *    باگ نیست؛ فقط قراردادِ رفتار را روی دیتابیس واقعی تثبیت می‌کند.
 *
 * همهٔ ردیف‌ها (Clinic/Location/Clinician/Slot/Hold) توسط خود تست ساخته و در
 * tearDown حذف می‌شوند؛ به clinic_id = 1 یا Location مشترکِ fixture وابسته نیست.
 */
final class HoldsExpireCapacityTest extends WP_UnitTestCase
{
    private int $clinicId = 0;

    private int $locationId = 0;

    private int $clinicianId = 0;

    /** @var list<int> */
    private array $createdSlotIds = [];

    /** @var list<int> */
    private array $createdHoldIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        global $wpdb;
        $now  = App::db()->nowUtcSql();
        $slug = 'holds-expire-' . wp_generate_password( 8, false, false );

        // Clinic خودِ تست — نه clinic_id = 1.
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (name, slug, timezone, created_at, updated_at)
                 VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Holds Expire Clinic',
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $this->clinicId, 'the test must persist its own Clinic' );

        // Location خودِ تست (is_primary = 0 تا با fixture مشترک تعارض نکند).
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                     (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                'Holds Expire Location',
                $slug . '-loc',
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->locationId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $this->locationId, 'the test must persist its own Location' );

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (%d, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                'Dr Holds',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $this->clinicianId, 'the test must persist its own Clinician' );
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ( $this->createdHoldIds as $holdId ) {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . $wpdb->prefix . 'cpms_slot_holds WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $holdId
                )
            );
        }
        foreach ( $this->createdSlotIds as $slotId ) {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $slotId
                )
            );
        }
        if ( $this->clinicianId > 0 ) {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $this->clinicianId
                )
            );
        }
        if ( $this->locationId > 0 ) {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . $wpdb->prefix . 'cpms_locations WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $this->locationId
                )
            );
        }
        if ( $this->clinicId > 0 ) {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $this->clinicId
                )
            );
        }

        parent::tearDown();
    }

    /**
     * قرارداد: Holdی که پیش از اجرا به converted رفته، ظرفیت را آزاد نمی‌کند و
     * اجرای دوم هیچ تغییری نمی‌دهد. (نه اثبات رفع باگ، نه پوشش مسابقه.)
     */
    public function testHoldAlreadyConvertedBeforeRunReleasesNothingAndSecondRunIsNoOp(): void
    {
        global $wpdb;

        $now  = App::db()->nowUtcSql();
        $date = gmdate( 'Y-m-d', time() + 86400 );

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $this->locationId,
                $this->clinicianId,
                $date,
                '09:00:00',
                $now,
                $now
            )
        );
        $slotId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $slotId, 'slot must persist' );
        $this->createdSlotIds[] = $slotId;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_slot_holds
                     (clinic_id, slot_id, holder_wp_user_id, token, expires_at, status, created_at)
                 VALUES (%d, %d, NULL, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $slotId,
                wp_generate_uuid4(),
                gmdate( 'Y-m-d H:i:s', time() - 60 ) . '.000',
                'active',
                $now
            )
        );
        $holdId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $holdId, 'hold must persist' );
        $this->createdHoldIds[] = $holdId;

        // Conversion happens BEFORE the handler runs: the handler's SELECT will
        // not even return this row. That is the honest scope of this test.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_slot_holds SET status = %s WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'converted',
                $holdId
            )
        );

        $handler = new HoldsExpireHandler( App::db() );

        self::assertSame( 0, $handler( [] ), 'a hold that is no longer active must not be reported as freed' );

        $slot = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT held_count, booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slotId
            ),
            ARRAY_A
        );
        self::assertSame( 1, (int) $slot['held_count'], 'capacity stays with the converted hold' );
        self::assertSame( 0, (int) $slot['booked_count'], 'booked counter is untouched' );
        self::assertSame(
            'converted',
            (string) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT status FROM ' . $wpdb->prefix . 'cpms_slot_holds WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $holdId
                )
            ),
            'the hold keeps its converted status'
        );

        self::assertSame( 0, $handler( [] ), 'a second run is a no-op' );
        $slotAfter = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT held_count, booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slotId
            ),
            ARRAY_A
        );
        self::assertSame( 1, (int) $slotAfter['held_count'], 'second run does not decrement' );
        self::assertSame( 0, (int) $slotAfter['booked_count'], 'second run leaves booked_count alone' );
    }
}
