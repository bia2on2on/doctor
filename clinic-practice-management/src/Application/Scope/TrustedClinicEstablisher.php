<?php

declare(strict_types=1);

namespace ClinicCore\Application\Scope;

use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * استقرار ClinicScope مورد اعتماد — transport-agnostic (ADR-0031).
 *
 * ورودی (clinic/location) درخواست است نه اعتماد؛ اعتماد بعد از:
 * عضویت فعال + وجود Clinic/Organization در DB (+ تعلق Location در صورت ارسال).
 *
 * SystemClinicResolver اینجا عمداً صدا زده نمی‌شود: حتی نصب تک‌کلینیکی
 * بدون عضویت فعال، برای عملیات staff بسته است.
 */
final class TrustedClinicEstablisher {
	public function __construct(
		private readonly CpmsDb $db,
		private readonly MembershipRepository $memberships
	) {
	}

	/**
	 * @throws ScopeRequiredException
	 */
	public function establish( int $wp_user_id, ?int $clinic_id, ?int $location_id = null ): ClinicScope {
		if ( $wp_user_id <= 0 ) {
			$this->unavailable( 'unauthenticated' );
		}

		if ( null === $clinic_id ) {
			if ( null !== $location_id ) {
				throw new ScopeRequiredException(
					'CLINIC_VALIDATION_FAILED',
					'Location cannot establish Clinic.',
					[ 'field' => 'location_id' ],
					422
				);
			}

			return $this->from_unique_membership( $wp_user_id );
		}

		return $this->from_requested_clinic( $wp_user_id, $clinic_id, $location_id );
	}

	/**
	 * @throws ScopeRequiredException
	 */
	private function from_unique_membership( int $wp_user_id ): ClinicScope {
		$ids = $this->memberships->active_clinic_ids_for_user( $wp_user_id );
		if ( [] === $ids ) {
			$this->unavailable( 'no_membership' );
		}
		if ( 1 !== count( $ids ) ) {
			throw new ScopeRequiredException(
				'CLINIC_SCOPE_REQUIRED',
				'Explicit clinic is required when the user has more than one active membership.',
				[],
				400
			);
		}

		return $this->verified_scope( $wp_user_id, $ids[0], null );
	}

	/**
	 * @throws ScopeRequiredException
	 */
	private function from_requested_clinic( int $wp_user_id, int $clinic_id, ?int $location_id ): ClinicScope {
		if ( $clinic_id <= 0 ) {
			throw new ScopeRequiredException(
				'CLINIC_VALIDATION_FAILED',
				'Invalid clinic id.',
				[ 'field' => 'clinic_id' ],
				422
			);
		}

		return $this->verified_scope( $wp_user_id, $clinic_id, $location_id );
	}

	/**
	 * Shared establisher validates explicit Location selector against Clinic/membership/location assignment.
	 * It does NOT force N>1 eligible Locations to select a Location — that stricter rule lives in Doctor Portal boundary.
	 *
	 * @throws ScopeRequiredException
	 */
	private function verified_scope( int $wp_user_id, int $clinic_id, ?int $location_id ): ClinicScope {
		$membership = $this->memberships->find_active( $clinic_id, $wp_user_id );
		if ( null === $membership ) {
			$this->unavailable( 'membership' );
		}

		$clinic = $this->db->fetchRow(
			'SELECT c.id, c.organization_id, o.status AS organization_status
			   FROM ' . $this->db->table( 'cpms_clinics' ) . ' c
			   INNER JOIN ' . $this->db->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id
			  WHERE c.id = %d
			  LIMIT 1',
			[ $clinic_id ]
		);
		if ( null === $clinic ) {
			$this->unavailable( 'clinic' );
		}
		if ( 'active' !== (string) ( $clinic['organization_status'] ?? '' ) ) {
			$this->unavailable( 'organization' );
		}

		$organization_id = (int) $clinic['organization_id'];
		$scope           = ClinicScope::forClinic( $clinic_id )->withOrganization( $organization_id );

		// Explicit Location path — validate against Clinic + active + assignment.
		if ( null === $location_id ) {
			// Pre-PR behavior: no auto-bind, no REQUIRED. Return scope without Location.
			// Doctor Portal will enforce 0/1/N>1 via its own boundary.
			return $scope;
		}

		if ( $location_id <= 0 ) {
			throw new ScopeRequiredException(
				'CLINIC_VALIDATION_FAILED',
				'Invalid location id.',
				[ 'field' => 'location_id' ],
				422
			);
		}

		$location_clinic = $this->memberships->location_clinic_map( [ $location_id ] );
		if ( ( $location_clinic[ $location_id ] ?? null ) !== $clinic_id ) {
			$this->unavailable( 'location' );
		}

		$active = $this->db->fetchValue(
			'SELECT id FROM ' . $this->db->table( 'cpms_locations' ) .
			' WHERE id = %d AND clinic_id = %d AND is_active = 1 LIMIT 1',
			[ $location_id, $clinic_id ]
		);
		if ( null === $active ) {
			$this->unavailable( 'location' );
		}

		// Validate eligibility (assigned when scope_mode=location).
		$active_location_ids = $this->active_location_ids_for_clinic( $clinic_id );
		$scope_mode          = (string) ( $membership['scope_mode'] ?? 'clinic' );
		if ( 'location' === $scope_mode ) {
			$assigned = $this->memberships->location_ids_for( (int) $membership['id'] );
			$eligible = array_values( array_intersect( $assigned, $active_location_ids ) );
			$eligible = array_values( array_unique( array_map( 'intval', $eligible ) ) );
			sort( $eligible );
		} else {
			$eligible = $active_location_ids;
		}

		if ( ! in_array( $location_id, $eligible, true ) ) {
			$this->unavailable( 'location' );
		}

		return $scope->withLocation( $location_id );
	}

	/**
	 * @return list<int>
	 */
	private function active_location_ids_for_clinic( int $clinic_id ): array {
		$rows = $this->db->fetchAll(
			'SELECT id FROM ' . $this->db->table( 'cpms_locations' ) .
			' WHERE clinic_id = %d AND is_active = 1 ORDER BY id ASC',
			[ $clinic_id ]
		);
		$ids = []; // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability
		foreach ( ( is_array( $rows ) ? $rows : [] ) as $r ) {
			$ids[] = (int) ( $r['id'] ?? 0 );
		}
		$ids = array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * @throws ScopeRequiredException
	 */
	private function unavailable( string $reason ): never {
		throw new ScopeRequiredException(
			'CLINIC_SCOPE_UNAVAILABLE',
			'Trusted clinic context is not available.',
			[ 'reason' => $reason ],
			403
		);
	}
}
