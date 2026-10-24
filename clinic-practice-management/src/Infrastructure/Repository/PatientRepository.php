<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository بیمار — ADR-0021 (Domain-Focused، از F3).
 *
 * - Queryهای Patient و پیوند پایدار Patient⇄User (نه God Repository).
 * - Mass Assignment Protection: فیلدها با Whitelist داخلی — هرگز مستقیم از Request.
 * - Transaction Ownership: Service (این Repository Transaction نمی‌بندد).
 */
final class PatientRepository
{
    /**
     * فیلدهای قابل ساخت (بازیافت از D4 — mobile normalized).
     */
    private const CREATE_FIELDS = [
        'clinic_id', 'mrn', 'first_name', 'last_name', 'mobile', 'national_id',
        'birth_date', 'gender', 'address', 'phone',
        'emergency_contact_name', 'emergency_contact_phone', 'blood_group',
        'medication_allergies', 'other_allergies', 'chronic_conditions',
        'medical_history', 'surgery_history', 'current_medications', 'status',
        'created_at', 'updated_at',
    ];

    /**
     * فیلدهای قابل ویرایش (MRN/clinic_id/mobile-immutable به‌جز Policy Change مجزأ).
     */
    private const UPDATE_FIELDS = [
        'first_name', 'last_name', 'national_id', 'birth_date', 'gender',
        'address', 'phone',
        'emergency_contact_name', 'emergency_contact_phone', 'blood_group',
        'medication_allergies', 'other_allergies', 'chronic_conditions',
        'medical_history', 'surgery_history', 'current_medications', 'status',
        'archived_at', 'archive_reason', 'updated_at',
    ];

    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_patients') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByMobile(int $clinicId, string $normalizedMobile): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_patients') .
            ' WHERE clinic_id = %d AND mobile = %s AND status = %s LIMIT 1',
            [$clinicId, $normalizedMobile, 'active']
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_by_national_id( int $clinic_id, string $national_id ): ?array {
        return $this->db->fetchRow(
            'SELECT id FROM ' . $this->db->table( 'cpms_patients' ) .
            ' WHERE clinic_id = %d AND national_id = %s LIMIT 1',
            [ $clinic_id, $national_id ]
        );
    }

    /**
     * Bounded active durable-link selector records for one authenticated user.
     *
     * @return list<array<string, mixed>>
     */
    public function active_linked_records_for_user( int $wp_user_id ): array {
        return $this->db->fetchAll(
            'SELECT l.id AS link_id, l.clinic_id, c.name AS clinic_name,' .
            ' p.id AS patient_id, p.first_name, p.last_name, p.mrn, l.is_primary' .
            ' FROM ' . $this->db->table( 'cpms_patient_user_links' ) . ' l' .
            ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' p' .
            ' ON p.id = l.patient_id AND p.clinic_id = l.clinic_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_clinics' ) . ' c' .
            ' ON c.id = l.clinic_id' .
            ' WHERE l.wp_user_id = %d AND p.status = %s' .
            $this->clinical_access_identity_predicate() .
            ' ORDER BY l.is_primary DESC, l.id ASC',
            [ $wp_user_id, 'active' ]
        );
    }

    /**
     * Trusted booking destinations derived only from active, Clinic-consistent
     * subject links. This intentionally does not apply clinical identity proof:
     * an explicitly linked dependent may be booked while remaining absent from
     * profile, visits, prescriptions and files selectors.
     *
     * @return list<array{id:int, name:string}>
     */
    public function active_booking_clinics_for_user( int $wp_user_id ): array {
        if ( $wp_user_id <= 0 ) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT DISTINCT c.id, c.name'
            . ' FROM ' . $this->db->table( 'cpms_patient_user_links' ) . ' l'
            . ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' p'
            . ' ON p.id = l.patient_id AND p.clinic_id = l.clinic_id'
            . ' INNER JOIN ' . $this->db->table( 'cpms_clinics' ) . ' c ON c.id = l.clinic_id'
            . ' INNER JOIN ' . $this->db->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id'
            . " WHERE l.wp_user_id = %d AND p.status = 'active'"
            . " AND c.status = 'active' AND o.status = 'active'"
            . ' ORDER BY c.name ASC, c.id ASC',
            [ $wp_user_id ]
        );

        return array_map(
            static fn ( array $row ): array => [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
            ],
            $rows
        );
    }

    /**
     * Exact identity-aware clinical ownership predicate for object-level reads
     * such as protected file streaming. Booking-only dependent links fail here.
     */
    public function user_has_clinical_access_to_patient( int $wp_user_id, int $patient_id, int $clinic_id ): bool {
        if ( $wp_user_id <= 0 || $patient_id <= 0 || $clinic_id <= 0 ) {
            return false;
        }

        $value = $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->db->table( 'cpms_patient_user_links' ) . ' l'
            . ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' p'
            . ' ON p.id = l.patient_id AND p.clinic_id = l.clinic_id'
            . ' INNER JOIN ' . $this->db->table( 'cpms_clinics' ) . ' c ON c.id = l.clinic_id'
            . " WHERE l.wp_user_id = %d AND l.patient_id = %d AND l.clinic_id = %d AND p.status = 'active'"
            . $this->clinical_access_identity_predicate()
            . ' LIMIT 1',
            [ $wp_user_id, $patient_id, $clinic_id ]
        );

        return null !== $value;
    }

    /**
     * Active Patient candidates backed by a matching durable link and Clinic.
     *
     * @return list<array<string, mixed>>
     */
    public function active_linked_patients_for_user( int $wp_user_id ): array {
        return $this->db->fetchAll(
            'SELECT p.*' .
            ' FROM ' . $this->db->table( 'cpms_patient_user_links' ) . ' l' .
            ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' p' .
            ' ON p.id = l.patient_id AND p.clinic_id = l.clinic_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_clinics' ) . ' c' .
            ' ON c.id = l.clinic_id' .
            ' WHERE l.wp_user_id = %d AND p.status = %s' .
            $this->clinical_access_identity_predicate() .
            ' ORDER BY l.is_primary DESC, l.id ASC LIMIT 2',
            [ $wp_user_id, 'active' ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_active_linked_patient_by_link( int $wp_user_id, int $link_id ): ?array {
        return $this->db->fetchRow(
            'SELECT p.*' .
            ' FROM ' . $this->db->table( 'cpms_patient_user_links' ) . ' l' .
            ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' p' .
            ' ON p.id = l.patient_id AND p.clinic_id = l.clinic_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_clinics' ) . ' c' .
            ' ON c.id = l.clinic_id' .
            ' WHERE l.id = %d AND l.wp_user_id = %d AND p.status = %s' .
            $this->clinical_access_identity_predicate() . ' LIMIT 1',
            [ $link_id, $wp_user_id, 'active' ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByMrn(int $clinicId, string $mrn): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_patients') .
            ' WHERE clinic_id = %d AND mrn = %s LIMIT 1',
            [$clinicId, $mrn]
        );
    }

    /**
     * جستجوی منشی (D2): نام / موبایل / کد ملی / MRN.
     *
     * @return list<array<string, mixed>>
     */
    public function search(int $clinicId, string $q, int $limit = 25): array
    {
        $like = '%' . $q . '%';

        return $this->db->fetchAll(
            'SELECT id, mrn, first_name, last_name, mobile, national_id, birth_date, gender, status
             FROM ' . $this->db->table('cpms_patients') . '
             WHERE clinic_id = %d AND status = %s AND (
                first_name LIKE %s OR last_name LIKE %s OR mobile LIKE %s
                OR national_id LIKE %s OR mrn LIKE %s
             )
             ORDER BY last_name, first_name
             LIMIT ' . (int) $limit,
            [$clinicId, 'active', $like, $like, $like, $like, $like]
        );
    }

    /**
     * @param array<string, mixed> $fields فقط فیلدهای Whitelist (بقیه حذف می‌شوند)
     */
    public function create(array $fields): int
    {
        $data = $this->whitelist($fields, self::CREATE_FIELDS);
        $this->db->insert('cpms_patients', $data);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @param array<string, mixed> $fields فقط فیلدهای Whitelist (بقیه حذف می‌شوند)
     */
    public function update(int $id, array $fields): int
    {
        $data = $this->whitelist($fields, self::UPDATE_FIELDS);
        if ($data === []) {
            return 0;
        }

        return $this->db->update('cpms_patients', $data, ['id' => $id]);
    }

    /**
     * Identity-bearing records require explicit Organization-level self proof.
     * This keeps a Clinic booking-subject link useful for a dependent without
     * silently turning it into profile or clinical-record access. Records that
     * predate Organization identities (identity_id IS NULL) retain their
     * explicit legacy clinical link for backwards compatibility.
     */
    private function clinical_access_identity_predicate(): string {
        return ' AND (p.identity_id IS NULL OR EXISTS ('
            . ' SELECT 1 FROM ' . $this->db->table( 'cpms_patient_identity_links' ) . ' pil'
            . ' INNER JOIN ' . $this->db->table( 'cpms_patient_identities' ) . ' pi'
            . ' ON pi.id = pil.identity_id AND pi.organization_id = pil.organization_id'
            . ' WHERE pil.identity_id = p.identity_id'
            . ' AND pil.wp_user_id = l.wp_user_id'
            . ' AND pil.organization_id = c.organization_id'
            . '))';
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string>         $whitelist
     * @return array<string, mixed>
     */
    private function whitelist(array $fields, array $whitelist): array
    {
        $out = [];
        foreach ($whitelist as $field) {
            if (array_key_exists($field, $fields)) {
                $out[$field] = $fields[$field];
            }
        }

        return $out;
    }
}
