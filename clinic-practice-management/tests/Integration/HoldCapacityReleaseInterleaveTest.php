<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\HoldsExpireHandler;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use WP_UnitTestCase;

/**
 * نگهداری ظرفیت Hold — دو مسیر release در برابر worker انقضا (holds.expire).
 *
 * دامنهٔ دقیق این فایل (بدون ادعای بیشتر):
 *  - مسیر resume (B6) و مسیر confirm (B2) روی Holdی که worker انقضا آن را
 *    عمیقاً transition داده است.
 *  - دو شیوهٔ بازسازی:
 *      (۱) متوالی: worker *پیش از* فراخوانی سرویس اجرا و commit می‌شود؛
 *      (۲) درهم‌تنیده (interleave): worker دقیقاً *میان* خواندنِ سرویس و
 *          نوشتنِ transition آن اجرا می‌شود — با یک hook تست‌محور روی فیلتر
 *          `query` وردپرس که لحظهٔ پیش از اجرای همان UPDATE را تشخیص می‌دهد.
 *          این دقیقاً همان «واقعی‌ترین بازسازی قطعیِ» موجود در harness است:
 *          همان‌جایی که transition سرویس قرار است اجرا شود، worker واقعیِ
 *          production (HoldsExpireHandler) روی همان اتصال و درون transaction
 *          بازِ تست (با SAVEPOINT) اجرا می‌شود؛ transition worker روی همان
 *          اتصال برای statement بعدی سرویس دیده می‌شود و متن SQL سرویس
 *          دست‌نخورده می‌ماند تا خودش اجرا شود. توجه: در این شیوه، COMMITِ
 *          واقعیِ worker هم‌مرزِ COMMITِ بیرونیِ سرویس است (SAVEPOINT تودرتو)،
 *          نه مرزی پیش از آن؛ بنابراین این تست اثباتِ قرارداد affected-rows
 *          روی یک اتصال است، نه ایزولاسیون InnoDB میان دو اتصال.
 *  - تست تبدیل (conversion): خطای واقعی درایور روی UPDATEِ
 *    `status = 'converted'` تزریق می‌شود؛ انتظار: rollback کامل confirm
 *    (appointments = 0) و آزادسازی جبرانیِ Hold دقیقاً یک‌بار.
 *
 * آنچه این فایل اثبات *نمی‌کند* (صریح):
 *  - ایزولاسیون InnoDB میان دو اتصال در سطح سرویس (هیچ تستی در این فایل
 *    برای این ادعا نیست). آنچه اینجا سنجیده می‌شود قراردادِ affected-rows
 *    روی همان اتصال و همان transaction است: برنده شدن/نبردنِ هر transition.
 *  - رفتار دوهم‌زمانی زیر بار واقعی (آن سناریوها در ConcurrencyTest /
 *    SlotClaimTest با آینهٔ SQL سنجیده شده‌اند، نه با سرویس production).
 *  - هیچ‌کدام از تست‌های این فایل خودبه‌خود سبز بودن سایر مسیرها را ثابت
 *    نمی‌کند؛ هر ادعا صرفاً در حدِ assertions همین فایل است.
 *
 * همهٔ ردیف‌ها (Clinic/Location/Clinician/Slot/Hold/User/Patient/Link) توسط
 * خود تست ساخته و در tearDown حذف می‌شوند؛ WP_UnitTestCase کل تست را در یک
 * transaction بیرونی نگه می‌دارد و ROLLBACK تستی همهٔ باقی‌مانده‌ها را
 * پاک می‌کند (هیچ COMMIT میان تست نیست).
 */
final class HoldCapacityReleaseInterleaveTest extends WP_UnitTestCase
{
    private int $clinicId = 0;

    private int $locationId = 0;

    private int $clinicianId = 0;

    /** @var list<int> */
    private array $createdSlotIds = [];

    /** @var list<int> */
    private array $createdHoldIds = [];

    /** @var list<int> */
    private array $createdPatientIds = [];

    /** @var list<int> */
    private array $createdUserIds = [];

    private int $userAId = 0;

    private int $userBId = 0;

    /** @var callable|null test-only one-shot interposer registered by a test */
    private $interposer = null;

    private int $interposerFirings = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('booking.hold_ttl_sec', 600);

        global $wpdb;
        $now  = App::db()->nowUtcSql();
        $slug = 'hold-interleave-' . wp_generate_password( 8, false, false );

