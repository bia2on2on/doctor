<?php

declare(strict_types=1);

namespace ClinicCore\Application\Location;

use ClinicCore\Domain\Machine\VisitMachine;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * Narrow Doctor Portal Location continuity policy.
 *
 * Scheduling, availability, booking and generic staff scope continue to use
 * active Locations only. The Portal may select an inactive Location solely
 * while this clinician still has an actionable appointment or an active Visit
 * there, and only within the actor's current Clinic membership scope.
 */
final class DoctorPortalLocationEligibility
{
    /**
     * @param CpmsDb                 $db
     * @param MembershipRepository   $memberships
     */
    public function __construct(
        private readonly CpmsDb $db,
        private readonly MembershipRepository $memberships
    ) {
    }

    /**
     * Explicit Portal choices: active eligible Locations plus inactive
     * Locations with this clinician's active Visit or pending/confirmed
     * appointment. No primary/sole Location inference is performed.
     *
     * @return list<array{id:int,name:string,slug:string,timezone:string,is_primary:bool,is_active:bool}>
     */
    public function eligible_locations( int $clinic_id, int $wp_user_id ): array
    {
        $actor = $this->actor_context( $clinic_id, $wp_user_id );
        if ( null === $actor ) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT id, name, slug, timezone, is_primary, is_active FROM ' . $this->db->table( 'cpms_locations' ) .
            ' WHERE clinic_id = %d ORDER BY id ASC',
            [ $clinic_id ]
        );
        $continuity_ids = $this->continuity_location_ids( $clinic_id, $actor['clinician_id'] );
        $continuity     = array_fill_keys( $continuity_ids, true );
        $assigned       = null;
        if ( 'location' === $actor['scope_mode'] ) {
            $assigned = array_fill_keys( $this->memberships->location_ids_for( $actor['membership_id'] ), true );
        }

