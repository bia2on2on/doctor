<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;
use RuntimeException;

/**
 * Canonical repository for cpms_locations — Location master data (Phase 4).
 *
 * Invariants (مالک‌تأییدشدهٔ slice):
 * - create() همیشه `is_primary = 0` و `is_active = 1` می‌نویسد؛ payload هرگز
 *   این ستون‌ها یا مالک (clinic_id) را انتخاب نمی‌کند — clinic_id فقط از
 *   trusted Clinic سرویس می‌آید.
 * - updateProfile() فقط name/timezone/updated_at را تغییر می‌دهد؛
 *   clinic_id/slug/is_primary/is_active/created_at هرگز mutate نمی‌شوند.
 * - Fail-closed روی خطای SQL (last_error) — خطای query هرگز موفقیت بی‌صدا نمی‌شود.
 * - timezone همان شناسهٔ IANA اعتبارسنجی‌شدهٔ سرویس ذخیره می‌شود؛ این ریپو
 *   validate نمی‌کند (تک‌مسئولیتی) و هیچ fallback زمانی ندارد.
 */
final class LocationRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $locationId): ?array
    {
        if ($locationId <= 0) {
            return null;
        }

        $row = $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_locations') . ' WHERE id = %d LIMIT 1',
            [$locationId]
        );

        $this->assertNoSqlError();

        return $row;
    }

    /**
     * یکتایی slug فقط در محدودهٔ یک Clinic بررسی می‌شود (u_location_slug).
     *
     * @return array<string,mixed>|null
     */
    public function findBySlug(int $clinicId, string $slug): ?array
    {
        if ($clinicId <= 0 || $slug === '') {
            return null;
        }

        $row = $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_locations') . ' WHERE clinic_id = %d AND slug = %s LIMIT 1',
            [$clinicId, $slug]
        );

        $this->assertNoSqlError();

        return $row;
    }

    /**
     * Phase 6 Slice 3 — تک‌پیش‌شرط قرارداد create برنامه: Location باید وجود
     * داشته باشد، متعلق به Clinic معتبر باشد و فعال باشد.
     *
     * بیگانه/ناموجود/غیرفعال همگی null برمی‌گردانند (پاریتِ «یافت نشد» — هیچ
     * تفکیکی در پاسخ افشا نمی‌شود)؛ سندهای کلینیکیِ Location هرگز از payload
     * یا Clinicِ خانهٔ پزشک نمی‌آید — $clinicId فقط از Scope مورد اعتماد سرویس.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveForClinic(int $clinicId, int $locationId): ?array
    {
        if ($clinicId <= 0 || $locationId <= 0) {
            return null;
        }

        $row = $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_locations') .
            ' WHERE id = %d AND clinic_id = %d AND is_active = 1 LIMIT 1',
            [$locationId, $clinicId]
        );

        $this->assertNoSqlError();

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForClinic(int $clinicId): array
    {
        if ($clinicId <= 0) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_locations') .
            ' WHERE clinic_id = %d ORDER BY is_primary DESC, id ASC LIMIT 500',
            [$clinicId]
        );

        $this->assertNoSqlError();

        return $rows;
    }

    /**
     * ساخت Location غیراصلیِ فعال برای trusted Clinic.
     *
     * is_primary=0 و is_active=1 اینجا قطعیت می‌گیرند (نه از payload) و
     * clinic_id فقط از سرویسِ دارای trusted Clinic می‌آید.
     *
     * @return int id ردیف ساخته‌شده
     * @throws RuntimeException روی خطای SQL (fail-closed)
     */
    public function create(int $clinicId, string $name, string $slug, string $timezone, string $nowSql): int
    {
        if ($clinicId <= 0) {
            throw new RuntimeException('invalid trusted clinic id for location create');
        }

        $ok = $this->db->insert('cpms_locations', [
            'clinic_id' => $clinicId,
            'name' => $name,
            'slug' => $slug,
            'timezone' => $timezone,
            'is_primary' => 0,
            'is_active' => 1,
            'created_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);

        if (!$ok) {
            throw new RuntimeException('location insert failed');
        }

        $this->assertNoSqlError();

        $id = $this->db->wpdb_last_insert_id();
        if ($id <= 0) {
            throw new RuntimeException('location insert id unavailable');
        }

        return $id;
    }

    /**
     * به‌روزرسانی فقط name/timezone/updated_at.
     *
     * @return int affected rows (0 = no-op یا not-found — تمایز با check وجودِ قبلی سرویس)
     * @throws RuntimeException روی خطای SQL (fail-closed)
     */
    public function updateProfile(int $locationId, string $name, string $timezone, string $nowSql): int
    {
        if ($locationId <= 0) {
            throw new RuntimeException('invalid location id');
        }

        $affected = $this->db->update(
            'cpms_locations',
            [
                'name' => $name,
                'timezone' => $timezone,
                'updated_at' => $nowSql,
            ],
            ['id' => $locationId]
        );

        $this->assertNoSqlError();

        return $affected;
    }

    /**
     * Fail-closed اگر wpdb خطای SQL گزارش کند.
     *
     * @throws RuntimeException
     */
    private function assertNoSqlError(): void
    {
        $error = $this->db->wpdb()->last_error;
        if (is_string($error) && $error !== '') {
            throw new RuntimeException('cpms_locations query failed: ' . $error);
        }
    }
}
