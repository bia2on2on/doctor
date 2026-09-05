<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository اسلات — ADR-0021 + تضمین Concurrency ADR-0004.
 *
 * عملیات اتمیک (ضد Double-Booking) با Conditional UPDATE — **بدون Gap**:
 * شرط در WHERE است، پس دو Request هم‌زمان هرگز ظرفیت را رد نمی‌کنند
 * (TP-03 / SlotClaimTest — DB-level guarantee).
 *
 * شمارنده‌ها تفریقی نگه داشته می‌شوند:
 *   hold   : held_count +1            (شرط: ظرفیت آزاد باقی)
 *   release: held_count -1            (شرط: > 0)
 *   claim  : held -1, booked +1       (شرط: held > 0)
 *   unbook : booked -1                (شرط: > 0)
 */
final class SlotRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_schedule_slots') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByClinicianSlot(int $clinicId, int $clinicianId, string $date, string $time): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_schedule_slots') .
            ' WHERE clinic_id = %d AND clinician_id = %d AND slot_date = %s AND slot_time = %s LIMIT 1',
            [$clinicId, $clinicianId, $date, $time]
        );
    }

    /**
     * Row Lock (Claim حیاتی) — داخل Transaction Service (ADR-0004).
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchRowForUpdate(
            'SELECT * FROM ' . $this->db->table('cpms_schedule_slots') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * تقویم آزاد (A1): روزهای باز + ظرفیت باقی — فقط اسلات‌های آتی.
     *
     * @return list<array<string, mixed>>
     */
    public function availability(
        int $clinicId,
        int $clinicianId,
        string $fromDate,
        string $toDate,
        string $todayUtc,
        string $nowTimeUtc
    ): array {
        return $this->db->fetchAll(
            'SELECT slot_date, slot_time, duration_min, capacity, booked_count, held_count,
                    (capacity - booked_count - held_count) AS capacity_left
             FROM ' . $this->db->table('cpms_schedule_slots') . '
             WHERE clinic_id = %d AND clinician_id = %d AND is_open = 1
               AND slot_date BETWEEN %s AND %s
               AND (slot_date > %s OR (slot_date = %s AND slot_time > %s))
               AND capacity - booked_count - held_count > 0
             ORDER BY slot_date, slot_time',
            [$clinicId, $clinicianId, $fromDate, $toDate, $todayUtc, $todayUtc, $nowTimeUtc]
        );
    }

    /**
     * Hold اتمیک — یک واحد ظرفیت رزرو موقت (B1).
     *
     * @return bool true اگر Hold موفق (دروغ = ظرفیت تمام → CLINIC_SLOT_TAKEN)
     */
    public function atomicHold(int $slotId): bool
    {
        return $this->db->execute((
            'UPDATE ' . $this->db->table('cpms_schedule_slots') . '
             SET held_count = held_count + 1, updated_at = %s
             WHERE id = %d AND is_open = 1 AND capacity - booked_count - held_count > 0',
            [$this->nowSql(), $slotId]
        ) > 0);
    }

    /**
     * آزادسازی Hold (انقضا/لغو/تغییر Slot).
     */
    public function releaseHold(int $slotId): void
    {
        $this->db->query(
            'UPDATE ' . $this->db->table('cpms_schedule_slots') . '
             SET held_count = GREATEST(held_count - 1, 0), updated_at = %s
             WHERE id = %d AND held_count > 0',
            [$this->nowSql(), $slotId]
        );
    }

    /**
     * تبدیل Hold→Booked (B2 confirm) — اتمیک، هم‌زمان با Insert Appointment (در Transaction Service).
     */
    public function atomicClaim(int $slotId): bool
    {
        return $this->db->execute((
            'UPDATE ' . $this->db->table('cpms_schedule_slots') . '
             SET held_count = GREATEST(held_count - 1, 0), booked_count = booked_count + 1, updated_at = %s
             WHERE id = %d AND held_count > 0',
            [$this->nowSql(), $slotId]
        ) > 0);
    }

    /**
     * Book مستقیم بدون Hold (D10 staff-create) — ظرفیت آزاد واقعی (منهای
     * Holdهای فعال بیماران دیگر) باید > 0؛ وگرنه Hold بیمار دیگری Overbook می‌شد.
     */
    public function atomicBook(int $slotId): bool
    {
        return $this->db->execute((
            'UPDATE ' . $this->db->table('cpms_schedule_slots') . '
             SET booked_count = booked_count + 1, updated_at = %s
             WHERE id = %d AND is_open = 1 AND capacity - booked_count - held_count > 0',
            [$this->nowSql(), $slotId]
        ) > 0);
    }

    /**
     * آزادسازی ظرفیت هنگام لغو نوبت.
     */
    public function releaseBooking(int $slotId): void
    {
        $this->db->query(
            'UPDATE ' . $this->db->table('cpms_schedule_slots') . '
             SET booked_count = GREATEST(booked_count - 1, 0), updated_at = %s
             WHERE id = %d AND booked_count > 0',
            [$this->nowSql(), $slotId]
        );
    }

    private function nowSql(): string
    {
        return $this->db->nowUtcSql();
    }
}
