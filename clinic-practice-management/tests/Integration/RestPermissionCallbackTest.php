<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Phase 1A — Item 1 (REST Security).
 *
 * پیش از Phase 1، از 88 ثبتِ `permission_callback` در فضای `clinic/v1`،
 * 67 مورد `__return_true` بود و مجوز فقط داخل Handler بررسی می‌شد
 * (Late Authorization). این تست قرارداد جدید را قفل می‌کند:
 *
 *  1) هیچ Route ای در `clinic/v1` مجاز به داشتن `__return_true` نیست.
 *  2) فقط Routeهای صراحتاً Public (لیست ثابت زیر) بدون Authentication
 *     پاسخ می‌دهند — «Public بودن» یک تصمیم قابل تست است، نه پیش‌فرض.
 *  3) Routeهای غیر Public برای کاربر ناشناس هرگز به Handler نمی‌رسند.
 *
 * ⚠️ محدوده: این تست فقط مجوزِ Scope-Independent را پوشش می‌دهد. ایزولاسیون
 * Organization/Clinic/Location در Phase 1B/3 تست می‌شود و عمداً اینجا با
 * `clinic_id = 1` شبیه‌سازی نشده است (AD-13).
 */
final class RestPermissionCallbackTest extends WP_UnitTestCase
{
    private const NS = 'clinic/v1';

