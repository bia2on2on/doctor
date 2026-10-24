<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * TP-03/TP-20 — جریان کامل رزرو (F3):
 * quote → hold → confirm (+Idempotency) → mine/cancel/reschedule + staff create.
 *
 * تضمین‌های کلیدی که اینجا تست می‌شوند:
 *  - Double-Booking: شمارنده‌ها هرگز ظرفیت را رد نمی‌کنند (DB-level).
 *  - Idempotent Replay: تکرار confirm با همان کلید = پاسخ Origin (بدون Appointment دوم).
 *  - Hold Expiry: آزادسازی ظرفیت + خطای CLINIC_HOLD_EXPIRED.
 *  - Duration Snapshot: تغییر Default بعد از رزرو، نوبت موجود را تغییر نمی‌دهد (TP-21).
 *  - Policy لغو: داخل Window = CLINIC_POLICY_VIOLATION.
 *  - Patient Auto-Creation برای کاربر جدید (OTP mobile).
 *  - Transaction Rollback: شکست Confirm → ظرفیت آزاد می‌شود.
 */
final class BookingFlowTest extends WP_UnitTestCase
{
    private int $clinicianId;
    private int $patientUserId;
    private int $otherUserId;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('booking.hold_ttl_sec', 600);
        App::settings()->set('booking.cancel_deadline_hours', 24);
        App::settings()->set('booking.reschedule_deadline_hours', 24);

        global $wpdb;
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
        $this->clinicianId = (int) $wpdb->insert_id;

        // بیمار + کاربر
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Test", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-TEST-0001',
                '09120000001',
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;

