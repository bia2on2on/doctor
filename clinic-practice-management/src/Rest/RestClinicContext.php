<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_Error;
use WP_REST_Request;

/**
 * مرز REST برای استقرار ClinicScope مورد اعتماد.
 *
 * Generic staff selector extraction happens here; Doctor Portal selectors are
 * parsed by the endpoint permission boundary. Membership/tenant verification is
 * delegated to {@see TrustedClinicEstablisher}.
 *
 * Generic staff scopes bind in `rest_request_before_callbacks`; Doctor Portal
 * and Reception operational scopes bind only after their permission callbacks.
 */
final class RestClinicContext
{
    /**
     * جفت‌های (requestId, scopePrevious) برای درخواست‌های bindشده و باز‌نشده.
     *
     * LIFO (stack) به‌جای dict باز‌شونده بر پایهٔ `spl_object_id` تنها: شناسهٔ
     * آبجکت پس از free شدنِ WP_REST_Request قابل بازاستفاده است و در فرآیندهای
     * بلند (یا تست‌های پردرخواست) کلیدِ یکسان می‌تواند به درخواست دیگری برگردد.
     *
     * @var list<array{rid: int, previous: ClinicScope|null, bound: ClinicScope}>
     */
    private static array $stack = [];

    private static bool $shutdownHookRegistered = false;