    /**
     * تنها Routeهایی که Public بودنشان تصمیم محصولی است.
     *
     * افزودن هر عضو جدید به این لیست باید یک تصمیم صریح باشد؛ در غیر این
     * صورت این تست شکست می‌خورد.
     *
     * @var list<string>
     */
    private const INTENTIONALLY_PUBLIC = [
        '/clinic/v1/availability',
        '/clinic/v1/booking/quote',
        '/clinic/v1/health',
        '/clinic/v1/otp/request',
        '/clinic/v1/otp/verify',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        do_action('rest_api_init');
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function clinicRoutes(): array
    {
        $routes = rest_get_server()->get_routes();
        $out = [];
        foreach ($routes as $route => $handlers) {
            if (str_starts_with(ltrim($route, '/'), self::NS . '/')) {
                $out[$route] = $handlers;
            }
        }

        return $out;
    }

    public function testNoClinicRouteUsesReturnTrueAsPermissionCallback(): void
    {
        $offenders = [];
        foreach ($this->clinicRoutes() as $route => $handlers) {
            foreach ($handlers as $handler) {
                $cb = $handler['permission_callback'] ?? null;
                if ($cb === '__return_true' || $cb === null) {
                    $offenders[] = $route;
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($offenders)),
            'هیچ Route ای در clinic/v1 نباید permission_callback برابر __return_true داشته باشد.'
        );
    }

    public function testEveryClinicRouteHasCallablePermissionCallback(): void
    {
        $routes = $this->clinicRoutes();
        self::assertNotEmpty($routes, 'هیچ Route ای در clinic/v1 ثبت نشده است.');

        foreach ($routes as $route => $handlers) {
            foreach ($handlers as $handler) {
                self::assertIsCallable(
                    $handler['permission_callback'],
                    'permission_callback باید callable باشد: ' . $route
                );
            }
        }
    }

    /**
     * حمله: کاربر ناشناس (بدون Login و بدون Nonce) هیچ Routeای جز لیست
     * Public را نباید بگذراند.
     */
    public function testAnonymousRequestIsRejectedOnEveryNonPublicRoute(): void
    {
        wp_set_current_user(0);
        $leaks = [];

        foreach ($this->clinicRoutes() as $route => $handlers) {
            if (in_array($route, self::INTENTIONALLY_PUBLIC, true)) {
                continue;
            }
            if (str_contains($route, '(?P<')) {
                continue; // مسیرهای پارامتری در تستهای اختصاصی هر Controller
            }
            foreach ($handlers as $handler) {
                $methods = array_keys(array_filter($handler['methods']));
                $request = new WP_REST_Request($methods[0] ?? 'GET', $route);
                $allowed = call_user_func($handler['permission_callback'], $request);
                if ($allowed === true) {
                    $leaks[] = $methods[0] . ' ' . $route;
                }
            }
        }

        self::assertSame([], $leaks, 'این Routeها برای کاربر ناشناس باز هستند: ' . implode(', ', $leaks));
    }

    /**
     * Routeهای Public باید واقعاً Public بمانند (رگرسیون معکوس: سختگیری
     * بیش از حد هم یک خرابی است).
     */
    public function testIntentionallyPublicRoutesStayPublic(): void
    {
        wp_set_current_user(0);
        $server = rest_get_server();
        $routes = $server->get_routes();

        foreach (self::INTENTIONALLY_PUBLIC as $route) {
            self::assertArrayHasKey($route, $routes, 'Route عمومی ثبت نشده است: ' . $route);
            foreach ($routes[$route] as $handler) {
                $methods = array_keys(array_filter($handler['methods']));
                $request = new WP_REST_Request($methods[0] ?? 'GET', $route);
                self::assertTrue(
                    call_user_func($handler['permission_callback'], $request),
                    'Route عمومی نباید Authentication بخواهد: ' . $route
                );
            }
        }
    }

    /**
     * حمله: کاربر واردشده اما بدون Capability لازم — باید 403 بگیرد و
     * Handler اجرا نشود.
     */
    public function testAuthenticatedUserWithoutCapabilityIsDeniedBeforeHandler(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($userId);

        $request = new WP_REST_Request('GET', '/clinic/v1/queue');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        $handlers = rest_get_server()->get_routes()['/clinic/v1/queue'] ?? null;
        self::assertNotNull($handlers, 'Route /queue ثبت نشده است.');

        $result = call_user_func($handlers[0]['permission_callback'], $request);
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('CLINIC_PERMISSION_DENIED', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status'] ?? 0);
    }

    /**
     * حمله: Nonce غایب/نامعتبر روی Routeی که Nonce می‌خواهد.
     */
    public function testMissingNonceIsRejected(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        $handlers = rest_get_server()->get_routes()['/clinic/v1/queue'] ?? null;
        self::assertNotNull($handlers);

        $noNonce = new WP_REST_Request('GET', '/clinic/v1/queue');
        $result = call_user_func($handlers[0]['permission_callback'], $noNonce);
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('CLINIC_INVALID_NONCE', $result->get_error_code());

        $badNonce = new WP_REST_Request('GET', '/clinic/v1/queue');
        $badNonce->set_header('X-WP-Nonce', 'not-a-real-nonce');
        $result = call_user_func($handlers[0]['permission_callback'], $badNonce);
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('CLINIC_INVALID_NONCE', $result->get_error_code());
    }

    /**
     * رگرسیون CI (Phase 1A): `permission_callback` باید Capability را از
     * همان منبعی بخواند که لایهٔ Service می‌خواند.
     *
     * `wp_get_current_user()` نمونهٔ سراسریِ کش‌شده را برمی‌گرداند و پس از
     * `add_cap()` روی یک نمونهٔ دیگرِ `WP_User` کهنه می‌ماند. اگر
     * `requireCap()` به آن تکیه کند، یک درخواست کاملاً مجاز رد می‌شود.
     */
    public function testPermissionCallbackSeesCapabilityGrantedDuringTheSameRequest(): void
    {
        $userId = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        wp_set_current_user($userId);

        $routes = $this->clinicRoutes();
        $route = '/clinic/v1/reports/exports/(?P<id>\\d+)/download';
        self::assertArrayHasKey($route, $routes, 'Route دانلود Export باید ثبت شده باشد.');

        $request = new WP_REST_Request('GET', '/clinic/v1/reports/exports/1/download');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        $before = call_user_func($routes[$route][0]['permission_callback'], $request);
        self::assertInstanceOf(\WP_Error::class, $before, 'بدون cpms_export باید رد شود.');

        // اعطای مجوز روی یک نمونهٔ *دیگر* از WP_User — دقیقاً همان کاری که
        // ReportsAuthzTest انجام می‌دهد و باعث افشای این نقص شد.
        $other = get_userdata($userId);
        $other->add_cap(RolesAndCapabilities::EXPORT);

        $after = call_user_func($routes[$route][0]['permission_callback'], $request);
        self::assertTrue(
            $after,
            'permission_callback باید مجوزِ همین درخواست را ببیند، نه نسخهٔ کش‌شده را.'
        );
    }

    /**
     * تعداد Routeهای Public نباید بی‌سروصدا رشد کند.
     */
    public function testPublicRouteCountIsPinned(): void
    {
        $public = [];
        wp_set_current_user(0);
        foreach ($this->clinicRoutes() as $route => $handlers) {
            foreach ($handlers as $handler) {
                $request = new WP_REST_Request('GET', $route);
                if (call_user_func($handler['permission_callback'], $request) === true) {
                    $public[$route] = true;
                }
            }
        }

        // این ادعا یک **مجموعه** را قفل می‌کند، نه یک دنباله. ترتیب کلیدهای
        // `rest_get_server()->get_routes()` به ترتیب ثبت کنترلرها وابسته است
        // و یک جزئیات پیاده‌سازی است، نه یک ویژگی امنیتی.
        $actual = array_keys($public);
        sort($actual);
        $expected = self::INTENTIONALLY_PUBLIC;
        sort($expected);

        self::assertSame(
            $expected,
            $actual,
            'مجموعه Routeهای Public تغییر کرده است — نیازمند تصمیم صریح امنیتی.'
        );
    }
}