        $this->patientUserId = (int) wp_create_user('patient_1', 'pass-12345', 'p1@test.local', ['role' => 'cpms_patient']);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (1, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $patientId,
                $this->patientUserId,
                '09120000001',
                $now
            )
        );

        // کاربر دوم (بدون Patient — برای تست Auto-Creation)
        $this->otherUserId = (int) wp_create_user('patient_2', 'pass-12345', '09120000002@otp.cpms.local', ['role' => 'cpms_patient']);
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        App::resetScope();
        parent::tearDown();
    }

    /**
     * @return array{id: int, date: string, time: string, location_id: int}
     */
    private function makeSlot(int $dayOffset, string $time, int $capacity = 1, int $duration = 20, ?int $locationId = null): array
    {
        global $wpdb;
        if ($locationId === null) {
            $locationId = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations'
                    . ' WHERE clinic_id = %d AND is_primary = 1 LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    1
                )
            );
        }
        $this->assertGreaterThan(0, $locationId, 'A persisted Location is required for every booking Slot.');
        $locationClinicId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT clinic_id FROM ' . $wpdb->prefix . 'cpms_locations WHERE id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $locationId
            )
        );
        $this->assertSame(1, $locationClinicId, 'The booking Slot Location must belong to Clinic 1.');
        $date = gmdate('Y-m-d', time() + $dayOffset * 86400);
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %d, %s, %s, %d, %d, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $locationId,
                $this->clinicianId,
                $date,
                $time,
                $duration,
                $capacity,
                $now,
                $now
            )
        );
        $this->assertGreaterThan(0, (int) $wpdb->insert_id, 'A test slot must persist.');

        return ['id' => (int) $wpdb->insert_id, 'date' => $date, 'time' => $time, 'location_id' => $locationId];
    }

    private function makeSecondaryLocation(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'cpms_locations',
            [
                'clinic_id' => 1,
                'name' => 'Booking lifecycle ' . bin2hex(random_bytes(3)),
                'slug' => 'booking-lifecycle-' . bin2hex(random_bytes(4)),
                'timezone' => 'Asia/Tehran',
                'is_primary' => 0,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );
        $this->assertNotFalse($inserted, 'The secondary test Location must persist.');
        $locationId = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $locationId, 'The secondary test Location needs a persisted id.');

        return $locationId;
    }

    private function deactivateLocation(int $locationId): void
    {
        global $wpdb;
        $changed = $wpdb->update(
            $wpdb->prefix . 'cpms_locations',
            ['is_active' => 0, 'updated_at' => App::db()->nowUtcSql()],
            ['id' => $locationId, 'clinic_id' => 1],
            ['%d', '%s'],
            ['%d', '%d']
        );
        $this->assertSame(1, $changed, 'The test Location must transition to inactive.');
    }

    private function booking()
    {
        return App::bookingService();
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ---------- A4 ----------

    public function testQuoteShowsCapacity(): void
    {
        $slot = $this->makeSlot(3, '10:00', 2);
        $result = $this->booking()->quote($this->clinicianId, $slot['date'], $slot['time']);
        $this->assertTrue($result['available']);
        $this->assertSame(2, $result['capacity_left']);

        // نوبت در گذشته = خارج Window
        $this->expectException(BookingException::class);
        $this->booking()->quote($this->clinicianId, gmdate('Y-m-d', time() - 86400), '10:00');
    }

    // ---------- B1/B2 ----------

    public function testReferenceUniqueIndexCollisionRetriesInsideAppointmentInsert(): void
    {
        $slot = $this->makeSlot(5, '10:00', 1, 20);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $first = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, 'اول', $this->uuid());
        $existing = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$first['appointment_id']]
        );
        self::assertNotNull($existing);
        $repo = new \ClinicCore\Infrastructure\Repository\AppointmentRepository(App::db());
        $inserted = App::db()->transactional(static function () use ($repo, $existing): int {
            return $repo->create($existing);
        });
        $reference = App::db()->fetchValue(
            'SELECT reference_code FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$inserted]
        );
        self::assertNotSame($first['reference_code'], $reference);
        self::assertSame(
            (int) substr((string) $first['reference_code'], -2) + 1,
            (int) substr((string) $reference, -2)
        );
    }

    public function testReferenceSequenceAdvancesPastGapAndLegacySuffix(): void
    {
        $firstSlot = $this->makeSlot(4, '10:00', 1, 20);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $firstSlot['date'], $firstSlot['time']);
        $first = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, 'اول', $this->uuid());
        $prefix = 'AP-' . str_replace('-', '', $firstSlot['date']) . '-';
        global $wpdb;
        foreach ([$prefix . '07', $prefix . 'LEG'] as $reference) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                 (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, booked_at, confirmed_at, created_at, updated_at)
                 SELECT clinic_id, location_id, %s, patient_id, clinician_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, booked_at, confirmed_at, created_at, updated_at
                 FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d',
                $reference,
                $first['appointment_id']
            ));
        }
        $secondSlot = $this->makeSlot(4, '11:00', 1, 20);
        $secondHold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $secondSlot['date'], $secondSlot['time']);
        $second = $this->booking()->confirm($secondHold['hold_token'], $this->patientUserId, 'دوم', $this->uuid());
        self::assertSame($prefix . '08', $second['reference_code']);
    }

    public function testHoldThenConfirmFullFlow(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1, 25);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $this->assertNotEmpty($hold['hold_token']);
        $this->assertSame(25, $hold['slot']['duration_min']);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(1, (int) $row['held_count']);

        $key = $this->uuid();
        $result = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, 'ویزیت عمومی', $key);
        $this->assertNotEmpty($result['reference_code']);
        $this->assertSame('confirmed', $result['status']);
        $this->assertSame(25, $result['duration_min'], 'Duration باید Snapshot شود (25، نه Default کلینیک)');

        $slotRow = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count, held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(1, (int) $slotRow['booked_count']);
        $this->assertSame(0, (int) $slotRow['held_count']);

        $appt = $wpdb->get_row(
            $wpdb->prepare('SELECT duration_min, slot_end_time, status FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', (int) $result['appointment_id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(25, (int) $appt['duration_min']);
        $this->assertSame('10:25:00', $appt['slot_end_time']);
    }

    public function testDoubleBookingPrevented(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        // Hold دوم (بیمار دیگر / کاربر دوم) باید SLOT_TAKEN باشد
        $this->expectException(BookingException::class);
        try {
            $this->booking()->hold($this->otherUserId, $this->clinicianId, $slot['date'], $slot['time']);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_SLOT_TAKEN', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
            throw $e;
        }
    }

    public function testConfirmIdempotentReplay(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $key = $this->uuid();

        $first = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $key);
        $second = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $key);

        $this->assertSame($first['reference_code'], $second['reference_code']);
        $this->assertSame($first['appointment_id'], $second['appointment_id']);

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_appointments WHERE slot_id = %d', $slot['id']) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $this->assertSame(1, $count, 'Replay نباید Appointment دوم بسازد');

        $slotRow = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(1, (int) $slotRow['booked_count']);
    }

    public function testConfirmWithoutIdempotencyKeyRejected(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        $this->expectException(BookingException::class);
        try {
            $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, null);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }

    public function testHoldExpiredReleasesCapacity(): void
    {
        global $wpdb;
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_slot_holds SET expires_at = %s WHERE token = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                gmdate('Y-m-d H:i:s', time() - 10) . '.000',
                $hold['hold_token']
            )
        );

        $this->expectException(BookingException::class);
        try {
            $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_HOLD_EXPIRED', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
            throw $e;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT held_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(0, (int) $row['held_count'], 'ظرفیت Hold منقضی باید آزاد شود');
    }

    public function testConfirmOwnershipEnforced(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        $this->expectException(BookingException::class);
        try {
            $this->booking()->confirm($hold['hold_token'], $this->otherUserId, null, $this->uuid());
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            $this->assertSame(403, $e->httpStatus);
            throw $e;
        }
    }

    public function testPatientAutoCreatedForNewUser(): void
    {
        global $wpdb;
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->otherUserId, $this->clinicianId, $slot['date'], $slot['time']);
        // Phase 8 Slice 2 (تصمیم مالک ۲): بیمارِ جدید در زمان confirm به نام/
        // نام‌خانوادگی نیاز دارد — همان قرارداد، اکنون در سطح Service.
        $result = $this->booking()->confirm($hold['hold_token'], $this->otherUserId, null, $this->uuid(), 'سارا', 'کریمی');

        $patient = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT p.* FROM ' . $wpdb->prefix . 'cpms_patient_user_links' . ' l
                 JOIN ' . $wpdb->prefix . 'cpms_patients' . ' p ON p.id = l.patient_id
                 WHERE l.wp_user_id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->otherUserId
            ),
            ARRAY_A
        );
        $this->assertNotNull($patient, 'Patient Record باید برای کاربر جدید ساخته شود');
        $this->assertSame('09120000002', $patient['mobile']);
        $this->assertSame('سارا', (string) $patient['first_name'], 'نام‌های تأییدشده روی بیمارِ تازه‌ساخته می‌نشیند');
        $this->assertSame('کریمی', (string) $patient['last_name']);
        $this->assertMatchesRegularExpression('/^MR-\d{6}-[A-Z0-9]{5}$/', (string) $patient['mrn']);
        $this->assertSame((int) $result['appointment_id'], (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'cpms_appointments WHERE patient_id = %d', (int) $patient['id']) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        ));
        $identityId = (int) $patient['identity_id'];
        $audit = App::db()->table( 'cpms_audit_logs' );
        $this->assertGreaterThan( 0, $identityId, 'BookingService::confirm activates a new organization identity' );
        $this->assertSame(
            1,
            (int) App::db()->fetchValue(
                "SELECT COUNT(*) FROM {$audit} WHERE action = %s AND resource_id = %d",
                [ 'PATIENT_IDENTITY_CREATED', $identityId ]
            ),
            'production BookingService::confirm persists the identity creation event'
        );
        $this->assertSame(
            1,
            (int) App::db()->fetchValue(
                "SELECT COUNT(*) FROM {$audit} WHERE action = %s AND resource_id = %d AND patient_id = %d AND clinic_id = %d",
                [ 'PATIENT_IDENTITY_LINKED', $identityId, (int) $patient['id'], 1 ]
            ),
            'production BookingService::confirm persists the identity linkage event'
        );
    }

    public function testBookingConfirmReusesVerifiedIdentityAndAuditsLinkage(): void
    {
        global $wpdb;
        $organizationId = (int) App::db()->fetchValue(
            'SELECT organization_id FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ 1 ]
        );
        $identityService = App::patient_identity_service();
        $identityId = $identityService->create_identity( $organizationId );
        $identityService->link_user( $organizationId, $identityId, $this->otherUserId, '09120000002' );

        $slot = $this->makeSlot( 3, '11:00', 1 );
        $hold = $this->booking()->hold( $this->otherUserId, $this->clinicianId, $slot['date'], $slot['time'] );
        $this->booking()->confirm( $hold['hold_token'], $this->otherUserId, null, $this->uuid(), 'سارا', 'کریمی' );

        $patient = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT p.id, p.identity_id FROM ' . $wpdb->prefix . 'cpms_patient_user_links l
                 JOIN ' . $wpdb->prefix . 'cpms_patients p ON p.id = l.patient_id
                 WHERE l.wp_user_id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->otherUserId
            ),
            ARRAY_A
        );
        $this->assertNotNull( $patient );
        $this->assertSame( $identityId, (int) $patient['identity_id'], 'confirm reuses only the pre-verified identity link' );

        $audit = App::db()->table( 'cpms_audit_logs' );
        $this->assertSame(
            1,
            (int) App::db()->fetchValue(
                "SELECT COUNT(*) FROM {$audit} WHERE action = %s AND resource_id = %d",
                [ 'PATIENT_IDENTITY_CREATED', $identityId ]
            )
        );
        $this->assertSame(
            1,
            (int) App::db()->fetchValue(
                "SELECT COUNT(*) FROM {$audit} WHERE action = %s AND resource_id = %d AND patient_id = %d AND clinic_id = %d",
                [ 'PATIENT_IDENTITY_LINKED', $identityId, (int) $patient['id'], 1 ]
            ),
            'verified-reuse path through BookingService::confirm must audit the new patient edge'
        );
    }

    public function testBookingConfirmAuditFailureRollsBackNewPatientAndIdentity(): void
    {
        global $wpdb;
        $slot = $this->makeSlot( 3, '12:00', 1 );
        $hold = $this->booking()->hold( $this->otherUserId, $this->clinicianId, $slot['date'], $slot['time'] );
        $beforeIdentities = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_identities' )
        );
        $audit = App::db()->table( 'cpms_audit_logs' );
        $createdEventsBefore = (int) App::db()->fetchValue(
            "SELECT COUNT(*) FROM {$audit} WHERE action = %s",
            [ 'PATIENT_IDENTITY_CREATED' ]
        );
        $linkedEventsBefore = (int) App::db()->fetchValue(
            "SELECT COUNT(*) FROM {$audit} WHERE action = %s",
            [ 'PATIENT_IDENTITY_LINKED' ]
        );
        $this->failAuditInserts();
        try {
            try {
                $this->booking()->confirm( $hold['hold_token'], $this->otherUserId, null, $this->uuid(), 'سارا', 'کریمی' );
                $this->fail( 'confirm must fail closed when identity audit persistence fails' );
            } catch ( BookingException $exception ) {
                $this->assertSame( 'CLINIC_INTERNAL_ERROR', $exception->errorCode );
            }
        } finally {
            $this->restoreAuditInserts();
        }

        $this->assertSame(
            $beforeIdentities,
            (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_identities' ) ),
            'identity creation must roll back with failed audit'
        );
        $this->assertSame(
            0,
            (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_patient_user_links WHERE wp_user_id = %d',
                $this->otherUserId
            ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'new patient user-link must roll back with failed identity audit'
        );
        $this->assertSame(
            0,
            (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_appointments WHERE slot_id = %d',
                $slot['id']
            ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'confirmation appointment must not persist'
        );
        $this->assertSame(
            $createdEventsBefore,
            (int) App::db()->fetchValue( "SELECT COUNT(*) FROM {$audit} WHERE action = %s", [ 'PATIENT_IDENTITY_CREATED' ] ),
            'creation success evidence cannot survive the failed transaction'
        );
        $this->assertSame(
            $linkedEventsBefore,
            (int) App::db()->fetchValue( "SELECT COUNT(*) FROM {$audit} WHERE action = %s", [ 'PATIENT_IDENTITY_LINKED' ] ),
            'link success evidence cannot survive the failed transaction'
        );
    }

    private function failAuditInserts(): void
    {
        global $wpdb;
        $fault = static function ( $query ) {
            $sql = (string) $query;
            if ( preg_match( '/\\A\\s*INSERT\\s+INTO\\s+(\\S+)/i', $sql, $match ) === 1
                && str_contains( $match[1], 'cpms_audit_logs' ) ) {
                return 'INSERT INTO ' . $match[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }
            return $query;
        };
        $this->previousSuppression = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $fault, 5 );
        $this->auditFault = $fault;
    }

    private function restoreAuditInserts(): void
    {
        if ( $this->auditFault === null ) {
            return;
        }
        remove_filter( 'query', $this->auditFault, 5 );
        $this->auditFault = null;
        global $wpdb;
        $wpdb->suppress_errors( $this->previousSuppression );
    }

    // ---------- B3/B4/B5/B6 ----------

    public function testListMine(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());

        $list = $this->booking()->listMine($this->patientUserId, gmdate('Y-m-d', time() - 86400), gmdate('Y-m-d', time() + 86400 * 30));
        $this->assertCount(1, $list);
        $this->assertSame($slot['date'], $list[0]['date']);
        $this->assertNotEmpty($list[0]['jalali']);
    }

    public function testCancelPolicyWindow(): void
    {
        // نوبت 3 روز بعد (خارج از Window لغو 24h — wait: 72h > 24h → مجاز)
        $slotFar = $this->makeSlot(3, '10:00', 1);
        $holdFar = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slotFar['date'], $slotFar['time']);
        $far = $this->booking()->confirm($holdFar['hold_token'], $this->patientUserId, null, $this->uuid());

        $result = $this->booking()->cancelByPatient($this->patientUserId, (int) $far['appointment_id'], 'تغییر برنامه');
        $this->assertSame('cancelled_by_patient', $result['status']);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slotFar['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(0, (int) $row['booked_count'], 'لغو باید ظرفیت آزاد کند');

        // بخش دوم: نوبت داخل Window — برای قطعیت مستقل از ساعت اجرای تست،
        // سقف Deadline را بالاتر از فاصله واقعی slot قرار می‌دهیم (47-54h < 96h).
        App::settings()->set('booking.cancel_deadline_hours', 96);
        $slotNear = $this->makeSlot(2, '23:00', 1);
        $holdNear = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slotNear['date'], $slotNear['time']);
        $near = $this->booking()->confirm($holdNear['hold_token'], $this->patientUserId, null, $this->uuid());

        $this->expectException(BookingException::class);
        try {
            $this->booking()->cancelByPatient($this->patientUserId, (int) $near['appointment_id'], null);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_POLICY_VIOLATION', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
            throw $e;
        }
    }

    public function testRescheduleMovesSlots(): void
    {
        global $wpdb;
        $oldSlot = $this->makeSlot(3, '10:00', 1, 20);
        $newSlot = $this->makeSlot(5, '14:00', 1, 40);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $oldSlot['date'], $oldSlot['time']);
        $appt = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());

        $result = $this->booking()->reschedule(
            $this->patientUserId,
            (int) $appt['appointment_id'],
            $this->clinicianId,
            $newSlot['date'],
            '14:00',
            $this->uuid()
        );
        $this->assertNotSame($appt['appointment_id'], $result['appointment_id']);
        $this->assertSame((int) $appt['appointment_id'], $result['previous_appointment_id']);
        $this->assertSame(40, $result['duration_min'], 'Snapshot اسلات جدید باید 40 باشد');

        $oldRow = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $oldSlot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $newRow = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $newSlot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(0, (int) $oldRow['booked_count'], 'اسلات قبلی باید آزاد شود');
        $this->assertSame(1, (int) $newRow['booked_count'], 'اسلات جدید باید Book شود');

        $oldAppt = $wpdb->get_row(
            $wpdb->prepare('SELECT status, rescheduled_to FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', (int) $appt['appointment_id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame('rescheduled', $oldAppt['status']);
        $this->assertSame((int) $result['appointment_id'], (int) $oldAppt['rescheduled_to']);
    }

    public function testInactiveLocationRejectsDirectHoldAndStaffCreateWithoutCapacityMutation(): void
    {
        $locationId = $this->makeSecondaryLocation();
        $slot = $this->makeSlot(3, '10:00', 1, 20, $locationId);
        $this->deactivateLocation($locationId);

        try {
            $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time'], $slot['id'], null, $locationId);
            $this->fail('A direct service hold must reject an inactive Location.');
        } catch (BookingException $exception) {
            $this->assertSame('CLINIC_NOT_FOUND', $exception->errorCode);
            $this->assertSame(404, $exception->httpStatus);
        }

        $patientId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_patients') . ' WHERE mrn = %s LIMIT 1',
            ['MR-TEST-0001']
        );
        $this->assertGreaterThan(0, $patientId, 'Staff-create control requires the persisted same-Clinic patient.');
        try {
            $this->booking()->createByStaff(
                $this->patientUserId,
                $patientId,
                $this->clinicianId,
                $slot['date'],
                $slot['time'],
                'inactive Location',
                $slot['id']
            );
            $this->fail('A direct service staff-create must reject an inactive Location.');
        } catch (BookingException $exception) {
            $this->assertSame('CLINIC_NOT_FOUND', $exception->errorCode);
            $this->assertSame(404, $exception->httpStatus);
        }

        $slotRow = App::db()->fetchRow(
            'SELECT booked_count, held_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slot['id']]
        );
        $this->assertNotNull($slotRow, 'The inactive Location slot remains persisted.');
        $this->assertSame(0, (int) $slotRow['booked_count'], 'Inactive Location denials leave booked capacity untouched.');
        $this->assertSame(0, (int) $slotRow['held_count'], 'Inactive Location denials leave held capacity untouched.');
        $holdCount = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_slot_holds') . ' WHERE slot_id = %d',
            [$slot['id']]
        );
        $appointmentCount = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE slot_id = %d',
            [$slot['id']]
        );
        $this->assertSame(0, $holdCount, 'The rejected hold creates no persisted row.');
        $this->assertSame(0, $appointmentCount, 'The rejected staff create creates no Appointment.');
    }

    public function testConfirmAtLocationDeactivatedAfterHoldReleasesOnlyTheTemporaryHold(): void
    {
        $locationId = $this->makeSecondaryLocation();
        $slot = $this->makeSlot(3, '10:00', 1, 20, $locationId);
        $hold = $this->booking()->hold(
            $this->patientUserId,
            $this->clinicianId,
            $slot['date'],
            $slot['time'],
            $slot['id'],
            null,
            $locationId
        );
        $heldBefore = (int) App::db()->fetchValue(
            'SELECT held_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slot['id']]
        );
        $this->assertSame(1, $heldBefore, 'The active Hold consumes one capacity unit before deactivation.');

        $this->deactivateLocation($locationId);
        try {
            $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());
            $this->fail('A Hold created before Location deactivation must not create a new Appointment there.');
        } catch (BookingException $exception) {
            $this->assertSame('CLINIC_NOT_FOUND', $exception->errorCode);
            $this->assertSame(404, $exception->httpStatus);
        }

        $holdRow = App::db()->fetchRow(
            'SELECT status FROM ' . App::db()->table('cpms_slot_holds') . ' WHERE token = %s LIMIT 1',
            [$hold['hold_token']]
        );
        $slotRow = App::db()->fetchRow(
            'SELECT booked_count, held_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slot['id']]
        );
        $this->assertNotNull($holdRow, 'The persisted Hold row remains as an auditable terminal row.');
        $this->assertNotNull($slotRow, 'The slot row remains present after the denial.');
        $this->assertSame('released', (string) $holdRow['status'], 'The temporary Hold is released so inactive capacity is not stranded.');
        $this->assertSame(0, (int) $slotRow['booked_count'], 'No Appointment was created.');
        $this->assertSame(0, (int) $slotRow['held_count'], 'Compensation releases the temporary held capacity exactly once.');
        $appointmentCount = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE slot_id = %d',
            [$slot['id']]
        );
        $this->assertSame(0, $appointmentCount, 'The inactive Location denial creates no Appointment.');
    }

    public function testRescheduleToInactiveLocationPreservesTheExistingAppointmentAndBothSlotCounters(): void
    {
        $locationId = $this->makeSecondaryLocation();
        $oldSlot = $this->makeSlot(3, '10:00', 1);
        $newSlot = $this->makeSlot(5, '14:00', 1, 20, $locationId);
        $hold = $this->booking()->hold(
            $this->patientUserId,
            $this->clinicianId,
            $oldSlot['date'],
            $oldSlot['time'],
            $oldSlot['id'],
            null,
            $oldSlot['location_id']
        );
        $appointment = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());
        $this->deactivateLocation($locationId);

        try {
            $this->booking()->reschedule(
                $this->patientUserId,
                (int) $appointment['appointment_id'],
                $this->clinicianId,
                $newSlot['date'],
                $newSlot['time'],
                $this->uuid(),
                $newSlot['id']
            );
            $this->fail('A replacement Appointment must not be created at an inactive destination Location.');
        } catch (BookingException $exception) {
            $this->assertSame('CLINIC_NOT_FOUND', $exception->errorCode);
            $this->assertSame(404, $exception->httpStatus);
        }

        $oldRow = App::db()->fetchRow(
            'SELECT booked_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$oldSlot['id']]
        );
        $newRow = App::db()->fetchRow(
            'SELECT booked_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$newSlot['id']]
        );
        $appointmentRow = App::db()->fetchRow(
            'SELECT status, rescheduled_to FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [(int) $appointment['appointment_id']]
        );
        $appointmentCount = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE slot_id IN (%d, %d)',
            [$oldSlot['id'], $newSlot['id']]
        );
        $this->assertNotNull($oldRow, 'The source Slot remains persisted.');
        $this->assertNotNull($newRow, 'The destination Slot remains persisted.');
        $this->assertNotNull($appointmentRow, 'The original Appointment remains persisted.');
        $this->assertSame(1, (int) $oldRow['booked_count'], 'Rollback preserves the original booking capacity.');
        $this->assertSame(0, (int) $newRow['booked_count'], 'Denied destination never claims capacity.');
        $this->assertSame('confirmed', (string) $appointmentRow['status'], 'The historical Appointment remains confirmed.');
        $this->assertNull($appointmentRow['rescheduled_to'], 'No replacement link is written.');
        $this->assertSame(1, $appointmentCount, 'No replacement appointment was persisted.');
    }

    public function testExistingAppointmentRemainsCancellableAfterLocationDeactivation(): void
    {
        $locationId = $this->makeSecondaryLocation();
        $slot = $this->makeSlot(3, '10:00', 1, 20, $locationId);
        $hold = $this->booking()->hold(
            $this->patientUserId,
            $this->clinicianId,
            $slot['date'],
            $slot['time'],
            $slot['id'],
            null,
            $locationId
        );
        $appointment = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());
        $this->deactivateLocation($locationId);

        $cancelled = $this->booking()->cancelByPatient(
            $this->patientUserId,
            (int) $appointment['appointment_id'],
            'Location deactivated'
        );
        $appointmentRow = App::db()->fetchRow(
            'SELECT status, location_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [(int) $appointment['appointment_id']]
        );
        $bookedCount = (int) App::db()->fetchValue(
            'SELECT booked_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slot['id']]
        );

        $this->assertNotNull($appointmentRow, 'The historical Appointment remains persisted after cancellation.');
        $this->assertSame('cancelled_by_patient', $cancelled['status']);
        $this->assertSame('cancelled_by_patient', (string) $appointmentRow['status']);
        $this->assertSame($locationId, (int) $appointmentRow['location_id'], 'The historical Appointment retains its Location snapshot.');
        $this->assertSame(0, $bookedCount, 'Continuity cancellation releases the original capacity.');
    }

    public function testResumeAfterDisconnect(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);

        $resumed = $this->booking()->resume($hold['hold_token'], $this->patientUserId);
        $this->assertSame('active', $resumed['status']);
        $this->assertSame($hold['hold_token'], $resumed['hold_token']);
        $this->assertSame($slot['id'], $resumed['slot']['slot_id']);
    }

    // ---------- Snapshot (TP-21) ----------

    public function testDurationSnapshotNotAffectedBySettingsChange(): void
    {
        $slot = $this->makeSlot(3, '10:00', 1, 20);
        $hold = $this->booking()->hold($this->patientUserId, $this->clinicianId, $slot['date'], $slot['time']);
        $appt = $this->booking()->confirm($hold['hold_token'], $this->patientUserId, null, $this->uuid());

        // تغییر Default کلینیک بعد از رزرو
        App::settings()->set('booking.duration_default_min', 45);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT duration_min FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', (int) $appt['appointment_id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(20, (int) $row['duration_min'], 'تغییر Default نباید نوبت ثبت‌شده را تغییر دهد (TP-21)');
    }

    // ---------- Staff (D9/D10/D11) ----------

    public function testStaffCreateDuplicateAndListAndCancel(): void
    {
        global $wpdb;
        $slot = $this->makeSlot(1, '10:00', 1);
        $patientId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'cpms_patients WHERE mrn = %s', 'MR-TEST-0001') // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        $created = $this->booking()->createByStaff($this->patientUserId, $patientId, $this->clinicianId, $slot['date'], '10:00', 'فوری');
        $this->assertSame('confirmed', $created['status']);

        // تکراری
        try {
            $this->booking()->createByStaff($this->patientUserId, $patientId, $this->clinicianId, $slot['date'], '10:00', null);
            $this->fail('Duplicate باید رد شود');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_DUPLICATE_APPOINTMENT', $e->errorCode);
        }

        // لیست روز (D9)
        $list = $this->booking()->listForClinician($this->clinicianId, $slot['date'], null);
        $this->assertCount(1, $list);
        $this->assertSame($created['reference_code'], $list[0]['reference_code']);

        // لغو توسط منشی (D11) — بدون محدودیت Deadline
        $result = $this->booking()->cancelByStaff($this->patientUserId, (int) $created['appointment_id'], 'درخواست بیمار');
        $this->assertSame('cancelled_by_staff', $result['status']);

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT booked_count FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE id = %d', $slot['id']), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(0, (int) $row['booked_count']);
    }
}
