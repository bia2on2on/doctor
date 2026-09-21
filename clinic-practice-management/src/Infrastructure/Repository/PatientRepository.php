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
            ' ORDER BY l.is_primary DESC, l.id ASC',
            [ $wp_user_id, 'active' ]
        );
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
            ' WHERE l.id = %d AND l.wp_user_id = %d AND p.status = %s LIMIT 1',
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