        $eligible = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $id        = (int) ( $row['id'] ?? 0 );
            $is_active = 1 === (int) ( $row['is_active'] ?? 0 );
            if ( $id <= 0 || ( ! $is_active && ! isset( $continuity[ $id ] ) ) ) {
                continue;
            }
            if ( null !== $assigned && ! isset( $assigned[ $id ] ) ) {
                continue;
            }
            $eligible[] = [
                'id'         => $id,
                'name'       => (string) ( $row['name'] ?? '' ),
                'slug'       => (string) ( $row['slug'] ?? '' ),
                'timezone'   => (string) ( $row['timezone'] ?? '' ),
                'is_primary' => 1 === (int) ( $row['is_primary'] ?? 0 ),
                'is_active'  => $is_active,
            ];
        }

        return $eligible;
    }

    /**
     * @return list<int>
     */
    public function eligible_location_ids( int $clinic_id, int $wp_user_id ): array
    {
        return array_map(
            static fn( array $location ): int => $location['id'],
            $this->eligible_locations( $clinic_id, $wp_user_id )
        );
    }

    /**
     * Inactive-Location Visit Workspace access requires the exact selected Visit
     * to remain active for the authenticated clinician. This prevents an
     * unrelated appointment at the same Location from reopening old work.
     */
    public function has_active_visit( int $clinic_id, int $wp_user_id, int $location_id, int $visit_id ): bool
    {
        if ( $visit_id <= 0 ) {
            return false;
        }
        $actor = $this->actor_context( $clinic_id, $wp_user_id );
        if ( null === $actor || ! $this->location_in_membership_scope( $clinic_id, $location_id, $actor ) ) {
            return false;
        }

        return $this->active_visit_exists( $clinic_id, $location_id, $actor['clinician_id'], $visit_id );
    }

    /**
     * Continuity gate for read-only historical Portal projections: an inactive
     * Location is usable only while this clinician has active treatment there.
     */
    public function has_active_visit_at_location( int $clinic_id, int $wp_user_id, int $location_id ): bool
    {
        $actor = $this->actor_context( $clinic_id, $wp_user_id );
        if ( null === $actor || ! $this->location_in_membership_scope( $clinic_id, $location_id, $actor ) ) {
            return false;
        }

        return $this->active_visit_exists( $clinic_id, $location_id, $actor['clinician_id'], null );
    }

    /**
     * @return array{clinician_id:int,membership_id:int,scope_mode:string}|null
     */
    private function actor_context( int $clinic_id, int $wp_user_id ): ?array
    {
        if ( $clinic_id <= 0 || $wp_user_id <= 0 ) {
            return null;
        }

        $membership = $this->memberships->find_active( $clinic_id, $wp_user_id );
        if ( null === $membership ) {
            return null;
        }
        $clinician_id = $this->memberships->active_clinician_id_for_wp_user( $wp_user_id );
        if ( null === $clinician_id || ! $this->memberships->clinician_participates_in( $clinician_id, $clinic_id ) ) {
            return null;
        }

        return [
            'clinician_id' => $clinician_id,
            'membership_id' => (int) $membership['id'],
            'scope_mode' => (string) ( $membership['scope_mode'] ?? 'clinic' ),
        ];
    }

    /**
     * @param array{clinician_id:int,membership_id:int,scope_mode:string} $actor
     */
    private function location_in_membership_scope( int $clinic_id, int $location_id, array $actor ): bool
    {
        if ( $location_id <= 0 ) {
            return false;
        }
        $clinic_map = $this->memberships->location_clinic_map( [ $location_id ] );
        if ( ( $clinic_map[ $location_id ] ?? null ) !== $clinic_id ) {
            return false;
        }
        if ( 'location' !== $actor['scope_mode'] ) {
            return true;
        }

        return in_array( $location_id, $this->memberships->location_ids_for( $actor['membership_id'] ), true );
    }

    /**
     * @return list<int>
     */
    private function continuity_location_ids( int $clinic_id, int $clinician_id ): array
    {
        $visit_statuses   = VisitMachine::ACTIVE_STATUSES;
        $visit_placeholders = implode( ',', array_fill( 0, count( $visit_statuses ), '%s' ) );
        $rows             = $this->db->fetchAll(
            'SELECT l.id FROM ' . $this->db->table( 'cpms_locations' ) . ' l' .
            ' WHERE l.clinic_id = %d AND l.is_active = 0' .
            ' AND (' .
            ' EXISTS (SELECT 1 FROM ' . $this->db->table( 'cpms_visits' ) . ' v' .
            ' WHERE v.clinic_id = %d AND v.location_id = l.id AND v.clinician_id = %d' .
            ' AND v.active = 1 AND v.status IN (' . $visit_placeholders . '))' .
            ' OR EXISTS (SELECT 1 FROM ' . $this->db->table( 'cpms_appointments' ) . ' a' .
            ' WHERE a.clinic_id = %d AND a.location_id = l.id AND a.clinician_id = %d' .
            " AND a.status IN ('pending', 'confirmed'))" .
            ' ) ORDER BY l.id ASC',
            array_merge( [ $clinic_id, $clinic_id, $clinician_id ], $visit_statuses, [ $clinic_id, $clinician_id ] )
        );

        $ids = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return array_values( array_unique( $ids ) );
    }

    private function active_visit_exists( int $clinic_id, int $location_id, int $clinician_id, ?int $visit_id ): bool
    {
        if ( $clinic_id <= 0 || $location_id <= 0 || $clinician_id <= 0 ) {
            return false;
        }

        $visit_statuses = VisitMachine::ACTIVE_STATUSES;
        $placeholders   = implode( ',', array_fill( 0, count( $visit_statuses ), '%s' ) );
        $visit_filter = null === $visit_id ? '' : ' AND v.id = %d';
        $params       = array_merge( [ $clinic_id, $location_id, $clinician_id ], $visit_statuses, [ $clinic_id ] );
        if ( null !== $visit_id ) {
            $params[] = $visit_id;
        }
        $value = $this->db->fetchValue(
            'SELECT v.id FROM ' . $this->db->table( 'cpms_visits' ) . ' v' .
            ' INNER JOIN ' . $this->db->table( 'cpms_locations' ) . ' l ON l.id = v.location_id' .
            ' WHERE v.clinic_id = %d AND v.location_id = %d AND v.clinician_id = %d' .
            ' AND v.active = 1 AND v.status IN (' . $placeholders . ')' .
            ' AND l.clinic_id = %d AND l.is_active = 0' . $visit_filter . ' LIMIT 1',
            $params
        );

        return null !== $value;
    }
}
