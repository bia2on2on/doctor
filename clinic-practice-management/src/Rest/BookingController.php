<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Booking\BookingException;
use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpointهای نوبت‌دهی (F3) — API Contract A1/A4 + B1–B6 + D9–D11.
 *
 * مجوز (5 لایه — auth-authorization.md §2.1):
 *  1) Authentication/Nonce  2) Capability/Role  3) Data-Access (در Service)
 *  4) Field-Access (View Service)  5) Action Rules (Service + State Machine).
 *
 * GAP-1/G-3 (تأیید 2026-09-05): B1/D10 `clinician_id` الزامی؛ B5 اختیاری
 * (Default = پزشک فعلی نوبت — در Controller resolve می‌شود).
 */
final class BookingController extends RestBase
{
    public function __construct(private readonly BookingService $booking)
    {
    }

    public function register_routes(): void
    {
        // ---------- Public ----------
        register_rest_route(self::NS, '/availability', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->availability($request),
                'permission_callback' => '__return_true',
                'args' => [
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'from' => ['required' => false, 'type' => 'string', 'default' => gmdate('Y-m-d')],
                    'to' => ['required' => false, 'type' => 'string', 'default' => gmdate('Y-m-d', time() + 29 * 86400)],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/booking/quote', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->quote($request),
                'permission_callback' => '__return_true',
                'args' => [
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'slot_date' => ['required' => true, 'type' => 'string'],
                    'slot_time' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        // ---------- Patient (B1–B6) ----------
        register_rest_route(self::NS, '/booking/hold', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->hold($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->requirePatient($request) !== null,
                'args' => [
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'slot_date' => ['required' => true, 'type' => 'string'],
                    'slot_time' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/booking/confirm', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->confirm($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->requirePatient($request) !== null,
                'args' => [
                    'hold_token' => ['required' => true, 'type' => 'string'],
                    'reason' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/booking/resume', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->resume($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->requirePatient($request) !== null,
                'args' => [
                    'hold_token' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/appointments/mine', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->mine($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->requirePatient($request) !== null,
                'args' => [
                    'from' => ['required' => false, 'type' => 'string', 'default' => gmdate('Y-m-d')],
                    'to' => ['required' => false, 'type' => 'string', 'default' => gmdate('Y-m-d', time() + 365 * 86400)],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/appointments/{id}/reschedule', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->reschedule($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->requirePatient($request) !== null,
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                    'slot_date' => ['required' => true, 'type' => 'string'],
                    'slot_time' => ['required' => true, 'type' => 'string'],
                    'clinician_id' => ['required' => false, 'type' => 'integer'],
                ],
            ],
        ]);

        // ---------- Secretary/Staff (D9–D11) ----------
        register_rest_route(self::NS, '/appointments', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->staffList($request),
                'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::APPT_READ) === null,
                'args' => [
                    'date' => ['required' => false, 'type' => 'string', 'default' => gmdate('Y-m-d')],
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'status' => ['required' => false, 'type' => 'string'],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->staffCreate($request),
                'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::APPT_CREATE) === null,
                'args' => [
                    'patient_id' => ['required' => true, 'type' => 'integer'],
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'slot_date' => ['required' => true, 'type' => 'string'],
                    'slot_time' => ['required' => true, 'type' => 'string'],
                    'reason' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/appointments/{id}/cancel', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->cancel($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->cancelPermission($request),
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                    'reason' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);
    }

    // ---------- Handlers ----------

    private function availability(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->wrap(fn () => $this->booking->availability(
            (int) $request->get_param('clinician_id'),
            (string) $request->get_param('from'),
            (string) $request->get_param('to')
        ));
    }

    private function quote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->wrap(fn () => $this->booking->quote(
            (int) $request->get_param('clinician_id'),
            (string) $request->get_param('slot_date'),
            (string) $request->get_param('slot_time')
        ));
    }

    private function hold(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        $rate = $this->rateLimit($request, 'booking-' . (int) $user->ID, 10, 3600);
        if ($rate instanceof WP_Error) {
            return $rate;
        }

        return $this->wrap(fn () => $this->booking->hold(
            (int) $user->ID,
            (int) $request->get_param('clinician_id'),
            (string) $request->get_param('slot_date'),
            (string) $request->get_param('slot_time')
        ));
    }

    private function confirm(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        $key = $this->idempotencyKey($request);
        if ($key === null) {
            return $this->error('CLINIC_VALIDATION_FAILED', 400, 'هدر Idempotency-Key (UUID) برای confirm الزامی است');
        }

        return $this->wrap(fn () => $this->booking->confirm(
            (string) $request->get_param('hold_token'),
            (int) $user->ID,
            $request->get_param('reason') !== null ? (string) $request->get_param('reason') : null,
            $key
        ));
    }

    private function resume(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->booking->resume(
            (string) $request->get_param('hold_token'),
            (int) $user->ID
        ));
    }

    private function mine(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->booking->listMine(
            (int) $user->ID,
            (string) $request->get_param('from'),
            (string) $request->get_param('to')
        ));
    }

    private function reschedule(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        $key = $this->idempotencyKey($request);
        if ($key === null) {
            return $this->error('CLINIC_VALIDATION_FAILED', 400, 'هدر Idempotency-Key (UUID) برای reschedule الزامی است');
        }
        $appointmentId = (int) $request->get_param('id');

        return $this->wrap(function () use ($request, $user, $key, $appointmentId) {
            // GAP-1/G-3: clinician_id اختیاری — Default = پزشک فعلی نوبت.
            // (Data-Access/مالکیت در Service enforce می‌شود؛ این خط فقط مقدار فیلد را resolve می‌کند.)
            $newClinicianId = $request->get_param('clinician_id');
            if ($newClinicianId === null || $newClinicianId === '') {
                $current = $this->clinicianIdOfAppointment($appointmentId);
                if ($current === null) {
                    throw new BookingException('CLINIC_NOT_FOUND', 'نوبت یافت نشد', 404);
                }
                $newClinicianId = $current;
            }

            return $this->booking->reschedule(
                (int) $user->ID,
                $appointmentId,
                (int) $newClinicianId,
                (string) $request->get_param('slot_date'),
                (string) $request->get_param('slot_time'),
                $key
            );
        });
    }

    private function staffList(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->wrap(fn () => $this->booking->listForClinician(
            (int) $request->get_param('clinician_id'),
            (string) $request->get_param('date'),
            $request->get_param('status') !== null ? (string) $request->get_param('status') : null
        ));
    }

    private function staffCreate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        $rate = $this->rateLimit($request, 'booking-' . (int) $user->ID, 10, 3600);
        if ($rate instanceof WP_Error) {
            return $rate;
        }

        return $this->wrap(fn () => $this->booking->createByStaff(
            (int) $user->ID,
            (int) $request->get_param('patient_id'),
            (int) $request->get_param('clinician_id'),
            (string) $request->get_param('slot_date'),
            (string) $request->get_param('slot_time'),
            $request->get_param('reason') !== null ? (string) $request->get_param('reason') : null
        ));
    }

    private function cancel(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        $appointmentId = (int) $request->get_param('id');
        $reason = $request->get_param('reason') !== null ? (string) $request->get_param('reason') : null;
        $isStaff = $user->has_cap(RolesAndCapabilities::APPT_CANCEL);

        return $this->wrap(fn () => $isStaff
            ? $this->booking->cancelByStaff((int) $user->ID, $appointmentId, $reason)
            : $this->booking->cancelByPatient((int) $user->ID, $appointmentId, $reason));
    }

    // ---------- Permission / error helpers ----------

    /**
     * Patient-Endpoint: Nonce (CSRF) + وجود Session + نقش بیمار (یا Staff با Cap مربوطه).
     */
    private function requirePatient(WP_REST_Request $request): ?WP_Error
    {
        $nonceError = $this->requireNonce($request);
        if ($nonceError instanceof WP_Error) {
            return $nonceError;
        }
        $user = wp_get_current_user();
        if (!$user->exists()) {
            return $this->error('CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید');
        }
        $isPatient = in_array(RolesAndCapabilities::ROLE_PATIENT, (array) $user->roles, true);
        if (!$isPatient) {
            App::audit()->log(
                'FORBIDDEN_ACCESS_ATTEMPT',
                ['wp_user_id' => (int) $user->ID, 'role' => $user->roles[0] ?? 'unknown'],
                'booking',
                null,
                null,
                null,
                null,
                ['reason' => 'patient_role_required']
            );

            return $this->error('CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید');
        }

        return null;
    }

    private function cancelPermission(WP_REST_Request $request): bool
    {
        $nonceError = $this->requireNonce();
        if ($nonceError instanceof WP_Error) {
            return false;
        }
        $user = wp_get_current_user();
        if (!$user->exists()) {
            return false;
        }

        return in_array(RolesAndCapabilities::ROLE_PATIENT, (array) $user->roles, true)
            || $user->has_cap(RolesAndCapabilities::APPT_CANCEL);
    }

    /**
     * @return int|null
     */
    private function clinicianIdOfAppointment(int $appointmentId): ?int
    {
        $v = App::db()->fetchValue(
            'SELECT clinician_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d LIMIT 1',
            [$appointmentId]
        );

        return $v === null ? null : (int) $v;
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
