<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Data access for doctor consultation fees and draft visit charges (0030).
 *
 * Only data access: authorization, visit-state guards, invoice delegation and
 * audit belong to VisitPricingService/FinanceService. All amounts are integer
 * Rial (IRR); cpms_invoice_items keeps the existing DECIMAL(12,2) storage.
 */
final class VisitPricingRepository {

    public function __construct( private readonly CpmsDb $db ) {
    }

    /**
     * Location-specific fee wins over the clinic-wide (location_id = 0) row.
     *
     * @return array<string, mixed>|null
     */
    public function default_fee( int $clinic_id, int $clinician_id, int $location_id ): ?array {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table( 'cpms_doctor_consultation_fees' )
            . ' WHERE clinic_id = %d AND clinician_id = %d AND location_id IN (0, %d)'
            . ' ORDER BY location_id DESC',
            [$clinic_id, $clinician_id, $location_id]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function default_fees( int $clinic_id, int $clinician_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table( 'cpms_doctor_consultation_fees' )
            . ' WHERE clinic_id = %d AND clinician_id = %d ORDER BY location_id ASC',
            [$clinic_id, $clinician_id]
        );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return array{before: int|null, after: int}
     */
    public function set_default_fee( int $clinic_id, int $clinician_id, int $location_id, int $fee, int $actor_user_id ): array {
        $table  = $this->db->table( 'cpms_doctor_consultation_fees' );
        $exists = $this->db->fetchRowForUpdate(
            "SELECT id, fee FROM {$table} WHERE clinic_id = %d AND clinician_id = %d AND location_id = %d",
            [$clinic_id, $clinician_id, $location_id]
        );
        $now    = $this->db->nowUtcSql();
        if ( $exists === null ) {
            $this->db->insert(
                'cpms_doctor_consultation_fees',
                [
					'clinic_id'             => $clinic_id,
					'clinician_id'          => $clinician_id,
					'location_id'           => $location_id,
					'fee'                   => $fee,
					'currency'              => 'IRR',
					'updated_by_wp_user_id' => $actor_user_id,
					'created_at'            => $now,
					'updated_at'            => $now,
				]
            );

            return ['before' => null, 'after' => $fee];
        }

        $this->db->update(
            'cpms_doctor_consultation_fees',
            [
                'fee'                   => $fee,
                'currency'              => 'IRR',
                'updated_by_wp_user_id' => $actor_user_id,
                'updated_at'            => $now,
            ],
            ['id' => (int) $exists['id']]
        );

        return ['before' => (int) $exists['fee'], 'after' => $fee];
    }

    /**
     * One row per Visit: either a system fee snapshot (`set_by_wp_user_id = 0`)
     * or an explicit doctor override (`set_by_wp_user_id > 0`).
     *
     * @return array<string, mixed>|null
     */
    public function visit_fee_row( int $visit_id ): ?array {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table( 'cpms_visit_consultation_fees' ) . ' WHERE visit_id = %d',
            [$visit_id]
        );
    }

    /**
     * Backwards-compatible name used by the override audit path.
     *
     * @return array<string, mixed>|null
     */
    public function visit_override( int $visit_id ): ?array {
        return $this->visit_fee_row( $visit_id );
    }

    /**
     * A failed check-in snapshot is durable so a later billing read cannot
     * silently substitute a newer default. The marker is separate from the
     * fee row so it remains writable when that insert path itself fails.
     */
    public function mark_fee_snapshot_failed( int $visit_id, int $clinic_id ): void {
        if ( $visit_id <= 0 || $clinic_id <= 0 ) {
            return;
        }
        $this->db->query(
            'INSERT INTO ' . $this->db->table( 'cpms_visit_fee_snapshot_failures' )
            . ' (visit_id, clinic_id, failed_at) VALUES (%d, %d, %s)'
            . ' ON DUPLICATE KEY UPDATE clinic_id = VALUES(clinic_id), failed_at = VALUES(failed_at)',
            [$visit_id, $clinic_id, $this->db->nowUtcSql()]
        );
        if ( ! $this->fee_snapshot_failed( $visit_id ) ) {
            throw new \RuntimeException( 'Unable to persist the Visit fee snapshot failure marker.' );
        }
    }

