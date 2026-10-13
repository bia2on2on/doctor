<?php

declare(strict_types=1);

namespace ClinicCore\Application\Roles;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * Custom Role & Permission Management — Slice 3B: Clinic-local custom-role
 * ASSIGNMENT for existing staff.
 *
 * Product Decision record: docs/decisions/2026-10-10-custom-role-permission-
 * management-model.md (decisions 1, 3, 4, 5, 6, 7, 10, 11, 12).
 *
 * Authority model (no parallel authorization engine):
 *  1. The requested Clinic is a *selector*, never a trust fact: it is resolved
 *     through the existing TrustedClinicEstablisher (durable ACTIVE membership +
 *     real Clinic + ACTIVE Organization), exactly like the rest of the staff
 *     surface. There is no administrator-to-all-Clinics authority and no
 *     first-row Clinic fallback.
 *  2. Effective assignment authority = AuthorizationService::can(actor, trusted
 *     Clinic, cpms_config). WordPress `manage_options`, the WP `administrator`
 *     role and any WP-global capability are never consulted.
 *  3. The ordinary TARGET must already hold an ACTIVE membership in that exact Clinic
 *     (no membership is created here — this is an assignment for existing staff,
 *     never an onboarding path).
 *  4. Only an ACTIVE custom-role DEFINITION of the exact trusted Clinic is
 *     addressable (durable object ownership, re-checked through
 *     AuthorizationService::authorizeForObject). Built-in role keys are not
 *     assignable through this path: built-in staff flows remain the existing
 *     ones and stay operational.
 *  5. Delegation is bounded (decision 4/6): every capability the definition
 *     would confer must be one the assigner effectively holds in that Clinic,
 *     decided exclusively by AuthorizationService — so a membership DENY can
 *     never be laundered through a custom role. An EMPTY capability set is
 *     vacuously within those bounds and therefore assignable; it confers no
 *     permission at all (it changes only the membership label).
 *  6. The write is membership-only: the durable `cpms_clinic_memberships`
 *     `role_key` of the target is updated. The WordPress-global role of the
 *     target is never modified, no clinician/professional identity is touched,
 *     and existing membership GRANT/DENY rows (`cpms_membership_capabilities`,
 *     which outrank the role) are left exactly as they were.
 *  7. The role update and its mandatory audit event share one transaction
 *     boundary: a failed audit rolls the role change back, so there is no
 *     assignment without its audit row.
 *  8. Reassignment to the key the membership already carries is a reported
 *     no-op: nothing is written and nothing is audited (there is no change to
 *     record). It is never reported as an assignment.
 *
 * There is deliberately NO WordPress-global role registration, NO WP role
 * mutation, NO membership creation, NO capability GRANT/DENY writing and no
 * automatic reactivation or deletion in this service. Slice 4B adds only the
 * explicit suspended-member reassignment path needed to release role references.
 */
final class CustomRoleAssignmentService {

    public const AUDIT_ASSIGNED = 'CUSTOM_ROLE_ASSIGNED';

    public const RESOURCE_TYPE = 'membership';

    public function __construct(
        private readonly TrustedClinicEstablisher $scope,
        private readonly AuthorizationService $authorization,
        private readonly MembershipRepository $memberships,
        private readonly CustomRoleRepository $roles,
        private readonly AuditLogger $audit,
        private readonly CpmsDb $db
    ) {
    }

    /**
     * Display/entry gate for the assignment surface: effective Clinic-scoped
     * management authority only. Never true for a WP administrator who holds no
     * durable membership in that Clinic (the selector is resolved the same way
     * as in assign()).
     */
    public function can_manage( int $actor_wp_user_id, int $clinic_id ): bool {
        if ( $actor_wp_user_id <= 0 || $clinic_id <= 0 ) {
            return false;
        }

        return $this->authorization->can( $actor_wp_user_id, $clinic_id, RolesAndCapabilities::CONFIG );
    }

