<?php

declare(strict_types=1);

namespace ClinicCore\Application\Authorization;

use ClinicCore\Auth\RolesAndCapabilities;
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
        private readonly MembershipRepository $memberships
    ) {
    }

    /**
     * Clinic-scoped permission check.
     *
     * @param int    $actorWpUserId Authenticated WP user id (>0)
     * @param int    $clinicId      Trusted durable clinic id (>0, from TrustedClinicEstablisher or durable ownership)
     * @param string $permission    Requested scoped permission (e.g. cpms_patient_read)
     */
    public function can(int $actorWpUserId, int $clinicId, string $permission): bool
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
        $membership = $this->memberships->find_active($clinicId, $actorWpUserId);
        if ($membership === null) {
            return false;
        }

        $membershipId = (int) ($membership['id'] ?? 0);
        if ($membershipId <= 0) {
            return false;
        }

        // Fetch explicit capability overrides for this membership (deny > grant)
        $caps = $this->memberships->capabilities_for($membershipId);

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

        // Role preset (clinic-scoped via membership role_key, not WP global role)
        $roleKey = (string) ($membership['role_key'] ?? '');
        if ($roleKey !== '') {
            $presetMap = RolesAndCapabilities::capsMap($roleKey);
            if (isset($presetMap[$permission]) && $presetMap[$permission] === true) {
                return true;
            }
        }

        // Deny by default
        return false;
    }

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
     * Authorize or throw typed exception (fail closed).
     *
     * @throws AuthorizationException
     */
    public function authorize(int $actorWpUserId, int $clinicId, string $permission): void
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

        $membership = $this->memberships->find_active($clinicId, $actorWpUserId);
        if ($membership === null) {
            // Distinguish suspended vs non-member for audit, but both deny
            $any = $this->memberships->find($clinicId, $actorWpUserId);
            if ($any !== null && (string) ($any['status'] ?? '') === 'suspended') {
                throw new AuthorizationException('AUTH_SUSPENDED', 'AUTH_SUSPENDED', ['clinic_id' => $clinicId], 403);
            }
            throw new AuthorizationException('AUTH_NO_MEMBERSHIP', 'AUTH_NO_MEMBERSHIP', ['clinic_id' => $clinicId], 403);
        }

        if (!$this->can($actorWpUserId, $clinicId, $permission)) {
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
    public function authorizeForObject(int $actorWpUserId, int $clinicId, string $permission, int $objectClinicId): void
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

        $this->authorize($actorWpUserId, $clinicId, $permission);
    }
}
