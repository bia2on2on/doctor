<?php

declare(strict_types=1);

namespace ClinicCore\Bootstrap;

use ClinicCore\Frontend\DoctorPortalShell;
use ClinicCore\Frontend\ManagementPortalShell;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Frontend\StaffPortalShell;
use WP_Error;
use WP_REST_Request;

/**
 * گیتِ متمرکزِ زمانِ اجرا برای سایتِ از-پیش-فعال روی موتورِ دیتابیسِ پشتیبانی‌نشده.
 *
 * فقط وقتی مسدود می‌کند که **هم‌زمان**:
 *  ۱. موتورِ دیتابیس با شواهدِ خوانا خارج از مجموعهٔ مجاز شناسایی شده باشد؛ و
 *  ۲. مهاجرتِ اعمال‌نشده واقعاً در انتظار باشد (schema عقب‌تر از کد).
 *
 * اگر schema از قبل به‌روز باشد، مسیرهای زمانِ اجرا باز می‌مانند و فقط اعلانِ
 * مدیریتی نمایش داده می‌شود؛ اگر شواهدِ نسخه ناخوانا/مخدوش باشد، زمانِ اجرا
 * مسدود نمی‌شود (فقط لاگِ throttled عملیاتی). مدیریتِ افزونه‌ها در wp-admin
 * (`plugins.php` و غیرفعال‌سازی) همیشه در دسترس می‌ماند.
 */
final class RuntimeSchemaGate {

    public const ERROR_CODE = 'CLINIC_DB_SCHEMA_PENDING_UNSUPPORTED_ENGINE';

    public const PUBLIC_ERROR_CODE = 'CLINIC_SERVICE_UNAVAILABLE';

    public const NEUTRAL_MESSAGE = 'سرویس موقتاً در دسترس نیست.';

    public const TEMPLATE_REL = 'templates/runtime-unavailable-shell.php';

    public static function register(): void {
        add_filter( 'rest_pre_dispatch', [ self::class, 'filter_rest_pre_dispatch' ], 5, 3 );
        add_action( 'template_redirect', [ self::class, 'guard_portal_request' ], 0 );
        add_filter( 'template_include', [ self::class, 'filter_portal_template' ], 100 );
    }

    public static function is_blocked(): bool {
        return App::is_runtime_blocked_by_pending_schema();
    }

    /**
     * آیا مسیرِ REST متعلق به فضای نامِ بالینی/عملیاتیِ CPMS (`clinic/v1`) است؟
     */
    public static function is_cpms_rest_route( string $route ): bool {
        return $route === '/clinic/v1' || str_starts_with( $route, '/clinic/v1/' );
    }

    /**
     * اتصال به `rest_pre_dispatch`: مسیرهای `/clinic/v1/*` در حالتِ مسدود با
     * پاسخِ پایدارِ HTTP 503 و بدونِ هیچ دادهٔ بالینی متوقف می‌شوند، در حالی که
     * مسیرهای هستهٔ وردپرس (`/wp/v2/*` و مدیریتِ افزونه) دست‌نخورده می‌مانند.
     *
     * @param mixed $result  پاسخِ از-پیش-تعیین‌شده (معمولاً null).
     * @param mixed $server  سرورِ REST وردپرس.
     * @param mixed $request درخواستِ جاری.
     * @return mixed
     */
    public static function filter_rest_pre_dispatch( $result, $server, $request ) {
        unset( $server );

        if ( ! $request instanceof WP_REST_Request ) {
            return $result;
        }

        if ( ! self::is_cpms_rest_route( $request->get_route() ) ) {
            return $result;
        }

        if ( ! self::is_blocked() ) {
            return $result;
        }

        return new WP_Error(
            is_user_logged_in() ? self::ERROR_CODE : self::PUBLIC_ERROR_CODE,
            self::NEUTRAL_MESSAGE,
            [ 'status' => 503 ]
        );
    }

    public static function is_any_portal_request(): bool {
        return PatientPortalShell::is_portal_request()
            || StaffPortalShell::is_portal_request()
            || DoctorPortalShell::is_portal_request()
            || ManagementPortalShell::is_portal_request();
    }

    /**
     * ارسالِ هدرهای عدمِ کش و وضعیتِ 503 روی پورتال‌های فرانت‌اند در حالتِ مسدود.
     */
    public static function guard_portal_request(): void {
        if ( ! self::is_any_portal_request() || ! self::is_blocked() ) {
            return;
        }

        if ( headers_sent() ) {
            return;
        }

        status_header( 503 );
        nocache_headers();
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
    }

    /**
     * جایگزینیِ قالبِ پورتال‌های فرانت‌اند با پیامِ کوتاه و خنثی (بدونِ هیچ دادهٔ بیمار).
     */
    public static function filter_portal_template( string $template ): string {
        if ( ! self::is_any_portal_request() || ! self::is_blocked() ) {
            return $template;
        }

        $owned = self::template_path();
        if ( ! is_readable( $owned ) ) {
            return $template;
        }

        return $owned;
    }

    public static function template_path(): string {
        $base = defined( 'CPMS_PLUGIN_DIR' ) ? (string) CPMS_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';

        return rtrim( $base, '/\\' ) . '/' . self::TEMPLATE_REL;
    }

    /**
     * خروجیِ کوتاه و خنثی برای شورت‌کدهای عمومی در حالتِ مسدود (بدونِ دادهٔ بیمار/مرکز).
     */
    public static function shortcode_markup(): string {
        return '<div class="cpms-public-booking cpms-public-booking--unavailable" data-state="unavailable" data-role="cpms-runtime-unavailable" role="status">'
            . '<p class="cpms-public-booking__notice">' . esc_html( self::NEUTRAL_MESSAGE ) . '</p>'
            . '</div>';
    }
}
