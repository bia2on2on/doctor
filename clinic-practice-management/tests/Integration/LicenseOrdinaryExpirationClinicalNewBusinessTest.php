<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Licensing\VendorGateway;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use WP_UnitTestCase;

/** Real signed-document/service regressions for the Owner post-expiration policy. */
final class LicenseOrdinaryExpirationClinicalNewBusinessTest extends WP_UnitTestCase
{
    /** @var string|null کلید امضا برای سندِ منقضیِ جعلی */
    public ?string $keypair = null;
    public ?int $expiresAt = null;

    private int $clinicianId;
    private int $patientId;
    private int $secretaryUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        if (!LicenseSignature::available()) {
            $this->markTestSkipped('sodium not available — signature tests need real sodium');
        }
        $this->keypair = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($this->keypair));
        add_filter('cpms_license_public_key', static fn (): string => $pub);

        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('booking.hold_ttl_sec', 600);
        App::settings()->set('queue.auto_enqueue', true);

        global $wpdb;
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Ordinary Expiry',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Expiry", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-EXP-0001',
                '09127770001',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $this->secretaryUserId = (int) wp_create_user('expiry_secretary', 'pass-12345', 'expiry_sec@test.local');
        $secretary = get_userdata($this->secretaryUserId);
        if ($secretary !== false) {
            $secretary->set_role('cpms_secretary');
        }
    }

    protected function tearDown(): void
    {
        remove_all_filters('cpms_license_public_key');
        remove_all_filters('cpms_license_dev_mode');
        parent::tearDown();
    }

    // ================= Fixtures =================

    /**
     * Gateway جعلی با یک رفتار: سندِ امضاشدهٔ منقضیِ خارج از grace
     * (expires_at = now - 10d؛ expiry_grace پیش‌فرض ۷ روز).
     */
    private function expiredGateway(): VendorGateway
    {
        return new class($this) implements VendorGateway {
            public function __construct(private readonly LicenseOrdinaryExpirationClinicalNewBusinessTest $t)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function activate(array $request): array
            {
                return $this->doc($request);
            }

            public function refresh(array $request): array
            {
                return $this->doc($request);
            }

            /**
             * @param array<string, mixed> $request
             *
             * @return array{payload: array<string, mixed>, signature_b64: string}
             */
            private function doc(array $request): array
            {
                $expires = $this->t->expiresAt ?? time() - 10 * 86400;
                $payload = [
                    'product' => 'cpms',
                    'license_id' => 'lic-ordinary-expired',
                    'install_id' => (string) ($request['install_id'] ?? ''),
                    'issued_at' => $expires - 365 * 86400,
                    'expires_at' => $expires,
                    'revoked' => false,
                    'suspended' => false,
                    'plan' => 'annual',
                    'entitlements' => ['features' => ['updates' => false]],
                ];
                $sig = sodium_crypto_sign_detached(
                    LicenseSignature::canonicalJson($payload),
                    sodium_crypto_sign_secretkey($this->t->keypair)
                );

                return ['payload' => $payload, 'signature_b64' => base64_encode($sig)];
            }
        };
    }

    private function licenseService(): LicenseService
    {
        return new LicenseService(
            new LicenseRepository(App::db()),
            $this->expiredGateway(),
            App::db()
        );
    }

    /**
     * نصبِ واقعیِ سندِ منقضی (اعتبارسنجی امضا + ذخیره) — مسیر تولید.
     */
    private function installOrdinaryExpiredLicense(): LicenseService
    {
        $service = $this->licenseService();
        $service->activateWithKey('key-ordinary-expired');

        return $service;
    }

    /**
     * VisitService واقعی با Gate واقعی (SignedLicenseGate روی LicenseService
     * واقعی) — همان سیم‌کشی `VisitLicenseGateTest` با Gate واقعی به‌جای Fake.
     */
    private function visitService(LicenseService $licenses, ?\Closure $clock = null): VisitService
    {
        $db = App::db();

        return new VisitService(
            $db,
            new VisitRepository($db),
            new AppointmentRepository($db),
            App::settingsFactory(),
            App::audit(),
            new SignedLicenseGate($licenses, new \ClinicCore\Infrastructure\Repository\ReturningPatientRepository($db), $clock),
            null,
            null
        );
    }

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function makeSlot(int $dayOffset, string $time, int $capacity = 1): array
    {
        global $wpdb;
        $date = gmdate('Y-m-d', time() + $dayOffset * 86400);
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, (SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1), %d, %s, %s, 20, %d, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianId,
                $date,
                $time,
                $capacity,
                $now,
                $now
            )
        );

        return ['id' => (int) $wpdb->insert_id, 'date' => $date, 'time' => $time];
    }

    public function testBookingRechecksT0AfterCapacityAndSlotLockWaits(): void
    {
        $this->expiresAt = time() + 86400;
        $licenses = $this->installOrdinaryExpiredLicense();
        $now = $this->expiresAt - 1;
        $clock = static function () use (&$now): int { return $now; };
        $db = App::db();
        $service = new \ClinicCore\Application\Booking\BookingService(
            $db, new \ClinicCore\Infrastructure\Repository\SlotRepository($db),
            new AppointmentRepository($db), new \ClinicCore\Infrastructure\Repository\PatientRepository($db),
            App::settingsFactory(), new SignedLicenseGate($licenses, null, $clock),
            App::audit(), App::op(), new \ClinicCore\Infrastructure\Security\Idempotency($db), App::smsService()
        );
        $slot = $this->makeSlot(2, '13:20');
        $user = (int) wp_create_user('expiry_lock_public', 'pass-12345', '09127770888@otp.cpms.local');
        // First demonstrate a hold capacity lock returning after T0.
        $cross = function (string $sql) use (&$now): string {
            if (str_contains($sql, 'cpms_schedule_slots') && (str_contains($sql, 'FOR UPDATE') || str_contains($sql, 'UPDATE '))) {
                $now = $this->expiresAt;
            }
            return $sql;
        };
        add_filter('query', $cross);
        try {
            $service->hold($user, $this->clinicianId, $slot['date'], '13:20');
            $this->fail('Hold must recheck after capacity lock');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        } finally {
            remove_filter('query', $cross);
        }
        $this->assertSame(0, (int) $db->fetchValue('SELECT held_count FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = %d', [$slot['id']]));
        $now = $this->expiresAt - 1;
        $hold = $service->hold($user, $this->clinicianId, $slot['date'], '13:20');
        add_filter('query', $cross);
        try {
            $service->confirm($hold['hold_token'], $user, null, wp_generate_uuid4(), 'New', 'After lock');
            $this->fail('Confirm must recheck after slot lock');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        } finally {
            remove_filter('query', $cross);
        }
        $now = $this->expiresAt - 1;
        add_filter('query', $cross);
        try {
            $service->createByStaff($this->secretaryUserId, $this->patientId, $this->clinicianId, $slot['date'], '13:20', null);
            $this->fail('Staff booking must recheck after slot lock');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        } finally {
            remove_filter('query', $cross);
        }
        $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_appointments')));
    }

    public function testRestStaffBookingCannotBypassT0(): void
    {
        $this->expiresAt = time() - 1;
        $this->installOrdinaryExpiredLicense();
        $slot = $this->makeSlot(2, '12:20');
        cpms_test_seed_membership($this->secretaryUserId, 1, 'cpms_secretary');
        wp_set_current_user($this->secretaryUserId);
        $request = new \WP_REST_Request('POST', '/clinic/v1/appointments');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', '1');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'patient_id' => $this->patientId, 'clinician_id' => $this->clinicianId,
            'slot_date' => $slot['date'], 'slot_time' => '12:20', 'slot_id' => $slot['id'],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(503, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertStringContainsString('CLINIC_LICENSE_BLOCKED', wp_json_encode($response->get_data()));
    }

    public function testPublicHoldAndPreT0HoldConfirmationCannotBookAfterT0(): void
    {
        $this->expiresAt = time() + 86400;
        $this->installOrdinaryExpiredLicense();
        $slot = $this->makeSlot(2, '11:20');
        $user = (int) wp_create_user('expiry_public', 'pass-12345', '09127770999@otp.cpms.local');
        (new \WP_User($user))->set_role('cpms_patient');
        $hold = App::bookingService()->hold($user, $this->clinicianId, $slot['date'], '11:20');
        $this->assertNotEmpty($hold['hold_token']);
        $this->expiresAt = time() - 1; // Grace and skew must NOT postpone shutdown.
        $this->installOrdinaryExpiredLicense();
        foreach (['hold', 'confirm'] as $operation) {
            try {
                if ($operation === 'hold') {
                    App::bookingService()->hold($user, $this->clinicianId, $slot['date'], '11:20');
                } else {
                    App::bookingService()->confirm($hold['hold_token'], $user, null, wp_generate_uuid4(), 'New', 'Patient');
                }
                $this->fail('Booking must stop at T0');
            } catch (BookingException $e) {
                $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
            }
        }
        $this->assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments')));
        $this->expiresAt = time() + 86400;
        $this->installOrdinaryExpiredLicense();
        $confirmed = App::bookingService()->confirm($hold['hold_token'], $user, null, wp_generate_uuid4(), 'Renewed', 'Patient');
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertSame(1, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments')));
        $this->assertTrue(App::audit()->verifyChain(500)['ok']);
    }

    public function testExistingAppointmentRemainsActionableAfterAllNewServiceWindows(): void
    {
        $this->expiresAt = time() + 86400;
        $this->installOrdinaryExpiredLicense();
        $slot = $this->makeSlot(2, '14:20');
        $appointment = App::bookingService()->createByStaff($this->secretaryUserId, $this->patientId, $this->clinicianId, $slot['date'], '14:20', null);
        $this->expiresAt = time() - 220 * 86400;
        $licenses = $this->installOrdinaryExpiredLicense();
        $id = (int) $appointment['appointment_id'];
        // Fixture an appointment booked before the now-expired signed term.
        App::db()->update('cpms_appointments', ['created_at' => gmdate('Y-m-d H:i:s', $this->expiresAt - 1), 'booked_at' => gmdate('Y-m-d H:i:s', $this->expiresAt - 1)], ['id' => $id]);
        // Simulate arrival on the booked Location-local calendar date; license
        // continuity is independent of the separate same-day check-in rule.
        $timezone = (string) App::db()->fetchValue(
            'SELECT l.timezone FROM ' . App::db()->table('cpms_locations') . ' l JOIN '
            . App::db()->table('cpms_appointments') . ' a ON a.location_id = l.id WHERE a.id = %d',
            [$id]
        );
        $this->assertNotEmpty($timezone);
        $arrival = new \DateTimeImmutable($slot['date'] . ' 00:00:00', new \DateTimeZone($timezone));
        \ClinicCore\Application\Visits\VisitService::setTestNowUtc($arrival->setTimezone(new \DateTimeZone('UTC')));
        try {
            $visit = $this->visitService($licenses)->checkIn($this->secretaryUserId, $this->patientId, $id);
        } finally {
            \ClinicCore\Application\Visits\VisitService::setTestNowUtc(null);
        }
        $this->assertSame('waiting', $visit['status']);
        $this->assertSame($id, (int) $visit['appointment_id']);
        $this->assertTrue(App::audit()->verifyChain(500)['ok']);
    }

    public function testNewPatientAndWalkInStopAfterOneMonthButVerifiedReturningContinues(): void
    {
        $this->expiresAt = time() - 60 * 86400;
        $licenses = $this->installOrdinaryExpiredLicense();
        try {
            App::patientService()->create(['first_name' => 'New', 'last_name' => 'Late', 'mobile' => '09127770103'], $this->secretaryUserId);
            $this->fail('New patient window ended');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        try {
            $this->visitService($licenses)->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId, ['returning' => true, 'identity_id' => 1]);
            $this->fail('Request metadata cannot prove returning identity');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        $db = App::db();
        $org = (int) $db->fetchValue('SELECT organization_id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [1]);
        $identity = App::patient_identity_service()->create_identity($org);
        App::patient_identity_service()->link_clinical_record($org, $identity, 1, $this->patientId);
        App::patient_identity_service()->link_user($org, $identity, $this->secretaryUserId, '09127770001');
        (new VisitRepository($db))->insert(1, [
            'patient_id' => $this->patientId, 'clinician_id' => $this->clinicianId,
            'visit_date' => gmdate('Y-m-d', $this->expiresAt - 86400),
            'check_in_at' => gmdate('Y-m-d H:i:s', $this->expiresAt - 86400),
            'consultation_completed_at' => gmdate('Y-m-d H:i:s', $this->expiresAt - 1),
            'status' => 'checked_out', 'active' => 0,
        ]);
        $visit = $this->visitService($licenses)->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
        $this->assertSame('waiting', $visit['status']);
        $deadline = \ClinicCore\Domain\Licensing\PostExpirationPolicy::deadline($this->expiresAt, 6);
        try {
            $this->visitService($licenses, static fn (): int => $deadline)->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
            $this->fail('Even verified returning status ends at the exact six-month boundary');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        $this->assertTrue(App::audit()->verifyChain(500)['ok']);
    }

    // ================= قرارداد =================

    public function testExpiredBeyondGraceStaysRepresentedAsExpiredRestricted(): void
    {
        $service = $this->installOrdinaryExpiredLicense();

        // نمایش وضعیت/تمدید بدون تغییر می‌ماند — انقضا پنهان/عادی‌سازی نمی‌شود.
        $state = $service->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
        $this->assertSame('expired', $state['reason']);
        $this->assertTrue($state['needs_renewal']);

        $meta = $service->statusMeta();
        $this->assertTrue($meta['configured']);
        $this->assertSame(LicenseStatus::RESTRICTED, $meta['status']);
        $this->assertSame('expired', $meta['reason']);
        $this->assertTrue($meta['needs_renewal']);

        $gate = new SignedLicenseGate($service);
        $this->assertSame(LicenseStatus::RESTRICTED, $gate->state());
        $this->assertTrue($gate->isReadOnly());
    }

    public function testRealGateBlocksBookingsButPreservesFinanceAndReception(): void
    {
        $gate = new SignedLicenseGate($this->installOrdinaryExpiredLicense());
        $this->assertFalse($gate->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
        $this->assertFalse($gate->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_PATIENT_CREATE)->allowed);
        $this->assertTrue($gate->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_PATIENT_CREATE, ['reception' => true])->allowed);
        $this->assertTrue($gate->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_INVOICE_CREATE)->allowed);
    }

    public function testReceptionCanCreateNewPatientInsideOneMonthWindow(): void
    {
        $this->installOrdinaryExpiredLicense();

        try {
            $created = App::patientService()->create([
                'first_name' => 'مریم',
                'last_name' => 'انقضای عادی',
                'mobile' => '09127770102',
            ], $this->secretaryUserId);
        } catch (BookingException $e) {
            $this->fail('انقضای عادی تجاری نباید ثبت بیمار جدید را مسدود کند — دریافت: ' . $e->errorCode);
        }

        $this->assertSame('active', $created['status']);
        $this->assertMatchesRegularExpression('/^MR-\d{6}-[A-Z0-9]{5}$/', (string) $created['mrn']);
    }

    public function testOrdinaryExpirationBlocksStaffAppointmentBooking(): void
    {
        $this->installOrdinaryExpiredLicense();
        $slot = $this->makeSlot(2, '10:20');
        try {
            App::bookingService()->createByStaff($this->secretaryUserId, $this->patientId, $this->clinicianId, $slot['date'], '10:20', null);
            $this->fail('Expected license block');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        $this->assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments')));
    }

    public function testReceptionCanStartWalkInInsideOneMonthWindow(): void
    {
        $service = $this->installOrdinaryExpiredLicense();

        try {
            $visit = $this->visitService($service)->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
        } catch (VisitException $e) {
            $this->fail('انقضای عادی تجاری نباید ثبت مراجعهٔ بدون نوبت را مسدود کند — دریافت: ' . $e->errorCode);
        }

        $this->assertSame('waiting', (string) $visit['status']);
        $this->assertSame('walk_in', (string) $visit['source']);
    }
}
