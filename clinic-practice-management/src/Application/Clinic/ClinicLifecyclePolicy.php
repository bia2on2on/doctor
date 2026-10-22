<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Clinic;

use ClinicCore\Domain\Clinic\ClinicLifecycleState;
use ClinicCore\Domain\Membership\MembershipException;
use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Central Clinic lifecycle gate for NEW participation (enrollment / reactivation).
 *
 * This is the single place that reads the durable lifecycle evidence and turns it into
 * the decision defined by ClinicLifecycleState. Every path that creates a Clinic
 * membership or reactivates one consults it, so the frontend Management Portal, the
 * Classic staff screen, the facility-plan staff assignment, the Organization Clinic
 * manager designation and the first-practice bootstrap all obey exactly the same rule.
 *
 * Why the audit trail is trustworthy evidence and not a user-controlled label:
 * provision_clinic() is the ONLY insert path for cpms_clinics and set_clinic_status() is
 * the ONLY writer of cpms_clinics.status; both write their record inside the same
 * transaction as the row change, an identical desired status is a no-op that writes
 * nothing, AuditLogger throws when a record is not persisted (so the transaction rolls
 * back and no status survives without its record), nothing in src/ deletes audit rows,
 * and every row is hash-chained (prev_hash/row_hash). A caller therefore cannot
 * manufacture HELD from request input, and cannot erase a suspension without breaking the
 * chain — which downgrades the Clinic to UNVERIFIED, i.e. still refused.
 *
 * Scope discipline: this is a lifecycle-state decision only. It grants nothing, it never
 * widens a WordPress capability, and every caller still establishes Clinic authority
 * independently (trusted scope before object lookup, DENY > GRANT).
 */
final class ClinicLifecyclePolicy {

    public const STATE_ACTIVE                 = ClinicLifecycleState::ACTIVE;
    public const STATE_HELD                   = ClinicLifecycleState::HELD;
    public const STATE_SUSPENDED_OPERATIONAL  = ClinicLifecycleState::SUSPENDED_OPERATIONAL;
    public const STATE_UNVERIFIED             = ClinicLifecycleState::UNVERIFIED;

    private const STATUS_SUSPENDED = 'suspended';
    private const RESOURCE_CLINIC  = 'clinic';

    private function __construct() {
    }

    /**
     * Convenience delegate so callers need only this one policy class.
     */
    public static function accepts_new_membership( string $state ): bool {
        return ClinicLifecycleState::accepts_new_membership( $state );
    }

    /**
     * Read the durable lifecycle state of one Clinic.
     *
     * A missing Clinic row, a non-positive id and any read that cannot establish the
     * state are all reported as UNVERIFIED (fail-closed).
     */
    public static function state_of( CpmsDb $db, int $clinic_id ): string {
        if ( $clinic_id <= 0 ) {
            return self::STATE_UNVERIFIED;
        }

        $clinic = $db->fetchRow(
            'SELECT id, status FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $clinic_id ]
        );
        if ( $clinic === null ) {
            return self::STATE_UNVERIFIED;
        }

        $status = (string) ( $clinic['status'] ?? '' );
        if ( $status !== self::STATUS_SUSPENDED ) {
            // Only a suspended row needs lifecycle evidence; anything else is decided by
            // the pure decision table without another read.
            return ClinicLifecycleState::classify( $status, null );
        }

        return ClinicLifecycleState::classify( $status, self::latest_lifecycle_record( $db, $clinic_id ) );
    }

    /**
     * Fail-closed gate for NEW participation in one Clinic.
     *
     * @throws MembershipException when the Clinic does not exist, or when its lifecycle
     *                             state refuses new participation.
     */
    public static function assert_accepts_new_membership( CpmsDb $db, int $clinic_id ): void {
        $exists = $db->fetchValue(
            'SELECT id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $clinic_id ]
        );
        if ( $exists === null ) {
            throw MembershipException::not_found( __( 'کلینیک', 'cpms' ), $clinic_id );
        }

        $state = self::state_of( $db, $clinic_id );
        if ( ClinicLifecycleState::accepts_new_membership( $state ) ) {
            return;
        }

        throw $state === self::STATE_SUSPENDED_OPERATIONAL
            ? MembershipException::clinic_suspended( $clinic_id )
            : MembershipException::clinic_lifecycle_unverified( $clinic_id );
    }

    /**
     * The Clinic's most recent durable lifecycle record, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    private static function latest_lifecycle_record( CpmsDb $db, int $clinic_id ): ?array {
        $actions = ClinicLifecycleState::lifecycle_actions();

        $row = $db->fetchRow(
            'SELECT action, meta_json, after_json FROM ' . $db->table( 'cpms_audit_logs' )
                . ' WHERE resource_type = %s AND resource_id = %d AND action IN (%s, %s, %s)'
                . ' ORDER BY id DESC LIMIT 1',
            [ self::RESOURCE_CLINIC, $clinic_id, $actions[0], $actions[1], $actions[2] ]
        );

        return $row === null ? null : (array) $row;
    }
}