    public function fee_snapshot_failed( int $visit_id ): bool {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_visit_fee_snapshot_failures' ) . ' WHERE visit_id = %d',
            [$visit_id]
        ) > 0;
    }

    /**
     * Capture the fee that applied when the Visit was established. The 0 actor
     * marks a system snapshot; a later explicit doctor override replaces it.
     * INSERT IGNORE keeps concurrent check-in / first-read paths idempotent.
     */
    public function snapshot_visit_fee( int $visit_id, int $clinic_id, int $clinician_id, int $fee ): void {
        if ( $visit_id <= 0 || $clinic_id <= 0 || $clinician_id <= 0 ) {
            return;
        }
        $table = $this->db->table( 'cpms_visit_consultation_fees' );
        $now   = $this->db->nowUtcSql();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from trusted CpmsDb::table(); every value is a placeholder.
        $this->db->query(
            "INSERT IGNORE INTO {$table} (visit_id, clinic_id, clinician_id, fee, set_by_wp_user_id, created_at, updated_at) VALUES (%d, %d, %d, %d, 0, %s, %s)",
            [$visit_id, $clinic_id, $clinician_id, $fee, $now, $now]
        );
    }

    public function set_visit_override( int $visit_id, int $clinic_id, int $clinician_id, int $fee, int $actor_user_id ): void {
        $table  = $this->db->table( 'cpms_visit_consultation_fees' );
        $exists = $this->db->fetchRowForUpdate(
            "SELECT visit_id FROM {$table} WHERE visit_id = %d",
            [$visit_id]
        );
        $now    = $this->db->nowUtcSql();
        if ( $exists === null ) {
            $this->db->insert(
                'cpms_visit_consultation_fees',
                [
					'visit_id'          => $visit_id,
					'clinic_id'         => $clinic_id,
					'clinician_id'      => $clinician_id,
					'fee'               => $fee,
					'set_by_wp_user_id' => $actor_user_id,
					'created_at'        => $now,
					'updated_at'        => $now,
				]
            );

            return;
        }
        $this->db->update(
            'cpms_visit_consultation_fees',
            [
                'fee'               => $fee,
                'set_by_wp_user_id' => $actor_user_id,
                'updated_at'        => $now,
            ],
            ['visit_id' => $visit_id]
        );
    }

    /**
     * @param list<int>|null $location_ids null = no Location restriction.
     * @return list<array<string, mixed>>
     */
    public function visits_for_doctor(
        int $clinic_id,
        int $clinician_id,
        string $visit_date,
        ?array $location_ids,
        int $limit
    ): array {
        $sql    = 'SELECT v.id, v.clinic_id, v.location_id, v.status, v.visit_date, v.check_in_at,'
            . ' p.first_name AS patient_first_name, p.last_name AS patient_last_name,'
            . ' c.full_name AS clinician_name'
            . ' FROM ' . $this->db->table( 'cpms_visits' ) . ' v'
            . ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' p ON p.id = v.patient_id'
            . ' INNER JOIN ' . $this->db->table( 'cpms_clinicians' ) . ' c ON c.id = v.clinician_id'
            . " WHERE v.clinic_id = %d AND v.clinician_id = %d AND v.visit_date = %s AND v.status NOT IN ('cancelled','skipped')";
        $params = [$clinic_id, $clinician_id, $visit_date];
        if ( $location_ids !== null ) {
            if ( $location_ids === [] ) {
                return [];
            }
            $sql   .= ' AND v.location_id IN (' . implode( ',', array_fill( 0, count( $location_ids ), '%d' ) ) . ')';
            $params = array_merge( $params, $location_ids );
        }
        $sql     .= ' ORDER BY v.id DESC LIMIT %d';
        $params[] = max( 1, min( 100, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- composed statement uses only generated %d/%s placeholders.
        $rows = $this->db->fetchAll( $sql, $params );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function charges_for_visit( int $clinic_id, int $visit_id, bool $include_removed = false ): array {
        $sql = 'SELECT * FROM ' . $this->db->table( 'cpms_visit_charge_items' )
            . ' WHERE clinic_id = %d AND visit_id = %d';
        if ( ! $include_removed ) {
            $sql .= ' AND removed_at IS NULL';
        }
        $sql .= ' ORDER BY id ASC LIMIT 200';

        $rows = $this->db->fetchAll( $sql, [$clinic_id, $visit_id] );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_charge( int $charge_id ): ?array {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table( 'cpms_visit_charge_items' ) . ' WHERE id = %d',
            [$charge_id]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert_charge( array $row ): int {
        $row += [
            'note'                  => null,
            'invoiced_invoice_id'   => null,
            'updated_by_wp_user_id' => null,
            'removed_at'            => null,
            'removed_by_wp_user_id' => null,
            'created_at'            => $this->db->nowUtcSql(),
            'updated_at'            => $this->db->nowUtcSql(),
        ];
        $this->db->insert( 'cpms_visit_charge_items', $row );

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update_charge( int $charge_id, array $data ): void {
        $data['updated_at'] = $this->db->nowUtcSql();
        $this->db->update( 'cpms_visit_charge_items', $data, ['id' => $charge_id] );
    }

    /**
     * @param list<int> $charge_ids
     */
    public function stamp_charges_invoiced( array $charge_ids, int $invoice_id, int $clinic_id ): void {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $charge_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) {
            return;
        }
        $sql = 'UPDATE ' . $this->db->table( 'cpms_visit_charge_items' )
            . ' SET invoiced_invoice_id = %d, updated_at = %s'
            . ' WHERE clinic_id = %d AND id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')'
            . ' AND invoiced_invoice_id IS NULL AND removed_at IS NULL';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only generated %d placeholders and prepared values.
        $this->db->query( $sql, array_merge( [$invoice_id, $this->db->nowUtcSql(), $clinic_id], $ids ) );
    }
}
