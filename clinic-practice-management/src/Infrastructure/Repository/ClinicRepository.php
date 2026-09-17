<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;
use RuntimeException;

/**
 * Canonical repository for cpms_clinics — only name/address/phone/updated_at may mutate.
 *
 * Invariants:
 * - cpms_clinics is canonical source for clinic profile.
 * - No creation/deletion here.
 * - updateProfile only touches allowed columns.
 * - Fail-closed on SQL error (last_error check).
 */
final class ClinicRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $clinicId): ?array
    {
        if ($clinicId <= 0) {
            return null;
        }

        $row = $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',
            [$clinicId]
        );

        $this->assertNoSqlError();

        return $row;
    }

    /**
     * Update only name/address/phone/updated_at.
     *
     * @param array{name:string, address:?string, phone:?string} $data
     * @return int affected rows (0 = no-op or not found)
     * @throws RuntimeException on query failure (fail-closed, not silent success)
     */
    public function updateProfile(int $clinicId, array $data, string $nowUtcSql): int
    {
        if ($clinicId <= 0) {
            throw new RuntimeException('invalid clinic id');
        }

        $payload = [
            'name' => (string) ($data['name'] ?? ''),
            'address' => array_key_exists('address', $data) ? $data['address'] : null,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : null,
            'updated_at' => $nowUtcSql,
        ];

        // Use wpdb update via CpmsDb which already checks strict mode but we also check last_error manually
        $affected = $this->db->update('cpms_clinics', $payload, ['id' => $clinicId]);

        $this->assertNoSqlError();

        // $affected can be 0 for no-op or not-found; caller distinguishes via prior existence check.
        // However if last_error was set, we would have thrown.
        return (int) $affected;
    }

    /**
     * Fail-closed if wpdb reports last_error.
     *
     * @throws RuntimeException
     */
    private function assertNoSqlError(): void
    {
        $last = $this->db->wpdb()->last_error;
        if ($last !== '') {
            throw new RuntimeException('CLINIC_QUERY_FAILED: ' . $last);
        }
    }
}
