<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository پزشکان.
 *
 * قواعد:
 *  - حذف فیزیکی ممنوع (FK از Visits/Schedule/…) — فقط فعال/غیرفعال (Deactivate).
 *  - پیوند ۱:۱ با کاربر وردپرس با UNIQUE constraint (Migration 0007) تضمین
 *    می‌شود؛ این Repository پیش از انتساب، تعارض را با پیام فارسی برمی‌گرداند.
 *  - متدهای find/update عمومی برای استفاده داخلی و تست باقی می‌مانند؛
 *    مسیرهای ادمین باید از نسخه‌های Clinic-predicated (findForClinic /
 *    updateForClinic) استفاده کنند تا cross-Clinic mutation ممکن نشود.
 */
final class ClinicianRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * فهرست پزشکان + شمارش برنامه هفتگی + login کاربر متصل.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(int $clinic_id, bool $includeInactive = true): array
    {
        $where = $includeInactive ? '' : ' AND c.is_active = 1';
        $rows = $this->db->fetchAll(
            'SELECT c.*,' .
            ' (SELECT COUNT(*) FROM ' . $this->db->table('cpms_schedule') . ' s WHERE s.clinician_id = c.id AND s.is_active = 1) AS schedule_days,' .
            ' u.user_login AS wp_user_login' .
            ' FROM ' . $this->db->table('cpms_clinicians') . ' c' .
            ' LEFT JOIN ' . $this->db->dbPrefix() . 'users u ON u.ID = c.wp_user_id' .
            ' WHERE c.clinic_id = %d' . $where .
            ' ORDER BY c.is_active DESC, c.full_name ASC LIMIT 500',
            [$clinic_id]
        );

        return is_array($rows) ? $rows : [];
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_clinicians') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * بارگذاری پزشک فقط وقتی که به Clinic مورد اعتماد تعلق دارد (404 parity).
     */
    public function findForClinic(int $id, int $clinicId): ?array
    {
        if ($id <= 0 || $clinicId <= 0) {
            return null;
        }

        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_clinicians') . ' WHERE id = %d AND clinic_id = %d LIMIT 1',
            [$id, $clinicId]
        );
    }

    /**
     * آیا این کاربر وردپرس به پزشک دیگری متصل است؟ (۱:۱ — Migration 0007)
     */
    public function isUserLinked(int $wpUserId, ?int $exceptClinicianId = null): bool
    {
        if ($wpUserId <= 0) {
            return false;
        }
        $sql = 'SELECT id FROM ' . $this->db->table('cpms_clinicians') . ' WHERE wp_user_id = %d';
        $params = [$wpUserId];
        if ($exceptClinicianId !== null) {
            $sql .= ' AND id <> %d';
            $params[] = $exceptClinicianId;
        }

        return $this->db->fetchValue($sql, $params) !== null;
    }

    /**
     * @param array<string, mixed> $fields {full_name*, specialty, room, wp_user_id, is_active}
     */
    public function create(int $clinic_id, array $fields): int
    {
        $now = $this->db->nowUtcSql();
        $this->db->insert('cpms_clinicians', [
            'clinic_id' => $clinic_id,
            'wp_user_id' => $fields['wp_user_id'] ?? null,
            'full_name' => (string) $fields['full_name'],
            'specialty' => $fields['specialty'] ?? null,
            'room' => $fields['room'] ?? null,
            'is_active' => (int) ($fields['is_active'] ?? 1),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * به‌روزرسانی عمومی (برای استفاده داخلی/تست).
     *
     * @param array<string, mixed> $fields
     * @throws \RuntimeException انتساب کاربر متصل به پزشک دیگر (۱:۱ — UNIQUE 0007)
     */
    public function update(int $id, array $fields): void
    {
        $data = ['updated_at' => $this->db->nowUtcSql()];
        foreach (['full_name', 'specialty', 'room'] as $key) {
            if (array_key_exists($key, $fields)) {
                $data[$key] = $fields[$key];
            }
        }
        if (isset($fields['wp_user_id'])) {
            $newUserId = (int) $fields['wp_user_id'];
            if ($this->isUserLinked($newUserId, $id)) {
                throw new \RuntimeException('این کاربر وردپرس قبلاً به پزشک دیگری متصل است (پیوند باید ۱:۱ باشد)');
            }
            $data['wp_user_id'] = $newUserId;
        }
        if (isset($fields['is_active'])) {
            $data['is_active'] = (int) $fields['is_active'];
        }
        $this->db->update('cpms_clinicians', $data, ['id' => $id]);
    }

    /**
     * به‌روزرسانیِ فقط-همان-کلینیک (Predicate بادوام برای مسیرهای ادمین).
     *
     * تعداد ردیف‌های متأثر را برمی‌گرداند (۰ یعنی رکورد به این Clinic تعلق
     * ندارد و جهش باید با 404 parity پاسخ بگیرد).
     *
     * @param array<string, mixed> $fields
     * @throws \RuntimeException
     */
    public function updateForClinic(int $id, int $clinicId, array $fields): int
    {
        if ($id <= 0 || $clinicId <= 0) {
            return 0;
        }
        $data = ['updated_at' => $this->db->nowUtcSql()];
        foreach (['full_name', 'specialty', 'room'] as $key) {
            if (array_key_exists($key, $fields)) {
                $data[$key] = $fields[$key];
            }
        }
        if (array_key_exists('wp_user_id', $fields)) {
            $newUserId = isset($fields['wp_user_id']) ? (int) $fields['wp_user_id'] : 0;
            if ($newUserId > 0 && $this->isUserLinked($newUserId, $id)) {
                throw new \RuntimeException('این کاربر وردپرس قبلاً به پزشک دیگری متصل است (پیوند باید ۱:۱ باشد)');
            }
            $data['wp_user_id'] = $newUserId > 0 ? $newUserId : null;
        }
        if (isset($fields['is_active'])) {
            $data['is_active'] = (int) $fields['is_active'];
        }
        $where = ['id' => $id, 'clinic_id' => $clinicId];

        return (int) $this->db->update('cpms_clinicians', $data, $where);
    }
}
