<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository مراجعه/صف — ADR-0021 (فقط Data-Access، بدون منطق دامنه).
 *
 * پرس‌جوهای صف بر اساس ایندکس‌های cpms_visits:
 *   idx_visit_day    (clinic_id, visit_date, status)  → داشبورد امروز / آمار
 *   idx_visit_queue  (clinician_id, status, waiting_since) → صف FIFO
 *   idx_visit_patient(patient_id, visit_date) → قانون J-5 (ویزیت فعال تکراری)
 *
 * ترتیب صف (J-4): نوبت فوری (walk-in express از نوبت مرجع، FR-7.3) اول،
 * سپس waiting_since صعودی (FIFO).
 */
final class VisitRepository
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
            'SELECT * FROM ' . $this->db->table('cpms_visits') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * Row Lock — داخل Transaction (الگوی ADR-0004؛ J-1/V10 یکتایی complete).
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchRowForUpdate(
            'SELECT * FROM ' . $this->db->table('cpms_visits') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * J-5: ویزیت Active همان بیمار×پزشک در همان روز (سخت‌گیرانه روی کل روز).
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByPatientDay(int $patientId, int $clinicianId, string $visitDate): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_visits') .
            ' WHERE patient_id = %d AND clinician_id = %d AND visit_date = %s AND active = 1 LIMIT 1',
            [$patientId, $clinicianId, $visitDate]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return int id رکورد جدید
     */
    public function insert(array $row): int
    {
        $now = $this->db->nowUtc();
        $row += [
            'clinic_id' => 1,
            'appointment_id' => null,
            'source' => 'walk_in',
            'status' => 'checked_in',
            'waiting_since' => null,
            'called_at' => null,
            'consultation_started_at' => null,
            'consultation_completed_at' => null,
            'checked_out_at' => null,
            'cancel_reason' => null,
            'skip_reason' => null,
            'cancelled_by_wp_user_id' => null,
            'recall_count' => 0,
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->db->insert('cpms_visits', $row);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function updateById(int $id, array $row): int
    {
        $row['updated_at'] = $this->db->nowUtc();

        return $this->db->update('cpms_visits', $row, ['id' => $id]);
    }

    /**
     * تاریخچه append-only (J-3) — هیچ مسیری history را UPDATE/DELETE نمی‌کند.
     *
     * @param array<string, mixed> $row
     * @return int id رخداد (event_id برای R1)
     */
    public function insertHistory(int $visitId, array $row): int
    {
        $row['visit_id'] = $visitId;
        $row['changed_at'] = $this->db->nowUtc();
        $this->db->insert('cpms_visit_status_history', $row);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * کل تاریخچه یک ویزیت (مرتب بر اساس زمان).
     *
     * @return list<array<string, mixed>>
     */
    public function historyFor(int $visitId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_visit_status_history') .
            ' WHERE visit_id = %d ORDER BY id ASC',
            [$visitId]
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * صف یک پزشک (E2) یا کل کلینیک (D1) — وضعیت‌های زنده صف.
     * نوبت فوری: walk-in با نوبت مرجع express (FR-7.3) — با LEFT JOIN مشخص می‌شود.
     *
     * @param list<string> $statuses
     * @return list<array<string, mixed>>
     */
    public function queueFor(int $clinicId, ?int $clinicianId, array $statuses, ?string $visitDate = null): array
    {
        $statuses = array_values($statuses);
        if ($statuses === []) {
            return [];
        }
        $date = $visitDate ?? gmdate('Y-m-d');

        $where = 'v.clinic_id = %d AND v.visit_date = %s AND v.status IN (' .
            implode(',', array_fill(0, count($statuses), '%s')) . ')';
        $params = [$clinicId, $date, ...$statuses];
        if ($clinicianId !== null) {
            $where .= ' AND v.clinician_id = %d';
            $params[] = $clinicianId;
        }

        $rows = $this->db->fetchAll(
            'SELECT v.*, p.first_name AS patient_first_name, p.last_name AS patient_last_name,' .
            ' c.full_name AS clinician_name,' .
            ' a.is_walkin_express AS express' .
            ' FROM ' . $this->db->table('cpms_visits') . ' v' .
            ' JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = v.patient_id' .
            ' JOIN ' . $this->db->table('cpms_clinicians') . ' c ON c.id = v.clinician_id' .
            ' LEFT JOIN ' . $this->db->table('cpms_appointments') . ' a ON a.id = v.appointment_id' .
            ' WHERE ' . $where .
            ' ORDER BY (a.is_walkin_express = 1) DESC, v.waiting_since ASC, v.id ASC',
            $params
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * آمار روز (D1/E1) — شمارش بر اساس status.
     *
     * غنی‌سازی داشبورد امروز (§16 دستور F4): علاوه بر شمارش ویزیت‌ها بر اساس
     * status، سه شمارش سبک دیگر:
     *  - appointments_today: کل نوبت‌های schedule شده برای امروز (هر status)
     *  - appointments_no_show: زیرمجموعه با status=no_show
     *  - walk_in_today: ویزیت‌های امروز بدون نوبت (walk-in مستقل)
     *
     * @return array<string, int>
     */
    public function statsFor(int $clinicId, ?string $visitDate = null): array
    {
        $date = $visitDate ?? gmdate('Y-m-d');
        $rows = $this->db->fetchAll(
            'SELECT status, COUNT(*) AS n FROM ' . $this->db->table('cpms_visits') .
            ' WHERE clinic_id = %d AND visit_date = %s GROUP BY status',
            [$clinicId, $date]
        );

        $stats = [
            'checked_in' => 0, 'waiting' => 0, 'called' => 0, 'in_consultation' => 0,
            'consultation_completed' => 0, 'awaiting_payment' => 0, 'paid' => 0,
            'checked_out' => 0, 'cancelled' => 0, 'skipped' => 0, 'total' => 0,
        ];
        foreach ((is_array($rows) ? $rows : []) as $r) {
            $key = (string) $r['status'];
            $stats[$key] = (int) $r['n'];
            $stats['total'] += (int) $r['n'];
        }

        $appts = $this->db->fetchRow(
            'SELECT COUNT(*) AS total, COALESCE(SUM(status = %s), 0) AS no_show' .
            ' FROM ' . $this->db->table('cpms_appointments') .
            ' WHERE clinic_id = %d AND slot_date = %s',
            ['no_show', $clinicId, $date]
        );
        $stats['appointments_today'] = $appts === null ? 0 : (int) ($appts['total'] ?? 0);
        $stats['appointments_no_show'] = $appts === null ? 0 : (int) ($appts['no_show'] ?? 0);

        $walkIn = $this->db->fetchRow(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('cpms_visits') .
            ' WHERE clinic_id = %d AND visit_date = %s AND appointment_id IS NULL',
            [$clinicId, $date]
        );
        $stats['walk_in_today'] = $walkIn === null ? 0 : (int) ($walkIn['n'] ?? 0);

        return $stats;
    }

    /**
     * Feed رویدادهای Real-time (R1 — ADR-0007): تغییرات صف امروز بعد از event_id.
     * محدود به ویزیت‌های امروز — فید «صف» است نه تاریخچه کامل.
     *
     * @return list<array<string, mixed>>
     */
    public function eventsSince(int $clinicId, int $sinceEventId, int $limit = 200): array
    {
        $rows = $this->db->fetchAll(
            'SELECT h.id, h.visit_id, h.from_status, h.to_status, h.changed_at,' .
            ' h.actor_wp_user_id, h.actor_role, h.note, v.clinic_id, v.clinician_id,' .
            ' v.patient_id, v.status AS visit_status' .
            ' FROM ' . $this->db->table('cpms_visit_status_history') . ' h' .
            ' JOIN ' . $this->db->table('cpms_visits') . ' v ON v.id = h.visit_id' .
            ' WHERE h.id > %d AND v.clinic_id = %d AND v.visit_date = %s ORDER BY h.id ASC LIMIT %d',
            [$sinceEventId, $clinicId, gmdate('Y-m-d'), $limit]
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * بیشینه id رویداد امروز کلینیک — ETag ورژن صف (R1).
     */
    public function lastEventId(int $clinicId): int
    {
        $row = $this->db->fetchRow(
            'SELECT MAX(h.id) AS max_id' .
            ' FROM ' . $this->db->table('cpms_visit_status_history') . ' h' .
            ' JOIN ' . $this->db->table('cpms_visits') . ' v ON v.id = h.visit_id' .
            ' WHERE v.clinic_id = %d AND v.visit_date = %s',
            [$clinicId, gmdate('Y-m-d')]
        );

        return $row === null ? 0 : (int) ($row['max_id'] ?? 0);
    }

    /**
     * رخدادهای no-show بالقوه (FR-5.5) — نوبت‌های بدون ویزیت فعال پس از grace.
     *
     * @return list<array<string, mixed>>
     */
    public function appointmentsPastGrace(string $beforeDateTime, int $limit = 100): array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.id, a.patient_id, a.clinician_id, a.slot_date, a.slot_time' .
            ' FROM ' . $this->db->table('cpms_appointments') . ' a' .
            ' WHERE a.status = \'confirmed\'' .
            ' AND CONCAT(a.slot_date, \' \', a.slot_time) < %s' .
            ' AND a.active_visit_id IS NULL' .
            ' LIMIT %d',
            [$beforeDateTime, $limit]
        );

        return is_array($rows) ? $rows : [];
    }
}
