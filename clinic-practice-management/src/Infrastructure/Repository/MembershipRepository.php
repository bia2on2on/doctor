<?php

declare( strict_types=1 );

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository عضویت‌ها (Phase 2 — C4 primitives / ADR-0031 / P2-D1).
 *
 * قواعد:
 *  - هر متد scope را «صریح» می‌گیرد (clinic_id/membership_id) — هیچ fallback
 *    clinic_id=1 یا فرض کاربر جاری وجود ندارد؛ ابهام = پاسخ خالی/خطای سرویس.
 *  - عضویت، رابطهٔ واقعی User↔Clinic است (SoT) — clinicians.clinic_id برای
 *    authorization استفاده نمی‌شود (legacy، فقط ثبت تاریخی).
 *  - ردیف‌سازی خام؛ قواعد اعتبارسنجی/تراکنش در MembershipService.
 *
 * جداول: cpms_clinic_memberships (u_membership = UNIQUE(clinic_id,wp_user_id))،
 * cpms_membership_capabilities (u_member_cap)،
 * cpms_membership_locations (u_member_loc)،
 * cpms_clinician_locations (u_clinician_loc).
 */
final class MembershipRepository {

    public function __construct( private readonly CpmsDb $db ) {
    }

    /**
     * عضویت (clinic, user) — بدون فیلتر وضعیت (تصمیم وضعیت با سرویس).
     *
     * @return array<string, mixed>|null
     */
    public function find( int $clinic_id, int $wp_user_id ): ?array {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table( 'cpms_clinic_memberships' ) .
            ' WHERE clinic_id = %d AND wp_user_id = %d LIMIT 1',
            [ $clinic_id, $wp_user_id ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_by_id( int $membership_id ): ?array {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table( 'cpms_clinic_memberships' ) .
            ' WHERE id = %d LIMIT 1',
            [ $membership_id ]
        );
    }

    /**
     * عضویت فعال (clinic, user) — suspend شده‌ها برنمی‌گردند.
     *
     * @return array<string, mixed>|null
     */
    public function find_active( int $clinic_id, int $wp_user_id ): ?array {
        return $this->db->fetchRow(
            'SELECT m.* FROM ' . $this->db->table( 'cpms_clinic_memberships' ) . ' m' .
            ' WHERE m.clinic_id = %d AND m.wp_user_id = %d AND m.status = \'active\' LIMIT 1',
            [ $clinic_id, $wp_user_id ]
        );
    }

    /**
     * همهٔ عضویت‌های فعال یک کاربر + نام کلینیک (برای انتخاب Clinic در UI آینده).
     *
     * @return list<array<string, mixed>>
     */
    public function active_for_user( int $wp_user_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT m.id, m.clinic_id, m.wp_user_id, m.role_key, m.scope_mode, m.status, m.is_primary,' .
            ' c.name AS clinic_name, c.slug AS clinic_slug' .
            ' FROM ' . $this->db->table( 'cpms_clinic_memberships' ) . ' m' .
            ' INNER JOIN ' . $this->db->table( 'cpms_clinics' ) . ' c ON c.id = m.clinic_id' .
            ' WHERE m.wp_user_id = %d AND m.status = \'active\'' .
            ' ORDER BY m.is_primary DESC, m.clinic_id ASC LIMIT 500',
            [ $wp_user_id ]
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * شناسهٔ Clinicهای دارای عضویت فعال برای کاربر — indexable (idx_membership_user).
     *
     * @return list<int>
     */
    public function active_clinic_ids_for_user( int $wp_user_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT clinic_id FROM ' . $this->db->table( 'cpms_clinic_memberships' ) .
            ' WHERE wp_user_id = %d AND status = \'active\' ORDER BY clinic_id',
            [ $wp_user_id ]
        );

        return array_map( static fn( array $r ): int => (int) $r['clinic_id'], is_array( $rows ) ? $rows : [] );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insert( array $fields ): bool {
        return $this->db->insert( 'cpms_clinic_memberships', $fields );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update( array $data, array $where ): int {
        return $this->db->update( 'cpms_clinic_memberships', $data, $where );
    }

    // ---------------- Capability primitives (metadata فاز ۳) ----------------

    /**
     * @return list<array<string, mixed>>
     */
    public function capabilities_for( int $membership_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT capability, effect FROM ' . $this->db->table( 'cpms_membership_capabilities' ) .
            ' WHERE membership_id = %d ORDER BY capability',
            [ $membership_id ]
        );

        return is_array( $rows ) ? $rows : [];
    }

    public function set_capability( int $membership_id, string $capability, string $effect ): void {
        $this->db->query(
            'INSERT INTO ' . $this->db->table( 'cpms_membership_capabilities' ) .
            ' (membership_id, capability, effect, created_at) VALUES (%d, %s, %s, %s)' .
            ' ON DUPLICATE KEY UPDATE effect = VALUES(effect)',
            [ $membership_id, $capability, $effect, $this->db->nowUtcSql() ]
        );
    }

    public function remove_capability( int $membership_id, string $capability ): void {
        $this->db->delete(
            'cpms_membership_capabilities',
            [
                'membership_id' => $membership_id,
                'capability'    => $capability,
            ]
        );
    }

    // ---------------- Membership↔Location ----------------

    /**
     * شناسهٔ Locationهای تخصیص‌یافته به عضویت.
     *
     * @return list<int>
     */
    public function location_ids_for( int $membership_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT location_id FROM ' . $this->db->table( 'cpms_membership_locations' ) .
            ' WHERE membership_id = %d ORDER BY location_id',
            [ $membership_id ]
        );

        return array_map( static fn( array $r ): int => (int) $r['location_id'], is_array( $rows ) ? $rows : [] );
    }

    public function replace_locations( int $membership_id, array $location_ids ): void {
        $this->db->delete( 'cpms_membership_locations', [ 'membership_id' => $membership_id ] );
        foreach ( array_unique( array_map( 'intval', $location_ids ) ) as $location_id ) {
            $this->db->insert(
                'cpms_membership_locations',
                [
                    'membership_id' => $membership_id,
                    'location_id'   => $location_id,
                ]
            );
        }
    }

    /**
     * (clinic_id, id) برای هر Location درخواستی — مبنای اعتبارسنجی تعلق.
     *
     * @param list<int> $location_ids
     *
     * @return array<int, int> map: location_id => clinic_id
     */
    public function location_clinic_map( array $location_ids ): array {
        if ( $location_ids === [] ) {
            return [];
        }

        $map = [];
        // حداکثر ورودی‌ها محدود است (تخصیص‌های عملیاتی)؛ IN با placeholders ساخته می‌شود.
        $ids          = array_values( array_unique( array_map( 'intval', $location_ids ) ) );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows         = $this->db->fetchAll(
            'SELECT id, clinic_id FROM ' . $this->db->table( 'cpms_locations' ) .
            ' WHERE id IN (' . $placeholders . ')',
            $ids
        );
        foreach ( ( is_array( $rows ) ? $rows : [] ) as $row ) {
            $map[ (int) $row['id'] ] = (int) $row['clinic_id'];
        }

        return $map;
    }

    // ---------------- Clinician↔Location (P2-D1: از طریق عضویت) ----------------

    /**
     * Clinicهایی که کاربرِ متصل به این Clinician در آن‌ها عضویت فعال دارد.
     *
     * @return list<int>
     */
    public function active_clinic_ids_for_clinician( int $clinician_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT m.clinic_id FROM ' . $this->db->table( 'cpms_clinic_memberships' ) . ' m' .
            ' INNER JOIN ' . $this->db->table( 'cpms_clinicians' ) . ' c ON c.wp_user_id = m.wp_user_id' .
            ' WHERE c.id = %d AND m.status = \'active\'',
            [ $clinician_id ]
        );

        return array_map( static fn( array $r ): int => (int) $r['clinic_id'], is_array( $rows ) ? $rows : [] );
    }

