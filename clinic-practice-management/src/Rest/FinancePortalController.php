<?php
/**
 * Phase 12 — Finance boundary for the independent Staff Portal.
 *
 * Slice 1 — read-only awaiting-payment board (GET only).
 * Slice 2 — bounded first-issuance surface for CURRENT trusted Location
 *           consultation-completed Visits. The mutation delegates to the
 *           existing FinanceService::issueInvoice() contract; existing
 *           Clinic-scoped permissions authorize every call and no new role,
 *           capability, state or schema is introduced.
 * Slice 3 — bounded manual payment capture for an EXISTING invoice whose
 *           persisted Visit belongs to the CURRENT trusted operational
 *           Location. The mutation delegates to the existing
 *           FinanceService::recordPayment() contract; the invoice is a
 *           selector, never authority, and no second payment/totals/state
 *           engine, migration, capability or device/POS integration is added.
 */

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Repository\InvoiceRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Read-only finance board + bounded first issuance + bounded payment capture. */
final class FinancePortalController extends RestBase {

	private const READ_CAPS = array(
		RolesAndCapabilities::FINANCE_READ,
		RolesAndCapabilities::INVOICE_READ,
		RolesAndCapabilities::QUEUE_READ,
	);

	/** Phase 12 Slice 2 — the existing capability that authorizes first issuance. */
	private const WRITE_CAP = RolesAndCapabilities::INVOICE_CREATE;

	/** Phase 12 Slice 3 — the existing capability that authorizes a capture. */
	private const PAYMENT_CAP = RolesAndCapabilities::PAYMENT_CREATE;

	/**
	 * Phase 12 Slice 3 — methods this portal slice exposes. `online` stays a
	 * backend method on the existing D13 contract; it is deliberately NOT
	 * exposed here, and `card_pos` means only “recorded manually” — no device
	 * communication of any kind is implemented or implied.
	 */
	private const PAYMENT_METHODS = array( 'cash', 'card_pos', 'other' );

	private const RESULT_LIMIT = 100;

	public function __construct(
		MembershipRepository $memberships,
		VisitRepository $visits,
		InvoiceRepository $invoices
	) {
		$this->memberships = $memberships;
		$this->visits      = $visits;
		$this->invoices    = $invoices;
	}

