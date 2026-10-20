<?php
/**
 * Dedicated (non-wp-admin) Management Portal — read model and authorized writes.
 *
 * Business-access architecture (Owner-approved 2026-10-20):
 *   - only WordPress Administrators need wp-admin;
 *   - every other CPMS user completes authorized business workflows through
 *     dedicated frontend portals;
 *   - CPMS business authority comes from authenticated identity + durable
 *     Organization/Clinic membership + explicit GRANT/DENY rules, never from a
 *     WordPress business role or a global WordPress capability.
 *
 * This service is a thin, fail-closed orchestration boundary. It does not
 * introduce a second authorization engine:
 *   - Organization authority is resolved by OrganizationClinicProvisioningService
 *     (Organization membership + DENY > GRANT > Organization-Manager preset);
 *   - Clinic authority is resolved by AuthorizationService
 *     (ACTIVE Clinic membership + DENY > GRANT > role preset > deny);
 *   - object ownership is established from durable storage before mutation;
 *   - every write re-authorizes itself inside the domain service.
 *
 * It never exposes clinical or financial data. Operational reads are limited to
 * Clinic configuration data (profile, Locations, staff membership metadata,
 * clinician directory, schedule configuration) and are gated by CPMS
 * capabilities. Reports/tariff links are decided by their own capabilities and
 * are not data reads here.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Application\Management;

// The portal read model returns several small associative arrays whose keys have
// very different lengths. This file follows the project idiom (compact arrays,
// one key per line) rather than the WordPress multi-statement alignment, which is
// purely cosmetic and would fight the surrounding code.
// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Location\LocationException;
use ClinicCore\Application\Onboarding\OrganizationClinicProvisioningService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\ClinicRepository;
use ClinicCore\Infrastructure\Repository\ClinicianRepository;
use ClinicCore\Infrastructure\Repository\LocationRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_User;

/**
 * Read model + authorized write orchestration for the dedicated Management Portal.
 */
final class ManagementPortalService {

	/** Organization Manager surface. */
	public const SECTION_ORGANIZATION = 'organization';

	/** Clinic Manager surface. */
	public const SECTION_CLINIC = 'clinic';

	/**
	 * Built-in staff role keys a Clinic Manager may assign through the portal.
	 * WordPress roles (administrator, editor, …) are deliberately absent: portal
	 * role assignment is Clinic membership data, never a WordPress-global role.
	 *
	 * @var list<string>
	 */
	public const ASSIGNABLE_BUILT_IN_ROLES = array(
		RolesAndCapabilities::ROLE_DOCTOR,
		RolesAndCapabilities::ROLE_SECRETARY,
		RolesAndCapabilities::ROLE_ACCOUNTANT,
		RolesAndCapabilities::ROLE_MANAGER,
		RolesAndCapabilities::ROLE_PATIENT,
	);

	/**
	 * Explicit capability a Clinic Manager needs for the Clinic configuration
	 * surface (profile, Locations, staff membership). No new vocabulary.
	 */
	public const CLINIC_MANAGE_CAPABILITY = RolesAndCapabilities::CONFIG;

	/**
	 * Reports are only ever a link decided by this read capability; no report
	 * data is produced here.
	 */
	public const CLINIC_REPORT_CAPABILITY = RolesAndCapabilities::REPORT_READ;

	public function __construct(
		private readonly OrganizationClinicProvisioningService $organizations,
		private readonly AuthorizationService $authorization,
		private readonly TrustedClinicEstablisher $scope,
		private readonly MembershipRepository $memberships,
		private readonly LocationRepository $locations,
		private readonly ClinicianRepository $clinicians,
		private readonly ClinicRepository $clinics
	) {
	}

	// ==================================================================
	// Surface eligibility (visibility only — every write re-authorizes)
	// ==================================================================

	/**
	 * Organizations this actor may manage, with per-capability flags.
	 *
	 * Visibility is the UNION of the independent Organization capabilities;
	 * a DENY on one never hides another grant (resolution stays per capability
	 * inside OrganizationClinicProvisioningService).
	 *
	 * @return list<array{id:int, name:string, can_create:bool, can_update:bool, can_lifecycle:bool, can_designate:bool, can_manage_members:bool}>
	 */
	public function organizations( int $actor_wp_user_id ): array {
		if ( $actor_wp_user_id <= 0 ) {
			return array();
		}

		$out = array();
		foreach ( $this->organizations->management_organizations( $actor_wp_user_id ) as $organization ) {
			$organization_id = (int) ( $organization['id'] ?? 0 );
			if ( $organization_id <= 0 ) {
				continue;
			}
			$out[] = array(
				'id'                 => $organization_id,
				'name'               => (string) ( $organization['name'] ?? '' ),
				'can_create'         => $this->organizations->can( $actor_wp_user_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY ),
				'can_update'         => $this->organizations->can( $actor_wp_user_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY_PROFILE_UPDATE ),
				'can_lifecycle'      => $this->organizations->can( $actor_wp_user_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY_LIFECYCLE ),
				'can_designate'      => $this->organizations->can( $actor_wp_user_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY_CLINIC_MANAGER ),
				'can_manage_members' => $this->organizations->can( $actor_wp_user_id, $organization_id, OrganizationClinicProvisioningService::MEMBERSHIP_CAPABILITY ),
			);
		}

		return $out;
	}

