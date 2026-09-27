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
 * Phase 11 Slice 2 — Clinic patient search (read-only selection): the
 * reception search route is a thin adapter over the ESTABLISHED
 * PatientService::search() (→ PatientRepository::search() + the bounded
 * searchView presentation with masked national ID). It adds only the strict
 * reception boundary (secretary role + ACTIVE membership + clinic-scoped
 * cpms_patient_read) in front of that contract. Patient identity is
 * Clinic-scoped: the reception Location selection is deliberately NOT a
 * filter, and no patient query, service, role, capability or schema is added.
 *
 * Phase 11 Slice 3 — create Clinic patient, then read-only select: the
 * reception create route is a thin adapter over the ESTABLISHED
 * PatientService::create() (→ PatientRepository::create, Clinic-scoped MRN,
 * mobile normalization/duplicate). It adds only the strict reception
 * boundary (secretary role + ACTIVE membership + clinic-scoped
 * cpms_patient_create) and returns the SAME bounded searchView used by
 * Slice 2 — never staffView. Location is visible in Reception context but
 * is never written as patient ownership. No walk-in / appointment /
 * check-in / queue / Visit is created.
 *
 * Phase 11 Slice 5 — create an appointment for an already-selected Clinic
 * patient: `GET /staff/portal/reception/slots` reads the ALREADY-GENERATED
 * available slots of one explicitly selected eligible doctor inside the trusted
 * operational Location for one Location-local operational date (ONE bounded
 * read per selection change, persisted rows only — a template without generated
 * slots offers nothing), and `POST /staff/portal/reception/appointments`
 * delegates the booking to the ESTABLISHED BookingService::createByStaff()
 * (the same service behind `POST /clinic/v1/appointments`) after re-verifying
 * the concrete persisted slot_id against the trusted Clinic + Location + the
 * selected eligible doctor + open state. slot_id is the only booking authority:
 * the persisted row supplies date/time, never the client. Both routes sit
 * behind the existing perm_reception with clinic-scoped cpms_appt_create. No
 * booking rule is duplicated, no second scheduler, no slot generation, no
 * Visit / queue / check-in / walk-in / payment, no reschedule/cancel, no
 * migration, no new role/capability, no reuse of the public availability route
 * (its authority derives from clinicians.clinic_id).
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Staff Portal reception boundary — context, board (read-only), arrival,
 * read-only Clinic patient search, bounded Clinic patient create, eligible
 * doctors + walk-in, and appointment booking from an explicitly selected
 * already-generated slot.
 */
final class ReceptionPortalController extends RestBase {

	/** Upper bound for one Location's eligible-doctor options (bounded query). */
	private const CLINICIAN_OPTION_LIMIT = 100;

	/** Hard ceiling for the selectable-day options of one slot read (bounded payload). */
	private const DAY_OPTION_LIMIT = 62;