	private MembershipRepository $memberships;
	private VisitRepository $visits;
	private InvoiceRepository $invoices;

	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/staff/portal/finance/context',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $request ) => $this->context( $request ),
					'permission_callback' => fn( WP_REST_Request $request ) => $this->permission( $request ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/staff/portal/finance/awaiting-payment',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $request ) => $this->board( $request ),
					'permission_callback' => fn( WP_REST_Request $request ) => $this->permission( $request ),
				),
			)
		);

		// Phase 12 Slice 2 — bounded read of Visits eligible for FIRST issuance.
		register_rest_route(
			self::NS,
			'/staff/portal/finance/invoice-eligible',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $request ) => $this->eligible( $request ),
					'permission_callback' => fn( WP_REST_Request $request ) => $this->permission( $request ),
				),
			)
		);

		// Phase 12 Slice 2 — first issuance only; delegates to the existing service.
		register_rest_route(
			self::NS,
			'/staff/portal/finance/visits/(?P<id>\d+)/invoice',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $request ) => $this->issue( $request ),
					'permission_callback' => fn( WP_REST_Request $request ) => $this->permission( $request, array( self::WRITE_CAP ) ),
				),
			)
		);

		// Phase 12 Slice 3 — manual payment capture against an EXISTING invoice;
		// no request schema is declared so that amount/method validation stays
		// exactly the delegated service contract (422 CLINIC_VALIDATION_FAILED /
		// CLINIC_OVERPAYMENT), never a second validation engine.
		register_rest_route(
			self::NS,
			'/staff/portal/finance/invoices/(?P<id>\d+)/payments',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $request ) => $this->payment( $request ),
					'permission_callback' => fn( WP_REST_Request $request ) => $this->permission( $request, array( self::PAYMENT_CAP ) ),
				),
			)
		);
	}

	/** Context returns only eligible Location choices; no patient or finance data. */
	private function context( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		$scope = $this->trusted_scope();
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}

		$clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
		$user_id   = (int) get_current_user_id();
		$locations = $this->eligible_locations( $clinic_id, $user_id );
		if ( $locations instanceof WP_Error ) {
			return $locations;
		}

		$location_id = null === $scope->locationId ? null : (int) $scope->locationId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
		if ( null !== $location_id && ! isset( $locations[ $location_id ] ) ) {
			return $this->error( 'CLINIC_SCOPE_UNAVAILABLE', 403, 'موقعیت عملیاتی معتبر نیست.', array( 'reason' => 'location' ) );
		}
		if ( null === $location_id && 1 === count( $locations ) ) {
			$location_id = (int) array_key_first( $locations );
		}

		return $this->success(
			array(
				'clinic_id'          => $clinic_id,
				'locations'          => array_values(
					array_map(
						static fn( array $location ): array => array(
							'id'   => (int) $location['id'],
							'name' => (string) $location['name'],
						),
						$locations
					)
				),
				'location_id'        => $location_id,
				'location_name'      => null === $location_id ? null : (string) $locations[ $location_id ]['name'],
				'selection_required' => count( $locations ) > 1 && null === $location_id,
				// Phase 12 Slice 2 — UI hint only; the mutation route re-checks.
				'can_issue_invoice'  => $this->can_issue_invoice( $clinic_id, $user_id ),
				// Phase 12 Slice 3 — UI hint only; the capture route re-checks.
				'can_capture_payment' => $this->can_capture_payment( $clinic_id, $user_id ),
			)
		);
	}

	/** Current Location's Location-local awaiting-payment projection. */
	private function board( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		$scope = $this->trusted_scope();
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}

		$clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
		$locations = $this->eligible_locations( $clinic_id, (int) get_current_user_id() );
		if ( $locations instanceof WP_Error ) {
			return $locations;
		}
		if ( array() === $locations ) {
			return $this->success(
				array(
					'date'          => null,
					'jalali_date'   => null,
					'location_id'   => null,
					'location_name' => null,
					'visits'        => array(),
					'has_more'      => false,
				)
			);
		}

		$current = $this->current_location( $scope, $locations );
		if ( $current instanceof WP_Error ) {
			return $current;
		}

		$location_id = (int) $current['location_id'];
		$date        = (string) $current['date'];

		$rows = $this->visits->awaiting_payment_finance_board( $clinic_id, $location_id, $date, self::RESULT_LIMIT + 1 );

		$has_more = count( $rows ) > self::RESULT_LIMIT;
		if ( $has_more ) {
			$rows = array_slice( $rows, 0, self::RESULT_LIMIT );
		}

		$visits = array();
		foreach ( $rows as $row ) {
			$invoice_exists = isset( $row['invoice_status'] ) && '' !== (string) $row['invoice_status'];
			$visits[]       = array(
				// Phase 12 Slice 3 — stable selector ids for the capture action;
				// selectors only, never authority.
				'visit_id'         => (int) $row['visit_id'],
				'patient_name'     => trim( (string) $row['patient_first_name'] . ' ' . (string) $row['patient_last_name'] ),
				'clinician_name'   => (string) $row['clinician_name'],
				'operational_date' => (string) $row['visit_date'],
				'jalali_date'      => Jalali::formatYmd( (string) $row['visit_date'] ),
				'operational_time' => $this->operational_time( $row, $current['timezone'] ),
				'visit_status'     => 'awaiting_payment',
				'invoice_id'       => ( $invoice_exists && null !== ( $row['invoice_id'] ?? null ) ) ? (int) $row['invoice_id'] : null,
				'invoice'          => $invoice_exists ? array(
					'status'    => (string) $row['invoice_status'],
					'total'     => (string) $row['invoice_total'],
					'paid'      => (string) $row['invoice_paid_amount'],
					'remaining' => (string) $row['invoice_balance'],
					'currency'  => (string) $row['invoice_currency'],
				) : null,
			);
		}

		return $this->success(
			array(
				'date'          => $date,
				'jalali_date'   => Jalali::formatYmd( $date ),
				'location_id'   => $location_id,
				'location_name' => (string) $current['name'],
				'visits'        => $visits,
				'has_more'      => $has_more,
			)
		);
	}

	/**
	 * Bounded Slice 2 read: CURRENT trusted Location, Location-local operational
	 * day, exact status consultation_completed, deterministic order, at most 100
	 * returned rows. Projection is minimal and carries no financial or clinical
	 * field; the visit id is a selector for the first-issuance route only.
	 */
	private function eligible( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		$scope = $this->trusted_scope();
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}

		$clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
		$locations = $this->eligible_locations( $clinic_id, (int) get_current_user_id() );
		if ( $locations instanceof WP_Error ) {
			return $locations;
		}
		if ( array() === $locations ) {
			return $this->success(
				array(
					'date'          => null,
					'jalali_date'   => null,
					'location_id'   => null,
					'location_name' => null,
					'visits'        => array(),
					'has_more'      => false,
				)
			);
		}

		$current = $this->current_location( $scope, $locations );
		if ( $current instanceof WP_Error ) {
			return $current;
		}

		$location_id = (int) $current['location_id'];
		$date        = (string) $current['date'];

		$rows     = $this->visits->invoice_eligible_consultation_completed( $clinic_id, $location_id, $date, self::RESULT_LIMIT + 1 );
		$has_more = count( $rows ) > self::RESULT_LIMIT;
		if ( $has_more ) {
			$rows = array_slice( $rows, 0, self::RESULT_LIMIT );
		}

		$visits = array();
		foreach ( $rows as $row ) {
			$visits[] = array(
				'visit_id'         => (int) $row['visit_id'],
				'patient_name'     => trim( (string) $row['patient_first_name'] . ' ' . (string) $row['patient_last_name'] ),
				'clinician_name'   => (string) $row['clinician_name'],
				'operational_date' => (string) $row['visit_date'],
				'jalali_date'      => Jalali::formatYmd( (string) $row['visit_date'] ),
				'operational_time' => $this->operational_time( $row, $current['timezone'] ),
				'visit_status'     => 'consultation_completed',
			);
		}

		return $this->success(
			array(
				'date'          => $date,
				'jalali_date'   => Jalali::formatYmd( $date ),
				'location_id'   => $location_id,
				'location_name' => (string) $current['name'],
				'visits'        => $visits,
				'has_more'      => $has_more,
			)
		);
	}

	/**
	 * FIRST issuance only. Raw visit/location/clinic ids are selectors, never
	 * authority: before delegating, the Visit must belong to the trusted Clinic
	 * AND to the CURRENT trusted operational Location AND be exactly
	 * consultation_completed. The mutation itself is the existing
	 * FinanceService::issueInvoice() — no second invoice implementation, and
	 * only {description, quantity, unit_price} per item is forwarded.
	 */
	private function issue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scope = $this->trusted_scope();
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}

		$clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
		$locations = $this->eligible_locations( $clinic_id, (int) get_current_user_id() );
		if ( $locations instanceof WP_Error ) {
			return $locations;
		}
		if ( array() === $locations ) {
			return $this->error( 'CLINIC_SCOPE_UNAVAILABLE', 403, 'موقعیت عملیاتی معتبر نیست.', array( 'reason' => 'location' ) );
		}

		$current = $this->current_location( $scope, $locations );
		if ( $current instanceof WP_Error ) {
			return $current;
		}
		$location_id = (int) $current['location_id'];

		$visit_id = (int) $request['id'];
		$visit    = $this->visits->find( $visit_id );
		if (
			null === $visit
			|| $clinic_id !== (int) $visit['clinic_id']
			|| $location_id !== (int) ( $visit['location_id'] ?? 0 )
		) {
			// Cross-Clinic and cross-Location selectors are indistinguishable
			// from a missing Visit (404 parity — no existence disclosure).
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'ویزیت یافت نشد' );
		}
		if ( 'consultation_completed' !== (string) $visit['status'] ) {
			// awaiting_payment (including the voided-invoice recovery path) is
			// deliberately NOT exposed by this slice; the existing back-office
			// route keeps that behaviour unchanged.
			return $this->error(
				'CLINIC_INVALID_TRANSITION',
				409,
				'فاکتور فقط برای ویزیت پایان‌یافتهٔ بدون فاکتور قابل صدور است',
				array( 'visit_status' => (string) $visit['status'] )
			);
		}

		$params = $this->body( $request );
		$items  = $params['items'] ?? null;
		if ( ! is_array( $items ) || array() === $items ) {
			return $this->error( 'CLINIC_VALIDATION_FAILED', 422, 'حداقل یک قلم فاکتور الزامی است' );
		}
		$payload = array(
			'visit_id' => $visit_id,
			'items'    => array(),
		);
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				return $this->error( 'CLINIC_VALIDATION_FAILED', 422, 'قلم فاکتور نامعتبر است' );
			}
			$payload['items'][] = array(
				'description' => $item['description'] ?? '',
				'quantity'    => $item['quantity'] ?? 1,
				'unit_price'  => $item['unit_price'] ?? null,
			);
		}

		try {
			return $this->success( App::financeService()->issueInvoice( (int) get_current_user_id(), $payload ), 201 );
		} catch ( FinanceException $exception ) {
			return $this->error( $exception->errorCode, $exception->httpStatus, $exception->getMessage(), $exception->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established FinanceException contract.
		} catch ( VisitException $exception ) {
			return $this->error( $exception->errorCode, $exception->httpStatus, $exception->getMessage(), $exception->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract.
		} catch ( Throwable $exception ) {
			error_log( '[CPMS][FinancePortalController] unexpected: ' . get_class( $exception ) . ': ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- established controller convention
			return $this->error( 'CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید' );
		}
	}

	/**
	 * Phase 12 Slice 3 — ONE manual payment against an EXISTING invoice (a
	 * partial amount or the exact remaining balance) whose persisted Visit
	 * belongs to the CURRENT trusted operational Location of the trusted
	 * Clinic. Raw invoice/visit ids are selectors, never authority: the
	 * PERSISTED invoice and its PERSISTED Visit are loaded server-side and
	 * must belong to the trusted Clinic AND to the CURRENT trusted Location
	 * before anything is delegated; foreign Clinic and same-Clinic foreign
	 * Location ids are indistinguishable from an unknown invoice (404 parity).
	 * The mutation itself is the existing FinanceService::recordPayment() —
	 * no second payment/totals/state engine, no new state, and no device/POS
	 * integration (`card_pos` is manual recording only). Client-supplied
	 * amounts/totals/balances/statuses/ids are never forwarded as authority.
	 */
	private function payment( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$key = $this->idempotencyKey( $request );
		if ( null === $key ) {
			// Same established contract as the existing D13 route: a missing or
			// malformed Idempotency-Key never reaches the service.
			return $this->error( 'CLINIC_VALIDATION_FAILED', 400, 'هدر Idempotency-Key (UUID) برای ثبت پرداخت الزامی است' );
		}

		$scope = $this->trusted_scope();
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}

		$clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
		$locations = $this->eligible_locations( $clinic_id, (int) get_current_user_id() );
		if ( $locations instanceof WP_Error ) {
			return $locations;
		}
		if ( array() === $locations ) {
			return $this->error( 'CLINIC_SCOPE_UNAVAILABLE', 403, 'موقعیت عملیاتی معتبر نیست.', array( 'reason' => 'location' ) );
		}
		$current = $this->current_location( $scope, $locations );
		if ( $current instanceof WP_Error ) {
			return $current;
		}
		$location_id = (int) $current['location_id'];

		$invoice_id = (int) $request['id'];
		$invoice    = $this->invoices->find( $invoice_id );
		if ( null === $invoice || $clinic_id !== (int) $invoice['clinic_id'] ) {
			// Cross-Clinic and unknown selectors never disclose existence.
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'فاکتور یافت نشد' );
		}

		$visit_id = (int) ( $invoice['visit_id'] ?? 0 );
		$visit    = $visit_id > 0 ? $this->visits->find( $visit_id ) : null;
		if (
			null === $visit
			|| $clinic_id !== (int) $visit['clinic_id']
			|| $location_id !== (int) ( $visit['location_id'] ?? 0 )
		) {
			// A same-Clinic invoice whose Visit lives at another Location is
			// not payable from here and is not disclosed either.
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'فاکتور یافت نشد' );
		}
		$invoice_location = $invoice['location_id'] ?? null;
		if ( null !== $invoice_location && (int) $invoice_location !== $location_id ) {
			// An invoice explicitly stamped with another Location is never
			// treated as this Location's invoice.
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'فاکتور یافت نشد' );
		}

		$params = $this->body( $request );
		$method = isset( $params['method'] ) && is_string( $params['method'] ) ? $params['method'] : '';
		if ( ! in_array( $method, self::PAYMENT_METHODS, true ) ) {
			// `online` remains a backend method on the existing shared route but
			// is deliberately not exposed by this Staff Portal slice.
			return $this->error( 'CLINIC_VALIDATION_FAILED', 422, 'روش پرداخت نامعتبر است (cash/card_pos/other)' );
		}

		// Only the three delegated fields are forwarded; the amount contract
		// (positive integer Rial, ≤ server balance, 422 otherwise) stays owned
		// by the existing service. The reference is optional and its existing
		// bounds/validation are unchanged.
		$payload = array(
			'amount' => $params['amount'] ?? null,
			'method' => $method,
		);
		if ( isset( $params['transaction_ref'] ) && ( is_string( $params['transaction_ref'] ) || is_numeric( $params['transaction_ref'] ) ) ) {
			$payload['transaction_ref'] = $params['transaction_ref'];
		}

		try {
			$result = App::financeService()->recordPayment( (int) get_current_user_id(), $invoice_id, $payload, $key );
		} catch ( FinanceException $exception ) {
			return $this->error( $exception->errorCode, $exception->httpStatus, $exception->getMessage(), $exception->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established FinanceException contract.
		} catch ( Throwable $exception ) {
			error_log( '[CPMS][FinancePortalController] unexpected: ' . get_class( $exception ) . ': ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- established controller convention
			return $this->error( 'CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید' );
		}

		// First capture 201; an idempotent replay of the same key keeps the
		// existing 200 + CLINIC_IDEMPOTENCY_REPLAY contract and never creates a
		// second payment.
		return $this->success( $result, empty( $result['idempotent_replay'] ) ? 201 : 200 );
	}

	/**
	 * CURRENT trusted operational Location, its timezone and the Location-local
	 * operational date — one policy implementation shared by the Slice 1 board
	 * and both Slice 2 routes. Raw ids are selectors, never authority: an
	 * unavailable or unassigned selection fails closed, and N>1 never
	 * auto-resolves.
	 *
	 * @param array<int, array{id:int,name:string,timezone:string}> $locations
	 * @return array{location_id:int,timezone:DateTimeZone,date:string,name:string}|WP_Error
	 */
	private function current_location( ClinicScope $scope, array $locations ): array|WP_Error {
		$location_id = null === $scope->locationId ? null : (int) $scope->locationId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
		if ( null === $location_id && 1 === count( $locations ) ) {
			$location_id = (int) array_key_first( $locations );
		}
		if ( null === $location_id ) {
			return $this->error(
				'CLINIC_SCOPE_REQUIRED',
				400,
				'انتخاب موقعیت عملیاتی الزامی است.',
				array(
					'field'  => 'location_id',
					'reason' => 'location_required',
				)
			);
		}
		if ( ! isset( $locations[ $location_id ] ) ) {
			return $this->error( 'CLINIC_SCOPE_UNAVAILABLE', 403, 'موقعیت عملیاتی معتبر نیست.', array( 'reason' => 'location' ) );
		}

		try {
			$timezone = new DateTimeZone( (string) $locations[ $location_id ]['timezone'] );
		} catch ( Throwable ) {
			return $this->error( 'CLINIC_SCOPE_UNAVAILABLE', 403, 'منطقهٔ زمانی موقعیت عملیاتی معتبر نیست.', array( 'reason' => 'timezone' ) );
		}

		return array(
			'location_id' => $location_id,
			'timezone'    => $timezone,
			'date'        => ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone )->format( 'Y-m-d' ),
			'name'        => (string) $locations[ $location_id ]['name'],
		);
	}

	/**
	 * Location-local presentation time: the scheduled slot time when the Visit
	 * has an appointment, otherwise the check-in instant in Location timezone.
	 * Jalali/time formatting is presentation only — storage stays untouched.
	 *
	 * @param array<string, mixed> $row
	 */
	private function operational_time( array $row, DateTimeZone $timezone ): string {
		$appointment_time = trim( (string) ( $row['appointment_time'] ?? '' ) );
		if ( '' !== $appointment_time ) {
			return substr( $appointment_time, 0, 5 );
		}
		$checked_in_utc = new DateTimeImmutable( (string) $row['check_in_at'], new DateTimeZone( 'UTC' ) );

		return $checked_in_utc->setTimezone( $timezone )->format( 'H:i' );
	}

	/** Existing cpms_invoice_create authority for this Clinic — UI hint only. */
	private function can_issue_invoice( int $clinic_id, int $user_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( false === $user || ! $user->exists() || ! $user->has_cap( self::WRITE_CAP ) ) {
			return false;
		}

		return App::authorization_service()->can( $user_id, $clinic_id, self::WRITE_CAP );
	}

	/**
	 * Phase 12 Slice 3 — existing cpms_payment_create authority for this Clinic,
	 * exposed as a UI hint only (the capture route re-checks). No role gains
	 * access: the Slice 1/2 read+module contract is unchanged.
	 */
	private function can_capture_payment( int $clinic_id, int $user_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( false === $user || ! $user->exists() || ! $user->has_cap( self::PAYMENT_CAP ) ) {
			return false;
		}

		return App::authorization_service()->can( $user_id, $clinic_id, self::PAYMENT_CAP );
	}

	/**
	 * Existing nonce + WP capability + Clinic-scoped authority; no audit writes.
	 *
	 * @param list<string> $caps Existing capabilities required for this route.
	 */
	private function permission( WP_REST_Request $request, array $caps = self::READ_CAPS ): bool|WP_Error {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return $this->error( 'CLINIC_INVALID_NONCE', 403, 'Nonce نامعتبر است.' );
		}

		$user_id = (int) get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( false === $user || ! $user->exists() ) {
			return $this->error( 'CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید.' );
		}
		foreach ( $caps as $capability ) {
			if ( ! $user->has_cap( $capability ) ) {
				return $this->error( 'CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید.' );
			}
		}

		$scope = $this->trusted_scope();
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}
		$clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
		foreach ( $caps as $capability ) {
			if ( ! App::authorization_service()->can( $user_id, $clinic_id, $capability ) ) {
				return $this->error( 'CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید.' );
			}
		}
		return true;
	}

	/** Scope has been established by RestClinicContext before route authorization. */
	private function trusted_scope(): ClinicScope|WP_Error {
		try {
			return App::scope();
		} catch ( ScopeRequiredException $exception ) {
			return $this->error( $exception->errorCode, $exception->httpStatus(), 'محدودهٔ معتبر در دسترس نیست.', $exception->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ScopeRequiredException contract.
		} catch ( Throwable ) {
			return $this->error( 'CLINIC_SCOPE_REQUIRED', 400, 'محدودهٔ کلینیک لازم است.' );
		}
	}

	/** @return array<int, array{id:int,name:string,timezone:string}>|WP_Error */
	private function eligible_locations( int $clinic_id, int $user_id ): array|WP_Error {
		$membership = $this->memberships->find_active( $clinic_id, $user_id );
		if ( null === $membership ) {
			return array();
		}

		$db = App::db();
		if ( 'location' === (string) ( $membership['scope_mode'] ?? 'clinic' ) ) {
			$rows = $db->fetchAll(
				'SELECT l.id, l.name, l.timezone FROM ' . $db->table( 'cpms_locations' ) . ' l' .
				' INNER JOIN ' . $db->table( 'cpms_membership_locations' ) . ' ml ON ml.location_id = l.id AND ml.membership_id = %d' .
				' WHERE l.clinic_id = %d AND l.is_active = 1 ORDER BY l.id ASC LIMIT 101',
				array( (int) $membership['id'], $clinic_id )
			);
		} else {
			$rows = $db->fetchAll(
				'SELECT id, name, timezone FROM ' . $db->table( 'cpms_locations' ) . ' WHERE clinic_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 101',
				array( $clinic_id )
			);
		}
		if ( 100 < count( is_array( $rows ) ? $rows : array() ) ) {
			return $this->error( 'CLINIC_SCOPE_UNAVAILABLE', 403, 'موقعیت‌های واجد شرایط بیش از حد مجاز هستند.', array( 'reason' => 'locations' ) );
		}

		$eligible = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$eligible[ (int) $row['id'] ] = array(
				'id'       => (int) $row['id'],
				'name'     => (string) $row['name'],
				'timezone' => (string) $row['timezone'],
			);
		}
		return $eligible;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function body( WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( is_array( $params ) ) {
			return $params;
		}
		$params = $request->get_params();

		return is_array( $params ) ? $params : array();
	}
}