	/**
	 * Clinics this actor may manage through the portal, derived ONLY from a
	 * durable ACTIVE Clinic membership plus the explicit Clinic management
	 * capability. No WordPress role and no global capability is consulted.
	 *
	 * @return list<array{id:int, name:string, status:string, can_manage:bool, can_view_reports:bool}>
	 */
	public function clinics( int $actor_wp_user_id ): array {
		if ( $actor_wp_user_id <= 0 ) {
			return array();
		}

		$out = array();
		foreach ( $this->memberships->active_clinic_ids_for_user( $actor_wp_user_id ) as $clinic_id ) {
			$clinic = $this->clinics->find( (int) $clinic_id );
			if ( $clinic === null ) {
				continue;
			}
			$can_manage  = $this->authorization->can( $actor_wp_user_id, (int) $clinic_id, self::CLINIC_MANAGE_CAPABILITY );
			$can_reports = $this->authorization->can( $actor_wp_user_id, (int) $clinic_id, self::CLINIC_REPORT_CAPABILITY );
			if ( ! $can_manage && ! $can_reports ) {
				continue;
			}
			$out[] = array(
				'id'               => (int) $clinic_id,
				'name'             => (string) ( $clinic['name'] ?? '' ),
				'status'           => (string) ( $clinic['status'] ?? 'active' ),
				'can_manage'       => $can_manage,
				'can_view_reports' => $can_reports,
			);
		}

		return $out;
	}

	/**
	 * Whether the actor has at least one management surface. Visibility only.
	 */
	public function has_any_surface( int $actor_wp_user_id ): bool {
		return $this->organizations( $actor_wp_user_id ) !== array()
			|| $this->clinics( $actor_wp_user_id ) !== array();
	}

	// ==================================================================
	// Organization overview (Organization authority only)
	// ==================================================================

	/**
	 * Organization overview: Clinics, lifecycle status, Location count and the
	 * durable Clinic-manager designations. No clinical/financial row is read.
	 *
	 * @return array{organization:array{id:int,name:string}, clinics:list<array<string,mixed>>, can:array<string,bool>}|null
	 */
	public function organization_overview( int $actor_wp_user_id, int $organization_id ): ?array {
		if ( $actor_wp_user_id <= 0 || $organization_id <= 0 ) {
			return null;
		}

		$organization = null;
		foreach ( $this->organizations( $actor_wp_user_id ) as $candidate ) {
			if ( $candidate['id'] === $organization_id ) {
				$organization = $candidate;
				break;
			}
		}
		if ( $organization === null ) {
			return null; // fail closed: not an authorized Organization for this actor
		}

		$clinics = array();
		foreach ( $this->organizations->clinics_for_organization( $actor_wp_user_id, $organization_id ) as $clinic ) {
			$clinic_id = (int) ( $clinic['id'] ?? 0 );
			if ( $clinic_id <= 0 ) {
				continue;
			}
			// Durable Organization ownership is re-checked server-side.
			if ( (int) ( $clinic['organization_id'] ?? 0 ) !== $organization_id ) {
				continue;
			}
			$clinics[] = array(
				'id'               => $clinic_id,
				'name'             => (string) ( $clinic['name'] ?? '' ),
				'slug'             => (string) ( $clinic['slug'] ?? '' ),
				'status'           => (string) ( $clinic['status'] ?? 'active' ),
				'location_count'   => $this->locations->active_count_for_clinic( $clinic_id ),
				'manager_user_ids' => $this->clinic_manager_user_ids( $clinic_id ),
			);
		}

		return array(
			'organization' => array(
				'id'           => $organization_id,
				'name'         => $organization['name'],
			),
			'clinics'   => $clinics,
			'can'       => array(
				'create'    => $organization['can_create'],
				'update'    => $organization['can_update'],
				'lifecycle' => $organization['can_lifecycle'],
				'designate' => $organization['can_designate'],
				'members'   => $organization['can_manage_members'],
			),
		);
	}

