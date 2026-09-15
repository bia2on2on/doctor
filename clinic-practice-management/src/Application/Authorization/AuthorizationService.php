<?php

declare(strict_types=1);

namespace ClinicCore\Application\Authorization;

use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * Phase 3 Slice 1 — RED stub (always deny).
 *
 * This is intentionally incomplete to produce a VALID RED:
 * - class exists (no parse error)
 * - bootstrap succeeds
 * - fixtures succeed
 * - product path reached (can() called)
 * - ALLOW contract fails because behavior is missing
 *
 * GREEN implementation will replace this stub.
 */
final class AuthorizationService
{
    public function __construct(
        private readonly MembershipRepository $memberships
    ) {
    }

    public function can(int $actorWpUserId, int $clinicId, string $permission): bool
    {
        // RED: always deny, so ALLOW test fails
        return false;
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(int $actorWpUserId, int $clinicId, string $permission): void
    {
        if (!$this->can($actorWpUserId, $clinicId, $permission)) {
            $code = $actorWpUserId <= 0 ? 'AUTH_UNAUTHENTICATED' : 'AUTH_DENIED';
            throw new AuthorizationException($code, $code, [], 403);
        }
    }

    public function canForObject(int $actorWpUserId, int $clinicId, string $permission, int $objectClinicId): bool
    {
        return false;
    }

    /**
     * @throws AuthorizationException
     */
    public function authorizeForObject(int $actorWpUserId, int $clinicId, string $permission, int $objectClinicId): void
    {
        if (!$this->canForObject($actorWpUserId, $clinicId, $permission, $objectClinicId)) {
            if ($objectClinicId !== $clinicId) {
                throw new AuthorizationException('AUTH_CROSS_CLINIC', 'AUTH_CROSS_CLINIC', [], 403);
            }
            $code = $actorWpUserId <= 0 ? 'AUTH_UNAUTHENTICATED' : 'AUTH_DENIED';
            throw new AuthorizationException($code, $code, [], 403);
        }
    }
}
