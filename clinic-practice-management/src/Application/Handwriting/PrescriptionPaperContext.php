<?php

declare(strict_types=1);

namespace ClinicCore\Application\Handwriting;

use ClinicCore\Infrastructure\Db\CpmsDb;

/** Display-only stationery fields, read from one already-authorized Visit. */
final class PrescriptionPaperContext {
    /** @return array<string, string>|null */
    public static function forVisit( CpmsDb $db, int $visitId, int $clinicId, int $locationId, int $clinicianId ): ?array {
        $row = $db->fetchRow(
            'SELECT c.full_name AS doctor, l.name AS location, p.first_name, p.last_name, v.visit_date' .
            ' FROM ' . $db->table( 'cpms_visits' ) . ' v' .
            ' INNER JOIN ' . $db->table( 'cpms_clinicians' ) . ' c ON c.id = v.clinician_id AND c.is_active = 1' .
            ' INNER JOIN ' . $db->table( 'cpms_locations' ) . ' l ON l.id = v.location_id AND l.clinic_id = v.clinic_id AND l.is_active = 1' .
            ' INNER JOIN ' . $db->table( 'cpms_patients' ) . ' p ON p.id = v.patient_id AND p.clinic_id = v.clinic_id' .
            ' WHERE v.id = %d AND v.clinic_id = %d AND v.location_id = %d AND v.clinician_id = %d LIMIT 1',
            [ $visitId, $clinicId, $locationId, $clinicianId ]
        );
        if ( $row === null ) {
            return null;
        }
        return [
            'doctor' => (string) $row['doctor'],
            'location' => (string) $row['location'],
            'patient' => trim( (string) $row['first_name'] . ' ' . (string) $row['last_name'] ),
            'date' => (string) $row['visit_date'],
        ];
    }
}
