<?php

declare(strict_types=1);

namespace ClinicCore\Application\Handwriting;

use ClinicCore\Domain\Clinical\DoctorDisplayName;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Db\CpmsDb;

/** Display-only stationery fields, read from one already-authorized Visit. */
final class PrescriptionPaperContext {
    /** @return array<string, string>|null */
    public static function for_visit( CpmsDb $db, int $visit_id, int $clinic_id, int $location_id, int $clinician_id ): ?array {
        $row = $db->fetchRow(
            'SELECT c.full_name AS doctor, l.name AS location, p.first_name, p.last_name, v.visit_date' .
            ' FROM ' . $db->table( 'cpms_visits' ) . ' v' .
            ' INNER JOIN ' . $db->table( 'cpms_clinicians' ) . ' c ON c.id = v.clinician_id AND c.is_active = 1' .
            ' INNER JOIN ' . $db->table( 'cpms_locations' ) . ' l ON l.id = v.location_id AND l.clinic_id = v.clinic_id AND l.is_active = 1' .
            ' INNER JOIN ' . $db->table( 'cpms_patients' ) . ' p ON p.id = v.patient_id AND p.clinic_id = v.clinic_id' .
            ' WHERE v.id = %d AND v.clinic_id = %d AND v.location_id = %d AND v.clinician_id = %d LIMIT 1',
            [ $visit_id, $clinic_id, $location_id, $clinician_id ]
        );
        if ( $row === null ) {
            return null;
        }
        return self::present_row( $row );
    }

    /**
     * Preserve the operational date and real names; labels must not invent names.
     *
     * @param array<string, mixed> $row Already-authorized joined row.
     * @return array<string, string>
     */
    private static function present_row( array $row ): array {
        $doctor  = (string) $row['doctor'];
        $patient = trim( (string) $row['first_name'] . ' ' . (string) $row['last_name'] );
        $date    = (string) $row['visit_date'];
        try {
            $jalali_date = Jalali::format_display_ymd( $date );
        } catch ( \DomainException ) {
            $jalali_date = '';
        }

        return [
            'doctor'      => '' === DoctorDisplayName::with_title( $doctor )
                ? 'نام پزشک ثبت نشده' : $doctor,
            'location'    => (string) $row['location'],
            'patient'     => 1 === preg_match( '/[^\s\x{00A0}]/u', $patient )
                ? $patient : 'نام بیمار ثبت نشده',
            'date'        => $date,
            'jalali_date' => $jalali_date,
        ];
    }
}
