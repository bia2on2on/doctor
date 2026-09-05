<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Application\Booking\ScheduleService;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpointهای مدیریت برنامه کاری (F3) — API Contract G1 (+ توسعه Additive برای
 * استثنائات SRS FR-3.2 — ثبت‌شده در Contract v1.1 و گزارش F3).
 *
 * مجوز: Capability `cpms_config` (Admin فنی — Matrix §4.4) + Nonce روی موتانت‌ها.
 * داده‌ها فنی‌اند — بدون PHI.
 *
 * خطاها: Envelope استاندارد `CLINIC_*` (ADR-0019).
 */
final class ScheduleController extends RestBase
{
    public function __construct(private readonly ScheduleService $schedules)
    {
    }

    public function register_routes(): void
    {
        // ---------- G1 — Weekly Schedule ----------
        register_rest_route(self::NS, '/config/schedules', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->list($request),
                'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::CONFIG),
                'args' => [
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->create($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->configMutation($request),
                'args' => [
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'day_of_week' => ['required' => true, 'type' => 'integer'],
                    'start_time' => ['required' => true, 'type' => 'string'],
                    'end_time' => ['required' => true, 'type' => 'string'],
                    'break_start' => ['required' => false, 'type' => 'string'],
                    'break_end' => ['required' => false, 'type' => 'string'],
                    'appointment_duration_min' => ['required' => false, 'type' => 'integer'],
                    'slot_capacity' => ['required' => false, 'type' => 'integer'],
                    'is_active' => ['required' => false, 'type' => 'boolean'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/config/schedules/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $request) => $this->update($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->configMutation($request),
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                    'start_time' => ['required' => false, 'type' => 'string'],
                    'end_time' => ['required' => false, 'type' => 'string'],
                    'break_start' => ['required' => false, 'type' => 'string'],
                    'break_end' => ['required' => false, 'type' => 'string'],
                    'appointment_duration_min' => ['required' => false, 'type' => 'integer'],
                    'slot_capacity' => ['required' => false, 'type' => 'integer'],
                    'is_active' => ['required' => false, 'type' => 'boolean'],
                ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => fn (WP_REST_Request $request) => $this->delete($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->configMutation($request),
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                ],
            ],
        ]);

        // ---------- G1b — Schedule Exceptions (SRS FR-3.2) ----------
        register_rest_route(self::NS, '/config/schedule-exceptions', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->listExceptions($request),
                'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::CONFIG),
                'args' => [
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'from' => ['required' => false, 'type' => 'string', 'default' => gmdate('Y-m-d')],
                    'to' => ['required' => false, 'type' => 'string', 'default' => gmdate('Y-m-d', time() + 90 * 86400)],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->createException($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->configMutation($request),
                'args' => [
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'date' => ['required' => true, 'type' => 'string'],
                    'type' => ['required' => true, 'type' => 'string', 'enum' => ['holiday', 'leave', 'blocked', 'open_override']],
                    'start_time' => ['required' => false, 'type' => 'string'],
                    'end_time' => ['required' => false, 'type' => 'string'],
                    'reason' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/config/schedule-exceptions/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => fn (WP_REST_Request $request) => $this->deleteException($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->configMutation($request),
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                ],
            ],
        ]);
    }

    // ---------- Handlers ----------

    private function list(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->wrap(fn () => $this->schedules->list((int) $request->get_param('clinician_id')));
    }

    private function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->schedules->create((int) $user->ID, $this->body($request)));
    }

    private function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->schedules->update(
            (int) $user->ID,
            (int) $request->get_param('id'),
            $this->body($request)
        ));
    }

    private function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->schedules->delete((int) $user->ID, (int) $request->get_param('id')));
    }

    private function listExceptions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->wrap(fn () => $this->schedules->listExceptions(
            (int) $request->get_param('clinician_id'),
            (string) $request->get_param('from'),
            (string) $request->get_param('to')
        ));
    }

    private function createException(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->schedules->createException((int) $user->ID, $this->body($request)));
    }

    private function deleteException(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->schedules->deleteException((int) $user->ID, (int) $request->get_param('id')));
    }

    // ---------- Helpers ----------

    /**
     * موتانت Config: Capability `cpms_config` + Nonce (CSRF).
     */
    private function configMutation(WP_REST_Request $request): bool|WP_Error
    {
        $nonceError = $this->requireNonce($request);
        if ($nonceError instanceof WP_Error) {
            return $nonceError;
        }

        return $this->requireCap(RolesAndCapabilities::CONFIG);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $request): array
    {
        $params = $request->get_params();

        return is_array($params) ? $params : [];
    }

    /**
     * Wrap استاندارد: BookingException → WP_Error با کد CLINIC_* + HTTP.
     *
     * @template T
     * @param callable(): T $fn
     * @return WP_REST_Response|WP_Error
     */
    private function wrap(callable $fn): WP_REST_Response|WP_Error
    {
        try {
            return $this->success($fn(), 200);
        } catch (BookingException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        }
    }
}
