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
     * @deprecated Use findAllByClinicianSlot for ambiguity-safe resolution.
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
     * Ambiguity-safe: returns ALL matching slots for tuple (clinic, clinician, date, time).
     * Used to detect multi-Location ambiguity and fail closed.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllByClinicianSlot(int $clinicId, int $clinicianId, string $date, string $time): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_schedule_slots') .
            ' WHERE clinic_id = %d AND clinician_id = %d AND slot_date = %s AND slot_time = %s ORDER BY id ASC',
            [$clinicId, $clinicianId, $date, $time]
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Exact identity resolution: load slot by id and validate Clinic ownership.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdAndClinic(int $slotId, int $clinicId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_schedule_slots') . ' WHERE id = %d AND clinic_id = %d LIMIT 1',
            [$slotId, $clinicId]
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
     * اکنون شامل id و location_id برای تفکیک چند-Location است.
     *
     * تذکر (Phase 6 Slice 2): فیلتر «گذشته» در این متد نسبت به قاب UTC است و
     * برای slot_date/slot_time محلیِ Location فقط در دسترس‌پذیر بودنِ پارامترهای
     * صریح caller معتبر است. مسیر محصولی availability از
     * {@see availabilityCandidates()} + فیلتر محلیِ سرویس استفاده می‌کند؛ این
     * متد را برای مسیرهای مبتنی بر تقویم محلیِ Location دوباره فراخوانی نکنید.
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
            'SELECT id, location_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count,
                    (capacity - booked_count - held_count) AS capacity_left
             FROM ' . $this->db->table('cpms_schedule_slots') . '
             WHERE clinic_id = %d AND clinician_id = %d AND is_open = 1
               AND slot_date BETWEEN %s AND %s
               AND (slot_date > %s OR (slot_date = %s AND slot_time > %s))
               AND capacity - booked_count - held_count > 0
             ORDER BY slot_date, slot_time, id ASC',
            [$clinicId, $clinicianId, $fromDate, $toDate, $todayUtc, $todayUtc, $nowTimeUtc]
        );
    }

    /**
     * Phase 6 Slice 2: نامزدهای availability بدون هیچ فیلتر زمانی — کران
     * `[fromDate, toDate]` صرفاً مرزِ کارایی دیده‌بانی است؛ تعیین «گذشته»
     * به‌عهدهٔ لایهٔ سرویس با تقویم محلیِ Locationِ هر ردیف است (slot_date و
     * slot_time مقادیر wall-clock محلیِ Location هستند و نباید در SQL با
     * gmdate() — قاب UTC — مقایسه شوند).
     *
     * @return list<array<string, mixed>>
     */
    public function availabilityCandidates(
        int $clinicId,
        int $clinicianId,
        string $fromDate,
        string $toDate
    ): array {
        return $this->db->fetchAll(
            'SELECT id, location_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count,
                    (capacity - booked_count - held_count) AS capacity_left
             FROM ' . $this->db->table('cpms_schedule_slots') . '
             WHERE clinic_id = %d AND clinician_id = %d AND is_open = 1
               AND slot_date BETWEEN %s AND %s
               AND capacity - booked_count - held_count > 0
             ORDER BY slot_date, slot_time, id ASC',
            [$clinicId, $clinicianId, $fromDate, $toDate]
        );
    }

    /**
     * Phase 11 Slice 5 — Reception booking: the ALREADY-GENERATED available
     * slots of ONE doctor at ONE trusted Location on ONE Location-local
     * operational day.
     *
     * Read-only and deliberately narrow: it never generates, repairs, moves or
     * reschedules a slot (no second scheduler, no lazy generation — a weekly
     * template without generated rows offers nothing). "Available" is the
     * established formula (`is_open = 1` AND real free capacity
     * `capacity - booked_count - held_count > 0`), bounded by the trusted
     * Clinic + Location + clinician + date, so ONE bounded query answers ONE
     * reception selection change (never a per-slot request).
     *
     * Scope is the caller's job: every id here is an already-trusted selector.
     * `slot_date`/`slot_time` are Location wall-clock values, so this query
     * never compares them against a UTC frame — deciding whether a row has
     * already started belongs to the caller that owns the Location timezone.
     *
     * @param int    $clinic_id    Trusted Clinic. Non-positive returns none.
     * @param int    $location_id  Trusted selected Location. Non-positive returns none.
     * @param int    $clinician_id Selected eligible doctor. Non-positive returns none.
     * @param string $date         Location-local operational day, Y-m-d. Malformed returns none.
     * @return list<array<string, mixed>>
     */
    public function list_available_for_reception_day( int $clinic_id, int $location_id, int $clinician_id, string $date ): array {
        if ( $clinic_id <= 0 || $location_id <= 0 || $clinician_id <= 0 ) {
            return [];
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT id, clinician_id, location_id, slot_date, slot_time, duration_min,'
                . ' capacity, booked_count, held_count,'
                . ' (capacity - booked_count - held_count) AS capacity_left'
                . ' FROM ' . $this->db->table( 'cpms_schedule_slots' )
                . ' WHERE clinic_id = %d AND location_id = %d AND clinician_id = %d'
                . ' AND slot_date = %s AND is_open = 1'
                . ' AND capacity - booked_count - held_count > 0'
                . ' ORDER BY slot_time ASC, id ASC',
            [ $clinic_id, $location_id, $clinician_id, $date ]
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Hold اتمیک — یک واحد ظرفیت رزرو موقت (B1).
     *
     * @return bool true اگر Hold موفق (دروغ = ظرفیت تمام → CLINIC_SLOT_TAKEN)
     */
    public function atomicHold(int $slotId): bool
    {
        return $this->db->execute(
            'UPDATE ' . $this->db->table('cpms_schedule_slots') . '
             SET held_count = held_count + 1, updated_at = %s
             WHERE id = %d AND is_open = 1 AND capacity - booked_count - held_count > 0',
            [$this->nowSql(), $slotId]
        ) > 0;
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
        return $this->db->execute(
            'UPDATE ' . $this->db->table('cpms_schedule_slots') . '
             SET held_count = GREATEST(held_count - 1, 0), booked_count = booked_count + 1, updated_at = %s
             WHERE id = %d AND held_count > 0',
            [$this->nowSql(), $slotId]
        ) > 0;
    }

    /**
     * Book مستقیم بدون Hold (D10 staff-create) — ظرفیت آزاد واقعی (منهای
     * Holdهای فعال بیماران دیگر) باید > 0؛ وگرنه Hold بیمار دیگری Overbook می‌شد.
     */
    public function atomicBook(int $slotId): bool
    {
        return $this->db->execute(
            'UPDATE ' . $this->db->table('cpms_schedule_slots') . '
             SET booked_count = booked_count + 1, updated_at = %s
             WHERE id = %d AND is_open = 1 AND capacity - booked_count - held_count > 0',
            [$this->nowSql(), $slotId]
        ) > 0;
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
