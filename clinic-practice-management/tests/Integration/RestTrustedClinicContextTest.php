<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Rest\RestClinicContext;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * C6 — مرز REST مورد اعتماد Clinic (نه Phase 3).
 *
 * کلاینت درخواست می‌کند؛ سرور پس از عضویت فعال برقرار می‌کند.
 */
final class RestTrustedClinicContextTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $clinicA = 1;

    private int $clinicB = 2;

    private int $locA = 0;

    private int $locB = 0;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        $this->today = gmdate('Y-m-d');
        $this->locA = $this->primaryLocation($this->clinicA);
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    public function testUniqueMembershipWithoutHeaderBindsThatClinic(): void
    {
        $userId = $this->makeStaff('cpms_doctor');
        // صریح (نه fixture سراسری): تنها عضویت فعال کاربر = همین Clinic.
        cpms_test_seed_membership($userId, $this->clinicA, 'cpms_doctor');
        wp_set_current_user($userId);

        $res = $this->dispatch('GET', self::NS . '/reports');
        $this->assertSame(200, $res->get_status());
        $this->assertSame(null, ScopeContext::tryGet(), 'Scope درخواست باید بعد از REST پاک شود');
    }

    public function testNoMembershipDeniedEvenWhenExactlyOneClinic(): void
    {
        $userId = $this->makeUser('no_mem', 'subscriber');
        $user = get_userdata($userId);
        $user?->add_cap('cpms_report_read');
        wp_set_current_user($userId);

        $res = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(403, $res->get_status());
        $this->assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($res));
        $flat = (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('membership', strtolower($flat));
        $this->assertStringNotContainsString('عضو', $flat);
    }

    public function testTwoMembershipsWithoutHeaderRequireExplicitClinic(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-b');
        $userId = $this->makeStaff('cpms_doctor');
        App::membership_service()->create_membership($this->clinicA, $userId, 'cpms_doctor');
        App::membership_service()->create_membership($this->clinicB, $userId, 'cpms_doctor');
        wp_set_current_user($userId);

        $res = $this->dispatch('GET', self::NS . '/reports');
        $this->assertSame(400, $res->get_status());
        $this->assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($res));
    }

    public function testValidHeaderBindsRequestedClinicAndOrgFromDatabase(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-b2');
        $userId = $this->makeStaff('cpms_doctor');
        App::membership_service()->create_membership($this->clinicB, $userId, 'cpms_doctor');
        wp_set_current_user($userId);

        $orgB = (int) App::db()->fetchValue(
            'SELECT organization_id FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$this->clinicB]
        );
        $this->assertGreaterThan(0, $orgB);

        $captured = null;
        add_filter('rest_request_before_callbacks', static function ($response, $handler, $request) use (&$captured) {
            if ($request instanceof WP_REST_Request && $request->get_route() === '/clinic/v1/reports') {
                $captured = ScopeContext::tryGet();
            }

            return $response;
        }, 11, 3);

        $res = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicB]);
        $this->assertSame(200, $res->get_status());
        $this->assertInstanceOf(ClinicScope::class, $captured);
        $this->assertSame($this->clinicB, $captured->clinicId);
        $this->assertSame($orgB, $captured->organizationId);
        $this->assertSame(ClinicScope::SOURCE_EXPLICIT, $captured->source);
        $this->assertSame(null, ScopeContext::tryGet());
    }

    public function testMalformedClinicIdIsValidationFailed(): void
    {
        $userId = $this->makeStaff('cpms_doctor');
        wp_set_current_user($userId);

        foreach (['abc', '0', '-1', '1.5', '01'] as $bad) {
            $res = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => $bad]);
            $this->assertSame(422, $res->get_status(), $bad);
            $this->assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($res), $bad);
        }
    }

    public function testHeaderAndParamMismatchIsValidationFailed(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-mismatch');
        $userId = $this->makeStaff('cpms_doctor');
        App::membership_service()->create_membership($this->clinicA, $userId, 'cpms_doctor');
        App::membership_service()->create_membership($this->clinicB, $userId, 'cpms_doctor');
        wp_set_current_user($userId);

        $res = $this->dispatch(
            'GET',
            self::NS . '/reports',
            ['clinic_id' => $this->clinicB],
            ['X-CPMS-Clinic-Id' => (string) $this->clinicA]
        );
        $this->assertSame(422, $res->get_status());
        $this->assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($res));
    }

    public function testNonMemberExistingClinicIsUnavailableWithoutExistenceLeak(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-other');
        $userId = $this->makeStaff('cpms_doctor');
        App::membership_service()->create_membership($this->clinicA, $userId, 'cpms_doctor');
        wp_set_current_user($userId);

        $res = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicB]);
        $this->assertSame(403, $res->get_status());
        $this->assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($res));
        $flat = (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString((string) $this->clinicB, $flat);
        $this->assertStringNotContainsString('عضو', $flat);
    }

    public function testUnknownClinicIdMatchesNonMemberEnvelope(): void
    {
        $userId = $this->makeStaff('cpms_doctor');
        wp_set_current_user($userId);

        $missing = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => '99991']);
        $this->assertSame(403, $missing->get_status());
        $this->assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($missing));
    }

    public function testSuspendedMembershipIsUnavailable(): void
    {
        $userId = $this->makeStaff('cpms_doctor');
        $membershipId = cpms_test_seed_membership($userId, $this->clinicA, 'cpms_doctor');
        $membership = App::membership_service()->membership_for($this->clinicA, $userId);
        $this->assertNotNull($membership);
        App::membership_service()->suspend_membership($membershipId);
        wp_set_current_user($userId);

        $res = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(403, $res->get_status());
        $this->assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($res));
    }

    public function testLocationBelongingToClinicIsBound(): void
    {
        $userId = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($userId, $this->clinicA, 'cpms_doctor');
        wp_set_current_user($userId);
        $captured = null;
        add_filter('rest_request_before_callbacks', static function ($response, $handler, $request) use (&$captured) {
            if ($request instanceof WP_REST_Request && $request->get_route() === '/clinic/v1/reports') {
                $captured = ScopeContext::tryGet();
            }

            return $response;
        }, 11, 3);

        $res = $this->dispatch('GET', self::NS . '/reports', [], [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
            'X-CPMS-Location-Id' => (string) $this->locA,
        ]);
        $this->assertSame(200, $res->get_status());
        $this->assertInstanceOf(ClinicScope::class, $captured);
        $this->assertSame($this->locA, $captured->locationId);
    }

    public function testLocationOfOtherClinicIsUnavailable(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-loc-b');
        $this->locB = $this->insertLocation($this->clinicB, 'rest-ctx-loc-b');
        $userId = $this->makeStaff('cpms_doctor');
        App::membership_service()->create_membership($this->clinicA, $userId, 'cpms_doctor');
        wp_set_current_user($userId);

        $res = $this->dispatch('GET', self::NS . '/reports', [], [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
            'X-CPMS-Location-Id' => (string) $this->locB,
        ]);
        $this->assertSame(403, $res->get_status());
        $this->assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($res));
    }

    public function testLocationWithoutClinicIdDoesNotInferClinic(): void
    {
        $userId = $this->makeStaff('cpms_doctor');
        wp_set_current_user($userId);

        $res = $this->dispatch('GET', self::NS . '/reports', [], [
            'X-CPMS-Location-Id' => (string) $this->locA,
        ]);
        $this->assertSame(422, $res->get_status());
        $this->assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($res));
    }

    public function testLeftoverScopeIsIgnoredWhenHeaderNamesAnotherClinic(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-left');
        $this->seedVisitPair();
        $userId = $this->makeStaff('cpms_doctor');
        App::membership_service()->create_membership($this->clinicA, $userId, 'cpms_doctor');
        App::membership_service()->create_membership($this->clinicB, $userId, 'cpms_doctor');
        $acc = get_userdata($userId);
        $acc?->add_cap('cpms_patient_read');

        ScopeContext::set(ClinicScope::forClinic($this->clinicA));
        wp_set_current_user($userId);
        $res = $this->dispatch('GET', self::NS . '/reports/visits', [
            'from' => $this->today,
            'to' => $this->today,
        ], ['X-CPMS-Clinic-Id' => (string) $this->clinicB]);
        $this->assertSame(200, $res->get_status());
        $payload = $this->payload($res);
        $this->assertStringContainsString('IsoB', (string) json_encode($payload));
        $this->assertStringNotContainsString('IsoA', (string) json_encode($payload));
    }

    public function testExactOneClinicIdNotOneStillRequiresMembership(): void
    {
        global $wpdb;
        $newId = 41;
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('UPDATE ' . $wpdb->prefix . 'cpms_clinics SET id = ' . $newId); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        App::resetScope();

        $userId = $this->makeStaff('cpms_doctor');
        // عضویت روی شناسهٔ واقعیِ جدیدِ Clinic (۴۱) — نه «Clinic 1».
        cpms_test_seed_membership($userId, $newId, 'cpms_doctor');
        wp_set_current_user($userId);

        $ok = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $newId]);
        $this->assertSame(200, $ok->get_status());

        $denied = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => '1']);
        $this->assertSame(403, $denied->get_status());
        $this->assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($denied));
    }

    public function testPatientNotificationsDoNotRequireClinicHeader(): void
    {
        $userId = $this->makeUser('ctx_pat', 'cpms_patient');
        wp_set_current_user($userId);
        $res = $this->dispatch('GET', self::NS . '/notifications');
        $this->assertSame(200, $res->get_status());
    }

    public function testPublicHealthDoesNotRequireClinicHeader(): void
    {
        wp_set_current_user(0);
        $request = new WP_REST_Request('GET', self::NS . '/health');
        $res = rest_do_request($request);
        $this->assertSame(200, $res->get_status());
    }

    public function testPermissionCallbackDoesNotBindScope(): void
    {
        $userId = $this->makeStaff('cpms_doctor');
        wp_set_current_user($userId);
        ScopeContext::clear();

        $handlers = rest_get_server()->get_routes()['/clinic/v1/reports'] ?? null;
        $this->assertNotNull($handlers);
        $request = new WP_REST_Request('GET', '/clinic/v1/reports');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $allowed = call_user_func($handlers[0]['permission_callback'], $request);
        $this->assertTrue($allowed);
        $this->assertSame(null, ScopeContext::tryGet());
    }

    public function testExportRestPutsRequestClinicOnJobNotLeftoverScope(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-export');
        $this->seedVisitPair();
        $userId = $this->makeStaff('cpms_doctor');
        App::membership_service()->create_membership($this->clinicA, $userId, 'cpms_doctor');
        App::membership_service()->create_membership($this->clinicB, $userId, 'cpms_doctor');
        $user = get_userdata($userId);
        $user?->add_cap('cpms_export');
        $user?->add_cap('cpms_patient_read');

        ScopeContext::set(ClinicScope::forClinic($this->clinicA));
        wp_set_current_user($userId);
        $res = $this->dispatch('POST', self::NS . '/reports/visits/export', [
            'from' => $this->today,
            'to' => $this->today,
        ], ['X-CPMS-Clinic-Id' => (string) $this->clinicB]);
        $this->assertSame(202, $res->get_status());
        $jobId = (int) $this->payload($res)['job_id'];
        $payloadJson = (string) App::db()->fetchValue(
            'SELECT payload_json FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
        $payload = json_decode($payloadJson, true);
        $this->assertIsArray($payload);
        $this->assertSame($this->clinicB, (int) $payload['clinic_id']);
    }

    public function testSuspendedOrganizationIsUnavailable(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
                 VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Org Suspended',
                'org-susp-' . bin2hex(random_bytes(2)),
                'suspended',
                $now,
                $now
            )
        );
        $orgId = (int) $wpdb->insert_id;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicB,
                $orgId,
                'Suspended clinic',
                'clinic-susp',
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $userId = $this->makeStaff('cpms_doctor');
        App::membership_service()->create_membership($this->clinicB, $userId, 'cpms_doctor');
        wp_set_current_user($userId);

        $res = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicB]);
        $this->assertSame(403, $res->get_status());
        $this->assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($res));
    }

    /**
     * C6 repair (بند ۴ Owner) — شاهدِ قابل‌اجرا:
     * staff با coarse permissionِ کافی ولی **بدون عضویت فعال** باید توسط همین
     * مرز رد شود (fail‑closed). در نصب «تنها یک Clinic» هم exact‑one مجوز
     * نمی‌سازد؛ مسیرِ fallbackِ سیستمی عمداً در Establisher صدا زده نمی‌شود.
     */
    public function testStaffActorWithoutActiveMembershipIsDeniedByBoundary(): void
    {
        // پزشک: REPORT_READ را در الگوی نقش دارد (منشی ندارد) — یعنی coarse
        // permissionِ این route را واقعاً pass می‌کند و فقط عضویت کم دارد.
        $userId = $this->makeUser('ctx_no_member_staff', 'cpms_doctor');
        $this->assertTrue(
            user_can($userId, \ClinicCore\Auth\RolesAndCapabilities::REPORT_READ),
            'پیش‌شرط تست: coarse permissionِ لازم را داشته باشد'
        );
        $this->assertSame(
            [],
            App::membership_service()->active_memberships_for_user($userId),
            'پیش‌شرط تست: هیچ عضویت فعالی نداشته باشد'
        );
        wp_set_current_user($userId);

        $withHeader = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(403, $withHeader->get_status());
        $this->assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($withHeader));

        $withoutHeader = $this->dispatch('GET', self::NS . '/reports');
        $this->assertSame(403, $withoutHeader->get_status());
        $this->assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($withoutHeader));
    }

    public function testStaffCancelBindsAndPatientCancelDoesNotRequireHeader(): void
    {
        $secretary = $this->makeStaff('cpms_secretary');
        $patient = $this->makeUser('ctx_cancel_pat', 'cpms_patient');
        // صریح: منشی عضو فعال Clinic A است (قبلاً از fixture سراسری می‌آمد).
        cpms_test_seed_membership($secretary, $this->clinicA, 'cpms_secretary');
        wp_set_current_user($secretary);
        $staff = $this->dispatch('POST', self::NS . '/appointments/1/cancel', ['reason' => 'test'], [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
        ]);
        $this->assertNotSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($staff));
        $this->assertNotSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($staff));

        wp_set_current_user($patient);
        $pat = $this->dispatch('POST', self::NS . '/appointments/1/cancel', ['reason' => 'test']);
        $this->assertNotSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($pat));
        $this->assertNotSame('CLINIC_VALIDATION_FAILED', $this->errorCode($pat));
    }

    /**
     * C6 repair (بند ۷ Owner) — شاهدِ قابل‌اجرا برای چرخهٔ حیات Scope.
     *
     * WordPress فیلتر `rest_request_after_callbacks` را *پس از* callback صدا
     * می‌زند؛ اگر handler استثنای مهار‌نشدده بدهد، آن فیلتر هرگز اجرا نمی‌شود.
     * این تست همان واقعیت را اثبات می‌کند (نشت در همان request/فرآیند) و سپس
     * اثبات می‌کند net پایانیِ مرز (restoreAllPending) Scope قبلیِ مشروع را
     * بازمی‌گرداند — نه هیچ مقدار پیش‌فرضی.
     */
    public function testUnhandledHandlerExceptionLeaksScopeUntilSafetyNetRuns(): void
    {
        $userId = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($userId, $this->clinicA, 'cpms_doctor');
        wp_set_current_user($userId);

        $previous = ClinicScope::forClinic($this->clinicA);
        ScopeContext::set($previous);

        /*
         * شبیه‌سازی «شکست handler» روی route واقعی: فیلتری با priority بالاتر
         * **پس از** bind مرز اجرا می‌شود و استثنای مهار‌نشدده می‌دهد — دقیقاً
         * همان موقعیتی که در آن WordPress فیلتر after را اجرا نمی‌کند. هیچ
         * route ساختگی ثبت نمی‌شود و routeهای افزونه دست‌نخورده‌اند.
         */
        $bomber = static function ($response, $handler, $req) {
            if ($req instanceof WP_REST_Request && $req->get_route() === '/clinic/v1/reports') {
                throw new \RuntimeException('cpms-probe-handler-failure');
            }

            return $response;
        };
        add_filter('rest_request_before_callbacks', $bomber, 11, 3);

        $thrown = null;
        try {
            $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        remove_filter('rest_request_before_callbacks', $bomber, 11);
        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'پیش‌شرط: probe باید استثنای مهار‌نشدده بدهد');
        // (۱) رفتار خام WP: after_callbacks اجرا نمی‌شود → Scope درخواستِ ناکام
        // در فرآیند باقی می‌ماند (این همان نشتی است که net پایانی بسته می‌شود).
        $leaked = ScopeContext::tryGet();
        $this->assertInstanceOf(ClinicScope::class, $leaked, 'اثبات نشت: scope پس از استثنای handler پاک نشده است');
        $this->assertNotSame($previous, $leaked, 'scopeِ باقی‌مانده متعلق به درخواستِ ناکام است نه scope قبلی');

        // (۲) خطای ایمنی: جفت‌های بازمانده → restore قبلیِ واقعی.
        RestClinicContext::restoreAllPending();
        $this->assertSame($previous, ScopeContext::tryGet(), 'restore باید Scope قبلی را برگرداند');

        // (۳) درخواست بعدی در همان فرآیند آلوده نیست: scope قبلی پاک می‌شود و
        // bind تازه بر اساس عضویت/هدر انجام می‌شود.
        ScopeContext::clear();
        $ok = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(200, $ok->get_status());
        $this->assertNull(ScopeContext::tryGet(), 'پایان درخواست موفق باید scope را بازگردانی کند');
    }

    public function testRestoreSafetyNetIsNoOpWhenNothingPending(): void
    {
        // هیچ درخواست bind‌شده‌ای در جریان نیست → net پایانی نباید Scope
        // مشروعِ جاری (مثلاً scope یک job) را پاک کند.
        $job = ClinicScope::forClinic($this->clinicA);
        ScopeContext::set($job);
        RestClinicContext::restoreAllPending();
        $this->assertSame($job, ScopeContext::tryGet());
        ScopeContext::clear();
    }


    /**
     * بند ۴ (batch بازبینی) — مسیر «پاسخ خطا» (WP_Error) هم restore می‌شود.
     *
     * در این افزونه الگوی مستقر `guard()` هر استثنای callback را به
     * `WP_Error` تبدیل می‌کند (تست جدا: `QueueController::guard` —
     * `CLINIC_INTERNAL_ERROR` 500)؛ یعنی در عمل «استثنای مهارنشدۀ callback»
     * به WordPress نمی‌رسد و مسیرِ واقعیِ خطا، WP_Error است. مسیرِ WP_Error
     * حتماً از `rest_request_after_callbacks` می‌گذرد ⇒ restore انجام می‌شود.
     */
    public function testErrorResponsePathStillRestoresScope(): void
    {
        $userId = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($userId, $this->clinicA, 'cpms_doctor');
        wp_set_current_user($userId);

        // شناسهٔ ناموجود ⇒ خطای دامنه در آینهٔ WP_Error (نه استثنای مهارنشدۀ callback).
        $bad = $this->dispatch('POST', self::NS . '/visits/999999/call', []);
        $this->assertGreaterThanOrEqual(400, $bad->get_status());
        $this->assertNull(ScopeContext::tryGet(), 'مسیر WP_Error هم باید scope را restore کند');

        // و بلافاصله یک درخواست سالمِ همان کاربر: scope نباید از «پاک‌سازی» آسیب ببیند.
        $ok = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(200, $ok->get_status());
        $this->assertNull(ScopeContext::tryGet(), 'درخواست پس از پاسخِ خطا هم ایزوله است');
    }

    /**
     * پاک‌سازیِ تکرارشونده: پس از drain شدنِ جفت‌های بازمانده (net پایانی)،
     * bind/restore بعدی دوباره کار می‌کند ⇒ ساختارِ pending در فرآیندِ بلند
     * انباشته نمی‌شود.
     */
    public function testPendingPairDrainsAndNextRequestStillRestores(): void
    {
        $userId = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($userId, $this->clinicA, 'cpms_doctor');
        wp_set_current_user($userId);

        $bomber = static function ($response, $handler, $req) {
            if ($req instanceof WP_REST_Request && $req->get_route() === '/clinic/v1/reports') {
                throw new \RuntimeException('cpms-probe-handler-failure');
            }

            return $response;
        };
        add_filter('rest_request_before_callbacks', $bomber, 11, 3);
        try {
            $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
            $this->fail('پیش‌شرط: probe باید استثنا بدهد');
        } catch (\RuntimeException $e) {
            $this->assertSame('cpms-probe-handler-failure', $e->getMessage());
        }
        remove_filter('rest_request_before_callbacks', $bomber, 11);

        $this->assertNotNull(ScopeContext::tryGet(), 'جفتِ بازمانده روی stack است (نشتِ خامِ مسیرِ غیر‑after)');
        RestClinicContext::restoreAllPending();
        $this->assertNull(ScopeContext::tryGet(), 'net پایانی باید pending را drain کند');

        $again = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(200, $again->get_status());
        $this->assertNull(ScopeContext::tryGet(), 'پس از drain، bind/restore بعدی سالم کار می‌کند');
    }

    /** Scope صریحِ از‌پیش‌موجود (مثلاً job) نباید توسط پاک‌سازیِ REST پاک شود. */
    public function testPreExistingExplicitScopeSurvivesRequestAndRestoresAfterwards(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-pre');
        $userId = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($userId, $this->clinicA, 'cpms_doctor');
        cpms_test_seed_membership($userId, $this->clinicB, 'cpms_doctor');
        wp_set_current_user($userId);

        $job = ClinicScope::forClinic($this->clinicA);
        ScopeContext::set($job);

        $seenInCallback = null;
        $spy = static function ($response, $handler, $req) use (&$seenInCallback) {
            if ($req instanceof WP_REST_Request && $req->get_route() === '/clinic/v1/reports') {
                $seenInCallback = ScopeContext::tryGet();
            }

            return $response;
        };
        add_filter('rest_request_before_callbacks', $spy, 11, 3);
        $res = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicB]);
        remove_filter('rest_request_before_callbacks', $spy, 11);

        $this->assertSame(200, $res->get_status());
        $this->assertInstanceOf(ClinicScope::class, $seenInCallback);
        $this->assertSame($this->clinicB, $seenInCallback->clinicId, 'در طول درخواست، scope صریحِ request جانشین scope job می‌شود');
        $this->assertSame($job, ScopeContext::tryGet(), 'پس از پایان درخواست، scope قبلی (job) باید دست‌نخورده برگردد');
        ScopeContext::clear();
    }

    /** انزوای درخواست‌های پشت‌سرهم روی دو Clinic (B→A و تکرار) — بدون نشت. */
    public function testSequentialClinicRequestsDoNotLeakScope(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-seq');
        $userId = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($userId, $this->clinicA, 'cpms_doctor');
        cpms_test_seed_membership($userId, $this->clinicB, 'cpms_doctor');
        wp_set_current_user($userId);

        $seen = [];
        $spy = static function ($response, $handler, $req) use (&$seen) {
            if ($req instanceof WP_REST_Request && $req->get_route() === '/clinic/v1/reports') {
                $scope = ScopeContext::tryGet();
                $seen[] = $scope?->clinicId;
            }

            return $response;
        };
        add_filter('rest_request_before_callbacks', $spy, 11, 3);
        foreach ([$this->clinicB, $this->clinicA, $this->clinicB] as $clinicId) {
            $res = $this->dispatch('GET', self::NS . '/reports', [], ['X-CPMS-Clinic-Id' => (string) $clinicId]);
            $this->assertSame(200, $res->get_status(), 'clinic ' . $clinicId);
            $this->assertNull(ScopeContext::tryGet(), 'هیچ scopeی نباید به درخواست بعدی برسد');
        }
        remove_filter('rest_request_before_callbacks', $spy, 11);

        $this->assertSame([$this->clinicB, $this->clinicA, $this->clinicB], $seen, 'هر درخواست دقیقاً scope خودش را دیده است');
    }

    /**
     * `rest_do_request()` تودرتو: درخواستِ درون‌ی باید scope خودش را ببندد و در
     * پایان همان scope بیرونی را بازگرداند (LIFO) — نه scope درون‌ی را به بیرون
     * نشت دهد و نه scope بیرونی را پاک کند.
     */
    public function testNestedRestDispatchRestoresOuterScope(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-nested');
        $userId = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($userId, $this->clinicA, 'cpms_doctor');
        cpms_test_seed_membership($userId, $this->clinicB, 'cpms_doctor');
        wp_set_current_user($userId);

        $observed = [];
        $innerClinic = $this->clinicB;
        $nested = static function ($response, $handler, $req) use (&$observed, $innerClinic) {
            static $inside = false; // وگرنه درخواستِ درون‌ی دوباره همین فیلتر را صدا می‌زند
            if ($inside) {
                return $response;
            }
            if (!($req instanceof WP_REST_Request) || $req->get_route() !== '/clinic/v1/reports') {
                return $response;
            }
            $inside = true;
            $outer = ScopeContext::tryGet();
            $observed['outer_before_inner'] = $outer?->clinicId;

            $innerRequest = new WP_REST_Request('GET', '/clinic/v1/reports');
            $innerRequest->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
            $innerRequest->set_header('X-CPMS-Clinic-Id', (string) $innerClinic);
            $inner = rest_do_request($innerRequest);
            $observed['inner_status'] = $inner->get_status();
            // پس از پایان درخواست درون‌ی، scope باید به مقدار بیرونی برگردد:
            $observed['after_inner'] = ScopeContext::tryGet()?->clinicId;
            $inside = false;

            return $response;
        };
        add_filter('rest_request_before_callbacks', $nested, 11, 3);
        $outer = $this->dispatch('GET', self::NS . '/reports', [], [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
        ]);
        remove_filter('rest_request_before_callbacks', $nested, 11);

        $this->assertSame(200, $outer->get_status());
        $this->assertSame($this->clinicA, $observed['outer_before_inner'] ?? null, 'بیرونی scope خودش را دارد');
        $this->assertSame(200, $observed['inner_status'] ?? null, 'درخواست تودرتو مجاز است');
        $this->assertSame($this->clinicA, $observed['after_inner'] ?? null, 'restore درون‌ی scope بیرونی را خراب نکرد');
        $this->assertNull(ScopeContext::tryGet(), 'هیچ نشتی از درخواست تودرتو به بیرون نمی‌ماند');
    }

    // ================= fixtures =================

    private function makeStaff(string $role): int
    {
        return $this->makeUser('ctx_' . $role, $role);
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . bin2hex(random_bytes(2)) . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function insertClinic(int $id, string $slug): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id,
                $orgId,
                'Clinic ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        self::assertNotFalse($ok);
        App::resetScope();
    }

    private function insertLocation(int $clinicId, string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function primaryLocation(int $clinicId): int
    {
        global $wpdb;
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d AND is_primary = 1 LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function seedVisitPair(): void
    {
        if ($this->locB <= 0) {
            $this->locB = $this->insertLocation($this->clinicB, 'rest-ctx-vis-b');
        }
        $doctor = $this->makeStaff('cpms_doctor');
        App::membership_service()->create_membership($this->clinicA, $doctor, 'cpms_doctor');
        if (App::membership_service()->membership_for($this->clinicB, $doctor) === null) {
            App::membership_service()->create_membership($this->clinicB, $doctor, 'cpms_doctor');
        }
        $clinicianId = $this->insertClinician($this->clinicA, $doctor, 'Dr Ctx');
        $patientA = $this->insertPatient($this->clinicA, 'IsoA');
        $patientB = $this->insertPatient($this->clinicB, 'IsoB');
        $this->insertVisit($this->clinicA, $this->locA, $clinicianId, $patientA);
        $this->insertVisit($this->clinicB, $this->locB, $clinicianId, $patientB);
    }

    private function insertClinician(int $homeClinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $homeClinicId,
                $name,
                $wpUserId,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $lastName): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'MR-CTX-' . $seq . '-' . $clinicId,
                'Patient',
                $lastName,
                '0913' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertVisit(int $clinicId, int $locationId, int $clinicianId, int $patientId): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                     (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, called_at, active, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, "walk_in", "waiting", %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $locationId,
                $clinicianId,
                $patientId,
                $this->today,
                $this->today . ' 10:00:00.000',
                $this->today . ' 10:00:00.000',
                $this->today . ' 10:05:00.000',
                $now,
                $now
            )
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $body = [], array $headers = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        return rest_do_request($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Response $res): array
    {
        $body = $res->get_data();
        if (is_array($body) && array_key_exists('data', $body)) {
            return is_array($body['data']) ? $body['data'] : [];
        }

        return is_array($body) ? $body : [];
    }

    private function errorCode(WP_REST_Response $res): string
    {
        $body = $res->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code();
        }

        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
    }
}
