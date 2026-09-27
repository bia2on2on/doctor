<?php
/**
 * Phase 11 Slice 1 — Staff Portal Reception Arrival Board (portal boundary).
 *
 * An authorized secretary works inside the EXISTING independent CPMS Staff
 * Portal: one trusted Clinic + one operational Location, today's booked
 * patients for that Location, ONE arrival action and read-only queue status.
 *
 * Adapter architecture (same as the Doctor Portal boundary): this controller
 * enforces the STRICT reception membership/Location contract in front of the
 * established shared services, which stay untouched:
 *   - arrivals run the EXISTING VisitService::checkIn() then the EXISTING
 *     VisitService::transition( 'enqueue' ) in valid order (VisitMachine
 *     new→check_in→checked_in→enqueue→waiting). No new status, no second
 *     machine, no parallel backend.
 *   - the board reuses the existing VisitService::today() queue read and the
 *     existing bounded patient presentations.
 *   - doctor-only queue actions stay on the existing doctor routes.
 *
 * Strict reception contract (enforced HERE, never globally):
 *   - authentication + secretary role + ACTIVE Clinic membership; membership
 *     alone is not permission (clinic-scoped capabilities via
 *     RestBase::requireClinicPermission).
 *   - operational Location policy: 0 eligible ⇒ fail closed / no reception
 *     data and no mutation; 1 eligible ⇒ auto-resolution allowed; N>1 ⇒
 *     explicit Location REQUIRED (never a first-Location fallback).
 *   - raw clinic/location/appointment ids are selectors only: cross-Clinic and
 *     cross-Location appointment selectors fail closed with the canonical
 *     non-enumerating fingerprint before any durable change.
 *   - operational "today" comes from the selected Location IANA timezone via
 *     the established VisitService operational-day contract.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Staff Portal reception boundary — context, board (read-only) and arrival.
 */
final class ReceptionPortalController extends RestBase {

