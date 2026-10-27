<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Finance\VisitPricingService;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST boundary for doctor-managed consultation fees and draft visit charges.
 *
 * Reads need `cpms_invoice_read`, writes `cpms_invoice_create` (Clinic-scoped,
 * active membership, explicit DENY precedence). The service re-checks the
 * trusted Clinic/Location, the server-derived clinician identity, the Visit
 * ownership and the draft/settlement state on every call. No payment capture
 * or void is exposed here.
 */
final class VisitPricingController extends RestBase
{
    private const WRITE_PER_MINUTE = 30;

    private const READ_CAPS  = [RolesAndCapabilities::INVOICE_READ];
    private const WRITE_CAPS = [RolesAndCapabilities::INVOICE_READ, RolesAndCapabilities::INVOICE_CREATE];

    public function __construct(private readonly VisitPricingService $pricing)
    {
    }

    public function register_routes(): void
    {
        register_rest_route(self::NS, '/staff/portal/pricing/context', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => fn (WP_REST_Request $r): WP_REST_Response|WP_Error => $this->context($r),
                'permission_callback' => fn (WP_REST_Request $r): bool|WP_Error => $this->permission($r, self::READ_CAPS),
                'args'                => [
                    'location_id' => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/staff/portal/pricing/default-fee', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => fn (WP_REST_Request $r): WP_REST_Response|WP_Error => $this->default_fee($r),
                'permission_callback' => fn (WP_REST_Request $r): bool|WP_Error => $this->permission($r, self::WRITE_CAPS),
                'args'                => [
                    'amount'      => ['required' => true, 'type' => ['integer', 'string']],
                    'location_id' => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/staff/portal/pricing/visits', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => fn (WP_REST_Request $r): WP_REST_Response|WP_Error => $this->visits($r),
                'permission_callback' => fn (WP_REST_Request $r): bool|WP_Error => $this->permission($r, self::READ_CAPS),
            ],
        ]);

        register_rest_route(self::NS, '/staff/portal/pricing/visits/(?P<id>\d+)/billing', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => fn (WP_REST_Request $r): WP_REST_Response|WP_Error => $this->billing($r),
                'permission_callback' => fn (WP_REST_Request $r): bool|WP_Error => $this->permission($r, self::READ_CAPS),
            ],
        ]);

        register_rest_route(self::NS, '/staff/portal/pricing/visits/(?P<id>\d+)/consultation-fee', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => fn (WP_REST_Request $r): WP_REST_Response|WP_Error => $this->consultation_fee($r),
                'permission_callback' => fn (WP_REST_Request $r): bool|WP_Error => $this->permission($r, self::WRITE_CAPS),
                'args'                => [
                    'amount' => ['required' => true, 'type' => ['integer', 'string']],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/staff/portal/pricing/visits/(?P<id>\d+)/charges', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => fn (WP_REST_Request $r): WP_REST_Response|WP_Error => $this->add_charge($r),
                'permission_callback' => fn (WP_REST_Request $r): bool|WP_Error => $this->permission($r, self::WRITE_CAPS),
                'args'                => [
                    'description' => ['required' => true, 'type' => 'string'],
                    'amount'      => ['required' => true, 'type' => ['integer', 'string']],
                    'quantity'    => ['required' => false, 'type' => 'number'],
                    'note'        => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/staff/portal/pricing/visits/(?P<id>\d+)/charges/(?P<charge_id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => fn (WP_REST_Request $r): WP_REST_Response|WP_Error => $this->update_charge($r),
                'permission_callback' => fn (WP_REST_Request $r): bool|WP_Error => $this->permission($r, self::WRITE_CAPS),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => fn (WP_REST_Request $r): WP_REST_Response|WP_Error => $this->remove_charge($r),
                'permission_callback' => fn (WP_REST_Request $r): bool|WP_Error => $this->permission($r, self::WRITE_CAPS),
            ],
        ]);
    }

    private function context(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $location = isset($request['location_id']) ? (int) $request['location_id'] : null;

        return $this->run(fn (): array => $this->pricing->context($this->user_id(), $location));
    }

    private function default_fee(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->write_limit($request);
        if ($limited instanceof WP_Error) {
            return $limited;
        }
        $params   = $this->body($request);
        $location = isset($params['location_id']) ? (int) $params['location_id'] : null;

        return $this->run(fn (): array => $this->pricing->set_default_fee($this->user_id(), $location, $params['amount'] ?? null));
    }

    private function visits(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->run(fn (): array => $this->pricing->visits($this->user_id()));
    }

    private function billing(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $visit_id = (int) $request['id'];

        return $this->run(fn (): array => $this->pricing->billing_for_visit($this->user_id(), $visit_id));
    }

    private function consultation_fee(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->write_limit($request);
        if ($limited instanceof WP_Error) {
            return $limited;
        }
        $params   = $this->body($request);
        $visit_id = (int) $request['id'];

        return $this->run(fn (): array => $this->pricing->set_visit_consultation_fee($this->user_id(), $visit_id, $params['amount'] ?? null));
    }

    private function add_charge(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->write_limit($request);
        if ($limited instanceof WP_Error) {
            return $limited;
        }
        $params   = $this->body($request);
        $visit_id = (int) $request['id'];

        return $this->run(fn (): array => $this->pricing->add_charge($this->user_id(), $visit_id, $params), 201);
    }

    private function update_charge(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->write_limit($request);
        if ($limited instanceof WP_Error) {
            return $limited;
        }
        $params    = $this->body($request);
        $visit_id  = (int) $request['id'];
        $charge_id = (int) $request['charge_id'];

        return $this->run(fn (): array => $this->pricing->update_charge($this->user_id(), $visit_id, $charge_id, $params));
    }

    private function remove_charge(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->write_limit($request);
        if ($limited instanceof WP_Error) {
            return $limited;
        }
        $visit_id  = (int) $request['id'];
        $charge_id = (int) $request['charge_id'];

        return $this->run(fn (): array => $this->pricing->remove_charge($this->user_id(), $visit_id, $charge_id));
    }

    // ================= Helpers =================

    /**
     * @param list<string> $caps
     */
    private function permission(WP_REST_Request $request, array $caps): bool|WP_Error
    {
        $nonce = $this->requireNonce($request);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        if ($this->user_id() <= 0) {
            return $this->error('CLINIC_UNAUTHORIZED', 401, 'وارد نشده اید');
        }
        foreach ($caps as $cap) {
            $decision = $this->requireClinicPermission($cap);
            if ($decision instanceof WP_Error) {
                return $decision;
            }
        }

        return true;
    }

    private function write_limit(WP_REST_Request $request): bool|WP_Error
    {
        $result = $this->rateLimit($request, 'pricing_write_' . $this->user_id(), self::WRITE_PER_MINUTE, MINUTE_IN_SECONDS);

        return $result instanceof WP_Error ? $result : true;
    }

    private function user_id(): int
    {
        return (int) get_current_user_id();
    }

    /**
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();
        if (is_array($params)) {
            return $params;
        }
        $params = $request->get_params();

        return is_array($params) ? $params : [];
    }

    /**
     * @param callable(): array<string, mixed> $fn
     */
    private function run(callable $fn, int $status = 200): WP_REST_Response|WP_Error
    {
        try {
            return $this->success($fn(), $status);
        } catch (FinanceException $exception) {
            return $this->error($exception->errorCode, $exception->httpStatus, $exception->getMessage(), $exception->data);
        } catch (\Throwable $exception) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- established controller convention; no request data is logged.
            error_log('[CPMS][VisitPricingController] unexpected: ' . get_class($exception));

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }
    }
}
