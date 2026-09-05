<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository تعرفه خدمات (cpms_services — FR-14.9) — G2.
 *
 * فقط Data-Access (ADR-0021). حذف منطقی = is_active=0 (FK از invoice_items
 * موجب RESTRICT است؛ سرویس ارجاع‌شده هرگز سخت‌حذف نمی‌شود).
 */
final class ServiceRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(bool $onlyActive = false): array
    {
        $where = 'clinic_id = %d' . ($onlyActive ? ' AND is_active = 1' : '');

        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_services') .
            ' WHERE ' . $where . ' ORDER BY name ASC LIMIT 500',
            [1]
        ) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_services') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $row += [
            'clinic_id' => 1,
            'currency' => 'IRR',
            'is_active' => 1,
            'created_at' => $this->db->nowUtcSql(),
            'updated_at' => $this->db->nowUtcSql(),
        ];
        $this->db->insert('cpms_services', $row);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = $this->db->nowUtcSql();
        $this->db->update('cpms_services', $data, ['id' => $id, 'clinic_id' => 1]);
    }

    public function existsWithCode(string $code, int $exceptId = 0): bool
    {
        $n = $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_services') .
            ' WHERE clinic_id = %d AND code = %s AND id != %d',
            [1, $code, $exceptId]
        );

        return (int) $n > 0;
    }
}
