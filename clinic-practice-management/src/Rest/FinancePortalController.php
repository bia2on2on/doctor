<?php
/**
 * Phase 12 Slice 1 — read-only Finance board boundary for the independent
 * Staff Portal. All authority comes from the existing Clinic-scoped finance,
 * invoice and queue read permissions; selectors never establish authority.
 */

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Read-only Staff Portal finance board. */
final class FinancePortalController extends RestBase
{
    private const READ_CAPS = [
        RolesAndCapabilities::FINANCE_READ,
        RolesAndCapabilities::INVOICE_READ,
        RolesAndCapabilities::QUEUE_READ,
    ];

    private const RESULT_LIMIT = 100;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly VisitRepository $visits
    ) {
    }

    public function register_routes(): void
    {
        register_rest_route(self::NS, '/staff/portal/finance/context', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->context($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->permission($request),
            ],
        ]);

        register_rest_route(self::NS, '/staff/portal/finance/awaiting-payment', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->board($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->permission($request),
            ],
        ]);
    }

    /**
     * Context contains only the authorized actor's eligible Location choices;
     * it contains no patient or finance data and supports explicit N>1 choice.
     */
    private function context(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);
        $scope = $this->trustedScope();
        if ($scope instanceof WP_Error) {
            return $scope;
        }

        $locations = $this->eligibleLocations((int) $scope->clinicId, (int) get_current_user_id());
        if ($locations instanceof WP_Error) {
            return $locations;
        }
        $selectedId = null === $scope->locationId ? null : (int) $scope->locationId;
        if (null !== $selectedId && !isset($locations[$selectedId])) {
            return $this->error('CLINIC_SCOPE_UNAVAILABLE', 403, 'موقعیت عملیاتی معتبر نیست.', ['reason' => 'location']);
        }
        if (null === $selectedId && 1 === count($locations)) {
            $selectedId = (int) array_key_first($locations);
        }

        return $this->success([
            'clinic_id' => (int) $scope->clinicId,
            'locations' => array_values(array_map(static fn (array $location): array => [
                'id' => (int) $location['id'],
                'name' => (string) $location['name'],
            ], $locations)),
            'location_id' => $selectedId,
            'location_name' => null === $selectedId ? null : (string) $locations[$selectedId]['name'],
            'selection_required' => count($locations) > 1 && null === $selectedId,
        ]);
    }

    /** Current Location's Location-local awaiting-payment projection. */
    private function board(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);
        $scope = $this->trustedScope();
        if ($scope instanceof WP_Error) {
            return $scope;
        }

        $clinicId = (int) $scope->clinicId;
        $locations = $this->eligibleLocations($clinicId, (int) get_current_user_id());
        if ($locations instanceof WP_Error) {
            return $locations;
        }
        if ($locations === []) {
            return $this->success(['date' => null, 'jalali_date' => null, 'location_id' => null, 'location_name' => null, 'visits' => [], 'has_more' => false]);
        }

        $selectedId = null === $scope->locationId ? null : (int) $scope->locationId;
        if (null === $selectedId && 1 === count($locations)) {
            $selectedId = (int) array_key_first($locations);
        }
        if (null === $selectedId) {
            return $this->error('CLINIC_SCOPE_REQUIRED', 400, 'انتخاب موقعیت عملیاتی الزامی است.', [
                'field' => 'location_id',
                'reason' => 'location_required',
            ]);
        }
        if (!isset($locations[$selectedId])) {
            return $this->error('CLINIC_SCOPE_UNAVAILABLE', 403, 'موقعیت عملیاتی معتبر نیست.', ['reason' => 'location']);
        }

        try {
            $timezone = new DateTimeZone((string) $locations[$selectedId]['timezone']);
        } catch (Throwable) {
            return $this->error('CLINIC_SCOPE_UNAVAILABLE', 403, 'منطقهٔ زمانی موقعیت عملیاتی معتبر نیست.', ['reason' => 'timezone']);
        }
        $date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
        $rows = $this->visits->awaitingPaymentFinanceBoard($clinicId, $selectedId, $date, self::RESULT_LIMIT + 1);
        $hasMore = count($rows) > self::RESULT_LIMIT;
        if ($hasMore) {
            $rows = array_slice($rows, 0, self::RESULT_LIMIT);
        }

        $visits = [];
        foreach ($rows as $row) {
            $appointmentTime = trim((string) ($row['appointment_time'] ?? ''));
            if ($appointmentTime !== '') {
                $time = substr($appointmentTime, 0, 5);
            } else {
                $checkedInUtc = new DateTimeImmutable((string) $row['check_in_at'], new DateTimeZone('UTC'));
                $time = $checkedInUtc->setTimezone($timezone)->format('H:i');
            }
            $invoiceExists = isset($row['invoice_status']) && (string) $row['invoice_status'] !== '';
            $visits[] = [
                'patient_name' => trim((string) $row['patient_first_name'] . ' ' . (string) $row['patient_last_name']),
                'clinician_name' => (string) $row['clinician_name'],
                'operational_date' => (string) $row['visit_date'],
                'jalali_date' => Jalali::formatYmd((string) $row['visit_date']),
                'operational_time' => $time,
                'visit_status' => 'awaiting_payment',
                'invoice' => $invoiceExists ? [
                    'status' => (string) $row['invoice_status'],
                    'total' => (string) $row['invoice_total'],
                    'paid' => (string) $row['invoice_paid_amount'],
                    'remaining' => (string) $row['invoice_balance'],
                    'currency' => (string) $row['invoice_currency'],
                ] : null,
            ];
        }

        return $this->success([
            'date' => $date,
            'jalali_date' => Jalali::formatYmd($date),
            'location_id' => $selectedId,
            'location_name' => (string) $locations[$selectedId]['name'],
            'visits' => $visits,
            'has_more' => $hasMore,
        ]);
    }

    /** Existing nonce + WP capability + Clinic-scoped authority, with no audit write on denial. */
    private function permission(WP_REST_Request $request): bool|WP_Error
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return $this->error('CLINIC_INVALID_NONCE', 403, 'Nonce نامعتبر است.');
        }
        $userId = (int) get_current_user_id();
        $user = $userId > 0 ? get_userdata($userId) : false;
        if ($user === false || !$user->exists()) {
            return $this->error('CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید.');
        }
        foreach (self::READ_CAPS as $capability) {
            if (!$user->has_cap($capability)) {
                return $this->error('CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید.');
            }
        }
        $scope = $this->trustedScope();
        if ($scope instanceof WP_Error) {
            return $scope;
        }
        foreach (self::READ_CAPS as $capability) {
            if (!App::authorization_service()->can($userId, (int) $scope->clinicId, $capability)) {
                return $this->error('CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید.');
            }
        }
        return true;
    }

    /** Trusted scope has already been bound by RestClinicContext. */
    private function trustedScope(): ClinicScope|WP_Error
    {
        try {
            return App::scope();
        } catch (ScopeRequiredException $exception) {
            return $this->error($exception->errorCode, $exception->httpStatus(), 'محدودهٔ معتبر در دسترس نیست.', $exception->data);
        } catch (Throwable) {
            return $this->error('CLINIC_SCOPE_REQUIRED', 400, 'محدودهٔ کلینیک لازم است.');
        }
    }

    /**
     * Active Locations eligible to this active member. Clinic membership does
     * not imply any of the finance permissions checked above.
     *
     * @return array<int, array{id:int,name:string,timezone:string}>|WP_Error
     */
    private function eligibleLocations(int $clinicId, int $userId): array|WP_Error
    {
        $membership = $this->memberships->find_active($clinicId, $userId);
        if ($membership === null) {
            return [];
        }
        $db = App::db();
        if ((string) ($membership['scope_mode'] ?? 'clinic') === 'location') {
            $rows = $db->fetchAll(
                'SELECT l.id, l.name, l.timezone FROM ' . $db->table('cpms_locations') . ' l' .
                ' INNER JOIN ' . $db->table('cpms_membership_locations') . ' ml ON ml.location_id = l.id AND ml.membership_id = %d' .
                ' WHERE l.clinic_id = %d AND l.is_active = 1 ORDER BY l.id ASC LIMIT 101',
                [(int) $membership['id'], $clinicId]
            );
        } else {
            $rows = $db->fetchAll(
                'SELECT id, name, timezone FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 101',
                [$clinicId]
            );
        }
        if (count(is_array($rows) ? $rows : []) > 100) {
            return $this->error('CLINIC_SCOPE_UNAVAILABLE', 403, 'موقعیت‌های واجد شرایط بیش از حد مجاز هستند.', ['reason' => 'locations']);
        }
        $eligible = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $eligible[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'timezone' => (string) $row['timezone'],
            ];
        }
        return $eligible;
    }
}
