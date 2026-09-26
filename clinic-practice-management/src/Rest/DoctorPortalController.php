<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Doctor Portal independent shell — context endpoints (read-only Today+Live Queue).
 */
final class DoctorPortalController extends RestBase {
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

	/** Rebind every selector to the authorized Visit before calling the shared engine service. */
	private function workspace_handwriting( WP_REST_Request $r, string $operation ): WP_REST_Response|WP_Error {
		$visit_id = (int) $r['id'];
		$guard    = $this->workspace_authorize_visit( $visit_id );
		if ( $guard instanceof WP_Error ) {
			$this->workspace_handwriting_denial( $visit_id, $operation );
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
				$this->workspace_handwriting_denial( $visit_id, $operation );
				return new WP_Error( 'CLINIC_NOT_FOUND', 'مراجعه یافت نشد', [ 'status' => 404 ] );
			}
		}

		$actor   = (int) wp_get_current_user()->ID;
		$service = App::handwritingService();
		try {
			switch ( $operation ) {
				case 'list':
					$list = $service->listDocuments( $actor, $visit_id );
					$list['autosave_sec'] = max( 2, (int) App::settingsFactory()->forClinic( (int) App::scope()->clinicId )->get( 'hw.autosave_sec', 5 ) ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- array key and established scope contract
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

	private function workspace_handwriting_denial( int $visit_id, string $operation ): void {
		$user = wp_get_current_user();
		App::audit()->log( 'FORBIDDEN_ACCESS_ATTEMPT', [ 'wp_user_id' => (int) $user->ID, 'role' => RolesAndCapabilities::ROLE_DOCTOR ], 'handwriting', $visit_id, null, null, null, [ 'operation' => $operation ] );
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
		$active_clinics = $this->memberships->active_clinic_ids_for_user( (int) $user->ID );
		if ( [] === $active_clinics ) {
			return new WP_Error( 'CLINIC_SCOPE_UNAVAILABLE', 'No active clinic membership', [ 'status' => 403 ] );
		}
		return true;
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
	 * Server-side prescription -> Visit ownership resolution for the portal
	 * boundary. Unknown OR foreign-Visit prescription selectors collapse to
	 * the same non-enumerating 404; existence is never leaked.
	 */
	private function workspace_authorize_prescription( int $prescription_id ): bool|WP_Error {
		$db = App::db();
		$rx = $db->fetchRow(
			'SELECT id, visit_id FROM ' . $db->table( 'cpms_prescriptions' ) . ' WHERE id = %d LIMIT 1',
			[ $prescription_id ]
		);
		if ( null === $rx ) {
			return new WP_Error( 'CLINIC_NOT_FOUND', 'نسخه یافت نشد', [ 'status' => 404 ] );
		}
		return $this->workspace_authorize_visit( (int) $rx['visit_id'] );
	}

	/**
	 * Portal-specific Visit Workspace authority — the narrow Doctor Portal
	 * boundary in front of the shared E7/E8 behavior.
	 *
	 * Authority = authenticated WP user + server-derived active clinician
	 * identity + active trusted Clinic + trusted operational Location
	 * (0 eligible => fail closed, 1 => auto-resolution, N>1 => explicit
	 * Location required, foreign/inactive/unassigned => fail closed) + Visit
	 * owned by that clinician and located at that Location.
	 *
	 * Raw visit_id/location_id remain selectors only; every foreign Visit
	 * selector fails non-enumerating with the established repository
	 * convention (404 CLINIC_NOT_FOUND).
	 */
	private function workspace_authorize_visit( int $visit_id ): bool|WP_Error {
		$user_id = (int) ( wp_get_current_user()->ID ?? 0 );

		// 1) Trusted Clinic scope — bound by RestClinicContext from selector headers.
		try {
			$scope = App::scope();
		} catch ( \Throwable $e ) {
			unset( $e );
			return new WP_Error( 'CLINIC_SCOPE_REQUIRED', 'Clinic scope required', [ 'status' => 400 ] );
		}
		$clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract

		// 2) Server-derived clinician identity — never client-provided.
		try {
			$clinician_id = $this->memberships->active_clinician_id_for_wp_user( $user_id );
		} catch ( \Throwable $e ) {
			unset( $e );
			$clinician_id = null; // Ambiguous identities fail closed.
		}
		if ( null === $clinician_id || ! $this->memberships->clinician_participates_in( $clinician_id, $clinic_id ) ) {
			return new WP_Error( 'CLINIC_PERMISSION_DENIED', 'Doctor identity is not active in the trusted clinic', [ 'status' => 403 ] );
		}

		// 3) Trusted operational Location — Doctor Portal 0/1/N policy.
		$eligible = array_map(
			static fn( array $loc ): int => (int) $loc['id'],
			$this->eligible_locations_for_clinic( $clinic_id, $user_id )
		);
		if ( [] === $eligible ) {
			return new WP_Error( 'CLINIC_SCOPE_UNAVAILABLE', 'Trusted clinic context is not available.', [ 'status' => 403, 'reason' => 'location' ] );
		}
		$location_id = $scope->locationId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
		if ( null === $location_id ) {
			if ( 1 !== count( $eligible ) ) {
				// N>1 without explicit Location => REQUIRED; never guess the first.
				return new WP_Error(
					'CLINIC_SCOPE_REQUIRED',
					'Location scope required: multiple eligible locations',
					[
						'status' => 400,
						'field'  => 'location_id',
						'reason' => 'location_required',
					]
				);
			}
			$location_id = $eligible[0];
		} elseif ( ! in_array( $location_id, $eligible, true ) ) {
			// Foreign/inactive/unassigned explicit Location => fail closed.
			return new WP_Error( 'CLINIC_SCOPE_UNAVAILABLE', 'Trusted clinic context is not available.', [ 'status' => 403, 'reason' => 'location' ] );
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

		$clinician_row = $db->fetchRow( // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability
			'SELECT id, full_name, clinic_id FROM ' . $db->table( 'cpms_clinicians' ) . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
			[ $user_id ]
		);
		$clinician_id   = $clinician_row ? (int) $clinician_row['id'] : 0;
		$clinician_name = $clinician_row ? (string) $clinician_row['full_name'] : '';

		$memberships = $this->memberships->active_for_user( $user_id );
		$clinics     = [];
		foreach ( $memberships as $m ) {
			$clinics[] = [
				'id'              => (int) $m['clinic_id'],
				'name'            => (string) ( $m['clinic_name'] ?? 'Clinic ' . $m['clinic_id'] ),
				'slug'            => (string) ( $m['clinic_slug'] ?? '' ),
				'organization_id' => 0,
			];
		}

		$current_clinic       = null;
		$current_location     = null;
		$selected_clinic_id   = null;
		$selected_location_id = null;

		if ( 1 === count( $clinics ) ) {
			$selected_clinic_id = $clinics[0]['id'];
			$current_clinic     = $clinics[0];
		} else {
			try {
				$scope                = App::scope();
				$selected_clinic_id   = $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
				$selected_location_id = $scope->locationId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
				foreach ( $clinics as $c ) {
					if ( $c['id'] === $selected_clinic_id ) {
						$current_clinic = $c;
						break;
					}
				}
				if ( null !== $selected_location_id ) {
					$loc_row = $db->fetchRow(
						'SELECT id, name, slug, timezone, is_primary, is_active FROM ' . $db->table( 'cpms_locations' ) . ' WHERE id = %d LIMIT 1',
						[ $selected_location_id ]
					);
					if ( $loc_row ) {
						$current_location = [
							'id'         => (int) $loc_row['id'],
							'name'       => (string) $loc_row['name'],
							'slug'       => (string) $loc_row['slug'],
							'timezone'   => (string) $loc_row['timezone'],
							'is_primary' => (int) $loc_row['is_primary'] === 1,
							'is_active'  => (int) $loc_row['is_active'] === 1,
						];
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		$eligible_locations = [];
		if ( null !== $selected_clinic_id ) {
			$eligible_locations = $this->eligible_locations_for_clinic( $selected_clinic_id, $user_id );
			if ( 1 === count( $eligible_locations ) ) {
				$selected_location_id = $eligible_locations[0]['id'];
				$current_location     = $eligible_locations[0];
			}
		}

		$data = [
			'doctor'             => [ // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
				'wp_user_id'     => $user_id,
				'clinician_id'   => $clinician_id,
				'clinician_name' => $clinician_name,
				'display_name'   => (string) $user->display_name,
			],
			'clinics'            => $clinics, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
			'current_clinic'     => $current_clinic, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
			'current_location'   => $current_location, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
			'selected_clinic_id' => $selected_clinic_id, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
			'selected_location_id' => $selected_location_id,
			'eligible_locations' => $eligible_locations, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
		];

		return $this->success( $data );
	}

	private function clinics( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$user_id     = (int) wp_get_current_user()->ID;
		$memberships = $this->memberships->active_for_user( $user_id );
		$clinics     = [];
		foreach ( $memberships as $m ) {
			$clinics[] = [
				'id'   => (int) $m['clinic_id'],
				'name' => (string) ( $m['clinic_name'] ?? 'Clinic ' . $m['clinic_id'] ),
				'slug' => (string) ( $m['clinic_slug'] ?? '' ),
			];
		}
		return $this->success( [ 'clinics' => $clinics ] );
	}

	private function locations( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$user    = wp_get_current_user(); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability
		$user_id = (int) ( $user->ID ?? 0 ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability
		$clinic_id = (int) $r->get_param( 'clinic_id' );

		if ( $clinic_id <= 0 ) {
			try {
				$scope     = App::scope();
				$clinic_id = $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
			} catch ( \Throwable $e ) {
				$active = $this->memberships->active_clinic_ids_for_user( $user_id );
				if ( 1 === count( $active ) ) {
					$clinic_id = $active[0];
				} else {
					return $this->error( 'CLINIC_SCOPE_REQUIRED', 400, 'Clinic scope required for locations', [ 'field' => 'clinic_id' ] );
				}
			}
		}

		if ( null === $this->memberships->find_active( $clinic_id, $user_id ) ) {
			return $this->error( 'CLINIC_SCOPE_UNAVAILABLE', 403, 'No membership for clinic', [ 'reason' => 'membership' ] );
		}

		$locations = $this->eligible_locations_for_clinic( $clinic_id, $user_id );

		return $this->success(
			[
				'locations' => $locations,
				'clinic_id' => $clinic_id,
			]
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function eligible_locations_for_clinic( int $clinic_id, int $wp_user_id ): array {
		$db         = App::db();
		$membership = $this->memberships->find_active( $clinic_id, $wp_user_id );
		if ( null === $membership ) {
			return [];
		}

		$active_rows = $db->fetchAll(
			'SELECT id, name, slug, timezone, is_primary, is_active FROM ' . $db->table( 'cpms_locations' ) .
			' WHERE clinic_id = %d AND is_active = 1 ORDER BY id ASC',
			[ $clinic_id ]
		);
		$active = []; // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability
		foreach ( ( is_array( $active_rows ) ? $active_rows : [] ) as $row ) {
			$active[ (int) $row['id'] ] = [
				'id'         => (int) $row['id'],
				'name'       => (string) $row['name'],
				'slug'       => (string) $row['slug'],
				'timezone'   => (string) $row['timezone'],
				'is_primary' => (int) $row['is_primary'] === 1,
				'is_active'  => (int) $row['is_active'] === 1,
			];
		}

		$scope_mode = (string) ( $membership['scope_mode'] ?? 'clinic' );
		if ( 'location' === $scope_mode ) {
			$assigned_ids = $this->memberships->location_ids_for( (int) $membership['id'] );
			$eligible     = [];
			foreach ( $assigned_ids as $id ) {
				if ( isset( $active[ $id ] ) ) {
					$eligible[] = $active[ $id ];
				}
			}
			return $eligible;
		}

		return array_values( $active );
	}
}