	/**
	 * Active Clinic-manager memberships of one Clinic (durable rows only).
	 *
	 * @return list<int>
	 */
	private function clinic_manager_user_ids( int $clinic_id ): array {
		$ids = array();
		foreach ( $this->memberships->list_for_clinic( $clinic_id ) as $membership ) {
			if ( (string) ( $membership['role_key'] ?? '' ) !== OrganizationClinicProvisioningService::CLINIC_MANAGER_ROLE_KEY ) {
				continue;
			}
			if ( (string) ( $membership['status'] ?? '' ) !== 'active' ) {
				continue;
			}
			$ids[] = (int) ( $membership['wp_user_id'] ?? 0 );
		}

		return array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * Organization managers of one Organization (Organization authority only).
	 *
	 * @return list<array{wp_user_id:int,status:string,display_name:string}>
	 */
	public function organization_managers( int $actor_wp_user_id, int $organization_id ): array {
		$overview = $this->organization_overview( $actor_wp_user_id, $organization_id );
		if ( $overview === null || ! $overview['can']['members'] ) {
			return array();
		}

		$out = array();
		foreach ( $this->db()->fetchAll(
			'SELECT wp_user_id, status FROM ' . $this->db()->table( 'cpms_organization_memberships' )
				. ' WHERE organization_id = %d AND role_key = %s ORDER BY status, wp_user_id',
			array( $organization_id, 'organization_manager' )
		) as $row ) {
			$user_id = (int) ( $row['wp_user_id'] ?? 0 );
			$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
			$out[]   = array(
				'wp_user_id'   => $user_id,
				'status'       => (string) ( $row['status'] ?? '' ),
				'display_name' => $user === false ? '' : (string) $user->display_name,
			);
		}

		return $out;
	}

	// ==================================================================
	// Clinic workspace (Clinic authority only, configuration data only)
	// ==================================================================

	/**
	 * Clinic management workspace for one explicitly selected Clinic.
	 *
	 * Fail closed: requires an ACTIVE membership and the Clinic management
	 * capability in that exact Clinic. Returns configuration/operational
	 * metadata only — never a clinical note, prescription, medical file,
	 * invoice or payment row.
	 *
	 * @return array<string,mixed>|null
	 */
	public function clinic_workspace( int $actor_wp_user_id, int $clinic_id ): ?array {
		if ( $actor_wp_user_id <= 0 || $clinic_id <= 0 ) {
			return null;
		}
		if ( ! $this->authorization->can( $actor_wp_user_id, $clinic_id, self::CLINIC_MANAGE_CAPABILITY ) ) {
			return null;
		}
		$clinic = $this->clinics->find( $clinic_id );
		if ( $clinic === null || (int) ( $clinic['id'] ?? 0 ) !== $clinic_id ) {
			return null;
		}

		$staff = array();
		foreach ( $this->memberships->list_for_clinic( $clinic_id ) as $membership ) {
			if ( (int) ( $membership['clinic_id'] ?? 0 ) !== $clinic_id ) {
				continue; // durable ownership must agree
			}
			$user_id = (int) ( $membership['wp_user_id'] ?? 0 );
			$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
			$membership_id = (int) ( $membership['id'] ?? 0 );
			$staff[]       = array(
				'wp_user_id'    => $user_id,
				'membership_id' => $membership_id,
				'display_name'  => $user === false ? '' : (string) $user->display_name,
				'role_key'      => (string) ( $membership['role_key'] ?? '' ),
				'status'        => (string) ( $membership['status'] ?? '' ),
				'scope_mode'    => (string) ( $membership['scope_mode'] ?? '' ),
				'locations'     => $membership_id > 0 ? $this->memberships->location_ids_for( $membership_id ) : array(),
				'capabilities'  => $membership_id > 0
					? array_map(
						static fn( array $row ): array => array(
							'capability' => (string) ( $row['capability'] ?? '' ),
							'effect'     => (string) ( $row['effect'] ?? '' ),
						),
						$this->memberships->capabilities_for( $membership_id )
					)
					: array(),
			);
		}

		$locations = array();
		foreach ( $this->locations->listForClinic( $clinic_id ) as $location ) {
			if ( (int) ( $location['clinic_id'] ?? 0 ) !== $clinic_id ) {
				continue;
			}
			$locations[] = array(
				'id'         => (int) ( $location['id'] ?? 0 ),
				'name'       => (string) ( $location['name'] ?? '' ),
				'slug'       => (string) ( $location['slug'] ?? '' ),
				'address'    => (string) ( $location['address'] ?? '' ),
				'phone'      => (string) ( $location['phone'] ?? '' ),
				'timezone'   => (string) ( $location['timezone'] ?? '' ),
				'is_active'  => (int) ( $location['is_active'] ?? 0 ),
				'is_primary' => (int) ( $location['is_primary'] ?? 0 ),
			);
		}

		$clinicians = array();
		foreach ( $this->clinicians->list_participating_in_clinic( $clinic_id ) as $clinician ) {
			if ( (int) ( $clinician['clinic_id'] ?? 0 ) !== $clinic_id ) {
				continue;
			}
			$clinicians[] = array(
				'id'        => (int) ( $clinician['id'] ?? 0 ),
				'full_name' => (string) ( $clinician['full_name'] ?? '' ),
				'is_active' => (int) ( $clinician['is_active'] ?? 0 ),
			);
		}

		// Custom roles the actor may actually delegate inside this Clinic.
		$custom_roles = array();
		try {
			$assignable   = App::custom_role_assignment_service()->assignable_definitions( $actor_wp_user_id, $clinic_id );
			$custom_roles = $assignable['definitions'];
		} catch ( \Throwable $e ) {
			$custom_roles = array(); // fail closed: no delegation options
		}

		return array(
			'clinic'          => array(
				'id'              => $clinic_id,
				'name'            => (string) ( $clinic['name'] ?? '' ),
				'slug'            => (string) ( $clinic['slug'] ?? '' ),
				'address'         => (string) ( $clinic['address'] ?? '' ),
				'phone'           => (string) ( $clinic['phone'] ?? '' ),
				'timezone'        => (string) ( $clinic['timezone'] ?? '' ),
				'status'          => (string) ( $clinic['status'] ?? 'active' ),
				'organization_id' => (int) ( $clinic['organization_id'] ?? 0 ),
			),
			'locations'        => $locations,
			'staff'            => $staff,
			'clinicians'       => $clinicians,
			'assignable_roles' => self::ASSIGNABLE_BUILT_IN_ROLES,
			// Delegation vocabulary: only capabilities this actor actually holds in
			// this Clinic may be granted or denied here (no self-escalation).
			'delegable_caps'   => $this->delegable_caps( $actor_wp_user_id, $clinic_id ),
			'custom_roles'     => array_map(
				static fn( array $role ): array => array(
					'role_key'     => (string) ( $role['role_key'] ?? '' ),
					'display_name' => (string) ( $role['display_name'] ?? '' ),
				),
				is_array( $custom_roles ) ? $custom_roles : array()
			),
			'can_view_reports' => $this->authorization->can( $actor_wp_user_id, $clinic_id, self::CLINIC_REPORT_CAPABILITY ),
			'can_view_finance' => $this->authorization->can( $actor_wp_user_id, $clinic_id, RolesAndCapabilities::FINANCE_READ ),
		);
	}

	// ==================================================================
	// Authorized writes — each delegates to the existing domain service
	// ==================================================================

	/**
	 * Organization: create a Clinic (Organization authority + per-op DENY).
	 *
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, message:string}
	 */
	public function create_clinic( int $actor_wp_user_id, int $organization_id, array $input ): array {
		try {
			$result = $this->organizations->provision(
				$actor_wp_user_id,
				$organization_id,
				self::string_value( $input, 'name' ),
				self::string_value( $input, 'slug' ),
				self::string_value( $input, 'timezone' ),
				self::nullable_string_value( $input, 'location_name' ),
				self::nullable_string_value( $input, 'address' ),
				self::nullable_string_value( $input, 'phone' )
			);
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array(
			'ok'      => true,
			'message' => 'درمانگاه ایجاد شد (شناسه: ' . (int) ( $result['clinic_id'] ?? 0 ) . ').',
		);
	}

	/**
	 * Organization: update Clinic profile (name/address/phone).
	 *
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, message:string}
	 */
	public function update_clinic_profile( int $actor_wp_user_id, int $organization_id, int $clinic_id, array $input ): array {
		try {
			$this->organizations->update_clinic_profile( $actor_wp_user_id, $organization_id, $clinic_id, $input );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'پروفایل درمانگاه به‌روزرسانی شد.' );
	}

	/**
	 * Organization: activate/deactivate a Clinic (history is preserved).
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function set_clinic_status( int $actor_wp_user_id, int $organization_id, int $clinic_id, bool $active ): array {
		try {
			$this->organizations->set_clinic_status( $actor_wp_user_id, $organization_id, $clinic_id, $active );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array(
			'ok'      => true,
			'message' => $active ? 'درمانگاه فعال شد.' : 'درمانگاه غیرفعال شد (سابقه حفظ می‌شود).',
		);
	}

	/**
	 * Organization: designate an explicit Clinic manager.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function designate_clinic_manager( int $actor_wp_user_id, int $organization_id, int $clinic_id, int $target_wp_user_id ): array {
		try {
			$this->organizations->designate_clinic_manager( $actor_wp_user_id, $organization_id, $clinic_id, $target_wp_user_id );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'مدیر درمانگاه تعیین شد.' );
	}

	/**
	 * Organization: grant/revoke an Organization manager membership.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function set_organization_manager( int $actor_wp_user_id, int $organization_id, int $target_wp_user_id, bool $active ): array {
		try {
			$this->organizations->set_manager( $actor_wp_user_id, $organization_id, $target_wp_user_id, $active );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'عضویت مدیر سازمان ثبت شد.' );
	}

	/**
	 * Clinic: update the Clinic profile through the Clinic-scoped service
	 * (ACTIVE membership + CONFIG in the explicitly selected Clinic).
	 *
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, message:string}
	 */
	public function update_clinic_settings( int $actor_wp_user_id, int $clinic_id, array $input ): array {
		try {
			$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id );
			if ( $trusted === null ) {
				return self::failure();
			}
			App::clinicProfileService()->updateProfile( $actor_wp_user_id, $trusted, $input );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'تنظیمات درمانگاه به‌روزرسانی شد.' );
	}

	/**
	 * Clinic: create a Location inside the trusted Clinic.
	 *
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, message:string}
	 */
	public function create_location( int $actor_wp_user_id, int $clinic_id, array $input ): array {
		try {
			$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id );
			if ( $trusted === null ) {
				return self::failure();
			}
			App::locationService()->createLocation( $actor_wp_user_id, $trusted, $input );
		} catch ( LocationException $e ) {
			return self::failure();
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'شعبه ایجاد شد.' );
	}