    /**
     * @param mixed $response
     * @param mixed $handler
     * @return mixed
     */
    public static function beforeCallbacks($response, $handler, $request)
    {
        if (!$request instanceof WP_REST_Request) {
            return $response;
        }
        if ($response instanceof WP_Error) {
            return $response;
        }
        if (!self::requiresTrustedClinic($request)) {
            return $response;
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return $response;
        }

        try {
            $ids = self::extractIds($request);
            $establisher = new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db()));
            if ( self::is_doctor_portal_queue_route( (string) $request->get_route() ) ) {
                $scope = $establisher->establish_for_doctor_portal(
                    $user_id,
                    $ids['clinic_id'],
                    $ids['location_id'],
                    self::doctor_portal_visit_id( (string) $request->get_route() )
                );
            } else {
                $scope = $establisher->establish( $user_id, $ids['clinic_id'], $ids['location_id'] );
            }
        } catch (ScopeRequiredException $e) {
            return self::toError($e);
        }

        self::pushRestoreState($request, $scope);

        return $response;
    }

    /**
     * Authorize explicit Reception Clinic scope before validating the tenant
     * selector. The only unscoped request permitted here is the Reception
     * context selector used to list the actor's own authorized Clinics.
     *
     * @param list<string> $permissions
     */
    public static function authorize_and_bind_reception_scope(
        WP_REST_Request $request,
        int $user_id,
        array $permissions,
        bool $allow_unscoped_context = false,
        bool $allow_missing_location = false
    ): bool|WP_Error {
        try {
            $ids = self::extractIds( $request );
        } catch ( ScopeRequiredException $e ) {
            return self::toError( $e );
        }

        if ( null === $ids['clinic_id'] ) {
            if ( null !== $ids['location_id'] ) {
                return self::toError(
                    new ScopeRequiredException(
                        'CLINIC_VALIDATION_FAILED',
                        'Location cannot establish Clinic.',
                        [ 'field' => 'location_id' ],
                        422
                    )
                );
            }
            if ( $allow_unscoped_context ) {
                return true;
            }

            return self::toError(
                new ScopeRequiredException(
                    'CLINIC_SCOPE_REQUIRED',
                    'Explicit Clinic is required for Reception operations.',
                    [ 'field' => 'clinic_id', 'reason' => 'clinic_required' ],
                    400
                )
            );
        }

        // Authorization is evaluated against the explicit Clinic before the
        // establisher validates that Clinic/Location exists or belongs to the
        // actor. This preserves DENY precedence and avoids tenant probing.
        try {
            foreach ( $permissions as $permission ) {
                App::authorization_service()->authorize( $user_id, $ids['clinic_id'], $permission );
            }
        } catch ( \ClinicCore\Application\Authorization\AuthorizationException $e ) {
            return new WP_Error( 'CLINIC_PERMISSION_DENIED', 'دسترسی ندارید', [ 'status' => 403 ] );
        }

        if ( null === $ids['location_id'] && ! $allow_unscoped_context && ! $allow_missing_location ) {
            return self::toError(
                new ScopeRequiredException(
                    'CLINIC_SCOPE_REQUIRED',
                    'Explicit operational Location is required for Reception operations.',
                    [ 'field' => 'location_id', 'reason' => 'location_required' ],
                    400
                )
            );
        }

        try {
            $establisher = new TrustedClinicEstablisher( App::db(), new MembershipRepository( App::db() ) );
            $scope       = $establisher->establish( $user_id, $ids['clinic_id'], $ids['location_id'] );
        } catch ( ScopeRequiredException $e ) {
            return self::toError( $e );
        }

        self::pushRestoreState( $request, $scope );

        return true;
    }

    /**
     * Establish Doctor Portal tenant scope only after the endpoint permission
     * callback has authorized its Clinic capability. The shared after-callback
     * and shutdown restoration lifecycle remains identical to generic staff scope.
     */
    public static function bind_doctor_portal_scope_after_authorization(
        WP_REST_Request $request,
        int $user_id,
        int $clinic_id,
        int $location_id
    ): bool|WP_Error {
        try {
            $establisher = new TrustedClinicEstablisher( App::db(), new MembershipRepository( App::db() ) );
            $scope       = $establisher->establish_for_doctor_portal(
                $user_id,
                $clinic_id,
                $location_id,
                self::doctor_portal_visit_id( (string) $request->get_route() )
            );
        } catch ( ScopeRequiredException $e ) {
            return self::toError( $e );
        }

        self::pushRestoreState( $request, $scope );

        return true;
    }

    /**
     * @param mixed $response
     * @param mixed $handler
     * @return mixed
     */
    public static function afterCallbacks($response, $handler, $request)
    {
        if (!$request instanceof WP_REST_Request) {
            return $response;
        }
        self::restoreForRequest((int) spl_object_id($request));

        return $response;
    }

    /**
     * ذخیرهٔ Scope قبلی + ثبت net پایانیِ فرآیند.
     *
     * net پایانی لازم است چون اگر callback مسیرِ مهار‌نشدده استثنا بدهد،
     * WordPress فیلتر `rest_request_after_callbacks` را اجرا نمی‌کند و Scope
     * درخواست در فرآیند (worker/PHP‑FPM یا فرآیند تست) باقی می‌ماند.
     */
    private static function pushRestoreState(WP_REST_Request $request, ClinicScope $scope): void
    {
        self::$stack[] = [
            'rid' => (int) spl_object_id($request),
            'previous' => ScopeContext::tryGet(),
            'bound' => $scope,
        ];
        if (!self::$shutdownHookRegistered) {
            self::$shutdownHookRegistered = true;
            add_action('shutdown', [self::class, 'restoreAllPending'], -100);
        }
        App::replaceExplicitScope($scope);
    }

    /**
     * بازگردانیِ جفتِ همان درخواست (از درون‌ترین به بیرونی‌ترین).
     *
     * دو محافظ: (۱) فقط جفتِ متعلق به همین درخواست pop می‌شود تا درخواست‌های
     * تودرتو/پشت‌سرهم همدیگر را خراب نکنند؛ (۲) restore فقط وقتی انجام می‌شود
     * که Scope فعلی همان scopeی باشد که *این* مرز بسته است — بنابراین Scope
     * مشروعی که لایهٔ بالاتر (job/system) جابه‌جا کرده، پاک نمی‌شود و هیچ
     * مقدار پیش‌فرض یا Clinic «اولیه» جای آن نمی‌نشیند.
     */
    private static function restoreForRequest(int $rid): void
    {
        for ($i = count(self::$stack) - 1; $i >= 0; $i--) {
            if ((int) self::$stack[$i]['rid'] !== $rid) {
                continue;
            }
            $entry = self::$stack[$i];
            array_splice(self::$stack, $i, 1);
            self::syncShutdownHook();
            if (ScopeContext::tryGet() === $entry['bound']) {
                App::replaceExplicitScope($entry['previous']);
            }

            return;
        }
    }

    /** hook فقط تا وقتی لازم است که جفتِ بازی روی stack باشد. */
    private static function syncShutdownHook(): void
    {
        if (self::$stack !== [] || !self::$shutdownHookRegistered) {
            return;
        }
        self::$shutdownHookRegistered = false;
        remove_action('shutdown', [self::class, 'restoreAllPending'], -100);
    }

    /**
     * آخرین خطای ایمنی: هر جفتِ بازمانده (استثنای مهار‌نشدده در handler) را
     * از بیرونی‌ترینِ باقی‌مانده restore می‌کند — یعنی Scope قبلیِ همان
     * درخواست، نه هیچ مقدار پیش‌فرض/Clinic 1.
     */
    public static function restoreAllPending(): void
    {
        while (self::$stack !== []) {
            $outer = array_shift(self::$stack);
            if (ScopeContext::tryGet() === $outer['bound']) {
                App::replaceExplicitScope($outer['previous']);
            }
        }
        self::syncShutdownHook();
    }

    public static function requiresTrustedClinic(WP_REST_Request $request): bool
    {
        $route = (string) $request->get_route();
        if (!str_starts_with($route, '/clinic/v1/')) {
            return false;
        }

        // Reception resolves Clinic authority in its permission callback so
        // role/capability authorization happens before tenant selector checks.
        if ( self::is_reception_route( $route ) ) {
            return false;
        }

        /*
         * C6 repair — این مرز فقط برای «استفادهٔ staff از عملیات Clinic‑scoped» است.
         *
         * درخواستِ بیمار/کاربرِ فاقدِ نقشِ کارکنی به درخواستِ scope‑دارِ کارکنی
         * تبدیل نمی‌شود «فقط چون URL زیر /clinic/v1/ است»؛ همان مسیر همیشگی
         * (permission_callback خشن + سیاست Service) جریان می‌یابد تا انکارِ
         * پایدار (CLINIC_PERMISSION_DENIED) حفظ شود.
         *
         * نکتهٔ معماری: این سنجش «منبع مجوز» نیست (Phase 3 نیست) — نقش سراسری WP
         * رابطهٔ tenant را تعریف نمی‌کند و اینجا فقط تعیین می‌کند که استقرار
         * Clinic مورد اعتماد اصلاً روی این درخواست اعمال شود یا نه. «staff» بودنِ
         * واقعی همچنان با Capability در لایهٔ خشن و با سیاست Service/Membership
         * سنجیده می‌شود و fail‑closed است: staff بدون عضویت فعال → 403.
         */
        if ( ! self::current_user_is_staff( $request ) ) {
            return false;
        }

        // Doctor Portal Workspace endpoints bind from their own endpoint
        // capability boundary; context/selector routes remain unscoped. The
        // queue projection aliases are handled below because QueueController
        // consumes the scope established by this post-permission REST hook.
        if (
            preg_match( '#^/clinic/v1/doctor/portal(?:/|$)#', $route ) === 1
            && ! self::is_doctor_portal_queue_route( $route )
        ) {
            return false;
        }

        $method = strtoupper($request->get_method());

        $skip = [
            '#^/clinic/v1/health$#',
            '#^/clinic/v1/otp/#',
            '#^/clinic/v1/availability$#',
            '#^/clinic/v1/booking/#',
            '#^/clinic/v1/appointments/mine$#',
            '#^/clinic/v1/appointments/\d+/reschedule$#',
            '#^/clinic/v1/patient/me$#',
            '#^/clinic/v1/patient/enroll$#', // Patient-self authorization, not staff membership.
            '#^/clinic/v1/patient/my-records$#',
            '#^/clinic/v1/files/\d+/stream$#',
            '#^/clinic/v1/patients/\d+/files$#',
            '#^/clinic/v1/prescriptions$#',
        ];
        foreach ($skip as $pattern) {
            if (preg_match($pattern, $route) === 1) {
                return false;
            }
        }

        if ($method === 'GET' && preg_match('#^/clinic/v1/visits$#', $route) === 1) {
            return false;
        }
        if ($method === 'GET' && preg_match('#^/clinic/v1/visits/\d+$#', $route) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @return array{clinic_id: int|null, location_id: int|null}
     *
     * @throws ScopeRequiredException
     */
    private static function extractIds(WP_REST_Request $request): array
    {
        $clinic = self::combine(
            $request->get_header('X-CPMS-Clinic-Id'),
            $request->get_param('clinic_id'),
            'clinic_id'
        );
        // Schedule configuration sends the target Location as an operational
        // BODY field; it is validated by ScheduleService (not-found parity), so
        // it must not be mistaken for a tenant scope selector. An explicit
        // X-CPMS-Location-Id header still binds scope normally.
        $location = preg_match( '#^/clinic/v1/config/schedules(?:/|$)#', (string) $request->get_route() ) === 1
            ? self::combine( $request->get_header( 'X-CPMS-Location-Id' ), null, 'location_id' )
            : self::combine(
                $request->get_header( 'X-CPMS-Location-Id' ),
                $request->get_param( 'location_id' ),
                'location_id'
            );
        // Reports/exports are Clinic-wide: a stale selector for an inactive
        // Location of the SAME Clinic must not suppress an authorized read.
        // Foreign, malformed and absent-Clinic selectors keep their established
        // validation and denial envelopes.
        if ( null !== $location && self::is_clinic_wide_report_route( (string) $request->get_route() ) ) {
            $location = self::report_location_selector( $clinic, $location );
        }

        return [
            'clinic_id' => $clinic,
            'location_id' => $location,
        ];
    }

    /**
     * @throws ScopeRequiredException
     */
    private static function combine(mixed $header, mixed $param, string $field): ?int
    {
        $headerId = self::parseOptionalId($header, $field);
        $paramId = self::parseOptionalId($param, $field);
        if ($headerId !== null && $paramId !== null && $headerId !== $paramId) {
            throw new ScopeRequiredException(
                'CLINIC_VALIDATION_FAILED',
                'Clinic/location header and parameter disagree.',
                ['field' => $field],
                422
            );
        }

        return $headerId ?? $paramId;
    }

    /**
     * @throws ScopeRequiredException
     */
    private static function parseOptionalId(mixed $raw, string $field): ?int
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^[1-9][0-9]{0,18}$/', $raw) === 1) {
            return (int) $raw;
        }

        throw new ScopeRequiredException(
            'CLINIC_VALIDATION_FAILED',
            'Invalid clinic/location identifier.',
            ['field' => $field],
            422
        );
    }

    private static function is_reception_route( string $route ): bool {
        return preg_match( '#^/clinic/v1/staff/portal/reception(?:/|$)#', $route ) === 1;
    }

    private static function is_doctor_portal_queue_route( string $route ): bool {
        return in_array(
            $route,
            [
                '/clinic/v1/doctor/today',
                '/clinic/v1/doctor/portal/today',
                '/clinic/v1/doctor/portal/rt/queue',
            ],
            true
        );
    }

    private static function is_clinic_wide_report_route( string $route ): bool {
        return preg_match( '#^/clinic/v1/reports(?:/|$)#', $route ) === 1;
    }

    /**
     * Keep the explicit report selector unless it names an INACTIVE Location
     * that durably belongs to the requested Clinic. In that one case the
     * Clinic-wide report keeps working (the Location is not a report filter);
     * every other selector keeps normal trusted-context validation.
     */
    private static function report_location_selector( ?int $clinic_id, int $location_id ): ?int {
        if ( null === $clinic_id || $clinic_id <= 0 || $location_id <= 0 ) {
            return $location_id;
        }

        $row = App::db()->fetchRow(
            'SELECT clinic_id, is_active FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE id = %d LIMIT 1',
            [ $location_id ]
        );
        if ( null === $row || (int) ( $row['clinic_id'] ?? 0 ) !== $clinic_id ) {
            return $location_id;
        }

        return 1 === (int) ( $row['is_active'] ?? 0 ) ? $location_id : null;
    }

    /**
     * Verify the Doctor Portal Clinic boundary (active membership + durable
     * Clinic/Organization) WITHOUT binding a request scope. Endpoint capability
     * authorization runs after this check and before any Location validation,
     * so tenant existence is never disclosed ahead of capability denial.
     */
    public static function verify_doctor_portal_clinic( int $user_id, int $clinic_id ): bool|WP_Error {
        try {
            ( new TrustedClinicEstablisher( App::db(), new MembershipRepository( App::db() ) ) )
                ->establish_for_doctor_portal( $user_id, $clinic_id, null );
        } catch ( ScopeRequiredException $e ) {
            return self::toError( $e );
        }

        return true;
    }

    private static function doctor_portal_visit_id( string $route ): ?int {
        if ( preg_match( '#^/clinic/v1/doctor/portal/visits/([1-9][0-9]*)/#', $route, $matches ) !== 1 ) {
            return null;
        }

        return (int) $matches[1];
    }

    private static function current_user_is_staff( WP_REST_Request $request ): bool
    {
        $userId = get_current_user_id();
        $user = $userId > 0 ? get_userdata($userId) : false;
        if ($user === false || !$user->exists()) {
            return false;
        }
        $roles = (array) $user->roles;
        $staffRoles = [
            RolesAndCapabilities::ROLE_SECRETARY,
            RolesAndCapabilities::ROLE_DOCTOR,
            RolesAndCapabilities::ROLE_ACCOUNTANT,
            RolesAndCapabilities::ROLE_MANAGER,
            'administrator',
        ];
        if (array_intersect($staffRoles, $roles) !== []) {
            return true;
        }

        if (
            $user->has_cap( RolesAndCapabilities::REPORT_READ )
            || $user->has_cap( RolesAndCapabilities::APPT_CANCEL )
            || $user->has_cap( RolesAndCapabilities::INVOICE_READ )
            || $user->has_cap( RolesAndCapabilities::QUEUE_READ )
            || $user->has_cap( RolesAndCapabilities::CONFIG )
        ) {
            return true;
        }

        // 3B follow-up — a Clinic-local custom role is DURABLE MEMBERSHIP data,
        // never a WordPress-global grant, so the WP-global signals above can
        // never recognize it. An actor with an ACTIVE STAFF membership in the
        // REQUESTED Clinic is routed through scope establishment; this grants no
        // authority of its own: TrustedClinicEstablisher still requires the
        // durable ACTIVE membership and a real trusted Clinic, every route gate
        // and the delegated service still decide capability and object ownership
        // (fail closed), and non-members are still refused. Patient memberships
        // are deliberately excluded — this is the staff surface.
        return self::has_active_staff_membership_in_request( (int) get_current_user_id(), $request );
    }

    /**
     * Durable, request-scoped staff-membership probe (routing signal only; never
     * an authorization decision — see the contract above).
     */
    private static function has_active_staff_membership_in_request( int $user_id, WP_REST_Request $request ): bool
    {
        if ( $user_id <= 0 ) {
            return false;
        }

        $raw = $request->get_header( 'X-CPMS-Clinic-Id' );
        if ( ! is_scalar( $raw ) || '' === trim( (string) $raw ) ) {
            $raw = $request->get_param( 'clinic_id' );
        }
        $clinic_id = is_scalar( $raw ) ? (int) $raw : 0;
        if ( $clinic_id <= 0 ) {
            return false;
        }

        $membership = ( new MembershipRepository( App::db() ) )->find_active( $clinic_id, $user_id );
        if ( $membership === null ) {
            return false;
        }
        $role_key = (string) ( $membership['role_key'] ?? '' );

        return in_array(
            $role_key,
            [
                RolesAndCapabilities::ROLE_SECRETARY,
                RolesAndCapabilities::ROLE_DOCTOR,
                RolesAndCapabilities::ROLE_ACCOUNTANT,
                RolesAndCapabilities::ROLE_MANAGER,
            ],
            true
        ) || CustomRolePolicy::is_definable_role_key( $role_key );
    }

    private static function toError( ScopeRequiredException $e ): WP_Error { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
        $reason = (string) ( $e->data['reason'] ?? '' );
        if ( '' !== $reason ) {
            error_log( '[CPMS][RestClinicContext] ' . $e->errorCode . ' reason=' . $reason ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.PHP.DevelopmentFunctions.error_log_error_log -- legacy PSR-style, established contract
        }

        $message = match ( $e->errorCode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
            'CLINIC_SCOPE_REQUIRED' => __( 'محدودهٔ کلینیک لازم است.', 'cpms' ),
            'CLINIC_VALIDATION_FAILED' => __( 'شناسهٔ کلینیک یا محل نامعتبر است.', 'cpms' ),
            default => __( 'امکان تعیین محدودهٔ کلینیک معتبر نیست.', 'cpms' ),
        };

        $data = [ 'status' => $e->httpStatus() ];

        // Preserve only bounded, non-sensitive selector-required metadata.
        // Never expose eligible IDs, membership internals, or tenant topology.
        if ( 'CLINIC_SCOPE_REQUIRED' === $e->errorCode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
            $field      = $e->data['field'] ?? null;
            $reason_val = $e->data['reason'] ?? null;
            if ( 'location_id' === $field && 'location_required' === $reason_val ) {
                $data['field']  = 'location_id';
                $data['reason'] = 'location_required';
            } elseif ( 'clinic_id' === $field && 'clinic_required' === $reason_val ) {
                $data['field']  = 'clinic_id';
                $data['reason'] = 'clinic_required';
            }
            // Generic multiple-membership ambiguity remains compatible: no field relabel.
        }

        if ( 'CLINIC_VALIDATION_FAILED' === $e->errorCode && isset( $e->data['field'] ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
            $f = $e->data['field'];
            if ( in_array( $f, [ 'clinic_id', 'location_id' ], true ) ) {
                $data['field'] = $f;
            }
        }

        // Never expose eligible_location_ids, membership internals, topology.
        return new WP_Error( $e->errorCode, $message, $data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
    }
}
