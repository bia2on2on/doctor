<?php

declare(strict_types=1);

namespace ClinicCore\Application\Authorization;

use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * Phase 3 Slice 1 — Clinic-scoped AuthorizationService foundation.
 *
 * Invariants (CHANGE CONTRACT):
 *  1. Clinic-scoped authorization requires authenticated actor + durable ACTIVE Clinic participation + requested scoped permission.
 *  2. Suspended membership denies access.
 *  3. Membership alone does not grant arbitrary permission.
 *  4. WordPress administrator/manage_options/cpms_config alone does NOT grant Clinic clinical/management authorization.
 *  5. Clinic context is not authorization. Never trust raw payload clinic_id as an authorization fact.
 *  6. Cross-Clinic object access is denied when durable object ownership contradicts the authorized Clinic.
 *  7. Same actor may be independently authorized in Clinic A and Clinic B through durable participation/policy; there is no first-Clinic fallback, Clinic 1, implicit global Clinic, or clinic_id=0.
 *  8. Permission resolution (verified against existing schema): explicit membership deny > explicit membership grant > role preset > deny by default.
 *  9. Job authorization/scope behavior remains unchanged.
 * 10. No schema/migration change.
 * 11. Later authorized extension — Custom Role & Permission Management Slice 1
 *     (decision record: docs/decisions/2026-10-10-custom-role-permission-management-model.md;
 *     supersedes item 10's "no schema change" for this slice only, via
 *     Migration 2026_10_10_0024): custom role keys resolve ONLY through
 *     Clinic-local custom definitions of the exact membership Clinic
 *     (CustomRoleRepository); built-in role keys never consult custom
 *     definitions; missing/inactive/malformed/foreign definitions supply
 *     nothing (fail closed); this is not a second authorization engine —
 *     precedence above is unchanged. When no CustomRoleRepository is wired,
 *     custom keys resolve to nothing (fail closed).
 *
 * Verified data model:
 *  - cpms_clinic_memberships: (clinic_id, wp_user_id) UNIQUE, status active/suspended, role_key as attribute
 *  - cpms_membership_capabilities: (membership_id, capability) UNIQUE, effect grant/deny
 *  - RolesAndCapabilities::capsMap(role_key) provides effective preset (including optional overrides)
 *
 * Design:
 *  - typed/explicit API
 *  - fail closed
 *  - no hardcoded IDs
 *  - no first row wins
 *  - no ambient Clinic guessing
 *  - no raw payload trust:
 *    trusted authorization Clinic (from TrustedClinicEstablisher / explicit membership)
 *    + independently retrieved durable object owner Clinic (from persistence/repository)
 *    must agree; equality alone does not establish trust unless owner Clinic was
 *    obtained server-side from durable storage. If durable ownership cannot be
 *    established, authorization must fail closed.
 *  - no administrator bypass
 *  - no secretary-to-doctor coupling
 */
