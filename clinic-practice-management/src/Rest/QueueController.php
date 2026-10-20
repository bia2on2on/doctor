<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Visits\VisitException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpointهای مراجعه/صف (F4) — API Contract D1/D6/D7/D8/D16 + E1–E6 + R1.
 */
final class QueueController extends RestBase {
	private const SECRETARY_STATUS_EVENTS = [
		'waiting'          => 'enqueue',
		'cancelled'        => 'cancel',
		'awaiting_payment' => 'invoice_ready',
		'checked_out'      => 'waive',
	];

	private const DOCTOR_EVENTS = [ 'call', 'recall', 'start', 'skip' ];

	public function __construct( private readonly VisitService $visits ) {
	}

	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/secretary/today',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->guard( $r, RolesAndCapabilities::QUEUE_READ, fn() => $this->visits->today( $this->userId( $r ) ) ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->permCap( $r, RolesAndCapabilities::QUEUE_READ ),
					'args'                => [
						'clinician_id' => [
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
			'/visits/checkin',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->guard(
						$r,
						RolesAndCapabilities::QUEUE_CHECKIN,
						function () use ( $r ): array {
							return $this->visits->checkIn(
								$this->userId( $r ),
								(int) $r['patient_id'],
								(int) $r['appointment_id'],
								[ 'note' => $r['note'] ?? null ]
							);
						}
					),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->permCap( $r, RolesAndCapabilities::QUEUE_CHECKIN ),
					'args'                => [
						'patient_id'     => [ 'required' => true, 'type' => 'integer' ],
						'appointment_id' => [ 'required' => true, 'type' => 'integer' ],
						'note'           => [ 'required' => false, 'type' => 'string' ],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/visits/walk-in',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->guard(
						$r,
						RolesAndCapabilities::QUEUE_CHECKIN,
						function () use ( $r ): array {
							return $this->visits->walkIn(
								$this->userId( $r ),
								(int) $r['patient_id'],
								(int) $r['clinician_id'],
								[ 'note' => $r['note'] ?? null ]
							);
						}
					),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->permCap( $r, RolesAndCapabilities::QUEUE_CHECKIN ),
					'args'                => [
						'patient_id'   => [ 'required' => true, 'type' => 'integer' ],
						'clinician_id' => [ 'required' => true, 'type' => 'integer' ],
						'note'         => [ 'required' => false, 'type' => 'string' ],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/visits/(?P<id>\d+)/status',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->guard(
						$r,
						RolesAndCapabilities::QUEUE_ADVANCE,
						function () use ( $r ): array {
							$to_status = (string) $r['to_status'];
							$event     = self::SECRETARY_STATUS_EVENTS[ $to_status ] ?? null;
							if ( null === $event ) {
								throw VisitException::of(
									'CLINIC_VALIDATION_FAILED',
									'وضعیت هدف برای منشی مجاز نیست (waiting, cancelled, awaiting_payment, checked_out)',
									422,
									[ 'to_status' => $to_status ]
								);
							}

							return $this->visits->transition(
								$this->userId( $r ),
								(int) $r['id'],
								$event,
								[
									'reason' => $r['note'] ?? $r['reason'] ?? null,
									'note'   => $r['note'] ?? null,
								]
							);
						}
					),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->permCap( $r, RolesAndCapabilities::QUEUE_ADVANCE ),
					'args'                => [
						'to_status' => [ 'required' => true, 'type' => 'string' ],
						'note'      => [ 'required' => false, 'type' => 'string' ],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/visits/(?P<id>\d+)/checkout',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->guard( $r, RolesAndCapabilities::QUEUE_CHECKOUT, fn() => $this->checkout( $r ) ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->permCap( $r, RolesAndCapabilities::QUEUE_CHECKOUT ),
					'args'                => [
						'waive_invoice' => [ 'required' => false, 'type' => 'object' ],
					],
				],
			]
		);