        // Clinics belong to an Organization (FK, migration 0010). Reuse the
        // seeded default Organization when present, else create one.
        $orgId = (int) $wpdb->get_var( 'SELECT id FROM ' . $wpdb->prefix . 'cpms_organizations ORDER BY id LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( $orgId <= 0 ) {
            $this->mustQuery(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
                     VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    'Hold Interleave Org',
                    $slug . '-org',
                    'active',
                    $now,
                    $now
                )
            );
            $orgId = (int) $wpdb->insert_id;
        }

        $this->mustQuery(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $orgId,
                'Hold Interleave Clinic',
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $this->clinicId, 'the test must persist its own Clinic' );

        $this->mustQuery(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                     (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                'Hold Interleave Location',
                $slug . '-loc',
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->locationId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $this->locationId, 'the test must persist its own Location' );

        $this->mustQuery(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (%d, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                'Dr Hold Interleave',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $this->clinicianId, 'the test must persist its own Clinician' );

        // Two patients + links, for confirm's frozen-subject revalidation.
        $patientA = $this->insertPatient( $slug . 'a', $now );
        $patientB = $this->insertPatient( $slug . 'b', $now );

        $this->userAId = (int) wp_create_user( 'hold_int_a_' . wp_generate_password( 6, false, false ), 'pass-a-12345', 'hold-int-a@test.local', [ 'role' => 'cpms_patient' ] );
        $this->userBId = (int) wp_create_user( 'hold_int_b_' . wp_generate_password( 6, false, false ), 'pass-b-12345', 'hold-int-b@test.local', [ 'role' => 'cpms_patient' ] );
        self::assertGreaterThan( 0, $this->userAId, 'user A must persist' );
        self::assertGreaterThan( 0, $this->userBId, 'user B must persist' );
        $this->createdUserIds[] = $this->userAId;
        $this->createdUserIds[] = $this->userBId;

        $this->mustQuery(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (%d, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $patientA,
                $this->userAId,
                '09120000011',
                $now
            )
        );
        $this->mustQuery(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (%d, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $patientB,
                $this->userBId,
                '09120000012',
                $now
            )
        );

        // The patient session A authenticates confirm/resume with. The interleave
        // runs inside one PHPUnit process on the parent's connection, so the
        // cookie + in-process binding resolve without any seeded tokens elsewhere.
        cpms_test_seed_patient_session( $this->userAId, $this->clinicId );

        // Slot with capacity 2; two active holds (A and B) => held_count = 2.
        $date = gmdate( 'Y-m-d', time() + 3 * 86400 );
        $this->mustQuery(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (%d, %d, %d, %s, %s, 20, 2, 0, 2, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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

        $this->insertHold( $slotId, $this->userAId, 'a', $now );
        $this->insertHold( $slotId, $this->userBId, 'b', $now );
    }

    protected function tearDown(): void
    {
        // Never leave a test-only interposer registered for the next test.
        if ( is_callable( $this->interposer ) ) {
            remove_filter( 'query', $this->interposer, 11 );
            $this->interposer = null;
        }

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
        foreach ( $this->createdPatientIds as $patientId ) {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . $wpdb->prefix . 'cpms_patient_user_links WHERE patient_id = %d AND clinic_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $patientId,
                    $this->clinicId
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . $wpdb->prefix . 'cpms_patients WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $patientId
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
        foreach ( $this->createdUserIds as $userId ) {
            wp_delete_user( $userId );
        }

        parent::tearDown();
    }

    // ---------- resume ----------

    /**
     * worker پیش از resume اجرا می‌شود (نه درهم‌تنیده): Hold A منقضی شده و
     * ظرفیتش آزاد شده است. B Hold فعال همان اسلات را نگه می‌دارد.
     * resume روی Hold A نباید ظرفیت B را دوباره آزاد کند.
     *
     * روی main (پیش از fix) قرمز است: UPDATE انتقال ۰ ردیف را نادیده می‌گیرد
     * و released unconditionally اتفاق می‌افتد — held از ۱ به ۰ می‌رود (ظرفیت B).
     */
    public function testResumeAfterExpiryWorkerKeepsOtherActiveHoldCapacity(): void
    {
        global $wpdb;
        $holdA = $this->holdIdFor( 'a' );
        $slotId = $this->slotId();
        $this->expireHold( $holdA );

        // worker (production code) transitions A: active -> expired, held 2 -> 1.
        $freed = ( new HoldsExpireHandler( App::db() ) )( [] );
        self::assertSame( 1, $freed, 'worker must free exactly the expired hold' );
        self::assertSame( 1, $this->heldCount( $slotId ), 'after the worker, only B capacity remains held' );

        try {
            App::bookingService()->resume( $this->holdTokenFor( 'a' ), $this->userAId );
            self::fail( 'an expired hold must not resume' );
        } catch ( BookingException $e ) {
            self::assertSame( 'CLINIC_HOLD_EXPIRED', $e->errorCode );
            self::assertSame( 422, $e->httpStatus );
        }

        self::assertSame(
            1,
            $this->heldCount( $slotId ),
            'the already-released hold A must not release B active capacity again'
        );
        self::assertSame( 'expired', $this->holdStatus( $holdA ) );
        self::assertSame( 0, $this->bookedCount( $slotId ) );
    }

    /**
     * همان سناریو، اما worker در نقطهٔ دقیقِ بین read و writeِ resume اجرا
     * می‌شود (hook روی فیلتر query — see file docblock).
     *
     * روی main قرمز است: transition UPDATE صفر ردیف اثر می‌دهد ولی released
     * بدون قید اجرا می‌شود — held از ۱ به ۰ می‌رود.
     */
    public function testResumeInterleavedWithExpiryWorkerKeepsOtherActiveHoldCapacity(): void
    {
        $slotId = $this->slotId();
        $holdA  = $this->holdIdFor( 'a' );
        $this->expireHold( $holdA );
        $this->registerExpiryInterposer();

        try {
            App::bookingService()->resume( $this->holdTokenFor( 'a' ), $this->userAId );
            self::fail( 'an expired hold must not resume' );
        } catch ( BookingException $e ) {
            self::assertSame( 'CLINIC_HOLD_EXPIRED', $e->errorCode );
        }

        self::assertSame( 1, $this->interposerFirings, 'the interposer must have fired exactly at the transition write' );
        self::assertSame(
            1,
            $this->heldCount( $slotId ),
            'worker released the hold inside the read/write window; resume must not release it again'
        );
        self::assertSame( 'expired', $this->holdStatus( $holdA ) );
    }

    // ---------- confirm ----------

    /**
     * کارگر پیش از confirm اجرا شده است. confirm Hold A را در دروازهٔ اول
     * (status != active) رد می‌کند و هرگز به مسیر release نمی‌رسد.
     *
     * این تست روی main هم سبز است — با عنوان «خصوصیت‌گرایی» لینت شده است؛
     * شاهد قرمزِ confirm در تست interleaved بعدی است.
     */
    public function testConfirmAfterExpiryWorkerIsDeniedWithoutReleasingCapacity(): void
    {
        $slotId = $this->slotId();
        $holdA  = $this->holdIdFor( 'a' );
        $this->expireHold( $holdA );

        $freed = ( new HoldsExpireHandler( App::db() ) )( [] );
        self::assertSame( 1, $freed );
        self::assertSame( 1, $this->heldCount( $slotId ) );

        try {
            App::bookingService()->confirm( $this->holdTokenFor( 'a' ), $this->userAId, null, $this->uuid() );
            self::fail( 'an expired hold must not confirm' );
        } catch ( BookingException $e ) {
            self::assertSame( 'CLINIC_HOLD_EXPIRED', $e->errorCode );
        }

        self::assertSame(
            1,
            $this->heldCount( $slotId ),
            'the first gate must not release capacity for an already-converted/expired hold'
        );
        self::assertSame( 0, $this->appointmentsForSlot( $slotId ) );
    }

    /**
     * worker دقیقاً میان read و writeِ شاخهٔ انقضای confirm اجرا می‌شود
     * (Hold A هنوز active و منقضی‌شده دیده می‌شود؛ worker آن را expired
     * می‌کند پیش از آنکه UPDATE انتقال confirm اجرا شود).
     *
     * روی main قرمز است: UPDATE انتقال صفر ردیف اثر می‌دهد ولی release_hold
     * بدون قید اجرا می‌شود — held از ۱ به ۰ می‌رود (ظرفیت B دزدیده می‌شود).
     */
    public function testConfirmInterleavedWithExpiryWorkerKeepsOtherActiveHoldCapacity(): void
    {
        $slotId = $this->slotId();
        $holdA  = $this->holdIdFor( 'a' );
        $this->expireHold( $holdA );
        $this->registerExpiryInterposer();

        try {
            App::bookingService()->confirm( $this->holdTokenFor( 'a' ), $this->userAId, null, $this->uuid() );
            self::fail( 'an expired hold must not confirm' );
        } catch ( BookingException $e ) {
            self::assertSame( 'CLINIC_HOLD_EXPIRED', $e->errorCode );
            self::assertSame( 422, $e->httpStatus );
        }

        self::assertSame( 1, $this->interposerFirings, 'the interposer must have fired exactly at the transition write' );
        self::assertSame(
            1,
            $this->heldCount( $slotId ),
            'worker released the hold inside the read/write window; confirm must not release it again'
        );
        self::assertSame( 'expired', $this->holdStatus( $holdA ) );
        self::assertSame( 0, $this->appointmentsForSlot( $slotId ) );
    }

    /**
     * خطای واقعیِ درایور روی UPDATEِ `status = 'converted'` داخل transaction
     * confirm تزریق می‌شود (جدول ناموجود — wpdb->query مقدار false و
     * last_error می‌دهد). انتظار پس از fix: rollback کامل (appointment = 0)
     * و جبران جبرانیِ Hold دقیقاً یک‌بار.
     *
     * روی main قرمز است: نرخ صفرِ converted UPDATE نادیده گرفته می‌شود و
     * appointment با Holdِ همچنان active committed می‌شود (assertappointments==0
     * می‌شکند).
     */
    public function testConfirmConversionWriteFailureRollsBackAppointmentAndCompensatesHold(): void
    {
        $slotId = $this->slotId();
        $holdA  = $this->holdIdFor( 'a' );
        // A stays active with a future expiry: confirm proceeds into the transaction.
        self::assertSame( 'active', $this->holdStatus( $holdA ) );

        $this->registerConversionBreaker();

        try {
            App::bookingService()->confirm( $this->holdTokenFor( 'a' ), $this->userAId, null, $this->uuid() );
            self::fail( 'a driver failure on the conversion write must fail the confirm' );
        } catch ( BookingException $e ) {
            self::assertSame(
                'CLINIC_INTERNAL_ERROR',
                $e->errorCode,
                'a real DB error surfaces with the existing generic contract, not a new code'
            );
            self::assertSame( 500, $e->httpStatus );
        }

        self::assertSame( 1, $this->interposerFirings, 'the breaker must have fired exactly at the conversion write' );
        self::assertSame( 0, $this->appointmentsForSlot( $slotId ), 'the failed conversion must roll the appointment back' );
        self::assertSame(
            'released',
            $this->holdStatus( $holdA ),
            'the compensation releases the hold exactly once after the rollback'
        );
        self::assertSame( 1, $this->heldCount( $slotId ), 'B active capacity is intact; A released exactly once' );
        self::assertSame( 0, $this->bookedCount( $slotId ), 'no booked capacity may be stranded by the rollback' );
    }

    // ---------- helpers ----------

    private function uuid(): string
    {
        $data    = random_bytes( 16 );
        $data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
        $data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

        return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
    }

    private function mustQuery( string $sql ): void
    {
        global $wpdb;
        $ok = $wpdb->query( $sql );
        if ( $ok === false ) {
            self::fail( 'fixture query failed: ' . ( $wpdb->last_error !== '' ? $wpdb->last_error : 'wpdb returned false without an error message' ) . ' | SQL: ' . $sql );
        }
    }

    /**
     * Fixture query failures must fail loudly at the statement that broke, not
     * at a later unrelated assertion.
     */
    private function insertPatient( string $tag, string $now ): int
    {
        global $wpdb;
        $this->mustQuery(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                'MR-INT-' . strtoupper( substr( $tag, -6 ) ) . '-' . wp_generate_password( 4, false, false ),
                'Interleave',
                'Patient',
                '0912' . str_pad( (string) ( abs( crc32( $tag ) ) % 10000000 ), 7, '0', STR_PAD_LEFT ),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $patientId, 'patient must persist' );
        $this->createdPatientIds[] = $patientId;

        return $patientId;
    }

    private function insertHold( int $slotId, int $holderUserId, string $tag, string $now ): void
    {
        global $wpdb;
        $expiry = gmdate( 'Y-m-d H:i:s', time() + 3600 ) . '.000';
        $this->mustQuery(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_slot_holds
                     (clinic_id, slot_id, holder_wp_user_id, token, expires_at, status, created_at)
                 VALUES (%d, %d, %d, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $slotId,
                $holderUserId,
                'tok-' . $tag . '-' . bin2hex( random_bytes( 6 ) ),
                $expiry,
                'active',
                $now
            )
        );
        $holdId = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $holdId, 'hold must persist' );
        $this->createdHoldIds[] = $holdId;
    }

    /** @return list<int> created hold ids in insertion order (a, b) */
    private function holdIds(): array
    {
        self::assertCount( 2, $this->createdHoldIds, 'exactly two holds (A, B) are seeded' );

        return $this->createdHoldIds;
    }

    private function holdIdFor( string $tag ): int
    {
        [ $a, $b ] = $this->holdIds();

        return $tag === 'a' ? $a : $b;
    }

    private function holdTokenFor( string $tag ): string
    {
        global $wpdb;
        $holdId = $this->holdIdFor( $tag );
        $token  = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT token FROM ' . $wpdb->prefix . 'cpms_slot_holds WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $holdId
            )
        );
        self::assertNotSame( '', $token, 'the seeded hold token must resolve' );

        return $token;
    }

    private function slotId(): int
    {
        self::assertArrayHasKey( 0, $this->createdSlotIds );

        return $this->createdSlotIds[0];
    }

    private function expireHold( int $holdId ): void
    {
        global $wpdb;
        $this->mustQuery(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_slot_holds SET expires_at = %s WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                gmdate( 'Y-m-d H:i:s', time() - 60 ) . '.000',
                $holdId
            )
        );
    }

    private function heldCount( int $slotId ): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slotId
            )
        );
    }

    private function bookedCount( int $slotId ): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slotId
            )
        );
    }

    private function holdStatus( int $holdId ): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . $wpdb->prefix . 'cpms_slot_holds WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $holdId
            )
        );
    }

    private function appointmentsForSlot( int $slotId ): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_appointments WHERE slot_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $slotId
            )
        );
    }

    /**
     * One-shot, test-only interposer on the WordPress `query` filter. When the
     * production code is about to run its expiry-transition UPDATE on the holds
     * table (the statement that follows its read), the interposer first runs the
     * real production worker inline — HoldsExpireHandler on the same connection,
     * nested inside the open service transaction via a CpmsDb SAVEPOINT.
     * The worker's transition therefore becomes visible to the service's own
     * statement on the same connection before it executes (the nested worker's
     * COMMIT coincides with the outer transaction's — see the file docblock).
     * The statement itself is returned unchanged.
     */
    private function registerExpiryInterposer(): void
    {
        $this->interposerFirings = 0;
        $this->interposer      = function ( string $query ): string {
            if ( $this->interposerFirings > 0 ) {
                return $query;
            }
            if (
                stripos( $query, 'cpms_slot_holds' ) !== false
                && stripos( $query, "status = 'expired'" ) !== false
            ) {
                ++$this->interposerFirings;
                remove_filter( 'query', $this->interposer, 11 );
                ( new HoldsExpireHandler( App::db() ) )( [] );
            }

            return $query;
        };
        add_filter( 'query', $this->interposer, 11 );
    }

    /**
     * One-shot, test-only breaker on the `query` filter. When the production
     * conversion write (`status = 'converted'`) is about to run, the statement
     * is rewritten to reference a guaranteed-missing table, so wpdb::query()
     * fails with a real driver error (false + last_error), exactly like the
     * soft-mode failures the production code must detect.
     */
    private function registerConversionBreaker(): void
    {
        $this->interposerFirings = 0;
        $this->interposer      = function ( string $query ): string {
            if ( $this->interposerFirings > 0 ) {
                return $query;
            }
            if (
                stripos( $query, 'cpms_slot_holds' ) !== false
                && stripos( $query, "status = 'converted'" ) !== false
            ) {
                ++$this->interposerFirings;
                remove_filter( 'query', $this->interposer, 11 );
                return str_ireplace( 'cpms_slot_holds', 'cpms_slot_holds__missing_for_interleave_test', $query );
            }

            return $query;
        };
        add_filter( 'query', $this->interposer, 11 );
    }
}