final class AuthorizationService
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly ?CustomRoleRepository $custom_roles = null
    ) {
    }

    /**
     * Clinic-scoped permission check. Optional current locking reads are used
     * only within serialized role writes; precedence is exactly the same.
     *
     * @param int    $actorWpUserId Authenticated WP user id (>0)
     * @param int    $clinicId      Trusted durable clinic id (>0, from TrustedClinicEstablisher or durable ownership)
     * @param string $permission    Requested scoped permission (e.g. cpms_patient_read)
     */
    public function can( int $actorWpUserId, int $clinicId, string $permission, bool $for_update = false ): bool // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing published named-argument API and surrounding legacy variable names.
    {
        // Fail closed: unauthenticated, invalid clinic, empty permission
        if ($actorWpUserId <= 0) {
            return false;
        }
        if ($clinicId <= 0) {
            return false;
        }
        if ($permission === '' || trim($permission) === '') {
            return false;
        }

        // Durable ACTIVE membership is required (suspended => find_active returns null)
        $membership = $this->memberships->find_active( $clinicId, $actorWpUserId, $for_update ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing published named-argument API and surrounding legacy variable names.
        if ($membership === null) {
            return false;
        }

        $membershipId = (int) ($membership['id'] ?? 0);
        if ($membershipId <= 0) {
            return false;
        }

        // Fetch explicit capability overrides for this membership (deny > grant)
        $caps = $this->memberships->capabilities_for( $membershipId, $for_update ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing published named-argument API and surrounding legacy variable names.

        // Explicit deny overrides everything
        foreach ($caps as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cap = (string) ($row['capability'] ?? '');
            $effect = (string) ($row['effect'] ?? '');
            if ($cap === $permission && $effect === 'deny') {
                return false;
            }
        }

        // Explicit grant
        foreach ($caps as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cap = (string) ($row['capability'] ?? '');
            $effect = (string) ($row['effect'] ?? '');
            if ($cap === $permission && $effect === 'grant') {
                return true;
            }
        }

        // Role capabilities (clinic-scoped via membership role_key, not WP global role).
        // Built-in keys resolve ONLY through the registered preset map; custom keys
        // resolve ONLY through Clinic-local custom-role definitions of the exact
        // membership Clinic. Built-in keys never consult custom definitions
        // (collision inertness), and an unwired repository fails closed to nothing.
        $role_key = (string) ( $membership['role_key'] ?? '' );
        if ( $role_key !== '' ) {
            if ( CustomRolePolicy::is_built_in_role_key( $role_key ) ) {
                $preset_map = RolesAndCapabilities::capsMap( $role_key );
                if ( isset( $preset_map[ $permission ] ) && $preset_map[ $permission ] === true ) {
                    return true;
                }
            } elseif ( $this->custom_roles !== null ) {
                foreach ( $this->custom_roles->active_capabilities_for( (int) $membership['clinic_id'], $role_key, $for_update ) as $custom_capability ) {
                    if ( $custom_capability === $permission ) {
                        return true;
                    }
                }
            }
        }

        // Deny by default
        return false;
    }

    /**
     * Authoritative scoped decision for a capability, or NULL when the scoped
     * engine does not govern this capability for this actor.
     *
     * Contract (owner policy: WordPress-independent business access):
     *  - `true`  — the ACTIVE membership effectively grants the capability;
     *  - `false` — the ACTIVE membership carries an EXPLICIT DENY for it. That
     *              answer is FINAL: no WordPress-global capability may override
     *              it, which is exactly how "explicit DENY outranks legacy
     *              `has_cap()`" is enforced;
     *  - `null`  — no ACTIVE membership in the Clinic, or a membership that
     *              neither grants nor explicitly denies the capability. The
     *              caller may then consult its legacy WordPress capability path
     *              (suspended memberships also land here, so they keep the
     *              original behaviour of their own surface).
     */
    // phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- published camelCase API of this class
    public function scoped_decision( int $actorWpUserId, int $clinicId, string $permission, bool $for_update = false ): ?bool {
        if ( $actorWpUserId <= 0 || $clinicId <= 0 ) {
            return null;
        }
        if ( '' === trim( $permission ) ) {
            return null;
        }

        $membership = $this->memberships->find_active( $clinicId, $actorWpUserId, $for_update );
        if ( $membership === null ) {
            return null;
        }

        if ( $this->can( $actorWpUserId, $clinicId, $permission, $for_update ) ) {
            return true;
        }

        $membership_id = (int) ( $membership['id'] ?? 0 );
        if ( $membership_id > 0 ) {
            foreach ( $this->memberships->capabilities_for( $membership_id, $for_update ) as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                if ( (string) ( $row['capability'] ?? '' ) === $permission && 'deny' === (string) ( $row['effect'] ?? '' ) ) {
                    return false;
                }
            }
        }

        return null;
    }
    // phpcs:enable

    /**
     * Clinic-scoped permission check with durable object ownership validation.
     *
     * Contract for $objectClinicId (both canForObject and authorizeForObject):
     *  - MUST be the durable owner clinic_id of the target object;
     *  - MUST be obtained server-side from persistence/repository data (e.g. SELECT clinic_id FROM cpms_patients WHERE id = ?);
     *  - MUST never be obtained/trusted from request/payload/context;
     *  - If durable ownership cannot be established (no row, null, 0), authorization must fail closed.
     *
     * This method checks that the independently retrieved durable owner Clinic
     * agrees with the trusted authorization Clinic. Equality alone does not
     * establish trust — the owner value must come from durable storage.
     *
     * @param int $objectClinicId Durable owner clinic_id retrieved server-side from persistence (>0)
     */
    public function canForObject(int $actorWpUserId, int $clinicId, string $permission, int $objectClinicId): bool
    {
        // Fail closed on invalid ids
        if ($actorWpUserId <= 0) {
            return false;
        }
        if ($clinicId <= 0 || $objectClinicId <= 0) {
            return false;
        }
        // Cross-Clinic object access denied when durable ownership contradicts authorized Clinic
        if ($objectClinicId !== $clinicId) {
            return false;
        }

        return $this->can($actorWpUserId, $clinicId, $permission);
    }

    /**
     * Clinic-scoped sensitive-finance check with verified transaction ownership
     * (Product Decision 2026-10-15 — docs/decisions/2026-10-15-financial-authorization-policy.md).
     *
     * Precedence is unchanged and remains the ONLY resolution order
     * (explicit membership DENY > explicit membership GRANT > role capabilities >
     * deny by default). This method adds exactly one conjunct:
     *
     *   - an actor whose ACTIVE membership carries the built-in DOCTOR role must
     *     own the transaction — unconditionally, and independently of where the
     *     capability came from (role preset, pre-existing explicit GRANT, or an
     *     explicit Clinic-manager delegation). Ownership can therefore never be
     *     bypassed by writing a membership override;
     *   - every other actor (accountant, manager, custom-role staff) keeps the
     *     unchanged behaviour: an explicit membership GRANT is deliberate
     *     Clinic-scoped authority and is not ownership-bound;
     *   - explicit membership DENY is evaluated first inside can() and still wins
     *     over ownership and over any grant.
     *
     * Ownership must be a durable server-side fact obtained by the caller
     * (e.g. PaymentRepository::owning_clinician_wp_user_id()) — never a
     * payload/context claim, and never a patient relationship. When ownership
     * cannot be established the caller must pass false (fail closed).
     */
    public function can_with_ownership( int $actor_wp_user_id, int $clinic_id, string $permission, bool $owns_transaction, bool $for_update = false ): bool {
        if ( ! $this->can( $actor_wp_user_id, $clinic_id, $permission, $for_update ) ) {
            return false;
        }
        if ( $owns_transaction ) {
            return true;
        }

        return ! $this->actor_is_doctor( $actor_wp_user_id, $clinic_id, $for_update );
    }

    /**
     * Ownership-aware authorization or throw typed exception (fail closed).
     *
     * @throws AuthorizationException AUTH_DENIED (capability) or AUTH_OWNERSHIP_REQUIRED (ownership conjunct)
     */
    public function authorize_with_ownership( int $actor_wp_user_id, int $clinic_id, string $permission, bool $owns_transaction, bool $for_update = false ): void {
        if ( ! $this->can( $actor_wp_user_id, $clinic_id, $permission, $for_update ) ) {
            $this->authorize( $actor_wp_user_id, $clinic_id, $permission, $for_update ); // throws the typed denial

            return;
        }
        if ( $this->can_with_ownership( $actor_wp_user_id, $clinic_id, $permission, $owns_transaction, $for_update ) ) {
            return;
        }

        throw new AuthorizationException(
            'AUTH_OWNERSHIP_REQUIRED',
            'AUTH_OWNERSHIP_REQUIRED',
            [ 'clinic_id' => $clinic_id, 'permission' => $permission ],
            403
        );
    }

    /**
     * True when the actor's durable ACTIVE membership of this exact Clinic carries
     * the built-in doctor role key. Deliberately independent of capability rows:
     * ownership is a property of the clinical role, so no membership override can
     * lift it (2026-10-15 security correction).
     */
    private function actor_is_doctor( int $actor_wp_user_id, int $clinic_id, bool $for_update ): bool {
        $membership = $this->memberships->find_active( $clinic_id, $actor_wp_user_id, $for_update ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the published named-argument API and legacy variable names.
        if ( $membership === null ) {
            return false;
        }

        return (string) ( $membership['role_key'] ?? '' ) === RolesAndCapabilities::ROLE_DOCTOR;
    }

    /**
     * Authorize or throw typed exception (fail closed).
     *
     * @throws AuthorizationException
     */
    public function authorize( int $actorWpUserId, int $clinicId, string $permission, bool $for_update = false ): void // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing published named-argument API and surrounding legacy variable names.
    {
        if ($actorWpUserId <= 0) {
            throw new AuthorizationException('AUTH_UNAUTHENTICATED', 'AUTH_UNAUTHENTICATED', ['reason' => 'unauthenticated'], 401);
        }
        if ($clinicId <= 0) {
            throw new AuthorizationException('AUTH_INVALID_CLINIC', 'AUTH_INVALID_CLINIC', ['reason' => 'invalid_clinic'], 400);
        }
        if ($permission === '' || trim($permission) === '') {
            throw new AuthorizationException('AUTH_INVALID_PERMISSION', 'AUTH_INVALID_PERMISSION', ['reason' => 'empty_permission'], 400);
        }

        $membership = $this->memberships->find_active( $clinicId, $actorWpUserId, $for_update ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing published named-argument API and surrounding legacy variable names.
        if ($membership === null) {
            // Distinguish suspended vs non-member for audit, but both deny
            $any = $this->memberships->find( $clinicId, $actorWpUserId, $for_update ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing published named-argument API and surrounding legacy variable names.
            if ($any !== null && (string) ($any['status'] ?? '') === 'suspended') {
                throw new AuthorizationException('AUTH_SUSPENDED', 'AUTH_SUSPENDED', ['clinic_id' => $clinicId], 403);
            }
            throw new AuthorizationException('AUTH_NO_MEMBERSHIP', 'AUTH_NO_MEMBERSHIP', ['clinic_id' => $clinicId], 403);
        }

        if ( ! $this->can( $actorWpUserId, $clinicId, $permission, $for_update ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing published named-argument API and surrounding legacy variable names.
            throw new AuthorizationException('AUTH_DENIED', 'AUTH_DENIED', ['clinic_id' => $clinicId, 'permission' => $permission], 403);
        }
    }

    /**
     * Authorize with object ownership check or throw.
     *
     * Contract for $objectClinicId:
     *  - MUST be the durable owner clinic_id of the target object;
     *  - MUST be obtained server-side from persistence/repository data;
     *  - MUST never be obtained/trusted from request/payload/context;
     *  - If durable ownership cannot be established, authorization must fail closed.
     *
     * The trusted authorization Clinic and the independently retrieved durable
     * object owner Clinic must agree.
     *
     * @param int $objectClinicId Durable owner clinic_id retrieved server-side from persistence (>0)
     *
     * @throws AuthorizationException
     */
    public function authorizeForObject( int $actorWpUserId, int $clinicId, string $permission, int $objectClinicId, bool $for_update = false ): void // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing published named-argument API and surrounding legacy variable names.
    {
        if ($actorWpUserId <= 0) {
            throw new AuthorizationException('AUTH_UNAUTHENTICATED', 'AUTH_UNAUTHENTICATED', ['reason' => 'unauthenticated'], 401);
        }
        if ($clinicId <= 0 || $objectClinicId <= 0) {
            throw new AuthorizationException('AUTH_INVALID_CLINIC', 'AUTH_INVALID_CLINIC', ['reason' => 'invalid_clinic'], 400);
        }
        if ($objectClinicId !== $clinicId) {
            throw new AuthorizationException('AUTH_CROSS_CLINIC', 'AUTH_CROSS_CLINIC', ['requested_clinic' => $clinicId, 'object_clinic' => $objectClinicId], 403);
        }

        $this->authorize( $actorWpUserId, $clinicId, $permission, $for_update ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing published named-argument API and surrounding legacy variable names.
    }
}
