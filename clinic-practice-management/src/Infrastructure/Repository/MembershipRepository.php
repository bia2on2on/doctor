<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository عضویت‌ها (Phase 2 — C4 primitives / ADR-0031 / P2-D1).
 *
 * قواعد:
 *  - هر متد scope را «صریح» می‌گیرد (clinicId/membershipId) — هیچ fallback
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
final class MembershipRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * عضویت (clinic, user) — بدون فیلتر وضعیت (تصمیم وضعیت با سرویس).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $clinicId, int $wpUserId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_clinic_memberships') .
            ' WHERE clinic_id = %d AND wp_user_id = %d LIMIT 1',
            [$clinicId, $wpUserId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $membershipId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_clinic_memberships') .
            ' WHERE id = %d LIMIT 1',
            [$membershipId]
        );
    }

    /**
     * عضویت فعال (clinic, user) — suspend شده‌ها برنمی‌گردند.
     *
     * @return array<string, mixed>|null
     */
    public function findActive(int $clinicId, int $wpUserId): ?array
    {
        return $this->db->fetchRow(
            'SELECT m.* FROM ' . $this->db->table('cpms_clinic_memberships') . ' m' .
            ' WHERE m.clinic_id = %d AND m.wp_user_id = %d AND m.status = \'active\' LIMIT 1',
            [$clinicId, $wpUserId]
        );
    }

    /**
     * همهٔ عضویت‌های فعال یک کاربر + نام کلینیک (برای انتخاب Clinic در UI آینده).
     *
     * @return list<array<string, mixed>>
     */
    public function activeForUser(int $wpUserId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.id, m.clinic_id, m.wp_user_id, m.role_key, m.scope_mode, m.status, m.is_primary,' .
            ' c.name AS clinic_name, c.slug AS clinic_slug' .
            ' FROM ' . $this->db->table('cpms_clinic_memberships') . ' m' .
            ' INNER JOIN ' . $this->db->table('cpms_clinics') . ' c ON c.id = m.clinic_id' .
            ' WHERE m.wp_user_id = %d AND m.status = \'active\'' .
            ' ORDER BY m.is_primary DESC, m.clinic_id ASC LIMIT 500',
            [$wpUserId]
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * شناسهٔ Clinicهای دارای عضویت فعال برای کاربر — indexable (idx_membership_user).
     *
     * @return list<int>
     */
    public function activeClinicIdsForUser(int $wpUserId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT clinic_id FROM ' . $this->db->table('cpms_clinic_memberships') .
            ' WHERE wp_user_id = %d AND status = \'active\' ORDER BY clinic_id',
            [$wpUserId]
        );

        return array_map(static fn (array $r): int => (int) $r['clinic_id'], is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insert(array $fields): bool
    {
        return $this->db->insert('cpms_clinic_memberships', $fields);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(array $data, array $where): int
    {
        return $this->db->update('cpms_clinic_memberships', $data, $where);
    }

    // ---------------- Capability primitives (metadata فاز ۳) ----------------

    /**
     * @return list<array<string, mixed>>
     */
    public function capabilitiesFor(int $membershipId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT capability, effect FROM ' . $this->db->table('cpms_membership_capabilities') .
            ' WHERE membership_id = %d ORDER BY capability',
            [$membershipId]
        );

        return is_array($rows) ? $rows : [];
    }

    public function setCapability(int $membershipId, string $capability, string $effect): void
    {
        $this->db->query(
            'INSERT INTO ' . $this->db->table('cpms_membership_capabilities') .
            ' (membership_id, capability, effect, created_at) VALUES (%d, %s, %s, %s)' .
            ' ON DUPLICATE KEY UPDATE effect = VALUES(effect)',
            [$membershipId, $capability, $effect, $this->db->nowUtcSql()]
        );
    }

    public function removeCapability(int $membershipId, string $capability): void
    {
        $this->db->delete('cpms_membership_capabilities', [
            'membership_id' => $membershipId,
            'capability' => $capability,
        ]);
    }

    // ---------------- Membership↔Location ----------------

    /**
     * شناسهٔ Locationهای تخصیص‌یافته به عضویت.
     *
     * @return list<int>
     */
    public function locationIdsFor(int $membershipId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT location_id FROM ' . $this->db->table('cpms_membership_locations') .
            ' WHERE membership_id = %d ORDER BY location_id',
            [$membershipId]
        );

        return array_map(static fn (array $r): int => (int) $r['location_id'], is_array($rows) ? $rows : []);
    }

    public function replaceLocations(int $membershipId, array $locationIds): void
    {
        $this->db->delete('cpms_membership_locations', ['membership_id' => $membershipId]);
        foreach (array_unique(array_map('intval', $locationIds)) as $locationId) {
            $this->db->insert('cpms_membership_locations', [
                'membership_id' => $membershipId,
                'location_id' => $locationId,
            ]);
        }
    }

    /**
     * (clinic_id, id) برای هر Location درخواستی — مبنای اعتبارسنجی تعلق.
     *
     * @param list<int> $locationIds
     *
     * @return array<int, int> map: location_id => clinic_id
     */
    public function locationClinicMap(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        $map = [];
        // حداکثر ورودی‌ها محدود است (تخصیص‌های عملیاتی)؛ IN با placeholders ساخته می‌شود.
        $ids = array_values(array_unique(array_map('intval', $locationIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $this->db->fetchAll(
            'SELECT id, clinic_id FROM ' . $this->db->table('cpms_locations') .
            ' WHERE id IN (' . $placeholders . ')',
            $ids
        );
        foreach ((is_array($rows) ? $rows : []) as $row) {
            $map[(int) $row['id']] = (int) $row['clinic_id'];
        }

        return $map;
    }

    // ---------------- Clinician↔Location (P2-D1: از طریق عضویت) ----------------

    /**
     * Clinicهایی که کاربرِ متصل به این Clinician در آن‌ها عضویت فعال دارد.
     *
     * @return list<int>
     */
    public function activeClinicIdsForClinician(int $clinicianId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.clinic_id FROM ' . $this->db->table('cpms_clinic_memberships') . ' m' .
            ' INNER JOIN ' . $this->db->table('cpms_clinicians') . ' c ON c.wp_user_id = m.wp_user_id' .
            ' WHERE c.id = %d AND m.status = \'active\'',
            [$clinicianId]
        );

        return array_map(static fn (array $r): int => (int) $r['clinic_id'], is_array($rows) ? $rows : []);
    }

    /**
     * @return list<int>
     */
    public function clinicianLocationIds(int $clinicianId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT location_id FROM ' . $this->db->table('cpms_clinician_locations') .
            ' WHERE clinician_id = %d ORDER BY is_primary DESC, location_id',
            [$clinicianId]
        );

        return array_map(static fn (array $r): int => (int) $r['location_id'], is_array($rows) ? $rows : []);
    }

    public function primaryClinicianLocationId(int $clinicianId): ?int
    {
        $value = $this->db->fetchValue(
            'SELECT location_id FROM ' . $this->db->table('cpms_clinician_locations') .
            ' WHERE clinician_id = %d AND is_primary = 1 LIMIT 1',
            [$clinicianId]
        );

        return $value === null ? null : (int) $value;
    }

    public function replaceClinicianLocations(int $clinicianId, array $locationIds, ?int $primaryLocationId): void
    {
        $this->db->delete('cpms_clinician_locations', ['clinician_id' => $clinicianId]);
        $primary = $primaryLocationId !== null ? (int) $primaryLocationId : (int) ($locationIds[0] ?? 0);
        foreach (array_unique(array_map('intval', $locationIds)) as $locationId) {
            $this->db->insert('cpms_clinician_locations', [
                'clinician_id' => $clinicianId,
                'location_id' => $locationId,
                'is_primary' => ($locationId === $primary) ? 1 : 0,
                'created_at' => $this->db->nowUtcSql(),
            ]);
        }
    }

    /**
     * wp_user_id متصل به Clinician (u_clinician_user — پروفایل یکتا).
     */
    public function clinicianWpUserId(int $clinicianId): ?int
    {
        $value = $this->db->fetchValue(
            'SELECT wp_user_id FROM ' . $this->db->table('cpms_clinicians') . ' WHERE id = %d',
            [$clinicianId]
        );

        return $value === null ? null : (int) $value;
    }
}
