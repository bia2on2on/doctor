<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
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
        $membershipB = App::membership_service()->create_membership($this->clinicB, $userId, 'cpms_doctor');
        $user = get_userdata($userId);
        $user?->add_cap('cpms_export');
        $user?->add_cap('cpms_patient_read');
        // Phase 3 Slice 4 — Cap سراسری مجوزِ Clinic نیست: قصدِ این تست عبور از
        // مسیرِ Export و اثباتِ «Clinic درخواست روی Job» است، پس مجوزِ scopedِ
        // EXPORT از عضویتِ پایدارِ همان Clinicِ درخواست (B) اعطا می‌شود.
        App::membership_service()->set_capability(
            $membershipB,
            \ClinicCore\Auth\RolesAndCapabilities::EXPORT,
            'grant'
        );

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

    // ================= C6-F (تفحصی) — طبقه‌بندی route‌ها: شواهد مالکیت Per‑Object =================
    /*
     * این دو تست «مشخصهٔ جداسازی مالکیت» را می‌سنجد، نه وضعیت فعلی؛ یعنی اگر
     * قرمز شوند، نشتِ Cross‑Tenant کدِ Product است نه نقص تست (طبق §۹، شاهد
     * حفظ می‌شود). مرجع کد:
     *  - `ClinicalService::finalizePrescription` (src/Application/Clinical/ClinicalService.php:372)
     *    با `PrescriptionRepository::findForUpdate` (src/Infrastructure/Repository/PrescriptionRepository.php:73)
     *    — کوئری فقط `WHERE id = %d`، بدون `clinic_id`.
     *  - `SmsService::logs` (src/Application/Notifications/SmsService.php:557)
     *    — کوئری بدون فیلتر `clinic_id` در حالی که جدول ستون tenant دارد.
     */

    public function testStaffCannotFinalizePrescriptionOfAnotherClinic(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-rx-authz');
        $this->seedVisitPair();
        $doctor = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($doctor, $this->clinicA, 'cpms_doctor');
        $clinicianA = $this->insertClinician($this->clinicA, $doctor, 'Dr RxAuthz');
        wp_set_current_user($doctor);

        // نسخه‌ای که متعلق به Clinic B است (بیمار و ویزیت B) — با INSERT صریح.
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $visitB = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_visits WHERE clinic_id = %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicB
            )
        );
        $this->assertGreaterThan(0, $visitB, 'پیش‌شرط: ویزیتِ Clinic B از seedVisitPair');
        $patientB = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT patient_id FROM ' . $wpdb->prefix . 'cpms_visits WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $visitB
            )
        );
        $seq = random_int(100000, 9999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_prescriptions
                     (clinic_id, prescription_number, visit_id, patient_id, clinician_id, status, is_patient_visible, created_at, updated_at)
                 VALUES (%d, %s, %d, %d, %d, "draft", 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicB,
                'RX-B-' . $seq,
                $visitB,
                $patientB,
                $clinicianA,
                $now,
                $now
            )
        );
        $rxId = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $rxId);

        $res = $this->dispatch(
            'POST',
            self::NS . '/prescriptions/' . $rxId . '/finalize',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinicA]
        );
        // قرارداد «safe not‑found» (کتاب‌الگوی فعلی: CLINIC_NOT_FOUND در
        // BookingService/ClinicalService) — نه 403 که وجود رکورد را فاش کند.
        $this->assertSame(404, $res->get_status(), 'پاسخ باید همان safe‑not‑found باشد: ' . (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE));
        $this->assertSame('CLINIC_NOT_FOUND', $this->errorCode($res));

        $finalized = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . $wpdb->prefix . 'cpms_prescriptions WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $rxId
            )
        );
        $this->assertNotSame(
            'finalized',
            $finalized,
            'C6‑F (Class A انتظار): نسخهٔ Clinic دیگر نهایی شد — status=' . $finalized
                . ', http=' . $res->get_status()
                . ', rx=' . $rxId . ' (clinic_id=' . $this->clinicB . '), actor clinic=' . $this->clinicA
                . ' | شاهد: PrescriptionRepository::findForUpdate WHERE id-only'
        );
    }

    public function testSmsLogsAreScopedToTheBoundClinic(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-sms-authz');
        $manager = $this->makeStaff('cpms_manager');
        cpms_test_seed_membership($manager, $this->clinicA, 'cpms_manager');
        wp_set_current_user($manager);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(100000, 9999999);
        foreach ([
            ['clinic' => $this->clinicA, 'marker' => 'A' . $seq, 'mobile' => '0912' . sprintf('%07d', $seq % 10000000)],
            ['clinic' => $this->clinicB, 'marker' => 'B' . $seq, 'mobile' => '0913' . sprintf('%07d', ($seq + 1) % 10000000)],
        ] as $row) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_sms_messages
                         (clinic_id, event, recipient, message, status, attempts, max_attempts, created_at, updated_at)
                     VALUES (%d, %s, %s, %s, "SENT", 1, 3, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $row['clinic'],
                    'reminder',
                    $row['mobile'],
                    'cpms-authz-' . $row['marker'],
                    $now,
                    $now
                )
            );
            $this->assertGreaterThan(0, (int) $wpdb->insert_id, 'پیش‌شرط: درج ردیف SMS در ' . $row['marker']);
        }

        $res = $this->dispatch(
            'GET',
            self::NS . '/sms/logs',
            ['per_page' => 100, 'page' => 1],
            ['X-CPMS-Clinic-Id' => (string) $this->clinicA]
        );
        $this->assertSame(200, $res->get_status(), 'مسیر SMS logs باید برای manager دارای Cap باز باشد');
        $data = (array) $res->get_data();
        $items = (array) ($data['data']['items'] ?? []);
        $flat = (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE);

        // Class D guard: اگر Envelope/ساختار پاسخ خوانده نشود، ادعای «نشت» بی‌معناست.
        $this->assertIsArray($data['data'] ?? null, 'Envelope انتظار: {data:{items,total,…}} — پاسخ: ' . $flat);
        $this->assertNotEmpty($items, 'هیچ ردیفی خوانده نشد (پیش‌شرط fixture یا Envelope) — پاسخ: ' . $flat);
        $this->assertStringContainsString('cpms-authz-A' . $seq, $flat);
        $this->assertStringNotContainsString(
            'cpms-authz-B' . $seq,
            $flat,
            'ردیف Clinic دیگر نباید در لاگ SMS دیده شود (شماره موبایل/متن → PHI) — C6‑F'
        );
        // COUNT هم باید tenant‑scoped باشد (وگرنه total تعداد کل tenantها را می‌دهد)
        $this->assertSame(1, (int) ($data['data']['total'] ?? 0), 'total باید فقط شمارشِ Clinic خودی باشد');
        $this->assertStringNotContainsString('0913', $flat, 'موبایل ردیف Clinic دیگر نباید در پاسخ باشد');
    }

    /**
     * §۴ (fix batch) — نسخهٔ Clinic A در context A نهایی می‌شود؛ نسخهٔ Clinic B
     * در همان context **نه** (نه به‌عنوان موفق، نه با افشای وجود).
     */
    public function testPrescriptionFinalizeIsScopedToTrustedClinicForMultiMembershipDoctor(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-rx-scope');
        $this->seedVisitPair();
        $doctor = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($doctor, $this->clinicA, 'cpms_doctor');
        cpms_test_seed_membership($doctor, $this->clinicB, 'cpms_doctor');
        // One Clinician profile per WP user (u_clinician_user UNIQUE): multi-Clinic
        // participation is membership, not a second clinician row. The previous
        // second insert silently failed and persisted clinician_id = 0 on rxB.
        $clinicianA = $this->insertClinician($this->clinicA, $doctor, 'Dr RxScopeA');
        $clinicianB = $clinicianA;
        wp_set_current_user($doctor);

        $visitA = $this->lastVisitId($this->clinicA);
        $visitB = $this->lastVisitId($this->clinicB);
        $rxA = $this->insertRx($this->clinicA, $visitA, $clinicianA);
        $rxB = $this->insertRx($this->clinicB, $visitB, $clinicianB);

        // context A → نسخهٔ A مجاز است
        $ok = $this->dispatch('POST', self::NS . '/prescriptions/' . $rxA . '/finalize', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(200, $ok->get_status(), 'نسخهٔ هم‌Clinic باید نهایی شود: ' . (string) json_encode($ok->get_data(), JSON_UNESCAPED_UNICODE));
        $this->assertSame('finalized', $this->rxStatus($rxA));

        // context A → نسخهٔ B رد + بدون هیچ جهش
        $denied = $this->dispatch('POST', self::NS . '/prescriptions/' . $rxB . '/finalize', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(404, $denied->get_status());
        $this->assertSame('CLINIC_NOT_FOUND', $this->errorCode($denied));
        $this->assertSame('draft', $this->rxStatus($rxB), 'هیچ جهشی روی نسخهٔ Clinic دیگر رخ نمی‌دهد');

        // افشای وجود: پاسخِ «ناموجود» و «متعلق به Clinic دیگر» یکی است
        $ghost = $this->dispatch('POST', self::NS . '/prescriptions/987654321/finalize', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(404, $ghost->get_status());
        $this->assertSame($this->errorCode($ghost), $this->errorCode($denied));
        $this->assertSame(
            (string) json_encode($ghost->get_data()),
            (string) json_encode($denied->get_data()),
            'بدنهٔ پاسخ نباید «نسخهٔ Clinic دیگر» را از «ناموجود» متمایز کند'
        );

        // context B → همان کاربر، همان نسخهٔ B: مجاز (ثبتهای Per‑Clinic واقعی‌اند)
        $okB = $this->dispatch('POST', self::NS . '/prescriptions/' . $rxB . '/finalize', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicB]);
        $this->assertSame(200, $okB->get_status(), 'در context B نسخهٔ B باید نهایی شود: ' . (string) json_encode($okB->get_data(), JSON_UNESCAPED_UNICODE));
        $this->assertSame('finalized', $this->rxStatus($rxB));
    }

    /**
     * §۴ — سوییچ پیاپی context (A→B→A) مالکیت را درپیش‌نگذشتهٔ درخواست
     * جاری نمی‌کند (Scope به‌ازای هر درخواست bind/restore می‌شود).
     */
    public function testSequentialContextSwitchDoesNotLeakPrescriptionOwnership(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-rx-seq');
        $this->seedVisitPair();
        $doctor = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($doctor, $this->clinicA, 'cpms_doctor');
        cpms_test_seed_membership($doctor, $this->clinicB, 'cpms_doctor');
        // Same single-profile rule as above: rxB carries the actor's real clinician.
        $clinicianA = $this->insertClinician($this->clinicA, $doctor, 'Dr RxSeqA');
        $clinicianB = $clinicianA;
        wp_set_current_user($doctor);

        $rxA = $this->insertRx($this->clinicA, $this->lastVisitId($this->clinicA), $clinicianA);
        $rxB = $this->insertRx($this->clinicB, $this->lastVisitId($this->clinicB), $clinicianB);

        // A (رد B) → B (موفق B) → A (موفق A)
        $this->assertSame(404, $this->dispatch('POST', self::NS . '/prescriptions/' . $rxB . '/finalize', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA])->get_status());
        $this->assertSame(200, $this->dispatch('POST', self::NS . '/prescriptions/' . $rxB . '/finalize', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicB])->get_status());
        $this->assertSame(200, $this->dispatch('POST', self::NS . '/prescriptions/' . $rxA . '/finalize', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicA])->get_status());
        $this->assertSame('finalized', $this->rxStatus($rxA));
        $this->assertSame('finalized', $this->rxStatus($rxB));
    }

    /**
     * §۴ — staff که عضویت Clinic A را **ندارد** هم نباید بداند نسخهٔ A وجود
     * دارد یا نه: همان safe‑not‑found، بدون جهش.
     */
    public function testNonMemberStaffGetsSameSafeNotFoundForOtherClinicPrescription(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-rx-nonmem');
        $this->seedVisitPair();
        $member = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($member, $this->clinicA, 'cpms_doctor');
        $clinicianA = $this->insertClinician($this->clinicA, $member, 'Dr RxOwner');
        $rxA = $this->insertRx($this->clinicA, $this->lastVisitId($this->clinicA), $clinicianA);

        $outsider = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($outsider, $this->clinicB, 'cpms_doctor');
        wp_set_current_user($outsider);

        $res = $this->dispatch('POST', self::NS . '/prescriptions/' . $rxA . '/finalize', [], ['X-CPMS-Clinic-Id' => (string) $this->clinicB]);
        $this->assertSame(404, $res->get_status());
        $this->assertSame('CLINIC_NOT_FOUND', $this->errorCode($res));
        $flat = (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('cross', strtolower($flat));
        $this->assertStringNotContainsString('clinic_id', strtolower($flat));
        $this->assertSame('draft', $this->rxStatus($rxA));
    }

    /** §۷ — context B فقط ردیف‌های B را می‌دهد (آینهٔ تست A). */
    public function testSmsLogsForClinicBContextReturnsOnlyB(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-sms-b');
        $manager = $this->makeStaff('cpms_manager');
        cpms_test_seed_membership($manager, $this->clinicB, 'cpms_manager');
        wp_set_current_user($manager);

        $seq = random_int(100000, 9999999);
        $this->insertSmsRow($this->clinicA, 'smsX' . $seq);
        $idB = $this->insertSmsRow($this->clinicB, 'smsB' . $seq);

        $res = $this->dispatch('GET', self::NS . '/sms/logs', ['per_page' => 100, 'page' => 1], ['X-CPMS-Clinic-Id' => (string) $this->clinicB]);
        $this->assertSame(200, $res->get_status(), (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE));
        $flat = (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('smsB' . $seq, $flat);
        $this->assertStringNotContainsString('smsX' . $seq, $flat, 'ردیف Clinic A نباید در context B دیده شود');
        $this->assertSame([$idB], $this->smsLogIds($res), 'دقیقاً ردیف Clinic خودی');
    }

    /** §۷ — یک کاربر با عضویت در A و B: هر context فقطClinic خودش را می‌بیند. */
    public function testSmsLogsForMultiMembershipUserSwitchesWithTrustedContext(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-sms-multi');
        $manager = $this->makeStaff('cpms_manager');
        cpms_test_seed_membership($manager, $this->clinicA, 'cpms_manager');
        cpms_test_seed_membership($manager, $this->clinicB, 'cpms_manager');
        wp_set_current_user($manager);

        $seq = random_int(100000, 9999999);
        $idA = $this->insertSmsRow($this->clinicA, 'smsMA' . $seq);
        $idB = $this->insertSmsRow($this->clinicB, 'smsMB' . $seq);

        $inA = $this->dispatch('GET', self::NS . '/sms/logs', ['per_page' => 100, 'page' => 1], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame([$idA], $this->smsLogIds($inA), 'context A فقط A');
        $inB = $this->dispatch('GET', self::NS . '/sms/logs', ['per_page' => 100, 'page' => 1], ['X-CPMS-Clinic-Id' => (string) $this->clinicB]);
        $this->assertSame([$idB], $this->smsLogIds($inB), 'context B فقط B');
        // سوییچ برگشتی: A همچنان فقط A (هیچ state بین‌درخواستی نشت نمی‌کند)
        $backToA = $this->dispatch('GET', self::NS . '/sms/logs', ['per_page' => 100, 'page' => 1], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame([$idA], $this->smsLogIds($backToA));
    }

    /** §۷ — نشت Cross‑Organization: Organization دیگر = هیچ. */
    public function testSmsLogsDoNotLeakAcrossOrganization(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-sms-org');
        $otherOrg = $this->insertOrg('org-sms-other');
        $clinicC = 60021;
        $this->insertClinicInOrg($clinicC, $otherOrg, 'rest-ctx-sms-c');
        $manager = $this->makeStaff('cpms_manager');
        cpms_test_seed_membership($manager, $this->clinicB, 'cpms_manager');
        wp_set_current_user($manager);

        $seq = random_int(100000, 9999999);
        $idB = $this->insertSmsRow($this->clinicB, 'smsOrgB' . $seq);
        $this->insertSmsRow($clinicC, 'smsOrgC' . $seq);

        $res = $this->dispatch('GET', self::NS . '/sms/logs', ['per_page' => 100, 'page' => 1], ['X-CPMS-Clinic-Id' => (string) $this->clinicB]);
        $this->assertSame(200, $res->get_status(), (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE));
        $this->assertSame([$idB], $this->smsLogIds($res), 'ردیف Clinic یک Organization دیگر هرگز نباید بیاید');
        $this->assertStringNotContainsString('smsOrgC' . $seq, (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE));
    }

    /** §۷ — Clinic خالی: پاسخ خالی، نه دادهٔ Clinic دیگر. */
    public function testSmsLogsEmptyClinicReturnsEmptyResult(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-sms-empty');
        $manager = $this->makeStaff('cpms_manager');
        cpms_test_seed_membership($manager, $this->clinicA, 'cpms_manager');
        wp_set_current_user($manager);

        $seq = random_int(100000, 9999999);
        $this->insertSmsRow($this->clinicB, 'smsOnlyB' . $seq);

        $res = $this->dispatch('GET', self::NS . '/sms/logs', ['per_page' => 100, 'page' => 1], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(200, $res->get_status());
        $data = (array) $res->get_data();
        $this->assertSame(0, (int) ($data['data']['total'] ?? -1), 'total باید صفر باشد');
        $this->assertSame([], (array) ($data['data']['items'] ?? []), 'items باید خالی باشد');
        $this->assertStringNotContainsString('smsOnlyB' . $seq, (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * §۷ — شاهدِ «Database predicate» (نه post‑filter در PHP): فراخوانی لایهٔ
     * سرویس با هر Clinic، دقیقاً ردیف‌های همان Clinic را برمی‌گرداند و شمارش و
     * ترتیب/صفحه‌بندی هم حول همان predicate پایدار است.
     */
    public function testSmsLogsApplyTenantPredicateInSql(): void
    {
        $this->insertClinic($this->clinicB, 'rest-ctx-sms-sql');
        $seq = random_int(100000, 9999999);
        $idsA = [];
        $idsB = [];
        for ($i = 0; $i < 3; $i++) {
            $idsA[] = $this->insertSmsRow($this->clinicA, 'smsSqlA' . $seq . '_' . $i);
            $idsB[] = $this->insertSmsRow($this->clinicB, 'smsSqlB' . $seq . '_' . $i);
        }

        $logsA = App::smsService()->logs($this->clinicA, null, 1, 10);
        $this->assertSame(3, (int) $logsA['total'], 'COUNT باید tenant‑scoped باشد');
        $this->assertSame(array_reverse($idsA), array_column($logsA['items'], 'id'), 'ORDER BY id DESC در همان Clinic');
        $this->assertNotContains($idsB[0], array_column($logsA['items'], 'id'), 'ردیف Clinic دیگر هرگز بارگذاری/فیلترِ PHP نمی‌شود');

        $logsB = App::smsService()->logs($this->clinicB, null, 1, 10);
        $this->assertSame(3, (int) $logsB['total']);
        $this->assertSame(array_reverse($idsB), array_column($logsB['items'], 'id'));

        // صفحه‌بندی پایدار: page 2 با per_page 2 → دو ردیف آخرِ همان Clinic
        $p2 = App::smsService()->logs($this->clinicA, null, 2, 2);
        $this->assertSame(3, (int) $p2['total'], 'total تحت صفحه‌بندی ثابت می‌ماند');
        $this->assertSame([$idsA[0]], array_column($p2['items'], 'id'));
        $this->assertSame([], array_column(App::smsService()->logs(60099, null, 1, 10)['items'], 'id'), 'Clinic بدون ردیف → خالی (نه دادهٔ دیگران)');
    }

    // ================= Phase 1B B-01/B-02 — TEST-ONLY authorization contract =================

    /**
     * Expected RED on the unmodified Phase 1B implementation: a Clinic doctor
     * with the default private-note capability must not gain another doctor's
     * private notes merely by reading the shared clinical record; no approved
     * inter-doctor shared-care policy currently exists. The shared record read
     * and patient-visible note remain available.
     */
    public function testPhase1BSharedRecordReadHidesAnotherDoctorsPrivateNotes(): void
    {
        $owner = $this->makeStaff('cpms_doctor');
        $peer = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($owner, $this->clinicA, 'cpms_doctor');
        cpms_test_seed_membership($peer, $this->clinicA, 'cpms_doctor');
        $ownerClinicianId = $this->insertClinician($this->clinicA, $owner, 'Dr Phase1B Note Owner');
        $this->insertClinician($this->clinicA, $peer, 'Dr Phase1B Note Peer');
        $patientId = $this->insertPatient($this->clinicA, 'Phase1BPrivate');
        $this->insertVisit($this->clinicA, $this->locA, $ownerClinicianId, $patientId);
        $visitId = $this->lastVisitId($this->clinicA);

        self::assertTrue(App::authorization_service()->can($peer, $this->clinicA, RolesAndCapabilities::MEDICAL_READ));
        self::assertTrue(App::authorization_service()->can($peer, $this->clinicA, RolesAndCapabilities::PRIVATE_NOTE_READ));

        wp_set_current_user($owner);
        $private = $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/notes', [
            'category' => 'private_note',
            'visibility' => 'doctor_private',
            'content_text' => 'phase1b-owner-only-private-note',
        ], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(200, $private->get_status(), (string) json_encode($private->get_data(), JSON_UNESCAPED_UNICODE));
        $shared = $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/notes', [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'phase1b-shared-clinical-note',
        ], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(200, $shared->get_status(), (string) json_encode($shared->get_data(), JSON_UNESCAPED_UNICODE));

        wp_set_current_user($peer);
        $record = $this->dispatch('GET', self::NS . '/visits/' . $visitId . '/record', [], [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
        ]);
        $this->assertSame(200, $record->get_status(), 'MEDICAL_READ still permits shared clinical record reading');
        $notes = $this->payload($record)['notes'] ?? [];
        $contents = array_column($notes, 'content_text');
        $this->assertContains('phase1b-shared-clinical-note', $contents);
        $this->assertNotContains('phase1b-owner-only-private-note', $contents);
    }

    /** A scoped MEDICAL_READ grant alone must never expose doctor_private notes. */
    public function testPhase1BMedicalReadDoesNotImplyPrivateNoteRead(): void
    {
        $owner = $this->makeStaff('cpms_doctor');
        $reader = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($owner, $this->clinicA, 'cpms_doctor');
        $readerMembership = cpms_test_seed_membership($reader, $this->clinicA, 'cpms_doctor');
        App::membership_service()->set_capability(
            $readerMembership,
            RolesAndCapabilities::PRIVATE_NOTE_READ,
            'deny'
        );
        $clinicianId = $this->insertClinician($this->clinicA, $owner, 'Dr Phase1B Cap Owner');
        $this->insertClinician($this->clinicA, $reader, 'Dr Phase1B Cap Reader');
        $patientId = $this->insertPatient($this->clinicA, 'Phase1BCap');
        $this->insertVisit($this->clinicA, $this->locA, $clinicianId, $patientId);
        $visitId = $this->lastVisitId($this->clinicA);

        self::assertTrue(App::authorization_service()->can($reader, $this->clinicA, RolesAndCapabilities::MEDICAL_READ));
        self::assertFalse(App::authorization_service()->can($reader, $this->clinicA, RolesAndCapabilities::PRIVATE_NOTE_READ));

        wp_set_current_user($owner);
        $created = $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/notes', [
            'category' => 'private_note',
            'visibility' => 'doctor_private',
            'content_text' => 'phase1b-medical-read-only-secret',
        ], ['X-CPMS-Clinic-Id' => (string) $this->clinicA]);
        $this->assertSame(200, $created->get_status(), (string) json_encode($created->get_data(), JSON_UNESCAPED_UNICODE));

        wp_set_current_user($reader);
        $record = $this->dispatch('GET', self::NS . '/visits/' . $visitId . '/record', [], [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
        ]);
        $this->assertSame(200, $record->get_status(), 'denying private-note access must not disable shared MEDICAL_READ');
        $contents = array_column($this->payload($record)['notes'] ?? [], 'content_text');
        $this->assertNotContains('phase1b-medical-read-only-secret', $contents);
    }

    /**
     * POSITIVE CONTROL (policy preserved, NOT a defect): same-Clinic note authoring
     * by a scoped `cpms_note_create` holder stays authorized even when the Visit
     * belongs to another clinician of the same Clinic. This is the accepted
     * shared-care boundary (permission matrix §4.3 note rows + the existing
     * ClinicalFilesScopedAuthorizationTest regressions); no narrower own-Visit rule
     * for note creation is authorized by a current Product Decision, so none is
     * invented here. The peer still must not see the owner's doctor_private notes
     * (covered by the two private-note regressions above).
     */
    public function testPhase1BSameClinicPeerNoteAuthoringRemainsAuthorized(): void
    {
        $owner = $this->makeStaff('cpms_doctor');
        $peer = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($owner, $this->clinicA, 'cpms_doctor');
        cpms_test_seed_membership($peer, $this->clinicA, 'cpms_doctor');
        $clinicianId = $this->insertClinician($this->clinicA, $owner, 'Dr Phase1B E8 Owner');
        $patientId = $this->insertPatient($this->clinicA, 'Phase1BE8');
        $this->insertVisit($this->clinicA, $this->locA, $clinicianId, $patientId);
        $visitId = $this->lastVisitId($this->clinicA);
        wp_set_current_user($peer);

        $body = [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'phase1b-authorized-same-clinic-peer-note',
        ];
        $attempt = $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/notes', $body, [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
        ]);
        $this->assertSame(200, $attempt->get_status(), (string) json_encode($attempt->get_data(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->clinicalNoteCount($this->clinicA, $visitId));

        // A peer without the scoped permission is still denied by the existing
        // capability boundary — the widened path above is permission-gated.
        $denied = $this->makeStaff('cpms_doctor');
        $deniedMembership = cpms_test_seed_membership($denied, $this->clinicA, 'cpms_doctor');
        App::membership_service()->set_capability($deniedMembership, RolesAndCapabilities::NOTE_CREATE, 'deny');
        wp_set_current_user($denied);
        $notesBefore = $this->clinicalNoteCount($this->clinicA, $visitId);
        $rejected = $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/notes', $body, [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
        ]);
        $this->assertSame(403, $rejected->get_status(), (string) json_encode($rejected->get_data(), JSON_UNESCAPED_UNICODE));
        $this->assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($rejected));
        $this->assertSame($notesBefore, $this->clinicalNoteCount($this->clinicA, $visitId), 'Denied write creates no note');
    }

    /** Same-Clinic peer prescription finalization is a safe 404 and is side-effect-free. */
    public function testPhase1BPeerCannotFinalizeAnotherDoctorsPrescription(): void
    {
        $owner = $this->makeStaff('cpms_doctor');
        $peer = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($owner, $this->clinicA, 'cpms_doctor');
        cpms_test_seed_membership($peer, $this->clinicA, 'cpms_doctor');
        $clinicianId = $this->insertClinician($this->clinicA, $owner, 'Dr Phase1B RX Owner');
        $this->insertClinician($this->clinicA, $peer, 'Dr Phase1B RX Peer');
        $patientId = $this->insertPatient($this->clinicA, 'Phase1BRx');
        $this->insertVisit($this->clinicA, $this->locA, $clinicianId, $patientId);
        $visitId = $this->lastVisitId($this->clinicA);
        $prescriptionId = $this->insertRx($this->clinicA, $visitId, $clinicianId);
        wp_set_current_user($peer);

        $before = App::db()->fetchRow(
            'SELECT status, finalized_at, updated_at FROM ' . App::db()->table('cpms_prescriptions') . ' WHERE id = %d',
            [$prescriptionId]
        );
        $auditBefore = $this->auditCount();
        $attempt = $this->dispatch('POST', self::NS . '/prescriptions/' . $prescriptionId . '/finalize', [], [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
        ]);
        $after = App::db()->fetchRow(
            'SELECT status, finalized_at, updated_at FROM ' . App::db()->table('cpms_prescriptions') . ' WHERE id = %d',
            [$prescriptionId]
        );
        $auditAfter = $this->auditCount();
        $missing = $this->dispatch('POST', self::NS . '/prescriptions/' . $this->missingId('cpms_prescriptions') . '/finalize', [], [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
        ]);
        $diagnostic = sprintf(
            'response=%s; prescription_before=%s; prescription_after=%s; audit_rows=%d->%d',
            (string) json_encode($attempt->get_data(), JSON_UNESCAPED_UNICODE),
            (string) json_encode($before, JSON_UNESCAPED_UNICODE),
            (string) json_encode($after, JSON_UNESCAPED_UNICODE),
            $auditBefore,
            $auditAfter
        );

        $this->assertSame($this->responseErrorIdentity($missing), $this->responseErrorIdentity($attempt), $diagnostic);
        $this->assertSame($before, $after, 'Rejected E11 write must not finalize or touch the prescription');
        $this->assertSame($auditBefore, $auditAfter, 'Rejected E11 write must not append an audit row');
    }

    /** A dual-member doctor cannot use trusted Clinic A context to mutate Clinic B. */
    public function testPhase1BDualClinicDoctorCannotCreateNoteOutsideTrustedClinic(): void
    {
        $fixture = $this->seedDualClinicVisitB('e8');
        $doctor = $fixture['doctor'];
        $visitB = $fixture['visit_id'];
        self::assertNotNull(App::membership_service()->membership_for($this->clinicA, $doctor));
        self::assertNotNull(App::membership_service()->membership_for($this->clinicB, $doctor));
        wp_set_current_user($doctor);

        $body = [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'phase1b-cross-clinic-rejected-note',
        ];
        $notesBeforeB = $this->clinicNoteCount($this->clinicB);
        $auditBefore = $this->auditCount();
        $auditBeforeB = $this->auditCount($this->clinicB);
        $attempt = $this->dispatch('POST', self::NS . '/visits/' . $visitB . '/notes', $body, [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
        ]);
        $notesAfterB = $this->clinicNoteCount($this->clinicB);
        $auditAfter = $this->auditCount();
        $auditAfterB = $this->auditCount($this->clinicB);
        $missing = $this->dispatch('POST', self::NS . '/visits/' . $this->missingId('cpms_visits') . '/notes', $body, [
            'X-CPMS-Clinic-Id' => (string) $this->clinicA,
        ]);
        $diagnostic = sprintf(
            'response=%s; clinic_b_notes=%d->%d; all_audit=%d->%d; clinic_b_audit=%d->%d',
            (string) json_encode($attempt->get_data(), JSON_UNESCAPED_UNICODE),
            $notesBeforeB,
            $notesAfterB,
            $auditBefore,
            $auditAfter,
            $auditBeforeB,
            $auditAfterB
        );

        $this->assertSame($this->responseErrorIdentity($missing), $this->responseErrorIdentity($attempt), $diagnostic);
        $this->assertSame($notesBeforeB, $notesAfterB, 'Clinic B clinical records must remain unchanged');
        $this->assertSame($auditBefore, $auditAfter, 'Rejected cross-Clinic write must not append any audit row');
        $this->assertSame($auditBeforeB, $auditAfterB, 'Clinic B audit records must remain unchanged');
    }

    /** Own-Visit creation/finalization and shared patient-visible reads remain valid. */
    public function testPhase1BOwnerCanCreateAndFinalizeOwnVisitRecords(): void
    {
        $owner = $this->makeStaff('cpms_doctor');
        cpms_test_seed_membership($owner, $this->clinicA, 'cpms_doctor');
        $clinicianId = $this->insertClinician($this->clinicA, $owner, 'Dr Phase1B Positive');
        $patientId = $this->insertPatient($this->clinicA, 'Phase1BPositive');
        $this->insertVisit($this->clinicA, $this->locA, $clinicianId, $patientId);
        $visitId = $this->lastVisitId($this->clinicA);
        wp_set_current_user($owner);

        $headers = ['X-CPMS-Clinic-Id' => (string) $this->clinicA];
        $note = $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/notes', [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'phase1b-authorized-own-visit-note',
        ], $headers);
        $this->assertSame(200, $note->get_status(), (string) json_encode($note->get_data(), JSON_UNESCAPED_UNICODE));
        $privateNote = $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/notes', [
            'category' => 'private_note',
            'visibility' => 'doctor_private',
            'content_text' => 'phase1b-authorized-own-private-note',
        ], $headers);
        $this->assertSame(200, $privateNote->get_status(), (string) json_encode($privateNote->get_data(), JSON_UNESCAPED_UNICODE));
        $ownRecord = $this->dispatch('GET', self::NS . '/visits/' . $visitId . '/record', [], $headers);
        $this->assertSame(200, $ownRecord->get_status());
        $ownNoteContents = array_column($this->payload($ownRecord)['notes'] ?? [], 'content_text');
        $this->assertContains('phase1b-authorized-own-private-note', $ownNoteContents);

        $draft = $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/prescriptions', [
            'items' => [[
                'generic_name' => 'Acetaminophen',
                'dose' => '500 mg',
                'frequency' => 'once daily',
            ]],
            'is_patient_visible' => true,
        ], $headers);
        $this->assertSame(200, $draft->get_status(), (string) json_encode($draft->get_data(), JSON_UNESCAPED_UNICODE));
        $prescriptionId = (int) ($this->payload($draft)['id'] ?? 0);
        $this->assertGreaterThan(0, $prescriptionId);

        $finalized = $this->dispatch('POST', self::NS . '/prescriptions/' . $prescriptionId . '/finalize', [], $headers);
        $this->assertSame(200, $finalized->get_status(), (string) json_encode($finalized->get_data(), JSON_UNESCAPED_UNICODE));
        $this->assertSame('finalized', (string) ($this->payload($finalized)['status'] ?? ''));
    }

    // ================= fixtures =================

    /**
     * @return array{doctor: int, visit_id: int, patient_id: int, clinician_id: int, membership_a: int, membership_b: int}
     */
    private function seedDualClinicVisitB(string $purpose): array
    {
        $slug = 'rest-ctx-phase1b-' . $purpose . '-' . bin2hex(random_bytes(3));
        $this->insertClinic($this->clinicB, $slug);
        $locationB = $this->insertLocation($this->clinicB, $slug . '-loc');

        $doctor = $this->makeStaff('cpms_doctor');
        $membershipA = cpms_test_seed_membership($doctor, $this->clinicA, 'cpms_doctor');
        $membershipB = cpms_test_seed_membership($doctor, $this->clinicB, 'cpms_doctor');
        $clinicianId = $this->insertClinician($this->clinicA, $doctor, 'Dr Phase1B Cross ' . $purpose);
        $patientId = $this->insertPatient($this->clinicB, 'Phase1BCross' . $purpose);
        $this->insertVisit($this->clinicB, $locationB, $clinicianId, $patientId);

        return [
            'doctor' => $doctor,
            'visit_id' => $this->lastVisitId($this->clinicB),
            'patient_id' => $patientId,
            'clinician_id' => $clinicianId,
            'membership_a' => $membershipA,
            'membership_b' => $membershipB,
        ];
    }

    private function clinicalNoteCount(int $clinicId, int $visitId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinical_notes') . ' WHERE clinic_id = %d AND visit_id = %d',
            [$clinicId, $visitId]
        );
    }

    private function clinicNoteCount(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinical_notes') . ' WHERE clinic_id = %d',
            [$clinicId]
        );
    }

    private function auditCount(?int $clinicId = null): int
    {
        if ($clinicId === null) {
            return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs'));
        }

        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$clinicId]
        );
    }

    private function missingId(string $table): int
    {
        $missing = (int) App::db()->fetchValue(
            'SELECT COALESCE(MAX(id), 0) + 1 FROM ' . App::db()->table($table)
        );
        self::assertGreaterThan(0, $missing, 'پیش‌شرط: شناسهٔ تهی باید مثبت باشد');

        return $missing;
    }

    /** @return array{status: int, code: string, message: string, data: mixed} */
    private function responseErrorIdentity(WP_REST_Response $response): array
    {
        $body = $response->get_data();
        if ($body instanceof \WP_Error) {
            $code = $body->get_error_code();

            return [
                'status' => $response->get_status(),
                'code' => $code,
                'message' => $body->get_error_message($code),
                'data' => $body->get_error_data($code),
            ];
        }
        $body = is_array($body) ? $body : [];

        return [
            'status' => $response->get_status(),
            'code' => (string) ($body['code'] ?? ''),
            'message' => (string) ($body['message'] ?? ''),
            'data' => $body['data'] ?? null,
        ];
    }

    /** @return list<int> شناسه‌های ردیف‌های لاگ در پاسخ REST */
    private function smsLogIds(WP_REST_Response $res): array
    {
        $data = (array) $res->get_data();
        $items = (array) ($data['data']['items'] ?? []);

        return array_map(static fn (array $i): int => (int) $i['id'], $items);
    }

    private function insertSmsRow(int $clinicId, string $marker): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(100000, 9999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_sms_messages
                     (clinic_id, event, recipient, message, status, attempts, max_attempts, created_at, updated_at)
                 VALUES (%d, "reminder", %s, %s, "SENT", 1, 3, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                '0912' . sprintf('%07d', $seq),
                'cpms-' . $marker,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج ردیف SMS (marker=' . $marker . ')');

        return $id;
    }

    private function insertOrg(string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
                 VALUES (%s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Org ' . $slug,
                $slug . '-' . bin2hex(random_bytes(2)),
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج Organization');

        return $id;
    }

    private function insertClinicInOrg(int $id, int $orgId, string $slug): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
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
        self::assertGreaterThan(0, (int) $id, 'پیش‌شرط: Clinic در Organization دیگر');
        App::resetScope();
    }



    /** آخرین visit یک Clinic (seedVisitPair دقیقاً یک visit per clinic می‌کارد). */
    private function lastVisitId(int $clinicId): int
    {
        global $wpdb;
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_visits WHERE clinic_id = %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
        self::assertGreaterThan(0, $id, 'پیش‌شرط: ویزیتِ همان Clinic از seedVisitPair');

        return $id;
    }

    /** درج صریح نسخهٔ draft متعلق به یک Clinic (ستون clinic_id واقعی، نه حدس). */
    private function insertRx(int $clinicId, int $visitId, int $clinicianId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $patientId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT patient_id FROM ' . $wpdb->prefix . 'cpms_visits WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $visitId
            )
        );
        $this->assertGreaterThan(0, $patientId, 'پیش‌شرط: visit متعلق به همان Clinic بیمار دارد');
        $seq = random_int(100000, 9999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_prescriptions
                     (clinic_id, prescription_number, visit_id, patient_id, clinician_id, status, is_patient_visible, created_at, updated_at)
                 VALUES (%d, %s, %d, %d, %d, "draft", 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'RX-SCOPE-' . $seq . '-' . $clinicId,
                $visitId,
                $patientId,
                $clinicianId,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج نسخه');

        return $id;
    }

    private function rxStatus(int $rxId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . $wpdb->prefix . 'cpms_prescriptions WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $rxId
            )
        );
    }



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