	/**
	 * @param MembershipRepository $memberships Membership lookup (trusted Clinic/Location eligibility).
	 */
	public function __construct(
		private readonly MembershipRepository $memberships,
		private readonly AppointmentRepository $appointments
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/staff/portal/reception/context',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->context( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_reception( $r, [ RolesAndCapabilities::QUEUE_READ ] ),
				],
			]
		);

		register_rest_route(
			self::NS,
			'/staff/portal/reception/board',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->board( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_reception( $r, [ RolesAndCapabilities::QUEUE_READ ] ),
				],
			]
		);

		register_rest_route(
			self::NS,
			'/staff/portal/reception/arrivals',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->arrivals( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_reception( $r, [ RolesAndCapabilities::QUEUE_CHECKIN, RolesAndCapabilities::QUEUE_ADVANCE ] ),
					'args'                => [
						'patient_id'     => [
							'required' => true,
							'type'     => 'integer',
						],
						'appointment_id' => [
							'required' => true,
							'type'     => 'integer',
						],
					],
				],
			]
		);
	}

	/**
	 * Reception permission (portal boundary): nonce + capabilities + secretary
	 * role + clinic-scoped authorization against the trusted Clinic.
	 *
	 * Role, capability and membership stay separate: WP role gates the module,
	 * the clinic-scoped authorization requires durable ACTIVE membership AND
	 * the requested scoped capability in the SAME trusted Clinic (explicit deny
	 * wins over the role preset) — membership alone is never permission.
	 *
	 * @param list<string> $caps Scoped capabilities required for this route.
	 */
	private function perm_reception( WP_REST_Request $r, array $caps ): bool|WP_Error {
		$nonce = $this->requireNonce( $r );
		if ( $nonce instanceof WP_Error ) {
			return $nonce;
		}
		foreach ( $caps as $cap ) {
			$allowed = $this->requireCap( $cap );
			if ( $allowed instanceof WP_Error ) {
				return $allowed;
			}
			$scoped = $this->requireClinicPermission( $cap );
			if ( $scoped instanceof WP_Error ) {
				return $scoped;
			}
		}

		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) || (int) $user->ID <= 0 ) {
			return $this->error( 'CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید' );
		}
		$roles = (array) ( $user->roles ?? [] );
		if ( ! in_array( RolesAndCapabilities::ROLE_SECRETARY, $roles, true ) ) {
			return $this->error( 'CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید' );
		}

		return true;
	}

	/**
	 * Reception context: trusted Clinic + eligible operational Locations for
	 * the 0/1/N selection contract. Raw ids remain selectors only; nothing here
	 * grants authority and no clinic/location payload carries patient data.
	 */
	private function context( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		unset( $r );
		$user_id     = (int) get_current_user_id();
		$memberships = $this->memberships->active_for_user( $user_id );
		$clinics     = [];
		foreach ( $memberships as $m ) {
			$clinics[] = [
				'id'   => (int) $m['clinic_id'],
				'name' => (string) ( $m['clinic_name'] ?? ( 'Clinic ' . (int) $m['clinic_id'] ) ),
				'slug' => (string) ( $m['clinic_slug'] ?? '' ),
			];
		}

		$selected_clinic_id   = null;
		$selected_location_id = null;
		try {
			$scope                = App::scope();
			$selected_clinic_id   = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
			$selected_location_id = null !== $scope->locationId ? (int) $scope->locationId : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
		} catch ( \Throwable $e ) {
			unset( $e );
			if ( 1 === count( $clinics ) ) {
				$selected_clinic_id = (int) $clinics[0]['id'];
			}
		}

		$eligible = [];
		if ( null !== $selected_clinic_id ) {
			$eligible = $this->eligible_locations_for_clinic( $selected_clinic_id, $user_id );
			if ( null !== $selected_location_id ) {
				$known = false;
				foreach ( $eligible as $row ) {
					if ( (int) $row['id'] === $selected_location_id ) {
						$known = true;
						break;
					}
				}
				if ( ! $known ) {
					$selected_location_id = null;
				}
			}
			if ( null === $selected_location_id && 1 === count( $eligible ) ) {
				$selected_location_id = (int) $eligible[0]['id'];
			}
		}

		$current_clinic = null;
		foreach ( $clinics as $c ) {
			if ( (int) $c['id'] === (int) $selected_clinic_id ) {
				$current_clinic = $c;
				break;
			}
		}

		$current_location = null;
		if ( null !== $selected_location_id ) {
			foreach ( $eligible as $row ) {
				if ( (int) $row['id'] === $selected_location_id ) {
					$current_location = $row;
					break;
				}
			}
		}

		return $this->success(
			[
				'clinics'              => $clinics,
				'current_clinic'       => $current_clinic,
				'selected_clinic_id'   => $selected_clinic_id,
				'eligible_locations'   => $eligible,
				'current_location'     => $current_location,
				'selected_location_id' => $selected_location_id,
			]
		);
	}

	/**
	 * Reception board: today's booked patients for the trusted Location plus a
	 * read-only view of the existing queue (waiting / called / in_consultation
	 * and established skipped visibility via visit_status). Strict Location
	 * policy: 0 eligible ⇒ empty fail-closed payload; N>1 without an explicit
	 * trusted Location ⇒ CLINIC_SCOPE_REQUIRED (location_required, no eligible
	 * ids); foreign/inactive/unassigned ⇒ CLINIC_SCOPE_UNAVAILABLE.
	 */
	private function board( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		unset( $r );
		$user_id  = (int) get_current_user_id();
		$resolved = $this->resolve_reception_location( $user_id );
		if ( $resolved instanceof WP_Error ) {
			return $resolved;
		}
		if ( null === $resolved['location_id'] ) {
			return $this->success( $this->empty_board() );
		}

		$clinic_id   = (int) $resolved['clinic_id'];
		$location_id = (int) $resolved['location_id'];

		try {
			$today = App::visitService()->today( $user_id );
		} catch ( VisitException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract
		}

		$date = (string) ( $today['date'] ?? '' );
		if ( '' === $date ) {
			return $this->success( $this->empty_board() );
		}

		return $this->success(
			[
				'date'          => $date,
				'clinic_id'     => $clinic_id,
				'location_id'   => $location_id,
				'location_name' => $resolved['location_name'],
				'appointments'  => $this->appointments->list_for_reception_operational_day( $clinic_id, $location_id, $date ),
				'queue'         => $today['queue'],
				'stats'         => $today['stats'],
				'last_event_id' => (int) ( $today['last_event_id'] ?? 0 ),
			]
		);
	}

	/**
	 * The one reception arrival action: EXISTING check-in then the EXISTING
	 * enqueue transition, in valid order, ending in the EXISTING waiting state.
	 *
	 * Recoverable partial: if stage 1 (check-in) commits but stage 2 (enqueue)
	 * cannot complete, the response is NEVER a success — it is the bounded
	 * CLINIC_ARRIVAL_INCOMPLETE envelope carrying the durable checked_in state.
	 * A retry for the same authorized appointment derives its target
	 * server-side (appointment + trusted Clinic + selected Location) and runs
	 * ONLY the existing enqueue transition on the existing visit — never a
	 * second check-in. A client-supplied visit id is never authority.
	 */
	private function arrivals( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$user_id        = (int) get_current_user_id();
		$patient_id     = (int) ( $r['patient_id'] ?? 0 );
		$appointment_id = (int) ( $r['appointment_id'] ?? 0 );
		if ( $patient_id <= 0 || $appointment_id <= 0 ) {
			return $this->error( 'CLINIC_VALIDATION_FAILED', 422, 'شناسه نوبت یا بیمار نامعتبر است' );
		}

		$resolved = $this->resolve_reception_location( $user_id );
		if ( $resolved instanceof WP_Error ) {
			return $resolved;
		}
		if ( null === $resolved['location_id'] ) {
			// 0 eligible Locations: fail closed — no reception mutation at all.
			return $this->error( 'CLINIC_SCOPE_UNAVAILABLE', 403, 'امکان تعیین محدودهٔ کلینیک معتبر نیست.', [ 'reason' => 'location' ] );
		}

		$clinic_id   = (int) $resolved['clinic_id'];
		$location_id = (int) $resolved['location_id'];

		// Selector validation at the reception boundary: the appointment must
		// durably belong to the trusted Clinic AND the trusted selected
		// Location (and to the selected patient). Any mismatch shares the one
		// canonical non-enumerating 404 fingerprint, before any durable change.
		$db   = App::db();
		$appt = $db->fetchRow(
			'SELECT id, clinic_id, location_id, patient_id FROM ' . $db->table( 'cpms_appointments' ) . ' WHERE id = %d LIMIT 1',
			[ $appointment_id ]
		);
		if (
			null === $appt
			|| (int) $appt['clinic_id'] !== $clinic_id
			|| (int) $appt['location_id'] !== $location_id
		) {
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'نوبت یافت نشد' );
		}
		if ( (int) $appt['patient_id'] !== $patient_id ) {
			return $this->error( 'CLINIC_PERMISSION_DENIED', 403, 'نوبت به این بیمار تعلق ندارد' );
		}

		// RECOVERY — the same authorized appointment may already carry its
		// valid active Visit stuck in `checked_in` (stage 1 committed, stage 2
		// failed). The recovery target is derived SERVER-SIDE from the
		// authorized appointment + trusted Clinic + selected Location; a
		// client-supplied visit id is never authority. No broad transaction
		// hides the intermediate state — it is real and recoverable.
		$active = $db->fetchRow(
			'SELECT id, status FROM ' . $db->table( 'cpms_visits' ) . ' WHERE appointment_id = %d AND clinic_id = %d AND location_id = %d AND active = 1 ORDER BY id DESC LIMIT 1',
			[ $appointment_id, $clinic_id, $location_id ]
		);
		if ( null !== $active ) {
			$active_status = (string) $active['status'];
			if ( 'checked_in' !== $active_status ) {
				// Already queued / in service (waiting, called, in_consultation,
				// …) — fail closed; no valid retry action exists for this row.
				return $this->error( 'CLINIC_INVALID_APPOINTMENT_STATE', 409, 'این نوبت هم‌اکنون در صف پذیرش یا ویزیت است', [ 'visit_status' => $active_status ] );
			}

			// Execute ONLY the existing enqueue transition on the existing
			// visit — the established checked_in→waiting machine path.
			try {
				$visit = App::visitService()->transition( $user_id, (int) $active['id'], 'enqueue' );
			} catch ( \Throwable $e ) {
				error_log( '[CPMS][ReceptionPortalController] recovery enqueue: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- established controller convention
				$code    = $e instanceof VisitException ? $e->errorCode : 'CLINIC_INTERNAL_ERROR'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract
				$message = $e instanceof VisitException ? $e->getMessage() : 'افزودن به صف انجام نشد';
				return $this->arrival_incomplete( 'existing', (int) $active['id'], 'checked_in', $code, $message );
			}
			// The response truth is the DURABLE visit state, never the
			// transition's in-memory return (contract truthfulness).
			$fresh    = $this->fresh_visit( $user_id, (int) $active['id'] );
			$outcome  = (string) ( $fresh['status'] ?? '' );
			$complete = 'waiting' === $outcome;
			if ( ! $complete ) {
				return $this->arrival_incomplete( 'existing', (int) $active['id'], ( '' === $outcome ? 'checked_in' : $outcome ), 'CLINIC_INVALID_TRANSITION', 'افزودن به صف انجام نشد' );
			}
			return $this->success(
				[
					'visit'   => ( $fresh ?: $visit ),
					'arrival' => [
						'check_in'      => 'existing',
						'enqueue'       => 'ok',
						'outcome'       => $outcome,
						'complete'      => true,
						'enqueue_error' => null,
					],
				]
			);
		}

		// Stage 1 — EXISTING authorized check-in transition (new→checked_in,
		// with the existing per-Clinic auto-enqueue behavior inside its own
		// established transaction).
		try {
			$visit = App::visitService()->checkIn( $user_id, $patient_id, $appointment_id );
		} catch ( VisitException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract
		}

		if ( 'waiting' === (string) ( $visit['status'] ?? '' ) ) {
			// The existing auto-enqueue transition already produced waiting.
			return $this->success(
				[
					'visit'   => $visit,
					'arrival' => [
						'check_in'      => 'ok',
						'enqueue'       => 'ok',
						'outcome'       => 'waiting',
						'complete'      => true,
						'enqueue_error' => null,
					],
				]
			);
		}

		// Stage 2 — EXISTING authorized enqueue transition (checked_in→waiting).
		try {
			$visit = App::visitService()->transition( $user_id, (int) $visit['id'], 'enqueue' );
			// The response truth is the DURABLE visit state, never the
			// transition's in-memory return (contract truthfulness).
			$fresh   = $this->fresh_visit( $user_id, (int) ( $visit['id'] ?? 0 ) );
			$outcome = (string) ( $fresh['status'] ?? '' );
			$complete = 'waiting' === $outcome;
			if ( ! $complete ) {
				return $this->arrival_incomplete( 'ok', (int) ( $visit['id'] ?? 0 ), ( '' === $outcome ? 'checked_in' : $outcome ), 'CLINIC_INVALID_TRANSITION', 'افزودن به صف انجام نشد' );
			}
			return $this->success(
				[
					'visit'   => ( $fresh ?: $visit ),
					'arrival' => [
						'check_in'      => 'ok',
						'enqueue'       => 'ok',
						'outcome'       => $outcome,
						'complete'      => true,
						'enqueue_error' => null,
					],
				]
			);
		} catch ( \Throwable $e ) {
			// Honest partial: check-in committed, enqueue did not — the
			// response is a real failure that preserves the partial checked_in
			// outcome; it is never silently turned into generic success.
			error_log( '[CPMS][ReceptionPortalController] enqueue stage: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- established controller convention
			$code    = $e instanceof VisitException ? $e->errorCode : 'CLINIC_INTERNAL_ERROR'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract
			$message = $e instanceof VisitException ? $e->getMessage() : 'افزودن به صف انجام نشد';
			$fresh   = $this->fresh_visit( $user_id, (int) ( $visit['id'] ?? 0 ) );
			return $this->arrival_incomplete( 'ok', (int) ( $visit['id'] ?? 0 ), (string) ( $fresh['status'] ?? 'checked_in' ), $code, $message );
		}
	}

	/**
	 * Honest partial-arrival envelope (contract truthfulness): stage 1 may be
	 * committed while the enqueue stage is not. Never a success — the bounded,
	 * non-sensitive CLINIC_ARRIVAL_INCOMPLETE failure carries the durable
	 * state so the UI can offer the recovery action.
	 */
	private function arrival_incomplete( string $check_in_stage, int $visit_id, string $outcome, string $code, string $message ): WP_Error {
		return $this->error(
			'CLINIC_ARRIVAL_INCOMPLETE',
			500,
			'حضور ثبت شد اما قرارگیری بیمار در صف انجام نشد',
			[
				'visit_id'     => $visit_id,
				'visit_status' => $outcome,
				'arrival'      => [
					'check_in'      => $check_in_stage,
					'enqueue'       => 'failed',
					'outcome'       => $outcome,
					'complete'      => false,
					'enqueue_error' => [
						'code'    => $code,
						'message' => $message,
					],
				],
			]
		);
	}

	/**
	 * Re-read one visit through the established bounded presentation.
	 *
	 * @return array<string, mixed>
	 */
	private function fresh_visit( int $user_id, int $visit_id ): array {
		if ( $visit_id <= 0 ) {
			return [];
		}
		try {
			return App::visitService()->getVisit( $user_id, $visit_id );
		} catch ( \Throwable $e ) {
			unset( $e );
			return [];
		}
	}

	/**
	 * Strict reception Location resolution (0/1/N policy).
	 *
	 * @return array{clinic_id: int, location_id: int|null, location_name: string|null}|WP_Error
	 */
	private function resolve_reception_location( int $user_id ): array|WP_Error {
		try {
			$scope     = App::scope();
			$clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
			$explicit  = null !== $scope->locationId ? (int) $scope->locationId : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope contract.
		} catch ( \Throwable $e ) {
			unset( $e );
			return $this->error( 'CLINIC_SCOPE_REQUIRED', 400, 'محدودهٔ کلینیک لازم است.' );
		}

		$eligible = $this->eligible_locations_for_clinic( $clinic_id, $user_id );
		if ( [] === $eligible ) {
			// 0 eligible: fail closed — no reception data, no reception mutation.
			return [
				'clinic_id'     => $clinic_id,
				'location_id'   => null,
				'location_name' => null,
			];
		}

		$by_id = [];
		foreach ( $eligible as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}

		if ( null !== $explicit ) {
			if ( ! isset( $by_id[ $explicit ] ) ) {
				// Foreign / inactive / unassigned Location selector.
				return $this->error( 'CLINIC_SCOPE_UNAVAILABLE', 403, 'امکان تعیین محدودهٔ کلینیک معتبر نیست.', [ 'reason' => 'location' ] );
			}
			return [
				'clinic_id'     => $clinic_id,
				'location_id'   => $explicit,
				'location_name' => (string) $by_id[ $explicit ]['name'],
			];
		}

		if ( 1 === count( $by_id ) ) {
			$only_id = (int) ( array_keys( $by_id )[0] ?? 0 );
			return [
				'clinic_id'     => $clinic_id,
				'location_id'   => $only_id,
				'location_name' => (string) $by_id[ $only_id ]['name'],
			];
		}

		// N>1 without an explicit trusted Location: REQUIRED, never a fallback.
		return $this->error(
			'CLINIC_SCOPE_REQUIRED',
			400,
			'محدودهٔ کلینیک لازم است.',
			[
				'field'  => 'location_id',
				'reason' => 'location_required',
			]
		);
	}

	/**
	 * Fail-closed empty board (no eligible Location ⇒ no reception data).
	 *
	 * @return array<string, mixed>
	 */
	private function empty_board(): array {
		return [
			'date'          => null,
			'clinic_id'     => null,
			'location_id'   => null,
			'location_name' => null,
			'appointments'  => [],
			'queue'         => [],
			'stats'         => [],
			'last_event_id' => 0,
		];
	}

	/**
	 * Eligible operational Locations for the trusted Clinic: active persisted
	 * Locations, limited to the membership's assignment when scope_mode=location.
	 * Requires durable ACTIVE membership — membership alone grants nothing else.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function eligible_locations_for_clinic( int $clinic_id, int $wp_user_id ): array {
		$membership = $this->memberships->find_active( $clinic_id, $wp_user_id );
		if ( null === $membership ) {
			return [];
		}

		$db          = App::db();
		$active_rows = $db->fetchAll(
			'SELECT id, name, slug, timezone, is_primary, is_active FROM ' . $db->table( 'cpms_locations' ) .
			' WHERE clinic_id = %d AND is_active = 1 ORDER BY id ASC',
			[ $clinic_id ]
		);
		$active      = [];
		foreach ( ( is_array( $active_rows ) ? $active_rows : [] ) as $row ) {
			$active[ (int) $row['id'] ] = [
				'id'         => (int) $row['id'],
				'name'       => (string) $row['name'],
				'slug'       => (string) $row['slug'],
				'timezone'   => (string) $row['timezone'],
				'is_primary' => 1 === (int) $row['is_primary'],
				'is_active'  => 1 === (int) $row['is_active'],
			];
		}

		$scope_mode = (string) ( $membership['scope_mode'] ?? 'clinic' );
		if ( 'location' === $scope_mode ) {
			$assigned_ids = $this->memberships->location_ids_for( (int) $membership['id'] );
			$eligible     = [];
			foreach ( $assigned_ids as $id ) {
				if ( isset( $active[ (int) $id ] ) ) {
					$eligible[] = $active[ (int) $id ];
				}
			}
			return $eligible;
		}

		return array_values( $active );
	}
}
