<?php

declare( strict_types=1 );

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Domain\Licensing\ReturningPatientEvidence;
use ClinicCore\Infrastructure\Db\CpmsDb;

/** Read-only Organization eligibility aggregate; no cross-Clinic PHI leaves here. */
final class ReturningPatientRepository implements ReturningPatientEvidence {
    public function __construct( private readonly CpmsDb $db ) {
    }

    public function qualifies( int $patient_id, int $clinic_id, int $expires_at ): bool {
        if ( $patient_id <= 0 || $clinic_id <= 0 || $expires_at <= 0 ) {
            return false;
        }

        // A freshly generated identity or a demographic match is NOT verification.
        // Require the foundation's explicit Organization user/identity link as well
        // as a completed encounter with a durable, strictly pre-T0 timestamp.
        $value = $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->db->table( 'cpms_patients' ) . ' current_patient' .
            ' INNER JOIN ' . $this->db->table( 'cpms_clinics' ) . ' current_clinic ON current_clinic.id = current_patient.clinic_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_patient_identities' ) . ' identity_row ON identity_row.id = current_patient.identity_id AND identity_row.organization_id = current_clinic.organization_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_patient_identity_links' ) . ' verified_link ON verified_link.identity_id = identity_row.id AND verified_link.organization_id = identity_row.organization_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' previous_patient ON previous_patient.identity_id = identity_row.id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_clinics' ) . ' previous_clinic ON previous_clinic.id = previous_patient.clinic_id AND previous_clinic.organization_id = identity_row.organization_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_visits' ) . ' encounter ON encounter.patient_id = previous_patient.id AND encounter.clinic_id = previous_clinic.id' .
            " WHERE current_patient.id = %d AND current_patient.clinic_id = %d AND encounter.status IN ('consultation_completed', 'awaiting_payment', 'paid', 'checked_out')" .
            ' AND encounter.consultation_completed_at IS NOT NULL AND encounter.consultation_completed_at < %s LIMIT 1',
            [ $patient_id, $clinic_id, gmdate( 'Y-m-d H:i:s', $expires_at ) ]
        );

        return $value !== null;
    }
}
