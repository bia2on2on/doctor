<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Read-only selectors for the authenticated patient's first-enrollment UI.
 *
 * Every query starts from an explicit user↔Organization identity link or from
 * the resulting Clinic set. No method accepts a Clinic chosen by the browser,
 * reads a foreign clinical row, or returns patient/identity/encounter data.
 */
final class PatientEnrollmentRepository
{
    public function __construct( private readonly CpmsDb $db ) {
    }

    /**
     * Organizations in which the user has exactly one explicit identity link,
     * with the owning Organization status. Bounded and deterministic.
     *
     * @return list<array{organization_id:int, identity_id:int, organization_status:string}>
     */
    public function verified_organization_identities( int $wp_user_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT l.organization_id, MIN(l.identity_id) AS identity_id,' .
            ' COUNT(DISTINCT l.identity_id) AS identity_count, o.status AS organization_status' .
            ' FROM ' . $this->db->table( 'cpms_patient_identity_links' ) . ' l' .
            ' INNER JOIN ' . $this->db->table( 'cpms_patient_identities' ) . ' i' .
            ' ON i.id = l.identity_id AND i.organization_id = l.organization_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_organizations' ) . ' o ON o.id = l.organization_id' .
            ' WHERE l.wp_user_id = %d' .
            ' GROUP BY l.organization_id, o.status' .
            ' ORDER BY l.organization_id ASC LIMIT 100',
            [ $wp_user_id ]
        );

        $verified = [];
        foreach ( $rows as $row ) {
            if ( (int) $row['identity_count'] !== 1 ) {
                continue; // Zero proof is not verified; ambiguity never becomes a fallback.
            }
            $verified[] = [
                'organization_id' => (int) $row['organization_id'],
                'identity_id' => (int) $row['identity_id'],
                'organization_status' => (string) $row['organization_status'],
            ];
        }

        return $verified;
    }

    /**
     * Clinics in one active Organization where this verified identity has no
     * local clinical record yet and the current user has no active local link.
     * Both existence checks are boolean-only and never read clinical fields from
     * the destination or any other Clinic; the stricter user-link check avoids
     * offering a second (legacy) record in a Clinic the patient already uses.
     *
     * @return list<array{id:int, name:string}>
     */
    public function destination_clinics( int $organization_id, int $identity_id, int $wp_user_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT c.id, c.name' .
            ' FROM ' . $this->db->table( 'cpms_clinics' ) . ' c' .
            ' WHERE c.organization_id = %d' .
            ' AND NOT EXISTS (SELECT 1 FROM ' . $this->db->table( 'cpms_patients' ) . ' p' .
            ' WHERE p.clinic_id = c.id AND p.identity_id = %d)' .
            ' AND NOT EXISTS (SELECT 1 FROM ' . $this->db->table( 'cpms_patient_user_links' ) . ' local_link' .
            ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' local_patient' .
            ' ON local_patient.id = local_link.patient_id AND local_patient.clinic_id = local_link.clinic_id' .
            " WHERE local_link.clinic_id = c.id AND local_link.wp_user_id = %d AND local_patient.status = 'active')" .
            ' ORDER BY c.name ASC, c.id ASC LIMIT 100',
            [ $organization_id, $identity_id, $wp_user_id ]
        );

        $clinics = [];
        foreach ( $rows as $row ) {
            $clinics[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return $clinics;
    }

    /**
     * Boolean-only returning evidence for the preview: a completed encounter in
     * the same Organization, strictly before signed T0, reached through the
     * current session's explicit identity link. No row or clinical field leaves
     * this method.
     */
    public function identity_has_pre_t0_encounter(
        int $wp_user_id,
        int $identity_id,
        int $organization_id,
        int $expires_at
    ): bool {
        if ( $wp_user_id <= 0 || $identity_id <= 0 || $organization_id <= 0 || $expires_at <= 0 ) {
            return false;
        }

        $value = $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->db->table( 'cpms_patient_identity_links' ) . ' verified_link' .
            ' INNER JOIN ' . $this->db->table( 'cpms_patient_identities' ) . ' identity_row' .
            ' ON identity_row.id = verified_link.identity_id AND identity_row.organization_id = verified_link.organization_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' previous_patient' .
            ' ON previous_patient.identity_id = identity_row.id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_clinics' ) . ' previous_clinic' .
            ' ON previous_clinic.id = previous_patient.clinic_id AND previous_clinic.organization_id = identity_row.organization_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_visits' ) . ' encounter' .
            ' ON encounter.patient_id = previous_patient.id AND encounter.clinic_id = previous_clinic.id' .
            " WHERE verified_link.wp_user_id = %d AND verified_link.identity_id = %d" .
            ' AND verified_link.organization_id = %d' .
            " AND encounter.status IN ('consultation_completed', 'awaiting_payment', 'paid', 'checked_out')" .
            ' AND encounter.consultation_completed_at IS NOT NULL' .
            ' AND encounter.consultation_completed_at < %s LIMIT 1',
            [ $wp_user_id, $identity_id, $organization_id, gmdate( 'Y-m-d H:i:s', $expires_at ) ]
        );

        return $value !== null;
    }
}
