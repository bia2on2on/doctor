<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_Error;
use WP_REST_Request;

/**
 * مرز REST برای استقرار ClinicScope مورد اعتماد.
 *
 * استخراج شناسه فقط اینجاست (هدر/پارامتر). تأیید عضویت و تعلق در
 * {@see TrustedClinicEstablisher} است — مستقل از HTTP.
 *
 * Bind در `rest_request_before_callbacks` است نه permission_callback
 * (تست‌های permission بدون dispatch صدا زده می‌شوند).
 */
final class RestClinicContext
{
    /**
     * Scope قبلی هر درخواستی که bind شده — keyed by spl_object_id.
     *
     * @var array<int, ClinicScope|null>
     */
    private static array $previousByRequest = [];

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

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return $response;
        }

        try {
            $ids = self::extractIds($request);
            $establisher = new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db()));
            $scope = $establisher->establish($userId, $ids['clinic_id'], $ids['location_id']);
        } catch (ScopeRequiredException $e) {
            return self::toError($e);
        }

        $rid = spl_object_id($request);
        self::$previousByRequest[$rid] = ScopeContext::tryGet();
        App::replaceExplicitScope($scope);

        return $response;
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
        $rid = spl_object_id($request);
        if (!array_key_exists($rid, self::$previousByRequest)) {
            return $response;
        }
        $previous = self::$previousByRequest[$rid];
        unset(self::$previousByRequest[$rid]);
        App::replaceExplicitScope($previous);

        return $response;
    }

    public static function requiresTrustedClinic(WP_REST_Request $request): bool
    {
        $route = (string) $request->get_route();
        if (!str_starts_with($route, '/clinic/v1/')) {
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

        if (preg_match('#^/clinic/v1/appointments/\d+/cancel$#', $route) === 1) {
            return self::currentUserIsStaff();
        }
        if (preg_match('#^/clinic/v1/(notifications|rt/notifications)#', $route) === 1) {
            return self::currentUserIsStaff();
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
        $location = self::combine(
            $request->get_header('X-CPMS-Location-Id'),
            $request->get_param('location_id'),
            'location_id'
        );

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

    private static function currentUserIsStaff(): bool
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

        return $user->has_cap(RolesAndCapabilities::REPORT_READ)
            || $user->has_cap(RolesAndCapabilities::APPT_CANCEL)
            || $user->has_cap(RolesAndCapabilities::INVOICE_READ)
            || $user->has_cap(RolesAndCapabilities::QUEUE_READ)
            || $user->has_cap(RolesAndCapabilities::CONFIG);
    }

    private static function toError(ScopeRequiredException $e): WP_Error
    {
        $reason = (string) ($e->data['reason'] ?? '');
        if ($reason !== '') {
            error_log('[CPMS][RestClinicContext] ' . $e->errorCode . ' reason=' . $reason);
        }

        $message = match ($e->errorCode) {
            'CLINIC_SCOPE_REQUIRED' => __('محدودهٔ کلینیک لازم است.', 'cpms'),
            'CLINIC_VALIDATION_FAILED' => __('شناسهٔ کلینیک یا محل نامعتبر است.', 'cpms'),
            default => __('امکان تعیین محدودهٔ کلینیک معتبر نیست.', 'cpms'),
        };

        $data = ['status' => $e->httpStatus()];
        if ($e->errorCode === 'CLINIC_VALIDATION_FAILED' && isset($e->data['field'])) {
            $data['field'] = $e->data['field'];
        }

        return new WP_Error($e->errorCode, $message, $data);
    }
}
