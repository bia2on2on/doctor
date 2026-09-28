<?php
/**
 * Phase 12 Slice 1 — read-only Finance board boundary for the independent
 * Staff Portal. Existing Clinic-scoped permissions authorize every read.
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
final class FinancePortalController extends RestBase {

	private const READ_CAPS = array(
		RolesAndCapabilities::FINANCE_READ,
		RolesAndCapabilities::INVOICE_READ,
		RolesAndCapabilities::QUEUE_READ,
	);

	private const RESULT_LIMIT = 100;

	public function __construct(
		MembershipRepository $memberships,
		VisitRepository $visits
	) {
		$this->memberships = $memberships;
		$this->visits      = $visits;
	}

	private MembershipRepository $memberships;
	private VisitRepository $visits;

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
	}

	/** Context returns only eligible Location choices; no patient or finance data. */
	private function context( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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

		$date = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone )->format( 'Y-m-d' );
		$rows = $this->visits->awaiting_payment_finance_board( $clinic_id, $location_id, $date, self::RESULT_LIMIT + 1 );
		$has_more = count( $rows ) > self::RESULT_LIMIT;
		if ( $has_more ) {
			$rows = array_slice( $rows, 0, self::RESULT_LIMIT );
		}

		$visits = array();
		foreach ( $rows as $row ) {
			$appointment_time = trim( (string) ( $row['appointment_time'] ?? '' ) );
			if ( '' !== $appointment_time ) {
				$time = substr( $appointment_time, 0, 5 );
			} else {
				$checked_in_utc = new DateTimeImmutable( (string) $row['check_in_at'], new DateTimeZone( 'UTC' ) );
				$time           = $checked_in_utc->setTimezone( $timezone )->format( 'H:i' );
			}

			$invoice_exists = isset( $row['invoice_status'] ) && '' !== (string) $row['invoice_status'];
			$visits[]       = array(
				'patient_name'      => trim( (string) $row['patient_first_name'] . ' ' . (string) $row['patient_last_name'] ),
				'clinician_name'    => (string) $row['clinician_name'],
				'operational_date'  => (string) $row['visit_date'],
				'jalali_date'       => Jalali::formatYmd( (string) $row['visit_date'] ),
				'operational_time'  => $time,
				'visit_status'      => 'awaiting_payment',
				'invoice'           => $invoice_exists ? array(
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
				'location_name' => (string) $locations[ $location_id ]['name'],
				'visits'        => $visits,
				'has_more'      => $has_more,
			)
		);
	}

	/** Existing nonce + WP capability + Clinic-scoped authority; no audit writes. */
	private function permission( WP_REST_Request $request ): bool|WP_Error {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return $this->error( 'CLINIC_INVALID_NONCE', 403, 'Nonce نامعتبر است.' );
		}

		$user_id = (int) get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( false === $user || ! $user->exists() ) {
			return $this->error( 'CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید.' );
		}
		foreach ( self::READ_CAPS as $capability ) {
			if ( ! $user->has_cap( $capability ) ) {
				return $this->error( 'CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید.' );
			}
		}

		$scope = $this->trusted_scope();
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}
		$clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
		foreach ( self::READ_CAPS as $capability ) {
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
}
