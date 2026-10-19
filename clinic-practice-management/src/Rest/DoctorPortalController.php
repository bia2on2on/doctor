<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Location\DoctorPortalLocationEligibility;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Machine\VisitMachine;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Doctor Portal independent shell — context endpoints (read-only Today+Live Queue).
 */
final class DoctorPortalController extends RestBase {
	/**
	 * Established portal bounded-list result limit (same convention as the
	 * delivered Staff/Finance Portal boards: fetch limit+1, return at most
	 * limit rows and report `has_more`). Deliberately NOT the legacy
	 * `past_visits` 50.
	 */
	private const HISTORY_RESULT_LIMIT = 100;

	public function __construct( private readonly MembershipRepository $memberships ) {
	}

	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/doctor/portal/context',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->context( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_doctor( $r ),
				],
			]
		);

		register_rest_route(
			self::NS,
			'/doctor/portal/locations',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->locations( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_doctor( $r ),
					'args'                => [
						'clinic_id' => [
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/doctor/portal/clinics',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->clinics( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_doctor( $r ),
				],
			]
		);

		// Doctor Portal queue actions use this Portal boundary too, rather than
		// the legacy shared /visits/{id}/... surface. That makes explicit Clinic
		// and Location selection, clinician ownership, and scoped capability
		// checks unavoidable for daily-workflow mutations.
		foreach ( [ 'call', 'recall', 'start', 'skip' ] as $event ) {
			$cap = 'start' === $event ? RolesAndCapabilities::CONSULT_START : RolesAndCapabilities::QUEUE_CALL;
			register_rest_route(
				self::NS,
				'/doctor/portal/visits/(?P<id>\\d+)/' . $event,
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => fn( WP_REST_Request $r ) => $this->workspace_doctor_action( $r, $event ),
						'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, $cap ),
						'args'                => [
							'reason' => [ 'required' => false, 'type' => 'string' ],
							'room'   => [ 'required' => false, 'type' => 'string' ],
						],
					],
				]
			);
		}

		// Phase 10 Slice 3 — Visit Workspace (portal-specific authority boundary).
		// These adapter routes add the Doctor Portal guard in front of the
		// established shared E7/E8 clinical behavior (which stays untouched).
		register_rest_route(
			self::NS,
			'/doctor/portal/visits/(?P<id>\d+)/record',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_record( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::MEDICAL_READ ),
				],
			]
		);

		register_rest_route(
			self::NS,
			'/doctor/portal/visits/(?P<id>\d+)/notes',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_add_note( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::NOTE_CREATE ),
					'args'                => [
						'category'      => [
							'required' => true,
							'type'     => 'string',
						],
						'visibility'    => [
							'required' => false,
							'type'     => 'string',
							'default'  => 'patient_visible',
						],
						'content_text'  => [
							'required' => true,
							'type'     => 'string',
						],
						'change_reason' => [
							'required' => false,
							'type'     => 'string',
						],
					],
				],
			]
		);

		// Phase 10 Rx write — Doctor Portal prescription boundary. Same adapter
		// architecture as the Visit Workspace: portal-specific guard in front
		// of the established shared E10/E11 behavior (which stays untouched).
		register_rest_route(
			self::NS,
			'/doctor/portal/visits/(?P<id>\d+)/prescriptions',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_create_prescription( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::RX_CREATE ),
					'args'                => [
						'items'              => [
							'required' => true,
							'type'     => 'array',
							'items'    => [ 'type' => 'object' ],
						],
						'is_patient_visible' => [
							'required' => false,
							'type'     => 'boolean',
							'default'  => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/doctor/portal/visits/(?P<id>\d+)/prescriptions/(?P<prescription_id>\d+)/print',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_print_prescription( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::RX_READ ),
					'args'                => $this->workspace_inert_client_args(),
				],
			]
		);

		// Phase 13 Slice 2 — bounded finalized structured-prescription history
		// for the CURRENT trusted Location. Read-only projection whose only
		// purpose is to let the doctor re-invoke the delivered Slice 1 print
		// route, which independently re-validates authority and eligibility.
		register_rest_route(
			self::NS,
			'/doctor/portal/prescriptions/history',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->prescription_history( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::RX_READ ),
					'args'                => $this->workspace_inert_client_args(),
				],
			]
		);

		register_rest_route(
			self::NS,
			'/doctor/portal/prescriptions/(?P<id>\d+)/finalize',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_finalize_prescription( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::RX_CREATE ),
				],
			]
		);

		// Phase 10 — Recommendation + Follow-Up authoring inside the Visit
		// Workspace. Same adapter architecture as the notes/Rx boundaries:
		// portal-specific guard in front of the established shared E12/E13
		// behavior (which stays untouched).
		register_rest_route(
			self::NS,
			'/doctor/portal/visits/(?P<id>\d+)/recommendations',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_add_recommendations( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::REC_CREATE ),
					'args'                => [
						'items' => [
							'required' => true,
							'type'     => 'array',
							'items'    => [ 'type' => 'object' ],
						],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/doctor/portal/visits/(?P<id>\d+)/follow-ups',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_add_follow_up( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::REC_CREATE ),
					'args'                => [
						'is_needed'      => [
							'required' => false,
							'type'     => 'boolean',
							'default'  => true,
						],
						'suggested_date' => [
							'required' => false,
							'type'     => 'string',
						],
						'interval_days'  => [
							'required' => false,
							'type'     => 'integer',
						],
						'reason'         => [
							'required' => false,
							'type'     => 'string',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/doctor/portal/visits/(?P<id>\d+)/complete',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_complete_consultation( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::CONSULT_COMPLETE ),
				],
			]
		);

		// Phase 10 Medical Files — Visit Workspace file boundary. Same adapter
		// architecture as the rest of the Visit Workspace: portal-specific guard
		// in front of the established shared E16/E17 file behavior (which stays
		// untouched). The route carries the Visit selector; the body carries only
		// the file + category + visibility — never authority.
		register_rest_route(
			self::NS,
			'/doctor/portal/visits/(?P<id>\d+)/files',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_upload_visit_file( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::FILE_UPLOAD ),
					'args'                => [
						'category'   => [
							'required' => false,
							'type'     => 'string',
							'default'  => 'other',
						],
						'visibility' => [
							'required' => false,
							'type'     => 'string',
							'default'  => 'patient_visible',
						],
					] + $this->workspace_inert_client_args(),
				],
			]
		);
		register_rest_route(
			self::NS,
			'/doctor/portal/visits/(?P<id>\d+)/files/(?P<file_id>\d+)/stream',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_stream_visit_file( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::FILE_READ ),
					'args'                => $this->workspace_inert_client_args(),
				],
			]
		);
		// Visit-scoped handwriting: every document/page selector is rebound to the Visit.
		$hw_base = '/doctor/portal/visits/(?P<id>\\d+)/handwriting';
		register_rest_route(
			self::NS,
			$hw_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_handwriting( $r, 'list' ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::MEDICAL_READ ),
					'args'                => $this->workspace_inert_client_args(),
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_handwriting( $r, 'create' ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::NOTE_CREATE ),
					'args'                => $this->workspace_inert_client_args(),
				],
			]
		);
		register_rest_route(
			self::NS,
			$hw_base . '/documents/(?P<document_id>\\d+)/pages',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => fn( WP_REST_Request $r ) => $this->workspace_handwriting( $r, 'add' ),
				'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::NOTE_CREATE ),
				'args'                => $this->workspace_inert_client_args(),
			]
		);
		register_rest_route(
			self::NS,
			$hw_base . '/pages/(?P<page_id>\\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_handwriting( $r, 'page' ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::MEDICAL_READ ),
					'args'                => $this->workspace_inert_client_args(),
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->workspace_handwriting( $r, 'save' ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->perm_workspace( $r, RolesAndCapabilities::NOTE_CREATE ),
					'args'                => $this->workspace_inert_client_args(),
				],
			]
		);
	}

	/**
	 * Preserve bounded operational-Location denial metadata when the established
	 * trusted-scope binder rejects an explicit Location before our Visit guard.
	 * This cannot turn a denial into authorization and never exposes eligible IDs.
	 *
	 * @param mixed $response Response from the existing binder.
	 * @param mixed $handler Matched REST handler.
	 * @param mixed $request Current REST request.
	 * @return mixed
	 */
	public function handwriting_location_denial( mixed $response, mixed $handler, mixed $request ): mixed {
		unset( $handler );
		if ( ! $response instanceof WP_Error || ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( 'CLINIC_SCOPE_UNAVAILABLE' !== $response->get_error_code() || ! preg_match( '#^/clinic/v1/doctor/portal/visits/([0-9]+)/handwriting(?:/|$)#', $request->get_route(), $matches ) ) {
			return $response;
		}
		// The scope-reason pass-through must not suppress this audit: the
		// handwriting denial is recorded exactly once, here, for the request.
		$clinic   = $request->get_header( 'X-CPMS-Clinic-Id' );
		$location = $request->get_header( 'X-CPMS-Location-Id' );
		if ( ! is_string( $clinic ) || ! ctype_digit( $clinic ) || ! is_string( $location ) || ! ctype_digit( $location ) ) {
			return $response;
		}
		$clinic_id   = (int) $clinic;
		$location_id = (int) $location;
		$user_id     = (int) get_current_user_id();
		if ( $clinic_id < 1 || $location_id < 1 || $user_id < 1 ) {
			return $response;
		}
		// Audit every trusted-binder denial on this live handwriting route.
		// The binder and the selector projection answer different questions
		// (Visit-bound continuity vs. selectable Locations), so re-deriving
		// "eligibility" here could wrongly suppress the denial audit.
		$this->workspace_handwriting_denial( (int) $matches[1], 'location' );
		return new WP_Error( 'CLINIC_SCOPE_UNAVAILABLE', $response->get_error_message(), [ 'status' => 403, 'reason' => 'location' ] );
	}

	/** Rebind every selector to the authorized Visit before calling the shared engine service. */
	private function workspace_handwriting( WP_REST_Request $r, string $operation ): WP_REST_Response|WP_Error {
		$visit_id = (int) $r['id'];
		// Handwriting is a live clinical workspace, never a historical
		// projection: even the exact active Visit cannot keep it at an inactive
		// Location. The bounded reason is preserved for the portal envelope.
		$scope        = App::scope();
		$scope_loc    = (int) ( $scope->locationId ?? 0 );
		$scope_clinic = (int) $scope->clinicId;
		$active_loc   = $scope_loc > 0
			? App::db()->fetchValue(
				'SELECT id FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE id = %d AND clinic_id = %d AND is_active = 1 LIMIT 1',
				[ $scope_loc, $scope_clinic ]
			)
			: null;
		if ( null === $active_loc ) {
			$this->workspace_handwriting_denial( $visit_id, 'location', $r );
			return new WP_Error( 'CLINIC_SCOPE_UNAVAILABLE', 'Trusted clinic context is not available.', [ 'status' => 403, 'reason' => 'location' ] );
		}
		$guard = $this->workspace_authorize_visit( $visit_id );
		if ( $guard instanceof WP_Error ) {
			// A guard denial whose bounded reason is the operational Location is a
			// Location-scope denial: it must be audited as such, not as the route
			// operation, so the bounded reason and the audit agree.
			$guard_reason = (string) ( $guard->get_error_data()['reason'] ?? '' );
			$denial_kind  = 'CLINIC_SCOPE_UNAVAILABLE' === $guard->get_error_code() && 'location' === $guard_reason ? 'location' : $operation;
			$this->workspace_handwriting_denial( $visit_id, $denial_kind, $r );
			return $guard;
		}

		$document_id = (int) ( $r['document_id'] ?? 0 );
		$page_id     = (int) ( $r['page_id'] ?? 0 );
		if ( 'add' === $operation || 'page' === $operation || 'save' === $operation ) {
			$db = App::db();
			if ( 'add' === $operation ) {
				$document = $db->fetchRow(
					'SELECT id, visit_id, clinic_id FROM ' . $db->table( 'cpms_handwriting_documents' ) . ' WHERE id = %d LIMIT 1',
					[ $document_id ]
				);
			} else {
				$document = $db->fetchRow(
					'SELECT d.id, d.visit_id, d.clinic_id FROM ' . $db->table( 'cpms_handwriting_pages' ) . ' p INNER JOIN ' . $db->table( 'cpms_handwriting_documents' ) . ' d ON d.id = p.document_id WHERE p.id = %d LIMIT 1',
					[ $page_id ]
				);
			}
			// Even a valid foreign document/page is indistinguishable from a missing one.
			if ( null === $document || (int) $document['visit_id'] !== $visit_id || (int) $document['clinic_id'] !== (int) App::scope()->clinicId ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- existing scope contract
				$this->workspace_handwriting_denial( $visit_id, $operation, $r );
				return new WP_Error( 'CLINIC_NOT_FOUND', 'مراجعه یافت نشد', [ 'status' => 404 ] );
			}
		}

		$actor   = (int) wp_get_current_user()->ID;
		$service = App::handwritingService();
		try {
			switch ( $operation ) {
				case 'list':
					$list = $service->listDocuments( $actor, $visit_id ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- next statement assigns an array key, not a peer variable
					$list['autosave_sec'] = max( 2, (int) App::settingsFactory()->forClinic( (int) App::scope()->clinicId )->get( 'hw.autosave_sec', 5 ) ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- array key and established scope contract

					$scope        = App::scope();
					$eligible     = $this->eligible_locations_for_clinic( (int) $scope->clinicId, $actor ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- trusted scope property
					$location_id  = $scope->locationId ?? ( 1 === count( $eligible ) ? $eligible[0]['id'] : null ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- trusted scope property
					$clinician_id = $this->memberships->active_clinician_id_for_wp_user( $actor );

					$list['paper_context'] = null !== $location_id && null !== $clinician_id
						? \ClinicCore\Application\Handwriting\PrescriptionPaperContext::for_visit( App::db(), $visit_id, (int) $scope->clinicId, (int) $location_id, $clinician_id ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- trusted scope property
						: null;
					return $this->success( $list );
				case 'create':
					return $this->success( $service->createDocument( $actor, $visit_id, null, [] ), 201 );
				case 'add':
					$body = array_intersect_key( $this->workspace_body( $r ), array_flip( [ 'width', 'height', 'background_template' ] ) );
					return $this->success( $service->addPage( $actor, $document_id, $body ), 201 );
				case 'page':
					return $this->success( $service->getPage( $actor, $page_id ) );
				case 'save':
					$key = $this->idempotencyKey( $r );
					if ( null === $key ) {
						return $this->error( 'CLINIC_VALIDATION', 400, 'هدر Idempotency-Key (UUID) برای ذخیره دست‌خط الزامی است' );
					}
					$body   = array_intersect_key( $this->workspace_body( $r ), array_flip( [ 'client_revision', 'stroke_data', 'width', 'height', 'background_template', 'saved_by', 'conflict_reason' ] ) );
					$result = $service->savePage( $actor, $page_id, $body, $key );
					return $this->success( $result['response'], $result['status'] );
			}
		} catch ( \ClinicCore\Application\Handwriting\HandwritingException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- existing exception contract
		} catch ( \Throwable $e ) {
			error_log( '[CPMS][DoctorPortalController] handwriting: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- existing controller convention
			return $this->error( 'CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید' );
		}
		return $this->error( 'CLINIC_NOT_FOUND', 404, 'مراجعه یافت نشد' );
	}

	private function workspace_handwriting_denial( int $visit_id, string $operation, ?WP_REST_Request $request = null ): void {
		if ( null !== $request ) {
			// One audit per denied HTTP request, whichever layer denied it.
			$request->set_param( '_cpms_hw_audited', true );
		}
		$user = wp_get_current_user();
		App::audit()->log( 'FORBIDDEN_ACCESS_ATTEMPT', [ 'wp_user_id' => (int) $user->ID, 'role' => RolesAndCapabilities::ROLE_DOCTOR ], 'handwriting', $visit_id, null, null, null, [ 'operation' => $operation ] );
	}

	/**
	 * Record the denial audit for a handwriting request that the shared scope
	 * binder rejected before the route callback ran. Runs on the final response
	 * so early WP_Error short-circuits are still covered, and is idempotent with
	 * the controller-level denial through the per-request marker.
	 *
	 * @param mixed $response Final REST response.
	 * @param mixed $server   REST server instance.
	 * @param mixed $request  Current REST request.
	 * @return mixed
	 */
	public function handwriting_post_dispatch( mixed $response, mixed $server, mixed $request ): mixed {
		unset( $server );
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( true === $request->get_param( '_cpms_hw_audited' ) ) {
			return $response;
		}
		$data = $response->get_data();
		$code = is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
		if ( 'CLINIC_SCOPE_UNAVAILABLE' !== $code || 403 !== (int) $response->get_status() ) {
			return $response;
		}
		if ( ! preg_match( '#^/clinic/v1/doctor/portal/visits/([0-9]+)/handwriting(?:/|$)#', (string) $request->get_route(), $matches ) ) {
			return $response;
		}
		$this->workspace_handwriting_denial( (int) $matches[1], 'location', $request );
		return $response;
	}


	private function perm_doctor( WP_REST_Request $r ): bool|WP_Error {
		$nonce = $this->requireNonce( $r );
		if ( $nonce instanceof WP_Error ) {
			return $nonce;
		}
		$cap = $this->requireCap( RolesAndCapabilities::QUEUE_READ );
		if ( $cap instanceof WP_Error ) {
			return $cap;
		}
		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) || (int) $user->ID <= 0 ) {
			return new WP_Error( 'CLINIC_UNAUTHORIZED', 'Unauthorized', [ 'status' => 401 ] );
		}
		$roles = (array) ( $user->roles ?? [] );
		if ( ! in_array( RolesAndCapabilities::ROLE_DOCTOR, $roles, true ) ) {
			return new WP_Error( 'CLINIC_PERMISSION_DENIED', 'Doctor role required', [ 'status' => 403 ] );
		}
		$db            = App::db(); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability
		$clinician_id = $db->fetchValue(
			'SELECT id FROM ' . $db->table( 'cpms_clinicians' ) . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
			[ (int) $user->ID ]
		);
		if ( null === $clinician_id || (int) $clinician_id <= 0 ) {
			return new WP_Error( 'CLINIC_PERMISSION_DENIED', 'Doctor not linked to active clinician', [ 'status' => 403 ] );
		}
		$active_clinics = $this->memberships->active_clinic_ids_for_user( (int) $user->ID );
		if ( [] === $active_clinics ) {
			return new WP_Error( 'CLINIC_SCOPE_UNAVAILABLE', 'No active clinic membership', [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Phase 10 Slice 3 — Visit Workspace permission (portal boundary).
	 *
	 * Nonce + capability + doctor role + server-derived active clinician
	 * identity. Visit ownership / trusted-Location matching is enforced in
	 * {@see self::workspace_authorize_visit()} — the visit_id/location_id
	 * selectors never establish authority by themselves.
	 */
	private function perm_workspace( WP_REST_Request $r, string $cap ): bool|WP_Error {
		$nonce = $this->requireNonce( $r );
		if ( $nonce instanceof WP_Error ) {
			return $nonce;
		}
		$perm = $this->requireCap( $cap );
		if ( $perm instanceof WP_Error ) {
			return $perm;
		}
		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) || (int) $user->ID <= 0 ) {
			return new WP_Error( 'CLINIC_UNAUTHORIZED', 'Unauthorized', [ 'status' => 401 ] );
		}
		$roles = (array) ( $user->roles ?? [] );
		if ( ! in_array( RolesAndCapabilities::ROLE_DOCTOR, $roles, true ) ) {
			return new WP_Error( 'CLINIC_PERMISSION_DENIED', 'Doctor role required', [ 'status' => 403 ] );
		}
		$db           = App::db();
		$clinician_id = $db->fetchValue(
			'SELECT id FROM ' . $db->table( 'cpms_clinicians' ) . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
			[ (int) $user->ID ]
		);
		if ( null === $clinician_id || (int) $clinician_id <= 0 ) {
			return new WP_Error( 'CLINIC_PERMISSION_DENIED', 'Doctor not linked to active clinician', [ 'status' => 403 ] );
		}
		$clinic_id = $this->explicit_request_id( $r, 'X-CPMS-Clinic-Id', 'clinic_id' );
		if ( null === $clinic_id ) {
			if ( $this->has_request_selector( $r, 'X-CPMS-Clinic-Id', 'clinic_id' ) ) {
				return $this->error(
					'CLINIC_VALIDATION_FAILED',
					422,
					'Invalid clinic id.',
					[ 'field' => 'clinic_id' ]
				);
			}
			return $this->error(
				'CLINIC_SCOPE_REQUIRED',
				400,
				'Clinic scope required',
				[ 'field' => 'clinic_id', 'reason' => 'clinic_required' ]
			);
		}
		// Establish the trusted Clinic boundary BEFORE capability evaluation: a
		// foreign/unavailable Clinic is the established CLINIC_SCOPE_UNAVAILABLE
		// envelope, never a capability oracle. No Location is validated here yet,
		// so an endpoint capability DENY still precedes Location validation.
		$clinic_scope = RestClinicContext::verify_doctor_portal_clinic( (int) $user->ID, $clinic_id );
		if ( $clinic_scope instanceof WP_Error ) {
			return $clinic_scope;
		}
		if ( ! App::authorization_service()->can( (int) $user->ID, $clinic_id, $cap ) ) {
			return $this->error( 'CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید' );
		}
		$location_id = $this->explicit_request_id( $r, 'X-CPMS-Location-Id', 'location_id' );
		if ( null === $location_id ) {
			if ( $this->has_request_selector( $r, 'X-CPMS-Location-Id', 'location_id' ) ) {
				return $this->error(
					'CLINIC_VALIDATION_FAILED',
					422,
					'Invalid location id.',
					[ 'field' => 'location_id' ]
				);
			}
			return $this->error(
				'CLINIC_SCOPE_REQUIRED',
				400,
				'Location scope required',
				[ 'field' => 'location_id', 'reason' => 'location_required' ]
			);
		}
		return RestClinicContext::bind_doctor_portal_scope_after_authorization(
			$r,
			(int) $user->ID,
			$clinic_id,
			$location_id
		);
	}

	/**
	 * Phase 10 Slice 3 — Visit Workspace record read (portal boundary).
	 *
	 * Reuses the established E7 clinical record AFTER the portal-specific
	 * guard; the shared E7 contract itself stays untouched.
	 */
	private function workspace_record( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$visit_id = (int) $r['id'];
		$guard    = $this->workspace_authorize_visit( $visit_id );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		return $this->workspace_wrap(
			fn() => App::clinicalService()->record( (int) wp_get_current_user()->ID, $visit_id )
		);
	}

	/**
	 * Phase 10 Slice 3 — Visit Workspace note creation (portal boundary).
	 *
	 * Reuses the established E8 note creation (visibility validation,
	 * versioning, auditing) AFTER the portal-specific guard; the shared E8
	 * contract itself stays untouched.
	 */
	private function workspace_add_note( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$visit_id = (int) $r['id'];
		$guard    = $this->workspace_authorize_visit( $visit_id );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		return $this->workspace_wrap(
			fn() => App::clinicalService()->addNote( (int) wp_get_current_user()->ID, $visit_id, $this->workspace_body( $r ) )
		);
	}

	/**
	 * Phase 10 Rx write — draft prescription creation (portal boundary).
	 *
	 * Reuses the established E10 create (structured item
	 * validation/enums/duration limits, drug_ref existence, established
	 * prescription number, draft lifecycle start, existing audit) AFTER the
	 * portal-specific Visit guard; the shared E10 contract stays untouched.
	 */
	private function workspace_create_prescription( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$visit_id = (int) $r['id'];
		$guard    = $this->workspace_authorize_visit( $visit_id );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		return $this->workspace_wrap(
			fn() => App::clinicalService()->createPrescription( (int) wp_get_current_user()->ID, $visit_id, $this->workspace_body( $r ) )
		);
	}

	/**
	 * Phase 10 — recommendation authoring (portal boundary).
	 *
	 * Reuses the established E12 behavior (established type set, text
	 * validation, per-item patient visibility, server-derived ownership,
	 * existing audit) AFTER the portal-specific Visit guard; the shared E12
	 * contract itself stays untouched.
	 */
	private function workspace_add_recommendations( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$visit_id = (int) $r['id'];
		$guard    = $this->workspace_authorize_visit( $visit_id );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		return $this->workspace_wrap(
			fn() => App::clinicalService()->addRecommendations( (int) wp_get_current_user()->ID, $visit_id, $this->workspace_body( $r ) )
		);
	}

	/**
	 * Phase 10 — follow-up authoring (portal boundary).
	 *
	 * Reuses the established E13 behavior (is_needed / suggested_date /
	 * interval_days / reason contract, existing audit, reminder processing
	 * left to the existing jobs path) AFTER the portal-specific Visit guard;
	 * the shared E13 contract itself stays untouched.
	 */
	private function workspace_add_follow_up( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$visit_id = (int) $r['id'];
		$guard    = $this->workspace_authorize_visit( $visit_id );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		return $this->workspace_wrap(
			fn() => App::clinicalService()->addFollowUp( (int) wp_get_current_user()->ID, $visit_id, $this->workspace_body( $r ) )
		);
	}

	/**
	 * Phase 10 — Visit Complete (portal boundary).
	 *
	 * Thin adapter: the portal Visit guard (server-derived clinician, active
	 * Clinic, trusted Location, Visit ownership) runs FIRST, then the
	 * established E14 completeConsultation is reused unchanged — its Chief
	 * Complaint policy (422), state machine (409 on repeat/wrong state),
	 * history and audit. No extra audit and no finance side effect here.
	 */
	private function workspace_doctor_action( WP_REST_Request $r, string $event ): WP_REST_Response|WP_Error {
		$visit_id = (int) $r['id'];
		$guard    = $this->workspace_authorize_visit( $visit_id );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$body = array_intersect_key( $this->workspace_body( $r ), array_flip( [ 'reason', 'room' ] ) );

		return $this->workspace_wrap(
			fn() => App::visitService()->transition( (int) wp_get_current_user()->ID, $visit_id, $event, $body )
		);
	}

	private function workspace_complete_consultation( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$visit_id = (int) $r['id'];
		$guard    = $this->workspace_authorize_visit( $visit_id );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		return $this->workspace_wrap(
			fn() => App::clinicalService()->completeConsultation( (int) wp_get_current_user()->ID, $visit_id )
		);
	}

	/**
	 * Phase 10 Rx write — draft finalize (portal boundary).
	 *
	 * The raw prescription_id is only a selector: the SERVER resolves its
	 * owning Visit and authorizes THAT Visit through the portal boundary
	 * before delegating to the established E11 transition (existing
	 * finalized_at, existing repeat-finalize 409 semantics, existing audit).
	 * The shared E11 contract stays untouched.
	 */
	private function workspace_finalize_prescription( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$rx_id = (int) $r['id'];
		$guard = $this->workspace_authorize_prescription( $rx_id );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		return $this->workspace_wrap(
			fn() => App::clinicalService()->finalizePrescription( (int) wp_get_current_user()->ID, $rx_id )
		);
	}

	/**
	 * Phase 13 Slice 1 — read-only structured prescription print projection.
	 * The Visit selector is authorized first; the prescription selector is then
	 * rebound to that exact persisted Visit in ClinicalService. The Location id
	 * comes only from the portal's trusted 0/1/N policy, never request data.
	 */
	private function workspace_print_prescription( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$visit_id            = (int) $r['id'];
		$prescription_id     = (int) $r['prescription_id'];
		$trusted_location_id = null;
		$guard               = $this->workspace_authorize_visit( $visit_id, $trusted_location_id );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		if ( null === $trusted_location_id || $trusted_location_id <= 0 ) {
			return $this->error( 'CLINIC_SCOPE_UNAVAILABLE', 403, 'Trusted clinic context is not available.', [ 'reason' => 'location' ] );
		}

		return $this->workspace_wrap(
			fn() => App::clinicalService()->finalized_prescription_for_portal_print(
				(int) wp_get_current_user()->ID,
				$visit_id,
				$prescription_id,
				$trusted_location_id
			)
		);
	}

	/**
	 * Phase 13 Slice 2 — bounded finalized structured-prescription history.
	 *
	 * Read-only. Authority is entirely server-derived (trusted Clinic +
	 * clinician<->WP-user mapping + CURRENT trusted Location under the strict
	 * 0/1/N policy). An inactive Location remains available for this read-only
	 * history projection only while the clinician has an active Visit there;
	 * every client-supplied identity argument is inert. The response carries only
	 * the privacy-minimal reprint row; listing a row
	 * never grants print authority — the existing Slice 1 print route
	 * re-validates independently.
	 */
	private function prescription_history( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		unset( $r );
		$clinic_id    = 0;
		$clinician_id = 0;
		$location_id  = 0;
		$context      = $this->workspace_trusted_context( $clinic_id, $clinician_id, $location_id, null, true );
		if ( $context instanceof WP_Error ) {
			return $context;
		}

		return $this->workspace_wrap(
			fn() => App::clinicalService()->finalized_prescription_history_for_portal(
				(int) wp_get_current_user()->ID,
				$clinic_id,
				$clinician_id,
				$location_id,
				self::HISTORY_RESULT_LIMIT,
				// The trusted-context guard above already established continuity:
				// this clinician has active treatment at the (possibly inactive)
				// Location, so read-only history stays available there.
				true
			)
		);
	}

	/**
	 * Client authority keys are deliberately inert at the portal file boundary:
	 * the SERVER derives patient/clinician/clinic/Location from the persisted
	 * authorized Visit (route selector + trusted selector headers only). Each
	 * key is dropped at arg sanitization — before the shared scope binder reads
	 * any selector — so a forged body key can never create authority, retarget
	 * a row, or block/alter a legitimate request (accepted Phase 10 contract:
	 * forged patient_id/clinician_id/clinic_id/location_id/visit_id are inert).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function workspace_inert_client_args(): array {
		return [
			'patient_id'   => [ 'required' => false, 'sanitize_callback' => static fn() => null ],
			'clinician_id' => [ 'required' => false, 'sanitize_callback' => static fn() => null ],
			'clinic_id'    => [ 'required' => false, 'sanitize_callback' => static fn() => null ],
			'location_id'  => [ 'required' => false, 'sanitize_callback' => static fn() => null ],
			'visit_id'     => [ 'required' => false, 'sanitize_callback' => static fn() => null ],
		];
	}

	/**
	 * Phase 10 Medical Files — Visit Workspace upload (portal boundary).
	 *
	 * The client may select ONLY: the Visit via the route, the uploaded file,
	 * category and visibility. The SERVER derives patient/clinician/clinic from
	 * the persisted authorized Visit and delegates storage/validation/audit to
	 * the established MedicalFileService (shared E16 — reused, never duplicated).
	 * Client patient_id/clinician_id/clinic_id/location_id/visit_id keys are
	 * never read — a forged key cannot create authority or re-target the upload.
	 */
	private function workspace_upload_visit_file( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$visit_id = (int) $r['id'];
		$guard    = $this->workspace_authorize_visit( $visit_id );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		$limited = $this->rateLimit( $r, 'files:upload:' . (int) wp_get_current_user()->ID, 10, 3600 );
		if ( $limited instanceof WP_Error ) {
			return $limited;
		}
		// Server-derived patient — read only from the persisted authorized Visit.
		$db    = App::db();
		$visit = $db->fetchRow(
			'SELECT id, patient_id FROM ' . $db->table( 'cpms_visits' ) . ' WHERE id = %d LIMIT 1',
			[ $visit_id ]
		);
		if ( null === $visit ) {
			return new WP_Error( 'CLINIC_NOT_FOUND', 'مراجعه یافت نشد', [ 'status' => 404 ] );
		}
		$actor      = (int) wp_get_current_user()->ID;
		$patient_id = (int) $visit['patient_id']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- established snake_case row/domain name
		$category   = (string) ( $r['category'] ?? 'other' );
		$visibility = (string) ( $r['visibility'] ?? 'patient_visible' );
		$file       = $this->workspace_uploaded_file( $r );

		return $this->workspace_wrap(
			fn() => App::medicalFileService()->upload( $actor, $file, $patient_id, $visit_id, $category, $visibility ),
			201
		);
	}

	/**
	 * Phase 10 Medical Files — Visit Workspace secure open/download (portal
	 * boundary). The file_id is a selector ONLY: the persisted file -> Visit
	 * binding is checked against the authorized current Visit BEFORE any disk
	 * read, then the established protected MedicalFileService::stream (shared
	 * E17) does resource authorization, visibility and audit. A file of another
	 * Visit is never downloadable just because the patient matches; a null-Visit
	 * (patient-level) file never silently gains current-Visit authority. Every
	 * file-dimension denial shares one non-enumerating fingerprint.
	 */
	private function workspace_stream_visit_file( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$visit_id = (int) $r['id'];
		$guard    = $this->workspace_authorize_visit( $visit_id );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		$file_id = (int) $r['file_id'];
		$db      = App::db();
		$file    = $db->fetchRow(
			'SELECT id, visit_id FROM ' . $db->table( 'cpms_medical_attachments' ) . ' WHERE id = %d AND deleted_at IS NULL LIMIT 1',
			[ $file_id ]
		);
		if ( null === $file || null === $file['visit_id'] || (int) $file['visit_id'] !== $visit_id ) {
			return new WP_Error( 'CLINIC_NOT_FOUND', 'فایل یافت نشد', [ 'status' => 404 ] );
		}
		try {
			$payload = App::medicalFileService()->stream( (int) wp_get_current_user()->ID, $file_id );
		} catch ( \ClinicCore\Application\Clinical\ClinicalException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
		} catch ( \Throwable $e ) {
			unset( $e );
			error_log( '[CPMS][DoctorPortalController] unexpected stream: file ' . $file_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- established controller convention

			return $this->error( 'CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید' );
		}
		// Same protected delivery contract as the established shared stream.
		$response = new WP_REST_Response( $payload['content'], 200 );
		$response->header( 'Content-Type', $payload['mime_type'] );
		$response->header( 'Content-Length', (string) $payload['size'] );
		$response->header( 'Content-Disposition', 'attachment; filename="' . rawurlencode( $payload['original_filename'] ) . '"' );
		$response->header( 'Cache-Control', 'private, max-age=0, no-cache' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}

	/**
	 * Multipart upload payload ($_FILES shape). The portal file boundary accepts
	 * only this one file field plus category/visibility selectors.
	 *
	 * @return array{name?: string, tmp_name?: string, size?: int, error?: int}
	 */
	private function workspace_uploaded_file( WP_REST_Request $r ): array {
		$files = $r->get_file_params();
		if ( ! is_array( $files ) || ! isset( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return [];
		}

		return $files['file'];
	}

	/**
	 * Establish authorized Clinic/clinician/Location scope before resolving the
	 * prescription selector. The query is limited to that tenant and clinician;
	 * inactive-Location finalization additionally requires an active Visit.
	 * Unknown and out-of-scope prescriptions collapse to the same 404.
	 */
	private function workspace_authorize_prescription( int $prescription_id ): bool|WP_Error {
		$clinic_id    = 0;
		$clinician_id = 0;
		$location_id  = 0;
		$context      = $this->workspace_trusted_context( $clinic_id, $clinician_id, $location_id, null, true );
		if ( $context instanceof WP_Error ) {
			return $context;
		}

		$visit_statuses = VisitMachine::ACTIVE_STATUSES;
		$placeholders   = implode( ',', array_fill( 0, count( $visit_statuses ), '%s' ) );
		$db             = App::db();
		$rx             = $db->fetchRow(
			'SELECT p.id, p.visit_id FROM ' . $db->table( 'cpms_prescriptions' ) . ' p' .
			' INNER JOIN ' . $db->table( 'cpms_visits' ) . ' v ON v.id = p.visit_id' .
			' INNER JOIN ' . $db->table( 'cpms_locations' ) . ' l ON l.id = v.location_id AND l.clinic_id = v.clinic_id' .
			' WHERE p.id = %d AND v.clinic_id = %d AND v.location_id = %d AND v.clinician_id = %d' .
			' AND (l.is_active = 1 OR (v.active = 1 AND v.status IN (' . $placeholders . '))) LIMIT 1',
			array_merge( [ $prescription_id, $clinic_id, $location_id, $clinician_id ], $visit_statuses )
		);
		if ( null === $rx ) {
			return new WP_Error( 'CLINIC_NOT_FOUND', 'نسخه یافت نشد', [ 'status' => 404 ] );
		}

		return $this->workspace_authorize_visit( (int) $rx['visit_id'] );
	}

	/**
	 * Server-derived Doctor Portal authority for the history projection (which
	 * has no Visit selector): trusted Clinic, server-derived clinician identity,
	 * and an explicitly selected eligible Location. An inactive Location is
	 * allowed only while this clinician has an active Visit there; an appointment
	 * alone cannot reopen unrelated historical workspace access.
	 *
	 * @param int $clinic_id    Out: trusted Clinic id.
	 * @param int $clinician_id Out: server-derived clinician id.
	 * @param int $location_id  Out: CURRENT trusted Location id.
	 */
	private function workspace_trusted_context(
		int &$clinic_id,
		int &$clinician_id,
		int &$location_id,
		?int $continuity_visit_id = null,
		bool $active_visit_continuity_only = false
	): bool|WP_Error {
		$user_id = (int) ( wp_get_current_user()->ID ?? 0 );

		// 1) Trusted Clinic scope — bound by RestClinicContext from selector headers.
		try {
			$scope = App::scope();
		} catch ( \Throwable $e ) {
			unset( $e );
			return new WP_Error( 'CLINIC_SCOPE_REQUIRED', 'Clinic scope required', [ 'status' => 400 ] );
		}
		if ( $scope->source !== ClinicScope::SOURCE_EXPLICIT ) {
			return new WP_Error(
				'CLINIC_SCOPE_REQUIRED',
				'Explicit Clinic scope required',
				[ 'status' => 400, 'field' => 'clinic_id', 'reason' => 'clinic_required' ]
			);
		}
		$clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract

		// 2) Server-derived clinician identity — never client-provided.
		try {
			$derived = $this->memberships->active_clinician_id_for_wp_user( $user_id );
		} catch ( \Throwable $e ) {
			unset( $e );
			$derived = null; // Ambiguous identities fail closed.
		}
		if ( null === $derived || ! $this->memberships->clinician_participates_in( $derived, $clinic_id ) ) {
			return new WP_Error( 'CLINIC_PERMISSION_DENIED', 'Doctor identity is not active in the trusted clinic', [ 'status' => 403 ] );
		}
		$clinician_id = (int) $derived;

		// 3) Explicit operational Location — no sole/primary inference. Inactive
		// Locations are eligible only for existing continuity, never new demand.
		$eligible_locations = $this->eligible_locations_for_clinic( $clinic_id, $user_id );
		$eligible_by_id     = [];
		foreach ( $eligible_locations as $eligible_location ) {
			$eligible_by_id[ (int) $eligible_location['id'] ] = $eligible_location;
		}
		if ( [] === $eligible_by_id ) {
			return new WP_Error( 'CLINIC_SCOPE_UNAVAILABLE', 'Trusted clinic context is not available.', [ 'status' => 403, 'reason' => 'location' ] );
		}
		$selected = $scope->locationId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
		if ( null === $selected ) {
			return new WP_Error(
				'CLINIC_SCOPE_REQUIRED',
				'Location scope required: explicit Location selection',
				[
					'status' => 400,
					'field'  => 'location_id',
					'reason' => 'location_required',
				]
			);
		}
		$selected = (int) $selected;
		if ( ! isset( $eligible_by_id[ $selected ] ) ) {
			return new WP_Error( 'CLINIC_SCOPE_UNAVAILABLE', 'Trusted clinic context is not available.', [ 'status' => 403, 'reason' => 'location' ] );
		}

		$location = $eligible_by_id[ $selected ];
		if ( ! $location['is_active'] ) {
			// Inactive Locations stay available only through continuity:
			//  - the read-only prescription-history projection requires active
			//    treatment by this clinician at this Location;
			//  - a Visit Workspace request requires the EXACT selected Visit to
			//    still be active for this clinician there.
			$continuity   = new DoctorPortalLocationEligibility( App::db(), $this->memberships );
			$can_continue = null !== $continuity_visit_id
				? $continuity->has_active_visit( $clinic_id, $user_id, $selected, $continuity_visit_id )
				: ( $active_visit_continuity_only && $continuity->has_active_visit_at_location( $clinic_id, $user_id, $selected ) );
			if ( ! $can_continue ) {
				return new WP_Error( 'CLINIC_SCOPE_UNAVAILABLE', 'Trusted clinic context is not available.', [ 'status' => 403, 'reason' => 'location' ] );
			}
		}
		$location_id = $selected;

		return true;
	}

	/**
	 * Portal-specific Visit Workspace authority — the narrow Doctor Portal
	 * boundary in front of the shared E7/E8 behavior.
	 *
	 * Authority = authenticated WP user + server-derived active clinician
	 * identity + explicitly selected trusted Clinic + explicitly selected,
	 * eligible operational Location (no sole/primary inference; foreign or
	 * unassigned selectors fail closed). An inactive Location is accepted only
	 * when the exact selected Visit is still active for this clinician.
	 *
	 * Raw visit_id/location_id remain selectors only; every foreign Visit
	 * selector fails non-enumerating with the established repository
	 * convention (404 CLINIC_NOT_FOUND).
	 */
	private function workspace_authorize_visit( int $visit_id, ?int &$trusted_location_id = null ): bool|WP_Error {
		$clinic_id    = 0;
		$clinician_id = 0;
		$location_id  = 0;
		$context      = $this->workspace_trusted_context( $clinic_id, $clinician_id, $location_id, $visit_id );
		if ( $context instanceof WP_Error ) {
			return $context;
		}

		// 4) Visit ownership — every mismatch is the same non-enumerating 404.
		$db    = App::db();
		$visit = $db->fetchRow(
			'SELECT id, clinic_id, location_id, clinician_id FROM ' . $db->table( 'cpms_visits' ) . ' WHERE id = %d LIMIT 1',
			[ $visit_id ]
		);
		if (
			null === $visit
			|| (int) $visit['clinic_id'] !== $clinic_id
			|| (int) $visit['location_id'] !== $location_id
			|| (int) $visit['clinician_id'] !== $clinician_id
		) {
			return new WP_Error( 'CLINIC_NOT_FOUND', 'مراجعه یافت نشد', [ 'status' => 404 ] );
		}

		$trusted_location_id = $location_id;

		return true;
	}

	/**
	 * Envelope for the reused ClinicalService/MedicalFileService calls (same
	 * convention as the shared clinical controller — bounded error codes, no
	 * raw leakage).
	 *
	 * @template T
	 *
	 * @param callable(): T $callback
	 */
	private function workspace_wrap( callable $callback, int $status = 200 ): WP_REST_Response|WP_Error {
		try {
			return $this->success( $callback(), $status );
		} catch ( \ClinicCore\Application\Clinical\ClinicalException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
		} catch ( \ClinicCore\Domain\Visits\VisitException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
		} catch ( \Throwable $e ) {
			error_log( '[CPMS][DoctorPortalController] unexpected: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- established controller convention
			return $this->error( 'CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function workspace_body( WP_REST_Request $r ): array {
		$params = $r->get_json_params();
		if ( is_array( $params ) ) {
			return $params;
		}
		$params = $r->get_params();

		return is_array( $params ) ? $params : [];
	}

	private function context( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$user    = wp_get_current_user();
		$user_id = (int) ( $user->ID ?? 0 );
		$db      = App::db();

		$clinician_row  = $db->fetchRow(
			'SELECT id, full_name FROM ' . $db->table( 'cpms_clinicians' ) . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
			[ $user_id ]
		);
		$clinician_id   = $clinician_row ? (int) $clinician_row['id'] : 0;
		$clinician_name = $clinician_row ? (string) $clinician_row['full_name'] : '';
		$clinics        = $this->eligible_portal_clinics( $user_id, $clinician_id );

		$selected_clinic_id   = $this->explicit_request_id( $r, 'X-CPMS-Clinic-Id', 'clinic_id' );
		$selected_location_id = $this->explicit_request_id( $r, 'X-CPMS-Location-Id', 'location_id' );
		if ( null === $selected_clinic_id && $this->has_request_selector( $r, 'X-CPMS-Location-Id', 'location_id' ) ) {
			return $this->error(
				'CLINIC_VALIDATION_FAILED',
				422,
				'Location cannot establish Clinic.',
				[ 'field' => 'location_id' ]
			);
		}

		$current_clinic     = null;
		$current_location   = null;
		$eligible_locations = [];
		if ( null !== $selected_clinic_id ) {
			foreach ( $clinics as $clinic ) {
				if ( (int) $clinic['id'] === $selected_clinic_id ) {
					$current_clinic = $clinic;
					break;
				}
			}
			if ( null === $current_clinic ) {
				return $this->error(
					'CLINIC_SCOPE_UNAVAILABLE',
					403,
					'Trusted clinic context is not available.',
					[ 'reason' => 'membership' ]
				);
			}
			$eligible_locations = $this->eligible_locations_for_clinic( $selected_clinic_id, $user_id );
		}

		if ( null !== $selected_location_id ) {
			foreach ( $eligible_locations as $location ) {
				if ( (int) $location['id'] === $selected_location_id ) {
					$current_location = $location;
					break;
				}
			}
			if ( null === $current_location ) {
				return $this->error(
					'CLINIC_SCOPE_UNAVAILABLE',
					403,
					'Trusted clinic context is not available.',
					[ 'reason' => 'location' ]
				);
			}
		}

		return $this->success(
			[
				'doctor'               => [
					'wp_user_id'     => $user_id,
					'clinician_id'   => $clinician_id,
					'clinician_name' => $clinician_name,
					'display_name'   => (string) $user->display_name,
				],
				'clinics'              => $clinics,
				'current_clinic'       => $current_clinic,
				'current_location'     => $current_location,
				'selected_clinic_id'   => $selected_clinic_id,
				'selected_location_id' => $selected_location_id,
				'eligible_locations'   => $eligible_locations,
			]
		);
	}

	private function clinics( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		unset( $r );
		$user_id      = (int) wp_get_current_user()->ID;
		$clinician_id = $this->memberships->active_clinician_id_for_wp_user( $user_id );

		return $this->success( [ 'clinics' => $this->eligible_portal_clinics( $user_id, (int) ( $clinician_id ?? 0 ) ) ] );
	}

	private function locations( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$user_id   = (int) ( wp_get_current_user()->ID ?? 0 );
		$clinic_id = $this->explicit_request_id( $r, 'X-CPMS-Clinic-Id', 'clinic_id' );
		if ( null === $clinic_id ) {
			if ( $this->has_request_selector( $r, 'X-CPMS-Clinic-Id', 'clinic_id' ) ) {
				return $this->error(
					'CLINIC_VALIDATION_FAILED',
					422,
					'Invalid clinic id.',
					[ 'field' => 'clinic_id' ]
				);
			}
			return $this->error(
				'CLINIC_SCOPE_REQUIRED',
				400,
				'Clinic scope required for locations',
				[ 'field' => 'clinic_id', 'reason' => 'clinic_required' ]
			);
		}

		$clinician_id = $this->memberships->active_clinician_id_for_wp_user( $user_id );
		$clinics      = $this->eligible_portal_clinics( $user_id, (int) ( $clinician_id ?? 0 ) );
		$authorized   = false;
		foreach ( $clinics as $clinic ) {
			if ( (int) $clinic['id'] === $clinic_id ) {
				$authorized = true;
				break;
			}
		}
		if ( ! $authorized ) {
			return $this->error(
				'CLINIC_SCOPE_UNAVAILABLE',
				403,
				'Trusted clinic context is not available.',
				[ 'reason' => 'membership' ]
			);
		}

		return $this->success(
			[
				'locations' => $this->eligible_locations_for_clinic( $clinic_id, $user_id ),
				'clinic_id' => $clinic_id,
			]
		);
	}

	/**
	 * Clinic choices are the intersection of active durable memberships,
	 * active Organization scopes, clinician participation, and scoped Portal
	 * authority. AuthorizationService is the only capability resolver, so an
	 * explicit membership DENY always wins over the Doctor role preset.
	 *
	 * @return list<array{id:int,name:string,slug:string,organization_id:int}>
	 */
	private function eligible_portal_clinics( int $user_id, int $clinician_id ): array {
		if ( $user_id <= 0 || $clinician_id <= 0 ) {
			return [];
		}
		$memberships = $this->memberships->active_for_user( $user_id );
		$clinic_ids  = array_values(
			array_unique(
				array_filter(
					array_map( static fn( array $m ): int => (int) ( $m['clinic_id'] ?? 0 ), $memberships ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);
		if ( $clinic_ids === [] ) {
			return [];
		}

		$placeholders  = implode( ',', array_fill( 0, count( $clinic_ids ), '%d' ) );
		$org_rows      = App::db()->fetchAll(
			'SELECT c.id, c.organization_id, o.status AS organization_status FROM ' . App::db()->table( 'cpms_clinics' ) . ' c'
			. ' INNER JOIN ' . App::db()->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id'
			. " WHERE c.id IN ({$placeholders})",
			$clinic_ids
		);
		$organizations = [];
		foreach ( is_array( $org_rows ) ? $org_rows : [] as $row ) {
			$organizations[ (int) $row['id'] ] = $row;
		}

		$authorized    = [];
		$authorization = App::authorization_service();
		foreach ( $memberships as $membership ) {
			$clinic_id = (int) ( $membership['clinic_id'] ?? 0 );
			$org       = $organizations[ $clinic_id ] ?? null;
			if ( $clinic_id <= 0 || $org === null || (string) $org['organization_status'] !== 'active' ) {
				continue;
			}
			if ( ! $this->memberships->clinician_participates_in( $clinician_id, $clinic_id ) ) {
				continue;
			}
			if (
				! $authorization->can( $user_id, $clinic_id, RolesAndCapabilities::QUEUE_READ )
				|| ! $authorization->can( $user_id, $clinic_id, RolesAndCapabilities::MEDICAL_READ )
			) {
				continue;
			}
			$authorized[] = [
				'id'              => $clinic_id,
				'name'            => (string) ( $membership['clinic_name'] ?? 'Clinic ' . $clinic_id ),
				'slug'            => (string) ( $membership['clinic_slug'] ?? '' ),
				'organization_id' => (int) $org['organization_id'],
			];
		}

		return $authorized;
	}

	private function explicit_request_id( WP_REST_Request $r, string $header, string $param ): ?int {
		$header_raw = $r->get_header( $header );
		$param_raw  = $r->get_param( $param );
		$header_id  = $this->parse_selector_id( $header_raw );
		$param_id   = $this->parse_selector_id( $param_raw );
		$has_header = null !== $header_raw && '' !== $header_raw && false !== $header_raw;
		$has_param  = null !== $param_raw && '' !== $param_raw && false !== $param_raw;

		if (
			( $has_header && null === $header_id )
			|| ( $has_param && null === $param_id )
			|| ( null !== $header_id && null !== $param_id && $header_id !== $param_id )
		) {
			return null;
		}

		return $header_id ?? $param_id;
	}

	private function parse_selector_id( mixed $raw ): ?int {
		if ( is_int( $raw ) && $raw > 0 ) {
			return $raw;
		}
		if ( is_string( $raw ) && preg_match( '/^[1-9][0-9]{0,18}$/', $raw ) === 1 ) {
			return (int) $raw;
		}

		return null;
	}

	private function has_request_selector( WP_REST_Request $r, string $header, string $param ): bool {
		$header_raw = $r->get_header( $header );
		$param_raw  = $r->get_param( $param );

		return $this->explicit_request_id( $r, $header, $param ) !== null
			|| ( null !== $header_raw && '' !== $header_raw && false !== $header_raw )
			|| ( null !== $param_raw && '' !== $param_raw && false !== $param_raw );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function eligible_locations_for_clinic( int $clinic_id, int $wp_user_id ): array {
		return ( new DoctorPortalLocationEligibility( App::db(), $this->memberships ) )
			->eligible_locations( $clinic_id, $wp_user_id );
	}
}