    /**
     * ACTIVE custom definitions of the trusted Clinic that this assigner may
     * actually delegate. Fail closed: a definition carrying a capability the
     * assigner does not effectively hold in that Clinic is not offered (and
     * assignment of it would be refused by assign() anyway). Empty-capability
     * definitions are included and flagged, so the UI can explain their effect.
     *
     * @throws ScopeRequiredException no trusted Clinic context for this actor
     * @throws \ClinicCore\Application\Authorization\AuthorizationException no management authority
     *
     * @return array{clinic_id:int, definitions:list<array{role_key:string, display_name:string|null, capabilities:list<string>, empty:bool}>}
     */
    public function assignable_definitions( int $actor_wp_user_id, ?int $requested_clinic_id ): array {
        $clinic_id = $this->require_management_authority( $actor_wp_user_id, $requested_clinic_id );

        $definitions = [];
        foreach ( $this->roles->list_definitions( $clinic_id ) as $definition ) {
            if ( (string) ( $definition['status'] ?? '' ) !== CustomRoleRepository::STATUS_ACTIVE ) {
                continue;
            }
            $capabilities = $definition['capabilities'];

            if ( $this->not_delegable( $actor_wp_user_id, $clinic_id, $capabilities ) !== [] ) {
                continue;
            }

            $definitions[] = [
                'role_key'     => (string) $definition['role_key'],
                'display_name' => $definition['display_name'],
                'capabilities' => $capabilities,
                'empty'        => $capabilities === [],
            ];
        }

        return [
            'clinic_id'   => $clinic_id,
            'definitions' => $definitions,
        ];
    }

    /**
     * Assign an ACTIVE Clinic-local custom role to an existing ACTIVE member of
     * the trusted Clinic. Membership-only, audited, atomic.
     *
     * @throws ScopeRequiredException no trusted Clinic context for this actor
     * @throws \ClinicCore\Application\Authorization\AuthorizationException suspended/non-member actor or denied management authority
     * @throws CustomRoleAssignmentException bounded-input or object-lookup failure
     *
     * @return array{clinic_id:int, membership_id:int, wp_user_id:int, role_key:string, display_name:string|null, capabilities:list<string>, previous_role_key:string, changed:bool}
     */
    public function assign(
        int $actor_wp_user_id,
        ?int $requested_clinic_id,
        int $target_wp_user_id,
        string $role_key
    ): array {
        return $this->assign_membership( $actor_wp_user_id, $requested_clinic_id, $target_wp_user_id, $role_key, 'active' );
    }

    /**
     * Explicit suspended-member reference resolution. Only role_key/updated_at
     * change; suspension, overrides, restore metadata and identities do not.
     *
     * @return array{clinic_id:int,membership_id:int,wp_user_id:int,role_key:string,display_name:string|null,capabilities:list<string>,previous_role_key:string,changed:bool}
     */
    public function reassign_suspended(
        int $actor_wp_user_id,
        ?int $requested_clinic_id,
        int $target_wp_user_id,
        string $role_key
    ): array {
        return $this->assign_membership( $actor_wp_user_id, $requested_clinic_id, $target_wp_user_id, $role_key, 'suspended' );
    }

