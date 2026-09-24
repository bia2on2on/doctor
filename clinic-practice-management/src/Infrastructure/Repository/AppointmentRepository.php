<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Application\Scope\PrimaryLocationResolver;
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
        'clinic_id', 'location_id', 'reference_code', 'clinician_id', 'patient_id', 'slot_id',
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

        // Phase 2 (AD-15): نوبت snapshot مکانی می‌گیرد — مثل slot_date/time.
        // منبع مقدار: Location همان Slot؛ وگرنه Location اصلی Clinic.
        if (empty($data['location_id'])) {
            $data['location_id'] = $this->locationForNewAppointment($data);
        }

        $this->db->insert('cpms_appointments', $data);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * Location نوبت جدید — از Slot مرجع (اگر هست) وگرنه Location اصلی Clinic.
     *
     * @param array<string, mixed> $data
     */
    private function locationForNewAppointment(array $data): int
    {
        if (!empty($data['slot_id'])) {
            $slotLocation = $this->db->fetchValue(
                'SELECT location_id FROM ' . $this->db->table('cpms_schedule_slots') . ' WHERE id = %d LIMIT 1',
                [(int) $data['slot_id']]
            );
            if ($slotLocation !== null && $slotLocation !== '') {
                return (int) $slotLocation;
            }
        }

        return PrimaryLocationResolver::resolve($this->db, (int) ($data['clinic_id'] ?? 0));
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
     * Doctor Portal operational-day list.
     *
     * The caller supplies the trusted clinic, eligible location, server-derived
     * clinician, and location-local date. This query does not establish scope
     * and does not return contact, identity, or tenant fields.
     *
     * @param int    $clinic_id    Trusted clinic.
     * @param int    $location_id  Eligible location.
     * @param int    $clinician_id Server-derived clinician. Non-positive returns none.
     * @param string $date         Location-local operational day, Y-m-d.
     * @return list<array<string, mixed>>
     */
    public function listForDoctorOperationalDay( // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
        int $clinic_id,
        int $location_id,
        int $clinician_id,
        string $date
    ): array {
        if ( $clinic_id <= 0 || $location_id <= 0 || $clinician_id <= 0 ) {
            return [];
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return [];
        }

        $sql = 'SELECT a.id, a.slot_time, a.status, a.is_walkin_express, a.active_visit_id,'
            . ' p.first_name AS patient_first_name, p.last_name AS patient_last_name,'
            . ' v.id AS visit_id, v.status AS visit_status'
            . ' FROM ' . $this->db->table( 'cpms_appointments' ) . ' a' // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- prefixed table; values stay placeholders.
            . ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' p' // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- prefixed table; values stay placeholders.
            . ' ON p.id = a.patient_id AND p.clinic_id = a.clinic_id'
            . ' LEFT JOIN ' . $this->db->table( 'cpms_visits' ) . ' v' // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- prefixed table; values stay placeholders.
            . ' ON v.clinic_id = a.clinic_id'
            . ' AND v.location_id = a.location_id'
            . ' AND v.clinician_id = a.clinician_id'
            . ' AND v.visit_date = a.slot_date'
            . ' AND ( v.appointment_id = a.id OR v.id = a.active_visit_id )'
            . ' WHERE a.clinic_id = %d AND a.location_id = %d'
            . ' AND a.clinician_id = %d AND a.slot_date = %s'
            . ' ORDER BY a.slot_time ASC, a.id ASC';

        $rows = $this->db->fetchAll( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- CpmsDb prepares the placeholders below.
            $sql,
            [ $clinic_id, $location_id, $clinician_id, $date ]
        );

        return $this->presentDoctorOperationalDay( is_array( $rows ) ? $rows : [] );
    }

    /**
     * Collapse a joined day list to one bounded row per appointment.
     *
     * @param list<array<string, mixed>> $rows Joined appointment rows.
     * @return list<array<string, mixed>>
     */
    private function presentDoctorOperationalDay( array $rows ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- matches AppointmentRepository camelCase contract.
        $chosen = [];
        foreach ( $rows as $row ) {
            $id = (int) $row['id'];
            if ( ! isset( $chosen[ $id ] ) ) {
                $chosen[ $id ] = $row;
                continue;
            }
            $active_visit_id = (int) ( $row['active_visit_id'] ?? 0 );
            $visit_id        = (int) ( $row['visit_id'] ?? 0 );
            $current_visit   = (int) ( $chosen[ $id ]['visit_id'] ?? 0 );
            if ( $active_visit_id > 0 && $visit_id === $active_visit_id ) {
                $chosen[ $id ] = $row;
            } elseif ( $current_visit <= 0 && $visit_id > 0 ) {
                $chosen[ $id ] = $row;
            }
        }

        $presented = [];
        foreach ( $chosen as $row ) {
            $visit_status = $row['visit_status'] ?? null;
            $presented[]  = [
                'id'           => (int) $row['id'],
                'time'         => substr( (string) $row['slot_time'], 0, 5 ),
                'patient_name' => trim( (string) ( $row['patient_first_name'] ?? '' ) . ' ' . (string) ( $row['patient_last_name'] ?? '' ) ),
                'status'       => (string) $row['status'],
                'visit_status' => is_string( $visit_status ) && '' !== $visit_status ? $visit_status : null,
                'express'      => 1 === (int) ( $row['is_walkin_express'] ?? 0 ),
            ];
        }

        return $presented;
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
