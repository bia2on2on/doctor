<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Machine\InvalidTransitionException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpointهای Check-in و صف (F4 — Slice 1).
 *
 * این Controller فقط لایه REST است؛ ساخت Visit، Transition و History در VisitService است.
 */
final class VisitController extends RestBase
{
    public function __construct(private readonly VisitService $visits)
    {
    }

    public function register_routes(): void
    {
        register_rest_route(self::NS, '/visits/checkin', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->checkIn($request),
            'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::QUEUE_CHECKIN) === null,
        ]);

        register_rest_route(self::NS, '/visits/walk-in', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->walkIn($request),
            'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::QUEUE_CHECKIN) === null,
        ]);

        register_rest_route(self::NS, '/visits/(?P<id>\d+)/status', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->status($request),
            'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::QUEUE_ADVANCE) === null,
        ]);

        register_rest_route(self::NS, '/queue', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request) => $this->queue($request),
            'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::QUEUE_READ) === null,
        ]);
    }

    private function checkIn(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($error = $this->requireNonce($request)) !== null) {
            return $error;
        }
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->visits->checkIn(
            (int) $user->ID,
            (int) $request->get_param('patient_id'),
            (int) $request->get_param('appointment_id')
        ));
    }

    private function walkIn(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($error = $this->requireNonce($request)) !== null) {
            return $error;
        }
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->visits->walkIn(
            (int) $user->ID,
            (int) $request->get_param('patient_id'),
            (int) $request->get_param('clinician_id'),
            $request->get_param('visit_date') !== null ? (string) $request->get_param('visit_date') : null
        ));
    }

    private function status(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($error = $this->requireNonce($request)) !== null) {
            return $error;
        }
        $user = wp_get_current_user();
        $role = in_array(RolesAndCapabilities::ROLE_DOCTOR, (array) $user->roles, true) ? 'doctor' : 'secretary';

        return $this->wrap(fn () => $this->visits->transition(
            (int) $user->ID,
            $role,
            (int) $request['id'],
            (string) $request->get_param('event'),
            $request->get_param('note') !== null ? (string) $request->get_param('note') : null
        ));
    }

    private function queue(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->wrap(fn () => [
            'items' => $this->visits->queue(
                (int) $request->get_param('clinician_id'),
                (string) ($request->get_param('date') ?: gmdate('Y-m-d')),
                $request->get_param('status') !== null ? (string) $request->get_param('status') : null
            ),
        ]);
    }

    private function wrap(callable $fn): WP_REST_Response|WP_Error
    {
        try {
            return $this->success($fn());
        } catch (BookingException|InvalidTransitionException $e) {
            $code = $e instanceof BookingException ? $e->errorCode : InvalidTransitionException::CODE;
            $status = $e instanceof BookingException ? $e->httpStatus : 409;
            $data = $e instanceof BookingException ? $e->data : [];

            return $this->error($code, $status, $e->getMessage(), $data);
        } catch (\Throwable $e) {
            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی رخ داد');
        }
    }
}
