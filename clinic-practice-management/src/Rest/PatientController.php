<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Booking\BookingException;
use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpointهای پروفایل بیمار (F3) — API Contract C1/C2 + D2–D5.
 *
 * C*: بیمار (Nonce + نقش) — فقط Data خود.
 * D*: منشی (Nonce + Capability) — Data-Access در Service.
 */
final class PatientController extends RestBase
{
    public function __construct(private readonly PatientService $patients)
    {
    }

    public function register_routes(): void
    {
        // ---------- Patient (C1/C2) ----------
        register_rest_route(self::NS, '/patient/me', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->me($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->requirePatient($request),
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $request) => $this->updateMe($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->requirePatient($request),
                'args' => [
                    'first_name' => ['required' => false, 'type' => 'string'],
                    'last_name' => ['required' => false, 'type' => 'string'],
                    'birth_date' => ['required' => false, 'type' => 'string'],
                    'gender' => ['required' => false, 'type' => 'string'],
                    'address' => ['required' => false, 'type' => 'string'],
                    'phone' => ['required' => false, 'type' => 'string'],
                    'national_id' => ['required' => false, 'type' => 'string'],
                    'emergency_contact_name' => ['required' => false, 'type' => 'string'],
                    'emergency_contact_phone' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        // ---------- Secretary (D2–D5) ----------
        register_rest_route(self::NS, '/patients/search', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->search($request),
                'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::PATIENT_READ),
                'args' => [
                    'q' => ['required' => true, 'type' => 'string'],
                    'limit' => ['required' => false, 'type' => 'integer', 'default' => 25],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/patients/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->get($request),
                'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::PATIENT_READ),
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                ],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $request) => $this->update($request),
                'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::PATIENT_UPDATE),
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/patients', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->create($request),
                'permission_callback' => fn () => $this->requireCap(RolesAndCapabilities::PATIENT_CREATE),
                'args' => [
                    'first_name' => ['required' => true, 'type' => 'string'],
                    'last_name' => ['required' => true, 'type' => 'string'],
                    'mobile' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);
    }

    // ---------- Handlers ----------

    private function me(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->patients->me((int) $user->ID));
    }

    private function updateMe(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        $fields = $this->body($request);

        return $this->wrap(fn () => $this->patients->updateMe((int) $user->ID, $fields));
    }

    private function search(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->wrap(fn () => $this->patients->search(
            (string) $request->get_param('q'),
            (int) $request->get_param('limit')
        ));
    }

    private function get(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->wrap(fn () => $this->patients->get((int) $request->get_param('id')));
    }

    private function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        $fields = $this->body($request);

        return $this->wrap(fn () => $this->patients->update(
            (int) $request->get_param('id'),
            $fields,
            (int) $user->ID
        ));
    }

    private function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        $fields = $this->body($request);

        return $this->wrap(fn () => $this->patients->create($fields, (int) $user->ID));
    }

    // ---------- Helpers ----------

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
        if (!in_array(RolesAndCapabilities::ROLE_PATIENT, (array) $user->roles, true)) {
            App::audit()->log(
                'FORBIDDEN_ACCESS_ATTEMPT',
                ['wp_user_id' => (int) $user->ID, 'role' => $user->roles[0] ?? 'unknown'],
                'patient',
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

    /**
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $request): array
    {
        $params = $request->get_params();

        return is_array($params) ? $params : [];
    }

    /**
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