    /**
     * @return array{clinic_id:int,membership_id:int,wp_user_id:int,role_key:string,display_name:string|null,capabilities:list<string>,previous_role_key:string,changed:bool}
     */
    private function assign_membership(
        int $actor_wp_user_id,
        ?int $requested_clinic_id,
        int $target_wp_user_id,
        string $role_key,
        string $required_membership_status
    ): array {
        $clinic_id = $this->require_management_authority( $actor_wp_user_id, $requested_clinic_id );

        // Built-in keys, numeric/malformed keys and unknown vocabulary are not
        // assignable here; the built-in staff flows own those.
        if ( ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            throw CustomRoleAssignmentException::invalid_role_key();
        }
        if ( $target_wp_user_id <= 0 ) {
            throw CustomRoleAssignmentException::target_membership_required();
        }

        // Hold the definition row lock from the current-status check through
        // membership mutation and audit. Lifecycle transitions take the same
        // lock, so whichever request wins is revalidated by the one that waits.
        return $this->db->transactional(
            function () use ( $actor_wp_user_id, $clinic_id, $target_wp_user_id, $role_key, $required_membership_status ): array {
                $definition = $this->roles->definition_for_update( $clinic_id, $role_key );
                if ( $definition === null ) {
                    throw CustomRoleAssignmentException::definition_not_found( $role_key );
                }

                // Revalidate trusted Clinic management authority after any wait
                // on the definition row, then check object ownership for both
                // the persisted definition and target membership.
                $this->require_management_authority( $actor_wp_user_id, $clinic_id, true );
                $this->authorization->authorizeForObject(
                    $actor_wp_user_id,
                    $clinic_id,
                    RolesAndCapabilities::CONFIG,
                    (int) $definition['clinic_id'],
                    true
                );

                if ( (string) $definition['status'] !== CustomRoleRepository::STATUS_ACTIVE ) {
                    throw CustomRoleAssignmentException::definition_not_active( $role_key );
                }

                $target        = $this->memberships->find( $clinic_id, $target_wp_user_id, true );
                $membership_id = $target === null ? 0 : (int) ( $target['id'] ?? 0 );
                if ( $target === null || $membership_id <= 0 || (int) ( $target['clinic_id'] ?? 0 ) !== $clinic_id
                    || (string) ( $target['status'] ?? '' ) !== $required_membership_status ) {
                    throw CustomRoleAssignmentException::target_membership_required();
                }
                $this->authorization->authorizeForObject(
                    $actor_wp_user_id,
                    $clinic_id,
                    RolesAndCapabilities::CONFIG,
                    (int) $target['clinic_id'],
                    true
                );

                // Bounded delegation is checked while the definition remains
                // locked. A changed manager capability set cannot race a role
                // status transition into an unauthorized membership write.
                $capabilities  = $definition['capabilities'];
                $not_delegable = $this->not_delegable( $actor_wp_user_id, $clinic_id, $capabilities, true );
                if ( $not_delegable !== [] ) {
                    throw CustomRoleAssignmentException::capability_not_delegable( $not_delegable );
                }

                $previous_role_key = (string) ( $target['role_key'] ?? '' );
                $result            = [
                    'clinic_id'         => $clinic_id,
                    'membership_id'     => $membership_id,
                    'wp_user_id'        => $target_wp_user_id,
                    'role_key'          => $role_key,
                    'display_name'      => $definition['display_name'],
                    'capabilities'      => $capabilities,
                    'previous_role_key' => $previous_role_key,
                    'changed'           => true,
                ];

                if ( $previous_role_key === $role_key ) {
                    $result['changed'] = false;

                    return $result;
                }

                $updated = $this->memberships->update(
                    [ 'role_key' => $role_key, 'updated_at' => $this->db->nowUtcSql() ],
                    [ 'id' => $membership_id ]
                );
                if ( $updated !== 1 ) {
                    throw CustomRoleAssignmentException::membership_update_failed( $membership_id );
                }

                $this->audit->log(
                    self::AUDIT_ASSIGNED,
                    [ 'wp_user_id' => $actor_wp_user_id, 'role' => 'clinic_manager' ],
                    self::RESOURCE_TYPE,
                    $membership_id,
                    null,
                    [
                        'clinic_id'         => $clinic_id,
                        'wp_user_id'        => $target_wp_user_id,
                        'role_key'          => $previous_role_key,
                        'membership_status' => $required_membership_status,
                    ],
                    [
                        'clinic_id'         => $clinic_id,
                        'wp_user_id'        => $target_wp_user_id,
                        'role_key'          => $role_key,
                        'capabilities'      => $capabilities,
                        'scope'             => 'clinic_local_membership',
                        'membership_status' => $required_membership_status,
                    ],
                    [ 'clinic_id' => $clinic_id, 'wp_global_role_modified' => false ],
                    $clinic_id
                );

                return $result;
            }
        );
    }

    // ================= internals =================

    /**
     * Capabilities of a candidate definition that the assigner does not
     * effectively hold in the trusted Clinic (empty = fully delegable).
     *
     * @param list<string> $capabilities
     *
     * @return list<string>
     */
    private function not_delegable( int $actor_wp_user_id, int $clinic_id, array $capabilities, bool $for_update = false ): array {
        $not_delegable = [];
        foreach ( $capabilities as $capability ) {
            if ( ! $this->authorization->can( $actor_wp_user_id, $clinic_id, $capability, $for_update ) ) {
                $not_delegable[] = $capability;
            }
        }

        return $not_delegable;
    }

    /**
     * Resolve the trusted Clinic and require effective management authority.
     *
     * @throws ScopeRequiredException unauthenticated / no durable membership / unknown Clinic / inactive Organization
     * @throws \ClinicCore\Application\Authorization\AuthorizationException suspended membership or denied permission
     */
    private function require_management_authority( int $actor_wp_user_id, ?int $requested_clinic_id, bool $for_update = false ): int {
        if ( $actor_wp_user_id <= 0 ) {
            throw new ScopeRequiredException( 'CLINIC_SCOPE_UNAVAILABLE', 'Trusted clinic context is not available.', [ 'reason' => 'unauthenticated' ], 403 );
        }

        // The selector is validated against durable persistence; it is never
        // trusted by itself, and a forged value cannot conjure a membership.
        $scope = $this->scope->establish( $actor_wp_user_id, $requested_clinic_id, null, $for_update );

        $this->authorization->authorize( $actor_wp_user_id, $scope->clinicId, RolesAndCapabilities::CONFIG, $for_update ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the existing published scope API.

        return $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the existing published scope API.
    }
}