	/**
	 * Clinic: update a Location that durably belongs to the trusted Clinic.
	 *
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, message:string}
	 */
	public function update_location( int $actor_wp_user_id, int $clinic_id, int $location_id, array $input ): array {
		try {
			$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id );
			if ( $trusted === null ) {
				return self::failure();
			}
			$location = $this->locations->find( $location_id );
			if ( $location === null || (int) ( $location['clinic_id'] ?? 0 ) !== $trusted ) {
				return self::failure(); // durable ownership mismatch / forged id
			}
			App::locationService()->updateLocation( $actor_wp_user_id, $trusted, $location_id, $input );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'شعبه به‌روزرسانی شد.' );
	}

	/**
	 * Clinic: activate/deactivate a Location of the trusted Clinic.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function set_location_active( int $actor_wp_user_id, int $clinic_id, int $location_id, bool $active ): array {
		try {
			$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id );
			if ( $trusted === null ) {
				return self::failure();
			}
			$location = $this->locations->find( $location_id );
			if ( $location === null || (int) ( $location['clinic_id'] ?? 0 ) !== $trusted ) {
				return self::failure();
			}
			App::locationService()->set_active( $actor_wp_user_id, $trusted, $location_id, $active );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'وضعیت شعبه به‌روزرسانی شد.' );
	}

	/**
	 * Clinic: assign an existing ACTIVE member a built-in CPMS membership role.
	 *
	 * Authority: ACTIVE actor membership + CONFIG in the trusted Clinic, and the
	 * target membership's Clinic is read from durable storage and re-checked
	 * with authorizeForObject before the write. WordPress-global roles are never
	 * touched: the membership role_key is Clinic-local data.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function assign_built_in_role( int $actor_wp_user_id, int $clinic_id, int $target_wp_user_id, string $role_key ): array {
		if ( ! in_array( $role_key, self::ASSIGNABLE_BUILT_IN_ROLES, true ) ) {
			return self::failure(); // never a WordPress role, never an unknown key
		}

		try {
			$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id, true );
			if ( $trusted === null ) {
				return self::failure();
			}
			$membership = $this->memberships->find( $trusted, $target_wp_user_id );
			if ( $membership === null || (string) ( $membership['status'] ?? '' ) !== 'active' ) {
				return self::failure();
			}
			$this->authorization->authorizeForObject(
				$actor_wp_user_id,
				$trusted,
				self::CLINIC_MANAGE_CAPABILITY,
				(int) ( $membership['clinic_id'] ?? 0 )
			);
			App::membership_service()->set_role_key( (int) $membership['id'], $role_key );
		} catch ( AuthorizationException $e ) {
			return self::failure();
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'نقش عضویت به‌روزرسانی شد.' );
	}

	/**
	 * Clinic: assign an ACTIVE Clinic-local custom role (delegation limits are
	 * enforced inside CustomRoleAssignmentService).
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function assign_custom_role( int $actor_wp_user_id, int $clinic_id, int $target_wp_user_id, string $role_key ): array {
		if ( ! CustomRolePolicy::is_well_formed_role_key( $role_key ) ) {
			return self::failure();
		}

		try {
			App::custom_role_assignment_service()->assign( $actor_wp_user_id, $clinic_id, $target_wp_user_id, $role_key );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'نقش سفارشی اختصاص یافت.' );
	}

	/**
	 * Clinic: enroll a staff member by linking an existing WordPress account or
	 * provisioning a brand-new plain Subscriber account.
	 *
	 * Safety contract:
	 *  - Clinic management capability + durable ACTIVE membership in the same
	 *    Clinic are required before anything is created;
	 *  - the only WordPress role this method can ever assign is `subscriber`;
	 *    an existing account is linked, never re-roled, and privileged accounts
	 *    (administrator included) receive no CPMS authority from linking —
	 *    authority still comes from the durable membership + scoped engine;
	 *  - an existing membership is never silently overwritten (fail closed);
	 *  - the new membership role is a CPMS membership role, never a WP role.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function enroll_staff( int $actor_wp_user_id, int $clinic_id, string $identifier, string $role_key ): array {
		$identifier = trim( $identifier );
		if ( '' === $identifier || ! in_array( $role_key, self::ASSIGNABLE_BUILT_IN_ROLES, true ) ) {
			return self::failure();
		}

		try {
			$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id, true );
			if ( $trusted === null ) {
				return self::failure();
			}
			$this->authorization->authorize( $actor_wp_user_id, $trusted, self::CLINIC_MANAGE_CAPABILITY, true );

			$target = $this->resolve_or_provision_subscriber( $identifier );
			if ( $target <= 0 ) {
				return self::failure();
			}
			if ( $this->memberships->find( $trusted, $target ) !== null ) {
				return self::failure(); // never overwrite an existing membership
			}
			App::membership_service()->create_membership(
				$trusted,
				$target,
				$role_key,
				'clinic', // MembershipService::SCOPE_CLINIC (Clinic-wide membership).
				$actor_wp_user_id
			);
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'کاربر به درمانگاه افزوده شد.' );
	}

	/**
	 * Clinic: activate or suspend one membership of this Clinic.
	 *
	 * Suspension is a durable deny (AuthorizationService requires an ACTIVE
	 * membership), so a suspended member keeps history but loses authority.
	 * Acting on your own membership is refused to avoid lock-out ambiguity.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function set_staff_status( int $actor_wp_user_id, int $clinic_id, int $membership_id, bool $active ): array {
		try {
			$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id, true );
			if ( $trusted === null ) {
				return self::failure();
			}
			$this->authorization->authorizeForObject( $actor_wp_user_id, $trusted, self::CLINIC_MANAGE_CAPABILITY, $trusted );
			$membership = $this->membership_in_clinic( $trusted, $membership_id );
			if ( $membership === null || (int) ( $membership['wp_user_id'] ?? 0 ) === $actor_wp_user_id ) {
				return self::failure();
			}

			if ( $active ) {
				App::membership_service()->reactivate_membership( $membership_id );
			} else {
				App::membership_service()->suspend_membership( $membership_id );
			}
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => $active ? 'عضویت فعال شد.' : 'عضویت معلق شد.' );
	}

	/**
	 * Clinic: set the Location scope of one membership (Clinic-wide or an
	 * explicit, verified subset of this Clinic's ACTIVE Locations).
	 *
	 * @param list<int> $location_ids
	 * @return array{ok:bool, message:string}
	 */
	public function set_staff_locations( int $actor_wp_user_id, int $clinic_id, int $membership_id, array $location_ids ): array {
		$location_ids = array_values( array_unique( array_filter( array_map( 'intval', $location_ids ), static fn( int $id ): bool => $id > 0 ) ) );

		try {
			$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id, true );
			if ( $trusted === null ) {
				return self::failure();
			}
			$this->authorization->authorize( $actor_wp_user_id, $trusted, self::CLINIC_MANAGE_CAPABILITY, true );
			$membership = $this->membership_in_clinic( $trusted, $membership_id );
			if ( $membership === null ) {
				return self::failure();
			}
			// Every Location must be real, ACTIVE and owned by this Clinic.
			foreach ( $location_ids as $location_id ) {
				if ( $this->locations->findActiveForClinic( $trusted, $location_id ) === null ) {
					return self::failure();
				}
			}

			if ( array() === $location_ids ) {
				App::membership_service()->set_scope_mode( $membership_id, 'clinic' );
			} else {
				App::membership_service()->set_scope_mode( $membership_id, 'location', $location_ids );
			}
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'دامنهٔ مکانی عضویت ذخیره شد.' );
	}

	/**
	 * Clinic: record an explicit capability GRANT / DENY on one membership, or
	 * clear an override so the role preset applies again.
	 *
	 * Delegation bound: the actor must already hold the same capability in this
	 * Clinic. Without that, a manager could hand out (or deny) authority it never
	 * had, which is an escalation. DENY still outranks GRANT and preset.
	 *
	 * @param string $effect One of `grant`, `deny`, `clear`.
	 * @return array{ok:bool, message:string}
	 */
	public function set_staff_capability( int $actor_wp_user_id, int $clinic_id, int $membership_id, string $capability, string $effect ): array {
		if ( ! in_array( $capability, RolesAndCapabilities::ALL_CAPS, true ) || ! in_array( $effect, array( 'grant', 'deny', 'clear' ), true ) ) {
			return self::failure();
		}

		try {
			$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id, true );
			if ( $trusted === null ) {
				return self::failure();
			}
			$membership = $this->membership_in_clinic( $trusted, $membership_id );
			if ( $membership === null || (string) ( $membership['status'] ?? '' ) !== 'active' ) {
				return self::failure();
			}
			if ( ! $this->authorization->can( $actor_wp_user_id, $trusted, $capability, true ) ) {
				return self::failure(); // no delegation beyond your own authority
			}

			if ( 'clear' === $effect ) {
				App::membership_service()->remove_capability( $membership_id, $capability );
			} else {
				App::membership_service()->set_capability( $membership_id, $capability, $effect );
			}
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'مجوز عضویت به‌روزرسانی شد.' );
	}

	/**
	 * Clinic: register a clinician record owned by this Clinic.
	 *
	 * A clinician optionally linked to a WordPress account must already hold an
	 * ACTIVE staff membership in the SAME Clinic, and one WordPress account can
	 * be linked to at most one clinician record (1:1 invariant). Multi-Clinic
	 * participation is therefore expressed as one clinician row per Clinic, each
	 * with its own durable ownership.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function register_clinician( int $actor_wp_user_id, int $clinic_id, string $full_name, string $specialty, int $wp_user_id = 0 ): array {
		$full_name = trim( $full_name );
		if ( '' === $full_name ) {
			return self::failure();
		}

		try {
			$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id, true );
			if ( $trusted === null ) {
				return self::failure();
			}
			$this->authorization->authorize( $actor_wp_user_id, $trusted, self::CLINIC_MANAGE_CAPABILITY, true );

			$linked = 0;
			if ( $wp_user_id > 0 ) {
				$membership = $this->memberships->find( $trusted, $wp_user_id );
				if ( $membership === null || (string) ( $membership['status'] ?? '' ) !== 'active' ) {
					return self::failure();
				}
				if ( $this->clinicians->isUserLinked( $wp_user_id ) ) {
					return self::failure();
				}
				$linked = $wp_user_id;
			}

			$this->clinicians->create(
				$trusted,
				array(
					'full_name'   => $full_name,
					'specialty'   => '' !== trim( $specialty ) ? trim( $specialty ) : null,
					'wp_user_id'  => $linked > 0 ? $linked : null,
					'is_active'   => 1,
				)
			);
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'پزشک ثبت شد.' );
	}

	/**
	 * Clinic: create a weekly schedule entry through the published
	 * ScheduleService contract (same conflict, overlap and lifecycle rules as
	 * REST). The trusted Clinic scope is established here because the service
	 * reads it from ScopeContext and never from the payload.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function create_schedule( int $actor_wp_user_id, int $clinic_id, int $clinician_id, int $location_id, int $day_of_week, string $start_time, string $end_time ): array {
		if ( $clinician_id <= 0 || $location_id <= 0 || $day_of_week < 0 || $day_of_week > 6 ) {
			return self::failure();
		}

		try {
			$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id, true );
			if ( $trusted === null ) {
				return self::failure();
			}
			$this->authorization->authorize( $actor_wp_user_id, $trusted, self::CLINIC_MANAGE_CAPABILITY, true );
			if ( $this->locations->findActiveForClinic( $trusted, $location_id ) === null ) {
				return self::failure();
			}
			if ( ! $this->memberships->clinician_participates_in( $clinician_id, $trusted ) ) {
				return self::failure();
			}

			$previous = ScopeContext::tryGet();
			try {
				ScopeContext::set( ClinicScope::forClinic( $trusted, $location_id ) );
				App::scheduleService()->create(
					$actor_wp_user_id,
					array(
						'clinician_id' => $clinician_id,
						'location_id'  => $location_id,
						'day_of_week'  => $day_of_week,
						'start_time'   => $start_time,
						'end_time'     => $end_time,
						'is_active'    => 1,
					)
				);
			} finally {
				if ( $previous === null ) {
					ScopeContext::clear();
				} else {
					ScopeContext::set( $previous );
				}
			}
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'برنامهٔ هفتگی ثبت شد.' );
	}

	/**
	 * Capabilities this actor may grant or deny inside the Clinic.
	 *
	 * @return list<string>
	 */
	private function delegable_caps( int $actor_wp_user_id, int $clinic_id ): array {
		$held = array();
		foreach ( RolesAndCapabilities::ALL_CAPS as $capability ) {
			if ( $this->authorization->can( $actor_wp_user_id, $clinic_id, $capability ) ) {
				$held[] = $capability;
			}
		}

		return $held;
	}

	/**
	 * Durable membership row, re-read from storage and required to belong to the
	 * trusted Clinic (the id submitted by the browser is only a selector).
	 *
	 * @return array<string,mixed>|null
	 */
	private function membership_in_clinic( int $trusted_clinic_id, int $membership_id ): ?array {
		if ( $membership_id <= 0 ) {
			return null;
		}
		$membership = $this->memberships->find_by_id( $membership_id );
		if ( $membership === null || (int) ( $membership['clinic_id'] ?? 0 ) !== $trusted_clinic_id ) {
			return null;
		}

		return $membership;
	}

	/**
	 * Resolve an existing account by e-mail/login, or provision a plain
	 * Subscriber account for a new e-mail address. Nothing else can be created
	 * here: no role other than `subscriber`, no password disclosure, no e-mail.
	 */
	private function resolve_or_provision_subscriber( string $identifier ): int {
		if ( ! is_email( $identifier ) ) {
			$by_login = get_user_by( 'login', $identifier );
			if ( $by_login instanceof WP_User ) {
				return (int) $by_login->ID;
			}

			return 0; // never provision from a login string
		}

		$existing = get_user_by( 'email', $identifier );
		if ( $existing instanceof WP_User ) {
			return (int) $existing->ID;
		}

		$local = strtolower( (string) strstr( $identifier, '@', true ) );
		$local = preg_replace( '/[^a-z0-9._-]/', '', $local );
		if ( ! is_string( $local ) || '' === $local ) {
			return 0;
		}

		$login = $local;
		$suffix = 1;
		while ( get_user_by( 'login', $login ) instanceof WP_User || username_exists( $login ) ) {
			++$suffix;
			if ( $suffix > 50 ) {
				return 0; // fail closed instead of guessing forever
			}
			$login = $local . '-' . (string) $suffix;
		}

		$created = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $identifier,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $local,
				'role'         => 'subscriber', // the only WP role this path can assign
			)
		);

		return is_int( $created ) ? $created : 0;
	}

	// ==================================================================
	// Helpers
	// ==================================================================

	/**
	 * Resolve the trusted Clinic from durable ACTIVE membership. The requested
	 * value is only a selector; it is never authorization by itself.
	 */
	private function trusted_clinic( int $actor_wp_user_id, int $clinic_id, bool $for_update = false ): ?int {
		if ( $actor_wp_user_id <= 0 || $clinic_id <= 0 ) {
			return null;
		}
		try {
			$scope = $this->scope->establish( $actor_wp_user_id, $clinic_id, null, $for_update );
		} catch ( ScopeRequiredException $e ) {
			return null;
		}

		return $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the existing published scope API.
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private static function string_value( array $input, string $key ): string {
		$value = $input[ $key ] ?? '';

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private static function nullable_string_value( array $input, string $key ): ?string {
		$value = $input[ $key ] ?? null;
		if ( $value === null ) {
			return null;
		}
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$string = trim( (string) $value );

		return '' === $string ? null : $string;
	}

	/**
	 * Uniform, non-disclosing failure. Never reveals tenant topology, whether a
	 * Clinic exists, or whether a membership is suspended.
	 *
	 * @return array{ok:bool, message:string}
	 */
	private static function failure(): array {
		return array(
			'ok'      => false,
			'message' => 'عملیات انجام نشد؛ دسترسی یا داده‌های ارسالی معتبر نیستند و هیچ تغییری ثبت نشد.',
		);
	}

	private function db(): \ClinicCore\Infrastructure\Db\CpmsDb {
		return App::db();
	}
}
