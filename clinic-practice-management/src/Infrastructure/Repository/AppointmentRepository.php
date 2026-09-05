<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository نوبت — ADR-0021.
 *
 * - فقط Queryهای `cpms_appointments`.
 * - Mass Assignment Protection با Whitelist داخلی.
 * - Transition وضعیت فقط از طریق `updateStatus()` (فیلدهای مجاز Transition) —
 *   خود State Machine در لایه Application (AppointmentMachine).
 */
final class AppointmentRepository
{
    private const CREATE_FIELDS = [
        'clinic_id', 'reference_code', 'clinician_id', 'patient_id', 'slot_id',
        'slot_date', 'slot_time', 'duration_min', 'slot_end_time',
        'wp_user_id', 'reason', 'status', 'is_walkin_express', 'rescheduled_from',
        'booked_at', 'confirmed_at', 'created_at', 'updated_at',
    ];

    private const STATUS_FIELDS = [
        'status', 'confirmed_at', 'cancelled_at', 'cancel_reason',
        'cancelled_by_wp_user_id', 'rescheduled_from', 'rescheduled_to',
        'no_show_at', 'active_visit_id', 'slot_id', 'slot_date', 'slot_time',
        'duration_min', 'slot_end_time', 'updated_at',
    ];

    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_appointments') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * Row Lock — برای Confirm/Cancel/Reschedule اتمیک (داخل Transaction Service).
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchRowForUpdate(
            'SELECT * FROM ' . $this->db->table('cpms_appointments') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByReference(string $referenceCode): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_appointments') . ' WHERE reference_code = %s LIMIT 1',
            [$referenceCode]
        );
    }

    /**
     * @param array<string, mixed> $fields Whitelist داخلی (بقیه حذف می‌شود)
     */
    public function create(array $fields): int
    {
        $data = [];
        foreach (self::CREATE_FIELDS as $field) {
            if (array_key_exists($field, $fields)) {
                $data[$field] = $fields[$field];
            }
        }
        $this->db->insert('cpms_appointments', $data);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * به‌روزرسانی فیلدهای Transition (سفید فیلدهای مجاز — وضعیت توسط Machine معتبر می‌شود).
     *
     * @param array<string, mixed> $fields
     */
    public function updateStatus(int $id, array $fields): int
    {
        $data = [];
        foreach (self::STATUS_FIELDS as $field) {
            if (array_key_exists($field, $fields)) {
                $data[$field] = $fields[$field];
            }
        }
        if ($data === []) {
            return 0;
        }

        return $this->db->update('cpms_appointments', $data, ['id' => $id]);
    }

    /**
     * نوبت‌های بیمار (B3) — بازه تاریخ.
     *
     * @return list<array<string, mixed>>
     */
    public function listByPatient(int $patientId, string $fromDate, string $toDate): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_appointments') . '
             WHERE patient_id = %d AND slot_date BETWEEN %s AND %s
             ORDER BY slot_date, slot_time',
            [$patientId, $fromDate, $toDate]
        );
    }

    /**
     * نوبت‌های پزشک در یک روز (D9).
     *
     * @return list<array<string, mixed>>
     */
    public function listByClinicianDate(int $clinicId, int $clinicianId, string $date): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_appointments') . '
             WHERE clinic_id = %d AND clinician_id = %d AND slot_date = %s
             ORDER BY slot_time',
            [$clinicId, $clinicianId, $date]
        );
    }

    /**
     * بررسی تکراری: آیا بیمار همین Slot را (در وضعیت‌های Active) قبلاً رزرو کرده؟
     * (CLINIC_DUPLICATE_APPOINTMENT — بدون Idempotency-Key).
     *
     * @param list<string> $activeStatuses
     * @return array<string, mixed>|null
     */
    public function findActiveForPatientSlot(int $patientId, int $slotId, array $activeStatuses): ?array
    {
        if ($activeStatuses === []) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($activeStatuses), '%s'));
        $params = array_merge([$patientId, $slotId], $activeStatuses);

        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_appointments') . '
             WHERE patient_id = %d AND slot_id = %d AND status IN (' . $placeholders . ')
             LIMIT 1',
            $params
        );
    }
}
