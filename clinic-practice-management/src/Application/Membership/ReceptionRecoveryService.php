<?php

declare(strict_types=1);

namespace ClinicCore\Application\Membership;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Clinic\ClinicLifecyclePolicy;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use RuntimeException;

/**
 * Narrow, additive Clinic-local recovery. The global replacement primitive in
 * MembershipService is intentionally not used: a clinician can participate in
 * several Clinics and a manager of one Clinic cannot erase or change the others.
 */
final class ReceptionRecoveryService {
    public function __construct(
        private readonly CpmsDb $db,
        private readonly MembershipRepository $memberships,
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * Add selected eligible locations without modifying existing assignments.
     * The clinician's existing global primary is never moved by this recovery
     * action. When none exists, a selected location becomes primary.
     *
     * @param list<int> $location_ids
     * @return bool True if a mutation and audit were committed; false for a no-op.
     */
    public function assign( int $actor_id, int $clinic_id, int $clinician_id, array $location_ids, ?int $primary_id ): bool {
        if ( $actor_id <= 0 || $clinic_id <= 0 || $clinician_id <= 0 || $location_ids === [] || count( $location_ids ) > 500 ) {
            throw new RuntimeException( 'invalid recovery selection' );
        }
        foreach ( $location_ids as $location_id ) {
            if ( ! is_int( $location_id ) || $location_id <= 0 ) {
                throw new RuntimeException( 'invalid recovery selection' );
            }
        }
        $location_ids = array_values( array_unique( $location_ids ) );
        sort( $location_ids, SORT_NUMERIC );
        if ( $primary_id !== null && ! in_array( $primary_id, $location_ids, true ) ) {
            throw new RuntimeException( 'invalid recovery selection' );
        }

        return $this->db->transactional(
            function () use ( $actor_id, $clinic_id, $clinician_id, $location_ids, $primary_id ): bool {
                // Follow the existing Organization -> Clinic lock order before
                // membership/profile locks. Lifecycle and authorization decisions
                // are rechecked in the same transaction as the mutation and audit.
                $this->lock_clinic_context( $clinic_id );
                ClinicLifecyclePolicy::assert_accepts_new_membership( $this->db, $clinic_id );
                ( new TrustedClinicEstablisher( $this->db, $this->memberships ) )->establish( $actor_id, $clinic_id, null, true );
                $this->authorization->authorize( $actor_id, $clinic_id, RolesAndCapabilities::CONFIG, true );
                $actor = $this->memberships->find_active( $clinic_id, $actor_id, true );
                if ( $actor === null ) {
                        throw new RuntimeException( 'recovery actor unavailable' );
                }

                $candidate      = $this->db->fetchRow(
                    'SELECT wp_user_id FROM ' . $this->db->table( 'cpms_clinicians' ) . ' WHERE id = %d LIMIT 1',
                    [ $clinician_id ]
                );
                $clinician_user = (int) ( $candidate['wp_user_id'] ?? 0 );
                if ( $clinician_user <= 0 ) {
                    throw new RuntimeException( 'recovery clinician unavailable' );
                }
                // Lock membership before profile, as in facility setup. Recheck the
                // profile after locking so the initial nonlocking lookup grants nothing.
                $clinician_membership = $this->memberships->find_active( $clinic_id, $clinician_user, true );
                $clinician            = $this->db->fetchRowForUpdate(
                    'SELECT id, wp_user_id, is_active FROM ' . $this->db->table( 'cpms_clinicians' ) . ' WHERE id = %d LIMIT 1',
                    [ $clinician_id ]
                );
                if ( $clinician_membership === null || $clinician === null ||
                (int) ( $clinician['wp_user_id'] ?? 0 ) !== $clinician_user || (int) ( $clinician['is_active'] ?? 0 ) !== 1 ) {
                    throw new RuntimeException( 'recovery clinician unavailable' );
                }

                $this->assert_location_scope( $actor, $location_ids );
                $this->assert_location_scope( $clinician_membership, $location_ids );
                foreach ( $location_ids as $location_id ) {
                        $location = $this->db->fetchRowForUpdate(
                            'SELECT id FROM ' . $this->db->table( 'cpms_locations' ) . ' WHERE id = %d AND clinic_id = %d AND is_active = 1 LIMIT 1',
                            [ $location_id, $clinic_id ]
                        );
                    if ( $location === null ) {
                        throw new RuntimeException( 'recovery location unavailable' );
                    }
                }

                $rows = $this->db->fetchAllForUpdate(
                    'SELECT location_id, is_primary FROM ' . $this->db->table( 'cpms_clinician_locations' ) . ' WHERE clinician_id = %d ORDER BY location_id',
                    [ $clinician_id ]
                );
                if ( $this->db->wpdb()->last_error !== '' ) {
                    throw new RuntimeException( 'recovery assignment read failed' );
                }
                $existing         = [];
                $existing_primary = null;
                foreach ( $rows as $row ) {
                    $id              = (int) ( $row['location_id'] ?? 0 );
                    $existing[ $id ] = true;
                    if ( (int) ( $row['is_primary'] ?? 0 ) === 1 ) {
                        if ( $existing_primary !== null ) {
                            throw new RuntimeException( 'ambiguous recovery primary' );
                        }
                        $existing_primary = $id;
                    }
                }
                if ( $existing_primary !== null && $primary_id !== null && $primary_id !== $existing_primary ) {
                        // In particular, never demote another Clinic's primary row.
                        throw new RuntimeException( 'recovery primary already exists' );
                }
                $chosen_primary = $existing_primary ?? $primary_id ?? $location_ids[0];
                $before         = array_values( array_filter( $location_ids, static fn( int $id ): bool => isset( $existing[ $id ] ) ) );
                $changed        = false;
                $now            = $this->db->nowUtcSql();
                foreach ( $location_ids as $location_id ) {
                    if ( isset( $existing[ $location_id ] ) ) {
                        continue;
                    }
                    if ( ! $this->db->insert(
                        'cpms_clinician_locations',
                        [
							'clinician_id' => $clinician_id,
							'location_id'  => $location_id,
							'is_primary'   => $existing_primary === null && $location_id === $chosen_primary ? 1 : 0,
							'created_at'   => $now,
                        ]
                    ) ) {
                            throw new RuntimeException( 'recovery insertion failed' );
                    }
                    $changed = true;
                }
                if ( $existing_primary === null && isset( $existing[ $chosen_primary ] ) ) {
                    $updated = $this->db->execute(
                        'UPDATE ' . $this->db->table( 'cpms_clinician_locations' ) . ' SET is_primary = 1 WHERE clinician_id = %d AND location_id = %d AND is_primary = 0',
                        [ $clinician_id, $chosen_primary ]
                    );
                    if ( $updated !== 1 ) {
                        throw new RuntimeException( 'recovery primary update failed' );
                    }
                    $changed = true;
                }
                if ( ! $changed ) {
                    return false;
                }
                foreach ( $location_ids as $location_id ) {
                    $stored = $this->db->fetchRowForUpdate(
                        'SELECT is_primary FROM ' . $this->db->table( 'cpms_clinician_locations' ) . ' WHERE clinician_id = %d AND location_id = %d LIMIT 1',
                        [ $clinician_id, $location_id ]
                    );
                    if ( $stored === null || ( $location_id === $chosen_primary && (int) $stored['is_primary'] !== 1 ) ) {
                        throw new RuntimeException( 'recovery readback failed' );
                    }
                }
                $this->audit->log(
                    'CLINICIAN_LOCATION_RECOVERY',
                    [ 'wp_user_id' => $actor_id, 'role' => (string) ( $actor['role_key'] ?? '' ) ],
                    'clinician',
                    $clinician_id,
                    null,
                    [ 'selected_existing_location_ids' => $before ],
                    [ 'selected_location_ids' => $location_ids ],
                    [ 'op' => 'reception_recovery' ],
                    $clinic_id
                );
                return true;
            }
        );
    }

    /** @param array<string, mixed> $membership @param list<int> $location_ids */
    private function assert_location_scope( array $membership, array $location_ids ): void {
        $mode = (string) ( $membership['scope_mode'] ?? '' );
        if ( $mode === 'clinic' ) {
            return;
        }
        if ( $mode !== 'location' ) {
            throw new RuntimeException( 'recovery membership scope unavailable' );
        }
        $rows = $this->db->fetchAllForUpdate(
            'SELECT location_id FROM ' . $this->db->table( 'cpms_membership_locations' ) . ' WHERE membership_id = %d ORDER BY location_id',
            [ (int) ( $membership['id'] ?? 0 ) ]
        );
        if ( $this->db->wpdb()->last_error !== '' ) {
            throw new RuntimeException( 'recovery membership scope read failed' );
        }
        $allowed = array_map( static fn( array $row ): int => (int) $row['location_id'], $rows );
        foreach ( $location_ids as $location_id ) {
            if ( ! in_array( $location_id, $allowed, true ) ) {
                throw new RuntimeException( 'recovery membership scope unavailable' );
            }
        }
    }

    private function lock_clinic_context( int $clinic_id ): void {
        $candidate       = $this->db->fetchRow(
            'SELECT organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $clinic_id ]
        );
        $organization_id = (int) ( $candidate['organization_id'] ?? 0 );
        if ( $organization_id <= 0 ) {
            throw new RuntimeException( 'recovery clinic unavailable' );
        }
        $organization = $this->db->fetchRowForUpdate(
            'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
            [ $organization_id ]
        );
        $clinic       = $this->db->fetchRowForUpdate(
            'SELECT id, organization_id, status FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $clinic_id ]
        );
        // The locking read, not the earlier repeatable-read snapshot, decides
        // whether this operational recovery may accept a new assignment.
        if ( $organization === null || (string) ( $organization['status'] ?? '' ) !== 'active' ||
            $clinic === null || (int) ( $clinic['organization_id'] ?? 0 ) !== $organization_id || (string) ( $clinic['status'] ?? '' ) !== 'active' ) {
            throw new RuntimeException( 'recovery clinic unavailable' );
        }
    }
}
