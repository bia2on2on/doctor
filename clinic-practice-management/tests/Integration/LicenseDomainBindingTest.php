<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Licensing\HttpVendorGateway;
use ClinicCore\Infrastructure\Licensing\VendorGateway;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use WP_UnitTestCase;

/**
 * Phase 16 Slice 2 — Signed Domain Binding (دامنه = قیدِ فعال‌سازیِ امضاشده).
 *
 * قرارداد:
 *  - سندِ امضاشدهٔ معتبری که ادعای `domain` دارد فقط برای همان دامنهٔ canonical
 *    محلی معتبر است؛ عدم‌تطابق → RESTRICTED با علتِ محدود/مشخص `binding_mismatch`
 *    و مسدودشدنِ عملیاتِ محافظت‌شدهٔ کسب‌وکارِ جدید (fail-closed).
 *  - سندِ legacy بدون `domain` = unbound؛ رفتار قبلی دست‌نخورده.
 *  - ادعای حاضر ولی خالی/نامعتبر/غیرقابل‌استفاده → fail-closed (نه unbound).
 *  - عدم‌تطابق بازگشت‌پذیر است (تغییرِ دامنهٔ محلی؛ بدون دست‌زدن به سند/داده).
 *  - منبعِ canonical محلی = `home_url()` وردپرس با همان قاعدهٔ موجود
 *    (lower-case + حذفِ `www.` پیشرو)؛ هیچ اتکایی به HTTP_HOST/request-host نیست.
 *  - install_id همچنان اجباری و بدون تغییر است.
 *  - Refresh باید دامنهٔ canonical *جاری* را (ابردادهٔ مجازِ ADR-0028 §2)
 *    بفرستد؛ PHI همچنان ممنوع است (VendorPlanePrivacyTest دست‌نخورده می‌ماند).
 *
 * فقط در CI (WP + MySQL + sodium) اجرا می‌شود.
 */
final class LicenseDomainBindingTest extends WP_UnitTestCase
{
    /**
     * Allowlist ابردادهٔ refresh (ADR-0028 §2) — همان کلیدهای refresh در
     * HttpVendorGateway به‌علاوهٔ `domain` که این اسلایس عمداً می‌فرستد.
     */
    private const REFRESH_ALLOWED_KEYS = ['install_id', 'license_id', 'environment', 'version', 'domain'];

    /** @var string|null کلید امضا برای سندهای جعلی */
    private ?string $keypair = null;

    private int $clinicianId = 0;
    private int $patientId = 0;
    private int $secretaryUserId = 0;
    private int $doctorUserId = 0;

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
        App::settings()->set('queue.max_recalls', 2);

