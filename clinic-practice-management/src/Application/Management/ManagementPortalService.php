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
use ClinicCore\Application\Onboarding\FacilitySetupException;
use ClinicCore\Application\Onboarding\OrganizationClinicProvisioningService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Geography\IranProvinceCityCatalog;
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
	 * Built-in staff role keys a Clinic Manager may assign through the portal —
	 * the APPROVED OPERATIONAL roles (doctors, receptionists/secretaries,
	 * accountants) plus the empty-preset patient membership role. WordPress
	 * roles (administrator, editor, …) are deliberately absent: portal role
	 * assignment is Clinic membership data, never a WordPress-global role.
	 *
	 * The Clinic Manager role is deliberately absent too: designating a Clinic
	 * Manager is EXPLICIT Organization authority
	 * (cpms_org_clinic_manager_assign, OrganizationClinicProvisioningService)
	 * and is never delegated through the staff surface — and nothing on this
	 * path ever touches cpms_organization_memberships, so no Organization
	 * Manager delegation can happen here.
	 *
	 * @var list<string>
	 */
	public const ASSIGNABLE_BUILT_IN_ROLES = array(
		RolesAndCapabilities::ROLE_DOCTOR,
		RolesAndCapabilities::ROLE_SECRETARY,
		RolesAndCapabilities::ROLE_ACCOUNTANT,
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

		// Clinician visibility is the UNION of the Clinic's own professional rows
		// and out-of-Clinic profiles that hold an ACTIVE membership here. A
		// clinician serving several Clinics of the same Organization therefore
		// appears in every one of them without a duplicate profile row.
		$clinicians = array();
		$seen       = array();
		$candidates = array_merge(
			$this->clinicians->listAll( $clinic_id ),
			$this->clinicians->list_participating_in_clinic( $clinic_id )
		);
		foreach ( $candidates as $clinician ) {
			$clinician_id = (int) ( $clinician['id'] ?? 0 );
			if ( $clinician_id <= 0 || isset( $seen[ $clinician_id ] ) ) {
				continue;
			}
			$seen[ $clinician_id ] = true;
			// Participating rows are read from another Clinic and do not carry
			// clinic_id; the durable home Clinic is re-read instead of assumed.
			$home_clinic = (int) ( $clinician['clinic_id'] ?? 0 );
			if ( $home_clinic <= 0 ) {
				$profile = $this->clinicians->find( $clinician_id );
				$home_clinic = $profile === null ? 0 : (int) ( $profile['clinic_id'] ?? 0 );
			}
			$clinicians[] = array(
				'id'                  => $clinician_id,
				'full_name'           => (string) ( $clinician['full_name'] ?? '' ),
				'is_active'           => (int) ( $clinician['is_active'] ?? 1 ),
				'home_clinic'         => $home_clinic,
				'home_clinic_matches' => $home_clinic === $clinic_id,
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
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function create_clinic( int $actor_wp_user_id, int $organization_id, array $input ): array {
		try {
			// FIX 3 — Iranian address: validate province/city via offline catalog (Persian RTL, no external service).
			try {
				$__resolved = self::resolve_portal_address( $input );
				if ( null !== $__resolved ) {
					$input['address'] = $__resolved;
				}
			} catch ( \InvalidArgumentException $e ) {
				return self::failure();
			}
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
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function update_clinic_profile( int $actor_wp_user_id, int $organization_id, int $clinic_id, array $input ): array {
		try {
			// FIX 3 — Iranian address: validate province/city via offline catalog (Persian RTL, no external service).
			try {
				$__resolved = self::resolve_portal_address( $input );
				if ( null !== $__resolved ) {
					$input['address'] = $__resolved;
				}
			} catch ( \InvalidArgumentException $e ) {
				return self::failure();
			}
			$this->organizations->update_clinic_profile( $actor_wp_user_id, $organization_id, $clinic_id, $input );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'پروفایل درمانگاه به‌روزرسانی شد.' );
	}

	/**
	 * Organization: activate/deactivate a Clinic (history is preserved).
	 *
	 * @return array{ok:bool, message:string, notice?:string}
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
	 * @return array{ok:bool, message:string, notice?:string}
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
	 * @return array{ok:bool, message:string, notice?:string}
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
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function update_clinic_settings( int $actor_wp_user_id, int $clinic_id, array $input ): array {
		try {
			// FIX 3 — Iranian address: validate province/city via offline catalog (Persian RTL, no external service).
			try {
				$__resolved = self::resolve_portal_address( $input );
				if ( null !== $__resolved ) {
					$input['address'] = $__resolved;
				}
			} catch ( \InvalidArgumentException $e ) {
				return self::failure();
			}
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
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	/**
	 * Clinic: create a Location.
	 *
	 * Order is deliberate: trusted Clinic scope is established from durable
	 * membership FIRST, then the scoped management capability is authorized, and
	 * only then is any caller-controlled value examined.
	 *
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function create_location( int $actor_wp_user_id, int $clinic_id, array $input ): array {
		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id, true );
		if ( $trusted === null ) {
			return self::failure();
		}

		try {
			try {
				$__resolved = self::resolve_portal_address( $input );
				if ( null !== $__resolved ) {
					$input['address'] = $__resolved;
				}
			} catch ( \InvalidArgumentException $e ) {
				return self::failure();
			}

			App::locationService()->createLocation( $actor_wp_user_id, $trusted, $input );
		} catch ( LocationException $e ) {
			return self::failure();
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		$this->audit_change(
			'LOCATION_CREATED',
			$actor_wp_user_id,
			'location',
			null,
			null,
			array(
				'clinic_id' => $trusted,
				'name'      => self::string_value( $input, 'name' ),
				'slug'      => self::string_value( $input, 'slug' ),
			),
			$trusted
		);

		return array( 'ok' => true, 'message' => 'شعبه ایجاد شد.' );
	}

	/**
	 * Clinic: update a Location that durably belongs to the trusted Clinic.
	 *
	 * The scoped management capability is authorized BEFORE the target row is
	 * read, so an unauthorized actor cannot use this path as an existence probe.
	 *
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function update_location( int $actor_wp_user_id, int $clinic_id, int $location_id, array $input ): array {
		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id, true );
		if ( $trusted === null || $location_id <= 0 ) {
			return self::failure();
		}

		$location = array();
		try {
			try {
				$__resolved = self::resolve_portal_address( $input );
				if ( null !== $__resolved ) {
					$input['address'] = $__resolved;
				}
			} catch ( \InvalidArgumentException $e ) {
				return self::failure();
			}

			$found = $this->locations->find( $location_id );
			if ( $found === null || (int) ( $found['clinic_id'] ?? 0 ) !== $trusted ) {
				return self::failure(); // durable ownership mismatch / forged id
			}
			$location = $found;
			App::locationService()->updateLocation( $actor_wp_user_id, $trusted, $location_id, $input );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		$this->audit_change(
			'LOCATION_UPDATED',
			$actor_wp_user_id,
			'location',
			$location_id,
			array(
				'name'    => (string) ( $location['name'] ?? '' ),
				'address' => (string) ( $location['address'] ?? '' ),
				'phone'   => (string) ( $location['phone'] ?? '' ),
			),
			array(
				'name'    => self::string_value( $input, 'name' ),
				'address' => self::string_value( $input, 'address' ),
				'phone'   => self::string_value( $input, 'phone' ),
			),
			$trusted
		);

		return array( 'ok' => true, 'message' => 'شعبه به‌روزرسانی شد.' );
	}

	/**
	 * Clinic: activate/deactivate a Location of the trusted Clinic.
	 *
	 * Capability is authorized before the row is read.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function set_location_active( int $actor_wp_user_id, int $clinic_id, int $location_id, bool $active ): array {
		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id, true );
		if ( $trusted === null || $location_id <= 0 ) {
			return self::failure();
		}

		$location = array();
		try {
			$found = $this->locations->find( $location_id );
			if ( $found === null || (int) ( $found['clinic_id'] ?? 0 ) !== $trusted ) {
				return self::failure();
			}
			$location = $found;
			App::locationService()->set_active( $actor_wp_user_id, $trusted, $location_id, $active );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		$this->audit_change(
			$active ? 'LOCATION_ACTIVATED' : 'LOCATION_DEACTIVATED',
			$actor_wp_user_id,
			'location',
			$location_id,
			array( 'is_active' => (int) ( $location['is_active'] ?? 0 ) ),
			array( 'is_active' => $active ? 1 : 0 ),
			$trusted
		);

		return array( 'ok' => true, 'message' => 'وضعیت شعبه به‌روزرسانی شد.' );
	}

	/**
	 * Clinic: assign an existing ACTIVE member a built-in CPMS membership role.
	 *
	 * Security contract (review P0-2, corrected role-assignment policy 2026-10-21):
	 *  - trusted Clinic scope + scoped staff-management capability are authorized
	 *    BEFORE the target membership is read (no probing, no ordering leak);
	 *  - an actor may never change its OWN membership role (no self-escalation);
	 *  - delegation follows the precise, auditable role-assignment policy
	 *    (may_assign_role): explicit staff-management authority in this Clinic
	 *    may hand out the APPROVED OPERATIONAL roles without possessing every
	 *    clinical or financial capability being delegated. The assignment
	 *    confers exactly the role preset — never an arbitrary capability set,
	 *    never a manager/manager designation, never a WordPress role.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function assign_built_in_role( int $actor_wp_user_id, int $clinic_id, int $target_wp_user_id, string $role_key ): array {
		if ( ! $this->may_assign_role( $role_key ) ) {
			// Input-level refusal: unknown/non-operational role keys (including
			// manager and any WordPress role) are never assignable here.
			return self::invalid( 'نقش انتخابی از این صفحه قابل انتساب نیست؛ انتساب مدیر درمانگاه فقط با اختیار سازمانی و از گام تعیین مدیر انجام می‌شود.' );
		}
		if ( $target_wp_user_id <= 0 ) {
			return self::invalid( 'کاربر هدف باید صریحاً انتخاب شود.' );
		}
		if ( $target_wp_user_id === $actor_wp_user_id ) {
			return self::invalid( 'تغییر نقش عضویت خودتان مجاز نیست.' ); // self-role changes are never allowed here.
		}

		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id, true );
		if ( $trusted === null ) {
			return self::failure();
		}

		$membership = array();
		try {
			$membership = (array) $this->memberships->find( $trusted, $target_wp_user_id );
			if ( $membership === null || (string) ( $membership['status'] ?? '' ) !== 'active' ) {
				return self::failure();
			}
			if ( ! $this->staff_target_is_eligible( $target_wp_user_id ) ) {
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
			return self::operational_failure();
		}

		$this->audit_change(
			'MEMBERSHIP_ROLE_CHANGED',
			$actor_wp_user_id,
			'membership',
			(int) $membership['id'],
			array( 'role_key' => (string) ( $membership['role_key'] ?? '' ) ),
			array( 'role_key' => $role_key, 'target_wp_user_id' => $target_wp_user_id ),
			$trusted
		);

		return array( 'ok' => true, 'message' => 'نقش عضویت به‌روزرسانی شد.' );
	}

	/**
	 * Clinic: assign an ACTIVE Clinic-local custom role (delegation limits are
	 * enforced inside CustomRoleAssignmentService; capability is authorized here
	 * before the call, and the assignment is audited).
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function assign_custom_role( int $actor_wp_user_id, int $clinic_id, int $target_wp_user_id, string $role_key ): array {
		if ( ! CustomRolePolicy::is_well_formed_role_key( $role_key ) || $target_wp_user_id <= 0 ) {
			return self::failure();
		}

		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id, true );
		if ( $trusted === null ) {
			return self::failure();
		}

		try {
			App::custom_role_assignment_service()->assign( $actor_wp_user_id, $trusted, $target_wp_user_id, $role_key );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		$this->audit_change(
			'CUSTOM_ROLE_ASSIGNED',
			$actor_wp_user_id,
			'membership',
			null,
			null,
			array(
				'clinic_id'        => $trusted,
				'target_wp_user_id' => $target_wp_user_id,
				'role_key'         => $role_key,
			),
			$trusted
		);

		return array( 'ok' => true, 'message' => 'نقش سفارشی اختصاص یافت.' );
	}

	/**
	 * Clinic: enroll a staff member by linking an existing WordPress account or
	 * provisioning a brand-new plain Subscriber account.
	 *
	 * Safety contract (corrected role-assignment policy 2026-10-21):
	 *  - trusted Clinic scope + scoped staff-management capability are authorized
	 *    BEFORE the target account is resolved or provisioned;
	 *  - delegation follows may_assign_role(): the approved operational roles
	 *    (doctor, secretary/receptionist, accountant) and the patient membership
	 *    role are assignable on staff-management authority ALONE — the Clinic
	 *    Manager does not need to hold the clinical/financial capabilities being
	 *    delegated (they come from the role preset, never an arbitrary set);
	 *  - manager/manager designation is never assignable here (explicit
	 *    Organization authority only) and no Organization membership is touched;
	 *  - the only WordPress role this method can ever assign is `subscriber`; an
	 *    existing account is linked, never re-roled, and a WordPress
	 *    Administrator is never a CPMS staff target;
	 *  - an existing membership is never silently overwritten (fail closed);
	 *  - the write is audited (hash-chained log).
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function enroll_staff( int $actor_wp_user_id, int $clinic_id, string $identifier, string $role_key ): array {
		$identifier = trim( $identifier );
		if ( '' === $identifier ) {
			return self::invalid( 'نام کاربری یا ایمیل کاربر الزامی است.' );
		}
		if ( ! $this->may_assign_role( $role_key ) ) {
			return self::invalid( 'نقش انتخابی برای ثبت کارکنان مجاز نیست؛ نقش‌های مجاز پزشک، منشی/پذیرش و حسابدار هستند (انتساب مدیر درمانگاه فقط با اختیار سازمانی).' );
		}

		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id, true );
		if ( $trusted === null ) {
			return self::failure();
		}

		$membership_id = 0;
		$target        = 0;
		try {
			$target = $this->resolve_or_provision_subscriber( $identifier );
			if ( $target <= 0 ) {
				return self::invalid( 'کاربری با این نام کاربری/ایمیل یافت نشد و ساخت حساب فقط با ایمیل معتبر انجام می‌شود.' );
			}
			if ( $target === $actor_wp_user_id ) {
				return self::invalid( 'افزودن خودتان به کارکنان این درمانگاه مجاز نیست.' );
			}
			if ( ! $this->staff_target_is_eligible( $target ) ) {
				return self::failure();
			}
			if ( $this->memberships->find( $trusted, $target ) !== null ) {
				return self::prerequisite( 'این کاربر از قبل در همین درمانگاه عضویت دارد؛ برای تغییر نقش یا وضعیت از فهرست کارکنان همان درمانگاه اقدام کنید.' );
			}
			$membership_id = App::membership_service()->create_membership(
				$trusted,
				$target,
				$role_key,
				'clinic', // MembershipService::SCOPE_CLINIC (Clinic-wide membership).
				$actor_wp_user_id
			);
		} catch ( AuthorizationException $e ) {
			return self::failure();
		} catch ( \Throwable $e ) {
			return self::operational_failure();
		}

		$this->audit_change(
			'STAFF_MEMBERSHIP_CREATED',
			$actor_wp_user_id,
			'membership',
			$membership_id,
			null,
			array(
				'clinic_id'        => $trusted,
				'target_wp_user_id' => $target,
				'role_key'         => $role_key,
				'scope_mode'       => 'clinic',
			),
			$trusted
		);

		return array( 'ok' => true, 'message' => 'کاربر به درمانگاه افزوده شد.' );
	}

	/**
	 * Clinic: activate or suspend one membership of this Clinic.
	 *
	 * Capability is authorized before the membership row is read; acting on your
	 * own membership is refused (no self-suspension lock-out, no self-reactivation).
	 * Suspension is a durable deny because AuthorizationService requires ACTIVE.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function set_staff_status( int $actor_wp_user_id, int $clinic_id, int $membership_id, bool $active ): array {
		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id, true );
		if ( $trusted === null ) {
			return self::failure();
		}

		$membership = array();
		try {
			$found = $this->membership_in_clinic( $trusted, $membership_id );
			if ( $found === null || (int) ( $found['wp_user_id'] ?? 0 ) === $actor_wp_user_id ) {
				return self::failure();
			}
			$membership = $found;

			if ( $active ) {
				App::membership_service()->reactivate_membership( $membership_id );
			} else {
				App::membership_service()->suspend_membership( $membership_id );
			}
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		$this->audit_change(
			$active ? 'MEMBERSHIP_REACTIVATED' : 'MEMBERSHIP_SUSPENDED',
			$actor_wp_user_id,
			'membership',
			$membership_id,
			array( 'status' => (string) ( $membership['status'] ?? '' ) ),
			array(
				'status'            => $active ? 'active' : 'suspended',
				'clinic_id'         => $trusted,
				'target_wp_user_id' => (int) ( $membership['wp_user_id'] ?? 0 ),
			),
			$trusted
		);

		return array( 'ok' => true, 'message' => $active ? 'عضویت فعال شد.' : 'عضویت معلق شد.' );
	}

	/**
	 * Clinic: set the Location scope of one membership (Clinic-wide or an
	 * explicit, verified subset of this Clinic's ACTIVE Locations).
	 *
	 * Capability is authorized before the membership row is read.
	 *
	 * @param list<int> $location_ids
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function set_staff_locations( int $actor_wp_user_id, int $clinic_id, int $membership_id, array $location_ids ): array {
		$location_ids = array_values( array_unique( array_filter( array_map( 'intval', $location_ids ), static fn( int $id ): bool => $id > 0 ) ) );

		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id, true );
		if ( $trusted === null ) {
			return self::failure();
		}

		$before     = array();
		$membership = array();
		try {
			$found = $this->membership_in_clinic( $trusted, $membership_id );
			if ( $found === null ) {
				return self::failure();
			}
			$membership = $found;
			$before = array(
				'scope_mode'   => (string) ( $membership['scope_mode'] ?? '' ),
				'location_ids' => array_values( array_map( 'intval', $this->memberships->location_ids_for( $membership_id ) ) ),
			);

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

		$this->audit_change(
			'MEMBERSHIP_SCOPE_CHANGED',
			$actor_wp_user_id,
			'membership',
			$membership_id,
			$before,
			array(
				'scope_mode'        => array() === $location_ids ? 'clinic' : 'location',
				'location_ids'      => $location_ids,
				'clinic_id'         => $trusted,
				'target_wp_user_id' => (int) ( $membership['wp_user_id'] ?? 0 ),
			),
			$trusted
		);

		return array( 'ok' => true, 'message' => 'دامنهٔ مکانی عضویت ذخیره شد.' );
	}

	/**
	 * Clinic: record an explicit capability GRANT / DENY on one membership, or
	 * clear an override so the role preset applies again.
	 *
	 * Security contract (review P0-1):
	 *  - scoped management capability (`cpms_config`) is authorized BEFORE any
	 *    membership lookup, so a non-manager holding some other capability can
	 *    never mutate a membership;
	 *  - the capability being granted or denied must also be effective for the
	 *    actor in this Clinic (no delegation beyond own authority);
	 *  - DENY > GRANT > preset is unchanged;
	 *  - every mutation is audited with before/after state.
	 *
	 * @param string $effect One of `grant`, `deny`, `clear`.
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function set_staff_capability( int $actor_wp_user_id, int $clinic_id, int $membership_id, string $capability, string $effect ): array {
		if ( ! in_array( $capability, RolesAndCapabilities::ALL_CAPS, true ) || ! in_array( $effect, array( 'grant', 'deny', 'clear' ), true ) ) {
			return self::failure();
		}

		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id, true );
		if ( $trusted === null ) {
			return self::failure();
		}
		if ( ! $this->authorization->can( $actor_wp_user_id, $trusted, $capability, true ) ) {
			return self::failure(); // no delegation beyond your own authority
		}

		$before     = array();
		$membership = array();
		try {
			$found = $this->membership_in_clinic( $trusted, $membership_id );
			if ( $found === null || (string) ( $found['status'] ?? '' ) !== 'active' ) {
				return self::failure();
			}
			$membership = $found;
			$before     = array(
				'effect'       => $this->capability_effect( $membership_id, $capability ),
				'target_user'  => (int) ( $membership['wp_user_id'] ?? 0 ),
			);

			if ( 'clear' === $effect ) {
				App::membership_service()->remove_capability( $membership_id, $capability );
			} else {
				App::membership_service()->set_capability( $membership_id, $capability, $effect );
			}
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		$this->audit_change(
			'MEMBERSHIP_CAPABILITY_CHANGED',
			$actor_wp_user_id,
			'membership',
			$membership_id,
			$before,
			array(
				'capability'        => $capability,
				'effect'            => 'clear' === $effect ? '' : $effect,
				'clinic_id'         => $trusted,
				'target_wp_user_id' => (int) ( $membership['wp_user_id'] ?? 0 ),
			),
			$trusted
		);

		return array( 'ok' => true, 'message' => 'مجوز عضویت به‌روزرسانی شد.' );
	}

	/**
	 * Clinic: register a clinician record owned by this Clinic.
	 *
	 * A clinician optionally linked to a WordPress account must already hold an
	 * ACTIVE staff membership in the SAME Clinic. One WordPress account keeps a
	 * single professional profile (1:1); participation in further Clinics of the
	 * same Organization reuses that profile through the membership, never a second
	 * profile row and never a WordPress role change.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function register_clinician( int $actor_wp_user_id, int $clinic_id, string $full_name, string $specialty, int $wp_user_id = 0 ): array {
		$full_name = trim( $full_name );

		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id, true );
		if ( $trusted === null ) {
			return self::failure();
		}
		if ( '' === $full_name && $wp_user_id <= 0 ) {
			return self::failure();
		}

		$clinician_id = 0;
		try {
			if ( $wp_user_id > 0 ) {
				$membership = $this->memberships->find( $trusted, $wp_user_id );
				if ( $membership === null || (string) ( $membership['status'] ?? '' ) !== 'active' ) {
					return self::failure();
				}
				// Existing profile: adopt it only inside the same Organization so a
				// clinician can serve several Clinics without a duplicate row.
				$existing = $this->clinicians->find_by_wp_user( $wp_user_id );
				if ( $existing !== null ) {
					$home_clinic = (int) ( $existing['clinic_id'] ?? 0 );
					$home        = $home_clinic > 0 ? $this->clinics->find( $home_clinic ) : null;
					$target      = $this->clinics->find( $trusted );
					if ( $home === null || $target === null
						|| (int) ( $home['organization_id'] ?? 0 ) !== (int) ( $target['organization_id'] ?? 0 ) ) {
						return self::failure(); // cross-Organization sharing is unsupported
					}
					return array( 'ok' => true, 'message' => 'پروفایل حرفه‌ای موجود به این درمانگاه متصل است.' );
				}
				$clinician_id = $this->clinicians->create(
					$trusted,
					array(
						'full_name'  => $full_name,
						'specialty'  => '' !== trim( $specialty ) ? trim( $specialty ) : null,
						'wp_user_id' => $wp_user_id,
						'is_active'  => 1,
					)
				);
			} else {
				$clinician_id = $this->clinicians->create(
					$trusted,
					array(
						'full_name'  => $full_name,
						'specialty'  => '' !== trim( $specialty ) ? trim( $specialty ) : null,
						'wp_user_id' => null,
						'is_active'  => 1,
					)
				);
			}
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		$this->audit_change(
			'CLINICIAN_CREATED',
			$actor_wp_user_id,
			'clinician',
			$clinician_id,
			null,
			array(
				'clinic_id'  => $trusted,
				'full_name'  => $full_name,
				'specialty'  => $specialty,
				'wp_user_id' => $wp_user_id,
			),
			$trusted
		);

		return array( 'ok' => true, 'message' => 'پزشک ثبت شد.' );
	}

	/**
	 * Clinic: create a weekly schedule entry through the published
	 * ScheduleService contract (same conflict, overlap and lifecycle rules as
	 * REST). Capability is authorized before any object is read; the trusted
	 * Clinic scope is established here because the service reads it from
	 * ScopeContext and never from the payload.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function create_schedule( int $actor_wp_user_id, int $clinic_id, int $clinician_id, int $location_id, int $day_of_week, string $start_time, string $end_time ): array {
		if ( $clinician_id <= 0 || $location_id <= 0 || $day_of_week < 0 || $day_of_week > 6 ) {
			return self::failure();
		}

		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id, true );
		if ( $trusted === null ) {
			return self::failure();
		}

		$created = 0;
		try {
			if ( $this->locations->findActiveForClinic( $trusted, $location_id ) === null ) {
				return self::failure();
			}
			if ( ! $this->memberships->clinician_participates_in( $clinician_id, $trusted ) ) {
				return self::failure();
			}

			$previous = ScopeContext::tryGet();
			try {
				ScopeContext::set( ClinicScope::forClinic( $trusted, $location_id ) );
				$view    = App::scheduleService()->create(
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
				$created = (int) ( $view['id'] ?? 0 );
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

		$this->audit_change(
			'SCHEDULE_CREATED',
			$actor_wp_user_id,
			'schedule',
			$created,
			null,
			array(
				'clinic_id'    => $trusted,
				'clinician_id' => $clinician_id,
				'location_id'  => $location_id,
				'day_of_week'  => $day_of_week,
				'start_time'   => $start_time,
				'end_time'     => $end_time,
			),
			$trusted
		);

		return array( 'ok' => true, 'message' => 'برنامهٔ هفتگی ثبت شد.' );
	}

	/**
	 * Establish the trusted Clinic from durable membership and authorize the
	 * scoped management capability BEFORE any caller-controlled identifier or
	 * target object is read.
	 *
	 * Returns the trusted Clinic id, or null on any refusal (uniform failure).
	 */
	private function clinic_manager_scope( int $actor_wp_user_id, int $clinic_id, bool $for_update = false ): ?int {
		$trusted = $this->trusted_clinic( $actor_wp_user_id, $clinic_id, $for_update );
		if ( $trusted === null ) {
			return null;
		}

		try {
			$this->authorization->authorize( $actor_wp_user_id, $trusted, self::CLINIC_MANAGE_CAPABILITY, $for_update );
		} catch ( AuthorizationException $e ) {
			return null;
		} catch ( \Throwable $e ) {
			return null;
		}

		return $trusted;
	}

	/**
	 * Precise, auditable role-assignment policy for the staff surface.
	 *
	 * Delegation is authorized by STAFF-MANAGEMENT AUTHORITY, not by capability
	 * possession: the actor must hold explicit staff-management authority
	 * (CLINIC_MANAGE_CAPABILITY = cpms_config) in this Clinic — already
	 * established by clinic_manager_scope() with DENY > GRANT > role preset
	 * precedence (an explicit DENY on cpms_config therefore blocks every staff
	 * write) — and the requested role must be one of the approved operational
	 * roles in ASSIGNABLE_BUILT_IN_ROLES. A Clinic Manager does NOT need to
	 * possess the clinical or financial capabilities of the role being handed
	 * out: the assignment confers exactly that role's preset (plus the target's
	 * own explicit overrides), never an arbitrary capability set, so it can
	 * never escalate beyond the approved preset. Manager designation and
	 * Organization Manager membership are NOT assignable here (explicit
	 * Organization authority only), and no WordPress role is ever written.
	 */
	private function may_assign_role( string $role_key ): bool {
		return in_array( $role_key, self::ASSIGNABLE_BUILT_IN_ROLES, true );
	}

	/**
	 * Whether a resolved enrollment/role target may receive a Clinic staff
	 * membership: an existing WordPress Administrator is never a CPMS staff
	 * target (the boundary the Classic staff screen enforces too), and a plain
	 * Subscriber (or any non-administrator account) is.
	 */
	private function staff_target_is_eligible( int $target_wp_user_id ): bool {
		if ( $target_wp_user_id <= 0 ) {
			return false;
		}
		$user = get_userdata( $target_wp_user_id );
		if ( false === $user ) {
			return false;
		}

		return ! in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * Current explicit override effect for one capability ('' when none).
	 */
	private function capability_effect( int $membership_id, string $capability ): string {
		foreach ( $this->memberships->capabilities_for( $membership_id ) as $row ) {
			if ( (string) ( $row['capability'] ?? '' ) === $capability ) {
				return (string) ( $row['effect'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * Hash-chained audit entry for one sensitive portal mutation.
	 *
	 * @param array<string,mixed>|null $before
	 * @param array<string,mixed>      $after
	 */
	private function audit_change( string $action, int $actor_wp_user_id, string $resource_type, ?int $resource_id, ?array $before, array $after, int $clinic_id ): void {
		$after['via'] = 'management_portal';

		App::audit()->log(
			$action,
			array(
				'wp_user_id' => $actor_wp_user_id,
				'role'       => 'clinic_portal',
			),
			$resource_type,
			$resource_id,
			null,
			$before,
			$after,
			array( 'surface' => 'management_portal' ),
			$clinic_id
		);
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
	// Organization: facility-aware onboarding (frontend, no wp-admin)
	//
	// The published FacilitySetupService already owns this journey: it authorizes
	// by ORGANIZATION authority, keeps the plan durable, and activates every held
	// Clinic in ONE transaction. The portal only exposes it in the frontend; it
	// grants no Clinic authority to an Organization Manager (each Clinic step that
	// needs Clinic authority is delegated to the Clinic manager persona) and it
	// never lowers any wp-admin gate.
	// ==================================================================

	/**
	 * Current durable facility plan for one Organization (null when none exists).
	 *
	 * @return array<string,mixed>|null
	 */
	public function facility_plan( int $actor_wp_user_id, int $organization_id ): ?array {
		if ( $actor_wp_user_id <= 0 || $organization_id <= 0 ) {
			return null;
		}

		try {
			return App::facility_setup_service()->plan( $actor_wp_user_id, $organization_id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Start (or re-target) the facility plan for one Organization.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function start_facility_setup( int $actor_wp_user_id, int $organization_id, string $facility_type, string $structure, string $display_name ): array {
		try {
			App::facility_setup_service()->start( $actor_wp_user_id, $organization_id, $facility_type, $structure, $display_name );
		} catch ( \Throwable $e ) {
			return self::facility_failure( $e );
		}

		return array( 'ok' => true, 'message' => 'راه‌اندازی مرکز آغاز شد؛ اطلاعات مرکز ذخیره شد.' );
	}

	/**
	 * Hospital only: explicit outpatient-only acknowledgment.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function acknowledge_facility_outpatient( int $actor_wp_user_id, int $organization_id ): array {
		try {
			App::facility_setup_service()->acknowledge_outpatient( $actor_wp_user_id, $organization_id, true );
		} catch ( \Throwable $e ) {
			return self::facility_failure( $e );
		}

		return array( 'ok' => true, 'message' => 'محدودهٔ فقط سرپایی تأیید شد.' );
	}

	/**
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function add_facility_site( int $actor_wp_user_id, int $organization_id, string $label, string $address ): array {
		try {
			App::facility_setup_service()->add_site( $actor_wp_user_id, $organization_id, $label, $address );
		} catch ( \Throwable $e ) {
			return self::facility_failure( $e );
		}

		return array( 'ok' => true, 'message' => 'ساختمان ثبت شد.' );
	}

	/**
	 * Clinic creation inside the plan, held suspended until activation.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function add_facility_clinic( int $actor_wp_user_id, int $organization_id, string $name, string $site_key, string $timezone ): array {
		try {
			App::facility_setup_service()->add_clinic(
				$actor_wp_user_id,
				$organization_id,
				bin2hex( random_bytes( 16 ) ), // server-issued idempotency key
				$name,
				$site_key,
				$timezone
			);
		} catch ( \Throwable $e ) {
			return self::facility_failure( $e );
		}

		return array( 'ok' => true, 'message' => 'درمانگاه در برنامه ثبت شد (تا فعال‌سازی معلق).' );
	}

	/**
	 * Room/Location inside a plan Clinic.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function add_facility_room( int $actor_wp_user_id, int $organization_id, string $clinic_key, string $name, string $site_key ): array {
		try {
			App::facility_setup_service()->add_room(
				$actor_wp_user_id,
				$organization_id,
				$clinic_key,
				bin2hex( random_bytes( 16 ) ),
				$name,
				$site_key
			);
		} catch ( \Throwable $e ) {
			return self::facility_failure( $e );
		}

		return array( 'ok' => true, 'message' => 'اتاق/مکان ثبت شد.' );
	}

	/**
	 * Explicit Clinic-manager designation for a plan Clinic (Organization authority).
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function designate_facility_manager( int $actor_wp_user_id, int $organization_id, string $clinic_key, int $target_wp_user_id ): array {
		try {
			App::facility_setup_service()->designate_manager( $actor_wp_user_id, $organization_id, $clinic_key, $target_wp_user_id );
		} catch ( \Throwable $e ) {
			return self::facility_failure( $e );
		}

		return array( 'ok' => true, 'message' => 'مدیر درمانگاه تعیین شد.' );
	}

	/**
	 * Clinic-manager designation addressed by a login or e-mail — the same
	 * human-friendly identifier the staff-enrollment step uses. The identifier is
	 * resolved server-side to an existing WordPress user; this path NEVER
	 * provisions an account and never changes a WordPress role. Authority stays
	 * the actor's Organization designation capability.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function designate_facility_manager_by_identifier( int $actor_wp_user_id, int $organization_id, string $clinic_key, string $identifier ): array {
		$identifier = trim( $identifier );
		if ( '' === $identifier ) {
			return self::failure();
		}

		// Authorize (and resolve the plan Clinic) BEFORE identifying any user, so a
		// failed attempt is not a user-existence probe.
		try {
			App::facility_setup_service()->authorize_manager_designation( $actor_wp_user_id, $organization_id, $clinic_key );
		} catch ( \Throwable $e ) {
			return self::facility_failure( $e );
		}

		$target = 0;
		// Robust resolution: try e-mail first when it looks like an e-mail, then
		// always try login. This covers logins that resemble e-mails and avoids
		// a false negative when is_email's strict validation rejects a valid login.
		if ( is_email( $identifier ) ) {
			$by_email = get_user_by( 'email', $identifier );
			$target   = $by_email instanceof WP_User ? (int) $by_email->ID : 0;
		}
		if ( $target <= 0 ) {
			$by_login = get_user_by( 'login', $identifier );
			$target   = $by_login instanceof WP_User ? (int) $by_login->ID : 0;
		}
		if ( $target <= 0 ) {
			return self::failure();
		}

		return $this->designate_facility_manager( $actor_wp_user_id, $organization_id, $clinic_key, $target );
	}

	/**
	 * Plan Clinics and their selectable rooms/locations, with the clinicians that
	 * may be scheduled there — the server-side source for portal selectors, so no
	 * free-text key ever has to be typed by a human.
	 *
	 * @return list<array{key:string, clinic_id:int, name:string, rooms:list<array{key:string,name:string}>, clinicians:list<array{id:int,full_name:string}>}>
	 */
	public function facility_selectors( int $actor_wp_user_id, int $organization_id ): array {
		$plan = $this->facility_plan( $actor_wp_user_id, $organization_id );
		if ( null === $plan || ! isset( $plan['clinics'] ) || ! is_array( $plan['clinics'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $plan['clinics'] as $entry ) {
			$clinic_key = (string) ( $entry['key'] ?? '' );
			if ( '' === $clinic_key ) {
				continue;
			}
			$rooms = array(
				array(
					'key'  => 'primary',
					'name' => 'مکان اصلی',
				),
			);
			foreach ( (array) ( $entry['rooms'] ?? array() ) as $room ) {
				$rooms[] = array(
					'key'  => (string) ( $room['key'] ?? '' ),
					'name' => (string) ( $room['name'] ?? '' ),
				);
			}

			$clinicians = array();
			try {
				foreach ( App::facility_setup_service()->clinic_clinicians( $actor_wp_user_id, $organization_id, $clinic_key ) as $clinician ) {
					$clinicians[] = array(
						'id'        => (int) ( $clinician['id'] ?? 0 ),
						'full_name' => (string) ( $clinician['full_name'] ?? '' ),
					);
				}
			} catch ( \Throwable $e ) {
				$clinicians = array();
			}

			$out[] = array(
				'key'           => $clinic_key,
				'clinic_id'     => (int) ( $entry['clinic_id'] ?? 0 ),
				'name'          => (string) ( $entry['name'] ?? '' ),
				'rooms'         => $rooms,
				'clinicians'    => $clinicians,
				// Organization authority NEVER implies Clinic authority: a Clinic step
				// form is only rendered for an actor who really holds CONFIG there.
				'can_configure' => $this->authorization->can( $actor_wp_user_id, (int) ( $entry['clinic_id'] ?? 0 ), self::CLINIC_MANAGE_CAPABILITY ),
			);
		}

		return $out;
	}

	/**
	 * Assign a clinician to a plan Clinic (Organization authority + the actor's
	 * delegated Clinic authority, enforced inside FacilitySetupService).
	 *
	 * @param list<string> $room_keys
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function assign_facility_clinician( int $actor_wp_user_id, int $organization_id, string $clinic_key, int $target_wp_user_id, string $full_name, array $room_keys ): array {
		try {
			App::facility_setup_service()->assign_clinician(
				$actor_wp_user_id,
				$organization_id,
				$clinic_key,
				$target_wp_user_id,
				$full_name,
				$room_keys
			);
		} catch ( \Throwable $e ) {
			return self::facility_failure( $e );
		}

		return array( 'ok' => true, 'message' => 'پزشک به درمانگاه اختصاص یافت.' );
	}

	/**
	 * Weekly schedule for one assigned clinician at one room/Location.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function add_facility_schedule( int $actor_wp_user_id, int $organization_id, string $clinic_key, int $clinician_id, string $room_key, int $day_of_week, string $start_time, string $end_time ): array {
		try {
			App::facility_setup_service()->add_weekly_schedule(
				$actor_wp_user_id,
				$organization_id,
				$clinic_key,
				$clinician_id,
				$room_key,
				array(
					'day_of_week' => $day_of_week,
					'start_time'  => $start_time,
					'end_time'    => $end_time,
				)
			);
		} catch ( \Throwable $e ) {
			return self::facility_failure( $e );
		}

		return array( 'ok' => true, 'message' => 'برنامهٔ هفتگی ثبت شد.' );
	}

	/**
	 * Booking policy for one plan Clinic (required by hospital readiness).
	 *
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function save_facility_booking_policy( int $actor_wp_user_id, int $organization_id, string $clinic_key, array $input ): array {
		try {
			App::facility_setup_service()->save_booking_policy( $actor_wp_user_id, $organization_id, $clinic_key, $input );
		} catch ( \Throwable $e ) {
			return self::facility_failure( $e );
		}

		return array( 'ok' => true, 'message' => 'سیاست رزرو ذخیره شد.' );
	}

	/**
	 * The designated Clinic Manager's own view of the held Clinic they must
	 * complete: live-row readiness + what is still missing. Authorized by their
	 * own ACTIVE membership + CONFIG in that Clinic (never by Organization
	 * authority), and it never exposes another Clinic.
	 *
	 * @return array<string,mixed>|null
	 */
	public function clinic_facility_readiness( int $actor_wp_user_id, int $clinic_id ): ?array {
		if ( $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id ) === null ) {
			return null;
		}

		try {
			return App::facility_setup_service()->clinic_readiness( $actor_wp_user_id, $clinic_id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Booking policy of one Clinic, written from that Clinic's own manager portal.
	 * The Clinic id is a selector only: FacilitySetupService re-establishies the
	 * actor's ACTIVE membership + CONFIG in exactly that Clinic.
	 *
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function save_clinic_booking_policy( int $actor_wp_user_id, int $clinic_id, array $input ): array {
		$trusted = $this->clinic_manager_scope( $actor_wp_user_id, $clinic_id );
		if ( $trusted === null ) {
			return self::failure();
		}

		try {
			App::facility_setup_service()->save_booking_policy_for_clinic( $actor_wp_user_id, $trusted, $input );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'سیاست رزرو و ظرفیت این درمانگاه ذخیره شد.' );
	}

	/**
	 * Consolidated review computed from live rows.
	 *
	 * @return array<string,mixed>|null
	 */
	public function review_facility_setup( int $actor_wp_user_id, int $organization_id ): ?array {
		if ( $actor_wp_user_id <= 0 || $organization_id <= 0 ) {
			return null;
		}

		try {
			return App::facility_setup_service()->review( $actor_wp_user_id, $organization_id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Atomic activation of every held Clinic of the plan (single transaction under
	 * the Organization row lock; a failure anywhere activates nothing).
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	public function activate_facility_setup( int $actor_wp_user_id, int $organization_id ): array {
		try {
			App::facility_setup_service()->activate( $actor_wp_user_id, $organization_id, true );
		} catch ( \Throwable $e ) {
			return self::failure();
		}

		return array( 'ok' => true, 'message' => 'مرکز به‌صورت اتمیک فعال شد.' );
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
	 * Offline Iran province/city address resolution for portal forms (FIX 3).
	 *
	 * Uses IranProvinceCityCatalog (31 provinces, offline, no external call) to validate
	 * province-city relationship server-side, preserve Persian RTL and allow safe retries
	 * (composed as `province، city، detail`). Returns resolved address or null if nothing
	 * submitted; throws InvalidArgumentException on mismatch (city without province or
	 * city not belonging to province) — caller maps it to a uniform failure.
	 *
	 * @param array<string,mixed> $input
	 */
	private static function resolve_portal_address( array $input ): ?string {
		$raw_address  = $input['address'] ?? null;
		$raw_province = $input['address_province'] ?? null;
		$raw_city     = $input['address_city'] ?? null;
		if (
			( null === $raw_address || '' === trim( (string) $raw_address ) )
			&& ( null === $raw_province || '' === trim( (string) $raw_province ) )
			&& ( null === $raw_city || '' === trim( (string) $raw_city ) )
		) {
			return null;
		}

		return IranProvinceCityCatalog::resolve_submitted_address(
			is_scalar( $raw_address ) ? (string) $raw_address : null,
			is_scalar( $raw_province ) ? (string) $raw_province : null,
			is_scalar( $raw_city ) ? (string) $raw_city : null
		);
	}

	/**
	 * Map one facility-setup refusal to a categorized, safe user-facing result.
	 *
	 * FacilitySetupException messages are already crafted safe Persian (stable
	 * machine reason + a short sentence with no SQL, no patient data and no
	 * other tenant's information), so they are surfaced verbatim, grouped into
	 * the three user-facing categories — invalid input, missing prerequisites,
	 * insufficient permissions. Anything else (database, transaction, runtime)
	 * is an operational failure that never leaks exception text.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	private static function facility_failure( \Throwable $e ): array {
		if ( $e instanceof FacilitySetupException ) {
			$reason = $e->reason();
			if ( str_starts_with( $reason, 'invalid' )
				|| in_array( $reason, [ 'incompatible_structure', 'duplicate_clinic', 'duplicate_room', 'limit', 'conflict', 'unsupported_flow', 'not_hospital' ], true ) ) {
				return self::invalid( $e->getMessage() );
			}
			if ( in_array( $reason, [ 'no_plan', 'ack_required', 'confirm_required', 'not_ready', 'not_found', 'user_not_found', 'user_not_eligible', 'membership_exists', 'membership_suspended', 'membership_failed', 'flow_locked', 'hold_failed', 'plan_write_failed' ], true ) ) {
				return self::prerequisite( $e->getMessage() );
			}

			return array(
				'ok'      => false,
				'message' => $e->getMessage(),
				'notice'  => 'denied',
			);
		}

		return self::operational_failure();
	}

	/**
	 * Uniform, non-disclosing authorization failure. Never reveals tenant
	 * topology, whether a Clinic exists, or whether a membership is suspended.
	 * The notice kind ('denied') distinguishes INSUFFICIENT PERMISSIONS from
	 * invalid input and missing prerequisites without saying which check failed.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	private static function failure(): array {
		return array(
			'ok'      => false,
			'message' => 'عملیات انجام نشد؛ دسترسی لازم را ندارید یا داده‌های ارسالی معتبر نیستند و هیچ تغییری ثبت نشد.',
			'notice'  => 'denied',
		);
	}

	/**
	 * Validation failure: the INPUT is invalid (unknown role, empty identifier,
	 * malformed value). Distinct from an authorization refusal so the form can
	 * show an actionable Persian hint without disclosing any tenant state.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	private static function invalid( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
			'notice'  => 'invalid',
		);
	}

	/**
	 * Missing-prerequisite failure: the input is valid and the actor is
	 * authorized, but a precondition of the operation is not met (e.g. the
	 * facility plan step must happen first). Never discloses tenant data.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	private static function prerequisite( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
			'notice'  => 'prerequisite',
		);
	}

	/**
	 * Operational failure (database/transaction/runtime) — never a refusal
	 * label and never an exception or SQL detail. Fail closed with a retry hint.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	private static function operational_failure(): array {
		return array(
			'ok'      => false,
			'message' => 'خطای داخلی در انجام عملیات رخ داد و هیچ تغییری ثبت نشد؛ لطفاً دوباره تلاش کنید.',
			'notice'  => 'failed',
		);
	}

	/**
	 * Success result with its user-facing Persian message.
	 *
	 * @return array{ok:bool, message:string, notice?:string}
	 */
	private static function success( string $message ): array {
		return array(
			'ok'      => true,
			'message' => $message,
			'notice'  => 'done',
		);
	}

	private function db(): \ClinicCore\Infrastructure\Db\CpmsDb {
		return App::db();
	}
}
