<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository مراجعه/صف (F4) — فقط دسترسی به visits و visit_status_history.
 * انتقال وضعیت و قواعد کسب‌وکار در VisitService/VisitMachine انجام می‌شود.
 */
final class VisitRepository
{
    private const CREATE_FIELDS = [
        'clinic_id', 'clinician_id', 'patient_id', 'appointment_id', 'source',
        'status', 'visit_date', 'check_in_at', 'waiting_since', 'active',
        'created_at', 'updated_at',
    ];

    private const UPDATE_FIELDS = [
        'status', 'waiting_since', 'called_at', 'consultation_started_at',
        'consultation_completed_at', 'checked_out_at', 'cancel_reason',
        'skip_reason', 'cancelled_by_wp_user_id', 'recall_count', 'active',
        'updated_at',
    ];

    public function __construct(private readonly CpmsDb $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_visits') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchRowForUpdate(
            'SELECT * FROM ' . $this->db->table('cpms_visits') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function findActiveForPatientDay(int $clinicId, int $patientId, int $clinicianId, string $date): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_visits') .
            ' WHERE clinic_id = %d AND patient_id = %d AND clinician_id = %d AND visit_date = %s
              AND active = 1 LIMIT 1',
            [$clinicId, $patientId, $clinicianId, $date]
        );
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): int
    {
        $data = [];
        foreach (self::CREATE_FIELDS as $field) {
            if (array_key_exists($field, $fields)) {
                $data[$field] = $fields[$field];
            }
        }
        $this->db->insert('cpms_visits', $data);

        return $this->db->wpdb_last_insert_id();
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): int
    {
        $data = [];
        foreach (self::UPDATE_FIELDS as $field) {
            if (array_key_exists($field, $fields)) {
                $data[$field] = $fields[$field];
            }
        }

        return $data === [] ? 0 : $this->db->update('cpms_visits', $data, ['id' => $id]);
    }

    /**
     * صف مرتب بر اساس Priority آینده و سپس زمان ورود؛ فعلاً Priority از زمان ورود
     * مشتق می‌شود و ستون جداگانه در Schema فعلی وجود ندارد.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(int $clinicId, int $clinicianId, string $date, ?string $status = null): array
    {
        $params = [$clinicId, $clinicianId, $date];
        $where = 'clinic_id = %d AND clinician_id = %d AND visit_date = %s';
        if ($status !== null) {
            $where .= ' AND status = %s';
            $params[] = $status;
        } else {
            $where .= " AND status IN ('checked_in','waiting','called','in_consultation','consultation_completed','awaiting_payment','paid')";
        }

        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_visits') .
            ' WHERE ' . $where . ' ORDER BY waiting_since IS NULL, waiting_since ASC, check_in_at ASC, id ASC',
            $params
        );
    }

    public function addHistory(
        int $visitId,
        ?string $from,
        string $to,
        ?int $actorUserId,
        string $actorRole,
        ?string $note
    ): int {
        $this->db->insert('cpms_visit_status_history', [
            'visit_id' => $visitId,
            'from_status' => $from,
            'to_status' => $to,
            'changed_at' => $this->db->nowUtcSql(),
            'actor_wp_user_id' => $actorUserId,
            'actor_role' => $actorRole,
            'note' => $note,
            'request_id' => function_exists('cpms_request_id') ? cpms_request_id() : null,
        ]);

        return $this->db->wpdb_last_insert_id();
    }

    public function linkAppointment(int $appointmentId, int $visitId): int
    {
        return $this->db->query(
            'UPDATE ' . $this->db->table('cpms_appointments') .
            ' SET active_visit_id = %d, updated_at = %s WHERE id = %d',
            [$visitId, $this->db->nowUtcSql(), $appointmentId]
        ) ? 1 : 0;
    }
}