        global $wpdb;
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Domain Binding',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Domain", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-DOM-0001',
                '09129990001',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $this->secretaryUserId = $this->makeUser('dom_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('dom_doctor', 'cpms_doctor');

        // F9 (ADR-0027): گارد مالکیت ویزیت پزشک‌ها را با wp_user_id تطبیق می‌دهد —
        // بدون پیوند، هر transition پزشک به‌اشتباه 403 می‌شود.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_clinicians SET wp_user_id = %d WHERE id = %d',
                $this->doctorUserId,
                $this->clinicianId
            )
        );
    }

    protected function tearDown(): void
    {
        remove_all_filters('cpms_license_public_key');
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    // =========================================================================
    // A) Signed-domain binding روی سرویس لایسنسِ واقعی
    // =========================================================================

    public function testMatchingDomainClaimKeepsExistingLicenseState(): void
    {
        $this->setLocalHome('https://clinic-a.example');
        $licenses = $this->licenseService();
        // ادعای دامنه با حروف بزرگ و پیشوند www — پس از canonicalization منطبق است.
        $this->installDocument($licenses, ['domain' => 'WWW.Clinic-A.Example']);

        $state = $licenses->currentState();
        $this->assertSame(LicenseStatus::ACTIVE, $state['status']);
        $this->assertSame('', $state['reason']);
        $this->assertFalse($state['needs_renewal']);

        $gate = new SignedLicenseGate($licenses);
        foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
            $this->assertTrue($gate->assert($op)->allowed, "{$op} با دامنهٔ منطبق باید مجاز بماند");
        }
    }

    public function testMismatchedDomainClaimRestrictsWithBoundedReason(): void
    {
        $this->setLocalHome('https://clinic-a.example');
        $licenses = $this->licenseService();
        $this->installDocument($licenses, ['domain' => 'clinic-b.example']);

        $state = $licenses->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
        $this->assertSame('binding_mismatch', $state['reason']);
        $this->assertTrue($state['needs_renewal']);

        $meta = $licenses->statusMeta();
        $this->assertSame(LicenseStatus::RESTRICTED, $meta['status']);
        $this->assertSame('binding_mismatch', $meta['reason']);

        $gate = new SignedLicenseGate($licenses);
        $decision = $gate->assert(LicenseGate::OP_PATIENT_CREATE);
        $this->assertFalse($decision->allowed, 'ثبت بیمار جدید با عدم‌تطابق دامنه باید مسدود شود');
        $this->assertSame('license:restricted', $decision->reason);

        // بهداشت/تکمیل/لغو (خارج از مجموعهٔ محافظت‌شدهٔ جدید) مجاز می‌ماند.
        $this->assertTrue($gate->assert(LicenseGate::OP_PATIENT_UPDATE)->allowed);
        $this->assertTrue($gate->assert(LicenseGate::OP_APPOINTMENT_CANCEL)->allowed);
        $this->assertTrue($gate->isReadOnly());
    }

    public function testMismatchIsReversibleWhenTheBoundLocalDomainReturns(): void
    {
        $this->setLocalHome('https://clinic-a.example');
        $licenses = $this->licenseService();
        $this->installDocument($licenses, ['domain' => 'clinic-b.example']);

        $this->assertSame('binding_mismatch', $licenses->currentState()['reason']);

        // همان سایت روی دامنهٔ اعلام‌شدهٔ سند → وضعیت عادی برمی‌گردد (بدون دست‌زدن به سند).
        $this->setLocalHome('https://www.Clinic-B.example');
        $this->assertSame(LicenseStatus::ACTIVE, $licenses->currentState()['status']);
        $this->assertTrue((new SignedLicenseGate($licenses))->assert(LicenseGate::OP_PATIENT_CREATE)->allowed);

        // بازگشت به دامنهٔ نادرست → دوباره محدود.
        $this->setLocalHome('https://clinic-a.example');
        $state = $licenses->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
        $this->assertSame('binding_mismatch', $state['reason']);
    }

    public function testLegacySignedDocumentWithoutDomainClaimKeepsExistingBehavior(): void
    {
        $this->setLocalHome('https://clinic-a.example');
        $licenses = $this->licenseService();
        $this->installDocument($licenses); // بدون ادعای دامنه = legacy/unbound

        $this->assertSame(LicenseStatus::ACTIVE, $licenses->currentState()['status']);

        // تغییرِ دامنهٔ محلی روی سندِ legacy بی‌اثر است (رفتار قبلی حفظ می‌شود).
        $this->setLocalHome('https://clinic-b.example');
        $state = $licenses->currentState();
        $this->assertSame(LicenseStatus::ACTIVE, $state['status']);
        $this->assertSame('', $state['reason']);
        $this->assertTrue((new SignedLicenseGate($licenses))->assert(LicenseGate::OP_PATIENT_CREATE)->allowed);
    }

    /**
     * ادعای حاضر ولی غیرقابل‌استفاده (خالی/فضاپایه/غیررشته/ناقص) نباید
     * «بی‌اثر» شود — باید fail-closed باشد.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function unusableDomainClaims(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'integer' => [123],
            'null' => [null],
            'array' => [[]],
            'scheme + host' => ['https://clinic-a.example'],
            'host with path' => ['clinic-a.example/admin'],
        ];
    }

    /**
     * @dataProvider unusableDomainClaims
     *
     * @param mixed $claim
     */
    public function testPresentButInvalidOrEmptyDomainClaimFailsClosed($claim): void
    {
        $this->setLocalHome('https://clinic-a.example');
        $licenses = $this->licenseService();
        $this->installDocument($licenses, ['domain' => $claim]);

        $state = $licenses->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status'], 'ادعای غیرقابل‌استفاده باید fail-closed باشد');
        $this->assertSame('binding_mismatch', $state['reason']);
        $this->assertFalse((new SignedLicenseGate($licenses))->assert(LicenseGate::OP_APPOINTMENT_BOOK)->allowed);
    }

    public function testMismatchedDomainClaimBlocksEvenWhenTheDocumentIsOrdinarilyExpired(): void
    {
        $this->setLocalHome('https://clinic-a.example');
        $licenses = $this->licenseService();
        $expired = time() - 10 * 86400; // خارج از expiry_grace پیش‌فرض (۷ روز)
        $this->installDocument($licenses, [
            'domain' => 'clinic-b.example',
            'issued_at' => $expired - 365 * 86400,
            'expires_at' => $expired,
        ]);

        // استثنای «انقضای عادی تجاری» (Phase 16 Slice 1) فقط برای سندِ همان دامنه است.
        $state = $licenses->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
        $this->assertSame('binding_mismatch', $state['reason']);
        $this->assertFalse(
            (new SignedLicenseGate($licenses))->assert(LicenseGate::OP_PATIENT_CREATE)->allowed,
            'سندِ منقضیِ متصل به دامنهٔ دیگر نباید از استثنای انقضای عادی بهره‌مند شود'
        );
    }

    public function testCorrectlyBoundOrdinarilyExpiredDocumentKeepsSliceOneBehavior(): void
    {
        $this->setLocalHome('https://clinic-a.example');
        $licenses = $this->licenseService();
        $expired = time() - 10 * 86400;
        $this->installDocument($licenses, [
            'domain' => 'clinic-a.example',
            'issued_at' => $expired - 365 * 86400,
            'expires_at' => $expired,
        ]);

        $state = $licenses->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
        $this->assertSame('expired', $state['reason']);
        $this->assertTrue((new SignedLicenseGate($licenses))->assert(LicenseGate::OP_PATIENT_CREATE)->allowed);
    }

    // =========================================================================
    // B) مرزِ واقعیِ محصول — مسدود برای کسب‌وکارِ جدید، باز برای داده/کارِ موجود
    // =========================================================================

    public function testMismatchedDomainClaimDeniesNewBusinessThroughRealProductServices(): void
    {
        $this->setLocalHome('https://clinic-a.example');
        $licenses = $this->licenseService();
        $this->installDocument($licenses, ['domain' => 'clinic-b.example']);

        $gate = new SignedLicenseGate($licenses);
        foreach (SignedLicenseGate::BLOCKED_UNDER_RESTRICTION as $op) {
            $this->assertFalse($gate->assert($op)->allowed, "{$op} با عدم‌تطابق دامنه باید مسدود شود");
        }

        // ۱) ثبت بیمار جدید — سرویسِ واقعیِ محصول.
        $before = $this->countRows('cpms_patients');
        try {
            App::patientService()->create([
                'first_name' => 'ن',
                'last_name' => 'مسدود',
                'mobile' => '09129990011',
            ], $this->secretaryUserId);
            $this->fail('ثبت بیمار جدید باید با عدم‌تطابق دامنه مسدود شود');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
            $this->assertSame(503, $e->httpStatus);
        }
        $this->assertSame($before, $this->countRows('cpms_patients'), 'هیچ بیمار جدیدی نباید ساخته شده باشد');

        // ۲) رزرو نوبت کارکنان — سرویسِ واقعیِ محصول.
        $slot = $this->makeSlot(2, '10:20');
        $before = $this->countRows('cpms_appointments');
        try {
            App::bookingService()->createByStaff(
                $this->secretaryUserId,
                $this->patientId,
                $this->clinicianId,
                $slot['date'],
                '10:20',
                'عدم‌تطابق دامنه'
            );
            $this->fail('رزرو نوبت جدید باید با عدم‌تطابق دامنه مسدود شود');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
            $this->assertSame(503, $e->httpStatus);
        }
        $this->assertSame($before, $this->countRows('cpms_appointments'), 'هیچ نوبتی نباید ساخته شده باشد');

        // ۳) مراجعهٔ بدون نوبت (ویزیت مستقل جدید) — سیم‌کشیِ واقعیِ App.
        $before = $this->countRows('cpms_visits');
        try {
            App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
            $this->fail('ثبت مراجعهٔ بدون نوبت باید با عدم‌تطابق دامنه مسدود شود');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
            $this->assertSame(503, $e->httpStatus);
        }
        $this->assertSame($before, $this->countRows('cpms_visits'), 'هیچ ویزیت جدیدی نباید ساخته شده باشد');
    }

    public function testMismatchedDomainClaimKeepsHistoricalDataAndInProgressCareOpen(): void
    {
        $this->setLocalHome('https://clinic-a.example');

        // ۱) داده و کارِ در جریان پیش از بروزِ عدم‌تطابق ساخته می‌شود
        //    (پیش از فعال‌سازی، پنجرهٔ فعال‌سازی کسب‌وکار جدید را مجاز می‌کند).
        $created = App::patientService()->create([
            'first_name' => 'ه',
            'last_name' => 'تاریخی',
            'mobile' => '09129990012',
        ], $this->secretaryUserId);
        $patientId = (int) $created['id'];
        $this->assertGreaterThan(0, $patientId);

        $apptAId = $this->makeAppointmentRow(time() + 3600, $patientId); // برای check-in امروز
        $apptBId = $this->makeAppointmentRow(time() + 2 * 86400, $patientId); // بدون ویزیت — برای لغو

        $visits = App::visitService();
        $visit = $visits->checkIn($this->secretaryUserId, $patientId, $apptAId);
        $visitId = (int) $visit['id'];
        $this->assertSame('waiting', (string) $visit['status']);

        // ۲) سندِ متصل به دامنهٔ دیگر نصب می‌شود.
        $licenses = $this->licenseService();
        $this->installDocument($licenses, ['domain' => 'clinic-b.example']);

        $state = $licenses->currentState();
        $this->assertSame(LicenseStatus::RESTRICTED, $state['status']);
        $this->assertSame('binding_mismatch', $state['reason']);

        // ۳) دادهٔ تاریخی: خواندن و به‌روزرسانی بیمار موجود مجاز می‌ماند.
        $row = App::patientService()->get($patientId);
        $this->assertSame($patientId, (int) $row['id']);
        $updated = App::patientService()->update($patientId, ['last_name' => 'ویرایش‌شده'], $this->secretaryUserId);
        $this->assertSame('ویرایش‌شده', (string) $updated['last_name']);

        // ۴) لغو نوبت موجود (بهداشتِ صف/بیمار) مجاز می‌ماند.
        $cancelled = App::bookingService()->cancelByStaff($this->secretaryUserId, $apptBId, 'لغو پس از عدم‌تطابق');
        $this->assertSame('cancelled_by_staff', (string) $cancelled['status']);

        // ۵) گردش‌کارِ بالینیِ ویزیتِ در جریان تا اتمام ادامه می‌یابد.
        $called = $visits->transition($this->doctorUserId, $visitId, 'call');
        $this->assertSame('called', (string) $called['status']);
        $started = $visits->transition($this->doctorUserId, $visitId, 'start');
        $this->assertSame('in_consultation', (string) $started['status']);
        $completed = $visits->transition($this->doctorUserId, $visitId, 'complete');
        $this->assertSame('consultation_completed', (string) $completed['status']);
    }

    // =========================================================================
    // C) متادیتای refresh — دامنهٔ canonical جاری در قراردادِ موجودِ Gateway
    // =========================================================================

    public function testRefreshSendsCurrentCanonicalDomainWithinPrivacyAllowlist(): void
    {
        $this->setLocalHome('https://www.Clinic-A.example');
        $installId = (new LicenseRepository(App::db()))->installId();

        $captured = [];
        add_filter('pre_http_request', function ($pre, array $args, string $url) use (&$captured, $installId) {
            $captured[] = ['url' => (string) $url, 'body' => (string) ($args['body'] ?? '')];

            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body' => (string) json_encode($this->httpFixtureDocument($installId)),
            ];
        }, 10, 3);

        $db = App::db();
        $licenses = new LicenseService(
            new LicenseRepository($db),
            new HttpVendorGateway(['server_url' => 'https://example.com/vendor']),
            $db
        );

        // سندِ fixture بدون ادعای دامنه است تا خودِ درخواست‌های خروجی سنجیده شود.
        $licenses->activateWithKey('key-domain-1');

        $expected = $this->canonicalLocalDomain();
        $this->assertSame('clinic-a.example', $expected);

        // فعال‌سازی از قبل domain را می‌فرستد (رفتار موجود — کنترل).
        $activation = $this->lastCapturedBody($captured);
        $this->assertSame($expected, $activation['domain'] ?? null, 'activation باید دامنهٔ canonical جاری را بفرستد');

        $captured = [];
        $licenses->refresh();
        $refresh = $this->lastCapturedBody($captured);
        $this->assertSame($expected, $refresh['domain'] ?? null, 'refresh باید دامنهٔ canonical جاری را بفرستد');
        $this->assertSame(
            [],
            array_diff(array_keys($refresh), self::REFRESH_ALLOWED_KEYS),
            'refresh فقط مجاز است کلیدهای allowlist را بفرستد'
        );

        // دامنهٔ محلی جاری تغییر کند → همان درخواستِ بعدی باید دامنهٔ جدید را بفرستد.
        $this->setLocalHome('https://clinic-b.example');
        $captured = [];
        $licenses->refresh();
        $refresh2 = $this->lastCapturedBody($captured);
        $this->assertSame('clinic-b.example', $refresh2['domain'] ?? null);
        $this->assertSame([], array_diff(array_keys($refresh2), self::REFRESH_ALLOWED_KEYS));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * تنظیم دامنهٔ محلیِ سایت — منبعِ canonical در تولید همان `home_url()` است.
     */
    private function setLocalHome(string $home): void
    {
        update_option('home', $home);
    }

    /**
     * دامنهٔ canonical محلی طبق قاعدهٔ موجودِ سرویس لایسنس:
     * lower-case + حذفِ `www.` پیشرو (بدون punycode/path/port).
     */
    private function canonicalLocalDomain(): string
    {
        $host = (string) parse_url((string) home_url(), PHP_URL_HOST);

        return (string) preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * سرویس لایسنس روی مخزن واقعی؛ Gatewayِ آفلاین (بدون I/O) — مسیرِ سندِ
     * دستی/آفلاین همان مسیرِ اعتبارسنجی امضا + ذخیرهٔ تولید است.
     */
    private function licenseService(): LicenseService
    {
        $db = App::db();

        return new LicenseService(new LicenseRepository($db), $this->offlineGateway(), $db);
    }

    private function offlineGateway(): VendorGateway
    {
        return new class implements VendorGateway {
            public function isConfigured(): bool
            {
                return false;
            }

            public function activate(array $request): array
            {
                throw new \RuntimeException('activate نباید در این تست صدا زده شود');
            }

            public function refresh(array $request): array
            {
                throw new \RuntimeException('refresh نباید در این تست صدا زده شود');
            }
        };
    }

    /**
     * سندِ امضاشدهٔ معتبر با کلیدِ تست؛ `$extra` شکل سند را تغییر می‌دهد
     * (مثلاً ادعای دامنه یا تاریخ انقضای دلخواه).
     *
     * @param array<string, mixed> $extra
     * @return array{payload: array<string, mixed>, signature_b64: string}
     */
    private function signedDocument(LicenseService $licenses, array $extra = []): array
    {
        $payload = array_merge([
            'product' => 'cpms',
            'license_id' => 'lic-domain-001',
            'install_id' => $licenses->installId(),
            'issued_at' => time() - 60,
            'expires_at' => time() + 30 * 86400,
            'revoked' => false,
            'suspended' => false,
            'plan' => 'annual',
            'entitlements' => ['features' => ['updates' => false]],
        ], $extra);

        $sig = sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->keypair)
        );

        return ['payload' => $payload, 'signature_b64' => base64_encode($sig)];
    }

    /**
     * نصبِ واقعیِ سند (اعتبارسنجی امضا + ذخیره) از مسیرِ تولید.
     *
     * @param array<string, mixed> $extra
     */
    private function installDocument(LicenseService $licenses, array $extra = []): void
    {
        $doc = $this->signedDocument($licenses, $extra);
        $licenses->activateWithDocument(
            (string) json_encode($doc['payload'], JSON_UNESCAPED_UNICODE),
            $doc['signature_b64']
        );
    }

    /**
     * پاسخِ fixture سرویس فروشنده — سندِ امضاشدهٔ بدون ادعای دامنه (legacy).
     *
     * @return array{payload: array<string, mixed>, signature_b64: string}
     */
    private function httpFixtureDocument(string $installId): array
    {
        $payload = [
            'product' => 'cpms',
            'license_id' => 'lic-domain-http',
            'install_id' => $installId,
            'issued_at' => time() - 60,
            'expires_at' => time() + 30 * 86400,
            'revoked' => false,
            'suspended' => false,
            'entitlements' => ['features' => ['updates' => false]],
        ];
        $sig = sodium_crypto_sign_detached(
            LicenseSignature::canonicalJson($payload),
            sodium_crypto_sign_secretkey($this->keypair)
        );

        return ['payload' => $payload, 'signature_b64' => base64_encode($sig)];
    }

    /**
     * @param list<array{url: string, body: string}> $captured
     * @return array<string, mixed>
     */
    private function lastCapturedBody(array $captured): array
    {
        $this->assertNotSame([], $captured, 'هیچ درخواست HTTP رهگیری نشد');
        $decoded = json_decode($captured[count($captured) - 1]['body'], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function countRows(string $table): int
    {
        $row = App::db()->fetchRow('SELECT COUNT(*) AS n FROM ' . App::db()->table($table));

        return (int) ($row['n'] ?? 0);
    }

    /**
     * ردیف‌های اسلات + نوبت با SQL خام (همان الگوی VisitLicenseGateTest) تا
     * check-inِ «امروز» بدون قیدِ min-lead سرویسِ رزرو ساخته شود.
     */
    private function makeAppointmentRow(int $atTs, ?int $patientId = null): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $dt = (new \DateTimeImmutable('@' . $atTs))->setTimezone(new \DateTimeZone('Asia/Tehran'));
        $slotDate = $dt->format('Y-m-d');
        $slotTime = $dt->format('H:i:s');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, (SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1), %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianId,
                $slotDate,
                $slotTime,
                $now,
                $now
            )
        );
        $slotId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                     (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time,
                      duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)
                 VALUES (1, (SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1), %s, %d, %d, %d, %s, %s, 20, %s, "confirmed", 0, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'DOM-' . bin2hex(random_bytes(6)),
                $patientId ?? $this->patientId,
                $this->clinicianId,
                $slotId,
                $slotDate,
                $slotTime,
                $dt->add(new \DateInterval('PT20M'))->format('H:i:s'),
                $now,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
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

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }
}
