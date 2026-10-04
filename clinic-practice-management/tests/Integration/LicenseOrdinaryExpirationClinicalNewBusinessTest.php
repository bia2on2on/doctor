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

/**
 * Phase 16 Slice 1 — «انقضای عادی تجاری نباید فعالیت بالینیِ جدید را قفل کند».
 *
 * جهت معماری/محصول این اسلایس: واحد تجاری = Organization (Organization ≠
 * Installation)؛ جهت پایهٔ تجاری = Annual License + Version Rights. پایانِ
 * صرفِ سالانه (Ordinary Commercial Expiration) مجوزِ بالینیِ جاری را بی‌اثر
 * نمی‌کند.
 *
 * مسیر واقعی (نه Fake): سندِ امضاشدهٔ منقضیِ خارج از grace از طریق
 * `LicenseService::activateWithKey()` (اعتبارسنجی واقعی امضا + ذخیره در
 * `cpms_license_state`) نصب می‌شود — همان fixture/mode «expired» موجود در
 * `LicenseLifecycleTest`. سپس:
 *  - نمایش وضعیت/تمدید (status/reason/needs_renewal/statusMeta) دست‌نخورده
 *    باقی می‌ماند = RESTRICTED + expired + needs_renewal؛
 *  - Gate/Service واقعی دیگر «فعالیت بالینی جدید» را صرفاً به‌خاطر انقضا رد
 *    نمی‌کند: ثبت بیمار جدید (`PatientService::create`)، رزرو نوبت کارکنان
 *    (`BookingService::createByStaff`) و مراجعهٔ بدون نوبت
 *    (`VisitService::walkIn`).
 *
 * صریحاً بیرون از این اسلایس (رفتار قبلی حفظ می‌شود — با تست‌های موجودِ
 * دست‌نخورده پوشش داده می‌شود): پایان پنجرهٔ فعال‌سازی
 * (`LicenseActivationWindowIntegrationTest`)، vendor-unreachable/stale،
 * suspension، revocation، سند نامعتبر/دستکاری‌شده، install binding، و هیچ
 * تغییر عددی در grace/offline/trial/activation policy.
 *
 * فقط در CI (MySQL + WP + sodium) اجرا می‌شود.
 */
final class LicenseOrdinaryExpirationClinicalNewBusinessTest extends WP_UnitTestCase
{
    /** @var string|null کلید امضا برای سندِ منقضیِ جعلی */
    public ?string $keypair = null;

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
                $expires = time() - 10 * 86400;
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
    private function visitService(LicenseService $licenses): VisitService
    {
        $db = App::db();

        return new VisitService(
            $db,
            new VisitRepository($db),
            new AppointmentRepository($db),
            App::settingsFactory(),
            App::audit(),
            new SignedLicenseGate($licenses),
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

    public function testRealGateAllowsEveryGatedOperationUnderOrdinaryExpiration(): void
    {
        $gate = new SignedLicenseGate($this->installOrdinaryExpiredLicense());

        foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
            $this->assertTrue(
                $gate->assert($op)->allowed,
                "{$op} نباید صرفاً به‌خاطر انقضای عادی تجاری مسدود شود"
            );
        }
    }

    public function testOrdinaryExpirationDoesNotBlockNewPatientCreation(): void
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

    public function testOrdinaryExpirationDoesNotBlockStaffAppointmentBooking(): void
    {
        $this->installOrdinaryExpiredLicense();
        $slot = $this->makeSlot(2, '10:20');

        try {
            $created = App::bookingService()->createByStaff(
                $this->secretaryUserId,
                $this->patientId,
                $this->clinicianId,
                $slot['date'],
                '10:20',
                'انقضای عادی'
            );
        } catch (BookingException $e) {
            $this->fail('انقضای عادی تجاری نباید رزرو نوبت جدید را مسدود کند — دریافت: ' . $e->errorCode);
        }

        $this->assertSame('confirmed', (string) $created['status']);
        $this->assertNotEmpty($created['reference_code']);
    }

    public function testOrdinaryExpirationDoesNotBlockWalkInVisit(): void
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
