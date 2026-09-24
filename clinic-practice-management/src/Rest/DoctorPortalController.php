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