	/**
	 * @param MembershipRepository $memberships Membership lookup (trusted Clinic/Location eligibility).
	 * @param AppointmentRepository $appointments Bounded operational-day appointment presentation.
	 * @param SlotRepository $slots Already-generated slot reads + persisted slot re-verification.
	 */
	public function __construct(
		private readonly MembershipRepository $memberships,
		private readonly AppointmentRepository $appointments,
		private readonly SlotRepository $slots
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
			'/staff/portal/reception/patients/search',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->patient_search( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_reception( $r, [ RolesAndCapabilities::PATIENT_READ ] ),
					'args'                => [
						'q'     => [
							'required' => true,
							'type'     => 'string',
						],
						'limit' => [
							'required' => false,
							'type'     => 'integer',
							'default'  => 25,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/staff/portal/reception/patients',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->patient_create( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_reception( $r, [ RolesAndCapabilities::PATIENT_CREATE ] ),
					'args'                => [
						'first_name'  => [
							'required' => true,
							'type'     => 'string',
						],
						'last_name'   => [
							'required' => true,
							'type'     => 'string',
						],
						'mobile'      => [
							'required' => true,
							'type'     => 'string',
						],
						'national_id' => [
							'required' => false,
							'type'     => 'string',
						],
						'birth_date'  => [
							'required' => false,
							'type'     => 'string',
						],
						'gender'      => [
							'required' => false,
							'type'     => 'string',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/staff/portal/reception/clinicians',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->clinicians( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_reception( $r, [ RolesAndCapabilities::QUEUE_CHECKIN ] ),
				],
			]
		);

		register_rest_route(
			self::NS,
			'/staff/portal/reception/walk-ins',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->walk_in( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_reception( $r, [ RolesAndCapabilities::QUEUE_CHECKIN, RolesAndCapabilities::QUEUE_ADVANCE ] ),
					'args'                => [
						'patient_id'   => [
							'required' => true,
							'type'     => 'integer',
						],
						'clinician_id' => [
							'required' => true,
							'type'     => 'integer',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/staff/portal/reception/slots',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->reception_slots( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_reception( $r, [ RolesAndCapabilities::APPT_CREATE ] ),
					'args'                => [
						'clinician_id' => [
							'required' => true,
							'type'     => 'integer',
						],
						// Deliberately an unformatted string: the Location-local
						// date is validated by this boundary so a malformed
						// selector answers with the established bounded
						// CLINIC_VALIDATION_FAILED envelope instead of a generic
						// framework parameter error.
						'date'         => [
							'required' => false,
							'type'     => 'string',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/staff/portal/reception/appointments',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->reception_appointment_create( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_reception( $r, [ RolesAndCapabilities::APPT_CREATE ] ),
					'args'                => [
						'patient_id'   => [
							'required' => true,
							'type'     => 'integer',
						],
						'clinician_id' => [
							'required' => true,
							'type'     => 'integer',
						],
						'slot_id'      => [
							'required' => true,
							'type'     => 'integer',
						],
						'reason'       => [
							'required' => false,
							'type'     => 'string',
						],
					],
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
	 * Reception Clinic patient search (read-only): delegates to the ESTABLISHED
	 * PatientService::search() contract — trimmed query, minimum 2 characters
	 * (CLINIC_VALIDATION_FAILED otherwise), limit clamped by the service, trusted
	 * Clinic from App::scope(), active patients only, bounded searchView with the
	 * masked national ID. The Location selector is intentionally ignored here:
	 * patient identity is Clinic-scoped and Location never filters this search.
	 * Nothing is written; a returned patient id stays a selector only.
	 */
	private function patient_search( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		try {
			return $this->success(
				App::patientService()->search(
					(string) $r->get_param( 'q' ),
					(int) $r->get_param( 'limit' )
				)
			);
		} catch ( BookingException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established BookingException contract
		}
	}

	/**
	 * Reception Clinic patient create: delegates to the ESTABLISHED
	 * PatientService::create() contract (trusted Clinic from App::scope(),
	 * mobile normalize/duplicate, generated MRN). Only the small form fields
	 * are forwarded — clinical/address/emergency/MRN/clinic_id/location_id
	 * from the body never become writes. The response is the Slice 2
	 * searchView (masked national ID), never staffView. Location is not a
	 * patient-ownership field and is not required for this mutation.
	 */
	private function patient_create( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$fields = [];
		foreach ( [ 'first_name', 'last_name', 'mobile', 'national_id', 'birth_date', 'gender' ] as $key ) {
			$value = $r->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$fields[ $key ] = $value;
			}
		}

		try {
			$created = App::patientService()->create( $fields, (int) get_current_user_id() );

			return $this->success( App::patientService()->to_search_view( $created ) );
		} catch ( BookingException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established BookingException contract
		}
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
					'visit'   => ( [] !== $fresh ) ? $fresh : $visit,
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
			$fresh    = $this->fresh_visit( $user_id, (int) ( $visit['id'] ?? 0 ) );
			$outcome  = (string) ( $fresh['status'] ?? '' );
			$complete = 'waiting' === $outcome;
			if ( ! $complete ) {
				return $this->arrival_incomplete( 'ok', (int) ( $visit['id'] ?? 0 ), ( '' === $outcome ? 'checked_in' : $outcome ), 'CLINIC_INVALID_TRANSITION', 'افزودن به صف انجام نشد' );
			}
			return $this->success(
				[
					'visit'   => ( [] !== $fresh ) ? $fresh : $visit,
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
	 * Reception eligible doctors (read-only) for the trusted Clinic + trusted
	 * operational Location under the strict 0/1/N Location policy. Bounded
	 * option rows only (id + display name): no user/admin/private data, no
	 * broad directory. 0 eligible Locations ⇒ fail-closed empty payload.
	 */
	private function clinicians( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		unset( $r );
		$resolved = $this->resolve_reception_location( (int) get_current_user_id() );
		if ( $resolved instanceof WP_Error ) {
			return $resolved;
		}
		if ( null === $resolved['location_id'] ) {
			return $this->success(
				[
					'location_id'   => null,
					'location_name' => null,
					'clinicians'    => [],
				]
			);
		}

		return $this->success(
			[
				'location_id'   => (int) $resolved['location_id'],
				'location_name' => $resolved['location_name'],
				'clinicians'    => $this->eligible_clinicians( (int) $resolved['clinic_id'], (int) $resolved['location_id'], null ),
			]
		);
	}

	/**
	 * The one Reception walk-in action for an explicitly selected Clinic
	 * patient + explicitly selected (or single auto-resolved in the UI)
	 * eligible doctor. Delegates to the EXISTING VisitService::walkIn()
	 * (create_walk_in → checked_in, established duplicate guard, history,
	 * VISIT_WALK_IN audit, per-Clinic auto-enqueue) and, when auto-enqueue
	 * legitimately leaves checked_in, the EXISTING enqueue transition
	 * (checked_in → waiting). No new status/machine; no appointment.
	 *
	 * Recoverable partial: when the walk-in committed but enqueue did not,
	 * the response is NEVER a success. A retry derives the existing Visit
	 * SERVER-SIDE from the established duplicate-active-Visit rule and
	 * enqueues it ONLY when it is this trusted Clinic + Location + patient +
	 * doctor, source=walk_in, no appointment, still checked_in. Any other
	 * existing active Visit keeps the established bounded conflict. A
	 * client-supplied visit id is never read.
	 */
	private function walk_in( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$user_id      = (int) get_current_user_id();
		$patient_id   = (int) ( $r['patient_id'] ?? 0 );
		$clinician_id = (int) ( $r['clinician_id'] ?? 0 );
		if ( $patient_id <= 0 || $clinician_id <= 0 ) {
			return $this->error( 'CLINIC_VALIDATION_FAILED', 422, 'شناسه بیمار یا پزشک نامعتبر است' );
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

		// Patient selector: must be an ACTIVE patient of the trusted Clinic
		// (the same population Reception search/create can select). Foreign,
		// archived and unknown ids share one non-enumerating fingerprint.
		$db      = App::db();
		$patient = $db->fetchRow(
			'SELECT id, clinic_id, status FROM ' . $db->table( 'cpms_patients' ) . ' WHERE id = %d LIMIT 1',
			[ $patient_id ]
		);
		if ( null === $patient || (int) $patient['clinic_id'] !== $clinic_id || 'active' !== (string) $patient['status'] ) {
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'بیمار یافت نشد' );
		}

		// Doctor selector: must be eligible for the trusted Clinic AND the
		// trusted Location (stricter than the shared participation check:
		// home-Clinic metadata never authorizes a Reception walk-in).
		if ( [] === $this->eligible_clinicians( $clinic_id, $location_id, $clinician_id ) ) {
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'پزشک یافت نشد' );
		}

		try {
			$visit = App::visitService()->walkIn( $user_id, $patient_id, $clinician_id );
		} catch ( VisitException $e ) {
			$recoverable = $this->recoverable_walk_in( $e, $clinic_id, $location_id, $patient_id, $clinician_id );
			if ( null !== $recoverable ) {
				return $this->walk_in_enqueue( $user_id, $recoverable, 'existing' );
			}
			if ( 'CLINIC_DUPLICATE_ACTIVE_VISIT' === $e->errorCode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract
				// Established conflict, bounded: the existing state only.
				return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), [ 'visit_status' => (string) ( $e->data['visit_status'] ?? '' ) ] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract
			}
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract
		}

		if ( 'waiting' === (string) ( $visit['status'] ?? '' ) ) {
			// The existing per-Clinic auto-enqueue already produced waiting.
			return $this->walk_in_success( $visit, 'new', 'auto' );
		}

		return $this->walk_in_enqueue( $user_id, (int) ( $visit['id'] ?? 0 ), 'new' );
	}

	/**
	 * Server-side recovery target from the ESTABLISHED duplicate guard
	 * (same patient + doctor + operational day): recoverable only when the
	 * durable row is this trusted Clinic + Location + patient + doctor,
	 * source=walk_in without appointment, active, still checked_in.
	 */
	private function recoverable_walk_in( VisitException $e, int $clinic_id, int $location_id, int $patient_id, int $clinician_id ): ?int {
		if ( 'CLINIC_DUPLICATE_ACTIVE_VISIT' !== $e->errorCode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract
			return null;
		}
		$visit_id = (int) ( $e->data['visit_id'] ?? 0 ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract
		if ( $visit_id <= 0 ) {
			return null;
		}
		$db  = App::db();
		$row = $db->fetchRow(
			'SELECT id, clinic_id, location_id, patient_id, clinician_id, appointment_id, source, status, active FROM ' . $db->table( 'cpms_visits' ) . ' WHERE id = %d LIMIT 1',
			[ $visit_id ]
		);
		if (
			null === $row
			|| (int) $row['clinic_id'] !== $clinic_id
			|| (int) $row['location_id'] !== $location_id
			|| (int) $row['patient_id'] !== $patient_id
			|| (int) $row['clinician_id'] !== $clinician_id
			|| null !== $row['appointment_id']
			|| 'walk_in' !== (string) $row['source']
			|| 1 !== (int) $row['active']
			|| 'checked_in' !== (string) $row['status']
		) {
			return null;
		}

		return (int) $row['id'];
	}

	/**
	 * EXISTING enqueue transition (checked_in → waiting) on a walk-in Visit;
	 * the response truth is the DURABLE state, never an in-memory return.
	 */
	private function walk_in_enqueue( int $user_id, int $visit_id, string $created ): WP_REST_Response|WP_Error {
		try {
			App::visitService()->transition( $user_id, $visit_id, 'enqueue' );
		} catch ( \Throwable $e ) {
			error_log( '[CPMS][ReceptionPortalController] walk-in enqueue: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- established controller convention
			$fresh = $this->fresh_visit( $user_id, $visit_id );
			if ( 'waiting' === (string) ( $fresh['status'] ?? '' ) ) {
				// Durable truth: a concurrent request already completed it.
				return $this->walk_in_success( $fresh, $created, 'ok' );
			}
			$code    = $e instanceof VisitException ? $e->errorCode : 'CLINIC_INTERNAL_ERROR'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established VisitException contract
			$message = $e instanceof VisitException ? $e->getMessage() : 'افزودن به صف انجام نشد';
			return $this->walk_in_incomplete( $created, (string) ( $fresh['status'] ?? 'checked_in' ), $code, $message );
		}

		$fresh   = $this->fresh_visit( $user_id, $visit_id );
		$outcome = (string) ( $fresh['status'] ?? '' );
		if ( 'waiting' !== $outcome ) {
			return $this->walk_in_incomplete( $created, ( '' === $outcome ? 'checked_in' : $outcome ), 'CLINIC_INVALID_TRANSITION', 'افزودن به صف انجام نشد' );
		}

		return $this->walk_in_success( $fresh, $created, 'ok' );
	}

	/**
	 * @param array<string, mixed> $visit Established presentVisit() row.
	 */
	private function walk_in_success( array $visit, string $created, string $enqueue ): WP_REST_Response {
		$bounded = [];
		foreach ( [ 'id', 'patient_id', 'patient_name', 'clinician_id', 'clinician_name', 'appointment_id', 'source', 'status', 'check_in_at', 'waiting_since', 'active' ] as $key ) {
			$bounded[ $key ] = $visit[ $key ] ?? null;
		}

		return $this->success(
			[
				'visit'   => $bounded,
				'walk_in' => [
					'created'       => $created,
					'enqueue'       => $enqueue,
					'outcome'       => (string) ( $visit['status'] ?? '' ),
					'complete'      => true,
					'enqueue_error' => null,
				],
			]
		);
	}

	/**
	 * Honest partial walk-in envelope: the walk-in Visit exists in checked_in
	 * but enqueue did not complete. Never a success; carries only the durable
	 * state (no visit id — recovery authority is derived server-side).
	 */
	private function walk_in_incomplete( string $created, string $outcome, string $code, string $message ): WP_Error {
		return $this->error(
			'CLINIC_WALK_IN_INCOMPLETE',
			500,
			'ورود حضوری ثبت شد اما قرارگیری بیمار در صف انجام نشد',
			[
				'visit_status' => $outcome,
				'walk_in'      => [
					'created'       => $created,
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
	 * Reception slot read (Phase 11 Slice 5): the ALREADY-GENERATED available
	 * slots of ONE explicitly selected eligible doctor, inside the trusted
	 * operational Location, for ONE Location-local operational date.
	 *
	 * ONE bounded read per selection change — never a per-slot request and never
	 * a poll. Only persisted rows are read: a weekly template without generated
	 * slots offers nothing and nothing is fabricated here (no second scheduler,
	 * no lazy generation). The PUBLIC availability route is deliberately not
	 * reused: its Clinic authority derives from clinicians.clinic_id (home
	 * Clinic) and it is not Location-bounded, while reception authority is the
	 * trusted Clinic + the trusted selected Location.
	 *
	 * The Location IANA timezone is the operational authority (Gregorian Y-m-d
	 * selector + the existing Jalali presentation); a malformed date selector is
	 * a bounded 422 and a date outside the Clinic booking horizon answers
	 * honestly empty rather than inventing capacity.
	 */
	private function reception_slots( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$user_id      = (int) get_current_user_id();
		$clinician_id = (int) ( $r['clinician_id'] ?? 0 );
		if ( $clinician_id <= 0 ) {
			return $this->error( 'CLINIC_VALIDATION_FAILED', 422, 'شناسهٔ پزشک نامعتبر است' );
		}

		$resolved = $this->resolve_reception_location( $user_id );
		if ( $resolved instanceof WP_Error ) {
			return $resolved;
		}
		if ( null === $resolved['location_id'] ) {
			// 0 eligible Locations: fail closed — the read answers with no scope
			// and no slot, never a guessed Location.
			return $this->success( $this->empty_slot_day( $clinician_id ) );
		}

		$clinic_id   = (int) $resolved['clinic_id'];
		$location_id = (int) $resolved['location_id'];
		$timezone    = $this->operational_timezone( (string) ( $resolved['timezone'] ?? '' ), $location_id, $clinic_id );

		// Doctor selector: eligible for the trusted Clinic AND the trusted
		// Location through the delivered Slice 4 contract; home-Clinic metadata
		// never authorizes a reception booking.
		$clinicians = $this->eligible_clinicians( $clinic_id, $location_id, $clinician_id );
		if ( [] === $clinicians ) {
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'پزشک یافت نشد' );
		}

		$now_local        = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( $timezone ) );
		$operational_date = $now_local->format( 'Y-m-d' );
		$horizon_days     = $this->booking_horizon_days( $clinic_id );
		$horizon_end      = $this->plus_days( $operational_date, $horizon_days );

		$requested = trim( (string) ( $r['date'] ?? '' ) );
		if ( '' === $requested ) {
			$date = $operational_date;
		} else {
			$date = $this->valid_local_date( $requested );
			if ( null === $date ) {
				return $this->error( 'CLINIC_VALIDATION_FAILED', 422, 'تاریخ نامعتبر است' );
			}
		}

		// The explicit date is a SELECTOR for querying valid slots, never
		// authority to fabricate one: outside the bounded window nothing is read
		// and nothing is offered.
		$rows = [];
		if ( $date >= $operational_date && $date <= $horizon_end ) {
			$rows = $this->slots->list_available_for_reception_day( $clinic_id, $location_id, $clinician_id, $date );
		}

		$slots     = [];
		$local_now = $now_local->format( 'H:i:s' );
		foreach ( $rows as $row ) {
			$time = substr( (string) $row['slot_time'], 0, 8 );
			// On the operational day an already-started slot is never offered:
			// the delegated staff create would only reject it, so the read stays
			// honest about what reception can book right now.
			if ( $date === $operational_date && $time <= $local_now ) {
				continue;
			}
			$slots[] = [
				'slot_id'       => (int) $row['id'],
				'time'          => substr( $time, 0, 5 ),
				'duration_min'  => (int) $row['duration_min'],
				'capacity_left' => (int) $row['capacity_left'],
			];
		}

		return $this->success(
			[
				'clinic_id'        => $clinic_id,
				'location_id'      => $location_id,
				'location_name'    => $resolved['location_name'],
				'timezone'         => $timezone,
				'clinician_id'     => $clinician_id,
				'clinician_name'   => (string) ( $clinicians[0]['name'] ?? '' ),
				'date'             => $date,
				'jalali'           => Jalali::formatYmd( $date ),
				'operational_date' => $operational_date,
				'horizon_end'      => $horizon_end,
				'days'             => $this->selectable_days( $operational_date, $horizon_days ),
				'slots'            => $slots,
			]
		);
	}

	/**
	 * Reception appointment create (Phase 11 Slice 5): ONE confirmed appointment
	 * for an explicitly selected Clinic patient in an explicitly selected,
	 * already-generated slot.
	 *
	 * Delegation, never duplication: the booking itself runs through the
	 * EXISTING BookingService::createByStaff() — the same service behind
	 * POST /clinic/v1/appointments — with its established license gate, trusted
	 * Clinic resolution, participation check, slot re-resolution, Location
	 * timezone window (staff minimum lead 0 + the Clinic booking horizon), the
	 * transactional duplicate + atomic capacity guards, the confirmed state with
	 * machine checks, reference numbering, audit and op-log. This boundary adds
	 * only the STRICT reception contract in front of it and re-verifies the
	 * persisted slot against the trusted scope. No booking rule is restated.
	 *
	 * slot_id is the ONLY booking authority: the concrete persisted slot is
	 * re-read and re-verified here (trusted Clinic + trusted Location + selected
	 * eligible doctor + open) and the persisted row supplies the date/time. A
	 * client date/time is never authority. Stale availability fails honestly
	 * (404 non-enumerating here, or the established 409 from the delegated
	 * transaction). No Visit / queue / check-in / walk-in / payment, no
	 * reschedule, no cancel, no slot generation.
	 */
	private function reception_appointment_create( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$user_id      = (int) get_current_user_id();
		$patient_id   = (int) ( $r['patient_id'] ?? 0 );
		$clinician_id = (int) ( $r['clinician_id'] ?? 0 );
		$slot_id      = (int) ( $r['slot_id'] ?? 0 );
		if ( $patient_id <= 0 || $clinician_id <= 0 ) {
			return $this->error( 'CLINIC_VALIDATION_FAILED', 422, 'شناسهٔ بیمار یا پزشک نامعتبر است' );
		}
		if ( $slot_id <= 0 ) {
			return $this->error( 'CLINIC_VALIDATION_FAILED', 422, 'شناسهٔ اسلات نامعتبر است' );
		}
		$reason = $r['reason'] ?? null;

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

		// Patient selector: an ACTIVE patient of the trusted Clinic — the same
		// population reception search/create can select. Foreign, archived and
		// unknown ids share one non-enumerating fingerprint.
		$db      = App::db();
		$patient = $db->fetchRow(
			'SELECT id, clinic_id, status FROM ' . $db->table( 'cpms_patients' ) . ' WHERE id = %d LIMIT 1',
			[ $patient_id ]
		);
		if ( null === $patient || (int) $patient['clinic_id'] !== $clinic_id || 'active' !== (string) $patient['status'] ) {
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'بیمار یافت نشد' );
		}

		// Doctor selector: eligible for the trusted Clinic AND the trusted
		// Location (stricter than the shared participation check that the
		// delegated service repeats internally).
		if ( [] === $this->eligible_clinicians( $clinic_id, $location_id, $clinician_id ) ) {
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'پزشک یافت نشد' );
		}

		// Slot selector: re-read the PERSISTED row and re-verify every trusted
		// selector BEFORE delegating. Capacity, duplicates and the booking
		// window stay the delegated service's authority inside its transaction.
		$slot = $this->slots->findByIdAndClinic( $slot_id, $clinic_id );
		if (
			null === $slot
			|| (int) $slot['location_id'] !== $location_id
			|| (int) $slot['clinician_id'] !== $clinician_id
			|| 1 !== (int) $slot['is_open']
		) {
			return $this->error( 'CLINIC_NOT_FOUND', 404, 'اسلات انتخابی یافت نشد' );
		}

		$slot_date = (string) $slot['slot_date'];
		$slot_time = substr( (string) $slot['slot_time'], 0, 8 );

		try {
			$view = App::bookingService()->createByStaff(
				$user_id,
				$patient_id,
				$clinician_id,
				$slot_date,
				$slot_time,
				is_string( $reason ) && '' !== trim( $reason ) ? trim( $reason ) : null,
				$slot_id
			);
		} catch ( BookingException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established BookingException contract
		} catch ( \Throwable $e ) {
			// Never a fake success and never a raw stack in the response.
			error_log( '[CPMS][ReceptionPortalController] appointment create: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- established controller convention

			return $this->error( 'CLINIC_INTERNAL_ERROR', 500, 'ثبت نوبت انجام نشد' );
		}

		$timezone         = $this->operational_timezone( (string) ( $resolved['timezone'] ?? '' ), $location_id, $clinic_id );
		$operational_date = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( $timezone ) )->format( 'Y-m-d' );

		return $this->success(
			[
				// The established representation, verbatim.
				'appointment' => $view,
				'reception'   => [
					'clinic_id'          => $clinic_id,
					'location_id'        => $location_id,
					'location_name'      => $resolved['location_name'],
					'operational_date'   => $operational_date,
					'on_operational_day' => $slot_date === $operational_date,
				],
			]
		);
	}

	/**
	 * Fail-closed empty slot day (0 eligible Locations ⇒ no reception scope).
	 *
	 * @return array<string, mixed>
	 */
	private function empty_slot_day( int $clinician_id ): array {
		return [
			'clinic_id'        => null,
			'location_id'      => null,
			'location_name'    => null,
			'timezone'         => null,
			'clinician_id'     => $clinician_id,
			'clinician_name'   => null,
			'date'             => null,
			'jalali'           => null,
			'operational_date' => null,
			'horizon_end'      => null,
			'days'             => [],
			'slots'            => [],
		];
	}

	/**
	 * Operational timezone of the trusted Location. Mirrors the established
	 * operational-day contract: an unresolved/invalid persisted timezone falls
	 * back to the UTC frame and is recorded — never silently presented as the
	 * Location truth.
	 */
	private function operational_timezone( string $timezone, int $location_id, int $clinic_id ): string {
		$name = trim( $timezone );
		$zone = null;
		if ( '' !== $name ) {
			try {
				$zone = new \DateTimeZone( $name );
			} catch ( \Throwable $e ) {
				unset( $e );
				$zone = null;
			}
		}
		if ( null !== $zone ) {
			return $name;
		}

		App::op()->warning(
			'reception.location_timezone_unresolvable',
			[
				'location_id' => $location_id,
				'clinic_id'   => $clinic_id,
			]
		);

		return 'UTC';
	}

	/**
	 * Clinic booking horizon (existing booking.max_future_days policy), bounded
	 * for the reception day-option payload. Fail closed to the operational day
	 * only when the Clinic policy cannot be read.
	 */
	private function booking_horizon_days( int $clinic_id ): int {
		try {
			$days = (int) App::settingsFactory()->forClinic( $clinic_id )->get( 'booking.max_future_days', 60 );
		} catch ( \Throwable $e ) {
			unset( $e );

			return 0;
		}

		return max( 0, min( $days, self::DAY_OPTION_LIMIT - 1 ) );
	}

	/**
	 * Strict Y-m-d validation of a client date SELECTOR: a calendar-impossible
	 * value (2026-13-45) is rejected instead of being rolled over into
	 * authority.
	 */
	private function valid_local_date( string $raw ): ?string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, new \DateTimeZone( 'UTC' ) );
		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $raw ) {
			return null;
		}

		return $raw;
	}

	/**
	 * Pure calendar arithmetic on an already Location-local Y-m-d label.
	 */
	private function plus_days( string $date, int $days ): string {
		$base = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
		if ( false === $base ) {
			return $date;
		}

		return $base->modify( '+' . max( 0, $days ) . ' day' )->format( 'Y-m-d' );
	}

	/**
	 * Bounded selectable-day options for the Persian date selector: the
	 * operational day plus the Clinic booking horizon, each with the existing
	 * Jalali presentation (server-side only — no client calendar conversion).
	 *
	 * @return list<array{date: string, jalali: string}>
	 */
	private function selectable_days( string $from, int $horizon_days ): array {
		$cursor = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from, new \DateTimeZone( 'UTC' ) );
		if ( false === $cursor ) {
			return [];
		}
		$limit = max( 0, min( $horizon_days, self::DAY_OPTION_LIMIT - 1 ) );
		$days  = [];
		for ( $offset = 0; $offset <= $limit; $offset++ ) {
			$date   = $cursor->modify( '+' . $offset . ' day' )->format( 'Y-m-d' );
			$days[] = [
				'date'   => $date,
				'jalali' => Jalali::formatYmd( $date ),
			];
		}

		return $days;
	}

	/**
	 * Doctors eligible for the trusted Clinic + trusted operational Location:
	 * ACTIVE professional identity + ACTIVE durable Clinic participation
	 * (membership of the bound user in THIS Clinic; a location-scoped
	 * membership must include this Location) + durable assignment to this
	 * ACTIVE Location of this Clinic. clinicians.clinic_id (home Clinic) is
	 * deliberately never read. One bounded query; optional id narrows it to
	 * a single selector check.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	private function eligible_clinicians( int $clinic_id, int $location_id, ?int $clinician_id ): array {
		$db     = App::db();
		$sql    = 'SELECT c.id, c.full_name FROM ' . $db->table( 'cpms_clinicians' ) . ' c' .
			' INNER JOIN ' . $db->table( 'cpms_clinician_locations' ) . ' cl ON cl.clinician_id = c.id AND cl.location_id = %d' .
			' INNER JOIN ' . $db->table( 'cpms_locations' ) . ' l ON l.id = cl.location_id AND l.clinic_id = %d AND l.is_active = 1' .
			' INNER JOIN ' . $db->table( 'cpms_clinic_memberships' ) . " m ON m.wp_user_id = c.wp_user_id AND m.clinic_id = %d AND m.status = 'active'" .
			' WHERE c.is_active = 1 AND c.wp_user_id > 0' .
			" AND ( m.scope_mode = 'clinic' OR EXISTS ( SELECT 1 FROM " . $db->table( 'cpms_membership_locations' ) . ' ml WHERE ml.membership_id = m.id AND ml.location_id = cl.location_id ) )';
		$params = [ $location_id, $clinic_id, $clinic_id ];
		if ( null !== $clinician_id ) {
			$sql     .= ' AND c.id = %d';
			$params[] = $clinician_id;
		}
		$sql     .= ' ORDER BY c.full_name ASC, c.id ASC LIMIT %d';
		$params[] = self::CLINICIAN_OPTION_LIMIT;

		$rows = $db->fetchAll( $sql, $params );
		$out  = [];
		foreach ( ( is_array( $rows ) ? $rows : [] ) as $row ) {
			$out[] = [
				'id'   => (int) $row['id'],
				'name' => (string) $row['full_name'],
			];
		}

		return $out;
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
	 * The resolved row also carries the persisted IANA `timezone` of the trusted
	 * Location: it is the operational authority for the reception appointment
	 * surface (Phase 11 Slice 5). Additive only — every existing caller keeps
	 * reading `clinic_id` / `location_id` / `location_name` unchanged.
	 *
	 * @return array{clinic_id: int, location_id: int|null, location_name: string|null, timezone: string|null}|WP_Error
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
				'timezone'      => null,
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
				'timezone'      => (string) ( $by_id[ $explicit ]['timezone'] ?? '' ),
			];
		}

		if ( 1 === count( $by_id ) ) {
			$only_id = (int) ( array_keys( $by_id )[0] ?? 0 );
			return [
				'clinic_id'     => $clinic_id,
				'location_id'   => $only_id,
				'location_name' => (string) $by_id[ $only_id ]['name'],
				'timezone'      => (string) ( $by_id[ $only_id ]['timezone'] ?? '' ),
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
