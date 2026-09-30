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
 * Slice 4 — bounded paid/checkout-ready board for the CURRENT trusted
 *           operational Location (exact persisted status `paid`) + the NORMAL
 *           paid checkout action. The mutation delegates to the EXISTING
 *           VisitService::checkout(actor, visitId, null) contract — no second
 *           checkout engine, no waive reason on this surface, no new
 *           capability/state/migration.
 * Slice 5 — bounded READ-ONLY receipt of the NORMAL fully settled invoice
 *           behind a CURRENT trusted Location Visit. GET only: no mutation, no
 *           audit side effect, no server PDF and no second receipt engine — a
 *           Staff Portal projection/adapter over the EXISTING persisted
 *           finance rows, printed through the browser. Eligibility is derived
 *           from durable rows only (exactly one non-voided invoice in `paid`
 *           with a zero balance that reconciles with its clean captured
 *           payments, no voided/refunded payment, no adjustment row, items
 *           present and no durable waiver transition) and every other shape
 *           fails closed with 409 CLINIC_RECEIPT_NOT_ELIGIBLE + a bounded
 *           reason — accounting history is never hidden, no new receipt state
 *           is invented, and the existing D17 back-office receipt route and
 *           wp-admin print flow stay untouched.
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
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\PaymentRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Read-only finance board + bounded first issuance + bounded payment capture + bounded paid checkout. */
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

	/** Phase 12 Slice 4 — the existing capability that authorizes a paid checkout. */
	private const CHECKOUT_CAP = RolesAndCapabilities::QUEUE_CHECKOUT;

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
		InvoiceRepository $invoices,
		PaymentRepository $payments,
		PatientRepository $patients
	) {
		$this->memberships = $memberships;
		$this->visits      = $visits;
		$this->invoices    = $invoices;
		$this->payments    = $payments;
		$this->patients    = $patients;
	}

	private MembershipRepository $memberships;
	private VisitRepository $visits;
	private InvoiceRepository $invoices;
	private PaymentRepository $payments;
	private PatientRepository $patients;

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

		// Phase 12 Slice 4 — bounded read of the CURRENT Location paid
		// (checkout-ready) Visits: exact persisted status `paid`.
		register_rest_route(
			self::NS,
			'/staff/portal/finance/paid',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $request ) => $this->paid_board( $request ),
					'permission_callback' => fn( WP_REST_Request $request ) => $this->permission( $request ),
				),
			)
		);

		// Phase 12 Slice 4 — NORMAL paid checkout only; no request schema is
		// declared (the route reads no body field) and the delegation never
		// receives a waive reason — the existing D16 waiver path stays on the
		// shared back-office route only.
		register_rest_route(
			self::NS,
			'/staff/portal/finance/visits/(?P<id>\d+)/checkout',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $request ) => $this->checkout( $request ),
					'permission_callback' => fn( WP_REST_Request $request ) => $this->permission( $request, array( self::CHECKOUT_CAP ) ),
				),
			)
		);

		// Phase 12 Slice 5 — READ-ONLY receipt of the NORMAL fully settled
		// invoice behind a CURRENT trusted Location Visit. The existing
		// `cpms_invoice_read` authority authorizes the read (global + scoped,
		// exactly the existing D12b/D17 read class this Staff Portal receipt
		// projects); the Visit id in the path is a selector, never authority,
		// and the route has no mutation, no audit side effect and no PDF.
		register_rest_route(
			self::NS,
			'/staff/portal/finance/visits/(?P<id>\d+)/receipt',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $request ) => $this->receipt( $request ),
					'permission_callback' => fn( WP_REST_Request $request ) => $this->permission( $request, array( RolesAndCapabilities::INVOICE_READ ) ),
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
				'clinic_id'           => $clinic_id,
				'locations'           => array_values(
					array_map(
						static fn( array $location ): array => array(
							'id'   => (int) $location['id'],
							'name' => (string) $location['name'],
						),
						$locations
					)
				),
				'location_id'         => $location_id,
				'location_name'       => null === $location_id ? null : (string) $locations[ $location_id ]['name'],
				'selection_required'  => count( $locations ) > 1 && null === $location_id,
				// Phase 12 Slice 2 — UI hint only; the mutation route re-checks.
				'can_issue_invoice'   => $this->can_issue_invoice( $clinic_id, $user_id ),
				// Phase 12 Slice 3 — UI hint only; the capture route re-checks.
				'can_capture_payment' => $this->can_capture_payment( $clinic_id, $user_id ),
				// Phase 12 Slice 4 — UI hint only; the checkout route re-checks.
				'can_check_out'       => $this->can_check_out( $clinic_id, $user_id ),
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
	 * Phase 12 Slice 4 — bounded paid/checkout-ready read: CURRENT trusted
	 * Location, Location-local operational day, exact persisted status `paid`,
	 * deterministic order, at most 100 returned rows (one joined query).
	 * Projection is minimal: display names + operational day/time + status
	 * literal, plus the minimal settlement summary of the legitimately linked
	 * active invoice when it exists (`invoice: null` otherwise). No identifier,
	 * number or other finance internal leaves this read.
	 */
	private function paid_board( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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

		$rows     = $this->visits->paid_checkout_ready_board( $clinic_id, $location_id, $date, self::RESULT_LIMIT + 1 );
		$has_more = count( $rows ) > self::RESULT_LIMIT;
		if ( $has_more ) {
			$rows = array_slice( $rows, 0, self::RESULT_LIMIT );
		}

		$visits = array();
		foreach ( $rows as $row ) {
			$invoice_exists = isset( $row['invoice_status'] ) && '' !== (string) $row['invoice_status'];
			$visits[]       = array(
				'visit_id'         => (int) $row['visit_id'],
				'patient_name'     => trim( (string) $row['patient_first_name'] . ' ' . (string) $row['patient_last_name'] ),
				'clinician_name'   => (string) $row['clinician_name'],
				'operational_date' => (string) $row['visit_date'],
				'jalali_date'      => Jalali::formatYmd( (string) $row['visit_date'] ),
				'operational_time' => $this->operational_time( $row, $current['timezone'] ),
				'visit_status'     => 'paid',
				// Minimal settlement summary for checkout confirmation only —
				// never an identifier or a number.
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
	 * Phase 12 Slice 4 — ONE paid checkout (the NORMAL paid workflow only).
	 * The Visit id in the path is a selector, never authority: the PERSISTED
	 * Visit is loaded server-side and must belong to the trusted Clinic AND to
	 * the CURRENT trusted operational Location AND be exactly `paid`; a
	 * foreign Clinic, a same-Clinic foreign/unassigned/inactive Location or an
	 * unknown id shares the established non-enumerating 404 `CLINIC_NOT_FOUND`
	 * parity. No body field is read and no waive reason is ever forwarded —
	 * the `awaiting_payment → waive` path is NOT exposed on this surface. The
	 * mutation itself is the EXISTING VisitService::checkout(actor, visitId,
	 * null): the V14 unsettled-invoice guard, the state machine, the
	 * checked-out stamp, the append-only history/audit and the established
	 * side effects all stay exactly as the shared D16 route produces them.
	 */
	private function checkout( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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
		if ( 'paid' !== (string) $visit['status'] ) {
			// Only the persisted `paid` state is acceptable here; every other
			// state keeps the established invalid-transition semantics — this
			// surface never accepts a waive reason.
			return $this->error(
				'CLINIC_INVALID_TRANSITION',
				409,
				'خروج از کلینیک فقط برای ویزیت پرداخت‌شده در این موقعیت عملیاتی ممکن است',
				array( 'visit_status' => (string) $visit['status'] )
			);
		}

		try {
			$result = App::visitService()->checkout( (int) get_current_user_id(), $visit_id, null );
		} catch ( VisitException $exception ) {
			return $this->error( $exception->errorCode, $exception->httpStatus, $exception->getMessage(), $exception->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract.
		} catch ( Throwable $exception ) {
			error_log( '[CPMS][FinancePortalController] unexpected: ' . get_class( $exception ) . ': ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- established controller convention
			return $this->error( 'CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید' );
		}

		return $this->success( $result );
	}

	/**
	 * Phase 12 Slice 5 — bounded READ-ONLY receipt of the NORMAL fully settled
	 * invoice behind a CURRENT trusted Location Visit.
	 *
	 * The Visit id in the path is a selector, never authority: the persisted
	 * Visit is loaded server-side and must belong to the trusted Clinic AND to
	 * the CURRENT trusted operational Location; foreign Clinic, same-Clinic
	 * foreign/unassigned/inactive Location and unknown ids keep the established
	 * non-enumerating 404 `CLINIC_NOT_FOUND` parity, and the 0/1/N Location
	 * policy (including the invalid-timezone fail-closed branch) is exactly the
	 * Slice 1–4 policy.
	 *
	 * Eligibility is derived from DURABLE rows only — no new "clean receipt"
	 * state, no schema change, no migration:
	 *   1. exactly ONE non-voided invoice on the Visit and NO voided invoice
	 *      (an ambiguous or voided invoice set is correction/re-issue
	 *      evidence → `correction_evidence`);
	 *   2. that invoice is exactly `paid`, not voided, `total > 0`,
	 *      `paid_amount = total` and `balance = 0` (else `invoice_not_settled`);
	 *   3. every payment row is a clean capture — `captured`,
	 *      `refunded_amount = 0`, no void markers — and no
	 *      `cpms_payment_adjustments` row exists for the invoice (else
	 *      `correction_evidence`); at least one payment row is required and the
	 *      captured sum must equal `paid_amount` (else `settlement_integrity`);
	 *   4. at least one line item exists (else `items_missing`);
	 *   5. the append-only Visit history holds no `awaiting_payment →
	 *      checked_out` waiver transition (else `waive_evidence`).
	 * Anything else fails closed with 409 `CLINIC_RECEIPT_NOT_ELIGIBLE` and the
	 * bounded `reason`; nothing is written on any path and the correction
	 * evidence itself is never mutated or erased.
	 *
	 * Presentation: stored UTC instants are converted FIRST to the CURRENT
	 * trusted Location timezone (the persisted Location IANA zone) and only
	 * then formatted, including the repository `Jalali` utility — never a UTC
	 * date substring, the WP/PHP ambient timezone or a hardcoded zone.
	 */
	private function receipt( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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
		$timezone    = $current['timezone'];

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

		$invoices = $this->invoices->forVisit( $visit_id );
		$active   = array_values(
			array_filter(
				$invoices,
				static fn( array $invoice ): bool => 'voided' !== (string) $invoice['status']
			)
		);
		if ( array() === $active ) {
			// The waive/no-invoice path (and any Visit without a finance
			// document): there is nothing to print as a paid receipt.
			return $this->receipt_not_eligible( 'invoice_missing' );
		}
		if ( 1 !== count( $active ) || count( $invoices ) !== count( $active ) ) {
			// More than one non-voided invoice, or a voided invoice in the
			// Visit's durable history: correction/re-issue evidence.
			return $this->receipt_not_eligible( 'correction_evidence' );
		}

		$invoice    = $active[0];
		$invoice_id = (int) $invoice['id'];
		$payments   = $this->payments->forInvoice( $invoice_id );

		// The persisted patient is loaded through the existing repository and
		// proven to be the Visit's patient INSIDE the trusted Clinic before any
		// identity is rendered: the display name must never come from a row that
		// another Clinic owns.
		$patient = $this->patients->find( (int) ( $visit['patient_id'] ?? 0 ) );

		// Durable ownership/linkage guard: the persisted Visit is the
		// tenant/Location anchor, so the patient row, the selected invoice and
		// every payment row used below must be internally consistent with that
		// Visit before any value is read, evaluated or rendered. Inconsistent
		// durable rows are never repaired and never disclosed: the established
		// non-enumerating 404 parity applies (same shape as the
		// cross-Clinic/cross-Location Visit rejection above and as
		// `FinanceService` invoice ownership).
		if ( ! $this->receipt_rows_owned_by_visit( $visit, $invoice, $payments, $patient ) ) {
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'ویزیت یافت نشد' );
		}

		if (
			'paid' !== (string) $invoice['status']
			|| null !== ( $invoice['void_reason'] ?? null )
			|| null !== ( $invoice['voided_at'] ?? null )
			|| $this->cents( $invoice['total'] ?? 0 ) <= 0
			|| $this->cents( $invoice['paid_amount'] ?? 0 ) !== $this->cents( $invoice['total'] ?? 0 )
			|| 0 !== $this->cents( $invoice['balance'] ?? 0 )
		) {
			return $this->receipt_not_eligible( 'invoice_not_settled' );
		}

		$captured = array();
		foreach ( $payments as $payment ) {
			if (
				'captured' !== (string) $payment['status']
				|| 0 !== $this->cents( $payment['refunded_amount'] ?? 0 )
				|| null !== ( $payment['void_reason'] ?? null )
				|| null !== ( $payment['voided_at'] ?? null )
				|| null !== ( $payment['voided_by_wp_user_id'] ?? null )
			) {
				// Correction/refund evidence on the durable payment rows: fail
				// closed instead of printing a misleading clean receipt.
				return $this->receipt_not_eligible( 'correction_evidence' );
			}
			$captured[] = $payment;
		}
		if ( array() !== $this->invoices->adjustmentsFor( $invoice_id ) ) {
			return $this->receipt_not_eligible( 'correction_evidence' );
		}
		if ( array() === $captured ) {
			return $this->receipt_not_eligible( 'settlement_integrity' );
		}
		$captured_sum = 0;
		foreach ( $captured as $payment ) {
			$captured_sum += $this->cents( $payment['amount'] ?? 0 );
		}
		if ( $captured_sum !== $this->cents( $invoice['paid_amount'] ?? 0 ) ) {
			return $this->receipt_not_eligible( 'settlement_integrity' );
		}

		$items = $this->invoices->itemsFor( $invoice_id );
		if ( array() === $items ) {
			return $this->receipt_not_eligible( 'items_missing' );
		}

		foreach ( $this->visits->historyFor( $visit_id ) as $transition ) {
			if (
				'awaiting_payment' === (string) ( $transition['from_status'] ?? '' )
				&& 'checked_out' === (string) ( $transition['to_status'] ?? '' )
			) {
				// The durable waiver transition (checkout WITHOUT a
				// settlement): never print this as a paid receipt.
				return $this->receipt_not_eligible( 'waive_evidence' );
			}
		}

		$clinic = App::db()->fetchRow(
			'SELECT name, address, phone FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
			array( $clinic_id )
		);

		$invoice_date = $this->location_date( (string) $invoice['created_at'], $timezone );

		return $this->success(
			array(
				'receipt' => array(
					// Display identity only — the receipt never carries MRN,
					// mobile, national id, clinical data or internal ids.
					'clinic'              => array(
						'name'    => (string) ( $clinic['name'] ?? '' ),
						'address' => $clinic['address'] ?? null,
						'phone'   => $clinic['phone'] ?? null,
					),
					// The ownership guard above proved this row exists, is the Visit's
					// own patient and belongs to the trusted Clinic, so the identity is
					// always read from that row (the ternary's empty branch is
					// unreachable and never a fallback identity).
					'patient'             => array(
						'name' => null !== $patient
							? trim( (string) $patient['first_name'] . ' ' . (string) $patient['last_name'] )
							: '',
					),
					'invoice_number'      => (string) $invoice['invoice_number'],
					'invoice_date'        => $invoice_date,
					'jalali_invoice_date' => Jalali::formatYmd( $invoice_date ),
					'items'               => array_map(
						static fn( array $item ): array => array(
							'description' => (string) $item['description'],
							'quantity'    => (string) $item['quantity'],
							'unit_price'  => (string) $item['unit_price'],
							'amount'      => (string) $item['amount'],
						),
						$items
					),
					'totals'              => array(
						'subtotal'    => (string) $invoice['subtotal'],
						'discount'    => (string) $invoice['discount'],
						'tax'         => (string) $invoice['tax'],
						'total'       => (string) $invoice['total'],
						'paid_amount' => (string) $invoice['paid_amount'],
						'balance'     => (string) $invoice['balance'],
						'currency'    => (string) $invoice['currency'],
					),
					'payments'            => array_map(
						fn( array $payment ): array => array(
							'payment_number' => (string) $payment['payment_number'],
							'method'         => (string) $payment['method'],
							'amount'         => (string) $payment['amount'],
							'paid_at'        => $this->location_date( (string) $payment['paid_at'], $timezone ),
							'jalali_paid_at' => Jalali::formatYmd( $this->location_date( (string) $payment['paid_at'], $timezone ) ),
						),
						$captured
					),
				),
			)
		);
	}

	/** Bounded, explicit, non-enumerating eligibility failure. */
	private function receipt_not_eligible( string $reason ): WP_Error {
		return $this->error(
			'CLINIC_RECEIPT_NOT_ELIGIBLE',
			409,
			'رسید این فاکتور در دسترس نیست؛ مسیر تسویهٔ عادی و کامل آن قابل اثبات نیست.',
			array( 'reason' => $reason )
		);
	}

	/**
	 * Ownership/linkage guard for the receipt projection: every durable row the
	 * receipt uses — the patient identity row, the selected invoice and every
	 * payment row of it — must belong to the already-authorized persisted Visit.
	 *
	 * The patient row is required to be the Visit's own patient inside the
	 * trusted Clinic (`cpms_patients.clinic_id`), so a Visit/Patient
	 * cross-Clinic inconsistency can never render a foreign patient's name.
	 *
	 * `location_id`: the Visit architecture carries a mandatory, backfilled
	 * Location (migration 0013 — NOT NULL + deterministic backfill), while the
	 * finance columns `cpms_invoices.location_id` / `cpms_payments.location_id`
	 * stay NULL-able for legacy durable rows (migration 0015) and existing
	 * finance reads already tolerate that NULL in specific established paths
	 * (the Slice 4 paid board joins `location_id = %d OR location_id IS NULL`).
	 * This guard therefore accepts a NULL finance Location ONLY as that bounded
	 * legacy-compatibility case, after Clinic/Visit/patient ownership is proven;
	 * any non-NULL finance Location must equal the persisted Visit Location
	 * exactly. Legacy rows are never migrated or backfilled here.
	 *
	 * @param array<string, mixed>            $visit    persisted, already-authorized Visit anchor.
	 * @param array<string, mixed>            $invoice  single non-voided invoice selected for the Visit.
	 * @param list<array<string, mixed>>      $payments every durable payment row of that invoice.
	 * @param array<string, mixed>|null       $patient  persisted patient row of the Visit, or null when absent.
	 */
	private function receipt_rows_owned_by_visit( array $visit, array $invoice, array $payments, ?array $patient ): bool {
		$visit_id    = (int) ( $visit['id'] ?? 0 );
		$clinic_id   = (int) ( $visit['clinic_id'] ?? 0 );
		$patient_id  = (int) ( $visit['patient_id'] ?? 0 );
		$location_id = (int) ( $visit['location_id'] ?? 0 );

		// Patient ownership: the persisted patient must be exactly the Visit's
		// patient and must belong to the same (trusted) Clinic. A missing row or
		// another Clinic's row is an ownership failure, not a printable blank.
		if ( ! $this->receipt_patient_owned_by_visit( $patient, $clinic_id, $patient_id ) ) {
			return false;
		}

		if (
			(int) ( $invoice['visit_id'] ?? 0 ) !== $visit_id
			|| (int) ( $invoice['clinic_id'] ?? 0 ) !== $clinic_id
			|| (int) ( $invoice['patient_id'] ?? 0 ) !== $patient_id
			|| ! $this->receipt_location_matches( $invoice['location_id'] ?? null, $location_id )
		) {
			return false;
		}

		$invoice_id = (int) ( $invoice['id'] ?? 0 );
		foreach ( $payments as $payment ) {
			if (
				(int) ( $payment['invoice_id'] ?? 0 ) !== $invoice_id
				|| (int) ( $payment['clinic_id'] ?? 0 ) !== $clinic_id
				|| (int) ( $payment['patient_id'] ?? 0 ) !== $patient_id
				|| ! $this->receipt_location_matches( $payment['location_id'] ?? null, $location_id )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The persisted patient row must be exactly the Visit's patient AND belong
	 * to the Visit's Clinic (already authorized as the trusted Clinic); the row
	 * is read through the existing `PatientRepository`, never ad-hoc.
	 *
	 * @param array<string, mixed>|null $patient    persisted patient row loaded for the Visit.
	 * @param int                       $clinic_id  trusted Clinic of the authorized Visit.
	 * @param int                       $patient_id patient id persisted on the Visit (and on the invoice).
	 */
	private function receipt_patient_owned_by_visit( ?array $patient, int $clinic_id, int $patient_id ): bool {
		if ( null === $patient ) {
			return false;
		}

		return (int) ( $patient['id'] ?? 0 ) === $patient_id
			&& (int) ( $patient['clinic_id'] ?? 0 ) === $clinic_id;
	}

	/**
	 * NULL is the bounded legacy/backward-compatible finance Location (the
	 * persisted Visit remains the Location anchor); any non-NULL value must
	 * match the Visit Location exactly.
	 */
	private function receipt_location_matches( mixed $row_location_id, int $visit_location_id ): bool {
		if ( null === $row_location_id ) {
			return true;
		}

		return (int) $row_location_id === $visit_location_id;
	}

	/** Integer cents — exact money comparison, never float equality. */
	private function cents( mixed $value ): int {
		return (int) round( ( (float) $value ) * 100 );
	}

	/**
	 * Stored UTC instant → Location-local `Y-m-d` in the authoritative
	 * CURRENT Location timezone (presentation only; storage stays UTC).
	 */
	private function location_date( string $instant, DateTimeZone $timezone ): string {
		return ( new DateTimeImmutable( $instant, new DateTimeZone( 'UTC' ) ) )
			->setTimezone( $timezone )
			->format( 'Y-m-d' );
	}

	/**
	 * CURRENT trusted operational Location, its timezone and the Location-local
	 * operational date — one policy implementation shared by the Slice 1 board
	 * and both Slice 2 routes.
	 * Raw ids are selectors, never authority: an
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
	 * Phase 12 Slice 4 — existing cpms_queue_checkout authority for this
	 * Clinic, exposed as a UI hint only (the checkout route re-checks). No
	 * role gains access: the Slice 1/2/3 module contract is unchanged.
	 */
	private function can_check_out( int $clinic_id, int $user_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( false === $user || ! $user->exists() || ! $user->has_cap( self::CHECKOUT_CAP ) ) {
			return false;
		}

		return App::authorization_service()->can( $user_id, $clinic_id, self::CHECKOUT_CAP );
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