    /**
     * @return list<int>
     */
    public function clinician_location_ids( int $clinician_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT location_id FROM ' . $this->db->table( 'cpms_clinician_locations' ) .
            ' WHERE clinician_id = %d ORDER BY is_primary DESC, location_id',
            [ $clinician_id ]
        );

        return array_map( static fn( array $r ): int => (int) $r['location_id'], is_array( $rows ) ? $rows : [] );
    }

    public function primary_clinician_location_id( int $clinician_id ): ?int {
        $value = $this->db->fetchValue(
            'SELECT location_id FROM ' . $this->db->table( 'cpms_clinician_locations' ) .
            ' WHERE clinician_id = %d AND is_primary = 1 LIMIT 1',
            [ $clinician_id ]
        );

        return $value === null ? null : (int) $value;
    }

    public function replace_clinician_locations( int $clinician_id, array $location_ids, ?int $primary_location_id ): void {
        $this->db->delete( 'cpms_clinician_locations', [ 'clinician_id' => $clinician_id ] );
        $primary = $primary_location_id !== null ? (int) $primary_location_id : (int) ( $location_ids[0] ?? 0 );
        foreach ( array_unique( array_map( 'intval', $location_ids ) ) as $location_id ) {
            $this->db->insert(
                'cpms_clinician_locations',
                [
                    'clinician_id' => $clinician_id,
                    'location_id'  => $location_id,
                    'is_primary'   => ( $location_id === $primary ) ? 1 : 0,
                    'created_at'   => $this->db->nowUtcSql(),
                ]
            );
        }
    }

    /**
     * wp_user_id متصل به Clinician (u_clinician_user — پروفایل یکتا).
     */
    public function clinician_wp_user_id( int $clinician_id ): ?int {
        $value = $this->db->fetchValue(
            'SELECT wp_user_id FROM ' . $this->db->table( 'cpms_clinicians' ) . ' WHERE id = %d',
            [ $clinician_id ]
        );

        return $value === null ? null : (int) $value;
    }
}
