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
final class TrustedClinicEstablisher
{
    public function __construct(
        private readonly CpmsDb $db,
        private readonly MembershipRepository $memberships
    ) {
    }

    /**
     * @throws ScopeRequiredException
     */
    public function establish(int $wpUserId, ?int $clinicId, ?int $locationId = null): ClinicScope
    {
        if ($wpUserId <= 0) {
            $this->unavailable('unauthenticated');
        }

        if ($clinicId === null) {
            if ($locationId !== null) {
                throw new ScopeRequiredException(
                    'CLINIC_VALIDATION_FAILED',
                    'Location cannot establish Clinic.',
                    ['field' => 'location_id'],
                    422
                );
            }

            return $this->fromUniqueMembership($wpUserId);
        }

        return $this->fromRequestedClinic($wpUserId, $clinicId, $locationId);
    }

    /**
     * @throws ScopeRequiredException
     */
    private function fromUniqueMembership(int $wpUserId): ClinicScope
    {
        $ids = $this->memberships->active_clinic_ids_for_user($wpUserId);
        if ($ids === []) {
            $this->unavailable('no_membership');
        }
        if (count($ids) !== 1) {
            throw new ScopeRequiredException(
                'CLINIC_SCOPE_REQUIRED',
                'Explicit clinic is required when the user has more than one active membership.',
                [],
                400
            );
        }

        return $this->verifiedScope($wpUserId, $ids[0], null);
    }

    /**
     * @throws ScopeRequiredException
     */
    private function fromRequestedClinic(int $wpUserId, int $clinicId, ?int $locationId): ClinicScope
    {
        if ($clinicId <= 0) {
            throw new ScopeRequiredException(
                'CLINIC_VALIDATION_FAILED',
                'Invalid clinic id.',
                ['field' => 'clinic_id'],
                422
            );
        }

        return $this->verifiedScope($wpUserId, $clinicId, $locationId);
    }

    /**
     * @throws ScopeRequiredException
     */
    private function verifiedScope(int $wpUserId, int $clinicId, ?int $locationId): ClinicScope
    {
        if ($this->memberships->find_active($clinicId, $wpUserId) === null) {
            $this->unavailable('membership');
        }

        $clinic = $this->db->fetchRow(
            'SELECT c.id, c.organization_id, o.status AS organization_status
               FROM ' . $this->db->table('cpms_clinics') . ' c
               INNER JOIN ' . $this->db->table('cpms_organizations') . ' o ON o.id = c.organization_id
              WHERE c.id = %d
              LIMIT 1',
            [$clinicId]
        );
        if ($clinic === null) {
            $this->unavailable('clinic');
        }
        if ((string) ($clinic['organization_status'] ?? '') !== 'active') {
            $this->unavailable('organization');
        }

        $organizationId = (int) $clinic['organization_id'];
        $scope = ClinicScope::forClinic($clinicId)->withOrganization($organizationId);

        if ($locationId === null) {
            return $scope;
        }

        if ($locationId <= 0) {
            throw new ScopeRequiredException(
                'CLINIC_VALIDATION_FAILED',
                'Invalid location id.',
                ['field' => 'location_id'],
                422
            );
        }

        $locationClinic = $this->memberships->location_clinic_map([$locationId]);
        if (($locationClinic[$locationId] ?? null) !== $clinicId) {
            $this->unavailable('location');
        }

        $active = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table('cpms_locations') .
            ' WHERE id = %d AND clinic_id = %d AND is_active = 1 LIMIT 1',
            [$locationId, $clinicId]
        );
        if ($active === null) {
            $this->unavailable('location');
        }

        return $scope->withLocation($locationId);
    }

    /**
     * @throws ScopeRequiredException
     */
    private function unavailable(string $reason): never
    {
        throw new ScopeRequiredException(
            'CLINIC_SCOPE_UNAVAILABLE',
            'Trusted clinic context is not available.',
            ['reason' => $reason],
            403
        );
    }
}