		// E1 — داشبورد پزشک (Doctor Portal strict) — Blocker 1
		register_rest_route(
			self::NS,
			'/doctor/today',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->guard( $r, RolesAndCapabilities::QUEUE_READ, fn() => $this->visits->todayForDoctorPortal( $this->userId( $r ) ) ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->permDoctorPortal( $r ),
				],
			]
		);

		register_rest_route(
			self::NS,
			'/doctor/portal/today',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->guard( $r, RolesAndCapabilities::QUEUE_READ, fn() => $this->visits->todayForDoctorPortal( $this->userId( $r ) ) ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->permDoctorPortal( $r ),
				],
			]
		);

		register_rest_route(
			self::NS,
			'/queue',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->guard(
						$r,
						RolesAndCapabilities::QUEUE_READ,
						function () use ( $r ): array {
							$clinician_id = isset( $r['clinician_id'] ) ? (int) $r['clinician_id'] : null;
							return $this->visits->today( $this->userId( $r ), $clinician_id );
						}
					),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->permCap( $r, RolesAndCapabilities::QUEUE_READ ),
					'args'                => [
						'clinician_id' => [
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		foreach ( self::DOCTOR_EVENTS as $event ) {
			register_rest_route(
				self::NS,
				'/visits/(?P<id>\d+)/' . $event,
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => fn( WP_REST_Request $r ) => $this->doctorAction( $r, $event ),
						'permission_callback' => fn( WP_REST_Request $r ) => $this->permCap( $r, 'start' === $event ? RolesAndCapabilities::CONSULT_START : RolesAndCapabilities::QUEUE_CALL ),
						'args'                => [
							'reason' => [ 'required' => false, 'type' => 'string' ],
							'room'   => [ 'required' => false, 'type' => 'string' ],
						],
					],
				]
			);
		}

		register_rest_route(
			self::NS,
			'/rt/queue',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->rtQueue( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->permCap( $r, RolesAndCapabilities::QUEUE_READ ),
					'args'                => [
						'since' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/doctor/portal/rt/queue',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( WP_REST_Request $r ) => $this->rtQueuePortal( $r ),
					'permission_callback' => fn( WP_REST_Request $r ) => $this->permDoctorPortal( $r ),
					'args'                => [
						'since' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);
	}

	/**
	 * Doctor Portal entry boundary (WordPress-independent business access).
	 *
	 * Same contract as the Doctor Portal controller boundary: authenticated actor
	 * + server-derived ACTIVE clinician identity + explicit trusted Clinic. The
	 * WordPress business role / global capability are not entry requirements; the
	 * scoped capability of the selected Clinic is enforced by guard() below.
	 */
	private function permDoctorPortal( WP_REST_Request $r ): bool|WP_Error {
		$nonce = $this->requireNonce( $r );
		if ( $nonce instanceof WP_Error ) {
			return $nonce;
		}
		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) || (int) $user->ID <= 0 ) {
			return $this->error( 'CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید' );
		}
		$clinician_id = App::db()->fetchValue(
			'SELECT id FROM ' . App::db()->table( 'cpms_clinicians' ) . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
			[ (int) $user->ID ]
		);
		if ( null === $clinician_id || (int) $clinician_id <= 0 ) {
			return $this->error( 'CLINIC_PERMISSION_DENIED', 403, 'Doctor not linked to active clinician' );
		}

		$selector_error = $this->requireExplicitPortalClinic( $r );
		return $selector_error instanceof WP_Error ? $selector_error : true;
	}

	private function requireExplicitPortalClinic( WP_REST_Request $r ): ?WP_Error {
		$route         = (string) $r->get_route();
		$portal_routes = [
			'/' . self::NS . '/doctor/today',
			'/' . self::NS . '/doctor/portal/today',
			'/' . self::NS . '/doctor/portal/rt/queue',
		];
		if ( ! in_array( $route, $portal_routes, true ) ) {
			return null;
		}
		$raw = $r->get_header( 'X-CPMS-Clinic-Id' );
		if ( null === $raw || '' === $raw ) {
			$raw = $r->get_param( 'clinic_id' );
		}
		if ( null === $raw || '' === $raw || false === $raw ) {
			return $this->error(
				'CLINIC_SCOPE_REQUIRED',
				400,
				'Clinic scope required',
				[ 'field' => 'clinic_id', 'reason' => 'clinic_required' ]
			);
		}
		$is_valid_id = ( is_int( $raw ) && $raw > 0 )
			|| ( is_string( $raw ) && preg_match( '/^[1-9][0-9]{0,18}$/', $raw ) === 1 );
		if ( ! $is_valid_id ) {
			return $this->error(
				'CLINIC_VALIDATION_FAILED',
				422,
				'Invalid clinic id.',
				[ 'field' => 'clinic_id' ]
			);
		}
		$clinic_id = (int) $raw;
		$user_id   = (int) wp_get_current_user()->ID;
		if ( ! App::authorization_service()->can( $user_id, $clinic_id, RolesAndCapabilities::QUEUE_READ ) ) {
			return $this->error( 'CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید' );
		}

		return null;
	}

	private function doctorAction( WP_REST_Request $r, string $event ): WP_REST_Response|WP_Error {
		$cap = match ( $event ) {
			'start' => RolesAndCapabilities::CONSULT_START,
			default => RolesAndCapabilities::QUEUE_CALL,
		};

		return $this->guard(
			$r,
			$cap,
			fn() => $this->visits->transition(
				$this->userId( $r ),
				(int) $r['id'],
				$event,
				[
					'reason' => $r['reason'] ?? null,
					'room'   => $r['room'] ?? null,
				]
			)
		);
	}

	private function checkout( WP_REST_Request $r ): array {
		$waive        = $r['waive_invoice'] ?? null;
		$waive_reason = is_array( $waive ) && isset( $waive['reason'] ) ? (string) $waive['reason'] : null;
		return $this->visits->checkout( $this->userId( $r ), (int) $r['id'], $waive_reason );
	}

	private function rtQueue( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$guard = $this->guard( $r, RolesAndCapabilities::QUEUE_READ, null );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$rl = $this->rateLimit( $r, 'rt_queue_' . $this->userId( $r ), 60, MINUTE_IN_SECONDS );
		if ( $rl instanceof WP_Error ) {
			return $rl;
		}

		$user_id       = $this->userId( $r );
		$since         = (int) ( $r['since'] ?? 0 );
		$last_event_id = $this->visits->lastEventId( $user_id );
		$etag          = '\"' . $last_event_id . '\"';
		$if_none_match = trim( (string) $r->get_header( 'If-None-Match' ) );
		if ( $if_none_match === $etag ) {
			$not_modified = new WP_REST_Response( null, 304 );
			$not_modified->header( 'ETag', $etag );
			return $not_modified;
		}

		$payload  = $this->visits->eventsSince( $user_id, $since );
		$response = $this->success( $payload );
		$response->header( 'ETag', $etag );
		return $response;
	}

	private function rtQueuePortal( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$guard = $this->guard( $r, RolesAndCapabilities::QUEUE_READ, null );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$rl = $this->rateLimit( $r, 'rt_queue_portal_' . $this->userId( $r ), 60, MINUTE_IN_SECONDS );
		if ( $rl instanceof WP_Error ) {
			return $rl;
		}

		$user_id = $this->userId( $r );
		$since   = (int) ( $r['since'] ?? 0 );

		try {
			$last_event_id = $this->visits->lastEventIdForDoctorPortal( $user_id );
		} catch ( VisitException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
		}

		$etag          = '\"' . $last_event_id . '\"';
		$if_none_match = trim( (string) $r->get_header( 'If-None-Match' ) );
		if ( $if_none_match === $etag ) {
			$not_modified = new WP_REST_Response( null, 304 );
			$not_modified->header( 'ETag', $etag );
			return $not_modified;
		}

		try {
			$payload = $this->visits->eventsSinceForDoctorPortal( $user_id, $since );
		} catch ( VisitException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
		}

		$response = $this->success( $payload );
		$response->header( 'ETag', $etag );
		return $response;
	}

	private function userId( WP_REST_Request $r ): int {
		return (int) ( wp_get_current_user()->ID ?: 0 ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- WPCS
	}

	private function guard( WP_REST_Request $r, string $cap, ?callable $fn ): WP_REST_Response|WP_Error { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.fnFound -- WPCS
		$nonce = $this->requireNonce( $r );
		if ( $nonce instanceof WP_Error ) {
			return $nonce;
		}
		// Authority for this operation is the scoped capability of the explicitly
		// established trusted Clinic (requireClinicPermission below). The
		// WordPress-global capability is no longer a precondition, so a Subscriber
		// with a durable staff membership uses the same boundary; nothing widens,
		// because the scoped check still runs and fails closed without a scope.
		$portal_context = $this->requireExplicitPortalClinic( $r );
		if ( $portal_context instanceof WP_Error ) {
			return $portal_context;
		}
		$scoped = $this->requireClinicPermission( $cap );
		if ( $scoped instanceof WP_Error ) {
			return $scoped;
		}
		if ( null === $fn ) {
			return $this->success( null );
		}

		try {
			return $this->success( $fn(), 200 );
		} catch ( VisitException $e ) {
			return $this->error( $e->errorCode, $e->httpStatus, $e->getMessage(), $e->data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
		} catch ( \Throwable $e ) {
			error_log( '[CPMS][QueueController] unexpected: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WPCS
			return $this->error( 'CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید' );
		}
	}
}
