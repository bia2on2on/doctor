<?php

declare( strict_types=1 );

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository هویت بیمار (Phase 2 — C5 / AD-14 / ADR-0021).
 *
 * قواعد:
 *  - هر متد جستجو **Organization-scoped** است — هیچ query روی
 *    normalized_mobile بدون predicate سازمان وجود ندارد (C5 §4).
 *  - مرتب‌سازی کاندیدها قطعی است (ORDER BY id) — رفتار duplicate
 *    non-destructive و deterministic.
 *  - `cpms_patients.identity_id` از این Repository نوشته/خوانده می‌شود چون
 *    مالکِ رابطهٔ Identity↔رکورد بالینی، همان aggregate هویت است؛ بقیهٔ
 *    ستونهای patients متعلق به PatientRepository است.
 *  - ردیف‌سازی خام؛ قواعد اعتبارسنجی/تراکنش در PatientIdentityService.
 */
final class PatientIdentityRepository {

    /** فیلدهای قابل ساخت (whitelist — Mass Assignment Protection). */
    private const CREATE_FIELDS = [
        'organization_id',
        'internal_ref',
        'normalized_mobile',
        'created_at',
        'updated_at',
    ];

    /** فیلدهای قابل ویرایش — internal_ref هرگز (immutable identity). */
    private const UPDATE_FIELDS = [
        'normalized_mobile',
        'updated_at',
    ];

    public function __construct( private readonly CpmsDb $db ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find( int $identity_id ): ?array {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table( 'cpms_patient_identities' ) . ' WHERE id = %d LIMIT 1',
            [ $identity_id ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_by_internal_ref( string $internal_ref ): ?array {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table( 'cpms_patient_identities' ) .
            ' WHERE internal_ref = %s LIMIT 1',
            [ $internal_ref ]
        );
    }

    /**
     * کاندیدهای هویت با این موبایل در یک Organization — قطعی (ORDER BY id).
     *
     * @return list<array<string, mixed>>
     */
    public function find_by_mobile( int $organization_id, string $normalized_mobile ): array {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table( 'cpms_patient_identities' ) .
            ' WHERE organization_id = %d AND normalized_mobile = %s ORDER BY id LIMIT 100',
            [ $organization_id, $normalized_mobile ]
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insert( array $fields ): int {
        $data = $this->whitelist( $fields, self::CREATE_FIELDS );
        $this->db->insert( 'cpms_patient_identities', $data );

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update( int $identity_id, array $fields ): int {
        $data = $this->whitelist( $fields, self::UPDATE_FIELDS );
        if ( $data === [] ) {
            return 0;
        }

        return $this->db->update( 'cpms_patient_identities', $data, [ 'id' => $identity_id ] );
    }

    /**
     * organization_id یک Clinic (برای اعتبارسنجی مرز Organization).
     */
    public function clinic_organization( int $clinic_id ): ?int {
        $value = $this->db->fetchValue(
            'SELECT organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $clinic_id ]
        );

        return $value === null ? null : (int) $value;
    }

    /**
     * سازمان وجود دارد؟
     */
    public function organization_exists( int $organization_id ): bool {
        $value = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d',
            [ $organization_id ]
        );

        return $value !== null;
    }

    /**
     * رکورد بالینی (cpms_patients) — فقط برای اعتبارسنجی linking.
     *
     * @return array<string, mixed>|null
     */
    public function patient( int $patient_id ): ?array {
        return $this->db->fetchRow(
            'SELECT id, clinic_id, identity_id FROM ' . $this->db->table( 'cpms_patients' ) .
            ' WHERE id = %d LIMIT 1',
            [ $patient_id ]
        );
    }

    /**
     * اتصال رکورد بالینی به هویت (cpms_patients.identity_id).
     */
    public function set_patient_identity( int $patient_id, int $identity_id ): void {
        $this->db->update(
            'cpms_patients',
            [ 'identity_id' => $identity_id ],
            [ 'id' => $patient_id ]
        );
    }

    /**
     * رکوردهای بالینیِ یک هویت در «یک» Clinic — ایزوله از Clinicهای دیگر.
     *
     * @return list<array<string, mixed>>
     */
    public function clinical_records_for_clinic( int $clinic_id, int $identity_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT id, clinic_id, mrn, first_name, last_name, mobile, status' .
            ' FROM ' . $this->db->table( 'cpms_patients' ) .
            ' WHERE clinic_id = %d AND identity_id = %d ORDER BY id LIMIT 100',
            [ $clinic_id, $identity_id ]
        );

        return is_array( $rows ) ? $rows : [];
    }

    // ---------------- Identity ↔ WP User links ----------------

    /**
     * @param array<string, mixed> $fields
     */
    public function insert_user_link( array $fields ): void {
        $data = $this->whitelist(
            $fields,
            [ 'organization_id', 'identity_id', 'wp_user_id', 'mobile_at_link', 'is_primary', 'linked_at' ]
        );
        $this->db->insert( 'cpms_patient_identity_links', $data );
    }

    public function user_link_exists( int $identity_id, int $wp_user_id ): bool {
        $value = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table( 'cpms_patient_identity_links' ) .
            ' WHERE identity_id = %d AND wp_user_id = %d',
            [ $identity_id, $wp_user_id ]
        );

        return $value !== null;
    }

    /**
     * is_primary=0 برای همهٔ لینکهای این کاربر در این Organization.
     */
    public function clear_primary_user_links( int $organization_id, int $wp_user_id ): void {
        $this->db->query(
            'UPDATE ' . $this->db->table( 'cpms_patient_identity_links' ) .
            ' SET is_primary = 0 WHERE organization_id = %d AND wp_user_id = %d AND is_primary = 1',
            [ $organization_id, $wp_user_id ]
        );
    }

    /**
     * هویتهای متصل به یک WP User در «یک» Organization (با internal_ref).
     *
     * @return list<array<string, mixed>>
     */
    public function identities_for_user( int $organization_id, int $wp_user_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT i.id, i.internal_ref, i.normalized_mobile, l.is_primary, l.linked_at' .
            ' FROM ' . $this->db->table( 'cpms_patient_identity_links' ) . ' l' .
            ' INNER JOIN ' . $this->db->table( 'cpms_patient_identities' ) . ' i ON i.id = l.identity_id' .
            ' WHERE l.organization_id = %d AND l.wp_user_id = %d' .
            ' ORDER BY l.is_primary DESC, l.id ASC LIMIT 100',
            [ $organization_id, $wp_user_id ]
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string>         $whitelist
     * @return array<string, mixed>
     */
    private function whitelist( array $fields, array $whitelist ): array {
        $out = [];
        foreach ( $whitelist as $field ) {
            if ( array_key_exists( $field, $fields ) ) {
                $out[ $field ] = $fields[ $field ];
            }
        }

        return $out;
    }
}
